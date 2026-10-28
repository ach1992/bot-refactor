#!/usr/bin/env python3
"""AChBot #81 actual PHP/cURL adapter + disposable MariaDB storage regressions.
Only task-owned loopback HTTP, synthetic values and socket-only temporary SQL.
This is deterministic native-client evidence, not a real PasarGuard installation.
"""
from __future__ import annotations
from concurrent.futures import ThreadPoolExecutor
import argparse, base64, contextlib, copy, json, socket, subprocess, tempfile, threading, time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import urlsplit, parse_qs
import test_access_state as owned
import test_v6_native as native
import test_v8_native as purchase

ROOT=Path(__file__).resolve().parents[1]
MARKER='achbot:v1:'+'a'*32
USER=dict(id=17,username='fixture_user',created_at='2026-10-26T05:00:00.123456+00:00',
    note=MARKER,data_limit=2147483648,expire='2033-05-18T03:33:20Z',status='active',
    group_ids=[2,5],used_traffic=10,subscription_url='https://sub.example.invalid/token',
    proxy_settings={'vless':{'id':'fixture-identity'}},data_limit_reset_strategy='no_reset',
    next_plan=None,auto_delete_in_days=None,on_hold_expire_duration=None,on_hold_timeout=None,admin={'private_fixture_field':'MUST_NOT_ESCAPE_NORMALIZATION'})
IDENTITY=dict(remote_user_id=17,remote_created_at=USER['created_at'],operation_marker=MARKER)
REQUEST=dict(username='fixture_user',operation_marker=MARKER,data_limit=2147483648,
    expire=2000000000,group_ids=[5,2,2])

class Handler(BaseHTTPRequestHandler):
    def log_message(self,*args): pass
    def do_GET(self): self.handle_request()
    def do_POST(self): self.handle_request()
    def do_PUT(self): self.handle_request()
    def do_DELETE(self): self.handle_request()
    def handle_request(self):
        n=int(self.headers.get('Content-Length','0'))
        request=json.loads(self.rfile.read(n)) if n else None
        s=self.server
        s.trace.append((self.command,self.path,request))
        assert self.headers.get('X-Api-Key')=='fixture-private-key'
        path=urlsplit(self.path).path
        mode=s.mode
        status=200
        content_type='application/json'
        if path=='/api/system': value={'version':'5.5.1' if mode!='wrong_version' else '5.5.2'}
        elif path=='/api/admin':
            actions={a:{'scope':1} for a in ['read','create','update','delete','reset_usage','revoke_sub']}
            value={'role':{'is_owner':False,'access':{'require_template':mode=='template_only','allowed_group_ids':[2,5]},
                'permissions':{'users':actions,'groups':{'read':True,'read_simple':True}}}}
            if mode=='permission_missing': del value['role']['permissions']['users']['revoke_sub']
        elif path=='/api/groups':
            offset=int(parse_qs(urlsplit(self.path).query)['offset'][0])
            value={'groups':[{'id':i,'name':'fixture_group','is_disabled':False,'inbound_tags':['native_tag']} for i in [2,5]][offset:offset+1], 'total':2}
            if mode=='groups_truncated' and offset: value['groups']=[]
            if mode=='groups_duplicate' and offset: value['groups'][0]['id']=2
            if mode=='groups_null_tags': value['groups'][0]['inbound_tags']=None
        elif '/subscription/' in path:
            s.links_requested=True
            links='vless://native-identity@host.invalid:443?type=grpc&serviceName=literal#Native\nvmess://eyJwcyI6Ik5hdGl2ZSJ9'
            value=base64.b64encode(links.encode()).decode() if path.endswith('links_base64') else links
            content_type='text/plain; charset=utf-8'
            if mode=='links_invalid': value='untrusted raw provider error'
            if mode=='links_base64_invalid': value='***'
        elif self.command=='POST' and path=='/api/user':
            s.user=copy.deepcopy(USER)
            s.user.update(request)
            status=200 if mode=='create_wrong_status' else 201
            value=s.user
            if mode=='create_wrong_entitlement': value=dict(s.user,data_limit=999)
        elif self.command=='PUT':
            s.write_count+=1
            if mode!='modify_lie': s.user.update(request)
            value=s.user
        elif self.command=='DELETE':
            s.write_count+=1
            if mode!='delete_lie': s.user=None
            status=200 if mode=='delete_wrong_status' else 204
            value=None
        elif self.command=='POST':
            s.write_count+=1
            if path.endswith('/reset') and mode!='reset_lie': s.user['used_traffic']=0
            if path.endswith('/revoke_sub'):
                s.user['subscription_url']='https://sub.example.invalid/changed'
                if mode!='revoke_url_only': s.user['proxy_settings']={'vless':{'id':'fixture-new-identity'}}
            value=s.user
        else:
            if s.user is None: status=404;value={'detail':'MUST_NOT_ESCAPE_RAW_RESPONSE'}
            else:
                value=copy.deepcopy(s.user)
                if mode=='foreign_created': value['created_at']='2026-10-25T05:00:00Z'
                if mode=='foreign_note': value['note']='foreign'
                if mode=='foreign_id': value['id']=18
                if mode=='scheduled_reset': value['data_limit_reset_strategy']='month'
                if mode=='scheduled_delete': value['auto_delete_in_days']=0
                if mode=='scheduled_hold': value['on_hold_expire_duration']=86400
                if mode=='native_on_hold': value['status']='on_hold'
                if mode=='wrong_numeric_type': value['data_limit']=2147483648.5
                if mode=='links_identity_race' and s.links_requested: value['created_at']='2026-10-25T05:00:00Z'
        if self.command!='GET' and mode=='write_timeout': time.sleep(11)
        if self.command!='GET' and mode=='write_lost':
            self.connection.shutdown(socket.SHUT_RDWR);self.connection.close();return
        if mode=='relative_subscription': value['subscription_url']='relative_token'
        if mode.startswith('http_'):
            status=int(mode[5:]);value={'detail':'MUST_NOT_ESCAPE_RAW_RESPONSE fixture-private-key'}
        if mode=='too_large': encoded=b'{"padding":"'+b'x'*1048576+b'"}';content_type='application/json'
        elif mode=='malformed': encoded=b'{"unclosed"';content_type='application/json'
        elif mode=='content_type': encoded=b'not json MUST_NOT_ESCAPE_RAW_RESPONSE';content_type='text/html'
        else: encoded=(json.dumps(value).encode() if content_type.startswith('application/json') else value.encode()) if status!=204 else b''
        self.send_response(status);self.send_header('Content-Type',content_type)
        self.send_header('Content-Length',str(len(encoded)));self.end_headers()
        try: self.wfile.write(encoded)
        except (BrokenPipeError,ConnectionResetError): pass

