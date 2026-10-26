#!/usr/bin/env python3
"""Post-V9 direct gift: actual bot.php route, PHP service seam, original MariaDB.

All Telegram/Marzban traffic is synthetic, and all SQL runs on an owned,
socket-only disposable instance. Original V0-V9 evidence stays immutable.
"""
from __future__ import annotations
import argparse
import concurrent.futures
import json
import os
import subprocess
import time
from pathlib import Path

import test_access_state as owned
import test_v6_native as native

ROOT = Path(__file__).resolve().parents[1]
DB = native.DBS[1]
OLD_EXPIRY = 1800000100
NEW_EXPIRY = 1802000100
SECRET = "fixture-direct-gift-token-NotReal_2026"

PHP = r'''<?php
if (PHP_SAPI !== 'cli') exit(3);
error_reporting(E_ALL & ~E_DEPRECATED);
set_error_handler(static function($n,$m) {throw new ErrorException($m,0,$n);});
$spec=json_decode($argv[1],true,512,JSON_THROW_ON_ERROR);
$GLOBALS['spec']=$spec;
class DirectGiftMysqli extends mysqli {
    private function fixtureGate(string $announce,string $release): void {
        file_put_contents($GLOBALS['spec'][$announce],'ready');
        $deadline=microtime(true)+25;
        while (!file_exists($GLOBALS['spec'][$release])) {
            if (microtime(true)>$deadline)
                throw new RuntimeException('synthetic_gate_timeout_'.$announce);
            usleep(10000);
        }
    }
    public function begin_transaction(int $flags=0,?string $name=null): bool {
        $begun=parent::begin_transaction($flags,$name);
        if ($begun && ($GLOBALS['spec']['mode']??'')==='probe_second_started')
            file_put_contents($GLOBALS['spec']['second_begin'],'ready');
        return $begun;
    }
    public function commit(int $flags=0,?string $name=null): bool {
        $pausing=($GLOBALS['spec']['mode']??'')==='pause_claim_commit' &&
            !($GLOBALS['paused_claim']??false);
        if ($pausing) {
            $rows=$this->query("SELECT id FROM setting WHERE type LIKE 'ACHBOT_DIRECT_GIFT_SOURCE_%' LIMIT 1");
            $pausing=$rows && $rows->num_rows===1;
            if ($pausing) {
                $GLOBALS['paused_claim']=true;
                $this->fixtureGate('pause_ready','release_commit');
            }
        }
        $done=parent::commit($flags,$name);
        if ($done && $pausing)
            $this->fixtureGate('post_commit_ready','release_effect');
        if ($done && in_array(($GLOBALS['spec']['mode']??''),
            ['moderation_commit_unknown','manual_commit_unknown','exempt_commit_unknown'],true))
            throw new RuntimeException('synthetic_lost_moderation_or_manual_ack');
        if ($done && ($GLOBALS['spec']['mode']??'')==='cancel_commit_unknown')
            throw new RuntimeException('synthetic_cancel_commit_ack_lost');
        if ($done && ($GLOBALS['spec']['mode']??'')==='commit_unknown' &&
            !($GLOBALS['did_unknown']??false)) {
            $rows=$this->query("SELECT id FROM setting WHERE type LIKE 'ACHBOT_DIRECT_GIFT_SOURCE_%' LIMIT 1");
            if ($rows && $rows->num_rows===1) {
                $GLOBALS['did_unknown']=true;
                throw new RuntimeException('synthetic_claim_commit_ack_lost');
            }
        }
        return $done;
    }
}
$connection=new DirectGiftMysqli('localhost','root','',
    getenv('ACHBOT_DIRECT_DB'),0,getenv('ACHBOT_DIRECT_SOCKET'));
$connection->set_charset('utf8mb4');
$_SERVER['REQUEST_METHOD']=($spec['bad_method']??false)?'GET':'POST';
$username='fixture';$first_name='Fixture';
$admin=9001;
$kind=(string)($spec['kind']??'message');
$actor=(int)($spec['actor']??9001);
$wireActor=(int)($spec['wire_actor']??$actor);
$wireChat=(int)($spec['wire_chat']??$wireActor);
$messageId=(int)($spec['message_id']??52);
$updateId=(int)($spec['update_id']??502);
$input=(string)($spec['text']??'');
$wireType=(string)($spec['chat_type']??'private');
$sender=(object)['id'=>$wireActor,'is_bot'=>($spec['is_bot']??false),
                 'first_name'=>'Synthetic','username'=>'fixture'];
$chat=(object)['id'=>$wireChat,'type'=>$wireType];
$msg=(object)['from'=>$sender,'chat'=>$chat,'message_id'=>$messageId,
             'text'=>$kind==='callback'?(string)($spec['callback_bot_text']??'Synthetic old bot text'):$input];
$update=(object)['update_id'=>$updateId];
if ($kind==='callback') {
    $msg->reply_markup=(object)['inline_keyboard'=>[]];
    $update->callback_query=(object)[
        'id'=>'fixture','data'=>$input,'from'=>$sender,'message'=>$msg
    ];
} elseif ($kind==='message') $update->message=$msg;
elseif ($kind==='mixed') {
    $msg->reply_markup=(object)['inline_keyboard'=>[]];
    $update->message=$msg;
    $update->callback_query=(object)['id'=>'fixture','data'=>$input,
                                     'from'=>$sender,'message'=>$msg];
} else throw new RuntimeException('fixture_invalid_kind');
$from_id=$actor;$chat_id=$wireChat;$message_id=$messageId;
$data=null;$text=null;
require __DIR__.'/src/Telegram/UpdateBindings.php';
if (isset($spec['bound_actor'])) $from_id=(int)$spec['bound_actor'];
if (isset($spec['bound_text'])) $text=$spec['bound_text'];
if (!($spec['missing_header']??false)) {
    $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']=
        ($spec['bad_header']??false)?'bad-secret':getenv('ACHBOT_TELEGRAM_WEBHOOK_SECRET');
}
$stmt=$connection->prepare('SELECT id,userid,isAdmin,is_agent,step,temp FROM users WHERE userid=?');
$stmt->bind_param('i',$from_id);$stmt->execute();
$userInfo=$stmt->get_result()->fetch_assoc()?:['step'=>'none','isAdmin'=>0];
$stmt->close();
if (isset($spec['stale_step']))$userInfo['step']=$spec['stale_step'];
if (isset($spec['cached_admin']))$userInfo['isAdmin']=$spec['cached_admin'];
if (in_array(($spec['mode']??''),['pause_before_route','stale_legacy_set_user'],true)) {
    // A has read the same cached userInfo that config.php populates.
    file_put_contents($spec['cached_ready'],'ready');
    $deadline=microtime(true)+25;
    while (!file_exists($spec['release_route'])) {
        if (microtime(true)>$deadline)
            throw new RuntimeException('synthetic_cached_route_gate_timeout');
        usleep(10000);
    }
}
$buttonValues=['cancel'=>'CANCEL','back_to_main'=>'BACK'];$mainValues=['reached_main_menu'=>'MAIN','waiting_message'=>'WAIT','start_message'=>'WELCOME','banned'=>'BANNED'];
$botState=['sellState'=>'on'];
$removeKeyboard='REMOVE';$cancelKey='CANCEL';
$GLOBALS['events']=[];
$GLOBALS['fixture_printed']=false;
register_shutdown_function(static function() {
    if (!$GLOBALS['fixture_printed'])
        echo json_encode(['events'=>$GLOBALS['events']],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
});
function bot($method,$args=[]) {
    if ($method==='editMessageReplyMarkup') $GLOBALS['events'][]='MOD_NOTICE';
    return (object)['ok'=>true];
}
function getUserInfoKeys($id) { return 'FIXTURE_MODERATION_KEYS'; }
function alert($msg=null,$show=false) { $GLOBALS['events'][]='ALERT:'.(string)$msg; }
function editKeys(...$args) { $GLOBALS['events'][]='EDIT_KEYS'; }
function getAdminKeys() { return 'ADMIN_KEYS'; }
function editText($mid,$body,$keys=null) {
    $GLOBALS['events'][]='EDIT_TEXT:'.(string)$body;
    return (object)['ok'=>true];
}
function sendMessage($body=null,$key=null) {
    $GLOBALS['events'][]=(string)$body;
    if (($GLOBALS['spec']['mode']??'')==='notice_fail' &&
        str_contains((string)$body,'✅'))
        throw new RuntimeException('synthetic_notification_lost');
    return (object)['ok'=>true];
}
function getMainKeys(){return 'MAIN_KEYS';}
function editMarzbanConfig($sid,$change,?array $target=null): object {
    $mode=(string)($GLOBALS['spec']['mode']??'verified');
    file_put_contents(getenv('ACHBOT_DIRECT_LOG'),
        json_encode(['sid'=>$sid,'change'=>$change,
            'target'=>$target['panel_url']??null],JSON_THROW_ON_ERROR)."\n",
        FILE_APPEND|LOCK_EX);
    if ($mode==='slow_provider') usleep(350000);
    if ($mode==='provider_throw') throw new RuntimeException('synthetic_lost_provider_ack');
    if ($mode==='sql_drift') {
        $GLOBALS['connection']->query('UPDATE orders_list SET expire_date=1800000101 WHERE id=91');
    }
    if ($mode==='config_drift') {
        $GLOBALS['connection']->query("UPDATE server_config SET panel_url='http://127.0.0.1:10' WHERE id=2");
    }
    if ($mode==='provider_false')
        return (object)['success'=>false,'uncertain'=>false,'msg'=>'synthetic_refused'];
    if ($mode==='provider_unknown')
        return (object)['success'=>false,'uncertain'=>true,'msg'=>'synthetic_unknown'];
    if ($mode==='provider_unverified')
        return (object)['success'=>true,'verified'=>false,'uncertain'=>true];
    if ($mode==='provider_ambiguous_success')
        return (object)['success'=>true,'verified'=>true,'uncertain'=>true,
            'applied_expire'=>1802000100,'applied_bytes'=>4294967296];
    if ($mode==='provider_bad_expiry')
        return (object)['success'=>true,'verified'=>true,
            'applied_expire'=>2147483648,'applied_bytes'=>4294967296];
    if ($mode==='provider_bad_bytes')
        return (object)['success'=>true,'verified'=>true,
            'applied_expire'=>1802000100,'applied_bytes'=>-1];
    $expire=isset($change['plus_day']) ? 1802000100 : 1800000100;
    return (object)['success'=>true,'verified'=>true,'uncertain'=>false,
        'applied_expire'=>$expire,'applied_bytes'=>4294967296];
}
require __DIR__.'/src/Purchase/Core.php';
require __DIR__.'/ingress-source.php';
if (!achbotWebhookIngressAuthenticated()) {
    http_response_code(403);
} elseif (($spec['route']??'gift')==='moderation') {
    require __DIR__.'/moderation-route.php';
    handleToggleCallbacks($data,$chat_id,$message_id);
} elseif (($spec['route']??'gift')==='exemption') {
    require __DIR__.'/exemption-route.php';
    achbotHandleChannelExemption($data,$text,$userInfo);
} elseif (($spec['route']??'gift')==='main_menu') {
    require __DIR__.'/legacy-set-user.php';
    require __DIR__.'/main-menu.php';
} elseif (($spec['route']??'gift')==='legacy_unban') {
    require __DIR__.'/legacy-set-user.php';
    require __DIR__.'/legacy-unban.php';
} elseif (($spec['route']??'gift')==='manual') {
    require __DIR__.'/src/Subscription/manual-route.php';
} elseif (($spec['route']??'gift')==='legacy_ban') {
    require __DIR__.'/legacy-set-user.php';
    require __DIR__.'/legacy-ban.php';
} elseif (($spec['route']??'gift')==='legacy_receipt_ban') {
    require __DIR__.'/legacy-receipt-ban.php';
} elseif (($spec['route']??'gift')==='broadcast') {
    $user=['userid'=>(int)($spec['target']??9001)];
    require __DIR__.'/broadcast-guard.php';
} else {
    require __DIR__.'/legacy-set-user.php';
    if (($spec['mode']??'')==='stale_legacy_set_user')
        setUser('obsoleteLegacyStep');
    require __DIR__.'/gift-route.php';
}
$GLOBALS['fixture_printed']=true;
echo json_encode(['events'=>$GLOBALS['events']],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
'''

