#!/usr/bin/env python3
"""Original source V6.1 -> V7 -> V6.1 -> V7 overlay on one owned DB.

Leaves dormant guarded new modules after rollback, never replaces private
config, and never restores a DB snapshot to make rollback appear successful.
"""
from __future__ import annotations
import argparse,hashlib,json,re
from pathlib import Path
import v7_contract as c
import test_v7_native as e2e
import test_v6_native as native
import test_access_state as support

def overlay(app:Path,version:str):
    if version=='v7':
        for name in c.MODULES:
            dest=app/name;dest.parent.mkdir(parents=True,exist_ok=True)
            dest.write_bytes((c.ROOT/name).read_bytes())
    for name in c.v61.members():
        dest=app/name;dest.parent.mkdir(parents=True,exist_ok=True)
        expected=(c.ROOT/name).read_bytes() if version=='v7' else c.old(name)
        dest.write_bytes(expected)
    for name in c.v61.members()+ (c.MODULES if version=='v7' else []):
        expected=(c.ROOT/name).read_bytes() if version=='v7' else c.old(name)
        if (app/name).read_bytes()!=expected:
            raise RuntimeError('Source-only overlay member differs: '+name)
    return len(c.v61.members())+(len(c.MODULES) if version=='v7' else 0)

def main():
    ap=argparse.ArgumentParser();ap.add_argument('--run-disposable',required=True,action='store_true')
    ap.add_argument('--report',type=Path);a=ap.parse_args();c.verify()
    phases=[]
    with support.owned_database() as (work,env),native.owned_provider() as (provider,url):
        e2e.init(work,env,url)
        app=work/'app';e2e.stage(app,False)
        sentinel=b'<?php // disposable private config, not executable here\n'
        (app/'baseInfo.php').write_bytes(sentinel)
        (app/'private-state.json').write_bytes(b'fixture private state; persists over overlay\n')
        before_private=[hashlib.sha256((app/name).read_bytes()).hexdigest()
                        for name in ('baseInfo.php','private-state.json')]
        db=e2e.SIDE[1]
        # Create a real persisted order with the OLD V6.1 code before any upgrade.
        overlay(app,'v61')
        e2e.run(work,env,app,db,'selectplan',{'data':'selectPlan7_1_none','actor':1001},modern=False)
        provider.trace.clear()
        paid=e2e.run(work,env,app,db,'wallet',{'data':'payBuyWithWalletxxxxxxxxx','actor':1001},modern=False)
        if len(provider.trace)<3 or paid['exception']:
            raise RuntimeError('Old source failed to create an owned paid service')
        stable_state=e2e.snapshot(work,env,db)
        stable_orders=native.sql(work,env,'SELECT COUNT(*) FROM orders_list',db).strip()
        if stable_orders!='1':raise RuntimeError('Prior persisted order not established')
        tables=native.sql(work,env,'SHOW TABLES',db).splitlines()
        def ddl():
            return [re.sub(r' AUTO_INCREMENT=\d+','',native.sql(work,env,'SHOW CREATE TABLE `'+name+'`',db)) for name in tables]
        schema_before=ddl();expected=None
        for idx,version in enumerate(('v61','v7','v61','v7')):
            count=overlay(app,version)
            if [hashlib.sha256((app/name).read_bytes()).hexdigest() for name in ('baseInfo.php','private-state.json')]!=before_private:
                raise RuntimeError('Private sentinel changed during source swap')
            provider.trace.clear()
            output=e2e.run(work,env,app,db,'catalogue',{'data':'selectServer2_none','actor':1001},modern=version=='v7')
            if output['exception'] or not output['events'] or provider.trace:
                raise RuntimeError('Catalogue broken across source swap '+version)
            if expected is None:expected=output
            if output!=expected or e2e.snapshot(work,env,db)!=stable_state:
                raise RuntimeError('Source swap changed Telegram or persisted SQL')
            if ddl()!=schema_before:raise RuntimeError('Original DDL drift during swap')
            phases.append({'phase':idx+1,'version':version,'source_members_verified':count,
                           'existing_paid_orders':int(stable_orders),
                           'telegram_trace_sha256':hashlib.sha256(json.dumps(output,sort_keys=True).encode()).hexdigest()})
    report={'phases':phases,'private_files_preserved':True,'source_only_rollback_without_db_restore':True,
            'unchanged_original_23_table_schema':True,'unchanged_existing_paid_order':True}
    if a.report:a.report.write_text(json.dumps(report,indent=2)+'\n')
    print(json.dumps(report),flush=True)
if __name__=='__main__':main()
