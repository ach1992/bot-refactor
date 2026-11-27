#!/usr/bin/env python3
"""Source-real ticket routes and durable notification worker on disposable MariaDB."""
import argparse
from concurrent.futures import ThreadPoolExecutor
import json
from pathlib import Path
import subprocess

import database_fixture as owned

ROOT=Path(__file__).resolve().parents[1]
DB=owned.NAMES[1]
PHP=r"""<?php
declare(strict_types=1);
mysqli_report($argv[4]==='strict'?MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT:MYSQLI_REPORT_OFF);
error_reporting(E_ALL);
set_error_handler(static function($n,$s){throw new ErrorException($s,0,$n);});
$root=$argv[1];$connection=new mysqli('localhost','root','',$argv[3],0,$argv[2]);
$connection->set_charset('utf8mb4');
$admin=9001;$telegramWebhookSecret='Synthetic_Ticket_Secret_2026';
$botState=['rewardChannel'=>'@fixture_channel'];
require $root.'/src/Support/Routes.php';
$spec=json_decode($argv[5],true,512,JSON_THROW_ON_ERROR);
$from_id=(int)($spec['actor']??1001);$callbackId='fixture';
$events=[];$counter=0;
function transportFixture($kind,$body,$keys,$mode,$to,$message=null,$photo=null) {
    global $connection,$events,$counter,$spec,$from_id;
    $to=$to??$from_id;$counter++;
    if ((int)achbotProviderRow($connection,'SELECT @@in_transaction AS tx')['tx']!==0)
        throw new RuntimeException('fixture_wire_inside_transaction');
    $ctx=$GLOBALS['achbotTelegramDiagnosticContext']??null;
    if ($ctx) {
        $f=achbotProviderRow($connection,'SELECT state,attempt_count FROM payment_followup_intents WHERE id=?','i',[$ctx['followup_id']]);
        if (!$f || $f['state']!=='dispatching' || $f['attempt_count']<=0) throw new RuntimeException('fixture_wire_uncommitted');
    }
    $events[]=['kind'=>$kind,'body'=>$body,'keys'=>$keys,'mode'=>$mode,'to'=>$to,'message'=>$message,'photo'=>$photo,'journal'=>$ctx['followup_id']??null];
    if ($ctx && array_key_exists('response',$spec) && (!isset($spec['fail_to']) || $to===$spec['fail_to']))
        return json_decode(json_encode($spec['response']));
    if ($kind==='edit' && isset($spec['edit_response'])) return json_decode(json_encode($spec['edit_response']));
    return (object)['ok'=>true,'result'=>(object)['message_id'=>$message??(100000+$counter),'chat'=>(object)['id'=>(int)$to]]];
}
function sendMessage($body,$keys=null,$mode=null,$to=null) {return transportFixture('send',$body,$keys,$mode,$to);}
function sendPhoto($photo,$body,$keys=null,$mode=null,$to=null) {return transportFixture('photo',$body,$keys,$mode,$to,null,$photo);}
function editText($id,$body,$keys=null,$mode=null,$to=null) {return transportFixture('edit',$body,$keys,$mode,$to,$id);}
function editKeys($keys=null,$id=null,$to=null) {return transportFixture('markup','',$keys,null,$to,$id);}
function delMessage($id=null,$to=null) {$GLOBALS['events'][]=['kind'=>'delete','message'=>$id,'to'=>$to];if ($GLOBALS['spec']['delete_fail']??false) throw new RuntimeException('fixture_delete_failure');}
function alert(...$args) {$GLOBALS['events'][]=['kind'=>'alert','body'=>$args[0]??''];}
function answerCallbackQuery(...$args) {$GLOBALS['events'][]=['kind'=>'ack','body'=>$args[1]??''];}
function getMainKeys(){return '{"inline_keyboard":[]}';}
$cached=achbotProviderRow($connection,'SELECT * FROM users WHERE userid=?','i',[$from_id]);
if (isset($spec['cached_step'])) $cached['step']=$spec['cached_step'];
$mid=(int)($spec['mid']??100);
$sender=(object)['id'=>$from_id,'first_name'=>'Fixture'];
$chat=(object)['id'=>$spec['chat']??$from_id,'type'=>$spec['chat_type']??'private'];
$update=isset($spec['callback'])?(object)['update_id'=>$spec['update']??$mid,'callback_query'=>(object)[
    'data'=>$spec['callback'],'from'=>$sender,'message'=>(object)['from'=>(object)['is_bot'=>true],'chat'=>$chat,'message_id'=>$mid]]]:
    (object)['update_id'=>$spec['update']??$mid,'message'=>(object)['from'=>$sender,'chat'=>$chat,'message_id'=>$mid]];
if (array_key_exists('text',$spec)) $update->message->text=$spec['text'];
if (isset($spec['photo'])) {$update->message->photo=[(object)['file_id'=>$spec['photo']]];$update->message->caption=$spec['caption']??'';}
if (isset($spec['entities'])) $update->message->entities=json_decode(json_encode($spec['entities']));
if (isset($spec['cancel'])) $update->message->text=achbotMessage('legacy_buttons.cancel');
$status=null;
if (isset($spec['dispatch'])) $status=achbotFollowupDispatchOne($connection,(int)$spec['dispatch']);
elseif (isset($spec['maintain'])) $status=achbotFollowupMaintainOriginalPending($connection,20,8.0);
else $status=achbotSupportRoute($connection,$from_id,$update,$cached,'{"keyboard":[]}','{"remove_keyboard":true}');
echo json_encode(['status'=>$status,'events'=>$events],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
"""