@contextlib.contextmanager
def panel():
    server=ThreadingHTTPServer(('127.0.0.1',0),Handler)
    server.trace=[];server.user=copy.deepcopy(USER);server.mode='ok';server.write_count=0;server.links_requested=False
    thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
    try: yield server,'http://127.0.0.1:'+str(server.server_address[1])
    finally: server.shutdown();thread.join(5);server.server_close()

PHP= r'''<?php
error_reporting(E_ALL);
set_error_handler(static function($n,$m){if($n===E_DEPRECATED)return true;throw new ErrorException('fixture_php_warning',0,$n);});
$_SERVER['SCRIPT_FILENAME']=__FILE__;
define('ACHBOT_PG_TEST_LOOPBACK',true);
require $argv[1].'/src/Panel/PasarGuard.php';
$spec=json_decode($argv[2],true,64,JSON_THROW_ON_ERROR);
$p=['id'=>1,'instance_key'=>str_repeat('b',32),'api_url'=>$spec['url'],'api_key'=>'fixture-private-key','version'=>'5.5.1'];
if(isset($spec['panel']))$p=array_replace($p,$spec['panel']);
$fn=$spec['fn'];$args=$spec['args']??[];
try { echo json_encode($fn($p,...$args),JSON_THROW_ON_ERROR); }
catch(Throwable $e) {echo json_encode(['fixture_exception'=>$e->getMessage()],JSON_THROW_ON_ERROR);}
'''
def php(script,*args):
    p=subprocess.run(['php','-d','display_errors=0','-d','log_errors=0',str(script),*map(str,args)],
        capture_output=True,timeout=18)
    if p.returncode or p.stderr: raise AssertionError('PHP child failed: '+p.stderr.decode()[:1000])
    value=json.loads(p.stdout)
    if 'fixture_exception' in value: raise AssertionError(value)
    return value

