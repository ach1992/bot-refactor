#!/usr/bin/env python3
"""Reproducible V2 runtime artifact; no install, real configuration or database payload."""
from __future__ import annotations
import argparse,hashlib,io,json,subprocess,zipfile
from pathlib import Path
from v2_contract import ROOT,previous,verify,runtime_files

def build_bytes(root:Path,lock:dict):
    paths=runtime_files(lock)
    if len(paths)!=26 or 'baseInfo.php' in paths:raise RuntimeError('V2 runtime allowlist drift')
    out=io.BytesIO();hashes={}
    with zipfile.ZipFile(out,'w',compression=zipfile.ZIP_DEFLATED,compresslevel=9) as z:
        for file in paths:
            p=root/file
            if not p.is_file() or any(q.is_symlink() for q in [p,*p.parents] if q!=root and root in q.parents):raise RuntimeError('Unsafe runtime member '+file)
            content=p.read_bytes();hashes[file]=hashlib.sha256(content).hexdigest()
            info=zipfile.ZipInfo(file,(1980,1,1,0,0,0));info.create_system=3;info.external_attr=0o100644<<16;info.compress_type=zipfile.ZIP_DEFLATED
            z.writestr(info,content,compress_type=zipfile.ZIP_DEFLATED,compresslevel=9)
    return out.getvalue(),hashes

def main():
    ap=argparse.ArgumentParser(description=__doc__);ap.add_argument('--output',type=Path,required=True);args=ap.parse_args()
    out=args.output.resolve();meta=Path(str(out)+'.manifest.json')
    if out.suffix!='.zip' or out==ROOT or ROOT in out.parents:raise SystemExit('ZIP output must be outside repository')
    if out.exists() or meta.exists():raise SystemExit('Refusing existing output')
    if subprocess.check_output(['git','status','--porcelain'],cwd=ROOT):raise SystemExit('Use a clean committed checkout')
    with previous() as (old,lock):verify(ROOT,old,lock);data,files=build_bytes(ROOT,lock)
    sha=subprocess.check_output(['git','rev-parse','HEAD'],cwd=ROOT,text=True).strip();tree=subprocess.check_output(['git','rev-parse','HEAD^{tree}'],cwd=ROOT,text=True).strip()
    manifest={'candidate':sha,'tree':tree,'zip_sha256':hashlib.sha256(data).hexdigest(),'files':files,'purpose':'staging-only runtime; not production approval',
      'production_approved':False,'baseInfo_php_included':False,'database_sql_included':False}
    out.parent.mkdir(parents=True,exist_ok=True)
    with out.open('xb') as f:f.write(data)
    with meta.open('x') as f:f.write(json.dumps(manifest,indent=2)+'\n')
    print(json.dumps({'candidate':sha,'tree':tree,'files':len(files),'zip_sha256':manifest['zip_sha256'],'output':str(out)}))
if __name__=='__main__':main()
