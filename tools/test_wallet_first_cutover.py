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
LEGACY_RESERVE_PHP=r'''
function fixtureLegacyReserve(mysqli $db,int $id): bool {
    global $argv;
    require_once $argv[1].'/src/Finance/ServiceEffects.php';
    if (!$db->begin_transaction()) throw new RuntimeException('fixture_legacy_begin');
    try {
        $p=achbotProviderRow($db,'SELECT * FROM pays WHERE id=? FOR UPDATE','i',[$id]);
        achbotProviderPinPayment($db,$id);
        $s=achbotProviderStatement($db,"UPDATE users SET wallet=wallet-? WHERE userid=?",
            'ii',[(int)$p['price'],(int)$p['user_id']]);$s->close();
        $s=achbotProviderStatement($db,"UPDATE pays SET state='processing_wallet' WHERE id=?",'i',[$id]);$s->close();
        // Model the already committed historical row in the disposable
        // database. This is fixture data, not an obsolete runtime writer.
        $fresh=achbotProviderRow($db,'SELECT provider_snapshot FROM pays WHERE id=?','i',[$id]);
        $snap=achbotProviderCanonicalSnapshot(json_decode($fresh['provider_snapshot'],true,64,JSON_THROW_ON_ERROR));
        $sid=(int)$p['server_id'];$pid=(int)$p['plan_id'];$count=max(1,(int)$p['agent_count']);
        $endpoint=$snap['endpoint_sha256'];$target='pg:'.$snap['instance_key'];
        $op=bin2hex(random_bytes(16));$remark='#wallet_hold_'.$id;
        $digest=hash('sha256',json_encode(['wallet_hold',$id,$count,$pid,$sid,null,$endpoint],JSON_THROW_ON_ERROR));
        $s=achbotProviderStatement($db,'UPDATE server_info SET ucount=ucount-? WHERE id=? AND ucount>=?',
            'iii',[$count,$sid,$count]);
        if ($s->affected_rows!==1) throw new RuntimeException('fixture_legacy_capacity');$s->close();
        $s=achbotProviderStatement($db,"INSERT INTO service_effects
            (pay_row_id,ordinal,operation_id,effect_kind,target_server_id,target_group_id,target_key,
             endpoint_sha256,plan_id,remote_username,requested_expire,requested_bytes,request_sha256,reserved_count,state)
            VALUES (?,0,?,'wallet_hold',?,NULL,?,?,?, ?,0,0,?,?,'reserved')",
            'isississi',[$id,$op,$sid,$target,$endpoint,$pid,$remark,$digest,$count]);$s->close();
        if (!$db->commit()) throw new RuntimeException('fixture_legacy_commit');
        return true;
    } catch (Throwable $e) {$db->rollback();throw $e;}
}
'''
PHP=r'''<?php
declare(strict_types=1);
mysqli_report($argv[4]==='strict'?MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT:MYSQLI_REPORT_OFF);
require $argv[1].'/tools/wallet_first_cutover.php';
$db=new mysqli('localhost','root','',$argv[3],0,$argv[2]);
''' + LEGACY_RESERVE_PHP + r'''
try {
    $c=json_decode($argv[5],true,32,JSON_THROW_ON_ERROR);
    $r=match($c['op']) {
        'audit'=>walletFirstCutoverAudit($db),
        'open'=>walletFirstCutoverOpen($db,$c['expected']),
        'legacy_reserve'=>fixtureLegacyReserve($db,$c['pay']),
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
            for m in ('001_marzban_panel_group_id.sql','003_payment_attempts.sql','004_automatic_renewal_attempts.sql',
                '007_service_effects.sql','008_referral_awards.sql','011_provider_identity.sql',
                '012_shared_provider_storage.sql','014_api_payment_evidence.sql',
                '018_payment_delivery_intents.sql','020_payment_followup_intents.sql',
                '022_gateway_review_archive.sql','023_scoped_payment_lock_indexes.sql',
                '024_wallet_entries.sql','025_wallet_receipt_credit.sql','026_service_operations.sql',
                '027_service_delivery_sources.sql','028_service_mutations.sql','029_service_purchase_referral.sql',
                '030_service_followup_sources.sql','031_service_followup_attempts.sql',
                '032_service_delivery_parts.sql','033_checkout_commands.sql','035_service_resolution.sql'):
                sql((ROOT/'migrations/post-v9'/m).read_text())
            sql("UPDATE users SET wallet=-300 WHERE userid=9002")
            sql("""INSERT INTO pays(hash_id,user_id,type,price,request_date,state)
                VALUES ('completed',1001,'BUY_SUB',100,UNIX_TIMESTAMP(),'approved'),
                ('reserved',1001,'BUY_SUB',200,UNIX_TIMESTAMP(),'processing'),
                ('unverified',9002,'INCREASE_WALLET',300,UNIX_TIMESTAMP(),'have_sent'),
                ('unissued',1001,'BUY_SUB',400,UNIX_TIMESTAMP(),'pending')""")
            sql("""INSERT INTO server_config(id,panel_url,ip,sni,header_type,request_header,response_header,security,tlsSettings,type,username,password)
                VALUES (2,'https://fixture.invalid','127.0.0.1','','none','','','none','{}','pasarguard','fixture','fixture');
                INSERT INTO server_info(id,title,ucount,remark,flag,active,state) VALUES (2,'Fixture','100','fixture','',1,1);
                UPDATE server_config SET provider_connection_id=2,provider_instance_key=REPEAT('a',32),
                    provider_version='5.5.1',provider_enabled=1 WHERE id=2;
                INSERT INTO server_plans(id,fileid,catid,server_id,inbound_id,acount,title,protocol,days,volume,type,price,descr,pic,active,step,date,custom_sni,provider_group_ids)
                VALUES (7,'7',1,2,0,6,'Fixture','vless',30,2,'normal',100,'description','',1,0,'fixture',NULL,'[1,2]');
                INSERT INTO pays(hash_id,user_id,type,price,request_date,state,server_id,plan_id,day,volume,description)
                VALUES ('prepaid-custom',1001,'BUY_SUB',100,UNIX_TIMESTAMP(),'pending',2,7,17,5,'original-custom-name'),
                       ('prepaid-standard',1001,'BUY_SUB',200,UNIX_TIMESTAMP(),'pending',2,7,0,0,'old-standard-name')""")
            custom=int(sql("SELECT id FROM pays WHERE hash_id='prepaid-custom'"))
            standard=int(sql("SELECT id FROM pays WHERE hash_id='prepaid-standard'"))
            assert ok('legacy_reserve',pay=custom)
            assert ok('legacy_reserve',pay=standard)
            assert sql("SELECT ucount FROM server_info WHERE id=2")=='98'
            old=sql('SELECT id,userid,wallet FROM users ORDER BY id')
            oldp=sql('SELECT * FROM pays ORDER BY id')
            report=ok('audit')
            snap=report['snapshot']
            assert snap['owners']==3 and snap['sum_toman']==49400 and snap['zero']==1 and snap['negative']==1,report
            assert report['inventory']['historical_receipt_or_payment_claim']['count']==4
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
            assert sql('SELECT SUM(delta_toman) FROM wallet_entries')=='49400'
            assert sql('SELECT COUNT(*) FROM wallet_entries w JOIN users u ON u.id=w.user_row_id AND u.userid=w.userid WHERE w.balance_after=u.wallet')=='3'
            assert sql('SELECT id,userid,wallet FROM users ORDER BY id')==old
            assert sql('SELECT * FROM pays ORDER BY id')==oldp
            assert ok('open',expected=snap['sha256'])['replayed']
            case('signed_exact_openings_and_idempotent_rehearsal')
            # No old claim may become a new executable service command.
            assert sql('SELECT COUNT(*) FROM service_operations')=='0'
            assert sql(f"SELECT COUNT(*) FROM service_effects WHERE pay_row_id IN ({custom},{standard}) AND state='reserved'")== '2'
            assert sql("SELECT ucount FROM server_info WHERE id=2")=='98'
            assert sql('SELECT * FROM pays ORDER BY id')==oldp
            assert sql('SELECT id,userid,wallet FROM users ORDER BY id')==old
            assert not ok('audit')['legacy_claims_clear']
            case('historical_paid_claims_preserved_not_adopted')
            sql("UPDATE users SET wallet=wallet+10 WHERE userid=9002")
            reject('open','cutover_snapshot_changed',expected=snap['sha256'])
            reject('open','cutover_open_reconciliation',expected=ok('snapshot')['sha256'])
            assert sql('SELECT COUNT(*) FROM wallet_entries')=='3'
            case('never_reopen_changed_balances')
    report=dict(complete=True,cases=len(checks),checks=checks,owned_socket_mariadb=True,external_requests=0)
    if args.report:args.report.write_text(json.dumps(report,indent=2)+'\n')
    print(json.dumps(report))

if __name__=='__main__':main()
