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
function editMarzbanConfig($server_id, $info) {
    $GLOBALS['remote_calls']++;
    if ($GLOBALS['case']['provider'] === 'reject') {
        return (object)['success' => false,
            'msg' => 'SECRET_PROVIDER_TOKEN_LOCAL_87a3 bearer:fake'];
    }
    if ($GLOBALS['case']['provider'] === 'throw') {
        throw new RuntimeException('SECRET_PROVIDER_TOKEN_LOCAL_87a3 runtime');
    }
    return (object)['success' => true];
}
try {
    require $root . '/buy_helpers.php';
    if ($case['module'] === 'WalletSettlement.php') {
        require $root . '/src/Finance/WalletSettlement.php';
        $result = processWalletPayment($connection, 'privacyhash',
            function ($payment, $account) {
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
echo json_encode(['events' => $events, 'remote_calls' => $remote_calls,
                  'uncaught' => $uncaught], JSON_INVALID_UTF8_SUBSTITUTE |
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
                {order_id},0,0,100,{request_date},'{pay_state}',
                {message_id},{chat_id})""")
    return order_id


def php(work, env, stage, case):
    path = work / "finance-privacy-driver.php"
    if not path.exists():
        path.write_text(DRIVER)
    spec = {
        "module": case["module"],
        "provider": case["provider"],
        "audience": case["audience"],
        "data": case["data"],
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
    if result["uncaught"] or result["remote_calls"] != 1:
        raise RuntimeError("Privacy route failed before/after remote exactly once: "
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
    if not any("Hash ID:" in m and "Reason (sanitized):" in m
               and expected_code in m and "External effect: unverified" in m
               for m in operator):
        raise RuntimeError("No actionable redacted operator report: " + repr(operator))
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
                if (wallet_before != sql(work, env,
                        "SELECT wallet FROM users WHERE userid=1001").strip()
                    or pay_before != sql(work, env,
                        "SELECT state FROM pays WHERE hash_id='privacyhash'").strip()):
                    raise RuntimeError("Uncertain provider path changed local money/payment state")
                names.append(case["name"] + "_" + mode)
                if provider == "success":
                    sql(work, env, "DROP TRIGGER achbot_privacy_sql_failure")

        # Check that tests fail when a staged operator path uses untrusted
        # exception text, without ever changing the actual source tree.
        source = current / "src/Finance/Routes/Extensions.php"
        original = source.read_text()
        mutator_cases = [
            ("operator_raw_exception",
             original.replace("achbotFinanceDiagnosticReason($e)", "$e->getMessage()")),
            ("customer_dynamic_exception",
             original.replace('sendMessage("⚠️ وضعیت درخواست مشخص نیست.',
                              'sendMessage($e->getMessage() . "⚠️ وضعیت درخواست مشخص نیست.')),
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
              "mutant_privacy_failures_proved": 2}
    if args.report:
        args.report.write_text(json.dumps(report, indent=2, ensure_ascii=False) + "\n")
    print(json.dumps(report, ensure_ascii=False))


if __name__ == "__main__":
    main()
