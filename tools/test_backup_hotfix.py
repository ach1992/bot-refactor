#!/usr/bin/env python3
"""AChBot V6.1 isolated PHP+fake mysqldump tests (no real DB/Telegram or live app)."""
from __future__ import annotations
import argparse, json, os, pathlib, subprocess, tempfile
ROOT=pathlib.Path(__file__).resolve().parents[1]
PHP=ROOT/'tests/backup/backup_case.php'
FAKE_DUMP='''#!/bin/sh
case "$1" in
  --defaults-extra-file=*) ;;
  *) echo "invalid option-file order" >&2; exit 11 ;;
esac
config=${1#--defaults-extra-file=}
test -r "$config" || exit 12
mode=$(stat -c %a "$config")
test "$mode" = 600 || exit 13
# No credential strings may appear in argv; inspect option-file entirely inside mock only.
case " $* " in *synthetic_password*) exit 14;; esac
case "${ACHBOT_TEST_DUMP_MODE:-ok}" in
  fail) echo 'Access denied for synthetic_user' >&2; exit 7 ;;
  empty) exit 0 ;;
esac
# Faked SQL with >1000 bytes, no real application/database data.
printf '%2048s\\n' 'SYNTHETIC_SQL_ONLY'
'''

EXPECT={
  'send.success':(True,1,2),
  'send.telegram_failure':(False,1,2),
  'send.dump_failure':(False,0,0),
  'send.empty':(False,0,0),
  'send.binary_missing':(False,0,0),
  'send.no_recipient':(False,0,0),
  'send.zip_missing':(False,0,0),
  'send.zip_error':(False,0,2),
}
TIMING={
  'schedule.success':(True,1,1,True),
  'schedule.failure':(False,1,0,False),
  'schedule.future':(True,0,0,True),
  'schedule.locked':(False,0,0,False),
  'schedule.missing':(True,1,1,True),
}

def run(*, only:str|None=None, native:bool=False):
    records={}
    with tempfile.TemporaryDirectory(prefix='achbot-backup-private-') as temp:
        d=pathlib.Path(temp);(d/'bin').mkdir();f=d/'bin/mysqldump';f.write_text(FAKE_DUMP);f.chmod(0o700)
        tempdir=d/'tmp';tempdir.mkdir()
        for scenario in [*EXPECT,*TIMING]:
            if only and scenario!=only:continue
            app=d/('app-'+scenario.replace('.', '-'));app.mkdir()
            env=dict(os.environ, ACHBOT_PRIVATE_TEST='1', TMPDIR=str(tempdir),
                     ACHBOT_MYSQLDUMP_BIN=str(f), ACHBOT_TEST_ZIP_SHIM='0' if native else '1',
                     ACHBOT_TEST_ZIP_ERROR='1' if scenario=='send.zip_error' else '0',
                     ACHBOT_TEST_DUMP_MODE='fail' if scenario=='send.dump_failure'
                     else 'empty' if scenario=='send.empty' else 'ok')
            if scenario=='send.binary_missing': env['ACHBOT_MYSQLDUMP_BIN']=str(d/'not-found')
            if scenario=='send.zip_missing': env['ACHBOT_TEST_ZIP_SHIM']='0'
            # Positive CI requires real ZipArchive; the missing-extension negative
            # is run through `php -n` separately, not the normal CI PHP runtime.
            command=['php']
            if scenario in ('send.zip_missing', 'send.zip_error'):
                command.append('-n')
                if scenario=='send.zip_error': env['ACHBOT_TEST_ZIP_SHIM']='1'
            command += [str(PHP),scenario,str(app),str(ROOT)]
            p=subprocess.run(command,cwd=app,env=env,text=True,capture_output=True,timeout=20)
            if p.returncode:raise RuntimeError(f'{scenario}: exit={p.returncode} stdout={p.stdout[-400:]} stderr={p.stderr[-500:]}')
            try:reply=json.loads(p.stdout)
            except Exception as err:raise RuntimeError(f'{scenario}: malformed {p.stdout[-500:]!r}') from err
            if scenario in EXPECT:
                expected=EXPECT[scenario]
                actual=(reply['result'],reply['delivered'],reply['sql_count_queries'])
                # Even expected-failure cases must clean private SQL/ZIP/credentials.
                if actual != expected or reply['private_workspace_residual']:
                    raise RuntimeError(f'{scenario}: {actual!r} != {expected!r}; residue={reply["private_workspace_residual"]}')
            else:
                expected=TIMING[scenario]
                actual=(reply['result'],reply['attempts'],reply['db_writes'],reply['timestamp_advanced'])
                if actual!=expected:raise RuntimeError(f'{scenario}: {actual!r} != {expected!r}')
            # In the presence of synthetic failure a bounded secret-free reason is logged.
            if 'synthetic_password' in p.stderr: raise RuntimeError(f'{scenario}: credential echoed')
            records[scenario]={'ok':True,'result':reply['result']}
        # Mutation negative control: if Telegram-failure gate is bypassed,
        # synthetic acceptance MUST detect the regression.
        source=ROOT/'src/Backup/Database.php'
        old=source.read_text()
        marker='if (!$deliver($zipPath, $caption, $channelId)) {'
        if old.count(marker)!=1:raise RuntimeError('Mutation site not unique')
        mutant=d/'mutated_backup.php'
        mutant.write_text(old.replace(marker, 'if (false && !$deliver($zipPath, $caption, $channelId)) {'))
        app=d/'mutation-app';app.mkdir()
        env=dict(os.environ,ACHBOT_PRIVATE_TEST='1',TMPDIR=str(tempdir),
                 ACHBOT_MYSQLDUMP_BIN=str(f), ACHBOT_TEST_ZIP_SHIM='1',
                 ACHBOT_TEST_DUMP_MODE='ok',ACHBOT_TEST_MODULE_FILE=str(mutant))
        p=subprocess.run(['php',str(PHP),'send.telegram_failure',str(app),str(ROOT)],
                         env=env,cwd=app,text=True,capture_output=True,timeout=20)
        if p.returncode==0:
            try:mutated=json.loads(p.stdout)
            except Exception:mutated={}
            if mutated.get('result') is False and mutated.get('delivered')==1:
                raise RuntimeError('Negative control did not detect bypassed Telegram failure check')
    # Absolute candidate discovery must still work when Cron's PATH is empty.
    script='require '+repr(str(ROOT/'src/Backup/Database.php'))+r'; echo \AchBot\Backup\Database\dumpBinary();'
    path_env=dict(os.environ,PATH='/nonexistent',ACHBOT_PRIVATE_TEST='1')
    path_env.pop('ACHBOT_MYSQLDUMP_BIN',None)
    resolved=subprocess.run(['/usr/bin/php','-r',script],env=path_env,text=True,capture_output=True,timeout=10)
    if resolved.returncode or not resolved.stdout.startswith('/') or not os.access(resolved.stdout,os.X_OK):
        raise RuntimeError('Unqualified Cron PATH still required for database dump discovery')
    return {'synthetic_cases':len(records),'native_zip':native,'negative_mutation_detected':True,
            'path_independent_discovery':True,
            'results':records,'no_real_db':True,'no_real_telegram':True}

if __name__=='__main__':
    ap=argparse.ArgumentParser();ap.add_argument('--case');ap.add_argument('--native-zip',action='store_true');ap.add_argument('--report');args=ap.parse_args()
    record=run(only=args.case,native=args.native_zip)
    if args.report:pathlib.Path(args.report).write_text(json.dumps(record,indent=2)+'\n')
    print(json.dumps(record))
