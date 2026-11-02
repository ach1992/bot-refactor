#!/usr/bin/env python3
"""Issue #107 R3/R5: source-real scoped API proof to PasarGuard native settlement.

Only PHP 8.5, disposable socket MariaDB, and task-owned loopback HTTP servers.
No live Telegram, bank, gift card, provider, customer or production credentials.
"""
from __future__ import annotations

import argparse
import contextlib
import datetime as dt
import hashlib
import json
import re
import subprocess
import threading
from pathlib import Path
from urllib.parse import urlsplit

import test_access_state as owned
import test_v6_native as native
import test_v8_native as purchase
import test_post_v9_pasarguard_client as pg
import test_post_v9_api_payment_evidence as sms_fixture

ROOT = Path(__file__).resolve().parents[1]
DB = purchase.SIDE[1]
DAY = 4
VOLUME = 5
DAY_PRICE = 1200
VOLUME_PRICE = 2200
INSTANCE = "b" * 32
OPERATION = "a" * 32


# The source code under test is never replaced. Only message transports are
# substituted; all provider identity, financial evidence, ledger, and service
# effect functions below resolve to the actual current source.
PHP = r'''<?php
error_reporting(E_ALL);
set_error_handler(static function($level,$msg){throw new ErrorException($msg,0,$level);});
if (PHP_VERSION_ID<80500 || PHP_VERSION_ID>=80600) throw new RuntimeException('php85_required');
define('ACHBOT_PG_TEST_LOOPBACK',true);
define('ACHBOT_PAYMENT_EVIDENCE_TEST_TRANSPORT',true);
$_SERVER['SCRIPT_FILENAME']=__FILE__;
$root=$argv[1];$spec=json_decode($argv[4],true,64,JSON_THROW_ON_ERROR);
$connection=new mysqli('localhost','root','',$argv[3],0,$argv[2]);
$connection->set_charset('utf8mb4');
$admin=9001;$from_id=1001;$botUrl='https://fixture.invalid';
$botState=['walletState'=>'on','cartToCartState'=>'on','smsApiVerifyState'=>'on',
    'giftCardState'=>'on','giftApiVerifyState'=>'on'];
$buttonValues=['back_to_main'=>'Back'];$telegramWebhookSecret='fixture-webhook-secret-32-bytes-123456';
$GLOBALS['events']=[];
function bot($method,$args) {
    $GLOBALS['events'][]=['kind'=>'telegram','method'=>$method];
    return (object)['ok'=>true,'result'=>(object)['message_id'=>222]];
}
function sendMessage(...$args){$GLOBALS['events'][]=['kind'=>'send'];return (object)['ok'=>true];}
function editKeys(...$args){$GLOBALS['events'][]=['kind'=>'edit_keys'];return (object)['ok'=>true];}
function achbotNotifyAdmins(...$args){$GLOBALS['events'][]=['kind'=>'admin_notice'];return (object)['ok'=>true];}
function getMainKeys(){return '{}';}
require_once $root.'/messages/loader.php';
require_once $root.'/src/Purchase/Core.php';
require_once $root.'/src/Finance/PaymentEvidence.php';
$paymentKeys=['bankAccount'=>'fixture-bank','holderName'=>'Fixture',
  'smsApiUrl'=>$spec['sms_base'].'/wp-json/ach-pay-manager/v1',
  'smsApiHorizonHours'=>'24','smsApiTimezone'=>'UTC','smsApiCalendar'=>'gregorian',
  'smsApiToken'=>achbotPaymentEvidenceProtect('setting:smsApiToken',['value'=>'fixture-sms-secret'])];
$mainValues=achbotMessages('legacy_messages');
require_once $root.'/src/Finance/ApiPayments.php';
require_once $root.'/src/Panel/ProviderRuntime.php';
// The production dispatcher loads this native storage layer lazily. The
// isolated bootstrap invokes its storage primitives directly.
achbotPgLoad();
$result=null;
try {
 $op=$spec['op'];
 if($op==='bootstrap') {
    $snap=achbotProviderSnapshot($connection,2,7);
    $panel=achbotPgServerPanel($connection,2);
    $identity=['remote_user_id'=>17,
        'remote_created_at'=>'2026-10-26T05:00:00.123456+00:00',
        'operation_marker'=>'achbot:v1:'.str_repeat('a',32)];
    $read=achbotPgRead($panel,$identity);
    if(($read['success']??false)!==true) throw new RuntimeException('fixture_pg_initial_identity');
    $user=$read['user'];
    $ent=['data_limit'=>$user['data_limit'],'expire'=>$user['expire'],
        'group_ids'=>$user['group_ids'],'status'=>$user['status']];
    if($connection->begin_transaction()!==true)throw new RuntimeException('fixture_begin');
    achbotProviderReserveAccount($connection,1,$snap,'fixture_user',$identity['operation_marker'],$ent);
    if(!$connection->commit())throw new RuntimeException('fixture_commit');
    achbotProviderVerifyAccount($connection,1,$user);
    achbotProviderLinkAccount($connection,1,41);
    $result=['linked'=>achbotPgOrderContext($connection,41)['account']['state']==='linked',
        'groups'=>$user['group_ids']];
 } elseif ($op==='issue') {
    $kind=$spec['kind'];$id=(int)$spec['offer_id'];
    $order=$connection->query('SELECT * FROM orders_list WHERE id=41')->fetch_assoc();
    $ctx=achbotAddonContext($connection,$order);
    $offer=achbotAddonOfferById($connection,$kind,$ctx,$id);
    if(!$offer)throw new RuntimeException('fixture_offer_missing');
    $price=(int)$offer['price'];$units=(int)$offer['volume'];
    $snap=achbotAddonQuote($order,$ctx,$kind,$offer,$price);
    $hash=$spec['hash'];
    $type='INCREASE_'.strtoupper($kind).'_41_'.$id;
    $vol=$kind==='volume'?$units:0;$day=$kind==='day'?$units:0;
    $stmt=$connection->prepare("INSERT INTO pays(hash_id,user_id,type,plan_id,server_id,
        order_id,volume,day,price,request_date,state,addon_scope_snapshot)
        VALUES (?,1001,?,7,2,41,?,?,?,UNIX_TIMESTAMP(),'pending',?)");
    $stmt->bind_param('ssiiis',$hash,$type,$vol,$day,$price,$snap);
    $stmt->execute();$payId=(int)$stmt->insert_id;$stmt->close();
    achbotPgPinPayment($connection,$payId);
    $stmt=$connection->prepare("INSERT INTO payment_attempts
       (pay_row_id,provider,order_hash,quote_amount,quote_currency,
        local_amount_toman,checkout_url,stage)
       VALUES (?,'bank_transfer',?,?,'toman',?,'{\"v\":1,\"method\":\"bank_transfer\"}','review')");
    $stmt->bind_param('isii',$payId,$hash,$price,$price);$stmt->execute();$stmt->close();
    if(!$connection->begin_transaction())throw new RuntimeException('fixture_pin_begin');
    $pay=achbotApiPaymentRow($connection,$payId,true);
    $attempt=achbotApiPaymentAttempt($connection,$payId,true);
    achbotApiPaymentPinLocked($connection,$pay,$attempt);
    if(!$connection->commit())throw new RuntimeException('fixture_pin_commit');
    $stmt=$connection->prepare("UPDATE pays SET state='have_sent',
       message_id=222,chat_id='9001' WHERE id=?");
    $stmt->bind_param('i',$payId);$stmt->execute();$stmt->close();
    $result=['pay_id'=>$payId,'price'=>$price,'units'=>$units,
        'profile'=>achbotPaymentEvidenceSnapshot(achbotApiPaymentAttempt($connection,$payId))!==null];
 } elseif ($op==='reserve') {
    $id=(int)$spec['pay_id'];
    $a=achbotApiPaymentAttempt($connection,$id);
    $profile=achbotPaymentEvidenceSnapshot($a);
    $e=achbotPaymentEvidenceSmsNormalize($spec['sms'],$profile);
    $result=$e===null?null:achbotPaymentEvidenceReserve($connection,$id,$e,'settle');
    if($result!==null)$result=['reserved'=>true];
 } elseif ($op==='consume') {
    $id=(int)$spec['pay_id'];$pending=achbotPaymentEvidencePending($connection,$id);
    $result=$pending?achbotPaymentEvidenceConsume($connection,$pending):null;
    if(is_array($result))$result=['status'=>$result['status']??null];
 } elseif ($op==='preflight') {
    $pay=achbotApiPaymentRow($connection,(int)$spec['pay_id']);
    $s=achbotApiPaymentSubjectPreflight($connection,$pay);
    $result=['valid'=>$s['valid'],'proof'=>achbotPaymentEvidenceGetProof($connection,(int)$spec['pay_id'])!==null];
 } elseif ($op==='settle') {
    $id=(int)$spec['pay_id'];$proof=achbotPaymentEvidenceGetProof($connection,$id);
    $result=$proof?achbotApiPaymentSettle($connection,$id,$proof):'missing_proof';
 } elseif ($op==='direct_edit') {
    $id=(int)$spec['pay_id'];
    $kind=(string)$spec['kind'];
    $params=['pay_id'=>$id,'remark'=>'fixture_user',
        $kind==='day'?'plus_day':'plus_volume'=>$kind==='day'?4:5];
    $v=achbotApplyServiceEdit($connection,2,$params);
    $result=['success'=>$v->success??false,'verified'=>$v->verified??false,
        'applied_expire'=>$v->applied_expire??null,
        'applied_bytes'=>$v->applied_bytes??null];
 } else throw new RuntimeException('fixture_op_unknown');
 echo json_encode(['ok'=>true,'result'=>$result,'events'=>$GLOBALS['events']],
      JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
} catch(Throwable $e) {
 echo json_encode(['ok'=>false,'error'=>get_class($e).':'.$e->getMessage()],
      JSON_THROW_ON_ERROR);
}
'''


