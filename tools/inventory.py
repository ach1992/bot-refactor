#!/usr/bin/env python3
"""Generate/check a lexical, source-linked legacy inventory; never execute the bot."""
from __future__ import annotations
import argparse
import hashlib
import json
import re
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
BASE = 'a89e63713131d16b5d6461fd41c01bb002d0be63'
RUNTIME = ['baseInfo.example.php', 'bot.php', 'buy_helpers.php', 'config.php', 'health.php', 'index.html',
           'pay/back.php', 'pay/index.php', 'phpqrcode/phpqrcode.php', 'phpqrcode/qrlib.php',
           'settings/gift2all.php', 'settings/jdf.php', 'settings/messagewizwiz.php',
           'settings/rewardReport.php', 'settings/subLink.php', 'settings/tronChecker.php',
           'settings/values.php', 'settings/warnusers.php', 'database/legacy-schema.sql']
TRIVIA = {'T_WHITESPACE', 'T_COMMENT', 'T_DOC_COMMENT'}

def short(s: str) -> str:
    return re.sub(r'\s+', ' ', s).strip()

def literal(s: str) -> str | None:
    if len(s) < 2 or s[0] != s[-1] or s[0] not in "\"'":
        return None
    if not re.fullmatch(r"'(?:(?:\\.)|[^'\\])*'|\"(?:(?:\\.)|[^\"\\])*\"", s, re.S):
        return None
    if s[0] == "'":
        return s[1:-1].replace("\\'", "'").replace('\\\\', '\\')
    if re.search(r'(?<!\\)\$', s):
        return None
    escapes = {'n': '\n', 'r': '\r', 't': '\t', 'v': '\v', 'e': '\x1b', 'f': '\f', '$': '$', '"': '"', '\\': '\\'}
    return re.sub(r'\\([nrtvef$"\\])', lambda m: escapes[m[1]], s[1:-1])

def expr_until(tokens: list, start: int, stop: set[str]) -> tuple[str, int]:
    depth = 0
    out = []
    j = start
    for j in range(start, len(tokens)):
        k, text, *_ = tokens[j]
        if depth == 0 and k in stop:
            break
        out.append(text)
        if k in ('(', '[', '{', 'T_CURLY_OPEN', 'T_DOLLAR_OPEN_CURLY_BRACES'):
            depth += 1
        elif k in (')', ']', '}'):
            depth -= 1
    return ''.join(out).strip(), j

def string_spans(tokens: list):
    i = 0
    while i < len(tokens):
        k, text, line, _ = tokens[i]
        if k == 'T_CONSTANT_ENCAPSED_STRING':
            yield i, i, text
        elif k == '"':
            j = i + 1
            while j < len(tokens) and tokens[j][0] != '"':
                j += 1
            if j < len(tokens):
                yield i, j, ''.join(t[1] for t in tokens[i:j+1])
                i = j
        i += 1

def source_link(file: str, line: int | None = None) -> str:
    return f'https://github.com/ach1992/bot-refactor/blob/{BASE}/{file}' + (f'#L{line}' if line else '')

