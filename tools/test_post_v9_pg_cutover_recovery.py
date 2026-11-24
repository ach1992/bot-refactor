#!/usr/bin/env python3
"""Original-payment PasarGuard recovery on an independently imported post-cutover reference.

Current PHP 8.5 source, disposable MariaDB socket and local Native HTTP only.
No installed bot, payment gateway, Telegram, or Production effects.
"""
from __future__ import annotations

import argparse
import copy
import datetime as dt
import json
import re
import subprocess
from pathlib import Path

import test_post_v9_pg_cutover_runtime as cutover
import test_post_v9_scoped_api_pg as api

ROOT = Path(__file__).resolve().parents[1]
USER = cutover.USER
CREATED = cutover.CREATED

PHP = r'''<?php
error_reporting(E_ALL & ~E_DEPRECATED);
set_error_handler(static function($l,$m){throw new ErrorException($m,0,$l);});
$_SERVER['SCRIPT_FILENAME']=__FILE__;
define('ACHBOT_PG_TEST_LOOPBACK',true);
require_once $argv[1].'/src/Panel/ProviderRuntime.php';
require_once $argv[1].'/src/Services/Execution.php';
$GLOBALS['telegramWebhookSecret']='SyntheticHistoricalServiceDeliveryKey_2026';
$GLOBALS['admin']=9001;$GLOBALS['botState']=[];
$db=new mysqli('localhost','root','',$argv[3],0,$argv[2]);
$db->set_charset('utf8mb4');
$spec=json_decode($argv[4],true,64,JSON_THROW_ON_ERROR);
try {
    $payId=(int)$spec['pay_id'];
    $orderId=103;
    $params=['pay_id'=>$payId,'remark'=>'historical_fixture','days'=>30];
    if ($spec['op']==='seed') {
        achbotPgPinPayment($db,$payId);
        $ctx=achbotPgOrderContext($db,$orderId);
        $read=achbotPgRead($ctx['panel'],$ctx['identity']);
        if (($read['success']??false)!==true)throw new RuntimeException('seed_native_unavailable');
        $native=$read['user'];
        $target=achbotPgEditChanges($native,$params);
        $changes=[
            'requested_units'=>achbotPgEditInputUnits($params),
            'order_expire_before'=>(int)$ctx['order']['expire_date'],
            'before'=>array_intersect_key($native,array_flip([
                'data_limit','expire','status','group_ids','used_traffic'])),
            'absolute'=>$target,'reset_usage'=>false];
        $intent=achbotPgBeginMutation($db,$ctx,'pay:'.$payId,'edit',$changes);
        achbotPgPersistMutationIntent($db,$ctx,$intent,$changes);
        $result=['target'=>$target,'before'=>$changes['before'],'key'=>'pay:'.$payId];
    } elseif ($spec['op']==='recover') {
        $result=achbotPgRecoverEdit($db,2,$params,
            ['kind'=>'renew','order'=>$orderId,'expiry'=>0],true);
    } elseif ($spec['op']==='new_issue') {
        $db->begin_transaction();
        $id=achbotServiceIssueLocked($db,1001,'fixture','historical-add-time',[
            'kind'=>'ADD_TIME','variant'=>'managed','price'=>100,'server_id'=>2,
            'plan_id'=>7,'order_id'=>103,'quantity'=>1,'days'=>1,'volume'=>0,
            'remark'=>'historical_fixture','uuid'=>'distinct-local-token']);
        $result=['id'=>$id,'funding'=>achbotServiceFundLocked($db,1001,$id)];
        if (!$db->commit()) throw new RuntimeException('fixture_commit');
    } elseif ($spec['op']==='new_step') {
        $result=achbotServiceExecuteStep($db,(int)$spec['id']);
    } elseif ($spec['op']==='context') {
        $ctx=achbotPgOrderContext($db,$orderId,false,true);
        $result=['physical_id'=>$ctx['account']['id'],'state'=>$ctx['account']['state']];
    } else throw new RuntimeException('unexpected_fixture_operation');
    echo json_encode(['ok'=>true,'result'=>$result],JSON_THROW_ON_ERROR);
} catch(Throwable $e) {
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_THROW_ON_ERROR);
}
'''


