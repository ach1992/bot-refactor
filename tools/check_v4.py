#!/usr/bin/env python3
"""V4: frozen V3/V2/V1/V0, access/agent differential, root and mutation controls."""
from __future__ import annotations
import argparse
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
import check_v3
import v1_contract as v1
import v4_contract as c

ROOT=c.ROOT
import importlib.util
_spec=importlib.util.spec_from_file_location('achbot_v4_cases',ROOT/'tests/v4/cases.py')
_cases=importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(_cases)
build_cases=_cases.build_cases
_root_spec=importlib.util.spec_from_file_location('achbot_v4_root_cases',ROOT/'tests/v4/root_cases.py')
_root_cases=importlib.util.module_from_spec(_root_spec)
_root_spec.loader.exec_module(_root_cases)
build_root_cases=_root_cases.build_root_cases


def sequence(old:Path,candidate:bool):
    if candidate:
        return b'<?php\n'+b''.join(("require __DIR__ . '/"+name+"';\n").encode() for name in c.NEW_FILES)
    return b'<?php\n'+b''.join(r['body'] for r in c.region_blocks(old))

def access(root:Path,old:Path,spec:dict,candidate:bool,override=None):
    with tempfile.TemporaryDirectory(prefix='achbot-access-') as tmp:
        d=Path(tmp)
        if candidate:shutil.copytree(root/'src',d/'src')
        (d/'tests/support').mkdir(parents=True)
        (d/'settings').mkdir()
        shutil.copyfile(old/'tests/support/doubles.php',d/'tests/support/doubles.php')
        shutil.copyfile(old/'settings/values.php',d/'settings/values.php')
        shutil.copyfile(ROOT/'tests/v4/access.php',d/'access.php')
        (d/'case.json').write_text(json.dumps(spec,ensure_ascii=True))
        names=sorted(set(re.findall(rb'\$(\w+)',b''.join(r['body'] for r in c.region_blocks(old)))))
        (d/'scope.json').write_text(json.dumps([s.decode() for s in names]))
        (d/'access-sequence.php').write_bytes(sequence(old,candidate))
        for rel,body in (override or {}).items():
            path=d/rel
            if Path(rel).is_absolute() or '..' in Path(rel).parts or not path.is_file():
                raise RuntimeError('Bad mutation override')
            path.write_bytes(body)
        check_v2.php([str(d/'access.php')],d)
        record=json.loads((d/'access-result.json').read_text())
        if record['harness_errors']:
            raise RuntimeError('Access harness error '+str(record['harness_errors']))
        return record

def assert_expected(name,spec,expected):
    error=expected['error']
    if spec.get('error'):
        if not error or error[0]!=spec['error']:
            raise RuntimeError(name+' reference error differed: '+str(error))
    elif error:
        raise RuntimeError(name+' unexpected reference error: '+str(error))
    if name.startswith('agent.apply.decline.9001-0'):
        state=[e for e in expected['events'] if e['kind']=='support.state']
        # Legacy setUser ignores the third argument; do not claim this is fixed.
        if not state or state[-1]['actor']!=9001:
            raise RuntimeError('Legacy decline stored-state target changed')
    if name.startswith('roles.delete.') and name.endswith('9001-0'):
        writes=[e for e in expected['events'] if e['kind']=='db.sql' and e['sql'].startswith('UPDATE')]
        if len(writes)!=1:raise RuntimeError('Admin role write unexpectedly missing')

def current_legacy(old:Path,lock:dict):
    oldf,prior=check_v1.extract(old)
    current,candidate=check_v1.extract(ROOT)
    if candidate!=prior:raise RuntimeError('V4 changed existing global declarations')
    expected=json.loads((old/'tests/fixtures/golden.json').read_text())['cases']
    check_v1.run.ROOT=ROOT
    for name in check_v1.run.CASES:
        if check_v1.run.run_case(name,current,c.runtime_files(lock))!=expected[name]:
            raise RuntimeError('Legacy scenario regression: '+name)
    for strict in (False,True):
        check_v1.assert_equal(check_v1.edges(old,oldf,strict=strict),check_v1.edges(ROOT,current,strict=strict),'V1 helper drift')
    return len(expected)

def root_case(root:Path,old:Path,lock:dict,spec:dict):
    with tempfile.TemporaryDirectory(prefix='achbot-access-root-') as tmp:
        d=Path(tmp)
        for rel in c.runtime_files(lock):
            src=root/rel
            if not src.is_file():continue
            target=d/rel
            target.parent.mkdir(parents=True,exist_ok=True)
            shutil.copyfile(src,target)
        shutil.copytree(old/'tests/support',d/'tests/support')
        child=d/'tests/support/child.php'
        content=child.read_bytes()
        assert b"require __DIR__.'/entrypoints.php';" in content
        child.write_bytes(content.replace(b"require __DIR__.'/entrypoints.php';",
                                          b"require __DIR__.'/root_entry.php';"))
        stream=(old/'tests/support/entrypoints.php').read_bytes().split(b'$variant=substr($scenario,6);',1)[0]
        script=(ROOT/'tests/v4/root_entry.php').read_bytes().removeprefix(b'<?php\n')
        (d/'tests/support/root_entry.php').write_bytes(stream+script)
        (d/'tests/support/root-case.json').write_text(json.dumps(spec,ensure_ascii=True))
        (d/'baseInfo.php').write_text(check_v1.run.BASEINFO)
        try:check_v2.php([str(child),'entry.support'],d)
        except RuntimeError as exc:
            trace=d/'result.json'
            if trace.is_file():
                body=json.loads(trace.read_text());raise RuntimeError('Whole-router fixture: '+str(body['harness_errors'])[:1500]) from exc
            raise
        result=json.loads((d/'result.json').read_text().replace(tmp,'<SANDBOX>'))
        if result['harness_errors']:
            raise RuntimeError('Root fixture error: '+str(result['harness_errors']))
        return result

