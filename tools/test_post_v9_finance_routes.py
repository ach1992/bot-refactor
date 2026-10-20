#!/usr/bin/env python3
"""Original-schema native financial route guards with mocked payment transport.

The current PHP modules run unmodified against a socket-only disposable
23-table database. curl functions are disabled and replaced by local spies:
no payment, Marzban, Telegram or internet request is possible.
"""
from __future__ import annotations

import argparse
import json
import re
import subprocess
import time as clock
from pathlib import Path

import test_access_state as owned
import test_v6_native as native
import test_v8_native as purchase
import test_post_v9_purchases as stages

ROOT = Path(__file__).resolve().parents[1]
DB = purchase.SIDE[1]

PHP_DRIVER = r"""<?php
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');
set_error_handler(function($level,$message,$file){
    // Gateway callback warnings are financial test failures, not accepted debt.
    throw new ErrorException($message, 0, $level);
});
$_SERVER['SCRIPT_FILENAME'] = 'owned-finance-guard.php';
$root = $argv[1];
$dbname = $argv[2];
$socket = $argv[3];
$spec = json_decode($argv[4], true, 512, JSON_THROW_ON_ERROR);
$_GET = $spec['get'];
$GLOBALS['network_calls'] = [];
$GLOBALS['error'] = null;
$GLOBALS['mock_invoice'] = $spec['mock_invoice'] ?? '';
$GLOBALS['fake_nowpayment_status'] = $spec['nowpayment_status'] ?? 'waiting';
$GLOBALS['fake_invoice_transport_failure'] = $spec['invoice_transport_failure'] ?? false;
$GLOBALS['fake_nowpayment_http'] = $spec['nowpayment_http'] ?? 200;
$GLOBALS['fake_nowpayment_raw'] = $spec['nowpayment_raw'] ?? null;
$GLOBALS['fake_nowpayment_transport_failure'] = $spec['nowpayment_transport_failure'] ?? false;
$GLOBALS['fake_nowpayment_payment_id'] = $spec['nowpayment_payment_id'] ?? null;
$GLOBALS['fake_nowpayment_order_id'] = $spec['nowpayment_order_id'] ?? null;
$GLOBALS['fake_nowpayment_price'] = $spec['nowpayment_price'] ?? 1;
$GLOBALS['fake_nowpayment_actual'] = $spec['nowpayment_actual'] ?? 1;
$GLOBALS['fake_nowpayment_outcome'] = $spec['nowpayment_outcome'] ?? 1;
$GLOBALS['fake_nowpayment_outcome_currency'] = $spec['nowpayment_outcome_currency'] ?? 'usdttrc20';
$GLOBALS['fake_nowpayment_pay_currency'] = $spec['nowpayment_pay_currency'] ?? 'usdttrc20';
$GLOBALS['fake_nowpayment_pay_amount'] = $spec['nowpayment_pay_amount'] ?? 1;
$GLOBALS['fake_nowpayment_force_id'] = $spec['nowpayment_force_id'] ?? null;
$GLOBALS['fake_invoice_id'] = $spec['fake_invoice_id'] ?? 'owned-new-invoice';
$GLOBALS['fake_nowpayment_flip_hash'] = $spec['nowpayment_flip_hash'] ?? null;
$GLOBALS['fake_nextpay_code'] = $spec['nextpay_code'] ?? -2;
$GLOBALS['fake_nextpay_http'] = $spec['nextpay_http'] ?? 200;
$GLOBALS['fake_nextpay_raw'] = $spec['nextpay_raw'] ?? null;
$GLOBALS['fake_nextpay_transport_failure'] = $spec['nextpay_transport_failure'] ?? false;
$GLOBALS['fake_nextpay_flip_hash'] = $spec['nextpay_flip_hash'] ?? null;
$GLOBALS['fake_nextpay_flip_state'] = $spec['nextpay_flip_state'] ?? 'paid';
$GLOBALS['fake_zarin_status'] = array_key_exists('zarin_status',$spec)
    ? $spec['zarin_status'] : -11;
$GLOBALS['fake_zarin_flip_hash'] = $spec['zarin_flip_hash'] ?? null;
$GLOBALS['fake_prepayment_flip_hash'] = $spec['prepayment_flip_hash'] ?? null;
$GLOBALS['owned_finance_dbname'] = $dbname;
$GLOBALS['owned_finance_socket'] = $socket;
$GLOBALS['fake_zarin_throw'] = $spec['zarin_throw'] ?? false;
$GLOBALS['fake_zarin_malformed'] = $spec['zarin_malformed'] ?? false;
$GLOBALS['fake_marzban_http'] = $spec['marzban_http'] ?? 200;
$GLOBALS['fake_marzban_raw'] = $spec['marzban_raw'] ?? null;
$GLOBALS['fake_marzban_transport_failure'] = $spec['marzban_transport_failure'] ?? false;
$GLOBALS['fake_marzban_reset_http'] = $spec['marzban_reset_http'] ?? 200;
$GLOBALS['fake_marzban_username'] = $spec['marzban_username'] ?? 'syntheticMarzban';
$GLOBALS['fake_renew_success'] = $spec['renew_success'] ?? false;
$GLOBALS['fake_remote_exit'] = $spec['remote_exit'] ?? false;
$GLOBALS['inspect_hosted_pay_id'] = (int)($spec['pay_row_id'] ?? 0);

$GLOBALS['fake_alert_inject_child'] = $spec['alert_inject_child'] ?? null;
$GLOBALS['fake_alert_inject_order'] = $spec['alert_inject_order'] ?? 'wallet-init';
$GLOBALS['fake_alert_inject_invoice'] = $spec['alert_inject_invoice'] ?? 'owned-new-invoice';
$GLOBALS['fake_alert_nested_worker'] = $spec['alert_nested_worker'] ?? false;
$GLOBALS['race_flip_applied'] = false;
$GLOBALS['fake_alert_send_failure'] = $spec['alert_send_failure'] ?? false;
$GLOBALS['invoice_reservation_seen'] = null;
$GLOBALS['events'] = [];
$GLOBALS['known_inherited_warnings'] = [];
$botState = array_replace(['nowPaymentWallet'=>'on','nowPaymentOther'=>'on',
    'zarinpal'=>'on','nextpay'=>'on'], $spec['states']??[]);
$botUrl = 'http://127.0.0.1:9/owned-fixture/';
$USDTPrice = 100000;
$USDTPriceagent = 100000;
$nowpaymentsIpnSecret = 'LOCAL-IPN-SECRET';
// No merchant payout floor is the normal hosted-checkout configuration.
// Set this optional variable only for legacy stricter-policy fixtures.
if (array_key_exists('settlement_policy', $spec)) {
    $nowpaymentsSettlementPolicy = $spec['settlement_policy'];
}
$paymentKeys = [
    'nowpayment'=>'SYNTHETIC-LOCAL-ONLY','nextpay'=>'SYNTHETIC-LOCAL-ONLY',
    'zarinpal'=>'SYNTHETIC-LOCAL-ONLY',
    'manualUsdtWalletTRC20'=>'TNPeeaaFB7K9cmo4uQpcU32zGK8G1NYqeL',
    'manualUsdtRateToman'=>'100000','manualTrxRateToman'=>'15000',
    'manualTrxWallet'=>'TJRabPrwbZy45sbavfcjinPJC18kjpRTv8'
];
$admin = 9001;
function sendMessage(...$args) {
    $GLOBALS['events'][] = ['name'=>'sendMessage', 'args'=>$args];
    if (is_string($args[0] ?? null) &&
        str_contains($args[0],'پرداخت نیازمند بررسی مالی')) {
        if ($GLOBALS['fake_alert_nested_worker']) {
            $GLOBALS['fake_alert_nested_worker']=false;
            $GLOBALS['events'][]=['name'=>'nested_alert','result'=>achbotNpAlertOperator(
                $GLOBALS['connection'],9001,5)];
        }
        if ($GLOBALS['fake_alert_inject_child'] !== null) {
            $child=(string)$GLOBALS['fake_alert_inject_child'];
            $GLOBALS['fake_alert_inject_child']=null;
            $payload=[
                'payment_id'=>$child,
                'invoice_id'=>$GLOBALS['fake_alert_inject_invoice'],
                'order_id'=>$GLOBALS['fake_alert_inject_order'],
                'payment_status'=>'finished',
                'price_amount'=>1,'price_currency'=>'usd'
            ];
            $raw=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $sig=hash_hmac('sha512',json_encode(achbotNpSortedPayload($payload),
                 JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'LOCAL-IPN-SECRET');
            $GLOBALS['events'][]=['name'=>'inflight_ipn',
                'code'=>achbotNpIngestIpn($GLOBALS['connection'],
                    'LOCAL-IPN-SECRET',$raw,$sig)];
        }
    }
    return (object)['ok'=>!$GLOBALS['fake_alert_send_failure']];
}
function Bot($method, $args=[]) {
    $GLOBALS['events'][]=['name'=>'Bot','method'=>$method];
    return (object)['ok'=>true,'result'=>(object)[
        'first_name'=>'Synthetic','username'=>'synthetic_user'
    ]];
}
function generateRandomString($length, $protocol) {
    return str_repeat('a', $length);
}
function renewConfigOnServer(...$args) {
    // Inspect the REAL persisted 003 state at the remote side-effect seam.
    // This is a read-only SQL observation, never a production adapter change.
    $id=$GLOBALS['inspect_hosted_pay_id'];
    if ($id>0) {
        $stmt=$GLOBALS['connection']->prepare("SELECT a.stage,a.provider_status
            FROM payment_attempts a WHERE a.pay_row_id=? LIMIT 1");
        $stmt->bind_param('i',$id);
        $stmt->execute();
        $record=$stmt->get_result()->fetch_assoc();
        $stmt->close();
        $GLOBALS['events'][]=['name'=>'hosted_remote_entry',
            'stage'=>$record['stage']??null,
            'reason'=>$record['provider_status']??null];
    }
    $GLOBALS['events'][]=['name'=>'renewConfigOnServer','result'=>$GLOBALS['fake_renew_success']];
    if ($GLOBALS['fake_remote_exit']) {
        // Simulate a PHP worker termination at the external-effect boundary.
        // The shutdown serializer still reports in-memory spy events while
        // the claimed DB transaction remains committed for the next worker.
        exit(0);
    }
    return (object)['success'=>$GLOBALS['fake_renew_success']];
}
function delMessage() {
    $GLOBALS['events'][]=['name'=>'delMessage'];
    if ($GLOBALS['fake_prepayment_flip_hash'] !== null) {
        // Independent worker commits between the route SELECT and
        // its conditional mismatch UPDATE, without modifying production PHP.
        $other=new mysqli('localhost','root','',
            $GLOBALS['owned_finance_dbname'],0,$GLOBALS['owned_finance_socket']);
        $stmt=$other->prepare("UPDATE pays SET state='paid'
                              WHERE hash_id=? AND state='pending'");
        $hash=(string)$GLOBALS['fake_prepayment_flip_hash'];
        $stmt->bind_param('s',$hash);
        $stmt->execute();
        $GLOBALS['race_flip_applied']=$stmt->affected_rows===1;
        $stmt->close();
        $other->close();
        $GLOBALS['fake_prepayment_flip_hash']=null;
    }
}
function RandomString($count=9, $type='all') { return 'new-wallet-safe-hash'; }
function getMainKeys() { return '{}'; }
function editText(...$args) {
    $GLOBALS['events'][]=['name'=>'editText','args'=>$args];
    return (object)['ok'=>true];
}

function alert(...$args) { $GLOBALS['events'][]=['name'=>'alert','args'=>$args]; }
function answerCallbackQuery(...$args) { $GLOBALS['events'][]=['name'=>'callback','args'=>$args]; }
function createServerAccount(...$args) {
    // Replace ONLY the external Marzban boundary with an ambiguous result.
    // The real doAction() payment/compensation handling remains unmodified.
    $GLOBALS['events'][] = ['name'=>'marzban_unknown_result'];
    return [null, $args[4] ?? '', $args[9] ?? 0];
}
// Native PHP -n has no SOAP extension. Mirror the real callback/request
// method contract while recording provider effects without external traffic.
if (!class_exists('SoapClient')) {
    class SoapClient {
        function __construct(...$args) {}
        function PaymentRequest($params) {
            $GLOBALS['network_calls'][]='zarinpal:request';
            return (object)['Status'=>100,'Authority'=>'zarin-'.($_GET['hash_id']??'unknown')];
        }
        function PaymentVerification($params) {
            $GLOBALS['network_calls'][]='zarinpal:verify';
            if ($GLOBALS['fake_zarin_flip_hash'] !== null) {
                $stmt=$GLOBALS['connection']->prepare(
                    "UPDATE pays SET state='paid' WHERE hash_id=? AND state='pending'");
                $stmt->bind_param('s',$GLOBALS['fake_zarin_flip_hash']);
                $stmt->execute();
                $GLOBALS['race_flip_applied']=$stmt->affected_rows===1;
                $stmt->close();
            }
            if ($GLOBALS['fake_zarin_throw']) throw new RuntimeException('simulated_provider_transport');
            if ($GLOBALS['fake_zarin_malformed']) return (object)[];
            return (object)['Status'=>$GLOBALS['fake_zarin_status']];
        }
    }
}
function curl_init() { return (object)['url'=>'','method'=>'GET','fields'=>null]; }
function curl_setopt($handle,$option,$value) {
    if ($option === CURLOPT_URL) $handle->url = (string)$value;
    if ($option === CURLOPT_CUSTOMREQUEST) $handle->method=(string)$value;
    if ($option === CURLOPT_POSTFIELDS) $handle->fields=$value;
    if ($option === CURLOPT_POST && $value) $handle->method='POST';
    return true;
}
function curl_setopt_array($handle,$options) {
    foreach ($options as $option => $value) curl_setopt($handle,$option,$value);
    return true;
}
function curl_exec($handle) {
    $GLOBALS['network_calls'][] = $handle->url;
    if (str_contains($handle->url, '/api/admin/token')) {
        return json_encode(['access_token'=>'TEST-ONLY']);
    }
    if (str_contains($handle->url, '/api/user/') &&
        str_ends_with($handle->url, '/reset')) {
        if ($GLOBALS['fake_marzban_reset_http'] !== 200) return '{"detail":"reset rejected"}';
        return json_encode(['username'=>$GLOBALS['fake_marzban_username']]);
    }
    if (str_contains($handle->url, '/api/user/') && $handle->method==='PUT') {
        if ($GLOBALS['fake_marzban_transport_failure']) return false;
        if ($GLOBALS['fake_marzban_raw'] !== null) return $GLOBALS['fake_marzban_raw'];
        $post=json_decode($handle->fields ?? '{}',true);
        return json_encode([
            'username'=>$GLOBALS['fake_marzban_username'],
            'expire'=>$post['expire'] ?? 0,
            'data_limit'=>$post['data_limit'] ?? 0,
            'status'=>'active'
        ]);
    }
    if (str_contains($handle->url, '/api/user/')) {
        return json_encode([
            'username'=>$GLOBALS['fake_marzban_username'],
            'expire'=>time()+86400,
            'data_limit'=>1073741824,
            'inbounds'=>['vless'=>['fake-inbound']],
            'proxies'=>['vless'=>new stdClass()],
            'note'=>'fixture',
            'data_limit_reset_strategy'=>'no_reset',
            'status'=>'active'
        ]);
    }
    if (str_contains($handle->url, '/nx/gateway/token')) {
        return json_encode(['code'=>-1,'trans_id'=>'next-'.($_GET['hash_id']??'unknown')]);
    }
    if (str_contains($handle->url, '/nx/gateway/verify')) {
        // Deterministically emulate a second worker settling the EXACT row
        // after this callback's SELECT and while provider verification is in flight.
        if ($GLOBALS['fake_nextpay_flip_hash'] !== null) {
            $stmt = $GLOBALS['connection']->prepare(
                "UPDATE pays SET state=? WHERE hash_id=? AND state IN ('pending','send')"
            );
            $stmt->bind_param('ss', $GLOBALS['fake_nextpay_flip_state'],
                                   $GLOBALS['fake_nextpay_flip_hash']);
            $stmt->execute();
            $GLOBALS['race_flip_applied'] = $stmt->affected_rows === 1;
            $stmt->close();
        }
        if ($GLOBALS['fake_nextpay_transport_failure']) return false;
        if ($GLOBALS['fake_nextpay_raw'] !== null) return $GLOBALS['fake_nextpay_raw'];
        return json_encode(['code'=>$GLOBALS['fake_nextpay_code']]);
    }
    if (str_contains($handle->url, '/v1/invoice')) {
        // Confirm a durable local claim exists BEFORE the provider receives
        // the non-idempotent invoice creation request.
        $stmt=$GLOBALS['connection']->prepare(
            "SELECT stage FROM payment_attempts WHERE order_hash=? LIMIT 1");
        $key=(string)($_GET['hash_id'] ?? '');
        $stmt->bind_param('s',$key);
        $stmt->execute();
        $reservation=$stmt->get_result()->fetch_assoc();
        $stmt->close();
        $GLOBALS['invoice_reservation_seen']=$reservation['stage'] ?? null;
        if ($GLOBALS['fake_invoice_transport_failure'] ?? false) return false;
        $invoice=(string)$GLOBALS['fake_invoice_id'];
        return json_encode(['id'=>$invoice,
             'invoice_url'=>'https://nowpayments.io/payment?iid='.$invoice]);
    }
    if (str_contains($handle->url, '/v1/payment/')) {
        if ($GLOBALS['fake_nowpayment_flip_hash'] !== null) {
            $stmt = $GLOBALS['connection']->prepare(
                "UPDATE pays SET state='paid' WHERE hash_id=? AND state IN ('pending','send')"
            );
            $stmt->bind_param('s', $GLOBALS['fake_nowpayment_flip_hash']);
            $stmt->execute();
            $GLOBALS['race_flip_applied'] = $stmt->affected_rows === 1;
            $stmt->close();
        }
        if ($GLOBALS['fake_nowpayment_transport_failure']) return false;
        if ($GLOBALS['fake_nowpayment_raw'] !== null) return $GLOBALS['fake_nowpayment_raw'];
        $record = ['invoice_id'=>$GLOBALS['mock_invoice'],
                   'payment_status'=>$GLOBALS['fake_nowpayment_status']];
        if ($GLOBALS['fake_nowpayment_payment_id'] !== null) {
            $record['payment_id'] = $GLOBALS['fake_nowpayment_payment_id'];
        }
        if ($GLOBALS['fake_nowpayment_order_id'] !== null) {
            $record['order_id'] = $GLOBALS['fake_nowpayment_order_id'];
        }
        $record['payment_id'] = $GLOBALS['fake_nowpayment_force_id'] ??
            $GLOBALS['fake_nowpayment_payment_id'] ?? $record['payment_id'] ?? null;
        $record['price_currency']='usd';
        $record['price_amount']=$GLOBALS['fake_nowpayment_price'];
        $record['pay_amount']=$GLOBALS['fake_nowpayment_pay_amount'];
        $record['actually_paid']=$GLOBALS['fake_nowpayment_actual'];
        $record['pay_currency']=$GLOBALS['fake_nowpayment_pay_currency'];
        $record['outcome_currency']=$GLOBALS['fake_nowpayment_outcome_currency'];
        $record['outcome_amount']=$GLOBALS['fake_nowpayment_outcome'];
        return json_encode($record);
    }
    return json_encode(['invoice_id'=>$GLOBALS['mock_invoice'],
                        'payment_status'=>'waiting']);
}
function curl_close($handle) {}
function curl_error($handle) {
    if (str_contains($handle->url,'/api/user/') && $handle->method==='PUT' &&
        $GLOBALS['fake_marzban_transport_failure']) return 'synthetic_timeout';
    return '';
}
function curl_getinfo($handle,$option) {
    if (str_contains($handle->url,'/api/user/') &&
        str_ends_with($handle->url,'/reset')) return $GLOBALS['fake_marzban_reset_http'];
    if (str_contains($handle->url,'/api/user/') && $handle->method==='PUT') return $GLOBALS['fake_marzban_http'];
    if (str_contains($handle->url,'/api/user/') || str_contains($handle->url,'/api/admin/token')) return 200;
    if (str_contains($handle->url, '/v1/payment/')) return $GLOBALS['fake_nowpayment_http'];
    return $GLOBALS['fake_nextpay_http'];
}
ob_start();
register_shutdown_function(function () {
    $html = ob_get_contents();
    ob_end_clean();
    $last=error_get_last();
    echo json_encode([
      'network_calls'=>$GLOBALS['network_calls'],
      'events'=>$GLOBALS['events'],
      'race_flip_applied'=>$GLOBALS['race_flip_applied'],
      'invoice_reservation_seen'=>$GLOBALS['invoice_reservation_seen'],
      'known_inherited_warnings'=>$GLOBALS['known_inherited_warnings'],
      'error'=>$GLOBALS['error'],
      'form'=>$html,
      'fatal'=>($last && in_array($last['type'], [E_ERROR,E_PARSE,E_COMPILE_ERROR])) ? $last['message'] : null
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
});
// Database fault adapter exists ONLY in this disposable PHP test driver.
// Production PHP is never given synthetic switches or mock mysqli classes.
class OwnedFinanceFaultMysqli extends mysqli {
    private function atHostedClaim(): bool {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 9) as $frame) {
            if (($frame['function'] ?? '') === 'achbotClaimHostedServiceForReview') return true;
        }
        return false;
    }
    public function query(string $sql, int $result_mode = MYSQLI_STORE_RESULT): mysqli_result|bool {
        if (!empty($GLOBALS['spec']['fail_hosted_schema_query']) &&
            $sql === "SHOW TABLES LIKE 'payment_attempts'" &&
            $this->atHostedClaim()) return false;
        return parent::query($sql, $result_mode);
    }
    public function begin_transaction(int $flags = 0, ?string $name = null): bool {
        if (!empty($GLOBALS['spec']['fail_hosted_begin']) && $this->atHostedClaim())
            return false;
        return parent::begin_transaction($flags, $name);
    }
    public function prepare(string $query): mysqli_stmt|false {
        if (!empty($GLOBALS['spec']['fail_hosted_preclaim_pays_read']) &&
            str_contains($query, "SELECT * FROM `pays` WHERE `id` = ?") &&
            str_contains($query, "`state` = 'pending'") &&
            array_filter(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 9),
                fn($frame) => ($frame['function'] ?? '') === 'achbotPerformPaymentEffect'))
            return false; // Simulate non-throwing MYSQLI_REPORT_OFF prepare().
        return parent::prepare($query);
    }
}
try {
    $connection = (!empty($spec['fail_hosted_schema_query']) ||
                   !empty($spec['fail_hosted_begin']) ||
                   !empty($spec['fail_hosted_preclaim_pays_read']))
        ? new OwnedFinanceFaultMysqli('localhost','root','',$dbname,0,$socket)
        : new mysqli('localhost', 'root', '', $dbname, 0, $socket);
    $connection->set_charset('utf8mb4');
    if (($spec['mysqli_report_off']??false)===true)
        mysqli_report(MYSQLI_REPORT_OFF);
    require $root . '/src/Purchase/Core.php';
    // Use the ACTUAL current wallet-credit helper; only outbound Telegram is
    // captured by the transport spy. No fake settlement implementation.
    require $root . '/src/Finance/ReceiptHelpers.php';
    // The hosted-renewal success path records a REAL increase_order row.
    // Load the shipping compatibility facade instead of mocking this write.
    require $root . '/buy_helpers.php';
    require $root . '/src/Finance/GatewayClient.php';
    if ($spec['route'] === 'ipn' || $spec['route'] === 'reconcile' || $spec['route'] === 'alert') {
        define('ACHBOT_NP_RECONCILE_ONLY',true);
        $_GET=[];
        require $root . '/src/Finance/GatewayReturn.php';
        if ($spec['route'] === 'ipn') {
            $raw = $spec['ipn_raw'] ?? '{}';
            $key = $spec['ipn_secret'] ?? 'LOCAL-IPN-SECRET';
            $signed = json_decode($raw,true);
            $canonical = json_encode(achbotNpSortedPayload($signed),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $signature = $spec['ipn_wrong_signature'] ?? hash_hmac('sha512',$canonical,$key);
            $GLOBALS['events'][]=['name'=>'ipn_result','code'=>achbotNpIngestIpn(
                $connection,$key,$raw,$signature)];
        } elseif ($spec['route'] === 'reconcile') {
            $GLOBALS['events'][]=['name'=>'reconcile_result','result'=>achbotNpReconcileBatch(
                $connection,$paymentKeys,1)];
        } else {
            $GLOBALS['events'][]=['name'=>'alert_result','result'=>achbotNpAlertOperator(
                $connection,$spec['alert_admin'] ?? $admin,5)];
        }
    } elseif ($spec['route'] === 'cron') {
        $botState=['sellState'=>'off','searchState'=>'off','rewaredTime'=>0,
                   'rewardChannel'=>null,'cartToCartAutoAcceptState'=>'off'];
        $paymentKeys=['nowpayment'=>'SYNTHETIC-LOCAL-ONLY'];
        $admin=9001;
        require $root . '/settings/rewardReport.php';
    } elseif ($spec['route'] === 'wallet_start') {
        require $root . '/src/Finance/WalletStart.php';
        $state=array_fill_keys(
            ['cartToCartState','nowPaymentWallet','zarinpal',
             'nextpay'], 'off');
        // This low-level finance fixture explicitly stages a synthetic
        // pre-authorized request. The separate manual-payment suite executes
        // real UpdateBindings.php, Telegram prompts and callback provenance.
        $expected='achbotWalletTopup:choose:990001:500:1700000000';
        if (($spec['wallet_authorized'] ?? false) === true) {
            $stmt=$connection->prepare('UPDATE users SET step=? WHERE userid=?');
            $actor=1001;
            $stmt->bind_param('si',$expected,$actor);
            if (!$stmt->execute() || $stmt->affected_rows!==1)
                throw new RuntimeException('fixture_user_stage_not_unique');
            $stmt->close();
        }
        $status=startWalletPayment($connection,$state,['cancel'=>'لغو'],
            $botUrl,1001,null,(int)($spec['topup_amount']??100000),
            $expected,990002,500);
        $GLOBALS['events'][]=['name'=>'wallet_start_result','status'=>$status];
    } elseif ($spec['route'] === 'wallet_refund') {
        $result=refundToWalletAndNotify($connection,
            (int)($spec['refund_user']??1001),(int)($spec['refund_amount']??100),
            'nowpayment',9001,'synthetic compensation',null);
        $GLOBALS['events'][]=['name'=>'refund_result','success'=>$result];
    } elseif ($spec['route'] === 'prepayment') {
        $data=(string)($spec['callback_data']??'');
        $callbackId='test-callback';
        $message_id=702;
        $from_id=(int)($spec['actor']??1001);
        require $root . '/src/Finance/Routes/PrePayment.php';
        require $root . '/src/Finance/Routes/ManualCrypto.php';
        $GLOBALS['events'][]=['name'=>'selected_data','data'=>$data,
            'network'=>$GLOBALS['achbotManualCryptoChoice']??null];
    } elseif ($spec['route'] === 'local_purchase_write') {
        try {
            if ($spec['operation']==='order_insert') {
                insertOrder($connection,1001,'owned-token',7,2,0,'owned-order-write',
                    'owned-uuid','vless',2000000000,'[]',100,time(),'none',0,true);
            } elseif ($spec['operation']==='capacity_update') {
                decreaseServerCapacity($connection,2,0,7,1,true);
            } else {
                throw new RuntimeException('unsupported_synthetic_action');
            }
            $GLOBALS['events'][]=['name'=>'local_write_result','status'=>'confirmed'];
        } catch (RuntimeException $error) {
            $GLOBALS['events'][]=['name'=>'local_write_result','status'=>'rejected',
                'reason'=>$error->getMessage()];
        }
    } elseif ($spec['route'] === 'marzban_edit') {
        require $root . '/src/Panel/Accounts.php';
        $change=$spec['marzban_change']??
            ['remark'=>'syntheticMarzban','plus_day'=>1];
        $result=\AchBot\Panel\Accounts\editMarzbanConfig(
            (int)($spec['marzban_server']??2),$change);
        $GLOBALS['events'][]=['name'=>'marzban_edit_result',
            'success'=>$result->success??false,'uncertain'=>$result->uncertain??null,
            'msg'=>$result->msg??null];
    } elseif ($spec['route'] === 'do_action') {
        define('ACHBOT_NP_RECONCILE_ONLY',true);
        require $root . '/src/Finance/GatewayReturn.php';
        $id=(int)($spec['pay_row_id']??0);
        $gate=(string)($spec['provider']??'nextpay');
        $GLOBALS['events'][]=['name'=>'do_action_result',
            'result'=>doAction($id,$gate)];
    } elseif ($spec['route'] === 'admin_review') {
        $data=(string)($spec['callback_data']??'npReviewInbox');
        $from_id=(int)($spec['actor']??9001);
        $chat_id=(int)($spec['chat_id']??9001);
        $message_id=8001;
        $callbackId='owned-admin-callback';
        $userInfo=['isAdmin'=>(bool)($spec['claimed_is_admin']??false)];
        // Prove that internal admin view cannot accidentally enter the
        // browser or IPN route on an unrelated webhook query string.
        $_GET=$spec['admin_get']??[];
        require $root . '/src/Finance/Routes/ManualReceipts.php';
        $GLOBALS['events'][]=['name'=>'admin_review_finished'];
    } elseif ($spec['route'] === 'return') {
        require $root . '/src/Finance/GatewayReturn.php';
    } elseif ($spec['route'] === 'initiate') {
        require $root . '/src/Finance/GatewayInitiation.php';
    } else {
        throw new RuntimeException('unknown route');
    }
} catch (Throwable $e) {
    $GLOBALS['error'] = get_class($e) . ': ' . $e->getMessage();
}
"""

