#!/usr/bin/env python3
"""Owned V8/V9 source overlays; actual physical inventory, private and DB parity.

Legacy V8 suite independently proves preserved PAID order, coupon and renewal
through its real-PHP four-phase V7/V8 rollback. This V9 layer verifies the
entire integrated runtime source set without replaying actual money operations.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import subprocess

import test_access_state as support
import test_v6_native as native
import test_v9_bootstrap as config
import v9_contract as c

PRIVATE = {
    "baseInfo.php": b"<?php // only isolated synthetic config, no real secrets\n",
    "private-state.json": b"owned private state sentinel\n",
    "settings/cron-state.txt": b"synthetic scheduled state sentinel\n",
}
VERSIONS = ("v8", "v9", "v8", "v9")


def digest(data):
    return hashlib.sha256(data).hexdigest()


def overlay(app, version):
    for name in c.members():
        dest = app / name
        if dest.is_symlink():
            raise RuntimeError("Refusing symlinked source entry: " + name)
        dest.parent.mkdir(parents=True, exist_ok=True)
        data = c.old(name) if version == "v8" else (c.ROOT / name).read_bytes()
        dest.write_bytes(data)
        if dest.read_bytes() != data:
            raise RuntimeError("Incomplete physical source overlay")
    if any(p.is_symlink() for p in app.rglob("*")):
        raise RuntimeError("Symlink in staged tree")
    inventory = {p.relative_to(app).as_posix() for p in app.rglob("*") if p.is_file()}
    if inventory != set(c.members()) | set(PRIVATE):
        raise RuntimeError("Physical file inventory drift: " +
                           repr(sorted(inventory ^ (set(c.members()) | set(PRIVATE)))))
    for name in c.PUBLIC_ENTRYPOINTS:
        p = subprocess.run(["php", "-l", str(app / name)], capture_output=True, timeout=15)
        if p.returncode:
            raise RuntimeError("Public PHP entrypoint not syntactically valid: " + name)
    return {
        "version": version, "physical_runtime_members": len(inventory - set(PRIVATE)),
        "physical_inventory_verified": True, "public_php_entrypoints_linted": len(c.PUBLIC_ENTRYPOINTS),
        "all_source_bytes_verified": True,
        "source_digest": digest(b"".join(
            name.encode() + b"\0" + (app / name).read_bytes()
            for name in c.members())),
    }


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--run-disposable", required=True, action="store_true")
    parser.add_argument("--report", type=__import__("pathlib").Path)
    args = parser.parse_args()
    c.verify()
    phases = []
    with support.owned_database() as (work, env):
        app = work / "synthetic-source-overlay"
        app.mkdir()
        for name, data in PRIVATE.items():
            p = app / name
            p.parent.mkdir(parents=True, exist_ok=True)
            p.write_bytes(data)
        private_before = {name: digest((app / name).read_bytes()) for name in PRIVATE}
        db = "achbot_v4_new"
        tables = native.sql(work, env, "SHOW TABLES", db).splitlines()
        if len(tables) != 23:
            raise RuntimeError("Expected untouched original 23-table schema")
        schema = config.ddl_snapshot(work, env, db, tables)
        rows = config.snapshot(work, env, db, tables)
        first = None
        for version in VERSIONS:
            result = overlay(app, version)
            if first is None:
                first = result["source_digest"]
            if result["source_digest"] != first:
                raise RuntimeError("V8/V9 runtime differs without accepted source changes")
            if any(digest((app / name).read_bytes()) != expected
                   for name, expected in private_before.items()):
                raise RuntimeError("Source swap touched private file")
            if config.ddl_snapshot(work, env, db, tables) != schema:
                raise RuntimeError("Source swap changed schema")
            if config.snapshot(work, env, db, tables) != rows:
                raise RuntimeError("Source swap changed persisted rows")
            phases.append(result)
            print("PASS physical integrated source overlay " + version, flush=True)
    report = {
        "phases": phases,
        "actual_runtime_members_each_phase": [x["physical_runtime_members"] for x in phases],
        "actual_private_files_unchanged": True,
        "original_23_table_schema_unchanged": True,
        "all_23_table_rows_unchanged": True,
        "no_database_restore_or_rollback": True,
        "real_money_or_cron_executed": False,
        "php_execution_scope": "php -l for public entrypoints only",
        "paid_financial_state_acceptance": "Covered by pinned V8 real PHP source-swap suite, not by this fixture",
    }
    if args.report:
        args.report.write_text(json.dumps(report, indent=2) + "\n")
    print(json.dumps(report))


if __name__ == "__main__":
    main()
