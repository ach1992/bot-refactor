#!/usr/bin/env python3
"""Issue #26: source-native per-account effect identity on disposable MariaDB.

NO remote server, customer, gateway, Telegram, or installed SQL is touched.
This tests the real PHP helper with local provider spies and real DB keys.
"""
from __future__ import annotations
import argparse
import json
import subprocess
import time
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path

import test_access_state as owned
import test_v6_native as native
import test_v8_native as purchase

ROOT=Path(__file__).resolve().parents[1]
DB=purchase.SIDE[1]

PHP=r"""<?php
error_reporting(E_ALL);
set_error_handler(static function($n,$text){
    if ($n===E_DEPRECATED) return true;
    throw new ErrorException($text,0,$n);
});
$_SERVER['SCRIPT_FILENAME']=__FILE__;
$root=$argv[1];$socket=$argv[2];$database=$argv[3];
$spec=json_decode($argv[4],true,512,JSON_THROW_ON_ERROR);
$connection=new mysqli('localhost','root','',$database,0,$socket);
$connection->set_charset('utf8mb4');
$GLOBALS['events']=[];
function createServerAccount(...$args) {
    $mode=$GLOBALS['spec']['mode']??'ok';
    $remark=(string)$args[4];
    $GLOBALS['events'][]=['event'=>'POST','username'=>$remark];
    $GLOBALS['remote']=[
        'username'=>$remark,
        'note'=>'achbot:v1:'.(string)$args[14],
        'expire'=>(int)$args[15],
        'data_limit'=>(int)$args[5]*1073741824
    ];
    if ($mode==='create_failure') return [(object)['success'=>false],$remark,12345];
    if ($mode==='post_lost' || $mode==='crash_before_ack')
        throw new RuntimeException('fixture_remote_post_response_lost');
    if ($mode==='target_change') {
        global $connection;
        $connection->query('UPDATE server_info SET panel_group_id=99 WHERE id=2');
    }
    if ($mode==='endpoint_change') {
        global $connection;
        $connection->query("UPDATE server_config
            SET panel_url='http://127.0.0.1:9/changed' WHERE id=2");
    }
    return [(object)['success'=>true],$remark,12345];
}
function getMarzbanUser($serverId,$remark,$token=null,$strict=false) {
    if ($strict!==true) throw new RuntimeException('effect_readback_must_be_strict');
    $GLOBALS['events'][]=['event'=>'GET','username'=>$remark];
    $mode=$GLOBALS['spec']['mode']??'ok';
    if ($mode==='get_missing' || $mode==='get_unavailable') return null;
    $account=$GLOBALS['remote']??null;
    if (!$account) return null;
    if ($mode==='other_note') $account['note']='someone_else';
    if ($mode==='wrong_expiry') $account['expire']+=1800;
    if ($mode==='wrong_expiry_fraction') $account['expire']+=0.5;
    if ($mode==='wrong_quota') $account['data_limit']+=1073741824;
    if ($mode==='wrong_quota_fraction') $account['data_limit']+=0.5;
    if ($mode==='wrong_username') $account['username']='stranger';
    // Model simultaneous unrelated create/delete with unchanged total count.
    if ($mode==='unrelated_churn') $GLOBALS['events'][]=[
       'event'=>'unrelated_delete_and_create','before'=>500,'after'=>500];
    return (object)$account;
}
require $root.'/src/Finance/ServiceEffects.php';
$GLOBALS['spec']=$spec;
$pay=(int)($spec['pay']??0);$line=(int)($spec['line']??1);
$name=(string)($spec['username']??('source_fixture_'.$pay.'_'.$line));
$p=[
    'user_id'=>(int)($spec['user']??1001),
    'server_type'=>'marzban','server_id'=>(int)($spec['target']??2),
    'inbound_id'=>0,'plan_id'=>(int)($spec['plan']??7),
    'remark'=>$name,'volume'=>10,'days'=>30,'uniqid'=>'synthetic',
    'protocol'=>'vless','port'=>12345,'expire_microdate'=>0,
    'net_type'=>'normal','rahgozar'=>0,'limitip'=>0
];
try {
    if (($spec['operation']??'')==='nonpaid_create') {
        $kind=(string)($spec['kind']??'free_trial');
        $source=(string)($spec['source']??'trial:1001');
        $actor=(int)($spec['actor']??1001);
        $p['is_primary_admin']=!empty($spec['primary_admin']);
        $result=achbotCreateNonPaymentAccount($connection,$kind,$source,
            $actor,$line,(int)($spec['count']??1),$p);
        $value=['success'=>$result[0]->success,'username'=>$result[1],
            'port'=>$result[2],'effect_id'=>$result[3]];
    } elseif (($spec['operation']??'')==='nonpaid_link') {
        achbotLinkNonPaymentAccountEffect($connection,
            (int)($spec['effect']??0),(int)($spec['order']??0));
        $value=['linked'=>(int)$spec['effect'],'order'=>(int)$spec['order']];
    } elseif (($spec['operation']??'create')==='link') {
        $id=(int)($spec['effect']??0);
        $order=(int)($spec['order']??0);
        achbotLinkPaidAccountEffect($connection,$id,$order);
        $value=['linked'=>$id,'order'=>$order];
    } elseif (($spec['operation']??'create')==='link_transactional') {
        $id=(int)($spec['effect']??0);
        if (!$connection->begin_transaction()) throw new RuntimeException('fixture_link_begin_failed');
        $ok=$connection->query("INSERT INTO orders_list
            (userid,token,transid,fileid,server_id,inbound_id,remark,uuid,protocol,
             expire_date,link,amount,status,date,notif,rahgozar,agent_bought)
             SELECT p.user_id,'token','',e.plan_id,e.target_server_id,0,
                    e.remote_username,'fixture-uuid','vless',2000000000,'[]',
                    100,1,'fixture',0,0,0
             FROM service_effects e JOIN pays p ON p.id=e.pay_row_id
             WHERE e.id=".$id);
        $newOrder=(int)$connection->insert_id;
        if (!$ok || $newOrder<=0) throw new RuntimeException('fixture_link_order_failed');
        achbotLinkPaidAccountEffect($connection,$id,$newOrder);
        if (!$connection->commit()) throw new RuntimeException('fixture_link_commit_failed');
        $value=['linked'=>$id,'order'=>$newOrder];
    } else {
        $result=achbotCreatePaidAccount($connection,$pay,$line,$p);
        $value=['success'=>$result[0]->success,'username'=>$result[1],
            'port'=>$result[2],'effect_id'=>$result[3]];
    }
    $error=null;
} catch(Throwable $e) {
    if (($spec['operation']??'')==='link_transactional') {
        try {$connection->rollback();} catch(Throwable $ignored) {}
    }
    $value=null;
    $error=$e->getMessage();
    // Exception class is diagnostic metadata; never forward raw provider data.
}
echo json_encode(['result'=>$value,'error'=>$error,'events'=>$GLOBALS['events']],
                 JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
"""

