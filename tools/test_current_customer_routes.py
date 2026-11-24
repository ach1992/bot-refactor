#!/usr/bin/env python3
"""Current consumer regression for content ownership, catalogue and checkout UI.

All actual route source is used against disposable MariaDB. The Telegram
transport is an in-process recording double; no external network or secrets.
"""
import argparse
import json
from pathlib import Path
import subprocess
import time

import test_access_state as owned
from test_v5_native import sql_command

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
foreach (['setUser','getMainKeys','getMainMenuButtonsKeys','RandomString'] as $name)
    eval(str_replace('__DIR__',var_export($root,true),achbotExtractFunction($root.'/config.php',$name)));
eval(achbotExtractFunction($root.'/bot.php','achbotWebhookIngressAuthenticated'));
$telegramWebhookSecret='Synthetic_Current_Routes_2026';
$_SERVER['REQUEST_METHOD']='POST';$_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']=$telegramWebhookSecret;
$spec=json_decode($argv[4],true,512,JSON_THROW_ON_ERROR);
$from_id=(int)($spec['actor']??2001);$admin=9001;$chat_id=$from_id;
$callbackId='fixture';$message_id=81;$username='fixture';$first_name='Fixture';
$userInfo=achbotProviderRow($connection,'SELECT * FROM users WHERE userid=?','i',[$from_id])??[];
$botState=json_decode(achbotProviderRow($connection,"SELECT value FROM setting WHERE type='BOT_STATES'")['value'],true);
$robotState=$botState['botState']??'on';$botUrl='https://fixture.invalid/';
$paymentKeys=['bankAccount'=>'fixture-bank','holderName'=>'fixture-holder'];
$cancelKey=$removeKeyboard='{}';$events=[];$inviteReward=0;$joinLink='https://t.me/fixture';
$channelLock=$spec['forced']??'';$joinMembershipVerified=$spec['member']??false;
$data=$spec['callback']??'';$text=$spec['text']??'';
$chat=(object)['id'=>(int)($spec['chat']??$from_id),'type'=>$spec['chat_type']??'private'];
$sender=(object)['id'=>$from_id,'first_name'=>'Fixture','username'=>'fixture'];
$update=$data!==''?(object)['callback_query'=>(object)['from'=>$sender,'data'=>$data,
    'message'=>(object)['chat'=>$chat,'message_id'=>81]]]:
    (object)['message'=>(object)['from'=>$sender,'chat'=>$chat,'text'=>$text]];
function sendMessage($text,$keys=null,$mode=null,$uid=null){$GLOBALS['events'][]=['send',$text,$keys,$mode];return (object)['ok'=>true,'result'=>(object)['message_id'=>100+count($GLOBALS['events'])]];}
function editText($id,$text,$keys=null,$mode=null){$GLOBALS['events'][]=['edit',$text,$keys,$mode];return (object)['ok'=>true];}
function delMessage(...$a){}
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
case 'ticket':
    require $root.'/src/Support/Categories.php';break;
case 'menu':
    $events[]=['menu',achbotMainMenuForContext($connection,$userInfo,$from_id,$admin,$botState)];break;
case 'capacity':
    eval(fixtureSection('if($data=="availableServers2"', "require __DIR__ . '/src/Agent/Applications.php'"));break;
case 'entry':
    require $root.'/src/Purchase/EntryAndBulk.php';break;
case 'catalogue':
    require $root.'/src/Purchase/Catalogue.php';break;
case 'standard':
    require $root.'/src/Purchase/SelectPlan.php';break;
