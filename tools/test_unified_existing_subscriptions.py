#!/usr/bin/env python3
"""Actual existing-subscription preview/adoption and native management acceptance.

Native wire fixtures follow official PasarGuard/panel 5.5.1 commit
eba70d68eaa5a89e34ffebb47c8fd5ce054d0e43: user.py d46b038..., jwt.py
dfb8b70..., operation/user.py fbdb410..., operation/__init__.py 813cb4d....
No real tenant, credentials, Telegram or non-loopback network is used.
"""
from __future__ import annotations
import argparse,base64,contextlib,copy,hashlib,hmac,json,re,shutil,socket,subprocess,threading,time,urllib.error,urllib.request
from concurrent.futures import ThreadPoolExecutor
from datetime import datetime,timezone
from http.server import BaseHTTPRequestHandler,ThreadingHTTPServer
from pathlib import Path
from urllib.parse import unquote,urlsplit
import test_access_state as owned
import test_v6_native as native
import test_v8_native as purchase
import test_v6_panels as bodies
import test_post_v9_pasarguard_client as pg
import test_post_v9_marzban_edits as mb
import package_post_v9 as package

ROOT=Path(__file__).resolve().parents[1]
DB=purchase.SIDE[1]
PIN='eba70d68eaa5a89e34ffebb47c8fd5ce054d0e43'
SECRET=b'owned-native-subscription-signing-secret'
GIB=1073741824

def b64(value:bytes)->str:return base64.urlsafe_b64encode(value).decode().rstrip('=')
def mint(uid:int,issued:int)->str:
 payload=b64(f'v3,{uid},{issued}'.encode())
 return payload+'.'+b64(hmac.new(SECRET,payload.encode(),hashlib.sha256).digest())
def mb_mint(username:str,issued:int)->str:
 payload=b64(f'{username},{issued}'.encode())
 return payload+b64(hashlib.sha256(payload.encode()+SECRET).digest())[:10]
def legacy(username:str,issued:int,padded=False)->str:
 payload=base64.urlsafe_b64encode(f'{username},{issued}'.encode()).decode()
 if not padded:payload=payload.rstrip('=')
 return payload+hashlib.sha256(payload.encode()+SECRET).hexdigest()[:10]
def jwt_token(username:str,issued:int)->str:
 header=b64(b'{"alg":"HS256","typ":"JWT"}')
 payload=b64(json.dumps(dict(sub=username,access='subscription',iat=issued),separators=(',',':')).encode())
 return header+'.'+payload+'.'+b64(hmac.new(SECRET,(header+'.'+payload).encode(),hashlib.sha256).digest())
def parse_token(token:str):
 try:
  if len(token.split('.'))==3:
   header,payload,signature=token.split('.')
   if not hmac.compare_digest(signature,b64(hmac.new(SECRET,(header+'.'+payload).encode(),hashlib.sha256).digest())):return None
   claims=json.loads(base64.urlsafe_b64decode(payload+'='*((-len(payload))%4)))
   if claims.get('access')!='subscription':return None
   return None,claims['sub'],int(claims['iat'])
  if '.'in token:
   payload,sig=token.rsplit('.',1)
   if not hmac.compare_digest(sig,b64(hmac.new(SECRET,payload.encode(),hashlib.sha256).digest())):return None
  else:
   payload,sig=token[:-10],token[-10:]
   expected=hashlib.sha256(payload.encode()+SECRET).hexdigest()[:10]
   native=b64(hashlib.sha256(payload.encode()+SECRET).digest())[:10]
   if not(hmac.compare_digest(sig,expected)or hmac.compare_digest(sig,native)):return None
  text=base64.urlsafe_b64decode(payload+'='*((-len(payload))%4)).decode()
  values=text.split(',')
  if len(values)==3 and values[0]in('v2','v3'):return int(values[1]),None,int(values[2])
  if len(values)==2:return None,values[0],int(values[1])
 except Exception:pass
 return None

class ExistingHandler(BaseHTTPRequestHandler):
 def log_message(self,*args):pass
 def do_GET(self):self.handle_request()
 def do_POST(self):self.handle_request()
 def do_PUT(self):self.handle_request()
 def do_DELETE(self):self.handle_request()
 def reply(self,status,value=None,content_type='application/json'):
  raw=b''if status==204 else(json.dumps(value).encode()if content_type=='application/json'else value.encode())
  self.send_response(status);self.send_header('Content-Type',content_type)
  self.send_header('Content-Length',str(len(raw)));self.end_headers();self.wfile.write(raw)
 def handle_request(self):
  s=self.server;path=unquote(urlsplit(self.path).path);raw=self.rfile.read(int(self.headers.get('Content-Length','0')))
  payload=json.loads(raw)if raw else None
  s.trace.append((self.command,path,payload,dict(self.headers)))
  if s.trap:self.reply(403,{});return
  if path.startswith('/native-subscriptions/')and path.endswith('/info'):
   s.public_credentials.append((self.headers.get('X-Api-Key'),self.headers.get('Authorization')))
   token=path[len('/native-subscriptions/'):-len('/info')];parsed=parse_token(token)
   user=None
   if parsed:
    uid,name,issued=parsed
    user=s.users.get(uid)if uid else next((u for u in s.users.values()if u['username']==name),None)
    if user:
     created=int(datetime.fromisoformat(user['created_at'].replace('Z','+00:00')).timestamp())
     if issued<created or issued<s.revoked.get(user['id'],0):user=None
   if not user:self.reply(404,{'detail':'not found'});return
   value={key:copy.deepcopy(value)for key,value in user.items()if key not in(
       'note','proxy_settings','subscription_url','admin','auto_delete_in_days')}
   if s.info_mode=='wrong_id':value['id']+=1000
   if s.info_mode=='wrong_name':value['username']='another_user'
   if s.info_mode=='wrong_created':value['created_at']='2026-10-25T05:00:00Z'
   self.reply(200,value);return
  if self.headers.get('X-Api-Key')!='fixture-private-key':
   s.violations.append('missing_api_key');self.reply(403,{});return
  if path in('/api/users','/api/users/simple')or(self.command=='POST'and path=='/api/user'):
   s.violations.append('list_or_create');self.reply(409,{});return
  if path.startswith('/api/user/by-id/'):
   tail=path[len('/api/user/by-id/'):].split('/');uid=int(tail[0])if tail[0].isdigit()else 0
  elif path.startswith('/api/user/by-username/'):
   name=path[len('/api/user/by-username/'):].split('/')[0]
   uid=next((key for key,u in s.users.items()if u['username']==name),0);tail=[str(uid)]
  elif path.startswith('/api/user/')and '/subscription/'in path:
   tail=path[len('/api/user/'):].split('/');uid=int(tail[0])if tail[0].isdigit()else 0
  else:s.violations.append('unexpected_route');self.reply(404,{});return
  user=s.users.get(uid)
  if not user:self.reply(404,{});return
  if self.command=='GET'and len(tail)==1 and s.on_read:
   hook=s.on_read;s.on_read=None;hook()
  if self.command=='DELETE':
   del s.users[uid];self.reply(204);return
  if self.command=='PUT':
   if set(payload)-{'data_limit','expire','status','group_ids'}:
    s.violations.append('unexpected_modify_field');self.reply(400,{});return
   user.update(payload)
  elif self.command=='POST':
   if path.endswith('/reset'):user['used_traffic']=0
   elif path.endswith('/revoke_sub'):
    user['proxy_settings']={'vless':{'id':'revoked-owned-proxy'}}
    s.link_labels[uid]='revoked-owned-proxy'
    s.revoked[uid]=int(time.time())+1
   else:s.violations.append('unexpected_post');self.reply(400,{});return
  if '/subscription/'in path:
   self.reply(200,f'vless://{s.link_labels.get(uid,"existing-native-"+str(uid))}@host.invalid:443?type=grpc#Owned','text/plain');return
  value=copy.deepcopy(user);s.sequence+=1
  value['subscription_url']=s.url+'/native-subscriptions/'+mint(uid,int(time.time())+s.sequence)
  s.returned_tokens.append(value['subscription_url'].rsplit('/',1)[1])
  self.reply(200,value)

@contextlib.contextmanager
def existing_panel(trap=False):
 s=ThreadingHTTPServer(('127.0.0.1',0),ExistingHandler)
 s.url='http://127.0.0.1:'+str(s.server_address[1]);s.trap=trap;s.trace=[];s.users={};s.revoked={}
 s.sequence=0;s.info_mode='';s.violations=[];s.public_credentials=[];s.returned_tokens=[];s.on_read=None;s.link_labels={}
 for uid in [41,42,43,44]:
  user=copy.deepcopy(pg.USER)
  user.update(id=uid,username='existing_user_'+str(uid),note='PRIVATE_EXISTING_NOTE_'+str(uid),
    proxy_settings={'vless':{'id':'owned-proxy-'+str(uid)}},subscription_url=s.url+'/native-subscriptions/'+mint(uid,int(time.time())-60))
  if uid==42:user.update(expire=None,data_limit=0)
  if uid==44:user['username']='12345'
  s.users[uid]=user
 thread=threading.Thread(target=s.serve_forever,daemon=True);thread.start()
 try:yield s,s.url
 finally:s.shutdown();thread.join(5);s.server_close()

