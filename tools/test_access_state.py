#!/usr/bin/env python3
"""Compare original-schema admin/agent state across V3/V4 code-only swaps."""
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
import check_v4 as check
import v4_contract as c
ROOT=c.ROOT
NAMES=['achbot_v4_old','achbot_v4_new']
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
    with tempfile.TemporaryDirectory(prefix='achbot-access-native-') as tmp:
        d=Path(tmp);(d/'data').mkdir();(d/'.achbot-access-db').write_text('achbot-v4-fixture-only\n')
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
    files=c.runtime_files(lock) if (source/c.NEW_FILES[0]).exists() else c.v3.runtime_files(lock)
    for file in files:
        p=app/file;p.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(source/file,p)
    (app/'access-sequence.php').write_bytes(check.sequence(old,(app/c.NEW_FILES[0]).exists() and b"/src/Admin/Roles.php" in (app/'bot.php').read_bytes()))


def execute(d:Path,env:dict,app:Path,database:str,spec:dict):
    request=dict(spec,app=str(app),database=database)
    (d/'request.json').write_text(json.dumps(request,ensure_ascii=True))
    env=dict(env,ACHBOT_PRIVATE_TEST='1')
    disabled=check.check_v1.run.DISABLED
    cmd=['php','-n','-d','extension=mysqlnd','-d','extension=mysqli','-d','open_basedir='+str(d),
        '-d','allow_url_fopen=0','-d','allow_url_include=0','-d','disable_functions='+disabled,
        '-d','display_errors=0','-d','log_errors=0','-d','memory_limit=128M',str(d/'native_access.php'),str(d)]
    result_path=d/'response.json'
    if result_path.exists():result_path.unlink()
    p=subprocess.run(cmd,cwd=app,env=env,capture_output=True,timeout=15,preexec_fn=check.check_v1.run.enforce_limits)
    if p.returncode or p.stdout or p.stderr or not result_path.exists():raise RuntimeError('Native support child failed: '+p.stderr.decode(errors='replace')[-1200:])
    result=json.loads(result_path.read_text())
    if result['error'] or not result['complete']:raise RuntimeError('Native lifecycle failed: '+str(result['error']))
    if result['schema_before']!=result['schema_after']:raise RuntimeError('Fixture schema changed during support DML')
    return result


