#!/usr/bin/env python3
"""Source-real unified receipt conversation against a fresh disposable schema."""
import argparse
from concurrent.futures import ThreadPoolExecutor
import json
from pathlib import Path
import subprocess
import time
import database_fixture as owned
from database_fixture import sql_command
from test_current_customer_routes import DRIVER

ROOT=Path(__file__).resolve().parents[1]
DB=owned.NAMES[1]
def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--run-disposable',required=True,action='store_true')
    parser.add_argument('--report',type=Path)
    args=parser.parse_args()
    cases=[]
    with owned.owned_database(ROOT/'database/current-schema.sql') as (w,env):
        def sql(q):return sql_command(w,env,q,DB).strip()
        driver=w/'manual-conversation.php';driver.write_text(DRIVER)
        def run(op='manual_checkout',**kw):
            r=subprocess.run(['php','-d','allow_url_fopen=0','-d','disable_functions=curl_exec,fsockopen,pfsockopen,stream_socket_client,mail,exec,shell_exec,system,passthru,popen,proc_open',str(driver),str(ROOT),str(w/'mysql.sock'),DB,json.dumps(dict(op=op,**kw))],env=env,capture_output=True,text=True,timeout=20)
            assert r.returncode==0,(kw,r.returncode,r.stdout[-2000:],r.stderr[-2000:])
            return json.loads(r.stdout)
        def callbacks(r):
            return [b.get('callback_data','') for e in r['events'] if e[0] in ('send','edit','photo') and e[2]
                    for row in json.loads(e[2]).get('inline_keyboard',[]) for b in row]
        sql("""INSERT INTO setting(type,value) VALUES ('BOT_STATES','{"walletState":"on","cartToCartState":"on","sellState":"on"}'),('PAYMENT_KEYS','{"bankAccount":"fixture-bank","holderName":"fixture-holder"}');
        INSERT INTO users(userid,name,username,refcode,wallet,date) VALUES (2001,'Fixture <&','fixture','0',0,'1700000000'),(2002,'Foreign','foreign','0',0,'1700000000');
        """)
        # A committed top-up with an ambiguous/mismatched Telegram send
        # is recoverable via a fresh exact customer action, not a new draft.
        now=int(time.time())
        sql(f"UPDATE users SET step='achbotWalletTopup:choose:90000:80:{now}' WHERE userid=2002")
        r=run('wallet_recharge',actor=2002,callback='increaseWallet_100000',
              message_id=80,update_id=90001,send_result=None,record_delete=True)
        assert not any(e[0]=='delete' for e in r['events']),r
        pending_id,pending_hash=sql("SELECT id,hash_id FROM pays WHERE user_id=2002").split('\t')
        assert r['user']['step'].startswith(f'achbotWalletTopup:committed:90001:{pending_id}:'),r
        assert sql(f"SELECT COUNT(*) FROM payment_message_refs WHERE pay_row_id={pending_id}")=='0'
        r=run('wallet_recharge',actor=2002,callback='increaseWallet_100000',
              message_id=80,update_id=90001,record_delete=True)
        assert not any(e[0] in ('send','delete') for e in r['events']),r
        r=run('wallet_recharge',actor=2002,callback='increaseWallet_100000',
              message_id=80,update_id=90002,send_result={'ok':True,'result':{'message_id':91,'chat':{'id':2001}}},record_delete=True)
        assert not any(e[0]=='delete' for e in r['events']),r
        assert sql(f"SELECT COUNT(*) FROM payment_message_refs WHERE pay_row_id={pending_id}")=='0'
        r=run('wallet_recharge',actor=2002,callback='increaseMyWallet',
              message_id=79,update_id=90003,sent_id=90,record_delete=True)
        assert 'pm_b_'+pending_hash in callbacks(r) and ['delete',80,2002] in r['events'],r
        assert sql(f"SELECT message_id FROM payment_message_refs WHERE pay_row_id={pending_id}")=='90'
        assert sql("SELECT COUNT(*) FROM pays WHERE user_id=2002")=='1'
        assert sql("SELECT COUNT(*) FROM wallet_entries")=='0'
        r=run('wallet_recharge',actor=2002,callback='increaseMyWallet',
              message_id=79,update_id=90003,record_delete=True)
        assert not any(e[0]=='send' for e in r['events']),r
        cases.append('committed_topup_selector_recovery_exact_owner_no_second_draft')
        r=run('cancel_idle',actor=2002,text=subprocess.check_output(['php','-r',"require 'messages/loader.php';echo achbotMessage('legacy_buttons.cancel');"],cwd=ROOT,text=True))
        assert r['user']['step']=='none' and not any('لغو شد' in str(e[1]) for e in r['events'] if e[0]=='send'),r
        cases.append('completed_topup_is_not_cancelled_editor')
        sql(f"UPDATE users SET step='achbotWalletTopup:amount:100000:81:{int(time.time())}' WHERE userid=2001")
        r=run('topup',sent_id=201)
        assert ['topup','created'] in r['events'],r
        pid,h=sql("SELECT id,hash_id FROM pays WHERE user_id=2001").split('\t')
        assert 'pm_b_'+h in callbacks(r) and 'dc_'+h in callbacks(r),r
        for spec in ({'actor':2002},{'chat':-10001,'chat_type':'group'}):
            r=run(callback='pm_b_'+h,**spec)
            assert 'rs_'+h not in callbacks(r),r
            assert sql('SELECT COUNT(*) FROM payment_attempts')=='0'
        # Concurrent method callbacks share the original session lease.
        with ThreadPoolExecutor(max_workers=2) as pool:
            pending=[pool.submit(run,callback='pm_b_'+h,message_id=201) for _ in range(2)]
            replies=[f.result() for f in pending]
        assert sum(e[0]=='edit' for result in replies for e in result['events'])==1,replies
        assert not any(e[0]=='send' for result in replies for e in result['events']),replies
        r=next(result for result in replies if any(e[0]=='edit' for e in result['events']))
        assert 'rs_'+h in callbacks(r) and 'ph_'+h in callbacks(r),r
        assert len([e for e in r['events'] if e[0]=='edit'])==1 and not any(e[0] in ('send','delete') for e in r['events']),r
        assert sql(f"SELECT purpose FROM payment_message_refs WHERE pay_row_id={pid} AND message_id=201")=='invoice'
        r=run(callback='pm_b_'+h,message_id=201)
        assert not any(e[0] in ('send','delete','edit') for e in r['events']),r
        assert sql(f"SELECT COUNT(*) FROM payment_attempts WHERE pay_row_id={pid}")=='1'
        assert sql(f"SELECT provider FROM payment_attempts WHERE pay_row_id={pid}")=='bank_transfer'
        cases.append('unified_method_issue_exact_owner_and_retained_invoice')
        r=run(callback='rs_'+h,message_id=201,sent_id=202)
        step=r['user']['step']
        assert step.startswith('receipt:'+h+':') and step.endswith(':202'),r
        assert 'rc_'+h in callbacks(r) and not any(e[0]=='delete' for e in r['events']),r
        r=run(callback='rc_'+h,message_id=201)
        assert r['user']['step']==step and not any(e[0] in ('send','edit','delete') for e in r['events']),r
        r=run(text='not-a-photo',message_id=203,sent_id=204)
        assert r['user']['step'].endswith(':204') and 'rc_'+h in callbacks(r),r
        assert len([e for e in r['events'] if e[0]=='send'])==1 and not any(e[0]=='edit' for e in r['events']),r
        assert ['delete',202,2001] in r['events'] and ['delete',203,2001] not in r['events'],r
        step=r['user']['step']
        r=run(callback='rs_'+h,message_id=201)
        assert r['user']['step']==step and not any(e[0]=='send' for e in r['events']),r
        for result in (None,{'ok':False,'error_code':429,'description':'Too Many Requests','parameters':{'retry_after':10}},
                       {'ok':False,'error_code':400,'description':"Bad Request: can't parse entities"},
                       {'ok':True,'result':{'message_id':206,'chat':{'id':2002}}}):
            r=run(text='not-a-photo',message_id=205,send_result=result)
            assert r['user']['step']==step and sum(e[0]=='send' for e in r['events'])==1,r
            assert not any(e[0] in ('edit','delete') for e in r['events']),r
        r=run(text='not-a-photo',message_id=207,sent_id=208)
        assert r['user']['step'].endswith(':208') and ['delete',204,2001] in r['events'],r
        step=r['user']['step']
        r=run(callback='rc_'+h,message_id=204)
        assert r['user']['step']==step and not any(e[0] in ('send','edit','delete') for e in r['events']),r
        cases.append('chronological_evidence_feedback_exact_ack_retires_only_previous_prompt')
        r=run(callback='rc_'+h,message_id=208)
        assert r['user']['step']=='none',r
        assert sql(f"SELECT state FROM pays WHERE id={pid}")=='pending'
        cases.append('prompt_reuse_and_exact_cancel_no_financial_cancellation')
        r=run(callback='rs_'+h,message_id=201,sent_id=209)
        assert r['user']['step'].endswith(':209'),r
        r=run(photo='fixture-photo',message_id=210,sent_id=215)
        assert r['user']['step']=='none' and any(e[0]=='photo' and e[4]==9001 for e in r['events']),r
        assert ['delete',209,2001] in r['events'] and ['delete',210,2001] not in r['events'],r
        assert sql(f"SELECT state FROM pays WHERE id={pid}")=='have_sent'
        assert sql(f"SELECT stage FROM payment_attempts WHERE pay_row_id={pid}")=='review'
        assert sql('SELECT COUNT(*) FROM wallet_entries')=='0'
        cases.append('original_receipt_routes_to_staff_without_credit_or_human_delete')
        # Actual admitted receipt: normal monitoring preserves staff actions,
        # but a dispatched external operation fences negative decisions.
        def staff_buttons(r):
            return [b for e in r['events'] if e[0]=='markup' and e[2]
                    for row in json.loads(e[2]).get('inline_keyboard',[]) for b in row]
        sql(f"UPDATE payment_attempts SET evidence_state='monitoring' WHERE pay_row_id={pid}")
        r=run('staff_notice',pay=int(pid))
        bs=staff_buttons(r)
        assert 'declineAction_'+h in [b['callback_data'] for b in bs],r
        assert any(b['callback_data']=='attentionOpenCase_'+pid and 'در انتظار بررسی خودکار' in b['text'] for b in bs),r
        aid=sql(f"SELECT id FROM payment_attempts WHERE pay_row_id={pid}")
        sql(f"INSERT INTO payment_transactions(attempt_id,provider,payment_id,evidence_operation,evidence_state) VALUES ({aid},'bank_transfer','fixture-pending',REPEAT('b',64),'dispatched')")
        sql(f"UPDATE payment_attempts SET evidence_state='uncertain' WHERE pay_row_id={pid}")
        r=run('staff_notice',pay=int(pid))
        bs=staff_buttons(r)
        assert bs and 'declineAction_'+h not in [b['callback_data'] for b in bs],r
        assert any(b['callback_data']=='attentionOpenCase_'+pid for b in bs),r
        sql(f"DELETE FROM payment_transactions WHERE attempt_id={aid}")
        sql(f"UPDATE payment_attempts SET evidence_state=NULL WHERE id={aid}")
        cases.append('staff_monitoring_actions_and_original_evidence_negative_fence')

        assert ['delete',201,2001] in r['events'],r
        assert sql(f"SELECT purpose FROM payment_message_refs WHERE pay_row_id={pid} AND message_id=215")=='receipt_view'
        assert sql(f"SELECT retired_reason FROM payment_message_refs WHERE pay_row_id={pid} AND message_id=201")=='receipt_replaced'
        assert not any(e[0]=='edit' and e[4]==215 for e in r['events']),r
        current=run('payment_status',callback='ps_'+h,message_id=215,update_id=2000)
        assert not any(e[0] in ('edit','send') for e in current['events']),current
        original=sql(f"SELECT checkout_url,quote_amount,wallet_invoice_sha256 FROM payment_attempts WHERE pay_row_id={pid}")
        # A genuine credit is independent of the mutable receipt status.
        run('settle_fixture',pay=int(pid))
        assert sql(f"SELECT state FROM pays WHERE id={pid}")=='paid'
        r=run('projection',pay=int(pid))
        edits=[e for e in r['events'] if e[0]=='edit']
        assert len(edits)==1 and edits[0][4]==215 and 'به کیف پول شما اضافه شد' in edits[0][1],r
        assert 'مهلت پرداخت' not in edits[0][1] and 'fixture-bank' not in edits[0][1],r
        assert sql(f"SELECT checkout_url,quote_amount,wallet_invoice_sha256 FROM payment_attempts WHERE pay_row_id={pid}")==original
        intent=int(sql(f"SELECT id FROM payment_followup_intents WHERE pay_row_id={pid} AND stage='wallet_credit' AND role='customer'"))
        r=run('dispatch_fixture',intent=intent,sent_id=216)
        assert ['dispatch','acknowledged'] in r['events'] and sum(e[0]=='send' for e in r['events'])==1,r
        assert '100,000' in next(e[1] for e in r['events'] if e[0]=='send'),r
        for event_id in (2001,2002):
            r=run('payment_status',callback='ps_'+h,message_id=215,update_id=event_id)
            assert not any(e[0] in ('edit','send') for e in r['events']),r
        r=run('dispatch_fixture',intent=intent)
        assert ['dispatch','acknowledged'] in r['events'] and not any(e[0]=='send' for e in r['events']),r
        assert sql(f"SELECT COUNT(*) FROM wallet_entries WHERE source_kind='payment_credit' AND source_ref='{pid}'")=='1'
        cases.append('same_receipt_status_and_separate_actual_credit_notification')
        # A definite lost view can recover on a user click; an ambiguous
        # replacement send cannot be repeated by a replay of that update.
        sql(f"UPDATE payment_message_refs SET synced_digest=NULL WHERE pay_row_id={pid} AND message_id=215")
        missing={'ok':False,'error_code':400,'description':'Bad Request: message to edit not found'}
        r=run('payment_status',callback='ps_'+h,message_id=215,update_id=2003,edit_result=missing,send_result=None)
        assert sum(e[0]=='send' for e in r['events'])==1,r
        r=run('payment_status',callback='ps_'+h,message_id=215,update_id=2003)
        assert not any(e[0]=='send' for e in r['events']),r
        r=run('payment_status',callback='ps_'+h,message_id=215,update_id=2004,sent_id=217)
        assert sum(e[0]=='send' for e in r['events'])==1,r
        assert sql(f"SELECT message_id FROM payment_message_refs WHERE pay_row_id={pid} AND purpose='receipt_view' AND retired_reason IS NULL")=='217'
        r=run('payment_status',callback='ps_'+h,message_id=217,update_id=2005)
        assert not any(e[0]=='send' for e in r['events']),r
        cases.append('definite_receipt_target_loss_explicit_recovery_no_blind_replay')
        # A selector that becomes Home is no longer a financial projection
        # target. Cancelling it never changes the original money state.
        sql(f"UPDATE users SET step='achbotWalletTopup:amount:100001:81:{int(time.time())}' WHERE userid=2001")
        r=run('topup',sent_id=220)
        assert ['topup','created'] in r['events'],r
        pid2,h2=sql("SELECT id,hash_id FROM pays WHERE user_id=2001 ORDER BY id DESC LIMIT 1").split('\t')
        r=run(callback='dc_'+h2,message_id=220)
        assert any(e[0]=='edit' for e in r['events']) and not any(e[0]=='send' for e in r['events']),r
        assert sql(f"SELECT retired_reason FROM payment_message_refs WHERE pay_row_id={pid2} AND message_id=220")=='navigation'
        r=run(callback='dc_'+h2,message_id=220)
        assert not any(e[0] in ('send','edit','delete') for e in r['events']),r
        assert sql(f"SELECT state FROM pays WHERE id={pid2}")=='pending'
        assert sql(f"SELECT COUNT(*) FROM payment_attempts WHERE pay_row_id={pid2}")=='0'
        cases.append('draft_cancel_retires_selector_only')
        # Admission can commit just before the request exits. The next
        # common projector promotes the same selector, using its pinned
        # original destination rather than new payment settings.
        def draft(mid):
            sql(f"UPDATE users SET step='achbotWalletTopup:amount:{mid}:81:{int(time.time())}' WHERE userid=2002")
            r=run('topup',actor=2002,sent_id=mid,update_id=mid+1)
            assert ['topup','created'] in r['events'],r
            return sql("SELECT id,hash_id FROM pays WHERE user_id=2002 ORDER BY id DESC LIMIT 1").split('\t')
        p3,h3=draft(230)
        assert ['claim',True] in run('manual_claim',actor=2002,pay=int(p3),method='bank_transfer',mode='topup')['events']
        original=sql(f"SELECT checkout_url,quote_amount,wallet_invoice_sha256 FROM payment_attempts WHERE pay_row_id={p3}")
        sql("UPDATE setting SET value=JSON_OBJECT('bankAccount','new-bank','holderName','new-holder') WHERE type='PAYMENT_KEYS'")
        # A stale cancellation delivered before Cron must recover the
        # admitted invoice instead of retiring its last selector.
        r=run(actor=2002,callback='dc_'+h3,message_id=230)
        assert sql(f"SELECT retired_reason IS NULL FROM payment_message_refs WHERE pay_row_id={p3}")=='1'
        edits=[e for e in r['events'] if e[0]=='edit']
        assert len(edits)==1 and edits[0][4]==230 and 'fixture-bank' in edits[0][1] and 'new-bank' not in edits[0][1],r
        assert sql(f"SELECT purpose FROM payment_message_refs WHERE pay_row_id={p3}")=='invoice'
        assert sql(f"SELECT checkout_url,quote_amount,wallet_invoice_sha256 FROM payment_attempts WHERE pay_row_id={p3}")==original
        sql(f"UPDATE pays SET state='expired' WHERE id={p3}")
        r=run('projection',actor=2002,pay=int(p3))
        edits=[e for e in r['events'] if e[0]=='edit']
        assert len(edits)==1 and 'fixture-bank' not in edits[0][1] and 'new-bank' not in edits[0][1] and 'مهلت پرداخت' not in edits[0][1],r
        assert sql(f"SELECT checkout_url,quote_amount,wallet_invoice_sha256 FROM payment_attempts WHERE pay_row_id={p3}")==original
        cases.append('committed_invoice_recovered_in_place_and_original_facts_preserved')

        # A rate limit or unknown edit must never cause a second send,
        # or let an explicit status click bypass the provider wait.
        p4,h4=draft(240)
        limit={'ok':False,'error_code':429,'description':'Too Many Requests','parameters':{'retry_after':90}}
        r=run(actor=2002,callback='pm_b_'+h4,message_id=240,edit_result=limit)
        assert sum(e[0]=='edit' for e in r['events'])==1 and not any(e[0] in ('send','markup') for e in r['events']),r
        assert int(sql(f"SELECT TIMESTAMPDIFF(SECOND,NOW(6),updated_at) FROM payment_message_refs WHERE pay_row_id={p4}"))>=85
        for r in (run('payment_status',actor=2002,callback='ps_'+h4,message_id=240),
                  run(actor=2002,callback='pm_b_'+h4,message_id=240)):
            assert not any(e[0] in ('edit','markup','send') for e in r['events']),r
        sql(f"UPDATE payment_message_refs SET updated_at=NOW(6)-INTERVAL 1 SECOND WHERE pay_row_id={p4}")
        r=run('projection',actor=2002,pay=int(p4))
        assert sum(e[0]=='edit' for e in r['events'])==1 and not any(e[0]=='send' for e in r['events']),r
        assert sql(f"SELECT COUNT(*) FROM payment_attempts WHERE pay_row_id={p4}")=='1'
        cases.append('rate_limit_respected_by_cron_and_explicit_status_replay')

        # Once Home owns a selector, even an ambiguous edit cannot make
        # that old reference available to later payment projections.
        p5,h5=draft(250)
        r=run(actor=2002,callback='dc_'+h5,message_id=250,edit_result=None)
        assert sum(e[0]=='edit' for e in r['events'])==1 and not any(e[0]=='send' for e in r['events']),r
        sql(f"UPDATE pays SET draft_expires_at=UNIX_TIMESTAMP()-1 WHERE id={p5}")
        r=run('projection',actor=2002,pay=int(p5))
        assert not any(e[0] in ('edit','markup','send') for e in r['events']),r
        r=run(actor=2002,callback='pm_b_'+h5,message_id=250)
        assert sql(f"SELECT COUNT(*) FROM payment_attempts WHERE pay_row_id={p5}")=='0'
        assert sql(f"SELECT retired_reason FROM payment_message_refs WHERE pay_row_id={p5}")=='navigation'
        cases.append('ambiguous_home_edit_cannot_revive_selector_or_admit_stale_payment')

        # Explicit status recovery from a verified retained message:
        # an unknown send result is not an ACK and cannot be replayed.
        r=run('payment_status',actor=2002,callback='ps_'+h5,message_id=251,update_id=3000,send_result=None)
        assert sum(e[0]=='send' for e in r['events'])==1,r
        for event_id in (3000,2999):
            r=run('payment_status',actor=2002,callback='ps_'+h5,message_id=251,update_id=event_id)
            assert not any(e[0]=='send' for e in r['events']),r
        r=run('payment_status',actor=2002,callback='ps_'+h5,message_id=251,update_id=3001,sent_id=252)
        assert sum(e[0]=='send' for e in r['events'])==1,r
        assert sql(f"SELECT COUNT(*) FROM payment_message_refs WHERE pay_row_id={p5} AND purpose='status' AND retired_reason IS NULL")=='1'
        r=run('payment_status',actor=2002,callback='ps_'+h5,message_id=251,update_id=3001)
        assert not any(e[0]=='send' for e in r['events']),r
        cases.append('status_send_unknown_replay_fenced_and_new_click_recovers')
        # An orphaned credit proves financial evidence even without an
        # attempt FK. Neither fresh method selection nor cancellation may
        # discard it. Keep the original journal and a recovery path.
        p6,h6=draft(260)
        sql(f"INSERT INTO wallet_entries(source_kind,source_ref,leg,user_row_id,userid,delta_toman,balance_before,balance_after,actor_userid,created_at) SELECT 'payment_credit','{p6}',0,id,userid,260,0,260,9001,UTC_TIMESTAMP(6) FROM users WHERE userid=2002")
        original=sql(f"SELECT id,delta_toman,balance_after FROM wallet_entries WHERE source_kind='payment_credit' AND source_ref='{p6}'")
        r=run(actor=2002,callback='pm_b_'+h6,message_id=260)
        assert not any(e[0] in ('send','delete') for e in r['events']),r
        assert any(e[0]=='edit' and 'نیازمند بررسی' in e[1] for e in r['events']),r
        assert 'ps_'+h6 in callbacks(r),r
        assert sql(f"SELECT COUNT(*) FROM payment_attempts WHERE pay_row_id={p6}")=='0'
        assert ['claim',False] in run('manual_claim',actor=2002,pay=int(p6),method='bank_transfer',mode='topup')['events']
        r=run(actor=2002,callback='dc_'+h6,message_id=260)
        assert not any(e[0] in ('send','delete') for e in r['events']),r
        assert sql(f"SELECT retired_reason IS NULL FROM payment_message_refs WHERE pay_row_id={p6}")=='1'
        sql(f"UPDATE pays SET draft_expires_at=UNIX_TIMESTAMP()-1 WHERE id={p6}")
        sql(f"UPDATE payment_message_refs SET synced_digest=NULL WHERE pay_row_id={p6}")
        r=run('payment_status',actor=2002,callback='ps_'+h6,message_id=260)
        edits=[e for e in r['events'] if e[0]=='edit']
        assert edits and 'نیازمند بررسی' in edits[-1][1] and 'ps_'+h6 in callbacks(r),r
        assert 'مهلت انتخاب روش پرداخت پایان یافت' not in edits[-1][1] and 'اضافه می‌شود' not in edits[-1][1],r
        assert sql(f"SELECT id,delta_toman,balance_after FROM wallet_entries WHERE source_kind='payment_credit' AND source_ref='{p6}'")==original
        cases.append('contradictory_credit_keeps_original_recovery_and_never_reissues')

        # A browser writer need not hold the Telegram session lease. The
        # original pay row lock must order cancellation after its commit.
        p7,h7=draft(270)
        client=['mariadb','--no-defaults','--protocol=SOCKET','--socket='+str(w/'mysql.sock'),
                '-uroot','--batch','--skip-column-names','--unbuffered',DB]
        with subprocess.Popen(client,stdin=subprocess.PIPE,stdout=subprocess.PIPE,
                              stderr=subprocess.PIPE,text=True,env=env,bufsize=1) as writer:
            try:
                writer.stdin.write(f"START TRANSACTION; SELECT id FROM pays WHERE id={p7} FOR UPDATE;\n")
                writer.stdin.flush()
                assert writer.stdout.readline().strip()==p7
                with ThreadPoolExecutor(max_workers=1) as pool:
                    pending=pool.submit(run,actor=2002,callback='dc_'+h7,message_id=270)
                    try:
                        # Match the actual prepared locking query. MariaDB
                        # 10.11 does not reliably expose INNODB_LOCK_WAITS
                        # (see the existing physical reservation race).
                        probe=subprocess.run(client,input=f"START TRANSACTION; SELECT id FROM pays WHERE id={p7} FOR UPDATE NOWAIT; ROLLBACK;\n",
                            text=True,env=env,capture_output=True,timeout=3)
                        assert probe.returncode!=0 and 'ERROR 1205' in probe.stderr,probe
                        wait_sql=("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE DB=DATABASE() "
                            "AND COMMAND='Execute' AND TIME>=1 AND INFO LIKE 'SELECT id FROM pays WHERE id=% AND user_id=% FOR UPDATE'")
                        deadline=time.monotonic()+8
                        while time.monotonic()<deadline and not pending.done():
                            if int(sql(wait_sql)):
                                break
                            time.sleep(.05)
                        else:
                            raise AssertionError(('navigation did not await original pay lock',
                                pending.result() if pending.done() else sql('SHOW FULL PROCESSLIST')))
                        writer.stdin.write(f"UPDATE pays SET payid='browser-reserved-fixture' WHERE id={p7}; COMMIT; SELECT 'committed';\n")
                        writer.stdin.flush()
                        assert writer.stdout.readline().strip()=='committed'
                    finally:
                        writer.stdin.write("ROLLBACK;\n")
                        writer.stdin.flush()
                    r=pending.result(timeout=15)
                assert sql(f"SELECT retired_reason IS NULL FROM payment_message_refs WHERE pay_row_id={p7}")=='1'
                assert sql(f"SELECT payid FROM pays WHERE id={p7}")=='browser-reserved-fixture'
                assert not any(e[0] in ('send','delete') for e in r['events']),r
                assert 'ps_'+h7 in callbacks(r),r
            finally:
                writer.stdin.close()
                writer.wait(timeout=5)
        cases.append('navigation_orders_after_concurrent_browser_admission')


    result={'complete':True,'status':'PASS','cases':len(cases),'scenarios':cases,'production_mutations':0,'external_requests':0}
    if args.report:args.report.write_text(json.dumps(result))
    print(json.dumps(result))
if __name__=='__main__':main()
