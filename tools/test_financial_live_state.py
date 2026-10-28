#!/usr/bin/env python3
"""Native two-provider renewal pricing, live add-on admission and statistics compatibility."""
from __future__ import annotations
import provider_storage_fixture as shared_storage
import argparse,copy,hashlib,json,re,subprocess,time
from datetime import datetime,timezone
from pathlib import Path
import test_access_state as owned
import test_v6_native as native
import test_v8_native as purchase
import test_v6_panels as bodies
import test_post_v9_pasarguard_client as pg
import test_post_v9_marzban_edits as mb
import test_unified_existing_subscriptions as imports
import test_unified_unlimited_renewal as renewal
ROOT=Path(__file__).resolve().parents[1]
DB=purchase.SIDE[1]
BASE='c9959811e264b5a8728f6a44376cb69c9a89b7f4'
DRIVER=renewal.PHP.replace("$botState=['renewAutoState'=>$spec['enabled']??'on'];",
    "$botState=['renewAutoState'=>$spec['enabled']??'on','agencyPlanDiscount'=>$spec['plan_discount']??'off'];")
DRIVER=DRIVER.replace(" } elseif($spec['action']==='changes'){",r""" } elseif($spec['action']==='baseline_reserve'){
  require __DIR__.'/baseline-reserve.php';$botState['agencyPlanDiscount']='on';
  $out=achbotAutoRenewReserveBaseline($connection,731,1001);
 } elseif($spec['action']==='peer_origin'){
  $connection->begin_transaction();$account=null;
  $identity=['remote_created_at'=>'2026-10-26T05:00:00.123456Z','remote_username'=>'unlimited_quota_user'];
  if($spec['provider']==='pasarguard'){
   achbotPgLoad();$account=achbotProviderAccountByOrder($connection,731,true);
   $identity['remote_user_id']=(int)$account['remote_user_id'];
  }
  if($account!==null)achbotProviderBindOrder($connection,$account,732);
  $out=achbotRecordImportedOrderOriginLocked($connection,732,1002,9001,$spec['provider'],2,
   'unlimited_quota_user',str_repeat('c',64),$identity,$account===null?null:(int)$account['id']);
  $connection->commit();
 } elseif($spec['action']==='changes'){""")
ROUTE_DRIVER=imports.PHP.replace(" $out=null;$op=$spec['op'];"," require_once $root.'/src/Finance/ReceiptHelpers.php';$out=null;$op=$spec['op'];").replace(" if($op==='external')",r""" if($op==='subject'){
  $pay=$connection->query('SELECT * FROM pays WHERE id='.(int)$spec['pay'])->fetch_assoc();
  if(!empty($spec['baseline'])){require __DIR__.'/baseline-subject.php';$out=achbotSupportsPaymentSubjectBaseline($connection,$pay,true);}
  else $out=achbotSupportsPaymentSubject($connection,$pay,true);
 } elseif($op==='wallet'){
  require_once $root.'/src/Finance/WalletSettlement.php';
  $pay=$connection->query('SELECT * FROM pays WHERE id='.(int)$spec['pay'])->fetch_assoc();
  $out=processWalletPayment($connection,$pay['hash_id'],static function(){throw new RuntimeException('unexpected_fulfillment');});
 } elseif($op==='external')""")

