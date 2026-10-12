#!/usr/bin/env python3
"""AChBot V5 full frozen V4 chain + exact source + synthetic end-to-end acceptance."""
from __future__ import annotations
import argparse
import hashlib
import json
import os
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path
import v5_contract as c
import package_v5

ROOT=c.ROOT


def execute(command:list[str],cwd:Path,env:dict,timeout=360):
    result=subprocess.run(command,cwd=cwd,env=env,capture_output=True,text=True,timeout=timeout)
    if result.returncode:
        raise RuntimeError('Verification failed: '+str(command)+'\nstdout:\n'+result.stdout[-3500:]+'\nstderr:\n'+result.stderr[-2000:])
    return {'command':command,'stdout_sha256':hashlib.sha256(result.stdout.encode()).hexdigest(),
            'last_line':result.stdout.strip().splitlines()[-1] if result.stdout.strip() else '',
            'stderr':result.stderr[-500:]}


def main():
    ap=argparse.ArgumentParser(description=__doc__)
    ap.add_argument('--report',type=Path)
    ap.add_argument('--evidence-dir',type=Path,help='Write standalone suite JSON reports outside the repository')
    ap.add_argument('--focused',action='store_true',help='Authoring only; skips frozen and native gates')
    args=ap.parse_args()
    source=c.verify()
    if args.evidence_dir:
        if ROOT == args.evidence_dir.resolve() or ROOT in args.evidence_dir.resolve().parents:
            raise RuntimeError('Evidence directory must be outside repository')
        args.evidence_dir.mkdir(parents=True,exist_ok=True)
    print('PASS pinned byte-for-byte source and DDL: '+json.dumps(source),flush=True)
    lint=[]
    for name in c.runtime_files():
        if not name.endswith('.php'):continue
        result=execute(['php','-l',str(ROOT/name)],ROOT,dict(os.environ),timeout=10)
        lint.append(name)
    assert len(lint)>30
    print('PASS runtime PHP syntax files: '+str(len(lint)),flush=True)
    blob,_=package_v5.build_bytes()
    report={'source':source,'php_linted':len(lint),'source_only_runtime_members':43,
            'package_dry_run_sha256':hashlib.sha256(blob).hexdigest(),'suites':{}}
    env=dict(os.environ)
    env['ACHBOT_PRIVATE_TEST']='1'
    if not args.focused:
        with c.previous() as ref, tempfile.TemporaryDirectory(prefix='achbot-v5-virtual-git-') as git_tmp:
            # An alternate-object-store read-only view lets frozen V4 suites
            # see their true pinned HEAD/index even after V5 commits, without
            # rewriting this repository's refs, branches, index or worktrees.
            physical_git=Path(subprocess.check_output(['git','rev-parse','--absolute-git-dir'],cwd=ROOT,text=True).strip())
            virtual=Path(git_tmp)/'git'
            subprocess.run(['git','init','--quiet','--bare',str(virtual)],check=True,capture_output=True)
            alt=virtual/'objects/info/alternates'
            alt.parent.mkdir(parents=True,exist_ok=True)
            alt.write_text(str(physical_git/'objects')+'\n')
            def vg(*argv):
                subprocess.run(['git','--git-dir='+str(virtual),*argv],check=True,capture_output=True)
            vg('update-ref','refs/heads/frozen-v4',c.BASE)
            vg('symbolic-ref','HEAD','refs/heads/frozen-v4')
            vg('read-tree',c.BASE)
            refenv=dict(env,GIT_DIR=str(virtual),GIT_WORK_TREE=str(ref))
            check=subprocess.check_output(['git','status','--porcelain'],cwd=ref,env=refenv)
            if check.strip():raise RuntimeError('Pinned V4 reference tree/index is not clean')
            suites=[
                ('historical_v0_v4',['python3',str(ref/'tools/check_v4.py'),'--report',str(ref/'v4-check.json')]),
                ('historical_original_schema',['python3',str(ref/'tools/test_schema.py'),'--run-disposable','--report',str(ref/'v4-schema.json')]),
                ('historical_telegram_wire',['python3',str(ref/'tools/test_v4_transport.py'),'--run-loopback','--report',str(ref/'v4-wire.json')]),
                ('historical_admin_native',['python3',str(ref/'tools/test_access_state.py'),'--run-disposable','--report',str(ref/'v4-native.json')]),
                ('historical_direct_http',['python3',str(ref/'tools/test_access_http.py'),'--run-loopback','--report',str(ref/'v4-http.json')]),
                ('historical_source_swap',['python3',str(ref/'tools/rehearse_v4.py'),'--report',str(ref/'v4-swap.json')]),
            ]
            for name,cmd in suites:
                report['suites'][name]=execute(cmd,ref,refenv,timeout=400)
                origin=Path(cmd[-1])
                if args.evidence_dir and origin.is_file():
                    shutil.copyfile(origin,args.evidence_dir/(name+'.json'))
                print('PASS '+name,flush=True)
    more=[
        ('broadcast_admin_diff',['python3',str(ROOT/'tools/test_v5_admin.py')]),
        ('cron_and_warning_diff',['python3',str(ROOT/'tools/test_v5_worker.py')]),
        ('negative_mutation_controls',['python3',str(ROOT/'tools/test_v5_mutation.py')]),
    ]
    if not args.focused:
        more += [
            ('native_mariadb_worker_recovery',['python3',str(ROOT/'tools/test_v5_native.py'),'--run-disposable']),
            ('direct_module_http',['python3',str(ROOT/'tools/test_v5_http.py'),'--run-loopback']),
        ]
    for name,cmd in more:
        if args.evidence_dir:
            cmd=cmd+['--report',str(args.evidence_dir/(name+'.json'))]
        report['suites'][name]=execute(cmd,ROOT,env,timeout=300)
        print('PASS '+name+' '+report['suites'][name]['last_line'],flush=True)
    report['complete_acceptance_suite']=not args.focused
    if args.report:args.report.write_text(json.dumps(report,ensure_ascii=False,indent=2)+'\n')
    print(json.dumps({'complete_acceptance_suite':not args.focused,'frozen_suites':sum(s.startswith('historical_') for s in report['suites']),
                      'new_suites':sum(not s.startswith('historical_') for s in report['suites']),
                      'runtime_members':43,'syntax_linted':len(lint)}))

if __name__=='__main__':main()
