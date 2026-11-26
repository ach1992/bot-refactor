#!/usr/bin/env python3
"""Execute the current broadcast worker with owned MariaDB and fake Telegram.
A killed process after the outbound request cannot replay the same recipient.
No installed data or real Telegram requests are used.
"""
from __future__ import annotations

import argparse
import json
import subprocess
import time
from pathlib import Path

import database_fixture as owned

ROOT = Path(__file__).resolve().parents[1]
DB = owned.DBS[1]
DRIVER = r'''<?php
$root=$argv[1];$mode=$argv[4];$trace=$argv[5];
$connection=new mysqli('localhost','root','',$argv[3],0,$argv[2]);
$connection->set_charset('utf8mb4');
if (str_starts_with($mode,'off:')) {
    mysqli_report(MYSQLI_REPORT_OFF);
    $mode=substr($mode,4);
}
$actionMode=str_starts_with($mode,'action:');
if ($actionMode) $mode=substr($mode,7);
$botUsername='fixture';
require $root.'/src/Jobs/Checkpoint.php';
require_once $root.'/src/Telegram/Messages.php';
function achbotNotifyAdmins($method,$payload,$options) {
    $phase=substr($options['report_ref'],strrpos($options['report_ref'],':')+1);
    $target=$GLOBALS['actionMode']?'bc_jobs':'send_list';
    $id=$GLOBALS['actionMode']?18:8;
    file_put_contents($GLOBALS['trace'].'.notices',json_encode([
        'method'=>$method,'phase'=>$phase,'text'=>$payload['text'],
        'state'=>(int)$GLOBALS['connection']->query("SELECT state FROM $target WHERE id=$id")->fetch_row()[0]
    ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)."\n",FILE_APPEND|LOCK_EX);
    if ($GLOBALS['mode']==='cancel_before_claim' && $phase==='started')
        $GLOBALS['connection']->query("UPDATE $target SET state=4 WHERE id=$id AND state=2");
    if ($GLOBALS['mode']==='notice_exception')
        throw new RuntimeException('private fixture notification error');
}
require_once $root.'/src/Broadcast/Storage.php';
function bot($method,$payload) {
    $uid=(int)$payload['chat_id'];
    file_put_contents($GLOBALS['trace'],json_encode(['method'=>$method,'uid'=>$uid,'payload'=>$payload],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)."\n",FILE_APPEND|LOCK_EX);
    if ($GLOBALS['mode']==='lost_after_dispatch') sleep(60);
    $mode=$GLOBALS['mode'];
    if ($mode==='cancel_during_send' && $uid===1001) {
        $target=$GLOBALS['actionMode']?'bc_jobs':'send_list';
        $id=$GLOBALS['actionMode']?18:8;
        $GLOBALS['connection']->query("UPDATE $target SET state=4 WHERE id=$id AND state=2");
    }
    if ($mode==='other_forbidden' && $uid===1001)
        return (object)['ok'=>false,'error_code'=>403,'description'=>'Forbidden: bot is not a member of the chat'];
    if ($mode==='unknown' && $uid===1001) return null;
    if ($mode==='malformed_ack' && $uid===1001) return (object)['ok'=>true];
    if ($mode==='malformed_id' && $uid===1001)
        return (object)['ok'=>true,'result'=>(object)['message_id'=>'11001invalid']];
    if ($mode==='exception' && $uid===1001)
        throw new RuntimeException('private fixture send error');
    if ($mode==='mixed' && $uid===1001)
        return (object)['ok'=>false,'error_code'=>403,'description'=>'Forbidden: bot was blocked by the user'];
    if ($mode==='mixed' && $uid===9001)
        return (object)['ok'=>false,'error_code'=>400,'description'=>'Bad Request: chat not found'];
    if ($mode==='edit_noop' && $uid===1001)
        return (object)['ok'=>false,'error_code'=>400,'description'=>'Bad Request: message is not modified'];
    if ($mode==='all_rejected')
        return (object)['ok'=>false,'error_code'=>400,'description'=>'Bad Request: fixture rejection'];
    if ($mode==='rate_limited' && $uid===1001)
        return (object)['ok'=>false,'error_code'=>429,'description'=>'Too Many Requests',
            'parameters'=>(object)['retry_after'=>120]];
    if ($mode==='rate_without_wait' && $uid===1001)
        return (object)['ok'=>false,'error_code'=>429,'description'=>'Too Many Requests'];
    if ($GLOBALS['actionMode']) {
        if ($mode==='wrong_edit_ack' && $uid===1001)
            return (object)['ok'=>true,'result'=>(object)['message_id'=>123]];
        return (object)['ok'=>true,'result'=>str_starts_with($method,'editMessage')
            ?(object)['message_id'=>$payload['message_id']]:true];
    }
    return (object)['ok'=>true,'result'=>(object)['message_id'=>$uid+10000]];
}
function pinOne($uid,$mid) {return \AchBot\Telegram\Messages\pinOne('bot',$uid,$mid);}
function unpinOne($uid,$mid) {return \AchBot\Telegram\Messages\unpinOne('bot',$uid,$mid);}
function deleteOne($uid,$mid) {return \AchBot\Telegram\Messages\deleteOne('bot',$uid,$mid);}
function sendMessage($text,$keys,$mode,$uid) {return bot('sendMessage',['chat_id'=>$uid,'text'=>$text,'parse_mode'=>$mode,'reply_markup'=>$keys]);}
function forwardmessage($uid,$chat,$mid) {return bot('forwardMessage',['chat_id'=>$uid,'from_chat_id'=>$chat,'message_id'=>$mid]);}
if ($mode==='progress') {
    $row=$connection->query('SELECT target_group,`offset` FROM send_list WHERE id=8')->fetch_assoc();
    echo json_encode(['total'=>achbotBroadcastAudienceCount($connection,$row['target_group']),
        'left'=>achbotBroadcastAudienceCount($connection,$row['target_group'],(int)$row['offset']),
        'ack'=>achbotBroadcastAckCount($connection,8)],JSON_THROW_ON_ERROR);
    return;
}
if ($mode==='monotonic_pause') {markPaused($connection,'send_list',8,0);return;}
if ($actionMode) {
    $inlineStartKey='{"inline_keyboard":[]}';
    require $root.'/src/Jobs/MessageActions.php';
} else require $root.'/src/Jobs/BroadcastSend.php';
'''


