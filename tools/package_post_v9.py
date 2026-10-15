#!/usr/bin/env python3
"""Marzban-only source-only ZIP with SHA/TREE-bound all-file source manifest.

Not a deployment operation, installer, or production/payment attestation.
Historical V9 packaging is immutable and deliberately not reused.
"""
from __future__ import annotations

import argparse
import hashlib
import io
import json
import subprocess
import zipfile
from pathlib import Path

import v9_contract as historical

ROOT = Path(__file__).resolve().parents[1]
V9 = "7eb077fd8e6d0a303421626d1500f69e295a6664"


def git(*args: str) -> bytes:
    return subprocess.check_output(["git", "-C", str(ROOT), *args])


def digest(raw: bytes) -> str:
    return hashlib.sha256(raw).hexdigest()


def identity() -> tuple[str, str]:
    head = git("rev-parse","HEAD").decode().strip()
    tree = git("rev-parse","HEAD^{tree}").decode().strip()
    if git("status","--porcelain"):
        raise RuntimeError("Must have exact committed, clean source tree")
    return head, tree


def members() -> list[str]:
    paths = historical.members()
    if len(paths)!=92 or len(set(paths))!=92:
        raise RuntimeError("Historical source file-membership drift")
    for path in paths:
        if path=="baseInfo.php" or not (ROOT/path).is_file() or (ROOT/path).is_symlink():
            raise RuntimeError("Illegal/missing runtime member: "+path)
    if (ROOT/"baseInfo.php").exists():
        raise RuntimeError("Real private config exists in checkout")
    for path in ["database/legacy-schema.sql","baseInfo.example.php"]:
        if (ROOT/path).read_bytes()!=git("show",V9+":"+path):
            raise RuntimeError("Original schema or fake private example changed")
    return paths


def build() -> tuple[bytes, dict]:
    head,tree=identity()
    names=members()
    file_map={}
    mem=io.BytesIO()
    with zipfile.ZipFile(mem,"w",compression=zipfile.ZIP_DEFLATED,compresslevel=9) as archive:
        for name in names:
            raw=(ROOT/name).read_bytes()
            blob=hashlib.sha1(b"blob "+str(len(raw)).encode()+b"\0"+raw).hexdigest()
            expected=git("rev-parse","HEAD:"+name).decode().strip()
            if blob!=expected:
                raise RuntimeError("Uncommitted/untracked byte difference: "+name)
            file_map[name]={"size":len(raw),"sha256":digest(raw),"git_blob_sha1":blob}
            info=zipfile.ZipInfo(name,(1980,1,1,0,0,0))
            info.create_system=3
            info.external_attr=0o100644<<16
            info.compress_type=zipfile.ZIP_DEFLATED
            archive.writestr(info,raw,compress_type=zipfile.ZIP_DEFLATED,compresslevel=9)
    data=mem.getvalue()
    metadata={
        "name":"AChBot post-V9 Marzban-only source-only development candidate",
        "candidate":head,"tree":tree,"historical_base":V9,
        "file_count":len(names),"zip_sha256":digest(data),
        "files":file_map,
        "legacy_xui_removed":True,
        "schema_migration_included":False,
        "private_config_included":False,
        "sql_data_included":False,
        "real_payment_tested_or_approved":False,
        "operator_installed_acceptance":False,
        "production_approved":False,
        "deploy_approved":False,
    }
    return data,metadata


def verify(path: Path, manifest_path: Path) -> dict:
    head,tree=identity()
    metadata=json.loads(manifest_path.read_text())
    expected_flags={"schema_migration_included":False,
        "private_config_included":False,"sql_data_included":False,
        "real_payment_tested_or_approved":False,
        "operator_installed_acceptance":False,
        "production_approved":False,"deploy_approved":False,
        "legacy_xui_removed":True}
    if (metadata.get("candidate")!=head or metadata.get("tree")!=tree
        or metadata.get("historical_base")!=V9):
        raise RuntimeError("Source identity mismatch")
    if any(metadata.get(k)!=v for k,v in expected_flags.items()):
        raise RuntimeError("Source-only package safety claim mismatch")
    names=members()
    if metadata.get("file_count")!=len(names) or list(metadata.get("files",{}))!=names:
        raise RuntimeError("Package manifest source inventory mismatch")
    raw=path.read_bytes()
    if digest(raw)!=metadata.get("zip_sha256"):
        raise RuntimeError("Runtime ZIP SHA mismatch")
    with zipfile.ZipFile(io.BytesIO(raw)) as archive:
        if archive.namelist()!=names:
            raise RuntimeError("Unexpected ZIP source inventory/order")
        for item in archive.infolist():
            if item.is_dir() or item.filename.startswith("/") or ".." in Path(item.filename).parts:
                raise RuntimeError("Unsafe ZIP member")
            field=metadata["files"][item.filename]
            current=(ROOT/item.filename).read_bytes()
            blob=git("rev-parse","HEAD:"+item.filename).decode().strip()
            data=archive.read(item.filename)
            if not (data==current and digest(data)==field["sha256"]
                    and len(data)==field["size"] and blob==field["git_blob_sha1"]):
                raise RuntimeError("ZIP member blob mismatch: "+item.filename)
    return {"verified":True,"candidate":head,"tree":tree,
            "file_count":len(names),"zip_sha256":digest(raw)}


def main():
    ap=argparse.ArgumentParser()
    cmd=ap.add_subparsers(dest="command",required=True)
    make=cmd.add_parser("create")
    make.add_argument("--output",required=True,type=Path)
    check=cmd.add_parser("verify")
    check.add_argument("--zip",required=True,type=Path)
    check.add_argument("--manifest",required=True,type=Path)
    args=ap.parse_args()
    if args.command=="create":
        output=args.output.resolve()
        manifest=Path(str(output)+".manifest.json")
        if (output.suffix!=".zip" or output.exists() or manifest.exists()
                or ROOT==output or ROOT in output.parents):
            raise RuntimeError("Create only a new outside-checkout archive")
        data,fields=build()
        output.parent.mkdir(parents=True,exist_ok=True)
        with output.open("xb") as f:f.write(data)
        with manifest.open("x") as f:f.write(json.dumps(fields,indent=2)+"\n")
        print(json.dumps({k:v for k,v in fields.items() if k!="files"}))
    else:
        print(json.dumps(verify(args.zip,args.manifest)))


if __name__=="__main__":main()