def run_lifecycle():
    with c.previous() as (old,lock),owned_database() as (d,env):
        c.verify(ROOT,old,lock)
        reference=d/'reference';stage=d/'stage'
        prepare_app(old,reference,old,lock)
        prepare_app(old,stage,old,lock)
        shutil.copyfile(ROOT/'tests/v4/native_access.php',d/'native_access.php')
        # The actual, byte-identical old state writer is used by both test sides.
        extract="require $argv[1]; echo '<?php'.PHP_EOL.achbotExtractFunction($argv[2],'setUser');"
        declaration=subprocess.check_output(['php','-n','-d','extension=tokenizer','-r',
                                             extract,str(old/'tools/source_scan.php'),str(old/'config.php')])
        (d/'set-user.php').write_bytes(declaration)
        private={'baseInfo.php':b'<?php // synthetic private config, never loaded\n',
                 'settings/QRCode.jpg':b'synthetic asset',
                 'private-state.json':b'{"unchanged":true}'}
        for rel,body in private.items():
            p=stage/rel;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(body)
        actions=[
            ('request',{'actor':1001,'data':'requestAgency'}),
            ('approval-start',{'actor':9001,'data':'agencyApprove1001'}),
            ('approval-save',{'actor':9001,'text':'12'}),
            ('agent-menu',{'actor':1001,'data':'agencySettings'}),
            ('discount-start',{'actor':9001,'data':'editAgentDiscountNormal1001_0'}),
            ('discount-save',{'actor':9001,'text':'21'}),
            ('remove-agent',{'actor':9001,'data':'removeAgent1001'}),
            ('reapply',{'actor':1001,'data':'requestAgency'}),
            ('decline',{'actor':9001,'data':'agencyDecline1001'}),
            ('admin-start',{'actor':9001,'data':'addNewAdmin'}),
            ('admin-save',{'actor':9001,'text':'1001'}),
            ('admin-remove',{'actor':9001,'data':'delAdmin1001'}),
            ('ban-start',{'actor':9001,'data':'banUser'}),
            ('ban-finish',{'actor':9001,'text':'1001'}),
            ('unban-start',{'actor':9001,'data':'unbanUser'}),
            ('unban-finish',{'actor':9001,'text':'1001'}),
            ('rejected-list',{'actor':9001,'data':'rejectedAgentList'}),
            ('rejected-release',{'actor':9001,'data':'releaseRejectedAgent1001'}),
            ('noop',{'actor':1001,'data':'wizwizch'}),
        ]
        phases=['old-source'];cases=[];schema_identity=None
        for n,(name,spec) in enumerate(actions):
            if name=='approval-save':
                for rel in c.NEW_FILES:
                    p=stage/rel;p.parent.mkdir(parents=True,exist_ok=True)
                    shutil.copyfile(ROOT/rel,p)
                phases.append('modules-first-old-source')
            if name=='agent-menu':
                prepare_app(ROOT,stage,old,lock)
                phases.append('v4-candidate-source')
            if name=='discount-save':
                prepare_app(old,stage,old,lock)
                phases.append('v3-code-restored')
            if name=='remove-agent':
                prepare_app(ROOT,stage,old,lock)
                phases.append('v4-reapplied')
            spec=dict(spec,epoch=1800000000+n)
            old_record=execute(d,env,reference,NAMES[0],spec)
            new_record=execute(d,env,stage,NAMES[1],spec)
            if old_record!=new_record:
                keys=[k for k in old_record if old_record[k]!=new_record[k]]
                raise RuntimeError('Native V4/V3 DML parity failure '+name+' '+str(keys))
            if schema_identity is None:schema_identity=old_record['schema_before']
            if old_record['schema_before']!=schema_identity:raise RuntimeError('SQL schema drift between requests')
            users={int(row['userid']):row for row in new_record['rows']['users']}
            applicant=users[1001];owner=users[9001]
            if name=='request' and int(applicant['is_agent'])!=2:
                raise RuntimeError('Agency request not persisted')
            if name=='approval-start' and owner['step']!='agencyApprove1001_71':
                raise RuntimeError('Approval wizard state not persisted')
            if name=='approval-save' and (int(applicant['is_agent'])!=1 or json.loads(applicant['discount_percent'])['normal']!='12'):
                raise RuntimeError('Approval did not persist existing user fields')
            if name=='discount-start' and owner['step']!='editAgentDiscountNormal1001_0':
                raise RuntimeError('Discount wizard not persisted on candidate')
            if name=='discount-save' and (owner['step']!='none' or json.loads(applicant['discount_percent'])['normal']!='21'):
                raise RuntimeError('Old-source rollback could not finish candidate-started wizard')
            if name=='remove-agent' and int(applicant['is_agent'])!=0:
                raise RuntimeError('Agent removal failed')
            if name=='decline' and (int(owner['is_agent'])!=-1 or int(applicant['is_agent'])!=2):
                raise RuntimeError('Legacy setUser extra-argument decline behavior changed')
            if name=='admin-save' and int(applicant['isAdmin'])!=1:
                raise RuntimeError('Admin grant state mismatch')
            if name=='admin-remove' and int(applicant['isAdmin'])!=0:
                raise RuntimeError('Admin revoke state mismatch')
            if name=='ban-finish' and applicant['step']!='banned':
                raise RuntimeError('User ban state mismatch')
            if name=='unban-finish' and applicant['step']!='none':
                raise RuntimeError('User unban state mismatch')
            if name=='rejected-release' and int(applicant['is_agent'])!=0:
                raise RuntimeError('Rejected-agent release failed')
            if any((stage/rel).read_bytes()!=body for rel,body in private.items()):
                raise RuntimeError('Private config/asset/state changed')
            cases.append({'case':name,'phase':phases[-1],
                          'warnings':len(new_record['warnings']),
                          'events':len(new_record['events']),
                          'rows_sha256':hashlib.sha256(json.dumps(new_record['rows'],sort_keys=True).encode()).hexdigest()})
        return {'native_access_cases':len(cases),
                'process_executions':len(cases)*2,
                'cases':cases,'source_phases':phases,'engine':old_record['engine'],
                'sql_mode':old_record['sql_mode'],'schema_sha256':schema_identity,
                'schema_unchanged':True,'original_setUser_used':True,
                'persisted_access_wizard_continuity':True,'private_files_preserved':True,
                'native_network_listener':False,'real_provider_used':False,
                'whole_webhook_executed':False,'fpm_opcache_tested':False,
                'operator_manual_acceptance':False,'production_acceptance':False}

def main():
    ap=argparse.ArgumentParser(description=__doc__)
    ap.add_argument('--run-disposable',action='store_true',required=True)
    ap.add_argument('--report',type=Path,required=True)
    args=ap.parse_args()
    data=run_lifecycle();data.update(c.identity())
    args.report.write_text(json.dumps(data,indent=2)+'\n')
    print(json.dumps({k:v for k,v in data.items() if k!='cases'},sort_keys=True))
if __name__=='__main__':main()
