#!/usr/bin/env python3
"""Static positive and failure controls for the main-push reuse attestation."""
import copy
import io
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

import ci_reuse as reuse

MAIN = "a" * 40
SOURCE = "b" * 40
TREE = "c" * 40
PREFIX = "repos/" + reuse.REPOSITORY + "/"
REPO_ID, WORKFLOW_ID, RUN_ID, JOB_ID, NUMBER, ATTEMPT = 7, 11, 13, 17, 19, 2
RUN_PATH = PREFIX + f"actions/runs/{RUN_ID}"
JOBS_PATH = RUN_PATH + f"/attempts/{ATTEMPT}/jobs?per_page=100"
RUNS_PATH = (PREFIX + f"actions/workflows/{WORKFLOW_ID}/runs"
             f"?event=pull_request&head_sha={SOURCE}&per_page=100")
PR_PATH = PREFIX + f"pulls/{NUMBER}"
ASSOCIATED_PATH = PREFIX + f"commits/{MAIN}/pulls?per_page=100"
WORKFLOW_ENDPOINT = PREFIX + "actions/workflows/v0.yml"


def repository():
    return {"id": REPO_ID, "full_name": reuse.REPOSITORY}


def evidence():
    pr = {"number": NUMBER, "merged": True, "merged_at": "2026-11-02T13:00:00Z",
          "merge_commit_sha": MAIN,
          "head": {"sha": SOURCE, "repo": repository()},
          "base": {"ref": "main", "repo": repository()}}
    run = {"id": RUN_ID, "workflow_id": WORKFLOW_ID,
           "name": reuse.WORKFLOW_NAME, "path": reuse.WORKFLOW_PATH,
           "event": "pull_request", "head_sha": SOURCE, "run_attempt": ATTEMPT,
           "status": "completed", "conclusion": "success",
           "html_url": f"https://github.com/{reuse.REPOSITORY}/actions/runs/{RUN_ID}",
           "repository": repository(), "head_repository": repository(),
           # Real closed-PR runs have this empty; authoritative PR lookup binds H.
           "pull_requests": []}
    job = {"id": JOB_ID, "name": reuse.REQUIRED_JOB, "run_id": RUN_ID,
           "run_attempt": ATTEMPT, "head_sha": SOURCE,
           "status": "completed", "conclusion": "success"}
    return {
        "repos/" + reuse.REPOSITORY: {**repository(), "default_branch": "main"},
        PREFIX + "git/ref/heads/main": {"object": {"type": "commit", "sha": MAIN}},
        ASSOCIATED_PATH: [copy.deepcopy(pr)],
        PR_PATH: pr,
        WORKFLOW_ENDPOINT: {"id": WORKFLOW_ID, "name": reuse.WORKFLOW_NAME,
                            "path": reuse.WORKFLOW_PATH, "state": "active"},
        RUNS_PATH: {"total_count": 1, "workflow_runs": [copy.deepcopy(run)]},
        RUN_PATH: run,
        JOBS_PATH: {"total_count": 2,
                    "jobs": [{"id": 23, "name": "impact-plan"}, job]},
    }


class FakeAPI:
    def __init__(self, data):
        self.data = data
        self.calls = []
        self.refresh = None

    def __call__(self, path):
        self.calls.append(path)
        if self.refresh is not None and path == RUN_PATH and self.calls.count(path) == 2:
            return copy.deepcopy(self.refresh)
        value = self.data[path]
        if isinstance(value, Exception):
            raise value
        return copy.deepcopy(value)


