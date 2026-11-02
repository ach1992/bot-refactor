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
        self.set_file("docs/post-v9/README.md", "Read-only documentation index\n")
        for name in (
            "FRESH-INSTALL.md",
            "CI-VALIDATION.md",
            "FINANCIAL-CONSOLIDATION.md",
            "AUTOMATIC-RENEWAL-SAFETY.md",
            "NEXT-RELEASE.md",
            "PASARGUARD-CONTRACT.md",
            "PAYMENT-READINESS-PREFLIGHT.md",
            "PAYMENT-LEDGER-MIGRATION.md",
            "RC20-TO-RC21.md",
            "GATEWAY-ELIGIBILITY-RULES.md",
        ):
            self.set_file("docs/post-v9/" + name, "Existing operational contract\n")
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

    def test_docs_only_are_lightweight_not_full(self):
        result = self.decide("README.md", "Updated guide\n")
        self.assertEqual(result["scope"], "docs")
        self.assertEqual(result["shards"], [])
        self.assertEqual(result["changed_paths"], ["README.md"])
        self.assertEqual(result["head"], self.git("rev-parse", "HEAD"))

    def test_approved_index_under_post_v9_is_lightweight(self):
        result = self.decide("docs/post-v9/README.md", "Updated informational index\n")
        self.assertEqual(result["scope"], "docs")

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

    def test_existing_financial_safety_release_and_install_docs_are_full(self):
        # These are real repository paths that were incorrectly exempted.
        for name in (
            "FINANCIAL-CONSOLIDATION.md",
            "AUTOMATIC-RENEWAL-SAFETY.md",
            "NEXT-RELEASE.md",
            "PASARGUARD-CONTRACT.md",
            "PAYMENT-READINESS-PREFLIGHT.md",
            "FRESH-INSTALL.md",
            "PAYMENT-LEDGER-MIGRATION.md",
            "RC20-TO-RC21.md",
            "GATEWAY-ELIGIBILITY-RULES.md",
            "CI-VALIDATION.md",
        ):
            with self.subTest(name=name):
                path = "docs/post-v9/" + name
                self.assertFalse(ci_plan.ordinary_doc(path))
                self.set_file(path, "Modified actual operational contract\n")
                head = self.commit("modify " + name)
                result = ci_plan.plan(self.repo, self.base, head)
                self.assertEqual(result["scope"], "full")
                self.assertEqual(result["changed_paths"], [path])
                self.assertEqual(result["shards"], list(range(1, 9)))
                self.set_file(path, "Existing operational contract\n")
                self.commit("restore " + name)

    def test_unlisted_post_v9_markdown_is_full(self):
        self.assertFalse(ci_plan.ordinary_doc("docs/post-v9/NEW-DEPLOYMENT-POLICY.md"))
        self.assertFalse(ci_plan.ordinary_doc("docs/post-v9/unknown.md"))
        self.assertFalse(ci_plan.ordinary_doc("docs/post-v9/subfolder/README.md"))
        result = self.decide("docs/post-v9/unknown.md", "An unknown contract\n")
        self.assertEqual(result["scope"], "full")

    def test_mixed_informational_and_contract_changes_are_full(self):
        self.set_file("README.md", "Updated overview\n")
        self.set_file("docs/post-v9/FINANCIAL-CONSOLIDATION.md",
                      "New financial contract\n")
        head = self.commit("mixed source docs")
        self.assertEqual(ci_plan.plan(self.repo, self.base, head)["scope"], "full")

    def test_single_document_add_or_delete_is_not_lightweight(self):
        self.git("rm", "docs/post-v9/README.md")
        head = self.commit("remove index")
        self.assertEqual(ci_plan.plan(self.repo, self.base, head)["scope"], "full")
        self.assertEqual(ci_plan.plan(self.repo, self.base, head)["reason"],
                         "document_added_or_removed")

    def prepare_doc_rename(self):
        # Both source and destination are positively allowlisted, but the
        # destination does not exist in this disposable base.
        self.git("rm", "docs/post-v9/README.md")
        self.base = self.commit("fixture removes target index")
        self.git("mv", "README.md", "docs/post-v9/README.md")

    def test_changed_content_rename_still_requires_full(self):
        self.prepare_doc_rename()
        content = (self.repo / "docs/post-v9/README.md").read_text()
        self.set_file("docs/post-v9/README.md", content + "An additional sentence\n")
        head = self.commit("move and partly edit readme")
        raw = self.git("diff", "--find-renames", "--name-status",
                       self.base, head)
        self.assertIn("R", raw)
        self.assertEqual(ci_plan.plan(self.repo, self.base, head)["scope"], "full")

    def test_low_similarity_move_is_add_delete_and_requires_full(self):
        self.prepare_doc_rename()
        self.set_file("docs/post-v9/README.md",
                      "Completely different text replacing the original short readme\n"
                      "Unrelated lines with no shared original content\n")
        head = self.commit("rewrite and move readme")
        changed = ci_plan.changes(self.repo, self.base, head)
        self.assertEqual(set(changed), {
            ("D", "README.md"),
            ("A", "docs/post-v9/README.md"),
        })
        result = ci_plan.plan(self.repo, self.base, head)
        self.assertEqual(result["scope"], "full")
        self.assertEqual(result["reason"], "ambiguous_add_delete")

    def test_document_deletion_with_unrelated_modification_is_full(self):
        self.git("rm", "README.md")
        self.set_file("docs/post-v9/README.md", "New index with unrelated text\n")
        head = self.commit("delete one approved doc and update another")
        # This case is D+M, so the independent deletion must still select full.
        self.assertEqual(ci_plan.plan(self.repo, self.base, head)["scope"], "full")
        self.assertEqual(ci_plan.plan(self.repo, self.base, head)["reason"],
                         "document_added_or_removed")

    def test_empty_effective_tree_and_identical_commit_are_no_impact(self):
        self.assertEqual(ci_plan.plan(self.repo, self.base, self.base)["scope"], "none")
        self.git("commit", "--allow-empty", "-qm", "empty source change")
        head = self.git("rev-parse", "HEAD")
        result = ci_plan.plan(self.repo, self.base, head)
        self.assertEqual(result["scope"], "none")
        self.assertEqual(result["reason"], "no_effective_changes")
        self.assertEqual(result["changed_paths"], [])
        self.assertEqual(result["shards"], [])

    def test_renamed_document_requires_full_evidence(self):
        self.git("mv", "README.md", "docs/post-v9/RENAMED.md")
        head = self.commit("rename document")
        self.assertEqual(ci_plan.plan(self.repo, self.base, head)["scope"], "full")

    def test_document_symlink_and_mode_change_are_not_lightweight(self):
        target = self.repo / "README.md"
        target.unlink()
        target.symlink_to("docs/post-v9/FRESH-INSTALL.md")
        head = self.commit("symlink document")
        self.assertEqual(ci_plan.plan(self.repo, self.base, head)["scope"], "full")

    def test_policy_and_security_document_paths_require_full(self):
        for path in ("docs/post-v9/CI.md", "docs/post-v9/SECURITY.md",
                     "docs/post-v9/TESTING.md", "docs/post-v9/flow-policy.md",
                     "docs/post-v9/policies/intro.md", "docs/post-v9/validation/intro.md"):
            with self.subTest(path=path):
                self.set_file(path, "policy text\n")
                head = self.commit("policy " + path)
                self.assertEqual(ci_plan.plan(self.repo, self.base, head)["scope"], "full")

    def test_missing_invalid_and_nonancestor_base_are_full(self):
        head = self.decide("README.md", "Some docs\n")["head"]
        self.assertEqual(ci_plan.plan(self.repo, None, head)["scope"], "full")
        self.assertEqual(ci_plan.plan(self.repo, "0" * 40, head)["scope"], "full")
        self.assertEqual(ci_plan.plan(self.repo, head, head)["scope"], "none")
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
        self.assertEqual(actual["scope"], "docs")
        self.assertEqual(output.read_text().splitlines(), ["scope=docs", "shards=[]"])
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
