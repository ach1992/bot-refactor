#!/usr/bin/env python3
"""Issue #26: applied-only Wallet add-on recovery on real current PHP + disposable MariaDB.

An original imported PasarGuard account retains a NULL originating order.
No second PUT/RESET, wallet debit, Telegram send, or fabricated financial row.
"""
from __future__ import annotations

import argparse
import datetime as dt
import json
import subprocess
from pathlib import Path

import test_post_v9_scoped_api_pg as api
import test_post_v9_pg_cutover_runtime as cutover

ROOT = Path(__file__).resolve().parents[1]
ORIGINAL_EXPIRE = 1893456000
USER = cutover.USER
CREATED = cutover.CREATED

PHP = r'''<?php
error_reporting(E_ALL & ~E_DEPRECATED);
set_error_handler(static function($level,$message){throw new ErrorException($message,0,$level);});
if (PHP_VERSION_ID<80500 || PHP_VERSION_ID>=80600) throw new RuntimeException('php85_required');
define('ACHBOT_PG_TEST_LOOPBACK',true);
$_SERVER['SCRIPT_FILENAME']=__FILE__;
$root=$argv[1];$spec=json_decode($argv[4],true,64,JSON_THROW_ON_ERROR);
require_once $root.'/src/Purchase/Core.php';
require_once $root.'/src/Finance/WalletEditRecovery.php';
$db=new mysqli('localhost','root','',$argv[3],0,$argv[2]);
$db->set_charset('utf8mb4');
try {
    $payId=(int)$spec['pay_id'];
    if ($spec['op']==='seed') {
        achbotPgPinPayment($db,$payId);
        $ctx=achbotPgOrderContext($db,103);
        $read=achbotPgRead($ctx['panel'],$ctx['identity']);
        if (($read['success']??false)!==true) throw new RuntimeException('seed_native_unavailable');
        $before=$read['user'];$field=($spec['kind']??'day')==='volume'?'plus_volume':'plus_day';
        $params=['pay_id'=>$payId,'remark'=>'historical_fixture',$field=>($field==='plus_day'?10:4)];
        $absolute=achbotPgEditChanges($before,$params);
        $units=achbotPgEditInputUnits($params);
        $intent=achbotPgBeginMutation($db,$ctx,'pay:'.$payId,'edit',
            ['phase'=>'claimed','requested_units'=>$units]);
        achbotPgPersistMutationIntent($db,$ctx,$intent,[
            'requested_units'=>$units,
            'order_expire_before'=>(int)$ctx['order']['expire_date'],
            'absolute'=>$absolute,'reset_usage'=>false,
            'before'=>array_intersect_key($before,array_flip([
                'data_limit','expire','status','group_ids','used_traffic']))]);
        $result=['absolute'=>$absolute,'baseline'=>$before['expire']];
    } elseif ($spec['op']==='resume') {
        $result=achbotWalletPgResumeAddon($db,$payId,
            (int)($spec['actor']??9001),(int)($spec['primary']??9001));
    } elseif ($spec['op']==='original') {
        $e=achbotWalletPgAddonOriginal($db,$payId);
        $result=['kind'=>$e['kind'],'units'=>$e['units'],
            'state'=>$e['account']['state'],'order_id'=>$e['account']['order_id']];
    } else throw new RuntimeException('unexpected_fixture_operation');
    echo json_encode(['ok'=>true,'result'=>$result],JSON_THROW_ON_ERROR);
} catch(Throwable $error) {
    echo json_encode(['ok'=>false,'error'=>$error->getMessage()],JSON_THROW_ON_ERROR);
}
'''


