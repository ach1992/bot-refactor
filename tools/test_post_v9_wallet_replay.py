#!/usr/bin/env python3
"""PR #85 replay fixes: actual routes, owned MariaDB, reviewed-source RED/current GREEN."""
from __future__ import annotations
import argparse
import json
import subprocess
from pathlib import Path

import test_access_state as owned
import test_post_v9_admin_wallet as admin
import test_post_v9_purchases as stage_mod
import test_post_v9_wallet_transfer as transfer

ROOT = Path(__file__).resolve().parents[1]
REVIEWED = "388e06358bef9f62dec75a7ebfe7db13a669e282"
MIGRATION = "migrations/post-v9/005_wallet_adjustments.sql"


def require(ok, reason):
    if not ok:
        raise RuntimeError(reason)


def sql(work, env, statement):
    return transfer.query(work, env, statement).strip()


def accounts(work, env):
    return sql(work, env, "SELECT userid,wallet,step FROM users ORDER BY id")


def rows(work, env):
    return int(sql(work, env, "SELECT COUNT(*) FROM wallet_adjustments"))


def setstep(work, env, actor, step):
    admin.setstep(work, env, actor, step)


def pending(actor=9002, update=9000, message=90, legacy=False):
    return ("tranfserUserAmount" + str(actor) if legacy else
            f"achbotTransferAmount:{actor}:{update}:{message}")


def reset(work, env, legacy=False, step="transferMyWallet"):
    transfer.prepare(work, env, step=step)
    if legacy:
        sql(work, env, "DROP TABLE wallet_adjustments")
        sql(work, env, subprocess.check_output(
            ["git", "-C", str(ROOT), "show", REVIEWED + ":" + MIGRATION], text=True))


def call_admin(work, env, stage, **spec):
    original = admin.ROOT
    try:
        admin.ROOT = stage
        return admin.execute(work, env, **spec)
    finally:
        admin.ROOT = original


def transfer_event(work, env, stage, text="300", actor=1001,
                   update=9001, message=91, **spec):
    return transfer.execute(work, env, stage, text, actor=actor,
                            update_id=update, message_id=message, **spec)


