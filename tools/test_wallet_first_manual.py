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
require_once dirname($argv[1]).'/GatewayReview.php';
function sendPhoto(...$args) {
    $GLOBALS['review_sends'][]=['kind'=>'photo','args'=>$args];
    return (object)['ok'=>!($GLOBALS['spec']['fail_send']??false),'result'=>(object)['message_id'=>333]];
}
function sendMessage(...$args) {
    $GLOBALS['review_sends'][]=['kind'=>'message','args'=>$args];
    return (object)['ok'=>!($GLOBALS['spec']['fail_send']??false),'result'=>(object)['message_id'=>333]];
}
function answerCallbackQuery(...$args) {$GLOBALS['review_alerts'][]=$args;}
function editText(...$args) {
    $GLOBALS['review_sends'][]=['kind'=>'edit','args'=>$args];
    return (object)['ok'=>true];
}
function fixtureReview(mysqli $db,array $spec): array {
    $connection=$db;$admin=9001;$from_id=(int)($spec['actor']??9001);
    $chat_id=(int)($spec['chat']??$from_id);$message_id=111;$callbackId='test-review';
    $data=$spec['callback'];$text='';$userInfo=['step'=>'none'];
    $GLOBALS['from_id']=$from_id;$GLOBALS['chat_id']=$chat_id;$GLOBALS['data']=$data;$GLOBALS['callbackId']=$callbackId;
    $GLOBALS['review_sends']=[];$GLOBALS['review_alerts']=[];
    require dirname($GLOBALS['argv'][1]).'/Routes/ManualReceipts.php';
    return ['sends'=>$GLOBALS['review_sends'],'alerts'=>$GLOBALS['review_alerts']];
}

$GLOBALS['admin']=9001;$GLOBALS['connection']=$db;
$GLOBALS['paymentKeys']=$keys+['bankAccount'=>'fixture-bank','holderName'=>'fixture-holder',
    'giftCardUrl'=>'https://fixture.invalid/gift',
    'manualUsdtWalletERC20'=>'0x'.str_repeat('1',40),'manualUsdtRateToman'=>100000,
    'manualTrxWallet'=>'TJRabPrwbZy45sbavfcjinPJC18kjpRTv8','manualTrxRateToman'=>10000];
