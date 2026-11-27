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

import database_fixture as owned
import database_fixture as native
import database_fixture as purchase

ROOT=Path(__file__).resolve().parents[1]
DB=purchase.SIDE[1]

DRIVER=r'''<?php
$_SERVER['SCRIPT_FILENAME']='owned-payment-ux.php';
error_reporting(E_ALL & ~E_DEPRECATED);
$spec=json_decode($argv[4],true,512,JSON_THROW_ON_ERROR);
$botState=$spec['states']??[];
$telegramWebhookSecret='owned_receipt_projection_secret_2026';
$botUrl='https://bot.fixture.invalid/';
$connection=new mysqli('localhost','root','',$argv[3],0,$argv[2]);
$connection->set_charset('utf8mb4');
$GLOBALS['events']=[];
function bot($method,$data=[]) {
    $GLOBALS['events'][]=['method'=>$method,'chat_id'=>$data['chat_id']??0,
        'message_id'=>$data['message_id']??0,'keyboard'=>$data['reply_markup']??'',
        'text'=>$data['text']??''];
    $fail=$GLOBALS['spec']['fail_edit']??false;
    if (($GLOBALS['spec']['telegram_message_missing']??false) &&
        in_array($method,['editMessageText','editMessageCaption','editMessageReplyMarkup'],true))
        return (object)['ok'=>false,'error_code'=>400,
            'description'=>'Bad Request: message to edit not found'];
    if (($GLOBALS['spec']['transport_failure']??false) &&
        $method==='editMessageText') return null;
    if (($GLOBALS['spec']['telegram_noop']??false) &&
        $method==='editMessageText')
        return (object)['ok'=>false,'error_code'=>400,
            'description'=>'Bad Request: message is not modified'];
    if (($GLOBALS['spec']['fail_text_only']??false) &&
        $method==='editMessageText') return (object)['ok'=>false,'error_code'=>400,'description'=>'Bad Request: cannot parse entities'];
    return $fail?(object)['ok'=>false]:(object)['ok'=>true,'result'=>(object)['message_id'=>(int)$data['message_id'],'chat'=>(object)['id'=>(int)$data['chat_id']]]];
}
function sendMessage($text,$keys=null,$mode='HTML',$chat=null) {
    $GLOBALS['events'][]=['method'=>'sendMessage','text'=>$text,'keyboard'=>$keys,'mode'=>$mode];
    return (object)['ok'=>true,'result'=>(object)['message_id'=>77]];
}
function payTypeToDescription($type){return $type==='INCREASE_WALLET'?'شارژ کیف پول':'خرید اشتراک';}
require_once $argv[1].'/src/Finance/ReceiptHelpers.php';
require_once $argv[1].'/src/Finance/PaymentLifecycle.php';
$id=(int)($spec['id']??0);
$action=(string)$spec['action'];
$before=null;
if ($action==='expire') {
    $before=achbotPayUxExpireDraft($connection,$id,$spec['clock']??null);
} elseif ($action==='render_checkout') {
    require_once $argv[1].'/src/Purchase/CheckoutPresentation.php';
    achbotRenderCheckoutDraft($connection,$spec['view'],$botUrl,false);
    $before=true;
} elseif ($action==='selectable') {
    $connection->begin_transaction();
    $stmt=$connection->prepare('SELECT * FROM pays WHERE id=? FOR UPDATE');
    $stmt->bind_param('i',$id);$stmt->execute();
    $pay=$stmt->get_result()->fetch_assoc();$stmt->close();
    $before=$pay && achbotPayUxUnclaimedDraftUsable($connection,$pay);
    $connection->rollback();
} elseif ($action==='invoice_expire') {
    $before=achbotPayUxExpireInvoice($connection,$id,$spec['clock']??null);
} elseif ($action==='pin') {
    require_once $argv[1].'/src/Finance/PaymentCredit.php';
    $connection->begin_transaction();
    if (!empty($spec['api'])) {
        [$pay,$attempt]=achbotCreditInvoiceLocked($connection,$id,(int)$spec['actor']);
        $keys=[
            'smsApiUrl'=>'https://fixture.invalid/wp-json/ach-pay-manager/v1',
            'smsApiToken'=>achbotPaymentEvidenceProtect('setting:smsApiToken',['value'=>'owned-sms-token']),
            'giftApiUrl'=>'https://fixture.invalid/wp-json/ach-gcw/v2',
            'giftApiKeyId'=>'owned-key','giftApiAmountUnit'=>'toman',
            'giftApiSecret'=>achbotPaymentEvidenceProtect('setting:giftApiSecret',['value'=>'owned-gift-secret'])
        ];
        $profile=achbotPaymentEvidenceProfile($attempt['provider'],$keys,
            ['smsApiVerifyState'=>'on','giftApiVerifyState'=>'on']);
        if (!$profile || !achbotPaymentEvidencePinLocked($connection,$pay,$attempt,$profile))
            throw new RuntimeException('owned_profile_pin_failed');
    }
    achbotCreditPinIssuedLocked($connection,$id,(int)$spec['actor']);
    $before=$connection->commit();
} elseif ($action==='manual_start') {
    $before=achbotPayUxStartManualReceipt($connection,$id,
        (string)($spec['provider']??'bank_transfer'),(int)($spec['actor']??1001));
} elseif ($action==='hosted_attention') {
    $before=achbotPayUxHostedAttentionElapsed($connection,$id);
} elseif ($action==='audience') {
    $before=achbotPayUxAudienceAllowed($connection,
        (string)($spec['provider']??'bank_transfer'),(int)($spec['actor']??1001));
} elseif ($action==='manual_allowed') {
    $stmt=$connection->prepare('SELECT * FROM pays WHERE id=? LIMIT 1');
    $stmt->bind_param('i',$id);$stmt->execute();
    $pay=$stmt->get_result()->fetch_assoc();$stmt->close();
    $before=achbotManualReceiptAllowed($connection,$pay,
        (string)($spec['provider']??'bank_transfer'),(int)($spec['actor']??1001));
} elseif ($action==='track') {
    $msgId=(int)($spec['message_id']??55);
    $reply=(object)['ok'=>true,'result'=>(object)['message_id'=>$msgId,'chat'=>(object)['id'=>(int)($spec['response_chat_id']??$spec['chat_id']??1001)]]];
    $before=achbotPayUxTrackMessage($connection,$id,(int)($spec['chat_id']??1001),
                                     $reply,(string)($spec['purpose']??'status'),(string)($spec['kind']??'text'));
 } elseif ($action==='ref_lookup') {
    $before=achbotPayUxRef($connection,$id,(int)$spec['chat_id'],(int)$spec['message_id'])!==null;
} elseif ($action==='sync') {
    $before=achbotPayUxSyncMessages($connection,$id);
} elseif ($action==='maintain') {
    $before=achbotPayUxMaintain($connection,(int)($spec['limit']??45));
} elseif ($action==='failure_category') {
    $reply=isset($spec['reply'])?(object)$spec['reply']:null;
    $before=achbotPayUxEditFailureCategory($reply);
} elseif ($action==='manual_projection') {
    require_once $argv[1].'/src/Finance/CheckoutAdmission.php';
    $pay=achbotProviderRow($connection,'SELECT * FROM pays WHERE id=?','i',[$id]);
    $attempt=achbotProviderRow($connection,'SELECT * FROM payment_attempts WHERE pay_row_id=?','i',[$id]);
    $method=$attempt['provider'];$hash=$pay['hash_id'];
    $code='AG2-FIXTURE-'.str_repeat('A',84);
    $photo=(object)['message'=>(object)['photo'=>[(object)['file_id'=>'owned-original-photo']]]];
    $text=(object)['message'=>(object)['text'=>$code]];
    $before=[
        'initial'=>json_decode(achbotManualReceiptKeyboard($pay,$attempt),true),
        'status'=>json_decode(achbotPayUxStatusKeyboard($hash,$pay,$attempt),true),
        'cancel'=>json_decode(kbOnlyCancel($pay),true),
        'prompt'=>achbotManualEvidencePrompt($connection,$pay,$method),
        'photo'=>achbotManualEvidenceInput($photo,$connection,$pay,$method),
        'code'=>achbotManualEvidenceInput($text,$connection,$pay,$method),
        'owner'=>achbotManualReceiptAllowed($connection,$pay,$method,(int)$pay['user_id'],$spec['mode']),
        'foreign'=>achbotManualReceiptAllowed($connection,$pay,$method,9002,$spec['mode']),
        'wrong_family'=>achbotManualReceiptAllowed($connection,$pay,$method,(int)$pay['user_id'],
            $spec['mode']==='topup'?'standard':'topup'),
        'other_hash'=>json_decode(achbotPayUxStatusKeyboard('unrelated',$pay,$attempt),true),
        'changed_original'=>achbotManualReceiptAction(array_replace($pay,['user_id'=>9002]),$attempt),
        'broken_pin'=>achbotManualReceiptAction($pay,array_replace($attempt,['wallet_invoice_sha256'=>str_repeat('f',64)])),
    ];
} elseif ($action==='state') {
    $stmt=$connection->prepare('SELECT * FROM pays WHERE id=?');
    $stmt->bind_param('i',$id);$stmt->execute();
    $pay=$stmt->get_result()->fetch_assoc();$stmt->close();
    $attempt=null;
    if (achbotPayUxTable($connection,'payment_attempts')) {
        $stmt=$connection->prepare('SELECT *,
        TIMESTAMPDIFF(SECOND,created_at,NOW()) AS age_seconds
        FROM payment_attempts WHERE pay_row_id=?');
        $stmt->bind_param('i',$id);$stmt->execute();
        $attempt=$stmt->get_result()->fetch_assoc()?:null;$stmt->close();
    }
    try { $pay['superseded']=achbotPayUxHasNewer($connection,$pay); }
    catch (Throwable $ignored) { $pay['superseded']=true; }
    $before=['state'=>achbotPayUxState($pay,$attempt),
             'body'=>achbotPayUxStatusText($pay,$attempt),
             'keyboard'=>achbotPayUxStatusKeyboard($pay['hash_id'],$pay,$attempt)];
} else throw new RuntimeException('unknown owned test action');
echo json_encode(['result'=>$before,'events'=>$GLOBALS['events']],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
'''

