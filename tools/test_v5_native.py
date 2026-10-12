#!/usr/bin/env python3
"""Paired V4/V5 original-schema native workers on owned socket-only MariaDB."""
from __future__ import annotations
import argparse
import hashlib
import json
import os
import shutil
import subprocess
import sys
import time
from pathlib import Path
import v5_contract as contract
import test_access_state as original

ROOT=contract.ROOT
TABLES=['send_list','bc_jobs','bc_messages','users','setting','orders_list']
DBS=['achbot_v4_old','achbot_v4_new']
NAMES=['reference','stage']


def sql_command(work:Path,env:dict,sql:str,dbname=None):
    cmd=['mariadb','--no-defaults','--protocol=SOCKET','--socket='+str(work/'mysql.sock'),
         '-uroot','--batch','--skip-column-names','--raw']
    if dbname:cmd.append(dbname)
    cmd+=['-e',sql]
    p=subprocess.run(cmd,env=env,capture_output=True,timeout=15)
    if p.returncode:raise RuntimeError('Isolated MariaDB SQL failed: '+p.stderr.decode(errors='replace')[:600])
    return p.stdout.decode()


def make_app(source:Path,target:Path):
    for name in ['settings/messagewizwiz.php','settings/warnusers.php','settings/jdf.php']:
        t=target/name;t.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(source/name,t)
    for name in contract.NEW_FILES:
        f=source/name
        if f.is_file():
            t=target/name;t.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(f,t)
    shutil.copyfile(ROOT/'tests/v5/native-worker.php',target/'native-worker.php')
    shutil.copyfile(ROOT/'tests/v5/native-config.php',target/'config.php')
    private=target/'baseInfo.php'
    if not private.exists():private.write_text('<?php // isolated private fixture, protected across source swaps\n')
    sentinel=target/'private-state.json'
    if not sentinel.exists():sentinel.write_text('{"synthetic_fixture":true}\n')


def run(work:Path,env:dict,app:Path,dbname:str,spec:dict):
    (app/'native-case.json').write_text(json.dumps(spec,ensure_ascii=True))
    result=app/'native-result.json'
    if result.exists():result.unlink()
    e=dict(env,ACHBOT_PRIVATE_TEST='1',ACHBOT_V5_NATIVE='1',ACHBOT_DATABASE=dbname,ACHBOT_SOCKET=str(work/'mysql.sock'))
    cmd=['php','-n','-d','extension=mysqlnd','-d','extension=mysqli',
         '-d','disable_functions=sleep,usleep,exec,shell_exec,system,passthru,popen,proc_open,pcntl_exec,pcntl_fork,mail,dl',
         '-d','allow_url_fopen=0','-d','open_basedir='+str(app)+':'+str(work),
         '-d','display_errors=0','-d','log_errors=0',str(app/'native-worker.php')]
    p=subprocess.run(cmd,cwd=app,env=e,capture_output=True,timeout=20)
    if p.returncode or p.stdout or p.stderr or not result.is_file():
        raise RuntimeError('Native worker process failure '+repr((p.returncode,p.stdout[-500:],p.stderr[-500:])))
    record=json.loads(result.read_text())
    if record['errors']:
        raise RuntimeError('Native worker errors '+str(record['errors'])[:900])
    return record


def snapshot(work:Path,env:dict,dbname:str):
    result={}
    for name,query in [
        ('broadcast','SELECT id,`offset`,type,state,target_group,COALESCE(sample_userid,0),COALESCE(sample_message_id,0),COALESCE(text,\'\') FROM send_list ORDER BY id'),
        ('jobs','SELECT id,broadcast_id,action,`offset`,state,ok_cnt,fail_cnt FROM bc_jobs ORDER BY id'),
        ('messages','SELECT id,broadcast_id,userid,message_id,pinned,deleted FROM bc_messages ORDER BY id'),
        ('users','SELECT id,userid,COALESCE(step,\'\') FROM users ORDER BY id'),
        ('warn','SELECT type,value FROM setting WHERE type IN (\'warnOffset\',\'AutoRenewCheck\') ORDER BY type'),
        ('orders','SELECT id,userid,status,notif,autoRenew FROM orders_list ORDER BY id'),
    ]:
        result[name]=sql_command(work,env,query,dbname)
    return result


