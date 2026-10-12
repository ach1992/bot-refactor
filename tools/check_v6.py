#!/usr/bin/env python3
"""AChBot V6: full preserved V0-V5 plus whole-domain old/new native acceptance."""
from __future__ import annotations
import argparse,hashlib,io,json,os,shutil,subprocess,tarfile,tempfile
from pathlib import Path
import package_v6
import v6_contract as c
ROOT=c.ROOT

def execute(command:list[str],cwd:Path,env:dict,timeout=360):
    result=subprocess.run(command,cwd=cwd,env=env,text=True,capture_output=True,timeout=timeout)
    if result.returncode:
        raise RuntimeError('V6 required check failed: '+str(command)+'\nstdout:\n'+result.stdout[-2200:]+ '\nstderr:\n'+result.stderr[-1900:])
    return {'name':Path(command[1]).name,'stdout_sha256':hashlib.sha256(result.stdout.encode()).hexdigest(),
            'last_line':result.stdout.strip().splitlines()[-1] if result.stdout.strip() else '',
            'stderr':result.stderr[-350:]}

def pinned_v5(out:Path, env:dict):
    # A separately staged immutable Git object/index view makes original
    # V5 verifier see the genuine release HEAD, with no branch/ref mutation.
    v5=out/'v5';v5.mkdir()
    archive=c.git('archive','--format=tar',c.BASE)
    with tarfile.open(fileobj=io.BytesIO(archive)) as tf:
        members=tf.getmembers()
        if len(members)>2000 or sum(x.size for x in members)>100*1024*1024:
            raise RuntimeError('Pinned V5 archive too large')
        for m in members:
            n=Path(m.name)
            if n.is_absolute() or '..' in n.parts or not (m.isfile() or m.isdir()):
                raise RuntimeError('Unsafe V5 archive member')
        tf.extractall(v5,filter='data')
    subprocess.run(['git','init','--quiet',str(v5)],check=True,capture_output=True)
    common=Path(subprocess.check_output(['git','-C',str(ROOT),'rev-parse','--path-format=absolute','--git-common-dir'],text=True).strip())
    alt=v5/'.git/objects/info/alternates';alt.parent.mkdir(parents=True,exist_ok=True)
    alt.write_text(str(common/'objects')+'\n')
    def vg(*parts):
        subprocess.run(['git','-C',str(v5),*parts],check=True,capture_output=True)
    vg('update-ref','refs/heads/frozen-v5',c.BASE)
    vg('symbolic-ref','HEAD','refs/heads/frozen-v5')
    vg('read-tree',c.BASE)
    e=dict(env)
    snapshot=subprocess.check_output(['git','-C',str(v5),'status','--porcelain'],env=e)
    if snapshot.strip():raise RuntimeError('Pinned V5 source archive/index not clean')
    return v5,e

def main():
    ap=argparse.ArgumentParser(description=__doc__)
    ap.add_argument('--report',type=Path)
    ap.add_argument('--evidence-dir',type=Path)
    ap.add_argument('--focused',action='store_true',help='Development diagnostics only, not release acceptance')
    args=ap.parse_args();source=c.verify()
    if args.evidence_dir:
        if args.evidence_dir.resolve()==ROOT or ROOT in args.evidence_dir.resolve().parents:
            raise RuntimeError('Evidence must not be created in repository')
        args.evidence_dir.mkdir(parents=True,exist_ok=True)
    report={'source':{k:v for k,v in source.items() if k!='body_sha256'},'suites':{},
            'exact_v5_base':c.BASE,'private_network_allowed':False}
    print('PASS pinned exact V5-to-V6 extraction, 23-table schema and frozen historical identities',flush=True)
    lint=[]
    for path in c.runtime_files():
        if not path.endswith('.php'):continue
        execute(['php','-l',str(ROOT/path)],ROOT,dict(os.environ),timeout=15)
        lint.append(path)
    report['php_linted']=len(lint)
    archive,manifest=package_v6.build_bytes()
    report['source_only_runtime_members']=len(manifest)
    report['dry_run_zip_sha256']=hashlib.sha256(archive).hexdigest()
    print('PASS PHP syntax '+str(len(lint))+' files and deterministic 53-member ZIP dry-run',flush=True)
    env=dict(os.environ,ACHBOT_PRIVATE_TEST='1')
    if not args.focused:
        with tempfile.TemporaryDirectory(prefix='achbot-v6-frozen-v5-') as t:
            v5,venv=pinned_v5(Path(t),env)
            previous_report=Path(t)/'v5-check.json'
            report['suites']['frozen_v0_v5']=execute([
                'python3',str(v5/'tools/check_v5.py'),'--report',str(previous_report),
                '--evidence-dir',str(Path(t)/'historical-evidence')],v5,venv,timeout=1100)
            prev=json.loads(previous_report.read_text())
            if not prev['complete_acceptance_suite'] or len(prev['suites'])!=11 or prev['php_linted']!=42:
                raise RuntimeError('Incomplete pinned historical V0-V5 gate')
            report['frozen_v5']={'suite_names':sorted(prev['suites']),
                                  'php_linted':prev['php_linted'],'complete':True,
                                  'evidence_sha256':hashlib.sha256(previous_report.read_bytes()).hexdigest()}
            if args.evidence_dir:
                shutil.copyfile(previous_report,args.evidence_dir/'frozen-v5-full.json')
            print('PASS pinned full historical V0-V5 acceptance ('+str(len(prev['suites']))+' suites)',flush=True)
    commands=[
        ('panel_and_helpers_differential','test_v6_panels.py',[]),
        ('subscription_telegram_differential','test_v6_routes.py',[]),
        ('account_deletion_and_url_negative_controls','test_v6_mutation.py',[]),
        ('cross_consumer_original_bytes','test_v6_consumers.py',[]),
    ]
    if not args.focused:
        commands += [
            ('real_php_mariadb_http_providers','test_v6_native.py',['--run-disposable']),
            ('native_mariadb_telegram_account_ops','test_v6_native_routes.py',['--run-disposable']),
            ('public_sublink_native_http','test_v6_subscription_http.py',['--run-disposable']),
            ('source_only_persistent_rollback','test_v6_swap.py',['--run-disposable']),
            ('new_internal_guards','test_v6_http.py',['--run-loopback'])]
    for name,file,flags in commands:
        cmd=['python3',str(ROOT/'tools'/file),*flags]
        if args.evidence_dir:cmd+=['--report',str(args.evidence_dir/(name+'.json'))]
        report['suites'][name]=execute(cmd,ROOT,env,timeout=360)
        print('PASS '+name+' '+report['suites'][name]['last_line'][:350],flush=True)
    report['complete_acceptance_suite']=not args.focused
    report['required_suites_run']=len(report['suites'])
    report['new_suites']=len(commands)
    report['exclusions']=['real panel/Telegram/payment/provider access','live baseInfo.php','production operator acceptance']
    if args.report:args.report.write_text(json.dumps(report,ensure_ascii=False,indent=2)+'\n')
    print(json.dumps({'complete':not args.focused,'php_linted':len(lint),
                      'old_runtime':source['old_runtime'],'new_modules':source['new_modules'],
                      'new_suites':len(commands),'full_including_historical':len(report['suites']),
                      'runtime_members':len(manifest)}))
if __name__=='__main__':main()
