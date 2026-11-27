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
import time

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
function wire($kind,$body,$keys,$mode,$to,$mid=null,$reply=null) {
    global $events,$connection,$spec,$from_id;
    if ((int)achbotProviderRow($connection,'SELECT @@in_transaction AS tx')['tx']!==0)
        throw new RuntimeException('fixture_wire_inside_transaction');
    if ($kind==='send' && isset($spec['expected_prompt_step'])) {
        $row=achbotProviderRow($connection,'SELECT step FROM users WHERE userid=?','i',[$from_id]);
        if ($row['step']!==$spec['expected_prompt_step'])
            throw new RuntimeException('fixture_prompt_before_editor_admission');
    }
    $events[]=['kind'=>$kind,'body'=>$body,'keys'=>$keys,'to'=>$to??$from_id,'mode'=>$mode];
    if ($reply!==null) $events[array_key_last($events)]['reply']=$reply;
    if (isset($spec['wire_fail_at']) && $spec['wire_fail_at']===count($events)) {
        return match($spec['wire_failure']??'missing') {
            'missing'=>null,
            'foreign'=>(object)['ok'=>true,'result'=>(object)['message_id'=>999999,'chat'=>(object)['id'=>-123]]],
            'incomplete'=>(object)['ok'=>true,'result'=>(object)['message_id'=>999999]],
            'rate'=>(object)['ok'=>false,'error_code'=>429,'description'=>'Too Many Requests'],
            'throw'=>throw new RuntimeException('fixture_wire_failure'),
        };
    }
    if (($spec['cleanup_fail']??false) && (json_decode($keys??'null',true)['remove_keyboard']??false))
        throw new RuntimeException('fixture_cleanup_failure');
    return (object)['ok'=>true,'result'=>(object)['message_id'=>$mid??100000+count($events),'chat'=>(object)['id'=>$to??$from_id]]];
}
function sendMessage($body,$keys=null,$mode=null,$to=null,$reply=null){return wire('send',$body,$keys,$mode,$to,null,$reply);}
function sendPhoto($file,$body,$keys=null,$mode=null,$to=null,$reply=null){return wire('photo',$body,$keys,$mode,$to,null,$reply);}
function editText($mid,$body,$keys=null,$mode=null,$to=null){return wire('edit',$body,$keys,$mode,$to,$mid);}
function delMessage(...$args){$GLOBALS['events'][]=['kind'=>'delete','args'=>$args];if ($GLOBALS['spec']['delete_fail']??false) throw new RuntimeException('fixture_delete_fail');}
function answerCallbackQuery(...$args){$GLOBALS['events'][]=['kind'=>'ack','body'=>$args[1]??''];}
function alert(...$args){$GLOBALS['events'][]=['kind'=>'ack','body'=>$args[0]??''];}
function getMainKeys(){return '{"inline_keyboard":[]}';}
function getAdminKeys(){return '{"inline_keyboard":[]}';}
require_once $root.'/tools/source_scan.php';
foreach (['achbotAdminWalletIsPendingStep'] as $name)
    eval(achbotExtractFunction($root.'/src/Finance/Routes/AdminWallet.php',$name));
eval(str_replace('__DIR__',var_export($root,true),achbotExtractFunction($root.'/config.php','setUser')));
eval(achbotExtractFunction($root.'/bot.php','achbotWebhookIngressAuthenticated'));
$telegramWebhookSecret='Synthetic_Referral_Settings_2026';
$_SERVER['REQUEST_METHOD']='POST';$_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']=$telegramWebhookSecret;
$first_name='Fixture';$username='fixture';$removeKeyboard='{"remove_keyboard":true}';$botToken='999999:fixture';
eval(section('$cancelKey=json_encode(', '$removeKeyboard =', 'config.php'));
function section($start,$end,$path='bot.php') {
    $file=$GLOBALS['root'].'/'.$path;
    $source=file_get_contents($file);
    $a=strpos($source,$start);$b=strpos($source,$end,$a);
    if ($a===false || $b===false) throw new RuntimeException('fixture_navigation_boundary');
    return str_replace('__DIR__',var_export(dirname($file),true),substr($source,$a,$b-$a));
}
/** Extract the actual single route's if-block using the existing PHP
 * tokenizer. The fixture never reimplements the production conditions. */
