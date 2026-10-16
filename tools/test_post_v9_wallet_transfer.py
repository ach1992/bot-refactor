#!/usr/bin/env python3
"""Actual post-V9 wallet transfer safety on isolated original-schema MariaDB.

Uses current route bytes, two real PHP/mysqli workers, owned Unix-socket DB,
and synthetic Telegram functions only. No live customer/provider access.
"""
from __future__ import annotations

import argparse
import json
import os
import subprocess
import time
from pathlib import Path

import test_access_state as owned
import test_post_v9_purchases as stage_mod
import test_v6_native as native
import test_v8_native as purchase

ROOT = Path(__file__).resolve().parents[1]
DB = purchase.SIDE[1]
TEST_ROUTE = b"<?php\nrequire __DIR__ . '/src/Finance/Routes/WalletTransfer.php';\n"


def query(work, env, sql):
    return native.sql(work, env, sql, DB)


def balances(work, env):
    data = query(work, env, "SELECT userid,wallet,step FROM users WHERE userid IN (1001,9002) ORDER BY userid")
    return [(int(a), int(b), c) for a, b, c in (line.split("\t", 2) for line in data.splitlines())]


def prepare(work, env, sender=50000, recipient=50000, step="tranfserUserAmount9002"):
    purchase.init(work, env, "http://127.0.0.1:9")
    query(work, env, "UPDATE users SET wallet={},step='{}' WHERE userid=1001;".format(sender, step))
    query(work, env, "UPDATE users SET wallet={},step='none' WHERE userid=9002;".format(recipient))


def execute(work, env, stage, text, actor=1001, data=""):
    orig = purchase.block
    try:
        purchase.block = lambda kind, modern: TEST_ROUTE if kind == "wallet_transfer_guard" else orig(kind, modern)
        return purchase.run(work, env, stage, DB, "wallet_transfer_guard",
                            {"actor": actor, "text": text, "data": data}, modern=True)
    finally:
        purchase.block = orig


def assert_events(result, successful):
    if result["exception"] or result["warnings"]:
        raise RuntimeError("Unexpected PHP warning/error in wallet transfer: " + repr(result)[:1200])
    events = result["events"]
    customer_success = any(
        e["name"] == "sendMessage" and "انتقال یافت" in str(e["args"])
        for e in events
    )
    if customer_success != successful:
        raise RuntimeError("Unexpected transfer success messages: " + repr(events)[:1400])
    return events


def worker_cmd(root, work):
    return ["php", "-n", "-d", "extension=mysqlnd", "-d", "extension=mysqli",
            "-d", "extension=curl", "-d", "extension=gd",
            "-d", "open_basedir=" + str(root) + ":" + str(work),
            "-d", "allow_url_fopen=0", "-d", "display_errors=0",
            "-d", "log_errors=0", "-d", "memory_limit=128M",
            "-d", "disable_functions=exec,shell_exec,system,passthru,popen,proc_open,pcntl_exec,pcntl_fork,mail,dl",
            str(root / "native-purchase.php")]


