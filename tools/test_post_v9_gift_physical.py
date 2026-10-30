#!/usr/bin/env python3
"""Real PHP gift worker -> Core -> Accounts cURL -> two owned Marzban panels.

Disposable original-schema MariaDB and loopback synthetic HTTP only.
No installed tenant, real provider, Telegram, payments or external network.
"""
from __future__ import annotations

import argparse
import contextlib
import json
import os
import shutil
import subprocess
import threading
from http.server import BaseHTTPRequestHandler,ThreadingHTTPServer
from pathlib import Path

import test_access_state as owned
import test_v6_native as native
import test_post_v9_gift_worker as gift

ROOT=Path(__file__).resolve().parents[1]
DB=native.DBS[1]


def require(ok,msg):
    if not ok:raise RuntimeError(msg)

def sql(work,env,query):
    return native.sql(work,env,query,DB).strip()

def remote_user(name):
    return {
        'username':name,'expire':gift.ORIGINAL_EXPIRY,
        'data_limit':2147483648,'used_traffic':0,
        'status':'active','data_limit_reset_strategy':'no_reset',
        'inbounds':{'vless':['inbound']},
        'proxies':{'vless':{'id':'test-only-'+name}},
    }

class Panel(BaseHTTPRequestHandler):
    def log_message(self,*args):pass
    def do_POST(self):self.route()
    def do_GET(self):self.route()
    def do_PUT(self):self.route()

    def route(self):
        server=self.server
        body=self.rfile.read(int(self.headers.get('Content-Length','0')))
        server.events.append({'method':self.command,'path':self.path})
        key=(self.command,self.path)
        payload={};status=200;redirect=None
        try:
            if key==('POST','/api/admin/token'):
                if b'fixture-admin' not in body or b'fixture-password' not in body:
                    status=401;payload={'detail':'bad_credentials'}
                else:
                    payload={'access_token':'owned-token'}
                    server.fire('after_login')
            elif key==('GET','/api/users'):
                payload={'users':list(server.accounts.values()),
                         'total':len(server.accounts)}
                if server.list_mode=='missing_total':
                    del payload['total']
                elif server.list_mode=='wrong_total':
                    payload['total']+=1
                elif server.list_mode=='redirect_to_other_panel':
                    status=302
                    redirect=server.other_url+'/api/users'
                server.fire('after_list')
            elif self.path.startswith('/api/user/'):
                username=self.path.split('/api/user/',1)[1]
                if username not in server.accounts:
                    status=404;payload={'detail':'unknown_user'}
                elif self.command=='GET':
                    if server.fail_readback and server.put_count>0:
                        status=503;payload={'detail':'synthetic_readback_lost'}
                    else:
                        payload=dict(server.accounts[username])
                        server.fire('after_user_get')
                elif self.command=='PUT':
                    request=json.loads(body)
                    require(set(request.keys()) <= {'data_limit','expire'} and request,
                            'Unexpected/unsafe fields in service edit PUT')
                    server.put_count+=1
                    server.accounts[username].update(request)
                    payload=dict(server.accounts[username])
                    server.fire('after_put')
                    if server.lost_put:
                        status=503;payload={'detail':'synthetic_put_response_lost'}
                else:
                    status=405;payload={'detail':'unsupported'}
            else:
                status=404;payload={'detail':'unrecognized'}
        except Exception as error:
            server.errors.append(str(error))
            status=500;payload={'detail':'fixture_error'}
        raw=json.dumps(payload,separators=(',',':')).encode()
        self.send_response(status)
        if redirect:self.send_header('Location',redirect)
        self.send_header('Content-Type','application/json')
        self.send_header('Content-Length',str(len(raw)))
        self.end_headers()
        try:self.wfile.write(raw)
        except BrokenPipeError:pass

