#!/usr/bin/env python3
"""Independent AChBot V6 ZIP/manifest/committed-blob verifier for staging operators."""
from __future__ import annotations
import argparse
import hashlib
import io
import json
import subprocess
import zipfile
from pathlib import Path
import v6_contract as c

ROOT=c.ROOT


def git(*args):
    return subprocess.check_output(['git','-C',str(ROOT),*args]).decode().strip()


def verify(zip_path:Path,manifest_path:Path):
    meta=json.loads(manifest_path.read_text())
    data=zip_path.read_bytes()
    if hashlib.sha256(data).hexdigest()!=meta['zip_sha256']:
        raise RuntimeError('ZIP digest mismatch')
    if meta['candidate']!=git('rev-parse','HEAD') or meta['tree']!=git('rev-parse','HEAD^{tree}'):
        raise RuntimeError('Candidate commit/tree mismatch')
    files=meta['files']
    if meta['file_count']!=53 or set(files)!=set(c.runtime_files()):
        raise RuntimeError('Expected exactly 53 allowlisted files')
    if meta['baseInfo_php_included'] or meta['sql_data_included'] or meta['production_approved']:
        raise RuntimeError('Unsafe package assertions')
    with zipfile.ZipFile(io.BytesIO(data)) as archive:
        if archive.namelist()!=sorted(files):raise RuntimeError('ZIP filenames/membership/order mismatch')
        for item in archive.infolist():
            if item.is_dir() or item.filename.startswith('/') or '..' in Path(item.filename).parts:
                raise RuntimeError('Unsafe ZIP member '+item.filename)
            member=archive.read(item)
            expected=files[item.filename]
            if (len(member)!=expected['size'] or hashlib.sha256(member).hexdigest()!=expected['sha256']):
                raise RuntimeError('Package member content mismatch '+item.filename)
            actual_blob=hashlib.sha1(b'blob '+str(len(member)).encode()+b'\0'+member).hexdigest()
            if actual_blob!=expected['git_blob_sha1'] or actual_blob!=git('rev-parse','HEAD:'+item.filename):
                raise RuntimeError('Committed Git blob identity mismatch '+item.filename)
    return {'candidate':meta['candidate'],'tree':meta['tree'],'file_count':len(files),
            'zip_sha256':meta['zip_sha256'],'checked_against_git_head':True}


def main():
    ap=argparse.ArgumentParser(description=__doc__)
    ap.add_argument('--zip',type=Path,required=True)
    ap.add_argument('--manifest',type=Path,required=True)
    args=ap.parse_args()
    print(json.dumps(verify(args.zip,args.manifest)))

if __name__=='__main__':main()
