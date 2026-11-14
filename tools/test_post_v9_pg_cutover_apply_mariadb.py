#!/usr/bin/env python3
"""Isolated SQL transaction/identity checks for the historical account cutover.

Only an owned disposable MariaDB socket is accessed. No live API/DB/Telegram.
"""
from __future__ import annotations

import hashlib
import json
import os
from pathlib import Path
import subprocess
import tempfile

import test_access_state as owned
import test_v6_native as native

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "tools/post_v9_pg_cutover_apply.php"

SCHEMA = """
CREATE TABLE server_info(id INT PRIMARY KEY,title VARCHAR(100));
CREATE TABLE server_config(
 id INT PRIMARY KEY,type VARCHAR(40),panel_url VARCHAR(1000),
 password TEXT,provider_connection_id INT NULL,provider_instance_key CHAR(32) NULL,
 provider_version VARCHAR(32) NULL,provider_enabled TINYINT DEFAULT 0);
CREATE TABLE server_plans(
 id INT PRIMARY KEY,server_id INT,inbound_id INT,provider_group_ids TEXT NULL);
CREATE TABLE orders_list(
 id INT PRIMARY KEY,server_id INT,fileid INT,inbound_id INT,
 userid BIGINT,remark VARCHAR(100) COLLATE utf8mb4_bin,
 amount INT,status TINYINT,autoRenew VARCHAR(10),
 provider_effect_id BIGINT NULL,provider_remote_user_id BIGINT NULL,
 provider_remote_created_at VARCHAR(40) NULL);
CREATE TABLE setting(id INT AUTO_INCREMENT PRIMARY KEY,type VARCHAR(500),value TEXT);
CREATE TABLE service_plan_changes(id INT PRIMARY KEY,state VARCHAR(30));
CREATE TABLE service_effects(
 id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 pay_row_id INT NULL,source_kind VARCHAR(20) NULL,source_ref VARCHAR(100) NULL,
 actor_userid BIGINT NULL,ordinal INT DEFAULT 1,reserved_count INT DEFAULT 0,
 operation_id CHAR(32) NULL,effect_kind VARCHAR(16) DEFAULT 'create',
 target_server_id INT NULL,target_group_id INT NULL,target_key VARCHAR(48) NULL,
 endpoint_sha256 CHAR(64) NULL,plan_id INT NULL,remote_username VARCHAR(100) NULL,
 requested_expire BIGINT NULL,requested_bytes BIGINT NULL,request_sha256 CHAR(64) NULL,
 state VARCHAR(24) DEFAULT 'dispatching',order_id INT NULL,
 verified_at DATETIME NULL,provider_snapshot LONGTEXT NULL,
 provider_reference_id BIGINT NULL,provider_connection_id INT NULL,
 provider_remote_user_id BIGINT NULL,provider_remote_created_at VARCHAR(40) NULL,
 provider_operation_marker VARCHAR(64) NULL,provider_entitlement_snapshot LONGTEXT NULL,
 provider_state VARCHAR(24) NULL,
 provider_verified_at DATETIME NULL,provider_evidence_created_at DATETIME NULL,
 provider_evidence_updated_at DATETIME NULL,
 updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_effect_source_line (source_kind,source_ref,ordinal),
 UNIQUE KEY uq_effect_operation (operation_id),
 UNIQUE KEY uq_effect_order (order_id),
 UNIQUE KEY uq_effect_remote (provider_connection_id,provider_remote_user_id),
 UNIQUE KEY uq_effect_target_username (target_key,remote_username),
 UNIQUE KEY uq_effect_provider_reference (provider_reference_id));
INSERT INTO server_info VALUES (2,'Plus'),(3,'Lite'),(14,'PasarGuard test');
INSERT INTO server_config VALUES
 (2,'marzban','https://marzban-a.invalid' ,'old-private',NULL,NULL,'legacy',0),
 (3,'marzban','https://marzban-b.invalid' ,'old-private',NULL,NULL,'legacy',0),
 (14,'pasarguard','https://pg.invalid','fixture-private-key',14,REPEAT('a',32),'5.6.0',1);
UPDATE server_config SET provider_connection_id=NULL WHERE id IN (2,3);
INSERT INTO server_plans VALUES (9,2,0,NULL),(10,3,0,NULL),(82,14,0,'[1]');
INSERT INTO orders_list VALUES
 (101,2,9,0,1001,'plus_fixture',50000,1,'on',NULL,NULL,NULL),
 (102,2,9,0,1002,'plus_fixture',40000,1,'off',NULL,NULL,NULL),
 (103,3,0,0,1003,'planless_fixture',0,1,'off',NULL,NULL,NULL),
 (201,14,82,0,2001,'pg_test_fixture',100000,1,'off',41,61269,'2026-11-14T21:59:50.305933Z');
INSERT INTO service_effects (id,source_kind,source_ref,state,provider_state,
 provider_connection_id,provider_remote_user_id,provider_remote_created_at)
 VALUES (41,'free_trial','pg_fixture','local_recorded','linked',14,61269,'2026-11-14T21:59:50.305933Z');
"""