class ExistingMarzbanHandler(mb.EditProviderHandler):
 def reply(self,status,value,content_type='application/json'):
  raw=json.dumps(value).encode()if content_type=='application/json'else value.encode()
  self.send_response(status);self.send_header('Content-Type',content_type)
  self.send_header('Content-Length',str(len(raw)));self.end_headers();self.wfile.write(raw)
 def handle_request(self):
  s=self.server
  if self.path.startswith('/api/user/')and self.command=='GET'and not s.deleted:
   s.trace.append(dict(method=self.command,path=self.path,body='',headers=dict(self.headers)))
   value=copy.deepcopy(s.edit_account);s.sequence+=1
   issued=s.fixed_clock if s.fixed_clock else int(time.time())+s.sequence
   value['subscription_url']=s.url+'/sub/'+mb_mint(value['username'],issued)
   s.returned_tokens.append(value['subscription_url'].rsplit('/',1)[1])
   self.reply(200,value);return
  if self.path.startswith('/sub/')and self.path.endswith('/info'):
   s.trace.append(dict(method=self.command,path=self.path,body='',headers=dict(self.headers)))
   parsed=parse_token(unquote(self.path[len('/sub/'):-len('/info')]))
   created=int(datetime.fromisoformat(s.edit_account['created_at']).replace(tzinfo=timezone.utc).timestamp())
   if not parsed or parsed[1]!=s.edit_account['username']or parsed[2]<max(created,s.revoked_at):
    self.reply(404,{'detail':'Not found'});return
   self.reply(200,{k:v for k,v in s.edit_account.items()if k not in('note','inbounds','excluded_inbounds','admin')});return
  if (self.command=='POST'and self.path.endswith('/revoke_sub'))or self.command=='DELETE':
   self.rfile.read(int(self.headers.get('Content-Length','0')))
   s.trace.append(dict(method=self.command,path=self.path,body='',headers=dict(self.headers)))
   if self.command=='DELETE':
    s.deleted=True;self.reply(200,{'detail':'User successfully deleted'});return
   s.revoke_count+=1;s.revoked_at=time.time()
   if s.revoke_mode=='malformed':identity='not-a-native-uuid'
   elif s.revoke_mode=='case_only':identity=s.edit_account['proxies']['vless']['id'].upper()
   else:identity='22222222-2222-4222-8222-'+str(s.revoke_count).zfill(12)
   s.edit_account['proxies']={'vless':{'id':identity,'flow':'xtls-rprx-vision'}}
   s.edit_account['links']=['vless://'+identity+'@host.invalid:443']
   # Official same-second token generation can retain the exact old token;
   # proxy rotation is the genuine revocation evidence.

   self.reply(200,s.edit_account);return
  if self.path.startswith('/sub/'):
   s.trace.append(dict(method=self.command,path=self.path,body='',headers=dict(self.headers)))
   self.reply(200,'\\n'.join(s.edit_account['links']),'text/plain');return
  if self.path.startswith('/api/user/')and s.deleted:
   s.trace.append(dict(method=self.command,path=self.path,body='',headers=dict(self.headers)))
   self.reply(404,{'detail':'User not found'});return
  super().handle_request()

@contextlib.contextmanager
def existing_marzban():
 server=ThreadingHTTPServer(('127.0.0.1',0),ExistingMarzbanHandler)
 server.url='http://127.0.0.1:'+str(server.server_address[1]);server.trace=[];server.deleted=False;server.revoke_count=0;server.sequence=0;server.fixed_clock=0;server.returned_tokens=[];server.revoked_at=0;server.revoke_mode='valid'
 thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
 try:yield server,'http://127.0.0.1:'+str(server.server_address[1])
 finally:server.shutdown();thread.join(5);server.server_close()

PHP=r'''<?php
error_reporting(E_ALL);
set_error_handler(static function($n,$m){if($n===E_DEPRECATED)return true;throw new ErrorException($m,0,$n);});
$_SERVER['SCRIPT_FILENAME']=__FILE__;define('ACHBOT_PG_TEST_LOOPBACK',true);
$root=$argv[1];$spec=json_decode($argv[4],true,64,JSON_THROW_ON_ERROR);
$connection=new mysqli('localhost','root','',$argv[3],0,$argv[2]);$connection->set_charset('utf8mb4');
$events=[];$emitted=false;
register_shutdown_function(static function(){if(!$GLOBALS['emitted'])echo json_encode(['events'=>$GLOBALS['events']],JSON_THROW_ON_ERROR);});
require_once $root.'/src/Purchase/Core.php';
require_once $root.'/src/Panel/Accounts.php';
require_once $root.'/settings/jdf.php';
require_once __DIR__.'/facades.php';
$mainValues=achbotMessages('legacy_messages');$buttonValues=achbotMessages('legacy_buttons');
$admin=9001;$botUrl='https://bot.example.invalid/';
$botState=array_fill_keys(['searchState','renewAutoState','sellState','renewAccountState','switchLocationState',
 'increaseVolumeState','increaseTimeState','renewConfigLinkState','updateConfigLinkState',
 'qrConfigState','qrSubState','subLinkState','configLinkState'],'on');
$botState['rewardChannel']='';
$botState+=['walletState'=>'on','nowPaymentOther'=>'off','zarinpal'=>'off','nextpay'=>'off',
 'cartToCartState'=>'off','giftCardState'=>'off','manualUsdtState'=>'off','agencyPlanDiscount'=>'off'];
$paymentKeys=[];$GLOBALS['owned_message_id']=(int)$spec['source']*10;
$from_id=(int)($spec['actor']??1001);$data=$spec['data']??'';$text=$spec['text']??'';
$source=(int)$spec['source'];$message_id=$source;$chat_id=$from_id;$cancelKey='{"keyboard":[]}';$removeKeyboard='{"remove_keyboard":true}';
$sender=['id'=>$from_id,'username'=>'owned_actor','first_name'=>'Owned'];
$chat=['id'=>$spec['chat_id']??$from_id,'type'=>$spec['chat_type']??'private'];
if($data!=='')$frame=['callback_query'=>['id'=>'owned_callback','from'=>$sender,'data'=>$data,
 'message'=>['message_id'=>$source,'text'=>'cached bot text','chat'=>$chat,'reply_markup'=>['inline_keyboard'=>[]]]]];
else $frame=['message'=>['message_id'=>$source,'from'=>$sender,'chat'=>$chat,'text'=>$text]];
$update=json_decode(json_encode(['update_id'=>$source]+$frame));
require $root.'/src/Telegram/UpdateBindings.php';
$q=$connection->prepare('SELECT * FROM users WHERE userid=? LIMIT 1');$q->bind_param('i',$from_id);$q->execute();$userInfo=$q->get_result()->fetch_assoc();$q->close();
if(!empty($spec['cached_admin']))$userInfo['isAdmin']=1;
function sendMessage($text,$keyboard=null,$parse=null,$to=null){
 $GLOBALS['events'][]=['kind'=>'send','text'=>$text,'keyboard'=>$keyboard,'to'=>$to];
 return(object)['ok'=>true,'result'=>(object)['message_id'=>++$GLOBALS['owned_message_id'],
  'chat'=>(object)['id'=>$to??$GLOBALS['from_id']]]];
}
function alert($text,$modal=false){$GLOBALS['events'][]=['kind'=>'alert','text'=>$text,'modal'=>$modal];}
function editText($id,$text,$keyboard=null,...$rest){$GLOBALS['events'][]=['kind'=>'edit','text'=>$text,'keyboard'=>$keyboard];return(object)['ok'=>true];}
function delMessage(...$a){$GLOBALS['events'][]=['kind'=>'delete'];}
function answerCallbackQuery($id,$text=null,...$rest){$GLOBALS['events'][]=['kind'=>'answer','text'=>$text];}
function bot(...$a){return(object)['ok'=>true,'result'=>(object)['first_name'=>'Owned']];}
function achbotSupportsPanel($type){return achbotSupportsProviderType($type);}
function getMarzbanUser(...$a){return \AchBot\Panel\Accounts\getMarzbanUser(...$a);}
function editMarzbanConfig(...$a){return \AchBot\Panel\Accounts\editMarzbanConfig(...$a);}
function getMainKeys(){return'{}';}
function getServerListKeys(...$a){return'{}';}
try {
 $out=null;$op=$spec['op'];
 if($op==='external')require $root.'/src/Subscription/ExternalAccounts.php';
 elseif($op==='manual'){
  require $root.'/src/Subscription/ExternalAccounts.php';
  require $root.'/src/Subscription/ManualAccounts.php';
 } elseif($op==='pay_pipeline'){
  require_once $root.'/src/Finance/ReceiptHelpers.php';
  require $root.'/src/Finance/Routes/PrePayment.php';
  require $root.'/src/Finance/Routes/RenewalCheckout.php';
 } elseif($op==='consent'){
  require __DIR__.'/consent.php';
 } elseif($op==='sweep'){
  $botState['renewAutoState']='off';
  require_once $root.'/src/Finance/AutomaticRenewal.php';
  $botState['renewAutoState']=$spec['enabled']??'on';
  achbotAutoRenewSweep($connection,$botState);
 } elseif($op==='renewal'){
  require_once $root.'/src/Finance/ReceiptHelpers.php';
  require $root.'/src/Finance/Routes/RenewalCheckout.php';
 } elseif($op==='extensions'){
  require $root.'/src/Finance/Routes/Extensions.php';
 } elseif($op==='copy'){
  $out=achbotMessage($spec['key']);
 } elseif($op==='customer'){
  require $root.'/src/Subscription/CustomerRoutes.php';
 } elseif($op==='delete'){
  require $root.'/src/Subscription/AccountDeletion.php';
 } elseif($op==='date'){
  $out=jdate('Y-m-d H:i',(int)$spec['expiry'],'','Asia/Tehran','en');
 } elseif($op==='finalize_capacity'){
  require_once $root.'/src/Finance/ReceiptHelpers.php';
  $out=achbotAutoRenewFinalizeDeletion($connection,(int)$spec['order'],(int)$spec['server'],true);
 } elseif($op==='capacity'){
  require_once $root.'/src/Finance/ReceiptHelpers.php';
  $order=$connection->query('SELECT * FROM orders_list WHERE id='.(int)$spec['order'])->fetch_assoc();
  if(isset($spec['plan']))$order['fileid']=(int)$spec['plan'];
  if(isset($spec['id_override']))$order['id']=(int)$spec['id_override'];
  $out=achbotOrderRestoresCapacity($connection,$order);
 } elseif($op==='created_identity'){
  achbotPgLoad();$user=achbotPgUserResult(achbotPgRequest(achbotPgServerPanel($connection,3),'GET','/api/user/by-id/41'));
  $out=achbotPgIdentityMatches($user['user'],['remote_user_id'=>41,'remote_created_at'=>'2026-10-26T05:00:00.123456Z','operation_marker'=>'achbot:v1:'.str_repeat('a',32)]);
 } elseif($op==='view'){
  require_once $root.'/src/Subscription/AccountDetails.php';
  $out=\AchBot\Subscription\AccountDetails\getOrderDetailKeys((int)$spec['owner'],(int)$spec['order']);
 } elseif($op==='edit'){
  $order=$connection->query('SELECT * FROM orders_list WHERE id='.(int)$spec['order'])->fetch_assoc();
  $out=achbotApplyServiceEdit($connection,(int)$order['server_id'],
   array_merge(['remark'=>$order['remark'],'operation_key'=>$spec['operation']],$spec['change']));
 } elseif($op==='action'){
  $order=$connection->query('SELECT * FROM orders_list WHERE id='.(int)$spec['order'])->fetch_assoc();
  $out=achbotProviderAction((int)$order['server_id'],$order['remark'],$spec['action']);
 } elseif($op==='observe'){
  require_once $root.'/src/Panel/ServiceLifecycle.php';
  $order=$connection->query('SELECT * FROM orders_list WHERE id='.(int)$spec['order'])->fetch_assoc();
  $observation=achbotServiceObserve((int)$order['server_id'],$order['remark']);
  $out=['observation'=>$observation,'policy'=>achbotServiceLifecycle($observation,time())];
 } elseif($op==='quote'){
  $connection->begin_transaction();achbotProviderPinPayment($connection,(int)$spec['pay']);$connection->commit();
  $pay=$connection->query('SELECT * FROM pays WHERE id='.(int)$spec['pay'])->fetch_assoc();
  $out=['eligible'=>achbotSupportsPaymentSubject($connection,$pay),'snapshot'=>json_decode($pay['provider_snapshot'],true)];
 } elseif($op==='lookup'){
  $out=achbotProviderExistingUser($connection,(int)$spec['server'],$spec['url'],$spec['hint']??null);
 } else throw new RuntimeException('fixture_op_missing');
 $emitted=true;echo json_encode(['events'=>$events,'out'=>$out],JSON_THROW_ON_ERROR);
}catch(Throwable$error){$emitted=true;echo json_encode(['fixture_error'=>get_class($error).':'.$error->getMessage(),'events'=>$events],JSON_THROW_ON_ERROR);}
'''

