#!/usr/bin/env python3
"""Review #232: exact 003/022 + throwing/nonthrowing MySQLi Archive fence matrix.

Every case runs current PHP source against an owned socket-only MariaDB, with
synthetic Native/Telegram adapters. Never touches an installed bot or provider.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import subprocess
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path
from time import monotonic, sleep

import test_access_state as owned
import test_v6_native as native
import test_v8_native as purchase
import test_post_v9_wallet_checkout as checkout
import test_post_v9_wallet_original_recovery as original

ROOT = Path(__file__).resolve().parents[1]
DB = purchase.SIDE[1]

PHP_FENCES = r'''<?php
$root=$argv[1];$sock=$argv[2];$database=$argv[3];$payId=(int)$argv[4];
$op=$argv[5];$mode=$argv[6];$restricted=($argv[7]??'')==='restricted';
mysqli_report($mode==='off' ? MYSQLI_REPORT_OFF : MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$_SERVER['SCRIPT_FILENAME']=__FILE__;
try {
    $db=new mysqli('localhost',$restricted?'archive_deny':'root',
        $restricted?'fixture_only':'',$database,0,$sock);
    $db->set_charset('utf8mb4');
    require_once $root.'/src/Finance/ServiceEffects.php';
    require_once $root.'/src/Finance/GatewayClient.php';
    if ($op==='helper') {
        $value=achbotGatewayOriginalArchived($db,$payId);
    } elseif ($op==='fence') {
        $db->begin_transaction();
        if (!$restricted) {
            $q=$db->prepare('SELECT id FROM pays WHERE id=? FOR UPDATE');
            $q->bind_param('i',$payId);$q->execute();$q->close();
        }
        achbotEffectAssertNotArchivedLocked($db,$payId,false,true);
        $db->rollback();$value='allowed';
    } elseif ($op==='probe') {
        $q=$db->prepare('SELECT review_archived_at FROM payment_attempts WHERE pay_row_id=?');
        $value=$q ? 0 : (int)$db->errno;
        if ($q) $q->close();
    } else throw new RuntimeException('unknown_fixture_operation');
    $result=['ok'=>true,'result'=>$value];
} catch(Throwable $error) {
    $sqlError=isset($db)?(int)$db->errno:0;
    try {if (isset($db))$db->rollback();}catch(Throwable $ignored){}
    $result=['ok'=>false,'class'=>get_class($error),
        'error'=>$error->getMessage(),'code'=>(int)$error->getCode(),
        'sql_errno'=>$sqlError];
}
echo json_encode($result,JSON_THROW_ON_ERROR);
'''

def run_php(work,env,source,mode,*arguments):
    driver=work/('archive-compat-'+mode+'-'+
                 hashlib.sha256(source.encode()).hexdigest()[:12]+'.php')
    if not driver.exists():
        driver.write_text(source.replace('<?php', '<?php\nmysqli_report('+
            ('MYSQLI_REPORT_OFF' if mode=='off'
             else 'MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT')+');',1))
    run=subprocess.run(['php','-n','-d','extension=mysqlnd','-d','extension=mysqli',
            '-d','extension=curl','-d','extension=mbstring',
            str(driver),str(ROOT),str(work/'mysql.sock'),DB,*map(str,arguments)],
        cwd=work,env=env,capture_output=True,text=True,timeout=40)
    if run.returncode or not run.stdout:
        raise AssertionError(('source_php_failed',mode,run.returncode,
                              run.stdout[-700:],run.stderr[-1000:]))
    return json.loads(run.stdout)

def check():
    cases=[]
    with owned.owned_database() as (work,env):
        purchase.init(work,env,'http://127.0.0.1:9')
        def sql(query):return native.sql(work,env,query,DB).strip()
        for migration in ('001_marzban_panel_group_id.sql',
                          '003_payment_attempts.sql',
                          '007_service_effects.sql'):
            sql((ROOT/'migrations/post-v9'/migration).read_text())
        sql("DELETE FROM setting WHERE type='BOT_STATES'; INSERT INTO setting(type,value) "
            "VALUES('BOT_STATES','{\"walletState\":\"on\","
            "\"gatewayAudience\":{\"wallet\":\"all\"}}')")
        sql("CREATE TABLE wallet_fixture_barrier "
            "(id INT PRIMARY KEY,arrived TINYINT NOT NULL,released TINYINT NOT NULL);"
            "INSERT INTO wallet_fixture_barrier VALUES(1,0,0)")
        def seeded(name,count=2):
            sql('UPDATE users SET wallet=50000 WHERE userid=1001')
            sql('UPDATE server_info SET ucount=10 WHERE id=2')
            sql("INSERT INTO pays (hash_id,user_id,type,plan_id,server_id,"
                "agent_count,day,volume,price,description,request_date,state) "
                f"VALUES('{name}',1001,'BUY_SUB',7,2,{count},0,0,100,"
                f"'{name}',UNIX_TIMESTAMP(),'pending')")
            return int(sql(f"SELECT id FROM pays WHERE hash_id='{name}'"))
        def snap(pay):
            return (
                sql(f'SELECT state FROM pays WHERE id={pay}'),
                sql(f"SELECT effect_kind,ordinal,state FROM service_effects "
                    f"WHERE pay_row_id={pay} ORDER BY ordinal,id"),
                sql('SELECT wallet FROM users WHERE userid=1001'),
                sql(f'SELECT COUNT(*) FROM orders_list o JOIN service_effects e '
                    f'ON o.id=e.order_id WHERE e.pay_row_id={pay}'),
            )
        def fences(mode,pay,op,restricted=False):
            result=run_php(work,env,PHP_FENCES,mode,pay,op,
                           mode,'restricted' if restricted else 'root')
            return result
        def buy(mode,name,**spec):
            events=run_php(work,env,checkout.PHP,mode,
                           json.dumps({'hash':name,**spec}))
            return events['events']
        def inspect(mode,pay,op='inspect',**spec):
            return run_php(work,env,original.PHP,mode,
                           json.dumps({'op':op,'pay':pay,**spec}))

        # First exercise a genuine two-account original checkout and the
        # admin recovery source under 003+007 without Archive migration 022.
        for mode in ('strict','off'):
            pay=seeded('legacy_003_'+mode)
            missing=fences(mode,pay,'probe')
            assert (missing=={'ok':True,'result':1054} if mode=='off'
                    else missing['ok'] is False and missing['code']==1054),missing
            assert fences(mode,pay,'helper')=={'ok':True,'result':False}
            assert fences(mode,pay,'fence')=={'ok':True,'result':'allowed'}
            created=buy(mode,'legacy_003_'+mode,remote='second_lost')
            assert len([e for e in created if e['event']=='POST'])==2,created
            assert not any(e['event']=='QR' for e in created)
            before=snap(pay)
            assert before[0]=='processing_wallet' and before[2]=='49900',before
            inspected=inspect(mode,pay)
            assert inspected['ok'],inspected
            assert inspected['result']['status'] in ('ready','review'),inspected
            assert snap(pay)==before,'legacy inspection changed original claim'
            assert fences(mode,pay,'fence')=={'ok':True,'result':'allowed'}
            assert fences(mode,pay,'helper')=={'ok':True,'result':False}
            cases.append('003_without_022_'+mode+'_real_wallet_buy_and_original_inspection')

        # Install 022 only after old-schema cases, confirming 003 is
        # independently usable and the added Archive fence is effective.
        sql((ROOT/'migrations/post-v9/022_gateway_review_archive.sql').read_text())
        for mode in ('strict','off'):
            pay=seeded('archive_022_'+mode)
            assert fences(mode,pay,'probe')=={'ok':True,'result':0}
            assert fences(mode,pay,'fence')=={'ok':True,'result':'allowed'}
            created=buy(mode,'archive_022_'+mode,remote='second_lost')
            assert len([e for e in created if e['event']=='POST'])==2,created
            assert snap(pay)[0]=='processing_wallet'
            sql(f'UPDATE pays SET request_date=UNIX_TIMESTAMP()-900 WHERE id={pay}')
            archive=run_php(work,env,checkout.PHP_ARCHIVE_GATE,mode,pay,'archive')
            assert archive=={'status':'archived'},archive
            recorded=snap(pay)
            assert fences(mode,pay,'helper')=={'ok':True,'result':True}
            fenced=fences(mode,pay,'fence')
            assert not fenced['ok'] and fenced['error']=='effect_original_archived',fenced
            review=inspect(mode,pay)
            assert review['ok'] and review['result']['status']=='archived',review
            stale=buy(mode,'archive_022_'+mode)
            assert not any(e['event'] in ('POST','QR') for e in stale),stale
            assert snap(pay)==recorded,'archived claim was mutated'
            cases.append('003_and_022_'+mode+'_archive_fences_native_and_staff_inspection')

            # Shared original session lease must span an active worker even
            # in MYSQLI_REPORT_OFF. Second connection uses real Archive SQL.
            active=seeded('active_022_'+mode)
            sql(f'UPDATE pays SET request_date=UNIX_TIMESTAMP()-900 WHERE id={active}')
            sql('UPDATE wallet_fixture_barrier SET arrived=0,released=0 WHERE id=1')
            with ThreadPoolExecutor(max_workers=1) as pool:
                future=pool.submit(buy,mode,'active_022_'+mode,
                                   after_wallet_commit='pause_for_rival')
                deadline=monotonic()+12
                while monotonic()<deadline and sql('SELECT arrived FROM wallet_fixture_barrier WHERE id=1')!='1':
                    sleep(.05)
                try:
                    assert sql('SELECT arrived FROM wallet_fixture_barrier WHERE id=1')=='1'
                    assert snap(active)[0]=='processing_wallet'
                    blocked=run_php(work,env,checkout.PHP_ARCHIVE_GATE,mode,active,'archive')
                    assert blocked=={'status':'busy'},blocked
                finally:
                    sql('UPDATE wallet_fixture_barrier SET released=1 WHERE id=1')
                finished=future.result(timeout=40)
            assert len([e for e in finished if e['event']=='POST'])==2,finished
            assert snap(active)[0]=='paid_with_wallet'
            assert fences(mode,active,'helper')=={'ok':True,'result':False}
            cases.append('003_and_022_'+mode+'_real_worker_lease_blocks_archive')

        # Privilege denial must not be interpreted as missing schema, for
        # either mysqli reporting mode or either Archive entrypoint.
        sql("CREATE USER 'archive_deny'@'localhost' IDENTIFIED BY 'fixture_only'")
        sql(f"GRANT SELECT ON {DB}.pays TO 'archive_deny'@'localhost'")
        for mode in ('strict','off'):
            for op in ('helper','fence'):
                denied=fences(mode,pay,op,restricted=True)
                assert denied['ok'] is False,denied
                assert (denied['code']==1142 or denied['sql_errno']==1142),denied
                assert denied['error']!='effect_original_archived',denied
                cases.append('privilege_1142_'+mode+'_'+op+'_fails_closed')

        # A different required column missing from a malformed table also
        # yields SQL 1054. We must NOT mistake it for a missing 022 column.
        sql('SET FOREIGN_KEY_CHECKS=0; DROP TABLE payment_attempts; '
            'CREATE TABLE payment_attempts '
            '(id INT PRIMARY KEY,review_archived_at DATETIME NULL); '
            'SET FOREIGN_KEY_CHECKS=1')
        for mode in ('strict','off'):
            missing=fences(mode,pay,'probe')
            assert (missing=={'ok':True,'result':1054} if mode=='off'
                    else missing['ok'] is False and missing['code']==1054),missing
            for op in ('helper','fence'):
                malformed=fences(mode,pay,op)
                assert malformed['ok'] is False,malformed
                cases.append('unexpected_missing_pay_row_id_'+mode+'_'+op+'_fails_closed')

        # A malformed *present* table with the 022 Archive column missing
        # but core 003 columns also absent must not be treated as an older
        # valid 003-only installation. Preserve original finance/effect state
        # and refuse staff Native inspection before even reaching a GET.
        for missing_name,columns in (
            ('both_archive_and_pay_lookup', 'id INT PRIMARY KEY'),
            ('archive_and_required_id', 'pay_row_id INT,provider VARCHAR(32)'),
            ('archive_and_required_provider', 'id INT PRIMARY KEY,pay_row_id INT'),
        ):
            sql('SET FOREIGN_KEY_CHECKS=0; DROP TABLE payment_attempts; '
                f'CREATE TABLE payment_attempts ({columns}); '
                'SET FOREIGN_KEY_CHECKS=1')
            for mode in ('strict','off'):
                missing=fences(mode,pay,'probe')
                assert (missing=={'ok':True,'result':1054} if mode=='off'
                        else missing['ok'] is False and missing['code']==1054),missing
                before=snap(pay)
                malformed=fences(mode,pay,'helper')
                assert malformed['ok'] is False,malformed
                denial=inspect(mode,pay)
                assert denial['ok'] is False and not denial['events'],denial
                assert fences(mode,pay,'fence')['ok'] is False
                assert snap(pay)==before,'malformed ledger modified a payment'
                cases.append(missing_name+'_'+mode+'_fails_before_native')

        # Older pre-003 installs also lack a local Archive. The absence of
        # the whole table is explicitly allowed, unlike permission errors.
        sql('SET FOREIGN_KEY_CHECKS=0; DROP TABLE payment_attempts; '
            'SET FOREIGN_KEY_CHECKS=1')
        for mode in ('strict','off'):
            assert fences(mode,pay,'helper')=={'ok':True,'result':False}
            assert fences(mode,pay,'fence')=={'ok':True,'result':'allowed'}
            cases.append('missing_003_ledger_'+mode+'_recognized_exact_1146')

    return {'complete':True,'cases':len(cases),'scenarios':cases,
        'strict_and_report_off':True,'real_php_mysql':True,
        'real_wallet_native_checkout':True,'original_buy_inspection':True,
        'archived_stale_callback':True,'active_worker_busy':True,
        'production_mutations':0}

if __name__=='__main__':
    parser=argparse.ArgumentParser()
    parser.add_argument('--run-disposable',action='store_true',required=True)
    parser.add_argument('--report',type=Path)
    args=parser.parse_args()
    result=check()
    if args.report:args.report.write_text(json.dumps(result,indent=2)+'\n')
    print(json.dumps(result))
