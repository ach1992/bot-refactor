#!/usr/bin/env python3
"""Compare actual support DML and persisted wizards across private code swap/rollback."""
from __future__ import annotations
import argparse
import contextlib
import hashlib
import json
import os
import pwd
import shutil
import subprocess
import tempfile
import time
from pathlib import Path
import check_v3 as check
import v3_contract as c
ROOT=c.ROOT
NAMES=['achbot_v3_old','achbot_v3_new']
SEED="""
INSERT INTO users (userid,name,username,refcode,wallet,date,isAdmin,first_start) VALUES
(1001,'Fixture','fixture_user','0',50000,'1700000000',0,'sent'),
(9001,'Fixture Admin','fixture_admin','0',0,'1700000000',0,'sent'),
(9002,'Fixture Delegate','fixture_delegate','0',0,'1700000000',1,'sent');
INSERT INTO setting (id,type,value) VALUES (50,'TICKETS_CATEGORY','Technical');
INSERT INTO chats (id,user_id,create_date,title,category,state,rate) VALUES(7,1001,1700000000,'Existing fixture','Technical',0,0);
INSERT INTO chats_info (id,chat_id,sent_date,msg_type,text) VALUES (70,7,1700000000,'USER','Existing message');
"""

@contextlib.contextmanager
def owned_database():
    for tool in ['mariadb-install-db','mariadbd','mariadb','php']:
        if not shutil.which(tool):raise RuntimeError('Missing existing prerequisite: '+tool)
    env={'PATH':os.environ.get('PATH','/usr/bin:/bin'),'LANG':'C.UTF-8','HOME':'/nonexistent'}
    with tempfile.TemporaryDirectory(prefix='achbot-support-native-') as tmp:
        d=Path(tmp);(d/'data').mkdir();(d/'.achbot-support-db').write_text('achbot-v3-fixture-only\n')
        with (d/'process.log').open('wb') as log:
            user=pwd.getpwuid(os.getuid()).pw_name
            subprocess.run(['mariadb-install-db','--no-defaults','--datadir='+str(d/'data'),
                '--auth-root-authentication-method=normal','--skip-test-db','--user='+user],env=env,check=True,stdout=log,stderr=log,timeout=45)
            cmd=['mariadbd','--no-defaults','--datadir='+str(d/'data'),'--socket='+str(d/'mysql.sock'),
                '--pid-file='+str(d/'mysql.pid'),'--log-error='+str(d/'server.log'),'--skip-networking','--skip-name-resolve',
                '--innodb-buffer-pool-size=32M','--innodb-log-file-size=8M','--key-buffer-size=8M','--aria-pagecache-buffer-size=8M',
                '--max-connections=10','--tmpdir='+str(d),'--user='+user]
            server=subprocess.Popen(cmd,env=env,stdout=log,stderr=log)
            client=['mariadb','--no-defaults','--protocol=SOCKET','--socket='+str(d/'mysql.sock'),'-uroot','--batch']
            try:
                for _ in range(100):
                    if server.poll() is not None:raise RuntimeError('Owned fixture exited before readiness')
                    if (d/'mysql.sock').exists() and subprocess.run(client+['-e','SELECT 1'],env=env,capture_output=True,timeout=3).returncode==0:break
                    time.sleep(.1)
                else:raise RuntimeError('Owned fixture not ready')
                for name in NAMES:
                    subprocess.run(client+['-e','CREATE DATABASE '+name+' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'],env=env,check=True,capture_output=True,timeout=5)
                    sql=(ROOT/'database/legacy-schema.sql').read_bytes()+SEED.encode()
                    subprocess.run(client+[name],input=sql,env=env,check=True,capture_output=True,timeout=10)
                yield d,env
            finally:
                if server.poll() is None:server.terminate()
                try:server.wait(timeout=10)
                except subprocess.TimeoutExpired:server.kill();server.wait(timeout=5)