def assert_native_expectations(scenario:str,step:int,state:dict):
    """Native tests must assert meaningful outcomes, not parity of two no-ops."""
    sends=state['broadcast'].strip().split('\t') if state['broadcast'].strip() else []
    jobs=state['jobs'].strip().split('\t') if state['jobs'].strip() else []
    messages=state['messages'].strip().splitlines()
    if scenario=='send.complete':
        if not sends or int(sends[1])<=0 or int(sends[3])!=(1 if step==0 else 3):
            raise RuntimeError('Expected send batch to checkpoint then complete')
        if step>=1 and len(messages)==0:raise RuntimeError('Expected persisted recipient message history')
    if scenario=='send.interrupt' and step==0:
        if not sends or int(sends[3])!=1 or int(sends[1])<=0:
            raise RuntimeError('Expected shutdown to pause in-progress sender')
    if scenario=='send.429' and step==0:
        if not sends or int(sends[1])<=0 or len(messages)!=len(state['users'].splitlines())-1:
            raise RuntimeError('Expected original 429 behavior: cursor progressed and one missing send: '+str((sends,len(messages))))
    if scenario=='send.cancel' and step==0:
        if not sends or int(sends[3])!=4:raise RuntimeError('Expected cancel state=4')
    if scenario.startswith('job.'):
        if not jobs:raise RuntimeError('Expected one native action job')
        if scenario=='job.cancel' and int(jobs[4])!=4:
            raise RuntimeError('Expected action job cancelled')
        if scenario=='job.interrupt' and step==0 and (int(jobs[4])!=1 or int(jobs[3])<=0):
            raise RuntimeError('Expected action shutdown pause+checkpoint')
        if scenario=='job.429' and step==0 and int(jobs[6])!=1:
            raise RuntimeError('Expected original rate-limited action failure counter')
        if scenario in ['job.pin','job.unpin','job.edit','job.delete']:
            expected=1 if step==0 else 3
            if int(jobs[4])!=expected:raise RuntimeError('Expected action batch pause then completion')
    if scenario=='warn.eligible' and '\t-1\t' not in state['orders']:
        raise RuntimeError('Expected persisted expiry warning notification flag')
    if scenario=='warn.expired' and state['orders'].strip():
        raise RuntimeError('Expected synthetic expired subscription cleanup')


def fixture_seed(work:Path,env:dict,scenario:str):
    for dbname in DBS:
        reset='DELETE FROM bc_jobs;DELETE FROM bc_messages;DELETE FROM send_list;DELETE FROM orders_list;DELETE FROM server_config WHERE id=2;'
        reset+='DELETE FROM users WHERE userid>=2000;DELETE FROM setting WHERE type IN (\'warnOffset\',\'AutoRenewCheck\');'
        sql_command(work,env,reset,dbname)
        if scenario.startswith('send.'):
            extras=12 if scenario=='send.cancel' else 3
            lines=[]
            for i in range(extras):
                uid=2001+i
                lines.append(f"({uid},'Fixture {uid}','synthetic','0',0,'1700000000',0,'sent')")
            sql_command(work,env,'INSERT INTO users (userid,name,username,refcode,wallet,date,isAdmin,first_start) VALUES '+','.join(lines)+';',dbname)
            send_type='photo'
            sql_command(work,env,"INSERT INTO send_list (id,`offset`,type,title,text,file_id,state,target_group) VALUES (8,0,'photo','Fixture','Fixture message','fake-file',1,'all');",dbname)
        elif scenario.startswith('job.'):
            action='pin'
            if scenario=='job.delete':action='delete'
            if scenario=='job.edit':action='edit'
            if scenario=='job.unpin':action='unpin'
            sql_command(work,env,"INSERT INTO send_list (id,`offset`,type,title,text,state,target_group) VALUES (8,0,'text','Fixture','Hello',3,'all');",dbname)
            pinned=1 if action=='unpin' else 0
            sql_command(work,env,'INSERT INTO bc_messages (id,broadcast_id,userid,message_id,pinned) VALUES '+','.join(
                f'({i+45},8,{2001+i},{301+i},{pinned})' for i in range(3))+';',dbname)
            sql_command(work,env,f"INSERT INTO bc_jobs (id,broadcast_id,action,`offset`,state,new_text) VALUES (31,8,'{action}',0,1,'Edited fixture');",dbname)
        elif scenario.startswith('warn.'):
            if scenario != 'warn.idle':
                sql_command(work,env,"INSERT INTO server_config (id,panel_url,ip,sni,header_type,request_header,response_header,security,tlsSettings,type,username,password) VALUES (2,'https://fixture.invalid','127.0.0.1','','none','','','none','{}','marzban','','');",dbname)
                notice=0 if scenario=='warn.eligible' else int(time.time())-10
                sql_command(work,env,f"INSERT INTO orders_list (id,userid,token,transid,fileid,server_id,inbound_id,remark,uuid,expire_date,link,amount,status,date,notif,autoRenew) VALUES (91,2001,'fixture','fixture',7,2,0,'fixture-plan','fixture-uuid',1800000100,'',1000,1,'2026-01-01',{notice},'off');",dbname)
        else:raise RuntimeError('Unknown native scenario')