@contextlib.contextmanager
def panels():
    a=ThreadingHTTPServer(('127.0.0.1',0),Panel)
    b=ThreadingHTTPServer(('127.0.0.1',0),Panel)
    for server in (a,b):
        server.events=[];server.errors=[];server.accounts={}
        server.put_count=0;server.hook_at=None;server.hook=None
        server.lost_put=False;server.fail_readback=False;server.list_mode='complete'
        def fire(where,sv=server):
            if sv.hook_at==where and sv.hook is not None:
                action=sv.hook
                sv.hook=None;sv.hook_at=None
                action()
        server.fire=fire
    a.other_url='http://127.0.0.1:'+str(b.server_address[1])
    b.other_url='http://127.0.0.1:'+str(a.server_address[1])
    threads=[threading.Thread(target=s.serve_forever,daemon=True) for s in (a,b)]
    for t in threads:t.start()
    try:yield a,b,*('http://127.0.0.1:'+str(s.server_address[1]) for s in (a,b))
    finally:
        for s in (a,b):s.shutdown()
        for t in threads:t.join(timeout=5)
        for s in (a,b):s.server_close()

CONFIG=r'''<?php
$connection=new mysqli('localhost','root','',getenv('ACHBOT_GIFT_DB'),0,getenv('ACHBOT_GIFT_SOCKET'));
$connection->set_charset('utf8mb4');
$admin=9001;
$botState=['rewardChannel'=>'@fixture-gift-private-channel'];
require_once __DIR__.'/notifications-under-test.php';
function achbotSupportsPanel($type){return $type==='marzban';}
require_once __DIR__.'/src/Panel/Accounts.php';
require_once __DIR__.'/src/Purchase/Core.php';
function getMarzbanJson($server,$token=null,?array $target=null){
    return \AchBot\Panel\Accounts\getMarzbanJson($server,$token,$target);
}
function getMarzbanToken($server,?string $url=null,?array $target=null){
    return \AchBot\Panel\Accounts\getMarzbanToken($server,$url,$target);
}
function getMarzbanUser($server,$name,$token=null,bool $strict=false,
                       ?string $url=null,?array $target=null){
    return \AchBot\Panel\Accounts\getMarzbanUser($server,$name,$token,$strict,$url,$target);
}
function editMarzbanConfig($server,$info,?array $target=null){
    return \AchBot\Panel\Accounts\editMarzbanConfig($server,$info,$target);
}
function sendMessage(...$args){
    file_put_contents(getenv('ACHBOT_GIFT_LOG'),
        json_encode(['kind'=>'MESSAGE','recipient'=>$args[3]??null],
          JSON_THROW_ON_ERROR)."\n",FILE_APPEND);
    if (getenv('ACHBOT_GIFT_MODE')==='interrupt_after_commit' &&
        ($args[3]??null)===1001) exit(0);
    return (object)['ok'=>true];
}
'''

def prepare(work):
    stage=work/'real-gift'
    (stage/'settings').mkdir(parents=True)
    # Core's actual common bootstrap spans purchase/subscription/finance.
    # Stage the full real module tree; keep the isolated config/transports below.
    shutil.copytree(ROOT/'src',stage/'src')
    shutil.copyfile(ROOT/'settings/gift2all.php',stage/'settings/gift2all.php')
    shutil.copytree(ROOT/'messages',stage/'messages')
    gift.stage_notifications(stage)
    (stage/'config.php').write_text(CONFIG)
    (stage/'baseInfo.php').write_text('<?php // disposable isolated harness\n')
    (stage/'run.php').write_text(gift.RUNNER)
    (stage/'enqueue.php').write_text(gift.ENQUEUE)
    return stage

def php_args(stage,runner):
    return ['php','-n','-d','extension=mysqlnd','-d','extension=mysqli',
            '-d','extension=curl','-d','display_errors=1',
            '-d','allow_url_fopen=0',
            '-d','disable_functions=exec,shell_exec,system,passthru,popen,proc_open,mail',
            '-d','open_basedir='+str(stage)+':'+str(stage.parent),
            str(stage/runner)]

def php(work,env,stage,runner,mode,log,args=()):
    log.unlink(missing_ok=True)
    result=subprocess.run(php_args(stage,runner)+list(args),cwd=stage,
        env={**env,'ACHBOT_GIFT_DB':DB,'ACHBOT_GIFT_SOCKET':str(work/'mysql.sock'),
             'ACHBOT_GIFT_LOG':str(log),'ACHBOT_GIFT_MODE':mode,
             'http_proxy':'','https_proxy':'','all_proxy':'',
             'no_proxy':'127.0.0.1,localhost'},
        capture_output=True,text=True,timeout=45)
    require(result.returncode==0 and not result.stderr,
            'Actual PHP failed: '+str([result.returncode,result.stdout[-500:],result.stderr[-1200:]]))
    return result.stdout