def ensure(ok: bool, why: str) -> None:
    if not ok:
        raise RuntimeError(why)

def q(work: Path, env: dict, query: str) -> str:
    return native.sql(work, env, query, DB).strip()

def setup(work: Path, env: dict) -> Path:
    native.seed(work, env, 'http://127.0.0.1:9')
    stage=work/'owned-direct-gift'
    for rel in [
        'src/Subscription/DirectGift.php',
        'src/Subscription/GiftBatch.php',
        'src/Purchase/Core.php',
        'src/Telegram/UpdateBindings.php'
    ]:
        dst=stage/rel
        dst.parent.mkdir(parents=True,exist_ok=True)
        dst.write_bytes((ROOT/rel).read_bytes())
    bot=(ROOT/'bot.php').read_text()
    begin=bot.index('// ACHBOT_DIRECT_GIFT_ROUTE_BEGIN')
    end=bot.index('// ACHBOT_DIRECT_GIFT_ROUTE_END',begin)+len('// ACHBOT_DIRECT_GIFT_ROUTE_END')
    route=bot[begin:end]
    ensure('achbotDirectGiftClaim(' in route and
           'achbotApplyServiceEdit(' in route and
           'editMarzbanConfig(' not in route and
           'achbotDirectGiftComplete(' in route,
           'Current bot.php no longer uses strictly guarded direct-gift source')
    # Execute the *actual* final global CANCEL fallback after the protected
    # route; otherwise an obsolete config.php userInfo snapshot can bypass it.
    legacy_start=bot.rfind("if ($text == $buttonValues['cancel']) {")
    ensure(legacy_start>end and
           bot[legacy_start:].count('DELETE FROM `server_plans`')==1,
           'Actual final legacy CANCEL fallback unavailable or unexpected')
    legacy_end=bot.index('\n?>',legacy_start)
    fallback=bot[legacy_start:legacy_end]
    ensure('DELETE FROM `server_plans`' in fallback,
           'Legacy CANCEL cleanup contract unexpectedly changed')
    config=(ROOT/'config.php').read_text()
    set_start=config.index("function setUser($value = 'none', $field = 'step'){")
    set_end=config.index('function generateRandomString(',set_start)
    (stage/'legacy-set-user.php').write_text('<?php\n'+config[set_start:set_end])
    guard_start=bot.index('// ACHBOT_WEBHOOK_INGRESS_BEGIN')
    guard_end=bot.index('if (!achbotWebhookIngressAuthenticated())',guard_start)
    ensure(guard_start<guard_end<bot.index("require_once 'config.php';") and
           bot.index('check();')<bot.index('if (handleToggleCallbacks(') and
           'handleToggleCallbacks($data, $chat_id, $message_id);' not in config,
           'Moderation can run before HTTP provenance/IP checks')
    (stage/'ingress-source.php').write_text('<?php\n'+bot[guard_start:guard_end])
    moderation_start=config.index('function handleToggleCallbacks(')
    moderation_end=config.index('\n//---',moderation_start)
    (stage/'moderation-route.php').write_text(
        '<?php\n'+config[moderation_start:moderation_end])
    exemption_start=config.index('function achbotHandleChannelExemption(')
    ensure(exemption_start<moderation_start and
           'if ($data == "addExemptUser")' not in config[config.index('$joniedState='):config.index('// تابع مشترک ساخت درخواست پرداخت')] and
           'if ($userInfo[\'step\'] == "awaitingExemptUserID")' not in config[config.index('$joniedState='):config.index('// تابع مشترک ساخت درخواست پرداخت')],
           'Exemption still mutates during config.php bootstrap')
    ensure(bot.index('check();')<bot.index('if (achbotHandleChannelExemption('),
           'Exemption route precedes IP check')
    (stage/'exemption-route.php').write_text(
        '<?php\n'+config[exemption_start:moderation_start])
    menu_start=bot.index("if (preg_match('/^\\/([Ss]tart)/'")
    menu_end=bot.index("if(preg_match('/^sendMessageToUser",menu_start)
    (stage/'main-menu.php').write_text('<?php\n'+bot[menu_start:menu_end])
    manual=(ROOT/'src/Subscription/ManualAccounts.php').read_text()
    manual_start=manual.index('function achbotManualAccountConsumeSelection(')
    manual_end=manual.index('function detectServerIdFromRemark(',manual_start)
    (stage/'src/Subscription/manual-route.php').write_text(
        '<?php\n'+manual[manual_start:manual_end])
    legacy_ban=(ROOT/'src/Admin/Bans.php').read_text()
    ban_start=legacy_ban.index('if($userInfo[\'step\'] == "banUser"')
    (stage/'legacy-ban.php').write_text('<?php\n'+legacy_ban[ban_start:])
    unban=(ROOT/'src/Admin/Unban.php').read_text()
    unban_start=unban.index('if($userInfo[\'step\'] == "unbanUser"')
    (stage/'legacy-unban.php').write_text('<?php\n'+unban[unban_start:])
    receipt=(ROOT/'src/Finance/Routes/ManualReceipts.php').read_text()
    receipt_start=receipt.index("if (preg_match('/^banUserDirect_")
    receipt_end=receipt.index('// END Decline Cart To Cart by ACh',receipt_start)
    (stage/'legacy-receipt-ban.php').write_text(
        '<?php\n'+receipt[receipt_start:receipt_end])
    broadcast=(ROOT/'src/Jobs/BroadcastSend.php').read_text()
    import re
    blocked=re.search(r'\$u = \$connection->prepare\("UPDATE `users`[^\"]+"\);\s*if \(\$u\) \{.*?\$u->close\(\);\s*\}',broadcast,re.S)
    ensure(blocked is not None and "NOT LIKE 'achbotDirectGift%'" in blocked.group(),
           'Automated broadcast-source step guard absent')
    (stage/'broadcast-guard.php').write_text('<?php\n'+blocked.group())
    (stage/'gift-route.php').write_text('<?php\n'+route+'\n'+fallback+'\n')
    (stage/'driver.php').write_text(PHP)
    return stage

def reset(work: Path, env: dict) -> None:
    q(work,env,"DELETE FROM setting WHERE type LIKE 'ACHBOT_DIRECT_GIFT_%'")
    q(work,env,"DELETE FROM setting WHERE type LIKE 'ACHBOT_ADMIN_MOD_SOURCE_%'")
    q(work,env,"DELETE FROM setting WHERE type LIKE 'ACHBOT_EXEMPT_SOURCE_%'")
    q(work,env,"DELETE FROM setting WHERE type='EXEMPTED_USERS'")
    q(work,env,"DELETE FROM orders_list WHERE id=92")
    q(work,env,"UPDATE users SET is_agent=0,temp=NULL WHERE userid IN (9001,9002,1001)")
    q(work,env,"DELETE FROM server_plans WHERE id=987")
    q(work,env,"UPDATE users SET step='none',isAdmin=CASE WHEN userid=9002 THEN 1 ELSE 0 END "
      "WHERE userid IN (9001,9002,1001)")
    q(work,env,"UPDATE orders_list SET server_id=2,inbound_id=0,remark='fixture-user',"
      "status=1,expire_date=1800000100 WHERE id=91")
    q(work,env,"INSERT INTO server_info (id,title,ucount,remark,flag,active,state) "
      "VALUES(2,'Fixture',5,'fixture','','1',1) "
      "ON DUPLICATE KEY UPDATE title='Fixture',active=1,state=1")
    q(work,env,"UPDATE server_config SET panel_url='http://127.0.0.1:9',"
      "username='fixture-admin',password='fixture-password',type='marzban' WHERE id=2")

def call(work: Path, env: dict, stage: Path, **spec) -> dict:
    log=stage/'effects.jsonl'
    proc=subprocess.run(
        ['php','-n','-d','extension=mysqlnd','-d','extension=mysqli',
         '-d','display_errors=1',
         '-d','disable_functions=curl_exec,curl_init,exec,shell_exec,system,passthru,popen,proc_open,mail',
         '-d','allow_url_fopen=0',
         '-d','open_basedir='+str(stage)+':'+str(work),
         str(stage/'driver.php'),json.dumps(spec)],
        cwd=stage,env={**env,'ACHBOT_DIRECT_DB':DB,
            'ACHBOT_DIRECT_SOCKET':str(work/'mysql.sock'),
            'ACHBOT_DIRECT_LOG':str(log),
            'ACHBOT_TELEGRAM_WEBHOOK_SECRET':SECRET},
        capture_output=True,text=True,timeout=38)
    ensure(proc.returncode==0,
        f'Actual PHP source route exception: rc={proc.returncode} stdout={proc.stdout[-500:]} stderr={proc.stderr[-1200:]} mode={spec}')
    # The PHP route deliberately emits narrowly scoped internal diagnostic
    # strings on expected negative cases. It must not leak private values.
    ensure('fixture-password' not in proc.stderr and SECRET not in proc.stderr,
           'Source execution emitted private diagnostic material')
    try:
        return json.loads(proc.stdout)
    except json.JSONDecodeError as e:
        raise RuntimeError(f'Malformed actual PHP route response: {proc.stdout[-700:]} {proc.stderr[-700:]}') from e

def effects(stage: Path) -> list[dict]:
    p=stage/'effects.jsonl'
    return [json.loads(l) for l in p.read_text().splitlines()] if p.exists() else []

def rows(work: Path, env: dict, name: str) -> list[dict]:
    raw=q(work,env,"SELECT value FROM setting WHERE type LIKE 'ACHBOT_DIRECT_GIFT_"+name+"_%' ORDER BY id")
    return [json.loads(line) for line in raw.splitlines() if line]

def row_state(work: Path, env: dict) -> tuple:
    return (
        q(work,env,"SELECT expire_date FROM orders_list WHERE id=91"),
        q(work,env,"SELECT step FROM users WHERE userid=9001"),
        len(rows(work,env,'SOURCE')),len(rows(work,env,'ACCOUNT')),
    )

def begin(work: Path, env: dict, stage: Path, *, actor: int=9001,
          update_id: int=500) -> None:
    r=call(work,env,stage,kind='callback',actor=actor,
           update_id=update_id,message_id=50,text='giftToUser2')
    ensure(any('نام کاربری' in m for m in r['events']),
           'Fresh authenticated callback failed to arm direct gift')

def remark(work: Path, env: dict, stage: Path, *, actor: int=9001,
           update_id: int=501, value: str='fixture-user') -> dict:
    return call(work,env,stage,kind='message',actor=actor,
                update_id=update_id,message_id=51,text=value)

def day(work: Path, env: dict, stage: Path, *, actor: int=9001,
        update_id: int=502, value: str='2') -> dict:
    return call(work,env,stage,kind='message',actor=actor,
                update_id=update_id,message_id=52,text=value)

def volume(work: Path, env: dict, stage: Path, *, actor: int=9001,
           update_id: int=503, value: str='1024', mode: str='verified') -> dict:
    return call(work,env,stage,kind='message',actor=actor,
                update_id=update_id,message_id=53,text=value,mode=mode)

def prepare_final(work: Path, env: dict, stage: Path,days: str='2',
                  *, actor: int=9001) -> None:
    begin(work,env,stage,actor=actor)
    ensure(any('روز' in x for x in remark(work,env,stage,actor=actor)['events']),
           'Direct gift remark did not progress to days')
    ensure(any('حجم' in x for x in day(work,env,stage,actor=actor,value=days)['events']),
           'Direct gift day did not progress to volume')

