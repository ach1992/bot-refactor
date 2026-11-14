#!/usr/bin/env python3
"""Deterministic negative and positive proof for a future offline bot cutover."""
from __future__ import annotations
import copy
import json
import os
from pathlib import Path
import stat
import subprocess
import sys
import tempfile
import unittest

import post_v9_pg_cutover_preflight as tool

ROOT = Path(__file__).resolve().parents[1]
DT = "2026-11-14T09:00:00.123456Z"
KEY = "a" * 32
ENDPOINT = "b" * 64


def fixture(tenant="hell"):
    local = {
        "version": 1,
        "tenant": tenant,
        "servers": [
            {"id": 2, "provider": "marzban"},
            {"id": 3, "provider": "marzban"},
        ],
        "plans": [
            {"id": 9, "server_id": 2, "inbound_id": 0},
            {"id": 10, "server_id": 3, "inbound_id": 0},
        ],
        "orders": [
            {"id": 101, "server_id": 2, "plan_id": 9, "inbound_id": 0,
             "username": "shared_user", "owner_id": 1001, "provider_effect_id": None,
             "provider_remote_user_id": None, "auto_renew": "on", "amount": 50000},
            {"id": 102, "server_id": 2, "plan_id": 9, "inbound_id": 0,
             "username": "shared_user", "owner_id": 1002, "provider_effect_id": None,
             "provider_remote_user_id": None, "auto_renew": "off", "amount": 40000},
            {"id": 103, "server_id": 3, "plan_id": 0, "inbound_id": 0,
             "username": "planless_user", "owner_id": 1003, "provider_effect_id": None,
             "provider_remote_user_id": None, "auto_renew": "off", "amount": 0},
        ],
        "claims": {
            "unsettled_service_payments": 0,
            "uncertain_provider_effects": 0,
            "uncommitted_plan_changes": 0,
        },
    }
    native = {
        "version": 1,
        "tenant": tenant,
        "panels": [
            {"key": "main", "id": 71, "instance_key": KEY,
             "endpoint_sha256": ENDPOINT, "available_groups": [7, 8]},
        ],
        "accounts": [
            {"panel_key": "main", "id": 701, "username": "shared_user",
             "created_at": DT, "group_ids": [7], "data_limit": 0,
             "used_traffic": 1_999_999, "expire": None, "status": "active",
             "native_schedules_clear": True},
            {"panel_key": "main", "id": 702, "username": "planless_user",
             "created_at": DT, "group_ids": [], "data_limit": 100_000_000,
             "used_traffic": 50_000_000, "expire": 2000000000, "status": "limited",
             "native_schedules_clear": True},
        ],
    }
    mapping = {
        "version": 1,
        "tenant": tenant,
        "server_panels": {"2": "main", "3": "main"},
        "plan_groups": {"9": [7], "10": [8]},
    }
    return local, native, mapping


