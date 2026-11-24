#!/usr/bin/env python3
"""Real PHP/MySQLi, isolated MariaDB: original service funding, never Native I/O."""
from __future__ import annotations

import argparse
from concurrent.futures import ThreadPoolExecutor
import json
from pathlib import Path
import subprocess

import test_access_state as owned

ROOT = Path(__file__).resolve().parents[1]
DB = owned.NAMES[1]
PHP = r'''<?php
declare(strict_types=1);
mysqli_report($argv[4]==='strict'?MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT:MYSQLI_REPORT_OFF);
require $argv[1].'/src/Finance/FundingCheckout.php';
$db=new mysqli('localhost','root','',$argv[3],0,$argv[2]);
$c=json_decode($argv[5],true,512,JSON_THROW_ON_ERROR);$out=[];
try {
    if (!$db->begin_transaction()) throw new RuntimeException('fixture_begin');
    foreach ($c['commands'] as $x) {
        $user=(int)($x['user']??1001);
        $out[]=match ($x['op']) {
            'issue'=>achbotServiceIssueLocked($db,$user,'fixture',$x['ref'],$x['quote']),
            'fund'=>achbotServiceFundLocked($db,$user,$x['id']),
            'cancel'=>achbotServiceCancelUnfundedLocked($db,$user,$x['id']),
            'pin'=>achbotCheckoutPinReceiptLocked($db,$x['pay'],$user),
            'settle'=>achbotCheckoutSettleReceiptLocked($db,$x['pay'],$user,'nextpay',$x['proof']),
            'move'=>achbotWalletApplyLocked($db,$user,'fixture',$x['ref'],0,$x['delta']),
        };
    }
    if ($c['rollback']??false) {
        if (!$db->rollback()) throw new RuntimeException('fixture_rollback');
    } else {
        if (!$db->commit()) throw new RuntimeException('fixture_commit');
        if ($c['lost_ack']??false) exit(19);
    }
    echo json_encode(['ok'=>true,'out'=>$out],JSON_THROW_ON_ERROR);
} catch(Throwable $e) {
    $db->rollback();
    echo json_encode(['ok'=>false,'reason'=>$e->getMessage()],JSON_THROW_ON_ERROR);
}
'''

