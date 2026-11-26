#!/usr/bin/env python3
"""Manual issuer and actual API evidence acquisition -> common Wallet owner."""
from __future__ import annotations

import argparse
from concurrent.futures import ThreadPoolExecutor
from datetime import datetime, timezone, timedelta
import json
from pathlib import Path
import subprocess
import threading

import database_fixture as owned
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
require_once dirname($argv[1]).'/ReceiptNotices.php';
function sendPhoto(...$args) {
    $GLOBALS['review_sends'][]=['kind'=>'photo','args'=>$args];
    if ($GLOBALS['spec']['approve_during_send']??false) {
        $GLOBALS['spec']['approve_during_send']=false;
        achbotCheckoutSettleReceipt($GLOBALS['connection'],(int)$GLOBALS['id'],1001,'manual',[],9001,9001);
    }
    if (array_key_exists('response',$GLOBALS['spec'])) return json_decode(json_encode($GLOBALS['spec']['response']));
    if ($GLOBALS['spec']['fail_send']??false) return null;
    return fixtureMessageAck($args[4],222);
}
function sendMessage(...$args) {
    $GLOBALS['review_sends'][]=['kind'=>'message','args'=>$args];
    if (isset($GLOBALS['achbotTelegramDiagnosticContext']['followup_id'])) {
        if (array_key_exists('response',$GLOBALS['spec'])) return json_decode(json_encode($GLOBALS['spec']['response']));
        if (!($GLOBALS['spec']['fail_send']??false)) return fixtureMessageAck($args[3],((int)$args[3]===9001 && str_contains($args[0],'AG2-'))?222:null);
    }
    return (object)['ok'=>!($GLOBALS['spec']['fail_send']??false),'result'=>(object)['message_id'=>333]];
}
function answerCallbackQuery(...$args) {$GLOBALS['review_alerts'][]=$args;}
function alert(...$args) {$GLOBALS['review_alerts'][]=$args;}
function editKeys(...$args) {
    $GLOBALS['review_sends'][]=['kind'=>'edit_keys','args'=>$args];
    if (isset($GLOBALS['achbotTelegramDiagnosticContext']['followup_id'])) {
        if (array_key_exists('response',$GLOBALS['spec'])) return json_decode(json_encode($GLOBALS['spec']['response']));
        return fixtureMessageAck($args[2],(int)$args[1]);
    }
    return (object)['ok'=>true];
}
function editText(...$args) {
    $GLOBALS['review_sends'][]=['kind'=>'edit','args'=>$args];
    return (object)['ok'=>true];
}
function fixtureReview(mysqli $db,array $spec): array {
    $connection=$db;$admin=9001;$from_id=(int)($spec['actor']??9001);
    $chat_id=(int)($spec['chat']??$from_id);$message_id=111;$callbackId='test-review';
    $data=$spec['callback'];$text=$spec['text']??'';$userInfo=['step'=>$spec['step']??'none'];
    $botUsername='fixture_bot';$cancelKey=null;
    $update=(object)['update_id'=>(int)($spec['update_id']??12345),'callback_query'=>(object)[
        'data'=>$data,'from'=>(object)['id'=>$from_id],'message'=>(object)['message_id'=>$message_id,
        'chat'=>(object)['id'=>$chat_id,'type'=>$chat_id===$from_id?'private':'group']]]];
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
if (array_key_exists('report_channel',$spec)) $GLOBALS['botState']['rewardChannel']=$spec['report_channel'];
if (!($spec['api']??false)) {$GLOBALS['botState']['smsApiVerifyState']='off';$GLOBALS['botState']['giftApiVerifyState']='off';}
try {
    if (isset($spec['issued_time'])) $db->query('SET timestamp='.(int)$spec['issued_time']);
    if (isset($spec['random_seed'])) mt_srand((int)$spec['random_seed']);
    $result=match ($spec['op']) {
        'attention'=>achbotGatewayAttentionCaseOpen($db,$id),
        'expire'=>achbotPayUxExpireInvoice($db,$id,(int)$spec['clock']),
        'receipt_deadline'=>achbotPayUxReceiptDeadline($spec['attempt']),
        'maintain'=>achbotApiPaymentMaintain($db,(int)($spec['limit']??1)),
        'duplicate_guard'=>(static function() use ($db,$pay,$spec) {
            $GLOBALS['botState']=array_replace($GLOBALS['botState'],$spec['policy']??[]);
            return duplicateReceiptGuard($db,$pay,(int)($spec['clock']??time()));
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
        'start'=>achbotPayUxStartManualReceipt($db,$id,$method,(int)($spec['owner']??1001),$spec['expected_mode']??null),
        'admit'=>achbotManualAdmitReceipt($db,$pay,$method,(int)($spec['owner']??1001),$spec['file'],
            'fixture original receipt',inlineKeyboard([[['text'=>'approve','callback_data'=>achbotManualApprovalCallbackData($pay)]]]),
            'fixture_bot',$spec['expected_mode']??null),
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


from operational_report_fixture import with_message_ack
PHP=with_message_ack(PHP)

def main():
    ap=argparse.ArgumentParser(description=__doc__)
    ap.add_argument('--run-disposable',required=True,action='store_true')
    ap.add_argument('--report',type=Path)
    args=ap.parse_args();cases=[]
    with owned.owned_database(ROOT/'database/current-schema.sql') as (work,env):
        cli=['mariadb','--no-defaults','--default-character-set=utf8mb4','--protocol=SOCKET','--socket='+str(work/'mysql.sock'),'-uroot','--batch','--skip-column-names',DB]
        def sql(q):
            return subprocess.run(cli,input="SET time_zone='+00:00';"+q,text=True,encoding="utf-8",env=env,check=True,capture_output=True,timeout=20).stdout.strip()
        sql("""INSERT INTO setting(type,value) VALUES ('BOT_STATES','{"cartToCartAutoAcceptState":"on","cartToCartAutoAcceptTime":5,"cartToCartAutoAcceptType":2}');
            INSERT INTO server_config(id,panel_url,type,username,password)
            VALUES (2,'https://fixture.invalid','pasarguard','fixture','fixture');
            INSERT INTO server_info(id,title,ucount,remark,flag,active,state) VALUES(2,'Fixture','100','fixture','',1,1);
            UPDATE server_config SET provider_connection_id=2,provider_instance_key=REPEAT('a',32),provider_version='5.5.1',provider_enabled=1 WHERE id=2;
            INSERT INTO server_plans(id,catid,server_id,inbound_id,title,days,volume,price,descr,active,date,provider_group_ids)
            VALUES(7,1,2,0,'Fixture',30,2,100,'description',1,'fixture','[1,2]');""")
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
            def notice(pid,recipient=9001):
                role='customer' if recipient==1001 else 'receipt_notice'
                return int(sql(f"SELECT id FROM payment_followup_intents WHERE pay_row_id={pid} AND stage='receipt_admitted' AND recipient='{recipient}' AND role='{role}'"))
            def send_original(mode,pid,**kw):
                return result(mode,'report',pid,report=notice(pid),**kw)
            def submit(mode,pid,method,file,api=False):
                assert result(mode,'admit',pid,method=method,file=file,api=api) is True
                assert send_original(mode,pid,api=api)['status']=='acknowledged'
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
                    assert result(mode,'admit',pid,method=method,file='fixture-photo',owner=9002) is False
                    submit(mode,pid,method,'fixture-photo')
                    b=balance()
                    assert run(mode,'approve',pid,actor=1001)['ok'] is False and balance()==b
                    r=result(mode,'approve',pid,actor=9001)
                    assert r['funding']=='wallet_only' and balance()==b+1000 and not r['credit']['replayed'],r
                    assert result(mode,'approve',pid)['credit']['replayed'] and balance()==b+1000
                    assert roles(pid)=='customer,receipt_primary,service_admin,service_channel',roles(pid)
                    case(method+'_actual_issue_and_manual_approval')
                for label,response,expected in (
                    ('exact',{'ok':True,'result':{'chat':{'id':9001},'message_id':222}},'acknowledged'),
                    ('no_op',{'ok':False,'error_code':400,'description':'Bad Request: message is not modified'},'acknowledged'),
                    ('deleted_receipt',{'ok':False,'error_code':400,'description':'Bad Request: message to edit not found'},'skipped'),
                    ('foreign_message',{'ok':True,'result':{'chat':{'id':9001},'message_id':223}},'unknown'),
                    ('foreign_chat',{'ok':True,'result':{'chat':{'id':9002},'message_id':222}},'unknown'),
                ):
                    pid=seed(mode+'-receipt-edit-'+label)
                    result(mode,'issue',pid,method='bank_transfer')
                    submit(mode,pid,'bank_transfer','fixture-identity-edit')
                    assert result(mode,'approve',pid)['funding']=='wallet_only'
                    rid=int(sql(f"SELECT id FROM payment_followup_intents WHERE pay_row_id={pid} AND role='receipt_primary' AND kind='edit'"))
                    b=balance()
                    report=result(mode,'report',pid,report=rid,response=response)
                    assert report['status']==('pending' if expected=='unknown' else expected) and len(report['sends'])==1,report
                    assert report['sends'][0]['kind']=='edit_keys' and report['sends'][0]['args'][1:]==[222,9001],report
                    identity=sql(f"SELECT CONCAT(result,':',COALESCE(ack_chat_id,'null'),':',COALESCE(ack_message_id,'null')) FROM payment_followup_attempts WHERE intent_id={rid}")
                    assert identity==(('rejected' if expected=='skipped' else expected)+(':9001:222' if expected=='acknowledged' else ':null:null')),identity
                    assert result(mode,'report',pid,report=rid)['sends']==[] and balance()==b
                    if expected=='unknown':
                        sql(f"UPDATE payment_followup_intents SET next_attempt_at=NOW(6) WHERE id={rid}")
                        recovered=result(mode,'report',pid,report=rid)
                        assert recovered['status']=='acknowledged' and recovered['sends'][0]['args'][1:]==[222,9001],recovered
                        assert balance()==b
                    case('original_receipt_edit_identity_'+label)
                # The approving operator receives a standalone report even
                # when general unsolicited notifications are disabled.
                sql("INSERT INTO setting(type,value) VALUES ('ADMIN_NOTIFY_9002','off')")
                pid=seed(mode+'-operator-report')
                result(mode,'issue',pid,method='bank_transfer')
                submit(mode,pid,'bank_transfer','fixture-operator-report')
                b=balance()
                assert result(mode,'approve',pid,actor=9002)['funding']=='wallet_only'
                assert balance()==b+1000
                targets=sql(f"SELECT CONCAT(role,':',recipient) FROM payment_followup_intents WHERE pay_row_id={pid} AND stage='wallet_credit' AND kind='send' ORDER BY role").splitlines()
                assert targets==['customer:1001','report_actor:9002','service_admin:9001','service_channel:@fixture_report'],targets
                for target in ('9001','9002','@fixture_report'):
                    rid=int(sql(f"SELECT id FROM payment_followup_intents WHERE pay_row_id={pid} AND stage='wallet_credit' AND recipient='{target}' AND kind='send'"))
                    report=result(mode,'report',pid,report=rid)
                    assert report['status']=='acknowledged' and len(report['sends'])==1,report
                    assert mode+'-operator-report' in report['sends'][0]['args'][0] and '9002' in report['sends'][0]['args'][0],report
                    assert result(mode,'report',pid,report=rid)['sends']==[]
                assert balance()==b+1000
                sql("DELETE FROM setting WHERE type='ADMIN_NOTIFY_9002'")
                case('actual_approver_owner_channel_reports_without_duplicate_money')
                # A malformed, missing, or overlapping configured channel
                # cannot veto an authenticated credit or duplicate the owner.
                for channel_case,destination in (('blank',' '),('malformed','not-a-telegram-destination'),('overflow','9223372036854775808'),('owner_overlap','9001')):
                    pid=seed(mode+'-channel-'+str(len(cases)))
                    result(mode,'issue',pid,method='bank_transfer')
                    submit(mode,pid,'bank_transfer','fixture-report-channel')
                    b=balance()
                    r=result(mode,'approve',pid,report_channel=destination)
                    assert r['funding']=='wallet_only' and balance()==b+1000,r
                    assert result(mode,'approve',pid,report_channel=destination)['credit']['replayed']
                    targets=sql(f"SELECT CONCAT(role,':',recipient) FROM payment_followup_intents WHERE pay_row_id={pid} AND stage='wallet_credit' AND kind='send' ORDER BY role").splitlines()
                    assert targets==['customer:1001','service_admin:9001'],(destination,targets)
                    rid=int(sql(f"SELECT id FROM payment_followup_intents WHERE pay_row_id={pid} AND role='service_admin'"))
                    report=result(mode,'report',pid,report=rid,report_channel=destination)
                    assert report['status']=='acknowledged' and len(report['sends'])==1,report
                    assert balance()==b+1000
                    case('report_channel_'+channel_case+'_financial_finality')
                # Telegram usernames are case insensitive; one immutable
                # recipient remains valid if only casing changes.
                pid=seed(mode+'-report-case')
                result(mode,'issue',pid,method='gift_card')
                submit(mode,pid,'gift_card','fixture-report-case')
                assert result(mode,'approve',pid,report_channel='@Fixture_Report')['funding']=='wallet_only'
                rid=int(sql(f"SELECT id FROM payment_followup_intents WHERE pay_row_id={pid} AND role='service_channel' AND recipient='@fixture_report'"))
                assert result(mode,'report',pid,report=rid,report_channel='@FIXTURE_REPORT')['status']=='acknowledged'
                case('configured_username_case_normalization')
                # Real typed manual rejection: one report per authorized recipient,
                # moderation control for the acting operator only, no Wallet credit.
                sql("INSERT INTO setting(type,value) VALUES ('ADMIN_NOTIFY_9002','off')")
                for actor in (9001,9002):
                    h=mode+'-reject-report-'+str(actor)
                    pid=seed(h)
                    result(mode,'issue',pid,method='bank_transfer')
                    submit(mode,pid,'bank_transfer','fixture-reject-report')
                    editor='declineActionReason_'+h+'_222'
                    sql(f"UPDATE users SET step='{editor}' WHERE userid={actor}")
                    b=balance()
                    rendered=result(mode,'review',pid,callback='',text='fixture <reason>',step=editor,actor=actor)
                    assert sql(f"SELECT CONCAT(state,':',approved_by) FROM pays WHERE id={pid}")==f'declined:{actor}'
                    targets=sql(f"SELECT recipient FROM payment_followup_intents WHERE pay_row_id={pid} AND stage='manual_reject' AND kind='send' ORDER BY recipient").splitlines()
                    assert targets==(['1001','9001','9002','@fixture_report'] if actor==9002 else ['1001','9001','@fixture_report']),targets
                    assert sql(f"SELECT COUNT(*) FROM payment_followup_intents WHERE pay_row_id={pid} AND stage='manual_reject' AND state<>'acknowledged'")=='0',(rendered,sql(f"SELECT role,recipient,state,last_result FROM payment_followup_intents WHERE pay_row_id={pid}"))
                    sends=[v['args'] for v in rendered['sends'] if v['kind']=='message']
                    assert sorted(str(v[3]) for v in sends)==targets,(rendered,targets)
                    for body,keys,parse_mode,recipient in sends:
                        assert parse_mode=='HTML'
                        if recipient==1001: assert '&lt;reason&gt;' in body and '<reason>' not in body
                        else:
                            assert str(actor) in body and h in body
                            assert ('banUserDirect_' in (keys or ''))==(recipient==actor),(keys,recipient,actor)
                    result(mode,'review',pid,callback='',text='fixture <reason>',step=editor,actor=actor)
                    assert balance()==b
                    assert int(sql(f"SELECT COUNT(*) FROM payment_followup_intents WHERE pay_row_id={pid} AND stage='manual_reject' AND kind='send'"))==len(targets)
                    case('manual_rejection_dedup_owner_actor_channel_'+str(actor))
                sql("DELETE FROM setting WHERE type='ADMIN_NOTIFY_9002'")
                # Original admission commits without Telegram. Lost ACK never
                # causes auto-resend, changes evidence, or gates money.
                for method in ('bank_transfer','gift_card','manual_usdt','manual_trx'):
                    pid=seed(mode+'-recover-'+method)
                    result(mode,'issue',pid,method=method)
                    assert result(mode,'attention',pid) is False
                    assert result(mode,'admit',pid,method=method,file='fixture-original-photo')
                    assert result(mode,'attention',pid) is True
                    assert sql(f"SELECT CONCAT(p.state,':',a.stage,':',p.message_id IS NULL) FROM pays p JOIN payment_attempts a ON a.pay_row_id=p.id WHERE p.id={pid}")=='have_sent:review:1'
                    b=balance();cb=f'attentionReceipt_{pid}'
                    assert not result(mode,'review',pid,callback=cb,actor=1001)['sends']
                    assert not result(mode,'review',pid,callback=cb,chat=1001)['sends']
                    assert result(mode,'admit',pid,method=method,file='replacement-photo') is False
                    lost=send_original(mode,pid,method=method,fail_send=True)
                    assert lost['status']=='unknown' and len(lost['sends'])==1 and lost['sends'][0]['args'][0]=='fixture-original-photo',lost
                    assert send_original(mode,pid)['sends']==[]
                    assert result(mode,'report',pid,report=notice(pid,1001))['status']=='acknowledged'
                    recovered=result(mode,'review',pid,callback=cb,update_id=10001)
                    assert len(recovered['sends'])==1 and recovered['sends'][0]['args'][0]=='fixture-original-photo',recovered
                    assert not result(mode,'review',pid,callback=cb,update_id=10001)['sends']
                    assert balance()==b and result(mode,'attention',pid)
                    assert result(mode,'approve',pid)['funding']=='wallet_only'
                    assert result(mode,'approve',pid)['credit']['replayed'] and balance()==b+1000
                    assert not result(mode,'review',pid,callback=cb,update_id=10002)['sends']
                    assert result(mode,'attention',pid) is True # original unknown remains auditable
                    assert result(mode,'outcome',pid)=='wallet_credited'
                    case(method+'_lost_receipt_ack_recovery_and_independent_report')
                # A failed outbox write must roll back the entire admission.
                pid=seed(mode+'-admit-rollback')
                result(mode,'issue',pid,method='bank_transfer')
                sql("CREATE TRIGGER fixture_receipt_outbox_fail BEFORE INSERT ON payment_followup_intents FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture admission failure'")
                try:
                    assert result(mode,'admit',pid,file='rolled-back-photo') is False
                    assert sql(f"SELECT state FROM pays WHERE id={pid}")=='pending'
                    assert sql(f"SELECT stage FROM payment_attempts WHERE pay_row_id={pid}")=='pending'
                    assert sql(f"SELECT COUNT(*) FROM payment_followup_intents WHERE pay_row_id={pid}")=='0'
                finally:
                    sql('DROP TRIGGER fixture_receipt_outbox_fail')
                assert result(mode,'admit',pid,file='real-photo') is True
                assert send_original(mode,pid)['sends'][0]['args'][0]=='real-photo'
                case('receipt_outbox_atomic_rollback')
                # Concurrent uploads cannot replace the first admitted evidence.
                pid=seed(mode+'-admit-concurrent')
                result(mode,'issue',pid,method='bank_transfer')
                files=['race-photo-a','race-photo-b']
                with ThreadPoolExecutor(max_workers=2) as pool:
                    admitted=list(pool.map(lambda f:result(mode,'admit',pid,file=f),files))
                assert sum(admitted)==1,admitted
                winner=files[admitted.index(True)]
                assert send_original(mode,pid)['sends'][0]['args'][0]==winner
                assert sql(f"SELECT COUNT(*) FROM payment_followup_intents WHERE pay_row_id={pid} AND stage='receipt_admitted' AND role='customer'")=='1'
                case('receipt_concurrent_original_winner')
                # The financial decision may commit while the staff send is in flight.
                pid=seed(mode+'-late-staff-ack')
                result(mode,'issue',pid,method='bank_transfer')
                assert result(mode,'admit',pid,file='late-staff-photo')
                b=balance()
                wire=send_original(mode,pid,approve_during_send=True)
                assert wire['status']=='acknowledged' and balance()==b+1000,wire
                assert 'pa_' in wire['sends'][0]['args'][2]
                edit=int(sql(f"SELECT id FROM payment_followup_intents WHERE pay_row_id={pid} AND stage='wallet_credit' AND kind='edit' AND recipient='9001'"))
                wire=result(mode,'report',pid,report=edit)
                assert wire['status']=='acknowledged' and wire['sends'][0]['args'][1]==222 and 'pa_' not in wire['sends'][0]['args'][0],wire
                customer=result(mode,'report',pid,report=notice(pid,1001))
                assert customer['status']=='superseded' and not customer['sends'],customer
                assert result(mode,'approve',pid)['credit']['replayed'] and balance()==b+1000
                case('receipt_late_ack_after_financial_commit')
                # A definite 429 is retryable only after retry_after; each recipient is independent.
                pid=seed(mode+'-receipt-rate-limit')
                result(mode,'issue',pid,method='bank_transfer')
                assert result(mode,'admit',pid,file='rate-limit-photo')
                r=send_original(mode,pid,response={'ok':False,'error_code':429,'description':'Too Many Requests','parameters':{'retry_after':60}})
                assert r['status']=='pending',r
                assert not send_original(mode,pid)['sends']
                assert result(mode,'report',pid,report=notice(pid,1001))['status']=='acknowledged'
                assert sql(f"SELECT retry_after_seconds FROM payment_followup_attempts WHERE intent_id={notice(pid)} ORDER BY id DESC LIMIT 1")=='60'
                sql(f"UPDATE payment_followup_intents SET next_attempt_at=NOW(6) WHERE id={notice(pid)}")
                assert send_original(mode,pid)['status']=='acknowledged'
                case('receipt_rate_limit_and_independent_customer')
                pid=seed(mode+'-recover-typed-gift')
                result(mode,'issue',pid,method='gift_card',api=True)
                code=evidence.fixture_gift_code(mode+'-recover-typed-gift')
                assert result(mode,'admit',pid,method='gift_card',file=code,api=True)
                recovered=result(mode,'review',pid,callback=f'attentionReceipt_{pid}',api=True)
                assert len(recovered['sends'])==1 and recovered['sends'][0]['kind']=='message' and code in recovered['sends'][0]['args'][0],recovered
                assert sql(f'SELECT state FROM pays WHERE id={pid}')=='have_sent'
                b=balance()
                assert result(mode,'approve',pid)['funding']=='wallet_only' and balance()==b+1000
                assert result(mode,'approve',pid)['credit']['replayed'] and balance()==b+1000
                case('typed_gift_lost_receipt_ack_recovery')
                pid=seed(mode+'-rejected-receipt')
                result(mode,'issue',pid,method='bank_transfer')
                assert result(mode,'admit',pid,file='original-photo')
                aid=sql(f'SELECT id FROM payment_attempts WHERE pay_row_id={pid}')
                assert result(mode,'attention',pid) is True
                assert not result(mode,'review',pid,callback=f'gatewayReview_H_{aid}')['sends']
                assert result(mode,'attention',pid) is True
                b=balance()
                assert run(mode,'reject',pid,actor=1001)['ok'] is False
                assert result(mode,'reject',pid)=='declined'
                assert result(mode,'attention',pid) is False
                assert result(mode,'admit',pid,file='original-photo') is False
                late=send_original(mode,pid)
                assert late['status']=='acknowledged' and 'pa_' not in late['sends'][0]['args'][2]
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
                assert run(mode,'approve',pid,basis='bank_timer',actor=0)['ok'] is False and balance()==b
                assert result(mode,'approve',pid)['funding']=='wallet_only' and balance()==b+1000
                assert roles(pid)=='customer,receipt_primary,service_admin,service_channel',roles(pid)
                case('retired_timer_cannot_credit')
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
                        token=result(mode,'admit',pid,method=method,file='original-inflight-photo')
                        assert token
                        sql(f"UPDATE pays SET state='service_superseded' WHERE id={pid}; UPDATE service_operations SET stage='cancelled' WHERE id={oid}")
                        b=balance()
                        if lost:
                            assert result(mode,'attention',pid) is True
                            assert result(mode,'admit',pid,method=method,file='replacement-photo') is False
                            recovered=send_original(mode,pid,fail_send=True)
                            assert recovered['status']=='unknown' and len(recovered['sends'])==1
                            recovered=result(mode,'review',pid,callback=f'attentionReceipt_{pid}')
                            assert len(recovered['sends'])==1 and recovered['sends'][0]['args'][0]=='original-inflight-photo'
                        else:
                            assert send_original(mode,pid)['status']=='acknowledged'
                        assert sql(f"SELECT state FROM pays WHERE id={pid}")=='service_superseded'
                        assert result(mode,'attention',pid) is True
                        assert run(mode,'approve',pid,basis='bank_timer',actor=0)['ok'] is False and balance()==b
                        assert result(mode,'approve',pid)['funding']=='request_invalidated'
                        assert balance()==b+100 and result(mode,'approve',pid)['credit']['replayed']
                        assert sql(f"SELECT stage FROM service_operations WHERE id={oid}")=='cancelled'
                        assert sql(f"SELECT COUNT(*) FROM wallet_entries WHERE source_kind='service_debit' AND source_ref='{oid}'")=='0'
                case('superseded_receiving_ack_and_recovery_all_photo_methods')
                case('superseded_receipt_requires_verified_or_staff_approval')
                # Typed gift code and receipt claim must commit atomically.
                name=mode+'-atomic-typed-gift'
                oid=result(mode,'quote',reference=name,request=q)
                pid=seed(name,price=100,kind='BUY_SUB')
                result(mode,'issue',pid,method='gift_card',api=True,service=oid)
                code=evidence.fixture_gift_code(name)
                sql("""\nDELIMITER //
CREATE TRIGGER fixture_receiving_fail BEFORE UPDATE ON payment_attempts
FOR EACH ROW BEGIN
    IF NEW.stage='review' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture receiving rollback';
    END IF;
END//
DELIMITER ;
""")
                try:
                    assert result(mode,'admit',pid,method='gift_card',file=code,api=True) is False
                    assert sql(f"SELECT CONCAT(stage,':',evidence_code IS NULL,':',review_notice_claim_token IS NULL) FROM payment_attempts WHERE pay_row_id={pid}")=='pending:1:1'
                finally:sql('DROP TRIGGER fixture_receiving_fail')
                assert result(mode,'admit',pid,method='gift_card',file=code,api=True)
                sql(f"UPDATE pays SET state='service_superseded' WHERE id={pid}; UPDATE service_operations SET stage='cancelled' WHERE id={oid}")
                assert result(mode,'attention',pid)
                assert result(mode,'admit',pid,method='gift_card',file=evidence.fixture_gift_code(name+'-other'),api=True) is False
                recovered=result(mode,'review',pid,callback=f'attentionReceipt_{pid}',api=True)
                assert len(recovered['sends'])==1 and code in recovered['sends'][0]['args'][0]
                assert sql(f"SELECT state FROM pays WHERE id={pid}")=='service_superseded'
                b=balance()
                assert result(mode,'approve',pid)['funding']=='request_invalidated' and balance()==b+100
                assert result(mode,'approve',pid)['credit']['replayed'] and balance()==b+100
                case('typed_gift_claim_atomic_and_superseded_recovery')
                # A closed service can receive original late proof but never a new service.
                name=mode+'-unstarted-superseded'
                oid=result(mode,'quote',reference=name,request=q)
                pid=seed(name,price=100,kind='BUY_SUB')
                result(mode,'issue',pid,method='bank_transfer',service=oid)
                sql(f"UPDATE pays SET state='service_superseded' WHERE id={pid}; UPDATE service_operations SET stage='cancelled' WHERE id={oid}")
                assert result(mode,'issue',pid,method='bank_transfer',service=oid)['status']=='closed'
                assert result(mode,'admit',pid,file='new-photo',owner=9002) is False
                submit(mode,pid,'bank_transfer','original-late-photo')
                assert result(mode,'attention',pid) is True
                b=balance()
                assert result(mode,'approve',pid)['funding']=='request_invalidated' and balance()==b+100
                assert result(mode,'approve',pid)['credit']['replayed'] and balance()==b+100
                assert sql(f"SELECT stage FROM service_operations WHERE id={oid}")=='cancelled'
                case('superseded_unstarted_late_evidence_wallet_only_never_reopens')

                # Deadline is fixed on issuance; settings and repeated clicks do not move it.
                # Inject only the clock into the genuine expiry transaction.
                name=mode+'-invoice-expiry'
                oid=result(mode,'quote',reference=name,request=q)
                pid=seed(name,price=100,kind='BUY_SUB')
                result(mode,'issue',pid,method='bank_transfer',service=oid)
                issued,deadline,grace=map(int,sql(f"SELECT CONCAT(UNIX_TIMESTAMP(created_at),':',invoice_expires_at,':',receipt_grace_seconds) FROM payment_attempts WHERE pay_row_id={pid}").split(':'))
                assert deadline-issued==21600 and grace==900
                sql("UPDATE setting SET value=JSON_SET(value,'$.paymentManualHours',1,'$.paymentReceiptGraceMinutes',1) WHERE type='BOT_STATES'")
                assert result(mode,'issue',pid,method='bank_transfer',service=oid)['status']=='issued'
                assert result(mode,'start',pid,method='bank_transfer',owner=9002) is False
                assert result(mode,'start',pid,method='bank_transfer') is True
                start=int(sql(f"SELECT receipt_started_at FROM payment_attempts WHERE pay_row_id={pid}"))
                assert result(mode,'start',pid,method='bank_transfer') is True
                assert sql(f"SELECT CONCAT(invoice_expires_at,':',receipt_grace_seconds,':',receipt_started_at) FROM payment_attempts WHERE pay_row_id={pid}")==f'{deadline}:{grace}:{start}'
                sql("UPDATE setting SET value=JSON_REMOVE(value,'$.paymentManualHours','$.paymentReceiptGraceMinutes') WHERE type='BOT_STATES'")
                assert result(mode,'receipt_deadline',attempt={'invoice_expires_at':deadline,'receipt_grace_seconds':grace,'receipt_started_at':deadline-1})==deadline-1+grace
                assert result(mode,'receipt_deadline',attempt={'invoice_expires_at':deadline,'receipt_grace_seconds':grace,'receipt_started_at':deadline})==deadline
                assert result(mode,'expire',pid,clock=deadline-1) is False
                assert result(mode,'expire',pid,clock=deadline) is True
                assert result(mode,'expire',pid,clock=deadline+1) is False
                assert sql(f"SELECT state FROM pays WHERE id={pid}")=='expired'
                assert result(mode,'issue',pid,method='bank_transfer',service=oid)['status']=='closed'
                assert sql(f"SELECT stage FROM service_operations WHERE id={oid}")=='cancelled'
                submit(mode,pid,'bank_transfer','late-expired-original-photo')
                b=balance()
                assert result(mode,'approve',pid)['funding']=='request_invalidated' and balance()==b+100
                assert result(mode,'approve',pid)['credit']['replayed'] and balance()==b+100
                assert sql(f"SELECT stage FROM service_operations WHERE id={oid}")=='cancelled'
                case('pinned_deadline_nonextendable_grace_late_wallet_once')
                for method in ('bank_transfer','gift_card'):
                    name=mode+'-started-superseded-'+method
                    oid=result(mode,'quote',reference=name,request=q)
                    pid=seed(name,price=100,kind='BUY_SUB')
                    api=method=='gift_card'
                    result(mode,'issue',pid,method=method,service=oid,api=api)
                    assert result(mode,'start',pid,method=method,expected_mode='topup',api=api) is False
                    assert result(mode,'start',pid,method=method,expected_mode='standard',api=api) is True
                    sql(f"UPDATE pays SET state='service_superseded' WHERE id={pid}; UPDATE service_operations SET stage='cancelled' WHERE id={oid}")
                    file=evidence.fixture_gift_code(name) if api else 'original-photo'
                    assert result(mode,'admit',pid,method=method,file=file,expected_mode='topup',api=api) is False
                    token=result(mode,'admit',pid,method=method,file=file,expected_mode='standard',api=api)
                    assert token is True
                    assert send_original(mode,pid,api=api)['status']=='acknowledged'
                    b=balance()
                    assert result(mode,'approve',pid)['funding']=='request_invalidated' and balance()==b+100
                    assert result(mode,'approve',pid)['credit']['replayed'] and balance()==b+100
                case('superseded_started_receipt_preserves_original_family_and_money')
                oid=result(mode,'quote',reference=mode+'-buy',request=q)
                pid=seed(mode+'-sms',price=100,kind='BUY_SUB')
                r=result(mode,'issue',pid,method='bank_transfer',api=True,service=oid)
                assert result(mode,'admit',pid,file='fixture-sms',api=True)
                assert send_original(mode,pid,api=True,fail_send=True)['status']=='unknown'
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
                assert roles(pid)=='customer,service_admin,service_channel',roles(pid)
                assert sql(f"SELECT COUNT(*) FROM payment_followup_attempts a JOIN payment_followup_intents f ON f.id=a.intent_id WHERE f.pay_row_id={pid} AND f.stage='receipt_admitted' AND a.result='acknowledged'")=='0'
                case('actual_bank_scan_without_staff_ack_service_invalidated')
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
                assert result(mode,'admit',fresh,file='same-recovery-photo')
                assert result(mode,'duplicate_guard',fresh,clock=now) is not None
                assert result(mode,'reject',old)=='declined'
                case('current_review_cooldown_does_not_block_original_recovery')

                price+=100
                historical=seed(mode+'-cooldown-historical',price=price)
                sql(f"UPDATE pays SET state='approved',request_date={now-30} WHERE id={historical}")
                fresh=seed(mode+'-cooldown-automatic-fresh',price=price)
                result(mode,'issue',fresh,method='bank_transfer')
                assert result(mode,'duplicate_guard',fresh,clock=now) is None
                case('historical_payments_do_not_create_manual_cooldown')

                # A corrupt current profile can fail before obtaining a claim.
                # It must not permanently occupy the only worker/projection
                # slot while another legitimate payment is ready.
                sql("UPDATE payment_attempts SET evidence_next_check_at=DATE_ADD(NOW(),INTERVAL 1 DAY),evidence_projection_checked_at=NOW() WHERE evidence_profile IS NOT NULL")
                queue_base=700000 if mode=='strict' else 800000
                historic=seed(mode+'-historical-projection',price=queue_base+1000)
                result(mode,'issue',historic,method='bank_transfer',api=True)
                sql(f"UPDATE payment_attempts SET wallet_invoice_sha256=NULL WHERE pay_row_id={historic}")
                bad=seed(mode+'-bad-profile',price=queue_base+3000)
                result(mode,'issue',bad,method='bank_transfer',api=True)
                submit(mode,bad,'bank_transfer','bad-profile-photo',api=True)
                sql(f"UPDATE payment_attempts SET evidence_profile='fixture-corrupt-profile',evidence_next_check_at=NOW()-INTERVAL 10 MINUTE WHERE pay_row_id={bad}")
                good_price=queue_base+5000
                good=seed(mode+'-healthy-behind-bad',price=good_price)
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
                queue_result=result(mode,'maintain',good)
                assert queue_result['checked']==1,queue_result
                actual_balance=balance()
                assert actual_balance==b+good_price and server.consumes.get(ident,0)==1,(
                    mode,queue_result,actual_balance,b+good_price,server.consumes.get(ident,0),
                    sql(f"SELECT CONCAT_WS(':',stage,evidence_state,COALESCE(wallet_credit_entry_id,0)) FROM payment_attempts WHERE pay_row_id={good}"))
                assert sql(f"SELECT evidence_projection_checked_at IS NOT NULL FROM payment_attempts WHERE pay_row_id={good}")=='1'
                assert sql(f"SELECT wallet_credit_entry_id IS NULL AND evidence_profile='fixture-corrupt-profile' FROM payment_attempts WHERE pay_row_id={bad}")=='1'
                case('api_maintenance_rotates_broken_pin_and_skips_historical_projection')

                # Isolate the scheduler test above from random quote overlap,
                # but explicitly preserve the independent collision guard. A
                # matching SMS must not settle two original invoices, including
                # when the competing original pin is no longer readable.
                shared_price=queue_base+10000
                competing=seed(mode+'-exact-sms-competing',price=shared_price)
                candidate=seed(mode+'-exact-sms-candidate',price=shared_price)
                collision_quotes=[result(mode,'issue',p,method='bank_transfer',api=True,random_seed=1729)
                                  for p in (competing,candidate)]
                assert collision_quotes[0]['amount']==collision_quotes[1]['amount'],collision_quotes
                for p in (competing,candidate): submit(mode,p,'bank_transfer','collision-photo',api=True)
                ident='32000' if mode=='strict' else '33000'
                nowdt=datetime.now(timezone.utc)
                server.messages[ident]=dict(id=int(ident),public_uid='UID'+ident,provider='BANK_FIXTURE',provider_sms_id='sms'+ident,
                    sender='BANK',mobile_source='phone',bank_key='fixture',rule_id=7,amount=int(float(collision_quotes[1]['amount'])),display_unit='toman',
                    sms_date=nowdt.strftime('%Y-%m-%d'),sms_time=nowdt.strftime('%H:%M:%S'),created_at=nowdt.strftime('%Y-%m-%d %H:%M:%S'),status='ready')
                b=balance()
                assert result(mode,'check',candidate)=='review'
                assert balance()==b and server.consumes.get(ident,0)==0
                sql(f"UPDATE payment_attempts SET evidence_profile='fixture-corrupt-overlapping-profile' WHERE pay_row_id={competing}")
                sql(f"UPDATE payment_attempts SET evidence_next_check_at=NOW() WHERE pay_row_id={candidate}")
                assert result(mode,'check',candidate)=='review'
                assert balance()==b and server.consumes.get(ident,0)==0
                del server.messages[ident]
                case('same_exact_sms_amount_with_competing_or_corrupt_original_never_auto_credits')

                # Source-issued old invoices: transfer may be timely even
                # when first submitted after closure. The SMS search duration
                # does not extend the actual transfer window.
                for offset,expected in [(0,'settled'),(1,'monitoring')]:
                    late=seed(mode+'-delayed-transfer-'+str(offset),price=queue_base+20000+offset*2000)
                    issued_time=int(datetime.now(timezone.utc).timestamp())-7*3600
                    r=result(mode,'issue',late,method='bank_transfer',api=True,issued_time=issued_time)
                    deadline=int(sql(f"SELECT invoice_expires_at FROM payment_attempts WHERE pay_row_id={late}"))
                    assert deadline==issued_time+21600 and r['status']=='closed'
                    assert result(mode,'expire',late,clock=deadline) is True
                    submit(mode,late,'bank_transfer','delayed-transfer-photo',api=True)
                    ident=str((30000 if mode=='strict' else 31000)+offset)
                    transfer=datetime.fromtimestamp(deadline+offset,timezone.utc)
                    received=datetime.now(timezone.utc)
                    server.messages[ident]=dict(id=int(ident),public_uid='UID'+ident,provider='BANK_FIXTURE',provider_sms_id='sms'+ident,
                        sender='BANK',mobile_source='phone',bank_key='fixture',rule_id=7,amount=int(float(r['amount'])),display_unit='toman',
                        sms_date=transfer.strftime('%Y-%m-%d'),sms_time=transfer.strftime('%H:%M:%S'),created_at=received.strftime('%Y-%m-%d %H:%M:%S'),status='ready')
                    b=balance()
                    assert result(mode,'check',late)==expected
                    if offset==0:
                        assert balance()==b+queue_base+20000 and server.consumes[ident]==1
                        assert result(mode,'check',late)=='settled' and server.consumes[ident]==1
                    else:
                        assert balance()==b and server.consumes.get(ident,0)==0
                        assert result(mode,'attention',late) is True
                    del server.messages[ident]
                case('source_issued_expiry_delayed_sms_exact_transfer_boundary_and_review')

                # More than four exact-amount pages is a scheduling budget,
                # never an operator-only threshold or permanent first-page loop.
                scan=seed(mode+'-paged-bank-proof',price=8371)
                r=result(mode,'issue',scan,method='bank_transfer',api=True)
                submit(mode,scan,'bank_transfer','paged-bank-photo',api=True)
                first=10000 if mode=='strict' else 20000
                unit='toman' if mode=='strict' else 'rial'
                multiplier=1 if unit=='toman' else 10
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
            assert sum(server.consumes.values())==10 and server.gift_consumes==2
        finally:server.shutdown();server.server_close();thread.join(timeout=5)
    report=dict(complete=True,cases=len(cases),checks=cases,owned_socket_mariadb=True,
        actual_protected_api_http=True,actual_manual_receipt_ack_contract=True,external_requests=0,production_effects=0)
    if args.report:args.report.write_text(json.dumps(report,indent=2)+'\n')
    print(json.dumps(report))
if __name__=='__main__':main()
