#!/usr/bin/env python3
"""Disposable MariaDB rehearsal of the exact signed opening balances.

The inventory is intentionally not a historical claim migration decision.
No payment replay, provider call, receipt credit, or runtime activation.
"""
from __future__ import annotations

import argparse
import json
from pathlib import Path
import subprocess
import test_access_state as owned

ROOT=Path(__file__).resolve().parents[1]
PHP=r'''<?php
declare(strict_types=1);
mysqli_report($argv[4]==='strict'?MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT:MYSQLI_REPORT_OFF);
require $argv[1].'/tools/wallet_first_cutover.php';
$db=new mysqli('localhost','root','',$argv[3],0,$argv[2]);
try {
    $c=json_decode($argv[5],true,32,JSON_THROW_ON_ERROR);
    $r=match($c['op']) {
        'audit'=>walletFirstCutoverAudit($db),
        'open'=>walletFirstCutoverOpen($db,$c['expected']),
        'snapshot'=>walletFirstCutoverSnapshot($db),
        default=>throw new RuntimeException('fixture_operation')
    };
    echo json_encode(['ok'=>true,'result'=>$r],JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    echo json_encode(['ok'=>false,'reason'=>$e->getMessage()],JSON_THROW_ON_ERROR);
}
'''

def main():
    ap=argparse.ArgumentParser(description=__doc__)
    ap.add_argument('--run-disposable',required=True,action='store_true')
    ap.add_argument('--report',type=Path)
    args=ap.parse_args()
    checks=[]
    with owned.owned_database() as (work,env):
        client=['mariadb','--no-defaults','--protocol=SOCKET','--socket='+str(work/'mysql.sock'),'-uroot','--batch','--skip-column-names']
        driver=work/'cutover.php';driver.write_text(PHP)
        for mode,db in zip(('strict','off'),owned.NAMES):
            def sql(q):
                return subprocess.run(client+[db],input=q,text=True,env=env,check=True,capture_output=True,timeout=20).stdout.strip()
            def run(op,**kwargs):
                p=subprocess.run(['php',str(driver),str(ROOT),str(work/'mysql.sock'),db,mode,json.dumps(dict(op=op,**kwargs))],
                                 env=env,check=True,capture_output=True,text=True,timeout=20)
                assert not p.stderr,p.stderr
                return json.loads(p.stdout)
            def ok(op,**kwargs):
                r=run(op,**kwargs);assert r['ok'],r
                return r['result']
            def reject(op,why,**kwargs):
                r=run(op,**kwargs);assert not r['ok'] and r['reason']==why,r
            def case(name):checks.append(mode+':'+name)
            for m in ('003_payment_attempts.sql','004_automatic_renewal_attempts.sql',
                '007_service_effects.sql','008_referral_awards.sql','011_provider_identity.sql',
                '012_shared_provider_storage.sql','014_api_payment_evidence.sql',
                '018_payment_delivery_intents.sql','020_payment_followup_intents.sql',
                '022_gateway_review_archive.sql','023_scoped_payment_lock_indexes.sql',
                '024_wallet_entries.sql','025_wallet_receipt_credit.sql','026_service_operations.sql',
                '027_service_delivery_sources.sql','028_service_mutations.sql','029_service_purchase_referral.sql',
                '030_service_followup_sources.sql','031_service_followup_attempts.sql',
                '032_service_delivery_parts.sql','033_checkout_commands.sql'):
                sql((ROOT/'migrations/post-v9'/m).read_text())
            sql("UPDATE users SET wallet=-300 WHERE userid=9002")
            sql("""INSERT INTO pays(hash_id,user_id,type,price,request_date,state)
                VALUES ('completed',1001,'BUY_SUB',100,UNIX_TIMESTAMP(),'approved'),
                ('reserved',1001,'BUY_SUB',200,UNIX_TIMESTAMP(),'processing'),
                ('unverified',9002,'INCREASE_WALLET',300,UNIX_TIMESTAMP(),'have_sent'),
                ('unissued',1001,'BUY_SUB',400,UNIX_TIMESTAMP(),'pending')""")
            old=sql('SELECT id,userid,wallet FROM users ORDER BY id')
            oldp=sql('SELECT * FROM pays ORDER BY id')
            report=ok('audit')
            snap=report['snapshot']
            assert snap['owners']==3 and snap['sum_toman']==49700 and snap['zero']==1 and snap['negative']==1,report
            assert report['inventory']['historical_receipt_or_payment_claim']['count']==2
            assert not report['legacy_claims_clear'] and not report['runtime_activation_authorized']
            assert sql('SELECT COUNT(*) FROM wallet_entries')=='0'
            case('read_only_original_claim_inventory')
            reject('open','cutover_unique_owner_required',expected=snap['sha256'])
            sql("""INSERT INTO users(userid,name,username,refcode,wallet,date,isAdmin,first_start)
                VALUES(1001,'Duplicate','duplicate','0',1,'1700000000',0,'sent')""")
            reject('snapshot','cutover_owner_identity_ambiguous')
            try:sql((ROOT/'migrations/post-v9/034_wallet_owner_uniqueness.sql').read_text())
            except subprocess.CalledProcessError:pass
            else:raise AssertionError('duplicate uniqueness DDL admitted')
            sql("DELETE FROM users WHERE username='duplicate'")
            sql((ROOT/'migrations/post-v9/034_wallet_owner_uniqueness.sql').read_text())
            case('duplicate_identity_rejected_without_merge')
            reject('open','cutover_snapshot_changed',expected='f'*64)
            sql("""CREATE TRIGGER fixture_open_failure BEFORE INSERT ON wallet_entries FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture forced rollback'""")
            try:
                reject('open','provider_storage_unavailable',expected=snap['sha256'])
                assert sql('SELECT COUNT(*) FROM wallet_entries')=='0'
            finally:sql('DROP TRIGGER fixture_open_failure')
            case('stale_snapshot_and_insert_failure_atomic')
            r=ok('open',expected=snap['sha256'])
            assert r['snapshot']==snap and not r['replayed']
            assert sql('SELECT COUNT(*) FROM wallet_entries')=='3'
            assert sql('SELECT SUM(delta_toman) FROM wallet_entries')=='49700'
            assert sql('SELECT COUNT(*) FROM wallet_entries w JOIN users u ON u.id=w.user_row_id AND u.userid=w.userid WHERE w.balance_after=u.wallet')=='3'
            assert sql('SELECT id,userid,wallet FROM users ORDER BY id')==old
            assert sql('SELECT * FROM pays ORDER BY id')==oldp
            assert ok('open',expected=snap['sha256'])['replayed']
            case('signed_exact_openings_and_idempotent_rehearsal')
            sql("UPDATE users SET wallet=wallet+10 WHERE userid=9002")
            reject('open','cutover_snapshot_changed',expected=snap['sha256'])
            reject('open','cutover_open_reconciliation',expected=ok('snapshot')['sha256'])
            assert sql('SELECT COUNT(*) FROM wallet_entries')=='3'
            case('never_reopen_changed_balances')
    report=dict(complete=True,cases=len(checks),checks=checks,owned_socket_mariadb=True,external_requests=0)
    if args.report:args.report.write_text(json.dumps(report,indent=2)+'\n')
    print(json.dumps(report))

if __name__=='__main__':main()
