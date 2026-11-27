#!/usr/bin/env python3
"""Actual private search/navigation and link mutation against owned SQL and loopback Native."""
import argparse
import copy
import json
from pathlib import Path
import subprocess

import database_fixture as owned
import provider_storage_fixture as storage
from test_post_v9_pasarguard_client import panel, USER, MARKER

ROOT=Path(__file__).resolve().parents[1]
DB=owned.NAMES[1]
PHP=r'''<?php
declare(strict_types=1);
error_reporting(E_ALL);
set_error_handler(static function($n,$s){throw new ErrorException($s,0,$n);});
define('ACHBOT_PG_TEST_LOOPBACK',true);
$root=$argv[1];$connection=new mysqli('localhost','root','',$argv[3],0,$argv[2]);
$connection->set_charset('utf8mb4');
$spec=json_decode($argv[4],true,64,JSON_THROW_ON_ERROR);
mysqli_report(($spec['strict']??true)?MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT:MYSQLI_REPORT_OFF);
$admin=9001;$from_id=(int)($spec['actor']??1001);
$update=(object)['update_id'=>(int)($spec['id']??31)];
require $root.'/src/Subscription/Search.php';
require $root.'/src/Subscription/LinkActions.php';
require_once $root.'/src/Telegram/Messages.php';
$u=achbotProviderRow($connection,'SELECT * FROM users WHERE userid=?','i',[$from_id]);
$result=null;$events=[];
function editText(...$args){$GLOBALS['events'][]='edit';return json_decode(json_encode($GLOBALS['spec']['edit']));}
function sendMessage(...$args){$GLOBALS['events'][]='send';return (object)['ok'=>true,'result'=>(object)['chat'=>(object)['id'=>$GLOBALS['from_id']],'message_id'=>42]];}
function delMessage(...$args){$GLOBALS['events'][]='delete';throw new RuntimeException('fixture cleanup refused');}
switch($spec['op']){
case 'search':$result=achbotSubscriptionSearch($connection,$from_id,$spec['mode'],$spec['input']);break;
case 'transition':
    if (isset($spec['cached_step'])) $u['step']=$spec['cached_step'];
    if (isset($spec['cached_temp'])) $u['temp']=$spec['cached_temp'];
    $result=\AchBot\Telegram\Conversation\transitionEditor($connection,$from_id,$u,$spec['next']??'none')!==null;
    break;
case 'frame':
    $update=json_decode(json_encode($spec['update']));
    $result=\AchBot\Telegram\Conversation\source($update,$from_id,$spec['callback'])!==null;break;
case 'replace':$result=\AchBot\Telegram\Conversation\replaceTransient($from_id,81,'fixture','{}');break;
case 'ack':
    $result=\AchBot\Telegram\Messages\answerCallbackQuery(static function($method,$data){return [$method,$data];},'fixture','ok',false);break;
case 'link':
    $result=achbotSubscriptionLinkAction($connection,$from_id,(int)$spec['order'],(bool)$spec['rotate'],
        static function($order,$remote)use(&$events,$spec){
            $events[]=['order'=>(int)$order['id'],'url'=>$remote->subscription_url,'links'=>$remote->links,
                'verified'=>$remote->provider_render_proof,'proxy'=>$remote->proxy_settings];
            return (bool)($spec['present']??true);
        });break;
case 'original':
    try {achbotProviderAssertOriginalCredentialsCurrent(achbotProviderRow($connection,'SELECT * FROM service_effects WHERE id=1'));$result=true;}
    catch(RuntimeException $e){$result=$e->getMessage();}
    break;
}
echo json_encode(['result'=>$result,'events'=>$events],JSON_THROW_ON_ERROR);
'''
def main():
    p=argparse.ArgumentParser();p.add_argument('--run-disposable',required=True,action='store_true');p.add_argument('--report',type=Path)
    a=p.parse_args();checks=[]
    with owned.owned_database(ROOT/'database/current-schema.sql') as (w,env), panel() as (server,url):
        script=w/'current_subscription.php';script.write_text(PHP)
        def sql(q):return owned.sql(w,env,q,DB)
        def call(op,**spec):
            proc=subprocess.run(['php',str(script),str(ROOT),str(w/'mysql.sock'),DB,json.dumps(dict(op=op,**spec))],capture_output=True,text=True,timeout=35)
            assert proc.returncode==0,(proc.stdout,proc.stderr)
            try:return json.loads(proc.stdout)
            except ValueError:raise AssertionError(proc.stdout+'\n'+proc.stderr)
        def check(label,value):
            assert value,label
            checks.append(label)
        sql(f"""INSERT INTO server_categories(id,title,parent,active) VALUES (1,'Fixture',0,1);
        INSERT INTO server_config(id,panel_url,type,username,password) VALUES(2,'{url}','pasarguard','fixture','fixture-private-key');
        INSERT INTO server_info(id,title,ucount,remark,flag,active,state) VALUES(2,'Fixture','100','pg','',1,1);
        INSERT INTO server_plans(id,catid,server_id,inbound_id,title,days,volume,price,descr,active,date,provider_group_ids)
        VALUES(7,1,2,0,'Fixture',30,2,100,'description',1,'fixture','[2,5]');
        INSERT INTO orders_list(id,userid,token,transid,fileid,server_id,remark,uuid,expire_date,link,amount,status,date,agent_bought)
        VALUES (91,1001,'original','fixture',7,2,'fixture_user','fixture-uuid',2000000000,'["vless://original"]',100,1,'fixture',0),
        (92,1001,'token','fixture',7,2,'fixture_user_extra','fixture-uuid',2000000000,'[]',100,1,'fixture',1),
        (93,9002,'token','fixture',7,2,'fixture_user','fixture-uuid',2000000000,'[]',100,1,'fixture',0),
        (94,1001,'token','fixture',7,2,'retired','fixture-uuid',2000000000,'[]',100,0,'fixture',0),
        (95,1001,'token','fixture',7,2,'percent%literal','fixture-uuid',2000000000,'[]',100,1,'fixture',0);""")
        sql(storage.connection(2,1,url))
        sql(storage.identity(1,91,1,url,17,'2026-10-26T05:00:00.123456Z','fixture_user',MARKER,dict(group_ids=[2,5],data_limit=2147483648,expire=2000000000,status='active')))
        sql("UPDATE service_effects SET actor_userid=1001,source_kind='free_trial',source_ref='trial:1001' WHERE id=1")
        # Search scope, literal wildcard, precedence and no arbitrary result.
        for strict in (True,False):
            def search(mode,query,actor=1001):return call('search',mode=mode,input=query,actor=actor,strict=strict)['result']
            prefix='strict' if strict else 'off'
            check(prefix+':exact_owned',search('searchAgentConfig','fixture_user')=={'status':'found','id':91})
            check(prefix+':ambiguous_partial',search('searchAgentConfig','fixture')['status']=='ambiguous')
            check(prefix+':literal_percent',search('searchAgentConfig','%')=={'status':'found','id':95})
            check(prefix+':literal_underscore',search('searchAgentConfig','_')['status']=='ambiguous')
            check(prefix+':my_scope',search('searchMyConfig','extra')['status']=='missing')
            check(prefix+':inactive',search('searchAgentConfig','retired')['status']=='missing')
            check(prefix+':unauthorized_staff',search('searchUsersConfig','fixture')['status']=='denied')
            check(prefix+':staff_duplicate_exact',search('searchUsersConfig','fixture_user',9001)['status']=='ambiguous')
            sql("UPDATE users SET step='searchAgentConfig_81',temp='current' WHERE userid=1001")
            check(prefix+':stale_step',not call('transition',cached_step='searchAgentConfig_80',strict=strict)['result'])
            check(prefix+':stale_temp',not call('transition',cached_temp='old',strict=strict)['result'])
            check(prefix+':current_transition',call('transition',strict=strict)['result'])
            sql("UPDATE users SET step='achbotDirectGift-protected' WHERE userid=1001")
            check(prefix+':protected_transition',not call('transition',strict=strict)['result'])
            sql("UPDATE users SET step='none',temp=NULL WHERE userid=1001")
        human=dict(from_={'id':1001},chat={'id':1001,'type':'private'},message_id=82,text='fixture')
        human['from']=human.pop('from_')
        check('private_typed',call('frame',update={'message':human},callback=False)['result'])
        cb={'callback_query':{'from':{'id':1001},'data':'test','message':human}}
        check('callback_not_typed',not call('frame',update=cb,callback=False)['result'])
        check('foreign_chat',not call('frame',update={'message':dict(human,chat={'id':9002,'type':'private'})},callback=False)['result'])
        check('shared_ack_transport',call('ack')['result']==['answerCallbackQuery',{'callback_query_id':'fixture','text':'ok','show_alert':False}])
        r=call('replace',edit={'ok':True,'result':{'message_id':82,'chat':{'id':1001}}})
        check('wrong_edit_ack_no_replacement',r=={'result':None,'events':['edit']})
        r=call('replace',edit={'ok':False,'error_code':400,'description':'Bad Request: message to edit not found'})
        check('missing_transient_best_effort_cleanup',r=={'result':42,'events':['edit','send','delete']})
        # Same real Native action, including the journal and provider ownership.
        orig=sql("SELECT link,token,uuid FROM orders_list WHERE id=91")
        # Other same-name rows have no Native identity. Remove the synthetic
        # search-only foreign duplicate before testing physical-account proof.
        sql("DELETE FROM orders_list WHERE id=93; UPDATE users SET isAdmin=0 WHERE userid=9002")
        check('original_credential_current',call('original')['result'] is True)
        r=call('link',order=91,rotate=False)
        check('refresh_verified_read_only',r['result']=='shown' and server.write_count==0 and r['events'][0]['url']==USER['subscription_url'])
        check('refresh_does_not_rewrite_original',orig==sql("SELECT link,token,uuid FROM orders_list WHERE id=91"))
        check('foreign_owner_denied',call('link',actor=9002,order=91,rotate=True)['result']=='denied' and server.write_count==0)
        server.mode='links_invalid'
        r=call('link',order=91,rotate=False)
        check('unverified_links_not_success',r['result']=='unavailable' and not r['events'])
        server.mode='ok'
        r=call('link',order=91,rotate=True,id=400,present=False)
        check('rotation_commit_independent_of_view',r['result']=='view_unconfirmed' and server.write_count==1)
        check('original_delivery_rejected_after_rotation',call('original')['result']=='service_delivery_credentials_rotated')
        check('rotation_preserves_original_record',orig==sql("SELECT link,token,uuid FROM orders_list WHERE id=91"))
        r=call('link',order=91,rotate=True,id=400)
        check('same_update_get_only_recovery',r['result']=='shown' and server.write_count==1 and r['events'][0]['url'].endswith('/changed'))
        previous_url=server.user['subscription_url']
        server.user['subscription_url']=previous_url+'/external-change'
        r=call('link',order=91,rotate=True,id=400)
        check('changed_subscription_url_not_original_success',r['result']=='review' and server.write_count==1 and not r['events'])
        server.user['subscription_url']=previous_url
        server.user['proxy_settings']={'vless':{'id':'external-change'}}
        r=call('link',order=91,rotate=True,id=400)
        check('changed_native_identity_not_original_success',r['result']=='review' and server.write_count==1 and not r['events'])
        r=call('link',order=91,rotate=False)
        check('explicit_refresh_current_native',r['result']=='shown' and server.write_count==1)
        server.mode='write_lost'
        r=call('link',order=91,rotate=True,id=401)
        check('unknown_rotation_fenced',r['result']=='review' and server.write_count==2)
        server.mode='ok'
        check('unknown_rotation_no_replay',call('link',order=91,rotate=True,id=401)['result']=='review' and server.write_count==2)
        check('unknown_rotation_new_click_fenced',call('link',order=91,rotate=True,id=402)['result']=='review' and server.write_count==2)
        check('unknown_rotation_read_only_refresh',call('link',order=91,rotate=False)['result']=='shown' and server.write_count==2)
    report={'complete':True,'cases':len(checks),'checks':checks,'production_mutations':0,'external_requests':0}
    if a.report:a.report.write_text(json.dumps(report))
    print(json.dumps(report))
if __name__=='__main__':main()