def race(work, env, workers, label):
    barrier = work / ("wallet-transfer-" + label + "-start")
    children = []
    try:
        for number, (actor, amount) in enumerate(workers, start=1):
            stage = work / ("transfer-" + label + "-race-" + str(number))
            stage_mod.stage_source(stage, True)
            (stage / "access-sequence.php").write_bytes(
                b"<?php\n"
                + ("file_put_contents('" + str(barrier) + "." + str(number)
                   + "','ready');\n").encode()
                + ("$until=microtime(true)+8;\n"
                   "while (!file_exists('" + str(barrier)
                   + ".release')) { if (microtime(true)>$until) "
                   "throw new RuntimeException('barrier timeout'); usleep(10000); }\n").encode()
                + TEST_ROUTE.replace(b"<?php\n", b"", 1)
            )
            (stage / "case.json").write_text(json.dumps({
                "actor": actor, "text": amount, "data": ""
            }))
            run_env = dict(env, ACHBOT_PRIVATE_TEST="1", ACHBOT_V7_NATIVE="1",
                           ACHBOT_DATABASE=DB, ACHBOT_SOCKET=str(work / "mysql.sock"))
            child = subprocess.Popen(worker_cmd(stage, work), cwd=stage, env=run_env,
                                     stdout=subprocess.PIPE, stderr=subprocess.PIPE)
            children.append((child, stage))
        deadline = time.monotonic() + 9
        while not all(Path(str(barrier) + "." + str(i)).exists()
                      for i in range(1, len(workers) + 1)):
            if any(p.poll() is not None for p, _ in children):
                raise RuntimeError("Worker failed before synchronized race")
            if time.monotonic() > deadline:
                raise RuntimeError("Worker race start timed out")
            time.sleep(.01)
        Path(str(barrier) + ".release").write_text("go")
        result = []
        for child, stage in children:
            out, err = child.communicate(timeout=16)
            if child.returncode or err or out or not (stage / "result.json").exists():
                raise RuntimeError("Race worker failure: " + repr((child.returncode, out[-300:], err[-300:])))
            report = json.loads((stage / "result.json").read_text())
            if report["exception"] or report["warnings"]:
                raise RuntimeError("Race PHP exception: " + repr(report)[:1400])
            result.append(report)
        return result
    finally:
        for child, _ in children:
            if child.poll() is None:
                child.kill()
                child.wait(timeout=5)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--run-disposable", action="store_true", required=True)
    parser.add_argument("--report", type=Path)
    args = parser.parse_args()
    reports = []
    with owned.owned_database() as (work, env):
        stage = work / "current-wallet"
        stage_mod.stage_source(stage, True)
        prepare(work, env)
        expected = [(1001, 49700, "none"), (9002, 50300, "none")]
        first = execute(work, env, stage, "300")
        assert_events(first, True)
        if balances(work, env) != expected:
            raise RuntimeError("Normal transfer not atomically completed")
        reports.append("transfer_positive_atomic")

        replay = execute(work, env, stage, "300")
        assert_events(replay, False)
        if balances(work, env) != expected:
            raise RuntimeError("Telegram replay duplicated transfer")
        reports.append("transfer_replay_claim_consumed")

        for case, text in [
            ("invalid_exponent", "3e2"), ("invalid_fraction", "300.5"),
            ("invalid_negative", "-300"), ("invalid_overflow", "2147483648"),
            ("invalid_zero", "0"), ("invalid_huge", "9999999999999999999999"),
        ]:
            prepare(work, env)
            before = balances(work, env)
            events = assert_events(execute(work, env, stage, text), False)
            if balances(work, env) != before or not events:
                raise RuntimeError("Invalid amount mutated balances: " + case)
            reports.append("transfer_" + case)

        prepare(work, env, sender=200, recipient=50000)
        before = balances(work, env)
        assert_events(execute(work, env, stage, "300"), False)
        if balances(work, env) != before:
            raise RuntimeError("Insufficient wallet debited")
        reports.append("transfer_insufficient_balance")

        prepare(work, env, sender=-100, recipient=50000)
        before = balances(work, env)
        assert_events(execute(work, env, stage, "300"), False)
        if balances(work, env) != before:
            raise RuntimeError("Negative sender debt deepened")
        reports.append("transfer_negative_debt_no_spend")

        prepare(work, env, sender=50000, recipient=2147483600)
        before = balances(work, env)
        assert_events(execute(work, env, stage, "300"), False)
        if balances(work, env) != before:
            raise RuntimeError("Recipient signed INT overflow not rejected")
        reports.append("transfer_recipient_overflow_refused")

        # Inject a sender-side SQL error AFTER the recipient UPDATE. MariaDB
        # must roll that credit back and only private admin diagnostics may
        # contain SQLSTATE/exception class, never the customer message.
        prepare(work, env)
        before = balances(work, env)
        query(work, env, "CREATE TRIGGER achbot_owned_sender_failure "
              "BEFORE UPDATE ON users FOR EACH ROW "
              "SET NEW.wallet=IF(NEW.userid=1001 AND NEW.wallet<OLD.wallet,"
              "999999999999999,NEW.wallet)")
        try:
            error_result = execute(work, env, stage, "300")
        finally:
            query(work, env, "DROP TRIGGER achbot_owned_sender_failure")
        events = assert_events(error_result, False)
        if balances(work, env) != before:
            raise RuntimeError("Failed second UPDATE did not roll back the first credit")
        private_reports = [e["args"][0] for e in events
                           if e["name"] == "sendMessage" and len(e["args"]) > 3
                           and e["args"][3] == 9001]
        customer_messages = [str(e["args"][0]) for e in events
                             if e["name"] == "sendMessage" and
                             (len(e["args"]) < 4 or e["args"][3] != 9001)]
        if not private_reports or not any("SQLSTATE:" in str(x) and "Reference:" in str(x)
                                           for x in private_reports):
            raise RuntimeError("Failure has no traceable private operator diagnostics")
        if not any("کد پیگیری" in m for m in customer_messages):
            raise RuntimeError("Customer did not receive generic tracking reference")
        if any(any(leak in m for leak in ("SQLSTATE", "mysqli", "MariaDB", "Marzban",
                                         "مرزبان", "پنل", "database"))
               for m in customer_messages):
            raise RuntimeError("Internal diagnostics leaked in customer-facing error")
        reports.append("transfer_debit_failure_rolls_back_and_redacts_user_error")

        # Simulated Telegram failure AFTER COMMIT must never undo the SQL
        # transfer. Continue other notifications and alert the private admin.
        notify_stage = work / "failed-notification-wallet"
        stage_mod.stage_source(notify_stage, True)
        synthetic_php = notify_stage / "native-purchase.php"
        fixture_text = synthetic_php.read_text()
        original_send = "function sendMessage(...$args){achEvent('sendMessage',$args);return (object)['ok'=>true];}"
        altered_send = (
            "function sendMessage(...$args){achEvent('sendMessage',$args);"
            "if (($args[3] ?? null) === 9002) return (object)['ok'=>false];"
            "if (($args[3] ?? null) === 1001 && "
            "($args[0] ?? '') === 'لطفا یکی از کلید های زیر را انتخاب کنید.') "
            "throw new RuntimeException('synthetic_notification_timeout');"
            "return (object)['ok'=>true];}"
        )
        if fixture_text.count(original_send) != 1:
            raise RuntimeError("Synthetic Telegram stub changed unexpectedly")
        synthetic_php.write_text(fixture_text.replace(original_send, altered_send))
        prepare(work, env)
        notice_run = execute(work, env, notify_stage, "300")
        assert_events(notice_run, True)
        if balances(work, env) != [(1001, 49700, "none"), (9002, 50300, "none")]:
            raise RuntimeError("Postcommit notification failure incorrectly reverted money")
        notification_report = [str(event["args"][0]) for event in notice_run["events"]
                               if event["name"] == "sendMessage" and len(event["args"]) > 3
                               and event["args"][3] == 9001]
        if not any("Transfer already committed:" in msg
                   and "recipient,sender_menu" in msg for msg in notification_report):
            raise RuntimeError("Lost Telegram notifications lack private recovery report")
        reports.append("transfer_postcommit_notification_failure_preserves_commit")

        prepare(work, env, sender=50000, recipient=-500)
        assert_events(execute(work, env, stage, "300"), True)
        if balances(work, env) != [(1001, 49700, "none"), (9002, -200, "none")]:
            raise RuntimeError("Genuine recipient credit did not reduce debt")
        reports.append("transfer_receiving_credit_offsets_debt")

        prepare(work, env, step="tranfserUserAmount1001")
        before = balances(work, env)
        assert_events(execute(work, env, stage, "300"), False)
        if balances(work, env) != before:
            raise RuntimeError("Self-transfer not rejected")
        reports.append("transfer_self_refused")

        prepare(work, env, step="tranfserUserAmount999999")
        before = balances(work, env)
        assert_events(execute(work, env, stage, "300"), False)
        if balances(work, env) != before:
            raise RuntimeError("Unknown recipient mutated wallet")
        reports.append("transfer_unknown_recipient")

        prepare(work, env)
        competing = race(work, env, [(1001, "300"), (1001, "300")], "same-step")
        if balances(work, env) != [(1001, 49700, "none"), (9002, 50300, "none")]:
            raise RuntimeError("Concurrent same-step replay double-debited")
        if sum(any(e["name"] == "sendMessage" and "انتقال یافت" in str(e["args"])
                   for e in report["events"]) for report in competing) != 1:
            raise RuntimeError("Concurrent sender transfer had multiple successes")
        reports.append("transfer_two_workers_exactly_one")

        prepare(work, env)
        query(work, env, "UPDATE users SET step='tranfserUserAmount1001' WHERE userid=9002")
        opposing = race(work, env, [(1001, "300"), (9002, "200")], "opposing")
        if balances(work, env) != [(1001, 49900, "none"), (9002, 50100, "none")]:
            raise RuntimeError("Opposing transfers did not both complete: " + repr(balances(work, env)))
        if len(opposing) != 2:
            raise RuntimeError("Opposing transfer race missing report")
        reports.append("transfer_opposing_lock_order_no_deadlock")

    result = {"complete": True, "cases": len(reports), "scenarios": reports,
              "original_mariadb_socket_only": True, "external_requests": 0,
              "active_schema_migration": False}
    if args.report:
        args.report.write_text(json.dumps(result, indent=2, ensure_ascii=False) + "\n")
    print(json.dumps(result, ensure_ascii=False))


if __name__ == "__main__":
    main()
