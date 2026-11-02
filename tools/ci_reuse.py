#!/usr/bin/env python3
"""Read-only, fail-closed reuse of an accepted PHP 8.5 source pipeline.

This attests pipeline acceptance, not full-suite coverage. A caller must perform
real validation unless the written receipt has reused == true. It must always
build any current-HEAD package separately. CLI exit 0 means a decision was
written, including reused:false; nonzero also requires normal validation.
"""
from __future__ import annotations

import argparse
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile
import time
import urllib.request

REPOSITORY = "ach1992/bot-refactor"
WORKFLOW_PATH = ".github/workflows/v0.yml"
WORKFLOW_NAME = "Compatibility"
REQUIRED_JOB = "php85-validation"
LEGACY_JOBS = {"phase1-php81-compatibility", "phase1-php84-full-history"}
_SHA = re.compile(r"[0-9a-f]{40}")
MAX_RESPONSE_BYTES = 2 * 1024 * 1024
# REST 2026-03-10 omits merge_commit_sha for merged PRs; this supported
# version retains the exact identity required for a fail-closed attestation.
GITHUB_API_VERSION = "2022-11-28"
_FAILURE_REASONS = frozenset({
    "invalid_run",
    "run_identity_mismatch",
    "run_repository_mismatch",
    "source_run_not_successful",
    "invalid_run_attempt",
    "run_url_mismatch",
    "run_pull_request_ambiguous",
    "run_pull_request_mismatch",
    "repository_not_allowed",
    "invalid_head",
    "canonical_repository_mismatch",
    "main_head_mismatch",
    "pull_request_lookup_incomplete",
    "merged_pull_request_missing_or_ambiguous",
    "invalid_pull_request",
    "pull_request_mismatch",
    "pull_request_repository_mismatch",
    "invalid_source_head",
    "invalid_git_tree",
    "tree_mismatch",
    "workflow_mismatch",
    "source_run_missing_or_ambiguous",
    "invalid_run_id",
    "run_attempt_changed",
    "job_lookup_incomplete",
    "legacy_php_jobs_not_eligible",
    "required_job_missing_or_duplicate",
    "job_identity_mismatch",
    "required_job_not_successful",
    "api_endpoint_not_allowed",
    "api_budget_exhausted",
    "api_response_too_large",
    "invalid_git_object",
})


class NoReuse(Exception):
    """A bounded, non-sensitive reason for falling back to real validation."""


def _require(condition, reason):
    if not condition:
        raise NoReuse(reason)


def _sha(value):
    return isinstance(value, str) and _SHA.fullmatch(value) is not None


def _id(value):
    return type(value) is int and value > 0


def _repo(value, expected_id):
    return (isinstance(value, dict) and value.get("id") == expected_id
            and value.get("full_name") == REPOSITORY)


def _run_identity(run, run_id, workflow_id, repo_id, source, number):
    _require(isinstance(run, dict), "invalid_run")
    _require(_id(run.get("id")) and _id(run.get("workflow_id"))
             and run.get("id") == run_id and run.get("workflow_id") == workflow_id
             and run.get("path") == WORKFLOW_PATH
             and run.get("name") == WORKFLOW_NAME
             and run.get("event") == "pull_request"
             and run.get("head_sha") == source, "run_identity_mismatch")
    _require(_repo(run.get("repository"), repo_id)
             and _repo(run.get("head_repository"), repo_id),
             "run_repository_mismatch")
    _require(run.get("status") == "completed"
             and run.get("conclusion") == "success", "source_run_not_successful")
    _require(_id(run.get("run_attempt")), "invalid_run_attempt")
    _require(run.get("html_url") ==
             f"https://github.com/{REPOSITORY}/actions/runs/{run_id}",
             "run_url_mismatch")
    prs = run.get("pull_requests")
    # GitHub clears this array after a PR merges. The merged-PR endpoint and
    # exact source SHA bind it independently; present conflicting data is denied.
    _require(isinstance(prs, list) and len(prs) <= 1, "run_pull_request_ambiguous")
    if not prs:
        return
    pr = prs[0]
    _require(pr.get("number") == number
             and pr.get("head", {}).get("sha") == source
             and pr.get("head", {}).get("repo", {}).get("id") == repo_id
             and pr.get("base", {}).get("ref") == "main"
             and pr.get("base", {}).get("repo", {}).get("id") == repo_id,
             "run_pull_request_mismatch")


