#!/usr/bin/env python3
"""PR66 R1: execute the actual gift Cron worker on owned MariaDB.

The exact worker is copied without modification. A local fake implements
Marzban service edits and Telegram sends; no live provider or money is used.
"""
from __future__ import annotations
import argparse
import json
import os
import shutil
import subprocess
from pathlib import Path

import test_access_state as owned
import test_v6_native as native

ROOT=Path(__file__).resolve().parents[1]
DB=native.DBS[1]
ORIGINAL_EXPIRY=1800000100
VERIFIED_EXPIRY=1802000100

CONFIG=r'''<?php
$connection=new mysqli('localhost','root','',getenv('ACHBOT_GIFT_DB'),0,getenv('ACHBOT_GIFT_SOCKET'));
$connection->set_charset('utf8mb4');
$admin=9001;
function giftLog(string $kind,array $values=[]): void {
    file_put_contents(getenv('ACHBOT_GIFT_LOG'),json_encode(
        ['kind'=>$kind]+$values,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)."\n",FILE_APPEND);
}
function achbotSupportsPanel($type){ return $type==='marzban'; }
function getMarzbanJson($sid) {
    giftLog('LIST',['server'=>$sid]);
    return (object)['users'=>[
        (object)['username'=>'fixture-user','data_limit'=>2147483648,
            'expire'=>1800000100,'status'=>getenv('ACHBOT_FIRST_STATUS')?:'active'],
        (object)['username'=>'inactive-user','data_limit'=>2147483648,
            'expire'=>1800000100,'status'=>'disabled']
    ]];
}
function achbotApplyServiceEdit($db,$serverId,array $change) {
    giftLog('EDIT',['server'=>$serverId,'change'=>$change]);
    $mode=getenv('ACHBOT_GIFT_MODE');
    if ($mode==='throw') throw new RuntimeException('synthetic_response_lost');
    if ($mode==='refused') return (object)['success'=>false,'uncertain'=>false];
    if ($mode==='uncertain') return (object)['success'=>false,'uncertain'=>true];
    if ($mode==='unverified') return (object)['success'=>true,'verified'=>false,'uncertain'=>true];
    return (object)['success'=>true,'verified'=>true,'uncertain'=>false,
        'applied_expire'=>$mode==='bad_expire'?2147483648:1802000100,
        'applied_bytes'=>4294967296];
}
function sendMessage(...$args){ giftLog('MESSAGE',['text'=>(string)$args[0],
    'recipient'=>$args[3]??null]);
    if (getenv('ACHBOT_GIFT_MODE')==='notify_fail' &&
        ($args[3]??null)===1001)
        throw new RuntimeException('synthetic_telegram_delivery_failure');
    return (object)['ok'=>true]; }
function sendToAllAdmins(...$args){giftLog('ADMINS');return true;}
'''
RUNNER=r'''<?php
error_reporting(E_ALL);
set_error_handler(static function($level,$text) {
    if ($level!==E_DEPRECATED) throw new ErrorException($text,0,$level);
    return true;
});
require __DIR__.'/settings/gift2all.php';
'''

def q(work,env,sql):
    return native.sql(work,env,sql,DB).strip()

def require(condition, message):
    if not condition: raise RuntimeError(message)

def run_tick(work,env,stage,log,mode,status='active'):
    if log.exists():log.unlink()
    process=subprocess.run(
        ['php','-n','-d','extension=mysqlnd','-d','extension=mysqli',
         '-d','display_errors=1',
         '-d','disable_functions=curl_init,curl_exec,exec,shell_exec,system,passthru,popen,proc_open,mail',
         '-d','allow_url_fopen=0','-d','open_basedir='+str(stage)+':'+str(work),
         str(stage/'run.php')],cwd=stage,
        env={**env,'ACHBOT_GIFT_DB':DB,'ACHBOT_GIFT_SOCKET':str(work/'mysql.sock'),
             'ACHBOT_GIFT_LOG':str(log),'ACHBOT_GIFT_MODE':mode,
             'ACHBOT_FIRST_STATUS':status},
        capture_output=True,text=True,timeout=45)
    require(process.returncode==0 and not process.stdout and not process.stderr,
            f"Worker PHP failed mode={mode}: {process.returncode} {process.stdout[-800:]} {process.stderr[-1600:]}")
    return [json.loads(line) for line in log.read_text().splitlines()] if log.exists() else []

