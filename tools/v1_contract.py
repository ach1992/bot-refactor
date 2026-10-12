#!/usr/bin/env python3
"""Reviewed V1 extraction envelope; this is test/build tooling, never runtime code."""
from __future__ import annotations
import contextlib
import hashlib
import io
import json
import subprocess
import tarfile
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
BASE = 'a89e63713131d16b5d6461fd41c01bb002d0be63'
V0 = '8e225265556afc33992575b216f941369a4ddc97'
V0_TREE = '58ccdacfba0ed6849a7d8836c751f5760f66e56b'
# module: namespace, (old source, old name, internal name, unchanged public arguments)
MODULES = {
 'src/Telegram/Response.php': ('AchBot\\Telegram\\Response', [
  ('config.php','tgRetryAfter','retryAfter',['res']),
  ('config.php','apiOk','isSuccessful',['res']),
  ('config.php','getApiMessageId','messageId',['res']),
  ('config.php','isForwardedMessageObj','isForwardedMessage',['msg']),
  ('buy_helpers.php','extractPhotoFileId','photoFileId',['update']),
 ]),
 'src/Telegram/Markup.php': ('AchBot\\Telegram\\Markup', [
  ('config.php','normalizeReplyMarkup','normalize',['key']),
  ('buy_helpers.php','inlineKeyboard','inlineKeyboard',['rows']),
  ('buy_helpers.php','kbSendReceiptAndCancel','receiptAndCancel',['sendPrefix','cancelPrefix','hashId']),
  ('buy_helpers.php','kbOnlyCancel','onlyCancel',['cancelPrefix','hashId']),
 ]),
 'src/Presentation/Text.php': ('AchBot\\Presentation\\Text', [
  ('config.php','replaceAllPlaceholdersInMainValues','replacePlaceholders',['mainValues','botState','userId']),
  ('config.php','getSupportUrl','supportUrl',['botState','fallback']),
 ]),
 'src/Presentation/Labels.php': ('AchBot\\Presentation\\Labels', [
  ('config.php','groupFa','audience',['g']),
  ('config.php','stateFa','broadcastState',['s']),
  ('buy_helpers.php','payTypeToDescription','paymentType',['payType']),
  ('buy_helpers.php','getCartToCartMessageKey','cardToCardMessageKey',['messageType']),
 ]),
 'src/Subscription/Presentation.php': ('AchBot\\Subscription\\Presentation', [
  ('config.php','buildConfigLinks','configLinks',['acc_link','botState','showIndexes']),
  ('config.php','buildSubLink','primaryLink',['serverType','panelUrl','botUrl','token','botState']),
  ('config.php','buildSubBackupSection','backupLinks',['subRaw','token']),
  ('config.php','sumerize','formatTraffic',['amount']),
  ('config.php','sumerize2','trafficInGigabytes',['amount']),
 ]),
}
NAMES = {name for _, entries in MODULES.values() for _, name, _, _ in entries}


def git(*args: str) -> bytes:
    return subprocess.check_output(['git', *args], cwd=ROOT, timeout=30)


@contextlib.contextmanager
def reference():
    """Materialize only the pinned, already-reviewed V0 Git tree; never fetch or run hooks."""
    if git('rev-parse', V0+'^{tree}').decode().strip() != V0_TREE:
        raise RuntimeError('V0 Git tree identity mismatch')
    archive = git('archive', '--format=tar', V0)
    with tempfile.TemporaryDirectory(prefix='achbot-v0-reference-') as tmp:
        root = Path(tmp)
        with tarfile.open(fileobj=io.BytesIO(archive)) as tar:
            members = tar.getmembers()
            if len(members) > 512 or sum(m.size for m in members) > 50*1024*1024:
                raise RuntimeError('Unexpected reference archive size')
            for member in members:
                path = Path(member.name)
                if path.is_absolute() or '..' in path.parts or '.git' in path.parts:
                    raise RuntimeError('Unsafe reference member')
                dest = root/path
                if member.isdir():
                    dest.mkdir(parents=True, exist_ok=True)
                elif member.isfile():
                    dest.parent.mkdir(parents=True, exist_ok=True)
                    dest.write_bytes(tar.extractfile(member).read())
                else:
                    raise RuntimeError('Non-regular reference member')
        lock = json.loads((root/'tests/fixtures/baseline-lock.json').read_text())
        if lock['baseline_commit'] != BASE:
            raise RuntimeError('Unexpected baseline anchor')
        for file, digest in lock['files'].items():
            if hashlib.sha256((root/file).read_bytes()).hexdigest() != digest:
                raise RuntimeError('Corrupt reference: '+file)
            if hashlib.sha256(git('show', BASE+':'+file)).hexdigest() != digest:
                raise RuntimeError('Reference differs from original: '+file)
        yield root, lock


def declarations(root: Path) -> dict:
    code = 'require $argv[1]; $r=[]; foreach(array_slice($argv,2) as $p) $r[basename($p)]=achbotFunctions(file_get_contents($p)); echo json_encode($r,JSON_THROW_ON_ERROR);'
    out = subprocess.check_output(['php','-n','-d','extension=tokenizer','-r',code,
          str(root/'tools/source_scan.php'),str(root/'config.php'),str(root/'buy_helpers.php')], timeout=20)
    return json.loads(out)


def format_internal(source: bytes) -> bytes:
    # Normalize PHP whitespace tokens only. Never alter string-literal or interpolated bytes.
    code = r'''$out=''; foreach(token_get_all("<?php\n".file_get_contents('php://stdin'),TOKEN_PARSE) as $t) { if(is_array($t)) { if($t[0]===T_OPEN_TAG) continue; $s=$t[1]; if($t[0]===T_WHITESPACE) $s=preg_replace('/[ \t]+(?=\n)/','',str_replace("\r\n","\n",$s)); $out.=$s; } else $out.=$t; } echo $out;'''
    return subprocess.check_output(['php','-n','-d','extension=tokenizer','-r',code],input=source,timeout=10)


