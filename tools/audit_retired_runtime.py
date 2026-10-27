#!/usr/bin/env python3
"""Read-only exact retired-source reconciliation. Emits review evidence, never deletes."""
from __future__ import annotations
import argparse
import hashlib
import json
import os
from pathlib import Path
import verify_installed_runtime as installed

ROOT = Path(__file__).resolve().parents[1]
PATHS = frozenset({"settings/tronChecker.php", "src/Finance/CryptoChecker.php",
                   "src/Finance/Routes/CryptoAndExchange.php"})

def audit(install_dir, metadata, disposition):
    root = Path(install_dir)
    if not root.is_absolute() or root.resolve() != root:
        raise ValueError("canonical installation path required")
    for key in ("installation_id", "operator", "schedule_inspection_reference"):
        if not isinstance(disposition.get(key), str) or not disposition[key].strip():
            raise ValueError("explicit installation/operator/schedule identity required")
    if disposition.get("install_dir") != str(root) or disposition.get("retired_schedule_state") != "none":
        raise ValueError("matching installation and independently inspected absent schedules required")
    manifest_raw = (ROOT/"migrations/post-v9/010_cleanup_manifest.json").read_bytes()
    manifest = json.loads(manifest_raw)
    retired = manifest["retired_files"]
    if set(retired) != PATHS or any(name in metadata["files"] for name in PATHS):
        raise ValueError("exact retired path set required")
    before = root.stat()
    current = installed.verify_install(root, metadata)
    if not current["verified"]:
        raise ValueError("current installed source identity must match trusted release manifest")
    records = []
    root_fd = os.open(root, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        if not installed._same_inode(before, os.fstat(root_fd)):
            raise ValueError("installation changed during audit")
        for name in sorted(PATHS):
            # Securely hash only known exact paths; never read config/data or glob.
            variants = retired[name]
            matched = next((v for v in variants if installed._hash_member(root_fd, name, v) is None), None)
            if matched:
                records.append({"file": name, "status": "known-retired-review-removal",
                                "sha256": matched["sha256"], "source": matched["source"]})
                continue
            # Report absent only on an explicit ENOENT through checked dirfds.
            current_fd = root_fd
            opened = []
            absent = False
            try:
                for component in Path(name).parts[:-1]:
                    current_fd, _ = installed._open_checked(current_fd, component, directory=True)
                    opened.append(current_fd)
                try:
                    os.stat(Path(name).name, dir_fd=current_fd, follow_symlinks=False)
                except FileNotFoundError:
                    absent = True
            except (OSError, installed._UnsafeMember):
                pass
            finally:
                for fd in reversed(opened): os.close(fd)
            records.append({"file": name, "status": "absent" if absent else "refused-unknown-or-unsafe"})
    finally:
        os.close(root_fd)
    if not installed._same_inode(before, root.stat()):
        raise ValueError("installation changed during audit")
    return {"operation": "read-only-retired-file-audit",
            "installation_id": disposition["installation_id"],
            "current_candidate": metadata["candidate"],
            "cleanup_manifest_sha256": hashlib.sha256(manifest_raw).hexdigest(),
            "complete": all(r["status"] != "refused-unknown-or-unsafe" for r in records),
            "files": records, "files_deleted": 0, "schedule_changes": 0,
            "next_step": "review exact paths and source backup under separate deployment authorization"}

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--install-dir", type=Path, required=True)
    parser.add_argument("--manifest", type=Path, required=True)
    parser.add_argument("--trusted-manifest-sha256", required=True)
    parser.add_argument("--expected-commit", required=True)
    parser.add_argument("--disposition", type=Path, required=True)
    args = parser.parse_args()
    metadata = installed.authenticate_manifest(args.manifest.read_bytes(),
        args.trusted_manifest_sha256, args.expected_commit)
    disposition = json.loads(args.disposition.read_text())
    result = audit(args.install_dir, metadata, disposition)
    print(json.dumps(result, sort_keys=True))
    return 0 if result["complete"] else 2

if __name__ == "__main__":
    raise SystemExit(main())
