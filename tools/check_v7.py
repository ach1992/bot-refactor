#!/usr/bin/env python3
"""AChBot V7 exact-source acceptance and frozen V0-V6.1 regression pipeline."""
from __future__ import annotations
import argparse,hashlib,io,json,os,subprocess,sys,tarfile,tempfile
from pathlib import Path
import v7_contract as c
ROOT=c.ROOT

def run(cmd:list[str],cwd:Path=ROOT,timeout:int=1100):
    p=subprocess.run(cmd,cwd=cwd,env=dict(os.environ,ACHBOT_PRIVATE_TEST='1'),
                     text=True,capture_output=True,timeout=timeout)
    if p.returncode:
        raise RuntimeError('Acceptance failed '+repr(cmd)+'\nstdout: '+p.stdout[-2800:]+'\nstderr: '+p.stderr[-2800:])
    return {'sha256':hashlib.sha256(p.stdout.encode()).hexdigest(),
            'tail':p.stdout.strip().splitlines()[-1] if p.stdout.strip() else ''}

def pin_v61(target:Path):
    target.mkdir(parents=True,exist_ok=True)
    archive=c.git('archive','--format=tar',c.BASE)
    with tarfile.open(fileobj=io.BytesIO(archive)) as tar:
        members=tar.getmembers()
        if len(members)>2000 or sum(m.size for m in members)>120*1024*1024:
            raise RuntimeError('Invalid pinned V6.1 historical archive')
        for member in members:
            path=Path(member.name)
            if path.is_absolute() or '..' in path.parts or not (member.isfile() or member.isdir()):
                raise RuntimeError('Unsafe pinned historical member')
        tar.extractall(target,filter='data')
    subprocess.run(['git','init','--quiet',str(target)],check=True,capture_output=True)
    objects=Path(c.git('rev-parse','--path-format=absolute','--git-common-dir').decode().strip())/'objects'
    alternates=target/'.git/objects/info/alternates';alternates.parent.mkdir(parents=True,exist_ok=True)
    alternates.write_text(str(objects)+'\n')
    for cmd in [('update-ref','refs/heads/frozen-v61',c.BASE),
                ('symbolic-ref','HEAD','refs/heads/frozen-v61'),('read-tree',c.BASE)]:
        subprocess.run(['git','-C',str(target),*cmd],check=True,capture_output=True)
    if subprocess.check_output(['git','-C',str(target),'status','--porcelain']).strip():
        raise RuntimeError('Frozen exact V6.1 work tree is dirty')
    if subprocess.check_output(['git','-C',str(target),'rev-parse','HEAD']).decode().strip()!=c.BASE:
        raise RuntimeError('Pinned baseline mismatch')

def main():
    p=argparse.ArgumentParser(description=__doc__)
    p.add_argument('--report',type=Path);p.add_argument('--evidence-dir',type=Path)
    p.add_argument('--php81',action='store_true',help='8.1 cross-runtime + pinned V6.1 compatibility')
    p.add_argument('--focused',action='store_true',help='V7 local fast feedback only; NOT acceptance')
    a=p.parse_args()
    src=c.verify();checks={}
    php=subprocess.check_output(['php','-r','echo PHP_VERSION_ID;']).decode().strip()
    if a.php81 and not php.startswith('801'):
        raise RuntimeError('PHP 8.1 gate must use PHP 8.1; got '+php)
    if not a.php81 and not a.focused and not php.startswith('804'):
        raise RuntimeError('Full acceptance requires PHP 8.4; got '+php)
    php_members=[n for n in c.members() if n.endswith('.php')]
    for name in php_members:run(['php','-l',str(ROOT/name)],timeout=15)
    checks['syntax']={'php_version_id':int(php),'php_members':len(php_members)}
    work=a.evidence_dir
    if work:work.mkdir(parents=True,exist_ok=True)
    programs=[
        ('facade_signatures','test_v7_facades.py',False),
        ('full_original_schema_native_e2e','test_v7_native.py',True),
        ('mutation_detection','test_v7_mutation.py',True),
        ('source_only_swap','test_v7_swap.py',True),
        ('internal_http','test_v7_http.py',False),
    ]
    for label,tool,native_required in programs:
        cmd=['python3',str(ROOT/'tools'/tool)]
        if native_required:cmd.append('--run-disposable')
        report_path=work/(label+'.json') if work else None
        if report_path:cmd+=['--report',str(report_path)]
        checks[label]=run(cmd,timeout=350)
        detail=json.loads(checks[label]['tail']) if label not in ('full_original_schema_native_e2e',) else json.loads(checks[label]['tail'])
        if label=='full_original_schema_native_e2e' and (detail['cases']!=47 or detail['scenarios']!=18):
            raise RuntimeError('V7 native scenario coverage regressed')
        if label=='mutation_detection' and (detail['mutations']!=5 or not detail['all_detected']):
            raise RuntimeError('V7 mutation controls did not execute')
        if label=='source_only_swap' and len(detail['phases'])!=4:
            raise RuntimeError('V7 source swap did not execute')
        print('PASS '+label+' '+checks[label]['tail'][:260],flush=True)
    if not a.focused:
        with tempfile.TemporaryDirectory(prefix='achbot-v7-frozen-v61-') as tmp:
            base=Path(tmp)/'v61';pin_v61(base)
            hist_cmd=['python3',str(base/'tools/check_backup_hotfix.py')]
            if a.php81:hist_cmd.append('--focused')
            previous=Path(tmp)/'historical.json'
            hist_cmd+=['--report',str(previous)]
            checks['pinned_original_v61']=run(hist_cmd,base,timeout=1200)
            historic=json.loads(previous.read_text())
            if historic['complete'] != (not a.php81):
                raise RuntimeError('Incorrect frozen history acceptance completeness')
            if a.php81:
                checks['pinned_php81_native_zip']=run(['python3',str(base/'tools/test_backup_hotfix.py'),'--native-zip'],base,timeout=220)
            if work:
                (work/'pinned-v61.json').write_text(json.dumps(historic,indent=2)+'\n')
            print('PASS pinned V6.1 '+('PHP 8.1 maintenance compatibility' if a.php81 else 'full V0-V6.1 historical suite'),flush=True)
    if not a.focused and not a.php81 and not checks['pinned_original_v61']:
        raise RuntimeError('Frozen historical suite missing')
    evidence={'source':{k:v for k,v in src.items() if k!='baseline_body_sha256'},
              'php_version_id':int(php),'checks':checks,'focused':a.focused,
              'full_historical_v0_to_v61':not a.php81 and not a.focused,
              'php81_compatibility':a.php81,'owner_operator_acceptance':False,
              'owner_merge_approved':False,'production_deployed':False,
              'sql_restore_tested':False}
    if a.report:a.report.write_text(json.dumps(evidence,indent=2)+'\n')
    print(json.dumps({'complete':not a.focused,'php81':a.php81,'checks':list(checks),
                      'php_linted':len(php_members),'source_members':len(c.members())}))
if __name__=='__main__':main()
