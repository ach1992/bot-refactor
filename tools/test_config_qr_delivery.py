#!/usr/bin/env python3
"""AChBot config QR caption/copy-pair regression on actual PHP dispatch/helper.

Owned socket-only MariaDB, local QR encoder spy and synthetic Telegram boundary.
Actual Marzban order route plus provider-neutral native presentation are composed;
PasarGuard native identity/HTTP integration is covered by provider integration.
"""
from __future__ import annotations
import argparse,base64,html,json,subprocess
from pathlib import Path
import test_access_state as owned
import test_v6_native as native
import test_v8_native as purchase
import test_v6_panels as bodies

ROOT=Path(__file__).resolve().parents[1]
BASE='c9959811e264b5a8728f6a44376cb69c9a89b7f4'
PHP=r'''<?php
error_reporting(E_ALL);
set_error_handler(static function($n,$m){if($n===E_DEPRECATED)return true;throw new ErrorException($m,0,$n);});
$_SERVER['SCRIPT_FILENAME']=__FILE__;
$root=$argv[1];$spec=json_decode($argv[4],true,64,JSON_THROW_ON_ERROR);
$connection=new mysqli('localhost','root','',$argv[3],0,$argv[2]);$connection->set_charset('utf8mb4');
$events=[];$qr_payloads=[];$files=[];$from_id=(int)($spec['actor']??1001);$admin=9001;
$botUrl='https://bot.example.invalid/';$message_id=1;$data=$spec['data']??'showQrConfig91';$text='';
$mainValues=['config_not_found'=>'QR delivery was not confirmed'];$buttonValues=['back_to_main'=>'Back'];
$botState=['configLinkState'=>'on','subLinkState'=>'on'];$userInfo=['isAdmin'=>0,'step'=>'none'];
class QRcode {
 public static function png($payload,$path,...$rest){
  $GLOBALS['qr_payloads'][]=$payload;$GLOBALS['files'][]=$path;file_put_contents($path,'owned-qr-fixture');
 }
}
function RandomString(){return 'owned-qr-'.bin2hex(random_bytes(8));}
function sendPhoto($photo,$caption=null,$keyboard=null,$parse=null,$to=null,...$rest){
 $path=$photo instanceof CURLFile?$photo->getFilename():(string)$photo;
 $GLOBALS['events'][]=['kind'=>'photo','caption'=>$caption,'parse'=>$parse,'to'=>$to,'file_present'=>is_file($path)];
 $mode=$GLOBALS['spec']['photo_mode']??'ok';
 if($mode==='throw')throw new RuntimeException('owned_photo_unknown');
 return $mode==='null'?null:(object)['ok'=>$mode!=='false'];
}
function sendMessage($text,$keys=null,$parse=null,$to=null,...$rest){
 $GLOBALS['events'][]=['kind'=>'text','text'=>$text,'parse'=>$parse,'to'=>$to];
 $mode=$GLOBALS['spec']['text_mode']??'ok';
 if($mode==='throw')throw new RuntimeException('owned_text_unknown');
 return $mode==='null'?null:(object)['ok'=>$mode!=='false'];
}
function alert($text,$modal=false){$GLOBALS['events'][]=['kind'=>'alert','text'=>$text,'modal'=>$modal];}
require $root.'/buy_helpers.php';
require __DIR__.'/actual-presentation-facade.php';
try {
 if(($spec['op']??'route')==='pure'){
  $p=\AchBot\Subscription\Presentation\present($spec['provider'],['links'=>$spec['links']],$botState,['policy'=>[]]);
  $out=[];foreach($p['qr_config_links']as$uri)$out[]=['uri'=>$uri,
   'caption'=>\AchBot\Subscription\Presentation\configQrCaption($uri,$p),
   'text'=>\AchBot\Subscription\Presentation\configQrText($uri)];
 }else{
  require $spec['route'];$out=null;
 }
 echo json_encode(['out'=>$out,'events'=>$events,'qr_payloads'=>$qr_payloads,
   'temp_files_remaining'=>count(array_filter($files,'is_file'))],JSON_THROW_ON_ERROR);
}catch(Throwable$error){fwrite(STDERR,get_class($error).':'.$error->getMessage());exit(1);}
'''

