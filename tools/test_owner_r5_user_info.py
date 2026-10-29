#!/usr/bin/env python3
"""AChBot R5: actual user-info SQL/editor + authenticated /start profile sync.
Owned original-schema socket MariaDB and synthetic Telegram only. No live calls.
"""
from __future__ import annotations
import argparse, hashlib, json, shutil, subprocess, time
from pathlib import Path
import test_access_state as owned
import test_v6_native as native

ROOT = Path(__file__).resolve().parents[1]
BASE = '64feca4b777a442023676122e315c68ad26e902d'
DB = owned.NAMES[1]
DRIVER = r'''<?php
error_reporting(E_ALL & ~E_DEPRECATED);
set_error_handler(static function($n,$s){if($n===E_DEPRECATED)return true;throw new ErrorException($s,0,$n);});
$_SERVER['SCRIPT_FILENAME']=__FILE__;
$_SERVER['REQUEST_METHOD']='POST';
$_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']='Synthetic_Profile_Token_Only_2026';
$telegramWebhookSecret='Synthetic_Profile_Token_Only_2026';
$root=$argv[1];$app=$argv[2];$spec=json_decode($argv[5],true,512,JSON_THROW_ON_ERROR);
$connection=new mysqli('localhost','root','',$argv[4],0,$argv[3]);$connection->set_charset('utf8mb4');
$from_id=(int)($spec['actor']??9001);$admin=9001;$chat_id=$from_id;$message_id=77;
$data=(string)($spec['data']??'');$text=(string)($spec['text']??'');
$buttonValues=['cancel'=>'Cancel','back_button'=>'Back','subscriptions_list'=>'Subscriptions'];
$mainValues=['saved_successfuly'=>'Saved','send_agent_discount_percent'=>'Percent',
 'agent_discount_settings'=>'Agent AGENT-NAME'];
$cancelKey='{}';$removeKeyboard='{}';$botState=['agencyPlanDiscount'=>'off'];$events=[];$chatCalls=0;
function bot($method,$args) {
 if($method!=='getChat')throw new RuntimeException('unexpected_telegram');
 $GLOBALS['chatCalls']++;
 if(!empty($GLOBALS['spec']['chat_failure']))return false;
 if(!empty($GLOBALS['spec']['chat_no_username']))return (object)['result'=>(object)['first_name'=>'Fixture','last_name'=>'Profile']];
 return (object)['result'=>(object)['username'=>'fixture_user','first_name'=>'Fixture','last_name'=>'Profile']];
}
function achbotSupportsAccount(...$unused){return false;}
function sendMessage(...$args){$GLOBALS['events'][]=['send',$args[0]??null,$args[1]??null];}
function editText(...$args){$GLOBALS['events'][]=['edit',$args[1]??null,$args[2]??null];}
function delMessage(...$args){$GLOBALS['events'][]=['delete'];}
function alert(...$args){$GLOBALS['events'][]=['alert',$args[0]??null];}
require $app.'/messages/loader.php';
require $app.'/src/Admin/Access.php';
require $app.'/functions.php';
$s=$connection->prepare('SELECT * FROM users WHERE userid=? LIMIT 1');$s->bind_param('i',$from_id);$s->execute();$userInfo=$s->get_result()->fetch_assoc();$s->close();
if($spec['action']==='profile') {
 $update=json_decode(json_encode($spec['update']),false,512,JSON_THROW_ON_ERROR);
 if(!empty($spec['invalid_token']))$_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']='Wrong';
 if(isset($update->message))require $app.'/src/Telegram/UpdateBindings.php';
 require $root.'/src/Telegram/UserProfile.php';
 $ok=achbotRefreshUserProfile($connection,$update,(int)($spec['claimed_actor']??$from_id));
 $out=['updated'=>$ok,'chat_calls'=>$chatCalls];
} elseif(in_array($spec['action'],['info','baseline_info'],true)) {
 $infoFunction=$spec['action']==='info'?'getUserInfoKeys':'getUserInfoKeysBaseline';
 $out=['details'=>$infoFunction((int)$spec['target']),'chat_calls'=>$chatCalls];
} else {
 if(($spec['source_kind']??'callback')==='callback') {
  $update=(object)['callback_query'=>(object)['from'=>(object)['id'=>$from_id]]];
 } else $update=(object)['message'=>(object)['from'=>(object)['id'=>$from_id],'text'=>$text]];
 require $app.'/src/Agent/Discounts.php';
 $out=['events'=>$events,'chat_calls'=>$chatCalls];
}
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
'''

