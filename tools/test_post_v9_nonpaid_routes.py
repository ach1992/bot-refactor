#!/usr/bin/env python3
"""Real trial and administrator bulk handlers, isolated PHP/MariaDB + fake wire.

Current PasarGuard HTTP adapter, real SQL claims, no real customer or provider.
"""
from __future__ import annotations
import argparse,contextlib,hashlib,json,subprocess,threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import unquote,urlsplit
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path

import database_fixture as owned
import provider_storage_fixture as storage

ROOT=Path(__file__).resolve().parents[1]
DB=owned.NAMES[1]
PHP=r'''<?php
error_reporting(E_ALL & ~E_DEPRECATED);
set_error_handler(static function($n,$m){throw new ErrorException($m,0,$n);});
$_SERVER['SCRIPT_FILENAME']=__FILE__;
define('ACHBOT_PG_TEST_LOOPBACK',true);
$root=$argv[1];$spec=json_decode($argv[4],true,512,JSON_THROW_ON_ERROR);
$connection=new mysqli('localhost','root','',$argv[3],0,$argv[2]);
$connection->set_charset('utf8mb4');
$actor=(int)($spec['actor']??1001);
$from_id=$actor;$admin=9001;$telegramWebhookSecret='FixtureNonpaidReportSecret2026';$chat_id=(int)($spec['wire_chat']??$actor);$message_id=92;$callbackId='fixture-callback';
$data=(string)($spec['data']??'freeTrial8_normal');
$text=(string)($spec['text']??'');
$updateId=(int)($spec['update_id']??987650);
$sender=(object)['id'=>(int)($spec['wire_actor']??$actor),'is_bot'=>false];
$chat=(object)['id'=>(int)($spec['wire_chat']??$actor),
    'type'=>(string)($spec['chat_type']??'private')];
$message=(object)['message_id'=>92,'chat'=>$chat,'from'=>$sender];
$update=(object)['update_id'=>$updateId];
if (in_array(($spec['route']??'trial'),['trial','attention'],true)) {
    $message->text='Previously delivered bot menu';
    $update->callback_query=(object)['data'=>$data,'from'=>$sender,
        'message'=>$message];
} else {
    $message->text=$text;
    $update->message=$message;
}
$botState=['remark'=>(string)($spec['remark_mode']??'digits'),'rewardChannel'=>'@fixtureReport','testAccount'=>'on'];
if (isset($spec['seed'])) mt_srand((int)$spec['seed']);
$buttonValues=['cancel'=>'cancel','start_bot'=>'start','back_to_main'=>'back'];
$mainValues=['sending_config_to_user'=>'Working',
 'send_only_number'=>'Enter integer','can_create_specific_account'=>'Limit AMOUNT',
 'enter_account_amount'=>'Enter amount','out_of_server_capacity'=>'No capacity',
 'out_of_connection_capacity'=>'No inbound capacity'];
$botUrl='https://fixture.invalid/';$botUsername='fixture';
$linkIndices=[];$hideConfigIfMany=false;
$cancelKey='cancel';$first_name='fixture';$username='fixture';$match=[];
$userQ=$connection->prepare('SELECT * FROM users WHERE userid=?');
$userQ->bind_param('i',$actor);$userQ->execute();
$userInfo=$userQ->get_result()->fetch_assoc();$userQ->close();
if (!empty($spec['force_cached_step'])) $userInfo['step']='createAccAmount7_30_2';
$GLOBALS['events']=[];$GLOBALS['spec']=$spec;
register_shutdown_function(static function(){
    echo json_encode(['events'=>$GLOBALS['events'],'fatal'=>error_get_last()],
       JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
});
function alert(...$args){$GLOBALS['events'][]=['name'=>'ALERT','text'=>$args[0]??''];}
function delMessage(...$a){$GLOBALS['events'][]=['name'=>'DELETE_MESSAGE'];}
function answerCallbackQuery(...$args){$GLOBALS['events'][]=['name'=>'ANSWER','args'=>$args];return (object)['ok'=>true];}
function editText($id,$body,$keys=null,$mode=null,$to=null){
    $GLOBALS['events'][]=['name'=>'REVIEW','body'=>$body,'keys'=>json_decode($keys??'null',true),
        'mode'=>$mode,'to'=>$to];
    return (object)['ok'=>true];
}
function setUser($value='none',$field='step'){
    global $connection,$from_id;
    if (!in_array($field,['step','temp','freetrial'],true)) throw new RuntimeException('bad_column');
    $stmt=$connection->prepare("UPDATE users SET `$field`=? WHERE userid=?");
    $stmt->bind_param('si',$value,$from_id);
    if (!$stmt->execute()) throw new RuntimeException('step_write_failed');
    $stmt->close();
}
function fixtureDeliveryTransport($to) {
    global $connection;
    $ctx=$GLOBALS['achbotTelegramDiagnosticContext']??null;
    if (!$ctx || !isset($ctx['delivery_id'])) return null;
    $d=achbotProviderRow($connection,'SELECT * FROM payment_delivery_intents WHERE id=?','i',[$ctx['delivery_id']]);
    $p=achbotProviderRow($connection,'SELECT * FROM payment_delivery_parts WHERE intent_id=? AND part_no=?','ii',[$ctx['delivery_id'],$ctx['delivery_part']]);
    if (!$d || !$p || $d['state']!=='dispatching' || $p['state']!=='dispatching' ||
        (int)$d['user_id']!==(int)$to || (int)achbotProviderRow($connection,'SELECT @@in_transaction AS active')['active']!==0)
        throw new RuntimeException('fixture_delivery_without_committed_claim');
    $GLOBALS['events'][]=['name'=>(int)$p['part_no']===1?'DELIVER':'DELIVER_PART',
        'uid'=>$to,'intent'=>(int)$d['id'],'part'=>(int)$p['part_no']];
    if (($GLOBALS['spec']['notice']??'')==='interrupt') exit(0);
    if (($GLOBALS['spec']['notice']??'')==='throw') throw new RuntimeException('lost_qr_ack');
    if (($GLOBALS['spec']['notice']??'')==='false') return (object)['ok'=>false,'error_code'=>500,'description'=>'uncertain'];
    return (object)['ok'=>true,'result'=>(object)['message_id'=>42]];
}
function sendMessage($body=null,$keys=null,$format=null,$to=null){
    $transport=fixtureDeliveryTransport($to);
    if ($transport!==null) return $transport;
    $GLOBALS['events'][]=['name'=>'MESSAGE','to'=>$to,'text'=>(string)$body,'format'=>$format];
    return (object)['ok'=>true];
}
function sendPhoto($photo,$caption,$keys,$format,$to) {
    if (!($photo instanceof CURLFile) || !is_file($photo->getFilename()))
        throw new RuntimeException('fixture_qr_image_missing');
    return fixtureDeliveryTransport($to);
}
function getMainKeys(){return '{}';}
function getServerInfoAndConfig($connection,$sid) {
    $stmt=$connection->prepare("SELECT i.remark,c.* FROM server_info i
        JOIN server_config c ON c.id=i.id WHERE i.id=?");
    $stmt->bind_param('i',$sid);$stmt->execute();
    $row=$stmt->get_result()->fetch_assoc();$stmt->close();
    return [['remark'=>$row['remark'],'title'=>'Test server'], $row];
}
function generateUID(){return 'fixtureuuid';}
function getNextPortAndIncrease(...$args){throw new RuntimeException('unexpected_native_port_allocation');}
function ensureAccountCreationSucceeded($response,$serverInfo,$inbound,$admin,$throw=false) {
    if (!is_object($response) || ($response->success??false)!==true)
        throw new RuntimeException('fixture_remote_unconfirmed');
}
require_once $root.'/src/Purchase/Core.php';
require_once $root.'/src/Purchase/OrderWrites.php';
require_once $root.'/src/Finance/ServiceEffects.php';
if (($spec['route']??'trial')==='boundary') {
    $kind=$spec['kind']??'free_trial';
    $ref=$kind==='free_trial'?'trial:'.$actor:'bulk:'.$actor.':'.$updateId;
    try {
        achbotCreateNonPaymentAccount($connection,$kind,$ref,$actor,1,1,[
            'is_primary_admin'=>true,'user_id'=>$actor,'server_type'=>'pasarguard',
            'server_id'=>2,'inbound_id'=>0,'plan_id'=>(int)($spec['plan']??8),
            'remark'=>'fixture-denied','volume'=>2,'days'=>30]);
        $GLOBALS['events'][]=['name'=>'ADMITTED'];
    } catch (DomainException $e) {$GLOBALS['events'][]=['name'=>'DENIED','reason'=>$e->getMessage()];}
} elseif (($spec['route']??'trial')==='trial') {
    require $root.'/src/Purchase/TrialProvision.php';
} elseif (($spec['route']??'trial')==='alias_identity') {
    mt_srand(1234);
    $first=buildRemark(['remark'=>'digits'],'mbz','',1001);
    mt_srand(5678);
    $second=buildRemark(['remark'=>'digits'],'mbz','',1001);
    $default=buildRemark(['remark'=>'default'],'someserver','',1001);
    $GLOBALS['events'][]=['name'=>'STYLE_IDENTITY','different_base'=>$first!==$second,
        'admin_default'=>achbotAdminBulkAccountRemark($default,'bulk:9001:987811',1,2,'pasarguard','default'),
        'admin_manual'=>achbotAdminBulkAccountRemark('batch_custom','bulk:9001:987811',1,2,'pasarguard','manual'),
        // Force the same buildRemark() random output for every ordinal.
        // The actual PHP formatter must not inherit those collisions.
        'pg_generated_base'=>'pg-12345',
        'pg_admin_line1'=>achbotAdminBulkAccountRemark('pg-12345','bulk:9001:987811',1,2,'pasarguard','digits'),
        'pg_admin_line2'=>achbotAdminBulkAccountRemark('pg-12345','bulk:9001:987811',2,2,'pasarguard','digits'),
        'pg_admin_replay'=>achbotAdminBulkAccountRemark('pg-99999','bulk:9001:987811',1,2,'pasarguard','digits'),
        'pg_admin_new_source'=>achbotAdminBulkAccountRemark('pg-12345','bulk:9001:987812',1,2,'pasarguard','digits'),
        'pg_paid_line1'=>achbotStyledAccountRemark('pg-12345','pasarguard','checkout:12345',1,2,'digits',false,false),
        'pg_paid_line2'=>achbotStyledAccountRemark('pg-12345','pasarguard','checkout:12345',2,2,'digits',false,false),
        'pg_paid_replay'=>achbotStyledAccountRemark('pg-99999','pasarguard','checkout:12345',1,2,'digits',false,false),
        'pg_paid_new_source'=>achbotStyledAccountRemark('pg-12345','pasarguard','checkout:12346',1,2,'digits',false,false),
        'pg_paid_normal'=>achbotStyledAccountRemark('pg-1001-12345','pasarguard','checkout:12345',1,2,'',false,false),
        'pg_admin_normal'=>achbotAdminBulkAccountRemark('pg-1001-12345','bulk:9001:987811',1,2,'pasarguard',''),
        'pg_paid_single'=>achbotStyledAccountRemark('pg-12345','pasarguard','checkout:12345',1,1,'digits',false,false),
        'pg_paid_explicit_single'=>achbotStyledAccountRemark('chosen_name','pasarguard','checkout:12345',1,1,'manual',true,false),
        'pg_paid_explicit_multi'=>achbotStyledAccountRemark('chosen_name','pasarguard','checkout:12345',2,2,'manual',true,false),
        'pg_admin_manual'=>achbotAdminBulkAccountRemark('batch_custom','bulk:9001:987811',1,2,'pasarguard','manual'),
        'pg_long_prefix'=>achbotAdminBulkAccountRemark(str_repeat('x',96).'-12345','bulk:9001:987811',1,2,'pasarguard','digits'),
        'batch_tokens'=>array_map(static fn($i)=>achbotAccountNumericToken('bulk:9001:987811',$i),range(1,500)),
        'plan_copy'=>achbotMessage('renewal.extensions.message.143c7f81f1'),
        'purchase_report'=>achbotMessages('legacy_messages')['sent_config_to_user'],
        'pasarguard_base'=>$first];
} elseif ($spec['route']==='attention') {
    require $root.'/src/Finance/Routes/AutomaticGatewayReview.php';
    if ($connection->errno) throw new RuntimeException('fixture_attention_sql: '.$connection->error);
} elseif ($spec['route']==='delivery_recovery') {
    require_once $root.'/src/Finance/ServiceDelivery.php';
    achbotDeliveryMaintainPending($connection);
} elseif ($spec['route']==='delivery_redrive') {
    require_once $root.'/src/Finance/ServiceDelivery.php';
    $id=(int)$spec['intent'];$part=(int)$spec['part'];
    $d=achbotProviderRow($connection,'SELECT * FROM payment_delivery_intents WHERE id=?','i',[$id]);
    $p=achbotProviderRow($connection,'SELECT * FROM payment_delivery_parts WHERE intent_id=? AND part_no=?','ii',[$id,$part]);
    $token=achbotDeliveryPartRedriveToken($d,$p,$actor);
    $result=achbotServiceDeliveryDispatch($connection,$id,$actor,$part,$token);
    $GLOBALS['events'][]=['name'=>'REDRIVE','status'=>$result];
} elseif ($spec['route']==='report_recovery') {
    require_once $root.'/src/Finance/OperationalReports.php';
    achbotOperationalReportDispatch($connection,$spec['reference']);
} else {
    require $root.'/src/Purchase/EntryAndBulk.php';
}
'''