def main():
 parser=argparse.ArgumentParser();parser.add_argument('--run-disposable',action='store_true',required=True)
 parser.add_argument('--report',type=Path);args=parser.parse_args();checks=[]
 def yes(ok,name):
  if not ok:raise AssertionError(name)
  checks.append(name)
 def legacy_migration_boundary(with_007):
  # Use the same actual controllers and native wire contract in separate
  # owned databases: original23 and additive007 without provider migration011.
  with owned.owned_database()as(legacy_work,legacy_env),existing_marzban()as(legacy_panel,legacy_url):
   purchase.init(legacy_work,legacy_env,legacy_url)
   def db(query):return native.sql(legacy_work,legacy_env,query,DB).strip()
   if with_007:
    for migration in ['001_marzban_panel_group_id.sql','004_automatic_renewal_attempts.sql','007_service_effects.sql']:
     db((ROOT/'migrations/post-v9'/migration).read_text())
   legacy_panel.mode='edit-reset-verified';legacy_panel.edit_account=copy.deepcopy(mb.INITIAL)
   input_token=mb_mint('legacy_existing_user',int(time.time())-60)if with_007 else jwt_token('legacy_existing_user',int(time.time())-60)
   legacy_panel.edit_account.update(username='legacy_existing_user',created_at='2026-10-26T05:00:00.123456',
       expire=None,data_limit=None,subscription_url='/sub/'+input_token,
       proxies={'vless':{'id':'aaaaaaaa-1111-4111-8111-111111111111','flow':'xtls-rprx-vision'}},
       links=['vless://aaaaaaaa-1111-4111-8111-111111111111@host.invalid:443'],
       excluded_inbounds={},next_plan=None,auto_delete_in_days=None,on_hold_expire_duration=None,on_hold_timeout=None)
   legacy_panel.edit_put_count=0;legacy_panel.edit_reset_count=0
   facade=bodies.function_bodies((ROOT/'config.php').read_bytes(),[
       'buildSubscriptionPresentation','buildConfigMessage','setUser','sumerize','getOrderDetailKeys','getUserOrderDetailKeys',
       'renewMarzbanUUID','deleteMarzban','changeMarzbanState','getMarzbanToken','RandomString'])
   (legacy_work/'facades.php').write_bytes(facade.replace(b'__DIR__',repr(str(ROOT)).encode()))
   driver=legacy_work/'existing.php';driver.write_text(PHP)
   event_id=100000;actor=9001 if with_007 else 1001
   def invoke(op,**spec):
    nonlocal event_id
    event_id+=1
    run=subprocess.run(['php','-d','display_errors=0','-d','log_errors=0',str(driver),
        str(ROOT),str(legacy_work/'mysql.sock'),DB,json.dumps(dict(op=op,source=event_id,**({'actor':actor}|spec)))],
        env=legacy_env,cwd=legacy_work,capture_output=True,text=True,timeout=25)
    allowed=all(re.fullmatch(r'achbot_existing_subscription_import_deferred reason=[A-Za-z0-9_]+ class=[A-Za-z0-9_\\]+ line=[0-9]+',line)for line in run.stderr.splitlines())
    if run.returncode or not allowed:raise AssertionError(('legacy_MB_child',run.returncode,run.stdout,run.stderr))
    result=json.loads(run.stdout)
    if 'fixture_error'in result:raise AssertionError(result)
    return result
   financial=db('SELECT userid,wallet FROM users ORDER BY id;SELECT * FROM pays ORDER BY id;SELECT id,ucount FROM server_info ORDER BY id')
   native_before=copy.deepcopy(legacy_panel.edit_account)
   if not with_007:
    invoke('external',data='showUUIDLeft')
    invalid=input_token[:-1]+('a'if input_token[-1]!='a'else'b')
    invoke('external',text=legacy_url+'/sub/'+invalid)
    yes(db('SELECT COUNT(*) FROM orders_list')=='0'and db('SELECT COUNT(*) FROM add_link')=='0'and
        legacy_panel.edit_account==native_before,
        'actual_original23_Marzban_legacy_JWT_invalid_native_signature_never_authorizes_import')
   invoke('external',data='showUUIDLeft')
   result=invoke('external',text=legacy_url+'/sub/'+input_token)
   if with_007:
    invoke('manual',data='addSubscriptionToCustomer2');invoke('manual',text='1001')
    db("CREATE TRIGGER owned_MB_adopt_reject BEFORE INSERT ON service_effects FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='owned_fixture_reject'")
    invoke('manual',data='addToOrders2')
    yes(db('SELECT COUNT(*) FROM orders_list')=='0'and db('SELECT COUNT(*) FROM service_effects')=='0',
        'Marzban_007_adoption_failure_rolls_back_local_order_and_ledger_without011')
    db('DROP TRIGGER owned_MB_adopt_reject')
   invoke('manual',data='addToOrders2')
   label='007_without011'if with_007 else'original23_without007_or011'
   yes(db('SELECT userid,fileid,amount,expire_date FROM orders_list')=='1001\\t0\\t0\\t0'.replace('\\t','\t')and
       legacy_panel.edit_account==native_before and input_token not in legacy_panel.returned_tokens,
       'actual_Marzban_'+label+'_imports_older_native_token_without_remote_changes')
   yes(db('SELECT userid,wallet FROM users ORDER BY id;SELECT * FROM pays ORDER BY id;SELECT id,ucount FROM server_info ORDER BY id')==financial,
       'actual_Marzban_'+label+'_has_no_wallet_payment_or_capacity_effect')
   if with_007:
    yes(db('SELECT source_kind,effect_kind,reserved_count,IFNULL(pay_row_id,"NULL") FROM service_effects')=='external_import\\tadopt\\t0\\tNULL'.replace('\\t','\t')and
        db("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()AND TABLE_NAME='provider_accounts'")=='0',
        'Marzban_007_genuine_adopt_ledger_requires_no_provider011_tables')
    count=db('SELECT COUNT(*) FROM service_effects');invoke('manual',data='addToOrders2')
    yes(db('SELECT COUNT(*) FROM service_effects')==count and db('SELECT COUNT(*) FROM orders_list')=='1',
        'Marzban_007_adoption_replay_has_no_second_order_or_ledger')
    oid=int(db('SELECT id FROM orders_list'))
    db(f'UPDATE orders_list SET fileid=7 WHERE id={oid}')
    capacity=db('SELECT id,ucount FROM server_info ORDER BY id')
    invoke('delete',data='yesDeleteConfig'+str(oid),actor=1001)
    yes(db('SELECT COUNT(*) FROM orders_list')=='0'and db('SELECT id,ucount FROM server_info ORDER BY id')==capacity and
        db('SELECT COUNT(*) FROM service_effects')==count and legacy_panel.deleted,
        'actual_Marzban_assigned_plan_adopt_delete_preserves_zero_capacity_origin_and_ledger')
   else:
    yes(len(db('SHOW TABLES').splitlines())==23,'original23_Marzban_import_never_creates_runtime_schema')
    yes(db('SELECT token FROM orders_list')==input_token and input_token.startswith('eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.'),
        'actual_original23_Marzban_native_legacy_JWT_proves_URL_alone_import_without011')

 def shared_registration_and_paid_journey(provider):
  fixture=existing_panel if provider=='pasarguard'else existing_marzban
  with owned.owned_database()as(shared_work,shared_env),fixture()as(vendor,vendor_url):
   purchase.init(shared_work,shared_env,vendor_url)
   def db(query):return native.sql(shared_work,shared_env,query,DB).strip()
   for migration in ['001_marzban_panel_group_id.sql','003_payment_attempts.sql',
       '004_automatic_renewal_attempts.sql','006_payment_message_refs.sql',
       '007_service_effects.sql','011_provider_identity.sql']:
    db((ROOT/'migrations/post-v9'/migration).read_text())
   for actor in [1002,1003]:
    db("INSERT INTO users(userid,name,username,refcode,wallet,date,isAdmin,first_start,is_agent,step)"
       f" VALUES({actor},'Shared','shared','0',50000,'1700000000',0,'sent',0,'none')")
   db("UPDATE server_plans SET price=137,days=30,volume=3 WHERE id=7;"
      "DELETE FROM setting WHERE type='BOT_STATES';"
      "INSERT INTO setting(type,value)VALUES(\'BOT_STATES\',CONVERT(UNHEX(\'7b2277616c6c65745374617465223a226f6e222c226761746577617941756469656e6365223a7b2277616c6c6574223a22616c6c227d7d\') USING utf8mb4))")
   if provider=='pasarguard':
    db("UPDATE server_config SET type='pasarguard' WHERE id=2;"
       "UPDATE server_info SET provider_panel_id=1,provider_group_ids='[2,5]' WHERE id=2;"
       "INSERT INTO provider_panels(id,provider,instance_key,name,api_url,api_key,version)"
       " VALUES(1,'pasarguard',REPEAT('b',32),'Shared fixture','"+vendor_url+"','fixture-private-key','5.5.1')")
    remark='existing_user_41';credential=vendor.users[41]['subscription_url']
    # Native scheduling is allowed for import/read; only existing mutation
    # eligibility is restricted, and must be checked before money/fence claims.
    vendor.users[41]['auto_delete_in_days']=3
    raw=lambda:vendor.users[41]
    def remote_writes():return [r for r in vendor.trace if r[0]!='GET']
   else:
    vendor.mode='edit-reset-verified';vendor.edit_put_count=0;vendor.edit_reset_count=0
    vendor.edit_account=copy.deepcopy(mb.INITIAL)
    remark='shared_native_user';token=mb_mint(remark,int(time.time())-60)
    vendor.edit_account.update(username=remark,created_at='2026-10-26T05:00:00.123456',
       subscription_url='/sub/'+token,proxies={'vless':{'id':'aaaaaaaa-1111-4111-8111-111111111111','flow':'xtls-rprx-vision'}},
       links=['vless://aaaaaaaa-1111-4111-8111-111111111111@host.invalid:443'],
       excluded_inbounds={},next_plan=None,auto_delete_in_days=None,on_hold_expire_duration=None,on_hold_timeout=None)
    credential=vendor_url+'/sub/'+token;raw=lambda:vendor.edit_account
    def remote_writes():
     return [r for r in vendor.trace if r['method']in('PUT','DELETE')or
         (r['method']=='POST'and not r['path'].endswith('/api/admin/token'))]
   facade=bodies.function_bodies((ROOT/'config.php').read_bytes(),[
       'buildSubscriptionPresentation','buildConfigMessage','setUser','sumerize',
       'getOrderDetailKeys','getUserOrderDetailKeys','renewMarzbanUUID','deleteMarzban',
       'changeMarzbanState','getMarzbanToken','RandomString'])
   (shared_work/'facades.php').write_bytes(facade.replace(b'__DIR__',repr(str(ROOT)).encode()))
   config_source=(ROOT/'config.php').read_text()
   consent_start=config_source.index("if (preg_match('/^(toggleAutoRenew|adminToggleAutoRenew)")
   consent_end=config_source.index('// END create user and admin get order detail',consent_start)
   (shared_work/'consent.php').write_text('<?php\n'+config_source[consent_start:consent_end].replace('__DIR__',repr(str(ROOT))))
   script=shared_work/'existing.php';script.write_text(PHP);event_id=200000;event_lock=threading.Lock()
   def invoke(op,**spec):
    nonlocal event_id
    with event_lock:
     event_id+=1;current_source=event_id
    process=subprocess.run(['php','-d','display_errors=0','-d','log_errors=0',str(script),
       str(ROOT),str(shared_work/'mysql.sock'),DB,json.dumps(dict(op=op,source=current_source,**({'actor':1001}|spec)))],
       env=shared_env,cwd=shared_work,capture_output=True,text=True,timeout=25)
    allowed=all(line=='achbot_auto_renew_reservation_deferred'or
        line.startswith(('achbot_existing_subscription_import_deferred reason=',
        'achbot_existing_subscription_lookup_deferred','achbot_customer_draft_creation_deferred',
        'achbot_server_display_unavailable'))for line in process.stderr.splitlines())
    if process.returncode or not allowed:raise AssertionError((provider,'shared_native_child',process.returncode,process.stdout,process.stderr))
    result=json.loads(process.stdout)
    if 'fixture_error'in result:raise AssertionError((provider,result))
    return result
   def finance():return db('SELECT userid,wallet FROM users ORDER BY id;SELECT * FROM pays ORDER BY id;SELECT id,ucount FROM server_info ORDER BY id')
   def attach(actor,target=None):
    invoke('external',actor=actor,data='showUUIDLeft')
    invoke('external',actor=actor,text=credential)
    if target is not None:
     invoke('manual',actor=actor,data='addSubscriptionToCustomer2')
     invoke('manual',actor=actor,text=str(target))
    return invoke('manual',actor=actor,data='addToOrders2')
   baseline=finance();native_before=copy.deepcopy(raw())
   attach(1001)
   first_physical=db('SELECT * FROM provider_accounts ORDER BY id')
   first_effect=db('SELECT * FROM service_effects ORDER BY id LIMIT 1')
   attach(1002);attach(9001,1003)
   yes(db('SELECT * FROM provider_accounts ORDER BY id')==first_physical and
       db('SELECT * FROM service_effects ORDER BY id LIMIT 1')==first_effect,
       provider+'_later_shared_attachments_preserve_original_physical_anchor_and_history')
   rows=db("SELECT userid,id FROM orders_list WHERE remark='"+remark+"' ORDER BY userid")
   links={int(user):int(oid)for user,oid in(line.split('\t')for line in rows.splitlines())}
   yes(set(links)=={1001,1002,1003}and len(set(links.values()))==3,
       provider+'_same_native_subscription_registers_for_A_B_and_admin_selected_C_without_transfer')
   yes(finance()==baseline and raw()==native_before and not remote_writes(),
       provider+'_multiple_registration_is_local_only_without_wallet_pay_capacity_or_native_claim')
   before=db('SELECT * FROM orders_list ORDER BY id;SELECT * FROM service_effects ORDER BY id')
   invoke('manual',actor=1002,data='addToOrders2')
   invoke('manual',actor=9001,data='addToOrders2')
   yes(db('SELECT * FROM orders_list ORDER BY id;SELECT * FROM service_effects ORDER BY id')==before,
       provider+'_registration_request_replay_does_not_duplicate_shared_references')
   for actor,oid in links.items():
    visible=invoke('view',actor=actor,owner=actor,order=oid)['out']
    yes(visible is not None and ('vless://'in json.dumps(visible))and remark in json.dumps(visible),
        provider+'_shared_reference_'+str(actor)+'_has_a_real_owner_scoped_native_view')

   origin_rows=db("SELECT value FROM setting WHERE type LIKE 'ACHBOT_SERVICE_ORIGIN:%' ORDER BY id")
   origins=[json.loads(row)for row in origin_rows.splitlines()]
   yes(len(origins)==3 and {row['owner_userid']for row in origins}=={1001,1002,1003}and
       {row['actor_userid']for row in origins}=={1001,1002,9001}and
       all(row['capacity_reserved']is False for row in origins)and
       (provider!='pasarguard'or len({row['provider_account_id']for row in origins})==1),
       provider+'_three_genuine_nonfinancial_origins_reference_one_native_identity_with_actual_actors')

   if provider=='pasarguard':
    yes(raw()['auto_delete_in_days']==3 and db("SELECT COUNT(*) FROM provider_accounts WHERE state='linked'AND mutation_key IS NULL")=='1',
        'scheduled_native_import_still_registers_and_renders_for_all_three_users_without_claiming_mutation')
    raw()['auto_delete_in_days']=None

   # Follow the actual sequential UI into a real positive priced renewal;
   # imported amount0 remains honest until the actual payment is made.
   current=links[1001];writes=len(remote_writes());before=finance()
   offered=invoke('extensions',data='changePlan_'+str(current))
   yes('confirmChangePlan_'+str(current)+'_7'in json.dumps(offered),
       provider+'_genuine_import_can_open_real_change_plan_menu_without_trial_misclassification')
   invoke('extensions',data='confirmChangePlan_'+str(current)+'_7')
   yes(db(f'SELECT fileid,amount FROM orders_list WHERE id={current}')=='7\t0'and
       finance()==before and len(remote_writes())==writes,
       provider+'_actual_plan_confirmation_assigns_real_catalogue_plan_without_faking_purchase_or_amount')
   visible=invoke('view',owner=1001,order=current)['out']
   yes('renewAccountAsk'+str(current)in json.dumps(visible),
       provider+'_assigned_import_exposes_actual_paid_renewal_entry')
   invoke('renewal',data='renewAccountAsk'+str(current))
   invoice=invoke('renewal',data='renewAccount'+str(current))
   pay=db(f"SELECT hash_id,price,user_id,state FROM pays WHERE order_id={current} ORDER BY id DESC LIMIT 1").split('\t')
   yes(len(pay)==4 and pay[1:]==['137','1001','pending']and
       'prePayCheck_Renew_Wallet_'+pay[0]in json.dumps(invoice),
       provider+'_real_renewal_controller_issues_owned_wallet_quote_from_assigned_plan')
   before=db('SELECT userid,wallet FROM users ORDER BY id;SELECT * FROM pays ORDER BY id');writes=len(remote_writes())
   invoke('renewal',actor=1002,data='payRenewWithWallet'+pay[0])
   yes(db('SELECT userid,wallet FROM users ORDER BY id;SELECT * FROM pays ORDER BY id')==before and len(remote_writes())==writes,
       provider+'_another_shared_reference_cannot_consume_the_request_owners_payment')
   if provider=='pasarguard':
    raw()['auto_delete_in_days']=3
    blocked_finance=finance();blocked_native=copy.deepcopy(raw());blocked_writes=len(remote_writes())
    blocked_identity=db('SELECT * FROM provider_accounts ORDER BY id;SELECT * FROM service_effects ORDER BY id')
    invoke('pay_pipeline',data='prePayCheck_Renew_Wallet_'+pay[0])
    yes(finance()==blocked_finance and raw()==blocked_native and len(remote_writes())==blocked_writes and
        db('SELECT * FROM provider_accounts ORDER BY id;SELECT * FROM service_effects ORDER BY id')==blocked_identity,
        'known_native_schedule_refuses_actual_wallet_payment_before_debit_reset_PUT_or_physical_claim')
    for action in ['revoke','toggle','reset','delete']:
     refused=invoke('action',order=current,action=action)['out']
     yes(refused.get('success')is False and finance()==blocked_finance and raw()==blocked_native and
         len(remote_writes())==blocked_writes and
         db('SELECT * FROM provider_accounts ORDER BY id;SELECT * FROM service_effects ORDER BY id')==blocked_identity,
         'known_native_schedule_refuses_direct_'+action+'_without_poisoning_physical_mutation_or_history')
    refused=invoke('edit',order=current,operation='fixture:known-scheduling-refusal',change={'days':30,'volume':3})['out']
    yes(refused.get('success')is False and finance()==blocked_finance and raw()==blocked_native and
        len(remote_writes())==blocked_writes and
        db('SELECT * FROM provider_accounts ORDER BY id;SELECT * FROM service_effects ORDER BY id')==blocked_identity,
        'known_native_schedule_refuses_direct_full_edit_before_mutation_key_or_reset_PUT')
    raw()['auto_delete_in_days']=None;old_status=raw()['status'];raw()['status']='limited'
    refused=invoke('action',order=current,action='toggle')['out']
    yes(refused.get('success')is False and raw()['status']=='limited'and finance()==blocked_finance and
        len(remote_writes())==blocked_writes and
        db('SELECT * FROM provider_accounts ORDER BY id;SELECT * FROM service_effects ORDER BY id')==blocked_identity,
        'known_restricted_native_status_refuses_toggle_before_physical_mutation_claim')
    raw()['status']=old_status
   capacity=db('SELECT id,ucount FROM server_info ORDER BY id')
   started=int(time.time());paid=invoke('pay_pipeline',data='prePayCheck_Renew_Wallet_'+pay[0]);ended=int(time.time())
   expiry=raw()['expire']
   if isinstance(expiry,str):expiry=int(datetime.fromisoformat(expiry.replace('Z','+00:00')).timestamp())
   yes(db("SELECT state FROM pays WHERE hash_id='"+pay[0]+"'")=='paid_with_wallet'and
       db('SELECT wallet FROM users WHERE userid=1001')=='49863'and
       db('SELECT wallet FROM users WHERE userid IN(1002,1003)ORDER BY userid')=='50000\n50000'and
       raw()['data_limit']==3*GIB and raw()['used_traffic']==0 and started+30*86400<=expiry<=ended+30*86400,
       provider+'_actual_wallet_pipeline_debits_only_A_once_and_verifies_shared_native_full_renewal')
   yes(db('SELECT COUNT(*),MIN(amount),MAX(amount)FROM increase_order')=='1\t137\t137'and
       db('SELECT id,ucount FROM server_info ORDER BY id')==capacity,
       provider+'_real_paid_renewal_keeps_one_accounting_record_and_no_CREATE_capacity_effect')
   before=db('SELECT userid,wallet FROM users ORDER BY id;SELECT * FROM pays ORDER BY id;SELECT * FROM increase_order ORDER BY id')
   writes=len(remote_writes());invoke('renewal',data='payRenewWithWallet'+pay[0])
   yes(db('SELECT userid,wallet FROM users ORDER BY id;SELECT * FROM pays ORDER BY id;SELECT * FROM increase_order ORDER BY id')==before and len(remote_writes())==writes,
       provider+'_same_paid_request_replay_has_no_second_wallet_debit_or_native_reset')
   expected=invoke('date',expiry=expiry)['out']
   for actor in [1002,1003]:
    visible=invoke('view',actor=actor,owner=actor,order=links[actor])['out']
    actual_date=json.loads(visible['keyboard'])['inline_keyboard'][1][0]['text']
    yes(actual_date==expected,
        provider+'_shared_reference_'+str(actor)+'_immediately_observes_A_native_renewal_expiry')

   # Both people explicitly select a priced plan and opt in through actual UI.
   # Native usage changes externally; the existing warning flag is a fixture
   # prerequisite for the real scheduled sweep, not a payment or consent bypass.
   invoke('extensions',actor=1002,data='changePlan_'+str(links[1002]))
   invoke('extensions',actor=1002,data='confirmChangePlan_'+str(links[1002])+'_7')
   before=finance();writes=len(remote_writes())
   for actor in [1001,1002]:
    invoke('consent',actor=actor,data='toggleAutoRenew'+str(links[actor]))
   yes(db('SELECT autoRenew FROM orders_list WHERE userid IN(1001,1002) ORDER BY userid')=='on\non'and
       finance()==before and len(remote_writes())==writes,
       provider+'_both_users_choose_real_paid_plans_and_explicitly_enable_actual_auto_renew_consent')
   raw()['used_traffic']=raw()['data_limit']-100000000
   db('UPDATE orders_list SET notif=1 WHERE id IN('+','.join(map(str,links.values()))+')')
   before_pays=int(db('SELECT COUNT(*) FROM pays'));writes=len(remote_writes())
   barrier=threading.Barrier(2)
   def concurrent_sweep():
    barrier.wait(timeout=10)
    return invoke('sweep')
   with ThreadPoolExecutor(max_workers=2)as pool:
    futures=[pool.submit(concurrent_sweep)for _ in range(2)]
    for future in futures:future.result(timeout=35)
   wallets=list(map(int,db('SELECT wallet FROM users WHERE userid IN(1001,1002,1003) ORDER BY userid').splitlines()))
   yes(wallets in ([49726,50000,50000],[49863,49863,50000])and
       int(db('SELECT COUNT(*) FROM pays'))==before_pays+1 and
       db('SELECT COUNT(*),MIN(stage),MAX(stage) FROM automatic_renewal_attempts')=='1\tdone\tdone'and
       raw()['used_traffic']==0 and raw()['data_limit']==3*GIB and
       len(remote_writes())==writes+2,
       provider+'_two_concurrent_actual_shared_sweeps_have_one_payer_one_reset_and_one_native_full_renewal')
   yes(db('SELECT COUNT(DISTINCT expire_date),MIN(notif),MAX(notif) FROM orders_list WHERE id IN('+
       ','.join(map(str,links.values()))+')')=='1\t0\t0',
       provider+'_one_verified_auto_renewal_synchronizes_every_shared_reference_expiry_and_warning')
   before=finance();writes=len(remote_writes());invoke('sweep')
   yes(finance()==before and len(remote_writes())==writes and
       db('SELECT COUNT(*) FROM automatic_renewal_attempts')=='1',
       provider+'_second_shared_sweep_cannot_charge_the_other_user_for_the_same_cycle')
   # Regeneration changes the physical service once; every reference must
   # immediately render the same new native configs, without a local transfer.
   before=finance();writes=len(remote_writes())
   invoke('customer',data='changAccountConnectionLink'+str(current))
   yes(finance()==before and len(remote_writes())==writes+1,
       provider+'_shared_actual_regenerate_has_one_native_action_and_no_financial_or_capacity_effect')
   expected_link=raw()['links'][0]if provider=='marzban'else'vless://revoked-owned-proxy@host.invalid:443'
   for actor in [1002,1003]:
    viewed=invoke('view',actor=actor,owner=actor,order=links[actor])['out']
    yes(expected_link in json.dumps(viewed),
        provider+'_shared_reference_'+str(actor)+'_sees_current_physical_configs_after_A_regenerates')
 legacy_migration_boundary(False)
 legacy_migration_boundary(True)
 with owned.owned_database()as(work,env),existing_panel()as(panel,url),existing_panel(True)as(foreign,foreign_url),existing_marzban()as(marzban,mb_url):
  purchase.init(work,env,mb_url)
  def sql(statement):return native.sql(work,env,statement,DB).strip()
  # Optional import hooks are an all-or-none pair, not a new required
  # business contract; original-schema Marzban remains usable without 011.
  probe=r'''require $argv[1].'/src/Purchase/Core.php';
$db=new mysqli('localhost','root','',$argv[3],0,$argv[2]);
$map=achbotProviderRegistry();$full=$map['marzban'];$none=$full;$one=$full;$bad=$full;
unset($none['import_lookup'],$none['import_adopt']);unset($one['import_adopt']);
$bad['import_lookup']='fixture_noncallable_missing';
echo json_encode(['required'=>count(achbotProviderRequiredOperations()),
 'full'=>achbotProviderRegistryEntryValid('marzban',$full),
 'none'=>achbotProviderRegistryEntryValid('marzban',$none),
 'one'=>achbotProviderRegistryEntryValid('marzban',$one),
 'bad'=>achbotProviderRegistryEntryValid('marzban',$bad),
 'plan'=>achbotSupportsPlan($db,7),
 'lookup'=>isset($map['marzban']['import_lookup'])?null:achbotProviderExistingUser($db,2,'https://invalid.example/sub/test','hint')]);'''
  def probe_call(root):
   run=subprocess.run(['php','-r',probe,str(root),str(work/'mysql.sock'),DB],
       env=env,cwd=work,capture_output=True,text=True,timeout=15)
   if run.returncode or run.stderr:raise AssertionError(('registry_probe',run.returncode,run.stdout,run.stderr))
   return json.loads(run.stdout)
  # Stage only task-owned runtime files, removing optional callbacks in the
  # fixture source. No implementation or frozen historical files are edited.
  staged=work/'no-import-capability'
  for member in package.members():
   target=staged/member;target.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(ROOT/member,target)
  target=staged/'src/Panel/ProviderRuntime.php';raw=target.read_text()
  raw=raw.replace("            'import_lookup'=>'achbotMarzbanExistingUser','import_adopt'=>'achbotMarzbanAdoptExistingLocked'",'')
  target.write_text(raw)
  optional=probe_call(staged)
  yes(optional['required']==14 and optional['none'] and optional['plan'] and
      optional['lookup']['success']is False,'missing_optional_import_capability_preserves_required14_and_Marzban_without011')
  optional=probe_call(ROOT)
  yes(optional['full']and optional['none']and not optional['one']and not optional['bad']and optional['plan'],
      'optional_import_pair_is_callable_all_or_none')
  for migration in ['001_marzban_panel_group_id.sql','004_automatic_renewal_attempts.sql','007_service_effects.sql','011_provider_identity.sql']:
   sql((ROOT/'migrations/post-v9'/migration).read_text())
  sql("DELETE FROM server_config WHERE id IN(3,4);INSERT INTO provider_panels(id,provider,instance_key,name,api_url,api_key,version)"
      " VALUES(1,'pasarguard',REPEAT('b',32),'Import fixture','"+url+"','fixture-private-key','5.5.1');"
      "INSERT INTO server_info(id,title,ucount,remark,flag,active,state,provider_panel_id,provider_group_ids)"
      " VALUES(3,'Native service',20,'existing','',1,1,1,'[2,5]'),(4,'Logical alias',20,'alias','',1,1,1,'[2,5]');"
      "INSERT INTO server_config SELECT 3,'"+url+"',ip,sni,header_type,request_header,response_header,security,tlsSettings,'pasarguard','','',port_type,reality,sub_links FROM server_config WHERE id=2;"
      "INSERT INTO server_config SELECT 4,'"+url+"',ip,sni,header_type,request_header,response_header,security,tlsSettings,'pasarguard','','',port_type,reality,sub_links FROM server_config WHERE id=2;")
  for actor in [1002,1003,1004]:
   sql("INSERT INTO users(userid,name,username,refcode,wallet,date,isAdmin,first_start,is_agent,step)"
       f" VALUES({actor},'Owned','owned','0',50000,'1700000000',0,'sent',0,'none')")
  marzban.mode='edit-reset-verified';marzban.edit_account=copy.deepcopy(mb.INITIAL)
  mb_token=mb_mint('existing_mb_user',int(time.time())-60)
  marzban.edit_account.update(username='existing_mb_user',subscription_url='/sub/'+mb_token,expire=None,data_limit=None,
      created_at='2026-10-26T05:00:00.123456',excluded_inbounds={},
      proxies={'vless':{'id':'aaaaaaaa-1111-4111-8111-111111111111','flow':'xtls-rprx-vision'}},
      links=['vless://aaaaaaaa-1111-4111-8111-111111111111@host.invalid:443'],
      next_plan=None,auto_delete_in_days=None,on_hold_expire_duration=None,on_hold_timeout=None)
  marzban.edit_put_count=0;marzban.edit_reset_count=0
  facades=bodies.function_bodies((ROOT/'config.php').read_bytes(),['buildSubscriptionPresentation','buildConfigMessage','setUser','sumerize','getOrderDetailKeys','getUserOrderDetailKeys','renewMarzbanUUID','deleteMarzban','changeMarzbanState','getMarzbanToken','RandomString'])
  (work/'facades.php').write_bytes(facades.replace(b'__DIR__',repr(str(ROOT)).encode()))
  driver=work/'existing.php';driver.write_text(PHP);source=900000;customer_outputs=[]
  def call(op,**spec):
   nonlocal source
   source+=1
   proc=subprocess.run(['php','-d','display_errors=0','-d','log_errors=0',
       str(driver),str(ROOT),str(work/'mysql.sock'),DB,json.dumps(dict(op=op,source=source,**spec))],
       env=env,cwd=work,capture_output=True,text=True,timeout=25)
   allowed=[line for line in proc.stderr.splitlines()if re.fullmatch(
      r'achbot_existing_subscription_import_deferred reason=[A-Za-z0-9_]+ class=[A-Za-z0-9_\\]+ line=[0-9]+',line)
      or line in('achbot_existing_subscription_lookup_deferred','achbot_auto_renew_local_deletion_deferred')]
   if proc.returncode or len(allowed)!=len(proc.stderr.splitlines()):
    raise AssertionError(('native_child',proc.returncode,proc.stdout,proc.stderr[:1000]))
   if any(secret in proc.stderr for secret in ['fixture-private-key','PRIVATE_EXISTING_NOTE',url]):
    raise AssertionError('private_native_diagnostic')
   out=json.loads(proc.stdout)
   if 'fixture_error'in out:raise AssertionError(out)
   if spec.get('actor',1001)not in(9001,9002)and op in('external','manual','view','customer','delete','extensions'):
    customer_outputs.append(json.dumps(out,ensure_ascii=False))
   return out
  def financial():return sql("SELECT userid,wallet FROM users ORDER BY id;SELECT * FROM pays ORDER BY id;SELECT id,ucount FROM server_info ORDER BY id")
  def entities():return sql('SELECT * FROM orders_list ORDER BY id;SELECT * FROM service_effects ORDER BY id;SELECT * FROM provider_accounts ORDER BY id')
  def state(actor):
   raw=sql(f'SELECT step,IFNULL(HEX(temp),"NULL") FROM users WHERE userid={actor}')
   step,encoded=raw.split('\t')
   return step,None if encoded=='NULL'else json.loads(bytes.fromhex(encoded).decode())
  def start(actor):return call('external',actor=actor,data='showUUIDLeft')
  def preview(actor,input_url):start(actor);return call('external',actor=actor,text=input_url)
  def mutations():return [r for r in panel.trace if r[0]!='GET']
  base_finance=financial();remote_before=copy.deepcopy(panel.users)
  supplied=panel.users[41]['subscription_url'];token=supplied.rsplit('/',1)[1]
  shown=preview(1001,supplied);step,temp=state(1001)
  yes(step=='none'and temp['provider']=='pasarguard'and temp['server_id']==3 and temp['import_url']==supplied,
      'URL_alone_native_preview_and_logical_alias_deduplicated_without_extra_inputs')
  yes('addToOrders3'in json.dumps(shown)and token not in panel.returned_tokens and panel.returned_tokens,
      'older_valid_v3_token_succeeds_while_authenticated_native_GET_tokens_rotate')
  yes(financial()==base_finance and sql('SELECT COUNT(*) FROM orders_list')=='0'and
      sql('SELECT COUNT(*) FROM provider_accounts')=='0'and sql('SELECT COUNT(*) FROM service_effects')=='0',
      'preview_never_creates_order_adoption_or_financial_effect')
  before=entities();call('manual',actor=1002,data='addToOrders3')
  yes(entities()==before,'cross_actor_callback_cannot_use_foreign_preview')
  before=entities();call('manual',actor=1001,data='addToOrders3',chat_type='group',chat_id=-1001)
  yes(entities()==before,'nonprivate_confirmation_has_no_local_adoption')
  call('manual',actor=1001,data='addToOrders3')
  yes(sql('SELECT userid,fileid,amount,server_id,IFNULL(autoRenew,"NULL") FROM orders_list')in
      ['1001\t0\t0\t3\toff','1001\t0\t0\t3\t0'],
      'customer_confirmation_creates_only_zero_price_unassigned_plan_import')
  order=int(sql("SELECT id FROM orders_list WHERE remark='existing_user_41'"))
  yes(sql('SELECT source_kind,effect_kind,IFNULL(pay_row_id,"NULL"),reserved_count,state FROM service_effects')==
      'external_import\tadopt\tNULL\t0\tlocal_recorded','genuine_adopt_effect_is_distinct_from_CREATE_or_financial_capacity_reservation')
  yes(sql('SELECT remote_user_id,state FROM provider_accounts')=='41\tlinked'and
      sql('SELECT operation_marker FROM provider_accounts').startswith('achbot:adopt:'),
      'adoption_persists_exact_native_id_and_linked_import_identity')
  yes(financial()==base_finance and panel.users==remote_before and not mutations(),
      'import_leaves_wallet_pays_capacity_native_note_limits_groups_proxy_and_tokens_untouched')
  before=entities();call('manual',actor=1001,data='addToOrders3')
  yes(entities()==before and not mutations(),'confirmation_replay_cannot_duplicate_order_or_adopt_ledger')

  supplied42=url+'/native-subscriptions/'+panel.users[42]['subscription_url'].rsplit('/',1)[1].replace('.','%2E')+'/'
  shown=preview(9001,supplied42)
  keyboard=json.loads(next(event['keyboard']for event in reversed(shown['events'])if event.get('keyboard')and'inline_keyboard'in event['keyboard']))['inline_keyboard']
  yes(all(keyboard[index][0]['text']=='نامحدود'for index in [3,5,6]),
      'PasarGuard_unlimited_quota_date_and_days_render_literal_unlimited_in_actual_preview')
  yes('addSubscriptionToCustomer3'in json.dumps(shown),'admin_preview_uses_familiar_customer_assignment_action')
  call('manual',actor=9001,data='addSubscriptionToCustomer3')
  yes(state(9001)[0]=='awaiting_userid_input','admin_target_step_matches_existing_Marzban_flow')
  call('manual',actor=9001,text='987654321')
  yes(state(9001)[0]=='awaiting_userid_input'and sql('SELECT COUNT(*) FROM orders_list')=='1',
      'unknown_customer_keeps_target_step_without_creating_order')
  call('manual',actor=9001,text='1003')
  call('manual',actor=9001,data='addToOrders3')
  yes(sql("SELECT userid,fileid,amount FROM orders_list WHERE remark='existing_user_42'")=='1003\t0\t0',
      'admin_URL_encoded_token_trailing_slash_import_attaches_to_selected_existing_customer')
  yes(financial()==base_finance and panel.users==remote_before and not mutations(),
      'admin_assignment_is_local_only_and_preserves_native_service')
  unlimited_order=int(sql("SELECT id FROM orders_list WHERE remark='existing_user_42'"))
  yes(sql(f'SELECT expire_date FROM orders_list WHERE id={unlimited_order}')=='0'and
      json.loads(sql(f'SELECT entitlement_snapshot FROM provider_accounts WHERE order_id={unlimited_order}'))['expire']is None,
      'native_NULL_expiry_uses_only_original_schema_zero_storage_sentinel_with_NULL_provenance')
  displayed=call('view',actor=1003,owner=1003,order=unlimited_order)['out']
  unlimited_controls=[displayed['keyboard']]
  keyboard=json.loads(displayed['keyboard'])['inline_keyboard']
  yes(keyboard[1][0]['text']=='نامحدود'and keyboard[2][0]['text']=='نامحدود',
      'PasarGuard_imported_customer_page_renders_actual_unlimited_date_and_quota')

  shown=preview(1002,mb_url+'/sub/'+mb_token)
  keyboard=json.loads(next(event['keyboard']for event in reversed(shown['events'])if event.get('keyboard')and'inline_keyboard'in event['keyboard']))['inline_keyboard']
  yes(all(keyboard[index][0]['text']=='نامحدود'for index in [3,5,6]),
      'Marzban_native_NULL_quota_and_expiry_preview_renders_literal_unlimited')
  yes('addToOrders2'in json.dumps(shown),'Marzban_URL_alone_preview_remains_available')
  call('manual',actor=1002,data='addToOrders2')
  yes(sql("SELECT userid,fileid,amount FROM orders_list WHERE remark='existing_mb_user'")=='1002\t0\t0',
      'Marzban_import_retains_same_zero_price_unassigned_plan_behavior')
  mb_order=int(sql("SELECT id FROM orders_list WHERE remark='existing_mb_user'"))
  displayed=call('view',actor=1002,owner=1002,order=mb_order)['out']
  unlimited_controls.append(displayed['keyboard'])
  keyboard=json.loads(displayed['keyboard'])['inline_keyboard']
  yes(keyboard[1][0]['text']=='نامحدود'and keyboard[2][0]['text']=='نامحدود'and
      sql(f'SELECT expire_date FROM orders_list WHERE id={mb_order}')=='0',
      'Marzban_imported_customer_page_never_exposes_zero_or_epoch_for_unlimited')
  yes(all(not any(callback in keys for callback in [
      'toggleAutoRenew','adminToggleAutoRenew','increaseADay','increaseAVolume'])
      for keys in unlimited_controls),
      'both_initial_unlimited_import_pages_hide_unsupported_autorenew_and_relative_actions')

  for padded in [False,True]:
   credential=legacy('existing_user_43',int(time.time())-30,padded)
   shown=preview(1004,url+'/native-subscriptions/'+credential)
   yes(state(1004)[1]['username']=='existing_user_43',
       'native_legacy_username_token_'+('padded'if padded else'unpadded')+'_uses_exact_lookup_and_signature_proof')
  shown=preview(1004,url+'/native-subscriptions/'+jwt_token('12345',int(time.time())-30))
  yes(state(1004)[1]['username']=='12345','native_legacy_JWT_numeric_username_is_not_mistaken_for_remote_ID')
  # A configured alias authorizes the input URL but never becomes a fetch
  # destination. The native endpoint remains the only token verifier.
  sql("UPDATE server_config SET sub_links='"+foreign_url+"' WHERE id=3")
  shown=preview(1004,foreign_url+'/native-subscriptions/'+panel.users[43]['subscription_url'].rsplit('/',1)[1])
  yes(state(1004)[1]['username']=='existing_user_43'and not foreign.trace,
      'explicit_subscription_origin_alias_is_proved_only_at_native_endpoint')
  sql("UPDATE server_config SET sub_links='' WHERE id=3")
  # Same URL and remote ID under a DIFFERENT configured physical instance is
  # ambiguity, not authority to select the first server.
  sql("INSERT INTO provider_panels(id,provider,instance_key,name,api_url,api_key,version)"
      " VALUES(2,'pasarguard',REPEAT('c',32),'Ambiguous physical fixture','"+url+"','fixture-private-key','5.5.1');"
      "INSERT INTO server_info(id,title,ucount,remark,flag,active,state,provider_panel_id,provider_group_ids)"
      " VALUES(5,'Ambiguous physical',20,'ambiguous','',1,1,2,'[2,5]');"
      "INSERT INTO server_config SELECT 5,panel_url,ip,sni,header_type,request_header,response_header,security,tlsSettings,type,username,password,port_type,reality,sub_links FROM server_config WHERE id=3")
  audit=sql('SELECT COUNT(*) FROM add_link');before=entities()
  preview(1004,panel.users[43]['subscription_url'])
  yes(entities()==before and sql('SELECT COUNT(*) FROM add_link')==audit,
      'distinct_physical_panel_matches_are_ambiguous_even_when_URL_and_ID_equal')
  sql('DELETE FROM server_config WHERE id=5;DELETE FROM server_info WHERE id=5;DELETE FROM provider_panels WHERE id=2')
  sql('INSERT INTO increase_plan(id,volume,price) VALUES(9979,1,100)')
  neutral=call('copy',key='payment.pre_payment.alert.fa55f5201f')['out']
  for target,actor,provider in [(unlimited_order,1003,'PasarGuard'),(mb_order,1002,'Marzban')]:
   for callback in ['increaseAVolume'+str(target),'increaseVolumePlan'+str(target)+'_9979']:
    before=financial();native_writes=len(mutations())
    response=call('extensions',actor=actor,data=callback)
    yes(any(e.get('text')==neutral for e in response['events'])and financial()==before and len(mutations())==native_writes,
        'actual_'+provider+'_unlimited_expiry_'+callback.split(str(target))[0]+'_refuses_neutrally_before_new_quote')
  for target,actor,plan,provider in [(unlimited_order,1003,8,'PasarGuard'),(mb_order,1002,7,'Marzban')]:
   sql('UPDATE server_plans SET server_id=3 WHERE id=8')
   sql(f'UPDATE orders_list SET fileid={plan} WHERE id={target}')
   before=financial();response=call('extensions',actor=actor,data='increaseVolumePlan'+str(target)+'_9979')
   yes(any(e.get('text')==neutral for e in response['events'])and financial()==before,
       'actual_'+provider+'_assigned_plan_unlimited_expiry_cannot_create_addon_quote')
   sql(f'UPDATE orders_list SET fileid=0 WHERE id={target}')

  for label,wrong in [
      ('signature',supplied[:-1]+('a'if supplied[-1]!='a'else'b')),
      ('foreign_origin',foreign_url+'/native-subscriptions/'+token),
      ('unknown_id',url+'/native-subscriptions/'+mint(999,int(time.time()))),
  ]:
   before=entities();audit=sql('SELECT COUNT(*) FROM add_link')
   preview(1004,wrong)
   yes(entities()==before and sql('SELECT COUNT(*) FROM add_link')==audit and not mutations(),
       'invalid_'+label+'_does_not_create_preview_credential_or_adoption')
  for mode in ['wrong_id','wrong_name','wrong_created']:
   panel.info_mode=mode;before=entities();audit=sql('SELECT COUNT(*) FROM add_link')
   preview(1004,panel.users[43]['subscription_url'])
   yes(entities()==before and sql('SELECT COUNT(*) FROM add_link')==audit,
       'native_public_info_'+mode+'_cannot_authorize_adoption')
  panel.info_mode=''
  before=entities();original_endpoint=url
  preview(1004,panel.users[43]['subscription_url'])
  sql("UPDATE server_config SET panel_url='"+foreign_url+"' WHERE id=3")
  call('manual',actor=1004,data='addToOrders3')
  yes(entities()==before and not foreign.trace,'changed_endpoint_after_preview_denies_without_contacting_new_target')
  sql("UPDATE server_config SET panel_url='"+url+"' WHERE id=3")

  for next_step,next_temp,label in [('none',None,'cancel'),('achbotManage|123456abcdef|0',{'owned':'newer'},'new_wizard')]:
   start(1004);before=entities();audit=sql('SELECT COUNT(*) FROM add_link')
   encoded='NULL'if next_temp is None else "CONVERT(UNHEX('"+json.dumps(next_temp).encode().hex()+"') USING utf8mb4)"
   panel.on_read=lambda ns=next_step,nt=encoded:sql(f"UPDATE users SET step='{ns}',temp={nt} WHERE userid=1004")
   call('external',actor=1004,text=panel.users[43]['subscription_url'])
   yes(state(1004)==(next_step,next_temp)and entities()==before and sql('SELECT COUNT(*) FROM add_link')==audit,
       'concurrent_'+label+'_cannot_be_overwritten_by_delayed_native_preview')
   sql("UPDATE users SET step='none',temp=NULL WHERE userid=1004")

  # Local INSERT failure rolls the order and both adopt evidence rows
  # back together; a retry observes no orphan identity or fake CREATE.
  preview(1004,panel.users[43]['subscription_url']);before=entities()
  sql("CREATE TRIGGER owned_import_reject BEFORE INSERT ON provider_accounts FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='owned_fixture_reject'")
  call('manual',actor=1004,data='addToOrders3')
  yes(entities()==before and not mutations(),'failed_adopt_account_insert_rolls_back_order_and_effect_atomically')
  sql('DROP TRIGGER owned_import_reject')
  call('manual',actor=1004,data='addToOrders3')
  yes(sql("SELECT COUNT(*) FROM orders_list WHERE remark='existing_user_43'")=='1',
      'rolled_back_adoption_can_complete_once_without_orphan_evidence')

  before=financial();trace_start=len(panel.trace);shown=call('view',actor=1001,owner=1001,order=order)
  page=panel.trace[trace_start:]
  yes(sum(r[0]=='GET'and r[1]=='/api/user/by-id/41'for r in page)==2 and
      sum(r[0]=='GET'and r[1]=='/api/user/41/subscription/links'for r in page)==1,
      'actual_native_customer_page_uses_exactly_two_identity_GETs_and_one_links_request')
  old_date=json.loads(shown['out']['keyboard'])['inline_keyboard'][1][0]['text']
  panel.users[41]['expire']='2036-07-18T13:20:00Z'
  refreshed=call('view',actor=1001,owner=1001,order=order)['out']
  expected=call('date',expiry=2100000000)['out']
  yes(json.loads(refreshed['keyboard'])['inline_keyboard'][1][0]['text']==expected and expected!=old_date and
      sql(f'SELECT expire_date FROM orders_list WHERE id={order}')=='2000000000',
      'actual_customer_page_refreshes_external_native_expiry_without_rewriting_local_order')
  yes(shown['out']is not None and 'vless://existing-native-41'in json.dumps(shown)and '/native-subscriptions/'in json.dumps(shown),
      'imported_customer_view_reads_real_native_configs_and_subscription')
  shown=call('view',actor=1002,owner=1001,order=order)
  yes(shown['out']is None,'foreign_actor_cannot_read_imported_customer_details')
  yes(financial()==before,'imported_detail_views_are_financially_read_only')
  # The visible refresh callback is a real planless controller journey.
  # Native link changes are fixture setup, not provider writes by the bot.
  panel.link_labels[41]='refreshed-native-41'
  marzban.edit_account['links']=['vless://refreshed-native-mb@host.invalid:443']
  finance=financial();writes_before=len(mutations())
  refreshed=call('customer',actor=1001,data='updateConfigConnectionLink'+str(order))
  yes('refreshed-native-41'in sql(f'SELECT link FROM orders_list WHERE id={order}')and
      financial()==finance and len(mutations())==writes_before,
      'actual_planless_PasarGuard_update_link_controller_reads_native_links_without_remote_or_financial_write')
  mb_writes=len([r for r in marzban.trace if r['method']in('PUT','DELETE')or(r['method']=='POST'and not r['path'].endswith('/api/admin/token'))])
  refreshed=call('customer',actor=1002,data='updateConfigConnectionLink'+str(mb_order))
  yes('refreshed-native-mb'in sql(f'SELECT link FROM orders_list WHERE id={mb_order}')and
      financial()==finance and mb_writes==len([r for r in marzban.trace if r['method']in('PUT','DELETE')or(r['method']=='POST'and not r['path'].endswith('/api/admin/token'))]),
      'actual_planless_Marzban_update_link_controller_has_no_plan_dereference_or_native_mutation')
  before=entities();requests=len(panel.trace)
  call('customer',actor=1004,cached_admin=True,data='updateConfigConnectionLink'+str(order))
  yes(entities()==before and len(panel.trace)==requests,
      'update_link_callback_rechecks_fresh_role_and_denies_cached_revoked_admin')
  refreshed=call('customer',actor=9001,data='updateConfigConnectionLink'+str(order))
  yes(any(e['kind']=='edit'for e in refreshed['events'])and financial()==finance,
      'authorized_admin_refresh_of_foreign_import_uses_real_admin_details_redraw')

  before_note=panel.users[41]['note'];before_groups=copy.deepcopy(panel.users[41]['group_ids'])
  old_expiry=int(datetime.fromisoformat(panel.users[41]['expire'].replace('Z','+00:00')).timestamp())
  result=call('edit',order=order,operation='fixture:adopt-volume',change={'plus_volume':1})['out']
  yes(result['success']and result['verified']and result['applied_bytes']==3*GIB and result['applied_expire']==old_expiry,
      'imported_identity_supports_verified_common_volume_extension')
  write_count=len(mutations());result=call('edit',order=order,operation='fixture:adopt-volume',change={'plus_volume':1})['out']
  yes(not result['success']and len(mutations())==write_count,'imported_volume_source_cannot_replay_native_effect')
  result=call('edit',order=order,operation='fixture:adopt-days',change={'plus_day':2})['out']
  yes(result['success']and result['applied_expire']==old_expiry+172800,'imported_identity_supports_verified_common_time_extension')
  result=call('edit',order=order,operation='fixture:adopt-renewal',change={'days':30,'volume':3})['out']
  yes(result['success']and result['verified']and result['applied_bytes']==3*GIB and panel.users[41]['used_traffic']==0,
      'imported_identity_supports_existing_verified_native_full_replacement_seam')
  result=call('action',order=order,action='toggle')['out']
  yes(result['success']and panel.users[41]['status']=='disabled','imported_identity_supports_native_toggle_with_shared_fence')
  result=call('action',order=order,action='toggle')['out']
  yes(result['success']and panel.users[41]['status']=='active','imported_identity_supports_distinct_native_reenable_source')
  yes(panel.users[41]['note']==before_note and panel.users[41]['group_ids']==before_groups and financial()==base_finance,
      'native_management_preserves_customer_note_groups_and_all_financial_rows')
  panel.users[41]['used_traffic']=panel.users[41]['data_limit']-100000000
  result=call('observe',order=order)['out']
  yes(result['observation']['verified']and result['policy']['warning']=='quota'and result['policy']['renew'],
      'imported_service_uses_shared_native_lifecycle_observation')
  generation=panel.users[41]['created_at'];panel.users[41]['created_at']='2026-10-28T12:00:00Z'
  result=call('observe',order=order)['out']
  yes(not result['observation']['verified'],'recreated_remote_id_is_not_the_adopted_native_incarnation')
  panel.users[41]['created_at']=generation

  result=call('created_identity')['out']
  yes(result is False,'created_identity_still_requires_its_original_remote_creation_note_marker')
  # Local evidence is the only authority for adopted identity; changing an
  # adopt effect into CREATE must fail before a provider read.
  sql(f"UPDATE service_effects SET effect_kind='create' WHERE order_id={order}")
  requests=len(panel.trace);result=call('observe',order=order)['out']
  yes(not result['observation']['verified']and len(panel.trace)==requests,
      'forged_adopt_marker_without_genuine_adopt_effect_has_no_native_read_authority')
  sql(f"UPDATE service_effects SET effect_kind='adopt' WHERE order_id={order}")
  sql(f"INSERT INTO pays(id,hash_id,user_id,type,plan_id,server_id,order_id,volume,day,price,request_date,state)"
      f" VALUES(901,'owned_import_quote',1001,'INCREASE_DAY_{order}_1',0,3,{order},0,2,100,1700000000,'pending')")
  quoted=call('quote',pay=901)['out'];pin=quoted['snapshot']
  yes(quoted['eligible']and pin['remote_user_id']==41 and
      pin['operation_marker'].startswith('achbot:adopt:')and
      pin['remote_created_at']=='2026-10-26T05:00:00.123456Z',
      'imported_native_identity_is_pinned_by_actual_common_quote_contract')
  # Exercise the actual customer routes with fileid=0: no nonexistent plan
  # dereference, fake verified flags, remote re-creation or capacity inflation.
  before_proxy=copy.deepcopy(panel.users[42]['proxy_settings'])
  call('customer',actor=1003,data='changAccountConnectionLink'+str(unlimited_order))
  yes(panel.users[42]['proxy_settings']!=before_proxy and
      sql(f'SELECT fileid FROM orders_list WHERE id={unlimited_order}')=='0',
      'actual_customer_PasarGuard_regenerate_controller_supports_planless_import')
  saved_native=copy.deepcopy(marzban.edit_account)
  for mode in ['malformed','case_only']:
   marzban.revoke_mode=mode;before=entities();posts=marzban.revoke_count
   denied=call('customer',actor=1002,data='changAccountConnectionLink'+str(mb_order))
   if not(entities()==before and marzban.revoke_count==posts+1):
    raise AssertionError(('native_revoke_negative',mode,entities()==before,marzban.revoke_count-posts,denied))
   yes(entities()==before and marzban.revoke_count==posts+1,
       'actual_Marzban_regenerate_'+mode+'_UUID_is_unverified_without_local_success_or_retry')
   marzban.edit_account=copy.deepcopy(saved_native)
  marzban.revoke_mode='valid'
  old_token=marzban.edit_account['subscription_url'];old_proxy=copy.deepcopy(marzban.edit_account['proxies']);marzban.fixed_clock=int(time.time())+1
  regenerated=call('customer',actor=1002,data='changAccountConnectionLink'+str(mb_order))
  yes(marzban.edit_account['proxies']!=old_proxy and marzban.edit_account['subscription_url']==old_token and len(set(marzban.returned_tokens[-2:]))==1 and
      sql(f'SELECT link FROM orders_list WHERE id={mb_order}').find('22222222-2222-4222-8222-')>=0,
      'actual_customer_Marzban_regenerate_verifies_proxy_rotation_even_with_unchanged_native_token')
  yes(sql(f'SELECT token FROM orders_list WHERE id={mb_order}')==marzban.returned_tokens[-1]and
      not sql(f'SELECT token FROM orders_list WHERE id={mb_order}').startswith('http'),
      'actual_Marzban_revoke_consumer_persists_only_token_from_verified_absolute_native_URL')
  for target,actor,provider in [(unlimited_order,1003,'PasarGuard'),(mb_order,1002,'Marzban')]:
   capacity=sql('SELECT id,ucount FROM server_info ORDER BY id')
   wallet=sql('SELECT userid,wallet FROM users ORDER BY id')
   call('delete',actor=actor,data='yesDeleteConfig'+str(target))
   yes(sql(f'SELECT COUNT(*) FROM orders_list WHERE id={target}')=='0'and
       sql('SELECT id,ucount FROM server_info ORDER BY id')==capacity and
       sql('SELECT userid,wallet FROM users ORDER BY id')==wallet,
       'actual_customer_'+provider+'_planless_import_delete_is_finalized_without_capacity_or_wallet_inflation')
   deletes=len([r for r in panel.trace if r[0]=='DELETE'])if provider=='PasarGuard'else len([r for r in marzban.trace if r['method']=='DELETE'])
   call('delete',actor=actor,data='yesDeleteConfig'+str(target))
   yes(deletes==(len([r for r in panel.trace if r[0]=='DELETE'])if provider=='PasarGuard'else len([r for r in marzban.trace if r['method']=='DELETE'])),
       'actual_customer_'+provider+'_delete_replay_has_no_second_native_DELETE')
  yes(42 not in panel.users and marzban.deleted and
      sql(f'SELECT state FROM provider_accounts WHERE order_id={unlimited_order}')=='deleted',
      'actual_customer_deletion_retains_native_import_identity_and_audit_evidence')
  # Later plan assignment cannot turn adoption provenance into CREATE capacity.
  assigned=int(sql("SELECT id FROM orders_list WHERE remark='existing_user_43'"))
  sql(f'UPDATE server_plans SET server_id=3 WHERE id=8;UPDATE orders_list SET fileid=8 WHERE id={assigned}')
  yes(call('capacity',order=assigned)['out']is False and
      call('capacity',order=order,plan=7,id_override=999999)['out']is True,
      'adopt_origin_survives_plan_assignment_while_normal_positive_plan_keeps_capacity_restoration')
  capacity=sql('SELECT id,ucount FROM server_info ORDER BY id')
  call('delete',actor=1004,data='yesDeleteConfig'+str(assigned))
  yes(sql(f'SELECT COUNT(*) FROM orders_list WHERE id={assigned}')=='0'and
      sql('SELECT id,ucount FROM server_info ORDER BY id')==capacity,
      'actual_assigned_plan_import_delete_retains_zero_capacity_restoration')

  # Unknown persisted capacity evidence is reconciliation, never permission
  # to delete a fenced order or silently discard its capacity entitlement.
  sql(f"UPDATE orders_list SET status=0,fileid=7 WHERE id={order};UPDATE service_effects SET effect_kind='malformed' WHERE order_id={order}")
  capacity=sql('SELECT id,ucount FROM server_info ORDER BY id');finance=financial();requests=len(panel.trace)
  finalized=call('finalize_capacity',order=order,server=3)['out']
  yes(finalized is False and sql(f'SELECT status FROM orders_list WHERE id={order}')=='0'and
      sql('SELECT id,ucount FROM server_info ORDER BY id')==capacity,
      'malformed_present_capacity_ledger_holds_fenced_order_and_capacity_for_reconciliation')
  yes(financial()==finance and len(panel.trace)==requests,
      'capacity_reconciliation_hold_has_no_financial_or_native_side_effect')
  sql(f"UPDATE orders_list SET status=1,fileid=0 WHERE id={order};UPDATE service_effects SET effect_kind='adopt' WHERE order_id={order}")

  # Public internal module remains a guarded implementation, without DB or
  # private configuration bootstrap on direct invocation.
  with socket.socket()as reserved:reserved.bind(('127.0.0.1',0));port=reserved.getsockname()[1]
  http=subprocess.Popen(['php','-S','127.0.0.1:'+str(port),'-t',str(ROOT)],
      cwd=work,env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
  try:
   status=None;body=b''
   for attempt in range(50):
    try:
     response=urllib.request.urlopen('http://127.0.0.1:'+str(port)+'/src/Panel/ProviderImport.php',timeout=1)
     status=response.status;body=response.read();break
    except urllib.error.HTTPError as error:status=error.code;body=error.read();break
    except urllib.error.URLError:time.sleep(.02)
   yes(status==404 and body==b'','actual_internal_import_module_direct_HTTP_is_private_404')
  finally:http.terminate();http.wait(timeout=5)

  yes(not panel.violations and not foreign.trace and all(pair==(None,None)for pair in panel.public_credentials),
      'native_import_uses_only_exact_API_lookup_and_credential_free_trusted_token_info')
  yes(not any(r[1]=='/api/user'and r[0]=='POST'for r in panel.trace),
      'no_remote_CREATE_or_note_claim_during_existing_service_import')
  yes(all(not any(private in value for private in ['PRIVATE_EXISTING_NOTE','fixture-private-key','RuntimeException','/api/user/'])
      for value in customer_outputs),'actual_customer_import_and_page_messages_never_expose_native_private_diagnostics')
  report=dict(complete=True,cases=len(checks),checks=checks,actual_php=True,owned_socket_mariadb=True,
      loopback_native_pasar_guard=True,real_telegram_tested=False,real_provider_tested=False,
      official_source_pin=PIN,URL_only_common_admin_and_customer=True,
      rotating_native_token_verified=True,genuine_local_adoption=True,
      import_remote_mutations=0,import_financial_mutations=0,no_new_schema=True,
      optional_import_pair_all_or_none=True,missing_import_capability_preserves_core=True,
      marzban_usable_without011=True,direct_module_http404=True,
      original23_marzban_import_verified=True,marzban007_without011_adoption_verified=True,
      marzban_adopt_assigned_plan_capacity_preserved=True,
      native_quote_and_delete_identity=True,both_provider_unlimited_preview_and_view=True,
      exact_native_page_request_count=True,external_native_expiry_refresh=True,
      legacy_native_token_forms=True,marzban_legacy_JWT_native_signature_verified=True,adoption_rollback_atomic=True,
      actual_planless_customer_regenerate_and_delete=True,
      actual_planless_update_link_controller_verified=True,
      verified_marzban_revoke_credential_negatives=True,
      unlimited_addon_entry_neutral_and_effect_free=True,
      adopted_capacity_origin_preserved=True,delete_replay_safe=True,
      malformed_capacity_ledger_reconciliation_hold=True,
      initial_import_controls_match_supported_actions=True,
      native_origin_alias_and_ambiguity_verified=True,created_marker_policy_preserved=True,
      external_requests=0,production_mutations=0)
 shared_registration_and_paid_journey('marzban')
 shared_registration_and_paid_journey('pasarguard')
 report.update(cases=len(checks),shared_native_registration_for_multiple_users=True,
     genuine_import_plan_confirm_paid_renewal=True,shared_native_auto_renew_debits_once=True,concurrent_shared_native_auto_renew_verified=True,shared_payment_owner_and_replay_verified=True,
     shared_native_views_coherent=True,shared_physical_anchor_history_preserved=True,
     shared_native_action_views_coherent=True,known_native_guard_preclaim_no_poison=True)
 if args.report:args.report.write_text(json.dumps(report,indent=2)+'\n')
 print(json.dumps(report))
if __name__=='__main__':main()
