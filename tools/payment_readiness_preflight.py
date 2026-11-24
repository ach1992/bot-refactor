#!/usr/bin/env python3
"""Release-bound, read-only Wallet-first installation/payment inspection."""
from __future__ import annotations

import argparse
import json
from pathlib import Path
import re
import subprocess

import package_current
from verify_installed_runtime import authenticate_manifest, verify_install

ROOT = package_current.ROOT


def schema_contract() -> dict:
    """Project the actual consolidated MariaDB dump, not a second schema inventory.

    Only the canonical dump grammar is accepted; new DDL forms require explicit
    support rather than silently dropping a constraint from inspection.
    """
    sql = (ROOT / "database/current-schema.sql").read_text()
    tables = {}
    for name, body, engine in re.findall(
            r"^CREATE TABLE \x60(\w+)\x60 \(\n(.*?)^\) ENGINE=(\w+)", sql, re.M | re.S):
        table = {"engine": engine, "columns": {}, "indexes": {}, "constraints": {}}
        for line in body.splitlines():
            line = line.strip().removesuffix(",")
            if m := re.fullmatch(r"\x60(\w+)\x60 (.+)", line):
                column, definition = m.groups()
                table["columns"][column] = "NO" if " NOT NULL" in definition else "YES"
            elif m := re.fullmatch(r"(PRIMARY KEY|UNIQUE KEY \x60\w+\x60|KEY \x60\w+\x60) \((.+)\)", line):
                key, columns = m.groups()
                fields = re.findall(r"\x60(\w+)\x60", columns)
                if re.sub(r"\x60\w+\x60|[\s,]", "", columns) or not fields:
                    raise ValueError("unsupported current index syntax")
                index = "PRIMARY" if key == "PRIMARY KEY" else key.split("\x60")[1]
                table["indexes"][index] = {"unique": key.startswith(("PRIMARY", "UNIQUE")),
                                            "columns": fields}
            elif m := re.fullmatch(r"CONSTRAINT \x60(\w+)\x60 (FOREIGN KEY|CHECK) (.+)", line):
                constraint, kind, definition = m.groups()
                item = {"type": "FOREIGN KEY" if kind == "FOREIGN KEY" else "CHECK"}
                if kind == "FOREIGN KEY":
                    fk = re.fullmatch(r"\(([^)]+)\) REFERENCES \x60(\w+)\x60 \(([^)]+)\)", definition)
                    if not fk:
                        raise ValueError("unsupported current foreign key syntax")
                    item.update(columns=re.findall(r"\x60(\w+)\x60", fk[1]), target=fk[2],
                                target_columns=re.findall(r"\x60(\w+)\x60", fk[3]))
                table["constraints"][constraint] = item
            else:
                raise ValueError("unsupported current schema syntax")
        if name in tables or not table["columns"]:
            raise ValueError("duplicate or empty current table")
        tables[name] = table
    if not tables or len(tables) != len(re.findall(r"^CREATE TABLE ", sql, re.M)):
        raise ValueError("incomplete consolidated schema projection")
    return tables


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--manifest", type=Path, required=True)
    parser.add_argument("--runtime-dir", type=Path, required=True)
    parser.add_argument("--expect-commit", required=True)
    parser.add_argument("--trusted-manifest-sha256", required=True)
    parser.add_argument("--php", default="php")
    args = parser.parse_args()
    report = {"tool": "achbot-payment-readiness-preflight", "report_version": 2,
              "completed": True, "structure_ready": False,
              "payment_configuration_ready": False, "ready_for_isolated_operator_smoke": False,
              "scope": "read_only_local_source_and_database", "checks": []}
    try:
        head, tree, _ = package_current.identity()
        metadata = authenticate_manifest(args.manifest.read_bytes(),
                                         args.trusted_manifest_sha256, args.expect_commit)
        # Reject arbitrary paths before opening even one installed file.
        if (head != metadata["candidate"] or tree != metadata["tree"] or
                metadata.get("schema_migration_included") is not False or
                set(metadata["files"]) != set(package_current.members())):
            raise ValueError("not the exact current source inventory")
        for path in (ROOT, args.runtime_dir):
            verified = verify_install(path, metadata, head)
            if not verified["verified"]:
                report["source_differences"] = verified["differences"]
                raise ValueError("source mismatch")
        contract = schema_contract()
    except (OSError, ValueError, RuntimeError, subprocess.SubprocessError):
        report["checks"].append({"id": "source_identity", "status": "BLOCK",
                                 "code": "untrusted_or_mismatched_source_or_schema_contract"})
        print(json.dumps(report))
        return 2
    report.update(source_candidate=head, source_tree=tree,
                  source_file_count=len(metadata["files"]))
    try:
        result = subprocess.run([args.php, str(ROOT / "tools/payment_readiness_preflight.php")],
                                input=json.dumps(contract), text=True, capture_output=True)
        inspected = json.loads(result.stdout)
        if result.returncode not in (0, 2, 3) or result.stderr or not isinstance(inspected, dict):
            raise ValueError("inspection failed")
    except (OSError, ValueError, subprocess.SubprocessError):
        report["checks"].append({"id": "database_inspection", "status": "BLOCK",
                                 "code": "read_only_inspection_unavailable"})
        print(json.dumps(report))
        return 2
    report.update(inspected)
    report["checks"].insert(0, {"id": "source_identity", "status": "PASS",
                                "code": "exact_trusted_manifest_and_source_members_match"})
    print(json.dumps(report, ensure_ascii=False))
    return result.returncode


if __name__ == "__main__":
    raise SystemExit(main())
