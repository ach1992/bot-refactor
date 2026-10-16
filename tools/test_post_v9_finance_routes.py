#!/usr/bin/env python3
"""Original-schema native financial route guards with mocked payment transport.

The current PHP modules run unmodified against a socket-only disposable
23-table database. curl functions are disabled and replaced by local spies:
no payment, Marzban, Telegram or internet request is possible.
"""
from __future__ import annotations

import argparse
import json
import subprocess
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
    // Existing doAction wallet branch references a missing local variable.
    // PHP normally logs and continues; isolate this one inherited warning
    // without suppressing new warnings/errors from the changed callback.
    if ($level === E_WARNING && $message === 'Undefined variable $rewardChannel'
        && str_ends_with($file, '/src/Finance/GatewayReturn.php')) {
        $GLOBALS['known_inherited_warnings'][] = $message;
        return true;
    }
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
$GLOBALS['fake_nowpayment_pay_amount'] = $spec['nowpayment_pay_amount'] ?? 1;
$GLOBALS['fake_nowpayment_force_id'] = $spec['nowpayment_force_id'] ?? null;
$GLOBALS['fake_nowpayment_flip_hash'] = $spec['nowpayment_flip_hash'] ?? null;
$GLOBALS['fake_nextpay_code'] = $spec['nextpay_code'] ?? -2;
$GLOBALS['fake_nextpay_http'] = $spec['nextpay_http'] ?? 200;
$GLOBALS['fake_nextpay_raw'] = $spec['nextpay_raw'] ?? null;
$GLOBALS['fake_nextpay_transport_failure'] = $spec['nextpay_transport_failure'] ?? false;
$GLOBALS['fake_nextpay_flip_hash'] = $spec['nextpay_flip_hash'] ?? null;
$GLOBALS['fake_nextpay_flip_state'] = $spec['nextpay_flip_state'] ?? 'paid';
$GLOBALS['race_flip_applied'] = false;
$GLOBALS['invoice_reservation_seen'] = null;
$GLOBALS['events'] = [];
$GLOBALS['known_inherited_warnings'] = [];
$botState = [];
$botUrl = 'http://127.0.0.1:9/owned-fixture/';
$USDTPrice = 100000;
$USDTPriceagent = 100000;
$nowpaymentsIpnSecret = 'LOCAL-IPN-SECRET';
$paymentKeys = ['nowpayment'=>'SYNTHETIC-LOCAL-ONLY', 'nextpay'=>'SYNTHETIC-LOCAL-ONLY'];
$admin = 9001;
function sendMessage(...$args) {
    $GLOBALS['events'][] = ['name'=>'sendMessage', 'args'=>$args];
    return (object)['ok'=>true];
}
function generateRandomString($length, $protocol) {
    return str_repeat('a', $length);
}
function createServerAccount(...$args) {
    // Replace ONLY the external Marzban boundary with an ambiguous result.
    // The real doAction() payment/compensation handling remains unmodified.
    $GLOBALS['events'][] = ['name'=>'marzban_unknown_result'];
    return [null, $args[4] ?? '', $args[9] ?? 0];
}
function curl_init() { return (object)['url' => '']; }
function curl_setopt($handle,$option,$value) {
    if ($option === CURLOPT_URL) $handle->url = (string)$value;
    return true;
}
function curl_setopt_array($handle,$options) {
    foreach ($options as $option => $value) curl_setopt($handle,$option,$value);
    return true;
}
function curl_exec($handle) {
    $GLOBALS['network_calls'][] = $handle->url;
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
            "SELECT stage FROM achbot_nowpayments_attempts WHERE order_hash=? LIMIT 1");
        $key=(string)($_GET['hash_id'] ?? '');
        $stmt->bind_param('s',$key);
        $stmt->execute();
        $reservation=$stmt->get_result()->fetch_assoc();
        $stmt->close();
        $GLOBALS['invoice_reservation_seen']=$reservation['stage'] ?? null;
        if ($GLOBALS['fake_invoice_transport_failure'] ?? false) return false;
        return json_encode(['id'=>'owned-new-invoice',
                             'invoice_url'=>'https://nowpayments.io/payment?iid=owned-new-invoice']);
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
        $record['pay_currency']='usdttrc20';
        return json_encode($record);
    }
    return json_encode(['invoice_id'=>$GLOBALS['mock_invoice'],
                        'payment_status'=>'waiting']);
}
function curl_close($handle) {}
function curl_error($handle) { return ''; }
function curl_getinfo($handle,$option) {
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
try {
    $connection = new mysqli('localhost', 'root', '', $dbname, 0, $socket);
    $connection->set_charset('utf8mb4');
    require $root . '/src/Purchase/Core.php';
    // Use the ACTUAL current wallet-credit helper; only outbound Telegram is
    // captured by the transport spy. No fake settlement implementation.
    require $root . '/src/Finance/ReceiptHelpers.php';
    require $root . '/src/Finance/GatewayClient.php';
    if ($spec['route'] === 'ipn' || $spec['route'] === 'reconcile') {
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
        } else {
            $GLOBALS['events'][]=['name'=>'reconcile_result','result'=>achbotNpReconcileBatch(
                $connection,$paymentKeys,1)];
        }
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
    if p.returncode or p.stderr:
        raise RuntimeError('native finance php error: '+repr((p.returncode,p.stdout[-500:],p.stderr[-600:])))
    try:
        result=json.loads(p.stdout)
    except json.JSONDecodeError as error:
        raise RuntimeError('native finance output not JSON: '+repr(p.stdout[-500:])) from error
    if result['error'] or result['fatal']:
        raise RuntimeError('native finance php runtime failure: '+repr(result)[:600])
    return result

def seed_payment(work,env,name,type_name,plan=0,server=0,payid=None):
    invoice=payid if payid is not None else 'invoice-'+name
    query="""INSERT INTO pays
    (hash_id,payid,user_id,type,plan_id,server_id,volume,day,price,request_date,state)
    VALUES ('{}','{}',1001,'{}',{},{},0,0,100,1700000000,'pending')""".format(
       name,invoice,type_name,plan,server)
    sql(work,env,query)
    return invoice

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
        if len(sql(work,env,'SHOW TABLES').splitlines()) != 23:
            raise RuntimeError('Original schema missing')
        keys=json.dumps({'nowpayment':'SYNTHETIC-LOCAL-ONLY','nowpayment_ipn_secret':'LOCAL-IPN-SECRET'})
        sql(work,env,"INSERT INTO setting (type,value) VALUES ('PAYMENT_KEYS','" + keys + "')")
        # Original 23 tables remain frozen; the new isolated proposed table
        # is layered on top ONLY after documenting the original table count.
        native.sql(work,env,(ROOT/'migrations/post-v9/002_nowpayments_checkout_attempts.sql').read_text(),DB)
        assert len(sql(work,env,'SHOW TABLES').splitlines()) == 24

        seed_payment(work,env,'unsupported-now','RETIRED_PANEL',7,2)
        seed_payment(work,env,'marzban-now','BUY_SUB',7,2,'owned-verified-invoice')
        seed_payment(work,env,'wallet-now','INCREASE_WALLET',0,0,'wallet-verified-invoice')
        seed_payment(work,env,'unsupported-init','RETIRED_PANEL',7,2)
        seed_payment(work,env,'unsupported-zarin','RETIRED_PANEL',7,2)
        seed_payment(work,env,'unsupported-next','RETIRED_PANEL',7,2)
        seed_payment(work,env,'wallet-init','INCREASE_WALLET',0,0)
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
        before=snapshot(work,env)
        res=run_php(work,env,stage,dict(route='return',
            get={'nowpayment':'','NP_id':'owned-pmt','hash_id':'wallet-now'},
            mock_invoice='different-invoice'))
        verify_unchanged(work,env,before,'mismatched_confirmed_invoice')
        if len(res['network_calls'])!=1 or 'v1/payment/owned-pmt' not in res['network_calls'][0]:
            raise RuntimeError('Supported wallet payment never reached fake verifier')
        if 'مطابقت' not in res['form']:
            raise RuntimeError('Invoice mismatch did not fail closed')
        records.append(dict(case='positive_subject_invoice_mismatch',pass_=True,financial_mutations=0,external_requests=1))
        # NOWPayments hosted invoice success_url is merchant-controlled and
        # may contain only hash_id. Treat a browser-only return as unknown,
        # never a provider rejection or financial confirmation.
        before=snapshot(work,env)
        no_provider_id=run_php(work,env,stage,dict(route='return',
            get={'nowpayment':'','hash_id':'wallet-now'}))
        verify_unchanged(work,env,before,'nowpay-browser-only-return')
        if no_provider_id['network_calls'] or 'در حال بررسی' not in no_provider_id['form']:
            raise RuntimeError('NOWPayments browser-only redirect falsely declared failure')
        records.append(dict(case='nowpayments_browser_only_return_not_proof',pass_=True,
                            external_requests=0,financial_mutations=0))
        # RED was demonstrated at the previous HEAD: 'confirmed' initiated
        # real wallet credit via doAction(). Every intermediate state remains
        # untouched until an authoritative GET reports finished.
        pending_states=('waiting','confirming','confirmed','sending','partially_paid',
                        'refunded','unknown-provider-state')
        for status in pending_states:
            name='nowpay-intermediate-'+status
            invoice='nowpay-'+status+'-invoice'
            seed_payment(work,env,name,'INCREASE_WALLET',0,0,invoice)
            before=snapshot(work,env)
            res=run_php(work,env,stage,dict(route='return',
                get={'nowpayment':'','NP_id':'np-'+status,'hash_id':name},
                mock_invoice=invoice,nowpayment_status=status))
            verify_unchanged(work,env,before,name)
            if len(res['network_calls'])!=1 or not res['form']:
                raise RuntimeError('NOWPayments intermediate status lacked pending reply: '+status)
            records.append(dict(case='nowpayments_'+status+'_not_final',pass_=True,
                                financial_mutations=0,external_requests=1))
        for name, status, http, raw, fail, pmt_id in [
            ('http_503_success','finished',503,None,False,None),
            ('malformed_reply','finished',200,'not-a-json',False,None),
            ('transport_failure','finished',200,None,True,None),
            ('mismatched_payment_id','finished',200,None,False,'wrong-provider-id'),
        ]:
            hash_id='nowpay-negative-'+name
            invoice='nowpay-negative-'+name+'-invoice'
            seed_payment(work,env,hash_id,'INCREASE_WALLET',0,0,invoice)
            before=snapshot(work,env)
            result=run_php(work,env,stage,dict(route='return',
                get={'nowpayment':'','NP_id':'expected-payment-id','hash_id':hash_id},
                mock_invoice=invoice,nowpayment_status=status,
                nowpayment_http=http,nowpayment_raw=raw,
                nowpayment_transport_failure=fail,nowpayment_payment_id=pmt_id))
            verify_unchanged(work,env,before,hash_id)
            if len(result['network_calls'])!=1 or not result['form']:
                raise RuntimeError('Untrusted NOWPayments response had no safe response: '+name)
            records.append(dict(case='nowpayments_'+name+'_no_mutation',pass_=True,
                                external_requests=1,real_provider_requests=0))
        seed_payment(work,env,'nowpay-order-mismatch','INCREASE_WALLET',0,0,
                     'nowpay-order-mismatch-invoice')
        before=snapshot(work,env)
        wrong_order=run_php(work,env,stage,dict(route='return',
            get={'nowpayment':'','NP_id':'pmt-guard','hash_id':'nowpay-order-mismatch'},
            mock_invoice='nowpay-order-mismatch-invoice',nowpayment_status='finished',
            nowpayment_payment_id='pmt-guard',nowpayment_order_id='other-order'))
        verify_unchanged(work,env,before,'nowpay-order-id-mismatch')
        if len(wrong_order['network_calls'])!=1 or not wrong_order['form']:
            raise RuntimeError('Provider merchant order mismatch was not rejected')
        records.append(dict(case='nowpayments_mismatched_merchant_order_guard',pass_=True,
                            external_requests=1,real_provider_requests=0))
        # Final terminal negatives may cancel only the selected still-pending
        # invoice. Provider errors, partial receipts and refunds are not final
        # positive payment evidence and do not credit money or provision.
        for status in ('failed','expired'):
            hash_id='nowpay-terminal-'+status
            invoice='nowpay-terminal-'+status+'-invoice'
            seed_payment(work,env,hash_id,'INCREASE_WALLET',0,0,invoice)
            wallet_before=int(sql(work,env,'SELECT wallet FROM users WHERE userid=1001').strip())
            res=run_php(work,env,stage,dict(route='return',
                get={'nowpayment':'','NP_id':'pmt-'+status,'hash_id':hash_id},
                mock_invoice=invoice,nowpayment_status=status))
            if sql(work,env,f"SELECT state FROM pays WHERE hash_id='{hash_id}'").strip()!='canceled':
                raise RuntimeError('Definitive NOWPayments negative did not cancel exact order: '+status)
            if int(sql(work,env,'SELECT wallet FROM users WHERE userid=1001').strip())!=wallet_before:
                raise RuntimeError('NOWPayments negative changed wallet: '+status)
            if len(res['network_calls'])!=1:
                raise RuntimeError('NOWPayments negative did not exercise fake verifier: '+status)
            records.append(dict(case='nowpayments_'+status+'_exact_row_cancel',pass_=True,
                                external_requests=1,real_provider_requests=0))
        # Malformed legacy provider data must not allow local invoice-ID aliasing
        # to settle one of two distinct rows that share the external ID.
        seed_payment(work,env,'nowpay-duplicate-a','INCREASE_WALLET',0,0,'nowpay-shared-invoice')
        seed_payment(work,env,'nowpay-duplicate-b','INCREASE_WALLET',0,0,'nowpay-shared-invoice')
        before=snapshot(work,env)
        result=run_php(work,env,stage,dict(route='return',
            get={'nowpayment':'','NP_id':'pmt-shared','hash_id':'nowpay-duplicate-a'},
            mock_invoice='nowpay-shared-invoice',nowpayment_status='finished'))
        verify_unchanged(work,env,before,'nowpay-duplicate-invoice')
        if len(result['network_calls'])!=1 or not result['form']:
            raise RuntimeError('NOWPayments ambiguous payid not refused')
        records.append(dict(case='nowpayments_duplicate_invoice_no_settlement',pass_=True,
                            external_requests=1,real_provider_requests=0))
        # Simulate a second worker settling the exact row while the provider
        # is being polled. The later negative response must not undo payment.
        seed_payment(work,env,'nowpay-race','INCREASE_WALLET',0,0,'nowpay-race-invoice')
        wallet_before=int(sql(work,env,'SELECT wallet FROM users WHERE userid=1001').strip())
        race=run_php(work,env,stage,dict(route='return',
            get={'nowpayment':'','NP_id':'pmt-race','hash_id':'nowpay-race'},
            mock_invoice='nowpay-race-invoice',nowpayment_status='expired',
            nowpayment_flip_hash='nowpay-race'))
        if not race['race_flip_applied'] or sql(work,env,
             "SELECT state FROM pays WHERE hash_id='nowpay-race'").strip()!='paid':
            raise RuntimeError('NOWPayments terminal negative overwrote concurrent paid row')
        if int(sql(work,env,'SELECT wallet FROM users WHERE userid=1001').strip())!=wallet_before:
            raise RuntimeError('NOWPayments race sent duplicate wallet credit')
        records.append(dict(case='nowpayments_negative_race_preserves_paid',pass_=True,
                            real_provider_requests=0))
        # The real current doAction()->addToWallet() branch is exercised once
        # on authoritative 'finished', not on a visual browser redirect.
        seed_payment(work,env,'nowpay-finished','INCREASE_WALLET',0,0,'nowpay-finished-invoice')
        before_balance=int(sql(work,env,'SELECT wallet FROM users WHERE userid=1001').strip())
        done=run_php(work,env,stage,dict(route='return',
            get={'nowpayment':'','NP_id':'pmt-final','hash_id':'nowpay-finished'},
            mock_invoice='nowpay-finished-invoice',nowpayment_status='finished',
            nowpayment_payment_id='pmt-final'))
        if sql(work,env,"SELECT state FROM pays WHERE hash_id='nowpay-finished'").strip()!='paid':
            raise RuntimeError('Verified finished NOWPayments did not mark row paid')
        if int(sql(work,env,'SELECT wallet FROM users WHERE userid=1001').strip())!=before_balance+100:
            raise RuntimeError('Verified finished NOWPayments did not credit wallet once')
        if len(done['network_calls'])!=1 or 'موفقیت' not in done['form']:
            raise RuntimeError('Finished NOWPayments response did not reach current success handler')
        records.append(dict(case='nowpayments_finished_real_wallet_helper_once',pass_=True,
                            real_provider_requests=0,real_current_wallet_helper=True))
        before=snapshot(work,env)
        replay=run_php(work,env,stage,dict(route='return',
            get={'nowpayment':'','NP_id':'pmt-final','hash_id':'nowpay-finished'},
            mock_invoice='nowpay-finished-invoice',nowpayment_status='finished'))
        verify_unchanged(work,env,before,'nowpay-finished-sequential-replay')
        if replay['network_calls']:
            raise RuntimeError('NOWPayments duplicate final callback recontacted provider')
        records.append(dict(case='nowpayments_sequential_replay_no_duplicate_credit',pass_=True,
                            external_requests=0))
        sql(work,env,"UPDATE pays SET price=100000,payid=NULL WHERE hash_id='wallet-init'")
        before=snapshot(work,env)
        res=run_php(work,env,stage,dict(route='initiate',
                    get={'nowpayment':'','hash_id':'wallet-init'}))
        if len(res['network_calls'])!=1 or '/v1/invoice' not in res['network_calls'][0]:
            raise RuntimeError('Independent wallet top-up did not initiate mock invoice: '+repr(res)[:1000])
        if res['invoice_reservation_seen']!='creating':
            raise RuntimeError('External invoice was created before a durable local reservation')
        if sql(work,env,"SELECT payid FROM pays WHERE hash_id='wallet-init'").strip()!='owned-new-invoice':
            raise RuntimeError('Wallet top-up invoice ID was not stored')
        records.append(dict(case='positive_wallet_gateway_initiation',pass_=True,synthetic_invoice=True,external_requests=1))

        # The exact hosted checkout URL must be durable and resumable: a second
        # click cannot create or replace another external provider invoice.
        before=snapshot(work,env)
        second=run_php(work,env,stage,dict(route='initiate',
            get={'nowpayment':'','hash_id':'wallet-init'}))
        verify_unchanged(work,env,before,'nowpay-existing-checkout-resume')
        if second['network_calls']:
            raise RuntimeError('Opening existing checkout spawned another invoice')
        persisted=sql(work,env,"SELECT invoice_id,quote_usd,invoice_url FROM achbot_nowpayments_attempts WHERE order_hash='wallet-init'").strip()
        if 'owned-new-invoice' not in persisted or 'nowpayments.io' not in persisted:
            raise RuntimeError('Checkout URL or quote not durable')
        records.append(dict(case='nowpayments_checkout_url_reused_without_new_invoice',
                            pass_=True,real_provider_requests=0))
        # A second click during a first provider POST must not trigger another
        # POST. Only the initial request may own the durable 'creating' row.
        seed_payment(work,env,'np-creating','INCREASE_WALLET',0,0,'unused-id')
        sql(work,env,"UPDATE pays SET price=100000,payid=NULL WHERE hash_id='np-creating'")
        sql(work,env,"INSERT INTO achbot_nowpayments_attempts "
            "(pay_row_id,order_hash,quote_usd,stage) "
            "SELECT id,hash_id,1,'creating' FROM pays WHERE hash_id='np-creating'")
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
        if sql(work,env,"SELECT stage FROM achbot_nowpayments_attempts "
               "WHERE order_hash='np-create-timeout'").strip()!='review':
            raise RuntimeError('Ambiguous invoice creation was not quarantined')
        before=snapshot(work,env)
        repeated=run_php(work,env,stage,dict(route='initiate',
            get={'nowpayment':'','hash_id':'np-create-timeout'}))
        verify_unchanged(work,env,before,'nowpay_ambiguous_invoice_retry')
        if repeated['network_calls'] or sql(work,env,
           "SELECT IFNULL(payid,'') FROM pays WHERE hash_id='np-create-timeout'").strip():
            raise RuntimeError('Provider timeout led to unsafe automatic reissue')
        records.append(dict(case='nowpayments_unknown_creation_prevents_orphan_reissue',
                            pass_=True,external_requests=1,real_provider_requests=0))
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
        got=sql(work,env,"SELECT payment_id,provider_status,stage FROM achbot_nowpayments_attempts WHERE order_hash='wallet-init'").strip()
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
        sql(work,env,"UPDATE achbot_nowpayments_attempts SET last_checked_at=NOW()-INTERVAL 120 SECOND WHERE order_hash='wallet-init'")
        final=run_php(work,env,stage,dict(route='reconcile',get={},
            mock_invoice='owned-new-invoice',
            nowpayment_payment_id='np-pmt-wallet-init',
            nowpayment_order_id='wallet-init',
            nowpayment_status='finished',
            nowpayment_price=1,nowpayment_pay_amount=1,nowpayment_actual=1))
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
        if sql(work,env,"SELECT stage FROM achbot_nowpayments_attempts WHERE order_hash='wallet-init'").strip()!='done':
            raise RuntimeError('Cron did not persist completed gateway attempt')
        if not any(x['name']=='sendMessage' and len(x['args'])>3 and x['args'][3]==1001
                   for x in final['events']):
            raise RuntimeError('Finished gateway was not delivered to Telegram recipient')
        records.append(dict(case='nowpayments_no_browser_return_final_telegram_credit',
                            pass_=True,real_provider_requests=0))
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
        # NOWPayments invoices can receive additional child payments after
        # the first settlement. The second signed event is NOT a second order.
        paid_wallet=int(sql(work,env,'SELECT wallet FROM users WHERE userid=1001').strip())
        extra=run_php(work,env,stage,signed_ipn(dict(ipn,
            payment_id='np-extra-deposit',payment_status='finished')))
        if extra['events'][-1].get('code')!=200:
            raise RuntimeError('Valid additional deposit was not acknowledged for review')
        if sql(work,env,"SELECT stage,provider_status FROM achbot_nowpayments_attempts "
               "WHERE order_hash='wallet-init'").strip()!='review\tadditional_payment':
            raise RuntimeError('Later child deposit was not surfaced for operator review')
        if int(sql(work,env,'SELECT wallet FROM users WHERE userid=1001').strip())!=paid_wallet:
            raise RuntimeError('Additional child payment duplicated an order credit')
        records.append(dict(case='nowpayments_later_child_after_done_needs_review',
                            pass_=True,external_requests=0))
        # A delayed replay of the originally settled payment must never
        # erase the operator-review reason for the additional child deposit.
        old_child_replay=run_php(work,env,stage,signed_ipn(ipn))
        if old_child_replay['events'][-1].get('code')!=200 or sql(work,env,
           "SELECT stage,provider_status FROM achbot_nowpayments_attempts "
           "WHERE order_hash='wallet-init'").strip()!='review\tadditional_payment':
            raise RuntimeError('Old IPN replay erased an extra-payment review marker')
        if int(sql(work,env,'SELECT wallet FROM users WHERE userid=1001').strip())!=paid_wallet:
            raise RuntimeError('Old IPN replay after extra deposit changed wallet')
        records.append(dict(case='nowpayments_replay_preserves_additional_payment_review',
                            pass_=True,external_requests=0))
        # A finished provider payment whose deposit is smaller than the
        # promised quote is quarantined, never auto-reconciled as successful.
        seed_payment(work,env,'np-underpaid','INCREASE_WALLET',0,0,'np-underpaid-invoice')
        sql(work,env,"INSERT INTO achbot_nowpayments_attempts (pay_row_id,order_hash,invoice_id,payment_id,invoice_url,quote_usd) "
            "SELECT id,hash_id,'np-underpaid-invoice','np-underpaid-pmt','https://nowpayments.io/pay/np-underpaid',1 FROM pays WHERE hash_id='np-underpaid'")
        wallet_prior=int(sql(work,env,'SELECT wallet FROM users WHERE userid=1001').strip())
        review=run_php(work,env,stage,dict(route='reconcile',get={},
            mock_invoice='np-underpaid-invoice',
            nowpayment_payment_id='np-underpaid-pmt',
            nowpayment_order_id='np-underpaid',
            nowpayment_status='finished',
            nowpayment_price=1,nowpayment_pay_amount=1,nowpayment_actual=0.5))
        if int(sql(work,env,'SELECT wallet FROM users WHERE userid=1001').strip())!=wallet_prior:
            raise RuntimeError('Underpaid crypto gateway credited wallet')
        if sql(work,env,"SELECT stage FROM achbot_nowpayments_attempts WHERE order_hash='np-underpaid'").strip()!='review':
            raise RuntimeError('Underpaid gateway did not enter reconciliation queue')
        records.append(dict(case='nowpayments_underpayment_quarantined',
                            pass_=True,external_requests=1))
        # NOWPayments partially_paid is a completed, underfunded child
        # payment. Do not credit or poll it indefinitely as pending.
        seed_payment(work,env,'np-partial-final','INCREASE_WALLET',0,0,
                     'np-partial-final-invoice')
        sql(work,env,"INSERT INTO achbot_nowpayments_attempts "
            "(pay_row_id,order_hash,invoice_id,payment_id,invoice_url,quote_usd) "
            "SELECT id,hash_id,'np-partial-final-invoice','np-partial-child',"
            "'https://nowpayments.io/pay/np-partial-final',1 FROM pays "
            "WHERE hash_id='np-partial-final'")
        before_balance=int(sql(work,env,'SELECT wallet FROM users WHERE userid=1001').strip())
        partial=run_php(work,env,stage,dict(route='reconcile',get={},
            mock_invoice='np-partial-final-invoice',
            nowpayment_payment_id='np-partial-child',
            nowpayment_order_id='np-partial-final',
            nowpayment_status='partially_paid',
            nowpayment_price=1,nowpayment_pay_amount=1,nowpayment_actual=0.5))
        if len(partial['network_calls'])!=1 or sql(work,env,
           "SELECT stage FROM achbot_nowpayments_attempts WHERE order_hash='np-partial-final'").strip()!='review':
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
        sql(work,env,"INSERT INTO achbot_nowpayments_attempts "
            "(pay_row_id,order_hash,invoice_id,payment_id,invoice_url,quote_usd) "
            "SELECT id,hash_id,'np-remote-uncertain-invoice','np-remote-uncertain-payment',"
            "'https://nowpayments.io/payment/np-remote-uncertain',1 FROM pays "
            "WHERE hash_id='np-remote-uncertain'")
        wallet_before=int(sql(work,env,'SELECT wallet FROM users WHERE userid=1001').strip())
        remote_uncertain=run_php(work,env,stage,dict(route='reconcile',get={},
            mock_invoice='np-remote-uncertain-invoice',
            nowpayment_payment_id='np-remote-uncertain-payment',
            nowpayment_order_id='np-remote-uncertain',
            nowpayment_status='finished',nowpayment_price=1,
            nowpayment_pay_amount=1,nowpayment_actual=1))
        if not any(e['name']=='marzban_unknown_result' for e in remote_uncertain['events']):
            raise RuntimeError('Uncertain provider fixture did not reach Marzban create seam: '+repr(remote_uncertain)[:700]+' DB '+repr(sql(work,env,"SELECT a.stage,a.provider_status,p.state FROM achbot_nowpayments_attempts a JOIN pays p ON p.id=a.pay_row_id WHERE a.order_hash='np-remote-uncertain'")))
        if sql(work,env,"SELECT stage FROM achbot_nowpayments_attempts "
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
        sql(work,env,"INSERT INTO achbot_nowpayments_attempts "
            "(pay_row_id,order_hash,invoice_id,payment_id,invoice_url,quote_usd,created_at) "
            "SELECT id,hash_id,'np-late-invoice-id','np-late-child-payment',"
            "'https://nowpayments.io/pay/np-late',1,"
            "DATE_SUB(NOW(), INTERVAL 12 DAY) FROM pays WHERE hash_id='np-late-invoice'")
        balance_before=int(sql(work,env,'SELECT wallet FROM users WHERE userid=1001').strip())
        late=run_php(work,env,stage,dict(route='reconcile',get={},
            mock_invoice='np-late-invoice-id',
            nowpayment_payment_id='np-late-child-payment',
            nowpayment_order_id='np-late-invoice',
            nowpayment_status='finished'))
        if len(late['network_calls'])!=1 or sql(work,env,
           "SELECT stage FROM achbot_nowpayments_attempts WHERE order_hash='np-late-invoice'").strip()!='done':
            raise RuntimeError('Valid payment on older timeless invoice was stranded')
        if int(sql(work,env,'SELECT wallet FROM users WHERE userid=1001').strip())!=balance_before+100:
            raise RuntimeError('Late-invoice finished payment failed to credit wallet')
        records.append(dict(case='nowpayments_late_payment_on_timeless_invoice',
                            pass_=True,external_requests=1,real_provider_requests=0))
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
        if positive['known_inherited_warnings']!=['Undefined variable $rewardChannel']:
            raise RuntimeError('Unexpected wallet success warning fingerprint: '+
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
        sql(work,env,"INSERT INTO achbot_nowpayments_attempts "
            "(pay_row_id,order_hash,invoice_id,invoice_url,quote_usd) "
            "SELECT id,hash_id,'np-http-invoice','https://nowpayments.io/pay/np-http',1 "
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
            if sql(work,env,"SELECT payment_id FROM achbot_nowpayments_attempts "
                   "WHERE order_hash='np-http-signed'").strip() not in ('NULL',''):
                raise RuntimeError('Forged HTTP IPN modified invoice')
            accepted,reply=post(mac)
            if accepted!=200 or json.loads(reply).get('accepted') is not True:
                raise RuntimeError('Real signed IPN HTTP rejected: '+repr((accepted,reply[:150])))
            got=sql(work,env,"SELECT payment_id FROM achbot_nowpayments_attempts "
                   "WHERE order_hash='np-http-signed'").strip()
            if got!='np-http-payment':
                raise RuntimeError('Real IPN HTTP path did not persist exact payment ID')
            records.append(dict(case='nowpayments_real_loopback_http_ipn_minimal_bootstrap',
                                pass_=True,real_provider_requests=0,external_requests=0))
        finally:
            srv.terminate()
            try:srv.wait(timeout=5)
            except subprocess.TimeoutExpired:srv.kill();srv.wait(timeout=3)
    report={'complete':True,'cases':len(records),'schema_tables':23, 'current_schema_tables':24,
       'additive_nowpayments_attempts_tested':True,
       'original_mariadb_socket_only':True,'real_provider_requests':0,
       'synthetic_payment_spy':True,'operator_finance_acceptance':False,
       'scenarios':records}
    if args.report:args.report.write_text(json.dumps(report,indent=2,ensure_ascii=False)+'\n')
    print(json.dumps({k:v for k,v in report.items() if k!='scenarios'}))
if __name__=='__main__':main()