def sql(work,env,cmd):
    return subprocess.run(['mariadb','--no-defaults','--protocol=SOCKET',
        '--socket='+str(work/'mysql.sock'),'-uroot','--batch','--skip-column-names',DB],
        input=cmd,env=env,capture_output=True,text=True,check=True,timeout=30).stdout.strip()

def php(work,env,driver,**spec):
    actor=spec.get('actor',1001)
    update=spec.get('update_id',987650)
    route=spec.get('route','trial')
    source=('bulk:'+str(actor)+':'+str(update)) if route=='bulk' else (
        ('trial_admin:'+str(actor)+':'+str(update)) if actor==9001 else 'trial:'+str(actor))
    with PEER.lock:
        start=len(PEER.trace)
        PEER.faults[source]=spec.get('remote','ok')
    p=subprocess.run(['php','-n','-d','extension=mysqlnd','-d','extension=mysqli',
        '-d','extension=curl','-d','extension=gd','-d','allow_url_fopen=0',
        '-d','disable_functions=exec,shell_exec,system,passthru,popen,proc_open,mail',
        str(driver),str(ROOT),str(work/'mysql.sock'),DB,json.dumps(spec)],
        cwd=work,env=env,capture_output=True,text=True,timeout=25)
    if p.returncode:raise RuntimeError('PHP fatal '+repr((p.returncode,p.stderr[-900:],p.stdout[-700:])))
    data=json.loads(p.stdout)
    if data.get('fatal'):raise RuntimeError('Native PHP fatal '+str(data['fatal'])[:400])
    with PEER.lock:
        trace=[event for ref,event in PEER.trace[start:] if ref==source]
    assert not PEER.errors,PEER.errors
    return trace+data['events']