def sql(w,e,q):
    return native.sql(w,e,q,DB).strip()
def quoted(s):
    return "'" + str(s).replace("\\","\\\\").replace("'","''") + "'"
def extracted(path,names):
    script="require $argv[1];foreach(json_decode($argv[3],true)as$n)echo achbotExtractFunction($argv[2],$n),PHP_EOL;"
    return subprocess.check_output(['php','-r',script,str(ROOT/'tools/source_scan.php'),str(path),json.dumps(names)])
def call(w,e,app,spec):
    r=subprocess.run(['php',str(w/'driver.php'),str(ROOT),str(app),str(w/'mysql.sock'),DB,json.dumps(spec)],
        env=e,capture_output=True,text=True,timeout=15)
    if r.returncode or r.stderr:raise AssertionError('actual_php_failed:'+r.stderr[-1200:])
    return json.loads(r.stdout)
def main():
    p=argparse.ArgumentParser();p.add_argument('--run-disposable',required=True,action='store_true')
    p.add_argument('--access-source',type=Path,default=ROOT/'src/Admin/Access.php')
    p.add_argument('--catalog-source',type=Path,default=ROOT)
    p.add_argument('--report',type=Path);a=p.parse_args();checks=[]
    def yes(ok,name):
        if not ok:raise AssertionError(name)
        checks.append(name)
    access=a.access_source.resolve();catalog=a.catalog_source.resolve()
    if not access.is_file():raise RuntimeError('integrate_R5_ROLES_Access_first')
    source=(ROOT/'bot.php').read_text()
    yes("achbotRefreshUserProfile($connection, $update ?? null, (int)$from_id);" in source,'actual_start_hook_present')
    yes(source.index('achbotRefreshUserProfile(')>source.index('$paymentHomeMatch=[];'),'profile_hook_after_fresh_menu_actor_check')
    before=subprocess.check_output(['git','show',BASE+':bot.php'],cwd=ROOT,text=True)
    yes('achbotRefreshUserProfile(' not in before,'baseline_missing_start_refresh_RED')
    names=['getUserInfoKeys','getPaymentSumInRange','getOrderCountInRange','setUser']
    cfg=extracted(ROOT/'config.php',names)
    webhook=extracted(ROOT/'bot.php',['achbotWebhookIngressAuthenticated'])
    with owned.owned_database() as (w,e):
        app=w/'user-app';(app/'src/Agent').mkdir(parents=True)
        shutil.copytree(catalog/'messages',app/'messages')
        (app/'src/Admin').mkdir(parents=True)
        shutil.copyfile(access,app/'src/Admin/Access.php')
        shutil.copyfile(ROOT/'src/Agent/Discounts.php',app/'src/Agent/Discounts.php')
        (app/'src/Telegram').mkdir(parents=True)
        shutil.copyfile(ROOT/'src/Telegram/UpdateBindings.php',app/'src/Telegram/UpdateBindings.php')
        original=w/'original-config.php';original.write_bytes(subprocess.check_output(['git','show',BASE+':config.php'],cwd=ROOT))
        baseline=extracted(original,['getUserInfoKeys']).replace(b'function getUserInfoKeys(',b'function getUserInfoKeysBaseline(')
        (app/'functions.php').write_bytes(b'<?php\n'+baseline.replace(b'__DIR__',json.dumps(str(ROOT)).encode())+cfg.replace(b'__DIR__',json.dumps(str(ROOT)).encode())+
            extracted(ROOT/'src/Finance/AgentDiscounts.php',['getAgentDiscounts'])+webhook)
        (w/'driver.php').write_text(DRIVER)
        now=int(time.time());week=now-2*86400;month=now-15*86400;old=now-100*86400
        sql(w,e,"DELETE FROM pays;DELETE FROM orders_list;DELETE FROM increase_order;"
            "UPDATE users SET wallet=500,step='none',name='Stored &amp; Name',username='stored_user',"
            "is_agent=1,discount_percent='{\"normal\":\"5\",\"servers\":{\"7\":\"11\"},\"plans\":{\"9\":\"22\"}}' WHERE userid=1001;"
            "INSERT INTO users(userid,name,username,refcode,wallet,date,isAdmin,step) VALUES"
            "(9003,'Support','support','0',0,'1700000000',1,'none'),"
            "(9004,'Operator','operator','0',0,'1700000000',1,'none');"
            "INSERT INTO setting(type,value) VALUES('ADMIN_ROLE_9002','manager'),"
            "('ADMIN_ROLE_9003','support'),('ADMIN_ROLE_9004','operator');")
        sql(w,e,"INSERT INTO orders_list(id,userid,token,transid,fileid,server_id,remark,expire_date,link,amount,status,date) VALUES"
            f"(501,1001,'','',1,1,'same',0,'[]',100,1,'{week}'),"
            f"(502,1001,'','',1,2,'same',0,'[]',200,1,'{month}'),"
            f"(503,1001,'','',0,2,'import',0,'[]',0,1,'{old}'),"
            f"(504,9002,'','',1,2,'foreign',0,'[]',999,1,'{week}');"
            "INSERT INTO increase_order(userid,server_id,inbound_id,remark,amount,date) VALUES"
            f"('1001',1,0,'same',10,'{week}'),('1001',2,0,'same',20,'{week}'),"
            f"('1001',2,0,'orphan',777,'{week}'),('9002',1,0,'same',999,'{week}');")
        pays=[('manual','BUY_SUB',100,week,'approved'),('gateway','BUY_SUB',200,month,'paid'),
            ('automatic','RENEW_ACCOUNT',300,old,'auto_cart_approved'),('topup','INCREASE_WALLET',1000,week,'approved'),
            ('wallet','BUY_SUB',800,week,'paid_with_wallet'),('pending','BUY_SUB',999,week,'pending'),
            ('review','BUY_SUB',999,week,'have_sent'),('expired','BUY_SUB',999,week,'expired_pending'),
            ('unknown','BUY_SUB',999,week,'processing_wallet')]
        for h,t,price,date,state in pays:
            sql(w,e,"INSERT INTO pays(hash_id,user_id,type,price,request_date,state) VALUES"+
                f"({quoted(h)},1001,{quoted(t)},{price},{date},{quoted(state)})")
        info=call(w,e,app,{'action':'info','target':1001})['details'];rows=info['inline_keyboard']
        yes(int(rows[2][0]['text'])==3,'service_count_includes_genuine_zero_amount_import')
        yes([int(rows[i][0]['text'])for i in [3,4,5,6]]==[1,2,2,3],'service_date_windows_current_fields')
        yes('330' in rows[7][0]['text'],'service_value_no_remark_join_fanout_or_foreign_or_orphan_addon')
        yes(int(rows[8][0]['text'])==4,'incoming_count_all_three_success_states_excludes_wallet_and_pending')
        yes('1,600' in rows[9][0]['text'],'incoming_total_no_topup_wallet_spend_doublecount')
        yes(['1,100' in rows[10][0]['text'],'1,300' in rows[11][0]['text'],
             '1,300' in rows[12][0]['text'],'1,600' in rows[13][0]['text']]==[True]*4,'incoming_date_windows_current_fields')
        agent_index=next(i for i,r in enumerate(rows)if any(b.get('callback_data')=='toggleAgent_1001'for b in r))
        yes(rows[agent_index+1][0]['callback_data']=='editAgentDiscountNormal1001_userinfo','percent_row_immediately_after_agent_status')
        for actor in [9002,9004]:
            r=call(w,e,app,{'action':'info','target':1001,'actor':actor})['details']['inline_keyboard']
            yes(any(b.get('callback_data')=='editAgentDiscountNormal1001_userinfo'for row in r for b in row),'percent_visible_to_'+str(actor))
        support=call(w,e,app,{'action':'info','target':1001,'actor':9003})['details']
        allcb=[b.get('callback_data','')for row in support['inline_keyboard']for b in row]
        yes(not any(cb.startswith(('editAgentDiscount','toggleAgent_'))for cb in allcb),'support_cannot_change_agent_from_info')
        yes(call(w,e,app,{'action':'info','target':1001,'actor':1001})['details']is None,'customer_cannot_invoke_admin_info_helper')
        sql(w,e,"UPDATE users SET username='' WHERE userid=1001")
        fallback=call(w,e,app,{'action':'info','target':1001,'chat_failure':True})['details']
        yes('url'not in fallback['inline_keyboard'][0][0]and fallback['inline_keyboard'][1][0]['text']=='Stored & Name','missing_telegram_metadata_safe_stored_name_and_no_empty_url')
        old_info=call(w,e,app,{'action':'baseline_info','target':1001,'chat_no_username':True})['details']
        yes(old_info['inline_keyboard'][0][0].get('url')=='t.me/','baseline_empty_username_keyboard_URL_RED')
        new_info=call(w,e,app,{'action':'info','target':1001,'chat_no_username':True})['details']
        yes('url' not in new_info['inline_keyboard'][0][0],'empty_username_keyboard_URL_fixed')
        sql(w,e,"UPDATE users SET username='stored_user' WHERE userid=1001")
        for raw,label,zero in [('', 'absent_discount', True), ('bad-json', 'malformed_discount', False), (json.dumps({'normal':101}), 'invalid_discount', False)]:
            sql(w,e,"UPDATE users SET discount_percent="+quoted(raw)+" WHERE userid=1001")
            r=call(w,e,app,{'action':'info','target':1001})['details']['inline_keyboard']
            value=next(b['text'] for row in r for b in row if b.get('callback_data')=='editAgentDiscountNormal1001_userinfo')
            yes(value.endswith('0%') if zero else '%' not in value,label+'_display')
        sql(w,e,"UPDATE users SET discount_percent="+quoted(json.dumps({'normal':'5','servers':{'7':'11'},'plans':{'9':'22'}}))+" WHERE userid=1001")
        # Discount editor uses the actual legacy normal field and route.
        opened=call(w,e,app,{'action':'editor','actor':9002,'data':'editAgentDiscountNormal1001_userinfo'})
        yes(any('userInfoDetails1001'in str(x)for x in opened['events']),'contextual_percent_editor_back')
        saved=call(w,e,app,{'action':'editor','actor':9002,'text':'20','source_kind':'message'})
        discount=json.loads(sql(w,e,'SELECT discount_percent FROM users WHERE userid=1001'))
        yes(discount=={'normal':'20','servers':{'7':'11'},'plans':{'9':'22'}},'percent_updates_existing_normal_and_preserves_overrides')
        yes(sql(w,e,'SELECT step FROM users WHERE userid=9002')=='none'and any('editAgentDiscountNormal1001_userinfo'in str(x)for x in saved['events']),'percent_save_returns_directly_to_user_info')
        for invalid in ['-1','101','1.5','1e1','abc']:
            sql(w,e,"UPDATE users SET step='editAgentDiscountNormal1001_userinfo' WHERE userid=9002")
            call(w,e,app,{'action':'editor','actor':9002,'text':invalid,'source_kind':'message'})
            yes(json.loads(sql(w,e,'SELECT discount_percent FROM users WHERE userid=1001'))['normal']=='20','invalid_percent_rejected_'+invalid)
        for valid in ['0','100']:
            sql(w,e,"UPDATE users SET step='editAgentDiscountNormal1001_userinfo' WHERE userid=9002")
            call(w,e,app,{'action':'editor','actor':9002,'text':valid,'source_kind':'message'})
            yes(json.loads(sql(w,e,'SELECT discount_percent FROM users WHERE userid=1001'))['normal']==valid,'valid_percent_boundary_'+valid)
        # A demoted actor cannot use a stale step; callback navigation isn't input.
        sql(w,e,"UPDATE users SET step='editAgentDiscountNormal1001_userinfo' WHERE userid=9003")
        call(w,e,app,{'action':'editor','actor':9003,'text':'77','source_kind':'message'})
        yes(json.loads(sql(w,e,'SELECT discount_percent FROM users WHERE userid=1001'))['normal']=='100','support_stale_percent_step_denied')
        sql(w,e,"UPDATE users SET step='editAgentDiscountNormal1001_userinfo' WHERE userid=9002")
        call(w,e,app,{'action':'editor','actor':9002,'data':'noop','text':'55'})
        yes(json.loads(sql(w,e,'SELECT discount_percent FROM users WHERE userid=1001'))['normal']=='100','callback_message_not_discount_input')
        back=call(w,e,app,{'action':'editor','actor':9002,'data':'userInfoDetails1001'})
        yes(sql(w,e,'SELECT step FROM users WHERE userid=9002')=='none'and len(back['events'])==1,'inline_back_consumes_own_editor_once')
        sql(w,e,"UPDATE users SET step='achbotDirectGift:sentinel' WHERE userid=9002")
        call(w,e,app,{'action':'editor','actor':9002,'data':'userInfoDetails1001'})
        yes(sql(w,e,'SELECT step FROM users WHERE userid=9002')=='achbotDirectGift:sentinel','stale_info_back_preserves_another_wizard')
        # Profile reads raw actual Update->message->from, preserving legacy storage convention.
        def update(**changes):
            user={'id':1001,'is_bot':False,'first_name':'نام <b>& ویژه','last_name':'خانوادگی','username':'new_username'}
            user.update(changes)
            return {'update_id':800,'message':{'message_id':88,'chat':{'id':1001,'type':'private'},'from':user,'text':'/start'}}
        protected=sql(w,e,'SELECT wallet,isAdmin,is_agent,discount_percent,step,date,refcode FROM users WHERE userid=1001')
        out=call(w,e,app,{'action':'profile','actor':1001,'update':update()})
        yes(out['updated']and out['chat_calls']==0,'start_profile_from_actual_bindings_without_getChat')
        expected='نام &lt;b&gt;&amp; ویژه خانوادگی'
        yes(sql(w,e,'SELECT name,username FROM users WHERE userid=1001')==expected+'\tnew_username','name_encoded_once_and_username_changed')
        yes(sql(w,e,'SELECT wallet,isAdmin,is_agent,discount_percent,step,date,refcode FROM users WHERE userid=1001')==protected,'profile_preserves_all_unrelated_user_fields')
        no_user=update(first_name='نام تازه');no_user['message']['from'].pop('username')
        yes(call(w,e,app,{'action':'profile','actor':1001,'update':no_user})['updated']and sql(w,e,'SELECT username FROM users WHERE userid=1001')=='new_username','removed_username_preserves_stored_value')
        badcases=[]
        other=update(id=9002);badcases.append(('other_actor',other,{}))
        group=update();group['message']['chat']['type']='group';badcases.append(('group',group,{}))
        nostart=update();nostart['message']['text']='hello';badcases.append(('non_start',nostart,{}))
        badcases.append(('unverified_ingress',update(),{'invalid_token':True}))
        badcases.append(('claimed_actor',update(),{'claimed_actor':9002}))
        before_row=sql(w,e,'SELECT * FROM users ORDER BY id')
        for label,u,flags in badcases:
            out=call(w,e,app,dict(action='profile',actor=1001,update=u,**flags))
            yes(not out['updated']and sql(w,e,'SELECT * FROM users ORDER BY id')==before_row,'profile_rejects_'+label)
        bot_actor=update(is_bot=True)
        yes(not call(w,e,app,{'action':'profile','actor':1001,'update':bot_actor})['updated'],'profile_rejects_bot_actor')
        sql(w,e,"INSERT INTO users(userid,name,username,refcode,wallet,date) VALUES(1001,'Duplicate','duplicate','0',0,'1700000000')")
        before_duplicate=sql(w,e,'SELECT * FROM users ORDER BY id')
        yes(not call(w,e,app,{'action':'profile','actor':1001,'update':update()})['updated'] and sql(w,e,'SELECT * FROM users ORDER BY id')==before_duplicate,'profile_ambiguous_userid_has_no_write')
        sql(w,e,"DELETE FROM users WHERE name='Duplicate' AND username='duplicate'")
        long_name='&'*64
        call(w,e,app,{'action':'profile','actor':1001,'update':update(first_name=long_name,last_name=long_name)})
        result=sql(w,e,'SELECT name FROM users WHERE userid=1001')
        yes(len(result)<=100 and result=='&amp;'*(len(result)//5),'profile_original_varchar100_without_partial_entity')
    report={'result':'PASS','cases':len(checks),'checks':checks,'source_real':True,'owned_socket_mariadb':True,
        'baseline_missing_refresh_red':True,'access_dependency_sha256':hashlib.sha256(access.read_bytes()).hexdigest(),
        'catalog_source':str(catalog),'limitations':['No live Telegram or installed operator acceptance.',
        'users.name intentionally retains the existing HTML-encoded storage convention.']}
    if a.report:a.report.write_text(json.dumps(report,ensure_ascii=False,indent=2)+'\n')
    print(json.dumps(report,ensure_ascii=False))
if __name__=='__main__':main()
