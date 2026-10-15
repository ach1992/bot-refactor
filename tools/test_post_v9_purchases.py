#!/usr/bin/env python3
"""Current Marzban-only purchase flow vs immutable V9 on owned PHP + MariaDB."""
from __future__ import annotations
import argparse
import hashlib
import json
import re
import subprocess
from pathlib import Path

import test_access_state as owned
import test_v6_native as native
import test_v8_native as purchase
import v9_contract as v9

ROOT=Path(__file__).resolve().parents[1]
HISTORY='7eb077fd8e6d0a303421626d1500f69e295a6664'
CASES=['customer_paid_wallet','agent_one_wallet','agent_many_wallet',
       'custom_paid_wallet','free_trial','free_trial_replay_denied',
       'admin_bulk_create','nonadmin_bulk_denied','invalid_category_no_purchase',
       'provider_reject_free','provider_unavailable_free',
       'admin_category_create','admin_category_edit','admin_plan_price_edit',
       'admin_plan_create_draft','admin_category_manage','interval_plan_manage']


def stage_source(stage:Path,current:bool)->dict:
    stage.mkdir(parents=True,exist_ok=False)
    for rel in v9.members():
        dest=stage/rel
        dest.parent.mkdir(parents=True,exist_ok=True)
        raw=(ROOT/rel).read_bytes() if current else subprocess.check_output(
            ['git','-C',str(ROOT),'show',HISTORY+':'+rel])
        dest.write_bytes(raw)
    fixture=(ROOT/'tests/v7/native-purchase.php').read_text()
    needle='function getConnectionLink(...$args)'
    assert fixture.count(needle)==1
    fixture=fixture.replace(needle,"function achbotSupportsPanel($type) { return $type === 'marzban'; }\n"+needle)
    (stage/'native-purchase.php').write_text(fixture)
    (stage/'settings/temp.txt').write_text('3000-0')
    (stage/'baseInfo.php').write_text('<?php // fixture only\n')
    (stage/'private-marker.json').write_text('{"synthetic":"only"}\n')
    return {name:hashlib.sha256((stage/name).read_bytes()).hexdigest()
            for name in ['baseInfo.php','private-marker.json']}


def ddl(work:Path,env:dict,db:str,tables:list[str])->list[str]:
    return [re.sub(r' AUTO_INCREMENT=\d+','',
                   native.sql(work,env,'SHOW CREATE TABLE '+chr(96)+t+chr(96),db))
            for t in tables]


def main():
    ap=argparse.ArgumentParser()
    ap.add_argument('--run-disposable',action='store_true',required=True)
    ap.add_argument('--report',type=Path)
    args=ap.parse_args()
    if subprocess.check_output(['git','-C',str(ROOT),'rev-parse',HISTORY+'^{tree}'],text=True).strip()!='7da17725c1e7f0448119b7e41c8b11b21f3e093d':
        raise RuntimeError('Original V9 TREE drift')
    records=[]
    with owned.owned_database() as (work,env),native.owned_provider() as (provider,url):
        old=work/'original-v9'
        current=work/'marzban-only'
        private_old=stage_source(old,False)
        private_new=stage_source(current,True)
        tables=native.sql(work,env,'SHOW TABLES',purchase.SIDE[0]).splitlines()
        if len(tables)!=23:raise RuntimeError('Not original 23-table schema')
        initial={db:ddl(work,env,db,tables) for db in purchase.SIDE}
        if initial[purchase.SIDE[0]]!=initial[purchase.SIDE[1]]:
            raise RuntimeError('Initial DDL mismatch')
        for label in CASES:
            purchase.init(work,env,url)
            provider.mode={'provider_reject_free':'marzban-invalid',
                           'provider_unavailable_free':'marzban-503'}.get(label,'ok')
            steps=purchase.series()[label]
            if label=='admin_plan_create_draft':
                steps=[(kind,{**spec,'data':'addNewMarzbanPlan'} if spec.get('data')=='addNewPlan' else spec)
                       for kind,spec in steps]
            for i,(kind,spec) in enumerate(steps):
                observations=[];wires=[];states=[]
                for stage,db in ((old,purchase.SIDE[0]),(current,purchase.SIDE[1])):
                    provider.trace.clear()
                    result=purchase.run(work,env,stage,db,kind,spec,modern=True)
                    observations.append(result)
                    wires.append(list(provider.trace))
                    states.append(purchase.snapshot(work,env,db))
                if observations[0]!=observations[1] or wires[0]!=wires[1] or states[0]!=states[1]:
                    reason={'case':label,'step':i,'route':kind,
                            'events_equal':observations[0]==observations[1],
                            'wire_equal':wires[0]==wires[1],
                            'sql_equal':states[0]==states[1],
                            'old_event':observations[0],'new_event':observations[1]}
                    raise RuntimeError('Marzban source drift: '+json.dumps(reason,ensure_ascii=False)[:4000])
                if kind=='wallet' and provider.mode=='ok' and not wires[0]:
                    raise RuntimeError('Wallet action never reached fake provider')
                if kind=='trialprovision' and provider.mode!='ok' and not wires[0]:
                    raise RuntimeError('Provider rejection did not exercise error')
                records.append({'case':label,'step':i,'kind':kind,
                                'fake_provider_calls':len(wires[0]),
                                'sql_sha256':hashlib.sha256(
                                    json.dumps(states[0],sort_keys=True).encode()).hexdigest()})
            purchase.assert_outcome(work,env,label)
            for db in purchase.SIDE:
                if ddl(work,env,db,tables)!=initial[db]:
                    raise RuntimeError('Schema changed during '+label)
        for stage,private in ((old,private_old),(current,private_new)):
            for name,hashval in private.items():
                if hashlib.sha256((stage/name).read_bytes()).hexdigest()!=hashval:
                    raise RuntimeError('Private synthetic file changed')
    report={'complete':True,'historical_v9':HISTORY,'scenarios':len(CASES),
            'steps':len(records),'schema_tables':23,'mysql_socket_only':True,
            'php_sql_telegram_fake_provider_parity':True,
            'live_provider_or_real_money_tested':False,'details':records}
    if args.report:
        args.report.write_text(json.dumps(report,indent=2,ensure_ascii=False)+'\n')
    print(json.dumps({k:v for k,v in report.items() if k!='details'}))

if __name__=='__main__':
    main()