class DriftHandler(pg.Handler):
    """Deterministic post-fence changes, observed only by a local provider."""

    def handle_request(self):
        s = self.server
        path = urlsplit(self.path).path
        if (self.command == "GET" and path == "/api/user/by-id/17"
                and getattr(s, "drift", None) and not getattr(s, "drift_fired", False)
                and s.mutation_claimed()):
            s.drift_fired = True
            if s.drift == "revoke":
                s.allowed_group_ids = [5]
            elif s.drift == "disable":
                s.disabled_group_ids = [2]
            elif s.drift == "remove":
                s.available_group_ids = [5]
            elif s.drift == "membership":
                s.user["group_ids"] = [5]
            elif s.drift == "foreign":
                s.user["created_at"] = "2026-10-25T05:00:00Z"
        super().handle_request()


@contextlib.contextmanager
def native_panel(mutation_claimed):
    import http.server
    server = http.server.ThreadingHTTPServer(("127.0.0.1", 0), DriftHandler)
    server.trace = []
    server.user = json.loads(json.dumps(pg.USER))
    server.mode = "ok"
    server.write_count = 0
    server.links_requested = False
    server.drift = None
    server.drift_fired = False
    server.mutation_claimed = mutation_claimed
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    try:
        yield server, "http://127.0.0.1:" + str(server.server_port)
    finally:
        server.shutdown()
        server.server_close()
        thread.join(timeout=5)


