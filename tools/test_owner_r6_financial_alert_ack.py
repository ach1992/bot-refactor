#!/usr/bin/env python3
"""Actual durable financial alert callers over owned shared-ledger MariaDB."""
from pathlib import Path
import argparse,json,shutil,subprocess
import notification_fixture
import test_access_state as owned

ROOT=Path(__file__).resolve().parents[1]
BASE='62eb06d164224947f1cda84144d08ff88d3a0c8e'
PHP=r'''<?php
error_reporting(E_ALL & ~E_DEPRECATED);
set_error_handler(static function($n,$m){if($n===E_DEPRECATED)return true;throw new ErrorException($m,0,$n);});
$root=$argv[1];$connection=new mysqli('localhost','root','',$argv[3],0,$argv[2]);
$connection->set_charset('utf8mb4');$admin=null;$botState=['renewAutoState'=>'off','rewardChannel'=>-100123];
$events=[];$checks=[];$failTarget=null;$nonBooleanTarget=null;$duringSend=null;
require_once $root.'/src/Purchase/Core.php';
define('ACHBOT_NP_RECONCILE_ONLY',true);
require_once $root.'/src/Finance/GatewayReturn.php';
require_once $root.'/src/Finance/AutomaticRenewal.php';
$admin=9001;
function yes(bool $ok,string $name):void{if(!$ok)throw new RuntimeException($name);$GLOBALS['checks'][]=$name;}
function sendMessage($text,$keys=null,$parse=null,$target=null){
 global $connection,$events,$failTarget,$nonBooleanTarget,$duringSend;
 $events[]=['to'=>(int)$target,'text'=>$text,'keys'=>$keys,'parse'=>$parse,'tx'=>(int)$connection->query('SELECT @@in_transaction')->fetch_row()[0]];
 if($duringSend!==null)$duringSend((int)$target);
 return(object)['ok'=>(int)$target===$nonBooleanTarget?'true':((int)$target!==$failTarget)];
}
function financialState():array{
 $out=[];$db=$GLOBALS['connection'];
 foreach(['users','pays','orders_list','payment_attempts','payment_transactions','automatic_renewal_attempts'] as $table){
  $rows=$db->query('SELECT * FROM '.$table.' ORDER BY id')->fetch_all(MYSQLI_ASSOC);
  if(in_array($table,['payment_attempts','automatic_renewal_attempts'],true))foreach($rows as &$row)
   foreach(['review_notified_at','review_notice_claim_token','review_notice_claimed_at','review_claim_token','review_claimed_at','updated_at'] as $key)unset($row[$key]);
  unset($row);$out[$table]=$rows;
 }
 return $out;
}
function alertState(string $domain):array{
 $db=$GLOBALS['connection'];$table=$domain==='renewal'?'automatic_renewal_attempts':'payment_attempts';
 $prefix=$domain==='renewal'?'review_':'review_notice_';
 return $db->query('SELECT review_notified_at,'.$prefix.'claim_token AS token,'.$prefix.'claimed_at AS claimed FROM '.$table.' WHERE id=1')->fetch_assoc();
}
function deliver(string $domain,int $primary=9001):?array{
 return $domain==='renewal'?(achbotAutoRenewAlertReview($GLOBALS['connection'],$primary)??null):achbotNpAlertOperator($GLOBALS['connection'],$primary);
}
function rearm(string $domain):void{
 $table=$domain==='renewal'?'automatic_renewal_attempts':'payment_attempts';
 $GLOBALS['connection']->query('UPDATE '.$table." SET stage='review',review_notified_at=NULL WHERE id=1");
}
$connection->query("INSERT INTO users(userid,name,username,refcode,wallet,date,isAdmin,first_start) VALUES(9003,'Manager','manager','0',0,'1700000000',1,'sent')");
$connection->query("INSERT INTO setting(type,value) VALUES('ADMIN_ROLE_9003','manager'),('ADMIN_NOTIFY_9003','on')");
$connection->query("INSERT INTO pays(id,hash_id,user_id,type,price,request_date,state) VALUES(101,'renew-alert',1001,'RENEW_ACCOUNT',100000,UNIX_TIMESTAMP(),'processing_wallet'),(102,'gateway-alert',1001,'INCREASE_WALLET',100000,UNIX_TIMESTAMP(),'review')");
$connection->query("INSERT INTO automatic_renewal_attempts(id,order_id,cycle_expire,pay_row_id,user_id,plan_id,server_id,account_remark,price_toman,duration_days,volume_gb,new_expire,stage,reason_code,created_at) VALUES(1,42,1,101,1001,7,2,'fixture',100000,30,2,2000000000,'review','remote_unconfirmed',NOW()-INTERVAL 10 MINUTE)");
$connection->query("INSERT INTO payment_attempts(id,pay_row_id,provider,order_hash,provider_reference,payment_id,quote_amount,quote_currency,local_amount_toman,stage,provider_status) VALUES(1,102,'nowpayments','gateway-alert','invoice-fixture','child-original',1,'usd',100000,'review','fulfillment_unconfirmed')");
$connection->query("INSERT INTO payment_transactions(attempt_id,provider,payment_id,observed_status) VALUES(1,'nowpayments','child-original','finished')");
$writer=new mysqli('localhost','root','',$argv[3],0,$argv[2]);
$rolePk=(int)$connection->query("SELECT id FROM setting WHERE type='ADMIN_ROLE_9003'")->fetch_row()[0];
$prefPk=(int)$connection->query("SELECT id FROM setting WHERE type='ADMIN_NOTIFY_9003'")->fetch_row()[0];
foreach(explode(',',$argv[4]) as $domain){
 foreach(['manager_failure','owner_failure','discovery_writer','role_writer','preference_writer','primary_mismatch','nonboolean_success'] as $mode){
  rearm($domain);$before=financialState();$events=[];$failTarget=match($mode){'manager_failure'=>9003,'owner_failure'=>9001,default=>null};
  $nonBooleanTarget=$mode==='nonboolean_success'?9003:null;
  if(str_ends_with($mode,'writer')){
   $writer->begin_transaction();$query=match($mode){'discovery_writer'=>'SELECT id FROM users WHERE userid=1001 FOR UPDATE','role_writer'=>'SELECT id FROM setting WHERE id='.$rolePk.' FOR UPDATE',default=>'SELECT id FROM setting WHERE id='.$prefPk.' FOR UPDATE'};
   $writer->query($query);
  }
  $started=microtime(true);$result=deliver($domain,$mode==='primary_mismatch'?9009:9001);$elapsed=microtime(true)-$started;$state=alertState($domain);
  $expected=$mode==='primary_mismatch'?[]:(str_ends_with($mode,'writer')?[9001]:[9001,9003]);
  yes(array_column($events,'to')===$expected&&$state['review_notified_at']===null&&$state['token']===null&&$state['claimed']===null&&$elapsed<1.0&&($domain!=='gateway'||($result['notified']===0&&$result['failed']===1))&&financialState()===$before,
   $domain.' '.$mode.' incomplete fanout retains retryable marker releases lease and preserves financial evidence');
  if(str_ends_with($mode,'writer'))$writer->rollback();$failTarget=null;$nonBooleanTarget=null;$events=[];$result=deliver($domain);$state=alertState($domain);
  yes(array_column($events,'to')===[9001,9003]&&$state['review_notified_at']!==null&&$state['token']===null&&$state['claimed']===null&&!array_filter($events,static fn($e)=>$e['tx']!==0)&&financialState()===$before&&($domain!=='gateway'||$result['notified']===1),
   $domain.' '.$mode.' next ordinary call reaches Manager acknowledges complete fanout without financial replay');
  $events=[];deliver($domain);
  yes($events===[]&&financialState()===$before,$domain.' '.$mode.' completed fanout remains quiet on unchanged evidence');
 }
 foreach(['off','revoked'] as $mode){
  rearm($domain);
  if($mode==='off')$connection->query("UPDATE setting SET value='off' WHERE type='ADMIN_NOTIFY_9003'");
  else $connection->query('UPDATE users SET isAdmin=0 WHERE userid=9003');
  $before=financialState();$events=[];$result=deliver($domain);$state=alertState($domain);
  yes(array_column($events,'to')===[9001]&&$state['review_notified_at']!==null&&$state['token']===null&&$state['claimed']===null&&financialState()===$before&&($domain!=='gateway'||$result['notified']===1),
   $domain.' '.$mode.' is a definitive complete exclusion preserving private payload destination');
  $events=[];deliver($domain);yes($events===[]&&financialState()===$before,$domain.' '.$mode.' successful exclusion retains existing notification throttle');
  $connection->query("UPDATE setting SET value='on' WHERE type='ADMIN_NOTIFY_9003'");$connection->query('UPDATE users SET isAdmin=1 WHERE userid=9003');
 }
 rearm($domain);$events=[];$injected=false;
 $duringSend=static function($target)use($domain,&$injected,$connection){
  if($target!==9001||$injected)return;$injected=true;
  if($domain==='gateway')$connection->query("INSERT INTO payment_transactions(attempt_id,provider,payment_id,observed_status) VALUES(1,'nowpayments','child-new','finished')");
  else $connection->query("UPDATE automatic_renewal_attempts SET stage='remote_started',review_claim_token='ffffffffffffffffffffffffffffffff',review_claimed_at=NOW() WHERE id=1");
 };
 $result=deliver($domain);$duringSend=null;$state=alertState($domain);
 $leaseSafe=$domain==='gateway'?($state['token']===null&&$state['claimed']===null):($state['token']==='ffffffffffffffffffffffffffffffff'&&$state['claimed']!==null);
 yes($injected&&array_column($events,'to')===[9001,9003]&&$state['review_notified_at']===null&&$leaseSafe&&($domain!=='gateway'||$result['notified']===0),
  $domain.' concurrent evidence generation preserves exact acknowledgement CAS and lease ownership');
 // The synthetic newer renewal worker releases its own lease; the old
 // notifier must never release it. No elapsed-time/retry policy is changed.
 if($domain==='renewal')$connection->query("UPDATE automatic_renewal_attempts SET review_claim_token=NULL,review_claimed_at=NULL WHERE review_claim_token='ffffffffffffffffffffffffffffffff'");
 $before=financialState();$events=[];$result=deliver($domain);$state=alertState($domain);
 yes(array_column($events,'to')===[9001,9003]&&$state['review_notified_at']!==null&&financialState()===$before&&($domain!=='gateway'||$result['notified']===1),
  $domain.' next ordinary call acknowledges fresh evidence generation without financial effects');
}
$writer->close();
echo json_encode(['complete'=>true,'cases'=>count($checks),'checks'=>$checks,'actual_domain_callers'=>true,'shared_ledger_owned_mariadb'=>true,'complete_eligible_fanout_ack'=>true,'failed_or_deferred_manager_retryable'=>true,'next_ordinary_delivery_no_financial_replay'=>true,'off_revoked_complete_exclusion'=>true,'evidence_generation_cas_preserved'=>true,'private_channel_policy_preserved'=>true,'nonempty_fanout_required'=>true,'strict_transport_success_required'=>true,'network_calls'=>0],JSON_THROW_ON_ERROR);
'''