def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--focused',action='store_true',help='Authoring only, not acceptance')
    parser.add_argument('--case',action='append',default=[],help='Only prefixes, authoring only')
    parser.add_argument('--report',type=Path)
    args=parser.parse_args()
    cases=build_cases()
    with c.previous() as (old,lock):
        report=c.verify(ROOT,old,lock)
        if not args.focused and not args.case:
            env=dict(os.environ)
            env['GIT_DIR']=v1.git('rev-parse','--absolute-git-dir').decode().strip()
            env['GIT_WORK_TREE']=str(old)
            subprocess.run(['python3',str(old/'tools/check_v3.py')],cwd=old,env=env,check=True,timeout=330,
                           stdout=subprocess.DEVNULL)
        previous_count=0 if args.focused or args.case else current_legacy(old,lock)
        selected={name:spec for name,spec in cases.items()
                  if not args.case or any(name.startswith(prefix) for prefix in args.case)}
        if not selected:raise RuntimeError('No V4 tests selected')
        warnings=0
        for name,spec in selected.items():
            old_trace=access(old,old,spec,False)
            assert_expected(name,spec,old_trace)
            new_trace=access(ROOT,old,spec,True)
            if old_trace!=new_trace:
                different=[k for k in old_trace if old_trace[k]!=new_trace[k]]
                raise RuntimeError('V4 mismatch '+name+' '+json.dumps(
                   {k:[old_trace[k],new_trace[k]] for k in different},ensure_ascii=True)[:3500])
            warnings+=bool(old_trace['warnings'])
            if args.focused or args.case:print('PASS',name,flush=True)
        php_count=0;controls=0;root_count=0
        if not args.focused and not args.case:
            for name,spec in build_root_cases().items():
                expected_root=root_case(old,old,lock,spec)
                actual_root=root_case(ROOT,old,lock,spec)
                if expected_root!=actual_root:
                    fields=[key for key in expected_root if expected_root[key]!=actual_root[key]]
                    raise RuntimeError('Whole-router V4 mismatch: '+name+' '+str(fields))
                root_count+=1
            print('PASS whole router',root_count,flush=True)
            files=sorted(set(ROOT/f for f in c.runtime_files(lock) if f.endswith('.php'))|
                         set((ROOT/'tests').rglob('*.php'))|
                         set((ROOT/'tools').rglob('*.php')))
            for path in files:
                subprocess.run(['php','-n','-d','disable_functions='+check_v1.run.DISABLED,'-l',str(path)],
                               capture_output=True,timeout=15,check=True)
            php_count=len(files)
            for path in list((ROOT/'tools').glob('*.py'))+list((ROOT/'tests/v4').glob('*.py')):
                compile(path.read_text(),str(path),'exec')
            # Bounded publication scan of the changed runtime and durable V4 text.
            # Historical sensitive-reference concerns are tracked separately.
            private_patterns=[
                r'\b[0-9]{7,11}:[A-Za-z0-9_-]{30,50}\b',
                r'-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----',
                r'gh[pousr]_[A-Za-z0-9_]{18,}',
            ]
            publication=[ROOT/p for p in c.NEW_FILES]
            publication+=[ROOT/'bot.php']
            publication+=list((ROOT/'docs/v4').glob('*.md'))
            publication+=list((ROOT/'tools').glob('*v4*.py'))
            publication+=list((ROOT/'tests/v4').glob('*.py'))
            for file in publication:
                data=file.read_text()
                if any(re.search(pattern,data) for pattern in private_patterns):
                    raise RuntimeError('Potential private token in V4 publication member: '+str(file.relative_to(ROOT)))
            subprocess.run(['python3',str(ROOT/'tests/v4/test_controls.py')],cwd=ROOT,check=True,timeout=180,
                           stdout=subprocess.DEVNULL)
            controls=17
        report.update(c.identity())
        report.update({'frozen_v3_full_gate':not (args.focused or args.case),
                       'legacy_candidate_cases':previous_count,
                       'v1_vectors_per_mode':300 if previous_count else 0,
                       'access_cases':len(selected),'access_warning_cases':warnings,
                       'root_router_cases':root_count,'php_files_linted':php_count,
                       'controls':controls,'negative_controls':15 if controls else 0,'independent_review':False,
                       'operator_manual_acceptance':False,'production_acceptance':False,
                       'external_network_used':False})
    if args.report:args.report.write_text(json.dumps(report,indent=2)+'\n')
    print(json.dumps(report,sort_keys=True))
if __name__=='__main__':main()
