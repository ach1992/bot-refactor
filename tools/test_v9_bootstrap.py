#!/usr/bin/env python3
"""Real whole-config bootstrap against V8/V9 on two isolated original-schema DBs.

Never reads a deployed baseInfo.php. All secrets and endpoints in this test are
synthetic; every owned provider request remains on Python loopback.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import re
from pathlib import Path

import test_access_state as support
import test_v6_bootstrap as bootstrap
import test_v6_native as native
import v6_contract
import v9_contract as c

ROOT = c.ROOT


def synthetic_private(dbname: str) -> bytes:
    template = (ROOT / c.SANITIZED_PRIVATE_EXAMPLE).read_bytes()
    if template.count(b"?>") != 1 or not template.strip().endswith(b"?>"):
        raise RuntimeError("Unexpected sanitized baseInfo example structure")
    names = re.findall(rb"(?m)^\s*\$([A-Za-z][A-Za-z0-9_]*)\s*=", template)
    if len(names) != len(set(names)) or len(names) < 12:
        raise RuntimeError("Invalid or incomplete private-config example")
    mandatory = {b"botToken", b"dbUserName", b"dbPassword", b"dbName",
                 b"admin", b"admins_list", b"botUrl"}
    if not mandatory <= set(names):
        raise RuntimeError("Missing original private-config entrypoint inputs")
    # Appended overrides are fake and only inside disposable test directories.
    overrides = (b"\n$dbUserName='root';$dbPassword='';"
                 + b"$dbName='" + dbname.encode() + b"';"
                 + b"$botToken='SYNTHETIC_ONLY_NEVER_LIVE';"
                 + b"$botUrl='https://bot.invalid/';"
                 + b"$admin=9001;$admins_list=[9001];"
                 + b"error_reporting(E_ALL);\n")
    return template.rsplit(b"?>", 1)[0] + overrides + b"?>\n"


def stage(root: Path, historical: bool, db: str):
    root.mkdir(parents=True, exist_ok=True)
    for name in c.members():
        dst = root / name
        dst.parent.mkdir(parents=True, exist_ok=True)
        dst.write_bytes(c.old(name) if historical else (ROOT / name).read_bytes())
    (root / "bootstrap.php").write_bytes((ROOT / "tests/v6/bootstrap.php").read_bytes())
    (root / "names.json").write_text(json.dumps(
        v6_contract.PANEL_FUNCS + v6_contract.DETAIL_FUNCS))
    private = synthetic_private(db)
    (root / "baseInfo.php").write_bytes(private)
    (root / "private-state.json").write_text(
        '{"fixture":"V9-only, not actual customer data"}\n')
    return {
        "baseInfo.php": hashlib.sha256(private).hexdigest(),
        "private-state.json": hashlib.sha256(
            (root / "private-state.json").read_bytes()).hexdigest(),
    }


def snapshot(work, env, db, tables):
    return {
        table: native.sql(work, env, "SELECT * FROM `" + table + "` ORDER BY 1", db)
        for table in tables
    }


def ddl_snapshot(work, env, db, tables):
    return {
        table: re.sub(r" AUTO_INCREMENT=\d+", "", native.sql(
            work, env, "SHOW CREATE TABLE `" + table + "`", db))
        for table in tables
    }


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--run-disposable", action="store_true", required=True)
    ap.add_argument("--report", type=Path)
    args = ap.parse_args()
    c.verify()
    results = []
    with support.owned_database() as (work, env), native.owned_provider() as (provider, url):
        native.seed(work, env, url)
        old = work / "complete-v8"
        new = work / "complete-v9"
        dbs = native.DBS
        private_old = stage(old, True, dbs[0])
        private_new = stage(new, False, dbs[1])
        tables = native.sql(work, env, "SHOW TABLES", dbs[0]).splitlines()
        if len(tables) != 23 or tables != native.sql(work, env, "SHOW TABLES", dbs[1]).splitlines():
            raise RuntimeError("Original and current databases must have exactly 23 tables")
        original_ddl = [ddl_snapshot(work, env, db, tables) for db in dbs]
        if original_ddl[0] != original_ddl[1]:
            raise RuntimeError("Original/new database DDL mismatch before bootstrap")
        # Execute the FULL config.php and 40 cross-component global facades,
        # including owned real cURL panel requests, from two different CWDs.
        # The same initialized DB survives all four executions.
        for label, cwd in (
            ("root", None),
            ("foreign_cwd", work / "external-cwd"),
        ):
            if cwd is not None:
                cwd.mkdir(exist_ok=True)
            observations = []
            wires = []
            for app, db in ((old, dbs[0]), (new, dbs[1])):
                provider.trace.clear()
                # Use the established V6 full-PHP bootstrap runner's strict
                # socket-only/open_basedir disabled-command environment.
                if cwd is None:
                    response = bootstrap.run(app, work, env, db)
                else:
                    # The original V6 runner uses its root CWD; temporarily
                    # exercise the foreign-CWD case using equivalent safe PHP.
                    response = run_foreign(app, work, env, db, cwd)
                observations.append(response)
                wires.append(list(provider.trace))
            if observations[0] != observations[1] or wires[0] != wires[1]:
                raise RuntimeError("V8/V9 full config/bootstrap divergence in "
                                   + label + ": " + json.dumps({
                                       "old": observations[0], "new": observations[1],
                                       "old_http": wires[0], "new_http": wires[1],
                                   }, ensure_ascii=False)[:1700])
            if observations[0]["exception"] or not observations[0]["result"]:
                raise RuntimeError("V9 full configuration bootstrap did not complete")
            if len(observations[0]["result"]["signatures"]) != 40 or not wires[0]:
                raise RuntimeError("Legacy facade or owned provider integration missing")
            states = [snapshot(work, env, db, tables) for db in dbs]
            if states[0] != states[1]:
                raise RuntimeError("V8/V9 23-table persisted SQL row mismatch")
            if any(ddl_snapshot(work, env, db, tables) != original_ddl[i]
                   for i, db in enumerate(dbs)):
                raise RuntimeError("Config bootstrap modified original table DDL")
            for app, before in ((old, private_old), (new, private_new)):
                if any(hashlib.sha256((app / name).read_bytes()).hexdigest() != digest
                       for name, digest in before.items()):
                    raise RuntimeError("Full config bootstrap touched synthetic private files")
            results.append({
                "context": label, "signatures": len(observations[0]["result"]["signatures"]),
                "loopback_provider_requests": len(wires[0]), "full_php_config": True,
                "same_23_table_sql_rows": True, "private_config_unchanged": True,
                "sql_row_sha256": hashlib.sha256(json.dumps(states[0], sort_keys=True).encode()).hexdigest()
            })
    report = {
        "scenarios": len(results), "contexts": results,
        "original_23_table_schema_unchanged": True,
        "full_v8_v9_config_facade_parity": True,
        "sample_config_variables_reviewed": True,
        "real_private_config_read": False, "real_payment_or_telegram_operated": False,
        "php_native_mysqli": True, "owned_http_loopback_only": True,
    }
    if args.report:
        args.report.write_text(json.dumps(report, indent=2) + "\n")
    print(json.dumps(report))


def run_foreign(root, work, env, db, cwd):
    import os
    import subprocess
    # Identical safety controls to test_v6_bootstrap.run; only CWD differs.
    e = dict(env, ACHBOT_PRIVATE_TEST="1", ACHBOT_V6_NATIVE="1",
             ACHBOT_DATABASE=db, ACHBOT_SOCKET=str(work / "mysql.sock"))
    cmd = [
        "php", "-n", "-d", "extension=mysqlnd", "-d", "extension=mysqli",
        "-d", "extension=curl",
        "-d", "mysqli.default_socket=" + str(work / "mysql.sock"),
        "-d", "open_basedir=" + str(root) + ":" + str(work),
        "-d", "allow_url_fopen=0", "-d", "display_errors=0", "-d", "log_errors=0",
        "-d", "disable_functions=exec,shell_exec,system,passthru,popen,proc_open,pcntl_exec,pcntl_fork,mail,dl",
        str(root / "bootstrap.php"),
    ]
    response_file = root / "result.json"
    if response_file.exists():
        response_file.unlink()
    p = subprocess.run(cmd, cwd=cwd, env=e, capture_output=True, timeout=25)
    if p.returncode or p.stdout or p.stderr or not response_file.is_file():
        raise RuntimeError("Foreign CWD full PHP config bootstrap failed: " +
                           repr((p.returncode, p.stdout[-500:], p.stderr[-500:])))
    return json.loads(response_file.read_text())


if __name__ == "__main__":
    main()