def cross_operation(work, env, stage, legacy, admin_first):
    reset(work, env, legacy)
    if admin_first:
        admin.move_to_reason(work, env, "credit", 300, actor=9001, target=9002)
        result = call_admin(work, env, stage, actor=9001, text="300",
                            update_id=9100, message_id=91)
        require(any("✅" in m for m in admin.messages(result)) and rows(work, env) == 1,
                "First administrator adjustment did not consume one event/leg")
        setstep(work, env, 9001, pending(9002, legacy=legacy))
        before = accounts(work, env)
        result = transfer_event(work, env, stage, actor=9001, update=9100)
        transfer.assert_events(result, legacy)
        if legacy:
            require(rows(work, env) == 3 and accounts(work, env) != before,
                    "Reviewed baseline did not reproduce admin-to-transfer reuse")
        else:
            require(rows(work, env) == 1 and accounts(work, env) == before,
                    "Admin event reused by a transfer")
            # A second attempt cannot leave half a pair or consume the step.
            transfer.assert_events(transfer_event(
                work, env, stage, actor=9001, update=9100), False)
            require(rows(work, env) == 1 and accounts(work, env) == before,
                    "Repeated cross-operation collision changed balances/journal")
    else:
        setstep(work, env, 9001, pending(9002, legacy=legacy))
        transfer.assert_events(transfer_event(
            work, env, stage, actor=9001, update=9100), True)
        require(rows(work, env) == 2, "Normal transfer did not commit exactly two legs")
        legs = sql(work, env, "SELECT CONCAT(telegram_update_id,':',entry_no,':',"
            "adjustment_type) FROM wallet_adjustments ORDER BY entry_no")
        require(legs == "9100:1:transfer_out\n9100:2:transfer_in",
                "Transfer event/leg identities differ")
        admin.move_to_reason(work, env, "credit", 300, actor=9001, target=1001)
        before = accounts(work, env)
        for report_off in ((False,) if legacy else (False, True)):
            result = call_admin(work, env, stage, actor=9001, text="300",
                update_id=9100, message_id=91, mysqli_report_off=report_off)
            if legacy:
                require(rows(work, env) == 3 and accounts(work, env) != before,
                        "Reviewed baseline did not reproduce transfer-to-admin reuse")
            else:
                require(rows(work, env) == 2 and accounts(work, env) == before,
                        "Transfer event reused by an administrator adjustment")
                require(not any("✅" in m for m in admin.messages(result)),
                        "Cross-operation collision falsely reported success")


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--run-disposable", action="store_true", required=True)
    ap.add_argument("--report", type=Path)
    opts = ap.parse_args()
    cases = []
    with owned.owned_database() as (work, env):
        (work / "owned-wallet-driver.php").write_text(admin.PHP)
        current = work / "current-replay"
        reviewed = work / "reviewed-replay"
        stage_mod.stage_source(current, True)
        stage_mod.stage_source(reviewed, True)
        driver = current / "native-purchase.php"
        driver.write_text(driver.read_text().replace("<?php\n",
            "<?php\nini_set('error_log',__DIR__.'/owned-selection.log');\n",1))
        for path in ("src/Finance/Routes/WalletTransfer.php",
                     "src/Finance/Routes/AdminWallet.php", "src/Finance/WalletAdjustments.php"):
            (reviewed / path).write_bytes(subprocess.check_output(
                ["git", "-C", str(ROOT), "show", REVIEWED + ":" + path]))

        # Source-level RED: a genuine older numeric message moves money under
        # the reviewed implementation after the actual selection route runs.
        reset(work, env, True)
        transfer_event(work, env, reviewed, "9002", update=9000, message=90)
        require(admin.step(work, env, 1001) == pending(legacy=True),
                "Reviewed recipient selection changed")
        transfer.assert_events(transfer_event(
            work, env, reviewed, update=8999, message=89), True)
        require(rows(work, env) == 2, "Reviewed stale-source RED was not reproduced")
        cases.append("r1_reviewed_source_red_accepts_older_amount")

        for admin_first in (True, False):
            cross_operation(work, env, reviewed, True, admin_first)
            cases.append("r2_reviewed_source_red_" +
                         ("admin_then_transfer" if admin_first else "transfer_then_admin"))

        for name, uid, mid, text in (
            ("older_prior_stage", 8900, 80, "300"),
            ("recipient_selection_redelivery", 9000, 90, "9002"),
            ("older_update_newer_message", 8999, 91, "300"),
            ("newer_update_older_message", 9001, 89, "300"),
            ("equal_update", 9000, 91, "300"),
            ("equal_message", 9001, 90, "300"),
        ):
            reset(work, env)
            transfer_event(work, env, current, "9002", update=9000, message=90)
            require(admin.step(work, env, 1001) == pending(),
                    "Actual selection did not persist both source markers")
            before = accounts(work, env)
            result = transfer_event(work, env, current, text, update=uid, message=mid)
            transfer.assert_events(result, False)
            require(accounts(work, env) == before and rows(work, env) == 0,
                    "Stale source changed balances/step/journal: " + name)
            require(any("شروع دوباره انتقال" in str(e["args"])
                        for e in result["events"] if e["name"] == "sendMessage"),
                    "Rejected source lacks understandable restart action")
            cases.append("r1_reject_" + name)

        # Real callback restart, real selection, stale redelivery, fresh amount.
        reset(work, env)
        transfer_event(work, env, current, "9002", update=9000, message=90)
        transfer.assert_events(transfer_event(work, env, current), True)
        require(rows(work, env) == 2, "New amount did not produce a complete pair")
        transfer_event(work, env, current, "menu", data="transferMyWallet",
                       update=9100, message=100)
        transfer_event(work, env, current, "9002", update=9200, message=110)
        before = accounts(work, env)
        transfer.assert_events(transfer_event(work, env, current), False)
        require(accounts(work, env) == before and rows(work, env) == 2,
                "Old amount reapplied after actual flow restart")
        cases.append("r1_amount_redelivery_after_real_restart_rejected")
        # An old cached selection worker must not overwrite this newer stage.
        transfer_event(work, env, current, "9002", update=9300, message=120,
                       cached_step="transferMyWallet")
        require(accounts(work, env) == before and rows(work, env) == 2,
                "Stale selection worker replaced a newer pending transfer")
        cases.append("r1_stale_selection_snapshot_cannot_overwrite_new_stage")
        transfer.assert_events(transfer_event(
            work, env, current, update=9201, message=111), True)
        require(rows(work, env) == 4, "Genuinely newer amount was not independently committed")
        cases.append("r1_genuinely_newer_amount_commits_exact_pair")

        for step in ("tranfserUserAmount9002", "achbotTransferAmount:9002",
                     "achbotTransferAmount:9002:0:90"):
            reset(work, env, step=step)
            before = accounts(work, env)
            result = transfer_event(work, env, current)
            transfer.assert_events(result, False)
            require(accounts(work, env) == before and rows(work, env) == 0,
                    "Legacy/malformed pending flow authorized money")
        cases.append("r1_legacy_unmarked_and_malformed_steps_require_restart")

        for mutation in ("callback", "group", "actor", "chat", "message", "text", "missing_update"):
            reset(work, env)
            before = accounts(work, env)
            transfer.assert_events(transfer_event(
                work, env, current, "9002", wire_mutation=mutation), False)
            require(accounts(work, env) == before and rows(work, env) == 0,
                    "Invalid recipient selection acquired a financial marker: " + mutation)
        cases.append("r1_recipient_selection_requires_authentic_private_source")

        reset(work, env)
        before = accounts(work, env)
        sql(work, env, "CREATE TRIGGER achbot_owned_selection_failure "
            "BEFORE UPDATE ON users FOR EACH ROW SET NEW.step=REPEAT('x',1200)")
        try:
            result = transfer_event(work, env, current, "9002", update=9000, message=90)
            transfer.assert_events(result, False)
        finally:
            sql(work, env, "DROP TRIGGER achbot_owned_selection_failure")
        require(accounts(work, env) == before and rows(work, env) == 0,
                "Failed selection claim left a pending stage or monetary effect")
        customer = [str(e["args"][0]) for e in result["events"] if e["name"] == "sendMessage"]
        require(any("مشکل" in m for m in customer) and not any(
                x in m for m in customer for x in ("SQLSTATE","mysqli","step","database")),
                "Selection error leaked diagnostics or concealed the affected action")
        log = (current / "owned-selection.log").read_text()
        require("update_id=9000" in log and "sqlstate=" in log and
                "reason=transfer_selection_database_or_runtime_error" in log and
                "Data too long" not in log,
                "Protected selection diagnostics lack safe source/reason correlation")
        cases.append("r1_selection_sql_failure_rolls_back_and_separates_diagnostics")

        for admin_first in (True, False):
            cross_operation(work, env, current, False, admin_first)
            cases.append("r2_current_" +
                         ("admin_then_transfer_rejected" if admin_first else "transfer_then_admin_rejected"))

        report = dict(complete=True, cases=len(cases), scenarios=cases,
                      reviewed_source=REVIEWED, baseline_red=True, current_green=True,
                      real_php_mysqli=True, owned_mariadb_only=True,
                      production_mutations=0, real_telegram_or_provider_calls=0)
    if opts.report:
        opts.report.write_text(json.dumps(report, indent=2) + "\n")
    print(json.dumps(report))


if __name__ == "__main__":
    main()