def case(*, kind: str = 'day', finalization_failure: bool = False) -> list[str]:
    checks: list[str] = []
    with api.sandbox() as (sql, run, provider, _bank, _epoch):
        effect_id = int(cutover.fixture_effect(sql))
        provider.user.update({
            'id': cutover.REMOTE_ID, 'username': USER, 'created_at': CREATED,
            'note': 'original-existing-account', 'data_limit': 123400000,
            'used_traffic': 20123,
            'expire': dt.datetime.fromtimestamp(ORIGINAL_EXPIRE,dt.timezone.utc).isoformat().replace('+00:00','Z'),
            'group_ids': [2,5], 'status': 'active',
        })
        sql("INSERT INTO orders_list (id,userid,token,transid,fileid,server_id,inbound_id,"
            "remark,uuid,expire_date,link,amount,status,date,autoRenew,"
            "provider_effect_id,provider_remote_user_id,provider_remote_created_at) VALUES "
            "(103,1001,'wallet-existing-ref','',7,2,0,'historical_fixture',"
            "'unchanged-original-uuid',"+str(ORIGINAL_EXPIRE)+",'[]',1200,1,'fixture','off',"
            f"{effect_id},72,{api.quote_sql(CREATED)})")
        origin = {'version': 1, 'kind': 'external_import', 'order_id': 103,
                  'owner_userid': 1001, 'actor_userid': 1001,
                  'provider': 'pasarguard', 'server_id': 2,
                  'remark': USER, 'proof_sha256': 'b' * 64,
                  'native_identity': {
                      'remote_user_id': 72, 'remote_created_at': CREATED,
                      'remote_username': USER},
                  'capacity_reserved': False, 'provider_account_id': effect_id}
        original_origin=json.dumps(origin,separators=(',',':'))
        sql("INSERT INTO setting(type,value) VALUES "
            "('ACHBOT_SERVICE_ORIGIN:103',"+api.quote_sql(original_origin)+")")
        sql('UPDATE users SET wallet=900000 WHERE userid=1001')
        sql("INSERT INTO pays(hash_id,user_id,type,plan_id,server_id,order_id,"
            "volume,day,price,request_date,state) VALUES "
            f"('wallet-original-103',1001,'INCREASE_{kind.upper()}_103_1',7,2,103,"
            +('0,10' if kind=='day' else '4,0')+
            ",100000,UNIX_TIMESTAMP(),'processing_wallet')")
        pay_id=int(sql("SELECT id FROM pays WHERE hash_id='wallet-original-103'"))
        driver=run.fixture_work/'wallet-edit-recovery.php'
        driver.write_text(PHP)

        def call(op:str,**args):
            proc=subprocess.run([
                'php','-n','-d','extension=mysqlnd','-d','extension=mysqli',
                '-d','extension=curl','-d','log_errors=1','-d','error_log=/dev/stderr',
                str(driver),str(ROOT),str(run.fixture_socket),api.DB,
                json.dumps({'op':op,'kind':kind,'pay_id':pay_id,**args}),
            ],cwd=run.fixture_work,env=run.fixture_env,capture_output=True,
                text=True,timeout=40)
            if proc.returncode or not proc.stdout:
                raise AssertionError(('fixture_php_failed',op,proc.returncode,
                                      proc.stdout[-900:],proc.stderr[-1200:]))
            if 'fixture-private-key' in proc.stderr or 'SENSITIVE' in proc.stderr:
                raise AssertionError('provider credential leaked')
            return json.loads(proc.stdout)

        saved=call('seed')
        assert saved['ok'],saved
        target=saved['result']['absolute']
        assert saved['result']['baseline']==ORIGINAL_EXPIRE
        if kind=='day':
            assert target['expire']==ORIGINAL_EXPIRE+864000,target
        else:
            assert target['expire']==ORIGINAL_EXPIRE,target
            assert target['data_limit']==123400000+4*1073741824,target
        assert call('original')['result']=={
            'kind':kind,'units':10 if kind=='day' else 4,
            'state':'mutating','order_id':None}
        assert sql('SELECT provider_state FROM service_effects WHERE id='+str(effect_id))=='mutating'
        assert sql('SELECT order_id IS NULL FROM service_effects WHERE id='+str(effect_id))=='1'
        checks.append('original_migration_null_order_and_claim_intent_preserved')

        denied=call('resume',actor=9002)
        assert denied['result']['status']=='review',denied
        assert provider.write_count==0
        checks.append('nonstaff_cannot_resume_original_wallet_claim')

        baseline=call('resume')
        assert baseline['result']['status']=='review',baseline
        assert provider.write_count==0
        assert sql('SELECT provider_state FROM service_effects WHERE id='+str(effect_id))=='mutating'
        checks.append('unapplied_baseline_never_triggers_second_native_put')

        provider.user['data_limit']+=27
        drift=call('resume')
        assert drift['result']['status']=='review',drift
        provider.user['data_limit']-=27
        checks.append('independent_native_entitlement_drift_remains_reviewable')

        wrong=dict(origin,provider='marzban')
        sql('UPDATE setting SET value='+api.quote_sql(json.dumps(wrong))+
            " WHERE type='ACHBOT_SERVICE_ORIGIN:103'")
        changed=call('resume')
        assert changed['result']['status']=='review',changed
        sql('UPDATE setting SET value='+api.quote_sql(original_origin)+
            " WHERE type='ACHBOT_SERVICE_ORIGIN:103'")
        checks.append('invalid_import_origin_never_authorizes_recovery')

        if kind=='day':
            provider.user['expire']=dt.datetime.fromtimestamp(
                target['expire'],dt.timezone.utc).isoformat().replace('+00:00','Z')
        else:
            provider.user['data_limit']=target['data_limit']
        if finalization_failure:
            sql("CREATE TRIGGER wallet_resume_local_audit_fail BEFORE INSERT ON increase_order "
                "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture_audit_failure'")
            failed=call('resume')
            assert failed['result']['status']=='review',failed
            assert sql('SELECT provider_state FROM service_effects WHERE id='+str(effect_id))=='linked'
            assert sql('SELECT state FROM pays WHERE id='+str(pay_id))=='processing_wallet'
            assert sql('SELECT expire_date FROM orders_list WHERE id=103')==str(ORIGINAL_EXPIRE)
            assert sql('SELECT COUNT(*) FROM increase_order')=='0'
            assert sql('SELECT wallet FROM users WHERE userid=1001')=='900000'
            assert provider.write_count==0
            checks.append('local_audit_failure_keeps_wallet_claim_without_mutation_or_double_debit')
            sql('DROP TRIGGER wallet_resume_local_audit_fail')
        result=call('resume')
        assert result['ok'] and result['result']['status']=='settled_delivery_unverified',result
        assert sql('SELECT state FROM pays WHERE id='+str(pay_id))=='paid_with_wallet'
        assert sql('SELECT expire_date FROM orders_list WHERE id=103')==str(target['expire'])
        col='day' if kind=='day' else 'volume'
        units=10 if kind=='day' else 4
        assert sql(f'SELECT COUNT(*) FROM increase_order WHERE userid=1001 AND {col}={units} AND amount=100000')=='1'
        assert sql('SELECT wallet FROM users WHERE userid=1001')=='900000'
        assert sql('SELECT provider_state FROM service_effects WHERE id='+str(effect_id))=='linked'
        assert sql('SELECT order_id IS NULL FROM service_effects WHERE id='+str(effect_id))=='1'
        assert sql("SELECT source_kind FROM service_effects WHERE id="+str(effect_id))=='legacy_migration'
        assert provider.write_count==0
        checks.append('applied_native_once_local_payment_order_and_audit_atomic')

        replay=call('resume')
        assert replay['result']['status']=='already_paid_state',replay
        assert sql('SELECT COUNT(*) FROM increase_order')=='1'
        assert provider.write_count==0
        assert sql('SELECT COUNT(*) FROM payment_delivery_intents')=='0'
        checks.append('repeat_resume_no_native_wallet_audit_or_telegram_replay')
    return checks


def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('--run-disposable',action='store_true',required=True)
    parser.add_argument('--report',type=Path)
    args=parser.parse_args()
    checks=case()+case(kind='volume',finalization_failure=True)
    result={'complete':True,'cases':len(checks),'checks':checks,
            'real_php_source':True,'disposable_mariadb':True,
            'local_native_only':True,'production_mutations':0}
    if args.report:
        args.report.write_text(json.dumps(result,indent=2)+'\n')
    print(json.dumps(result))

if __name__=='__main__':
    main()
