#!/usr/bin/env python3
"""Current source settings/renderer/admin/delivery against an owned socket-only DB."""
import argparse
import json
import subprocess
from pathlib import Path
import test_access_state as owned

ROOT=Path(__file__).resolve().parents[1]
PHP=r'''<?php
error_reporting(E_ALL & ~E_DEPRECATED);
set_error_handler(static function($s,$m,$f,$l) {
    if ($s===E_DEPRECATED) return true;
    throw new ErrorException($m,0,$s,$f,$l);
});
$root=$argv[1];chdir(dirname($argv[2]));
$connection=new mysqli('localhost','root','',$argv[3],0,$argv[2]);
$connection->set_charset('utf8mb4');
require_once $root.'/src/Subscription/ServerDisplaySettings.php';
use function AchBot\Subscription\Presentation\present;
$checks=0;
function verify($ok,$case) {
    global $checks;if (!$ok) throw new RuntimeException($case);$checks++;
}
function equal($a,$b,$case) {verify($a===$b,$case);}
function rejects($fn,$case) {
    try {$fn();} catch(Throwable $e) {verify(true,$case);return;}
    throw new RuntimeException('not rejected '.$case);
}
function snapshot() {
    global $connection;
    return [
        $connection->query('SELECT * FROM setting ORDER BY id')->fetch_all(MYSQLI_ASSOC),
        $connection->query('SELECT id,step,isAdmin FROM users ORDER BY id')->fetch_all(MYSQLI_ASSOC)
    ];
}
function noChange($fn,$case) {
    $before=snapshot();$fn();equal(snapshot(),$before,$case);
}
function settingRow($sid,$display,$provider='marzban',$source=1) {
    global $connection;
    $type=achbotServerDisplayType($sid);
    $value=json_encode(['version'=>1,'server_id'=>$sid,'provider'=>$provider,
        'updated_source'=>$source,'display'=>$display],JSON_THROW_ON_ERROR);
    $s=$connection->prepare('INSERT INTO setting(type,value) VALUES (?,?)');
    $s->bind_param('ss',$type,$value);$s->execute();$s->close();
}
function removeSetting($sid) {
    global $connection;$type=achbotServerDisplayType($sid);
    $s=$connection->prepare('DELETE FROM setting WHERE type=?');$s->bind_param('s',$type);
    $s->execute();$s->close();
}
$connection->query("INSERT INTO server_info(id,title,ucount,remark,flag,active,state) VALUES
    (1,'A',10,'a','',1,1),(2,'B',10,'b','',1,1),(3,'PG',10,'pg','',1,1),
    (4,'Unsupported',10,'unsupported','',1,1),(5,'Inactive',10,'inactive','',0,1)");
$connection->query("INSERT INTO server_config(id,panel_url,ip,sni,header_type,request_header,response_header,
    security,tlsSettings,type,username,password) VALUES
    (1,'https://fixture.invalid','','','none','','','none','','marzban','',''),
    (2,'https://fixture.invalid','','','none','','','none','','marzban','',''),
    (3,'https://fixture.invalid','','','none','','','none','','pasarguard','',''),
    (4,'https://fixture.invalid','','','none','','','none','','retired','',''),
    (5,'https://fixture.invalid','','','none','','','none','','marzban','','')");
$beforeSchema=preg_replace("/AUTO_INCREMENT=[0-9]+/", "", $connection->query("SHOW CREATE TABLE setting")->fetch_row()[1]);
$one='vless://native.invalid:443?type=ws#Fast';
$two='trojan://native.invalid:443#Backup';
$three='ss://native.invalid:443#Third';
$four='vless://native.invalid:8443#Fourth';
$sub='https://subscription.invalid/native?id=a&b=c';
$all=[$one,$two,$three,$four];
$native=['links'=>$all,'subscription_url'=>$sub];
$on=['configLinkState'=>'on','subLinkState'=>'on'];
equal(achbotServerDisplayOptions($connection,1,'marzban'),achbotServerDisplayDefault(),'missing_setting_native_default');
equal(achbotServerDisplayOptions($connection,3,'pasarguard'),achbotServerDisplayDefault(),'pg_default_native');
equal(present('pasarguard',$native,['configLinkState'=>'off','subLinkState'=>'off'],achbotServerDisplayDefault())['links'],$all,'retired_global_switches_do_not_compete');
equal(achbotServerDisplayOptions($connection,0,'marzban'),['policy'=>false],'zero_server_closed');
equal(achbotServerDisplayOptions($connection,4,'retired'),['policy'=>false],'retired_closed');
equal(achbotServerDisplayOptions($connection,5,'marzban'),['policy'=>false],'deleted_closed');
equal(achbotServerDisplayOptions($connection,1,'pasarguard'),['policy'=>false],'provider_mismatch_closed');
equal(achbotServerDisplayOptions(null,1,'marzban'),['policy'=>false],'no_db_closed');

// Exercise the authenticated handler rather than only storage helpers.
$admin=9001;$events=[];$cancelKey='cancel-key';$buttonValues=['cancel'=>'Cancel'];
$telegramWebhookSecret='synthetic_display_secret_v1';
$_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']=$telegramWebhookSecret;
$_SERVER['REQUEST_METHOD']='POST';
function achbotWebhookIngressAuthenticated() {
    return ($_SERVER['REQUEST_METHOD']??'')==='POST' &&
        hash_equals($GLOBALS['telegramWebhookSecret'],
            $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']??'');
}
function sendMessage(...$args) {$GLOBALS['events'][]=$args;return (object)['ok'=>true];}
function callback($bound,$source,$actor=9001,$forge='') {
    global $connection,$from_id,$chat_id,$message_id,$update,$userInfo,$data,$text,$events;
    $from_id=$chat_id=$actor;$message_id=80;$data=$bound;$text='';$events=[];
    $s=$connection->prepare('SELECT * FROM users WHERE userid=?');$s->bind_param('i',$actor);
    $s->execute();$userInfo=$s->get_result()->fetch_assoc();$s->close();
    $update=(object)['update_id'=>$source,'callback_query'=>(object)[
        'from'=>(object)['id'=>$forge==='sender'?1001:$actor,'is_bot'=>false],
        'message'=>(object)['message_id'=>80,'chat'=>(object)[
            'id'=>$actor,'type'=>$forge==='group'?'group':'private']],
        'data'=>$bound]];
    verify(achbotHandleServerDisplayAdmin(),'callback_consumed');
}
function message($body,$source,$actor=9001,$forge='') {
    global $connection,$from_id,$chat_id,$message_id,$update,$userInfo,$data,$text,$events;
    $from_id=$chat_id=$actor;$message_id=81;$data='';$text=$body;$events=[];
    $s=$connection->prepare('SELECT * FROM users WHERE userid=?');$s->bind_param('i',$actor);
    $s->execute();$userInfo=$s->get_result()->fetch_assoc();$s->close();
    $update=(object)['update_id'=>$source,'message'=>(object)[
        'message_id'=>81,'from'=>(object)['id'=>$forge==='sender'?1001:$actor,'is_bot'=>false],
        'chat'=>(object)['id'=>$actor,'type'=>'private'],'text'=>$body,
        'date'=>1800000000]];
    achbotHandleServerDisplayAdmin();
}
$policyA=['mode'=>'native','policy'=>['selectors'=>[['protocol'=>'trojan']],'qr'=>'first_config']];
$policyB=['mode'=>'native','policy'=>['selectors'=>[['name'=>'Fast']],'show_subscription'=>false]];
callback('serverDisplay1',10);
verify(str_contains(json_encode($events,JSON_UNESCAPED_UNICODE),'serverDisplayEdit1'),'marzban_admin_editor_visible');
callback('serverDisplay3',11);
verify(str_contains(json_encode($events,JSON_UNESCAPED_UNICODE),'serverDisplayEdit3'),'pg_admin_editor_visible');
callback('serverDisplayEdit1',20);message(json_encode($policyA),21);
callback('serverDisplayEdit2',30);message(json_encode($policyB),31);
equal(present('marzban',$native,$on,achbotServerDisplayOptions($connection,1,'marzban'))['links'],[$two],
    'server_a_native_selection');
$b=present('marzban',$native,$on,achbotServerDisplayOptions($connection,2,'marzban'));
equal([$b['links'],$b['subscription_url']], [[$one],''],'server_b_distinct_policy_same_provider');
equal(achbotServerDisplayOptions($connection,3,'pasarguard'),achbotServerDisplayDefault(),'new_provider_does_not_inherit_other_server');
noChange(static fn()=>message(json_encode($policyA),21),'replayed_save_no_write');
foreach ([
    ['serverDisplayEdit1',40,1001,''],
    ['serverDisplayEdit1',41,9001,'sender'],
    ['serverDisplayEdit1',42,9001,'group'],
    ['serverDisplayEdit1garbage',43,9001,''],
    ['serverDisplayEdit0',44,9001,''],
    ['serverDisplayEdit9999',45,9001,''],
    ['serverDisplayEdit4',46,9001,''],
] as $args) noChange(static fn()=>callback(...$args),'forged_callback_no_setting_or_step_mutation');
$_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']='forged';
noChange(static fn()=>callback('serverDisplayEdit1',47),'unauthenticated_callback_no_change');
$_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']=$telegramWebhookSecret;
callback('serverDisplayEdit1',50,9002);
$connection->query('UPDATE users SET isAdmin=0 WHERE userid=9002');
noChange(static fn()=>message(json_encode($policyA),51,9002),'revoked_admin_cannot_save');
$connection->query("UPDATE users SET isAdmin=1,step='none' WHERE userid=9002");
callback('serverDisplayEdit1',60);
foreach ([
    'null','[]','not json',
    '{"mode":"native","policy":{"show_configs":"false"}}',
    '{"mode":"native","policy":{"max_links":-1}}',
    '{"mode":"native","policy":{"max_links":1001}}',
    '{"mode":"native","policy":{"selectors":[{"index":"2"}]}}',
    '{"mode":"native","policy":{"selectors":[{}]}}',
    '{"mode":"native","policy":{"qr":"wrong"}}',
    '{"mode":"native","policy":{},"legacy":{}}'
] as $input) noChange(static fn()=>message($input,61),'invalid_input_no_write');
// Fresh frame and matching actor are required even in an active editor.
noChange(static fn()=>message(json_encode($policyA),62,9001,'sender'),'forged_message_no_write');
message('Cancel',63);
callback('serverDisplayEdit1',70);
// Concurrent editor changes the baseline; stale save cannot overwrite.
$connection->query("UPDATE setting SET value=CONCAT(value,' ') WHERE type='ACHBOT_SERVER_DISPLAY_1'");
noChange(static fn()=>message(json_encode($policyB),71),'stale_baseline_no_overwrite');
$connection->query("UPDATE users SET step='none' WHERE userid=9001");

// Malformed and duplicate stored records fail closed, with explicit repair.
removeSetting(1);settingRow(1,$policyA);
$connection->query("UPDATE setting SET value='invalid json' WHERE type='ACHBOT_SERVER_DISPLAY_1'");
equal(present('marzban',$native,$on,achbotServerDisplayOptions($connection,1,'marzban'))['qr_link'],'','malformed_storage_closed');
callback('serverDisplay1',80);
verify(str_contains(json_encode($events,JSON_UNESCAPED_UNICODE),'serverDisplayEdit1'),'invalid_single_setting_repair_entry');
callback('serverDisplayEdit1',81);message(json_encode($policyA),82);
equal(achbotServerDisplayOptions($connection,1,'marzban'),$policyA,'explicit_repair_succeeds');
settingRow(1,$policyA);
equal(achbotServerDisplayOptions($connection,1,'marzban'),['policy'=>false],'duplicate_storage_closed');
noChange(static fn()=>callback('serverDisplayEdit1',90),'duplicate_storage_admin_write_closed');
removeSetting(1);
settingRow(1,$policyA,'pasarguard');
equal(achbotServerDisplayOptions($connection,1,'marzban'),['policy'=>false],'stored_provider_identity_closed');
removeSetting(1);

// Explicit, validated Marzban positional compatibility remains available.
$legacy=['mode'=>'marzban_legacy','policy'=>new stdClass(),'legacy'=>[
    'showIndexes'=>[2,4],'linkIndices'=>[2,9],
    'hideConfigIfMany'=>false,'hideConnectLinkForMarzban'=>false]];
callback('serverDisplayEdit1',100);message(json_encode($legacy),101);
$opts=achbotServerDisplayOptions($connection,1,'marzban');
$v=present('marzban',$native,$on,$opts);
$d=present('marzban',$native,$on,$opts,'delivery');
equal($v['links'],[$two,$four],'managed_marzban_view_one_based');
equal($d['links'],[$three,$one],'managed_marzban_delivery_zero_based_and_fallback');
equal($v['qr_config_links'],$all,'managed_marzban_explicit_legacy_qr_semantics');
callback('serverDisplayEdit3',110);
noChange(static fn()=>message(json_encode($legacy),111),'pg_rejects_legacy_mode');
message(json_encode(['mode'=>'native','policy'=>['selectors'=>[['name'=>'Fast']],'qr'=>'first_config']]),112);
equal(present('pasarguard',$native,$on,achbotServerDisplayOptions($connection,3,'pasarguard'))['links'],[$one],
    'pg_native_name_selector_not_index');

// Retired global switches cannot be persisted or toggled by stale callbacks.
$cfg=file_get_contents($root.'/config.php');
$a=strpos($cfg,'function setSettings(');$b=strpos($cfg,'/**',$a);
eval(substr($cfg,$a,$b-$a));
noChange(static function() {
    equal(setSettings('subLinkState','off'),false,'retired_subscription_setter_rejected');
    equal(setSettings('configLinkState','off'),false,'retired_config_setter_rejected');
},'retired_setters_no_mutation');
function answerCallbackQuery(...$args) {$GLOBALS['events'][]=['callback-rejected'];}
$bot=file_get_contents($root.'/bot.php');
$a=strpos($bot,'if(($data=="botSettings"');$b=strpos($bot,'if($data=="changeUpdateConfigLinkState"',$a);
$toggle=substr($bot,$a,$b-$a);$from_id=9001;$userInfo=['isAdmin'=>true];$callbackId='fixture';
foreach (['changeBotsubLinkState','changeBotconfigLinkState'] as $data) {
    $before=snapshot();$events=[];eval($toggle);
    equal(snapshot(),$before,'stale_global_callback_no_write');
    equal($events,[['callback-rejected']],'stale_global_callback_bounded_response');
}
// Load actual facade bodies without bootstrap/private configuration.
$source=file_get_contents($root.'/config.php');
$a=strpos($source,'function buildSubscriptionPresentation(');
// Stop at the next retained facade group; obsolete protocol helpers are gone.
$b=$a===false?false:strpos($source,'function getUserOrderDetailKeys(',$a);
if ($a===false || $b===false || $b<=$a)
    throw new RuntimeException('current_presentation_facade_boundary_missing');
eval(str_replace('__DIR__',var_export($root,true),substr($source,$a,$b-$a)));
$showIndexes=[99];$linkIndices=[99];$hideConfigIfMany=true;$hideConnectLinkForMarzban=true;
$subscriptionPresentation=['marzban'=>['show_configs'=>false],'pasarguard'=>['show_configs'=>false]];
equal(buildSubscriptionPresentation('marzban',$native,$on,[],'view',2)['links'],[$one],
    'runtime_ignores_all_private_display_globals');
equal(buildSubscriptionPresentation('marzban',$native,$on,['policy'=>['show_configs'=>false]],'view',2)['links'],[$one],
    'caller_override_cannot_bypass_server_setting');
equal(buildSubscriptionPresentation('marzban',$native,$on,[],'view',0)['reason'],'invalid_policy',
    'missing_server_identity_fails_closed');
function achbotSupportsPanel($provider) {return in_array($provider,['marzban','pasarguard'],true);}
function RandomString() {return bin2hex(random_bytes(7));}
require_once $root.'/buy_helpers.php';
function delivery($provider,$sid,$response) {
    global $on;
    return buildLinksAndSendQr($provider,['panel_url'=>'https://panel.invalid','sub_links'=>'https://backup.invalid'],
        $on,'',$sid,'stable-id','','fixture',0,'',0,'','','','',$response,1001,2,30,[],[99],true,'',null,false);
}
$pg=delivery('pasarguard',3,['links'=>[$one,$two],'subscription_url'=>$sub]);
equal([$pg[0],$pg[2],$pg[5]],['','stable-id',$one],'pg_delivery_native_identity_qr');
equal(json_decode($pg[1],true),[$one,$two],'pg_persisted_native_links_unmodified');
verify(str_contains($pg[3],htmlspecialchars($one,ENT_QUOTES,'UTF-8')) &&
    !str_contains($pg[3],'trojan://') && !str_contains($pg[3],'https://panel.invalid') &&
    !str_contains($pg[3],'https://backup.invalid'),'pg_delivery_selection_native_no_vendor_rewrite');
$m=delivery('marzban',1,(object)['vray_links'=>$all,'sub_link'=>'/sub/native-token']);
equal([$m[0],$m[2],$m[5]],['native-token','native-token','https://panel.invalid/sub/native-token'],
    'marzban_delivery_preserves_native_token_and_managed_policy');
verify(str_contains($m[3],$three) && str_contains($m[3],htmlspecialchars($one,ENT_QUOTES,'UTF-8')),
    'real_delivery_uses_managed_legacy_not_old_parameters');
// The real renderer and delivery transport support a configured text-only receipt.
removeSetting(3);settingRow(3,['mode'=>'native','policy'=>['qr'=>'none']], 'pasarguard',200);
$textOnly=delivery('pasarguard',3,['links'=>[$one],'subscription_url'=>$sub]);
equal($textOnly[5],'','configured_text_only_has_no_qr');
verify(str_contains($textOnly[3],htmlspecialchars($one,ENT_QUOTES,'UTF-8')) &&
    str_contains($textOnly[3],htmlspecialchars($sub,ENT_QUOTES,'UTF-8')),'text_only_contains_real_selected_connection_content');
$events=[];
verify(sendQrFromLink('', $textOnly[3], $textOnly[4], '', 1001), 'text_only_needs_real_text_ack');
equal(count($events),1,'text_only_single_text_effect_no_photo');
removeSetting(3);settingRow(3,['mode'=>'native','policy'=>[]],'marzban',201);
noChange(static fn()=>rejects(static fn()=>achbotRequireServerDisplay($connection,3,'pasarguard'),
    'invalid_display_preflight_throws'),'invalid_preflight_read_only');
// Normal administrator controls require no JSON and bind the visible revision.
$serverOneBefore=achbotServerDisplayOptions($connection,1,'marzban');
callback('serverDisplay2',300);
verify(str_contains(json_encode($events,JSON_UNESCAPED_UNICODE),'serverDisplaySet2_cfg_flip_31') &&
    !str_contains($events[0][0],'<pre>'),'friendly_summary_and_normal_controls');
callback('serverDisplaySet2_cfg_flip_31',301);
equal(achbotServerDisplayOptions($connection,2,'marzban')['policy']['show_configs'],false,'normal_config_toggle');
equal(achbotServerDisplayOptions($connection,1,'marzban'),$serverOneBefore,'normal_toggle_isolated_by_server');
noChange(static fn()=>callback('serverDisplaySet2_cfg_flip_31',301),'normal_toggle_replay_rejected');
noChange(static fn()=>callback('serverDisplaySet2_cfgx_flip_301',302),'forged_control_field_rejected');
callback('serverDisplaySet2_cfg_flip_301',303);
callback('serverDisplaySet2_sub_flip_303',304);
equal(achbotServerDisplayOptions($connection,2,'marzban')['policy']['show_subscription'],true,'normal_subscription_toggle');
callback('serverDisplayQr2',305);
verify(str_contains(json_encode($events,JSON_UNESCAPED_UNICODE),'serverDisplaySet2_qr_none_304'),'normal_qr_choice_menu');
callback('serverDisplaySet2_qr_none_304',306);
equal(achbotServerDisplayOptions($connection,2,'marzban')['policy']['qr'],'none','normal_text_only_choice');
callback('serverDisplayValue2_max_306',307);
noChange(static fn()=>message('-1',308),'guided_number_invalid_no_write');
message('2',309);
equal(achbotServerDisplayOptions($connection,2,'marzban')['policy']['max_links'],2,'guided_number_save');
callback('serverDisplayValue2_protocols_309',310);message('vless,trojan',311);
equal(achbotServerDisplayOptions($connection,2,'marzban')['policy']['selectors'],
    [['protocol'=>'vless'],['protocol'=>'trojan']],'guided_native_protocol_selection');
callback('serverDisplayValue2_names_311',312);message("Fast\nBackup",313);
equal(achbotServerDisplayOptions($connection,2,'marzban')['policy']['selectors'],
    [['name'=>'Fast'],['name'=>'Backup']],'guided_native_name_selection');
callback('serverDisplayValue2_threshold_313',314);message('/off',315);
verify(!isset(achbotServerDisplayOptions($connection,2,'marzban')['policy']['hide_if_more_than']),'guided_threshold_off');
callback('serverDisplaySet2_mode_marzban_legacy_315',316);
callback('serverDisplayValue2_view_316',317);message('2,4',318);
callback('serverDisplayValue2_delivery_318',319);message('2,9',320);
$managed=achbotServerDisplayOptions($connection,2,'marzban');
equal(present('marzban',$native,$on,$managed)['links'],[$two,$four],'guided_legacy_one_based_view');
equal(present('marzban',$native,$on,$managed,'delivery')['links'],[$three,$one],'guided_legacy_zero_based_delivery');
removeSetting(3);settingRow(3,achbotServerDisplayDefault(),'pasarguard',330);
noChange(static fn()=>callback('serverDisplaySet3_mode_marzban_legacy_330',331),'normal_pg_rejects_legacy_positions');
noChange(static fn()=>callback('serverDisplaySet2_sub_flip_31',332),'normal_old_screen_revision_rejected');
// Native helper resolves the actual order logical server, including shared panels.
$order=['id'=>1,'server_id'=>1,'link'=>json_encode($all),'token'=>'native-token'];
$server=['type'=>'marzban','panel_url'=>'https://native.invalid','sub_links'=>''];
equal(achbotOrderPresentation($connection,$order,$server,$on)['links'],[$two,$four],'actual_order_helper_uses_server_one_policy');
removeSetting(2);settingRow(2,$policyB,'marzban',400);
$order['server_id']=2;
equal(achbotOrderPresentation($connection,$order,$server,$on)['links'],[$one],'actual_order_helper_uses_server_two_policy');
// Current refresh and administrator account-list paths consume the same resolver.
$a=strpos($cfg,'function getServerConfigById(');$b=strpos($cfg,'function getOrderTrafficInfo(',$a);
eval(str_replace('__DIR__',var_export($root,true),substr($cfg,$a,$b-$a)));
function alert(...$args) {$GLOBALS['events'][]=['alert'=>(string)($args[0]??'')];}
function sendPhoto(...$args) {$GLOBALS['events'][]=['photo'=>true];return (object)['ok'=>true];}
function getMarzbanUserInfo($sid,$remark) {
    $GLOBALS['native_reads']=($GLOBALS['native_reads']??0)+1;
    return (object)['links'=>$GLOBALS['all'],'subscription_url'=>$GLOBALS['sub']];
}
$from_id=1001;$botState=['configLinkState'=>'off','subLinkState'=>'off'];$botUrl='https://fixture.invalid/';$mainValues=['please_wait_message'=>'Working'];
$userInfo=['step'=>'none','isAdmin'=>false,'temp'=>json_encode(['server_id'=>2,'remark'=>'fixture'])];
$data='sConfigUpdate1';$events=[];$native_reads=0;
require $root.'/src/Subscription/ConfigRefresh.php';
equal($native_reads,0,'refresh_rejects_forged_logical_server_before_provider_read');
$data='sConfigUpdate2garbage';require $root.'/src/Subscription/ConfigRefresh.php';
equal($native_reads,0,'refresh_rejects_malformed_callback');
$data='sConfigUpdate2';$events=[];
require $root.'/src/Subscription/ConfigRefresh.php';
equal($native_reads,1,'refresh_uses_native_provider_data_once');
$rendered=json_encode($events,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
verify(str_contains($rendered,htmlspecialchars($one,ENT_QUOTES,'UTF-8')) &&
    !str_contains($rendered,$two),'refresh_applies_managed_native_selection_to_qr_and_text');
verify(count(glob(getcwd().'/*.png'))===0,'refresh_qr_files_cleaned');
function jdate(...$args) {return 'fixture-date';}
$links=$connection->real_escape_string(json_encode($all));
$connection->query("INSERT INTO orders_list(userid,token,transid,fileid,server_id,inbound_id,remark,uuid,
    protocol,expire_date,link,amount,status,date) VALUES
    (1001,'native-token','',7,2,0,'fixture','id','vless',2000000000,'".$links."',100,1,'1700000000')");
$data='wizwizplanacclist7';$from_id=9001;$userInfo=['step'=>'none','isAdmin'=>true];
$channelLock='fixture';$events=[];
$plans=file_get_contents($root.'/src/Plans/Administration.php');
$a=strpos($plans,"if(preg_match('/^wizwizplanacclist");
$b=strpos($plans,"if(preg_match('/^wizwizplandelete",$a);
eval(substr($plans,$a,$b-$a));
$rendered=json_encode($events,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
verify(str_contains($rendered,htmlspecialchars($one,ENT_QUOTES,'UTF-8')) &&
    !str_contains($rendered,$two),'actual_admin_plan_account_list_uses_per_server_policy');
$tooMany=['links'=>array_map(static fn($i)=>'vless://native.invalid/'.$i,range(1,200)),
    'subscription_url'=>$sub];
$bounded=present('pasarguard',$tooMany,$on,achbotServerDisplayDefault());
verify(count($bounded['qr_config_links'])<=10 && strlen($bounded['config_html'].$bounded['subscription_html'].$bounded['backup_html'])<=2200
    && $bounded['limited'] && $bounded['notice_html']!=='','large_native_result_bounded_without_uri_truncation');
equal(preg_replace("/AUTO_INCREMENT=[0-9]+/", "", $connection->query("SHOW CREATE TABLE setting")->fetch_row()[1]),$beforeSchema,'no_ddl_or_schema_change');
echo json_encode(['pass'=>true,'checks'=>$checks,'database'=>'owned socket only','network_calls'=>0],JSON_THROW_ON_ERROR);
'''
def main():
    p=argparse.ArgumentParser()
    p.add_argument('--run-disposable',action='store_true',required=True)
    p.add_argument('--report',type=Path)
    args=p.parse_args()
    with owned.owned_database() as (work,env):
        runner=work/'server-display.php'
        runner.write_text(PHP)
        result=subprocess.run(['php',str(runner),str(ROOT),str(work/'mysql.sock'),owned.NAMES[1]],
            env=env,capture_output=True,text=True,timeout=45)
        if result.returncode:
            raise RuntimeError(result.stdout[-1000:]+'\n'+result.stderr[-2500:])
        report=json.loads(result.stdout)
    if args.report:
        args.report.write_text(json.dumps(report,indent=2)+'\n')
    print(json.dumps(report))
if __name__=='__main__':
    main()
