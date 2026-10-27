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
TEST_ROUTE = rb"""<?php
$message_id=(int)($spec['message_id']??$message_id);
$update=(object)['update_id'=>(int)($spec['update_id']??(8000+$from_id)),
    'message'=>(object)['from'=>(object)['id'=>$from_id,'is_bot'=>false,'username'=>'fixture','first_name'=>'Fixture'],
        'chat'=>(object)['id'=>$chat_id,'type'=>'private'],
        'message_id'=>$message_id,'text'=>$text]];
if ($data !== '') {
    $update->message->reply_markup=(object)['inline_keyboard'=>[]];
    $update->callback_query=(object)['id'=>'owned-transfer-callback',
        'from'=>$update->message->from,'message'=>$update->message,'data'=>$data];
    unset($update->message);
}
// Exercise actual binding bytes, then vary only the advertised source mismatch.
require __DIR__.'/src/Telegram/UpdateBindings.php';
if (isset($spec['cached_step'])) $userInfo['step']=$spec['cached_step'];
if (isset($spec['wire_mutation'])) {
    $wireMessage=$update->message??($update->callback_query->message??null);
    $wireSender=$update->callback_query->from??($wireMessage->from??null);
    switch ($spec['wire_mutation']) {
        case 'callback': $update=(object)['update_id'=>$update->update_id,
            'callback_query'=>(object)['message'=>$update->message]]; break;
        case 'group': $wireMessage->chat->type='group'; break;
        case 'actor': $wireSender->id=9999; break;
        case 'chat': $wireMessage->chat->id=9999; break;
        case 'message': $wireMessage->message_id++; break;
        case 'text':
            if (isset($update->callback_query)) $update->callback_query->data='forged-start';
            else $wireMessage->text='301';
            break;
        case 'missing_update': unset($update->update_id); break;
    }
}
require __DIR__ . '/src/Finance/Routes/WalletTransfer.php';
"""


def query(work, env, sql):
    return native.sql(work, env, sql, DB)


def balances(work, env):
    data = query(work, env, "SELECT userid,wallet,step FROM users WHERE userid IN (1001,9002) ORDER BY userid")
    return [(int(a), int(b), c) for a, b, c in (line.split("\t", 2) for line in data.splitlines())]


def seed_flow(work, env, actor, step):
    # Explicit fixture for direct monetary-route tests. Lifecycle regressions
    # below use actual authenticated start/selection routes instead.
    import re
    amount = re.fullmatch(r"achbotTransferAmount:(\d+):(\d+):(\d+):(\d+):(\d+)", step)
    select = re.fullmatch(r"achbotTransferRecipient:(\d+):(\d+)", step)
    if not amount and not select:
        return
    actor_pk = int(query(work, env, "SELECT id FROM users WHERE userid="+str(actor)).splitlines()[0])
    if amount:
        recipient, uid, mid, start_uid, start_mid = map(int, amount.groups())
        found=query(work,env,"SELECT id FROM users WHERE userid="+str(recipient)).splitlines()
        recipient_pk=int(found[0]) if found else 0
        phase="amount"
    else:
        start_uid,start_mid=map(int,select.groups())
        recipient=recipient_pk=uid=mid=0
        phase="recipient"
    state=dict(version=1,actor_id=actor,actor_pk=actor_pk,
        start_update_id=start_uid,start_message_id=start_mid,start_message_floor=start_mid,
        selection_update_id=uid,selection_message_id=mid,
        last_update_id=uid or start_uid,last_message_id=mid or start_mid,
        recipient_id=recipient,recipient_pk=recipient_pk,stage=phase)
    kind="ACHBOT_WALLET_TRANSFER_"+str(actor)
    query(work,env,"DELETE FROM setting WHERE type='"+kind+"'")
    raw=json.dumps(state,separators=(",",":"))
    query(work,env,"INSERT INTO setting(type,value) VALUES ('"+kind+"','"+raw+"')")


