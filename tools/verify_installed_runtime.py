#!/usr/bin/env python3
"""Read-only AChBot installed-source identity check against a release manifest.

No database, PHP include/execute, webhook request, network call, or private
configuration read is performed. Matching disk files do not prove that the
active Telegram webhook or web PHP handler (LSAPI/PHP-FPM) serves these files.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import stat
import sys
from pathlib import Path

SHA1 = re.compile(r"[0-9a-f]{40}\Z")
SHA256 = re.compile(r"[0-9a-f]{64}\Z")
CRITICAL = ("bot.php", "config.php")


class _UnsafeMember(Exception):
    def __init__(self, reason):
        super().__init__(reason)
        self.reason = reason


def authenticate_manifest(raw: bytes, trusted_sha256: str, expected_commit=None):
    """Accept the raw manifest only against a separately trusted SHA256 digest."""
    if not isinstance(trusted_sha256, str) or not SHA256.fullmatch(trusted_sha256):
        raise ValueError("trusted manifest SHA256 must contain 64 lowercase hex characters")
    if hashlib.sha256(raw).hexdigest() != trusted_sha256:
        raise ValueError("manifest SHA256 differs from independent trusted digest")
    metadata = json.loads(raw.decode("utf-8"))
    _manifest_files(metadata)
    if expected_commit is not None and metadata["candidate"] != expected_commit:
        raise ValueError("manifest is not the requested release commit")
    return metadata


def _manifest_files(metadata):
    if not isinstance(metadata, dict):
        raise ValueError("manifest must be a JSON object")
    if not isinstance(metadata.get("candidate"), str) or not SHA1.fullmatch(metadata["candidate"]):
        raise ValueError("manifest has no exact source commit")
    if not isinstance(metadata.get("tree"), str) or not SHA1.fullmatch(metadata["tree"]):
        raise ValueError("manifest has no exact source tree")
    if metadata.get("private_config_included") is not False or metadata.get("sql_data_included") is not False:
        raise ValueError("not a source-only runtime manifest")
    files = metadata.get("files")
    if not isinstance(files, dict) or not files or metadata.get("file_count") != len(files):
        raise ValueError("invalid source file inventory")
    if any(f not in files for f in CRITICAL):
        raise ValueError("bot.php or config.php missing from source inventory")
    for name, record in files.items():
        if not isinstance(name, str) or not name or "\\" in name:
            raise ValueError("invalid source file path")
        rel = Path(name)
        if (rel.is_absolute() or not rel.parts or ".." in rel.parts or
                "." in rel.parts or rel.as_posix() != name or
                any(part.startswith(".") for part in rel.parts) or
                rel.name == "baseInfo.php" or rel.suffix.lower() == ".sql"):
            raise ValueError("unsafe source inventory path")
        if (not isinstance(record, dict) or type(record.get("size")) is not int or
                record["size"] < 0 or not isinstance(record.get("sha256"), str) or
                not SHA256.fullmatch(record["sha256"])):
            raise ValueError("invalid source manifest file metadata")
    return files



def _same_inode(a, b):
    return (a.st_dev, a.st_ino) == (b.st_dev, b.st_ino)


def _file_fingerprint(value):
    return (value.st_dev, value.st_ino, value.st_mode, value.st_size,
            value.st_mtime_ns, value.st_ctime_ns)


def _stat_entry(parent_fd, name):
    try:
        return os.stat(name, dir_fd=parent_fd, follow_symlinks=False)
    except (FileNotFoundError, NotADirectoryError):
        raise _UnsafeMember("missing_or_not_regular_file") from None
    except OSError:
        raise _UnsafeMember("unreadable") from None


def _open_checked(parent_fd, name, directory):
    """Bind a checked component to one descriptor, never following symlinks."""
    before = _stat_entry(parent_fd, name)
    if stat.S_ISLNK(before.st_mode):
        raise _UnsafeMember("symlink_rejected")
    if not (stat.S_ISDIR(before.st_mode) if directory else stat.S_ISREG(before.st_mode)):
        raise _UnsafeMember("missing_or_not_regular_file")
    flags = os.O_RDONLY | os.O_NOFOLLOW | getattr(os, "O_CLOEXEC", 0)
    flags |= os.O_DIRECTORY if directory else os.O_NONBLOCK
    try:
        fd = os.open(name, flags, dir_fd=parent_fd)
    except OSError:
        # Includes an intervening symlink, renamed component or permission change.
        raise _UnsafeMember("unstable_path") from None
    after = os.fstat(fd)
    if (not _same_inode(before, after) or
            not (stat.S_ISDIR(after.st_mode) if directory else stat.S_ISREG(after.st_mode)) or
            (not directory and _file_fingerprint(before) != _file_fingerprint(after))):
        os.close(fd)
        raise _UnsafeMember("unstable_path")
    return fd, before


def _hash_member(root_fd, name, record):
    """Open each component via dirfd; recheck inode/path and content stability."""
    owned_fds = []
    snapshots = []
    current = root_fd
    try:
        parts = Path(name).parts
        for component in parts[:-1]:
            fd, opened = _open_checked(current, component, directory=True)
            snapshots.append((current, component, opened))
            owned_fds.append(fd)
            current = fd
        leaf_fd, opened = _open_checked(current, parts[-1], directory=False)
        owned_fds.append(leaf_fd)
        snapshots.append((current, parts[-1], opened))
        reason = None
        if opened.st_size != record["size"]:
            reason = "size_mismatch"
        else:
            digest = hashlib.sha256()
            while True:
                chunk = os.read(leaf_fd, 1024 * 1024)
                if not chunk:
                    break
                digest.update(chunk)
            if digest.hexdigest() != record["sha256"]:
                reason = "sha256_mismatch"

        if _file_fingerprint(os.fstat(leaf_fd)) != _file_fingerprint(opened):
            raise _UnsafeMember("unstable_file")
        for index, (parent_fd, component, original) in enumerate(snapshots):
            current_entry = _stat_entry(parent_fd, component)
            if (not _same_inode(current_entry, original) or
                    stat.S_ISLNK(current_entry.st_mode) or
                    (index == len(snapshots) - 1 and
                     _file_fingerprint(current_entry) != _file_fingerprint(original))):
                raise _UnsafeMember("unstable_path")
        return reason
    except _UnsafeMember as exc:
        return exc.reason
    except OSError:
        return "unreadable"
    finally:
        for fd in reversed(owned_fds):
            os.close(fd)


def verify_install(install_dir, metadata, expected_commit=None):
    """Verify manifest-listed disk files only; return a redacted, bounded report."""
    files = _manifest_files(metadata)  # Validate *before* reading any files.
    if expected_commit is not None and metadata["candidate"] != expected_commit:
        raise ValueError("manifest is not the requested release commit")
    if (not all(hasattr(os, key) for key in ("O_NOFOLLOW", "O_DIRECTORY", "O_NONBLOCK")) or
            os.open not in os.supports_dir_fd or os.stat not in os.supports_dir_fd or
            os.stat not in os.supports_follow_symlinks):
        raise ValueError("secure Linux descriptor-relative no-follow checks are required")
    root = Path(install_dir)
    try:
        root_before = root.lstat()
        if not stat.S_ISDIR(root_before.st_mode):
            raise ValueError("installation root must be a real directory, not a symlink")
        root_fd = os.open(root, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    except OSError:
        raise ValueError("installation root is unavailable or changed") from None

    try:
        if not _same_inode(root_before, os.fstat(root_fd)):
            raise ValueError("installation root changed during verification")
        errors = []
        good = 0
        good_files = set()
        for name, record in files.items():
            reason = _hash_member(root_fd, name, record)
            if reason is None:
                good += 1
                good_files.add(name)
            else:
                errors.append({"file": name, "reason": reason})
        try:
            root_after = root.lstat()
        except OSError:
            raise ValueError("installation root changed during verification") from None
        if not _same_inode(root_before, root_after):
            raise ValueError("installation root changed during verification")
    finally:
        os.close(root_fd)

    return {
        "status": "MATCH" if not errors else "MISMATCH",
        "source_commit": metadata["candidate"],
        "source_tree": metadata["tree"],
        "matched": good,
        "expected": len(files),
        "core_files_match": all(name in good_files for name in CRITICAL),
        "differences": errors,
        "verified": not errors,
        "active_webhook_verified": False,
        "web_sapi_opcache_verified": False,
        "database_schema_verified": False,
        "private_config_read": False,
    }


def main():
    parser = argparse.ArgumentParser(
        description="Read-only compare installed AChBot runtime files to the official source-only manifest."
    )
    parser.add_argument("--install-dir", required=True, type=Path, help="Actual PHP webhook document directory")
    parser.add_argument("--manifest", required=True, type=Path, help="Published runtime ZIP .manifest.json")
    parser.add_argument("--expect-commit", help="Require exact source commit SHA from the release")
    parser.add_argument("--trusted-manifest-sha256", required=True,
                        help="Independent trusted SHA256 of the raw published manifest")
    parser.add_argument("--json", action="store_true", help="Print machine-readable redacted report")
    args = parser.parse_args()

    try:
        if args.expect_commit and not SHA1.fullmatch(args.expect_commit):
            raise ValueError("expect-commit must be a 40-character Git SHA")
        metadata = authenticate_manifest(args.manifest.read_bytes(),
                                         args.trusted_manifest_sha256, args.expect_commit)
        report = verify_install(args.install_dir, metadata, args.expect_commit)
        report["trusted_manifest_sha256_verified"] = True
    except (OSError, ValueError) as exc:
        print("INSTALL-CHECK INVALID: " + str(exc), file=sys.stderr)
        return 2

    if args.json:
        print(json.dumps(report, ensure_ascii=False, sort_keys=True))
    else:
        print("Trusted manifest SHA256: VERIFIED")
        print("Manifest-listed disk source:", report["status"])
        print("Commit:", report["source_commit"])
        print("Matching files:", str(report["matched"]) + "/" + str(report["expected"]))
        print("bot.php and config.php both match:", "yes" if report["core_files_match"] else "no")
        for mismatch in report["differences"][:20]:
            print("  " + mismatch["file"] + ": " + mismatch["reason"])
        if len(report["differences"]) > 20:
            print("  ... " + str(len(report["differences"]) - 20) + " additional file mismatches")
        print("WARNING: This checks disk source only, not the live Telegram webhook or web-SAPI OPcache (LSAPI/PHP-FPM).")
        print("WARNING: No SQL migrations, private config, credentials or runtime actions were accessed.")
    return 0 if report["verified"] else 1


if __name__ == "__main__":
    sys.exit(main())
