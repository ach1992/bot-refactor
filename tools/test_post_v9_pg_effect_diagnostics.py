#!/usr/bin/env python3
"""AChBot #117: source-real PasarGuard unknown CREATE diagnostic boundaries.

PHP 8.5, disposable socket MariaDB, and a 127.0.0.1 native provider emulator.
No installed Chili, real credentials, payments, Telegram, or external POSTs.
"""
from __future__ import annotations

import argparse
import contextlib
import copy
import hashlib
import json
import re
import subprocess
import threading
from http.server import ThreadingHTTPServer
from pathlib import Path
from urllib.parse import urlsplit

import test_access_state as owned
import test_post_v9_pasarguard_client as pg
import test_v6_native as native
import test_v8_native as purchase

ROOT = Path(__file__).resolve().parents[1]
DB = purchase.SIDE[1]
EXPIRE = 2000000000
BYTES = 2147483648
INSTANCE = "b" * 32

PHP = r'''<?php
error_reporting(E_ALL);
set_error_handler(static function($severity,$text){
    if ($severity===E_DEPRECATED) return true;
    throw new ErrorException('fixture_warning',0,$severity);
});
if (PHP_VERSION_ID<80500 || PHP_VERSION_ID>=80600) throw new RuntimeException('php85_required');
define('ACHBOT_PG_TEST_LOOPBACK',true);
$_SERVER['SCRIPT_FILENAME']=__FILE__;
$root=$argv[1];$socket=$argv[2];$database=$argv[3];
$spec=json_decode($argv[4],true,64,JSON_THROW_ON_ERROR);
ini_set('log_errors','1');
ini_set('error_log',__DIR__.'/pg-effect-diagnostic.log');
class BeginFaultDb extends mysqli {
    public bool $failNextBegin=false;
    public function begin_transaction(int $flags=0,?string $name=null):bool {
        if ($this->failNextBegin) {$this->failNextBegin=false;return false;}
        return parent::begin_transaction($flags,$name);
    }
}
$db=new BeginFaultDb('localhost','root','',$database,0,$socket);
$db->set_charset('utf8mb4');
require_once $root.'/src/Finance/ServiceEffects.php';
achbotPgLoad();
$result=null;
try {
    $effect=(int)($spec['effect']??0);
    $username=(string)($spec['username']??'');
    $operation=(string)($spec['operation']??'');
    if ($spec['op']==='reserve') {
        if (!$db->begin_transaction()) throw new RuntimeException('fixture_reserve_begin');
        $snap=achbotProviderSnapshot($db,2,7);
        $result=achbotProviderReserveAccount($db,$effect,$snap,$username,
            'achbot:v1:'.$operation,
            ['data_limit'=>2147483648,'expire'=>2000000000,
             'group_ids'=>[2,5],'status'=>'active']);
        if (!$db->commit()) throw new RuntimeException('fixture_reserve_commit');
        $result=['newly_reserved'=>$result['newly_reserved']];
    } elseif ($spec['op']==='dispatch') {
        $db->failNextBegin=($spec['fail_begin']??false)===true;
        $result=achbotPgDispatchReserved($db,$effect,2,$username,$operation,
            2000000000,2147483648,['port'=>443],hash('sha256','fixture-private-key'));
        $result=['verified'=>($result[0]->verified??false)===true];
    } elseif ($spec['op']==='readback') {
        require_once $root.'/src/Finance/RecoveryInspection.php';
        $result=achbotRecoveryInspectPaidLine($db,(int)($spec['pay']??0),
            (int)($spec['ordinal']??0),
            static function(){throw new RuntimeException('wrong_marzban_adapter');});
    } elseif ($spec['op']==='group_preflight') {
        $panel=achbotPgServerPanel($db,2);
        $result=achbotPgCreate($panel,['username'=>'group_probe',
            'data_limit'=>2147483648,'expire'=>2000000000,
            'group_ids'=>[],'operation_marker'=>'achbot:v1:'.str_repeat('e',32)]);
        $result=['success'=>$result['success'],'reason'=>$result['reason'],
            'uncertain'=>$result['uncertain'],
            'safe'=>achbotEffectSafeFailure(new RuntimeException($result['reason']))];
    } elseif ($spec['op']==='untrusted_reason') {
        $reason=new RuntimeException('fixture-private-key untrusted provider SQL detail');
        $result=['safe'=>achbotEffectOperatorDiagnostic(
            new RuntimeException('pg_create_requires_review',0,$reason),0,$effect,1,'remote_create')];
    } else throw new RuntimeException('fixture_operation_invalid');
    echo json_encode(['ok'=>true,'result'=>$result],JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    echo json_encode(['ok'=>false,'class'=>get_class($error),
        'reason'=>$error->getMessage()],JSON_THROW_ON_ERROR);
}
'''


