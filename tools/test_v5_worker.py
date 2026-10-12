#!/usr/bin/env python3
"""Scripted V4-vs-V5 full original Cron entrypoints; ordered SQL, Telegram and checkpoints."""
from __future__ import annotations
import argparse
import hashlib
import json
import os
import shutil
import subprocess
import tempfile
from pathlib import Path
import v5_contract as contract
import sys
sys.path.insert(0,str(contract.ROOT/'tests'))
from run import DISABLED

ROOT=contract.ROOT


def sql(pattern, rows):
    return {'pattern':pattern, 'rows':rows}


def case_base(**spec):
    return {'worker':'messagewizwiz','reads':[], **spec}


def job(action='pin', **extra):
    data={'id':31,'broadcast_id':8,'action':action,'offset':0,'new_text':'Fixture edit','parse_mode':'HTML'}
    data.update(extra)
    reads=[sql(r'/FROM `bc_jobs` WHERE `state`=1 ORDER BY/',[data])]
    if action=='edit':reads.append(sql(r'/SELECT `type` FROM `send_list`/',[{'type':extra.get('type','text')}]))
    reads.append(sql(r'/FROM bc_messages WHERE broadcast_id=/', [{'id':45,'userid':1001,'message_id':501}]))
    return reads


def send_job(**extra):
    data={'id':8,'offset':0,'type':'text','title':'Fixture','file_id':'file01',
          'chat_id':2002,'text':'Hello world','message_id':77,'target_group':'all'}
    data.update(extra)
    return data


def users(n=1):
    return [{'id':i+11,'userid':2001+i,'step':'none','is_agent':0} for i in range(n)]


def send_reads(info=None, recipients=None):
    if info is None:info=send_job()
    if recipients is None:recipients=users()
    return [sql(r'/FROM `send_list` WHERE `state` = 1 OR/',[{'id':info['id']}]),
            sql(r'/SELECT \* FROM `send_list` WHERE `id`=\? LIMIT 1/',[info]),
            sql(r'/FROM `users` WHERE id >/',recipients)]


def warn_reads(primary=None, expired=None, *, have_offset=True, total=1):
    reads=[sql(r'/SELECT `value` FROM `setting` WHERE `type` = \'warnOffset\'/',[{'value':0}] if have_offset else []),
           sql(r'/SELECT \* FROM `orders_list` WHERE `status`=1 AND \(`notif`=0/',primary or [])]
    for item in primary or []:
        reads.append(sql(r'/SELECT \* FROM `server_config` WHERE `id` =/',[{'type':'marzban','panel_url':'fixture.invalid'}]))
    if primary:
        reads.append(sql(r'/SELECT COUNT\(\*\) FROM `orders_list`/',[{'total':total}]))
    reads.append(sql(r'/SELECT \* FROM `orders_list` WHERE `status`=1 AND `notif` > 0/',expired or []))
    for item in expired or []:
        reads.append(sql(r'/SELECT \* FROM `server_config` WHERE `id` =/',[{'type':'marzban','panel_url':'fixture.invalid'}]))
    reads.append(sql(r'/SELECT \* FROM `setting` WHERE `type` = \'AutoRenewCheck\'/',[{'value':0}]))
    return reads


