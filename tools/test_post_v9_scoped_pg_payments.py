#!/usr/bin/env python3
"""Source-real imported PasarGuard non-API add-ons, PHP 8.5 only.

Every financial/native effect uses an owned MariaDB socket and 127.0.0.1
PasarGuard emulator. No installed server, payment provider, Telegram API,
bank, secret or Production database is contacted.
"""
from __future__ import annotations

import argparse
import json
import subprocess
from pathlib import Path

import test_post_v9_scoped_api_pg as api
import test_post_v9_service_unit_guards as route
import test_post_v9_finance_routes as hosted

ROOT = Path(__file__).resolve().parents[1]
DB = api.DB

# Reuse the actual finance callback dispatcher with only Telegram delivery
# intercepted. The production provider dispatch and SQL functions run as-is.
FORBIDDEN = "function achbotApplyServiceEdit(...$args){++$GLOBALS['writes'];throw new RuntimeException('forbidden_provider_effect');}"
ADDON_DRIVER = (route.PHP.replace(
    "if($n===E_DEPRECATED)return true;", "")
    .replace("$_SERVER['SCRIPT_FILENAME']=__FILE__;",
             "define('ACHBOT_PG_TEST_LOOPBACK',true);\n$_SERVER['SCRIPT_FILENAME']=__FILE__;")
    .replace(FORBIDDEN, """function editKeys(...$args){$GLOBALS['events'][]=['event'=>'DELIVERY_KEYS'];}
function achbotNotifyAdmins(...$args){$GLOBALS['events'][]=['event'=>'ADMIN_NOTICE'];}"""))


def yes(checks: list[str], good: bool, label: str):
    if not good:
        raise AssertionError(label)
    if label in checks:
        raise AssertionError("duplicate_case_name_" + label)
    checks.append(label)


