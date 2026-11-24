#!/usr/bin/env python3
"""Real scheduled Gift Worker, native PasarGuard loopback, disposable MariaDB."""
from __future__ import annotations
import argparse
import copy
import json
import shutil
import subprocess
from datetime import datetime
from pathlib import Path

import notification_fixture
import provider_storage_fixture as storage
import test_access_state as owned
import test_post_v9_direct_gift as direct
import test_post_v9_direct_gift_native as native
import test_post_v9_pasarguard_client as pg
import test_post_v9_gift_worker as worker

ROOT=Path(__file__).resolve().parents[1]
CONFIG=r'''<?php
define('ACHBOT_PG_TEST_LOOPBACK',true);
$connection=new mysqli('localhost','root','',getenv('ACHBOT_DIRECT_DB'),0,getenv('ACHBOT_DIRECT_SOCKET'));
$connection->set_charset('utf8mb4');
$admin=9001;
$telegramWebhookSecret='fixture-direct-gift-token-NotReal_2026';
$botState=['rewardChannel'=>'@fixturechannel'];
require_once __DIR__.'/messages/loader.php';
require_once __DIR__.'/notifications-under-test.php';
require_once __DIR__.'/src/Purchase/Core.php';
function sendMessage($text,$keys=null,$mode=null,$recipient=null) {
    file_put_contents(getenv('ACHBOT_DIRECT_LOG'),json_encode(['recipient'=>(string)$recipient,'text'=>$text,
        'context'=>$GLOBALS['achbotTelegramDiagnosticContext']??null],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)."\n",FILE_APPEND);
    return (object)['ok'=>true];
}
function bot($method,$args=[]) {return (object)['ok'=>true];}
'''
ENQUEUE=r'''<?php
require __DIR__.'/config.php';
require_once __DIR__.'/src/Subscription/GiftBatch.php';
$actor=9002;$step='giftServerVolume2_2';
$update=(object)['update_id'=>(int)$argv[1],'message'=>(object)[
    'from'=>(object)['id'=>$actor,'is_bot'=>false],
    'chat'=>(object)['id'=>$actor,'type'=>'private'],'message_id'=>91,'text'=>'1024']];
$source=achbotGiftTelegramSource($update,$actor,$actor,91,'1024');
echo json_encode(achbotGiftNewBatch($connection,2,1024,2,$source,9001,$step),JSON_THROW_ON_ERROR);
'''
DAY=r'''<?php
require __DIR__.'/config.php';
$userInfo=['step'=>'giftServerDay2','isAdmin'=>true];
$from_id=9002;$text=$argv[1];
function setUser($step){echo json_encode(['step'=>$step]);}
require __DIR__.'/day-route.php';
'''

def run(work,env,stage,script,*args):
    result=subprocess.run(['php','-d','display_errors=0',str(stage/script),*map(str,args)],
        cwd=stage,env={**env,'ACHBOT_DIRECT_DB':direct.DB,
        'ACHBOT_DIRECT_SOCKET':str(work/'mysql.sock'),'ACHBOT_DIRECT_LOG':str(stage/'worker.jsonl'),
        'http_proxy':'','https_proxy':'','all_proxy':'','no_proxy':'127.0.0.1,localhost'},
        capture_output=True,text=True,timeout=40)
    assert result.returncode==0,(script,result.stdout[-1000:],result.stderr[-1800:])
    assert 'fixture-private-key' not in result.stderr and direct.SECRET not in result.stderr
    return result.stdout

