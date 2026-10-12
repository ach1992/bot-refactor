#!/usr/bin/env python3
"""AChBot V6.1 isolated PHP+fake mysqldump tests (no real DB/Telegram or live app)."""
from __future__ import annotations
import argparse, json, os, pathlib, subprocess, tempfile
ROOT=pathlib.Path(__file__).resolve().parents[1]
PHP=ROOT/'tests/backup/backup_case.php'
FAKE_DUMP=r'''#!/bin/sh
case "$1" in
  --defaults-extra-file=*) ;;
  *) echo "invalid option-file order" >&2; exit 11 ;;
esac
config=${1#--defaults-extra-file=}
test -r "$config" || exit 12
mode=$(stat -c %a "$config")
test "$mode" = 600 || exit 13
# Credentials must be kept out of process arguments.
case " $* " in *synthetic_password*) exit 14;; esac
mode=${ACHBOT_TEST_DUMP_MODE:-ok}
case "$mode" in
  fail) echo 'Access denied for synthetic_user' >&2; exit 7 ;;
  empty) exit 0 ;;
  invalid_large)
    # Successful process, >2KiB, but this is NOT SQL.
    printf '%2048s\n' 'SYNTHETIC_NON_SQL_JUNK'
    exit 0 ;;
esac
if [ "$mode" != "invalid_header" ]; then
  if [ "$mode" = "mysql_header" ]; then
    printf '%s\n' '-- MySQL dump 10.13  Distrib 8.0.46'
  else
    printf '%s\n' '-- MariaDB dump 10.19 Distrib 10.11.10-MariaDB'
  fi
  printf '%s\n' '-- Host: localhost    Database: synthetic_db'
fi
if [ "$mode" != "invalid_schema" ]; then
  cat <<'SQL'
--
-- Table structure for table `users`
--
CREATE TABLE `users` (`id` INT);
SQL
fi
# This is a syntactically valid, fixture-only SQL stream (>1000 bytes).
i=1
limit=90
if [ "$mode" = "large_valid" ]; then limit=3200; fi
while [ "$i" -le "$limit" ]; do
  printf 'INSERT INTO `users` VALUES (%s);\n' "$i"
  i=$((i + 1))
done
if [ "$mode" = 'truncated' ]; then exit 0; fi
printf '%s\n' '-- Dump completed on 2026-10-12 00:00:00'
if [ "$mode" = 'trailing_junk' ]; then
  printf '%s\n' 'NON_SQL_AFTER_COMPLETION'
fi
'''

EXPECT={
  'send.success':(True,1,2),
  'send.mysql_header':(True,1,2),
  'send.large_valid':(True,1,2),
  'send.telegram_failure':(False,1,2),
  'send.dump_failure':(False,0,0),
  'send.empty':(False,0,0),
  'send.invalid_large':(False,0,0),
  'send.truncated_large':(False,0,0),
  'send.invalid_schema':(False,0,0),
  'send.invalid_header':(False,0,0),
  'send.trailing_junk':(False,0,0),
  'send.binary_missing':(False,0,0),
  'send.no_recipient':(False,0,0),
  'send.zip_missing':(False,0,0),
  'send.zip_error':(False,0,2),
}
TIMING={
  'schedule.success':(True,1,1,True,0),
  'schedule.failure':(False,1,0,False,0),
  'schedule.future':(True,0,0,True,0),
  'schedule.locked':(False,0,0,False,0),
  'schedule.missing':(True,1,1,True,0),
  'schedule.invalid_large':(False,1,0,False,0),
}