def fixtures():
    local = {
        "version": 1, "tenant": "hell",
        "servers": [{"id": 2, "provider": "marzban"}, {"id": 3, "provider": "marzban"}],
        "plans": [{"id": 9, "server_id": 2, "inbound_id": 0},
                  {"id": 10, "server_id": 3, "inbound_id": 0}],
        "orders": [
            {"id": 101, "server_id": 2, "plan_id": 9, "inbound_id": 0,
             "username": "plus_fixture", "owner_id": 1001,
             "provider_effect_id": None, "provider_remote_user_id": None,
             "auto_renew": "on", "amount": 50000},
            {"id": 102, "server_id": 2, "plan_id": 9, "inbound_id": 0,
             "username": "plus_fixture", "owner_id": 1002,
             "provider_effect_id": None, "provider_remote_user_id": None,
             "auto_renew": "off", "amount": 40000},
            {"id": 103, "server_id": 3, "plan_id": 0, "inbound_id": 0,
             "username": "planless_fixture", "owner_id": 1003,
             "provider_effect_id": None, "provider_remote_user_id": None,
             "auto_renew": "off", "amount": 0},
        ],
        "claims": {"unsettled_service_payments": 3,
                   "uncertain_provider_effects": 0, "uncommitted_plan_changes": 0},
    }
    mapping = {"version": 1, "tenant": "hell",
               "server_panels": {"2": "main", "3": "main"},
               "plan_groups": {"9": [1], "10": [3]}}
    native_manifest = {
        "version": 1, "tenant": "hell",
        "panels": [{
            "key": "main", "id": 14, "instance_key": "a"*32,
            "endpoint_sha256": hashlib.sha256(b"https://pg.invalid").hexdigest(),
            "available_groups": [1, 3],
        }],
        "accounts": [
            {"panel_key": "main", "id": 17, "username": "plus_fixture",
             "created_at": "2026-11-14T21:00:00.000000Z",
             "group_ids": [1], "data_limit": 7000000000, "used_traffic": 3000000000,
             "expire": None, "status": "active", "native_schedules_clear": True},
            {"panel_key": "main", "id": 18, "username": "planless_fixture",
             "created_at": "2026-11-14T21:02:00.000000Z",
             "group_ids": [], "data_limit": 0, "used_traffic": 0,
             "expire": None, "status": "expired", "native_schedules_clear": True},
        ],
    }
    refs = []
    for order in local["orders"]:
        account = native_manifest["accounts"][0 if order["server_id"] == 2 else 1]
        refs.append({
            "order_id": order["id"], "server_id": order["server_id"],
            "plan_id": order["plan_id"], "owner_id": order["owner_id"],
            "panel_id": 14, "remote_user_id": account["id"],
            "remote_created_at": account["created_at"],
            "native_groups": account["group_ids"],
            "planned_groups": [1] if order["plan_id"] else None,
        })
    for order in local["orders"]:
        order["status"] = 1
    return local, native_manifest, mapping, refs


