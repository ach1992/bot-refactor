#!/usr/bin/env python3
"""Actual PHP 8.x, owned MariaDB, and stateful loopback Marzban PUT/GET acceptance.

Tests that customer/admin success cannot follow a merely-acknowledged PUT.
The only automatic repair is a bounded absolute write of the same target
following confirmed unchanged baseline. Never a second relative increment
or a second traffic reset. No installed panel, customer or payment.
"""
import argparse
import shutil
import json
from pathlib import Path
from concurrent.futures import ThreadPoolExecutor

import test_access_state as owned
import test_v6_native as native
import test_post_v9_marzban as marzban

ROOT=Path(__file__).resolve().parents[1]
GIB=1073741824
INITIAL={
    'username':'fixture-user','status':'active',
    'data_limit':2*GIB,'used_traffic':GIB,
    'expire':1800000100,'inbounds':{},'proxies':{},
    'note':'fixture','data_limit_reset_strategy':'no_reset',
    'subscription_url':'/sub/fixture'
}

def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('--run-disposable',action='store_true',required=True)
    parser.add_argument('--report',type=Path)
    args=parser.parse_args()
    cases=[]
    with owned.owned_database() as (work,env),native.owned_provider() as (panel,url):
        native.seed(work,env,url)
        source=work/'candidate'
        marzban.prepare(work,source,True)
        core=source/'src/Purchase/Core.php'
        core.parent.mkdir(parents=True,exist_ok=True)
        core.write_bytes((ROOT/'src/Purchase/Core.php').read_bytes())
        # Test the SAME general dispatcher used by checkout, wallet, bank
        # receipts, auto-renew and future panel adapters; no hidden fake
        # provider support is inferred for an unimplemented panel.
        with (source/'functions-under-test.php').open('a') as facade:
            facade.write("\nrequire_once __DIR__.'/src/Purchase/Core.php';\n"
                         "function achbotOwnedEditRouter($serverId,$change){"
                         "global $connection;"
                         "return achbotApplyServiceEdit($connection,$serverId,$change);}\n")

        for mode,kwargs,want,puts,resets in (
            ('edit-verified',{'plus_volume':1},'verified',1,0),
            ('edit-lost-applied',{'plus_volume':1},'reconciled',1,0),
            ('edit-repair',{'plus_volume':1},'repaired',2,0),
            ('edit-lost-repair',{'plus_volume':1},'repaired',2,0),
            ('edit-badbody-repair',{'plus_volume':1},'repaired',2,0),
            ('edit-noop',{'plus_volume':1},'unverified',2,0),
            ('edit-bad-number-baseline',{'plus_volume':1},'unverified',0,0),
            ('edit-bad-number-readback',{'plus_volume':1},'unverified',1,0),
            ('edit-foreign',{'plus_volume':1},'unverified',1,0),
            ('edit-reset-verified',{'days':30,'volume':3},'verified',1,1),
            ('edit-reset-lie',{'days':30,'volume':3},'reset_unverified',0,1),
        ):
            panel.mode=mode
            panel.trace.clear()
            panel.edit_account=INITIAL.copy()
            panel.edit_put_count=0
            panel.edit_reset_count=0
            result=native.run(work,env,source,native.DBS[1],
                {'function':'editMarzbanConfig',
                 'args':[2,{'remark':'fixture-user',**kwargs}]})
            if result['exception'] or result['warnings'] or result['stdout']:
                raise RuntimeError('Actual PHP Marzban edit runtime failure: '+repr((mode,result)))
            value=result['result']
            if not isinstance(value,dict):
                raise RuntimeError('Missing provider edit verdict: '+repr((mode,value)))
            if want in ('verified','reconciled','repaired'):
                if value.get('success') is not True or value.get('verified') is not True:
                    raise RuntimeError('Provider confirmed without verified GET: '+repr((mode,value)))
                if want=='reconciled' and value.get('reconciled') is not True:
                    raise RuntimeError('Lost response was not reconciled: '+repr((mode,value)))
                if want=='repaired' and value.get('repaired') is not True:
                    raise RuntimeError('Safe corrective absolute PUT was not detected: '+repr((mode,value)))
            elif value.get('success') is not False or value.get('uncertain') is not True:
                raise RuntimeError('Unknown/unapplied provider operation misreported success: '+repr((mode,value)))
            trace=[e['method'] for e in panel.trace]
            if (panel.edit_put_count!=puts or panel.edit_reset_count!=resets or
                trace.count('PUT')!=puts or trace.count('GET')<1):
                raise RuntimeError('Wrong bounded Marzban wire requests: '+
                    repr((mode,puts,resets,trace,panel.edit_put_count,panel.edit_reset_count)))
            if mode=='edit-reset-lie' and value.get('msg')!='marzban_reset_readback_unverified':
                raise RuntimeError('Traffic reset non-effect did not block renewal')
            if want in ('verified','reconciled','repaired'):
                expected_volume=(3 if 'volume' in kwargs else 3)*GIB
                if panel.edit_account['data_limit']!=expected_volume:
                    raise RuntimeError('Remote quota did not really change: '+mode)
            cases.append(mode+'_source_native_'+want)

        # The legacy quote schema accepts FLOAT, while the old audit stores
        # integer units. Reject unsupported fractions rather than claiming
        # an incomplete 1.5 GB / 0.5 day paid service as fulfilled.
        for label,change in (
            ('volume_delta',{'plus_volume':1.5}),
            ('day_delta',{'plus_day':0.5}),
            ('plan_quota',{'days':30,'volume':1.5})
        ):
            panel.mode='edit-verified'
            panel.trace.clear()
            panel.edit_account=INITIAL.copy()
            rejected=native.run(work,env,source,native.DBS[1],{
                'function':'editMarzbanConfig',
                'args':[2,{'remark':'fixture-user',**change}]})
            assert rejected['exception'] is None and rejected['result']['success'] is False,rejected
            assert rejected['result']['msg'] in ('invalid_volume','invalid_days'),rejected
            assert not any(x['method'] in ('PUT','DELETE') or '/reset' in x['path']
                           for x in panel.trace),panel.trace
            cases.append('fractional_'+label+'_never_truncated_or_approved')

        # Genuine Marzban performs exact GET+PUT+GET using the shared
        # provider-neutral dispatcher. A plausible future panel type remains
        # explicitly UNSUPPORTED until its adapter/verified-readback exists.
        panel.mode='edit-verified'
        panel.trace.clear()
        panel.edit_account=INITIAL.copy()
        panel.edit_put_count=0
        good=native.run(work,env,source,native.DBS[1],{
            'function':'achbotOwnedEditRouter',
            'args':[2,{'remark':'fixture-user','plus_volume':1}]})
        assert good['exception'] is None and good['result']['success'] is True,good
        assert good['result']['verified'] is True
        assert any(event['method']=='PUT' for event in panel.trace)
        cases.append('panel_neutral_dispatch_to_current_verified_marzban_adapter')

        native.sql(work,env,"UPDATE server_config SET type='pasarguard' WHERE id=2",
                   native.DBS[1])
        panel.trace.clear()
        future=native.run(work,env,source,native.DBS[1],{
            'function':'achbotOwnedEditRouter',
            'args':[2,{'remark':'fixture-user','plus_volume':1}]})
        assert future['exception'] is None and future['result']['success'] is False,future
        assert future['result']['msg']=='unsupported_service_adapter',future
        assert panel.trace==[],panel.trace
        native.sql(work,env,"UPDATE server_config SET type='marzban' WHERE id=2",
                   native.DBS[1])
        cases.append('unimplemented_future_panel_denied_without_remote_dispatch')

        # Each parallel PHP worker needs its OWN fixture directory. Sharing
        # native.php/case.json/result.json would falsely test file races rather
        # than the real two-process MariaDB advisory-lock behavior.
        sites=[]
        for worker in (0,1):
            site=work/('service-edit-worker-'+str(worker))
            shutil.copytree(source,site)
            sites.append(site)

        # Two different payments applying independent increments to one
        # account are serialized. No lost update is permissible.
        panel.mode='edit-verified'
        panel.edit_account=INITIAL.copy()
        panel.edit_put_count=0
        panel.edit_reset_count=0
        with ThreadPoolExecutor(max_workers=2) as pool:
            results=list(pool.map(
                lambda worker:native.run(work,env,sites[worker],native.DBS[1],{
                   'function':'editMarzbanConfig',
                   'args':[2,{'remark':'fixture-user','plus_volume':1}]
                }),range(2)))
        if any(r['exception'] or r['warnings'] or r['stdout'] or
               r['result'].get('success') is not True for r in results):
            raise RuntimeError('Parallel distinct modifications did not both verify: '+repr(results))
        if panel.edit_account['data_limit']!=4*GIB or panel.edit_put_count!=2:
            raise RuntimeError('Lost update under independent concurrent modifications')
        cases.append('two_concurrent_distinct_edits_serialized_no_lost_increment')

        # Physical aliases can use DIFFERENT URLs and still share a Marzban
        # user database. 001's explicit group ID, not raw endpoint spelling,
        # must be the account-level serialization key.
        native.sql(work,env,
            (ROOT/'migrations/post-v9/001_marzban_panel_group_id.sql').read_text(),
            native.DBS[1])
        native.sql(work,env,
            "INSERT INTO setting (id,type,value) VALUES "
            "(900001,'ACHBOT_MARZBAN_PANEL_GROUP','Fixture physical panel')",
            native.DBS[1])
        native.sql(work,env,
            "INSERT INTO server_info (id,title,ucount,remark,flag,active,state) "
            "VALUES (2,'Physical panel',9,'fixture','',1,1),"
            "(3,'Physical alias',9,'fixture','',1,1) "
            "ON DUPLICATE KEY UPDATE active=1,state=1",
            native.DBS[1])
        native.sql(work,env,
            "UPDATE server_info SET panel_group_id=900001 WHERE id IN (2,3)",
            native.DBS[1])
        alias=url.replace('127.0.0.1','localhost')
        native.sql(work,env,
            "UPDATE server_config SET type='marzban',"
            f"panel_url='{alias}' WHERE id=3",
            native.DBS[1])
        panel.mode='edit-verified'
        panel.trace.clear()
        panel.edit_account=INITIAL.copy()
        panel.edit_put_count=0
        with ThreadPoolExecutor(max_workers=2) as pool:
            futures=[pool.submit(native.run,work,env,sites[worker],native.DBS[1],{
                'function':'editMarzbanConfig',
                'args':[sid,{'remark':'fixture-user','plus_volume':1}]
            }) for worker,sid in enumerate((2,3))]
            values=[f.result() for f in futures]
        assert all(r['exception'] is None and r['result']['success'] is True
                   for r in values),values
        assert panel.edit_account['data_limit']==4*GIB
        assert panel.edit_put_count==2
        cases.append('physical_panel_alias_different_urls_group_lock_no_lost_increment')

        # A stale or unknown physical grouping cannot authorize any edit.
        native.sql(work,env,
            "UPDATE server_info SET panel_group_id=900002 WHERE id=3",
            native.DBS[1])
        panel.trace.clear()
        unknown=native.run(work,env,source,native.DBS[1],{
            'function':'editMarzbanConfig',
            'args':[3,{'remark':'fixture-user','plus_volume':1}]})
        assert unknown['exception'] is None
        assert unknown['result']['success'] is False
        assert unknown['result']['msg']=='marzban_group_identity_unverified'
        assert panel.trace==[]
        cases.append('missing_physical_panel_group_rejects_before_remote_dispatch')

    report={'complete':True,'cases':len(cases),'scenarios':cases,
        'actual_php_mysqli_curl':True,'real_provider_requests':0,
        'owned_mariadb_only':True,'production_effects':0,
        'absolute_correction_not_relative_replay':True}
    if args.report:
        args.report.parent.mkdir(parents=True,exist_ok=True)
        args.report.write_text(json.dumps(report,indent=2)+'\n')
    print(json.dumps(report))
if __name__=='__main__':main()
