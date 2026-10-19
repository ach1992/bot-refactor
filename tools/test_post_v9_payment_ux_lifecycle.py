#!/usr/bin/env python3
"""Issue #51: source-real payment message lifecycle against owned MariaDB.

No real Telegram, providers, customer database, deployment or financial transfer.
"""
from __future__ import annotations

import argparse
import json
import subprocess
import time
from pathlib import Path

import test_access_state as owned
import test_v6_native as native
import test_v8_native as purchase

ROOT=Path(__file__).resolve().parents[1]
DB=purchase.SIDE[1]

DRIVER=r'''<?php
$_SERVER['SCRIPT_FILENAME']='owned-payment-ux.php';
error_reporting(E_ALL & ~E_DEPRECATED);
$spec=json_decode($argv[4],true,512,JSON_THROW_ON_ERROR);
$connection=new mysqli('localhost','root','',$argv[3],0,$argv[2]);
$connection->set_charset('utf8mb4');
$GLOBALS['events']=[];
function bot($method,$data=[]) {
    $GLOBALS['events'][]=['method'=>$method,'chat_id'=>$data['chat_id']??0,
        'message_id'=>$data['message_id']??0,'keyboard'=>$data['reply_markup']??'',
        'text'=>$data['text']??''];
    $fail=$GLOBALS['spec']['fail_edit']??false;
    if (($GLOBALS['spec']['fail_text_only']??false) &&
        $method==='editMessageText') $fail=true;
    return (object)['ok'=>!$fail];
}
function payTypeToDescription($type){return $type==='INCREASE_WALLET'?'شارژ کیف پول':'خرید اشتراک';}
require_once $argv[1].'/src/Finance/PaymentLifecycle.php';
$id=(int)($spec['id']??0);
$action=(string)$spec['action'];
$before=null;
if ($action==='expire') {
    $before=achbotPayUxExpireDraft($connection,$id,$spec['clock']??null);
} elseif ($action==='selectable') {
    $connection->begin_transaction();
    $stmt=$connection->prepare('SELECT * FROM pays WHERE id=? FOR UPDATE');
    $stmt->bind_param('i',$id);$stmt->execute();
    $pay=$stmt->get_result()->fetch_assoc();$stmt->close();
    $before=$pay && achbotPayUxUnclaimedDraftUsable($connection,$pay);
    $connection->rollback();
} elseif ($action==='track') {
    $msgId=(int)($spec['message_id']??55);
    $reply=(object)['ok'=>true,'result'=>(object)['message_id'=>$msgId]];
    $before=achbotPayUxTrackMessage($connection,$id,(int)($spec['chat_id']??1001),
                                     $reply,(string)($spec['kind']??'text'));
} elseif ($action==='clicked') {
    achbotPayUxTrackClicked($connection,$id,(int)$spec['chat_id'],
                            (int)$spec['message_id']);
} elseif ($action==='sync') {
    $before=achbotPayUxSyncMessages($connection,$id);
} elseif ($action==='maintain') {
    $before=achbotPayUxMaintain($connection,(int)($spec['limit']??45));
} elseif ($action==='state') {
    $stmt=$connection->prepare('SELECT * FROM pays WHERE id=?');
    $stmt->bind_param('i',$id);$stmt->execute();
    $pay=$stmt->get_result()->fetch_assoc();$stmt->close();
    $attempt=null;
    if (achbotPayUxTable($connection,'payment_attempts')) {
        $stmt=$connection->prepare('SELECT * FROM payment_attempts WHERE pay_row_id=?');
        $stmt->bind_param('i',$id);$stmt->execute();
        $attempt=$stmt->get_result()->fetch_assoc()?:null;$stmt->close();
    }
    try { $pay['superseded']=achbotPayUxHasNewer($connection,$pay); }
    catch (Throwable $ignored) { $pay['superseded']=true; }
    $before=['state'=>achbotPayUxState($pay,$attempt),
             'body'=>achbotPayUxStatusText($pay,$attempt),
             'keyboard'=>achbotPayUxStatusKeyboard($pay['hash_id'])];
} else throw new RuntimeException('unknown owned test action');
echo json_encode(['result'=>$before,'events'=>$GLOBALS['events']],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
'''

def sql(w,e,q):
    return native.sql(w,e,q,DB)

def cmd(w,e,root,s):
    src=w/'owned-payment-ux.php'
    if not src.exists():src.write_text(DRIVER)
    return ['php','-n','-d','extension=mysqlnd','-d','extension=mysqli',
            '-d','allow_url_fopen=0',
            '-d','disable_functions=exec,shell_exec,system,passthru,popen,proc_open,mail',
            str(src),str(root),str(w/'mysql.sock'),DB,json.dumps(s,ensure_ascii=False)]