function routeBlock(string $path,string $marker): string {
    $file=$GLOBALS['root'].'/'.$path;$source=file_get_contents($file);
    if (substr_count($source,$marker)!==1) throw new RuntimeException('fixture_route_marker');
    $start=strpos($source,$marker);$tokens=achbotTokens($source);
    foreach ($tokens as $i=>$token) if ($token[3]>=$start && $token[0]==='{') {
        $end=$tokens[achbotBodyEnd($tokens,$i)];
        return str_replace('__DIR__',var_export(dirname($file),true),substr($source,$start,$end[3]+strlen($end[1])-$start));
    }
    throw new RuntimeException('fixture_route_block');
}
$userInfo=achbotProviderRow($connection,'SELECT * FROM users WHERE userid=?','i',[$from_id]);
if (isset($spec['cached_step'])) $userInfo['step']=$spec['cached_step'];
if (array_key_exists('cached_temp',$spec)) $userInfo['temp']=$spec['cached_temp'];
$mid=(int)$spec['mid'];$data=$spec['callback']??'';$text=$spec['text']??null;
if ($spec['cancel']??false) $text=achbotMessage('legacy_buttons.cancel');
$sender=(object)['id'=>$from_id];$chat=(object)['id'=>$spec['chat']??$from_id,'type'=>$spec['chat_type']??'private'];
$update=$data!==''?(object)['update_id'=>$spec['update'],'callback_query'=>(object)['data'=>$data,'from'=>$sender,
    'message'=>(object)['chat'=>$chat,'message_id'=>$mid,'from'=>(object)['is_bot'=>true],'text'=>$text]]]:
    (object)['update_id'=>$spec['update'],'message'=>(object)['from'=>$sender,'chat'=>$chat,'message_id'=>$mid,'date'=>$spec['date']??time()]];