def prepare_second_alias(work: Path, env: dict, stage: Path) -> None:
    # Two real wizard generations, distinct actors/orders/logical servers,
    # but the SAME physical endpoint and provider remark.
    q(work,env,"INSERT INTO server_info (id,title,ucount,remark,flag,active,state) "
      "VALUES(4,'Alias',5,'fixture','','1',1) "
      "ON DUPLICATE KEY UPDATE title='Alias',active=1,state=1")
    q(work,env,"UPDATE server_config SET type='marzban',"
      "panel_url='http://127.0.0.1:9' WHERE id=4")
    q(work,env,"INSERT INTO orders_list (id,userid,token,transid,fileid,server_id,"
      "inbound_id,remark,uuid,protocol,expire_date,link,amount,status,date) "
      "VALUES(92,1001,REPEAT('a',30),'fixture',7,4,0,'fixture-user',"
      "'alias-uuid','vless',1800000100,'[]',100,1,'fixture')")
    for kind,uid,mid,value in [
        ('callback',600,60,'giftToUser4'),
        ('message',601,61,'fixture-user'),
        ('message',602,62,'2')
    ]:
        result=call(work,env,stage,kind=kind,actor=9002,
                    update_id=uid,message_id=mid,text=value)
        ensure(result['events'] and
               not any('تأیید نشد' in e for e in result['events']),
               'Second delegated actor could not reach legitimate final gift step')
    ensure(q(work,env,'SELECT step FROM users WHERE userid=9002')
               .startswith('achbotDirectGiftVolume:'),
           'Second delegated actor did not reach final step')


def wait_for(predicate, reason: str, timeout: float=12) -> None:
    deadline=time.monotonic()+timeout
    while time.monotonic()<deadline:
        if predicate():return
        time.sleep(.045)
    raise RuntimeError(reason)


def owner_row_lock_wait_visible(work: Path, env: dict) -> bool:
    # Different MariaDB versions expose live waits through different views.
    # Require positive evidence of a *current* InnoDB row-lock wait, not
    # mere process overlap or a delay-based concurrency guess. This fixture
    # is a dedicated disposable server with only these two active workers.
    for sql in [
        'SELECT COUNT(*) FROM information_schema.INNODB_LOCK_WAITS',
        "SELECT COUNT(*) FROM information_schema.INNODB_TRX WHERE trx_state='LOCK WAIT'",
        "SHOW GLOBAL STATUS LIKE 'Innodb_row_lock_current_waits'",
    ]:
        try:
            raw=q(work,env,sql)
            n=raw.split()[-1] if raw else '0'
            if n.isdecimal() and int(n)>0:
                return True
        except (RuntimeError,ValueError):
            # An unavailable introspection view is not proof of no lock.
            continue
    return False


def race_claim_before_owner_release(work: Path, env: dict, stage: Path,
                                    isolation: str='REPEATABLE-READ') -> None:
    ensure(isolation in q(work,env,'SELECT @@tx_isolation').upper().replace('_','-'),
           'Disposable MariaDB isolation differs from required '+isolation)
    gates={k:stage/('owned-'+k) for k in
           ['pause_ready','release_commit','post_commit_ready',
            'release_effect','second_begin']}
    for path in gates.values():path.unlink(missing_ok=True)
    with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
        try:
            first=pool.submit(call,work,env,stage,kind='message',actor=9001,
                update_id=503,message_id=53,text='1024',mode='pause_claim_commit',
                **{k:str(p) for k,p in gates.items()})
            wait_for(lambda:gates['pause_ready'].exists(),
                     'First claimant did not pause with uncommitted claim')
            second=pool.submit(call,work,env,stage,kind='message',actor=9002,
                update_id=603,message_id=63,text='1024',mode='probe_second_started',
                second_begin=str(gates['second_begin']))
            # Establish B actually began its claim transaction after its
            # source/actor pre-resolution; then require real row-wait evidence.
            wait_for(lambda:gates['second_begin'].exists(),
                     'Second actor never began the serialized claim transaction')
            try:
                wait_for(lambda:owner_row_lock_wait_visible(work,env),
                    'Second real actor never entered InnoDB primary-owner row-lock wait')
            except RuntimeError as e:
                # Snapshot-safe diagnostic: only synthetic disposable MariaDB.
                version=q(work,env,'SELECT VERSION()')
                process=q(work,env,
                    "SELECT ID,COALESCE(STATE,''),LEFT(COALESCE(INFO,''),120) "
                    "FROM information_schema.PROCESSLIST "
                    "WHERE DB='"+DB+"' ORDER BY ID")
                raise RuntimeError(str(e)+'; MariaDB='+version+
                    '; B_done='+str(second.done())+
                    '; processlist='+process[-800:]) from e
            gates['release_commit'].write_text('go')
            wait_for(lambda:gates['post_commit_ready'].exists(),
                     'First claimant did not commit its durable journal')
            held=second.result(timeout=28)
            ensure(any('انتظار' in e for e in held['events']),
                   'Second competing actor did not receive a physical hold')
            ensure(len(rows(work,env,'SOURCE'))==1 and
                   len(rows(work,env,'ACCOUNT'))==1 and not effects(stage) and
                   q(work,env,'SELECT step FROM users WHERE userid=9002')=='none',
                   'REPEATABLE READ stale snapshot created conflicting source/marker')
            gates['release_effect'].write_text('go')
            completed=first.result(timeout=28)
            ensure(any('✅' in e for e in completed['events']) and
                   len(effects(stage))==1 and
                   rows(work,env,'SOURCE')[0]['state']=='completed' and
                   rows(work,env,'ACCOUNT')[0]['state']=='completed',
                   'Original serialized claimant could not complete after hold loser')
        finally:
            # Release only fixture-owned gates before waiting on worker exit.
            gates['release_commit'].write_text('go')
            gates['release_effect'].write_text('go')


def seed_inactive_legacy_plan(work: Path, env: dict) -> None:
    # This record belongs to the disposable original-schema DB only.
    q(work,env,"INSERT INTO server_plans "
      "(id,fileid,catid,server_id,acount,title,protocol,days,volume,"
      "type,price,descr,pic,active,step,date) "
      "VALUES (987,'987',1,2,1,'Fixture','vless',30,1,"
      "'normal',100,'fixture','',0,0,'fixture')")


def verify_global_cancel_stale_cache(work: Path, env: dict, stage: Path,
                                     passed: list[str]) -> None:
    # The actual bot.php global fallback and actual config.php setUser()
    # are staged after the real protected route. A is frozen only AFTER
    # config.php-equivalent userInfo was cached; B then commits a newer
    # nonce-protected gift step. This interleaving must be observable in
    # an unmodified/old candidate and safe in the corrected candidate.
    for target, legacy_step in [
        ('remark','none'),('day','giftServerDay2'),
        ('volume','giftServerVolume2_2'),
    ]:
        reset(work,env);stage.joinpath('effects.jsonl').unlink(missing_ok=True)
        if legacy_step!='none':
            q(work,env,"UPDATE users SET step='"+legacy_step+
              "' WHERE userid=9001")
        seed_inactive_legacy_plan(work,env)
        ready=stage/('owned-stale-cache-'+target)
        release=stage/('owned-release-route-'+target)
        ready.unlink(missing_ok=True);release.unlink(missing_ok=True)
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
            try:
                stale=pool.submit(call,work,env,stage,kind='message',actor=9001,
                    update_id=599,message_id=49,text='CANCEL',
                    mode='pause_before_route',cached_ready=str(ready),
                    release_route=str(release))
                wait_for(ready.exists,'Old CANCEL did not read its cached legacy state')
                ensure(q(work,env,'SELECT step FROM users WHERE userid=9001')==legacy_step,
                       'Old CANCEL fixture did not capture the expected legacy step')
                begin(work,env,stage,update_id=600)
                if target in ['day','volume']:
                    remark(work,env,stage,update_id=601)
                if target=='volume':
                    day(work,env,stage,update_id=602)
                exact=q(work,env,'SELECT step FROM users WHERE userid=9001')
                ensure(exact.startswith('achbotDirectGift'+target.title()+':'),
                       'Newer direct gift did not reach expected '+target+' step')
                release.write_text('go')
                result=stale.result(timeout=38)
            finally:
                release.write_text('go')
        ensure(q(work,env,'SELECT step FROM users WHERE userid=9001')==exact and
               q(work,env,"SELECT COUNT(*) FROM server_plans WHERE id=987")=='1' and
               len(rows(work,env,'START'))==1 and not rows(work,env,'SOURCE') and
               not effects(stage) and not any(
                   e in ['WAIT','MAIN'] for e in result['events']),
               'Stale cached global CANCEL erased a newer '+target+
               ' wizard or performed legacy cleanup')
        passed.append('global_cancel_stale_cached_legacy_preserves_'+target)

    reset(work,env);stage.joinpath('effects.jsonl').unlink(missing_ok=True)
    q(work,env,"UPDATE users SET step='giftServerDay2' WHERE userid=9001")
    seed_inactive_legacy_plan(work,env)
    fresh=call(work,env,stage,kind='message',actor=9001,
               update_id=650,message_id=65,text='CANCEL')
    ensure(q(work,env,'SELECT step FROM users WHERE userid=9001')=='none' and
           q(work,env,"SELECT COUNT(*) FROM server_plans WHERE id=987")=='0' and
           'WAIT' in fresh['events'] and 'MAIN' in fresh['events'],
           'Fresh legacy CANCEL did not preserve existing step/cleanup UX')
    passed.append('global_cancel_fresh_legacy_flow_unchanged')

    # Revocation cannot allow an older cached fallback to clear an already
    # committed delegated-admin gift; the guarded direct route rejects fresh
    # revoked cancellation separately.
    reset(work,env);stage.joinpath('effects.jsonl').unlink(missing_ok=True)
    seed_inactive_legacy_plan(work,env)
    ready=stage/'owned-delegated-cached-ready'
    release=stage/'owned-delegated-cached-release'
    ready.unlink(missing_ok=True);release.unlink(missing_ok=True)
    with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
        try:
            stale=pool.submit(call,work,env,stage,kind='message',actor=9002,
                update_id=599,message_id=49,text='CANCEL',
                mode='pause_before_route',cached_ready=str(ready),
                release_route=str(release))
            wait_for(ready.exists,'Delegated actor did not cache legacy state')
            begin(work,env,stage,actor=9002,update_id=600)
            current=q(work,env,'SELECT step FROM users WHERE userid=9002')
            q(work,env,'UPDATE users SET isAdmin=0 WHERE userid=9002')
            release.write_text('go')
            result=stale.result(timeout=38)
        finally:
            release.write_text('go')
    ensure(q(work,env,'SELECT step FROM users WHERE userid=9002')==current and
           q(work,env,"SELECT COUNT(*) FROM server_plans WHERE id=987")=='1' and
           not any(e in ['WAIT','MAIN'] for e in result['events']) and
           not effects(stage),
           'Revoked delegated actor old CANCEL clobbered newer gift')
    passed.append('global_cancel_stale_revoked_delegate_does_not_clobber')

    # A second class of obsolete handlers may call the original config.php
    # setUser() before reaching the fallback. It must never overwrite an
    # active direct-gift generation either.
    reset(work,env);stage.joinpath('effects.jsonl').unlink(missing_ok=True)
    seed_inactive_legacy_plan(work,env)
    ready=stage/'owned-stale-set-user-ready'
    release=stage/'owned-stale-set-user-release'
    ready.unlink(missing_ok=True);release.unlink(missing_ok=True)
    with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
        try:
            stale=pool.submit(call,work,env,stage,kind='message',actor=9001,
                update_id=599,message_id=49,text='CANCEL',
                mode='stale_legacy_set_user',cached_ready=str(ready),
                release_route=str(release))
            wait_for(ready.exists,'Legacy setter did not cache older userInfo')
            begin(work,env,stage,update_id=600)
            current=q(work,env,'SELECT step FROM users WHERE userid=9001')
            release.write_text('go')
            result=stale.result(timeout=38)
        finally:
            release.write_text('go')
    ensure(q(work,env,'SELECT step FROM users WHERE userid=9001')==current and
           q(work,env,"SELECT COUNT(*) FROM server_plans WHERE id=987")=='1' and
           not any(e in ['WAIT','MAIN'] for e in result['events']) and
           not effects(stage),
           'Other cached legacy setUser step write bypassed direct-gift lock')
    passed.append('global_cancel_stale_legacy_setuser_cannot_clobber')

    reset(work,env);stage.joinpath('effects.jsonl').unlink(missing_ok=True)
    q(work,env,"UPDATE users SET step='giftServerDay2' WHERE userid=9001")
    call(work,env,stage,kind='message',actor=9001,
         update_id=650,message_id=65,text='CANCEL',mode='cancel_commit_unknown')
    ensure(q(work,env,'SELECT step FROM users WHERE userid=9001')=='none',
           'Unknown legacy CANCEL COMMIT acknowledgment did not preserve DB truth')
    begin(work,env,stage,update_id=700)
    current=q(work,env,'SELECT step FROM users WHERE userid=9001')
    reissued=call(work,env,stage,kind='message',actor=9001,
        update_id=650,message_id=65,text='CANCEL')
    ensure(q(work,env,'SELECT step FROM users WHERE userid=9001')==current and
           not any(e in ['WAIT','MAIN'] for e in reissued['events']) and
           not effects(stage),
           'Old CANCEL following uncertain ACK erased new direct-gift nonce')
    passed.append('global_cancel_unknown_ack_replay_preserves_new_gift')