def reset(work,env,stage,log,a,b,url_a):
    sql(work,env,"DELETE FROM setting WHERE type LIKE 'ACHBOT_GIFT_%'")
    sql(work,env,"DELETE FROM gift_list")
    sql(work,env,"UPDATE server_config SET panel_url='"+url_a+
        "',username='fixture-admin',password='fixture-password',type='marzban' WHERE id=2")
    sql(work,env,f"UPDATE orders_list SET expire_date={gift.ORIGINAL_EXPIRY},"
        "server_id=2,inbound_id=0,remark='fixture-user' WHERE id=91")
    a.events.clear();b.events.clear();a.errors.clear();b.errors.clear()
    a.put_count=0;b.put_count=0
    a.accounts={n:remote_user(n) for n in ('fixture-user','bob')}
    b.accounts={n:remote_user(n) for n in ('fixture-user','bob')}
    a.hook=None;a.hook_at=None;a.lost_put=False;a.fail_readback=False;a.list_mode='complete'
    b.hook=None;b.hook_at=None;b.lost_put=False;b.fail_readback=False;b.list_mode='complete'
    out=php(work,env,stage,'enqueue.php','verified',log,['1024','0']).strip()
    require(out.isdigit(),'Real enqueue failed to create new-format job: '+out)
    return int(out)

def edits(server):
    return sum(e['method']=='PUT' and e['path'].startswith('/api/user/') for e in server.events)

def state(work,env,gid):
    raw=sql(work,env,'SELECT `offset` FROM gift_list WHERE id='+str(gid))
    hold=sql(work,env,
             "SELECT value FROM setting WHERE type='ACHBOT_GIFT_HOLD_"+str(gid)+"'")
    return (None if raw=='' else int(raw),json.loads(hold) if hold else None)

