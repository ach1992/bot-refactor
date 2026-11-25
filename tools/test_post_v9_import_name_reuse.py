#!/usr/bin/env python3
"""PasarGuard external import after deletion and Native username reuse.

Source-real adoption with disposable socket MariaDB. The previous native
incarnation's tombstone remains immutable; no production Native/Telegram calls.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import subprocess
from pathlib import Path

import test_post_v9_pg_cutover_runtime as historical
import native_identity_fixture as fixtures

ROOT = Path(__file__).resolve().parents[1]
USERNAME = historical.USER
NEW_ID = 73
NEW_CREATED = "2026-11-22T10:00:00.000000Z"

PHP = r'''<?php
error_reporting(E_ALL & ~E_DEPRECATED);
set_error_handler(static function($level,$message){throw new ErrorException($message,0,$level);});
$_SERVER['SCRIPT_FILENAME']=__FILE__;
define('ACHBOT_PG_TEST_LOOPBACK',true);
require_once $argv[1].'/src/Panel/ProviderRuntime.php';
require_once $argv[1].'/src/Panel/ProviderStorage.php';
$db=new mysqli('localhost','root','',$argv[3],0,$argv[2]);
$db->set_charset('utf8mb4');
$spec=json_decode($argv[4],true,64,JSON_THROW_ON_ERROR);
try {
    if ($spec['op']==='hint_versions') {
        $hints=[];
        foreach ([2,3,4,27] as $version) {
            $payload='v'.$version.',73,1700000000';
            $token=rtrim(strtr(base64_encode($payload),'+/','-_'),'=').'0123456789';
            $hints[]=achbotPgImportHint($token);
        }
        echo json_encode(['ok'=>true,'hints'=>$hints],JSON_THROW_ON_ERROR);
        exit;
    }
    $old=achbotProviderAccountByEffect($db,(int)$spec['old_effect']);
    $oldIdentity=achbotProviderAccountIdentity($db,$old,true);
    if ($old['state']!=='deleted' || $oldIdentity['remote_user_id']!==72)
        throw new RuntimeException('tombstone_fixture_invalid');
    $native=achbotPgNormalizeUser($spec['native']);
    $panel=achbotPgServerPanel($db,2);
    $proof=[
        'verified'=>true,
        'provider'=>'pasarguard',
        'server_id'=>2,
        'input_url'=>'https://subscriptions.example.invalid/new-token',
        'input_sha256'=>hash('sha256','https://subscriptions.example.invalid/new-token'),
        'token'=>'new-token',
        'target'=>achbotPgImportTarget($panel),
        'native_user'=>$native,
    ];
    $db->begin_transaction();
    if ($spec['op']==='wrong_id') $native['id']=72;
    achbotProviderAdoptAccount($db,2,$native,$proof,1001,201,9001,9001);
    $db->commit();
    $account=achbotProviderAccountByOrder($db,201);
    $byRemark=achbotPgRemarkContext($db,2,$native['username']);
    if ($byRemark['account']['id']!==$account['id'])
        throw new RuntimeException('reused_username_remark_misrouted');
    echo json_encode(['ok'=>true,
        'account_id'=>$account['id'],
        'native_id'=>$account['remote_user_id'],
        'origin_id'=>$account['order_id'],
        'target_key'=>$db->query("SELECT target_key FROM service_effects WHERE id=".(int)$account['effect_id'])->fetch_column(0),
        'old_state'=>$db->query("SELECT provider_state FROM service_effects WHERE id=".(int)$spec['old_effect'])->fetch_column(0),
    ],JSON_THROW_ON_ERROR);
} catch(Throwable $e) {
    try { $db->rollback(); } catch(Throwable $ignored) {}
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_THROW_ON_ERROR);
}
'''


def run() -> list[str]:
    checks: list[str] = []
    with fixtures.sandbox() as (sql, runner, provider, _epoch):
        # Use the same historical-cutover provenance constructor as the
        # original Hell/Vivan acceptance suite (two locally linked references).
        old_effect = int(historical.fixture_effect(sql))
        old_snapshot = sql(
            "SELECT provider_snapshot FROM service_effects WHERE id=" + str(old_effect))
        old_id = "d" * 32
        old_key = "delete:" + str(old_effect)
        old_native = {
            "remote_user_id": 72,
            "remote_created_at": historical.CREATED,
            "operation_marker": "achbot:adopt:" + old_id,
            "identity_mode": "adopted",
            "remote_username": USERNAME,
            "adoption_sha256": hashlib.sha256(old_snapshot.encode()).hexdigest(),
        }
        deletion_proof = {
            "version": 1, "key": old_key, "consumed_keys": [old_key],
            "action": "delete", "result": "verified", "identity": old_native,
        }
        sql("UPDATE service_effects SET provider_state='deleted', "
            "provider_mutation_key=" + fixtures.quote_sql(old_key) +
            ",provider_mutation_snapshot=" +
            fixtures.quote_sql(json.dumps(deletion_proof, separators=(",", ":"))) +
            " WHERE id=" + str(old_effect))
        # The old local references were deleted after the verified Native
        # deletion. Their financial and physical provenance stays on disk.
        sql("DELETE FROM orders_list WHERE id IN (101,102)")
        assert sql("SELECT COUNT(*) FROM orders_list WHERE remark=" +
                   fixtures.quote_sql(USERNAME)) == "0"
        old_preserved = sql(
            "SELECT source_kind,source_ref,target_key,provider_remote_user_id,"
            "provider_remote_created_at,provider_state,provider_snapshot,"
            "provider_mutation_snapshot FROM service_effects WHERE id=" +
            str(old_effect))
        checks.append("historical_deleted_incarnation_persisted_without_local_refs")

        sql("INSERT INTO add_link(remark,token,added_by) VALUES (" +
            fixtures.quote_sql(USERNAME) + ",'new-token',9001)")
        sql("INSERT INTO orders_list "
            "(id,userid,token,transid,fileid,server_id,inbound_id,remark,uuid,"
            "expire_date,link,amount,status,date,autoRenew) VALUES "
            "(201,1001,'new-token','',0,2,0," + fixtures.quote_sql(USERNAME) +
            ",'',0,'[]',0,1,'fixture','off')")
        native = {
            "id": NEW_ID, "username": USERNAME, "created_at": NEW_CREATED,
            "note": "independent-native-user", "data_limit": 123400000,
            "used_traffic": 20123, "expire": None, "status": "active",
            "group_ids": [2, 5],
            "proxy_settings": {"vless": {"id": "new-identity"}},
            "subscription_url": "https://subscriptions.example.invalid/new-token",
            "data_limit_reset_strategy": "no_reset", "next_plan": None,
            "auto_delete_in_days": None, "on_hold_expire_duration": None,
            "on_hold_timeout": None,
        }
        script = runner.fixture_work / "pasarguard-reimport.php"
        script.write_text(PHP, encoding="utf-8")

        def php_case(op: str):
            args = [
                "php", "-n", "-d", "extension=mysqlnd", "-d",
                "extension=mysqli", "-d", "extension=curl", "-d",
                "log_errors=1", "-d", "error_log=/dev/stderr",
                str(script), str(ROOT), str(runner.fixture_socket), fixtures.DB,
                json.dumps({"op": op, "old_effect": old_effect, "native": native}),
            ]
            proc = subprocess.run(
                args, cwd=runner.fixture_work, env=runner.fixture_env,
                capture_output=True, text=True, timeout=25)
            assert proc.returncode == 0, (
                "PHP failed", proc.returncode, proc.stderr[-800:])
            assert "fixture-private-key" not in proc.stderr
            assert "new-token" not in proc.stderr
            return json.loads(proc.stdout)

        # Accept future token versions with the same ID-hint shape, while
        # actual Native GET and signed /info verification remain mandatory.
        hints = php_case("hint_versions")
        assert hints == {"ok": True, "hints": [{"id": NEW_ID}]*4}, hints
        checks.append("token_version_is_not_an_arbitrary_lookup_allowlist")

        # The previously deleted ID must never be resurrected.
        negative = php_case("wrong_id")
        assert negative["ok"] is False and negative["error"] == (
            "provider_adoption_existing_identity_unverified"), negative
        assert sql("SELECT COUNT(*) FROM service_effects WHERE "
                   "source_kind='external_import'") == "0"
        checks.append("old_native_id_deleted_tombstone_cannot_be_resurrected")

        # A deleted old Native account with an outstanding active local order
        # still creates ambiguous same-name routing; do not attach yet.
        sql("INSERT INTO orders_list "
            "(id,userid,token,transid,fileid,server_id,inbound_id,remark,uuid,"
            "expire_date,link,amount,status,date,autoRenew,provider_effect_id,"
            "provider_remote_user_id,provider_remote_created_at) VALUES "
            "(102,1001,'old-token','',7,2,0," + fixtures.quote_sql(USERNAME) +
            ",'old-native-uuid',0,'[]',20000,1,'fixture','off'," +
            str(old_effect) + ",72," + fixtures.quote_sql(historical.CREATED) + ")")
        conflicting = php_case("new_incarnation")
        assert conflicting == {"ok": False,
                              "error": "provider_adoption_username_conflict"}, conflicting
        # A truly retired reference does not reserve a newly reused name.
        sql("UPDATE orders_list SET status=0 WHERE id=102")
        checks.append("stale_live_reference_blocks_same_name_reimport")
        handler=(ROOT/'src/Subscription/ManualAccounts.php').read_text()
        shared=(ROOT/'src/Subscription/PhysicalService.php').read_text()
        assert "achbotPhysicalImportAlreadyAttached(" in handler
        assert "o.userid=? AND o.status=1 AND BINARY o.remark=BINARY ?" in shared
        checks.append("retired_order_and_exact_case_do_not_block_new_native")

        result = php_case("new_incarnation")
        assert result["ok"] is True, result
        assert result["native_id"] == NEW_ID
        assert result["origin_id"] == 201
        assert result["target_key"] == "import:201", result
        assert result["old_state"] == "deleted"
        checks.append("fresh_native_id_same_username_adopts_without_name_collision")
        assert sql("SELECT COUNT(*) FROM orders_list WHERE id=102 AND status=0") == "1"
        checks.append("retired_order_preserved_and_live_username_resolves_new_identity")
        assert sql(
            "SELECT source_kind,source_ref,target_key,provider_remote_user_id,"
            "provider_remote_created_at,provider_state,provider_snapshot,"
            "provider_mutation_snapshot FROM service_effects WHERE id=" +
            str(old_effect)) == old_preserved
        checks.append("historical_deleted_provenance_and_mutation_untouched")
        assert sql("SELECT COUNT(*) FROM service_effects WHERE "
                   "provider_connection_id=1 AND provider_remote_user_id=73") == "1"
        assert sql("SELECT COUNT(*) FROM orders_list WHERE id=201 AND "
                   "provider_remote_user_id=73 AND provider_effect_id IS NOT NULL") == "1"
        assert sql("SELECT COUNT(*) FROM setting WHERE type='ACHBOT_SERVICE_ORIGIN:201'") == "1"
        checks.append("new_import_persists_distinct_native_identity_and_origin")
        return checks


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--run-disposable", action="store_true", required=True)
    parser.add_argument("--report", type=Path)
    args = parser.parse_args()
    checks = run()
    report = {"complete": True, "cases": len(checks), "checks": checks,
              "source_real": True, "disposable_socket_mariadb": True,
              "native_mutations": 0, "telegram_calls": 0, "production_effects": 0}
    if args.report:
        args.report.write_text(json.dumps(report, indent=2) + "\n")
    print(json.dumps(report))


if __name__ == "__main__":
    main()
