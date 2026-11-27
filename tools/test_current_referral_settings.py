#!/usr/bin/env python3
"""Actual invitation settings routes and financial policy on disposable MariaDB."""
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
PHP=r"""<?php
declare(strict_types=1);
mysqli_report($argv[4]==='strict'?MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT:MYSQLI_REPORT_OFF);
error_reporting(E_ALL);
set_error_handler(static function($n,$s){throw new ErrorException($s,0,$n);});
$root=$argv[1];$connection=new mysqli('localhost','root','',$argv[3],0,$argv[2]);
$connection->set_charset('utf8mb4');
$spec=json_decode($argv[5],true,64,JSON_THROW_ON_ERROR);
$admin=9001;$from_id=(int)($spec['actor']??9001);$botState=[];$events=[];$callbackId='fixture';
require_once $root.'/src/Admin/ReferralSettings.php';
function wire($kind,$body,$keys,$mode,$to,$mid=null) {
    global $events,$connection,$spec,$from_id;
    if ((int)achbotProviderRow($connection,'SELECT @@in_transaction AS tx')['tx']!==0)
        throw new RuntimeException('fixture_wire_inside_transaction');
    $events[]=['kind'=>$kind,'body'=>$body,'keys'=>$keys,'to'=>$to??$from_id,'mode'=>$mode];
    if (($spec['cleanup_fail']??false) && (json_decode($keys??'null',true)['remove_keyboard']??false))
        throw new RuntimeException('fixture_cleanup_failure');
    return (object)['ok'=>true,'result'=>(object)['message_id'=>$mid??100000+count($events),'chat'=>(object)['id'=>$to??$from_id]]];
}
function sendMessage($body,$keys=null,$mode=null,$to=null){return wire('send',$body,$keys,$mode,$to);}
function sendPhoto($file,$body,$keys=null,$mode=null,$to=null){return wire('photo',$body,$keys,$mode,$to);}
function editText($mid,$body,$keys=null,$mode=null,$to=null){return wire('edit',$body,$keys,$mode,$to,$mid);}
function delMessage(...$args){$GLOBALS['events'][]=['kind'=>'delete','args'=>$args];if ($GLOBALS['spec']['delete_fail']??false) throw new RuntimeException('fixture_delete_fail');}
function answerCallbackQuery(...$args){$GLOBALS['events'][]=['kind'=>'ack','body'=>$args[1]??''];}
function getMainKeys(){return '{"inline_keyboard":[]}';}
$userInfo=achbotProviderRow($connection,'SELECT * FROM users WHERE userid=?','i',[$from_id]);
if (isset($spec['cached_step'])) $userInfo['step']=$spec['cached_step'];
if (array_key_exists('cached_temp',$spec)) $userInfo['temp']=$spec['cached_temp'];
$mid=(int)$spec['mid'];$data=$spec['callback']??'';$text=$spec['text']??null;
if ($spec['cancel']??false) $text=achbotMessage('legacy_buttons.cancel');
$sender=(object)['id'=>$from_id];$chat=(object)['id'=>$spec['chat']??$from_id,'type'=>$spec['chat_type']??'private'];
$update=$data!==''?(object)['update_id'=>$spec['update'],'callback_query'=>(object)['data'=>$data,'from'=>$sender,
    'message'=>(object)['chat'=>$chat,'message_id'=>$mid,'from'=>(object)['is_bot'=>true],'text'=>$text]]]:
    (object)['update_id'=>$spec['update'],'message'=>(object)['from'=>$sender,'chat'=>$chat,'message_id'=>$mid]];
if ($data==='' && $text!==null) $update->message->text=$text;
if (isset($spec['photo'])) {$update->message->photo=[(object)['file_id'=>$spec['photo']]];$update->message->caption=$spec['caption']??'';}
if (isset($spec['entities'])) $update->message->entities=json_decode(json_encode($spec['entities']));
$status=achbotReferralSettingsRoute($connection,$from_id,$update,$userInfo,'{"keyboard":[]}');
if (!$status && ($spec['cancel']??false)) {
    // Exercise the actual generic Cancel route, not a fixture implementation.
    $source=file_get_contents($root.'/bot.php');
    $a=strpos($source,'// Final ordinary-editor cancellation');
    $b=strpos($source,"\n?>",$a);
    if ($a===false || $b===false) throw new RuntimeException('fixture_cancel_boundary');
    eval(substr($source,$a,$b-$a));
}
$policy=null;$policyError=null;
try {$policy=achbotPurchaseReferralPolicyAmount($connection);}catch(Throwable $e){$policyError=$e->getMessage();}
echo json_encode(['handled'=>$status,'events'=>$events,'policy'=>$policy,'policy_error'=>$policyError,
    'saved_text'=>achbotMessage('legacy_messages.saved_successfuly'),
    'number_text'=>achbotMessage('legacy_messages.send_only_number')],JSON_THROW_ON_ERROR);
"""

