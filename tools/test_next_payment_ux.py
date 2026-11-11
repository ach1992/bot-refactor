#!/usr/bin/env python3
"""Next-release owner QA: actual PHP routes, socket-only MariaDB, fake Telegram.

No provider/Telegram wire, tenant data, settlement or automatic repair is used.
"""
from __future__ import annotations
import argparse,json,subprocess,time
from pathlib import Path
import test_access_state as owned
import test_v6_native as native
import test_v8_native as purchase
import test_post_v9_purchases as stages
import test_post_v9_recovery_inspection as recovery

ROOT=Path(__file__).resolve().parents[1]
BASE='621307be775163f0b85ae9fa5b21b1d0b654cfed'
DB=purchase.SIDE[1]
PHP=r'''<?php
error_reporting(E_ALL & ~E_DEPRECATED);
set_error_handler(static function($n,$m){if ($n===E_DEPRECATED || (!empty($GLOBALS['spec']['baseline']) && $m==='Undefined array key "id"')) return true; throw new ErrorException($m,0,$n);});
$_SERVER['SCRIPT_FILENAME']=__FILE__;
$root=$argv[1];$spec=json_decode($argv[4],true,512,JSON_THROW_ON_ERROR);
$connection=new mysqli('localhost','root','',$argv[3],0,$argv[2]);$connection->set_charset('utf8mb4');
$from_id=(int)($spec['actor']??1001);$admin=9001;$chat_id=$from_id;$message_id=702;$callbackId='fixture';
$data=(string)($spec['data']??'');$text=(string)($spec['text']??'');$joniedState='member';$first_name='Fixture';$username='fixture';
$botState=['requirePhone'=>'off','sellState'=>'on','agencyPlanDiscount'=>$spec['discount_mode']??'on',
 'walletState'=>'on','nowPaymentOther'=>'off','zarinpal'=>'off','nextpay'=>'off','cartToCartState'=>'off','giftCardState'=>'off'];
$paymentKeys=[];$botUrl='http://127.0.0.1:9/';$botUsername='fixture';
$buttonValues=['back_to_main'=>'Home','cancel'=>'Cancel'];$mainValues=['start_message'=>'PERSONAL HOME'];
$q=$connection->prepare('SELECT * FROM users WHERE userid=?');$q->bind_param('i',$from_id);$q->execute();$userInfo=$q->get_result()->fetch_assoc();$q->close();
$GLOBALS['events']=[];$GLOBALS['native_reads']=0;
register_shutdown_function(static function(){echo json_encode(['events'=>$GLOBALS['events'],'native_reads'=>$GLOBALS['native_reads']],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);});
function sendMessage($text=null,$keys=null,$format=null,$to=null){$GLOBALS['events'][]=['event'=>'SEND','text'=>$text,'keys'=>json_decode($keys??'{}',true),'to'=>$to];return (object)['ok'=>true,'result'=>(object)['message_id'=>703,'chat'=>(object)['id'=>$GLOBALS['from_id']]]];}
function editText($id,$text,$keys=null,...$args){$GLOBALS['events'][]=['event'=>'EDIT','text'=>$text,'keys'=>json_decode($keys??'{}',true)];return (object)['ok'=>true];}
function alert(...$a){$GLOBALS['events'][]=['event'=>'ALERT','text'=>$a[0]??''];}
function answerCallbackQuery(...$a){$GLOBALS['events'][]=['event'=>'ACK','text'=>$a[1]??''];}
function delMessage(...$a){throw new RuntimeException('forbidden_delete_evidence');}
function getMainKeys(){return '{}';}
function setUser($v='none',$field='step'){global $connection,$from_id;if (!in_array($field,['step','temp'],true)) throw new RuntimeException('unexpected_user_write');$q=$connection->prepare("UPDATE users SET `$field`=? WHERE userid=?");$q->bind_param('si',$v,$from_id);$q->execute();$q->close();}
function RandomString(){return 'N'.bin2hex(random_bytes(9));}
function achbotSupportsPanel($type){return $type==='marzban';}
function getMarzbanUser($serverId,$remark){
 ++$GLOBALS['native_reads'];
 if((int)$serverId!==2 || !in_array($remark,['fixture41','fixture42'],true) ||
    !empty($GLOBALS['spec']['native_unknown']))return null;
 return(object)['username'=>$remark,'expire'=>$GLOBALS['spec']['native_expire']??(time()+30*86400),
  'data_limit'=>10*1073741824,'used_traffic'=>1073741824,'status'=>'active'];
}
function check(){}
function handleToggleCallbacks(...$a){return false;}
function achbotHandleChannelExemption(...$a){return false;}
function checkSpam(){return false;}
function createServerAccount(...$a){throw new RuntimeException('forbidden_provider_write');}
function achbotApplyServiceEdit(...$a){throw new RuntimeException('forbidden_provider_write');}
require_once $root.'/src/Purchase/Core.php';require_once $root.'/src/Finance/ReceiptHelpers.php';require_once $root.'/src/Finance/PaymentLifecycle.php';
require_once $root.'/src/Finance/PaymentKeyAdmin.php';
if (($spec['route']??'volume')==='home') {
 $telegramWebhookSecret='owned-fixture-Telegram-2026';$_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']=$telegramWebhookSecret;$_SERVER['REQUEST_METHOD']='POST';require $root.'/bot.php';
} elseif ($spec['route']==='status') {require $root.'/src/Finance/Routes/PrePayment.php'; $GLOBALS['events'][]=['event'=>'DISPATCH','data'=>$data];}
elseif ($spec['route']==='reference') {
 $hash=(string)$spec['hash'];$pay=achbotPayUxOwnedReference($connection,$hash,$from_id);
 $GLOBALS['events'][]=['event'=>'REFERENCE','pay'=>$pay,'text'=>$pay?achbotPayUxReference($pay):''];
 if ($pay) { $ack=achbotManualAcknowledgement($pay,'bank_transfer');sendWithStartButton($ack,'Home','fixture','mainMenu','HTML',$hash); }
} else require $root.'/src/Finance/Routes/Extensions.php';
'''

