#!/usr/bin/env python3
"""Execute changed finance recovery routes on isolated legacy MariaDB.

The actual PHP routes and shared error helper are loaded without modification.
Remote account mutations and Telegram are synthetic spies; SQL errors are
injected AFTER remote success on a disposable database. Deliberately poisoned
temp-stage copies prove that the dynamic audience assertions detect leaks.
"""
from __future__ import annotations

import argparse
import json
import subprocess
import time
from pathlib import Path

import test_access_state as owned
import test_post_v9_purchases as stages
import test_v6_native as sql_native
import test_v8_native as purchase
import catalog_source

DB = purchase.SIDE[1]
ROOT = Path(__file__).resolve().parents[1]
SQL_SECRET = "SECRET_SQL_LOCAL_SENTINEL_4d55"
PROVIDER_SECRET = "SECRET_PROVIDER_TOKEN_LOCAL_87a3"

DRIVER = r"""<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
set_error_handler(function ($level, $message) {
    throw new ErrorException($message, 0, $level);
});
$_SERVER['SCRIPT_FILENAME'] = 'finance-error-privacy-fixture.php';
$root = $argv[1];
$db = $argv[2];
$socket = $argv[3];
$case = json_decode($argv[4], true, 512, JSON_THROW_ON_ERROR);
$events = [];
$remote_calls = 0;
$native_reads = 0;
$dispatch_snapshot = null;
$GLOBALS['ownedPrivacyDb']=$db;
$GLOBALS['ownedPrivacySocket']=$socket;
$uncaught = null;
$connection = new mysqli('localhost', 'root', '', $db, 0, $socket);
$connection->set_charset('utf8mb4');
$admin = 9001;
$from_id = ($case['audience'] === 'manual') ? 9001 : 1001;
$chat_id = $from_id;
$message_id = 42;
$markup = [];
$data = $case['data'];
$text = '';
$match = [];
$first_name = 'Fixture';
$username = 'fixture';
$botState = ['rewardChannel' => ''];
$buttonValues = ['cancel' => 'cancel'];
$mainValues = ['renewed_config_to_user' => 'renewed USERID',
    'renew_account_request_message' => 'renewed TYPE USER-ID'];
$userInfo = ['step' => 'none', 'isAdmin' => $from_id === 9001];
function sendMessage(...$args) {
    $GLOBALS['events'][] = ['name' => 'sendMessage', 'args' => $args];
    return (object)['ok'=>true];
}
function alert(...$args) {
    $GLOBALS['events'][] = ['name' => 'alert', 'args' => $args];
}
function editText(...$args) {
    $GLOBALS['events'][] = ['name' => 'editText', 'args' => $args];
}
function editKeys(...$args) {
    $GLOBALS['events'][] = ['name' => 'editKeys', 'args' => $args];
}
function getMainKeys() { return '{}'; }
function achbotSupportsPanel($type) { return $type==='marzban'; }
function getMarzbanUser($serverId,$remark) {
    ++$GLOBALS['native_reads'];
    // Only seed()'s explicit existing account has a controlled native state.
    // Reads are separate from the poisoned mutation boundary below.
    if ((int)$serverId!==2 || $remark!=='privacy-order' ||
        !empty($GLOBALS['case']['native_unknown'])) return null;
    return (object)['username'=>$remark,'expire'=>time()+86400,
        'data_limit'=>10*1073741824,'used_traffic'=>1073741824,'status'=>'active'];
}
function privacyDispatchSnapshot(): void {
    $reader=new mysqli('localhost','root','',$GLOBALS['ownedPrivacyDb'],0,$GLOBALS['ownedPrivacySocket']);
    $row=$reader->query("SELECT p.state,u.wallet FROM pays p JOIN users u ON u.userid=p.user_id WHERE p.hash_id='privacyhash'")->fetch_assoc();
    $reader->close();
    $GLOBALS['dispatch_snapshot']=$row;
}
function editMarzbanConfig($server_id, $info) {
    privacyDispatchSnapshot();
    $GLOBALS['remote_calls']++;
    if ($GLOBALS['case']['provider'] === 'reject') {
        return (object)['success' => false,
            'msg' => 'SECRET_PROVIDER_TOKEN_LOCAL_87a3 bearer:fake'];
    }
    if ($GLOBALS['case']['provider'] === 'throw') {
        throw new RuntimeException('SECRET_PROVIDER_TOKEN_LOCAL_87a3 runtime');
    }
    return (object)['success' => true,'verified'=>true,
        'applied_expire'=>2000000000,'applied_bytes'=>10737418240];
}
try {
    require $root . '/buy_helpers.php';
    if ($case['module'] === 'WalletSettlement.php') {
        require $root . '/src/Finance/WalletSettlement.php';
        $result = processWalletPayment($connection, 'privacyhash',
            function ($payment, $account) {
                privacyDispatchSnapshot();
                $GLOBALS['remote_calls']++;
                if ($GLOBALS['case']['provider'] === 'reject') {
                    throw new RuntimeException('provider_rejected');
                }
                if ($GLOBALS['case']['provider'] === 'throw') {
                    throw new RuntimeException('SECRET_PROVIDER_TOKEN_LOCAL_87a3 runtime');
                }
                return true;
            });
    } else {
        require $root . '/src/Finance/Routes/' . $case['module'];
    }
} catch (Throwable $e) {
    $uncaught = get_class($e) . ':' . $e->getMessage();
}
echo json_encode(['events' => $events, 'remote_calls' => $remote_calls, 'native_reads'=>$native_reads,
                  'uncaught' => $uncaught, 'dispatch_snapshot'=>$dispatch_snapshot], JSON_INVALID_UTF8_SUBSTITUTE |
                  JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
"""


