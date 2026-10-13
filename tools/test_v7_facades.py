#!/usr/bin/env python3
"""Verify purchase and existing financial global facade signatures remain identical."""
from __future__ import annotations
import argparse,json,os,shutil,subprocess,tempfile
from pathlib import Path
import v7_contract as c

def main():
    p=argparse.ArgumentParser();p.add_argument('--report',type=Path);args=p.parse_args();c.verify()
    with tempfile.TemporaryDirectory(prefix='achbot-v7-facades-') as tmp:
        representations=[]
        for modern in (False,True):
            root=Path(tmp)/('new' if modern else 'old');root.mkdir()
            (root/'buy_helpers.php').write_bytes((c.ROOT/'buy_helpers.php').read_bytes() if modern else c.old('buy_helpers.php'))
            (root/'reflect.php').write_bytes((c.ROOT/'tests/v7/reflect.php').read_bytes())
            if modern:
                for name in [n for n,_,_ in c.HELPERS]:
                    target=root/name;target.parent.mkdir(parents=True,exist_ok=True)
                    shutil.copyfile(c.ROOT/name,target)
            e={'PATH':os.environ.get('PATH','/usr/bin:/bin'),'ACHBOT_PRIVATE_TEST':'1'}
            proc=subprocess.run(['php','-n','-d','open_basedir='+str(root),'-d','allow_url_fopen=0',
                                 str(root/'reflect.php')],cwd=root,env=e,capture_output=True,timeout=15)
            if proc.returncode or proc.stderr:raise RuntimeError('Facade reflection failed '+repr(proc.stderr[-500:]))
            representations.append(json.loads(proc.stdout))
        if representations[0]!=representations[1]:
            raise RuntimeError('Public helper signatures changed')
    report={'facades':len(representations[0]),'signatures_identical':True,
            'legacy_financial_helper_signatures_unchanged':True}
    if args.report:args.report.write_text(json.dumps(report,indent=2)+'\n')
    print(json.dumps(report))
if __name__=='__main__':main()
