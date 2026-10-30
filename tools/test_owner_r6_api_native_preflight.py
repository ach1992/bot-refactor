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
 '011_provider_identity.sql','012_shared_provider_storage.sql','014_api_payment_evidence.sql']

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
elseif($op==='human') {
 $from_id=9001;$chat_id=9001;$message_id=222;$text=null;$userInfo=['step'=>'none'];$markup=[[]];
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
    php=php.replace("function editKeys(...$args) {\n    $GLOBALS['events'][]=['event'=>'EDIT_KEYS','state'=>fixtureState()];\n}","function editKeys(...$args){$GLOBALS['events'][]=['event'=>'EDIT_KEYS'];return(object)['ok'=>true];}")
    php=php.replace("return (object)['ok'=>true];\n}\nfunction editKeys","return (object)['ok'=>true,'result'=>(object)['message_id'=>456]];\n}\nfunction editKeys")
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
            q('DELETE FROM payment_transactions;DELETE FROM bank_buy_invoice_claims;DELETE FROM payment_attempts;DELETE FROM service_effects;DELETE FROM orders_list;DELETE FROM pays')
            q(storage.connection(2,1,pg_url))
            q("UPDATE server_info SET ucount=100,provider_group_ids='[2,5]' WHERE id=2;UPDATE server_plans SET provider_group_ids='[2,5]',days=30,volume=2,price=101,active=1 WHERE id=7")
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
    if not report['complete']:raise SystemExit(1)
if __name__=='__main__':main()
