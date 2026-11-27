"""Isolated source-real subscription presentation fixture."""
PHP=r'''<?php
error_reporting(E_ALL);
set_error_handler(static function($n,$m){if($n===E_DEPRECATED)return true;throw new ErrorException($m,0,$n);});
$_SERVER['SCRIPT_FILENAME']=__FILE__;
$spec=json_decode($argv[4],true);$root=$argv[1];
require_once $root.'/messages/loader.php';
$mainValues=achbotMessages('legacy_messages');
$buttonValues=achbotMessages('legacy_buttons');
$connection=new mysqli('localhost','root','',$argv[3],0,$argv[2]);$connection->set_charset('utf8mb4');
$from_id=(int)($spec['actor']??1001);$admin=9001;$botUrl='https://bot.example.invalid/';
$q=$connection->prepare('SELECT * FROM users WHERE userid=?');$q->bind_param('i',$from_id);$q->execute();$userInfo=$q->get_result()->fetch_assoc()??['step'=>'none','isAdmin'=>0,'is_agent'=>0,'freetrial'=>null];$q->close();
$botState=array_fill_keys(['renewAutoState','sellState','renewAccountState','switchLocationState','increaseVolumeState','increaseTimeState','renewConfigLinkState','updateConfigLinkState','qrConfigState','qrSubState','subLinkState','configLinkState'],'on');
$botState['plandelkhahState']='off';$botState['agencyPlanDiscount']='off';
$botState['renewAutoState']=$spec['renew_auto_state']??'on';
$data=$spec['data']??'';$text='';$message_id=1;$cancelKey='{}';$removeKeyboard='{}';$time=time();
$GLOBALS['response_emitted']=false;register_shutdown_function(static function(){if(!$GLOBALS['response_emitted'])echo json_encode(['out'=>$GLOBALS['out']??$GLOBALS['events'],'reads'=>$GLOBALS['reads'],'presentations'=>$GLOBALS['presentations']],JSON_THROW_ON_ERROR);});
$GLOBALS['reads']=0;$GLOBALS['presentations']=0;$GLOBALS['events']=[];
function achbotSupportsProviderType($type){return $type==='pasarguard';}
// Stub ONLY the separate Native mutation-admission seam. The source-real
// provider integration exercises actual PasarGuard mutating fences.
function achbotSupportsOrder($db,$orderId,$planId=null,$userId=null,$lock=false){return $orderId===91;}
// This composition harness controls native packets and the plan-admission
// seam. Actual adapter eligibility/import/payment are tested separately by
// current Native import, customer-route and Wallet-first service suites.
function achbotServerCommerceAvailable($db,$serverId,$newPurchase,$lock=false) {
 $q=$db->prepare('SELECT active,state FROM server_info WHERE id=?');
 $q->bind_param('i',$serverId);$q->execute();$row=$q->get_result()->fetch_assoc();$q->close();
 return $row && (int)$row['active']===1 && ($newPurchase?(int)$row['state']===1:in_array((int)$row['state'],[1,2],true));
}
function achbotSupportsPlan($db,$id,$server=null,$lock=false,$allowInactive=false) {
 $q=$db->prepare('SELECT p.server_id,p.active,c.type FROM server_plans p JOIN server_config c ON c.id=p.server_id WHERE p.id=?');
 $id=(int)$id;$q->bind_param('i',$id);$q->execute();$row=$q->get_result()->fetch_assoc();$q->close();
 return $row && ($allowInactive || (int)$row['active']===1) && achbotSupportsProviderType($row['type']) &&
  ($server===null || (int)$server===(int)$row['server_id']);
}
function achbotSameProviderPanel($db,$first,$second){return (int)$first===(int)$second;}
require __DIR__.'/actual-service-unit-helpers.php';
function achbotProviderSqlTypes(){return "'pasarguard'";}
function achbotProviderUser($server,$remark,$withLinks=true){++$GLOBALS['reads'];if($withLinks)throw new RuntimeException('unexpected_link_read');return json_decode(json_encode($GLOBALS['spec']['remote']??null));}
function achbotProviderRelocationTargets($db,$server){return $GLOBALS['spec']['relocations']??[];}
function achbotProviderRenderPacket($db,$server,$remark):object {
 $remote=achbotProviderUser($server,$remark,false);
 $GLOBALS['last_packet']=is_object($remote)?$remote:(object)[];
 $GLOBALS['last_packet']->provider_render_proof=['provider'=>$GLOBALS['spec']['provider']??'fixture','server_id'=>(int)$server,'username'=>(string)$remark];
 return $GLOBALS['last_packet'];
}
function achbotOrderPresentation(...$args){
 ++$GLOBALS['presentations'];
 if (($args[4]??null)!==($GLOBALS['last_packet']??null))throw new RuntimeException('presentation_must_reuse_current_render_packet');
 $hide=$GLOBALS['spec']['hidden_links']??false;
 $available=!($GLOBALS['spec']['native_links_missing']??false);
 return ['config_html'=>$hide||!$available?'':'vless://fixture-config','subscription_html'=>$hide||!$available?'':'https://sub.example.invalid/fixture',
  'backup_html'=>'','notice_html'=>'','subscription_url'=>$hide?'':'https://sub.example.invalid/fixture',
  'qr_subscription_url'=>$available?'https://sub.example.invalid/fixture':'',
  'qr_config_links'=>$available?['vless://fixture-config']:[]];
}
function buildConfigMessage($state,$remark,$links,$sub,$backup,$type){return $state."\n".$remark."\n".$links."\n".$sub;}
require_once $root.'/settings/jdf.php';
function bot(...$args){return (object)['result'=>(object)['first_name'=>'Fixture']];}
function alert(...$args){$GLOBALS['events'][]=['alert'=>$args[0]];}
function editText(...$args){$GLOBALS['events'][]=['edit'=>$args[1],'keyboard'=>$args[2]??null];}
function sendMessage(...$args){$GLOBALS['events'][]=['message'=>$args[0],'keyboard'=>$args[1]??null];return (object)['ok'=>true];}
function delMessage(...$args){}
function setUser(...$args){throw new RuntimeException('unexpected_state_write');}
try {
 switch($spec['op']) {
 case 'admission':
  function achbotManualEnabled($method){return in_array($method,$GLOBALS['spec']['manual']??[],true);}
  function achbotManualCryptoEnabled($actor=null){return (bool)array_intersect($GLOBALS['spec']['manual']??[],['manual_usdt','manual_trx']);}
  function achbotPayUxAudienceAllowed($db,$method,$actor,...$rest){return !in_array($method,$GLOBALS['spec']['denied']??[],true);}
  function achbotUsdtRateForUser($db,$actor){return $GLOBALS['spec']['rate']??null;}
  require $root.'/src/Purchase/EntryAndBulk.php';
  $out=achbotCustomerHasPaymentMethod($connection,$from_id,$spec['state']??[]);break;
 case 'catalogue':
  require $root.'/src/Purchase/Catalogue.php';$out=$GLOBALS['events'];break;
 case 'trial':
  require $root.'/src/Purchase/TrialCatalogue.php';$out=$GLOBALS['events'];break;
 default:
  require $root.'/src/Subscription/AccountDetails.php';
  if ($spec['op']==='sequence') {
   $out=[];
   foreach ($spec['remote_sequence'] as $remote) {
    $GLOBALS['spec']['remote']=$remote;$beforeRenderReads=$GLOBALS['reads'];$beforeRenderPresentations=$GLOBALS['presentations'];
    $rendered=($spec['audience']??'customer')==='admin'?
      \AchBot\Subscription\AccountDetails\getUserOrderDetailKeys(91):
      \AchBot\Subscription\AccountDetails\getOrderDetailKeys(1001,91);
    $out[]=['rendered'=>$rendered,'reads'=>$GLOBALS['reads']-$beforeRenderReads,'presentations'=>$GLOBALS['presentations']-$beforeRenderPresentations];
   }
  } else $out=match($spec['op']){
   'traffic'=>\AchBot\Subscription\AccountDetails\getOrderTrafficInfo($spec['provider'],2,'fixture_user'),
   'admin'=>\AchBot\Subscription\AccountDetails\getUserOrderDetailKeys(91),
   default=>\AchBot\Subscription\AccountDetails\getOrderDetailKeys(1001,91)
  };
 }
 $GLOBALS['response_emitted']=true;echo json_encode(['out'=>$out,'reads'=>$GLOBALS['reads'],'presentations'=>$GLOBALS['presentations']],JSON_THROW_ON_ERROR);
} catch(Throwable $error){fwrite(STDERR,get_class($error).':'.$error->getMessage());exit(1);}
'''

import notification_fixture
PHP=notification_fixture.with_router(PHP)
