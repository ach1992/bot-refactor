#!/usr/bin/env python3
"""Disposable physical V9 -> post-V9 -> V9 -> post-V9 file overlay proof.

Source-only: NEVER connects to a real DB/provider or examines real private
configuration.  Tests bytes and preserved local synthetic private state only.
"""
from __future__ import annotations
import argparse
import hashlib
import json
import subprocess
import tempfile
from pathlib import Path
import v9_contract

ROOT=Path(__file__).resolve().parents[1]
BASE='7eb077fd8e6d0a303421626d1500f69e295a6664'
BASE_TREE='7da17725c1e7f0448119b7e41c8b11b21f3e093d'


def historical(path:str)->bytes:
    return subprocess.check_output(['git','-C',str(ROOT),'show',BASE+':'+path])


def digest(raw:bytes)->str:
    return hashlib.sha256(raw).hexdigest()


def main():
    ap=argparse.ArgumentParser()
    ap.add_argument('--run-disposable',required=True,action='store_true')
    ap.add_argument('--report',type=Path)
    args=ap.parse_args()
    tree=subprocess.check_output(['git','-C',str(ROOT),'rev-parse',BASE+'^{tree}'],text=True).strip()
    if tree!=BASE_TREE:
        raise RuntimeError('Historic V9 rollback reference drift')
    names=v9_contract.members()
    if len(names)!=92 or len(names)!=len(set(names)):
        raise RuntimeError('Original V9 runtime source inventory drift')
    if (ROOT/'baseInfo.php').exists():
        raise RuntimeError('Do not use a checkout containing private deployment state')
    original={name:historical(name) for name in names}
    candidate={name:(ROOT/name).read_bytes() for name in names}
    if all(candidate[n]==original[n] for n in names):
        raise RuntimeError('This new post-V9 candidate unexpectedly has no source delta')
    schema=(ROOT/'database/legacy-schema.sql').read_bytes()
    if schema!=historical('database/legacy-schema.sql'):
        raise RuntimeError('Original 23-table DDL modified')
    phases=[]
    with tempfile.TemporaryDirectory(prefix='achbot-postv9-owned-overlay-') as tmp:
        app=Path(tmp)/'app'
        app.mkdir()
        (app/'database').mkdir()
        (app/'database/legacy-schema.sql').write_bytes(schema)
        (app/'baseInfo.php').write_bytes(b'<?php // owned fake state; never live\n')
        (app/'private-state.json').write_bytes(b'{"owned":"synthetic"}\n')
        private={name:digest((app/name).read_bytes()) for name in (
            'baseInfo.php','private-state.json','database/legacy-schema.sql')}
        for label,source in [('historical-v9',original),('post-v9',candidate),
                             ('rollback-v9',original),('reinstall-post-v9',candidate)]:
            for name in names:
                path=app/name
                path.parent.mkdir(parents=True,exist_ok=True)
                path.write_bytes(source[name])
            for name in names:
                if (app/name).read_bytes()!=source[name]:
                    raise RuntimeError('Source overlay member wrong: '+label+':'+name)
            for name,expected in private.items():
                if digest((app/name).read_bytes())!=expected:
                    raise RuntimeError('Source swap touched private data: '+label+':'+name)
            scanned=[
                p.relative_to(app).as_posix() for p in app.rglob('*') if p.is_file()
            ]
            if sorted(scanned)!=sorted(names+list(private)):
                raise RuntimeError('Source swap injected/deleted unrelated files')
            phases.append({'phase':label,'members':len(source),
                'source_merkle_sha256':digest(''.join(digest(source[n]) for n in names).encode()),
                'private_state_unchanged':True})
    if phases[0]['source_merkle_sha256']!=phases[2]['source_merkle_sha256']:
        raise RuntimeError('V9 rollback byte identity not preserved')
    if phases[1]['source_merkle_sha256']!=phases[3]['source_merkle_sha256']:
        raise RuntimeError('Post-V9 reinstall byte identity not preserved')
    report={'complete':True,'pinned_v9':BASE,'phase_count':len(phases),
            'runtime_members':92,'source_roll_forward_and_back_byte_identical':True,
            'unchanged_original_sql_ddl':True,'synthetic_private_files_untouched':True,
            'real_sql_migration_or_db_restore_tested':False,'real_provider_or_bot_touched':False,
            'phases':phases}
    if args.report:
        args.report.write_text(json.dumps(report,indent=2)+'\n')
    print(json.dumps({k:v for k,v in report.items() if k!='phases'}))

if __name__=='__main__':
    main()
