#!/usr/bin/env python3
"""Issue #176: actual onboarding/join/menu source with owned MariaDB and synthetic Telegram.

Only source snippets from the current bot/config are executed. No production
configuration, Telegram API, provider, external network, or customer data.
"""
from __future__ import annotations

import argparse
from concurrent.futures import ThreadPoolExecutor
import json
from pathlib import Path
import re
import shutil
import subprocess

import test_access_state as owned
import test_v6_native as native

ROOT = Path(__file__).resolve().parents[1]
DB = owned.NAMES[1]

DRIVER = r"""<?php
error_reporting(E_ALL);
set_error_handler(static function($level,$message) {
    if ($level & (E_WARNING | E_NOTICE | E_USER_WARNING))
        throw new ErrorException($message,0,$level);
    return true;
});
$_SERVER['SCRIPT_FILENAME']=__FILE__;
$_SERVER['REQUEST_METHOD']='POST';
$root=$argv[1]; $socket=$argv[2]; $dbName=$argv[3];
$spec=json_decode($argv[4],true,512,JSON_THROW_ON_ERROR);
$_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']=
    !empty($spec['bad_secret'])?'Invalid':'Synthetic_Onboarding_Secret_2026';
$telegramWebhookSecret='Synthetic_Onboarding_Secret_2026';
define('ACHBOT_VERIFIED_WEBHOOK_ENTRYPOINT',true);
$connection=new mysqli('localhost','root','',$dbName,0,$socket);
$connection->set_charset('utf8mb4');
$actor=(int)$spec['actor']; $from_id=$actor; $admin=9001; $message_id=81;
$chat_id=(int)($spec['chat_id']??$actor);
$chatType=(string)($spec['chat_type']??'private');
$first_name='Fixture';$username='fixture_user';
$data=(string)($spec['callback']??'');
$text=(string)($spec['text']??'/start');
$robotState='on';$inviteReward=(int)($spec['reward']??0);
$channelLock=!empty($spec['forced'])?'@fixture_channel':'';
$joinLink='https://t.me/fixture_channel';
$botState=['lockChannel'=>$channelLock,'supportID'=>'@fixture_support',
    'cooperationUrl'=>'@fixture_support','helpChannel'=>'',
    'agencyState'=>'off','sellState'=>'on','testAccount'=>'off','sharedExistence'=>'off',
    'individualExistence'=>'off','searchState'=>'off'];
$mainValues=['join_channel_message'=>'JOIN_PROMPT','not_joine_yet'=>'NOT_JOINED',
  'bot_is_updating'=>'UPDATING','start_message'=>'HOME',
  'new_member_joined'=>'NEW FULLNAME USERNAME USERID','user_welcome_message'=>'WELCOME'];
$buttonValues=['join_channel'=>'Join channel','have_joined'=>'Joined','sharj'=>'Wallet',
  'buy_subscriptions'=>'Buy','my_info'=>'Info','my_subscriptions'=>'Subscriptions',
  'invite_friends'=>'Invite','application_links'=>'Apps','back_to_main'=>'Back',
  'request_agency'=>'Agency','test_account'=>'Trial','search_config'=>'Search'];
$events=[];$memberQueries=0;
$sender=(object)['id'=>(int)($spec['sender']??$actor),'first_name'=>'Fixture',
    'last_name'=>'Test','username'=>'fixture_user','is_bot'=>false];
$chat=(object)['id'=>$chat_id,'type'=>$chatType];
if($data!=='') {
  $update=(object)['callback_query'=>(object)['id'=>'callback-fixture',
    'from'=>$sender,'data'=>$data,'message'=>(object)[
       'chat'=>$chat,'message_id'=>81,'text'=>'JOIN_PROMPT']]];
  $text='JOIN_PROMPT';
} else {
  $update=(object)['message'=>(object)['from'=>$sender,'chat'=>$chat,
    'message_id'=>81,'text'=>$text]];
}
function bot($method,$args=[]) {
  if($method!=='getChatMember') throw new RuntimeException('unanticipated_telegram');
  $GLOBALS['memberQueries']++;
  $spec=$GLOBALS['spec'];
  if(($spec['membership']??'error')==='error') return (object)['ok'=>false];
  return (object)['ok'=>true,'result'=>(object)[
    'status'=>$spec['membership'],'is_member'=>(bool)($spec['is_member']??false)]];
}
function sendMessage($text,$key=null,$parse='HTML',$ci=null) {
  $GLOBALS['events'][]=['kind'=>'send','text'=>$text,'markup'=>$key,'chat'=>$ci];
  return (object)['ok'=>true];
}
function achbotNotifyAdmins($method,$args) {$GLOBALS['events'][]=['kind'=>'admin','method'=>$method];}
function delMessage() {$GLOBALS['events'][]=['kind'=>'delete'];}
function alert($text) {$GLOBALS['events'][]=['kind'=>'alert','text'=>$text];}
function achbotMessage($key,$params=[]) {return $key==='bot.bootstrap.unavailable'?'UNAVAILABLE':'CATALOG_'.$key;}
require __DIR__.'/auth.php';
require __DIR__.'/set-user.php';
require __DIR__.'/get-main-keys.php';
require __DIR__.'/src/Presentation/Text.php';
require __DIR__.'/src/Telegram/UserProfile.php';
$userInfo=null;
$stmt=$connection->prepare('SELECT * FROM users WHERE userid=? LIMIT 2');
$stmt->bind_param('i',$actor);$stmt->execute();
$initRows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
if(count($initRows)===1)$userInfo=$initRows[0];
else $userInfo=[];
register_shutdown_function(static function() {
  $db=$GLOBALS['connection'];$actor=$GLOBALS['actor'];$events=$GLOBALS['events'];
  $stmt=$db->prepare('SELECT userid,step,wallet,refered_by,first_start FROM users WHERE userid=?');
  $stmt->bind_param('i',$actor);$stmt->execute();
  $rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
  $stmt=$db->prepare('SELECT wallet FROM users WHERE userid=1001 LIMIT 2');
  $stmt->execute();$walletRows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
  echo json_encode(['rows'=>$rows,'inviter_wallet'=>$walletRows[0]['wallet']??null,
      'events'=>$events,'member_queries'=>$GLOBALS['memberQueries'],
      'registered'=>$GLOBALS['registration']['created']??null,
      'text'=>$GLOBALS['text']],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
});
if(($spec['mode']??'flow')==='helper') {
  $registration=achbotRegisterStartUser($connection,$update,$actor,
      (int)($spec['inviter']??0),(int)($spec['reward']??0),$dbName);
} else {
  eval(file_get_contents(__DIR__.'/membership-source.php'));
  eval(file_get_contents(__DIR__.'/onboarding-source.php'));
  eval(file_get_contents(__DIR__.'/menu-source.php'));
}
"""