$GLOBALS['botState']=$states+['cartToCartState'=>'on','giftCardState'=>'on','manualUsdtState'=>'on','rewardChannel'=>'@fixture_report'];
if (!($spec['api']??false)) {$GLOBALS['botState']['smsApiVerifyState']='off';$GLOBALS['botState']['giftApiVerifyState']='off';}
try {
    $result=match ($spec['op']) {
        'attention'=>achbotGatewayAttentionCaseOpen($db,$id),
        'maintain'=>achbotApiPaymentMaintain($db,(int)($spec['limit']??1)),
        'duplicate_guard'=>(static function() use ($db,$pay,$spec) {
            $GLOBALS['botState']=array_replace($GLOBALS['botState'],$spec['policy']??[]);
            return duplicateReceiptGuard($db,$pay,(int)($spec['clock']??time()));
        })(),
        'timer_sweep'=>(static function() use ($db) {
            $connection=$db;$botState=['cartToCartAutoAcceptState'=>'on'];
            require dirname($GLOBALS['argv'][1]).'/AutomaticReceipts.php';
            return true;
        })(),
        'reject'=>(static function() use ($db,$id,$pay,$spec) {
            $db->begin_transaction();
            $r=achbotCheckoutRejectReceiptLocked($db,$id,(int)$pay['user_id'],(int)($spec['actor']??9001),9001,$pay['hash_id']);
            $db->commit();return $r['state'];
        })(),
        'outcome'=>achbotCheckoutReceiptOutcome($db,$id),
        'review'=>fixtureReview($db,$spec),
        'report'=>(static function() use ($db,$spec) {
            require_once dirname($GLOBALS['argv'][1]).'/FollowupOutbox.php';
            $GLOBALS['review_sends']=[];
            $status=achbotFollowupDispatchOne($db,(int)$spec['report']);
            return ['status'=>$status,'sends'=>$GLOBALS['review_sends']];
        })(),
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
    with owned.owned_database(ROOT/'database/current-schema.sql') as (work,env):
        cli=['mariadb','--no-defaults','--protocol=SOCKET','--socket='+str(work/'mysql.sock'),'-uroot','--batch','--skip-column-names',DB]
        def sql(q):
            return subprocess.run(cli,input="SET time_zone='+00:00';"+q,text=True,env=env,check=True,capture_output=True,timeout=20).stdout.strip()
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
                    assert roles(pid)=='customer,receipt_primary,service_admin,service_channel',roles(pid)
                    case(method+'_actual_issue_and_manual_approval')
                # The approving operator receives a standalone report even
                # when general unsolicited notifications are disabled.
                sql("INSERT INTO setting(type,value) VALUES ('ADMIN_NOTIFY_9002','off')")
                pid=seed(mode+'-operator-report')
                result(mode,'issue',pid,method='bank_transfer')
                submit(mode,pid,'bank_transfer','fixture-operator-report')
                b=balance()
                assert result(mode,'approve',pid,actor=9002)['funding']=='wallet_only'
                assert balance()==b+1000
                targets=sql(f"SELECT CONCAT(role,':',recipient) FROM payment_followup_intents WHERE pay_row_id={pid} AND kind='send' ORDER BY role").splitlines()
                assert targets==['customer:1001','report_actor:9002','service_admin:9001','service_channel:@fixture_report'],targets
                for target in ('9001','9002','@fixture_report'):
                    rid=int(sql(f"SELECT id FROM payment_followup_intents WHERE pay_row_id={pid} AND recipient='{target}' AND kind='send'"))
                    report=result(mode,'report',pid,report=rid)
                    assert report['status']=='acknowledged' and len(report['sends'])==1,report
                    assert mode+'-operator-report' in report['sends'][0]['args'][0] and '9002' in report['sends'][0]['args'][0],report
                    assert result(mode,'report',pid,report=rid)['sends']==[]
                assert balance()==b+1000
                sql("DELETE FROM setting WHERE type='ADMIN_NOTIFY_9002'")
                case('actual_approver_owner_channel_reports_without_duplicate_money')
                # Real private recovery route, lost ACK and independent delivery.
                for method in ('bank_transfer','gift_card','manual_usdt','manual_trx'):
                    pid=seed(mode+'-recover-'+method)
                    result(mode,'issue',pid,method=method)
                    assert result(mode,'attention',pid) is False
                    token=result(mode,'begin',pid,method=method,file='fixture-original-photo')
                    assert token and result(mode,'attention',pid) is False
                    sql(f'UPDATE payment_attempts SET review_notice_claimed_at=NOW()-INTERVAL 3 MINUTE WHERE pay_row_id={pid}')
                    assert result(mode,'attention',pid) is True
                    b=balance();cb=f'attentionReceipt_{pid}'
                    assert not result(mode,'review',pid,callback=cb,actor=1001)['sends']
                    assert not result(mode,'review',pid,callback=cb,chat=1001)['sends']
                    assert result(mode,'begin',pid,method=method,file='replacement-photo') is None
                    lost=result(mode,'review',pid,callback=cb,fail_send=True)
                    assert len(lost['sends'])==1 and lost['sends'][0]['args'][0]=='fixture-original-photo',lost
                    assert sql(f'SELECT state FROM pays WHERE id={pid}')=='pending'
                    recovered=result(mode,'review',pid,callback=cb)
                    assert len(recovered['sends'])==1 and recovered['sends'][0]['args'][0]=='fixture-original-photo',recovered
                    assert sql(f'SELECT state FROM pays WHERE id={pid}')=='have_sent'
                    assert balance()==b and result(mode,'attention',pid)
                    assert result(mode,'approve',pid)['funding']=='wallet_only'
                    assert result(mode,'approve',pid)['credit']['replayed'] and balance()==b+1000
                    assert not result(mode,'review',pid,callback=cb)['sends']
                    assert result(mode,'attention',pid) is False
                    sql(f"UPDATE payment_followup_intents SET state='unknown' WHERE pay_row_id={pid} AND role='customer'")
                    assert result(mode,'attention',pid) is True
                    assert not result(mode,'review',pid,callback=f'gatewayReview_H_{pid}')['sends']
                    assert result(mode,'outcome',pid)=='wallet_credited'
                    case(method+'_lost_receipt_ack_recovery_and_independent_report')
                pid=seed(mode+'-recover-typed-gift')
                result(mode,'issue',pid,method='gift_card',api=True)
                code=evidence.fixture_gift_code(mode+'-recover-typed-gift')
                token=result(mode,'begin',pid,method='gift_card',file=code,api=True)
                assert token
                sql(f'UPDATE payment_attempts SET review_notice_claimed_at=NOW()-INTERVAL 3 MINUTE WHERE pay_row_id={pid}')
                recovered=result(mode,'review',pid,callback=f'attentionReceipt_{pid}',api=True)
                assert len(recovered['sends'])==1 and recovered['sends'][0]['kind']=='message' and code in recovered['sends'][0]['args'][0],recovered
                assert sql(f'SELECT state FROM pays WHERE id={pid}')=='have_sent'
                b=balance()
                assert result(mode,'approve',pid)['funding']=='wallet_only' and balance()==b+1000
                assert result(mode,'approve',pid)['credit']['replayed'] and balance()==b+1000
                case('typed_gift_lost_receipt_ack_recovery')
                pid=seed(mode+'-rejected-receipt')
                result(mode,'issue',pid,method='bank_transfer')
                token=result(mode,'begin',pid,file='original-photo')
                sql(f'UPDATE payment_attempts SET review_notice_claimed_at=NOW()-INTERVAL 3 MINUTE WHERE pay_row_id={pid}')
                aid=sql(f'SELECT id FROM payment_attempts WHERE pay_row_id={pid}')
                assert result(mode,'attention',pid) is True
                assert not result(mode,'review',pid,callback=f'gatewayReview_H_{aid}')['sends']
                assert result(mode,'attention',pid) is True
                assert result(mode,'review',pid,callback=f'attentionReceipt_{pid}')['sends']
                b=balance()
                assert run(mode,'reject',pid,actor=1001)['ok'] is False
                assert result(mode,'reject',pid)=='declined'
                assert result(mode,'attention',pid) is False
                assert result(mode,'finish',pid,file='original-photo',token=token) is False
                assert result(mode,'begin',pid,file='original-photo') is None
                assert not result(mode,'review',pid,callback=f'attentionReceipt_{pid}')['sends']
                assert run(mode,'approve',pid)['ok'] is False and balance()==b
                case('rejected_receipt_cannot_reopen_archive_callback_inert')
                pid=seed(mode+'-orphan',kind='BUY_SUB')
                assert run(mode,'issue',pid,method='bank_transfer')['reason']=='checkout_service_invoice_missing'
                assert sql(f'SELECT COUNT(*) FROM payment_attempts WHERE pay_row_id={pid}')=='0'
                case('no_orphan_service_invoice')
                pid=seed(mode+'-timer')
                result(mode,'issue',pid,method='bank_transfer')
                submit(mode,pid,'bank_transfer','fixture-timer')
                b=balance()
                assert result(mode,'approve',pid,basis='bank_timer',actor=0)['funding']=='wallet_only' and balance()==b+1000
                assert roles(pid)=='customer,receipt_primary,service_admin,service_channel',roles(pid)
                case('actual_explicit_timer')
                # The real bank checker must find and consume exactly the
                # provider-owned original SMS, then use the common funding
                # transaction even when the local service is invalidated.
                q=dict(kind='BUY',variant='standard',price=100,server_id=2,plan_id=7,order_id=0,
                    quantity=1,days=30,volume=2,remark='fixture',uuid='',naming_mode='fixed',explicit_name=True)
                # A committed service supersession between receipt claim and
                # Telegram ACK must not lose the original evidence or reopen
                # the retired service. All photo methods share this path.
                for method in ('bank_transfer','gift_card','manual_usdt','manual_trx'):
                    for lost in (False,True):
                        name=mode+'-inflight-'+method+('-lost' if lost else '-ack')
                        oid=result(mode,'quote',reference=name,request=q)
                        pid=seed(name,price=100,kind='BUY_SUB')
                        result(mode,'issue',pid,method=method,service=oid)
                        token=result(mode,'begin',pid,method=method,file='original-inflight-photo')
                        assert token
                        sql(f"UPDATE pays SET state='service_superseded' WHERE id={pid}; UPDATE service_operations SET stage='cancelled' WHERE id={oid}")
                        b=balance()
                        if lost:
                            sql(f"UPDATE payment_attempts SET review_notice_claimed_at=NOW()-INTERVAL 3 MINUTE WHERE pay_row_id={pid}")
                            assert result(mode,'attention',pid) is True
                            assert result(mode,'begin',pid,method=method,file='replacement-photo') is None
                            recovered=result(mode,'review',pid,callback=f'attentionReceipt_{pid}',fail_send=True)
                            assert len(recovered['sends'])==1
                            assert sql(f"SELECT review_notice_claim_token IS NULL FROM payment_attempts WHERE pay_row_id={pid}")=='1'
                            recovered=result(mode,'review',pid,callback=f'attentionReceipt_{pid}')
                            assert len(recovered['sends'])==1 and recovered['sends'][0]['args'][0]=='original-inflight-photo'
                        else:
                            assert result(mode,'finish',pid,method=method,file='original-inflight-photo',token=token) is True
                        assert sql(f"SELECT state FROM pays WHERE id={pid}")=='service_superseded'
                        assert result(mode,'attention',pid) is True
                        if method=='bank_transfer' and not lost:
                            assert result(mode,'timer_sweep',pid)
                            assert sql(f"SELECT wallet_credit_basis FROM payment_attempts WHERE pay_row_id={pid}")=='bank_timer'
                        else:
                            assert result(mode,'approve',pid)['funding']=='request_invalidated'
                        assert balance()==b+100 and result(mode,'approve',pid)['credit']['replayed']
                        assert sql(f"SELECT stage FROM service_operations WHERE id={oid}")=='cancelled'
                        assert sql(f"SELECT COUNT(*) FROM wallet_entries WHERE source_kind='service_debit' AND source_ref='{oid}'")=='0'
                case('superseded_receiving_ack_and_recovery_all_photo_methods')
                case('superseded_receipt_preserves_configured_timer_policy')
                # Typed gift code and receipt claim must commit atomically.
                name=mode+'-atomic-typed-gift'
                oid=result(mode,'quote',reference=name,request=q)
                pid=seed(name,price=100,kind='BUY_SUB')
                result(mode,'issue',pid,method='gift_card',api=True,service=oid)
                code=evidence.fixture_gift_code(name)
                sql("""\nDELIMITER //
CREATE TRIGGER fixture_receiving_fail BEFORE UPDATE ON payment_attempts
FOR EACH ROW BEGIN
    IF NEW.stage='receiving' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture receiving rollback';
    END IF;
END//
DELIMITER ;
""")
                try:
                    assert result(mode,'begin',pid,method='gift_card',file=code,api=True) is None
                    assert sql(f"SELECT CONCAT(stage,':',evidence_code IS NULL,':',review_notice_claim_token IS NULL) FROM payment_attempts WHERE pay_row_id={pid}")=='pending:1:1'
                finally:sql('DROP TRIGGER fixture_receiving_fail')
                assert result(mode,'begin',pid,method='gift_card',file=code,api=True)
                sql(f"UPDATE pays SET state='service_superseded' WHERE id={pid}; UPDATE service_operations SET stage='cancelled' WHERE id={oid}; UPDATE payment_attempts SET review_notice_claimed_at=NOW()-INTERVAL 3 MINUTE WHERE pay_row_id={pid}")
                assert result(mode,'attention',pid)
                assert result(mode,'begin',pid,method='gift_card',file=evidence.fixture_gift_code(name+'-other'),api=True) is None
                recovered=result(mode,'review',pid,callback=f'attentionReceipt_{pid}',api=True)
                assert len(recovered['sends'])==1 and code in recovered['sends'][0]['args'][0]
                assert sql(f"SELECT state FROM pays WHERE id={pid}")=='service_superseded'
                b=balance()
                assert result(mode,'approve',pid)['funding']=='request_invalidated' and balance()==b+100
                assert result(mode,'approve',pid)['credit']['replayed'] and balance()==b+100
                case('typed_gift_claim_atomic_and_superseded_recovery')
                # No NEW evidence may start from an already-invalidated draft.
                name=mode+'-unstarted-superseded'
                oid=result(mode,'quote',reference=name,request=q)
                pid=seed(name,price=100,kind='BUY_SUB')
                result(mode,'issue',pid,method='bank_transfer',service=oid)
                sql(f"UPDATE pays SET state='service_superseded' WHERE id={pid}")
                assert result(mode,'begin',pid,file='new-photo') is None
                assert result(mode,'attention',pid) is False
                case('superseded_unstarted_receipt_not_reopened')
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
                assert roles(pid)=='customer,receipt_primary,service_admin,service_channel',roles(pid)
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
                assert sql(f"SELECT GROUP_CONCAT(recipient ORDER BY recipient) FROM payment_followup_intents WHERE pay_row_id={pid} AND role IN ('service_admin','service_channel')")=='9001,@fixture_report'
                rid=int(sql(f"SELECT id FROM payment_followup_intents WHERE pay_row_id={pid} AND role='service_channel'"))
                report=result(mode,'report',pid,report=rid)
                assert report['status']=='acknowledged' and len(report['sends'])==1,report
                text=report['sends'][0]['args'][0]
                assert 'API' in text and mode+'-superseded' in text and 'تغییر یا حذف سرویس' in text,text
                case('superseded_api_wallet_report_searchable_and_delivered')
                case('superseded_submitted_api_receipt_credits_without_service')
                # An unfunded superseded receipt must still allow a real reject,
                # not require a removed archive or silently remain in review.
                oid=result(mode,'quote',reference=mode+'-superseded-reject',request=q)
                pid=seed(mode+'-superseded-reject',price=100,kind='BUY_SUB')
                result(mode,'issue',pid,method='bank_transfer',service=oid)
                submit(mode,pid,'bank_transfer','fixture-superseded-reject')
                sql(f"UPDATE pays SET state='service_superseded' WHERE id={pid}")
                b=balance()
                assert result(mode,'attention',pid) is True
                assert result(mode,'reject',pid)=='declined' and balance()==b
                assert result(mode,'attention',pid) is False
                assert sql(f"SELECT stage FROM service_operations WHERE id={oid}")=='cancelled'
                case('superseded_unfunded_receipt_reject_without_archive')
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
                assert run(mode,'reject',pid)['reason']=='manual_reject_original_evidence_pending'
                assert result(mode,'check',pid)=='settled' and balance()==b+1000
                assert result(mode,'check',pid)=='settled' and balance()==b+1000
                assert roles(pid)=='customer,receipt_primary,service_admin,service_channel',roles(pid)
                case('actual_gift_proven_local_rollback_recovery_reject_denied')

                # Existing cooldown policy must use current receipt facts,
                # not retired approved rows or automatic settlements.
                price=5421 if mode=='strict' else 5422
                now=int(datetime.now(timezone.utc).timestamp())
                old=seed(mode+'-cooldown-paid',price=price)
                sql(f'UPDATE pays SET request_date={now-500} WHERE id={old}')
                result(mode,'issue',old,method='bank_transfer')
                submit(mode,old,'bank_transfer','cooldown-paid-photo')
                result(mode,'approve',old)
                fresh=seed(mode+'-cooldown-fresh',price=price)
                result(mode,'issue',fresh,method='bank_transfer')
                msg=result(mode,'duplicate_guard',fresh,clock=now)
                assert isinstance(msg,str) and 'تایید' in msg,msg
                assert result(mode,'duplicate_guard',fresh,clock=now,policy={'receiptApprovedMinutes':5}) is None
                assert result(mode,'duplicate_guard',fresh,clock=now,policy={'receiptApprovedMinutes':0})==msg
                case('current_manual_approval_cooldown_preserves_configured_policy')

                price+=100
                old=seed(mode+'-cooldown-review',price=price)
                sql(f'UPDATE pays SET request_date={now-500} WHERE id={old}')
                result(mode,'issue',old,method='bank_transfer')
                submit(mode,old,'bank_transfer','cooldown-review-photo')
                fresh=seed(mode+'-cooldown-review-fresh',price=price)
                result(mode,'issue',fresh,method='bank_transfer')
                msg=result(mode,'duplicate_guard',fresh,clock=now)
                assert isinstance(msg,str) and 'بررسی' in msg,msg
                assert result(mode,'duplicate_guard',fresh,clock=now,policy={'receiptReviewMinutes':5}) is None
                assert result(mode,'begin',fresh,file='same-recovery-photo')
                assert result(mode,'duplicate_guard',fresh,clock=now) is None
                assert result(mode,'reject',old)=='declined'
                case('current_review_cooldown_does_not_block_original_recovery')

                price+=100
                old=seed(mode+'-cooldown-timer',price=price)
                result(mode,'issue',old,method='bank_transfer')
                submit(mode,old,'bank_transfer','cooldown-timer-photo')
                result(mode,'approve',old,basis='bank_timer',actor=0)
                historical=seed(mode+'-cooldown-historical',price=price)
                sql(f"UPDATE pays SET state='approved',request_date={now-30} WHERE id={historical}")
                fresh=seed(mode+'-cooldown-automatic-fresh',price=price)
                result(mode,'issue',fresh,method='bank_transfer')
                assert result(mode,'duplicate_guard',fresh,clock=now) is None
                case('historical_and_automatic_payments_do_not_create_manual_cooldown')

                # A corrupt current profile can fail before obtaining a claim.
                # It must not permanently occupy the only worker/projection
                # slot while another legitimate payment is ready.
                sql("UPDATE payment_attempts SET evidence_next_check_at=DATE_ADD(NOW(),INTERVAL 1 DAY),evidence_projection_checked_at=NOW() WHERE evidence_profile IS NOT NULL")
                historic=seed(mode+'-historical-projection',price=7381)
                result(mode,'issue',historic,method='bank_transfer',api=True)
                sql(f"UPDATE payment_attempts SET wallet_invoice_sha256=NULL WHERE pay_row_id={historic}")
                bad=seed(mode+'-bad-profile',price=7382)
                result(mode,'issue',bad,method='bank_transfer',api=True)
                submit(mode,bad,'bank_transfer','bad-profile-photo',api=True)
                sql(f"UPDATE payment_attempts SET evidence_profile='fixture-corrupt-profile',evidence_next_check_at=NOW()-INTERVAL 10 MINUTE WHERE pay_row_id={bad}")
                good=seed(mode+'-healthy-behind-bad',price=7383)
                r=result(mode,'issue',good,method='bank_transfer',api=True)
                submit(mode,good,'bank_transfer','healthy-profile-photo',api=True)
                ident='102' if mode=='strict' else '202'
                nowdt=datetime.now(timezone.utc).replace(microsecond=0)
                server.messages[ident]=dict(id=int(ident),public_uid='UID'+ident,provider='BANK_FIXTURE',provider_sms_id='sms'+ident,
                    sender='BANK',mobile_source='phone',bank_key='fixture',rule_id=7,amount=int(float(r['amount'])),display_unit='toman',
                    sms_date=nowdt.strftime('%Y-%m-%d'),sms_time=nowdt.strftime('%H:%M:%S'),created_at=nowdt.strftime('%Y-%m-%d %H:%M:%S'),status='ready')
                sql(f"UPDATE payment_attempts SET evidence_next_check_at=NOW()-INTERVAL 1 MINUTE WHERE pay_row_id={good}")
                b=balance()
                assert result(mode,'maintain',good)['checked']==1
                assert balance()==b
                assert sql(f"SELECT evidence_next_check_at>NOW() AND evidence_projection_checked_at IS NOT NULL FROM payment_attempts WHERE pay_row_id={bad}")=='1'
                assert sql(f"SELECT evidence_projection_checked_at IS NULL FROM payment_attempts WHERE pay_row_id={historic}")=='1'
                assert result(mode,'maintain',good)['checked']==1
                assert balance()==b+7383 and server.consumes[ident]==1
                assert sql(f"SELECT evidence_projection_checked_at IS NOT NULL FROM payment_attempts WHERE pay_row_id={good}")=='1'
                assert sql(f"SELECT wallet_credit_entry_id IS NULL AND evidence_profile='fixture-corrupt-profile' FROM payment_attempts WHERE pay_row_id={bad}")=='1'
                case('api_maintenance_rotates_broken_pin_and_skips_historical_projection')

                # More than four exact-amount pages is a scheduling budget,
                # never an operator-only threshold or permanent first-page loop.
                scan=seed(mode+'-paged-bank-proof',price=8371)
                r=result(mode,'issue',scan,method='bank_transfer',api=True)
                submit(mode,scan,'bank_transfer','paged-bank-photo',api=True)
                first=10000 if mode=='strict' else 20000
                unit='toman' if mode=='strict' else 'rial'
                multiplier=1 if unit=='toman' else 10
                from datetime import timedelta
                nowdt=datetime.now(timezone.utc).replace(microsecond=0)
                older=nowdt-timedelta(minutes=10)
                for n in range(401):
                    ident=str(first+n)
                    transfer=nowdt if n==400 else older
                    server.messages[ident]=dict(id=int(ident),public_uid='UID'+ident,provider='BANK_FIXTURE',provider_sms_id='sms'+ident,
                        sender='BANK',mobile_source='phone',bank_key='fixture',rule_id=7,amount=int(float(r['amount']))*multiplier,display_unit=unit,
                        sms_date=transfer.strftime('%Y-%m-%d'),sms_time=transfer.strftime('%H:%M:%S'),created_at=nowdt.strftime('%Y-%m-%d %H:%M:%S'),status='ready')
                b=balance()
                assert result(mode,'check',scan)=='monitoring'
                assert balance()==b
                assert int(sql(f"SELECT evidence_cursor_id FROM payment_attempts WHERE pay_row_id={scan}"))>=first+399
                sql(f"UPDATE payment_attempts SET evidence_next_check_at=NOW() WHERE pay_row_id={scan}")
                assert result(mode,'check',scan)=='settled' and balance()==b+8371
                assert result(mode,'check',scan)=='settled' and server.consumes[str(first+400)]==1
                for n in range(401):del server.messages[str(first+n)]
                case('bank_filtered_search_resumes_after_page_budget')
            assert sum(server.consumes.values())==8 and server.gift_consumes==2
        finally:server.shutdown();server.server_close();thread.join(timeout=5)
    report=dict(complete=True,cases=len(cases),checks=cases,owned_socket_mariadb=True,
        actual_protected_api_http=True,actual_manual_receipt_ack_contract=True,external_requests=0,production_effects=0)
    if args.report:args.report.write_text(json.dumps(report,indent=2)+'\n')
    print(json.dumps(report))
if __name__=='__main__':main()
