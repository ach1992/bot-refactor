#!/usr/bin/env python3
"""Source-real payment-method settings and cohort admin authorization.

Actual bot.php callback slices + config.php BOT_STATES writer + original 23-table
socket-only MariaDB, genuine-shaped private Telegram callbacks, no providers.
The merged baseline demonstrates stale-admin and stale-global failures (RED).
"""
from __future__ import annotations

import argparse
import json
import subprocess
from pathlib import Path

import test_access_state as owned
import test_post_v9_checkout_serialization as dbops
import test_v8_native as fixture

ROOT = Path(__file__).resolve().parents[1]
BASE = "502976c4419a865e7e7183c9874c469815d04c20"
DB = fixture.SIDE[1]

DRIVER = r'''<?php
error_reporting(E_ALL & ~E_DEPRECATED);
set_error_handler(static function($level,$message) {
    if ($level===E_DEPRECATED) return true;
    throw new ErrorException($message,0,$level);
});
$_SERVER['SCRIPT_FILENAME']=__FILE__;
$_SERVER['REQUEST_METHOD']='POST';
$_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']='SafeSyntheticGatewayToken_2026';
putenv('ACHBOT_TELEGRAM_WEBHOOK_SECRET=SafeSyntheticGatewayToken_2026');
$root=$argv[1];$socket=$argv[2];$dbname=$argv[3];
$config=$argv[4];$route=$argv[5];
$spec=json_decode($argv[6],true,512,JSON_THROW_ON_ERROR);
$connection=new mysqli('localhost','root','',$dbname,0,$socket);
$connection->set_charset('utf8mb4');
$from_id=(int)$spec['actor']; $admin=9001;$chat_id=$from_id;$message_id=77;
$data=(string)$spec['data'];
$botState=$spec['cached'];
$userInfo=['id'=>0,'userid'=>$from_id,'isAdmin'=>(int)$spec['cached_admin'],'step'=>'none'];
$mainValues=['change_bot_settings_message'=>'settings'];
$buttonValues=['cancel'=>'Cancel'];
$callbackId='fixture-callback';
$update=(object)['update_id'=>(int)$spec['update_id'],
    'callback_query'=>(object)[
        'id'=>'unique-callback-'.(int)$spec['update_id'],
        'from'=>(object)['id'=>$from_id,'is_bot'=>false],
        'message'=>(object)['message_id'=>$message_id,
                          'chat'=>(object)['id'=>$from_id,'type'=>'private']],
        'data'=>$data
    ]];
if (!empty($spec['invalid_source'])) {
    if ($spec['invalid_source']==='group')
        $update->callback_query->message->chat->type='group';
    elseif ($spec['invalid_source']==='token')
        unset($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']);
    elseif ($spec['invalid_source']==='message')
        $update->callback_query->message->message_id++;
}
$GLOBALS['events']=[];
function editText(...$args) { $GLOBALS['events'][]=['edit',$args[0]??null]; }
function answerCallbackQuery(...$args) {$GLOBALS['events'][]=['denied',$args[1]??null];}
function getGateWaysKeys(){return 'payment settings';}
function achbotGatewayAudienceKeyboard(){return 'cohort settings';}
require $root.'/src/Finance/PaymentLifecycle.php';
require $root.'/src/Subscription/DirectGift.php';
require $argv[7];
require $config;
require $route;
echo json_encode(['events'=>$GLOBALS['events']],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
'''

def git_blob(name: str) -> bytes:
    return subprocess.check_output(['git','show',f'{BASE}:{name}'],cwd=ROOT)

def extraction(config: bytes, bot: bytes, webhook: bytes) -> tuple[bytes,bytes,bytes]:
    start=config.index(b'function achbotMutateBotStates(')
    stop=config.index(b'\nfunction getRejectedAgentList()',start)
    a=bot.index(b'if(($data=="gateWays_Channels"')
    b=bot.index(b'// Only actual administrators may change invoice',a)
    wa=webhook.index(b'function achbotWebhookIngressAuthenticated(): bool')
    wb=webhook.index(b'if (!achbotWebhookIngressAuthenticated())',wa)
    return b'<?php\n'+config[start:stop]+b'\n',b'<?php\n'+bot[a:b]+b'\n',b'<?php\n'+webhook[wa:wb]+b'\n'

def sql(w,e,q):
    return dbops.sql(w,e,q)

def reset(w,e,states: dict,role=1):
    raw=json.dumps(states,ensure_ascii=False,separators=(',',':')).replace("'","''")
    sql(w,e,"DELETE FROM setting WHERE type IN ('BOT_STATES','ACHBOT_ADMIN_GATEWAY_SOURCE'); "
            f"INSERT INTO setting (type,value) VALUES ('BOT_STATES','{raw}'); "
            f"UPDATE users SET isAdmin={role},step='none' WHERE userid=9002;")

def read(w,e):
    r=sql(w,e,"SELECT value FROM setting WHERE type='BOT_STATES' LIMIT 1")
    return json.loads(r)

