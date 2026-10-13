#!/usr/bin/env python3
"""Make deliberately bad V7 fixture mutations and demand E2E differential catches all."""
from __future__ import annotations
import argparse,json
from pathlib import Path
import v7_contract as c
import test_v7_native as e2e
import test_v6_native as native
import test_access_state as support

def main():
    p=argparse.ArgumentParser();p.add_argument('--run-disposable',required=True,action='store_true')
    p.add_argument('--report',type=Path);a=p.parse_args();c.verify()
    cases=[
        ('broken_callback','src/Purchase/Catalogue.php',b'selectCategory{$id}_',b'BROKENCategory{$id}_','catalogue',{'data':'selectServer2_none','actor':1001}),
        ('reversed_capacity','src/Purchase/OrderWrites.php',b'`ucount` - ?',b'`ucount` + ?','trialprovision',{'data':'freeTrial8_normal','actor':1001}),
        ('reject_capacity','src/Purchase/Core.php',b"['ucount'] <= 0",b"['ucount'] <= 1000",'trialprovision',{'data':'freeTrial8_normal','actor':1001}),
        ('broken_trial_callback','src/Purchase/TrialProvision.php',b"'/freeTrial(\\d+)_",b"'/NOTrial(\\d+)_",'trialprovision',{'data':'freeTrial8_normal','actor':1001}),
        ('broken_provider_path','src/Panel/Accounts.php',b'$panel_url . "/api/user"',b'$panel_url . "/api/unknown"','trialprovision',{'data':'freeTrial8_normal','actor':1001}),
    ]
    outcomes=[]
    with support.owned_database() as (work,env),native.owned_provider() as (provider,url):
        old=work/'old';new=work/'mutant';e2e.stage(old,False);e2e.stage(new,True)
        for label,path,good,bad,kind,spec in cases:
            e2e.init(work,env,url);provider.mode='ok'
            target=new/path;original=target.read_bytes()
            if original.count(good)!=1:raise RuntimeError('Mutation anchor drift '+label+': '+str(original.count(good)))
            target.write_bytes(original.replace(good,bad,1))
            try:
                provider.trace.clear();old_events=e2e.run(work,env,old,e2e.SIDE[0],kind,spec)
                old_wire=list(provider.trace);old_db=e2e.snapshot(work,env,e2e.SIDE[0])
                provider.trace.clear();new_events=e2e.run(work,env,new,e2e.SIDE[1],kind,spec)
                new_wire=list(provider.trace);new_db=e2e.snapshot(work,env,e2e.SIDE[1])
            finally:target.write_bytes(original)
            mismatched=(old_events!=new_events or old_wire!=new_wire or old_db!=new_db)
            if not mismatched:raise RuntimeError('Undetected synthetic regression: '+label)
            outcomes.append({'mutant':label,'detected':True,'telegram_diff':old_events!=new_events,
                             'provider_diff':old_wire!=new_wire,'sql_diff':old_db!=new_db})
    report={'mutations':len(outcomes),'all_detected':True,'cases':outcomes,'owned_fixture_only':True}
    if a.report:a.report.write_text(json.dumps(report,indent=2)+'\n')
    print(json.dumps(report),flush=True)
if __name__=='__main__':main()
