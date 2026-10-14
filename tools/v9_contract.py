#!/usr/bin/env python3
"""AChBot V9 Phase 1 final integration pinned to merged V8 verified runtime."""
from __future__ import annotations

import hashlib
import subprocess
from pathlib import Path

import v8_contract as v8

ROOT = Path(__file__).resolve().parents[1]
BASE = "9ca6cabe43121a528db8d32c9b4aa1b5d10168ea"
BASE_TREE = "434f2d7aac6f23e0afb16b320782641aab81a6e0"
EXPECTED_RUNTIME_MEMBERS = 92
EXPECTED_FINANCIAL_MODULES = 27
# No unsupported behavioral or monetary change is invented merely to release V9.
AUTHORIZED_RUNTIME_CHANGES: frozenset[str] = frozenset()

PUBLIC_ENTRYPOINTS = (
    "bot.php", "config.php", "buy_helpers.php", "health.php",
    "pay/index.php", "pay/back.php", "settings/gift2all.php",
    "settings/messagewizwiz.php", "settings/rewardReport.php",
    "settings/subLink.php", "settings/tronChecker.php",
    "settings/warnusers.php",
)
SANITIZED_PRIVATE_EXAMPLE = "baseInfo.example.php"
LEGACY_DDL = "database/legacy-schema.sql"


def git(*args: str) -> bytes:
    return subprocess.check_output(["git", "-C", str(ROOT), *args])


def old(name: str) -> bytes:
    return git("show", BASE + ":" + name)


def members() -> list[str]:
    return v8.members()


def verify() -> dict:
    if git("rev-parse", BASE + "^{tree}").decode().strip() != BASE_TREE:
        raise RuntimeError("Pinned V8 merged base/tree changed")
    state = v8.verify()
    runtime = members()
    if len(runtime) != EXPECTED_RUNTIME_MEMBERS or len(set(runtime)) != len(runtime):
        raise RuntimeError("Incorrect/duplicate integrated runtime members")
    if state["financial_modules"] != EXPECTED_FINANCIAL_MODULES:
        raise RuntimeError("Lost finance integration modules")
    if not set(PUBLIC_ENTRYPOINTS) <= set(runtime):
        raise RuntimeError("Missing public PHP/health/payment/Cron entrypoint")
    changed = []
    for name in runtime:
        path = ROOT / name
        if not path.is_file() or path.is_symlink():
            raise RuntimeError("Missing or symlinked integrated runtime: " + name)
        if path.read_bytes() != old(name):
            changed.append(name)
    if set(changed) - AUTHORIZED_RUNTIME_CHANGES:
        raise RuntimeError("Unapproved Phase 1 runtime change: " + repr(changed))
    for name in (LEGACY_DDL, SANITIZED_PRIVATE_EXAMPLE):
        path = ROOT / name
        if not path.is_file() or path.is_symlink() or path.read_bytes() != old(name):
            raise RuntimeError("Legacy DDL or synthetic private example changed: " + name)
    if (ROOT / "baseInfo.php").exists():
        raise RuntimeError("Private baseInfo.php appeared in development worktree")
    tracked = set(git("ls-files", "-z").decode().split("\0"))
    if "baseInfo.php" in tracked:
        raise RuntimeError("Private baseInfo.php tracked")
    if subprocess.run(["git", "-C", str(ROOT), "check-ignore", "-q", "baseInfo.php"]).returncode:
        raise RuntimeError("Private config would not be ignored by Git")
    exposed = {name: hashlib.sha256((ROOT / name).read_bytes()).hexdigest()
               for name in PUBLIC_ENTRYPOINTS}
    return {
        "integrated_base": BASE, "integrated_base_tree": BASE_TREE,
        "runtime_members": len(runtime), "finance_modules": len(v8.MODULES),
        "public_entrypoints": len(exposed),
        "runtime_changes_vs_v8": sorted(changed),
        "all_public_entrypoints_pinned": not changed,
        "source_sha256": hashlib.sha256(
            b"".join(name.encode() + b"\0" + (ROOT / name).read_bytes()
                     for name in runtime)
        ).hexdigest(),
        "private_config_template_pinned": True, "private_config_untracked": True,
        "original_23_table_ddl_unchanged": True,
        "no_runtime_config_migration": True,
        "production_financial_safety_verified": False,
    }


if __name__ == "__main__":
    import json
    print(json.dumps(verify(), sort_keys=True))