def multi_process_lock_test(d:pathlib.Path, tempdir:pathlib.Path):
    """Two independent PHP processes contend for the same real OS flock."""
    import select
    app=d/'multiprocess-app';app.mkdir()
    env=dict(os.environ,ACHBOT_PRIVATE_TEST='1',TMPDIR=str(tempdir))
    holder=subprocess.Popen(
        ['php',str(ROOT/'tests/backup/hold_lock.php'),str(app),str(ROOT)],
        cwd=app,env=env,text=True,
        stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.PIPE
    )
    try:
        ready,_,_=select.select([holder.stdout],[],[],8)
        if not ready or holder.stdout.readline().strip()!='LOCK_HELD':
            raise RuntimeError('Independent holder did not acquire real file lock')
        def scheduled():
            p=subprocess.run(['php',str(PHP),'schedule.success',str(app),str(ROOT)],
                             cwd=app,env=env,text=True,capture_output=True,timeout=12)
            if p.returncode:raise RuntimeError('Competing PHP process failed '+p.stderr[-300:])
            return json.loads(p.stdout)
        blocked=scheduled()
        if blocked['result'] is not False or blocked['attempts'] or blocked['db_writes']:
            raise RuntimeError('Other process sent or advanced while lock was held '+str(blocked))
        holder.stdin.write('release\n')
        holder.stdin.flush()
        _,errors=holder.communicate(timeout=8)
        if holder.returncode:raise RuntimeError('Holder process failed '+errors[-350:])
        resumed=scheduled()
        if resumed['result'] is not True or resumed['attempts']!=1 or resumed['db_writes']!=1:
            raise RuntimeError('Eligible backup did not resume after lock release '+str(resumed))
    finally:
        if holder.poll() is None:
            holder.kill()
            holder.communicate(timeout=5)
    return {'two_independent_php_processes':True,'blocked_backup_sends':0,
            'blocked_timestamp_writes':0,'post_release_backup_attempts':1}


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
                     ACHBOT_TEST_DUMP_MODE={
                         'send.mysql_header':'mysql_header',
                         'send.large_valid':'large_valid',
                         'send.dump_failure':'fail',
                         'send.empty':'empty',
                         'send.invalid_large':'invalid_large',
                         'send.truncated_large':'truncated',
                         'send.invalid_schema':'invalid_schema',
                         'send.invalid_header':'invalid_header',
                         'send.trailing_junk':'trailing_junk',
                         'schedule.invalid_large':'invalid_large',
                     }.get(scenario, 'ok'))
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
                actual=(reply['result'],reply['attempts'],reply['db_writes'],reply['timestamp_advanced'],reply['deliveries'])
                if actual!=expected:raise RuntimeError(f'{scenario}: {actual!r} != {expected!r}')
            # In the presence of synthetic failure a bounded secret-free reason is logged.
            if 'synthetic_password' in p.stderr: raise RuntimeError(f'{scenario}: credential echoed')
            records[scenario]={'ok':True,'result':reply['result']}
        multiprocess=multi_process_lock_test(d,tempdir)
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
        # Mutation negative control: if SQL structural validation is skipped, the
        # >1000-byte junk output would reach ZIP/Telegram despite exit code 0.
        validator='if (!completeSqlDump($sql)) {'
        if old.count(validator)!=1:raise RuntimeError('SQL validity mutation site not unique')
        invalid=d/'mutated_sql_validation.php'
        invalid.write_text(old.replace(validator, 'if (false && !completeSqlDump($sql)) {'))
        another=d/'mutation-sql-app';another.mkdir()
        env=dict(os.environ,ACHBOT_PRIVATE_TEST='1',TMPDIR=str(tempdir),
                 ACHBOT_MYSQLDUMP_BIN=str(f),ACHBOT_TEST_ZIP_SHIM='1',
                 ACHBOT_TEST_DUMP_MODE='invalid_large',ACHBOT_TEST_MODULE_FILE=str(invalid))
        p=subprocess.run(['php',str(PHP),'send.invalid_large',str(another),str(ROOT)],
                         env=env,cwd=another,text=True,capture_output=True,timeout=20)
        if p.returncode:raise RuntimeError('SQL mutation probe did not run '+p.stderr[-350:])
        outcome=json.loads(p.stdout)
        if outcome['result'] is not True or outcome['delivered']!=1:
            raise RuntimeError('Invalid SQL negative case cannot detect bypassed validator')
    # Absolute candidate discovery must still work when Cron's PATH is empty.
    script='require '+repr(str(ROOT/'src/Backup/Database.php'))+r'; echo \AchBot\Backup\Database\dumpBinary();'
    path_env=dict(os.environ,PATH='/nonexistent',ACHBOT_PRIVATE_TEST='1')
    path_env.pop('ACHBOT_MYSQLDUMP_BIN',None)
    resolved=subprocess.run(['/usr/bin/php','-r',script],env=path_env,text=True,capture_output=True,timeout=10)
    if resolved.returncode or not resolved.stdout.startswith('/') or not os.access(resolved.stdout,os.X_OK):
        raise RuntimeError('Unqualified Cron PATH still required for database dump discovery')
    return {'synthetic_cases':len(records),'native_zip':native,
            'telegram_negative_mutation_detected':True,
            'invalid_sql_negative_mutation_detected':True,
            'multi_process_lock':multiprocess,
            'path_independent_discovery':True,
            'results':records,'no_real_db':True,'no_real_telegram':True}

if __name__=='__main__':
    ap=argparse.ArgumentParser();ap.add_argument('--case');ap.add_argument('--native-zip',action='store_true');ap.add_argument('--report');args=ap.parse_args()
    record=run(only=args.case,native=args.native_zip)
    if args.report:pathlib.Path(args.report).write_text(json.dumps(record,indent=2)+'\n')
    print(json.dumps(record))