def adapter_checks(work):
    script=work/'pg-native.php';script.write_text(PHP)
    cases=[]
    with panel() as (s,url):
        def call(fn,args=[],mode='ok',success=True,uncertain=None,overrides=None):
            s.mode=mode;s.user=copy.deepcopy(USER);s.trace=[];s.write_count=0;s.links_requested=False
            spec=dict(fn=fn,url=url,args=args)
            if overrides:spec['panel']=overrides
            r=php(script,ROOT,json.dumps(spec))
            assert r['success'] is success,(fn,mode,r)
            if uncertain is not None: assert r['uncertain'] is uncertain,(mode,r)
            assert not any(x in json.dumps(r) for x in ['MUST_NOT_ESCAPE','fixture-private-key','private_fixture_field']),(mode,r)
            cases.append(fn+':'+mode)
            return r
        r=call('achbotPgCreate',[REQUEST]);assert r['user']['group_ids']==[2,5] and r['user']['expire']==2000000000
        assert [e[:2] for e in s.trace]==[('POST','/api/user'),('GET','/api/user/by-id/17')]
        for field in ['created_at','note','id']:
            call('achbotPgRead',[IDENTITY],mode='foreign_'+('created' if field=='created_at' else field),success=False)
        for status in ['active','disabled','limited','expired','on_hold']:
            s.user=copy.deepcopy(USER)
            # The actual response normalizer must preserve native status distinctions.
            code="require $argv[1].'/src/Panel/PasarGuard.php';echo json_encode(achbotPgNormalizeUser(json_decode($argv[2],true)));"
            out=subprocess.check_output(['php','-r',code,str(ROOT),json.dumps(dict(USER,status=status))])
            assert json.loads(out)['status']==status;cases.append('native_status:'+status)
        for mode in ['malformed','content_type','wrong_numeric_type','relative_subscription','too_large']:
            call('achbotPgRead',[IDENTITY],mode,False,False)
        for status in [201,204,401,403,404,409,422,429,503]:
            call('achbotPgRead',[IDENTITY],'http_'+str(status),False,False)
        for mode in ['scheduled_reset','scheduled_delete','scheduled_hold','native_on_hold']:
            call('achbotPgModify',[IDENTITY,{'data_limit':4294967296}],mode,False,False)
            assert s.write_count==0
            call('achbotPgReset',[IDENTITY],mode,False,False);assert s.write_count==0
        r=call('achbotPgModify',[IDENTITY,{'data_limit':4294967296,'expire':2100000000}])
        assert r['user']['group_ids']==[2,5] and s.write_count==1
        call('achbotPgModify',[IDENTITY,{'group_ids':[9]}]);assert s.user['group_ids']==[9]
        for mode in ['modify_lie','write_lost','write_timeout']:
            call('achbotPgModify',[IDENTITY,{'data_limit':4294967296}],mode,False,True)
            assert s.write_count==1
        call('achbotPgCreate',[dict(REQUEST,data_limit=0,expire=None)]);assert s.user['data_limit']==0 and s.user['expire'] is None
        call('achbotPgCreate',[REQUEST],'relative_subscription',False,True)
        assert sum(e[0]=='POST' for e in s.trace)==1
        call('achbotPgCreate',[REQUEST],'create_wrong_status',False,True)
        call('achbotPgCreate',[REQUEST],'too_large',False,True)
        call('achbotPgCreate',[REQUEST],'write_lost',False,True)
        assert sum(e[0]=='POST' for e in s.trace)==1
        call('achbotPgCreate',[dict(REQUEST,group_ids=[])],success=False,uncertain=False);assert not s.trace
        call('achbotPgModify',[IDENTITY,{'data_limit':-1}],success=False);assert not s.trace
        call('achbotPgModify',[IDENTITY,{'next_plan':{}}],success=False);assert not s.trace
        call('achbotPgReset',[IDENTITY]);assert s.user['used_traffic']==0
        call('achbotPgReset',[IDENTITY],'reset_lie',False,True)
        call('achbotPgRevoke',[IDENTITY]);assert s.write_count==1
        call('achbotPgRevoke',[IDENTITY],'revoke_url_only',False,True)
        call('achbotPgDelete',[IDENTITY]);assert [e[0] for e in s.trace]==['GET','DELETE','GET']
        call('achbotPgDelete',[IDENTITY],'delete_lie',False,True)
        call('achbotPgDelete',[IDENTITY],'delete_wrong_status',False,True)
        for fmt in ['links','links_base64']:
            r=call('achbotPgLinks',[IDENTITY,fmt]);assert r['links'][0].startswith('vless://native-identity')
        call('achbotPgLinks',[IDENTITY],'links_invalid',False)
        call('achbotPgLinks',[IDENTITY,'links_base64'],'links_base64_invalid',False)
        call('achbotPgLinks',[IDENTITY],'links_identity_race',False)
        call('achbotPgCheck');assert len(s.trace)==4
        for mode in ['wrong_version','template_only','permission_missing','groups_truncated','groups_duplicate']:
            call('achbotPgCheck',[],mode,False)
        call('achbotPgGroups',[],'groups_null_tags')
        call('achbotPgRead',[IDENTITY],success=False,overrides={'version':'5.6.0'});assert not s.trace
        call('achbotPgRead',[IDENTITY],success=False,overrides={'api_url':'http://example.invalid'});assert not s.trace
    return cases

