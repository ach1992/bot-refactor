#!/usr/bin/env python3
"""#227: force indexed, service-bound row locks, never historical full-table X scans.

Tests real-source PHP against a temporary MariaDB and deliberately concurrent
readers/writers. Never connects to production MariaDB.
"""
from __future__ import annotations
import argparse
import json
import subprocess
from pathlib import Path
import test_post_v9_scoped_api_pg as api

ROOT=Path(__file__).resolve().parents[1]
PHP=r'''<?php
error_reporting(E_ALL & ~E_DEPRECATED);
set_error_handler(static function($code,$msg){throw new ErrorException($msg,0,$code);});
$_SERVER['SCRIPT_FILENAME']=__FILE__;
require_once $argv[1].'/src/Finance/ReceiptHelpers.php';
$db=new mysqli('localhost','root','',$argv[3],0,$argv[2]);
$db->set_charset('utf8mb4');
try {
    if (!$db->begin_transaction())throw new RuntimeException('fixture_begin');
    $rows=achbotServicePaymentRowsLocked($db,[777]);
    $ids=array_column($rows,'id');
    if($argv[4]==='hold'){
        echo "LOCKED\n";flush();sleep(4);
    }
    if(!$db->commit())throw new RuntimeException('fixture_commit');
    echo json_encode(['ids'=>$ids],JSON_THROW_ON_ERROR);
}catch(Throwable $e) {
    try{$db->rollback();}catch(Throwable $ignored){}
    echo json_encode(['error'=>$e->getMessage()],JSON_THROW_ON_ERROR);
}
'''

def main():
    ap=argparse.ArgumentParser()
    ap.add_argument('--run-disposable',action='store_true',required=True)
    ap.add_argument('--report',type=Path)
    opts=ap.parse_args()
    checks=[]
    with api.sandbox() as (sql, runner, _provider, _bank, _epoch):
        # DDL is only against the owned DB; production rollout remains gated.
        sql((ROOT/'migrations/post-v9/023_scoped_payment_lock_indexes.sql').read_text())
        # Exact original late add-on shapes, including historical order_id=0.
        sql("INSERT INTO pays(hash_id,user_id,type,server_id,order_id,price,request_date,state)"
            " VALUES ('lock-renew',1001,'RENEW_ACCOUNT',2,777,100,UNIX_TIMESTAMP(),'processing_wallet'),"
            "('lock-addon-zero',1001,'INCREASE_DAY_777_10',2,0,100,UNIX_TIMESTAMP(),'pending'),"
            "('lock-addon-volume',1001,'INCREASE_VOLUME_777_5',2,777,100,UNIX_TIMESTAMP(),'processing'),"
            "('lock-neighbor',1001,'INCREASE_DAY_7770_2',2,7770,100,UNIX_TIMESTAMP(),'processing'),"
            "('lock-unrelated',1001,'BUY_SUB',2,888,100,UNIX_TIMESTAMP(),'processing')")
        # More rows make a global PRIMARY FOR UPDATE scan objectively costly.
        # No secrets, Native effects or wallets in these synthetic inserts.
        values=[f"('locked-bulk-{i}',1001,'BUY_SUB',2,{9000+i},100,UNIX_TIMESTAMP(),'paid')"
                for i in range(800)]
        for i in range(0,len(values),200):
            sql("INSERT INTO pays(hash_id,user_id,type,server_id,order_id,price,request_date,state) VALUES "
                +','.join(values[i:i+200]))
        def id_of(hash_:str)->int:
            return int(sql("SELECT id FROM pays WHERE hash_id="+api.quote_sql(hash_)))
        expected={id_of('lock-renew'),id_of('lock-addon-zero'),id_of('lock-addon-volume')}
        unrelated=id_of('lock-unrelated')
        neighbor=id_of('lock-neighbor')
        expl=sql("EXPLAIN SELECT id,state FROM pays FORCE INDEX (idx_pays_order_ref_v1)"
                 " WHERE order_id IN (777) ORDER BY order_id,id FOR UPDATE; "
                 "EXPLAIN SELECT id,state FROM pays FORCE INDEX (idx_pays_type_ref_v1)"
                 " WHERE type LIKE 'INCREASE=_DAY=_777=_%' ESCAPE '='"
                 " ORDER BY type,id FOR UPDATE")
        assert 'idx_pays_order_ref_v1' in expl and 'idx_pays_type_ref_v1' in expl,expl
        # EXPLAIN must say range/ref, never PRIMARY or global index access.
        rows=[x.split('\t') for x in expl.splitlines() if '\t' in x]
        assert len(rows)==2,expl
        assert rows[0][3] in ('range','ref') and rows[1][3] in ('range','ref'),expl
        checks.append('mariadb_forced_indexed_order_and_literal_addon_prefix_ranges')
        driver=runner.fixture_work/'scoped-locked-invoices.php'
        driver.write_text(PHP)
        command=['php','-n','-d','extension=mysqlnd','-d','extension=mysqli',
            str(driver),str(ROOT),str(runner.fixture_socket),api.DB]
        normal=subprocess.run(command+['once'],cwd=runner.fixture_work,
            env=runner.fixture_env,capture_output=True,text=True,timeout=10)
        assert normal.returncode==0 and normal.stdout.startswith('{'),normal.stderr
        result=json.loads(normal.stdout)
        assert set(map(int,result['ids']))==expected,result
        assert unrelated not in result['ids'] and neighbor not in result['ids']
        checks.append('exact_original_order_and_null_legacy_addon_only')
        p=subprocess.Popen(command+['hold'],cwd=runner.fixture_work,
                           env=runner.fixture_env,stdout=subprocess.PIPE,
                           stderr=subprocess.PIPE,text=True)
        try:
            signal=p.stdout.readline().strip() if p.stdout else None
            assert signal=='LOCKED',signal
            # A different payment row cannot be X-locked by this service guard.
            sql("SET innodb_lock_wait_timeout=1; UPDATE pays SET description='not-blocked' "
                f"WHERE id={unrelated}; SET innodb_lock_wait_timeout=50")
            checks.append('unrelated_existing_payment_write_completes_while_guard_holds')
            # But a relevant row is actually locked, not just a nonlocking
            # snapshot check. The independent UPDATE must time out.
            failed=False
            try:
                sql("SET innodb_lock_wait_timeout=1; UPDATE pays SET description='should-wait' "
                    f"WHERE id={id_of('lock-renew')}")
            except Exception as exc:
                failed='1205' in str(exc) or 'Lock wait timeout' in str(exc)
            assert failed,'relevant_payment_lock_missing'
            checks.append('relevant_payment_write_is_serialized')
        finally:
            p.communicate(timeout=10)
        # No temporary SQL schema/data escapes the context manager.
    result={'complete':True,'status':'PASS','checks':len(checks),'cases':checks,'database':'owned-temporary-socket'}
    if opts.report:opts.report.write_text(json.dumps(result,indent=2)+'\n')
    print(json.dumps({'checks':len(checks),'passed':True}))

if __name__=='__main__':main()
