#!/usr/bin/env python3
"""R3: actual transfer generations on owned MariaDB, reviewed RED/current GREEN."""
from __future__ import annotations
import argparse
import json
from pathlib import Path
import subprocess

import test_access_state as owned
import test_post_v9_purchases as staging
import test_post_v9_wallet_transfer as transfer

ROOT=Path(__file__).resolve().parents[1]
REVIEWED="18aa3629290db60a1a6e7c9be9c9f47464dcf333"


def require(ok, why):
    if not ok: raise RuntimeError(why)


def q(work,env,sql):
    return transfer.query(work,env,sql).strip()


def wallets(work,env):
    return q(work,env,"SELECT userid,wallet,step FROM users ORDER BY id")


def ledger(work,env):
    return q(work,env,"SELECT source_type,source_id,entry_no,target_userid,amount_toman "
             "FROM wallet_adjustments ORDER BY id")


def cursor(work,env,actor=1001):
    return q(work,env,"SELECT id,type,value FROM setting "
             "WHERE type='ACHBOT_WALLET_TRANSFER_"+str(actor)+"' ORDER BY id")


def state(work,env,actor=1001):
    return json.loads(q(work,env,"SELECT value FROM setting "
        "WHERE type='ACHBOT_WALLET_TRANSFER_"+str(actor)+"'"))


def snapshot(work,env):
    return wallets(work,env),ledger(work,env),cursor(work,env)


def call(work,env,stage,uid,mid,text="",data="",success=False,**extra):
    result=transfer.execute(work,env,stage,text,data=data,
                            update_id=uid,message_id=mid,**extra)
    transfer.assert_events(result,success)
    return result


def start(work,env,stage,uid=8000,mid=70):
    return call(work,env,stage,uid,mid,"menu","transferMyWallet")


def select(work,env,stage,uid=8100,mid=80,target="9002"):
    return call(work,env,stage,uid,mid,target)


def amount(work,env,stage,uid=8200,mid=81,value="300",success=True):
    return call(work,env,stage,uid,mid,value,success=success)


def reset(work,env):
    transfer.prepare(work,env,step="none")


def complete_a(work,env,stage):
    start(work,env,stage)
    select(work,env,stage)
    amount(work,env,stage)