def moderation_rows(work: Path, env: dict) -> list[dict]:
    raw=q(work,env,"SELECT value FROM setting WHERE type LIKE 'ACHBOT_ADMIN_MOD_SOURCE_%'")
    return [json.loads(x) for x in raw.splitlines() if x]


def verify_admin_ingress_and_legacy_manual(work: Path, env: dict, stage: Path,
                                           passed: list[str]) -> None:
    # Same real current config.php moderation body, bot.php ingress function
    # and ManualAccounts.php handler; all DB/socket and notices are disposable.
    def temp(actor: int=9001) -> str:
        return q(work,env,f"SELECT COALESCE(temp,'NULL') FROM users WHERE userid={actor}")

    # An unauthenticated request must be denied by the bot.php ingress before
    # any source-derived mutation. Invalid callback frames and nonadmins must
    # also fail closed under current role/identity checks.
    for label, extra in [
        ('absent_header',{'missing_header':True}),
        ('wrong_header',{'bad_header':True}),
        ('wrong_method',{'bad_method':True}),
        ('group_chat',{'chat_type':'group'}),
        ('mixed_update',{'kind':'mixed'}),
        ('bot_sender',{'is_bot':True}),
        ('malformed_callback',{'text':'toggleBan_1001junk'}),
        ('forged_actor_binding',{'bound_actor':9002}),
    ]:
        reset(work,env)
        spec=dict(route='moderation',kind='callback',actor=9001,
                  update_id=520,message_id=60,text='toggleBan_1001')
        spec.update(extra)
        result=call(work,env,stage,**spec)
        ensure(q(work,env,"SELECT step FROM users WHERE userid=1001")=='none' and
               not moderation_rows(work,env) and
               not any('MOD_NOTICE' in e for e in result['events']),
               'Unauthentic or malformed moderation mutated users: '+label)
        passed.append('moderation_rejects_'+label)

    reset(work,env)
    ordinary=call(work,env,stage,route='moderation',kind='callback',
        actor=1001,update_id=550,message_id=65,text='toggleBan_9001')
    ensure(q(work,env,"SELECT step FROM users WHERE userid=9001")=='none' and
           not moderation_rows(work,env) and
           'MOD_NOTICE' not in ordinary['events'],
           'An ordinary customer performed an admin moderation toggle')
    passed.append('moderation_current_admin_required')

    reset(work,env)
    q(work,env,'UPDATE users SET isAdmin=0 WHERE userid=9002')
    revoked=call(work,env,stage,route='moderation',kind='callback',
        actor=9002,update_id=550,message_id=65,text='toggleAgent_1001')
    ensure(q(work,env,"SELECT is_agent FROM users WHERE userid=1001")=='0' and
           not moderation_rows(work,env) and
           'MOD_NOTICE' not in revoked['events'],
           'A revoked delegated administrator modified agent privileges')
    passed.append('moderation_revoked_delegate_denied')

    reset(work,env)
    success=call(work,env,stage,route='moderation',kind='callback',
        actor=9001,update_id=550,message_id=65,text='toggleAgent_1001')
    ensure(q(work,env,'SELECT is_agent FROM users WHERE userid=1001')=='1' and
           'MOD_NOTICE' in success['events'] and
           len(moderation_rows(work,env))==1,
           'A fresh verified administrator could not toggle agent status')
    repeated=call(work,env,stage,route='moderation',kind='callback',
        actor=9001,update_id=550,message_id=65,text='toggleAgent_1001')
    ensure(q(work,env,'SELECT is_agent FROM users WHERE userid=1001')=='1' and
           'MOD_NOTICE' not in repeated['events'] and
           len(moderation_rows(work,env))==1,
           'Replay of the same signed moderation update double-toggled user')
    passed.append('moderation_fresh_admin_agent_idempotence')
    later=call(work,env,stage,route='moderation',kind='callback',
        actor=9001,update_id=551,message_id=65,text='toggleBan_1001')
    ensure(q(work,env,'SELECT step FROM users WHERE userid=1001')=='banned' and
           'MOD_NOTICE' in later['events'],
           'Subsequent authorized moderation could not ban customer')
    passed.append('moderation_fresh_admin_ban_succeeds')

    # Reject a callback whose update_id predates a newly committed gift,
    # even when it is a signed, currently privileged moderator.
    reset(work,env)
    begin(work,env,stage,update_id=600)
    step=q(work,env,'SELECT step FROM users WHERE userid=9001')
    old=call(work,env,stage,route='moderation',kind='callback',
        actor=9002,update_id=599,message_id=59,text='toggleBan_9001')
    ensure(q(work,env,'SELECT step FROM users WHERE userid=9001')==step and
           not moderation_rows(work,env) and
           'MOD_NOTICE' not in old['events'] and not effects(stage),
           'Old signed moderator callback erased current gift wizard')
    passed.append('moderation_old_callback_cannot_erase_gift_nonce')
    # Deliberate NEWER authorized admin ban takes precedence over the gift,
    # and only the exact current state may be consumed.
    fresh=call(work,env,stage,route='moderation',kind='callback',
        actor=9002,update_id=601,message_id=60,text='toggleBan_9001')
    ensure(q(work,env,'SELECT step FROM users WHERE userid=9001')=='banned' and
           'MOD_NOTICE' in fresh['events'] and not effects(stage),
           'Explicit current moderation cannot preempt gift safely')
    passed.append('moderation_fresh_authorized_ban_preempts_gift')

    reset(work,env)
    before=temp()
    q(work,env,"INSERT INTO users(userid,name,username,refcode,wallet,date) "
      "VALUES(1001,'Duplicate','duplicate','0',0,'1700000000')")
    try:
        denied=call(work,env,stage,route='moderation',kind='callback',
            actor=9001,update_id=601,message_id=60,text='toggleAgent_1001')
        ensure('MOD_NOTICE' not in denied['events'] and
               not moderation_rows(work,env) and temp()==before,
               'Ambiguous target identity was accepted for moderation')
    finally:
        q(work,env,"DELETE FROM users WHERE username='duplicate' AND userid=1001")
    passed.append('moderation_nonunique_target_identity_rejected')

    # Simulated lost post-COMMIT acknowledgment: durable source record ensures
    # that retry cannot reverse a completed toggle, and no success is claimed.
    reset(work,env)
    uncertain=call(work,env,stage,route='moderation',kind='callback',
        actor=9001,update_id=610,message_id=70,text='toggleAgent_1001',
        mode='moderation_commit_unknown')
    ensure(q(work,env,'SELECT is_agent FROM users WHERE userid=1001')=='1' and
           'MOD_NOTICE' not in uncertain['events'] and
           len(moderation_rows(work,env))==1,
           'Unknown moderation COMMIT did not fail closed with journal')
    retry=call(work,env,stage,route='moderation',kind='callback',
        actor=9001,update_id=610,message_id=70,text='toggleAgent_1001')
    ensure(q(work,env,'SELECT is_agent FROM users WHERE userid=1001')=='1' and
           'MOD_NOTICE' not in retry['events'],
           'Moderation duplicate replay after uncertain COMMIT changed state')
    passed.append('moderation_uncertain_commit_never_replays_toggle')

    legacy_json='{"awaiting_userid_for_add":true,"selected_server_id":2}'
    # Fresh authorized ManualAccounts numeric input still consumes exactly its
    # legacy step and matching temp, with no lost legitimate functionality.
    reset(work,env)
    q(work,env,"UPDATE users SET step='awaiting_userid_input',temp='"+
      legacy_json+"' WHERE userid=9001")
    fresh=call(work,env,stage,route='manual',kind='message',actor=9001,
        update_id=500,message_id=50,text='1001')
    ensure(q(work,env,'SELECT step FROM users WHERE userid=9001')=='' and
           json.loads(temp())['custom_userid']==1001 and
           any('با موفقیت ثبت شد' in e for e in fresh['events']) and
           not effects(stage),
           'Fresh legitimate ManualAccounts selection broke')
    passed.append('manual_accounts_fresh_current_authorized_succeeds')

    # A reads cached old step+temp, B commits newer direct gift, A resumes
    # through the real ManualAccounts handler (which has historically exited
    # before the protected direct-gift route).
    reset(work,env)
    q(work,env,"UPDATE users SET step='awaiting_userid_input',temp='"+
      legacy_json+"' WHERE userid=9001")
    ready=stage/'manual-cache-ready'
    release=stage/'manual-release-route'
    ready.unlink(missing_ok=True);release.unlink(missing_ok=True)
    with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
        try:
            stale=pool.submit(call,work,env,stage,route='manual',kind='message',
                actor=9001,update_id=599,message_id=59,text='1001',
                mode='pause_before_route',cached_ready=str(ready),
                release_route=str(release))
            wait_for(ready.exists,'ManualAccounts did not cache old step/temp')
            ensure(q(work,env,"SELECT step FROM users WHERE userid=9001")==
                   'awaiting_userid_input','ManualAccounts fixture incorrectly armed')
            begin(work,env,stage,update_id=600)
            current=q(work,env,'SELECT step FROM users WHERE userid=9001')
            before=temp()
            release.write_text('go')
            result=stale.result(timeout=38)
        finally:
            release.write_text('go')
    ensure(q(work,env,'SELECT step FROM users WHERE userid=9001')==current and
           temp()==before and not any('با موفقیت ثبت شد' in e for e in result['events']) and
           not effects(stage),
           'Old ManualAccounts overwrote newer gift nonce, temp or notice')
    passed.append('manual_accounts_stale_cached_step_temp_preserves_new_gift')

    reset(work,env)
    q(work,env,"UPDATE users SET step='awaiting_userid_input',temp='"+
      legacy_json+"' WHERE userid=9002")
    ready=stage/'manual-revoked-ready'
    release=stage/'manual-revoked-release'
    ready.unlink(missing_ok=True);release.unlink(missing_ok=True)
    with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
        try:
            old=pool.submit(call,work,env,stage,route='manual',kind='message',
                actor=9002,update_id=600,message_id=60,text='1001',
                mode='pause_before_route',cached_ready=str(ready),
                release_route=str(release))
            wait_for(ready.exists,'Delegated ManualAccounts worker not cached')
            q(work,env,'UPDATE users SET isAdmin=0 WHERE userid=9002')
            original=temp(9002)
            release.write_text('go')
            result=old.result(timeout=38)
        finally:
            release.write_text('go')
    ensure(q(work,env,'SELECT step FROM users WHERE userid=9002')==
           'awaiting_userid_input' and temp(9002)==original and
           not any('با موفقیت ثبت شد' in e for e in result['events']),
           'Revoked delegated admin modified stale ManualAccounts temp/step')
    passed.append('manual_accounts_current_delegate_role_required')

    reset(work,env)
    q(work,env,"UPDATE users SET step='awaiting_userid_input',temp='"+
      legacy_json+"' WHERE userid=9001")
    unknown=call(work,env,stage,route='manual',kind='message',
        actor=9001,update_id=610,message_id=70,text='1001',
        mode='manual_commit_unknown')
    ensure(q(work,env,'SELECT step FROM users WHERE userid=9001')=='' and
           json.loads(temp())['custom_userid']==1001 and
           not any('با موفقیت ثبت شد' in e for e in unknown['events']),
           'Unknown ManualAccounts COMMIT asserted success or lost state')
    begin(work,env,stage,update_id=700)
    current=q(work,env,'SELECT step FROM users WHERE userid=9001')
    replay=call(work,env,stage,route='manual',kind='message',
        actor=9001,update_id=610,message_id=70,text='1001',
        stale_step='awaiting_userid_input')
    ensure(q(work,env,'SELECT step FROM users WHERE userid=9001')==current and
           not any('با موفقیت ثبت شد' in e for e in replay['events']) and
           not effects(stage),
           'Old ManualAccounts after uncertain COMMIT erased later gift')
    passed.append('manual_accounts_unknown_commit_replay_preserves_gift')

    # A callback's old *bot-authored* text must never be consumed as a
    # newly authored private numeric message; mixed frames fail identically.
    for label, spec in [
        ('callback_old_bot_text',dict(kind='callback',text='legacyAction',
                                      callback_bot_text='1001')),
        ('mixed_update',dict(kind='mixed',text='1001')),
        ('missing_ingress_secret',dict(kind='message',text='1001',
                                       missing_header=True)),
    ]:
        reset(work,env)
        q(work,env,"UPDATE users SET step='awaiting_userid_input',temp='"+
          legacy_json+"' WHERE userid=9001")
        original=temp()
        result=call(work,env,stage,route='manual',actor=9001,
                    update_id=650,message_id=65,**spec)
        ensure(q(work,env,'SELECT step FROM users WHERE userid=9001')==
               'awaiting_userid_input' and temp()==original and
               not any('با موفقیت ثبت شد' in e for e in result['events']) and
               not effects(stage),
               'ManualAccounts accepted non-message/unauthenticated input '+label)
        passed.append('manual_accounts_rejects_'+label)

