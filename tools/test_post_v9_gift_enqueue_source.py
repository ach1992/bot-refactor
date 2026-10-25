#!/usr/bin/env python3
"""Real source: bot.php bulk-gift route + Telegram bindings + MariaDB source claim.

Owned original-schema socket MariaDB, synthetic Telegram/Marzban only.
No actual service, provider, payment, tenant or deployment changes.
"""
from __future__ import annotations
import argparse
import concurrent.futures
import json
import subprocess
import time
from pathlib import Path

import test_access_state as owned
import test_v6_native as native
import test_post_v9_gift_worker as gift

ROOT=Path(__file__).resolve().parents[1]
DB=native.DBS[1]

PHP=r'''<?php
if (PHP_SAPI!=='cli') exit(3);
error_reporting(E_ALL & ~E_DEPRECATED);
set_error_handler(static function($n,$m){throw new ErrorException($m,0,$n);});
$spec=json_decode($argv[1],true,512,JSON_THROW_ON_ERROR);
class GiftSourceMysqli extends mysqli {
    public function commit(int $flags=0,?string $name=null): bool {
        $ok=parent::commit($flags,$name);
        $mode=$GLOBALS['spec']['mode']??'';
        if ($ok && !($GLOBALS['once']??false) &&
            $mode==='pause_after_commit') {
            $GLOBALS['once']=true;
            $path=(string)$GLOBALS['spec']['commit_path'];
            file_put_contents($path.'.committed','1');
            $until=microtime(true)+16;
            while (!is_file($path.'.release') && microtime(true)<$until)
                usleep(10000);
            if (!is_file($path.'.release'))
                throw new RuntimeException('owned_commit_pause_timeout');
        }
        if ($ok && !($GLOBALS['once']??false) &&
            $mode==='newer_step_after_commit') {
            $GLOBALS['once']=true;
            if (!$this->query("UPDATE users SET step='giftServerDay2' WHERE userid=9001"))
                throw new RuntimeException('owned_newer_step_failed');
        }
        if ($ok && !($GLOBALS['once']??false) &&
            in_array($mode,['kill_after_commit','uncertain_commit'],true)) {
            $GLOBALS['once']=true;
            if ($mode==='kill_after_commit') exit(0);
            throw new RuntimeException('synthetic_lost_commit_ack');
        }
        return $ok;
    }
}
$connection=new GiftSourceMysqli('localhost','root','',
    getenv('ACHBOT_SOURCE_DB'),0,getenv('ACHBOT_SOURCE_SOCKET'));
$connection->set_charset('utf8mb4');
$admin=9001;
$actor=(int)($spec['actor']??9001);
$wireActor=(int)($spec['wire_actor']??$actor);
$wireChat=(int)($spec['wire_chat']??$wireActor);
$wireMessage=$spec['wire_message']??31;
$wireUpdate=$spec['wire_update']??501;
$wireText=(string)($spec['wire_text']??'1024');
$kind=(string)($spec['kind']??'message');
$sender=(object)['id'=>$wireActor,'is_bot'=>($spec['is_bot']??false),
                 'first_name'=>'Synthetic','username'=>'fixture'];
$chat=(object)['id'=>$wireChat,'type'=>($spec['chat_type']??'private')];
$message=(object)['from'=>$sender,'chat'=>$chat,
                  'message_id'=>$wireMessage,'text'=>$wireText];
$update=(object)['update_id'=>$wireUpdate];
if ($kind==='message')$update->message=$message;
elseif ($kind==='callback' || $kind==='mixed') {
    $message->reply_markup=(object)['inline_keyboard'=>[]];
    $update->callback_query=(object)[
        'id'=>'fixture','data'=>'old-button','from'=>$sender,'message'=>$message
    ];
    if ($kind==='mixed')$update->message=$message;
} elseif ($kind==='edited')$update->edited_message=$message;
elseif ($kind==='channel')$update->channel_post=$message;
elseif ($kind==='missing_update') {
    unset($update->update_id);$update->message=$message;
} else throw new RuntimeException('fixture_update_kind_unknown');
$from_id=$actor;$chat_id=$actor;$message_id=31;
$text=null;$data=null;$first_name='Fixture';$username='fixture';
require __DIR__.'/src/Telegram/UpdateBindings.php';
if (isset($spec['bound_actor']))$from_id=(int)$spec['bound_actor'];
if (isset($spec['bound_chat']))$chat_id=(int)$spec['bound_chat'];
if (isset($spec['bound_message']))$message_id=(int)$spec['bound_message'];
if (array_key_exists('bound_text',$spec))$text=$spec['bound_text'];
$stmt=$connection->prepare('SELECT id,userid,isAdmin,step FROM users WHERE userid=?');
$stmt->bind_param('i',$from_id);$stmt->execute();
$userInfo=$stmt->get_result()->fetch_assoc()?:['step'=>'none','isAdmin'=>0];
$stmt->close();
if (!empty($spec['stale_admin']))$userInfo['isAdmin']=1;
if (isset($spec['stale_step']))$userInfo['step']=(string)$spec['stale_step'];
$mainValues=['saved_successfuly'=>'SAVED','reached_main_menu'=>'MAIN',
             'send_only_number'=>'ONLY_NUMBER'];
$buttonValues=['cancel'=>'CANCEL'];$removeKeyboard='REMOVE';
$GLOBALS['events']=[];
function sendMessage(...$args) {
    $GLOBALS['events'][]=(string)($args[0]??'');
    if (($GLOBALS['spec']['mode']??'')==='notice_fail')
        throw new RuntimeException('synthetic_telegram_failure');
}
function getMainKeys(){return 'MAIN_KEYS';}
if (isset($spec['barrier'])) {
    $path=(string)$spec['barrier'];
    file_put_contents($path.'.ready','1');
    $until=microtime(true)+18;
    while (!is_file($path.'.release') && microtime(true)<$until)usleep(10000);
    if (!is_file($path.'.release'))
        throw new RuntimeException('owned_barrier_timeout');
}
require __DIR__.'/gift-route.php';
echo json_encode(['events'=>$GLOBALS['events']],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
'''

