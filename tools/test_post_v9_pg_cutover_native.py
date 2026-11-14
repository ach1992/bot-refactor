#!/usr/bin/env python3
"""Real PHP8.5 GET-only PasarGuard cutover export on local fake API."""
from __future__ import annotations
import copy
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

import post_v9_pg_cutover_preflight as check
import test_post_v9_pasarguard_client as pg

ROOT = Path(__file__).resolve().parents[1]
EXPORT = ROOT / "tools/post_v9_pg_cutover_native_export.php"


class NativeExport(unittest.TestCase):
    def run_case(self, mode="ok", username="fixture_user", insecure=False):
        with tempfile.TemporaryDirectory(prefix="pg-native-cutover-") as directory:
            folder = Path(directory)
            local = {
                "version": 1, "tenant": "hell",
                "servers": [{"id": 2, "provider": "marzban"}],
                "plans": [{"id": 9, "server_id": 2, "inbound_id": 0}],
                "orders": [{"id": 101, "server_id": 2, "plan_id": 9,
                    "inbound_id": 0, "username": username,
                    "owner_id": 1001, "provider_effect_id": None,
                    "provider_remote_user_id": None, "auto_renew": "on",
                    "amount": 50000}],
                "claims": {"unsettled_service_payments": 0,
                    "uncertain_provider_effects": 0, "uncommitted_plan_changes": 0},
            }
            mapping = {"version": 1, "tenant": "hell",
                       "server_panels": {"2": "main"},
                       "plan_groups": {"9": [2, 5]}}
            with pg.panel() as (server, url):
                server.mode = mode
                credentials = {"version": 1, "panels": {"main": {
                    "id": 71, "instance_key": "a" * 32, "api_url": url,
                    "api_key": "fixture-private-key", "version": "5.5.1"}}}
                files = [folder / p for p in ("source.json", "mapping.json", "secret.json")]
                for path, payload in zip(files, (local, mapping, credentials)):
                    path.write_text(json.dumps(payload))
                os.chmod(files[2], 0o644 if insecure else 0o600)
                prepend = folder / "prepend.php"
                prepend.write_text("<?php define('ACHBOT_PG_TEST_LOOPBACK',true);")
                output = folder / "native.json"
                cmd = ["php", "-d", "display_errors=0", "-d", "log_errors=0",
                    "-d", "auto_prepend_file=" + str(prepend), str(EXPORT),
                    "--local=" + str(files[0]), "--mapping=" + str(files[1]),
                    "--connections=" + str(files[2]), "--output=" + str(output)]
                run = subprocess.run(cmd, capture_output=True, text=True, timeout=18)
                parsed = json.loads(run.stdout)
                self.assertNotIn("fixture-private-key", run.stdout + run.stderr)
                self.assertNotIn("fixture_user", run.stdout + run.stderr)
                if run.returncode == 0:
                    self.assertTrue(parsed["complete"])
                    self.assertTrue(output.is_file())
                    native = json.loads(output.read_text())
                    self.assertEqual(native["accounts"][0]["username"], "fixture_user")
                    self.assertEqual(native["accounts"][0]["id"], 17)
                    self.assertEqual(native["accounts"][0]["created_at"],
                                     "2026-10-26T05:00:00.123456Z")
                    self.assertEqual(native["accounts"][0]["used_traffic"], 10)
                    self.assertEqual(native["accounts"][0]["data_limit"], 2147483648)
                    self.assertEqual(native["panels"][0]["available_groups"], [2, 5])
                    self.assertNotIn("api_key", output.read_text())
                    report, _ = check.reconcile(local, native, mapping)
                    self.assertEqual(report["blocker_counts"],
                                     {"native_scheduling_conflict": 1} if mode == "scheduled_hold" else {})
                    self.assertEqual(report["complete"], mode != "scheduled_hold")
                    self.assertEqual(output.stat().st_mode & 0o777, 0o600)
                else:
                    self.assertFalse(output.exists())
                    self.assertEqual(run.returncode, 2)
                    self.assertFalse(parsed["complete"])
                self.assertTrue(all(method == "GET" for method, _, _ in server.trace))
                self.assertEqual(server.write_count, 0)
                return parsed

    def test_success_readonly_and_mapping(self):
        self.assertTrue(self.run_case()["complete"])

    def test_native_schedule_conflict_blocked_by_preflight(self):
        self.assertTrue(self.run_case(mode="scheduled_hold")["complete"])

    def test_wrong_version_denied_before_accounts(self):
        self.assertEqual(self.run_case(mode="wrong_version")["error_code"],
                         "panel_readonly_check_failed")

    def test_username_mismatch_denied(self):
        self.assertEqual(self.run_case(username="other_user")["error_code"],
                         "native_username_mismatch")

    def test_insecure_credentials_file_denied_without_network(self):
        self.assertEqual(self.run_case(insecure=True)["error_code"],
                         "credential_file_permissions")


if __name__ == "__main__":
    unittest.main(verbosity=1)
