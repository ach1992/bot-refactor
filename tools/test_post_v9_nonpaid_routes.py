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

import test_access_state as owned
import test_v6_native as native
import test_v8_native as purchase
import provider_storage_fixture as storage

ROOT=Path(__file__).resolve().parents[1]
DB=purchase.SIDE[1]
PHP=r'''<?php
error_reporting(E_ALL & ~E_DEPRECATED);
set_error_handler(static function($n,$m){throw new ErrorException($m,0,$n);});
$_SERVER['SCRIPT_FILENAME']=__FILE__;
define('ACHBOT_PG_TEST_LOOPBACK',true);
$root=$argv[1];$spec=json_decode($argv[4],true,512,JSON_THROW_ON_ERROR);
$connection=new mysqli('localhost','root','',$argv[3],0,$argv[2]);
$connection->set_charset('utf8mb4');
$actor=(int)($spec['actor']??1001);
$from_id=$actor;$admin=9001;$chat_id=$actor;$message_id=92;
$data=(string)($spec['data']??'freeTrial8_normal');
$text=(string)($spec['text']??'');
$updateId=(int)($spec['update_id']??987650);
$sender=(object)['id'=>(int)($spec['wire_actor']??$actor),'is_bot'=>false];
$chat=(object)['id'=>(int)($spec['wire_chat']??$actor),
    'type'=>(string)($spec['chat_type']??'private')];
$message=(object)['message_id'=>92,'chat'=>$chat,'from'=>$sender];
$update=(object)['update_id'=>$updateId];
if (($spec['route']??'trial')==='trial') {
    $message->text='Previously delivered bot menu';
    $update->callback_query=(object)['data'=>$data,'from'=>$sender,
        'message'=>$message];
} else {
    $message->text=$text;
    $update->message=$message;
}
$botState=['remark'=>(string)($spec['remark_mode']??'digits'),'rewardChannel'=>'@fixtureReport'];
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
function setUser($value='none',$field='step'){
    global $connection,$from_id;
    if (!in_array($field,['step','temp','freetrial'],true)) throw new RuntimeException('bad_column');
    $stmt=$connection->prepare("UPDATE users SET `$field`=? WHERE userid=?");
    $stmt->bind_param('si',$value,$from_id);
    if (!$stmt->execute()) throw new RuntimeException('step_write_failed');
    $stmt->close();
}
function sendMessage($body=null,$keys=null,$format=null,$to=null){
    $GLOBALS['events'][]=['name'=>'MESSAGE','to'=>$to,'text'=>(string)$body,'format'=>$format];
    return (object)['ok'=>true];
}
function sendQrFromLink(...$args) {
    $GLOBALS['events'][]=['name'=>'DELIVER','uid'=>$args[4]??null];
    if (($GLOBALS['spec']['notice']??'')==='throw')
        throw new RuntimeException('lost_qr_ack');
    if (($GLOBALS['spec']['notice']??'')==='false') return false;
    return true;
}
function getMainKeys(){return '{}';}
function calculateAgentPrice($userInfo,$botState,$planId,$serverId,$buyType,$price){
    return [(int)$price,false,0];
}
function getServerInfoAndConfig($connection,$sid) {
    $stmt=$connection->prepare("SELECT i.remark,c.* FROM server_info i
        JOIN server_config c ON c.id=i.id WHERE i.id=?");
    $stmt->bind_param('i',$sid);$stmt->execute();
    $row=$stmt->get_result()->fetch_assoc();$stmt->close();
    return [['remark'=>$row['remark'],'title'=>'Test server'], $row];
}
function generateRandomString(){return 'fixtureuuid';}
function getNextPortAndIncrease(...$args){throw new RuntimeException('unexpected_native_port_allocation');}
function buildLinksAndSendQr(...$args) {
    if (end($args)!==false)
        throw new RuntimeException('premature_link_delivery');
    $GLOBALS['events'][]=['name'=>'BUILD'];
    return ['token','["vless://fixture"]','uuid','Fixture account','{}','vless://fixture'];
}
function ensureAccountCreationSucceeded($response,$serverInfo,$inbound,$admin,$throw=false) {
    if (!is_object($response) || ($response->success??false)!==true)
        throw new RuntimeException('fixture_remote_unconfirmed');
}
require_once $root.'/src/Purchase/Core.php';
require_once $root.'/src/Purchase/OrderWrites.php';
require_once $root.'/src/Finance/ServiceEffects.php';
if (($spec['route']??'trial')==='trial') {
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
} else {
    require $root.'/src/Purchase/EntryAndBulk.php';
}
'''

def sql(work,env,cmd):
    return native.sql(work,env,cmd,DB).strip()

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
        '-d','extension=curl','-d','allow_url_fopen=0',
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
    with owned.owned_database() as (work,env),provider(work,env) as url:
        purchase.init(work,env,'http://127.0.0.1:9')
        sql(work,env,(ROOT/'migrations/post-v9/001_marzban_panel_group_id.sql').read_text())
        sql(work,env,(ROOT/'migrations/post-v9/007_service_effects.sql').read_text())
        for migration in ('011_provider_identity.sql','012_shared_provider_storage.sql'):
            sql(work,env,(ROOT/'migrations/post-v9'/migration).read_text())
        sql(work,env,'ALTER TABLE service_effects'+(ROOT/'migrations/post-v9/035_service_resolution.sql').read_text().split('ALTER TABLE service_effects',1)[1])
        def configure_pg():
            sql(work,env,storage.connection(2,2,url))
            sql(work,env,"UPDATE server_plans SET provider_group_ids='[1,2]' WHERE server_id=2")
        configure_pg()
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
            sql(work,env,'DELETE FROM service_effects')
            purchase.init(work,env,'http://127.0.0.1:9')
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

        reset()
        sql(work,env,"UPDATE users SET step='createAccAmount7_30_2' WHERE userid=9001")
        bulk=php(work,env,driver,route='bulk',actor=9001,text='2')
        assert len([e for e in bulk if e['name']=='POST'])==2,bulk
        assert len([e for e in bulk if e['name']=='DELIVER'])==2
        assert capacity()==8 and effects('admin_bulk')=='1\tlocal_recorded\t2\n2\tlocal_recorded\t0'
        assert not any(e['name']=='POST' for e in php(work,env,driver,route='bulk',
            actor=9001,text='2',force_cached_step=True))
        reports=[e for e in bulk if e['name']=='MESSAGE' and 'ساخت گروهی اکانت' in e['text']]
        assert {e['to'] for e in reports}>={9001,'@fixtureReport'} and all(e['format']=='HTML' for e in reports)
        assert '2 از 2' in reports[0]['text'] and 'مدیر: 9001' in reports[0]['text'] and 'شناسه عملیات:' in reports[0]['text']
        for index,post in enumerate([e for e in bulk if e['name']=='POST'],1):
            assert (post['remark'] in reports[0]['text'] and
                f'{index}.' in reports[0]['text']),reports
        cases.append('actual_admin_bulk_two_accounts_full_reservation_and_complete_owner_report')

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
        assert {e['to'] for e in partial_reports}>={9001,'@fixtureReport'}
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
        assert {e['to'] for e in delivery}>={9001,'@fixtureReport'} and all('2 از 2' in e['text'] and 'ارسال اطلاعات 2 اکانت' in e['text'] for e in delivery)
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