STORAGE=r'''<?php
error_reporting(E_ALL);
set_error_handler(static function($n,$m){if($n===E_DEPRECATED)return true;throw new ErrorException('fixture_php_warning',0,$n);});
$_SERVER['SCRIPT_FILENAME']=__FILE__;
require $argv[1].'/src/Panel/ProviderStorage.php';
$db=new mysqli('localhost','root','',$argv[3],0,$argv[2]);$db->set_charset('utf8mb4');
$spec=json_decode($argv[4],true,64,JSON_THROW_ON_ERROR);
$cases=[];
function ok($condition,$name){if(!$condition)throw new RuntimeException($name);$GLOBALS['cases'][]=$name;}
function denied(callable $fn,$reason){try{$fn();throw new LogicException('expected_rejection');}
 catch(RuntimeException $e){ok($e->getMessage()===$reason,'reject:'.$reason);}}
try {
 if($spec['op']==='missing'){
   denied(fn()=>achbotProviderPanel($db,1),'provider_schema_missing_or_invalid');
 }elseif($spec['op']==='engine'){
   denied(fn()=>achbotProviderRequireSchema($db),'provider_schema_missing_or_invalid');
 }elseif($spec['op']==='reserve_race'){
   if (($spec['snapshot_compat']??false) && $db->query("SHOW VARIABLES LIKE 'innodb_snapshot_isolation'")->num_rows)
       $db->query('SET SESSION innodb_snapshot_isolation=OFF');
   $db->begin_transaction();
   $snap=achbotProviderSnapshot($db,2,0);
   // Both sessions establish a read view before serializing on the effect.
   usleep(250000);
   $r=achbotProviderReserveAccount($db,2,$snap,'fixture_race','achbot:v1:'.str_repeat('d',32),
      ['data_limit'=>2147483648,'expire'=>2000000000,'group_ids'=>$snap['group_ids'],'status'=>'active']);
   $db->commit();
   echo json_encode(['newly_reserved'=>$r['newly_reserved']]);exit;
 }elseif($spec['op']==='all'){
   $snap=achbotProviderSnapshot($db,2,7);
   ok($snap['group_ids']===[2,5],'nullable_plan_inherits_default');
   $db->query("UPDATE server_plans SET provider_group_ids='[9]' WHERE id=7");
   ok(achbotProviderSnapshot($db,2,7)['group_ids']===[9],'explicit_groups_replace_default');
   ok(achbotProviderSnapshot($db,2,0)['group_ids']===[2,5],'trial_uses_server_default');
   $db->query("UPDATE server_info SET provider_group_ids='[8]' WHERE id=2");
   ok($snap['group_ids']===[2,5],'quoted_snapshot_immutable');
   ok(achbotProviderPanelForSnapshot($db,$snap)['id']===1,'quoted_groups_do_not_reinherit');
   $db->query("UPDATE server_config SET panel_url='https://changed.invalid' WHERE id=2");
   denied(fn()=>achbotProviderPanelForSnapshot($db,$snap),'provider_snapshot_target_changed');
   $db->query("UPDATE server_config SET panel_url='https://panel.example.invalid' WHERE id=2");
   $ent=['data_limit'=>2147483648,'expire'=>2000000000,'group_ids'=>[2,5],'status'=>'active'];
   $marker='achbot:v1:'.str_repeat('a',32);
   denied(fn()=>achbotProviderReserveAccount($db,1,$snap,'fixture_user',$marker,$ent),'provider_reservation_requires_transaction');
   $db->begin_transaction();
   $new=achbotProviderReserveAccount($db,1,$snap,'fixture_user',$marker,$ent);
   ok($new['newly_reserved']===true,'new_reservation_only_once');
   $observer=new mysqli('localhost','root','',$argv[3],0,$argv[2]);
   ok((int)$observer->query('SELECT COUNT(*) FROM service_effects WHERE provider_reference_id IS NOT NULL')->fetch_row()[0]===0,'uncommitted_reservation_invisible');
   $db->commit();
   ok((int)$observer->query('SELECT COUNT(*) FROM service_effects WHERE provider_reference_id IS NOT NULL')->fetch_row()[0]===1,'committed_reservation_visible');
   $db->begin_transaction();
   $again=achbotProviderReserveAccount($db,1,$snap,'fixture_user',$marker,$ent);
   ok($again['newly_reserved']===false,'existing_reservation_no_dispatch');
   denied(fn()=>achbotProviderReserveAccount($db,1,$snap,'fixture_user',$marker,array_replace($ent,['data_limit'=>0])),'provider_effect_identity_mismatch');
   denied(fn()=>achbotProviderReserveAccount($db,1,$snap,'wrong_user',$marker,$ent),'provider_effect_identity_mismatch');
   $db->commit();
   $user=$spec['user'];$user['created_at']=achbotPgDate($user['created_at']);$user['expire']=2000000000;
   denied(fn()=>achbotProviderVerifyAccount($db,1,array_replace($user,['note'=>'foreign'])),'provider_account_verification_invalid');
   achbotProviderVerifyAccount($db,1,$user);achbotProviderVerifyAccount($db,1,$user);
   ok(achbotProviderAccountByEffect($db,1)['state']==='verified','verified_identity_persisted');
   denied(fn()=>achbotProviderVerifyAccount($db,1,array_replace($user,['id'=>18])),'provider_account_identity_conflict');
   achbotProviderLinkAccount($db,1,91);achbotProviderLinkAccount($db,1,91);
   ok(achbotProviderAccountByOrder($db,91)['state']==='linked','verified_order_link_idempotent');
   $db->query("UPDATE service_effects SET provider_state='review',provider_mutation_key='fixture:mutation',
       provider_mutation_snapshot=JSON_OBJECT('action','reset') WHERE id=1");
   $db->begin_transaction();$held=achbotProviderReserveAccount($db,1,$snap,'fixture_user',$marker,$ent);$db->commit();
   ok($held['newly_reserved']===false && $held['state']==='review','unknown_account_never_reauthorizes_create');
   ok($held['entitlement_snapshot']===json_encode($ent),'mutation_preserves_purchased_entitlement');
   $db->query("ALTER TABLE service_effects DROP INDEX uq_effect_provider_reference");
   denied(fn()=>achbotProviderPanel($db,1),'provider_unique_index_invalid');
 }
 echo json_encode(['complete'=>true,'cases'=>$cases],JSON_THROW_ON_ERROR);
}catch(Throwable $e){
 if($spec['op']==='reserve_race' && in_array($e->getMessage(),['provider_storage_unavailable','provider_reservation_unconfirmed'],true)) {
   echo json_encode(['reservation_rejected'=>true]);
 }else echo json_encode(['fixture_exception'=>$e->getMessage(),'cases'=>$cases]);
}
'''
def storage_checks(work,env):
    script=work/'pg-storage.php';script.write_text(STORAGE)
    db=purchase.SIDE[1]
    purchase.init(work,env,'https://panel.example.invalid')
    cases=[]
    r=php(script,ROOT,work/'mysql.sock',db,json.dumps({'op':'missing'}));cases+=r['cases']
    for name in ['001_marzban_panel_group_id.sql','007_service_effects.sql','011_provider_identity.sql','012_shared_provider_storage.sql']:
        native.sql(work,env,(ROOT/'migrations/post-v9'/name).read_text(),db)
    snap=dict(provider='pasarguard',panel_id=1,instance_key='b'*32,
        endpoint_sha256=__import__('hashlib').sha256(b'https://panel.example.invalid').hexdigest(),group_ids=[2,5])
    quoted=json.dumps(snap,separators=(',',':'))
    native.sql(work,env,"""
UPDATE server_config SET type='pasarguard',panel_url='https://panel.example.invalid',password='fixture-private-key',
 provider_connection_id=1,provider_instance_key=REPEAT('b',32),provider_version='5.5.1' WHERE id=2;
UPDATE server_info SET provider_group_ids='[5,2,2]' WHERE id=2;
INSERT INTO service_effects (id,source_kind,source_ref,actor_userid,ordinal,operation_id,
 target_server_id,target_key,endpoint_sha256,plan_id,remote_username,requested_expire,
 requested_bytes,request_sha256,provider_snapshot)
 VALUES(1,'trial','fixture-trial',1001,1,REPEAT('a',32),2,'pg:fixture',
 SHA2('https://panel.example.invalid',256),7,'fixture_user',2000000000,2147483648,
 REPEAT('c',64),'"""+quoted+"""');
INSERT INTO orders_list(id,userid,token,transid,fileid,server_id,inbound_id,remark,
 uuid,protocol,expire_date,link,amount,status,date)
 VALUES(91,1001,'fixture-token','',7,2,0,'fixture_user','native-placeholder','vless',2000000000,'[]',100,1,'fixture');
""",db)
    # Deliberately construct incompatible storage in this disposable DB only;
    # the real FK otherwise prevents converting the payment/effect pair.
    native.sql(work,env,'ALTER TABLE service_effects DROP FOREIGN KEY fk_effect_pay',db)
    for table in ['service_effects','users','pays',
                  'orders_list','server_info','server_plans','server_config','setting']:
        before=native.sql(work,env,'SELECT COUNT(*) FROM '+table,db)
        native.sql(work,env,'ALTER TABLE '+table+' ENGINE=Aria TRANSACTIONAL=0',db)
        try:
            r=php(script,ROOT,work/'mysql.sock',db,json.dumps({'op':'engine'}))
            assert r.get('complete') is True and len(r['cases'])==1,r
            assert native.sql(work,env,'SELECT COUNT(*) FROM '+table,db)==before
            cases.append('nontransactional_'+table+'_blocks_before_provider_dispatch')
        finally:
            native.sql(work,env,'ALTER TABLE '+table+' ENGINE=InnoDB',db)
    native.sql(work,env,'ALTER TABLE service_effects ADD CONSTRAINT fk_effect_pay FOREIGN KEY(pay_row_id) REFERENCES pays(id) ON DELETE RESTRICT ON UPDATE RESTRICT',db)
    # Replace the two retired-table engine cases with actual shared-storage
    # shape refusal at the same no-dispatch boundary.
    for broken,restore,label in [
        ('ALTER TABLE orders_list MODIFY provider_remote_created_at VARCHAR(39) COLLATE utf8mb4_bin DEFAULT NULL',
         'ALTER TABLE orders_list MODIFY provider_remote_created_at VARCHAR(40) COLLATE utf8mb4_bin DEFAULT NULL',
         'incompatible_current_native_order_identity_storage_blocks'),
        ('ALTER TABLE server_config MODIFY password VARCHAR(100) COLLATE utf8mb4_bin NOT NULL',
         'ALTER TABLE server_config MODIFY password TEXT COLLATE utf8mb4_bin NOT NULL',
         'incompatible_current_native_key_storage_blocks')]:
        native.sql(work,env,broken,db)
        try:
            r=php(script,ROOT,work/'mysql.sock',db,json.dumps({'op':'engine'}))
            assert r.get('complete') is True and len(r['cases'])==1,r
            cases.append(label)
        finally:native.sql(work,env,restore,db)
    r=php(script,ROOT,work/'mysql.sock',db,json.dumps({'op':'all','user':USER}));cases+=r['cases']
    native.sql(work,env,"ALTER TABLE service_effects ADD UNIQUE KEY uq_effect_provider_reference(provider_reference_id);",db)
    snap['group_ids']=[8]
    race_snapshot=json.dumps(snap,separators=(',',':'))
    native.sql(work,env,"""INSERT INTO service_effects (id,source_kind,source_ref,actor_userid,ordinal,operation_id,
 target_server_id,target_key,endpoint_sha256,plan_id,remote_username,requested_expire,
 requested_bytes,request_sha256,provider_snapshot)
 VALUES(2,'trial','fixture-race',1001,1,REPEAT('d',32),2,'pg:fixture',
 SHA2('https://panel.example.invalid',256),7,'fixture_race',2000000000,2147483648,
 REPEAT('e',64),'"""+race_snapshot+"');",db)
    with ThreadPoolExecutor(max_workers=2) as pool:
        results=list(pool.map(lambda _:php(script,ROOT,work/'mysql.sock',db,json.dumps({'op':'reserve_race'})),range(2)))
    # Modern MariaDB strict snapshot isolation may reject the loser rather than
    # expose a row created after its read view. Both outcomes forbid dispatch.
    assert sum(r.get('newly_reserved') is True for r in results)==1,results
    assert all(r.get('newly_reserved') in [False,True] if 'newly_reserved' in r else r=={'reservation_rejected':True} for r in results),results
    assert native.sql(work,env,'SELECT COUNT(*) FROM service_effects WHERE provider_reference_id IS NOT NULL AND id=2',db).strip()=='1'
    cases.append('concurrent_default_snapshot_single_reservation_safe_loser')
    native.sql(work,env,"UPDATE service_effects SET provider_reference_id=NULL,provider_connection_id=NULL,provider_operation_marker=NULL,provider_entitlement_snapshot=NULL,provider_state=NULL WHERE id=2",db)
    with ThreadPoolExecutor(max_workers=2) as pool:
        results=list(pool.map(lambda _:php(script,ROOT,work/'mysql.sock',db,json.dumps({'op':'reserve_race','snapshot_compat':True})),range(2)))
    assert sorted(r.get('newly_reserved') for r in results)==[False,True],results
    cases.append('concurrent_compat_snapshot_current_read_single_dispatch')
    # RED control: a nonlocking reservation read sees the stale repeatable-read
    # snapshot and attempts an INSERT rejected by the unique effect fence.
    native.sql(work,env,"UPDATE service_effects SET provider_reference_id=NULL,provider_connection_id=NULL,provider_operation_marker=NULL,provider_entitlement_snapshot=NULL,provider_state=NULL WHERE id=2",db)
    negative=work/'negative-source';(negative/'src/Panel').mkdir(parents=True)
    (negative/'src/Panel/PasarGuard.php').write_bytes((ROOT/'src/Panel/PasarGuard.php').read_bytes())
    text=(ROOT/'src/Panel/ProviderStorage.php').read_text()
    old="'SELECT * FROM service_effects WHERE id=? FOR UPDATE','i',[$effectId]"
    assert text.count(old)==1
    (negative/'src/Panel/ProviderStorage.php').write_text(text.replace(old,"'SELECT * FROM service_effects WHERE id=?','i',[$effectId]"))
    with ThreadPoolExecutor(max_workers=2) as pool:
        results=list(pool.map(lambda _:php(script,negative,work/'mysql.sock',db,json.dumps({'op':'reserve_race','snapshot_compat':True})),range(2)))
    assert sum(r.get('newly_reserved') is True for r in results)==1 and any(r.get('reservation_rejected') for r in results),results
    cases.append('red_nonlocking_snapshot_regression_reproduced')
    return cases

def main():
    p=argparse.ArgumentParser();p.add_argument('--run-disposable',action='store_true',required=True)
    p.add_argument('--report',type=Path);a=p.parse_args()
    with tempfile.TemporaryDirectory(prefix='achbot-pg-http-') as tmp:
        cases=adapter_checks(Path(tmp))
    with owned.owned_database() as (work,env): cases+=storage_checks(work,env)
    report=dict(complete=True,cases=len(cases),checks=cases,real_php_curl=True,
        disposable_socket_mariadb=True,real_pasarguard_installation_tested=False)
    if a.report:a.report.write_text(json.dumps(report,indent=2)+'\n')
    print(json.dumps(report))
if __name__=='__main__':main()
