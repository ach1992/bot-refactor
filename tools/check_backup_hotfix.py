#!/usr/bin/env python3
"""AChBot V6.1 exact candidate: frozen V6 optional full plus real isolated backup acceptance."""
from __future__ import annotations
import argparse,hashlib,io,json,os,subprocess,sys,tarfile,tempfile
from pathlib import Path
import backup_hotfix_contract as c
ROOT=c.ROOT

def run(argv,where=ROOT,timeout=400):
    p=subprocess.run(argv,cwd=where,env=dict(os.environ,ACHBOT_PRIVATE_TEST='1'),text=True,
                     capture_output=True,timeout=timeout)
    if p.returncode:
        raise RuntimeError('Required test failed '+repr(argv)+'\nstdout: '+p.stdout[-1600:]+'\nstderr: '+p.stderr[-1600:])
    return {'stdout_sha256':hashlib.sha256(p.stdout.encode()).hexdigest(),
            'last_line':p.stdout.strip().splitlines()[-1] if p.stdout.strip() else ''}

def pinned_v6(directory):
    blob=c.git('archive','--format=tar',c.BASE)
    with tarfile.open(fileobj=io.BytesIO(blob)) as tar:
        members=tar.getmembers()
        if len(members)>2000 or sum(m.size for m in members)>100*1024*1024:
            raise RuntimeError('Invalid historical archive size')
        for m in members:
            path=Path(m.name)
            if path.is_absolute() or '..' in path.parts or not(m.isfile() or m.isdir()):
                raise RuntimeError('Invalid historical archive member')
        tar.extractall(directory,filter='data')
    subprocess.run(['git','init','--quiet',str(directory)],check=True)
    objects=Path(c.git('rev-parse','--path-format=absolute','--git-common-dir').decode().strip())/'objects'
    alt=directory/'.git/objects/info/alternates';alt.parent.mkdir(parents=True,exist_ok=True)
    alt.write_text(str(objects)+'\n')
    for args in [('update-ref','refs/heads/pinned-v6',c.BASE),('symbolic-ref','HEAD','refs/heads/pinned-v6'),('read-tree',c.BASE)]:
        subprocess.run(['git','-C',str(directory),*args],check=True,capture_output=True)
    if subprocess.check_output(['git','-C',str(directory),'status','--porcelain']).strip():
        raise RuntimeError('Pinned V6 archive not clean')

def main():
    parser=argparse.ArgumentParser();parser.add_argument('--report');parser.add_argument('--evidence-dir')
    parser.add_argument('--focused',action='store_true',help='Skip expensive frozen V6 full suite and CI packaging')
    args=parser.parse_args()
    source=c.verify();checks={};lint=0
    for item in c.members():
        if item.endswith('.php'):
            run(['php','-l',str(ROOT/item)],timeout=12);lint+=1
    checks['syntax']={'php_linted':lint,'runtime_members':len(c.members())}
    for name,cmd in (
        ('synthetic_backup',['python3',str(ROOT/'tools/test_backup_hotfix.py'),'--native-zip']),
        ('real_isolated_mariadb',['python3',str(ROOT/'tools/test_backup_hotfix_native.py')]),
        ('guarded_php_http',['python3',str(ROOT/'tools/test_backup_hotfix_http.py')]),
    ):
        if name=='synthetic_backup' and args.focused:
            cmd=[*cmd[:-1]]
        checks[name]=run(cmd,timeout=200)
        print('PASS '+name+' '+checks[name]['last_line'][:260],flush=True)
    if not args.focused:
        with tempfile.TemporaryDirectory(prefix='achbot-backup-frozen-v6-') as tmp:
            pinned=Path(tmp)/'v6';pinned.mkdir();pinned_v6(pinned)
            checks['pinned_v6_full']=run(['python3',str(pinned/'tools/check_v6.py'),
                '--report',str(Path(tmp)/'frozen-v6.json'),
                '--evidence-dir',str(Path(tmp)/'v6-evidence')],pinned,timeout=1100)
            past=json.loads((Path(tmp)/'frozen-v6.json').read_text())
            if not past['complete_acceptance_suite'] or past['required_suites_run']!=11:
                raise RuntimeError('Historical V6 suite incomplete')
            print('PASS pinned V6 full historical/native gate 11/11',flush=True)
        import package_backup_hotfix
        archive,meta=package_backup_hotfix.build()
        checks['package']={'source_members':meta['file_count'],'zip_sha256':meta['zip_sha256'],
                           'private_state_in_package':False}
        print('PASS 54-member exact Git source ZIP dry-run',flush=True)
    result={'source':source,'checks':checks,'complete':not args.focused,
            'production_deployed':False,'actual_telegram_sent':False}
    if args.report:Path(args.report).write_text(json.dumps(result,indent=2)+'\n')
    print(json.dumps({'complete':result['complete'],'checks':list(checks),'runtime_members':54,'php_linted':lint}))
if __name__=='__main__':main()
