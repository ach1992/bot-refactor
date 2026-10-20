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
import package_post_v9 as package

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
    historical_names=v9_contract.members()
    names=package.members()
    expected=[p for p in historical_names
              if p not in package.RETIRED_RUNTIME_MEMBERS]
    expected.extend(package.NEW_RUNTIME_MEMBERS)
    if (len(historical_names)!=92 or len(names)!=93
            or names!=expected or len(names)!=len(set(names))
            or set(historical_names)-set(names)!=package.RETIRED_RUNTIME_MEMBERS):
        raise RuntimeError('V9 or current post-V9 runtime source inventory drift')
    if (ROOT/'baseInfo.php').exists():
        raise RuntimeError('Do not use a checkout containing private deployment state')
    original={name:historical(name) for name in historical_names}
    candidate={name:(ROOT/name).read_bytes() for name in names}
    if len(candidate)==len(original) and all(candidate[n]==original[n] for n in original):
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
            # Each source-only switch reconciles obsolete files in BOTH
            # directions; merely extracting a ZIP leaves retired old PHP on disk.
            # This models ONLY the owned synthetic installer, never a live deletion.
            for extra in (set(names)|set(historical_names))-set(source):
                path=app/extra
                if path.exists():
                    path.unlink()
            for name in source:
                path=app/name
                path.parent.mkdir(parents=True,exist_ok=True)
                path.write_bytes(source[name])
            for name in source:
                if (app/name).read_bytes()!=source[name]:
                    raise RuntimeError('Source overlay member wrong: '+label+':'+name)
            for name,expected in private.items():
                if digest((app/name).read_bytes())!=expected:
                    raise RuntimeError('Source swap touched private data: '+label+':'+name)
            scanned=[
                p.relative_to(app).as_posix() for p in app.rglob('*') if p.is_file()
            ]
            if sorted(scanned)!=sorted(list(source)+list(private)):
                raise RuntimeError('Source swap injected/deleted unrelated files')
            phases.append({'phase':label,'members':len(source),
                'retired_old_payment_files_absent':
                    all(not (app/name).exists() for name in package.RETIRED_RUNTIME_MEMBERS)
                    if source is candidate else True,
                'source_merkle_sha256':digest(''.join(digest(source[n]) for n in source).encode()),
                'private_state_unchanged':True})
    if phases[0]['source_merkle_sha256']!=phases[2]['source_merkle_sha256']:
        raise RuntimeError('V9 rollback byte identity not preserved')
    if phases[1]['source_merkle_sha256']!=phases[3]['source_merkle_sha256']:
        raise RuntimeError('Post-V9 reinstall byte identity not preserved')
    report={'complete':True,'pinned_v9':BASE,'phase_count':len(phases),
            'runtime_members':len(candidate),'historical_runtime_members':len(original),
            'source_roll_forward_and_back_byte_identical':True,
            'retired_old_payment_files_absent_after_source_upgrade':
                phases[1]['retired_old_payment_files_absent'] and
                phases[3]['retired_old_payment_files_absent'],
            'unchanged_original_sql_ddl':True,'synthetic_private_files_untouched':True,
            'real_sql_migration_or_db_restore_tested':False,'real_provider_or_bot_touched':False,
            'phases':phases}
    if args.report:
        args.report.write_text(json.dumps(report,indent=2)+'\n')
    print(json.dumps({k:v for k,v in report.items() if k!='phases'}))

if __name__=='__main__':
    main()