def verify_audited_step_writers(work: Path, env: dict, stage: Path,
                               passed: list[str]) -> None:
    # Other pre-existing direct SQL users.step writers must not bypass the
    # active direct-gift generation. These probes use actual source-extracted
    # PHP handlers/statement on the same disposable historical schema.
    reset(work,env);begin(work,env,stage,update_id=600)
    current=q(work,env,'SELECT step FROM users WHERE userid=9001')
    old=call(work,env,stage,route='legacy_ban',kind='message',
        actor=9001,update_id=599,message_id=59,text='9001',
        stale_step='banUser')
    ensure(q(work,env,'SELECT step FROM users WHERE userid=9001')==current and
           not any('مسدودش کردم' in e for e in old['events']) and
           not effects(stage),
           'Legacy ban text wrote an active direct-gift step')
    passed.append('legacy_text_ban_cannot_erase_active_gift')

    reset(work,env);begin(work,env,stage,update_id=600)
    current=q(work,env,'SELECT step FROM users WHERE userid=9001')
    old=call(work,env,stage,route='legacy_receipt_ban',kind='callback',
        actor=9002,update_id=599,message_id=59,
        text='banUserDirect_9001')
    ensure(q(work,env,'SELECT step FROM users WHERE userid=9001')==current and
           not any(e.startswith('ALERT:✅') or e=='EDIT_KEYS'
                   for e in old['events']) and
           not effects(stage),
           'Old receipt-ban callback erased a protected direct-gift wizard')
    passed.append('legacy_receipt_ban_cannot_erase_active_gift')

    reset(work,env);begin(work,env,stage,update_id=600)
    current=q(work,env,'SELECT step FROM users WHERE userid=9001')
    call(work,env,stage,route='broadcast',kind='message',
         actor=9001,update_id=601,message_id=61,text='irrelevant',
         target=9001)
    ensure(q(work,env,'SELECT step FROM users WHERE userid=9001')==current and
           not effects(stage),
           'Automated broadcast block erased a protected gift state')
    passed.append('broadcast_bot_blocked_preserves_active_gift')

    reset(work,env)
    call(work,env,stage,route='broadcast',kind='message',
         actor=1001,update_id=601,message_id=61,text='irrelevant',
         target=1001)
    ensure(q(work,env,'SELECT step FROM users WHERE userid=1001')==
           'Bot_Blocked' and not effects(stage),
           'Ordinary broadcast block behavior unexpectedly disabled')
    passed.append('broadcast_bot_blocked_ordinary_behavior_preserved')


