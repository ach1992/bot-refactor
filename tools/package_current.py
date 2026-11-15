#!/usr/bin/env python3
"""Build and verify the exact committed Stable runtime without V0-V9 contracts.

The current tracked PHP entrypoints and application directories define the
runtime. No archived source, legacy V9 member inventory, old baseInfo template,
customer data, SQL migrations, or private config is read.
"""
from __future__ import annotations

import argparse
from datetime import datetime, timezone
import hashlib
import io
import json
import os
from pathlib import Path
import re
import subprocess
import zipfile

ROOT = Path(__file__).resolve().parents[1]
ROOT_FILES = frozenset({
    "baseInfo.example.php", "bot.php", "config.php", "health.php", "index.php"
})
RUNTIME_DIRS = ("src/", "pay/", "phpqrcode/", "settings/", "messages/")


def git(*arguments: str) -> bytes:
    return subprocess.check_output(["git", "-C", str(ROOT), *arguments])


def sha256(value: bytes) -> str:
    return hashlib.sha256(value).hexdigest()


def identity() -> tuple[str, str, tuple[int, int, int, int, int, int]]:
    head = git("rev-parse", "HEAD").decode().strip()
    tree = git("rev-parse", "HEAD^{tree}").decode().strip()
    if not re.fullmatch("[0-9a-f]{40}", head) or not re.fullmatch("[0-9a-f]{40}", tree):
        raise ValueError("Expected a Git SHA1 HEAD/tree")
    if git("status", "--porcelain"):
        raise RuntimeError("Only a clean committed checkout can produce a Stable runtime")
    epoch = int(git("show", "-s", "--format=%ct", head))
    dt = datetime.fromtimestamp(epoch, timezone.utc)
    if not 1980 <= dt.year <= 2107:
        raise ValueError("Commit time cannot be represented in a ZIP timestamp")
    stamp = (dt.year, dt.month, dt.day, dt.hour, dt.minute, dt.second // 2 * 2)
    return head, tree, stamp


def members() -> list[str]:
    """Read current HEAD's tracked runtime members, not historical manifests."""
    entries = git("ls-tree", "-rz", "--full-tree", "HEAD").split(b"\0")
    names = set()
    for entry in entries:
        if not entry:
            continue
        meta, name = entry.split(b"\t", 1)
        mode, kind, _ = meta.split(b" ")
        if name.startswith(b"/") or b"\\" in name or b".." in name.split(b"/"):
            raise RuntimeError("Unsafe committed path")
        path = name.decode("utf-8")
        if path in ROOT_FILES or (path.endswith(".php") and path.startswith(RUNTIME_DIRS)):
            if mode != b"100644" and mode != b"100755" or kind != b"blob":
                raise RuntimeError("Runtime member is not a regular Git blob: " + path)
            if path == "baseInfo.php":
                raise RuntimeError("Private runtime config cannot be packaged")
            names.add(path)
    if not ROOT_FILES.issubset(names):
        raise RuntimeError("Missing core Stable PHP entrypoint")
    if not any(n.startswith("messages/") for n in names):
        raise RuntimeError("Missing Stable user/admin language catalog")
    if not any(n.startswith("src/") for n in names):
        raise RuntimeError("Missing Stable application source")
    if (ROOT / "baseInfo.php").exists():
        raise RuntimeError("Private baseInfo.php is present in source checkout")
    if any((ROOT / name).is_symlink() or not (ROOT / name).is_file() for name in names):
        raise RuntimeError("Missing or symlinked runtime source file")
    return sorted(names)


def build() -> tuple[bytes, dict]:
    head, tree, stamp = identity()
    files = {}
    binary = io.BytesIO()
    with zipfile.ZipFile(binary, "w", compression=zipfile.ZIP_DEFLATED,
                         compresslevel=9) as archive:
        for name in members():
            data = (ROOT / name).read_bytes()
            obj = hashlib.sha1(b"blob " + str(len(data)).encode() + b"\0" + data).hexdigest()
            actual = git("rev-parse", "HEAD:" + name).decode().strip()
            if obj != actual:
                raise RuntimeError("Uncommitted runtime bytes: " + name)
            files[name] = {"size": len(data), "sha256": sha256(data),
                           "git_blob_sha1": obj}
            info = zipfile.ZipInfo(name, stamp)
            info.create_system = 3
            info.external_attr = 0o100644 << 16
            info.compress_type = zipfile.ZIP_DEFLATED
            archive.writestr(info, data, compress_type=zipfile.ZIP_DEFLATED,
                             compresslevel=9)
    zipped = binary.getvalue()
    report = {
        "name": "AChBot Stable current-source-only runtime",
        "candidate": head,
        "tree": tree,
        "zip_timestamp_utc": list(stamp),
        "file_count": len(files),
        "zip_sha256": sha256(zipped),
        "files": files,
        "private_config_included": False,
        "sql_data_included": False,
        "schema_migration_included": False,
        "production_approved": False,
        "deploy_approved": False,
    }
    return zipped, report


def verify(path: Path, manifest_path: Path) -> dict:
    _, expected = build()
    actual = json.loads(manifest_path.read_text(encoding="utf-8"))
    if actual != expected:
        raise ValueError("Source manifest does not match exact committed Stable checkout")
    zipped = path.read_bytes()
    if sha256(zipped) != expected["zip_sha256"]:
        raise ValueError("Runtime ZIP content differs from committed manifest")
    with zipfile.ZipFile(io.BytesIO(zipped)) as archive:
        if archive.namelist() != list(expected["files"]):
            raise ValueError("Runtime ZIP has missing, extra, or unordered members")
        for member in archive.infolist():
            if member.is_dir() or member.date_time != tuple(expected["zip_timestamp_utc"]):
                raise ValueError("Runtime member type/timestamp mismatch")
            if sha256(archive.read(member.filename)) != expected["files"][member.filename]["sha256"]:
                raise ValueError("Runtime member digest mismatch: " + member.filename)
    return {"verified": True, "candidate": expected["candidate"],
            "file_count": expected["file_count"], "zip_sha256": sha256(zipped)}


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    cmd = parser.add_subparsers(dest="action", required=True)
    make = cmd.add_parser("create")
    make.add_argument("--output", type=Path, required=True)
    check = cmd.add_parser("verify")
    check.add_argument("--zip", type=Path, required=True)
    check.add_argument("--manifest", type=Path, required=True)
    show = cmd.add_parser("inventory")
    args = parser.parse_args()
    if args.action == "inventory":
        print(json.dumps({"files": members()}, ensure_ascii=False))
    elif args.action == "create":
        output = args.output.resolve()
        manifest = Path(str(output) + ".manifest.json")
        if (output.suffix != ".zip" or output.exists() or manifest.exists()
                or output == ROOT or ROOT in output.parents):
            raise ValueError("Create only a fresh ZIP outside the checkout")
        payload, info = build()
        output.parent.mkdir(parents=True, exist_ok=True)
        with output.open("xb") as fp:
            fp.write(payload)
        with manifest.open("x", encoding="utf-8") as fp:
            json.dump(info, fp, ensure_ascii=False, indent=2)
            fp.write("\n")
        print(json.dumps({k: v for k, v in info.items() if k != "files"}))
    else:
        print(json.dumps(verify(args.zip, args.manifest)))


if __name__ == "__main__":
    main()
