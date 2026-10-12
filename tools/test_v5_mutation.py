#!/usr/bin/env python3
"""Deterministic negative controls over disposable V5 copies (frozen fixtures untouched)."""
from __future__ import annotations
import argparse
import json
import shutil
import tempfile
from pathlib import Path
import test_v5_admin as admin
import test_v5_worker as worker
import v5_contract as c


def alter(path:Path, old:bytes,new:bytes):
    src=path.read_bytes()
    if src.count(old)!=1:raise RuntimeError('Mutation anchor ambiguous '+str(path))
    path.write_bytes(src.replace(old,new))


def main():
    ap=argparse.ArgumentParser(description=__doc__)
    ap.add_argument('--report',type=Path)
    args=ap.parse_args()
    c.verify()
    controls=[]
    with c.previous() as original, tempfile.TemporaryDirectory(prefix='achbot-v5-mut-') as tmp:
        d=Path(tmp)
        for n,condition,spec,old,new in [
            ('admin.callback','admin',{'data':'broadcastStart','actor':9001},
             b'chooseSendGroup_agents',b'chooseSendGroup_all'),
            ('admin.sql','admin',{'data':'cancelBroadcast8','actor':9001},
             b'`send_list` SET `state` = 4',b'`send_list` SET `state` = 3'),
            ('worker.429','worker',worker.scenarios()['job.429'],
             b'sleep((int)($res->parameters->retry_after ?? 1) + 1);',b'sleep(0);'),
            ('broadcast.429','worker',worker.scenarios()['send.429'],
             b'sleep($retry + 1);',b'sleep(0);'),
        ]:
            oldroot=d/(n.replace('.','-')+'-old')
            newroot=d/(n.replace('.','-')+'-new')
            if condition=='admin':
                admin.fixture(oldroot,original,False)
                admin.fixture(newroot,original,True)
                mutated=newroot/'src/Broadcast/Routes.php'
                comparator=admin.run
            else:
                worker.copy_app(original,oldroot)
                worker.copy_app(c.ROOT,newroot,True)
                mutated=newroot/('src/Jobs/MessageActions.php' if n=='worker.429' else 'src/Jobs/BroadcastSend.php')
                comparator=worker.run
            before=comparator(oldroot,spec)
            after=comparator(newroot,spec)
            if before!=after:raise RuntimeError('Clean expected V4/V5 equivalence failed: '+n)
            alter(mutated,old,new)
            altered=comparator(newroot,spec)
            if before==altered:raise RuntimeError('Mutation escaped differential test: '+n)
            controls.append(n)
        # A real source-identity verifier must reject tampered runtime bytes,
        # not merely demonstrate that changing a string changes the string.
        package=d/'candidate-copy'
        shutil.copytree(c.ROOT,package,ignore=shutil.ignore_patterns('.git','__pycache__','.venv'))
        c.verify(package)
        altered=package/'src/Jobs/BroadcastSend.php'
        if altered.read_bytes().count(b'LIMIT {$batchLimit}') != 4:
            raise RuntimeError('Legacy batch selection anchor drift')
        # Four identical selection boundaries: deliberately change only one.
        altered.write_bytes(altered.read_bytes().replace(b'LIMIT {$batchLimit}',b'LIMIT 1',1))
        try:
            c.verify(package)
        except RuntimeError as error:
            if 'V5 source drift: src/Jobs/BroadcastSend.php' not in str(error):raise
        else:raise RuntimeError('Tampered broadcast source escaped contract verifier')
        controls.append('contract.sql-selection')
        # Broken nested require sentinel must NOT let legacy early return continue.
        prefix=d/'admin-return-old';current=d/'admin-return-new'
        admin.fixture(prefix,original,False);admin.fixture(current,original,True)
        spec=admin.cases()['pin.missing.9001-0']
        clean=admin.run(prefix,spec)
        if admin.run(current,spec)!=clean:raise RuntimeError('Early-return reference drift')
        alter(current/'access-sequence.php',b') === null) return;',b') === false) return;')
        if admin.run(current,spec)==clean:raise RuntimeError('Broken early-return adapter escaped')
        controls.append('admin.early-return')
        # Persisting the wrong worker cursor must be visible in ordered SQL binds.
        prefix=d/'job-offset-old';current=d/'job-offset-new'
        worker.copy_app(original,prefix);worker.copy_app(c.ROOT,current,True)
        spec=worker.scenarios()['job.pin']
        clean=worker.run(prefix,spec)
        if worker.run(current,spec)!=clean:raise RuntimeError('Job cursor reference drift')
        alter(current/'src/Jobs/MessageActions.php',b'$bc_offsetJ = $rowId;',b'$bc_offsetJ = 0;')
        if worker.run(current,spec)==clean:raise RuntimeError('Wrong persisted worker cursor escaped')
        controls.append('job.cursor')
        # A worker refactor may not move the legacy error log location.
        prefix=d/'log-path-old';current=d/'log-path-new'
        worker.copy_app(original,prefix);worker.copy_app(c.ROOT,current,True)
        spec=worker.scenarios()['send.403']
        clean=worker.run(prefix,spec)
        if worker.run(current,spec)!=clean:raise RuntimeError('Error path reference drift')
        alter(current/'src/Jobs/BroadcastSend.php',b'dirname(__DIR__, 2) . "/settings/error_log.txt"',
              b'dirname(__DIR__, 2) . "/src/error_log.txt"')
        if worker.run(current,spec)==clean:raise RuntimeError('Wrong legacy error log path escaped')
        controls.append('broadcast.error-log-path')
    result={'negative_controls':controls,'controls_detected':len(controls),'frozen_sources_modified':False}
    if args.report:args.report.write_text(json.dumps(result,indent=2)+'\n')
    print(json.dumps(result))

if __name__=='__main__':main()