def verify_exemption_and_ban_freshness(work: Path, env: dict, stage: Path,
                                      passed: list[str]) -> None:
    # Execute the actual extracted config.php exemption implementation and
    # actual bot.php mainMenu, on disposable 23-table socket-only MariaDB.
    def policy() -> list[str]:
        raw=q(work,env,"SELECT value FROM setting WHERE type='EXEMPTED_USERS' ORDER BY id")
        return [] if not raw else json.loads(raw)

    def exempt_marker(actor: int=9001) -> dict | None:
        raw=q(work,env,"SELECT value FROM setting WHERE type LIKE 'ACHBOT_EXEMPT_SOURCE_%' ORDER BY id")
        values=[json.loads(x) for x in raw.splitlines() if x]
        return next((x for x in values if x['actor_id']==actor),None)

    def arm(actor: int=9001,update_id: int=500) -> dict:
        return call(work,env,stage,route='exemption',kind='callback',
                    actor=actor,update_id=update_id,message_id=50,text='addExemptUser')

    reset(work,env)
    r=arm()
    step=q(work,env,'SELECT step FROM users WHERE userid=9001')
    ensure(step.startswith('achbotExempt:500:50:') and
           any('شناسه عددی' in e for e in r['events']) and
           exempt_marker() and exempt_marker()['last_update_id']==500 and
           policy()==[] and not effects(stage),
           'Fresh exemption callback failed to arm verified wizard')
    done=call(work,env,stage,route='exemption',kind='message',
        actor=9001,update_id=501,message_id=51,text='1001')
    ensure(q(work,env,'SELECT step FROM users WHERE userid=9001')=='none' and
           list(map(str,policy()))==['1001'] and
           exempt_marker()['last_update_id']==501 and
           any('✅' in e for e in done['events']) and not effects(stage),
           'Authorized channel exemption did not commit atomically')
    passed.append('exemption_authorized_current_start_and_atomic_completion')

    # Replays of both the original callback and completed numeric message
    # must not rearm or repeat a stale policy mutation.
    replay=arm(update_id=500)
    repeat=call(work,env,stage,route='exemption',kind='message',
        actor=9001,update_id=501,message_id=51,text='1001',
        stale_step=step)
    ensure(q(work,env,'SELECT step FROM users WHERE userid=9001')=='none' and
           policy()==['1001'] and
           not any('✅' in e or 'شناسه عددی' in e
                   for result in (replay,repeat) for e in result['events']),
           'Replayed exemption source repeated a consumed wizard')
    passed.append('exemption_durable_source_replay_denied')

    # A newer Telegram callback may originate from an older bot-authored
    # settings message. Only update_id, not callback message_id, orders
    # successive callback events. A fresh numeric reply must still be newer.
    freshOldMenu=arm(update_id=502)
    ensure(q(work,env,'SELECT step FROM users WHERE userid=9001').startswith(
               'achbotExempt:502:50:') and
           any('شناسه عددی' in e for e in freshOldMenu['events']),
           'Fresh exemption callback on older bot menu incorrectly rejected')
    already=call(work,env,stage,route='exemption',kind='message',
        actor=9001,update_id=503,message_id=52,text='1001')
    ensure(policy()==['1001'] and
           q(work,env,'SELECT step FROM users WHERE userid=9001')=='none' and
           exempt_marker()['last_update_id']==503 and
           any('قبلاً' in e for e in already['events']),
           'Fresh older-menu callback produced an invalid exemption completion')
    passed.append('exemption_new_update_from_old_bot_menu_still_works')

    reset(work,env)
    first=call(work,env,stage,route='moderation',kind='callback',
        actor=9001,update_id=550,message_id=70,text='toggleAgent_1001')
    second=call(work,env,stage,route='moderation',kind='callback',
        actor=9001,update_id=551,message_id=35,text='toggleBan_1001')
    ensure('MOD_NOTICE' in first['events'] and
           'MOD_NOTICE' in second['events'] and
           q(work,env,'SELECT step FROM users WHERE userid=1001')=='banned' and
           len(moderation_rows(work,env))==1,
           'New signed moderation callback from older menu was rejected')
    passed.append('moderation_new_update_from_old_bot_menu_still_works')

    reset(work,env)
    ordinary=call(work,env,stage,route='exemption',kind='callback',
        actor=1001,update_id=550,message_id=60,text='addExemptUser')
    ensure(q(work,env,'SELECT step FROM users WHERE userid=1001')=='none' and
           not exempt_marker() and policy()==[] and
           not ordinary['events'],
           'Ordinary customer started a privileged exemption wizard')
    passed.append('exemption_ordinary_customer_start_denied')

    reset(work,env)
    q(work,env,'UPDATE users SET isAdmin=0 WHERE userid=9002')
    revoked=call(work,env,stage,route='exemption',kind='callback',
        actor=9002,update_id=550,message_id=60,text='addExemptUser')
    ensure(q(work,env,'SELECT step FROM users WHERE userid=9002')=='none' and
           not exempt_marker(9002) and policy()==[] and not revoked['events'],
           'Revoked delegated administrator started an exemption wizard')
    passed.append('exemption_revoked_delegate_start_denied')

    reset(work,env)
    arm(actor=9002)
    q(work,env,'UPDATE users SET isAdmin=0 WHERE userid=9002')
    step=q(work,env,'SELECT step FROM users WHERE userid=9002')
    revoked=call(work,env,stage,route='exemption',kind='message',
        actor=9002,update_id=501,message_id=51,text='1001')
    ensure(q(work,env,'SELECT step FROM users WHERE userid=9002')==step and
           policy()==[] and not any('✅' in e for e in revoked['events']) and
           exempt_marker(9002)['last_update_id']==500,
           'Revoked delegated administrator retained stale exemption authority')
    passed.append('exemption_revoked_delegate_completion_denied')

    for label,opts in [
        ('unsigned',dict(kind='callback',text='addExemptUser',missing_header=True)),
        ('wrong_secret',dict(kind='callback',text='addExemptUser',bad_header=True)),
        ('group_callback',dict(kind='callback',text='addExemptUser',chat_type='group')),
        ('mixed_callback',dict(kind='mixed',text='addExemptUser')),
        ('bot_sender',dict(kind='callback',text='addExemptUser',is_bot=True)),
        ('wrong_actor',dict(kind='callback',text='addExemptUser',wire_actor=1001)),
    ]:
        reset(work,env)
        result=call(work,env,stage,route='exemption',actor=9001,
                    update_id=550,message_id=60,**opts)
        ensure(q(work,env,'SELECT step FROM users WHERE userid=9001')=='none' and
               policy()==[] and not exempt_marker() and not result['events'],
               'Invalid exemption ingress/callback source accepted: '+label)
        passed.append('exemption_start_rejects_'+label)

    for label,opts in [
        ('callback_bot_text',dict(kind='callback',text='otherCallback',
                                  callback_bot_text='1001')),
        ('mixed_message',dict(kind='mixed',text='1001')),
        ('stale_message_id',dict(kind='message',text='1001',message_id=50)),
        ('stale_update_id',dict(kind='message',text='1001',update_id=500)),
        ('wrong_chat',dict(kind='message',text='1001',wire_chat=1002)),
    ]:
        reset(work,env)
        arm()
        step=q(work,env,'SELECT step FROM users WHERE userid=9001')
        opts=dict(opts)
        opts.setdefault('update_id',501)
        opts.setdefault('message_id',51)
        result=call(work,env,stage,route='exemption',actor=9001,**opts)
        ensure(q(work,env,'SELECT step FROM users WHERE userid=9001')==step and
               policy()==[] and exempt_marker()['last_update_id']==500 and
               not any('✅' in e for e in result['events']),
               'Invalid exemption text source mutated global policy: '+label)
        passed.append('exemption_finish_rejects_'+label)

    # A caches an old exemption wizard, B starts a newer DirectGift in the
    # same actor's row. A must not touch the global exemption policy.
    reset(work,env)
    arm()
    ready=stage/'exempt-cache-ready';release=stage/'exempt-cache-release'
    ready.unlink(missing_ok=True);release.unlink(missing_ok=True)
    with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
        try:
            old=pool.submit(call,work,env,stage,route='exemption',
                actor=9001,kind='message',text='1001',update_id=599,
                message_id=59,mode='pause_before_route',
                cached_ready=str(ready),release_route=str(release))
            wait_for(ready.exists,'Exemption worker did not cache old step')
            begin(work,env,stage,update_id=600)
            current=q(work,env,'SELECT step FROM users WHERE userid=9001')
            release.write_text('go')
            oldResult=old.result(timeout=38)
        finally:
            release.write_text('go')
    ensure(current.startswith('achbotDirectGift') and
           q(work,env,'SELECT step FROM users WHERE userid=9001')==current and
           policy()==[] and exempt_marker()['last_update_id']==500 and
           not any('✅' in e for e in oldResult['events']) and
           not effects(stage),
           'Obsolete exemption modified global policy after newer gift')
    passed.append('exemption_stale_cached_worker_preserves_new_gift_and_policy')

    reset(work,env);arm()
    commitUnknown=call(work,env,stage,route='exemption',kind='message',
        actor=9001,update_id=501,message_id=51,text='1001',
        mode='exempt_commit_unknown')
    ensure(policy()==['1001'] and
           q(work,env,'SELECT step FROM users WHERE userid=9001')=='none' and
           exempt_marker()['last_update_id']==501 and
           not any('✅' in e for e in commitUnknown['events']),
           'Uncertain exemption COMMIT advertised unverified success')
    retry=call(work,env,stage,route='exemption',kind='message',
        actor=9001,update_id=501,message_id=51,text='1001',
        stale_step='achbotExempt:500:50:'+'a'*32)
    ensure(policy()==['1001'] and
           not any('✅' in e for e in retry['events']) and not effects(stage),
           'Uncertain exemption result permitted a duplicate policy change')
    passed.append('exemption_unknown_commit_no_success_or_replay')

    reset(work,env);arm()
    q(work,env,"INSERT INTO setting(type,value) VALUES('EXEMPTED_USERS','[]'),('EXEMPTED_USERS','[]')")
    amb=call(work,env,stage,route='exemption',kind='message',
        actor=9001,update_id=501,message_id=51,text='1001')
    ensure(q(work,env,"SELECT COUNT(*) FROM setting WHERE type='EXEMPTED_USERS'")=='2' and
           q(work,env,'SELECT step FROM users WHERE userid=9001').startswith('achbotExempt') and
           not any('✅' in e for e in amb['events']),
           'Ambiguous nonunique channel-exemption settings accepted')
    passed.append('exemption_nonunique_setting_fail_closed')

    # A caches an old mainMenu request. B commits an authorized newer ban
    # against A. A resumes the ACTUAL bot.php mainMenu + config.php setUser.
    reset(work,env)
    ready=stage/'menu-cache-ready';release=stage/'menu-cache-release'
    ready.unlink(missing_ok=True);release.unlink(missing_ok=True)
    with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
        try:
            old=pool.submit(call,work,env,stage,route='main_menu',
                actor=9001,kind='callback',text='mainMenu',update_id=599,
                message_id=59,mode='pause_before_route',
                cached_ready=str(ready),release_route=str(release))
            wait_for(ready.exists,'Legacy mainMenu worker did not cache old step')
            moderator=call(work,env,stage,route='moderation',kind='callback',
                actor=9002,update_id=600,message_id=60,text='toggleBan_9001')
            ensure('MOD_NOTICE' in moderator['events'],
                   'Newer authentic moderation ban did not commit')
            release.write_text('go')
            oldResult=old.result(timeout=38)
        finally:
            release.write_text('go')
    ensure(q(work,env,'SELECT step FROM users WHERE userid=9001')=='banned' and
           len(moderation_rows(work,env))==1 and
           not any('WELCOME' in e or 'BANNED' in e
                   for e in oldResult['events']) and not effects(stage),
           'Stale mainMenu cleared or misreported newly authorized ban')
    passed.append('legacy_main_menu_cannot_erase_newer_authorized_ban')

    staleCancel=call(work,env,stage,kind='message',actor=9001,
        update_id=599,message_id=59,text='CANCEL',stale_step='none')
    ensure(q(work,env,'SELECT step FROM users WHERE userid=9001')=='banned' and
           len(moderation_rows(work,env))==1 and not effects(stage) and
           not any('WAIT' in e for e in staleCancel['events']),
           'Older CANCEL erased a newer administrator ban')
    passed.append('legacy_stale_cancel_cannot_erase_newer_ban')

    # The dedicated authorized legacy unban still has its own exact PK/CAS
    # against the current banned state; generic setUser is NEVER unban.
    q(work,env,"UPDATE users SET step='unbanUser' WHERE userid=9002")
    unbanned=call(work,env,stage,route='legacy_unban',kind='message',
        actor=9002,update_id=601,message_id=61,text='9001')
    ensure(q(work,env,'SELECT step FROM users WHERE userid=9001')=='none' and
           any('✅' in e for e in unbanned['events']),
           'Deliberate authorized unban was disabled by legacy step guard')
    passed.append('legacy_explicit_authorized_unban_still_operates')

    reset(work,env)
    q(work,env,"UPDATE users SET step='oldLegacyStep' WHERE userid=9001")
    ordinary=call(work,env,stage,route='main_menu',kind='callback',
        actor=9001,update_id=600,message_id=60,text='mainMenu')
    ensure(q(work,env,'SELECT step FROM users WHERE userid=9001')=='none' and
           any('EDIT_TEXT:WELCOME' in e for e in ordinary['events']),
           'Ordinary non-banned mainMenu stopped functioning')
    passed.append('legacy_nonbanned_main_menu_works')

    # Exemption is itself a nonce-protected administrator wizard. Obsolete
    # mainMenu/text moderation and older signed bans must not consume it.
    reset(work,env)
    arm(update_id=600)
    exemptStep=q(work,env,'SELECT step FROM users WHERE userid=9001')
    oldMenu=call(work,env,stage,route='main_menu',kind='callback',
        actor=9001,update_id=599,message_id=59,text='mainMenu')
    ensure(q(work,env,'SELECT step FROM users WHERE userid=9001')==exemptStep and
           not any('WELCOME' in e for e in oldMenu['events']),
           'Older mainMenu erased an active exemption wizard')
    passed.append('exemption_old_main_menu_cannot_erase_current_nonce')

    oldBan=call(work,env,stage,route='moderation',kind='callback',
        actor=9002,update_id=599,message_id=59,text='toggleBan_9001')
    ensure(q(work,env,'SELECT step FROM users WHERE userid=9001')==exemptStep and
           'MOD_NOTICE' not in oldBan['events'],
           'Older signed moderator ban erased newer exemption wizard')
    passed.append('exemption_old_moderation_ban_cannot_erase_nonce')

    oldText=call(work,env,stage,route='legacy_ban',kind='message',
        actor=9001,update_id=599,message_id=59,text='9001',
        stale_step='banUser')
    ensure(q(work,env,'SELECT step FROM users WHERE userid=9001')==exemptStep and
           not any('مسدودش کردم' in e for e in oldText['events']),
           'Older textual admin ban erased current exemption wizard')
    passed.append('exemption_legacy_text_ban_cannot_erase_nonce')

    newer=call(work,env,stage,route='moderation',kind='callback',
        actor=9002,update_id=601,message_id=61,text='toggleBan_9001')
    ensure(q(work,env,'SELECT step FROM users WHERE userid=9001')=='banned' and
           'MOD_NOTICE' in newer['events'] and
           exempt_marker()['last_update_id']==600 and policy()==[],
           'Fresh deliberate admin ban cannot override exemption safely')
    passed.append('exemption_newer_authorized_moderation_ban_preempts_wizard')

    # A failing actor-step CAS must roll back a previously staged global
    # policy write, not leave a channel exception behind.
    reset(work,env);arm()
    beforeStep=q(work,env,'SELECT step FROM users WHERE userid=9001')
    q(work,env,"CREATE TRIGGER owned_exempt_step_abort BEFORE UPDATE ON users "
      "FOR EACH ROW SIGNAL SQLSTATE '45000' "
      "SET MESSAGE_TEXT='owned_exempt_step_abort'")
    try:
        rejected=call(work,env,stage,route='exemption',kind='message',
            actor=9001,update_id=501,message_id=51,text='1001')
        ensure(policy()==[] and
               q(work,env,'SELECT step FROM users WHERE userid=9001')==beforeStep and
               exempt_marker()['last_update_id']==500 and
               not any('✅' in e for e in rejected['events']),
               'Failed exemption CAS left a partial global policy modification')
    finally:
        q(work,env,'DROP TRIGGER owned_exempt_step_abort')
    passed.append('exemption_policy_and_step_atomic_rollback_on_sql_failure')

    reset(work,env)
    q(work,env,'INSERT INTO setting(type,value) VALUES('
      "'EXEMPTED_USERS','[\"1001\"]')")
    arm()
    prior=call(work,env,stage,route='exemption',kind='message',
        actor=9001,update_id=501,message_id=51,text='1001')
    ensure(policy()==['1001'] and
           q(work,env,'SELECT step FROM users WHERE userid=9001')=='none' and
           exempt_marker()['last_update_id']==501 and
           any('قبلاً' in e for e in prior['events']),
           'Existing channel exemption was duplicated or wizard left active')
    passed.append('exemption_existing_member_preserved_and_wizard_completed')