def main():
    ap=argparse.ArgumentParser(description=__doc__)
    ap.add_argument('--run-disposable',required=True,action='store_true')
    ap.add_argument('--report',type=Path)
    args=ap.parse_args()
    cases=[]
    with owned.owned_database() as (work,env):
        client=['mariadb','--no-defaults','--protocol=SOCKET','--socket='+str(work/'mysql.sock'),'-uroot','--batch','--skip-column-names',DB]
        def sql(query):
            p=subprocess.run(client,input=query,text=True,env=env,capture_output=True,timeout=30)
            assert p.returncode==0,(p.stderr,query[:200])
            return p.stdout.strip()
        for m in ('001_marzban_panel_group_id.sql','003_payment_attempts.sql',
                  '007_service_effects.sql','011_provider_identity.sql','012_shared_provider_storage.sql',
                  '014_api_payment_evidence.sql','022_gateway_review_archive.sql',
                  '023_scoped_payment_lock_indexes.sql','024_wallet_entries.sql',
                  '025_wallet_receipt_credit.sql','026_service_operations.sql'):
            sql((ROOT/'migrations/post-v9'/m).read_text())
        sql("""INSERT INTO server_config(id,panel_url,ip,sni,header_type,request_header,response_header,security,tlsSettings,type,username,password)
            VALUES (2,'https://fixture.invalid','127.0.0.1','','none','','','none','{}','pasarguard','fixture','fixture'),
                   (3,'http://127.0.0.1:9','127.0.0.1','','none','','','none','{}','marzban','fixture','fixture');
            INSERT INTO server_info(id,title,ucount,remark,flag,active,state) VALUES
                (2,'Fixture PG','100','pgfixture','',1,1),(3,'Retired M','100','fixture','',1,1);
            UPDATE server_config SET provider_connection_id=2,provider_instance_key=REPEAT('a',32),
                provider_version='5.5.1',provider_enabled=1 WHERE id=2;
            INSERT INTO server_plans(id,fileid,catid,server_id,inbound_id,acount,title,protocol,days,volume,type,price,descr,pic,active,step,date,custom_sni,provider_group_ids)
            VALUES (7,'7',1,2,0,6,'Fixture PG','vless',30,2,'normal',100,'description','',1,0,'fixture',NULL,'[1,2]'),
                   (8,'8',1,3,0,6,'Retired M','vless',30,2,'normal',100,'description','',1,0,'fixture','{"inbounds":{"vless":["inbound"]}}',NULL);
            INSERT INTO orders_list(id,userid,token,transid,fileid,server_id,inbound_id,remark,uuid,expire_date,link,amount,status,date,
                provider_effect_id,provider_remote_user_id,provider_remote_created_at)
            VALUES (91,1001,REPEAT('a',30),'fixture',7,2,0,'fixture-user','fixture-uuid',1800000100,'["vless://fixture"]',100,1,'fixture',
                91,17,'2026-10-26T05:00:00Z');
            INSERT INTO service_effects(id,source_kind,source_ref,actor_userid,ordinal,reserved_count,operation_id,effect_kind,
                target_server_id,target_key,endpoint_sha256,plan_id,remote_username,requested_expire,requested_bytes,request_sha256,
                state,order_id,verified_at,provider_snapshot,provider_reference_id,provider_connection_id,provider_remote_user_id,
                provider_remote_created_at,provider_operation_marker,provider_entitlement_snapshot,provider_state,provider_verified_at)
            VALUES (91,'admin_bulk','fixture-existing',1001,1,1,REPEAT('b',32),'create',
                2,CONCAT('pg:',REPEAT('a',32)),SHA2('https://fixture.invalid',256),7,'fixture-user',1800000100,2147483648,REPEAT('c',64),
                'local_recorded',91,UTC_TIMESTAMP(),
                JSON_OBJECT('provider','pasarguard','panel_id',2,'instance_key',REPEAT('a',32),
                  'endpoint_sha256',SHA2('https://fixture.invalid',256),'group_ids',JSON_ARRAY(1,2)),
                91,2,17,'2026-10-26T05:00:00Z',CONCAT('achbot:v1:',REPEAT('b',32)),
                '{"data_limit":2147483648,"expire":1800000100,"group_ids":[1,2],"status":"active"}','linked',UTC_TIMESTAMP());
        """)
        driver=work/'funding.php';driver.write_text(PHP)
        def php(mode,*commands,**kw):
            p=subprocess.run(['php','-n','-d','extension=mysqlnd','-d','extension=mysqli',
                '-d','allow_url_fopen=0','-d','disable_functions=exec,shell_exec,system,passthru,popen,proc_open,mail',
                str(driver),str(ROOT),str(work/'mysql.sock'),DB,mode,
                json.dumps(dict(commands=commands,**kw))],env=env,text=True,capture_output=True,timeout=30)
            if kw.get('lost_ack'):
                assert p.returncode==19 and not p.stdout,(p.returncode,p.stdout,p.stderr)
                return None
            assert p.returncode==0 and not p.stderr,(p.returncode,p.stderr)
            return json.loads(p.stdout)
        def snap():
            return tuple(sql(q) for q in (
                'SELECT userid,wallet FROM users ORDER BY id',
                'SELECT id,source_kind,source_ref,delta_toman FROM wallet_entries ORDER BY id',
                'SELECT id,ucount FROM server_info ORDER BY id',
                'SELECT id,stage,COALESCE(debit_entry_id,0),reserved_count FROM service_operations ORDER BY id',
                'SELECT id,COALESCE(wallet_credit_entry_id,0),COALESCE(wallet_funding_result,"") FROM payment_attempts ORDER BY id'))
        def balance():return int(sql('SELECT wallet FROM users WHERE userid=1001'))
        def capacity(sid=2):return int(sql(f'SELECT ucount FROM server_info WHERE id={sid}'))
        def cmd(op,**kw):return dict(op=op,**kw)
        def quote(**kw):
            return dict(dict(kind='BUY',variant='standard',price=100,server_id=2,plan_id=7,
                order_id=0,quantity=1,days=30,volume=2,remark='fixture-user-12345',
                uuid='',naming_mode='digits',explicit_name=False),**kw)
        def issue(mode,ref,**kw):
            r=php(mode,cmd('issue',ref=ref,quote=quote(**kw)))
            assert r['ok'],(ref,r)
            return r['out'][0]
        def fund(mode,id,expected='funded'):
            r=php(mode,cmd('fund',id=id))
            assert r=={'ok':True,'out':[expected]},r
        def invoice(mode,ref,oid=None,amount=100):
            sql(f"""INSERT INTO pays(hash_id,user_id,type,price,request_date,state,payid)
                VALUES ('{ref}',1001,'INCREASE_WALLET',{amount},UNIX_TIMESTAMP(),'pending','inv-{ref}')""")
            pid=int(sql(f"SELECT id FROM pays WHERE hash_id='{ref}'"))
            sql(f"""INSERT INTO payment_attempts(pay_row_id,provider,order_hash,provider_reference,
                quote_amount,quote_currency,local_amount_toman,checkout_url,wallet_invoice_sha256,service_operation_id)
                VALUES ({pid},'nextpay','{ref}','inv-{ref}',{amount},'toman',{amount},'https://fixture.invalid/checkout','',{oid or 'NULL'})""")
            r=php(mode,cmd('pin',pay=pid));assert r['ok'],r
            return cmd('settle',pay=pid,proof=dict(reference='inv-'+ref,status=0,amount=amount))
        def fail(mode,command,reason=None):
            before=snap();r=php(mode,command)
            assert not r['ok'] and (reason is None or r['reason']==reason),r
            assert snap()==before,(command,r)
        for mode in ('strict','off'):
            def case(name):cases.append(mode+':'+name)
            b=balance();c=capacity()
            oid=issue(mode,mode+'-buy',quantity=3)
            assert issue(mode,mode+'-buy',quantity=3)==oid
            fail(mode,cmd('issue',ref=mode+'-buy',quote=quote(quantity=2)),'service_command_conflict')
            fail(mode,cmd('fund',id=oid,user=9002),'service_operation_owner')
            fund(mode,oid);fund(mode,oid,'already_funded')
            assert (balance(),capacity())==(b-100,c-3);case('bulk_once')
            fail(mode,cmd('cancel',id=oid),'service_cancel_has_funding');case('funded_cancel_denied')
            oid=issue(mode,mode+'-custom',variant='custom',days=17,volume=5,explicit_name=True,remark='custom_fixture')
            fund(mode,oid);case('custom')
            oid=issue(mode,mode+'-free',price=0)
            b=balance();fund(mode,oid);fund(mode,oid,'already_funded')
            assert balance()==b and sql(f'SELECT debit_entry_id FROM service_operations WHERE id={oid}')=='NULL';case('zero')
            for kind,variant,days,volume,order,remark,uuid in (
                ('RENEW','managed',30,2,91,'fixture-user','fixture-uuid'),
                ('ADD_TIME','managed',3,0,91,'fixture-user','fixture-uuid'),
                ('ADD_VOLUME','managed',0,1,91,'fixture-user','fixture-uuid')):
                oid=issue(mode,mode+'-'+kind+'-'+variant,kind=kind,variant=variant,days=days,
                    volume=volume,order_id=order,remark=remark,uuid=uuid)
                b=balance();c=capacity();fund(mode,oid);fund(mode,oid,'already_funded')
                assert (balance(),capacity())==(b-100,c);case(kind+'-'+variant)
            fail(mode,cmd('issue',ref=mode+'-retired-buy',quote=quote(server_id=3,plan_id=8)),
                 'service_server_unavailable');case('retired_marzban_buy_rejected')
            fail(mode,cmd('issue',ref=mode+'-retired-renew',quote=quote(kind='RENEW',variant='external',
                 server_id=3,plan_id=8,remark='external-user',uuid='external-uuid')),
                 'service_quote_kind');case('retired_external_renew_rejected')
            oid=issue(mode,mode+'-price')
            sql('UPDATE server_plans SET price=99999,days=45,volume=9 WHERE id=7')
            b=balance();fund(mode,oid)
            assert balance()==b-100
            sql('UPDATE server_plans SET price=100,days=30,volume=2 WHERE id=7');case('immutable_quote')
            oid=issue(mode,mode+'-no-funds',price=2147483647)
            b=balance();c=capacity();fund(mode,oid,'insufficient_funds')
            assert (balance(),capacity())==(b,c);case('insufficient')
            oid=issue(mode,mode+'-receipt',quantity=2);settle=invoice(mode,mode+'-receipt',oid)
            b=balance();c=capacity()
            first=php(mode,settle);assert first['ok'] and first['out'][0]['funding']=='funded',first
            assert (balance(),capacity())==(b,c-2)
            second=php(mode,settle);assert second['out'][0]['credit']['replayed'] and second['out'][0]['funding']=='funded',second
            assert (balance(),capacity())==(b,c-2);case('receipt_atomic')
            oid=issue(mode,mode+'-capacity',quantity=1000);settle=invoice(mode,mode+'-capacity',oid)
            b=balance();c=capacity()
            first=php(mode,settle);assert first['out'][0]['funding']=='capacity_unavailable',first
            assert (balance(),capacity())==(b+100,c)
            sql('UPDATE server_info SET ucount=2000 WHERE id=2')
            second=php(mode,settle);assert second['out'][0]['funding']=='capacity_unavailable',second
            assert (balance(),capacity())==(b+100,2000)
            sql(f'UPDATE server_info SET ucount={c} WHERE id=2');case('late_replay_not_refund_or_debit')
            oid=issue(mode,mode+'-cancel');settle=invoice(mode,mode+'-cancel',oid)
            assert php(mode,cmd('cancel',id=oid))['ok']
            b=balance();c=capacity()
            r=php(mode,settle);assert r['out'][0]['funding']=='request_invalidated',r
            assert (balance(),capacity())==(b+100,c);case('cancelled_receipt_preserved')
            oid=issue(mode,mode+'-changed');settle=invoice(mode,mode+'-changed',oid)
            sql("UPDATE server_config SET panel_url='https://changed.invalid' WHERE id=2")
            b=balance();c=capacity();r=php(mode,settle)
            assert r['out'][0]['funding']=='request_invalidated' and (balance(),capacity())==(b+100,c),r
            sql("UPDATE server_config SET panel_url='https://fixture.invalid' WHERE id=2");case('changed_panel_receipt_preserved')
            oid=issue(mode,mode+'-retired-after-quote');settle=invoice(mode,mode+'-retired-after-quote',oid)
            sql("UPDATE server_config SET type='marzban' WHERE id=2")
            b=balance();c=capacity();r=php(mode,settle)
            assert r['ok'] and r['out'][0]['funding']=='request_invalidated' and (balance(),capacity())==(b+100,c),r
            assert php(mode,settle)['out'][0]['credit']['replayed']
            assert (balance(),capacity())==(b+100,c)
            sql("UPDATE server_config SET type='pasarguard' WHERE id=2")
            case('retired_target_receipt_preserved')
            oid=issue(mode,mode+'-rollback');settle=invoice(mode,mode+'-rollback',oid)
            before=snap();r=php(mode,settle,rollback=True);assert r['ok'] and snap()==before,r
            # Trigger a failure AFTER the credit and capacity update; all
            # journal/receipt/funding changes must roll back together.
            sql("CREATE TRIGGER fixture_fail_funding BEFORE UPDATE ON service_operations FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture'")
            try:fail(mode,settle)
            finally:sql('DROP TRIGGER fixture_fail_funding')
            assert snap()==before
            assert php(mode,settle)['out'][0]['funding']=='funded';case('atomic_failure')
            oid=issue(mode,mode+'-lost');settle=invoice(mode,mode+'-lost',oid)
            b=balance();c=capacity();php(mode,settle,lost_ack=True)
            r=php(mode,settle);assert r['out'][0]['credit']['replayed'] and r['out'][0]['funding']=='funded',r
            assert (balance(),capacity())==(b,c-1);case('commit_ack_lost')
            oid=issue(mode,mode+'-race');settle=invoice(mode,mode+'-race',oid)
            b=balance();c=capacity()
            with ThreadPoolExecutor(4) as pool:
                rs=list(pool.map(lambda _:php(mode,settle),range(4)))
            assert all(x['ok'] and x['out'][0]['funding']=='funded' for x in rs),rs
            assert sum(not x['out'][0]['credit']['replayed'] for x in rs)==1
            assert (balance(),capacity())==(b,c-1);case('parallel_receipt')
            oid=issue(mode,mode+'-paid-other');settle=invoice(mode,mode+'-paid-other',oid)
            fund(mode,oid);b=balance();c=capacity()
            assert php(mode,settle)['out'][0]['funding']=='already_funded'
            assert (balance(),capacity())==(b+100,c);case('late_second_payment')
            settle=invoice(mode,mode+'-topup');b=balance()
            assert php(mode,settle)['out'][0]['funding']=='wallet_only'
            assert balance()==b+100;case('topup')
    report=dict(complete=True,cases=len(cases),case_names=cases,php_version=subprocess.check_output(['php','-r','echo PHP_VERSION;'],text=True))
    if args.report:args.report.write_text(json.dumps(report,ensure_ascii=False,indent=2)+'\n')
    print(json.dumps(report,ensure_ascii=False))

if __name__=='__main__':main()