def sql(w,e,q):return native.sql(w,e,q,DB).strip()
def call(w,e,stage,**spec):
    driver=w/'next-payment.php';driver.write_text(PHP)
    cmd=['php','-n','-d','extension=mysqlnd','-d','extension=mysqli','-d','allow_url_fopen=0',
      '-d','disable_functions=curl_init,curl_exec,exec,shell_exec,system,passthru,popen,proc_open,mail',
      str(driver),str(stage),str(w/'mysql.sock'),DB,json.dumps(spec)]
    r=subprocess.run(cmd,cwd=stage,env=e,capture_output=True,text=True,timeout=20)
    if r.returncode or r.stderr:raise RuntimeError('native route failure '+repr((spec,r.returncode,r.stderr[-1600:],r.stdout[-300:])))
    return json.loads(r.stdout)
def event(r,n):return [x for x in r['events'] if x['event']==n]
def pay(w,e,key,kind='BUY_SUB',state='pending',provider=None,stage='review',status='fulfillment_unconfirmed',order=0):
    sql(w,e,f"INSERT INTO pays (hash_id,user_id,type,price,request_date,state,order_id,plan_id,server_id) VALUES ('{key}',1001,'{kind}',100,UNIX_TIMESTAMP(),'{state}',{order},7,2)")
    pid=int(sql(w,e,f"SELECT id FROM pays WHERE hash_id='{key}'"))
    if provider:sql(w,e,f"INSERT INTO payment_attempts (pay_row_id,provider,order_hash,quote_amount,quote_currency,local_amount_toman,stage,provider_status) VALUES ({pid},'{provider}','{key}',100,'toman',100,'{stage}','{status}')")
    return pid