case 'seed_order':
    // Synthetic already-linked Native account, not a remote creation.
    $snap=achbotProviderSnapshot($connection,2,7);
    $json=json_encode($snap,JSON_THROW_ON_ERROR);
    $endpoint=$snap['endpoint_sha256'];$key='pg:'.$snap['instance_key'];
    $marker='achbot:v1:'.str_repeat('b',32);$operation=str_repeat('b',32);
    $created='2026-01-01T00:00:00Z';
    $entitlement=json_encode(['group_ids'=>[1],'data_limit'=>2147483648,'expire'=>time()+86400,'status'=>'active']);
    $s=achbotProviderStatement($connection,"INSERT INTO orders_list (userid,token,transid,fileid,server_id,remark,uuid,expire_date,link,amount,status,date) VALUES (?,'fixture','fixture',7,2,'fixture-linked','fixture-uuid',?,'fixture',100,1,?)",'iis',[$from_id,time()+86400,(string)time()]);
    $oid=$s->insert_id;$s->close();
    $s=achbotProviderStatement($connection,"INSERT INTO service_effects(source_kind,source_ref,actor_userid,ordinal,operation_id,effect_kind,target_server_id,target_key,endpoint_sha256,plan_id,remote_username,requested_expire,requested_bytes,request_sha256,state,order_id,verified_at,provider_snapshot,provider_reference_id,provider_connection_id,provider_remote_user_id,provider_remote_created_at,provider_operation_marker,provider_entitlement_snapshot,provider_state,provider_verified_at) VALUES ('free_trial','fixture',?,1,?,'create',2,?,?,7,'fixture-linked',?,2147483648,?,'local_recorded',?,NOW(),?,12345,2,12345,?,?,?,'linked',NOW())",'isssisissss',[$from_id,$operation,$key,$endpoint,time()+86400,hash('sha256',$json),$oid,$json,$created,$marker,$entitlement]);
    $effect=$s->insert_id;$s->close();
    achbotProviderBindOrder($connection,['effect_id'=>(int)$effect,'remote_user_id'=>12345,'remote_created_at'=>$created],(int)$oid);
    $events[]=['order',$oid];break;
case 'renew':
    require $root.'/src/Finance/Routes/RenewalCheckout.php';break;
case 'discount':
    require $root.'/src/Finance/Routes/DiscountEntry.php';break;
case 'wallet':
    $events[]=['wallet',achbotCheckoutWallet($connection,$spec['hash'],$from_id,$spec['mode'], $botState)];break;