def main():
    p=argparse.ArgumentParser(description=__doc__)
    p.add_argument('--run-disposable',action='store_true',required=True)
    p.add_argument('--report',type=Path)
    args=p.parse_args();checks=[]
    with owned.owned_database(ROOT/'database/current-schema.sql') as (work,env):
        driver=work/'support-current.php';driver.write_text(PHP)
        cli=['mariadb','--no-defaults','--protocol=SOCKET','--socket='+str(work/'mysql.sock'),'-uroot','--batch','--skip-column-names',DB]
        def sql(q):
            return subprocess.run(cli,input=q,text=True,encoding='utf-8',env=env,check=True,capture_output=True,timeout=20).stdout.strip()
        sql("INSERT INTO setting(type,value) VALUES ('ADMIN_ROLE_9002','manager'); INSERT INTO users(userid,name,username,refcode,wallet,date,isAdmin,first_start) VALUES(1002,'Other','other','0',0,'1700000000',0,'sent'),(9003,'Support','support','0',0,'1700000000',1,'sent'); INSERT INTO setting(type,value) VALUES ('ADMIN_ROLE_9003','support')")
        def run(mode,**spec):
            r=subprocess.run(['php',str(driver),str(ROOT),str(work/'mysql.sock'),DB,mode,json.dumps(spec,ensure_ascii=False)],env=env,text=True,capture_output=True,timeout=25)
            assert r.returncode==0,(r.stdout,r.stderr)
            assert all(l.startswith(('achbot_','[AChBot] ')) for l in r.stderr.splitlines()),r.stderr
            return json.loads(r.stdout)
        def wire(r):return [x for x in r['events'] if x.get('journal')]
        for mode in ('strict','off'):
            base=1000 if mode=='strict' else 2000
            def case(n):checks.append(mode+':'+n)
            def start(mid,**kw):
                return run(mode,callback='supportCat50',mid=mid,**kw)
            def step(uid=1001):return sql(f'SELECT step FROM users WHERE userid={uid}')
            def last_ticket():return int(sql('SELECT MAX(id) FROM chats WHERE user_id=1001'))
            def msgs(t):return int(sql(f'SELECT COUNT(*) FROM chats_info WHERE chat_id={t}'))
            def notice(ref,who,stage='ticket_notice'):
                return int(sql(f"SELECT id FROM payment_followup_intents WHERE operational_ref='{ref}' AND recipient='{who}' AND stage='{stage}'"))
            sql("UPDATE users SET step='none',temp=NULL WHERE userid IN (1001,9001,9002)")
            r=run(mode,callback='usersNewTicket',mid=base)
            assert 'supportCat50' in r['events'][0]['keys'],r
            assert run(mode,callback='supportCat999',mid=base+1)['status'] and step()=='none'
            start(base+2,delete_fail=True)
            assert step()==f'newTicket_50_{base+2}'
            run(mode,callback='wizwizch',mid=base+3)
            assert step()==f'newTicket_50_{base+2}'
            run(mode,text='not private',mid=base+4,chat_type='group')
            assert step()==f'newTicket_50_{base+2}'
            run(mode,text='Title <literal>',mid=base+5)
            assert step()==f'sendTicket_50_{base+5}'
            case('private_category_title_and_best_effort_cleanup')
            count=int(sql('SELECT COUNT(*) FROM chats'))
            sql("CREATE TRIGGER fixture_ticket_outbox_fail BEFORE INSERT ON payment_followup_intents FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture outbox failure'")
            try:
                r=run(mode,text='Rollback',mid=base+6)
                assert not wire(r) and int(sql('SELECT COUNT(*) FROM chats'))==count and step()==f'sendTicket_50_{base+5}',r
            finally:sql('DROP TRIGGER fixture_ticket_outbox_fail')
            case('atomic_ticket_body_and_outbox_rollback')
            r=run(mode,text='Message <b> & literal',mid=base+7,response=None,fail_to=9001)
            t=last_ticket()
            assert msgs(t)==1 and step()=='none'
            assert [x['to'] for x in wire(r)]==[9001,9002],r
            assert all('fixture_channel' not in str(x['to']) for x in wire(r))
            assert '&lt;b&gt;' in wire(r)[0]['body'] and '&amp;' in wire(r)[0]['body']
            message=int(sql(f'SELECT id FROM chats_info WHERE chat_id={t}'))
            ref=f'ticket:{t}:message:{message}'
            u=notice(ref,9001);v=notice(ref,9002)
            assert sql(f'SELECT state FROM payment_followup_intents WHERE id={u}')=='unknown'
            assert sql(f'SELECT state FROM payment_followup_intents WHERE id={v}')=='acknowledged'
            assert not wire(run(mode,dispatch=u))
            case('durable_private_independent_audience_unknown_not_resent')
            # Replayed typed message cannot be admitted into another wizard.
            run(mode,callback=f'replySupport_{t}',mid=base+6)
            assert step()==f'sendMsg_{t}_{base+6}'
            run(mode,text='Message <b> & literal',mid=base+7)
            assert msgs(t)==1
            assert step()==f'sendMsg_{t}_{base+6}'
            # Another user cannot open the reply wizard or retrieve its history.
            run(mode,actor=1002,callback=f'replySupport_{t}',mid=base+8)
            assert step(1002)=='none'
            assert not [x for x in run(mode,actor=1002,callback=f'latestMsg_c_{t}',mid=base+9)['events'] if x['kind']=='send']
            case('original_message_identity_and_private_ownership')
            # Real staff reply with photo, no reply from Support-only actor.
            run(mode,actor=9003,callback=f'reply_{t}',mid=base+10)
            assert step(9003)=='none'
            run(mode,actor=9002,callback=f'reply_{t}',mid=base+11)
            assert step(9002)==f'answer_{t}_{base+11}'
            r=run(mode,actor=9002,photo='original-file-id',caption='Original <caption>',mid=base+12,
                  response={'ok':False,'error_code':429,'description':'Too Many Requests','parameters':{'retry_after':60}})
            assert msgs(t)==2 and step(9002)=='none' and sql(f'SELECT state FROM chats WHERE id={t}')=='1'
            assert wire(r)[0]['photo']=='original-file-id' and wire(r)[0]['to']==1001
            m=int(sql(f'SELECT MAX(id) FROM chats_info WHERE chat_id={t}'))
            photo_notice=notice(f'ticket:{t}:message:{m}',1001)
            assert sql(f'SELECT state FROM payment_followup_intents WHERE id={photo_notice}')=='pending'
            assert not wire(run(mode,dispatch=photo_notice))
            sql(f'UPDATE payment_followup_intents SET next_attempt_at=NOW(6) WHERE id={photo_notice}')
            assert run(mode,dispatch=photo_notice)['status']=='acknowledged'
            assert not [x for x in run(mode,actor=1002,text=f'/dlPic{m}',mid=base+13)['events'] if x['kind']=='photo']
            assert run(mode,text=f'/dlPic{m}',mid=base+14)['events'][0]['photo']=='original-file-id'
            case('staff_reply_photo_ownership_and_rate_aware_retry')
            # An open ticket with a new customer message is awaiting staff.
            r=run(mode,text='{"file_id":"not-a-photo","caption":"literal"}',mid=base+15)
            assert msgs(t)==3 and sql(f'SELECT state FROM chats WHERE id={t}')=='0'
            assert all(x['kind']=='send' for x in wire(r))
            assert not [x for x in r['events'] if x['kind']=='delete']
            case('literal_json_not_photo_and_reply_reopens_staff_queue')
            run(mode,actor=9002,callback=f'reply_{t}',mid=base+16)
            r=run(mode,callback=f'closeTicket_c_{t}',mid=base+17)
            assert sql(f'SELECT state FROM chats WHERE id={t}')=='2'
            assert [x['to'] for x in wire(r)]==[1001,9001,9002],r
            assert not wire(run(mode,callback=f'closeTicket_c_{t}',mid=base+18))
            run(mode,actor=9002,text='Late reply',mid=base+19)
            assert msgs(t)==3 and sql(f'SELECT state FROM chats WHERE id={t}')=='2'
            case('closed_ticket_cannot_reopen_or_duplicate_rating_prompt')
            r=run(mode,callback=f'rate_{t}_5',mid=base+20,update=base+20)
            assert sql(f'SELECT rate FROM chats WHERE id={t}')=='5' and len(wire(r))==2
            assert not wire(run(mode,callback=f'rate_{t}_5',mid=base+20,update=base+20))
            run(mode,callback=f'rate_{t}_4',mid=base+21,update=base+21)
            assert sql(f'SELECT rate FROM chats WHERE id={t}')=='4'
            run(mode,callback=f'rate_{t}_5',mid=base+20,update=base+20)
            assert sql(f'SELECT rate FROM chats WHERE id={t}')=='4'
            case('rating_changes_and_replay_idempotency')
            # Six current personal tickets, same date, distinct IDs. Manager
            # personal pages remain personal rather than switching to staff.
            for i in range(6):
                sql(f"INSERT INTO chats(user_id,create_date,title,category,state,rate) VALUES(9002,1700000001,'Personal {i}','Technical',0,0)")
            r=run(mode,actor=9002,callback='userAllTickets',mid=base+30)
            assert len([x for x in r['events'] if x['kind']=='send' and ('latestMsg_c_' in (x.get('keys') or '') or 'moreTicket_c_' in (x.get('keys') or ''))])==6
            assert 'moreTicket_c_all_5' in r['events'][-1]['keys']
            r=run(mode,actor=9002,callback='moreTicket_c_all_5',mid=base+31,delete_fail=True)
            assert all('Personal' in x['body'] for x in r['events'] if x['kind']=='send' and 'latestMsg_c_' in (x.get('keys') or '')),r
            assert not [x for x in run(mode,actor=1002,callback='moreTicket_s_all_5',mid=base+32)['events'] if x['kind']=='send']
            case('bounded_stable_personal_and_staff_pagination')
            # Recipient revocation after admission is checked at dispatch.
            sql("UPDATE users SET step='none' WHERE userid=9002")
            start(base+40);run(mode,text='Another',mid=base+41)
            r=run(mode,photo='long-photo',caption='x'*1024,mid=base+42,response=None)
            t2=last_ticket();m2=int(sql(f'SELECT MAX(id) FROM chats_info WHERE chat_id={t2}'))
            ref=f'ticket:{t2}:message:{m2}'
            assert len([x for x in wire(r) if x['kind']=='photo'])==2
            assert all(len(x['body'])<5000 for x in wire(r))
            assert sql(f"SELECT COUNT(*) FROM payment_followup_intents WHERE operational_ref='{ref}' AND recipient='9001'")=='2'
            case('photo_overflow_preserves_media_and_text_parts')
            # Current role check on a definitely rejected original send.
            r=run(mode,callback=f'replySupport_{t2}',mid=base+43)
            run(mode,text='retryable',mid=base+44,response={'ok':False,'error_code':429,'description':'Too Many Requests','parameters':{'retry_after':60}})
            m3=int(sql(f'SELECT MAX(id) FROM chats_info WHERE chat_id={t2}'))
            f=notice(f'ticket:{t2}:message:{m3}',9002)
            sql(f"UPDATE setting SET value='support' WHERE type='ADMIN_ROLE_9002'; UPDATE payment_followup_intents SET next_attempt_at=NOW(6) WHERE id={f}")
            r=run(mode,dispatch=f)
            assert r['status']=='skipped' and not wire(r),r
            sql("UPDATE setting SET value='manager' WHERE type='ADMIN_ROLE_9002'")
            case('current_recipient_revocation_skips_only_original_copy')
            # A stale cached writer must not clear a newer unrelated step.
            sql("UPDATE users SET step='none',temp=NULL WHERE userid=1001")
            start(base+50);run(mode,text='Concurrent title',mid=base+51)
            st=step()
            with ThreadPoolExecutor(max_workers=2) as pool:
                list(pool.map(lambda i:run(mode,text='Concurrent '+str(i),mid=base+52+i,cached_step=st),[0,1]))
            t3=last_ticket()
            assert msgs(t3)==1 and step()=='none'
            case('concurrent_submit_single_ticket')
            # A true concurrent close and staff reply must serialize on the
            # ticket itself, not on unrelated actors or Telegram transport.
            run(mode,actor=9002,callback=f'reply_{t3}',mid=base+60)
            with ThreadPoolExecutor(max_workers=2) as pool:
                a=pool.submit(run,mode,actor=9002,text='Race reply',mid=base+61)
                b=pool.submit(run,mode,callback=f'closeTicket_c_{t3}',mid=base+62)
                a.result();b.result()
            assert sql(f'SELECT state FROM chats WHERE id={t3}')=='2' and msgs(t3) in (1,2)
            assert sql(f"SELECT COUNT(*) FROM payment_followup_intents WHERE operational_ref='ticket:{t3}:close'")== '3'
            case('concurrent_close_reply_linearization')
            # Category input is a fresh private typed message, not the text
            # of a callback. Role and draft ownership are rechecked at commit.
            run(mode,actor=9002,callback='addTicketCategory',mid=base+70)
            st=step(9002)
            assert st==f'addTicketCategory_{base+70}'
            run(mode,actor=9002,callback='wizwizch',mid=base+71)
            run(mode,actor=9002,text='Group category',mid=base+72,chat_type='group')
            assert step(9002)==st
            sql("UPDATE setting SET value='support' WHERE type='ADMIN_ROLE_9002'")
            run(mode,actor=9002,text='Revoked category',mid=base+73)
            assert sql("SELECT COUNT(*) FROM setting WHERE value='Revoked category'")=='0'
            sql("UPDATE setting SET value='manager' WHERE type='ADMIN_ROLE_9002'")
            title=f'Category {mode}'
            with ThreadPoolExecutor(max_workers=2) as pool:
                list(pool.map(lambda _:run(mode,actor=9002,text=title,mid=base+74,cached_step=st),[0,1]))
            assert sql(f"SELECT COUNT(*) FROM setting WHERE type='TICKETS_CATEGORY' AND value='{title}'")=='1'
            assert step(9002)=='none'
            case('category_private_current_role_and_concurrent_create')
            start(base+80);run(mode,text='Cancel draft',mid=base+81)
            st=step()
            sql("UPDATE users SET step='unrelated_newer_editor',temp='must stay' WHERE userid=1001")
            run(mode,cancel=True,mid=base+82,cached_step=st)
            assert step()=='unrelated_newer_editor' and sql("SELECT temp FROM users WHERE userid=1001")=='must stay'
            start(base+83);run(mode,text='Cancel draft',mid=base+84)
            r=run(mode,cancel=True,mid=base+85)
            assert step()=='none' and sql("SELECT temp IS NULL FROM users WHERE userid=1001")=='1'
            assert not [x for x in r['events'] if x['kind']=='delete']
            start(base+86);run(mode,text='Leave draft',mid=base+87)
            run(mode,callback='supportSection',mid=base+88)
            assert step()=='none'
            case('support_cancel_and_menu_do_not_clear_newer_editor')
            # Legacy attachment and literal current text remain distinguishable.
            sql(f"""INSERT INTO chats_info(chat_id,sent_date,msg_type,text)
                VALUES({t3},1700000001,'USER','{{"file_id":"legacy-photo","caption":"old caption"}}')""")
            old=int(sql(f'SELECT MAX(id) FROM chats_info WHERE chat_id={t3}'))
            assert run(mode,text=f'/dlPic{old}',mid=base+90)['events'][0]['photo']=='legacy-photo'
            assert not [x for x in run(mode,actor=1002,text=f'/dlPic{old}',mid=base+91)['events'] if x['kind']=='photo']
            for i in range(10):
                sql(f"INSERT INTO chats_info(chat_id,sent_date,msg_type,text) VALUES({t3},UNIX_TIMESTAMP()+1,'USER',REPEAT('a',4000))")
            r=run(mode,callback=f'latestMsg_c_{t3}',mid=base+92)
            bodies=[x['body'] for x in r['events'] if x['kind']=='send']
            assert len(bodies)>1 and sum(x.count('a') for x in bodies)==40000, (len(bodies),sum(x.count('a') for x in bodies))
            case('legacy_attachment_and_long_history_preserved')

        result={'complete':True,'cases':len(checks),'checks':checks,'production_effects':0,'external_requests':0}
        if args.report:args.report.write_text(json.dumps(result))
        print(json.dumps(result))
if __name__=='__main__':main()
