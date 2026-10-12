#!/usr/bin/env python3
"""V1 gate: frozen V0 reference + current wrappers/modules + identical behavior traces."""
from __future__ import annotations
import argparse
import hashlib
import json
import os
import re
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path
from v1_contract import ROOT, MODULES, NAMES, reference, verify, declarations
sys.path.insert(0,str(ROOT/'tests'))
import run


def extract(root: Path) -> tuple[bytes,list]:
    with tempfile.TemporaryDirectory(prefix='achbot-v1-extract-') as tmp:
        f=Path(tmp)/'functions.php'; m=Path(tmp)/'provenance.json'
        subprocess.run(['php','-n','-d','extension=tokenizer',str(root/'tests/support/extract.php'),str(f),str(m)],check=True,timeout=25)
        return f.read_bytes(),json.loads(m.read_text())


def compare_provenance(old: list, new: list) -> None:
    expected={(v['file'],v['function']):v['sha256'] for v in old}
    actual={(v['file'],v['function']):v['sha256'] for v in new}
    if set(expected)!=set(actual): raise RuntimeError('Declaration inventory drift')
    for key,digest in expected.items():
        if key[1] not in NAMES and actual[key]!=digest:
            raise RuntimeError('Unrelated declaration changed: '+str(key))
    # All changed declarations are separately checked byte-for-byte by v1_contract.verify.


def edges(root: Path, functions: bytes, mode='legacy', strict=False) -> dict:
    with tempfile.TemporaryDirectory(prefix='achbot-v1-helpers-') as tmp:
        d=Path(tmp); (d/'functions-under-test.php').write_bytes(functions)
        mapping={old:namespace+'\\'+new for namespace,entries in MODULES.values() for _,old,new,_ in entries}
        (d/'mapping.json').write_text(json.dumps(mapping))
        script=(ROOT/'tests/v1/helpers.php').read_bytes()
        if strict: script=script.replace(b'<?php',b'<?php\ndeclare(strict_types=1);',1)
        if mode=='internal':
            imports=''.join("require_once __DIR__ . '/"+p+"';\n" for p in MODULES)
            script=script.replace(b"require __DIR__.'/functions-under-test.php';",imports.encode())
        (d/'helpers.php').write_bytes(script)
        if (root/'src').exists(): shutil.copytree(root/'src',d/'src')
        env={'PATH':os.environ.get('PATH','/usr/bin:/bin'),'LANG':'C.UTF-8','ACHBOT_PRIVATE_TEST':'1'}
        cmd=['php','-n','-d','allow_url_fopen=0','-d','allow_url_include=0','-d','open_basedir='+tmp,
             '-d','disable_functions='+run.DISABLED,'-d','memory_limit=128M','-d','display_errors=0','-d','log_errors=0',str(d/'helpers.php'),mode]
        p=subprocess.run(cmd,cwd=d,env=env,capture_output=True,timeout=15,preexec_fn=run.enforce_limits)
        if p.returncode or p.stdout or p.stderr:
            raise RuntimeError('Helper subprocess failed or emitted diagnostics: '+str(p.returncode)+' '+p.stderr.decode(errors='replace')[:500])
        return json.loads((d/'helpers-result.json').read_text())


def assert_equal(expected:dict,actual:dict,label:str):
    if set(expected)!=set(actual): raise RuntimeError(label+' case list drift')
    for key,value in expected.items():
        if actual[key]!=value:
            raise RuntimeError(label+' mismatch '+key+': '+json.dumps({'old':value,'new':actual[key]},ensure_ascii=True)[:1600])


def main():
    ap=argparse.ArgumentParser(description=__doc__)
    ap.add_argument('--report',type=Path)
    ap.add_argument('--focused',action='store_true',help='Authoring only: skip repeated V0 full gate; never acceptance evidence')
    args=ap.parse_args()
    with reference() as (old,lock):
        summary=verify(ROOT,old,lock)
        php_files=sorted({ROOT/f for f in lock['files'] if f.endswith('.php')} |
                         {ROOT/f for f in MODULES} | set((ROOT/'tests').rglob('*.php')) | set((ROOT/'tools').rglob('*.php')))
        for path in php_files:
            subprocess.run(['php','-n','-d','disable_functions='+run.DISABLED,'-l',str(path)],check=True,capture_output=True,timeout=15)
        for path in [*(ROOT/'tools').glob('*.py'),*(ROOT/'tests/v1').glob('*.py')]:
            compile(path.read_text(),str(path),'exec')
        patterns=[r'\b[0-9]{7,11}:[A-Za-z0-9_-]{30,50}\b',r'-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----',r'gh[pousr]_[A-Za-z0-9_]{18,}']
        for path in [*php_files,*(ROOT/'tools').glob('*.py'),*(ROOT/'docs/v1').glob('*.md')]:
            if any(re.search(pattern,path.read_text()) for pattern in patterns):
                raise RuntimeError('Potential secret in '+str(path.relative_to(ROOT)))
        if not args.focused:
            subprocess.run(['python3',str(old/'tools/check.py')],cwd=old,check=True,timeout=180)
        old_functions,old_provenance=extract(old)
        functions,provenance=extract(ROOT)
        compare_provenance(old_provenance,provenance)
        run.ROOT=ROOT
        expected=json.loads((old/'tests/fixtures/golden.json').read_text())['cases']
        if set(expected)!=set(run.CASES): raise RuntimeError('Original scenario set drift')
        for case in run.CASES:
            got=run.run_case(case,functions,list(lock['files'])+list(MODULES))
            if got!=expected[case]: raise RuntimeError('Candidate golden mismatch: '+case)
        print('PASS candidate legacy traces:',len(expected),flush=True)
        for strict in [False,True]:
            original=edges(old,old_functions,strict=strict)
            current=edges(ROOT,functions,strict=strict)
            internal=edges(ROOT,functions,mode='internal',strict=strict)
            assert_equal(original,current,'legacy wrappers strict='+str(strict))
            assert_equal(original,internal,'internal functions strict='+str(strict))
            print('PASS helper vectors:',len(original),'strict:',strict,flush=True)
        subprocess.run(['python3',str(ROOT/'tests/v1/test_controls.py')],cwd=ROOT,check=True,timeout=180)
        families={k.split('.')[0] for k in original}
        if families!=NAMES: raise RuntimeError('Missing helper edge coverage')
        summary.update({'candidate_characterization_cases':len(expected),'helper_vectors_per_typing_mode':len(original),
                        'typing_modes':2,'candidate_php_files_linted':len(php_files),'v1_control_tests':12,'v1_negative_controls':9,'helper_comparison_pairs':len(original)*4,'helper_names_covered':len(families),
                        'full_v0_gate':not args.focused,'production_acceptance':False,'independent_review':False})
    if args.report: args.report.write_text(json.dumps(summary,indent=2)+'\n')
    print(json.dumps(summary,sort_keys=True)); return 0


if __name__=='__main__': raise SystemExit(main())
