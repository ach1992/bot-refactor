#!/usr/bin/env python3
"""V3 gate: frozen earlier evidence, support trace parity and root-router integration."""
from __future__ import annotations
import argparse
import base64
import json
import os
import re
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path
import check_v1
import check_v2
import v1_contract as v1
import v2_contract as v2
import v3_contract as c
ROOT=c.ROOT
run=check_v1.run
sys.path.insert(0,str(ROOT/'tests/v3'))
from cases import build_cases
from root_cases import build_root_cases


def support_sequence(old:Path, candidate:bool):
    if candidate:
        return b'<?php\n'+b''.join(("require __DIR__ . '/"+f+"';\n").encode() for f in c.NEW_FILES)
    return b'<?php\n'+b''.join(r['body'] for r in c.region_blocks(old))


def support(root:Path,old:Path,spec:dict,candidate:bool,*,override:dict[str,bytes]|None=None):
    with tempfile.TemporaryDirectory(prefix='achbot-support-case-') as tmp:
        d=Path(tmp)
        if candidate: shutil.copytree(root/'src',d/'src')
        (d/'tests/support').mkdir(parents=True);(d/'settings').mkdir()
        shutil.copyfile(old/'tests/support/doubles.php',d/'tests/support/doubles.php')
        shutil.copyfile(old/'settings/values.php',d/'settings/values.php')
        shutil.copyfile(ROOT/'tests/v3/support.php',d/'support.php')
        (d/'case.json').write_text(json.dumps(spec,ensure_ascii=True))
        names=sorted(set(re.findall(rb'\$(\w+)',b''.join(r['body'] for r in c.region_blocks(old)))))
        (d/'scope.json').write_text(json.dumps([s.decode() for s in names]))
        (d/'support-sequence.php').write_bytes(support_sequence(old,candidate))
        for path,data in (override or {}).items():
            relative=Path(path)
            if relative.is_absolute() or '..' in relative.parts:raise RuntimeError('Unsafe test override')
            p=d/relative
            if not p.is_file() or d not in p.resolve().parents:raise RuntimeError('Invalid test override target')
            p.write_bytes(data)
        check_v2.php([str(d/'support.php')],d)
        result=json.loads((d/'support-result.json').read_text())
        if result['harness_errors']:raise RuntimeError('Support harness failed: '+str(result['harness_errors']))
        return result


def assert_case(name:str,spec:dict,result:dict):
    error=result['error']
    if spec.get('error'):
        if not error or error[0]!=spec['error']:raise RuntimeError(name+' expected fixture/legacy exception '+spec['error'])
    elif error:raise RuntimeError(name+' unexpected reference error '+str(error))
    if not error and result['complete']==bool(spec.get('stops',False)):
        raise RuntimeError(name+' unexpected exit/fall-through behavior')
    if 'expect_state' in spec and result['persisted'].get('step')!=spec['expect_state']:
        raise RuntimeError(name+' incorrect stored wizard step')
    if name=='rating.rate_123_5':
        writes=[e for e in result['events'] if e['kind']=='db.sql' and e['sql'].startswith('UPDATE')]
        if len(writes)!=1 or writes[0]['params']!=['3']:raise RuntimeError('Legacy repeated-capture behavior not characterized')
    if name=='create.message_write_error':
        kinds=[e['kind']+':'+e.get('function','') for e in result['events']]
        if 'support.call:sendToAllAdmins' not in kinds:raise RuntimeError('Legacy notify-before-message-write ordering lost')
    if name=='close.9002':
        writes=[e for e in result['events'] if e['kind']=='db.sql' and e['sql'].startswith('UPDATE')]
        if len(writes)!=2:raise RuntimeError('Existing delegated-admin fall-through must be characterized')


def current_regression(old:Path,lock:dict):
    functions,p=check_v1.extract(ROOT);oldf,q=check_v1.extract(old)
    if p!=q:raise RuntimeError('V3 changed declarations outside the support route scope')
    expected=json.loads((old/'tests/fixtures/golden.json').read_text())['cases']
    if set(expected)!=set(run.CASES):raise RuntimeError('Original case inventory drift')
    run.ROOT=ROOT
    for name in run.CASES:
        if run.run_case(name,functions,c.runtime_files(lock))!=expected[name]:raise RuntimeError('Original scenario mismatch: '+name)
    for strict in [False,True]:
        check_v1.assert_equal(check_v1.edges(old,oldf,strict=strict),check_v1.edges(ROOT,functions,strict=strict),'V1 helper parity')
    return len(expected)