def main():
    ap=argparse.ArgumentParser()
    ap.add_argument("--run-disposable",action="store_true",required=True)
    ap.add_argument("--report",type=Path)
    opts=ap.parse_args(); cases=[]
    with owned.owned_database() as (work,env):
        current=work/"current-generation"; old=work/"reviewed-generation"
        staging.stage_source(current,True); staging.stage_source(old,True)
        driver=current/"native-purchase.php"
        driver.write_text(driver.read_text().replace("<?php\n",
            "<?php\nini_set('error_log',__DIR__.'/owned-generation.log');\n",1))
        for name in ("src/Finance/Routes/WalletTransfer.php","src/Finance/WalletAdjustments.php"):
            (old/name).write_bytes(subprocess.check_output(
                ["git","-C",str(ROOT),"show",REVIEWED+":"+name]))

        # Exact reported sequence, without synthetic state/cursor injection.
        reset(work,env); complete_a(work,env,old)
        start(work,env,old,9000,90)
        before=wallets(work,env)
        select(work,env,old)  # delayed A selection U8100 after B start U9000
        require(wallets(work,env)!=before,"Reviewed ABA selection defect not reproduced")
        # This fresh numeric value is intended as B's recipient Y=9001.
        call(work,env,old,9100,91,"9001",success=True)
        require(q(work,env,"SELECT wallet FROM users WHERE userid=1001")=="40699" and
                q(work,env,"SELECT wallet FROM users WHERE userid=9002")=="59301" and
                q(work,env,"SELECT wallet FROM users WHERE userid=9001")=="50000",
                "Reviewed RED did not misinterpret recipient Y as amount for X")
        cases.append("r3_reviewed_red_old_selection_hijacks_new_generation")

        reset(work,env); complete_a(work,env,current)
        require(state(work,env)["stage"]=="complete" and
                state(work,env)["last_update_id"]==8200,
                "Committed transfer did not retain replay ownership")
        start(work,env,current,9000,90)
        require(q(work,env,"SELECT step FROM users WHERE userid=1001")==
                "achbotTransferRecipient:9000:90","Start B is not versioned")
        before=snapshot(work,env)
        select(work,env,current)
        require(snapshot(work,env)==before,"A selection captured B's recipient stage")
        cases.append("r3_old_selection_after_new_start_preserves_entire_state")

        for uid,mid in ((8000,70),(9000,90),(8500,75)):
            start(work,env,current,uid,mid)
            require(snapshot(work,env)==before,"Stale/replayed start regressed B")
        cases.append("r3_old_duplicate_and_out_of_order_starts_preserve_recipient_stage")

        # Previously cached A state cannot match the actor-locked B generation.
        call(work,env,current,8100,80,"9002",
             cached_step="achbotTransferRecipient:8000:70")
        require(snapshot(work,env)==before,"Cached A worker overwrote B")
        cases.append("r3_stale_cached_selection_cannot_claim_new_generation")

        select(work,env,current,9100,91,"9001")
        require(state(work,env)["recipient_id"]==9001 and
                state(work,env)["start_update_id"]==9000,
                "Fresh B selection did not own the intended recipient")
        before=snapshot(work,env)
        start(work,env,current,9050,90)
        require(snapshot(work,env)==before,"A delayed start regressed B amount stage")
        amount(work,env,current,9200,92,"200")
        require(q(work,env,"SELECT wallet FROM users WHERE userid=1001")=="49500" and
                q(work,env,"SELECT wallet FROM users WHERE userid=9002")=="50300" and
                q(work,env,"SELECT wallet FROM users WHERE userid=9001")=="50200" and
                q(work,env,"SELECT COUNT(*) FROM wallet_adjustments")=="4",
                "Fresh B amount moved money to an incorrect recipient or left partial legs")
        cases.append("r3_fresh_b_selection_and_amount_use_recipient_y")

        before=snapshot(work,env)
        start(work,env,current,8000,70); start(work,env,current,9000,90)
        require(snapshot(work,env)==before,"Completed step lost durable start watermark")
        cases.append("r3_completed_flow_rejects_old_start_callbacks")

        start(work,env,current,9300,100)
        # Models the existing generic navigation/cancellation step write:
        # the transfer cursor itself must NOT be erased by that write.
        q(work,env,"UPDATE users SET step='none' WHERE userid=1001")
        before=snapshot(work,env)
        start(work,env,current,9300,100)
        require(snapshot(work,env)==before,"Generic step reset erased start replay ownership")
        start(work,env,current,9400,110)
        select(work,env,current,9500,111,"9002")
        amount(work,env,current,9600,112,"50")
        require(q(work,env,"SELECT COUNT(*) FROM wallet_adjustments")=="6",
                "Fresh flow after navigation did not complete normally")
        cases.append("r3_navigation_reset_retains_cursor_and_new_flow_still_completes")

        # A new callback may reference an older still-visible wallet menu.
        # Retain the last known user-message floor across that start.
        start(work,env,current,9700,70)
        require(state(work,env)["start_message_id"]==70 and
                state(work,env)["start_message_floor"]==112,
                "Old menu lowered the durable message boundary")
        before=snapshot(work,env)
        select(work,env,current,9800,100,"9001")
        require(snapshot(work,env)==before,"Older message ID entered a new flow")
        select(work,env,current,9800,113,"9001")
        amount(work,env,current,9900,114,"25")
        cases.append("r3_old_menu_reuse_never_lowers_private_message_floor")

        # Compare-and-claim under a single actor lock: duplicate and reordered
        # real callback workers must converge to the newest generation.
        reset(work,env)
        reports=transfer.race(work,env,[
            (1001,"menu",dict(data="transferMyWallet",update_id=10000,message_id=120)),
            (1001,"menu",dict(data="transferMyWallet",update_id=10000,message_id=120)),
        ],"duplicate-start")
        prompts=sum("شناسه عددی کاربری که" in str(e["args"][0])
                    for r in reports for e in r["events"] if e["name"]=="sendMessage")
        require(prompts==1 and state(work,env)["start_update_id"]==10000 and not ledger(work,env),
                "Duplicate callback workers both claimed a generation")
        cases.append("r3_two_start_workers_claim_exactly_once")
        reset(work,env)
        transfer.race(work,env,[
            (1001,"menu",dict(data="transferMyWallet",update_id=10000,message_id=120)),
            (1001,"menu",dict(data="transferMyWallet",update_id=11000,message_id=121)),
        ],"reordered-start")
        require(state(work,env)["start_update_id"]==11000 and not ledger(work,env),
                "Reordered callback workers regressed the latest generation")
        cases.append("r3_reordered_start_workers_converge_to_newest_generation")

        # Invalid source envelopes may not create or alter the durable cursor.
        for mutation in ("group","actor","chat","message","text","missing_update"):
            reset(work,env)
            before=snapshot(work,env)
            start_spec=dict(wire_mutation=mutation)
            call(work,env,current,10000,120,"menu","transferMyWallet",**start_spec)
            require(snapshot(work,env)==before,"Invalid callback changed flow state")
        cases.append("r3_start_requires_authentic_private_callback")

        # No metadata drift or ambiguous row may permit a fallback start.
        reset(work,env); start(work,env,current)
        before=wallets(work,env)
        q(work,env,"INSERT INTO setting(type,value) SELECT type,value FROM setting "
          "WHERE type='ACHBOT_WALLET_TRANSFER_1001'")
        start(work,env,current,9000,90)
        require(wallets(work,env)==before and not ledger(work,env),
                "Ambiguous durable cursor authorized a new flow")
        cases.append("r3_duplicate_cursor_fails_closed")
        reset(work,env); start(work,env,current)
        q(work,env,"UPDATE setting SET value='not-json' WHERE type='ACHBOT_WALLET_TRANSFER_1001'")
        before=snapshot(work,env); start(work,env,current,9000,90)
        require(snapshot(work,env)==before,"Malformed cursor was silently replaced")
        cases.append("r3_malformed_cursor_fails_closed")
        reset(work,env)
        q(work,env,"ALTER TABLE setting ENGINE=MyISAM")
        before=snapshot(work,env)
        try: start(work,env,current)
        finally: q(work,env,"ALTER TABLE setting ENGINE=InnoDB")
        require(snapshot(work,env)==before,"Nontransactional cursor permitted a start")
        cases.append("r3_nontransactional_cursor_fails_closed")

        # Losing any cursor write must roll back BOTH financial legs and money.
        reset(work,env); start(work,env,current); select(work,env,current)
        before=snapshot(work,env)
        q(work,env,"CREATE TRIGGER achbot_owned_cursor_failure BEFORE UPDATE ON setting "
          "FOR EACH ROW SET NEW.value=IF(JSON_UNQUOTE(JSON_EXTRACT(NEW.value,'$.stage'))="
          "'complete',REPEAT('x',70000),NEW.value)")
        try: amount(work,env,current,success=False)
        finally: q(work,env,"DROP TRIGGER achbot_owned_cursor_failure")
        require(snapshot(work,env)==before,"Cursor failure left money or partial journal")
        cases.append("r3_cursor_write_failure_rolls_back_balances_legs_and_step")

        # Incarnation binding avoids paying a replacement row that was not selected.
        reset(work,env); start(work,env,current); select(work,env,current)
        q(work,env,"DELETE FROM users WHERE userid=9002")
        q(work,env,"INSERT INTO users(userid,name,username,refcode,wallet,date,step) "
          "VALUES(9002,'Replacement','replacement','0',50000,'1700000000','none')")
        before=snapshot(work,env); amount(work,env,current,success=False)
        require(snapshot(work,env)==before,"Selected recipient incarnation was replaced silently")
        cases.append("r3_replaced_recipient_requires_new_selection")

        report=dict(complete=True,cases=len(cases),scenarios=cases,reviewed_source=REVIEWED,
                    baseline_red=True,current_green=True,real_php_mysqli=True,
                    owned_mariadb_only=True,production_mutations=0,external_requests=0)
    if opts.report: opts.report.write_text(json.dumps(report,indent=2)+"\n")
    print(json.dumps(report))


if __name__=="__main__":
    main()