def steps_for(name:str):
    if name=='send.complete':return [({},'v4'),({},'v5'),({},'v4')]
    if name=='send.429':return [({'rate_method':'sendPhoto','rate_chat_id':2001},'v4'),({},'v5')]
    if name=='send.interrupt':return [({'interrupt_method':'sendPhoto','interrupt_chat_id':2001},'v4'),({},'v5'),({},'v4')]
    if name=='send.cancel':return [({'cancel_on_method':'sendPhoto','cancel_chat_id':2005,'cancel_id':8,'cancel_table':'send_list'},'v5')]
    if name in ['job.pin','job.unpin','job.edit','job.delete']:
        return [({},'v4'),({},'v5'),({},'v4')]
    if name=='job.429':return [({'rate_method':'pinChatMessage','rate_chat_id':2001},'v4'),({},'v5')]
    if name=='job.interrupt':return [({'interrupt_method':'pinChatMessage','interrupt_chat_id':2001},'v4'),({},'v5'),({},'v4')]
    if name=='job.cancel':return [({'cancel_on_method':'sendMessage','cancel_chat_id':9001,'cancel_id':31,'cancel_table':'bc_jobs'},'v5')]
    if name=='warn.idle':return [({},'v4'),({},'v5')]
    if name=='warn.eligible':return [({'panel':{'username':'fixture-plan','data_limit':1073741824,
        'used_traffic':0,'expire':int(time.time())+3600,'status':'active'}},'v4'),
        ({'panel':{'username':'fixture-plan','data_limit':1073741824,'used_traffic':0,
          'expire':int(time.time())+3600,'status':'active'}},'v5')]
    if name=='warn.expired':return [({'panel':{'username':'fixture-plan','data_limit':1073741824,
        'used_traffic':1073741824,'expire':int(time.time())-100,'status':'inactive'}},'v5')]
    raise RuntimeError('Unknown scenario: '+name)


def main():
    ap=argparse.ArgumentParser(description=__doc__)
    ap.add_argument('--run-disposable',action='store_true',required=True)
    ap.add_argument('--report',type=Path)
    ap.add_argument('--case',action='append',default=[])
    a=ap.parse_args()
    contract.verify()
    cases=['send.complete','send.429','send.interrupt','send.cancel',
           'job.pin','job.unpin','job.edit','job.delete','job.429','job.interrupt','job.cancel','warn.idle','warn.eligible','warn.expired']
    names=[n for n in cases if not a.case or any(n.startswith(p) for p in a.case)]
    report=[]
    with contract.previous() as prior,original.owned_database() as (work,env):
        ref=work/'reference';stage=work/'stage';ref.mkdir();stage.mkdir()
        make_app(prior,ref);make_app(prior,stage)
        private={p.name:hashlib.sha256(p.read_bytes()).hexdigest() for p in [stage/'baseInfo.php',stage/'private-state.json']}
        for name in names:
            fixture_seed(work,env,name)
            for step,(spec,version) in enumerate(steps_for(name)):
                make_app(ROOT if version=='v5' else prior,stage)
                request={'worker':'warnusers' if name.startswith('warn.') else 'messagewizwiz',**spec}
                old_run=run(work,env,ref,DBS[0],request)
                new_run=run(work,env,stage,DBS[1],request)
                if old_run!=new_run:
                    raise RuntimeError('Native worker emitted differing calls '+name+': '+json.dumps({'old':old_run,'new':new_run},ensure_ascii=True)[:1800])
                old_data=snapshot(work,env,DBS[0]);new_data=snapshot(work,env,DBS[1])
                if old_data!=new_data:
                    raise RuntimeError('Native MariaDB state difference '+name+' step '+str(step)+': '+str({k:[old_data[k],new_data[k]] for k in old_data if old_data[k]!=new_data[k]})[:1600])
                assert_native_expectations(name,step,old_data)
                report.append({'scenario':name,'step':step+1,'source':version,
                               'send':old_data['broadcast'].strip(), 'jobs':old_data['jobs'].strip(),
                               'messages_count':len(old_data['messages'].splitlines()),
                               'mock_api_events':sum(x['name']=='telegram' for x in old_run['events'])})
        for p in [stage/'baseInfo.php',stage/'private-state.json']:
            if hashlib.sha256(p.read_bytes()).hexdigest()!=private[p.name]:
                raise RuntimeError('Source switch changed private data: '+p.name)
    summary={'reference':contract.BASE,'native_db':'owned original 23-table MariaDB, private socket, --skip-networking',
             'scenarios':len(names),'process_steps':len(report),'details':report,
             'external_effects':'strict fake API/panel','private_source_swap_ok':True}
    if a.report:a.report.write_text(json.dumps(summary,indent=2)+'\n')
    print(json.dumps({k:v for k,v in summary.items() if k!='details'}))

if __name__=='__main__':main()