def query(work,env,sql,db=DB):
    return native.sql(work,env,sql,db).strip()

def run_php(work,env,driver,**spec):
    db=spec.pop('db',DB)
    cmd=['php','-n','-d','extension=mysqlnd','-d','extension=mysqli',
         '-d','allow_url_fopen=0','-d','display_errors=1',
         '-d','disable_functions=curl_init,curl_exec,exec,shell_exec,system,passthru,popen,proc_open,mail',
         str(driver),str(ROOT),str(work/'mysql.sock'),db,json.dumps(spec)]
    p=subprocess.run(cmd,cwd=work,env=env,capture_output=True,text=True,timeout=20)
    if p.returncode or p.stderr:
        raise RuntimeError('local PHP helper failed '+repr((p.returncode,p.stderr[-900:]))[:1100])
    return json.loads(p.stdout)

def pay(work,env,key,count=1,db=DB):
    query(work,env,
          "INSERT INTO pays (hash_id,user_id,type,plan_id,server_id,volume,day,price,request_date,state,agent_count)"
          f" VALUES ('{key}',1001,'BUY_SUB',7,2,0,0,100,{int(time.time())},'processing',{int(count)})",db)
    return int(query(work,env,f"SELECT id FROM pays WHERE hash_id='{key}'",db))

def state(work,env,pid,db=DB):
    return query(work,env,
       f"SELECT ordinal,state,remote_username,IFNULL(order_id,0) FROM service_effects WHERE pay_row_id={pid} ORDER BY ordinal",db)

