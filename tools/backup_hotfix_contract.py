#!/usr/bin/env python3
"""AChBot V6.1 source boundary: only DB backup and its scheduler may change."""
from __future__ import annotations
import hashlib, subprocess
from pathlib import Path
import v6_contract as v6
ROOT=Path(__file__).resolve().parents[1]
BASE='b43b12132537e0e383729cbef26745b420d0553d'
NEW_MODULE='src/Backup/Database.php'
CHANGEABLE={
    'config.php':(b'function sendDatabaseBackup($channelId) {', b'function sendDatabaseBackup($channelId) {', b'function sendDocument($filePath, $caption = "", $ci = null){'),
    'settings/rewardReport.php':(b'$backupInterval = 10 * 60;', b'// AChBot backup: source-only compatibility', b'if($botState[\'cartToCartAutoAcceptState\']=="on")'),
}

def git(*args):return subprocess.check_output(['git','-C',str(ROOT),*args])
def old(name):return git('show',BASE+':'+name)
def members():return sorted(v6.runtime_files()+[NEW_MODULE])

def verify():
    paths=members()
    if len(paths)!=54 or len(set(paths))!=54:raise RuntimeError('V7 runtime path count mismatch')
    for name in paths:
        p=ROOT/name
        if not p.is_file() or p.is_symlink():raise RuntimeError('Missing/symlink member '+name)
        if name==NEW_MODULE:continue
        before=old(name); now=p.read_bytes()
        if name in CHANGEABLE:
            old_begin,new_begin,end=CHANGEABLE[name]
            if before.count(old_begin)!=1 or before.count(end)!=1 or now.count(new_begin)!=1 or now.count(end)!=1:
                raise RuntimeError('Cannot isolate changed maintenance-only source segment '+name)
            bstart=before.index(old_begin);bend=before.index(end,bstart)
            nstart=now.index(new_begin);nend=now.index(end,nstart)
            if before[:bstart]!=now[:nstart] or before[bend:]!=now[nend:]:
                raise RuntimeError('Unapproved non-backup runtime drift in '+name)
        elif now!=before:raise RuntimeError('Original V6 runtime was changed unexpectedly: '+name)
    actual_php={p.relative_to(ROOT).as_posix() for p in ROOT.rglob('*.php')
                if p.relative_to(ROOT).parts[0] not in ('tests','tools','.git') and p.name!='baseInfo.php'}
    expected_php={f for f in paths if f.endswith('.php')}
    if actual_php!=expected_php:raise RuntimeError('Unexpected PHP runtime paths: '+str(sorted(actual_php^expected_php)))
    module=(ROOT/NEW_MODULE).read_bytes()
    for must in (b'--defaults-extra-file=',b'LOCK_EX | LOCK_NB',b'ZipArchive',b'--host=localhost',b'dumpBinary'):
        if must not in module:raise RuntimeError('Backup regression: missing '+str(must))
    for name in ('baseInfo.php','backup/bot_backup.sql','backup/bot_backup.zip'):
        if name in paths:raise RuntimeError('Private state cannot be packaged')
    return {'base':BASE,'runtime_members':len(paths),'old_runtime_preserved':len(paths)-len(CHANGEABLE)-1,
            'modified_entrypoints':sorted(CHANGEABLE),'new_private_backup_module':NEW_MODULE,
            'v6_legacy_source_identical_outside_backup':True,'module_sha256':hashlib.sha256(module).hexdigest()}
if __name__=='__main__':import json;print(json.dumps(verify()))