class AttestationTests(unittest.TestCase):
    def setUp(self):
        self.data = evidence()
        self.api = FakeAPI(self.data)
        self.tree = lambda sha: TREE

    def result(self, **kwargs):
        return reuse.attest(kwargs.get("head", MAIN),
                            kwargs.get("repo", reuse.REPOSITORY),
                            api=self.api, tree=kwargs.get("tree", self.tree))

    def denied(self, reason=None, **kwargs):
        result = self.result(**kwargs)
        self.assertIs(result["reused"], False)
        if reason:
            self.assertEqual(result["reason"], reason)
        self.assertNotIn("run", result)
        return result

    def test_valid_closed_pr_pipeline_retains_original_source_and_attempt(self):
        result = self.result()
        self.assertIs(result["reused"], True)
        self.assertEqual(result["attestation"], "accepted_source_pipeline")
        self.assertEqual(result["main_head"], MAIN)
        self.assertEqual(result["source_head"], SOURCE)
        self.assertEqual(result["run"]["head_sha"], SOURCE)
        self.assertEqual(result["run"]["attempt"], ATTEMPT)
        self.assertEqual(result["required_job"]["name"], "php85-validation")
        self.assertEqual(result["required_job"]["id"], JOB_ID)
        self.assertNotIn("complete", result)
        self.assertIs(result["package_reused"], False)
        self.assertIn(JOBS_PATH, self.api.calls)

    def test_present_matching_run_pr_association_is_accepted(self):
        association = {"number": NUMBER, "head": {"sha": SOURCE, "repo": {"id": REPO_ID}},
                       "base": {"ref": "main", "repo": {"id": REPO_ID}}}
        self.data[RUN_PATH]["pull_requests"] = [association]
        self.data[RUNS_PATH]["workflow_runs"][0]["pull_requests"] = [association]
        self.assertIs(self.result()["reused"], True)

    def test_invalid_inputs_do_not_query_api(self):
        for args, reason in [({"head": "main"}, "invalid_head"),
                             ({"head": "A" * 40}, "invalid_head"),
                             ({"repo": "other/private"}, "repository_not_allowed")]:
            with self.subTest(args=args):
                self.denied(reason, **args)
        self.assertEqual(self.api.calls, [])

    def test_wrong_canonical_or_main_identity_is_denied(self):
        for endpoint, field, value, reason in [
            ("repos/" + reuse.REPOSITORY, "full_name", "other/repo",
             "canonical_repository_mismatch"),
            ("repos/" + reuse.REPOSITORY, "id", True, "canonical_repository_mismatch"),
            (PREFIX + "git/ref/heads/main", "object", {"type": "commit", "sha": SOURCE},
             "main_head_mismatch")]:
            with self.subTest(field=field):
                self.setUp()
                self.data[endpoint][field] = value
                self.denied(reason)

    def test_missing_ambiguous_or_truncated_merged_pr_is_denied(self):
        for rows, reason in [([], "merged_pull_request_missing_or_ambiguous"),
                             ([evidence()[PR_PATH]] * 2,
                              "merged_pull_request_missing_or_ambiguous"),
                             ([evidence()[PR_PATH]] * 100, "pull_request_lookup_incomplete")]:
            with self.subTest(reason=reason, count=len(rows)):
                self.setUp()
                self.data[ASSOCIATED_PATH] = rows
                self.denied(reason)

    def test_missing_merge_sha_in_associated_pr_fails_closed(self):
        self.data[ASSOCIATED_PATH][0]["merge_commit_sha"] = None
        self.denied("merged_pull_request_missing_or_ambiguous")

    def test_authoritative_pr_must_still_be_exact_merged_main_pr(self):
        for field, value in [("merged", False), ("merge_commit_sha", SOURCE),
                             ("number", NUMBER + 1),
                             ("base", {"ref": "elsewhere", "repo": repository()})]:
            with self.subTest(field=field):
                self.setUp()
                self.data[PR_PATH][field] = value
                self.denied("pull_request_mismatch")

    def test_fork_or_wrong_repository_cannot_supply_source(self):
        self.data[PR_PATH]["head"]["repo"] = {"id": 99, "full_name": "fork/repo"}
        self.denied("pull_request_repository_mismatch")

    def test_full_tree_equality_and_valid_tree_are_required(self):
        self.denied("tree_mismatch", tree=lambda sha: TREE if sha == MAIN else "d" * 40)
        self.denied("invalid_git_tree", tree=lambda sha: "not-a-tree")
        self.data[PR_PATH]["head"]["sha"] = "refs/heads/source"
        self.denied("invalid_source_head")

    def test_current_expected_workflow_identity_is_required(self):
        for field, value in [("path", ".github/workflows/other.yml"),
                             ("name", "Unrelated"), ("id", False), ("state", "disabled")]:
            with self.subTest(field=field):
                self.setUp()
                self.data[WORKFLOW_ENDPOINT][field] = value
                self.denied("workflow_mismatch")

    def test_source_run_selection_cannot_be_absent_or_ambiguous(self):
        for count, rows in [(0, []), (2, [self.data[RUN_PATH]] * 2),
                            (2, [self.data[RUN_PATH]]), (True, [self.data[RUN_PATH]])]:
            with self.subTest(count=count):
                self.setUp()
                self.data[RUNS_PATH] = {"total_count": count, "workflow_runs": rows}
                self.denied("source_run_missing_or_ambiguous")

    def test_run_requires_exact_source_workflow_event_and_repository(self):
        mutations = [
            ("head_sha", MAIN, "run_identity_mismatch"),
            ("workflow_id", WORKFLOW_ID + 1, "run_identity_mismatch"),
            ("path", ".github/workflows/forged.yml", "run_identity_mismatch"),
            ("event", "push", "run_identity_mismatch"),
            ("name", "Forged", "run_identity_mismatch"),
            ("head_repository", {"id": REPO_ID, "full_name": "forged/repo"},
             "run_repository_mismatch"),
            ("repository", {"id": 99, "full_name": reuse.REPOSITORY},
             "run_repository_mismatch"),
            ("html_url", "https://example.invalid/private?token=secret", "run_url_mismatch")]
        for field, value, reason in mutations:
            with self.subTest(field=field):
                self.setUp()
                self.data[RUN_PATH][field] = value
                self.denied(reason)

    def test_successful_run_and_current_attempt_are_required(self):
        for field, value, reason in [
            ("status", "in_progress", "source_run_not_successful"),
            ("conclusion", "failure", "source_run_not_successful"),
            ("run_attempt", 0, "invalid_run_attempt"),
            ("run_attempt", ATTEMPT + 1, "run_attempt_changed")]:
            with self.subTest(field=field, value=value):
                self.setUp()
                self.data[RUN_PATH][field] = value
                self.denied(reason)

    def test_conflicting_nonempty_run_pr_information_is_denied(self):
        self.data[RUN_PATH]["pull_requests"] = [{"number": NUMBER + 1}]
        self.denied("run_pull_request_mismatch")
        self.data[RUN_PATH]["pull_requests"] *= 2
        self.denied("run_pull_request_ambiguous")

    def test_rerun_race_invalidates_sampled_attempt(self):
        self.api.refresh = {**self.data[RUN_PATH], "run_attempt": ATTEMPT + 1}
        self.denied("run_attempt_changed")
        self.assertEqual(self.api.calls.count(RUN_PATH), 2)

    def test_missing_duplicate_or_incomplete_job_listing_is_denied(self):
        required = copy.deepcopy(self.data[JOBS_PATH]["jobs"][1])
        for listing, reason in [
            ({"total_count": 0, "jobs": []}, "required_job_missing_or_duplicate"),
            ({"total_count": 2, "jobs": [required, required]},
             "required_job_missing_or_duplicate"),
            ({"total_count": 2, "jobs": [required]}, "job_lookup_incomplete")]:
            with self.subTest(reason=reason):
                self.setUp()
                self.data[JOBS_PATH] = listing
                self.denied(reason)

    def test_legacy_php_results_never_qualify(self):
        for name in reuse.LEGACY_JOBS:
            with self.subTest(name=name):
                self.setUp()
                self.data[JOBS_PATH]["jobs"][1]["name"] = name
                self.denied("legacy_php_jobs_not_eligible")

    def test_skipped_neutral_failed_or_running_required_job_is_not_success(self):
        for status, conclusion in [("completed", "skipped"), ("completed", "neutral"),
                                   ("completed", "failure"), ("completed", "cancelled"),
                                   ("in_progress", None)]:
            with self.subTest(conclusion=conclusion):
                self.setUp()
                self.data[JOBS_PATH]["jobs"][1].update(status=status, conclusion=conclusion)
                self.denied("required_job_not_successful")

    def test_wrong_job_attempt_run_or_head_is_denied(self):
        for field, value in [("run_attempt", ATTEMPT + 1), ("run_attempt", True),
                             ("run_id", RUN_ID + 1), ("head_sha", MAIN), ("id", False)]:
            with self.subTest(field=field):
                self.setUp()
                self.data[JOBS_PATH]["jobs"][1][field] = value
                self.denied("job_identity_mismatch")

    def test_errors_and_malformed_evidence_never_expose_private_values(self):
        private = "private-token-and-provider-diagnostic"
        for bad in [RuntimeError(private), TimeoutError(private),
                    reuse.NoReuse(private), None, ["invalid", private]]:
            with self.subTest(kind=type(bad).__name__):
                self.setUp()
                self.data[WORKFLOW_ENDPOINT] = bad
                result = self.denied("lookup_or_evidence_error")
                self.assertNotIn(private, json.dumps(result))
        def broken_tree(sha):
            raise RuntimeError(private)
        self.denied("lookup_or_evidence_error", tree=broken_tree)

    def test_cli_writes_decision_and_returns_zero_for_fallback(self):
        with tempfile.TemporaryDirectory() as folder:
            out = Path(folder) / "receipt.json"
            with patch("sys.stdout", new_callable=io.StringIO):
                status = reuse.main(["--head", MAIN, "--repo", reuse.REPOSITORY,
                                     "--output", str(out)], api=self.api, tree=self.tree)
            self.assertEqual(status, 0)
            self.assertIs(json.loads(out.read_text())["reused"], True)
            self.data[JOBS_PATH]["jobs"] = []
            self.data[JOBS_PATH]["total_count"] = 0
            with patch("sys.stdout", new_callable=io.StringIO):
                status = reuse.main(["--head", MAIN, "--repo", reuse.REPOSITORY,
                                     "--output", str(out)], api=self.api, tree=self.tree)
            self.assertEqual(status, 0)
            self.assertIs(json.loads(out.read_text())["reused"], False)
            self.assertEqual(sorted(p.name for p in Path(folder).iterdir()), ["receipt.json"])

    def test_cli_write_failure_is_nonzero_and_sanitized(self):
        with tempfile.TemporaryDirectory() as folder:
            out = Path(folder) / "private-secret" / "missing.json"
            with patch("sys.stderr", new_callable=io.StringIO) as stderr:
                status = reuse.main(["--head", MAIN, "--repo", reuse.REPOSITORY,
                                     "--output", str(out)], api=self.api, tree=self.tree)
            self.assertEqual(status, 1)
            self.assertEqual(stderr.getvalue(), "ci_reuse: receipt_write_failed\n")