def test() -> dict:
    checks: list[str] = []
    assert FORBIDDEN in route.PHP and FORBIDDEN not in ADDON_DRIVER
    with api.sandbox(imported_empty=True) as (sql, run, provider, bank, original_expiry):
        config = dict(walletState="on", cartToCartState="on", giftCardState="on",
                      manualUsdtState="on", nowPaymentOther="on",
                      nowPaymentWallet="on", nextpay="on", zarinpal="on")
        sql("INSERT INTO setting (type,value) VALUES ('BOT_STATES'," +
            api.quote_sql(json.dumps(config, separators=(",", ":"))) + ")")
        sql("UPDATE users SET wallet=50000 WHERE userid=1001")
        yes(checks, sql("SELECT wallet FROM users WHERE userid=1001") == "50000",
            "pg_disposable_finance_policy_and_wallet")
        yes(checks, sql("SELECT fileid FROM orders_list WHERE id=41") == "0" and
            sql("SELECT COUNT(*) FROM setting WHERE type='ACHBOT_SERVICE_ORIGIN:41'") == "1" and
            sql("SELECT COUNT(*) FROM service_effects WHERE effect_kind='adopt' "
                "AND source_kind='external_import'") == "1",
            "pg_actual_adoption_provenance_planless_order")

        driver = run.fixture_work / "scoped-pg-finance.php"
        driver.write_text(ADDON_DRIVER)

        def callback(kind: str, channel: str, key: str) -> dict:
            if channel == "wallet":
                verb = "payIncreaseDayWithWallet" if kind == "day" else "payIncreaseWithWallet"
                actor = 1001
            else:
                verb = "approveIncreaseDay" if kind == "day" else "approveIncreaseVolume"
                actor = 9001
            data = verb + key
            spec = {"route": "extensions", "actor": actor, "data": data,
                    "frame": {"update_id": 1000, "callback_query": {
                        "id": "pg-finance-fixture", "data": data,
                        "from": {"id": actor}, "message": {
                            "message_id": 721, "chat": {
                                "id": actor, "type": "private"}}}}}
            argv = [
                "php", "-n", "-d", "extension=mysqlnd", "-d", "extension=mysqli",
                "-d", "extension=curl", "-d", "extension=ctype",
                "-d", "allow_url_fopen=0", "-d", "display_errors=stderr",
                "-d", "log_errors=1", "-d", "error_log=/dev/stderr",
                "-d", "disable_functions=exec,shell_exec,system,passthru,popen,proc_open,mail",
                str(driver), str(ROOT), str(run.fixture_socket), DB,
                json.dumps(spec, separators=(",", ":")),
            ]
            proc = subprocess.run(argv, cwd=run.fixture_work, env=run.fixture_env,
                                  capture_output=True, text=True, timeout=50)
            if proc.returncode or proc.stderr:
                raise AssertionError(("pg_nonapi_callback_php_error", kind, channel,
                    proc.returncode, proc.stderr[-1500:], proc.stdout[-900:]))
            try:
                return json.loads(proc.stdout)
            except json.JSONDecodeError as error:
                raise AssertionError(("pg_nonapi_callback_json", proc.stdout[-1200:])) from error

        for kind, channel in (("day", "wallet"), ("day", "manual"),
                              ("volume", "wallet"), ("volume", "manual")):
            key = f"pgimport_{kind}_{channel}"
            offer = 704 if kind == "day" else 705
            issued = run("issue", kind=kind, offer_id=offer, hash=key,
                         channel=channel)
            yes(checks, issued["price"]>0 and issued["units"]>0
                and not issued["profile"],
                f"pg_{kind}_{channel}_actual_immutable_nonapi_quote")
            pay_id = issued["pay_id"]
            yes(checks, sql(f"SELECT CONCAT(fileid,':',server_id) FROM orders_list WHERE id=41")
                == "0:2" and sql(f"SELECT CONCAT(plan_id,':',server_id) FROM pays "
                                  f"WHERE id={pay_id}") == "0:2",
                f"pg_{kind}_{channel}_no_fabricated_plan_or_server")
            old_wallet = int(sql("SELECT wallet FROM users WHERE userid=1001"))
            old_audit = int(sql("SELECT COUNT(*) FROM increase_order"))
            old_writes = provider.write_count
            old_expiry = int(sql("SELECT expire_date FROM orders_list WHERE id=41"))
            result = callback(kind, channel, key)
            expected_state = "paid_with_wallet" if channel=="wallet" else "approved"
            expected_wallet = old_wallet-issued["price"] if channel=="wallet" else old_wallet
            state = sql(f"SELECT state FROM pays WHERE id={pay_id}")
            current_wallet = int(sql("SELECT wallet FROM users WHERE userid=1001"))
            audit_count = int(sql("SELECT COUNT(*) FROM increase_order"))
            updated_expiry = int(sql("SELECT expire_date FROM orders_list WHERE id=41"))
            # The source-real provider emits exactly one successful PUT with
            # authenticated local GET readback; no fake service edit is used.
            if not (state == expected_state and current_wallet == expected_wallet
                    and audit_count == old_audit+1 and provider.write_count == old_writes+1
                    and (updated_expiry>old_expiry if kind=="day"
                         else updated_expiry==old_expiry)):
                raise AssertionError(("pg_nonapi_route_not_terminal",kind,channel,
                    dict(state=state,expected=expected_state,wallet=current_wallet,
                         expected_wallet=expected_wallet,audit=audit_count,
                         expected_audit=old_audit+1,writes=provider.write_count-old_writes,
                         old_expiry=old_expiry,updated_expiry=updated_expiry,
                         events=result.get("events",[])[-7:])))
            yes(checks, True, f"pg_{kind}_{channel}_native_put_readback_audit_terminal")
            yes(checks, int(sql("SELECT COUNT(*) FROM increase_order WHERE "
                f"userid=1001 AND server_id=2 AND amount={issued['price']} "
                f"AND remark='fixture_user'"))>=1,
                f"pg_{kind}_{channel}_correct_pinned_audit_owner_amount")
            callback(kind, channel, key)
            yes(checks, sql(f"SELECT state FROM pays WHERE id={pay_id}")==expected_state
                and int(sql("SELECT wallet FROM users WHERE userid=1001"))==expected_wallet
                and int(sql("SELECT COUNT(*) FROM increase_order"))==audit_count
                and provider.write_count==old_writes+1,
                f"pg_{kind}_{channel}_replay_no_second_put_debit_or_audit")

        # Zarinpal verification is a local SoapClient spy, while the actual
        # GatewayReturn.php doAction() and local PasarGuard HTTP GET/PUT run
        # unchanged. Replace only the old fake cURL transport declarations
        # so PHP 8.5's real extension can contact 127.0.0.1 PG emulator.
        hosted_source=hosted.PHP_DRIVER.replace(
            'error_reporting(E_ALL & ~E_DEPRECATED);',
            "error_reporting(E_ALL);\ndefine('ACHBOT_PG_TEST_LOOPBACK',true);")
        hosted_source=hosted_source.replace(
            "function achbotSupportsPanel($type) { return $type==='marzban'; }",
            "function achbotSupportsPanel($type) { return in_array($type,['marzban','pasarguard'],true); }")
        start=hosted_source.index('function curl_init()')
        end=hosted_source.index('ob_start();',start)
        hosted_source=hosted_source[:start]+hosted_source[end:]
        assert "function curl_init()" not in hosted_source
        hosted_driver=run.fixture_work/"scoped-pg-hosted.php"
        hosted_driver.write_text(hosted_source)
        def hosted_call(pay_id):
            spec={"route":"do_action","get":{},"pay_row_id":pay_id,
                  "provider":"zarinpal","zarin_status":100}
            argv=[
                "php","-n","-d","extension=mysqlnd","-d","extension=mysqli",
                "-d","extension=curl","-d","extension=ctype",
                "-d","allow_url_fopen=0","-d","display_errors=stderr",
                "-d","log_errors=1","-d","error_log=/dev/stderr",
                "-d","disable_functions=exec,shell_exec,system,passthru,popen,proc_open,mail",
                str(hosted_driver),str(ROOT),DB,str(run.fixture_socket),
                json.dumps(spec,separators=(",",":")),
            ]
            proc=subprocess.run(argv,cwd=run.fixture_work,env=run.fixture_env,
                                capture_output=True,text=True,timeout=60)
            if proc.returncode or proc.stderr:
                raise AssertionError(("pg_hosted_php_error",proc.returncode,
                                      proc.stderr[-1500:],proc.stdout[-900:]))
            try:
                result=json.loads(proc.stdout)
            except json.JSONDecodeError as error:
                raise AssertionError(("pg_hosted_bad_json",proc.stdout[-900:])) from error
            if result.get("error") or result.get("fatal"):
                raise AssertionError(("pg_hosted_runtime_error",result["error"],
                                      result["fatal"],result.get("events",[])[-6:]))
            return result

        # Hosted Day: the old shared expiry finisher was a deterministic
        # post-PUT failure for imported fileid=0. Verify its complete
        # payment/attempt/audit closure and no second physical mutation.
        key="pgimport_day_hosted_zarinpal"
        issued=run("issue",kind="day",offer_id=704,hash=key,channel="hosted")
        pay_id=issued["pay_id"]
        sql(f"UPDATE pays SET payid='zarin-{key}' WHERE id={pay_id}")
        sql("INSERT INTO payment_attempts "
            "(pay_row_id,provider,order_hash,provider_reference,checkout_url,"
            "quote_amount,quote_currency,local_amount_toman,stage) VALUES "
            f"({pay_id},'zarinpal','{key}','zarin-{key}',"
            f"'https://fixture.invalid/pay/{key}',{issued['price']},'toman',"
            f"{issued['price']},'pending')")
        before_wallet=int(sql("SELECT wallet FROM users WHERE userid=1001"))
        before_audit=int(sql("SELECT COUNT(*) FROM increase_order"))
        before_expiry=int(sql("SELECT expire_date FROM orders_list WHERE id=41"))
        before_put=provider.write_count
        delivered=hosted_call(pay_id)
        outcome=[x.get("result") for x in delivered["events"]
                 if x.get("name")=="do_action_result"]
        ledger=sql("SELECT CONCAT(p.state,'|',a.stage,'|',a.provider_status) "
                   "FROM pays p JOIN payment_attempts a ON a.pay_row_id=p.id "
                   f"WHERE p.id={pay_id}")
        if not (outcome==["delivered"] and ledger=="paid|done|delivered"
                and provider.write_count==before_put+1
                and int(sql("SELECT COUNT(*) FROM increase_order"))==before_audit+1
                and int(sql("SELECT wallet FROM users WHERE userid=1001"))==before_wallet
                and int(sql("SELECT expire_date FROM orders_list WHERE id=41"))>before_expiry):
            raise AssertionError(("pg_hosted_day_not_terminal",outcome,ledger,
                provider.write_count-before_put,
                int(sql("SELECT COUNT(*) FROM increase_order"))-before_audit,
                delivered.get("events",[])[-8:]))
        yes(checks,True,"pg_imported_day_hosted_source_real_put_readback_ledger")
        hosted_call(pay_id)
        yes(checks,provider.write_count==before_put+1
            and int(sql("SELECT COUNT(*) FROM increase_order"))==before_audit+1
            and int(sql("SELECT wallet FROM users WHERE userid=1001"))==before_wallet,
            "pg_imported_day_hosted_replay_no_new_put_audit_or_debit")
        yes(checks,provider.write_count==5,
            "pg_five_nonapi_settlements_five_native_puts")
    return dict(status="PASS", cases=len(checks), checks=checks,
                php85_source_real=True, owned_socket_mariadb=True,
                native_pg_put_readback=True, real_payment_calls=0,
                real_provider_calls=0, production_database_modified=False)


def main():
    p=argparse.ArgumentParser()
    p.add_argument("--run-disposable",action="store_true",required=True)
    p.add_argument("--report",type=Path)
    a=p.parse_args()
    result=test()
    if a.report:
        a.report.write_text(json.dumps(result,indent=2)+"\n")
    print(json.dumps(result,sort_keys=True))


if __name__=="__main__":
    main()