def expected_runtime(root: Path) -> tuple[dict[str, bytes], list[dict]]:
    """Compute the exact allowed extraction from original bytes, not new snapshots."""
    scan = declarations(root)
    source = {file:(root/file).read_bytes() for file in scan}
    changes = {file:[] for file in scan}
    modules = {}; manifest = []
    for path, (namespace, entries) in MODULES.items():
        module = ('<?php\n/**\n * AchBot presentation helpers. No bootstrap, database or network effects.\n * Legacy coercions, strings and edge behavior are intentional in V1.\n */\nnamespace '+namespace+';\n\n').encode()
        for file, old, new, args in entries:
            found = [f for f in scan[file] if f['name'] == old]
            if len(found) != 1:
                raise RuntimeError('Ambiguous helper '+old)
            f = found[0]; raw = source[file][f['offset']:f['offset']+f['length']]
            signature = raw[:raw.index(b'{')]
            internal = raw.replace(('function '+old+'(').encode(), ('function '+new+'(').encode(), 1)
            if internal == raw and old != new:
                raise RuntimeError('Missing declaration rename '+old)
            callargs = ', '.join('$'+a for a in args)
            if old == 'replaceAllPlaceholdersInMainValues':
                needle = b'replaceAllPlaceholdersInMainValues($value, $botState, $userId)'
                if internal.count(needle) != 1: raise RuntimeError('Recursive call drift')
                internal = internal.replace(needle,b'replacePlaceholders($value, $botState, $userId)')
            if old == 'kbSendReceiptAndCancel':
                needle = b"    $messageType = $GLOBALS['lastCartToCartMessageType'] ?? 'detail';"
                if internal.count(needle) != 1: raise RuntimeError('Message-choice global drift')
                internal = internal.replace(needle,b'')
                internal = internal.replace(b'string $hashId): string',b"string $hashId, $messageType = 'detail'): string",1)
                callargs += ", $GLOBALS['lastCartToCartMessageType'] ?? 'detail'"
            closing = raw.rsplit(b'\n',1)[-1]
            indent = closing[:len(closing)-len(closing.lstrip(b' \t'))].decode()
            wrapper = signature + ('{\n'+indent+'    require_once __DIR__ . \'/'+path+'\';\n'+indent+'    return \\'+namespace+'\\'+new+'('+callargs+');\n'+indent+'}').encode()
            changes[file].append((f['offset'],f['length'],wrapper))
            module += format_internal(internal)+b'\n\n'
            manifest.append({'source':file,'legacy':old,'target':path,'function':namespace+'\\'+new,
                             'original_sha256':hashlib.sha256(raw).hexdigest()})
        modules[path] = module.rstrip(b'\n')+b'\n'
    for file, edits in changes.items():
        text = source[file]
        for offset, length, new in sorted(edits,reverse=True):
            text = text[:offset]+new+text[offset+length:]
        modules[file] = text
    return modules, manifest


def verify(root: Path, baseline: Path, lock: dict) -> dict:
    expected, manifest = expected_runtime(baseline)
    for file in list(lock['files'])+list(MODULES):
        path=root/file
        if any(p.is_symlink() for p in [path,*path.parents] if p != root and root in p.parents):
            raise RuntimeError('Symlink in runtime path: '+file)
    for file, content in expected.items():
        p = root/file
        if not p.is_file() or p.is_symlink() or p.read_bytes() != content:
            raise RuntimeError('V1 extraction contract mismatch: '+file)
    frozen = [f for f in lock['files'] if f not in expected]
    for file in frozen:
        if hashlib.sha256((root/file).read_bytes()).hexdigest() != lock['files'][file]:
            raise RuntimeError('Unrelated runtime/reference change: '+file)
    allowed_php = {f for f in lock['files'] if f.endswith('.php')} | set(MODULES)
    actual_php = {str(p.relative_to(root)) for p in root.rglob('*.php')
                  if p.relative_to(root).parts[0] not in ['tests','tools','.git']
                  and p.relative_to(root).as_posix() != 'baseInfo.php'}
    if actual_php != allowed_php: raise RuntimeError('Unexpected application PHP inventory')
    actual_modules = {str(p.relative_to(root)) for p in (root/'src').rglob('*') if p.is_file() or p.is_symlink()}
    if actual_modules != set(MODULES): raise RuntimeError('Unexpected runtime module inventory')
    # Existing fixtures/harness remain immutable. The V1 driver adds coverage, not new expected results.
    evidence = ['tools/source_scan.php','tools/inventory.py','tools/check.py','tools/test_schema.py',
                'tests/run.py','tests/test_harness.py','tests/schema_probe.php']
    evidence += [str(p.relative_to(baseline)) for folder in ['tests/fixtures','tests/support','docs/v0']
                 for p in (baseline/folder).rglob('*') if p.is_file()]
    for file in evidence:
        if (root/file).read_bytes() != (baseline/file).read_bytes():
            raise RuntimeError('Frozen V0 evidence changed: '+file)
    return {'extracted_helpers':len(manifest),'modules':len(MODULES),'unchanged_runtime_reference_files':len(frozen),
            'frozen_evidence_files':len(evidence),'schema_unchanged':True,'baseline_commit':BASE,'v0_commit':V0}



if __name__ == '__main__':
    with reference() as (old, lock): print(json.dumps(verify(ROOT,old,lock),sort_keys=True))