def sql(work, env, statement):
    return sql_native.sql(work, env, statement, DB)


def seed(work, env, case):
    purchase.init(work, env, "http://127.0.0.1:9")
    # Source-real final wallet authorization reads the persisted BOT_STATES
    # document; the old fixture had only a PHP request-global snapshot.
    enabled = {k:'on' for k in ('walletState','cartToCartState','giftCardState',
               'manualUsdtState','nowPaymentWallet','nowPaymentOther',
               'zarinpal','nextpay')}
    persisted = json.dumps(enabled,separators=(',',':'))
    sql(work, env, "DELETE FROM setting WHERE type='BOT_STATES'; "
        "INSERT INTO setting (type,value) VALUES ('BOT_STATES','"+
        persisted+"')")
    sql(work, env, "DELETE FROM increase_day; DELETE FROM increase_plan")
    sql(work, env, """INSERT INTO increase_day (id,volume,price) VALUES (1,7,100);
       INSERT INTO increase_plan (id,volume,price) VALUES (1,7,100);
       INSERT INTO orders_list
          (userid,token,transid,fileid,server_id,inbound_id,remark,uuid,
           protocol,expire_date,link,amount,status,date)
       VALUES (1001,'privacy-token','',7,2,0,'privacy-order',
               'privacy-uuid','vless',2000000000,'[]',100,1,'synthetic');""")
    order_id = int(sql(work, env,
                       "SELECT id FROM orders_list WHERE remark='privacy-order'").strip())
    if case["name"].startswith("extension_volume"):
        pay_type = f"INCREASE_VOLUME_{order_id}_1"
    elif case["name"].startswith("extension_day"):
        pay_type = f"INCREASE_DAY_{order_id}_1"
    else:
        pay_type = "RENEW_ACCOUNT"
    # These supported current fixtures carry the immutable quantity shown in
    # the quote. Dedicated service-unit guards retain legacy zero/unprovable
    # add-on negatives; do not manufacture units from a mutable SKU at effect time.
    quoted_volume=7 if case['name'].startswith('extension_volume') else 0
    quoted_day=7 if case['name'].startswith('extension_day') else 0
    # Wallet checkout must be unclaimed. Manual operator checks simulate an
    # inherited (pre-ledger) receipt already delivered to the administrator;
    # a missing Telegram delivery ID cannot authorize remote effects.
    pay_state = 'pending' if case['audience'] == 'wallet' else 'have_sent'
    message_id = 0 if case['audience'] == 'wallet' else 42
    chat_id = 0 if case['audience'] == 'wallet' else 9001
    # A wallet checkout undergoing remote failure must be a fresh, valid
    # synthetic payment draft. Old 2023 fixture timestamps are intentionally
    # rejected by the current payment-UX validity guard.
    request_date = int(time.time()) if case['audience']=='wallet' else 1700000000
    sql(work, env, f"""INSERT INTO pays
        (hash_id,payid,user_id,type,plan_id,server_id,order_id,
         volume,day,price,request_date,state,message_id,chat_id)
        VALUES ('privacyhash','',1001,'{pay_type}',7,2,
                {order_id},{quoted_volume},{quoted_day},100,{request_date},'{pay_state}',
                {message_id},{chat_id})""")
    return order_id