def main():
    arg=argparse.ArgumentParser()
    arg.add_argument('--run-disposable',action='store_true',required=True)
    arg.add_argument('--report',type=Path)
    args=arg.parse_args()
    cases=[]
    with owned.owned_database() as (work,env):
        purchase.init(work,env,'http://127.0.0.1:9')
        # Shared reserved capacity now decreases before every paid CREATE.
        # This large fixture budget isolates identity/replay cases; dedicated
        # low-capacity races below exercise actual contention.
        query(work,env,'UPDATE server_info SET ucount=1000 WHERE id=2')
        assert len(query(work,env,'SHOW TABLES').splitlines())==23
        for db in (DB,purchase.SIDE[0]):
            query(work,env,(ROOT/'migrations/post-v9/001_marzban_panel_group_id.sql').read_text(),db)
        query(work,env,(ROOT/'migrations/post-v9/007_service_effects.sql').read_text())
        assert len(query(work,env,'SHOW TABLES').splitlines())==24
        driver=work/'effect-run.php';driver.write_text(PHP)

        # Original 23-table installation plus 001 alone cannot accidentally
        # send a paid CREATE without its separately approved 007 migration.
        absent=pay(work,env,'effectmissing',db=purchase.SIDE[0])
        no_ddl=run_php(work,env,driver,pay=absent,db=purchase.SIDE[0])
        assert no_ddl['error'] and no_ddl['events']==[],no_ddl
        cases.append('missing_007_no_remote_post')
        # An incompletely applied operator migration MUST fail before POST,
        # even though the extra short pays-row lock is also a replay guard.
        legacy=purchase.SIDE[0]
        query(work,env,(ROOT/'migrations/post-v9/007_service_effects.sql').read_text(),legacy)
        query(work,env,'ALTER TABLE service_effects ADD INDEX fallback_pay_line (pay_row_id,effect_kind,ordinal), DROP INDEX uq_effect_pay_line',legacy)
        malformed=pay(work,env,'effectbadunique',db=legacy)
        invalid=run_php(work,env,driver,pay=malformed,db=legacy)
        assert invalid['error']=='effect_unique_index_invalid' and invalid['events']==[]
        cases.append('malformed_007_unique_index_no_remote_post')
        # Optional O1: every uniquely identifying index participates in the
        # same pre-remote schema gate; removing just a different key fails shut.
        for idx,columns in [('uq_effect_operation','operation_id'),
                             ('uq_effect_order','order_id')]:
            query(work,env,f'DROP INDEX {idx} ON service_effects',legacy)
            wrong=pay(work,env,'missing_'+idx,db=legacy)
            denied=run_php(work,env,driver,pay=wrong,db=legacy)
            assert denied['error']=='effect_unique_index_invalid' and denied['events']==[],denied
            query(work,env,f'ALTER TABLE service_effects ADD UNIQUE KEY {idx} ({columns})',legacy)
            cases.append('review_O1_missing_'+idx+'_no_remote_post')

        first=pay(work,env,'effectfirst')
        result=run_php(work,env,driver,pay=first)
        assert result['error'] is None and [e['event'] for e in result['events']]==['POST','GET'],result
        row=state(work,env,first)
        assert row.startswith(f'1\tverified\tsource_fixture_{first}_1\t0'),row
        cases.append('one_reserved_unique_operation_verified_by_exact_note_username_quota_expiry')
        proof=query(work,env,f'SELECT operation_id FROM service_effects WHERE pay_row_id={first}')
        assert len(proof)==32 and set(proof)<=set('0123456789abcdef')
        repeat=run_php(work,env,driver,pay=first)
        assert repeat['error'] and repeat['events']==[] and state(work,env,first)==row,repeat
        cases.append('same_pay_line_replay_stopped_before_post')

        # The same remote username is not safe under a different paid order
        # on the same physical target. Reject in SQL BEFORE any second POST.
        collision=pay(work,env,'effectcrosspaycollision')
        dup=run_php(work,env,driver,pay=collision,username=f'source_fixture_{first}_1')
        assert dup['error'] and dup['events']==[] and state(work,env,collision)==''
        cases.append('cross_payment_same_target_username_rejected_before_post')

        # Real concurrent PHP and MariaDB transactions race against one unique
        # pay+line claim. Only one is permitted to reach fake Marzban.
        race=pay(work,env,'effectrace')
        with ThreadPoolExecutor(max_workers=2) as pool:
            pair=list(pool.map(lambda _:run_php(work,env,driver,pay=race),range(2)))
        assert sum(sum(e['event']=='POST' for e in p['events']) for p in pair)==1,pair
        assert query(work,env,f'SELECT COUNT(*) FROM service_effects WHERE pay_row_id={race}')=='1'
        cases.append('two_concurrent_requests_one_remote_post')

        for mode in ('create_failure','post_lost','crash_before_ack',
                     'get_missing','get_unavailable','other_note',
                     'wrong_expiry','wrong_quota','wrong_expiry_fraction','wrong_quota_fraction',
                     'wrong_username','target_change','endpoint_change'):
            id=pay(work,env,'effect'+mode)
            before=run_php(work,env,driver,pay=id,mode=mode)
            expected_posts=1
            assert before['error'] and [e['event'] for e in before['events']].count('POST')==expected_posts,before
            assert state(work,env,id).split('\t')[1]=='dispatching'
            retry=run_php(work,env,driver,pay=id,mode='ok')
            assert retry['error'] and retry['events']==[],(mode,retry)
            assert query(work,env,f'SELECT COUNT(*) FROM service_effects WHERE pay_row_id={id}')=='1'
            cases.append('unknown_'+mode+'_frozen_no_replay')
            if mode=='target_change':
                query(work,env,"UPDATE server_info SET panel_group_id=NULL WHERE id=2")
            if mode=='endpoint_change':
                query(work,env,"UPDATE server_config SET panel_url='http://127.0.0.1:9' WHERE id=2")

        churn=pay(work,env,'effectchurn')
        preserved=run_php(work,env,driver,pay=churn,mode='unrelated_churn')
        assert preserved['error'] is None and any(
             e['event']=='unrelated_delete_and_create' and e['before']==e['after']
             for e in preserved['events'])
        assert state(work,env,churn).split('\t')[1]=='verified'
        cases.append('same_total_after_unrelated_delete_create_never_used_as_success_signal')

        # Two line items have unrelated, durable remote identities; a partial
        # completion never opens a second dispatch of the first or second.
        multi=pay(work,env,'effectmulti',count=2)
        a=run_php(work,env,driver,pay=multi,line=1,username='paid_multi_1')
        b=run_php(work,env,driver,pay=multi,line=2,username='paid_multi_2',mode='post_lost')
        assert a['error'] is None and b['error'] and len(state(work,env,multi).splitlines())==2
        assert '1\tverified' in state(work,env,multi) and '2\tdispatching' in state(work,env,multi)
        assert run_php(work,env,driver,pay=multi,line=2,username='paid_multi_2')['events']==[]
        cases.append('two_account_partial_remote_no_bulk_replay')

        # Accurate source-to-order join: matching order must be persisted
        # before ledger state can become local_recorded; a foreign row fails.
        recorded=pay(work,env,'effectlink')
        created=run_php(work,env,driver,pay=recorded,username='account_for_order')
        assert created['error'] is None
        query(work,env,"INSERT INTO orders_list "
            "(userid,token,transid,fileid,server_id,inbound_id,remark,uuid,protocol,"
            "expire_date,link,amount,status,date,notif,rahgozar,agent_bought) "
            "VALUES (1001,'token','',7,2,0,'account_for_order','fixture-uuid','vless',"
            "2000000000,'[]',100,1,'fixture',0,0,0)")
        order=int(query(work,env,"SELECT id FROM orders_list WHERE remark='account_for_order'"))
        bad=run_php(work,env,driver,operation='link',effect=created['result']['effect_id'],order=order+999)
        assert bad['error'] and state(work,env,recorded).split('\t')[1]=='verified'
        good=run_php(work,env,driver,operation='link',effect=created['result']['effect_id'],order=order)
        assert good['error'] is None and state(work,env,recorded).endswith('\t'+str(order))
        assert state(work,env,recorded).split('\t')[1]=='local_recorded'
        assert run_php(work,env,driver,operation='link',effect=created['result']['effect_id'],
                       order=order)['error']
        cases.append('exact_local_order_fk_join_and_no_second_finalize')

        # The external effect is immutable financial history. A subsequent
        # permitted subscription deletion must NOT be blocked by its audit
        # record or silently erase the original order number.
        query(work,env,f'DELETE FROM orders_list WHERE id={order}')
        assert query(work,env,f'SELECT COUNT(*) FROM orders_list WHERE id={order}')=='0'
        assert state(work,env,recorded).endswith('\t'+str(order))
        cases.append('historical_effect_retained_after_local_subscription_deletion')

        # R2: final linkage must revalidate the same physical target even
        # AFTER a successful remote readback. New order INSERT must roll back.
        for drift,update,undo in (
            ('endpoint',"UPDATE server_config SET panel_url='http://127.0.0.1:9/drift' WHERE id=2",
                        "UPDATE server_config SET panel_url='http://127.0.0.1:9' WHERE id=2"),
            ('group',"UPDATE server_info SET panel_group_id=1234 WHERE id=2",
                     "UPDATE server_info SET panel_group_id=NULL WHERE id=2"),
            ('type',"UPDATE server_config SET type='other' WHERE id=2",
                    "UPDATE server_config SET type='marzban' WHERE id=2"),
        ):
            name='link_drift_'+drift
            pid=pay(work,env,name,count=2)
            result=run_php(work,env,driver,pay=pid,username='link_'+drift+'_first')
            second=run_php(work,env,driver,pay=pid,line=2,username='link_'+drift+'_second')
            assert result['error'] is None and second['error'] is None
            effects=[int(v) for v in query(work,env,
                f'SELECT id FROM service_effects WHERE pay_row_id={pid} ORDER BY ordinal').splitlines()]
            assert len(effects)==2
            query(work,env,update)
            for effect in effects:
                linked=run_php(work,env,driver,operation='link_transactional',effect=effect)
                assert linked['error'] and linked['events']==[],linked
            assert query(work,env,
                f'SELECT COUNT(*) FROM orders_list WHERE remark IN '
                f"('link_{drift}_first','link_{drift}_second')")=='0'
            assert all(row.split('\t')[1]=='verified' and row.endswith('\t0')
                       for row in state(work,env,pid).splitlines())
            query(work,env,undo)
            # Healthy configuration remains linkable exactly once.
            good=run_php(work,env,driver,operation='link_transactional',effect=effects[0])
            assert good['error'] is None,good
            assert state(work,env,pid).splitlines()[0].split('\t')[1]=='local_recorded'
            cases.append('review_R2_'+drift+'_drift_blocks_both_manual_multi_link_and_hosted_link')
        # Follow-on tests also preserve the successful unchanged plan flow.

        # Unsupported subject/target and invalid ordinal cannot reserve,
        # even when a forged PHP caller reaches the function directly.
        for change in ({'plan':8},{'target':1},{'line':2},{'username':'bad name'}):
            id=pay(work,env,'effectwrong'+str(len(cases)))
            wrong=run_php(work,env,driver,pay=id,**change)
            assert wrong['error'] and wrong['events']==[],wrong
            assert state(work,env,id)==''
            cases.append('wrong_identity_rejected_without_post_'+str(len(cases)))

        # One explicitly declared physical panel can have two DIFFERENT
        # logical Marzban server entries. Username uniqueness must be
        # scoped to the group identity, not accidentally to logical IDs.
        query(work,env,"INSERT INTO setting (id,type,value) VALUES "
                       "(9009,'ACHBOT_MARZBAN_PANEL_GROUP','shared-test-panel')")
        query(work,env,"UPDATE server_info SET panel_group_id=9009 WHERE id=2")
        query(work,env,"INSERT INTO server_info "
            "(id,title,ucount,remark,flag,active,state,panel_group_id) "
            "VALUES (33,'Fixture alias',9,'fx','',1,1,9009)")
        query(work,env,"INSERT INTO server_config "
            "(id,panel_url,ip,sni,header_type,request_header,response_header,"
            "security,tlsSettings,type,username,password,sub_links) "
            "SELECT 33,panel_url,ip,sni,header_type,request_header,"
            "response_header,security,tlsSettings,type,username,password,"
            "sub_links FROM server_config WHERE id=2")
        query(work,env,"INSERT INTO server_plans "
            "(id,fileid,catid,server_id,inbound_id,acount,title,protocol,days,"
            "volume,type,price,descr,pic,active,step,date,custom_sni) "
            "SELECT 33,'33',catid,33,0,acount,title,protocol,days,volume,"
            "type,price,descr,pic,active,step,date,custom_sni "
            "FROM server_plans WHERE id=7")
        group_first=pay(work,env,'effectgroupfirst')
        good=run_php(work,env,driver,pay=group_first,username='same_physical_user')
        assert good['error'] is None,good
        group_second=pay(work,env,'effectgroupsecond')
        query(work,env,f"UPDATE pays SET plan_id=33,server_id=33 "
             f"WHERE id={group_second}")
        denied=run_php(work,env,driver,pay=group_second,plan=33,target=33,
                       username='same_physical_user')
        assert denied['error'] and denied['events']==[] and state(work,env,group_second)=='',denied
        cases.append('explicit_physical_group_alias_username_collision_before_post')

        # R1: the paid server is immutable even if the mutable plan mapping
        # and the callback's currently requested destination agree elsewhere.
        old=pay(work,env,'review_original_paid_target')
        query(work,env,'UPDATE server_plans SET server_id=33 WHERE id=7')
        denied=run_php(work,env,driver,pay=old,plan=7,target=33,
                       username='changed_plan_target_account')
        assert denied['error'] and denied['events']==[] and state(work,env,old)=='',denied
        assert query(work,env,
            f"SELECT state FROM pays WHERE id={old}")=='processing'
        query(work,env,'UPDATE server_plans SET server_id=2 WHERE id=7')
        unchanged=pay(work,env,'review_unchanged_paid_target')
        valid=run_php(work,env,driver,pay=unchanged,
                      username='unchanged_plan_success')
        assert valid['error'] is None and any(x['event']=='POST' for x in valid['events'])
        cases.append('review_R1_plan_reassignment_original_paid_target_no_remote_effect')

        # Mixed paid methods share ONE real InnoDB capacity counter. An
        # existing pending/uncertain effect consumes the reserved slots until
        # a human reconciles it, not just until remote POST returns.
        query(work,env,'UPDATE server_info SET ucount=1 WHERE id=2')
        low=pay(work,env,'capacity_fullcount',count=2)
        refusal=run_php(work,env,driver,pay=low,username='unavailable_capacity')
        assert refusal['error']=='effect_capacity_insufficient_or_unconfirmed'
        assert refusal['events']==[] and state(work,env,low)==''
        assert query(work,env,'SELECT ucount FROM server_info WHERE id=2')=='1'
        cases.append('r1_full_requested_count_rejected_before_remote_post')

        a=pay(work,env,'capacity_concurrent_a')
        b=pay(work,env,'capacity_concurrent_b')
        with ThreadPoolExecutor(max_workers=2) as pool:
            pair=list(pool.map(
                lambda k:run_php(work,env,driver,pay=k,username='held_'+str(k)),
                (a,b)))
        assert sum(sum(v['event']=='POST' for v in x['events']) for x in pair)==1,pair
        assert query(work,env,'SELECT ucount FROM server_info WHERE id=2')=='0'
        assert sum(query(work,env,
            f'SELECT COALESCE(SUM(reserved_count),0) FROM service_effects WHERE pay_row_id={k}')=='1'
            for k in (a,b))==1
        cases.append('r1_two_distinct_buyers_compete_for_one_slot')

        # The POST can have happened but its response vanished. The entire
        # reservation is retained and cannot be automatically released.
        query(work,env,'UPDATE server_info SET ucount=2 WHERE id=2')
        ambiguous=pay(work,env,'capacity_unknown_two',count=2)
        uncertain=run_php(work,env,driver,pay=ambiguous,mode='post_lost')
        assert uncertain['error'] and len(uncertain['events'])==1
        assert query(work,env,'SELECT ucount FROM server_info WHERE id=2')=='0'
        assert query(work,env,f'SELECT reserved_count FROM service_effects WHERE pay_row_id={ambiguous}')=='2'
        assert run_php(work,env,driver,pay=ambiguous)['events']==[]
        cases.append('r1_unknown_remote_retains_full_reserved_count')

        # A failed SQL INSERT must roll back its preceding capacity UPDATE:
        # no external CREATE, phantom quota consumption, or replay marker.
        query(work,env,'UPDATE server_info SET ucount=1 WHERE id=2')
        faulty=pay(work,env,'capacity_sql_rollback')
        query(work,env,"CREATE TRIGGER owned_effect_insert_denied "
            "BEFORE INSERT ON service_effects FOR EACH ROW "
            "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='owned_sql_failure'")
        try:
            result=run_php(work,env,driver,pay=faulty)
        finally:
            query(work,env,'DROP TRIGGER owned_effect_insert_denied')
        assert result['error'] and result['events']==[]
        assert state(work,env,faulty)==''
        assert query(work,env,'SELECT ucount FROM server_info WHERE id=2')=='1'
        cases.append('r1_prepost_insert_sql_failure_rolls_back_capacity')

        # Terminal review/payment states cannot authorize a brand-new
        # unjournaled CREATE when 007 is first introduced on older paid data.
        query(work,env,'UPDATE server_info SET ucount=10 WHERE id=2')
        for terminal in ('approved','auto_cart_approved','paid_with_wallet'):
            legacy=pay(work,env,'already_paid_terminal_'+terminal)
            query(work,env,f"UPDATE pays SET state='{terminal}' WHERE id={legacy}")
            denied=run_php(work,env,driver,pay=legacy,
                username='terminal_forbidden_'+terminal)
            assert denied['error']=='effect_payment_identity_mismatch',denied
            assert denied['events']==[] and state(work,env,legacy)==''
            assert query(work,env,'SELECT ucount FROM server_info WHERE id=2')=='10'
        cases.append('r1_terminal_legacy_paid_states_cannot_authorize_new_post')

        query(work,env,'UPDATE server_info SET ucount=10 WHERE id=2')
        query(work,env,"UPDATE users SET freetrial=NULL WHERE userid=1001")
        trial=run_php(work,env,driver,operation='nonpaid_create',
                      kind='free_trial',source='trial:1001',actor=1001,
                      username='free_trial_source')
        assert trial['error'] is None and [e['event'] for e in trial['events']]==['POST','GET'],trial
        assert query(work,env,'SELECT freetrial FROM users WHERE userid=1001')=='reserved'
        assert query(work,env,"SELECT source_kind,source_ref,reserved_count,state "
            "FROM service_effects WHERE remote_username='free_trial_source'")==(
            'free_trial\ttrial:1001\t1\tverified')
        replay=run_php(work,env,driver,operation='nonpaid_create',
                       kind='free_trial',source='trial:1001',actor=1001,
                       username='free_trial_source')
        assert replay['error'] and replay['events']==[]
        cases.append('nonpaid_trial_user_unique_reserved_before_post')

        query(work,env,'UPDATE server_info SET ucount=10 WHERE id=2')
        for i in (1,2):
            b=run_php(work,env,driver,operation='nonpaid_create',
                kind='admin_bulk',source='bulk:9002:456123',actor=9002,
                primary_admin=True,count=2,line=i,username='bulk_account_'+str(i))
            assert b['error'] is None and len(b['events'])==2,b
        verify=query(work,env,"SELECT ordinal,reserved_count,state FROM service_effects "
            "WHERE source_kind='admin_bulk' ORDER BY ordinal")
        assert verify=='1\t2\tverified\n2\t0\tverified',verify
        repeated=run_php(work,env,driver,operation='nonpaid_create',
            kind='admin_bulk',source='bulk:9002:456123',actor=9002,
            primary_admin=True,count=2,line=1,username='bulk_account_1')
        assert repeated['error'] and repeated['events']==[]
        cases.append('nonpaid_bulk_exact_update_and_full_capacity_reservation')

        effect=int(query(work,env,"SELECT id FROM service_effects "
            "WHERE source_kind='free_trial' AND source_ref='trial:1001'"))
        query(work,env,"INSERT INTO orders_list "
            "(userid,token,transid,fileid,server_id,inbound_id,remark,uuid,protocol,"
            "expire_date,link,amount,status,date,notif,rahgozar,agent_bought) "
            "VALUES (1001,'token','',7,2,0,'free_trial_source','uuid','vless',"
            "2000000000,'[]',0,1,'fixture',0,0,0)")
        order=int(query(work,env,"SELECT id FROM orders_list WHERE remark='free_trial_source'"))
        assert run_php(work,env,driver,operation='nonpaid_link',
                       effect=effect,order=order)['error'] is None
        assert run_php(work,env,driver,operation='nonpaid_link',
                       effect=effect,order=order)['error']
        assert query(work,env,f"SELECT state FROM service_effects WHERE id={effect}")=='local_recorded'
        cases.append('nonpaid_trial_exact_order_link_once_only')

    report={'complete':True,'cases':len(cases),'scenarios':cases,
            'source_real_php':True,'owned_mariadb_only':True,
            'real_provider_requests':0,'real_financial_effects':0,
            'external_account_count_not_used':True,
            'additive_007_only':True,'installed_operator_tested':False}
    if args.report:
        args.report.write_text(json.dumps(report,indent=2)+'\n')
    print(json.dumps({k:v for k,v in report.items() if k!='scenarios'}))

if __name__=='__main__':
    main()
