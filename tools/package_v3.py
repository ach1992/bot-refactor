#!/usr/bin/env python3
"""Build only the complete identified V3 runtime; never copy private state or test tooling."""
from __future__ import annotations
import argparse,hashlib,io,json,subprocess,zipfile
from pathlib import Path
import v3_contract as c
ROOT=c.ROOT

def build_bytes(root:Path,lock:dict):
    paths=c.runtime_files(lock)
    if len(paths)!=32 or len(set(paths))!=len(paths) or 'baseInfo.php' in paths or any(p.endswith('.sql') for p in paths):raise RuntimeError('V3 package allowlist drift')
    contents={}
    for file in paths:
        p=root/file
        if not p.is_file() or any(q.is_symlink() for q in [p,*p.parents] if q!=root and root in q.parents):raise RuntimeError('Unsafe package member: '+file)
        contents[file]=p.read_bytes()
    out=io.BytesIO()
    with zipfile.ZipFile(out,'w',compression=zipfile.ZIP_DEFLATED,compresslevel=9) as z:
        for file,content in contents.items():
            entry=zipfile.ZipInfo(file,(1980,1,1,0,0,0));entry.create_system=3;entry.external_attr=0o100644<<16;entry.compress_type=zipfile.ZIP_DEFLATED
            z.writestr(entry,content,compress_type=zipfile.ZIP_DEFLATED,compresslevel=9)
    return out.getvalue(),{f:hashlib.sha256(b).hexdigest() for f,b in contents.items()}

def main():
    ap=argparse.ArgumentParser(description=__doc__);ap.add_argument('--output',required=True,type=Path);args=ap.parse_args()
    output=args.output.resolve();meta=Path(str(output)+'.manifest.json')
    if output.suffix!='.zip' or output==ROOT or ROOT in output.parents:raise SystemExit('Choose a ZIP outside the repository')
    if output.exists() or meta.exists():raise SystemExit('Existing output will not be overwritten')
    identity=c.identity()
    if identity['worktree_dirty']:raise SystemExit('Build only from a clean committed candidate')
    with c.previous() as (old,lock):c.verify(ROOT,old,lock);data,files=build_bytes(ROOT,lock)
    manifest={'candidate':identity['candidate_head'],'tree':identity['candidate_tree'],'zip_sha256':hashlib.sha256(data).hexdigest(),
        'files':files,'purpose':'staging-only; independent review and operator approval are separate',
        'production_approved':False,'baseInfo_php_included':False,'database_sql_included':False}
    output.parent.mkdir(parents=True,exist_ok=True)
    with output.open('xb') as f:f.write(data)
    with meta.open('x') as f:f.write(json.dumps(manifest,indent=2)+'\n')
    print(json.dumps({k:v for k,v in manifest.items() if k!='files'}|{'files':len(files),'output':str(output)}))
if __name__=='__main__':main()
