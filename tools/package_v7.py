#!/usr/bin/env python3
"""Deterministic 65-member source-only AChBot V6.1 package from clean exact Git HEAD."""
from __future__ import annotations
import argparse,hashlib,io,json,subprocess,zipfile
from pathlib import Path
import v7_contract as c
ROOT=c.ROOT

def git(*args):return subprocess.check_output(['git','-C',str(ROOT),*args]).decode().strip()

def build():
    c.verify()
    content={};metadata={}
    for name in c.members():
        file=ROOT/name
        if any(part.is_symlink() for part in [file,*file.parents] if ROOT==part or ROOT in part.parents):
            raise RuntimeError('Symlink packaging prohibited: '+name)
        data=file.read_bytes()
        if len(data)>8*1024*1024:raise RuntimeError('Oversized runtime member: '+name)
        sha=hashlib.sha256(data).hexdigest()
        blob=hashlib.sha1(b'blob '+str(len(data)).encode()+b'\x00'+data).hexdigest()
        if blob!=git('rev-parse','HEAD:'+name):raise RuntimeError('Runtime differs from committed Git HEAD: '+name)
        content[name]=data;metadata[name]={'size':len(data),'sha256':sha,'git_blob_sha1':blob}
    stream=io.BytesIO()
    with zipfile.ZipFile(stream,'w',compression=zipfile.ZIP_DEFLATED,compresslevel=9) as archive:
        for name,data in sorted(content.items()):
            zi=zipfile.ZipInfo(name,(1980,1,1,0,0,0));zi.create_system=3
            zi.external_attr=0o100644<<16;zi.compress_type=zipfile.ZIP_DEFLATED
            archive.writestr(zi,data,compress_type=zipfile.ZIP_DEFLATED,compresslevel=9)
    data=stream.getvalue()
    manifest={'name':'AChBot V7 source-only product plans and provisioning candidate',
        'candidate':git('rev-parse','HEAD'),'tree':git('rev-parse','HEAD^{tree}'),
        'file_count':len(metadata),'files':metadata,
        'zip_sha256':hashlib.sha256(data).hexdigest(),
        'baseInfo_php_included':False,'sql_data_included':False,'mysql_schema_migration_included':False,
        'production_approved':False,'review_complete':False,'owner_merge_approved':False,'deploy_approved':False}
    return data,manifest

if __name__=='__main__':
    p=argparse.ArgumentParser();p.add_argument('--output',type=Path,required=True);args=p.parse_args()
    dest=args.output.resolve();manifest_path=Path(str(dest)+'.manifest.json')
    if dest.suffix!='.zip' or ROOT==dest or ROOT in dest.parents:
        raise SystemExit('Output ZIP must be outside repository')
    if dest.exists() or manifest_path.exists():raise SystemExit('Never overwrite an existing candidate')
    if subprocess.check_output(['git','-C',str(ROOT),'status','--porcelain']).strip():
        raise SystemExit('Cannot package a dirty candidate')
    archive,manifest=build();dest.parent.mkdir(parents=True,exist_ok=True)
    with dest.open('xb') as f:f.write(archive)
    with manifest_path.open('x') as f:f.write(json.dumps(manifest,indent=2)+'\n')
    print(json.dumps({k:v for k,v in manifest.items() if k!='files'}))
