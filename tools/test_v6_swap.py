#!/usr/bin/env python3
"""Actual V5->V6->V5->V6 source-only overlay on persisted owned subscription DB."""
from __future__ import annotations
import argparse,hashlib,json,shutil
from pathlib import Path
import v6_contract as c
import test_v6_subscription_http as endpoint
import test_v6_native as native
import test_access_state as support
ROOT=c.ROOT

def main():
    p=argparse.ArgumentParser();p.add_argument('--run-disposable',required=True,action='store_true')
    p.add_argument('--report',type=Path);args=p.parse_args();c.verify()
    phases=[]
    with support.owned_database() as (work,env),native.owned_provider() as (provider,url):
        native.seed(work,env,url)
        app=work/'source-swap';endpoint.stage(app,'achbot_v4_new',False)
        original_base=(app/'baseInfo.php').read_bytes();private=(app/'private-state.json').read_bytes()
        original_sub=(app/'settings/subLink.php').read_bytes()
        pinned=c.reference('settings/subLink.php')
        assert original_sub==pinned
        # Record original real 23-table SHOW CREATE TABLE across swap.
        tables=native.sql(work,env,'SHOW TABLES','achbot_v4_new').splitlines()
        if len(tables)!=23:raise RuntimeError('Schema table count not 23')
        before_schema=[native.sql(work,env,'SHOW CREATE TABLE `'+table+'`','achbot_v4_new') for table in tables]
        for n,version in enumerate(['v5','v6','v5','v6']):
            source=(ROOT/'config.php').read_bytes() if version=='v6' else c.reference('config.php')
            if version=='v6':
                for path,ns in c.MODULES.items():
                    if ns is None:continue
                    target=app/path;target.parent.mkdir(parents=True,exist_ok=True)
                    shutil.copyfile(ROOT/path,target)
            compiled=endpoint.panels.function_bodies(source,c.PANEL_FUNCS+c.DETAIL_FUNCS)
            compiled+=b'\nfunction RandomString($count=9,$type="all"){return str_repeat("x",(int)$count);}\nfunction generateUID(){return "fixture-uid";}\n'
            (app/'config.php').write_bytes(compiled)
            if (app/'baseInfo.php').read_bytes()!=original_base or (app/'private-state.json').read_bytes()!=private:
                raise RuntimeError('Synthetic real-config or private files altered on phase '+str(n))
            if (app/'settings/subLink.php').read_bytes()!=original_sub:
                raise RuntimeError('Public endpoint altered during code overlay')
            # Single real PHP HTTP endpoint on same persistent DB in each phase.
            provider.trace.clear();web,port=endpoint.serve(app,work,env)
            try:result=endpoint.fetch(port,'a'*30)
            finally:
                if web.poll() is None:web.terminate()
                try:web.wait(timeout=5)
                except Exception:web.kill();web.wait(timeout=5)
            phases.append({'step':n+1,'version':version,'status':result['status'],
                'body_sha256':hashlib.sha256(result['body'].encode()).hexdigest(),
                'panel_calls':len(provider.trace)})
            if n and phases[n]['body_sha256']!=phases[0]['body_sha256']:
                raise RuntimeError('V5/V6/V5 public endpoint response changed on persisted database')
        after_schema=[native.sql(work,env,'SHOW CREATE TABLE `'+table+'`','achbot_v4_new') for table in tables]
        if after_schema!=before_schema:raise RuntimeError('Original schema drifted in source-only swap')
        rows=native.sql(work,env,'SELECT id,remark,link FROM orders_list WHERE id=91','achbot_v4_new')
        if not rows:raise RuntimeError('Persisted order vanished on rollback')
    report={'steps':len(phases),'phases':phases,'private_real_baseinfo_fixture_preserved':True,
       'same_persisted_original_23_table_schema':True,'subscription_response_identity':True,
       'no_file_tree_reset':True,'v5_new_modules_ignored_on_rollback':True}
    if args.report:args.report.write_text(json.dumps(report,indent=2)+'\n')
    print(json.dumps(report))
if __name__=='__main__':main()