def quote_sql(value):
    """Only fixture-owned, never user input; avoid SQL injection."""
    return "'" + str(value).replace("'", "''") + "'"


@contextlib.contextmanager
def sandbox():
    # Every scenario owns its own MariaDB socket and fresh native/API state;
    # a durable failed mutation in one case can never be silently repaired to
    # make a later test pass.
    with owned.owned_database() as (work, env):
        purchase.init(work, env, "http://127.0.0.1:9")

        def sql(statement):
            return native.sql(work, env, statement, DB).strip()

        for name in (
            "001_marzban_panel_group_id.sql",
            "003_payment_attempts.sql",
            "006_payment_message_refs.sql",
            "007_service_effects.sql",
            "008_referral_awards.sql",
            "009_bank_buy_invoice_claims.sql",
            "011_provider_identity.sql",
            "012_shared_provider_storage.sql",
            "014_api_payment_evidence.sql",
            "015_scoped_addons.sql",
        ):
            sql((ROOT / "migrations/post-v9" / name).read_text())

        # The SMS API asserts via another socket connection that the
        # application never holds a financial SQL transaction across HTTP.
        def sms_dbcheck():
            sql("SET innodb_lock_wait_timeout=1; "
                "UPDATE payment_attempts SET last_checked_at=UTC_TIMESTAMP()")

        sms_server = sms_fixture.Server(sms_dbcheck)
        sms_thread = threading.Thread(
            target=sms_server.serve_forever, daemon=True)
        sms_thread.start()
        sms_url = f"http://127.0.0.1:{sms_server.server_port}"

        def mutation_claimed():
            return sql(
                "SELECT COUNT(*) FROM service_effects WHERE id=1 "
                "AND provider_state='mutating'") == "1"

        try:
            with native_panel(mutation_claimed) as (provider, provider_url):
                provider_hash = hashlib.sha256(provider_url.encode()).hexdigest()
                snapshot = json.dumps({
                    "provider": "pasarguard", "panel_id": 1,
                    "instance_key": INSTANCE,
                    "endpoint_sha256": provider_hash,
                    "group_ids": [2, 5],
                }, separators=(",", ":"))
                epoch = int(dt.datetime.fromisoformat(
                    pg.USER["expire"].replace("Z", "+00:00")).timestamp())
                sql("UPDATE server_config SET type='pasarguard',"
                    f"panel_url={quote_sql(provider_url)},"
                    "password='fixture-private-key',"
                    "provider_connection_id=1,provider_instance_key="
                    f"{quote_sql(INSTANCE)},provider_version='5.5.1',"
                    "provider_enabled=1 WHERE id=2")
                sql("UPDATE server_info SET provider_group_ids='[2,5]' WHERE id=2")
                sql("INSERT INTO orders_list "
                    "(id,userid,token,transid,fileid,server_id,inbound_id,remark,"
                    "uuid,expire_date,link,amount,status,date) VALUES "
                    f"(41,1001,'fixture-token','',7,2,0,'fixture_user',"
                    f"'fixture-native-uuid',{epoch},'[]',100,1,'fixture')")
                sql("INSERT INTO service_effects "
                    "(id,source_kind,source_ref,actor_userid,ordinal,operation_id,"
                    "target_server_id,target_key,endpoint_sha256,plan_id,"
                    "remote_username,requested_expire,requested_bytes,"
                    "request_sha256,provider_snapshot) VALUES ("
                    f"1,'trial','fixture-native-identity',1001,1,"
                    f"{quote_sql(OPERATION)},2,'pg:fixture',"
                    f"{quote_sql(provider_hash)},7,'fixture_user',{epoch},"
                    f"{pg.USER['data_limit']},REPEAT('c',64),{quote_sql(snapshot)})")
                for kind, offer, units, price in [
                    ("day", 701, DAY, DAY_PRICE),
                    ("volume", 702, VOLUME, VOLUME_PRICE),
                ]:
                    table = "increase_day" if kind == "day" else "increase_plan"
                    group_hash = hashlib.sha256(b"P:2:2,5").hexdigest()
                    sql(f"INSERT INTO {table} "
                        "(id,volume,price,server_id,group_ids,scope_hash) "
                        f"VALUES ({offer},{units},{price},2,'[2,5]',"
                        f"'{group_hash}')")

                driver = work / "scoped-api-pg.php"
                driver.write_text(PHP)

                def run(op, **params):
                    spec = {"op": op, "sms_base": sms_url, **params}
                    argv = [
                        "php", "-n", "-d", "extension=mysqlnd",
                        "-d", "extension=mysqli", "-d", "extension=curl",
                        "-d", "extension=ctype",
                        "-d", "allow_url_fopen=0", "-d", "display_errors=stderr",
                        "-d", "log_errors=1", "-d", "error_log=/dev/stderr",
                        "-d", ("disable_functions=exec,shell_exec,system,"
                               "passthru,popen,proc_open,mail"),
                        str(driver), str(ROOT), str(work / "mysql.sock"), DB,
                        json.dumps(spec, separators=(",", ":")),
                    ]
                    proc = subprocess.run(argv, cwd=work, env=env,
                                          capture_output=True, text=True,
                                          timeout=50)
                    if proc.returncode:
                        raise AssertionError(("php85_source_failed", op,
                            proc.returncode, proc.stderr[-1500:], proc.stdout[-800:]))
                    try:
                        response = json.loads(proc.stdout)
                    except json.JSONDecodeError as error:
                        raise AssertionError(("php85_invalid_json", op,
                            proc.stdout[-1000:], proc.stderr[-1000:])) from error
                    lines = [
                        re.sub(r'^\[\d{2}-[A-Za-z]{3}-\d{4} '
                               r'\d{2}:\d{2}:\d{2} UTC\] ', '', line)
                        for line in proc.stderr.strip().splitlines()
                    ]
                    if any(not line.startswith((
                            "achbot_api_claim_review pay_id=",
                            "achbot_api_effect_review pay_id=",
                            "achbot_pg_edit_needs_review pay_id=",
                            "achbot_api_paid_delivery_review pay_id=",
                            "achbot_api_invoice_edit_deferred pay_id=",
                            "achbot_api_invoice_reference_deferred")) for line in lines):
                        raise AssertionError(("unexpected_php_error", op, lines[-8:]))
                    if not response["ok"]:
                        raise AssertionError(("native_source_exception",
                            op, response["error"]))
                    if op == "settle":
                        run.last_diagnostic = [
                            line.split(" code=", 1)[-1][:128]
                            for line in lines
                            if line.startswith("achbot_pg_edit_needs_review pay_id=")]
                    return response["result"]

                linked = run("bootstrap")
                assert linked["linked"] and linked["groups"] == [2, 5], linked
                assert provider.write_count == 0
                yield sql, run, provider, sms_server, epoch
        finally:
            sms_server.shutdown()
            sms_server.server_close()
            sms_thread.join(timeout=5)