def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('--run-disposable',action='store_true',required=True)
    parser.add_argument('--report',type=Path)
    args=parser.parse_args()
    cases=[]
    with owned.owned_database() as (work,env),panels() as (a,b,url_a,url_b):
        native.seed(work,env,url_a)
        sql(work,env,"INSERT INTO server_info (id,title,ucount,remark,flag,active,state) "
            "VALUES (2,'Fixture','0','fixture','','1',1) "
            "ON DUPLICATE KEY UPDATE title='Fixture'")
        gift.seed_second_order(work,env)
        stage=prepare(work);log=work/'real-gift-events.jsonl'

        gid=reset(work,env,stage,log,a,b,url_a)
        php(work,env,stage,'run.php','verified',log)
        require(edits(a)==2 and edits(b)==0 and state(work,env,gid)[0]==2 and
                not a.errors,'Both frozen accounts were not edited on panel A')
        php(work,env,stage,'run.php','verified',log)
        require(state(work,env,gid)[0] is None and edits(a)==2,
                'Final cleanup re-edited panel A')
        cases.append('original_panel_real_php_two_members_one_edit_each')

        gid=reset(work,env,stage,log,a,b,url_a)
        php(work,env,stage,'run.php','interrupt_after_commit',log)
        require(edits(a)==1 and state(work,env,gid)[0]==1,
                'Initial panel A gift did not persist progress')
        sql(work,env,"UPDATE server_config SET panel_url='"+url_b+"' WHERE id=2")
        php(work,env,stage,'run.php','verified',log)
        offset,hold=state(work,env,gid)
        require(offset==-2 and hold and hold['username']=='' and
                edits(b)==0 and edits(a)==1,
                'Panel B substituted original panel A after first commit')
        cases.append('same_username_on_two_panels_continuation_never_targets_b')
        sql(work,env,"UPDATE server_config SET panel_url='"+url_a+"' WHERE id=2")
        php(work,env,stage,'run.php','verified',log)
        require(edits(a)==1 and edits(b)==0 and state(work,env,gid)[0]==-2,
                'Restoring original panel automatically replayed held edit')
        cases.append('original_panel_restored_held_effect_never_replayed')

        gid=reset(work,env,stage,log,a,b,url_a)
        sql(work,env,"UPDATE server_config SET username='changed-admin' WHERE id=2")
        php(work,env,stage,'run.php','verified',log)
        require(edits(a)==0 and edits(b)==0 and state(work,env,gid)[0]==-1,
                'Credential drift allowed provider edit')
        cases.append('credential_identity_change_before_list_denied')

        for point in ('after_login','after_list','after_user_get','after_put'):
            gid=reset(work,env,stage,log,a,b,url_a)
            a.hook_at=point
            a.hook=lambda:sql(work,env,
                "UPDATE server_config SET panel_url='"+url_b+"' WHERE id=2")
            php(work,env,stage,'run.php','verified',log)
            offset,hold=state(work,env,gid)
            require(hold is not None and offset==-1 and edits(b)==0,
                    'Wrong-panel or missing hold after provider switch '+point)
            require(edits(a)==(1 if point=='after_put' else 0),
                    'Unexpected original panel effects '+point)
            cases.append('panel_swap_'+point+'_no_wrong_panel_edit_hold')

        gid=reset(work,env,stage,log,a,b,url_a)
        a.hook_at='after_user_get'
        a.hook=lambda:sql(work,env,
            "UPDATE server_config SET password='changed-pass' WHERE id=2")
        php(work,env,stage,'run.php','verified',log)
        require(edits(a)==0 and edits(b)==0 and state(work,env,gid)[0]==-1,
                'Authentication identity change before PUT allowed an edit')
        cases.append('credentials_changed_after_authenticated_user_get_no_put')

        gid=reset(work,env,stage,log,a,b,url_a)
        a.lost_put=True;a.fail_readback=True
        php(work,env,stage,'run.php','verified',log)
        require(edits(a)==1 and edits(b)==0 and state(work,env,gid)[0]==-1,
                'Lost PUT/readback allowed repeated effect')
        php(work,env,stage,'run.php','verified',log)
        require(edits(a)==1 and state(work,env,gid)[0]==-1,
                'Unknown remote effect replayed on next cron')
        cases.append('ambiguous_original_panel_put_and_get_failure_no_replay')

        sql(work,env,(ROOT/'migrations/post-v9/001_marzban_panel_group_id.sql').read_text())
        group=sql(work,env,
            "SELECT id FROM setting WHERE type='ACHBOT_MARZBAN_PANEL_GROUP' ORDER BY id LIMIT 1")
        if not group:
            sql(work,env,
                "INSERT INTO setting(type,value) VALUES('ACHBOT_MARZBAN_PANEL_GROUP','fixture')")
            group=sql(work,env,
                "SELECT id FROM setting WHERE type='ACHBOT_MARZBAN_PANEL_GROUP' ORDER BY id DESC LIMIT 1")
        sql(work,env,"UPDATE server_info SET panel_group_id="+group+" WHERE id=2")
        gid=reset(work,env,stage,log,a,b,url_a)
        sql(work,env,"UPDATE server_config SET panel_url='"+url_b+"' WHERE id=2")
        php(work,env,stage,'run.php','verified',log)
        require(edits(b)==0 and state(work,env,gid)[0]==-1,
                'Same logical panel group authorized physical URL substitution')
        cases.append('same_logical_group_does_not_authorize_physical_change')

        for list_mode in ('missing_total','wrong_total','redirect_to_other_panel'):
            gid=reset(work,env,stage,log,a,b,url_a)
            a.list_mode=list_mode
            php(work,env,stage,'run.php','verified',log)
            require(state(work,env,gid)[0]==0 and edits(a)==0 and edits(b)==0 and
                    not b.events,
                    'Malformed/redirected list was accepted by pinned PHP HTTP: '+list_mode)
            cases.append('real_http_list_'+list_mode+'_no_snapshot_or_edit')

        gid=reset(work,env,stage,log,a,b,url_a)
        a.hook_at='after_put'
        a.hook=lambda:sql(work,env,
            "UPDATE server_config SET password='reconfigured' WHERE id=2")
        php(work,env,stage,'run.php','verified',log)
        require(edits(a)==1 and edits(b)==0 and state(work,env,gid)[0]==-1,
                'Credential drift during PUT lost hold or redirected readback')
        cases.append('credential_drift_during_put_holds_original_effect')
    result={'complete':True,'cases':len(cases),'scenarios':cases,
            'actual_php_list_edit_http':True,'separate_loopback_panels':2,
            'owned_mariadb_only':True,'real_provider_requests':0}
    if args.report:args.report.write_text(json.dumps(result,indent=2)+'\n')
    print(json.dumps(result))
if __name__=='__main__':main()
