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
$botUsername='fixture';
require $root.'/src/Jobs/Checkpoint.php';
require_once $root.'/src/Telegram/Response.php';
function achbotNotifyAdmins(...$args) {
    if ($GLOBALS['mode']==='cancel_before_claim')
        $GLOBALS['connection']->query('UPDATE send_list SET state=4 WHERE id=8 AND state=2');
}
function apiOk($res) {return \AchBot\Telegram\Response\isSuccessful($res);}
function getApiMessageId($res) {return \AchBot\Telegram\Response\messageId($res);}
function logBroadcastUserMessage($db,$bid,$uid,$mid) {
    $s=$db->prepare('INSERT INTO bc_messages(broadcast_id,userid,message_id) VALUES (?,?,?)');
    $s->bind_param('iii',$bid,$uid,$mid);$s->execute();$s->close();
}
function bot($method,$payload) {
    $uid=(int)$payload['chat_id'];
    file_put_contents($GLOBALS['trace'],json_encode(['method'=>$method,'uid'=>$uid,'payload'=>$payload],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)."\n",FILE_APPEND|LOCK_EX);
    if ($GLOBALS['mode']==='lost_after_dispatch') sleep(60);
    if ($GLOBALS['mode']==='unknown' && $uid===1001) return null;
    return (object)['ok'=>true,'result'=>(object)['message_id'=>$uid+10000]];
}
function sendMessage($text,$keys,$mode,$uid) {return bot('sendMessage',['chat_id'=>$uid,'text'=>$text,'parse_mode'=>$mode,'reply_markup'=>$keys]);}
function forwardmessage($uid,$chat,$mid) {return bot('forwardMessage',['chat_id'=>$uid,'from_chat_id'=>$chat,'message_id'=>$mid]);}
if ($mode==='monotonic_pause') {markPaused($connection,'send_list',8,0);return;}
require $root.'/src/Jobs/BroadcastSend.php';
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
        def sql(statement):
            return owned.sql(work, env, statement, DB).strip()
        def seed(kind='text'):
            trace.write_text('')
            sql("DELETE FROM bc_messages; DELETE FROM send_list; "
                "INSERT INTO send_list(id,type,state,text,text_html,file_id) VALUES "
                "(7,'text',1,'historical pending',NULL,NULL),"
                f"(8,'{kind}',1,'literal <b> &','<b>سلام</b> &amp; &lt;tag&gt;','fixture-photo')")
        def command(mode):
            return ['php', '-n', '-d', 'extension=mysqlnd', '-d', 'extension=mysqli',
                    str(driver), str(ROOT), str(work/'mysql.sock'), DB, mode, str(trace)]
        def run(mode='normal'):
            result = subprocess.run(command(mode), env=env, capture_output=True, text=True, timeout=20)
            assert result.returncode == 0, result.stderr
            return result.stderr
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
        sql('UPDATE send_list SET updated_at=DATE_SUB(NOW(),INTERVAL 16 MINUTE) WHERE id=8')
        run(); run()
        assert [x['uid'] for x in events()] == users, events()
        assert sql('SELECT state FROM send_list WHERE id=8') == '3'
        assert sql('SELECT COUNT(*) FROM bc_messages') == '2'
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
        assert sql('SELECT COUNT(*) FROM bc_messages') == '2'
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
    result={'complete':True,'cases':len(cases),'scenarios':cases,'network_requests':0,'production_mutations':0,'source_real_php':True}
    if args.report: args.report.write_text(json.dumps(result, indent=2)+'\n')
    print(json.dumps(result))

if __name__=='__main__':
    main()
