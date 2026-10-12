#!/usr/bin/env python3
"""Deliberate V6 behavioral negatives: fail on SQL, provider options/encoding, callback, deletion and public base64 drift."""
from __future__ import annotations
import argparse, hashlib, json, shutil, tempfile
from pathlib import Path
import v6_contract as c
import test_v6_panels as panel
import test_v6_routes as routes
import test_v6_subscription_http as endpoint
import test_v6_native as native
import test_access_state as support


def patch_file(path:Path,before:bytes,after:bytes):
    old=path.read_bytes()
    if old.count(before)!=1:raise RuntimeError('Mutation site count unexpected '+str(path))
    path.write_bytes(old.replace(before,after,1))

def negative_pair(old:Path,new:Path,spec:dict,runner,name:str):
    initial=runner(new,spec);control=runner(old,spec)
    if initial!=control:raise RuntimeError('Baseline invalid for mutation '+name)
    return control

def main():
    ap=argparse.ArgumentParser();ap.add_argument('--report',type=Path)
    a=ap.parse_args();c.verify()
    checked=[]
    with tempfile.TemporaryDirectory(prefix='achbot-v6-mut-') as tmp:
        root=Path(tmp)
        panel_cases=panel.cases()
        for label,field,before,after,case in [
            ('panel.auth_header','src/Panel/Accounts.php',b'Content-Type: application/x-www-form-urlencoded',b'Content-Type: fixture-invalid', 'marzban.login.ok'),
            ('panel.encoded_path','src/Panel/Accounts.php',b"'/api/user/' . urlencode($remark)",b"'/api/user/' . rawurlencode($remark)", 'marzban.user.explicit-token'),
            ('account.sql_bind','src/Subscription/AccountDetails.php',b'SELECT * FROM server_config WHERE id=?',b'SELECT id FROM server_config WHERE id=?', 'server.config'),
        ]:
            old=root/'old';new=root/'new';
            if old.exists():shutil.rmtree(old)
            if new.exists():shutil.rmtree(new)
            panel.fixture(old,False);panel.fixture(new,True)
            control=negative_pair(old,new,panel_cases[case],panel.run,label)
            patch_file(new/field,before,after)
            changed=panel.run(new,panel_cases[case])
            if changed==control:raise RuntimeError('Undetected deliberate panel mutation: '+label)
            checked.append(label)
        route_cases=routes.cases()
        for label,module,before,after,case in [
            ('telegram.callback','src/Subscription/CustomerRoutes.php',b"'mySubscriptions'",b"'mutatedSubscriptionCallback'",'customer.list.empty.mySubscriptions.1001-0'),
            ('telegram.delete','src/Subscription/AccountDeletion.php',b'deleteMyConfig',b'mutatedDeleteCallback','deletion.ask.1001-0'),
        ]:
            old=root/'old';new=root/'new'
            if old.exists():shutil.rmtree(old)
            if new.exists():shutil.rmtree(new)
            routes.fixture(old,module,False);routes.fixture(new,module,True)
            spec=route_cases[case]
            control=negative_pair(old,new,spec,routes.run,label)
            patch_file(new/module,before,after)
            changed=routes.run(new,spec)
            if changed==control:raise RuntimeError('Undetected deliberate route mutation '+label)
            checked.append(label)
        with support.owned_database() as (work,env),native.owned_provider() as (provider,url):
            native.seed(work,env,url)
            old=work/'old-http';new=work/'new-http'
            endpoint.stage(old,'achbot_v4_old',False);endpoint.stage(new,'achbot_v4_new',True)
            def fetch(app):
                web,port=endpoint.serve(app,work,env)
                try:return endpoint.fetch(port,'a'*30)
                finally:
                    if web.poll() is None:web.terminate()
                    try:web.wait(timeout=5)
                    except Exception:web.kill();web.wait(timeout=5)
            before=fetch(old);initial=fetch(new)
            if before!=initial:raise RuntimeError('Negative endpoint baseline already drifted')
            patch_file(new/'settings/subLink.php',b'base64_encode(implode("\\n", $vraylink))',b'implode("\\n", $vraylink)')
            mutated=fetch(new)
            if before==mutated:raise RuntimeError('Undetected deliberately modified public subscription output')
            checked.append('public.base64')
    report={'negative_controls':len(checked),'names':checked,'original_runtime_modified':False,
            'detects_provider_sql_telegram_delete_public_url_drift':True}
    if a.report:a.report.write_text(json.dumps(report,indent=2)+'\n')
    print(json.dumps(report))
if __name__=='__main__':main()