def inventory() -> dict:
    php_files = [f for f in RUNTIME if f.endswith('.php')]
    proc = subprocess.run(['php', '-n', '-d', 'extension=tokenizer', str(ROOT/'tools/source_scan.php'), *php_files], cwd=ROOT,
                          capture_output=True, check=True, timeout=45)
    scanned = json.loads(proc.stdout)
    result = {'baseline_commit': BASE, 'method': 'PHP tokenizer + lexical SQL/routing extraction; not an AST/type/dataflow proof',
              'limitations': ['Dynamic SQL/call targets and cross-request states require manual confirmation.',
                 'Literal SQL sites are not equivalent to executed queries or prepare-call counts.',
                 'Conditions include nested guards; they are not an ordered executable router.',
                 'An emitted callback template is not proof its handler is reachable or authorized.'],
              'files': [], 'functions': [], 'includes': [], 'sql': [], 'callbacks': [], 'route_conditions': [],
              'state_writes': [], 'settings_access': [], 'external_literals': [], 'runtime_effects': [],
              'legacy_names': []}
    for file in RUNTIME:
        data = (ROOT/file).read_bytes()
        result['files'].append({'path': file, 'sha256': hashlib.sha256(data).hexdigest(), 'bytes': len(data),
                               'lines': len(data.splitlines()), 'third_party': file.startswith('phpqrcode/') or file == 'settings/jdf.php'})
    for file, parsed in scanned.items():
        ts = parsed['tokens']
        sig = [(i, t) for i, t in enumerate(ts) if t[0] not in TRIVIA]
        pos = {i: n for n, (i, _) in enumerate(sig)}
        source = (ROOT/file).read_text()
        funcs = parsed['functions']
        def owner(i):
            matches = [f for f in funcs if f['start_token'] <= i <= f['end_token']]
            return min(matches, key=lambda f: f['length'])['name'] if matches else '<top-level>'
        for f in funcs:
            sub = [t for t in ts[f['start_token']:f['end_token']+1] if t[0] not in TRIVIA]
            calls = set()
            globals_ = set()
            for n, t in enumerate(sub[:-1]):
                if t[0] == 'T_STRING' and sub[n+1][0] == '(' and (n == 0 or sub[n-1][0] != 'T_FUNCTION'):
                    calls.add(t[1])
                if t[0] == 'T_GLOBAL':
                    j = n + 1
                    while j < len(sub) and sub[j][0] != ';':
                        if sub[j][0] == 'T_VARIABLE': globals_.add(sub[j][1])
                        j += 1
            parents = [g['name'] for g in funcs if g['start_token'] < f['start_token'] and g['end_token'] >= f['end_token']]
            result['functions'].append({'file': file, 'name': f['name'], 'line': f['line'], 'end_line': f['end_line'],
                'signature': short(f['signature']), 'code_sha256': f['code_sha256'], 'enclosing_functions': parents,
                'calls_lexical': sorted(calls), 'globals': sorted(globals_)})
        for n, (i, t) in enumerate(sig):
            k, text, line, _ = t
            nxt = sig[n+1][1] if n+1 < len(sig) else ['', '', 0, 0]
            if k in ('T_REQUIRE', 'T_REQUIRE_ONCE', 'T_INCLUDE', 'T_INCLUDE_ONCE'):
                expr, _ = expr_until(ts, i+1, {';'})
                paths = re.findall(r"['\"]([^'\"]+\.php)['\"]", expr)
                resolved = []
                for p in paths:
                    candidates = [('__DIR__', (ROOT/file).parent / p.lstrip('/'))] if '__DIR__' in expr else [
                        ('root_cwd', ROOT/p), ('source_directory_candidate', (ROOT/file).parent/p)]
                    for context, candidate in candidates:
                        normalized = str(candidate.resolve().relative_to(ROOT)) if candidate.resolve().is_relative_to(ROOT) else '<outside>'
                        if any(r['path']==normalized for r in resolved): continue
                        resolved.append({'path': normalized, 'present': candidate.is_file(),
                                         'runtime_local': normalized == 'baseInfo.php', 'context': context})
                result['includes'].append({'file': file, 'line': line, 'kind': k, 'expression': expr, 'resolved': resolved})
            if k == 'T_CONSTANT_ENCAPSED_STRING' and literal(text) == 'callback_data' and nxt[0] == 'T_DOUBLE_ARROW':
                expr, _ = expr_until(ts, sig[n+1][0]+1, {',', ']'})
                value = literal(expr)
                result['callbacks'].append({'file': file, 'line': line, 'owner': owner(i), 'expression': expr,
                                            'kind': 'literal' if value is not None else 'template_or_expression', 'literal': value})
            if k in ('T_IF', 'T_ELSEIF') and nxt[0] == '(':
                expr, _ = expr_until(ts, sig[n+1][0]+1, {')'})
                if re.search(r'\$(?:data|text|userInfo|payType|step)\b', expr):
                    result['route_conditions'].append({'file': file, 'line': line, 'owner': owner(i), 'condition': short(expr)})
            if k == 'T_STRING' and text == 'setUser' and nxt[0] == '(' and (n == 0 or sig[n-1][1][0] != 'T_FUNCTION'):
                expr, _ = expr_until(ts, sig[n+1][0]+1, {')'})
                result['state_writes'].append({'file': file, 'line': line, 'owner': owner(i), 'arguments': short(expr)})
            if k == 'T_VARIABLE' and text in ('$botState', '$paymentKeys', '$userInfo') and nxt[0] == '[' and n+2 < len(sig):
                key = literal(sig[n+2][1][1])
                if key is not None:
                    result['settings_access'].append({'file': file, 'line': line, 'variable': text, 'key': key, 'owner': owner(i)})
            effects = {'curl_exec', 'curl_multi_exec', 'file_put_contents', 'fopen', 'fwrite', 'unlink', 'rename', 'mkdir',
                       'sleep', 'usleep', 'exec', 'shell_exec', 'system', 'header', 'set_time_limit', 'flock',
                       'begin_transaction', 'commit', 'rollback'}
            if k == 'T_STRING' and text in effects and nxt[0] == '(':
                expr, _ = expr_until(ts, sig[n+1][0]+1, {')'})
                result['runtime_effects'].append({'file': file, 'line': line, 'owner': owner(i), 'call': text, 'arguments': short(expr)})
        for a, b, raw in string_spans(ts):
            line = ts[a][2]
            value = literal(raw)
            inner = value if value is not None else raw[1:-1]
            op = re.match(r'^\s*(SELECT|UPDATE|INSERT|DELETE|REPLACE|SHOW|CREATE|ALTER|DROP|TRUNCATE)\b', inner, re.I)
            if op and re.search(r"\s+\S", inner.strip()):
                tables = sorted(set(re.findall(r'\b(?:FROM|JOIN|INTO|UPDATE|TABLE)\s+`?([a-zA-Z_][\w]*)`?', inner, re.I)))
                result['sql'].append({'file': file, 'line': line, 'end_line': ts[b][2], 'owner': owner(a),
                                      'operation': op[1].upper(), 'tables': tables, 'dynamic': value is None,
                                      'source': inner})
            if 'https://' in inner or 'http://' in inner:
                for url in re.findall(r'https?://[^\s\"\'<>]+', inner):
                    result['external_literals'].append({'file': file, 'line': line, 'owner': owner(a), 'literal': url})
        for line, text in enumerate(source.splitlines(), 1):
            if re.search('wizwiz', text, re.I):
                category = 'needs_semantic_review'
                if 'callback_data' in text: category = 'emitted_callback_contract'
                elif re.search(r'\$data\b|preg_match', text): category = 'received_callback_or_route'
                elif re.search(r'\$userInfo.*step|setUser\(', text): category = 'persisted_state_candidate'
                elif re.match(r'^\s*(//|/\*|\*)', text): category = 'comment'
                result['legacy_names'].append({'file': file, 'line': line, 'category': category, 'source': text.strip()})
    ddl = (ROOT/'database/legacy-schema.sql').read_text()
    result['tables'] = []
    for m in re.finditer(r'CREATE TABLE `([^`]+)` \((.*?)\) ENGINE=(.*?);', ddl, re.S):
        name = m[1]
        cols = [{'name': c[1], 'definition': c[2].rstrip(',')} for c in re.finditer(r'^\s*`([^`]+)` (.+)$', m[2], re.M)]
        alters = re.findall(r'ALTER TABLE `' + re.escape(name) + r'`\s+(.*?);', ddl, re.S)
        refs = [{'file': s['file'], 'line': s['line'], 'operation': s['operation']} for s in result['sql'] if name in s['tables']]
        result['tables'].append({'name': name, 'columns': cols, 'engine': m[3], 'alter_definitions': alters,
                                  'literal_sql_references': refs})
    result['counts'] = {key: len(result[key]) for key in ['files','functions','includes','sql','callbacks','route_conditions',
                            'state_writes','settings_access','runtime_effects','legacy_names','tables']}
    return result