@contextlib.contextmanager
def provider(work,env):
    class Handler(BaseHTTPRequestHandler):
        def log_message(self,*args):pass
        def reply(self,status,data,ctype='application/json'):
            body=json.dumps(data).encode() if ctype=='application/json' else data.encode()
            self.send_response(status);self.send_header('Content-Type',ctype)
            self.send_header('Content-Length',str(len(body)));self.end_headers()
            self.wfile.write(body)
        def do_GET(self):
            try:
                assert self.headers['X-Api-Key']=='fixture-private-key'
                path=urlsplit(self.path).path
                if path=='/api/system': return self.reply(200,{'version':'5.5.1'})
                if path=='/api/admin': return self.reply(200,{'role':{'is_owner':True,'access':{'require_template':False,'allowed_group_ids':[1,2]}}})
                if path=='/api/groups':
                    return self.reply(200,{'total':2,'groups':[{'id':i,'name':'group','is_disabled':False,'inbound_tags':['vless']} for i in (1,2)]})
                with self.server.lock:
                    if '/subscription/' in path:
                        return self.reply(200,'vless://fixture@example.invalid:443#Native','text/plain')
                    if '/by-id/' in path:
                        user=next((u for u in self.server.users.values() if str(u['id'])==path.rsplit('/',1)[1]),None)
                    elif '/by-username/' in path:
                        user=self.server.users.get(unquote(path.rsplit('/',1)[1]))
                    else:raise AssertionError(path)
                    if user:
                        ref=self.server.sources[user['username']]
                        if not any(r==ref and e['name']=='GET' and e['remark']==user['username'] for r,e in self.server.trace):
                            self.server.trace.append((ref,{'name':'GET','remark':user['username']}))
                    return self.reply(200,user) if user else self.reply(404,{})
            except Exception as e:
                self.server.errors.append(repr(e));self.reply(500,{})
        def do_POST(self):
            try:
                assert self.path=='/api/user' and self.headers['X-Api-Key']=='fixture-private-key'
                body=json.loads(self.rfile.read(int(self.headers['Content-Length'])))
                assert body['note'].startswith('achbot:v1:')
                op=body['note'].split(':')[-1]
                assert len(op)==32 and all(c in '0123456789abcdef' for c in op)
                # A second SQL connection sees committed reservation before I/O.
                state=sql(work,env,"SELECT CONCAT(state,':',provider_state),source_ref,ordinal FROM service_effects WHERE operation_id='"+op+"'")
                stage,ref,ordinal=state.split('\t')
                assert stage=='dispatching:dispatching',state
                with self.server.lock:
                    name=body['username']
                    assert name not in self.server.users,'duplicate Native CREATE'
                    self.server.next_id+=1
                    user=dict(body,id=self.server.next_id,created_at='2026-01-01T00:00:00Z',used_traffic=0,
                        subscription_url='https://fixture.invalid/sub/'+str(self.server.next_id),proxy_settings={'vless':{'id':'fixture'}})
                    self.server.users[name]=user;self.server.sources[name]=ref
                    self.server.trace.append((ref,{'name':'POST','remark':name}))
                    fault=self.server.faults.get(ref,'ok')
                if fault=='lost' or (fault=='second_lost' and ordinal=='2'):return self.reply(503,{})
                self.reply(201,user)
            except Exception as e:
                self.server.errors.append(repr(e));self.reply(500,{})
    global PEER
    PEER=ThreadingHTTPServer(('127.0.0.1',0),Handler)
    PEER.lock=threading.Lock();PEER.users={};PEER.sources={};PEER.trace=[];PEER.errors=[];PEER.faults={};PEER.next_id=0
    thread=threading.Thread(target=PEER.serve_forever,daemon=True);thread.start()
    try:yield 'http://127.0.0.1:'+str(PEER.server_port)
    finally:PEER.shutdown();PEER.server_close();thread.join(5)

