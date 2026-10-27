#!/usr/bin/env python3
"""Read-only, source-linked current AChBot dependency inventory (not tenant proof)."""
from __future__ import annotations
import argparse
import hashlib
import json
import re
import subprocess
from pathlib import Path
import inventory as lexical
import package_post_v9 as package

ROOT = Path(__file__).resolve().parents[1]
ENTRYPOINTS = {
    "index.html": "existing public root landing page",
    "bot.php": "authenticated Telegram webhook",
    "health.php": "public health endpoint",
    "pay/index.php": "public hosted checkout",
    "pay/back.php": "public gateway return/IPN",
    "settings/subLink.php": "public subscription endpoint",
    "settings/cronRunner.php": "current scheduled coordinator",
    "settings/gift2all.php": "current gift worker entrypoint",
    "settings/messagewizwiz.php": "current broadcast worker entrypoint",
    "settings/rewardReport.php": "current reward/backup/renewal worker entrypoint",
    "settings/warnusers.php": "current warning worker entrypoint",
    "baseInfo.example.php": "intentionally shipped synthetic private-config template",
}
CANDIDATES = {"admins", "black_list", "servers"}

def audit():
    members = package.members()
    references = {p: [] for p in members}
    sql = []
    dynamic = []
    for file in members:
        if not file.endswith(".php"):
            continue
        parsed = json.loads(subprocess.check_output([
            "php", "-n", "-d", "extension=tokenizer", "-d", "memory_limit=512M",
            str(ROOT/"tools/source_scan.php"), file], cwd=ROOT))[file]
        tokens = parsed["tokens"]
        for index, token in enumerate(tokens):
            if token[0] not in {"T_REQUIRE", "T_REQUIRE_ONCE", "T_INCLUDE", "T_INCLUDE_ONCE"}:
                continue
            expr, _ = lexical.expr_until(tokens, index+1, {";"})
            for part in re.findall(r"""['"]([^'"]+\.php)['"]""", expr):
                base = (ROOT/file).parent if "__DIR__" in expr else ROOT
                if "dirname(__DIR__)" in expr:
                    base = base.parent
                target = (base/part.lstrip("/")).resolve()
                if target.is_relative_to(ROOT):
                    name = target.relative_to(ROOT).as_posix()
                    if name in references:
                        references[name].append({"file": file, "line": token[2]})
        for start, end, raw in lexical.string_spans(tokens):
            literal = lexical.literal(raw)
            text = literal if literal is not None else raw[1:-1]
            match = re.match(r"^\s*(SELECT|UPDATE|INSERT|DELETE|REPLACE|SHOW|CREATE|ALTER|DROP|TRUNCATE)\b", text, re.I)
            if not match:
                continue
            site = {"file": file, "line": tokens[start][2], "operation": match[1].upper()}
            tables = re.findall(r"\b(?:FROM|JOIN|INTO|UPDATE|TABLE)\s+\x60?([a-zA-Z_]\w*)\x60?", text, re.I)
            for table in sorted(set(tables)):
                sql.append(dict(site, table=table))
            if re.search(r"\b(?:FROM|JOIN|INTO|UPDATE|TABLE)\s+\x60?\{?\$", text, re.I):
                dynamic.append(dict(site, expression=text))
    original = re.findall(r"CREATE TABLE \x60([^\x60]+)\x60", (ROOT/"database/legacy-schema.sql").read_text())
    current = set(original)
    for path in (ROOT/"migrations/post-v9").glob("0*.sql"):
        current.update(re.findall(r"CREATE TABLE\s+\x60?([a-zA-Z_]\w*)", path.read_text(), re.I))
    files = [{
        "path": p,
        "sha256": hashlib.sha256((ROOT/p).read_bytes()).hexdigest(),
        "role": ENTRYPOINTS.get(p, "current included module/library/facade"),
        "included_by": references[p],
    } for p in members]
    unresolved = [f["path"] for f in files if not f["included_by"] and f["path"] not in ENTRYPOINTS]
    if unresolved:
        raise RuntimeError("Review unresolved runtime roles: "+repr(unresolved))
    def grouped_sites(table):
        groups = {}
        for site in sql:
            if site["table"] == table:
                groups.setdefault(site["file"], {}).setdefault(site["operation"], []).append(site["line"])
        return [{"file": file, "operations": {op: sorted(set(lines)) for op, lines in ops.items()}}
                for file, ops in sorted(groups.items())]
    return {
        "source": subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=ROOT).decode().strip(),
        "method": "Per-file PHP tokenizer, lexical SQL/include extraction, manually audited dynamic targets",
        "limitations": ["Source references do not prove installed absence, emptiness, external non-use, or retention disposition.",
                        "This inventory is a pinned audit, not a runtime/startup gate."],
        "runtime_files": files,
        "tables": [{"name": table, "original": table in original,
                    "disposition": "optional candidate; tenant audit required" if table in CANDIDATES else "active; preserve",
                    "literal_sql_sites": grouped_sites(table)} for table in sorted(current)],
        "dynamic_table_sites": dynamic,
    }

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output", type=Path, required=True)
    args = parser.parse_args()
    result = audit()
    args.output.write_text(json.dumps(result, indent=2)+"\n")
    print(json.dumps({"runtime_files": len(result["runtime_files"]), "tables": len(result["tables"]),
                      "dynamic_table_sites": len(result["dynamic_table_sites"])}))

if __name__ == "__main__":
    main()