def attest(head, repo, *, api, tree):
    """Return a receipt using injected GET-only API and Git-tree boundaries.

    api(relative_endpoint) returns decoded JSON; tree(exact_sha) returns a
    Git tree SHA. Exceptions and ambiguous evidence produce reused:false.
    Nothing from an external exception or unvalidated response is emitted.
    """
    receipt = {"schema_version": 1, "reused": False,
               "repository": REPOSITORY, "main_head": head if _sha(head) else None}
    try:
        _require(repo == REPOSITORY, "repository_not_allowed")
        _require(_sha(head), "invalid_head")
        prefix = f"repos/{REPOSITORY}/"
        canonical = api(f"repos/{REPOSITORY}")
        _require(canonical.get("full_name") == REPOSITORY
                 and _id(canonical.get("id"))
                 and canonical.get("default_branch") == "main",
                 "canonical_repository_mismatch")
        repo_id = canonical["id"]
        main = api(prefix + "git/ref/heads/main")
        _require(main.get("object", {}).get("type") == "commit"
                 and main.get("object", {}).get("sha") == head, "main_head_mismatch")
        associated = api(prefix + f"commits/{head}/pulls?per_page=100")
        _require(isinstance(associated, list) and len(associated) < 100,
                 "pull_request_lookup_incomplete")
        matches = [p for p in associated if isinstance(p, dict)
                   and p.get("merged_at") and p.get("merge_commit_sha") == head
                   and p.get("base", {}).get("ref") == "main"]
        _require(len(matches) == 1, "merged_pull_request_missing_or_ambiguous")
        number = matches[0].get("number")
        _require(_id(number), "invalid_pull_request")
        pr = api(prefix + f"pulls/{number}")
        _require(pr.get("number") == number and pr.get("merged") is True
                 and pr.get("merge_commit_sha") == head
                 and pr.get("base", {}).get("ref") == "main",
                 "pull_request_mismatch")
        _require(_repo(pr.get("base", {}).get("repo"), repo_id)
                 and _repo(pr.get("head", {}).get("repo"), repo_id),
                 "pull_request_repository_mismatch")
        source = pr.get("head", {}).get("sha")
        _require(_sha(source), "invalid_source_head")
        main_tree, source_tree = tree(head), tree(source)
        _require(_sha(main_tree) and _sha(source_tree), "invalid_git_tree")
        _require(main_tree == source_tree, "tree_mismatch")
        workflow = api(prefix + "actions/workflows/v0.yml")
        _require(_id(workflow.get("id")) and workflow.get("path") == WORKFLOW_PATH
                 and workflow.get("name") == WORKFLOW_NAME
                 and workflow.get("state") == "active", "workflow_mismatch")
        workflow_id = workflow["id"]
        runs = api(prefix + f"actions/workflows/{workflow_id}/runs"
                   f"?event=pull_request&head_sha={source}&per_page=100")
        _require(type(runs.get("total_count")) is int and runs["total_count"] == 1
                 and isinstance(runs.get("workflow_runs"), list)
                 and len(runs["workflow_runs"]) == 1,
                 "source_run_missing_or_ambiguous")
        selected = runs["workflow_runs"][0]
        run_id = selected.get("id")
        _require(_id(run_id), "invalid_run_id")
        _run_identity(selected, run_id, workflow_id, repo_id, source, number)
        run = api(prefix + f"actions/runs/{run_id}")
        _run_identity(run, run_id, workflow_id, repo_id, source, number)
        attempt = run["run_attempt"]
        _require(selected["run_attempt"] == attempt, "run_attempt_changed")
        jobs = api(prefix + f"actions/runs/{run_id}/attempts/{attempt}/jobs?per_page=100")
        entries = jobs.get("jobs")
        _require(isinstance(entries, list) and type(jobs.get("total_count")) is int
                 and jobs["total_count"] == len(entries), "job_lookup_incomplete")
        _require(not any(j.get("name") in LEGACY_JOBS for j in entries),
                 "legacy_php_jobs_not_eligible")
        required = [j for j in entries if j.get("name") == REQUIRED_JOB]
        _require(len(required) == 1, "required_job_missing_or_duplicate")
        job = required[0]
        _require(_id(job.get("id")) and _id(job.get("run_id"))
                 and _id(job.get("run_attempt")) and job.get("run_id") == run_id
                 and job.get("run_attempt") == attempt
                 and job.get("head_sha") == source, "job_identity_mismatch")
        _require(job.get("status") == "completed"
                 and job.get("conclusion") == "success", "required_job_not_successful")
        # A rerun that starts during inspection invalidates the sampled attempt.
        current = api(prefix + f"actions/runs/{run_id}")
        _run_identity(current, run_id, workflow_id, repo_id, source, number)
        _require(current["run_attempt"] == attempt, "run_attempt_changed")
        receipt.update({
            "reused": True, "reason": "accepted_source_pipeline",
            "attestation": "accepted_source_pipeline",
            "main_tree": main_tree, "source_head": source, "source_tree": source_tree,
            "pull_request": number,
            "workflow": {"id": workflow_id, "name": WORKFLOW_NAME, "path": WORKFLOW_PATH},
            "run": {"id": run_id, "attempt": attempt, "head_sha": source,
                    "url": run["html_url"], "event": "pull_request",
                    "status": "completed", "conclusion": "success"},
            "required_job": {"id": job["id"], "name": REQUIRED_JOB,
                             "run_id": run_id, "run_attempt": attempt,
                             "head_sha": source, "status": "completed",
                             "conclusion": "success"},
            "coverage": "Original source pipeline accepted; full-suite coverage is not asserted.",
            "package_reused": False,
        })
    except NoReuse as exc:
        reason = str(exc)
        receipt["reason"] = reason if reason in _FAILURE_REASONS else "lookup_or_evidence_error"
    except Exception:
        receipt["reason"] = "lookup_or_evidence_error"
    return receipt


