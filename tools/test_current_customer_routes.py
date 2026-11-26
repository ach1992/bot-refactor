#!/usr/bin/env python3
"""Current consumer regression for content ownership, catalogue and checkout UI.

All actual route source is used against disposable MariaDB. The Telegram
transport is an in-process recording double; no external network or secrets.
"""
import argparse
from concurrent.futures import ThreadPoolExecutor
import json
from pathlib import Path
import subprocess
import time

import database_fixture as owned
from database_fixture import sql_command

ROOT=Path(__file__).resolve().parents[1]
DB=owned.NAMES[1]
DRIVER=r'''<?php
declare(strict_types=1);
error_reporting(E_ALL);
set_error_handler(static function($n,$s){throw new ErrorException($s,0,$n);});
$root=$argv[1];$dbName=$argv[3];
$connection=new mysqli('localhost','root','',$dbName,0,$argv[2]);
$connection->set_charset('utf8mb4');
require $root.'/src/Purchase/Core.php';
require_once $root.'/src/Finance/CheckoutAdmission.php';
require_once $root.'/src/Admin/Access.php';
require_once $root.'/src/Presentation/MainMenu.php';
require_once $root.'/tools/source_scan.php';
require_once $root.'/src/Telegram/UserProfile.php';
foreach (['setUser','getMainKeys','getMainMenuButtonsKeys','getCategoriesKeys','RandomString'] as $name)
    eval(str_replace('__DIR__',var_export($root,true),achbotExtractFunction($root.'/config.php',$name)));
eval(achbotExtractFunction($root.'/bot.php','achbotWebhookIngressAuthenticated'));
$telegramWebhookSecret='Synthetic_Current_Routes_2026';
$_SERVER['REQUEST_METHOD']='POST';$_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']=$telegramWebhookSecret;
$spec=json_decode($argv[4],true,512,JSON_THROW_ON_ERROR);
$from_id=(int)($spec['actor']??2001);$admin=9001;$chat_id=$from_id;
$botToken='999999:fixture-only';$botUsername='fixture_bot';$callbackId='fixture';$message_id=(int)($spec['message_id']??81);$username='fixture';$first_name='Fixture';
$userInfo=achbotProviderRow($connection,'SELECT * FROM users WHERE userid=?','i',[$from_id])??[];
$botState=json_decode(achbotProviderRow($connection,"SELECT value FROM setting WHERE type='BOT_STATES'")['value'],true);
$robotState=$botState['botState']??'on';$botUrl='https://fixture.invalid/';
$paymentKeys=json_decode(achbotProviderRow($connection,"SELECT value FROM setting WHERE type='PAYMENT_KEYS'")['value'],true)??[];
$nowpaymentsIpnSecret=$spec['ipn_secret']??'fixture-ipn-secret';
if (array_key_exists('settlement_policy',$spec))
    $nowpaymentsSettlementPolicy=$spec['settlement_policy'];
if (isset($spec['stale_state'])) $botState=array_replace($botState,$spec['stale_state']);
if (isset($spec['stale_keys'])) $paymentKeys=array_replace($paymentKeys,$spec['stale_keys']);
$cancelKey=$removeKeyboard='{}';$events=[];$inviteReward=0;$joinLink='https://t.me/fixture';
$channelLock=$spec['forced']??'';$joinMembershipVerified=$spec['member']??false;
$data=$spec['callback']??'';$text=$spec['text']??'';
$chat=(object)['id'=>(int)($spec['chat']??$from_id),'type'=>$spec['chat_type']??'private'];
$sender=(object)['id'=>$from_id,'first_name'=>'Fixture','username'=>'fixture'];
$update=$data!==''?(object)['callback_query'=>(object)['from'=>$sender,'data'=>$data,
    'message'=>(object)['chat'=>$chat,'message_id'=>$message_id,'from'=>(object)['id'=>999999,'is_bot'=>true],
      'reply_markup'=>(object)['inline_keyboard'=>[[(object)['callback_data'=>$data]]]]]]]:
    (object)['message'=>(object)['from'=>$sender,'chat'=>$chat,'message_id'=>(int)($spec['message_id']??82),'text'=>$text]];
$update->update_id=(int)($spec['update_id']??100001);
if (isset($spec['photo']) && isset($update->message)) $update->message->photo=[(object)['file_id'=>$spec['photo']]];
function sendMessage($text,$keys=null,$mode=null,$uid=null){
    $GLOBALS['events'][]=['send',$text,$keys,$mode];
    if (array_key_exists('send_result',$GLOBALS['spec'])) return json_decode(json_encode($GLOBALS['spec']['send_result']));
    $chat=(int)($uid??$GLOBALS['from_id']);
    $next=(int)achbotProviderRow($GLOBALS['connection'],'SELECT COALESCE(MAX(message_id),100) AS n FROM payment_message_refs WHERE chat_id=?','i',[$chat])['n'];
    return (object)['ok'=>true,'result'=>(object)['message_id'=>(int)($GLOBALS['spec']['sent_id']??$next+count($GLOBALS['events'])),'chat'=>(object)['id'=>$chat]]];
}
function editText($id,$text,$keys=null,$mode=null){$GLOBALS['events'][]=['edit',$text,$keys,$mode,$id];
    if (array_key_exists('edit_result',$GLOBALS['spec'])) return json_decode(json_encode($GLOBALS['spec']['edit_result']));
    return (object)['ok'=>true];}
function bot($method,$data=[]){
    if ($method==='editMessageText') return editText($data['message_id'],$data['text'],$data['reply_markup']??null,$data['parse_mode']??null);
    if ($method==='editMessageReplyMarkup') {$GLOBALS['events'][]=['markup',$data['message_id'],$data['reply_markup']??null];return (object)['ok'=>true];}
    throw new RuntimeException('unexpected fixture bot method '.$method);
}
function delMessage(...$a){if (($GLOBALS['spec']['op']??'')==='manual_checkout') $GLOBALS['events'][]=['delete',...$a];return (object)['ok'=>true];}
function sendPhoto($file,$text,$keys=null,$mode=null,$uid=null){$GLOBALS['events'][]=['photo',$text,$keys,$mode,$uid,$file];return (object)['ok'=>true,'result'=>(object)['message_id'=>333,'chat'=>(object)['id'=>(int)$uid]]];}
function sendToAllAdmins(...$a){}
function alert(...$a){$GLOBALS['events'][]=['alert',...$a];}
function answerCallbackQuery(...$a){$GLOBALS['events'][]=['ack',...$a];}
function sendAction(...$a){}
function achbotNotifyAdmins(...$a){$GLOBALS['events'][]=['notice'];return true;}
function fixtureSection($start,$end){
    $source=file_get_contents($GLOBALS['root'].'/bot.php');
    $a=strpos($source,$start);$b=strpos($source,$end,$a);
    if ($a===false || $b===false) throw new RuntimeException('fixture_source_boundary');
    return str_replace('__DIR__',var_export($GLOBALS['root'],true),substr($source,$a,$b-$a));
}
register_shutdown_function(static function(){
    global $events,$connection,$from_id;
    $u=achbotProviderRow($connection,'SELECT step,wallet,first_start FROM users WHERE userid=?','i',[$from_id]);
    echo json_encode(['events'=>$events,'user'=>$u],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
});
if (!achbotAdminRequestAllowed($data,$text,(string)($userInfo['step']??''))) {achbotAdminDeny();return;}
switch($spec['op']){
case 'public':
    eval(fixtureSection("if (preg_match('/\\AshowMainButtonAns", "require __DIR__ . '/src/Agent/Rejected.php'"));
    break;
case 'delete_main':
    eval(fixtureSection("if(preg_match('/\\AdelMainButton", 'if($data == "addNewMainButton"'));
    break;
case 'plan_category':
    require $root.'/src/Plans/Categories.php';break;
case 'ticket':
    require $root.'/src/Support/Categories.php';break;
case 'menu':
    $events[]=['menu',achbotMainMenuForContext($connection,$userInfo,$from_id,$admin,$botState)];break;
case 'capacity':
    eval(fixtureSection('if($data=="availableServers2"', "require __DIR__ . '/src/Agent/Applications.php'"));break;
case 'entry':
    require $root.'/src/Purchase/EntryAndBulk.php';break;
case 'trial_catalogue':
    require $root.'/src/Purchase/TrialCatalogue.php';break;
case 'catalogue':
    require $root.'/src/Purchase/Catalogue.php';break;
case 'standard':
    require $root.'/src/Purchase/SelectPlan.php';break;
case 'seed_order':
    // Disposable Native linkage fixture. Commercial cases exercise the actual
    // issue/fund boundary; no remote creation is simulated as a live result.
    $trial=(bool)($spec['trial']??false);$plan=$trial?14:7;
    $price=$trial?0:100;$remark=$trial?'fixture-trial':'fixture-linked';
    $remote=$trial?12346:12345;
    $connection->begin_transaction();
    $op=null;
    if (!$trial) {
        $op=achbotServiceIssueLocked($connection,$from_id,'fixture','existing-order-'.$from_id,
            ['kind'=>'BUY','variant'=>'standard','price'=>$price,'server_id'=>2,'plan_id'=>$plan,'order_id'=>0,
                'quantity'=>1,'days'=>30,'volume'=>2,'remark'=>$remark,'uuid'=>'','naming_mode'=>'manual','explicit_name'=>true]);
        if (achbotServiceFundLocked($connection,$from_id,$op)!=='funded') throw new RuntimeException('fixture_fund');
    }
    $snap=achbotProviderSnapshot($connection,2,$plan);
    $json=json_encode($snap,JSON_THROW_ON_ERROR);
    $endpoint=$snap['endpoint_sha256'];$key='pg:'.$snap['instance_key'];
    $operation=str_repeat($trial?'c':'b',32);$marker='achbot:v1:'.$operation;
    $created='2026-01-01T00:00:00Z';$expire=time()+86400;
    $entitlement=json_encode(['group_ids'=>[1],'data_limit'=>2147483648,'expire'=>$expire,'status'=>'active']);
    $s=achbotProviderStatement($connection,"INSERT INTO orders_list (userid,token,transid,fileid,server_id,remark,uuid,expire_date,link,amount,status,date) VALUES (?,'fixture','fixture',?,2,?,'fixture-uuid',?,'fixture',?,1,?)",'iisiis',[$from_id,$plan,$remark,$expire,$price,(string)time()]);
    $oid=$s->insert_id;$s->close();
    $s=achbotProviderStatement($connection,"INSERT INTO service_effects(source_kind,source_ref,actor_userid,ordinal,operation_id,effect_kind,target_server_id,target_key,endpoint_sha256,plan_id,remote_username,requested_expire,requested_bytes,request_sha256,state,order_id,verified_at,provider_snapshot,provider_reference_id,provider_connection_id,provider_remote_user_id,provider_remote_created_at,provider_operation_marker,provider_entitlement_snapshot,provider_state,provider_verified_at) VALUES (?,?,?,1,?,'create',2,?,?,?, ?,?,2147483648,?,'local_recorded',?,NOW(),?,?,2,?,?,?,?,'linked',NOW())",
        'ssisssisisisiisss',[$trial?'free_trial':'service_operation',$trial?'trial:'.$from_id:(string)$op,$from_id,$operation,$key,$endpoint,$plan,$remark,$expire,hash('sha256',$json),$oid,$json,$remote,$remote,$created,$marker,$entitlement]);
    $effect=$s->insert_id;$s->close();
    achbotProviderBindOrder($connection,['effect_id'=>(int)$effect,'remote_user_id'=>$remote,'remote_created_at'=>$created],(int)$oid);
    if (!$trial) {
        $s=achbotProviderStatement($connection,"UPDATE service_operations SET stage='completed',next_attempt_at=NULL WHERE id=?",'i',[$op]);$s->close();
    }
    $connection->commit();
    $events[]=['order',$oid];break;
case 'extensions':
    require $root.'/src/Finance/Routes/Extensions.php';break;
case 'topup':
    require_once $root.'/src/Finance/WalletStart.php';
    $events[]=['topup',startWalletPayment($connection,$botUrl,$from_id,
        100000,(string)$userInfo['step'],(int)$update->update_id,$message_id)];
    break;
case 'manual_checkout':
    require $root.'/src/Finance/Routes/ManualCheckout.php';break;
case 'manual_claim':
    $pay=achbotProviderRow($connection,'SELECT * FROM pays WHERE id=?','i',[(int)$spec['pay']]);
    $events[]=['claim',achbotManualClaim($connection,$pay,$spec['method'],$from_id,$spec['network']??null,$spec['mode']??'standard')];
    break;
case 'projection':
    $events[]=['projection',achbotPayUxSyncMessages($connection,(int)$spec['pay'])];break;
case 'payment_status':
    require $root.'/src/Finance/Routes/PrePayment.php';break;
case 'renew':
    require $root.'/src/Finance/Routes/RenewalCheckout.php';break;
case 'discount':
    require $root.'/src/Finance/Routes/DiscountEntry.php';break;
case 'wallet':
    try {$events[]=['wallet',achbotCheckoutWallet($connection,$spec['hash'],$from_id,$spec['mode'], $botState)];}
    catch (DomainException $e) {if (empty($spec['expect_denied'])) throw $e; $events[]=['denied',$e->getMessage()];}
    catch (mysqli_sql_exception $e) {
        // MariaDB snapshot isolation may refuse a locking CURRENT read of
        // a concurrently modified row (ER_CHECKREAD). Only that precise
        // refusal is accepted in the synchronized test, followed by a
        // fresh transaction that must observe the revoked policy.
        if (empty($spec['expect_conflict']) || (int)$e->getCode()!==1020) throw $e;
        $events[]=['snapshot_conflict',(int)$e->getCode()];
    }
    break;
case 'shortcut':
    // Actual current ban and membership/registration/phone/home source.
    eval(fixtureSection("if ((\$userInfo['step'] ?? null) ===", '$checkSpam = checkSpam();'));
    eval(fixtureSection('// "عضو شدم" is a navigation continuation', "if ((require __DIR__.'/src/Finance/Routes/ManualCheckout.php')"));
    break;
default:throw new RuntimeException('unknown fixture route');
}
'''
def main():
    p=argparse.ArgumentParser()
    p.add_argument('--run-disposable',required=True,action='store_true')
    p.add_argument('--report',type=Path)
    a=p.parse_args();cases=[]
    with owned.owned_database(ROOT/'database/current-schema.sql') as (w,env):
        def sql(q):return sql_command(w,env,q,DB).strip()
        driver=w/'customer-routes.php';driver.write_text(DRIVER)
        def run(op,**kw):
            args=['php','-d','allow_url_fopen=0','-d','disable_functions=curl_exec,fsockopen,pfsockopen,stream_socket_client,mail,exec,shell_exec,system,passthru,popen,proc_open',str(driver),str(ROOT),str(w/'mysql.sock'),DB,json.dumps(dict(op=op,**kw))]
            r=subprocess.run(args,env=env,capture_output=True,text=True,timeout=20)
            assert r.returncode==0,(op,kw,r.returncode,r.stdout[-1200:],r.stderr[-1600:])
            for line in r.stderr.splitlines():
                assert op in ('manual_claim','manual_checkout') and line.startswith('[AChBot] '),(op,line)
                diagnostic=json.loads(line[len('[AChBot] '):])
                assert diagnostic['event']=='achbot.finance.checkout_manual_admission_deferred',diagnostic
            return json.loads(r.stdout)
        def callbacks(r):
            out=[]
            for e in r['events']:
                if e[0] in ('send','edit') and len(e)>2 and e[2]:
                    out.extend(b.get('callback_data','') for row in json.loads(e[2]).get('inline_keyboard',[]) for b in row)
                if e[0]=='menu':
                    out.extend(b.get('callback_data','') for row in json.loads(e[1])['inline_keyboard'] for b in row)
            return out
        def state(**kw):
            s=dict(sellState='on',walletState='on',remark='digits',agencyState='on',agencyPlanDiscount='off',plandelkhahState='on',gbPrice=10,dayPrice=20,individualExistence='on',renewAccountState='on',increaseTimeState='on',increaseVolumeState='on')
            s.update(kw);sql("UPDATE setting SET value='"+json.dumps(s)+"' WHERE type='BOT_STATES'")
        sql("INSERT INTO setting(id,type,value) VALUES (1,'BOT_STATES','{}'),(2,'PAYMENT_KEYS','{\"bankAccount\":\"fixture-bank\",\"holderName\":\"fixture-holder\",\"private\":\"PRIVATE_SYNTHETIC\"}'),(3,'MAIN_BUTTONSVisible','Public answer'),(4,'XMAIN_BUTTONSBad','PRIVATE_SYNTHETIC'),(5,'MAINXBUTTONSBad','PRIVATE_SYNTHETIC'),(6,'main_buttonsBad','PRIVATE_SYNTHETIC'),(7,'TICKETS_CATEGORY','Ticket'),(8,'tickets_category','PRIVATE_SYNTHETIC'),(9,'MAIN_BUTTONSDelete','Public second'),(10,'TICKETS_CATEGORY','Ticket second')")
        state()
        for uid,admin,agent in [(2001,0,0),(2002,0,1),(2003,1,0),(2004,1,0)]:
            sql(f"INSERT INTO users(userid,name,username,refcode,wallet,date,isAdmin,is_agent,discount_percent,first_start) VALUES ({uid},'Fixture','fixture','0',1000000,'1700000000',{admin},{agent},'{{\"normal\":0}}','sent')")
        sql("INSERT INTO setting(type,value) VALUES ('ADMIN_ROLE_2003','operator'),('ADMIN_ROLE_2004','support')")
        for id in (2,4,5,6,7,8,999):
            r=run('public',callback=f'showMainButtonAns{id}')
            assert not r['events'],r
        assert run('public',callback='showMainButtonAns3')['events'][0][1]=='Public answer'
        assert 'showMainButtonAns3' in callbacks(run('menu'))
        assert not any(f'showMainButtonAns{i}' in callbacks(run('menu')) for i in (2,4,5,6,7,8))
        cases.append('actual_public_content_namespace_exact_and_no_private_values')
        for actor in (2001,2003,2004):
            for id in (1,2,4,5,6,7,8,10):
                run('delete_main',actor=actor,callback=f'delMainButton{id}')
                assert sql(f'SELECT COUNT(*) FROM setting WHERE id={id}')=='1'
            for id in (1,2,3,4,5,6,8,9):
                run('ticket',actor=actor,callback=f'delTicketCat_{id}')
                assert sql(f'SELECT COUNT(*) FROM setting WHERE id={id}')=='1'
        for actor in (2001,2004):
            run('delete_main',actor=actor,callback='delMainButton9')
            run('ticket',actor=actor,callback='delTicketCat_10')
            assert sql('SELECT COUNT(*) FROM setting WHERE id IN (9,10)')=='2'
        run('delete_main',actor=2003,callback='delMainButton9')
        run('ticket',actor=2003,callback='delTicketCat_10')
        assert sql('SELECT COUNT(*) FROM setting WHERE id IN (9,10)')=='0'
        cases.append('actual_content_deletion_scope_and_staff_capability')
        # Two administrators may open category creation independently. Neither
        # draft creates or deletes shared catalogue rows; typed submission
        # consumes only its own locked step, exactly once.
        sql("UPDATE setting SET value='manager' WHERE type='ADMIN_ROLE_2003'")
        sql("INSERT INTO server_categories(id,title,parent,active) VALUES (100,'Preserved inactive',0,0)")
        for actor in (9001,2003):
            r=run('plan_category',actor=actor,callback='addNewCategory')
            assert r['user']['step']=='addNewCategory',r
        assert sql("SELECT COUNT(*) FROM server_categories")=='1'
        for invalid in ('','x'*51):
            r=run('plan_category',actor=9001,text=invalid)
            assert r['user']['step']=='addNewCategory' and r['events'][0][0]=='send',r
            assert sql("SELECT COUNT(*) FROM server_categories")=='1'
        run('plan_category',actor=9001,callback='wizwizch',text='not typed')
        assert sql("SELECT COUNT(*) FROM server_categories")=='1'
        with ThreadPoolExecutor(max_workers=2) as pool:
            futures=[pool.submit(run,'plan_category',actor=actor,text=title) for actor,title in ((9001,'First category'),(2003,'Second category'))]
            for f in futures:
                r=f.result();assert r['user']['step']=='none',r
        assert sql("SELECT COUNT(*) FROM server_categories WHERE active=1 AND title IN ('First category','Second category')")=='2'
        assert sql("SELECT COUNT(*) FROM server_categories WHERE id=100 AND active=0")=='1'
        run('plan_category',actor=9001,text='First category')
        assert sql("SELECT COUNT(*) FROM server_categories WHERE title='First category'")=='1'
        run('plan_category',actor=2003,callback='addNewCategory')
        sql("UPDATE setting SET value='operator' WHERE type='ADMIN_ROLE_2003'")
        run('plan_category',actor=2003,text='Revoked category')
        assert sql("SELECT COUNT(*) FROM server_categories WHERE title='Revoked category'")=='0'
        sql("UPDATE users SET step='none' WHERE userid=2003")
        cid=int(sql("SELECT id FROM server_categories WHERE title='First category'"))
        run('plan_category',actor=9001,callback=f'wizwizcategoryedit{cid}_0x')
        assert sql("SELECT step FROM users WHERE userid=9001")=='none'
        run('plan_category',actor=9001,callback=f'wizwizcategoryedit{cid}_0')
        run('plan_category',actor=9001,text='Invalid group',chat_type='group')
        assert sql(f"SELECT title FROM server_categories WHERE id={cid}")=='First category'
        run('plan_category',actor=9001,text='Renamed category')
        assert sql(f"SELECT title FROM server_categories WHERE id={cid}")=='Renamed category'
        assert sql("SELECT step FROM users WHERE userid=9001")=='none'
        sql("DELETE FROM server_categories WHERE id>=100")
        cases.append('actual_category_actor_owned_drafts_concurrent_submit_and_revocation')
        # Real supported Native catalogue and intentionally non-saleable controls.
        sql("INSERT INTO server_categories(id,title,parent,active) VALUES (1,'Category',0,1),(2,'Inactive category',0,0)")
        for sid,active,server_state,capacity in [(2,1,1,100),(3,0,1,10),(4,1,2,10),(5,1,1,0),(6,1,1,10)]:
            sql(f"INSERT INTO server_info(id,title,ucount,remark,flag,active,state) VALUES ({sid},'Server {sid}','{capacity}','fixture','',{active},{server_state}); INSERT INTO server_config(id,panel_url,type,username,password,provider_connection_id,provider_instance_key,provider_version,provider_enabled) VALUES ({sid},'https://fixture.invalid','pasarguard','fixture','fixture',{sid},REPEAT('a',32),'5.5.1',1)")
        for pid,sid,cat,active,inbound,groups in [(7,2,1,1,0,'[1]'),(8,2,1,0,0,'[1]'),(9,3,1,1,0,'[1]'),(10,4,1,1,0,'[1]'),(11,5,1,1,0,'[1]'),(12,6,1,1,9,'[1]'),(13,6,2,1,0,'[1]')]:
            sql(f"INSERT INTO server_plans(id,catid,server_id,inbound_id,title,days,volume,price,descr,active,date,provider_group_ids) VALUES ({pid},{cat},{sid},{inbound},'Plan {pid}',30,2,100,'description',{active},'fixture','{groups}')")
        r=run('capacity',callback='availableServers2')
        body=json.dumps(r,ensure_ascii=False)
        assert 'Plan 7' in body and all(f'Plan {i}' not in body for i in range(8,14)),r
        assert [c for c in callbacks(run('entry',callback='buySubscription')) if c.startswith('selectServer')]==['selectServer2_none']
        assert [c for c in callbacks(run('catalogue',callback='selectCategory1_2_none')) if c.startswith('selectPlan')]==['selectPlan7_1_none']
        assert 'availableServers' not in callbacks(run('menu'))
        cases.append('actual_capacity_entry_catalogue_share_current_native_saleability')
        # All references, even inactive plans, prevent category deletion.
        sql("INSERT INTO server_categories(id,title,parent,active) VALUES (90,'Referenced',0,1),(91,'Empty',0,1)")
        sql("UPDATE server_plans SET catid=90 WHERE id=8")
        r=run('plan_category',actor=9001,callback='wizwizcategorydelete90_0')
        assert r['events'][0][0]=='send' and 'دارای پلن' in r['events'][0][1],r
        assert sql("SELECT COUNT(*) FROM server_categories WHERE id=90")=='1'
        run('plan_category',actor=9001,callback='wizwizcategorydelete91_0')
        assert sql("SELECT COUNT(*) FROM server_categories WHERE id=91")=='0'
        sql("UPDATE server_plans SET catid=1 WHERE id=8")
        run('plan_category',actor=9001,callback='wizwizcategorydelete90_0')
        assert sql("SELECT COUNT(*) FROM server_categories WHERE id=90")=='0'
        cases.append('actual_category_delete_preserves_inactive_plan_references')

        # The original card is created by the actual SelectPlan/custom routes;
        # the emitted discount button is then consumed by the real route.
        def latest(uid):
            return sql(f'SELECT id,hash_id,price,agent_count FROM pays WHERE user_id={uid} ORDER BY id DESC LIMIT 1').split('\t')
        def redeem(uid,r,code,mode,back,amount,quantity=1,zero=False,**configuration):
            pid,h,oldprice,count=latest(uid)
            assert 'haveDiscountCheckout_'+pid in callbacks(r),r
            r=run('discount',actor=uid,callback='haveDiscountCheckout_'+pid)
            assert r['user']['step']=='discountCheckout_'+pid,r
            sql(f"INSERT INTO discounts(hash_id,type,amount,expire_date,expire_count,can_use) VALUES ('{code}','percent',{100 if zero else 20},0,2,-1)")
            r=run('discount',actor=uid,text=code,**configuration)
            cb=callbacks(r);prefix={'standard':'payBuy','custom':'payCustom','renew':'payRenew'}[mode]
            assert prefix+'WithWallet'+h in cb and (('dc_'+h if back=='mainMenu' else back) in cb),r
            assert int(latest(uid)[2])==amount and max(1,int(count))==quantity,(r,latest(uid))
            assert sql(f"SELECT expire_count FROM discounts WHERE hash_id='{code}'")=='1'
            # A replayed button cannot reopen a second redemption.
            rr=run('discount',actor=uid,callback='haveDiscountCheckout_'+pid)
            assert rr['user']['step']=='none' and sql(f"SELECT expire_count FROM discounts WHERE hash_id='{code}'")=='1',rr
            funded=run('wallet',actor=uid,hash=h,mode=mode)['events'][0][1]
            assert funded['status']=='funded',funded
            before=sql(f"SELECT COUNT(*) FROM wallet_entries WHERE source_kind='service_debit' AND source_ref='{funded['operation']}'")
            progress=run('projection',actor=uid,pay=int(pid))
            assert any(e[0]=='edit' and 'در حال انجام درخواست شما هستیم' in e[1] for e in progress['events']),progress
            assert sql(f"SELECT COUNT(*) FROM wallet_entries WHERE source_kind='service_debit' AND source_ref='{funded['operation']}'")==before
            quote=json.loads(sql(f"SELECT quote_payload FROM service_operations WHERE id={funded['operation']}"))['request']
            assert quote['price']==amount and quote['plan_id']==7 and quote['quantity']==quantity,quote
            if mode=='custom': assert quote['days']==3 and quote['volume']==2 and quote['remark']=='custom12345',quote
            return r
        original=run('standard',callback='selectPlan7_1_none')
        sql('UPDATE server_plans SET price=250 WHERE id=7')
        redeem(2001,original,'standard','standard','selectCategory1_2_none',80)
        sql('UPDATE server_plans SET price=100 WHERE id=7')
        redeem(2002,run('standard',actor=2002,callback='selectPlan7_1_one'),'agentone','standard','selectCategory1_2_one',80)
        before_bulk=sql('SELECT COUNT(*) FROM pays')
        run('standard',actor=2002,callback='selectPlan7_1_much')
        run('standard',actor=2002,text='2147483648')
        assert sql('SELECT COUNT(*) FROM pays')==before_bulk
        run('standard',actor=2002,callback='selectPlan7_1_much')
        run('standard',actor=2002,text='2147483647')
        assert sql('SELECT COUNT(*) FROM pays')==before_bulk
        cases.append('actual_bulk_quantity_and_amount_fit_storage_without_coercion')
        r=run('standard',actor=2002,callback='selectPlan7_1_much')
        assert r['user']['step']=='selectPlan7_1_much',r
        r=redeem(2002,run('standard',actor=2002,text='3'),'agentbulk','standard','selectCategory1_2_much',240,3)
        assert '3' in json.dumps(r,ensure_ascii=False),r
        cases.append('actual_standard_agent_single_and_bulk_discount_to_wallet_admission')
        sql("UPDATE users SET step='enterCustomPlanName7_1_2_3_none' WHERE userid=2001")
        redeem(2001,run('catalogue',text='custom12345'),'custom','custom','mainMenu',64)
        cases.append('actual_custom_discount_uses_original_plan_units_and_name')
        redeem(2001,run('standard',callback='selectPlan7_1_none'),'zero','standard','selectCategory1_2_none',0,zero=True)
        cases.append('actual_full_discount_zero_price_wallet_admission_and_no_replay')
        r=run('standard',callback='selectPlan7_1_none');pid,h,_,_=latest(2001)
        run('discount',callback='haveDiscountCheckout_'+pid)
        r=run('discount',text='missing-code')
        assert 'payBuyWithWallet'+h in callbacks(r) and latest(2001)[2]=='100',r
        r=run('standard',callback='selectPlan7_1_none');pid,h,_,_=latest(2001)
        run('discount',callback='haveDiscountCheckout_'+pid)
        sql("INSERT INTO discounts(hash_id,type,amount,expire_date,expire_count,can_use) VALUES ('stale','percent',20,0,2,-1)")
        sql('UPDATE server_info SET state=0 WHERE id=2')
        r=run('discount',text='stale')
        assert not callbacks(r) and latest(2001)[2]=='100' and sql("SELECT expire_count FROM discounts WHERE hash_id='stale'")=='2',r
        sql('UPDATE server_info SET state=1 WHERE id=2')
        cases.append('actual_invalid_discount_preserves_card_and_stale_target_consumes_nothing')
        for method in ('walletState','zarinpal','cartToCartState'):
            state(walletState='off',**{method:'on'}) if method!='walletState' else state()
            assert 'selectServer2_none' in callbacks(run('shortcut',text='/buy')),method
            assert 'selectServer2_none' in callbacks(run('entry',callback='buySubscription')),method
        state(gatewayAudience={'wallet':'agents'})
        assert 'selectServer2_none' in callbacks(run('shortcut',text='/buy'))
        assert 'selectServer2_none' in callbacks(run('entry',callback='buySubscription'))
        assert 'agentOneBuy' in callbacks(run('shortcut',actor=2002,text='/buy'))
        state(requirePhone='on')
        r=run('shortcut',text='/buy')
        assert 'selectServer2_none' not in callbacks(r) and 'request_contact' in json.dumps(r),r
        state()
        assert run('shortcut',text='/mysubs')['events'], 'owner scoped empty subscriptions'
        oid=run('seed_order')['events'][0][1]
        assert f'orderDetails{oid}' in callbacks(run('shortcut',text='/mysubs'))
        assert f'orderDetails{oid}' not in callbacks(run('shortcut',actor=2002,text='/mysubs'))
        r=run('renew',callback=f'renewAccount{oid}')
        redeem(2001,r,'renew','renew','mainMenu',80)
        cases.append('actual_owned_renewal_discount_and_subscription_shortcut')
        # Deny new commands for an actual trial even if its template or
        # historical amount/agent flag changes. No provider call is permitted.
        sql("INSERT INTO server_plans(id,catid,server_id,inbound_id,title,days,volume,price,descr,active,date,provider_group_ids) SELECT 14,catid,server_id,inbound_id,'Trial fixture',days,volume,0,descr,active,date,provider_group_ids FROM server_plans WHERE id=7")
        trial=run('seed_order',trial=True)['events'][0][1]
        before=sql("SELECT COUNT(*) FROM pays")
        for phase in (0,1):
            if phase:
                sql(f"UPDATE orders_list SET amount=100,agent_bought=1 WHERE id={trial}; UPDATE server_plans SET price=100 WHERE id=14")
            for route,callback in [('renew',f'renewAccountAsk{trial}'),('renew',f'renewAccount{trial}'),('extensions',f'increaseADay{trial}'),('extensions',f'increaseAVolume{trial}')]:
                r=run(route,callback=callback)
                assert not callbacks(r),r
                assert sql("SELECT COUNT(*) FROM pays")==before
        sql(f"UPDATE orders_list SET amount=0,agent_bought=0 WHERE id={trial}; UPDATE server_plans SET price=0,active=0 WHERE id=14")
        for flag,route,callback in [('renewAccountState','renew',f'renewAccount{oid}'),('increaseTimeState','extensions',f'increaseADay{oid}'),('increaseVolumeState','extensions',f'increaseAVolume{oid}'),('sellState','renew',f'renewAccount{oid}')]:
            state(**{flag:'off'})
            r=run(route,callback=callback)
            assert not callbacks(r) and sql("SELECT COUNT(*) FROM pays")==before,(flag,r)
        state()
        r=run('renew',callback=f'renewAccount{oid}')
        pid,h,*_=latest(2001)
        assert 'payRenewWithWallet'+h in callbacks(r),r
        state(renewAccountState='off')
        before_wallet=sql("SELECT wallet FROM users WHERE userid=2001")
        before_ops=sql("SELECT COUNT(*) FROM service_operations")
        r=run('wallet',hash=h,mode='renew',expect_denied=True)
        assert any(e[0]=='denied' for e in r['events']),r
        assert sql("SELECT wallet FROM users WHERE userid=2001")==before_wallet and sql("SELECT COUNT(*) FROM service_operations")==before_ops
        state()
        cases.append('managed_trial_policy_and_revoked_draft_denied_before_effect')


        assert 'selectServer2_none' not in callbacks(run('shortcut',text='/buy',forced='@fixture',member=False))
        assert 'haveJoined/start buy' in callbacks(run('shortcut',text='/buy',forced='@fixture',member=False))
        assert 'selectServer2_none' in callbacks(run('shortcut',callback='haveJoined/start buy',forced='@fixture',member=True))
        state(botState='off')
        assert 'selectServer2_none' not in callbacks(run('shortcut',text='/buy'))
        state()
        sql("UPDATE users SET step='banned' WHERE userid=2001")
        assert 'selectServer2_none' not in callbacks(run('shortcut',text='/buy'))
        sql("UPDATE users SET step='none' WHERE userid=2001")
        r=run('shortcut',actor=2019,text='/start buy')
        assert r['user'] and 'selectServer2_none' in callbacks(r),r
        cases.append('actual_shortcuts_and_callback_share_purchase_methods_and_entry_gates')
        # Trial-only category and server, with an explicit custom purpose.
        sql("INSERT INTO server_categories(id,title,parent,active) VALUES (3,'Trial category',0,1)")
        sql("INSERT INTO server_info(id,title,ucount,remark,flag,active,state) VALUES (8,'Trial server','10','fixture','',1,1); INSERT INTO server_config(id,panel_url,type,username,password,provider_connection_id,provider_instance_key,provider_version,provider_enabled) VALUES (8,'https://fixture.invalid','pasarguard','fixture','fixture',8,REPEAT('b',32),'5.5.1',1)")
        sql("INSERT INTO server_plans(id,catid,server_id,inbound_id,title,days,volume,price,descr,active,date,provider_group_ids) VALUES (80,3,8,0,'Trial only',1,1,0,'description',1,'fixture','[1]'),(81,1,2,0,'Mixed trial',1,1,0,'description',1,'fixture','[1]'),(82,3,2,0,'Only trial category',1,1,0,'description',1,'fixture','[1]')")
        state(plandelkhahState='off',testAccount='on')
        assert 'selectServer8_none' not in callbacks(run('entry',callback='buySubscription'))
        assert 'selectCategory3_2_none' not in callbacks(run('catalogue',callback='selectServer2_none'))
        assert 'selectPlan81_1_none' not in callbacks(run('catalogue',callback='selectCategory1_2_none'))
        assert 'freeTrial80_normal' in callbacks(run('trial_catalogue',callback='getTestAccount'))
        assert 'freeTrial81_normal' in callbacks(run('trial_catalogue',callback='getTestAccount'))
        assert 'freeTrial7_normal' not in callbacks(run('trial_catalogue',callback='getTestAccount'))
        assert 'Trial only' not in json.dumps(run('capacity',callback='availableServers2'))
        before=sql('SELECT COUNT(*) FROM pays')
        run('standard',callback='selectPlan80_3_none')
        assert sql('SELECT COUNT(*) FROM pays')==before
        state(plandelkhahState='on')
        assert 'selectServer8_none' not in callbacks(run('entry',callback='buySubscription'))
        assert 'selectCategory3_2_none' not in callbacks(run('catalogue',callback='selectServer2_none'))
        assert 'selectCustomPlan3_8_none' not in callbacks(run('catalogue',callback='selectCategory3_8_none'))
        assert 'selectCustomePlan80_3_none' not in callbacks(run('catalogue',callback='selectCustomPlan3_8_none'))
        mixed=callbacks(run('catalogue',callback='selectCustomPlan1_2_none'))
        assert 'selectCustomePlan7_1_none' in mixed and 'selectCustomePlan81_1_none' not in mixed,mixed
        assert 'selectServer8_much' not in callbacks(run('entry',actor=2002,callback='agentMuchBuy'))
        stock=run('capacity',callback='availableServers2')
        assert stock['events'][0][1]=='📊 موجودی سرویس‌ها:',stock
        rows=json.loads(stock['events'][0][2])['inline_keyboard']
        assert len(rows[0])==3 and rows[1][1]['text']=='Plan 7' and rows[1][2]['text']=='Server 2',rows
        assert 'Trial only' not in json.dumps(stock) and 'Mixed trial' not in json.dumps(stock),stock
        menu=run('menu')
        assert any(b['text']=='📊 موجودی سرویس‌ها' and b.get('callback_data')=='availableServers2' for row in json.loads(menu['events'][0][1])['inline_keyboard'] for b in row),menu
        assert 'availableServers' not in callbacks(menu)
        state(individualExistence='off')
        assert 'availableServers2' not in callbacks(run('menu'))
        assert not run('capacity',callback='availableServers2')['events']
        state(remark='manual')
        sql("UPDATE users SET step='none' WHERE userid IN (2001,2002)")
        assert run('standard',callback='selectPlan80_3_none')['user']['step']=='none'
        assert run('standard',actor=2002,callback='selectPlan81_1_much')['user']['step']=='none'
        assert run('catalogue',callback='selectCustomePlan81_1_none')['user']['step']=='none'
        sql("UPDATE users SET step='enterCustomPlanName81_1_2_3_none' WHERE userid=2001")
        run('catalogue',text='custom12345')
        assert sql('SELECT COUNT(*) FROM pays')==before
        state()
        sql("UPDATE users SET step='none' WHERE userid=2001")
        cases.append('actual_trial_only_and_mixed_catalogue_purpose_and_custom_gate')
        # A previously displayed commercial quote must not buy a plan which
        # has since become a dedicated trial. Neither path may reserve funds.
        for mode in ('standard','custom'):
            if mode=='custom':
                sql("UPDATE users SET step='enterCustomPlanName7_1_2_3_none' WHERE userid=2001")
                r=run('catalogue',text='custom12345')
            else:
                r=run('standard',callback='selectPlan7_1_none')
            _,h,_,_=latest(2001)
            prefix='payBuy' if mode=='standard' else 'payCustom'
            assert prefix+'WithWallet'+h in callbacks(r),r
            queries=['SELECT COUNT(*) FROM service_operations','SELECT COUNT(*) FROM service_effects','SELECT COUNT(*) FROM wallet_entries','SELECT ucount FROM server_info WHERE id=2','SELECT wallet,freetrial FROM users WHERE userid=2001','SELECT COUNT(*) FROM orders_list']
            snapshot=tuple(sql(q) for q in queries)
            sql('UPDATE server_plans SET price=0 WHERE id=7')
            denied=run('wallet',hash=h,mode=mode,expect_denied=True)
            assert denied['events'][0]==['denied','service_plan_unavailable'],denied
            assert tuple(sql(q) for q in queries)==snapshot
            sql('UPDATE server_plans SET price=100 WHERE id=7')
        cases.append('actual_trial_conversion_refuses_existing_unfunded_standard_and_custom_drafts')

        # Configured zero-price agent and primary owner use exact service
        # command, not trial entitlement or a fabricated monetary debit.
        state(walletState='off')
        sql("UPDATE users SET discount_percent='{\"normal\":100}' WHERE userid=2002")
        # No monetary method is enabled. Authorized zero standard and
        # custom offers remain selectable throughout the catalogue.
        assert 'selectServer2_none' in callbacks(run('entry',callback='buySubscription'))
        assert 'selectServer2_one' in callbacks(run('entry',actor=2002,callback='agentOneBuy'))
        assert 'selectServer2_much' in callbacks(run('entry',actor=2002,callback='agentMuchBuy'))
        assert 'selectServer2_none' in callbacks(run('entry',actor=9001,callback='buySubscription'))
        assert 'selectCategory1_2_one' in callbacks(run('catalogue',actor=2002,callback='selectServer2_one'))
        cb=callbacks(run('catalogue',actor=2002,callback='selectCategory1_2_one'))
        assert 'selectPlan7_1_one' in cb and 'selectCustomPlan1_2_one' in cb,cb
        assert 'selectPlan7_1_none' in callbacks(run('catalogue',callback='selectCategory1_2_none'))
        cases.append('zero_commercial_catalogue_without_any_monetary_method')
        def free_checkout(uid,r,count):
            pid,h,price,qty=latest(uid)
            assert int(price)==0 and max(1,int(qty))==count,r
            assert 'payBuyWithWallet'+h in callbacks(r),r
            assert not any(x.startswith('freeTrial') for x in callbacks(r)),r
            before=sql(f'SELECT wallet,freetrial FROM users WHERE userid={uid}')
            debit_count=sql('SELECT COUNT(*) FROM wallet_entries')
            funded=run('wallet',actor=uid,hash=h,mode='standard')['events'][0][1]
            assert funded['status']=='funded',funded
            quote=json.loads(sql(f"SELECT quote_payload FROM service_operations WHERE id={funded['operation']}"))['request']
            assert quote['price']==0 and quote['quantity']==count and quote['plan_id']==7,quote
            assert sql(f'SELECT debit_entry_id IS NULL FROM service_operations WHERE id={funded["operation"]}')=='1'
            assert sql('SELECT COUNT(*) FROM wallet_entries')==debit_count
            assert sql(f'SELECT wallet,freetrial FROM users WHERE userid={uid}')==before
            assert run('wallet',actor=uid,hash=h,mode='standard')['events'][0][1]['status']=='already_funded'
        free_checkout(2002,run('standard',actor=2002,callback='selectPlan7_1_one'),1)
        free_checkout(2002,run('standard',actor=2002,callback='selectPlan7_1_none'),1)
        run('standard',actor=2002,callback='selectPlan7_1_much')
        free_checkout(2002,run('standard',actor=2002,text='3'),3)
        free_checkout(9001,run('standard',actor=9001,callback='selectPlan7_1_none'),1)
        cases.append('actual_zero_agent_single_bulk_and_primary_commercial_no_money_or_trial')
        # An originally authorized zero commercial draft is not a grant after
        # its configured agency entitlement changes before reservation.
        r=run('standard',actor=2002,callback='selectPlan7_1_one')
        _,h,price,_=latest(2002)
        assert int(price)==0 and 'payBuyWithWallet'+h in callbacks(r),r
        before=tuple(sql(q) for q in ['SELECT COUNT(*) FROM service_operations','SELECT COUNT(*) FROM service_effects','SELECT COUNT(*) FROM wallet_entries','SELECT ucount FROM server_info WHERE id=2','SELECT wallet,freetrial FROM users WHERE userid=2002'])
        for value in [50,"100junk"]:
            sql("UPDATE users SET discount_percent='"+json.dumps({'normal':value})+"' WHERE userid=2002")
            denied=run('wallet',actor=2002,hash=h,mode='standard',expect_denied=True)
            assert denied['events'][0]==['denied','checkout_zero_commercial_changed'],denied
            assert before==tuple(sql(q) for q in ['SELECT COUNT(*) FROM service_operations','SELECT COUNT(*) FROM service_effects','SELECT COUNT(*) FROM wallet_entries','SELECT ucount FROM server_info WHERE id=2','SELECT wallet,freetrial FROM users WHERE userid=2002'])
        cases.append('zero_commercial_current_agency_authorization_rechecked_before_reservation')
        # A committed revocation wins before the actual reservation even
        # when the original draft is valid and the callback is already waiting.
        # Hold the real affected row, prove lock contention, then COMMIT.
        changes=[
            ("UPDATE users SET is_agent=0 WHERE userid=2002",
             "UPDATE users SET is_agent=1 WHERE userid=2002"),
            ("UPDATE server_plans SET price=0 WHERE id=7",
             "UPDATE server_plans SET price=100 WHERE id=7"),
            ("UPDATE setting SET value=JSON_SET(value,'$.agencyState','off') WHERE type='BOT_STATES'",
             "UPDATE setting SET value=JSON_SET(value,'$.agencyState','on') WHERE type='BOT_STATES'"),
        ]
        sql("""UPDATE users SET discount_percent='{"normal":100}' WHERE userid=2002""")
        client=['mariadb','--no-defaults','--protocol=SOCKET','--socket='+str(w/'mysql.sock'),'-uroot','--batch','--skip-column-names','--unbuffered',DB]
        for index,(revoke,restore) in enumerate(changes):
            holder=subprocess.Popen(client,env=env,stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
            try:
                holder.stdin.write("START TRANSACTION; "+revoke+"; SELECT 'locked';\n");holder.stdin.flush()
                assert holder.stdout.readline().strip()=='locked'
                with ThreadPoolExecutor(max_workers=1) as pool:
                    waiting=pool.submit(run,'wallet',actor=2002,hash=h,mode='standard',expect_denied=True,expect_conflict=True)
                    try:
                        end=time.monotonic()+5
                        while time.monotonic()<end:
                            processes=sql("SHOW FULL PROCESSLIST")
                            expected=('idx_users_userid_v1','category_active','LOCK IN SHARE MODE')[index]
                            if any(expected in line and ('FOR UPDATE' in line or 'LOCK IN SHARE MODE' in line)
                                   for line in processes.splitlines()):break
                            assert not waiting.done(),waiting.result()
                            time.sleep(.05)
                        else:raise AssertionError(('reservation_did_not_reach_locked_query',index,processes))
                    finally:
                        holder.stdin.write("COMMIT;\n");holder.stdin.flush()
                    denied=waiting.result(timeout=15)
                    assert denied['events'][0] in (['denied','checkout_zero_commercial_changed'],['snapshot_conflict',1020]),denied
                    assert before==tuple(sql(q) for q in ['SELECT COUNT(*) FROM service_operations','SELECT COUNT(*) FROM service_effects','SELECT COUNT(*) FROM wallet_entries','SELECT ucount FROM server_info WHERE id=2','SELECT wallet,freetrial FROM users WHERE userid=2002'])
                    fresh=run('wallet',actor=2002,hash=h,mode='standard',expect_denied=True)
                    assert fresh['events'][0]==['denied','checkout_zero_commercial_changed'],fresh
                assert before==tuple(sql(q) for q in ['SELECT COUNT(*) FROM service_operations','SELECT COUNT(*) FROM service_effects','SELECT COUNT(*) FROM wallet_entries','SELECT ucount FROM server_info WHERE id=2','SELECT wallet,freetrial FROM users WHERE userid=2002'])
            finally:
                holder.stdin.close()
                holder.wait(timeout=5)
                sql(restore)
        cases.append('zero_commercial_synchronized_role_plan_policy_revocation_before_reservation')
        # The custom wizard shares the price calculator and the original
        # monetary method gate. Raw zero must be proven from configured
        # rates or the current agency entitlement; redeemed discounts are
        # separately authorized before consumption.
        state()
        sql("""UPDATE users SET discount_percent='{"normal":100}' WHERE userid=2002""")
        sql("UPDATE users SET step='enterCustomPlanName7_1_2_3_one' WHERE userid=2002")
        r=run('catalogue',actor=2002,text='agencyzero12345')
        pid,h,price,_=latest(2002)
        assert int(price)==0 and 'payCustomWithWallet'+h in callbacks(r),r
        assert json.loads(sql(f"SELECT checkout_draft_context FROM pays WHERE id={pid}"))['zero_custom']=='agency'
        state(agencyState='off')
        denied=run('wallet',actor=2002,hash=h,mode='custom',expect_denied=True)
        assert denied['events'][0]==['denied','checkout_zero_custom_changed'],denied
        state()
        sql("""UPDATE users SET discount_percent='{"normal":50}' WHERE userid=2002""")
        denied=run('wallet',actor=2002,hash=h,mode='custom',expect_denied=True)
        assert denied['events'][0]==['denied','checkout_zero_custom_changed'],denied
        sql("""UPDATE users SET discount_percent='{"normal":100}' WHERE userid=2002""")
        before=sql("SELECT wallet,freetrial FROM users WHERE userid=2002")
        ledger=sql("SELECT COUNT(*) FROM wallet_entries")
        funded=run('wallet',actor=2002,hash=h,mode='custom')['events'][0][1]
        assert funded['status']=='funded',funded
        assert sql(f"SELECT debit_entry_id IS NULL FROM service_operations WHERE id={funded['operation']}")=='1'
        assert sql("SELECT wallet,freetrial FROM users WHERE userid=2002")==before
        assert sql("SELECT COUNT(*) FROM wallet_entries")==ledger
        state(gbPrice=0,dayPrice=0)
        sql("UPDATE users SET step='enterCustomPlanName7_1_2_3_none' WHERE userid=2001")
        r=run('catalogue',text='configzero12345')
        pid,h,price,_=latest(2001)
        assert int(price)==0 and 'payCustomWithWallet'+h in callbacks(r),r
        assert json.loads(sql(f"SELECT checkout_draft_context FROM pays WHERE id={pid}"))['zero_custom']=='configured_rates'
        state(gbPrice=1,dayPrice=0)
        denied=run('wallet',hash=h,mode='custom',expect_denied=True)
        assert denied['events'][0]==['denied','checkout_zero_custom_changed'],denied
        state(gbPrice=0,dayPrice=0,walletState='off')
        assert 'selectServer2_none' in callbacks(run('entry',callback='buySubscription'))
        assert 'selectCustomPlan1_2_none' in callbacks(run('catalogue',callback='selectCategory1_2_none'))
        assert 'selectPlan7_1_none' in callbacks(run('catalogue',callback='selectCategory1_2_none'))
        funded=run('wallet',hash=h,mode='custom')['events'][0][1]
        assert funded['status']=='funded',funded
        assert sql(f"SELECT debit_entry_id IS NULL FROM service_operations WHERE id={funded['operation']}")=='1'
        assert run('wallet',hash=h,mode='custom')['events'][0][1]['status']=='already_funded'
        state()
        sql("UPDATE users SET step='selectCustomPlanGB7_1_none' WHERE userid=2001")
        assert run('catalogue',text='1e2')['user']['step']=='selectCustomPlanGB7_1_none'
        assert run('catalogue',text='2')['user']['step']=='selectCustomPlanDay7_1_2_none'
        assert run('catalogue',text='3')['user']['step']=='enterCustomPlanName7_1_2_3_none'
        cases.append('zero_custom_configured_rates_and_agency_without_monetary_methods')
        state(testAccount='on',renewAutoState='on')
        r=run('standard',callback='selectPlan7_1_none')
        pid,h,price,_=latest(2001)
        assert int(price)>0 and 'payBuyWithWallet'+h in callbacks(r),r
        before=tuple(sql(q) for q in ['SELECT COUNT(*) FROM service_operations','SELECT COUNT(*) FROM wallet_entries','SELECT ucount FROM server_info WHERE id=2','SELECT wallet FROM users WHERE userid=2001'])
        sql('UPDATE server_plans SET active=1,price=0 WHERE id=14')
        state(sellState='off',testAccount='on',renewAutoState='on')
        denied=run('wallet',hash=h,mode='standard',expect_denied=True)
        assert denied['events'][0]==['denied','checkout_sales_disabled'],denied
        assert tuple(sql(q) for q in ['SELECT COUNT(*) FROM service_operations','SELECT COUNT(*) FROM wallet_entries','SELECT ucount FROM server_info WHERE id=2','SELECT wallet FROM users WHERE userid=2001'])==before
        assert 'buySubscription' not in callbacks(run('menu'))
        assert 'getTestAccount' in callbacks(run('menu'))
        assert 'freeTrial14_normal' in callbacks(run('trial_catalogue',callback='getTestAccount'))
        state(sellState='off',testAccount='off',renewAutoState='on')
        assert 'getTestAccount' not in callbacks(run('menu'))
        assert 'buySubscription' in callbacks(run('menu',actor=9001))
        for actor in (2001,2002,2003,2004):
            assert not set(callbacks(run('menu',actor=actor))) & {'buySubscription','agentOneBuy','agentMuchBuy'}
            assert 'increaseMyWallet' in callbacks(run('menu',actor=actor))
            assert not any(x.startswith('selectServer') for x in callbacks(run('entry',actor=actor,callback='buySubscription')))
            assert not any(x.startswith('selectServer') for x in callbacks(run('shortcut',actor=actor,text='/buy')))
            assert not callbacks(run('catalogue',actor=actor,callback='selectCategory1_2_none'))
        assert 'selectServer2_none' in callbacks(run('entry',actor=9001,callback='buySubscription'))
        cases.append('sales_closure_denies_unissued_positive_quote_preserves_independent_trial_switch')
        # The original valid code is consumed atomically even when all
        # monetary methods are disabled after the positive draft was issued.
        state()
        run('standard',callback='selectPlan7_1_none')
        pid,h,price,_=latest(2001)
        sql("INSERT INTO discounts(hash_id,type,amount,expire_date,expire_count,can_use) VALUES ('nomoneyfull','percent',100,0,1,-1)")
        state(walletState='off')
        run('discount',callback=f'haveDiscountCheckout_{pid}')
        r=run('discount',text='nomoneyfull')
        assert 'payBuyWithWallet'+h in callbacks(r),r
        assert any(b.get('text')=='📥 دریافت رایگان' for e in r['events'] if e[0]=='send' and e[2] for row in json.loads(e[2])['inline_keyboard'] for b in row),r
        assert sql(f"SELECT price FROM pays WHERE id={pid}")=='0'
        before=sql("SELECT COUNT(*) FROM wallet_entries")
        op=run('wallet',hash=h,mode='standard')['events'][0][1]
        assert op['status']=='funded' and sql("SELECT COUNT(*) FROM wallet_entries")==before
        assert sql(f"SELECT COUNT(*) FROM payment_attempts WHERE pay_row_id={pid}")=='0'
        cases.append('full_discount_zero_confirmation_without_wallet_or_gateway')
        # Fresh discount discovery works for each existing commercial route
        # with all monetary methods off, or an ineligible method audience.
        no_method_notice='\u26a0\ufe0f \u0647\u06cc\u0686 \u0631\u0648\u0634 \u067e\u0631\u062f\u0627\u062e\u062a\u06cc \u0628\u0631\u0627\u06cc \u0627\u06cc\u0646 \u0633\u0641\u0627\u0631\u0634 \u062f\u0631 \u062f\u0633\u062a\u0631\u0633 \u0646\u06cc\u0633\u062a. \u062f\u0631 \u0627\u06cc\u0646 \u0634\u0631\u0627\u06cc\u0637 \u0641\u0642\u0637 \u06a9\u062f \u062a\u062e\u0641\u06cc\u0641\u06cc \u06a9\u0647 \u0645\u0628\u0644\u063a \u0646\u0647\u0627\u06cc\u06cc \u0631\u0627 \u0635\u0641\u0631 \u06a9\u0646\u062f \u0642\u0627\u0628\u0644 \u0627\u0633\u062a\u0641\u0627\u062f\u0647 \u0627\u0633\u062a.'
        def buttons(r):
            return [b for e in r['events'] if e[0] in ('send','edit') and len(e)>2 and e[2]
                for row in json.loads(e[2]).get('inline_keyboard',[]) for b in row]
        def no_money_card(r,pid):
            cb=callbacks(r)
            assert 'haveDiscountCheckout_'+str(pid) in cb,r
            assert not any(b.get('url') or b.get('callback_data','').startswith(('payBuyWith','payCustomWith','payRenewWith','pm_','mcm_')) for b in buttons(r)),r
            assert sum(no_method_notice in e[1] for e in r['events'] if e[0]=='send')==1,r
        def reject_partial(uid,r,tag,discount_type='percent',amount=20,maximum=None,stale_state=None,stale_keys=None,**configuration):
            pid,h,price,_=latest(uid)
            no_money_card(r,pid)
            run('discount',actor=uid,callback='haveDiscountCheckout_'+pid)
            max_value='NULL' if maximum is None else str(maximum)
            sql(f"INSERT INTO discounts(hash_id,type,amount,expire_date,expire_count,can_use,max_discount) VALUES ('{tag}','{discount_type}',{amount},0,2,-1,{max_value})")
            queries=[f"SELECT * FROM pays WHERE id={pid}",f"SELECT * FROM discounts WHERE hash_id='{tag}'",
                'SELECT COUNT(*) FROM wallet_entries','SELECT COUNT(*) FROM service_operations',
                'SELECT COUNT(*) FROM payment_attempts','SELECT id,ucount FROM server_info ORDER BY id']
            # Message tracking may change after rendering; compare actual
            # immutable draft/discount and financial fields instead.
            queries[0]=f"SELECT price,checkout_draft_context,state,checkout_operation_id FROM pays WHERE id={pid}"
            snapshot=tuple(sql(q) for q in queries)
            extra=dict(configuration)
            if stale_state is not None:extra['stale_state']=stale_state
            if stale_keys is not None:extra['stale_keys']=stale_keys
            rr=run('discount',actor=uid,text=tag,**extra)
            no_money_card(rr,pid)
            assert not any(e[0]=='notice' for e in rr['events']),rr
            assert tuple(sql(q) for q in queries)==snapshot,(tag,rr)
            assert rr['user']['step']=='none',rr
            return rr
        state(walletState='off')
        sql("UPDATE users SET discount_percent='{\"normal\":0}' WHERE userid=2002")
        for uid,mode,back,quantity,tag in [(2001,'standard','selectCategory1_2_none',1,'freshordinary'),
                (2002,'standard','selectCategory1_2_one',1,'freshagent'),
                (2002,'standard','selectCategory1_2_much',3,'freshbulk'),
                (2001,'custom','mainMenu',1,'freshcustom'),
                (2002,'custom','mainMenu',1,'freshagentcustom')]:
            if mode=='custom':
                role='one' if uid==2002 else 'none'
                assert 'selectCustomPlan1_2_'+role in callbacks(run('catalogue',actor=uid,callback='selectCategory1_2_'+role))
                run('catalogue',actor=uid,callback='selectCustomePlan7_1_'+role)
                run('catalogue',actor=uid,text='2')
                run('catalogue',actor=uid,text='3')
                r=run('catalogue',actor=uid,text='custom12345')
            elif quantity>1:
                run('standard',actor=uid,callback='selectPlan7_1_much')
                r=run('standard',actor=uid,text=str(quantity))
            else:
                r=run('standard',actor=uid,callback='selectPlan7_1_'+('one' if uid==2002 else 'none'))
            pid,h,price,_=latest(uid)
            assert int(price)>0,(tag,r)
            before=sql(f"SELECT wallet,freetrial FROM users WHERE userid={uid}")
            ledger=sql("SELECT COUNT(*) FROM wallet_entries")
            r=reject_partial(uid,r,tag+'partial','amount',1)
            r=reject_partial(uid,r,tag+'capped','percent',100,1)
            r=redeem(uid,r,tag+'full',mode,back,0,quantity,True)
            assert sql(f"SELECT wallet,freetrial FROM users WHERE userid={uid}")==before
            assert sql("SELECT COUNT(*) FROM wallet_entries")==ledger
            assert sql(f"SELECT COUNT(*) FROM payment_attempts WHERE pay_row_id={pid}")=='0'
            assert run('wallet',actor=uid,hash=h,mode=mode)['events'][0][1]['status']=='already_funded'
        cases.append('fresh_ordinary_agent_bulk_custom_discount_only_routes_atomic_partial_refusal')
        # A configured but audience-denied method is not available.
        state(gatewayAudience={'wallet':'agents'})
        r=run('standard',callback='selectPlan7_1_none')
        r=reject_partial(2001,r,'audiencedeniedpartial',stale_state={'gatewayAudience':{'wallet':'all'}})
        redeem(2001,r,'audiencedeniedfull','standard','selectCategory1_2_none',0,zero=True)
        # A stale request's enabled gateway flag cannot consume a positive
        # discount after the current policy was switched off.
        state(walletState='off')
        r=run('standard',callback='selectPlan7_1_none')
        r=reject_partial(2001,r,'staleflagpartial',stale_state={'walletState':'on'})
        redeem(2001,r,'staleflagfull','standard','selectCategory1_2_none',0,zero=True)
        # A configured flag without its actual credential does not count.
        state(walletState='off',zarinpal='on')
        r=run('standard',callback='selectPlan7_1_none')
        r=reject_partial(2001,r,'missingmerchantpartial',stale_keys={'zarinpal':'fixture-removed'})
        redeem(2001,r,'missingmerchantfull','standard','selectCategory1_2_none',0,zero=True)
        cases.append('current_audience_flag_and_credential_not_stale_request_snapshot')
        state(walletState='off')
        # Existing service renewal also supports discount-only checkout.
        r=run('renew',callback=f'renewAccount{oid}')
        pid,h,price,_=latest(2001)
        assert int(price)>0 and 'haveDiscountCheckout_'+pid in callbacks(r),r
        assert 'payRenewWithWallet'+h not in callbacks(r),r
        r=reject_partial(2001,r,'nomoneyrenewpartial')
        before=sql("SELECT COUNT(*) FROM wallet_entries")
        before_wallet=sql("SELECT wallet FROM users WHERE userid=2001")
        r=redeem(2001,r,'nomoneyrenew','renew','mainMenu',0,zero=True)
        assert any(b.get('text')=='✅ تمدید رایگان' for e in r['events'] if e[0]=='send' and e[2] for row in json.loads(e[2])['inline_keyboard'] for b in row),r
        assert sql("SELECT COUNT(*) FROM wallet_entries")==before
        assert sql("SELECT wallet FROM users WHERE userid=2001")==before_wallet
        assert sql(f"SELECT COUNT(*) FROM payment_attempts WHERE pay_row_id={pid}")=='0'
        op=run('wallet',hash=h,mode='renew')['events'][0][1]
        assert op['status']=='already_funded' and sql(f"SELECT debit_entry_id IS NULL FROM service_operations WHERE id={op['operation']}")=='1'
        cases.append('fresh_renewal_full_discount_without_monetary_methods')
        state()

        # Active representative pricing must not depend on route or historical
        # classification. Assert the actual persisted user checkout drafts.
        sql("UPDATE users SET step='none',discount_percent='{\"normal\":25,\"plans\":{\"7\":40},\"servers\":{\"2\":30}}' WHERE userid=2002")
        for plan_scope,percent in [('off',30),('on',40)]:
            state(agencyPlanDiscount=plan_scope)
            for route in ('none','one'):
                r=run('standard',actor=2002,callback=f'selectPlan7_1_{route}')
                assert int(latest(2002)[2])==100-percent,(route,r,latest(2002))
                sql(f"UPDATE users SET step='enterCustomPlanName7_1_2_3_{route}' WHERE userid=2002")
                r=run('catalogue',actor=2002,text='uniform12345')
                assert int(latest(2002)[2])==2*(10-10*percent//100)+3*(20-20*percent//100),(route,r)
            run('standard',actor=2002,callback='selectPlan7_1_much')
            run('standard',actor=2002,text='3')
            assert int(latest(2002)[2])==300-3*percent
        state(agencyState='off')
        run('standard',actor=2002,callback='selectPlan7_1_none')
        assert int(latest(2002)[2])==100
        before=sql("SELECT COUNT(*) FROM pays")
        run('standard',actor=2002,callback='selectPlan7_1_one')
        assert sql("SELECT COUNT(*) FROM pays")==before
        state()
        sql("UPDATE users SET is_agent=1,discount_percent='{\"normal\":25}' WHERE userid=2001")
        for old_flag in (0,1):
            sql(f"UPDATE orders_list SET agent_bought={old_flag} WHERE id={oid}")
            r=run('renew',callback=f'renewAccount{oid}')
            assert int(latest(2001)[2])==75,r
        sql("UPDATE users SET is_agent=0 WHERE userid=2001")
        r=run('renew',callback=f'renewAccount{oid}')
        assert int(latest(2001)[2])==100,r
        cases.append('actual_current_agency_discount_standard_bulk_custom_and_renewal')
        base_keys={'bankAccount':'fixture-bank','holderName':'fixture-holder','private':'PRIVATE_SYNTHETIC'}
        methods=[
            ('walletState','wallet',{},'payBuyWithWallet'),
            ('cartToCartState','bank_transfer',{},'pm_b_'),
            ('giftCardState','gift_card',{'giftCardUrl':'https://fixture.invalid/gift'},'pm_g_'),
            ('manualUsdtState','manual_usdt',{'manualUsdtWalletBEP20':'0x'+'a'*40,'manualUsdtRateToman':'1000'},'mcm_'),
            ('manualUsdtState','manual_trx',{'manualTrxWallet':'T9yD14Nj9j7xAB4dbGeiX9h8unkKHxuWwb','manualTrxRateToman':'1000'},'mcm_'),
            ('zarinpal','zarinpal',{'zarinpal':'fixture-merchant'},'zarinpal'),
            ('nextpay','nextpay',{'nextpay':'fixture-merchant'},'nextpay'),
            ('nowPaymentOther','nowpayments',{'nowpayment':'fixture-merchant','manualUsdtRateToman':'1000'},'nowpayment'),
        ]
        for index,(flag,method,keys,prefix) in enumerate(methods):
            sql("UPDATE setting SET value='"+json.dumps(base_keys|keys)+"' WHERE type='PAYMENT_KEYS'")
            state(**({'walletState':'off',flag:'on'} if flag!='walletState' else {'walletState':'on'}))
            r=run('standard',callback='selectPlan7_1_none')
            pid,h,price,_=latest(2001)
            def method_visible(r):
                if method in ('zarinpal','nextpay','nowpayments'):
                    return any('?'+prefix+'&hash_id='+h in b.get('url','') for b in buttons(r))
                return prefix+h in callbacks(r)
            assert method_visible(r), (method,r)
            code='methodpositive'+str(index)
            sql(f"INSERT INTO discounts(hash_id,type,amount,expire_date,expire_count,can_use) VALUES ('{code}','percent',20,0,2,-1)")
            run('discount',callback='haveDiscountCheckout_'+pid)
            rr=run('discount',text=code)
            assert method_visible(rr) and latest(2001)[2]=='80',(method,rr)
            assert sql(f"SELECT expire_count FROM discounts WHERE hash_id='{code}'")=='1'
            assert not any(no_method_notice in e[1] for e in rr['events'] if e[0]=='send'),rr
        # TRX-only configuration must not become usable via the USDT audience.
        state(walletState='off',manualUsdtState='on',gatewayAudience={'manual_trx':'off','manual_usdt':'all'})
        sql("UPDATE setting SET value='"+json.dumps(base_keys|methods[4][2])+"' WHERE type='PAYMENT_KEYS'")
        r=run('standard',callback='selectPlan7_1_none')
        r=reject_partial(2001,r,'trxaudiencepartial')
        redeem(2001,r,'trxaudiencefull','standard','selectCategory1_2_none',0,zero=True)
        sql("UPDATE setting SET value='"+json.dumps(base_keys)+"' WHERE type='PAYMENT_KEYS'")
        state()
        cases.append('all_eight_configured_methods_positive_discount_and_crypto_audience_exactness')
        # Invalid destination types are unavailable, never an implicit
        # string conversion into a bank recipient or crypto destination.
        state(walletState='off',cartToCartState='on',giftCardState='on',manualUsdtState='on')
        invalid_keys=base_keys|{'bankAccount':['invalid'],'holderName':['invalid'],
            'giftCardUrl':['invalid'],'manualUsdtWalletBEP20':['invalid'],
            'manualTrxWallet':['invalid'],'manualUsdtRateToman':'1000','manualTrxRateToman':'1000'}
        sql("UPDATE setting SET value='"+json.dumps(invalid_keys)+"' WHERE type='PAYMENT_KEYS'")
        r=run('standard',callback='selectPlan7_1_none')
        r=reject_partial(2001,r,'malformedmethodpartial')
        redeem(2001,r,'malformedmethodfull','standard','selectCategory1_2_none',0,zero=True)
        sql("UPDATE setting SET value='"+json.dumps(base_keys)+"' WHERE type='PAYMENT_KEYS'")
        state()
        cases.append('malformed_payment_destinations_do_not_block_free_or_consume_partial_discount')
        # Wallet recharge uses the same local admission checks, but its own
        # NOWPayments flag and no Wallet self-payment.
        def assert_topup_methods(expected,**configuration):
            before=int(sql("SELECT COUNT(*) FROM pays"))
            sql(f"UPDATE users SET step='achbotWalletTopup:amount:100000:81:{int(time.time())}' WHERE userid=2001")
            rr=run('topup',**configuration)
            assert ['topup','created'] in rr['events'],rr
            assert int(sql("SELECT COUNT(*) FROM pays"))==before+1,rr
            pid,h,price,_=latest(2001)
            assert price=='100000',rr
            actual=set()
            for b in buttons(rr):
                cb=b.get('callback_data','');url=b.get('url','')
                if cb=='dc_'+h:continue
                elif cb=='pm_b_'+h:actual.add('bank_transfer')
                elif cb=='pm_g_'+h:actual.add('gift_card')
                elif cb=='mcm_'+h:actual.add('crypto')
                else:
                    urls={f'https://fixture.invalid/pay/checkout.php?{flag}&hash_id={h}':m
                        for flag,m in [('nowpayment','nowpayments'),('zarinpal','zarinpal'),('nextpay','nextpay')]}
                    assert url in urls,(b,rr)
                    actual.add(urls[url])
            assert actual==expected and 'dc_'+h in callbacks(rr),(expected,actual,rr)
            assert not any(x.startswith('haveDiscountCheckout_') for x in callbacks(rr)),rr
        for flag,method,keys,_ in methods:
            sql("UPDATE setting SET value='"+json.dumps(base_keys|keys)+"' WHERE type='PAYMENT_KEYS'")
            state(walletState='off',**{flag:'on'}) if flag!='walletState' else state()
            expected={'crypto' if method in ('manual_usdt','manual_trx') else method}
            if method=='nowpayments':
                assert_topup_methods(set())
                state(walletState='off',nowPaymentWallet='on')
                assert_topup_methods(expected)
            else:
                assert_topup_methods(set() if method=='wallet' else expected)
        cases.append('configured_methods_shared_by_independent_topup_no_wallet_self_payment')
        state(walletState='off',zarinpal='on',nextpay='on',nowPaymentOther='on',nowPaymentWallet='on')
        sql("UPDATE setting SET value='"+json.dumps(base_keys)+"' WHERE type='PAYMENT_KEYS'")
        assert_topup_methods(set(),stale_keys={'zarinpal':'stale','nextpay':'stale','nowpayment':'stale','manualUsdtRateToman':'1000'})
        state(walletState='off',nowPaymentOther='on',nowPaymentWallet='on')
        sql("UPDATE setting SET value='"+json.dumps(base_keys|methods[-1][2])+"' WHERE type='PAYMENT_KEYS'")
        assert_topup_methods(set(),ipn_secret='')
        assert_topup_methods(set(),settlement_policy={'outcome_currency':'usdttrc20','minimum_outcome_per_usd':'0'})
        assert_topup_methods({'nowpayments'},settlement_policy={'outcome_currency':'usdttrc20','minimum_outcome_per_usd':'0.8'})
        sql("UPDATE setting SET value='"+json.dumps(base_keys|{'nowpayment':'fixture-merchant','manualUsdtRateToman':'1000000000'})+"' WHERE type='PAYMENT_KEYS'")
        assert_topup_methods(set())
        for index,denied,allowed in [(4,'manual_trx','manual_usdt'),(3,'manual_usdt','manual_trx')]:
            sql("UPDATE setting SET value='"+json.dumps(base_keys|methods[index][2])+"' WHERE type='PAYMENT_KEYS'")
            state(walletState='off',manualUsdtState='on',gatewayAudience={denied:'off',allowed:'all'})
            assert_topup_methods(set())
        cases.append('topup_invalid_config_audience_and_quote_unavailable')

        # Explicit null, malformed nested types, invalid asset/rate and zero
        # rate must all behave exactly like the existing hosted quote validator.
        # No partial code consumption or financial state change on rejection.
        state(walletState='off',nowPaymentOther='on')
        sql("UPDATE setting SET value='"+json.dumps(base_keys|methods[-1][2])+"' WHERE type='PAYMENT_KEYS'")
        policies=[None,{}, {'outcome_currency':['usdttrc20'],'minimum_outcome_per_usd':'0.8'},
            {'outcome_currency':'usdttrc20','minimum_outcome_per_usd':['0.8']},
            {'outcome_currency':'!','minimum_outcome_per_usd':'0.8'},
            {'outcome_currency':'usdttrc20','minimum_outcome_per_usd':'0'}]
        for i,policy in enumerate(policies):
            r=run('standard',callback='selectPlan7_1_none',settlement_policy=policy)
            r=reject_partial(2001,r,'npinvalid'+str(i),settlement_policy=policy)
            if i==0:
                redeem(2001,r,'npinvalidfull','standard','selectCategory1_2_none',0,zero=True,settlement_policy=policy)
        for i,config in enumerate([{}, {'settlement_policy':{'outcome_currency':'USDTTRC20','minimum_outcome_per_usd':'0.8'}}]):
            r=run('standard',callback='selectPlan7_1_none',**config)
            pid,h,_,_=latest(2001)
            assert any('?nowpayment&hash_id='+h in b.get('url','') for b in buttons(r)),r
            code='npvalid'+str(i)
            sql(f"INSERT INTO discounts(hash_id,type,amount,expire_date,expire_count,can_use) VALUES ('{code}','percent',20,0,2,-1)")
            run('discount',callback='haveDiscountCheckout_'+pid)
            rr=run('discount',text=code,**config)
            assert latest(2001)[2]=='80' and any('?nowpayment&hash_id='+h in b.get('url','') for b in buttons(rr)),rr
            assert sql(f"SELECT expire_count FROM discounts WHERE hash_id='{code}'")=='1'
        # Valid initial configuration may become invalid before redemption.
        # Re-evaluate the residual quote at consumption, not only at preview.
        r=run('standard',callback='selectPlan7_1_none')
        pid,h,_,_=latest(2001)
        run('discount',callback='haveDiscountCheckout_'+pid)
        sql("INSERT INTO discounts(hash_id,type,amount,expire_date,expire_count,can_use) VALUES ('npchanged','percent',20,0,2,-1)")
        before=sql(f"SELECT price,checkout_draft_context FROM pays WHERE id={pid}")
        rr=run('discount',text='npchanged',settlement_policy=None)
        no_money_card(rr,pid)
        assert sql(f"SELECT price,checkout_draft_context FROM pays WHERE id={pid}")==before
        assert sql("SELECT expire_count FROM discounts WHERE hash_id='npchanged'")=='2'
        cases.append('nowpayments_local_policy_quote_validation_atomic_discount_and_zero')
        # Compare all method rows against established callback order and
        # exact mode-specific labels, including positive volume price.
        # Current crypto submenus and real issuance must ignore stale
        # request snapshots. An original receipt must keep its destination.
        state(walletState='off',manualUsdtState='on',cartToCartState='on')
        crypto_keys=base_keys|{'manualUsdtWalletBEP20':'0x'+'a'*40,
            'manualUsdtWalletERC20':'0x'+'b'*40,'manualUsdtRateToman':'1000',
            'manualTrxWallet':'T9yD14Nj9j7xAB4dbGeiX9h8unkKHxuWwb','manualTrxRateToman':'1000'}
        sql("UPDATE setting SET value='"+json.dumps(crypto_keys)+"' WHERE type='PAYMENT_KEYS'")
        r=run('standard',callback='selectPlan7_1_none');pid,h,_,_=latest(2001)
        state(walletState='off',manualUsdtState='on',gatewayAudience={'manual_trx':'off'})
        mid=int(sql(f'SELECT message_id FROM payment_message_refs WHERE pay_row_id={pid} AND retired_reason IS NULL ORDER BY id DESC LIMIT 1'))
        rr=run('manual_checkout',callback='mcm_'+h,message_id=mid,stale_state={'manualUsdtState':'off'},stale_keys={'manualUsdtWalletBEP20':'invalid','manualUsdtWalletERC20':'invalid'})
        assert callbacks(rr)==['mc_b_'+h,'mc_e_'+h,'dc_'+h],rr
        before=sql("SELECT COUNT(*) FROM payment_attempts")
        rr=run('manual_claim',pay=int(pid),method='manual_trx',network='TRON')
        assert ['claim',False] in rr['events'] and sql("SELECT COUNT(*) FROM payment_attempts")==before,rr
        rr=run('manual_claim',pay=int(pid),method='manual_usdt',network='BEP20',
            stale_keys={'manualUsdtWalletBEP20':'0x'+'c'*40,'manualUsdtRateToman':'10000'})
        assert ['claim',True] in rr['events'],rr
        issued=sql(f"SELECT checkout_url,quote_amount,wallet_invoice_sha256 FROM payment_attempts WHERE pay_row_id={pid}").split('\t')
        assert json.loads(issued[0])['wallet']=='0x'+'a'*40 and float(issued[1])==0.1 and len(issued[2])==64,issued
        sql("UPDATE setting SET value='"+json.dumps(base_keys)+"' WHERE type='PAYMENT_KEYS'")
        state(walletState='off',manualUsdtState='off')
        rr=run('manual_checkout',callback='mcm_'+h,message_id=mid)
        assert 'rs_'+h in callbacks(rr) and not any(cb.startswith('mc_') for cb in callbacks(rr)),rr
        assert sql(f"SELECT purpose FROM payment_message_refs WHERE pay_row_id={pid} AND message_id={mid}")=='invoice'
        assert not any(e[0]=='send' for e in rr['events']),rr
        rr=run('manual_checkout',callback='mc_e_'+h,message_id=mid)
        assert not any(e[0] in ('send','edit','delete') for e in rr['events']),rr
        rr=run('manual_claim',pay=int(pid),method='manual_usdt',network='BEP20')
        assert ['claim',True] in rr['events'],rr
        assert sql(f"SELECT checkout_url,quote_amount,wallet_invoice_sha256 FROM payment_attempts WHERE pay_row_id={pid}").split('\t')==issued
        # Newly configured method can disappear between menu and claim.
        state(walletState='off',cartToCartState='on')
        r=run('standard',callback='selectPlan7_1_none');pid,h,_,_=latest(2001)
        sql("UPDATE setting SET value='{}' WHERE type='PAYMENT_KEYS'")
        before=sql("SELECT COUNT(*) FROM payment_attempts")
        rr=run('manual_claim',pay=int(pid),method='bank_transfer',stale_keys=base_keys)
        assert ['claim',False] in rr['events'],rr
        assert sql("SELECT COUNT(*) FROM payment_attempts")==before
        assert sql(f"SELECT checkout_operation_id IS NULL FROM pays WHERE id={pid}")=='1'
        cases.append('actual_crypto_submenu_and_manual_issuance_current_config_original_receipt')
        # A previously displayed renewal link must use current local
        # credentials; stale request policy cannot open a new merchant.
        state(walletState='off',nextpay='on')
        sql("UPDATE setting SET value='"+json.dumps(base_keys|{'nextpay':'fixture-merchant'})+"' WHERE type='PAYMENT_KEYS'")
        r=run('renew',callback=f'renewAccount{oid}');pid,h,_,_=latest(2001)
        assert any('?nextpay&hash_id='+h in b.get('url','') for b in buttons(r)),r
        assert not any(cb.startswith('renewPay_') for cb in callbacks(r)),r
        sql("UPDATE setting SET value='"+json.dumps(base_keys)+"' WHERE type='PAYMENT_KEYS'")
        rr=run('renew',callback=f'renewAccount{oid}',stale_keys={'nextpay':'stale'})
        assert not any('?nextpay&hash_id=' in b.get('url','') for b in buttons(rr)),rr
        pid,h,_,_=latest(2001)
        cases.append('actual_renewal_hosted_navigation_current_method_configuration')
        # Actual current-schema checkout draft, without any issuance/claim.
        sql(f"UPDATE pays SET draft_expires_at=UNIX_TIMESTAMP()-1 WHERE id={pid}")
        before=sql(f"SELECT state,COALESCE(checkout_operation_id,0),COALESCE(payid,'') FROM pays WHERE id={pid}")
        mid=int(sql(f'SELECT message_id FROM payment_message_refs WHERE pay_row_id={pid} AND retired_reason IS NULL ORDER BY id DESC LIMIT 1'))
        r=run('payment_status',callback='ps_'+h,message_id=mid)
        edits=[e for e in r['events'] if e[0]=='edit']
        assert len(edits)==1 and 'مهلت انتخاب روش پرداخت پایان یافت' in edits[0][1],r
        assert callbacks(r)==['mainMenu'],r
        assert sql(f"SELECT state,COALESCE(checkout_operation_id,0),COALESCE(payid,'') FROM pays WHERE id={pid}")==before
        r=run('payment_status',actor=2002,callback='ps_'+h)
        assert not any(e[0]=='edit' for e in r['events']),r
        # A contradictory original provider locator must retain recovery.
        sql(f"UPDATE pays SET payid='fixture-unknown-provider' WHERE id={pid}")
        r=run('payment_status',callback='ps_'+h,message_id=mid)
        assert 'ps_'+h in callbacks(r),r
        assert not any('مهلت انتخاب روش پرداخت پایان یافت' in e[1] for e in r['events'] if e[0]=='edit'),r
        cases.append('expired_unadmitted_current_draft_only_home_and_uncertain_retains_recovery')


        sql("UPDATE setting SET value='"+json.dumps(base_keys)+"' WHERE type='PAYMENT_KEYS'")
        state()








    result={'status':'PASS','complete':True,'cases':len(cases),'scenarios':cases,'external_requests':0,'production_mutations':0}
    if a.report:a.report.write_text(json.dumps(result))
    print(json.dumps(result))
if __name__=='__main__':main()
