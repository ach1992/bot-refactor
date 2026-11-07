#!/usr/bin/env python3
"""API subject preflight on real PasarGuard PHP/cURL, owned SQL and loopback.
No installed bot, real credentials, external provider or Telegram operation.
"""
from __future__ import annotations
import argparse
import copy
import json
from pathlib import Path
import shutil
import subprocess
import threading
import time
from http.server import ThreadingHTTPServer

import provider_storage_fixture as storage
import test_access_state as owned
import test_v8_native as purchase
import test_owner_r6_api_flow as flow
import test_post_v9_bank_buy as bank
import test_post_v9_pasarguard_client as pg

ROOT=Path(__file__).resolve().parents[1]
DB=purchase.SIDE[1]
MIGRATIONS=['001_marzban_panel_group_id.sql','003_payment_attempts.sql',
 '004_automatic_renewal_attempts.sql','006_payment_message_refs.sql',
 '007_service_effects.sql','008_referral_awards.sql','009_bank_buy_invoice_claims.sql',
 '011_provider_identity.sql','012_shared_provider_storage.sql','014_api_payment_evidence.sql',
 '018_payment_delivery_intents.sql','020_payment_followup_intents.sql']

OPS=r'''
$id=(int)$spec['pay'];$pay=achbotApiPaymentRow($connection,$id);$value=null;
register_shutdown_function(static function(){
 $error=error_get_last();
 echo json_encode(['result'=>$GLOBALS['value']??null,'events'=>$GLOBALS['events'],
  'fatal'=>$error&&in_array($error['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR],true)?$error:null],
  JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
});
$op=$spec['op']??'check';
if($op==='pin') {achbotPgPinPayment($connection,$id);$value=true;}
elseif($op==='issue')$value=achbotManualClaim($connection,$pay,$spec['method']??'gift_card',1001);
elseif($op==='check')$value=achbotApiPaymentCheck($connection,$id);
elseif($op==='save'){$value=achbotPaymentEvidenceSaveGiftCode($connection,$id,1001,$spec['code']);if(($value['status']??'')==='saved'){$v=achbotManualBeginEvidence($connection,$pay,'gift_card',1001,$spec['code']);if(!$v||!achbotManualFinishEvidence($connection,$pay['hash_id'],$spec['code'],$v,222,9001))throw new RuntimeException('fixture_gift_review_delivery_failed');}}
elseif($op==='maintain')$value=achbotApiPaymentMaintain($connection);
elseif($op==='technical') {
 require_once $root.'/src/Finance/RecoveryInspection.php';
 $value=achbotRecoveryTechnicalCase($connection,$id)!==null;
}
elseif($op==='receipt'){$v=achbotManualBeginEvidence($connection,$pay,'bank_transfer',1001,'native-photo');$value=$v?achbotManualFinishEvidence($connection,$pay['hash_id'],'native-photo',$v,222,9001):false;}
elseif($op==='locked_drift') {
 if(!function_exists('achbotApiPaymentSubjectPreflight'))$value=['supported'=>false];
 else {
  $seen=achbotApiPaymentSubjectPreflight($connection,$pay);
  $other=new mysqli('localhost','root','',$dbName,0,$socket);
  $other->query("UPDATE orders_list SET uuid='changed-after-preflight' WHERE id=91");$other->close();
  $connection->begin_transaction();
  $locked=achbotApiPaymentRow($connection,$id,true);
  $value=['supported'=>true,'preflight'=>$seen['valid'],
   'allowed'=>achbotApiPaymentLocalSubjectAllowed($connection,$locked,$seen)];
  $connection->rollback();
 }
}
elseif($op==='locked_commerce') {
 $seen=achbotApiPaymentSubjectPreflight($connection,$pay);
 $other=new mysqli('localhost','root','',$dbName,0,$socket);
 if (array_key_exists('new_state',$spec)) {
  $next=(int)$spec['new_state'];
  $s=$other->prepare('UPDATE server_info SET state=? WHERE id=2');
  $s->bind_param('i',$next);$s->execute();$s->close();
 }
 $other->close();
 $connection->begin_transaction();
 $locked=achbotApiPaymentRow($connection,$id,true);
 $value=['preflight'=>$seen['valid'],
  'allowed'=>achbotApiPaymentLocalSubjectAllowed($connection,$locked,$seen)];
 $connection->rollback();
}
elseif($op==='human') {
 $from_id=(int)($spec['actor']??9001);$chat_id=$from_id;$message_id=222;$text=null;$userInfo=['step'=>'none'];$markup=[[]];
 $data=($pay['type']==='RENEW_ACCOUNT'?'approveRenewAcc':(str_starts_with($pay['type'],'INCREASE_DAY_')?'approveIncreaseDay':'approveIncreaseVolume')).$pay['hash_id'];
 if($pay['type']==='RENEW_ACCOUNT')require $root.'/src/Finance/Routes/RenewalCheckout.php';
 else require $root.'/src/Finance/Routes/Extensions.php';$value=true;
} else throw new RuntimeException('native_fixture_operation_unknown');
'''