def sql(w,e,q):
    return native.sql(w,e,q,DB)

def cmd(w,e,root,s,database=DB):
    src=w/'owned-payment-ux.php'
    if not src.exists():src.write_text(DRIVER)
    return ['php','-n','-d','extension=mysqlnd','-d','extension=mysqli','-d','extension=mbstring',
            '-d','allow_url_fopen=0',
            '-d','disable_functions=exec,shell_exec,system,passthru,popen,proc_open,mail',
            str(src),str(root),str(w/'mysql.sock'),database,json.dumps(s,ensure_ascii=False)]

def run(w,e,s,database=DB):
    expected_diagnostic=s.pop('expected_edit_diagnostic',None)
    expected_click_denied=s.pop('expected_click_denied',False)
    p=subprocess.run(cmd(w,e,ROOT,s,database),cwd=w,env=e,capture_output=True,timeout=20)
    import re
    expected=re.compile(rb'achbot_payment_telegram_edit_deferred pay_id=[0-9]+ '
                        rb'ref_id=[0-9]+ kind=(text|media) '
                        rb'category=(transport_unavailable|message_missing|message_not_editable|'
                        rb'access_denied|rate_limited|telegram_server_error|telegram_rejected) '
                        rb'tg_code=[0-9]{1,3} markup_only=[01]')
    unexpected=[line for line in p.stderr.splitlines() if not expected.fullmatch(line)]
    if expected_click_denied:
        denied=f"achbot_manual_receipt_click_deferred pay_id={s['id']}".encode()
        assert denied in unexpected, (denied,unexpected)
        unexpected.remove(denied)
    if expected_diagnostic is not None and expected_diagnostic.encode() not in p.stderr:
        raise RuntimeError('Missing expected categorized Telegram edit diagnostic: '+str(expected_diagnostic))
    if p.returncode or unexpected:
        raise RuntimeError('PHP native failure: '+repr((p.returncode,p.stdout[-400:],unexpected[-8:])))
    return json.loads(p.stdout)