def extract_function(path: Path, function: str) -> str:
    script = "require $argv[1];echo achbotExtractFunction($argv[2],$argv[3]);"
    return subprocess.check_output([
        "php","-r",script,str(ROOT/"tools/source_scan.php"),str(path),function
    ],cwd=ROOT,text=True,timeout=15)

def current_section(path: str, start: str, end: str) -> str:
    raw=(ROOT/path).read_text(encoding="utf-8")
    a=raw.index(start);b=raw.index(end,a)
    return raw[a:b]

def write_runtime(w: Path) -> None:
    (w/"onboarding-driver.php").write_text(DRIVER,encoding="utf-8")
    files={
        "auth.php": '<?php\n'+extract_function(ROOT/"bot.php","achbotWebhookIngressAuthenticated"),
        "set-user.php": '<?php\n'+extract_function(ROOT/"config.php","setUser"),
        "get-main-keys.php": '<?php\n'+extract_function(ROOT/"config.php","getMainKeys"),
        "membership-source.php":current_section("config.php",
            "$joniedState = null;", "// Channel exemption is dispatched"),
        "onboarding-source.php":current_section("bot.php",
            '// "عضو شدم" is a navigation continuation',
            "if($userInfo['phone'] == null"),
        "menu-source.php":current_section("bot.php",
            "$paymentHomeMatch=[];",
            "if(preg_match('/^sendMessageToUser"),
    }
    for file,body in files.items():
        (w/file).write_text(body,encoding="utf-8")
    for f in ("src/Telegram/UserProfile.php","src/Presentation/Text.php"):
        to=w/f;to.parent.mkdir(parents=True,exist_ok=True)
        shutil.copyfile(ROOT/f,to)

