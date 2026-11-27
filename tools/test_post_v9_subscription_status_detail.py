#!/usr/bin/env python3
"""Current-source customer/admin subscription labels with real PHP + disposable SQL.

Uses the real catalogs and the current AccountDetails functions, not synthetic
status buttons. Local stub remote observations; no provider/Telegram writes.
"""
from __future__ import annotations

import argparse
import json
from pathlib import Path
import subprocess

import database_fixture as owned
import subscription_status_fixture as existing
import database_fixture as native
import database_fixture as purchase

ROOT = Path(__file__).resolve().parents[1]


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--run-disposable", action="store_true", required=True)
    parser.add_argument("--report", type=Path)
    args = parser.parse_args()
    checks: list[str] = []
    with owned.owned_database() as (work, env):
        purchase.init(work, env, "http://127.0.0.1:9")
        db = purchase.SIDE[1]
        script = work / "subscription-status-current.php"
        script.write_text(existing.PHP)
        source = (ROOT / "src/Panel/ProviderRuntime.php").read_bytes()
        start = source.index(b"function achbotWholeServiceUnits(")
        end = source.index(b"function achbotPinnedAddonUnits(", start)
        (work / "actual-service-unit-helpers.php").write_bytes(
            b"<?php\n" + source[start:end])

        def sql(query: str) -> str:
            return native.sql(work, env, query, db)

        sql("INSERT INTO orders_list("
            "id,userid,token,transid,fileid,server_id,inbound_id,remark,"
            "uuid,protocol,expire_date,link,amount,status,date)"
            " VALUES(91,1001,'fixture','','7',2,0,'fixture_user',"
            "'fixture','vless',1900000000,'[]',100,1,'1700000000')")
        sql((ROOT / "migrations/post-v9/013_remove_obsolete_order_columns.sql").read_text())
        original = sql("SELECT * FROM orders_list WHERE id=91")
        expected = {"active": "🟢 فعال", "disabled": "🔴 غیرفعال"}
        for provider in ("pasarguard",):
            sql("UPDATE server_config SET type='" + provider + "' WHERE id=2")
            for status, label in expected.items():
                remote = {
                    "username": "fixture_user", "status": status,
                    "expire": 1800000000, "data_limit": 2147483648,
                    "used_traffic": 1073741824,
                }
                for audience, actor in (("view", 1001), ("admin", 9001)):
                    before = sql("SELECT * FROM orders_list WHERE id=91")
                    request = json.dumps({
                        "op": audience, "actor": actor, "remote": remote})
                    result = subprocess.run(
                        ["php", "-d", "display_errors=stderr", "-d", "log_errors=1",
                         str(script), str(ROOT), str(work / "mysql.sock"), db,
                         request],
                        capture_output=True, text=True, timeout=25, check=False)
                    if result.returncode or result.stderr:
                        raise AssertionError(
                            (provider, status, audience, result.returncode,
                             result.stderr[-1200:], result.stdout[-600:]))
                    response = json.loads(result.stdout)["out"]
                    if label not in response["msg"]:
                        raise AssertionError("status_label_changed:" +
                                             provider + ":" + status + ":" + audience)
                    if before != sql("SELECT * FROM orders_list WHERE id=91"):
                        raise AssertionError("status_detail_modified_order")
                    checks.append(provider + ":" + status + ":" + audience)
        if original != sql("SELECT * FROM orders_list WHERE id=91"):
            raise AssertionError("subscription_detail_changed_order")
    result = {
        "complete": True, "status": "PASS", "checks": checks, "cases": len(checks),
        "actual_catalog": True, "real_php_account_details": True,
        "disposable_sql": True, "network_calls": 0,
    }
    if args.report:
        args.report.write_text(json.dumps(result, ensure_ascii=False) + "\n")
    print(json.dumps(result, ensure_ascii=False))


if __name__ == "__main__":
    main()
