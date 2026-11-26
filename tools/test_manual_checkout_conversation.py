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
        r=run(text='not-a-photo',message_id=203)
        assert r['user']['step']==step and 'rc_'+h in callbacks(r),r
        assert len([e for e in r['events'] if e[0]=='edit'])==1 and not any(e[0]=='send' for e in r['events']),r
        r=run(callback='rs_'+h,message_id=201)
        assert r['user']['step']==step and not any(e[0]=='send' for e in r['events']),r
        for result in (None,{'ok':False,'error_code':429,'description':'Too Many Requests','parameters':{'retry_after':10}},
                       {'ok':False,'error_code':400,'description':"Bad Request: can't parse entities"}):
            r=run(text='not-a-photo',message_id=203,edit_result=result)
            assert r['user']['step']==step and not any(e[0] in ('send','delete') for e in r['events']),r
        r=run(text='not-a-photo',message_id=203,edit_result={'ok':False,'error_code':400,'description':'Bad Request: message to edit not found'},sent_id=208)
        assert r['user']['step'].endswith(':208') and ['delete',202,2001] in r['events'],r
        step=r['user']['step']
        r=run(callback='rc_'+h,message_id=202)
        assert r['user']['step']==step and not any(e[0] in ('send','edit','delete') for e in r['events']),r
        cases.append('definite_target_rejection_replaces_prompt_unknown_and_rate_limit_do_not')
        r=run(callback='rc_'+h,message_id=208)
        assert r['user']['step']=='none',r
        assert sql(f"SELECT state FROM pays WHERE id={pid}")=='pending'
        cases.append('prompt_reuse_and_exact_cancel_no_financial_cancellation')
        r=run(callback='rs_'+h,message_id=201,sent_id=209)
        assert r['user']['step'].endswith(':209'),r
        r=run(photo='fixture-photo',message_id=210)
        assert r['user']['step']=='none' and any(e[0]=='photo' and e[4]==9001 for e in r['events']),r
        assert ['delete',209,2001] in r['events'] and ['delete',210,2001] not in r['events'],r
        assert sql(f"SELECT state FROM pays WHERE id={pid}")=='have_sent'
        assert sql(f"SELECT stage FROM payment_attempts WHERE pay_row_id={pid}")=='review'
        assert sql('SELECT COUNT(*) FROM wallet_entries')=='0'
        cases.append('original_receipt_routes_to_staff_without_credit_or_human_delete')
        # A selector that becomes Home is no longer a financial projection
        # target. Cancelling it never changes the original money state.
        sql(f"UPDATE users SET step='achbotWalletTopup:amount:100001:81:{int(time.time())}' WHERE userid=2001")
        r=run('topup',sent_id=211)
        assert ['topup','created'] in r['events'],r
        pid2,h2=sql("SELECT id,hash_id FROM pays WHERE user_id=2001 ORDER BY id DESC LIMIT 1").split('\t')
        r=run(callback='dc_'+h2,message_id=211)
        assert any(e[0]=='edit' for e in r['events']) and not any(e[0]=='send' for e in r['events']),r
        assert sql(f"SELECT retired_reason FROM payment_message_refs WHERE pay_row_id={pid2} AND message_id=211")=='navigation'
        r=run(callback='dc_'+h2,message_id=211)
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
        r=run('projection',actor=2002,pay=int(p3))
        edits=[e for e in r['events'] if e[0]=='edit']
        assert len(edits)==1 and edits[0][4]==230 and 'fixture-bank' in edits[0][1] and 'new-bank' not in edits[0][1],r
        assert sql(f"SELECT purpose FROM payment_message_refs WHERE pay_row_id={p3}")=='invoice'
        assert sql(f"SELECT checkout_url,quote_amount,wallet_invoice_sha256 FROM payment_attempts WHERE pay_row_id={p3}")==original
        sql(f"UPDATE pays SET state='expired' WHERE id={p3}")
        r=run('projection',actor=2002,pay=int(p3))
        edits=[e for e in r['events'] if e[0]=='edit']
        assert len(edits)==1 and 'fixture-bank' in edits[0][1] and 'سوابق' in edits[0][1] and 'new-bank' not in edits[0][1],r
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

    result={'complete':True,'status':'PASS','cases':len(cases),'scenarios':cases,'production_mutations':0,'external_requests':0}
    if args.report:args.report.write_text(json.dumps(result))
    print(json.dumps(result))
if __name__=='__main__':main()
