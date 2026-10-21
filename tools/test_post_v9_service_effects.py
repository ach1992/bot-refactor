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
    if ($mode==='wrong_quota') $account['data_limit']+=1073741824;
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
    if (($spec['operation']??'create')==='link') {
        $id=(int)($spec['effect']??0);
        $order=(int)($spec['order']??0);
        achbotLinkPaidAccountEffect($connection,$id,$order);
        $value=['linked'=>$id,'order'=>$order];
    } else {
        $result=achbotCreatePaidAccount($connection,$pay,$line,$p);
        $value=['success'=>$result[0]->success,'username'=>$result[1],
            'port'=>$result[2],'effect_id'=>$result[3]];
    }
    $error=null;
} catch(Throwable $e) {
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
                     'wrong_expiry','wrong_quota','wrong_username','target_change','endpoint_change'):
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
