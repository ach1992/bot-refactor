#!/usr/bin/env python3
"""Compare current legacy functions/entrypoints with recorded deterministic baseline traces."""
from __future__ import annotations
import argparse
import difflib
import hashlib
import json
import os
import resource
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DISABLED = ','.join([
    'time','microtime','rand','mt_rand','random_int','random_bytes','openssl_random_pseudo_bytes','uniqid','date','strtotime',
    'sleep','usleep','set_time_limit',
    'exec','shell_exec','system','passthru','popen','proc_open','pcntl_exec','pcntl_fork','mail','dl','putenv',
    'fsockopen','pfsockopen','stream_socket_client','stream_socket_server','stream_socket_sendto',
    'gethostbyname','gethostbynamel','gethostbyaddr','getmxrr','dns_get_record','checkdnsrr',
    'link','symlink',
])
FAMILIES = {
 'pure': ['contracts'],
 'price': ['customer','plan','server','malformed','over100_legacy'],
 'remark': ['manual','forced','digits','default'],
 'port': ['auto','manual','missing'],
 'wallet': ['missing','already','busy','approved','declined','expired_sent','low','success','business_false','handler_exception','write_exception','expired_legacy','foreign_owner_legacy'],
 'capacity': ['inbound_empty','server_empty','ok'],
 'provision': ['marzban','marzban_duplicate','xui','xui_duplicate','xui_port','inbound','inbound_duplicate'],
 'renew': ['marzban','client','inbound'],
 'account_error': ['null','inbound','response','array_success'],
 'order': ['insert','increase','capacity','plan'],
 'referral': ['wallet_skip','external'],
 'discount': ['missing_pay','missing_code','wrong_audience','minprice','used','percent','fixed_cap','unlimited','wrong_target','zero_clamp'],
 'receipt': ['current','recent_buy','boundary_buy','renew','declined','decline_boundary','have_sent','none'],
 'cart': ['low','usdt','new','agent','old_one','old_two','ten_days_three','ten_days_two'],
 'menu': ['customer','agent','sale_off','custom','admin_main','regular_admin','special_admin'],
 'command': ['buy','buy_off','gateways_off','agent_buy','mysubs','agent_mysubs','unknown'],
 'broadcast': ['helpers','cancelled','running'],
 'transport': ['json','429','failure','500','bad_json','multipart'],
 'entry': ['health_method','health_no_token','health_get','health_head','health_cached_failure','health_db_failure',
           'bot_banned','bot_off','bot_start','bot_main_callback','bot_legacy_callback',
           'pay_unknown','pay_missing','pay_return_missing','sub_missing','sub_short','gift_idle','tron_disabled','warn_idle','broadcast_idle','broadcast_pin','broadcast_429','reward_idle'],
}
CASES = [f'{k}.{v}' for k, vs in FAMILIES.items() for v in vs]

BASEINFO = '''<?php
// Synthetic, test-only deployment. NEVER copy this to a running bot.
error_reporting(0);
$botToken='fixture-token'; $dbUserName='fixture'; $dbPassword='fixture'; $dbName='fixture';
$botUrl='https://bot.example.invalid/'; $admin=9001; $admins_list=[9001,9002]; $special_admins=[9001];
$botUsername='FixtureBot'; $hideConnectLinkForMarzban=false; $showIndexes=[0,1];
$hideConfigIfMany=false; $linkIndices=[0,1]; $inviteReward=0; $joinLink='https://t.me/fixture_channel';
$USDTPrice='100000'; $USDTPriceagent='90000';
'''

def enforce_limits() -> None:
    resource.setrlimit(resource.RLIMIT_CPU, (8, 8))
    resource.setrlimit(resource.RLIMIT_FSIZE, (4*1024*1024, 4*1024*1024))
    resource.setrlimit(resource.RLIMIT_CORE, (0, 0))

def assert_baseline() -> dict:
    lock=json.loads((ROOT/'tests/fixtures/baseline-lock.json').read_text())
    for file, digest in lock['files'].items():
        if hashlib.sha256((ROOT/file).read_bytes()).hexdigest()!=digest:
            raise SystemExit('Refusing baseline recording with changed original file: '+file)
    return lock