def evidence_sms(message_id, price, wrong_amount=0):
    moment = dt.datetime.now(dt.timezone.utc)
    return {
        "id": message_id, "public_uid": "UID" + str(message_id),
        "provider": "BANK_FIXTURE",
        "provider_sms_id": "provider" + str(message_id),
        "sender": "BANK", "mobile_source": "phone",
        "bank_key": "fixture", "rule_id": 7,
        "amount": price + wrong_amount, "display_unit": "toman",
        "sms_date": moment.strftime("%Y-%m-%d"),
        "sms_time": moment.strftime("%H:%M:%S"), "status": "ready",
    }


def claim_proof(run, bank, kind, key, *, wrong_amount=0):
    offer = 701 if kind == "day" else 702
    issued = run("issue", kind=kind, offer_id=offer, hash=key)
    assert issued["profile"] and issued["price"] == (
        DAY_PRICE if kind == "day" else VOLUME_PRICE), issued
    message = evidence_sms(1, issued["price"], wrong_amount=wrong_amount)
    bank.messages["1"] = dict(message)
    return issued, message


def terminal_state(sql, pay_id):
    return sql(
        "SELECT CONCAT(p.state,'|',"
        "COALESCE(a.evidence_fulfillment_state,'NULL'),'|',"
        "COALESCE(a.evidence_state,'NULL')) "
        "FROM pays p JOIN payment_attempts a ON a.pay_row_id=p.id "
        f"WHERE p.id={pay_id}")


