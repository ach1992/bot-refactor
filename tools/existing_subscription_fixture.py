"""PasarGuard existing-subscription source-real import fixture."""
from __future__ import annotations
import base64,contextlib,copy,hashlib,hmac,json,threading,time
from datetime import datetime
from http.server import BaseHTTPRequestHandler,ThreadingHTTPServer
from urllib.parse import unquote,urlsplit,parse_qs
import test_post_v9_pasarguard_client as pg

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
  # Live PasarGuard plan access verifies current version, administrator
  # authority and active Group catalogue before approving a selected plan.
  # Use the same 5.5.1 schema as the independent native panel fixture.
  if path=='/api/system':
   self.reply(200,{'version':'5.5.1'});return
  if path=='/api/admin':
   actions={action:{'scope':1} for action in
     ['read','create','update','delete','reset_usage','revoke_sub']}
   self.reply(200,{'role':{'is_owner':False,'access':{'require_template':False,
       'allowed_group_ids':getattr(s,'allowed_group_ids',[2,5])},
       'permissions':{'users':actions,'groups':{'read':True,'read_simple':True}}}});return
  if path=='/api/groups':
   query=parse_qs(urlsplit(self.path).query)
   offset=int(query.get('offset',['0'])[0]);limit=int(query.get('limit',['50'])[0])
   ids=getattr(s,'available_group_ids',[2,5])
   self.reply(200,{'groups':[{'id':gid,'name':'fixture_group',
       'is_disabled':False,'inbound_tags':['native_tag']}
       for gid in ids[offset:offset+limit]],'total':len(ids)});return
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
   if 'group_ids' in payload and getattr(s,'access_put_usage_override',None) is not None:
    # Post-V9 opt-in negative Native fixture: simulate a broken vendor
    # resetting consumption as a side effect of a Group-only PUT.
    user['used_traffic']=s.access_put_usage_override
    s.access_put_usage_override=None
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


PHP=r'''<?php
error_reporting(E_ALL);
set_error_handler(static function($n,$m){
 if($n===E_DEPRECATED && empty($GLOBALS['spec']['strict_deprecations']))return true;
 throw new ErrorException($m,0,$n);
});
$_SERVER['SCRIPT_FILENAME']=__FILE__;define('ACHBOT_PG_TEST_LOOPBACK',true);
$root=$argv[1];$spec=json_decode($argv[4],true,64,JSON_THROW_ON_ERROR);
$connection=new mysqli('localhost','root','',$argv[3],0,$argv[2]);$connection->set_charset('utf8mb4');
$events=[];$emitted=false;
register_shutdown_function(static function(){if(!$GLOBALS['emitted'])echo json_encode(['events'=>$GLOBALS['events']],JSON_THROW_ON_ERROR);});
require_once $root.'/src/Purchase/Core.php';
require_once $root.'/settings/jdf.php';
require_once __DIR__.'/facades.php';
$admin=9001;$botUrl='https://bot.example.invalid/';
$telegramWebhookSecret='FixtureImportReportSecret2026';$botState=array_fill_keys(['searchState','renewAutoState','sellState','renewAccountState','switchLocationState',
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
if(array_key_exists('cached_temp',$spec))$userInfo['temp']=$spec['cached_temp'];
function sendMessage($text,$keyboard=null,$parse=null,$to=null){
 $GLOBALS['events'][]=['kind'=>'send','text'=>$text,'keyboard'=>$keyboard,'to'=>$to];
 return(object)['ok'=>$GLOBALS['spec']['telegram_ok']??true,
  'result'=>(object)['message_id'=>++$GLOBALS['owned_message_id'],
  'chat'=>(object)['id'=>$to??$GLOBALS['from_id']]]];
}
function alert($text,$modal=false){$GLOBALS['events'][]=['kind'=>'alert','text'=>$text,'modal'=>$modal];}
function editText($id,$text,$keyboard=null,...$rest){$GLOBALS['events'][]=['kind'=>'edit','text'=>$text,'keyboard'=>$keyboard];return(object)['ok'=>true];}
function delMessage(...$a){$GLOBALS['events'][]=['kind'=>'delete'];}
function answerCallbackQuery($id,$text=null,...$rest){$GLOBALS['events'][]=['kind'=>'answer','text'=>$text];}
function bot(...$a){return(object)['ok'=>true,'result'=>(object)['first_name'=>'Owned']];}
function achbotSupportsPanel($type){return achbotSupportsProviderType($type);}
function getMainKeys(){return'{}';}
function getServerListKeys(...$a){return'{}';}
try {
 $out=null;$op=$spec['op'];
 if($op==='external')require $root.'/src/Subscription/ExternalAccounts.php';
 elseif($op==='manual'){
  require $root.'/src/Subscription/ExternalAccounts.php';
  require $root.'/src/Subscription/ManualAccounts.php';
 } elseif($op==='transfer'){
  require_once $root.'/src/Subscription/PhysicalService.php';
  $out=achbotReassignLogicalServer($connection,(int)$spec['order'],
      (int)$spec['target'],(int)$from_id,true);
 } else throw new RuntimeException('fixture_op_missing');
 $emitted=true;echo json_encode(['events'=>$events,'out'=>$out],JSON_THROW_ON_ERROR);
}catch(Throwable$error){$emitted=true;echo json_encode(['fixture_error'=>get_class($error).':'.$error->getMessage(),'events'=>$events],JSON_THROW_ON_ERROR);}
'''

import notification_fixture
PHP=notification_fixture.with_router(PHP)