def prepare(work, env, sender=50000, recipient=50000,
            step="achbotTransferAmount:9002:8000:70:7900:69"):

    purchase.init(work, env, "http://127.0.0.1:9")
    query(work, env, "DROP TABLE IF EXISTS wallet_adjustments")
    query(work, env, (ROOT / 'migrations/post-v9/005_wallet_adjustments.sql').read_text())
    query(work, env, "UPDATE users SET wallet={},step='{}' WHERE userid=1001;".format(sender, step))
    query(work, env, "UPDATE users SET wallet={},step='none' WHERE userid=9002;".format(recipient))
    query(work,env,"DELETE FROM setting WHERE type LIKE 'ACHBOT_WALLET_TRANSFER_%'")
    seed_flow(work,env,1001,step)


def execute(work, env, stage, text, actor=1001, data="", **source):
    orig = purchase.block
    try:
        purchase.block = lambda kind, modern: TEST_ROUTE if kind == "wallet_transfer_guard" else orig(kind, modern)
        return purchase.run(work, env, stage, DB, "wallet_transfer_guard",
                            {"actor": actor, "text": text, "data": data, **source}, modern=True)
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
        for number, (actor, amount, *extra) in enumerate(workers, start=1):
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
                "actor": actor, "text": amount, "data": "", **(extra[0] if extra else {})
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
        baseline_stage = work / "baseline-wallet"
        stage_mod.stage_source(baseline_stage, True)
        (baseline_stage / "src/Finance/Routes/WalletTransfer.php").write_bytes(
            subprocess.check_output(["git","-C",str(ROOT),"show",
                "ea6834cb0612b5fa687ec492fbd9c6e1fd0b6a50:src/Finance/Routes/WalletTransfer.php"]))
        prepare(work, env, step="tranfserUserAmount9002")
        assert_events(execute(work, env, baseline_stage, "300"),True)
        if balances(work, env) != [(1001,49700,"none"),(9002,50300,"none")] or query(
                work, env,"SELECT COUNT(*) FROM wallet_adjustments").strip() != "0":
            raise RuntimeError("Baseline no longer reproduces unjournaled transfer")
        reports.append("transfer_baseline_red_money_moves_without_journal")
        prepare(work, env)
        expected = [(1001, 49700, "none"), (9002, 50300, "none")]
        first = execute(work, env, stage, "300")
        assert_events(first, True)
        if balances(work, env) != expected:
            raise RuntimeError("Normal transfer not atomically completed")
        rows = query(work, env, "SELECT CONCAT(source_type,':',source_id,':',entry_no,"
            "':',actor_userid,':',target_userid,':',adjustment_type,':',amount_toman,"
            "':',before_balance,':',after_balance,':',telegram_update_id,':',"
            "source_chat_id,':',source_message_id) FROM wallet_adjustments ORDER BY entry_no")
        expected_rows = ("transfer:9001:1:1001:1001:transfer_out:300:50000:49700:9001:1001:71\n"
                         "transfer:9001:2:1001:9002:transfer_in:300:50000:50300:9001:1001:71")
        if rows.strip() != expected_rows:
            raise RuntimeError("Transfer legs do not retain exact identity and balances: "+rows)
        reports.append("transfer_positive_atomic")
        reports.append("transfer_paired_immutable_journal_exact_evidence")

        # Redelivery cannot debit again even if the UI starts another transfer.
        # Unique journal identity is independent of the consumed legacy step.
        query(work, env, "UPDATE users SET step='achbotTransferAmount:9002:8000:70:7900:69' WHERE userid=1001")
        seed_flow(work,env,1001,"achbotTransferAmount:9002:8000:70:7900:69")
        before = balances(work, env)
        assert_events(execute(work, env, stage, "300"), False)
        if balances(work, env) != before or query(work, env,
                "SELECT COUNT(*) FROM wallet_adjustments").strip() != "2":
            raise RuntimeError("Restarted step replay duplicated historical transfer")
        reports.append("transfer_journal_redelivery_after_step_restart_no_effect")
        query(work, env, "UPDATE users SET step='none' WHERE userid=1001")


        replay = execute(work, env, stage, "300")
        assert_events(replay, False)
        if balances(work, env) != expected:
            raise RuntimeError("Telegram replay duplicated transfer")
        reports.append("transfer_replay_claim_consumed")

        for mutation in ('callback','group','actor','chat','message','text','missing_update'):
            prepare(work, env)
            before = balances(work, env)
            assert_events(execute(work, env, stage, "300",wire_mutation=mutation),False)
            if balances(work, env) != before or query(work, env,
                    "SELECT COUNT(*) FROM wallet_adjustments").strip() != "0":
                raise RuntimeError("Invalid transfer source mutated money: "+mutation)
            reports.append("transfer_source_rejects_"+mutation)

        for drift in ("DROP TABLE wallet_adjustments",
                      "ALTER TABLE wallet_adjustments DROP INDEX uq_wallet_source_entry",
                      "ALTER TABLE wallet_adjustments DROP INDEX uq_wallet_telegram_entry",
                      "ALTER TABLE wallet_adjustments ENGINE=MyISAM",
                      "ALTER TABLE wallet_adjustments MODIFY source_id VARCHAR(64) COLLATE utf8mb4_unicode_ci NOT NULL"):
            prepare(work, env)
            before = balances(work, env)
            query(work, env, drift)
            assert_events(execute(work, env, stage, "300"),False)
            if balances(work, env) != before:
                raise RuntimeError("Incompatible journal permitted transfer: "+drift)
        reports.append("transfer_missing_or_incompatible_journal_fails_closed")

        # Second-leg failure happens AFTER BOTH monetary writes and first leg.
        # Test-only trigger forces a real MariaDB insert failure.
        prepare(work, env)
        before=balances(work, env)
        query(work, env, "CREATE TRIGGER achbot_owned_journal_failure "
              "BEFORE INSERT ON wallet_adjustments FOR EACH ROW "
              "SET NEW.entry_no=IF(NEW.entry_no=2,9999,NEW.entry_no)")
        try:
            assert_events(execute(work, env, stage, "300"),False)
        finally:
            query(work, env, "DROP TRIGGER achbot_owned_journal_failure")
        if balances(work, env) != before or query(work, env,
                "SELECT COUNT(*) FROM wallet_adjustments").strip() != "0":
            raise RuntimeError("Second-leg insert failure left money or half journal")
        reports.append("transfer_second_journal_leg_failure_rolls_back_everything")

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

        # The real Telegram transport also returns None on a connection
        # failure. No response is NOT an acknowledged delivery.
        stub = synthetic_php.read_text()
        false_delivery = "if (($args[3] ?? null) === 9002) return (object)['ok'=>false];"
        if stub.count(false_delivery) != 1:
            raise RuntimeError("Expected synthetic failed-delivery stub absent")
        synthetic_php.write_text(stub.replace(
            false_delivery, "if (($args[3] ?? null) === 9002) return null;"
        ))
        prepare(work, env)
        missing_ack = execute(work, env, notify_stage, "300")
        assert_events(missing_ack, True)
        if balances(work, env) != [(1001, 49700, "none"), (9002, 50300, "none")]:
            raise RuntimeError("No-response Telegram delivery changed committed money")
        missing_reports = [str(e["args"][0]) for e in missing_ack["events"]
                           if e["name"] == "sendMessage" and len(e["args"]) > 3
                           and e["args"][3] == 9001]
        if not any("recipient,sender_menu" in msg and
                   "transfer_notification_unconfirmed" in msg for msg in missing_reports):
            raise RuntimeError("Telegram null response incorrectly counted as delivered")
        reports.append("transfer_telegram_null_is_unconfirmed_not_success")

        prepare(work, env, sender=50000, recipient=-500)
        assert_events(execute(work, env, stage, "300"), True)
        if balances(work, env) != [(1001, 49700, "none"), (9002, -200, "none")]:
            raise RuntimeError("Genuine recipient credit did not reduce debt")
        reports.append("transfer_receiving_credit_offsets_debt")

        prepare(work, env, step="achbotTransferAmount:1001:8000:70:7900:69")
        before = balances(work, env)
        assert_events(execute(work, env, stage, "300"), False)
        if balances(work, env) != before:
            raise RuntimeError("Self-transfer not rejected")
        reports.append("transfer_self_refused")

        prepare(work, env, step="achbotTransferAmount:999999:8000:70:7900:69")
        before = balances(work, env)
        assert_events(execute(work, env, stage, "300"), False)
        if balances(work, env) != before:
            raise RuntimeError("Unknown recipient mutated wallet")
        reports.append("transfer_unknown_recipient")

        # userid has a non-unique index in the immutable original schema.
        # If there are duplicates, SELECT ... FOR UPDATE must inspect ALL rows
        # and both wallets/steps must stay unchanged after fail-closed rejection.
        def all_accounts():
            raw = query(work, env,
                        "SELECT id,userid,wallet,step FROM users ORDER BY id")
            return [tuple(line.split("\t")) for line in raw.splitlines()]

        def assert_ambiguous_identity(label, identity):
            before = all_accounts()
            failure = execute(work, env, stage, "300")
            events = assert_events(failure, False)
            if all_accounts() != before:
                raise RuntimeError("Ambiguous " + label + " mutated any account record")
            private_messages = [str(e["args"][0]) for e in events
                       if e["name"] == "sendMessage" and len(e["args"]) > 3
                       and e["args"][3] == 9001]
            users = [str(e["args"][0]) for e in events
                     if e["name"] == "sendMessage" and
                     (len(e["args"]) < 4 or e["args"][3] != 9001)]
            if not any(identity in x and "Reference:" in x for x in private_messages):
                raise RuntimeError("Missing bounded private duplicate identity reason")
            if not any("کد پیگیری" in x for x in users) or any(
                    identity in x or "SQLSTATE" in x for x in users):
                raise RuntimeError("Duplicate identity exposed to customer")
            reports_list = label + "_duplicate_userid_fails_closed"
            reports.append(reports_list)

        prepare(work, env)
        query(work, env,
              "INSERT INTO users (userid,name,username,refcode,wallet,date,step) "
              "VALUES (1001,'Collision','collision','0',900000,'1700000000','different_step')")
        try:
            assert_ambiguous_identity("sender", "transfer_sender_identity_ambiguous")
        finally:
            query(work, env, "DELETE FROM users WHERE name='Collision' AND userid=1001")

        prepare(work, env)
        query(work, env,
              "INSERT INTO users (userid,name,username,refcode,wallet,date,step) "
              "VALUES (9002,'Collision','collision','0',-900,'1700000000','unrelated_step')")
        try:
            assert_ambiguous_identity("recipient", "transfer_recipient_identity_ambiguous")
        finally:
            query(work, env, "DELETE FROM users WHERE name='Collision' AND userid=9002")

        # Two workers execute the same check-then-insert registration shape
        # against the original schema. Both pass the check before either
        # inserts, demonstrating why the route may NOT assume userid unique.
        registration_barrier = work / "achbot-synthetic-registration-release"
        php_registration = (
            "$db=new mysqli('localhost','root','','" + DB + "',0,$argv[1]);"
            "$r=$db->query('SELECT COUNT(*) FROM users WHERE userid=77777');"
            "if((int)$r->fetch_row()[0]!==0)throw new Exception('already_registered');"
            "file_put_contents($argv[2],'ready');"
            "$until=microtime(true)+8;"
            "while(!file_exists($argv[3])){"
            "if(microtime(true)>$until)throw new Exception('registration_timeout');"
            "usleep(10000);}"
            "$db->query(\"INSERT INTO users (userid,name,username,refcode,wallet,date,step) "
            "VALUES (77777,'Concurrent','concurrent','0',120,'1700000000','none')\");"
        )
        reg_workers = []
        try:
            for worker in range(2):
                ready = str(registration_barrier) + "." + str(worker)
                p = subprocess.Popen([
                    "php", "-n", "-d", "extension=mysqlnd", "-d", "extension=mysqli",
                    "-r", php_registration, str(work / "mysql.sock"),
                    ready, str(registration_barrier)
                ], stdout=subprocess.PIPE, stderr=subprocess.PIPE, env=env)
                reg_workers.append((p, ready))
            cutoff = time.monotonic() + 9
            while not all(Path(ready).exists() for _, ready in reg_workers):
                if any(p.poll() is not None for p, _ in reg_workers):
                    raise RuntimeError("Synthetic registration exited before barrier")
                if time.monotonic() > cutoff:
                    raise RuntimeError("Synthetic registration barrier timeout")
                time.sleep(.01)
            registration_barrier.write_text("go")
            for p, _ in reg_workers:
                out, err = p.communicate(timeout=10)
                if p.returncode or out or err:
                    raise RuntimeError("Registration worker failed: " + repr((out, err)))
            if query(work, env, "SELECT COUNT(*) FROM users WHERE userid=77777").strip() != "2":
                raise RuntimeError("Expected two concurrent registration identities")
            prepare(work, env, step="achbotTransferAmount:77777:8000:70:7900:69")
            before = all_accounts()
            failure = execute(work, env, stage, "300")
            assert_events(failure, False)
            if all_accounts() != before:
                raise RuntimeError("Concurrent duplicate registration allowed wallet effect")
            reports.append("transfer_concurrent_registration_duplicate_identity_rejected")
        finally:
            for p, _ in reg_workers:
                if p.poll() is None:
                    p.kill()
                    p.wait(timeout=5)
            query(work, env, "DELETE FROM users WHERE userid=77777")

        # This fixture calls a REAL MariaDB COMMIT and then loses the client
        # acknowledgement. A subsequent successful rollback API call must
        # never be treated as proof that the money was reverted.
        def make_commit_fixture(name, override):
            fixture_stage = work / name
            stage_mod.stage_source(fixture_stage, True)
            php_path = fixture_stage / "native-purchase.php"
            code = php_path.read_text()
            declaration = "class SyntheticCommitConnection extends mysqli {\n" + override + "\n}\n"
            needle = "$connection = new mysqli('localhost', 'root', '', $db, 0, $socket);"
            if code.count(needle) != 1:
                raise RuntimeError("Commit fixture could not locate DB initialization")
            php_path.write_text(code.replace(
                needle,
                declaration + "$connection = new SyntheticCommitConnection('localhost', 'root', '', $db, 0, $socket);"
            ))
            return fixture_stage

        ambiguous_stage = make_commit_fixture("commit-ack-lost", (
            "public function commit(int $flags=0, ?string $name=null): bool {"
            "$result=parent::commit($flags,$name);"
            "if (!$result) return false;"
            "throw new RuntimeException('provider_secret_ACK_LOST_123');}"
        ))
        prepare(work, env)
        unknown = execute(work, env, ambiguous_stage, "300")
        events = assert_events(unknown, False)
        if balances(work, env) != [(1001, 49700, "none"), (9002, 50300, "none")]:
            raise RuntimeError("Ambiguous ack fixture did not really COMMIT the transfer")
        messages = [str(e["args"][0]) for e in events if e["name"] == "sendMessage"]
        if not any("Transaction outcome: <code>unknown</code>" in m and
                   "Rollback API: <code>returned_success</code>" in m and
                   "Commit attempted: <code>yes</code>" in m for m in messages):
            raise RuntimeError("Operator was not warned of ambiguous committed result")
        if any("provider_secret_ACK_LOST_123" in m for m in messages):
            raise RuntimeError("Raw commit exception leaked to Telegram")
        if not any("نیاز به بررسی" in m for m in messages):
            raise RuntimeError("Customer did not receive review guidance")
        if query(work, env,"SELECT COUNT(*) FROM wallet_adjustments").strip() != "2":
            raise RuntimeError("Real committed lost acknowledgement lacks journal pair")
        reports.append("transfer_real_commit_lost_ack_outcome_unknown")

        false_stage = make_commit_fixture("commit-returned-false", (
            "public function commit(int $flags=0, ?string $name=null): bool {"
            "return false;}"
        ))
        prepare(work, env)
        before = balances(work, env)
        ambiguous_false = execute(work, env, false_stage, "300")
        events = assert_events(ambiguous_false, False)
        if balances(work, env) != before or not any(
            "Transaction outcome: <code>unknown</code>" in str(e["args"][0])
            for e in events if e["name"] == "sendMessage"
        ):
            raise RuntimeError("False COMMIT return was treated as confirmed failure")
        reports.append("transfer_false_commit_return_is_unknown")

        # mysqli can also be configured to return false instead of raising.
        # A failed transaction start must never permit bare monetary writes.
        begin_false_stage = make_commit_fixture("begin-returned-false", (
            "public function begin_transaction(int $flags=0, ?string $name=null): bool {"
            "return false;}"
        ))
        prepare(work, env)
        before = balances(work, env)
        begin_false = execute(work, env, begin_false_stage, "300")
        events = assert_events(begin_false, False)
        if balances(work, env) != before or not any(
            "transfer_begin_failed" in str(e["args"][0]) and
            "Transaction outcome: <code>not_started</code>" in str(e["args"][0])
            for e in events if e["name"] == "sendMessage"
        ):
            raise RuntimeError("Failed BEGIN incorrectly allowed a monetary effect")
        reports.append("transfer_false_begin_return_fails_closed")

        # An unsuccessful rollback API result after a pre-commit failure is
        # also unknown; the DB connection close subsequently cancels writes.
        rollback_false_stage = make_commit_fixture("rollback-returned-false", (
            "public function rollback(int $flags=0, ?string $name=null): bool {"
            "$result=parent::rollback($flags,$name);"
            "return false;}"
        ))
        prepare(work, env)
        before = balances(work, env)
        query(work, env, "CREATE TRIGGER achbot_rollback_api_failure "
              "BEFORE UPDATE ON users FOR EACH ROW "
              "SET NEW.wallet=IF(NEW.userid=1001 AND NEW.wallet<OLD.wallet,"
              "999999999999999,NEW.wallet)")
        try:
            uncertain_rollback = execute(work, env, rollback_false_stage, "300")
        finally:
            query(work, env, "DROP TRIGGER achbot_rollback_api_failure")
        events = assert_events(uncertain_rollback, False)
        if balances(work, env) != before or not any(
            "Transaction outcome: <code>unknown</code>" in str(e["args"][0])
            and "Rollback API: <code>returned_failure</code>" in str(e["args"][0])
            for e in events if e["name"] == "sendMessage"
        ):
            raise RuntimeError("False rollback API return was reported as proven rollback")
        reports.append("transfer_false_rollback_return_outcome_unknown")

        prepare(work, env)
        competing = race(work, env, [(1001, "300"), (1001, "300")], "same-step")
        if balances(work, env) != [(1001, 49700, "none"), (9002, 50300, "none")]:
            raise RuntimeError("Concurrent same-step replay double-debited")
        if sum(any(e["name"] == "sendMessage" and "انتقال یافت" in str(e["args"])
                   for e in report["events"]) for report in competing) != 1:
            raise RuntimeError("Concurrent sender transfer had multiple successes")
        if query(work, env,"SELECT COUNT(*) FROM wallet_adjustments").strip() != "2":
            raise RuntimeError("Concurrent replay did not produce exactly one journal pair")
        reports.append("transfer_two_workers_exactly_one")

        prepare(work, env)
        query(work, env, "UPDATE users SET step='achbotTransferAmount:1001:8000:70:7900:69' WHERE userid=9002")
        seed_flow(work,env,9002,"achbotTransferAmount:1001:8000:70:7900:69")
        opposing = race(work, env, [(1001, "300"), (9002, "200")], "opposing")
        if balances(work, env) != [(1001, 49900, "none"), (9002, 50100, "none")]:
            raise RuntimeError("Opposing transfers did not both complete: " + repr(balances(work, env)))
        if len(opposing) != 2:
            raise RuntimeError("Opposing transfer race missing report")
        if query(work, env,"SELECT COUNT(*) FROM wallet_adjustments").strip() != "4":
            raise RuntimeError("Opposing transfers lack two complete journal pairs")
        reports.append("transfer_opposing_lock_order_no_deadlock")

    result = {"complete": True, "cases": len(reports), "scenarios": reports,
              "original_mariadb_socket_only": True, "external_requests": 0,
              "active_schema_migration": False, "baseline_red": True}
    if args.report:
        args.report.write_text(json.dumps(result, indent=2, ensure_ascii=False) + "\n")
    print(json.dumps(result, ensure_ascii=False))


if __name__ == "__main__":
    main()