def call(w: Path, env: dict, spec: dict) -> dict:
    cmd=["php","-d","allow_url_fopen=0",
         "-d","disable_functions=curl_exec,fsockopen,pfsockopen,stream_socket_client,stream_socket_server",
         "-d","display_errors=0","-d","log_errors=1",
         str(w/"onboarding-driver.php"),str(ROOT),str(w/"mysql.sock"),
         DB,json.dumps(spec)]
    p=subprocess.run(cmd,cwd=w,env=env,capture_output=True,text=True,timeout=20)
    if p.returncode!=0 or p.stderr or not p.stdout:
        raise AssertionError("onboarding PHP: exit="+str(p.returncode)+" stderr="+p.stderr[-1400:])
    try:return json.loads(p.stdout)
    except json.JSONDecodeError as e:raise AssertionError(p.stdout[-1200:]) from e

def catalog_labels() -> tuple[int,int]:
    # PHP 8.5 loads the actual published text catalog; do not infer composition
    # from the raw PHP string syntax. Headings repeat only when semantically duplicated.
    code=r"""
    $files=['messages/fa/user.php','messages/fa/admin.php'];
    $duplicates=[];$seen=0;
    foreach($files as $f) {
      $catalog=require $argv[1].'/'.$f;
      $walk=function($tree,$path)use(&$walk,&$duplicates,&$seen) {
        if(is_array($tree)&&isset($tree['text'])&&is_string($tree['text']))$tree=$tree['text'];
        if(is_array($tree)) {foreach($tree as $key=>$v)$walk($v,$path.'.'.$key);return;}
        if(!is_string($tree))return;
        $seen++;
        preg_match_all('/(?m)^[^\\S\\r\\n]*(?:⁉️\\s*آیدی پشتیبانی:|💬\\s*پشتیبانی:)/u',$tree,$matches);
        if(count($matches[0])>1 ||
           ((str_contains($tree,'SUPPORTID')||str_contains($tree,'{supportID}')) &&
            substr_count($tree,'پشتیبانی')>1))$duplicates[]=$path;
      };
      $walk($catalog,$f);
    }
    echo json_encode(['duplicates'=>$duplicates,'strings'=>$seen],JSON_THROW_ON_ERROR);
    """
    p=subprocess.run(["php","-r",code,str(ROOT)],cwd=ROOT,capture_output=True,text=True,timeout=12)
    if p.returncode or p.stderr:raise AssertionError('catalog scan failed:'+p.stderr[-1000:])
    result=json.loads(p.stdout)
    if result["duplicates"]:raise AssertionError("repeated support headings: "+str(result["duplicates"]))
    join=(ROOT/"messages/fa/user.php").read_text(encoding="utf-8")
    if "'⁉️ آیدی پشتیبانی:'" in join:raise AssertionError("obsolete duplicate join label")
    return 0,result["strings"]

