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
import notification_fixture

ROOT=Path(__file__).resolve().parents[1]
DB=native.DBS[1]
ORIGINAL_EXPIRY=1800000100
VERIFIED_EXPIRY=1802000100

CONFIG=r'''<?php
$connection=new mysqli('localhost','root','',getenv('ACHBOT_GIFT_DB'),0,getenv('ACHBOT_GIFT_SOCKET'));
$connection->set_charset('utf8mb4');
$admin=9001;
$botState=['rewardChannel'=>'@fixture-gift-private-channel'];
require_once __DIR__.'/notifications-under-test.php';
function giftLog(string $kind,array $values=[]): void {
    file_put_contents(getenv('ACHBOT_GIFT_LOG'),json_encode(
        ['kind'=>$kind]+$values,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)."\n",FILE_APPEND);
}
function achbotSupportsPanel($type){ return $type==='marzban'; }
function getMarzbanJson($sid,$token=null,?array $giftTarget=null) {
    if (getenv('ACHBOT_GIFT_MODE')!=='legacy_old_worker' &&
        ($giftTarget===null ||
         !achbotGiftTargetMatches($GLOBALS['connection'],(int)$sid,$giftTarget)))
        return (object)['success'=>false,'msg'=>'target_invalid'];
    giftLog('LIST',['server'=>$sid,'panel_url'=>$giftTarget['panel_url']??'legacy']);
    if (getenv('ACHBOT_GIFT_MODE')==='switch_after_list') {
        $GLOBALS['connection']->query(
            "UPDATE server_config SET panel_url='http://127.0.0.1:10' WHERE id=2");
    }
    $file=getenv('ACHBOT_GIFT_ROSTER_FILE');
    if ($file && is_file($file)) {
        $users=json_decode(file_get_contents($file));
        if (!is_array($users)) throw new RuntimeException('bad_test_roster');
        if (getenv('ACHBOT_GIFT_MODE')==='missing_total')
            return (object)['users'=>$users];
        $total=count($users);
        if (getenv('ACHBOT_GIFT_MODE')==='partial_page') $total++;
        if (getenv('ACHBOT_GIFT_MODE')==='empty_bad_total') {
            $users=[];$total=1;
        }
        if (getenv('ACHBOT_GIFT_MODE')==='invalid_total_string') $total=(string)$total;
        if (getenv('ACHBOT_GIFT_MODE')==='invalid_total_negative') $total=-1;
        if (getenv('ACHBOT_GIFT_MODE')==='invalid_total_float') $total=(float)$total;
        return (object)['users'=>$users,'total'=>$total];
    }
    return (object)['users'=>[
        (object)['username'=>'fixture-user','data_limit'=>2147483648,
            'expire'=>1800000100,'status'=>getenv('ACHBOT_FIRST_STATUS')?:'active'],
        (object)['username'=>'inactive-user','data_limit'=>2147483648,
            'expire'=>1800000100,'status'=>'disabled']
    ],'total'=>2];
}
function achbotApplyServiceEdit($db,$serverId,array $change,?array $giftTarget=null) {
    if ($giftTarget===null ||
        !achbotGiftTargetMatches($db,(int)$serverId,$giftTarget))
        return (object)['success'=>false,'uncertain'=>true,
                        'msg'=>'gift_physical_target_changed'];
    giftLog('EDIT',['server'=>$serverId,'change'=>$change]);
    $mode=getenv('ACHBOT_GIFT_MODE');
    if ($mode==='parallel') usleep(200000);
    if ($mode==='order_vanish_after_provider')
        $db->query('DELETE FROM orders_list WHERE id=91');
    if ($mode==='throw') throw new RuntimeException('synthetic_response_lost');
    if ($mode==='refused') return (object)['success'=>false,'uncertain'=>false];
    if ($mode==='uncertain') return (object)['success'=>false,'uncertain'=>true];
    if ($mode==='unverified') return (object)['success'=>true,'verified'=>false,'uncertain'=>true];
    return (object)['success'=>true,'verified'=>true,'uncertain'=>false,
        'applied_expire'=>$mode==='bad_expire'?2147483648:1802000100,
        'applied_bytes'=>4294967296];
}
function editMarzbanConfig($serverId,$change) {
    giftLog('EDIT',['server'=>$serverId,'change'=>$change]);
    if (getenv('ACHBOT_GIFT_MODE')==='legacy_old_worker') exit(0);
    return (object)['success'=>true,'verified'=>true,'applied_expire'=>1802000100,
                    'applied_bytes'=>4294967296];
}
function sendMessage(...$args){ giftLog('MESSAGE',['text'=>(string)$args[0],
    'recipient'=>$args[3]??null,'parse_mode'=>$args[2]??null,
    'reply_markup'=>$args[1]??null]);
    if (getenv('ACHBOT_GIFT_MODE')==='manager_notice_fail' &&
        ($args[3]??null)===9101)
        throw new RuntimeException('synthetic_manager_delivery_failure');
    if (getenv('ACHBOT_GIFT_MODE')==='notify_fail' &&
        ($args[3]??null)===1001)
        throw new RuntimeException('synthetic_telegram_delivery_failure');
    if (getenv('ACHBOT_GIFT_MODE')==='notify_unacknowledged' &&
        ($args[3]??null)===1001)
        return (object)['ok'=>false];
    // Exit from the actual worker's post-COMMIT notification boundary.
    // This process terminates before next recipient/cleanup, with no code
    // mutation of the source PHP worker.
    if (getenv('ACHBOT_GIFT_MODE')==='interrupt_after_commit' &&
        ($args[3]??null)===1001) exit(0);
    return (object)['ok'=>true]; }
function bot($method,$data=[]) {
    if ($method!=='sendMessage') throw new RuntimeException('fixture_message_transport_only');
    return sendMessage($data['text']??'', $data['reply_markup']??null,
        $data['parse_mode']??null, $data['chat_id']??null);
}
'''
ENQUEUE=r'''<?php
error_reporting(E_ALL);
set_error_handler(static function($level,$text) {
    if ($level!==E_DEPRECATED) throw new ErrorException($text,0,$level);
    return true;
});
require __DIR__.'/config.php';
require __DIR__.'/src/Subscription/GiftBatch.php';
$volume=(int)$argv[1];$days=(int)$argv[2];
$step='giftServerVolume2_'.$days;
$set=$connection->prepare('UPDATE users SET step=? WHERE userid=9001');
$set->bind_param('s',$step);$set->execute();$set->close();
$update=(object)[
  'update_id'=>701,
  'message'=>(object)[
    'from'=>(object)['id'=>9001,'is_bot'=>false],
    'chat'=>(object)['id'=>9001,'type'=>'private'],
    'message_id'=>91,'text'=>(string)$volume
  ]
];
$source=achbotGiftTelegramSource($update,9001,9001,91,(string)$volume);
$created=achbotGiftNewBatch($connection,2,$volume,$days,$source,9001,$step);
echo $created['id'];
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

def stage_notifications(stage):
    notification_fixture.stage_router(stage)
    # Historical worker controls still call the actual current secondary
    # facade. Only its Telegram transport is spied; no routing substitute.
    definition=subprocess.check_output(['php','-r',
        "require $argv[1]; echo achbotExtractFunction($argv[2], 'sendToAllAdmins');",
        str(ROOT/'tools/source_scan.php'),str(ROOT/'config.php')],cwd=ROOT,text=True)
    with (stage/'notifications-under-test.php').open('a') as stream:
        stream.write('\n'+definition+'\n')


def seed_notification_staff(work,env):
    for actor,role in [(9101,'manager'),(9102,'manager'),(9103,'operator'),(9104,'support')]:
        q(work,env,"INSERT INTO users(userid,name,username,refcode,wallet,date,isAdmin,first_start,step) "
          "VALUES("+str(actor)+",'Fixture Staff','staff','0',0,'1700000000',1,'sent','none')")
        q(work,env,"INSERT INTO setting(type,value) VALUES('ADMIN_ROLE_"+str(actor)+"','"+role+"')")
    q(work,env,"INSERT INTO setting(type,value) VALUES('ADMIN_NOTIFY_9102','off')")


def assert_private_fanout(events,expected=(9001,9101)):
    notices=[e for e in events if e['kind']=='MESSAGE' and e['recipient'] not in (1001,1002)\
        and not e['text'].startswith('🎁 هدیهٔ اشتراک اعمال شد')]
    require(notices,'No administrator notices observed')
    groups={}
    for notice in notices:
        require(notice.get('parse_mode') is None and notice.get('reply_markup') is None,
                'Private gift notice changed formatting')
        groups.setdefault(notice['text'],[]).append(notice['recipient'])
    require(all(sorted(recipients)==sorted(expected) for recipients in groups.values()),
            'Private gift fanout changed text, duplicated a Manager or reached excluded staff/channel: '+repr(groups))
    return groups


def _php_command(stage,work):
    return [
        'php','-n','-d','extension=mysqlnd','-d','extension=mysqli',
        '-d','display_errors=1',
        '-d','disable_functions=curl_init,curl_exec,exec,shell_exec,system,passthru,popen,proc_open,mail',
        '-d','allow_url_fopen=0','-d','open_basedir='+str(stage)+':'+str(work),
        str(stage/'run.php')
    ]

def _roster(stage, members):
    path=stage/'current-roster.json'
    if members is None:
        path.unlink(missing_ok=True)
    else:
        path.write_text(json.dumps(members,ensure_ascii=False))
    return path

def _run_env(env,work,log,roster_path,mode,status):
    return {**env,'ACHBOT_GIFT_DB':DB,'ACHBOT_GIFT_SOCKET':str(work/'mysql.sock'),
            'ACHBOT_GIFT_LOG':str(log),'ACHBOT_GIFT_MODE':mode,
            'ACHBOT_GIFT_FIRST_STATUS':status, 'ACHBOT_FIRST_STATUS':status,
            'ACHBOT_GIFT_ROSTER_FILE':str(roster_path)}

def run_tick(work,env,stage,log,mode,status='active',members=None,old=False):
    if log.exists():log.unlink()
    roster=_roster(stage,members)
    process=subprocess.run(
        (_php_command(stage,work)[:-1]+[str(stage/'run-legacy.php')]
         if old else _php_command(stage,work)),cwd=stage,
        env=_run_env(env,work,log,roster,mode,status),
        capture_output=True,text=True,timeout=45)
    stderr_lines=process.stderr.splitlines()
    allowed_diagnostics=(bool(stderr_lines) and all(
        (mode=='manager_notice_fail' and (notification_fixture.sanitized_exception_line(line) or\
         __import__('re').fullmatch(r'achbot_gift_completion_report_unconfirmed reference=batch:[1-9][0-9]*:[0-9]+',line))) or
        (mode in ('notify_fail','notify_unacknowledged') and line.strip()=='achbot_gift_customer_notice_unconfirmed order_id=91 userid=1001')
        for line in stderr_lines))
    require(process.returncode==0 and not process.stdout and (not process.stderr or allowed_diagnostics),
            f"Worker PHP failed mode={mode}: {process.returncode} {process.stdout[-800:]} {process.stderr[-1600:]}")
    return [json.loads(line) for line in log.read_text().splitlines()] if log.exists() else []

def setup_gift(work,env,stage,volume,day,legacy=False):
    q(work,env,"DELETE FROM setting WHERE type LIKE 'ACHBOT_GIFT_%'")
    q(work,env,"DELETE FROM gift_list")
    q(work,env,f"UPDATE orders_list SET server_id=2,inbound_id=0,remark='fixture-user',"
      f"expire_date={ORIGINAL_EXPIRY} WHERE id=91")
    if legacy or volume%1024!=0 or (volume==0 and day==0):
        q(work,env,f"INSERT INTO gift_list(server_id,volume,day,`offset`) VALUES(2,{volume},{day},0)")
        return int(q(work,env,"SELECT id FROM gift_list ORDER BY id DESC LIMIT 1"))
    process=subprocess.run(
        _php_command(stage,work)[:-1]+[str(stage/'enqueue.php'),str(volume),str(day)],
        cwd=stage,env=_run_env(env,work,work/'gift-enqueue.jsonl',
                               stage/'current-roster.json','verified','active'),
        text=True,capture_output=True,timeout=45)
    require(process.returncode==0 and process.stdout.strip().isdigit() and
            not process.stderr,
            f'New-format atomic enqueue failed: {process.stdout} {process.stderr}')
    return int(process.stdout.strip())

def state(work,env,gift_id):
    offset=q(work,env,f'SELECT `offset` FROM gift_list WHERE id={gift_id}')
    expiry_raw=q(work,env,'SELECT expire_date FROM orders_list WHERE id=91')
    expiry=int(expiry_raw) if expiry_raw else None
    holds=int(q(work,env,f"SELECT COUNT(*) FROM setting WHERE type='ACHBOT_GIFT_HOLD_{gift_id}'"))
    detail=q(work,env,f"SELECT value FROM setting WHERE type='ACHBOT_GIFT_HOLD_{gift_id}' LIMIT 1")
    return (None if offset=='' else int(offset),expiry,holds,
            json.loads(detail) if detail else None)

def member(name,status='active'):
    return {'username':name,'data_limit':2147483648,
            'expire':ORIGINAL_EXPIRY,'status':status}

def seed_second_order(work,env):
    q(work,env,"DELETE FROM orders_list WHERE id=92")
    q(work,env,"INSERT INTO orders_list "
      "SELECT 92,1002,token,transid,fileid,server_id,inbound_id,'bob',"
      "uuid,protocol,expire_date,link,amount,status,date,notif,rahgozar,"
      "agent_bought,autoRenew FROM orders_list WHERE id=91")

def manifest(work,env,gift_id):
    raw=q(work,env,f"SELECT value FROM setting WHERE type='ACHBOT_GIFT_ROSTER_{gift_id}'")
    return json.loads(raw) if raw else None

def effect_names(events):
    return [e['change']['remark'] for e in events if e['kind']=='EDIT']

def parallel_ticks(work,env,stage,base_log,members):
    roster=_roster(stage,members)
    processes=[]
    logs=[base_log.with_name(base_log.name+f'.parallel-{i}') for i in range(2)]
    for log in logs:
        log.unlink(missing_ok=True)
        processes.append(subprocess.Popen(
            _php_command(stage,work),cwd=stage,
            env=_run_env(env,work,log,roster,'parallel','active'),
            stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True))
    events=[]
    for process,log in zip(processes,logs):
        stdout,stderr=process.communicate(timeout=45)
        require(process.returncode==0 and not stdout and not stderr,
                f'Parallel PHP failed: {process.returncode} {stdout[-400:]} {stderr[-800:]}')
        if log.exists():events.extend(json.loads(line) for line in log.read_text().splitlines())
    return events

def exercise_roster_cases(work,env,stage,log,cases):
    first=[member('fixture-user'),member('bob')]
    added=[member('carol'),member('fixture-user'),member('bob')]
    reordered=[member('bob'),member('fixture-user')]
    # A real PHP exit from sendMessage happens immediately AFTER first
    # verified provider edit + local/snapshot commit, but before next member.
    gift=setup_gift(work,env,stage,2048,2)
    seed_second_order(work,env)
    event=run_tick(work,env,stage,log,'interrupt_after_commit',members=first)
    meta=manifest(work,env,gift)
    require(effect_names(event)==['fixture-user'] and state(work,env,gift)[0]==1 and
            meta is not None and meta['cursor']==1 and meta['count']==2,
            'First completed user was not durably frozen before interruption')
    cases.append('r1a_interrupt_after_first_commit_durable_username_cursor')

    event=run_tick(work,env,stage,log,'verified',members=added)
    require(effect_names(event)==['bob'] and state(work,env,gift)[0]==2 and
            manifest(work,env,gift)['cursor']==2 and
            int(q(work,env,'SELECT expire_date FROM orders_list WHERE id=92'))==ORIGINAL_EXPIRY and
            q(work,env,'SELECT notif FROM orders_list WHERE id=92')=='0',
            'Prepending a new user retargeted or repeated a completed account')
    cases.append('r1a_insert_prepend_only_original_pending_username_edited')
    require('carol' not in effect_names(event) and 'fixture-user' not in effect_names(event),
            'New or completed username received an extra gift')
    cases.append('r1a_late_added_user_explicitly_excluded')
    event=run_tick(work,env,stage,log,'verified',
                   members=[member('bob'),member('carol'),member('fixture-user')])
    require(not effect_names(event) and state(work,env,gift)[0] is None and
            manifest(work,env,gift) is None and
            q(work,env,f"SELECT COUNT(*) FROM setting WHERE type='ACHBOT_GIFT_ROSTER_PART_{gift}'")=='0',
            'Next-tick batch completion replayed an account or leaked roster chunks')
    cases.append('r1a_reordered_final_cleanup_no_replay_and_no_orphans')

    gift=setup_gift(work,env,stage,2048,2)
    seed_second_order(work,env)
    event=run_tick(work,env,stage,log,'interrupt_after_commit',members=first)
    require(effect_names(event)==['fixture-user'],'Interrupted first gift did not complete')
    event=run_tick(work,env,stage,log,'verified',
                   members=[member('bob'),member('fixture-user')])
    require(effect_names(event)==['bob'] and state(work,env,gift)[0]==2,
            'Pure reorder changed the recipient identity')
    cases.append('r1a_reorder_without_insertion_exactly_once_per_username')

    gift=setup_gift(work,env,stage,2048,2)
    seed_second_order(work,env)
    run_tick(work,env,stage,log,'interrupt_after_commit',members=first)
    event=run_tick(work,env,stage,log,'verified',members=[member('fixture-user'),
                                                         member('carol')])
    offset,expiry,hold,detail=state(work,env,gift)
    require(not effect_names(event) and offset==-2 and hold==1 and
            detail['recipient_index']==1 and detail['username']=='bob' and
            manifest(work,env,gift)['cursor']==1,
            'Deleted pending member did not preserve original identity in hold')
    cases.append('r1a_deleted_pending_member_holds_without_substitution')
    event=run_tick(work,env,stage,log,'verified',members=first)
    require(not effect_names(event) and state(work,env,gift)[0]==-2,
            'Restoring deleted username automatically replayed held effect')
    cases.append('r1a_deleted_member_return_requires_operator_reconciliation')

    gift=setup_gift(work,env,stage,2048,2)
    seed_second_order(work,env)
    run_tick(work,env,stage,log,'interrupt_after_commit',members=first)
    q(work,env,f'UPDATE gift_list SET `offset`=0 WHERE id={gift}')
    event=run_tick(work,env,stage,log,'verified',members=first)
    require(not effect_names(event) and state(work,env,gift)[0]==-1 and
            manifest(work,env,gift)['cursor']==1,
            'Manual reset of old numeric cursor replayed completed username')
    cases.append('r1a_legacy_cursor_reset_detected_by_independent_roster_progress')

    gift=setup_gift(work,env,stage,2048,2)
    seed_second_order(work,env)
    q(work,env,f'UPDATE gift_list SET `offset`=1 WHERE id={gift}')
    event=run_tick(work,env,stage,log,'verified',members=added)
    offset,_,held,detail=state(work,env,gift)
    require(not effect_names(event) and offset==-2 and held==1 and
            detail['reason']=='roster_snapshot_requires_reconciliation',
            'Existing pre-upgrade positive offset reconstructed unsafe membership')
    cases.append('r1a_legacy_inflight_without_snapshot_fails_closed')

    gift=setup_gift(work,env,stage,2048,2)
    event=run_tick(work,env,stage,log,'verified',
                   members=[member('fixture-user'),member('fixture-user')])
    require(not effect_names(event) and state(work,env,gift)[0]==-1 and
            manifest(work,env,gift) is None,
            'Provider with duplicate usernames was treated as a valid identity map')
    cases.append('r1a_ambiguous_provider_usernames_never_edited')

    gift=setup_gift(work,env,stage,2048,2)
    seed_second_order(work,env)
    run_tick(work,env,stage,log,'interrupt_after_commit',members=first)
    raw=q(work,env,f"SELECT value FROM setting WHERE type='ACHBOT_GIFT_ROSTER_{gift}'")
    data=json.loads(raw);data['chunks'][0]['hash']='0'*64
    damaged=json.dumps(data,ensure_ascii=False).replace("'","''")
    q(work,env,f"UPDATE setting SET value='{damaged}' WHERE type='ACHBOT_GIFT_ROSTER_{gift}'")
    event=run_tick(work,env,stage,log,'verified',members=first)
    require(not effect_names(event) and state(work,env,gift)[0]==-2,
            'Corrupt roster chunk digest did not fail closed')
    cases.append('r1a_corrupt_roster_chunk_digest_no_external_replay')

    gift=setup_gift(work,env,stage,2048,2)
    long_names=[member('u'+str(i).zfill(6)+'x'*70,'disabled') for i in range(650)]
    event=run_tick(work,env,stage,log,'interrupt_after_commit',
                   members=[member('fixture-user')]+long_names)
    meta=manifest(work,env,gift)
    require(effect_names(event)==['fixture-user'] and
            meta is not None and len(meta['chunks'])>=2 and
            meta['count']==651 and meta['cursor']==1 and
            state(work,env,gift)[0]==1,
            'Large provider membership was truncated instead of chunk-frozen')
    parts=q(work,env,
        f"SELECT COUNT(*) FROM setting WHERE type='ACHBOT_GIFT_ROSTER_PART_{gift}'")
    require(int(parts)==len(meta['chunks']),
            'Durable chunk inventory does not match manifest')
    cases.append('r1a_large_roster_bounded_chunks_preserve_all_original_members')

    gift=setup_gift(work,env,stage,2048,2)
    event=parallel_ticks(work,env,stage,log,[member('fixture-user'),
                                            member('inactive-user','disabled')])
    require(effect_names(event).count('fixture-user')==1 and len(effect_names(event))==1 and
            state(work,env,gift)[0]==2 and manifest(work,env,gift)['cursor']==2,
            'Two Cron processes dispatched a duplicate remote increment')
    cases.append('r1a_parallel_cron_workers_one_remote_increment')
    event=run_tick(work,env,stage,log,'verified',members=added)
    require(not effect_names(event) and state(work,env,gift)[0] is None,
            'Finalizing concurrent batch dispatched another increment')
    cases.append('r1a_parallel_completion_lifecycle_consistent')

def main():
    ap=argparse.ArgumentParser()
    ap.add_argument('--run-disposable',action='store_true',required=True)
    ap.add_argument('--report',type=Path)
    args=ap.parse_args()
    cases=[]
    with owned.owned_database() as (work,env):
        native.seed(work,env,'http://127.0.0.1:9')
        # Roster fixture references a second real customer; notices now verify
        # exact persisted recipient identity instead of sending to orphan IDs.
        q(work,env,"INSERT INTO users(userid,name,username,refcode,wallet,date) VALUES(1002,'Second','fixture-second','0',0,'1700000000')")
        # Legacy real worker dereferences server_info.title directly; the
        # original 23-table isolated native.seed fixture only populated
        # server_config and orders_list.
        q(work,env,"INSERT INTO server_info "
          "(id,title,ucount,remark,flag,active,state) "
          "VALUES (2,'Fixture','0','fixture','','1',1) "
          "ON DUPLICATE KEY UPDATE title='Fixture'")
        stage=work/'gift-worker'
        (stage/'settings').mkdir(parents=True)
        shutil.copyfile(ROOT/'settings/gift2all.php',stage/'settings/gift2all.php')
        (stage/'src/Panel').mkdir(parents=True)
        shutil.copyfile(ROOT/'src/Panel/ProviderRuntime.php',stage/'src/Panel/ProviderRuntime.php')
        shutil.copyfile(ROOT/'src/Panel/ProviderImport.php',stage/'src/Panel/ProviderImport.php')
        (stage/'src/Admin').mkdir(parents=True)
        shutil.copyfile(ROOT/'src/Admin/Access.php',stage/'src/Admin/Access.php')
        (stage/'src/Subscription').mkdir(parents=True)
        shutil.copyfile(ROOT/'src/Subscription/GiftBatch.php',
                        stage/'src/Subscription/GiftBatch.php')
        (stage/'src/Purchase').mkdir(parents=True)
        shutil.copyfile(ROOT/'src/Purchase/OrderWrites.php',stage/'src/Purchase/OrderWrites.php')
        shutil.copytree(ROOT/'messages',stage/'messages')
        (stage/'baseInfo.php').write_text('<?php // owned test\n')
        stage_notifications(stage)
        (stage/'config.php').write_text(CONFIG)
        (stage/'run.php').write_text(RUNNER)
        (stage/'enqueue.php').write_text(ENQUEUE)
        original=subprocess.check_output(['git','show',
            '97a91ef5588922bbaabc7c5932b5a80a89c98453:settings/gift2all.php'],
            cwd=ROOT)
        (stage/'settings/legacy-gift2all.php').write_bytes(original)
        (stage/'run-legacy.php').write_text(RUNNER.replace(
            "settings/gift2all.php","settings/legacy-gift2all.php"))
        log=work/'gift-worker-events.jsonl'
        seed_notification_staff(work,env)

        for mode,volume,day,want_offset,want_hold,want_expiry in [
            ('verified',2048,2,2,0,ORIGINAL_EXPIRY),
            ('refused',2048,2,2,0,ORIGINAL_EXPIRY),
            ('uncertain',2048,2,-1,1,ORIGINAL_EXPIRY),
            ('throw',2048,2,-1,1,ORIGINAL_EXPIRY),
            ('unverified',2048,2,-1,1,ORIGINAL_EXPIRY),
            ('bad_expire',2048,2,-1,1,ORIGINAL_EXPIRY),
            ('verified',0,2,2,0,ORIGINAL_EXPIRY),
            ('verified',2048,0,2,0,ORIGINAL_EXPIRY),
            ('notify_fail',2048,2,2,0,ORIGINAL_EXPIRY),
            ('verified',1536,2,-1,1,ORIGINAL_EXPIRY),
            ('verified',0,0,-1,1,ORIGINAL_EXPIRY),
        ]:
            gift_id=setup_gift(work,env,stage,volume,day)
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
                require(notices and holds==0 and offset==2 and
                        any('ارسال اعلان کاربر ناموفق بود' in item['text'] for item in notices),
                        'Postcommit Telegram exception changed gift truth or lost its specific diagnostic')
                result=json.loads(q(work,env,"SELECT value FROM setting WHERE type='ACHBOT_GIFT_RESULT_"+str(gift_id)+"_0'"))
                require(result['customer_notices']['send_exceptions']==[1001] and
                        result['customer_notices']['unconfirmed']==[1001],
                        'Customer transport exception was not retained separately from unconfirmed ACK')
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
            if mode=='notify_fail':
                # The same failure contract distinguishes a returned negative
                # ACK from an actual thrown send, without repeating native work.
                ack_id=setup_gift(work,env,stage,2048,2)
                ack_events=run_tick(work,env,stage,log,'notify_unacknowledged')
                ack_result=json.loads(q(work,env,"SELECT value FROM setting WHERE type='ACHBOT_GIFT_RESULT_"+str(ack_id)+"_0'"))
                ack_notices=[item for item in ack_events if item['kind']=='MESSAGE' and item['recipient']==9001 and 'اعلان' in item['text']]
                require(len(effect_names(ack_events))==1 and state(work,env,ack_id)[0]==2 and
                        ack_result['customer_notices']['send_exceptions']==[] and
                        ack_result['customer_notices']['unconfirmed']==[1001] and
                        any('تأیید' in item['text'] for item in ack_notices) and
                        not any('ارسال اعلان کاربر ناموفق بود' in item['text'] for item in ack_notices),
                        'Returned unconfirmed ACK was mislabeled as a transport exception')
                ack_replay=run_tick(work,env,stage,log,'verified')
                require(not effect_names(ack_replay) and state(work,env,ack_id)[0] is None,
                        'Unconfirmed customer ACK rearmed the completed native gift')
            cases.append(f'gift_{mode}_volume_{volume}_days_{day}')

        gift_id=setup_gift(work,env,stage,2048,2)
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

        gift_id=setup_gift(work,env,stage,2048,2)
        events=run_tick(work,env,stage,log,'verified',status='disabled')
        require(not [x for x in events if x['kind']=='EDIT'],'Inactive user edited')
        require(state(work,env,gift_id)[0]==2,'Inactive user was not advanced without effect')
        cases.append('inactive_recipient_never_inherits_previous_success_or_edit')

        exercise_roster_cases(work,env,stage,log,cases)

        # Execute the real inherited main worker. The synthetic provider
        # applies an effect and exits inside editMarzbanConfig before the old
        # offset can be incremented; the new worker must not repeat it.
        gift_id=setup_gift(work,env,stage,2048,2,legacy=True)
        old_events=run_tick(work,env,stage,log,'legacy_old_worker',old=True)
        require(effect_names(old_events)==['fixture-user'] and
                state(work,env,gift_id)[0]==0,
                'Original legacy Cron did not reproduce zero-offset effect')
        events=run_tick(work,env,stage,log,'verified')
        offset,_,held,detail=state(work,env,gift_id)
        require(not effect_names(events) and offset==-1 and held==1 and
                detail['reason']=='legacy_batch_missing_enqueued_provenance',
                'Old remote-applied zero-offset job was replayed on upgrade')
        cases.append('r1a1_original_main_worker_exited_after_effect_zero_offset_no_replay')

        gift_id=setup_gift(work,env,stage,2048,2,legacy=True)
        events=run_tick(work,env,stage,log,'verified')
        require(not effect_names(events) and state(work,env,gift_id)[0]==-1,
                'Unmarked zero-offset batch automatically started')
        cases.append('r1a1_unmarked_untouched_zero_offset_requires_operator')

        gift_id=setup_gift(work,env,stage,2048,2)
        raw=q(work,env,f"SELECT value FROM setting WHERE type='ACHBOT_GIFT_ENQUEUE_{gift_id}'")
        require(json.loads(raw)['version']==2 and
                json.loads(raw)['gift_id']==gift_id and
                'password' not in raw,
                'Real enqueue did not create identity without leaked secrets')
        events=run_tick(work,env,stage,log,'verified')
        require(effect_names(events)==['fixture-user'],
                'New-format versioned zero-offset batch failed to start')
        cases.append('r1a1_new_format_enqueue_identity_allows_first_gift')

        # A failed provenance INSERT must roll back the queue INSERT.
        # This is the actual PHP transaction, not a mock journal.
        q(work,env,"DELETE FROM gift_list")
        q(work,env,"DELETE FROM setting WHERE type LIKE 'ACHBOT_GIFT_%'")
        q(work,env,"CREATE TRIGGER gift_provenance_insert_failure "
          "BEFORE INSERT ON setting FOR EACH ROW "
          "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture_provenance_error'")
        try:
            process=subprocess.run(
                _php_command(stage,work)[:-1]+[
                    str(stage/'enqueue.php'),'1024','0'],cwd=stage,
                env=_run_env(env,work,log,stage/'current-roster.json','verified','active'),
                text=True,capture_output=True,timeout=45)
        finally:
            q(work,env,'DROP TRIGGER gift_provenance_insert_failure')
        require(process.returncode!=0 and
                q(work,env,'SELECT COUNT(*) FROM gift_list')=='0' and
                q(work,env,"SELECT COUNT(*) FROM setting "
                  "WHERE type LIKE 'ACHBOT_GIFT_ENQUEUE_%'")=='0',
                'Broken provenance insertion persisted a gift queue row')
        cases.append('r1a1_provenance_sql_failure_atomically_rolls_back_enqueue')

        gift_id=setup_gift(work,env,stage,2048,2)
        q(work,env,f"UPDATE setting SET value='malformed' "
          f"WHERE type='ACHBOT_GIFT_ENQUEUE_{gift_id}'")
        events=run_tick(work,env,stage,log,'verified')
        require(not effect_names(events) and state(work,env,gift_id)[0]==-1,
                'Malformed receipt allowed unverified new-format batch')
        cases.append('r1a1_corrupt_provenance_never_authorizes_provider_edit')

        gift_id=setup_gift(work,env,stage,2048,2)
        q(work,env,f"DELETE FROM setting WHERE type='ACHBOT_GIFT_ENQUEUE_{gift_id}'")
        events=run_tick(work,env,stage,log,'verified')
        require(not effect_names(events) and state(work,env,gift_id)[0]==-1,
                'Absent enqueue identity permitted first remote edit')
        cases.append('r1a1_deleted_provenance_cannot_rearm')

        gift_id=setup_gift(work,env,stage,2048,2)
        q(work,env,"UPDATE server_config SET panel_url='http://127.0.0.1:10' WHERE id=2")
        events=run_tick(work,env,stage,log,'verified')
        offset,_,held,detail=state(work,env,gift_id)
        require(not effect_names(events) and offset==-1 and held==1 and
                detail['reason']=='gift_physical_target_changed',
                'Changed panel before first list did not hold')
        q(work,env,"UPDATE server_config SET panel_url='http://127.0.0.1:9' WHERE id=2")
        cases.append('r1a2_drift_before_initial_list_no_remote_effect')
        events=run_tick(work,env,stage,log,'verified')
        require(not effect_names(events) and state(work,env,gift_id)[0]==-1,
                'Original panel restored and unobservedly restarted held batch')
        cases.append('r1a2_restoring_panel_does_not_auto_rearm')

        gift_id=setup_gift(work,env,stage,2048,2)
        events=run_tick(work,env,stage,log,'switch_after_list')
        offset,_,held,detail=state(work,env,gift_id)
        require(not effect_names(events) and offset==-1 and held==1 and
                detail['reason']=='gift_physical_target_changed_after_list',
                'Changed physical identity after list was allowed to edit')
        q(work,env,"UPDATE server_config SET panel_url='http://127.0.0.1:9' WHERE id=2")
        cases.append('r1a2_change_between_list_and_edit_fails_closed')

        gift_id=setup_gift(work,env,stage,2048,2)
        q(work,env,"UPDATE server_config SET password='changed-secret' WHERE id=2")
        events=run_tick(work,env,stage,log,'verified')
        require(not effect_names(events) and state(work,env,gift_id)[0]==-1,
                'Credential change before list was not held')
        q(work,env,"UPDATE server_config SET password='fixture-password' WHERE id=2")
        cases.append('r1a2_changed_credential_before_list_no_edit')

        # The shared, real enqueue path is called by bot.php, not legacy
        # direct SQL. Keep this wiring under source-native acceptance.
        bot=(ROOT/'bot.php').read_text()
        require('achbotGiftNewBatch(' in bot and
                'achbotGiftTelegramSource(' in bot and
                'achbotGiftDismissEnqueueStep(' not in bot and
                'INSERT INTO `gift_list`' not in bot,
                'New-format Telegram batch enqueue bypasses its transaction')
        cases.append('r1a1_bot_admin_enqueue_uses_durable_provenance_path')

        for mode in ('missing_total','invalid_total_string',
                     'invalid_total_negative','invalid_total_float','empty_bad_total'):
            gift_id=setup_gift(work,env,stage,2048,2)
            events=run_tick(work,env,stage,log,mode,
                            members=[member('fixture-user')])
            require(not effect_names(events) and state(work,env,gift_id)[0]==0 and
                    manifest(work,env,gift_id) is None,
                    f'Malformed provider total {mode} permitted snapshot')
            cases.append('r1a3_'+mode+'_no_roster_no_false_completion')

        gift_id=setup_gift(work,env,stage,2048,2)
        events=run_tick(work,env,stage,log,'partial_page',
                        members=[member('fixture-user')])
        require(not effect_names(events) and state(work,env,gift_id)[0]==0 and
                manifest(work,env,gift_id) is None,
                'Provider total/list mismatch established an unsafe snapshot')
        cases.append('r1a_incomplete_provider_list_never_frozen')

        gift_id=setup_gift(work,env,stage,2048,2)
        q(work,env,'ALTER TABLE gift_list ENGINE=MyISAM')
        try:
            events=run_tick(work,env,stage,log,'verified')
            require(not effect_names(events) and state(work,env,gift_id)[0]==0,
                    'Nontransactional gift table permitted a provider call')
        finally:
            q(work,env,'ALTER TABLE gift_list ENGINE=InnoDB')
        cases.append('r1a_nontransactional_installed_table_fails_closed')

        gift_id=setup_gift(work,env,stage,2048,2)
        event=run_tick(work,env,stage,log,'verified')
        groups=assert_private_fanout(event)
        summaries=[e for e in event if e['kind']=='MESSAGE' and e['text'].startswith('🎁 هدیهٔ اشتراک اعمال شد')]
        require(sorted(str(e['recipient']) for e in summaries)==sorted(map(str,[9001,9101,'@fixture-gift-private-channel']))
                and all(e['parse_mode']=='HTML' for e in summaries),
                'Verified physical gift summary did not reach exact general report destinations once')
        require(len(groups)==1 and effect_names(event)==['fixture-user'] and
                state(work,env,gift_id)[0]==2,
                'Start fanout changed committed gift effect/cursor')
        end=run_tick(work,env,stage,log,'verified')
        require(len(assert_private_fanout(end))==1 and not effect_names(end) and
                state(work,env,gift_id)[0] is None,
                'Completion fanout changed cleanup or repeated effect')
        require(not run_tick(work,env,stage,log,'verified'),
                'Empty queue repeated a completion notice')
        cases.append('gift_private_progress_and_verified_general_summary_destinations')

        gift_id=setup_gift(work,env,stage,2048,2)
        event=run_tick(work,env,stage,log,'uncertain')
        require(len(assert_private_fanout(event))==2 and state(work,env,gift_id)[0]==-1 and
                len(effect_names(event))==1,
                'Uncertain gift hold/fanout lost the exact first mutation fence')
        require(not run_tick(work,env,stage,log,'verified'),
                'Held gift repeated a notification or provider edit')
        cases.append('gift_private_hold_owner_manager_no_replay')

        q(work,env,"INSERT INTO setting(type,value) VALUES('ADMIN_NOTIFY_9101','off')")
        try:
            gift_id=setup_gift(work,env,stage,2048,2)
            event=run_tick(work,env,stage,log,'uncertain')
            require(len(assert_private_fanout(event,(9001,)))==2 and
                    state(work,env,gift_id)[0]==-1,
                    'Explicit Manager receiving-off changed hold or leaked a copy')
        finally:q(work,env,"DELETE FROM setting WHERE type='ADMIN_NOTIFY_9101'")
        cases.append('gift_private_hold_manager_receiving_off_owner_only')

        gift_id=setup_gift(work,env,stage,2048,2)
        event=run_tick(work,env,stage,log,'manager_notice_fail')
        require(len(assert_private_fanout(event))==1 and effect_names(event)==['fixture-user'] and
                state(work,env,gift_id)[0]==2,
                'Manager transport failure changed successful gift or cursor')
        end=run_tick(work,env,stage,log,'manager_notice_fail')
        require(not effect_names(end) and state(work,env,gift_id)[0] is None,
                'Manager notification failure replayed the native effect')
        cases.append('gift_private_manager_notice_failure_preserves_effect_cursor_and_cleanup')

        gift_id=setup_gift(work,env,stage,2048,0)
        events=run_tick(work,env,stage,log,'order_vanish_after_provider')
        offset,_,hold,detail=state(work,env,gift_id)
        require(effect_names(events)==['fixture-user'] and offset==-1 and hold==1 and
                not [x for x in events if x['kind']=='MESSAGE' and
                     x['recipient']==1001] and
                detail['username']=='fixture-user',
                'Remote volume-only edit with deleted local order falsely completed')
        cases.append('r1a_volume_only_local_identity_lost_after_provider_held')
    result={'complete':True,'cases':len(cases),'scenarios':cases,
        'actual_gift_worker':True,'owned_mariadb_only':True,
        'real_provider_requests':0,'customer_money_effects':0,
        'actual_notification_router':True,'actual_admin_access':True,
        'private_owner_manager_gift_notices':True,'operator_support_gift_excluded':True}
    if args.report:args.report.write_text(json.dumps(result,ensure_ascii=False,indent=2)+'\n')
    print(json.dumps(result,ensure_ascii=False))
if __name__=='__main__':main()
