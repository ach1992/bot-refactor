#!/usr/bin/env python3
"""Source-real unified receipt conversation against a fresh disposable schema."""
import argparse
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
        r=run('topup')
        assert ['topup','created'] in r['events'],r
        pid,h=sql("SELECT id,hash_id FROM pays WHERE user_id=2001").split('\t')
        assert 'pm_b_'+h in callbacks(r) and 'dc_'+h in callbacks(r),r
        for spec in ({'actor':2002},{'chat':-10001,'chat_type':'group'}):
            r=run(callback='pm_b_'+h,**spec)
            assert 'rs_'+h not in callbacks(r),r
            assert sql('SELECT COUNT(*) FROM payment_attempts')=='0'
        r=run(callback='pm_b_'+h,message_id=101,sent_id=201)
        assert 'rs_'+h in callbacks(r) and 'ph_'+h in callbacks(r),r
        assert ['delete',101,2001] in r['events'],r
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
        assert sql(f"SELECT COUNT(*) FROM payment_message_refs WHERE pay_row_id={pid2} AND message_id=211")=='0'
        assert sql(f"SELECT state FROM pays WHERE id={pid2}")=='pending'
        assert sql(f"SELECT COUNT(*) FROM payment_attempts WHERE pay_row_id={pid2}")=='0'
        cases.append('draft_cancel_retires_selector_only')
    result={'complete':True,'status':'PASS','cases':len(cases),'scenarios':cases,'production_mutations':0,'external_requests':0}
    if args.report:args.report.write_text(json.dumps(result))
    print(json.dumps(result))
if __name__=='__main__':main()