def setup_gift(work,env,volume,day):
    q(work,env,"DELETE FROM setting WHERE type LIKE 'ACHBOT_GIFT_HOLD_%'")
    q(work,env,"DELETE FROM gift_list")
    q(work,env,f"UPDATE orders_list SET server_id=2,inbound_id=0,remark='fixture-user',"
      f"expire_date={ORIGINAL_EXPIRY} WHERE id=91")
    q(work,env,f"INSERT INTO gift_list(server_id,volume,day,`offset`) VALUES(2,{volume},{day},0)")
    return int(q(work,env,"SELECT id FROM gift_list ORDER BY id DESC LIMIT 1"))

def state(work,env,gift_id):
    offset=q(work,env,f'SELECT `offset` FROM gift_list WHERE id={gift_id}')
    expiry=int(q(work,env,'SELECT expire_date FROM orders_list WHERE id=91'))
    holds=int(q(work,env,f"SELECT COUNT(*) FROM setting WHERE type='ACHBOT_GIFT_HOLD_{gift_id}'"))
    detail=q(work,env,f"SELECT value FROM setting WHERE type='ACHBOT_GIFT_HOLD_{gift_id}' LIMIT 1")
    return (None if offset=='' else int(offset),expiry,holds,
            json.loads(detail) if detail else None)

