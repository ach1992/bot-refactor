#!/usr/bin/env python3
"""Reuse immutable V3 native Telegram transport evidence, with exact V4 blob parity."""
from __future__ import annotations
import argparse
import json
import os
import subprocess
import tempfile
from pathlib import Path
import v1_contract as v1
import v4_contract as c

def main():
    p=argparse.ArgumentParser(description=__doc__)
    p.add_argument('--run-loopback',required=True,action='store_true')
    p.add_argument('--report',required=True,type=Path)
    args=p.parse_args()
    with c.previous() as (old,lock),tempfile.TemporaryDirectory(prefix='achbot-v4-wire-') as tmp:
        c.verify(c.ROOT,old,lock)
        for path in ['config.php','src/Telegram/Transport.php','src/Telegram/Messages.php',
                     'src/Telegram/UpdateBindings.php']:
            if (c.ROOT/path).read_bytes()!=(old/path).read_bytes():
                raise RuntimeError('Telegram/transport implementation changed in V4')
        source_report=Path(tmp)/'v3-wire.json'
        env=dict(os.environ,GIT_DIR=v1.git('rev-parse','--absolute-git-dir').decode().strip(),
                 GIT_WORK_TREE=str(old))
        subprocess.run(['python3',str(old/'tools/test_v3_transport.py'),'--run-loopback',
                        '--report',str(source_report)],cwd=old,env=env,check=True,timeout=180,
                       stdout=subprocess.DEVNULL)
        source=json.loads(source_report.read_text())
        if source['comparison_pairs']!=16 or source['requests']!=32:
            raise RuntimeError('Frozen V3 native wire evidence unexpected')
    report={'inherited_reference':c.V3,'native_wire_test_run_on_unchanged_v3_files':True,
            'v4_transport_blobs_equal_reference':True,
            'comparison_pairs':source['comparison_pairs'],'requests':source['requests'],
            'php':source['php'],'curl':source['curl'],
            'real_provider_used':False,'tls_or_production_dns_tested':False,
            'operator_manual_acceptance':False,'production_acceptance':False}
    report.update(c.identity())
    args.report.write_text(json.dumps(report,indent=2)+'\n')
    print(json.dumps(report,sort_keys=True))
if __name__=='__main__':main()
