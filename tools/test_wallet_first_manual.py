#!/usr/bin/env python3
"""Manual issuer and actual API evidence acquisition -> common Wallet owner."""
from __future__ import annotations

import argparse
from datetime import datetime, timezone
import json
from pathlib import Path
import subprocess
import threading

import test_access_state as owned
import test_post_v9_api_payment_evidence as evidence

ROOT=Path(__file__).resolve().parents[1]
DB=owned.NAMES[1]
PHP=evidence.DRIVER[:evidence.DRIVER.index("switch($spec['op'])")].replace(
    'mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);',
    'mysqli_report(($argv[5]??"strict")==="strict"?MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT:MYSQLI_REPORT_OFF);'
)+r"""
require_once dirname($argv[1]).'/ManualReceipt.php';
require_once dirname($argv[1]).'/ApiPayments.php';
$GLOBALS['admin']=9001;
$GLOBALS['paymentKeys']=$keys+['bankAccount'=>'fixture-bank','holderName'=>'fixture-holder',
    'giftCardUrl'=>'https://fixture.invalid/gift','manualUsdtNetwork'=>'ERC20',
    'manualUsdtWalletERC20'=>'0x'.str_repeat('1',40),'manualUsdtRateToman'=>100000,
    'manualTrxWallet'=>'TJRabPrwbZy45sbavfcjinPJC18kjpRTv8','manualTrxRateToman'=>10000];
$GLOBALS['botState']=$states+['cartToCartState'=>'on','giftCardState'=>'on','manualUsdtState'=>'on'];
if (!($spec['api']??false)) {$GLOBALS['botState']['smsApiVerifyState']='off';$GLOBALS['botState']['giftApiVerifyState']='off';}
try {
    $result=match ($spec['op']) {
        'issue'=>achbotManualReceiptIssue($db,$id,(int)($spec['owner']??1001),$method,$spec['service']??null,$spec['network']??null),
        'begin'=>achbotManualBeginEvidence($db,$pay,$method,(int)($spec['owner']??1001),$spec['file']),
        'finish'=>achbotManualFinishEvidence($db,$pay['hash_id'],$spec['file'],$spec['token'],222,9001),
        'approve'=>achbotCheckoutSettleReceipt($db,$id,(int)($spec['owner']??1001),$spec['basis']??'manual',[],(int)($spec['actor']??9001),9001),
        'check'=>achbotManualReceiptCheckApi($db,$id),
        'quote'=>achbotServiceIssueLocked($db,1001,'fixture',$spec['reference'],$spec['request']),
        default=>throw new RuntimeException('fixture_unknown_op'),
    };
    echo json_encode(['ok'=>true,'result'=>$result],JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    $db->rollback();echo json_encode(['ok'=>false,'reason'=>$e->getMessage()],JSON_THROW_ON_ERROR);
}
"""
# The quote operation must own its SQL transaction just like the real route.
PHP=PHP.replace("    $result=match ($spec['op']) {","    if ($spec['op']==='quote') $db->begin_transaction();\n    $result=match ($spec['op']) {").replace(
    "    echo json_encode(['ok'=>true", "    if ($spec['op']==='quote' && !$db->commit()) throw new RuntimeException('fixture_commit');\n    echo json_encode(['ok'=>true")