def main():
 p=argparse.ArgumentParser();p.add_argument('--run-disposable',action='store_true',required=True);p.add_argument('--report',type=Path);a=p.parse_args()
 checks=[]
 def yes(ok,name):
  if not ok:raise AssertionError(name)
  checks.append(name)
 with owned.owned_database()as(work,env):
  purchase.init(work,env,'http://127.0.0.1:1');db=purchase.SIDE[1]
  driver=work/'config-qr.php';driver.write_text(PHP)
  facade=bodies.function_bodies((ROOT/'config.php').read_bytes(),['buildSubscriptionPresentation'])
  (work/'actual-presentation-facade.php').write_bytes(facade.replace(b'__DIR__',repr(str(ROOT)).encode()))
  baseline=work/'old-checkout.php';baseline.write_bytes(subprocess.check_output(['git','show',BASE+':src/Finance/Routes/Checkout.php'],cwd=ROOT).replace(b'__DIR__',repr(str(ROOT/'src/Finance/Routes')).encode()))
  def sql(query):return native.sql(work,env,query,db).strip()
  def snapshot():return sql('SELECT * FROM orders_list ORDER BY id;SELECT * FROM pays ORDER BY id;SELECT userid,wallet FROM users ORDER BY id;SELECT id,ucount FROM server_info ORDER BY id')
  def call(**spec):
   spec.setdefault('route',str(ROOT/'src/Finance/Routes/Checkout.php'))
   run=subprocess.run(['php','-d','display_errors=0','-d','log_errors=0',str(driver),str(ROOT),str(work/'mysql.sock'),db,json.dumps(spec)],cwd=work,env=env,capture_output=True,text=True,timeout=15)
   allowed=['achbot_qr_photo_unconfirmed class=RuntimeException','achbot_account_text_unconfirmed class=RuntimeException']
   if run.returncode or any(line not in allowed for line in run.stderr.splitlines()):raise AssertionError((run.returncode,run.stdout,run.stderr))
   return json.loads(run.stdout)
  def seed(links):
   raw=json.dumps(links,ensure_ascii=False).encode().hex()
   sql("DELETE FROM orders_list;INSERT INTO orders_list(id,userid,token,transid,fileid,server_id,inbound_id,remark,uuid,expire_date,link,amount,status,date) VALUES(91,1001,'owned-token','',7,2,0,'owned_qr','native',2000000000,CONVERT(UNHEX('"+raw+"') USING utf8mb4),100,1,'1700000000')")
  vmess='vmess://'+base64.b64encode(json.dumps({'ps':'🇮🇷 IR <CDN> & TLS','id':'owned'}).encode()).decode()
  named='trojan://owned@host.invalid:443?security=tls&type=ws#'+ '%F0%9F%87%AE%F0%9F%87%B7%20IR%20%3CCDN%3E%20%26%20TLS'
  unnamed='vless://owned@host.invalid:443?type=grpc&serviceName=literal'
  links=[named,vmess,unnamed];seed(links);before=snapshot()
  current=call();ev=current['events']
  yes([e['kind']for e in ev]==['photo','text']*3,'actual_order_route_sends_one_captioned_photo_then_one_copyable_message_per_config')
  expected=['🇮🇷 IR <CDN> & TLS','🇮🇷 IR <CDN> & TLS','VLESS']
  yes([html.unescape(e['caption'])for e in ev if e['kind']=='photo']==expected,'fragment_and_vmess_native_names_with_emoji_HTML_and_missing_name_fallback')
  yes(all(e['file_present']and e['parse']=='HTML'and e['to']==1001 for e in ev if e['kind']=='photo'),'actual_helper_uploads_existing_local_PNG_with_safe_HTML_caption_to_actor')
  yes([html.unescape(e['text'][11:-13])for e in ev if e['kind']=='text']==links and
      all(e['text'].startswith('<pre><code>')and e['text'].endswith('</code></pre>')and e['parse']=='HTML'for e in ev if e['kind']=='text'),
      'copyable_preformatted_configs_roundtrip_exact_original_URI_bytes')
  yes(current['qr_payloads']==links and current['temp_files_remaining']==0 and snapshot()==before,'QR_payloads_exact_temp_cleanup_and_zero_order_wallet_payment_capacity_mutation')
  old=call(route=str(baseline))
  yes(not any(e['kind']=='text'for e in old['events'])and all(not e['caption']for e in old['events']if e['kind']=='photo'),
      'archived_c995981_actual_route_RED_has_no_config_name_or_copy_message')
  denied=call(actor=9002)
  yes(not denied['qr_payloads']and all(e['kind']=='alert'for e in denied['events'])and snapshot()==before,'foreign_user_callback_denied_without_QR_or_secret_text')
  for provider in ['marzban','pasarguard']:
   out=call(op='pure',provider=provider,links=[{'uri':unnamed,'name':'Native metadata <name> & 🇮🇷'}])['out']
   yes(len(out)==1 and html.unescape(out[0]['caption'])=='Native metadata <name> & 🇮🇷'and html.unescape(out[0]['text'][11:-13])==unnamed,
       provider+'_provider_neutral_presentation_preserves_explicit_native_metadata_name')
  long_uri='vless://owned@host.invalid:443?path='+('a'*1750)+'#Long'
  seed([long_uri]);out=call()
  yes(out['qr_payloads']==[long_uri]and html.unescape(out['events'][1]['text'][11:-13])==long_uri,
      'long_complete_URI_never_truncated_in_QR_or_copyable_text')
  long_name='vless://owned@host.invalid:443#'+('a'*700)
  out=call(op='pure',provider='pasarguard',links=[long_name])['out'][0]
  yes(len(html.unescape(out['caption']))==201 and html.unescape(out['text'][11:-13])==long_name,
      'long_caption_is_bounded_separately_without_modifying_config')
  seed([named,unnamed]);before=snapshot()
  for target in ['photo','text']:
   for mode in ['false','null','throw']:
    out=call(**{target+'_mode':mode})
    yes(len(out['qr_payloads'])==2 and out['temp_files_remaining']==0 and
        len([e for e in out['events']if e['kind']=='alert'])==1 and snapshot()==before,
        target+'_'+mode+'_reported_once_without_automatic_retry_financial_write_or_temp_leak')
 report=dict(complete=True,cases=len(checks),checks=checks,actual_php=True,owned_socket_mariadb=True,
  actual_Checkout_dispatch=True,actual_sendQrFromLink=True,provider_neutral_native_names_both=True,
  actual_PasarGuard_identity_HTTP_here=False,archived_route_RED=True,baseline=BASE,
  unchanged_URI_bytes=True,temp_cleanup=True,financial_mutations=0,external_requests=0,production_mutations=0,
  real_telegram_rendering_tested=False)
 if a.report:a.report.write_text(json.dumps(report,indent=2)+'\n')
 print(json.dumps({k:v for k,v in report.items()if k!='checks'}))
if __name__=='__main__':main()