def marker(w,e):
    r=sql(w,e,"SELECT value FROM setting WHERE type='ACHBOT_ADMIN_GATEWAY_SOURCE' LIMIT 1")
    return json.loads(r) if r else None

def call(w,e,files,src,actor=9002,data='changeGateWaysnowPaymentOther',
         update_id=101,cached=None,cached_admin=1,invalid_source=None):
    spec={'actor':actor,'data':data,'update_id':update_id,
          'cached':cached if cached is not None else read(w,e),
          'cached_admin':cached_admin,'invalid_source':invalid_source}
    cmd=['php','-n','-d','extension=mysqlnd','-d','extension=mysqli',
         '-d','log_errors=1','-d',f'error_log={w}/gateway-admin.log',
         '-d','display_errors=0','-d','allow_url_fopen=0',
         '-d','disable_functions=exec,shell_exec,system,passthru,popen,proc_open,mail',
         str(files['driver']),str(ROOT),str(w/'mysql.sock'),DB,
         str(src['config']),str(src['bot']),
         json.dumps(spec,ensure_ascii=False),str(src['webhook'])]
    result=subprocess.run(cmd,cwd=w,env=e,capture_output=True,timeout=20)
    assert result.returncode==0 and result.stdout,(result.returncode,result.stdout[-1200:],result.stderr[-1500:])
    assert not result.stderr,(result.stderr[-1500:],)
    return json.loads(result.stdout)

