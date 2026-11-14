#!/usr/bin/env python3
"""Conservative no-impact/docs/source/Cron/full selection from immutable Git objects.

This plans coverage only. It neither runs tests nor attests reused acceptance.
Unknown inputs select all eight current PHP 8.5 native families.
"""
from __future__ import annotations

import argparse
import json
import os
import re
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SHA = re.compile(r"[0-9a-f]{40}\Z")
CRON = "settings/cronRunner.php"
CRON_TEST = "tools/test_post_v9_cron_runner.py"
PREPARATION_START = b"$installDirectory = realpath(dirname(__DIR__));\n"
PREPARATION_END = b"$lockFile = $lockDirectory . DIRECTORY_SEPARATOR . 'achbot-cron-'\n"
# Explicitly owned paths only. Shared/unknown runtime, validation infrastructure,
# migrations and policy code still need all eight families. Indices are the
# existing disjoint post_v9_suites.RUNNERS order, not ad hoc single-test claims.
# Keep this mapping intentionally small and evidence-backed; extend it when an
# actual dependency/calling-path review establishes a narrower safe selection.
# Existing informational entrypoints have a reviewed lightweight contract.
# Financial/security/release/migration/policy docs and unknown Markdown stay full.
ORDINARY_DOC_PATHS = frozenset({
    "README.md",
    "docs/post-v9/README.md",
})

FAMILY_PATHS = {
    "src/Finance/ApiPayments.php": (2, 4, 7, 8),
    "src/Finance/AutomaticRenewal.php": (3, 6, 7, 8),
    "src/Finance/PaymentPage.php": (4, 5),
    "src/Finance/Routes/WalletRecharge.php": (4, 8),
    "src/Finance/Routes/WalletTransfer.php": (4, 6, 8),
    "src/Finance/Routes/AdminWallet.php": (1, 4, 6, 8),
    "src/Finance/Routes/DiscountEntry.php": (4, 5, 8),
    "src/Finance/Routes/DiscountAdministration.php": (1, 4, 6, 8),
    "src/Finance/Routes/ManualReceipts.php": (1, 2, 7, 8),
    "src/Presentation/Labels.php": (1, 3, 5),
    "src/Presentation/Text.php": (1, 4, 5),
    "src/Subscription/Presentation.php": (1, 3, 5),
    "src/Subscription/AccountDetails.php": (1, 3, 5),
    "src/Telegram/UserProfile.php": (1, 3, 5),
    "src/Telegram/Markup.php": (1, 5),
    "src/Jobs/BroadcastSend.php": (1, 3),
    "tools/test_post_v9_onboarding.py": (1,),
    "tools/test_owner_destination_escaping.py": (1,),
    "tools/test_owner_r5_roles.py": (1,),
    "tools/test_post_v9_api_staff_digest.py": (2,),
    "tools/test_owner_r6_api_flow.py": (2,),
    "tools/test_post_v9_payment_api_admin.py": (2,),
    "tools/test_unified_service_lifecycle.py": (3,),
    "tools/test_unified_customer_services.py": (3,),
    "tools/test_post_v9_wallet_transfer.py": (4,),
    "tools/test_next_payment_ux.py": (4,),
    "tools/test_post_v9_provider_integration.py": (5,),
    "tools/test_post_v9_payment_ux_lifecycle.py": (2, 5, 6),
    "tools/test_post_v9_automatic_renewal.py": (3, 6),
    "tools/test_post_v9_payment_readiness.py": (6,),
    "tools/test_post_v9_gateway_rules.py": (6,),
    "tools/test_post_v9_finance_routes.py": (2, 5, 6, 7, 8),
    "tools/test_post_v9_service_effects.py": (7,),
    "tools/test_post_v9_logical_transfer.py": (8,),
    "tools/test_post_v9_wallet_checkout.py": (7, 8),
    "tools/test_post_v9_bank_buy.py": (2, 4, 8),
    "tools/test_post_v9_manual_payments.py": (1, 2, 4, 5, 6, 8),
}


class PlanError(RuntimeError):
    """Source identity cannot be established; running another checkout is unsafe."""


class GitError(RuntimeError):
    def __init__(self, operation: str, returncode: int | None = None):
        super().__init__(operation)
        self.returncode = returncode


def git(repo: Path, *arguments: str) -> bytes:
    try:
        result = subprocess.run(
            ["git", "--no-optional-locks", "-c", "core.fsmonitor=false",
             "-C", str(repo), *arguments],
            env={**os.environ, "GIT_NO_REPLACE_OBJECTS":"1"},
            capture_output=True, timeout=20, check=False,
        )
    except (OSError, subprocess.TimeoutExpired) as error:
        raise GitError("git_unavailable") from error
    if result.returncode:
        raise GitError(arguments[0], result.returncode)
    return result.stdout


def exact_sha(value) -> bool:
    return isinstance(value, str) and SHA.fullmatch(value) is not None


