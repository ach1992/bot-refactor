#!/usr/bin/env python3
"""V4-vs-V5 admin broadcast lifecycle: ordered SQL binds, callbacks and return semantics."""
from __future__ import annotations
import argparse
import json
import os
import shutil
import subprocess
import tempfile
from pathlib import Path
import v5_contract as contract
from test_v5_worker import sql
import sys
sys.path.insert(0,str(contract.ROOT/'tests'))
from run import DISABLED
ROOT=contract.ROOT


def row(**kwargs):
    return kwargs


def cases():
    test={}
    def add(name, **args):
        if name in test:raise RuntimeError('Duplicate case')
        test[name]=args
    for actor,admin in [(9001,0),(9002,1),(1001,0)]:
        suffix=f'.{actor}-{admin}'
        allow=(actor==9001 or admin==1)
        add('home.idle'+suffix,data='message2All',actor=actor,is_admin=admin,
            reads=[sql(r'/FROM `send_list` WHERE `state` IN/',[])] if allow else [])
        add('home.running'+suffix,data='message2All',actor=actor,is_admin=admin,
            reads=[sql(r'/FROM `send_list` WHERE `state` IN/',
                       [row(id=8,target_group='agents',type='text',title='Fixture',offset=20)]),
                   sql(r'/FROM `bc_messages` WHERE `broadcast_id`=/',[row(c=3)])] if allow else [])
        add('start'+suffix,data='broadcastStart',actor=actor,is_admin=admin)
        for group in ['all','has','none','agents']:
            add('group.'+group+suffix,data='chooseSendGroup_'+group,actor=actor,is_admin=admin)
            add('draft.title.'+group+suffix,step='s2a_title_'+group,text='Fixture title',actor=actor,is_admin=admin)
        add('cancel'+suffix,data='cancelBroadcast8',actor=actor,is_admin=admin)
        add('draft.confirm'+suffix,data='yesSend2All8',actor=actor,is_admin=admin)
        add('draft.decline'+suffix,data='noDontSend2all8',actor=actor,is_admin=admin)
        add('draft.message.text'+suffix,step='s2a_msg_8',text='Fixture text',actor=actor,is_admin=admin)
        add('draft.message.photo'+suffix,step='s2a_msg_8',text='fixture photo',fileid='file9',filetype='photo',actor=actor,is_admin=admin)
        add('draft.message.forward'+suffix,step='s2a_msg_8',text='Forwarded message',forward=True,actor=actor,is_admin=admin)
        add('history.empty'+suffix,data='broadcastHistory_0',actor=actor,is_admin=admin,
            reads=[sql(r'/FROM `send_list` s LEFT JOIN/',[])] if allow else [])
        add('history.rows'+suffix,data='broadcastHistory_2',actor=actor,is_admin=admin,
            reads=[sql(r'/FROM `send_list` s LEFT JOIN/',
                       [row(id=8,title='Fixture',type='text',target_group='all',state=3,offset=5,
                            sent_count=3,created_at='2026-01-01')])] if allow else [])
        add('pin.page.empty'+suffix,data='pinManageAll',actor=actor,is_admin=admin,
            reads=[sql(r'/SELECT COUNT\(\*\) AS cnt FROM `send_list`/',[row(cnt=0)]),
                   sql(r'/FROM `send_list` s LEFT JOIN/',[])] if allow else [])
        add('pin.page.rows'+suffix,data='pinManageAllPage_0',actor=actor,is_admin=admin,
            reads=[sql(r'/SELECT COUNT\(\*\) AS cnt FROM `send_list`/',[row(cnt=1)]),
                   sql(r'/FROM `send_list` s LEFT JOIN/',
                       [row(id=8,target_group='all',total_cnt=3,pinned_cnt=1,deleted_cnt=0)])] if allow else [])
        add('pin.item'+suffix,data='pinManageSelect_8',actor=actor,is_admin=admin,
            reads=[sql(r'/FROM `send_list` WHERE `id`=/',[row(id=8,title='Fixture',type='text',
                   target_group='all',created_at='2026-01-01',sample_userid=1001,sample_message_id=55,text='Fixture')]),
                   sql(r'/FROM `bc_messages` WHERE `broadcast_id`=/',[row(total_cnt=3,pinned_cnt=1,deleted_cnt=0)])] if allow else [])
        add('pin.missing'+suffix,data='pinManageSelect_8',actor=actor,is_admin=admin,
            reads=[sql(r'/FROM `send_list` WHERE `id`=/',[])] if allow else [])
        add('status.broadcast'+suffix,data='jobsStatusBid_8_0',actor=actor,is_admin=admin,
            reads=[sql(r'/SELECT COUNT\(\*\) AS cnt FROM `bc_jobs`/',[row(cnt=1)]),
                   sql(r'/FROM `bc_jobs` j LEFT JOIN/',[row(id=31,broadcast_id=8,action='pin',offset=45,
                       ok_cnt=1,fail_cnt=0,state=2,created_at='2026-01-01',title='Fixture',target_group='all')])] if allow else [])
        add('edit.ask'+suffix,data='editBroadcastAsk_8',actor=actor,is_admin=admin,
            reads=[sql(r'/FROM `send_list` WHERE `id`=/',[row(type='text',title='Fixture')])] if allow else [])
        add('edit.ask.forward'+suffix,data='editBroadcastAsk_8',actor=actor,is_admin=admin,
            reads=[sql(r'/FROM `send_list` WHERE `id`=/',[row(type='forwardall',title='Fixture')])] if allow else [])
        for action in ['pin','unpin','delete']:
            reads=[sql(r'/SELECT `id` FROM `send_list` WHERE/',[row(id=8)])]
            if action!='delete':reads.append(sql(r'/FROM `bc_messages` WHERE `broadcast_id`=/',[row(total_cnt=4,deleted_cnt=0)]))
            reads.append(sql(r'/SELECT `id` FROM `bc_jobs` WHERE/',[]))
            add('job.create.'+action+suffix,data=f'jobCreate_{action}_8',actor=actor,is_admin=admin,
                reads=reads if allow else [])
        add('job.status'+suffix,data='jobsStatus_0',actor=actor,is_admin=admin,
            reads=[sql(r'/SELECT COUNT\(\*\) AS cnt FROM `bc_jobs`/',[row(cnt=1)]),
                   sql(r'/FROM `bc_jobs` j LEFT JOIN/',[row(id=31,broadcast_id=8,action='delete',
                       offset=40,ok_cnt=8,fail_cnt=1,state=3,created_at='2026-01-01',
                       title='Fixture',target_group='all')])] if allow else [])
        add('job.cancel'+suffix,data='jobCancel_31',actor=actor,is_admin=admin)
        add('edit.save'+suffix,step='bc_edit_8',text='Updated caption',actor=actor,is_admin=admin,
            reads=[sql(r'/FROM `send_list` WHERE `id`=/',[row(id=8,type='text')]),
                   sql(r'/FROM `bc_jobs` WHERE/',[])] if allow else [])
        add('edit.cancel'+suffix,step='bc_edit_8',text='Cancel',actor=actor,is_admin=admin)
    add('noop.callback',data='pmNoop')
    add('draft.empty',step='s2a_title_all',text='!!')
    add('draft.long',step='s2a_title_all',text='x'*260)
    add('edit.empty',step='bc_edit_8',text=' ')
    add('edit.long',step='bc_edit_8',text='x'*4100)
    add('job.existing',data='jobCreate_pin_8',reads=[sql(r'/SELECT `id` FROM `send_list` WHERE/',[row(id=8)]),
        sql(r'/FROM `bc_messages` WHERE `broadcast_id`=/',[row(total_cnt=4,deleted_cnt=0)]),
        sql(r'/SELECT `id` FROM `bc_jobs` WHERE/',[row(id=31)])])
    add('job.deleted',data='jobCreate_pin_8',reads=[sql(r'/SELECT `id` FROM `send_list` WHERE/',[row(id=8)]),
        sql(r'/FROM `bc_messages` WHERE `broadcast_id`=/',[row(total_cnt=4,deleted_cnt=4)])])
    add('edit.duplicate',step='bc_edit_8',text='Test edit',reads=[
        sql(r'/FROM `send_list` WHERE `id`=/',[row(id=8,type='text')]),
        sql(r'/FROM `bc_jobs` WHERE/',[row(id=31)])])
    add('history.invalid-page',data='broadcastHistory_999999',reads=[sql(r'/FROM .send_list. s LEFT JOIN/',[])])
    return test