class _NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


class ReadOnlyGitHub:
    """GET-only GitHub boundary: limited requests, response size and time."""
    def __init__(self, token=None, *, timeout=5, budget=45):
        self.token = token
        self.timeout = timeout
        self.deadline = time.monotonic() + budget
        self.calls = 0
        self.opener = urllib.request.build_opener(_NoRedirect())

    def __call__(self, endpoint):
        _require(isinstance(endpoint, str)
                 and (endpoint == f"repos/{REPOSITORY}"
                      or endpoint.startswith(f"repos/{REPOSITORY}/"))
                 and ".." not in endpoint and "#" not in endpoint,
                 "api_endpoint_not_allowed")
        remaining = self.deadline - time.monotonic()
        _require(remaining > 0 and self.calls < 12, "api_budget_exhausted")
        self.calls += 1
        headers = {"Accept": "application/vnd.github+json",
                   "User-Agent": "AChBot-ci-reuse",
                   "X-GitHub-Api-Version": GITHUB_API_VERSION}
        if self.token:
            headers["Authorization"] = "Bearer " + self.token
        request = urllib.request.Request("https://api.github.com/" + endpoint,
                                         headers=headers, method="GET")
        with self.opener.open(request, timeout=min(self.timeout, remaining)) as response:
            raw = response.read(MAX_RESPONSE_BYTES + 1)
        _require(len(raw) <= MAX_RESPONSE_BYTES, "api_response_too_large")
        return json.loads(raw)


def git_tree(sha):
    _require(_sha(sha), "invalid_git_object")
    env = {**os.environ, "GIT_OPTIONAL_LOCKS": "0", "GIT_TERMINAL_PROMPT": "0"}
    result = subprocess.run(["git", "rev-parse", "--verify", sha + "^{tree}"],
                            capture_output=True, timeout=5, env=env, check=True)
    value = result.stdout.decode("ascii").strip()
    _require(_sha(value), "invalid_git_tree")
    return value


def _write_receipt(path, receipt):
    temporary = None
    try:
        with tempfile.NamedTemporaryFile(mode="w", encoding="utf-8", dir=path.parent,
                                         prefix=".ci-reuse-", delete=False) as handle:
            temporary = Path(handle.name)
            json.dump(receipt, handle, indent=2)
            handle.write("\n")
        os.replace(temporary, path)
    finally:
        if temporary is not None:
            temporary.unlink(missing_ok=True)


def main(argv=None, *, api=None, tree=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--head", required=True)
    parser.add_argument("--repo", required=True)
    parser.add_argument("--output", required=True, type=Path)
    args = parser.parse_args(argv)
    if api is None:
        api = ReadOnlyGitHub(os.environ.get("GH_TOKEN") or os.environ.get("GITHUB_TOKEN"))
    receipt = attest(args.head, args.repo, api=api, tree=tree or git_tree)
    try:
        _write_receipt(args.output, receipt)
    except Exception:
        print("ci_reuse: receipt_write_failed", file=sys.stderr)
        return 1
    print(json.dumps(receipt))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