def ordinary_doc(path: str) -> bool:
    return path in ORDINARY_DOC_PATHS


def changes(repo: Path, base: str, head: str) -> list[tuple[str, str]]:
    raw = git(repo, "diff", "--no-ext-diff", "--no-textconv",
              "--find-renames", "--name-status", "-z", base, head, "--")
    if not raw:
        return []
    if not raw.endswith(b"\0"):
        raise GitError("ambiguous_diff")
    fields = raw[:-1].split(b"\0")
    if len(fields) % 2:
        raise GitError("ambiguous_diff")
    result = []
    for index in range(0, len(fields), 2):
        try:
            status = fields[index].decode("ascii")
            path = fields[index+1].decode("utf-8")
        except UnicodeError as error:
            raise GitError("ambiguous_diff") from error
        if (not path or path.startswith("/") or "\\" in path
                or any(ord(char) < 32 or ord(char) == 127 for char in path)
                or any(part in (".","..") for part in path.split("/"))):
            raise GitError("ambiguous_path")
        result.append((status,path))
    if len({path for _,path in result}) != len(result):
        raise GitError("ambiguous_diff")
    return result


def entry(repo: Path, revision: str, path: str):
    raw = git(repo, "ls-tree", "-z", revision, "--", path)
    if not raw:
        return None
    records = raw.rstrip(b"\0").split(b"\0")
    if len(records) != 1:
        raise GitError("ambiguous_tree_entry")
    try:
        metadata, name = records[0].split(b"\t",1)
        mode, kind, object_id = metadata.decode("ascii").split()
        if name.decode("utf-8") != path or not exact_sha(object_id):
            raise ValueError("unexpected tree entry")
    except (UnicodeError, ValueError) as error:
        raise GitError("ambiguous_tree_entry") from error
    return mode, kind, object_id


def regular(item) -> bool:
    return item is not None and item[0] in ("100644","100755") and item[1] == "blob"


def outside_preparation(source: bytes):
    # Whole line anchors, exactly once, avoid accepting aliases or a second
    # coordinator/lease boundary hidden inside the preparation implementation.
    if source.count(PREPARATION_START) != 1 or source.count(PREPARATION_END) != 1:
        return None
    start = source.index(PREPARATION_START)
    end = source.index(PREPARATION_END)
    if (start == 0 or source[start-1:start] != b"\n"
            or end <= start or source[end-1:end] != b"\n"):
        return None
    return source[:start], source[end:]


def classify(repo: Path, base: str, head: str,
             changed: list[tuple[str, str]]) -> tuple[str, str, list[int]]:
    if not changed:
        return "none", "no_effective_changes", []
    # A low-similarity move can be reported as independent add/delete records.
    # Never assume it is a harmless documentation-only change.
    statuses = {status for status, _ in changed}
    if "A" in statuses and "D" in statuses:
        return "full", "ambiguous_add_delete", list(range(1, 9))
    cron = False
    selected = set()
    for status, path in changed:
        if status not in ("A", "M", "D"):
            return "full", "file_type_or_ambiguous_change", list(range(1, 9))
        old = entry(repo, base, path)
        new = entry(repo, head, path)
        if ((status != "A" and not regular(old))
                or (status != "D" and not regular(new))
                or (old is not None and new is not None and old[0] != new[0])):
            return "full", "nonregular_or_mode_change", list(range(1, 9))
        if ordinary_doc(path):
            # Added/deleted docs and disguised low-similarity moves are not
            # covered by the reviewed modified-in-place docs contract.
            if status != "M":
                return "full", "document_added_or_removed", list(range(1, 9))
            continue
        if path in (CRON, CRON_TEST):
            if status != "M" or old is None or new is None:
                return "full", "cron_file_added_or_removed", list(range(1, 9))
            cron = True
            if path == CRON:
                before = outside_preparation(git(repo, "cat-file", "blob", old[2]))
                after = outside_preparation(git(repo, "cat-file", "blob", new[2]))
                if before is None or after is None or before != after:
                    return "full", "cron_outside_preparation_changed_or_ambiguous", list(range(1, 9))
            continue
        families = FAMILY_PATHS.get(path)
        if families is None or status != "M":
            return "full", "shared_policy_or_unknown_path", list(range(1, 9))
        if not families or any(type(index) is not int or index not in range(1, 9)
                               for index in families):
            return "full", "invalid_family_mapping", list(range(1, 9))
        selected.update(families)
    if cron and selected:
        # No lightweight path may silently omit its independent Cron regression.
        return "full", "mixed_cron_and_domain", list(range(1, 9))
    if cron:
        return "cron", "cron_preparation_or_dedicated_test", []
    if selected:
        if len(selected) == 8:
            return "full", "all_families_affected", list(range(1, 9))
        return "families", "explicit_affected_families", sorted(selected)
    return "docs", "ordinary_docs_only", []


