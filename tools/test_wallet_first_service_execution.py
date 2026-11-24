#!/usr/bin/env python3
"""Funded service execution against real PHP/MySQLi and an owned HTTP peer.

The peer models PasarGuard's existing authenticated, unique-name CREATE and
readback contract. It does not certify a deployed provider or Telegram delivery.
"""
from __future__ import annotations

import argparse
from concurrent.futures import ThreadPoolExecutor
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
from pathlib import Path
import subprocess
import threading
import re
from urllib.parse import unquote

import test_access_state as owned

ROOT=Path(__file__).resolve().parents[1]
DB=owned.NAMES[1]
PHP=r'''<?php
declare(strict_types=1);
mysqli_report($argv[4]==='strict'?MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT:MYSQLI_REPORT_OFF);
define('ACHBOT_PG_TEST_LOOPBACK',true);
require $argv[1].'/src/Services/Execution.php';
require $argv[1].'/src/Services/AutomaticRenewal.php';
require_once $argv[1].'/src/Finance/ServiceDelivery.php';
require_once $argv[1].'/src/Purchase/OrderWrites.php';
require_once $argv[1].'/src/Finance/FundingCheckout.php';
$data='';
require_once $argv[1].'/src/Subscription/AccountDeletion.php';
$GLOBALS['telegramWebhookSecret']='SyntheticServiceDeliveryKey_2026';
function fixtureDeliverySend(string $kind,int $uid,?string $text,?string $keys,?string $mode,$photo=null) {
    global $db,$x;
    $ctx=$GLOBALS['achbotTelegramDiagnosticContext'];
    if ((int)achbotProviderRow($db,'SELECT @@in_transaction AS tx')['tx']!==0)
        throw new RuntimeException('fixture_telegram_inside_transaction');
    $d=achbotProviderRow($db,'SELECT * FROM payment_delivery_intents WHERE id=?','i',[$ctx['delivery_id']]);
    $p=achbotProviderRow($db,'SELECT * FROM payment_delivery_parts WHERE intent_id=? AND part_no=?','ii',[$d['id'],$ctx['delivery_part']]);
    $a=achbotProviderRow($db,'SELECT * FROM payment_delivery_attempts WHERE intent_id=? AND attempt_no=?','ii',[$d['id'],$ctx['delivery_attempt']]);
    if ($d['state']!=='dispatching' || $p['state']!=='dispatching' || $a['result']!=='dispatching' ||
        (int)$d['attempt_count']!==$ctx['delivery_attempt'] || (int)$p['last_attempt_no']!==$ctx['delivery_attempt'] ||
        $a['dispatch_token']!==$d['dispatch_token'] || (int)$a['part_no']!==$ctx['delivery_part'] ||
        (int)$a['actor_userid']!==(int)($x['actor']??0) || (int)$d['user_id']!==$uid)
        throw new RuntimeException('fixture_telegram_claim_not_committed');
    $body=achbotPaymentEvidenceUnprotect(achbotServiceDeliveryPurpose((int)$d['service_operation_id'],
        (int)$d['ordinal'],(int)$d['effect_id'],(int)$d['order_id']),$d['sealed_payload']);
    $part=$body['parts'][$ctx['delivery_part']-1];
    if ($part['kind']!==$kind || !hash_equals($p['payload_sha256'],achbotDeliveryPartDigest($part)))
        throw new RuntimeException('fixture_part_source');
    $qr=null;
    if ($kind==='qr') {
        $path=$photo instanceof CURLFile?$photo->getFilename():$photo;
        if (!is_string($path) || !is_file($path) || (getimagesize($path)[2]??null)!==IMAGETYPE_PNG ||
            $text!==$part['caption'] || $keys!==null || $mode!==null)
            throw new RuntimeException('fixture_qr_wire');
        // Compare real generated image against original immutable URL.
        if ($x['op']==='deliver') {
            $expected=tempnam(sys_get_temp_dir(),'fixture-qr-');
            try {
                mt_srand(20261124);QRcode::png($part['qr'],$expected,'L',11,4);
                if (hash_file('sha256',$expected)!==hash_file('sha256',$path))
                    throw new RuntimeException('fixture_qr_bytes');
            } finally {unlink($expected);}
        }
        $GLOBALS['fixturePhotoPaths'][]=$path;
        $qr=$part['qr'];
    } elseif ($part['text']!==$text || $part['keys']!==$keys || $part['mode']!==$mode)
        throw new RuntimeException('fixture_text_wire');
    $s=achbotProviderStatement($db,'INSERT INTO fixture_telegram(intent_id,user_id,part_no,kind,qr,caption,keys_json,mode)
        VALUES (?,?,?,?,?,?,?,?)','iiisssss',[$d['id'],$uid,$ctx['delivery_part'],$kind,$qr,$text,$keys,$mode]);$s->close();
    if (($x['crash_after_part']??null)===$ctx['delivery_part']) exit(29);
    if (array_key_exists($ctx['delivery_part'],$x['responses']??[]))
        return json_decode(json_encode($x['responses'][$ctx['delivery_part']],JSON_THROW_ON_ERROR),false,64,JSON_THROW_ON_ERROR);
    return (object)['ok'=>!($x['unknown_ack']??false)];
}
function sendPhoto($photo,$caption=null,$keys=null,$mode=null,$uid=null) {
    return fixtureDeliverySend('qr',$uid,$caption,$keys,$mode,$photo);
}

function sendMessage($text,$keys=null,$mode=null,$uid=null) {
    global $db,$x;
    if ((int)achbotProviderRow($db,'SELECT @@in_transaction AS tx')['tx']!==0)
        throw new RuntimeException('fixture_telegram_inside_transaction');
    $fid=$GLOBALS['achbotTelegramDiagnosticContext']['followup_id']??null;
    if ($fid!==null) {
        $d=achbotProviderRow($db,'SELECT id,state,attempt_count,dispatch_token FROM payment_followup_intents WHERE id=?','i',[$fid]);
        $a=achbotProviderRow($db,'SELECT result,attempt_no,actor_userid FROM payment_followup_attempts
            WHERE intent_id=? AND dispatch_token=?','is',[$fid,$d['dispatch_token']]);
        if ($d['state']!=='dispatching' || !$a || $a['result']!=='dispatching' ||
            (int)$a['attempt_no']!==(int)$d['attempt_count'] ||
            (int)$a['actor_userid']!==(int)($x['actor']??0))
            throw new RuntimeException('fixture_followup_claim_not_committed');
        $s=achbotProviderStatement($db,'INSERT INTO fixture_followup(intent_id,recipient,text,mode) VALUES (?,?,?,?)',
            'isss',[$d['id'],(string)$uid,$text,$mode]);$s->close();
        if (isset($x['response'])) return json_decode(json_encode($x['response'],JSON_THROW_ON_ERROR),false,64,JSON_THROW_ON_ERROR);
        return (object)['ok'=>!($x['unknown_ack']??false)];
    }
    return fixtureDeliverySend('text',$uid,$text,$keys,$mode);
}
function editKeys($keys,$msgId,$ci) {return sendMessage($keys,null,'edit:'.$msgId,$ci);}
function fixtureAlias(mysqli $db,int $order): int {
    $ctx=achbotPgOrderContext($db,$order,true);$o=$ctx['order'];$a=$ctx['account'];
    $id=insertOrder($db,9002,'',0,2,0,$o['remark'],'alias-'.$o['uuid'],
        (int)$o['expire_date'],$o['link'],0,time(),0,true);
    achbotRecordImportedOrderOriginLocked($db,$id,9002,9002,'pasarguard',2,$o['remark'],
        str_repeat('a',64),['remote_user_id'=>$a['remote_user_id'],'remote_created_at'=>$a['remote_created_at'],
            'remote_username'=>$a['remote_username']],$a['id']);
    achbotProviderBindOrder($db,$a,$id);
    return $id;
}
$db=new mysqli('localhost','root','',$argv[3],0,$argv[2]);
$connection=$db;$GLOBALS['admin']=9001;
$x=json_decode($argv[5],true,512,JSON_THROW_ON_ERROR);
if ($x['qr_local_fail']??false) {
    class QRcode {public static function png(...$args) {throw new RuntimeException('fixture_qr_prepare');}}
}

$GLOBALS['botState']=['rewardChannel'=>'@fixture_report'];
$GLOBALS['paymentKeys']=['bankAccount'=>'fixture-bank','holderName'=>'fixture-holder'];
try {
    $presentation=['bot_state'=>[],'bot_url'=>'https://bot.fixture.invalid','bot_username'=>'fixture_bot'];
    if ($x['op']==='step') $r=achbotServiceExecuteStep($db,$x['id'],$presentation);
    elseif ($x['op']==='auto') $r=achbotServiceAutoRenewAdmit($db,$x['order'],(int)($x['owner']??1001),$x['bot_state']??['renewAutoState'=>'on']);
    elseif ($x['op']==='due') $r=achbotServiceExecuteDue($db,$x['limit'],10.0,$presentation);
    elseif ($x['op']==='deliver') {
        $other=null;
        if ($x['block_account']??false) {
            $other=new mysqli('localhost','root','',$argv[3],0,$argv[2]);
            $d=achbotProviderRow($db,'SELECT order_id FROM payment_delivery_intents WHERE id=?','i',[$x['id']]);
            $name=achbotPgServiceAccountLock($other,(int)$d['order_id']);
            if ((int)achbotProviderRow($other,'SELECT GET_LOCK(?,0) AS acquired','s',[$name])['acquired']!==1)
                throw new RuntimeException('fixture_delivery_block_account');
        }
        mt_srand(20261124);
        try {$r=achbotServiceDeliveryDispatch($db,$x['id'],(int)($x['actor']??0),(int)($x['part']??0),$x['confirmation']??null);}
        finally {if ($other!==null) $other->close();}
    }
    elseif ($x['op']==='preview_delivery') {
        $d=achbotProviderRow($db,'SELECT * FROM payment_delivery_intents WHERE id=?','i',[$x['id']]);
        $p=achbotProviderRow($db,'SELECT * FROM payment_delivery_parts WHERE intent_id=? AND part_no=?','ii',[$x['id'],$x['part']]);
        $r=achbotDeliveryPartRedriveToken($d,$p,$x['actor']);
    }
    elseif ($x['op']==='maintain_delivery') $r=achbotDeliveryMaintainPending($db,$x['limit'],10.0);
    elseif ($x['op']==='manual_receipt') {
        require_once $argv[1].'/src/Finance/ManualReceipt.php';
        $pid=$x['pay'];$pay=achbotProviderRow($db,'SELECT * FROM pays WHERE id=?','i',[$pid]);
        $issued=achbotManualReceiptIssue($db,$pid,1001,'bank_transfer',$x['id']);
        if ($issued['status']!=='issued') throw new RuntimeException('fixture_manual_issue');
        $token=achbotManualBeginEvidence($db,$pay,'bank_transfer',1001,'fixture-photo');
        if (!$token || !achbotManualFinishEvidence($db,$pay['hash_id'],'fixture-photo',$token,222,9001))
            throw new RuntimeException('fixture_manual_receipt');
        $r=achbotCheckoutSettleReceipt($db,$pid,1001,'manual',[],9001,9001);
    }
    elseif ($x['op']==='referral') $r=achbotServiceReferralComplete($db,$x['id'],9001);
    elseif ($x['op']==='maintain_referrals') $r=achbotServiceReferralMaintainPending($db,9001,$x['limit'],10.0);
    elseif ($x['op']==='followup') $r=achbotFollowupDispatchOne($db,$x['id'],(int)($x['actor']??0),$x['confirmation']??null);
    elseif ($x['op']==='preview_followup') {
        require_once $argv[1].'/src/Finance/FollowupAttempts.php';
        $r=achbotFollowupRedriveToken(achbotProviderRow($db,'SELECT * FROM payment_followup_intents WHERE id=?','i',[$x['id']]),$x['actor']);
    }
    elseif ($x['op']==='claim_followup') {
        require_once $argv[1].'/src/Finance/FollowupAttempts.php';
        $db->begin_transaction();
        $r=achbotFollowupAttemptClaimLocked($db,achbotProviderRow($db,'SELECT * FROM payment_followup_intents WHERE id=? FOR UPDATE','i',[$x['id']]),0,null);
        $db->commit();
    }
    elseif ($x['op']==='maintain_followups') {
        $other=null;
        if (isset($x['block_service'])) {
            $other=new mysqli('localhost','root','',$argv[3],0,$argv[2]);
            $lock=achbotServiceExecutionLock($other,$x['block_service']);
            if ((int)achbotProviderRow($other,'SELECT GET_LOCK(?,0) AS acquired','s',[$lock])['acquired']!==1)
                throw new RuntimeException('fixture_block_service');
        }
        try {$r=achbotFollowupMaintainOriginalPending($db,$x['limit'],10.0);}
        finally {if ($other!==null) $other->close();}
    }
    elseif ($x['op']==='plan') $r=achbotPhysicalSelectPlan($db,$x['order'],$x['plan'],1001);
    elseif ($x['op']==='reassign') $r=achbotReassignLogicalServer($db,$x['order'],$x['server'],9001,true);
    elseif (in_array($x['op'],['delete','local_delete'],true)) {
        $o=achbotProviderRow($db,'SELECT * FROM orders_list WHERE id=?','i',[$x['order']]);
        if (!$o) $r='missing';
        elseif ($x['op']==='delete') $r=achbotSubscriptionDeleteConfirmed($db,$o,true);
        else $r=achbotAutoRenewPrepareDeletion($db,(int)$o['id']) &&
            achbotAutoRenewFinalizeDeletion($db,(int)$o['id'],(int)$o['server_id'],false);
    }
    elseif ($x['op']==='rebind') {
        $db->begin_transaction();
        achbotPhysicalAssertRebindSafe($db,$x['server'],false,$x['deny_active']);
        $db->rollback();$r=true;
    }
    elseif ($x['op']==='claim_delivery') {
        $db->begin_transaction();
        $d=achbotProviderRow($db,'SELECT * FROM payment_delivery_intents WHERE id=? FOR UPDATE','i',[$x['id']]);
        $p=achbotProviderRow($db,'SELECT *,1 AS due FROM payment_delivery_parts WHERE intent_id=? AND part_no=? FOR UPDATE','ii',[$x['id'],$x['part']??1]);
        $r=achbotDeliveryPartClaimLocked($db,$d,$p);$db->commit();
    }
    else {
        if (!$db->begin_transaction()) throw new RuntimeException('fixture_begin');
        $r=match($x['op']) {
            'issue'=>achbotServiceIssueLocked($db,(int)($x['owner']??1001),'fixture',$x['ref'],$x['quote']),
            'fund'=>achbotServiceFundLocked($db,(int)($x['owner']??1001),$x['id']),
            'alias'=>fixtureAlias($db,$x['order']),
            'pin'=>achbotCheckoutPinReceiptLocked($db,$x['pay'],1001),
            'settle'=>achbotCheckoutSettleReceiptLocked($db,$x['pay'],1001,'nextpay',
                ['reference'=>$x['reference'],'status'=>0,'amount'=>100]),

        };
        if (!$db->commit()) throw new RuntimeException('fixture_commit');
    }
    foreach ($GLOBALS['fixturePhotoPaths']??[] as $path) if (file_exists($path)) throw new RuntimeException('fixture_qr_temp_leaked');
    if ($x['lost_ack']??false) exit(19);
    echo json_encode(['ok'=>true,'result'=>$r],JSON_THROW_ON_ERROR);
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
        def sql(q):
            p=subprocess.run(client,input=q,text=True,env=env,capture_output=True,timeout=15)
            assert p.returncode==0,(q,p.stderr)
            return p.stdout.strip()
        lock=threading.Lock()
        users={};requests=[];faults=[];errors=[]
        entered=threading.Event();release=threading.Event()
        next_native_id=0
        def make_user(body,note=None):
            nonlocal next_native_id
            next_native_id+=1
            return dict(body,id=next_native_id,created_at='2026-01-01T00:00:00Z',
                note=body['note'] if note is None else note,used_traffic=0,
                subscription_url='https://fixture.invalid/sub/'+str(next_native_id),
                proxy_settings={'vless':{'id':'fixture'}})
        class Handler(BaseHTTPRequestHandler):
            def log_message(self,*a):pass
            def reply(self,status,data):
                wire=json.dumps(data).encode()
                self.send_response(status);self.send_header('Content-Type','application/json')
                self.send_header('Content-Length',str(len(wire)));self.end_headers()
                try:self.wfile.write(wire)
                except (BrokenPipeError,ConnectionResetError):pass
            def reply_links(self,status,link):
                data=link.encode()
                self.send_response(status);self.send_header('Content-Type','text/plain')
                self.send_header('Content-Length',str(len(data)));self.end_headers()
                self.wfile.write(data)
            def do_GET(self):
                try:
                    assert self.headers['X-Api-Key']=='fixture'
                    # Actual SQL writer on another connection proves no open
                    # InnoDB owner/effect lock spans the authenticated request.
                    sql('SET SESSION innodb_lock_wait_timeout=2; UPDATE users SET wallet=wallet WHERE userid=1001')
                    with lock:
                        requests.append(('GET',self.path))
                        if faults and faults[0]=='get_unavailable':
                            faults.pop(0);return self.reply(503,{})
                        if self.path=='/api/system':
                            return self.reply(200,{'version':'fixture'})
                        if self.path=='/api/admin':
                            return self.reply(200,{'role':{'is_owner':True,'access':{'require_template':False,'allowed_group_ids':[1,2]}}})
                        if self.path.startswith('/api/groups?'):
                            return self.reply(200,{'total':2,'groups':[
                                {'id':i,'name':'group','is_disabled':False,'inbound_tags':['vless']} for i in (1,2)]})
                        if self.path.startswith('/api/user/by-username/'):
                            u=users.get(unquote(self.path.rsplit('/',1)[1]))
                        elif re.fullmatch(r'/api/user/[0-9]+/subscription/links',self.path):
                            uid=int(self.path.split('/')[3])
                            assert any(v['id']==uid for v in users.values())
                            return self.reply_links(200,'vless://fixture-'+str(uid)+'@example.invalid:443#Original%20name\n')
                        elif self.path.startswith('/api/user/by-id/'):
                            u=next((v for v in users.values() if str(v['id'])==self.path.rsplit('/',1)[1]),None)
                        else:raise AssertionError(self.path)
                    self.reply(200,u) if u is not None else self.reply(404,{})
                except Exception as e:
                    errors.append(repr(e));self.reply(500,{})
            def do_edit(self):
                try:
                    assert self.headers['X-Api-Key']=='fixture'
                    path=self.path.split('/')
                    native_id=int(path[4])
                    phase='reset' if self.command=='POST' else 'modify'
                    state=sql("SELECT CONCAT(e.provider_state,':',m.phase,':',o.stage) "
                        "FROM service_effects e JOIN service_mutations m "
                        "ON m.account_effect_id=e.id AND e.provider_mutation_key=CONCAT('service:',m.service_operation_id) "
                        "JOIN service_operations o ON o.id=m.service_operation_id "
                        f"WHERE e.provider_remote_user_id={native_id}")
                    assert state=='mutating:'+phase+':dispatching',state
                    body=json.loads(self.rfile.read(int(self.headers.get('Content-Length',0)))) if self.command=='PUT' else {}
                    with lock:
                        u=next(v for v in users.values() if v['id']==native_id)
                        requests.append(('RESET' if phase=='reset' else 'PUT',body.copy()))
                        fault=faults.pop(0) if faults else ''
                        if fault==phase+'_unavailable':return self.reply(503,{})
                        if phase=='reset':
                            assert path[-1]=='reset'
                            u['used_traffic']=0
                        else:
                            assert set(body)<={'data_limit','expire','status'}
                            u.update(body)
                        ack=u.copy()
                        if fault=='reset_traffic':
                            u['used_traffic']=123
                    if fault==phase+'_lost':return self.reply(503,{})
                    self.reply(200,ack)
                except Exception as e:
                    errors.append(repr(e));self.reply(500,{})
            def do_DELETE(self):
                try:
                    assert self.headers['X-Api-Key']=='fixture'
                    native_id=int(self.path.rsplit('/',1)[1])
                    state=sql("SELECT CONCAT(e.provider_state,':',o.status) FROM service_effects e "
                        "JOIN orders_list o ON o.provider_effect_id=e.id "
                        f"WHERE e.provider_remote_user_id={native_id}")
                    assert state=='mutating:0',state
                    with lock:
                        requests.append(('DELETE',native_id))
                        name=next(k for k,v in users.items() if v['id']==native_id)
                        del users[name]
                    self.send_response(204);self.end_headers()
                except Exception as e:
                    errors.append(repr(e));self.reply(500,{})
            def do_PUT(self):
                self.do_edit()
            def do_POST(self):
                if self.path.endswith('/reset'):return self.do_edit()
                try:
                    assert self.path=='/api/user' and self.headers['X-Api-Key']=='fixture'
                    body=json.loads(self.rfile.read(int(self.headers['Content-Length'])))
                    assert body['note'].startswith('achbot:v1:')
                    # Check committed dispatch intent before every POST.
                    state=sql("SELECT CONCAT(state,':',provider_state) FROM service_effects WHERE operation_id='"+body['note'].split(':')[-1]+"'")
                    assert state=='dispatching:dispatching',state
                    with lock:
                        requests.append(('POST',body.copy()))
                        fault=faults.pop(0) if faults else ''
                        if fault=='post_unavailable':return self.reply(503,{})
                        assert body['username'] not in users,'unexpected duplicate POST'
                        u=make_user(body);users[body['username']]=u
                    if fault=='pause':
                        entered.set()
                        assert release.wait(10)
                    if fault=='lost_response':return self.reply(503,{})
                    if fault=='conflict_after_create':return self.reply(409,{})
                    self.reply(201,u)
                except Exception as e:
                    errors.append(repr(e));self.reply(500,{})
        server=ThreadingHTTPServer(('127.0.0.1',0),Handler)
        thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
        base='http://127.0.0.1:'+str(server.server_port)
        try:
            for m in ('001_marzban_panel_group_id.sql','003_payment_attempts.sql',
                      '007_service_effects.sql','008_referral_awards.sql','011_provider_identity.sql','012_shared_provider_storage.sql',
                      '014_api_payment_evidence.sql','017_shared_plan_changes.sql','018_payment_delivery_intents.sql','020_payment_followup_intents.sql','022_gateway_review_archive.sql',
                      '023_scoped_payment_lock_indexes.sql','024_wallet_entries.sql',
                      '025_wallet_receipt_credit.sql','026_service_operations.sql'):
                sql((ROOT/'migrations/post-v9'/m).read_text())
            # Rehearse forward alteration with an original 018 row present.
            sql("""INSERT INTO pays(hash_id,user_id,type,price,request_date,state)
                VALUES ('legacy-delivery-fixture',1001,'INCREASE_WALLET',100,1700000000,'paid');
                INSERT INTO payment_attempts(pay_row_id,provider,order_hash,quote_amount,quote_currency,local_amount_toman,stage)
                SELECT id,'nextpay',hash_id,price,'toman',price,'done' FROM pays WHERE hash_id='legacy-delivery-fixture';
                INSERT INTO payment_delivery_intents(pay_row_id,ordinal,attempt_id,user_id,pay_type,pay_fingerprint,source_digest,sealed_payload,payload_sha256,state)
                SELECT p.id,1,a.id,p.user_id,p.type,REPEAT('a',64),REPEAT('b',64),'original-packet',REPEAT('c',64),'unknown'
                FROM pays p JOIN payment_attempts a ON a.pay_row_id=p.id WHERE p.hash_id='legacy-delivery-fixture';""")
            original=sql("SELECT id,pay_row_id,attempt_id,pay_type,sealed_payload,state FROM payment_delivery_intents")
            sql((ROOT/'migrations/post-v9/027_service_delivery_sources.sql').read_text())
            sql((ROOT/'migrations/post-v9/028_service_mutations.sql').read_text())
            assert sql("SELECT id,pay_row_id,attempt_id,pay_type,sealed_payload,state FROM payment_delivery_intents")==original
            sql("""INSERT INTO referral_awards(pay_row_id,buyer_userid,inviter_userid,amount_toman,state,before_balance,after_balance)
                SELECT id,1001,9002,50,'CONFIRMED',0,50 FROM pays WHERE hash_id='legacy-delivery-fixture';
                INSERT INTO payment_followup_intents(pay_row_id,intent_key,stage,kind,role,recipient,pay_fingerprint,sealed_payload,payload_sha256,state)
                SELECT id,REPEAT('a',64),'referral_reward','send','referral_inviter','9002',REPEAT('b',64),'historical-packet',REPEAT('c',64),'unknown'
                FROM pays WHERE hash_id='legacy-delivery-fixture'""")
            original_award=sql("SELECT id,pay_row_id,amount_toman,state,before_balance,after_balance FROM referral_awards")
            original_report=sql("SELECT id,pay_row_id,sealed_payload,state FROM payment_followup_intents")
            sql((ROOT/'migrations/post-v9/029_service_purchase_referral.sql').read_text())
            sql((ROOT/'migrations/post-v9/030_service_followup_sources.sql').read_text())
            sql((ROOT/'migrations/post-v9/031_service_followup_attempts.sql').read_text())
            sql("UPDATE payment_delivery_intents SET attempt_count=1 WHERE pay_row_id IS NOT NULL")
            sql("INSERT INTO payment_delivery_attempts(intent_id,attempt_no,dispatch_token,result) SELECT id,1,REPEAT('d',32),'unknown' FROM payment_delivery_intents WHERE pay_row_id IS NOT NULL")
            original_delivery=sql("SELECT id,pay_row_id,attempt_id,sealed_payload,state,attempt_count FROM payment_delivery_intents")
            original_attempt=sql("SELECT id,intent_id,attempt_no,dispatch_token,result FROM payment_delivery_attempts")
            sql((ROOT/'migrations/post-v9/032_service_delivery_parts.sql').read_text())
            assert sql("SELECT id,pay_row_id,attempt_id,sealed_payload,state,attempt_count FROM payment_delivery_intents")==original_delivery
            assert sql("SELECT id,intent_id,attempt_no,dispatch_token,result FROM payment_delivery_attempts")==original_attempt
            assert sql("SELECT COUNT(*) FROM payment_delivery_parts")=='0'
            assert sql("SELECT COUNT(*) FROM payment_delivery_attempts WHERE part_no IS NOT NULL")=='0'
            assert sql("SELECT id,pay_row_id,amount_toman,state,before_balance,after_balance FROM referral_awards")==original_award
            assert sql("SELECT id,pay_row_id,sealed_payload,state FROM payment_followup_intents")==original_report
            assert sql("SELECT COUNT(*) FROM referral_awards WHERE service_operation_id IS NOT NULL")=='0'
            sql("""CREATE TABLE fixture_followup(id INT AUTO_INCREMENT PRIMARY KEY,intent_id BIGINT UNSIGNED NOT NULL,
                recipient VARCHAR(128),text TEXT,mode VARCHAR(20)) ENGINE=InnoDB""")
            sql("""CREATE TABLE fixture_telegram (
                id INT AUTO_INCREMENT PRIMARY KEY, intent_id BIGINT UNSIGNED NOT NULL,
                user_id BIGINT NOT NULL, part_no INT NOT NULL,kind VARCHAR(12), qr TEXT, caption TEXT, keys_json TEXT, mode VARCHAR(12)) ENGINE=InnoDB""")

            sql(f"""INSERT INTO server_config(id,panel_url,ip,sni,header_type,request_header,response_header,security,tlsSettings,type,username,password)
                VALUES (2,'{base}','127.0.0.1','','none','','','none','{{}}','pasarguard','fixture','fixture');
                INSERT INTO server_info(id,title,ucount,remark,flag,active,state) VALUES (2,'Fixture PG','100','pgfixture','',1,1);
                UPDATE server_config SET provider_connection_id=2,provider_instance_key=REPEAT('a',32),
                    provider_version='5.5.1',provider_enabled=1 WHERE id=2;
                INSERT INTO server_plans(id,fileid,catid,server_id,inbound_id,acount,title,protocol,days,volume,type,price,descr,pic,active,step,date,custom_sni,provider_group_ids)
                VALUES (7,'7',1,2,0,6,'Fixture PG','vless',30,2,'normal',100,'description','',1,0,'fixture',NULL,'[1,2]');""")
            sql(f"""INSERT INTO server_config(id,panel_url,ip,sni,header_type,request_header,response_header,security,tlsSettings,type,username,password,provider_connection_id,provider_instance_key,provider_version,provider_enabled)
                VALUES (3,'{base}','127.0.0.1','','none','','','none','{{}}','pasarguard','fixture','fixture',2,REPEAT('a',32),'5.5.1',1);
                INSERT INTO server_info(id,title,ucount,remark,flag,active,state) VALUES (3,'Fixture alias','200','pgalias','',1,1);
                INSERT INTO setting(type,value) VALUES ('ACHBOT_MARZBAN_PANEL_GROUP','fixture group');
                UPDATE server_info SET panel_group_id=LAST_INSERT_ID() WHERE id IN (2,3);
                INSERT INTO server_plans(id,fileid,catid,server_id,inbound_id,acount,title,protocol,days,volume,type,price,descr,pic,active,step,date,custom_sni,provider_group_ids)
                VALUES (8,'8',1,2,0,6,'Fixture PG next','vless',30,2,'normal',100,'description','',1,0,'fixture',NULL,'[1,2]'),
                       (9,'9',1,3,0,6,'Fixture PG alias','vless',30,2,'normal',100,'description','',1,0,'fixture',NULL,'[1,2]');""")
            sql('UPDATE users SET wallet=50000 WHERE userid=9002')
            driver=work/'execution.php';driver.write_text(PHP)
            def php(mode,op,**kw):
                p=subprocess.run(['php','-n','-d','extension=mysqlnd','-d','extension=mysqli','-d','extension=curl','-d','extension=gd',
                    '-d','allow_url_fopen=0','-d','disable_functions=exec,shell_exec,system,passthru,popen,proc_open,mail',
                    str(driver),str(ROOT),str(work/'mysql.sock'),DB,mode,json.dumps(dict(op=op,**kw))],
                    env=env,text=True,capture_output=True,timeout=25)
                if kw.get('crash_after_part'):
                    assert p.returncode==29 and not p.stdout,(p.returncode,p.stdout,p.stderr)
                    return None
                if kw.get('lost_ack'):
                    assert p.returncode==19 and not p.stdout,(p.returncode,p.stdout,p.stderr)
                    return None
                assert p.returncode==0,(p.returncode,p.stdout,p.stderr)
                for line in p.stderr.splitlines():
                    assert line.startswith(('[AChBot] ','achbot_auto_renew_','achbot_shared_plan_','achbot_shared_transfer_','achbot_subscription_delete_')) or re.fullmatch(r'achbot_followup_ack_unknown id=[1-9][0-9]*|achbot_qr_prepare_failed class=RuntimeException',line),line
                try:r=json.loads(p.stdout)
                except ValueError as e:raise AssertionError((op,p.stdout,p.stderr)) from e
                if 'expect_error' in kw:
                    assert not r['ok'] and r['reason']==kw['expect_error'],r
                    return r['reason']
                assert r['ok'],r
                return r['result']
            def issue(mode,ref,quantity=1,server=2,plan=7):
                return php(mode,'issue',ref=mode+'-'+ref,quote=dict(kind='BUY',variant='standard',price=100,
                    server_id=server,plan_id=plan,order_id=0,quantity=quantity,days=30,volume=2,
                    remark=mode+'-'+ref+'-12345',uuid='',naming_mode='digits',explicit_name=False))
            def step(mode,id,status,reason=None):
                r=php(mode,'step',id=id)
                assert r['status']==status and (reason is None or r.get('reason')==reason),(id,r,status,reason)
                return r
            def retry(id):
                sql(f'UPDATE service_operations SET next_attempt_at=NOW(6) WHERE id={id}')
            def fund(mode,id):
                assert php(mode,'fund',id=id)=='funded'
            def finance():
                return tuple(sql(q) for q in ('SELECT userid,wallet FROM users ORDER BY id',
                    'SELECT source_kind,source_ref,delta_toman FROM wallet_entries ORDER BY id',
                    'SELECT id,ucount FROM server_info ORDER BY id'))
            def rows(id):
                return sql(f"SELECT ordinal,operation_id,remote_username,requested_expire,requested_bytes,state,COALESCE(provider_remote_user_id,0) FROM service_effects WHERE source_kind='service_operation' AND source_ref='{id}' ORDER BY ordinal").splitlines()
            def posts():
                return sum(v[0]=='POST' for v in requests)
            def edit(mode,ref,order,kind,days=0,volume=0,owner=1001,policy=None):
                sid,plan,remark,uuid=sql(f"SELECT server_id,fileid,remark,uuid FROM orders_list WHERE id={order}").split('\t')
                return php(mode,'issue',owner=owner,ref=mode+'-'+ref,
                    quote=dict(kind=kind,variant='managed',price=100,server_id=int(sid),plan_id=int(plan),
                        order_id=order,quantity=1,days=days,volume=volume,remark=remark,uuid=uuid,
                        policy=policy or {}))
            def invoice(mode,ref,op=None):
                ref=mode+'-'+ref
                sql(f"""INSERT INTO pays(hash_id,user_id,type,price,request_date,state,payid)
                    VALUES ('{ref}',1001,'INCREASE_WALLET',100,UNIX_TIMESTAMP(),'pending','inv-{ref}');
                    INSERT INTO payment_attempts(pay_row_id,provider,order_hash,provider_reference,quote_amount,quote_currency,local_amount_toman,checkout_url,wallet_invoice_sha256,service_operation_id)
                    SELECT id,'nextpay',hash_id,'inv-{ref}',100,'toman',100,'https://fixture.invalid/checkout','',{op if op is not None else 'NULL'} FROM pays WHERE hash_id='{ref}';""")
                pid=int(sql(f"SELECT id FROM pays WHERE hash_id='{ref}'"))
                php(mode,'pin',pay=pid)
                return pid,'inv-'+ref
            def settle(mode,receipt):
                return php(mode,'settle',pay=receipt[0],reference=receipt[1])
            def award(op):
                return sql(f"SELECT CONCAT(pay_row_id,':',amount_toman,':',state) FROM referral_awards WHERE service_operation_id={op}")
            def mutation(id):
                return sql(f"SELECT phase,COALESCE(intent_sha256,''),COALESCE(reset_ack_at,''),COALESCE(audit_id,0) FROM service_mutations WHERE service_operation_id={id}")
            def writes():
                return [v for v in requests if v[0] in ('RESET','PUT')]
            def native(order):
                remark=sql(f"SELECT remark FROM orders_list WHERE id={order}")
                return users[remark]
            for mode in ('strict','off'):
                # Isolate each test mode's scheduler from previously deferred
                # conflict fixtures; no terminal evidence is changed.
                sql("UPDATE service_operations SET next_attempt_at=DATE_ADD(NOW(6),INTERVAL 1 DAY) WHERE stage IN ('funded','prepared','dispatching','applied')")
                sql("UPDATE payment_delivery_intents SET updated_at=NOW() WHERE service_operation_id IS NOT NULL AND state='pending'")
                # No order exists yet. The committed reservation must block
                # endpoint rebinding, but not ordinary credential rotation.
                original=issue(mode,'rebind-buy',server=3,plan=9);fund(mode,original)
                before=finance()
                assert php(mode,'rebind',server=3,deny_active=True,expect_error='physical_rebind_pending_service_operation')
                assert php(mode,'rebind',server=3,deny_active=False) is True
                assert finance()==before
                step(mode,original,'applied');step(mode,original,'completed')
                rebind_order=int(sql(f"SELECT order_id FROM service_effects WHERE source_kind='service_operation' AND source_ref='{original}'"))
                assert php(mode,'delete',order=rebind_order)=='complete'
                assert php(mode,'rebind',server=3,deny_active=True) is True
                cases.append(mode+'_funded_buy_before_first_order_blocks_endpoint_rebind')
                id=issue(mode,'bulk',3);n=len(requests)
                step(mode,id,'awaiting_funds');assert len(requests)==n and not rows(id)
                cases.append(mode+'_unfunded_no_io')
                fund(mode,id);before=finance();n=posts()
                step(mode,id,'progressed');assert posts()==n+1 and len(rows(id))==1
                faults.append('lost_response')
                step(mode,id,'pending','pg_vendor_unavailable')
                original=rows(id)[1].split('\t')[:5]
                assert posts()==n+2 and len(rows(id))==2
                step(mode,id,'not_due')
                retry(id);step(mode,id,'progressed')
                assert rows(id)[1].split('\t')[:5]==original and posts()==n+2
                step(mode,id,'applied');assert posts()==n+3 and len(rows(id))==3
                assert all('\tverified\t' in row for row in rows(id))
                assert len({row.split('\t')[2] for row in rows(id)})==3
                assert finance()==before
                cases.append(mode+'_bulk_lost_response_exact_ordinal')
                # Verify atomic finalization after the Native success is
                # already committed. A failed delivery insert rolls back the
                # Order and link. Retry is GET-only, never another CREATE.
                sql("CREATE TRIGGER fixture_delivery_fail BEFORE INSERT ON payment_delivery_intents FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture rollback'")
                try:
                    step(mode,id,'pending','provider_storage_unavailable')
                    assert sql(f"SELECT COUNT(*) FROM service_effects WHERE source_kind='service_operation' AND source_ref='{id}' AND order_id IS NOT NULL")=='0'
                    assert sql(f"SELECT COUNT(*) FROM payment_delivery_intents WHERE service_operation_id={id}")=='0'
                finally:sql('DROP TRIGGER fixture_delivery_fail')
                retry(id)
                step(mode,id,'progressed')
                assert sql(f"SELECT COUNT(*) FROM payment_delivery_intents WHERE service_operation_id={id}")=='1'
                first_delivery=int(sql(f"SELECT id FROM payment_delivery_intents WHERE service_operation_id={id} AND ordinal=1"))
                assert php(mode,'deliver',id=first_delivery)=='not_ready'
                first_order=int(sql(f"SELECT order_id FROM service_effects WHERE source_kind='service_operation' AND source_ref='{id}' AND ordinal=1"))
                reqs=len(requests)
                assert php(mode,'local_delete',order=first_order) is False
                assert php(mode,'plan',order=first_order,plan=8)['reason']=='physical_pending_financial_effect'
                assert finance()==before and len(requests)==reqs
                cases.append(mode+'_unfinished_bulk_ordinal_keeps_ownership')
                step(mode,id,'progressed')
                # The last ordinal and completed state commit together.
                php(mode,'step',id=id,lost_ack=True)
                step(mode,id,'completed')
                assert posts()==n+3 and finance()==before
                assert sql(f"SELECT COUNT(*),SUM(o.amount),SUM(o.provider_effect_id=e.id) FROM service_effects e JOIN orders_list o ON o.id=e.order_id WHERE e.source_kind='service_operation' AND e.source_ref='{id}'")=='3\t100\t3'
                assert sql(f"SELECT COUNT(*) FROM payment_delivery_intents WHERE service_operation_id={id} AND pay_row_id IS NULL AND attempt_id IS NULL AND state='pending'")=='3'
                assert all('\tlocal_recorded\t' in row for row in rows(id))
                reports=sql(f"SELECT id,role FROM payment_followup_intents WHERE service_operation_id={id} AND stage='service_completed' AND pay_row_id IS NULL ORDER BY role").splitlines()
                assert len(reports)==2,reports
                report_id=int(reports[0].split('\t')[0])
                reqs=len(requests)
                assert php(mode,'followup',id=report_id)=='acknowledged'
                assert php(mode,'followup',id=report_id)=='acknowledged'
                wire=sql(f"SELECT text FROM fixture_followup WHERE intent_id={report_id}")
                for row in rows(id):assert row.split('\t')[2] in wire,wire
                assert sql(f"SELECT COUNT(*) FROM fixture_followup WHERE intent_id={report_id}")=='1'
                assert finance()==before and len(requests)==reqs
                cases.append(mode+'_bulk_completion_single_original_report_before_customer_ack')
                cases.append(mode+'_atomic_order_delivery_and_completion')
                delivery_ids=list(map(int,sql(f"SELECT id FROM payment_delivery_intents WHERE service_operation_id={id} ORDER BY ordinal").splitlines()))
                # Actual common claim + finish journal with Telegram boundary
                # spy; no new money or Native work for acknowledgement loss.
                reqs=len(requests)
                with ThreadPoolExecutor(3) as ex:
                    outcomes=list(ex.map(lambda _:php(mode,'deliver',id=delivery_ids[0]),range(3)))
                assert 'acknowledged' in outcomes and set(outcomes)<={'acknowledged','busy'},outcomes
                assert sql(f"SELECT COUNT(*) FROM fixture_telegram WHERE intent_id={delivery_ids[0]}")=='2'
                wire=sql(f"SELECT user_id,qr,caption,keys_json FROM fixture_telegram WHERE intent_id={delivery_ids[0]} ORDER BY part_no")
                assert wire.startswith('1001\t') and 'fixture_bot' in wire,wire
                assert sql(f"SELECT sealed_payload FROM payment_delivery_intents WHERE id={delivery_ids[0]}").startswith('achpe1:')
                assert php(mode,'deliver',id=delivery_ids[1],responses={2:None})=='unknown'
                assert php(mode,'deliver',id=delivery_ids[1])=='unknown'
                assert sql(f"SELECT GROUP_CONCAT(CONCAT(part_no,':',state) ORDER BY part_no) FROM payment_delivery_parts WHERE intent_id={delivery_ids[1]}")=='1:acknowledged,2:unknown'
                assert sql(f"SELECT COUNT(*) FROM fixture_telegram WHERE intent_id={delivery_ids[1]}")=='2'
                token=php(mode,'preview_delivery',id=delivery_ids[1],part=2,actor=1001)
                assert php(mode,'deliver',id=delivery_ids[1],part=2,actor=1001,confirmation=token)=='denied'
                token=php(mode,'preview_delivery',id=delivery_ids[1],part=2,actor=9001)
                assert php(mode,'deliver',id=delivery_ids[1],part=2,actor=9001,confirmation='0'*20)=='stale'
                assert php(mode,'deliver',id=delivery_ids[1],part=2,actor=9001,confirmation=token,responses={2:None})=='unknown'
                token=php(mode,'preview_delivery',id=delivery_ids[1],part=2,actor=9001)
                assert php(mode,'deliver',id=delivery_ids[1],part=2,actor=9001,confirmation=token)=='stale'
                assert sql(f"SELECT COUNT(*) FROM fixture_telegram WHERE intent_id={delivery_ids[1]} AND part_no=1")=='1'
                assert sql(f"SELECT COUNT(*) FROM fixture_telegram WHERE intent_id={delivery_ids[1]} AND part_no=2")=='2'
                php(mode,'claim_delivery',id=delivery_ids[2])
                assert php(mode,'deliver',id=delivery_ids[2])=='unknown'
                assert sql(f"SELECT COUNT(*) FROM fixture_telegram WHERE intent_id={delivery_ids[2]} AND part_no=1")=='0'
                assert sql(f"SELECT COUNT(*) FROM fixture_telegram WHERE intent_id={delivery_ids[2]} AND part_no=2")=='1'
                assert sql(f"SELECT GROUP_CONCAT(CONCAT(part_no,':',state) ORDER BY part_no) FROM payment_delivery_parts WHERE intent_id={delivery_ids[2]}")=='1:unknown,2:acknowledged'
                token=php(mode,'preview_delivery',id=delivery_ids[2],part=1,actor=9001)
                assert php(mode,'deliver',id=delivery_ids[2],part=1,actor=9001,confirmation=token)=='acknowledged'
                assert php(mode,'deliver',id=delivery_ids[2])=='acknowledged'
                assert sql(f"SELECT COUNT(*) FROM payment_delivery_attempts a JOIN payment_delivery_intents d ON d.id=a.intent_id WHERE d.service_operation_id={id} AND a.result IN ('acknowledged','unknown')")=='8'
                assert len(requests)==reqs and finance()==before
                step(mode,id,'completed')
                cases.append(mode+'_delivery_ack_unknown_and_abandoned_claim')

                id=issue(mode,'unavailable');fund(mode,id);before=finance();n=posts()
                faults.append('get_unavailable');step(mode,id,'pending','pg_vendor_unavailable')
                original=rows(id)[0].split('\t')[:5];assert posts()==n
                retry(id);faults.append('post_unavailable');step(mode,id,'pending','pg_vendor_unavailable')
                assert posts()==n+1
                retry(id);step(mode,id,'applied')
                assert posts()==n+2 and rows(id)[0].split('\t')[:5]==original and finance()==before
                cases.append(mode+'_outage_same_identity')
                id=issue(mode,'conflict');fund(mode,id);before=finance();n=posts()
                faults.append('conflict_after_create');step(mode,id,'pending','pg_conflict')
                retry(id);step(mode,id,'applied')
                assert posts()==n+1 and finance()==before
                cases.append(mode+'_409_readback')
                id=issue(mode,'rollback');fund(mode,id);before=finance();n=posts()
                sql("""DELIMITER //
CREATE TRIGGER fixture_execution_failure BEFORE UPDATE ON service_effects FOR EACH ROW
                    BEGIN IF NEW.state='verified' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture rollback'; END IF; END//
DELIMITER ;
""")
                try:
                    step(mode,id,'pending','provider_storage_unavailable')
                    assert posts()==n+1 and '\tdispatching\t0' in rows(id)[0]
                finally:sql('DROP TRIGGER fixture_execution_failure')
                retry(id);step(mode,id,'applied')
                assert posts()==n+1 and finance()==before
                cases.append(mode+'_local_commit_failure_recovery')
                id=issue(mode,'foreign');fund(mode,id);before=finance();n=posts()
                faults.append('get_unavailable');step(mode,id,'pending')
                name=rows(id)[0].split('\t')[2]
                with lock:
                    users[name]=make_user(dict(username=name,note='foreign',data_limit=1,expire=1800000000,
                        group_ids=[1],status='active',data_limit_reset_strategy='no_reset',next_plan=None))
                retry(id);step(mode,id,'pending','pg_create_name_identity_conflict')
                assert posts()==n and finance()==before
                cases.append(mode+'_foreign_identity_no_overwrite')
                id=issue(mode,'repoint');fund(mode,id);before=finance();n=len(requests)
                sql("UPDATE server_config SET provider_instance_key=REPEAT('b',32) WHERE id=2")
                step(mode,id,'pending')
                assert len(requests)==n and not rows(id) and finance()==before
                sql("UPDATE server_config SET provider_instance_key=REPEAT('a',32) WHERE id=2")
                retry(id);step(mode,id,'applied')
                cases.append(mode+'_original_panel_only')
                # A sales-disabled server does not undo a verified purchase.
                sql('UPDATE server_info SET active=0 WHERE id=2')
                step(mode,id,'completed')
                sql('UPDATE server_info SET active=1 WHERE id=2')
                cases.append(mode+'_funded_order_finalizes_after_sales_disabled')
                delivery=int(sql(f"SELECT id FROM payment_delivery_intents WHERE service_operation_id={id}"))
                order=int(sql(f"SELECT order_id FROM payment_delivery_intents WHERE id={delivery}"))
                sql(f"UPDATE orders_list SET uuid='different-local-identity' WHERE id={order}")
                assert php(mode,'deliver',id=delivery)=='deferred'
                assert sql(f"SELECT COUNT(*) FROM fixture_telegram WHERE intent_id={delivery}")=='0'
                cases.append(mode+'_changed_order_no_stale_packet')
                next_id=issue(mode,'delivery-fair');fund(mode,next_id)
                step(mode,next_id,'applied');step(mode,next_id,'completed')
                next_delivery=int(sql(f"SELECT id FROM payment_delivery_intents WHERE service_operation_id={next_id}"))
                sql(f"UPDATE payment_delivery_intents SET updated_at=DATE_SUB(NOW(),INTERVAL 4 MINUTE) WHERE id IN ({delivery},{next_delivery})")
                reqs=len(requests);before=finance()
                stats=php(mode,'maintain_delivery',limit=2)
                assert stats==dict(checked=2,acknowledged=1,unknown=0,deferred=1),stats
                assert sql(f"SELECT COUNT(*) FROM fixture_telegram WHERE intent_id={next_delivery}")=='2'
                assert php(mode,'maintain_delivery',limit=2)['checked']==0
                assert len(requests)==reqs and finance()==before
                cases.append(mode+'_shared_delivery_worker_fair_progress')


                id=issue(mode,'evidence');fund(mode,id);before=finance()
                faults.append('get_unavailable');step(mode,id,'pending')
                sql(f"UPDATE service_effects SET provider_entitlement_snapshot=JSON_SET(provider_entitlement_snapshot,'$.data_limit',1) WHERE source_kind='service_operation' AND source_ref='{id}'")
                n=len(requests);retry(id);step(mode,id,'pending','service_buy_account_evidence_missing')
                assert len(requests)==n and finance()==before
                cases.append(mode+'_changed_local_entitlement_no_io')
                id=issue(mode,'concurrent');fund(mode,id);before=finance();n=posts()
                faults.append('pause');entered.clear();release.clear()
                with ThreadPoolExecutor(max_workers=2) as ex:
                    future=ex.submit(php,mode,'step',id=id)
                    try:
                        assert entered.wait(10),errors
                        step(mode,id,'busy')
                    finally:release.set()
                    assert future.result()['status']=='applied'
                assert posts()==n+1 and finance()==before
                cases.append(mode+'_session_owner')
                # One deferred operation cannot monopolize the due batch.
                sql("UPDATE service_operations SET next_attempt_at=DATE_ADD(NOW(6),INTERVAL 1 DAY) WHERE stage='applied'")
                first=issue(mode,'fair-first');fund(mode,first)
                second=issue(mode,'fair-second');fund(mode,second)
                before=finance();faults.append('get_unavailable')
                r=php(mode,'due',limit=2)
                assert r[str(first)]['status']=='pending' and r[str(second)]['status']=='applied',r
                assert finance()==before
                cases.append(mode+'_due_fair_progress')
                # A source-real CREATE and linked Order are the original
                # account for every subsequent edit; no fabricated identity.
                buy=issue(mode,'edit-source');fund(mode,buy)
                step(mode,buy,'applied');step(mode,buy,'completed')
                order=int(sql(f"SELECT order_id FROM service_effects WHERE source_kind='service_operation' AND source_ref='{buy}'"))
                effect=int(sql(f"SELECT provider_effect_id FROM orders_list WHERE id={order}"))
                native(order)['used_traffic']=456
                native(order)['expire']=1800000000
                renewal=edit(mode,'edit-renew',order,'RENEW',days=30,volume=2)
                fund(mode,renewal);before=finance();n=len(writes())
                step(mode,renewal,'progressed');original=mutation(renewal).split('\t')[1]
                assert mutation(renewal).startswith('reset\t')
                faults.append('reset_traffic')
                step(mode,renewal,'progressed')
                assert mutation(renewal).startswith('modify\t') and mutation(renewal).split('\t')[2]
                assert native(order)['used_traffic']==123
                faults.append('modify_unavailable')
                # Freeze a different expiry to ensure a real PUT is needed:
                # the provider's valid current baseline has already been
                # observed; use its existing expiry, not an injected drift.
                step(mode,renewal,'pending','pg_vendor_unavailable')
                retry(renewal);step(mode,renewal,'applied')
                assert mutation(renewal).split('\t')[1]==original
                assert [v[0] for v in writes()[n:]]==['RESET','PUT','PUT']
                cases.append(mode+'_reset_ack_with_new_traffic_no_repeat_on_put_outage')
                # Force local completion rollback after verified Native target.
                sql("CREATE TRIGGER fixture_edit_delivery_fail BEFORE INSERT ON payment_delivery_intents FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture rollback'")
                try:
                    step(mode,renewal,'pending','provider_storage_unavailable')
                    assert mutation(renewal).startswith('verified\t') and mutation(renewal).endswith('\t0')
                finally:sql('DROP TRIGGER fixture_edit_delivery_fail')
                reqs=len(requests)
                retry(renewal);php(mode,'step',id=renewal,lost_ack=True)
                step(mode,renewal,'completed')
                assert len(requests)==reqs and finance()==before
                assert mutation(renewal).startswith('completed\t')
                assert sql(f"SELECT CONCAT(provider_state,':',COALESCE(provider_mutation_key,'')) FROM service_effects WHERE id={effect}")=='linked:'
                delivery=int(sql(f"SELECT id FROM payment_delivery_intents WHERE service_operation_id={renewal}"))
                assert php(mode,'deliver',id=delivery)=='acknowledged'
                assert php(mode,'deliver',id=delivery)=='acknowledged'
                assert sql(f"SELECT COUNT(*) FROM fixture_telegram WHERE intent_id={delivery}")=='1'
                cases.append(mode+'_edit_local_atomic_finality_no_remote_replay')
                # Another local reference to the same physical account
                # uses exactly the same anchor and waits without HTTP.
                alias=php(mode,'alias',order=order)
                volume=edit(mode,'edit-volume',order,'ADD_VOLUME',volume=1)
                time_edit=edit(mode,'edit-time',alias,'ADD_TIME',days=3,owner=9002)
                fund(mode,volume);assert php(mode,'fund',owner=9002,id=time_edit)=='funded'
                before=finance();n=len(writes())
                step(mode,volume,'progressed');original=mutation(volume).split('\t')[1]
                reqs=len(requests)
                step(mode,time_edit,'pending','service_edit_account_busy')
                assert len(requests)==reqs
                faults.append('modify_lost')
                quota=native(order)['data_limit']
                step(mode,volume,'pending','pg_vendor_unavailable')
                assert native(order)['data_limit']==quota+1073741824
                retry(volume);step(mode,volume,'applied');step(mode,volume,'completed')
                assert mutation(volume).split('\t')[1]==original
                assert writes()[n:]==[('PUT',{'data_limit':quota+1073741824})]
                retry(time_edit);step(mode,time_edit,'progressed')
                step(mode,time_edit,'applied');step(mode,time_edit,'completed')
                assert len(writes())==n+2 and set(writes()[-1][1])=={'expire'}
                assert native(order)['data_limit']==quota+1073741824 and finance()==before
                cases.append(mode+'_alias_fence_and_lost_put_absolute_addons')
                # A different authoritative entitlement is never overwritten.
                conflict=edit(mode,'edit-drift',order,'ADD_VOLUME',volume=1)
                fund(mode,conflict);step(mode,conflict,'progressed')
                native(order)['data_limit']+=7
                n=len(writes());before=finance()
                step(mode,conflict,'pending','pg_service_edit_entitlement_conflict')
                assert len(writes())==n and finance()==before
                cases.append(mode+'_edit_external_drift_no_blind_overwrite')
                # Scheduled renewal uses the exact same operation/physical
                # journal, without creating a synthetic payment or attempt.
                buy=issue(mode,'auto-source');fund(mode,buy)
                step(mode,buy,'applied');step(mode,buy,'completed')
                ao=int(sql(f"SELECT order_id FROM service_effects WHERE source_kind='service_operation' AND source_ref='{buy}'"))
                alias=php(mode,'alias',order=ao)
                sql(f"UPDATE orders_list SET fileid=7,notif=1,autoRenew='on' WHERE id IN ({ao},{alias})")
                native(ao)['used_traffic']=native(ao)['data_limit']
                # Force a different actual expiry, irrespective of test speed.
                native(ao)['expire']=1800000000
                n=len(writes());pays=sql("SELECT COUNT(*) FROM pays");attempts=sql("SELECT COUNT(*) FROM payment_attempts")
                f0=finance()
                assert php(mode,'auto',order=ao,bot_state={'renewAutoState':'off'})['status']=='ineligible'
                assert finance()==f0
                # Both competing owners hold active local aliases.
                with ThreadPoolExecutor(max_workers=2) as ex:
                    a=ex.submit(php,mode,'auto',order=ao)
                    b=ex.submit(php,mode,'auto',order=alias,owner=9002)
                    results=[a.result(),b.result()]
                assert sorted(r['status'] for r in results)==['account_busy','funded'],results
                winner=next(r['id'] for r in results if r['status']=='funded')
                owner_order=ao if results[0]['status']=='funded' else alias
                uid=1001 if owner_order==ao else 9002
                assert php(mode,'auto',order=owner_order,owner=uid)=={'status':'already_claimed','id':winner}
                assert len(writes())==n and mutation(winner).startswith('reset')
                before=finance()
                step(mode,winner,'progressed');step(mode,winner,'applied')
                reqs=len(requests)
                sql("CREATE TRIGGER fixture_auto_report_fail BEFORE INSERT ON payment_followup_intents FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture rollback'")
                try:
                    step(mode,winner,'pending')
                    assert mutation(winner).startswith('verified\t') and mutation(winner).endswith('\t0')
                    assert sql(f"SELECT COUNT(*) FROM payment_delivery_intents WHERE service_operation_id={winner}")=='0'
                    assert sql(f"SELECT COUNT(*) FROM payment_followup_intents WHERE service_operation_id={winner}")=='0'
                    assert finance()==before and len(requests)==reqs
                finally:sql('DROP TRIGGER fixture_auto_report_fail')
                retry(winner);php(mode,'step',id=winner,lost_ack=True)
                step(mode,winner,'completed')
                assert finance()==before and len(writes())==n+2 and len(requests)==reqs
                cases.append(mode+'_auto_report_rollback_local_only_recovery')
                assert sql("SELECT COUNT(*) FROM pays")==pays and sql("SELECT COUNT(*) FROM payment_attempts")==attempts
                assert sql(f"SELECT COUNT(*) FROM wallet_entries WHERE source_kind='service_debit' AND source_ref='{winner}'")== '1'
                loser=alias if owner_order==ao else ao
                assert php(mode,'auto',order=loser,owner=9002 if loser==alias else 1001)['status']=='ineligible'
                did=int(sql(f"SELECT id FROM payment_delivery_intents WHERE service_operation_id={winner}"))
                assert php(mode,'deliver',id=did)=='acknowledged'
                assert sql(f"SELECT COUNT(*) FROM fixture_telegram WHERE intent_id={did} AND user_id={uid} AND caption LIKE '%rn_%'")=='1'
                reports=sql(f"SELECT id,role,pay_row_id FROM payment_followup_intents WHERE service_operation_id={winner} ORDER BY id").splitlines()
                assert len(reports)==2 and all(x.endswith('\tNULL') for x in reports),reports
                sql("UPDATE payment_followup_intents SET updated_at=NOW() WHERE state='pending' AND next_attempt_at IS NULL")
                report_id=int(reports[0].split('\t')[0])
                rate_limit=dict(ok=False,error_code=429,description='Too Many Requests',parameters=dict(retry_after=35))
                assert php(mode,'followup',id=report_id,response=rate_limit)=='pending'
                assert sql(f"SELECT CONCAT(result,':',telegram_error_code,':',retry_after_seconds) FROM payment_followup_attempts WHERE intent_id={report_id}")=='rejected:429:35'
                assert sql(f"SELECT next_attempt_at>NOW(6) FROM payment_followup_intents WHERE id={report_id}")=='1'
                assert php(mode,'followup',id=report_id)=='not_due'
                assert php(mode,'maintain_followups',limit=10)==[]
                assert sql(f"SELECT COUNT(*) FROM fixture_followup WHERE intent_id={report_id}")=='1'
                sql(f"UPDATE payment_followup_intents SET next_attempt_at=NOW(6) WHERE id={report_id}")
                assert php(mode,'maintain_followups',limit=10,block_service=winner)=={str(report_id):'busy'}
                assert sql(f"SELECT next_attempt_at>NOW(6) FROM payment_followup_intents WHERE id={report_id}")=='1'
                assert php(mode,'maintain_followups',limit=10)==[]
                assert sql(f"SELECT COUNT(*) FROM fixture_followup WHERE intent_id={report_id}")=='1'
                cases.append(mode+'_followup_busy_due_row_deferred_fairly')
                sql(f"UPDATE payment_followup_intents SET next_attempt_at=NOW(6) WHERE id={report_id}")
                assert php(mode,'maintain_followups',limit=10)=={str(report_id):'acknowledged'}
                assert php(mode,'followup',id=report_id)=='acknowledged'
                assert sql(f"SELECT COUNT(*) FROM fixture_followup WHERE intent_id={report_id} AND text LIKE '%rn_%'")=='2'
                assert sql(f"SELECT GROUP_CONCAT(result ORDER BY attempt_no) FROM payment_followup_attempts WHERE intent_id={report_id}")=='rejected,acknowledged'
                cases.append(mode+'_followup_known_429_due_redelivery')
                report_id=int(reports[1].split('\t')[0])
                assert php(mode,'followup',id=report_id,response=dict(ok=False,error_code=500,description='Internal error'))=='unknown'
                assert php(mode,'followup',id=report_id)=='unknown'
                denied=php(mode,'preview_followup',id=report_id,actor=1001)
                assert php(mode,'followup',id=report_id,actor=1001,confirmation=denied)=='denied'
                preview=php(mode,'preview_followup',id=report_id,actor=9001)
                assert php(mode,'followup',id=report_id,actor=9001,confirmation='0'*20)=='stale'
                assert sql(f"SELECT COUNT(*) FROM fixture_followup WHERE intent_id={report_id}")=='1'
                php(mode,'followup',id=report_id,actor=9001,confirmation=preview,lost_ack=True)
                assert php(mode,'followup',id=report_id)=='acknowledged'
                assert php(mode,'followup',id=report_id,actor=9001,confirmation=preview)=='stale'
                assert sql(f"SELECT COUNT(*) FROM fixture_followup WHERE intent_id={report_id}")=='2'
                assert sql(f"SELECT GROUP_CONCAT(CONCAT(claim_state,':',result,':',actor_userid) ORDER BY attempt_no) FROM payment_followup_attempts WHERE intent_id={report_id}")=='pending:unknown:0,unknown:acknowledged:9001'
                cases.append(mode+'_followup_unknown_explicit_actor_bound_redrive')
                assert finance()==before and len(writes())==n+2
                cases.append(mode+'_automatic_renewal_source_only_reports_independent_ack')
                cases.append(mode+'_automatic_renewal_alias_single_charge_original_notice')
                # Original low-balance policy: disable auto renewal, no debit,
                # and no synthetic paid invoice or remote write.
                buy=issue(mode,'auto-low');fund(mode,buy);step(mode,buy,'applied');step(mode,buy,'completed')
                lo=int(sql(f"SELECT order_id FROM service_effects WHERE source_kind='service_operation' AND source_ref='{buy}'"))
                sql(f"UPDATE orders_list SET notif=1,autoRenew='on' WHERE id={lo}")
                native(lo)['used_traffic']=native(lo)['data_limit']
                saved=sql("SELECT wallet FROM users WHERE userid=1001")
                sql("UPDATE users SET wallet=0 WHERE userid=1001")
                before=finance();n=len(writes())
                sql("CREATE TRIGGER fixture_low_report_fail BEFORE INSERT ON payment_followup_intents FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture rollback'")
                try:
                    assert php(mode,'auto',order=lo)['status']=='deferred'
                    assert sql(f"SELECT autoRenew FROM orders_list WHERE id={lo}")=='on'
                    assert sql(f"SELECT COUNT(*) FROM service_operations WHERE command_kind='automatic_renewal_refusal' AND subject_order_id={lo}")=='0'
                    assert finance()==before and len(writes())==n
                finally:sql('DROP TRIGGER fixture_low_report_fail')
                php(mode,'auto',order=lo,lost_ack=True)
                cases.append(mode+'_auto_low_wallet_report_rollback_and_lost_commit_ack')
                assert sql(f"SELECT autoRenew FROM orders_list WHERE id={lo}")=='off'
                assert finance()==before and len(writes())==n
                sql(f"UPDATE users SET wallet={saved} WHERE userid=1001")
                refusal=int(sql(f"SELECT id FROM service_operations WHERE command_kind='automatic_renewal_refusal' AND subject_order_id={lo}"))
                assert sql(f"SELECT CONCAT(stage,':',failure_code,':',COALESCE(debit_entry_id,0)) FROM service_operations WHERE id={refusal}")=='cancelled:wallet_balance_insufficient:0'
                notice=int(sql(f"SELECT id FROM payment_followup_intents WHERE service_operation_id={refusal} AND pay_row_id IS NULL AND stage='auto_renewal_low_wallet'"))
                assert php(mode,'auto',order=lo)['status']=='ineligible'
                assert php(mode,'followup',id=notice,response=dict(ok=False,error_code=403,description='Forbidden'))=='rejected'
                assert php(mode,'followup',id=notice)=='rejected'
                assert sql(f"SELECT COUNT(*) FROM fixture_followup WHERE intent_id={notice}")=='1'
                preview=php(mode,'preview_followup',id=notice,actor=9001)
                assert php(mode,'followup',id=notice,actor=9001,confirmation=preview)=='acknowledged'
                assert php(mode,'followup',id=notice)=='acknowledged'
                assert sql(f"SELECT COUNT(*) FROM fixture_followup WHERE intent_id={notice} AND recipient='1001'")=='2'
                cases.append(mode+'_followup_definite_permanent_rejection_authorized_retry')
                # A later intentional re-enable can still renew the original cycle.
                sql(f"UPDATE orders_list SET autoRenew='on' WHERE id={lo}")
                r=php(mode,'auto',order=lo);assert r['status']=='funded',r
                aid=r['id'];step(mode,aid,'progressed');step(mode,aid,'applied');step(mode,aid,'completed')
                for row in sql(f"SELECT id FROM payment_followup_intents WHERE service_operation_id={aid}").splitlines():
                    assert php(mode,'followup',id=int(row))=='acknowledged'
                cases.append(mode+'_auto_low_wallet_durable_notice_and_deliberate_reenable')
                cases.append(mode+'_automatic_renewal_low_wallet_no_charge')
                # The original fresh observation is frozen with funding,
                # rather than recomputed after an independent native edit.
                buy=issue(mode,'auto-drift');fund(mode,buy);step(mode,buy,'applied');step(mode,buy,'completed')
                od=int(sql(f"SELECT order_id FROM service_effects WHERE source_kind='service_operation' AND source_ref='{buy}'"))
                sql(f"UPDATE orders_list SET notif=1,autoRenew='on' WHERE id={od}")
                native(od)['used_traffic']=native(od)['data_limit']
                r=php(mode,'auto',order=od);assert r['status']=='funded',r
                aid=r['id'];assert mutation(aid).startswith('reset')
                native(od)['data_limit']+=7
                before=finance();n=len(writes())
                step(mode,aid,'pending','pg_service_reset_baseline_conflict')
                assert finance()==before and len(writes())==n
                cases.append(mode+'_automatic_renewal_funded_baseline_not_recomputed')
                # Full agent discount is allowed even with signed debt.
                buy=issue(mode,'auto-free');fund(mode,buy);step(mode,buy,'applied');step(mode,buy,'completed')
                od=int(sql(f"SELECT order_id FROM service_effects WHERE source_kind='service_operation' AND source_ref='{buy}'"))
                sql(f"UPDATE orders_list SET notif=1,autoRenew='on' WHERE id={od}")
                saved=sql("SELECT wallet FROM users WHERE userid=1001")
                sql("UPDATE users SET wallet=-10,is_agent=1,discount_percent='"+json.dumps({'normal':100})+"' WHERE userid=1001")
                native(od)['used_traffic']=native(od)['data_limit'];native(od)['expire']=1800000000
                before=finance();n=len(writes())
                r=php(mode,'auto',order=od);assert r['status']=='funded',r
                aid=r['id'];step(mode,aid,'progressed');step(mode,aid,'applied');step(mode,aid,'completed')
                assert finance()==before and len(writes())==n+2
                assert sql(f"SELECT price_toman,debit_entry_id FROM service_operations WHERE id={aid}")=='0\tNULL'
                did=int(sql(f"SELECT id FROM payment_delivery_intents WHERE service_operation_id={aid}"))
                assert php(mode,'deliver',id=did)=='acknowledged'
                sql(f"UPDATE users SET wallet={saved},is_agent=0,discount_percent='' WHERE userid=1001")
                rids=[int(row) for row in sql(f"SELECT id FROM payment_followup_intents WHERE service_operation_id={aid} ORDER BY id").splitlines()]
                assert len(rids)==2
                before=finance();n=len(writes());rid=rids[0]
                sql("CREATE TRIGGER fixture_followup_result_fail BEFORE UPDATE ON payment_followup_attempts FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture acknowledgement rollback'")
                try:
                    assert php(mode,'followup',id=rid)=='unknown'
                    assert sql(f"SELECT state FROM payment_followup_intents WHERE id={rid}")=='dispatching'
                    assert sql(f"SELECT result FROM payment_followup_attempts WHERE intent_id={rid}")=='dispatching'
                    assert sql(f"SELECT COUNT(*) FROM fixture_followup WHERE intent_id={rid}")=='1'
                finally:sql('DROP TRIGGER fixture_followup_result_fail')
                assert php(mode,'followup',id=rid)=='unknown'
                assert sql(f"SELECT result FROM payment_followup_attempts WHERE intent_id={rid}")=='unknown'
                assert php(mode,'followup',id=rid)=='unknown'
                assert sql(f"SELECT COUNT(*) FROM fixture_followup WHERE intent_id={rid}")=='1'
                assert php(mode,'followup',id=rids[1])=='acknowledged'
                assert finance()==before and len(writes())==n
                cases.append(mode+'_followup_post_send_sql_rollback_never_auto_replays')
                cases.append(mode+'_automatic_renewal_full_discount_signed_debt_no_wallet_write')
                # A funded edit owns the original subject even BEFORE a
                # Native mutation journal exists. Shared plan/transfer and
                # deletion must consult that same durable ownership.
                buy=issue(mode,'lifecycle');fund(mode,buy);step(mode,buy,'applied');step(mode,buy,'completed')
                order=int(sql(f"SELECT order_id FROM service_effects WHERE source_kind='service_operation' AND source_ref='{buy}'"))
                pending=edit(mode,'lifecycle-edit',order,'ADD_VOLUME',volume=1);fund(mode,pending)
                assert not mutation(pending)
                f=finance();n=len(writes());deletes=lambda:sum(v[0]=='DELETE' for v in requests)
                d=deletes()
                assert php(mode,'delete',order=order)=='review'
                assert php(mode,'local_delete',order=order) is False
                assert php(mode,'plan',order=order,plan=8)['reason']=='physical_pending_financial_effect'
                assert php(mode,'reassign',order=order,server=3) is None
                assert sql(f'SELECT stage FROM service_operations WHERE id={pending}')=='funded'
                assert finance()==f and len(writes())==n and deletes()==d
                step(mode,pending,'progressed');step(mode,pending,'applied');step(mode,pending,'completed')
                cases.append(mode+'_funded_before_dispatch_blocks_lifecycle_changes')

                # A successful plan change retires only unpaid original
                # quotes. Returning to the old plan cannot resurrect one.
                unpaid=edit(mode,'unpaid-plan',order,'ADD_TIME',days=1)
                other=edit(mode,'unpaid-unrelated',ao,'ADD_TIME',days=1)
                ref=mode+'-late-lifecycle'
                sql(f"""INSERT INTO pays(hash_id,user_id,type,price,request_date,state,payid)
                    VALUES ('{ref}',1001,'INCREASE_WALLET',100,UNIX_TIMESTAMP(),'pending','inv-{ref}');
                    INSERT INTO payment_attempts(pay_row_id,provider,order_hash,provider_reference,quote_amount,quote_currency,local_amount_toman,checkout_url,wallet_invoice_sha256,service_operation_id)
                    SELECT id,'nextpay',hash_id,'inv-{ref}',100,'toman',100,'https://fixture.invalid/checkout','',{unpaid} FROM pays WHERE hash_id='{ref}';""")
                pid=int(sql(f"SELECT id FROM pays WHERE hash_id='{ref}'"))
                php(mode,'pin',pay=pid)
                f=finance()
                r=php(mode,'plan',order=order,plan=8);assert r['success'],r
                assert sql(f'SELECT stage FROM service_operations WHERE id={unpaid}')=='cancelled'
                assert sql(f'SELECT stage FROM service_operations WHERE id={other}')=='awaiting_funds'
                r=php(mode,'plan',order=order,plan=7);assert r['success'],r
                assert finance()==f
                r=php(mode,'settle',pay=pid,reference='inv-'+ref);assert r['funding']=='request_invalidated' and not r['credit']['replayed'],r
                f=finance()
                r=php(mode,'settle',pay=pid,reference='inv-'+ref);assert r['funding']=='request_invalidated' and r['credit']['replayed'],r
                assert finance()==f
                assert sql(f"SELECT COUNT(*) FROM wallet_entries WHERE source_kind='service_debit' AND source_ref='{unpaid}'")=='0'
                cases.append(mode+'_verified_late_receipt_credits_cancelled_quote_once')

                # Real relocation, not a fabricated order row: capacity
                # remains tied to the original BUY reservation. Inject SQL
                # failure after Native DELETE and recover local finality.
                r=php(mode,'reassign',order=order,server=3);assert r is not None,r
                assert sql(f'SELECT CONCAT(server_id,":",fileid) FROM orders_list WHERE id={order}')=='3:0'
                counts=tuple(int(sql(f'SELECT ucount FROM server_info WHERE id={sid}')) for sid in (2,3))
                f=finance();d=deletes()
                sql("CREATE TRIGGER fixture_capacity_fail BEFORE UPDATE ON server_info FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture rollback'")
                try:
                    assert php(mode,'delete',order=order)=='local'
                    assert finance()==f and deletes()==d+1
                    assert sql(f'SELECT status FROM orders_list WHERE id={order}')=='0'
                finally:sql('DROP TRIGGER fixture_capacity_fail')
                # Recovery is tied to the actual claimed local subject, not
                # merely a matching physical Native account.
                eid=int(sql(f'SELECT provider_effect_id FROM orders_list WHERE id={order}'))
                reqs=len(requests)
                sql(f"UPDATE service_effects SET provider_mutation_snapshot=JSON_SET(provider_mutation_snapshot,'$.delete_subject.userid',9002) WHERE id={eid}")
                assert php(mode,'delete',order=order)=='review'
                assert finance()==f and len(requests)==reqs
                sql(f"UPDATE service_effects SET provider_mutation_snapshot=JSON_SET(provider_mutation_snapshot,'$.delete_subject.userid',1001) WHERE id={eid}")
                cases.append(mode+'_delete_recovery_subject_mismatch_no_io')
                assert php(mode,'delete',order=order)=='complete'
                assert deletes()==d+1 and finance()[:2]==f[:2]
                assert tuple(int(sql(f'SELECT ucount FROM server_info WHERE id={sid}')) for sid in (2,3))==(counts[0]+1,counts[1])
                f=finance()
                assert php(mode,'delete',order=order)=='missing' and finance()==f and deletes()==d+1
                cases.append(mode+'_relocated_delete_restores_original_slot_once_after_sql_failure')

                buy=issue(mode,'lifecycle-alias');fund(mode,buy);step(mode,buy,'applied');step(mode,buy,'completed')
                order=int(sql(f"SELECT order_id FROM service_effects WHERE source_kind='service_operation' AND source_ref='{buy}'"))
                alias=php(mode,'alias',order=order)
                pending=edit(mode,'alias-funded',alias,'ADD_TIME',days=1,owner=9002)
                assert php(mode,'fund',id=pending,owner=9002)=='funded'
                f=finance();n=len(requests)
                assert php(mode,'local_delete',order=order) is False
                assert php(mode,'plan',order=order,plan=8)['reason']=='physical_pending_financial_effect'
                assert php(mode,'reassign',order=order,server=3) is None
                assert finance()==f and len(requests)==n
                step(mode,pending,'progressed');step(mode,pending,'applied');step(mode,pending,'completed')
                own=edit(mode,'alias-original-unpaid',order,'ADD_TIME',days=1)
                peer=edit(mode,'alias-other-unpaid',alias,'ADD_TIME',days=1,owner=9002)
                f=finance();d=deletes()
                assert php(mode,'local_delete',order=order) is True
                assert sql(f'SELECT stage FROM service_operations WHERE id={own}')=='cancelled'
                assert sql(f'SELECT stage FROM service_operations WHERE id={peer}')=='awaiting_funds'
                assert finance()==f and deletes()==d
                assert php(mode,'delete',order=alias)=='complete'
                assert sql(f'SELECT stage FROM service_operations WHERE id={peer}')=='cancelled'
                assert finance()==f and deletes()==d+1
                cases.append(mode+'_funded_alias_protects_shared_account_no_unreserved_slot')

                # A real plan attempt commits its journal before Native
                # readback. Its unresolved journal is not a new sale target.
                buy=issue(mode,'pending-plan');fund(mode,buy);step(mode,buy,'applied');step(mode,buy,'completed')
                order=int(sql(f"SELECT order_id FROM service_effects WHERE source_kind='service_operation' AND source_ref='{buy}'"))
                unpaid=edit(mode,'pending-plan-edit',order,'ADD_TIME',days=1)
                faults.append('get_unavailable')
                r=php(mode,'plan',order=order,plan=8);assert not r['success'],r
                assert sql(f"SELECT COUNT(*) FROM service_plan_changes WHERE source_order_id={order} AND state<>'committed'")=='1'
                f=finance()
                assert php(mode,'fund',id=unpaid)=='request_invalidated' and finance()==f
                cases.append(mode+'_unresolved_plan_intent_blocks_new_debit')
                # Actual externally funded BUY: one frozen reward for all
                # ordinals, no reward on another late receipt or wallet BUY.
                sql("UPDATE users SET refered_by=9002 WHERE userid=1001; INSERT INTO setting(type,value) VALUES ('INVITE_BANNER_AMOUNT','75')")
                op=issue(mode,'referral-bulk',3)
                first=invoice(mode,'referral-first',op);late=invoice(mode,'referral-late',op)
                assert settle(mode,first)['funding']=='funded'
                assert settle(mode,first)['credit']['replayed']
                assert settle(mode,late)['funding']=='already_funded'
                assert not award(op)
                for stage in ('progressed','progressed','applied','progressed','progressed'):
                    step(mode,op,stage);assert not award(op)
                php(mode,'step',id=op,lost_ack=True)
                assert sql(f"SELECT stage FROM service_operations WHERE id={op}")=='completed'
                assert award(op)==f'{first[0]}:75:NOT_ATTEMPTED'
                assert sql(f"SELECT COUNT(*) FROM payment_followup_intents WHERE pay_row_id={first[0]} AND stage='wallet_credit'")=='0'
                assert sql(f"SELECT COUNT(*) FROM payment_followup_intents WHERE pay_row_id={late[0]} AND stage='wallet_credit'")=='3'
                report_id=int(sql(f"SELECT id FROM payment_followup_intents WHERE service_operation_id={op} AND stage='service_completed' AND role='service_admin'"))
                assert php(mode,'followup',id=report_id)=='acknowledged'
                wire=sql(f"SELECT text FROM fixture_followup WHERE intent_id={report_id}")
                assert 'nextpay' in wire.lower() and all(row.split('\t')[2] in wire for row in rows(op)),wire
                credit_id=int(sql(f"SELECT id FROM payment_followup_intents WHERE pay_row_id={late[0]} AND stage='wallet_credit' AND role='customer'"))
                assert php(mode,'followup',id=credit_id,response=dict(ok=False,error_code=429,description='Too Many Requests',parameters=dict(retry_after=35)))=='pending'
                assert php(mode,'followup',id=credit_id)=='not_due'
                sql(f"UPDATE payment_followup_intents SET next_attempt_at=NOW(6) WHERE id={credit_id}")
                assert php(mode,'followup',id=credit_id)=='acknowledged'
                assert sql(f"SELECT GROUP_CONCAT(result ORDER BY attempt_no) FROM payment_followup_attempts WHERE intent_id={credit_id}")=='rejected,acknowledged'
                cases.append(mode+'_external_funding_vs_retained_late_receipt_report_and_retry')
                cases.append(mode+'_external_bulk_referral_reserved_once_in_completion')
                sql("UPDATE setting SET value='999' WHERE type='INVITE_BANNER_AMOUNT'; UPDATE users SET refered_by=0 WHERE userid=1001")
                before=finance();n=len(requests)
                sql("CREATE TRIGGER fixture_reward_report_fail BEFORE INSERT ON payment_followup_intents FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture rollback'")
                try:
                    assert php(mode,'referral',id=op)=='deferred'
                    assert finance()==before and award(op)==f'{first[0]}:75:NOT_ATTEMPTED'
                    assert sql(f"SELECT COUNT(*) FROM payment_followup_intents WHERE service_operation_id={op} AND stage='referral_reward'")=='0'
                finally:sql('DROP TRIGGER fixture_reward_report_fail')
                assert php(mode,'maintain_referrals',limit=10)==[]
                sql(f"UPDATE referral_awards SET next_attempt_at=NOW(6) WHERE service_operation_id={op}")
                balance=int(sql("SELECT wallet FROM users WHERE userid=9002"))
                with ThreadPoolExecutor(max_workers=3) as pool:
                    jobs=[pool.submit(php,mode,'referral',id=op,lost_ack=True),
                          pool.submit(php,mode,'maintain_referrals',limit=10),
                          pool.submit(php,mode,'referral',id=op)]
                    assert jobs[0].result() is None
                    assert jobs[2].result()=='confirmed'
                    assert jobs[1].result() in ([],{str(op):'confirmed'})
                assert award(op)==f'{first[0]}:75:CONFIRMED'
                assert int(sql("SELECT wallet FROM users WHERE userid=9002"))==balance+75
                assert sql(f"SELECT COUNT(*) FROM wallet_entries WHERE source_kind='purchase_referral' AND source_ref='{op}' AND delta_toman=75")=='1'
                assert len(requests)==n
                cases.append(mode+'_referral_credit_outbox_rollback_frozen_policy_concurrency_lost_ack')
                targets=dict(line.split('	') for line in sql(f"SELECT role,id FROM payment_followup_intents WHERE service_operation_id={op} AND stage='referral_reward'").splitlines())
                assert set(targets)=={'referral_inviter','referral_admin','referral_channel'},targets
                rid=int(targets['referral_admin'])
                with ThreadPoolExecutor(max_workers=3) as pool:
                    results=list(pool.map(lambda _:php(mode,'followup',id=rid),range(3)))
                assert 'acknowledged' in results and set(results)<={'acknowledged','busy'},results
                assert php(mode,'followup',id=rid)=='acknowledged'
                assert sql(f"SELECT COUNT(*) FROM fixture_followup WHERE intent_id={rid}")=='1'
                assert sql(f"SELECT COUNT(*) FROM fixture_followup WHERE intent_id={rid} AND text LIKE '%{first[0]}%'")=='1'
                rid=int(targets['referral_inviter'])
                assert php(mode,'followup',id=rid,unknown_ack=True)=='unknown'
                assert php(mode,'followup',id=rid)=='unknown'
                assert sql(f"SELECT COUNT(*) FROM fixture_followup WHERE intent_id={rid}")=='1'
                preview=php(mode,'preview_followup',id=rid,actor=9001)
                assert php(mode,'followup',id=rid,actor=9001,confirmation=preview,unknown_ack=True)=='unknown'
                preview=php(mode,'preview_followup',id=rid,actor=9001)
                assert php(mode,'followup',id=rid,actor=9001,confirmation=preview)=='stale'
                assert sql(f"SELECT COUNT(*) FROM fixture_followup WHERE intent_id={rid}")=='2'
                cases.append(mode+'_followup_unknown_manual_attempt_not_repeated')
                sql("UPDATE payment_followup_intents SET updated_at=NOW() WHERE state='pending' AND next_attempt_at IS NULL")
                rid=int(targets['referral_channel'])
                assert php(mode,'claim_followup',id=rid)['status']=='claimed'
                sql(f"UPDATE payment_followup_intents SET updated_at=DATE_SUB(NOW(),INTERVAL 3 MINUTE) WHERE id={rid}")
                assert php(mode,'maintain_followups',limit=10)=={str(rid):'unknown'}
                assert php(mode,'followup',id=rid)=='unknown'
                assert sql(f"SELECT COUNT(*) FROM fixture_followup WHERE intent_id={rid}")=='0'
                assert sql(f"SELECT result FROM payment_followup_attempts WHERE intent_id={rid}")=='unknown'
                assert len(requests)==n
                cases.append(mode+'_referral_reports_committed_claim_single_send_unknown_never_replayed')
                sql("UPDATE users SET refered_by=9002 WHERE userid=1001")
                op=issue(mode,'referral-wallet')
                receipt=invoice(mode,'referral-wallet-late',op)
                fund(mode,op);assert settle(mode,receipt)['funding']=='already_funded'
                step(mode,op,'applied');step(mode,op,'completed')
                assert not award(op) and php(mode,'referral',id=op)=='not_applicable'
                cases.append(mode+'_wallet_buy_late_receipt_never_earns_reward')
                sql("UPDATE setting SET value='0' WHERE type='INVITE_BANNER_AMOUNT'")
                op=issue(mode,'referral-disabled');receipt=invoice(mode,'referral-disabled',op)
                assert settle(mode,receipt)['funding']=='funded'
                step(mode,op,'applied');step(mode,op,'completed')
                assert award(op)==f'{receipt[0]}:0:DISABLED'
                sql("UPDATE setting SET value='100' WHERE type='INVITE_BANNER_AMOUNT'")
                assert php(mode,'referral',id=op)=='disabled'
                cases.append(mode+'_disabled_reward_decision_is_not_backfilled')
                sql("INSERT INTO users(userid,name,username,refcode,wallet,date,isAdmin,first_start) VALUES (8888,'Reward identity','reward_identity','0',0,'1700000000',0,'sent'); UPDATE users SET refered_by=8888 WHERE userid=1001")
                op=issue(mode,'referral-identity');receipt=invoice(mode,'referral-identity',op)
                assert settle(mode,receipt)['funding']=='funded'
                step(mode,op,'applied');step(mode,op,'completed')
                assert award(op)==f'{receipt[0]}:100:NOT_ATTEMPTED'
                sql("DELETE FROM users WHERE userid=8888; INSERT INTO users(userid,name,username,refcode,wallet,date,isAdmin,first_start) VALUES (8888,'Replacement','replacement','0',0,'1700000000',0,'sent')")
                assert php(mode,'referral',id=op)=='needs_review'
                assert award(op)==f'{receipt[0]}:100:NEEDS_REVIEW' and sql("SELECT wallet FROM users WHERE userid=8888")=='0'
                assert sql(f"SELECT COUNT(*) FROM wallet_entries WHERE source_kind='purchase_referral' AND source_ref='{op}'")=='0'
                sql("DELETE FROM users WHERE userid=8888; UPDATE users SET refered_by=0 WHERE userid=1001; DELETE FROM setting WHERE type='INVITE_BANNER_AMOUNT'")
                cases.append(mode+'_referral_replaced_inviter_never_credited')
                # Hosted edit completion uses the same sealed original service
                # evidence. An unrelated later payment does not replace it.
                order=int(sql(f"SELECT order_id FROM service_effects WHERE source_kind='service_operation' AND source_ref='{op}'"))
                for kind,days,volume in [('RENEW',30,2),('ADD_TIME',3,0),('ADD_VOLUME',0,1)]:
                    eid=edit(mode,'hosted-'+kind.lower(),order,kind,days=days,volume=volume)
                    receipt=invoice(mode,'hosted-'+kind.lower(),eid)
                    assert settle(mode,receipt)['funding']=='funded'
                    assert sql(f"SELECT COUNT(*) FROM payment_followup_intents WHERE pay_row_id={receipt[0]}")=='0'
                    for stage in (['progressed','progressed','applied','completed'] if kind=='RENEW' else ['progressed','applied','completed']):
                        step(mode,eid,stage)
                    report_id=int(sql(f"SELECT id FROM payment_followup_intents WHERE service_operation_id={eid} AND stage='service_completed' AND role='service_admin'"))
                    before=finance();n=len(requests)
                    assert php(mode,'followup',id=report_id)=='acknowledged'
                    assert php(mode,'followup',id=report_id)=='acknowledged'
                    assert sql(f"SELECT COUNT(*) FROM fixture_followup WHERE intent_id={report_id}")=='1'
                    assert finance()==before and len(requests)==n
                    cases.append(mode+'_hosted_'+kind.lower()+'_original_report')
                mid=edit(mode,'manual-addon',order,'ADD_TIME',days=2)
                ref=mode+'-manual-addon'
                sql(f"INSERT INTO pays(hash_id,user_id,type,price,request_date,state) VALUES ('{ref}',1001,'INCREASE_DAY',100,UNIX_TIMESTAMP(),'pending')")
                pid=int(sql(f"SELECT id FROM pays WHERE hash_id='{ref}'"))
                assert php(mode,'manual_receipt',pay=pid,id=mid)['funding']=='funded'
                assert sql(f"SELECT COUNT(*) FROM payment_followup_intents WHERE pay_row_id={pid}")=='0'
                step(mode,mid,'progressed');step(mode,mid,'applied')
                # The service completion transaction cannot lose its report.
                before=finance();n=len(requests)
                sql("CREATE TRIGGER fixture_service_report_fail BEFORE INSERT ON payment_followup_intents FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture rollback'")
                try:
                    step(mode,mid,'pending')
                    assert mutation(mid).startswith('verified\t')
                    assert sql(f"SELECT COUNT(*) FROM payment_followup_intents WHERE service_operation_id={mid}")=='0'
                finally:sql('DROP TRIGGER fixture_service_report_fail')
                retry(mid);step(mode,mid,'completed')
                assert finance()==before and len(requests)==n
                row=sql(f"SELECT id,role,recipient,message_id FROM payment_followup_intents WHERE service_operation_id={mid} AND stage='service_completed'").split('\t')
                assert row[1:]==['receipt_primary','9001','222'],row
                rid=int(row[0])
                # Changing a stored receipt target cannot re-route the sealed edit.
                sql(f"UPDATE pays SET message_id=223 WHERE id={pid}")
                assert php(mode,'followup',id=rid)=='unavailable'
                assert sql(f"SELECT COUNT(*) FROM fixture_followup WHERE intent_id={rid}")=='0'
                sql(f"UPDATE pays SET message_id=222 WHERE id={pid}")
                assert php(mode,'followup',id=rid)=='acknowledged'
                assert sql(f"SELECT COUNT(*) FROM fixture_followup WHERE intent_id={rid} AND mode='edit:222'")=='1'
                assert finance()==before and len(requests)==n
                cases.append(mode+'_manual_service_completion_rollback_and_exact_receipt_edit')
                # Real multi-message BUY, not a fabricated packet. Long
                # backup aliases force the existing Telegram HTML splitter.
                aliases=','.join(f'https://backup{i}.fixture.invalid/'+('a'*1000) for i in range(6))
                sql(f"UPDATE server_config SET sub_links='{aliases}' WHERE id=2")
                multi=issue(mode,'multipart');fund(mode,multi);step(mode,multi,'applied')
                before=finance();n=posts()
                sql("CREATE TRIGGER fixture_part_insert_fail BEFORE INSERT ON payment_delivery_parts FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture part rollback'")
                try:
                    step(mode,multi,'pending','provider_storage_unavailable')
                    assert sql(f"SELECT COUNT(*) FROM payment_delivery_intents WHERE service_operation_id={multi}")=='0'
                    assert sql(f"SELECT COUNT(*) FROM service_effects WHERE source_kind='service_operation' AND source_ref='{multi}' AND order_id IS NOT NULL")=='0'
                finally:sql('DROP TRIGGER fixture_part_insert_fail')
                retry(multi);step(mode,multi,'completed')
                assert finance()==before and posts()==n
                did,count=map(int,sql(f"SELECT id,part_count FROM payment_delivery_intents WHERE service_operation_id={multi}").split('	'))
                assert count>=3,count
                sql("UPDATE server_config SET sub_links='' WHERE id=2")
                def part_states(d):
                    return sql(f"SELECT GROUP_CONCAT(state ORDER BY part_no) FROM payment_delivery_parts WHERE intent_id={d}").split(',')
                def wire_counts(d):
                    return dict((int(k),int(v)) for k,v in (r.split('	') for r in sql(f"SELECT part_no,COUNT(*) FROM fixture_telegram WHERE intent_id={d} GROUP BY part_no ORDER BY part_no").splitlines()))
                def due_part(d,p):
                    sql(f"UPDATE payment_delivery_parts SET next_attempt_at=NOW(6) WHERE intent_id={d} AND part_no={p}; UPDATE payment_delivery_intents SET next_attempt_at=NOW(6) WHERE id={d}")
                def buy_delivery(tag):
                    sid=issue(mode,tag);fund(mode,sid);step(mode,sid,'applied');step(mode,sid,'completed')
                    return int(sql(f"SELECT id FROM payment_delivery_intents WHERE service_operation_id={sid}"))
                before=finance();n=len(requests)
                rate=dict(ok=False,error_code=429,description='Too Many Requests',parameters=dict(retry_after=35))
                assert php(mode,'deliver',id=did,responses={2:rate})=='pending'
                assert part_states(did)==['acknowledged','pending']+['acknowledged']*(count-2)
                assert wire_counts(did)=={p:1 for p in range(1,count+1)}
                assert sql(f"SELECT CONCAT(result,':',telegram_error_code,':',retry_after_seconds) FROM payment_delivery_attempts WHERE intent_id={did} AND part_no=2")=='rejected:429:35'
                assert sql(f"SELECT COUNT(*) FROM fixture_telegram WHERE intent_id={did} AND keys_json IS NOT NULL AND keys_json<>'' AND part_no={count}")=='1'
                assert sql(f"SELECT COUNT(*) FROM fixture_telegram WHERE intent_id={did} AND keys_json IS NOT NULL AND keys_json<>'' AND part_no<>{count}")=='0'
                assert php(mode,'deliver',id=did)=='pending'
                assert wire_counts(did)=={p:1 for p in range(1,count+1)}
                # A false projection must not certify an unsent/rejected part.
                sql(f"UPDATE payment_delivery_parts SET state='acknowledged' WHERE intent_id={did} AND part_no=2")
                assert php(mode,'deliver',id=did)=='deferred'
                sql(f"UPDATE payment_delivery_parts SET state='pending' WHERE intent_id={did} AND part_no=2")
                sql(f"UPDATE payment_delivery_intents SET updated_at=NOW(),next_attempt_at=DATE_ADD(NOW(6),INTERVAL 1 DAY) WHERE service_operation_id IS NOT NULL AND state='pending' AND id<>{did}")
                due_part(did,2)
                assert php(mode,'maintain_delivery',limit=1)==dict(checked=1,acknowledged=1,unknown=0,deferred=0)
                assert part_states(did)==['acknowledged']*count
                assert wire_counts(did)=={p:(2 if p==2 else 1) for p in range(1,count+1)}
                assert finance()==before and len(requests)==n
                cases.append(mode+'_multipart_atomic_manifest_frozen_text_429_due_only')
                # Local account lease and original credential proof must
                # remain valid on a part-specific operator redrive.
                did=buy_delivery('part-reject')
                before=finance();n=len(requests)
                assert php(mode,'deliver',id=did,block_account=True)=='busy'
                assert wire_counts(did)=={}
                digest=sql(f"SELECT payload_sha256 FROM payment_delivery_parts WHERE intent_id={did} AND part_no=1")
                sql(f"UPDATE payment_delivery_parts SET payload_sha256=REPEAT('0',64) WHERE intent_id={did} AND part_no=1")
                assert php(mode,'deliver',id=did)=='deferred' and wire_counts(did)=={}
                sql(f"UPDATE payment_delivery_parts SET payload_sha256='{digest}' WHERE intent_id={did} AND part_no=1")
                assert php(mode,'deliver',id=did,responses={1:dict(ok=False,error_code=403,description='Forbidden')})=='rejected'
                assert part_states(did)==['rejected','acknowledged']
                assert php(mode,'deliver',id=did)=='rejected'
                token=php(mode,'preview_delivery',id=did,part=1,actor=9001)
                oid=int(sql(f"SELECT order_id FROM payment_delivery_intents WHERE id={did}"))
                uuid=sql(f"SELECT uuid FROM orders_list WHERE id={oid}")
                sql(f"UPDATE orders_list SET uuid='fixture-changed' WHERE id={oid}")
                assert php(mode,'deliver',id=did,part=1,actor=9001,confirmation=token)=='deferred'
                sql(f"UPDATE orders_list SET uuid='{uuid}' WHERE id={oid}")
                assert php(mode,'deliver',id=did,part=1,actor=9001,confirmation=token)=='acknowledged'
                assert wire_counts(did)=={1:2,2:1} and part_states(did)==['acknowledged']*2
                assert finance()==before and len(requests)==n
                cases.append(mode+'_part_rejection_redrive_exact_identity_and_account_lease')
                did=buy_delivery('qr-local-fail')
                before=finance();n=len(requests)
                assert php(mode,'deliver',id=did,qr_local_fail=True)=='pending'
                assert part_states(did)==['pending','acknowledged'] and wire_counts(did)=={2:1}
                assert sql(f"SELECT CONCAT(result,':',retry_after_seconds) FROM payment_delivery_attempts WHERE intent_id={did} AND part_no=1")=='local_failed:120'
                assert php(mode,'deliver',id=did)=='pending'
                assert wire_counts(did)=={2:1}
                due_part(did,1)
                assert php(mode,'deliver',id=did)=='acknowledged'
                assert wire_counts(did)=={1:1,2:1} and finance()==before and len(requests)==n
                cases.append(mode+'_qr_local_failure_never_claims_remote_rejection')
                did=buy_delivery('part-crash')
                before=finance();n=len(requests)
                php(mode,'deliver',id=did,crash_after_part=2)
                assert part_states(did)==['acknowledged','dispatching'] and wire_counts(did)=={1:1,2:1}
                assert php(mode,'deliver',id=did)=='unknown'
                assert php(mode,'deliver',id=did)=='unknown'
                assert part_states(did)==['acknowledged','unknown'] and wire_counts(did)=={1:1,2:1}
                assert finance()==before and len(requests)==n
                cases.append(mode+'_process_exit_after_wire_never_replays_confirmed_part')
                did=buy_delivery('part-ack-rollback')
                before=finance();n=len(requests)
                sql("""DELIMITER //
CREATE TRIGGER fixture_part_ack_fail BEFORE UPDATE ON payment_delivery_attempts FOR EACH ROW
                    BEGIN IF NEW.result='acknowledged' AND OLD.part_no=2 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture ack rollback'; END IF; END//
DELIMITER ;
""")
                try:
                    assert php(mode,'deliver',id=did)=='unknown'
                    assert part_states(did)==['acknowledged','dispatching'] and wire_counts(did)=={1:1,2:1}
                finally:sql('DROP TRIGGER fixture_part_ack_fail')
                assert php(mode,'deliver',id=did)=='unknown'
                assert part_states(did)==['acknowledged','unknown'] and wire_counts(did)=={1:1,2:1}
                assert finance()==before and len(requests)==n
                cases.append(mode+'_part_ack_sql_rollback_never_auto_replays')
                assert not faults and not errors,(faults,errors)
        finally:
            release.set();server.shutdown();server.server_close();thread.join(5)
    result=dict(complete=True,cases=len(cases),checks=cases,requests=len(requests),posts=posts())
    if args.report:args.report.write_text(json.dumps(result,indent=2)+'\n')
    print(json.dumps(result))

if __name__=='__main__':main()