def run(w,e,s):
    p=subprocess.run(cmd(w,e,ROOT,s),cwd=w,env=e,capture_output=True,timeout=20)
    expected=b'achbot_payment_telegram_edit_deferred pay_id='
    unexpected=[line for line in p.stderr.splitlines()
                if not (line.startswith(expected) and
                        line[len(expected):].isdigit())]
    if p.returncode or unexpected:
        raise RuntimeError('PHP native failure: '+repr((p.returncode,p.stdout[-400:],unexpected[-8:])))
    return json.loads(p.stdout)

def add(w,e,h,who=1001,kind='BUY_SUB',old=1700000000,order='NULL'):
    sql(w,e,f"INSERT INTO pays "
        f"(hash_id,user_id,type,plan_id,server_id,volume,day,price,request_date,state,order_id) "
        f"VALUES ('{h}',{who},'{kind}',7,2,0,0,100,{old},'pending',{order})")
    return int(sql(w,e,f"SELECT id FROM pays WHERE hash_id='{h}'"))

def state(w,e,h):
    return sql(w,e,f"SELECT state FROM pays WHERE hash_id='{h}'").strip()

def count(w,e,table,where='1=1'):
    return int(sql(w,e,f'SELECT COUNT(*) FROM {table} WHERE {where}'))