def main():
    p=argparse.ArgumentParser();p.add_argument('--domain',choices=['renewal','gateway','both'],default='both');p.add_argument('--source-ref');p.add_argument('--report',type=Path);a=p.parse_args()
    if a.source_ref and (len(a.source_ref)!=40 or any(c not in '0123456789abcdef' for c in a.source_ref)):raise ValueError('exact source SHA required')
    with owned.owned_database() as (w,env):
        app=w/'app';app.mkdir();shutil.copytree(ROOT/'src',app/'src');shutil.copytree(ROOT/'messages',app/'messages')
        shutil.copy2(ROOT/'config.php',app/'config.php')
        if a.source_ref:
            for name in ['src/Finance/AutomaticRenewal.php','src/Finance/GatewayReturn.php']:
                (app/name).write_bytes(subprocess.check_output(['git','show',a.source_ref+':'+name],cwd=ROOT))
        client=['mariadb','--no-defaults','--protocol=SOCKET','--socket='+str(w/'mysql.sock'),'-uroot',owned.NAMES[1]]
        for name in ['003_payment_attempts.sql','004_automatic_renewal_attempts.sql']:
            subprocess.run(client,input=(ROOT/'migrations/post-v9'/name).read_bytes(),env=env,capture_output=True,check=True,timeout=15)
        driver=w/'financial-alerts.php';driver.write_text(notification_fixture.with_router(PHP))
        domains='renewal,gateway' if a.domain=='both' else a.domain
        result=subprocess.run(['php','-d','allow_url_fopen=0','-d','disable_functions=curl_init,curl_exec',str(driver),str(app),str(w/'mysql.sock'),owned.NAMES[1],domains],env=env,capture_output=True,text=True,timeout=30)
        if result.returncode or result.stderr:raise RuntimeError(result.stdout[-1000:]+'\n'+result.stderr[-4000:])
        report=json.loads(result.stdout);report['source_ref']=a.source_ref or 'current-source';report['baseline_source']=BASE
    if a.report:a.report.write_text(json.dumps(report,ensure_ascii=False,indent=2)+'\n')
    print(json.dumps({k:v for k,v in report.items() if k!='checks'}))
if __name__=='__main__':main()
