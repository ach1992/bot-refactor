#!/usr/bin/env python3
"""AChBot wallet/manual unsupported preflight on real PHP/mysqli + MariaDB.

The seven current rejection scenarios use original-schema disposable socket
MariaDB plus001. An actual SELECT-only DB identity, complete row snapshots,
and native transaction counters guard the zero-effect boundary. Provider and
notification boundaries are failure spies; the dispatcher is shipping source.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import re
import subprocess
import time
from pathlib import Path

import test_access_state as owned
import test_v6_native as native
import test_v8_native as purchase

ROOT=Path(__file__).resolve().parents[1]
DB=purchase.SIDE[1]

PHP=r'''<?php
error_reporting(E_ALL & ~E_DEPRECATED);
set_error_handler(static function($level,$message){
    if ($level===E_DEPRECATED) return true;
    throw new ErrorException($message,0,$level);
});
$_SERVER['SCRIPT_FILENAME']=__FILE__;
$root=$argv[1];$socket=$argv[2];$dbname=$argv[3];
$spec=json_decode($argv[4],true,512,JSON_THROW_ON_ERROR);
$GLOBALS['alerts']=[];$GLOBALS['handler_calls']=0;$GLOBALS['provider_calls']=0;
$GLOBALS['state_calls']=0;$GLOBALS['notification_calls']=0;
$networkBlocked=!filter_var(ini_get('allow_url_fopen'),FILTER_VALIDATE_BOOLEAN);
foreach (['curl_init','curl_exec','fsockopen','stream_socket_client','socket_create'] as $fn)
    $networkBlocked=$networkBlocked && !function_exists($fn);
if (!$networkBlocked) throw new RuntimeException('owned_network_boundary_not_closed');
// This remains a genuine mysqli connection, statement and result. Only
// observations are added; no SQL/result dispatch or guard is substituted.
final class ObservedMysqli extends mysqli {
    public $begins=0,$rollbacks=0,$commits=0,$reads=0,$writeStatements=0;
    private function observe(string $sql): void {
        if (preg_match('/^\s*(SELECT|SHOW)\b/i',$sql)) $this->reads++;
        else $this->writeStatements++;
    }
    public function prepare(string $query): mysqli_stmt|false {
        $this->observe($query);return parent::prepare($query);
    }
    public function query(string $query,int $result_mode=MYSQLI_STORE_RESULT): mysqli_result|bool {
        $this->observe($query);return parent::query($query,$result_mode);
    }
    public function begin_transaction(int $flags=0,?string $name=null): bool {
        $this->begins++;return parent::begin_transaction($flags,$name);
    }
    public function rollback(int $flags=0,?string $name=null): bool {
        $this->rollbacks++;return parent::rollback($flags,$name);
    }
    public function commit(int $flags=0,?string $name=null): bool {
        $this->commits++;return parent::commit($flags,$name);
    }
}
function alert($message,$urgent=false){$GLOBALS['alerts'][]=(string)$message;}
function setUser(...$args){$GLOBALS['state_calls']++;throw new RuntimeException('owned_unexpected_state_handler');}
function sendMessage(...$args){$GLOBALS['notification_calls']++;throw new RuntimeException('owned_unexpected_notification');}
function ownedUnexpectedProvider(){
    $GLOBALS['provider_calls']++;throw new RuntimeException('owned_unexpected_provider');
}
function createServerAccount(...$args){return ownedUnexpectedProvider();}
function getMarzbanUser(...$args){return ownedUnexpectedProvider();}
function editMarzbanConfig(...$args){return ownedUnexpectedProvider();}
function renewConfigOnServer(...$args){return ownedUnexpectedProvider();}
$connection=new ObservedMysqli('localhost','owned_guard','',$dbname,0,$socket);
$connection->set_charset('utf8mb4');
$identity=$connection->query('SELECT CURRENT_USER() AS identity')->fetch_assoc()['identity'];
if ($identity!=='owned_guard@localhost') throw new RuntimeException('owned_reader_identity_wrong');
$admin=9001;$botState=[];
require_once $root.'/src/Purchase/Core.php';
require_once $root.'/src/Finance/ReceiptHelpers.php';
require_once $root.'/src/Finance/WalletSettlement.php';
$positive=null;$status=null;$expectedAlert=null;
if ($spec['mode']==='positive') {
    $stmt=$connection->prepare('SELECT * FROM pays WHERE hash_id=?');
    $hash=(string)$spec['hash'];$stmt->bind_param('s',$hash);$stmt->execute();
    $pay=$stmt->get_result()->fetch_assoc();$stmt->close();
    $positive=[
        'marzban'=>achbotSupportsMarzbanPlan($connection,7,0,2),
        'common_plan'=>achbotSupportsPlan($connection,7,0,2),
        'common_subject'=>achbotSupportsPaymentSubject($connection,$pay)
    ];
} elseif ($spec['mode']==='wallet') {
    $from_id=1001;
    $result=processWalletPayment($connection,(string)$spec['hash'],static function(){
        $GLOBALS['handler_calls']++;return true;
    });
    $status=$result['status'];
    $expectedAlert=achbotMessage('wallet.wallet_settlement.process_wallet_payment.alert.244ca46c8f');
} else {
    $from_id=9001;$text='';$buttonValues=['cancel'=>'cancel'];
    $userInfo=['userid'=>9001,'isAdmin'=>false];
    $data='accept'.(string)$spec['hash'];
    require $root.'/src/Finance/Routes/ManualApproval.php';
    $status='manual_returned';
    $expectedAlert=achbotMessage('manual_payment.manual_approval.manual_standard_report_delivery.alert.9ca98e8676');
}
$body=[
    'status'=>$status,'positive'=>$positive,'alerts'=>$GLOBALS['alerts'],
    'expected_alert'=>$expectedAlert,'native_mysqli'=>$connection instanceof mysqli,
    'select_only_identity'=>true,'network_blocked'=>$networkBlocked,'begins'=>$connection->begins,
    'rollbacks'=>$connection->rollbacks,'commits'=>$connection->commits,
    'read_statements'=>$connection->reads,'write_statements'=>$connection->writeStatements,
    'handler_calls'=>$GLOBALS['handler_calls'],'provider_calls'=>$GLOBALS['provider_calls'],
    'state_calls'=>$GLOBALS['state_calls'],'notification_calls'=>$GLOBALS['notification_calls']
];
$connection->close();
echo json_encode($body,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
'''

CASES=[
    ('retired_sanaei','sanaei',0,'BUY_SUB',0,False),
    ('retired_alireza','alireza',0,'BUY_SUB',0,False),
    ('retired_normal','normal',0,'BUY_SUB',0,False),
    ('unknown_future','future',0,'BUY_SUB',0,False),
    ('old_inbound','marzban',7,'BUY_SUB',0,False),
    ('obsolete_external_renewal','marzban',0,'RENEW_SCONFIG',7,False),
    ('missing_plan','marzban',0,'BUY_SUB',0,True),
]


def sql(work,env,query):
    return native.sql(work,env,query,DB).strip()


def snapshot_query(work,env):
    tables=sql(work,env,'SHOW TABLES').splitlines()
    assert len(tables)==23,tables
    queries=[]
    for table in tables:
        assert re.fullmatch(r'[A-Za-z0-9_]+',table)
        columns=[line.split('\t')[0] for line in sql(work,env,f'SHOW COLUMNS FROM `{table}`').splitlines()]
        assert all(re.fullmatch(r'[A-Za-z0-9_]+',c) for c in columns)
        fields=','.join(f"'{c}',`{c}`" for c in columns)
        queries.append(f"SELECT JSON_OBJECT('table','{table}','row',JSON_OBJECT({fields})) FROM `{table}`")
    return ';'.join(queries)


def select_only_probe(work,env):
    # This separate native client proves that a no-op DML statement is denied
    # by MariaDB itself; the application driver's observations remain separate.
    p=subprocess.run(['mariadb','--no-defaults','--protocol=SOCKET',
        '--socket='+str(work/'mysql.sock'),'-uowned_guard',DB,
        '-e','UPDATE users SET wallet=wallet WHERE 1=0'],
        env=env,capture_output=True,text=True,timeout=15)
    assert p.returncode!=0 and 'ERROR 1142 ' in p.stderr,('reader_dml_not_denied',p.returncode,p.stderr)
    return True


def snapshot(work,env,query):
    # JSON preserves NULL/string and numeric distinctions, while sorting
    # preserves complete duplicate-row multisets independently of row order.
    return tuple(sorted(sql(work,env,query).splitlines()))


def seed(work,env,case,mode):
    name,panel,inbound,paytype,volume,missing=case
    purchase.init(work,env,'http://127.0.0.1:9')
    sql(work,env,f"UPDATE server_config SET type='{panel}' WHERE id=2")
    sql(work,env,f'UPDATE server_plans SET inbound_id={inbound} WHERE id=7')
    if missing:sql(work,env,'DELETE FROM server_plans WHERE id=7')
    key='guard'+name.replace('_','')+mode
    state='have_sent' if mode=='manual' else 'pending'
    sql(work,env,"INSERT INTO pays "
        "(hash_id,user_id,type,plan_id,server_id,volume,day,price,request_date,state,message_id,chat_id) "
        f"VALUES('{key}',1001,'{paytype}',7,2,{volume},0,50,{int(time.time())},'{state}',222,9001)")
    return key


def run_php(work,env,driver,mode,key):
    p=subprocess.run(['php','-n','-d','extension=mysqlnd','-d','extension=mysqli',
        '-d','allow_url_fopen=0','-d','display_errors=0','-d','log_errors=1',
        '-d','error_log=/dev/stderr',
        '-d','disable_functions=curl_init,curl_exec,fsockopen,stream_socket_client,exec,shell_exec,system,passthru,popen,proc_open,mail',
        str(driver),str(ROOT),str(work/'mysql.sock'),DB,
        json.dumps({'mode':mode,'hash':key})],cwd=work,env=env,
        capture_output=True,text=True,timeout=15)
    if p.returncode or p.stderr:
        raise AssertionError(('native_preflight_failure',p.returncode,p.stderr[-900:],p.stdout[-400:]))
    return json.loads(p.stdout)


def main():
    ap=argparse.ArgumentParser(description=__doc__)
    ap.add_argument('--run-disposable',required=True,action='store_true')
    ap.add_argument('--report',type=Path)
    args=ap.parse_args();records=[]
    with owned.owned_database() as (work,env):
        sql(work,env,(ROOT/'migrations/post-v9/001_marzban_panel_group_id.sql').read_text())
        sql(work,env,"CREATE USER 'owned_guard'@'localhost' IDENTIFIED BY ''; "
            f"GRANT SELECT ON `{DB}`.* TO 'owned_guard'@'localhost'")
        grants=sql(work,env,"SHOW GRANTS FOR 'owned_guard'@'localhost'").splitlines()
        assert any(line.startswith('GRANT SELECT ON ') for line in grants),grants
        assert all(line.startswith(('GRANT USAGE ON ','GRANT SELECT ON ')) for line in grants),grants
        rows=snapshot_query(work,env)
        before=snapshot(work,env,rows)
        select_only_enforced=select_only_probe(work,env)
        assert snapshot(work,env,rows)==before,'reader_probe_row_mutation'
        driver=work/'native-wallet-guard.php';driver.write_text(PHP)
        for case in CASES:
            for mode in ('wallet','manual'):
                key=seed(work,env,case,mode);before=snapshot(work,env,rows)
                result=run_php(work,env,driver,mode,key)
                assert result['native_mysqli'] and result['select_only_identity']
                assert result['status']==('unsupported_panel' if mode=='wallet' else 'manual_returned'),result
                assert result['alerts']==[result['expected_alert']] and result['expected_alert'],result
                assert re.search(r'[\u0600-\u06ff]',result['expected_alert']),result
                assert not re.search(r'marzban|pasarguard|mysqli|database|sql|exception|stack trace|پنل|دیتابیس|پایگاه داده',
                    result['expected_alert'],re.I),result
                assert result['network_blocked'],result
                assert [result[k] for k in ('begins','rollbacks','commits')]==(
                    [0,0,0] if mode=='wallet' else [1,1,0]),result
                assert all(result[k]==0 for k in ('write_statements','handler_calls','provider_calls','state_calls','notification_calls')),result
                assert snapshot(work,env,rows)==before,('row_mutation',case[0],mode)
                records.append({'case':case[0]+'_'+mode,'mode':mode,'pass':True,
                    'native_php_mysqli':result['native_mysqli'],
                    'select_only_identity':result['select_only_identity'],
                    'network_blocked':result['network_blocked'],'safe_feedback':True,
                    'snapshot_unchanged':True,'snapshot_rows':len(before),
                    'snapshot_sha256':hashlib.sha256('\n'.join(before).encode()).hexdigest(),
                    'transactions':{k:result[k] for k in ('begins','rollbacks','commits')},
                    'read_statements':result['read_statements'],
                    **{k:result[k] for k in ('write_statements','handler_calls','provider_calls','state_calls','notification_calls')}})
        valid=('valid_marzban','marzban',0,'BUY_SUB',0,False)
        key=seed(work,env,valid,'positive');before=snapshot(work,env,rows)
        result=run_php(work,env,driver,'positive',key)
        assert result['positive']=={'marzban':True,'common_plan':True,'common_subject':True},result
        assert result['alerts']==[]
        assert all(result[k]==0 for k in ('begins','rollbacks','commits','write_statements','handler_calls','provider_calls','state_calls','notification_calls')),result
        assert snapshot(work,env,rows)==before
        assert result['network_blocked'],result
        records.append({'case':'genuine_marzban_preflight_remains_supported','mode':'positive','pass':True,
            'native_php_mysqli':result['native_mysqli'],
            'select_only_identity':result['select_only_identity'],
            'network_blocked':result['network_blocked'],
            'positive':result['positive'],'snapshot_unchanged':True,
            'snapshot_rows':len(before),
            'snapshot_sha256':hashlib.sha256('\n'.join(before).encode()).hexdigest()})
    wallet=[r for r in records if r['mode']=='wallet']
    manual=[r for r in records if r['mode']=='manual']
    assert len(wallet)==len(manual)==7 and len(records)==15
    report={'complete':True,'cases':len(wallet),'total_cases':len(records),'negative_scenarios':7,
        'wallet_cases':len(wallet),'manual_cases':len(manual),'positive_cases':1,
        'native_php_mysqli':all(r['native_php_mysqli'] for r in records),
        'actual_php_mysqli':all(r['native_php_mysqli'] for r in records),
        'select_only_account_enforced':select_only_enforced and all(r['select_only_identity'] for r in records),
        'original_23_table_snapshots_unchanged':all(r['snapshot_unchanged'] for r in records),
        'wallet_rejects_before_transaction':all(r['transactions']=={'begins':0,'rollbacks':0,'commits':0} for r in wallet),
        'manual_rejects_single_rollback':all(r['transactions']=={'begins':1,'rollbacks':1,'commits':0} for r in manual),
        'network_capability_blocked':all(r['network_blocked'] for r in records),
        'owned_socket_only':True,'schema_tables':23,'migration_001_only':True,
        'select_only_database_capability':True,'dispatcher_under_test':'shipping_source',
        'http_calls':0,'handler_calls':0,'committed_sql_mutations':0,'all_table_snapshots_unchanged':True,
        'cases_detail':records}
    if args.report:args.report.write_text(json.dumps(report,indent=2)+'\n')
    print(json.dumps({k:v for k,v in report.items() if k!='cases_detail'}))


if __name__=='__main__':main()