def run():
    checks = []
    with owned.owned_database() as (work, env):
        with tempfile.TemporaryDirectory(prefix="pg-cutover-fixture-") as directory:
            folder = Path(directory)
            local, pg, mapping, refs = fixtures()
            docs = {"local": local, "native": pg, "mapping": mapping}
            hashes = {}
            args = []
            for name, obj in docs.items():
                path = folder / (name + ".json")
                path.write_text(json.dumps(obj, separators=(",", ":")) + "\n")
                path.chmod(0o600)
                hashes[name] = hashlib.sha256(path.read_bytes()).hexdigest()
                args.extend(["--" + name, str(path)])
            validated = {"version": 1, "tenant": "hell",
                         "input_sha256": hashes, "references": refs}
            vpath = folder / "validated-map.json"
            vpath.write_text(json.dumps(validated, separators=(",", ":")) + "\n")
            vpath.chmod(0o600)
            args.extend(["--validated-map", str(vpath)])
            os_env = dict(env, ACHBOT_PG_CUTOVER_DB_SOCKET=str(work/"mysql.sock"),
                          ACHBOT_PG_CUTOVER_DB="bothell",
                          ACHBOT_PG_CUTOVER_DB_USER="root", ACHBOT_PG_CUTOVER_DB_PASSWORD="",
                          ACHBOT_PG_CUTOVER_FIXTURE_DEBUG="1")

            # The operator script permits only the exact tenant-local schema.
            database = "bothell"
            native.sql(work, env, "CREATE DATABASE " + database)
            native.sql(work, env, SCHEMA, database)
            os_env["ACHBOT_PG_CUTOVER_DB"] = database

            def query(sql):
                return native.sql(work, env, sql, database).strip()

            def execute(flag=None):
                cmd = ["php", str(SCRIPT), "--tenant=hell"]
                for name in ("local", "native", "mapping"):
                    cmd.append("--" + name + "=" + str(folder / (name + ".json")))
                cmd.append("--validated-map=" + str(vpath))
                if flag:
                    cmd.append(flag)
                process = subprocess.run(cmd, env=os_env, cwd=work,
                                         capture_output=True, text=True, timeout=30)
                try:
                    result = json.loads(process.stdout)
                except json.JSONDecodeError as exc:
                    raise AssertionError((process.stdout, process.stderr)) from exc
                return process.returncode, result

            ret, result = execute()
            assert ret == 0 and result["outcome"] == "dry_run_only", result
            assert query("SELECT type FROM server_config WHERE id=2") == "marzban"
            assert query("SELECT provider_effect_id FROM orders_list WHERE id=101") == "NULL"
            checks.append("dry_run_no_effect")

            native.sql(work, env, "INSERT INTO service_plan_changes VALUES (1,'review')", database)
            ret, result = execute("--apply")
            assert ret == 2 and result["error_code"] == "live_unsettled_effects_or_plans", result
            assert query("SELECT COUNT(*) FROM setting") == "0"
            native.sql(work, env, "DELETE FROM service_plan_changes WHERE id=1", database)
            checks.append("live_uncommitted_plan_journal_hold")

            native.sql(work, env,
                "INSERT INTO service_effects (id,source_kind,source_ref,state) "
                "VALUES (90,'admin_bulk','fixture-ongoing','dispatching')",database)
            ret, result = execute("--apply")
            assert ret == 2 and result["error_code"] == "live_unsettled_effects_or_plans", result
            native.sql(work, env, "DELETE FROM service_effects WHERE id=90",database)
            checks.append("live_uncertain_effect_hold")

            native.sql(work, env, "UPDATE orders_list SET amount=40001 WHERE id=102", database)
            ret, result = execute("--apply")
            assert ret == 2 and result["error_code"] == "live_order_changed", result
            assert query("SELECT COUNT(*) FROM service_effects") == "1"
            native.sql(work, env, "UPDATE orders_list SET amount=40000 WHERE id=102", database)
            checks.append("stale_source_transaction_rollback")

            ret, result = execute("--apply")
            assert ret == 0 and result["outcome"] == "applied", result
            assert result["physical_accounts"] == 2 and result["orders"] == 3
            assert query("SELECT GROUP_CONCAT(type ORDER BY id) FROM server_config") == (
                "pasarguard,pasarguard,pasarguard")
            assert query("SELECT GROUP_CONCAT(provider_group_ids ORDER BY id) "
                         "FROM server_plans WHERE id IN (9,10)") == "[1],[3]"
            assert query("SELECT COUNT(DISTINCT provider_effect_id) FROM orders_list "
                         "WHERE id IN (101,102,103)") == "2"
            assert query("SELECT COUNT(*) FROM service_effects "
                         "WHERE source_kind='legacy_migration'") == "2"
            assert query("SELECT source_kind FROM service_effects WHERE id=41") == "free_trial"
            assert query("SELECT provider_effect_id FROM orders_list WHERE id=201") == "41"
            assert query("SELECT amount FROM orders_list WHERE id=102") == "40000"
            assert query("SELECT autoRenew FROM orders_list WHERE id=101") == "on"
            assert query("SELECT COUNT(*) FROM setting WHERE type='ACHBOT_PG_CUTOVER:v1:hell'") == "1"
            checks.append("atomic_effect_and_legacy_order_links")

            ret, result = execute("--apply")
            assert ret == 0 and result["outcome"] == "already_applied", result
            assert query("SELECT COUNT(*) FROM service_effects") == "3"
            checks.append("repeat_apply_no_replay")

            native.sql(work, env, "UPDATE server_plans SET provider_group_ids='[2]' "
                       "WHERE id=9", database)
            ret, result = execute("--apply")
            assert ret == 2 and result["error_code"] == "live_plan_changed", result
            assert query("SELECT COUNT(*) FROM service_effects") == "3"
            native.sql(work, env, "UPDATE server_plans SET provider_group_ids='[1]' "
                       "WHERE id=9", database)
            checks.append("already_applied_plan_drift_refused")

            # Evidence proof is checked by actual current source validator.
            helper = folder / "evidence.php"
            helper.write_text("""<?php
$_SERVER['SCRIPT_FILENAME']=__FILE__;
require $argv[1].'/src/Panel/ProviderStorage.php';
$db=new mysqli('localhost','root','','bothell',0,$argv[2]);
$e=$db->query("SELECT * FROM service_effects WHERE source_kind='legacy_migration' ORDER BY id LIMIT 1")->fetch_assoc();
$a=achbotProviderAccountRow($e);
$refs=achbotProviderLegacyCutoverEvidence($e,$a);
$id=achbotProviderAccountIdentity($db,$a);
echo json_encode(['refs'=>count($refs),'id_mode'=>$id['identity_mode'],
  'match'=>achbotPgIdentityMatches(['id'=>$a['remote_user_id'],
    'created_at'=>$a['remote_created_at'],'username'=>$a['remote_username'],'note'=>''], $id)]);
""")
            process = subprocess.run(["php", str(helper), str(ROOT), str(work/"mysql.sock")],
                                     env=os_env, capture_output=True, text=True, timeout=15)
            assert process.returncode == 0, (process.stdout, process.stderr)
            assert json.loads(process.stdout) == {
                "refs": 2, "id_mode": "adopted", "match": True
            }, process.stdout
            checks.append("source_real_historical_provenance")

            native.sql(work, env, "UPDATE service_effects SET provider_remote_created_at="
                       "'2026-11-14T20:00:00.000000Z' WHERE source_kind='legacy_migration' "
                       "AND remote_username='plus_fixture'", database)
            process = subprocess.run(["php", str(helper), str(ROOT), str(work/"mysql.sock")],
                                     env=os_env, capture_output=True, text=True, timeout=15)
            assert process.returncode != 0, process.stdout
            checks.append("altered_incarnation_rejected")
    with owned.owned_database() as (work, env):
        with tempfile.TemporaryDirectory(prefix="pg-cutover-exceptions-") as directory:
            folder = Path(directory)
            native.sql(work, env, "CREATE DATABASE bothell")
            native.sql(work, env, SCHEMA, "bothell")
            native.sql(work, env, "INSERT INTO orders_list VALUES "
                "(104,2,9,0,1004,'orphan_historical',70000,1,'on',NULL,NULL,NULL)", "bothell")
            local, pg, mapping, refs = fixtures()
            local["orders"].append({"id": 104, "server_id": 2, "plan_id": 9,
                "inbound_id": 0, "username": "orphan_historical", "owner_id": 1004,
                "provider_effect_id": None, "provider_remote_user_id": None,
                "auto_renew": "on", "status": 1, "amount": 70000})
            pg["accounts"][0]["group_ids"] = [3]
            pg["accounts"][0]["native_schedules_clear"] = False
            pg["not_found"] = [{"panel_key": "main", "username": "orphan_historical"}]
            for ref in refs[:2]:
                ref["native_groups"] = [3]
                ref["group_preserved"] = True
                ref["schedule_preserved"] = True
            refs[2]["group_preserved"] = False
            refs[2]["schedule_preserved"] = False
            docs = {"local": local, "native": pg, "mapping": mapping}
            hashes = {}
            for label, value in docs.items():
                path = folder / (label + ".json")
                path.write_text(json.dumps(value, separators=(",", ":")) + "\n")
                path.chmod(0o600)
                hashes[label] = hashlib.sha256(path.read_bytes()).hexdigest()
            validated = {"version": 1, "tenant": "hell", "input_sha256": hashes,
                "exceptions": {"missing_accounts": 1, "preserved_groups": 1, "preserved_schedules": 1},
                "references": refs,
                "excluded_orders": [{"order_id": 104, "server_id": 2,
                    "username": "orphan_historical", "owner_id": 1004, "plan_id": 9}]}
            vpath = folder / "validated-map.json"
            vpath.write_text(json.dumps(validated, separators=(",", ":")) + "\n")
            vpath.chmod(0o600)
            db_env = dict(env, ACHBOT_PG_CUTOVER_DB_SOCKET=str(work / "mysql.sock"),
                ACHBOT_PG_CUTOVER_DB="bothell", ACHBOT_PG_CUTOVER_DB_USER="root",
                ACHBOT_PG_CUTOVER_DB_PASSWORD="", ACHBOT_PG_CUTOVER_FIXTURE_DEBUG="1")
            def execute(action):
                cmd = ["php", str(SCRIPT), "--tenant=hell"] + [
                    "--" + name + "=" + str(folder / (name + ".json"))
                    for name in ("local", "native", "mapping")]
                cmd += ["--validated-map=" + str(vpath), action]
                process = subprocess.run(cmd, env=db_env, capture_output=True,
                    text=True, timeout=30)
                return process.returncode, json.loads(process.stdout)
            result = execute("--dry-run")
            assert result[0] == 0 and result[1]["excluded_orders"] == 1, result
            assert native.sql(work, env,
                "SELECT status,autoRenew FROM orders_list WHERE id=104", "bothell").strip() == "1\ton"
            ret, result = execute("--apply")
            assert ret == 0 and result["outcome"] == "applied", result
            assert result["excluded_orders"] == 1 and result["preserved_groups"] == 1
            assert result["preserved_schedules"] == 1
            assert native.sql(work, env,
                "SELECT CONCAT(status,':',autoRenew,':',COALESCE(provider_effect_id,'NULL')) "
                "FROM orders_list WHERE id=104", "bothell").strip() == "0:off:NULL"
            assert native.sql(work, env,
                "SELECT COUNT(*) FROM service_effects "
                "WHERE source_kind='legacy_migration'", "bothell").strip() == "2"
            assert native.sql(work, env,
                "SELECT provider_group_ids FROM server_plans WHERE id=9", "bothell").strip() == "[1]"
            assert native.sql(work, env,
                "SELECT JSON_UNQUOTE(JSON_EXTRACT(provider_entitlement_snapshot,'$.group_ids[0]')) "
                "FROM service_effects WHERE source_kind='legacy_migration' "
                "AND remote_username='plus_fixture'", "bothell").strip() == "3"
            assert native.sql(work, env,
                "SELECT JSON_UNQUOTE(JSON_EXTRACT(provider_entitlement_snapshot,"
                "'$.native_schedules_clear')) FROM service_effects "
                "WHERE source_kind='legacy_migration' AND remote_username='plus_fixture'",
                "bothell").strip() == "false"
            ret, result = execute("--apply")
            assert ret == 0 and result["outcome"] == "already_applied", result
            assert result["excluded_orders"] == 1
            checks.append("approved_orphan_preserved_finance_and_group_actual")
            # A changed Native identity can never silently replace the absent account.
            native.sql(work, env,
                "UPDATE orders_list SET status=1 WHERE id=104", "bothell")
            ret, result = execute("--apply")
            assert ret == 2 and result["error_code"] == "live_order_changed", result
            checks.append("orphan_post_cutover_drift_rejected")
    print(json.dumps({"complete": True, "isolated": True, "checks": checks}))


if __name__ == "__main__":
    run()