def main() -> None:
    ap=argparse.ArgumentParser()
    ap.add_argument('--run-disposable',action='store_true',required=True)
    ap.add_argument('--report',type=Path)
    opts=ap.parse_args()
    passed=[]
    with owned.owned_database() as (work,env):
        stage=setup(work,env)
        verify_global_cancel_stale_cache(work,env,stage,passed)
        verify_admin_ingress_and_legacy_manual(work,env,stage,passed)
        verify_audited_step_writers(work,env,stage,passed)
        verify_exemption_and_ban_freshness(work,env,stage,passed)

        reset(work,env);prepare_final(work,env,stage)
        positive=volume(work,env,stage)
        ensure(len(effects(stage))==1 and row_state(work,env)==(str(NEW_EXPIRY),'none',1,1)
               and rows(work,env,'SOURCE')[0]['state']=='completed'
               and rows(work,env,'ACCOUNT')[0]['state']=='completed'
               and any('✅' in x for x in positive['events']),
               'Verified result failed complete preclaim/remote/CAS journey')
        ensure('password' not in json.dumps(rows(work,env,'SOURCE')),
               'Journal leaked credentials')
        ensure(effects(stage)[0]['change']=={
            'remark':'fixture-user','plus_day':2,'plus_volume':1},
            'Both positive gift fields not transferred as whole units')
        passed.append('real_bot_route_verified_combined_gift_success')

        for days,v,expected,fields in [
            ('0','1024',OLD_EXPIRY,['plus_volume']),
            ('2','0',NEW_EXPIRY,['plus_day'])
        ]:
            reset(work,env);stage.joinpath('effects.jsonl').unlink(missing_ok=True)
            prepare_final(work,env,stage,days)
            volume(work,env,stage,value=v)
            ensure(row_state(work,env)==(str(expected),'none',1,1)
                   and rows(work,env,'SOURCE')[0]['state']=='completed'
                   and all(f in effects(stage)[0]['change'] for f in fields)
                   and all(f not in effects(stage)[0]['change']
                           for f in ['plus_volume','plus_day'] if f not in fields),
                   'Nonzero-only gift payload/verified local state incorrect')
            passed.append('real_bot_route_only_'+('days' if v=='0' else 'volume'))

        for invalid_day in ['-1','1.0','1e2','+2',' 2','2147483648']:
            reset(work,env);stage.joinpath('effects.jsonl').unlink(missing_ok=True)
            begin(work,env,stage);remark(work,env,stage)
            invalid=day(work,env,stage,value=invalid_day)
            ensure('نامعتبر' in ' '.join(invalid['events']) and
                   not effects(stage) and row_state(work,env)[2:]==(0,0),
                   'Invalid day was accepted or applied: '+invalid_day)
            passed.append('invalid_day_'+invalid_day)

        for invalid_vol in ['0','5','1023','-1024','1.0','1e3','+1024',
                            ' 1024','2147483648']:
            reset(work,env);stage.joinpath('effects.jsonl').unlink(missing_ok=True)
            prepare_final(work,env,stage,days='0')
            invalid=volume(work,env,stage,value=invalid_vol)
            ensure('نامعتبر' in ' '.join(invalid['events']) and
                   not effects(stage) and row_state(work,env)[2:]==(0,0),
                   'Invalid volume was accepted or applied: '+invalid_vol)
            passed.append('invalid_volume_'+invalid_vol)

        for mode in ['provider_false','provider_unknown','provider_throw',
                     'provider_unverified','provider_ambiguous_success',
                     'provider_bad_expiry','provider_bad_bytes','sql_drift',
                     'config_drift']:
            reset(work,env);stage.joinpath('effects.jsonl').unlink(missing_ok=True)
            prepare_final(work,env,stage)
            failed=volume(work,env,stage,mode=mode)
            ensure(len(effects(stage))==1 and len(rows(work,env,'SOURCE'))==1
                   and rows(work,env,'SOURCE')[0]['state'] in ['pending','review']
                   and len(rows(work,env,'ACCOUNT'))==1
                   and rows(work,env,'ACCOUNT')[0]['state'] in ['pending','review']
                   and not any('✅' in m for m in failed['events']),
                   'Unknown/failed provider returned success or lost durable hold: '+mode)
            again=volume(work,env,stage,mode=mode)
            ensure(len(effects(stage))==1 and
                   not any('✅' in m for m in again['events']),
                   'Unknown provider replayed effect: '+mode)
            passed.append('provider_or_local_failure_hold_'+mode)

        reset(work,env);stage.joinpath('effects.jsonl').unlink(missing_ok=True)
        prepare_final(work,env,stage)
        no_notice=volume(work,env,stage,mode='notice_fail')
        ensure(len(effects(stage))==1 and
               rows(work,env,'SOURCE')[0]['state']=='completed' and
               row_state(work,env)[0]==str(NEW_EXPIRY),
               'Notification outage reverted a completed provider/SQL commit')
        volume(work,env,stage)
        ensure(len(effects(stage))==1, 'Notification outage replayed provider')
        passed.append('postcommit_notice_failure_no_remote_replay')

        reset(work,env);stage.joinpath('effects.jsonl').unlink(missing_ok=True)
        prepare_final(work,env,stage)
        original_step=q(work,env,'SELECT step FROM users WHERE userid=9001')
        volume(work,env,stage,mode='provider_unknown')
        ordinary_replay=call(work,env,stage,kind='message',actor=9001,
            update_id=503,message_id=53,text='1024',stale_step=original_step)
        ensure(any('قبلاً' in e for e in ordinary_replay['events']) and
               len(rows(work,env,'SOURCE'))==1 and len(effects(stage))==1,
               'Intact original source was not recognized as a harmless duplicate')
        passed.append('verified_prior_source_envelope_duplicate_no_effect')

        for key in ['$.actor_id','$.source.actor_id']:
            reset(work,env);stage.joinpath('effects.jsonl').unlink(missing_ok=True)
            prepare_final(work,env,stage)
            old_step=q(work,env,'SELECT step FROM users WHERE userid=9001')
            volume(work,env,stage,mode='provider_unknown')
            q(work,env,"UPDATE setting SET value=JSON_SET(value,'"+key+"',9002) "
              "WHERE type LIKE 'ACHBOT_DIRECT_GIFT_SOURCE_%'")
            suspicious=call(work,env,stage,kind='message',actor=9001,
                update_id=503,message_id=53,text='1024',stale_step=old_step)
            ensure(not any('قبلاً' in e for e in suspicious['events']) and
                   any('تأیید نشد' in e for e in suspicious['events']) and
                   len(rows(work,env,'SOURCE'))==1 and
                   len(rows(work,env,'ACCOUNT'))==1 and len(effects(stage))==1,
                   'Conflicting stored '+key+' was mislabeled as clean duplicate')
            passed.append('corrupt_source_provenance_fail_closed_'+key)

        reset(work,env);stage.joinpath('effects.jsonl').unlink(missing_ok=True)
        prepare_final(work,env,stage)
        unknown=volume(work,env,stage,mode='commit_unknown')
        ensure(len(effects(stage))==0 and
               len(rows(work,env,'SOURCE'))==1 and
               rows(work,env,'SOURCE')[0]['state']=='pending' and
               row_state(work,env)[1]=='none',
               'Lost pre-effect COMMIT acknowledgment attempted external edit')
        volume(work,env,stage)
        ensure(len(effects(stage))==0,
               'Replay after lost claim COMMIT attempted external edit')
        passed.append('unknown_claim_commit_holds_no_provider_dispatch')

        reset(work,env);stage.joinpath('effects.jsonl').unlink(missing_ok=True)
        begin(work,env,stage,update_id=600)
        prior=q(work,env,'SELECT step FROM users WHERE userid=9001')
        delayed=call(work,env,stage,kind='callback',actor=9001,
            update_id=599,message_id=49,text='giftToUser2')
        ensure(q(work,env,'SELECT step FROM users WHERE userid=9001')==prior and
               len(rows(work,env,'START'))==1 and
               not any('نام کاربری' in t for t in delayed['events']),
               'An older callback overrode the current wizard')
        passed.append('old_callback_cannot_override_new_random_nonce')

        reset(work,env);stage.joinpath('effects.jsonl').unlink(missing_ok=True)
        prepare_final(work,env,stage)
        prior=q(work,env,'SELECT step FROM users WHERE userid=9001')
        call(work,env,stage,kind='callback',actor=9001,
            update_id=501,message_id=49,text='giftToUser2')
        ensure(q(work,env,'SELECT step FROM users WHERE userid=9001')==prior and
               len(rows(work,env,'START'))==1,
               'An older callback interrupted the volume-stage wizard')
        passed.append('old_callback_cannot_interrupt_volume_stage')

        reset(work,env);stage.joinpath('effects.jsonl').unlink(missing_ok=True)
        begin(work,env,stage,update_id=600)
        prior=q(work,env,'SELECT step FROM users WHERE userid=9001')
        replacement=call(work,env,stage,kind='callback',actor=9001,
            update_id=601,message_id=51,text='giftToUser2')
        ensure(q(work,env,'SELECT step FROM users WHERE userid=9001')!=prior and
               len(rows(work,env,'START'))==2 and
               any('نام کاربری' in t for t in replacement['events']),
               'Fresh explicit callback could not restart the wizard')
        passed.append('new_callback_restarts_distinct_nonce')

        # CANCEL is a real wizard transition and must reject older updates.
        for stage_name in ['remark','day','volume']:
            reset(work,env);stage.joinpath('effects.jsonl').unlink(missing_ok=True)
            begin(work,env,stage)
            if stage_name in ['day','volume']: remark(work,env,stage)
            if stage_name=='volume': day(work,env,stage)
            last={'remark':500,'day':501,'volume':502}[stage_name]
            fresh=call(work,env,stage,kind='message',actor=9001,
                       update_id=last+1,message_id=last-449,text='CANCEL')
            ensure(q(work,env,'SELECT step FROM users WHERE userid=9001')=='none' and
                   any('لغو شد' in e for e in fresh['events']) and
                   not effects(stage) and not rows(work,env,'SOURCE'),
                   'Fresh CANCEL did not consume exactly the current '+stage_name+' step')
            passed.append('fresh_cancel_'+stage_name)

        reset(work,env);begin(work,env,stage,update_id=600)
        current=q(work,env,'SELECT step FROM users WHERE userid=9001')
        stale=call(work,env,stage,kind='message',actor=9001,
                   update_id=599,message_id=49,text='CANCEL')
        ensure(q(work,env,'SELECT step FROM users WHERE userid=9001')==current and
               not any('لغو شد' in e for e in stale['events']),
               'Older CANCEL erased a newer nonce-protected wizard')
        passed.append('old_cancel_cannot_erase_new_start_nonce')

        for stage_name in ['day','volume']:
            reset(work,env);begin(work,env,stage);remark(work,env,stage)
            if stage_name=='volume': day(work,env,stage)
            current=q(work,env,'SELECT step FROM users WHERE userid=9001')
            last={'day':501,'volume':502}[stage_name]
            old=call(work,env,stage,kind='message',actor=9001,
                     update_id=last,message_id=last-450,text='CANCEL')
            ensure(q(work,env,'SELECT step FROM users WHERE userid=9001')==current and
                   not any('لغو شد' in e for e in old['events']),
                   'Out-of-order CANCEL consumed '+stage_name+' step')
            passed.append('old_cancel_preserves_'+stage_name)

        reset(work,env);begin(work,env,stage);remark(work,env,stage)
        current=q(work,env,'SELECT step FROM users WHERE userid=9001')
        call(work,env,stage,kind='message',actor=9001,
             update_id=503,message_id=51,text='CANCEL')
        ensure(q(work,env,'SELECT step FROM users WHERE userid=9001')==current,
               'CANCEL with newer update but stale chat message id was accepted')
        passed.append('cancel_checks_last_message_as_well_as_update')

        reset(work,env);begin(work,env,stage)
        call(work,env,stage,kind='message',actor=9001,
             update_id=501,message_id=51,text='CANCEL',mode='cancel_commit_unknown')
        ensure(q(work,env,'SELECT step FROM users WHERE userid=9001')=='none' and
               not effects(stage),
               'Lost CANCEL COMMIT ACK rearmed the old wizard')
        begin(work,env,stage,update_id=600)
        current=q(work,env,'SELECT step FROM users WHERE userid=9001')
        call(work,env,stage,kind='message',actor=9001,
             update_id=501,message_id=51,text='CANCEL')
        ensure(q(work,env,'SELECT step FROM users WHERE userid=9001')==current,
               'Redelivered CANCEL after unknown COMMIT erased a new wizard')
        passed.append('cancel_lost_commit_ack_then_redelivery_fails_closed')

        reset(work,env);begin(work,env,stage,actor=9002)
        current=q(work,env,'SELECT step FROM users WHERE userid=9002')
        q(work,env,'UPDATE users SET isAdmin=0 WHERE userid=9002')
        call(work,env,stage,kind='message',actor=9002,
             update_id=501,message_id=51,text='CANCEL')
        ensure(q(work,env,'SELECT step FROM users WHERE userid=9002')==current,
               'Revoked delegated admin cancelled an active wizard')
        passed.append('revoked_delegated_admin_cannot_cancel')

        reset(work,env);begin(work,env,stage);remark(work,env,stage)
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
            results=list(pool.map(lambda spec:call(work,env,stage,kind='message',
                actor=9001,**spec),[
                {'update_id':502,'message_id':52,'text':'2'},
                {'update_id':503,'message_id':53,'text':'CANCEL'}]))
        remaining=q(work,env,'SELECT step FROM users WHERE userid=9001')
        ensure(remaining=='none' or remaining.startswith('achbotDirectGiftVolume:'),
               'Concurrent CANCEL/progress created an invalid wizard generation')
        ensure(not effects(stage) and not rows(work,env,'SOURCE'),
               'Concurrent CANCEL/progress dispatched a provider effect')
        passed.append('concurrent_cancel_and_progress_generation_cas')

        for kind,spec in [
            ('missing_webhook_secret',{'missing_header':True}),
            ('wrong_webhook_secret',{'bad_header':True}),
            ('group_chat',{'chat_type':'group'}),
            ('wrong_sender',{'wire_actor':9002,'bound_actor':9001}),
            ('bot_sender',{'is_bot':True}),
            ('wrong_actor_binding',{'bound_actor':9002})
        ]:
            reset(work,env);stage.joinpath('effects.jsonl').unlink(missing_ok=True)
            call(work,env,stage,kind='callback',actor=9001,update_id=500,
                 message_id=50,text='giftToUser2',**spec)
            ensure(row_state(work,env)[1]=='none' and not effects(stage) and
                   len(rows(work,env,'START'))==0,
                   'Unauthentic Telegram callback armed admin gift: '+kind)
            passed.append('unauthentic_callback_'+kind)

        reset(work,env);stage.joinpath('effects.jsonl').unlink(missing_ok=True)
        call(work,env,stage,kind='callback',actor=1001,
             update_id=500,message_id=50,text='giftToUser2')
        ensure(row_state(work,env)[1]=='none' and not rows(work,env,'START'),
               'Ordinary customer initiated privileged direct gift')
        passed.append('customer_cannot_start_direct_gift')

        reset(work,env);stage.joinpath('effects.jsonl').unlink(missing_ok=True)
        begin(work,env,stage,actor=9002)
        q(work,env,'UPDATE users SET isAdmin=0 WHERE userid=9002')
        result=remark(work,env,stage,actor=9002)
        ensure(not any('روز' in x for x in result['events']) and
               not effects(stage) and
               q(work,env,"SELECT step FROM users WHERE userid=9002")
                    .startswith('achbotDirectGiftRemark:'),
               'Revoked delegated admin advanced stale wizard')
        passed.append('delegated_admin_revoked_before_step')

        reset(work,env);stage.joinpath('effects.jsonl').unlink(missing_ok=True)
        begin(work,env,stage,actor=9002)
        remark(work,env,stage,actor=9002)
        day(work,env,stage,actor=9002)
        result=volume(work,env,stage,actor=9002)
        ensure(len(effects(stage))==1 and
               q(work,env,"SELECT step FROM users WHERE userid=9002")=='none'
               and any('✅' in e for e in result['events']),
               'Current privileged delegated administrator gift denied')
        passed.append('fresh_delegated_admin_private_gift')

        reset(work,env);stage.joinpath('effects.jsonl').unlink(missing_ok=True)
        begin(work,env,stage)
        q(work,env,"INSERT INTO orders_list (id,userid,token,transid,fileid,server_id,"
          "inbound_id,remark,uuid,protocol,expire_date,link,amount,status,date) "
          "VALUES(92,1001,REPEAT('a',30),'fixture',7,2,0,'fixture-user',"
          "'second-uuid','vless',1800000100,'[]',100,1,'fixture')")
        r=remark(work,env,stage)
        ensure(not any('روز' in x for x in r['events']) and
               not effects(stage) and row_state(work,env)[2:]==(0,0),
               'Ambiguous order was chosen for direct gift')
        passed.append('duplicate_remark_order_rejected')

        reset(work,env);stage.joinpath('effects.jsonl').unlink(missing_ok=True)
        prepare_final(work,env,stage)
        q(work,env,"UPDATE orders_list SET userid=9002 WHERE id=91")
        rejected=volume(work,env,stage)
        ensure(not effects(stage) and not any('✅' in x for x in rejected['events']),
               'Stale recipient identity reached provider')
        passed.append('changed_order_identity_before_claim_denied')

        reset(work,env);stage.joinpath('effects.jsonl').unlink(missing_ok=True)
        prepare_final(work,env,stage)
        # Callback text is NOT an authenticated freshly authored gift value.
        call(work,env,stage,kind='callback',update_id=503,message_id=53,text='giftToUser2')
        ensure(not effects(stage), 'Cached bot-text callback dispatched service edit')
        passed.append('callback_text_never_used_as_gift_input')

        reset(work,env);stage.joinpath('effects.jsonl').unlink(missing_ok=True)
        prepare_final(work,env,stage)
        same_update=lambda:volume(work,env,stage,mode='slow_provider')
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
            out=list(pool.map(lambda ignored:same_update(),range(2)))
        ensure(len(effects(stage))==1 and
               rows(work,env,'SOURCE')[0]['state']=='completed' and
               len(rows(work,env,'SOURCE'))==1 and
               len(rows(work,env,'ACCOUNT'))==1,
               'Concurrent same-source PHP workers dispatched multiple edits')
        passed.append('concurrent_workers_one_unique_durable_claim')

        reset(work,env);stage.joinpath('effects.jsonl').unlink(missing_ok=True)
        prepare_final(work,env,stage)
        volume(work,env,stage,mode='provider_unknown')
        ensure(rows(work,env,'ACCOUNT')[0]['state']=='review',
               'First unknown gift did not retain account-wide hold')
        q(work,env,"INSERT INTO server_info (id,title,ucount,remark,flag,active,state) "
          "VALUES(4,'Alias',5,'fixture','','1',1)")
        q(work,env,"UPDATE server_config SET type='marzban',"
          "panel_url='http://127.0.0.1:9' WHERE id=4")
        q(work,env,"INSERT INTO orders_list (id,userid,token,transid,fileid,server_id,"
          "inbound_id,remark,uuid,protocol,expire_date,link,amount,status,date) "
          "VALUES(92,1001,REPEAT('a',30),'fixture',7,4,0,'fixture-user',"
          "'alias-uuid','vless',1800000100,'[]',100,1,'fixture')")
        call(work,env,stage,kind='callback',actor=9002,
             update_id=600,message_id=60,text='giftToUser4')
        call(work,env,stage,kind='message',actor=9002,
             update_id=601,message_id=61,text='fixture-user')
        call(work,env,stage,kind='message',actor=9002,
             update_id=602,message_id=62,text='2')
        held=call(work,env,stage,kind='message',actor=9002,
                  update_id=603,message_id=63,text='1024')
        ensure(len(effects(stage))==1 and
               len(rows(work,env,'ACCOUNT'))==1 and
               len(rows(work,env,'SOURCE'))==1 and
               q(work,env,'SELECT step FROM users WHERE userid=9002')=='none' and
               any('انتظار' in x for x in held['events']),
               'Same physical account through second logical server bypassed hold')
        passed.append('physical_account_alias_cross_order_cross_admin_held')

        reset(work,env);stage.joinpath('effects.jsonl').unlink(missing_ok=True)
        prepare_final(work,env,stage)
        prepare_second_alias(work,env,stage)
        race_claim_before_owner_release(work,env,stage)
        passed.append('repeatable_read_two_admins_two_orders_lockwait_before_claim_commit')

        # Verify the same owner-lock invariant under MariaDB READ COMMITTED;
        # restore server isolation afterwards, never change any tenant setting.
        q(work,env,'SET GLOBAL TRANSACTION ISOLATION LEVEL READ COMMITTED')
        try:
            reset(work,env);stage.joinpath('effects.jsonl').unlink(missing_ok=True)
            prepare_final(work,env,stage)
            prepare_second_alias(work,env,stage)
            race_claim_before_owner_release(work,env,stage,'READ-COMMITTED')
            passed.append('read_committed_two_admins_alias_lockwait_safe')
        finally:
            q(work,env,'SET GLOBAL TRANSACTION ISOLATION LEVEL REPEATABLE READ')

        # An installed optional panel-group column must hold aliases with
        # distinct URLs. Add/drop ONLY on our disposable fixture.
        reset(work,env);stage.joinpath('effects.jsonl').unlink(missing_ok=True)
        prepare_final(work,env,stage)
        prepare_second_alias(work,env,stage)
        q(work,env,'ALTER TABLE server_info ADD COLUMN panel_group_id INT NULL')
        try:
            q(work,env,"INSERT INTO setting (type,value) "
              "VALUES ('ACHBOT_MARZBAN_PANEL_GROUP','{}')")
            groupid=q(work,env,"SELECT id FROM setting WHERE type="
                      "'ACHBOT_MARZBAN_PANEL_GROUP' ORDER BY id DESC LIMIT 1")
            ensure(groupid.isdigit(),'Synthetic panel group identity was not persisted')
            q(work,env,'UPDATE server_info SET panel_group_id='+groupid+
                     ' WHERE id IN (2,4)')
            q(work,env,"UPDATE server_config SET panel_url='http://127.0.0.1:10' WHERE id=4")
            volume(work,env,stage,mode='provider_unknown')
            held=call(work,env,stage,kind='message',actor=9002,
                      update_id=603,message_id=63,text='1024')
            ensure(len(effects(stage))==1 and
                   len(rows(work,env,'SOURCE'))==1 and
                   len(rows(work,env,'ACCOUNT'))==1 and
                   any('انتظار' in e for e in held['events']),
                   'Shared group alias with distinct physical URLs bypassed hold')
            passed.append('shared_panel_group_two_urls_cross_admin_hold')
        finally:
            q(work,env,'ALTER TABLE server_info DROP COLUMN panel_group_id')
            q(work,env,"DELETE FROM setting WHERE type='ACHBOT_MARZBAN_PANEL_GROUP'")

        reset(work,env);stage.joinpath('effects.jsonl').unlink(missing_ok=True)
        prepare_final(work,env,stage)
        volume(work,env,stage,mode='provider_unknown')
        call(work,env,stage,kind='message',actor=9001,
             update_id=504,message_id=54,text='1024')
        ensure(len(rows(work,env,'SOURCE'))==1 and
               len(rows(work,env,'ACCOUNT'))==1 and len(effects(stage))==1,
               'Distinct update IDs in same actor generation bypassed permanent hold')
        passed.append('same_actor_distinct_update_ids_no_second_account_claim')

        reset(work,env);stage.joinpath('effects.jsonl').unlink(missing_ok=True)
        prepare_final(work,env,stage,days='0')
        volume(work,env,stage,mode='verified')
        begin(work,env,stage,update_id=600)
        call(work,env,stage,kind='message',actor=9001,
             update_id=601,message_id=61,text='fixture-user')
        call(work,env,stage,kind='message',actor=9001,
             update_id=602,message_id=62,text='0')
        call(work,env,stage,kind='message',actor=9001,
             update_id=603,message_id=63,text='1024')
        ensure(len(rows(work,env,'SOURCE'))==2 and
               len(rows(work,env,'ACCOUNT'))==1 and
               len(effects(stage))==2 and
               rows(work,env,'ACCOUNT')[0]['state']=='completed',
               'Completed hold improperly blocked or duplicated new authorized request')
        passed.append('completed_marker_reuse_only_after_distinct_new_wizard')

        reset(work,env);stage.joinpath('effects.jsonl').unlink(missing_ok=True)
        prepare_final(work,env,stage)
        q(work,env,"CREATE TRIGGER owned_direct_step_abort BEFORE UPDATE ON users "
          "FOR EACH ROW SIGNAL SQLSTATE '45000' "
          "SET MESSAGE_TEXT='owned_direct_step_abort'")
        try:
            refused=volume(work,env,stage)
            ensure(len(effects(stage))==0 and not rows(work,env,'SOURCE') and
                   not rows(work,env,'ACCOUNT') and
                   q(work,env,'SELECT step FROM users WHERE userid=9001')
                      .startswith('achbotDirectGiftVolume:'),
                   'Failed atomic admin-step consumption still dispatched provider')
        finally:
            q(work,env,'DROP TRIGGER owned_direct_step_abort')
        passed.append('source_and_account_hold_rollback_when_step_consume_fails')

        reset(work,env);stage.joinpath('effects.jsonl').unlink(missing_ok=True)
        prepare_final(work,env,stage)
        q(work,env,"CREATE TRIGGER owned_direct_journal_abort BEFORE INSERT ON setting "
          "FOR EACH ROW SIGNAL SQLSTATE '45000' "
          "SET MESSAGE_TEXT='owned_direct_journal_abort'")
        try:
            volume(work,env,stage)
            ensure(not effects(stage) and not rows(work,env,'SOURCE') and
                   not rows(work,env,'ACCOUNT'),
                   'Failed source INSERT dispatched remote or partial journal')
        finally:
            q(work,env,'DROP TRIGGER owned_direct_journal_abort')
        passed.append('journal_sql_insert_failure_prevents_provider_dispatch')

        # Original schema never changed; no tenant DDL was applied.
        number=q(work,env,
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()")
        ensure(number=='23','Source-only gift fixture altered historical table count')
        passed.append('original_23_table_schema_unmodified')
    report={
        'complete':True,'cases':len(passed),'scenarios':passed,
        'real_bot_php_route':True,'real_mysqli_original_schema':True,
        'actual_global_cancel_fallback':True,'actual_config_set_user':True,
        'actual_webhook_pre_config_auth':True,
        'actual_config_moderation':True,
        'actual_manual_account_handler':True,
        'actual_channel_exemption_handler':True,
        'actual_legacy_main_menu':True,
        'shared_real_service_seam_with_synthetic_adapter':True,
        'owned_mariadb_only':True,'real_telegram_or_marzban_calls':0,
        'tenant_migrations':0,'production_operations':0,
    }
    if opts.report: opts.report.write_text(
        json.dumps(report,indent=2,ensure_ascii=False)+'\n')
    print(json.dumps({k:v for k,v in report.items() if k!='scenarios'},
        sort_keys=True))

if __name__=='__main__':
    main()