def test():
    checks = []

    def passed(condition, name, context=None):
        if not condition:
            raise AssertionError((name, context))
        checks.append(name)

    for kind in ("day", "volume"):
        with sandbox() as (sql, run, provider, bank, old_expiry):
            issued, message = claim_proof(
                run, bank, kind, f"scoped_api_pg_{kind}")
            payment_id = issued["pay_id"]
            passed(run("preflight", pay_id=payment_id)["valid"],
                   kind + "_native_scoped_api_preflight")
            passed(run("reserve", pay_id=payment_id, sms=message) == {
                "reserved": True}, kind + "_evidence_reserved")
            passed(run("consume", pay_id=payment_id)["status"] == "proven"
                   and bank.consumes.get("1") == 1,
                   kind + "_original_bank_evidence_consumed_once")
            before_wallet = sql("SELECT wallet FROM users WHERE userid=1001")
            settled = run("settle", pay_id=payment_id)
            current = terminal_state(sql, payment_id)
            audit = sql("SELECT COUNT(*) FROM increase_order")
            mutation = sql("SELECT provider_state FROM service_effects WHERE id=1")
            writes = provider.write_count
            terminal_ok=(settled == "confirmed" and writes == 1 and
                   current.startswith("auto_cart_approved|done|") and
                   audit == "1" and mutation == "linked" and
                   sql("SELECT wallet FROM users WHERE userid=1001") == before_wallet)
            observed={'result':settled,'payment':current,'audit':audit,
                'native_journal':mutation,'writes':writes,
                'internal_reason':getattr(run, 'last_diagnostic', [])}
            if not terminal_ok:
                # Only synthetic loopback state and fixed field names: never
                # print provider credentials, customer identifiers or raw API.
                observed['http_sequence']=[(method, urlsplit(path).path)
                    for method,path,_ in provider.trace[-15:]]
                observed['native_entitlement']={k:provider.user.get(k)
                    for k in ['expire','data_limit','group_ids','status']}
                observed['intent']=sql("SELECT CONCAT(COALESCE(JSON_UNQUOTE("
                    "JSON_EXTRACT(provider_mutation_snapshot,'$.phase')),'NULL'),"
                    "'|',COALESCE(JSON_UNQUOTE(JSON_EXTRACT("
                    "provider_mutation_snapshot,'$.result')),'NULL')) "
                    "FROM service_effects WHERE id=1")
            passed(terminal_ok,kind + "_api_proof_native_put_audit_terminal",observed)
            assert provider.user["group_ids"] == [2, 5]
            if kind == "day":
                passed(provider.user["expire"] == dt.datetime.fromtimestamp(
                    old_expiry + DAY * 86400, dt.timezone.utc
                ).strftime("%Y-%m-%dT%H:%M:%SZ") or
                    int(dt.datetime.fromisoformat(
                        provider.user["expire"].replace("Z","+00:00")
                    ).timestamp()) == old_expiry + DAY * 86400,
                    "api_day_correct_native_absolute_expiry")
            else:
                passed(provider.user["data_limit"] ==
                    pg.USER["data_limit"] + VOLUME * 1073741824,
                    "api_volume_correct_native_absolute_quota")
            passed(run("settle", pay_id=payment_id) == "settled" and
                   provider.write_count == writes and
                   bank.consumes.get("1") == 1 and
                   sql("SELECT COUNT(*) FROM increase_order") == "1",
                   kind + "_api_replay_no_duplicate_evidence_or_native_mutation")
            passed(sql("SELECT provider_mutation_key FROM service_effects WHERE id=1")
                   == "pay:" + str(payment_id),
                   kind + "_native_account_mutation_journal_pinned")

    for change in ("revoke", "disable", "remove", "membership", "foreign"):
        with sandbox() as (sql, run, provider, bank, _):
            issued, message = claim_proof(
                run, bank, "day", "scoped_api_pg_drift_" + change)
            payment_id = issued["pay_id"]
            passed(run("reserve", pay_id=payment_id, sms=message) is not None
                   and run("consume", pay_id=payment_id)["status"] == "proven",
                   "drift_" + change + "_exact_api_proof")
            before_wallet = sql("SELECT wallet FROM users WHERE userid=1001")
            provider.drift = change
            result = run("settle", pay_id=payment_id)
            state = terminal_state(sql, payment_id)
            fence = sql("SELECT provider_state FROM service_effects WHERE id=1")
            passed(provider.drift_fired and result == "review" and
                   provider.write_count == 0 and
                   state.startswith("processing|dispatched|") and
                   fence == "mutating" and
                   sql("SELECT wallet FROM users WHERE userid=1001")
                   == before_wallet and
                   sql("SELECT COUNT(*) FROM increase_order") == "0",
                   "postclaim_" + change + "_holds_without_remote_put",
                   [provider.drift_fired, result, state, fence,
                    provider.write_count])
            passed(run("settle", pay_id=payment_id) == "review" and
                   provider.write_count == 0,
                   "postclaim_" + change + "_replay_stays_held")

    with sandbox() as (sql, run, provider, bank, _):
        issued, message = claim_proof(
            run, bank, "volume", "scoped_api_pg_unknown_ack")
        pay = issued["pay_id"]
        assert run("reserve", pay_id=pay, sms=message) is not None
        assert run("consume", pay_id=pay)["status"] == "proven"
        provider.mode = "write_lost"
        first = run("settle", pay_id=pay)
        holds = terminal_state(sql, pay)
        passed(first == "review" and provider.write_count == 1 and
               holds.startswith("processing|dispatched|") and
               sql("SELECT provider_state FROM service_effects WHERE id=1")
               == "mutating", "unknown_pg_put_ack_holds_durable_effect",
               [first, holds, provider.write_count])
        provider.mode = "ok"
        passed(run("settle", pay_id=pay) == "review" and
               provider.write_count == 1 and
               sql("SELECT COUNT(*) FROM increase_order") == "0",
               "unknown_pg_put_ack_never_replays_or_refunds")

    with sandbox() as (sql, run, provider, bank, _):
        issued, wrong = claim_proof(
            run, bank, "day", "scoped_api_pg_wrong_amount",
            wrong_amount=1)
        pay = issued["pay_id"]
        passed(run("reserve", pay_id=pay, sms=wrong) is None and
               provider.write_count == 0 and
               terminal_state(sql, pay).startswith("have_sent|NULL|") and
               sql("SELECT COUNT(*) FROM payment_transactions") == "0",
               "wrong_sms_amount_never_binds_scoped_quote")

    with sandbox() as (sql, run, provider, bank, _):
        issued, message = claim_proof(
            run, bank, "day", "scoped_api_pg_scope_changed")
        pay = issued["pay_id"]
        assert run("reserve", pay_id=pay, sms=message) is not None
        assert run("consume", pay_id=pay)["status"] == "proven"
        sql("UPDATE increase_day SET scope_hash=REPEAT('f',64) WHERE id=701")
        passed(run("settle", pay_id=pay) == "review" and
               provider.write_count == 0 and
               terminal_state(sql, pay).startswith("have_sent|NULL|"),
               "scoped_offer_reassignment_rejected_before_api_claim")

    with sandbox() as (sql, run, provider, bank, _):
        issued, message = claim_proof(
            run, bank, "volume", "scoped_api_pg_proof_race")
        pay = issued["pay_id"]
        from concurrent.futures import ThreadPoolExecutor
        with ThreadPoolExecutor(max_workers=2) as pool:
            responses = list(pool.map(
                lambda _: run("reserve", pay_id=pay, sms=message),
                (1, 2)))
        passed(sum(r is not None for r in responses) == 1 and
               sql("SELECT COUNT(*) FROM payment_transactions") == "1" and
               provider.write_count == 0,
               "scoped_api_evidence_reservation_concurrent_single_winner",
               responses)

    return {
        "status": "PASS", "cases": len(checks), "checks": checks,
        "php85": True, "scoped_api_proof_settlement": True,
        "native_pg_put_readback": True, "real_payment_calls": 0,
        "real_provider_calls": 0, "production_database_modified": False,
    }


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--run-disposable", action="store_true", required=True)
    parser.add_argument("--report", type=Path)
    args = parser.parse_args()
    result = test()
    if args.report:
        args.report.write_text(json.dumps(result, sort_keys=True, indent=2) + "\n")
    print(json.dumps(result, sort_keys=True))