def plan(repo: Path, base, head, *, force_full: bool = False) -> dict:
    if not exact_sha(head):
        raise PlanError("Expected an exact 40-character source HEAD")
    try:
        checkout = git(repo,"rev-parse","--verify","HEAD").decode().strip()
        resolved = git(repo,"rev-parse","--verify",head+"^{commit}").decode().strip()
        tree = git(repo,"rev-parse","--verify",head+"^{tree}").decode().strip()
    except (GitError, UnicodeError) as error:
        raise PlanError("Cannot verify checkout source identity") from error
    if checkout != head or resolved != head or not exact_sha(tree):
        raise PlanError("Checkout HEAD does not match the requested/event source HEAD")

    scope, reason, changed, shards = "full", "invalid_or_missing_base", [], list(range(1, 9))
    try:
        if exact_sha(base):
            try:
                resolved_base = git(repo,"rev-parse","--verify",base+"^{commit}").decode().strip()
            except (GitError, UnicodeError):
                reason = "base_unavailable"
            else:
                if resolved_base != base:
                    reason = "ambiguous_base"
                else:
                    try:
                        git(repo,"merge-base","--is-ancestor",base,head)
                    except GitError as error:
                        reason = "base_not_ancestor" if error.returncode == 1 else "ancestry_unavailable"
                    else:
                        changed = changes(repo,base,head)
                        if git(repo,"status","--porcelain=v1","-z","--untracked-files=normal"):
                            reason = "dirty_checkout"
                        else:
                            scope, reason, shards = classify(repo,base,head,changed)
    except (GitError, UnicodeError):
        scope, reason = "full", "git_evidence_unavailable_or_ambiguous"
    if force_full:
        scope, reason, shards = "full", "forced_full", list(range(1, 9))
    try:
        final_head = git(repo,"rev-parse","--verify","HEAD").decode().strip()
    except (GitError, UnicodeError) as error:
        raise PlanError("Cannot reverify checkout source HEAD") from error
    if final_head != head:
        raise PlanError("Checkout HEAD changed during scope selection")
    return {
        "scope":scope, "head":head, "tree":tree, "base":base,
        "changed_paths":[path for _,path in changed], "reason":reason,
        "shards":shards,
    }


def event_inputs(environ: dict) -> tuple:
    try:
        path = environ["GITHUB_EVENT_PATH"]
        event = json.loads(Path(path).read_text())
        if not isinstance(event,dict):
            raise ValueError("event must be an object")
    except (KeyError,OSError,ValueError) as error:
        raise PlanError("Trusted GitHub event JSON is unavailable or malformed") from error
    repository = event.get("repository",{}).get("full_name")
    if repository and environ.get("GITHUB_REPOSITORY") not in (None,repository):
        raise PlanError("GitHub event repository does not match platform context")
    name = environ.get("GITHUB_EVENT_NAME")
    if name == "pull_request":
        pr = event.get("pull_request")
        if not isinstance(pr,dict):
            raise PlanError("Pull request source identity is unavailable")
        head = pr.get("head",{}).get("sha")
        base = pr.get("base",{}).get("sha")
        return base,head,False
    if name == "push":
        head = event.get("after")
        if environ.get("GITHUB_SHA") != head:
            raise PlanError("Push source SHA does not match platform context")
        return event.get("before"),head,False
    # Dispatch is always full. Other event kinds are conservatively full,
    # and still require checkout identity to match the platform source SHA.
    return None,environ.get("GITHUB_SHA"),True


def main(argv=None, *, repo: Path = ROOT, environ=None):
    environ = os.environ if environ is None else environ
    parser = argparse.ArgumentParser()
    identity = parser.add_mutually_exclusive_group(required=True)
    identity.add_argument("--head")
    identity.add_argument("--github-event",action="store_true")
    parser.add_argument("--base")
    parser.add_argument("--force-full",action="store_true")
    parser.add_argument("--output",type=Path)
    parser.add_argument("--github-output",action="store_true")
    args = parser.parse_args(argv)
    if args.github_event:
        if args.base is not None:
            parser.error("--base cannot override a trusted GitHub event")
        base,head,forced = event_inputs(environ)
    else:
        base,head,forced = args.base,args.head,False
    result = plan(repo,base,head,force_full=args.force_full or forced)
    encoded = json.dumps(result,sort_keys=True)
    if args.output:
        args.output.write_text(encoded+"\n")
    if args.github_output:
        destination = environ.get("GITHUB_OUTPUT")
        if not destination:
            raise PlanError("GITHUB_OUTPUT is unavailable")
        with Path(destination).open("a") as output:
            output.write("scope="+result["scope"]+"\nshards="+json.dumps(result["shards"])+"\n")
    print(encoded)
    return result


if __name__ == "__main__":
    try:
        main()
    except (PlanError,GitError) as error:
        print("AChBot validation plan: "+str(error),file=sys.stderr)
        raise SystemExit(2)