def prepare_app(source:Path,app:Path,old:Path,lock:dict):
    files=c.runtime_files(lock) if (source/c.NEW_FILES[0]).exists() else c.v2.runtime_files(lock)
    for file in files:
        p=app/file;p.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(source/file,p)
    (app/'support-sequence.php').write_bytes(check.support_sequence(old,(app/c.NEW_FILES[0]).exists() and b"/src/Support/Entry.php" in (app/'bot.php').read_bytes()))


def execute(d:Path,env:dict,app:Path,database:str,spec:dict):
    request=dict(spec,app=str(app),database=database)
    (d/'request.json').write_text(json.dumps(request,ensure_ascii=True))
    env=dict(env,ACHBOT_PRIVATE_TEST='1')
    disabled=check.run.DISABLED
    cmd=['php','-n','-d','extension=mysqlnd','-d','extension=mysqli','-d','open_basedir='+str(d),
        '-d','allow_url_fopen=0','-d','allow_url_include=0','-d','disable_functions='+disabled,
        '-d','display_errors=0','-d','log_errors=0','-d','memory_limit=128M',str(d/'native_support.php'),str(d)]
    result_path=d/'response.json'
    if result_path.exists():result_path.unlink()
    p=subprocess.run(cmd,cwd=app,env=env,capture_output=True,timeout=15,preexec_fn=check.run.enforce_limits)
    if p.returncode or p.stdout or p.stderr or not result_path.exists():raise RuntimeError('Native support child failed: '+p.stderr.decode(errors='replace')[-1200:])
    result=json.loads(result_path.read_text())
    if result['error'] or not result['complete']:raise RuntimeError('Native lifecycle failed: '+str(result['error']))
    if result['schema_before']!=result['schema_after']:raise RuntimeError('Fixture schema changed during support DML')
    return result


