#!/usr/bin/env python3
"""Current agency routes, original application generations and durable delivery."""
from __future__ import annotations
import argparse
from concurrent.futures import ThreadPoolExecutor
import itertools
import json
from pathlib import Path
import subprocess
import threading

import database_fixture as owned
ROOT=Path(__file__).resolve().parents[1]
DB=owned.NAMES[1]
PHP=r'''<?php
error_reporting(E_ALL);
set_error_handler(static function($n,$m){throw new ErrorException($m,0,$n);});
$root=$argv[1];$connection=new mysqli('localhost','root','',$argv[3],0,$argv[2]);
$connection->set_charset('utf8mb4');
$spec=json_decode($argv[4],true,64,JSON_THROW_ON_ERROR);
mysqli_report(($spec['strict']??true)?MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT:MYSQLI_REPORT_OFF);
$from_id=(int)$spec['actor'];$admin=9001;$data=$spec['data']??'';$text=$spec['text']??'';
$telegramWebhookSecret='Synthetic_Agency_Event_2026';$botState=['rewardChannel'=>'@fixture_report','supportID'=>'@support_fixture'];
$message_id=(int)($spec['message']??($data!==''?77:78));$time=time();$username='fixture';$first_name='Fixture';
$cancelKey='{"keyboard":[]}';$removeKeyboard='{"remove_keyboard":true}';$events=[];
$chat=(object)['id'=>(int)($spec['chat']??$from_id),'type'=>$spec['chat_type']??'private'];
$sender=(object)['id'=>$from_id,'first_name'=>'Fixture'];
$update=$data!==''?(object)['update_id'=>$spec['update'],'callback_query'=>(object)['id'=>'fixture',
    'from'=>$sender,'data'=>$data,'message'=>(object)['message_id'=>$message_id,'chat'=>$chat,'text'=>$text]]]:
    (object)['update_id'=>$spec['update'],'message'=>(object)['from'=>$sender,'chat'=>$chat,'message_id'=>$message_id,'text'=>$text]];
$callbackId=$update->callback_query->id??null;
function getAgentKeys(){return '{}';}
function getAgentDiscounts($id){return '{}';}
function getAgentsList(){return '{}';}
function getRejectedAgentList(){return '{}';}
function getUserInfoKeys($id){return ['inline_keyboard'=>[]];}
function delMessage(){throw new RuntimeException('fixture disposable prompt unavailable');}
function getMainKeys(){return '{}';}
function alert($v,...$args){$GLOBALS['events'][]=['alert',$v];}
function answerCallbackQuery(...$args){$GLOBALS['events'][]=['answer',$args];}
function editText(...$args){$GLOBALS['events'][]=['editText',$args];}
function editKeys(...$args){$GLOBALS['events'][]=['editKeys',$args];}
function sendMessage($text,$keys=null,$mode=null,$uid=null){
    $target=$uid??$GLOBALS['from_id'];$GLOBALS['events'][]=['send',$target,$text,$keys,$mode];
    if (($GLOBALS['spec']['cleanup_fail']??false) && (json_decode($keys??'null',true)['remove_keyboard']??false))
        throw new RuntimeException('fixture_cleanup_failure');
    if (($GLOBALS['spec']['fail_recipient']??null)===$target) {
        if (!empty($GLOBALS['spec']['unknown'])) return null;
        return (object)['ok'=>false,'error_code'=>429,'description'=>'Too Many Requests','parameters'=>(object)['retry_after'=>1]];
    }
    return (object)['ok'=>true,'result'=>(object)['message_id'=>80+count($GLOBALS['events']),
        'chat'=>(object)['id'=>is_int($target)?$target:-1001234567,'username'=>is_string($target)?ltrim($target,'@'):null]]];
}
require_once $root.'/src/Telegram/UserEvents.php';
$userInfo=achbotProviderRow($connection,'SELECT * FROM users WHERE userid=?','i',[$from_id])??[];
if (isset($spec['cached_step'])) $userInfo['step']=$spec['cached_step'];
if (isset($spec['dispatch'])) achbotOperationalReportDispatch($connection,$spec['dispatch']);
elseif (($spec['route']??'')==='discount') require $root.'/src/Agent/Discounts.php';
elseif (($spec['route']??'')==='rejected') require $root.'/src/Agent/Rejected.php';
else require $root.'/src/Agent/Applications.php';
echo json_encode(['events'=>$events],JSON_THROW_ON_ERROR);
'''
def main():
    p=argparse.ArgumentParser();p.add_argument('--run-disposable',action='store_true',required=True);p.add_argument('--report',type=Path)
    a=p.parse_args();checks=[]
    with owned.owned_database(ROOT/'database/current-schema.sql') as (w,env):
        script=w/'agency.php';script.write_text(PHP)
        counter=itertools.count(1000);lock=threading.Lock()
        def sql(q):return owned.sql(w,env,q,DB)
        def run(actor,data='',text='',**spec):
            with lock:seq=next(counter)
            spec=dict(actor=actor,data=data,text=text,update=seq,**spec)
            if 'fixed_update' in spec:spec['update']=spec.pop('fixed_update')
            proc=subprocess.run(['php','-d','allow_url_fopen=0','-d','disable_functions=curl_exec,fsockopen,pfsockopen,stream_socket_client,stream_socket_server',
                '-d','log_errors=1','-d','error_log='+str(w/'agency-errors.log'),str(script),str(ROOT),str(w/'mysql.sock'),DB,json.dumps(spec)],
                capture_output=True,text=True,timeout=25)
            assert proc.returncode==0,(proc.stdout,proc.stderr,(w/'agency-errors.log').read_text()[-2000:] if (w/'agency-errors.log').exists() else '')
            return json.loads(proc.stdout)['events'],seq
        def check(label,ok):
            assert ok,label
            checks.append(label)
        def field(user,col):
            return sql(f'SELECT {col} FROM users WHERE userid={user}').strip()
        row=int(field(1001,'id'))
        def ref(gen,kind='request'):return f'agency-{kind}:{row}:{gen}'
        def states(reference):return sql("SELECT recipient,state FROM payment_followup_intents WHERE operational_ref='"+reference+"' ORDER BY recipient").splitlines()
        def outgoing(events,target):return sum(e[0]=='send' and e[1]==target for e in events)
        def removed(events,target):return sum(e[0]=='send' and e[1]==target and bool(json.loads(e[3] or 'null') or {}) and bool(json.loads(e[3]).get('remove_keyboard')) for e in events)
        def start(gen,actor=9001,**kw):return run(actor,f'agencyApprove1001_{gen}',**kw)[0]
        def decline(gen,actor=9001,**kw):return run(actor,f'agencyDecline1001_{gen}',**kw)[0]
        def request(**kw):
            events,gen=run(1001,'requestAgency',**kw);return events,gen
        def due(reference):sql("UPDATE payment_followup_intents SET next_attempt_at=NOW(6) WHERE state='pending' AND operational_ref='"+reference+"'")
        def reset():sql("UPDATE users SET is_agent=0,step='none',temp=NULL WHERE userid=1001;UPDATE users SET step='none',temp=NULL WHERE userid IN (9001,9002)")
        def trigger(ddl):
            code='$c=new mysqli("localhost","root","",$argv[1],0,$argv[2]);$c->query($argv[3]);'
            subprocess.run(['php','-r',code,DB,str(w/'mysql.sock'),ddl],check=True,capture_output=True,timeout=10)
        sql("INSERT INTO users(userid,name,username,refcode,wallet,date,isAdmin,first_start) VALUES(1003,'Other','other','0',0,'1700000000',0,'sent')")
        for strict in (True,False):
            tag='strict' if strict else 'off'
            reset()
            events,gen=request(strict=strict,fail_recipient=9001)
            check(tag+':request_commits_before_send',field(1001,'is_agent')=='2' and outgoing(events,9001)==1)
            check(tag+':independent_original_audience',states(ref(gen))==['9001\tpending','@fixture_report\tacknowledged'])
            due(ref(gen))
            events,_=run(9001,dispatch=ref(gen),strict=strict)
            check(tag+':request_known_failure_recovery_only',outgoing(events,9001)==1 and outgoing(events,'@fixture_report')==0)
            events,_=run(9001,dispatch=ref(gen),strict=strict)
            check(tag+':original_request_not_resent',not events)
            events,_=request(strict=strict)
            check(tag+':already_pending_no_new_report',outgoing(events,9001)==0)
            for bad in ('agencyDecline1001','agencyDecline1001_0','agencyDecline1001_999999999999999999999999','agencyDecline1001_'+str(gen)+'x'):
                run(9001,bad,strict=strict)
                check(tag+':malformed_'+bad,field(1001,'is_agent')=='2')
            decline(gen,actor=1003,strict=strict)
            check(tag+':nonadmin_denied',field(1001,'is_agent')=='2')
            decline(gen,chat_type='group',strict=strict)
            check(tag+':foreign_frame_denied',field(1001,'is_agent')=='2')
            start(gen,strict=strict)
            step=field(9001,'step')
            check(tag+':approval_editor_pinned',step==f'agencyApprove1001_{gen}_77')
            run(9001,'unrelated',text='12',strict=strict)
            check(tag+':callback_text_not_typed',field(1001,'is_agent')=='2' and field(9001,'step')==step)
            run(9001,text='101',strict=strict)
            check(tag+':invalid_discount_no_role_change',field(1001,'is_agent')=='2' and field(9001,'step')==step)
            events,_=run(9001,text='12',strict=strict,fail_recipient=1001)
            check(tag+':decision_committed_with_original_notice',field(1001,'is_agent')=='1' and field(9001,'step')=='none' and
                json.loads(field(1001,'discount_percent'))=={'normal':'12'} and states(ref(gen,'result'))==['1001\tpending'])
            due(ref(gen,'result'))
            events,_=run(9001,dispatch=ref(gen,'result'),strict=strict)
            check(tag+':approval_notice_retry_only',outgoing(events,1001)==1 and states(ref(gen,'result'))==['1001\tacknowledged'])
            decline(gen,strict=strict)
            check(tag+':decided_replay_no_mutation',field(1001,'is_agent')=='1')
            run(9001,'releaseRejectedAgent1001',route='rejected',strict=strict)
            check(tag+':old_rejected_control_cannot_reset_agent',field(1001,'is_agent')=='1')
            run(9001,'editAgentDiscountNormal1001_0',route='discount',strict=strict)
            step=field(9001,'step')
            check(tag+':discount_editor_survives_missing_prompt',step=='editAgentDiscountNormal1001_0_77')
            run(9001,'unrelated',text='50',route='discount',strict=strict)
            check(tag+':discount_callback_not_typed',field(9001,'step')==step and json.loads(field(1001,'discount_percent'))['normal']=='12')
            sql("""UPDATE users SET discount_percent='{"normal":"12","plans":{"7":"5"}}' WHERE userid=1001""")
            run(9001,text='35',route='discount',strict=strict)
            check(tag+':discount_preserves_other_scope',field(9001,'step')=='none' and json.loads(field(1001,'discount_percent'))['normal']=='35' and json.loads(field(1001,'discount_percent'))['plans']=={'7':'5'})
            run(9001,'removeAgent1001',route='discount',strict=strict)
            check(tag+':remove_only_current_agent',field(1001,'is_agent')=='0')
            reset()
            events,new=request(strict=strict)
            decline(gen,strict=strict)
            check(tag+':old_application_cannot_decide_new',field(1001,'is_agent')=='2')
            run(9001,'removeAgent1001',route='discount',strict=strict)
            run(9001,'releaseRejectedAgent1001',route='rejected',strict=strict)
            check(tag+':old_role_controls_cannot_cancel_pending',field(1001,'is_agent')=='2')
            start(new,strict=strict)
            decline(new,actor=9002,strict=strict,fail_recipient=1001,unknown=True)
            events,_=run(9001,text='not a discount',strict=strict)
            check(tag+':decline_wins_and_consumes_stale_editor',field(1001,'is_agent')=='-1' and field(9001,'step')=='none' and outgoing(events,1001)==0 and removed(events,9001)==1)
            events,_=run(9001,dispatch=ref(new,'result'),strict=strict)
            check(tag+':unknown_not_blindly_resent',not events and states(ref(new,'result'))==['1001\tunknown'])
            reset()
            _,gen=request(strict=strict)
            start(gen,strict=strict)
            events=decline(gen,strict=strict,cleanup_fail=True)
            check(tag+':same_actor_decline_retires_editor_independent_cleanup',field(1001,'is_agent')=='-1' and field(9001,'step')=='none' and removed(events,9001)==1 and states(ref(gen,'result'))==['1001\tacknowledged'])
            events=decline(gen,strict=strict)
            check(tag+':decline_replay_no_second_notice_or_cleanup',not removed(events,9001) and outgoing(events,1001)==0)
            reset()
            _,gen=request(strict=strict)
            start(gen,strict=strict)
            stale=field(9001,'step')
            sql("UPDATE users SET step='unrelated_newer_editor',temp='keep' WHERE userid=9001")
            events=decline(gen,strict=strict)
            check(tag+':decline_preserves_unrelated_editor',field(1001,'is_agent')=='-1' and field(9001,'step')=='unrelated_newer_editor' and removed(events,9001)==0)
            events,_=run(9001,text='12',cached_step=stale,strict=strict)
            check(tag+':stale_approval_preserves_newer_editor',field(9001,'step')=='unrelated_newer_editor' and removed(events,9001)==0)
            reset()
            _,gen=request(strict=strict)
            start(gen,strict=strict);start(gen,actor=9002,strict=strict)
            events,_=run(9002,text='12',strict=strict,cleanup_fail=True)
            check(tag+':approval_cleanup_failure_does_not_block_result',field(1001,'is_agent')=='1' and field(9002,'step')=='none' and removed(events,9002)==1 and states(ref(gen,'result'))==['1001\tacknowledged'])
            events=decline(gen,strict=strict)
            expected=subprocess.check_output(['php','-r','require '+json.dumps(str(ROOT/'messages/loader.php'))+';echo achbotMessage("legacy_messages.config_not_found");'],text=True)
            check(tag+':stale_decline_retires_editor_without_false_rejection',field(1001,'is_agent')=='1' and field(9001,'step')=='none' and removed(events,9001)==1 and any(e[0]=='send' and e[1]==9001 and e[2]==expected for e in events) and outgoing(events,1001)==0)
            reset()
            _,gen=request(strict=strict)
            start(gen,strict=strict);start(gen,actor=9002,strict=strict)
            barrier=threading.Barrier(2)
            def save(actor):barrier.wait(timeout=5);return run(actor,text='15',strict=strict)
            with ThreadPoolExecutor(max_workers=2) as pool:
                results=list(pool.map(save,[9001,9002]))
            check(tag+':concurrent_exactly_one_decision',field(1001,'is_agent')=='1' and len(states(ref(gen,'result')))==1)
            due(ref(gen,'result'))
            run(9001,dispatch=ref(gen,'result'),strict=strict)
            check(tag+':concurrent_original_notice_ack',states(ref(gen,'result'))==['1001\tacknowledged'])
            reset()
            _,gen=request(strict=strict);start(gen,strict=strict)
            sql('CREATE TABLE fixture_agency_victim(n INT NOT NULL) ENGINE=MyISAM;INSERT INTO fixture_agency_victim VALUES(0)')
            trigger("""CREATE TRIGGER fixture_agency_deadlock BEFORE UPDATE ON users FOR EACH ROW
                BEGIN IF OLD.userid=1001 AND OLD.is_agent=2 AND NEW.is_agent=1 THEN
                UPDATE fixture_agency_victim SET n=n+1;
                IF (SELECT n FROM fixture_agency_victim LIMIT 1)=1 THEN SIGNAL SQLSTATE '40001' SET MYSQL_ERRNO=1213,MESSAGE_TEXT='fixture victim'; END IF;
                END IF; END""")
            try:
                run(9001,text='12',strict=strict)
                check(tag+':definite_deadlock_retried_before_effect',field(1001,'is_agent')=='1' and
                    sql('SELECT n FROM fixture_agency_victim').strip()=='2' and len(states(ref(gen,'result')))==1)
            finally:sql('DROP TRIGGER fixture_agency_deadlock;DROP TABLE fixture_agency_victim')
            reset()
            _,gen=request(strict=strict);start(gen,strict=strict)
            for errno,sqlstate in ((1213,'40001'),(1205,'HY000')):
                trigger(f"""CREATE TRIGGER fixture_agency_fail BEFORE UPDATE ON users FOR EACH ROW
                    BEGIN IF OLD.userid=1001 AND OLD.is_agent=2 AND NEW.is_agent=1 THEN
                    SIGNAL SQLSTATE '{sqlstate}' SET MYSQL_ERRNO={errno},MESSAGE_TEXT='fixture failure'; END IF; END""")
                try:
                    events,_=run(9001,text='12',strict=strict)
                    check(tag+':sql_failure_preserves_original_pending_'+str(errno),
                        field(1001,'is_agent')=='2' and not states(ref(gen,'result')) and outgoing(events,1001)==0 and
                        field(9001,'step')==f'agencyApprove1001_{gen}_77')
                finally:sql('DROP TRIGGER fixture_agency_fail')
            reset()
            # Original event and state both roll back if notification storage fails.
            trigger("""CREATE TRIGGER fixture_agency_outbox BEFORE INSERT ON payment_followup_intents FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture outbox failure'""")
            try:
                events,gen=request(strict=strict)
                check(tag+':request_outbox_failure_atomic',field(1001,'is_agent')=='0' and not states(ref(gen)) and outgoing(events,9001)==0)
            finally:sql('DROP TRIGGER fixture_agency_outbox')
        # Explicit re-entry of a real pending historical applicant, no bulk replay.
        sql("UPDATE users SET is_agent=2 WHERE userid=1003")
        events,gen=run(1003,'requestAgency')
        check('historical_pending_explicit_current_application',outgoing(events,9001)==1 and field(1003,'is_agent')=='2')
        report={'complete':True,'cases':len(checks),'checks':checks,'schema_tables':35,'production_mutations':0,'external_requests':0}
    if a.report:a.report.write_text(json.dumps(report))
    print(json.dumps(report))
if __name__=='__main__':main()
