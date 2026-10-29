#!/usr/bin/env python3
"""Source-real instance content/settings with owned socket MariaDB and captured Telegram."""
from __future__ import annotations
import argparse,hashlib,json,pathlib,re,shutil,subprocess
import test_access_state as owned
ROOT=pathlib.Path(__file__).resolve().parents[1]
DB=owned.NAMES[1]
DRIVER=r"""<?php
error_reporting(E_ALL);
set_error_handler(static function($level,$message){if($level===E_DEPRECATED)return true;throw new ErrorException($message,0,$level);});
$root=$argv[1];$socket=$argv[2];$dbName=$argv[3];$spec=json_decode($argv[4],true,512,JSON_THROW_ON_ERROR);
require $root.'/src/Admin/InstanceContent.php';
if(($spec['mode']??'')==='pure'){
    $checks=[];$check=static function($ok,$label)use(&$checks){if(!$ok)throw new RuntimeException($label);$checks[]=$label;};
    $check(achbotInstanceInputHtml('متن <b> & "')==='متن &lt;b&gt; &amp; &quot;','literal_html_escaped');
    $check(achbotInstanceInputHtml('🔥 سلام & <tag>',[['type'=>'bold','offset'=>3,'length'=>4]])==='🔥 <b>سلام</b> &amp; &lt;tag&gt;','utf16_emoji_entities');
    $check(achbotInstanceInputHtml('سلام',[['type'=>'bold','offset'=>0,'length'=>4],['type'=>'italic','offset'=>0,'length'=>4]])==='<b><i>سلام</i></b>','nested_entities');
    $check(achbotInstanceInputHtml('abcd',[['type'=>'bold','offset'=>0,'length'=>3],['type'=>'italic','offset'=>1,'length'=>3]])===null,'crossing_entities_rejected');
    $check(achbotInstanceInputHtml('🔥 متن',[['type'=>'bold','offset'=>1,'length'=>1]])===null,'surrogate_split_rejected');
    $check(achbotInstanceInputHtml('لینک',[['type'=>'text_link','offset'=>0,'length'=>4,'url'=>'javascript:alert(1)']])===null,'unsafe_entity_url_rejected');
    $check(achbotInstanceInputHtml('لینک',[['type'=>'text_link','offset'=>0,'length'=>4,'url'=>'https://example.com/?a=1&b=2']])==='<a href="https://example.com/?a=1&amp;b=2">لینک</a>','entity_url_attribute_escaped');
    $check(achbotInstanceInputHtml('')===null&&achbotInstanceInputHtml(str_repeat('x',3001))===null,'input_bounds');
    $check(achbotInstanceInputHtml(str_repeat('🔥',1501))===null,'utf16_input_bound');
    $check(str_contains(achbotInstanceMessage(['supportID'=>str_repeat('x',1000),'instanceMessages'=>['start_message'=>str_repeat('SUPPORTID ',5)]],'start_message',1),'تنظیمات ربات'),'expanded_template_bound');
    $check(\AchBot\Presentation\Text\destinationUrl('@support_example')==='https://t.me/support_example','telegram_destination');
    $check(\AchBot\Presentation\Text\supportUrl([],'trusted-fallback')==='trusted-fallback','explicit_support_fallback_contract');
    $private=['lockChannel'=>'-1001234567890','lockChannelJoinUrl'=>'https://t.me/+NEW_SYNTHETIC'];
    $check(\AchBot\Presentation\Text\lockChannelJoinUrl($private,'https://t.me/+OLD_SYNTHETIC')==='https://t.me/+NEW_SYNTHETIC','private_numeric_configured_invite');
    $check($private['lockChannel']==='-1001234567890','resolver_preserves_membership_identity');
    $check(\AchBot\Presentation\Text\lockChannelJoinUrl(['lockChannel'=>'@public_example'],'https://t.me/+OLD_SYNTHETIC')==='https://t.me/public_example','public_channel_derivation');
    $check(\AchBot\Presentation\Text\lockChannelJoinUrl(['lockChannel'=>'+INVITE_SYNTHETIC'],'')==='https://t.me/+INVITE_SYNTHETIC','bare_invite_derivation');
    $check(\AchBot\Presentation\Text\lockChannelJoinUrl(['lockChannel'=>'-1001234567890'],'https://t.me/+OLD_SYNTHETIC')==='https://t.me/+OLD_SYNTHETIC','legacy_private_invite_fallback');
    $check(\AchBot\Presentation\Text\lockChannelJoinUrl(['lockChannel'=>'-1001234567890','lockChannelJoinUrl'=>'javascript:x'],'https://t.me/+OLD_SYNTHETIC')==='https://t.me/+OLD_SYNTHETIC','invalid_override_uses_valid_legacy');
    $check(\AchBot\Presentation\Text\lockChannelJoinUrl(['lockChannel'=>'-1001234567890'],'javascript:x')==='','invalid_join_destinations_empty');
    $check(\AchBot\Presentation\Text\lockChannelJoinUrl(['lockChannel'=>'@public_example','lockChannelJoinUrl'=>'https://t.me/+NEW_SYNTHETIC'],'')==='https://t.me/+NEW_SYNTHETIC','explicit_invite_precedence');

    foreach(['javascript:x','https://user:pass@example.com/','https://example.com/a b',"https://example.com/\nsecret"]as $url)
        $check(\AchBot\Presentation\Text\destinationUrl($url)==='','unsafe_destination_'.count($checks));
    $check(\AchBot\Presentation\Text\configuredDestination(['cooperationUrl'=>'https://partners.example/','supportID'=>'@support_example'],'cooperationUrl',true)==='https://partners.example/','independent_cooperation_destination');
    $check(\AchBot\Presentation\Text\configuredDestination(['supportID'=>'javascript:x'],'cooperationUrl',true)==='','no_invalid_support_fallback');
    $raw=['format'=>'html','text'=>'<b>برند</b> SUPPORTID userid'];
    $a=['instanceMessages'=>['start_message'=>$raw],'supportID'=>'@my_userid<&>'];
    $check(achbotInstanceMessage($a,'start_message',123)==='<b>برند</b> @my_userid&lt;&amp;&gt; 123','escaped_one_pass_placeholders');
    $check(str_contains(achbotInstanceMessage([],'start_message',123),'تنظیمات ربات'),'unset_guidance');
    $check(!str_contains(achbotInstanceMessage([],'start_message',123),'Hell'),'no_legacy_brand_fallback');
    $check(achbotInstanceMessage(['start_message'=>'نمونه قدیمی'],'start_message',1)==='نمونه قدیمی','legacy_persisted_override');
    $check(str_contains(achbotInstanceMessage(['start_message'=>'قدیمی','instanceMessages'=>['start_message'=>null]],'start_message',1),'تنظیمات ربات'),'clear_does_not_restore_legacy');
    $check(achbotInstanceMessage(['instanceMessages'=>['start_message'=>'الف']],'start_message',1)!==achbotInstanceMessage(['instanceMessages'=>['start_message'=>'ب']],'start_message',1),'instances_independent');
    $v=achbotInstanceContentValues(['start_message'=>'old','buy_subscription_detail'=>'PRICE VOLUME'],['instanceMessages'=>['purchase_note'=>'یادداشت']],123);
    $check($v['buy_subscription_detail']==="PRICE VOLUME\n\nیادداشت",'transaction_tokens_retained');
    echo json_encode(['checks'=>$checks],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;
}
$connection=new mysqli('localhost','root','',$dbName,0,$socket);$connection->set_charset('utf8mb4');
$from_id=(int)($spec['actor']??9002);$admin=9001;$message_id=77;$first_name='Synthetic';$username='synthetic';
$buttonValues=achbotMessages('legacy_buttons');$cancelKey='CANCEL';$mainValues=[];
$botState=json_decode($connection->query("SELECT value FROM setting WHERE type='BOT_STATES'")->fetch_assoc()['value'],true);
require $root.'/Access.php';require $root.'/writer.php';require $root.'/set-user.php';
$userInfo=$connection->query('SELECT * FROM users WHERE userid='.(int)$from_id)->fetch_assoc();
if(($spec['mode']??'')==='menus'){
    require $root.'/main-keys.php';require $root.'/bot-settings.php';
    echo json_encode(['main'=>json_decode(getMainKeys(),true),'settings'=>json_decode(getBotSettingKeys(),true)],JSON_THROW_ON_ERROR);exit;
}
$events=[];
function sendMessage($text,$key=null,$parse='HTML') {global $events;$events[]=['send',$text,$key,$parse];}
function editText($id,$text,$key=null,$parse='HTML') {global $events;$events[]=['edit',$text,$key,$parse];}
if(($spec['mode']??'')==='join'){
    $mainValues=achbotMessages('legacy_messages');
    $channelLock=$botState['lockChannel']??'';
    $joinLink=$spec['legacy_join']??'https://t.me/+OLD_SYNTHETIC';
    $text='/start';
    require $root.(($spec['baseline']??false)?'/baseline-join-ui.php':'/join-ui.php');
    echo json_encode(['events'=>$events,'membership'=>$channelLock,'state'=>$botState],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;
}

$data=$spec['data']??null;$text=($spec['use_cancel_button']??false)?$buttonValues['cancel']:($spec['text']??null);
$actorObject=(object)['id'=>$from_id,'is_bot'=>false];$chat=(object)['id'=>$from_id,'type'=>'private'];
if($spec['kind']==='callback'){
    $update=(object)['callback_query'=>(object)['from'=>$actorObject,'data'=>$data,'message'=>(object)['message_id'=>77,'chat'=>$chat]]];
}else{
    $update=(object)['message'=>(object)['from'=>$actorObject,'text'=>$text,'entities'=>$spec['entities']??[],'message_id'=>77,'chat'=>$chat]];
}
if(($spec['invalid']??'')==='group'){$message=$spec['kind']==='callback'?$update->callback_query->message:$update->message;$message->chat->type='group';}
if(($spec['invalid']??'')==='sender'){$source=$spec['kind']==='callback'?$update->callback_query:$update->message;$source->from->id=1001;}
$r=$connection->query('SELECT step FROM users WHERE userid='.(int)$from_id)->fetch_assoc();
$userInfo=['step'=>$spec['cached_step']??$r['step'],'isAdmin'=>1];
$handled=achbotHandleInstanceContent($update,$data,$text,$userInfo);
$state=json_decode($connection->query("SELECT value FROM setting WHERE type='BOT_STATES'")->fetch_assoc()['value'],true);
$step=$connection->query('SELECT step FROM users WHERE userid='.(int)$from_id)->fetch_assoc()['step'];
$render=achbotInstanceContentValues(['buy_subscription_detail'=>'PRICE VOLUME'],$state,$from_id);
echo json_encode(['handled'=>$handled,'events'=>$events,'state'=>$state,'step'=>$step,'render'=>$render],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
"""
def main():
    p=argparse.ArgumentParser()
    p.add_argument('--run-disposable',action='store_true',required=True)
    p.add_argument('--catalog-source',type=pathlib.Path,default=ROOT)
    p.add_argument('--access-source',type=pathlib.Path,default=ROOT/'src/Admin/Access.php')
    p.add_argument('--report',type=pathlib.Path)
    a=p.parse_args();checks=[]
    def check(ok,label):
        assert ok,label;checks.append(label)
    cfg=(ROOT/'config.php').read_text();bot=(ROOT/'bot.php').read_text()
    # Integrated ancestor retains the exact old membership/join blocks after worker cleanup.
    baseline='4548705609518f741d14375c9d47739eb302c3b6'
    old_bot=subprocess.check_output(['git','show',baseline+':bot.php'],cwd=ROOT).decode()
    old_cfg=subprocess.check_output(['git','show',baseline+':config.php'],cwd=ROOT).decode()
    def membership_block(source):
        start=source.index("$channelLock = $botState['lockChannel'];")
        return source[start:source.index('// Channel exemption is dispatched',start)]
    check(membership_block(cfg)==membership_block(old_cfg),'membership_checks_byte_unchanged')
    def join_block(source):
        marker="        $joinUrl = \\AchBot\\Presentation\\Text\\lockChannelJoinUrl("
        start=source.index(marker) if marker in source else source.index("        $lockChannelValue = trim($botState['lockChannel'] ?? '');")
        return '<?php\n'+source[start:source.index('\n        exit;',start)]
    baseline_red=False
    with owned.owned_database() as (w,e):
        fixture=w/'instance-fixture';(fixture/'src/Admin').mkdir(parents=True);(fixture/'src/Presentation').mkdir();(fixture/'messages/fa').mkdir(parents=True)
        for rel in ['src/Admin/InstanceContent.php','src/Presentation/Text.php','messages/loader.php']:
            shutil.copy2(ROOT/rel,fixture/rel)
        for who in ['user','admin']:shutil.copy2(a.catalog_source/f'messages/fa/{who}.php',fixture/f'messages/fa/{who}.php')
        shutil.copy2(a.access_source,fixture/'Access.php')
        start=cfg.index('function achbotMutateBotStates(');end=cfg.index('function achbotAdminGatewayChange(',start)
        (fixture/'writer.php').write_text('<?php\n'+cfg[start:end])
        start=cfg.index('function setUser(');end=cfg.index('function generateRandomString(',start)
        (fixture/'set-user.php').write_text('<?php\n'+cfg[start:end]);(fixture/'driver.php').write_text(DRIVER)
        start=cfg.index('function getMainKeys()');end=cfg.index('function getAgentKeys()',start)
        main_keys=cfg[start:end];(fixture/'main-keys.php').write_text('<?php\n'+main_keys)
        start=cfg.index('function getBotSettingKeys()');end=cfg.index('function fetchData(',start)
        bot_settings=cfg[start:end];(fixture/'bot-settings.php').write_text('<?php\n'+bot_settings)
        (fixture/'join-ui.php').write_text(join_block(bot))
        (fixture/'baseline-join-ui.php').write_text(join_block(old_bot))
        client=['mariadb','--no-defaults','--protocol=SOCKET','--socket='+str(w/'mysql.sock'),'-uroot','--batch','--skip-column-names',DB]
        def sql(query):
            r=subprocess.run(client,input=query.encode(),env=e,capture_output=True,check=True);return r.stdout.decode().strip()
        defaults={key:'off' for key in re.findall(r"\$botState\['([^']+)'\]",main_keys+bot_settings)}
        defaults.update({'supportID':'@support_example','cooperationUrl':'https://partners.example.com/start','helpChannel':'@example_help'})
        state_json=json.dumps(defaults).replace("'","''")
        sql("INSERT INTO setting(type,value)VALUES('BOT_STATES','"+state_json+"'),('ADMIN_ROLE_9002','manager'),('PAYMENT_KEYS','{}');")
        wallets_before=sql('SELECT userid,wallet FROM users ORDER BY id')
        keys_before=sql("SELECT value FROM setting WHERE type='PAYMENT_KEYS'")
        def call(**spec):
            cmd=['php','-d','allow_url_fopen=0','-d','display_errors=0','-d','log_errors=1',
                 '-d',f'error_log={w}/instance.log','-d','disable_functions=curl_init,curl_exec,exec,shell_exec,system,passthru,popen,proc_open,mail',
                 str(fixture/'driver.php'),str(fixture),str(w/'mysql.sock'),DB,json.dumps(spec)]
            r=subprocess.run(cmd,env=e,capture_output=True,timeout=15)
            assert r.returncode==0 and not r.stderr,(r.returncode,r.stdout[-800:],r.stderr,(w/'instance.log').read_text()[-1000:] if(w/'instance.log').exists()else'')
            return json.loads(r.stdout)
        def state_update(**values):
            doc=json.loads(sql("SELECT value FROM setting WHERE type='BOT_STATES'"));doc.update(values)
            raw=json.dumps(doc).replace("'","''")
            sql("UPDATE setting SET value='"+raw+"' WHERE type='BOT_STATES'")
        def join_keyboard(result):
            return json.loads(result['events'][0][2])['inline_keyboard']
        private_id='-1001234567890';explicit='https://t.me/+NEW_SYNTHETIC?source=bot&name=invite'
        state_update(lockChannel=private_id,lockChannelJoinUrl=explicit)
        old_join=call(mode='join',baseline=True);new_join=call(mode='join')
        baseline_red=join_keyboard(old_join)[0][0]['url']!=explicit and join_keyboard(old_join)[0][0]['url']=='https://t.me/+OLD_SYNTHETIC'
        check(baseline_red,'baseline_private_invite_ignores_configured_destination_red')
        keyboard=join_keyboard(new_join)
        check(len(keyboard)==2 and keyboard[0][0]['url']==explicit and keyboard[1][0]['callback_data']=='haveJoined/start' and new_join['events'][0][3]=='HTML','production_join_keyboard_shape_and_raw_native_url')
        check(new_join['membership']==private_id and new_join['state']['lockChannel']==private_id,'production_join_membership_unchanged')
        state_update(lockChannelJoinUrl='javascript:x')
        check(join_keyboard(call(mode='join'))[0][0]['url']=='https://t.me/+OLD_SYNTHETIC','production_invalid_override_falls_back')
        missing=call(mode='join',legacy_join='javascript:x')
        check(len(join_keyboard(missing))==1 and 'url'not in join_keyboard(missing)[0][0],'production_missing_invite_has_no_broken_url_button')
        state_update(lockChannelJoinUrl='')
        pure=call(mode='pure');checks.extend(pure['checks'])
        menus=call(mode='menus',actor=1001)
        buttons=[button for row in menus['main']['inline_keyboard'] for button in row]
        check(any(button.get('url')=='https://partners.example.com/start' for button in buttons),'main_menu_uses_cooperation_destination')
        check(any(button.get('url')=='https://t.me/example_help' for button in buttons),'main_menu_uses_help_channel')
        check(any(button.get('callback_data')=='ic|m' for row in menus['settings']['inline_keyboard'] for button in row),'bot_settings_reaches_instance_editor')
        r=call(kind='callback',data='ic|m');check(any(x[0]=='edit'for x in r['events']),'manager_settings_menu')
        r=call(kind='callback',data='ic|e|start_message');check(r['step']=='achbotInstance:start_message','message_editor_opens')
        r=call(kind='message',text='🔥 سلام SUPPORTID userid',entities=[{'type':'bold','offset':3,'length':4}])
        check(r['state']['instanceMessages']['start_message']['text']=='🔥 <b>سلام</b> SUPPORTID userid' and r['step']=='none','formatted_message_persisted')
        check(r['render']['start_message']=='🔥 <b>سلام</b> @support_example 9002','fresh_render_uses_persisted_text')
        before=r['state']
        r=call(kind='message',text='replay',cached_step='achbotInstance:start_message');check(r['state']==before,'stale_cached_step_no_write')
        call(kind='callback',data='ic|e|cooperationUrl');r=call(kind='message',text='https://partners.example.com/');check(r['state']['cooperationUrl']=='https://partners.example.com/','cooperation_destination_persisted')
        r=call(kind='callback',data='ic|m')
        menu=json.loads(next(event[2] for event in r['events'] if event[0]=='edit'))['inline_keyboard']
        check(any(button.get('callback_data')=='ic|e|lockChannelJoinUrl' for row in menu for button in row),'lock_join_destination_in_existing_settings_menu')
        call(kind='callback',data='ic|e|lockChannelJoinUrl');r=call(kind='message',text=explicit)
        check(r['state']['lockChannelJoinUrl']==explicit and r['state']['lockChannel']==private_id,'lock_join_invite_saved_without_membership_change')
        call(kind='callback',data='ic|e|lockChannelJoinUrl');r=call(kind='message',text='javascript:x')
        check(r['state']['lockChannelJoinUrl']==explicit,'lock_join_invalid_edit_preserves_value')
        call(kind='callback',data='ic|e|lockChannelJoinUrl');r=call(kind='message',text='/clear')
        check(r['state']['lockChannelJoinUrl']=='' and r['state']['lockChannel']==private_id,'lock_join_clear_preserves_membership')
        check(join_keyboard(call(mode='join'))[0][0]['url']=='https://t.me/+OLD_SYNTHETIC','lock_join_clear_restores_legacy_fallback')
        state_update(lockChannel='@public_example')
        check(join_keyboard(call(mode='join'))[0][0]['url']=='https://t.me/public_example','lock_join_clear_restores_public_derivation')
        state_update(lockChannel=private_id)
        call(kind='callback',data='ic|e|helpChannel');r=call(kind='message',text='javascript:alert(1)');check(r['state']['helpChannel']=='@example_help','invalid_destination_not_persisted')
        r=call(kind='message',text='@example_help');check(r['state']['helpChannel']=='https://t.me/example_help','help_channel_normalized')
        call(kind='callback',data='ic|e|start_message');r=call(kind='message',text='/clear');check(r['state']['instanceMessages']['start_message']is None and 'تنظیمات ربات'in r['render']['start_message'],'clear_returns_setup_guidance')
        call(kind='callback',data='ic|e|purchase_note');r=call(kind='message',text='x'*1201);check('purchase_note'not in r['state'].get('instanceMessages',{}),'purchase_note_bound')
        r=call(kind='message',text='Cancel');check(r['step']=='none' and r['state']['instanceMessages']['purchase_note']['text']=='Cancel','ordinary_text_saved_not_assumed_cancel')
        call(kind='callback',data='ic|e|purchase_note');r=call(kind='message',text='/cancel');check(r['step']=='none','cancel_consumes_step')
        call(kind='callback',data='ic|e|purchase_note');r=call(kind='message',use_cancel_button=True);check(r['step']=='none','actual_cancel_button_consumes_step')
        call(kind='callback',data='ic|e|start_message');sql("UPDATE setting SET value='support' WHERE type='ADMIN_ROLE_9002'")
        before=call(kind='callback',data='ic|m')['state'];r=call(kind='message',text='forbidden');check(r['state']==before,'demotion_during_editor_denies_write')
        sql("UPDATE setting SET value='manager' WHERE type='ADMIN_ROLE_9002'")
        before=r['state'];r=call(kind='message',text='forbidden',invalid='group');check(r['state']==before,'group_input_denied')
        r=call(kind='message',text='forbidden',invalid='sender');check(r['state']==before,'foreign_sender_denied')
        sql("UPDATE users SET isAdmin=0 WHERE userid=9002")
        r=call(kind='message',text='forbidden');check(r['state']==before,'revoked_admin_denied')
        sql("UPDATE users SET step='none' WHERE userid=9001")
        r=call(kind='callback',data='ic|e|start_message',actor=9001);check(r['step']=='achbotInstance:start_message','owner_without_admin_bit_editor')
        r=call(kind='message',text='متن مالک',actor=9001);check(r['state']['instanceMessages']['start_message']['text']=='متن مالک','owner_save')
        check(sql('SELECT userid,wallet FROM users ORDER BY id')==wallets_before and sql("SELECT value FROM setting WHERE type='PAYMENT_KEYS'")==keys_before,'wallets_and_payment_settings_unchanged')
        check(all(r['state'].get(key)==value for key,value in defaults.items() if key not in ['cooperationUrl','helpChannel']),'unrelated_bot_settings_preserved')
    report={'result':'PASS','cases':len(checks),'checks':checks,'source_real':True,'socket_only_original_schema':True,'catalog_source':str(a.catalog_source),'catalog_source_sha256':{who:hashlib.sha256((a.catalog_source/f'messages/fa/{who}.php').read_bytes()).hexdigest()for who in ['user','admin']},'access_source_sha256':hashlib.sha256(a.access_source.read_bytes()).hexdigest(),'no_external_calls':True,'lock_join_baseline_red':baseline_red,'lock_join_baseline_source':baseline}
    if a.report:a.report.write_text(json.dumps(report,ensure_ascii=False,indent=2)+'\n')
    print(json.dumps(report,ensure_ascii=False))
if __name__=='__main__':main()