def main():
    a=argparse.ArgumentParser()
    a.add_argument('--run-disposable',action='store_true',required=True)
    a.add_argument('--report',type=Path)
    args=a.parse_args()
    cases=[]
    with owned.owned_database() as (w,e):
        purchase.init(w,e,'http://127.0.0.1:9')
        assert len(sql(w,e,'SHOW TABLES').splitlines())==23
        missing=add(w,e,'missing-003')
        assert run(w,e,dict(action='expire',id=missing,clock=1800000000))['result'] is False
        assert state(w,e,'missing-003')=='pending'
        cases.append('missing_003_fails_closed_and_preserves_draft')
        sql(w,e,(ROOT/'migrations/post-v9/003_payment_attempts.sql').read_text())
        assert len(sql(w,e,'SHOW TABLES').splitlines())==25
        assert run(w,e,dict(action='track',id=missing,message_id=101))['result'] is False
        assert sql(w,e,f"SELECT message_id FROM pays WHERE id={missing}").strip() in ('NULL','')
        cases.append('uninstalled_006_cannot_overwrite_manual_receipt_id')
        sql(w,e,(ROOT/'migrations/post-v9/006_payment_message_refs.sql').read_text())
        assert len(sql(w,e,'SHOW TABLES').splitlines())==26
        older=add(w,e,'original-draft')
        newer=add(w,e,'replacement-draft',old=1700000020)
        assert run(w,e,dict(action='expire',id=older,clock=1700000040))['result'] is True
        assert state(w,e,'original-draft')=='expired'
        assert state(w,e,'replacement-draft')=='pending'
        assert run(w,e,dict(action='expire',id=older,clock=1800000000))['result'] is False
        cases.append('committed_newer_draft_expires_old_without_deleting_pays')
        far=add(w,e,'isolated-customer',who=9002,old=1700000060)
        assert run(w,e,dict(action='expire',id=far,clock=1700000070))['result'] is False
        assert state(w,e,'isolated-customer')=='pending'
        cases.append('another_customer_draft_does_not_collide')
        overdue=add(w,e,'overdue-alone',kind='INCREASE_WALLET',old=1700000100)
        assert run(w,e,dict(action='expire',id=overdue,clock=1700001900))['result'] is True
        cases.append('exact_thirty_minute_unclaimed_deadline')
        claim=add(w,e,'provider-invoice',kind='INCREASE_WALLET')
        sql(w,e,f"UPDATE pays SET payid='synthetic-invoice' WHERE id={claim}")
        assert run(w,e,dict(action='expire',id=claim,clock=1800000000))['result'] is False
        assert state(w,e,'provider-invoice')=='pending'
        cases.append('provider_payid_never_cancelled')
        uncertain=add(w,e,'remote-uncertain',kind='INCREASE_WALLET')
        sql(w,e,f"INSERT INTO payment_attempts "
            f"(pay_row_id,provider,order_hash,quote_amount,quote_currency,stage) "
            f"VALUES ({uncertain},'nowpayments','remote-uncertain',100,'toman','review')")
        assert run(w,e,dict(action='expire',id=uncertain,clock=1800000000))['result'] is False
        cases.append('provider_ledger_review_never_expired_or_deleted')
        manual=add(w,e,'manual-review',kind='INCREASE_WALLET')
        sql(w,e,f"UPDATE pays SET state='have_sent',message_id=666,chat_id='1001' WHERE id={manual}")
        assert run(w,e,dict(action='expire',id=manual,clock=1800000000))['result'] is False
        text=run(w,e,dict(action='state',id=manual))['result']
        assert text['state']=='receipt_review' and 'رسید' in text['body']
        cases.append('manual_receipt_not_expired')
        now=int(time.time())
        fresh=add(w,e,'selectable-fresh',who=9021,kind='INCREASE_WALLET',old=now)
        assert run(w,e,dict(action='selectable',id=fresh))['result'] is True
        newerFresh=add(w,e,'selectable-newer',who=9021,kind='INCREASE_WALLET',
                       old=now+1)
        assert run(w,e,dict(action='selectable',id=fresh))['result'] is False
        assert run(w,e,dict(action='selectable',id=newerFresh))['result'] is True
        cases.append('fresh_and_superseded_payment_method_guard')
        day=add(w,e,'day-quote-before',who=9024,kind='INCREASE_DAY_planA',
                old=now,order='41')
        dayNew=add(w,e,'day-quote-after',who=9024,kind='INCREASE_DAY_planB',
                   old=now+1,order='41')
        assert run(w,e,dict(action='selectable',id=day))['result'] is False
        assert run(w,e,dict(action='state',id=day))['result']['state']=='superseded'
        assert run(w,e,dict(action='expire',id=day,clock=now+2))['result'] is True
        cases.append('different_extension_variants_same_account_supersede')
        other=add(w,e,'other-account-before',who=9025,
                  kind='INCREASE_DAY_planA',old=now,order='41')
        another=add(w,e,'other-account-after',who=9025,
                    kind='INCREASE_DAY_planB',old=now+1,order='42')
        assert run(w,e,dict(action='selectable',id=other))['result'] is True
        assert run(w,e,dict(action='expire',id=other,clock=now+2))['result'] is False
        cases.append('different_extension_accounts_do_not_supersede')
        claimedBefore=add(w,e,'claimed-before-newer',who=9026,
                          kind='INCREASE_WALLET',old=now)
        sql(w,e,f"UPDATE pays SET payid='synthetic-issued-claim' "
            f"WHERE id={claimedBefore}")
        claimedLater=add(w,e,'claimed-newer-quote',who=9026,
                         kind='INCREASE_WALLET',old=now+1)
        protected=run(w,e,dict(action='state',id=claimedBefore))['result']
        assert protected['state']=='needs_review' and 'دوباره پرداخت نکنید' in protected['body']
        assert run(w,e,dict(action='expire',id=claimedBefore,clock=now+1801))['result'] is False
        cases.append('newer_provider_claim_held_for_review_not_cancelled')
        stale=add(w,e,'selectable-stale',who=9022,kind='INCREASE_WALLET',
                  old=now-1801)
        assert run(w,e,dict(action='selectable',id=stale))['result'] is False
        assert run(w,e,dict(action='expire',id=stale,clock=now))['result'] is True
        cases.append('aged_unclaimed_payment_method_blocked')
        zero=add(w,e,'bad-legacy-zero-date',who=9023,kind='INCREASE_WALLET',old=0)
        assert run(w,e,dict(action='selectable',id=zero))['result'] is False
        assert run(w,e,dict(action='expire',id=zero,clock=now))['result'] is True
        cases.append('zero_timestamp_unclaimed_quote_can_be_expired_safely')
        msg=add(w,e,'multiple-msg',kind='INCREASE_WALLET',old=1700000100)
        for mid in (71,72,73):
            assert run(w,e,dict(action='track',id=msg,message_id=mid))['result'] is True
        assert run(w,e,dict(action='track',id=msg,message_id=81,chat_id=9002))['result'] is False
        assert count(w,e,'payment_message_refs',f'pay_row_id={msg}')==3
        sql(w,e,f"UPDATE pays SET state='paid' WHERE id={msg}")
        fail=run(w,e,dict(action='sync',id=msg,fail_edit=True))
        assert fail['result']==0
        assert count(w,e,'payment_message_refs',
                     f'pay_row_id={msg} AND synced_digest IS NOT NULL')==0
        markup=run(w,e,dict(action='sync',id=msg,fail_text_only=True))
        assert markup['result']==0
        assert len([x for x in markup['events'] if x['method']=='editMessageReplyMarkup'])==3
        assert count(w,e,'payment_message_refs',
                     f'pay_row_id={msg} AND synced_digest IS NOT NULL')==0
        cases.append('markup_only_removes_links_but_does_not_claim_text_synced')
        good=run(w,e,dict(action='sync',id=msg))
        edits=[x for x in good['events'] if x['method']=='editMessageText']
        assert len(edits)==3 and good['result']==3,(good,edits)
        assert all('پرداخت فاکتور' not in x['keyboard'] and 'پرداخت فاکتور' not in x['text'] for x in edits)
        assert count(w,e,'payment_message_refs',
                     f'pay_row_id={msg} AND synced_digest IS NOT NULL')==3
        assert run(w,e,dict(action='sync',id=msg))['result']==0
        assert sql(w,e,f"SELECT message_id FROM pays WHERE id={manual}").strip()=='666'
        cases.append('paid_three_old_keyboards_removed_retry_after_edit_failure')
        overflow=add(w,e,'message-overflow',who=9030,kind='INCREASE_WALLET')
        for mid in range(1100,1112):
            assert run(w,e,dict(action='track',id=overflow,chat_id=9030,
                                 message_id=mid))['result'] is True
        sql(w,e,f"UPDATE pays SET state='paid' WHERE id={overflow}")
        first=run(w,e,dict(action='sync',id=overflow,fail_text_only=True))
        assert first['result']==0
        assert len([x for x in first['events']
                    if x['method']=='editMessageReplyMarkup'])==10
        assert count(w,e,'payment_message_refs',
                     f'pay_row_id={overflow} AND synced_digest IS NOT NULL')==0
        # The first ten were attempted but not truthfully synchronized.
        # Microsecond last-attempt rotation MUST serve the other two next.
        second=run(w,e,dict(action='sync',id=overflow))
        updated=[x['message_id'] for x in second['events']
                 if x['method']=='editMessageText']
        assert updated[:2]==[1110,1111] and len(updated)==10,(second,updated)
        assert second['result']==10
        third=run(w,e,dict(action='sync',id=overflow))
        assert third['result']==2
        assert count(w,e,'payment_message_refs',
                     f'pay_row_id={overflow} AND synced_digest IS NOT NULL')==12
        cases.append('over_ten_messages_markup_failure_rotates_without_starvation')
        for mid in (90,91):
            run(w,e,dict(action='track',id=uncertain,message_id=mid))
        protected=run(w,e,dict(action='sync',id=uncertain))
        assert protected['result']==2
        assert not any('پرداخت فاکتور' in x.get('keyboard','') for x in protected['events'])
        assert state(w,e,'remote-uncertain')=='pending'
        cases.append('provider_review_removes_buttons_without_fake_settlement')
        media=add(w,e,'media-payment-msg',kind='INCREASE_WALLET')
        assert run(w,e,dict(action='track',id=media,message_id=94,kind='media'))['result'] is True
        sql(w,e,f"UPDATE pays SET state='have_sent' WHERE id={media}")
        caption=run(w,e,dict(action='sync',id=media))
        assert caption['result']==1 and any(x['method']=='editMessageCaption'
                  for x in caption['events']),caption
        assert count(w,e,'payment_message_refs',
                     f'pay_row_id={media} AND synced_digest IS NOT NULL')==1
        cases.append('media_caption_and_buttons_updated_after_receipt_review')
        run(w,e,dict(action='clicked',id=msg,chat_id=1001,message_id=200))
        assert count(w,e,'payment_message_refs',f'pay_row_id={msg}')==4
        run(w,e,dict(action='clicked',id=msg,chat_id=9002,message_id=201))
        assert count(w,e,'payment_message_refs',f'pay_row_id={msg}')==4
        cases.append('owner_qualified_clicked_message_tracked')
        old=add(w,e,'race-expire',kind='INCREASE_WALLET')
        s={'action':'expire','id':old,'clock':1800000000}
        ps=[subprocess.Popen(cmd(w,e,ROOT,s),env=e,cwd=w,stdout=subprocess.PIPE,
                             stderr=subprocess.PIPE) for _ in range(2)]
        results=[]
        for p in ps:
            o,err=p.communicate(timeout=25)
            assert p.returncode==0 and not err,(p.returncode,err)
            results.append(json.loads(o)['result'])
        assert results.count(True)==1 and results.count(False)==1,results
        cases.append('two_concurrent_expiry_workers_exactly_one_mutation')
        assert count(w,e,'pays',f"id={old}") == 1
        assert count(w,e,'payment_message_refs',f"pay_row_id={msg}") == 4
        # Incomplete provider ledger cannot prove a draft never had an invoice.
        sql(w,e,'DROP TABLE payment_transactions')
        incomplete=add(w,e,'partial-ledger-do-not-expire',kind='INCREASE_WALLET')
        assert run(w,e,dict(action='expire',id=incomplete,clock=1800000000))['result'] is False
        assert state(w,e,'partial-ledger-do-not-expire')=='pending'
        cases.append('incomplete_003_schema_blocks_expiry')
    result={'complete':True,'cases':len(cases),'scenarios':cases,
            'source_real_php':True,'original_schema_unchanged':True,
            'production_mutations':0,'external_provider_requests':0}
    if args.report:args.report.write_text(json.dumps(result,indent=2,ensure_ascii=False)+'\n')
    print(json.dumps(result,ensure_ascii=False))

if __name__=='__main__':main()