def check():
    checks=[]
    with owned.owned_database() as (w,e):
        purchase.init(w,e,'http://127.0.0.1:9')
        for name in ['001_marzban_panel_group_id.sql','003_payment_attempts.sql','004_automatic_renewal_attempts.sql','006_payment_message_refs.sql','007_service_effects.sql','022_gateway_review_archive.sql']:
            sql(w,e,(ROOT/'migrations/post-v9'/name).read_text())
        app=w/'app';stages.stage_source(app,True);(app/'config.php').write_text('<?php // no production bootstrap\n')
        future=int(time.time())+86400*30
        sql(w,e,"INSERT INTO orders_list (id,userid,token,transid,fileid,server_id,inbound_id,remark,expire_date,link,amount,status,date,agent_bought) VALUES "
           f"(41,1001,'fixture','','7',2,0,'fixture41',{future},'[]',100,1,'fixture',0),"
           f"(42,9002,'fixture','','7',2,0,'fixture42',{future},'[]',100,1,'fixture',1)")
        sql(w,e,"INSERT INTO increase_plan(id,volume,price) VALUES (51,2,1000),(52,2.5,1000)")
        sql(w,e,"UPDATE users SET discount_percent='{\"normal\":10,\"plans\":{\"7\":25},\"servers\":{\"2\":40}}' WHERE userid=9002")
        path=app/'src/Finance/Routes/Extensions.php';current=path.read_bytes()
        path.write_bytes(subprocess.check_output(['git','show',BASE+':src/Finance/Routes/Extensions.php'],cwd=ROOT))
        assert event(call(w,e,app,route='volume',data='increaseAVolume41',baseline=True),'ALERT')
        path.write_bytes(current);r=call(w,e,app,route='volume',data='increaseAVolume41')
        assert '2 گیگ' in str(r) and '1000' in str(r) and '1,000' not in str(r) and 'orderDetails41' in str(r) and 'increaseVolumePlan41_51' in str(r)
        assert 'increaseVolumePlan41_52' not in str(r),r
        checks.append('baseline_RED_missing_order_identity_then_actual_volume_entry_GREEN')
        before=purchase.snapshot(w,e,DB)
        invalid=call(w,e,app,route='volume',data='increaseVolumePlan41_52')
        assert event(invalid,'ALERT') and not event(invalid,'SEND') and not event(invalid,'EDIT'),invalid
        assert purchase.snapshot(w,e,DB)==before
        checks.append('fractional_plan_hidden_and_forged_selection_has_no_draft_or_effect')
        r=call(w,e,app,route='volume',data='increaseAVolume42',actor=9002)
        assert '750' in str(r)
        r=call(w,e,app,route='volume',data='increaseAVolume42',actor=9002,discount_mode='off')
        assert '600' in str(r)
        checks.append('agent_plan_and_server_discount_use_persisted_order_fields')
        before=purchase.snapshot(w,e,DB)
        for data in ['increaseAVolume42','increaseVolumePlan42_51','increaseVolumePlan41_999','increaseAVolume41junk','increaseVolumePlan41_51junk']:
            r=call(w,e,app,route='volume',data=data)
            assert not event(r,'SEND') and not event(r,'EDIT'),(data,r)
        assert purchase.snapshot(w,e,DB)==before
        checks.append('foreign_missing_plan_and_malformed_callbacks_no_draft_or_effect')
        sql(w,e,"UPDATE server_config SET type='sanaei' WHERE id=2")
        for data in ['increaseAVolume41','increaseVolumePlan41_51']:
            assert event(call(w,e,app,route='volume',data=data),'ALERT')
        sql(w,e,"UPDATE server_config SET type='marzban' WHERE id=2")
        sql(w,e,'UPDATE orders_list SET expire_date=1 WHERE id=41')
        # A stale local expiry must not override the current native state.
        live=call(w,e,app,route='volume',data='increaseAVolume41')
        assert 'increaseVolumePlan41_51' in str(live) and live['native_reads']>0,live
        checks.append('stale_local_expiry_does_not_block_live_native_volume_entry')
        sql(w,e,f'UPDATE orders_list SET expire_date={future} WHERE id=41')
        for data in ['increaseAVolume41','increaseVolumePlan41_51']:
            assert event(call(w,e,app,route='volume',data=data,native_expire=1),'ALERT')
        checks.append('retired_provider_and_expired_entry_and_selection_fail_closed')
        before=purchase.snapshot(w,e,DB)
        for data in ['increaseAVolume41','increaseVolumePlan41_51']:
            unknown=call(w,e,app,route='volume',data=data,native_unknown=True)
            assert unknown['native_reads']>0 and event(unknown,'ALERT') and not event(unknown,'SEND') and not event(unknown,'EDIT'),unknown
        assert purchase.snapshot(w,e,DB)==before
        checks.append('unknown_native_volume_entry_and_selection_refuse_without_draft_or_effect')
        r=call(w,e,app,route='volume',data='increaseVolumePlan42_51',actor=9002)
        assert event(r,'SEND') and sql(w,e,"SELECT price FROM pays WHERE user_id=9002 ORDER BY id DESC LIMIT 1")=='750'
        assert sql(w,e,"SELECT volume FROM pays WHERE user_id=9002 ORDER BY id DESC LIMIT 1")=='2'
        draft=sql(w,e,'SELECT hash_id FROM pays WHERE user_id=9002 ORDER BY id DESC LIMIT 1')
        assert sql(w,e,'SELECT CONCAT(plan_id,\':\',server_id,\':\',order_id) FROM pays WHERE user_id=9002 ORDER BY id DESC LIMIT 1')=='7:2:42'
        r=call(w,e,app,route='status',data='prePayCheck_Increase_wallet_'+draft,actor=9002)
        assert event(r,'DISPATCH')[0]['data']=='payIncreaseWithWallet'+draft,r
        assert sql(w,e,"SELECT state FROM pays WHERE hash_id='"+draft+"'")=='pending'
        checks.append('selected_agent_draft_enters_real_guarded_prepayment_without_settlement')
        pid=pay(w,e,'evidenceinvoice',state='expired')
        oldbot=app/'bot.php';newbot=oldbot.read_bytes()
        baseline_bot=subprocess.check_output(['git','show',BASE+':bot.php'],cwd=ROOT)
        # Only the disposable historical route copy uses the current bootstrap.
        # The archived source bytes and home/evidence comparison stay unchanged.
        old_import=b"require_once 'buy_helpers.php';"
        assert baseline_bot.count(old_import)==1
        oldbot.write_bytes(baseline_bot.replace(old_import,
            b"require_once __DIR__.'/src/Purchase/Core.php';",1))
        assert event(call(w,e,app,route='home',data='mainMenu'),'EDIT')
        oldbot.write_bytes(newbot)
        before=purchase.snapshot(w,e,DB)
        for data,text in [('mainMenu',''),('evidenceHome',''),('ph_evidenceinvoice',''),('ph_othercustomer',''),('','/start')]:
            r=call(w,e,app,route='home',data=data,text=text)
            assert len(event(r,'SEND'))==1 and not event(r,'EDIT') and event(r,'SEND')[0]['text']=='PERSONAL HOME',r
        assert purchase.snapshot(w,e,DB)==before
        checks.append('baseline_RED_home_replaces_evidence_then_new_and_legacy_home_GREEN_including_deep_link')
        r=call(w,e,app,route='status',data='ps_evidenceinvoice')
        assert '1001' in str(r) and 'evidenceinvoice' in str(r) and 'ph_evidenceinvoice' in str(r)
        assert 'url' not in str(r)
        r=call(w,e,app,route='status',data='ps_evidenceinvoice',actor=9002)
        assert event(r,'ACK') and not event(r,'EDIT') and 'evidenceinvoice' not in str(event(r,'ACK'))
        r=call(w,e,app,route='reference',hash='evidenceinvoice',actor=9002)
        assert event(r,'REFERENCE')[0]['pay'] is None
        r=call(w,e,app,route='reference',hash='evidenceinvoice')
        assert event(r,'SEND')[0]['text'].count('1001')==1
        assert 'https://t.me/fixture?start=mainMenu' in str(r)
        assert purchase.snapshot(w,e,DB)==before
        checks.append('expired_status_no_payment_action_foreign_status_private_persisted_reference_no_duplicate')
        driver=w/'recovery-driver.php';driver.write_text(recovery.DRIVER)
        # The new inbox deliberately excludes manually reviewed receipts and
        # finalized/paid Native service cases; it is ONLY for automatic-gateway
        # invoices with a recorded original provider claim.
        recovery.ROOT=app
        manual=pay(w,e,'manualreview','INCREASE_WALLET',state='have_sent',
            provider='bank_transfer',status='receipt_received')
        terminal=pay(w,e,'alreadydone',state='paid',provider='zarinpal',
            stage='done',status='delivered')
        native=pay(w,e,'paidnative',state='paid',provider='nextpay',
            stage='review',status='fulfillment_unconfirmed')
        gateway=pay(w,e,'auto-uncertain','INCREASE_WALLET',state='pending',
            provider='zarinpal',stage='review',status='verification_unavailable')
        sql(w,e,f"UPDATE pays SET payid='fixture-zarinpal-authority' WHERE id={gateway}; "
                f"UPDATE payment_attempts SET provider_reference='fixture-zarinpal-authority' "
                f"WHERE pay_row_id={gateway}")
        unknown=pay(w,e,'failed-original','INCREASE_WALLET',state='pending',
            provider='nextpay',stage='review',status='operator_fulfillment_unconfirmed')
        held=pay(w,e,'held-native','BUY_SUB',state='processing',
            provider='nowpayments',stage='review',status='fulfillment_failed')
        sql(w,e,f"UPDATE payment_attempts SET updated_at=NOW()-INTERVAL 11 MINUTE "
            f"WHERE pay_row_id IN ({held},{unknown})")
        unpaid=pay(w,e,'old-unpaid','INCREASE_WALLET',state='pending',
            provider='nextpay',stage='pending',status=None)
        sql(w,e,f'UPDATE payment_attempts SET created_at=NOW()-INTERVAL 7 HOUR '
            f'WHERE pay_row_id={unpaid}')
        before=purchase.snapshot(w,e,DB)
        inbox=recovery.call(w,e,driver,op='route',callback='attentionInbox')
        cards=event(inbox,'EDIT')
        assert len(cards)==1 and 'پرداخت‌های نیازمند بررسی' in cards[0]['text'],inbox
        keys={b['callback_data'] for row in cards[0]['buttons']['inline_keyboard'] for b in row}
        assert f'attentionCase_{gateway}' in keys,('missing_automatic_case',keys)
        assert all(f'attentionCase_{i}' in keys for i in (unknown,held)),keys
        assert all(f'attentionCase_{i}' not in keys for i in (manual,terminal,native,unpaid)),keys
        checks.append('only_incomplete_automatic_gateways_appear_no_receipts_or_paid_service')
        for payid in (unknown,held):
            blocked=recovery.call(w,e,driver,op='route',callback=f'attentionCase_{payid}')
            edits=event(blocked,'EDIT')
            assert len(edits)==1 and not event(blocked,'GET'),blocked
            controls={b['callback_data'] for row in edits[0]['buttons']['inline_keyboard']
                for b in row}
            assert controls=={'attentionInbox',
                'gatewayReview_H_'+sql(w,e,
                    f'SELECT id FROM payment_attempts WHERE pay_row_id={payid}')},controls
            assert 'عملیات قبلی' in edits[0]['text'],edits
        checks.append('unfinished_original_effects_readonly_but_archive_available')
        detail=recovery.call(w,e,driver,op='route',callback=f'attentionCase_{gateway}')
        edits=event(detail,'EDIT')
        assert len(edits)==1 and 'زرین‌پال' in edits[0]['text'],detail
        assert 'fixture-zarinpal-authority' in edits[0]['text']
        assert '🎫 شناسه عددی: ' in edits[0]['text']
        choices={b['callback_data'] for row in edits[0]['buttons']['inline_keyboard'] for b in row}
        attempt=int(sql(w,e,f'SELECT id FROM payment_attempts WHERE pay_row_id={gateway}'))
        assert {'gatewayReview_A_'+str(attempt),'gatewayReview_R_'+str(attempt),
                'gatewayReview_H_'+str(attempt),'attentionInbox'}.issubset(choices),choices
        assert not event(detail,'GET') and not event(detail,'SEND'),detail
        checks.append('original_invoice_details_and_two_evidence_aware_actions_are_local')
        declined=recovery.call(w,e,driver,op='route',callback=f'attentionCase_{terminal}')
        assert event(declined,'EDIT') and not event(declined,'GET')
        assert 'دیگر نیازمند بررسی نیست' in event(declined,'EDIT')[0]['text']
        checks.append('completed_payment_cannot_be_reopened_from_old_callback')
        for actor,chat in [(1001,1001),(9001,1001),(9002,9001)]:
            private=recovery.call(w,e,driver,op='route',
                callback=f'attentionCase_{gateway}',actor=actor,chat=chat)
            assert event(private,'ACK') and not event(private,'EDIT') and not event(private,'SEND'),private
        checks.append('forged_nonadmin_and_nonprivate_original_order_inbox_denied')
        assert purchase.snapshot(w,e,DB)==before
        checks.append('automatic_gateway_inbox_all_views_readonly_no_native_get_or_mutation')
        # Simulate the ACTUAL operator archive callback; no native, wallet,
        # settlement, customer message, child status or service mutation.
        native_before=purchase.snapshot(w,e,DB)
        original=sql(w,e,f"SELECT p.state,a.stage,a.provider_status "
            f"FROM pays p JOIN payment_attempts a ON a.pay_row_id=p.id WHERE p.id={held}")
        hold_attempt=sql(w,e,f'SELECT id FROM payment_attempts WHERE pay_row_id={held}')
        rejected=recovery.call(w,e,driver,op='route',
            callback='gatewayReview_H_'+hold_attempt,actor=1001,chat=1001)
        assert event(rejected,'ACK') and not event(rejected,'EDIT') and not event(rejected,'SEND')
        assert sql(w,e,f'SELECT review_archived_at IS NULL FROM payment_attempts WHERE id={hold_attempt}')=='1'
        rejected=recovery.call(w,e,driver,op='route',
            callback='gatewayReview_H_'+hold_attempt,actor=9001,chat=1001)
        assert event(rejected,'ACK') and not event(rejected,'EDIT') and not event(rejected,'SEND')
        assert sql(w,e,f'SELECT review_archived_at IS NULL FROM payment_attempts WHERE id={hold_attempt}')=='1'
        checks.append('archive_requires_current_staff_and_private_chat')
        archived=recovery.call(w,e,driver,op='route',
            callback='gatewayReview_H_'+hold_attempt)
        assert event(archived,'ACK') and len(event(archived,'EDIT'))==1,archived
        assert 'پرونده آرشیو شد' in event(archived,'EDIT')[0]['text'],archived
        after=sql(w,e,f"SELECT p.state,a.stage,a.provider_status "
            f"FROM pays p JOIN payment_attempts a ON a.pay_row_id=p.id WHERE p.id={held}")
        assert after==original,(original,after)
        actor=sql(w,e,f'SELECT review_archived_by FROM payment_attempts WHERE id={hold_attempt}')
        assert actor=='9001',actor
        reopened=recovery.call(w,e,driver,op='route',callback='attentionInbox')
        keys={b['callback_data'] for row in event(reopened,'EDIT')[0]['buttons']['inline_keyboard']
              for b in row}
        assert f'attentionCase_{held}' not in keys and f'attentionCase_{unknown}' in keys,keys
        assert f'attentionCase_{gateway}' in keys,keys
        stale=recovery.call(w,e,driver,op='route',callback=f'attentionCase_{held}')
        assert 'دیگر نیازمند بررسی نیست' in event(stale,'EDIT')[0]['text']
        repeated=recovery.call(w,e,driver,op='route',
            callback='gatewayReview_H_'+hold_attempt)
        assert 'قبلاً بررسی شده' in event(repeated,'EDIT')[0]['text']
        assert not event(archived,'GET') and not event(archived,'SEND')
        checks.append('operator_archive_removes_only_selected_case_without_settlement')
        # An old/stale callback must not reopen the original case or
        # rewrite its financial status. Actual signed IPN/Cron probes are
        # source-real in the finance-route suite.
        duplicate=recovery.call(w,e,driver,op='route',
            callback='gatewayReview_H_'+hold_attempt)
        assert 'قبلاً بررسی شده' in event(duplicate,'EDIT')[0]['text']
        reopened=recovery.call(w,e,driver,op='route',callback='attentionInbox')
        keys={b['callback_data'] for row in event(reopened,'EDIT')[0]['buttons']['inline_keyboard']
              for b in row}
        assert f'attentionCase_{held}' not in keys,keys
        assert sql(w,e,f'SELECT review_archived_by FROM payment_attempts WHERE id={hold_attempt}')=='9001'
        checks.append('archived_case_stays_closed_after_stale_operator_callback')
    return {'complete':True,'cases':len(checks),'scenarios':checks,'fresh_native_addon_admission':True,'unknown_native_refuses_without_draft':True,'limits':['Fake Telegram transport; no installed/operator UI acceptance','No live tenant/provider/financial effects','PHP runtime reported by invocation; aggregate PHP matrix owned by Master']}

if __name__=='__main__':
    p=argparse.ArgumentParser();p.add_argument('--run-disposable',required=True,action='store_true');p.add_argument('--report',type=Path);a=p.parse_args();r=check()
    if a.report:a.report.write_text(json.dumps(r,indent=2,ensure_ascii=False)+'\n')
    print(json.dumps(r,ensure_ascii=False))