def check():
    cases=[]
    # Current Native Marzban/PasarGuard creation must never depend on the
    # mutable settings/temp.txt auto-port cursor (root-owned on Hell).
    # The archived utility definition in Core.php is not an active call.
    runtime=(ROOT/'src',ROOT/'pay',ROOT/'settings')
    active=[p for base in runtime for p in base.rglob('*.php')]
    active.extend(ROOT/name for name in ('bot.php','config.php','index.php','health.php'))
    obsolete=[str(p.relative_to(ROOT)) for p in active
              if 'getNextPortAndIncrease(' in p.read_text()]
    assert not obsolete,obsolete
    cases.append('native_create_routes_do_not_use_mutable_temp_port_allocator')
    with owned.owned_database(ROOT/'database/current-schema.sql') as (work,env),provider(work,env) as url:
        def configure_pg():
            sql(work,env,"INSERT INTO server_config(id,panel_url,type,username,password) VALUES (2,"+
                storage.text(url)+",'pasarguard','','fixture-private-key');"
                "INSERT INTO server_info(id,title,ucount,remark,flag,active,state) VALUES(2,'Fixture purchase server',10,'buyfixture','',1,1);"
                "INSERT INTO server_categories(id,title,parent,active) VALUES(1,'Fixture category',0,1);"
                "INSERT INTO server_plans(id,catid,server_id,inbound_id,title,days,volume,price,descr,active,date,provider_group_ids) VALUES "
                "(7,1,2,0,'Fixture',30,2,100,'description',1,'fixture','[1,2]'),"
                "(8,1,2,0,'Free fixture',30,2,0,'fixture-free',1,'fixture','[1,2]');")
            sql(work,env,storage.connection(2,2,url))
            sql(work,env,"UPDATE users SET step='none',freetrial=NULL,temp=NULL,wallet=50000,is_agent=0,discount_percent=NULL; "
                "UPDATE users SET is_agent=1,discount_percent='{\"normal\":10,\"plans\":{},\"servers\":{}}' WHERE userid=9002")
        configure_pg()
        sql(work,env,"INSERT INTO setting(type,value) VALUES ('BOT_STATES','{\"testAccount\":\"on\"}')")
        driver=work/'nonpaid-route.php';driver.write_text(PHP)
        observed=php(work,env,driver,route='alias_identity')
        assert len(observed)==1 and observed[0]['name']=='STYLE_IDENTITY',observed
        evidence=observed[0]
        assert evidence['different_base'],evidence
        assert len(set(evidence['batch_tokens']))==500
        assert all(__import__('re').fullmatch(r'[1-9][0-9]{4}',value)
                   for value in evidence['batch_tokens'])
        assert evidence['plan_copy']==(
            '🎲 لطفاً یکی از پلن‌های زیر را انتخاب کنید.\n'
            '♻️ بعد از تغییر پلن می‌توانید اشتراک خودتان را مطابق پلن جدید تمدید کنید.\n'
            '⚠️ در صورت تغییر پلن پرداخت های در دست بررسی یا نیمه تمام شما برای این اشتراک نامعتبر می شوند.'
        )
        assert evidence['purchase_report']==(
            '✅ اشتراک برای کاربر ارسال شد.\n\n'
            '🎫 شناسه عددی: USERID\n'
            '🔄 نام کاربری اشتراک: REMARK\n'
            '📦 حجم اشتراک: VOLUME گیگ\n'
            '📅 مدت زمان اشتراک: DAYS روز\n'
            '💰 هزینه پرداختی: PRICE تومان\n'
            '🔖 شناسه پرداخت: HASHID\n'
        )
        assert __import__('re').fullmatch(r'someserver-1001-[0-9]{5}',evidence['admin_default'])
        assert evidence['admin_manual'].startswith('batch_custom_') and (
            evidence['admin_manual'].endswith('_1'))
        import re
        assert (evidence['pg_admin_line1']==evidence['pg_admin_replay'] and
                evidence['pg_paid_line1']==evidence['pg_paid_replay'])
        for group in (('pg_admin_line1','pg_admin_line2','pg_admin_new_source'),
                      ('pg_paid_line1','pg_paid_line2','pg_paid_new_source')):
            names=[evidence[key] for key in group]
            assert len(set(names))==3 and all(
                re.fullmatch(r'pg-[0-9]{5}',name) and len(name)<=100
                for name in names),names
        assert (re.fullmatch(r'pg-1001-[0-9]{5}',evidence['pg_paid_normal']) and
                re.fullmatch(r'pg-1001-[0-9]{5}',evidence['pg_admin_normal']))
        assert evidence['pg_paid_single']==evidence['pg_generated_base']
        assert evidence['pg_paid_explicit_single']=='chosen_name'
        assert evidence['pg_paid_explicit_multi'].startswith('chosen_name_')
        assert evidence['pg_admin_manual'].startswith('batch_custom_')
        assert len(evidence['pg_long_prefix'])==100 and re.fullmatch(
            r'x+-[0-9]{5}',evidence['pg_long_prefix'])
        cases.append('configured_single_style_and_source_stable_bulk_decimal_identifiers')
        cases.append('pasarguard_forced_random_collision_source_bound_bulk_and_provider_limits')
        def reset():
            sql(work,env,'DELETE FROM payment_followup_attempts; DELETE FROM payment_followup_intents; DELETE FROM payment_delivery_attempts; DELETE FROM payment_delivery_parts; DELETE FROM payment_delivery_intents; DELETE FROM service_effects')
            sql(work,env,'DELETE FROM orders_list; DELETE FROM server_plans; DELETE FROM server_categories; DELETE FROM server_info; DELETE FROM server_config')
            configure_pg()
            with PEER.lock:PEER.users.clear();PEER.sources.clear();PEER.trace.clear();PEER.faults.clear()
            sql(work,env,'UPDATE users SET freetrial=NULL WHERE userid=1001')
        def capacity():
            return int(sql(work,env,'SELECT ucount FROM server_info WHERE id=2'))
        def effects(kind):
            return sql(work,env,f"SELECT ordinal,state,reserved_count FROM service_effects WHERE source_kind='{kind}' ORDER BY ordinal")
        trial=php(work,env,driver,route='trial')
        assert [e['name'] for e in trial if e['name'] in ('POST','GET','DELIVER')]==['POST','GET','DELIVER'],trial
        assert sql(work,env,'SELECT freetrial FROM users WHERE userid=1001')=='used'
        assert capacity()==9 and effects('free_trial')=='1\tlocal_recorded\t1'
        assert sql(work,env,"SELECT COUNT(*) FROM service_effects e JOIN orders_list o ON o.id=e.order_id WHERE e.source_kind='free_trial' AND e.requested_expire=o.expire_date")=='1'
        cases.append('actual_trial_local_expiry_matches_verified_effect')
        assert not any(e['name']=='POST' for e in php(work,env,driver,route='trial',update_id=987651))
        cases.append('actual_trial_route_reserved_once_with_real_order_and_delivery')

        reset()
        bad=php(work,env,driver,route='trial',remote='lost')
        assert len([e for e in bad if e['name']=='POST'])==1
        assert not any(e['name']=='DELIVER' for e in bad)
        assert capacity()==9 and effects('free_trial')=='1\tdispatching\t1'
        assert sql(work,env,'SELECT freetrial FROM users WHERE userid=1001')=='reserved'
        assert not any(e['name']=='POST' for e in php(work,env,driver,route='trial',update_id=987652))
        cases.append('actual_trial_unknown_remote_retained_fence_and_capacity')

        reset()
        denied=php(work,env,driver,route='trial',wire_actor=9002)
        assert not any(e['name']=='POST' for e in denied)
        assert capacity()==10
        cases.append('forged_trial_update_no_dispatch')
        def no_new_effect(events):
            assert not any(e['name'] in ('POST','ADMITTED','DELIVER') for e in events),events
            assert capacity()==10 and effects('free_trial')=='' and effects('admin_bulk')==''
            for table in ('orders_list','payment_delivery_intents','wallet_entries','pays'):
                assert sql(work,env,'SELECT COUNT(*) FROM '+table)=='0',table
        no_new_effect(php(work,env,driver,route='trial',data='freeTrial7_normal'))
        no_new_effect(php(work,env,driver,route='trial',data='freeTrial7_one'))
        no_new_effect(php(work,env,driver,route='boundary',plan=7))
        cases.append('commercial_positive_price_denied_at_nonpaid_reservation')
        sql(work,env,"UPDATE setting SET value='{\"testAccount\":\"off\"}' WHERE type='BOT_STATES'")
        # Cached request flag remains ON; current persisted reservation denies.
        no_new_effect(php(work,env,driver,route='trial'))
        no_new_effect(php(work,env,driver,route='boundary'))
        sql(work,env,"UPDATE setting SET value='{\"testAccount\":\"on\"}' WHERE type='BOT_STATES'")
        sql(work,env,"UPDATE server_plans SET days=31 WHERE id=8")
        no_new_effect(php(work,env,driver,route='boundary'))
        sql(work,env,"UPDATE server_plans SET days=30 WHERE id=8")
        cases.append('trial_current_policy_and_entitlement_rechecked_before_reservation')
        sql(work,env,"UPDATE users SET freetrial='used' WHERE userid=1001")
        no_new_effect(php(work,env,driver,route='boundary'))
        sql(work,env,"UPDATE users SET freetrial=NULL WHERE userid=1001")
        cases.append('consumed_trial_entitlement_denied_at_reservation')
        sql(work,env,"INSERT INTO setting(type,value) VALUES ('ADMIN_ROLE_9002','support')")
        no_new_effect(php(work,env,driver,route='boundary',kind='admin_bulk',actor=9002,plan=7))
        sql(work,env,"DELETE FROM setting WHERE type='ADMIN_ROLE_9002'; UPDATE users SET isAdmin=0 WHERE userid=9002")
        no_new_effect(php(work,env,driver,route='boundary',kind='admin_bulk',actor=9002,plan=7))
        sql(work,env,"UPDATE users SET isAdmin=1 WHERE userid=9002")
        cases.append('restricted_or_revoked_bulk_staff_denied_at_reservation')


        reset()
        sql(work,env,"UPDATE users SET step='createAccAmount7_30_2' WHERE userid=9001")
        bulk=php(work,env,driver,route='bulk',actor=9001,text='2')
        assert len([e for e in bulk if e['name']=='POST'])==2,bulk
        assert len([e for e in bulk if e['name']=='DELIVER'])==2
        assert capacity()==8 and effects('admin_bulk')=='1\tlocal_recorded\t2\n2\tlocal_recorded\t0'
        assert sql(work,env,"SELECT COUNT(*) FROM service_effects e JOIN orders_list o ON o.id=e.order_id WHERE e.source_kind='admin_bulk' AND e.requested_expire=o.expire_date")=='2'
        cases.append('actual_bulk_local_expiry_matches_each_verified_effect')
        assert not any(e['name']=='POST' for e in php(work,env,driver,route='bulk',
            actor=9001,text='2',force_cached_step=True))
        reports=[e for e in bulk if e['name']=='MESSAGE' and 'ساخت گروهی اکانت' in e['text']]
        assert {e['to'] for e in reports}>={9001,'@fixturereport'} and all(e['format']=='HTML' for e in reports)
        assert '2 از 2' in reports[0]['text'] and 'مدیر: 9001' in reports[0]['text'] and 'شناسه عملیات:' in reports[0]['text']
        for index,post in enumerate([e for e in bulk if e['name']=='POST'],1):
            assert (post['remark'] in reports[0]['text'] and
                f'{index}.' in reports[0]['text']),reports
        cases.append('actual_admin_bulk_two_accounts_full_reservation_and_complete_owner_report')

        reset()
        sql(work,env,"UPDATE users SET step='createAccAmount7_30_2' WHERE userid=9001")
        interrupted=php(work,env,driver,route='bulk',actor=9001,text='2',notice='interrupt')
        names=[e['remark'] for e in interrupted if e['name']=='POST']
        assert len(names)==2 and capacity()==8
        assert len([e for e in interrupted if e['name']=='DELIVER'])==1
        assert effects('admin_bulk')=='1\tlocal_recorded\t2\n2\tlocal_recorded\t0'
        ref='bulk:9001:987650'
        assert sql(work,env,f"SELECT COUNT(*) FROM payment_followup_intents WHERE operational_ref='{ref}' AND state='pending' AND attempt_count=0")=='4'
        native_count=len(PEER.trace)
        recovered=php(work,env,driver,route='report_recovery',actor=9001,reference=ref)
        for target in (9001,'@fixturereport'):
            packets=[e['text'] for e in recovered if e['name']=='MESSAGE' and e['to']==target]
            assert len(packets)==2 and all(sum(name in packet for packet in packets)==1 for name in names),recovered
        assert sql(work,env,f"SELECT COUNT(*) FROM payment_followup_intents WHERE operational_ref='{ref}' AND state='acknowledged'")=='4'
        assert not php(work,env,driver,route='report_recovery',actor=9001,reference=ref)
        assert len(PEER.trace)==native_count and capacity()==8
        assert sql(work,env,"SELECT state FROM payment_delivery_intents ORDER BY ordinal")=='dispatching\npending'
        sql(work,env,"UPDATE payment_delivery_intents SET updated_at=DATE_SUB(NOW(),INTERVAL 3 MINUTE)")
        recovery=php(work,env,driver,route='delivery_recovery',actor=9001)
        assert len([e for e in recovery if e['name']=='DELIVER'])==1,recovery
        assert sql(work,env,"SELECT state FROM payment_delivery_intents ORDER BY ordinal")=='unknown\nacknowledged'
        unknown=sql(work,env,"SELECT d.id,p.part_no FROM payment_delivery_intents d JOIN payment_delivery_parts p ON p.intent_id=d.id WHERE p.state='unknown'").split()
        # Exercise the real staff inbox and actor-bound confirmation, not
        # only a direct call into the recovery engine.
        callback='attentionDeliveryC_'+unknown[0]+'_'+unknown[1]
        def attention(data,actor=9001,**kw):
            return php(work,env,driver,route='attention',actor=actor,data=data,**kw)
        def review_keys(events):
            edits=[e for e in events if e['name']=='REVIEW']
            assert len(edits)==1,events
            assert edits[0]['mode']=='HTML' and edits[0]['to']==9001,edits
            return [button['callback_data'] for row in edits[0]['keys']['inline_keyboard'] for button in row]
        before=sql(work,env,"SELECT COUNT(*) FROM payment_delivery_attempts")
        inbox=attention('attentionInbox')
        assert callback in review_keys(inbox),inbox
        for forbidden in (attention(callback,actor=1001),attention(callback,wire_chat=-100123,chat_type='supergroup')):
            assert not any(e['name'] in ('REVIEW','DELIVER','DELIVER_PART') for e in forbidden),forbidden
        confirmation=attention(callback)
        assert ref in next(e['body'] for e in confirmation if e['name']=='REVIEW'),confirmation
        keys=review_keys(confirmation)
        confirm=[key for key in keys if key.startswith('attentionDeliveryS_')]
        assert len(confirm)==1 and 'attentionInbox' in keys,keys
        confirm=confirm[0]
        tampered=confirm[:-1]+('a' if confirm[-1]!='a' else 'b')
        for forbidden in (attention(tampered),attention(confirm,actor=1001)):
            assert not any(e['name'] in ('DELIVER','DELIVER_PART') for e in forbidden),forbidden
        assert sql(work,env,"SELECT COUNT(*) FROM payment_delivery_attempts")==before
        redrive=attention(confirm)
        assert len([e for e in redrive if e['name'] in ('DELIVER','DELIVER_PART')])==1,redrive
        assert int(sql(work,env,"SELECT COUNT(*) FROM payment_delivery_attempts"))==int(before)+1
        assert not any(e['name'] in ('DELIVER','DELIVER_PART') for e in attention(confirm))
        assert callback not in review_keys(attention('attentionInbox'))
        assert int(sql(work,env,"SELECT COUNT(*) FROM payment_delivery_attempts"))==int(before)+1
        cases.append('actual_nonpaid_attention_inbox_actor_bound_redrive_and_replay_denial')
        sql(work,env,"UPDATE payment_delivery_intents SET updated_at=DATE_SUB(NOW(),INTERVAL 3 MINUTE)")
        php(work,env,driver,route='delivery_recovery',actor=9001)
        assert sql(work,env,"SELECT COUNT(*) FROM payment_delivery_intents WHERE state='acknowledged'")=='2'
        assert len(PEER.trace)==native_count and capacity()==8
        cases.append('actual_interrupted_bulk_shared_part_recovery_and_explicit_redrive_no_native_replay')
        cases.append('actual_admin_bulk_process_exit_recovers_every_original_report_without_native_replay')

        reset()
        sql(work,env,"UPDATE users SET step='createAccAmount7_30_2' WHERE userid=9001")
        partial=php(work,env,driver,route='bulk',actor=9001,text='2',remote='second_lost')
        assert len([e for e in partial if e['name']=='POST'])==2
        assert not any(e['name']=='DELIVER' for e in partial)
        assert capacity()==8 and effects('admin_bulk')=='1\tlocal_recorded\t2\n2\tdispatching\t0'
        assert not any(e['name']=='POST' for e in php(work,env,driver,route='bulk',
            actor=9001,text='2',force_cached_step=True))
        partial_reports=[e for e in partial if e['name']=='MESSAGE'
                         and 'ساخت گروهی اکانت' in e.get('text','')]
        assert {e['to'] for e in partial_reports}>={9001,'@fixturereport'}
        assert all('1 از 2' in e['text'] and '2 از 2' not in e['text']
                   and 'شناسه عملیات:' in e['text'] for e in partial_reports),partial
        assert any(e['remark'] in partial_reports[0]['text'] for e in partial
                   if e['name']=='POST'),partial
        cases.append('actual_admin_bulk_partial_retains_capacity_and_audit')

        reset()
        sql(work,env,"UPDATE users SET step='createAccAmount7_30_2' WHERE userid=9001")
        qr_unknown=php(work,env,driver,route='bulk',actor=9001,text='2',notice='false')
        assert len([e for e in qr_unknown if e['name']=='POST'])==2
        delivery=[e for e in qr_unknown if e['name']=='MESSAGE' and 'ساخت گروهی اکانت' in e['text']]
        assert {e['to'] for e in delivery}>={9001,'@fixturereport'} and all('2 از 2' in e['text'] and 'ارسال اطلاعات 2 اکانت' in e['text'] for e in delivery)
        assert capacity()==8
        cases.append('admin_bulk_delivery_uncertain_reports_verified_creates_and_missing_telegram_ack')

        reset()
        sql(work,env,"UPDATE users SET step='createAccAmount7_30_2' WHERE userid=9001")
        sql(work,env,"UPDATE users SET isAdmin=1,step='createAccAmount7_30_2' WHERE userid=9002")
        sql(work,env,'UPDATE server_info SET ucount=1 WHERE id=2')
        with ThreadPoolExecutor(max_workers=2) as ex:
            pair=list(ex.map(lambda actor:php(work,env,driver,route='bulk',
                actor=actor,text='1',update_id=987650+actor),[9001,9002]))
        assert sum(sum(e['name']=='POST' for e in x) for x in pair)==1,pair
        assert capacity()==0
        cases.append('two_real_admin_batches_race_one_available_server_slot')

        reset()
        sql(work,env,"UPDATE server_plans SET descr='batch_custom' WHERE id=7")
        manual_names=[]
        for n in (1,2):
            firstnames=[]
            for update_id in (987701+n*10,987702+n*10):
                sql(work,env,"UPDATE users SET step='createAccAmount7_30_2' WHERE userid=9001")
                created=php(work,env,driver,route='bulk',actor=9001,
                    text=str(n),remark_mode='manual',update_id=update_id)
                new_names=[e['remark'] for e in created if e['name']=='POST']
                assert len(new_names)==n,(n,update_id,created)
                assert all(
                    name.startswith('batch_custom_') and
                    name.endswith('_'+str(i+1)) and len(name)<=100
                    for i,name in enumerate(new_names)),new_names
                assert not set(new_names).intersection(manual_names)
                assert not set(new_names).intersection(firstnames)
                firstnames.extend(new_names)
                manual_names.extend(new_names)
                replay=php(work,env,driver,route='bulk',actor=9001,
                    text=str(n),remark_mode='manual',update_id=update_id,
                    force_cached_step=True)
                assert not [e for e in replay if e['name']=='POST'],replay
        assert capacity()==4 and len(manual_names)==6
        assert sql(work,env,"SELECT COUNT(DISTINCT remote_username) FROM "
                "service_effects WHERE source_kind='admin_bulk'")=='6'
        cases.append('real_manual_remark_two_independent_single_and_multi_batches_unique_names_no_replay')

        # The administrator form has no manual per-account username input.
        # Empty plan description must still use the same standard name shape.
        reset()
        sql(work,env,"UPDATE server_plans SET descr='' WHERE id=7")
        sql(work,env,"UPDATE users SET step='createAccAmount7_30_2' WHERE userid=9001")
        fallback=php(work,env,driver,route='bulk',actor=9001,text='2',
                     remark_mode='manual',update_id=987814)
        fallback_names=[e['remark'] for e in fallback if e['name']=='POST']
        assert len(fallback_names)==2 and all(
            __import__('re').fullmatch(r'[A-Za-z0-9_-]+-9001-[0-9]{5}',name)
            for name in fallback_names),fallback
        assert len(set(fallback_names))==2
        assert capacity()==8
        cases.append('admin_bulk_empty_manual_description_uses_normal_single_account_shape')

        reset()
        seen={}
        for update in range(990000,1080001):
            token=int(hashlib.sha256(f'bulk:9001:{update}'.encode()).hexdigest()[:11],16)%90000
            if token in seen:
                pair=(seen[token],update)
                break
            seen[token]=update
        else:raise AssertionError('fixture collision not found')
        for remark_mode in ('digits','manual'):
            reset()
            sql(work,env,"UPDATE server_plans SET descr='collision_manual' WHERE id=7")
            names=[]
            for update in pair:
                sql(work,env,"UPDATE users SET step='createAccAmount7_30_2' WHERE userid=9001")
                events=php(work,env,driver,route='bulk',actor=9001,text='1',remark_mode=remark_mode,update_id=update)
                posts=[e['remark'] for e in events if e['name']=='POST']
                assert len(posts)==1 and len([e for e in events if e['name']=='DELIVER'])==1,events
                names.extend(posts)
            assert len(set(names))==2 and capacity()==8,names
            assert all(re.fullmatch(r'buyfixture-[1-9][0-9]{4}' if remark_mode=='digits' else r'collision_manual_[1-9][0-9]{4}_1',name) for name in names),names
            assert sql(work,env,"SELECT COUNT(*) FROM service_effects WHERE source_kind='admin_bulk' AND state='local_recorded'")=='2'
        cases.append('actual_nonpaid_bulk_physical_name_collision_resolved_before_dispatch')

        reset()
        names=[]
        for update in (990001,990002):
            events=php(work,env,driver,route='trial',actor=9001,update_id=update,seed=20261125)
            posts=[e['remark'] for e in events if e['name']=='POST']
            assert len(posts)==1 and len([e for e in events if e['name']=='DELIVER'])==1,events
            names.extend(posts)
        assert len(set(names))==2 and capacity()==8 and all(re.fullmatch(r'buyfixture-[1-9][0-9]{4}',v) for v in names),names
        cases.append('actual_trial_generated_name_collision_resolved_before_dispatch')

        reset()
        # Preserve the current provider limit while keeping ordinal uniqueness.
        very_long='prefix_'+'x'*93
        sql(work,env,"UPDATE server_plans SET descr='"+very_long+"' WHERE id=7")
        sql(work,env,"UPDATE users SET step='createAccAmount7_30_2' WHERE userid=9001")
        compact=php(work,env,driver,route='bulk',actor=9001,text='2',
                    remark_mode='manual',update_id=987811)
        compact_posts=[e for e in compact if e['name']=='POST']
        assert len(compact_posts)==2 and len([e for e in compact if e['name']=='DELIVER'])==2,compact
        compact_names=[e['remark'] for e in compact_posts]
        assert (len(set(compact_names))==2 and all(
            name.startswith('prefix_') and len(name)==100
            and name.endswith('_'+str(i+1))
            for i,name in enumerate(compact_names))),compact_names
        assert capacity()==8 and effects('admin_bulk')=='1\tlocal_recorded\t2\n2\tlocal_recorded\t0'
        assert not [e for e in php(work,env,driver,route='bulk',actor=9001,
            text='2',remark_mode='manual',update_id=987811,
            force_cached_step=True) if e['name']=='POST']
        compact_reports=[e['text'] for e in compact if e['name']=='MESSAGE'
            and 'ساخت گروهی اکانت' in e['text']]
        assert compact_reports and all(name in compact_reports[0] for name in compact_names)
        cases.append('admin_bulk_long_base_compact_pasarguard_username_100_no_remote_replay')

        reset()
        sql(work,env,"UPDATE server_plans SET descr='name with spaces' WHERE id=7")
        sql(work,env,"UPDATE users SET step='createAccAmount7_30_2' WHERE userid=9001")
        bad_manual=php(work,env,driver,route='bulk',actor=9001,
            text='1',remark_mode='manual',update_id=987799)
        assert not [e for e in bad_manual if e['name']=='POST'],bad_manual
        assert capacity()==10 and effects('admin_bulk')==''
        cases.append('invalid_manual_remark_rejected_without_remote_or_capacity_effect')

        reset()
        sql(work,env,"UPDATE users SET step='createAccAmount7_30_2' WHERE userid=9001")
        sql(work,env,"CREATE TRIGGER fixture_effect_insert_block BEFORE INSERT ON service_effects "
            "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='disposable_failure'")
        try: blocked=php(work,env,driver,route='bulk',actor=9001,text='1')
        finally: sql(work,env,'DROP TRIGGER fixture_effect_insert_block')
        assert not any(e['name']=='POST' for e in blocked)
        assert capacity()==10 and effects('admin_bulk')==''
        cases.append('nonpaid_source_db_insert_failure_before_post_no_capacity_loss')

        reset()
        delivered=php(work,env,driver,route='trial',notice='false')
        assert len([e for e in delivered if e['name']=='POST'])==1
        assert sql(work,env,'SELECT freetrial FROM users WHERE userid=1001')=='used'
        assert effects('free_trial')=='1\tlocal_recorded\t1'
        assert not any(e['name']=='POST' for e in php(work,env,driver,route='trial'))
        unknown=sql(work,env,"SELECT d.id,p.part_no FROM payment_delivery_intents d JOIN payment_delivery_parts p ON p.intent_id=d.id WHERE p.state='unknown' ORDER BY p.part_no LIMIT 1").split()
        assert unknown,delivered
        native_count=len(PEER.trace)
        sql(work,env,"UPDATE orders_list SET userid=9002")
        stale=php(work,env,driver,route='delivery_redrive',actor=9001,intent=int(unknown[0]),part=int(unknown[1]))
        assert not any(e['name'] in ('DELIVER','DELIVER_PART') for e in stale),stale
        assert len(PEER.trace)==native_count and capacity()==9
        cases.append('nonpaid_redrive_never_releases_credentials_to_changed_owner')
        cases.append('trial_postcommit_telegram_failure_no_recreate')

    return dict(complete=True,cases=len(cases),scenarios=cases,source_real_php=True,
        socket_only=True,production_mutations=0,actual_native_http=True,external_requests=0)


# Current domain fixture imports the real event-aware notification router.
import notification_fixture
PHP=notification_fixture.with_router(PHP)

if __name__=='__main__':
    p=argparse.ArgumentParser()
    p.add_argument('--run-disposable',action='store_true',required=True)
    p.add_argument('--report',type=Path)
    a=p.parse_args()
    r=check()
    if a.report:a.report.write_text(json.dumps(r,indent=2)+'\n')
    print(json.dumps(r))
