<?php
/** Synthetic Telegram dispatch, real original-schema MariaDB, owned HTTP provider. */
if (PHP_SAPI !== 'cli' || getenv('ACHBOT_PRIVATE_TEST') !== '1'
    || getenv('ACHBOT_V7_NATIVE') !== '1' || extension_loaded('FFI')
    || !extension_loaded('mysqli') || !extension_loaded('curl')) exit(3);
$db = getenv('ACHBOT_DATABASE');
$socket = getenv('ACHBOT_SOCKET');
if (!in_array($db, ['achbot_v4_old','achbot_v4_new'], true) || !file_exists($socket)) exit(4);
$spec = json_decode(file_get_contents(__DIR__.'/case.json'), true, 512, JSON_THROW_ON_ERROR);
$connection = new mysqli('localhost', 'root', '', $db, 0, $socket);
$connection->set_charset('utf8mb4');
$admin=9001;$from_id=(int)($spec['actor']??1001);$chat_id=$from_id;$message_id=71;$time=time();
$data=$spec['data']??'';$text=$spec['text']??'';$match=[];$first_name='Fixture';$username='fixture';
$botUrl='http://localhost.invalid/';$botUsername='';$paymentKeys=[];
$botState=['sellState'=>'on','walletState'=>'on','cartToCartState'=>'on','nowPaymentOther'=>'off',
    'zarinpal'=>'off','nextpay'=>'off','weSwapState'=>'off','tronWallet'=>'off','remark'=>'digits',
    'subLinkState'=>'on','configLinkState'=>'on','rewardChannel'=>'',
    'plandelkhahState'=>'on','agencyPlanDiscount'=>'on','gbPrice'=>10,'dayPrice'=>5];
$mainValues=array_fill_keys(['buy_sub_select_location','no_server_available','enter_account_amount',
    'category_not_avilable','receive_categories','buy_sub_select_category','buy_sub_select_plan',
    'no_plan_available','receive_plans','buy_custom_plan','select_one_plan_to_edit',
    'customer_custome_plan_volume','customer_custome_plan_day','customer_custome_plan_name',
    'send_only_number','send_positive_number','incorrect_config_name','receving_information',
    'buy_subscription_detail','buy_much_subscription_detail','sending_config_to_user',
    'out_of_connection_capacity','out_of_server_capacity','can_create_specific_account',
    'buy_new_account_request','invite_buy_new_account','selling_is_off','not_valid_discount_code',
    'buy_sub_select_location','reached_main_menu'], 'fixture-message');
$buttonValues=array_fill_keys(['cancel','back_to_main','back_button','pay_with_wallet',
    'cart_to_cart','weswap_gateway','tron_gateway','now_payment_gateway','zarinpal_gateway',
    'nextpay_gateway','start_bot','my_subscriptions'], 'fixture-button');
$botState['testAccount']='on';$cancelKey='cancel-keys';$removeKeyboard='remove-keys';
$linkIndices=[0];$hideConfigIfMany=false;$permissions=[];$update=[];
$stmt=$connection->prepare('SELECT * FROM users WHERE userid=?');$stmt->bind_param('i',$from_id);
$stmt->execute();$userInfo=$stmt->get_result()->fetch_assoc();$stmt->close();
if(!$userInfo) throw new RuntimeException('Missing fixture actor');
$ach_events=[];$ach_warnings=[];$ach_return=false;$ach_exception=null;
function achEvent($name,$args){$GLOBALS['ach_events'][]=['name'=>$name,'args'=>$args];}
function sendMessage(...$args){achEvent('sendMessage',$args);return (object)['ok'=>true];}
function editText(...$args){achEvent('editText',$args);return (object)['ok'=>true];}
function delMessage(...$args){achEvent('delMessage',$args);return (object)['ok'=>true];}
function alert(...$args){achEvent('alert',$args);return (object)['ok'=>true];}
function sendPhoto(...$args){achEvent('sendPhoto',['synthetic']);return (object)['ok'=>true];}
function sendToAllAdmins(...$args){achEvent('sendToAllAdmins',$args);}
function getMainKeys(){return 'fixture-main';}
function getGateWaysKeys(){return 'fixture-gateways';}
function getCategoriesKeys($page=0){return 'fixture-categories';}
function getPlanDetailsKeys($id){return 'fixture-plan-detail';}
function getServerListKeys($page=0){return 'fixture-servers';}
function checkStep($table){return 1;}
function RandomString($count=9,$type='all'){return str_repeat('x',(int)$count);}
function generateRandomString($len=42,$protocol='vless'){return '11111111-2222-4333-8444-555555555555';}
function setUser($value='none',$field='step'){
    if(!in_array($field,['step','temp','freetrial'],true))throw new RuntimeException('Unexpected state column');
    achEvent('setUser',[$value,$field]);
    $stmt=$GLOBALS['connection']->prepare('UPDATE users SET `'.$field.'` = ? WHERE userid = ?');
    $stmt->bind_param('si',$value,$GLOBALS['from_id']);$stmt->execute();$stmt->close();
}
function getConnectionLink(...$args){return ['vless://fixture-server-link'];}
function addMarzbanUser(...$args){return \AchBot\Panel\Accounts\addMarzbanUser(...$args);}
function getMarzbanToken(...$args){return \AchBot\Panel\Accounts\getMarzbanToken(...$args);}
function getMarzbanUserInfo(...$args){return \AchBot\Panel\Accounts\getMarzbanUserInfo(...$args);}
function addUser(...$args){return \AchBot\Panel\Accounts\addUser(...$args);}
function addInboundAccount(...$args){return \AchBot\Panel\Accounts\addInboundAccount(...$args);}
set_error_handler(function($level,$msg){
    if(error_reporting()&$level)$GLOBALS['ach_warnings'][]=$level.': '.$msg;
    return true;
});
error_reporting(E_ALL);
srand(21997);
ob_start();
register_shutdown_function(function(){
    $fatal=error_get_last();
    if($fatal && in_array($fatal['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR],true))
        $GLOBALS['ach_warnings'][]='FATAL: '.$fatal['message'];
    $out=ob_get_contents();if(ob_get_level())ob_end_clean();
    file_put_contents(__DIR__.'/result.json',json_encode([
        'events'=>$GLOBALS['ach_events'],'warnings'=>$GLOBALS['ach_warnings'],
        'stdout'=>$out,'returned'=>$GLOBALS['ach_return'],'exception'=>$GLOBALS['ach_exception']
    ],JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE|JSON_UNESCAPED_UNICODE));
});
require __DIR__.'/src/Panel/Accounts.php';
require __DIR__.'/buy_helpers.php';
try {require __DIR__.'/access-sequence.php';$ach_return=true;}
catch(Throwable $e){$ach_exception=get_class($e).': '.$e->getMessage();}
