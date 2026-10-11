<?php
// Intentionally no strict_types: legacy PHP uses coercive call semantics.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (extension_loaded('mysqli') || extension_loaded('curl') || extension_loaded('FFI')) {
    fwrite(STDERR,"Refusing native I/O extensions\n"); exit(3);
}
if (getenv('ACHBOT_PRIVATE_TEST') !== '1') { fwrite(STDERR,"Use tests/run.py\n"); exit(3); }
date_default_timezone_set('UTC');
require __DIR__.'/doubles.php';
$scenario=$argv[1];
$root=realpath(__DIR__.'/../..');
chdir($root);
ini_set('include_path',$root);
$_GET=[]; $_POST=[]; $_SERVER['REMOTE_ADDR']='149.154.160.1'; $_SERVER['REQUEST_METHOD']='GET';
$admin=9001; $admins_list=[9001,9002]; $special_admins=[9001]; $from_id=1001; $chat_id=1001; $message_id=71; $callbackId='fixture-callback';
$first_name='Fixture'; $username='fixture_user'; $botToken='fixture-token'; $botUrl='https://bot.example.invalid/'; $botUsername='FixtureBot';
$USDTPrice='100000'; $USDTPriceagent='90000'; $hideConnectLinkForMarzban=false; $showIndexes=[0,1]; $linkIndices=[0,1]; $hideConfigIfMany=false;
$buttons_only_for_special_admins=['bot_reports','server_settings','gateways_settings','bot_settings','categories_settings','plan_settings'];
$fixtureUser=['id'=>1,'userid'=>1001,'name'=>'Fixture','username'=>'fixture_user','wallet'=>1000000,'refered_by'=>2002,
 'step'=>'none','temp'=>'','isAdmin'=>0,'is_agent'=>0,'discount_percent'=>'{}','spam_info'=>null,'first_start'=>'sent','date'=>'1700000000'];
$userInfo=$fixtureUser;
$fixtureState=['supportID'=>'@fixture_support','lockChannel'=>'@fixture_channel','botState'=>'on','sellState'=>'on','searchState'=>'on',
 'walletState'=>'on','cartToCartState'=>'on','nextpay'=>'off','zarinpal'=>'off','nowPaymentWallet'=>'off','nowPaymentOther'=>'off',
 'weSwapState'=>'off','tronWallet'=>'off','agencyState'=>'off','testAccount'=>'off','requirePhone'=>'off','requireIranPhone'=>'off',
 'rateLimit'=>1900000000,'rewardChannel'=>'','rewaredTime'=>0,'lastRewardMessage'=>1900000000,'lastBackupMessage'=>1900000000,
 'renewAutoState'=>'off','remark'=>'idanddigits','subLinkState'=>'on','configLinkState'=>'on','renewAccountState'=>'on',
 'changeProtocolState'=>'off','increaseTimeState'=>'on','increaseVolumeState'=>'on','switchLocationState'=>'off','qrSubState'=>'off','qrConfigState'=>'off'];
$botState=$fixtureState; $paymentKeys=[]; $removeKeyboard=json_encode(['remove_keyboard'=>true]);
require $root.'/settings/values.php';
set_error_handler(function($severity,$message,$file,$line){
    if (!(error_reporting() & $severity)) return false;
    $GLOBALS['ach_errors'][]='PHP '.$severity.': '.$message.' ['.basename($file).':'.$line.']';
    return true;
});
ob_start();
register_shutdown_function(function() use($root) {
    // Register final capture after application shutdown handlers (broadcast checkpoints).
    register_shutdown_function(function() use($root) {
        $last=error_get_last();
        if ($last && in_array($last['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR],true)) $GLOBALS['ach_errors'][]='Fatal: '.$last['message'];
        $out=ob_get_contents(); if(ob_get_level()) ob_end_clean();
        if ($GLOBALS['ach_reads']) $GLOBALS['ach_errors'][]='Unconsumed SQL read scripts: '.count($GLOBALS['ach_reads']);
        if ($GLOBALS['ach_http']) $GLOBALS['ach_errors'][]='Unconsumed HTTP scripts: '.count($GLOBALS['ach_http']);
        if ($GLOBALS['ach_panel']) $GLOBALS['ach_errors'][]='Unconsumed panel scripts: '.count($GLOBALS['ach_panel']);
        $data=['result'=>$GLOBALS['ach_result'],'events'=>$GLOBALS['ach_trace'],'stdout'=>$out,'http_status'=>http_response_code() ?: 200,
               'harness_errors'=>$GLOBALS['ach_errors']];
        file_put_contents($root.'/result.json',json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
    });
});
if (!str_starts_with($scenario,'entry.')) {
    require $root.'/functions-under-test.php';
    function addMarzbanUser(...$args) { return achPanelCall(__FUNCTION__,$args); }
    function addUser(...$args) { return achPanelCall(__FUNCTION__,$args); }
    function addInboundAccount(...$args) { return achPanelCall(__FUNCTION__,$args); }
    function editMarzbanConfig(...$args) { return achPanelCall(__FUNCTION__,$args); }
    function editClientTraffic(...$args) { return achPanelCall(__FUNCTION__,$args); }
    function editInboundTraffic(...$args) { return achPanelCall(__FUNCTION__,$args); }
    require __DIR__.'/scenarios.php';
} else {
    require __DIR__.'/entrypoints.php';
}