def main():
    p=argparse.ArgumentParser()
    p.add_argument('--run-disposable',action='store_true',required=True)
    p.add_argument('--report',type=Path)
    args=p.parse_args()
    checks=[]
    with owned.owned_database() as (work,env),pg.panel() as (server,url):
        stage=direct.setup(work,env)
        native.migration_setup(work,env)
        direct.reset(work,env)
        q=lambda sql:direct.q(work,env,sql)
        q(storage.connection(2,1,url))
        server.user=copy.deepcopy(pg.USER);server.user.update(username='fixture-user',expire=direct.OLD_EXPIRY)
        q(storage.identity(1,91,1,url,17,'2026-10-26T05:00:00.123456Z','fixture-user',pg.MARKER,
            dict(data_limit=2147483648,expire=direct.OLD_EXPIRY,group_ids=[2,5],status='active')))
        (stage/'settings').mkdir()
        shutil.copyfile(ROOT/'settings/gift2all.php',stage/'settings/gift2all.php')
        notification_fixture.stage_router(stage)
        supports=subprocess.check_output(['php','-r',
            "require $argv[1]; echo achbotExtractFunction($argv[2], 'achbotSupportsPanel');",
            str(ROOT/'tools/source_scan.php'),str(ROOT/'config.php')],cwd=ROOT,text=True)
        (stage/'config.php').write_text(CONFIG+'\n'+supports+'\n')
        (stage/'enqueue.php').write_text(ENQUEUE)
        (stage/'worker.php').write_text(worker.RUNNER)
        bot=(ROOT/'bot.php').read_text()
        start=bot.index("if(preg_match('/^giftServerDay")
        end=bot.index("if(preg_match('/^giftServerVolume",start)
        (stage/'day-route.php').write_text('<?php\n'+bot[start:end])
        (stage/'day.php').write_text(DAY)
        for invalid in ('1.5','-1','999999999999','no'):
            assert not run(work,env,stage,'day.php',invalid),invalid
        assert json.loads(run(work,env,stage,'day.php','2.0'))['step']=='giftServerVolume2_2'
        checks.append('actual_day_route_rejects_fraction_and_overflow_canonicalizes_whole_units')
        q("UPDATE users SET step='giftServerVolume2_2' WHERE userid=9002")
        money=q('SELECT userid,wallet FROM users ORDER BY id; SELECT * FROM pays ORDER BY id')
        enqueued=json.loads(run(work,env,stage,'enqueue.php',701))
        assert enqueued['created'],enqueued
        run(work,env,stage,'worker.php')
        assert server.write_count==1,(server.trace,q('SELECT * FROM gift_list'))
        remote=server.user
        expiry=remote['expire']
        if isinstance(expiry,str):expiry=int(datetime.fromisoformat(expiry.replace('Z','+00:00')).timestamp())
        assert expiry==direct.OLD_EXPIRY+2*86400 and remote['data_limit']==3221225472,remote
        run(work,env,stage,'worker.php')
        assert server.write_count==1 and q('SELECT COUNT(*) FROM gift_list')=='0'
        ref='batch:'+str(enqueued['id'])+':0'
        rows=q("SELECT role,recipient,state FROM payment_followup_intents WHERE operational_ref='"+ref+"' ORDER BY role,recipient")
        assert '9001\tacknowledged' in rows and '9002\tacknowledged' in rows and '@fixturechannel\tacknowledged' in rows,rows
        events=[json.loads(line) for line in (stage/'worker.jsonl').read_text().splitlines()]
        assert any(e['recipient']=='1001' for e in events),events
        assert q('SELECT userid,wallet FROM users ORDER BY id; SELECT * FROM pays ORDER BY id')==money
        checks.append('actual_scheduled_worker_native_write_and_original_customer_owner_actor_channel')
        run(work,env,stage,'worker.php')
        assert not json.loads(run(work,env,stage,'enqueue.php',701))['created']
        assert server.write_count==1 and q('SELECT COUNT(*) FROM gift_list')=='0'
        checks.append('completed_worker_and_source_replay_never_repeat_native_write')
        q("UPDATE users SET step='giftServerVolume2_2' WHERE userid=9002")
        held=json.loads(run(work,env,stage,'enqueue.php',702))
        assert held['created']
        server.mode='write_lost'
        run(work,env,stage,'worker.php')
        count=server.write_count
        assert count==2,(server.trace,q('SELECT * FROM gift_list'))
        assert int(q('SELECT `offset` FROM gift_list WHERE id='+str(held['id'])))<0
        run(work,env,stage,'worker.php')
        assert server.write_count==count
        assert q('SELECT COUNT(*) FROM payment_followup_intents WHERE operational_ref LIKE \'batch:'+str(held['id'])+':%\'')=='0'
        assert q('SELECT userid,wallet FROM users ORDER BY id; SELECT * FROM pays ORDER BY id')==money
        checks.append('uncertain_worker_write_keeps_hold_and_never_replays_or_reports_success')
    report={'complete':True,'status':'PASS','cases':len(checks),'scenarios':checks,
        'actual_worker':True,'actual_native_adapter':True,'real_provider_requests':0,'production_operations':0}
    if args.report:args.report.write_text(json.dumps(report,indent=2)+'\n')
    print(json.dumps(report))

if __name__=='__main__':main()