case 'shortcut':
    // Actual current ban and membership/registration/phone/home source.
    eval(fixtureSection("if ((\$userInfo['step'] ?? null) ===", '$checkSpam = checkSpam();'));
    eval(fixtureSection('// "عضو شدم" is a navigation continuation', "if(preg_match('/^sendMessageToUser"));
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
            assert r.returncode==0 and not r.stderr,(op,kw,r.returncode,r.stdout[-1200:],r.stderr[-1600:])
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
            s=dict(sellState='on',walletState='on',remark='digits',agencyState='on',agencyPlanDiscount='off',plandelkhahState='on',gbPrice=10,dayPrice=20,individualExistence='on')
            s.update(kw);sql("UPDATE setting SET value='"+json.dumps(s)+"' WHERE type='BOT_STATES'")
        sql("INSERT INTO setting(id,type,value) VALUES (1,'BOT_STATES','{}'),(2,'PAYMENT_KEYS','PRIVATE_SYNTHETIC'),(3,'MAIN_BUTTONSVisible','Public answer'),(4,'XMAIN_BUTTONSBad','PRIVATE_SYNTHETIC'),(5,'MAINXBUTTONSBad','PRIVATE_SYNTHETIC'),(6,'main_buttonsBad','PRIVATE_SYNTHETIC'),(7,'TICKETS_CATEGORY','Ticket'),(8,'tickets_category','PRIVATE_SYNTHETIC'),(9,'MAIN_BUTTONSDelete','Public second'),(10,'TICKETS_CATEGORY','Ticket second')")
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
        # Real supported Native catalogue and intentionally non-saleable controls.
        sql("INSERT INTO server_categories(id,server_id,title,parent,step,active) VALUES (1,'0','Category',0,0,1),(2,'0','Inactive category',0,0,0)")
        for sid,active,server_state,capacity in [(2,1,1,100),(3,0,1,10),(4,1,2,10),(5,1,1,0),(6,1,1,10)]:
            sql(f"INSERT INTO server_info(id,title,ucount,remark,flag,active,state) VALUES ({sid},'Server {sid}','{capacity}','fixture','',{active},{server_state}); INSERT INTO server_config(id,panel_url,type,username,password,provider_connection_id,provider_instance_key,provider_version,provider_enabled) VALUES ({sid},'https://fixture.invalid','pasarguard','fixture','fixture',{sid},REPEAT('a',32),'5.5.1',1)")
        for pid,sid,cat,active,inbound,groups in [(7,2,1,1,0,'[1]'),(8,2,1,0,0,'[1]'),(9,3,1,1,0,'[1]'),(10,4,1,1,0,'[1]'),(11,5,1,1,0,'[1]'),(12,6,1,1,9,'[1]'),(13,6,2,1,0,'[1]')]:
            sql(f"INSERT INTO server_plans(id,fileid,catid,server_id,inbound_id,acount,title,protocol,days,volume,type,price,descr,pic,active,step,date,provider_group_ids) VALUES ({pid},'{pid}',{cat},{sid},{inbound},0,'Plan {pid}','vless',30,2,'normal',100,'description','',{active},0,'fixture','{groups}')")
        r=run('capacity',callback='availableServers2')
        body=json.dumps(r,ensure_ascii=False)
        assert 'Plan 7' in body and all(f'Plan {i}' not in body for i in range(8,14)),r
        assert [c for c in callbacks(run('entry',callback='buySubscription')) if c.startswith('selectServer')]==['selectServer2_none']
        assert [c for c in callbacks(run('catalogue',callback='selectCategory1_2_none')) if c.startswith('selectPlan')]==['selectPlan7_1_none']
        assert 'availableServers' not in callbacks(run('menu'))
        cases.append('actual_capacity_entry_catalogue_share_current_native_saleability')
        # The original card is created by the actual SelectPlan/custom routes;
        # the emitted discount button is then consumed by the real route.
        def latest(uid):
            return sql(f'SELECT id,hash_id,price,agent_count FROM pays WHERE user_id={uid} ORDER BY id DESC LIMIT 1').split('\t')
        def redeem(uid,r,code,mode,back,amount,quantity=1,zero=False):
            pid,h,oldprice,count=latest(uid)
            assert 'haveDiscountCheckout_'+pid in callbacks(r),r
            r=run('discount',actor=uid,callback='haveDiscountCheckout_'+pid)
            assert r['user']['step']=='discountCheckout_'+pid,r
            sql(f"INSERT INTO discounts(hash_id,type,amount,expire_date,expire_count,can_use) VALUES ('{code}','percent',{100 if zero else 20},0,2,-1)")
            r=run('discount',actor=uid,text=code)
            cb=callbacks(r);prefix={'standard':'payBuy','custom':'payCustom','renew':'payRenew'}[mode]
            assert prefix+'WithWallet'+h in cb and back in cb,r
            assert int(latest(uid)[2])==amount and max(1,int(count))==quantity,(r,latest(uid))
            assert sql(f"SELECT expire_count FROM discounts WHERE hash_id='{code}'")=='1'
            # A replayed button cannot reopen a second redemption.
            rr=run('discount',actor=uid,callback='haveDiscountCheckout_'+pid)
            assert rr['user']['step']=='none' and sql(f"SELECT expire_count FROM discounts WHERE hash_id='{code}'")=='1',rr
            funded=run('wallet',actor=uid,hash=h,mode=mode)['events'][0][1]
            assert funded['status']=='funded',funded
            quote=json.loads(sql(f"SELECT quote_payload FROM service_operations WHERE id={funded['operation']}"))['request']
            assert quote['price']==amount and quote['plan_id']==7 and quote['quantity']==quantity,quote
            if mode=='custom': assert quote['days']==3 and quote['volume']==2 and quote['remark']=='custom12345',quote
            return r
        original=run('standard',callback='selectPlan7_1_none')
        sql('UPDATE server_plans SET price=250 WHERE id=7')
        redeem(2001,original,'standard','standard','selectCategory1_2_none',80)
        sql('UPDATE server_plans SET price=100 WHERE id=7')
        redeem(2002,run('standard',actor=2002,callback='selectPlan7_1_one'),'agentone','standard','selectCategory1_2_one',80)
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
        assert 'selectServer2_none' not in callbacks(run('shortcut',text='/buy'))
        assert 'selectServer2_none' not in callbacks(run('entry',callback='buySubscription'))
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
    result={'status':'PASS','complete':True,'cases':len(cases),'scenarios':cases,'external_requests':0,'production_mutations':0}
    if a.report:a.report.write_text(json.dumps(result))
    print(json.dumps(result))
if __name__=='__main__':main()
