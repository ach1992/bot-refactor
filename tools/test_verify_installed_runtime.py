#!/usr/bin/env python3
"""Read-only installed-source verification negative controls (standard library)."""
from __future__ import annotations

import hashlib
import json
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

from tools.verify_installed_runtime import verify_install


def sha(data):
    return hashlib.sha256(data).hexdigest()


class InstalledSourceVerifierTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.base = Path(self.tmp.name)
        self.root = self.base / "active"
        self.root.mkdir()
        self.config = b"<?php /* expected group UI */\n"
        self.bot = b"<?php /* expected route */\n"
        (self.root / "config.php").write_bytes(self.config)
        (self.root / "bot.php").write_bytes(self.bot)
        (self.root / "baseInfo.php").write_text("SECRET_MARKER_NEVER_READ", encoding="utf-8")
        self.meta = {
            "candidate": "a" * 40,
            "tree": "b" * 40,
            "file_count": 2,
            "private_config_included": False,
            "sql_data_included": False,
            "files": {
                "bot.php": {"size": len(self.bot), "sha256": sha(self.bot)},
                "config.php": {"size": len(self.config), "sha256": sha(self.config)},
            },
        }

    def test_matches_and_ignores_private_configuration(self):
        report = verify_install(self.root, self.meta, "a" * 40)
        self.assertTrue(report["verified"])
        self.assertTrue(report["core_files_match"])
        self.assertFalse(report["private_config_read"])
        self.assertFalse(report["active_webhook_verified"])
        self.assertFalse(report["web_sapi_opcache_verified"])
        self.assertEqual(report["matched"], 2)

    def test_stale_same_size_detected(self):
        changed = b"X" + self.config[1:]
        self.assertEqual(len(changed), len(self.config))
        (self.root / "config.php").write_bytes(changed)
        report = verify_install(self.root, self.meta)
        self.assertEqual(report["status"], "MISMATCH")
        self.assertFalse(report["core_files_match"])
        self.assertEqual(report["differences"][0]["file"], "config.php")
        self.assertEqual(report["differences"][0]["reason"], "sha256_mismatch")

    def test_missing_detected(self):
        (self.root / "bot.php").unlink()
        report = verify_install(self.root, self.meta)
        self.assertEqual(report["differences"][0]["reason"], "missing_or_not_regular_file")

    def test_manifest_commit_guard(self):
        with self.assertRaisesRegex(ValueError, "requested release"):
            verify_install(self.root, self.meta, "c" * 40)

    def test_reject_private_and_traversal_before_file_read(self):
        for name in ("baseInfo.php", "../baseInfo.php", "settings/../../baseInfo.php", ".env", "src/.secret"):
            m = json.loads(json.dumps(self.meta))
            m["files"][name] = {"size": 5, "sha256": sha(b"hello")}
            m["file_count"] += 1
            with self.subTest(name=name):
                with self.assertRaises(ValueError):
                    verify_install(self.root, m)

    def test_symlink_not_followed(self):
        (self.root / "config.php").unlink()
        (self.root / "config.php").symlink_to(self.root / "baseInfo.php")
        report = verify_install(self.root, self.meta)
        self.assertEqual(report["differences"][0]["reason"], "symlink_rejected")

    def test_reject_manifest_missing_source(self):
        m = dict(self.meta)
        m["candidate"] = "not-a-commit"
        with self.assertRaisesRegex(ValueError, "source commit"):
            verify_install(self.root, m)

    def test_cli_exit_status_and_no_private_output(self):
        manifest = self.base / "manifest.json"
        manifest.write_text(json.dumps(self.meta), encoding="utf-8")
        tool = Path(__file__).with_name("verify_installed_runtime.py")
        cmd = [sys.executable, str(tool), "--install-dir", str(self.root),
               "--manifest", str(manifest), "--expect-commit", "a" * 40, "--json"]
        success = subprocess.run(cmd, capture_output=True, text=True, check=False)
        self.assertEqual(success.returncode, 0, success.stderr)
        self.assertTrue(json.loads(success.stdout)["verified"])
        self.assertNotIn("SECRET_MARKER", success.stdout + success.stderr)
        (self.root / "config.php").write_bytes(b"stale")
        failed = subprocess.run(cmd, capture_output=True, text=True, check=False)
        self.assertEqual(failed.returncode, 1, failed.stderr)
        self.assertFalse(json.loads(failed.stdout)["verified"])
        self.assertNotIn("SECRET_MARKER", failed.stdout + failed.stderr)


if __name__ == "__main__":
    unittest.main()