class ObservedPG(pg.Handler):
    def handle_request(self):
        if self.server.delay:time.sleep(self.server.delay)
        hook=self.server.hook
        if hook and self.command=='GET' and '/api/user/' in self.path:
            self.server.hook=None
            # Freeze the authenticated observation, perform local drift, THEN
            # publish the old observation. The client cannot finish preflight
            # or claim SQL before the fixture's drift has actually committed.
            before=copy.deepcopy(self.server.user)
            assert self.headers.get('X-Api-Key')=='fixture-private-key'
            self.server.trace.append((self.command,self.path,None))
            hook()
            raw=json.dumps(before).encode()
            self.send_response(200);self.send_header('Content-Type','application/json')
            self.send_header('Content-Length',str(len(raw)));self.end_headers()
            try:self.wfile.write(raw)
            except (BrokenPipeError,ConnectionResetError):pass
            return
        super().handle_request()

def driver_source():
    php=bank.PHP[:bank.PHP.index("$op=$spec['op']??'auto';")]
    php=php.replace("function achbotApplyServiceEdit(...$args) {throw new RuntimeException('unexpected_edit');}","")
    php=php.replace("function achbotSupportsPanel($type) {return $type==='marzban';}","function achbotSupportsPanel($type) {return achbotSupportsProviderType($type);}")
    php=php.replace("$root=$argv[1];", "define('ACHBOT_PG_TEST_LOOPBACK',true);\n$root=$argv[1];")
    php=php.replace("function editKeys(...$args) {\n    $GLOBALS['events'][]=['event'=>'EDIT_KEYS','state'=>fixtureState()];\n}","function editKeys(...$args){$GLOBALS['events'][]=['event'=>'EDIT_KEYS','keys'=>(string)($args[0]??'')];return(object)['ok'=>true];}")
    php=php.replace("return (object)['ok'=>true];\n}\nfunction editKeys","return (object)['ok'=>true,'result'=>(object)['message_id'=>456]];\n}\nfunction editKeys")
    # Isolated Telegram uncertain ACK: the send is attempted but not proven.
    send_marker="    return (object)['ok'=>true,'result'=>(object)['message_id'=>456]];\n}\nfunction editKeys"
    assert php.count(send_marker)==1
    php=php.replace(send_marker,
        "    if (!empty($GLOBALS['spec']['customer_ack_unknown']) && (int)$to===1001) return (object)['ok'=>false];\n"+
        send_marker)
    php+="\n"+flow.PREP+r'''
function alert(...$args){$GLOBALS['events'][]=['event'=>'ALERT'];}
function editText(...$args){return(object)['ok'=>true];}
function delMessage(...$args){}
function setUser(...$args){}
function bot(...$args){return(object)['ok'=>true];}
''' +OPS
    return php