def run_case(case: str, functions: bytes, files: list[str]) -> dict:
    with tempfile.TemporaryDirectory(prefix='achbot-case-') as tmp:
        root=Path(tmp)
        for file in files:
            if file.endswith('.php') or file=='index.html':
                dest=root/file; dest.parent.mkdir(parents=True, exist_ok=True); shutil.copyfile(ROOT/file,dest)
        shutil.copytree(ROOT/'tests/support',root/'tests/support')
        (root/'functions-under-test.php').write_bytes(functions)
        (root/'baseInfo.php').write_text(BASEINFO)
        args=['php','-n','-d','allow_url_fopen=0','-d','allow_url_include=0','-d',f'open_basedir={tmp}',
              '-d',f'disable_functions={DISABLED}','-d','memory_limit=128M','-d','max_execution_time=5',
              '-d','display_errors=0','-d','log_errors=0',str(root/'tests/support/child.php'),case]
        # Clear inherited credentials and proxy settings. Child cannot spawn, open sockets or load extensions.
        env={'PATH':os.environ.get('PATH','/usr/bin:/bin'),'LANG':'C.UTF-8','ACHBOT_PRIVATE_TEST':'1'}
        with (root/'process.out').open('wb') as out, (root/'process.err').open('wb') as err:
            p=subprocess.run(args,cwd=root,env=env,stdout=out,stderr=err,timeout=10,preexec_fn=enforce_limits)
        result=root/'result.json'
        if not result.is_file():
            raise RuntimeError(f'{case}: no trace (exit {p.returncode}): '+(root/'process.err').read_text()[:1000])
        raw=result.read_text().replace(tmp,'<SANDBOX>')
        data=json.loads(raw)
        if p.returncode!=0 or data['harness_errors']:
            raise RuntimeError(f'{case}: exit={p.returncode}, errors='+json.dumps(data['harness_errors'],ensure_ascii=False)[:2500])
        return data

def main() -> int:
    ap=argparse.ArgumentParser(description=__doc__)
    ap.add_argument('--record-baseline',action='store_true',help='Explicit baseline recording; refuses changed baseline files')
    ap.add_argument('--case',action='append',default=[],help='Run matching case prefix (repeatable)')
    ap.add_argument('--report',type=Path,help='Write synthetic result summary outside source')
    args=ap.parse_args()
    if args.record_baseline and args.case: raise SystemExit('Full recording only; no partial snapshot rewrites')
    lock=assert_baseline() if args.record_baseline else json.loads((ROOT/'tests/fixtures/baseline-lock.json').read_text())
    golden_path=ROOT/'tests/fixtures/golden.json'
    expected={} if args.record_baseline else json.loads(golden_path.read_text())['cases']
    selected=[c for c in CASES if not args.case or any(c.startswith(q) for q in args.case)]
    if not selected: raise SystemExit('No matching cases')
    if not args.record_baseline and not args.case and set(expected)!=set(CASES): raise SystemExit('Golden case list drift')
    with tempfile.TemporaryDirectory(prefix='achbot-extract-') as tmp:
        f=Path(tmp)/'functions.php'; manifest=Path(tmp)/'provenance.json'
        subprocess.run(['php','-n','-d','extension=tokenizer',str(ROOT/'tests/support/extract.php'),str(f),str(manifest)],check=True,timeout=20)
        functions=f.read_bytes()
        provenance=json.loads(manifest.read_text())
    if not args.record_baseline:
        recorded=json.loads((ROOT/'tests/fixtures/extraction-provenance.json').read_text())
        if provenance!=recorded:
            raise SystemExit('Extraction provenance drift: review the baseline/candidate strategy before accepting changed declarations')
        metadata=json.loads(golden_path.read_text())
        if metadata.get('baseline_commit')!=lock['baseline_commit'] or metadata.get('clock_epoch')!=1800000000:
            raise SystemExit('Golden baseline identity mismatch')
    results={}; failures=[]
    for case in selected:
        try:
            data=run_case(case,functions,list(lock['files']))
            if not args.record_baseline and data!=expected.get(case):
                diff='\n'.join(difflib.unified_diff(json.dumps(expected.get(case),ensure_ascii=False,indent=2).splitlines(),
                              json.dumps(data,ensure_ascii=False,indent=2).splitlines(),fromfile='golden',tofile=case))
                raise RuntimeError('Snapshot mismatch\n'+diff[:3500])
            results[case]=data
            print('PASS',case,flush=True)
        except (RuntimeError,subprocess.TimeoutExpired) as exc:
            failures.append({'case':case,'error':str(exc)})
            print('FAIL',case,str(exc),flush=True)
    if failures:
        print(f'{len(failures)} failed / {len(selected)} cases'); return 1
    if args.record_baseline:
        payload={'baseline_commit':lock['baseline_commit'],'clock_epoch':1800000000,'cases':results}
        golden_path.write_text(json.dumps(payload,ensure_ascii=False,indent=2)+'\n')
        (ROOT/'tests/fixtures/extraction-provenance.json').write_text(json.dumps(provenance,indent=2)+'\n')
    summary={'cases_passed':len(selected),'cases_failed':0,'families':{k:len(v) for k,v in FAMILIES.items()},
             'extracted_functions':len(provenance),'baseline_commit':lock['baseline_commit'],
             'native_external_io':False,'real_database_engine':False,'production_acceptance':False}
    if args.report: args.report.write_text(json.dumps(summary,indent=2)+'\n')
    print(json.dumps(summary,sort_keys=True))
    return 0

if __name__=='__main__': raise SystemExit(main())