def php(work, env, stage, case, expected_remote_calls=1):
    path = work / "finance-privacy-driver.php"
    if not path.exists():
        path.write_text(DRIVER)
    spec = {
        "module": case["module"],
        "provider": case["provider"],
        "audience": case["audience"],
        "data": case["data"],
        "native_unknown": case.get("native_unknown",False),
    }
    args = ["php", "-n", "-d", "extension=mysqlnd", "-d", "extension=mysqli",
            "-d", "allow_url_fopen=0",
            "-d", "disable_functions=exec,shell_exec,system,passthru,popen,proc_open,pcntl_exec,pcntl_fork,mail,dl",
            str(path), str(stage), DB, str(work / "mysql.sock"), json.dumps(spec)]
    p = subprocess.run(args, env=env, cwd=work, capture_output=True, timeout=25)
    if p.returncode != 0 or p.stderr:
        raise RuntimeError("Privacy route runner PHP failure: " + repr(
            (p.returncode, p.stdout[-400:], p.stderr[-500:])))
    result = json.loads(p.stdout)
    if result["uncaught"] or result["remote_calls"] != expected_remote_calls:
        raise RuntimeError("Privacy route dispatch/exception boundary failed: "
                           + repr(result)[:1400])
    return result


def assert_privacy(result, audience, expected_code, require_sql_secret=False):
    customer = []
    operator = []
    for event in result["events"]:
        if event["name"] == "sendMessage":
            args = event["args"]
            target = args[3] if len(args) > 3 else None
            (operator if target == 9001 else customer).append(str(args[0]))
        elif event["name"] == "alert":
            # A manual administrator callback displays the safe recovery hint
            # and is treated as an audience-facing message, not a raw traceback.
            customer.append(str(event["args"][0]))
    all_text = "\n".join(customer + operator)
    for secret in (SQL_SECRET, PROVIDER_SECRET):
        if secret in all_text:
            raise RuntimeError("Sensitive synthetic provider/SQL sentinel leaked: " + secret)
    unverified = "نتیجه عملیات سرویس تأیید نشده است؛ پیش از تکرار یا جبران، آن را بررسی کنید."
    if not any("شناسه پیگیری: privacyhash\n" in m
               and m.endswith("علت بررسی‌شده: " + expected_code)
               and unverified in m for m in operator):
        raise RuntimeError("No actionable redacted operator report: " + repr(operator))
    private_diagnostics = ("Hash ID:", "Reason (sanitized):",
                           "External effect: unverified", "شناسه پیگیری: privacyhash",
                           "علت بررسی‌شده:", unverified, expected_code)
    if any(label in message for message in customer for label in private_diagnostics):
        raise RuntimeError("Private diagnostics leaked to customer")
    if not any("بررسی" in m and ("اشتراک" in m or "پرداخت" in m) for m in customer):
        raise RuntimeError("Customer lacks review/check status guidance: " + repr(customer))
    if any("دوباره تلاش" in m or "۳۰ ثانیه" in m or "انجام نشد" in m
           for m in customer):
        raise RuntimeError("Customer was advised to retry or told false failure")
    if require_sql_secret and not any("database_error_45000" in m for m in operator):
        raise RuntimeError("Controlled SQL error did not preserve safe SQLSTATE")
    if result["remote_calls"] != 1:
        raise RuntimeError("Provider called more than once")


