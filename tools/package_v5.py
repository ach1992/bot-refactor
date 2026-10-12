#!/usr/bin/env python3
"""Build the source-only AChBot V5 from clean HEAD; verify ZIP members against Git blobs."""
from __future__ import annotations
import argparse
import hashlib
import io
import json
import subprocess
import zipfile
from pathlib import Path
import v5_contract as c

ROOT=c.ROOT


def git(*args:str, data:bytes|None=None)->bytes:
    process=subprocess.run(['git','-C',str(ROOT),*args],input=data,capture_output=True,check=True)
    return process.stdout


def verified_members(root:Path, *, assert_git:bool):
    members=c.runtime_files()
    if len(members)!=43 or len(members)!=len(set(members)):
        raise RuntimeError('Package member count/uniqueness drift')
    if 'baseInfo.php' in members or any(s.endswith('.sql') or s.startswith(('tests/','tools/','.git/','docs/')) for s in members):
        raise RuntimeError('Private state / evidence in runtime package allowlist')
    result={}
    for name in members:
        file=root/name
        if not file.is_file() or file.is_symlink() or any(p.is_symlink() for p in file.parents if p!=root and root in p.parents):
            raise RuntimeError('Unsafe/unavailable package member: '+name)
        content=file.read_bytes()
        if len(content)>8*1024*1024:raise RuntimeError('Unexpected oversized runtime member: '+name)
        sha256=hashlib.sha256(content).hexdigest()
        blob=hashlib.sha1(b'blob '+str(len(content)).encode()+b'\0'+content).hexdigest()
        if assert_git:
            recorded=git('rev-parse','HEAD:'+name).decode().strip()
            if blob!=recorded or git('show','HEAD:'+name)!=content:
                raise RuntimeError('HEAD blob verification failed: '+name)
        result[name]={'sha256':sha256,'git_blob_sha1':blob,'size':len(content),'bytes':content}
    return result


def build_bytes(root:Path=ROOT, *, assert_git:bool=False):
    content=verified_members(root,assert_git=assert_git)
    out=io.BytesIO()
    with zipfile.ZipFile(out,'w',compression=zipfile.ZIP_DEFLATED,compresslevel=9) as archive:
        for name,info in sorted(content.items()):
            member=zipfile.ZipInfo(name,(1980,1,1,0,0,0))
            member.create_system=3
            member.external_attr=0o100644<<16
            member.compress_type=zipfile.ZIP_DEFLATED
            archive.writestr(member,info['bytes'],compress_type=zipfile.ZIP_DEFLATED,compresslevel=9)
    data=out.getvalue()
    with zipfile.ZipFile(io.BytesIO(data)) as archive:
        names=archive.namelist()
        if names!=sorted(content):raise RuntimeError('ZIP member mismatch')
        for name in names:
            if hashlib.sha256(archive.read(name)).hexdigest()!=content[name]['sha256']:
                raise RuntimeError('ZIP data mismatch: '+name)
    manifest={k:{field:value for field,value in info.items() if field!='bytes'} for k,info in sorted(content.items())}
    return data,manifest


def main():
    ap=argparse.ArgumentParser(description=__doc__)
    ap.add_argument('--output',type=Path,required=True)
    args=ap.parse_args()
    c.verify()
    output=args.output.resolve()
    meta=Path(str(output)+'.manifest.json')
    if output.suffix!='.zip' or output==ROOT or ROOT in output.parents:
        raise SystemExit('Choose an outside-repository ZIP output path')
    if output.exists() or meta.exists():raise SystemExit('Refusing to overwrite an existing ZIP/manifest')
    head=git('rev-parse','HEAD').decode().strip()
    tree=git('rev-parse','HEAD^{tree}').decode().strip()
    if git('status','--porcelain').strip():raise SystemExit('Only clean committed HEAD may be packaged')
    data,files=build_bytes(ROOT,assert_git=True)
    manifest={'name':'AChBot V5 source-only runtime', 'candidate':head,'tree':tree,
        'zip_sha256':hashlib.sha256(data).hexdigest(),'files':files,
        'file_count':len(files),'baseInfo_php_included':False,'sql_data_included':False,
        'production_approved':False,'purpose':'isolated operator evaluation, independent review pending'}
    output.parent.mkdir(parents=True,exist_ok=True)
    with output.open('xb') as f:f.write(data)
    with meta.open('x') as f:f.write(json.dumps(manifest,ensure_ascii=False,indent=2)+'\n')
    print(json.dumps({k:v for k,v in manifest.items() if k!='files'}|{'path':str(output)}))

if __name__=='__main__':main()
