#!/usr/bin/env python3
"""Actual socket-only MariaDB and synthetic installed-file cleanup guard tests."""
from __future__ import annotations
import argparse
import hashlib
import json
import os
import shutil
import subprocess
import tempfile
from pathlib import Path
import audit_retired_runtime as retired
import package_post_v9 as package
import test_access_state as owned
import test_v6_native as native

ROOT = Path(__file__).resolve().parents[1]
MANIFEST = json.loads((ROOT/"migrations/post-v9/010_cleanup_manifest.json").read_text())
DB = owned.NAMES[1]

def private_json(path, value):
    path.write_text(json.dumps(value))
    path.chmod(0o600)

def run():
    passed = []
    with owned.owned_database() as (work, env):
        config = work/"connection.json"
        private_json(config, {"socket": str(work/"mysql.sock"), "database": DB, "user": "root", "password": ""})
        def sql(query):
            return native.sql(work, env, query, DB).strip()
        def call(table="admins", extra=(), reason=None):
            command = ["php", str(ROOT/"tools/cleanup_post_v9.php"),
                       "--config="+str(config), "--table="+table, *extra]
            process = subprocess.run(command, capture_output=True, env=env, timeout=15)
            if process.stderr:
                raise RuntimeError("Unexpected PHP diagnostics: "+process.stderr.decode())
            result = json.loads(process.stdout)
            if reason is not None:
                assert process.returncode == 2 and result == {"applied": False, "refused": reason}, result
            else:
                assert process.returncode == 0, result
            return result
        def approve(plan, **override):
            disposition = {key: plan[key] for key in ("table","database","identity_sha256","schema_sha256")}
            disposition.update(external_consumers="none-confirmed", retention="empty-no-retention",
                maintenance="exclusive-no-writers-or-ddl", operator="synthetic-test",
                approval_reference="owned-fixture-only")
            disposition.update(override)
            path = work/"disposition.json"
            private_json(path, disposition)
            return "--disposition="+str(path)
        before = sql("SHOW TABLES")
        active_rows = {table: sql("SELECT * FROM "+table) for table in before.splitlines()
                       if table not in MANIFEST["tables"]}
        for table in MANIFEST["tables"]:
            plan = call(table)
            assert plan["empty"] and not plan["applied"]
        assert sql("SHOW TABLES") == before
        passed.append("default_plan_all_three_is_read_only")
        call("users", reason="table_not_allowlisted")
        passed.append("active_table_refused")
        call(extra=["--apply"], reason="explicit_disposition_and_restore_path_required")
        passed.append("apply_requires_disposition_and_restore")
        plan = call()
        restore = "--restore-ddl="+str(work/"restore.sql")
        call(extra=["--apply",approve(plan, identity_sha256="0"*64),restore],
             reason="target_disposition_mismatch")
        call(extra=["--apply",approve(plan, retention="undecided"),restore],
             reason="operator_external_retention_maintenance_disposition_required")
        call(extra=["--apply",approve(plan, external_consumers="unknown"),restore],
             reason="operator_external_retention_maintenance_disposition_required")
        passed.append("target_retention_external_use_disposition_refused")
        # No table-content or credential values are emitted in refusals.
        inserts = {
            "admins": "INSERT INTO admins(username,password,backupchannel,lang) VALUES('fixture','synthetic-secret','0','fa')",
            "black_list": "INSERT INTO black_list(info) VALUES('fixture')",
            "servers": "INSERT INTO servers(ip,port,username,password,name,panel,status) VALUES('fixture',1,'fixture','synthetic-secret','fixture','old',0)",
        }
        for table, query in inserts.items():
            sql(query)
            call(table, reason="nonempty_table_preserved")
            assert sql("SELECT COUNT(*) FROM "+table) == "1"
            sql("DELETE FROM "+table)
        passed.append("all_nonempty_candidates_preserved")
        sql("ALTER TABLE admins ADD unexpected int NULL")
        call(reason="original_schema_fingerprint_required")
        sql("ALTER TABLE admins DROP unexpected")
        passed.append("schema_drift_preserved")
        sql("CREATE TABLE dependency(id int PRIMARY KEY, admin_id int, FOREIGN KEY(admin_id) REFERENCES admins(id)) ENGINE=InnoDB")
        call(reason="foreign_key_dependency_preserved")
        sql("DROP TABLE dependency")
        passed.append("incoming_foreign_key_preserved")
        sql("CREATE VIEW dependency_view AS SELECT id FROM admins")
        call(reason="programmable_dependency_unproven")
        sql("DROP VIEW dependency_view")
        sql("CREATE VIEW "+owned.NAMES[0]+".external_dependency AS SELECT id FROM "+DB+".admins")
        call(reason="programmable_dependency_unproven")
        sql("DROP VIEW "+owned.NAMES[0]+".external_dependency")
        sql("CREATE PROCEDURE dependency_routine() SELECT id FROM admins")
        call(reason="programmable_dependency_unproven")
        sql("DROP PROCEDURE dependency_routine")
        sql("CREATE TRIGGER dependency_trigger BEFORE INSERT ON admins FOR EACH ROW SET NEW.lang='fa'")
        call(reason="programmable_dependency_unproven")
        sql("DROP TRIGGER dependency_trigger")
        sql("CREATE EVENT dependency_event ON SCHEDULE EVERY 1 DAY DO SELECT id FROM admins")
        call(reason="programmable_dependency_unproven")
        sql("DROP EVENT dependency_event")
        passed.append("view_routine_trigger_event_refused")
        sql("CREATE USER 'cleanup_limited'@'localhost' IDENTIFIED BY ''")
        sql("GRANT SELECT ON "+DB+".* TO 'cleanup_limited'@'localhost'")
        private_json(config, {"socket": str(work/"mysql.sock"), "database": DB, "user":"cleanup_limited","password":""})
        call(reason="global_dependency_metadata_visibility_required")
        private_json(config, {"socket": str(work/"mysql.sock"), "database": DB, "user":"root","password":""})
        passed.append("incomplete_metadata_visibility_refused")
        (work/"restore.sql").write_text("preserve-existing")
        call(extra=["--apply",approve(call()),restore], reason="new_private_restore_file_required")
        assert (work/"restore.sql").read_text() == "preserve-existing"
        passed.append("existing_restore_file_preserved")
        for table in MANIFEST["tables"]:
            plan = call(table)
            path = work/(table+"-empty-restore.sql")
            result = call(table, ["--apply",approve(plan),"--restore-ddl="+str(path)])
            assert result["applied"] and path.stat().st_mode & 0o077 == 0
            assert table not in sql("SHOW TABLES").splitlines()
            sql(path.read_text())
            assert call(table)["schema_sha256"] == plan["schema_sha256"]
            assert sql("SELECT COUNT(*) FROM "+table) == "0"
        assert sql("SHOW TABLES") == before
        assert active_rows == {table: sql("SELECT * FROM "+table) for table in active_rows}
        passed.append("all_three_exact_empty_apply_and_ddl_restore")
    with tempfile.TemporaryDirectory(prefix="achbot-retired-source-") as tmp:
        install = Path(tmp)/"install"
        install.mkdir()
        files = {}
        for name in package.members():
            path = install/name
            path.parent.mkdir(parents=True, exist_ok=True)
            raw = (ROOT/name).read_bytes()
            path.write_bytes(raw)
            files[name] = {"size": len(raw), "sha256": hashlib.sha256(raw).hexdigest()}
        head = subprocess.check_output(["git","rev-parse","HEAD"],cwd=ROOT).decode().strip()
        tree = subprocess.check_output(["git","rev-parse","HEAD^{tree}"],cwd=ROOT).decode().strip()
        metadata = {"candidate":head,"tree":tree,"files":files,"file_count":len(files),
                    "private_config_included":False,"sql_data_included":False}
        disposition = {"install_dir":str(install),"installation_id":"synthetic-one",
                       "operator":"fixture","schedule_inspection_reference":"synthetic-no-schedules",
                       "retired_schedule_state":"none"}
        # These private/unrelated files are never inspected or changed.
        untouched = {"baseInfo.php":b"synthetic-private", "customer-data.bin":b"synthetic-data"}
        for name,raw in untouched.items(): (install/name).write_bytes(raw)
        for name,variants in MANIFEST["retired_files"].items():
            path = install/name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(subprocess.check_output(["git","show",variants[0]["source"]+":"+name],cwd=ROOT))
        report = retired.audit(install,metadata,disposition)
        assert report["complete"] and report["files_deleted"] == 0
        assert all(r["status"] == "known-retired-review-removal" for r in report["files"])
        passed.append("known_exact_retired_paths_read_only")
        unknown = install/"settings/tronChecker.php"
        unknown.write_bytes(b"unknown code must survive")
        report = retired.audit(install,metadata,disposition)
        assert not report["complete"] and unknown.read_bytes() == b"unknown code must survive"
        passed.append("unknown_hash_refused_and_preserved")
        unknown.unlink()
        unknown.symlink_to(install/"baseInfo.php")
        assert not retired.audit(install,metadata,disposition)["complete"]
        unknown.unlink()
        assert next(r for r in retired.audit(install,metadata,disposition)["files"]
                    if r["file"] == "settings/tronChecker.php")["status"] == "absent"
        passed.append("symlink_refused_absent_distinguished")
        for override in ({"install_dir":"/unrelated"},{"retired_schedule_state":"unknown"},
                         {"schedule_inspection_reference":""},{"installation_id":""}):
            try: retired.audit(install,metadata,dict(disposition,**override))
            except ValueError: pass
            else: raise AssertionError("Unidentified installation/schedules accepted")
        passed.append("installation_and_schedule_identity_required")
        for name,raw in untouched.items(): assert (install/name).read_bytes() == raw
        assert all((install/name).is_file() for name in files)
        passed.append("current_private_data_and_unrelated_files_unchanged")
    return {"complete":True,"checks":passed,"count":len(passed),
            "live_database_touched":False,"live_files_touched":False,"cron_changes":False}

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--run-disposable", action="store_true", required=True)
    parser.add_argument("--report", type=Path)
    args = parser.parse_args()
    result = run()
    if args.report: args.report.write_text(json.dumps(result,indent=2)+"\n")
    print(json.dumps(result))

if __name__ == "__main__":
    main()