def route_cases():
    return [
        dict(name="extension_day_wallet", module="Extensions.php",
             audience="wallet", data="payIncreaseDayWithWalletprivacyhash"),
        dict(name="extension_volume_wallet", module="Extensions.php",
             audience="wallet", data="payIncreaseWithWalletprivacyhash"),
        dict(name="renewal_wallet", module="RenewalCheckout.php",
             audience="wallet", data="payRenewWithWalletprivacyhash"),
        dict(name="extension_day_manual", module="Extensions.php",
             audience="manual", data="approveIncreaseDayprivacyhash"),
        dict(name="extension_volume_manual", module="Extensions.php",
             audience="manual", data="approveIncreaseVolumeprivacyhash"),
        dict(name="renewal_manual", module="RenewalCheckout.php",
             audience="manual", data="approveRenewAccprivacyhash"),
        dict(name="wallet_settlement", module="WalletSettlement.php",
             audience="wallet", data=""),
    ]


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--run-disposable", action="store_true", required=True)
    ap.add_argument("--report", type=Path)
    args = ap.parse_args()
    names = []
    with owned.owned_database() as (work, env):
        current = work / "privacy-current"
        stages.stage_source(current, True)
        # The production-era manual approval gate now performs a fail-closed
        # shared-ledger lookup even for genuine pre-ledger review records.
        # Install only the additive isolated schema; no real DB touched.
        purchase.init(work, env, "http://127.0.0.1:9")
        sql(work, env, (ROOT/"migrations/post-v9/003_payment_attempts.sql").read_text())
        for case in route_cases():
            for mode in ("after_provider_success_sql_failure", "provider_reject",
                         "provider_exception"):
                seed(work, env, case)
                provider = ("success" if mode == "after_provider_success_sql_failure"
                            else ("reject" if mode == "provider_reject" else "throw"))
                if provider == "success":
                    if case["name"] == "wallet_settlement":
                        # Remote handler confirms success, then the final
                        # payment-state UPDATE fails; its earlier wallet
                        # debit must not be claimed as durable.
                        sql(work, env,
                            "CREATE TRIGGER achbot_privacy_sql_failure "
                            "BEFORE UPDATE ON pays FOR EACH ROW "
                            "SET NEW.price=IF(NEW.state='paid_with_wallet',"
                            "999999999999999,NEW.price)")
                    else:
                        sql(work, env,
                            "CREATE TRIGGER achbot_privacy_sql_failure "
                            "BEFORE INSERT ON increase_order FOR EACH ROW "
                            "SIGNAL SQLSTATE '45000' "
                            "SET MESSAGE_TEXT = '" + SQL_SECRET + "'")
                spec = {**case, "provider": provider}
                wallet_before = sql(work, env,
                                    "SELECT wallet FROM users WHERE userid=1001").strip()
                pay_before = sql(work, env,
                                 "SELECT state FROM pays WHERE hash_id='privacyhash'").strip()
                result = php(work, env, current, spec)
                expected = (("database_error_22003" if case["name"] ==
                             "wallet_settlement" else "database_error_45000")
                            if provider == "success" else
                            ("provider_rejected" if provider == "reject"
                             else "runtime_error"))
                assert_privacy(result, case["audience"], expected,
                               require_sql_secret=(provider == "success"
                                                   and case["name"] != "wallet_settlement"))
                # Shared day/volume/full-renewal routes commit ownership
                # and a wallet reservation BEFORE external PUT. The injected
                # increase_order INSERT failure occurs after that dispatch;
                # retained debit/processing is intentional review, not a new
                # refund. The separate legacy wallet-settlement handler keeps
                # its existing local transaction policy. No failure may duplicate debit or
                # claim delivered; preserving a spent reservation is safer
                # than rolling it back and authorizing a second remote PUT.
                changes_owner = case["name"] in (
                    "extension_day_wallet", "extension_volume_wallet", "renewal_wallet",
                    "extension_day_manual", "extension_volume_manual", "renewal_manual")
                expected_state = (
                    "processing_wallet" if changes_owner and case["audience"]=="wallet"
                    else "processing" if changes_owner else pay_before)
                expected_wallet = int(wallet_before) - (
                    100 if changes_owner and case["audience"]=="wallet" else 0)
                new_wallet=int(sql(work,env,
                    "SELECT wallet FROM users WHERE userid=1001").strip())
                new_state=sql(work,env,
                    "SELECT state FROM pays WHERE hash_id='privacyhash'").strip()
                if new_wallet!=expected_wallet or new_state!=expected_state:
                    raise RuntimeError("Invalid reserved/review financial state: "+
                                       repr((case["name"],provider,
                                             new_state,new_wallet,
                                             expected_state,expected_wallet)))
                if changes_owner:
                    # A separate native connection at the provider spy proves
                    # claim/debit durability before any simulated vendor write.
                    dispatch=result['dispatch_snapshot']
                    if dispatch['state']!=expected_state or int(dispatch['wallet'])!=expected_wallet:
                        raise RuntimeError('Provider dispatched before durable claim/debit: '+repr(dispatch))
                    claim_count=sql(work,env,"SELECT COUNT(*) FROM pays WHERE hash_id='privacyhash'").strip()
                    if claim_count!='1' or sql(work,env,"SELECT COUNT(*) FROM increase_order").strip()!='0':
                        raise RuntimeError('Ambiguous operation manufactured a duplicate claim/completion')
                    # Replaying the identical callback must neither redispatch
                    # nor debit/complete/refund the unresolved operation.
                    replay=php(work,env,current,spec,expected_remote_calls=0)
                    if replay['dispatch_snapshot'] is not None:
                        raise RuntimeError('Unresolved callback replay reached provider boundary')
                    if int(sql(work,env,"SELECT wallet FROM users WHERE userid=1001").strip())!=expected_wallet or sql(work,env,"SELECT state FROM pays WHERE hash_id='privacyhash'").strip()!=expected_state:
                        raise RuntimeError('Unresolved replay changed claim/debit')
                    customer=[str(e['args'][0]) for e in replay['events'] if e['name'] in ('sendMessage','alert') and (e['name']=='alert' or len(e['args'])<4 or e['args'][3]!=9001)]
                    if any(term in '\n'.join(customer) for term in ('برگشت داده شد','بازگردانده شد','انجام نشد')):
                        raise RuntimeError('Unresolved replay claimed false refund/failure')
                names.append(case["name"] + "_" + mode)
                if provider == "success":
                    sql(work, env, "DROP TRIGGER achbot_privacy_sql_failure")

        # A failure before the claim COMMIT must still roll back the tentative
        # debit and never dispatch. This distinguishes the post-dispatch review
        # cases above from an uncommitted local reservation failure.
        for case in (route_cases()[1],route_cases()[4]):
            seed(work,env,case)
            wallet_before=sql(work,env,"SELECT wallet FROM users WHERE userid=1001").strip()
            pay_before=sql(work,env,"SELECT state FROM pays WHERE hash_id='privacyhash'").strip()
            claimed='processing_wallet' if case['audience']=='wallet' else 'processing'
            sql(work,env,"CREATE TRIGGER achbot_privacy_preclaim_failure BEFORE UPDATE ON pays FOR EACH ROW SET NEW.price=IF(NEW.state='"+claimed+"',999999999999999,NEW.price)")
            try:
                result=php(work,env,current,{**case,'provider':'success'},expected_remote_calls=0)
                if result['dispatch_snapshot'] is not None or sql(work,env,"SELECT wallet FROM users WHERE userid=1001").strip()!=wallet_before or sql(work,env,"SELECT state FROM pays WHERE hash_id='privacyhash'").strip()!=pay_before:
                    raise RuntimeError('Preclaim failure was not fully rolled back before dispatch')
                names.append(case['name']+'_preclaim_failure_rolls_back_without_dispatch')
            finally:sql(work,env,'DROP TRIGGER achbot_privacy_preclaim_failure')

        # An unavailable native observation is an admission refusal, never
        # permission to debit or call the mutation spy. Prove both audiences
        # and both add-on kinds independently of the post-dispatch SQL cases.
        for case in (route_cases()[0],route_cases()[1],route_cases()[3],route_cases()[4]):
            seed(work,env,case)
            before=purchase.snapshot(work,env,DB)
            unknown=php(work,env,current,{**case,'provider':'success',
                'native_unknown':True},expected_remote_calls=0)
            if (unknown['native_reads']<1 or unknown['dispatch_snapshot'] is not None or
                purchase.snapshot(work,env,DB)!=before):
                raise RuntimeError('Unknown native admission changed financial state or dispatched')
            names.append(case['name']+'_unknown_native_refuses_before_claim_or_dispatch')

        # Check that tests fail when a staged operator path uses untrusted
        # exception text, without ever changing the actual source tree.
        source = current / "src/Finance/Routes/Extensions.php"
        original = source.read_text()
        catalog=catalog_source.templates(current)
        message_key=next((key for key,value in catalog.items()
                          if value.startswith('⚠️ وضعیت درخواست مشخص نیست.') and ("achbotMessage('"+key+"'") in original),None)
        message_anchor=("sendMessage("+chr(92)+"achbotMessage('"+message_key+"'"
                        if message_key else 'sendMessage("⚠️ وضعیت درخواست مشخص نیست.')
        poisoned_customer="sendMessage($e->getMessage() . "+message_anchor[len('sendMessage('):]
        mutator_cases = [
            ("operator_raw_exception",
             original.replace("achbotFinanceDiagnosticReason($e)", "$e->getMessage()")),
            ("customer_dynamic_exception",
             original.replace(message_anchor,poisoned_customer)),
        ]
        for label, poisoned in mutator_cases:
            if poisoned == original:
                raise RuntimeError("Privacy mutation target missing: " + label)
            source.write_text(poisoned)
            seed(work, env, route_cases()[1])
            sql(work, env, "CREATE TRIGGER achbot_privacy_sql_failure "
                "BEFORE INSERT ON increase_order FOR EACH ROW "
                "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '" + SQL_SECRET + "'")
            try:
                result = php(work, env, current, {
                    **route_cases()[1], "provider": "success"})
                try:
                    assert_privacy(result, "wallet", "database_error_45000")
                except RuntimeError as caught:
                    if "leaked" not in str(caught):
                        raise RuntimeError("Regression guard failed for wrong reason: " +
                                           str(caught)) from caught
                else:
                    raise RuntimeError("Privacy regression fixture missed " + label)
                names.append(label + "_detects_sensitive_leak")
            finally:
                sql(work, env, "DROP TRIGGER achbot_privacy_sql_failure")
                source.write_text(original)

    report = {"complete": True, "cases": len(names), "scenarios": names,
              "original_mariadb_socket_only": True, "external_requests": 0,
              "mutant_privacy_failures_proved": 2,
              "fresh_native_addon_admission": True,"unknown_native_refuses_preclaim": True}
    if args.report:
        args.report.write_text(json.dumps(report, indent=2, ensure_ascii=False) + "\n")
    print(json.dumps(report, ensure_ascii=False))


if __name__ == "__main__":
    main()