def add(w,e,h,who=1001,kind='BUY_SUB',old=1700000000,order='NULL',sealed=True):
    sql(w,e,f"INSERT INTO pays "
        f"(hash_id,user_id,type,plan_id,server_id,volume,day,price,request_date,state,order_id) "
        f"VALUES ('{h}',{who},'{kind}',7,2,0,0,100,{old},'pending',{order})")
    if sealed and old>0:
        sql(w,e,f"UPDATE pays SET draft_expires_at={old+1800} WHERE hash_id='{h}'")
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
        missing=add(w,e,'missing-003',sealed=False)
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
        # A partial 003 ledger must not permit historical draft expiry.
        sql(w,e,'DROP TABLE payment_transactions')
        assert run(w,e,dict(action='expire',id=missing,clock=1800000000))['result'] is False
        assert state(w,e,'missing-003')=='pending'
        cases.append('incomplete_003_schema_blocks_expiry')
    # All positive lifecycle cases now use the canonical installation,
    # not a partial historical schema with fabricated invoice hashes.
    with owned.owned_database(ROOT/'database/current-schema.sql') as (w,e):
        # The real checkout producer escapes literal stored data, preserves
        # valid trusted instance HTML, and never re-expands embedded tokens.
        for checkout_mode,agents in [('standard',0),('custom',0),('standard',2)]:
            view=dict(pay=dict(hash_id='literal-checkout',user_id=1001,price=100,id=0,agent_bought=int(bool(agents)),agent_count=agents),
                      quote=dict(server_id=2,quantity=2,days=30,volume=3),plan=dict(title='<b>PRICE &</b>',descr='سلام <tag> DESCRIPTION &'),
                      category=dict(title='🔥 PLAN-NAME'),mode=checkout_mode,payment_methods=['wallet'],context=dict(category_id=1))
            note='<a href="https://t.me/PRICE_support?x=PLAN-NAME&amp;y=DESCRIPTION">SUPPORTID</a> <b>PRICE VOLUME DAYS ACCOUNT-COUNT TOTAL-PRICE</b> userid'
            result=run(w,e,dict(action='render_checkout',view=view,
                states=dict(supportID='@PRICE_support',instanceMessages=dict(purchase_note=dict(format='html',text=note)))))
            rendered=result['events'][0]
            assert rendered['mode']=='HTML'
            assert '🔥 PLAN-NAME &lt;b&gt;PRICE &amp;&lt;/b&gt;' in rendered['text'],rendered
            assert 'سلام &lt;tag&gt; DESCRIPTION &amp;' in rendered['text'],rendered
            assert rendered['text'].endswith('\n\n<a href="https://t.me/PRICE_support?x=PLAN-NAME&amp;y=DESCRIPTION">@PRICE_support</a> <b>PRICE VOLUME DAYS ACCOUNT-COUNT TOTAL-PRICE</b> 1001'),rendered
        cases.append('actual_checkout_literal_data_one_pass_trusted_instance_html')
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
        # Old contradictory drafts must not occupy every limited sweep slot.
        contradictory=add(w,e,'contradictory-pending-receipt',who=9530,old=100)
        sql(w,e,f"UPDATE pays SET message_id=667,chat_id='9530' WHERE id={contradictory}")
        eligible=add(w,e,'eligible-behind-contradictory',who=9531,old=110)
        run(w,e,dict(action='maintain',limit=1))
        assert state(w,e,'contradictory-pending-receipt')=='pending'
        assert state(w,e,'eligible-behind-contradictory')=='expired'
        cases.append('bounded_expiry_skips_original_evidence_without_starvation')

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
        assert protected['state']=='needs_review' and 'پرداخت را تکرار نکنید' in protected['body']
        assert run(w,e,dict(action='expire',id=claimedBefore,clock=now+1801))['result'] is False
        cases.append('newer_provider_claim_held_for_review_not_cancelled')
        stale=add(w,e,'selectable-stale',who=9022,kind='INCREASE_WALLET',
                  old=now-1801)
        assert run(w,e,dict(action='selectable',id=stale))['result'] is False
        assert run(w,e,dict(action='expire',id=stale,clock=now))['result'] is True
        cases.append('aged_unclaimed_payment_method_blocked')
        zero=add(w,e,'bad-legacy-zero-date',who=9023,kind='INCREASE_WALLET',old=0)
        assert run(w,e,dict(action='selectable',id=zero))['result'] is False
        assert run(w,e,dict(action='expire',id=zero,clock=now))['result'] is False
        cases.append('unsealed_historical_quote_not_reissued_or_expired')
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
        assert count(w,e,'payment_message_refs',
                     f'pay_row_id={msg} AND updated_at>NOW(6)')==3
        no_retry=run(w,e,dict(action='sync',id=msg))
        assert no_retry['result']==0 and not no_retry['events'],no_retry
        sql(w,e,f"UPDATE payment_message_refs SET updated_at=NOW(6)-INTERVAL 1 SECOND WHERE pay_row_id={msg}")
        markup=run(w,e,dict(action='sync',id=msg,fail_text_only=True))
        assert markup['result']==0
        assert len([x for x in markup['events'] if x['method']=='editMessageReplyMarkup'])==3
        assert count(w,e,'payment_message_refs',
                     f'pay_row_id={msg} AND synced_digest IS NOT NULL')==0
        cases.append('markup_only_removes_links_but_does_not_claim_text_synced')
        assert count(w,e,'payment_message_refs',
                     f'pay_row_id={msg} AND updated_at>NOW(6)')==3
        sql(w,e,f"UPDATE payment_message_refs SET updated_at=NOW(6)-INTERVAL 1 SECOND WHERE pay_row_id={msg}")
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
        first=run(w,e,dict(action='sync',id=overflow,fail_text_only=True,
            expected_edit_diagnostic='category=telegram_rejected tg_code=400 markup_only=1'))
        assert first['result']==0
        assert len([x for x in first['events']
                    if x['method']=='editMessageReplyMarkup'])==10
        assert count(w,e,'payment_message_refs',
                     f'pay_row_id={overflow} AND synced_digest IS NOT NULL')==0
        # Failed ten receive cooldown, but the two untouched references
        # remain immediately eligible: no starvation and no repeated storm.
        second=run(w,e,dict(action='sync',id=overflow))
        updated=[x['message_id'] for x in second['events']
                 if x['method']=='editMessageText']
        assert updated==[1110,1111] and second['result']==2,(second,updated)
        immediate=run(w,e,dict(action='sync',id=overflow))
        assert immediate['result']==0 and not immediate['events'],immediate
        sql(w,e,f"UPDATE payment_message_refs SET updated_at=NOW(6)-INTERVAL 1 SECOND "
                f"WHERE pay_row_id={overflow} AND synced_digest IS NULL")
        third=run(w,e,dict(action='sync',id=overflow))
        assert third['result']==10
        assert count(w,e,'payment_message_refs',
                     f'pay_row_id={overflow} AND synced_digest IS NOT NULL')==12
        cases.append('over_ten_messages_markup_failure_rotates_without_starvation')
        transport=add(w,e,'transport-failed-edit',who=9031,kind='INCREASE_WALLET')
        assert run(w,e,dict(action='track',id=transport,chat_id=9031,
                            message_id=2001))['result'] is True
        sql(w,e,f"UPDATE pays SET state='paid' WHERE id={transport}")
        bad_transport=run(w,e,dict(action='sync',id=transport,transport_failure=True))
        assert bad_transport['result']==0
        assert [x['method'] for x in bad_transport['events']]==['editMessageText'],bad_transport
        assert count(w,e,'payment_message_refs',
                     f'pay_row_id={transport} AND updated_at>NOW(6)')==1
        sql(w,e,f"UPDATE payment_message_refs SET updated_at=NOW(6)-INTERVAL 1 SECOND WHERE pay_row_id={transport}")
        noop=run(w,e,dict(action='sync',id=transport,telegram_noop=True))
        assert noop['result']==1 and [x['method'] for x in noop['events']]==['editMessageText']
        assert count(w,e,'payment_message_refs',
                     f'pay_row_id={transport} AND synced_digest IS NOT NULL')==1
        for response,expected_class in [
            (None,'transport_unavailable'),
            ({'error_code':400,'description':'Bad Request: message to edit not found'},'message_missing'),
            ({'error_code':400,'description':"Bad Request: message can't be edited"},'message_not_editable'),
            ({'error_code':403},'access_denied'),
            ({'error_code':429},'rate_limited'),
            ({'error_code':503},'telegram_server_error'),
            ({'error_code':400,'description':'some other provider error'},'telegram_rejected'),
        ]:
            assert run(w,e,dict(action='failure_category',reply=response))['result']==expected_class
        cases.append('telegram_non_sensitive_failure_categories')
        missing_msg=add(w,e,'telegram-missing-old-message',who=9055,kind='INCREASE_WALLET')
        assert run(w,e,dict(action='track',id=missing_msg,chat_id=9055,
                            message_id=2055))['result'] is True
        sql(w,e,f"UPDATE pays SET state='paid' WHERE id={missing_msg}")
        lost=run(w,e,dict(action='sync',id=missing_msg,telegram_message_missing=True,
            expected_edit_diagnostic='category=message_missing tg_code=400 markup_only=0'))
        assert lost['result']==0
        assert [x['method'] for x in lost['events']]==['editMessageText']
        assert count(w,e,'payment_message_refs',f'pay_row_id={missing_msg} AND synced_digest IS NULL')==1
        assert count(w,e,'payment_message_refs',f"pay_row_id={missing_msg} AND retired_reason='message_missing' AND synced_digest IS NULL")==1
        sql(w,e,f"UPDATE payment_message_refs SET updated_at=NOW(6)-INTERVAL 1 DAY WHERE pay_row_id={missing_msg}")
        not_retried=run(w,e,dict(action='sync',id=missing_msg))
        assert not_retried['result']==0 and not not_retried['events']
        cases.append('missing_telegram_message_retired_without_false_ack_or_retry')
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
        assert not run(w,e,dict(action='ref_lookup',id=msg,chat_id=1001,message_id=200))['result']
        assert run(w,e,dict(action='track',id=msg,chat_id=1001,message_id=200))['result']
        assert run(w,e,dict(action='ref_lookup',id=msg,chat_id=1001,message_id=200))['result']
        assert not run(w,e,dict(action='ref_lookup',id=msg,chat_id=9002,message_id=200))['result']
        assert not run(w,e,dict(action='track',id=msg,chat_id=1001,message_id=201,response_chat_id=9002))['result']
        assert count(w,e,'payment_message_refs',f'pay_row_id={msg}')==4
        cases.append('exact_ack_and_owner_qualified_reference_only')
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
        # Source-real pin on the canonical schema. Backdate the initial
        # fixture INSERT, never mutate a sealed deadline/fingerprint.
        valid_intents={
            'bank_transfer':{'v':1,'method':'bank_transfer','account':'6037999999999999','holder':'Fixture'},
            'gift_card':{'v':1,'method':'gift_card','seller_url':'https://example.org/gift-card'},
            'manual_usdt':{'v':1,'method':'manual_usdt','network':'BEP20','wallet':'0x'+'1'*40},
            'manual_trx':{'v':1,'method':'manual_trx','network':'TRON','wallet':'TJRabPrwbZy45sbavfcjinPJC18kjpRTv8'},
        }
        for who in (9431,9432,9444,9455,9466,9467,9499):
            sql(w,e,f"INSERT INTO users(userid,name,username,refcode,date) VALUES ({who},'Fixture','fixture','0','1700000000')")
        def quote(key,provider,who,age=0,kind='INCREASE_WALLET',custom=False,api=False):
            pay_id=add(w,e,key,who=who,kind=kind,old=int(time.time()))
            if custom:
                sql(w,e,f"UPDATE pays SET day=30,volume=2 WHERE id={pay_id}")
            manual=provider in valid_intents
            intent=json.dumps(valid_intents[provider],separators=(',',':')) if manual else 'https://example.org/fixture'
            literal="'"+intent.replace("'","''")+"'"
            ref="NULL" if manual else f"'{key}'"
            if not manual:
                sql(w,e,f"UPDATE pays SET payid='{key}' WHERE id={pay_id}")
            sql(w,e,f"INSERT INTO payment_attempts "
                f"(pay_row_id,provider,order_hash,quote_amount,quote_currency,local_amount_toman,checkout_url,provider_reference,stage,wallet_invoice_sha256,created_at) "
                f"VALUES ({pay_id},'{provider}','{key}',100,'toman',100,{literal},{ref},'pending','',DATE_SUB(NOW(),INTERVAL {age} SECOND))")
            assert run(w,e,dict(action='pin',id=pay_id,actor=who,api=api))['result'] is True
            return pay_id
        def deadline(pid):
            return int(sql(w,e,f"SELECT invoice_expires_at FROM payment_attempts WHERE pay_row_id={pid}"))
        # Six real checkout-family contracts, independently enumerated. Both
        # initial and status projections must retain the original evidence
        # type and callback even when current API settings are the opposite.
        families=[
            ('standard','BUY_SUB',False),
            ('custom','BUY_SUB',True),
            ('renew','RENEW_ACCOUNT',False),
            ('day','INCREASE_DAY_1_1',False),
            ('volume','INCREASE_VOLUME_1_1',False),
            ('topup','INCREASE_WALLET',False),
        ]
        methods=[
            ('bank_transfer',False,'📤 ارسال رسید بانکی'),
            ('bank_transfer',True,'📤 ارسال رسید بانکی'),
            ('gift_card',False,'📤 ارسال گیفت کارت'),
            ('gift_card',True,'📤 ارسال کد گیفت کارت'),
            ('manual_usdt',False,'📤 ارسال رسید تتر'),
            ('manual_trx',False,'📤 ارسال رسید TRX'),
        ]
        for mode,kind,custom in families:
            for method,api,label in methods:
                h=f'ui-{mode}-{method}-{int(api)}'
                pid=quote(h,method,9431,age=21300,kind=kind,custom=custom,api=api)
                spec=dict(action='manual_projection',id=pid,mode=mode,
                          states=dict(giftApiVerifyState='off' if api else 'on',
                                      smsApiVerifyState='off' if api else 'on'))
                result=run(w,e,spec)['result']
                callback='rs_'+h
                cancel='ph_'+h
                expected=dict(text=label,callback_data=callback)
                initial=result['initial']['inline_keyboard']
                status=result['status']['inline_keyboard']
                assert initial[0][0]==status[0][0]==expected,result
                assert initial[-1][0]['callback_data']==cancel and result['cancel']['inline_keyboard'][0][0]['callback_data']=='rc_'+h,result
                if method=='gift_card':
                    assert initial[0][1]['url']=='https://example.org/gift-card'
                else:
                    assert len(initial[0])==1
                typed=method=='gift_card' and api
                assert result['photo']==(None if typed else 'owned-original-photo')
                assert result['code']==('AG2-FIXTURE-'+'A'*84 if typed else None)
                assert ('فقط کد بفرستید' if typed else 'تصویر') in result['prompt']
                assert result['owner'] is True and result['foreign'] is False and result['wrong_family'] is False
                assert result['changed_original'] is None and result['broken_pin'] is None
                assert all(not b.get('callback_data','').startswith('rs_') for row in result['other_hash']['inline_keyboard'] for b in row)
                # The issued deadline is not modified: start a real receipt
                # grace then simulate expiration through the current clock.
                assert run(w,e,dict(action='manual_start',id=pid,provider=method,actor=9431))['result'] is True
                issued=deadline(pid)
                started=int(sql(w,e,f"SELECT receipt_started_at FROM payment_attempts WHERE pay_row_id={pid}"))
                assert started<issued<started+900
                assert run(w,e,dict(action='invoice_expire',id=pid,clock=issued))['result'] is False
                assert run(w,e,dict(action='invoice_expire',id=pid,clock=started+899))['result'] is False
                assert run(w,e,dict(action='invoice_expire',id=pid,clock=started+900))['result'] is True
                late=run(w,e,spec)['result']
                assert late['initial']==late['status']
                assert late['status']['inline_keyboard'][0][0]==dict(text='🔎 بررسی پرداخت قبلی',callback_data=callback),late
                assert late['photo']==result['photo'] and late['code']==result['code'] and late['owner'] is True
                assert not any('url' in b for row in late['status']['inline_keyboard'] for b in row)
                cases.append(f'original_receipt_{mode}_{method}_{int(api)}_initial_status_and_late')
        for method in valid_intents:
            late=quote('idle-'+method,method,9431,age=21660)
            assert run(w,e,dict(action='track',id=late,chat_id=9431,
                                message_id=2000+len(cases)))['result'] is True
            assert run(w,e,dict(action='invoice_expire',id=late,clock=deadline(late)))['result'] is True
            changed=run(w,e,dict(action='sync',id=late))
            assert changed['result']==1,changed
            assert all('مهلت' in event['text'] and 'پرداخت فاکتور' not in event['keyboard']
                       and 'بررسی پرداخت قبلی' in event['keyboard']
                       for event in changed['events'] if event['method']=='editMessageText')
            assert state(w,e,'idle-'+method)=='expired'
            # Preserve the original pending evidence owner, not a retired
            # terminal attempt state. Late receipt admission is not reopening.
            assert sql(w,e,f"SELECT stage FROM payment_attempts WHERE pay_row_id={late}").strip()=='pending'
            assert run(w,e,dict(action='invoice_expire',id=late))['result'] is False
            assert run(w,e,dict(action='manual_start',id=late,provider=method,actor=9431))['result'] is True
            assert run(w,e,dict(action='manual_allowed',id=late,provider=method,actor=9431))['result'] is True
            assert state(w,e,'idle-'+method)=='expired'
            assert sql(w,e,f"SELECT receipt_started_at FROM payment_attempts WHERE pay_row_id={late}").strip()=='NULL'
            cases.append('expired_'+method+'_original_late_receipt_without_reopening')
            timely=quote('click-'+method,method,9432,age=21300)
            assert run(w,e,dict(action='manual_start',id=timely,provider=method,actor=9431,expected_click_denied=True))['result'] is False
            assert run(w,e,dict(action='manual_start',id=timely,provider=method,actor=9432))['result'] is True
            start=int(sql(w,e,f"SELECT receipt_started_at FROM payment_attempts WHERE pay_row_id={timely}"))
            original=deadline(timely)
            assert start<original<start+900
            assert run(w,e,dict(action='manual_start',id=timely,provider=method,actor=9432))['result'] is True
            assert int(sql(w,e,f"SELECT receipt_started_at FROM payment_attempts WHERE pay_row_id={timely}"))==start
            assert run(w,e,dict(action='manual_allowed',id=timely,provider=method,actor=9432))['result'] is True
            assert run(w,e,dict(action='invoice_expire',id=timely,clock=start+899))['result'] is False
            assert run(w,e,dict(action='state',id=timely))['result']['state']=='awaiting_receipt'
            assert run(w,e,dict(action='invoice_expire',id=timely,clock=start+900))['result'] is True
            cases.append('first_click_'+method+'_grace_is_pinned_and_nonextendable')
        manual_review=quote('manual-evidence-protection','bank_transfer',9444,age=25000)
        sql(w,e,f"UPDATE payment_attempts SET stage='receiving' WHERE pay_row_id={manual_review}")
        assert run(w,e,dict(action='invoice_expire',id=manual_review))['result'] is False
        assert state(w,e,'manual-evidence-protection')=='pending'
        cases.append('manual_evidence_lease_not_expired')
        custom=quote('custom-idle-window','bank_transfer',9455,age=7200)
        custom_deadline=deadline(custom)
        sql(w,e,"INSERT INTO setting(type,value) VALUES ('BOT_STATES',JSON_OBJECT('paymentManualHours',1,'paymentHostedHours',72))")
        assert run(w,e,dict(action='invoice_expire',id=custom))['result'] is False
        assert deadline(custom)==custom_deadline
        new=quote('new-custom-window','bank_transfer',9455,age=7200)
        assert run(w,e,dict(action='invoice_expire',id=new))['result'] is True
        cases.append('persisted_policy_only_applies_to_new_issuance')
        sql(w,e,"DELETE FROM setting WHERE type='BOT_STATES'")
        hosted=quote('hosted-claim','nextpay',9466,age=21660)
        assert run(w,e,dict(action='hosted_attention',id=hosted))['result'] is True
        assert run(w,e,dict(action='track',id=hosted,chat_id=9466,
                            message_id=3466))['result'] is True
        hosted_sync=run(w,e,dict(action='sync',id=hosted))
        assert hosted_sync['result']==1 and len(hosted_sync['events'])==1,hosted_sync
        assert 'پرداخت فاکتور' not in hosted_sync['events'][0]['keyboard']
        assert 'بررسی پرداخت قبلی' in hosted_sync['events'][0]['keyboard']
        assert run(w,e,dict(action='state',id=hosted))['result']['state']=='expired'
        assert run(w,e,dict(action='invoice_expire',id=hosted))['result'] is True
        assert state(w,e,'hosted-claim')=='expired'
        assert sql(w,e,f"SELECT CONCAT(stage,':',provider_reference) FROM payment_attempts WHERE pay_row_id={hosted}").strip()=='pending:hosted-claim'
        cases.append('expired_hosted_link_closed_original_merchant_reference_preserved')
        fresh_host=quote('fresh-hosted-claim','nextpay',9467)
        assert run(w,e,dict(action='hosted_attention',id=fresh_host))['result'] is False
        fresh_state=run(w,e,dict(action='state',id=fresh_host))['result']
        assert fresh_state['state']=='awaiting_payment'
        assert any(b.get('url')=='https://bot.fixture.invalid/pay/checkout.php?nextpay&hash_id=fresh-hosted-claim' for row in json.loads(fresh_state['keyboard'])['inline_keyboard'] for b in row),fresh_state
        assert 'مهلت پرداخت' in fresh_state['body']
        sql(w,e,f"UPDATE payment_attempts SET provider_status='confirming' WHERE pay_row_id={fresh_host}")
        confirming=run(w,e,dict(action='state',id=fresh_host))['result']
        assert confirming['state']=='needs_review' and 'پرداخت فاکتور' not in confirming['keyboard']
        sql(w,e,f"UPDATE payment_attempts SET stage='review',provider_status='confirmed' WHERE pay_row_id={fresh_host}")
        assert run(w,e,dict(action='invoice_expire',id=fresh_host,clock=deadline(fresh_host)))['result'] is False
        assert run(w,e,dict(action='state',id=fresh_host))['result']['state']=='needs_review'
        cases.append('hosted_recorded_provider_evidence_survives_expiry')
        race=quote('manual-expiry-race','bank_transfer',9499,age=21660)
        spec1=dict(action='invoice_expire',id=race)
        spec2=dict(action='manual_start',id=race,actor=9499,provider='bank_transfer')
        ps=[subprocess.Popen(cmd(w,e,ROOT,spec),env=e,cwd=w,stdout=subprocess.PIPE,
                             stderr=subprocess.PIPE) for spec in (spec1,spec2)]
        results=[]
        for pr in ps:
            stdout,stderr=pr.communicate(timeout=25)
            assert pr.returncode==0 and not stderr,(pr.returncode,stderr)
            results.append(json.loads(stdout)['result'])
        # Late click is always admissible; either transaction can close the
        # invoice, neither may revive it or create a timely grace.
        assert results in ([True,True],[False,True]),results
        assert state(w,e,'manual-expiry-race')=='expired'
        assert sql(w,e,f"SELECT receipt_started_at FROM payment_attempts WHERE pay_row_id={race}").strip()=='NULL'
        cases.append('concurrent_late_click_and_expiry_never_reopen_or_extend')
        # Current eligibility requires the linked service-operation evidence.
        # This suite's earlier partial-schema failures remain isolated.
        audience_db='fresh_audience'
        sql(w,e,"CREATE DATABASE "+audience_db+" CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")
        def audience_sql(q): return native.sql(w,e,q,audience_db)
        audience_sql((ROOT/'database/current-schema.sql').read_text())
        audience_sql("INSERT INTO users (userid,name,username,refcode,date,is_agent) "
            "VALUES (9501,'Agent fixture','synthetic_agent','0','1700000000',1),"
            "(9502,'Buyer fixture','synthetic_buyer','0','1700000000',0),"
            "(9503,'Ordinary fixture','synthetic_ordinary','0','1700000000',0)")
        audience_sql("INSERT INTO pays "
            "(hash_id,user_id,type,plan_id,server_id,volume,day,price,request_date,state) "
            "VALUES ('synthetic-paid-buyer',9502,'BUY_SUB',7,2,0,0,100,1700000000,'paid')")
        audience=lambda actor,mode,provider='bank_transfer': run(w,e,dict(
            action='audience',actor=actor,provider=provider,
            states={'gatewayAudience':{provider:mode}}),audience_db)['result']
        assert audience(9501,'agents') is True
        assert audience(9502,'agents') is False
        assert audience(9503,'non_agents') is True
        assert audience(9501,'non_agents') is False
        assert audience(9502,'buyers') is True
        assert audience(9503,'buyers') is False
        assert audience(9503,'non_buyers') is True
        assert audience(9501,'non_buyers') is True
        assert audience(9501,'off') is False
        assert audience(9501,'garbage') is False
        assert audience(9501,'all') is True
        # Reviewer F4: absent permits legacy all; explicit malformed NULL is
        # a real configured restriction and MUST deny, including nested modes.
        assert run(w,e,dict(action='audience',actor=9501,
            provider='bank_transfer',states={}),audience_db)['result'] is True
        assert run(w,e,dict(action='audience',actor=9501,
            provider='bank_transfer',states={'gatewayAudience':{}}),audience_db)['result'] is True
        for invalid in (None,7,'all',{'bank_transfer':None},
                        {'bank_transfer':[]},{'bank_transfer':{}},
                        {'bank_transfer':True},{'bank_transfer':'INVALID'}):
            assert run(w,e,dict(action='audience',actor=9501,
                provider='bank_transfer',states={'gatewayAudience':invalid}),audience_db)['result'] is False, invalid
        cases.append('f4_explicit_null_malformed_audience_denied_absent_legacy_compatible')
        assert run(w,e,dict(action='audience',actor=9501,provider='weSwap',
                            states={'gatewayAudience':{}}),audience_db)['result'] is False
        cases.append('per_method_audience_all_agent_buyer_none_fail_closed')
        # Current pinned-receipt cooldown policy is exercised in
        # test_wallet_first_manual.py on the canonical installation schema.
        # The installed Hell incident had hundreds of historical payment
        # messages awaiting projection while a newly auto-approved payment
        # had four unsynced receipt/invoice copies. Reserve newer terminal
        # work without starving the oldest backlog; never change paid state.
        sql(w,e,'UPDATE payment_message_refs SET updated_at=DATE_ADD(NOW(6),INTERVAL 1 DAY)')
        oldest=[]
        for idx in range(12):
            pay=add(w,e,f'projection-old-{idx}',old=int(time.time())-3600)
            sql(w,e,f"UPDATE pays SET state='paid' WHERE id={pay}")
            mid=6100+idx
            assert run(w,e,dict(action='track',id=pay,message_id=mid))['result'] is True
            sql(w,e,f'UPDATE payment_message_refs SET updated_at=DATE_SUB(NOW(6),INTERVAL 4 HOUR) WHERE pay_row_id={pay}')
            oldest.append((pay,mid))
        recent=add(w,e,'projection-new-auto-approved',old=int(time.time()))
        sql(w,e,f"UPDATE pays SET state='auto_cart_approved' WHERE id={recent}")
        assert run(w,e,dict(action='track',id=recent,message_id=6199))['result'] is True
        batch=run(w,e,dict(action='maintain',limit=8))
        edited={x['message_id'] for x in batch['events'] if x['method']=='editMessageText'}
        assert 6199 in edited and oldest[0][1] in edited,(batch,edited)
        assert batch['result']['edited']==8 and len(edited)==8,batch
        assert all('پرداخت فاکتور' not in e['keyboard'] for e in batch['events']
                   if e['method']=='editMessageText'),batch
        assert sql(w,e,f'SELECT COUNT(*) FROM payment_message_refs WHERE pay_row_id={recent} AND synced_digest IS NOT NULL').strip()=='1'
        assert state(w,e,'projection-new-auto-approved')=='auto_cart_approved'
        second=run(w,e,dict(action='maintain',limit=8))
        assert all(e['message_id']!=6199 for e in second['events']),second
        cases.append('recent_approved_receipt_projects_immediately_while_oldest_backlog_progresses')
        # Real installed Hell had seven already-ACKed payments among the first
        # eight oldest due candidates; the bounded worker wasted its capacity
        # scanning them instead of reconciling later unacknowledged payments.
        # Keep the old synced receipts available for future state changes,
        # but do not let them delay currently unsynced customer messages.
        sql(w,e,'UPDATE payment_message_refs SET updated_at=DATE_ADD(NOW(6),INTERVAL 1 DAY)')
        old_synced=[]
        for idx in range(12):
            paid=add(w,e,f'old-acked-{idx}',old=now-20000)
            sql(w,e,f"UPDATE pays SET state='paid' WHERE id={paid}")
            mid=6350+idx
            assert run(w,e,dict(action='track',id=paid,message_id=mid))['result'] is True
            assert run(w,e,dict(action='sync',id=paid))['result']==1
            sql(w,e,f'UPDATE payment_message_refs SET updated_at=DATE_SUB(NOW(6),INTERVAL 4 HOUR) WHERE pay_row_id={paid}')
            old_synced.append((paid,mid))
        # Changed state requires editing a previously ACKed digest; the fallback
        # scan must still discover and reproject it when budget is available.
        sql(w,e,f"UPDATE pays SET state='declined' WHERE id={old_synced[0][0]}")
        due=add(w,e,'backlogged-unacked-manual',kind='INCREASE_WALLET',old=now-300)
        sql(w,e,f"UPDATE pays SET state='have_sent' WHERE id={due}")
        assert run(w,e,dict(action='track',id=due,message_id=6399))['result'] is True
        sql(w,e,f'UPDATE payment_message_refs SET updated_at=DATE_SUB(NOW(6),INTERVAL 1 HOUR) WHERE pay_row_id={due}')
        recovered=run(w,e,dict(action='maintain',limit=8))
        edits=[x['message_id'] for x in recovered['events'] if x['method']=='editMessageText']
        assert 6399 in edits and old_synced[0][1] in edits,(recovered,edits)
        assert recovered['result']['edited']==2 and len(edits)==2,recovered
        assert count(w,e,'payment_message_refs',f'pay_row_id={due} AND synced_digest IS NOT NULL')==1
        assert count(w,e,'payment_message_refs',f'pay_row_id={old_synced[0][0]} AND synced_digest IS NOT NULL')==1
        subsequent=run(w,e,dict(action='maintain',limit=8))
        assert 6399 not in [x['message_id'] for x in subsequent['events']],subsequent
        cases.append('already_acked_old_receipts_do_not_block_unsynced_and_stale_state_still_reprojects')
        # A continuous queue of unacknowledged messages must not starve an
        # earlier already-ACKed original whose payment state subsequently
        # changes. Reserve a bounded full-scan lane even while many unsynced
        # due payments exist; no new record, backfill or financial action.
        sql(w,e,'UPDATE payment_message_refs SET updated_at=DATE_ADD(NOW(6),INTERVAL 1 DAY)')
        stale=add(w,e,'older-acked-then-paid',kind='INCREASE_WALLET',old=now-600)
        sql(w,e,f"UPDATE pays SET state='have_sent' WHERE id={stale}")
        assert run(w,e,dict(action='track',id=stale,message_id=6490))['result'] is True
        assert run(w,e,dict(action='sync',id=stale))['result']==1
        sql(w,e,f'UPDATE payment_message_refs SET updated_at=DATE_SUB(NOW(6),INTERVAL 5 HOUR) WHERE pay_row_id={stale}')
        sql(w,e,f"UPDATE pays SET state='paid' WHERE id={stale}")
        for idx in range(12):
            pay=add(w,e,f'new-unacked-continuous-{idx}',kind='INCREASE_WALLET',old=now-300)
            sql(w,e,f"UPDATE pays SET state='have_sent' WHERE id={pay}")
            assert run(w,e,dict(action='track',id=pay,message_id=6500+idx))['result'] is True
            sql(w,e,f'UPDATE payment_message_refs SET updated_at=DATE_SUB(NOW(6),INTERVAL 1 HOUR) WHERE pay_row_id={pay}')
        busy=run(w,e,dict(action='maintain',limit=8))
        busy_edits=[x['message_id'] for x in busy['events'] if x['method']=='editMessageText']
        assert 6490 in busy_edits and len(busy_edits)==8,(busy,busy_edits)
        assert count(w,e,'payment_message_refs',f'pay_row_id={stale} AND synced_digest IS NOT NULL')==1
        cases.append('continuous_unacked_backlog_keeps_full_scan_lane_for_changed_acked_originals')
    result={'complete':True,'cases':len(cases),'scenarios':cases,
            'source_real_php':True,'original_schema_unchanged':True,
            'production_mutations':0,'external_provider_requests':0}
    if args.report:args.report.write_text(json.dumps(result,indent=2,ensure_ascii=False)+'\n')
    print(json.dumps(result,ensure_ascii=False))

if __name__=='__main__':main()
