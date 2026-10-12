#!/usr/bin/env python3
"""Build a reproducible staging-only runtime ZIP; never include local configuration/state."""
from __future__ import annotations
import argparse
import hashlib
import io
import json
import subprocess
import zipfile
from pathlib import Path
from v1_contract import ROOT, MODULES, reference, verify


def build_bytes(root: Path, lock: dict) -> tuple[bytes, dict]:
    files=sorted([f for f in lock['files'] if not f.startswith('database/')]+list(MODULES))
    if len(files)!=23 or 'baseInfo.php' in files: raise RuntimeError('Runtime allowlist drift')
    contents={}
    for file in files:
        path=root/file
        if not path.is_file() or path.is_symlink(): raise RuntimeError('Missing/unsafe runtime file: '+file)
        contents[file]=path.read_bytes()
    buf=io.BytesIO()
    with zipfile.ZipFile(buf,'w',compression=zipfile.ZIP_DEFLATED,compresslevel=9) as archive:
        for file,content in contents.items():
            entry=zipfile.ZipInfo(file,(1980,1,1,0,0,0)); entry.create_system=3
            entry.external_attr=0o100644 << 16; entry.compress_type=zipfile.ZIP_DEFLATED
            archive.writestr(entry,content,compress_type=zipfile.ZIP_DEFLATED,compresslevel=9)
    manifest={f:hashlib.sha256(b).hexdigest() for f,b in contents.items()}
    return buf.getvalue(),manifest


def main():
    ap=argparse.ArgumentParser(description=__doc__)
    ap.add_argument('--output',required=True,type=Path)
    args=ap.parse_args(); out=args.output.resolve(); meta=Path(str(out)+'.manifest.json')
    if out.suffix!='.zip' or out==ROOT or ROOT in out.parents:
        raise SystemExit('Choose a .zip output outside the repository')
    if out.exists() or meta.exists(): raise SystemExit('Refusing to replace an existing artifact')
    if subprocess.check_output(['git','status','--porcelain'],cwd=ROOT):
        raise SystemExit('Commit the reviewed candidate before building an identified artifact')
    with reference() as (old,lock):
        verify(ROOT,old,lock); data,files=build_bytes(ROOT,lock)
    sha=subprocess.check_output(['git','rev-parse','HEAD'],cwd=ROOT,text=True).strip()
    tree=subprocess.check_output(['git','rev-parse','HEAD^{tree}'],cwd=ROOT,text=True).strip()
    manifest={'candidate':sha,'tree':tree,'zip_sha256':hashlib.sha256(data).hexdigest(),'files':files,
              'purpose':'staging-only; independent review and operator acceptance are external gates',
              'production_approved':False,'baseInfo_php_included':False,'database_sql_included':False}
    out.parent.mkdir(parents=True,exist_ok=True)
    with out.open('xb') as f: f.write(data)
    with meta.open('x') as f: f.write(json.dumps(manifest,indent=2)+'\n')
    print(json.dumps({'candidate':sha,'files':len(files),'zip_sha256':manifest['zip_sha256'],'output':str(out)}))


if __name__=='__main__': main()
