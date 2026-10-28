#!/usr/bin/env python3
"""Whole-unit quote/claim guards: actual PHP routes and owned socket MariaDB."""
import argparse,json,subprocess,time
from pathlib import Path
import test_access_state as owned
import test_v6_native as native
import test_v8_native as purchase
ROOT=Path(__file__).resolve().parents[1]
DB=purchase.SIDE[1]
PHP=r'''<?php
error_reporting(E_ALL);
set_error_handler(static function($n,$m){if($n===E_DEPRECATED)return true;throw new ErrorException($m,0,$n);});
$_SERVER['SCRIPT_FILENAME']=__FILE__;
$root=$argv[1];$spec=json_decode($argv[4],true,512,JSON_THROW_ON_ERROR);
$connection=new mysqli('localhost','root','',$argv[3],0,$argv[2]);$connection->set_charset('utf8mb4');
$from_id=(int)($spec['actor']??1001);$admin=9001;$chat_id=$from_id;$message_id=702;$callbackId='fixture';
$data=$spec['data']??'';$text=$spec['text']??'';$time=time();$first_name='Fixture';$username='fixture';$agentBought=false;
$botState=['walletState'=>'on','nowPaymentOther'=>'off','zarinpal'=>'off','nextpay'=>'off','agencyPlanDiscount'=>'on'];
$buttonValues=['cancel'=>'Cancel','back_to_main'=>'Home','pay_with_wallet'=>'Wallet'];$mainValues=['reached_main_menu'=>'fixture-home'];$paymentKeys=[];$botUrl='https://bot.example.invalid/';
$update=json_decode(json_encode($spec['frame']??($data!==''?
 ['update_id'=>801,'callback_query'=>['id'=>'fixture','data'=>$data,'from'=>['id'=>$from_id],'message'=>['message_id'=>$message_id,'chat'=>['id'=>$from_id,'type'=>'private']]]]:
 ['update_id'=>801,'message'=>['message_id'=>$message_id,'text'=>$text,'from'=>['id'=>$from_id],'chat'=>['id'=>$from_id,'type'=>'private']]])));
$cancelKey='{}';$removeKeyboard='{}';$q=$connection->prepare('SELECT * FROM users WHERE userid=?');$q->bind_param('i',$from_id);$q->execute();$userInfo=$q->get_result()->fetch_assoc();$q->close();
$GLOBALS['events']=[];$GLOBALS['writes']=0;
register_shutdown_function(static function(){echo json_encode(['events'=>$GLOBALS['events'],'provider_writes'=>$GLOBALS['writes']],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);});
function sendMessage($text=null,$keys=null,...$rest){$GLOBALS['events'][]=['event'=>'SEND','text'=>$text,'keys'=>json_decode($keys??'{}',true)];return (object)['ok'=>true,'result'=>(object)['message_id'=>703,'chat'=>(object)['id'=>$GLOBALS['from_id']]]];}
function editText($id,$text,$keys=null,...$rest){$GLOBALS['events'][]=['event'=>'EDIT','text'=>$text,'keys'=>json_decode($keys??'{}',true)];return (object)['ok'=>true];}
function alert(...$args){$GLOBALS['events'][]=['event'=>'ALERT','text'=>$args[0]??''];}
function getMainKeys(){return '{}';}
function getAdminKeys(){return '{}';}
function setUser($value=null,$field='step'){global $connection,$from_id;$value=$value??'none';if($field!=='step')throw new RuntimeException('unexpected_field');$s=$connection->prepare('UPDATE users SET step=? WHERE userid=?');$s->bind_param('si',$value,$from_id);$s->execute();$s->close();}
function delMessage(...$args){}
function RandomString(){return 'units_'.bin2hex(random_bytes(8));}
function achbotSupportsPanel($type){return in_array($type,['marzban','pasarguard'],true);}
function achbotApplyServiceEdit(...$args){++$GLOBALS['writes'];throw new RuntimeException('forbidden_provider_effect');}
require_once $root.'/src/Purchase/Core.php';require_once $root.'/src/Finance/ReceiptHelpers.php';require_once $root.'/src/Finance/PaymentLifecycle.php';
if (($spec['route']??'extensions')==='claim') {
 $s=$connection->prepare('SELECT * FROM pays WHERE hash_id=?');$s->bind_param('s',$spec['hash']);$s->execute();$pay=$s->get_result()->fetch_assoc();$s->close();
 $GLOBALS['events'][]=['event'=>'CLAIM','value'=>achbotAutoBankClaim($connection,$pay)];
} elseif ($spec['route']==='effectmatch') {
 $s=$connection->prepare('SELECT id FROM pays WHERE hash_id=?');$s->bind_param('s',$spec['hash']);$s->execute();$id=(int)$s->get_result()->fetch_assoc()['id'];$s->close();
 $GLOBALS['events'][]=['event'=>'MATCH','value'=>achbotServiceEditMatchesPay($connection,$id,['plus_volume'=>$spec['units']])];
} elseif ($spec['route']==='subject') {
 $s=$connection->prepare('SELECT * FROM pays WHERE hash_id=?');$s->bind_param('s',$spec['hash']);$s->execute();$pay=$s->get_result()->fetch_assoc();$s->close();
 $GLOBALS['events'][]=['event'=>'SUBJECT','value'=>achbotSupportsPaymentSubject($connection,$pay)];
} elseif ($spec['route']==='admin') require $root.'/src/Plans/Intervals.php';
else require $root.'/src/Finance/Routes/Extensions.php';
'''
def main():
 p=argparse.ArgumentParser();p.add_argument('--run-disposable',action='store_true',required=True);p.add_argument('--report',type=Path);a=p.parse_args()
 checks=[]
 with owned.owned_database() as (w,e):
  purchase.init(w,e,'http://127.0.0.1:9')
  def sql(q):return native.sql(w,e,q,DB).strip()
  for name in ['001_marzban_panel_group_id.sql','003_payment_attempts.sql','004_automatic_renewal_attempts.sql','006_payment_message_refs.sql','007_service_effects.sql']:
   sql((ROOT/'migrations/post-v9'/name).read_text())
  future=int(time.time())+86400*30
  sql("INSERT INTO orders_list(id,userid,token,transid,fileid,server_id,inbound_id,remark,expire_date,link,amount,status,date) VALUES(41,1001,'','','7',2,0,'units-order',"+str(future)+",'[]',100,1,'fixture')")
  sql("INSERT INTO increase_plan(id,volume,price) VALUES(51,2,100),(52,0.5,100),(53,1.5,100),(54,0,100),(55,-1,100),(56,1000000000000,100)")
  sql("INSERT INTO increase_day(id,volume,price) VALUES(61,3,200)")
  driver=w/'unit-routes.php';driver.write_text(PHP)
  def call(**spec):
   r=subprocess.run(['php','-d','display_errors=0','-d','log_errors=0',str(driver),str(ROOT),str(w/'mysql.sock'),DB,json.dumps(spec)],capture_output=True,text=True,timeout=20)
   assert r.returncode==0 and not r.stderr,(spec,r.returncode,r.stderr,r.stdout)
   return json.loads(r.stdout)
  def yes(value,label):
   assert value,label;checks.append(label)
  def snapshot():
   return sql("SELECT userid,wallet,step FROM users ORDER BY userid;SELECT id,hash_id,state,payid,price,volume FROM pays ORDER BY id;SELECT id,state,operation_id FROM service_effects ORDER BY id;SELECT id,volume,price FROM increase_plan ORDER BY id;SELECT * FROM increase_order ORDER BY id")
  before=snapshot();r=call(route='extensions',data='increaseAVolume41')
  shown=json.dumps(r,ensure_ascii=False)
  yes('increaseVolumePlan41_51' in shown and all('increaseVolumePlan41_'+str(i) not in shown for i in range(52,57)) and snapshot()==before,'catalogue_hides_fractional_zero_negative_overflow_before_quote')
  for plan in range(52,57):
   before=snapshot();r=call(route='extensions',data='increaseVolumePlan41_'+str(plan))
   yes(snapshot()==before and r['provider_writes']==0,'unsupported_selection_'+str(plan)+'_creates_no_quote_claim_or_debit')
  r=call(route='extensions',data='increaseVolumePlan41_51')
  yes(sql("SELECT COUNT(*) FROM pays WHERE type='INCREASE_VOLUME_41_51'")=='1' and r['provider_writes']==0,'whole_addon_quote_remains_available')
  quoted=sql("SELECT hash_id FROM pays WHERE type='INCREASE_VOLUME_41_51' ORDER BY id DESC LIMIT 1")
  yes(sql("SELECT CONCAT(volume,':',day,':',price) FROM pays WHERE hash_id='"+quoted+"'")=='2:0:100','new_volume_draft_pins_original_whole_quantity_and_price')
  sql("UPDATE increase_plan SET volume=0.5,price=999 WHERE id=51")
  yes(call(route='subject',hash=quoted)['events'][-1]['value'] is True,'issued_volume_quote_survives_fractional_SKU_and_price_drift')
  yes(call(route='effectmatch',hash=quoted,units=2)['events'][-1]['value'] is True and call(route='effectmatch',hash=quoted,units=10)['events'][-1]['value'] is False,'final_effect_must_match_pinned_quantity_not_new_SKU')
  sql("DELETE FROM increase_plan WHERE id=51")
  yes(call(route='subject',hash=quoted)['events'][-1]['value'] is True,'issued_volume_quote_survives_SKU_deletion')
  call(route='extensions',data='selectPlanDayIncrease41_61')
  dayquote=sql("SELECT hash_id FROM pays WHERE type='INCREASE_DAY_41_61' ORDER BY id DESC LIMIT 1")
  yes(sql("SELECT CONCAT(volume,':',day,':',price) FROM pays WHERE hash_id='"+dayquote+"'")=='0:3:200','new_day_draft_pins_original_whole_quantity_and_price')
  sql("UPDATE increase_day SET volume=9,price=999 WHERE id=61")
  yes(call(route='subject',hash=dayquote)['events'][-1]['value'] is True,'issued_day_quote_survives_SKU_quantity_price_drift')

  for kind,state,actor,callback in [('wallet','pending',1001,'payIncreaseWithWallet'),('manual','have_sent',9001,'approveIncreaseVolume')]:
   key='units_'+kind
   sql("INSERT INTO pays(hash_id,user_id,type,plan_id,server_id,order_id,price,request_date,state,volume,day) VALUES('"+key+"',1001,'INCREASE_VOLUME_41_52',7,2,41,100,UNIX_TIMESTAMP(),'"+state+"',0,0)")
   before=snapshot();r=call(route='extensions',data=callback+key,actor=actor)
   yes(snapshot()==before and r['provider_writes']==0,'fractional_'+kind+'_invoice_no_claim_debit_effect_or_refund')
  for kind,state,actor,callback in [('wallet','pending',1001,'payIncreaseDayWithWallet'),('manual','have_sent',9001,'approveIncreaseDay')]:
   key='legacy_day_'+kind
   sql("INSERT INTO pays(hash_id,user_id,type,plan_id,server_id,order_id,price,request_date,state,volume,day) VALUES('"+key+"',1001,'INCREASE_DAY_41_61',7,2,41,200,UNIX_TIMESTAMP(),'"+state+"',0,0)")
   before=snapshot();result=call(route='extensions',actor=actor,data=callback+key)
   yes(snapshot()==before and result['provider_writes']==0,'legacy_zero_day_'+kind+'_invoice_no_claim_debit_or_effect')
  for state in ['pending','have_sent','processing','paid']:
   key='units_subject_'+state
   sql("INSERT INTO pays(hash_id,user_id,type,plan_id,server_id,order_id,price,request_date,state,volume,day) VALUES('"+key+"',1001,'INCREASE_VOLUME_41_53',7,2,41,100,UNIX_TIMESTAMP(),'"+state+"',0,0)")
   before=snapshot();r=call(route='subject',hash=key)
   yes(r['events'][-1]['value'] is False and snapshot()==before,'fractional_'+state+'_subject_refused_without_mutation')
  key='units_auto_fraction'
  sql("INSERT INTO pays(hash_id,user_id,type,plan_id,server_id,order_id,price,request_date,state,volume,day) VALUES('"+key+"',1001,'INCREASE_VOLUME_41_52',7,2,41,100,UNIX_TIMESTAMP(),'have_sent',0,0)")
  before=snapshot();r=call(route='claim',hash=key)
  yes(r['events'][-1]['value'] is False and snapshot()==before,'scheduled_fractional_quote_cannot_claim')
  key='units_auto_whole'
  sql("INSERT INTO pays(hash_id,user_id,type,plan_id,server_id,order_id,price,request_date,state,volume,day) VALUES('"+key+"',1001,'INCREASE_VOLUME_41_51',7,2,41,100,UNIX_TIMESTAMP(),'have_sent',2,0)")
  r=call(route='claim',hash=key)
  yes(r['events'][-1]['value'] is True and sql("SELECT state FROM pays WHERE hash_id='"+key+"'")=='processing','scheduled_whole_quote_claim_still_valid')
  # A later operator/template change is not permission to truncate a quote.
  sql("INSERT INTO increase_plan(id,volume,price) VALUES(51,0.5,999)")
  before=snapshot();r=call(route='subject',hash=key)
  yes(r['events'][-1]['value'] is True and snapshot()==before,'claimed_pinned_quote_ignores_later_fractional_SKU')
  sql("UPDATE users SET step='addNewVolumePlan' WHERE userid=9001")
  before=snapshot();r=call(route='admin',actor=9001,text='0.5-100',data='')
  yes(snapshot()==before and any('اعشاری' in str(x) for x in r['events']),'admin_fractional_create_refused_with_setup_guidance')
  sql("UPDATE users SET step='changeVolumePlanVolume52' WHERE userid=9001")
  before=snapshot();r=call(route='admin',actor=9001,text='1.5',data='')
  yes(snapshot()==before and sql('SELECT volume FROM increase_plan WHERE id=52')=='0.5','admin_fractional_edit_does_not_rewrite_existing_row')
  sql("UPDATE users SET step='changeDayPlanPrice61',temp=NULL WHERE userid=9001")
  frames=[
   ('group',{'update_id':802,'message':{'message_id':702,'text':'100','from':{'id':9001},'chat':{'id':-123,'type':'group'}}}),
   ('foreign_sender',{'update_id':802,'message':{'message_id':702,'text':'100','from':{'id':1001},'chat':{'id':9001,'type':'private'}}}),
   ('edited_message',{'update_id':802,'edited_message':{'message_id':702,'text':'100','from':{'id':9001},'chat':{'id':9001,'type':'private'}}}),
  ]
  for label,frame in frames:
   before=snapshot();out=call(route='admin',actor=9001,text='100',data='',frame=frame)
   yes(snapshot()==before and not out['events'] and out['provider_writes']==0,'admin_addon_rejects_'+label+'_frame')
 report={'complete':True,'cases':len(checks),'checks':checks,'actual_php_mysqli':True,'owned_socket_mariadb':True,'provider_requests':0,'production_mutations':0}
 if a.report:a.report.write_text(json.dumps(report,indent=2)+'\n')
 print(json.dumps(report))
if __name__=='__main__':main()