def require(ok,msg):
    if not ok:raise RuntimeError(msg)

def q(work,env,query):
    return native.sql(work,env,query,DB).strip()

def prepare(work,env):
    native.seed(work,env,'http://127.0.0.1:9')
    q(work,env,"INSERT INTO server_info (id,title,ucount,remark,flag,active,state) "
               "VALUES(2,'Fixture','0','fixture','','1',1) "
               "ON DUPLICATE KEY UPDATE title='Fixture'")
    stage=work/'owned-gift-enqueue'
    for file in ['src/Subscription/GiftBatch.php','src/Telegram/UpdateBindings.php']:
        dst=stage/file;dst.parent.mkdir(parents=True,exist_ok=True)
        dst.write_bytes((ROOT/file).read_bytes())
    bot=(ROOT/'bot.php').read_text()
    start=bot.index("if(preg_match('/^giftServerVolume")
    end=bot.index("\nelse {\n    require __DIR__ . '/src/Subscription/AccountDeletion.php';",start)
    route=bot[start:end]
    require(route.count('achbotGiftNewBatch(')==1 and
            'achbotGiftTelegramSource(' in route and
            'achbotGiftDismissEnqueueStep(' not in route,
            'Source route has unsafe post-COMMIT dismissal or is incomplete')
    (stage/'gift-route.php').write_text('<?php\n'+route+'\n')
    (stage/'driver.php').write_text(PHP)
    worker=work/'owned-gift-worker'
    for file in ['src/Subscription/GiftBatch.php','settings/gift2all.php']:
        dst=worker/file;dst.parent.mkdir(parents=True,exist_ok=True)
        dst.write_bytes((ROOT/file).read_bytes())
    (worker/'config.php').write_text(gift.CONFIG)
    (worker/'baseInfo.php').write_text('<?php // disposable\n')
    (worker/'run.php').write_text(gift.RUNNER)
    return stage,worker