def check(progress_path=None):
    observations=[]
    def record(name,passed,actual):
        observations.append(dict(scenario=name,passed=bool(passed),actual=actual))
        if progress_path:progress_path.write_text(json.dumps(dict(complete=False,observations=observations),ensure_ascii=False,indent=2)+'\n')
    api=ThreadingHTTPServer(('127.0.0.1',0),flow.APIs)
    at=threading.Thread(target=api.serve_forever,daemon=True);at.start()
    api_url='http://127.0.0.1:'+str(api.server_port)
    panel=ThreadingHTTPServer(('127.0.0.1',0),ObservedPG)
    panel.trace=[];panel.user=copy.deepcopy(pg.USER);panel.mode='ok';panel.write_count=0
    panel.links_requested=False;panel.delay=0;panel.hook=None
    pt=threading.Thread(target=panel.serve_forever,daemon=True);pt.start()
    pg_url='http://127.0.0.1:'+str(panel.server_port)
    try:
      with owned.owned_database() as(work,env):
        app=work/'app'
        for folder in ['src','messages']:shutil.copytree(ROOT/folder,app/folder)
        # Observation only: the actual transport/authentication/HTTP result is
        # unchanged. This records the actual caller connection at every cURL.
        transport=app/'src/Panel/PasarGuard.php'
        needle='$ok=curl_exec($curl);$errno=curl_errno($curl);'
        body=transport.read_text();assert body.count(needle)==1
        body=body.replace(needle,r'''$GLOBALS['events'][]=['event'=>'PG_HTTP','method'=>$method,'path'=>$path,
 'tx'=>(int)$GLOBALS['connection']->query('SELECT @@in_transaction')->fetch_row()[0]];
    '''+needle)
        transport.write_text(body)
        source=app/'src/Finance/ApiPayments.php'
        body=source.read_text();needle="$result=(object)($payload['result']??[]);";assert body.count(needle)==1
        source.write_text(body.replace(needle,"if(!empty($GLOBALS['spec']['crash_after_verified']))throw new RuntimeException('fixture_process_loss_after_verified');\n    "+needle))
        # Exercise the actual manual callbacks through a controlled process
        # interruption AFTER the provider verified/journalled the native edit
        # and BEFORE the original local audit/payment COMMIT.
        for path,markers in [
            ('src/Finance/Routes/RenewalCheckout.php',
             ['if ($connection->begin_transaction()!==true)\n            throw new RuntimeException(\'manual_renew_finish_begin_failed\');']),
            ('src/Finance/Routes/Extensions.php',
             ['if ($connection->begin_transaction()!==true)\n            throw new RuntimeException(\'day_extension_finalization_begin_failed\');',
              'if ($connection->begin_transaction()!==true)\n            throw new RuntimeException(\'volume_extension_finalization_begin_failed\');'])
        ]:
            target=app/path
            raw=target.read_text()
            for marker in markers:
                assert raw.count(marker)==1,(path,marker)
                raw=raw.replace(marker,
                    "if(!empty($GLOBALS['spec']['crash_after_native']))\n"
                    "            throw new RuntimeException('fixture_manual_native_verified_before_sql');\n        "+marker,1)
            target.write_text(raw)
        # Test-only scheduling fault, applied to a disposable source copy:
        # payment+sealed outbox commits, but PHP ends before any Telegram.
        recovery=app/'src/Finance/ManualEditRecovery.php'
        body=recovery.read_text()
        marker='function achbotManualPgEditNotify(mysqli $db,int $payId): void {'
        assert body.count(marker)==1
        recovery.write_text(body.replace(marker,
            marker+"\n    if (!empty($GLOBALS['spec']['skip_followup_dispatch'])) return;",1))
        purchase.init(work,env,pg_url)
        def q(sql):return bank.query(work,env,sql)
        for migration in MIGRATIONS:q((ROOT/'migrations/post-v9'/migration).read_text())
        q("INSERT INTO setting(type,value) VALUES('BOT_STATES','{\"cartToCartState\":\"on\",\"giftCardState\":\"on\"}')")
        driver=work/'api-native.php';driver.write_text(driver_source())
        count=0
        def call(**spec):
            r=flow.run(work,env,app,driver,api_url,**spec)
            assert not r['fatal'],r
            return r
        def reset(kind='volume',method='gift_card'):
            nonlocal count
            count+=1
            q('DELETE FROM payment_followup_intents;DELETE FROM payment_delivery_attempts;DELETE FROM payment_delivery_intents;DELETE FROM payment_transactions;DELETE FROM bank_buy_invoice_claims;DELETE FROM payment_attempts;DELETE FROM increase_order;DELETE FROM service_effects;DELETE FROM orders_list;DELETE FROM pays')
            q(storage.connection(2,1,pg_url))
            q("UPDATE server_info SET ucount=100,state=1,active=1,provider_group_ids='[2,5]' WHERE id=2;UPDATE server_plans SET provider_group_ids='[2,5]',days=30,volume=2,price=101,active=1 WHERE id=7")
            q("INSERT INTO orders_list(id,userid,token,transid,fileid,server_id,inbound_id,remark,uuid,expire_date,link,amount,status,date) VALUES(91,1001,'fixture-token','',7,2,0,'fixture_user','fixture-uuid',1900000000,'[]',101,1,'1700000000')")
            panel.user=copy.deepcopy(pg.USER);panel.user['expire']=int(time.time())+3600
            panel.mode='ok';panel.trace=[];panel.write_count=0;panel.links_requested=False
            panel.delay=0;panel.hook=None
            q(storage.identity(1,91,1,pg_url,17,'2026-10-26T05:00:00.123456Z','fixture_user',pg.MARKER,
                dict(data_limit=2147483648,expire=panel.user['expire'],group_ids=[2,5],status='active')))
            typ={'volume':'INCREASE_VOLUME_91_1','day':'INCREASE_DAY_91_1','renew':'RENEW_ACCOUNT','buy':'BUY_SUB'}[kind]
            day=2 if kind=='day' else 0;volume=1 if kind=='volume' else 0;oid=0 if kind=='buy' else 91
            key='native'+str(count)
            q(f"INSERT INTO pays(hash_id,user_id,type,plan_id,server_id,order_id,price,day,volume,description,request_date,state) VALUES('{key}',1001,'{typ}',7,2,{oid},101,{day},{volume},'native fixture',UNIX_TIMESTAMP(),'pending')")
            n=int(q(f"SELECT id FROM pays WHERE hash_id='{key}'"))
            assert call(op='pin',pay=n)['result']
            flow.APIs.rows={};flow.APIs.cards={};flow.APIs.cache={};flow.APIs.mode='ok'
            return n,key
        def issue(n,method='gift_card'):
            return call(op='issue',pay=n,method=method)
        def fund(n,key,**extra):
            flow.APIs.cards[key]=dict(card_uuid='card-'+key,status='active',redeem_amount='101',
                display_amount='9999',game_key='fixture',redeem_value_id=1)
            assert call(op='save',pay=n,code=key)['result']['status']=='saved'
            return call(pay=n,**extra)
        def state(n):return q(f'SELECT state FROM pays WHERE id={n}')
        def tx_ok(r):return all(x['tx']==0 for x in r['events'] if x['event']=='PG_HTTP')
        def pg_events(r):return [x for x in r['events'] if x['event']=='PG_HTTP']
        for kind in ['volume','renew','day']:
            n,key=reset(kind);r=issue(n)
            record(kind+'_issuance_native_http_outside_sql',r['result'] is True and bool(pg_events(r)) and tx_ok(r),r)
        n,key=reset();panel.mode='http_503';r=issue(n)
        record('unreadable_issuance_no_attempt_no_locked_http',r['result'] is False and tx_ok(r) and
            q(f'SELECT COUNT(*) FROM payment_attempts WHERE pay_row_id={n}')=='0',r)
        n,key=reset();assert issue(n)['result'] is True;r=call(op='locked_drift',pay=n)
        record('local_identity_drift_after_preflight_before_locked_claim_denied',
            r['result'].get('supported') and r['result']['preflight'] and not r['result']['allowed'] and
            state(n)=='pending' and panel.write_count==0 and tx_ok(r),r)
        # The issuer may have accepted a Native snapshot, but final SQL
        # admission must reject a subsequently forbidden commerce policy.
        for kind,old_state,new_state,label in [
            ('buy',1,0,'new_buy_1_to_0'),
            ('buy',1,2,'new_buy_1_to_2'),
            ('renew',2,0,'existing_renew_2_to_0'),
            ('volume',1,0,'existing_addon_1_to_0'),
        ]:
            n,key=reset(kind)
            q(f'UPDATE server_info SET state={old_state} WHERE id=2')
            assert issue(n)['result'] is True,(kind,old_state)
            panel.trace=[];before=panel.write_count
            r=call(op='locked_commerce',pay=n,new_state=new_state)
            record('local_server_state_'+label+'_after_preflight_before_locked_claim_denied',
                r['result']=={'preflight':True,'allowed':False} and
                state(n)=='pending' and panel.write_count==before and tx_ok(r),r)
        n,key=reset('renew')
        assert issue(n)['result'] is True
        q('UPDATE server_info SET state=2 WHERE id=2')
        q('UPDATE server_plans SET active=0 WHERE id=7')
        r=call(op='locked_commerce',pay=n)
        record('state2_existing_renewal_saved_inactive_plan_allowed_at_locked_claim',
            r['result']=={'preflight':True,'allowed':True} and
            state(n)=='pending' and tx_ok(r),r)
        for mode in ['expired','unreadable','live','slow']:
            n,key=reset();assert issue(n)['result'] is True
            if mode=='expired':panel.user['expire']=int(time.time())-5;panel.user['status']='expired'
            if mode=='unreadable':panel.mode='http_503'
            if mode=='slow':panel.delay=.05
            r=fund(n,key);bad=mode in ['expired','unreadable']
            ok=(r['result']=='review' and state(n)=='have_sent' and panel.write_count==0) if bad else (r['result']=='confirmed' and state(n)=='auto_cart_approved' and panel.write_count==1)
            record('volume_'+mode+'_native_preflight_and_no_locked_http',ok and tx_ok(r),dict(result=r,state=state(n),writes=panel.write_count,trace=panel.trace))
            before=panel.write_count
            q(f'UPDATE payment_attempts SET evidence_next_check_at=NULL WHERE pay_row_id={n}')
            replay=call(pay=n)
            record('volume_'+mode+'_replay_no_second_write',panel.write_count==before,dict(result=replay,writes=panel.write_count))
        for kind in ['volume','day']:
            n,key=reset(kind);r=issue(n,'bank_transfer');assert r['result'] is True
            q(f'UPDATE payment_attempts SET created_at=NOW()-INTERVAL 601 SECOND WHERE pay_row_id={n}')
            assert call(op='receipt',pay=n)['result'] is True
            r=call(op='human',pay=n)
            record('human_'+kind+'_actual_route_native_http_outside_sql',state(n)=='approved' and panel.write_count==1 and tx_ok(r),dict(result=r,state=state(n),writes=panel.write_count))
        # A successful PasarGuard readback was committed to the existing
        # per-account mutation journal, but the PHP request died before its
        # local payment/order/audit COMMIT. The same original human button
        # finishes locally WITHOUT a second PUT/reset or provider GET.
        for kind in ['renew','day','volume']:
            n,key=reset(kind)
            assert issue(n,'bank_transfer')['result'] is True
            q(f'UPDATE payment_attempts SET created_at=NOW()-INTERVAL 601 SECOND WHERE pay_row_id={n}')
            assert call(op='receipt',pay=n)['result'] is True
            first=call(op='human',pay=n,actor=9001,crash_after_native=True)
            journal=json.loads(q('SELECT provider_mutation_snapshot FROM service_effects WHERE order_id=91'))
            writes=panel.write_count
            no_local=q('SELECT COUNT(*) FROM increase_order')=='0'
            record('manual_'+kind+'_verified_native_crash_leaves_processing',
                state(n)=='processing' and no_local and
                journal['key']=='manual:pay:'+str(n) and journal['result']=='verified' and
                journal['changes']['original_approver_id']==9001 and
                journal['changes']['original_amount_toman']==101 and
                q('SELECT provider_mutation_key FROM service_effects WHERE order_id=91')=='manual:pay:'+str(n),
                dict(state=state(n),journal=journal,writes=writes,result=first))
            panel.mode='http_503';panel.trace=[]
            # A approved; B (an existing authorized Operator) only finishes
            # the already-journalled work. Audit must still attribute A.
            done=call(op='human',pay=n,actor=9002)
            settled=state(n)=='approved'
            audit=q('SELECT COUNT(*) FROM increase_order')=='1'
            audit_units=q('SELECT IFNULL(day,0),IFNULL(volume,0) FROM increase_order ORDER BY id DESC LIMIT 1').split('\t')
            correct_units=((kind=='day' and audit_units==['2','0']) or
                           (kind=='volume' and audit_units==['0','1']) or
                           (kind=='renew' and audit_units==['0','0']))
            receipt_edits=[e for e in done['events'] if e.get('event')=='EDIT_KEYS']
            customer_notices=[e for e in done['events'] if
                              e.get('event')=='MESSAGE' and e.get('to')==1001]
            record('manual_'+kind+'_resume_verified_local_only_once',
                settled and audit and correct_units and panel.write_count==writes and
                panel.trace==[] and not pg_events(done) and
                len(receipt_edits)>=1 and len(customer_notices)>=1 and
                all('9001' in r.get('keys','') and '9002' not in r.get('keys','')
                    for r in receipt_edits) and
                q(f'SELECT approved_by FROM pays WHERE id={n}')=='9001' and
                q('SELECT amount FROM increase_order ORDER BY id DESC LIMIT 1')=='101',
                dict(state=state(n),audit=audit_units,result=done,writes=panel.write_count,
                     approved_by=q(f'SELECT approved_by FROM pays WHERE id={n}')))
            again=call(op='human',pay=n,actor=9002)
            record('manual_'+kind+'_repeat_never_reapplies_or_audits',
                state(n)=='approved' and q('SELECT COUNT(*) FROM increase_order')=='1' and
                panel.write_count==writes and panel.trace==[] and not pg_events(again),
                dict(state=state(n),writes=panel.write_count,result=again))

        # Previously verified native edit: finalize locally and seal all
        # original notices in 020, then simulate request death before
        # dispatch. A later authorized operator continues only PENDING
        # recipient packets without another provider call or audit.
        for kind in ['renew','day','volume']:
            n,key=reset(kind)
            assert issue(n,'bank_transfer')['result'] is True
            q(f'UPDATE payment_attempts SET created_at=NOW()-INTERVAL 601 SECOND WHERE pay_row_id={n}')
            assert call(op='receipt',pay=n)['result'] is True
            call(op='human',pay=n,actor=9001,crash_after_native=True)
            # Original Owner chat has a genuine second message plus exact
            # duplicates of both references; preserve two distinct edits.
            q(f"""UPDATE pays SET admins_messages='[{{"chat_id":9001,"message_id":222}},
              {{"chat_id":9001,"message_id":223}},{{"chat_id":9001,"message_id":223}}]'
              WHERE id={n}""")
            writes=panel.write_count;panel.trace=[]
            closed=call(op='human',pay=n,actor=9002,skip_followup_dispatch=True)
            sealed=q(f"SELECT role,recipient,IFNULL(message_id,0),state FROM payment_followup_intents "
                     f"WHERE pay_row_id={n} ORDER BY id").splitlines()
            before=q('SELECT COUNT(*) FROM increase_order')
            technical_pending=call(op='technical',pay=n)['result'] is True
            pending=len(sealed)==(4 if kind=='renew' else 3) and all(
                line.endswith('\tpending') for line in sealed)
            record('manual_'+kind+'_postcommit_before_telegram_staged',
                state(n)=='approved' and before=='1' and pending and technical_pending and
                panel.write_count==writes and panel.trace==[] and not pg_events(closed) and
                not [e for e in closed['events'] if e.get('event') in ('MESSAGE','EDIT_KEYS')],
                dict(closed=closed,sealed=sealed,write_count=writes))
            recovered=call(op='human',pay=n,actor=9002)
            dispatch=q(f"SELECT role,recipient,IFNULL(message_id,0),state FROM "
                       f"payment_followup_intents WHERE pay_row_id={n} ORDER BY id").splitlines()
            technical_closed=call(op='technical',pay=n)['result'] is False
            edited=[e for e in recovered['events'] if e.get('event')=='EDIT_KEYS']
            sent=[e for e in recovered['events'] if e.get('event')=='MESSAGE']
            record('manual_'+kind+'_delivery_only_resume_pending_once',
                state(n)=='approved' and q('SELECT COUNT(*) FROM increase_order')=='1' and
                len(dispatch)==len(sealed) and all(row.endswith('\tacknowledged') for row in dispatch) and
                len(edited)==2 and len(sent)==(2 if kind=='renew' else 1) and
                panel.trace==[] and panel.write_count==writes and not pg_events(recovered),
                dict(recovered=recovered,dispatch=dispatch,write_count=writes))
            repeat=call(op='human',pay=n,actor=9002)
            record('manual_'+kind+'_delivery_only_repeat_never_resends',
                q('SELECT COUNT(*) FROM increase_order')=='1' and
                panel.write_count==writes and not panel.trace and not pg_events(repeat) and
                not [e for e in repeat['events'] if e.get('event') in ('MESSAGE','EDIT_KEYS')],
                dict(repeat=repeat,write_count=writes))
        # A Telegram request with an unknown ACK is a one-shot attempt.
        # Repeated original approval never silently resends this customer
        # notice, while independent original receipts still reach admins.
        n,key=reset('volume')
        assert issue(n,'bank_transfer')['result'] is True
        q(f'UPDATE payment_attempts SET created_at=NOW()-INTERVAL 601 SECOND WHERE pay_row_id={n}')
        assert call(op='receipt',pay=n)['result'] is True
        call(op='human',pay=n,actor=9001,crash_after_native=True)
        writes=panel.write_count;panel.trace=[]
        uncertain=call(op='human',pay=n,actor=9002,customer_ack_unknown=True)
        states=q(f"SELECT role,state,attempt_count FROM payment_followup_intents "
                 f"WHERE pay_row_id={n} ORDER BY id").splitlines()
        technical_unknown=call(op='technical',pay=n)['result'] is True
        repeated=call(op='human',pay=n,actor=9002)
        record('manual_volume_unknown_customer_ack_not_automatically_resent',
            state(n)=='approved' and q('SELECT COUNT(*) FROM increase_order')=='1' and
            len(states)==2 and any(x=='customer\tunknown\t1' for x in states) and
            any(x=='receipt_primary\tacknowledged\t1' for x in states) and
            not [e for e in repeated['events'] if e.get('event') in ('MESSAGE','EDIT_KEYS')] and
            panel.write_count==writes and not panel.trace and not pg_events(repeated),
            dict(first=uncertain,repeat=repeated,states=states,writes=writes))
        # Missing 020 evidence must roll back BOTH local paid state and
        # order audit. Restore schema in the disposable fixture and resume
        # the same previously VERIFIED native edit without another HTTP.
        n,key=reset('day')
        assert issue(n,'bank_transfer')['result'] is True
        q(f'UPDATE payment_attempts SET created_at=NOW()-INTERVAL 601 SECOND WHERE pay_row_id={n}')
        assert call(op='receipt',pay=n)['result'] is True
        call(op='human',pay=n,actor=9001,crash_after_native=True)
        writes=panel.write_count;panel.trace=[]
        q('DROP TABLE payment_followup_intents')
        blocked=call(op='human',pay=n,actor=9002)
        no_commit=state(n)=='processing' and q('SELECT COUNT(*) FROM increase_order')=='0'
        q((ROOT/'migrations/post-v9/020_payment_followup_intents.sql').read_text())
        resumed=call(op='human',pay=n,actor=9002)
        record('manual_day_missing_followup_schema_rolls_back_local_finish',
            no_commit and state(n)=='approved' and
            q('SELECT COUNT(*) FROM increase_order')=='1' and
            q(f"SELECT COUNT(*) FROM payment_followup_intents WHERE pay_row_id={n}")=='2' and
            panel.write_count==writes and not panel.trace and not pg_events(blocked) and
            not pg_events(resumed) and
            any(e.get('event')=='ALERT' for e in blocked['events']),
            dict(blocked=blocked,resumed=resumed,no_commit=no_commit,writes=writes))

        # R1/R2 regression: a distinct authorized recovery Operator never
        # replaces the first human approver. The *original* issued 003 local
        # quote must also match the durable verified mutation evidence.
        for kind in ['renew','day','volume']:
            for mutation in ['changed_payment_amount','changed_attempt_amount','missing_original_actor']:
                n,key=reset(kind)
                assert issue(n,'bank_transfer')['result'] is True
                q(f'UPDATE payment_attempts SET created_at=NOW()-INTERVAL 601 SECOND WHERE pay_row_id={n}')
                assert call(op='receipt',pay=n)['result'] is True
                call(op='human',pay=n,actor=9001,crash_after_native=True)
                if mutation=='changed_payment_amount':
                    q(f'UPDATE pays SET price=202 WHERE id={n}')
                elif mutation=='changed_attempt_amount':
                    q(f'UPDATE payment_attempts SET local_amount_toman=202 WHERE pay_row_id={n}')
                else:
                    q("UPDATE service_effects SET provider_mutation_snapshot=JSON_REMOVE("
                      "provider_mutation_snapshot,'$.changes.original_approver_id') WHERE order_id=91")
                before=panel.write_count
                panel.mode='http_503';panel.trace=[]
                blocked=call(op='human',pay=n,actor=9002)
                record('manual_'+kind+'_resume_refuses_'+mutation,
                    state(n)=='processing' and
                    q('SELECT COUNT(*) FROM increase_order')=='0' and
                    q(f'SELECT IFNULL(approved_by,0) FROM pays WHERE id={n}')=='0' and
                    panel.write_count==before and not panel.trace and not pg_events(blocked) and
                    any(e.get('event')=='ALERT' for e in blocked['events']),
                    dict(state=state(n),writes=panel.write_count,result=blocked))
        # A claimed edit without exactly this final verified journal
        # cannot be finished from an old keyboard or the quiet inbox.
        for mutation in ['unverified','wrong_key','wrong_quote','wrong_identity','nonmanual_claim']:
            n,key=reset('volume')
            assert issue(n,'bank_transfer')['result'] is True
            q(f'UPDATE payment_attempts SET created_at=NOW()-INTERVAL 601 SECOND WHERE pay_row_id={n}')
            assert call(op='receipt',pay=n)['result'] is True
            call(op='human',pay=n,crash_after_native=True)
            if mutation=='unverified':
                q("UPDATE service_effects SET provider_state='mutating' WHERE order_id=91")
            elif mutation=='wrong_key':
                q("UPDATE service_effects SET provider_mutation_key='pay:99999' WHERE order_id=91")
            elif mutation=='wrong_quote':
                q(f'UPDATE pays SET volume=2 WHERE id={n}')
            elif mutation=='nonmanual_claim':
                other='pay:'+str(n)
                q("UPDATE service_effects SET provider_mutation_key='"+other+
                  "', provider_mutation_snapshot=JSON_SET(provider_mutation_snapshot,"+
                  "'$.key','"+other+"','$.consumed_keys',JSON_ARRAY('"+other+"')) WHERE order_id=91")
            else:
                q(f"UPDATE pays SET provider_snapshot=JSON_SET(provider_snapshot,'$.remote_user_id',99999) WHERE id={n}")
            writes=panel.write_count;panel.trace=[]
            r=call(op='human',pay=n)
            record('manual_volume_resume_refuses_'+mutation,
                state(n)=='processing' and q('SELECT COUNT(*) FROM increase_order')=='0' and
                panel.write_count==writes and not panel.trace and not pg_events(r) and
                any(e.get('event')=='ALERT' for e in r['events']),
                dict(state=state(n),writes=panel.write_count,result=r))
        for mode in ['live','unreadable','drift']:
            n,key=reset('renew');assert issue(n,'bank_transfer')['result'] is True
            q(f'UPDATE payment_attempts SET created_at=NOW()-INTERVAL 601 SECOND WHERE pay_row_id={n}')
            assert call(op='receipt',pay=n)['result'] is True
            if mode=='unreadable':panel.mode='http_503'
            elif mode=='drift':
                def drift_human():
                    panel.user['id']=18
                    q("UPDATE orders_list SET provider_remote_user_id=18 WHERE id=91;UPDATE service_effects SET provider_remote_user_id=18 WHERE id=1;UPDATE pays SET provider_snapshot=JSON_SET(provider_snapshot,'$.remote_user_id',18) WHERE id="+str(n))
                panel.hook=drift_human
            r=call(op='human',pay=n)
            ok=(state(n)=='approved' and panel.write_count==2) if mode=='live' else (state(n)=='have_sent' and panel.write_count==0)
            record('human_renew_'+mode+'_actual_route_native_http_outside_sql',ok and tx_ok(r),dict(result=r,state=state(n),writes=panel.write_count,trace=panel.trace))
        # Coherent local rewrites during the authenticated preflight must not
        # turn one observed incarnation into authorization for another.
        for phase in ['issue','settle']:
            n,key=reset()
            if phase=='settle':assert issue(n)['result'] is True
            def drift():
                panel.user['id']=18
                q("UPDATE orders_list SET provider_remote_user_id=18 WHERE id=91;UPDATE service_effects SET provider_remote_user_id=18 WHERE id=1;UPDATE pays SET provider_snapshot=JSON_SET(provider_snapshot,'$.remote_user_id',18) WHERE id="+str(n))
            panel.hook=drift
            r=issue(n) if phase=='issue' else fund(n,key)
            ok=(r['result'] is False and q(f'SELECT COUNT(*) FROM payment_attempts WHERE pay_row_id={n}')=='0') if phase=='issue' else (r['result']=='review' and state(n)=='have_sent')
            record(phase+'_coherent_native_identity_drift_during_preflight_denied',ok and panel.write_count==0,dict(result=r,state=state(n),writes=panel.write_count,trace=panel.trace))
        # A sealed verified result must finish locally even if the service
        # expires or is unreadable after the already-completed remote effect.
        for kind,mode in [('volume','expired'),('renew','unreadable')]:
            n,key=reset(kind);assert issue(n)['result'] is True
            r=fund(n,key,crash_after_verified=True)
            assert state(n)=='processing' and q(f"SELECT evidence_fulfillment_state FROM payment_attempts WHERE pay_row_id={n}")=='verified',r
            before=panel.write_count;panel.trace=[]
            if mode=='expired':panel.user['expire']=int(time.time())-5;panel.user['status']='expired'
            else:panel.mode='http_503'
            # Already verified/committed effects are recoverable even if the
            # administrator subsequently disables all new commerce.
            q('UPDATE server_info SET state=0 WHERE id=2')
            q(f'UPDATE payment_attempts SET evidence_next_check_at=NULL WHERE pay_row_id={n}')
            r=call(op='maintain',pay=n)
            record(kind+'_verified_recovery_after_'+mode+'_local_only',state(n)=='auto_cart_approved' and panel.write_count==before and not panel.trace and not pg_events(r),dict(result=r,state=state(n),writes=panel.write_count,trace=panel.trace))
        return dict(complete=all(x['passed'] for x in observations),cases=len(observations),
            scenarios=[x['scenario'] for x in observations],observations=observations,
            source_real_php=True,actual_pasarguard_http=True,socket_mariadb=True,
            migrations_executed=MIGRATIONS,live_requests=0,installed_tested=False,
            source_head=subprocess.check_output(['git','rev-parse','HEAD'],cwd=ROOT,text=True).strip())
    finally:
        api.shutdown();api.server_close();at.join()
        panel.shutdown();panel.server_close();pt.join()

def main():
    ap=argparse.ArgumentParser();ap.add_argument('--run-disposable',action='store_true',required=True)
    ap.add_argument('--report',type=Path);ap.add_argument('--source-root',type=Path,default=ROOT);args=ap.parse_args()
    global_root=args.source_root
    globals()['ROOT']=global_root
    report=check(args.report)
    if args.report:args.report.write_text(json.dumps(report,ensure_ascii=False,indent=2)+'\n')
    print(json.dumps({k:v for k,v in report.items() if k!='observations'}))
    if not report['complete']:
        print(json.dumps({'failed_observations':[
            {'scenario':o['scenario'],'actual':str(o['actual'])[:1800]}
            for o in report['observations'] if not o['passed']]}))
        raise SystemExit(1)
if __name__=='__main__':main()
