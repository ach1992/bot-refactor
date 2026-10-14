#!/usr/bin/env python3
"""Deterministic V9 final Phase 1 source-only ZIP/manifest from clean exact HEAD."""
from __future__ import annotations

import argparse
import json
import subprocess
from pathlib import Path

import package_v8
import v9_contract as c


def build():
    evidence = c.verify()
    archive, manifest = package_v8.build()
    manifest["name"] = "AChBot V9 Phase 1 final integrated source-only candidate"
    manifest["prior_integrated_release"] = "v0.8.0-rc.1"
    manifest["prior_integrated_commit"] = c.BASE
    manifest["prior_integrated_tree"] = c.BASE_TREE
    manifest["runtime_unchanged_since_approved_v8"] = True
    manifest["global_compatibility_evidence_separate"] = True
    manifest["live_provider_payment_or_cron_approved"] = False
    manifest["operator_installed_acceptance"] = False
    if len(c.members()) != manifest["file_count"] or evidence["runtime_changes_vs_v8"]:
        raise RuntimeError("Unexpected V9 runtime or legacy provenance")
    return archive, manifest


def main():
    p = argparse.ArgumentParser()
    p.add_argument("--output", required=True, type=Path)
    args = p.parse_args()
    output = args.output.resolve()
    manifest_path = Path(str(output) + ".manifest.json")
    if output.suffix != ".zip" or output == c.ROOT or c.ROOT in output.parents:
        raise RuntimeError("Package destination must be outside the source repository")
    if output.exists() or manifest_path.exists():
        raise RuntimeError("Never overwrite an existing exact-HEAD package")
    if subprocess.check_output(["git", "-C", str(c.ROOT), "status", "--porcelain"]).strip():
        raise RuntimeError("V9 package requires an exactly clean committed worktree")
    archive, manifest = build()
    output.parent.mkdir(parents=True, exist_ok=True)
    with output.open("xb") as f:
        f.write(archive)
    with manifest_path.open("x") as f:
        f.write(json.dumps(manifest, indent=2) + "\n")
    print(json.dumps({k: v for k, v in manifest.items() if k != "files"}))


if __name__ == "__main__":
    main()
