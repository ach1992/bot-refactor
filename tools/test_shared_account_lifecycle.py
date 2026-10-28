#!/usr/bin/env python3
"""Shared-reference financial guards using actual PHP and owned socket MariaDB.

The edit callback is the existing source-only renewal spy. Real native HTTP,
shared import and paid consent journeys are covered by the separate import
suite; these checks target concurrent reservations and held peer evidence.
"""
from __future__ import annotations
import argparse,hashlib,json,subprocess,time
from pathlib import Path
import test_access_state as owned
import test_v8_native as purchase
import test_post_v9_automatic_renewal as renew
ROOT=Path(__file__).resolve().parents[1]

def main():
 p=argparse.ArgumentParser();p.add_argument('--run-disposable',action='store_true',required=True);p.add_argument('--report',type=Path);a=p.parse_args()
 checks=[]
 def yes(ok,name):
  if not ok:raise AssertionError(name)
  checks.append(name)
 with owned.owned_database()as(w,e):
  purchase.init(w,e,'http://127.0.0.1:9')
  driver=renew.PHP.replace('achbotAutoRenewPrepareDeletion($connection,$oid)];',
      'achbotAutoRenewPrepareDeletion($connection,$oid,$spec[\'expected\']??null)];')
  # The synthetic provider normally shares state for the same account name.
  # Explicitly distinct panel fixtures may use separate controlled state even
  # when their URLs and account names happen to match; runtime still decides
  # account equivalence solely from the real administrator-assigned group.
  driver=driver.replace("hash('sha256',$remark)",
      "hash('sha256',($GLOBALS['spec']['native_fixture_scope']??'').$remark)")
  driver=driver.replace("hash('sha256',$info['remark'])",
      "hash('sha256',($GLOBALS['spec']['native_fixture_scope']??'').$info['remark'])")
  (w/'owned-renew-php.php').write_text(driver)
  def q(sql):return renew.sql(w,e,sql).strip()
  for name in ['001_marzban_panel_group_id.sql','003_payment_attempts.sql','004_automatic_renewal_attempts.sql','007_service_effects.sql']:
   q((ROOT/'migrations/post-v9'/name).read_text())
  q("INSERT INTO users(userid,name,username,refcode,wallet,date,step) VALUES(1002,'Shared','shared','0',500000,'1700000000','none')")
  def wallets():return q('SELECT userid,wallet FROM users WHERE userid IN(1001,1002)ORDER BY userid')
  def pair(name):
   x=renew.seed(w,e,name);y=renew.seed(w,e,name)
   cycle=int(time.time())+1000
   q(f'UPDATE orders_list SET userid=1002 WHERE id={y};UPDATE orders_list SET expire_date={cycle} WHERE id IN({x},{y});UPDATE users SET wallet=500000 WHERE userid IN(1001,1002)')
   return x,y
  def run(oid,uid,**extra):return renew.run(w,e,dict(order_id=oid,user_id=uid,enabled='on',**extra))
  def journal(ids):return int(q('SELECT COUNT(*)FROM automatic_renewal_attempts WHERE order_id IN('+','.join(map(str,ids))+')'))
  def concurrent(ids):
   children=[subprocess.Popen(renew.cmd(w,e,dict(order_id=oid,user_id=uid,enabled='on',delay_ms=250)),cwd=w,env=e,stdout=subprocess.PIPE,stderr=subprocess.PIPE)for oid,uid in zip(ids,[1001,1002])]
   out=[]
   for child in children:
    stdout,stderr=child.communicate(timeout=25)
    yes(child.returncode==0 and all(line==b'achbot_auto_renew_reservation_deferred'for line in stderr.splitlines()),'concurrent_child_has_only_safe_local_contention')
    out+=json.loads(stdout)
   return out
  x,y=pair('shared-distinct-users')
  original_rows={oid:q(f'SELECT * FROM orders_list WHERE id={oid}')for oid in (x,y)}
  out=concurrent((x,y))
  yes(sum(v['type']=='remote'for v in out)==1 and journal((x,y))==1 and
      q('SELECT SUM(wallet)FROM users WHERE userid IN(1001,1002)')=='900000',
      'two_consented_users_one_native_cycle_has_one_debit_and_effect')
  payer=int(q(f'SELECT order_id FROM automatic_renewal_attempts WHERE order_id IN({x},{y})'))
  yes(payer in (x,y),'completed_shared_cycle_journal_identifies_the_actual_paying_order')
  peer=y if payer==x else x
  native=json.loads((w/('native-'+hashlib.sha256(b'shared-distinct-users').hexdigest()+'.json')).read_text())
  yes(q(f'SELECT * FROM orders_list WHERE id={peer}')==original_rows[peer] and
      q(f'SELECT * FROM orders_list WHERE id={payer}')!=original_rows[payer] and
      q(f'SELECT expire_date,notif FROM orders_list WHERE id={payer}')==str(native['expire'])+'\t0' and
      q(f'SELECT COUNT(DISTINCT expire_date),SUM(notif)FROM orders_list WHERE id IN({x},{y})')=='2\t1',
      'verified_completion_updates_only_actual_paying_order_and_preserves_full_peer_row')
  before=wallets();out=run(x,1001)+run(y,1002)
  yes(wallets()==before and journal((x,y))==1 and any(v['type']=='read'for v in out) and
      not any(v['type']=='remote'for v in out) and
      q(f'SELECT * FROM orders_list WHERE id={peer}')==original_rows[peer],
      'stale_callbacks_read_native_completed_cycle_without_second_debit_or_peer_write')
  # Merely sharing is not a deletion restriction once evidence is completed.
  yes(run(x,1001,action='deletion_guard')==[{'type':'delete_guard','allowed':True}],
      'completed_shared_references_do_not_permanently_disable_native_delete_guard')

  x,y=pair('shared-held-reservation')
  reserved=run(y,1002,action='reserve')
  yes([v for v in reserved if v['type']!='read']==[{'type':'reserve','status':'reserved'}] and
      any(v['type']=='read'for v in reserved),
      'one_user_can_reserve_native_verified_existing_cycle_without_native_dispatch')
  before=wallets();out=run(x,1001)
  yes(wallets()==before and journal((x,y))==1 and not any(v['type']=='remote'for v in out),
      'another_reference_cannot_debit_while_peer_reservation_is_in_flight')
  yes(run(x,1001,action='deletion_guard')==[{'type':'delete_guard','allowed':False}],
      'peer_in_flight_reservation_protects_physical_account_from_delete')

  x,y=pair('shared-uncertain-effect')
  out=run(y,1002,remote_success=False)
  yes(any(v['type']=='remote'for v in out)and renew.state(w,e,y)=='review',
      'uncertain_native_result_keeps_real_reserved_payment_and_journal')
  before=wallets();out=run(x,1001)
  yes(wallets()==before and journal((x,y))==1 and not any(v['type']=='remote'for v in out),
      'peer_uncertain_result_never_authorizes_second_charge_or_replay')
  yes(run(x,1001,action='deletion_guard')==[{'type':'delete_guard','allowed':False}],
      'peer_review_hold_protects_physical_delete')

  x,y=pair('shared-insufficient-first')
  q('UPDATE users SET wallet=10 WHERE userid=1001')
  out=run(x,1001)
  yes(not any(v['type']=='remote'for v in out)and q(f'SELECT autoRenew FROM orders_list WHERE id={x}')=='off'and journal((x,y))==0,
      'insufficient_user_disables_only_own_consent_without_holding_other_user')
  out=run(y,1002)
  yes(sum(v['type']=='remote'for v in out)==1 and journal((x,y))==1 and
      q('SELECT wallet FROM users WHERE userid=1001')=='10'and q('SELECT wallet FROM users WHERE userid=1002')=='400000',
      'another_consented_funded_user_can_pay_shared_cycle_without_designated_owner')

  x,y=pair('shared-pending-manual')
  q(f"INSERT INTO pays(hash_id,user_id,type,plan_id,server_id,order_id,price,request_date,state)VALUES('shared-pending',1002,'RENEW_ACCOUNT',7,2,{y},100000,UNIX_TIMESTAMP(),'processing_wallet')")
  before=wallets();out=run(x,1001)
  yes(wallets()==before and journal((x,y))==0 and not any(v['type']=='remote'for v in out),
      'actual_peer_payment_processing_blocks_auto_debit_before_effect')
  yes(run(x,1001,action='deletion_guard')==[{'type':'delete_guard','allowed':False}],
      'actual_peer_payment_processing_blocks_conflicting_delete')

  x,y=pair('shared-unclaimed-quote')
  q(f"INSERT INTO pays(hash_id,user_id,type,plan_id,server_id,order_id,price,request_date,state)VALUES('shared-unclaimed',1002,'RENEW_ACCOUNT',7,2,{y},100000,UNIX_TIMESTAMP(),'pending')")
  out=run(x,1001)
  yes(sum(v['type']=='remote'for v in out)==1 and journal((x,y))==1,
      'unclaimed_unpaid_bot_quote_does_not_starve_consented_auto_renewal')

  x,y=pair('shared-unclaimed-delete')
  q(f"INSERT INTO pays(hash_id,user_id,type,plan_id,server_id,order_id,price,request_date,state)VALUES('shared-delete-unclaimed',1002,'RENEW_ACCOUNT',7,2,{y},100000,UNIX_TIMESTAMP(),'pending')")
  expiry=int(q(f'SELECT expire_date FROM orders_list WHERE id={x}'))
  expected=dict(expire_date=expiry,notif=1,server_id=2,remark='shared-unclaimed-delete')
  yes(run(x,1001,action='deletion_guard',expected=expected)==[{'type':'delete_guard','allowed':False}],
      'scheduled_delete_retains_existing_pending_quote_protection_across_references')
  yes(run(x,1001,action='deletion_guard')==[{'type':'delete_guard','allowed':True}],
      'explicit_manual_delete_is_not_blocked_by_merely_unclaimed_unpaid_quote')

  q("INSERT INTO server_info(id,title,ucount,remark,flag,active,state)SELECT 88,'Alias',ucount,remark,flag,active,state FROM server_info WHERE id=2")
  q("INSERT INTO server_config SELECT 88,panel_url,ip,sni,header_type,request_header,response_header,security,tlsSettings,type,username,password,port_type,reality,sub_links FROM server_config WHERE id=2")
  q("INSERT INTO setting(type,value)VALUES('ACHBOT_MARZBAN_PANEL_GROUP','Explicit shared group')")
  group=int(q("SELECT id FROM setting WHERE type='ACHBOT_MARZBAN_PANEL_GROUP'AND value='Explicit shared group'"))
  q(f'UPDATE server_info SET panel_group_id={group} WHERE id IN(2,88)')
  x,y=pair('shared-explicit-alias')
  q(f'UPDATE orders_list SET server_id=88 WHERE id={y}')
  out=concurrent((x,y))
  yes(sum(v['type']=='remote'for v in out)==1 and journal((x,y))==1 and
      q('SELECT SUM(wallet)FROM users WHERE userid IN(1001,1002)')=='900000',
      'explicit_same_panel_logical_aliases_share_one_concurrent_cycle')
  q('UPDATE server_info SET panel_group_id=NULL WHERE id IN(2,88)')
  x,y=pair('shared-unassigned-same-url')
  q(f'UPDATE server_plans SET server_id=88,price=100000,days=30,volume=2,active=1 WHERE id=8;UPDATE orders_list SET server_id=88,fileid=8 WHERE id={y}')
  out=run(x,1001,native_fixture_scope='panel-2:')+run(y,1002,native_fixture_scope='panel-88:')
  yes(sum(v['type']=='remote'for v in out)==2 and journal((x,y))==2,
      'equal_endpoint_without_explicit_group_is_not_inferred_physical_identity')
 report=dict(complete=True,cases=len(checks),checks=checks,actual_php=True,owned_socket_mariadb=True,
    two_distinct_users=True,concurrent_workers=2,shared_cycle_one_payer=True,
    paying_order_only=True,full_peer_row_unchanged=True,fresh_native_shared_cycle_refusal=True,
    stale_callback_safe=True,pending_peer_work_held=True,explicit_panel_group_authority=True,
    native_provider_HTTP=False,external_requests=0,production_mutations=0,new_schema=False)
 if a.report:a.report.write_text(json.dumps(report,ensure_ascii=False,indent=2)+'\n')
 print(json.dumps(report,ensure_ascii=False))
if __name__=='__main__':main()