def scenarios():
    cases={}
    def add(name, **spec):
        if name in cases:raise RuntimeError('Duplicate case name')
        cases[name]=case_base(**spec)
    add('idle',reads=[sql(r'/FROM `bc_jobs` WHERE `state`=1 ORDER BY/',[]),
                      sql(r'/FROM `send_list` WHERE `state` = 1 OR/',[])])
    for action in ['pin','unpin','delete','edit']:
        reads=job(action)
        if action!='edit': reads.insert(2,sql(r'/SELECT `state` FROM `bc_jobs`/',[{'state':2}]))
        else:reads.insert(3,sql(r'/SELECT `state` FROM `bc_jobs`/',[{'state':2}]))
        reads.append(sql(r'/FROM `send_list` WHERE `state` = 1 OR/',[]))
        add('job.'+action,reads=reads)
    # The first job iteration checks the cancel flag before contacting Telegram.
    reads=job('pin');reads.insert(2,sql(r'/SELECT `state` FROM `bc_jobs`/',[{'state':4}]))
    reads.append(sql(r'/FROM `send_list` WHERE `state` = 1 OR/',[]))
    add('job.cancel',reads=reads)
    reads=job('pin');reads.insert(2,sql(r'/SELECT `state` FROM `bc_jobs`/',[{'state':2}]))
    reads.append(sql(r'/FROM `send_list` WHERE `state` = 1 OR/',[]))
    add('job.429',reads=reads,responses=[
        {'method':'sendMessage','response':{'ok':True,'result':{'message_id':501}}},
        {'method':'pinChatMessage','response':{'ok':False,'error_code':429,'parameters':{'retry_after':3}}}])
    reads=job('pin');reads.insert(2,sql(r'/SELECT `state` FROM `bc_jobs`/',[{'state':2}]))
    reads.append(sql(r'/SELECT `state` FROM `bc_jobs`/',[{'state':2}]))
    add('job.shutdown',reads=reads,exit_on_method='pinChatMessage')
    # A completed action job, no broadcast send queue.
    add('job.done',reads=[sql(r'/FROM `bc_jobs` WHERE `state`=1 ORDER BY/',[dict(id=31,broadcast_id=8,action='delete',offset=45)]),
                          sql(r'/FROM bc_messages WHERE broadcast_id=/',[]),
                          sql(r'/FROM `send_list` WHERE `state` = 1 OR/',[])])
    add('job.edit.media',reads=[sql(r'/FROM `bc_jobs` WHERE `state`=1 ORDER BY/',
                                [dict(id=31,broadcast_id=8,action='edit',offset=0,new_text='Media caption',parse_mode=None)]),
                                sql(r'/SELECT `type` FROM `send_list`/',[{'type':'photo'}]),
                                sql(r'/FROM bc_messages WHERE broadcast_id=/',[{'id':45,'userid':2001,'message_id':75}]),
                                sql(r'/SELECT `state` FROM `bc_jobs`/',[{'state':2}]),
                                sql(r'/FROM `send_list` WHERE `state` = 1 OR/',[])])
    for group in ['all','has','none','agents']:
        info=send_job(target_group=group)
        recipients=users()
        if group=='all':recipient_sql=r'/FROM `users` WHERE id >/'
        elif group=='has':recipient_sql=r'/FROM `users` u WHERE u.id >/'
        elif group=='none':recipient_sql=r'/FROM `users` u LEFT JOIN `orders_list`/'
        else:recipient_sql=r'/FROM `users` WHERE id >/'
        reads=[sql(r'/FROM `bc_jobs` WHERE `state`=1 ORDER BY/',[])]
        reads.extend(send_reads(info,recipients));reads[-1]['pattern']=recipient_sql
        add('send.'+group,reads=reads)
    for typ,method in [('photo','sendPhoto'),('music','sendAudio'),('video','sendVideo'),
                       ('voice','sendVoice'),('document','sendDocument'),('forwardall','forwardMessage')]:
        reads=[sql(r'/FROM `bc_jobs` WHERE `state`=1 ORDER BY/',[]),*send_reads(send_job(type=typ))]
        add('send.'+typ,reads=reads)
    reads=[sql(r'/FROM `bc_jobs` WHERE `state`=1 ORDER BY/',[]),*send_reads(send_job(type='photo'))]
    add('send.429',reads=reads,responses=[
        {'method':'sendMessage','response':{'ok':True,'result':{'message_id':501}}},
        {'method':'sendPhoto','response':{'ok':False,'error_code':429,'parameters':{'retry_after':4}}}])
    reads=[sql(r'/FROM `bc_jobs` WHERE `state`=1 ORDER BY/',[]),*send_reads(send_job(type='photo'))]
    add('send.403',reads=reads,responses=[
        {'method':'sendMessage','response':{'ok':True,'result':{'message_id':501}}},
        {'method':'sendPhoto','response':{'ok':False,'error_code':403,'description':'Blocked test chat'}}])
    reads=[sql(r'/FROM `bc_jobs` WHERE `state`=1 ORDER BY/',[]),*send_reads(send_job(offset=10),users())]
    reads.append(sql(r'/SELECT `state` FROM `send_list` WHERE `id`=/',[{'state':2}]))
    add('send.shutdown',reads=reads,exit_on_method='sendMessage')
    reads=[sql(r'/FROM `bc_jobs` WHERE `state`=1 ORDER BY/',[]),*send_reads(send_job(),users(10)),
           sql(r'/SELECT `state` FROM `send_list` WHERE `id`=/',[{'state':4}])]
    add('send.cancel',reads=reads)
    reads=[sql(r'/FROM `bc_jobs` WHERE `state`=1 ORDER BY/',[]),*send_reads(send_job(offset=50),[]),
           sql(r'/SELECT COUNT\(\*\) AS c FROM `users` WHERE id <=/',[{'c':40}])]
    add('send.finished',reads=reads)
    add('warn.idle',worker='warnusers',reads=warn_reads())
    one={'id':10,'userid':2001,'remark':'fixture-plan','uuid':'fixture-uuid','server_id':1,'inbound_id':0,
         'notif':0,'amount':10000,'status':1}
    add('warn.initial',worker='warnusers',reads=warn_reads([one]),
        marzban_response={'username':'fixture-plan','data_limit':1073741824,'used_traffic':0,
                          'expire':1800000100,'status':'active'})
    add('warn.missing',worker='warnusers',reads=warn_reads([one]),marzban_response={})
    expired={**one,'notif':1799999990,'token':'stub','link':'','amount':10000}
    add('warn.expired',worker='warnusers',reads=warn_reads([], [expired]),
        marzban_response={'username':'fixture-plan','data_limit':1073741824,'used_traffic':0,
                          'expire':1799999900,'status':'inactive'})
    return cases


