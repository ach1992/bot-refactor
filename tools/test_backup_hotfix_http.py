#!/usr/bin/env python3
"""AChBot private module must answer 404 when directly requested through local PHP HTTP."""
from __future__ import annotations
import contextlib,json,socket,subprocess,time,urllib.error,urllib.request
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
with socket.socket() as s:
    s.bind(('127.0.0.1',0));port=s.getsockname()[1]
cmd=['php','-S','127.0.0.1:'+str(port),'-t',str(ROOT)]
p=subprocess.Popen(cmd,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
try:
    for _ in range(30):
        try:
            urllib.request.urlopen('http://127.0.0.1:'+str(port)+'/src/Backup/Database.php', timeout=1)
            raise RuntimeError('Backup module unexpectedly exposed via HTTP')
        except urllib.error.HTTPError as error:
            if error.code != 404:raise RuntimeError('Expected 404, got '+str(error.code))
            print(json.dumps({'direct_backup_php_status':404,'private_module_guard':True,'loopback_only':True}))
            break
        except urllib.error.URLError:
            time.sleep(.1)
    else:raise RuntimeError('Loopback PHP server did not start')
finally:
    p.terminate()
    try:p.wait(timeout=5)
    except subprocess.TimeoutExpired:p.kill();p.wait(timeout=3)