def call(work,env,stage,**spec):
    p=subprocess.run([
        'php','-n','-d','extension=mysqlnd','-d','extension=mysqli',
        '-d','display_errors=1','-d',
        'disable_functions=exec,shell_exec,system,passthru,popen,proc_open,mail',
        str(stage/'driver.php'),json.dumps(spec)],
        cwd=stage,
        env={**env,'ACHBOT_SOURCE_DB':DB,'ACHBOT_SOURCE_SOCKET':str(work/'mysql.sock')},
        text=True,capture_output=True,timeout=36)
    expected_log=('gift_enqueue_telegram_notice_unconfirmed'
                  if spec.get('mode')=='notice_fail' else '')
    require(p.returncode==0 and p.stderr.strip()==expected_log,
            f'Actual PHP route failed: {p.returncode} {p.stdout[-700:]} {p.stderr[-900:]}')
    if spec.get('mode')=='kill_after_commit':
        require(p.stdout=='','Lost-COMMIT fixture unexpectedly reached UI')
        return {'interrupted':True}
    return json.loads(p.stdout)

def reset(w,e):
    q(w,e,"DELETE FROM setting WHERE type LIKE 'ACHBOT_GIFT_%'")
    q(w,e,"DELETE FROM gift_list")
    q(w,e,"UPDATE users SET step='none' WHERE userid IN (9001,9002,1001)")
    q(w,e,"UPDATE users SET isAdmin=1 WHERE userid=9002")
    q(w,e,"UPDATE users SET isAdmin=0 WHERE userid=1001")
    q(w,e,"UPDATE server_config SET panel_url='http://127.0.0.1:9',"
      "username='fixture-admin',password='fixture-password',type='marzban' WHERE id=2")
    q(w,e,f"UPDATE orders_list SET expire_date={gift.ORIGINAL_EXPIRY},"
      "server_id=2,inbound_id=0,remark='fixture-user' WHERE id=91")

def ready(w,e,actor=9001):
    q(w,e,f"UPDATE users SET step='giftServerVolume2_0' WHERE userid={actor}")

def ids(w,e):
    out=q(w,e,'SELECT id FROM gift_list ORDER BY id')
    return [int(x) for x in out.split()] if out else []

def claims(w,e):
    return int(q(w,e,"SELECT COUNT(*) FROM setting WHERE type LIKE 'ACHBOT_GIFT_SOURCE_%'"))

def claim(w,e):
    raw=q(w,e,"SELECT value FROM setting WHERE type LIKE 'ACHBOT_GIFT_SOURCE_%' LIMIT 1")
    return json.loads(raw) if raw else None

def receipt(w,e,i):
    raw=q(w,e,f"SELECT value FROM setting WHERE type='ACHBOT_GIFT_ENQUEUE_{i}'")
    return json.loads(raw) if raw else None

def worker_tick(w,e,stage,label):
    log=w/('gift-source-worker-'+label+'.jsonl')
    roster=gift._roster(stage,[gift.member('fixture-user')])
    process=subprocess.run(['php','-n','-d','extension=mysqlnd',
        '-d','extension=mysqli',str(stage/'run.php')],cwd=stage,
        env=gift._run_env(e,w,log,roster,'verified','active'),
        capture_output=True,text=True,timeout=40)
    require(process.returncode==0 and not process.stderr and not process.stdout,
            f'Actual Cron failed: {process.returncode} {process.stdout} {process.stderr}')
    return [json.loads(x) for x in log.read_text().splitlines()] if log.exists() else []

