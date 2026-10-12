#!/usr/bin/env python3
"""Create a private socket-only MariaDB, test reference DDL, and remove only that instance."""
from __future__ import annotations
import argparse
import json
import os
import pwd
import shutil
import subprocess
import tempfile
import time
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]

def main() -> int:
    ap=argparse.ArgumentParser(description=__doc__)
    ap.add_argument('--run-disposable',action='store_true',required=True,help='Explicitly allow creating a disposable test DB')
    ap.add_argument('--record-baseline',action='store_true',help='Record baseline SQL-prepare failures after review')
    ap.add_argument('--report',type=Path,required=True)
    args=ap.parse_args()
    for cmd in ['mariadb-install-db','mariadbd','mariadb','php']:
        if not shutil.which(cmd): raise SystemExit('Missing prerequisite: '+cmd+'; no host packages are installed by this tool')
    if args.record_baseline:
        import sys
        sys.path.insert(0,str(ROOT/'tests'))
        from run import assert_baseline
        assert_baseline()
    env={'PATH':os.environ.get('PATH','/usr/bin:/bin'),'LANG':'C.UTF-8','HOME':str(ROOT)}
    with tempfile.TemporaryDirectory(prefix='achbot-disposable-db-') as tmp:
        d=Path(tmp); (d/'data').mkdir(); (d/'.achbot-disposable-db').write_text('achbot-v0-fixture-only\n')
        log=(d/'process.log').open('wb')
        install=['mariadb-install-db','--no-defaults','--datadir='+str(d/'data'),'--auth-root-authentication-method=normal',
                 '--skip-test-db','--user='+pwd.getpwuid(os.getuid()).pw_name]
        subprocess.run(install,env=env,stdout=log,stderr=log,check=True,timeout=45)
        command=['mariadbd','--no-defaults','--datadir='+str(d/'data'),'--socket='+str(d/'mysql.sock'),
          '--pid-file='+str(d/'mysql.pid'),'--log-error='+str(d/'server.log'),'--skip-networking','--skip-name-resolve',
          '--innodb-buffer-pool-size=32M','--innodb-log-file-size=8M','--key-buffer-size=8M','--aria-pagecache-buffer-size=8M',
          '--max-connections=10','--tmpdir='+str(d),'--user='+pwd.getpwuid(os.getuid()).pw_name]
        server=subprocess.Popen(command,env=env,stdout=log,stderr=log)
        client=['mariadb','--no-defaults','--protocol=SOCKET','--socket='+str(d/'mysql.sock'),'-uroot','--batch']
        try:
            ready=False
            for _ in range(100):
                if server.poll() is not None: raise RuntimeError('Disposable MariaDB exited: '+(d/'server.log').read_text()[-2000:])
                if (d/'mysql.sock').exists():
                    p=subprocess.run(client+['-e','SELECT 1'],env=env,capture_output=True,timeout=3)
                    if p.returncode==0: ready=True; break
                time.sleep(.1)
            if not ready: raise RuntimeError('Disposable MariaDB did not become ready')
            subprocess.run(client+['-e','CREATE DATABASE achbot_v0_fixture CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'],env=env,check=True,capture_output=True,timeout=5)
            subprocess.run(client+['achbot_v0_fixture'],input=(ROOT/'database/legacy-schema.sql').read_bytes(),env=env,check=True,capture_output=True,timeout=10)
            p=subprocess.run(['php','-n','-d','extension=mysqlnd','-d','extension=mysqli','-d','extension=tokenizer',
                              '-d','date.timezone=UTC',str(ROOT/'tests/schema_probe.php'),tmp],env=env,capture_output=True,timeout=45)
            if p.returncode!=0: raise RuntimeError('SQL probe failed: '+p.stdout.decode(errors='replace')[-3000:]+p.stderr.decode(errors='replace')[-1000:])
            report=json.loads(p.stdout)
            expected=ROOT/'tests/fixtures/sql-prepare-baseline.json'
            # Session/version recorded, but prepare failure identities are the portable assertion.
            failures=[{k:v for k,v in f.items() if k!='message'} for f in report['prepare_failures']]
            if args.record_baseline: expected.write_text(json.dumps(failures,indent=2)+'\n')
            elif not expected.is_file() or failures!=json.loads(expected.read_text()):
                raise RuntimeError('SQL prepare results changed; inspect errors without modifying application/schema')
            args.report.write_text(json.dumps(report,indent=2)+'\n')
            print(json.dumps({k:v for k,v in report.items() if k!='prepare_failures'},sort_keys=True))
        finally:
            server.terminate()
            try: server.wait(timeout=10)
            except subprocess.TimeoutExpired: server.kill(); server.wait(timeout=5)
            log.close()
    return 0

if __name__=='__main__': raise SystemExit(main())