def markdown(inv: dict) -> str:
    out = ['# V0 generated source atlas', '', f'Baseline: `{BASE}`. Regenerate with `python3 tools/inventory.py --write`.',
           '', '**Lexical evidence, not proof of runtime reachability, authorization or complete flow coverage.**', '',
           '## Inventory counts', '', '| Catalog | Records |', '|---|---:|']
    for key, val in inv['counts'].items(): out.append(f'| {key} | {val} |')
    out += ['', '## Files', '', '| File | Lines | Role |', '|---|---:|---|']
    for f in inv['files']:
        out.append(f"| [{f['path']}]({source_link(f['path'])}) | {f['lines']} | {'Bundled third party' if f['third_party'] else 'Application/config/schema'} |")
    out += ['', '## Include edges', '', 'Non-`__DIR__` paths are candidates: actual resolution depends on CWD/include_path/SAPI. A missing root-CWD candidate does not prove a missing dependency.', '', '| Source | Expression | Resolved candidates |', '|---|---|---|']
    for e in inv['includes']:
        paths = ', '.join(f"`{r['path']}` ({'present' if r['present'] else 'local-only' if r['runtime_local'] else 'not_at_candidate'})" for r in e['resolved'])
        out.append(f"| [{e['file']}:{e['line']}]({source_link(e['file'],e['line'])}) | `{e['expression']}` | {paths or 'dynamic'} |")
    out += ['', '## Database coverage', '', '| Table | Columns | Literal SQL sites | Operations |', '|---|---:|---:|---|']
    for t in inv['tables']:
        out.append(f"| `{t['name']}` | {len(t['columns'])} | {len(t['literal_sql_references'])} | {', '.join(sorted({r['operation'] for r in t['literal_sql_references']})) or 'No direct literal site: inspect dynamic calls'} |")
    out += ['', '## First-party function map', '', 'Method names may be shared; nested definitions are identified in the JSON atlas.', '',
            '| Source | Function | Globals | Lexical callees |', '|---|---|---|---|']
    for f in inv['functions']:
        if f['file'].startswith('phpqrcode/') or f['file']=='settings/jdf.php': continue
        calls = ', '.join(f['calls_lexical']).replace('|','\\|')
        out.append(f"| [{f['file']}:{f['line']}-{f['end_line']}]({source_link(f['file'],f['line'])}) | `{f['name']}` | {', '.join(f['globals'])} | {calls} |")
    out += ['', '## Machine-readable detail', '', '`inventory.json` contains every extracted SQL site, schema field, emitted callback expression,',
            'routing condition, `setUser` call, settings access, include, side-effect site, and legacy-name occurrence.',
            'Inspect source-linked expressions for dynamic behavior; do not treat regex-derived table lists as a SQL parser.', '']
    return '\n'.join(out)

def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument('--write', action='store_true', help='Explicitly regenerate reviewed inventory artifacts')
    args = ap.parse_args()
    inv = inventory()
    files = {ROOT/'docs/v0/inventory.json': json.dumps(inv, ensure_ascii=False, indent=2)+'\n',
             ROOT/'docs/v0/SOURCE-ATLAS.md': markdown(inv)}
    for p, content in files.items():
        if args.write:
            p.parent.mkdir(parents=True, exist_ok=True); p.write_text(content)
        elif not p.is_file() or p.read_text() != content:
            raise SystemExit(f'Stale inventory: {p.relative_to(ROOT)}; review source changes before --write')
    print(json.dumps(inv['counts'], sort_keys=True))
    return 0

if __name__ == '__main__':
    raise SystemExit(main())