def main():
    ap=argparse.ArgumentParser(description=__doc__)
    ap.add_argument('--focused',action='store_true',help='Authoring only; not full acceptance evidence')
    ap.add_argument('--support-only',action='store_true',help='Authoring only; excludes the global gate')
    ap.add_argument('--case',action='append',default=[],help='Support case prefix; authoring only')
    ap.add_argument('--report',type=Path)
    args=ap.parse_args();cases=build_cases();warnings=0
    with c.previous() as (old,lock):
        summary=c.verify(ROOT,old,lock)
        if not args.focused and not args.support_only and not args.case:
            env=dict(os.environ);env['GIT_DIR']=v1.git('rev-parse','--absolute-git-dir').decode().strip();env['GIT_WORK_TREE']=str(old)
            subprocess.run(['python3',str(old/'tools/check_v2.py')],cwd=old,env=env,check=True,timeout=300)
        count=0 if args.support_only or args.case else current_regression(old,lock)
        selected={name:s for name,s in cases.items() if not args.case or any(name.startswith(p) for p in args.case)}
        if not selected:raise RuntimeError('No support cases matched')
        for name,spec in selected.items():
            expected=support(old,old,spec,False);assert_case(name,spec,expected)
            got=support(ROOT,old,spec,True)
            if got!=expected:
                keys=[k for k in expected if expected[k]!=got[k]]
                raise RuntimeError('Support parity failed '+name+' fields '+str(keys)+' '+json.dumps({k:[expected[k],got[k]] for k in keys},ensure_ascii=True)[:4500])
            warnings+=bool(expected['warnings'])
            print('PASS support',name,flush=True)
        root_count=0;php_count=0;controls=0
        if not args.support_only and not args.case:
            for name,spec in build_root_cases().items():
                expected=root_case(old,old,lock,spec);actual=root_case(ROOT,old,lock,spec)
                if expected!=actual:raise RuntimeError('Whole-router mismatch: '+name)
                root_count+=1
            print('PASS whole bot.php support routes:',root_count,flush=True)
            files=sorted(set(ROOT/f for f in c.runtime_files(lock) if f.endswith('.php'))|
                         set((ROOT/'tests').rglob('*.php'))|set((ROOT/'tools').rglob('*.php')))
            for path in files:
                subprocess.run(['php','-n','-d','disable_functions='+run.DISABLED,'-l',str(path)],check=True,capture_output=True,timeout=15)
            php_count=len(files)
            for path in [*(ROOT/'tools').glob('*.py'),*(ROOT/'tests/v3').glob('*.py')]:compile(path.read_text(),str(path),'exec')
            patterns=[r'\b[0-9]{7,11}:[A-Za-z0-9_-]{30,50}\b',r'-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----',r'gh[pousr]_[A-Za-z0-9_]{18,}']
            for path in files+list((ROOT/'tools').glob('*.py'))+list((ROOT/'docs/v3').glob('*.md')):
                if any(re.search(pattern,path.read_text()) for pattern in patterns):raise RuntimeError('Potential secret: '+str(path.relative_to(ROOT)))
            subprocess.run(['python3',str(ROOT/'tests/v3/test_controls.py')],check=True,cwd=ROOT,timeout=180)
            controls=15
        summary.update(c.identity())
        summary.update({'whole_router_support_cases':root_count,'php_files_linted':php_count,'v3_control_tests':controls,
                        'v3_negative_controls':13 if controls else 0,'v1_helper_vectors_per_typing_mode':300 if count else 0})
        summary.update({'support_cases':len(selected),'support_warning_cases':warnings,'candidate_legacy_cases':count,
            'frozen_v2_full_gate':not (args.focused or args.support_only or args.case),
            'native_external_io':False,'operator_manual_acceptance':False,'independent_review':False,'production_acceptance':False})
    if args.report:args.report.write_text(json.dumps(summary,indent=2)+'\n')
    print(json.dumps(summary,sort_keys=True));return 0


# Whole-router execution is additional to isolated support-region characterization.
def root_case(root:Path,old:Path,lock:dict,spec:dict):
    with tempfile.TemporaryDirectory(prefix='achbot-support-router-') as tmp:
        d=Path(tmp);files=v2.runtime_files(lock)
        if (root/c.NEW_FILES[0]).is_file():files+=c.NEW_FILES
        for file in files:
            p=d/file;p.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(root/file,p)
        shutil.copytree(old/'tests/support',d/'tests/support')
        child=d/'tests/support/child.php'
        child.write_bytes(child.read_bytes().replace(b"require __DIR__.'/entrypoints.php';",b"require __DIR__.'/root_entry.php';"))
        stream=(old/'tests/support/entrypoints.php').read_bytes().split(b'$variant=substr($scenario,6);',1)[0]
        script=(ROOT/'tests/v3/root_entry.php').read_bytes().removeprefix(b'<?php\n')
        (d/'tests/support/root_entry.php').write_bytes(stream+script)
        (d/'tests/support/root-case.json').write_text(json.dumps(spec,ensure_ascii=True))
        (d/'baseInfo.php').write_text(run.BASEINFO)
        check_v2.php([str(child),'entry.support'],d)
        result=json.loads((d/'result.json').read_text().replace(tmp,'<SANDBOX>'))
        if result['harness_errors']:raise RuntimeError('Root fixture error '+str(result['harness_errors'])[:1500])
        return result

if __name__=='__main__':raise SystemExit(main())
