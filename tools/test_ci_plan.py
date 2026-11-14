#!/usr/bin/env python3
"""Conservative impact-selection regression with disposable Git commits only.

No PHP version, provider, Telegram, MariaDB or protected history executes.
"""
from __future__ import annotations

import ast
import contextlib
import io
from collections import defaultdict
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

import ci_plan


# Previously proposed PHP leaf exemptions are not closed over the actual
# source-real native test consumers and PHP include graph. Until each can
# be justified against the full dependency graph, every such edit needs full.
REVIEWED_PHP_SOURCE_FALLBACKS = (
    "src/Finance/ApiPayments.php",
    "src/Finance/AutomaticRenewal.php",
    "src/Finance/PaymentPage.php",
    "src/Finance/Routes/WalletRecharge.php",
    "src/Finance/Routes/WalletTransfer.php",
    "src/Finance/Routes/AdminWallet.php",
    "src/Finance/Routes/DiscountEntry.php",
    "src/Finance/Routes/DiscountAdministration.php",
    "src/Finance/Routes/ManualReceipts.php",
    "src/Presentation/Labels.php",
    "src/Presentation/Text.php",
    "src/Subscription/Presentation.php",
    "src/Subscription/AccountDetails.php",
    "src/Telegram/UserProfile.php",
    "src/Telegram/Markup.php",
    "src/Jobs/BroadcastSend.php",
)