def isolated_case(mode: str) -> list[str]:
    checks: list[str] = []
    with api.sandbox() as (sql, run, provider, _bank, _epoch):
        effect_id = int(cutover.fixture_effect(sql))
        provider.user.update({
            "id": cutover.REMOTE_ID, "username": USER, "created_at": CREATED,
            "note": "historically-adopted", "data_limit": 123400000,
            "used_traffic": 20123, "expire": None, "group_ids": [2, 5],
            "status": "active",
        })
        sql("INSERT INTO orders_list (id,userid,token,transid,fileid,server_id,inbound_id,"
            "remark,uuid,expire_date,link,amount,status,date,autoRenew,"
            "provider_effect_id,provider_remote_user_id,provider_remote_created_at) VALUES "
            "(103,1001,'post-cutover-ref','',7,2,0,'historical_fixture',"
            "'distinct-local-token',0,'[]',1200,1,'fixture','off',"
            f"{effect_id},72,{api.quote_sql(CREATED)})")
        origin = {"version": 1, "kind": "external_import", "order_id": 103,
                  "owner_userid": 1001, "actor_userid": 1001,
                  "provider": "pasarguard", "server_id": 2,
                  "remark": USER, "proof_sha256": "b" * 64,
                  "native_identity": {
                      "remote_user_id": 72, "remote_created_at": CREATED,
                      "remote_username": USER,
                  },
                  "capacity_reserved": False, "provider_account_id": effect_id}
        original_origin = json.dumps(origin, separators=(",", ":"))
        sql("INSERT INTO setting(type,value) VALUES "
            "('ACHBOT_SERVICE_ORIGIN:103',"
            f"{api.quote_sql(original_origin)})")
        sql("INSERT INTO pays(hash_id,user_id,type,plan_id,server_id,order_id,"
            "volume,day,price,request_date,state) VALUES "
            "('post_cutover_original_payment',1001,'RENEW_ACCOUNT',7,2,103,"
            "2,30,3150,UNIX_TIMESTAMP(),'processing')")
        pay_id = int(sql("SELECT id FROM pays WHERE hash_id='post_cutover_original_payment'"))
        driver = run.fixture_work / "post-cutover-recovery.php"
        driver.write_text(PHP)

        def call(op: str, **extra):
            proc = subprocess.run([
                "php", "-n", "-d", "extension=mysqlnd", "-d", "extension=mysqli",
                "-d", "extension=curl", "-d", "allow_url_fopen=0",
                "-d", "log_errors=1", "-d", "error_log=/dev/stderr",
                str(driver), str(ROOT), str(run.fixture_socket), api.DB,
                json.dumps({"op": op, "pay_id": pay_id, **extra}),
            ], cwd=run.fixture_work, env=run.fixture_env, capture_output=True,
                text=True, timeout=35)
            if proc.returncode or not proc.stdout:
                raise AssertionError(("php_failed", op, proc.returncode,
                                      proc.stderr[-600:]))
            if "fixture-private-key" in proc.stderr or "SENSITIVE" in proc.stderr:
                raise AssertionError("Native secret leaked into recovery diagnostics")
            for raw in proc.stderr.strip().splitlines():
                line=re.sub(r'^\[\d{2}-[A-Za-z]{3}-\d{4} '
                            r'\d{2}:\d{2}:\d{2} UTC\] ', '',raw)
                if line.startswith("[AChBot] "):
                    json.loads(line[len("[AChBot] "):])
                elif not line.startswith("achbot_provider_edit_recovery_deferred pay_id="):
                    raise AssertionError(("unexpected_php_log", line[:300]))
            return json.loads(proc.stdout)

        assert call("context")["result"]["physical_id"] == effect_id
        prepared = call("seed")
        assert prepared["ok"], prepared
        target = prepared["result"]["target"]
        original_key = prepared["result"]["key"]
        assert original_key == f"pay:{pay_id}"
        initial_snapshot = sql(
            "SELECT provider_snapshot FROM service_effects WHERE id="+str(effect_id))
        assert sql("SELECT order_id IS NULL FROM service_effects WHERE id="+str(effect_id)) == "1"
        assert sql("SELECT provider_state FROM service_effects WHERE id="+str(effect_id)) == "mutating"
        checks.append(mode + "_original_payment_and_native_fence_held")

        if mode == "baseline":
            # Bad independent origin or ownership must not unlock the existing
            # original pay-fence or touch Native, regardless of staff authority.
            wrong = dict(origin, provider="marzban")
            sql("UPDATE setting SET value="+api.quote_sql(json.dumps(wrong))+
                " WHERE type='ACHBOT_SERVICE_ORIGIN:103'")
            assert call("recover")["result"]["success"] is False
            sql("UPDATE setting SET value="+api.quote_sql(original_origin)+
                " WHERE type='ACHBOT_SERVICE_ORIGIN:103'")
            checks.append("wrong_imported_provider_refused")
            sql("UPDATE orders_list SET userid=9002 WHERE id=103")
            assert call("recover")["result"]["success"] is False
            sql("UPDATE orders_list SET userid=1001 WHERE id=103")
            checks.append("wrong_owner_refused")
            wrong = dict(origin, provider_account_id=effect_id + 99)
            sql("UPDATE setting SET value="+api.quote_sql(json.dumps(wrong))+
                " WHERE type='ACHBOT_SERVICE_ORIGIN:103'")
            assert call("recover")["result"]["success"] is False
            sql("UPDATE setting SET value="+api.quote_sql(original_origin)+
                " WHERE type='ACHBOT_SERVICE_ORIGIN:103'")
            checks.append("wrong_imported_provider_account_refused")
            current_url=sql("SELECT panel_url FROM server_config WHERE id=2")
            sql("UPDATE server_config SET panel_url='http://127.0.0.1:9' WHERE id=2")
            assert call("recover")["result"]["success"] is False
            sql("UPDATE server_config SET panel_url="+api.quote_sql(current_url)+" WHERE id=2")
            checks.append("changed_provider_endpoint_refused")
            provider.user["created_at"] = "2026-11-14T21:00:01.000000Z"
            assert call("recover")["result"]["success"] is False
            provider.user["created_at"] = CREATED
            checks.append("native_incarnation_change_refused")
            provider.user["data_limit"] += 13
            drift = call("recover")
            assert drift["ok"] and drift["result"]["success"] is False, drift
            assert "drift" in str(drift["result"].get("msg") or drift["result"].get("reason")), drift
            provider.user["data_limit"] -= 13
            assert provider.write_count == 0
            checks.append("independent_native_entitlement_drift_never_put")
        else:
            provider.user["expire"] = dt.datetime.fromtimestamp(
                target["expire"], dt.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")

        first = call("recover")
        assert first["ok"] and first["result"]["success"] is True, first
        assert first["result"]["verified"] is True, first
        assert provider.write_count == (1 if mode == "baseline" else 0)
        assert sql("SELECT provider_state FROM service_effects WHERE id="+str(effect_id)) == "linked"
        checks.append(mode+"_recover_original_absolute_once")

        before_writes = provider.write_count
        replay = call("recover")
        assert replay["ok"] and replay["result"]["success"] is True, replay
        assert provider.write_count == before_writes
        checks.append(mode+"_repeat_operator_confirmation_no_extra_native_write")

        assert sql("SELECT order_id IS NULL FROM service_effects WHERE id="+str(effect_id)) == "1"
        assert sql("SELECT provider_mutation_key FROM service_effects WHERE id="+str(effect_id)) == original_key
        assert sql("SELECT source_kind FROM service_effects WHERE id="+str(effect_id)) == "legacy_migration"
        assert sql("SELECT provider_snapshot FROM service_effects WHERE id="+str(effect_id)) == initial_snapshot
        assert sql("SELECT COUNT(*) FROM pays") == "1"
        assert sql("SELECT state FROM pays WHERE id="+str(pay_id)) == "processing"
        assert sql("SELECT COUNT(*) FROM payment_transactions") == "0"
        assert sql("SELECT COUNT(*) FROM payment_delivery_intents") == "0"
        assert sql("SELECT COUNT(*) FROM service_effects WHERE source_kind='legacy_migration'") == "1"
        checks.append(mode+"_original_financial_and_migration_journals_preserved")
        # Continue with the same migrated physical account and independently
        # imported reference, without changing the historical purchase or
        # origin. The new normalized journal must temporarily own the anchor
        # and then restore the original historical key/snapshot.
        for name in (
            "023_scoped_payment_lock_indexes.sql", "024_wallet_entries.sql",
            "025_wallet_receipt_credit.sql", "026_service_operations.sql",
            "027_service_delivery_sources.sql", "028_service_mutations.sql",
            "020_payment_followup_intents.sql", "029_service_purchase_referral.sql",
            "030_service_followup_sources.sql", "031_service_followup_attempts.sql",
            "032_service_delivery_parts.sql",
        ):
            sql((ROOT / "migrations/post-v9" / name).read_text())
        prior_mutation=sql("SELECT provider_mutation_snapshot FROM service_effects WHERE id="+str(effect_id))
        prior_wallet=int(sql("SELECT wallet FROM users WHERE userid=1001"))
        prior_audits=int(sql("SELECT COUNT(*) FROM increase_order"))
        prior_requests=provider.write_count
        issued=call("new_issue")
        assert issued["ok"] and issued["result"]["funding"]=="funded",issued
        new_id=issued["result"]["id"]
        for expected in ("progressed","applied","completed"):
            result=call("new_step",id=new_id)
            assert result["ok"] and result["result"]["status"]==expected,result
            if expected!="completed":
                assert call("context")["result"]=={"physical_id":effect_id,"state":"mutating"}
        assert call("context")["result"]=={"physical_id":effect_id,"state":"linked"}
        assert call("new_step",id=new_id)["result"]["status"]=="completed"
        assert call("new_issue")["result"]=={"id":new_id,"funding":"already_funded"}
        assert provider.write_count==prior_requests+1
        assert int(sql("SELECT wallet FROM users WHERE userid=1001"))==prior_wallet-100
        assert int(sql("SELECT COUNT(*) FROM increase_order"))==prior_audits+1
        assert sql(f"SELECT COUNT(*) FROM wallet_entries WHERE source_kind='service_debit' AND source_ref='{new_id}'")=="1"
        assert sql(f"SELECT COUNT(*) FROM service_mutations WHERE service_operation_id={new_id} AND account_effect_id={effect_id} AND phase='completed'")=="1"
        assert sql(f"SELECT COUNT(*) FROM payment_delivery_intents WHERE service_operation_id={new_id} AND pay_row_id IS NULL AND attempt_id IS NULL")=="1"
        assert sql("SELECT provider_mutation_key FROM service_effects WHERE id="+str(effect_id))==original_key
        assert sql("SELECT provider_mutation_snapshot FROM service_effects WHERE id="+str(effect_id))==prior_mutation
        assert sql("SELECT provider_snapshot FROM service_effects WHERE id="+str(effect_id))==initial_snapshot
        assert sql("SELECT value FROM setting WHERE type='ACHBOT_SERVICE_ORIGIN:103'")==original_origin
        assert sql("SELECT COUNT(*) FROM pays")=="1"
        assert sql("SELECT state FROM pays WHERE id="+str(pay_id))=="processing"
        assert sql("SELECT COUNT(*) FROM payment_transactions")=="0"
        checks.append(mode+"_new_journal_edit_on_migrated_imported_account")

    return checks


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--run-disposable", action="store_true", required=True)
    ap.add_argument("--report", type=Path)
    args = ap.parse_args()
    checks = isolated_case("baseline") + isolated_case("applied")
    report = {"complete": True, "cases": len(checks), "checks": checks,
              "real_php_source": True, "disposable_mariadb": True,
              "local_native_only": True, "production_mutations": 0}
    if args.report:
        args.report.write_text(json.dumps(report, indent=2) + "\n")
    print(json.dumps(report))


if __name__ == "__main__":
    main()