if ($data==='' && $text!==null) $update->message->text=$text;
if (isset($spec['photo'])) {$update->message->photo=[(object)['file_id'=>$spec['photo']]];$update->message->caption=$spec['caption']??'';}
if (isset($spec['entities'])) $update->message->entities=json_decode(json_encode($spec['entities']));
$message_id=$mid;$chat_id=$from_id;$customerShortcut=null;$status=false;$result=null;
if ($data==='inviteFriends') {
    $botUsername='fixture_bot';$inviteReward=25;
    $update->callback_query->message->from->id=999999;
    if (!($spec['missing_source_keyboard']??false)) {
        $update->callback_query->message->reply_markup=(object)['inline_keyboard'=>[[(object)['callback_data'=>'inviteFriends']]]];
    }
}
register_shutdown_function(static function() use ($connection,&$status,&$result,&$events){
    $policy=null;$policyError=null;
    try {$policy=achbotPurchaseReferralPolicyAmount($connection);}catch(Throwable $e){$policyError=$e->getMessage();}
    echo json_encode(['handled'=>$status,'events'=>$events,'policy'=>$policy,'policy_error'=>$policyError,'result'=>$result,
        'saved_text'=>achbotMessage('legacy_messages.saved_successfuly'),
        'number_text'=>achbotMessage('legacy_messages.send_only_number'),
        'canceled_text'=>achbotMessage('legacy_messages.operation_canceled'),
        'home_text'=>achbotCurrentMessage('start_message'),
        'user_report_prompt'=>achbotMessage('bot.bot.user_reports.message.b1c793c917'),
        'cancel_keys'=>$GLOBALS['cancelKey']],JSON_THROW_ON_ERROR);
});
// The real ingress permission gate permits a demoted actor to abandon their
// own stale settings editor without granting settings-write authority.
if (!achbotAdminRequestAllowed($data,$text,(string)$userInfo['step'])) {achbotAdminDeny();return;}
// Exercise the actual early Home block before invitation or final Cancel.
if (\AchBot\Telegram\Conversation\homeRequested($update,$from_id)) {
    eval(section('$paymentHomeMatch=[];',"if ((require __DIR__.'/src/Finance/Routes/ManualCheckout.php')"));
    return;
}
// The early actual userReports and other ordinary starts are exercised
// with the same state, authorization, and real setter as bot.php. Later
// route blocks are selected only for their exact callback/typed input.
if (isset($spec['ordinary_route'])) {
    $route=$spec['ordinary_route'];
    $routes=[
        'userReports'=>['bot.php','if ($data == "userReports"'],
        'editRewaredTime'=>['bot.php',"if(preg_match('/^edit(RewaredTime)"],
        'editRewardChannel'=>['bot.php','if($data=="editRewardChannel"'],
        'editLockChannel'=>['bot.php','if ($data == "editLockChannel"'],
        'editSupportID'=>['bot.php','if($data=="editSupportID"'],
        'messageToSpeceficUser'=>['bot.php','if($data=="messageToSpeceficUser"'],
        'addNewMainButton'=>['bot.php','if($data == "addNewMainButton"'],
        'giftToAll'=>['bot.php',"if(preg_match('/^giftToAll"],
        'addNewAdmin'=>['src/Admin/Roles.php',"if ((\$data??null)==='addNewAdmin')"],
        'chooseSendGroup'=>['src/Broadcast/Routes.php',"if(preg_match('/^chooseSendGroup_"],
        'editBroadcastAsk'=>['src/Broadcast/Routes.php',"if(preg_match('/^editBroadcastAsk_"],
        'editCustomPrice'=>['src/Plans/Administration.php',"if(preg_match('/^editCustom(gbPrice|dayPrice)"],
        'wizwizplanname'=>['src/Plans/Administration.php',"if(preg_match('/^wizwizplanname"],
        'wizwizplaneditdes'=>['src/Plans/Administration.php',"if(preg_match('/^wizwizplaneditdes"],
        'wizwizplanrial'=>['src/Plans/Administration.php',"if(preg_match('/^wizwizplanrial"],
        'createAccPlan'=>['src/Purchase/EntryAndBulk.php',"if(preg_match('/\AcreateAccPlan"],
        'messageAdvance'=>['bot.php','if(isset($update->message->text) && !isset($update->callback_query) && $userInfo[\'step\'] == "messageToSpeceficUser"'],
        'buttonAdvance'=>['bot.php','if(isset($update->message->text) && !isset($update->callback_query) && $userInfo[\'step\'] == "addNewMainButton"'],
        'giftAdvance'=>['bot.php',"if(preg_match('/^giftServerDay"],
        'bulkDays'=>['src/Purchase/EntryAndBulk.php',"if(isset(\$update->message->text) && !isset(\$update->callback_query) && preg_match('/^createAccDate"],
        'bulkVolume'=>['src/Purchase/EntryAndBulk.php',"if(isset(\$update->message->text) && !isset(\$update->callback_query) && preg_match('/^createAccVolume"],
    ];
    if ($route==='wallet') require $root.'/src/Finance/Routes/AdminWallet.php';
    else {
        require_once $root.'/src/Purchase/Core.php';
        if (in_array($route,['chooseSendGroup','editBroadcastAsk'],true))
            require_once $root.'/src/Broadcast/Storage.php';
        if (!isset($routes[$route])) throw new RuntimeException('fixture_unknown_ordinary_route');
        eval(routeBlock(...$routes[$route]));
    }
    return;
}
$status=achbotReferralSettingsRoute($connection,$from_id,$update,$userInfo,$cancelKey);
if ($status) return;
if ($data==='inviteFriends') {
    eval(routeBlock('bot.php','if($data=="inviteFriends"){'));
} elseif ($data==='managePanel') {
    eval(section('if($data == "managePanel"','if($data == \'reciveApplications\')'));
} elseif ($spec['cancel']??false) {
    eval(section('// Final ordinary-editor cancellation',"\n?>"));
} elseif ($data==='fixtureOrdinaryEditor') {
    // Invoke the actual generic writer used by ordinary menu editors.
    $result=setUser('fixture_next');
} elseif ($data==='fixtureTopup') {
    require_once $root.'/src/Finance/WalletStart.php';
    $result=achbotWalletTopupAdvance($connection,$from_id,null,
        achbotWalletTopupStage('opening',$update->update_id,$mid),$update->update_id);
} elseif ($data==='fixtureReceipt') {
    require_once $root.'/src/Finance/ManualCheckoutPresentation.php';
    $result=achbotReceiptStepChange($connection,$from_id,$userInfo['step'],'receipt:fixture:0123456789abcdef:0',$update);
}
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
            spec={'actor':actor,'mid':seq,'update':seq,**spec}
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
        sql("INSERT INTO server_categories(id,title,parent,active) VALUES (1,'Category',0,1); "
            "INSERT INTO server_info(id,title,ucount,remark,flag,active,state) VALUES (2,'Server','100','fixture','',1,1); "
            "INSERT INTO server_config(id,panel_url,type,username,password,provider_connection_id,provider_instance_key,provider_version,provider_enabled) VALUES (2,'https://fixture.invalid','pasarguard','fixture','fixture',2,REPEAT('a',32),'5.5.1',1); "
            "INSERT INTO server_plans(id,catid,server_id,title,days,volume,price,descr,active,date,provider_group_ids) VALUES (7,1,2,'Plan',30,2,100,'description',1,'fixture','[1]'); "
            "INSERT INTO send_list(id,type,title,text,state) VALUES (70,'text','Fixture','Old',3)")
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
            # The delayed old input can read the NEW current editor, rather
            # than carrying the old cached generation. Source message alone
            # is insufficient when the same menu survived cosmetic cleanup.
            old_update=int(s[1].split(':')[0])
            r=run(mode,text='654',mid=mid+1,update=old_update+1)
            assert value()=='2147483647' and row()==n and not succeeded(r),r
            r=run(mode,callback='editInviteAmount',mid=mid,update=old_update)
            assert row()==n and not r['events'],r
            case('delayed_old_update_cannot_consume_fresh_generation')
            run(mode,callback='inviteSetting')
            assert row()==['none','']
            # A new update after Telegram's idle sequence reset may carry
            # a lower ID. This is not a lifetime limit on a valid editor.
            g=start(mode)
            gen=int(g[1].split(':')[0])
            sql(f"UPDATE users SET temp='{gen}:{int(time.time())-8*86400}' WHERE userid=9001")
            r=run(mode,text='2147483647',update=1)
            assert value()=='2147483647' and row()==['none',''] and len(saved(r))==1,r
            case('idle_update_sequence_reset_does_not_expire_editor')
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
            bmid=int(n[0].rsplit('_',1)[1])
            fresh=start(mode,action='editInviteBannerText',mid=bmid)
            r=run(mode,photo='old-photo',caption='Old LINK',mid=bmid+1,update=int(n[1].split(':')[0])+1)
            assert row()==fresh and json.loads(value('TEXT'))['text']=='Hello LINK' and not succeeded(r),r
            case('delayed_banner_input_preserves_fresh_generation')
            r=run(mode,photo='fixture-file',caption='Photo LINK')
            assert json.loads(value('TEXT'))=={'type':'photo','caption':'Photo LINK','file_id':'fixture-file'} and row()==['none',''] and len(saved(r))==1,r
            case('banner_text_photo_and_stale_cancel_ownership')
            # The real customer referral route must retain navigation after
            # incomplete/foreign delivery and must never reply to unknown IDs.
            for banner_kind in ('text','photo'):
                start(mode,action='editInviteBannerText')
                run(mode,**({'text':'Invite LINK'} if banner_kind=='text' else {'photo':'fixture-file','caption':'Invite LINK'}))
                first_kind='send' if banner_kind=='text' else 'photo'
                for failure in ('missing','foreign','incomplete','rate','throw'):
                    for fail_at in (1,2):
                        r=run(mode,callback='inviteFriends',wire_fail_at=fail_at,wire_failure=failure)
                        assert [e['kind'] for e in r['events']]==[first_kind]+(['send'] if fail_at==2 else []),r
                        if fail_at==2:assert r['events'][1]['reply']==100001,r
                r=run(mode,callback='inviteFriends',delete_fail=True)
                assert [e['kind'] for e in r['events']]==[first_kind,'send','delete'],r
                assert r['events'][1]['reply']==100001 and r['events'][0]['to']==9001,r
                assert json.loads(r['events'][1]['keys'])['inline_keyboard'][0][0]['callback_data']=='mainMenu',r
                r=run(mode,callback='inviteFriends',missing_source_keyboard=True)
                assert [e['kind'] for e in r['events']]==[first_kind,'send'],r
            case('referral_banner_navigation_requires_both_exact_delivery_acks')

            # Delayed navigation observes a fresh actor row, not a stale
            # cached temp. Reuse the same source message and exact old update.
            for action,kind,payload in (
                ('editInviteAmount','AMOUNT',{'text':'135'}),
                ('editInviteBannerText','TEXT',{'text':'Fresh banner'})):
                previous=start(mode,action=action)
                old_id=int(previous[1].split(':')[0]);old_mid=int(previous[0].rsplit('_',1)[1])
                old_cancel={'cancel':True,'update':old_id+1,'mid':old_mid+1,'date':int(time.time())}
                r=run(mode,**old_cancel)
                assert row()==['none',''] and not succeeded(r),r
                fresh=start(mode,action=action,mid=old_mid)
                before=value(kind)
                assert int(fresh[1].split(':')[0])>old_cancel['update']
                r=run(mode,**old_cancel)
                assert row()==fresh and value(kind)==before and not r['events'],r
                # Older typed and callback Home, as well as admin panel,
                # must not leave the current editor or send a misleading menu.
                for nav in ({'text':'/start'},{'callback':'mainMenu'},
                    {'callback':'navHome'},{'callback':'evidenceHome'},
                    {'callback':'ph_fixture'},{'callback':'managePanel'}):
                    r=run(mode,**nav,update=old_id,mid=old_mid)
                    assert row()==fresh and value(kind)==before and not r['events'],(nav,r)
                r=run(mode,**payload)
                expected='135' if kind=='AMOUNT' else 'Fresh banner'
                actual=value(kind) if kind=='AMOUNT' else json.loads(value(kind))['text']
                assert actual==expected and row()==['none',''] and len(saved(r))==1,r
            case('old_cancel_home_and_panel_preserve_reentered_editors')
            # A higher update on the same old message is intentional fresh
            # navigation. No existence/deletion check is required.
            for nav in ({'cancel':True},{'text':'/start'},{'callback':'navHome'},
                {'callback':'mainMenu'},{'callback':'managePanel'}):
                for cleanup_fail in (False,True):
                    g=start(mode)
                    r=run(mode,**nav,mid=int(g[0].rsplit('_',1)[1]),cleanup_fail=cleanup_fail)
                    assert row()==['none',''],(nav,r)
                    if nav.get('callback')=='managePanel':
                        assert len(r['events'])==2 and r['events'][0]=={
                            'kind':'send','body':r['canceled_text'],'keys':'{"remove_keyboard":true}',
                            'to':9001,'mode':'HTML'} and r['events'][1]['kind']=='edit',(nav,r)
                    else:
                        # The relocated Home cancellation send has exactly
                        # the prior body, parse mode, target and keyboard.
                        # A failed removal must not suppress the next menu.
                        assert r['events']==[
                            {'kind':'send','body':r['canceled_text'],'keys':'{"remove_keyboard":true}','to':9001,'mode':'HTML'},
                            {'kind':'send','body':r['home_text'],'keys':'{"inline_keyboard":[]}','to':9001,'mode':'HTML'}
                        ],(nav,cleanup_fail,r)
            case('fresh_navigation_and_cleanup_failure_are_independent')
            # Old typed message date cannot masquerade as idle-reset input.
            g=start(mode);gen=int(g[1].split(':')[0]);opened=int(time.time())-8*86400
            sql(f"UPDATE users SET temp='{gen}:{opened}' WHERE userid=9001")
            g=row()
            r=run(mode,cancel=True,update=1,date=opened)
            assert row()==g and not r['events'],r
            r=run(mode,cancel=True,update=1,date=int(time.time()))
            assert row()==['none',''] and r['events'],r
            # Broken state must not trap a user or authorize a save.
            g=start(mode);sql("UPDATE users SET temp='broken' WHERE userid=9001")
            before=value();r=run(mode,text='333')
            assert value()==before and row()[1]=='broken' and not succeeded(r),r
            r=run(mode,cancel=True)
            assert row()==['none',''] and r['events'],r
            case('idle_reset_and_malformed_editor_escape')
            for nav in ({'cancel':True},{'callback':'mainMenu'}):
                g=start(mode,9002)
                sql("UPDATE setting SET value='operator' WHERE type='ADMIN_ROLE_9002'")
                before=value();r=run(mode,9002,text='789')
                assert value()==before and row(9002)==g and not succeeded(r),r
                r=run(mode,9002,**nav,update=int(g[1].split(':')[0])-1)
                assert row(9002)==g and not r['events'],r
                r=run(mode,9002,**nav)
                assert row(9002)==['none',''] and r['events'],r
                sql("UPDATE setting SET value='manager' WHERE type='ADMIN_ROLE_9002'")
            case('demoted_actor_can_leave_but_cannot_save')
            # The same ownership boundary applies to ordinary and dedicated
            # editor starts, not to unrelated payments or retained records.
            for callback,expected in (('fixtureOrdinaryEditor','fixture_next'),
                ('fixtureTopup','achbotWalletTopup:opening:'),('fixtureReceipt','receipt:fixture:')):
                g=start(mode);gen=int(g[1].split(':')[0]);mid=int(g[0].rsplit('_',1)[1])
                r=run(mode,callback=callback,mid=mid,update=gen-1)
                assert row()==g and r['result'] in (False,'stale'),(callback,r)
                r=run(mode,callback=callback,mid=mid)
                assert sql('SELECT step FROM users WHERE userid=9001').startswith(expected) and r['result'] in (True,'applied'),(callback,r)
                sql("UPDATE users SET step='none',temp=NULL WHERE userid=9001")
            case('replacement_editor_starts_share_opening_generation')
            # All newly corrected callback starts execute the real route,
            # not a synthetic call to setUser. Each must leave a newer
            # referral editor and its intended next input intact.
            starts=[
                ('userReports','userReports','userReports'),
                ('editRewaredTime','editRewaredTime','editRewaredTime'),
                ('editRewardChannel','editRewardChannel','editRewardChannel'),
                ('editLockChannel','editLockChannel','editLockChannel'),
                ('editSupportID','editSupportID','editSupportID'),
                ('messageToSpeceficUser','messageToSpeceficUser','messageToSpeceficUser'),
                ('addNewMainButton','addNewMainButton','addNewMainButton'),
                ('giftToAll','giftToAll2','giftServerDay2'),
                ('addNewAdmin','addNewAdmin','addNewAdmin'),
                ('chooseSendGroup','chooseSendGroup_all','s2a_title_all'),
                ('editBroadcastAsk','editBroadcastAsk_70','bc_edit_70'),
                ('wallet','increaseUserWallet','increaseUserWallet'),
                ('wallet','decreaseUserWallet','decreaseUserWallet'),
                ('editCustomPrice','editCustomgbPrice','editCustomgbPrice'),
                ('editCustomPrice','editCustomdayPrice','editCustomdayPrice'),
                ('wizwizplanname','wizwizplanname7','wizwizplanname7'),
                ('wizwizplaneditdes','wizwizplaneditdes7','wizwizplaneditdes7'),
                ('wizwizplanrial','wizwizplanrial7','wizwizplanrial7'),
                ('createAccPlan','createAccPlan7','createAccDate7'),
            ]
            for route,callback,expected in starts:
                for action,kind,payload in (
                    ('editInviteAmount','AMOUNT','246'),
                    ('editInviteBannerText','TEXT','Current LINK')):
                    g=start(mode,action=action);gen=int(g[1].split(':')[0]);mid=int(g[0].rsplit('_',1)[1])
                    before=value(kind)
                    r=run(mode,callback=callback,ordinary_route=route,update=gen-1,mid=mid)
                    assert row()==g and value(kind)==before and not r['events'],(route,action,r)
                    r=run(mode,text=payload)
                    assert row()==['none',''] and len(saved(r))==1,(route,action,r)
                    assert (value(kind) if kind=='AMOUNT' else json.loads(value(kind))['text'])==payload
                # Fresh start, including a throwing optional delete, must
                # present the actual prompt only after the accepted state.
                g=start(mode)
                r=run(mode,callback=callback,ordinary_route=route,delete_fail=True,expected_prompt_step=expected)
                sends=[e for e in r['events'] if e['kind']=='send']
                state,generation=row()
                assert state==expected and len(sends)==1,(route,callback,r)
                if route in ('chooseSendGroup','editBroadcastAsk'):
                    event,opened=map(int,generation.split(':'))
                    assert event>gen and opened>0,(route,generation)
                else:assert generation=='',(route,generation)
                assert sends[0]['to']==9001 and sends[0]['keys']==r['cancel_keys'] and sends[0]['body'],(route,r)
                if route=='userReports':assert sends[0]['body']==r['user_report_prompt'],r
                run(mode,cancel=True)
                assert row()==['none',''],(route,row())
            case('actual_ordinary_starts_require_admission_before_prompt')
            # A genuine SQL rejection and cached-row mismatch are distinct
            # from a stale update ID. Neither can display an unadmitted prompt.
            g=start(mode);before=value()
            sql("CREATE TRIGGER fixture_editor_start_fail BEFORE UPDATE ON users FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture editor start failure'")
            try:
                r=run(mode,callback='userReports',ordinary_route='userReports')
                assert row()==g and value()==before and not r['events'],r
            finally:sql('DROP TRIGGER fixture_editor_start_fail')
            r=run(mode,callback='userReports',ordinary_route='userReports',cached_step='none',cached_temp=None)
            assert row()==g and value()==before and not r['events'],r
            r=run(mode,text='357')
            assert row()==['none',''] and value()=='357' and len(saved(r))==1,r
            case('failed_ordinary_start_never_publishes_prompt')
            # Typed advances also must not emit the next prompt if their
            # cached editor has been replaced while the request is in flight.
            advances=[
                ('messageAdvance','messageToSpeceficUser','1001','sendMessageToUser1001'),
                ('buttonAdvance','addNewMainButton','Fixture','setMainButtonAnswerFixture'),
                ('giftAdvance','giftServerDay2','3','giftServerVolume2_3'),
                ('bulkDays','createAccDate7','30','createAccVolume7_30'),
                ('bulkVolume','createAccVolume7_30','2','createAccAmount7_30_2'),
                ('wallet','increaseUserWallet','1001','increaseWalletUser1001'),
            ]
            for route,old_step,typed,expected in advances:
                g=start(mode,action='editInviteBannerText');before=value('TEXT')
                r=run(mode,text=typed,ordinary_route=route,cached_step=old_step,cached_temp=None)
                assert row()==g and value('TEXT')==before and not r['events'],(route,r)
                sql(f"UPDATE users SET step='{old_step}',temp=NULL WHERE userid=9001")
                r=run(mode,text=typed,ordinary_route=route,expected_prompt_step=expected)
                assert row()==[expected,''] and any(e['kind']=='send' for e in r['events']),(route,r)
                sql("UPDATE users SET step='none',temp=NULL WHERE userid=9001")
            case('actual_typed_advances_require_current_editor')
            # The actual persistent keyboard is used at entry. Both kinds
            # and both actor identities must get a removal attempt only
            # after a confirmed panel abandonment, even if cleanup fails.
            for actor in (9001,9002):
                for action in ('editInviteAmount','editInviteBannerText'):
                    for cleanup_fail in (False,True):
                        g=start(mode,actor,action=action)
                        gen=int(g[1].split(':')[0]);mid=int(g[0].rsplit('_',1)[1])
                        old=run(mode,actor,callback='managePanel',update=gen-1,mid=mid)
                        assert row(actor)==g and not old['events'],old
                        r=run(mode,actor,callback='managePanel',mid=mid,cleanup_fail=cleanup_fail)
                        assert row(actor)==['none',''] and len(r['events'])==2,r
                        assert r['events'][0]=={'kind':'send','body':r['canceled_text'],'keys':'{"remove_keyboard":true}','to':actor,'mode':'HTML'},r
                        assert r['events'][1]['kind']=='edit' and r['events'][1]['to']==actor,r
                    idle=run(mode,actor,callback='managePanel')
                    assert row(actor)==['none',''] and len(idle['events'])==1 and idle['events'][0]['kind']=='edit',idle
            case('panel_terminal_cleanup_exact_actor_and_idle_truth')


    result={'complete':True,'cases':len(checks),'checks':checks,'external_requests':0,'production_effects':0}
    if a.report:a.report.write_text(json.dumps(result))
    print(json.dumps(result))
if __name__=='__main__':main()
