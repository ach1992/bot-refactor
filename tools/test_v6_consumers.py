#!/usr/bin/env python3
"""All V6-sensitive consumer bodies and public API/paths unchanged vs merged V5."""
from __future__ import annotations
import argparse,hashlib,json,re
from pathlib import Path
import v6_contract as c
PATHS=['buy_helpers.php','pay/back.php','settings/gift2all.php',
       'settings/rewardReport.php','settings/tronChecker.php',
       'settings/warnusers.php','settings/subLink.php',
       'settings/messagewizwiz.php','src/Subscription/Presentation.php']
CALLS=('addMarzbanUser','addUser','addInboundAccount','editMarzbanConfig',
       'editClientTraffic','editInboundTraffic','getConnectionLink',
       'getMarzbanUser','getMarzbanJson','getJson','editClientRemark','deleteClient')

def main():
    ap=argparse.ArgumentParser();ap.add_argument('--report',type=Path);args=ap.parse_args();c.verify()
    results={};counts={name:0 for name in CALLS}
    for name in PATHS:
        data=(c.ROOT/name).read_bytes()
        if data!=c.reference(name):raise RuntimeError('High consequence consumer byte drift '+name)
        for func in CALLS:
            counts[func]+=len(re.findall(rb'\b'+func.encode()+rb'\s*\(',data))
        results[name]=hashlib.sha256(data).hexdigest()
    if not all(counts[name]>0 for name in ('getJson','editInboundTraffic','getConnectionLink','addUser','editClientRemark')):
        raise RuntimeError('Essential consumer invocation missing')
    if c.reference('settings/subLink.php').count(b'settings/subLink.php'):
        raise RuntimeError('Unexpected self-referential endpoint change')
    report={'v5_reference':c.BASE,'unchanged_sensitive_consumers':len(PATHS),
        'file_sha256':results,'observed_account_facade_invocations':counts,
        'public_endpoint_original_bytes':True,'payment_warnings_cron_unchanged':True}
    if args.report:args.report.write_text(json.dumps(report,indent=2)+'\n')
    print(json.dumps({k:v for k,v in report.items() if k not in ('file_sha256','observed_account_facade_invocations')}))
if __name__=='__main__':main()