def main():
 parser=argparse.ArgumentParser();parser.add_argument('--run-disposable',action='store_true',required=True)
 parser.add_argument('--report',type=Path);args=parser.parse_args();checks=[]
 def yes(ok,name):
  if not ok:raise AssertionError(name)
  checks.append(name)
 for provider in ('marzban','pasarguard'):
  is_pg=provider=='pasarguard'
  fixture=pg.panel if is_pg else mb.owned_edit_provider
  with owned.owned_database()as(work,env),fixture()as(server,url):
   purchase.init(work,env,url)
   def sql(q):return native.sql(work,env,q,DB).strip()
   for name in ['001_marzban_panel_group_id.sql','003_payment_attempts.sql',
       '004_automatic_renewal_attempts.sql','006_payment_message_refs.sql',
       '007_service_effects.sql','011_provider_identity.sql','012_shared_provider_storage.sql']:
    sql((ROOT/'migrations/post-v9'/name).read_text())
   endpoint=hashlib.sha256(url.encode()).hexdigest()
   ent=json.dumps(dict(data_limit=2147483648,expire=None,group_ids=[2,5],status='active'),separators=(',',':'))
   sql("UPDATE server_config SET type='"+provider+"' WHERE id=2;"
       "UPDATE server_plans SET days=30,volume=3,price=137 WHERE id=7;"
       "INSERT INTO users(userid,name,username,refcode,wallet,date,isAdmin,first_start,step)"
       " VALUES(1002,'Peer','peer','0',1000,'1700000000',0,'sent','none');"
       "INSERT INTO orders_list(id,userid,token,transid,fileid,server_id,inbound_id,remark,uuid,protocol,"
       "expire_date,link,amount,status,date,notif,autoRenew)"
       " VALUES(731,1001,'','',7,2,0,'unlimited_quota_user','native-unused','vless',0,'[]',137,1,'1700000000',1,'on');"
       "DELETE FROM increase_plan;DELETE FROM increase_day;"
       "INSERT INTO increase_plan(id,volume,price)VALUES(91,2,31);"
       "INSERT INTO increase_day(id,volume,price)VALUES(92,3,37);"
       "INSERT INTO setting(type,value)VALUES('BOT_STATES','{\"walletState\":\"on\",\"gatewayAudience\":{\"wallet\":\"all\"}}')")
   if is_pg:
    sql("UPDATE server_info SET provider_group_ids='[5]' WHERE id=2;"+shared_storage.connection(2,1,url)+
        shared_storage.identity(731,731,1,url,73,'2026-10-26T05:00:00.123456Z','unlimited_quota_user',renewal.MARKER,ent))
    server.user=copy.deepcopy(pg.USER);server.user.update(id=73,username='unlimited_quota_user',
        note=renewal.MARKER,expire=None,group_ids=[2,5],data_limit=2147483648,used_traffic=2047483648)
    remote=server.user
   else:
    server.mode='edit-reset-verified';server.edit_account=copy.deepcopy(mb.INITIAL)
    server.edit_account.update(username='unlimited_quota_user',expire=None,data_limit=2147483648,
       used_traffic=2047483648,inbounds={'vless':['kept']},proxies={'vless':{'id':'kept'}},
       note='kept',created_at='2026-10-26T05:00:00.123456')
    server.edit_put_count=0;server.edit_reset_count=0;remote=server.edit_account
   (work/'automatic.php').write_text(DRIVER);(work/'routes.php').write_text(ROUTE_DRIVER)
   facade=bodies.function_bodies((ROOT/'config.php').read_bytes(),[
      'buildSubscriptionPresentation','buildConfigMessage','setUser','sumerize',
      'getOrderDetailKeys','getUserOrderDetailKeys','getMarzbanToken','RandomString'])
   (work/'facades.php').write_bytes(facade.replace(b'__DIR__',repr(str(ROOT)).encode()))
   old=subprocess.check_output(['git','show',BASE+':src/Finance/AutomaticRenewal.php'],cwd=ROOT)
   begin=old.index(b'function achbotAutoRenewReserve(');end=old.index(b'\n}\n',begin)+3
   part=b'<?php\n'+old[begin:end]
   (work/'baseline-reserve.php').write_bytes(part.replace(b'achbotAutoRenewReserve(',b'achbotAutoRenewReserveBaseline('))
   oldcore=subprocess.check_output(['git','show',BASE+':src/Purchase/Core.php'],cwd=ROOT)
   begin=oldcore.index(b'    function achbotSupportsPaymentSubject(');end=oldcore.index(b'\n    }\n}',begin)+6
   (work/'baseline-subject.php').write_bytes(b'<?php\n'+oldcore[begin:end].replace(b'achbotSupportsPaymentSubject(',b'achbotSupportsPaymentSubjectBaseline('))
   sequence=50
   def call(action,route=False,**spec):
    nonlocal sequence
    sequence+=1;payload=dict(spec,source=sequence)
    payload['op' if route else 'action']=action
    p=subprocess.run(['php','-d','display_errors=0','-d','log_errors=0',
       str(work/('routes.php'if route else 'automatic.php')),str(ROOT),str(work/'mysql.sock'),DB,
       json.dumps(payload)],cwd=work,env=env,capture_output=True,text=True,timeout=30)
    if p.returncode or p.stderr:raise AssertionError((provider,action,p.returncode,p.stderr[:500],p.stdout[-500:]))
    out=json.loads(p.stdout)
    if 'fixture_error'in out:raise AssertionError((provider,action,out))
    return out
   def expiry(value):
    remote['expire']=(datetime.fromtimestamp(value,timezone.utc).isoformat().replace('+00:00','Z')
       if is_pg and value is not None else value)
   def low():
    expiry(None);remote.update(data_limit=2147483648,used_traffic=2047483648,status='active')
   def writes():
    return [r for r in server.trace if (r[0]!='GET'if is_pg else r['method']in('PUT','DELETE')or
       (r['method']=='POST'and not r['path'].endswith('/api/admin/token')))]
   def money():
    return sql("SELECT id,wallet FROM users ORDER BY id;SELECT * FROM pays ORDER BY id;"
       "SELECT * FROM automatic_renewal_attempts ORDER BY id;SELECT * FROM increase_order ORDER BY id")
   discount={'normal':10,'plans':{'7':33},'servers':{'2':25}}
   sql("UPDATE users SET wallet=92,is_agent=1,discount_percent='"+json.dumps(discount)+"' WHERE userid=1001")
   out=call('baseline_reserve')
   yes(out['status']=='insufficient_funds'and sql("SELECT COUNT(*) FROM pays")=='0',
       provider+'_archived_raw_price_RED_rejects_affordable_agent_renewal')
   sql("UPDATE orders_list SET autoRenew='on' WHERE id=731")
   call('sweep',plan_discount='on')
   yes(sql("SELECT wallet FROM users WHERE userid=1001")=='0'
       and sql("SELECT price,state FROM pays")=='92\tpaid_with_wallet'
       and sql("SELECT price_toman,stage FROM automatic_renewal_attempts")=='92\tdone'
       and sql("SELECT amount FROM increase_order")=='92',
       provider+'_actual_native_agent_plan_discount_pinned_debited_and_finalized')
   for n,(agent,discounts,mode,charged,wallet)in enumerate([
       (1,{'normal':10},'on',124,1000),
       (1,discount,'off',103,1000),
       (0,discount,'on',137,1000),
       (1,{'normal':100},'off',0,-27)],1):
    low();sql("UPDATE users SET wallet="+str(wallet)+",is_agent="+str(agent)+",discount_percent='"+
       json.dumps(discounts)+"' WHERE userid=1001;UPDATE orders_list SET notif=1,autoRenew='on',expire_date="+str(1700000000+n)+" WHERE id=731")
    before=int(sql("SELECT COUNT(*) FROM pays"));call('sweep',plan_discount=mode)
    yes(int(sql("SELECT wallet FROM users WHERE userid=1001"))==wallet-charged and
        sql("SELECT price,state FROM pays ORDER BY id DESC LIMIT 1")==str(charged)+'\tpaid_with_wallet'and
        int(sql("SELECT COUNT(*) FROM pays"))==before+1,
        provider+'_native_discount_case_'+str(n)+'_exact_net_price_and_debt_preservation')
   low();sql("UPDATE users SET wallet=102,is_agent=1,discount_percent='"+json.dumps(discount)+
       "' WHERE userid=1001;UPDATE orders_list SET notif=1,autoRenew='on',expire_date=1700000010 WHERE id=731")
   before=money();start=len(writes());call('sweep',plan_discount='off')
   yes(money()==before and len(writes())==start and sql("SELECT autoRenew FROM orders_list WHERE id=731")=='off',
       provider+'_discounted_insufficient_balance_no_claim_or_native_write')
   low();sql("UPDATE users SET wallet=1000,is_agent=1,discount_percent='{\"normal\":101}' WHERE userid=1001;"
       "UPDATE orders_list SET autoRenew='on' WHERE id=731")
   before=money();start=len(writes());call('sweep')
   yes(money()==before and len(writes())==start,provider+'_invalid_discount_no_overcharge_or_native_write')
   # A second customer's ordinary imported reference: no exclusive-owner setup.
   sql("UPDATE users SET is_agent=0 WHERE userid=1001;"
       "INSERT INTO orders_list(id,userid,token,transid,fileid,server_id,inbound_id,remark,uuid,protocol,"
       "expire_date,link,amount,status,date,notif,autoRenew)"
       " VALUES(732,1002,'','',0,2,0,'unlimited_quota_user','native-unused','vless',1700000099,'[]',0,1,'1700000000',1,'off')")
   call('peer_origin',provider=provider)
   sql("UPDATE orders_list SET fileid=7,autoRenew='on' WHERE id=732;"
       "UPDATE orders_list SET expire_date=1700000088,notif=1 WHERE id=731")
   low();peer_before=sql("SELECT * FROM orders_list WHERE id=732");wallet_before=sql("SELECT wallet FROM users WHERE userid=1002")
   before=int(sql("SELECT COUNT(*) FROM pays"));call('sweep')
   yes(sql("SELECT * FROM orders_list WHERE id=732")==peer_before and
       sql("SELECT wallet FROM users WHERE userid=1002")==wallet_before and
       int(sql("SELECT COUNT(*) FROM pays"))==before+1,
       provider+'_shared_native_renewal_changes_only_paying_order_and_one_wallet')
   before=money();call('sweep')
   yes(money()==before and sql("SELECT * FROM orders_list WHERE id=732")==peer_before,
       provider+'_fresh_native_state_blocks_stale_peer_second_charge_without_peer_writes')
   # Fresh native eligibility must win over either stale local date direction.
   sql("UPDATE orders_list SET expire_date=1 WHERE id=731;UPDATE users SET is_agent=0 WHERE userid=1001")
   expiry(int(time.time())+86400);remote.update(data_limit=2147483648,used_traffic=0,status='active')
   for data in ['increaseAVolume731','increaseVolumePlan731_91','increaseADay731','selectPlanDayIncrease731_92']:
    result=call('extensions',route=True,data=data)
    yes(any(e['kind']in('send','edit')for e in result['events'])and
        not any(e['kind']=='alert'for e in result['events']),
        provider+'_externally_renewed_local_expired_'+data.split('731')[0]+'_allowed')
   last=int(sql("SELECT id FROM pays WHERE type='INCREASE_VOLUME_731_91' ORDER BY id DESC LIMIT 1"))
   yes(call('subject',route=True,pay=last)['out']is True,
       provider+'_native_valid_volume_quote_passes_common_payment_admission')
   # Native expiry changed after the quote, while local date now says future.
   sql("UPDATE orders_list SET expire_date=2000000000 WHERE id=731");expiry(int(time.time())-1)
   before=money()
   yes(call('subject',route=True,pay=last,baseline=True)['out']is True and money()==before,
       provider+'_archived_payment_admission_RED_accepts_native_expired_volume_quote')
   yes(call('subject',route=True,pay=last)['out']is False and money()==before,
       provider+'_stale_payable_volume_quote_denied_before_money_on_native_expiry')
   result=call('wallet',route=True,pay=last)
   yes(result['out']['status']=='unsupported_panel'and money()==before,
       provider+'_actual_wallet_controller_stops_expired_native_quote_before_debit_or_fulfillment')
   for data in ['increaseAVolume731','increaseVolumePlan731_91']:
    before=money();result=call('extensions',route=True,data=data)
    yes(any(e['kind']=='alert'for e in result['events'])and money()==before,
        provider+'_native_expired_local_future_'+data.split('731')[0]+'_denied')
   expiry(None)
   for data in ['increaseADay731','selectPlanDayIncrease731_92','increaseAVolume731','increaseVolumePlan731_91']:
    before=money();result=call('extensions',route=True,data=data)
    yes(any(e['kind']=='alert'for e in result['events'])and money()==before,
        provider+'_known_unlimited_time_'+data.split('731')[0]+'_not_finite_addon')
   expiry(int(time.time())+86400);remote.update(data_limit=0,used_traffic=0)
   before=money();result=call('extensions',route=True,data='increaseVolumePlan731_91')
   yes(any(e['kind']=='alert'for e in result['events'])and money()==before,
       provider+'_known_unlimited_quota_not_volume_addon')
   expiry(int(time.time())+86400);remote.update(data_limit=2147483648,used_traffic=0)
   for dimension in ['expire','data_limit']:
    old_value=remote[dimension];remote[dimension]='malformed-native-value'
    for data in ['increaseAVolume731','increaseVolumePlan731_91','increaseADay731','selectPlanDayIncrease731_92']:
     before=money();result=call('extensions',route=True,data=data)
     yes(any(e['kind']=='alert'for e in result['events'])and money()==before,
         provider+'_unknown_native_'+dimension+'_'+data.split('731')[0]+'_no_local_date_fallback')
    remote[dimension]=old_value
   # Query the actual statistics SQL, preserving its distinct-user definition.
   query=re.search(r'\$unfinishedPurchases = fetchData\("([^"]+)"\)',(ROOT/'config.php').read_text()).group(1)
   for uid,state in [(2001,'expired_pending'),(2002,'expired'),(2003,'processing_wallet'),
        (2004,'processing'),(2005,'paid'),(2006,'paid_with_wallet')]:
    sql("INSERT INTO pays(hash_id,user_id,type,price,state,request_date)VALUES('stats-"+str(uid)+"',"+
        str(uid)+",'BUY_SUB',100,'"+state+"',1700000000)")
   sql("INSERT INTO pays(hash_id,user_id,type,price,state,request_date)VALUES"
       "('stats-old-final',2001,'INCREASE_WALLET',100,'paid',1700000000),"
       "('stats-new-repeat',2002,'BUY_SUB',100,'expired',1700000000)")
   yes(sql(query)=='1',provider+'_statistics_old_new_expiry_distinct_users_and_final_payment_exclusion')
   oldconfig=subprocess.check_output(['git','show',BASE+':config.php'],cwd=ROOT).decode()
   oldquery=re.search(r'\$unfinishedPurchases = fetchData\("([^"]+)"\)',oldconfig).group(1)
   yes(sql(oldquery)=='0',provider+'_archived_statistics_RED_misses_current_expired_state')
   yes(sql("SELECT SUM(price) FROM pays WHERE user_id=2001 AND state IN('paid','approved','auto_cart_approved')")=='100',
       provider+'_receipt_definition_preserved_without_wallet_spend_duplication')
 report=dict(complete=True,cases=len(checks),checks=checks,native_two_provider=True,
    agent_discount=True,own_order_only=True,fresh_addon_eligibility=True,statistics_compatibility=True,
    external_requests=0,production_mutations=0)
 if args.report:args.report.write_text(json.dumps(report,indent=2)+'\n')
 print(json.dumps({k:v for k,v in report.items()if k!='checks'}))
if __name__=='__main__':main()
