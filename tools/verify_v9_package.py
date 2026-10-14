#!/usr/bin/env python3
"""Verify V9 exact SHA/TREE, all 92 source members and release restrictions."""
from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path

import v9_contract as c
import verify_v8_package


def verify(zip_path, manifest_path):
    state = c.verify()
    manifest = json.loads(Path(manifest_path).read_text())
    required = {
        "prior_integrated_release": "v0.8.0-rc.1",
        "prior_integrated_commit": c.BASE,
        "prior_integrated_tree": c.BASE_TREE,
        "runtime_unchanged_since_approved_v8": True,
        "global_compatibility_evidence_separate": True,
        "live_provider_payment_or_cron_approved": False,
        "operator_installed_acceptance": False,
    }
    if any(manifest.get(k) != v for k, v in required.items()):
        raise RuntimeError("V9 manifest provenance, runtime or safety claims invalid")
    result = verify_v8_package.verify(zip_path, manifest_path)
    if len(c.members()) != 92 or state["runtime_changes_vs_v8"]:
        raise RuntimeError("V9 source contract not verified")
    if hashlib.sha256(Path(zip_path).read_bytes()).hexdigest() != manifest["zip_sha256"]:
        raise RuntimeError("Release ZIP hash mismatch")
    return result


if __name__ == "__main__":
    p = argparse.ArgumentParser()
    p.add_argument("--zip", required=True)
    p.add_argument("--manifest", required=True)
    a = p.parse_args()
    print(json.dumps(verify(a.zip, a.manifest)))
