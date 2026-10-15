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
from unittest.mock import patch

if not __package__:
    sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from tools import verify_installed_runtime as installed
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
               "--manifest", str(manifest), "--expect-commit", "a" * 40,
               "--trusted-manifest-sha256", sha(manifest.read_bytes()), "--json"]
        success = subprocess.run(cmd, capture_output=True, text=True, check=False)
        self.assertEqual(success.returncode, 0, success.stderr)
        self.assertTrue(json.loads(success.stdout)["verified"])
        self.assertTrue(json.loads(success.stdout)["trusted_manifest_sha256_verified"])
        self.assertNotIn("SECRET_MARKER", success.stdout + success.stderr)
        (self.root / "config.php").write_bytes(b"stale")
        failed = subprocess.run(cmd, capture_output=True, text=True, check=False)
        self.assertEqual(failed.returncode, 1, failed.stderr)
        self.assertFalse(json.loads(failed.stdout)["verified"])
        self.assertNotIn("SECRET_MARKER", failed.stdout + failed.stderr)



    def test_tampered_manifest_cannot_claim_exact_release_identity(self):
        original = json.dumps(self.meta, sort_keys=True).encode("utf-8")
        trusted = sha(original)
        self.assertEqual(installed.authenticate_manifest(original, trusted, "a" * 40), self.meta)
        forged_file = b"<?php /* changed but advertised as release */\n"
        (self.root / "config.php").write_bytes(forged_file)
        forged = json.loads(original)
        forged["files"]["config.php"] = {"size": len(forged_file), "sha256": sha(forged_file)}
        self.assertEqual(forged["candidate"], self.meta["candidate"])
        self.assertTrue(verify_install(self.root, forged)["verified"])  # Only supplied-manifest equality.
        tampered = json.dumps(forged, sort_keys=True).encode("utf-8")
        with self.assertRaisesRegex(ValueError, "independent trusted digest"):
            installed.authenticate_manifest(tampered, trusted, "a" * 40)

        manifest = self.base / "forged.json"
        manifest.write_bytes(tampered)
        cmd = [sys.executable, str(Path(installed.__file__)), "--install-dir", str(self.root),
               "--manifest", str(manifest), "--expect-commit", "a" * 40,
               "--trusted-manifest-sha256", trusted, "--json"]
        rejected = subprocess.run(cmd, capture_output=True, text=True, check=False)
        self.assertEqual(rejected.returncode, 2, rejected.stderr)
        self.assertNotIn("SECRET_MARKER", rejected.stdout + rejected.stderr)

    def test_cli_requires_independent_manifest_digest(self):
        manifest = self.base / "manifest.json"
        manifest.write_text(json.dumps(self.meta), encoding="utf-8")
        cmd = [sys.executable, str(Path(installed.__file__)), "--install-dir", str(self.root),
               "--manifest", str(manifest), "--expect-commit", "a" * 40]
        rejected = subprocess.run(cmd, capture_output=True, text=True, check=False)
        self.assertEqual(rejected.returncode, 2)
        self.assertNotIn("SECRET_MARKER", rejected.stdout + rejected.stderr)

    def test_install_root_symlink_rejected(self):
        link = self.base / "linked-active"
        link.symlink_to(self.root, target_is_directory=True)
        with self.assertRaisesRegex(ValueError, "real directory"):
            verify_install(link, self.meta)

    def test_intermediate_static_symlink_rejected(self):
        outside = self.base / "private"
        outside.mkdir()
        (outside / "nested.php").write_text("SECRET_MARKER_NEVER_READ")
        (self.root / "settings").symlink_to(outside, target_is_directory=True)
        self.meta["files"]["settings/nested.php"] = {"size": 1, "sha256": sha(b"X")}
        self.meta["file_count"] += 1
        report = verify_install(self.root, self.meta)
        self.assertFalse(report["verified"])
        self.assertEqual(report["differences"][-1]["reason"], "symlink_rejected")

    def test_final_file_swapped_to_symlink_between_stat_and_open(self):
        real_stat = installed._stat_entry
        changed = False

        def swap_after_stat(fd, name):
            nonlocal changed
            result = real_stat(fd, name)
            if name == "config.php" and not changed:
                changed = True
                (self.root / "config.php").unlink()
                (self.root / "config.php").symlink_to(self.root / "baseInfo.php")
            return result

        with patch.object(installed, "_stat_entry", side_effect=swap_after_stat):
            report = verify_install(self.root, self.meta)
        self.assertTrue(changed)
        self.assertFalse(report["verified"])
        self.assertEqual(report["differences"][0]["reason"], "unstable_path")
        self.assertNotIn("SECRET_MARKER", json.dumps(report))

    def test_parent_directory_swapped_to_symlink_between_stat_and_open(self):
        directory = self.root / "settings"
        directory.mkdir()
        (directory / "nested.php").write_bytes(b"safe")
        self.meta["files"]["settings/nested.php"] = {"size": 4, "sha256": sha(b"safe")}
        self.meta["file_count"] += 1
        outside = self.base / "outside"
        outside.mkdir()
        (outside / "nested.php").write_text("SECRET_MARKER_NEVER_READ")
        real_stat = installed._stat_entry
        changed = False

        def swap_parent_after_stat(fd, name):
            nonlocal changed
            result = real_stat(fd, name)
            if name == "settings" and not changed:
                changed = True
                directory.rename(self.root / "settings-old")
                directory.symlink_to(outside, target_is_directory=True)
            return result

        with patch.object(installed, "_stat_entry", side_effect=swap_parent_after_stat):
            report = verify_install(self.root, self.meta)
        self.assertTrue(changed)
        self.assertFalse(report["verified"])
        self.assertEqual(report["differences"][-1]["reason"], "unstable_path")
        self.assertNotIn("SECRET_MARKER", json.dumps(report))

    def test_final_path_swapped_after_descriptor_open(self):
        real_read = installed.os.read
        tracked_fd = None
        swapped = False

        def swap_while_reading(fd, amount):
            nonlocal swapped
            if fd == tracked_fd and not swapped:
                swapped = True
                (self.root / "config.php").rename(self.root / "original-config.php")
                (self.root / "config.php").symlink_to(self.root / "baseInfo.php")
            return real_read(fd, amount)

        # Only patch reading. The stable fd is discovered without replacing os.open.
        original_open_checked = installed._open_checked

        def record_descriptor(fd, name, directory):
            nonlocal tracked_fd
            descriptor, snapshot = original_open_checked(fd, name, directory)
            if name == "config.php":
                tracked_fd = descriptor
            return descriptor, snapshot

        with patch.object(installed, "_open_checked", side_effect=record_descriptor), \
             patch.object(installed.os, "read", side_effect=swap_while_reading):
            report = verify_install(self.root, self.meta)
        self.assertTrue(swapped)
        self.assertFalse(report["verified"])
        self.assertIn(report["differences"][0]["reason"], ("unstable_path", "unstable_file"))
        self.assertNotIn("SECRET_MARKER", json.dumps(report))

if __name__ == "__main__":
    unittest.main()
