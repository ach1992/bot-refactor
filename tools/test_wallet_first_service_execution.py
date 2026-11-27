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
import hashlib
from pathlib import Path
import subprocess
import threading
import time
import re
from urllib.parse import unquote

import database_fixture as owned
import import_occupancy_fixture as imports
from test_wallet_first_cutover import LEGACY_RESERVE_PHP

ROOT=Path(__file__).resolve().parents[1]
DB=owned.NAMES[1]
PHP=r'''<?php
declare(strict_types=1);
mysqli_report($argv[4]==='strict'?MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT:MYSQLI_REPORT_OFF);
define('ACHBOT_PG_TEST_LOOPBACK',true);
require $argv[1].'/src/Services/Execution.php';
require_once $argv[1].'/src/Services/Resolution.php';
require $argv[1].'/src/Services/AutomaticRenewal.php';
require_once $argv[1].'/src/Finance/ServiceDelivery.php';
require_once $argv[1].'/src/Purchase/OrderWrites.php';
require_once $argv[1].'/src/Finance/FundingCheckout.php';
$data='';
require_once $argv[1].'/src/Subscription/AccountDeletion.php';
$GLOBALS['telegramWebhookSecret']='SyntheticServiceDeliveryKey_2026';
function setUser(...$args) {if (($GLOBALS['x']['op']??null)==='telegram_wallet') throw new RuntimeException('unexpected_wallet_wizard_reset');}
function RandomString(...$args) {return bin2hex(random_bytes(10));}
function getServerListKeys() {return '{}';}
function alert(...$args) {$GLOBALS['fixtureAlerts'][]=$args;}
function answerCallbackQuery(...$args) {$GLOBALS['fixtureAlerts'][]=$args;}
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
        (int)$a['actor_userid']!==(int)($x['op']==='telegram_review' && str_starts_with($x['callback'],'attentionService')?0:($x['actor']??0)) || (int)$d['user_id']!==$uid)
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
        VALUES (?,?,?,?,?,?,?,?)','iiisssss',[$d['id'],$uid,$ctx['delivery_part'],$kind,$qr,$text,$keys,$mode]);$messageId=(int)$s->insert_id;$s->close();
    if (($x['crash_after_part']??null)===$ctx['delivery_part']) exit(29);
    if (array_key_exists($ctx['delivery_part'],$x['responses']??[]))
        return json_decode(json_encode($x['responses'][$ctx['delivery_part']],JSON_THROW_ON_ERROR),false,64,JSON_THROW_ON_ERROR);
    return ($x['unknown_ack']??false)?(object)['ok'=>false]:fixtureMessageAck($uid,$messageId);
}
function sendPhoto($photo,$caption=null,$keys=null,$mode=null,$uid=null) {
    if (isset($GLOBALS['achbotTelegramDiagnosticContext']['followup_id'])) {
        $ack=sendMessage($caption,$keys,$mode,$uid);
        if (($ack->ok??false)===true) $ack->result->message_id=222;
        return $ack;
    }
    return fixtureDeliverySend('qr',$uid,$caption,$keys,$mode,$photo);
}

function sendMessage($text,$keys=null,$mode=null,$uid=null) {
    global $db,$x;
    if ((int)achbotProviderRow($db,'SELECT @@in_transaction AS tx')['tx']!==0)
        throw new RuntimeException('fixture_telegram_inside_transaction');
    $fid=$GLOBALS['achbotTelegramDiagnosticContext']['followup_id']??null;
    if ($fid!==null) {
        $d=achbotProviderRow($db,'SELECT id,state,stage,attempt_count,dispatch_token FROM payment_followup_intents WHERE id=?','i',[$fid]);
        $a=achbotProviderRow($db,'SELECT result,attempt_no,actor_userid,wire_action,wire_recipient,wire_message_id FROM payment_followup_attempts
            WHERE intent_id=? AND dispatch_token=?','is',[$fid,$d['dispatch_token']]);
        if ($d['state']!=='dispatching' || !$a || $a['result']!=='dispatching' ||
            (int)$a['attempt_no']!==(int)$d['attempt_count'] ||
            (int)$a['actor_userid']!==(int)($d['stage']==='receipt_admitted' || ($x['op']==='telegram_review' && str_starts_with($x['callback'],'attentionService'))?0:($x['actor']??0)))
            throw new RuntimeException('fixture_followup_claim_not_committed');
        $wireAction=str_starts_with((string)$mode,'edit_text:')?'edit_text':
            (str_starts_with((string)$mode,'edit:')?'edit_keys':'send');
        $wireMessage=$wireAction==='send'?null:(int)substr((string)$mode,strpos($mode,':')+1);
        if ($a['wire_action']!==$wireAction || $a['wire_recipient']!==(string)$uid ||
            ($a['wire_message_id']===null?null:(int)$a['wire_message_id'])!==$wireMessage)
            throw new RuntimeException('fixture_followup_wire_not_committed');
        $s=achbotProviderStatement($db,'INSERT INTO fixture_followup(intent_id,recipient,text,mode,keys_json) VALUES (?,?,?,?,?)',
            'issss',[$d['id'],(string)$uid,$text,$mode,$keys]);$mid=(int)$s->insert_id;$s->close();
        if (isset($x['response'])) return json_decode(json_encode($x['response'],JSON_THROW_ON_ERROR),false,64,JSON_THROW_ON_ERROR);
        return ($x['unknown_ack']??false)?(object)['ok'=>false]:fixtureMessageAck($uid,
            $wireMessage??$mid);
    }
    if ($x['op']==='telegram_addon_quote') {
        $GLOBALS['fixtureQuoteMessages'][]=['text'=>$text,'keys'=>json_decode($keys??'null',true)];
        $actor=(int)$GLOBALS['from_id'];
        $latest=achbotProviderRow($db,'SELECT MAX(id) AS id FROM pays WHERE user_id=?','i',[$actor]);
        return (object)['ok'=>true,'result'=>(object)['message_id'=>200000+(int)$latest['id'],'chat'=>(object)['id'=>$actor]]];
    }
    if ($x['op']==='referral_config') return fixtureMessageAck((int)$uid,12345);
    if (in_array($x['op'],['checkout_discount','rotate_key'],true)) return (object)['ok'=>true];
    return fixtureDeliverySend('text',$uid,$text,$keys,$mode);
}
function bot($method,$data=[]) {
    global $db,$x;
    if ($x['op']==='telegram_wallet' && in_array($method,['editMessageText','editMessageReplyMarkup'],true)) {
        $ref=achbotProviderRow($db,'SELECT * FROM payment_message_refs WHERE chat_id=? AND message_id=?','ii',[(int)$data['chat_id'],(int)$data['message_id']]);
        if (!$ref || $ref['purpose']!=='status' || $ref['retired_reason']!==null ||
            (int)$data['chat_id']!==(int)$GLOBALS['from_id'] ||
            (int)achbotProviderRow($db,'SELECT @@in_transaction AS tx')['tx']!==0)
            throw new RuntimeException('fixture_wallet_projection_target');
        $GLOBALS['fixtureWalletProjection'][]=$data;
        return fixtureMessageAck((int)$data['chat_id'],(int)$data['message_id']);
    }
    if (!in_array($x['op'],['plan','plan_recover','plan_correct','reassign'],true) || $method!=='editMessageReplyMarkup' ||
        !in_array(array_keys($data),[['chat_id','message_id'],['chat_id','message_id','reply_markup']],true) ||
        (int)achbotProviderRow($db,'SELECT @@in_transaction AS tx')['tx']!==0)
        throw new RuntimeException('fixture_unexpected_bot_markup');
    $s=achbotProviderStatement($db,'INSERT INTO fixture_markup(chat_id,message_id,markup) VALUES (?,?,?)',
        'iis',[(int)$data['chat_id'],(int)$data['message_id'],$data['reply_markup']??null]);$s->close();
    return fixtureMessageAck((int)$data['chat_id'],(int)$data['message_id']);
}
function achbotNotifyAdmins(...$args) {return true;}
function delMessage(...$args) {}
function editKeys($keys,$msgId,$ci) {return sendMessage($keys,null,'edit:'.$msgId,$ci);}
function editText($msgId,$text,$keys=null,$mode=null,$uid=null) {
    if (isset($GLOBALS['achbotTelegramDiagnosticContext']['followup_id']))
        return sendMessage($text,$keys,'edit_text:'.$msgId,$uid);
    if (($GLOBALS['x']['op']??'')!=='telegram_review') throw new RuntimeException('fixture_unexpected_edit');
    $GLOBALS['fixtureReview'][]=['message'=>$msgId,'body'=>$text,'keys'=>json_decode($keys??'null',true),'mode'=>$mode,'user'=>$uid];
    return (object)['ok'=>true];
}

function fixtureAlias(mysqli $db,int $order): int {
    $ctx=achbotPgOrderContext($db,$order,true);$o=$ctx['order'];$a=$ctx['account'];
    $id=insertOrder($db,9002,'',0,2,$o['remark'],'alias-'.$o['uuid'],
        (int)$o['expire_date'],$o['link'],0,time(),0,true);
    achbotRecordImportedOrderOriginLocked($db,$id,9002,9002,'pasarguard',2,$o['remark'],
        str_repeat('a',64),['remote_user_id'=>$a['remote_user_id'],'remote_created_at'=>$a['remote_created_at'],
            'remote_username'=>$a['remote_username']],$a['id']);
    achbotProviderBindOrder($db,$a,$id);
    return $id;
}
$db=new mysqli('localhost','root','',$argv[3],0,$argv[2]);
if (!$db->set_charset('utf8mb4')) throw new RuntimeException('fixture_charset');
$connection=$db;$GLOBALS['admin']=9001;
$x=json_decode($argv[5],true,512,JSON_THROW_ON_ERROR);
if ($x['short_lock_timeout']??false) $db->query('SET SESSION innodb_lock_wait_timeout=2');
if ($x['read_committed']??false) $db->query('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
if ($x['qr_local_fail']??false) {
    class QRcode {public static function png(...$args) {throw new RuntimeException('fixture_qr_prepare');}}
}

$GLOBALS['botState']=array_replace(['rewardChannel'=>'@fixture_report'],$x['menu_state']??[]);
if (isset($x['menu_state'])) {
    // Deliberately unrelated webhook user; the notice must not use it.
    $GLOBALS['from_id']=1001;$GLOBALS['userInfo']=['isAdmin'=>0,'is_agent'=>0,'freetrial'=>'used'];
}
$GLOBALS['paymentKeys']=['bankAccount'=>'fixture-bank','holderName'=>'fixture-holder'];
''' + LEGACY_RESERVE_PHP + imports.PHP_IMPORT + r'''
try {
    $presentation=['bot_state'=>[],'bot_url'=>'https://bot.fixture.invalid','bot_username'=>'fixture_bot'];
    if ($x['op']==='import_occupancy') $r=fixtureImportOccupancy($db,$x);
    elseif ($x['op']==='step') $r=achbotServiceExecuteStep($db,$x['id'],$presentation,(bool)($x['force']??false));
    elseif ($x['op']==='resolution_preview') {
        $actor=(int)($x['actor']??9001);
        $r=achbotServiceResolutionPreview($db,$x['id'],$actor,9001);
        if ($r) $r['token']=achbotServiceResolutionToken($r,$actor,$x['decision']);
    }
    elseif ($x['op']==='resolve') $r=achbotServiceResolve($db,$x['id'],(int)($x['actor']??9001),9001,$x['decision'],$x['token']);
    elseif ($x['op']==='service_attention') $r=achbotServiceAttentionCaseOpen($db,$x['id']);
    elseif ($x['op']==='auto') $r=achbotServiceAutoRenewAdmit($db,$x['order'],(int)($x['owner']??1001),$x['bot_state']??['renewAutoState'=>'on']);
    elseif ($x['op']==='gateway_history') {
        require_once $argv[1].'/src/Finance/PaymentLifecycle.php';
        $r=achbotCustomerHistoryCount($db,(int)$x['owner'],(string)$x['metric'],0,'older',false,time());
    }
    elseif ($x['op']==='sales_snapshot') {
        require_once $argv[1].'/src/Finance/ServiceHistory.php';
        $r=array_map('intval',achbotProviderRow($db,"SELECT COUNT(*) AS n,
            COALESCE(SUM(amount),0) AS total,COALESCE(SUM(wallet_origin),0) AS wallet_count,
            COALESCE(SUM(CASE WHEN wallet_origin THEN amount ELSE 0 END),0) AS wallet_total
            FROM (".achbotServiceSalesSql().") s"));
    }
    elseif ($x['op']==='due') {
        $other=null;
        if (isset($x['block_service'])) {
            $other=new mysqli('localhost','root','',$argv[3],0,$argv[2]);
            if (!$other->set_charset('utf8mb4')) throw new RuntimeException('fixture_charset');
            $lock=achbotServiceExecutionLock($other,$x['block_service']);
            if ((int)achbotProviderRow($other,'SELECT GET_LOCK(?,0) AS acquired','s',[$lock])['acquired']!==1)
                throw new RuntimeException('fixture_block_due_service');
        }
        try {$r=achbotServiceExecuteDue($db,$x['limit'],10.0,$presentation);}
        finally {if ($other!==null) $other->close();}
    }
    elseif ($x['op']==='deliver') {
        $other=null;
        if ($x['block_account']??false) {
            $other=new mysqli('localhost','root','',$argv[3],0,$argv[2]);
            if (!$other->set_charset('utf8mb4')) throw new RuntimeException('fixture_charset');
            $d=achbotProviderRow($db,'SELECT order_id FROM payment_delivery_intents WHERE id=?','i',[$x['id']]);
            $name=achbotPhysicalSessionLockName($other,achbotPgServiceAccountLock($other,(int)$d['order_id']));
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
    elseif (in_array($x['op'],['cutover_snapshot','cutover_open','cutover_adopt','legacy_reserve'],true)) {
        require_once $argv[1].'/tools/wallet_first_cutover.php';
        $r=match($x['op']) {
            'cutover_snapshot'=>walletFirstCutoverSnapshot($db),
            'cutover_open'=>walletFirstCutoverOpen($db,$x['expected']),
            'legacy_reserve'=>fixtureLegacyReserve($db,$x['pay'])
        };
    }
    elseif (in_array($x['op'],['telegram_wallet','telegram_approval'],true)) {
        $from_id=(int)($x['sender']??($x['op']==='telegram_approval'?9001:1001));$chat_id=$from_id;$callbackId='fixture-callback';
        $userInfo=achbotProviderRow($db,'SELECT * FROM users WHERE userid=?','i',[$from_id]);
        $text='';$botUrl='https://bot.fixture.invalid/';$botUsername='fixture_bot';$botToken='999999:fixture-only';
        $botState=$GLOBALS['botState']+['walletState'=>'on','remark'=>'digits'];
        $data=$x['callback'];$GLOBALS['fixtureAlerts']=[];$GLOBALS['fixtureWalletProjection']=[];
        // Each synthetic draft has its own bot-authored selector, exactly as
        // Telegram sends it. Replay retains that message identity.
        preg_match('/\A(?:pw|pa)_([A-Za-z0-9_-]{1,50})\z/D',$data,$match);
        $p=achbotProviderRow($db,'SELECT id FROM pays WHERE hash_id=?','s',[$match[1]??'']);
        $message_id=100000+(int)($p['id']??0);
        $update=(object)['update_id'=>123456,'callback_query'=>(object)['id'=>$callbackId,'from'=>(object)['id'=>$from_id],
            'data'=>$data,'message'=>(object)['message_id'=>$message_id,'chat'=>(object)['id'=>$chat_id,'type'=>($x['group']??false)?'group':'private'],
                'from'=>(object)['id'=>999999,'is_bot'=>true],
                'reply_markup'=>(object)['inline_keyboard'=>[[(object)['callback_data'=>$data]]]]]]];
        if ($x['missing_key']??false) $GLOBALS['telegramWebhookSecret']=null;
        require $argv[1].'/src/Finance/Routes/PaymentActions.php';
        $r=['alerts'=>$GLOBALS['fixtureAlerts'],'projection'=>$GLOBALS['fixtureWalletProjection']];
    }
    elseif ($x['op']==='commercial_plan') {
        require_once $argv[1].'/src/Subscription/AccountDetails.php';
        $o=achbotProviderRow($db,'SELECT * FROM orders_list WHERE id=?','i',[(int)$x['order']]);
        $a=\AchBot\Subscription\AccountDetails\orderPlanActions($db,$o);
        $r=['origin'=>achbotOrderMayUsePaidPlan($db,$o),'choose'=>$a['choose'],'paid'=>isset($a['paid'])&&$a['paid']!==null];
    }
    elseif ($x['op']==='telegram_addon_quote') {
        $from_id=(int)$x['actor'];$chat_id=$from_id;$callbackId='fixture-addon';$message_id=721;
        $userInfo=achbotProviderRow($db,'SELECT * FROM users WHERE userid=?','i',[$from_id]);
        $text='';$botUrl='https://bot.fixture.invalid/';$botUsername='fixture_bot';
        $botState=json_decode(achbotProviderRow($db,"SELECT value FROM setting WHERE type='BOT_STATES'")['value'],true)
            +['nowPaymentOther'=>'off','zarinpal'=>'off','nextpay'=>'off','walletState'=>'on'];
        $nowpaymentsIpnSecret=$x['ipn_secret']??'fixture-ipn-secret';
        if (array_key_exists('settlement_policy',$x)) $nowpaymentsSettlementPolicy=$x['settlement_policy'];
        if (isset($x['stale_keys'])) $paymentKeys=array_replace($paymentKeys,$x['stale_keys']);
        $data=$x['callback'];$GLOBALS['fixtureAlerts']=[];$GLOBALS['fixtureQuoteMessages']=[];
        $update=(object)['update_id'=>123456,'callback_query'=>(object)['id'=>$callbackId,'from'=>(object)['id'=>$from_id],
            'data'=>$data,'message'=>(object)['message_id'=>$message_id,'chat'=>(object)['id'=>$chat_id,'type'=>'private']]]];
        require $argv[1].'/src/Finance/Routes/'.(str_starts_with($data,'renewAccount')?'RenewalCheckout':'Extensions').'.php';
        $r=['alerts'=>$GLOBALS['fixtureAlerts'],'messages'=>$GLOBALS['fixtureQuoteMessages']];
    }
    elseif ($x['op']==='original_receipt_quote') {
        require_once $argv[1].'/src/Finance/ManualReceipt.php';
        $pay=achbotProviderRow($db,'SELECT * FROM pays WHERE id=?','i',[$x['pay']]);
        $r=achbotManualOriginalServiceQuote($db,$pay,(int)$pay['user_id'],$x['mode']);
    }
    elseif ($x['op']==='telegram_review') {
        $from_id=(int)$x['actor'];$chat_id=(int)($x['chat']??$from_id);
        $callbackId='fixture-review';$message_id=889;$data=$x['callback'];
        $GLOBALS['fixtureAlerts']=[];$GLOBALS['fixtureReview']=[];
        require $argv[1].'/src/Finance/Routes/AutomaticGatewayReview.php';
        $r=['alerts'=>$GLOBALS['fixtureAlerts'],'review'=>$GLOBALS['fixtureReview']];
    }
    elseif ($x['op']==='retired_callback') {
        require_once $argv[1].'/src/Finance/CheckoutAdmission.php';
        $p=achbotProviderRow($db,'SELECT * FROM pays WHERE id=?','i',[$x['pay']]);
        $r=['modes'=>array_map(static fn($mode)=>achbotCheckoutModeMatchesRequest($p,$mode),['standard','custom','renew','day','volume','sconfig']),
            'provider'=>achbotSupportsProviderType('marzban'),'subject'=>achbotProviderPaymentSubjectServer($db,$p)];
    }
    elseif ($x['op']==='checkout_insert') {
        require_once $argv[1].'/src/Finance/CheckoutAdmission.php';
        $h=$x['hash'];$uid=(int)($x['actor']??1001);$type=$x['type'];$price=$x['price'];$sid=$type==='INCREASE_WALLET'?0:2;$plan=$type==='INCREASE_WALLET'?0:7;
        $order=(int)($x['order']??0);$days=(int)($x['days']??0);$volume=(int)($x['volume']??0);
        $count=(int)($x['quantity']??1);$description=(string)($x['description']??'fixture');$agent=(int)($x['agent_bought']??0);
        $snapshot=null;$scoped=false;
        if (isset($x['addon_kind'])) {
            $o=achbotProviderRow($db,'SELECT * FROM orders_list WHERE id=?','i',[$order]);
            $context=achbotAddonContext($db,$o);
            $offer=achbotAddonOfferById($db,$x['addon_kind'],$context,(int)$x['offer']);
            if (!$offer) throw new RuntimeException('fixture_offer_missing');
            $snapshot=achbotAddonQuote($o,$context,$x['addon_kind'],$offer,$price);
            $scoped=true;
        }
        $stmt=$db->prepare("INSERT INTO pays(hash_id,user_id,type,price,request_date,state,server_id,plan_id,order_id,day,volume,agent_count,description,addon_scope_snapshot,agent_bought)
            VALUES (?,?,?,?,UNIX_TIMESTAMP(),'pending',?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param('sisiiiiiiissi',$h,$uid,$type,$price,$sid,$plan,$order,$days,$volume,$count,$description,$snapshot,$agent);
        $display=$plan?achbotProviderRow($db,'SELECT days,volume FROM server_plans WHERE id=?','i',[$plan]):[];
        $pricingUser=achbotProviderRow($db,'SELECT is_agent,discount_percent FROM users WHERE userid=?','i',[$uid]);
        $pricingStates=json_decode(achbotProviderRow($db,"SELECT value FROM setting WHERE type='BOT_STATES'")['value'],true);
        $pricing=$x['pricing_policy']??achbotAgencyQuotePolicy($pricingUser,$pricingStates,$plan,$sid);
        $r=achbotPayUxInsertDraft($db,$uid,$stmt,$pricing,$scoped,$display,['remark'=>$x['naming_mode']??'digits']);
        $stmt->close();
    }
    elseif ($x['op']==='attention_receipt') {
        require_once $argv[1].'/src/Finance/GatewayClient.php';
        $r=achbotGatewayAttentionCaseOpen($db,(int)$x['pay']);
    }
    elseif ($x['op']==='receipt_outcome') {
        require_once $argv[1].'/src/Finance/CheckoutRoutes.php';
        $r=achbotCheckoutReceiptOutcome($db,(int)$x['pay']);
    }
    elseif ($x['op']==='checkout_pin') {
        require_once $argv[1].'/src/Finance/CheckoutAdmission.php';
        $db->begin_transaction();
        achbotProviderPinPayment($db,$x['pay']);
        $p=achbotProviderRow($db,'SELECT * FROM pays WHERE id=? FOR UPDATE','i',[$x['pay']]);
        $plan=achbotProviderRow($db,'SELECT days,volume FROM server_plans WHERE id=?','i',[(int)$p['plan_id']]);
        achbotCheckoutSealDraftLocked($db,$x['pay'],$p,$plan,['remark'=>'digits']);
        $db->commit();$r=true;
    }
    elseif ($x['op']==='checkout_discount') {
        require_once $argv[1].'/src/Finance/Discounts.php';
        $r=achbotDiscountRedeem($db,(int)$x['pay'],$x['code'],(int)($x['actor']??1001),[]);
    }
    elseif ($x['op']==='checkout_wallet') {
        require_once $argv[1].'/src/Finance/CheckoutAdmission.php';
        $r=achbotCheckoutWallet($db,$x['hash'],(int)($x['actor']??1001),['remark'=>'digits']);
    }
    elseif ($x['op']==='checkout_manual') {
        require_once $argv[1].'/src/Finance/ManualReceipt.php';
        $GLOBALS['botState']['cartToCartState']='on';
        $pay=achbotProviderRow($db,'SELECT * FROM pays WHERE id=?','i',[$x['pay']]);
        $r=achbotManualClaim($db,$pay,'bank_transfer',(int)($x['actor']??1001));
        if ($r && ($x['submit']??false)) {
            require_once $argv[1].'/src/Finance/ReceiptNotices.php';
            $pay=achbotProviderRow($db,'SELECT * FROM pays WHERE id=?','i',[(int)$pay['id']]);
            if (!achbotManualAdmitReceipt($db,$pay,'bank_transfer',1001,'fixture-photo','fixture receipt',inlineKeyboard([])))
                throw new RuntimeException('fixture_manual_admission');
            $notice=achbotProviderRow($db,"SELECT id FROM payment_followup_intents WHERE pay_row_id=? AND stage='receipt_admitted' AND recipient='9001'",'i',[(int)$pay['id']]);
            if (achbotFollowupDispatchOne($db,(int)$notice['id'])!=='acknowledged')
                throw new RuntimeException('fixture_manual_receipt');
        }
    }
    elseif ($x['op']==='checkout_approve') {
        require_once $argv[1].'/src/Finance/CheckoutRoutes.php';
        $r=achbotCheckoutApproveReceipt($db,$x['hash'],(int)($x['actor']??9001),9001);
    }
    elseif ($x['op']==='checkout_reject') {
        require_once $argv[1].'/src/Finance/CheckoutRoutes.php';
        require_once $argv[1].'/src/Finance/GatewayClient.php';
        $pid=(int)$x['pay'];$lease=achbotOriginalDispatchLeaseBegin($db,$pid);
        if ($lease!=='owned') throw new RuntimeException('fixture_reject_lease');
        try {
            if (!$db->begin_transaction()) throw new RuntimeException('fixture_reject_begin');
            try {
                $pay=achbotCheckoutRejectReceiptLocked($db,$pid,1001,(int)($x['actor']??9001),9001,$x['hash']);
                achbotFollowupInsertLocked($db,$pay,'manual_reject','customer','send','1001',null,
                    ['text'=>'fixture original rejection','keys'=>null,'mode'=>'HTML']);
                if (!$db->commit()) throw new RuntimeException('fixture_reject_commit');
                $r=true;
            } catch (Throwable $e) {$db->rollback();throw $e;}
        } finally {achbotOriginalDispatchLeaseEnd($db,$pid,$lease);}
    }
    elseif ($x['op']==='manual_receipt') {
        require_once $argv[1].'/src/Finance/ManualReceipt.php';
        $pid=$x['pay'];$pay=achbotProviderRow($db,'SELECT * FROM pays WHERE id=?','i',[$pid]);
        $issued=achbotManualReceiptIssue($db,$pid,1001,'bank_transfer',$x['id']);
        if ($issued['status']!=='issued') throw new RuntimeException('fixture_manual_issue');
        require_once $argv[1].'/src/Finance/ReceiptNotices.php';
        $pay=achbotProviderRow($db,'SELECT * FROM pays WHERE id=?','i',[$pid]);
        if (!achbotManualAdmitReceipt($db,$pay,'bank_transfer',1001,'fixture-photo','fixture receipt',inlineKeyboard([])))
            throw new RuntimeException('fixture_manual_admission');
        $notice=achbotProviderRow($db,"SELECT id FROM payment_followup_intents WHERE pay_row_id=? AND stage='receipt_admitted' AND recipient='9001'",'i',[$pid]);
        if (achbotFollowupDispatchOne($db,(int)$notice['id'])!=='acknowledged')
            throw new RuntimeException('fixture_manual_receipt');
        $r=achbotCheckoutSettleReceipt($db,$pid,1001,'manual',[],(int)($x['actor']??9001),9001);
    }
    elseif ($x['op']==='referral_config') {
        require_once $argv[1].'/src/Admin/ReferralSettings.php';
        $actor=9001;$GLOBALS['from_id']=$actor;
        $chat=(object)['id'=>$actor,'type'=>'private'];$sender=(object)['id'=>$actor];
        $cached=achbotProviderRow($db,'SELECT * FROM users WHERE userid=?','i',[$actor]);
        $start=(object)['update_id'=>12340,'callback_query'=>(object)['data'=>'editInviteAmount','from'=>$sender,
            'message'=>(object)['message_id'=>12340,'chat'=>$chat,'from'=>(object)['is_bot'=>true]]]];
        if (!achbotReferralSettingsRoute($db,$actor,$start,$cached,'{"keyboard":[]}'))
            throw new RuntimeException('fixture_referral_start');
        $cached=achbotProviderRow($db,'SELECT * FROM users WHERE userid=?','i',[$actor]);
        $input=(object)['update_id'=>12341,'message'=>(object)['from'=>$sender,'chat'=>$chat,'message_id'=>12341,'text'=>$x['value']]];
        if (!achbotReferralSettingsRoute($db,$actor,$input,$cached,'{"keyboard":[]}'))
            throw new RuntimeException('fixture_referral_save');
        $r=achbotPurchaseReferralPolicyAmount($db);
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
        $intent=achbotProviderRow($db,'SELECT * FROM payment_followup_intents WHERE id=? FOR UPDATE','i',[$x['id']]);
        $r=achbotFollowupAttemptClaimLocked($db,$intent,0,null,achbotFollowupDefaultWire($intent));
        $db->commit();
    }
    elseif ($x['op']==='maintain_followups') {
        $other=null;
        if (isset($x['block_service']) || isset($x['block_report'])) {
            $other=new mysqli('localhost','root','',$argv[3],0,$argv[2]);
            if (!$other->set_charset('utf8mb4')) throw new RuntimeException('fixture_charset');
            $lock=isset($x['block_report'])?achbotFollowupLeaseName($other,achbotProviderRow($other,'SELECT * FROM payment_followup_intents WHERE id=?','i',[$x['block_report']])):achbotServiceExecutionLock($other,$x['block_service']);
            if ((int)achbotProviderRow($other,'SELECT GET_LOCK(?,0) AS acquired','s',[$lock])['acquired']!==1)
                throw new RuntimeException('fixture_block_service');
        }
        try {$r=achbotFollowupMaintainOriginalPending($db,$x['limit'],10.0);}
        finally {if ($other!==null) $other->close();}
    }
    elseif ($x['op']==='plan') $r=achbotPhysicalSelectPlan($db,$x['order'],$x['plan'],1001);
    elseif ($x['op']==='plan_recover') $r=achbotPhysicalRecoverPlan($db,$x['journal'],9001,$x['order']);
    elseif ($x['op']==='plan_correct') $r=achbotPhysicalCorrectPlan($db,$x['journal'],9001,$x['order']);
    elseif ($x['op']==='rotate_key') {
        require_once $argv[1].'/src/Panel/ProviderAdmin.php';
        $from_id=9001;$data='';$text=$x['key'];$cancelKey='{}';$message_id=1;$chat_id=9001;
        $userInfo=achbotProviderRow($db,'SELECT * FROM users WHERE userid=9001');
        $update=(object)['update_id'=>1234,'message'=>(object)['text'=>$text,
            'from'=>(object)['id'=>9001],'chat'=>(object)['type'=>'private','id'=>9001]]];
        $r=achbotHandleProviderAdmin();
    }
    elseif ($x['op']==='reassign') $r=achbotReassignLogicalServer($db,$x['order'],$x['server'],9001,true);
    elseif (in_array($x['op'],['delete','local_delete'],true)) {
        $o=achbotProviderRow($db,'SELECT * FROM orders_list WHERE id=?','i',[$x['order']]);
        if (!$o) $r='missing';
        elseif ($x['op']==='delete') $r=achbotSubscriptionDeleteConfirmed($db,$o,true);
        else $r=achbotAutoRenewPrepareDeletion($db,(int)$o['id']) &&
            achbotAutoRenewFinalizeDeletion($db,(int)$o['id'],(int)$o['server_id'],false,'local',9001);
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

from operational_report_fixture import with_message_ack
PHP=with_message_ack(PHP)

def main():
    ap=argparse.ArgumentParser(description=__doc__)
    ap.add_argument('--run-disposable',required=True,action='store_true')
    ap.add_argument('--report',type=Path)
    focus=ap.add_mutually_exclusive_group()
    focus.add_argument('--plan-recovery-only',action='store_true',help='Run focused plan recovery cases')
    focus.add_argument('--resolution-only',action='store_true',help='Run focused current-schema resolution cases during development')
    focus.add_argument('--referral-agent-only',action='store_true',help='Run focused agent purchase referral cases')
    focus.add_argument('--bulk-report-only',action='store_true',help='Run real large purchase report continuation proof')
    focus.add_argument('--zero-commercial-only',action='store_true',help='Run zero-price commercial execution proof')
    focus.add_argument('--name-collision-only',action='store_true',help='Run physical generated-name collision proof')
    focus.add_argument('--report-revisions-only',action='store_true',help='Run real report revision and retry proof')
    focus.add_argument('--payment-actions-only',action='store_true',help='Unified actual Wallet and staff approval route matrix')
    focus.add_argument('--delivery-identity-only',action='store_true',help='Run original customer message identity and due queue proof')
    args=ap.parse_args()
    cases=[]
    with owned.owned_database(ROOT/'database/current-schema.sql') as (work,env):
        client=['mariadb','--no-defaults','--default-character-set=utf8mb4','--protocol=SOCKET','--socket='+str(work/'mysql.sock'),'-uroot','--batch','--skip-column-names',DB]
        def sql(q):
            p=subprocess.run(client,input=q,text=True,encoding="utf-8",env=env,capture_output=True,timeout=15)
            assert p.returncode==0,(q,p.stderr)
            return p.stdout.strip()
        # The current outbox schedules new reports immediately. Isolate the
        # retry/lease scenario without changing any other report's real state,
        # payload or original schedule; restore its schedule after the check.
        def isolate_followup(rid):
            rows=sql(f"SELECT id,COALESCE(CAST(next_attempt_at AS CHAR),'NULL') FROM payment_followup_intents WHERE id<>{int(rid)} AND state IN ('pending','dispatching')").splitlines()
            ids=[int(row.split('\t',1)[0]) for row in rows]
            if ids:
                sql("UPDATE payment_followup_intents SET next_attempt_at=DATE_ADD(NOW(6),INTERVAL 1 HOUR),updated_at=updated_at WHERE id IN ("+','.join(map(str,ids))+")")
            return rows
        def restore_followups(rows):
            for row in rows:
                rid,stamp=row.split('\t',1)
                val='NULL' if stamp=='NULL' else "'"+stamp+"'"
                sql(f"UPDATE payment_followup_intents SET next_attempt_at={val},updated_at=updated_at WHERE id={int(rid)}")
        assert sql('SELECT @@character_set_client,@@character_set_connection,@@character_set_results')=='utf8mb4\tutf8mb4\tutf8mb4'
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
                    with lock:
                        public=imports.public_info(self.path,users.values())
                    if public is not None:
                        assert not self.headers.get('X-Api-Key') and not self.headers.get('Authorization')
                        with lock:requests.append(('IMPORT_INFO',self.path))
                        return self.reply(*public)
                    assert self.headers['X-Api-Key'] in ('fixture','fixture-rotated')
                    # Actual SQL writer on another connection proves no open
                    # InnoDB owner/effect lock spans the authenticated request.
                    with lock:
                        is_import_read=self.path.startswith('/api/user/by-id/') and any(
                            u['note']=='independent-native-origin' and str(u['id'])==self.path.rsplit('/',1)[1]
                            for u in users.values())
                    # The supported import rechecks a read-only proof inside
                    # its transaction; this is not a Native service write.
                    if not is_import_read:
                        sql('SET SESSION innodb_lock_wait_timeout=2; UPDATE users SET wallet=wallet WHERE userid=1001')
                    with lock:
                        requests.append(('GET',self.path))
                        if faults and (faults[0]=='get_unavailable' or (faults[0]=='correction_prewrite_get_unavailable' and sql("SELECT COUNT(*) FROM service_plan_changes WHERE provider='pasarguard_correction' AND state='prepared'")!='0')):
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
                    assert self.headers['X-Api-Key'] in ('fixture','fixture-rotated')
                    path=self.path.split('/')
                    native_id=int(path[4])
                    phase='reset' if self.command=='POST' else 'modify'
                    body=json.loads(self.rfile.read(int(self.headers.get('Content-Length',0)))) if self.command=='PUT' else {}
                    if set(body)=={'group_ids'}:
                        state=sql("SELECT CONCAT(e.provider_state,':',j.state,':',JSON_UNQUOTE(JSON_EXTRACT(e.provider_mutation_snapshot,'$.phase'))) "
                            "FROM service_effects e JOIN service_plan_changes j ON e.provider_mutation_key=CONCAT('plan:',j.id) "
                            f"WHERE e.provider_remote_user_id={native_id}")
                        assert state=='mutating:dispatching:dispatching',state
                        with lock:
                            u=next(v for v in users.values() if v['id']==native_id)
                            requests.append(('PLAN_PUT',body.copy()))
                            fault=faults.pop(0) if faults else ''
                            if fault=='plan_put_unavailable':return self.reply(503,{})
                            u.update(body)
                            ack=u.copy()
                        return self.reply(503 if fault=='plan_put_lost' else 200,{} if fault=='plan_put_lost' else ack)
                    state=sql("SELECT CONCAT(e.provider_state,':',m.phase,':',o.stage,':',m.dispatch_started_at IS NOT NULL) "
                        "FROM service_effects e JOIN service_mutations m "
                        "ON m.account_effect_id=e.id AND e.provider_mutation_key=CONCAT('service:',m.service_operation_id) "
                        "JOIN service_operations o ON o.id=m.service_operation_id "
                        f"WHERE e.provider_remote_user_id={native_id}")
                    assert state=='mutating:'+phase+':dispatching:1',state
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
            sql("""INSERT INTO pays(hash_id,user_id,type,price,request_date,state)
                VALUES ('legacy-delivery-fixture',1001,'INCREASE_WALLET',100,1700000000,'paid');
                INSERT INTO payment_attempts(pay_row_id,provider,order_hash,quote_amount,quote_currency,local_amount_toman,stage)
                SELECT id,'nextpay',hash_id,price,'toman',price,'done' FROM pays WHERE hash_id='legacy-delivery-fixture';""")
            sql("""INSERT INTO referral_awards(pay_row_id,buyer_userid,inviter_userid,amount_toman,state,before_balance,after_balance)
                SELECT id,1001,9002,50,'CONFIRMED',0,50 FROM pays WHERE hash_id='legacy-delivery-fixture';
                INSERT INTO payment_followup_intents(pay_row_id,intent_key,stage,kind,role,recipient,pay_fingerprint,sealed_payload,payload_sha256,state)
                SELECT id,REPEAT('a',64),'referral_reward','send','referral_inviter','9002',REPEAT('b',64),'historical-packet',REPEAT('c',64),'unknown'
                FROM pays WHERE hash_id='legacy-delivery-fixture'""")
            original_award=sql("SELECT id,pay_row_id,amount_toman,state,before_balance,after_balance FROM referral_awards")
            original_report=sql("SELECT id,pay_row_id,sealed_payload,state FROM payment_followup_intents")
            assert sql("SELECT COUNT(*) FROM payment_delivery_intents")=='0'
            assert sql("SELECT COUNT(*) FROM payment_delivery_parts")=='0'
            assert sql("SELECT COUNT(*) FROM payment_delivery_attempts")=='0'
            assert sql("SELECT id,pay_row_id,amount_toman,state,before_balance,after_balance FROM referral_awards")==original_award
            assert sql("SELECT id,pay_row_id,sealed_payload,state FROM payment_followup_intents")==original_report
            assert sql("SELECT COUNT(*) FROM referral_awards WHERE service_operation_id IS NOT NULL")=='0'
            assert sql("SELECT COUNT(*) FROM pays WHERE checkout_operation_id IS NOT NULL")=='0'

            sql("""CREATE TABLE fixture_markup(id INT AUTO_INCREMENT PRIMARY KEY,
                chat_id BIGINT NOT NULL,message_id BIGINT NOT NULL,markup TEXT NULL) ENGINE=InnoDB""")
            sql("""CREATE TABLE fixture_followup(id INT AUTO_INCREMENT PRIMARY KEY,intent_id BIGINT UNSIGNED NOT NULL,
                recipient VARCHAR(128),text TEXT,mode VARCHAR(20),keys_json TEXT) ENGINE=InnoDB""")
            sql("""CREATE TABLE fixture_telegram (
                id INT AUTO_INCREMENT PRIMARY KEY, intent_id BIGINT UNSIGNED NOT NULL,
                user_id BIGINT NOT NULL, part_no INT NOT NULL,kind VARCHAR(12), qr TEXT, caption TEXT, keys_json TEXT, mode VARCHAR(12)) ENGINE=InnoDB""")

            sql("INSERT INTO server_categories(id,title,parent,active) VALUES (1,'Fixture category',0,1)")
            sql("INSERT INTO setting(type,value) VALUES ('PAYMENT_KEYS','{\"bankAccount\":\"fixture-bank\",\"holderName\":\"fixture-holder\"}')")
            sql("INSERT INTO setting(type,value) VALUES ('BOT_STATES','{\"renewAutoState\":\"on\",\"sellState\":\"on\",\"renewAccountState\":\"on\",\"increaseTimeState\":\"on\",\"increaseVolumeState\":\"on\",\"plandelkhahState\":\"on\",\"gbPrice\":10,\"dayPrice\":20}')")
            sql(f"""INSERT INTO server_config(id,panel_url,type,username,password)
                VALUES (2,'{base}','pasarguard','fixture','fixture');
                INSERT INTO server_info(id,title,ucount,remark,flag,active,state) VALUES (2,'Fixture PG','100','pgfixture','',1,1);
                UPDATE server_config SET provider_connection_id=2,provider_instance_key=REPEAT('a',32),
                    provider_version='5.5.1',provider_enabled=1 WHERE id=2;
                INSERT INTO server_plans(id,catid,server_id,title,days,volume,price,descr,active,date,provider_group_ids)
                VALUES (7,1,2,'Fixture PG',30,2,100,'description',1,'fixture','[1,2]');""")
            sql(f"""INSERT INTO server_config(id,panel_url,type,username,password,provider_connection_id,provider_instance_key,provider_version,provider_enabled)
                VALUES (3,'{base}','pasarguard','fixture','fixture',2,REPEAT('a',32),'5.5.1',1);
                INSERT INTO server_info(id,title,ucount,remark,flag,active,state) VALUES (3,'Fixture alias','200','pgalias','',1,1);
                INSERT INTO setting(type,value) VALUES ('ACHBOT_PANEL_GROUP','fixture group');
                UPDATE server_info SET panel_group_id=LAST_INSERT_ID() WHERE id IN (2,3);
                INSERT INTO server_plans(id,catid,server_id,title,days,volume,price,descr,active,date,provider_group_ids)
                VALUES (8,1,2,'Fixture PG next',30,2,100,'description',1,'fixture','[1,2]'),
                       (9,1,3,'Fixture PG alias',30,2,100,'description',1,'fixture','[1,2]'),
                       (10,1,2,'Fixture PG group change',30,2,100,'description',1,'fixture','[1]');""")
            sql('UPDATE users SET wallet=50000 WHERE userid=9002')
            driver=work/'execution.php';driver.write_text(PHP)
            last_diagnostics=[]
            def php(mysqli_mode,op,*,return_diagnostics=False,**kw):
                p=subprocess.run(['php','-n','-d','extension=mysqlnd','-d','extension=mysqli','-d','extension=curl','-d','extension=gd',
                    '-d','allow_url_fopen=0','-d','disable_functions=exec,shell_exec,system,passthru,popen,proc_open,mail',
                    str(driver),str(ROOT),str(work/'mysql.sock'),DB,mysqli_mode,json.dumps(dict(op=op,**kw))],
                    env=env,text=True,encoding="utf-8",capture_output=True,timeout=25)
                if kw.get('crash_after_part'):
                    assert p.returncode==29 and not p.stdout,(p.returncode,p.stdout,p.stderr)
                    return None
                if kw.get('lost_ack'):
                    assert p.returncode==19 and not p.stdout,(p.returncode,p.stdout,p.stderr)
                    return None
                assert p.returncode==0,(p.returncode,p.stdout,p.stderr)
                diagnostics=[]
                for line in p.stderr.splitlines():
                    if line.startswith('[AChBot] '):
                        diagnostics.append(json.loads(line[len('[AChBot] '):]))
                    else:
                        assert line.startswith(('achbot_auto_renew_','achbot_shared_plan_','achbot_shared_transfer_','achbot_subscription_delete_','achbot_plan_correction_')) or (op in ('telegram_wallet','telegram_approval') and line.startswith('achbot_admin_')) or re.fullmatch(r'achbot_followup_ack_unknown id=[1-9][0-9]*|achbot_qr_prepare_failed class=RuntimeException',line),line
                last_diagnostics[:]=diagnostics
                if 'expect_diagnostic' in kw:
                    expected=kw['expect_diagnostic']
                    assert any(all(item.get(k)==v for k,v in expected.items()) for item in diagnostics),(expected,diagnostics)
                try:r=json.loads(p.stdout)
                except ValueError as e:raise AssertionError((op,p.stdout,p.stderr)) from e
                if 'expect_error' in kw:
                    assert not r['ok'] and r['reason']==kw['expect_error'],r
                    return r['reason']
                assert r['ok'],r
                return (r['result'],diagnostics) if return_diagnostics else r['result']
            def issue(mode,ref,quantity=1,server=2,plan=7,policy=None):
                return php(mode,'issue',ref=mode+'-'+ref,quote=dict(kind='BUY',variant='standard',price=100,
                    server_id=server,plan_id=plan,order_id=0,quantity=quantity,days=30,volume=2,
                    remark=mode+'-'+ref+'-12345',uuid='',naming_mode='digits',explicit_name=False,policy=policy or {}))
            def step(mode,id,status,reason=None):
                r=php(mode,'step',id=id)
                assert r['status']==status and (reason is None or r.get('reason')==reason),(mode,id,r,status,reason,last_diagnostics,sql(f"SELECT stage,kind FROM service_operations WHERE id={id}"))
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
            def report_revision_cases(mode):
                def stage_id(op,stage,role='service_admin'):
                    return int(sql(f"SELECT id FROM payment_followup_intents WHERE service_operation_id={op} AND stage='{stage}' AND role='{role}'"))
                def state(rid):
                    return sql(f"SELECT state FROM payment_followup_intents WHERE id={rid}")
                def wire(rid):
                    return sql(f"SELECT CONCAT_WS(':',wire_action,wire_recipient,COALESCE(wire_message_id,0),result,COALESCE(ack_message_id,0)) FROM payment_followup_attempts WHERE intent_id={rid} ORDER BY attempt_no").splitlines()
                def ack(rid):
                    return int(sql(f"SELECT ack_message_id FROM payment_followup_attempts WHERE intent_id={rid} AND result='acknowledged'"))
                def due(rid):
                    sql(f"UPDATE payment_followup_intents SET next_attempt_at=NOW(6) WHERE id={rid}")
                def create(label):
                    op=issue(mode,'report-revision-'+label)
                    receipt=invoice(mode,'report-revision-'+label,op)
                    assert settle(mode,receipt)['funding']=='funded'
                    assert sql(f"SELECT COUNT(*) FROM payment_followup_intents WHERE pay_row_id={receipt[0]} AND stage='wallet_credit'")=='0'
                    return op,receipt[0]
                def complete(op):
                    step(mode,op,'applied');step(mode,op,'completed')
                    return int(sql(f"SELECT id FROM payment_delivery_intents WHERE service_operation_id={op}"))
                # Ready before first send: one final packet, old rows retained
                # as never-attempted superseded evidence.
                op,pid=create('terminal-first');funded=stage_id(op,'service_funded')
                did=complete(op);assert php(mode,'deliver',id=did)=='acknowledged'
                finished=stage_id(op,'service_completed');delivered=stage_id(op,'service_delivered')
                before=finance();n=len(requests)
                assert php(mode,'followup',id=funded)=='acknowledged'
                assert state(funded)==state(finished)=='superseded' and state(delivered)=='acknowledged'
                assert not wire(funded) and not wire(finished) and wire(delivered)[0].startswith('send:9001:0:acknowledged:')
                body=sql(f"SELECT text FROM fixture_followup WHERE intent_id={delivered}")
                assert 'Telegram' in body and f'P{pid}' in body and 'NextPay' in body
                credit=int(sql(f"SELECT wallet_credit_entry_id FROM payment_attempts WHERE pay_row_id={pid}"))
                debit=int(sql(f"SELECT debit_entry_id FROM service_operations WHERE id={op}"))
                assert f'W{credit}' in body and f'W{debit}' in body,body
                assert finance()==before and len(requests)==n
                cases.append(mode+'_logical_report_final_before_first_send')
                # Progress first, then edit exactly the pinned identity. A
                # failed edit recovers without operator or a duplicate send.
                op,pid=create('progress');funded=stage_id(op,'service_funded')
                assert php(mode,'followup',id=funded)=='acknowledged';mid=ack(funded)
                did=complete(op);finished=stage_id(op,'service_completed')
                before=finance();n=len(requests)
                assert php(mode,'followup',id=finished,unknown_ack=True)=='pending'
                assert wire(finished)==[f'edit_text:9001:{mid}:unknown:0']
                assert php(mode,'followup',id=finished)=='not_due'
                assert php(mode,'deliver',id=did)=='acknowledged'
                delivered=stage_id(op,'service_delivered')
                assert php(mode,'followup',id=delivered)=='not_due'
                saved=isolate_followup(delivered)
                assert php(mode,'maintain_followups',limit=1)==[]
                restore_followups(saved)
                due(finished)
                assert php(mode,'followup',id=delivered,response=dict(ok=False,error_code=400,description='Bad Request: message is not modified'))=='acknowledged'
                assert ack(finished)==mid and state(delivered)=='pending'
                assert php(mode,'followup',id=delivered)=='acknowledged' and ack(delivered)==mid
                assert wire(delivered)==[f'edit_text:9001:{mid}:acknowledged:{mid}']
                assert finance()==before and len(requests)==n
                cases.append(mode+'_logical_report_exact_edit_recovers_and_keeps_same_identity')
                # Known missing summary -> new send to the already pinned
                # chat. No fake ACK, never re-create receipt media.
                op,_=create('missing');funded=stage_id(op,'service_funded')
                assert php(mode,'followup',id=funded)=='acknowledged';mid=ack(funded)
                did=complete(op);finished=stage_id(op,'service_completed')
                assert php(mode,'followup',id=finished,response=dict(ok=False,error_code=400,description='Bad Request: message to edit not found'))=='pending'
                assert wire(finished)==[f'edit_text:9001:{mid}:rejected:0']
                assert php(mode,'followup',id=finished)=='acknowledged'
                replacement=ack(finished);assert replacement!=mid
                assert wire(finished)[1]==f'send:9001:0:acknowledged:{replacement}'
                assert php(mode,'deliver',id=did)=='acknowledged'
                delivered=stage_id(op,'service_delivered')
                assert php(mode,'followup',id=delivered)=='acknowledged' and ack(delivered)==replacement
                cases.append(mode+'_logical_report_deleted_message_replaced_once')
                # Unknown non-idempotent first send blocks only that logical
                # recipient. Native, delivery and the channel still succeed.
                op,_=create('ambiguous-send');funded=stage_id(op,'service_funded')
                assert php(mode,'followup',id=funded,unknown_ack=True)=='unknown'
                did=complete(op);assert php(mode,'deliver',id=did)=='acknowledged'
                delivered=stage_id(op,'service_delivered')
                assert php(mode,'followup',id=delivered)=='not_ready' and not wire(delivered)
                saved=isolate_followup(delivered)
                assert php(mode,'maintain_followups',limit=1)==[]
                restore_followups(saved)
                channel=stage_id(op,'service_delivered','service_channel')
                assert php(mode,'followup',id=channel)=='acknowledged'
                before=finance();n=len(requests)
                token=php(mode,'preview_followup',id=funded,actor=9001)
                assert php(mode,'followup',id=funded,actor=9001,confirmation=token)=='acknowledged'
                assert php(mode,'followup',id=delivered)=='acknowledged' and ack(delivered)==ack(funded)
                assert finance()==before and len(requests)==n
                cases.append(mode+'_logical_report_unknown_send_isolated_from_service_and_peer')
                # Retry-After is inherited across both unattempted revisions,
                # not bypassed by completing the service or delivering it.
                op,_=create('rate-inheritance');funded=stage_id(op,'service_funded')
                assert php(mode,'followup',id=funded,response=dict(ok=False,error_code=429,description='Too Many Requests',parameters=dict(retry_after=120)))=='pending'
                stamp=sql(f"SELECT next_attempt_at FROM payment_followup_intents WHERE id={funded}")
                did=complete(op);finished=stage_id(op,'service_completed')
                assert php(mode,'followup',id=finished)=='not_due'
                assert state(funded)=='superseded' and sql(f"SELECT next_attempt_at FROM payment_followup_intents WHERE id={finished}")==stamp
                assert php(mode,'deliver',id=did)=='acknowledged';delivered=stage_id(op,'service_delivered')
                assert php(mode,'followup',id=delivered)=='not_due'
                assert state(finished)=='superseded' and sql(f"SELECT next_attempt_at FROM payment_followup_intents WHERE id={delivered}")==stamp
                assert not wire(delivered);due(delivered)
                assert php(mode,'followup',id=delivered)=='acknowledged'
                assert wire(delivered)[0].startswith('send:9001:0:acknowledged:')
                cases.append(mode+'_logical_report_rate_limit_survives_multiple_revisions')
                # A different original report destination is not serialized
                # by the service execution or another recipient's lease.
                op,_=create('lock-independent');funded=stage_id(op,'service_funded')
                saved=isolate_followup(funded);due(funded)
                assert php(mode,'maintain_followups',limit=1,block_service=op)=={str(funded):'acknowledged'}
                restore_followups(saved)
                cases.append(mode+'_logical_report_transport_lease_independent_of_native')
                complete(op)

            def agent_referral_cases(mode):
                # Original external receipt funds the actual service and
                # finalization owns the decision; no simulated award writer.
                # A zero-discount agent, including bulk purchases, is excluded.
                sql("UPDATE users SET refered_by=9002,is_agent=1 WHERE userid=1001; "
                    "UPDATE users SET is_agent=0 WHERE userid=9002")
                assert php(mode,'referral_config',value='00075')==75
                assert sql("SELECT value FROM setting WHERE type='INVITE_BANNER_AMOUNT'")=='75'
                before=int(sql("SELECT wallet FROM users WHERE userid=9002"))
                for label,quantity,pinned,demote in (
                    ('current-agent',1,False,False),
                    ('agent-bulk',3,True,True),
                ):
                    sql("UPDATE users SET is_agent=1 WHERE userid=1001")
                    op=issue(mode,label,quantity,policy={'agent_bought':pinned})
                    receipt=invoice(mode,label+'-receipt',op)
                    assert settle(mode,receipt)['funding']=='funded'
                    if demote:
                        sql("UPDATE users SET is_agent=0 WHERE userid=1001")
                    for j in range(quantity):
                        step(mode,op,'applied')
                        step(mode,op,'completed' if j==quantity-1 else 'progressed')
                    assert not award(op)
                    assert php(mode,'referral',id=op)=='not_applicable'
                    assert sql(f"SELECT COUNT(*) FROM payment_followup_intents WHERE service_operation_id={op} AND stage='referral_reward'")=='0'
                    assert sql(f"SELECT COUNT(*) FROM wallet_entries WHERE source_kind='purchase_referral' AND source_ref='{op}'")=='0'
                    assert int(sql("SELECT wallet FROM users WHERE userid=9002"))==before
                    assert sql(f"SELECT COUNT(*) FROM orders_list o JOIN service_effects e ON e.order_id=o.id WHERE e.source_kind='service_operation' AND e.source_ref='{op}'")==str(quantity)
                    # Demotion after completion must not backfill a reward.
                    sql("UPDATE users SET is_agent=0 WHERE userid=1001")
                    assert php(mode,'referral',id=op)=='not_applicable'
                    cases.append(mode+'_referral_excludes_'+label.replace('-','_'))
                # Ineligibility belongs to the buyer, not the inviter.
                sql("UPDATE users SET is_agent=1 WHERE userid=9002")
                op=issue(mode,'customer-agent-inviter',policy={'agent_bought':False})
                receipt=invoice(mode,'customer-agent-inviter-receipt',op)
                assert settle(mode,receipt)['funding']=='funded'
                step(mode,op,'applied');step(mode,op,'completed')
                assert award(op)==f'{receipt[0]}:75:NOT_ATTEMPTED'
                assert php(mode,'referral',id=op)=='confirmed'
                assert php(mode,'referral',id=op)=='confirmed'
                assert int(sql("SELECT wallet FROM users WHERE userid=9002"))==before+75
                assert sql(f"SELECT COUNT(*) FROM wallet_entries WHERE source_kind='purchase_referral' AND source_ref='{op}'")=='1'
                cases.append(mode+'_referral_customer_purchase_agent_inviter_eligible')
                cases.append(mode+'_referral_actual_setting_route_purchase_completion')
                # Reuse the actual receipt-funded BUY engine; no fabricated
                # actor for the automatic reward, but retain the real funding actor.
                prior_role=sql("SELECT value FROM setting WHERE type='ADMIN_ROLE_9002'")
                prior_admin=sql("SELECT isAdmin FROM users WHERE userid=9002")
                sql("UPDATE users SET isAdmin=1 WHERE userid=9002; DELETE FROM setting WHERE type IN ('ADMIN_ROLE_9002','ADMIN_NOTIFY_9002'); INSERT INTO setting(type,value) VALUES ('ADMIN_ROLE_9002','operator'),('ADMIN_NOTIFY_9002','off')")
                op=issue(mode,'manual-referral-actor')
                h=mode+'-manual-referral-actor'
                sql(f"INSERT INTO pays(hash_id,user_id,type,price,request_date,state) VALUES ('{h}',1001,'BUY_SUB',100,UNIX_TIMESTAMP(),'pending')")
                pid=int(sql(f"SELECT id FROM pays WHERE hash_id='{h}'"))
                assert php(mode,'manual_receipt',pay=pid,id=op,actor=9002)['funding']=='funded'
                step(mode,op,'applied');step(mode,op,'completed')
                assert php(mode,'referral',id=op)=='confirmed'
                target_rows=sql(f"SELECT role,recipient,id FROM payment_followup_intents WHERE service_operation_id={op} AND stage='referral_reward' ORDER BY role").splitlines()
                assert [v.split('\t')[:2] for v in target_rows]==[['referral_inviter','9002'],['report_actor','9002'],['service_admin','9001'],['service_channel','@fixture_report']],target_rows
                rid=int(target_rows[1].split('\t')[2])
                assert php(mode,'followup',id=rid)=='acknowledged'
                wire=sql(f"SELECT text FROM fixture_followup WHERE intent_id={rid}")
                assert '9002' in wire and h in wire and f'P{pid}' in wire,wire
                assert php(mode,'referral',id=op)=='confirmed'
                assert sql(f"SELECT COUNT(*) FROM wallet_entries WHERE source_kind='purchase_referral' AND source_ref='{op}'")=='1'
                sql(f"UPDATE users SET isAdmin={prior_admin} WHERE userid=9002; DELETE FROM setting WHERE type IN ('ADMIN_ROLE_9002','ADMIN_NOTIFY_9002')")
                if prior_role: sql(f"INSERT INTO setting(type,value) VALUES ('ADMIN_ROLE_9002','{prior_role}')")
                cases.append(mode+'_referral_actual_funding_actor_common_audience')
                sql("UPDATE users SET refered_by=0,is_agent=0 WHERE userid=1001; "
                    "UPDATE users SET is_agent=0 WHERE userid=9002; "
                    "DELETE FROM setting WHERE type='INVITE_BANNER_AMOUNT'")
            def resolution_cases(mode):
                def review(cb,actor=9001,**kw):
                    return php(mode,'telegram_review',actor=actor,callback=cb,**kw)
                def confirmation(r,prefix):
                    assert len(r['review'])==1 and r['review'][0]['mode']=='HTML',r
                    return next(b['callback_data'] for row in r['review'][0]['keys']['inline_keyboard']
                                for b in row if b['callback_data'].startswith(prefix))
                # Resolution on the actual current-schema execution and UI paths.
                def resolve_op(op,decision='refunded',actor=9001):
                    plan=php(mode,'resolution_preview',id=op,decision=decision,actor=actor)
                    assert plan,plan
                    return php(mode,'resolve',id=op,decision=decision,actor=actor,token=plan['token'])
                # Full cancellation before any remote dispatch; no old name,
                # invoice, or claim can consume the original debit again.
                op=issue(mode,'resolution-full');fund(mode,op)
                assert not php(mode,'service_attention',id=op)
                sql(f"UPDATE service_operations SET next_attempt_at=NOW(6)-INTERVAL 3 MINUTE WHERE id={op}")
                assert php(mode,'service_attention',id=op)
                cases.append(mode+'_stalled_funded_worker_visible_without_failure_code')

                cap=int(sql('SELECT ucount FROM server_info WHERE id=2'))
                bal=int(sql('SELECT wallet FROM users WHERE userid=1001'))
                before=len(requests)
                plan=php(mode,'resolution_preview',id=op,decision='refunded')
                assert plan['refund']==100 and plan['cancel_count']==1 and not plan['can_close'],plan
                assert php(mode,'resolve',id=op,decision='refunded',actor=1001,token=plan['token'])['status']=='denied'
                assert php(mode,'resolve',id=op,decision='refunded',token='0'*20)['status']=='stale'
                r=php(mode,'resolve',id=op,decision='refunded',token=plan['token'])
                assert r==dict(status='refunded',refund=100,retained=0,cancelled=1),r
                assert php(mode,'resolve',id=op,decision='refunded',token=plan['token'])['status']=='stale'
                assert php(mode,'fund',id=op)=='request_invalidated'
                assert int(sql('SELECT ucount FROM server_info WHERE id=2'))==cap+1
                assert int(sql('SELECT wallet FROM users WHERE userid=1001'))==bal+100
                assert len(requests)==before
                step(mode,op,'cancelled')
                assert sql(f"SELECT COUNT(*) FROM wallet_entries WHERE source_kind='service_refund' AND source_ref='{op}' AND delta_toman=100")=='1'
                for rid in sql(f"SELECT id FROM payment_followup_intents WHERE service_operation_id={op} AND stage='service_resolution'").splitlines():
                    assert php(mode,'followup',id=int(rid))=='acknowledged'
                cases.append(mode+'_resolution_full_refund_once_and_reports')

                # An operator may deliberately delete a logical server.
                # The missing inventory must not prevent an original refund.
                sid=31 if mode=='strict' else 32
                sql(f"""INSERT INTO server_config(id,panel_url,type,username,password,provider_connection_id,provider_instance_key,provider_version,provider_enabled)
                    SELECT {sid},panel_url,type,username,password,provider_connection_id,provider_instance_key,provider_version,provider_enabled FROM server_config WHERE id=2;
                    INSERT INTO server_info(id,title,ucount,remark,flag,active,state) VALUES ({sid},'Fixture removed','10','removed','',1,1);
                    INSERT INTO server_plans(id,catid,server_id,title,days,volume,price,descr,active,date,provider_group_ids)
                    VALUES ({sid},1,{sid},'Fixture removed',30,2,100,'description',1,'fixture','[1,2]');""")
                op=issue(mode,'resolution-removed-server',server=sid,plan=sid);fund(mode,op)
                sql(f"START TRANSACTION; DELETE FROM server_config WHERE id={sid}; DELETE FROM server_info WHERE id={sid}; COMMIT")
                before=finance();bal=int(sql('SELECT wallet FROM users WHERE userid=1001'));wire=len(requests)
                assert resolve_op(op)==dict(status='refunded',refund=100,retained=0,cancelled=1)
                assert int(sql('SELECT wallet FROM users WHERE userid=1001'))==bal+100
                assert finance()[2]==before[2] and len(requests)==wire
                assert sql(f"SELECT COUNT(*) FROM wallet_entries WHERE source_kind='service_refund' AND source_ref='{op}' AND delta_toman=100")=='1'
                cases.append(mode+'_resolution_refund_after_logical_server_delete')

                # A failed second ordinal does not strand or refund the first.
                op=issue(mode,'resolution-partial',3);fund(mode,op)
                step(mode,op,'applied');step(mode,op,'progressed')
                faults.append('get_unavailable');step(mode,op,'pending','pg_vendor_unavailable')
                assert php(mode,'service_attention',id=op)
                plan=php(mode,'resolution_preview',id=op,decision='refunded')
                assert plan['retained']==1 and plan['cancel_count']==2 and plan['refund']==66 and len(plan['unlinked'])==1,plan
                n=posts();cap=int(sql('SELECT ucount FROM server_info WHERE id=2'))
                bal=int(sql('SELECT wallet FROM users WHERE userid=1001'))
                assert not review(f'attentionServiceRefundC_{op}',actor=1001)['review']
                confirm=confirmation(review(f'attentionServiceRefundC_{op}'),'attentionServiceRefundS_')
                # UI's confirmation goes through the same atomic resolver.
                review(confirm)
                review(confirm)
                assert sql(f"SELECT CONCAT(stage,':',resolution_kind,':',cancelled_count) FROM service_operations WHERE id={op}")=='completed:refunded:2'
                assert int(sql('SELECT ucount FROM server_info WHERE id=2'))==cap+2
                assert int(sql('SELECT wallet FROM users WHERE userid=1001'))==bal+66
                assert posts()==n
                assert sql(f"SELECT COUNT(*) FROM service_effects WHERE source_kind='service_operation' AND source_ref='{op}' AND state='cancelled'")=='1'
                assert sql(f"SELECT COUNT(*) FROM orders_list o JOIN service_effects e ON o.id=e.order_id WHERE e.source_kind='service_operation' AND e.source_ref='{op}' AND o.amount=34")=='1'
                retained_order=int(sql(f"SELECT order_id FROM service_effects WHERE source_kind='service_operation' AND source_ref='{op}' AND state='local_recorded'"))
                assert php(mode,'commercial_plan',order=retained_order)==dict(origin=True,choose=True,paid=True)
                did=int(sql(f"SELECT id FROM payment_delivery_intents WHERE service_operation_id={op}"))
                assert php(mode,'deliver',id=did)=='acknowledged'
                step(mode,op,'completed')
                assert posts()==n
                report_id=int(sql(f"SELECT id FROM payment_followup_intents WHERE service_operation_id={op} AND stage='service_resolution' AND role='service_admin'"))
                assert php(mode,'followup',id=report_id)=='acknowledged'
                wire=sql(f"SELECT text FROM fixture_followup WHERE intent_id={report_id}")
                assert 'تعیین تکلیف شد' in wire and 'نیازمند بررسی است' not in wire,wire
                stored=bytes.fromhex(sql(f'SELECT HEX(text) FROM fixture_followup WHERE intent_id={report_id}')).decode('utf-8')
                assert 'تعیین تکلیف شد' in stored and 'نیازمند بررسی است' not in stored,stored
                assert rows(op)[0].split('\t')[2] in wire,wire
                cases.append(mode+'_resolution_partial_buy_exact_allocation_and_delivery')

                # Verified remote existence cannot be silently refunded.
                op=issue(mode,'resolution-created');fund(mode,op)
                faults.append('lost_response');step(mode,op,'pending','pg_vendor_unavailable')
                plan=php(mode,'resolution_preview',id=op,decision='refunded')
                f=finance()
                assert php(mode,'resolve',id=op,decision='refunded',token=plan['token'])['status']=='account_exists'
                assert finance()==f
                n=posts()
                assert php(mode,'step',id=op,force=True)['status']=='applied'
                step(mode,op,'completed')
                assert posts()==n
                cases.append(mode+'_resolution_existing_native_blocks_refund_and_immediate_retry')
                oid=int(sql(f"SELECT order_id FROM service_effects WHERE source_kind='service_operation' AND source_ref='{op}'"))

                # No dependency on a previous operation's entitlement. While
                # an operation is open, only its own field is a replay fence.
                native(oid)['data_limit']+=7
                e=edit(mode,'resolution-unrelated-edit',oid,'ADD_VOLUME',volume=1);fund(mode,e)
                step(mode,e,'progressed')
                before_quota=native(oid)['data_limit'];native(oid)['expire']=1800000000
                new_expiry=native(oid)['expire']
                step(mode,e,'applied');step(mode,e,'completed')
                assert native(oid)['data_limit']==before_quota+1073741824 and native(oid)['expire']==new_expiry
                cases.append(mode+'_resolution_independent_native_state_and_unrelated_field')

                # Explicit external closure releases the physical marker,
                # does not manufacture a provider result or financial credit.
                e=edit(mode,'resolution-close',oid,'ADD_VOLUME',volume=1);fund(mode,e)
                step(mode,e,'progressed');faults.append('modify_unavailable')
                step(mode,e,'pending','pg_vendor_unavailable')
                native(oid)['data_limit']+=19;retry(e)
                step(mode,e,'pending','pg_service_edit_entitlement_conflict')
                before=finance();n=len(writes())
                assert resolve_op(e,'closed')['status']=='closed'
                assert finance()==before and len(writes())==n
                step(mode,e,'cancelled')
                assert sql(f"SELECT CONCAT(stage,':',resolution_kind) FROM service_operations WHERE id={e}")=='cancelled:closed'
                effect=int(sql(f'SELECT provider_effect_id FROM orders_list WHERE id={oid}'))
                assert sql(f"SELECT provider_state FROM service_effects WHERE id={effect}")=='linked'
                e=edit(mode,'resolution-new-edit',oid,'ADD_TIME',days=2);fund(mode,e)
                step(mode,e,'progressed');step(mode,e,'applied');step(mode,e,'completed')
                cases.append(mode+'_resolution_close_releases_account_and_next_operation')

                # Unperformed edit may be refunded, but a readback proving
                # the requested target was applied blocks a refund.
                e=edit(mode,'resolution-edit-refund',oid,'ADD_VOLUME',volume=1);fund(mode,e)
                step(mode,e,'progressed')
                native(oid)['data_limit']+=1073741824
                quota=native(oid)['data_limit'];n=len(writes())
                bal=int(sql('SELECT wallet FROM users WHERE userid=1001'))
                assert resolve_op(e)['status']=='refunded'
                assert int(sql('SELECT wallet FROM users WHERE userid=1001'))==bal+100
                assert native(oid)['data_limit']==quota and len(writes())==n
                e=edit(mode,'resolution-edit-applied',oid,'ADD_VOLUME',volume=1);fund(mode,e)
                step(mode,e,'progressed');faults.append('modify_lost')
                step(mode,e,'pending','pg_vendor_unavailable')
                f=finance()
                assert resolve_op(e)['status']=='service_applied'
                assert finance()==f
                assert resolve_op(e,'closed')['status']=='closed'
                cases.append(mode+'_resolution_edit_refund_and_known_applied_fence')

                # A scheduled renewal has no pays row, yet appears in the
                # same admin inbox and can be explicitly finalized.
                sql(f"UPDATE orders_list SET notif=1,autoRenew='on' WHERE id={oid}")
                native(oid)['used_traffic']=native(oid)['data_limit']
                r=php(mode,'auto',order=oid);assert r['status']=='funded',r
                aid=r['id'];faults.append('reset_unavailable')
                step(mode,aid,'pending','pg_vendor_unavailable')
                native(oid)['data_limit']+=11;retry(aid)
                step(mode,aid,'pending','pg_service_reset_baseline_conflict')
                assert php(mode,'service_attention',id=aid)
                assert sql(f'SELECT COUNT(*) FROM pays WHERE checkout_operation_id={aid}')=='0'
                card=review(f'attentionService_{aid}')
                confirm=confirmation(card,'attentionServiceCloseC_')
                assert confirm==f'attentionServiceCloseC_{aid}'
                r=resolve_op(aid);assert r['status']=='refunded',r
                assert sql(f'SELECT autoRenew FROM orders_list WHERE id={oid}')=='off'
                assert php(mode,'auto',order=oid)['status']=='ineligible'
                sql(f"UPDATE orders_list SET autoRenew='on' WHERE id={oid}")
                r=php(mode,'auto',order=oid);assert r['status']=='funded',r
                assert r['id']!=aid
                assert resolve_op(r['id'],'closed')['status']=='closed'
                cases.append(mode+'_resolution_auto_renewal_service_only_and_reenable')

            def rotate(mode,key):
                sql("UPDATE users SET step='pg:key:2:2' WHERE userid=9001")
                before=sum(x[0]=='PLAN_PUT' for x in requests)
                assert php(mode,'rotate_key',key=key) is True
                assert sql("SELECT DISTINCT password FROM server_config WHERE provider_connection_id=2")==key
                assert sql("SELECT step FROM users WHERE userid=9001")=='none'
                assert sum(x[0]=='PLAN_PUT' for x in requests)==before
            def plan_recovery_cases(mode):
                # A real plan attempt commits its journal before Native
                # readback. Its unresolved journal is not a new sale target.
                buy=issue(mode,'pending-plan');fund(mode,buy);step(mode,buy,'applied');step(mode,buy,'completed')
                order=int(sql(f"SELECT order_id FROM service_effects WHERE source_kind='service_operation' AND source_ref='{buy}'"))
                unpaid=edit(mode,'pending-plan-edit',order,'ADD_TIME',days=1)
                unrelated_buy=issue(mode,'unrelated-plan-draft');fund(mode,unrelated_buy)
                step(mode,unrelated_buy,'applied');step(mode,unrelated_buy,'completed')
                unrelated_order=int(sql(f"SELECT order_id FROM service_effects WHERE source_kind='service_operation' AND source_ref='{unrelated_buy}'"))
                # A read outage before any PUT must not create a permanent
                # hold, consume money or invalidate an otherwise valid quote.
                f=finance();n=sum(x[0]=='PLAN_PUT' for x in requests)
                faults.append('get_unavailable')
                r=php(mode,'plan',order=order,plan=8);assert not r['success'],r
                assert sql(f"SELECT state FROM service_plan_changes WHERE source_order_id={order} ORDER BY id DESC LIMIT 1")=='cancelled'
                assert finance()==f and sum(x[0]=='PLAN_PUT' for x in requests)==n
                # Simulate process loss after the original prepared commit.
                # No lease is held; the next original funding safely retires it.
                sql(f"INSERT INTO service_plan_changes(lock_key,source_order_id,plan_id,server_id,provider,state,intent_json) SELECT lock_key,source_order_id,plan_id,server_id,provider,'prepared',intent_json FROM service_plan_changes WHERE source_order_id={order} ORDER BY id DESC LIMIT 1")
                assert php(mode,'fund',id=unpaid)=='funded'
                assert sql(f"SELECT state FROM service_plan_changes WHERE source_order_id={order} ORDER BY id DESC LIMIT 1")=='cancelled'
                for stage in ('progressed','applied','completed'):step(mode,unpaid,stage)
                assert php(mode,'plan',order=order,plan=8)['success']
                cases.append(mode+'_plan_prewrite_outage_and_abandoned_preparation_recover_without_manual_hold')
                # Actual group-changing PUT with lost ACK must retain a
                # durable hold. Exceed the former lifetime replay ceiling.
                unpaid=edit(mode,'actual-plan-pending-edit',order,'ADD_TIME',days=1)
                keys=['fixture-history:'+str(i) for i in range(4096)]
                snapshot=json.dumps({'version':1,'key':keys[-1],'consumed_keys':keys},separators=(',',':'))
                sql(f"UPDATE service_effects e JOIN orders_list o ON o.provider_effect_id=e.id SET e.provider_mutation_key='{keys[-1]}',e.provider_mutation_snapshot='{snapshot}' WHERE o.id={order}")
                f=finance();n=sum(x[0]=='PLAN_PUT' for x in requests)
                faults.append('plan_put_lost')
                r=php(mode,'plan',order=order,plan=10);assert not r['success'],r
                assert sum(x[0]=='PLAN_PUT' for x in requests)==n+1
                assert sql(f"SELECT COUNT(*) FROM service_plan_changes WHERE source_order_id={order} AND state='dispatching'")=='1'
                assert php(mode,'fund',id=unpaid)=='request_invalidated' and finance()==f
                cases.append(mode+'_unresolved_plan_intent_blocks_new_debit')
                cases.append(mode+'_native_mutation_replay_history_has_no_lifetime_count_ceiling')
                rejected_hash=mode+'-blocked-plan-draft'
                assert php(mode,'checkout_insert',hash=rejected_hash,type='RENEW_ACCOUNT',price=100,order=order)==0
                assert sql(f"SELECT COUNT(*) FROM pays WHERE hash_id='{rejected_hash}'")=='0'
                # Lock the other account's actual unresolved journal using a
                # separate connection. The old global FOR UPDATE scan could
                # not create this unrelated draft within its short deadline.
                journal=int(sql(f"SELECT id FROM service_plan_changes WHERE source_order_id={order} AND state='dispatching'"))
                hold_php=('mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);'
                    '$db=new mysqli("localhost","root","",$argv[2],0,$argv[1]);'
                    '$db->begin_transaction();'
                    '$r=$db->query("SELECT id FROM service_plan_changes WHERE id=".(int)$argv[3]." FOR UPDATE");'
                    'if ($r->num_rows!==1) exit(3);echo "LOCKED\\n";flush();fgets(STDIN);$db->rollback();')
                holder=subprocess.Popen(['php','-n','-d','extension=mysqlnd','-d','extension=mysqli','-r',hold_php,
                    str(work/'mysql.sock'),DB,str(journal)],env=env,text=True,stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
                try:
                    assert holder.stdout.readline().strip()=='LOCKED'
                    accepted=php(mode,'checkout_insert',hash=mode+'-unrelated-plan-draft',type='RENEW_ACCOUNT',
                        price=100,order=unrelated_order,short_lock_timeout=True)
                    assert accepted>0 and finance()==f
                finally:
                    _,err=holder.communicate('release\n',timeout=10)
                    assert holder.returncode==0 and not err,err
                cases.append(mode+'_current_draft_scoped_to_own_plan_journal')
                # Credentials can rotate while a verified plan result is
                # awaiting local completion. Recover the exact account using
                # fresh credentials; never require the retired secret.
                rotate(mode,'fixture-rotated')
                r=php(mode,'plan_recover',order=order,journal=journal)
                assert r['success'],r
                assert sum(x[0]=='PLAN_PUT' for x in requests)==n+1 and finance()==f
                assert sql(f"SELECT fileid FROM orders_list WHERE id={order}")=='10'
                assert sql(f"SELECT state FROM service_plan_changes WHERE id={journal}")=='committed'
                rotate(mode,'fixture')
                cases.append(mode+'_plan_lost_ack_recovers_with_current_rotated_credential')


                # A corrective preflight that never dispatches must not consume
                # the authorized one-shot write, including after restart.
                faults.append('plan_put_unavailable')
                r=php(mode,'plan',order=order,plan=7);assert not r['success'],r
                original=int(sql(f"SELECT id FROM service_plan_changes WHERE source_order_id={order} AND provider='pasarguard' AND state='dispatching'"))
                n=sum(x[0]=='PLAN_PUT' for x in requests);before=finance()
                faults.append('correction_prewrite_get_unavailable')
                r=php(mode,'plan_correct',order=order,journal=original);assert not r['success'],r
                assert sum(x[0]=='PLAN_PUT' for x in requests)==n and finance()==before
                assert sql(f"SELECT state FROM service_plan_changes WHERE source_order_id={order} AND provider='pasarguard_correction' ORDER BY id DESC LIMIT 1")=='cancelled'
                r=php(mode,'plan_correct',order=order,journal=original);assert r['success'],r
                assert sum(x[0]=='PLAN_PUT' for x in requests)==n+1 and finance()==before
                assert sql(f"SELECT fileid FROM orders_list WHERE id={order}")=='7'
                assert sql(f"SELECT state FROM service_plan_changes WHERE id={original}")=='committed'
                assert sql(f"SELECT state FROM service_plan_changes WHERE source_order_id={order} AND provider='pasarguard_correction' ORDER BY id DESC LIMIT 1")=='committed'
                cases.append(mode+'_plan_correction_prewrite_outage_does_not_consume_dispatch')

            # Old prepaid claims are historical only. Neither the current
            # wallet callback nor a worker may adopt/replay them. Keep the
            # actual debit/capacity evidence for separate manual reconciliation.
            cases.extend(mode+'_current_schema_source_execution_without_migrations' for mode in ('strict','off'))
            historical={}
            for mode in ('strict','off'):
                ref=mode+'-prepaid-custom'
                sql(f"""INSERT INTO pays(hash_id,user_id,type,price,request_date,state,server_id,plan_id,day,volume,agent_count,description)
                    VALUES ('{ref}',1001,'BUY_SUB',100,UNIX_TIMESTAMP(),'pending',2,7,17,5,1,'{ref}')""")
                pid=int(sql(f"SELECT id FROM pays WHERE hash_id='{ref}'"))
                assert php(mode,'legacy_reserve',pay=pid)
                historical[mode]=pid
            snap=php('strict','cutover_snapshot')
            php('strict','cutover_open',expected=snap['sha256'])
            before=finance();n=len(requests)
            for mode,pid in historical.items():
                ref=mode+'-prepaid-custom'
                assert php(mode,'checkout_wallet',hash=ref,expect_error='checkout_draft_unavailable')
                assert sql(f"SELECT CONCAT(state,':',checkout_operation_id IS NULL) FROM pays WHERE id={pid}")=='processing_wallet:1'
                assert sql(f"SELECT state FROM service_effects WHERE pay_row_id={pid}")=='reserved'
                assert finance()==before and len(requests)==n
                cases.append(mode+'_historical_prepaid_claim_no_adoption_or_new_effect')
            for mode in (() if args.payment_actions_only or args.delivery_identity_only or args.report_revisions_only or args.resolution_only or args.referral_agent_only or args.plan_recovery_only or args.bulk_report_only or args.zero_commercial_only or args.name_collision_only else ('strict','off')):
                # Isolate each test mode's scheduler from previously deferred
                # conflict fixtures; no terminal evidence is changed.
                sql("UPDATE service_operations SET next_attempt_at=DATE_ADD(NOW(6),INTERVAL 1 DAY) WHERE stage IN ('funded','prepared','dispatching','applied')")
                sql("UPDATE payment_delivery_intents SET updated_at=NOW() WHERE service_operation_id IS NOT NULL AND state='pending'")
                original=issue(mode,'rotate-funded-buy',server=3,plan=9);fund(mode,original)
                before=finance()
                rotate(mode,'fixture-rotated')
                rotate(mode,'fixture')
                assert finance()==before
                step(mode,original,'applied');step(mode,original,'completed')
                rebind_order=int(sql(f"SELECT order_id FROM service_effects WHERE source_kind='service_operation' AND source_ref='{original}'"))
                assert php(mode,'delete',order=rebind_order)=='complete'
                cases.append(mode+'_funded_buy_allows_actual_credential_rotation')
                id=issue(mode,'bulk',3);n=len(requests)
                step(mode,id,'awaiting_funds');assert len(requests)==n and not rows(id)
                cases.append(mode+'_unfunded_no_io')
                fund(mode,id);before=finance();n=posts()
                step(mode,id,'applied');assert posts()==n+1 and len(rows(id))==1
                # Verify atomic finalization after the Native success is
                # already committed. A failed delivery insert rolls back the
                # Order and link. Retry is GET-only, never another CREATE.
                sql("CREATE TRIGGER fixture_delivery_fail BEFORE INSERT ON payment_delivery_intents FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture rollback'")
                try:
                    step(mode,id,'pending','provider_storage_unavailable')
                    assert any(d.get('sql_errno')==1644 and d.get('sql_state')=='45000' and d.get('phase')=='apply' for d in last_diagnostics),last_diagnostics
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
                faults.append('lost_response')
                step(mode,id,'pending','pg_vendor_unavailable')
                original=rows(id)[1].split('\t')[:5]
                assert posts()==n+2 and len(rows(id))==2
                step(mode,id,'not_due')
                retry(id);step(mode,id,'applied')
                assert rows(id)[1].split('\t')[:5]==original and posts()==n+2
                step(mode,id,'progressed')
                step(mode,id,'applied');assert posts()==n+3 and len(rows(id))==3
                assert len({row.split('\t')[2] for row in rows(id)})==3
                assert finance()==before
                cases.append(mode+'_bulk_lost_response_exact_ordinal')
                # The last ordinal and completed state commit together.
                php(mode,'step',id=id,lost_ack=True)
                step(mode,id,'completed')
                assert posts()==n+3 and finance()==before
                assert sql(f"SELECT COUNT(*),SUM(o.amount),SUM(o.provider_effect_id=e.id) FROM service_effects e JOIN orders_list o ON o.id=e.order_id WHERE e.source_kind='service_operation' AND e.source_ref='{id}'")=='3\t100\t3'
                assert sql(f"SELECT COUNT(*) FROM payment_delivery_intents WHERE service_operation_id={id} AND state='pending'")=='3'
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
                assert php(mode,'deliver',id=delivery_ids[1],part=2,actor=9001,confirmation=token)=='stale'
                token=php(mode,'preview_delivery',id=delivery_ids[1],part=2,actor=9001)
                assert php(mode,'deliver',id=delivery_ids[1],part=2,actor=9001,confirmation=token)=='acknowledged'
                assert sql(f"SELECT COUNT(*) FROM fixture_telegram WHERE intent_id={delivery_ids[1]} AND part_no=1")=='1'
                assert sql(f"SELECT COUNT(*) FROM fixture_telegram WHERE intent_id={delivery_ids[1]} AND part_no=2")=='3'
                php(mode,'claim_delivery',id=delivery_ids[2])
                assert php(mode,'deliver',id=delivery_ids[2])=='unknown'
                assert sql(f"SELECT COUNT(*) FROM fixture_telegram WHERE intent_id={delivery_ids[2]} AND part_no=1")=='0'
                assert sql(f"SELECT COUNT(*) FROM fixture_telegram WHERE intent_id={delivery_ids[2]} AND part_no=2")=='1'
                assert sql(f"SELECT GROUP_CONCAT(CONCAT(part_no,':',state) ORDER BY part_no) FROM payment_delivery_parts WHERE intent_id={delivery_ids[2]}")=='1:unknown,2:acknowledged'
                token=php(mode,'preview_delivery',id=delivery_ids[2],part=1,actor=9001)
                assert php(mode,'deliver',id=delivery_ids[2],part=1,actor=9001,confirmation=token)=='acknowledged'
                assert php(mode,'deliver',id=delivery_ids[2])=='acknowledged'
                assert sql(f"SELECT COUNT(*) FROM payment_delivery_attempts a JOIN payment_delivery_intents d ON d.id=a.intent_id WHERE d.service_operation_id={id} AND a.result IN ('acknowledged','unknown')")=='9'
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
                sql(f"UPDATE payment_delivery_intents SET next_attempt_at=DATE_ADD(NOW(6),INTERVAL 1 DAY) WHERE state IN ('pending','dispatching') AND id NOT IN ({delivery},{next_delivery})")
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
                sql("UPDATE service_operations SET next_attempt_at=DATE_ADD(NOW(6),INTERVAL 1 DAY) WHERE stage IN ('funded','prepared','dispatching','applied')")
                first=issue(mode,'busy-first');fund(mode,first)
                second=issue(mode,'busy-second');fund(mode,second)
                before=finance()
                r=php(mode,'due',limit=1,block_service=first)
                assert set(r)=={str(second)} and r[str(second)]['status']=='applied',r
                assert finance()==before
                step(mode,first,'applied')
                cases.append(mode+'_busy_due_head_does_not_starve_next_operation')
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
                step(mode,renewal,'progressed')
                assert mutation(renewal).startswith('reset\t')
                faults.append('reset_traffic')
                step(mode,renewal,'progressed')
                assert mutation(renewal).startswith('modify\t') and mutation(renewal).split('\t')[2]
                original=mutation(renewal).split('\t')[1]
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
                step(mode,volume,'progressed')
                reqs=len(requests)
                step(mode,time_edit,'pending','service_edit_account_busy')
                assert len(requests)==reqs
                faults.append('modify_lost')
                quota=native(order)['data_limit']
                step(mode,volume,'pending','pg_vendor_unavailable')
                assert native(order)['data_limit']==quota+1073741824
                original=mutation(volume).split('\t')[1]
                retry(volume);step(mode,volume,'applied');step(mode,volume,'completed')
                assert mutation(volume).split('\t')[1]==original
                assert writes()[n:]==[('PUT',{'data_limit':quota+1073741824})]
                retry(time_edit);step(mode,time_edit,'progressed')
                step(mode,time_edit,'applied');step(mode,time_edit,'completed')
                assert len(writes())==n+2 and set(writes()[-1][1])=={'expire'}
                assert native(order)['data_limit']==quota+1073741824 and finance()==before
                cases.append(mode+'_alias_fence_and_lost_put_absolute_addons')
                # Independent Native work before ANY write is not our result.
                # Even exact equality with the old prepared target cannot prove it.
                fresh=edit(mode,'edit-fresh-before-dispatch',order,'ADD_VOLUME',volume=1)
                fund(mode,fresh);step(mode,fresh,'progressed')
                faults.append('get_unavailable')
                step(mode,fresh,'pending','pg_vendor_unavailable')
                assert sql(f"SELECT dispatch_started_at IS NULL FROM service_mutations WHERE service_operation_id={fresh}")=='1'
                native(order)['data_limit']+=1073741824
                quota=native(order)['data_limit'];before=finance();n=len(writes())
                retry(fresh);step(mode,fresh,'applied');step(mode,fresh,'completed')
                assert native(order)['data_limit']==quota+1073741824 and finance()==before
                assert writes()[n:]==[('PUT',{'data_limit':quota+1073741824})]
                cases.append(mode+'_pre_dispatch_native_target_equality_not_our_effect')
                # After a possible dispatch, a different authoritative
                # entitlement is never overwritten or used for another delta.
                conflict=edit(mode,'edit-drift',order,'ADD_VOLUME',volume=1)
                fund(mode,conflict);step(mode,conflict,'progressed')
                faults.append('modify_unavailable')
                step(mode,conflict,'pending','pg_vendor_unavailable')
                native(order)['data_limit']+=7;retry(conflict)
                n=len(writes());before=finance()
                step(mode,conflict,'pending','pg_service_edit_entitlement_conflict')
                assert len(writes())==n and finance()==before
                cases.append(mode+'_edit_external_drift_no_blind_overwrite')
                # No historical renewal journal exists in this fixture.
                assert sql("SHOW TABLES LIKE 'automatic_renewal_attempts'")==''
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
                histories={u:php(mode,'gateway_history',owner=u,metric='renew') for u in (1001,9002)}
                sales_before=php(mode,'sales_snapshot')
                assert all(isinstance(x,int) for x in histories.values()),histories
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
                assert all(php(mode,'gateway_history',owner=u,metric='renew')==v for u,v in histories.items())
                assert php(mode,'sales_snapshot')==sales_before
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
                assert all(php(mode,'gateway_history',owner=u,metric='renew')==v+int(u==uid) for u,v in histories.items())
                cases.append(mode+'_gateway_history_counts_only_actual_completed_automatic_renewal')
                auto_price=int(sql(f"SELECT price_toman FROM service_operations WHERE id={winner}"))
                auto_sales=dict(sales_before)
                auto_sales['n']+=1;auto_sales['total']+=auto_price
                auto_sales['wallet_count']+=1;auto_sales['wallet_total']+=auto_price
                assert php(mode,'sales_snapshot')==auto_sales
                loser=alias if owner_order==ao else ao
                assert php(mode,'auto',order=loser,owner=9002 if loser==alias else 1001)['status']=='ineligible'
                did=int(sql(f"SELECT id FROM payment_delivery_intents WHERE service_operation_id={winner}"))
                assert php(mode,'deliver',id=did)=='acknowledged'
                assert sql(f"SELECT COUNT(*) FROM fixture_telegram WHERE intent_id={did} AND user_id={uid} AND caption LIKE '%rn_%'")=='1'
                reports=sql(f"SELECT id,role,pay_row_id FROM payment_followup_intents WHERE service_operation_id={winner} ORDER BY id").splitlines()
                assert len(reports)==2 and all(x.endswith('\tNULL') for x in reports),reports
                sql("UPDATE payment_followup_intents SET updated_at=NOW() WHERE state='pending' AND next_attempt_at IS NULL")
                for oid in sql("SELECT id FROM payment_followup_intents WHERE operational_ref IS NOT NULL AND state='pending' ORDER BY id").splitlines():
                    assert php(mode,'followup',id=int(oid))=='acknowledged'
                report_id=int(reports[0].split('\t')[0])
                rate_limit=dict(ok=False,error_code=429,description='Too Many Requests',parameters=dict(retry_after=35))
                assert php(mode,'followup',id=report_id,response=rate_limit)=='pending'
                assert sql(f"SELECT CONCAT(result,':',telegram_error_code,':',retry_after_seconds) FROM payment_followup_attempts WHERE intent_id={report_id}")=='rejected:429:35'
                assert sql(f"SELECT next_attempt_at>NOW(6) FROM payment_followup_intents WHERE id={report_id}")=='1'
                assert php(mode,'followup',id=report_id)=='not_due'
                schedules=isolate_followup(report_id)
                assert php(mode,'maintain_followups',limit=10)==[]
                assert sql(f"SELECT COUNT(*) FROM fixture_followup WHERE intent_id={report_id}")=='1'
                sql(f"UPDATE payment_followup_intents SET next_attempt_at=NOW(6) WHERE id={report_id}")
                assert php(mode,'maintain_followups',limit=10,block_report=report_id)=={str(report_id):'busy'}
                assert sql(f"SELECT next_attempt_at>NOW(6) FROM payment_followup_intents WHERE id={report_id}")=='1'
                assert php(mode,'maintain_followups',limit=10)==[]
                assert sql(f"SELECT COUNT(*) FROM fixture_followup WHERE intent_id={report_id}")=='1'
                cases.append(mode+'_followup_busy_due_row_deferred_fairly')
                sql(f"UPDATE payment_followup_intents SET next_attempt_at=NOW(6) WHERE id={report_id}")
                assert php(mode,'maintain_followups',limit=10,block_service=winner)=={str(report_id):'acknowledged'}
                assert php(mode,'followup',id=report_id)=='acknowledged'
                assert sql(f"SELECT COUNT(*) FROM fixture_followup WHERE intent_id={report_id} AND text LIKE '%rn_%'")=='2'
                assert sql(f"SELECT GROUP_CONCAT(result ORDER BY attempt_no) FROM payment_followup_attempts WHERE intent_id={report_id}")=='rejected,acknowledged'
                restore_followups(schedules)
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
                cases.append(mode+'_automatic_renewal_without_historical_table')
                assert php(mode,'sales_snapshot')==auto_sales
                cases.append(mode+'_actual_auto_renewal_sales_once_despite_delivery_and_report_retries')
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
                assert php(mode,'followup',id=notice,response=dict(ok=False,error_code=403,description='Forbidden'))=='pending'
                assert php(mode,'followup',id=notice)=='not_due'
                assert sql(f"SELECT COUNT(*) FROM fixture_followup WHERE intent_id={notice}")=='1'
                sql(f"UPDATE payment_followup_intents SET next_attempt_at=NOW(6) WHERE id={notice}")
                assert php(mode,'followup',id=notice)=='acknowledged'
                assert php(mode,'followup',id=notice)=='acknowledged'
                assert sql(f"SELECT COUNT(*) FROM fixture_followup WHERE intent_id={notice} AND recipient='1001'")=='2'
                cases.append(mode+'_followup_definite_permission_failure_automatic_recovery')
                # A later intentional re-enable can still renew the original cycle.
                sql(f"UPDATE orders_list SET autoRenew='on' WHERE id={lo}")
                r=php(mode,'auto',order=lo);assert r['status']=='funded',r
                aid=r['id'];step(mode,aid,'progressed');step(mode,aid,'applied');step(mode,aid,'completed')
                for row in sql(f"SELECT id FROM payment_followup_intents WHERE service_operation_id={aid}").splitlines():
                    assert php(mode,'followup',id=int(row))=='acknowledged'
                cases.append(mode+'_auto_low_wallet_durable_notice_and_deliberate_reenable')
                cases.append(mode+'_automatic_renewal_low_wallet_no_charge')
                # Before the first possible reset, automatic renewal refreshes
                # Native state; after that boundary, it cannot change the intent.
                buy=issue(mode,'auto-drift');fund(mode,buy);step(mode,buy,'applied');step(mode,buy,'completed')
                od=int(sql(f"SELECT order_id FROM service_effects WHERE source_kind='service_operation' AND source_ref='{buy}'"))
                sql(f"UPDATE orders_list SET notif=1,autoRenew='on' WHERE id={od}")
                native(od)['used_traffic']=native(od)['data_limit']
                r=php(mode,'auto',order=od);assert r['status']=='funded',r
                aid=r['id'];assert mutation(aid).startswith('reset')
                native(od)['data_limit']+=7
                faults.append('reset_unavailable')
                step(mode,aid,'pending','pg_vendor_unavailable')
                intent=json.loads(sql(f"SELECT intent_payload FROM service_mutations WHERE service_operation_id={aid}"))
                assert intent['before']['data_limit']==native(od)['data_limit']
                native(od)['data_limit']+=7;retry(aid)
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
                saved_states=sql("SELECT value FROM setting WHERE type='BOT_STATES'")
                sql("UPDATE setting SET value='"+json.dumps({'renewAutoState':'on','agencyState':'on'})+"' WHERE type='BOT_STATES'")
                r=php(mode,'auto',order=od,bot_state={'renewAutoState':'on'});assert r['status']=='funded',r
                sql("UPDATE setting SET value='"+saved_states+"' WHERE type='BOT_STATES'")
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
                # Missing attempt evidence is corruption, not a historical
                # report compatibility mode. Keep the dispatch state intact.
                sql(f"CREATE TABLE fixture_saved_attempt LIKE payment_followup_attempts; INSERT INTO fixture_saved_attempt SELECT * FROM payment_followup_attempts WHERE intent_id={rid}; DELETE FROM payment_followup_attempts WHERE intent_id={rid}")
                try:
                    assert php(mode,'followup',id=rid)=='unavailable'
                    assert sql(f"SELECT state FROM payment_followup_intents WHERE id={rid}")=='dispatching'
                    assert sql(f"SELECT COUNT(*) FROM fixture_followup WHERE intent_id={rid}")=='1'
                finally:
                    sql("INSERT INTO payment_followup_attempts SELECT * FROM fixture_saved_attempt; DROP TABLE fixture_saved_attempt")
                cases.append(mode+'_missing_current_report_attempt_no_legacy_fallback')
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

                plan_recovery_cases(mode)

                # Actual externally funded BUY: one frozen reward for all
                # ordinals, no reward on another late receipt or wallet BUY.
                sql("UPDATE users SET refered_by=9002 WHERE userid=1001; INSERT INTO setting(type,value) VALUES ('INVITE_BANNER_AMOUNT','75')")
                op=issue(mode,'referral-bulk',3)
                first=invoice(mode,'referral-first',op);late=invoice(mode,'referral-late',op)
                assert settle(mode,first)['funding']=='funded'
                assert settle(mode,first)['credit']['replayed']
                assert settle(mode,late)['funding']=='already_funded'
                assert not award(op)
                for stage in ('applied','progressed','applied','progressed','applied'):
                    step(mode,op,stage);assert not award(op)
                php(mode,'step',id=op,lost_ack=True)
                assert sql(f"SELECT stage FROM service_operations WHERE id={op}")=='completed'
                assert award(op)==f'{first[0]}:75:NOT_ATTEMPTED'
                assert sql(f"SELECT COUNT(*) FROM payment_followup_intents WHERE service_operation_id={op} AND stage='service_funded'")=='2'
                assert sql(f"SELECT COUNT(*) FROM payment_followup_intents WHERE pay_row_id={late[0]} AND stage='wallet_credit'")=='3'
                report_id=int(sql(f"SELECT id FROM payment_followup_intents WHERE service_operation_id={op} AND stage='service_completed' AND role='service_admin'"))
                assert php(mode,'followup',id=report_id)=='acknowledged'
                wire=sql(f"SELECT text FROM fixture_followup WHERE intent_id={report_id}")
                assert 'nextpay' in wire.lower() and all(row.split('\t')[2] in wire for row in rows(op)),wire
                debit=sql(f"SELECT w.id,w.balance_before,w.balance_after FROM service_operations s JOIN wallet_entries w ON w.id=s.debit_entry_id WHERE s.id={op}").split('\t')
                assert f'P{first[0]}' in wire and f'W{debit[0]}' in wire,wire
                assert f"موجودی پیش از کسر: {int(debit[1]):,} تومان" in wire,wire
                assert f"موجودی پس از کسر: {int(debit[2]):,} تومان" in wire,wire
                cases.append(mode+'_service_report_original_receipt_and_wallet_debit')
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
                assert set(targets)=={'referral_inviter','service_admin','service_channel'},targets
                rid=int(targets['service_admin'])
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
                assert php(mode,'followup',id=rid,actor=9001,confirmation=preview)=='stale'
                preview=php(mode,'preview_followup',id=rid,actor=9001)
                assert php(mode,'followup',id=rid,actor=9001,confirmation=preview)=='acknowledged'
                assert sql(f"SELECT COUNT(*) FROM fixture_followup WHERE intent_id={rid}")=='3'
                cases.append(mode+'_followup_unknown_manual_attempt_not_repeated')
                sql("UPDATE payment_followup_intents SET updated_at=NOW() WHERE state='pending' AND next_attempt_at IS NULL")
                for oid in sql("SELECT id FROM payment_followup_intents WHERE operational_ref IS NOT NULL AND state='pending' ORDER BY id").splitlines():
                    assert php(mode,'followup',id=int(oid))=='acknowledged'
                rid=int(targets['service_channel'])
                assert php(mode,'claim_followup',id=rid)['status']=='claimed'
                sql(f"UPDATE payment_followup_intents SET updated_at=DATE_SUB(NOW(),INTERVAL 3 MINUTE) WHERE id={rid}")
                schedules=isolate_followup(rid)
                assert php(mode,'maintain_followups',limit=10)=={str(rid):'unknown'}
                restore_followups(schedules)
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
                    assert sql(f"SELECT COUNT(*) FROM payment_followup_intents WHERE service_operation_id={eid} AND stage='service_funded'")=='2'
                    for stage in (['progressed','progressed','applied','completed'] if kind=='RENEW' else ['progressed','applied','completed']):
                        step(mode,eid,stage)
                    report_id=int(sql(f"SELECT id FROM payment_followup_intents WHERE service_operation_id={eid} AND stage='service_completed' AND role='service_admin'"))
                    before=finance();n=len(requests)
                    assert php(mode,'followup',id=report_id)=='acknowledged'
                    assert php(mode,'followup',id=report_id)=='acknowledged'
                    assert sql(f"SELECT COUNT(*) FROM fixture_followup WHERE intent_id={report_id}")=='1'
                    did=int(sql(f"SELECT id FROM payment_delivery_intents WHERE service_operation_id={eid}"))
                    assert php(mode,'deliver',id=did)=='acknowledged'
                    wire=sql(f"SELECT caption,keys_json,mode FROM fixture_telegram WHERE intent_id={did}").split('	')
                    assert len(wire)==3 and wire[2]=='NULL' and json.loads(wire[1])['inline_keyboard'][0][0]['callback_data']=='mainMenu',wire
                    assert sql(f"SELECT COUNT(*) FROM payment_followup_intents WHERE service_operation_id={eid} AND stage='service_delivered'")=='2'
                    assert finance()==before and len(requests)==n
                    cases.append(mode+'_hosted_'+kind.lower()+'_original_report_and_customer_menu')
                mid=edit(mode,'manual-addon',order,'ADD_TIME',days=2)
                ref=mode+'-manual-addon'
                sql(f"INSERT INTO pays(hash_id,user_id,type,price,request_date,state) VALUES ('{ref}',1001,'INCREASE_DAY',100,UNIX_TIMESTAMP(),'pending')")
                pid=int(sql(f"SELECT id FROM pays WHERE hash_id='{ref}'"))
                assert php(mode,'manual_receipt',pay=pid,id=mid)['funding']=='funded'
                assert sql(f"SELECT COUNT(*) FROM payment_followup_intents WHERE service_operation_id={mid} AND stage='service_funded'")=='2'
                step(mode,mid,'progressed');step(mode,mid,'applied')
                # The service completion transaction cannot lose its report.
                before=finance();n=len(requests)
                sql("CREATE TRIGGER fixture_service_report_fail BEFORE INSERT ON payment_followup_intents FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture rollback'")
                try:
                    step(mode,mid,'pending')
                    assert mutation(mid).startswith('verified\t')
                    assert sql(f"SELECT COUNT(*) FROM payment_followup_intents WHERE service_operation_id={mid} AND stage<>'service_funded'")=='0'
                finally:sql('DROP TRIGGER fixture_service_report_fail')
                retry(mid);step(mode,mid,'completed')
                assert finance()==before and len(requests)==n
                row=sql(f"SELECT id,role,recipient,message_id FROM payment_followup_intents WHERE pay_row_id={pid} AND stage='wallet_credit' AND role='receipt_primary'").split('\t')
                assert row[1:]==['receipt_primary','9001','222'],row
                rid=int(row[0])
                # Changing a stored receipt target cannot re-route the sealed edit.
                sql(f"UPDATE payment_followup_attempts SET ack_message_id=223 WHERE intent_id IN (SELECT id FROM payment_followup_intents WHERE pay_row_id={pid} AND stage='receipt_admitted' AND recipient='9001') AND result='acknowledged'")
                assert php(mode,'followup',id=rid)=='unavailable'
                assert sql(f"SELECT COUNT(*) FROM fixture_followup WHERE intent_id={rid}")=='0'
                sql(f"UPDATE payment_followup_attempts SET ack_message_id=222 WHERE intent_id IN (SELECT id FROM payment_followup_intents WHERE pay_row_id={pid} AND stage='receipt_admitted' AND recipient='9001') AND result='acknowledged'")
                assert php(mode,'followup',id=rid)=='acknowledged'
                assert sql(f"SELECT COUNT(*) FROM fixture_followup WHERE intent_id={rid} AND mode='edit:222'")=='1'
                assert finance()==before and len(requests)==n
                cases.append(mode+'_manual_service_completion_rollback_and_exact_receipt_edit')
                did=int(sql(f"SELECT id FROM payment_delivery_intents WHERE service_operation_id={mid}"))
                assert php(mode,'deliver',id=did)=='acknowledged'
                wire=sql(f"SELECT caption,mode FROM fixture_telegram WHERE intent_id={did}")
                assert '<code>'+sql(f"SELECT remark FROM orders_list WHERE id={order}")+'</code>' in wire and wire.endswith('	HTML'),wire
                assert finance()==before and len(requests)==n
                cases.append(mode+'_manual_edit_original_named_customer_copy')
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
                assert php(mode,'deliver',id=did,responses={1:dict(ok=False,error_code=400,description='Bad Request: invalid request')})=='rejected'
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
                did=buy_delivery('temporary-forbidden')
                before=finance();n=len(requests)
                assert php(mode,'deliver',id=did,responses={1:dict(ok=False,error_code=403,description='Forbidden')})=='pending'
                assert part_states(did)==['pending','acknowledged'] and wire_counts(did)=={1:1,2:1}
                assert php(mode,'deliver',id=did)=='pending' and wire_counts(did)=={1:1,2:1}
                due_part(did,1)
                assert php(mode,'deliver',id=did)=='acknowledged'
                assert wire_counts(did)=={1:2,2:1} and finance()==before and len(requests)==n
                cases.append(mode+'_part_temporary_rejection_auto_retries_without_replaying_siblings')
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
                # Human approval of a real multi-order purchase. The
                # original operator may differ from both owner and worker.
                menu_state=dict(agencyState='on',sellState='off',testAccount='on',searchState='off',
                    helpChannel='@fixture_help')
                sql("UPDATE users SET isAdmin=1,is_agent=1,freetrial='not_used' WHERE userid=9002")
                sql("INSERT INTO setting(type,value) VALUES ('ADMIN_ROLE_9002','operator')")
                op=issue(mode,'manual-delivered-bulk',2)
                ref=mode+'-manual-delivered-bulk'
                sql(f"INSERT INTO pays(hash_id,user_id,type,price,request_date,state,agent_count) VALUES ('{ref}',1001,'BUY_SUB',100,UNIX_TIMESTAMP(),'pending',2)")
                pid=int(sql(f"SELECT id FROM pays WHERE hash_id='{ref}'"))
                assert php(mode,'manual_receipt',pay=pid,id=op,actor=9002)['funding']=='funded'
                for fid in sql(f"SELECT id FROM payment_followup_intents WHERE service_operation_id={op} AND stage='service_funded' ORDER BY id").splitlines():
                    assert php(mode,'followup',id=int(fid))=='acknowledged'
                step(mode,op,'applied');step(mode,op,'progressed');step(mode,op,'applied')
                before=finance();n=posts()
                sql("""DELIMITER //
CREATE TRIGGER fixture_manual_delivery_notice_fail BEFORE INSERT ON payment_followup_intents FOR EACH ROW
                    BEGIN IF NEW.stage='service_delivered' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture delivered report rollback'; END IF; END//
DELIMITER ;
""")
                try:
                    step(mode,op,'pending')
                    assert sql(f"SELECT COUNT(*) FROM payment_followup_intents WHERE service_operation_id={op} AND stage<>'service_funded'")=='0'
                    assert sql(f"SELECT COUNT(*) FROM payment_delivery_intents WHERE service_operation_id={op}")=='1'
                finally:sql('DROP TRIGGER fixture_manual_delivery_notice_fail')
                retry(op)
                assert php(mode,'step',id=op,menu_state=menu_state)['status']=='completed'
                assert finance()==before and posts()==n
                notices=dict((role,int(rid)) for role,rid in (row.split('	') for row in sql(
                    f"SELECT role,id FROM payment_followup_intents WHERE service_operation_id={op} AND stage='service_delivered'").splitlines()))
                assert set(notices)=={'report_actor','service_admin','service_channel'},notices
                operator=notices['report_actor']
                edit_id=int(sql(f"SELECT id FROM payment_followup_intents WHERE pay_row_id={pid} AND stage='wallet_credit' AND role='receipt_primary'"))
                dids=list(map(int,sql(f"SELECT id FROM payment_delivery_intents WHERE service_operation_id={op} ORDER BY ordinal").splitlines()))
                assert len(dids)==2
                before=finance();n=len(requests)
                # Awaiting delivery must not enter the bounded due queue or
                # prevent the independent terminal receipt edit.
                sql("UPDATE payment_followup_intents SET next_attempt_at=DATE_ADD(NOW(6),INTERVAL 1 DAY) WHERE state='pending'")
                sql(f"UPDATE payment_followup_intents SET next_attempt_at=NULL,updated_at=DATE_SUB(NOW(),INTERVAL 4 MINUTE) WHERE (service_operation_id={op} AND stage IN ('service_delivered','service_completed')) OR id={edit_id}")
                assert php(mode,'followup',id=operator)=='not_ready'
                assert php(mode,'maintain_followups',limit=1)=={str(edit_id):'acknowledged'}
                completion_ids=sql(f"SELECT id FROM payment_followup_intents WHERE service_operation_id={op} AND stage='service_completed' AND kind='send' ORDER BY id").splitlines()
                assert len(completion_ids)==3,completion_ids
                for cid in completion_ids:
                    assert php(mode,'maintain_followups',limit=1)=={cid:'acknowledged'}
                assert php(mode,'maintain_followups',limit=1)==[]
                assert sql(f"SELECT SUM(attempt_count) FROM payment_followup_intents WHERE service_operation_id={op} AND stage='service_delivered'")=='0'
                assert php(mode,'deliver',id=dids[0])=='acknowledged'
                assert php(mode,'deliver',id=dids[1],responses={2:None})=='unknown'
                assert php(mode,'followup',id=operator)=='not_ready'
                assert php(mode,'maintain_followups',limit=1)==[]
                # Neither a forged parent ACK nor a forged child projection
                # can replace the original per-message attempt evidence.
                sql(f"UPDATE payment_delivery_intents SET state='acknowledged',acknowledged_at=NOW(),last_result='acknowledged' WHERE id={dids[1]}")
                assert php(mode,'followup',id=operator)=='unavailable'
                sql(f"UPDATE payment_delivery_parts SET state='acknowledged' WHERE intent_id={dids[1]} AND part_no=2")
                assert php(mode,'followup',id=operator)=='unavailable'
                sql(f"UPDATE payment_delivery_parts SET state='unknown' WHERE intent_id={dids[1]} AND part_no=2; UPDATE payment_delivery_intents SET state='unknown',acknowledged_at=NULL,last_result='unknown' WHERE id={dids[1]}")
                token=php(mode,'preview_delivery',id=dids[1],part=2,actor=9001)
                assert php(mode,'deliver',id=dids[1],part=2,actor=9001,confirmation=token)=='acknowledged'
                assert wire_counts(dids[1])=={1:1,2:2}
                # The original approving operator remains eligible here to
                # verify their actual menu. Changed channel is terminal and
                # never rerouted; revoked staff are exercised by the outbox suite.
                assert php(mode,'followup',id=notices['service_channel'],menu_state={'rewardChannel':'@different_fixture'})=='skipped'
                assert sql(f"SELECT state,attempt_count,last_result FROM payment_followup_intents WHERE id={notices['service_channel']}")=='skipped\t0\trecipient_ineligible'
                with ThreadPoolExecutor(max_workers=3) as ex:
                    results=list(ex.map(lambda _:php(mode,'followup',id=operator),range(3)))
                assert 'acknowledged' in results and set(results)<={'acknowledged','busy'},results
                wire=sql(f"SELECT recipient,keys_json,mode FROM fixture_followup WHERE intent_id={operator}").split('	')
                assert wire[0]=='9002' and wire[2].startswith('edit_text:'),wire
                callbacks=[b.get('callback_data') for row in json.loads(wire[1])['inline_keyboard'] for b in row]
                assert {'agencySettings','increaseMyWallet','getTestAccount','agentConfigsList','managePanel','searchUsersConfig'}<=set(callbacks),callbacks
                # Closed sales applies to the non-primary operator's ordinary
                # purchase navigation, not to their authorized staff work.
                assert not {'agentOneBuy','agentMuchBuy','buySubscription','mySubscriptions','inviteFriends'} & set(callbacks),callbacks
                text=sql(f"SELECT text FROM fixture_followup WHERE intent_id={operator}")
                for original in rows(op):assert original.split('	')[2] in text
                assert ref in text
                out=php(mode,'maintain_followups',limit=4)
                assert out=={str(notices['service_admin']):'acknowledged'},out
                assert php(mode,'followup',id=notices['service_channel'])=='skipped'
                assert php(mode,'followup',id=operator)=='acknowledged'
                assert sql(f"SELECT COUNT(*) FROM fixture_followup WHERE intent_id IN ({','.join(map(str,notices.values()))})")=='2'
                assert sql(f"SELECT COUNT(*) FROM fixture_followup WHERE intent_id IN ({notices['service_admin']},{notices['service_channel']}) AND keys_json IS NULL")=='1'
                assert finance()==before and len(requests)==n
                cases.append(mode+'_manual_bulk_delivered_requires_all_real_parts_actor_context_and_fair_queue')
                # Renewal operator copy claims local renewal, not Telegram
                # delivery. It must remain independent of the customer ACK.
                ord_id=int(sql(f"SELECT order_id FROM payment_delivery_intents WHERE id={dids[0]}"))
                eid=edit(mode,'manual-renew-notice',ord_id,'RENEW',days=30,volume=2)
                ref=mode+'-manual-renew-notice'
                sql(f"INSERT INTO pays(hash_id,user_id,type,price,request_date,state) VALUES ('{ref}',1001,'RENEW_ACCOUNT',100,UNIX_TIMESTAMP(),'pending')")
                pid=int(sql(f"SELECT id FROM pays WHERE hash_id='{ref}'"))
                assert php(mode,'manual_receipt',pay=pid,id=eid,actor=9002)['funding']=='funded'
                for stage in ['progressed','progressed','applied','completed']:step(mode,eid,stage)
                did=int(sql(f"SELECT id FROM payment_delivery_intents WHERE service_operation_id={eid}"))
                rid=int(sql(f"SELECT id FROM payment_followup_intents WHERE service_operation_id={eid} AND stage='service_completed' AND role='report_actor'"))
                assert sql(f"SELECT COUNT(*) FROM payment_followup_intents WHERE service_operation_id={eid} AND stage='service_completed' AND kind='send' AND recipient='9002'")=='1'
                before=finance();n=len(requests)
                assert php(mode,'followup',id=rid)=='acknowledged'
                assert sql(f"SELECT COUNT(*) FROM fixture_followup WHERE intent_id={rid} AND keys_json IS NOT NULL")=='1'
                assert sql(f"SELECT state FROM payment_delivery_intents WHERE id={did}")=='pending'
                assert php(mode,'deliver',id=did)=='acknowledged'
                assert sql(f"SELECT mode FROM fixture_telegram WHERE intent_id={did}")=='HTML'
                assert sql(f"SELECT COUNT(*) FROM fixture_followup WHERE intent_id={rid} AND recipient='9002'")=='1'
                assert finance()==before and len(requests)==n
                cases.append(mode+'_manual_renewal_operator_independent_of_delivery_ack')
                sql("UPDATE users SET isAdmin=0,is_agent=0,freetrial=NULL WHERE userid=9002; DELETE FROM setting WHERE type='ADMIN_ROLE_9002'")
                # Actual persisted purchase draft -> Wallet command and manual
                # receipt entry points. Old synthetic commands remain isolated.
                sql("""DELETE FROM setting WHERE type='BOT_STATES'; INSERT INTO setting(type,value) VALUES ('BOT_STATES','{"walletState":"on","cartToCartState":"on","renewAutoState":"on","sellState":"on","renewAccountState":"on","increaseTimeState":"on","increaseVolumeState":"on","plandelkhahState":"on","gbPrice":10,"dayPrice":20}')""")
                sql("UPDATE users SET wallet=50000 WHERE userid=1001; UPDATE server_info SET ucount=100 WHERE id=2")
                def draft(suffix,price=100,typ='BUY_SUB'):
                    h=mode+'-checkout-'+suffix
                    sql(f"INSERT INTO pays(hash_id,user_id,type,price,request_date,state,server_id,plan_id,day,volume,agent_count,description) VALUES ('{h}',1001,'{typ}',{price},UNIX_TIMESTAMP(),'pending',2,7,0,0,1,'fixture')")
                    pid=int(sql(f"SELECT id FROM pays WHERE hash_id='{h}'"))
                    assert php(mode,'checkout_pin',pay=pid)
                    return h,pid
                h,pid=draft('changed-entitlement')
                prior=finance();before=len(requests)
                sql("UPDATE server_plans SET days=31 WHERE id=7")
                php(mode,'checkout_wallet',hash=h,expect_error='service_plan_units_changed')
                assert finance()==prior and len(requests)==before
                assert sql(f"SELECT checkout_operation_id IS NULL FROM pays WHERE id={pid}")=='1'
                sql("UPDATE server_plans SET days=30 WHERE id=7")
                cases.append(mode+'_displayed_plan_cannot_be_silently_requoted')
                code=mode+'-discount'
                sql(f"INSERT INTO discounts(hash_id,type,amount,expire_date,expire_count,can_use) VALUES ('{code}','percent',20,0,2,1)")
                h,pid=draft('discount')
                assert not php(mode,'checkout_discount',pay=pid,code=code,actor=9002)['success']
                assert sql(f"SELECT expire_count FROM discounts WHERE hash_id='{code}'")=='2'
                sql("CREATE TRIGGER fixture_discount_fail BEFORE UPDATE ON pays FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture_discount_failure'")
                try:
                    assert not php(mode,'checkout_discount',pay=pid,code=code)['success']
                    assert sql(f"SELECT expire_count FROM discounts WHERE hash_id='{code}'")=='2'
                    assert sql(f"SELECT price FROM pays WHERE id={pid}")=='100'
                finally:
                    sql("DROP TRIGGER fixture_discount_fail")
                r=php(mode,'checkout_discount',pay=pid,code=code)
                assert r['success'] and r['price']==80,r
                assert sql(f"SELECT price FROM pays WHERE id={pid}")=='80'
                assert sql(f"SELECT expire_count FROM discounts WHERE hash_id='{code}'")=='1'
                assert not php(mode,'checkout_discount',pay=pid,code=code)['success']
                assert sql(f"SELECT expire_count FROM discounts WHERE hash_id='{code}'")=='1'
                r=php(mode,'checkout_wallet',hash=h)
                assert r['status']=='funded',r
                for stage in ('applied','completed'):step(mode,r['operation'],stage)
                cases.append(mode+'_discount_counter_and_quote_atomic_and_owner_bound')
                sales_before=php(mode,'sales_snapshot')
                h,pid=draft('wallet')
                before=len(requests)
                assert php(mode,'checkout_wallet',hash=h,actor=9002,expect_error='checkout_draft_unavailable')
                r=php(mode,'checkout_wallet',hash=h)
                assert r['status']=='funded',r
                op=r['operation']
                assert len(requests)==before
                assert sql(f"SELECT state,checkout_operation_id FROM pays WHERE id={pid}")==f'paid_with_wallet\t{op}'
                assert php(mode,'checkout_wallet',hash=h)['status']=='already_funded'
                assert sql(f"SELECT COUNT(*) FROM wallet_entries WHERE source_kind='service_debit' AND source_ref='{op}'")=='1'
                for stage in ('applied','completed'):step(mode,op,stage)
                wallet_sales=dict(sales_before)
                wallet_sales['n']+=1;wallet_sales['total']+=100
                wallet_sales['wallet_count']+=1;wallet_sales['wallet_total']+=100
                assert php(mode,'sales_snapshot')==wallet_sales
                cases.append(mode+'_real_wallet_draft_admission_and_execution')
                h,pid=draft('low',1000000)
                assert php(mode,'checkout_wallet',hash=h)['status']=='insufficient_funds'
                assert sql(f"SELECT checkout_operation_id IS NULL FROM pays WHERE id={pid}")=='1'
                assert php(mode,'checkout_manual',pay=pid,submit=True)
                assert php(mode,'checkout_wallet',hash=h,expect_error='checkout_original_attempt_conflict')
                r=php(mode,'checkout_approve',hash=h)
                assert r['funding']=='funded',r
                op=r['operation']
                assert php(mode,'checkout_approve',hash=h)['credit']['replayed']
                assert sql(f"SELECT COUNT(*) FROM wallet_entries WHERE source_kind='service_debit' AND source_ref='{op}'")=='1'
                assert sql(f"SELECT COUNT(*) FROM wallet_entries WHERE source_kind='payment_credit' AND source_ref='{pid}'")=='1'
                for stage in ('applied','completed'):step(mode,op,stage)
                cases.append(mode+'_declined_wallet_switches_to_manual_exactly_once')
                # Use the same transactional draft insert as the Telegram
                # checkout controller, not only a synthetic service quote.
                sql("UPDATE users SET wallet=50000 WHERE userid=1001; UPDATE server_info SET ucount=100 WHERE id=2")
                def insert_checkout(suffix,typ,**kw):
                    h=mode+'-ui-'+suffix
                    pid=php(mode,'checkout_insert',hash=h,type=typ,price=100,**kw)
                    assert pid>0,(h,typ,pid)
                    return h,pid
                # Exercise the actual persisted UI route with the same limit
                # as management, funding and the Native absolute expiry.
                max_days=(2147483647-int(time.time()))//86400
                long_days=max_days-1
                sql(f"UPDATE server_plans SET days={max_days+1} WHERE id=7")
                before=finance();request_count=len(requests)
                assert php(mode,'checkout_insert',hash=mode+'-ui-overflow',type='BUY_SUB',price=100)==0
                assert finance()==before and len(requests)==request_count
                sql(f"UPDATE server_plans SET days={long_days} WHERE id=7")
                lh,lpid=insert_checkout('long','BUY_SUB')
                lr=php(mode,'checkout_wallet',hash=lh)
                assert lr['status']=='funded',lr
                long_op=lr['operation']
                assert json.loads(sql(f'SELECT quote_payload FROM service_operations WHERE id={long_op}'))['request']['days']==long_days
                for stage in ('applied','completed'):step(mode,long_op,stage)
                long_order=int(sql(f"SELECT order_id FROM service_effects WHERE source_kind='service_operation' AND source_ref='{long_op}'"))
                sql(f"UPDATE orders_list SET notif=1,autoRenew='on' WHERE id={long_order}")
                native(long_order)['used_traffic']=native(long_order)['data_limit']
                ar=php(mode,'auto',order=long_order)
                assert ar['status']=='funded',ar
                assert json.loads(sql(f"SELECT quote_payload FROM service_operations WHERE id={ar['id']}"))['request']['days']==long_days
                for stage in ('progressed','applied','completed'):step(mode,ar['id'],stage)
                sql('UPDATE server_plans SET days=30 WHERE id=7')
                cases.append(mode+'_actual_long_checkout_and_auto_renew_storage_bound')
                h,pid=insert_checkout('custom','BUY_SUB',days=10,volume=3,description=mode+'-custom-checkout')
                r=php(mode,'checkout_wallet',hash=h)
                assert r['status']=='funded',r
                op=r['operation']
                # Wallet-funded service checkout has no external receipt.
                # Its own operation can complete without inventing a receipt.
                assert php(mode,'receipt_outcome',pay=pid)=='unknown'
                for stage in ('applied','completed'):step(mode,op,stage)
                assert php(mode,'receipt_outcome',pay=pid)=='unknown'
                assert sql(f"SELECT stage FROM service_operations WHERE id={op}")=='completed'
                assert php(mode,'receipt_outcome',pay=0)=='unknown'
                order=int(sql(f"SELECT order_id FROM service_effects WHERE source_kind='service_operation' AND source_ref='{op}'"))
                cases.append(mode+'_actual_custom_draft_and_review_completion_truth')
                # The real current receipt projection distinguishes credit,
                # original service finality and independently acknowledged delivery.
                rh,rpid=insert_checkout('receipt-outcome','BUY_SUB')
                assert php(mode,'checkout_manual',pay=rpid,submit=True)
                assert php(mode,'receipt_outcome',pay=rpid)=='unknown'
                sales_before=php(mode,'sales_snapshot')
                rr=php(mode,'checkout_approve',hash=rh)
                assert rr['funding']=='funded',rr
                rop=rr['operation']
                assert php(mode,'receipt_outcome',pay=rpid)=='service_pending'
                assert php(mode,'sales_snapshot')==sales_before
                for stage in ('applied','completed'):step(mode,rop,stage)
                assert php(mode,'receipt_outcome',pay=rpid)=='delivery_unconfirmed'
                did=int(sql(f"SELECT id FROM payment_delivery_intents WHERE service_operation_id={rop}"))
                # A corrupt parent projection without acknowledged parts is
                # not evidence of Telegram delivery. Restore fixture afterward.
                sql(f"UPDATE payment_delivery_intents SET state='acknowledged',acknowledged_at=NOW(6),last_result='acknowledged' WHERE id={did}")
                assert php(mode,'receipt_outcome',pay=rpid)=='unknown'
                sql(f"UPDATE payment_delivery_intents SET state='pending',acknowledged_at=NULL,last_result=NULL WHERE id={did}")
                assert php(mode,'deliver',id=did)=='acknowledged'
                assert php(mode,'receipt_outcome',pay=rpid)=='completed'
                assert php(mode,'checkout_approve',hash=rh)['credit']['replayed']
                external_sales=dict(sales_before)
                external_sales['n']+=1;external_sales['total']+=100
                assert php(mode,'sales_snapshot')==external_sales
                cases.append(mode+'_actual_external_sale_not_double_counted_as_wallet_or_delivery')
                cases.append(mode+'_receipt_projection_original_funding_service_and_delivery')
                rh,rpid=insert_checkout('receipt-resolution','BUY_SUB')
                assert php(mode,'checkout_manual',pay=rpid,submit=True)
                rr=php(mode,'checkout_approve',hash=rh)
                assert rr['funding']=='funded',rr
                rop=rr['operation']
                plan=php(mode,'resolution_preview',id=rop,decision='refunded')
                assert plan and php(mode,'resolve',id=rop,decision='refunded',token=plan['token'])['status']=='refunded'
                assert php(mode,'receipt_outcome',pay=rpid)=='resolved'
                assert php(mode,'checkout_approve',hash=rh)['credit']['replayed']
                assert php(mode,'receipt_outcome',pay=rpid)=='resolved'
                assert php(mode,'sales_snapshot')==external_sales
                cases.append(mode+'_actual_full_refund_not_service_sale')
                cases.append(mode+'_receipt_projection_resolved_original_never_reopened')
                h,pid=insert_checkout('bulk','BUY_SUB',quantity=2)
                r=php(mode,'checkout_wallet',hash=h)
                assert r['status']=='funded',r
                op=r['operation']
                for stage in ('applied','progressed','applied','completed'):step(mode,op,stage)
                assert sql(f"SELECT COUNT(*) FROM service_effects WHERE source_kind='service_operation' AND source_ref='{op}' AND state='local_recorded'")=='2'
                cases.append(mode+'_actual_bulk_checkout_draft')
                # The same real current checkout can keep one of three
                # originally allocated accounts and refund the exact rest.
                sales_before=php(mode,'sales_snapshot')
                ph,ppid=insert_checkout('sales-partial','BUY_SUB',quantity=3)
                pr=php(mode,'checkout_wallet',hash=ph);assert pr['status']=='funded',pr
                pop=pr['operation'];step(mode,pop,'applied');step(mode,pop,'progressed')
                faults.append('get_unavailable');step(mode,pop,'pending','pg_vendor_unavailable')
                assert php(mode,'sales_snapshot')==sales_before
                plan=php(mode,'resolution_preview',id=pop,decision='refunded')
                assert plan['refund']==66 and plan['retained']==1,plan
                assert php(mode,'resolve',id=pop,decision='refunded',token=plan['token'])['status']=='refunded'
                partial_sales=dict(sales_before)
                partial_sales['n']+=1;partial_sales['total']+=34
                partial_sales['wallet_count']+=1;partial_sales['wallet_total']+=34
                assert php(mode,'sales_snapshot')==partial_sales
                did=int(sql(f"SELECT id FROM payment_delivery_intents WHERE service_operation_id={pop}"))
                assert php(mode,'deliver',id=did)=='acknowledged'
                step(mode,pop,'completed')
                assert php(mode,'sales_snapshot')==partial_sales
                cases.append(mode+'_actual_partial_checkout_sale_uses_exact_original_refund')

                h,pid=insert_checkout('renew','RENEW_ACCOUNT',order=order)
                r=php(mode,'checkout_wallet',hash=h)
                assert r['status']=='funded',r
                op=r['operation']
                for stage in ('progressed','progressed','applied','completed'):step(mode,op,stage)
                cases.append(mode+'_actual_renew_checkout_draft')
                # A submitted current receipt is a financial claim, not
                # physical ownership. Supersede its original unpaid quote
                # on a verified plan change; preserve and credit late proof.
                h,pid=insert_checkout('plan-unpaid-receipt','RENEW_ACCOUNT',order=order)
                assert php(mode,'checkout_manual',pay=pid,submit=True)
                prior=int(sql(f"SELECT checkout_operation_id FROM pays WHERE id={pid}"))
                sql(f"INSERT INTO pays(hash_id,user_id,type,price,request_date,state,server_id,plan_id,order_id) VALUES ('{mode}-historical-bound',1001,'RENEW_ACCOUNT',100,UNIX_TIMESTAMP(),'processing',2,7,{order})")
                before=finance()
                markups=int(sql('SELECT COUNT(*) FROM fixture_markup'))
                r=php(mode,'plan',order=order,plan=8);assert r['success'],r
                assert int(sql('SELECT COUNT(*) FROM fixture_markup'))==markups+1
                assert sql('SELECT chat_id,message_id FROM fixture_markup ORDER BY id DESC LIMIT 1')=='9001\t222'
                markup=json.loads(sql('SELECT markup FROM fixture_markup ORDER BY id DESC LIMIT 1'))
                assert markup['inline_keyboard'][0][0]['callback_data']==f'attentionOpenCase_{pid}',markup
                assert sql(f"SELECT state FROM pays WHERE id={pid}")=='service_superseded'
                assert php(mode,'attention_receipt',pay=pid) is True
                assert sql(f"SELECT stage FROM service_operations WHERE id={prior}")=='cancelled'
                assert sql(f"SELECT state FROM pays WHERE hash_id='{mode}-historical-bound'")=='processing'
                r=php(mode,'plan',order=order,plan=7);assert r['success'],r
                assert finance()==before
                r=php(mode,'checkout_approve',hash=h)
                assert r['funding']=='request_invalidated',r
                assert sql(f"SELECT stage FROM service_operations WHERE id={prior}")=='cancelled'
                assert sql(f"SELECT COUNT(*) FROM wallet_entries WHERE source_kind='service_debit' AND source_ref='{prior}'")=='0'
                assert php(mode,'checkout_approve',hash=h)['credit']['replayed']
                cases.append(mode+'_physical_change_retires_current_draft_not_historical_receipt')
                for kind,table in [('day','increase_day'),('volume','increase_plan')]:
                    sql(f"INSERT INTO {table}(volume,price) VALUES (97,100) ON DUPLICATE KEY UPDATE price=100")
                    offer=int(sql(f"SELECT id FROM {table} WHERE volume=97 AND server_id IS NULL"))
                    typ=f"INCREASE_{kind.upper()}_{order}_{offer}"
                    h,pid=insert_checkout(kind,typ,order=order,days=97 if kind=='day' else 0,
                        volume=97 if kind=='volume' else 0,addon_kind=kind,offer=offer)
                    assert php(mode,'checkout_manual',pay=pid,submit=True)
                    r=php(mode,'checkout_approve',hash=h)
                    assert r['funding']=='funded',r
                    op=r['operation']
                    for stage in ('progressed','applied','completed'):step(mode,op,stage)
                    assert php(mode,'checkout_approve',hash=h)['credit']['replayed']
                    cases.append(mode+'_actual_scoped_'+kind+'_manual_checkout_draft')
                # Real Telegram callbacks through the current dispatch files,
                # not direct service-function-only evidence.
                def callback(suffix,typ,kind,**kw):
                    h,pid=insert_checkout(suffix,typ,**kw)
                    before=finance();wire=len(requests)
                    # Wrong customer and missing outbox key must fail without
                    # reserving the command, wallet or Native capacity.
                    php(mode,'telegram_wallet',callback='pw_'+h,sender=9002)
                    assert finance()==before and len(requests)==wire
                    php(mode,'telegram_wallet',callback='pw_'+h,missing_key=True)
                    assert finance()==before and len(requests)==wire
                    assert sql(f"SELECT checkout_operation_id IS NULL FROM pays WHERE id={pid}")=='1'
                    result=php(mode,'telegram_wallet',callback='pw_'+h)
                    assert result['projection'],(result,last_diagnostics)
                    assert sql(f"SELECT CONCAT(purpose,':',synced_digest IS NOT NULL) FROM payment_message_refs WHERE pay_row_id={pid} AND chat_id=1001")=='status:1'
                    op=int(sql(f"SELECT checkout_operation_id FROM pays WHERE id={pid}"))
                    assert sql(f"SELECT state FROM pays WHERE id={pid}")=='paid_with_wallet'
                    assert sql(f"SELECT stage FROM service_operations WHERE id={op}")=='completed'
                    assert sql(f"SELECT COUNT(*) FROM wallet_entries WHERE source_kind='service_debit' AND source_ref='{op}'")=='1'
                    after=finance();native=posts()
                    php(mode,'telegram_wallet',callback='pw_'+h)
                    assert finance()==after and posts()==native
                    return op
                op=callback('cb-buy','BUY_SUB','buy')
                order_cb=int(sql(f"SELECT order_id FROM service_effects WHERE source_kind='service_operation' AND source_ref='{op}'"))
                callback('cb-custom','BUY_SUB','custom',days=10,volume=3,description=mode+'-actual-custom')
                callback('cb-renew','RENEW_ACCOUNT','renew',order=order_cb)
                for kind,table in [('day','increase_day'),('volume','increase_plan')]:
                    offer=int(sql(f"SELECT id FROM {table} WHERE volume=97 AND server_id IS NULL"))
                    callback('cb-'+kind,f"INCREASE_{kind.upper()}_{order_cb}_{offer}",kind,order=order_cb,
                        days=97 if kind=='day' else 0,volume=97 if kind=='volume' else 0,addon_kind=kind,offer=offer)
                cases.append(mode+'_five_real_wallet_callbacks_owner_schema_and_replay_fences')
                # New insufficient funds leave the actual draft usable.
                h,pid=insert_checkout('cb-low','BUY_SUB')
                wallet=int(sql('SELECT wallet FROM users WHERE userid=1001'))
                sql('UPDATE users SET wallet=0 WHERE userid=1001')
                before=finance();wire=len(requests)
                php(mode,'telegram_wallet',callback='pw_'+h)
                assert finance()==before and len(requests)==wire
                assert sql(f"SELECT CONCAT(state,':',checkout_operation_id IS NULL) FROM pays WHERE id={pid}")=='pending:1'
                assert php(mode,'checkout_manual',pay=pid,submit=True)
                r=php(mode,'checkout_approve',hash=h)
                assert r['funding']=='funded' and php(mode,'checkout_approve',hash=h)['credit']['replayed']
                for stage in ('applied','completed'):step(mode,r['operation'],stage)
                sql(f'UPDATE users SET wallet={wallet} WHERE userid=1001')
                cases.append(mode+'_actual_low_wallet_callback_switch_to_manual')
                # Names that the current Native CREATE would reject are
                # rejected at the local admission boundary, before debit.
                for i,name in enumerate(['x','ab','a--b','a__b','a-_b','a'*101]):
                    h,pid=insert_checkout('bad-name-'+str(i),'BUY_SUB',days=10,volume=3,description=name)
                    before=finance();wire=len(requests)
                    php(mode,'telegram_wallet',callback='pw_'+h)
                    assert finance()==before and len(requests)==wire
                    assert sql(f"SELECT checkout_operation_id IS NULL FROM pays WHERE id={pid}")=='1'
                cases.append(mode+'_invalid_native_names_never_debit_or_allocate')
                # Retired external renewal and unsupported panel: reject
                # all current wallet families without making Native calls.
                sql(f"INSERT INTO pays(hash_id,user_id,type,price,request_date,state,server_id,plan_id) VALUES ('{mode}-retired',1001,'RENEW_SCONFIG',100,UNIX_TIMESTAMP(),'pending',2,7)")
                pid=int(sql(f"SELECT id FROM pays WHERE hash_id='{mode}-retired'"))
                before=finance();wire=len(requests)
                assert php(mode,'retired_callback',pay=pid)==dict(modes=[False]*6,provider=False,subject=None)
                php(mode,'telegram_wallet',callback='pw_'+mode+'-retired')
                assert finance()==before and len(requests)==wire
                assert sql(f"SELECT checkout_operation_id IS NULL FROM pays WHERE id={pid}")=='1'
                cases.append(mode+'_retired_external_renewal_and_provider_denied')
                h,pid=insert_checkout('reject','BUY_SUB')
                assert php(mode,'checkout_manual',pay=pid,submit=True)
                op=int(sql(f"SELECT checkout_operation_id FROM pays WHERE id={pid}"))
                prior=finance();before=len(requests)
                assert php(mode,'checkout_reject',pay=pid,hash=h,actor=9002,expect_error='manual_reject_stale')
                assert sql(f"SELECT state FROM pays WHERE id={pid}")=='have_sent'
                aid=int(sql(f"SELECT id FROM payment_attempts WHERE pay_row_id={pid}"))
                php(mode,'telegram_review',actor=9001,callback=f'gatewayReview_H_{aid}')
                assert sql(f"SELECT state FROM pays WHERE id={pid}")=='have_sent' and finance()==prior
                assert php(mode,'checkout_reject',pay=pid,hash=h)
                assert sql(f"SELECT CONCAT(state,':',approved_by) FROM pays WHERE id={pid}")=='declined:9001'
                assert sql(f"SELECT stage FROM service_operations WHERE id={op}")=='cancelled'
                assert sql(f"SELECT CONCAT(stage,':',provider_status) FROM payment_attempts WHERE pay_row_id={pid}")=='done:operator_rejected'
                assert php(mode,'checkout_approve',hash=h,expect_error='manual_receipt_original_unadmitted')
                assert php(mode,'checkout_reject',pay=pid,hash=h,expect_error='manual_reject_stale')
                rid=int(sql(f"SELECT id FROM payment_followup_intents WHERE pay_row_id={pid} AND stage='manual_reject'"))
                assert php(mode,'followup',id=rid,response=dict(ok=False,error_code=429,description='Too Many Requests',parameters=dict(retry_after=35)))=='pending'
                sql(f"UPDATE payment_followup_intents SET next_attempt_at=DATE_SUB(NOW(6),INTERVAL 1 SECOND) WHERE id={rid}")
                assert php(mode,'maintain_followups',limit=100).get(str(rid))=='acknowledged'
                assert sql(f"SELECT COUNT(*) FROM payment_followup_attempts WHERE intent_id={rid}")=='2'
                assert finance()==prior and len(requests)==before
                cases.append(mode+'_manual_rejection_cancels_original_and_recovers_notice_without_money')
                # Source-real operator confirmation and unknown delivery
                # recovery. Never construct an independent finance command.
                h,pid=insert_checkout('operator-redrive','BUY_SUB')
                php(mode,'telegram_wallet',callback='pw_'+h,unknown_ack=True)
                op=int(sql(f"SELECT checkout_operation_id FROM pays WHERE id={pid}"))
                assert sql(f"SELECT stage FROM service_operations WHERE id={op}")=='completed'
                d=int(sql(f"SELECT id FROM payment_delivery_intents WHERE service_operation_id={op}"))
                part=int(sql(f"SELECT part_no FROM payment_delivery_parts WHERE intent_id={d} AND state='unknown' LIMIT 1"))
                # Pending aggregate must not hide individual unknown parts.
                original=sql(f"SELECT state FROM payment_delivery_intents WHERE id={d}")
                original_followups=sql(f"SELECT CONCAT(id,':',state) FROM payment_followup_intents WHERE service_operation_id={op}").splitlines()
                sql(f"UPDATE payment_delivery_intents SET state='pending' WHERE id={d}")
                sql(f"UPDATE payment_followup_intents SET state='pending' WHERE service_operation_id={op} AND state IN ('unknown','rejected')")
                assert php(mode,'service_attention',id=op)
                for saved in original_followups:
                    fid,state=saved.split(':')
                    sql(f"UPDATE payment_followup_intents SET state='{state}' WHERE id={fid}")
                sql(f"UPDATE payment_delivery_intents SET state='{original}' WHERE id={d}")
                cases.append(mode+'_unknown_delivery_part_visible_even_with_pending_aggregate')

                before=finance();wire=len(requests)
                def review(cb,actor=9001,**kw):
                    return php(mode,'telegram_review',actor=actor,callback=cb,**kw)
                def confirmation(r,prefix):
                    assert len(r['review'])==1 and r['review'][0]['mode']=='HTML',r
                    return next(b['callback_data'] for row in r['review'][0]['keys']['inline_keyboard']
                                for b in row if b['callback_data'].startswith(prefix))
                cb=f'attentionDeliveryC_{d}_{part}'
                assert not review(cb,actor=1001)['review']
                assert not review(cb,chat=-100123)['review']
                confirm=confirmation(review(cb),'attentionDeliveryS_')
                count=int(sql(f"SELECT COUNT(*) FROM fixture_telegram WHERE intent_id={d} AND part_no={part}"))
                assert not review(confirm,actor=1001)['review']
                assert int(sql(f"SELECT COUNT(*) FROM fixture_telegram WHERE intent_id={d} AND part_no={part}"))==count
                review(confirm)
                review(confirm)
                assert int(sql(f"SELECT COUNT(*) FROM fixture_telegram WHERE intent_id={d} AND part_no={part}"))==count+1
                assert sql(f"SELECT state FROM payment_delivery_parts WHERE intent_id={d} AND part_no={part}")=='acknowledged'
                # The same attention case can recover an original frozen
                # customer/admin report, not merely a part of the subscription.
                rid=int(sql(f"SELECT id FROM payment_followup_intents WHERE service_operation_id={op} AND state='unknown' LIMIT 1"))
                cb=f'attentionReportC_{rid}'
                assert not review(cb,actor=1001)['review']
                confirm=confirmation(review(cb),'attentionReportS_')
                count=int(sql(f"SELECT COUNT(*) FROM fixture_followup WHERE intent_id={rid}"))
                review(confirm);review(confirm)
                assert int(sql(f"SELECT COUNT(*) FROM fixture_followup WHERE intent_id={rid}"))==count+1
                assert sql(f"SELECT state FROM payment_followup_intents WHERE id={rid}")=='acknowledged'
                assert finance()==before and len(requests)==wire
                cases.append(mode+'_actual_private_operator_delivery_and_report_confirmation_once')

                resolution_cases(mode)
                agent_referral_cases(mode)

                assert not faults and not errors,(faults,errors)
            if not (args.payment_actions_only or args.delivery_identity_only or args.report_revisions_only or args.plan_recovery_only or args.referral_agent_only or args.resolution_only or args.bulk_report_only or args.name_collision_only):
                # Original zero-price commercial checkout, through actual
                # reservation, Native execution, order, delivery and reports.
                for mode in ('strict','off'):
                    sql("""DELETE FROM setting WHERE type='BOT_STATES'; INSERT INTO setting(type,value) VALUES ('BOT_STATES','{"sellState":"on","agencyState":"on","walletState":"off"}')""")
                    sql("""UPDATE users SET is_agent=1,discount_percent='{"normal":100}',step='none' WHERE userid=9002; UPDATE users SET step='none' WHERE userid=9001; UPDATE server_info SET ucount=100,state=1,active=1 WHERE id=2; UPDATE server_plans SET price=100,active=1 WHERE id=7""")
                    for label,uid,qty,agent in [('ordinary-agent',9002,1,0),('agent-single',9002,1,1),('agent-bulk',9002,3,1),('primary',9001,1,0)]:
                        h=mode+'-zero-commercial-'+label
                        name='zerocommercial'+str(uid)+(('1' if mode=='strict' else '2'))
                        manual=qty==1
                        if label=='primary':name+='9'
                        if label=='ordinary-agent':name+='8'
                        before=sql(f"SELECT wallet,freetrial FROM users WHERE userid={uid}")
                        ledger=sql("SELECT COUNT(*) FROM wallet_entries")
                        capacity=int(sql("SELECT ucount FROM server_info WHERE id=2"))
                        pid=php(mode,'checkout_insert',hash=h,type='BUY_SUB',price=0,actor=uid,quantity=qty,agent_bought=agent,description=name if manual else '',naming_mode='manual' if manual else 'digits')
                        assert pid>0,(mode,label,pid)
                        r=php(mode,'checkout_wallet',hash=h,actor=uid);assert r['status']=='funded',r
                        op=r['operation'];n=posts()
                        for i in range(qty):
                            step(mode,op,'applied')
                            step(mode,op,'completed' if i==qty-1 else 'progressed')
                        assert posts()==n+qty
                        assert sql(f"SELECT COUNT(*) FROM service_effects e JOIN orders_list o ON o.id=e.order_id WHERE e.source_kind='service_operation' AND e.source_ref='{op}' AND e.state='local_recorded' AND o.userid={uid} AND o.amount=0")==str(qty)
                        if manual:
                            assert sql(f"SELECT remote_username FROM service_effects WHERE source_kind='service_operation' AND source_ref='{op}'")==name
                        assert sql(f"SELECT debit_entry_id IS NULL FROM service_operations WHERE id={op}")=='1'
                        assert sql(f"SELECT wallet,freetrial FROM users WHERE userid={uid}")==before
                        assert sql("SELECT COUNT(*) FROM wallet_entries")==ledger
                        assert int(sql("SELECT ucount FROM server_info WHERE id=2"))==capacity-qty
                        assert sql(f"SELECT COUNT(*) FROM payment_attempts WHERE pay_row_id={pid}")=='0'
                        deliveries=sql(f"SELECT id FROM payment_delivery_intents WHERE service_operation_id={op} ORDER BY id").splitlines()
                        assert len(deliveries)==qty
                        for did in deliveries:
                            assert php(mode,'deliver',id=int(did))=='acknowledged'
                        for role in ('service_admin','service_channel'):
                            ids=sql(f"SELECT id FROM payment_followup_intents WHERE service_operation_id={op} AND stage='service_completed' AND role='{role}'").splitlines()
                            assert len(ids)==1,(role,ids)
                            assert php(mode,'followup',id=int(ids[0]))=='acknowledged'
                        assert php(mode,'checkout_wallet',hash=h,actor=uid)['status']=='already_funded'
                        assert posts()==n+qty and sql("SELECT COUNT(*) FROM wallet_entries")==ledger
                        for linked in sql(f"SELECT order_id FROM service_effects WHERE source_kind='service_operation' AND source_ref='{op}'").splitlines():
                            assert php(mode,'commercial_plan',order=int(linked))==dict(origin=True,choose=True,paid=True)
                        if label=='ordinary-agent':
                            ordinary_zero=int(sql(f"SELECT order_id FROM service_effects WHERE source_kind='service_operation' AND source_ref='{op}'"))
                    cases.append(mode+'_zero_commercial_actual_checkout_native_delivery_reports')
                    # Real add-on checkout route uses the active representative
                    # entitlement regardless of the original order flag.
                    oid=int(sql("SELECT MAX(id) FROM orders_list WHERE userid=9002"))
                    sql("""UPDATE setting SET value='{"agencyState":"on","walletState":"on","cartToCartState":"on","sellState":"on","renewAccountState":"on","increaseTimeState":"on","increaseVolumeState":"on","plandelkhahState":"on","gbPrice":10,"dayPrice":20}' WHERE type='BOT_STATES'""")
                    sql("""UPDATE users SET is_agent=1,discount_percent='{"normal":25}',step='none' WHERE userid=9002""")
                    for flag in (0,1):
                        sql(f"UPDATE orders_list SET agent_bought={flag} WHERE id={oid}")
                        for table,prefix in [('increase_day','selectPlanDayIncrease'),('increase_plan','increaseVolumePlan')]:
                            sql(f"INSERT INTO {table}(volume,price) VALUES (97,100) ON DUPLICATE KEY UPDATE price=100")
                            offer=int(sql(f"SELECT id FROM {table} WHERE volume=97 AND server_id IS NULL"))
                            before=int(sql("SELECT COUNT(*) FROM pays"))
                            r=php(mode,'telegram_addon_quote',actor=9002,callback=f'{prefix}{oid}_{offer}')
                            assert r['messages'] and int(sql("SELECT COUNT(*) FROM pays"))==before+1,r
                            assert sql("SELECT price FROM pays WHERE user_id=9002 ORDER BY id DESC LIMIT 1")=='75',r
                    sql("UPDATE users SET is_agent=0 WHERE userid=9002")
                    before=sql("SELECT COUNT(*) FROM pays")
                    assert php(mode,'checkout_insert',hash=mode+'-stale-agency-price',type='BUY_SUB',price=75,actor=9002,
                               pricing_policy={'active':True,'percent':25},
                               expect_diagnostic={'event':'achbot.finance.draft_creation_deferred','actor_id':9002,
                                   'server_id':2,'reason':'draft_agency_pricing_changed','class':'DomainException'})==0
                    assert sql("SELECT COUNT(*) FROM pays")==before
                    # A real duplicate-key failure keeps the numeric SQL
                    # cause in strict and non-strict modes without the hash.
                    existing=mode+'-duplicate-diagnostic-fixture'
                    assert php(mode,'checkout_insert',hash=existing,type='BUY_SUB',price=100,actor=9002)>0
                    before=sql("SELECT COUNT(*) FROM pays")
                    assert php(mode,'checkout_insert',hash=existing,type='BUY_SUB',price=100,actor=9002,
                               expect_diagnostic={'event':'achbot.finance.draft_creation_deferred','actor_id':9002,
                                   'sql_errno':1062,'reason':'internal_database_exception' if mode=='strict' else 'draft_insert_failed'})==0
                    assert sql("SELECT COUNT(*) FROM pays")==before
                    for table,prefix in [('increase_day','selectPlanDayIncrease'),('increase_plan','increaseVolumePlan')]:
                        offer=int(sql(f"SELECT id FROM {table} WHERE volume=97 AND server_id IS NULL"))
                        r=php(mode,'telegram_addon_quote',actor=9002,callback=f'{prefix}{oid}_{offer}')
                        assert r['messages'] and sql("SELECT price FROM pays WHERE user_id=9002 ORDER BY id DESC LIMIT 1")=='100',r
                    cases.append(mode+'_actual_addon_current_agency_pricing_and_draft_guard')
                    # Source-real positive ADD_TIME/ADD_VOLUME with the
                    # owned PasarGuard peer; no finance or merchant effects.
                    saved_states=sql("SELECT value FROM setting WHERE type='BOT_STATES'")
                    saved_keys=sql("SELECT value FROM setting WHERE type='PAYMENT_KEYS'").splitlines()
                    assert len(saved_keys)<=1
                    sql("DELETE FROM setting WHERE type='PAYMENT_KEYS'; INSERT INTO setting(type,value) VALUES ('PAYMENT_KEYS','{}')")
                    base_keys={'bankAccount':'fixture-bank','holderName':'fixture-holder'}
                    methods=[
                        ('walletState','wallet',{}),
                        ('cartToCartState','bank_transfer',{}),
                        ('giftCardState','gift_card',{'giftCardUrl':'https://fixture.invalid/gift'}),
                        ('manualUsdtState','manual_usdt',{'manualUsdtWalletBEP20':'0x'+'a'*40,'manualUsdtRateToman':'1000'}),
                        ('manualUsdtState','manual_trx',{'manualTrxWallet':'T9yD14Nj9j7xAB4dbGeiX9h8unkKHxuWwb','manualTrxRateToman':'1000'}),
                        ('zarinpal','zarinpal',{'zarinpal':'fixture-merchant'}),
                        ('nextpay','nextpay',{'nextpay':'fixture-merchant'}),
                        ('nowPaymentOther','nowpayments',{'nowpayment':'fixture-merchant','manualUsdtRateToman':'1000'}),
                    ]
                    def addon_state(**flags):
                        state=dict(sellState='on',agencyState='on',increaseTimeState='on',increaseVolumeState='on',walletState='off')
                        state.update(flags)
                        sql("UPDATE setting SET value='"+json.dumps(state)+"' WHERE type='BOT_STATES'")
                    def addon_keys(keys):
                        sql("UPDATE setting SET value='"+json.dumps(keys)+"' WHERE type='PAYMENT_KEYS'")
                    def assert_addon_methods(expected,**configuration):
                        for table,route in [('increase_day','selectPlanDayIncrease'),('increase_plan','increaseVolumePlan')]:
                            offer=sql(f"SELECT id FROM {table} WHERE volume=97 AND server_id IS NULL")
                            before=finance()
                            count=int(sql("SELECT COUNT(*) FROM pays"))
                            attempts=sql("SELECT COUNT(*) FROM payment_attempts")
                            operations=sql("SELECT COUNT(*) FROM service_operations")
                            rr=php(mode,'telegram_addon_quote',actor=9002,callback=f'{route}{oid}_{offer}',**configuration)
                            assert rr['messages'] and int(sql("SELECT COUNT(*) FROM pays"))==count+1,rr
                            h,price=sql("SELECT hash_id,price FROM pays WHERE user_id=9002 ORDER BY id DESC LIMIT 1").split('\t')
                            assert price=='100',rr
                            assert 'مبلغ: 100 تومان' in rr['messages'][-1]['text'],rr
                            actual=set();cancel=False
                            for row in rr['messages'][-1]['keys']['inline_keyboard']:
                                for b in row:
                                    cb=b.get('callback_data','');url=b.get('url','')
                                    if cb=='dc_'+h:cancel=True
                                    elif cb=='pw_'+h:actual.add('wallet')
                                    elif cb=='pm_b_'+h:actual.add('bank_transfer')
                                    elif cb=='pm_g_'+h:actual.add('gift_card')
                                    elif cb=='mcm_'+h:actual.add('crypto')
                                    else:
                                        urls={f'https://bot.fixture.invalid/pay/checkout.php?{flag}&hash_id={h}':m for flag,m in [('nowpayment','nowpayments'),('zarinpal','zarinpal'),('nextpay','nextpay')]}
                                        assert url in urls,(b,rr)
                                        actual.add(urls[url])
                            assert cancel and actual==expected,(mode,route,expected,actual,rr)
                            assert sql(f"SELECT COUNT(*) FROM payment_message_refs r JOIN pays p ON p.id=r.pay_row_id WHERE p.hash_id='{h}' AND r.chat_id=9002 AND r.purpose='selector' AND r.retired_reason IS NULL")=='1'
                            assert finance()==before
                            assert sql("SELECT COUNT(*) FROM payment_attempts")==attempts and sql("SELECT COUNT(*) FROM service_operations")==operations
                    for flag,method,keys in methods:
                        addon_keys(base_keys|keys)
                        addon_state(**{flag:'on'})
                        assert_addon_methods({'crypto' if method in ('manual_usdt','manual_trx') else method})
                    addon_state(nowPaymentWallet='on')
                    assert_addon_methods(set())
                    addon_state(zarinpal='on',nextpay='on',nowPaymentOther='on')
                    addon_keys(base_keys)
                    assert_addon_methods(set(),stale_keys={'zarinpal':'stale','nextpay':'stale','nowpayment':'stale','manualUsdtRateToman':'1000'})
                    addon_state(nowPaymentOther='on')
                    addon_keys(base_keys|methods[-1][2])
                    assert_addon_methods(set(),ipn_secret='')
                    assert_addon_methods(set(),settlement_policy={'outcome_currency':'usdttrc20','minimum_outcome_per_usd':'0'})
                    assert_addon_methods({'nowpayments'},settlement_policy={'outcome_currency':'usdttrc20','minimum_outcome_per_usd':'0.8'})
                    addon_keys(base_keys|{'nowpayment':'fixture-merchant','manualUsdtRateToman':'1000000000'})
                    assert_addon_methods(set())
                    for index,denied,allowed in [(4,'manual_trx','manual_usdt'),(3,'manual_usdt','manual_trx')]:
                        addon_keys(base_keys|methods[index][2])
                        addon_state(manualUsdtState='on',gatewayAudience={denied:'off',allowed:'all'})
                        assert_addon_methods(set())
                    sql("UPDATE setting SET value='"+saved_states+"' WHERE type='BOT_STATES'; DELETE FROM setting WHERE type='PAYMENT_KEYS'")
                    if saved_keys:
                        addon_keys_value=saved_keys[0].replace("'","''")
                        sql("INSERT INTO setting(type,value) VALUES ('PAYMENT_KEYS','"+addon_keys_value+"')")
                    cases.append(mode+'_positive_addons_shared_configured_method_eligibility')
                    # A zero effective ordinary-mode BUY remains eligible after
                    # representative demotion, without rewriting its history.
                    assert php(mode,'commercial_plan',order=ordinary_zero)==dict(origin=True,choose=True,paid=True)
                    r=php(mode,'telegram_addon_quote',actor=9002,callback=f'renewAccount{ordinary_zero}')
                    assert r['messages'] and sql("SELECT price FROM pays WHERE user_id=9002 ORDER BY id DESC LIMIT 1")=='100',r
                    assert sql(f"SELECT amount,agent_bought FROM orders_list WHERE id={ordinary_zero}")=='0\t0'
                    cases.append(mode+'_ordinary_zero_commercial_origin_survives_demotion')
                    # A full discount-code purchase and a bulk order with
                    # individual zero allocation are real commercial origins.
                    # Discount-code zero and partial allocations retain
                    # the same durable commercial provenance.
                    for label,quantity,initial,discount,expected in (
                        ('discount-full',1,100,100,[0]),
                        ('discount-bulk',3,300,299,[1,0,0]),
                    ):
                        h=mode+'-'+label;code=h+'-code'
                        sql(f"INSERT INTO discounts(hash_id,type,amount,expire_date,expire_count,can_use) VALUES ('{code}','amount',{discount},0,1,1)")
                        actor=1001 if quantity==1 else 9002
                        if quantity==3: sql("""UPDATE users SET is_agent=1,discount_percent='{"normal":0}' WHERE userid=9002""")
                        else: sql("UPDATE users SET is_agent=0 WHERE userid=1001")
                        pid=php(mode,'checkout_insert',hash=h,type='BUY_SUB',price=initial,actor=actor,quantity=quantity,agent_bought=int(quantity>1))
                        assert pid>0
                        r=php(mode,'checkout_discount',pay=pid,code=code,actor=actor)
                        assert r['success'] and r['price']==sum(expected),r
                        before_wallet=int(sql(f"SELECT wallet FROM users WHERE userid={actor}"))
                        op=php(mode,'checkout_wallet',hash=h,actor=actor)['operation']
                        for j in range(quantity):
                            step(mode,op,'applied')
                            step(mode,op,'completed' if j==quantity-1 else 'progressed')
                        assert int(sql(f"SELECT wallet FROM users WHERE userid={actor}"))==before_wallet-sum(expected)
                        allocated_rows=sql(f"SELECT o.id,o.amount FROM orders_list o JOIN service_effects e ON e.order_id=o.id WHERE e.source_kind='service_operation' AND e.source_ref='{op}' ORDER BY e.ordinal").splitlines()
                        assert [int(row.split('\t')[1]) for row in allocated_rows]==expected,allocated_rows
                        for row in allocated_rows:
                            assert php(mode,'commercial_plan',order=int(row.split('\t')[0]))==dict(origin=True,choose=True,paid=True)
                        if quantity==1: discounted_order=int(allocated_rows[0].split('\t')[0])
                    cases.append(mode+'_discounted_and_zero_allocated_bulk_commercial_origin')
                    # A draft is not an issued payment. Revoking any manual
                    # operation policy before method admission must not debit.
                    for table,prefix,flag,kind in [('increase_day','selectPlanDayIncrease','increaseTimeState','day'),('increase_plan','increaseVolumePlan','increaseVolumeState','volume')]:
                        offer=int(sql(f"SELECT id FROM {table} WHERE volume=97 AND server_id IS NULL"))
                        r=php(mode,'telegram_addon_quote',actor=9002,callback=f'{prefix}{ordinary_zero}_{offer}')
                        assert r['messages'],r
                        h,pid=sql("SELECT hash_id,id FROM pays WHERE user_id=9002 ORDER BY id DESC LIMIT 1").split('\t')
                        saved=sql("SELECT value FROM setting WHERE type='BOT_STATES'")
                        sql("UPDATE setting SET value=JSON_SET(value,'$."+flag+"','off') WHERE type='BOT_STATES'")
                        before=finance();requests_before=len(requests)
                        php(mode,'checkout_wallet',hash=h,actor=9002,expect_error='checkout_managed_service_unavailable')
                        assert finance()==before and len(requests)==requests_before
                        assert sql(f"SELECT checkout_operation_id IS NULL FROM pays WHERE id={pid}")=='1'
                        sql("UPDATE setting SET value='"+saved+"' WHERE type='BOT_STATES'")
                    cases.append(mode+'_managed_addon_policy_revocation_blocks_new_funding')
                    # Actual zero-value managed routes with all monetary
                    # methods disabled; one button plus existing cancel.
                    sql("""UPDATE users SET is_agent=1,discount_percent='{"normal":100}' WHERE userid=9002""")
                    sql("""UPDATE setting SET value='{"sellState":"on","agencyState":"on","renewAccountState":"on","increaseTimeState":"on","increaseVolumeState":"on","walletState":"off"}' WHERE type='BOT_STATES'""")
                    for kind,callback,label in [
                        ('renew',f'renewAccount{ordinary_zero}','✅ تمدید رایگان'),
                        ('day',f'selectPlanDayIncrease{ordinary_zero}_'+sql("SELECT id FROM increase_day WHERE volume=97 AND server_id IS NULL"),'✅ افزایش زمان رایگان'),
                        ('volume',f'increaseVolumePlan{ordinary_zero}_'+sql("SELECT id FROM increase_plan WHERE volume=97 AND server_id IS NULL"),'✅ افزایش حجم رایگان'),
                    ]:
                        r=php(mode,'telegram_addon_quote',actor=9002,callback=callback)
                        assert r['messages'],r
                        h,pid,price=sql("SELECT hash_id,id,price FROM pays WHERE user_id=9002 ORDER BY id DESC LIMIT 1").split('\t')
                        assert price=='0'
                        buttons=[b for row in r['messages'][-1]['keys']['inline_keyboard'] for b in row]
                        assert buttons[0]==dict(text=label,callback_data='pw_'+h),buttons
                        assert len(buttons)==2 and buttons[1]['callback_data']=='dc_'+h,buttons
                        assert r['messages'][-1]['text']=='🧾 مبلغ نهایی این سفارش صفر تومان است. برای انجام عملیات، تأیید کنید.',r
                        before=finance();request_count=len(requests)
                        # Current agency price must still authorize a raw
                        # zero; loss of agency discount is not a free grant.
                        sql("""UPDATE users SET discount_percent='{"normal":50}' WHERE userid=9002""")
                        php(mode,'checkout_wallet',hash=h,actor=9002,expect_error='checkout_zero_managed_changed')
                        assert finance()==before and len(requests)==request_count
                        sql("""UPDATE users SET discount_percent='{"normal":100}' WHERE userid=9002""")
                        # External zero attempt cannot be created even if a
                        # bank method is later enabled.
                        sql("UPDATE setting SET value=JSON_SET(value,'$.cartToCartState','on') WHERE type='BOT_STATES'")
                        assert not php(mode,'checkout_manual',pay=int(pid),actor=9002)
                        assert sql(f"SELECT COUNT(*) FROM payment_attempts WHERE pay_row_id={pid}")=='0'
                        sql("UPDATE setting SET value=JSON_SET(value,'$.cartToCartState','off') WHERE type='BOT_STATES'")
                        result=php(mode,'checkout_wallet',hash=h,actor=9002)
                        assert result['status']=='funded' and finance()==before,result
                        assert php(mode,'checkout_wallet',hash=h,actor=9002)['status']=='already_funded'
                        assert sql(f"SELECT debit_entry_id IS NULL FROM service_operations WHERE id={result['operation']}")=='1'
                        assert sql(f"SELECT COUNT(*) FROM payment_attempts WHERE pay_row_id={pid}")=='0'
                        for stage in (('progressed','progressed','applied','completed') if kind=='renew' else ('progressed','applied','completed')):step(mode,result['operation'],stage)
                    cases.append(mode+'_zero_managed_free_confirmation_no_monetary_methods')

                    # Restore original fixture pricing for the existing auto
                    # renewal and receipt checks below.
                    sql("""UPDATE users SET is_agent=0,discount_percent='{"normal":25}' WHERE userid=9002""")


                    sql("UPDATE setting SET value='"+json.dumps({'renewAutoState':'on','agencyState':'on','walletState':'on','cartToCartState':'on','sellState':'on','renewAccountState':'on','increaseTimeState':'on','increaseVolumeState':'on'})+"' WHERE type='BOT_STATES'")
                    sql("UPDATE users SET is_agent=1 WHERE userid=9002")
                    sql(f"UPDATE orders_list SET notif=1,autoRenew='on',agent_bought=0 WHERE id={oid}")
                    native(oid)['used_traffic']=native(oid)['data_limit']
                    r=php(mode,'auto',order=oid,owner=9002,bot_state={'renewAutoState':'on','agencyState':'off'})
                    assert r['status']=='funded',r
                    aid=r['id']
                    assert sql(f"SELECT price_toman FROM service_operations WHERE id={aid}")=='75',r
                    for stage in ('progressed','applied','completed'):step(mode,aid,stage)
                    cases.append(mode+'_actual_auto_renew_current_agency_policy')
                    sql("UPDATE users SET is_agent=0 WHERE userid=9002")
                    pid=php(mode,'checkout_insert',hash=mode+'-receipt-labels',type='BUY_SUB',price=100)
                    assert pid>0 and php(mode,'checkout_manual',pay=pid,submit=False)
                    quote=php(mode,'original_receipt_quote',pay=pid,mode='standard')
                    assert quote and quote['days']==30 and quote['price']==100,quote
                    original=sql("SELECT title FROM server_plans WHERE id=7")
                    sql("UPDATE server_plans SET title='Changed after issue',days=31,price=200 WHERE id=7")
                    assert php(mode,'original_receipt_quote',pay=pid,mode='standard')==quote
                    sql("UPDATE server_plans SET title='"+original.replace("'","''")+"',days=30,price=100 WHERE id=7")
                    cases.append(mode+'_manual_receipt_original_frozen_quote')

                    assert not faults and not errors,(faults,errors)
                sql("UPDATE users SET is_agent=0,discount_percent=NULL WHERE userid=9002")
            if not (args.payment_actions_only or args.delivery_identity_only or args.report_revisions_only or args.plan_recovery_only or args.referral_agent_only or args.resolution_only or args.bulk_report_only or args.zero_commercial_only):
                # Two independent claims on the same physical panel must not
                # strand a valid generated-name purchase. Cover single,
                # bulk, explicit-name refusal and uncertain-write replay.
                for mode in ('strict','off'):
                    sql("UPDATE users SET wallet=50000 WHERE userid=1001; UPDATE server_info SET ucount=100,state=1,active=1 WHERE id IN (2,3)")
                    def named(ref,name,quantity=1,explicit=False,server=2,plan=7,owner=1001,**kw):
                        return php(mode,'issue',owner=owner,ref=mode+'-'+ref,**kw,quote=dict(kind='BUY',variant='standard',
                            price=100,server_id=server,plan_id=plan,order_id=0,quantity=quantity,days=30,volume=2,
                            remark=name,uuid='',naming_mode='manual' if explicit else 'digits',
                            explicit_name=explicit,policy={}))
                    base=mode+'-collision-'
                    first=named('collision-original',base+'99999')
                    exact=named('collision-explicit-late',base+'99999',explicit=True)
                    unfunded=named('collision-explicit-unfunded',base+'99999',explicit=True)
                    fund(mode,first);fund(mode,exact)
                    step(mode,first,'applied');step(mode,first,'completed')
                    before=finance();n=len(requests)
                    assert php(mode,'fund',id=unfunded)=='request_invalidated'
                    assert sql(f"SELECT failure_code FROM service_operations WHERE id={unfunded}")=='service_account_name_conflict'
                    assert named('collision-explicit-known',base+'99999',explicit=True,expect_error='service_account_name_conflict')
                    assert finance()==before and len(requests)==n and not rows(unfunded)
                    second=named('collision-alias',base+'99999',server=3,plan=9)
                    fund(mode,second);before=finance();n=posts()
                    faults.append('lost_response');step(mode,second,'pending','pg_vendor_unavailable')
                    reserved=rows(second)[0].split('\t')[:5]
                    assert reserved[2]==base+'10000' and posts()==n+1,reserved
                    retry(second);step(mode,second,'applied');step(mode,second,'completed')
                    assert rows(second)[0].split('\t')[:5]==reserved and posts()==n+1 and finance()==before
                    before=finance();n=len(requests)
                    step(mode,exact,'pending','service_account_name_conflict')
                    assert not rows(exact) and len(requests)==n and finance()==before
                    sql(f"UPDATE service_operations SET next_attempt_at=DATE_ADD(NOW(6),INTERVAL 1 DAY) WHERE id={exact}")
                    bulk=named('collision-bulk',base+'bulk-12345',quantity=2)
                    token=10000+((int(hashlib.sha256(f'service:{bulk}'.encode()).hexdigest()[:11],16)+1)%90000)
                    occupied=named('collision-bulk-occupant',base+'bulk-'+str(token))
                    fund(mode,occupied);step(mode,occupied,'applied');step(mode,occupied,'completed')
                    fund(mode,bulk);before=finance();n=posts()
                    for stage in ('applied','progressed','applied','completed'):step(mode,bulk,stage)
                    names=[r.split('\t')[2] for r in rows(bulk)]
                    assert len(set(names))==2 and base+'bulk-'+str(token) not in names and all(re.fullmatch(re.escape(base)+r'bulk-[1-9][0-9]{4}',v) for v in names),names
                    assert posts()==n+2 and finance()==before and not faults and not errors
                    # Separate users and logical aliases compete for the
                    # same physical username. A transient SQL deadlock may
                    # defer one claim; it never authorizes a second debit.
                    race_name=base+'race-12345'
                    other=named('collision-race-other',race_name,server=3,plan=9,owner=9002)
                    own=named('collision-race-own',race_name)
                    assert php(mode,'fund',id=other,owner=9002)=='funded'
                    fund(mode,own);before=finance();n=posts()
                    with ThreadPoolExecutor(max_workers=2) as pool:
                        results=list(pool.map(lambda oid:php(mode,'step',id=oid,return_diagnostics=True),(own,other)))
                    for oid,(result,diagnostics) in zip((own,other),results):
                        if result['status']=='pending':
                            assert result['reason']=='provider_storage_unavailable',(result,diagnostics)
                            retry(oid);step(mode,oid,'applied')
                        else:assert result['status']=='applied',result
                        step(mode,oid,'completed')
                    assert posts()==n+2 and finance()==before and len({rows(oid)[0].split('\t')[2] for oid in (own,other)})==2
                    cases.append(mode+'_physical_generated_name_collision_before_dispatch_only')

                # F15: real imported origins use import:<order_id>, not pg:<instance>.
                for mode in ('strict','off'):
                    sql("UPDATE users SET wallet=50000 WHERE userid IN (1001,9002); UPDATE server_info SET ucount=100,state=1,active=1 WHERE id IN (2,3)")
                    def imported_name(ref,name,quantity=1,explicit=False,server=2,plan=7,**kw):
                        return php(mode,'issue',ref=mode+'-import-'+ref,**kw,quote=dict(kind='BUY',variant='standard',
                            price=100,server_id=server,plan_id=plan,order_id=0,quantity=quantity,days=30,volume=2,
                            remark=name,uuid='',naming_mode='manual' if explicit else 'digits',
                            explicit_name=explicit,policy={}))
                    def remote_existing(name):
                        nonlocal next_native_id
                        with lock:
                            assert name not in users
                            next_native_id+=1
                            u=imports.external_user('http://127.0.0.1:'+str(server.server_port),next_native_id,name)
                            users[name]=u
                            return u['subscription_url']
                    def adopt(url,server_id=3,owner=9001,**kw):
                        return php(mode,'import_occupancy',server=server_id,owner=owner,url=url,**kw)
                    def import_identity(effect):
                        return sql(f"SELECT target_key,source_kind,source_ref,provider_remote_user_id,provider_operation_marker,provider_snapshot FROM service_effects WHERE id={effect}")
                    prefix=mode+'-import-'
                    name=prefix+'99999'
                    awaiting=imported_name('awaiting',name,explicit=True)
                    funded=imported_name('funded',name,explicit=True)
                    fund(mode,funded)
                    before=finance();n=posts()
                    url=remote_existing(name)
                    anchor=adopt(url)
                    attachment=adopt(url,server_id=2,owner=9002)
                    assert anchor['target']=='import:'+str(anchor['order'])
                    assert attachment['target']=='attachment:'+str(attachment['order']) and attachment['effect']==anchor['effect']
                    assert finance()==before and posts()==n
                    original=import_identity(anchor['effect'])
                    wire=len(requests)
                    assert php(mode,'fund',id=awaiting)=='request_invalidated'
                    assert sql(f"SELECT failure_code FROM service_operations WHERE id={awaiting}")=='service_account_name_conflict'
                    imported_name('known',name,explicit=True,expect_error='service_account_name_conflict')
                    step(mode,funded,'pending','service_account_name_conflict')
                    assert finance()==before and len(requests)==wire and not rows(awaiting) and not rows(funded)
                    sql(f"UPDATE service_operations SET next_attempt_at=DATE_ADD(NOW(6),INTERVAL 1 DAY) WHERE id={funded}")
                    # The next suffix is occupied by a different imported
                    # origin as well; both namespaces must be considered.
                    other=adopt(remote_existing(prefix+'10000'),server_id=2)
                    other_original=import_identity(other['effect'])
                    single=imported_name('single',name,server=3,plan=9)
                    fund(mode,single);before=finance();n=posts()
                    faults.append('lost_response');step(mode,single,'pending','pg_vendor_unavailable')
                    pinned=rows(single)[0].split('\t')[:5]
                    assert pinned[2]==prefix+'10001' and posts()==n+1,pinned
                    retry(single);step(mode,single,'applied');step(mode,single,'completed')
                    assert rows(single)[0].split('\t')[:5]==pinned and posts()==n+1 and finance()==before
                    assert import_identity(anchor['effect'])==original and import_identity(other['effect'])==other_original
                    bulk=imported_name('bulk',prefix+'bulk-12345',quantity=2,server=3,plan=9)
                    t=10000+((int(hashlib.sha256(f'service:{bulk}'.encode()).hexdigest()[:11],16)+1)%90000)
                    occupied=prefix+'bulk-'+str(t)
                    anchor_bulk=adopt(remote_existing(occupied),server_id=2)
                    original_bulk=import_identity(anchor_bulk['effect'])
                    fund(mode,bulk);before=finance();n=posts()
                    for stage in ('applied','progressed','applied','completed'):step(mode,bulk,stage)
                    names=[r.split('\t')[2] for r in rows(bulk)]
                    assert len(set(names))==2 and occupied not in names and all(re.fullmatch(re.escape(prefix)+r'bulk-[1-9][0-9]{4}',v) for v in names),names
                    assert posts()==n+2 and finance()==before and import_identity(anchor_bulk['effect'])==original_bulk

                    # Model the established verified-deletion/live-reference
                    # states of the real imported origin. The existing delete
                    # and re-import suites prove the actual deletion protocol.
                    with lock:del users[name]
                    sql(f"UPDATE service_effects SET provider_state='deleted' WHERE id={anchor['effect']}; UPDATE orders_list SET status=0 WHERE id={anchor['order']}")
                    imported_name('stale-live',name,explicit=True,expect_error='service_account_name_conflict')
                    sql(f"UPDATE orders_list SET status=0 WHERE id={attachment['order']}")
                    reusable=imported_name('retired-reuse',name)
                    fund(mode,reusable);before=finance();n=posts()
                    step(mode,reusable,'applied');step(mode,reusable,'completed')
                    assert rows(reusable)[0].split('\t')[2]==name and finance()==before and posts()==n+1
                    assert import_identity(anchor['effect'])==original
                    assert sql(f"SELECT provider_state FROM service_effects WHERE id={anchor['effect']}")=='deleted'
                    cases.append(mode+'_imported_physical_names_paid_explicit_generated_bulk_deleted_and_attachment')

                    # Deliberately hold a genuine new import transaction
                    # while a funded BUY on another alias competes. Use
                    # READ COMMITTED: the panel row lock, not an accidental
                    # repeatable-read gap lock, must serialize both claims.
                    race_name=prefix+'race-12345'
                    race=imported_name('race',race_name)
                    fund(mode,race);before=finance();n=posts()
                    race_url=remote_existing(race_name)
                    barrier=work/('import-barrier-'+mode);barrier.mkdir()
                    with ThreadPoolExecutor(max_workers=2) as pool:
                        pending=pool.submit(adopt,race_url,3,9001,barrier=str(barrier),read_committed=True)
                        try:
                            deadline=time.monotonic()+6
                            while not (barrier/'ready').exists() and not pending.done() and time.monotonic()<deadline:time.sleep(.02)
                            assert (barrier/'ready').exists(),pending.result() if pending.done() else 'import not ready'
                            # Prove the import transaction owns the row of
                            # the OTHER logical alias, not merely its own.
                            probe=subprocess.run(client,input='START TRANSACTION; SELECT id FROM server_config WHERE id=2 FOR UPDATE NOWAIT; ROLLBACK;',
                                text=True,env=env,capture_output=True,timeout=3)
                            assert probe.returncode!=0 and 'ERROR 1205' in probe.stderr,probe
                            worker=pool.submit(php,mode,'step',id=race,read_committed=True)
                            # INNODB_LOCK_WAITS is not reliably populated by
                            # all supported MariaDB builds. Observe the exact
                            # outstanding locking SQL plus the NOWAIT proof.
                            wait_sql=("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE DB=DATABASE() "
                                "AND COMMAND='Execute' AND TIME>=1 AND INFO LIKE 'SELECT c.*,i.title FROM server_config c JOIN server_info i ON i.id=c.id%' "
                                "AND INFO LIKE '%FOR UPDATE%'")
                            deadline=time.monotonic()+4
                            waiting=False
                            while not worker.done() and time.monotonic()<deadline:
                                waiting=int(sql(wait_sql))>0
                                if waiting:break
                                time.sleep(.03)
                            assert waiting and not worker.done(),('missing physical reservation wait',worker.result() if worker.done() else sql('SHOW FULL PROCESSLIST'),last_diagnostics)
                        finally:(barrier/'release').touch()
                        race_anchor=pending.result()
                        result=worker.result()
                    assert result['status']=='applied',result
                    step(mode,race,'completed')
                    assert rows(race)[0].split('\t')[2]!=race_name and posts()==n+1 and finance()==before
                    assert race_anchor['target']=='import:'+str(race_anchor['order'])

                    # Reverse ownership: a committed CREATE reservation
                    # cannot be bypassed by a subsequent competing import
                    # of an independently existing remote account.
                    foreign=prefix+'foreign-12345'
                    op=imported_name('foreign',foreign)
                    fund(mode,op);before=finance();n=posts()
                    foreign_url=remote_existing(foreign)
                    step(mode,op,'pending','pg_create_name_identity_conflict')
                    pinned=rows(op)[0]
                    counts=sql("SELECT COUNT(*) FROM orders_list; SELECT COUNT(*) FROM service_effects; SELECT COUNT(*) FROM add_link")
                    adopt(foreign_url,expect_error='provider_adoption_username_conflict')
                    assert sql("SELECT COUNT(*) FROM orders_list; SELECT COUNT(*) FROM service_effects; SELECT COUNT(*) FROM add_link")==counts
                    retry(op);step(mode,op,'pending','pg_create_name_identity_conflict')
                    assert rows(op)[0]==pinned and posts()==n and finance()==before and not faults and not errors
                    sql(f"UPDATE service_operations SET next_attempt_at=DATE_ADD(NOW(6),INTERVAL 1 DAY) WHERE id={op}")
                    cases.append(mode+'_import_create_physical_lock_read_committed_and_original_fence')
            if not (args.payment_actions_only or args.delivery_identity_only or args.report_revisions_only or args.plan_recovery_only or args.referral_agent_only or args.resolution_only or args.zero_commercial_only or args.name_collision_only):
                # Actual large BUY: every Native name and original order ID
                # is present in exactly one bounded report per recipient.
                mode='strict'
                sql('UPDATE users SET wallet=50000 WHERE userid=1001; UPDATE server_info SET ucount=100 WHERE id=2')
                # Exercise continuation boundaries with realistic larger IDs.
                sql('ALTER TABLE orders_list AUTO_INCREMENT=1000000')
                op=issue(mode,'bulk-report-'+('x'*76),42)
                fund(mode,op)
                for i in range(42):
                    step(mode,op,'applied')
                    step(mode,op,'completed' if i==41 else 'progressed')
                names=sql(f"SELECT remote_username,order_id FROM service_effects WHERE source_kind='service_operation' AND source_ref='{op}' ORDER BY ordinal").splitlines()
                assert len(names)==42 and all(len(v.split('\t')[0])>=80 for v in names),names
                before=finance();n=len(requests)
                max_wire=0;max_display=0
                for role in ('service_admin','service_channel'):
                    ids=list(map(int,sql(f"SELECT id FROM payment_followup_intents WHERE service_operation_id={op} AND role='{role}' AND (stage='service_completed' OR stage LIKE 'service_completed_p%') ORDER BY id").splitlines()))
                    assert len(ids)>1,(role,ids)
                    bodies=[]
                    for rid in ids:
                        assert php(mode,'followup',id=rid)=='acknowledged'
                        assert php(mode,'followup',id=rid)=='acknowledged'
                        assert sql(f"SELECT COUNT(*) FROM fixture_followup WHERE intent_id={rid}")=='1'
                        # The batch client escapes line breaks and backslashes.
                        # Verify the actual wire body, not its SQL display.
                        body=bytes.fromhex(sql(f"SELECT HEX(text) FROM fixture_followup WHERE intent_id={rid}")).decode('utf-8')
                        size=len(body.encode('utf-8'))
                        assert size==int(sql(f"SELECT OCTET_LENGTH(text) FROM fixture_followup WHERE intent_id={rid}"))
                        assert size<=4000,(role,rid,size)
                        max_wire=max(max_wire,size)
                        max_display=max(max_display,len(sql(f"SELECT text FROM fixture_followup WHERE intent_id={rid}").encode('utf-8')))
                        bodies.append(body)
                    for line in names:
                        name,oid=line.split('\t')
                        assert sum(f'<code>{name}</code> (#{oid})' in body for body in bodies)==1,(role,name,oid)
                assert finance()==before and len(requests)==n
                assert max_display>max_wire,(max_wire,max_display)
                cases.append('actual_large_buy_all_names_bounded_durable_owner_channel_reports')
            if args.plan_recovery_only:
                for mode in ('strict','off'):
                    plan_recovery_cases(mode)
                    assert not faults and not errors,(faults,errors)
            if args.referral_agent_only:
                for mode in ('strict','off'):
                    agent_referral_cases(mode)
                    assert not faults and not errors,(faults,errors)
            if args.resolution_only:
                for mode in ('strict','off'):
                    resolution_cases(mode)
                    assert not faults and not errors,(faults,errors)
            if args.payment_actions_only or not any((args.delivery_identity_only,args.report_revisions_only,args.resolution_only,args.referral_agent_only,args.plan_recovery_only,args.bulk_report_only,args.zero_commercial_only,args.name_collision_only)):
                sql("""UPDATE users SET wallet=50000,refered_by=0,step='none',is_agent=0 WHERE userid=1001;
                    UPDATE server_info SET ucount=100,state=1,active=1 WHERE id=2;
                    UPDATE server_plans SET days=30,volume=2,price=100,active=1 WHERE id=7;
                    DELETE FROM setting WHERE type='BOT_STATES'; INSERT INTO setting(type,value) VALUES ('BOT_STATES','{"sellState":"on","walletState":"on","cartToCartState":"on","renewAccountState":"on","increaseTimeState":"on","increaseVolumeState":"on","plandelkhahState":"on","gbPrice":10,"dayPrice":20}')""")
                for mode in ('strict','off'):
                    def new_invoice(label,typ,**kw):
                        h=mode+'-action-'+label
                        p=php(mode,'checkout_insert',hash=h,type=typ,price=100,**kw)
                        assert p>0,(label,p,last_diagnostics)
                        return h,p
                    def check_action(h,p,staff=False,legacy=None):
                        op='telegram_approval' if staff else 'telegram_wallet'
                        action=('pa_' if staff else 'pw_')+h
                        before=finance();n=len(requests)
                        if legacy:
                            php(mode,op,callback=legacy+h)
                        php(mode,op,callback=action,sender=1001 if staff else 9002)
                        php(mode,op,callback=action,group=True)
                        assert finance()==before and len(requests)==n
                        if staff:
                            assert sql(f"SELECT state FROM pays WHERE id={p}")=='have_sent'
                        else:
                            assert sql(f"SELECT checkout_operation_id IS NULL FROM pays WHERE id={p}")=='1'
                        php(mode,op,callback=action)
                        row=sql(f"SELECT state,COALESCE(checkout_operation_id,0) FROM pays WHERE id={p}").split('\t')
                        assert row[0]==('paid' if staff else 'paid_with_wallet'),(h,row,last_diagnostics)
                        sid=int(row[1])
                        if staff:
                            assert sql(f"SELECT COUNT(*) FROM wallet_entries WHERE source_kind='payment_credit' AND source_ref='{p}'")=='1'
                        if sid:
                            assert sql(f"SELECT stage FROM service_operations WHERE id={sid}")=='completed'
                            assert sql(f"SELECT COUNT(*) FROM wallet_entries WHERE source_kind='service_debit' AND source_ref='{sid}'")=='1'
                        after=finance();writes=[r for r in requests if r[0] in ('POST','PUT','PLAN_PUT','RESET','DELETE')]
                        php(mode,op,callback=action)
                        assert finance()==after and [r for r in requests if r[0] in ('POST','PUT','PLAN_PUT','RESET','DELETE')]==writes
                        return sid
                    h,p=new_invoice('wallet-buy','BUY_SUB')
                    sid=check_action(h,p,legacy='payBuyWithWallet')
                    order=int(sql(f"SELECT order_id FROM service_effects WHERE source_kind='service_operation' AND source_ref='{sid}'"))
                    assert order>0
                    sql("INSERT INTO increase_day(volume,price) VALUES (5,100) ON DUPLICATE KEY UPDATE price=VALUES(price); INSERT INTO increase_plan(volume,price) VALUES (5,100) ON DUPLICATE KEY UPDATE price=VALUES(price)")
                    day=int(sql("SELECT MAX(id) FROM increase_day WHERE volume=5 AND server_id IS NULL"))
                    vol=int(sql("SELECT MAX(id) FROM increase_plan WHERE volume=5 AND server_id IS NULL"))
                    subjects=[
                        ('buy','BUY_SUB',{},'payBuyWithWallet','accept'),
                        ('custom','BUY_SUB',dict(days=4,volume=4,description=mode+'-action-custom'),'payCustomWithWallet','accCustom'),
                        ('renew','RENEW_ACCOUNT',dict(order=order),'payRenewWithWallet','approveRenewAcc'),
                        ('day',f'INCREASE_DAY_{order}_{day}',dict(order=order,days=5,addon_kind='day',offer=day),'payIncreaseDayWithWallet','approveIncreaseDay'),
                        ('volume',f'INCREASE_VOLUME_{order}_{vol}',dict(order=order,volume=5,addon_kind='volume',offer=vol),'payIncreaseWithWallet','approveIncreaseVolume'),
                        ('topup','INCREASE_WALLET',{},None,'approvePayment')]
                    for label,typ,kw,old_wallet,old_approve in subjects:
                        if old_wallet and label!='buy':
                            h,p=new_invoice('wallet-'+label,typ,**kw)
                            check_action(h,p,legacy=old_wallet)
                        if label=='custom':kw=dict(kw,description=mode+'-approval-custom')
                        h,p=new_invoice('staff-'+label,typ,**kw)
                        assert php(mode,'checkout_manual',pay=p,submit=True),(label,last_diagnostics)
                        check_action(h,p,staff=True,legacy=old_approve)
                    cases.append(mode+'_single_wallet_and_staff_dispatch_all_original_subjects')
            if args.delivery_identity_only or not any((args.payment_actions_only,args.report_revisions_only,args.resolution_only,args.referral_agent_only,args.plan_recovery_only,args.bulk_report_only,args.zero_commercial_only,args.name_collision_only)):
                for mode in ('strict','off'):
                    # Isolate scheduling without modifying historical packets or attempts.
                    sql("UPDATE payment_delivery_intents SET next_attempt_at=DATE_ADD(NOW(6),INTERVAL 1 HOUR) WHERE state IN ('pending','dispatching')")
                    def identity_delivery(tag):
                        sid=issue(mode,mode+'-delivery-identity-'+tag)
                        fund(mode,sid);step(mode,sid,'applied');step(mode,sid,'completed')
                        did=int(sql(f"SELECT id FROM payment_delivery_intents WHERE service_operation_id={sid}"))
                        assert sql(f"SELECT part_count FROM payment_delivery_intents WHERE id={did}")=='2'
                        return did
                    # Both fresh intents are due without a fabricated old timestamp.
                    bad=identity_delivery('blocked');good=identity_delivery('fresh')
                    bad_order=int(sql(f"SELECT order_id FROM payment_delivery_intents WHERE id={bad}"))
                    sql(f"UPDATE orders_list SET uuid='fixture-obsolete-credential' WHERE id={bad_order}")
                    before=finance();n=len(requests)
                    assert php(mode,'maintain_delivery',limit=2)==dict(checked=2,acknowledged=1,unknown=0,deferred=1)
                    assert sql(f"SELECT COUNT(*) FROM payment_delivery_attempts WHERE intent_id={bad}")=='0'
                    assert sql(f"SELECT COUNT(*) FROM payment_delivery_attempts WHERE intent_id={good} AND result='acknowledged' AND ack_chat_id=1001 AND ack_message_id>0")=='2'
                    assert sql(f"SELECT next_attempt_at>NOW(6) FROM payment_delivery_intents WHERE id={bad}")=='1'
                    assert finance()==before and len(requests)==n
                    cases.append(mode+'_new_delivery_due_immediately_and_failed_preflight_yields')
                    # Malformed or foreign success cannot certify either a photo or text.
                    for i,response in enumerate([dict(ok=True),dict(ok=True,result=dict(chat=dict(id=9001),message_id=22)),dict(ok=True,result=dict(chat=dict(id=1001),message_id=0)),dict(ok=True,error_code=400,result=dict(chat=dict(id=1001),message_id=22))]):
                        did=identity_delivery('malformed-'+str(i))
                        before=finance();n=len(requests)
                        part=1+i%2
                        assert php(mode,'deliver',id=did,responses={part:response})=='unknown'
                        assert sql(f"SELECT COUNT(*) FROM payment_delivery_attempts WHERE intent_id={did} AND result='unknown' AND ack_chat_id IS NULL AND ack_message_id IS NULL")=='1'
                        assert sql(f"SELECT COUNT(*) FROM payment_delivery_attempts WHERE intent_id={did} AND result='acknowledged' AND ack_chat_id=1001 AND ack_message_id>0")=='1'
                        assert php(mode,'deliver',id=did)=='unknown'
                        assert sql(f"SELECT COUNT(*) FROM fixture_telegram WHERE intent_id={did}")=='2'
                        assert finance()==before and len(requests)==n
                    cases.append(mode+'_customer_photo_text_ack_identity_and_independent_sibling')
            if args.report_revisions_only or not any((args.payment_actions_only,args.delivery_identity_only,args.resolution_only,args.referral_agent_only,args.plan_recovery_only,args.bulk_report_only,args.zero_commercial_only,args.name_collision_only)):
                sql("UPDATE users SET wallet=50000,refered_by=0 WHERE userid=1001; UPDATE server_info SET ucount=100 WHERE id=2")
                for mode in ('strict','off'):
                    report_revision_cases(mode)
        finally:
            release.set();server.shutdown();server.server_close();thread.join(5)
    result=dict(complete=True,cases=len(cases),checks=cases,requests=len(requests),posts=posts())
    if args.report:args.report.write_text(json.dumps(result,indent=2)+'\n')
    print(json.dumps(result))

if __name__=='__main__':main()