class Reconciliation(unittest.TestCase):
    def test_positive_hell_shared_and_planless(self):
        local, native, mapping = fixture()
        report, result = tool.reconcile(local, native, mapping)
        self.assertTrue(report["complete"], report)
        self.assertEqual(report["statistics"]["local_order_references"], 3)
        self.assertEqual(report["statistics"]["shared_reference_excess"], 1)
        self.assertEqual(report["statistics"]["unique_source_accounts"], 2)
        self.assertEqual(report["statistics"]["planless_orders"], 1)
        self.assertEqual(report["statistics"]["auto_renew_on"], 1)
        self.assertEqual(len(result), 3)
        self.assertEqual([r["remote_user_id"] for r in result], [701, 701, 702])
        self.assertEqual(result[2]["planned_groups"], None)
        self.assertNotIn("username", result[0])
        self.assertNotIn("amount", result[0])

    def test_positive_vivan_no_planless(self):
        local, native, mapping = fixture("vivan")
        local["orders"] = local["orders"][:1]
        report, result = tool.reconcile(local, native, mapping)
        self.assertTrue(report["complete"])
        self.assertEqual(report["statistics"]["native_accounts_unrelated"], 1)
        self.assertEqual(len(result), 1)

    def test_missing_account_blocks_and_map_incomplete(self):
        local, native, mapping = fixture()
        native["accounts"].pop()
        report, result = tool.reconcile(local, native, mapping)
        self.assertFalse(report["complete"])
        self.assertEqual(report["blocker_counts"], {"native_account_missing": 1})
        self.assertEqual(len(result), 2)

    def test_incorrect_native_group_blocks_without_edit(self):
        local, native, mapping = fixture()
        native["accounts"][0]["group_ids"] = [8]
        report, _ = tool.reconcile(local, native, mapping)
        self.assertEqual(report["blocker_counts"], {"native_group_mismatch": 1})

    def test_active_native_schedule_conflict_blocks(self):
        local, native, mapping = fixture()
        native["accounts"][0]["native_schedules_clear"] = False
        report, _ = tool.reconcile(local, native, mapping)
        self.assertEqual(report["blocker_counts"], {"native_scheduling_conflict": 1})

    def test_shared_subscriber_different_plan_groups_blocks(self):
        local, native, mapping = fixture()
        local["orders"][1]["plan_id"] = 10
        local["orders"][1]["server_id"] = 2
        local["plans"][1]["server_id"] = 2
        report, _ = tool.reconcile(local, native, mapping)
        self.assertEqual(report["blocker_counts"], {"shared_account_plan_conflict": 1})

    def test_duplicate_native_id_denied(self):
        local, native, mapping = fixture()
        duplicate = copy.deepcopy(native["accounts"][0])
        duplicate["username"] = "different"
        native["accounts"].append(duplicate)
        with self.assertRaisesRegex(ValueError, "native_duplicate_identity"):
            tool.reconcile(local, native, mapping)

    def test_cross_server_identity_collision(self):
        local, native, mapping = fixture()
        local["orders"][2]["username"] = "shared_user"
        report, _ = tool.reconcile(local, native, mapping)
        self.assertEqual(report["blocker_counts"], {"cross_server_identity_collision": 1})

    def test_plan_missing_blocks(self):
        local, native, mapping = fixture()
        local["orders"][0]["plan_id"] = 777
        report, _ = tool.reconcile(local, native, mapping)
        self.assertEqual(report["blocker_counts"], {"order_plan_mismatch": 1})

    def test_inbound_nonzero_denied(self):
        local, native, mapping = fixture()
        local["orders"][0]["inbound_id"] = 9
        with self.assertRaisesRegex(ValueError, "source_order_invalid"):
            tool.reconcile(local, native, mapping)

    def test_preexisting_provider_anchor_denied(self):
        local, native, mapping = fixture()
        local["orders"][0]["provider_effect_id"] = 3
        with self.assertRaisesRegex(ValueError, "source_order_invalid"):
            tool.reconcile(local, native, mapping)

    def test_missing_group_or_alias_denied(self):
        local, native, mapping = fixture()
        mapping["plan_groups"]["9"] = [111]
        with self.assertRaisesRegex(ValueError, "mapped_group_unavailable"):
            tool.reconcile(local, native, mapping)
        mapping["plan_groups"]["9"] = [7]
        del mapping["server_panels"]["2"]
        with self.assertRaisesRegex(ValueError, "mapping_incomplete"):
            tool.reconcile(local, native, mapping)

    def test_unfinished_invoice_is_attention_not_global_identity_block(self):
        local, native, mapping = fixture()
        local["claims"]["unsettled_service_payments"] = 554
        report, private_map = tool.reconcile(local, native, mapping)
        self.assertTrue(report["complete"])
        self.assertTrue(report["financial_review_required"])
        self.assertEqual(report["blocker_counts"], {})
        self.assertEqual(report["unresolved_claim_counts"]["unsettled_service_payments"], 554)
        self.assertFalse(report["ready_for_production_cutover"])
        self.assertEqual(len(private_map), 3)

    def test_unfinished_native_effect_or_plan_journal_blocks_mapping(self):
        local, native, mapping = fixture()
        for claim_type in ("uncertain_provider_effects", "uncommitted_plan_changes"):
            copy_local = copy.deepcopy(local)
            copy_local["claims"][claim_type] = 3
            report, _ = tool.reconcile(copy_local, native, mapping)
            self.assertEqual(report["blocker_counts"], {"unresolved_" + claim_type: 1})
            self.assertEqual(report["unresolved_claim_counts"][claim_type], 3)
            self.assertFalse(report["complete"])

    def test_claims_missing_denied(self):
        local, native, mapping = fixture()
        del local["claims"]
        with self.assertRaisesRegex(ValueError, "claim_evidence_missing"):
            tool.reconcile(local, native, mapping)

    def test_invalid_status_and_time_denied(self):
        local, native, mapping = fixture()
        native["accounts"][1]["created_at"] = "2026-02-30T09:00:00.123456Z"
        with self.assertRaisesRegex(ValueError, "native_account_invalid"):
            tool.reconcile(local, native, mapping)
        native["accounts"][1]["created_at"] = DT
        native["accounts"][1]["status"] = "on_hold"
        with self.assertRaisesRegex(ValueError, "native_account_invalid"):
            tool.reconcile(local, native, mapping)

    def test_unlimited_quota_and_expiry_are_literal(self):
        local, native, mapping = fixture()
        report, _ = tool.reconcile(local, native, mapping)
        self.assertTrue(report["complete"])
        self.assertEqual(native["accounts"][0]["data_limit"], 0)
        self.assertIsNone(native["accounts"][0]["expire"])

    def test_secret_json_key_denied_and_duplicate_keys(self):
        with tempfile.TemporaryDirectory(prefix="pgcutover-fixture-") as directory:
            path = Path(directory) / "unsafe.json"
            path.write_text('{"version":1,"api_key":"DO_NOT_ECHO"}')
            with self.assertRaisesRegex(ValueError, "secret_field_forbidden"):
                tool.load(path)
            path.write_text('{"version":1,"version":1}')
            with self.assertRaisesRegex(ValueError, "duplicate_json_key"):
                tool.load(path)

    def test_private_map_sealed_and_preexisting_not_overwritten(self):
        with tempfile.TemporaryDirectory(prefix="pgcutover-fixture-") as directory:
            local, native, mapping = fixture()
            write = lambda name, data: (Path(directory) / name)
            paths = [write(name, data) for name, data in (("local.json", local),
                    ("native.json", native), ("mapping.json", mapping))]
            for path, payload in zip(paths, (local, native, mapping)):
                path.write_text(json.dumps(payload))
            output = write("private-plan.json", None)
            cmd = [sys.executable, str(ROOT / "tools/post_v9_pg_cutover_preflight.py"),
                   "--local", str(paths[0]), "--native", str(paths[1]),
                   "--mapping", str(paths[2]), "--private-map", str(output)]
            p = subprocess.run(cmd, capture_output=True, text=True, check=True, timeout=15)
            result = json.loads(p.stdout)
            self.assertTrue(result["complete"])
            self.assertEqual(result["private_map_size"], 3)
            self.assertEqual(stat.S_IMODE(os.stat(output).st_mode), 0o600)
            self.assertEqual(len(json.loads(output.read_text())["references"]), 3)
            p = subprocess.run(cmd, capture_output=True, text=True, timeout=15)
            self.assertEqual(p.returncode, 2)
            self.assertEqual(json.loads(p.stdout)["error_code"], "invalid_input_or_io")
            self.assertEqual(len(json.loads(output.read_text())["references"]), 3)
            self.assertNotIn("shared_user", p.stdout)

    def test_source_export_parser_single_snapshot_and_rejection(self):
        import post_v9_pg_cutover_local_export as source
        sql = source.snapshot_sql()
        self.assertIn("START TRANSACTION READ ONLY", sql)
        self.assertIn("ROLLBACK;", sql)
        self.assertNotIn("UPDATE ", sql)
        self.assertNotIn("INSERT ", sql)
        self.assertNotIn("DELETE ", sql)
        data = ("PAGER set to stdout\n"
                "S|{\"id\":2,\"provider\":\"marzban\"}\n"
                "P|{\"id\":9,\"server_id\":2,\"inbound_id\":0}\n"
                "O|{\"id\":101,\"server_id\":2}\n"
                "C|{\"unsettled_service_payments\":0,\"uncertain_provider_effects\":0,\"uncommitted_plan_changes\":0}\n")
        result = source.parse_rows(data, "vivan")
        self.assertEqual(result["tenant"], "vivan")
        self.assertEqual(result["servers"][0]["id"], 2)
        self.assertEqual(result["orders"][0]["id"], 101)
        with self.assertRaisesRegex(ValueError, "duplicate_claims"):
            source.parse_rows(data + data.splitlines()[-1] + "\n", "hell")
        with self.assertRaisesRegex(ValueError, "unexpected_client_output"):
            source.parse_rows("SECRET_IN_OUTPUT\n" + data, "hell")
        with self.assertRaisesRegex(ValueError, "incomplete_database_snapshot"):
            source.parse_rows(data.replace(data.splitlines()[-1] + "\n", ""), "hell")

    def test_strict_manifest_version_type(self):
        with tempfile.TemporaryDirectory(prefix="pgcutover-fixture-") as directory:
            path = Path(directory) / "version.json"
            path.write_text('{"version":true}')
            with self.assertRaisesRegex(ValueError, "input_version_invalid"):
                tool.load(path)

    def test_cli_failed_matching_does_not_create_private_map(self):
        with tempfile.TemporaryDirectory(prefix="pgcutover-fixture-") as directory:
            local, native, mapping = fixture()
            native["accounts"][0]["group_ids"] = [8]
            paths = [Path(directory) / name for name in ("l", "n", "m")]
            for path, payload in zip(paths, (local, native, mapping)):
                path.write_text(json.dumps(payload))
            output = Path(directory) / "map.json"
            p = subprocess.run([sys.executable, str(ROOT / "tools/post_v9_pg_cutover_preflight.py"),
                   "--local", str(paths[0]), "--native", str(paths[1]),
                   "--mapping", str(paths[2]), "--private-map", str(output)],
                   capture_output=True, text=True, timeout=15)
            self.assertEqual(p.returncode, 2)
            self.assertFalse(output.exists())
            self.assertEqual(json.loads(p.stdout)["blocker_counts"], {"native_group_mismatch": 1})


if __name__ == "__main__":
    unittest.main(verbosity=1)
