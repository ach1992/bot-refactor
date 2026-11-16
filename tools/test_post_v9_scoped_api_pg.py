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
 } elseif ($op==='issue_renew') {
    $hash=$spec['hash'];$price=3150;
    $plan=$connection->query('SELECT days,volume FROM server_plans WHERE id=7')->fetch_assoc();
    $stmt=$connection->prepare("INSERT INTO pays(hash_id,user_id,type,plan_id,server_id,order_id,volume,day,price,request_date,state)
        VALUES (?,1001,'RENEW_ACCOUNT',7,2,41,?,?,?,UNIX_TIMESTAMP(),'pending')");
    $days=(int)$plan['days'];$volume=(int)$plan['volume'];
    $stmt->bind_param('siii',$hash,$volume,$days,$price);$stmt->execute();
    $payId=(int)$stmt->insert_id;$stmt->close();
    achbotPgPinPayment($connection,$payId);
    $stmt=$connection->prepare("INSERT INTO payment_attempts
       (pay_row_id,provider,order_hash,quote_amount,quote_currency,local_amount_toman,checkout_url,stage)
       VALUES (?,'bank_transfer',?,?,'toman',?,'{\"v\":1,\"method\":\"bank_transfer\"}','review')");
    $stmt->bind_param('isii',$payId,$hash,$price,$price);$stmt->execute();$stmt->close();
    if(!$connection->begin_transaction())throw new RuntimeException('renew_pin_begin');
    $pay=achbotApiPaymentRow($connection,$payId,true);
    $attempt=achbotApiPaymentAttempt($connection,$payId,true);
    achbotApiPaymentPinLocked($connection,$pay,$attempt);
    if(!$connection->commit())throw new RuntimeException('renew_pin_commit');
    $stmt=$connection->prepare("UPDATE pays SET state='have_sent',message_id=222,chat_id='9001' WHERE id=?");
    $stmt->bind_param('i',$payId);$stmt->execute();$stmt->close();
    $result=['pay_id'=>$payId,'price'=>$price,'units'=>$days,'profile'=>true];
 } elseif ($op==='seed_rearmed_create') {
    // A real original PG CREATE reservation with a committed source/Native
    // identity, as if the worker terminated after rearm COMMIT.
    $target=achbotEffectTargetIdentity($connection,2,7,null,
        ['panel_type'=>'pasarguard']);
    $snapshot=$target['snapshot'];
    $epoch=time()+30*86400;$bytes=2*1073741824;
    $opId=str_repeat('e',32);$remark='fixture_rearmed';
    $hash='fixture-rearmed-paid-create';
    $p=$connection->prepare("INSERT INTO pays
        (hash_id,user_id,type,plan_id,server_id,price,request_date,
         state,approved_by,agent_count)
        VALUES (?,1001,'BUY_SUB',7,2,3150,UNIX_TIMESTAMP(),'processing',0,1)");
    $p->bind_param('s',$hash);$p->execute();
    $payId=(int)$p->insert_id;$p->close();
    achbotPgPinPayment($connection,$payId);
    if (!$connection->begin_transaction()) throw new RuntimeException('create_rearm_test_begin');
    $snapJson=json_encode($snapshot,JSON_THROW_ON_ERROR);
    $requestHash=hash('sha256',$snapJson);
    $endpoint=$target['endpoint'];$key=$target['key'];
    $e=$connection->prepare("INSERT INTO service_effects
        (pay_row_id,ordinal,reserved_count,operation_id,effect_kind,
         target_server_id,target_key,endpoint_sha256,plan_id,
         remote_username,requested_expire,requested_bytes,
         request_sha256,state,provider_snapshot)
         VALUES (?,1,1,?,'create',2,?,?,7,?,?,?,?,'reserved',?)");
    $e->bind_param('issssiiss',$payId,$opId,$key,$endpoint,$remark,
        $epoch,$bytes,$requestHash,$snapJson);
    $e->execute();$effectId=(int)$e->insert_id;$e->close();
    $reserved=achbotProviderReserveAccount($connection,$effectId,$snapshot,
        $remark,'achbot:v1:'.$opId,
        ['data_limit'=>$bytes,'expire'=>$epoch,
         'group_ids'=>$snapshot['group_ids'],'status'=>'active']);
    if (($reserved['newly_reserved']??false)!==true)
        throw new RuntimeException('create_rearm_test_reserve_failed');
    $connection->query('UPDATE server_info SET ucount=ucount-1 WHERE id=2');
    if (!$connection->commit()) throw new RuntimeException('create_rearm_test_commit');
    $result=['pay_id'=>$payId,'effect_id'=>$effectId,'name'=>$remark,
        'operation'=>$opId,'expiry'=>$epoch,'bytes'=>$bytes];
 } elseif ($op==='seed_original_create_post') {
    // Original provider POST really succeeds via the Native PHP adapter,
    // but the fixture intentionally loses the acknowledgement before any
    // service_effect verification. Its original local row stays reserved.
    $effectId=(int)$spec['effect_id'];
    $e=$connection->query('SELECT * FROM service_effects WHERE id='.$effectId)->fetch_assoc();
    if (!$e || $e['state']!=='reserved')
        throw new RuntimeException('fixture_original_reserved_create_missing');
    $panel=achbotPgServerPanel($connection,(int)$e['target_server_id']);
    $snapshot=json_decode($e['provider_snapshot'],true,64,JSON_THROW_ON_ERROR);
    $remote=achbotPgCreate($panel,[
        'username'=>$e['remote_username'],
        'operation_marker'=>'achbot:v1:'.$e['operation_id'],
        'data_limit'=>(int)$e['requested_bytes'],
        'expire'=>(int)$e['requested_expire'],
        'group_ids'=>$snapshot['group_ids'],
    ]);
    $result=['native_success'=>($remote['success']??false)===true,
        'effect_still_reserved'=>$connection->query('SELECT state FROM service_effects
            WHERE id='.$effectId)->fetch_row()[0]==='reserved'];
 } elseif ($op==='continue_rearmed_create') {
    require_once $root.'/src/Finance/PaidAccountRecovery.php';
    $payId=(int)$spec['pay_id'];$effectId=(int)$spec['effect_id'];
    $e=$connection->query('SELECT * FROM service_effects WHERE id='.$effectId)->fetch_assoc();
    $request=['server_id'=>2,'plan_id'=>7,'inbound_id'=>0,
        'server_type'=>'pasarguard','user_id'=>1001,'days'=>30,'volume'=>2,
        'remark'=>$e['remote_username'],'protocol'=>'vless','net_type'=>'tcp'];
    require_once $root.'/src/Finance/GatewayClient.php';
    $lease=achbotHostedWorkerLockName($connection,$payId);
    $lock=$connection->prepare('SELECT GET_LOCK(?,0) AS acquired');
    $lock->bind_param('s',$lease);$lock->execute();
    $hasLease=$lock->get_result()->fetch_assoc();$lock->close();
    if ((int)($hasLease['acquired']??0)!==1)
        throw new RuntimeException('fixture_original_payment_lease_missing');
    try {
        $continued=achbotContinuePaidAccount($connection,$payId,1,$request,
            null,9001,9001);
    } finally {
        $release=$connection->prepare('SELECT RELEASE_LOCK(?)');
        $release->bind_param('s',$lease);$release->execute();$release->close();
    }
    $effect=$connection->query('SELECT id,state,operation_id,remote_username,reserved_count
        FROM service_effects WHERE id='.$effectId)->fetch_assoc();
    $result=['effect_id'=>$continued[3], 'state'=>$effect['state'],
        'username'=>$continued[1],'operation'=>$effect['operation_id'],
        'reserved_count'=>(int)$effect['reserved_count']];
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
 } elseif ($op==='operator') {
    $id=(int)$spec['pay_id'];
    $proof=achbotPaymentEvidenceGetProof($connection,$id);
    $actor=(int)($spec['actor']??9001);
    $result=$proof?achbotApiPaymentSettle($connection,$id,$proof,$actor,9001)
        :'missing_proof';
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
 echo json_encode(['ok'=>false,'error'=>get_class($e).':'.$e->getMessage().':'.basename($e->getFile()).':'.$e->getLine()],
      JSON_THROW_ON_ERROR);
}
'''


class DriftHandler(pg.Handler):
    """Deterministic post-fence changes, observed only by a local provider."""

    def handle_request(self):
        s = self.server
        path = urlsplit(self.path).path
        # Separate original CREATE race: the owner closes the payment after
        # the staff lease/permission preflight but during Native observation.
        # Local verification must re-check payment/Archive under its own lock.
        hook=getattr(s,'create_reconcile_hook',None)
        if (self.command=='GET' and path.startswith('/api/user/by-username/')
                and callable(hook)):
            s.create_reconcile_hook=None
            hook()
            s.create_reconcile_hook_fired=True
        # Recovery-only intermediate race: another panel operator changes
        # Native state AFTER the coordinator GET but BEFORE the provider's
        # separate authenticated GET immediately preceding RESET or PUT.
        if (self.command == 'GET' and path == '/api/user/by-id/17' and
                getattr(s,'recovery_drift_at',None) is not None):
            s.recovery_drift_seen=getattr(s,'recovery_drift_seen',0)+1
            if s.recovery_drift_seen==s.recovery_drift_at:
                s.user.update(json.loads(json.dumps(s.recovery_drift_change)))
                s.recovery_drift_fired=True
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
                    # Current source emits typed diagnostic JSON in addition to
                    # the established plain failure codes. Validate rather
                    # than blindly ignoring STDERR: source secrets and
                    # unexpected events must still fail this acceptance.
                    def known_diagnostic(line):
                        if not line.startswith('[AChBot] {'):
                            return False
                        try:
                            event=json.loads(line[len('[AChBot] '):])
                        except ValueError:
                            return False
                        name=event.get('event','')
                        expected=(
                            'achbot.finance.evidence_',
                            'achbot.finance.hosted_',
                            'achbot.native.edit_recovery_',
                            'achbot.native.account_action',
                            'achbot.delivery.',
                        )
                        if not isinstance(name,str) or not (
                            name.startswith(expected) or
                            name=='achbot.native.edit_result_unverified'):
                            return False
                        if name=='achbot.native.edit_result_unverified':
                            # This new readback/ACK diagnostic is deliberately
                            # accepted by exact name only. Require bounded,
                            # typed payment identity and machine-only reason;
                            # unexpected log events remain test failures.
                            reason=event.get('reason')
                            if (not isinstance(event.get('pay_id'),int) or
                                event['pay_id']<=0 or
                                not isinstance(event.get('order_id'),int) or
                                event['order_id']<=0 or
                                event.get('stage')!='native_result' or
                                event.get('result')!='review' or
                                not isinstance(reason,str) or
                                re.fullmatch(r'pg_[a-z0-9_]{2,90}',reason) is None):
                                return False
                        disallowed={'token','secret','password','url',
                            'payload','raw_text','bank_account','sms_text','username'}
                        return (isinstance(event.get('trace_id'),str)
                            and len(event['trace_id'])==16
                            and not disallowed.intersection(event)
                            and all(isinstance(k,str) for k in event))
                    if any(not known_diagnostic(line) and not line.startswith((
                            "achbot_api_claim_review pay_id=",
                            "achbot_api_effect_review pay_id=",
                            "achbot_operator_service_completed pay_id=",
                            "achbot_pg_edit_needs_review pay_id=",
                            "achbot_provider_edit_recovery_deferred pay_id=",
                            "achbot_operator_create_redrive pay_id=",
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

    # Independent review REQUIRED A: do not trust only the coordinator's
    # first Native GET. Inject a real provider-side external change immediately
    # before the separate GET inside achbotPgAction(reset) or achbotPgModify.
    # The original payment/effect journal stays unchanged and reviewable.
    native_changes=(
        ('quota',{'data_limit':17*1073741824}),
        ('expiry',{'expire':'2035-04-01T00:00:00Z'}),
        ('status',{'status':'disabled'}),
        ('groups',{'group_ids':[5]}),
    )
    for kind in ('day','volume','renew'):
        with sandbox() as (sql,run,provider,bank,_):
            if kind=='renew':
                issued=run('issue_renew',hash='review197_middle_renew')
                msg=evidence_sms(1,issued['price'])
                bank.messages['1']=dict(msg)
            else:
                issued,msg=claim_proof(run,bank,kind,
                    'review197_middle_'+kind)
            pay=issued['pay_id']
            assert run('reserve',pay_id=pay,sms=msg) is not None
            assert run('consume',pay_id=pay)['status']=='proven'
            provider.mode='modify_lie'
            assert run('settle',pay_id=pay)=='review'
            assert terminal_state(sql,pay).startswith('processing|dispatched|')
            assert sql('SELECT provider_state FROM service_effects WHERE id=1')=='mutating'
            provider.mode='ok'
            baseline=json.loads(json.dumps(provider.user))
            stages=(('reset',2),('put',4)) if kind=='renew' else (('put',2),)
            for stage,get_at in stages:
                for change,foreign in native_changes:
                    provider.user=json.loads(json.dumps(baseline))
                    provider.recovery_drift_at=get_at
                    provider.recovery_drift_seen=0
                    provider.recovery_drift_fired=False
                    provider.recovery_drift_change=foreign
                    trace_start=len(provider.trace)
                    result=run('operator',pay_id=pay)
                    actions=[(method,path) for method,path,_ in provider.trace[trace_start:]
                             if method in ('PUT','POST') and
                                (method=='PUT' or path.endswith('/reset'))]
                    assert provider.recovery_drift_fired,(kind,stage,change,provider.trace[trace_start:])
                    assert result=='review' and ('PUT','/api/user/by-id/17') not in actions,(kind,stage,change,result,actions)
                    expected_resets=1 if stage=='put' and kind=='renew' else 0
                    assert actions==[('POST','/api/user/by-id/17/reset')]*expected_resets,(kind,stage,change,actions)
                    for field,value in foreign.items():
                        assert provider.user[field]==value,(kind,stage,change,provider.user)
                    assert terminal_state(sql,pay).startswith('processing|dispatched|') and                         sql('SELECT provider_state FROM service_effects WHERE id=1')=='mutating' and                         sql('SELECT COUNT(*) FROM increase_order')=='0',(kind,stage,change)
                    checks.append('review197_'+kind+'_'+stage+'_'+change+'_native_intermediate_drift_blocks_write')
            provider.recovery_drift_at=None
            provider.user=json.loads(json.dumps(baseline))
            finished=run('operator',pay_id=pay)
            assert finished=='confirmed' and                 sql('SELECT provider_state FROM service_effects WHERE id=1')=='linked' and                 sql('SELECT COUNT(*) FROM increase_order')=='1',(kind,finished)
            checks.append('review197_'+kind+'_unchanged_baseline_recovery_completes_once')

    # Review #197: an actual PasarGuard paid CREATE whose original rearm
    # COMMIT survived while PHP died before POST admission. The next staff
    # action resumes the same reserved effect, Native identity and capacity.
    with sandbox() as (sql,run,provider,bank,_):
        original=run('seed_rearmed_create')
        pay=original['pay_id'];eid=original['effect_id']
        assert sql(f"SELECT state FROM service_effects WHERE id={eid}")=='reserved'
        capacity=sql("SELECT ucount FROM server_info WHERE id=2")
        provider.user=None
        # The linked original account owns remote id=17. The new paid
        # CREATE receives a different physical ID, as a real provider would.
        first_native_id=pg.USER['id']
        pg.USER['id']=first_native_id+1
        before=[x for x in provider.trace if x[0]=='POST' and x[1]=='/api/user']
        try:
            first=run('continue_rearmed_create',pay_id=pay,effect_id=eid)
            after=[x for x in provider.trace if x[0]=='POST' and x[1]=='/api/user']
            passed(first['state']=='verified' and first['effect_id']==eid and
                first['username']==original['name'] and
                first['operation']==original['operation'] and
                first['reserved_count']==1 and len(after)==len(before)+1 and
                sql("SELECT ucount FROM server_info WHERE id=2")==capacity,
                'pg_original_reserved_create_rearm_crash_recovers_same_effect_once',
                [first,len(after),capacity])
            repeated=run('continue_rearmed_create',pay_id=pay,effect_id=eid)
            after_repeat=[x for x in provider.trace if x[0]=='POST' and x[1]=='/api/user']
            passed(repeated['effect_id']==eid and
                repeated['state']=='verified' and
                len(after_repeat)==len(after) and
                sql("SELECT ucount FROM server_info WHERE id=2")==capacity,
                'pg_verified_original_create_readback_no_second_post_or_capacity',
                [repeated,len(after_repeat)])
        finally:
            pg.USER['id']=first_native_id
            provider.user=pg.USER.copy()

    # REQUIRED B: the original Native CREATE truly succeeds via provider
    # POST, then its PHP acknowledgement is lost. With local effect rearmed
    # and still reserved, staff must observe this EXISTING physical account
    # before deciding on an additional POST.
    with sandbox() as (sql,run,provider,bank,_):
        original=run('seed_rearmed_create')
        pay=original['pay_id'];eid=original['effect_id']
        capacity=sql('SELECT ucount FROM server_info WHERE id=2')
        first_native_id=pg.USER['id']
        pg.USER['id']=first_native_id+1
        try:
            provider.user=None
            created=run('seed_original_create_post',effect_id=eid)
            assert created['native_success'] and created['effect_still_reserved'],created
            old_posts=[x for x in provider.trace if x[0]=='POST' and x[1]=='/api/user']
            assert len(old_posts)==1 and sql(f"SELECT state FROM service_effects WHERE id={eid}")=='reserved'
            native_copy=json.loads(json.dumps(provider.user))
            complete=run('continue_rearmed_create',pay_id=pay,effect_id=eid)
            current_posts=[x for x in provider.trace if x[0]=='POST' and x[1]=='/api/user']
            passed(complete['state']=='verified' and complete['effect_id']==eid and
                complete['operation']==original['operation'] and
                complete['username']==original['name'] and
                len(current_posts)==len(old_posts) and
                provider.user==native_copy and
                sql('SELECT ucount FROM server_info WHERE id=2')==capacity,
                'pg_rearmed_original_native_create_preexists_read_first_no_post',
                [complete,len(current_posts),capacity])
            repeat=run('continue_rearmed_create',pay_id=pay,effect_id=eid)
            passed(repeat['state']=='verified' and repeat['effect_id']==eid and
                len([x for x in provider.trace if x[0]=='POST' and x[1]=='/api/user'])==1,
                'pg_existing_native_rearmed_repeat_never_posts_create')
        finally:
            pg.USER['id']=first_native_id

    with sandbox() as (sql,run,provider,bank,_):
        original=run('seed_rearmed_create')
        eid=original['effect_id']
        provider.user=json.loads(json.dumps(pg.USER))
        provider.user.update(username=original['name'],id=pg.USER['id']+1,
            note='achbot:v1:'+str('f'*32))
        before_posts=len([x for x in provider.trace if x[0]=='POST' and x[1]=='/api/user'])
        try:
            run('continue_rearmed_create',pay_id=original['pay_id'],effect_id=eid)
        except AssertionError as error:
            passed('paid_resume_native_mismatch' in str(error) and
                len([x for x in provider.trace if x[0]=='POST' and x[1]=='/api/user'])==before_posts and
                sql(f"SELECT state FROM service_effects WHERE id={eid}")=='reserved',
                'pg_rearmed_foreign_native_identity_never_reissues_create',str(error))
        else: raise AssertionError('pg_rearmed_foreign_account_was_not_held')

    # The existing Native proof is not license to settle after the original
    # payment was independently closed between staff preview and effect
    # verification. This exercises the final locked payment check, not only
    # the initial worker lease gate.
    with sandbox() as (sql,run,provider,bank,_):
        original=run('seed_rearmed_create')
        pay=original['pay_id'];eid=original['effect_id']
        provider.user=json.loads(json.dumps(pg.USER))
        provider.user.update(id=pg.USER['id']+1,username=original['name'],
            note='achbot:v1:'+original['operation'],
            data_limit=original['bytes'],
            expire=dt.datetime.fromtimestamp(original['expiry'],dt.timezone.utc
                ).strftime('%Y-%m-%dT%H:%M:%SZ'))
        provider.create_reconcile_hook=lambda:sql(
            f"UPDATE pays SET state='declined' WHERE id={pay}")
        provider.create_reconcile_hook_fired=False
        try:
            run('continue_rearmed_create',pay_id=pay,effect_id=eid)
        except AssertionError as error:
            passed('paid_resume_reconciliation_payment_changed' in str(error) and
                provider.create_reconcile_hook_fired and
                sql(f"SELECT state FROM service_effects WHERE id={eid}")=='reserved' and
                sql(f"SELECT state FROM pays WHERE id={pay}")=='declined' and
                len([x for x in provider.trace if x[0]=='POST' and x[1]=='/api/user'])==0,
                'pg_native_observation_payment_closed_before_locked_verify_holds',
                str(error))
        else: raise AssertionError('pg_payment_closed_during_get_was_finalized')

    # Absolute renewal: no operator or manual action is required after an
    # uncertain Native response. A lost reset acknowledgement is permitted
    # to run reset again and then finish the saved absolute PUT; the original
    # payment must not be charged twice.
    with sandbox() as (sql,run,provider,bank,_):
        issued=run('issue_renew',hash='operator_auto_renewal')
        pay=issued['pay_id'];message=evidence_sms(1,issued['price'])
        bank.messages['1']=dict(message)
        assert run('reserve',pay_id=pay,sms=message) is not None
        assert run('consume',pay_id=pay)['status']=='proven'
        provider.mode='write_lost'
        assert run('settle',pay_id=pay)=='review'
        assert terminal_state(sql,pay).startswith('processing|dispatched|')
        first=provider.write_count
        provider.mode='ok'
        response=run('settle',pay_id=pay)
        passed(response=='confirmed' and
            terminal_state(sql,pay).startswith('auto_cart_approved|done|') and
            provider.write_count==first+2 and
            sql("SELECT provider_state FROM service_effects WHERE id=1")=='linked' and
            sql("SELECT COUNT(*) FROM increase_order")=='1',
            'real_pg_renewal_auto_retries_lost_reset_ack_once',
            [response,first,provider.write_count,terminal_state(sql,pay)])
    # The same source, provider adapter and original payment recover native
    # timeouts. An operator who inspected the panel can finish both additive
    # types; the original absolute effect is never applied twice.
    for kind in ('day','volume'):
        with sandbox() as (sql,run,provider,bank,old_expiry):
            issued,msg=claim_proof(run,bank,kind,'operator_redrive_'+kind)
            pay=issued['pay_id']
            assert run('reserve',pay_id=pay,sms=msg) is not None
            assert run('consume',pay_id=pay)['status']=='proven'
            provider.mode='write_lost'
            first=run('settle',pay_id=pay)
            assert first=='review' and provider.write_count==1
            assert terminal_state(sql,pay).startswith('processing|dispatched|')
            assert sql("SELECT provider_state FROM service_effects WHERE id=1")=='mutating'
            provider.mode='ok'
            before=provider.write_count
            recovered=run('operator',pay_id=pay)
            passed(recovered=='confirmed' and
                   terminal_state(sql,pay).startswith('auto_cart_approved|done|') and
                   provider.write_count==before and
                   sql("SELECT provider_state FROM service_effects WHERE id=1")=='linked' and
                   sql("SELECT COUNT(*) FROM increase_order")=='1',
                   'operator_'+kind+'_lost_ack_finalized_without_second_native_write',
                   [recovered,terminal_state(sql,pay),provider.write_count])
            passed(run('operator',pay_id=pay)=='settled' and
                   provider.write_count==before and
                   sql('SELECT COUNT(*) FROM increase_order')=='1',
                   'operator_'+kind+'_completed_payment_never_adds_again')
    # Reviewer #197: independently edited Native quota/expiry/status after
    # a lost remote acknowledgement is not permission to replace that edit
    # with an older absolute target, nor to RESET traffic again.
    for kind in ('day','volume'):
        for drift,field,changed in (
            ('quota','data_limit',17*1073741824),
            ('expiry','expire',1950000000),
            ('status','status','disabled')):
            with sandbox() as (sql,run,provider,bank,_):
                issued,message=claim_proof(
                    run,bank,kind,'review197_pg_drift_'+kind+'_'+drift)
                pay=issued['pay_id']
                assert run('reserve',pay_id=pay,sms=message) is not None
                assert run('consume',pay_id=pay)['status']=='proven'
                provider.mode='write_lost'
                assert run('settle',pay_id=pay)=='review'
                assert terminal_state(sql,pay).startswith('processing|dispatched|')
                provider.mode='ok'
                provider.user[field]=changed
                before_writes=provider.write_count
                before_native=dict(provider.user)
                attempt=run('operator',pay_id=pay)
                passed(attempt=='review' and provider.write_count==before_writes and
                    provider.user==before_native and
                    terminal_state(sql,pay).startswith('processing|dispatched|') and
                    sql('SELECT COUNT(*) FROM increase_order')=='0' and
                    sql("SELECT provider_state FROM service_effects WHERE id=1")=='mutating',
                    'operator_'+kind+'_postdispatch_'+drift+'_never_overwritten',
                    [attempt,provider.write_count,before_writes])
                passed(run('operator',pay_id=pay)=='review' and
                    provider.write_count==before_writes,
                    'operator_'+kind+'_repeated_'+drift+'_stays_reviewable')
    # If the first worker died before any remote PUT, the actor's deliberate
    # confirmation restarts only the saved absolute service operation.
    for kind in ('day','volume'):
        with sandbox() as (sql,run,provider,bank,old_expiry):
            issued,msg=claim_proof(run,bank,kind,'operator_preput_'+kind)
            pay=issued['pay_id']
            assert run('reserve',pay_id=pay,sms=msg) is not None
            assert run('consume',pay_id=pay)['status']=='proven'
            provider.drift='authority_fail'
            assert run('settle',pay_id=pay)=='review'
            assert provider.write_count==0
            provider.mode='ok'
            recovered=run('operator',pay_id=pay)
            passed(recovered=='confirmed' and provider.write_count==1 and
                   sql("SELECT provider_state FROM service_effects WHERE id=1")=='linked' and
                   terminal_state(sql,pay).startswith('auto_cart_approved|done|'),
                   'operator_'+kind+'_can_complete_earlier_preput_failure',
                   [recovered,provider.write_count,terminal_state(sql,pay)])
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
