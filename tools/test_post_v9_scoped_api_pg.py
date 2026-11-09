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
    $imported=($spec['imported_empty']??false)===true;
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
    if ($imported) {
        // Real source adoption, not a fabricated CREATE entitlement: the
        // source requires exact order, actor, bearer credential and
        // external-import provenance. Imported accounts may have Groups=[].
        require_once $root.'/src/Panel/ProviderImport.php';
        $token='fixture-import-token';
        $proof=['target'=>achbotPgImportTarget($panel),'token'=>$token,
            'input_sha256'=>hash('sha256','https://alias.invalid/import/'.$token)];
        achbotProviderAdoptAccount($connection,2,$user,$proof,1001,41,1001,9001);
    } else {
        $snap=achbotProviderSnapshot($connection,2,7);
        achbotProviderReserveAccount($connection,1,$snap,'fixture_user',$identity['operation_marker'],$ent);
    }
    if(!$connection->commit())throw new RuntimeException('fixture_commit');
    if (!$imported) {
        achbotProviderVerifyAccount($connection,1,$user);
        achbotProviderLinkAccount($connection,1,41);
    }
    $context=achbotPgOrderContext($connection,41);
    $result=['linked'=>$context['account']['state']==='linked',
        'imported'=>$imported,
        'identity_mode'=>$context['identity']['identity_mode']??null,
        'groups'=>$user['group_ids']];
 } elseif ($op==='issue') {
    $kind=$spec['kind'];$id=(int)$spec['offer_id'];
    $order=$connection->query('SELECT * FROM orders_list WHERE id=41')->fetch_assoc();
    $ctx=achbotAddonContext($connection,$order);
    $offer=achbotAddonOfferById($connection,$kind,$ctx,$id);
    if(!$offer)throw new RuntimeException('fixture_offer_missing');
    $price=(int)$offer['price'];$units=(int)$offer['volume'];
    $legacy=($spec['legacy']??false)===true;
    if ($legacy && ($offer['server_id']??null)!==null)
        throw new RuntimeException('fixture_historical_must_be_global');
    $snap=$legacy?null:achbotAddonQuote($order,$ctx,$kind,$offer,$price);
    $hash=$spec['hash'];
    $type='INCREASE_'.strtoupper($kind).'_41_'.$id;
    $vol=$kind==='volume'?$units:0;$day=$kind==='day'?$units:0;
    $planId=(int)$order['fileid']; // Real imported references remain planless.
    $stmt=$connection->prepare("INSERT INTO pays(hash_id,user_id,type,plan_id,server_id,
        order_id,volume,day,price,request_date,state,addon_scope_snapshot)
        VALUES (?,1001,?,?,2,41,?,?,?,UNIX_TIMESTAMP(),'pending',?)");
    $stmt->bind_param('ssiiiis',$hash,$type,$planId,$vol,$day,$price,$snap);
    $stmt->execute();$payId=(int)$stmt->insert_id;$stmt->close();
    achbotPgPinPayment($connection,$payId);
    $channel=(string)($spec['channel']??'api');
    if ($channel==='api') {
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
    } elseif ($channel==='manual') {
        // New non-API manual receipts keep the original actual ledger and
        // operator-reviewed stored photo. A missing ledger is legacy only.
        $stmt=$connection->prepare("INSERT INTO payment_attempts
           (pay_row_id,provider,order_hash,quote_amount,quote_currency,
            local_amount_toman,checkout_url,stage)
           VALUES (?,'bank_transfer',?,?,'toman',?,
             '{\"v\":1,\"method\":\"bank_transfer\",\"receipt_file_id\":\"fixture-photo-id\"}','review')");
        $stmt->bind_param('isii',$payId,$hash,$price,$price);$stmt->execute();$stmt->close();
    } elseif (!in_array($channel,['wallet','hosted'],true)) {
        throw new RuntimeException('fixture_channel_invalid');
    }
    $state=$channel==='api'||$channel==='manual'?'have_sent':'pending';
    $stmt=$connection->prepare("UPDATE pays SET state=?,
       message_id=222,chat_id='9001' WHERE id=?");
    $stmt->bind_param('si',$state,$payId);$stmt->execute();$stmt->close();
    $result=['pay_id'=>$payId,'price'=>$price,'units'=>$units,
        'profile'=>$channel==='api'
            && achbotPaymentEvidenceSnapshot(achbotApiPaymentAttempt($connection,$payId))!==null];
 } elseif ($op==='policy_probe') {
    $createSnap=achbotProviderSnapshot($connection,2,7);
    $createSnap['group_ids']=[];
    $refused=false;
    try {achbotProviderCanonicalSnapshot($createSnap);}
    catch(RuntimeException $e) {$refused=$e->getMessage()==='provider_groups_invalid';}
    $panel=achbotPgServerPanel($connection,2);
    $created=achbotPgCreate($panel,['username'=>'never_create_empty',
        'operation_marker'=>'achbot:v1:'.str_repeat('d',32),
        'data_limit'=>2147483648,'expire'=>2000000000,'group_ids'=>[]]);
    $result=['empty_create_snapshot_rejected'=>$refused,
        'native_create_denied'=>($created['success']??null)===false &&
            ($created['reason']??null)==='pg_groups_required'];
 } elseif ($op==='catalog') {
    $order=$connection->query('SELECT * FROM orders_list WHERE id=41')->fetch_assoc();
    $context=achbotAddonContext($connection,$order);
    $result=['context_groups'=>$context['groups'],
        'offers'=>achbotAddonCatalog($connection,(string)$spec['kind'],$context)];
 } elseif ($op==='reserve') {
    $id=(int)$spec['pay_id'];
    $a=achbotApiPaymentAttempt($connection,$id);
    $profile=achbotPaymentEvidenceSnapshot($a);
    $e=achbotPaymentEvidenceSmsNormalize($spec['sms'],$profile);
    $result=$e===null?null:achbotPaymentEvidenceReserve($connection,$id,$e,'settle');
    if($result!==null)$result=['reserved'=>true,
        'transaction_id'=>(int)$result['transaction_id'],
        'operation'=>(string)$result['operation']];
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
 } elseif ($op==='finisher_probe') {
    // Deliberately synthetic remote-verification object only for a negative
    // AUTHORIZATION test. This never dispatches provider traffic or commits
    // a service effect. Positive financial tests use actual native PUT.
    $order=$connection->query('SELECT * FROM orders_list WHERE id=41')->fetch_assoc();
    if(!$connection->begin_transaction())throw new RuntimeException('probe_begin');
    $verified=(object)['success'=>true,'verified'=>true,
        'applied_expire'=>(int)$order['expire_date']+86400,
        'applied_bytes'=>2147483648];
    try {
        achbotStoreVerifiedServiceExpiryLocked($connection,$order,$verified);
        $result=['allowed'=>true];
    } catch(Throwable $error) {
        $result=['allowed'=>false,'reason'=>$error->getMessage()];
    } finally {
        $connection->rollback();
    }
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
            elif s.drift == "empty_to_nonempty":
                s.user["group_ids"] = [2]
            elif s.drift == "authority_fail":
                s.mode = "permission_missing"
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
def sandbox(*, imported_empty=False):
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
            "022_gateway_review_archive.sql",
            "006_payment_message_refs.sql",
            "007_service_effects.sql",
            "008_referral_awards.sql",
            "009_bank_buy_invoice_claims.sql",
            "011_provider_identity.sql",
            "012_shared_provider_storage.sql",
            "014_api_payment_evidence.sql",
            "018_payment_delivery_intents.sql",
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
                if imported_empty:
                    provider.user["group_ids"] = []
                    # No enabled Group must be invented for a Group-less
                    # imported subscription, even if credentials allow none.
                    provider.allowed_group_ids = []
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
                # Current PG native access is explicit on each plan, never
                # inherited from a server-level default.
                sql("UPDATE server_plans SET provider_group_ids='[2,5]' WHERE id IN (7,8)")
                for migration in ("016_remove_pasarguard_server_groups.sql",
                                  "017_shared_plan_changes.sql"):
                    sql((ROOT / "migrations/post-v9" / migration).read_text())
                if imported_empty:
                    sql("INSERT INTO add_link(remark,token,added_by) "
                        "VALUES ('fixture_user','fixture-import-token',1001)")
                    sql("INSERT INTO orders_list "
                        "(id,userid,token,transid,fileid,server_id,inbound_id,remark,"
                        "uuid,expire_date,link,amount,status,date,autoRenew) VALUES "
                        f"(41,1001,'fixture-import-token','',0,2,0,'fixture_user',"
                        f"'fixture-native-uuid',{epoch},'[]',0,1,'fixture','off')")
                else:
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
                sql("INSERT INTO increase_day(id,volume,price) "
                    "VALUES (703,3,300)")
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

                if imported_empty:
                    # Exact Server day and Global volume fallbacks. Group-only
                    # rows 701/702 must remain ineligible for Groups=[].
                    server_scope=hashlib.sha256(b"S:2").hexdigest()
                    global_scope=hashlib.sha256(b"G").hexdigest()
                    sql("INSERT INTO increase_day "
                        "(id,volume,price,server_id,scope_hash) VALUES "
                        f"(704,{DAY},{DAY_PRICE},2,'{server_scope}')")
                    sql("INSERT INTO increase_plan "
                        "(id,volume,price,scope_hash) VALUES "
                        f"(705,{VOLUME},{VOLUME_PRICE},'{global_scope}')")

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
                            # No real Telegram delivery exists in this isolated
                            # source-real financial acceptance; the separate
                            # notification path must report deferred delivery.
                            "achbot_api_report_delivery_deferred pay_id=",
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

                # Let sibling source-real finance fixtures reuse this exact
                # task-owned MariaDB/socket and loopback provider instance.
                # No second database, remote server or shared host resource.
                run.fixture_work = work
                run.fixture_env = env
                run.fixture_socket = work / "mysql.sock"
                linked = run("bootstrap", imported_empty=imported_empty)
                assert linked["linked"] and linked["groups"] == (
                    [] if imported_empty else [2, 5]), linked
                assert linked["imported"] is imported_empty, linked
                assert linked["identity_mode"] == ("adopted" if imported_empty else None), linked
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


def claim_proof(run, bank, kind, key, *, wrong_amount=0, offer_id=None):
    offer = offer_id if offer_id is not None else (701 if kind == "day" else 702)
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
            reservation=run("reserve", pay_id=payment_id, sms=message)
            passed(reservation is not None and reservation["reserved"]
                   and reservation["transaction_id"]>0,
                   kind + "_evidence_reserved")
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

    # Imported, not CREATE-originated, physical PG accounts may legitimately
    # have zero Groups. Server/Global pricing is still available and cannot
    # be upgraded to an invented Group-specific offer.
    for kind, offer_id, scope in (
        ("day", 704, "server"),
        ("volume", 705, "global"),
    ):
        with sandbox(imported_empty=True) as (sql, run, provider, bank, old_expiry):
            policy=run("policy_probe")
            passed(all(policy.values()) and provider.write_count==0,
                   "imported_empty_groups_"+kind+"_create_must_still_require_groups",
                   policy)
            catalogue=run("catalog",kind=kind)
            ids=[r["id"] for r in catalogue["offers"]]
            # Different unit quantities remain independent visible offers:
            # the historical Global 3-day row is also valid alongside the
            # newly scoped Server 4-day row. The native Group-specific rows
            # 701/702 must not be admitted for an empty membership.
            visible=([703,704] if kind=="day" else [705])
            selected=next((r for r in catalogue["offers"] if r["id"]==offer_id),None)
            passed(catalogue["context_groups"]==[] and ids==visible and
                   selected is not None and selected["scope_groups"] is None and
                   selected["scope_server_id"]==(2 if kind=="day" else None),
                   "imported_empty_groups_"+kind+"_only_"+scope+"_scope_visible",
                   catalogue)
            passed(provider.write_count==0 and
                   sql("SELECT CONCAT(fileid,':',amount,':',autoRenew) "
                       "FROM orders_list WHERE id=41")=="0:0:off" and
                   sql("SELECT effect_kind FROM service_effects WHERE id=1")=="adopt",
                   "imported_empty_groups_"+kind+"_real_adoption_provenance")
            key="scoped_api_pg_imported_empty_"+kind
            issued,message=claim_proof(run,bank,kind,key,offer_id=offer_id)
            pay=issued["pay_id"]
            pinned=json.loads(sql("SELECT addon_scope_snapshot "
                                  f"FROM pays WHERE id={pay}"))
            passed(pinned["native_groups"]==[] and
                   pinned["offer_id"]==offer_id and
                   pinned["provider"]=="pasarguard" and
                   pinned["scope_hash"]==hashlib.sha256(
                       (b"S:2" if kind=="day" else b"G")).hexdigest() and
                   sql(f"SELECT plan_id FROM pays WHERE id={pay}")=="0",
                   "imported_empty_groups_"+kind+"_immutable_scoped_quote",
                   pinned)
            passed(issued["price"]==(DAY_PRICE if kind=="day" else VOLUME_PRICE)
                   and run("preflight",pay_id=pay)["valid"],
                   "imported_empty_groups_"+kind+"_original_price_preflight")
            reservation=run("reserve",pay_id=pay,sms=message)
            passed(reservation is not None and reservation["reserved"] and
                   run("consume",pay_id=pay)["status"]=="proven" and
                   bank.consumes.get("1")==1,
                   "imported_empty_groups_"+kind+"_exact_api_evidence")
            wallet=sql("SELECT wallet FROM users WHERE userid=1001")
            settled=run("settle",pay_id=pay)
            outcome=terminal_state(sql,pay)
            writes=[e for e in provider.trace if e[0]=="PUT"]
            passed(settled=="confirmed" and
                   outcome.startswith("auto_cart_approved|done|") and
                   len(writes)==1 and provider.write_count==1 and
                   provider.user["group_ids"]==[] and
                   sql("SELECT COUNT(*) FROM increase_order")=="1" and
                   sql("SELECT provider_state FROM service_effects WHERE id=1")
                   =="linked" and
                   sql("SELECT provider_mutation_key FROM service_effects "
                       "WHERE id=1")==f"pay:{pay}" and
                   sql("SELECT wallet FROM users WHERE userid=1001")==wallet,
                   "imported_empty_groups_"+kind+"_native_put_readback_and_terminal",
                   [settled,outcome,len(writes)])
            if kind=="day":
                passed(int(dt.datetime.fromisoformat(
                    provider.user["expire"].replace("Z","+00:00")
                ).timestamp())==old_expiry+DAY*86400,
                       "imported_empty_groups_day_absolute_extension")
            else:
                passed(provider.user["data_limit"]==
                       pg.USER["data_limit"]+VOLUME*1073741824,
                       "imported_empty_groups_volume_absolute_extension")
            passed(run("settle",pay_id=pay)=="settled" and
                   provider.write_count==1 and
                   bank.consumes.get("1")==1 and
                   sql("SELECT COUNT(*) FROM increase_order")=="1",
                   "imported_empty_groups_"+kind+"_terminal_replay_no_second_put")

    for scenario in ("empty_to_nonempty","authority_fail"):
        with sandbox(imported_empty=True) as (sql,run,provider,bank,_):
            issued,message=claim_proof(run,bank,"day",
                "scoped_api_pg_empty_drift_"+scenario,offer_id=704)
            pay=issued["pay_id"]
            pinned=json.loads(sql("SELECT addon_scope_snapshot "
                                  f"FROM pays WHERE id={pay}"))
            assert pinned["native_groups"]==[]
            assert run("reserve",pay_id=pay,sms=message) is not None
            assert run("consume",pay_id=pay)["status"]=="proven"
            wallet=sql("SELECT wallet FROM users WHERE userid=1001")
            provider.drift=scenario
            status=run("settle",pay_id=pay)
            audit=sql("SELECT COUNT(*) FROM increase_order")
            held=terminal_state(sql,pay)
            mutation=sql("SELECT provider_state FROM service_effects WHERE id=1")
            passed(provider.drift_fired and status=="review" and
                   provider.write_count==0 and
                   not any(x[0]=="PUT" for x in provider.trace) and
                   held.startswith("processing|dispatched|") and
                   mutation=="mutating" and audit=="0" and
                   sql("SELECT wallet FROM users WHERE userid=1001")==wallet,
                   "postclaim_empty_groups_"+scenario+"_held_before_any_put",
                   [status,held,mutation,audit])
            passed(run("settle",pay_id=pay)=="review" and
                   provider.write_count==0 and
                   sql("SELECT COUNT(*) FROM increase_order")=="0",
                   "postclaim_empty_groups_"+scenario+"_cannot_replay")

    with sandbox() as (sql, run, provider, bank, old_expiry):
        # A pre-015 globally quoted payment has no addon_scope_snapshot.
        # Existing pinned amount/units survive catalog repricing, and API
        # verification must not turn it into a new Group-scoped offer.
        issued=run("issue", kind="day", offer_id=703, legacy=True,
                   hash="legacy_global_api_day")
        pay=issued["pay_id"]
        original=issued["price"]
        message=evidence_sms(1, original)
        bank.messages["1"]=dict(message)
        passed(original==300 and issued["units"]==3 and
               sql("SELECT addon_scope_snapshot IS NULL FROM pays "
                   f"WHERE id={pay}")=="1",
               "historical_global_payment_still_has_no_scoped_snapshot")
        reservation=run("reserve", pay_id=pay, sms=message)
        passed(reservation is not None and
               run("consume", pay_id=pay)["status"]=="proven",
               "historical_global_quote_api_evidence_matches_original_amount")
        sql("UPDATE increase_day SET price=997 WHERE id=703")
        state=run("settle", pay_id=pay)
        passed(state=="confirmed" and
               terminal_state(sql,pay).startswith("auto_cart_approved|done|") and
               sql("SELECT price FROM pays "
                   f"WHERE id={pay}")==str(original) and
               sql("SELECT amount FROM increase_order ORDER BY id DESC LIMIT 1")
                   ==str(original) and provider.write_count==1 and
               sql("SELECT provider_state FROM service_effects WHERE id=1")=="linked" and
               sql("SELECT expire_date FROM orders_list WHERE id=41")
                   ==str(old_expiry+3*86400),
               "historical_global_api_settlement_uses_pinned_legacy_quote",
               [state,terminal_state(sql,pay),provider.write_count])
        passed(run("settle",pay_id=pay)=="settled" and provider.write_count==1,
               "historical_global_api_settlement_replay_no_extra_native_put")

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
        # The original API reservation deliberately supports idempotent
        # repeated handles for the SAME proof/invoice. The durable invariant
        # is one operation/transaction, not exactly one successful caller.
        handles=[r for r in responses if r is not None]
        identities={(r["transaction_id"],r["operation"]) for r in handles}
        passed(len(handles)>=1 and len(identities)==1 and
               all(r["reserved"] for r in handles) and
               sql("SELECT COUNT(*) FROM payment_transactions") == "1" and
               provider.write_count == 0,
               "scoped_api_concurrent_idempotent_single_evidence_operation",
               responses)

    with sandbox() as (sql, run, provider, bank, _):
        original, message = claim_proof(
            run, bank, "day", "scoped_api_proof_original_owner")
        competing=run("issue", kind="day", offer_id=701,
                      hash="scoped_api_proof_foreign_invoice")
        source=run("reserve", pay_id=original["pay_id"], sms=message)
        stolen=run("reserve", pay_id=competing["pay_id"], sms=message)
        passed(source is not None and stolen is None and
               sql("SELECT COUNT(*) FROM payment_transactions") == "1" and
               provider.write_count == 0,
               "scoped_api_bank_evidence_cannot_be_reassigned_to_other_invoice")

    if len(checks)!=len(set(checks)):
        raise AssertionError("duplicate_scoped_api_pg_acceptance_names")
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