def main() -> None:
    ap=argparse.ArgumentParser()
    ap.add_argument("--run-disposable",required=True,action="store_true")
    ap.add_argument("--report",type=Path)
    a=ap.parse_args()
    checks=[]
    def yes(ok: bool,label: str) -> None:
        if not ok:raise AssertionError(label)
        checks.append(label)
    auth=(ROOT/"bot.php").read_text(encoding="utf-8")
    yes(auth.index("if (!achbotWebhookIngressAuthenticated())") <
        auth.index("require_once 'config.php';"),
        "real_webhook_ingress_precedes_config_and_reg")
    yes(auth.index("if ($joinRequired)") < auth.index("achbotRegisterStartUser(") <
        auth.index("if($userInfo['phone'] == null") <
        auth.index("$paymentHomeMatch=[];"),
        "real_join_then_registration_then_phone_then_menu")

    duplicates,scanned=catalog_labels()
    yes(duplicates==0 and scanned>100,"all_catalog_support_heading_duplicates_absent")

    if not re.search(r"\$userInfo\s*=\s*\$uinfo->fetch_assoc\(\)\s*\?:\s*\[\];",
                     (ROOT/"config.php").read_text()):
        raise AssertionError("missing actor safe initial state")
    with owned.owned_database() as (w,env):
        write_runtime(w)
        def run(**kwargs):return call(w,env,kwargs)
        r=run(actor=2401)
        yes(len(r['rows'])==1 and
            [e['text'] for e in r['events'] if e['kind']=='send']==['HOME','WELCOME'],
            "plain_first_start_registers_and_displays_menu")
        home=next(e for e in r['events'] if e['kind']=='send' and e['text']=='HOME')
        buttons=[b for row in json.loads(home['markup'])['inline_keyboard'] for b in row]
        yes(sum(b.get('url')=='https://t.me/fixture_support' for b in buttons)==1,
            "home_single_support_destination_no_duplicate_button")
        r=run(actor=2401)
        yes(len(r['rows'])==1 and r['registered'] is False and
            [e['text'] for e in r['events'] if e['kind']=='send']==['HOME'],
            "repeat_start_reuses_identity_no_repeat_welcome")
        r=run(actor=2402,forced=True,membership='left')
        yes(r['rows']==[] and
            [e['text'] for e in r['events'] if e['kind']=='send']==['JOIN_PROMPT'] and
            'haveJoined/start' in r['events'][0]['markup'],
            "not_joined_receives_only_join_prompt")
        r=run(actor=2403,forced=True,membership='error')
        yes(r['rows']==[] and any(e['text']=='JOIN_PROMPT' for e in r['events']),
            "unverified_getChatMember_does_not_bypass_join")
        r=run(actor=2404,forced=True,membership='member')
        yes(len(r['rows'])==1 and any(e['text']=='HOME' for e in r['events']) and
            all(e['text']!='JOIN_PROMPT' for e in r['events'] if e['kind']=='send'),
            "already_member_goes_direct_to_menu")
        r=run(actor=2405,forced=True,membership='left',callback='haveJoined/start')
        yes(r['rows']==[] and [e['kind'] for e in r['events']]==['alert'],
            "premature_join_button_denied_no_registration")
        r=run(actor=2405,forced=True,membership='member',callback='haveJoined/start')
        yes(len(r['rows'])==1 and
            [e['kind'] for e in r['events']]==['delete','admin','send','send'] and
            any(e['text']=='HOME' for e in r['events'] if e['kind']=='send'),
            "joined_button_registers_then_shows_menu")
        r=run(actor=2406,forced=True,membership='restricted',is_member=True)
        yes(len(r['rows'])==1 and any(e['text']=='HOME' for e in r['events']),
            "restricted_actual_member_accepted")
        r=run(actor=2407,forced=True,membership='restricted',is_member=False)
        yes(r['rows']==[] and any(e['text']=='JOIN_PROMPT' for e in r['events']),
            "restricted_nonmember_denied")
        r=run(actor=2408,forced=True,membership='left',text='/start 1001',reward=150)
        yes(r['rows']==[] and int(r['inviter_wallet'])==50000,
            "referral_not_awarded_before_mandatory_join")
        r=run(actor=2408,forced=True,membership='member',
              callback='haveJoined/start 1001',reward=150)
        yes(len(r['rows'])==1 and int(r['inviter_wallet'])==50150 and
            int(r['rows'][0]['refered_by'])==1001 and
            any(e['text']=='HOME' for e in r['events']),
            "joined_referral_awarded_once_after_registration")
        r=run(actor=2408,forced=True,membership='member',
              callback='haveJoined/start 1001',reward=150)
        yes(len(r['rows'])==1 and int(r['inviter_wallet'])==50150 and
            r['registered'] is False,
            "replayed_join_referral_cannot_credit_again")
        r=run(actor=1001)
        yes(int(r['rows'][0]['wallet'])==50150 and r['registered'] is False,
            "existing_actor_preserves_wallet_and_row")
        r=run(actor=2410,mode='helper',bad_secret=True)
        yes(r['rows']==[] and r['registered'] is None,
            "invalid_webhook_secret_cannot_register")
        r=run(actor=2411,mode='helper',chat_type='group')
        yes(r['rows']==[] and r['registered'] is None,
            "group_update_cannot_register_private_actor")
        r=run(actor=2412,mode='helper',sender=9999)
        yes(r['rows']==[] and r['registered'] is None,
            "different_sender_cannot_claim_actor")
        native.sql(w,env,"INSERT INTO users(userid,name,username,refcode,wallet,date) VALUES "
                       "(2413,'first','first','0',0,'1700000000'),"
                       "(2413,'second','second','0',0,'1700000000')",DB)
        r=run(actor=2413,mode='helper')
        yes(len(r['rows'])==2 and r['registered'] is None,
            "ambiguous_legacy_identity_never_overwritten")
        native.sql(w,env,"INSERT INTO users(userid,name,username,refcode,wallet,date) VALUES "
                       "(2414,'prior','prior','0',0,'1700000000')",DB)
        native.sql(w,env,"DELETE FROM users WHERE userid=2414",DB)
        r=run(actor=2414)
        yes(len(r['rows'])==1 and any(e['text']=='HOME' for e in r['events']),
            "controlled_deleted_account_rejoin_recovers_menu")
        with ThreadPoolExecutor(max_workers=2) as pool:
            jobs=[pool.submit(run,actor=2415,text='/start 1001',reward=150) for _ in range(2)]
            results=[x.result(timeout=25) for x in jobs]
        yes(all(len(x['rows'])==1 for x in results) and
            [x['registered'] for x in results].count(True)==1 and
            all(int(x['inviter_wallet'])==50300 for x in results),
            "parallel_first_start_single_row_single_referral_reward")
        yes(int(native.sql(w,env,"SELECT COUNT(*) FROM users WHERE userid=2415",DB).strip())==1,
            "owned_database_enforces_parallel_one_row_result")
        r=run(actor=2416,forced=False,callback='haveJoined')
        yes(len(r['rows'])==1 and any(e['text']=='HOME' for e in r['events']),
            "old_join_button_after_lock_disabled_shows_menu")
        r=run(actor=2417,forced=True,membership='administrator')
        yes(len(r['rows'])==1 and any(e['text']=='HOME' for e in r['events']),
            "channel_administrator_status_accepted")
        r=run(actor=2418,forced=True,membership='creator')
        yes(len(r['rows'])==1 and any(e['text']=='HOME' for e in r['events']),
            "channel_creator_status_accepted")
        r=run(actor=2419,mode='helper',text='/start',chat_id=1)
        yes(r['rows']==[] and r['registered'] is None,
            "mismatched_private_chat_cannot_register")
        r=run(actor=2420,mode='helper',text='/invalid')
        yes(r['rows']==[] and r['registered'] is None,
            "non_start_message_cannot_create_identity")
    proof={'complete':True,'cases':len(checks),'checks':checks,
           'source_real':True,'owned_socket_mariadb':True,'php85':True,
           'forced_membership_verified':True,'concurrent_registration':True,
           'referral_awarded_once':True,'duplicate_support_label_count':duplicates,
           'catalog_strings_inspected':scanned,'no_external_calls':True}
    print(json.dumps(proof,ensure_ascii=False,sort_keys=True))
    if a.report:a.report.write_text(json.dumps(proof,ensure_ascii=False,sort_keys=True)+"\n")

if __name__=="__main__":
    main()