def fixture(root:Path,reference:Path,changed:bool):
    (root/'tests/support').mkdir(parents=True)
    shutil.copyfile(ROOT/'tests/support/doubles.php',root/'tests/support/doubles.php')
    shutil.copyfile(ROOT/'tests/v5/admin.php',root/'admin.php')
    full=(reference/'bot.php').read_bytes()
    _,original=contract.replace_region(full,b"// BEGIN send to all\nif($data == 'message2All'",b'// END pin Manage\n',b'',include_end=True)
    if changed:
        p=root/'src/Broadcast/Routes.php';p.parent.mkdir(parents=True)
        shutil.copyfile(ROOT/'src/Broadcast/Routes.php',p)
        content=b"<?php\nif ((require __DIR__ . '/src/Broadcast/Routes.php') === null) return;\n"
    else:content=b'<?php\n'+original
    (root/'access-sequence.php').write_bytes(content+b"achEvent('after-route');\n")


def run(root:Path,spec:dict):
    (root/'case.json').write_text(json.dumps(spec,ensure_ascii=True))
    if (root/'result.json').exists():(root/'result.json').unlink()
    env={'PATH':os.environ.get('PATH','/usr/bin:/bin'),'ACHBOT_PRIVATE_TEST':'1','LANG':'C.UTF-8'}
    cmd=['php','-n','-d','open_basedir='+str(root),'-d','allow_url_fopen=0','-d','disable_functions='+DISABLED,
         '-d','display_errors=0','-d','log_errors=0',str(root/'admin.php')]
    child=subprocess.run(cmd,cwd=root,env=env,capture_output=True,timeout=12)
    if child.returncode or child.stdout or child.stderr or not (root/'result.json').is_file():
        raise RuntimeError('Admin test process error: '+repr((child.returncode,child.stdout[-500:],child.stderr[-500:])))
    trace=json.loads((root/'result.json').read_text())
    if trace['errors']:
        raise RuntimeError('Admin scenario harness errors: '+str(trace['errors']))
    return trace


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--report',type=Path)
    parser.add_argument('--case',action='append',default=[],help='Only prefixes, authoring only')
    args=parser.parse_args()
    contract.verify()
    chosen={n:s for n,s in cases().items() if not args.case or any(n.startswith(p) for p in args.case)}
    with contract.previous() as ref, tempfile.TemporaryDirectory(prefix='achbot-v5-admin-') as tmp:
        old=Path(tmp)/'old';new=Path(tmp)/'new';fixture(old,ref,False);fixture(new,ref,True)
        for name,spec in chosen.items():
            try:
                before=run(old,spec);after=run(new,spec)
            except Exception as exc:raise RuntimeError('Case '+name+': '+str(exc)) from exc
            if before!=after:
                raise RuntimeError('Admin route drift '+name+': '+json.dumps({'old':before,'new':after},ensure_ascii=True)[:3000])
    report={'reference':contract.BASE,'cases':len(chosen),'names':sorted(chosen),
            'ordered_sql_bound_values':True,'callbacks_unchanged':True,'top_level_return_preserved':True}
    if args.report:args.report.write_text(json.dumps(report,indent=2)+'\n')
    print(json.dumps({k:v for k,v in report.items() if k!='names'}))

if __name__=='__main__':main()
