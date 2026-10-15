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
import re
import sys
from pathlib import Path

SHA1 = re.compile(r"[0-9a-f]{40}\Z")
SHA256 = re.compile(r"[0-9a-f]{64}\Z")
CRITICAL = ("bot.php", "config.php")


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


def verify_install(install_dir, metadata, expected_commit=None):
    """Verify manifest-listed disk files only; return a redacted, bounded report."""
    files = _manifest_files(metadata)  # Validate *before* reading any files.
    if expected_commit is not None and metadata["candidate"] != expected_commit:
        raise ValueError("manifest is not the requested release commit")
    root = Path(install_dir).resolve(strict=True)
    if not root.is_dir():
        raise ValueError("installation root is not a directory")

    errors = []
    good = 0
    good_files = set()
    for name, record in files.items():
        path = root
        linked = False
        for part in Path(name).parts:
            path = path / part
            if path.is_symlink():
                linked = True
                break
        if linked:
            reason = "symlink_rejected"
        elif not path.is_file():
            reason = "missing_or_not_regular_file"
        else:
            try:
                if path.stat().st_size != record["size"]:
                    reason = "size_mismatch"
                else:
                    digest = hashlib.sha256()
                    with path.open("rb") as handle:
                        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
                            digest.update(chunk)
                    reason = None if digest.hexdigest() == record["sha256"] else "sha256_mismatch"
            except OSError:
                reason = "unreadable"
        if reason is None:
            good += 1
            good_files.add(name)
        else:
            errors.append({"file": name, "reason": reason})

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
    parser.add_argument("--json", action="store_true", help="Print machine-readable redacted report")
    args = parser.parse_args()

    try:
        if args.expect_commit and not SHA1.fullmatch(args.expect_commit):
            raise ValueError("expect-commit must be a 40-character Git SHA")
        metadata = json.loads(args.manifest.read_text(encoding="utf-8"))
        report = verify_install(args.install_dir, metadata, args.expect_commit)
    except (OSError, ValueError) as exc:
        print("INSTALL-CHECK INVALID: " + str(exc), file=sys.stderr)
        return 2

    if args.json:
        print(json.dumps(report, ensure_ascii=False, sort_keys=True))
    else:
        print("AChBot installed source:", report["status"])
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