def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('--run-disposable', action='store_true', required=True)
    p.add_argument('--report', type=Path)
    args = p.parse_args()
    cases = []
    with owned.owned_database(ROOT/'database/current-schema.sql') as (work, env):
        driver = work/'broadcast-driver.php'
        driver.write_text(DRIVER)
        trace = work/'broadcast-trace.jsonl'
        notices = Path(str(trace)+'.notices')
        def sql(statement):
            return owned.sql(work, env, statement, DB).strip()
        def seed(kind='text'):
            trace.write_text('')
            notices.write_text('')
            sql("DELETE FROM bc_jobs; DELETE FROM bc_messages; DELETE FROM send_list; UPDATE users SET step='none'; "
                "INSERT INTO send_list(id,type,state,text,text_html,file_id,chat_id,message_id) VALUES "
                "(7,'text',1,'historical pending',NULL,NULL,NULL,NULL),"
                f"(8,'{kind}',1,'literal <b> &','<b>سلام</b> &amp; &lt;tag&gt;','fixture-photo',9001,42)")
        def seed_action(action,kind='text'):
            seed(kind)
            sql("UPDATE send_list SET state=3 WHERE id=8; "
                "INSERT INTO bc_messages(broadcast_id,userid,message_id,pinned) VALUES "+
                ','.join(f"(8,{uid},{uid+10000},{int(action=='unpin')})" for uid in (1001,9001,9002))+
                "; INSERT INTO bc_jobs(id,broadcast_id,action,state,new_text,new_html) VALUES "
                "(17,8,'edit',1,'historical raw edit',NULL),"
                f"(18,8,'{action}',1,'raw edit &','<b>ویرایش</b> &amp;')")
        def action_result(state,ok,fail):
            assert sql("SELECT CONCAT(state,':',ok_cnt,':',fail_cnt) FROM bc_jobs WHERE id=18")==f'{state}:{ok}:{fail}'
            assert sql('SELECT state FROM bc_jobs WHERE id=17')=='1'
            reports=[json.loads(line) for line in notices.read_text().splitlines() if json.loads(line)['phase']=='completed']
            assert len(reports)==int(state==3),reports
            if reports:assert reports[0]['state']==3
        def command(mode):
            return ['php', '-n', '-d', 'extension=mysqlnd', '-d', 'extension=mysqli',
                    str(driver), str(ROOT), str(work/'mysql.sock'), DB, mode, str(trace)]
        def run(mode='normal',failed=False):
            result = subprocess.run(command(mode), env=env, capture_output=True, text=True, timeout=70)
            assert (result.returncode!=0)==failed,(result.returncode,result.stderr,result.stdout)
            return result.stderr
        def progress():
            result=subprocess.run(command('progress'),env=env,capture_output=True,text=True,timeout=10)
            assert result.returncode==0 and not result.stderr,result.stderr
            return json.loads(result.stdout)
        def completed(count,kind='text'):
            finished=[json.loads(line) for line in notices.read_text().splitlines()
                      if json.loads(line)['phase']=='completed']
            title='فوروارد' if kind=='forwardall' else 'ارسال'
            expected=(f'📣 پردازش {title} همگانی به پایان رسید.\n\n'
                      f'✅ تعداد گیرندگان با پاسخ موفق ثبت‌شده از تلگرام: {count} نفر\n\n'
                      'ارسال‌های بدون تأیید در این تعداد لحاظ نشده‌اند.')
            assert finished==[dict(method='sendMessage',phase='completed',text=expected,state=3)],finished
            assert sql('SELECT state FROM send_list WHERE id=8')=='3'
            assert sql('SELECT COUNT(*) FROM bc_messages WHERE broadcast_id=8 AND message_id>0')==str(count)
        def no_completion():
            assert not any(json.loads(line)['phase']=='completed' for line in notices.read_text().splitlines())

        def events():
            return [json.loads(line) for line in trace.read_text().splitlines()]
        users = [int(v) for v in sql('SELECT userid FROM users ORDER BY id').splitlines()]
        assert users == [1001, 9001, 9002], users
        first_id = int(sql('SELECT id FROM users WHERE userid=1001'))
        seed()
        proc = subprocess.Popen(command('lost_after_dispatch'), env=env, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        try:
            deadline = time.monotonic()+10
            while not trace.stat().st_size and proc.poll() is None and time.monotonic()<deadline:
                time.sleep(.025)
            assert trace.stat().st_size, 'source did not reach fake outbound request'
            assert [x['uid'] for x in events()] == [1001]
            assert sql('SELECT CONCAT(state,\':\',`offset`) FROM send_list WHERE id=8') == f'2:{first_id}'
        finally:
            if proc.poll() is None: proc.kill()
            proc.communicate(timeout=5)
        assert sql('SELECT COUNT(*) FROM bc_messages') == '0'
        # The actual canonical lease has been released by the dead process.
        # Do not backdate the timestamp: the next tick must recover immediately.
        run(); run()
        assert [x['uid'] for x in events()] == users, events()
        assert sql('SELECT state FROM send_list WHERE id=8') == '3'
        completed(2)
        assert sql('SELECT state FROM send_list WHERE id=7') == '1'
        cases.append('killed_after_outbound_before_ack_never_replays_recipient')
        assert all(e['payload']['text']=='<b>سلام</b> &amp; &lt;tag&gt;' and e['payload']['parse_mode']=='HTML' for e in events())
        assert sql('SELECT text FROM send_list WHERE id=8') == 'literal <b> &'
        cases.append('actual_html_text_and_raw_history_unchanged')
        seed('photo')
        log=run('unknown'); run()
        assert [e['uid'] for e in events()] == users
        assert all(e['method']=='sendPhoto' and e['payload']['caption']=='<b>سلام</b> &amp; &lt;tag&gt;' and e['payload']['parse_mode']=='HTML' for e in events())
        assert 'achbot_broadcast_delivery_unknown broadcast_id=8 user_id=1001' in log
        completed(2)
        cases.append('actual_media_caption_unknown_ack_not_retried_and_logged')
        seed()
        run('cancel_before_claim')
        assert not events() and sql('SELECT state FROM send_list WHERE id=8') == '4'
        cases.append('cancelled_job_cannot_claim_or_dispatch')
        seed()
        sql(f'UPDATE send_list SET state=2,`offset`={first_id} WHERE id=8')
        run('monotonic_pause')
        assert sql('SELECT `offset` FROM send_list WHERE id=8') == str(first_id)
        cases.append('pause_cannot_rewind_durable_cursor')
        # Completion is based on immutable recorded ACKs, not the cursor,
        # dynamically changed audience, or unrelated/invalid stored IDs.
        seed()
        run(); run()
        completed(3)
        run()
        completed(3)
        cases.append('all_ack_completion_committed_before_one_truthful_report')
        seed('forwardall')
        run('all_rejected'); run()
        completed(0,'forwardall')
        assert [e['uid'] for e in events()]==users
        assert all(e['method']=='forwardMessage' and e['payload']['message_id']==42 for e in events())
        cases.append('zero_ack_forward_completion_not_misreported_as_success')
        seed()
        log=run('mixed'); run()
        completed(1)
        assert [e['uid'] for e in events()]==users
        assert 'delivery_rejected code=403 user_id=1001' in log
        assert 'delivery_rejected code=400 user_id=9001' in log
        assert sql('SELECT step FROM users WHERE userid=1001')=='Bot_Blocked'
        cases.append('recipient_rejections_continue_with_only_recorded_ack_count')
        seed()
        sql("UPDATE users SET step='achbotDirectGift:test' WHERE userid=1001")
        run('mixed');run()
        assert sql('SELECT step FROM users WHERE userid=1001')=='achbotDirectGift:test'
        completed(1)
        seed()
        sql("UPDATE users SET step='achbotExempt:fixture' WHERE userid=1001")
        run('mixed');run()
        assert sql('SELECT step FROM users WHERE userid=1001')=='achbotExempt:fixture'
        completed(1)
        seed()
        run('other_forbidden');run()
        assert sql('SELECT step FROM users WHERE userid=1001')=='none'
        completed(2)
        cases.append('only_actual_blocked_user_marked_without_erasing_protected_steps')
        for mode in ('exception','malformed_ack','malformed_id'):
            seed()
            log=run(mode);run()
            completed(2)
            assert [e['uid'] for e in events()]==users
            assert 'delivery_unknown broadcast_id=8 user_id=1001' in log
            assert 'private fixture send error' not in log
            if mode=='exception': assert 'dispatch_exception broadcast_id=8 user_id=1001' in log
            cases.append('recipient_'+mode+'_does_not_interrupt_other_recipients')
        seed()
        log=run('notice_exception');log+=run('notice_exception')
        completed(3)
        assert 'notice_failed broadcast_id=8 phase=started' in log
        assert 'notice_failed broadcast_id=8 phase=completed' in log
        assert 'private fixture notification error' not in log
        run()
        completed(3)
        assert [e['uid'] for e in events()]==users
        cases.append('admin_notice_exception_cannot_stop_or_reopen_dispatch')
        seed()
        run('cancel_during_send');run()
        assert [e['uid'] for e in events()]==[1001]
        assert sql('SELECT state FROM send_list WHERE id=8')=='4'
        assert sql('SELECT COUNT(*) FROM bc_messages WHERE broadcast_id=8')=='1'
        no_completion()
        cases.append('cancellation_during_dispatch_preserves_claim_and_no_completion')
        seed()
        log=run('rate_limited')
        assert 'rate_deferred broadcast_id=8 user_id=1001 retry_after=120' in log
        assert sql("SELECT CONCAT(state,':',`offset`) FROM send_list WHERE id=8")==f'1:{first_id}'
        assert int(sql('SELECT TIMESTAMPDIFF(SECOND,NOW(),updated_at) FROM send_list WHERE id=8'))>100
        assert progress()=={'total':3,'left':2,'ack':0},progress()
        sql("INSERT INTO send_list(id,type,state,text_html) VALUES (9,'text',1,'queued')")
        run()
        assert [e['uid'] for e in events()]==[1001]
        assert sql('SELECT `offset` FROM send_list WHERE id=9')=='0'
        no_completion()
        # Simulate the passage of the provider's waiting interval, not a
        # restart or a retry of the already claimed rejected recipient.
        sql('UPDATE send_list SET updated_at=NOW() WHERE id=8')
        run();run()
        completed(2)
        assert [e['uid'] for e in events()]==users
        assert sql('SELECT state FROM send_list WHERE id=9')=='1'
        cases.append('rate_limit_checkpoint_survives_ticks_and_resumes_fifo_without_replay')
        seed()
        log=run('rate_without_wait')
        assert 'rate_deferred broadcast_id=8 user_id=1001 retry_after=0' in log
        assert [e['uid'] for e in events()]==[1001]
        run();run()
        completed(2)
        assert [e['uid'] for e in events()]==users
        cases.append('explicit_rate_limit_without_wait_yields_one_tick_not_entire_job')
        for mode in ('normal','off:normal'):
            seed()
            # SQL-local ACK write fails for one recipient. The REAL storage
            # helper must detect both strict and nonthrowing MySQLi errors.
            sql("CREATE TRIGGER owned_fail_ack BEFORE INSERT ON bc_messages FOR EACH ROW "
                "SET NEW.message_id=IF(NEW.userid=1001,NULL,NEW.message_id)")
            try:
                log=run(mode)
            finally:
                sql('DROP TRIGGER owned_fail_ack')
            run()
            completed(2)
            assert 'ack_record_failed broadcast_id=8 user_id=1001 message_id=11001' in log,log
            assert [e['uid'] for e in events()]==users
            cases.append('ack_write_failure_isolated_'+('nonthrowing' if mode.startswith('off:') else 'strict'))
            seed()
            sql("CREATE TRIGGER owned_fail_claim BEFORE UPDATE ON send_list FOR EACH ROW "
                f"SET NEW.`offset`=IF(NEW.id=8 AND NEW.`offset`={first_id} AND NEW.`offset`>OLD.`offset`,NULL,NEW.`offset`)")
            try:
                log=run(mode,failed=True)
                assert not events()
                assert 'worker_deferred broadcast_id=8 offset=0' in log,log
                assert sql('SELECT `offset` FROM send_list WHERE id=8')=='0'
                no_completion()
            finally:
                sql('DROP TRIGGER owned_fail_claim')
            run();run()
            completed(3)
            assert [e['uid'] for e in events()]==users
            cases.append('database_claim_failure_recovers_without_unclaimed_send_'+('nonthrowing' if mode.startswith('off:') else 'strict'))
            seed()
            run()
            sql('RENAME TABLE bc_messages TO owned_saved_bc_messages')
            try:
                log=run(mode,failed=True)
                no_completion()
                assert sql('SELECT state FROM send_list WHERE id=8')=='1'
                assert [e['uid'] for e in events()]==users
                assert 'worker_deferred broadcast_id=8' in log
            finally:
                sql('RENAME TABLE owned_saved_bc_messages TO bc_messages')
            run()
            completed(3)
            assert [e['uid'] for e in events()]==users
            cases.append('failed_ack_count_cannot_report_zero_or_replay_'+('nonthrowing' if mode.startswith('off:') else 'strict'))
        # Count only this broadcast's positive stored ACKs, even if the
        # audience changed after sending. Deleted messages remain accepted
        # historical sends; invalid/foreign IDs are not counted.
        seed()
        run()
        sql("UPDATE users SET step='banned'; UPDATE bc_messages SET deleted=1 WHERE userid=1001; "
            "INSERT INTO bc_messages(broadcast_id,userid,message_id) VALUES (8,123456,0),(7,123456,99)")
        run()
        completed(3)
        cases.append('completion_count_independent_of_current_audience_and_invalid_or_other_job_rows')
        for media,field in [('music','audio'),('video','video'),('voice','voice'),('document','document')]:
            seed(media);run();run();completed(3)
            assert all(e['method']=='send'+field.capitalize() and e['payload'][field]=='fixture-photo'
                       and e['payload']['caption']=='<b>سلام</b> &amp; &lt;tag&gt;'
                       and e['payload']['parse_mode']=='HTML' for e in events())
        cases.append('all_supported_media_use_original_file_caption_and_html')
        # Count and select the same current audience, including users with
        # multiple subscriptions (one recipient, not one row per order).
        sql("DELETE FROM orders_list; UPDATE users SET is_agent=0; UPDATE users SET is_agent=1 WHERE userid=9002; "
            "INSERT INTO orders_list(userid,token,transid,fileid,server_id,remark,expire_date,link,amount,status,date) VALUES "
            "(1001,'fixture','fixture-a',1,1,'fixture-a',0,'',0,1,'1700000000'),"
            "(1001,'fixture','fixture-b',1,1,'fixture-b',0,'',0,1,'1700000000')")
        for group,expected in [('has',[1001]),('none',[9001,9002]),('agents',[9002]),('all',[1001,9001,9002])]:
            seed()
            sql(f"UPDATE send_list SET target_group='{group}' WHERE id=8")
            assert progress()=={'total':len(expected),'left':len(expected),'ack':0}
            run()
            assert [e['uid'] for e in events()]==expected
            assert progress()=={'total':len(expected),'left':0,'ack':len(expected)}
            run();completed(len(expected))
        cases.append('shared_current_audience_progress_and_dispatch_all_groups')
        # Real pin, unpin, delete and edit paths share the durable cursor.
        # Every wire target remains the original chat and message, never
        # the latest pinned message. Do not run historical raw edit jobs.
        for action,kind,method in [
                ('pin','text','pinChatMessage'),('unpin','text','unpinChatMessage'),
                ('delete','text','deleteMessage'),('edit','text','editMessageText'),
                ('edit','photo','editMessageCaption')]:
            seed_action(action,kind)
            run('action:normal');run('action:normal')
            action_result(3,3,0)
            assert [e['uid'] for e in events()]==users
            assert all(e['method']==method and e['payload']['message_id']==e['uid']+10000 for e in events()),events()
            if action=='edit':
                field='text' if kind=='text' else 'caption'
                assert all(e['payload'][field]=='<b>ویرایش</b> &amp;' and e['payload']['parse_mode']=='HTML' for e in events())
            if action=='pin':assert sql('SELECT SUM(pinned) FROM bc_messages')=='3'
            if action=='unpin':assert sql('SELECT SUM(pinned) FROM bc_messages')=='0'
            if action=='delete':assert sql('SELECT SUM(deleted) FROM bc_messages')=='3'
        cases.append('all_current_message_actions_use_exact_original_target_and_html')
        seed_action('pin')
        # Neither a missing/uncompleted broadcast nor a forward-only edit
        # is admitted work; an invalid earlier row cannot starve a valid job.
        sql("INSERT INTO send_list(id,type,state) VALUES (10,'forwardall',3); "
            "INSERT INTO bc_jobs(id,broadcast_id,action,state,new_html) VALUES "
            "(14,99999,'pin',1,NULL),(15,7,'delete',1,NULL),(16,10,'edit',1,'new')")
        run('action:normal');run('action:normal')
        action_result(3,3,0)
        assert [e['uid'] for e in events()]==users
        assert sql('SELECT SUM(state=1) FROM bc_jobs WHERE id IN (14,15,16)')=='3'
        cases.append('invalid_or_historical_action_does_not_execute_or_starve_current_job')
        for mode in ('unknown','exception','malformed_ack','edit_noop'):
            seed_action('unpin')
            run('action:'+mode);run('action:normal')
            action_result(3,2,1)
            assert [e['uid'] for e in events()]==users
            assert all(e['method']=='unpinChatMessage' and e['payload']['message_id']==e['uid']+10000 for e in events()),events()
            assert sql('SELECT pinned FROM bc_messages WHERE userid=1001')=='1'
        cases.append('failed_unpin_never_falls_back_to_latest_message_and_continues')
        seed_action('edit')
        run('action:wrong_edit_ack');run('action:normal')
        action_result(3,2,1)
        cases.append('edit_ack_must_identify_original_message')
        seed_action('edit')
        run('action:edit_noop');run('action:normal')
        action_result(3,3,0)
        assert [e['uid'] for e in events()]==users
        cases.append('confirmed_identical_edit_is_not_a_failure_or_a_resend')
        seed_action('pin')
        proc=subprocess.Popen(command('action:lost_after_dispatch'),env=env,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        try:
            deadline=time.monotonic()+10
            while not trace.stat().st_size and proc.poll() is None and time.monotonic()<deadline:
                time.sleep(.025)
            assert [e['uid'] for e in events()]==[1001]
            row_id=sql('SELECT id FROM bc_messages WHERE broadcast_id=8 AND userid=1001')
            assert sql("SELECT CONCAT(state,':',`offset`) FROM bc_jobs WHERE id=18")==f'2:{row_id}'
        finally:
            if proc.poll() is None:proc.kill()
            proc.communicate(timeout=5)
        run('action:normal');run('action:normal')
        action_result(3,2,0)
        assert [e['uid'] for e in events()]==users
        cases.append('message_action_killed_after_claim_recovers_next_without_replay')
        for mode in ('cancel_before_claim','cancel_during_send'):
            seed_action('pin')
            run('action:'+mode);run('action:normal')
            assert [e['uid'] for e in events()]==([] if mode=='cancel_before_claim' else [1001])
            action_result(4,0 if mode=='cancel_before_claim' else 1,0)
        cases.append('message_action_cancellation_fences_every_recipient')
        seed_action('pin')
        run('action:rate_limited')
        action_result(1,0,1)
        assert int(sql('SELECT TIMESTAMPDIFF(SECOND,NOW(),updated_at) FROM bc_jobs WHERE id=18'))>100
        sql("INSERT INTO bc_jobs(id,broadcast_id,action,state) VALUES (19,8,'delete',1)")
        run('action:normal')
        assert [e['uid'] for e in events()]==[1001]
        assert sql('SELECT `offset` FROM bc_jobs WHERE id=19')=='0'
        sql('UPDATE bc_jobs SET updated_at=NOW() WHERE id=18')
        run('action:normal');run('action:normal')
        action_result(3,2,1)
        assert [e['uid'] for e in events()]==users
        cases.append('message_action_rate_limit_durable_pause_and_fifo_recovery')
        for mode in ('action:normal','off:action:normal'):
            seed_action('pin')
            sql("CREATE TRIGGER owned_fail_action BEFORE UPDATE ON bc_messages FOR EACH ROW "
                "SET NEW.pinned=IF(NEW.userid=1001,NULL,NEW.pinned)")
            try:
                log=run(mode)
                assert 'message_action_record_failed job_id=18' in log
            finally:
                sql('DROP TRIGGER owned_fail_action')
            run('action:normal')
            action_result(3,2,0)
            assert [e['uid'] for e in events()]==users
            assert sql('SELECT SUM(pinned) FROM bc_messages')=='2'
            cases.append('message_action_failed_local_projection_isolated_'+('nonthrowing' if mode.startswith('off:') else 'strict'))
            seed_action('pin')
            sql('RENAME TABLE bc_messages TO owned_saved_bc_messages')
            try:
                log=run(mode,failed=True)
                no_completion()
                assert sql('SELECT state FROM bc_jobs WHERE id=18')=='1'
                assert not events()
                assert 'message_action_worker_deferred job_id=18' in log
            finally:
                sql('RENAME TABLE owned_saved_bc_messages TO bc_messages')
            run('action:normal');run('action:normal')
            action_result(3,3,0)
            assert [e['uid'] for e in events()]==users
            cases.append('message_action_failed_audience_read_recovers_'+('nonthrowing' if mode.startswith('off:') else 'strict'))
        # Cross the actual production page boundary without changing the
        # batch size or pacing. Every eligible user must be reached once.
        seed()
        sql("INSERT INTO users(userid,name,username,refcode,date) VALUES "+
            ','.join(f"({20000+i},'Fixture','fixture','0','1700000000')" for i in range(402)))
        expected_users=[int(v) for v in sql('SELECT userid FROM users ORDER BY id').splitlines()]
        assert len(expected_users)==405
        run()
        first_slice=[e['uid'] for e in events()]
        assert 0<len(first_slice)<=400 and first_slice==expected_users[:len(first_slice)]
        assert sql('SELECT state FROM send_list WHERE id=8')=='1'
        for _ in range(6):
            if sql('SELECT state FROM send_list WHERE id=8')=='3':break
            run()
        assert [e['uid'] for e in events()]==expected_users
        completed(405)
        cases.append('actual_batch_boundary_resumes_all_405_recipients_once')
    result={'complete':True,'cases':len(cases),'scenarios':cases,'network_requests':0,'production_mutations':0,'source_real_php':True}
    if args.report: args.report.write_text(json.dumps(result, indent=2)+'\n')
    print(json.dumps(result))

if __name__=='__main__':
    main()