def main():
    ap=argparse.ArgumentParser(description=__doc__)
    ap.add_argument('--run-disposable',required=True,action='store_true')
    ap.add_argument('--report',type=Path)
    args=ap.parse_args();cases=[]
    with owned.owned_database() as (work,env):
        cli=['mariadb','--no-defaults','--protocol=SOCKET','--socket='+str(work/'mysql.sock'),'-uroot','--batch','--skip-column-names',DB]
        def sql(q):
            return subprocess.run(cli,input="SET time_zone='+00:00';"+q,text=True,env=env,check=True,capture_output=True,timeout=20).stdout.strip()
        for m in ('001_marzban_panel_group_id.sql','003_payment_attempts.sql','004_automatic_renewal_attempts.sql','007_service_effects.sql',
                  '011_provider_identity.sql','012_shared_provider_storage.sql','014_api_payment_evidence.sql',
                  '022_gateway_review_archive.sql','023_scoped_payment_lock_indexes.sql','024_wallet_entries.sql',
                  '025_wallet_receipt_credit.sql','026_service_operations.sql',
                  '008_referral_awards.sql','020_payment_followup_intents.sql','029_service_purchase_referral.sql',
                  '030_service_followup_sources.sql','031_service_followup_attempts.sql'):
            sql((ROOT/'migrations/post-v9'/m).read_text())
        sql("""INSERT INTO setting(type,value) VALUES ('BOT_STATES','{"cartToCartAutoAcceptState":"on","cartToCartAutoAcceptTime":5,"cartToCartAutoAcceptType":2}');
            INSERT INTO server_config(id,panel_url,ip,sni,header_type,request_header,response_header,security,tlsSettings,type,username,password)
            VALUES (2,'https://fixture.invalid','127.0.0.1','','none','','','none','{}','pasarguard','fixture','fixture');
            INSERT INTO server_info(id,title,ucount,remark,flag,active,state) VALUES(2,'Fixture','100','fixture','',1,1);
            UPDATE server_config SET provider_connection_id=2,provider_instance_key=REPEAT('a',32),provider_version='5.5.1',provider_enabled=1 WHERE id=2;
            INSERT INTO server_plans(id,fileid,catid,server_id,inbound_id,acount,title,protocol,days,volume,type,price,descr,pic,active,step,date,custom_sni,provider_group_ids)
            VALUES(7,'7',1,2,0,6,'Fixture','vless',30,2,'normal',100,'description','',1,0,'fixture',NULL,'[1,2]');""")
        server=evidence.Server(lambda:sql('SET innodb_lock_wait_timeout=1; UPDATE payment_attempts SET last_checked_at=NOW()'))
        thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
        driver=work/'manual.php';driver.write_text(PHP)
        base='http://127.0.0.1:'+str(server.server_port)
        try:
            def run(mode,op,pid=0,**kw):
                p=subprocess.run(['php','-n','-d','extension=mysqlnd','-d','extension=mysqli','-d','extension=curl',
                    '-d','allow_url_fopen=0','-d','disable_functions=exec,shell_exec,system,passthru,popen,proc_open,mail',
                    str(driver),str(ROOT/'src/Finance/PaymentEvidence.php'),str(work/'mysql.sock'),DB,
                    json.dumps(dict(op=op,pay_id=pid,base=base,**kw)),mode],
                    env=env,capture_output=True,text=True,timeout=25)
                assert p.returncode==0,(p.returncode,p.stdout,p.stderr)
                assert all(x.startswith('[AChBot] ') or x.startswith('achbot_') for x in p.stderr.splitlines()),p.stderr
                return json.loads(p.stdout)
            def result(mode,op,pid=0,**kw):
                r=run(mode,op,pid,**kw);assert r['ok'],r
                return r['result']
            def seed(name,price=1000,kind='INCREASE_WALLET'):
                sql(f"INSERT INTO pays(hash_id,user_id,type,price,request_date,state) VALUES ('{name}',1001,'{kind}',{price},UNIX_TIMESTAMP()-600,'pending')")
                return int(sql(f"SELECT id FROM pays WHERE hash_id='{name}'"))
            def balance():return int(sql('SELECT wallet FROM users WHERE userid=1001'))
            def submit(mode,pid,method,file,api=False):
                token=result(mode,'begin',pid,method=method,file=file,api=api)
                assert isinstance(token,str) and len(token)==32,token
                assert result(mode,'finish',pid,method=method,file=file,token=token,api=api) is True
            def roles(pid):
                return sql(f"SELECT GROUP_CONCAT(role ORDER BY role) FROM payment_followup_intents WHERE pay_row_id={pid} AND service_operation_id IS NULL AND stage='wallet_credit'")
            for mode in ('strict','off'):
                def case(name):cases.append(mode+':'+name)
                for method in ('bank_transfer','gift_card','manual_usdt','manual_trx'):
                    pid=seed(mode+'-'+method)
                    r=result(mode,'issue',pid,method=method)
                    assert r['status']=='issued' and result(mode,'issue',pid,method=method)==r,r
                    if method=='bank_transfer':assert 1100<=float(r['amount'])<=1999
                    if method=='gift_card':assert float(r['amount'])==1000
                    if method=='manual_usdt':assert float(r['amount'])==0.01
                    if method=='manual_trx':assert float(r['amount'])==0.1
                    assert run(mode,'issue',pid,method=method,owner=9002)['ok'] is False
                    assert result(mode,'begin',pid,method=method,file='fixture-photo',owner=9002) is None
                    submit(mode,pid,method,'fixture-photo')
                    b=balance()
                    assert run(mode,'approve',pid,actor=1001)['ok'] is False and balance()==b
                    r=result(mode,'approve',pid,actor=9001)
                    assert r['funding']=='wallet_only' and balance()==b+1000 and not r['credit']['replayed'],r
                    assert result(mode,'approve',pid)['credit']['replayed'] and balance()==b+1000
                    assert roles(pid)=='customer,receipt_primary,service_admin',roles(pid)
                    case(method+'_actual_issue_and_manual_approval')
                pid=seed(mode+'-orphan',kind='BUY_SUB')
                assert run(mode,'issue',pid,method='bank_transfer')['reason']=='checkout_service_invoice_missing'
                assert sql(f'SELECT COUNT(*) FROM payment_attempts WHERE pay_row_id={pid}')=='0'
                case('no_orphan_service_invoice')
                pid=seed(mode+'-timer')
                result(mode,'issue',pid,method='bank_transfer')
                submit(mode,pid,'bank_transfer','fixture-timer')
                b=balance()
                assert result(mode,'approve',pid,basis='bank_timer',actor=0)['funding']=='wallet_only' and balance()==b+1000
                assert roles(pid)=='customer,receipt_primary',roles(pid)
                case('actual_explicit_timer')
                # The real bank checker must find and consume exactly the
                # provider-owned original SMS, then use the common funding
                # transaction even when the local service is invalidated.
                q=dict(kind='BUY',variant='standard',price=100,server_id=2,plan_id=7,order_id=0,
                    quantity=1,days=30,volume=2,remark='fixture',uuid='',naming_mode='fixed',explicit_name=True)
                oid=result(mode,'quote',reference=mode+'-buy',request=q)
                pid=seed(mode+'-sms',price=100,kind='BUY_SUB')
                r=result(mode,'issue',pid,method='bank_transfer',api=True,service=oid)
                submit(mode,pid,'bank_transfer','fixture-sms',api=True)
                ident='100' if mode=='strict' else '200'
                now=datetime.now(timezone.utc).replace(microsecond=0)
                server.messages[ident]=dict(id=int(ident),public_uid='UID'+ident,provider='BANK_FIXTURE',provider_sms_id='sms'+ident,
                    sender='BANK',mobile_source='phone',bank_key='fixture',rule_id=7,amount=int(float(r['amount'])),display_unit='toman',
                    sms_date=now.strftime('%Y-%m-%d'),sms_time=now.strftime('%H:%M:%S'),created_at=now.strftime('%Y-%m-%d %H:%M:%S'),status='ready')
                sql('UPDATE server_info SET active=0 WHERE id=2')
                b=balance();server.fail_once=True
                assert result(mode,'check',pid)=='pending' and balance()==b
                sql(f'UPDATE payment_attempts SET evidence_next_check_at=NOW() WHERE pay_row_id={pid}')
                assert result(mode,'check',pid)=='settled' and balance()==b+100
                assert result(mode,'check',pid)=='settled' and balance()==b+100 and server.consumes[ident]==1
                assert sql(f'SELECT wallet_funding_result FROM payment_attempts WHERE pay_row_id={pid}')=='request_invalidated'
                sql('UPDATE server_info SET active=1 WHERE id=2')
                assert roles(pid)=='customer,receipt_primary',roles(pid)
                case('actual_bank_scan_lost_remote_ack_service_invalidated')
                # A receipt already submitted before service supersession is
                # still real money. Never revive the invalidated service.
                oid=result(mode,'quote',reference=mode+'-superseded',request=q)
                pid=seed(mode+'-superseded',price=100,kind='BUY_SUB')
                issued=result(mode,'issue',pid,method='bank_transfer',api=True,service=oid)
                submit(mode,pid,'bank_transfer','fixture-late-sms',api=True)
                sql(f"UPDATE pays SET state='service_superseded' WHERE id={pid}")
                ident='101' if mode=='strict' else '201'
                now=datetime.now(timezone.utc).replace(microsecond=0)
                server.messages[ident]=dict(id=int(ident),public_uid='UID'+ident,provider='BANK_FIXTURE',provider_sms_id='sms'+ident,
                    sender='BANK',mobile_source='phone',bank_key='fixture',rule_id=7,amount=int(float(issued['amount'])),display_unit='toman',
                    sms_date=now.strftime('%Y-%m-%d'),sms_time=now.strftime('%H:%M:%S'),created_at=now.strftime('%Y-%m-%d %H:%M:%S'),status='ready')
                b=balance()
                assert result(mode,'check',pid)=='settled' and balance()==b+100
                assert result(mode,'check',pid)=='settled' and balance()==b+100 and server.consumes[ident]==1
                assert sql(f"SELECT wallet_funding_result FROM payment_attempts WHERE pay_row_id={pid}")=='request_invalidated'
                assert sql(f"SELECT COUNT(*) FROM wallet_entries WHERE source_kind='service_debit' AND source_ref='{oid}'")=='0'
                case('superseded_submitted_api_receipt_credits_without_service')
                pid=seed(mode+'-gift-api',price=1000)
                result(mode,'issue',pid,method='gift_card',api=True)
                code=evidence.fixture_gift_code(mode+'-gift-api')
                server.card=dict(card_uuid='CARD_'+mode,status='active',game_key='fixture',redeem_value_id=42,
                    redeem_amount=1000,display_amount=1000,face_value=10,currency_label='fixture',order_id=1234,
                    order_number='WEB-2026-0042',order_created_at='2026-09-09T07:00:00+00:00',created_at='2026-09-10T08:05:00+00:00')
                submit(mode,pid,'gift_card',code,api=True)
                b=balance()
                # Fail the actual local money insertion after successful
                # external consume. The protected proof must survive.
                sql("CREATE TRIGGER fixture_credit_fail BEFORE INSERT ON wallet_entries FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture'")
                try:
                    r=run(mode,'check',pid)
                    assert not r['ok'] and balance()==b,r
                finally:sql('DROP TRIGGER fixture_credit_fail')
                sql(f'UPDATE payment_attempts SET review_archived_at=NOW() WHERE pay_row_id={pid}')
                assert result(mode,'check',pid)=='settled' and balance()==b+1000
                assert result(mode,'check',pid)=='settled' and balance()==b+1000
                assert roles(pid)=='customer',roles(pid)
                case('actual_gift_proven_local_rollback_then_archive')
            assert sum(server.consumes.values())==4 and server.gift_consumes==2
        finally:server.shutdown();server.server_close();thread.join(timeout=5)
    report=dict(complete=True,cases=len(cases),checks=cases,owned_socket_mariadb=True,
        actual_protected_api_http=True,actual_manual_receipt_ack_contract=True,external_requests=0,production_effects=0)
    if args.report:args.report.write_text(json.dumps(report,indent=2)+'\n')
    print(json.dumps(report))
if __name__=='__main__':main()