class BoundaryTests(unittest.TestCase):
    def test_transport_is_get_only_and_restricted_to_canonical_repository(self):
        client = reuse.ReadOnlyGitHub("private-token")
        seen = []
        def opened(request, timeout):
            seen.append((request, timeout))
            return io.BytesIO(b'{"ok":true}')
        client.opener.open = opened
        self.assertEqual(client("repos/" + reuse.REPOSITORY), {"ok": True})
        request, timeout = seen[0]
        self.assertEqual(request.get_method(), "GET")
        headers = {key.lower(): value for key, value in request.header_items()}
        self.assertEqual(headers["x-github-api-version"], "2022-11-28")
        self.assertEqual(headers["x-github-api-version"], reuse.GITHUB_API_VERSION)
        self.assertEqual(request.full_url, "https://api.github.com/repos/" + reuse.REPOSITORY)
        self.assertLessEqual(timeout, 5)
        with self.assertRaises(reuse.NoReuse):
            client("https://example.invalid/private")
        with self.assertRaises(reuse.NoReuse):
            client("repos/other/repository")
        self.assertEqual(len(seen), 1)

    def test_transport_limits_response_and_budget(self):
        client = reuse.ReadOnlyGitHub()
        client.opener.open = lambda *args, **kwargs: io.BytesIO(
            b"x" * (reuse.MAX_RESPONSE_BYTES + 1))
        with self.assertRaisesRegex(reuse.NoReuse, "api_response_too_large"):
            client("repos/" + reuse.REPOSITORY)
        expired = reuse.ReadOnlyGitHub(budget=-1)
        with self.assertRaisesRegex(reuse.NoReuse, "api_budget_exhausted"):
            expired("repos/" + reuse.REPOSITORY)


if __name__ == "__main__":
    unittest.main()
