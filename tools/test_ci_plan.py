#!/usr/bin/env python3
"""Conservative impact-selection regression with disposable Git commits only.

No PHP version, provider, Telegram, MariaDB or protected history executes.
"""
from __future__ import annotations

import contextlib
import io
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

import ci_plan


CRON_BASE = (
    b"<?php\n"
    b"// fixed coordinator entry\n"
    b"$installDirectory = realpath(dirname(__DIR__));\n"
    b"// private lock directory preparation baseline\n"
    b"$lockDirectory = '/tmp/achbot-fixture-locks';\n"
    b"$lockFile = $lockDirectory . DIRECTORY_SEPARATOR . 'achbot-cron-'\n"
    b"// fixed worker launch and finance routines\n"
)


class ImpactPlanTests(unittest.TestCase):
    def setUp(self):
        self.private = tempfile.TemporaryDirectory(prefix="achbot-ci-plan-fixture-")
        self.addCleanup(self.private.cleanup)
        self.repo = Path(self.private.name) / "repo"
        self.repo.mkdir()
        self.git("init", "-q", "-b", "main")
        self.git("config", "user.email", "fixture@example.invalid")
        self.git("config", "user.name", "Disposable CI fixture")
        self.set_file(ci_plan.CRON, CRON_BASE)
        self.set_file(ci_plan.CRON_TEST, "print('standins only')\n")
        self.set_file("README.md", "Initial guide\n")
        self.set_file("docs/post-v9/FRESH-INSTALL.md", "Installation guide\n")
        self.set_file("tools/test_other.py", "print('domain tests')\n")
        self.set_file("src/common.php", "<?php // shared runtime\n")
        self.set_file("AGENTS.md", "project rules\n")
        self.set_file(".github/workflows/v0.yml", "name: Compatibility\n")
        self.base = self.commit("baseline fixture")

    def git(self, *args):
        result = subprocess.run(["git", "-C", str(self.repo), *args],
                                check=True, capture_output=True, text=True,
                                env={**os.environ, "GIT_TERMINAL_PROMPT": "0"})
        return result.stdout.strip()

    def set_file(self, path, content):
        target = self.repo / path
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(content if isinstance(content, bytes) else content.encode())

    def commit(self, message):
        self.git("add", "--all")
        self.git("commit", "-qm", message)
        return self.git("rev-parse", "HEAD")

    def decide(self, path, data, *, force_full=False):
        self.set_file(path, data)
        head = self.commit("candidate")
        return ci_plan.plan(self.repo, self.base, head, force_full=force_full)

    def test_docs_only_are_source_not_full(self):
        result = self.decide("README.md", "Updated guide\n")
        self.assertEqual(result["scope"], "source")
        self.assertEqual(result["shards"], [])
        self.assertEqual(result["changed_paths"], ["README.md"])
        self.assertEqual(result["head"], self.git("rev-parse", "HEAD"))

    def test_doc_under_post_v9_is_source(self):
        result = self.decide("docs/post-v9/FRESH-INSTALL.md", "Revised guide\n")
        self.assertEqual(result["scope"], "source")

    def test_cron_preparation_only_selects_cron(self):
        updated = CRON_BASE.replace(
            b"// private lock directory preparation baseline\n",
            b"// private mode 0700 creation, no existing inode replacement\n"
        )
        result = self.decide(ci_plan.CRON, updated)
        self.assertEqual(result["scope"], "cron")
        self.assertEqual(result["reason"], "cron_preparation_or_dedicated_test")
        self.assertEqual(result["shards"], [])

    def test_dedicated_cron_test_only_still_executes_cron(self):
        result = self.decide(ci_plan.CRON_TEST, "print('all 95 standins')\n")
        self.assertEqual(result["scope"], "cron")

    def test_worker_body_change_same_file_is_full(self):
        changed = CRON_BASE.replace(b"// fixed worker launch", b"// modified worker launch")
        result = self.decide(ci_plan.CRON, changed)
        self.assertEqual(result["scope"], "full")
        self.assertEqual(result["reason"], "cron_outside_preparation_changed_or_ambiguous")
        self.assertEqual(result["shards"], list(range(1, 9)))

    def test_shared_helpers_and_unknown_tests_are_full(self):
        for path, data in (
            ("src/common.php", "<?php // changed shared helper\n"),
            ("tools/test_other.py", "print('changed domain tests')\n"),
            ("AGENTS.md", "policy change\n"),
            (".github/workflows/v0.yml", "name: Revised\n"),
        ):
            with self.subTest(path=path):
                self.set_file(path, data)
                head = self.commit("changed " + path)
                self.assertEqual(ci_plan.plan(self.repo, self.base, head)["scope"], "full")

    def test_policy_document_is_not_source_only(self):
        result = self.decide("docs/post-v9/CI-VALIDATION.md", "new validation policy\n")
        self.assertEqual(result["scope"], "full")

    def test_missing_invalid_and_nonancestor_base_are_full(self):
        head = self.decide("README.md", "Some docs\n")["head"]
        self.assertEqual(ci_plan.plan(self.repo, None, head)["scope"], "full")
        self.assertEqual(ci_plan.plan(self.repo, "0" * 40, head)["scope"], "full")
        self.assertEqual(ci_plan.plan(self.repo, head, head)["scope"], "full")
        self.assertEqual(ci_plan.plan(self.repo, self.base, head,
                                     force_full=True)["reason"], "forced_full")

    def test_dirty_checkout_cannot_be_selected(self):
        head = self.decide("README.md", "docs\n")["head"]
        self.set_file("README.md", "local uncommitted edits\n")
        result = ci_plan.plan(self.repo, self.base, head)
        self.assertEqual(result["scope"], "full")
        self.assertEqual(result["reason"], "dirty_checkout")

    def test_wrong_checkout_head_fails_instead_of_testing_other_commit(self):
        self.decide("README.md", "docs\n")
        with self.assertRaises(ci_plan.PlanError):
            ci_plan.plan(self.repo, self.base, self.base)
        with self.assertRaises(ci_plan.PlanError):
            ci_plan.plan(self.repo, self.base, "not-a-sha")

    def test_cron_symlink_is_not_treated_as_safe_preparation(self):
        target = self.repo / ci_plan.CRON
        target.unlink()
        target.symlink_to("external-coordinator.php")
        head = self.commit("symlink in place of runtime")
        self.assertEqual(ci_plan.plan(self.repo, self.base, head)["scope"], "full")

    def test_missing_preparation_anchor_is_full(self):
        updated = CRON_BASE.replace(ci_plan.PREPARATION_END, b"// lost lock boundary\n")
        self.assertEqual(self.decide(ci_plan.CRON, updated)["scope"], "full")

    def test_github_pr_event_uses_exact_checkout_source_sha(self):
        result = self.decide("README.md", "Docs for PR\n")
        event = {
            "repository": {"full_name": "ach1992/bot-refactor"},
            "pull_request": {
                "base": {"sha": self.base},
                "head": {"sha": result["head"]},
            }
        }
        event_path = Path(self.private.name) / "event.json"
        event_path.write_text(json.dumps(event))
        output = Path(self.private.name) / "github-output"
        environ = {
            "GITHUB_EVENT_NAME": "pull_request",
            "GITHUB_EVENT_PATH": str(event_path),
            "GITHUB_REPOSITORY": "ach1992/bot-refactor",
            "GITHUB_OUTPUT": str(output),
        }
        with contextlib.redirect_stdout(io.StringIO()):
            actual = ci_plan.main(["--github-event", "--github-output"], repo=self.repo,
                                  environ=environ)
        self.assertEqual(actual["scope"], "source")
        self.assertEqual(output.read_text().splitlines(), ["scope=source", "shards=[]"])
        event["pull_request"]["head"]["sha"] = self.base
        event_path.write_text(json.dumps(event))
        with self.assertRaises(ci_plan.PlanError):
            ci_plan.main(["--github-event"], repo=self.repo, environ=environ)

    def test_dispatch_cannot_downgrade_to_targeted(self):
        result = self.decide("README.md", "Docs\n")
        event_path = Path(self.private.name) / "event.json"
        event_path.write_text(json.dumps({"repository": {"full_name": "ach1992/bot-refactor"}}))
        environ = {"GITHUB_EVENT_NAME": "workflow_dispatch",
                   "GITHUB_EVENT_PATH": str(event_path), "GITHUB_SHA": result["head"],
                   "GITHUB_REPOSITORY": "ach1992/bot-refactor"}
        with contextlib.redirect_stdout(io.StringIO()):
            self.assertEqual(ci_plan.main(["--github-event"], repo=self.repo,
                                          environ=environ)["scope"], "full")


if __name__ == "__main__":
    unittest.main()
