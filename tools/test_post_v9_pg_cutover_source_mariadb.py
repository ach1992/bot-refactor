#!/usr/bin/env python3
"""Current source-export SQL against disposable socket-only MariaDB (never prod)."""
from __future__ import annotations
import json
import subprocess
import sys
from pathlib import Path

import test_access_state as owned
import test_v6_native as native
import post_v9_pg_cutover_local_export as source

DB = "achbot_pgcutover_fixture"
DDL = """
CREATE TABLE server_info(id INT PRIMARY KEY);
CREATE TABLE server_config(id INT PRIMARY KEY,type VARCHAR(40));
CREATE TABLE server_plans(id INT PRIMARY KEY,server_id INT,inbound_id INT);
CREATE TABLE orders_list(id INT PRIMARY KEY,server_id INT,fileid INT,inbound_id INT,
  remark VARCHAR(100),userid BIGINT,provider_effect_id BIGINT NULL,
  provider_remote_user_id BIGINT NULL,autoRenew VARCHAR(10),amount INT);
CREATE TABLE pays(id INT PRIMARY KEY,type VARCHAR(100),state VARCHAR(30));
CREATE TABLE service_effects(id INT PRIMARY KEY,state VARCHAR(30),provider_state VARCHAR(30) NULL);
CREATE TABLE service_plan_changes(id INT PRIMARY KEY,state VARCHAR(30));
INSERT INTO server_info VALUES (2),(3);
INSERT INTO server_config VALUES (2,'marzban'),(3,'marzban');
INSERT INTO server_plans VALUES (9,2,0),(10,3,0);
INSERT INTO orders_list VALUES
 (101,2,9,0,'shared_user',1001,NULL,NULL,'on',50000),
 (102,2,9,0,'shared_user',1002,NULL,NULL,'off',40000),
 (103,3,0,0,'planless_user',1003,NULL,NULL,'off',0);
INSERT INTO pays VALUES (1,'RENEW_ACCOUNT','pending'),
 (2,'INCREASE_WALLET','pending'),(3,'BUY_SUB','auto_cart_approved'),
 (4,'INCREASE_VOLUME_101_2','have_sent');
INSERT INTO service_effects VALUES (1,'local_recorded',NULL),
 (2,'dispatching',NULL),(3,'consumed','mutating');
INSERT INTO service_plan_changes VALUES (1,'committed'),(2,'review');
"""


def run():
    with owned.owned_database() as (work, env):
        native.sql(work, env, "CREATE DATABASE " + DB)
        native.sql(work, env, DDL, DB)
        cli = ["mariadb", "--no-defaults", "--protocol=SOCKET",
               "--socket=" + str(work / "mysql.sock"), "-uroot",
               "--batch", "--raw", "--skip-column-names",
               "--database=" + DB, "--execute=" + source.snapshot_sql()]
        p = subprocess.run(cli, env=env, capture_output=True, text=True, timeout=20, check=True)
        parsed = source.parse_rows(p.stdout, "hell")
        assert len(parsed["servers"]) == 2
        assert len(parsed["plans"]) == 2
        assert len(parsed["orders"]) == 3
        assert [r["username"] for r in parsed["orders"]] == [
            "shared_user", "shared_user", "planless_user"]
        assert parsed["orders"][0]["provider_effect_id"] is None
        assert parsed["claims"] == {
            "unsettled_service_payments": 2,
            "uncertain_provider_effects": 2,
            "uncommitted_plan_changes": 1,
        }, parsed["claims"]
        # Financial, local account and journal rows remain exactly unchanged
        # after the SELECT-only snapshot.
        assert native.sql(work, env, "SELECT COUNT(*) FROM orders_list", DB).strip() == "3"
        assert native.sql(work, env, "SELECT COUNT(*) FROM pays", DB).strip() == "4"
        assert native.sql(work, env, "SELECT COUNT(*) FROM service_effects", DB).strip() == "3"
        assert native.sql(work, env, "SELECT COUNT(*) FROM service_plan_changes", DB).strip() == "2"
    print(json.dumps({"complete": True, "owned_mariadb": True,
                      "real_snapshot_sql": True, "readonly": True,
                      "source_rows": 3, "financial_attention": 2}))


if __name__ == "__main__":
    run()
