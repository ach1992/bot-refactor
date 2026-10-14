#!/usr/bin/env python3
"""AChBot V9 final Phase 1 exact-source, synthetic bootstrap and rollback gate.

Focused is only local feedback. The full gate reruns unchanged pinned V8/V7
and frozen historical V0-V6.1 tests without touching installed bots.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import os
import subprocess
import tempfile
from pathlib import Path

import v9_contract as c

ROOT = c.ROOT


def run(command, *, timeout=3000):
    p = subprocess.run(command, cwd=ROOT, env=dict(os.environ, ACHBOT_PRIVATE_TEST="1"),
                       capture_output=True, text=True, timeout=timeout)
    if p.returncode:
        raise RuntimeError("Acceptance failure " + repr(command) +
                           "\nstdout: " + p.stdout[-3500:] +
                           "\nstderr: " + p.stderr[-3500:])
    return p.stdout.strip().splitlines()[-1] if p.stdout.strip() else ""


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--php81", action="store_true")
    parser.add_argument("--focused", action="store_true",
                        help="V9-only development feedback; NOT final acceptance")
    parser.add_argument("--report", type=Path)
    parser.add_argument("--evidence-dir", type=Path)
    args = parser.parse_args()
    version = int(subprocess.check_output(["php", "-r", "echo PHP_VERSION_ID;"]))
    if args.php81 and not (80100 <= version < 80200):
        raise RuntimeError("PHP 8.1 evidence must use actual PHP 8.1")
    if not args.php81 and not args.focused and not (80400 <= version < 80500):
        raise RuntimeError("Full historical gate requires PHP 8.4")
    state = c.verify()
    php_files = [name for name in c.members() if name.endswith(".php")]
    for name in php_files:
        run(["php", "-l", str(ROOT / name)], timeout=15)
    result = {
        "complete": False, "focused": args.focused,
        "php81": args.php81, "php_version_id": version,
        "source_contract": state,
        "php_files_linted": len(php_files),
        "runtime_members": len(c.members()),
        "private_deployment_config_read": False,
        "phase2_or_database_migration": False,
        "actual_operator_acceptance": "NOT TESTED",
        "live_financial_safety_certified": False,
    }
    evidence = args.evidence_dir
    if evidence:
        evidence.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory(prefix="achbot-v9-test-reports-") as tmp:
        tmp_path = Path(tmp)
        reports = {}
        for label, script in (
            ("config_bootstrap", "test_v9_bootstrap.py"),
            ("source_swap", "test_v9_swap.py"),
        ):
            output = (evidence if evidence else tmp_path) / (label + ".json")
            line = run(["python3", str(ROOT / "tools" / script),
                        "--run-disposable", "--report", str(output)], timeout=280)
            payload = json.loads(output.read_text())
            reports[label] = payload
            if label == "config_bootstrap":
                if (payload.get("scenarios") != 2 or
                        not payload.get("full_v8_v9_config_facade_parity") or
                        not payload.get("original_23_table_schema_unchanged") or
                        any(x.get("signatures") != 40 or x.get("loopback_provider_requests", 0) < 1
                            for x in payload.get("contexts", [])) or
                        payload.get("real_private_config_read") or
                        payload.get("real_payment_or_telegram_operated")):
                    raise RuntimeError("V9 complete bootstrap/private config evidence incomplete")
            if label == "source_swap":
                phases = payload.get("phases", [])
                if ([x.get("version") for x in phases] != ["v8", "v9", "v8", "v9"] or
                    [x.get("physical_runtime_members") for x in phases] != [92]*4 or
                    not all(x.get("physical_inventory_verified") and
                            x.get("all_source_bytes_verified") and
                            x.get("public_php_entrypoints_linted") == len(c.PUBLIC_ENTRYPOINTS)
                            for x in phases) or
                    len({x.get("source_digest") for x in phases}) != 1 or
                    not payload.get("all_23_table_rows_unchanged") or
                    not payload.get("original_23_table_schema_unchanged") or
                    not payload.get("actual_private_files_unchanged") or
                    not payload.get("no_database_restore_or_rollback")):
                    raise RuntimeError("V9 source-only physical rollback incomplete")
            print("PASS V9 " + label + " " + line[:210], flush=True)
        result["checks"] = {
            "config_bootstrap": {
                "contexts": [x["context"] for x in reports["config_bootstrap"]["contexts"]],
                "legacy_facade_signatures": 40,
                "real_owned_loopback_http": True,
            },
            "source_swap": {
                "actual_on_disk_phases": ["v8", "v9", "v8", "v9"],
                "actual_member_counts": [92, 92, 92, 92],
                "private_rows_schema_unchanged": True,
            },
        }
        if not args.focused:
            v8report = (evidence if evidence else tmp_path) / "pinned_v8_full.json"
            command = ["python3", str(ROOT / "tools/check_v8.py"),
                       "--report", str(v8report)]
            if args.php81:
                command.append("--php81")
            elif evidence:
                command += ["--evidence-dir", str(evidence / "frozen_v8_evidence")]
            line = run(command, timeout=4200)
            old = json.loads(v8report.read_text())
            if (not old.get("complete") or
                    old.get("source_members") != 92 or
                    old.get("financial_modules") != 27 or
                    old.get("php81") != args.php81 or
                    "full_pinned_v7_and_v0_to_v61" not in old.get("checks", {})):
                raise RuntimeError("Pinned V8 full finance/history gate incomplete")
            result["checks"]["pinned_v8_v0_to_v7"] = {
                "complete": True, "source_members": 92, "finance_modules": 27,
                "frozen_history": True,
                "reported_stdout_sha256": hashlib.sha256(line.encode()).hexdigest(),
            }
            print("PASS unchanged V8 -> V7 -> V0-V6.1 full history", flush=True)
            result["complete"] = True
    if args.report:
        args.report.write_text(json.dumps(result, indent=2) + "\n")
    print(json.dumps({
        "complete": result["complete"],
        "php81": result["php81"],
        "runtime_members": result["runtime_members"],
        "php_files_linted": len(php_files),
        "checks": list(result["checks"]),
    }))


if __name__ == "__main__":
    main()
