#!/usr/bin/env python3
"""Verify exact V6.1 ZIP against manifest and committed candidate Git blob IDs."""
from __future__ import annotations
import argparse,hashlib,io,json,subprocess,zipfile
from pathlib import Path
import backup_hotfix_contract as c
ROOT=c.ROOT

def git(*args):return subprocess.check_output(['git','-C',str(ROOT),*args]).decode().strip()
def verify(archive_path,manifest_path):
    meta=json.loads(Path(manifest_path).read_text());data=Path(archive_path).read_bytes()
    if meta['candidate']!=git('rev-parse','HEAD') or meta['tree']!=git('rev-parse','HEAD^{tree}'):
        raise RuntimeError('HEAD/tree drift')
    if meta['zip_sha256']!=hashlib.sha256(data).hexdigest():raise RuntimeError('ZIP digest mismatch')
    if meta['file_count']!=54 or set(meta['files'])!=set(c.members()):raise RuntimeError('ZIP member allowlist mismatch')
    if meta['baseInfo_php_included'] or meta['sql_data_included'] or meta['production_approved'] or meta['review_complete']:
        raise RuntimeError('Unacceptable release manifest assertions')
    with zipfile.ZipFile(io.BytesIO(data)) as z:
        if z.namelist()!=sorted(c.members()):raise RuntimeError('ZIP member order/path mismatch')
        for item in z.infolist():
            if item.is_dir() or item.filename.startswith('/') or '..' in Path(item.filename).parts:
                raise RuntimeError('Path traversal in ZIP')
            blob=z.read(item);name=item.filename;expect=meta['files'][name]
            actual_hash=hashlib.sha256(blob).hexdigest()
            actual_git=hashlib.sha1(b'blob '+str(len(blob)).encode()+b'\x00'+blob).hexdigest()
            if (len(blob)!=expect['size'] or actual_hash!=expect['sha256'] or
                actual_git!=expect['git_blob_sha1'] or actual_git!=git('rev-parse','HEAD:'+name)):
                raise RuntimeError('Git member mismatch '+name)
    return {'verified':True,'candidate':meta['candidate'],'file_count':54,'zip_sha256':meta['zip_sha256']}

if __name__=='__main__':
    p=argparse.ArgumentParser();p.add_argument('--zip',required=True);p.add_argument('--manifest',required=True);args=p.parse_args()
    print(json.dumps(verify(args.zip,args.manifest)))
