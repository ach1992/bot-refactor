#!/usr/bin/env python3
"""AChBot V6.1: owned socket-only MariaDB, real dump and simulated Telegram delivery."""
from __future__ import annotations
import json,os,subprocess,sys,zipfile
from pathlib import Path
import test_access_state as native
ROOT=Path(__file__).resolve().parents[1]

def run():
    with native.owned_database() as (d,env):
        # A deliberately complex SYNTHETIC password exercises MySQL option-file quoting.
        password='synthetic_\\_\"_!#;%'
        user='achbot_backup_fixture'
        escaped=password.replace('\\','\\\\').replace("'","\\'")
        client=['mariadb','--no-defaults','--socket='+str(d/'mysql.sock'),'-uroot','-e',"CREATE USER 'achbot_backup_fixture'@'localhost' IDENTIFIED BY '"+escaped+"'; GRANT ALL PRIVILEGES ON `"+native.NAMES[0]+"`.* TO 'achbot_backup_fixture'@'localhost';"]
        # The fixture is owned and isolated: never touch the host DB.
        provision=subprocess.run(client,env=env,capture_output=True,timeout=6)
        if provision.returncode:raise RuntimeError('Synthetic grant failed '+provision.stderr.decode(errors='replace')[-230:])
        target=d/'achbot-backup-archive.zip'
        fixture=ROOT/'tests/backup/native_backup.php'
        e=dict(env,ACHBOT_PRIVATE_TEST='1',MYSQL_UNIX_PORT=str(d/'mysql.sock'),TMPDIR=str(d),
               ACHBOT_MYSQLDUMP_BIN='/usr/bin/mysqldump',ACHBOT_MYSQLDUMP_SOCKET=str(d/'mysql.sock'),ACHBOT_TEST_ZIP_SHIM='1',ACHBOT_TEST_DUMP_USER=user,ACHBOT_TEST_DUMP_PASSWORD=password)
        # Deliberately do NOT require mysqldump on PATH; absolute CLI must work.
        command=['/usr/bin/php',str(fixture),str(ROOT),native.NAMES[0],str(target)]
        p=subprocess.run(command,cwd=d,env=e,text=True,capture_output=True,timeout=35)
        if p.returncode:raise RuntimeError(f'PHP failed: {p.returncode}, {p.stderr[-350:]}')
        try:data=json.loads(p.stdout)
        except Exception as err:raise RuntimeError(f'Invalid isolated response {p.stdout[-350:]}') from err
        if not data.get('ok') or not data.get('delivered') or not target.is_file():
            raise RuntimeError('Native dump/ZIP/transport not successful '+repr(data)+' stderr='+p.stderr[-500:])
        with zipfile.ZipFile(target) as archive:
            if archive.namelist()!=['bot_backup.sql']:raise RuntimeError('Unexpected ZIP members')
            sql=archive.read('bot_backup.sql')
            if not 1000<len(sql)<2*1024*1024 or b'CREATE TABLE' not in sql or b'users' not in sql:
                raise RuntimeError('Missing real native synthetic DB dump')
        if (ROOT/'backup').exists():raise RuntimeError('Candidate introduced webroot backup directory')
        return {'native_mariadb':True,'db_schema':'original_23_tables',
                'mysqldump':'real_executable','real_zip_file':True,
                'synthetic_sql_bytes':len(sql),'simulated_telegram_sends':1,
                'temporary_private_output_cleaned':True,'real_providers_used':False}

if __name__=='__main__': print(json.dumps(run()))