def sql(work,env,query):
    return native.sql(work,env,query,DB)

def run_php(work, env, stage, spec):
    script=work/'owned-finance-guard.php'
    if not script.exists():
        script.write_text(PHP_DRIVER)
    cmd=['php','-n','-d','extension=mysqlnd','-d','extension=mysqli',
         '-d','extension=curl','-d','allow_url_fopen=0',
         '-d','disable_functions=curl_init,curl_setopt,curl_setopt_array,curl_exec,curl_close,curl_error,curl_getinfo,exec,shell_exec,system,passthru,popen,proc_open,mail',
         str(script),str(stage),DB,str(work/'mysql.sock'),json.dumps(spec)]
    p=subprocess.run(cmd,env=env,cwd=work,capture_output=True,timeout=20)
    claim_code=spec.get('expect_hosted_claim_code')
    expected_log = (
        f"achbot_hosted_claim_unconfirmed pay_id={spec['pay_row_id']} "
        f"provider={spec['provider']} code={claim_code}\n".encode()
        if claim_code else
        (f"achbot_hosted_service_effect_needs_review pay_id={spec['pay_row_id']} "
         f"provider={spec['provider']} code=postclaim_effect_exception\n"
         .encode() if spec.get('expect_local_error') else b''))
    if p.returncode or p.stderr != expected_log:
        raise RuntimeError('native finance php error: '+
                           repr((p.returncode,p.stdout[-300:],p.stderr[-600:])))
    try:
        result=json.loads(p.stdout)
    except json.JSONDecodeError as error:
        raise RuntimeError('native finance output not JSON: '+repr(p.stdout[-500:])) from error
    if (result['error'] or result['fatal']) and not spec.get('expect_local_error'):
        raise RuntimeError('native finance php runtime failure: '+
                           repr((result['error'],result['fatal']))[:850])
    if claim_code:
        result['protected_diagnostic']=p.stderr.decode('utf-8')
    return result

def seed_payment(work,env,name,type_name,plan=0,server=0,payid=None):
    invoice=payid if payid is not None else 'invoice-'+name
    query="""INSERT INTO pays
    (hash_id,payid,user_id,type,plan_id,server_id,volume,day,price,request_date,state)
    VALUES ('{}','{}',1001,'{}',{},{},0,0,100,{},'pending')""".format(
       name,invoice,type_name,plan,server,int(clock.time()))
    sql(work,env,query)
    return invoice

def seed_np_attempt(work,env,name,invoice,payment_id,*,default_fees=False):
    seed_payment(work,env,name,'INCREASE_WALLET',0,0,invoice)
    currency = 'NULL' if default_fees else "'usdttrc20'"
    floor = '0' if default_fees else '1'
    sql(work,env,
        "INSERT INTO payment_attempts "
        "(pay_row_id,provider,order_hash,provider_reference,payment_id,checkout_url,quote_amount,quote_currency,"
        "local_amount_toman,settlement_currency,minimum_settlement_amount) "
        f"SELECT id,'nowpayments',hash_id,'{invoice}','{payment_id}',"
        f"'https://nowpayments.io/pay/{invoice}',1,'usd',price,{currency},{floor} "
        f"FROM pays WHERE hash_id='{name}'")

def snapshot(work,env):
    return purchase.snapshot(work,env,DB)

def verify_unchanged(work,env,before,label):
    if snapshot(work,env)!=before:
        raise RuntimeError('Unexpected financial/account SQL mutation on '+label)