def main():
    ap=argparse.ArgumentParser()
    ap.add_argument('--run-disposable',action='store_true',required=True)
    ap.add_argument('--report',type=Path)
    args=ap.parse_args()
    cases=[]
    with owned.owned_database() as (work,env):
        native.seed(work,env,'http://127.0.0.1:9')
        stage=work/'gift-worker'
        (stage/'settings').mkdir(parents=True)
        shutil.copyfile(ROOT/'settings/gift2all.php',stage/'settings/gift2all.php')
        (stage/'baseInfo.php').write_text('<?php // owned test\n')
        (stage/'config.php').write_text(CONFIG)
        (stage/'run.php').write_text(RUNNER)
        log=work/'gift-worker-events.jsonl'

        for mode,volume,day,want_offset,want_hold,want_expiry in [
            ('verified',2048,2,2,0,VERIFIED_EXPIRY),
            ('refused',2048,2,2,0,ORIGINAL_EXPIRY),
            ('uncertain',2048,2,-1,1,ORIGINAL_EXPIRY),
            ('throw',2048,2,-1,1,ORIGINAL_EXPIRY),
            ('unverified',2048,2,-1,1,ORIGINAL_EXPIRY),
            ('bad_expire',2048,2,-1,1,ORIGINAL_EXPIRY),
            ('verified',0,2,2,0,VERIFIED_EXPIRY),
            ('verified',2048,0,2,0,ORIGINAL_EXPIRY),
            ('notify_fail',2048,2,2,0,VERIFIED_EXPIRY),
            ('verified',1536,2,-1,1,ORIGINAL_EXPIRY),
            ('verified',0,0,-1,1,ORIGINAL_EXPIRY),
        ]:
            gift_id=setup_gift(work,env,volume,day)
            events=run_tick(work,env,stage,log,mode)
            offset,expiry,holds,detail=state(work,env,gift_id)
            require((offset,expiry,holds)==(want_offset,want_expiry,want_hold),
                    f'{mode} state wrong: {(offset,expiry,holds)} expected {(want_offset,want_expiry,want_hold)}')
            edits=[x for x in events if x['kind']=='EDIT']
            if volume==1536 or (volume==0 and day==0):
                require(not edits, f'Invalid units reached provider: {events}')
            else:
                require(len(edits)==1,f'Unexpected effect count: {events}')
                change=edits[0]['change']
                require(('plus_day' in change)==(day>0),f'Wrong zero-day contract: {change}')
                require(('plus_volume' in change)==(volume>0),f'Wrong zero-volume contract: {change}')
                if volume>0:require(change['plus_volume']==volume//1024,f'Wrong MB to GB: {change}')
            customer=[x for x in events if x['kind']=='MESSAGE' and x['recipient']==1001]
            require(bool(customer)==(offset==2 and mode in ('verified','notify_fail')
                                         and volume in (0,2048)),
                    f'False customer success for {mode}/{volume}/{day}: {customer}')
            if mode=='notify_fail':
                notices=[x for x in events if x['kind']=='MESSAGE'
                         and x['recipient']==9001 and 'اعلان' in x['text']]
                require(notices and holds==0 and offset==2,
                        'Postcommit Telegram failure changed gift truth')
            if want_hold:
                require(detail is not None and detail['gift_id']==gift_id and
                        detail['recipient_index']==0 and
                        (detail['username']=='fixture-user' or volume in (1536,0)),
                        f'Missing durable recipient identity: {detail}')
                retry=run_tick(work,env,stage,log,'verified')
                require(not [x for x in retry if x['kind']=='EDIT'],
                        f'Cron replayed held unknown effect: {retry}')
                require(state(work,env,gift_id)[0]==-1,
                        'Hold was cleared without operator reconciliation')
            else:
                continuation=run_tick(work,env,stage,log,'uncertain')
                require(not [x for x in continuation if x['kind']=='EDIT'],
                        'Cron re-edited prior recipient')
                require(state(work,env,gift_id)[0] is None,
                        f'Completed job was not removed: {continuation}')
            cases.append(f'gift_{mode}_volume_{volume}_days_{day}')

        gift_id=setup_gift(work,env,2048,2)
        q(work,env,"CREATE TRIGGER gift_local_update_failure "
          "BEFORE UPDATE ON orders_list FOR EACH ROW "
          "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic_local_failure'")
        try:
            events=run_tick(work,env,stage,log,'verified')
        finally:
            q(work,env,'DROP TRIGGER gift_local_update_failure')
        offset,expiry,holds,detail=state(work,env,gift_id)
        require((offset,expiry,holds)==(-1,ORIGINAL_EXPIRY,1),
                'Remote edit plus local SQL failure lost reconciliation hold')
        require(sum(x['kind']=='EDIT' for x in events)==1 and
                not [x for x in events if x['kind']=='MESSAGE'
                     and x['recipient']==1001],
                'Local SQL failure misannounced gift')
        replay=run_tick(work,env,stage,log,'verified')
        require(not [x for x in replay if x['kind']=='EDIT'],
                'Local SQL failure allowed repeat external effect')
        cases.append('verified_remote_local_sql_failure_stays_held_no_replay')

        gift_id=setup_gift(work,env,2048,2)
        events=run_tick(work,env,stage,log,'verified',status='disabled')
        require(not [x for x in events if x['kind']=='EDIT'],'Inactive user edited')
        require(state(work,env,gift_id)[0]==2,'Inactive user was not advanced without effect')
        cases.append('inactive_recipient_never_inherits_previous_success_or_edit')
    result={'complete':True,'cases':len(cases),'scenarios':cases,
        'actual_gift_worker':True,'owned_mariadb_only':True,
        'real_provider_requests':0,'customer_money_effects':0}
    if args.report:args.report.write_text(json.dumps(result,ensure_ascii=False,indent=2)+'\n')
    print(json.dumps(result,ensure_ascii=False))
if __name__=='__main__':main()