def run_lifecycle():
    with c.previous() as (old,lock),owned_database() as (d,env):
        c.verify(ROOT,old,lock)
        reference=d/'reference';stage=d/'stage';prepare_app(old,reference,old,lock);prepare_app(old,stage,old,lock)
        shutil.copyfile(ROOT/'tests/v3/native_support.php',d/'native_support.php')
        code="require $argv[1]; echo '<?php'.PHP_EOL.achbotExtractFunction($argv[2],'setUser');"
        decl=subprocess.check_output(['php','-n','-d','extension=tokenizer','-r',code,str(old/'tools/source_scan.php'),str(old/'config.php')])
        (d/'set-user.php').write_bytes(decl)
        sentinels={'baseInfo.php':b'<?php // synthetic private sentinel, not loaded\n','settings/QRCode.jpg':b'fixture asset','private-state.json':b'{"unchanged":true}'}
        for f,b in sentinels.items():p=stage/f;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(b)
        phases=['old-source'];results=[];ids={};first_hash=None
        actions=[
            ('entry',{'data':'supportSection'}),('choose',{'data':'supportCatTechnical'}),
            ('title',{'text':"fixture /'# title"}),('create',{'text':"fixture /'# body"}),
            ('admin-choose',{'actor':9001,'data':'reply_{ticket}'}),('admin-answer',{'actor':9001,'text':"admin /'# answer"}),
            ('user-list',{'data':'userAllTickets'}),('user-reply-choose',{'data':'replySupport_{ticket}'}),
            ('user-photo-answer',{'text':None,'photo':True}),('admin-close',{'actor':9001,'data':'closeTicket_{ticket}'}),
            ('rating',{'data':'rate_7_5'}),('history',{'data':'latestMsg_{ticket}'}),
            ('category-choose',{'actor':9001,'data':'addTicketCategory'}),('category-save',{'actor':9001,'text':'Billing'}),
            ('category-delete',{'actor':9001,'data':'delTicketCat_{category}'}),('admin-queue',{'actor':9001,'data':'allTickets'}),
            ('attachment',{'text':'/dlPic{photo}'}),('noop',{'data':'wizwizch'}),
        ]
        for n,(name,spec) in enumerate(actions):
            if name=='title':
                for f in c.NEW_FILES:
                    p=stage/f;p.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(ROOT/f,p)
                phases.append('modules-first-old-source')
                # Source is still old even though new handler dependencies are now present.
            if name in ['create','user-photo-answer']:
                prepare_app(ROOT,stage,old,lock);phases.append('candidate-source' if name=='create' else 'candidate-reapplied')
            if name=='admin-answer':
                prepare_app(old,stage,old,lock);phases.append('old-source-restored')
            spec={k:(v.format(**ids) if isinstance(v,str) else v) for k,v in spec.items()};spec['epoch']=1800000000+n
            a=execute(d,env,reference,NAMES[0],spec);b=execute(d,env,stage,NAMES[1],spec)
            if a!=b:raise RuntimeError('Native persisted-state parity failure: '+name)
            if first_hash is None:first_hash=a['schema_before']
            if a['schema_before']!=first_hash:raise RuntimeError('Schema changed between requests')
            users={int(r['userid']):r for r in b['rows']['users']};chats=b['rows']['chats'];messages=b['rows']['chats_info']
            if name=='choose' and users[1001]['step']!='newTicket_Technical':raise RuntimeError('Category wizard not persisted')
            if name=='title' and (users[1001]['step']!='sendTicket_Technical' or users[1001]['temp']!="fixture /'# title"):raise RuntimeError('Title wizard not persisted')
            if name=='create':
                if len(chats)!=2 or users[1001]['step']!='none' or users[1001]['temp'] is not None:raise RuntimeError('Ticket creation storage mismatch')
                ids['ticket']=int(chats[-1]['id'])
            if name=='admin-choose' and users[9001]['step']!='answer_'+str(ids['ticket']):raise RuntimeError('Admin wizard not persisted')
            if name=='admin-answer' and (int(chats[-1]['state'])!=1 or users[9001]['step']!='none' or messages[-1]['msg_type']!='ADMIN'):raise RuntimeError('Rollback did not resume the pending admin reply')
            if name=='user-photo-answer':
                payload=json.loads(messages[-1]['text'])
                if payload['file_id']!='fixture-photo' or users[1001]['step']!='none':raise RuntimeError('Photo reply persistence mismatch')
                ids['photo']=int(messages[-1]['id'])
            if name=='admin-close' and int(chats[-1]['state'])!=2:raise RuntimeError('Ticket close state mismatch')
            if name=='rating' and int(chats[0]['rate'])!=5:raise RuntimeError('Ticket rating not persisted')
            if name=='category-save':ids['category']=int(b['rows']['setting'][-1]['id'])
            if any((stage/f).read_bytes()!=value for f,value in sentinels.items()):raise RuntimeError('Private runtime sentinel overwritten')
            results.append({'case':name,'phase':phases[-1],'message_events':len(a['events']),'warnings':len(a['warnings']),
                            'state_sha256':hashlib.sha256(json.dumps(a['rows'],sort_keys=True).encode()).hexdigest()})
        return {'native_support_cases':len(results),'process_executions':len(results)*2,'cases':results,'source_phases':phases,
            'engine':a['engine'],'sql_mode':a['sql_mode'],'schema_sha256':first_hash,'schema_unchanged':True,
            'persisted_support_wizard_continuity':True,'original_setUser_used':True,'private_files_preserved':True,
            'native_network_listener':False,'real_provider_used':False,'whole_webhook_executed':False,
            'fpm_opcache_tested':False,'operator_manual_acceptance':False,'production_acceptance':False}


def main():
    ap=argparse.ArgumentParser(description=__doc__);ap.add_argument('--run-disposable',action='store_true',required=True);ap.add_argument('--report',type=Path,required=True);args=ap.parse_args()
    report=run_lifecycle();report.update(c.identity());args.report.write_text(json.dumps(report,indent=2)+'\n')
    print(json.dumps({k:v for k,v in report.items() if k!='cases'},sort_keys=True))
if __name__=='__main__':main()