def main():
    p=argparse.ArgumentParser();p.add_argument('--run-disposable',action='store_true',required=True);p.add_argument('--report',type=Path)
    a=p.parse_args();checks=[]
    with owned.owned_database(ROOT/'database/current-schema.sql') as (w,env):
        driver=w/'referral-settings.php';driver.write_text(PHP)
        counter=itertools.count(1000);lock=threading.Lock()
        def sql(q):return owned.sql(w,env,q,DB).strip()
        def run(mode,actor=9001,**spec):
            with lock:seq=next(counter)
            spec=dict(actor=actor,mid=seq,update=seq,**spec) if 'mid' not in spec else dict(actor=actor,update=seq,**spec)
            r=subprocess.run(['php','-d','allow_url_fopen=0','-d','disable_functions=curl_exec,fsockopen,pfsockopen,stream_socket_client,mail,exec,shell_exec,system,passthru,popen,proc_open',
                str(driver),str(ROOT),str(w/'mysql.sock'),DB,mode,json.dumps(spec)],env=env,text=True,capture_output=True,timeout=25)
            assert r.returncode==0,(r.stdout,r.stderr)
            assert all(line.startswith(('achbot_','[AChBot] ')) for line in r.stderr.splitlines()),r.stderr
            return json.loads(r.stdout)
        def row(actor=9001):
            return sql(f"SELECT CONCAT(step,':',COALESCE(temp,'')) FROM users WHERE userid={actor}").split(':',1)
        def value(kind='AMOUNT'):
            return sql(f"SELECT value FROM setting WHERE type='INVITE_BANNER_{kind}'")
        def count(kind='AMOUNT'):
            return int(sql(f"SELECT COUNT(*) FROM setting WHERE type='INVITE_BANNER_{kind}'"))
        def saved(r):
            return [e for e in r['events'] if e['kind']=='send' and e.get('body')==r['saved_text'] and json.loads(e.get('keys') or 'null')=={'remove_keyboard':True}]
        def succeeded(r):return any(e.get('body')==r['saved_text'] for e in r['events'])
        def start(mode,actor=9001,action='editInviteAmount',**kw):
            r=run(mode,actor,callback=action,**kw)
            assert row(actor)[0].startswith(action+'_'),r
            return row(actor)
        sql("INSERT INTO setting(type,value) VALUES ('ADMIN_ROLE_9002','manager')")
        for mode in ('strict','off'):
            def case(name):checks.append(mode+':'+name)
            sql("DELETE FROM setting WHERE type IN ('INVITE_BANNER_AMOUNT','INVITE_BANNER_TEXT'); UPDATE users SET step='none',temp=NULL WHERE userid IN (9001,9002); INSERT INTO setting(type,value) VALUES('INVITE_BANNER_AMOUNT','100')")
            s=start(mode,delete_fail=True)
            for bad in ('1e3','1.25','-1','2147483648','999999999999999999999999999999999','', '00x'):
                r=run(mode,text=bad)
                assert r['policy']==100 and value()=='100' and row()==s and not succeeded(r),r
            case('invalid_amount_cannot_enter_financial_policy')
            for raw,expected in (('0',0),(' 00075 ',75),('2147483647',2147483647)):
                if row()[0]=='none':start(mode)
                r=run(mode,text=raw,cleanup_fail=(expected==75))
                assert r['policy']==expected and value()==str(expected) and row()==['none',''] and len(saved(r))==1,r
            case('canonical_zero_positive_and_existing_money_bound')
            s=start(mode)
            r=run(mode,cancel=True)
            assert row()==['none',''] and not succeeded(r) and not any(e.get('body')==r['number_text'] for e in r['events']),r
            r=run(mode,text='123',cached_step=s[0],cached_temp=s[1])
            assert value()=='2147483647' and row()==['none',''] and not succeeded(r),r
            # Re-opening the same source has a distinct generation in temp.
            mid=int(s[0].rsplit('_',1)[1])
            n=start(mode,mid=mid)
            assert n!=s and n[0]==s[0],(s,n)
            r=run(mode,text='123',cached_step=s[0],cached_temp=s[1])
            assert row()==n and value()=='2147483647' and not succeeded(r),r
            r=run(mode,text='321',mid=mid-1)
            assert row()==n and value()=='2147483647' and not succeeded(r),r
            case('cancel_and_reentry_fence_delayed_input')
            run(mode,callback='inviteSetting')
            assert row()==['none','']
            s=start(mode,9002)
            sql("UPDATE setting SET value='operator' WHERE type='ADMIN_ROLE_9002'")
            r=run(mode,9002,text='456')
            assert row(9002)==s and value()=='2147483647' and not succeeded(r),r
            sql("UPDATE setting SET value='manager' WHERE type='ADMIN_ROLE_9002'; UPDATE users SET step='none',temp=NULL WHERE userid=9002")
            case('current_role_and_navigation')
            # Both current actor editors may save; singleton creation is
            # serialized across different actors, not merely per user.
            sql("DELETE FROM setting WHERE type='INVITE_BANNER_AMOUNT'")
            start(mode);start(mode,9002)
            with ThreadPoolExecutor(max_workers=2) as pool:
                results=list(pool.map(lambda x:run(mode,x[0],text=x[1]),[(9001,'123'),(9002,'456')]))
            assert count()==1 and value() in ('123','456') and all(len(saved(r))==1 for r in results),results
            assert row()==['none',''] and row(9002)==['none','']
            case('concurrent_singleton_creation_one_setting')
            s=start(mode)
            with ThreadPoolExecutor(max_workers=2) as pool:
                results=list(pool.map(lambda v:run(mode,text=v,cached_step=s[0],cached_temp=s[1]),['789','987']))
            assert count()==1 and value() in ('789','987') and sum(len(saved(r)) for r in results)==1 and row()==['none',''],results
            case('concurrent_same_editor_one_winner')
            before=value();s=start(mode)
            sql("CREATE TRIGGER fixture_referral_fail BEFORE UPDATE ON setting FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture write failure'")
            try:
                r=run(mode,text='100')
                assert value()==before and row()==s and not succeeded(r),r
            finally:sql('DROP TRIGGER fixture_referral_fail')
            sql("INSERT INTO setting(type,value) VALUES ('INVITE_BANNER_AMOUNT','999')")
            r=run(mode,text='100')
            assert count()==2 and row()==s and not succeeded(r) and r['policy_error']=='referral_policy_ambiguous',r
            sql("DELETE FROM setting WHERE type='INVITE_BANNER_AMOUNT' AND value='999'; UPDATE users SET step='none',temp=NULL WHERE userid=9001")
            case('write_failure_and_ambiguous_existing_setting_not_success')
            s=start(mode,action='editInviteBannerText')
            r=run(mode,text='Hello LINK')
            assert row()==['none',''] and json.loads(value('TEXT'))=={'type':'text','text':'Hello LINK'} and len(saved(r))==1,r
            n=start(mode,action='editInviteBannerText')
            run(mode,cancel=True)
            r=run(mode,text='Wrong',cached_step=n[0],cached_temp=n[1])
            assert row()==['none',''] and json.loads(value('TEXT'))['text']=='Hello LINK' and not succeeded(r),r
            start(mode,action='editInviteBannerText')
            r=run(mode,photo='fixture-file',caption='Photo LINK')
            assert json.loads(value('TEXT'))=={'type':'photo','caption':'Photo LINK','file_id':'fixture-file'} and row()==['none',''] and len(saved(r))==1,r
            case('banner_text_photo_and_stale_cancel_ownership')
    result={'complete':True,'cases':len(checks),'checks':checks,'external_requests':0,'production_effects':0}
    if a.report:a.report.write_text(json.dumps(result))
    print(json.dumps(result))
if __name__=='__main__':main()