def main():
    p=argparse.ArgumentParser()
    p.add_argument('--run-disposable',required=True,action='store_true')
    p.add_argument('--report',type=Path)
    args=p.parse_args()
    records=[]
    with owned.owned_database() as (work,env):
        stage=work/'current-finance'
        stages.stage_source(stage,True)
        purchase.init(work,env,'http://127.0.0.1:9')
        sql(work,env,"INSERT INTO setting (type,value) VALUES ('BOT_STATES','{\"walletState\":\"on\",\"cartToCartState\":\"on\",\"giftCardState\":\"on\",\"manualUsdtState\":\"on\",\"nowPaymentWallet\":\"on\",\"nowPaymentOther\":\"on\",\"zarinpal\":\"on\",\"nextpay\":\"on\"}')")
        if len(sql(work,env,'SHOW TABLES').splitlines()) != 23:
            raise RuntimeError('Original schema missing')
        keys=json.dumps({'nowpayment':'SYNTHETIC-LOCAL-ONLY','nextpay':'SYNTHETIC-LOCAL-ONLY','zarinpal':'SYNTHETIC-LOCAL-ONLY','nowpayment_ipn_secret':'LOCAL-IPN-SECRET'})
        sql(work,env,"INSERT INTO setting (type,value) VALUES ('PAYMENT_KEYS','" + keys + "')")
        # Original 23 tables remain frozen; the new proposed attempt table
        # is layered on top ONLY after documenting the original table count.
        native.sql(work,env,(ROOT/'migrations/post-v9/003_payment_attempts.sql').read_text(),DB)
        assert len(sql(work,env,'SHOW TABLES').splitlines()) == 25

        seed_payment(work,env,'unsupported-now','RETIRED_PANEL',7,2)
        seed_payment(work,env,'marzban-now','BUY_SUB',7,2,'owned-verified-invoice')
        seed_payment(work,env,'wallet-now','INCREASE_WALLET',0,0,'wallet-verified-invoice')
        seed_payment(work,env,'unsupported-init','RETIRED_PANEL',7,2)
        seed_payment(work,env,'unsupported-zarin','RETIRED_PANEL',7,2)
        seed_payment(work,env,'unsupported-next','RETIRED_PANEL',7,2)
        seed_payment(work,env,'nohash-now','RETIRED_PANEL',7,2,'retired-fallback-id')
        seed_payment(work,env,'inbound-now','BUY_SUB',7,2,'inbound-invoice')
        seed_payment(work,env,'supported-nextpay','INCREASE_WALLET',0,0,'owned-nextpay-decline')
        seed_payment(work,env,'other-nextpay','INCREASE_WALLET',0,0,'owned-different-invoice')
        seed_payment(work,env,'owned-nextpay-decline','INCREASE_WALLET',0,0,'owned-other-nextpay-invoice')
        for name, spec in [
            ('retired_callback',dict(route='return',get={'nowpayment':'','NP_id':'owned-pmt','hash_id':'unsupported-now'})),
            ('unknown_callback',dict(route='return',get={'nowpayment':'','NP_id':'owned-pmt','hash_id':'no-such-pay'})),
            ('retired_fallback_no_hash',dict(route='return',get={'nowpayment':'','NP_id':'retired-fallback-id'})),
            ('retired_initiation',dict(route='initiate',get={'nowpayment':'','hash_id':'unsupported-init'})),
            ('retired_zarinpal_callback',dict(route='return',get={'zarinpal':'',
                     'hash_id':'unsupported-zarin','Authority':'owned-synthetic'})),
            ('retired_nextpay_callback',dict(route='return',get={'nextpay':'',
                     'trans_id':'invoice-unsupported-next'})),
        ]:
            before=snapshot(work,env)
            res=run_php(work,env,stage,spec)
            verify_unchanged(work,env,before,name)
            if res['network_calls'] or not res['form']:
                raise RuntimeError('Unsupported subject reached gateway or lacked error: '+name)
            records.append(dict(case=name,pass_=True,financial_mutations=0,external_requests=0))
        sql(work,env,'UPDATE server_plans SET inbound_id=7 WHERE id=7')
        before=snapshot(work,env)
        res=run_php(work,env,stage,dict(route='return',
                get={'nowpayment':'','NP_id':'owned-pmt','hash_id':'inbound-now'}))
        verify_unchanged(work,env,before,'forged_inbound_callback')
        if res['network_calls']:
            raise RuntimeError('Invalid Marzban inbound reached payment provider')
        records.append(dict(case='forged_inbound_callback',pass_=True,financial_mutations=0,external_requests=0))
        sql(work,env,'UPDATE server_plans SET inbound_id=0 WHERE id=7')
        sql(work,env,"UPDATE server_config SET type='normal' WHERE id=2")
        before=snapshot(work,env)
        res=run_php(work,env,stage,dict(route='return',
                get={'nowpayment':'','NP_id':'owned-pmt','hash_id':'marzban-now'}))
        verify_unchanged(work,env,before,'retired_provider_live_reference')
        if res['network_calls']:
            raise RuntimeError('Retired panel reached payment provider')
        records.append(dict(case='retired_provider_live_reference',pass_=True,financial_mutations=0,external_requests=0))
        sql(work,env,"UPDATE server_config SET type='marzban' WHERE id=2")
        # Legacy NOWPayments browser callbacks have NO stored USD/merchant
        # payout evidence and cannot be reconciled safely from NP_id alone.
        # Even a provider 'finished' reply must not be consulted for credit:
        # the entire legacy browser route is read-only and requires review.
        for status in ('waiting','confirming','confirmed','sending',
                       'partially_paid','refunded','finished','failed','expired'):
            name='legacy-nowpay-'+status
            invoice='legacy-'+status+'-invoice'
            seed_payment(work,env,name,'INCREASE_WALLET',0,0,invoice)
            before=snapshot(work,env)
            result=run_php(work,env,stage,dict(route='return',
                get={'nowpayment':'','NP_id':'np-'+status,'hash_id':name},
                mock_invoice=invoice,nowpayment_status=status,
                nowpayment_actual=0.01,nowpayment_price=999,
                nowpayment_outcome_currency='wrong-asset',
                nowpayment_outcome=0.0001))
            verify_unchanged(work,env,before,'legacy_'+status)
            if result['network_calls'] or 'بررسی' not in result['form']:
                raise RuntimeError('Legacy browser callback escaped safe review: '+status)
            records.append(dict(case='legacy_nowpayment_'+status+'_read_only',
                                pass_=True,financial_mutations=0,external_requests=0))
        seed_payment(work,env,'legacy-nowpay-duplicate-a','INCREASE_WALLET',0,0,'legacy-same-invoice')
        seed_payment(work,env,'legacy-nowpay-duplicate-b','INCREASE_WALLET',0,0,'legacy-same-invoice')
        for name in ('legacy-nowpay-duplicate-a','legacy-nowpay-duplicate-b'):
            before=snapshot(work,env)
            result=run_php(work,env,stage,dict(route='return',
                get={'nowpayment':'','NP_id':'legacy-same-invoice','hash_id':name},
                mock_invoice='legacy-same-invoice',nowpayment_status='finished'))
            verify_unchanged(work,env,before,'legacy_invoice_collision')
            if result['network_calls'] or not result['form']:
                raise RuntimeError('Ambiguous legacy invoice reached settlement')
            records.append(dict(case='legacy_nowpayment_duplicate_invoice_refused',
                                pass_=True,financial_mutations=0,external_requests=0))
        before=snapshot(work,env)
        browser=run_php(work,env,stage,dict(route='return',
            get={'nowpayment':'','hash_id':'wallet-now'}))
        verify_unchanged(work,env,before,'legacy_browser_return')
        if browser['network_calls'] or 'در حال بررسی' not in browser['form']:
            raise RuntimeError('Untrusted browser-only return declared settlement')
        records.append(dict(case='legacy_nowpayment_browser_only_read_only',
                            pass_=True,financial_mutations=0,external_requests=0))
        # This is a fresh positive payment journey: allocate the pays ID NOW,
        # after unrelated legacy invoice fixtures. Advancing request_date on
        # a lower ID cannot make it newest: customer-draft order is the ID
        # allocated under the owner lock, not the pre-lock PHP timestamp.
        # Keep the historical mocked invoices for return/reconciliation tests.
        seed_payment(work,env,'wallet-init','INCREASE_WALLET',0,0,'')
        sql(work,env,"UPDATE pays SET price=100000 WHERE hash_id='wallet-init'")
        before=snapshot(work,env)
        res=run_php(work,env,stage,dict(route='initiate',
                    get={'nowpayment':'','hash_id':'wallet-init'}))
        if len(res['network_calls'])!=1 or '/v1/invoice' not in res['network_calls'][0]:
            raise RuntimeError('Independent wallet top-up did not initiate mock invoice: '+repr(res)[:1000])
        if res['invoice_reservation_seen']!='creating':
            raise RuntimeError('External invoice was created before a durable local reservation')
        if sql(work,env,"SELECT payid FROM pays WHERE hash_id='wallet-init'").strip()!='owned-new-invoice':
            raise RuntimeError('Wallet top-up invoice ID was not stored')
        snapshot_policy=sql(work,env,
            "SELECT settlement_currency IS NULL,minimum_settlement_amount=0 "
            "FROM payment_attempts WHERE order_hash='wallet-init'").strip()
        if snapshot_policy!='1	1':
            raise RuntimeError('Default hosted invoice was not recorded without an optional payout floor')
        records.append(dict(case='nowpayments_default_fees_invoice_without_private_policy',
                            pass_=True,synthetic_invoice=True,external_requests=1))
        records.append(dict(case='positive_wallet_gateway_initiation',pass_=True,synthetic_invoice=True,external_requests=1))

        # The exact hosted checkout URL must be durable and resumable: a second
        # click cannot create or replace another external provider invoice.
        before=snapshot(work,env)
        second=run_php(work,env,stage,dict(route='initiate',
            get={'nowpayment':'','hash_id':'wallet-init'}))
        verify_unchanged(work,env,before,'nowpay-existing-checkout-resume')
        if second['network_calls']:
            raise RuntimeError('Opening existing checkout spawned another invoice')
        persisted=sql(work,env,"SELECT provider_reference,quote_amount,checkout_url FROM payment_attempts WHERE order_hash='wallet-init'").strip()
        if 'owned-new-invoice' not in persisted or 'nowpayments.io' not in persisted:
            raise RuntimeError('Checkout URL or quote not durable')
        records.append(dict(case='nowpayments_checkout_url_reused_without_new_invoice',
                            pass_=True,real_provider_requests=0))
        # Switching providers for the SAME order cannot overwrite an
        # existing NOWPayments invoice or issue a second request.
        for p in ('nextpay','zarinpal'):
            before=snapshot(work,env)
            refused=run_php(work,env,stage,dict(route='initiate',
                get={p:'','hash_id':'wallet-init'}))
            verify_unchanged(work,env,before,'now_to_'+p)
            if refused['network_calls'] or sql(work,env,
               "SELECT payid FROM pays WHERE hash_id='wallet-init'").strip()!='owned-new-invoice':
                raise RuntimeError('NOWPayments switched to '+p)
            records.append(dict(case='gateway_now_to_'+p+'_blocked',pass_=True))
        # Reverse order: NextPay and Zarinpal claim payid before external
        # requests, preventing another gateway from issuing a new invoice.
        for p in ('nextpay','zarinpal'):
            name='switch-'+p
            seed_payment(work,env,name,'INCREASE_WALLET',0,0,'initial')
            sql(work,env,f"UPDATE pays SET price=100000,payid=NULL WHERE hash_id='{name}'")
            issued=run_php(work,env,stage,dict(route='initiate',get={p:'','hash_id':name}))
            if len(issued['network_calls'])!=1:
                raise RuntimeError('Expected first '+p+' provider issuance')
            external=sql(work,env,f"SELECT payid FROM pays WHERE hash_id='{name}'").strip()
            if not external.startswith('next-' if p=='nextpay' else 'zarin-'):
                raise RuntimeError('Original '+p+' identity was not preserved')
            attempt=sql(work,env,f"SELECT provider,stage,provider_reference,quote_currency,"
                f"quote_amount,local_amount_toman FROM payment_attempts WHERE order_hash='{name}'").strip()
            expected=f"{p}\tpending\t{external}\ttoman\t100000.00000000\t100000"
            if attempt!=expected:
                raise RuntimeError('Common ledger did not durably record '+p+': '+repr(attempt))
            records.append(dict(case='gateway_'+p+'_shared_ledger_identity_and_quote',
                                pass_=True,external_requests=1))
            before=snapshot(work,env)
            refused=run_php(work,env,stage,dict(route='initiate',
                get={'nowpayment':'','hash_id':name}))
            verify_unchanged(work,env,before,'switch_'+p+'_now')
            if refused['network_calls'] or sql(work,env,
               f"SELECT payid FROM pays WHERE hash_id='{name}'").strip()!=external:
                raise RuntimeError('Competing NOWPayments invoice after '+p)
            records.append(dict(case='gateway_'+p+'_to_now_blocked',pass_=True))
        # Shared persistence is provider-scoped: the SAME external reference
        # and SAME child payment ID may be legitimately reused by two
        # different processors. They must never collide or cross-link.
        for provider in ('nextpay','zarinpal'):
            name='ledger-scope-'+provider
            seed_payment(work,env,name,'INCREASE_WALLET',0,0,'shared-external-identity')
            sql(work,env,f"INSERT INTO payment_attempts "
                f"(pay_row_id,provider,order_hash,provider_reference,payment_id,"
                f"quote_amount,quote_currency,local_amount_toman,stage) "
                f"SELECT id,'{provider}',hash_id,'shared-external-identity',"
                f"'shared-payment-identity',100,'toman',100,'pending' FROM pays "
                f"WHERE hash_id='{name}'")
            attempt_id=int(sql(work,env,f"SELECT id FROM payment_attempts "
                f"WHERE order_hash='{name}'").strip())
            sql(work,env,f"INSERT INTO payment_transactions "
                f"(attempt_id,provider,payment_id,observed_status) "
                f"VALUES ({attempt_id},'{provider}','shared-child-payment','pending')")
        if sql(work,env,"SELECT COUNT(*) FROM payment_attempts "
               "WHERE provider_reference='shared-external-identity'").strip()!='2':
            raise RuntimeError('Provider-scoped checkout identities collided')
        if sql(work,env,"SELECT COUNT(*) FROM payment_transactions "
               "WHERE payment_id='shared-child-payment'").strip()!='2':
            raise RuntimeError('Provider-scoped child payment IDs collided')
        n_id=int(sql(work,env,"SELECT id FROM payment_attempts "
            "WHERE order_hash='ledger-scope-nextpay'").strip())
        try:
            sql(work,env,f"INSERT INTO payment_transactions "
                f"(attempt_id,provider,payment_id,observed_status) "
                f"VALUES ({n_id},'zarinpal','mismatched-provider-child','pending')")
        except Exception:
            pass  # Composite FK must prevent a cross-provider attachment.
        else:
            raise RuntimeError('Wrong provider attached transaction to attempt')
        if sql(work,env,"SELECT COUNT(*) FROM payment_transactions "
               "WHERE payment_id='mismatched-provider-child'").strip()!='0':
            raise RuntimeError('Provider mismatch was persisted')
        records.append(dict(case='shared_ledger_composite_provider_uniqueness_and_fk',
                            pass_=True,real_provider_requests=0))
        # Run REAL handler logic against two different providers sharing the
        # same external reference. Old pays.payid is ambiguous, so only the
        # provider-scoped, verified ledger row may be resolved. No real HTTP.
        scoped_wallet_start=int(sql(work,env,'SELECT wallet FROM users WHERE userid=1001').strip())
        negative=run_php(work,env,stage,dict(route='return',
            get={'nextpay':'','trans_id':'shared-external-identity'},
            nextpay_code=-2))
        if (len(negative['network_calls'])!=1 or
            sql(work,env,"SELECT state FROM pays WHERE hash_id='ledger-scope-nextpay'").strip()!='canceled' or
            sql(work,env,"SELECT state FROM pays WHERE hash_id='ledger-scope-zarinpal'").strip()!='pending' or
            int(sql(work,env,'SELECT wallet FROM users WHERE userid=1001').strip())!=scoped_wallet_start):
            raise RuntimeError('Provider-scoped NextPay callback selected the wrong order')
        records.append(dict(case='nextpay_callback_provider_scoped_colliding_external_reference',
                            pass_=True,external_requests=1))
        positive=run_php(work,env,stage,dict(route='return',
            get={'zarinpal':'','hash_id':'ledger-scope-zarinpal',
                 'Authority':'shared-external-identity','Status':'OK'},
            zarin_status=100))
        if (sql(work,env,"SELECT state FROM pays WHERE hash_id='ledger-scope-zarinpal'").strip()!='paid' or
            sql(work,env,"SELECT state FROM pays WHERE hash_id='ledger-scope-nextpay'").strip()!='canceled' or
            int(sql(work,env,'SELECT wallet FROM users WHERE userid=1001').strip())!=scoped_wallet_start+100):
            raise RuntimeError('Provider-scoped Zarinpal callback selected the wrong order')
        records.append(dict(case='zarinpal_callback_provider_scoped_colliding_external_reference',
                            pass_=True,external_requests=1))
        # A gateway-neutral ledger cannot retroactively identify older
        # pays.payid rows. A scoped provider match with an unscoped historical
        # duplicate must remain in review, never silently settle that ID.
        for provider in ('nextpay','zarinpal'):
            reference='mixed-generation-ref-'+provider
            seed_payment(work,env,'legacy-ref-'+provider,
                         'INCREASE_WALLET',0,0,reference)
            name='scoped-ref-'+provider
            seed_payment(work,env,name,'INCREASE_WALLET',0,0,reference)
            sql(work,env,"INSERT INTO payment_attempts "
                "(pay_row_id,provider,order_hash,provider_reference,"
                "quote_amount,quote_currency,local_amount_toman,stage) "
                f"SELECT id,'{provider}',hash_id,'{reference}',"
                "100,'toman',100,'pending' FROM pays "
                f"WHERE hash_id='{name}'")
            before=snapshot(work,env)
            spec=(dict(route='return',get={'nextpay':'','trans_id':reference},
                       nextpay_code=0) if provider=='nextpay'
                  else dict(route='return',get={'zarinpal':'',
                       'hash_id':name,'Authority':reference,'Status':'OK'},
                       zarin_status=100))
            refused=run_php(work,env,stage,spec)
            verify_unchanged(work,env,before,'ambiguous_legacy_scoped_'+provider)
            if refused['network_calls'] or not refused['form']:
                raise RuntimeError('Mixed-generation '+provider+
                                   ' reference reached provider verification')
            records.append(dict(case=provider+'_legacy_scoped_id_collision_refused',
                                pass_=True,external_requests=0))
        # A legacy callback must not reclassify a duplicate ID belonging to
        # a different gateway with a new provider-scoped attempt.
        ref='legacy-nextpay-vs-scoped-zarin'
        seed_payment(work,env,'legacy-np-vs-zp','INCREASE_WALLET',0,0,ref)
        seed_payment(work,env,'scoped-zp-vs-np','INCREASE_WALLET',0,0,ref)
        sql(work,env,"INSERT INTO payment_attempts "
            "(pay_row_id,provider,order_hash,provider_reference,"
            "quote_amount,quote_currency,local_amount_toman,stage) "
            f"SELECT id,'zarinpal',hash_id,'{ref}',"
            "100,'toman',100,'pending' FROM pays "
            "WHERE hash_id='scoped-zp-vs-np'")
        before=snapshot(work,env)
        refused=run_php(work,env,stage,dict(route='return',
            get={'nextpay':'','trans_id':ref},nextpay_code=0))
        verify_unchanged(work,env,before,'legacy_nextpay_vs_new_zarinpal')
        if refused['network_calls'] or not refused['form']:
            raise RuntimeError('Ambiguous historical NextPay ID was accepted')
        records.append(dict(case='legacy_nextpay_vs_new_zarinpal_collision_refused',
                            pass_=True,external_requests=0))
        # Provider references and child IDs are opaque case-sensitive IDs.
        # The SAME gateway must be able to represent case-distinct identities.
        for name in ('upper','lower'):
            ref='CaseSensitiveABC' if name=='upper' else 'CaseSensitiveabc'
            child='ChildCaseABC' if name=='upper' else 'ChildCaseabc'
            seed_payment(work,env,'case-sensitive-'+name,
                         'INCREASE_WALLET',0,0,ref)
            sql(work,env,"INSERT INTO payment_attempts "
                "(pay_row_id,provider,order_hash,provider_reference,"
                "payment_id,quote_amount,quote_currency,local_amount_toman,stage) "
                f"SELECT id,'nextpay',hash_id,'{ref}','{child}',"
                "100,'toman',100,'pending' FROM pays "
                f"WHERE hash_id='case-sensitive-{name}'")
            aid=int(sql(work,env,"SELECT id FROM payment_attempts "
                f"WHERE order_hash='case-sensitive-{name}'").strip())
            sql(work,env,"INSERT INTO payment_transactions "
                f"(attempt_id,provider,payment_id) VALUES ({aid},'nextpay','{child}')")
        if sql(work,env,"SELECT COUNT(*) FROM payment_attempts "
               "WHERE provider='nextpay' AND provider_reference LIKE 'CaseSensitive%'").strip()!='2':
            raise RuntimeError('Case-distinct external provider references merged')
        if sql(work,env,"SELECT COUNT(*) FROM payment_transactions "
               "WHERE provider='nextpay' AND payment_id LIKE 'ChildCase%'").strip()!='2':
            raise RuntimeError('Case-distinct child payment IDs merged')
        records.append(dict(case='provider_identifiers_exact_case_persistence',
                            pass_=True,external_requests=0))
        # Even BEFORE exposing a newly issued provider URL, reject a token
        # already present in legacy unscoped pays rows and retain the durable
        # creating reservation for operator-led reconciliation.
        for provider in ('nextpay','zarinpal'):
            target='issuer-duplicate-'+provider
            ref=('next-' if provider=='nextpay' else 'zarin-')+target
            seed_payment(work,env,'legacy-issuer-'+provider,
                         'INCREASE_WALLET',0,0,ref)
            seed_payment(work,env,target,'INCREASE_WALLET',0,0,'unset')
            sql(work,env,f"UPDATE pays SET payid=NULL,price=100000 "
                         f"WHERE hash_id='{target}'")
            issued=run_php(work,env,stage,dict(route='initiate',
                            get={provider:'','hash_id':target}))
            attempt=sql(work,env,"SELECT provider,stage,"
                "COALESCE(provider_reference,'NULL') FROM payment_attempts "
                f"WHERE order_hash='{target}'").strip()
            bound=sql(work,env,f"SELECT payid FROM pays "
                f"WHERE hash_id='{target}'").strip()
            if (len(issued['network_calls'])!=1 or
                attempt!=provider+'\treview\tNULL' or
                bound==ref or not issued['form']):
                raise RuntimeError('New '+provider+
                                   ' checkout published a legacy-colliding ID: '+
                                   repr((attempt,bound,issued['network_calls'])))
            records.append(dict(case=provider+'_issuance_legacy_reference_collision_held',
                                pass_=True,external_requests=1))
        seed_payment(work,env,'switch-pending','INCREASE_WALLET',0,0,'old')
        sql(work,env,"UPDATE pays SET payid='achbot_nextpay_creating_999' WHERE hash_id='switch-pending'")
        before=snapshot(work,env)
        for p in ('nowpayment','nextpay','zarinpal'):
            refused=run_php(work,env,stage,dict(route='initiate',
                get={p:'','hash_id':'switch-pending'}))
            verify_unchanged(work,env,before,'switch_inflight_'+p)
            if refused['network_calls']:
                raise RuntimeError('Pending provider claim allowed double issuance')
        records.append(dict(case='all_gateway_switches_blocked_on_inflight_claim',pass_=True))
        # A second click during a first provider POST must not trigger another
        # POST. Only the initial request may own the durable 'creating' row.
        seed_payment(work,env,'np-creating','INCREASE_WALLET',0,0,'unused-id')
        sql(work,env,"UPDATE pays SET price=100000,payid=NULL WHERE hash_id='np-creating'")
        sql(work,env,"INSERT INTO payment_attempts "
            "(pay_row_id,provider,order_hash,quote_amount,quote_currency,stage) "
            "SELECT id,'nowpayments',hash_id,1,'usd','creating' FROM pays WHERE hash_id='np-creating'")
        before=snapshot(work,env)
        in_progress=run_php(work,env,stage,dict(route='initiate',
            get={'nowpayment':'','hash_id':'np-creating'}))
        verify_unchanged(work,env,before,'nowpay_in_flight_reservation')
        if in_progress['network_calls']:
            raise RuntimeError('Overlapping checkout issued a duplicate provider invoice')
        records.append(dict(case='nowpayments_inflight_reservation_no_double_invoice',
                            pass_=True,external_requests=0))

        # HTTP timeouts can occur AFTER the provider created an invoice. The
        # local reservation must enter review, not permit a second POST.
        seed_payment(work,env,'np-create-timeout','INCREASE_WALLET',0,0,'unused-id')
        sql(work,env,"UPDATE pays SET price=100000,payid=NULL WHERE hash_id='np-create-timeout'")
        timed_out=run_php(work,env,stage,dict(route='initiate',
            get={'nowpayment':'','hash_id':'np-create-timeout'},
            invoice_transport_failure=True))
        if len(timed_out['network_calls'])!=1 or timed_out['invoice_reservation_seen']!='creating':
            raise RuntimeError('Timeout test did not reserve before ambiguous provider POST')
        if sql(work,env,"SELECT stage FROM payment_attempts "
               "WHERE order_hash='np-create-timeout'").strip()!='review':
            raise RuntimeError('Ambiguous invoice creation was not quarantined')
        before=snapshot(work,env)
        repeated=run_php(work,env,stage,dict(route='initiate',
            get={'nowpayment':'','hash_id':'np-create-timeout'}))
        verify_unchanged(work,env,before,'nowpay_ambiguous_invoice_retry')
        if repeated['network_calls'] or not sql(work,env,
           "SELECT IFNULL(payid,'') FROM pays WHERE hash_id='np-create-timeout'").strip().startswith('achbot_nowpayment_creating_'):
            raise RuntimeError('Provider timeout lost its durable provider claim or reissued')
        records.append(dict(case='nowpayments_unknown_creation_prevents_orphan_reissue',
                            pass_=True,external_requests=1,real_provider_requests=0))
        # A timed-out invoice creation or a stalled reservation does NOT
        # demonstrate received funds. No finance alert may be sent for
        # either technical case, regardless of its age or retry cadence.
        sql(work,env,"UPDATE payment_attempts SET "
            "created_at=NOW()-INTERVAL 25 MINUTE "
            "WHERE order_hash='np-creating'")
        absent=run_php(work,env,stage,dict(route='alert',get={}))
        if absent['events'][-1]['result']['examined']!=0 or any(
            e['name']=='sendMessage' for e in absent['events']):
            raise RuntimeError('Unpaid invoice/stalled creation generated a finance alert')
        records.append(dict(case='nowpayments_technical_creation_no_finance_alert',
                            pass_=True,real_provider_requests=0))

        # A durable, positively evidenced provider claim with uncertain
        # delivery is actionable. Verify send failure/retry and no daily
        # reminder; the native fixture never issues a real payment.
        seed_np_attempt(work,env,'np-funded-review','np-funded-review-invoice',
                        'np-funded-review-primary')
        sql(work,env,"UPDATE payment_attempts SET stage='review',"
            "provider_status='fulfillment_unconfirmed' "
            "WHERE order_hash='np-funded-review'")
        sql(work,env,"INSERT INTO payment_transactions "
            "(attempt_id,provider,payment_id,observed_status) "
            "SELECT id,'nowpayments',payment_id,'finished' "
            "FROM payment_attempts WHERE order_hash='np-funded-review'")
        failed_notice=run_php(work,env,stage,dict(route='alert',get={},
            alert_send_failure=True))
        if (failed_notice['events'][-1]['result']['failed']!=1 or
            sql(work,env,"SELECT review_notified_at IS NULL "
                "FROM payment_attempts WHERE order_hash='np-funded-review'").strip()!='1'):
            raise RuntimeError('Failed funded-case notification was not retryable')
        notices=run_php(work,env,stage,dict(route='alert',get={}))
        sent=[e for e in notices['events'] if e['name']=='sendMessage']
        if notices['events'][-1]['result']['notified']!=1 or len(sent)!=1 or (
            'پرداخت نیازمند بررسی مالی' not in sent[0]['args'][0] or
            '🆔' not in sent[0]['args'][0] or
            'مبلغ سفارش' not in sent[0]['args'][0] or
            'npReviewCase_' not in sent[0]['args'][1] or
            sent[0]['args'][2]!='HTML' or sent[0]['args'][3]!=9001):
            raise RuntimeError('Actionable Persian review alert was incomplete')
        again=run_php(work,env,stage,dict(route='alert',get={}))
        if again['events'][-1]['result']['notified'] or any(
            e['name']=='sendMessage' for e in again['events']):
            raise RuntimeError('Operator received a duplicate notice for unchanged evidence')
        records.append(dict(case='nowpayments_evidenced_review_persian_inbox_retry_once',
                            pass_=True,real_provider_requests=0))
        records.append(dict(case='nowpayments_review_without_24_hour_resends',
                            pass_=True,real_provider_requests=0))
        admin_case=int(sql(work,env,
            "SELECT id FROM payment_attempts "
            "WHERE order_hash='np-funded-review'").strip())
        admin_identity={'route':'admin_review','get':{},'chat_id':9001,
                        'actor':9001}
        before=snapshot(work,env)
        inbox=run_php(work,env,stage,dict(admin_identity,
            callback_data='npReviewInbox'))
        cards=[e for e in inbox['events'] if e['name']=='editText']
        if (len(cards)!=1 or 'مرکز بررسی پرداخت' not in cards[0]['args'][1]
            or ('npReviewCase_'+str(admin_case)) not in cards[0]['args'][2]):
            raise RuntimeError('Admin inbox has no actionable case selector')
        details=run_php(work,env,stage,dict(admin_identity,
            callback_data='npReviewCase_'+str(admin_case),
            admin_get={'nowpayment_ipn':''}))
        cards=[e for e in details['events'] if e['name']=='editText']
        if (len(cards)!=1 or
            'شناسه تلگرام' not in cards[0]['args'][1] or
            'مبلغ سفارش' not in cards[0]['args'][1] or
            'npReviewVerify_'+str(admin_case) not in cards[0]['args'][2] or
            details['network_calls']):
            raise RuntimeError('Admin review detail leaked/ran provider action')
        for actor,chat,claim in [(1001,1001,True),(9001,1001,False)]:
            unauthorized=run_php(work,env,stage,dict(admin_identity,
                actor=actor,chat_id=chat,claimed_is_admin=claim,
                callback_data='npReviewCase_'+str(admin_case)))
            if (any(x['name']=='editText' for x in unauthorized['events']) or
                unauthorized['network_calls'] or
                not any(x['name']=='callback' for x in unauthorized['events'])):
                raise RuntimeError('Unauthorized actor accessed finance case')
        verified=run_php(work,env,stage,dict(admin_identity,
            callback_data='npReviewVerify_'+str(admin_case),
            mock_invoice='np-funded-review-invoice',
            nowpayment_payment_id='np-funded-review-primary',
            nowpayment_order_id='np-funded-review',
            nowpayment_status='finished'))
        verify_events=[e for e in verified['events'] if e['name']=='editText']
        if (len(verified['network_calls'])!=1 or
            '/v1/payment/np-funded-review-primary' not in verified['network_calls'][0] or
            len(verify_events)!=1 or
            'استعلام فقط' not in verify_events[0]['args'][1] or
            'مقدار گزارش‌شده' not in verify_events[0]['args'][1] or
            'خالص گزارش‌شده' not in verify_events[0]['args'][1]):
            raise RuntimeError('Read-only provider inquiry was not grounded')
        mismatch=run_php(work,env,stage,dict(admin_identity,
            callback_data='npReviewVerify_'+str(admin_case),
            mock_invoice='mismatched-provider-invoice',
            nowpayment_payment_id='np-funded-review-primary',
            nowpayment_order_id='np-funded-review',
            nowpayment_status='finished'))
        mismatch_cards=[e for e in mismatch['events'] if e['name']=='editText']
        if (len(mismatch_cards)!=1 or
            'پاسخ معتبر و منطبق دریافت نشد' not in mismatch_cards[0]['args'][1] or
            'مقدار گزارش‌شده' in mismatch_cards[0]['args'][1]):
            raise RuntimeError('Forged provider identity was displayed as money evidence')
        verify_unchanged(work,env,before,'admin_review_readonly_access')
        records.append(dict(case='nowpayments_operator_inbox_details_private_auth',
                            pass_=True,real_provider_requests=0))
        records.append(dict(case='nowpayments_operator_provider_inquiry_readonly',
                            pass_=True,real_provider_requests=0))
        records.append(dict(case='nowpayments_operator_unauthorized_callbacks_refused',
                            pass_=True,real_provider_requests=0))

        # Direct browser query with forged NP_id must NOT bypass the new
        # quote/attempt validation even when the fake provider returns finished.
        before=snapshot(work,env)
        forged_browser=run_php(work,env,stage,dict(route='return',
            get={'nowpayment':'','NP_id':'forged-provider-id','hash_id':'wallet-init'},
            mock_invoice='owned-new-invoice',nowpayment_status='finished'))
        verify_unchanged(work,env,before,'nowpay-new-attempt-browser-bypass')
        if forged_browser['network_calls']:
            raise RuntimeError('Browser bypassed IPN worker and called provider')
        records.append(dict(case='nowpayments_new_attempt_browser_bypass_closed',
                            pass_=True,external_requests=0))
        import hmac,hashlib
        def signed_ipn(spec, *, wrong=False):
            raw=json.dumps(spec,ensure_ascii=False,sort_keys=True,separators=(',',':'))
            signature=hmac.new(b'LOCAL-IPN-SECRET',raw.encode('utf-8'),hashlib.sha512).hexdigest()
            if wrong:signature='a'*128
            return dict(route='ipn',get={},ipn_raw=raw,
                        ipn_wrong_signature=signature)
        ipn={
          'payment_id':'np-pmt-wallet-init','invoice_id':'owned-new-invoice',
          'order_id':'wallet-init','payment_status':'confirmed',
          'price_amount':1,'price_currency':'usd',
          'pay_amount':1,'actually_paid':1,'pay_currency':'usdttrc20',
          'fee':{'withdrawalFee':'0.010','depositFee':'0.001'}
        }
        before=snapshot(work,env)
        spoof=run_php(work,env,stage,signed_ipn(ipn,wrong=True))
        verify_unchanged(work,env,before,'nowpay-ipn-invalid-signature')
        if spoof['events'][-1].get('code')!=401 or spoof['network_calls']:
            raise RuntimeError('Unsigned NOWPayments IPN was accepted')
        records.append(dict(case='nowpayments_invalid_signature_no_effect',
                            pass_=True,external_requests=0))
        forged=dict(ipn,order_id='not-wallet-init')
        before=snapshot(work,env)
        mismatch=run_php(work,env,stage,signed_ipn(forged))
        verify_unchanged(work,env,before,'nowpay-ipn-mismatched-order')
        if mismatch['events'][-1].get('code')!=409 or mismatch['network_calls']:
            raise RuntimeError('Signed IPN for unrelated order admitted')
        records.append(dict(case='nowpayments_authenticated_order_binding',
                            pass_=True,external_requests=0))
        before=snapshot(work,env)
        before_wallet=int(sql(work,env,'SELECT wallet FROM users WHERE userid=1001').strip())
        accepted=run_php(work,env,stage,signed_ipn(ipn))
        if accepted['events'][-1].get('code')!=200 or accepted['network_calls']:
            raise RuntimeError('Correct signed IPN not stored cheaply: '+repr(accepted)[:400])
        got=sql(work,env,"SELECT payment_id,provider_status,stage FROM payment_attempts WHERE order_hash='wallet-init'").strip()
        if 'np-pmt-wallet-init' not in got or 'confirmed' not in got:
            raise RuntimeError('IPN provider ID/status was not durable')
        if int(sql(work,env,'SELECT wallet FROM users WHERE userid=1001').strip())!=before_wallet:
            raise RuntimeError('IPN handler mutated a balance')
        records.append(dict(case='nowpayments_signed_ipn_durable_without_browser',
                            pass_=True,external_requests=0))
        again=run_php(work,env,stage,signed_ipn(ipn))
        if again['events'][-1].get('code')!=200:
            raise RuntimeError('Replay of same signed IPN not idempotent')
        wallet_start=int(sql(work,env,'SELECT wallet FROM users WHERE userid=1001').strip())
        intermediate=run_php(work,env,stage,dict(route='reconcile',get={},
            mock_invoice='owned-new-invoice',
            nowpayment_payment_id='np-pmt-wallet-init',
            nowpayment_order_id='wallet-init',
            nowpayment_status='confirmed',
            nowpayment_price=1,nowpayment_pay_amount=1,nowpayment_actual=1))
        if int(sql(work,env,'SELECT wallet FROM users WHERE userid=1001').strip())!=wallet_start:
            raise RuntimeError('Confirmed provider status prematurely credited wallet')
        if intermediate['events'][-1]['result']['pending']!=1:
            raise RuntimeError('Background confirmation not retained as pending: '+repr(intermediate)[-500:])
        records.append(dict(case='nowpayments_signed_confirmed_background_nonfinal',
                            pass_=True,external_requests=1))
        sql(work,env,"UPDATE payment_attempts SET last_checked_at=NOW()-INTERVAL 120 SECOND WHERE order_hash='wallet-init'")
        final=run_php(work,env,stage,dict(route='reconcile',get={},
            mock_invoice='owned-new-invoice',
            nowpayment_payment_id='np-pmt-wallet-init',
            nowpayment_order_id='wallet-init',
            nowpayment_status='finished',
            nowpayment_price=1,nowpayment_pay_amount=1,nowpayment_actual=1,
            nowpayment_outcome=0.98))
        if int(sql(work,env,'SELECT wallet FROM users WHERE userid=1001').strip())!=wallet_start+100000:
            raise RuntimeError('Finished IPN+Cron did not autonomously credit existing wallet path')
        if sql(work,env,"SELECT state FROM pays WHERE hash_id='wallet-init'").strip()!='paid':
            raise RuntimeError('Finished Cron did not mark exact order paid')
        confirmed_in_browser=run_php(work,env,stage,dict(route='return',
            get={'nowpayment':'','hash_id':'wallet-init'}))
        if confirmed_in_browser['network_calls'] or 'ثبت شده' not in confirmed_in_browser['form']:
            raise RuntimeError('Post-settlement browser return did not show read-only success')
        records.append(dict(case='nowpayments_browser_only_readonly_paid_status',
                            pass_=True,external_requests=0))
        if sql(work,env,"SELECT stage FROM payment_attempts WHERE order_hash='wallet-init'").strip()!='done':
            raise RuntimeError('Cron did not persist completed gateway attempt')
        if not any(x['name']=='sendMessage' and len(x['args'])>3 and x['args'][3]==1001
                   for x in final['events']):
            raise RuntimeError('Finished gateway was not delivered to Telegram recipient')
        records.append(dict(case='nowpayments_no_browser_return_final_telegram_credit',
                            pass_=True,real_provider_requests=0))
        records.append(dict(case='nowpayments_default_provider_fees_finished_without_return',
                            pass_=True,ordinary_fee_deducted=0.02))
        after=snapshot(work,env)
        run_php(work,env,stage,signed_ipn(dict(ipn,payment_status='finished')))
        duplicate=run_php(work,env,stage,dict(route='reconcile',get={},
            mock_invoice='owned-new-invoice',
            nowpayment_payment_id='np-pmt-wallet-init',
            nowpayment_order_id='wallet-init',
            nowpayment_status='finished'))
        verify_unchanged(work,env,after,'nowpay-finished-ipn-replay')
        if duplicate['network_calls']:
            raise RuntimeError('Finished replay attempted another API verification')
        records.append(dict(case='nowpayments_finished_webhook_replay_no_double_credit',
                            pass_=True,external_requests=0))
        # Owner-reported incident: a second provider payment *identifier*
        # with only waiting/unfunded status is NOT a second payment.
        # The exact signed IPN route must keep a completed first purchase
        # intact, without an actionable finance notice or duplicate credit.
        seed_np_attempt(work,env,'np-idle-child','np-idle-invoice',
                        'np-idle-primary')
        sql(work,env,"UPDATE pays SET state='paid' "
            "WHERE hash_id='np-idle-child'")
        sql(work,env,"UPDATE payment_attempts SET stage='done',"
            "provider_status='finished' WHERE order_hash='np-idle-child'")
        idle_payload={
            'payment_id':'np-idle-secondary','invoice_id':'np-idle-invoice',
            'order_id':'np-idle-child','payment_status':'waiting',
            'price_amount':1,'price_currency':'usd'
        }
        wallet_snapshot=sql(work,env,
            "SELECT wallet FROM users WHERE userid=1001").strip()
        idle=run_php(work,env,stage,signed_ipn(idle_payload))
        state=sql(work,env,"SELECT stage,provider_status "
            "FROM payment_attempts WHERE order_hash='np-idle-child'").strip()
        child=sql(work,env,"SELECT observed_status FROM payment_transactions "
            "WHERE payment_id='np-idle-secondary'").strip()
        if (idle['events'][-1]['code']!=200 or
            state!='done\tfinished' or child!='waiting' or
            sql(work,env,"SELECT wallet FROM users WHERE userid=1001").strip()
                !=wallet_snapshot):
            raise RuntimeError('Unfunded secondary child incorrectly reopened paid order')
        quiet=run_php(work,env,stage,dict(route='alert',get={}))
        if quiet['events'][-1]['result']['notified'] or any(
            e['name']=='sendMessage' for e in quiet['events']):
            raise RuntimeError('Unpaid secondary child caused finance notification')
        records.append(dict(case='nowpayments_second_unpaid_child_is_not_payment',
                            pass_=True,real_provider_requests=0))
        failed_child=run_php(work,env,stage,signed_ipn(dict(
            idle_payload,payment_status='failed')))
        if failed_child['events'][-1]['code']!=200 or sql(work,env,
            "SELECT stage FROM payment_attempts "
            "WHERE order_hash='np-idle-child'").strip()!='done' or sql(work,env,
            "SELECT observed_status FROM payment_transactions "
            "WHERE payment_id='np-idle-secondary'").strip()!='failed':
            raise RuntimeError('Failed unpaid secondary created fake finance case')
        records.append(dict(case='nowpayments_unpaid_failed_child_no_operator_case',
                            pass_=True,real_provider_requests=0))

        funding=run_php(work,env,stage,signed_ipn(dict(
            idle_payload,payment_status='confirming')))
        state=sql(work,env,"SELECT stage,provider_status "
            "FROM payment_attempts WHERE order_hash='np-idle-child'").strip()
        if funding['events'][-1]['code']!=200 or (
            state!='review\tadditional_payment'):
            raise RuntimeError('Authenticated later in-flight funds were ignored')
        stale=run_php(work,env,stage,signed_ipn(idle_payload))
        if (stale['events'][-1]['code']!=200 or
            sql(work,env,"SELECT observed_status FROM payment_transactions "
                "WHERE payment_id='np-idle-secondary'").strip()!='confirming'):
            raise RuntimeError('Out-of-order unpaid IPN erased in-flight evidence')
        records.append(dict(case='nowpayments_same_child_funds_reopen_once_no_downgrade',
                            pass_=True,real_provider_requests=0))
        finish=run_php(work,env,stage,signed_ipn(dict(
            idle_payload,payment_status='finished')))
        if (finish['events'][-1]['code']!=200 or
            sql(work,env,"SELECT observed_status FROM payment_transactions "
                "WHERE payment_id='np-idle-secondary'").strip()!='finished' or
            sql(work,env,"SELECT wallet FROM users WHERE userid=1001").strip()
                !=wallet_snapshot):
            raise RuntimeError('Secondary completed payment bypassed review/credited')
        actionable=run_php(work,env,stage,dict(route='alert',get={}))
        sent=[e for e in actionable['events'] if e['name']=='sendMessage']
        if actionable['events'][-1]['result']['notified']!=1 or len(sent)!=1 or (
            'پرداخت نیازمند بررسی مالی' not in sent[0]['args'][0]):
            raise RuntimeError('Funded secondary did not produce one admin inbox notice')
        after_alert=run_php(work,env,stage,dict(route='alert',get={}))
        if after_alert['events'][-1]['result']['notified'] or any(
            e['name']=='sendMessage' for e in after_alert['events']):
            raise RuntimeError('Unchanged funded secondary caused daily re-alert')
        records.append(dict(case='nowpayments_funded_secondary_one_actionable_alert',
                            pass_=True,real_provider_requests=0))

        # Repeated deposits create independent provider child IDs. Capture
        # all authenticated extra IDs durably before returning 200, without
        # crediting the original order again.
        paid_wallet=int(sql(work,env,'SELECT wallet FROM users WHERE userid=1001').strip())
        for name in ('np-extra-deposit','np-third-child','np-fourth-child'):
            extra=run_php(work,env,stage,signed_ipn(dict(ipn,
                payment_id=name,payment_status='finished')))
            if extra['events'][-1].get('code')!=200:
                raise RuntimeError('Valid extra child lacked durable acceptance: '+name)
        children=sql(work,env,"SELECT e.payment_id FROM payment_transactions e "
            "JOIN payment_attempts a ON a.id=e.attempt_id "
            "WHERE a.order_hash='wallet-init' AND e.payment_id<>a.payment_id ORDER BY e.payment_id").strip().splitlines()
        if children!=['np-extra-deposit','np-fourth-child','np-third-child']:
            raise RuntimeError('Distinct signed child identifiers were lost: '+repr(children))
        if sql(work,env,"SELECT stage,provider_status FROM payment_attempts "
               "WHERE order_hash='wallet-init'").strip()!='review\tadditional_payment':
            raise RuntimeError('Additional deposit not surfaced for operator review')
        if int(sql(work,env,'SELECT wallet FROM users WHERE userid=1001').strip())!=paid_wallet:
            raise RuntimeError('Additional child payment duplicated credit')
        records.append(dict(case='nowpayments_multiple_children_durably_stored',
                            pass_=True,external_requests=0))
        # A stored extra-child replay must neither duplicate credit nor
        # re-arm an operator alert that was already delivered. A genuinely
        # NEW later child may re-arm the alert to surface new financial data.
        sql(work,env,"UPDATE payment_attempts SET "
                     "review_notified_at='2026-10-16 01:00:00' "
                     "WHERE order_hash='wallet-init'")
        for replay_id in ('np-pmt-wallet-init','np-extra-deposit','np-third-child'):
            again=run_php(work,env,stage,signed_ipn(dict(ipn,
                payment_id=replay_id,payment_status='finished')))
            if again['events'][-1].get('code')!=200:
                raise RuntimeError('Replay of persisted child rejected: '+replay_id)
        if sql(work,env,"SELECT IFNULL(DATE_FORMAT(review_notified_at,'%Y-%m-%d %H:%i:%s'),'') "
           "FROM payment_attempts WHERE order_hash='wallet-init'").strip()!='2026-10-16 01:00:00':
            raise RuntimeError('Extra-child replay cleared already-sent operator notification')
        if int(sql(work,env,'SELECT wallet FROM users WHERE userid=1001').strip())!=paid_wallet:
            raise RuntimeError('Replay after extra deposits changed balance')
        records.append(dict(case='nowpayments_extra_child_replay_is_idempotent',
                            pass_=True,external_requests=0))
        new_child=run_php(work,env,stage,signed_ipn(dict(ipn,
            payment_id='np-fifth-child',payment_status='finished')))
        if new_child['events'][-1].get('code')!=200 or sql(work,env,
           "SELECT COUNT(*) FROM payment_transactions e "
           "JOIN payment_attempts a ON a.id=e.attempt_id "
           "WHERE a.order_hash='wallet-init'").strip()!='5':
            raise RuntimeError('New signed child was not durably retained')
        if sql(work,env,"SELECT review_notified_at IS NULL FROM payment_attempts "
           "WHERE order_hash='wallet-init'").strip()!='1':
            raise RuntimeError('New child receipt did not re-arm operator alert')
        records.append(dict(case='nowpayments_new_child_rearms_operator_notice',
                            pass_=True,external_requests=0))
        # Deterministically interleave a NEW signed child after an alert
        # worker has read N children but BEFORE it acknowledges Telegram.
        # Also simulate overlapping Cron: the nested worker must not send
        # the same leased snapshot.
        sql(work,env,"UPDATE payment_attempts SET "
            "review_notified_at=NULL,review_notice_claim_token=NULL,"
            "review_notice_claimed_at=NULL WHERE order_hash='wallet-init'")
        overlapping=run_php(work,env,stage,dict(route='alert',get={},
            alert_nested_worker=True,alert_inject_child='np-child-mid-notice',
            alert_inject_order='wallet-init',alert_inject_invoice='owned-new-invoice'))
        notice=[e for e in overlapping['events'] if e['name']=='alert_result']
        nested=[e for e in overlapping['events'] if e['name']=='nested_alert']
        injected=[e for e in overlapping['events'] if e['name']=='inflight_ipn']
        sends=[e for e in overlapping['events'] if e['name']=='sendMessage']
        if len(notice)!=1 or notice[0]['result']['notified']!=0 or len(nested)!=1 or (
           nested[0]['result']['notified']!=0) or len(injected)!=1 or (
           injected[0]['code']!=200) or len(sends)!=1:
            raise RuntimeError('Concurrent alert/child interleaving was not guarded: '+
                               repr(overlapping['events'])[:800])
        if sql(work,env,"SELECT review_notified_at IS NULL, "
           "review_notice_claim_token IS NULL FROM payment_attempts "
           "WHERE order_hash='wallet-init'").strip()!='1\t1':
            raise RuntimeError('Old alert hid a new signed child')
        latest=run_php(work,env,stage,dict(route='alert',get={}))
        sent=[e for e in latest['events'] if e['name']=='sendMessage']
        if latest['events'][-1]['result']['notified']!=1 or len(sent)!=1 or not (
            'شناسه‌های گزارش‌شده:</b> 6' in sent[0]['args'][0]):
            raise RuntimeError('New child was not notified after stale acknowledgment')
        quiet=run_php(work,env,stage,dict(route='alert',get={}))
        if quiet['events'][-1]['result']['notified'] or any(
           e['name']=='sendMessage' for e in quiet['events']):
            raise RuntimeError('Successful new child notice was not throttled')
        records.append(dict(case='nowpayments_concurrent_alert_child_highwater_cas',
                            pass_=True,external_requests=0))
        records.append(dict(case='nowpayments_concurrent_alert_worker_lease_exclusive',
                            pass_=True,external_requests=0))
        # A finished provider payment whose deposit is smaller than the
        # promised quote is quarantined, never auto-reconciled as successful.
        seed_payment(work,env,'np-underpaid','INCREASE_WALLET',0,0,'np-underpaid-invoice')
        sql(work,env,"INSERT INTO payment_attempts (pay_row_id,provider,order_hash,provider_reference,payment_id,checkout_url,quote_amount,quote_currency,local_amount_toman,settlement_currency,minimum_settlement_amount) "
            "SELECT id,'nowpayments',hash_id,'np-underpaid-invoice','np-underpaid-pmt','https://nowpayments.io/pay/np-underpaid',1,'usd',price,'usdttrc20',1 FROM pays WHERE hash_id='np-underpaid'")
        wallet_prior=int(sql(work,env,'SELECT wallet FROM users WHERE userid=1001').strip())
        review=run_php(work,env,stage,dict(route='reconcile',get={},
            mock_invoice='np-underpaid-invoice',
            nowpayment_payment_id='np-underpaid-pmt',
            nowpayment_order_id='np-underpaid',
            nowpayment_status='finished',
            nowpayment_price=1,nowpayment_pay_amount=1,nowpayment_actual=0.5))
        if int(sql(work,env,'SELECT wallet FROM users WHERE userid=1001').strip())!=wallet_prior:
            raise RuntimeError('Underpaid crypto gateway credited wallet')
        if sql(work,env,"SELECT stage FROM payment_attempts WHERE order_hash='np-underpaid'").strip()!='review':
            raise RuntimeError('Underpaid gateway did not enter reconciliation queue')
        records.append(dict(case='nowpayments_underpayment_quarantined',
                            pass_=True,external_requests=1))
        # NOWPayments partially_paid is a completed, underfunded child
        # payment. Do not credit or poll it indefinitely as pending.
        seed_payment(work,env,'np-partial-final','INCREASE_WALLET',0,0,
                     'np-partial-final-invoice')
        sql(work,env,"INSERT INTO payment_attempts "
            "(pay_row_id,provider,order_hash,provider_reference,payment_id,checkout_url,quote_amount,quote_currency,local_amount_toman,settlement_currency,minimum_settlement_amount) "
            "SELECT id,'nowpayments',hash_id,'np-partial-final-invoice','np-partial-child',"
            "'https://nowpayments.io/pay/np-partial-final',1,'usd',price,'usdttrc20',1 FROM pays "
            "WHERE hash_id='np-partial-final'")
        before_balance=int(sql(work,env,'SELECT wallet FROM users WHERE userid=1001').strip())
        partial=run_php(work,env,stage,dict(route='reconcile',get={},
            mock_invoice='np-partial-final-invoice',
            nowpayment_payment_id='np-partial-child',
            nowpayment_order_id='np-partial-final',
            nowpayment_status='partially_paid',
            nowpayment_price=1,nowpayment_pay_amount=1,nowpayment_actual=0.5))
        if len(partial['network_calls'])!=1 or sql(work,env,
           "SELECT stage FROM payment_attempts WHERE order_hash='np-partial-final'").strip()!='review':
            raise RuntimeError('Terminal partially_paid child payment was not quarantined')
        if int(sql(work,env,'SELECT wallet FROM users WHERE userid=1001').strip())!=before_balance:
            raise RuntimeError('Partial child incorrectly changed wallet')
        records.append(dict(case='nowpayments_terminal_partial_review_no_credit',
                            pass_=True,external_requests=1,real_provider_requests=0))
        # Once the provider has verified finished, an uncertain Marzban
        # create must NOT automatically refund, since the remote operation
        # may have succeeded despite an ambiguous transport response.
        seed_payment(work,env,'np-remote-uncertain','BUY_SUB',7,2,
                     'np-remote-uncertain-invoice')
        sql(work,env,"INSERT INTO payment_attempts "
            "(pay_row_id,provider,order_hash,provider_reference,payment_id,checkout_url,quote_amount,quote_currency,local_amount_toman,settlement_currency,minimum_settlement_amount) "
            "SELECT id,'nowpayments',hash_id,'np-remote-uncertain-invoice','np-remote-uncertain-payment',"
            "'https://nowpayments.io/payment/np-remote-uncertain',1,'usd',price,'usdttrc20',1 FROM pays "
            "WHERE hash_id='np-remote-uncertain'")
        wallet_before=int(sql(work,env,'SELECT wallet FROM users WHERE userid=1001').strip())
        remote_uncertain=run_php(work,env,stage,dict(route='reconcile',get={},
            mock_invoice='np-remote-uncertain-invoice',
            nowpayment_payment_id='np-remote-uncertain-payment',
            nowpayment_order_id='np-remote-uncertain',
            nowpayment_status='finished',nowpayment_price=1,
            nowpayment_pay_amount=1,nowpayment_actual=1))
        if not any(e['name']=='marzban_unknown_result' for e in remote_uncertain['events']):
            raise RuntimeError('Uncertain provider fixture did not reach Marzban create seam: '+repr(remote_uncertain)[:700]+' DB '+repr(sql(work,env,"SELECT a.stage,a.provider_status,p.state FROM payment_attempts a JOIN pays p ON p.id=a.pay_row_id WHERE a.order_hash='np-remote-uncertain'")))
        if sql(work,env,"SELECT stage FROM payment_attempts "
               "WHERE order_hash='np-remote-uncertain'").strip()!='review':
            raise RuntimeError('Uncertain remote create was not held for operator review')
        if int(sql(work,env,'SELECT wallet FROM users WHERE userid=1001').strip())!=wallet_before:
            raise RuntimeError('Uncertain remote Marzban operation triggered automatic wallet refund')
        if sql(work,env,"SELECT state FROM pays WHERE hash_id='np-remote-uncertain'").strip()!='paid':
            raise RuntimeError('Verified provider paid identity was lost on uncertain fulfillment')
        records.append(dict(case='nowpayments_uncertain_marzban_no_automatic_refund',
                            pass_=True,real_provider_requests=0,remote_spy=True))
        # NOWPayments invoices have no expiry; an IPN for a payment on an
        # older invoice is not itself stale. The child payment has its own
        # seven-day lifespan. The old created_at cutoff incorrectly stranded
        # a genuine finished payment before making any provider status request.
        seed_payment(work,env,'np-late-invoice','INCREASE_WALLET',0,0,
                     'np-late-invoice-id')
        sql(work,env,"INSERT INTO payment_attempts "
            "(pay_row_id,provider,order_hash,provider_reference,payment_id,checkout_url,quote_amount,quote_currency,local_amount_toman,settlement_currency,minimum_settlement_amount,created_at) "
            "SELECT id,'nowpayments',hash_id,'np-late-invoice-id','np-late-child-payment',"
            "'https://nowpayments.io/pay/np-late',1,'usd',price,'usdttrc20',1,"
            "DATE_SUB(NOW(), INTERVAL 12 DAY) FROM pays WHERE hash_id='np-late-invoice'")
        balance_before=int(sql(work,env,'SELECT wallet FROM users WHERE userid=1001').strip())
        late=run_php(work,env,stage,dict(route='reconcile',get={},
            mock_invoice='np-late-invoice-id',
            nowpayment_payment_id='np-late-child-payment',
            nowpayment_order_id='np-late-invoice',
            nowpayment_status='finished'))
        if len(late['network_calls'])!=1 or sql(work,env,
           "SELECT stage FROM payment_attempts WHERE order_hash='np-late-invoice'").strip()!='done':
            raise RuntimeError('Valid payment on older timeless invoice was stranded')
        if int(sql(work,env,'SELECT wallet FROM users WHERE userid=1001').strip())!=balance_before+100:
            raise RuntimeError('Late-invoice finished payment failed to credit wallet')
        records.append(dict(case='nowpayments_late_payment_on_timeless_invoice',
                            pass_=True,external_requests=1,real_provider_requests=0))
        # A provider 'finished' status must prove the exact net payout
        # asset and amount against the immutable per-order acceptance floor.
        for name,overrides,reason in [
            ('output_short',dict(nowpayment_outcome=0.75),'amount_mismatch'),
            ('output_fee_short',dict(nowpayment_outcome=0.98,
                nowpayment_pay_amount=1,nowpayment_actual=1),'amount_mismatch'),
            ('output_wrong_asset',dict(nowpayment_outcome_currency='btc'),'amount_mismatch'),
            ('output_zero',dict(nowpayment_outcome=0),'amount_mismatch'),
            ('output_wrong_quote',dict(nowpayment_price=2),'amount_mismatch'),
            ('input_short',dict(nowpayment_actual=0.3),'amount_mismatch'),
        ]:
            h='np-safety-'+name
            invoice=h+'-invoice'
            payid=h+'-payment'
            seed_np_attempt(work,env,h,invoice,payid)
            start_wallet=int(sql(work,env,'SELECT wallet FROM users WHERE userid=1001').strip())
            obs=run_php(work,env,stage,dict(route='reconcile',get={},
                mock_invoice=invoice,
                nowpayment_payment_id=payid,
                nowpayment_order_id=h,
                nowpayment_status='finished',**overrides))
            if len(obs['network_calls'])!=1:
                raise RuntimeError('Provider outcome fixture not exercised: '+name)
            if sql(work,env,f"SELECT stage,provider_status FROM payment_attempts WHERE order_hash='{h}'").strip()!='review\t'+reason:
                raise RuntimeError('Unsafe net merchant outcome was accepted: '+name)
            if sql(work,env,f"SELECT state FROM pays WHERE hash_id='{h}'").strip()!='pending' or int(sql(work,env,'SELECT wallet FROM users WHERE userid=1001').strip())!=start_wallet:
                raise RuntimeError('Unsafe finished outcome mutated funds: '+name)
            records.append(dict(case='nowpayments_'+name+'_needs_review',pass_=True))
        # Default-fees invoices must still refuse zero/missing payouts,
        # incomplete deposits and altered immutable original quotes.
        for name,overrides in [
            ('default_zero_payout',dict(nowpayment_outcome=0)),
            ('default_bad_asset',dict(nowpayment_outcome_currency='!bad')),
            ('default_underpaid',dict(nowpayment_actual=0.5)),
            ('default_pay_amount_zero',dict(nowpayment_pay_amount=0)),
            ('default_wrong_quote',dict(nowpayment_price=2)),
            ('default_missing_payout',dict(nowpayment_raw=json.dumps({
                'payment_id':'_REPLACE_', 'invoice_id':'_INVOICE_',
                'order_id':'_ORDER_', 'payment_status':'finished',
                'price_amount':1, 'price_currency':'usd',
                'pay_amount':1, 'actually_paid':1,
                'pay_currency':'usdttrc20', 'outcome_currency':'usdttrc20'
            }))),
        ]:
            h='np-default-'+name
            invoice=h+'-invoice'
            payid=h+'-payment'
            seed_np_attempt(work,env,h,invoice,payid,default_fees=True)
            if 'nowpayment_raw' in overrides:
                overrides['nowpayment_raw']=overrides['nowpayment_raw'].replace(
                    '_REPLACE_',payid).replace('_INVOICE_',invoice).replace('_ORDER_',h)
            wallet_before=int(sql(work,env,'SELECT wallet FROM users WHERE userid=1001').strip())
            seen=run_php(work,env,stage,dict(route='reconcile',get={},
                mock_invoice=invoice,nowpayment_payment_id=payid,
                nowpayment_order_id=h,nowpayment_status='finished',**overrides))
            if len(seen['network_calls'])!=1 or sql(work,env,
                f"SELECT stage,provider_status FROM payment_attempts "
                f"WHERE order_hash='{h}'").strip()!='review\tamount_mismatch':
                raise RuntimeError('Default-fees underpayment/invalid final data accepted: '+name)
            if int(sql(work,env,'SELECT wallet FROM users WHERE userid=1001').strip())!=wallet_before:
                raise RuntimeError('Invalid default-fees payment moved wallet: '+name)
            if sql(work,env,f"SELECT state FROM pays WHERE hash_id='{h}'").strip()!='pending':
                raise RuntimeError('Invalid default-fees payment finalized order: '+name)
            records.append(dict(case='nowpayments_'+name+'_needs_review',
                                pass_=True))
        # A previously strict invoice cannot be made lax by deleting only
        # its currency while retaining the positive immutable minimum.
        h='np-default-tampered-floor'
        seed_np_attempt(work,env,h,h+'-invoice',h+'-payment',default_fees=True)
        sql(work,env,"UPDATE payment_attempts SET minimum_settlement_amount=1 "
            "WHERE order_hash='np-default-tampered-floor'")
        seen=run_php(work,env,stage,dict(route='reconcile',get={},
            mock_invoice=h+'-invoice',nowpayment_payment_id=h+'-payment',
            nowpayment_order_id=h,nowpayment_status='finished'))
        if sql(work,env,"SELECT stage,provider_status FROM payment_attempts "
           "WHERE order_hash='np-default-tampered-floor'").strip()!='review\tamount_mismatch':
            raise RuntimeError('Incomplete nonzero-floor snapshot downgraded to default fees')
        records.append(dict(case='nowpayments_default_tampered_floor_does_not_downgrade',
                            pass_=True))
        # Changing pays.price after invoice issuance must never let a
        # previously valid USD quote settle a larger local Toman obligation.
        name='np-safety-local-price-change'
        inv=name+'-invoice'
        pid=name+'-payment'
        seed_np_attempt(work,env,name,inv,pid)
        sql(work,env,f"UPDATE pays SET price=999 WHERE hash_id='{name}'")
        before_wallet=int(sql(work,env,'SELECT wallet FROM users WHERE userid=1001').strip())
        drift=run_php(work,env,stage,dict(route='reconcile',get={},
            mock_invoice=inv,nowpayment_payment_id=pid,
            nowpayment_order_id=name,nowpayment_status='finished'))
        if sql(work,env,f"SELECT stage,provider_status FROM payment_attempts WHERE order_hash='{name}'").strip()!='review\tlocal_amount_changed':
            raise RuntimeError('Mutated local amount was not quarantined')
        if sql(work,env,f"SELECT state FROM pays WHERE hash_id='{name}'").strip()!='pending' or int(sql(work,env,'SELECT wallet FROM users WHERE userid=1001').strip())!=before_wallet:
            raise RuntimeError('Tampered local price was settled')
        records.append(dict(case='nowpayments_locked_local_toman_price',pass_=True))
        # Operator can use legacy-style hosted checkout without a manually
        # maintained NET payout floor. It must still persist an immutable
        # quote, merchant invoice and default-fees snapshot.
        seed_payment(work,env,'np-no-policy','INCREASE_WALLET',0,0,'no-policy')
        sql(work,env,"UPDATE pays SET price=100000,payid=NULL WHERE hash_id='np-no-policy'")
        no_policy=run_php(work,env,stage,dict(route='initiate',
            get={'nowpayment':'','hash_id':'np-no-policy'},
            fake_invoice_id='np-no-policy-invoice'))
        if len(no_policy['network_calls'])!=1 or '/v1/invoice' not in no_policy['network_calls'][0]:
            raise RuntimeError('Legacy-style default payout fees blocked invoice issuance')
        got=sql(work,env,"SELECT p.payid,a.quote_currency,"
            "a.settlement_currency IS NULL,a.minimum_settlement_amount=0 "
            "FROM pays p JOIN payment_attempts a ON a.pay_row_id=p.id "
            "WHERE p.hash_id='np-no-policy'").strip()
        if got!='np-no-policy-invoice\tusd\t1\t1':
            raise RuntimeError('Missing-policy default invoice has incorrect safety snapshot '+got)
        records.append(dict(case='nowpayments_no_payout_policy_issues_hosted_invoice',
                            pass_=True,synthetic_invoice=True))
        # Explicitly opted-in stricter policy remains usable and immutable;
        # malformed operator config must not downgrade to a zero-floor mode.
        seed_payment(work,env,'np-strict-policy','INCREASE_WALLET',0,0,'strict-policy')
        sql(work,env,"UPDATE pays SET price=100000,payid=NULL WHERE hash_id='np-strict-policy'")
        strict=run_php(work,env,stage,dict(route='initiate',
            get={'nowpayment':'','hash_id':'np-strict-policy'},
            fake_invoice_id='np-strict-policy-invoice',
            settlement_policy={'outcome_currency':'usdttrc20',
                               'minimum_outcome_per_usd':'1.000000'}))
        if len(strict['network_calls'])!=1 or sql(work,env,
           "SELECT settlement_currency,minimum_settlement_amount FROM payment_attempts "
           "WHERE order_hash='np-strict-policy'").strip()!='usdttrc20\t1.00000000':
            raise RuntimeError('Existing explicit payout-floor policy changed unexpectedly')
        records.append(dict(case='nowpayments_explicit_floor_remains_enforced',
                            pass_=True))
        seed_payment(work,env,'np-invalid-policy','INCREASE_WALLET',0,0,'invalid-policy')
        sql(work,env,"UPDATE pays SET price=100000,payid=NULL WHERE hash_id='np-invalid-policy'")
        invalid=run_php(work,env,stage,dict(route='initiate',
            get={'nowpayment':'','hash_id':'np-invalid-policy'},settlement_policy={}))
        if invalid['network_calls'] or sql(work,env,
            "SELECT IFNULL(payid,'') FROM pays WHERE hash_id='np-invalid-policy'").strip():
            raise RuntimeError('Malformed explicit operator payout policy became a default invoice')
        if not invalid['form'] or 'NOWPayments' in invalid['form'] or 'تسویه پذیرنده' in invalid['form']:
            raise RuntimeError('Operator-only payout settings leaked into customer checkout error')
        records.append(dict(case='nowpayments_malformed_optional_floor_rejected_and_private',
                            pass_=True))
        # Presence is not the same as non-null: explicitly configured NULL
        # must fail BEFORE any durable invoice reservation or provider call.
        seed_payment(work,env,'np-null-policy','INCREASE_WALLET',0,0,'null-policy')
        sql(work,env,"UPDATE pays SET price=100000,payid=NULL WHERE hash_id='np-null-policy'")
        before=snapshot(work,env)
        invalid_null=run_php(work,env,stage,dict(route='initiate',
            get={'nowpayment':'','hash_id':'np-null-policy'},
            settlement_policy=None))
        verify_unchanged(work,env,before,'explicit-null-policy')
        if invalid_null['network_calls'] or sql(work,env,
            "SELECT COUNT(*) FROM payment_attempts WHERE order_hash='np-null-policy'").strip()!='0' or sql(work,env,
            "SELECT IFNULL(payid,'') FROM pays WHERE hash_id='np-null-policy'").strip():
            raise RuntimeError('Explicit NULL policy created an invoice or checkout reservation')
        if (not invalid_null['form'] or
            'این روش پرداخت فعلاً در دسترس نیست' not in invalid_null['form'] or
            'NOWPayments' in invalid_null['form'] or
            'تسویه پذیرنده' in invalid_null['form']):
            raise RuntimeError('Explicit NULL policy exposed configuration details')
        records.append(dict(case='nowpayments_explicit_null_policy_fails_before_reservation',
                            pass_=True,external_requests=0))
        # RED regression: historical NextPay cancellation used hash_id=trans_id
        # even though the callback resolves the invoice through pays.payid.
        res=run_php(work,env,stage,dict(route='return',
                    get={'nextpay':'','trans_id':'owned-nextpay-decline'}))
        if len(res['network_calls'])!=1 or '/nx/gateway/verify' not in res['network_calls'][0]:
            raise RuntimeError('NextPay fixture failed to exercise synthetic negative verification')
        if sql(work,env,"SELECT state FROM pays WHERE hash_id='supported-nextpay'").strip()!='canceled':
            raise RuntimeError('NextPay failed verification did not cancel selected pending row')
        if sql(work,env,"SELECT state FROM pays WHERE hash_id='other-nextpay'").strip()!='pending':
            raise RuntimeError('NextPay cancellation modified unrelated payment')
        if sql(work,env,"SELECT state FROM pays WHERE hash_id='owned-nextpay-decline'").strip()!='pending':
            raise RuntimeError('NextPay incorrectly canceled unrelated row whose hash_id equals external payid')
        records.append(dict(case='nextpay_negative_verification_targets_row',
                            pass_=True,external_requests=1,real_provider_requests=0))
        before=snapshot(work,env)
        replay=run_php(work,env,stage,dict(route='return',
                    get={'nextpay':'','trans_id':'owned-nextpay-decline'}))
        verify_unchanged(work,env,before,'nextpay_declined_replay')
        if replay['network_calls']:
            raise RuntimeError('Already-canceled NextPay callback reached external verifier')
        records.append(dict(case='nextpay_canceled_replay_rejected',pass_=True,external_requests=0))
        seed_payment(work,env,'duplicate-nextpay-a','INCREASE_WALLET',0,0,'same-external-id')
        seed_payment(work,env,'duplicate-nextpay-b','INCREASE_WALLET',0,0,'same-external-id')
        before=snapshot(work,env)
        duplicate=run_php(work,env,stage,dict(route='return',
                     get={'nextpay':'','trans_id':'same-external-id'}))
        verify_unchanged(work,env,before,'nextpay_ambiguous_payid')
        if duplicate['network_calls'] or not duplicate['form']:
            raise RuntimeError('Ambiguous NextPay payid entered external verification path')
        records.append(dict(case='nextpay_duplicate_payid_fails_closed',pass_=True,external_requests=0))
        seed_payment(work,env,'already-paid-nextpay','INCREASE_WALLET',0,0,'processed-nextpay-id')
        sql(work,env,"UPDATE pays SET state='paid' WHERE hash_id='already-paid-nextpay'")
        before=snapshot(work,env)
        processed=run_php(work,env,stage,dict(route='return',
                   get={'nextpay':'','trans_id':'processed-nextpay-id'}))
        verify_unchanged(work,env,before,'nextpay_processed_invoice')
        if processed['network_calls']:
            raise RuntimeError('Previously processed NextPay invoice reached verifier')
        records.append(dict(case='nextpay_final_state_replay_rejected',pass_=True,external_requests=0))
        seed_payment(work,env,'cross-state-pending','INCREASE_WALLET',0,0,'cross-state-payid')
        seed_payment(work,env,'cross-state-paid','INCREASE_WALLET',0,0,'cross-state-payid')
        sql(work,env,"UPDATE pays SET state='paid' WHERE hash_id='cross-state-paid'")
        before=snapshot(work,env)
        collision=run_php(work,env,stage,dict(route='return',
                    get={'nextpay':'','trans_id':'cross-state-payid'}))
        verify_unchanged(work,env,before,'nextpay_cross_state_collision')
        if collision['network_calls']:
            raise RuntimeError('NextPay identifier collision across states reached verifier')
        records.append(dict(case='nextpay_cross_state_payid_collision_rejected',
                            pass_=True,external_requests=0))
        # NextPay documents -1 / -3 as pending and -2 / -4 as definitive
        # rejection/cancellation. Unknown/HTTP/transport errors must preserve
        # payment state rather than silently recording a cancellation.
        synthetic_nextpay_cases=[
            ('awaiting_payer',-1,200,None,False,'pending'),
            ('awaiting_bank',-3,200,None,False,'pending'),
            ('provider_unavailable',-42,200,None,False,'pending'),
            ('transport_error',-2,200,None,True,'pending'),
            ('bad_json',-2,200,'not-json',False,'pending'),
            ('http_503_denial',-2,503,None,False,'pending'),
            ('http_503_success',0,503,None,False,'pending'),
            ('confirmed_cancellation',-4,200,None,False,'canceled'),
        ]
        for name,code,http,raw,fail,want in synthetic_nextpay_cases:
            hash_id='nextpay-'+name
            invoice='nextpay-'+name+'-invoice'
            seed_payment(work,env,hash_id,'INCREASE_WALLET',0,0,invoice)
            before=snapshot(work,env)
            spec=dict(route='return',get={'nextpay':'','trans_id':invoice},
                      nextpay_code=code,nextpay_http=http,
                      nextpay_transport_failure=fail)
            if raw is not None: spec['nextpay_raw']=raw
            outcome=run_php(work,env,stage,spec)
            got=sql(work,env,f"SELECT state FROM pays WHERE hash_id='{hash_id}'").strip()
            if got!=want:
                raise RuntimeError(f'NextPay {name} changed state to {got}, expected {want}')
            if want=='pending': verify_unchanged(work,env,before,name)
            if len(outcome['network_calls'])!=1 or not outcome['form']:
                raise RuntimeError('NextPay mocked verification not exercised: '+name)
            records.append(dict(case='nextpay_'+name,pass_=True,
                                external_requests=1,real_provider_requests=0))
        # Positive NextPay success: execute the real current GatewayReturn
        # doAction() -> ReceiptHelpers.addToWallet() path on synthetic MariaDB.
        # This is ONE legitimate fulfillment check, not global idempotency.
        seed_payment(work,env,'nextpay-success','INCREASE_WALLET',0,0,'unique-success-transid')
        wallet_before=int(sql(work,env,'SELECT wallet FROM users WHERE userid=1001').strip())
        other_before=sql(work,env,"SELECT state FROM pays WHERE hash_id='other-nextpay'").strip()
        positive=run_php(work,env,stage,dict(route='return',
                    get={'nextpay':'','trans_id':'unique-success-transid'},nextpay_code=0))
        if sql(work,env,"SELECT state FROM pays WHERE hash_id='nextpay-success'").strip()!='paid':
            raise RuntimeError('Legitimate code-0 NextPay callback did not settle selected row')
        if int(sql(work,env,'SELECT wallet FROM users WHERE userid=1001').strip())!=wallet_before+100:
            raise RuntimeError('Legitimate NextPay code-0 wallet credit was not applied once')
        if sql(work,env,"SELECT state FROM pays WHERE hash_id='other-nextpay'").strip()!=other_before:
            raise RuntimeError('NextPay success touched an unrelated payment')
        if len(positive['network_calls'])!=1 or '/nx/gateway/verify' not in positive['network_calls'][0]:
            raise RuntimeError('Positive fake NextPay verification was not reached')
        if not any(event['name']=='sendMessage' and len(event['args'])>=4
                   and event['args'][3]==1001 for event in positive['events']):
            raise RuntimeError('Real NextPay wallet success did not emit a Telegram send spy')
        if 'موفقیت' not in positive['form']:
            raise RuntimeError('Real NextPay success did not generate success form')
        if positive['known_inherited_warnings']:
            raise RuntimeError('Gateway wallet success emitted PHP warning: '+
                               repr(positive['known_inherited_warnings']))
        records.append(dict(case='nextpay_http_200_code_zero_actual_wallet_fulfillment',
                            pass_=True,external_requests=1,real_provider_requests=0,
                            real_current_wallet_helper=True))

        # A verified payment must NOT become paid unless the matching wallet
        # was actually credited. Reproduce the inherited paid-before-credit
        # bug using an intentionally missing synthetic customer.
        seed_payment(work,env,'nextpay-missing-wallet-user','INCREASE_WALLET',0,0,
                     'missing-wallet-user-transid')
        sql(work,env,"UPDATE pays SET user_id=99999999 "
                     "WHERE hash_id='nextpay-missing-wallet-user'")
        before=snapshot(work,env)
        missing_user=run_php(work,env,stage,dict(route='return',
            get={'nextpay':'','trans_id':'missing-wallet-user-transid'},
            nextpay_code=0))
        verify_unchanged(work,env,before,'nextpay_missing_wallet_user_atomic_rollback')
        if len(missing_user['network_calls'])!=1 or not missing_user['form']:
            raise RuntimeError('Missing-wallet-user negative fixture was not exercised')
        records.append(dict(case='nextpay_missing_wallet_user_atomic_rollback',
                            pass_=True,external_requests=1,real_provider_requests=0))

        # Keep Owner-authorized negative wallet debt: a legitimate credit
        # offsets it without applying a zero floor or inventing debt removal.
        wallet_saved=int(sql(work,env,'SELECT wallet FROM users WHERE userid=1001').strip())
        sql(work,env,"UPDATE users SET wallet=-200 WHERE userid=1001")
        seed_payment(work,env,'nextpay-debt-offset','INCREASE_WALLET',0,0,
                     'nextpay-debt-offset-transid')
        run_php(work,env,stage,dict(route='return',
            get={'nextpay':'','trans_id':'nextpay-debt-offset-transid'},
            nextpay_code=0))
        if int(sql(work,env,'SELECT wallet FROM users WHERE userid=1001').strip())!=-100:
            raise RuntimeError('Valid gateway credit did not offset signed admin debt')
        if sql(work,env,"SELECT state FROM pays WHERE hash_id='nextpay-debt-offset'").strip()!='paid':
            raise RuntimeError('Debt-offset payment did not settle with wallet credit')
        records.append(dict(case='nextpay_signed_debt_offset_transaction',
                            pass_=True,external_requests=1,real_provider_requests=0))
        sql(work,env,f"UPDATE users SET wallet={wallet_saved} WHERE userid=1001")

        # Existing wallet INT must not overflow; failing the credit must
        # leave the payment pending, not falsely paid.
        sql(work,env,"UPDATE users SET wallet=2147483640 WHERE userid=1001")
        seed_payment(work,env,'nextpay-wallet-overflow','INCREASE_WALLET',0,0,
                     'nextpay-wallet-overflow-transid')
        before=snapshot(work,env)
        overflow=run_php(work,env,stage,dict(route='return',
            get={'nextpay':'','trans_id':'nextpay-wallet-overflow-transid'},
            nextpay_code=0))
        verify_unchanged(work,env,before,'nextpay_wallet_overflow_atomic_rollback')
        if len(overflow['network_calls'])!=1 or not overflow['form']:
            raise RuntimeError('Wallet-overflow negative fixture not exercised')
        records.append(dict(case='nextpay_wallet_overflow_atomic_rollback',
                            pass_=True,external_requests=1,real_provider_requests=0))
        sql(work,env,f"UPDATE users SET wallet={wallet_saved} WHERE userid=1001")

        # A zero-price wallet credit cannot be marked as a settled purchase.
        seed_payment(work,env,'nextpay-wallet-zero','INCREASE_WALLET',0,0,
                     'nextpay-wallet-zero-transid')
        sql(work,env,"UPDATE pays SET price=0 WHERE hash_id='nextpay-wallet-zero'")
        before=snapshot(work,env)
        zero=run_php(work,env,stage,dict(route='return',
            get={'nextpay':'','trans_id':'nextpay-wallet-zero-transid'},
            nextpay_code=0))
        verify_unchanged(work,env,before,'nextpay_zero_wallet_credit_fails_closed')
        if len(zero['network_calls'])!=1 or not zero['form']:
            raise RuntimeError('Zero wallet-credit negative fixture not exercised')
        records.append(dict(case='nextpay_zero_wallet_credit_fails_closed',
                            pass_=True,external_requests=1,real_provider_requests=0))

        # Mid-verification deterministic race: synthetic second-worker state
        # transition occurs INSIDE fake cURL, before this route attempts its
        # -2 cancellation compare-and-set. No threads or real gateway needed.
        seed_payment(work,env,'nextpay-race','INCREASE_WALLET',0,0,'nextpay-race-external')
        race_wallet=int(sql(work,env,'SELECT wallet FROM users WHERE userid=1001').strip())
        unrelated_state=sql(work,env,"SELECT state FROM pays WHERE hash_id='other-nextpay'").strip()
        raced=run_php(work,env,stage,dict(route='return',
                    get={'nextpay':'','trans_id':'nextpay-race-external'},
                    nextpay_code=-2,nextpay_flip_hash='nextpay-race',
                    nextpay_flip_state='paid'))
        if raced['race_flip_applied'] is not True:
            raise RuntimeError('Controlled in-flight NextPay state transition did not happen')
        if sql(work,env,"SELECT state FROM pays WHERE hash_id='nextpay-race'").strip()!='paid':
            raise RuntimeError('Stale negative verification overwrote concurrent final state')
        if sql(work,env,"SELECT state FROM pays WHERE hash_id='other-nextpay'").strip()!=unrelated_state:
            raise RuntimeError('Raced NextPay cancellation affected another payment')
        if int(sql(work,env,'SELECT wallet FROM users WHERE userid=1001').strip())!=race_wallet:
            raise RuntimeError('Stale negative verification changed the wallet')
        if 'وضعیت پرداخت تغییر کرده است' not in raced['form']:
            raise RuntimeError('Raced cancellation failed to surface changed-state message')
        if len(raced['network_calls'])!=1:
            raise RuntimeError('Controlled race did not execute exactly one fake verification')
        records.append(dict(case='nextpay_mid_verify_state_change_cas_preserves_paid',
                            pass_=True,external_requests=1,real_provider_requests=0))

        # Zarinpal: local order MUST bind the exact unique Authority before
        # contacting provider; stale/negative verification may never clobber
        # a parallel paid state.
        seed_payment(work,env,'zarin-wrong-authority','INCREASE_WALLET',0,0,'zarin-owned')
        before=snapshot(work,env)
        forged=run_php(work,env,stage,dict(route='return',
            get={'zarinpal':'','hash_id':'zarin-wrong-authority',
                 'Authority':'zarin-forged','Status':'OK'},zarin_status=100))
        verify_unchanged(work,env,before,'zarin_wrong_authority')
        if forged['network_calls']:
            raise RuntimeError('Zarinpal callback verified unrelated Authority')
        records.append(dict(case='zarinpal_mismatched_authority_refused',pass_=True))
        seed_payment(work,env,'zarin-duplicate-one','INCREASE_WALLET',0,0,'zarin-duplicated')
        seed_payment(work,env,'zarin-duplicate-two','INCREASE_WALLET',0,0,'zarin-duplicated')
        before=snapshot(work,env)
        duplicated=run_php(work,env,stage,dict(route='return',
            get={'zarinpal':'','hash_id':'zarin-duplicate-one',
                 'Authority':'zarin-duplicated','Status':'OK'},zarin_status=100))
        verify_unchanged(work,env,before,'zarin_duplicate_authority')
        if duplicated['network_calls']:
            raise RuntimeError('Ambiguous Zarinpal Authority entered provider verification')
        records.append(dict(case='zarinpal_duplicated_authority_refused',pass_=True))
        seed_payment(work,env,'zarin-negative','INCREASE_WALLET',0,0,'zarin-neg')
        negative=run_php(work,env,stage,dict(route='return',
            get={'zarinpal':'','hash_id':'zarin-negative','Authority':'zarin-neg',
                 'Status':'NOK'},zarin_status=52))
        if sql(work,env,"SELECT state FROM pays WHERE hash_id='zarin-negative'").strip()!='pending':
            raise RuntimeError('Unproven provider code 52 canceled an existing payment')
        if negative['network_calls']!=['zarinpal:verify']:
            raise RuntimeError('Zarinpal fake negative verification did not run')
        records.append(dict(case='zarinpal_unproven_52_retains_pending',pass_=True))
        seed_payment(work,env,'zarin-race','INCREASE_WALLET',0,0,'zarin-race-auth')
        race_wallet=int(sql(work,env,'SELECT wallet FROM users WHERE userid=1001').strip())
        race=run_php(work,env,stage,dict(route='return',
            get={'zarinpal':'','hash_id':'zarin-race','Authority':'zarin-race-auth',
                 'Status':'NOK'},zarin_status=52,zarin_flip_hash='zarin-race'))
        if not race['race_flip_applied'] or sql(work,env,
           "SELECT state FROM pays WHERE hash_id='zarin-race'").strip()!='paid':
            raise RuntimeError('Stale Zarinpal denial overwrote positive concurrent state')
        if int(sql(work,env,'SELECT wallet FROM users WHERE userid=1001').strip())!=race_wallet:
            raise RuntimeError('Zarinpal late negative changed wallet')
        if 'سفارش لغو نشد' not in race['form']:
            raise RuntimeError('Zarinpal unconfirmed result was not surfaced for review')
        records.append(dict(case='zarinpal_mid_verify_cas_preserves_paid',pass_=True))
        seed_payment(work,env,'zarin-success','INCREASE_WALLET',0,0,'zarin-good-auth')
        before_wallet=int(sql(work,env,'SELECT wallet FROM users WHERE userid=1001').strip())
        positive=run_php(work,env,stage,dict(route='return',
            get={'zarinpal':'','hash_id':'zarin-success','Authority':'zarin-good-auth',
                 'Status':'OK'},zarin_status=100))
        if sql(work,env,"SELECT state FROM pays WHERE hash_id='zarin-success'").strip()!='paid':
            raise RuntimeError('Zarinpal legitimate success not settled')
        if int(sql(work,env,'SELECT wallet FROM users WHERE userid=1001').strip())!=before_wallet+100:
            raise RuntimeError('Zarinpal wallet top-up did not credit atomically')
        records.append(dict(case='zarinpal_authenticated_positive_wallet_credit',pass_=True))

        # Status supplied by the browser is presentation metadata, not
        # settlement authority. A verified 100 must win over a forged NOK.
        seed_payment(work,env,'zarin-browser-nok-paid','INCREASE_WALLET',0,0,'zarin-nok-ok')
        funds=int(sql(work,env,'SELECT wallet FROM users WHERE userid=1001').strip())
        verified=run_php(work,env,stage,dict(route='return',
            get={'zarinpal':'','hash_id':'zarin-browser-nok-paid',
                 'Authority':'zarin-nok-ok','Status':'NOK'},zarin_status=100))
        if sql(work,env,"SELECT state FROM pays WHERE hash_id='zarin-browser-nok-paid'").strip()!='paid':
            raise RuntimeError('Browser NOK canceled provider-verifiable funds')
        if int(sql(work,env,'SELECT wallet FROM users WHERE userid=1001').strip())!=funds+100:
            raise RuntimeError('Provider-verified payment did not credit wallet')
        records.append(dict(case='zarinpal_browser_nok_provider_100_settled',pass_=True))
        for browser,provider_status in [('OK',101),('NOK',101),('OK',-11),('NOK',-11)]:
            name='zp-review-'+browser.lower()+'-'+str(provider_status).replace('-','m')
            seed_payment(work,env,name,'INCREASE_WALLET',0,0,name+'-auth')
            before=snapshot(work,env)
            disputed=run_php(work,env,stage,dict(route='return',
                get={'zarinpal':'','hash_id':name,'Authority':name+'-auth',
                     'Status':browser},zarin_status=provider_status))
            verify_unchanged(work,env,before,'zarin_unconfirmed_'+name)
            if disputed['network_calls']!=['zarinpal:verify'] or not disputed['form']:
                raise RuntimeError('Zarinpal provider ambiguity was not surfaced')
            records.append(dict(case='zarinpal_'+browser.lower()+'_provider_'+str(provider_status)+'_review',
                                pass_=True))
        for scenario,overrides in [
            ('transport',dict(zarin_throw=True)),
            ('malformed',dict(zarin_malformed=True))
        ]:
            name='zp-'+scenario
            seed_payment(work,env,name,'INCREASE_WALLET',0,0,name+'-auth')
            before=snapshot(work,env)
            unverified=run_php(work,env,stage,dict(route='return',
                get={'zarinpal':'','hash_id':name,'Authority':name+'-auth',
                     'Status':'OK'},**overrides))
            verify_unchanged(work,env,before,'zarin_'+scenario)
            if unverified['network_calls']!=['zarinpal:verify']:
                raise RuntimeError('Zarinpal failed transport was not executed')
            records.append(dict(case='zarinpal_'+scenario+'_preserves_pending',pass_=True))
        # SOAP numeric conversion must never transform "100.9", 1e2,
        # bool, float or a padded/alternate string into a trusted code 100.
        # Exercise the unchanged actual GatewayReturn callback + wallet SQL.
        zarin_strict_codes=[
            ('canonical_int',100,True),
            ('canonical_string','100',True),
            ('fractional_string','100.9',False),
            ('fractional_float',100.9,False),
            ('scientific_string','1e2',False),
            ('scientific_float',1e2,False),
            ('float_whole',100.0,False),
            ('boolean_true',True,False),
            ('boolean_false',False,False),
            ('null',None,False),
            ('plus_prefix','+100',False),
            ('zero_prefix','0100',False),
            ('space_prefix',' 100',False),
            ('decimal_string','100.0',False),
            ('status101',101,False),
            ('status52',52,False),
            ('negative52',-52,False)
        ]
        for label,code,should_settle in zarin_strict_codes:
            name='zpstrict'+label.replace('_','')
            seed_payment(work,env,name,'INCREASE_WALLET',0,0,name+'authority')
            before=snapshot(work,env)
            wallet_before=int(sql(work,env,'SELECT wallet FROM users WHERE userid=1001').strip())
            returned=run_php(work,env,stage,dict(route='return',
                get={'zarinpal':'','hash_id':name,'Authority':name+'authority',
                     'Status':'NOK'},zarin_status=code))
            if returned['network_calls']!=['zarinpal:verify']:
                raise RuntimeError('Strict SOAP code missed actual verification '+label)
            state=sql(work,env,f"SELECT state FROM pays WHERE hash_id='{name}'").strip()
            wallet_after=int(sql(work,env,'SELECT wallet FROM users WHERE userid=1001').strip())
            if should_settle:
                if state!='paid' or wallet_after!=wallet_before+100:
                    raise RuntimeError('Canonical trusted code rejected '+label)
            else:
                verify_unchanged(work,env,before,'zarinpal_strict_'+label)
                if state!='pending' or wallet_after!=wallet_before:
                    raise RuntimeError('Malformed SOAP code settled money '+label)
                if not returned['form'] or 'نیازمند تطبیق' not in returned['form']:
                    raise RuntimeError('Malformed SOAP code lacked review state '+label)
            records.append(dict(case='zarinpal_strict_status_'+label,
                                pass_=True,external_requests=1,real_provider_requests=0))

        # Execute the ACTUAL Marzban PHP PUT adapter, not a substitute
        # success flag: only a 2xx parsed UserResponse matching requested
        # account/expiry/traffic is verified remote success.
        for name,override,want in [
            ('valid_update',{},True),
            ('http_rejection',dict(marzban_http=422,
                marzban_raw='{"detail":"unacceptable"}'),False),
            ('http_503',dict(marzban_http=503),False),
            ('lost_response',dict(marzban_transport_failure=True),False),
            ('bad_json',dict(marzban_raw='not-json'),False),
            ('explicit_detail_200',dict(marzban_raw='{"detail":"no"}'),False),
            ('wrong_get_account',dict(marzban_username='other-account'),False),
            ('wrong_account_200',dict(marzban_raw=
                '{"username":"other","expire":1,"data_limit":1}'),False),
            ('wrong_amount_200',dict(marzban_raw=
                '{"username":"syntheticMarzban","expire":1,"data_limit":1}'),False),
        ]:
            before=snapshot(work,env)
            result=run_php(work,env,stage,dict(route='marzban_edit',get={},
                marzban_change={'remark':'syntheticMarzban','plus_day':2},
                **override))
            verify_unchanged(work,env,before,'marzban_adapter_'+name)
            effect=[x for x in result['events'] if x['name']=='marzban_edit_result']
            if len(effect)!=1 or effect[0]['success'] is not want:
                raise RuntimeError('Marzban PUT misclassified '+name+': '+repr(effect))
            if not want and effect[0]['uncertain'] is not True:
                raise RuntimeError('Marzban failed PUT lost uncertainty classification '+name)
            if not any('/api/user/syntheticMarzban' in v for v in result['network_calls']):
                raise RuntimeError('Real Marzban PUT adapter was not exercised: '+name)
            records.append(dict(case='marzban_put_'+name+'_native_contract',
                                pass_=True,real_provider_requests=0))
        for name,http,want in [('reset_ok',200,True),('reset_rejected',503,False)]:
            before=snapshot(work,env)
            res=run_php(work,env,stage,dict(route='marzban_edit',get={},
                marzban_reset_http=http,
                marzban_change={'remark':'syntheticMarzban','days':2,'volume':2}))
            verify_unchanged(work,env,before,'marzban_reset_'+name)
            out=[x for x in res['events'] if x['name']=='marzban_edit_result']
            if len(out)!=1 or out[0]['success'] is not want or not any(
                '/reset' in c for c in res['network_calls']):
                raise RuntimeError('Marzban reset/renew verification failed '+name)
            records.append(dict(case='marzban_reset_'+name+'_native_contract',pass_=True))

        # Real wallet credit helper (not a test-only SQL copy) must confirm
        # one updated user row before success or any Telegram compensation
        # notice. Explicit negative admin debt remains a valid balance.
        wallet_before=int(sql(work,env,'SELECT wallet FROM users WHERE userid=1001').strip())
        for name,user,amount in [
            ('user_missing',99999991,100),
            ('zero_amount',1001,0),
            ('negative_amount',1001,-10)
        ]:
            before=snapshot(work,env)
            fail=run_php(work,env,stage,dict(route='wallet_refund',get={},
                refund_user=user,refund_amount=amount))
            verify_unchanged(work,env,before,'compensate_'+name)
            if [x for x in fail['events'] if x['name']=='refund_result']!=[
               {'name':'refund_result','success':False}] or any(
                x['name']=='sendMessage' for x in fail['events']):
                raise RuntimeError('Failed compensation claimed successful credit: '+name)
            records.append(dict(case='wallet_compensation_'+name+'_no_claim',pass_=True))
        sql(work,env,"UPDATE users SET wallet=2147483640 WHERE userid=1001")
        before=snapshot(work,env)
        over=run_php(work,env,stage,dict(route='wallet_refund',get={},
            refund_user=1001,refund_amount=100))
        verify_unchanged(work,env,before,'refund_overflow')
        if over['events'][-1].get('success') is not False or any(
            x['name']=='sendMessage' for x in over['events']):
            raise RuntimeError('Wallet overflow falsely compensated')
        records.append(dict(case='wallet_compensation_overflow_no_claim',pass_=True))
        sql(work,env,"UPDATE users SET wallet=-150 WHERE userid=1001")
        credited=run_php(work,env,stage,dict(route='wallet_refund',get={},
            refund_user=1001,refund_amount=100))
        if credited['events'][-1].get('success') is not True or sql(work,env,
           'SELECT wallet FROM users WHERE userid=1001').strip()!='-50':
            raise RuntimeError('Durable valid refund could not offset signed admin debt')
        if not any(x['name']=='sendMessage' and x['args'][3]==1001 for x in credited['events']):
            raise RuntimeError('Successful refund did not notify beneficiary')
        records.append(dict(case='wallet_compensation_verified_credit_and_debt_offset',
                            pass_=True))
        sql(work,env,f"UPDATE users SET wallet={wallet_before} WHERE userid=1001")

        # Execute the REAL shared doAction renewal decision with only
        # its outgoing Marzban boundary mocked. A non-null success=false
        # must be an ambiguous fulfillment for ALL 3 payment providers.
        sql(work,env,"INSERT INTO orders_list "
           "(userid,token,transid,fileid,server_id,inbound_id,remark,uuid,"
           "protocol,expire_date,link,amount,status,date) VALUES "
           "(1001,'renew-test-token','',7,2,0,'renew-fixture',"
           "'renew-uuid','vless',2000000000,'[]',100,1,'synthetic')")
        oid=int(sql(work,env,"SELECT id FROM orders_list WHERE remark='renew-fixture'").strip())
        original_exp=sql(work,env,f"SELECT expire_date FROM orders_list WHERE id={oid}").strip()
        for provider in ('nowpayment','nextpay','zarinpal'):
            name='renewfail'+provider
            seed_payment(work,env,name,'RENEW_ACCOUNT',7,2,name+'-invoice')
            sql(work,env,f"UPDATE pays SET order_id={oid} WHERE hash_id='{name}'")
            if provider=='nowpayment':
                sql(work,env,
                  "INSERT INTO payment_attempts "
                  "(pay_row_id,provider,order_hash,provider_reference,payment_id,checkout_url,quote_amount,quote_currency,"
                  "local_amount_toman,settlement_currency,minimum_settlement_amount,stage) "
                  f"SELECT id,'nowpayments',hash_id,'{name}-invoice','{name}-payment',"
                  f"'https://nowpayments.io/pay/{name}',1,'usd',price,'usdttrc20',1,'fulfilling' "
                  f"FROM pays WHERE hash_id='{name}'")
            before_wallet=int(sql(work,env,'SELECT wallet FROM users WHERE userid=1001').strip())
            pay_id=int(sql(work,env,f"SELECT id FROM pays WHERE hash_id='{name}'").strip())
            failed=run_php(work,env,stage,dict(route='do_action',get={},
                pay_row_id=pay_id,provider=provider,renew_success=False))
            responses=[e for e in failed['events'] if e['name']=='do_action_result']
            if len(responses)!=1 or responses[0]['result']!='ambiguous' or not any(
               e['name']=='renewConfigOnServer' for e in failed['events']):
                raise RuntimeError('False remote renewal accepted for '+provider)
            if sql(work,env,f"SELECT expire_date FROM orders_list WHERE id={oid}").strip()!=original_exp:
                raise RuntimeError('Failed remote renewal extended local service '+provider)
            if int(sql(work,env,'SELECT wallet FROM users WHERE userid=1001').strip())!=before_wallet:
                raise RuntimeError('Failed remote renewal applied unsafe wallet refund '+provider)
            if any(e['name']=='sendMessage' for e in failed['events']):
                raise RuntimeError('Failed renewal announced false success '+provider)
            if provider=='nowpayment':
                # The direct doAction unit route skips the enclosing worker;
                # reflect the review handoff a real worker would perform.
                sql(work,env,f"UPDATE payment_attempts "
                             f"SET stage='review',provider_status='fulfillment_unconfirmed' "
                             f"WHERE order_hash='{name}' AND stage='fulfilling'")
            records.append(dict(case='renewal_'+provider+'_explicit_failure_is_ambiguous',
                                pass_=True,external_requests=0))

        # Stage C: a verified hosted charge and the existing 003 attempt
        # must atomically stage operator review BEFORE any uncertain Marzban
        # renewal. Unlike NOWPayments, NextPay/Zarinpal have no background
        # fulfillment worker; a pending attempt on an already-paid service
        # hides a financial recovery obligation.
        def seed_hosted_renewal(name,provider,stage_state='pending',
                                amount_adjust=0):
            seed_payment(work,env,name,'RENEW_ACCOUNT',7,2,name+'-invoice')
            sql(work,env,f"UPDATE pays SET order_id={oid} WHERE hash_id='{name}'")
            sql(work,env,
                "INSERT INTO payment_attempts "
                "(pay_row_id,provider,order_hash,provider_reference,checkout_url,"
                "quote_amount,quote_currency,local_amount_toman,stage) "
                f"SELECT id,'{provider}',hash_id,'{name}-invoice','https://fixture.invalid/{name}',"
                f"price,'toman',price+({amount_adjust}),'{stage_state}' "
                f"FROM pays WHERE hash_id='{name}'")
            return int(sql(work,env,
                f"SELECT id FROM pays WHERE hash_id='{name}'").strip())

        for provider in ('nextpay','zarinpal'):
            for outcome,remote_success,remote_exit,want_stage,want_reason in (
                ('remote_uncertain',False,False,'review','fulfillment_started'),
                ('worker_stopped_at_remote',True,True,'review','fulfillment_started'),
                ('remote_and_local_success',True,False,'done','delivered'),
            ):
                name='hosted-'+provider+'-'+outcome
                pay_id=seed_hosted_renewal(name,provider)
                wallet_before=sql(work,env,
                    'SELECT wallet FROM users WHERE userid=1001').strip()
                first=run_php(work,env,stage,dict(route='do_action',get={},
                    pay_row_id=pay_id,provider=provider,
                    renew_success=remote_success,remote_exit=remote_exit))
                stage_after=sql(work,env,f"SELECT stage,provider_status "
                    f"FROM payment_attempts WHERE pay_row_id={pay_id}").strip()
                pay_after=sql(work,env,
                    f"SELECT state FROM pays WHERE id={pay_id}").strip()
                observed=[e for e in first['events']
                          if e['name']=='hosted_remote_entry']
                if pay_after!='paid' or stage_after!=want_stage+'\t'+want_reason or (
                    len(observed)!=1 or observed[0]['stage']!='review' or
                    observed[0]['reason']!='fulfillment_started'):
                    raise RuntimeError('Hosted remote boundary not durably staged: '+
                                       repr((name,pay_after,stage_after,observed)))
                if (remote_exit and any(e['name']=='do_action_result'
                                       for e in first['events'])) or (
                    not remote_exit and [e['result'] for e in first['events']
                                        if e['name']=='do_action_result']!=
                    [ 'delivered' if remote_success else 'ambiguous' ]):
                    raise RuntimeError('Hosted gateway outcome not faithfully classified: '+name)
                if sql(work,env,'SELECT wallet FROM users WHERE userid=1001').strip()!=wallet_before:
                    raise RuntimeError('Hosted remote uncertainty changed local wallet')
                second=run_php(work,env,stage,dict(route='do_action',get={},
                    pay_row_id=pay_id,provider=provider,renew_success=True))
                if any(e['name']=='renewConfigOnServer' for e in second['events']):
                    raise RuntimeError('Previously claimed hosted service was remotely replayed')
                if sql(work,env,f"SELECT stage,provider_status FROM payment_attempts "
                    f"WHERE pay_row_id={pay_id}").strip()!=stage_after:
                    raise RuntimeError('Hosted claim/replay rewrote finality evidence')
                if remote_success and not remote_exit:
                    sql(work,env,f"UPDATE orders_list SET expire_date={original_exp} "
                        f"WHERE id={oid}")
                elif sql(work,env,f"SELECT expire_date FROM orders_list "
                             f"WHERE id={oid}").strip()!=original_exp:
                    raise RuntimeError('Failed hosted remote renewal extended local order')
                records.append(dict(case='hosted_'+provider+'_'+outcome+
                                    '_staged_before_remote_and_not_replayed',
                                    pass_=True,real_provider_requests=0))

            # An existing, differently owned 003 attempt, a provider-status
            # review, or a changed local quote cannot be interpreted as an
            # authorized provider success merely from a direct callback.
            for reason,attempt_provider,stage_state,amount_adjust in (
                ('wrong_provider','nowpayments','pending',0),
                ('preexisting_review',provider,'review',0),
                ('changed_quote',provider,'pending',1),
            ):
                name='hosted-guard-'+provider+'-'+reason
                seed_payment(work,env,name,'RENEW_ACCOUNT',7,2,name+'-invoice')
                sql(work,env,f"UPDATE pays SET order_id={oid} WHERE hash_id='{name}'")
                sql(work,env,
                    "INSERT INTO payment_attempts "
                    "(pay_row_id,provider,order_hash,provider_reference,quote_amount,"
                    "quote_currency,local_amount_toman,stage) "
                    f"SELECT id,'{attempt_provider}',hash_id,'{name}-invoice',"
                    f"price,'toman',price+({amount_adjust}),'{stage_state}' "
                    f"FROM pays WHERE hash_id='{name}'")
                pay_id=int(sql(work,env,
                    f"SELECT id FROM pays WHERE hash_id='{name}'").strip())
                before=snapshot(work,env)
                declined=run_php(work,env,stage,dict(route='do_action',get={},
                    pay_row_id=pay_id,provider=provider,renew_success=True,
                    expect_hosted_claim_code='attempt_mismatch'))
                verify_unchanged(work,env,before,'hosted_guard_'+reason)
                if any(e['name']=='renewConfigOnServer' for e in declined['events']):
                    raise RuntimeError('Invalid hosted ledger claim reached remote effect')
                records.append(dict(case='hosted_'+provider+'_'+reason+
                                    '_paid_claim_rolls_back',pass_=True))

        # F1 (author-separated review): provider identity and success were
        # verified before an atomic claim can fail. Both providers require
        # accurate customer guidance, a protected correlation log and an
        # unchanged SQL snapshot. Faults occur only in the native mock DB
        # boundary; no production fault switches and no real HTTP are used.
        for provider in ('nextpay','zarinpal'):
            for failure,reason in (
                ('stage_rejected','ledger_stage_failed'),
                ('schema_query_failed','schema_inspection_failed'),
                ('begin_rejected','transaction_begin_failed'),
            ):
                name='hv-'+('np' if provider=='nextpay' else 'zp')+'-'+{
                    'stage_rejected':'stage',
                    'schema_query_failed':'schema',
                    'begin_rejected':'begin',
                }[failure]
                pay_id=seed_hosted_renewal(name,provider)
                ref=name+'-invoice'
                get=({'nextpay':'','trans_id':ref} if provider=='nextpay'
                     else {'zarinpal':'','hash_id':name,
                           'Authority':ref,'Status':'OK'})
                trigger=f"owned_deny_hosted_stage_{provider}"
                if failure=='stage_rejected':
                    sql(work,env,f"CREATE TRIGGER {trigger} "
                        "BEFORE UPDATE ON payment_attempts FOR EACH ROW "
                        "SIGNAL SQLSTATE '45000' "
                        "SET MESSAGE_TEXT='sensitive_sql_error_not_for_logs'")
                try:
                    before=snapshot(work,env)
                    opts=dict(route='return',get=get,pay_row_id=pay_id,
                        provider=provider,mysqli_report_off=True,
                        nextpay_code=0,zarin_status=100,
                        expect_hosted_claim_code=reason,
                        fail_hosted_schema_query=(failure=='schema_query_failed'),
                        fail_hosted_begin=(failure=='begin_rejected'))
                    for attempt_number in (1,2):
                        denied=run_php(work,env,stage,opts)
                        verify_unchanged(work,env,before,
                            f"hosted_verified_unstaged_{provider}_{failure}_{attempt_number}")
                        if len(denied['network_calls'])!=1 or (
                            provider=='nextpay' and
                            '/nx/gateway/verify' not in denied['network_calls'][0]
                        ) or (provider=='zarinpal' and
                              denied['network_calls'][0]!='zarinpal:verify'):
                            raise RuntimeError('Provider verification not reached before failed claim')
                        if any(e['name'] in ('renewConfigOnServer','marzban_unknown_result')
                               for e in denied['events']):
                            raise RuntimeError('Failed hosted claim reached remote Marzban boundary')
                        if (not all(word in denied['form'] for word in
                              ('پرداخت','پشتیبانی','دوباره پرداخت نکنید')) or
                            'ثبت شده است' in denied['form'] or
                            'sensitive_sql_error_not_for_logs' in denied['form'] or
                            'database' in denied['form'].lower()):
                            raise RuntimeError('Verified unstaged hosted charge got wrong customer guidance')
                        if denied['protected_diagnostic']!=(
                            f"achbot_hosted_claim_unconfirmed pay_id={pay_id} "
                            f"provider={provider} code={reason}\n"):
                            raise RuntimeError('Hosted protected failure code lost correlation')
                    records.append(dict(case='hosted_'+provider+'_'+failure+
                        '_verified_charge_rollback_customer_log_and_replay',
                        pass_=True,real_provider_requests=0,
                        provider_verification_spied=True))
                finally:
                    if failure=='stage_rejected':
                        sql(work,env,f'DROP TRIGGER IF EXISTS {trigger}')

            # Once an earlier pre-remote claim failure has been repaired,
            # ONE new callback can durably claim and fulfill, then replay
            # must not run the remote effect a second time.
            recovering='hosted-verified-recover-once-'+provider
            pay_id=seed_hosted_renewal(recovering,provider)
            ref=recovering+'-invoice'
            get=({'nextpay':'','trans_id':ref} if provider=='nextpay'
                else {'zarinpal':'','hash_id':recovering,
                      'Authority':ref,'Status':'OK'})
            failed=run_php(work,env,stage,dict(route='return',get=get,
                pay_row_id=pay_id,provider=provider,mysqli_report_off=True,
                nextpay_code=0,zarin_status=100,
                fail_hosted_begin=True,
                expect_hosted_claim_code='transaction_begin_failed'))
            if any(e['name']=='renewConfigOnServer' for e in failed['events']):
                raise RuntimeError('Transient claim failure started a remote effect')
            succeeded=run_php(work,env,stage,dict(route='return',get=get,
                pay_row_id=pay_id,provider=provider,
                nextpay_code=0,zarin_status=100,renew_success=True))
            if len([e for e in succeeded['events']
                    if e['name']=='renewConfigOnServer'])!=1 or (
                sql(work,env,f"SELECT stage,provider_status FROM payment_attempts "
                    f"WHERE pay_row_id={pay_id}").strip()!='done\tdelivered'):
                raise RuntimeError('Recovered callback not fulfilled exactly once')
            replay=run_php(work,env,stage,dict(route='return',get=get,
                pay_row_id=pay_id,provider=provider,
                nextpay_code=0,zarin_status=100,renew_success=True))
            if any(e['name']=='renewConfigOnServer' for e in replay['events']):
                raise RuntimeError('Recovered hosted charge caused duplicate remote effect')
            sql(work,env,f"UPDATE orders_list SET expire_date={original_exp} "
                f"WHERE id={oid}")
            records.append(dict(case='hosted_'+provider+
                '_unstaged_recovery_claim_once_no_remote_replay',pass_=True))

        # Independent F1-R1 re-review: provider verification can be
        # successful BEFORE achbotPerformPaymentEffect reaches its initial
        # pending pays lookup. A nonthrowing prepare() failure there must not
        # masquerade as a durably recorded operator-review request.
        for provider in ('nextpay','zarinpal'):
            name='hp-'+('np' if provider=='nextpay' else 'zp')+'-read'
            pay_id=seed_hosted_renewal(name,provider)
            ref=name+'-invoice'
            get=({'nextpay':'','trans_id':ref} if provider=='nextpay'
                else {'zarinpal':'','hash_id':name,
                      'Authority':ref,'Status':'OK'})
            before=snapshot(work,env)
            for attempt_number in (1,2):
                interrupted=run_php(work,env,stage,dict(route='return',get=get,
                    pay_row_id=pay_id,provider=provider,
                    nextpay_code=0,zarin_status=100,mysqli_report_off=True,
                    fail_hosted_preclaim_pays_read=True,
                    expect_hosted_claim_code='preclaim_local_error'))
                verify_unchanged(work,env,before,
                    f"hosted_preclaim_read_{provider}_attempt_{attempt_number}")
                if len(interrupted['network_calls'])!=1 or (
                    provider=='nextpay' and
                    '/nx/gateway/verify' not in interrupted['network_calls'][0]
                ) or (provider=='zarinpal' and
                      interrupted['network_calls'][0]!='zarinpal:verify'):
                    raise RuntimeError('Positive provider verification absent for preclaim fault')
                if any(e['name']=='renewConfigOnServer' for e in interrupted['events']):
                    raise RuntimeError('Preclaim DB failure reached remote provisioning')
                if (not all(word in interrupted['form'] for word in
                     ('پرداخت','پشتیبانی','دوباره پرداخت نکنید')) or
                    'درخواست برای بررسی ثبت شده است' in interrupted['form'] or
                    'mysql' in interrupted['form'].lower() or
                    'marzban' in interrupted['form'].lower() or
                    'database' in interrupted['form'].lower() or
                    'Call to a member function' in interrupted['form']):
                    raise RuntimeError('Preclaim customer text falsely states durable review')
                want=(f"achbot_hosted_claim_unconfirmed pay_id={pay_id} "
                    f"provider={provider} code=preclaim_local_error\n")
                if interrupted['protected_diagnostic'] != want:
                    raise RuntimeError('Preclaim correlation/phase log incorrect')

            # One legitimate later callback is allowed after transient
            # preclaim failure, but the following replay cannot reprovision.
            success=run_php(work,env,stage,dict(route='return',get=get,
                pay_row_id=pay_id,provider=provider,
                nextpay_code=0,zarin_status=100,renew_success=True))
            if len([e for e in success['events']
                    if e['name']=='renewConfigOnServer'])!=1 or (
                sql(work,env,f"SELECT stage,provider_status FROM payment_attempts "
                    f"WHERE pay_row_id={pay_id}").strip()!='done\tdelivered'):
                raise RuntimeError('Recovered preclaim callback did not fulfill once')
            replay=run_php(work,env,stage,dict(route='return',get=get,
                pay_row_id=pay_id,provider=provider,
                nextpay_code=0,zarin_status=100,renew_success=True))
            if any(e['name']=='renewConfigOnServer' for e in replay['events']):
                raise RuntimeError('Recovered preclaim callback caused duplicate effect')
            sql(work,env,f"UPDATE orders_list SET expire_date={original_exp} "
                f"WHERE id={oid}")
            records.append(dict(case='hosted_'+provider+
                '_verified_preclaim_pays_read_failure_customer_log_recovery_no_replay',
                pass_=True,real_provider_requests=0))

        # If the remote renewal was accepted but the ORIGINAL local ledger
        # INSERT fails under MYSQLI_REPORT_OFF, a success page or 'done'
        # state is unsafe. Reproduce that post-remote boundary with one
        # disposable MariaDB trigger, never with a real provider call.
        write_fail_id=seed_hosted_renewal('hosted-increase-write-failure','nextpay')
        before_recorded=sql(work,env,'SELECT COUNT(*) FROM increase_order').strip()
        sql(work,env,"CREATE TRIGGER owned_deny_increase_insert "
            "BEFORE INSERT ON increase_order FOR EACH ROW "
            "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='owned_local_write_denial'")
        try:
            failure=run_php(work,env,stage,dict(route='do_action',get={},
                pay_row_id=write_fail_id,provider='nextpay',
                renew_success=True,mysqli_report_off=True,
                expect_local_error=True))
            persisted=sql(work,env,f"SELECT stage,provider_status FROM "
                f"payment_attempts WHERE pay_row_id={write_fail_id}").strip()
            if persisted!='review\tfulfillment_started':
                raise RuntimeError('Failed local hosted record falsely marked done: '+persisted)
            if sql(work,env,'SELECT COUNT(*) FROM increase_order').strip()!=before_recorded:
                raise RuntimeError('Local SQL failure unexpectedly inserted ledger record')
            if 'موفقیت' in failure['form'] or not any(e['name']=='renewConfigOnServer'
                for e in failure['events']):
                raise RuntimeError('Post-remote SQL error falsely announced success')
            records.append(dict(case='hosted_post_remote_local_insert_failure_requires_review',
                                pass_=True,real_provider_requests=0))
        finally:
            sql(work,env,'DROP TRIGGER IF EXISTS owned_deny_increase_insert')

        # The two original purchase helpers are shared with manual/wallet
        # routes. Under MYSQLI_REPORT_OFF they must not silently succeed if
        # the local order/capacity DML fails after a possible remote effect.
        for name,operation,table,verb in (
            ('insert_order','order_insert','orders_list','INSERT'),
            ('decrease_capacity','capacity_update','server_info','UPDATE'),
        ):
            trigger='owned_deny_'+name
            sql(work,env,f"CREATE TRIGGER {trigger} BEFORE {verb} ON {table} "
                "FOR EACH ROW SIGNAL SQLSTATE '45000' "
                "SET MESSAGE_TEXT='owned_local_write_denial'")
            try:
                before=snapshot(work,env)
                reject=run_php(work,env,stage,dict(route='local_purchase_write',
                    get={},operation=operation,mysqli_report_off=True))
                verify_unchanged(work,env,before,'local_purchase_'+name)
                results=[e for e in reject['events'] if e['name']=='local_write_result']
                if results!=[{'name':'local_write_result','status':'rejected',
                              'reason':('order_insert_unconfirmed' if operation=='order_insert'
                                        else 'server_capacity_update_unconfirmed')}]:
                    raise RuntimeError('Unconfirmed purchase write treated as success: '+
                                       repr((name,results)))
                records.append(dict(case='local_'+name+
                    '_mysqlireportoff_requires_confirmed_sql',pass_=True))
            finally:
                sql(work,env,f'DROP TRIGGER IF EXISTS {trigger}')

        # A remote renewal success cannot close the hosted attempt when the
        # original order expiry failed to update, even if the accounting
        # journal might otherwise accept an insertion.
        expiry_fail_id=seed_hosted_renewal('hosted-expiry-write-failure','zarinpal')
        sql(work,env,"CREATE TRIGGER owned_deny_expiry "
            "BEFORE UPDATE ON orders_list FOR EACH ROW "
            "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='owned_expiry_denial'")
        try:
            before_count=sql(work,env,'SELECT COUNT(*) FROM increase_order').strip()
            expiry=run_php(work,env,stage,dict(route='do_action',get={},
                pay_row_id=expiry_fail_id,provider='zarinpal',
                renew_success=True,mysqli_report_off=True,
                expect_local_error=True))
            status=sql(work,env,f"SELECT stage,provider_status FROM "
                f"payment_attempts WHERE pay_row_id={expiry_fail_id}").strip()
            if status!='review\tfulfillment_started' or (
                sql(work,env,'SELECT COUNT(*) FROM increase_order').strip()!=before_count
            ) or 'موفقیت' in expiry['form']:
                raise RuntimeError('Remote success with failed local expiry falsely settled')
            records.append(dict(case='hosted_expiry_update_failed_retains_review',
                                pass_=True,real_provider_requests=0))
        finally:
            sql(work,env,'DROP TRIGGER IF EXISTS owned_deny_expiry')

        # NOWPayments finished + known pre-provision capacity failure must
        # not mark compensated/done until the wallet refund actually commits.
        sql(work,env,"UPDATE server_info SET ucount=0 WHERE id=2")
        for name,missing_user,want_done in [
            ('np-compensation-missing-user',True,False),
            ('np-compensation-verified',False,True)
        ]:
            seed_np_attempt(work,env,name,name+'-invoice',name+'-payment')
            sql(work,env,f"UPDATE pays SET type='BUY_SUB',plan_id=7,server_id=2 "
                         f"WHERE hash_id='{name}'")
            if missing_user:
                sql(work,env,f"UPDATE pays SET user_id=99999991 WHERE hash_id='{name}'")
            # Existing unrelated pending test invoices must not consume
            # this deliberately bounded one-attempt reconciliation tick.
            sql(work,env,"UPDATE payment_attempts SET last_checked_at=NOW() "
                         f"WHERE order_hash<>'{name}' AND stage IN ('pending','checking')")
            before_wallet=int(sql(work,env,'SELECT wallet FROM users WHERE userid=1001').strip())
            run=run_php(work,env,stage,dict(route='reconcile',get={},
                mock_invoice=name+'-invoice',nowpayment_order_id=name,
                nowpayment_payment_id=name+'-payment',
                nowpayment_status='finished'))
            stage_status=sql(work,env,
                "SELECT a.stage,a.provider_status FROM payment_attempts a "
                f"WHERE a.order_hash='{name}'").strip()
            if want_done:
                if stage_status!='done\tcompensated':
                    raise RuntimeError('Verified wallet compensation was not marked done')
                if int(sql(work,env,'SELECT wallet FROM users WHERE userid=1001').strip())!=before_wallet+100:
                    raise RuntimeError('Verified capacity refund was not credited exactly once')
            else:
                if not stage_status.startswith('review\t') or int(sql(work,env,
                   'SELECT wallet FROM users WHERE userid=1001').strip())!=before_wallet:
                    raise RuntimeError('Missing-user refund falsely completed: '+stage_status)
                if any(e['name']=='sendMessage' and 'اضافه شد' in str(e['args']) for e in run['events']):
                    raise RuntimeError('Missing-user refund was falsely announced')
            records.append(dict(case='nowpayments_capacity_'+
                ('missing_user_never_done' if missing_user else 'verified_refund_done'),
                pass_=True,external_requests=1))
        sql(work,env,"UPDATE server_info SET ucount=10 WHERE id=2")

        # Someone with a leaked order hash cannot expire, redirect or
        # inspect a foreign customer's pending invoice.
        seed_payment(work,env,'foreignunclaimed','INCREASE_WALLET',0,0,'')
        original=snapshot(work,env)
        foreign=run_php(work,env,stage,dict(route='prepayment',get={},actor=9002,
            callback_data='prePayCheck_BUYSUB_zarinpal_foreignunclaimed'))
        verify_unchanged(work,env,original,'foreign_prepayment')
        if foreign['network_calls'] or not any(
                e['name']=='callback' for e in foreign['events']) or any(
                e['name']=='sendMessage' for e in foreign['events']):
            raise RuntimeError('Foreign actor obtained payment navigation')
        records.append(dict(case='prepayment_foreign_owner_denied',
                            pass_=True,external_requests=0))

        # A forged callback must not create a free/negative/oversized topup
        # nor silently erase the customer's prior unclaimed quote.
        for invalid in (-100000,0,50000,100001,10000001):
            old=snapshot(work,env)
            out=run_php(work,env,stage,dict(route='wallet_start',get={},
                                            topup_amount=invalid))
            verify_unchanged(work,env,old,'bad_topup_'+str(invalid))
            if out['network_calls'] or not any(
                    e['name']=='wallet_start_result' and e['status']=='invalid'
                    for e in out['events']) or any(
                    e['name']=='sendMessage' for e in out['events']):
                raise RuntimeError('Forged topup callback not rejected at persistence boundary')
        records.append(dict(case='topup_invalid_amount_denied_before_sql',
                            pass_=True,external_requests=0))

        # Stale buttons from OTHER gateways must not expire an issued
        # NOWPayments invoice or a still-unresolved creating reservation.
        seed_np_attempt(work,env,'staleinvoice','stale-invoice','stale-payment')
        for gateway in ('nextpay','zarinpal'):
            before=snapshot(work,env)
            selected=run_php(work,env,stage,dict(route='prepayment',get={},
                callback_data='prePayCheck_BUYSUB_'+gateway+'_staleinvoice'))
            verify_unchanged(work,env,before,'stale_alternative_'+gateway)
            if selected['network_calls'] or sql(work,env,
               "SELECT state,payid FROM pays WHERE hash_id='staleinvoice'").strip()!='pending\tstale-invoice':
                raise RuntimeError('Old gateway button expired or clobbered active invoice')
            # Updated #51 UX: never return a raw provider URL to a stale
            # competing gateway callback. The guarded bot-owned route can
            # explain already-identified payment without another charge.
            guard_urls=[]
            for e in selected['events']:
                if e['name']!='sendMessage' or len(e['args'])<2:
                    continue
                try:
                    keyboard=json.loads(e['args'][1])
                except (TypeError,ValueError):
                    continue
                guard_urls.extend(btn['url'] for row in keyboard.get('inline_keyboard',[])
                                  for btn in row if 'url' in btn)
            if not any(url.endswith('/pay/?nowpayment&hash_id=staleinvoice')
                       for url in guard_urls) or any(
                       'nowpayments.io/pay/' in url for url in guard_urls):
                raise RuntimeError('Stale gateway did not retain guarded invoice/status route')
            records.append(dict(case='prepayment_stale_'+gateway+'_preserves_np_invoice',
                                pass_=True,external_requests=0))
        signed=signed_ipn(dict(payment_id='stale-payment',
            invoice_id='stale-invoice',order_id='staleinvoice',
            payment_status='confirmed',price_amount=1,price_currency='usd'))
        stored=run_php(work,env,stage,signed)
        if stored['events'][-1].get('code')!=200:
            raise RuntimeError('Stale alternative gateway blocked later signed IPN')
        records.append(dict(case='prepayment_stale_button_allows_later_ipn',pass_=True))
        seed_payment(work,env,'stalecreating','INCREASE_WALLET',0,0,'unused')
        sql(work,env,"UPDATE pays SET payid='achbot_nowpayment_creating_100' "
                     "WHERE hash_id='stalecreating'")
        sql(work,env,"INSERT INTO payment_attempts "
            "(pay_row_id,provider,order_hash,quote_amount,quote_currency,stage) "
            "SELECT id,'nowpayments',hash_id,1,'usd','creating' FROM pays WHERE hash_id='stalecreating'")
        before=snapshot(work,env)
        pending=run_php(work,env,stage,dict(route='prepayment',get={},
            callback_data='prePayCheck_BUYSUB_nextpay_stalecreating'))
        verify_unchanged(work,env,before,'stale_creating_reservation')
        if pending['network_calls'] or sql(work,env,
           "SELECT state FROM pays WHERE hash_id='stalecreating'").strip()!='pending':
            raise RuntimeError('Old button canceled uncertain invoice issuance')
        records.append(dict(case='prepayment_stale_button_preserves_creating',pass_=True))

        # A stale RENEW_ACCOUNT button must never undo a provider-finalized
        # pays.state='paid', nor invalidate a reserved provider invoice.
        # Execute the actual prePayCheck route against the original MariaDB.
        sql(work,env,"INSERT INTO orders_list "
            "(userid,token,transid,fileid,server_id,inbound_id,remark,uuid,"
            "protocol,expire_date,link,amount,status,date) VALUES "
            "(1001,'callback-renew-token','',8,2,0,'callback-renew-mismatch',"
            "'callback-renew-uuid','vless',2000000000,'[]',100,1,'synthetic')")
        mismatch_oid=int(sql(work,env,
            "SELECT id FROM orders_list WHERE remark='callback-renew-mismatch'").strip())
        def mismatch_payment(name, status='pending', invoice=''):
            seed_payment(work,env,name,'RENEW_ACCOUNT',7,2,invoice)
            sql(work,env,f"UPDATE pays SET state='{status}', "
                f"order_id={mismatch_oid},request_date=UNIX_TIMESTAMP() "
                f"WHERE hash_id='{name}'")
        for name,status in [
            ('rnoldpaid','paid'),
            ('rnoldcanceled','canceled'),
            ('rnoldexpired','expired'),
            ('rnoldmismatch','mismatched_plan')
        ]:
            mismatch_payment(name,status)
            before=snapshot(work,env)
            stale=run_php(work,env,stage,dict(route='prepayment',get={},
                callback_data='prePayCheck_RENEWACCOUNT_nextpay_'+name))
            verify_unchanged(work,env,before,'renewal_terminal_stale_'+status)
            if stale['network_calls'] or any(
               'pay/?' in str(e) for e in stale['events']):
                raise RuntimeError('Terminal renewal offered competing checkout '+status)
            if not any(e['name']=='callback' for e in stale['events']):
                raise RuntimeError('Terminal renewal lacked early-state guard '+status)
            records.append(dict(case='prepayment_renewal_terminal_'+status+'_immutable',
                                pass_=True,external_requests=0))

        mismatch_payment('rnordinary')
        ordinary=run_php(work,env,stage,dict(route='prepayment',get={},
            callback_data='prePayCheck_RENEWACCOUNT_nextpay_rnordinary'))
        if sql(work,env,"SELECT state FROM pays WHERE hash_id='rnordinary'").strip()!='mismatched_plan':
            raise RuntimeError('Unclaimed renewal plan mismatch was not rejected')
        if not any('پلن فعلی' in str(e.get('args')) for e in ordinary['events']
                   if e['name']=='sendMessage'):
            raise RuntimeError('Safe plan mismatch did not inform the customer')
        if ordinary['network_calls']:
            raise RuntimeError('Mismatched unclaimed renewal contacted provider')
        records.append(dict(case='prepayment_renewal_unclaimed_mismatch_cas',
                            pass_=True,external_requests=0))

        mismatch_payment('rnmidrace')
        prior_balance=int(sql(work,env,'SELECT wallet FROM users WHERE userid=1001').strip())
        concurrent=run_php(work,env,stage,dict(route='prepayment',get={},
            callback_data='prePayCheck_RENEWACCOUNT_nextpay_rnmidrace',
            prepayment_flip_hash='rnmidrace'))
        if not concurrent['race_flip_applied'] or sql(work,env,
            "SELECT state FROM pays WHERE hash_id='rnmidrace'").strip()!='paid':
            raise RuntimeError('Concurrent settled payment overwritten by old renewal callback')
        if int(sql(work,env,'SELECT wallet FROM users WHERE userid=1001').strip())!=prior_balance:
            raise RuntimeError('Stale renewal race changed wallet balance')
        if any('پلن فعلی' in str(e.get('args')) for e in concurrent['events']
               if e['name']=='sendMessage'):
            raise RuntimeError('CAS-lost renewal incorrectly announced mismatch')
        records.append(dict(case='prepayment_renewal_pending_to_paid_midflight_cas',
                            pass_=True,external_requests=0))

        mismatch_payment('rnpayclaimed',invoice='zarin-old-authority')
        before=snapshot(work,env)
        claimed=run_php(work,env,stage,dict(route='prepayment',get={},
            callback_data='prePayCheck_RENEWACCOUNT_nextpay_rnpayclaimed'))
        verify_unchanged(work,env,before,'renewal_issued_external_authority')
        if claimed['network_calls'] or not any(
           'محفوظ' in str(e.get('args')) for e in claimed['events']
           if e['name']=='sendMessage'):
            raise RuntimeError('Issued provider Authority not retained for operator review')
        records.append(dict(case='prepayment_renewal_issued_external_claim_preserved',
                            pass_=True,external_requests=0))

        seed_np_attempt(work,env,'rnnpclaimed','rnnp-invoice','rnnp-payment')
        sql(work,env,"UPDATE pays SET type='RENEW_ACCOUNT',plan_id=7,"
            f"server_id=2,order_id={mismatch_oid},request_date=UNIX_TIMESTAMP() "
            "WHERE hash_id='rnnpclaimed'")
        np_identity=sql(work,env,"SELECT provider_reference,payment_id,stage "
            "FROM payment_attempts WHERE order_hash='rnnpclaimed'").strip()
        for gateway in ('nowpayment','zarinpal'):
            before=snapshot(work,env)
            retained=run_php(work,env,stage,dict(route='prepayment',get={},
                callback_data='prePayCheck_RENEWACCOUNT_'+gateway+'_rnnpclaimed'))
            verify_unchanged(work,env,before,'renewal_np_invoice_'+gateway)
            if sql(work,env,"SELECT provider_reference,payment_id,stage "
                "FROM payment_attempts WHERE order_hash='rnnpclaimed'").strip()!=np_identity:
                raise RuntimeError('Existing NOWPayments invoice/child evidence modified')
            if retained['network_calls'] or any(
               'pay/?' in str(e) or 'https://nowpayments.io/pay/' in str(e)
               for e in retained['events']):
                raise RuntimeError('Mismatched renewal offered an unsafe payable invoice')
            records.append(dict(case='prepayment_renewal_np_invoice_'+gateway+'_preserved',
                                pass_=True,external_requests=0))

        # Valid unchanged renewal retains the original redirect behavior.
        seed_payment(work,env,'rnstillvalid','RENEW_ACCOUNT',8,2,'')
        sql(work,env,f"UPDATE pays SET order_id={mismatch_oid},"
            "request_date=UNIX_TIMESTAMP() WHERE hash_id='rnstillvalid'")
        ready=run_php(work,env,stage,dict(route='prepayment',get={},
            callback_data='prePayCheck_RENEWACCOUNT_zarinpal_rnstillvalid'))
        redirect_markup=[
            json.loads(e['args'][1]) for e in ready['events']
            if e['name']=='sendMessage' and len(e['args'])>1
            and isinstance(e['args'][1],str)]
        if not any('pay/?zarinpal' in json.dumps(markup,ensure_ascii=False)
                   for markup in redirect_markup) or ready['network_calls']:
            raise RuntimeError('Valid renewal callback lost checkout compatibility')
        if sql(work,env,"SELECT state FROM pays WHERE hash_id='rnstillvalid'").strip()!='pending':
            raise RuntimeError('Valid renewal unexpectedly changed payment state')
        records.append(dict(case='prepayment_renewal_unchanged_plan_checkout_compatible',
                            pass_=True,external_requests=0))

        # Actual admin route, including old cached/forged callbacks, cannot
        # perform the inherited database-only server rewrite.
        native.sql(work,env,'''
        INSERT INTO orders_list
            (userid,token,transid,fileid,server_id,inbound_id,remark,uuid,
             protocol,expire_date,link,amount,status,date)
        VALUES (1001,'admin-callback-token','',7,2,0,'admin-fixture','id','vless',
                2000000000,'[]',100,1,'synthetic')''',DB)
        oid=int(sql(work,env,"SELECT id FROM orders_list WHERE remark='admin-fixture'").strip())
        sql(work,env,'''INSERT INTO server_plans
            (id,fileid,catid,server_id,inbound_id,acount,title,protocol,days,
             volume,type,price,descr,pic,active,step,date,custom_sni)
            SELECT 10,'10',catid,2,7,acount,'Unsupported inbound',protocol,
                   days,volume,type,price,descr,pic,active,step,date,custom_sni
            FROM server_plans WHERE id=7;
            INSERT INTO server_plans
            (id,fileid,catid,server_id,inbound_id,acount,title,protocol,days,
             volume,type,price,descr,pic,active,step,date,custom_sni)
            SELECT 11,'11',catid,3,0,acount,'Wrong server plan',protocol,
                   days,volume,type,price,descr,pic,active,step,date,custom_sni
            FROM server_plans WHERE id=7;''')
        old=purchase.block
        try:
            purchase.block=lambda kind,modern: (
               b"<?php\nrequire __DIR__ . '/src/Subscription/OrderManagement.php';\n"
               if kind=='order_management' else
               b"<?php\nrequire __DIR__ . '/src/Finance/Routes/RenewalPlan.php';\n"
               if kind=='external_renew' else old(kind,modern)
            )
            for name, data in [('admin_open_legacy_relocation',f'changeServerId_{oid}'),
                               ('admin_forged_location_change',f'selectServerId_{oid}_3')]:
                before=snapshot(work,env)
                obs=purchase.run(work,env,stage,DB,'order_management',
                                 dict(data=data,actor=9001),modern=True)
                verify_unchanged(work,env,before,name)
                if obs.get('exception') or obs.get('warnings'):
                    raise RuntimeError('Admin route runtime failure: '+repr(obs)[:650])
                if not any(event['name']=='alert' for event in obs['events']):
                    raise RuntimeError('Admin stale relocation callback had no error')
                records.append(dict(case=name,pass_=True,financial_mutations=0,external_requests=0))
            for name, data in [('admin_forged_inbound_plan',f'selectFileId_{oid}_10'),
                               ('admin_forged_cross_server_plan',f'selectFileId_{oid}_11')]:
                before=snapshot(work,env)
                obs=purchase.run(work,env,stage,DB,'order_management',
                                 dict(data=data,actor=9001),modern=True)
                verify_unchanged(work,env,before,name)
                if obs.get('exception') or obs.get('warnings'):
                    raise RuntimeError('Admin plan negative runtime failure: '+repr(obs)[:650])
                if not any(event['name']=='alert' for event in obs['events']):
                    raise RuntimeError('Forged plan selector had no visible rejection')
                records.append(dict(case=name,pass_=True,financial_mutations=0,external_requests=0))
            obs=purchase.run(work,env,stage,DB,'order_management',
                dict(data=f'changeFileId_{oid}_2',actor=9001),modern=True)
            if obs.get('exception') or obs.get('warnings'):
                raise RuntimeError('Marzban plan catalogue error: '+repr(obs)[:650])
            wire=json.dumps(obs['events'],ensure_ascii=False)
            if f'selectFileId_{oid}_10' in wire or f'selectFileId_{oid}_11' in wire:
                raise RuntimeError('Invalid plans leaked into administrative plan selector')
            if f'selectFileId_{oid}_7' not in wire:
                raise RuntimeError('Supported Marzban plan absent from admin selector')
            records.append(dict(case='admin_supported_plan_catalogue',pass_=True,external_requests=0))
            obs=purchase.run(work,env,stage,DB,'order_management',
                dict(data=f'selectFileId_{oid}_8',actor=9001),modern=True)
            if obs.get('exception') or obs.get('warnings'):
                raise RuntimeError('Supported same-server plan change error: '+repr(obs)[:650])
            if sql(work,env,f'SELECT fileid FROM orders_list WHERE id={oid}').strip()!='8':
                raise RuntimeError('Supported admin plan change was not committed')
            records.append(dict(case='admin_supported_plan_change',pass_=True,external_requests=0))
            for name,profile in [
                ('external_renew_other_server',{'marzban':True,'remark':'external-fixture',
                                                'uuid':'id','server_id':3}),
                ('external_renew_retired_identity',{'marzban':False,'remark':'external-fixture',
                                                   'uuid':'id','server_id':2})
            ]:
                profile_json=json.dumps(profile,separators=(',',':'))
                sql(work,env,"UPDATE users SET temp='"+profile_json+"' WHERE userid=1001")
                before=snapshot(work,env)
                obs=purchase.run(work,env,stage,DB,'external_renew',
                                 dict(data='sConfigRenewPlan7_0',actor=1001),modern=True)
                verify_unchanged(work,env,before,name)
                if obs.get('exception') or obs.get('warnings'):
                    raise RuntimeError('External renewal rejection error: '+repr(obs)[:650])
                if not any(event['name']=='alert' for event in obs['events']):
                    raise RuntimeError('Mismatched external account was accepted: '+name)
                records.append(dict(case=name,pass_=True,financial_mutations=0,external_requests=0))
            profile={'marzban':True,'remark':'external-fixture','uuid':'id','server_id':2}
            sql(work,env,"UPDATE users SET temp='"+json.dumps(profile,separators=(',',':'))+"' WHERE userid=1001")
            obs=purchase.run(work,env,stage,DB,'external_renew',
                             dict(data='sConfigRenewPlan7_0',actor=1001),modern=True)
            if obs.get('exception') or obs.get('warnings'):
                raise RuntimeError('Supported external renewal route error: '+repr(obs)[:850])
            data=sql(work,env,"SELECT server_id,description FROM pays WHERE type='RENEW_SCONFIG' AND user_id=1001 LIMIT 1").strip()
            if not data or not data.startswith('2\t') or 'external-fixture' not in data:
                raise RuntimeError('Valid external Marzban payment identity not persisted: '+repr(data))
            records.append(dict(case='positive_external_renewal_checkout',pass_=True,
                                original_schema_payment_insert=True,external_requests=0))
        finally:
            purchase.block=old

        # Exercise the real public pay/back.php IPN HTTP entrypoint on an
        # owned loopback PHP server; production baseInfo and webhooks are not
        # loaded, and the only DB is the disposable Unix-socket MariaDB.
        import socket, time, urllib.request, urllib.error
        seed_payment(work,env,'np-http-signed','INCREASE_WALLET',0,0,'np-http-invoice')
        sql(work,env,"INSERT INTO payment_attempts "
            "(pay_row_id,provider,order_hash,provider_reference,checkout_url,quote_amount,quote_currency) "
            "SELECT id,'nowpayments',hash_id,'np-http-invoice','https://nowpayments.io/pay/np-http',1,'usd' "
            "FROM pays WHERE hash_id='np-http-signed'")
        (stage/'baseInfo.php').write_text(
            "<?php\n$dbUserName='root';\n$dbPassword='';\n"
            "$dbName='"+DB+"';\n$nowpaymentsIpnSecret='LOCAL-IPN-SECRET';\n")
        with socket.socket() as port_socket:
            port_socket.bind(('127.0.0.1',0))
            port=port_socket.getsockname()[1]
        cmd=['php','-n','-d','extension=mysqlnd','-d','extension=mysqli',
             '-d','extension=curl','-d','mysqli.default_socket='+str(work/'mysql.sock'),
             '-d','allow_url_fopen=0','-S','127.0.0.1:'+str(port),'-t',str(stage)]
        srv=subprocess.Popen(cmd,env=env,cwd=stage,
                             stdin=subprocess.DEVNULL,stdout=subprocess.DEVNULL,
                             stderr=subprocess.DEVNULL)
        try:
            url=f'http://127.0.0.1:{port}/pay/back.php?nowpayment_ipn=1'
            for attempt in range(25):
                try:
                    urllib.request.urlopen(url,timeout=1)
                except urllib.error.HTTPError as ready:
                    if ready.code==405:break
                except (OSError,TimeoutError):
                    pass
                time.sleep(0.10)
            else:
                raise RuntimeError('Owned PHP IPN loopback server not ready')
            posted={
                'payment_id':'np-http-payment','invoice_id':'np-http-invoice',
                'order_id':'np-http-signed','payment_status':'confirmed',
                'price_amount':1,'price_currency':'usd',
                'pay_amount':1,'actually_paid':1,'pay_currency':'usdttrc20'
            }
            body=json.dumps(posted,sort_keys=True,separators=(',',':')).encode('utf-8')
            mac=hmac.new(b'LOCAL-IPN-SECRET',body,hashlib.sha512).hexdigest()
            def post(sig):
                request=urllib.request.Request(url,data=body,method='POST',
                    headers={'Content-Type':'application/json','x-nowpayments-sig':sig})
                try:
                    with urllib.request.urlopen(request,timeout=4) as response:
                        return response.status,response.read()
                except urllib.error.HTTPError as error:
                    return error.code,error.read()
            refused,_=post('0'*128)
            if refused!=401:
                raise RuntimeError('Real HTTP entrypoint accepted wrong IPN HMAC: '+str(refused))
            if sql(work,env,"SELECT payment_id FROM payment_attempts "
                   "WHERE order_hash='np-http-signed'").strip() not in ('NULL',''):
                raise RuntimeError('Forged HTTP IPN modified invoice')
            accepted,reply=post(mac)
            if accepted!=200 or json.loads(reply).get('accepted') is not True:
                raise RuntimeError('Real signed IPN HTTP rejected: '+repr((accepted,reply[:150])))
            got=sql(work,env,"SELECT payment_id FROM payment_attempts "
                   "WHERE order_hash='np-http-signed'").strip()
            if got!='np-http-payment':
                raise RuntimeError('Real IPN HTTP path did not persist exact payment ID')
            records.append(dict(case='nowpayments_real_loopback_http_ipn_minimal_bootstrap',
                                pass_=True,real_provider_requests=0,external_requests=0))
        finally:
            srv.terminate()
            try:srv.wait(timeout=5)
            except subprocess.TimeoutExpired:srv.kill();srv.wait(timeout=3)

        # All seven original pending-order cleanup sites must protect a
        # provider-issued reference; do not silently remove signed callbacks
        # or Marzban deliveries after a customer requests another quote.
        cleanup_paths=[
            'src/Purchase/SelectPlan.php','src/Purchase/Catalogue.php',
            'src/Finance/Routes/RenewalPlan.php',
            'src/Finance/Routes/Extensions.php',
            'src/Finance/Routes/RenewalCheckout.php',
            'src/Finance/WalletStart.php'
        ]
        checked=0
        for source_path in cleanup_paths:
            code=(ROOT/source_path).read_text()
            # WalletStart now locks the authenticated user row as $actor in
            # one transaction; do not depend on legacy variable spacing/name.
            checked+=len(re.findall(
                r'achbotRemoveUnclaimedPendingPayments\(\s*\$connection\s*,\s*'
                r'(?:\$from_id|\$actor)\s*,', code))
        helper=(ROOT/'src/Purchase/Core.php').read_text()
        lifecycle=(ROOT/'src/Finance/PaymentLifecycle.php').read_text()
        if (checked!=7 or
            "DELETE FROM pays" in helper or
            "function achbotPayUxExpireDraft" not in lifecycle or
            "FOR UPDATE" not in lifecycle or
            "NOT EXISTS (SELECT 1 FROM payment_attempts a" not in lifecycle):
            raise RuntimeError('Pending checkout cleanup can orphan issued claims: '+
                               str(checked))
        records.append(dict(case='all_pending_quote_cleanup_routes_preserve_issued_ids',
                            pass_=True,routes=checked))

        # Execute ACTUAL current WalletStart PHP against the owned database,
        # proving a fresh quote no longer deletes an already-issued checkout.
        seed_payment(work,env,'wallet-previous-issued',
                     'INCREASE_WALLET',0,0,'old-issued-ref')
        sql(work,env,"INSERT INTO payment_attempts "
            "(pay_row_id,provider,order_hash,provider_reference,quote_amount,"
            "quote_currency,local_amount_toman,stage) "
            "SELECT id,'nextpay',hash_id,payid,100,'toman',100,'pending' "
            "FROM pays WHERE hash_id='wallet-previous-issued'")
        seed_payment(work,env,'wallet-unused-draft',
                     'INCREASE_WALLET',0,0,'unused')
        sql(work,env,"UPDATE pays SET payid=NULL "
                     "WHERE hash_id='wallet-unused-draft'")
        wallet_route=run_php(work,env,stage,dict(
            route='wallet_start',get={},wallet_authorized=True))
        if wallet_route['network_calls'] or not any(
                e['name']=='wallet_start_result' and e['status']=='created'
                for e in wallet_route['events']):
            raise RuntimeError('WalletStart failed owned authorized quote creation')
        active=sql(work,env,"SELECT COUNT(*) FROM pays "
            "WHERE hash_id='wallet-previous-issued'").strip()
        draft=sql(work,env,"SELECT COUNT(*) FROM pays "
            "WHERE hash_id='wallet-unused-draft'").strip()
        fresh=sql(work,env,"SELECT COUNT(*) FROM pays "
            "WHERE hash_id='new-wallet-safe-hash'").strip()
        linked=sql(work,env,"SELECT COUNT(*) FROM payment_attempts a "
            "JOIN pays p ON p.id=a.pay_row_id "
            "WHERE p.hash_id='wallet-previous-issued'").strip()
        # New safe source preserves the old unclaimed draft until the
        # committed replacement is expired by the owned lifecycle worker;
        # never DELETE an ID that can have a historical message reference.
        if (active,draft,fresh,linked)!=('1','1','1','1'):
            raise RuntimeError('Creating a new wallet quote erased payment history: '+
                               repr((active,draft,fresh,linked)))
        records.append(dict(case='wallet_requote_preserves_existing_issued_payment',
                            pass_=True,real_php=True))
        # The schema itself also enforces the lifetime of the parent pays row.
        try:
            sql(work,env,"DELETE FROM pays WHERE hash_id='wallet-previous-issued'")
        except Exception:
            pass
        else:
            raise RuntimeError('Database permitted deletion of a claimed payment')
        if sql(work,env,"SELECT COUNT(*) FROM pays "
               "WHERE hash_id='wallet-previous-issued'").strip()!='1':
            raise RuntimeError('Claimed pays row deleted despite attempt FK')
        records.append(dict(case='database_fk_prevents_claim_orphan',
                            pass_=True,external_requests=0))

        # A new NOWPayments invoice may collide with an old unscoped payid.
        # The source cannot infer whether that historical ID was issued by
        # NextPay or Zarinpal. Keep the new invoice reservation for review,
        # but do not publish the colliding hosted URL or disturb old orders.
        for gateway in ('nextpay','zarinpal'):
            ref='np-colliding-legacy-'+gateway
            name='np-probe-collision-'+gateway
            older='legacy-unscoped-'+gateway
            seed_payment(work,env,older,'INCREASE_WALLET',0,0,ref)
            seed_payment(work,env,name,'INCREASE_WALLET',0,0,'unused')
            sql(work,env,"UPDATE pays SET payid=NULL,price=100000 "
                         f"WHERE hash_id='{name}'")
            refused=run_php(work,env,stage,dict(route='initiate',
                get={'nowpayment':'','hash_id':name},fake_invoice_id=ref))
            check=sql(work,env,"SELECT a.stage,IFNULL(a.provider_reference,'NULL'),"
                "IFNULL(a.checkout_url,'NULL'),p.payid FROM payment_attempts a "
                "JOIN pays p ON p.id=a.pay_row_id "
                f"WHERE a.order_hash='{name}'").strip()
            legacy=sql(work,env,f"SELECT payid,state FROM pays WHERE hash_id='{older}'").strip()
            marker='achbot_nowpayment_creating_'+sql(work,env,
                f"SELECT id FROM pays WHERE hash_id='{name}'").strip()
            if (len(refused['network_calls'])!=1 or '/v1/invoice' not in refused['network_calls'][0] or
                check!=f'review\tNULL\tNULL\t{marker}' or
                legacy!=f'{ref}\tpending' or
                any('nowpayments.io' in str(event) for event in refused['events']) or
                'nowpayments.io' in refused['form'] or
                'بررسی' not in refused['form']):
                raise RuntimeError('Legacy '+gateway+' invoice collision was published or lost: '+
                                   repr((check,legacy,refused)))
            records.append(dict(case='nowpayments_legacy_'+gateway+
                                '_invoice_collision_preserves_reservation',
                                pass_=True,external_requests=1))
        # A reference reused across two FULLY scoped providers is valid.
        # Do not turn provider-scoped identity into global uniqueness.
        for provider in ('nextpay','zarinpal'):
            ref='np-shared-with-'+provider
            previous='np-other-scoped-'+provider
            name='np-positive-'+provider
            seed_payment(work,env,previous,'INCREASE_WALLET',0,0,ref)
            sql(work,env,"INSERT INTO payment_attempts "
                "(pay_row_id,provider,order_hash,provider_reference,"
                "quote_amount,quote_currency,local_amount_toman,stage) "
                f"SELECT id,'{provider}',hash_id,payid,"
                "100,'toman',100,'pending' FROM pays "
                f"WHERE hash_id='{previous}'")
            seed_payment(work,env,name,'INCREASE_WALLET',0,0,'unused')
            sql(work,env,"UPDATE pays SET payid=NULL,price=100000 "
                         f"WHERE hash_id='{name}'")
            issued=run_php(work,env,stage,dict(route='initiate',
                get={'nowpayment':'','hash_id':name},fake_invoice_id=ref))
            now=sql(work,env,"SELECT a.stage,a.provider_reference,p.payid "
                "FROM payment_attempts a JOIN pays p ON p.id=a.pay_row_id "
                f"WHERE a.order_hash='{name}'").strip()
            prior=sql(work,env,"SELECT a.provider,a.provider_reference,p.payid "
                "FROM payment_attempts a JOIN pays p ON p.id=a.pay_row_id "
                f"WHERE a.order_hash='{previous}'").strip()
            if (len(issued['network_calls'])!=1 or
                now!=f'pending\t{ref}\t{ref}' or
                prior!=f'{provider}\t{ref}\t{ref}' or
                not any(name in str(event) for event in issued['events']) or
                any('nowpayments.io/pay/' in str(event) for event in issued['events'])):
                raise RuntimeError('Provider-scoped NOWPayments reference was rejected: '+
                                   repr((now,prior,issued)))
            records.append(dict(case='nowpayments_cross_scoped_'+provider+
                                '_same_reference_allowed',
                                pass_=True,external_requests=1))

        # Run the ACTUAL unmodified Cron entrypoint on the same disposable
        # MariaDB fixture, with only test-private bootstrap and outbound
        # backup/Telegram boundaries stubbed. The real GatewayReturn and
        # AutomaticReceipts modules are loaded, the latter instrumented
        # after execution to prove unrelated Cron work was reached.
        (stage/'config.php').write_text('<?php // owned test Cron bootstrap\n')
        (stage/'buy_helpers.php').write_text('<?php // no remote work needed\n')
        (stage/'src/Backup/Database.php').write_text(
            "<?php namespace AchBot\\Backup\\Database; "
            "function schedule($conn,$channel,$callback,$root) { "
            "$GLOBALS['events'][]=['name'=>'backup_reached'];return true;}\n")
        receipts=stage/'src/Finance/AutomaticReceipts.php'
        receipts.write_text(receipts.read_text()+
            "\n$GLOBALS['events'][]=['name'=>'automatic_receipts_reached'];\n")
        seed_payment(work,env,'cron-due-operator-alert',
                     'INCREASE_WALLET',0,0,'np-cron-probe')
        sql(work,env,"INSERT INTO payment_attempts "
            "(pay_row_id,provider,order_hash,provider_reference,quote_amount,"
            "quote_currency,local_amount_toman,stage) "
            "SELECT id,'nowpayments',hash_id,payid,100,'usd',100,'review' "
            "FROM pays WHERE hash_id='cron-due-operator-alert'")
        healthy=run_php(work,env,stage,dict(route='cron',get={}))
        names=[v['name'] for v in healthy['events']]
        if (healthy['error'] or healthy['fatal'] or
            'backup_reached' not in names or
            'automatic_receipts_reached' not in names or
            'sendMessage' not in names):
            raise RuntimeError('Owned healthy Cron did not finish local workers: '+repr(healthy))
        records.append(dict(case='cron_complete_003_schema_alert_and_receipts_run',
                            pass_=True,real_php=True))

        # Actual bot-owned URL, not a raw provider URL: a stale or
        # superseded local request must stop BEFORE any provider HTTP issue.
        current=int(clock.time())
        seed_payment(work,env,'ux-old-expired','INCREASE_WALLET',0,0,'unused')
        sql(work,env,f"UPDATE pays SET payid=NULL,request_date={current-3601} "
                     "WHERE hash_id='ux-old-expired'")
        before_wallet=sql(work,env,"SELECT wallet FROM users WHERE userid=1001")
        expired=run_php(work,env,stage,dict(route='initiate',
                    get={'nowpayment':'','hash_id':'ux-old-expired'}))
        if (expired['network_calls'] or
            sql(work,env,"SELECT state FROM pays WHERE hash_id='ux-old-expired'").strip()!='expired' or
            'مهلت' not in expired['form']):
            raise RuntimeError('Stale 30-minute bot-owned URL escaped expiry guard')
        records.append(dict(case='ux_stale_bot_checkout_url_expires_unclaimed_draft_before_provider',
                            pass_=True,external_requests=0))
        seed_payment(work,env,'ux-old-replaced','INCREASE_WALLET',0,0,'unused')
        sql(work,env,f"UPDATE pays SET payid=NULL,request_date={current} "
                     "WHERE hash_id='ux-old-replaced'")
        seed_payment(work,env,'ux-new-replacement','INCREASE_WALLET',0,0,'unused')
        sql(work,env,f"UPDATE pays SET payid=NULL,request_date={current+1} "
                     "WHERE hash_id='ux-new-replacement'")
        replaced=run_php(work,env,stage,dict(route='initiate',
                    get={'nextpay':'','hash_id':'ux-old-replaced'}))
        if (replaced['network_calls'] or
            sql(work,env,"SELECT state FROM pays WHERE hash_id='ux-old-replaced'").strip()!='expired'):
            raise RuntimeError('Superseded bot-owned checkout URL created provider invoice')
        if sql(work,env,"SELECT wallet FROM users WHERE userid=1001")!=before_wallet:
            raise RuntimeError('Stale-checkout handling unexpectedly changed wallet balance')
        records.append(dict(case='ux_superseded_local_url_no_provider_effect_or_wallet_change',
                            pass_=True,external_requests=0))
        seed_payment(work,env,'ux-claimed-older','INCREASE_WALLET',0,0,'claimed-remote-invoice')
        # The claim itself persists, even when a newer draft is created.
        seed_payment(work,env,'ux-new-after-claim','INCREASE_WALLET',0,0,'unused')
        sql(work,env,"UPDATE pays SET payid=NULL WHERE hash_id='ux-new-after-claim'")
        claimed=run_php(work,env,stage,dict(route='initiate',
                    get={'zarinpal':'','hash_id':'ux-claimed-older'}))
        if (claimed['network_calls'] or
            sql(work,env,"SELECT payid,state FROM pays WHERE hash_id='ux-claimed-older'").strip()
              !='claimed-remote-invoice\tpending' or
            not claimed['form']):
            raise RuntimeError('Stale provider-claimed original URL was silently reissued or cancelled')
        records.append(dict(case='ux_old_claimed_provider_invoice_remains_financially_unmodified',
                            pass_=True,external_requests=0))

        # An interrupted/mismatched 003 migration can leave payment_attempts
        # present while payment_transactions is absent. NEVER issue a new
        # checkout that could receive funds but could not persist IPN evidence.
        # This destructive DDL is limited to our disposable fixture database.
        sql(work,env,'DROP TABLE payment_transactions')
        before=snapshot(work,env)
        stopped=run_php(work,env,stage,dict(route='cron',get={}))
        after=snapshot(work,env)
        names=[v['name'] for v in stopped['events']]
        if (stopped['error'] or stopped['fatal'] or stopped['network_calls'] or
            'backup_reached' not in names or
            'automatic_receipts_reached' not in names or
            'sendMessage' in names or before!=after):
            raise RuntimeError('Incomplete 003 Cron halted receipts or ran unsafe reconciliation: '+
                               repr(stopped))
        records.append(dict(case='cron_partial_003_schema_skips_np_and_runs_receipts',
                            pass_=True,real_php=True,external_requests=0))
        for provider in ('nowpayment','nextpay','zarinpal'):
            name='partial-schema-'+provider
            seed_payment(work,env,name,'INCREASE_WALLET',0,0,'unused')
            sql(work,env,f"UPDATE pays SET payid=NULL,price=100000 "
                         f"WHERE hash_id='{name}'")
            before=snapshot(work,env)
            blocked=run_php(work,env,stage,dict(route='initiate',
                get={provider:'','hash_id':name}))
            verify_unchanged(work,env,before,'partial_schema_'+provider)
            attempt_count=sql(work,env,f"SELECT COUNT(*) FROM payment_attempts "
                f"WHERE order_hash='{name}'").strip()
            token=sql(work,env,f"SELECT IFNULL(payid,'') FROM pays "
                f"WHERE hash_id='{name}'").strip()
            if blocked['network_calls'] or not blocked['form'] or attempt_count!='0' or token:
                raise RuntimeError('Incomplete shared schema issued '+provider+
                    ' invoice or reserved without evidence table: '+
                    repr((attempt_count,token,blocked['network_calls'])))
            records.append(dict(case=provider+'_incomplete_shared_schema_fails_before_issue',
                                pass_=True,external_requests=0))
    report={'complete':True,'cases':len(records),'schema_tables':23, 'current_schema_tables':25,
       'shared_payment_attempt_ledger_tested':True,
       'original_mariadb_socket_only':True,'real_provider_requests':0,
       'synthetic_payment_spy':True,'operator_finance_acceptance':False,
       'scenarios':records}
    if args.report:args.report.write_text(json.dumps(report,indent=2,ensure_ascii=False)+'\n')
    print(json.dumps({k:v for k,v in report.items() if k!='scenarios'}))
if __name__=='__main__':main()