class FaultPanel(pg.Handler):
    """Mutate only the disposable DB after the real POST reaches loopback."""
    def handle_request(self):
        if self.command == "GET" and urlsplit(self.path).path.startswith("/api/user/by-"):
            callback = getattr(self.server, "on_read", None)
            if callback is not None:
                self.server.on_read = None
                callback()
        if self.command == "POST" and urlsplit(self.path).path == "/api/user":
            callback = getattr(self.server, "on_create", None)
            if callback is not None:
                self.server.on_create = None
                callback()
        super().handle_request()


@contextlib.contextmanager
def panel():
    server = ThreadingHTTPServer(("127.0.0.1", 0), FaultPanel)
    server.trace = []
    server.user = copy.deepcopy(pg.USER)
    server.mode = "ok"
    server.write_count = 0
    server.links_requested = False
    server.on_create = None
    server.on_read = None
    worker = threading.Thread(target=server.serve_forever, daemon=True)
    worker.start()
    try:
        yield server, f"http://127.0.0.1:{server.server_port}"
    finally:
        server.shutdown()
        worker.join(timeout=5)
        server.server_close()


def test():
    checks = []
    with owned.owned_database() as (work, env), panel() as (server, url):
        purchase.init(work, env, url)

        def sql(statement):
            return native.sql(work, env, statement, DB).strip()

        for name in ("001_marzban_panel_group_id.sql", "007_service_effects.sql",
                     "011_provider_identity.sql", "012_shared_provider_storage.sql"):
            sql((ROOT / "migrations/post-v9" / name).read_text())
        sql("UPDATE server_config SET type='pasarguard',"
            "password='fixture-private-key',provider_connection_id=1,"
            f"provider_instance_key='{INSTANCE}',provider_version='5.5.1',"
            "provider_enabled=1 WHERE id=2")
        sql("UPDATE server_plans SET provider_group_ids='[2,5]' WHERE id IN (7,8)")
        target_hash = hashlib.sha256(url.encode()).hexdigest()
        snapshot = json.dumps(dict(provider="pasarguard", panel_id=1,
            instance_key=INSTANCE, endpoint_sha256=target_hash,
            group_ids=[2, 5]), separators=(",", ":"))
        driver = work / "pg-effect-run.php"
        driver.write_text(PHP)
        log = work / "pg-effect-diagnostic.log"

        def call(**spec):
            command = ["php", "-n", "-d", "extension=mysqlnd", "-d", "extension=mysqli",
                       "-d", "extension=curl", "-d", "extension=ctype",
                       "-d", "allow_url_fopen=0", "-d", "display_errors=0",
                       "-d", "disable_functions=exec,shell_exec,system,passthru,popen,proc_open,mail",
                       str(driver), str(ROOT), str(work / "mysql.sock"), DB,
                       json.dumps(spec, separators=(",", ":"))]
            p = subprocess.run(command, cwd=work, env=env, capture_output=True,
                               text=True, timeout=35)
            if p.returncode or p.stderr:
                raise AssertionError(("php85_fixture_error", p.returncode,
                                      p.stderr[-1000:], p.stdout[-600:]))
            try:
                return json.loads(p.stdout)
            except ValueError as error:
                raise AssertionError(("php85_invalid_fixture_response", p.stdout[-800:])) from error

        def lines_since(before):
            after = log.read_text() if log.exists() else ""
            assert after.startswith(before), "diagnostic_log_rewritten"
            return [re.sub(r"^\[[^\]]+\]\s*", "", line)
                    for line in after[len(before):].splitlines()]

        def new_effect(label):
            digest = hashlib.sha256(label.encode()).hexdigest()[:32]
            username = "fixture_" + label
            source = "case:" + label
            escaped = snapshot.replace("'", "''")
            sql("INSERT INTO service_effects "
                "(source_kind,source_ref,actor_userid,ordinal,operation_id,effect_kind,"
                "target_server_id,target_key,endpoint_sha256,plan_id,remote_username,"
                "requested_expire,requested_bytes,request_sha256,provider_snapshot) VALUES "
                f"('trial','{source}',1001,1,'{digest}','create',2,'pg:fixture',"
                f"'{target_hash}',7,'{username}',{EXPIRE},{BYTES},REPEAT('c',64),"
                f"'{escaped}')")
            effect = int(sql(f"SELECT id FROM service_effects WHERE source_ref='{source}'"))
            r = call(op="reserve", effect=effect, username=username, operation=digest)
            assert r == {"ok": True, "result": {"newly_reserved": True}}, (label, r)
            return effect, username, digest

        cases = [
            ("normal", "ok", None, None, None, 1, True),
            ("begin_failure", "ok", "begin", "verify_local", "pg_verify_begin", 1, False),
            ("credential_drift", "ok", "credentials", "verify_local", "pg_credentials_changed", 1, False),
            ("invalid_username", "ok", "username", "preflight", "pg_create_invalid", 0, False),
            ("post_lost", "write_lost", None, "remote_create", "pg_transport_unavailable", 1, False),
            ("provider_503", "http_503", None, "remote_create", "pg_vendor_unavailable", 1, False),
        ]
        for label, mode, fault, stage, reason, posts, success in cases:
            sql("UPDATE server_config SET password='fixture-private-key' WHERE id=2")
            effect, username, operation = new_effect(label)
            if fault == "username":
                username = "invalid%%name"
                sql(f"UPDATE service_effects SET remote_username='{username}' WHERE id={effect}")
            server.mode = mode
            server.trace = []
            server.user = copy.deepcopy(pg.USER)
            server.on_create = (lambda: sql("UPDATE server_config SET password='changed-fixture-key' WHERE id=2")) if fault == "credentials" else None
            before = log.read_text() if log.exists() else ""
            old_capacity = sql("SELECT ucount FROM server_info WHERE id=2")
            old_orders = sql("SELECT COUNT(*) FROM orders_list")
            old_pays = sql("SELECT COUNT(*) FROM pays")
            result = call(op="dispatch", effect=effect, username=username,
                operation=operation, fail_begin=fault == "begin")
            sent = sum(command == "POST" and urlsplit(path).path == "/api/user"
                       for command, path, _ in server.trace)
            assert sent == posts, (label, sent, server.trace)
            assert result["ok"] is success, (label, result)
            diagnostic = lines_since(before)
            if success:
                assert not diagnostic, (label, diagnostic)
                assert result["result"]["verified"] is True, (label, result)
                assert sql(f"SELECT state,provider_state FROM service_effects WHERE id={effect}") == "verified\tverified"
            else:
                assert result["reason"] in ("pg_create_requires_review", "pg_verify_begin", "pg_credentials_changed"), (label, result)
                assert len(diagnostic) == 1, (label, diagnostic)
                pattern = (rf"achbot_paid_effect_needs_reconciliation pay_id=0 effect_id={effect} ordinal=1 "
                    rf"stage={stage} reason={reason} cause=runtime_exception depth=[1-8]")
                assert re.fullmatch(pattern, diagnostic[0]), (label, diagnostic)
                assert "fixture-private-key" not in diagnostic[0] and "SQL" not in diagnostic[0]
                assert sql(f"SELECT state,provider_state FROM service_effects WHERE id={effect}") == "dispatching\tdispatching", label
                # A second invocation MUST NOT resend an already claimed POST.
                old_sent = len(server.trace)
                repeated = call(op="dispatch", effect=effect, username=username,
                    operation=operation)
                assert repeated["ok"] is False and len(server.trace) == old_sent, (label, repeated)
            assert sql("SELECT ucount FROM server_info WHERE id=2") == old_capacity, label
            assert sql("SELECT COUNT(*) FROM orders_list") == old_orders, label
            assert sql("SELECT COUNT(*) FROM pays") == old_pays, label
            checks.append(label + "_source_real_diagnostic_and_no_replay")

        server.mode = "ok"
        server.trace = []
        before = log.read_text() if log.exists() else ""
        empty = call(op="group_preflight")
        assert empty["ok"] is True and empty["result"]["success"] is False
        assert empty["result"]["reason"] == "pg_groups_required"
        assert empty["result"]["uncertain"] is False
        assert empty["result"]["safe"]["reason"] == "pg_groups_required"
        assert not server.trace and not lines_since(before), empty
        checks.append("native_group_preflight_no_post_and_safe_reason")

        raw = call(op="untrusted_reason", effect=1)
        assert raw["ok"] is True and "runtime_error" in raw["result"]["safe"]
        assert not any(secret in raw["result"]["safe"] for secret in
                       ("fixture-private-key", "SQL detail", "provider")), raw
        checks.append("untrusted_provider_error_text_sanitized")

        # An original *paid* two-account purchase, with only its first native
        # CREATE journaled: the other ordinal is never guessed or queried.
        pay_key="pg-inspect-owned"
        sql("INSERT INTO pays(hash_id,user_id,type,plan_id,server_id,agent_count,"
            "price,request_date,state) VALUES "
            f"('{pay_key}',1001,'BUY_SUB',7,2,2,200,1700000000,'processing')")
        pay_id=int(sql(f"SELECT id FROM pays WHERE hash_id='{pay_key}'"))
        operation="d"*32
        username="fixture_paid"
        target_key=f"pg:{INSTANCE}"
        fingerprint=hashlib.sha256(json.dumps(
            [pay_id,1,7,2,None,target_key,target_hash,username,EXPIRE,BYTES],
            separators=(",",":")).encode()).hexdigest()
        quoted_snap=snapshot.replace("'","''")
        sql("INSERT INTO service_effects "
            "(pay_row_id,ordinal,operation_id,effect_kind,target_server_id,"
            "target_key,endpoint_sha256,plan_id,remote_username,requested_expire,"
            "requested_bytes,request_sha256,provider_snapshot,reserved_count,state) "
            f"VALUES ({pay_id},1,'{operation}','create',2,'{target_key}',"
            f"'{target_hash}',7,'{username}',{EXPIRE},{BYTES},'{fingerprint}',"
            f"'{quoted_snap}',2,'dispatching')")
        paid_effect=int(sql(f"SELECT id FROM service_effects WHERE pay_row_id={pay_id}"))
        assert call(op="reserve",effect=paid_effect,username=username,
                    operation=operation)=={"ok":True,"result":{"newly_reserved":True}}
        server.user=copy.deepcopy(pg.USER)
        server.user.update(id=117,username=username,note="achbot:v1:"+operation,
                           data_limit=BYTES,expire="2033-05-18T03:33:20Z",
                           group_ids=[2,5])
        baseline=(
            sql(f"SELECT state FROM pays WHERE id={pay_id}"),
            sql("SELECT wallet FROM users WHERE userid=1001"),
            sql("SELECT ucount FROM server_info WHERE id=2"),
            sql("SELECT COUNT(*) FROM orders_list"),
            sql(f"SELECT state,provider_state,order_id FROM service_effects WHERE id={paid_effect}"),
        )
        def inspect(ordinal=1,mode="ok"):
            server.mode=mode
            server.trace=[]
            server.write_count=0
            reply=call(op="readback",pay=pay_id,ordinal=ordinal)
            assert reply["ok"] is True, reply
            assert server.write_count==0
            assert all(method=="GET" for method,path,_ in server.trace),server.trace
            assert (sql(f"SELECT state FROM pays WHERE id={pay_id}"),
                    sql("SELECT wallet FROM users WHERE userid=1001"),
                    sql("SELECT ucount FROM server_info WHERE id=2"),
                    sql("SELECT COUNT(*) FROM orders_list"),
                    sql(f"SELECT state,provider_state,order_id FROM service_effects WHERE id={paid_effect}"))==baseline
            return reply["result"]
        success=inspect()
        assert success["status"]=="matching_observation" and success["remote_gets"]==1
        assert success["read_only"] is True and success["settlement_proven"] is False
        assert len(server.trace)==1 and server.trace[0][1]=="/api/user/by-username/"+username
        checks.append("paid_pg_exact_marker_username_readback_no_post_no_financial_write")
        missing=inspect(ordinal=2)
        assert missing["status"]=="target_or_identity_changed" and missing["remote_gets"]==0
        assert not server.trace
        checks.append("paid_pg_partial_bulk_missing_ordinal_never_infers_absence")
        mismatch=inspect(mode="foreign_note")
        assert mismatch["status"]=="remote_mismatch" and mismatch["remote_gets"]==1
        checks.append("paid_pg_other_account_marker_is_not_original_provision")
        server.user["group_ids"]=[2,7]
        group_mismatch=inspect()
        assert group_mismatch["status"]=="remote_mismatch" and group_mismatch["remote_gets"]==1
        server.user["group_ids"]=[2,5]
        checks.append("paid_pg_original_groups_mismatch_is_not_proof")
        reset=inspect(mode="scheduled_reset")
        assert reset["status"]=="remote_mismatch" and reset["remote_gets"]==1
        checks.append("paid_pg_native_policy_drift_is_not_proof")
        unavailable=inspect(mode="http_404")
        assert unavailable["status"]=="remote_unavailable" and unavailable["remote_gets"]==1
        checks.append("paid_pg_404_is_uncertainty_not_create_permission")
        sql(f"UPDATE service_effects SET request_sha256=REPEAT('f',64) WHERE id={paid_effect}")
        # The deliberately corrupted journal is not a new legitimate local state.
        reject=call(op="readback",pay=pay_id,ordinal=1)["result"]
        assert reject["status"]=="target_or_identity_changed" and reject["remote_gets"]==0
        sql(f"UPDATE service_effects SET request_sha256='{fingerprint}' WHERE id={paid_effect}")
        checks.append("paid_pg_corrupt_original_fingerprint_blocks_remote_read")
        sql("UPDATE server_config SET provider_instance_key=REPEAT('e',32) WHERE id=2")
        server.trace=[]
        reject=call(op="readback",pay=pay_id,ordinal=1)["result"]
        assert reject["status"]=="target_or_identity_changed" and reject["remote_gets"]==0
        assert not server.trace
        sql(f"UPDATE server_config SET provider_instance_key='{INSTANCE}' WHERE id=2")
        checks.append("paid_pg_other_native_instance_refuses_get")
        server.on_read=lambda:sql(f"UPDATE pays SET state='approved' WHERE id={pay_id}")
        server.mode="ok";server.trace=[]
        changed=call(op="readback",pay=pay_id,ordinal=1)["result"]
        assert changed["status"]=="changed_during_inspection" and changed["remote_gets"]==1
        assert len(server.trace)==1
        sql(f"UPDATE pays SET state='processing' WHERE id={pay_id}")
        checks.append("paid_pg_payment_race_during_get_fails_closed")
        # An already identified original incarnation must use by-ID, not
        # silently fall back to another account with the same username.
        sql("UPDATE service_effects SET state='verified',provider_state='verified',"
            "provider_remote_user_id=117,"
            "provider_remote_created_at='2026-10-26T05:00:00.123456Z' "
            f"WHERE id={paid_effect}")
        baseline=(
            sql(f"SELECT state FROM pays WHERE id={pay_id}"),
            sql("SELECT wallet FROM users WHERE userid=1001"),
            sql("SELECT ucount FROM server_info WHERE id=2"),
            sql("SELECT COUNT(*) FROM orders_list"),
            sql(f"SELECT state,provider_state,order_id FROM service_effects WHERE id={paid_effect}"),
        )
        by_id=inspect()
        assert by_id["status"]=="matching_observation" and by_id["remote_gets"]==1
        assert len(server.trace)==1 and server.trace[0][1]=="/api/user/by-id/117"
        checks.append("paid_pg_verified_native_incarnation_exact_by_id_only")
        foreign_id=inspect(mode="foreign_id")
        assert foreign_id["status"]=="remote_unavailable" and foreign_id["remote_gets"]==1
        checks.append("paid_pg_foreign_native_id_does_not_match_verified_claim")
    return dict(complete=True, cases=len(checks), checks=checks,
                php85_source_real=True, owned_socket_mariadb=True,
                actual_pasarguard_post_and_readback=True, native_post_replays=0,
                production_and_external_effects=0)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--run-disposable", action="store_true", required=True)
    parser.add_argument("--report", type=Path)
    args = parser.parse_args()
    result = test()
    if args.report:
        args.report.write_text(json.dumps(result, indent=2) + "\n")
    print(json.dumps(result, sort_keys=True))


if __name__ == "__main__":
    main()