def main():
    p=argparse.ArgumentParser()
    p.add_argument('--run-disposable',required=True,action='store_true')
    p.add_argument('--report',type=Path)
    args=p.parse_args()
    cases=[]
    bot=(ROOT/'bot.php').read_bytes()
    cfg=(ROOT/'config.php').read_bytes()
    baseline=extraction(git_blob('config.php'),git_blob('bot.php'),git_blob('bot.php'))
    current=extraction(cfg,bot,bot)
    with owned.owned_database() as (w,e):
        d=w/'gateway-source'
        d.mkdir()
        files={'driver':d/'driver.php'};files['driver'].write_text(DRIVER)
        sources={}
        for tag,group in [('old',baseline),('new',current)]:
            paths={}
            for key,content in zip(['config','bot','webhook'],group):
                path=d/f'{tag}-{key}.php'
                path.write_bytes(content);paths[key]=path
            sources[tag]=paths
        # Source-real proof: old route accepts a revoked delegate through a
        # cached isAdmin; current must reject without touching persisted flags.
        initial={'nowPaymentOther':'off','gatewayAudience':{'wallet':'all'}}
        reset(w,e,initial,role=0)
        old=call(w,e,files,sources['old'],cached=initial)
        assert read(w,e)['nowPaymentOther']=='on' and old['events'],old
        cases.append('red_revoked_delegate_changes_gateway_enabled')
        reset(w,e,initial,role=0)
        new=call(w,e,files,sources['new'],cached=initial)
        assert read(w,e)['nowPaymentOther']=='off' and not any(x[0]=='edit' for x in new['events']),new
        cases.append('green_revoked_delegate_cannot_change_gateway_enabled')
        # Two actors may have loaded an old snapshot while a newer setting
        # was persisted. Current must toggle the fresh document, not $botState.
        initial={'nowPaymentOther':'on','gatewayAudience':{'wallet':'all'}}
        stale={'nowPaymentOther':'off','gatewayAudience':{'wallet':'all'}}
        reset(w,e,initial)
        old=call(w,e,files,sources['old'],cached=stale)
        assert read(w,e)['nowPaymentOther']=='on' and old['events']
        cases.append('red_stale_admin_snapshot_loses_toggle_intent')
        reset(w,e,initial)
        new=call(w,e,files,sources['new'],cached=stale)
        assert read(w,e)['nowPaymentOther']=='off' and any(x[0]=='edit' for x in new['events'])
        cases.append('green_current_persisted_flag_toggle')
        # Delegated actor role is also rechecked for audience changes.
        reset(w,e,{'nowPaymentOther':'on','gatewayAudience':{'wallet':'all'}},role=0)
        old=call(w,e,files,sources['old'],data='cycleGatewayAudience_wallet')
        assert read(w,e)['gatewayAudience']['wallet']!='all' and old['events']
        cases.append('red_revoked_delegate_cycles_cohort')
        reset(w,e,{'nowPaymentOther':'on','gatewayAudience':{'wallet':'all'}},role=0)
        new=call(w,e,files,sources['new'],data='cycleGatewayAudience_wallet')
        assert read(w,e)['gatewayAudience']['wallet']=='all' and not any(x[0]=='edit' for x in new['events'])
        cases.append('green_revoked_delegate_cannot_cycle_cohort')
        # Newer accepted source must fence a delayed older one across actors.
        reset(w,e,{'nowPaymentOther':'off','gatewayAudience':{'wallet':'all'}})
        good=call(w,e,files,sources['new'],actor=9001,update_id=400,
                  data='changeGateWaysnowPaymentOther')
        assert read(w,e)['nowPaymentOther']=='on'
        assert marker(w,e) is not None
        newer=call(w,e,files,sources['new'],actor=9002,update_id=410,
                   data='changeGateWaysnowPaymentOther')
        assert read(w,e)['nowPaymentOther']=='off'
        delayed=call(w,e,files,sources['new'],actor=9001,update_id=400,
                    data='changeGateWaysnowPaymentOther')
        assert read(w,e)['nowPaymentOther']=='off'
        cases.append('global_update_watermark_rejects_late_replay_across_admins')
        # Distinct old update callback may not overwrite a newer cohort setting.
        delayed=call(w,e,files,sources['new'],actor=9001,update_id=405,
                     data='cycleGatewayAudience_wallet')
        assert read(w,e)['gatewayAudience']['wallet']=='all'
        cases.append('global_update_watermark_rejects_older_cohort_mutation')
        for kind in ('group','token','message'):
            reset(w,e,{'nowPaymentOther':'off','gatewayAudience':{'wallet':'all'}})
            result=call(w,e,files,sources['new'],invalid_source=kind)
            assert read(w,e)['nowPaymentOther']=='off'
            assert marker(w,e) is None
            cases.append('signed_private_callback_denies_'+kind)
        # Primary owner may operate, but old source cannot duplicate.
        reset(w,e,{'nowPaymentOther':'off','gatewayAudience':{'wallet':'all'}})
        result=call(w,e,files,sources['new'],actor=9001,update_id=820,
                    data='cycleGatewayAudience_wallet')
        assert read(w,e)['gatewayAudience']['wallet']=='agents'
        assert marker(w,e)['last_update_id']==820
        cases.append('primary_admin_can_cycle_with_source_record')
        # Userid nonunique in original DDL; a duplicate actor must fail.
        reset(w,e,{'nowPaymentOther':'off','gatewayAudience':{'wallet':'all'}})
        sql(w,e,"INSERT INTO users (userid,name,username,refcode,wallet,date,isAdmin,first_start) VALUES (9002,'Dup','duplicate','0',0,'1700000000',1,'sent')")
        result=call(w,e,files,sources['new'],actor=9002)
        assert read(w,e)['nowPaymentOther']=='off'
        assert marker(w,e) is None
        cases.append('ambiguous_delegate_identity_fail_closed')
        sql(w,e,"DELETE FROM users WHERE username='duplicate'")
        # The original nonunique setting.type must never choose a random
        # source marker, even if two settings rows existed before this code.
        reset(w,e,{'nowPaymentOther':'off','gatewayAudience':{'wallet':'all'}})
        sql(w,e,"INSERT INTO setting(type,value) VALUES "
            "('ACHBOT_ADMIN_GATEWAY_SOURCE','{}'),"
            "('ACHBOT_ADMIN_GATEWAY_SOURCE','{}')")
        result=call(w,e,files,sources['new'])
        assert read(w,e)['nowPaymentOther']=='off'
        assert not any(x[0]=='edit' for x in result['events'])
        cases.append('duplicate_source_marker_fails_closed')
        # Errors on the LAST BOT_STATES mutation must roll back the earlier
        # source marker INSERT as well: no false success and no stale lease.
        reset(w,e,{'nowPaymentOther':'off','gatewayAudience':{'wallet':'all'}})
        sql(w,e,"CREATE TRIGGER achbot_test_gateway_write_failure "
            "BEFORE UPDATE ON setting FOR EACH ROW "
            "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic gateway rollback'")
        result=call(w,e,files,sources['new'])
        assert read(w,e)['nowPaymentOther']=='off' and marker(w,e) is None
        assert not any(x[0]=='edit' for x in result['events'])
        cases.append('setting_sql_failure_rolls_back_source_and_state')
        sql(w,e,'DROP TRIGGER achbot_test_gateway_write_failure')
        result=call(w,e,files,sources['new'])
        assert read(w,e)['nowPaymentOther']=='on' and marker(w,e) is not None, result
        cases.append('after_failed_transaction_fresh_retry_can_commit')
        # A malicious or malformed callback field must not be a raw writer.
        reset(w,e,{'nowPaymentOther':'off','gatewayAudience':{'wallet':'all'}})
        result=call(w,e,files,sources['new'],data='changeGateWaysweSwapState')
        assert read(w,e)['nowPaymentOther']=='off' and marker(w,e) is None
        assert not any(x[0]=='edit' for x in result['events'])
        cases.append('unsupported_retired_gateway_never_enabled')
        result=call(w,e,files,sources['new'],data='cycleGatewayAudience_unknown')
        assert marker(w,e) is None
        cases.append('unsupported_cohort_name_never_persisted')
    result={'complete':True,'cases':len(cases),'scenarios':cases,
            'actual_bot_callback_extraction':True,'actual_config_writer_extraction':True,
            'real_original_schema_mariadb':True,'baseline_red':True,
            'new_source_green':True,'provider_effects':0,'production_effects':0}
    if args.report:args.report.write_text(json.dumps(result,ensure_ascii=False,indent=2)+'\n')
    print(json.dumps(result,ensure_ascii=False))

if __name__=='__main__':main()