def copy_app(origin:Path,target:Path,changed=False):
    paths=['settings/messagewizwiz.php','settings/warnusers.php','settings/jdf.php']
    if changed:paths+=contract.NEW_FILES
    for name in paths:
        p=target/name;p.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(origin/name,p)
    (target/'tests/support').mkdir(parents=True,exist_ok=True)
    shutil.copyfile(ROOT/'tests/support/doubles.php',target/'tests/support/doubles.php')
    shutil.copyfile(ROOT/'tests/v5/worker.php',target/'worker.php')
    shutil.copyfile(ROOT/'tests/v5/config-stub.php',target/'config.php')
    (target/'baseInfo.php').write_text('<?php // isolated synthetic private bootstrap only\n')


def run(root:Path,spec:dict):
    (root/'case.json').write_text(json.dumps(spec,ensure_ascii=True))
    record=root/'result.json'
    if record.exists():record.unlink()
    out_log=root/'settings/error_log.txt'
    if out_log.exists():out_log.unlink()
    env={'PATH':os.environ.get('PATH','/usr/bin:/bin'),'LANG':'C.UTF-8','ACHBOT_PRIVATE_TEST':'1'}
    args=['php','-n','-d','open_basedir='+str(root),'-d','allow_url_fopen=0',
          '-d','display_errors=1','-d','log_errors=1',
          '-d','disable_functions='+DISABLED,str(root/'worker.php')]
    result=subprocess.run(args,cwd=root,env=env,capture_output=True,timeout=15)
    if result.returncode or result.stdout or result.stderr or not record.is_file():
        raise RuntimeError('Worker child failure: '+repr((result.returncode,result.stderr[-600:],result.stdout[-600:])))
    body=json.loads(record.read_text())
    body['log_sha256']=hashlib.sha256(out_log.read_bytes()).hexdigest() if out_log.is_file() else None
    if body['errors']:
        raise RuntimeError('Worker harness defect: '+str(body['errors']))
    return body


def main():
    ap=argparse.ArgumentParser(description=__doc__)
    ap.add_argument('--report',type=Path)
    ap.add_argument('--case',action='append',default=[],help='Only prefixes, authoring only')
    args=ap.parse_args()
    contract.verify()
    cases={n:s for n,s in scenarios().items() if not args.case or any(n.startswith(p) for p in args.case)}
    if not cases:raise SystemExit('No selected tests')
    with contract.previous() as base, tempfile.TemporaryDirectory(prefix='achbot-v5-worker-') as tmp:
        old=Path(tmp)/'old';new=Path(tmp)/'new'
        copy_app(base,old)
        copy_app(ROOT,new,True)
        for name,spec in cases.items():
            before=run(old,spec);after=run(new,spec)
            if before!=after:
                detail={'before':before,'after':after}
                raise RuntimeError('Cron differential mismatch '+name+': '+json.dumps(detail,ensure_ascii=True)[:2500])
    report={'reference':contract.BASE,'cases':len(cases),'case_names':sorted(cases),
            'sql_and_values_ordered':True,'telegram_payloads_ordered':True,
            'checkpoint_and_shutdown':True,'error_log_bytes_compared':True,'real_db':False}
    if args.report:
        args.report.write_text(json.dumps(report,indent=2)+'\n')
    print(json.dumps({k:v for k,v in report.items() if k!='case_names'}))

if __name__=='__main__':main()
