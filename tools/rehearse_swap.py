#!/usr/bin/env python3
"""Private file-swap drill plus installed-source CLI smoke; not an FPM/live-provider test."""
from __future__ import annotations
import argparse,hashlib,json,shutil,tempfile
from pathlib import Path
import check_v1
import v1_contract as v1
from v2_contract import ROOT,previous,verify,V1,V1_TREE,candidate_identity
run=check_v1.run
SMOKE=['pure.contracts','menu.customer','menu.agent','menu.admin_main','menu.regular_admin','menu.special_admin',
 'command.buy','command.mysubs','wallet.success','wallet.already','wallet.low','wallet.handler_exception','provision.marzban',
 'provision.xui','renew.marzban','transport.json','transport.429','transport.failure','cart.agent','cart.low',
 'entry.bot_start','entry.bot_main_callback','entry.bot_legacy_callback','entry.broadcast_pin','entry.health_get']

def copy_members(source:Path,dest:Path,paths:list[str]):
    for file in paths:
        p=dest/file;p.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(source/file,p)

def runtime(root:Path,lock:dict):
    return sorted([f for f in lock['files'] if not f.startswith('database/')]+[p.relative_to(root).as_posix() for p in (root/'src').rglob('*.php')])

def drill(old:Path,new:Path,lock:dict,label:str):
    with tempfile.TemporaryDirectory(prefix='achbot-source-swap-') as tmp:
        stage=Path(tmp);before_files=runtime(old,lock);after_files=runtime(new,lock)
        copy_members(old,stage,before_files)
        # Test support lives only in this private CLI copy; it is never a runtime ZIP member.
        shutil.copytree(old/'tests/support',stage/'tests/support');(stage/'tools').mkdir()
        shutil.copyfile(old/'tools/source_scan.php',stage/'tools/source_scan.php')
        private={'baseInfo.php':run.BASEINFO.encode(),'settings/QRCode.jpg':b'fixture-asset-sentinel',
                 'settings/temp.txt':b'12345-7','private-state.json':b'{"step":"pending_fixture_step","wallet":12000}'}
        for path,content in private.items():p=stage/path;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(content)
        expected=json.loads((old/'tests/fixtures/golden.json').read_text())['cases'];phases=[]
        def smoke(phase):
            funcs,_=check_v1.extract(stage);run.ROOT=stage
            files=[p for p in sorted(set(before_files+after_files)) if (stage/p).exists()]
            for case in SMOKE:
                if run.run_case(case,funcs,files)!=expected[case]:raise RuntimeError(label+' '+phase+' mismatch '+case)
            if any((stage/p).read_bytes()!=value for p,value in private.items()):raise RuntimeError('Private config/state overwritten')
            phases.append({'phase':phase,'cli_cases':len(SMOKE),'private_files_preserved':len(private)})
        smoke('before')
        copy_members(new,stage,[f for f in after_files if f.startswith('src/')]);smoke('modules-first-old-facades')
        copy_members(new,stage,[f for f in after_files if not f.startswith('src/')])
        if any((stage/f).read_bytes()!=(new/f).read_bytes() for f in after_files):raise RuntimeError('Incomplete file activation')
        smoke('new-source-active')
        copy_members(old,stage,before_files)
        if any((stage/f).read_bytes()!=(old/f).read_bytes() for f in before_files):raise RuntimeError('Code rollback mismatch')
        smoke('old-source-restored')
        return {'transition':label,'phases':phases,'private_file_hashes':{p:hashlib.sha256(b).hexdigest() for p,b in private.items()},
                'schema_file_unchanged':(old/'database/legacy-schema.sql').read_bytes()==(new/'database/legacy-schema.sql').read_bytes(),
                'live_database_used':False,'actual_database_continuity_tested':False,'fpm_opcache_tested':False,'provider_calls':'scripted doubles',
                'operator_manual_acceptance':False,'scope':'module-first file copy and CLI smoke from installed bytes; private file sentinels are not a real DB'}

def main():
    ap=argparse.ArgumentParser(description=__doc__);ap.add_argument('--report',type=Path,required=True);args=ap.parse_args()
    with previous() as (old,lock),v1.reference() as (v0,_):
        verify(ROOT,old,lock)
        reports=[drill(v0,old,lock,'V0 -> integrated V1 -> V0'),drill(old,ROOT,lock,'integrated V1 -> V2 candidate -> V1')]
    summary={'v1_commit':V1,'v1_tree':V1_TREE,'drills':reports,'production_approved':False}
    summary.update(candidate_identity())
    args.report.write_text(json.dumps(summary,indent=2)+'\n')
    print(json.dumps({'drills':len(reports),'phases':sum(len(r['phases']) for r in reports),'cli_smoke_executions':sum(p['cli_cases'] for r in reports for p in r['phases']),
      'private_files_preserved':True,'database_schema_file_unchanged':True,'operator_manual_acceptance':False,'production_approved':False}))
if __name__=='__main__':main()