# Native runner witnesses from independent review: these fixtures consume
# the named source and expose the omitted owner in the prior mapping.
PHP_SOURCE_CONSUMER_WITNESSES = {
    "src/Finance/Routes/WalletRecharge.php": (
        ("test_owner_r5_roles.py", 1),
        ("test_post_v9_api_payment_evidence.py", 2),
    ),
    "src/Finance/ApiPayments.php": (
        ("test_shared_plan_review_fixes.py", 3),
        ("test_post_v9_scoped_api_pg.py", 5),
    ),
    "src/Finance/AutomaticRenewal.php": (
        ("test_owner_r6_financial_alert_ack.py", 1),
        ("test_post_v9_provider_integration.py", 5),
    ),
    "src/Presentation/Text.php": (
        ("test_owner_r4_admin_intents.py", 3),
    ),
    "src/Jobs/BroadcastSend.php": (
        ("test_post_v9_direct_gift.py", 7),
    ),
}

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
        for path in (
            *REVIEWED_PHP_SOURCE_FALLBACKS,
            "src/Finance/ReceiptHelpers.php",
            "tools/test_post_v9_onboarding.py",
            "tools/test_post_v9_api_staff_digest.py",
            "tools/test_unified_service_lifecycle.py",
            "tools/post_v9_suites.py",
        ):
            self.set_file(path, "synthetic original source\n")
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

    def test_new_markdown_requires_full_when_not_positively_reviewed(self):
        result = self.decide("docs/post-v9/NEW-RELEASE-NOTES.md", "A new document\n")
        self.assertEqual(result["scope"], "full")
        self.assertEqual(result["shards"], list(range(1, 9)))

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

    def test_mapped_test_files_cover_transitive_test_family_dependencies(self):
        # Check the actual current runner catalog, not just the filename of
        # the changed test. Shared Python fixture imports can affect multiple
        # independently scheduled families.
        root = ci_plan.ROOT / "tools"
        functions = ("run_admin", "run_api", "run_services", "run_wallet",
                     "run_presentation", "run_authorization", "run_effects", "run_funding")
        owners = defaultdict(set)
        catalog = ast.parse((root/"post_v9_suites.py").read_text())
        for function in catalog.body:
            if not isinstance(function, ast.FunctionDef) or function.name not in functions:
                continue
            index = functions.index(function.name) + 1
            for item in ast.walk(function):
                if (isinstance(item, ast.Constant) and isinstance(item.value, str)
                        and Path(item.value).name.startswith("test_")
                        and item.value.endswith((".py", ".php"))):
                    owners[Path(item.value).stem].add(index)
        # Standalone scoped suite invocations are owned by native shard 5.
        for name in ("test_post_v9_scoped_addons",
                     "test_post_v9_scoped_api_pg",
                     "test_post_v9_scoped_pg_payments"):
            owners[name].add(5)
        imports = {}
        for path in root.glob("*.py"):
            tree = ast.parse(path.read_text())
            used = set()
            for node in ast.walk(tree):
                if isinstance(node, ast.Import):
                    used.update(alias.name.split(".")[0] for alias in node.names)
                elif isinstance(node, ast.ImportFrom) and node.module:
                    used.add(node.module.split(".")[0])
            imports[path.stem] = used
        for _ in range(len(imports) + 1):
            changed = False
            for parent, children in imports.items():
                if parent not in owners:
                    continue
                for child in children:
                    if not owners[parent].issubset(owners[child]):
                        owners[child].update(owners[parent])
                        changed = True
            if not changed:
                break
        else:
            self.fail("Test dependency closure did not converge")
        for path, selected in ci_plan.FAMILY_PATHS.items():
            with self.subTest(path=path):
                self.assertTrue((ci_plan.ROOT/path).is_file())
                self.assertFalse((ci_plan.ROOT/path).is_symlink())
                self.assertTrue(path.startswith("tools/test_") and path.endswith(".py"),
                                "Unverified PHP/runtime source mappings must use full fallback")
                self.assertTrue(owners[Path(path).stem],
                                "Explicit test has no current runner owner")
                self.assertTrue(owners[Path(path).stem].issubset(set(selected)),
                                "Mapped test omits a consuming family")
        for source, witnesses in PHP_SOURCE_CONSUMER_WITNESSES.items():
            with self.subTest(source=source):
                # These are direct native consuming paths, not path-name guesses.
                # The old PHP map missed at least one of these owner families.
                self.assertNotIn(source, ci_plan.FAMILY_PATHS)
                for test_file, expected_family in witnesses:
                    text = (root / test_file).read_text()
                    self.assertIn(source, text)
                    self.assertIn(expected_family, owners[Path(test_file).stem])

    def test_reviewed_php_source_paths_fail_closed_until_dependencies_are_proven(self):
        self.assertFalse(any(path.endswith(".php") for path in ci_plan.FAMILY_PATHS))
        for path in REVIEWED_PHP_SOURCE_FALLBACKS:
            with self.subTest(path=path):
                self.set_file(path, "modified source\n")
                head = self.commit("source path: " + path)
                result = ci_plan.plan(self.repo, self.base, head)
                self.assertEqual(result["scope"], "full")
                self.assertEqual(result["shards"], list(range(1, 9)))
                self.assertEqual(result["reason"], "shared_policy_or_unknown_path")
                self.set_file(path, "synthetic original source\n")
                self.commit("restore source path: " + path)

    def test_test_only_onboarding_avoids_unrelated_native_families(self):
        result = self.decide("tools/test_post_v9_onboarding.py", "updated synthetic cases\n")
        self.assertEqual(result["scope"], "families")
        self.assertEqual(result["shards"], [1])

    def test_mixed_mapped_tests_select_exact_family_union(self):
        self.set_file("tools/test_post_v9_onboarding.py", "welcome regression\n")
        self.set_file("tools/test_post_v9_api_staff_digest.py", "API regression\n")
        self.set_file("docs/post-v9/README.md", "welcome notes\n")
        head = self.commit("bounded test-only union")
        result = ci_plan.plan(self.repo, self.base, head)
        self.assertEqual(result["scope"], "families")
        self.assertEqual(result["shards"], [1, 2])

    def test_mixed_test_and_unproven_php_source_requires_full(self):
        self.set_file("tools/test_post_v9_onboarding.py", "welcome regression\n")
        self.set_file("src/Finance/Routes/WalletRecharge.php", "wallet source changed\n")
        head = self.commit("mixed runtime source and tests")
        result = ci_plan.plan(self.repo, self.base, head)
        self.assertEqual(result["scope"], "full")
        self.assertEqual(result["shards"], list(range(1, 9)))

    def test_shared_helper_or_runner_change_forces_full(self):
        for path in ("src/Finance/ReceiptHelpers.php", "tools/post_v9_suites.py"):
            with self.subTest(path=path):
                self.set_file(path, "shared engine change\n")
                head = self.commit("shared change")
                self.assertEqual(ci_plan.plan(self.repo, self.base, head)["scope"], "full")

    def test_domain_module_addition_not_trusted_as_bounded(self):
        result = self.decide("src/Finance/Routes/WalletRecharge.php", "new source\n")
        self.assertEqual(result["scope"], "full")

    def test_family_and_cron_mixed_requires_full(self):
        self.set_file("tools/test_post_v9_onboarding.py", "new onboarding test\n")
        self.set_file(ci_plan.CRON_TEST, "changed cron fixture\n")
        head = self.commit("mixed domain and cron")
        self.assertEqual(ci_plan.plan(self.repo, self.base, head)["scope"], "full")

    def test_docs_plus_unknown_runtime_never_launders_full(self):
        self.set_file("docs/post-v9/README.md", "informational note\n")
        self.set_file("src/common.php", "unknown shared runtime change\n")
        head = self.commit("unknown and docs")
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
