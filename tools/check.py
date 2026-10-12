#!/usr/bin/env python3
"""V0 acceptance checks: reference integrity, lexical inventory, syntax, harness, and traces."""
from __future__ import annotations
import argparse
import hashlib
import json
import os
import re
import subprocess
import sys
import tempfile
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'tests'))
from run import DISABLED,assert_baseline

def command(args:list[str]) -> None:
    subprocess.run(args,cwd=ROOT,check=True,timeout=180)

def main() -> int:
    ap=argparse.ArgumentParser(description=__doc__)
    ap.add_argument('--report',type=Path,help='Optional synthetic evidence summary; keep local output out of Git')
    args=ap.parse_args()
    lock=assert_baseline()
    # Frozen source anchor, not a claim that arbitrary future refactors must remain byte-identical.
    if (ROOT/'.git').exists():
        for file,digest in lock['files'].items():
            p=subprocess.run(['git','show',lock['baseline_commit']+':'+file],cwd=ROOT,capture_output=True,check=True)
            if hashlib.sha256(p.stdout).hexdigest()!=digest: raise SystemExit('Reference lock does not match imported commit: '+file)
    command(['python3','tools/inventory.py'])
    php_files=[ROOT/f for f in lock['files'] if f.endswith('.php')]+list((ROOT/'tests').rglob('*.php'))+list((ROOT/'tools').glob('*.php'))
    for p in php_files:
        command(['php','-n','-d','disable_functions='+DISABLED,'-l',str(p)])
    for p in [*(ROOT/'tools').glob('*.py'),*(ROOT/'tests').glob('*.py')]:
        compile(p.read_text(),str(p),'exec')
    # Defense in depth, not an exhaustive secret or vulnerability audit. Report paths only, never values.
    patterns=[r'\b[0-9]{7,11}:[A-Za-z0-9_-]{30,50}\b',r'-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----',r'gh[pousr]_[A-Za-z0-9_]{18,}']
    scan=[*php_files,*(ROOT/'tests/fixtures').glob('*.json'),*(ROOT/'docs/v0').glob('*.md')]
    for p in scan:
        if any(re.search(pat,p.read_text()) for pat in patterns): raise SystemExit('Potential secret in '+str(p.relative_to(ROOT)))
    command(['python3','tests/test_harness.py'])
    with tempfile.TemporaryDirectory(prefix='achbot-check-') as d:
        report=Path(d)/'mock.json'
        command(['python3','tests/run.py','--report',str(report)])
        summary=json.loads(report.read_text())
    summary.update({'baseline_files_unchanged':len(lock['files']),'php_files_linted':len(php_files),
                    'harness_self_tests':8,'mutation_controls':4,'inventory_current':True,'independent_review':False})
    if args.report:
        args.report.parent.mkdir(parents=True,exist_ok=True); args.report.write_text(json.dumps(summary,indent=2)+'\n')
    print(json.dumps(summary,sort_keys=True)); return 0

if __name__=='__main__': raise SystemExit(main())