def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('--run-disposable',action='store_true',required=True)
    parser.add_argument('--report',type=Path)
    args=parser.parse_args()
    cases=[]
    with owned.owned_database() as (w,e):
        stage,worker=prepare(w,e)
        reset(w,e);ready(w,e)
        first=call(w,e,stage)
        one=ids(w,e)
        require(len(one)==claims(w,e)==1 and receipt(w,e,one[0]) is not None and
                claim(w,e)['gift_id']==one[0] and
                claim(w,e)['source']['update_id']==501 and
                'password' not in json.dumps(claim(w,e)) and
                q(w,e,'SELECT step FROM users WHERE userid=9001')=='none' and
                'SAVED' in first['events'],
                'First valid Telegram message failed atomic job/claim/provenance')
        cases.append('f1_first_real_bot_message_one_job_claim_and_receipt')

        ready(w,e)
        repeat=call(w,e,stage)
        require(ids(w,e)==one and claims(w,e)==1 and
                any('تکراری' in x for x in repeat['events']),
                'Same update replayed another job or failed existing acknowledgment')
        cases.append('f1_replayed_identical_update_one_existing_batch')

        reset(w,e);ready(w,e)
        tags=('parallel-a','parallel-b')
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
            fs=[pool.submit(call,w,e,stage,wire_update=600,wire_message=41,
                 barrier=str(stage/tag)) for tag in tags]
            deadline=time.monotonic()+15
            while time.monotonic()<deadline:
                if all((stage/(tag+'.ready')).exists() for tag in tags):break
                time.sleep(.02)
            require(all((stage/(tag+'.ready')).exists() for tag in tags),
                    'Two distinct PHP sessions did not reach race barrier')
            for tag in tags:(stage/(tag+'.release')).write_text('go')
            events=[f.result(timeout=44)['events'] for f in fs]
        all_events=[m for result in events for m in result]
        require(len(ids(w,e))==claims(w,e)==1 and
                all_events.count('SAVED')==1 and
                sum('تکراری' in x for x in all_events)==1,
                f'Concurrent source duplicated batch: {all_events}')
        cases.append('f1_two_actual_php_mysql_sessions_concurrent_one_source')

        reset(w,e);ready(w,e)
        call(w,e,stage,mode='kill_after_commit')
        committed=ids(w,e)
        require(len(committed)==claims(w,e)==1 and
                q(w,e,'SELECT step FROM users WHERE userid=9001')=='none',
                'Process crash left final step unconsumed after committed enqueue')
        call(w,e,stage)
        require(ids(w,e)==committed and claims(w,e)==1,
                'Identical update after post-COMMIT crash duplicated job')
        cases.append('f1_crash_after_commit_before_telegram_notice_no_replay')
        require(receipt(w,e,committed[0]) is not None and
                q(w,e,'SELECT step FROM users WHERE userid=9001')=='none',
                'Crash preserved job/claim without atomic consumed step')
        cases.append('f2_postcommit_crash_preserves_job_claim_receipt_consumed_step')

        reset(w,e);ready(w,e)
        q(w,e,"ALTER TABLE setting ADD CONSTRAINT owned_claim_denied "
              "CHECK (type NOT LIKE 'ACHBOT_GIFT_SOURCE_%')")
        try:
            result=call(w,e,stage)
            require(not ids(w,e) and claims(w,e)==0 and
                    'SAVED' not in result['events'],
                    'Failed source-claim INSERT persisted partial job/receipt')
        finally:q(w,e,'ALTER TABLE setting DROP CONSTRAINT owned_claim_denied')
        cases.append('f1_source_claim_sql_failure_rolls_back_queue_and_receipt')

        reset(w,e);ready(w,e)
        q(w,e,"CREATE TRIGGER owned_receipt_denied "
              "BEFORE INSERT ON setting FOR EACH ROW "
              "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='owned_receipt_denied'")
        try:
            call(w,e,stage)
            require(not ids(w,e) and claims(w,e)==0,
                    'Failed provenance INSERT left partial source/queue state')
        finally:q(w,e,'DROP TRIGGER owned_receipt_denied')
        cases.append('f1_receipt_sql_failure_rolls_back_new_queue')

        reset(w,e);ready(w,e)
        unknown=call(w,e,stage,mode='uncertain_commit')
        committed=ids(w,e)
        require(len(committed)==claims(w,e)==1 and 'SAVED' not in unknown['events'] and
                q(w,e,'SELECT step FROM users WHERE userid=9001')=='none',
                'Unknown COMMIT ACK left source claim and step inconsistent')
        call(w,e,stage)
        require(ids(w,e)==committed and claims(w,e)==1,
                'Ambiguous COMMIT then retry inserted a second batch')
        cases.append('f1_uncertain_commit_ack_recovered_by_source_claim')
        require(receipt(w,e,committed[0]) is not None and
                q(w,e,'SELECT step FROM users WHERE userid=9001')=='none',
                'Uncertain COMMIT produced inconsistent actor step/provenance')
        cases.append('f2_uncertain_commit_preserves_step_claim_consistency')

        reset(w,e);ready(w,e)
        call(w,e,stage)
        completed_id=ids(w,e)[0]
        event=worker_tick(w,e,worker,'first')
        require(gift.effect_names(event)==['fixture-user'],
                'Original sourced job could not be executed by real Cron')
        worker_tick(w,e,worker,'cleanup')
        require(not ids(w,e) and claims(w,e)==1 and
                receipt(w,e,completed_id) is not None,
                'Completed Cron changed durable enqueue/source evidence')
        ready(w,e)
        replay=call(w,e,stage)
        require(not ids(w,e) and claims(w,e)==1 and
                any('تکراری' in x for x in replay['events']),
                'Retry of completed source created second job')
        cases.append('f1_source_claim_survives_completed_cron_and_blocks_reenqueue')
        require(q(w,e,'SELECT step FROM users WHERE userid=9001')==
                    'giftServerVolume2_0',
                'Late replay after completed Cron erased a new same-literal step')
        cases.append('f2_completed_cron_late_duplicate_preserves_rearmed_step')

        reset(w,e);ready(w,e)
        call(w,e,stage,wire_update=710,wire_message=51)
        ready(w,e)
        call(w,e,stage,wire_update=711,wire_message=52)
        require(len(ids(w,e))==claims(w,e)==2,
                'Two genuinely distinct authenticated updates coalesced')
        cases.append('f1_distinct_authorized_messages_create_distinct_batches')

        reset(w,e);ready(w,e)
        call(w,e,stage,wire_update=800,wire_message=56)
        ready(w,e,9002)
        other=call(w,e,stage,actor=9002,wire_update=800,wire_message=56)
        require(len(ids(w,e))==claims(w,e)==1 and
                'SAVED' not in other['events'],
                'Delegated actor appropriated original Telegram update ID')
        cases.append('f1_different_actor_same_global_update_id_conflicts')

        reset(w,e);ready(w,e);ready(w,e,9002)
        cases_before=claims(w,e)
        tags=('cross-a','cross-b')
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
            fs=[pool.submit(call,w,e,stage,actor=actor,wire_update=801,
                            wire_message=57,barrier=str(stage/tag))
                for actor,tag in zip((9001,9002),tags)]
            deadline=time.monotonic()+15
            while time.monotonic()<deadline:
                if all((stage/(tag+'.ready')).exists() for tag in tags):break
                time.sleep(.02)
            require(all((stage/(tag+'.ready')).exists() for tag in tags),
                    'Cross-actor PHP processes did not reach shared barrier')
            for tag in tags:(stage/(tag+'.release')).write_text('go')
            outcomes=[f.result(timeout=44) for f in fs]
        require(len(ids(w,e))==1 and claims(w,e)==cases_before+1 and
                sum('SAVED' in x['events'] for x in outcomes)==1,
                'Same globally unique update committed by two actors')
        cases.append('f1_two_concurrent_actors_same_update_id_one_global_claim')

        reset(w,e);ready(w,e)
        call(w,e,stage,mode='newer_step_after_commit',wire_update=802)
        require(len(ids(w,e))==1 and
                q(w,e,'SELECT step FROM users WHERE userid=9001')==
                    'giftServerDay2',
                'Postcommit presentation CAS erased a newer admin step')
        cases.append('f1_postcommit_ui_does_not_erase_newer_admin_step')

        reset(w,e);ready(w,e)
        call(w,e,stage,wire_update=803,wire_message=58)
        q(w,e,"UPDATE setting SET value='corrupt' "
          "WHERE type LIKE 'ACHBOT_GIFT_SOURCE_%'")
        ready(w,e)
        call(w,e,stage,wire_update=803,wire_message=58)
        require(len(ids(w,e))==claims(w,e)==1,
                'Malformed source claim accepted as an unused message')
        cases.append('f1_corrupted_existing_source_claim_fails_closed')

        reset(w,e)
        malformed=[
            ('bound_actor',{'bound_actor':9002}),
            ('bound_chat',{'bound_chat':9002}),
            ('bound_message',{'bound_message':99}),
            ('bound_text',{'bound_text':'1025'}),
            ('callback',{'kind':'callback'}),
            ('mixed',{'kind':'mixed'}),
            ('edited',{'kind':'edited'}),
            ('channel_post',{'kind':'channel'}),
            ('missing_update',{'kind':'missing_update'}),
            ('string_update',{'wire_update':'501'}),
            ('negative_update',{'wire_update':-1}),
            ('group_chat',{'chat_type':'group'}),
            ('other_chat',{'wire_chat':9002}),
            ('bot_sender',{'is_bot':True}),
            ('zero_message_id',{'wire_message':0}),
        ]
        for name,spec in malformed:
            ready(w,e)
            call(w,e,stage,**spec)
            require(not ids(w,e) and claims(w,e)==0,
                    'Malformed/forged update accepted: '+name)
            cases.append('f1_invalid_telegram_envelope_'+name)

        reset(w,e);ready(w,e,1001)
        call(w,e,stage,actor=1001)
        require(not ids(w,e),'Ordinary actor gained gift authority')
        cases.append('f1_nonadmin_source_denied')

        reset(w,e);ready(w,e,9002)
        call(w,e,stage,actor=9002)
        require(len(ids(w,e))==claims(w,e)==1,
                'Valid delegated admin source rejected')
        cases.append('f1_delegated_admin_fresh_private_update_allowed')

        reset(w,e);ready(w,e,9002)
        q(w,e,'UPDATE users SET isAdmin=0 WHERE userid=9002')
        call(w,e,stage,actor=9002,stale_admin=True)
        require(not ids(w,e),'Revoked cached admin created job')
        cases.append('f1_fresh_actor_role_rechecked_under_lock')

        reset(w,e);ready(w,e)
        q(w,e,"UPDATE users SET step='none' WHERE userid=9001")
        call(w,e,stage,stale_step='giftServerVolume2_0')
        require(not ids(w,e),'Stale pre-read step created job')
        cases.append('f1_fresh_actor_step_rechecked_under_lock')

        reset(w,e);ready(w,e)
        q(w,e,"INSERT INTO users (userid,name,username,refcode,date,isAdmin,step) "
              "VALUES(9001,'Owned Duplicate','duplicate','0','0',1,'giftServerVolume2_0')")
        try:
            call(w,e,stage)
            require(not ids(w,e),'Non-unique users.userid treated as safe source')
        finally:q(w,e,"DELETE FROM users WHERE username='duplicate' AND userid=9001")
        cases.append('f1_duplicate_actor_userid_fails_closed')

        reset(w,e);ready(w,e)
        call(w,e,stage)
        q(w,e,"INSERT INTO setting(type,value) SELECT type,value FROM setting "
              "WHERE type LIKE 'ACHBOT_GIFT_SOURCE_%' LIMIT 1")
        ready(w,e)
        call(w,e,stage)
        require(len(ids(w,e))==1 and claims(w,e)==2,
                'Nonunique duplicate setting.type claim created another job')
        cases.append('f1_duplicate_setting_claim_fails_closed')

        reset(w,e);ready(w,e)
        call(w,e,stage,mode='notice_fail')
        require(len(ids(w,e))==claims(w,e)==1,
                'Post-COMMIT Telegram failure lost committed source state')
        cases.append('f1_telegram_notice_failure_preserves_committed_job')

        reset(w,e);ready(w,e)
        call(w,e,stage,wire_update=1701,wire_message=52)
        ready(w,e)
        call(w,e,stage,wire_update=1701,wire_message=53)
        require(len(ids(w,e))==claims(w,e)==1,
                'Mutated message ID under same update ID created another batch')
        cases.append('f1_same_update_id_changed_message_denied')

        # F2-T1: two genuinely different Telegram messages use one
        # previously active wizard step in separate PHP/MySQLi sessions.
        reset(w,e);ready(w,e)
        tags=('f2-distinct-a','f2-distinct-b')
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
            futures=[pool.submit(call,w,e,stage,wire_update=update,
                       wire_message=message,barrier=str(stage/tag))
                     for update,message,tag in zip((1801,1802),(61,62),tags)]
            deadline=time.monotonic()+15
            while time.monotonic()<deadline:
                if all((stage/(tag+'.ready')).exists() for tag in tags):break
                time.sleep(.02)
            require(all((stage/(tag+'.ready')).exists() for tag in tags),
                    'Two distinct Telegram source processes did not overlap')
            for tag in tags:(stage/(tag+'.release')).write_text('go')
            events=[future.result(timeout=43)['events'] for future in futures]
        require(len(ids(w,e))==claims(w,e)==1 and
                sum('SAVED' in x for x in events)==1 and
                q(w,e,'SELECT step FROM users WHERE userid=9001')=='none',
                f'F2: two different updates consumed same active step: {events}')
        cases.append('f2_parallel_distinct_updates_one_active_step_one_batch')

        # F2-T2: schedule B's old pre-read step first. Pause A precisely
        # AFTER durable COMMIT but BEFORE any post-COMMIT bot presentation.
        # The buggy previous HEAD inserted B as a second batch in this gap.
        reset(w,e);ready(w,e)
        gate=stage/'f2-controlled-b';committed=stage/'f2-controlled-a'
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
            future_b=pool.submit(call,w,e,stage,wire_update=1812,
                                 wire_message=64,barrier=str(gate))
            deadline=time.monotonic()+15
            while not (stage/'f2-controlled-b.ready').exists() and time.monotonic()<deadline:
                time.sleep(.02)
            require((stage/'f2-controlled-b.ready').exists(),
                    'B did not pre-read original active final step')
            future_a=pool.submit(call,w,e,stage,wire_update=1811,
                 wire_message=63,mode='pause_after_commit',commit_path=str(committed))
            deadline=time.monotonic()+15
            while not (stage/'f2-controlled-a.committed').exists() and time.monotonic()<deadline:
                time.sleep(.02)
            require((stage/'f2-controlled-a.committed').exists(),
                    'A did not reach real COMMIT before presentation')
            try:
                (stage/'f2-controlled-b.release').write_text('go')
                result_b=future_b.result(timeout=27)
                require(len(ids(w,e))==claims(w,e)==1 and
                        'SAVED' not in result_b['events'] and
                        q(w,e,'SELECT step FROM users WHERE userid=9001')=='none',
                        'B committed same active step after A COMMIT before A UI')
            finally:
                (stage/'f2-controlled-a.release').write_text('go')
                result_a=future_a.result(timeout=30)
            require('SAVED' in result_a['events'] and len(ids(w,e))==1,
                    'Controlled A completion lost the one durable original job')
        cases.append('f2_second_distinct_update_after_first_commit_before_ui_denied')

        # F2-T3: unlike a different literal step, a NEW generation of the
        # identical giftServerVolume2_0 string must survive late replay A.
        reset(w,e);ready(w,e)
        call(w,e,stage,wire_update=1821,wire_message=65)
        original=ids(w,e)
        require(len(original)==1 and
                q(w,e,'SELECT step FROM users WHERE userid=9001')=='none',
                'First genuine intent did not consume its step')
        ready(w,e)
        duplicate=call(w,e,stage,wire_update=1821,wire_message=65)
        require(ids(w,e)==original and claims(w,e)==1 and
                any('تکراری' in msg for msg in duplicate['events']) and
                q(w,e,'SELECT step FROM users WHERE userid=9001')==
                    'giftServerVolume2_0',
                'Late identical-source replay erased newly rearmed same-literal step')
        cases.append('f2_old_update_replay_does_not_consume_new_same_literal_step')

        # F2-T4: the operator's new, genuinely different message is still
        # authorized by that preserved generation, exactly once.
        new=call(w,e,stage,wire_update=1822,wire_message=66)
        require(len(ids(w,e))==claims(w,e)==2 and
                'SAVED' in new['events'] and
                q(w,e,'SELECT step FROM users WHERE userid=9001')=='none',
                'Late replay prevented new intentional gift after rearming')
        cases.append('f2_new_update_after_late_replay_consumes_new_step_once')

        # F2-T6: SQL failure of the final wizard-step consumption must
        # roll back already-inserted gift, enqueue receipt and source claim.
        reset(w,e);ready(w,e)
        q(w,e,"CREATE TRIGGER owned_f2_step_update_denied "
          "BEFORE UPDATE ON users FOR EACH ROW "
          "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='owned_step_transition_failed'")
        try:
            response=call(w,e,stage,wire_update=1831,wire_message=67)
            require(not ids(w,e) and claims(w,e)==0 and
                    q(w,e,"SELECT COUNT(*) FROM setting WHERE type LIKE 'ACHBOT_GIFT_ENQUEUE_%'")=='0' and
                    q(w,e,'SELECT step FROM users WHERE userid=9001')==
                        'giftServerVolume2_0' and
                    'SAVED' not in response['events'],
                    'Failed step UPDATE did not roll back ALL new enqueue state')
        finally:
            q(w,e,'DROP TRIGGER owned_f2_step_update_denied')
        cases.append('f2_failed_step_sql_update_rolls_back_all_while_step_remains')

        # Another SQL path: a trigger silently prevents the transition,
        # producing 0 changed rows. Commit must still be refused.
        reset(w,e);ready(w,e)
        q(w,e,'CREATE TRIGGER owned_f2_step_unchanged '
          'BEFORE UPDATE ON users FOR EACH ROW SET NEW.step=OLD.step')
        try:
            response=call(w,e,stage,wire_update=1832,wire_message=68)
            require(not ids(w,e) and claims(w,e)==0 and
                    q(w,e,"SELECT COUNT(*) FROM setting WHERE type LIKE 'ACHBOT_GIFT_ENQUEUE_%'")=='0' and
                    q(w,e,'SELECT step FROM users WHERE userid=9001')==
                        'giftServerVolume2_0' and
                    'SAVED' not in response['events'],
                    'Zero-row wizard step transition committed partial gift state')
        finally:
            q(w,e,'DROP TRIGGER owned_f2_step_unchanged')
        cases.append('f2_zero_row_step_transition_rolls_back_all_records')

        # F2-T8: a delegated operator's own final step is consumed,
        # while another actor's independent wizard step remains intact.
        reset(w,e);ready(w,e,9002)
        q(w,e,"UPDATE users SET step='unrelated_active_step' WHERE userid=9001")
        outcome=call(w,e,stage,actor=9002,wire_update=1841,wire_message=69)
        require(len(ids(w,e))==claims(w,e)==1 and
                'SAVED' in outcome['events'] and
                q(w,e,'SELECT step FROM users WHERE userid=9002')=='none' and
                q(w,e,'SELECT step FROM users WHERE userid=9001')==
                    'unrelated_active_step',
                'Delegated gift consumed wrong actor step or was not consumed')
        cases.append('f2_delegated_admin_consumes_only_own_step')

        # Two administrators may each intentionally complete their OWN
        # separate active steps. The common global source lock serializes
        # claims; neither actor is allowed to clear the other's step.
        reset(w,e);ready(w,e);ready(w,e,9002)
        q(w,e,"UPDATE users SET step='unrelated_active_step' WHERE userid=1001")
        tags=('f2-actor-a','f2-actor-b')
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
            futures=[pool.submit(call,w,e,stage,actor=actor,
                wire_update=update,wire_message=message,barrier=str(stage/tag))
                for actor,update,message,tag in zip(
                    (9001,9002),(1842,1843),(70,71),tags)]
            deadline=time.monotonic()+15
            while time.monotonic()<deadline:
                if all((stage/(tag+'.ready')).exists() for tag in tags):break
                time.sleep(.02)
            require(all((stage/(tag+'.ready')).exists() for tag in tags),
                    'Distinct-actor source processes not concurrently scheduled')
            for tag in tags:(stage/(tag+'.release')).write_text('go')
            results=[f.result(timeout=43)['events'] for f in futures]
        require(len(ids(w,e))==claims(w,e)==2 and
                all('SAVED' in result for result in results) and
                q(w,e,'SELECT step FROM users WHERE userid=9001')=='none' and
                q(w,e,'SELECT step FROM users WHERE userid=9002')=='none' and
                q(w,e,'SELECT step FROM users WHERE userid=1001')==
                    'unrelated_active_step',
                f'Distinct authorized actors interfered with each other: {results}')
        cases.append('f2_parallel_distinct_actors_consumed_only_respective_steps')

    report={'complete':True,'cases':len(cases),'scenarios':cases,
        'real_bot_php_route':True,'real_telegram_update_bindings':True,
        'real_gift_batch_helper':True,'owned_original_schema_mariadb':True,
        'parallel_php_sessions':True,
        'atomic_admin_step_and_source_commit':True,
        'production_mutations':0,'real_provider_requests':0}
    if args.report:args.report.write_text(json.dumps(report,indent=2)+'\n')
    print(json.dumps(report))

if __name__=='__main__':main()
