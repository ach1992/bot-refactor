<?php

file_put_contents(__DIR__.'/admins_list_check.txt', print_r($admins_list, true));

include_once "settings/values.php";
include_once 'settings/jdf.php';
require_once 'baseInfo.php';

$connection = new mysqli('localhost',$dbUserName,$dbPassword,$dbName);
if($connection->connect_error){
    exit("error " . $connection->connect_error);  
}
$connection->set_charset("utf8mb4");

function tgRetryAfter($res){
    require_once __DIR__ . '/src/Telegram/Response.php';
    return \AchBot\Telegram\Response\retryAfter($res);
}

function bot($method, $datas = []) {
    global $botToken;
    require_once __DIR__ . '/src/Telegram/Transport.php';
    return \AchBot\Telegram\Transport\request($botToken, __DIR__, $method, $datas);
}

function sendFileToTelegram($method, $datas = []) {
    global $botToken;
    require_once __DIR__ . '/src/Telegram/Transport.php';
    return \AchBot\Telegram\Transport\sendFile($botToken, __DIR__, $method, $datas);
}

function normalizeReplyMarkup($key){
    require_once __DIR__ . '/src/Telegram/Markup.php';
    return \AchBot\Telegram\Markup\normalize($key);
}

function sendMessage($txt, $key = null, $parse ="MarkDown", $ci= null, $msg = null){
    global $from_id;
    require_once __DIR__ . '/src/Telegram/Messages.php';
    return \AchBot\Telegram\Messages\sendMessage('bot', $from_id, $txt, $key, $parse, $ci, $msg);
}

function sendDatabaseBackup($channelId) {
    global $connection, $dbUserName, $dbPassword, $dbName;

    $backupDir = __DIR__ . "/backup";
    if (!is_dir($backupDir)) {
        mkdir($backupDir, 0755, true);
    }

    $backupFile = $backupDir . "/bot_backup.sql";
    $zipFile    = $backupDir . "/bot_backup.zip";

    $dbHost = 'localhost';

    exec(
        "mysqldump --default-character-set=utf8mb4 --routines --events --triggers " .
        "-h $dbHost -u $dbUserName -p\"$dbPassword\" $dbName > \"$backupFile\" 2> /dev/null"
    );
	
	if (!file_exists($backupFile) || filesize($backupFile) < 1000) {
        return false;
    }

    $stmt = $connection->prepare("SELECT COUNT(*) as count FROM `users`");
    $stmt->execute();
    $userCount = $stmt->get_result()->fetch_assoc()['count'] ?? 0;
    $stmt->close();

    $stmt = $connection->prepare("SELECT COUNT(*) as count FROM `orders_list`");
    $stmt->execute();
    $orderCount = $stmt->get_result()->fetch_assoc()['count'] ?? 0;
    $stmt->close();

    date_default_timezone_set('Asia/Tehran');
    $now = date("Y-m-d H:i:s");

    $caption = "
📦 Bot Database Backup

⏰ Time: $now
👤 Users: $userCount
📋 Subscriptions: $orderCount
";

    $zip = new ZipArchive();
    if ($zip->open($zipFile, ZipArchive::CREATE) === TRUE) {
        $zip->addFile($backupFile, basename($backupFile));
        $zip->close();

        unlink($backupFile);

        $sent = sendDocument($zipFile, $caption, $channelId);
        if ($sent) {
            unlink($zipFile);
        }
    }
}

function sendDocument($filePath, $caption = "", $ci = null){
    global $from_id;
    require_once __DIR__ . '/src/Telegram/Messages.php';
    return \AchBot\Telegram\Messages\sendDocument('sendFileToTelegram', $from_id, $filePath, $caption, $ci);
}

function answerCallbackQuery($callbackId, $text = "", $show_alert = false) {
    require_once __DIR__ . '/src/Telegram/Messages.php';
    return \AchBot\Telegram\Messages\answerCallbackQuery('file_get_contents', $GLOBALS['botUrl'], $callbackId, $text, $show_alert);
}

function editKeys($keys = null, $msgId = null, $ci = null){
    global $from_id,$message_id;
    require_once __DIR__ . '/src/Telegram/Messages.php';
    return \AchBot\Telegram\Messages\editKeys('bot', $from_id, $message_id, $keys, $msgId, $ci);
}
function editText($msgId, $txt, $key = null, $parse = 'HTML', $ci = null) {
    global $from_id;
    require_once __DIR__ . '/src/Telegram/Messages.php';
    return \AchBot\Telegram\Messages\editText('bot', $from_id, $msgId, $txt, $key, $parse, $ci);
}

function delMessage($msg = null, $chat_id = null){
    global $from_id, $message_id;
    require_once __DIR__ . '/src/Telegram/Messages.php';
    return \AchBot\Telegram\Messages\deleteMessage('bot', $from_id, $message_id, $msg, $chat_id);
}
function sendAction($action, $ci= null){
    global $from_id;
    require_once __DIR__ . '/src/Telegram/Messages.php';
    return \AchBot\Telegram\Messages\sendAction('bot', $from_id, $action, $ci);
}
// BEGIN send to all
function forwardmessage($tochatId, $fromchatId, $message_id){
    require_once __DIR__ . '/src/Telegram/Messages.php';
    return \AchBot\Telegram\Messages\forwardMessage('bot', $tochatId, $fromchatId, $message_id);
}

function isForwardedMessageObj($msg){
    require_once __DIR__ . '/src/Telegram/Response.php';
    return \AchBot\Telegram\Response\isForwardedMessage($msg);
}

function groupFa($g){
    require_once __DIR__ . '/src/Presentation/Labels.php';
    return \AchBot\Presentation\Labels\audience($g);
}

function stateFa($s){
    require_once __DIR__ . '/src/Presentation/Labels.php';
    return \AchBot\Presentation\Labels\broadcastState($s);
}
function getUsersCountByGroup($connection, $group){
    if($group === 'has'){
        $stmt = $connection->prepare("
            SELECT COUNT(*) AS count
            FROM `users` u
            WHERE (u.step IS NULL OR (u.step <> 'Bot_Blocked' AND u.step <> 'banned'))
              AND EXISTS (SELECT 1 FROM `orders_list` o WHERE o.userid = u.userid)
        ");

    } elseif($group === 'none'){
        $stmt = $connection->prepare("
            SELECT COUNT(*) AS count
            FROM `users` u
            LEFT JOIN `orders_list` o ON o.userid = u.userid
            WHERE (u.step IS NULL OR (u.step <> 'Bot_Blocked' AND u.step <> 'banned'))
              AND o.userid IS NULL
        ");

    } elseif($group === 'agents'){
        $stmt = $connection->prepare("
            SELECT COUNT(*) AS count
            FROM `users`
            WHERE is_agent = 1
              AND (step IS NULL OR (step <> 'Bot_Blocked' AND step <> 'banned'))
        ");

    } else {
        $stmt = $connection->prepare("
            SELECT COUNT(*) AS count
            FROM `users`
            WHERE (step IS NULL OR (step <> 'Bot_Blocked' AND step <> 'banned'))
        ");
    }

    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    $stmt->close();

    return (int)($row['count'] ?? 0);
}

// ✅ Helpers for Broadcast Pin/Unpin/Delete
function apiOk($res){
    require_once __DIR__ . '/src/Telegram/Response.php';
    return \AchBot\Telegram\Response\isSuccessful($res);
}

function getApiMessageId($res){
    require_once __DIR__ . '/src/Telegram/Response.php';
    return \AchBot\Telegram\Response\messageId($res);
}

function logBroadcastUserMessage($connection, $broadcastId, $userid, $messageId){
    if($broadcastId <= 0 || $userid <= 0 || $messageId <= 0) return;

    $stmt = $connection->prepare("
        INSERT INTO `bc_messages` (`broadcast_id`,`userid`,`message_id`)
        VALUES (?,?,?)
        ON DUPLICATE KEY UPDATE
            `message_id`=VALUES(`message_id`),
            `deleted`=0,
            `deleted_at`=NULL
    ");
    $stmt->bind_param("iii", $broadcastId, $userid, $messageId);
    $stmt->execute();
    $stmt->close();
}

function pinOne($chatId, $messageId){
    require_once __DIR__ . '/src/Telegram/Messages.php';
    return \AchBot\Telegram\Messages\pinOne('bot', $chatId, $messageId);
}

function unpinOne($chatId, $messageId){
    require_once __DIR__ . '/src/Telegram/Messages.php';
    return \AchBot\Telegram\Messages\unpinOne('bot', $chatId, $messageId);
}

function deleteOne($chatId, $messageId){
    require_once __DIR__ . '/src/Telegram/Messages.php';
    return \AchBot\Telegram\Messages\deleteOne('bot', $chatId, $messageId);
}
// END send to all
function sendPhoto($photo, $caption = null, $keyboard = null, $parse = "MarkDown", $ci = null, $replyTo = null){
    global $from_id;
    require_once __DIR__ . '/src/Telegram/Messages.php';
    return \AchBot\Telegram\Messages\sendPhoto('bot', $from_id, $photo, $caption, $keyboard, $parse, $ci, $replyTo);
}

function getFileUrl($fileid){
    global $botToken;
    require_once __DIR__ . '/src/Telegram/Messages.php';
    return \AchBot\Telegram\Messages\getFileUrl('bot', $botToken, $fileid);
}

function alert($txt, $type = false, $callid = null){
    global $callbackId;
    require_once __DIR__ . '/src/Telegram/Messages.php';
    return \AchBot\Telegram\Messages\alert('bot', $callbackId, $txt, $type, $callid);
}

$range = [
    '149.154.160.0/20',
    '91.108.4.0/22',
    '91.108.8.0/22',
    '91.108.12.0/22',
    '91.108.16.0/22',
    '91.108.20.0/22',
    '91.108.56.0/22',
    '91.105.192.0/23',
    '185.76.151.0/24',
];
	
function check($return = false){
    global $range;
    foreach ($range as $rg) {
        if (ip_in_range($_SERVER['REMOTE_ADDR'], $rg)) {
            return true;
        }
    }
    if ($return == true) {
        return false;
    }

    die('You do not have access');

}

function curl_get_file_contents($URL){
    $c = curl_init();
    curl_setopt($c, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($c, CURLOPT_URL, $URL);
    $contents = curl_exec($c);
    curl_close($c);

    if ($contents) return $contents;
    else return FALSE;
}

function ip_in_range($ip, $range){
    if (strpos($range, '/') == false) {
        $range .= '/32';
    }
    // $range is in IP/CIDR format eg 127.0.0.1/24
    list($range, $netmask) = explode('/', $range, 2);
    $range_decimal = ip2long($range);
    $ip_decimal = ip2long($ip);
    $wildcard_decimal = pow(2, (32 - $netmask)) - 1;
    $netmask_decimal = ~$wildcard_decimal;
    return (($ip_decimal & $netmask_decimal) == ($range_decimal & $netmask_decimal));
}

$time = time();
$update = json_decode(file_get_contents("php://input"));
require __DIR__ . '/src/Telegram/UpdateBindings.php';
if($from_id < 0) exit();
$stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid`=?");
$stmt->bind_param("i", $from_id);
$stmt->execute();
$uinfo = $stmt->get_result();
$userInfo = $uinfo->fetch_assoc();
$stmt->close();
 
$stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'PAYMENT_KEYS'");
$stmt->execute();
$paymentKeys = $stmt->get_result()->fetch_assoc()['value'];
if(!is_null($paymentKeys)) $paymentKeys = json_decode($paymentKeys,true);
else $paymentKeys = array();
$stmt->close();

$stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'BOT_STATES'");
$stmt->execute();
$botState = $stmt->get_result()->fetch_assoc()['value'];
if(!is_null($botState)) $botState = json_decode($botState,true);
else $botState = array();
$stmt->close();

$supportID = $botState['supportID'];
$channelLock = $botState['lockChannel'];
$joniedState= bot('getChatMember', ['chat_id' => $channelLock,'user_id' => $from_id])->result->status;

if ($data == "addExemptUser") {
    setUser("awaitingExemptUserID", "step");
    sendMessage("لطفاً شناسه عددی کاربری که می‌خواهید از الزام عضویت در کانال معاف شود را وارد کنید:", $removeKeyboard);
}
if ($userInfo['step'] == "awaitingExemptUserID") {
    if (!is_numeric($text)) {
        sendMessage("❌ لطفاً فقط شناسه عددی معتبر وارد کنید.");
        return;
    }

    // بررسی وجود EXEMPTED_USERS
    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'EXEMPTED_USERS'");
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        // اگر وجود نداشت، ایجادش کن
        $empty = json_encode([]);
        $stmt = $connection->prepare("INSERT INTO `setting` (`type`, `value`) VALUES ('EXEMPTED_USERS', ?)");
        $stmt->bind_param("s", $empty);
        $stmt->execute();
        $stmt->close();
        $exemptedUsers = [];
    } else {
        $exemptedUsers = json_decode($row['value'], true);
        if (!is_array($exemptedUsers)) $exemptedUsers = [];
    }

    // اضافه کردن آیدی اگر نبود
    if (!in_array($text, $exemptedUsers)) {
        $exemptedUsers[] = $text;

        $updatedValue = json_encode($exemptedUsers);
        $stmt = $connection->prepare("UPDATE `setting` SET `value` = ? WHERE `type` = 'EXEMPTED_USERS'");
        $stmt->bind_param("s", $updatedValue);
        $stmt->execute();
        $stmt->close();

        sendMessage("✅ کاربر با آیدی <code>$text</code> معاف شد.", $removeKeyboard, "HTML");
    } else {
        sendMessage("⚠️ این کاربر قبلاً معاف شده است.", $removeKeyboard, "HTML");
    }

    // پاک کردن step
    setUser("none", "step");
}

// تابع مشترک ساخت درخواست پرداخت
function startWalletPayment($connection, $botState, $buttonValues, $botUrl, $from_id, $removeKeyboard, $amount){
    sendMessage("🪄 لطفا صبور باشید ...", $removeKeyboard);

    $hash_id = RandomString();

    // حذف موارد pending قبلی
    $stmt = $connection->prepare("DELETE FROM `pays` WHERE `user_id` = ? AND `type` = 'INCREASE_WALLET' AND `state` = 'pending'");
    $stmt->bind_param("i", $from_id);
    $stmt->execute();
    $stmt->close();

    $time = time();
    $stmt = $connection->prepare("INSERT INTO `pays` 
        (`hash_id`, `user_id`, `type`, `plan_id`, `volume`, `day`, `price`, `request_date`, `state`)
        VALUES (?, ?, 'INCREASE_WALLET', '0', '0', '0', ?, ?, 'pending')");
    $stmt->bind_param("siii", $hash_id, $from_id, $amount, $time);
    $stmt->execute();
    $stmt->close();

    $keyboard = [];

    if($botState['cartToCartState'] == "on") 
        $keyboard[] = [['text' => $buttonValues['cart_to_cart'], 'callback_data' => "payIncreaseWalletWithCartToCart$hash_id"]];
    if($botState['nowPaymentWallet'] == "on") 
        $keyboard[] = [['text' => $buttonValues['now_payment_gateway'], 'url' => $botUrl . "pay/?nowpayment&hash_id=" . $hash_id]];
    if($botState['zarinpal'] == "on") 
        $keyboard[] = [['text' => $buttonValues['zarinpal_gateway'], 'url' => $botUrl . "pay/?zarinpal&hash_id=" . $hash_id]];
    if($botState['nextpay'] == "on") 
        $keyboard[] = [['text' => $buttonValues['nextpay_gateway'], 'url' => $botUrl . "pay/?nextpay&hash_id=" . $hash_id]];
    if($botState['weSwapState'] == "on") 
        $keyboard[] = [['text' => $buttonValues['weswap_gateway'], 'callback_data' => "payWithWeSwap$hash_id"]];
    if($botState['tronWallet'] == "on") 
        $keyboard[] = [['text' => $buttonValues['tron_gateway'], 'callback_data' => "payWithTronWallet$hash_id"]];

    $keyboard[] = [['text' => $buttonValues['cancel'], 'callback_data'=> "mainMenu"]];

    $keys = json_encode(['inline_keyboard' => $keyboard], JSON_UNESCAPED_UNICODE);
    
    sendMessage("اطلاعات شارژ:\nمبلغ ". number_format($amount) . " تومان\n\nلطفا روش پرداخت را انتخاب کنید:", $keys);
}

function replaceAllPlaceholdersInMainValues(array $mainValues, array $botState, $userId = '') {
    require_once __DIR__ . '/src/Presentation/Text.php';
    return \AchBot\Presentation\Text\replacePlaceholders($mainValues, $botState, $userId);
}
$mainValues = replaceAllPlaceholdersInMainValues($mainValues, $botState, $from_id ?? '');

function getSupportUrl(array $botState, string $fallback = '') {
    require_once __DIR__ . '/src/Presentation/Text.php';
    return \AchBot\Presentation\Text\supportUrl($botState, $fallback);
}

if (isset($update->message->document->file_id)) {
    $filetype = 'document';
    $fileid = $update->message->document->file_id;

} elseif (isset($update->message->audio->file_id)) {
    $filetype = 'music';
    $fileid = $update->message->audio->file_id;

} elseif (isset($update->message->photo) && is_array($update->message->photo) && isset($update->message->photo[0]->file_id)) {
    $filetype = 'photo';

    if (isset($update->message->photo[2]->file_id)) {
        $fileid = $update->message->photo[2]->file_id;
    } elseif (isset($update->message->photo[1]->file_id)) {
        $fileid = $update->message->photo[1]->file_id;
    } else {
        $fileid = $update->message->photo[0]->file_id;
    }

} elseif (isset($update->message->voice->file_id)) {
    $filetype = 'voice';
    $voiceid = $update->message->voice->file_id;
    $fileid = $voiceid;

} elseif (isset($update->message->video->file_id)) {
    $filetype = 'video';
    $fileid = $update->message->video->file_id;
}

$cancelKey=json_encode(['keyboard'=>[
    [['text'=>$buttonValues['cancel']]]
],'resize_keyboard'=>true]);
$removeKeyboard = json_encode(['remove_keyboard'=>true]);

function getMainKeys(){
    global $connection, $userInfo, $from_id, $admin, $botState, $buttonValues;
    $mainKeys = array();
    $temp = array();

    if($botState['agencyState'] == "on" && $userInfo['is_agent'] == 1){
		$mainKeys = array_merge($mainKeys, [
			[['text'=>$buttonValues['agency_setting'],'callback_data'=>"agencySettings"]],
			[['text'=>$buttonValues['sharj'],'callback_data'=>"increaseMyWallet"]],
		]);

		if (($botState['sellState'] ?? 'off') == "on" || $from_id == $admin || !empty($userInfo['isAdmin'])) {
			$mainKeys[] = [
				['text'=>$buttonValues['agent_one_buy'],'callback_data'=>"agentOneBuy"],
				['text'=>$buttonValues['agent_much_buy'],'callback_data'=>"agentMuchBuy"]
			];
		}
	}else{
        $mainKeys = array_merge($mainKeys,[
            (($botState['agencyState'] == "on" && $userInfo['is_agent'] == 0)?[
                ['text'=>$buttonValues['request_agency'],'callback_data'=>"requestAgency"]
            ]: []),
            (($botState['sellState'] == "on" || $from_id == $admin || $userInfo['isAdmin'] == true)?
                [['text'=>$buttonValues['sharj'],'callback_data'=>"increaseMyWallet"],['text'=>$buttonValues['buy_subscriptions'],'callback_data'=>"buySubscription"]]
                :
                [['text'=>$buttonValues['sharj'],'callback_data'=>"increaseMyWallet"]]
            )
        ]);
    }

    $mainKeys = array_merge($mainKeys, [
		( 
        ($botState['testAccount'] == "on" && ( $from_id == $admin || $userInfo['freetrial'] != 'used')) ? 
			[['text'=>$buttonValues['test_account'],'callback_data'=>"getTestAccount"]] : [] 
		),
        (
		($userInfo['is_agent'] == 1) ?
			[['text'=>$buttonValues['my_subscriptions'],'callback_data'=>'agentConfigsList']] :
			[['text'=>$buttonValues['my_subscriptions'],'callback_data'=>'mySubscriptions']]
        ),
        [['text'=>$buttonValues['application_links'],'callback_data'=>"reciveApplications"],['text'=>$buttonValues['my_info'],'callback_data'=>"myInfo"]],
        (($botState['sharedExistence'] == "on" && $botState['individualExistence'] == "on")?
            [['text'=>$buttonValues['shared_existence'],'callback_data'=>"availableServers"],['text'=>$buttonValues['individual_existence'],'callback_data'=>"availableServers2"]] : []
        ),
        (($botState['sharedExistence'] == "on" && $botState['individualExistence'] != "on")?
            [['text'=>$buttonValues['shared_existence'],'callback_data'=>"availableServers"]] : []
        ),
        (($botState['sharedExistence'] != "on" && $botState['individualExistence'] == "on")?
            [['text'=>$buttonValues['individual_existence'],'callback_data'=>"availableServers2"]] : []
        ),
		(($from_id != $admin && $userInfo['isAdmin'] != true) ?
			[['text' => "🔰 دریافت پنل همکاری", 'url' => getSupportUrl($botState)]] : []
			// [['text' => "👋 پشتیبانی ربات", 'url' => 'https://t.me/+xiKk-5AhNZ5mMWU0']] : []
		),
		(($from_id != $admin && $userInfo['isAdmin'] != true) ?
			[['text'=>$buttonValues['invite_friends'],'callback_data'=>"inviteFriends"]] : []
		),
        (($botState['searchState']=="on" || $from_id == $admin || $userInfo['isAdmin'] == true)?
            [['text'=>$buttonValues['search_config'],'callback_data'=>"showUUIDLeft"]] : []
        ),
    ]);

    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` LIKE '%MAIN_BUTTONS%'");
    $stmt->execute();
    $buttons = $stmt->get_result();
    $stmt->close();
    if($buttons->num_rows > 0){
        while($row = $buttons->fetch_assoc()){
            $rowId = $row['id'];
            $title = str_replace("MAIN_BUTTONS","",$row['type']);
            
            $temp[] = ['text'=>$title,'callback_data'=>"showMainButtonAns" . $rowId];
            if(count($temp) >= 2){
                array_push($mainKeys, $temp);
                $temp = array();
            }
        }
    }
    array_push($mainKeys, $temp);

    if ($from_id == $admin || $userInfo['isAdmin'] == true) {
        array_push($mainKeys, [['text' => "🔍 جستجوی اشتراک کاربر", 'callback_data' => "searchUsersConfig"]]);
        array_push($mainKeys, [['text' => "⚙️ مدیریت ربات", 'callback_data' => "managePanel"]]);
    }

    return json_encode(['inline_keyboard' => $mainKeys]);
}

function getAgentKeys(){
    global $buttonValues, $mainValues, $from_id, $userInfo, $connection;
    $agencyDate = jdate("Y-m-d H:i:s",$userInfo['agent_date']);
    $joinedDate = jdate("Y-m-d H:i:s",$userInfo['date']);
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `userid` = ? AND `agent_bought` = 1");
    $stmt->bind_param("i", $from_id);
    $stmt->execute();
    $boughtAccounts = $stmt->get_result()->num_rows;
    $stmt->close();
    
    return json_encode(['inline_keyboard'=>[
        [['text'=>$boughtAccounts,'callback_data'=>"wizwizch"],['text'=>$buttonValues['agent_bought_accounts'],'callback_data'=>"wizwizch"]],
        [['text'=>$joinedDate,'callback_data'=>"wizwizch"],['text'=>$buttonValues['agent_joined_date'],'callback_data'=>"wizwizch"]],
        [['text'=>$agencyDate,'callback_data'=>"wizwizch"],['text'=>$buttonValues['agent_agency_date'],'callback_data'=>"wizwizch"]],
        [['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"]],
    ]]);
}

function getAdminKeys(){
    global $buttonValues, $from_id, $admin;

    $btn = fn($k,$c)=>['text'=>$buttonValues[$k],'callback_data'=>$c];

    $rows = [
        [[['bot_reports','botReports',fn()=>canSeeButton('bot_reports',$from_id)]]],
        [[['user_reports','userReports'],['message_to_user','messageToSpeceficUser']]],
        [[['increase_wallet','increaseUserWallet'],['decrease_wallet','decreaseUserWallet']]],
        [[['create_account','createMultipleAccounts'],['gift_volume_day','giftVolumeAndDay']]],
        [[['ban_user','banUser'],['unban_user','unbanUser']]],
        [[['manage_order_database','manageorderdatabase']]],
        [[['server_settings','serversSetting',fn()=>canSeeButton('server_settings',$from_id)]]],
        [[['categories_settings','categoriesSetting',fn()=>canSeeButton('categories_settings',$from_id)],['plan_settings','backplan',fn()=>canSeeButton('plan_settings',$from_id)]]],
        [[['discount_settings','discount_codes'],['main_button_settings','mainMenuButtons']]],
        [[['tickets_list','ticketsList'],['message_to_all','message2All']]],
        [[['agent_list','agentsList'],['pin_manage_all','pinManageAll']]],
		//[[['agent_list','agentsList'],['rejected_agent_list','rejectedAgentList']]],
		[[['admins_list','adminsList',fn()=> $from_id==$admin]]],
		[[['gateways_settings','gateWays_Channels',fn()=>canSeeButton('gateways_settings',$from_id)],['bot_settings','botSettings',fn()=>canSeeButton('bot_settings',$from_id)]]],
        [[['back_to_main','mainMenu']]],
    ];

    $keyboard = [];

    foreach ($rows as $rowWrap) {
        $buttons = $rowWrap[0];
        $line = [];

        foreach ($buttons as $b) {
            $key  = $b[0];
            $cb   = $b[1];
            $when = $b[2] ?? null;

            if (is_callable($when) && !$when()) continue;
            $line[] = $btn($key,$cb);
        }

        if (!empty($line)) $keyboard[] = $line;
    }

    return json_encode(['inline_keyboard'=>$keyboard]);
}

function setSettings($field, $value){
    global $connection, $botState;
    $botState[$field]= $value;
    
    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'BOT_STATES'");
    $stmt->execute();
    $isExists = $stmt->get_result();
    $stmt->close();
    if($isExists->num_rows>0) $query = "UPDATE `setting` SET `value` = ? WHERE `type` = 'BOT_STATES'";
    else $query = "INSERT INTO `setting` (`type`, `value`) VALUES ('BOT_STATES', ?)";
    $newData = json_encode($botState);
    
    $stmt = $connection->prepare($query);
    $stmt->bind_param("s", $newData);
    $stmt->execute();
    $stmt->close();
}
function getRejectedAgentList(){
    global $connection, $mainValues, $buttonValues;
    
    $stmt = $connection->prepare("SELECT * FROM `users` WHERE `is_agent` = 2");
    $stmt->execute();
    $list = $stmt->get_result();
    $stmt->close();
    
    if($list->num_rows>0){
        $keys = array();
        $keys[] = [['text'=>"آزاد ساختن",'callback_data'=>"wizwizch"],['text'=>"اسم کاربر",'callback_data'=>'wizwizch'],['text'=>"شناسه عددی",'callback_data'=>"wizwizch"]];
        while($row = $list->fetch_assoc()){
            $userId = $row['userid'];
            
            $userDetail = bot('getChat',['chat_id'=>$userId])->result;
            $fullName = $userDetail->first_name . " " . $userDetail->last_name;
            
            $keys[] = [['text'=>"✅",'callback_data'=>"releaseRejectedAgent" . $userId],['text'=>$fullName,'callback_data'=>"wizwizch"],['text'=>$userId,'callback_data'=>"wizwizch"]];
        }
        $keys[] = [['text'=>$buttonValues['back_button'],'callback_data'=>"managePanel"]];
        return json_encode(['inline_keyboard'=>$keys]);
    }else return null;
}
function getAgentDetails($userId){
    global $connection, $mainVAlues, $buttonValues;
    
    $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid` = ? AND `is_agent` = 1");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $agentDetail = $stmt->get_result();
    $stmt->close();


    $today = strtotime("today");
    $yesterday = strtotime("yesterday");
    $lastWeek = strtotime("last week");
    $lastMonth = strtotime("last month");

    $stmt = $connection->prepare("SELECT COUNT(`id`) AS `count`, SUM(`amount`) AS `total` FROM `orders_list` WHERE `date` >= ? AND `agent_bought` = 1 AND `userid` = ?");
    
    $stmt->bind_param("ii", $today, $userId);
    $stmt->execute();
    $todayIncome = $stmt->get_result()->fetch_assoc();
    
    $stmt->bind_param("ii", $yesterday, $userId);
    $stmt->execute();
    $yesterdayIncome = $stmt->get_result()->fetch_assoc();
    
    $stmt->bind_param("ii", $lastWeek, $userId);
    $stmt->execute();
    $lastWeekIncome = $stmt->get_result()->fetch_assoc();
    
    $stmt->bind_param("ii", $lastMonth, $userId);
    $stmt->execute();
    $lastMonthIncome = $stmt->get_result()->fetch_assoc();
    
    $stmt->close();
    
    
    return json_encode(['inline_keyboard'=>[
        [
            ['text'=>"(" . $todayIncome['count'] . ") " . number_format($todayIncome['total']),'callback_data'=>'wizwizch'],
            ['text'=>"درآمد امروز",'callback_data'=>'wizwizch']
            ],
        [
            ['text'=>"(" . $yesterdayIncome['count'] . ") " . number_format($yesterdayIncome['total']),'callback_data'=>"wizwizch"],
            ['text'=>"درآمد دیروز",'callback_data'=>"wizwizch"]
            ],
        [
            ['text'=>"(" . $lastWeekIncome['count'] . ") " . number_format($lastWeekIncome['total']),'callback_data'=>"wizwizch"],
            ['text'=>"درآمد یک هفته",'callback_data'=>"wizwizch"]
            ],
        [
            ['text'=>"(" . $lastMonthIncome['count'] . ") " . number_format($lastMonthIncome['total']),'callback_data'=>"wizwizch"],
            ['text'=>"درآمد یک ماه",'callback_data'=>"wizwizch"]
            ],
        [['text' => $buttonValues['back_button'], 'callback_data' => "agentsList"]]
        ]]);
}
function checkSpam(){
    global $connection, $from_id, $userInfo, $admin;
    
    if($userInfo != null && $from_id != $admin){
        $spamInfo = json_decode($userInfo['spam_info'],true)??array();
        $spamDate = $spamInfo['date'];
        if(isset($spamInfo['banned'])){
            if(time() <= $spamInfo['banned']) return $spamInfo['banned'];
        }
        
        if(time() <= $spamDate) $spamInfo['count'] += 1;
        else{
            $spamInfo['count'] = 1;
            $spamInfo['date'] = strtotime("+1 minute");
        }
        if($spamInfo['count'] >= 50){
            $spamInfo['banned'] = strtotime("+1 day");
        }
        $spamInfo = json_encode($spamInfo);
        
        $stmt = $connection->prepare("UPDATE `users` SET `spam_info` = ? WHERE `userid` = ?");
        $stmt->bind_param("si", $spamInfo, $from_id);
        $stmt->execute();
        $stmt->close();
    }else return null;
}
function getAgentsList($offset = 0){
    global $connection, $mainValues, $buttonValues;
    $limit = 15;
    
    $stmt = $connection->prepare("SELECT * FROM `users` WHERE `is_agent` = 1 LIMIT ? OFFSET ?");
    $stmt->bind_param('ii', $limit, $offset);
    $stmt->execute();
    $agentList = $stmt->get_result();
    $stmt->close();
    
    $keys = array();
    if($agentList->num_rows == 0 && $offset == 0) return null;
    
    $keys[] = [['text'=>"حذف",'callback_data'=>"wizwizch"],['text'=>"درصد تخفیف",'callback_data'=>"wizwizch"],['text'=>"تاریخ همکاری",'callback_data'=>"wizwizch"],['text'=>"اسم همکار",'callback_data'=>"wizwizch"],['text'=>"شناسه عددی",'callback_data'=>"wizwizch"]];
    if($agentList->num_rows > 0){
        while($row = $agentList->fetch_assoc()){
            $userId = $row['userid'];
            
            $userDetail = bot('getChat',['chat_id'=>$userId])->result;
            $userUserName = $userDetail->username;
            $fullName = $userDetail->first_name . " " . $userDetail->last_name;
            $joinedDate = jdate("Y-m-d H:i",$row['agent_date']);

            $keys[] = [['text'=>"❌",'callback_data'=>"removeAgent" . $userId],['text'=>"⚙️",'callback_data'=>"agentPercentDetails" . $userId],['text'=>$joinedDate,'callback_data'=>"wizwizch"],['text'=>$fullName,'callback_data'=>"agentDetails" . $userId],['text'=>$userId,'callback_data'=>"agentDetails" . $userId]];
        }
    }
    if($offset == 0 && $limit <= $agentList->num_rows)
        $keys[] = [
            ['text'=>" »» صفحه بعدی »»",'callback_data'=>"nextAgentList" . ($offset + $limit)]
            ];
    elseif($limit <= $agentList->num_rows)
        $keys[] = [
            ['text'=>" »» صفحه بعدی »»",'callback_data'=>"nextAgentList" . ($offset + $limit)],
            ['text'=>" «« صفحه قبلی ««",'callback_data'=>"nextAgentList" . ($offset - $limit)]
            ];
    elseif($offset != 0)
        $keys[] = [
            ['text'=>" «« صفحه قبلی ««",'callback_data'=>"nextAgentList" . ($offset - $limit)]
            ];
            
    $keys[] = [['text' => $buttonValues['back_button'], 'callback_data' => "managePanel"]];
    return json_encode(['inline_keyboard'=>$keys]);
}
function getAgentDiscounts($agentId){
    global $connection, $mainValues, $buttonValues, $botState;
    
    $stmt = $connection->prepare("SELECT * FROM `users` WHERE `is_agent` = 1 AND `userid` = ?");
    $stmt->bind_param("i", $agentId);
    $stmt->execute();
    $agentInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $keys = array();
    
    $discounts = json_decode($agentInfo['discount_percent'],true);

    $normal = $discounts['normal'];
    $keys[] = [['text'=>" ",'callback_data'=>"wizwizch"],
    ['text'=>$normal . "%",'callback_data'=>"editAgentDiscountNormal" . $agentId . "_0"],
    ['text'=>"عمومی",'callback_data'=>"wizwizch"]];            
    
    if($botState['agencyPlanDiscount']=="on"){
        foreach($discounts['plans'] as $planId=>$discount){
            $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id` = ?");
            $stmt->bind_param('i', $planId);
            $stmt->execute();
            $info = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            $stmt = $connection->prepare("SELECT * FROM `server_categories` WHERE `id` = ?");
            $stmt->bind_param("i", $info['catid']);
            $stmt->execute();
            $catInfo = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            $keys[] = [['text'=>"❌",'callback_data'=>"removePercentOfAgentPlan" . $agentId . "_" . $planId],
            ['text'=>$discount . "%",'callback_data'=>"editAgentDiscountPlan" . $agentId . "_" . $planId],
            ['text'=>$info['title'] . " " . $catInfo['title'],'callback_data'=>"wizwizch"]];            
        }
    }else{
        foreach($discounts['servers'] as $serverId=>$discount){
            $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id` = ?");
            $stmt->bind_param('i', $serverId);
            $stmt->execute();
            $info = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            $keys[] = [['text'=>"❌",'callback_data'=>"removePercentOfAgentServer" . $agentId . "_" . $serverId],
            ['text'=>$discount . "%",'callback_data'=>"editAgentDiscountServer" . $agentId . "_" . $serverId],
            ['text'=>$info['title'],'callback_data'=>"wizwizch"]];            
        }                
    }
    if($botState['agencyPlanDiscount']=="on")$keys[] = [['text' => "افزودن تخفیف پلن", 'callback_data' => "addDiscountPlanAgent" . $agentId]];
    else $keys[] = [['text' => "افزودن تخفیف سرور", 'callback_data' => "addDiscountServerAgent" . $agentId]];
    $keys[] = [['text' => $buttonValues['back_button'], 'callback_data' => "agentsList"]];
    return json_encode(['inline_keyboard'=>$keys]);
}
function NOWPayments($method, $endpoint, $datas = []){
    global $paymentKeys;

    $base_url = 'https://api.nowpayments.io/v1/';

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

    switch ($method) {
        case 'GET':
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-API-KEY: ' . $paymentKeys['nowpayment']]);
            if(!empty($datas)) {
                if(is_array($datas)) {
                    $parameters = http_build_query($datas);
                    curl_setopt($ch, CURLOPT_URL, $base_url . $endpoint . '?' . $parameters);
                } else {
                    if($endpoint == 'payment') curl_setopt($ch, CURLOPT_URL,$base_url . $endpoint . '/' . $datas);
                }
            } else {
                curl_setopt($ch, CURLOPT_URL, $base_url . $endpoint);
            }
            break;

        case 'POST':
            $datas = json_encode($datas);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-API-KEY: ' . $paymentKeys['nowpayment'], 'Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $datas);
            curl_setopt($ch, CURLOPT_URL, $base_url . $endpoint);
            break;

        default:
            break;
    }

    $res = curl_exec($ch);
    
    if(curl_error($ch)) var_dump(curl_error($ch));
    else return json_decode($res);
}
function getServerConfigKeys($serverId,$offset = 0){
    global $connection, $mainValues, $buttonValues;
    $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
    $stmt->bind_param("i", $serverId);
    $stmt->execute();
    $cats= $stmt->get_result();
    $stmt->close();
    
    $cty = $cats->fetch_assoc();
    $id = $cty['id'];
    $cname = $cty['title'];
    $flagwizwiz = $cty['flag'];
    $remarkwizwiz = $cty['remark'];
    $ucount = $cty['ucount'];
    $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id`=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $serverConfig= $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $reality = $serverConfig['reality']=="true"?$buttonValues['active']:$buttonValues['deactive'];
    $panelUrl = $serverConfig['panel_url'];
    $sni = !empty($serverConfig['sni'])?$serverConfig['sni']:" ";
    $headerType = !empty($serverConfig['header_type'])?$serverConfig['header_type']:" ";
    $requestHeader = !empty($serverConfig['request_header'])?$serverConfig['request_header']:" ";
    $responseHeader = !empty($serverConfig['response_header'])?$serverConfig['response_header']:" ";
    $security = !empty($serverConfig['security'])?$serverConfig['security']:" ";
    $portType = $serverConfig['port_type']=="auto"?"خودکار":"تصادفی";
    $serverType = " ";
    switch ($serverConfig['type']){
        case "sanaei":
            $serverType = "سنایی";
            break;
        case "alireza":
            $serverType = "علیرضا";
            break;
        case "normal":
            $serverType = "ساده";
            break;
        case "marzban":
            $serverType = "مرزبان";
            break;
    }
    return json_encode(['inline_keyboard'=>array_merge([
        [
            ['text'=>$panelUrl,'callback_data'=>"wizwizch"],
            ],
        [
            ['text'=>$cname,'callback_data'=>"editServerName$id"],
            ['text'=>"❕نام سرور",'callback_data'=>"wizwizch"]
            ],
        [
            ['text'=>$flagwizwiz,'callback_data'=>"editServerFlag$id"],
            ['text'=>"🚩 پرچم سرور",'callback_data'=>"wizwizch"]
            ],
        [
            ['text'=>$remarkwizwiz,'callback_data'=>"editServerRemark$id"],
            ['text'=>"📣 ریمارک سرور",'callback_data'=>"wizwizch"]
            ],
        [
            ['text'=>$serverType??" ",'callback_data'=>"changeServerType$id"],
            ['text'=>"نوع سرور",'callback_data'=>"wizwizch"]
            ],
        [
            ['text'=>$ucount,'callback_data'=>"editServerMax$id"],
            ['text'=>"ظرفیت سرور",'callback_data'=>"wizwizch"]
            ]
            ],
            ($serverConfig['type'] != "marzban"?[
        [
            ['text'=>$portType,'callback_data'=>"changePortType$id"],
            ['text'=>"نوع پورت",'callback_data'=>"wizwizch"]
            ],
        [
            ['text'=>$sni,'callback_data'=>"editsServersni$id"],
            ['text'=>"sni",'callback_data'=>"wizwizch"],
            ],
        [
            ['text'=>$headerType,'callback_data'=>"editsServerheader_type$id"],
            ['text'=>"header type",'callback_data'=>"wizwizch"],
            ],
        [
            ['text'=>$requestHeader,'callback_data'=>"editsServerrequest_header$id"],
            ['text'=>"request header",'callback_data'=>"wizwizch"],
            ],
        [
            ['text'=>$responseHeader,'callback_data'=>"editsServerresponse_header$id"],
            ['text'=>"response header",'callback_data'=>"wizwizch"],
            ],
        [
            ['text'=>$security,'callback_data'=>"editsServersecurity$id"],
            ['text'=>"security",'callback_data'=>"wizwizch"],
            ],
        (($serverConfig['type'] == "sanaei" || $serverConfig['type'] == "alireza")?
        [
            ['text'=>$reality,'callback_data'=>"changeRealityState$id"],
            ['text'=>"reality",'callback_data'=>"wizwizch"],
            ]:[]),
        [
            ['text'=>"♻️ تغییر آیپی های سرور",'callback_data'=>"changesServerIp$id"],
            ],
        [
            ['text'=>"♻️ تغییر security setting",'callback_data'=>"editsServertlsSettings$id"],
            ]
            ]:[]),[
        [
			['text'=>"🔅 تغییر اطلاعات ورود",'callback_data'=>"changesServerLoginInfo$id"],
		],
		[
			['text'=>"🔗 لینک اشتراک کمکی",'callback_data'=>"editSubLinks$id"],
		],
		[
			['text'=>"✂️ حذف سرور",'callback_data'=>"wizwizdeleteserver$id"],
		],
        [['text' => $buttonValues['back_button'], 'callback_data' => "nextServerPage" . $offset]]
        ])]);
}
function getServerListKeys($offset = 0){
    global $connection, $mainValues, $buttonValues;
    
    $limit = 15;
    
    $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `active`=1 LIMIT ? OFFSET ?");
    $stmt->bind_param('ii', $limit, $offset);
    $stmt->execute();
    $cats= $stmt->get_result();
    $stmt->close();


    $keys = array();
    $keys[] = [['text'=>"وضعیت",'callback_data'=>"wizwizch"],['text'=>"تنظیمات",'callback_data'=>"wizwizch"],['text'=>"نوع",'callback_data'=>"wizwizch"],['text'=>"سرور",'callback_data'=>"wizwizch"]];
    if($cats->num_rows == 0){
        $keys[] = [['text'=>"سروری یافت نشد",'callback_data'=>"wizwizch"]];
    }else {
        while($cty = $cats->fetch_assoc()){
            $id = $cty['id'];
            $cname = $cty['title'];
            $flagwizwiz = $cty['flag'];
            $remarkwizwiz = $cty['remark'];
            $state = $cty['state'] == "1"?$buttonValues['active']:$buttonValues['deactive'];
            $ucount = $cty['ucount'];
            $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id`=?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $serverTypeInfo= $stmt->get_result()->fetch_assoc();
            $stmt->close(); 
            $portType = $serverTypeInfo['port_type']=="auto"?"خودکار":"تصادفی";
            $serverType = " ";
            switch ($serverTypeInfo['type']){
                case "sanaei":
                    $serverType = "سنایی";
                    break;
                case "alireza":
                    $serverType = "علیرضا";
                    break;
                case "normal":
                    $serverType = "ساده";
                    break;
                case "marzban":
                    $serverType = "مرزبان";
                    break;
            }
            $keys[] = [['text'=>$state,'callback_data'=>'toggleServerState' . $id . "_" . $offset],['text'=>"⚙️",'callback_data'=>"showServerSettings" . $id . "_" . $offset],['text'=>$serverType??" ",'callback_data'=>"wizwizch"],['text'=>$cname,'callback_data'=>"wizwizch"]];
        } 
    }
    if($offset == 0 && $cats->num_rows >= $limit){
        $keys[] = [['text'=>" »» صفحه بعدی »»",'callback_data'=>"nextServerPage" . ($offset + $limit)]];
    }
    elseif($cats->num_rows >= $limit){
        $keys[] = [
            ['text'=>" »» صفحه بعدی »»",'callback_data'=>"nextServerPage" . ($offset + $limit)],
            ['text'=>" «« صفحه قبلی ««",'callback_data'=>"nextServerPage" . ($offset - $limit)]
            ];
    }
    elseif($offset != 0){
        $keys[] = [['text'=>" «« صفحه قبلی ««",'callback_data'=>"nextServerPage" . ($offset - $limit)]];
    }
    $keys[] = [
        ['text'=>'➕ ثبت سرور xui','callback_data'=>"addNewServer"],
        ['text'=>"➕ ثبت سرور مرزبان",'callback_data'=>"addNewMarzbanPanel"]
        ];
    $keys[] = [['text' => $buttonValues['back_button'], 'callback_data' => "managePanel"]];
    return json_encode(['inline_keyboard'=>$keys]);
}
function getCategoriesKeys($offset = 0){
    $limit = 15;
    
    global $connection, $mainValues, $buttonValues;
    $stmt = $connection->prepare("SELECT * FROM `server_categories` WHERE `active`=1 AND `parent`=0 LIMIT ? OFFSET ?");
    $stmt->bind_param('ii', $limit, $offset);
    $stmt->execute();
    $cats = $stmt->get_result();
    $stmt->close();


    $keys = array();
    $keys[] = [['text'=>"حذف",'callback_data'=>"wizwizch"],['text'=>"اسم دسته",'callback_data'=>"wizwizch"]];
    if($cats->num_rows == 0){
        $keys[] = [['text'=>"دسته بندی یافت نشد.",'callback_data'=>"wizwizch"]];
    }else {
        while($cty = $cats->fetch_assoc()){
            $id = $cty['id'];
            $cname = $cty['title'];
            $keys[] = [['text'=>"❌",'callback_data'=>"wizwizcategorydelete$id" . "_" . $offset],['text'=>$cname,'callback_data'=>"wizwizcategoryedit$id" . "_" . $offset]];
        }
    }
    
    if($offset == 0 && $cats->num_rows >= $limit){
        $keys[] = [['text'=>" »» صفحه بعدی »»",'callback_data'=>"nextCategoryPage" . ($offset + $limit)]];
    }
    elseif($cats->num_rows >= $limit){
        $keys[] = [
            ['text'=>" »» صفحه بعدی »»",'callback_data'=>"nextCategoryPage" . ($offset + $limit)],
            ['text'=>" «« صفحه قبلی ««",'callback_data'=>"nextCategoryPage" . ($offset - $limit)]
            ];
    }
    elseif($offset != 0){
        $keys[] = [['text'=>" «« صفحه قبلی ««",'callback_data'=>"nextCategoryPage" . ($offset - $limit)]];
    }
    
    $keys[] = [['text'=>'➕ افزودن دسته جدید','callback_data'=>"addNewCategory"]];
    $keys[] = [['text' => $buttonValues['back_button'], 'callback_data' => "managePanel"]];
    return json_encode(['inline_keyboard'=>$keys]);
}
function getGateWaysKeys(){
    global $connection, $mainValues, $buttonValues;
    
    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'BOT_STATES'");
    $stmt->execute();
    $botState = $stmt->get_result()->fetch_assoc()['value'];
    if(!is_null($botState)) $botState = json_decode($botState,true);
    else $botState = array();
    $stmt->close();
    
    $cartToCartState = $botState['cartToCartState']=="on"?$buttonValues['on']:$buttonValues['off'];
    $walletState = $botState['walletState']=="on"?$buttonValues['on']:$buttonValues['off'];
    $sellState = $botState['sellState']=="on"?$buttonValues['on']:$buttonValues['off'];
    $weSwapState = $botState['weSwapState']=="on"?$buttonValues['on']:$buttonValues['off'];
    $robotState = $botState['botState']=="on"?$buttonValues['on']:$buttonValues['off'];
    $nowPaymentWallet = $botState['nowPaymentWallet']=="on"?$buttonValues['on']:$buttonValues['off'];
    $nowPaymentOther = $botState['nowPaymentOther']=="on"?$buttonValues['on']:$buttonValues['off'];
    $tronWallet = $botState['tronWallet']=="on"?$buttonValues['on']:$buttonValues['off'];
    $zarinpal = $botState['zarinpal']=="on"?$buttonValues['on']:$buttonValues['off'];
    $nextpay = $botState['nextpay']=="on"?$buttonValues['on']:$buttonValues['off'];
    $rewaredChannel = $botState['rewardChannel']??" ";
    $lockChannel = $botState['lockChannel']??" ";
	$supportID = $botState['supportID']??" ";

    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'PAYMENT_KEYS'");
    $stmt->execute();
    $paymentKeys = $stmt->get_result()->fetch_assoc()['value'];
    if(!is_null($paymentKeys)) $paymentKeys = json_decode($paymentKeys,true);
    else $paymentKeys = array();
    $stmt->close();
    return json_encode(['inline_keyboard'=>[
        [
            ['text'=>(!empty($paymentKeys['bankAccount'])?$paymentKeys['bankAccount']:" "),'callback_data'=>"changePaymentKeysbankAccount"],
            ['text'=>"شماره حساب",'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>(!empty($paymentKeys['holderName'])?$paymentKeys['holderName']:" "),'callback_data'=>"changePaymentKeysholderName"],
            ['text'=>"دارنده حساب",'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>(!empty($paymentKeys['nowpayment'])?$paymentKeys['nowpayment']:" "),'callback_data'=>"changePaymentKeysnowpayment"],
            ['text'=>"کد درگاه nowPayment",'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>(!empty($paymentKeys['zarinpal'])?$paymentKeys['zarinpal']:" "),'callback_data'=>"changePaymentKeyszarinpal"],
            ['text'=>"کد درگاه زرین پال",'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>(!empty($paymentKeys['nextpay'])?$paymentKeys['nextpay']:" "),'callback_data'=>"changePaymentKeysnextpay"],
            ['text'=>"کد درگاه نکست پی",'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>(!empty($paymentKeys['tronwallet'])?$paymentKeys['tronwallet']:" "),'callback_data'=>"changePaymentKeystronwallet"],
            ['text'=>"آدرس والت ترون",'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$weSwapState,'callback_data'=>"changeGateWaysweSwapState"],
            ['text'=>"درگاه وی سواپ",'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$cartToCartState,'callback_data'=>"changeGateWayscartToCartState"],
            ['text'=>"کارت به کارت",'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$nextpay,'callback_data'=>"changeGateWaysnextpay"],
            ['text'=>"درگاه نکست پی",'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$zarinpal,'callback_data'=>"changeGateWayszarinpal"],
            ['text'=>"درگاه زرین پال",'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$nowPaymentWallet,'callback_data'=>"changeGateWaysnowPaymentWallet"],
            ['text'=>"درگاه NowPayment کیف پول",'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$nowPaymentOther,'callback_data'=>"changeGateWaysnowPaymentOther"],
            ['text'=>"درگاه NowPayment سایر",'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$tronWallet,'callback_data'=>"changeGateWaystronWallet"],
            ['text'=>"درگاه ترون",'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$walletState,'callback_data'=>"changeGateWayswalletState"],
            ['text'=>"کیف پول",'callback_data'=>"wizwizch"]
        ],
		[
			['text'=>$supportID, 'callback_data' => 'editSupportID'],
			['text'=>"آیدی پشتیبانی",'callback_data'=>'wizwizch']
		],
        [
            ['text'=>$rewaredChannel,'callback_data'=>'editRewardChannel'],
            ['text'=>"کانال گزارش درآمد",'callback_data'=>'wizwizch']
		],
        [
            ['text'=>$lockChannel,'callback_data'=>'editLockChannel'],
            ['text'=>"کانال قفل",'callback_data'=>'wizwizch']
		],
		[
			['text'=>"معاف کاربر از عضویت در کانال",'callback_data'=>"addExemptUser"]
		],
			[['text'=>$buttonValues['back_button'],'callback_data'=>"managePanel"]]
        ]]);
}
function getBotSettingKeys(){
    global $connection, $mainValues, $buttonValues;
    
    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'BOT_STATES'");
    $stmt->execute();
    $botState = $stmt->get_result()->fetch_assoc()['value'];
    if(!is_null($botState)) $botState = json_decode($botState,true);
    else $botState = array();
    $stmt->close();

    $changeProtocole = $botState['changeProtocolState']=="on"?$buttonValues['on']:$buttonValues['off'];
    $renewAccount = $botState['renewAccountState']=="on"?$buttonValues['on']:$buttonValues['off'];
    $plandelkhahwiz = $botState['plandelkhahState']=="on"?$buttonValues['on']:$buttonValues['off'];
    $switchLocation = $botState['switchLocationState']=="on"?$buttonValues['on']:$buttonValues['off'];
    $increaseTime = $botState['increaseTimeState']=="on"?$buttonValues['on']:$buttonValues['off'];
    $increaseVolume = $botState['increaseVolumeState']=="on"?$buttonValues['on']:$buttonValues['off'];
    $subLink = $botState['subLinkState']=="on"?$buttonValues['on']:$buttonValues['off'];
    $configLink = $botState['configLinkState']=="off"?$buttonValues['off']:$buttonValues['on'];
    $renewConfigLink = $botState['renewConfigLinkState']=="on"?$buttonValues['on']:$buttonValues['off'];
    $updateConfigLink = $botState['updateConfigLinkState']=="on"?$buttonValues['on']:$buttonValues['off'];
    $individualExistence = $botState['individualExistence']=="on"?$buttonValues['on']:$buttonValues['off'];
    $sharedExistence = $botState['sharedExistence']=="on"?$buttonValues['on']:$buttonValues['off'];
    $testAccount = $botState['testAccount']=="on"?$buttonValues['on']:$buttonValues['off'];
    $agency = $botState['agencyState']=="on"?$buttonValues['on']:$buttonValues['off'];
    $agencyPlanDiscount = $botState['agencyPlanDiscount']=="on"?$buttonValues['plan_discount']:$buttonValues['server_discount'];
    $qrConfig = $botState['qrConfigState']=="on"?$buttonValues['on']:$buttonValues['off'];
    $qrSub = $botState['qrSubState']=="on"?$buttonValues['on']:$buttonValues['off'];
	$renewAuto = $botState['renewAutoState'] == "on" ? $buttonValues['on'] : $buttonValues['off'];
    $requirePhone = $botState['requirePhone']=="on"?$buttonValues['on']:$buttonValues['off'];
    $requireIranPhone = $botState['requireIranPhone']=="on"?$buttonValues['on']:$buttonValues['off'];
    $sellState = $botState['sellState']=="on"?$buttonValues['on']:$buttonValues['off'];
    $robotState = $botState['botState']=="on"?$buttonValues['on']:$buttonValues['off'];
    $searchState = $botState['searchState']=="on"?$buttonValues['on']:$buttonValues['off'];
    $updateConnectionState = $botState['updateConnectionState']=="robot"?"از روی ربات":"از روی سایت";
    $rewaredTime = ($botState['rewaredTime']??0) . " ساعت";
    switch($botState['remark']){
        case "digits":
            $remarkType = "عدد رندم 5 حرفی";
            break;
        case "manual":
            $remarkType = "توسط کاربر";
            break;
        default:
            $remarkType = "آیدی و عدد رندوم";
            break;
    }
    
    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'PAYMENT_KEYS'");
    $stmt->execute();
    $paymentKeys = $stmt->get_result()->fetch_assoc()['value'];
    if(!is_null($paymentKeys)) $paymentKeys = json_decode($paymentKeys,true);
    else $paymentKeys = array();
    $stmt->close();
    return json_encode(['inline_keyboard'=>[
        [
            ['text'=>"🎗 بنر بازاریابی 🎗",'callback_data'=>"inviteSetting"]
            ],
        [
            ['text'=> $updateConnectionState,'callback_data'=>"changeUpdateConfigLinkState"],
            ['text'=>"آپدیت کانفیگ",'callback_data'=>"wizwizch"]
            ],
        [
            ['text'=> $agency,'callback_data'=>"changeBotagencyState"],
            ['text'=>"همکاری",'callback_data'=>"wizwizch"]
            ],
        [
            ['text'=> $agencyPlanDiscount,'callback_data'=>"changeBotagencyPlanDiscount"],
            ['text'=>"نوع تخفیف همکاری",'callback_data'=>"wizwizch"]
            ],
        [
            ['text'=>$individualExistence,'callback_data'=>"changeBotindividualExistence"],
            ['text'=>"موجودی اختصاصی",'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$sharedExistence,'callback_data'=>"changeBotsharedExistence"],
            ['text'=>"موجودی اشتراکی",'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$testAccount,'callback_data'=>"changeBottestAccount"],
            ['text'=>"اکانت تست",'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$changeProtocole,'callback_data'=>"changeBotchangeProtocolState"],
            ['text'=>"تغییر پروتکل",'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$renewAccount,'callback_data'=>"changeBotrenewAccountState"],
            ['text'=>"تمدید اشتراک",'callback_data'=>"wizwizch"]
        ],
        [
            ['text' => $renewAuto, 'callback_data' => "changeBotrenewAutoState"],
            ['text'=>"تمدید خودکار اشتراک",'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$plandelkhahwiz,'callback_data'=>"changeBotplandelkhahState"],
            ['text'=>"پلن دلخواه",'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$switchLocation,'callback_data'=>"changeBotswitchLocationState"],
            ['text'=>"تغییر سرویس",'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$increaseTime,'callback_data'=>"changeBotincreaseTimeState"],
            ['text'=>"افزایش زمان",'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$increaseVolume,'callback_data'=>"changeBotincreaseVolumeState"],
            ['text'=>"افزایش حجم",'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$requirePhone,'callback_data'=>"changeBotrequirePhone"],
            ['text'=>"تایید شماره",'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$requireIranPhone,'callback_data'=>"changeBotrequireIranPhone"],
            ['text'=>"تایید شماره ایرانی",'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$sellState,'callback_data'=>"changeBotsellState"],
            ['text'=>"فروش",'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$robotState,'callback_data'=>"changeBotbotState"],
            ['text'=>"وضعیت ربات",'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$subLink,'callback_data'=>"changeBotsubLinkState"],
            ['text'=>"لینک ساب و مشخصات وب",'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$configLink,'callback_data'=>"changeBotconfigLinkState"],
            ['text'=>"لینک کانفیگ",'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$searchState,'callback_data'=>"changeBotsearchState"],
            ['text'=>"مشخصات اشتراک",'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$renewConfigLink,'callback_data'=>"changeBotrenewConfigLinkState"],
            ['text'=>"دریافت لینک جدید",'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$updateConfigLink,'callback_data'=>"changeBotupdateConfigLinkState"],
            ['text'=>"بروزرسانی لینک",'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$qrConfig,'callback_data'=>"changeBotqrConfigState"],
            ['text'=>"کیوآرکد کانفیگ",'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$qrSub,'callback_data'=>"changeBotqrSubState"],
            ['text'=>"کیوآرکد اشتراک",'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$remarkType,'callback_data'=>"changeConfigRemarkType"],
            ['text'=>"نوع ریمارک",'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$rewaredTime,'callback_data'=>'editRewaredTime'],
            ['text'=>"ارسال گزارش درآمد", 'callback_data'=>'wizwizch']
            ],
        [
            ['text'=>$botState['cartToCartAutoAcceptState']=="on"?$buttonValues['on']:$buttonValues['off'],'callback_data'=>"changeBotcartToCartAutoAcceptState"],
            ['text'=>"تایید خودکار کارت به کارت",'callback_data'=>"wizwizch"]
        ],
        ($botState['cartToCartAutoAcceptState']=="on"?[
            ['text'=>($botState['cartToCartAutoAcceptType'] == "0"?"همکار":($botState['cartToCartAutoAcceptType'] == "1"?"کاربر":"همه")),'callback_data'=>"changeBotcartToCartAutoAcceptType"],
            ['text'=>"نوع تایید",'callback_data'=>"wizwizch"]
        ]:[]),
        ($botState['cartToCartAutoAcceptState']=="on"?[
            ['text'=>($botState['cartToCartAutoAcceptTime']??"10") . " دقیقه",'callback_data'=>"editcartToCartAutoAcceptTime"],
            ['text'=>"زمان تایید خودکار ",'callback_data'=>"wizwizch"]
        ]:[]),
        [['text'=>$buttonValues['back_button'],'callback_data'=>"managePanel"]]
        ]]);
}

// ایجاد بخش آمار ربات
function fetchData($sql, $params = []) {
    global $connection;
    $stmt = $connection->prepare($sql);
    
    if ($params) {
        $stmt->bind_param(...$params);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();

    return $result;
}
function sendChunkedMessages($data, $messageType) {
    global $from_id;
    $chunks = array_chunk($data, 50); // تقسیم به پیام های 20تایی

    foreach ($chunks as $index => $group) {
        $text = ($index === 0) ? "$messageType:\n\n" : "";
        foreach ($group as $row) {
            // استفاده از user_id برای جدول pays
            $userId = isset($row['user_id']) ? $row['user_id'] : (isset($row['userid']) ? $row['userid'] : 'N/A');
            $amount = number_format($row['wallet'] ?? $row['total_orders'] ?? $row['total_paid']);
            
            $text .= "👤 شناسه کاربر: <code>$userId</code>\n";
            
            if (isset($row['wallet'])) {
                $text .= "💰 موجودی: <b>$amount تومان</b>\n\n";
            } elseif (isset($row['total_orders'])) {
                $text .= "📦 تعداد اشتراک‌ها: <b>$amount</b>\n\n";
            } else {
                $text .= "💰 مبلغ پرداختی: <b>$amount تومان</b>\n\n";
            }
        }

        sendMessage($text, null, "html", $from_id);
    }
}

function getBotReportKeys($data = null) {
    global $connection, $from_id;

    // منوی اصلی آمار
    if ($data == null || $data == 'show_stats_menu') {
        return json_encode(['inline_keyboard' => [
            [['text' => '👥 آمار کاربران', 'callback_data' => 'stats_users']],
            [['text' => '📦 آمار اشتراک‌ها', 'callback_data' => 'stats_orders']],
            [['text' => '💰 آمار فروش', 'callback_data' => 'stats_sales']],
            [['text' => '🔙 بازگشت به مدیریت', 'callback_data' => 'managePanel']]
        ]]);
    }

    // بررسی دکمه‌ها
    $queries = [
        'top_50_wallet' => "SELECT userid, wallet FROM users WHERE isAdmin != 1 AND id > 4 ORDER BY wallet DESC LIMIT 50",
        'top_50_orders' => "SELECT userid, COUNT(*) as total_orders FROM orders_list GROUP BY userid ORDER BY total_orders DESC LIMIT 50",
        'top_50_users_pays' => "SELECT user_id, SUM(price) as total_paid FROM pays WHERE state IN ('paid', 'approved', 'auto_cart_approved') GROUP BY user_id ORDER BY total_paid DESC LIMIT 50"
    ];

    if (isset($queries[$data])) {
        $result = fetchData($queries[$data]);
        if ($result->num_rows > 0) {
            $users = [];
            while ($row = $result->fetch_assoc()) {
                $users[] = $row;
            }

            // بررسی اینکه کدام دکمه انتخاب شده است و ارسال پیام مناسب
            if ($data == 'top_50_wallet') {
                sendChunkedMessages($users, "📈 کاربران با بیشترین موجودی");
            } elseif ($data == 'top_50_orders') {
                sendChunkedMessages($users, "📦 کاربران با بیشترین تعداد اشتراک");
            } elseif ($data == 'top_50_users_pays') {
                sendChunkedMessages($users, "🛍 کاربران با بیشترین حجم خرید");
            }
        } else {
            sendMessage("❌ کوئری مشکلی داشت یا داده‌ها موجود نیستند", null, "html", $from_id);
        }
        return false;
    }

    // بررسی دکمه 'agent_users_list'
    if ($data == 'agent_users_list') {
        $sql = "SELECT userid, agent_date FROM users WHERE is_agent = 1 AND userid > 4";
        $result = fetchData($sql);

        if ($result->num_rows > 0) {
            $agents = [];
            while ($row = $result->fetch_assoc()) {
                $agents[] = $row;
            }
            $chunks = array_chunk($agents, 15);
            foreach ($chunks as $index => $group) {
                $text = "🧑‍💼 لیست کاربران همکار:\n\n";
                foreach ($group as $row) {
                    $userId = $row['userid'];
                    $agentDate = $row['agent_date'];
                    $formattedDate = date("Y-m-d H:i:s", $agentDate);

                    // تعداد اشتراک‌ها
                    $orderResult = fetchData("SELECT COUNT(*) as total_orders FROM orders_list WHERE userid = ?", ["i", $userId])->fetch_assoc();
                    $totalOrders = $orderResult['total_orders'];

                    // مجموع مبلغ پرداخت‌ها
                    $paymentResult = fetchData("SELECT SUM(price) as total_paid FROM pays WHERE user_id = ? AND state IN ('paid', 'approved', 'auto_cart_approved')", ["i", $userId])->fetch_assoc();
                    $totalPaid = number_format($paymentResult['total_paid']);

                    // افزودن اطلاعات به متن پیام
                    $text .= "👤 شناسه کاربر: <code>$userId</code>\n";
                    $text .= "📦 تعداد اشتراک‌ها: <b>$totalOrders</b>\n";
                    $text .= "💰 مجموع پرداخت‌ها: <b>$totalPaid تومان</b>\n";
                    $text .= "📅 تاریخ شروع همکاری: <b>$formattedDate</b>\n\n";
                }
                sendMessage($text, null, "html", $from_id);
            }
        } else {
            sendMessage("❌ هیچ کاربر همکاری یافت نشد.", null, "html", $from_id);
        }
        return false;
    }

    // بررسی دکمه 'stats_users'
    if ($data == 'stats_users') {
        $allUsers = fetchData("SELECT * FROM `users`")->num_rows;
        $allOrders = fetchData("SELECT * FROM `orders_list`")->num_rows;
        $usersWithOrders = fetchData("SELECT COUNT(DISTINCT userid) as total FROM `orders_list`")->fetch_assoc()['total'];
        $usersWithoutOrders = $allUsers - $usersWithOrders;
        $walletSum = number_format(fetchData("SELECT SUM(wallet) as total FROM users WHERE isAdmin != 1 AND id > 4")->fetch_assoc()['total']);
        $agentUsers = fetchData("SELECT COUNT(*) as total FROM users WHERE is_agent = 1")->fetch_assoc()['total'];
        
        $dayStart = strtotime("today");
        $newToday = fetchData("SELECT COUNT(*) as total FROM users WHERE date >= ?", ["i", $dayStart])->fetch_assoc()['total'];

        $weekStart = strtotime("-7 days");
        $newWeek = fetchData("SELECT COUNT(*) as total FROM users WHERE date >= ?", ["i", $weekStart])->fetch_assoc()['total'];

        $monthStart = strtotime("-30 days");
        $newMonth = fetchData("SELECT COUNT(*) as total FROM users WHERE date >= ?", ["i", $monthStart])->fetch_assoc()['total'];

        $month3Start = strtotime("-90 days");
        $new3Month = fetchData("SELECT COUNT(*) as total FROM users WHERE date >= ?", ["i", $month3Start])->fetch_assoc()['total'];

        return json_encode(['inline_keyboard' => [
            [['text' => "$allUsers", 'callback_data' => 'wizwizch'], ['text' => "👤 تعداد کل کاربران", 'callback_data' => 'wizwizch']],
            [['text' => "$allOrders", 'callback_data' => 'wizwizch'], ['text' => "🧾 کل اشتراک‌های کاربران", 'callback_data' => 'wizwizch']],
            [['text' => "$usersWithOrders", 'callback_data' => 'wizwizch'], ['text' => "👥 کاربران دارای اشتراک", 'callback_data' => 'wizwizch']],
            [['text' => "$usersWithoutOrders", 'callback_data' => 'wizwizch'], ['text' => "🚫 کاربران بدون اشتراک", 'callback_data' => 'wizwizch']],
            [['text' => "$walletSum تومان", 'callback_data' => 'wizwizch'], ['text' => "💳 مجموع کیف پول کاربران", 'callback_data' => 'wizwizch']],
            [['text' => "$agentUsers", 'callback_data' => 'wizwizch'], ['text' => "🤝 تعداد کاربران همکار", 'callback_data' => 'wizwizch']],
            [['text' => "$newToday", 'callback_data' => 'wizwizch'], ['text' => "🔂 کاربران جدید امروز", 'callback_data' => 'wizwizch']],
            [['text' => "$newWeek", 'callback_data' => 'wizwizch'], ['text' => "📅 کاربران جدید هفته", 'callback_data' => 'wizwizch']],
            [['text' => "$newMonth", 'callback_data' => 'wizwizch'], ['text' => "🗓 کاربران جدید ماه", 'callback_data' => 'wizwizch']],
            [['text' => "$new3Month", 'callback_data' => 'wizwizch'], ['text' => "📊 کاربران جدید ۳ ماه", 'callback_data' => 'wizwizch']],
            [['text' => '📈 کاربران با بیشترین موجودی (50 کاربر)', 'callback_data' => 'top_50_wallet']],
            [['text' => '📦 کاربران با بیشترین تعداد اشتراک (50 کاربر)', 'callback_data' => 'top_50_orders']],
            [['text' => '🛍 کاربران با بیشترین حجم خرید (50 کاربر)', 'callback_data' => 'top_50_users_pays']],
            [['text' => '🧑‍💼 لیست کاربران همکار', 'callback_data' => 'agent_users_list']],
            [['text' => '🔙 بازگشت به آمار', 'callback_data' => 'show_stats_menu']]
        ]]);
    }
	
	// بررسی دکمه 'plan_sales'
	if ($data == 'plan_sales') {
		$sql = "SELECT fileid, COUNT(*) as plan_count FROM orders_list GROUP BY fileid ORDER BY plan_count DESC";
		$result = fetchData($sql);

		if ($result->num_rows > 0) {
			$plans = [];
			while ($row = $result->fetch_assoc()) {
				$plans[] = $row;
			}

			// تقسیم داده ها به بخش های 50تایی
			$chunks = array_chunk($plans, 50);

			foreach ($chunks as $index => $group) {
				$text = "🧮 تعداد اشتراک‌ها بر اساس پلن:\n\n";
				foreach ($group as $row) {
					$planId = $row['fileid'];
					$planCount = $row['plan_count'];

					// استخراج نام پلن از جدول server_plans
					$planNameResult = fetchData("SELECT title FROM server_plans WHERE id = ?", ["i", $planId])->fetch_assoc();
					$planName = $planNameResult ? $planNameResult['title'] : "ناشناس";

					// افزودن اطلاعات به متن پیام
					$text .= "📝 نام پلن: <b>$planName</b>\n";
					$text .= "🔐 تعداد اشتراک‌ها: <b>$planCount</b>\n\n";
				}

				// ارسال پیام برای هر بخش از دکمه‌ها
				sendMessage($text, null, "html", $from_id);
			}
		} else {
			sendMessage("❌ هیچ اشتراکی برای پلن‌ها یافت نشد.", null, "html", $from_id);
		}

		return false;
	}

    // بررسی دکمه 'stats_orders'
    if ($data == 'stats_orders') {
        // تعداد سرورها، دسته ها و پلن ها
        $serverCount = fetchData("SELECT COUNT(*) as total FROM server_info")->fetch_assoc()['total'];
        $categoryCount = fetchData("SELECT COUNT(*) as total FROM server_categories")->fetch_assoc()['total'];
        $planCount = fetchData("SELECT COUNT(*) as total FROM server_plans")->fetch_assoc()['total'];
		
		$todayStart = strtotime("today");
		$weekStart = strtotime("-7 days");
		$monthStart = strtotime("-30 days");
		$threeMonthStart = strtotime("-90 days");
		
        // تعداد خرید جدید در بازه زمانی مختلف
        $ordersToday = fetchData("SELECT COUNT(*) as total FROM orders_list WHERE date >= ?", ["i", $todayStart])->fetch_assoc()['total'];
        $ordersWeek = fetchData("SELECT COUNT(*) as total FROM orders_list WHERE date >= ?", ["i", $weekStart])->fetch_assoc()['total'];
        $ordersMonth = fetchData("SELECT COUNT(*) as total FROM orders_list WHERE date >= ?", ["i", $monthStart])->fetch_assoc()['total'];
        $ordersThreeMonth = fetchData("SELECT COUNT(*) as total FROM orders_list WHERE date >= ?", ["i", $threeMonthStart])->fetch_assoc()['total'];

        // تعداد تمدیدها در بازه زمانی مختلف
        $renewalsToday = fetchData("SELECT COUNT(*) as total FROM increase_order WHERE date >= ?", ["i", $todayStart])->fetch_assoc()['total'];
        $renewalsWeek = fetchData("SELECT COUNT(*) as total FROM increase_order WHERE date >= ?", ["i", $weekStart])->fetch_assoc()['total'];
        $renewalsMonth = fetchData("SELECT COUNT(*) as total FROM increase_order WHERE date >= ?", ["i", $monthStart])->fetch_assoc()['total'];
        $renewalsThreeMonth = fetchData("SELECT COUNT(*) as total FROM increase_order WHERE date >= ?", ["i", $threeMonthStart])->fetch_assoc()['total'];

        // تعداد اشتراک‌های سرور
        $servers = fetchData("SELECT id, title FROM server_info")->fetch_all(MYSQLI_ASSOC);

        // ایجاد دکمه‌ها برای سرورها
        $serverButtons = [];
        foreach ($servers as $server) {
            $serverId = $server['id'];
            $serverTitle = $server['title'];
			
            $serverSubscriptions = fetchData("SELECT COUNT(*) as total FROM orders_list WHERE server_id = ?", ["i", $serverId])->fetch_assoc()['total'];

            // ایجاد دکمه برای هر سرور
			$serverButtons[] = ['text' => "$serverSubscriptions اشتراک", 'callback_data' => "server_$serverId"."_subscriptions"];
            $serverButtons[] = ['text' => "$serverTitle", 'callback_data' => "server_$serverId"];
        }
		$serverButtonsChunked = array_chunk($serverButtons, 2);
	
        return json_encode([
            'inline_keyboard' => [
                [['text' => "$serverCount", 'callback_data' => 'wizwizch'], ['text' => "🌐 تعداد سرورها", 'callback_data' => 'wizwizch']],
                [['text' => "$categoryCount", 'callback_data' => 'wizwizch'], ['text' => "📦 تعداد دسته‌بندی‌ها", 'callback_data' => 'wizwizch']],
                [['text' => "$planCount", 'callback_data' => 'wizwizch'], ['text' => "📝 تعداد پلن‌ها", 'callback_data' => 'wizwizch']],
				[['text' => '🛒 آمار اشتراک‌ها بر اساس خرید جدید', 'callback_data' => 'wizwizch']],
                [['text' => "$ordersToday اشتراک", 'callback_data' => 'wizwizch'], ['text' => "🛍 تعداد خرید جدید امروز", 'callback_data' => 'wizwizch']],
                [['text' => "$ordersWeek اشتراک", 'callback_data' => 'wizwizch'], ['text' => "🛍 تعداد خرید جدید هفته", 'callback_data' => 'wizwizch']],
                [['text' => "$ordersMonth اشتراک", 'callback_data' => 'wizwizch'], ['text' => "🛍 تعداد خرید جدید ماه", 'callback_data' => 'wizwizch']],
                [['text' => "$ordersThreeMonth اشتراک", 'callback_data' => 'wizwizch'], ['text' => "🛍 تعداد خرید جدید سه ماه", 'callback_data' => 'wizwizch']],
				[['text' => '♻️ آمار اشتراک‌ها بر اساس تمدید', 'callback_data' => 'wizwizch']],
                [['text' => "$renewalsToday اشتراک", 'callback_data' => 'wizwizch'], ['text' => "🔄 تعداد تمدیدهای امروز", 'callback_data' => 'wizwizch']],
                [['text' => "$renewalsWeek اشتراک", 'callback_data' => 'wizwizch'], ['text' => "🔄 تعداد تمدیدهای هفته", 'callback_data' => 'wizwizch']],
                [['text' => "$renewalsMonth اشتراک", 'callback_data' => 'wizwizch'], ['text' => "🔄 تعداد تمدیدهای ماه", 'callback_data' => 'wizwizch']],
                [['text' => "$renewalsThreeMonth اشتراک", 'callback_data' => 'wizwizch'], ['text' => "🔄 تعداد تمدیدهای سه ماه", 'callback_data' => 'wizwizch']],
				[['text' => '🌐 تفکیک اشتراک‌ها بر اساس هر سرور', 'callback_data' => 'wizwizch']],
                ...$serverButtonsChunked,
				[['text' => '🗂 مشاهده تعداد اشتراک‌ها بر اساس هر پلن', 'callback_data' => 'plan_sales']],
				[['text' => '🔙 بازگشت به آمار', 'callback_data' => 'show_stats_menu']]
            ]
        ]);
    }
	
	// بررسی دکمه‌های 50 پرداخت برتر
	// این بخش عمداً شبیه هندلر قبلی نوشته شده تا دکمه قبلی هم بدون تغییر کار کند.
	if (in_array($data, ['top_50_pays', 'top_50_pays_month', 'top_50_pays_week', 'top_50_pays_24h'])) {
		$endTimestamp = time();
		$params = [];
		$title = "💎 50 بیشترین پرداخت تا به امروز:";
		$sql = "SELECT price, user_id, request_date FROM pays WHERE state IN ('paid', 'approved', 'auto_cart_approved')";

		if ($data == 'top_50_pays_month') {
			$startTimestamp = strtotime('-30 days');
			$sql .= " AND request_date BETWEEN ? AND ?";
			$params = ["ii", $startTimestamp, $endTimestamp];
			$title = "💎 50 بیشترین پرداخت 30 روز گذشته:";
		} elseif ($data == 'top_50_pays_week') {
			$startTimestamp = strtotime('-7 days');
			$sql .= " AND request_date BETWEEN ? AND ?";
			$params = ["ii", $startTimestamp, $endTimestamp];
			$title = "💎 50 بیشترین پرداخت 7 روز گذشته:";
		} elseif ($data == 'top_50_pays_24h') {
			$startTimestamp = strtotime('-24 hours');
			$sql .= " AND request_date BETWEEN ? AND ?";
			$params = ["ii", $startTimestamp, $endTimestamp];
			$title = "💎 50 بیشترین پرداخت 24 ساعت گذشته:";
		}

		$sql .= " ORDER BY price DESC LIMIT 50";
		$result = fetchData($sql, $params);

		if ($result && $result->num_rows > 0) {
			$topPayments = [];
			while ($row = $result->fetch_assoc()) {
				$topPayments[] = $row;
			}
		
			$chunks = array_chunk($topPayments, 15);

			foreach ($chunks as $index => $group) {
				$text = "$title\n\n";
				foreach ($group as $row) {
					$amount = number_format($row['price']);
					$userId = $row['user_id'];
					$payDate = !empty($row['request_date']) ? date("Y-m-d H:i", (int)$row['request_date']) : "نامشخص";

					$text .= "💰 مبلغ پرداختی: <b>$amount</b>\n";
					$text .= "👤 شناسه کاربر: <code>$userId</code>\n";
					$text .= "🕒 تاریخ پرداخت: <b>$payDate</b>\n\n";
				}

				sendMessage($text, null, "html", $from_id);
			}
		} else {
			sendMessage("❌ هیچ پرداختی با وضعیت مورد نظر در این بازه یافت نشد.", null, "html", $from_id);
		}

		return false;
	}

	// بررسی دکمه 'stats_sales'
    if ($data == 'stats_sales') {
		
		$todayStart = strtotime("today");
		$weekStart = strtotime("-7 days");
		$monthStart = strtotime("-30 days");
		$threeMonthStart = strtotime("-90 days");
		$sixMonthStart = strtotime("-180 days");
		$oneYearStart = strtotime("-365 days");
		
        // محاسبه فروش بر اساس (paid، approved، auto_cart_approved)
		$salesTotalResult = fetchData("SELECT SUM(price) as total FROM pays WHERE state IN ('paid', 'approved', 'auto_cart_approved')");
		$salesTotal = $salesTotalResult->fetch_assoc()['total'] ?? 0;
		$salesTodayResult = fetchData("SELECT SUM(price) as total FROM pays WHERE request_date >= ? AND state IN ('paid', 'approved', 'auto_cart_approved')", ["i", $todayStart]);
		$salesToday = $salesTodayResult->fetch_assoc()['total'] ?? 0;
		$salesWeekResult = fetchData("SELECT SUM(price) as total FROM pays WHERE request_date >= ? AND state IN ('paid', 'approved', 'auto_cart_approved')", ["i", $weekStart]);
		$salesWeek = $salesWeekResult->fetch_assoc()['total'] ?? 0;
		$salesMonthResult = fetchData("SELECT SUM(price) as total FROM pays WHERE request_date >= ? AND state IN ('paid', 'approved', 'auto_cart_approved')", ["i", $monthStart]);
		$salesMonth = $salesMonthResult->fetch_assoc()['total'] ?? 0;
		$salesThreeMonthResult = fetchData("SELECT SUM(price) as total FROM pays WHERE request_date >= ? AND state IN ('paid', 'approved', 'auto_cart_approved')", ["i", $threeMonthStart]);
		$salesThreeMonth = $salesThreeMonthResult->fetch_assoc()['total'] ?? 0;
		$salesSixMonthResult = fetchData("SELECT SUM(price) as total FROM pays WHERE request_date >= ? AND state IN ('paid', 'approved', 'auto_cart_approved')", ["i", $sixMonthStart]);
		$salesSixMonth = $salesSixMonthResult->fetch_assoc()['total'] ?? 0;
		$salesYearResult = fetchData("SELECT SUM(price) as total FROM pays WHERE request_date >= ? AND state IN ('paid', 'approved', 'auto_cart_approved')", ["i", $oneYearStart]);
		$salesYear = $salesYearResult->fetch_assoc()['total'] ?? 0;
		
		// تابع برای استخراج کاربران همکار
		function getAgentUsers() {
			global $connection;
			// کوئری برای پیدا کردن کاربران همکار
			$query = "SELECT userid FROM users WHERE is_agent = 1";
			$result = $connection->query($query);
			if ($result && $result->num_rows > 0) {
				$agentUsers = [];
				// اضافه کردن کاربران به آرایه
				while ($row = $result->fetch_assoc()) {
					$agentUsers[] = $row;
				}
				return $agentUsers;
			}
			return [];
		}
		// تابع برای محاسبه فروش کاربران همکار در بازه‌های زمانی مختلف
		function calculateAgentSales($agentUsers, $startDate, $endDate = null) {
			global $connection;
			$totalSales = 0;

			if ($endDate === null) {
				$endDate = time(); // تاریخ کنونی
			}
			$startDate = date('Y-m-d H:i:s', $startDate);
			$endDate = date('Y-m-d H:i:s', $endDate);

			// یکجا آماده کردن کوئری برای همه کاربران همکار در یکبار
			$userIds = array_map(function($user) { return $user['userid']; }, $agentUsers);
			$userIdsStr = implode(",", $userIds);

			// آماده کردن کوئری برای دریافت مجموع فروش برای همه کاربران
			$query = "SELECT user_id, SUM(price) as total 
				FROM pays 
				WHERE user_id IN ($userIdsStr) 
				AND FROM_UNIXTIME(request_date) BETWEEN ? AND ? 
				AND LOWER(state) IN ('paid', 'approved', 'auto_cart_approved') 
				GROUP BY user_id";

			// اجرای کوئری با پارامترهای مناسب
			$stmt = $connection->prepare($query);
			$stmt->bind_param("ss", $startDate, $endDate);
			$stmt->execute();
			$result = $stmt->get_result();

			// جمع‌آوری نتایج و محاسبه مجموع فروش
			while ($data = $result->fetch_assoc()) {
				if (isset($data['total'])) {
					$totalSales += $data['total'];
				}
			}
			return $totalSales;
		}

		// استخراج کاربران همکار
		$agentUsers = getAgentUsers();

		if (empty($agentUsers)) {
			$agentSalesToday = $agentSalesWeek = $agentSalesMonth = $agentSalesThreeMonth = $agentSalesSixMonth = $agentSalesYear = 0;
		} else {
			// محاسبه فروش برای هر بازه زمانی فقط در صورتی که کاربران همکار موجود باشند
			$agentSalesToday = calculateAgentSales($agentUsers, $todayStart);
			$agentSalesWeek = calculateAgentSales($agentUsers, $weekStart);
			$agentSalesMonth = calculateAgentSales($agentUsers, $monthStart);
			$agentSalesThreeMonth = calculateAgentSales($agentUsers, $threeMonthStart);
			$agentSalesSixMonth = calculateAgentSales($agentUsers, $sixMonthStart);
			$agentSalesYear = calculateAgentSales($agentUsers, $oneYearStart);
		}
		
		// تابع برای استخراج سرورها
		function getServers() {
			global $connection;

			// کوئری برای استخراج عنوان سرورها
			$query = "SELECT id, title FROM server_info";
			
			if ($stmt = $connection->prepare($query)) {
				$stmt->execute();
				$result = $stmt->get_result();
        
				if ($result && $result->num_rows > 0) {
					$servers = [];
		
					while ($row = $result->fetch_assoc()) {
						$servers[] = $row;
					}

					// بازگشت لیست سرورها
					return $servers;
				} else {
					return [];
				}
			} else {
				return [];
			}
		}

		// تابع برای محاسبه فروش کلی هر سرور
		function calculateServerSales($serverId) {
			global $connection;

			// کوئری برای محاسبه فروش کلی هر سرور (باید type != 'INCREASE_WALLET' و state یکی از مقادیر معتبر باشد)
			$query = "SELECT SUM(price) AS total
				FROM pays
				WHERE server_id = ? 
				AND type != 'INCREASE_WALLET'
				AND state IN ('paid', 'approved', 'auto_cart_approved', 'paid_with_wallet')";

			if ($stmt = $connection->prepare($query)) {
				$stmt->bind_param("i", $serverId);  // بایند کردن پارامتر server_id
				$stmt->execute();
				$result = $stmt->get_result();
        
				// بررسی نتایج
				if ($result && $row = $result->fetch_assoc()) {
					return $row['total'] ?? 0;
				} else {
					return 0;
				}
			} else {
				return 0;
			}
		}

		// تابع برای محاسبه فروش ماهانه هر سرور
		function calculateServerMonthlySales($serverId) {
			global $connection;
			$oneMonthAgo = strtotime("-30 days");

			// کوئری برای محاسبه فروش ماهانه هر سرور (باید type != 'INCREASE_WALLET' و state یکی از مقادیر معتبر باشد)
			$query = "SELECT SUM(price) AS total
				FROM pays
				WHERE server_id = ? 
				AND request_date >= ? 
				AND type != 'INCREASE_WALLET'
				AND state IN ('paid', 'approved', 'auto_cart_approved', 'paid_with_wallet')";

			if ($stmt = $connection->prepare($query)) {
				$stmt->bind_param("ii", $serverId, $oneMonthAgo);  // بایند کردن پارامترها
				$stmt->execute();
				$result = $stmt->get_result();
        
				// بررسی نتایج
				if ($result && $row = $result->fetch_assoc()) {
					return $row['total'] ?? 0;
				} else {
					return 0;
				}
			} else {
				return 0;
			}
		}

		$servers = getServers();

		$serverSales = [];
		$serverSalesMonth = [];

		foreach ($servers as $server) {
			// فروش کلی هر سرور
			$totalSales = calculateServerSales($server['id']);
			$serverSales[$server['id']] = $totalSales;

			// فروش ماهانه هر سرور
			$monthlySales = calculateServerMonthlySales($server['id']);
			$serverSalesMonth[$server['id']] = $monthlySales;
		}
		
		// تابع برای استخراج پنج پلن برتر فروش کلی
		function getTopPlans() {
			global $connection;

			// کوئری برای پیدا کردن پنج پلن برتر کل تاریخ
			$query = "SELECT plan_id, SUM(price) as total
					FROM pays
					WHERE type != 'INCREASE_WALLET'
					AND state IN ('paid', 'approved', 'auto_cart_approved', 'paid_with_wallet')
					GROUP BY plan_id
					ORDER BY total DESC
					LIMIT 5";  // فقط پنج پلن برتر

			if ($stmt = $connection->prepare($query)) {
				$stmt->execute();
				$result = $stmt->get_result();

				$topPlans = [];
				while ($row = $result->fetch_assoc()) {
					$topPlans[] = $row;
				}
				return $topPlans;
			} else {
				return [];
			}
		}

		// تابع برای استخراج پنج پلن برتر فروش ماهانه
		function getTopPlansMonth() {
			global $connection;
			$oneMonthAgo = strtotime("-30 days");

			// کوئری برای پیدا کردن پنج پلن برتر ماه
			$query = "SELECT plan_id, SUM(price) as total
					FROM pays
					WHERE type != 'INCREASE_WALLET'
					AND state IN ('paid', 'approved', 'auto_cart_approved', 'paid_with_wallet')
					AND request_date >= ?
					GROUP BY plan_id
					ORDER BY total DESC
					LIMIT 5";  // فقط پنج پلن برتر

			if ($stmt = $connection->prepare($query)) {
				$stmt->bind_param("i", $oneMonthAgo);  // بایند کردن تاریخ ماه گذشته
				$stmt->execute();
				$result = $stmt->get_result();

				$topPlansMonth = [];
				while ($row = $result->fetch_assoc()) {
					$topPlansMonth[] = $row;
				}
				return $topPlansMonth;
			} else {
				return [];
			}
		}

		// تابع برای استخراج نام پلن‌ها از جدول server_plans
		function getPlanTitle($planId) {
			global $connection;

			// کوئری برای استخراج نام پلن
			$query = "SELECT title FROM server_plans WHERE id = ?";

			if ($stmt = $connection->prepare($query)) {
				$stmt->bind_param("i", $planId);  // بایند کردن plan_id
				$stmt->execute();
				$result = $stmt->get_result();

				if ($row = $result->fetch_assoc()) {
					return $row['title'];  // بازگشت نام پلن
				} else {
					return 'Unknown Plan';  // اگر نام پلن یافت نشد
				}
			} else {
				return 'Error';
			}
		}

		// کد اصلی برای ایجاد دکمه‌های پلن‌ها
		$topPlans = getTopPlans();
		$topPlansMonth = getTopPlansMonth();
		
		// تعداد پرداخت‌ها با وضعیت paid_with_wallet
		$totalPaidWithWalletCount = fetchData("SELECT COUNT(*) as total FROM pays WHERE state = 'paid_with_wallet'")->fetch_assoc()['total'] ?? 0;
		// مجموع فروش با وضعیت paid_with_wallet
		$totalPaidWithWalletSum = fetchData("SELECT SUM(price) as total FROM pays WHERE state = 'paid_with_wallet'")->fetch_assoc()['total'] ?? 0;

        // خریدهای نهایی نشده
		$unfinishedPurchases = fetchData("SELECT COUNT(DISTINCT user_id) as total FROM pays WHERE state = 'expired_pending' AND user_id NOT IN (SELECT user_id FROM pays WHERE state IN ('paid', 'approved', 'auto_cart_approved', 'paid_with_wallet'))")->fetch_assoc()['total'] ?? 0;


        return json_encode([
            'inline_keyboard' => [
                [['text' => number_format($salesTotal) . ' تومان', 'callback_data' => 'wizwizch'], ['text' => '📈 فروش کل', 'callback_data' => 'wizwizch']],
                [['text' => number_format($salesToday) . ' تومان', 'callback_data' => 'wizwizch'], ['text' => '🛍 فروش امروز', 'callback_data' => 'wizwizch']],
                [['text' => number_format($salesWeek) . ' تومان', 'callback_data' => 'wizwizch'], ['text' => '🛍 فروش هفته', 'callback_data' => 'wizwizch']],
                [['text' => number_format($salesMonth) . ' تومان', 'callback_data' => 'wizwizch'], ['text' => '🛍 فروش ماه', 'callback_data' => 'wizwizch']],
                [['text' => number_format($salesThreeMonth) . ' تومان', 'callback_data' => 'wizwizch'], ['text' => '🛍 فروش سه ماه اخیر', 'callback_data' => 'wizwizch']],
                [['text' => number_format($salesSixMonth) . ' تومان', 'callback_data' => 'wizwizch'], ['text' => '🛍 فروش شش ماه اخیر', 'callback_data' => 'wizwizch']],
                [['text' => number_format($salesYear) . ' تومان', 'callback_data' => 'wizwizch'], ['text' => '🛍 فروش یکسال اخیر', 'callback_data' => 'wizwizch']],
				[['text' => "$totalPaidWithWalletCount اشتراک", 'callback_data' => 'wizwizch'], ['text' => "💼 تعداد فروش با کیف پول", 'callback_data' => 'wizwizch']],
				[['text' => number_format($totalPaidWithWalletSum) . ' تومان', 'callback_data' => 'wizwizch'], ['text' => '💼 مجموع فروش با کیف پول', 'callback_data' => 'wizwizch']],
				[['text' => "$unfinishedPurchases عدد", 'callback_data' => 'wizwizch'], ['text' => "🚫 خریدهای نهایی نشده", 'callback_data' => 'wizwizch']],
				[['text' => '🛒 آمار فروش همکاری', 'callback_data' => 'wizwizch']],
				[['text' => number_format($agentSalesToday) . ' تومان', 'callback_data' => 'wizwizch'], ['text' => '🤝 فروش همکاری امروز', 'callback_data' => 'wizwizch']],
                [['text' => number_format($agentSalesWeek) . ' تومان', 'callback_data' => 'wizwizch'], ['text' => '🤝 فروش همکاری هفته', 'callback_data' => 'wizwizch']],
                [['text' => number_format($agentSalesMonth) . ' تومان', 'callback_data' => 'wizwizch'], ['text' => '🤝 فروش همکاری ماه', 'callback_data' => 'wizwizch']],
                [['text' => number_format($agentSalesThreeMonth) . ' تومان', 'callback_data' => 'wizwizch'], ['text' => '🤝 فروش همکاری سه ماه', 'callback_data' => 'wizwizch']],
                [['text' => number_format($agentSalesSixMonth) . ' تومان', 'callback_data' => 'wizwizch'], ['text' => '🤝 فروش همکاری شش ماه', 'callback_data' => 'wizwizch']],
                [['text' => number_format($agentSalesYear) . ' تومان', 'callback_data' => 'wizwizch'], ['text' => '🤝 فروش همکاری یکسال', 'callback_data' => 'wizwizch']],
				[['text' => '🌐 آمار فروش کلی هر سرور', 'callback_data' => 'wizwizch']],
				// دکمه های سرورها
				...array_map(function ($server) use ($serverSales) {
					return [
						[
							'text' => number_format($serverSales[$server['id']]) . ' تومان', // میزان فروش کلی
							'callback_data' => 'server_sales_' . $server['id']
						],
						[
							'text' => $server['title'], // عنوان سرور
							'callback_data' => 'server_sales_' . $server['id']
						]
					];
				}, $servers),
				[['text' => '🌙 آمار فروش ماهانه هر سرور', 'callback_data' => 'wizwizch']],
				// دکمه های فروش ماهانه سرورها
				...array_map(function ($server) use ($serverSalesMonth) {
					return [
						[
							'text' => number_format($serverSalesMonth[$server['id']]) . ' تومان', // فروش ماهانه به تومان
							'callback_data' => 'server_sales_month_' . $server['id']
						],
						[
							'text' => $server['title'] . '', // عنوان سرور + ماهانه
							'callback_data' => 'server_sales_month_' . $server['id']
						]
					];
				}, $servers),
				[['text' => '🗂 آمار فروش 5 پلن برتر تاریخ', 'callback_data' => 'wizwizch']],
				// دکمه های پلن های برتر کل تاریخ
				...array_map(function ($plan) {
					$planTitle = getPlanTitle($plan['plan_id']);  // استخراج عنوان پلن
					return [
						[
							'text' => number_format($plan['total']) . ' تومان',  // فروش کل پلن به تومان
							'callback_data' => 'plan_sales_' . $plan['plan_id']
						],
						[
							'text' => $planTitle,  // عنوان پلن
							'callback_data' => 'plan_sales_' . $plan['plan_id']
						]
					];
				}, $topPlans),
				[['text' => '🌛 آمار فروش 5 پلن برتر ماه', 'callback_data' => 'wizwizch']],
				// دکمه های پلن های برتر ماه
				...array_map(function ($plan) {
					$planTitle = getPlanTitle($plan['plan_id']);  // استخراج عنوان پلن
					return [
						[
							'text' => number_format($plan['total']) . ' تومان',  // فروش ماهانه پلن به تومان
							'callback_data' => 'plan_sales_month_' . $plan['plan_id']
						],
						[
							'text' => $planTitle . '',  // عنوان پلن + ماهانه
							'callback_data' => 'plan_sales_month_' . $plan['plan_id']
						]
					];
				}, $topPlansMonth),
                [['text' => '📈 50 بیشترین مبلغ پرداختی تا امروز', 'callback_data' => 'top_50_pays']],
                [['text' => '📅 50 بیشترین مبلغ پرداختی ماه گذشته', 'callback_data' => 'top_50_pays_month']],
                [['text' => '🗓 50 بیشترین مبلغ پرداختی 7 روز گذشته', 'callback_data' => 'top_50_pays_week']],
                [['text' => '⏱ 50 بیشترین مبلغ پرداختی 24 ساعت گذشته', 'callback_data' => 'top_50_pays_24h']],
                [['text' => '📆 مشاهده فروش در بازه دلخواه', 'callback_data' => 'custom_sales_date']],
                [['text' => '🔙 بازگشت به آمار', 'callback_data' => 'show_stats_menu']]
            ]
        ]);
    }
}

if (!function_exists('convertPersianDigitsToEnglishForStats')) {
    function convertPersianDigitsToEnglishForStats($string) {
        $persian = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹','٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
        $english = ['0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9'];
        return str_replace($persian, $english, $string);
    }
}

if (!function_exists('normalizeStatsDate')) {
    function normalizeStatsDate($date) {
        $date = trim(convertPersianDigitsToEnglishForStats($date));
        $date = str_replace('/', '-', $date);

        if (!preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $date, $matches)) {
            return false;
        }

        $year = (int)$matches[1];
        $month = (int)$matches[2];
        $day = (int)$matches[3];

        if (!checkdate($month, $day, $year)) {
            return false;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }
}

if (!function_exists('parseStatsDateRange')) {
    function parseStatsDateRange($dateRange) {
        $dateRange = convertPersianDigitsToEnglishForStats((string)$dateRange);

        // حذف کاراکترهای نامرئی که گاهی تلگرام/کیبورد موبایل وسط متن می‌گذارد
        $dateRange = str_replace([
            "\xE2\x80\x8C", // ZWNJ
            "\xE2\x80\x8D", // ZWJ
            "\xE2\x80\x8E", // LRM
            "\xE2\x80\x8F", // RLM
            "\xC2\xA0",       // NBSP
        ], ' ', $dateRange);

        $dateRange = trim($dateRange);
        $dateRange = str_replace(['–', '—', '−', '.', "\\"], ['-', '-', '-', '/', '/'], $dateRange);
        $dateRange = preg_replace('/\s+/u', ' ', $dateRange);

        // به جای وابستگی به کلمه to، دو تاریخ معتبر را از هر متنی استخراج می‌کنیم.
        // نمونه‌های قابل قبول:
        // 2026-05-21 to 2026-05-22
        // 2026/05/21 تا 2026/05/22
        // 2026-05-21 2026-05-22
        if (!preg_match_all('/\b(\d{4}[\\/-]\d{1,2}[\\/-]\d{1,2})\b/u', $dateRange, $matches)) {
            return false;
        }

        if (count($matches[1]) < 2) {
            return false;
        }

        $startDate = normalizeStatsDate($matches[1][0]);
        $endDate = normalizeStatsDate($matches[1][1]);

        if (!$startDate || !$endDate) {
            return false;
        }

        return [$startDate, $endDate];
    }
}


function calculateSalesBetweenDates($startDate, $endDate) {
    global $connection, $cancelKey, $removeKeyboard;

    if (empty($startDate) || empty($endDate)) {
        sendMessage("❌ تاریخ شروع یا پایان به درستی ذخیره نشده است.", $cancelKey, "HTML");
        return;
    }

    $startTimestamp = strtotime($startDate . ' 00:00:00');
    $endTimestamp = strtotime($endDate . ' 23:59:59');

    if ($startTimestamp === false || $endTimestamp === false) {
        sendMessage("❌ تاریخ وارد شده معتبر نیست.", $cancelKey, "HTML");
        return;
    }

    $stmt = $connection->prepare("
        SELECT
            COALESCE(SUM(price), 0) AS totalSales,
            COUNT(*) AS totalPayments
        FROM pays
        WHERE state IN ('paid', 'approved', 'auto_cart_approved')
          AND request_date BETWEEN ? AND ?
    ");

    if (!$stmt) {
        sendMessage("❌ خطا در آماده‌سازی کوئری فروش.", $cancelKey, "HTML");
        return;
    }

    $stmt->bind_param("ii", $startTimestamp, $endTimestamp);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    $totalSales = (int)($row['totalSales'] ?? 0);
    $totalPayments = (int)($row['totalPayments'] ?? 0);

    sendMessage(
        "💸 مجموع فروش بین تاریخ‌های <code>" . date("Y-m-d", $startTimestamp) . "</code> و <code>" . date("Y-m-d", $endTimestamp) . "</code> برابر با <b>" . number_format($totalSales) . "</b> تومان است.\n\n" .
        "🧾 تعداد پرداخت‌های موفق در این بازه: <b>" . number_format($totalPayments) . "</b>",
        $removeKeyboard,
        "HTML"
    );
}

// در صورتی که دکمه لغو (Cancel) فشرده شد
if ($data == 'cancel_operation') {
    setUser("none", "step");
    sendMessage("❌ عملیات لغو شد.", $removeKeyboard, "HTML");
}
// انتهای بخش آمار ربات

function getAdminsKeys(){
    global $connection, $mainValues, $buttonValues;
    $keys = array();
    
    $stmt = $connection->prepare("SELECT * FROM `users` WHERE `isAdmin` = true");
    $stmt->execute();
    $usersList = $stmt->get_result();
    $stmt->close();
    if($usersList->num_rows > 0){
        while($user = $usersList->fetch_assoc()){
            $keys[] = [['text'=>"❌",'callback_data'=>"delAdmin" . $user['userid']],['text'=>$user['name'], "callback_data"=>"wizwizch"]];
        }
    }else{
        $keys[] = [['text'=>"لیست ادمین ها خالی است ❕",'callback_data'=>"wizwizch"]];
    }
    $keys[] = [['text'=>"➕ افزودن ادمین",'callback_data'=>"addNewAdmin"]];
    $keys[] = [['text'=>$buttonValues['back_button'],'callback_data'=>"managePanel"]];
    return json_encode(['inline_keyboard'=>$keys]);
}

// BEGIN By ACh

function getUserInfoKeys($userId) { 
    global $connection, $mainValues, $buttonValues; 
 
    // گرفتن اطلاعات کاربر از جدول users 
    $stmt = $connection->prepare("SELECT * FROM users WHERE userid = ?"); 
    $stmt->bind_param("i", $userId); 
    $stmt->execute(); 
    $userCount = $stmt->get_result(); 
    $stmt->close(); 
 
    if ($userCount->num_rows > 0) { 
        $userInfos = $userCount->fetch_assoc(); 
        $userWallet = number_format($userInfos['wallet'] ?? 0) . " تومان"; 
 
        // وضعیت همکاری و مسدودی
        $isAgent = $userInfos['is_agent'] ?? 0;
        $step = $userInfos['step'] ?? 'none';
        $agentStatus = ($isAgent == 1) ? "✅ هست" : "❌ نیست";
        $banStatus = ($step == 'banned') ? "🚫 هست" : "🟢 نیست";
 
        // گرفتن تعداد و مجموع خرید از جدول orders_list 
        $stmt = $connection->prepare("SELECT COUNT(amount) as count, SUM(amount) as total FROM orders_list WHERE userid = ?"); 
        $stmt->bind_param("i", $userId); 
        $stmt->execute(); 
        $info = $stmt->get_result()->fetch_assoc(); 
        $stmt->close(); 
 
        $boughtService = $info['count'] ?? 0; 
        $totalBoughtPrice = $info['total'] ?? 0; 
 
        // گرفتن اطلاعات کاربر از تلگرام 
        $userDetail = bot('getChat', ['chat_id' => $userId])->result; 
        $userUserName = $userDetail->username ?? ''; 
        $first = $userDetail->first_name ?? ''; 
        $last = $userDetail->last_name ?? ''; 
        $fullName = trim($first . ' ' . $last); 
 
        // تعداد پرداخت تأیید شده 
        $stmt = $connection->prepare("SELECT COUNT(*) as approvedPayments FROM pays WHERE user_id = ? AND state IN ('approved', 'paid', 'auto_cart_approved')"); 
        $stmt->bind_param("i", $userId); 
        $stmt->execute(); 
        $approvedPayments = $stmt->get_result()->fetch_assoc()['approvedPayments'] ?? 0; 
        $stmt->close(); 
 
        // مجموع پرداخت تأیید شده 
        $stmt = $connection->prepare("SELECT SUM(price) as totalApprovedPayments FROM pays WHERE user_id = ? AND state IN ('approved', 'paid', 'auto_cart_approved')"); 
        $stmt->bind_param("i", $userId); 
        $stmt->execute(); 
        $val = $stmt->get_result()->fetch_assoc()['totalApprovedPayments'] ?? 0; 
        $totalApprovedPayments = number_format($val) . " تومان"; 
        $stmt->close(); 
 
        // محاسبه بازه های زمانی 
        $currentTimestamp = time(); 
        $weekAgo = $currentTimestamp - 7 * 24 * 60 * 60; 
        $monthAgo = $currentTimestamp - 30 * 24 * 60 * 60; 
        $threeMonthsAgo = $currentTimestamp - 90 * 24 * 60 * 60; 
        $sixMonthsAgo = $currentTimestamp - 180 * 24 * 60 * 60; 
 
        // مجموع پرداخت ها در بازه های زمانی 
        $totalWeekPayments = getPaymentSumInRange($connection, $userId, $weekAgo); 
        $totalMonthPayments = getPaymentSumInRange($connection, $userId, $monthAgo); 
        $totalThreeMonthsPayments = getPaymentSumInRange($connection, $userId, $threeMonthsAgo); 
        $totalSixMonthsPayments = getPaymentSumInRange($connection, $userId, $sixMonthsAgo); 
 
        // تعداد سرویس ها در بازه های زمانی 
        $countWeek = getOrderCountInRange($connection, $userId, $weekAgo); 
        $countMonth = getOrderCountInRange($connection, $userId, $monthAgo); 
        $countThreeMonths = getOrderCountInRange($connection, $userId, $threeMonthsAgo); 
        $countSixMonths = getOrderCountInRange($connection, $userId, $sixMonthsAgo); 
 
        // اشتراک ها 
        $increaseAmountTotal = 0; 
        $subscriptions = []; 
 
        $stmt = $connection->prepare("SELECT DISTINCT remark FROM orders_list WHERE userid = ?"); 
        $stmt->bind_param("i", $userId); 
        $stmt->execute(); 
        $remarksResult = $stmt->get_result(); 
        $stmt->close(); 
 
        while ($remarkRow = $remarksResult->fetch_assoc()) { 
            $remark = $remarkRow['remark']; 
 
            $stmt2 = $connection->prepare("SELECT expire_date FROM orders_list WHERE userid = ? AND remark = ?"); 
            $stmt2->bind_param("is", $userId, $remark); 
            $stmt2->execute(); 
            $expireResult = $stmt2->get_result(); 
            $stmt2->close(); 
 
            while ($expireRow = $expireResult->fetch_assoc()) { 
                $expire_timestamp = $expireRow['expire_date']; 
                $expire_date = date('Y-m-d H:i:s', $expire_timestamp); 
                $subscriptions[] = "👤 نام کاربری: <code>$remark</code>\n📅 تاریخ انقضا: $expire_date"; 
            } 
 
            $stmt2 = $connection->prepare("SELECT SUM(amount) as totalIncreaseAmount FROM increase_order WHERE userid = ? AND remark = ?"); 
            $stmt2->bind_param("is", $userId, $remark); 
            $stmt2->execute(); 
            $increaseAmountResult = $stmt2->get_result()->fetch_assoc(); 
            $stmt2->close(); 
 
            $increaseAmountTotal += $increaseAmountResult['totalIncreaseAmount'] ?? 0; 
        } 
 
        $totalAmountWithIncrease = $totalBoughtPrice + $increaseAmountTotal; 
        $totalAmountWithIncreaseFormatted = number_format($totalAmountWithIncrease) . " تومان"; 
 
        // آماده سازی لیست اشتراک ها 
        $chunkedSubscriptions = array_chunk($subscriptions, 20); 
        $subscriptionMessages = []; 
        foreach ($chunkedSubscriptions as $chunk) { 
            $subscriptionMessages[] = implode("\n\n", $chunk); 
        } 
 
        return [ 
            'inline_keyboard' => [ 
                [ 
                    ['text' => $userUserName ?: " ", 'url' => "t.me/$userUserName"], 
                    ['text' => "نام کاربری", 'callback_data' => "wizwizch"] 
                ], 
                [ 
                    ['text' => $fullName ?: " ", 'callback_data' => "wizwizch"], 
                    ['text' => "نام", 'callback_data' => "wizwizch"] 
                ], 
                [ 
                    ['text' => $boughtService, 'callback_data' => "wizwizch"], 
                    ['text' => "🔄 تعداد کل سرویس ها", 'callback_data' => "wizwizch"] 
                ], 
                [ 
                    ['text' => $countWeek, 'callback_data' => "wizwizch"], 
                    ['text' => "هفته اخیر", 'callback_data' => "wizwizch"] 
                ], 
                [ 
                    ['text' => $countMonth, 'callback_data' => "wizwizch"], 
                    ['text' => "ماه اخیر", 'callback_data' => "wizwizch"] 
                ], 
                [ 
                    ['text' => $countThreeMonths, 'callback_data' => "wizwizch"], 
                    ['text' => "3 ماه اخیر", 'callback_data' => "wizwizch"] 
                ], 
                [ 
                    ['text' => $countSixMonths, 'callback_data' => "wizwizch"], 
                    ['text' => "6 ماه اخیر", 'callback_data' => "wizwizch"] 
                ], 
                [ 
                    ['text' => $totalAmountWithIncreaseFormatted, 'callback_data' => "wizwizch"], 
                    ['text' => "ارزش سرویس ها", 'callback_data' => "wizwizch"] 
                ], 
                [ 
                    ['text' => $approvedPayments, 'callback_data' => "wizwizch"], 
                    ['text' => "تعداد پرداخت ها", 'callback_data' => "wizwizch"] 
                ], 
                [ 
                    ['text' => $totalApprovedPayments, 'callback_data' => "wizwizch"], 
                    ['text' => "💰 جمع کل پرداخت ها", 'callback_data' => "wizwizch"] 
                ], 
                [ 
                    ['text' => $totalWeekPayments, 'callback_data' => "wizwizch"], 
                    ['text' => "هفته اخیر", 'callback_data' => "wizwizch"] 
                ], 
                [ 
                    ['text' => $totalMonthPayments, 'callback_data' => "wizwizch"], 
                    ['text' => "ماه اخیر", 'callback_data' => "wizwizch"] 
                ], 
                [ 
                    ['text' => $totalThreeMonthsPayments, 'callback_data' => "wizwizch"], 
                    ['text' => "3 ماه اخیر", 'callback_data' => "wizwizch"] 
                ], 
                [ 
                    ['text' => $totalSixMonthsPayments, 'callback_data' => "wizwizch"], 
                    ['text' => "6 ماه اخیر", 'callback_data' => "wizwizch"] 
                ], 
                [ 
                    ['text' => $userWallet, 'callback_data' => "wizwizch"], 
                    ['text' => "موجودی کیف پول", 'callback_data' => "wizwizch"] 
                ],
                [
                    ['text' => $agentStatus, 'callback_data' => "toggleAgent_{$userId}"],
                    ['text' => "همکار", 'callback_data' => "wizwizch"]
                ],
                [
                    ['text' => $banStatus, 'callback_data' => "toggleBan_{$userId}"],
                    ['text' => "مسدود", 'callback_data' => "wizwizch"]
                ],
                [ 
                    ['text' => $buttonValues['subscriptions_list'], 'callback_data' => "showUserSubscriptions$userId"] 
                ], 
                [ 
                    ['text' => $buttonValues['back_button'], 'callback_data' => "managePanel"] 
                ] 
            ], 
            'subscriptionMessages' => $subscriptionMessages 
        ]; 
    } else { 
        return null; 
    } 
} 
 
// توابع کمکی برای خوانایی بیشتر 
function getPaymentSumInRange($connection, $userId, $fromDate) { 
    $stmt = $connection->prepare("SELECT SUM(price) as total FROM pays WHERE user_id = ? AND state IN ('approved', 'paid', 'auto_cart_approved') AND request_date >= ?"); 
    $stmt->bind_param("ii", $userId, $fromDate); 
    $stmt->execute(); 
    $result = $stmt->get_result()->fetch_assoc()['total'] ?? 0; 
    $stmt->close(); 
    return number_format($result) . " تومان"; 
} 
 
function getOrderCountInRange($connection, $userId, $fromDate) { 
    $stmt = $connection->prepare("SELECT COUNT(*) as count FROM orders_list WHERE userid = ? AND date >= ?"); 
    $stmt->bind_param("ii", $userId, $fromDate); 
    $stmt->execute(); 
    $result = $stmt->get_result()->fetch_assoc()['count'] ?? 0; 
    $stmt->close(); 
    return $result; 
} 

// ✨ هندل کردن تغییر وضعیت همکار و مسدودی
function handleToggleCallbacks($data, $chat_id, $message_id) {
    global $connection;

    // تغییر وضعیت همکار
    if (preg_match('/^toggleAgent_(\d+)/', $data, $match)) {
        $userId = intval($match[1]);

        $stmt = $connection->prepare("SELECT `is_agent` FROM `users` WHERE `userid` = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $stmt->close();

        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $newVal = ($row['is_agent'] == 1) ? 0 : 1;

            $stmt = $connection->prepare("UPDATE `users` SET `is_agent` = ? WHERE `userid` = ?");
            $stmt->bind_param("ii", $newVal, $userId);
            $stmt->execute();
            $stmt->close();

            bot('editMessageReplyMarkup', [
                'chat_id' => $chat_id,
                'message_id' => $message_id,
                'reply_markup' => json_encode(getUserInfoKeys($userId))
            ]);
        }
    }

    // تغییر وضعیت مسدودی
    if (preg_match('/^toggleBan_(\d+)/', $data, $match)) {
        $userId = intval($match[1]);

        $stmt = $connection->prepare("SELECT `step` FROM `users` WHERE `userid` = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $stmt->close();

        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $newStep = ($row['step'] == 'banned') ? 'none' : 'banned';

            $stmt = $connection->prepare("UPDATE `users` SET `step` = ? WHERE `userid` = ?");
            $stmt->bind_param("si", $newStep, $userId);
            $stmt->execute();
            $stmt->close();

            bot('editMessageReplyMarkup', [
                'chat_id' => $chat_id,
                'message_id' => $message_id,
                'reply_markup' => json_encode(getUserInfoKeys($userId))
            ]);
        }
    }
}

//---

function getCallbackFromCommand($command) {
    global $connection, $userInfo, $from_id, $admin, $botState, $buttonValues, $chat_id;

    $isRealAdmin = ($from_id == $admin || !empty($userInfo['isAdmin']));

    switch ($command) {
        case '/buy':
            if (($botState['sellState'] ?? 'off') !== "on" && !$isRealAdmin) {
                sendMessage("⛔️ سیستم فروش غیرفعال است.", null, "MarkDown", $chat_id);
                exit();
            }

            if (($botState['cartToCartState'] ?? 'off') == "off" && ($botState['walletState'] ?? 'off') == "off") {
                sendMessage("⛔️ سیستم فروش غیرفعال است.", null, "MarkDown", $chat_id);
                exit();
            }

            if (($botState['agencyState'] ?? 'off') == "on" && !empty($userInfo['is_agent']) && $userInfo['is_agent'] == 1) {
                $keyboard = [
                    [
                        ['text' => $buttonValues['agent_one_buy'], 'callback_data' => "agentOneBuy"],
                        ['text' => $buttonValues['agent_much_buy'], 'callback_data' => "agentMuchBuy"]
                    ],
                    [['text' => $buttonValues['back_to_main'], 'callback_data' => "mainMenu"]]
                ];

                sendMessage(
                    "⛔️ لطفا از دکمه خرید تکی یا خرید انبوه استفاده کنید.",
                    json_encode(['inline_keyboard' => $keyboard]),
                    "MarkDown",
                    $chat_id
                );
                exit();
            }

            return 'buySubscription';

        case '/mysubs':
            if (($botState['agencyState'] ?? 'off') == "on" && !empty($userInfo['is_agent']) && $userInfo['is_agent'] == 1) {
                return 'agentConfigsList';
            } else {
                return 'mySubscriptions';
            }

        default:
            return null;
    }
}

$update = json_decode(file_get_contents("php://input"));
handleToggleCallbacks($data, $chat_id, $message_id);

if (isset($update->message->text)) {
    $txt = trim($update->message->text);
    $mappedText = null;

    if (strpos($txt, "/start ") === 0) {
        $payload = trim(substr($txt, 7));

        if (!ctype_digit($payload)) {
            $cmd = strtolower(explode("_", $payload, 2)[0]);

            if ($cmd === "mysubs") {
                $mappedText = "/mysubs";
            } elseif ($cmd === "buy") {
                $mappedText = "/buy";
            }
        }
    } elseif ($txt === "/mysubs" || $txt === "/buy") {
        $mappedText = $txt;
    }

    if ($mappedText !== null && isset($update->message)) {
        $from_id = $update->message->from->id ?? null;
        $chat_id = $update->message->chat->id ?? null;

        $isRealAdmin = ($from_id == $admin || !empty($userInfo['isAdmin']));

        if ($mappedText === "/buy") {
            if (($botState['sellState'] ?? 'off') !== "on" && !$isRealAdmin) {
                sendMessage("⛔️ سیستم فروش غیرفعال است.", null, "MarkDown", $chat_id);
                exit;
            }

            if (($botState['cartToCartState'] ?? 'off') == "off" && ($botState['walletState'] ?? 'off') == "off") {
                sendMessage("⛔️ سیستم فروش غیرفعال است.", null, "MarkDown", $chat_id);
                exit;
            }
        }

        $callback_data = getCallbackFromCommand($mappedText);

        if ($callback_data) {
            processCallbackQuery($callback_data, $chat_id, $from_id);
            exit;
        }
    }
}
// END By ACh

// BEGIN Apply Discount
function applyDiscountToPay($connection, $rowId, $discountCode, $from_id, $username, $first_name, $admin, $mainValues, $botState){
    // گرفتن اطلاعات پرداخت
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `id` = ?");
    $stmt->bind_param("i", $rowId);
    $stmt->execute();
    $payInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if(!$payInfo){
        // پرداخت یافت نشد
        sendMessage($mainValues['not_valid_discount_code']);
        return [
            'success'        => false,
            'price'          => 0,
            'hash_id'        => '',
            'discount_value' => 0,
            'discount_text'  => '',
            'payInfo'        => null,
        ];
    }

    $hash_id = $payInfo['hash_id'];
    $price   = (int)$payInfo['price'];
    $planId  = isset($payInfo['plan_id']) ? (int)$payInfo['plan_id'] : 0;
    // $user_id در جدول pays برابر همون userid تلگرام است:
    $userId  = isset($payInfo['user_id']) ? (int)$payInfo['user_id'] : $from_id;

    // گرفتن اطلاعات کد تخفیف
	$time = time();
	$stmt = $connection->prepare("SELECT * FROM `discounts` WHERE (`expire_date` > $time OR `expire_date` = 0) AND (`expire_count` > 0 OR `expire_count` = -1) AND `is_active` = 1 AND `hash_id` = ?");
	$stmt->bind_param("s", $discountCode);
    $stmt->execute();
    $list = $stmt->get_result();
    $stmt->close();

    // اگر کد تخفیف پیدا نشد
    if($list->num_rows <= 0){
        // کد اصلاً وجود ندارد یا منقضی شده
        if (!empty($mainValues['discount_not_found'])) {
            sendMessage($mainValues['discount_not_found']);
        } else {
            sendMessage($mainValues['not_valid_discount_code']);
        }

        return [
            'success'        => false,
            'price'          => $price,
            'hash_id'        => $hash_id,
            'discount_value' => 0,
            'discount_text'  => '',
            'payInfo'        => $payInfo,
        ];
    }

    $discountInfo = $list->fetch_assoc();

    $amount       = (int)$discountInfo['amount'];
    $type         = $discountInfo['type'];
    $count        = (int)$discountInfo['expire_count'];
    $canUse       = (int)$discountInfo['can_use'];
    $minPrice     = isset($discountInfo['min_price']) ? (int)$discountInfo['min_price'] : 0;
    $maxDiscount  = isset($discountInfo['max_discount']) && $discountInfo['max_discount'] !== null
                    ? (int)$discountInfo['max_discount'] : 0;

    $audienceType    = !empty($discountInfo['audience_type']) ? $discountInfo['audience_type'] : 'all';
    $audienceUserId  = !empty($discountInfo['audience_user_id']) ? (int)$discountInfo['audience_user_id'] : null;
    $targetType      = !empty($discountInfo['target_type']) ? $discountInfo['target_type'] : 'all';
    $targetId        = !empty($discountInfo['target_id']) ? (int)$discountInfo['target_id'] : null;

    // چک نوع کاربر (audience_type)

    // گرفتن اطلاعات کاربر از جدول users
    $stmt = $connection->prepare("SELECT `is_agent` FROM `users` WHERE `userid` = ? LIMIT 1");
    $stmt->bind_param("i", $from_id);
    $stmt->execute();
    $userRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $isAgent = ($userRow && isset($userRow['is_agent'])) ? (int)$userRow['is_agent'] : 0;

    $allowedForUser = true;

    switch ($audienceType) {
        case 'user':
            // کد فقط برای یک userid خاص
            $allowedForUser = ($audienceUserId == $from_id);
            break;

        case 'agents':
            // فقط برای همکاران (is_agent = 1)
            $allowedForUser = ($isAgent == 1);
            break;
			
		case 'non_agents':
            // کاربران غیر همکار = هر کسی که is_agent != 1
            $allowedForUser = ($isAgent != 1);
            break;

        case 'buyers':
        case 'non_buyers':
            // چک خرید موفق در جدول pays
            $stmt = $connection->prepare("
                SELECT COUNT(*) AS c FROM `pays`
                WHERE `user_id` = ? AND `state` IN ('auto_cart_approved','approved','paid')
            ");
            $stmt->bind_param("i", $from_id);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $hasBuy = $result && $result['c'] > 0;

            if ($audienceType == 'buyers') {
                $allowedForUser = $hasBuy;
            } else { // non_buyers
                $allowedForUser = !$hasBuy;
            }
            break;

        case 'has_active_order':
        case 'no_active_order':
            // تعریف شما: هر رکورد در orders_list = سفارش فعال
            $stmt = $connection->prepare("
                SELECT COUNT(*) AS c FROM `orders_list`
                WHERE `userid` = ?
            ");
            $stmt->bind_param("i", $from_id);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $hasActiveOrder = $result && $result['c'] > 0;

            if ($audienceType == 'has_active_order') {
                $allowedForUser = $hasActiveOrder;
            } else { // no_active_order
                $allowedForUser = !$hasActiveOrder;
            }
            break;

        case 'all':
        default:
            $allowedForUser = true;
            break;
    }

    if (!$allowedForUser) {
        // این کد مخصوص این کاربر/شرایط نیست
        if (!empty($mainValues['discount_not_for_user'])) {
            sendMessage($mainValues['discount_not_for_user']);
        } else {
            sendMessage($mainValues['not_valid_discount_code']);
        }

        return [
            'success'        => false,
            'price'          => $price,
            'hash_id'        => $hash_id,
            'discount_value' => 0,
            'discount_text'  => '',
            'payInfo'        => $payInfo,
        ];
    }

    // چک هدف سرویس (target_type)

    // اگر کد محدود به پلن/سرور/دسته است، باید پلن پرداخت را هم داشته باشیم
    if ($targetType !== 'all' && $planId > 0) {
        $stmt = $connection->prepare("SELECT `server_id`, `catid` FROM `server_plans` WHERE `id` = ? LIMIT 1");
        $stmt->bind_param("i", $planId);
        $stmt->execute();
        $planRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } else {
        $planRow = null;
    }

    $allowedForPlan = true;

    switch ($targetType) {
        case 'plan':
            $allowedForPlan = ($planId > 0 && $targetId == $planId);
            break;

        case 'server':
            $allowedForPlan = ($planRow && $targetId == (int)$planRow['server_id']);
            break;

        case 'category':
            $allowedForPlan = ($planRow && $targetId == (int)$planRow['catid']);
            break;

        case 'all':
        default:
            $allowedForPlan = true;
            break;
    }

    if (!$allowedForPlan) {
        // این کد برای این پلن/سرور/دسته معتبر نیست
        if (!empty($mainValues['discount_not_for_service'])) {
            sendMessage($mainValues['discount_not_for_service']);
        } else {
            sendMessage($mainValues['not_valid_discount_code']);
        }

        return [
            'success'        => false,
            'price'          => $price,
            'hash_id'        => $hash_id,
            'discount_value' => 0,
            'discount_text'  => '',
            'payInfo'        => $payInfo,
        ];
    }

    // چک حداقل مبلغ سفارش

    if ($minPrice > 0 && $price < $minPrice) {
        if (!empty($mainValues['discount_min_price'])) {
            $msg = str_replace("MINPRICE", number_format($minPrice) . " تومان", $mainValues['discount_min_price']);
            sendMessage($msg);
        } else {
            sendMessage($mainValues['not_valid_discount_code']);
        }

        return [
            'success'        => false,
            'price'          => $price,
            'hash_id'        => $hash_id,
            'discount_value' => 0,
            'discount_text'  => '',
            'payInfo'        => $payInfo,
        ];
    }

    // چک تعداد استفاده هر یوزر (can_use + used_by)

    $usedBy = !is_null($discountInfo['used_by']) ? json_decode($discountInfo['used_by'], true) : array();
    if(!is_array($usedBy)) $usedBy = array();

    $counts = array_count_values($usedBy);
    $userUsedCount = isset($counts[$from_id]) ? (int)$counts[$from_id] : 0;

    // اگر can_use = -1 یعنی نامحدود، محدودیتی نباید اعمال شود
    if($canUse != -1 && $canUse <= $userUsedCount){
        if (!empty($mainValues['discount_user_limit'])) {
            sendMessage($mainValues['discount_user_limit']);
        } else {
            sendMessage($mainValues['not_valid_discount_code']);
        }

        return [
            'success'        => false,
            'price'          => $price,
            'hash_id'        => $hash_id,
            'discount_value' => 0,
            'discount_text'  => '',
            'payInfo'        => $payInfo,
        ];
    }

    $usedBy[] = $from_id;
    $encodeUsedBy = json_encode($usedBy, JSON_UNESCAPED_UNICODE);

    if ($count != -1) {
        $query = "UPDATE `discounts` SET `expire_count` = `expire_count` - 1, `used_by` = ? WHERE `id` = ?";
    } else {
        $query = "UPDATE `discounts` SET `used_by` = ? WHERE `id` = ?";
    }

    $stmt = $connection->prepare($query);
    $stmt->bind_param("si", $encodeUsedBy, $discountInfo['id']);
    $stmt->execute();
    $stmt->close();

    // اعمال تخفیف روی قیمت

    if($type == "percent"){
        $discountValue = floor($price * $amount / 100);
    } else {
        $discountValue = $amount;
    }

    // اعمال سقف تخفیف اگر تعیین شده باشد
    if ($maxDiscount > 0 && $discountValue > $maxDiscount) {
        $discountValue = $maxDiscount;
    }

    $price -= $discountValue;
    if($price < 0) $price = 0;

    // آپدیت قیمت در جدول pays
    $stmt = $connection->prepare("UPDATE `pays` SET `price` = ? WHERE `id` = ?");
    $stmt->bind_param("ii", $price, $rowId);
    $stmt->execute();
    $stmt->close();

    // پیام به کاربر

    $discountText = number_format($discountValue) . " تومان";

    if (!empty($mainValues['valid_discount_code'])) {
        sendMessage(str_replace("AMOUNT", $discountText, $mainValues['valid_discount_code']));
    } else {
        sendMessage("کد تخفیف با موفقیت اعمال شد. مبلغ تخفیف: " . $discountText);
    }

    // پیام به ادمین و کانال گزارش

    $keys = json_encode(['inline_keyboard'=>[
        [
            ['text'=>"❤️", "callback_data"=>"wizwizch"]
        ],
    ]]);

    $msg = str_replace(
        ['USERID', 'USERNAME', 'NAME', 'AMOUNT', 'DISCOUNTCODE'],
        [$from_id, $username, $first_name, $discountText, $discountCode],
        $mainValues['used_discount_code']
    );

    // ارسال پیام به ادمین خاص
    sendMessage($msg, $keys, null, $admin);

    // ارسال پیام به کانال گزارشات
    $rewardChannel = $botState['rewardChannel'];
    if (!empty($rewardChannel)) {
        sendMessage($msg, $keys, "html", $rewardChannel);
    }

    return [
        'success'        => true,
        'price'          => $price,
        'hash_id'        => $hash_id,
        'discount_value' => $discountValue,
        'discount_text'  => $discountText,
        'payInfo'        => $payInfo,
    ];
}

// END Apply Discount
function getDiscountCodeKeys($page = 1){
    global $connection, $buttonValues;

    $perPage = 20;
    if($page < 1) $page = 1;

    // ✅ شمارش همه کدهای تخفیف (بدون فیلتر تاریخ و تعداد)
    $stmt = $connection->prepare("
        SELECT COUNT(*) AS c 
        FROM `discounts`
    ");
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $total = $res ? (int)$res['c'] : 0;
    $totalPages = $total > 0 ? ceil($total / $perPage) : 1;
    if($page > $totalPages) $page = $totalPages;

    $offset = ($page - 1) * $perPage;

    $keys = [];

    if($total > 0){

        $stmt = $connection->prepare("
            SELECT `id`,`hash_id`,`type`,`amount`,`is_active`,`expire_date`,`expire_count` 
            FROM `discounts`
            ORDER BY `id` DESC
            LIMIT ?,?
        ");
        $stmt->bind_param("ii", $offset, $perPage);
        $stmt->execute();
        $list = $stmt->get_result();
        $stmt->close();

        $now = time();
		
        $keys[] = [
            [
                'text' => "📋 لیست کدهای تخفیف (صفحه {$page})",
                'callback_data' => "wizwizch"
            ]
        ];

        $keys[] = [
            ['text' => "کد",      'callback_data' => "wizwizch"],
            ['text' => "مقدار",  'callback_data' => "wizwizch"],
            ['text' => "وضعیت",  'callback_data' => "wizwizch"],
            ['text' => "ویرایش", 'callback_data' => "wizwizch"],
            ['text' => "حذف",    'callback_data' => "wizwizch"],
        ];

        while($row = $list->fetch_assoc()){
            $id          = (int)$row['id'];
            $hashId      = $row['hash_id'];
            $isActiveFlag = isset($row['is_active']) ? (int)$row['is_active'] : 1;
            $expireDate  = (int)$row['expire_date'];
            $expireCount = (int)$row['expire_count'];

            // مقدار تخفیف
            if($row['type'] == 'percent'){
                $amountText = $row['amount'] . "%";
            }else{
                $amountText = number_format($row['amount']) . " تومان";
            }

            $isExpiredDate   = ($expireDate != 0 && $expireDate < $now);
			$isCountFinished = ($expireCount == 0);

			if ($isExpiredDate) {
				$statusText = "📅 منقضی";
			}
			elseif ($isCountFinished) {
				$statusText = "🔢 ناموجود";
			}
			else {
				$statusText = $isActiveFlag ? "✅ فعال" : "⛔️ غیرفعال";
			}

            // ردیف هر کد:
            $keys[] = [
                // کد → برای کپی
                [
                    'text' => $hashId,
                    'callback_data' => "copyHash" . $hashId
                ],
                // مقدار
                [
                    'text' => $amountText,
                    'callback_data' => "wizwizch"
                ],
                // وضعیت → برای فعال/غیرفعال کردن
                [
                    'text' => $statusText,
                    'callback_data' => "toggleDiscount" . $id
                ],
                // ویرایش
                [
                    'text' => "✏️",
                    'callback_data' => "editDiscount" . $id
                ],
                // حذف (با تأیید)
                [
                    'text' => "❌",
                    'callback_data' => "delDiscountConfirm" . $id
                ],
            ];
        }

        if($totalPages > 1){
            $navRow = [];

            if($page > 1){
                $prevPage = $page - 1;
                $navRow[] = [
                    'text' => "⬅️",
                    'callback_data' => "discount_codes_" . $prevPage
                ];
            }

            if($page < $totalPages){
                $nextPage = $page + 1;
                $navRow[] = [
                    'text' => "➡️",
                    'callback_data' => "discount_codes_" . $nextPage
                ];
            }

            if(!empty($navRow)){
                $keys[] = $navRow;
            }

            $keys[] = [
                [
                    'text' => "صفحه {$page} از {$totalPages}",
                    'callback_data' => "wizwizch"
                ]
            ];
        }

    }else{
        $keys[] = [
            [
                'text'=>"کد تخفیفی یافت نشد.",'callback_data'=>"wizwizch"
            ]
        ];
    }

    // دکمه افزودن + برگشت
    $keys[] = [
        ['text'=>"➕ افزودن کد تخفیف", 'callback_data'=>"addDiscountCode"]
    ];
    $keys[] = [
        ['text'=>$buttonValues['back_button'],'callback_data'=>"managePanel"]
    ];

    return json_encode(['inline_keyboard'=>$keys]);
}

function getMainMenuButtonsKeys(){
    global $connection, $mainValues, $buttonValues;
    
    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` LIKE '%MAIN_BUTTONS%'");
    $stmt->execute();
    $buttons = $stmt->get_result();
    $stmt->close();
    
    $keys = array();
    if($buttons->num_rows > 0){
        while($row = $buttons->fetch_assoc()){
            $rowId = $row['id'];
            $title = str_replace("MAIN_BUTTONS","", $row['type']);
            $answer = $row['value'];
            $keys[] = [
                        ['text'=>"❌",'callback_data'=>"delMainButton" . $rowId],
                        ['text'=>$title??" " ,'callback_data'=>"wizwizch"]];
        }
    }else{
        $keys[] = [['text'=>"دکمه ای یافت نشد❕",'callback_data'=>"wizwizch"]];
    }
    $keys[] = [['text'=>"➕ افزودن دکمه جدید",'callback_data'=>"addNewMainButton"]];
    $keys[] = [['text'=>$buttonValues['back_button'],'callback_data'=>"managePanel"]];
    return json_encode(['inline_keyboard'=>$keys]);
}
function getPlanDetailsKeys($planId){
    global $connection, $mainValues, $buttonValues;
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
    $stmt->bind_param("i", $planId);
    $stmt->execute();
    $pdResult = $stmt->get_result();
    $pd = $pdResult->fetch_assoc();
    $stmt->close();


    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $pd['server_id']);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $reality = $server_info['reality'];


    if($pdResult->num_rows == 0) return null;
    else {
        $id=$pd['id'];
        $name=$pd['title'];
        $price=$pd['price'];
        $acount =$pd['acount'];
        $rahgozar = $pd['rahgozar'];
        $customPath = $pd['custom_path']==true?$buttonValues['on']:$buttonValues['off'];
        $dest = $pd['dest']??" ";
        $spiderX = $pd['spiderX']??" ";
        $serverName = $pd['serverNames']??" ";
        $flow = $pd['flow'];
        $customPort = $pd['custom_port'];
        $customSni = $pd['custom_sni']??" ";

        $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `status`=1 AND `fileid`=?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $wizwizplanaccnumber = $stmt->get_result()->num_rows;
        $stmt->close();

        $srvid= $pd['server_id'];
        $keyboard = [
            ($rahgozar==true?[['text'=>"* نوع پلن: رهگذر *",'callback_data'=>'wizwizch']]:[]),
            ($rahgozar==true?[
                ['text'=>$customPath,'callback_data'=>'changeCustomPath' . $id],
                ['text'=>"Path Custom",'callback_data'=>'wizwizch'],
                ]:[]),
            ($rahgozar==true?[
                ['text'=>$customPort,'callback_data'=>'changeCustomPort' . $id],
                ['text'=>"پورت دلخواه",'callback_data'=>'wizwizch'],
                ]:[]),
            ($rahgozar==true?[
                ['text'=>$customSni,'callback_data'=>'changeCustomSni' . $id],
                ['text'=>"sni دلخواه",'callback_data'=>'wizwizch'],
                ]:[]),
            [['text'=>$name,'callback_data'=>"wizwizplanname$id"],['text'=>"🔮 نام پلن",'callback_data'=>"wizwizch"]],
            ($reality == "true"?[['text'=>$dest,'callback_data'=>"editDestName$id"],['text'=>"dest",'callback_data'=>"wizwizch"]]:[]),
            ($reality == "true"?[['text'=>$serverName,'callback_data'=>"editServerNames$id"],['text'=>"serverNames",'callback_data'=>"wizwizch"]]:[]),
            ($reality == "true"?[['text'=>$spiderX,'callback_data'=>"editSpiderX$id"],['text'=>"spiderX",'callback_data'=>"wizwizch"]]:[]),
            ($reality == "true"?[['text'=>$flow,'callback_data'=>"editFlow$id"],['text'=>"flow",'callback_data'=>"wizwizch"]]:[]),
            [['text'=>$wizwizplanaccnumber,'callback_data'=>"wizwizch"],['text'=>"🎗 تعداد اکانت های فروخته شده",'callback_data'=>"wizwizch"]],
            ($pd['inbound_id'] != 0?[['text'=>"$acount",'callback_data'=>"wizwizplanslimit$id"],['text'=>"🚪 تغییر ظرفیت کانفیگ",'callback_data'=>"wizwizch"]]:[]),
            ($pd['inbound_id'] != 0?[['text'=>$pd['inbound_id'],'callback_data'=>"wizwizplansinobundid$id"],['text'=>"🚪 سطر کانفیگ",'callback_data'=>"wizwizch"]]:[]),
            [['text'=>"✏️ ویرایش توضیحات",'callback_data'=>"wizwizplaneditdes$id"]],
            [['text'=>number_format($price) . " تومان",'callback_data'=>"wizwizplanrial$id"],['text'=>"💰 قیمت پلن",'callback_data'=>"wizwizch"]],
            [['text'=>"♻️ دریافت لیست اکانت ها",'callback_data'=>"wizwizplanacclist$id"]],
            ($server_info['type'] == "marzban"?[['text'=>"انتخاب Host",'callback_data'=>"marzbanHostSettings" . $id]]:[]),
            [['text'=>"✂️ حذف",'callback_data'=>"wizwizplandelete$id"]],
            [['text' => $buttonValues['back_button'], 'callback_data' =>"plansList$srvid"]]
            ];
        return json_encode(['inline_keyboard'=>$keyboard]);
    }
}

// BEGIN create user and admin get order detail
function findOrder($id, $userId = null) {
    global $connection;

    if ($userId === null) {
        $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id`=?");
        $stmt->bind_param("i", $id);
    } else {
        $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `userid`=? AND `id`=?");
        $stmt->bind_param("ii", $userId, $id);
    }

    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    return $res->num_rows ? $res->fetch_assoc() : null;
}

function getPlanFullName($fid, $fallbackId) {
    global $connection;

    $stmt = $connection->prepare("SELECT * FROM server_plans WHERE id=? AND active=1");
    $stmt->bind_param("i", $fid);
    $stmt->execute();
    $plan = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$plan) return (string)$fallbackId;

    $stmt = $connection->prepare("SELECT title FROM server_categories WHERE id=?");
    $stmt->bind_param("i", $plan['catid']);
    $stmt->execute();
    $cat = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return ($cat ? $cat['title'] . " " : "") . $plan['title'];
}

function getServerConfigById($server_id) {
    global $connection;

    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $config = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $config;
}

function getOrderTrafficInfo($serverType, $server_id, $remark, $uuid, $inbound_id, $rahgozar=false) {

    $result = [
        'found'    => false,
        'enable'   => false,
        'leftgb'   => "⚠️",
        'netType'  => null,
        'security' => null,
    ];

    // ---- Marzban ----
    if ($serverType == "marzban") {
        $info = getMarzbanUser($server_id, $remark);

        if (!isset($info->username)) {
            return $result;
        }

        $result['found']  = true;
        $result['enable'] = ($info->status == "active");

        $total = $info->data_limit ?? 0;
        $used  = $info->used_traffic ?? 0;

        $result['leftgb'] = round(($total - $used) / 1073741824, 2) . " GB";
        return $result;
    }

    // ---- Non-Marzban ----
    $data = getJson($server_id)->obj ?? null;
    if (!$data) return $result;

    foreach ($data as $row) {

        $settings = json_decode($row->settings ?? '{}');
        $clients  = $settings->clients ?? [];

        if ($inbound_id == 0) {
            if (empty($clients) || !isset($clients[0])) continue;

            $c0 = $clients[0];
            $cid = $c0->id ?? null;
            $cpw = $c0->password ?? null;

            if ($cid != $uuid && $cpw != $uuid) continue;

            $result['found'] = true;

            $stream = json_decode($row->streamSettings ?? '{}');
            $result['netType']  = $stream->network ?? null;
            $result['security'] = $stream->security ?? null;

            $total  = $row->total ?? 0;
            $up     = $row->up ?? 0;
            $down   = $row->down ?? 0;
            $enable = $row->enable ?? false;
			
            if (!empty($row->clientStats)) {
                $stats = $row->clientStats;
                $email = $c0->email ?? null;

                if ($email) {
                    $emails = array_column($stats, 'email');
                    $k = array_search($email, $emails);

                    if ($k !== false) {
                        $clientTotal = $stats[$k]->total ?? null;
                        $clientUp    = $stats[$k]->up ?? 0;
                        $clientDown  = $stats[$k]->down ?? 0;

                        $clientExpiry = $stats[$k]->expiryTime ?? null;

                        if ($clientTotal !== null && $clientTotal != 0) {
                            if ($clientExpiry === null || $clientExpiry != 0) {
                                $total = $clientTotal;
                                $up   += $clientUp;
                                $down += $clientDown;
                            }
                        }
                    }
                }
            }

            $result['enable'] = $enable;
            $result['leftgb'] = round(($total - $up - $down) / 1073741824, 2) . " GB";
            return $result;
        }

        // inbound_id != 0
        if (($row->id ?? null) != $inbound_id) continue;

        $stream = json_decode($row->streamSettings ?? '{}');
        $result['netType']  = $stream->network ?? null;
        $result['security'] = $stream->security ?? null;

        $clientsStates = $row->clientStats ?? [];

        foreach ($clients as $client) {
            $cid = $client->id ?? null;
            $cpw = $client->password ?? null;

            if ($cid == $uuid || $cpw == $uuid) {
                $result['found'] = true;

                $email = $client->email ?? null;
                if (!$email) return $result;

                $emails = array_column($clientsStates, 'email');
                $k = array_search($email, $emails);

                if ($k === false) return $result;

                $up    = $clientsStates[$k]->up ?? 0;
                $down  = $clientsStates[$k]->down ?? 0;
                $total = $clientsStates[$k]->total ?? 0;

                $stateEnable = $clientsStates[$k]->enable ?? false;
                $clientEnable = $client->enable ?? true;

                $result['enable'] = ($stateEnable && $clientEnable);
                $result['leftgb'] = round(($total - $up - $down) / 1073741824, 2) . " GB";
                return $result;
            }
        }
    }

    return $result;
}

function buildConfigLinks($acc_link, $botState, $showIndexes) {
    require_once __DIR__ . '/src/Subscription/Presentation.php';
    return \AchBot\Subscription\Presentation\configLinks($acc_link, $botState, $showIndexes);
}

function buildSubLink($serverType, $panelUrl, $botUrl, $token, $botState) {
    require_once __DIR__ . '/src/Subscription/Presentation.php';
    return \AchBot\Subscription\Presentation\primaryLink($serverType, $panelUrl, $botUrl, $token, $botState);
}

function buildSubBackupSection($subRaw, $token) {
    require_once __DIR__ . '/src/Subscription/Presentation.php';
    return \AchBot\Subscription\Presentation\backupLinks($subRaw, $token);
}

function buildConfigMessage($enableText, $remark, $configLinks, $subLink, $backupSection, $serverType) {
    global $mainValues, $hideConnectLinkForMarzban;

    if ($hideConnectLinkForMarzban && $serverType == "marzban") {
        $template = str_replace("\n🔌 لینک تکی کانفیگ:\nCONNECT-LINK\n", "", $mainValues['config_details_message']);
    } else {
        $template = $mainValues['config_details_message'];
    }

    $msg = str_replace(
        ["STATE", "NAME", "CONNECT-LINK", "SUB-LINK"],
        [$enableText, $remark, $configLinks, $subLink],
        $template
    );

    $msg .= $backupSection;
    $msg .= "\n\n♻️ در صورتی که قصد دارید حجم دیگری خریداری کنید می توانید از دکمه ← 🔄 <b>تغییر پلن</b> استفاده و بعد تمدید کنید.";

    return $msg;
}


function getProtocolNamesRow($protocol, $netType, $security, $rahgozar, $inbound_id) {

    if ($inbound_id != 0) {
        return [$protocol];
    }

    if ($protocol == 'trojan') {
        if ($security == "xtls") {
            return ['trojan', 'vless'];
        }
        return ['trojan', 'vmess', 'vless'];
    }

    if ($netType == "grpc") {
        return ['vmess', 'vless'];
    }

    if ($netType == "tcp" && $security == "xtls") {
        return ['trojan', 'vless'];
    }

    if ($rahgozar) {
        return ['vmess', 'vless'];
    }

    return ['trojan', 'vmess', 'vless'];
}

function buildAdminProtocolButtons($protocol, $netType, $security, $rahgozar, $inbound_id) {
    $names = getProtocolNamesRow($protocol, $netType, $security, $rahgozar, $inbound_id);

    $row = [];
    foreach ($names as $name) {
        $row[] = [
            'text' => ($protocol == $name ? "☑️ $name" : $name),
            'callback_data' => "wizwizch"
        ];
    }

    return [$row];
}

function buildUserProtocolButtons($protocol, $netType, $security, $rahgozar, $inbound_id, $serverType, $botState, $fid, $id) {
    if ($serverType == "marzban") return [];

    $names = getProtocolNamesRow($protocol, $netType, $security, $rahgozar, $inbound_id);

    $row = [];
    foreach ($names as $name) {
        $cb = ($botState['changeProtocolState']=="on")
            ? "changeAccProtocol{$fid}_{$id}_{$name}"
            : "changeProtocolIsDisable";

        $row[] = [
            'text' => ($protocol == $name ? "☑️ $name" : $name),
            'callback_data' => $cb
        ];
    }

    return [$row];
}

function getUserOrderDetailKeys($id, $offset = 0){
    global $botState, $buttonValues, $botUrl, $showIndexes;

    $order = findOrder($id);
    if (!$order) return null;

    $userId      = $order['userid'];
    $firstName   = bot('getChat',['chat_id'=>$userId])->result->first_name ?? " ";
    $name        = getPlanFullName($order['fileid'], $id);

    $date        = jdate("Y-m-d H:i", $order['date']);
    $expire_date = jdate("Y-m-d H:i", $order['expire_date']);

    $remark      = $order['remark'];
    $uuid        = $order['uuid'] ?? "0";
    $acc_link    = json_decode($order['link'], true);
    $protocol    = $order['protocol'];
    $token       = $order['token'];
    $server_id   = $order['server_id'];
    $inbound_id  = $order['inbound_id'];
    $rahgozar    = $order['rahgozar'];
    $agentBought = $order['agent_bought'];
    $autoRenew   = $order['autoRenew'] ?? "off";

    $server      = getServerConfigById($server_id);
    $serverType  = $server['type'] ?? '';
    $panelUrl    = $server['panel_url'] ?? '';
    $subLinksRaw = $server['sub_links'] ?? '';

    $traffic     = getOrderTrafficInfo($serverType, $server_id, $remark, $uuid, $inbound_id, $rahgozar);

    $leftgb      = $traffic['leftgb'] ?? "⚠️";
    $enableBool  = $traffic['enable'] ?? false;
    $enableText  = $enableBool ? $buttonValues['active'] : $buttonValues['deactive'];

    $configLinks = buildConfigLinks($acc_link, $botState, $showIndexes);
    $subLink     = buildSubLink($serverType, $panelUrl, $botUrl, $token, $botState);
    $backup      = buildSubBackupSection($subLinksRaw, $token);

    $msg = buildConfigMessage($enableText, $remark, $configLinks, $subLink, $backup, $serverType);

    // ---- Admin Keyboard ----
    $keyboard = [];

    $keyboard[] = [
        ['text' => $userId, 'callback_data' => "wizwizch"],
        ['text' => "آیدی کاربر", 'callback_data' => "wizwizch"],
    ];

    $keyboard[] = [
        ['text' => $firstName, 'callback_data' => "wizwizch"],
        ['text' => "اسم کاربر", 'callback_data' => "wizwizch"],
    ];

    $keyboard[] = [
        ['text' => ($agentBought ? "بله" : "خیر"), 'callback_data' => "wizwizch"],
        ['text' => "خرید همکار", 'callback_data' => "wizwizch"],
    ];

    $keyboard[] = [
        ['text' => $name, 'callback_data' => "wizwizch"],
        ['text' => $buttonValues['plan_name'], 'callback_data' => "wizwizch"],
    ];

    $keyboard[] = [
        ['text' => $date, 'callback_data' => "wizwizch"],
        ['text' => $buttonValues['buy_date'], 'callback_data' => "wizwizch"],
    ];

    $keyboard[] = [
        ['text' => $expire_date, 'callback_data' => "wizwizch"],
        ['text' => $buttonValues['expire_date'], 'callback_data' => "wizwizch"],
    ];

    $keyboard[] = [
        ['text' => $leftgb, 'callback_data' => "wizwizch"],
        ['text' => $buttonValues['volume_left'], 'callback_data' => "wizwizch"],
    ];

    // change plan + switch location
    $keyboard[] = [
        ['text' => $buttonValues['change_plan'], 'callback_data' => "changePlan_{$id}"],
        ['text' => $buttonValues['change_config_location'], 'callback_data' => "switchLocation{$id}_{$server_id}_{$leftgb}_".$order['expire_date']],
    ];

    if (($botState['renewAutoState'] ?? "off") == "on") {
        $keyboard[] = [[
            'text' => (($autoRenew ?? 'off') == "on" ? "🟢 تمدید خودکار روشن" : "🔴 تمدید خودکار خاموش"),
            'callback_data' => "adminToggleAutoRenew{$order['id']}"
        ]];
    }

    // protocol label
    /*$keyboard[] = [
        ['text' => $buttonValues['selected_protocol'], 'callback_data' => "wizwizch"],
    ];

    // protocol rows
    foreach (buildAdminProtocolButtons($protocol, $traffic['netType'], $traffic['security'], $rahgozar, $inbound_id) as $row) {
        $keyboard[] = $row;
    }*/

    // enable/disable + delete
    $keyboard[] = [
        [
            'text' => ($enableBool ? $buttonValues['disable_config'] : $buttonValues['enable_config']),
            'callback_data' => "changeUserConfigState" . $order['id']
        ],
        [
            'text' => $buttonValues['delete_config'],
            'callback_data' => "delUserConfig" . $order['id']
        ],
    ];

    $keyboard[] = [[
        'text' => $buttonValues['back_button'],
        'callback_data' => "managePanel"
    ]];

    return [
        "keyboard" => json_encode(['inline_keyboard' => $keyboard]),
        "msg"      => $msg
    ];
}

function getOrderDetailKeys($from_id, $id, $offset = 0){
    global $botState, $mainValues, $buttonValues, $botUrl, $userInfo, $showIndexes;

    $order = findOrder($id, $from_id);
    if (!$order) return null;

    $fid         = $order['fileid'];
    $name        = getPlanFullName($fid, $id);

    $expire_date = jdate("Y-m-d H:i", $order['expire_date']);

    $remark      = $order['remark'];
    $uuid        = $order['uuid'] ?? "0";
    $acc_link    = json_decode($order['link'], true);
    $protocol    = $order['protocol'];
    $token       = $order['token'];
    $server_id   = $order['server_id'];
    $inbound_id  = $order['inbound_id'];
    $rahgozar    = $order['rahgozar'];
    $agentBought = $order['agent_bought'];
    $autoRenew   = $order['autoRenew'] ?? "off";
    $price       = $order['amount'];

    $server      = getServerConfigById($server_id);
    $serverType  = $server['type'] ?? '';
    $panelUrl    = $server['panel_url'] ?? '';
    $subLinksRaw = $server['sub_links'] ?? '';

    $traffic = getOrderTrafficInfo($serverType, $server_id, $remark, $uuid, $inbound_id, $rahgozar);

    $found      = $traffic['found'] ?? false;
    $enableBool = $traffic['enable'] ?? false;
    $leftgb     = $traffic['leftgb'] ?? "⚠️";

    $enableText = $found
        ? ($enableBool ? $buttonValues['active'] : $buttonValues['deactive'])
        : $mainValues['config_doesnt_exist'];

    $configLinks = buildConfigLinks($acc_link, $botState, $showIndexes);
    $subLink     = buildSubLink($serverType, $panelUrl, $botUrl, $token, $botState);
    $backup      = buildSubBackupSection($subLinksRaw, $token);

    $msg = buildConfigMessage($enableText, $remark, $configLinks, $subLink, $backup, $serverType);

    // ---- User Keyboard ----
    $keyboard = [];

    $keyboard[] = [
        ['text' => $name, 'callback_data' => "wizwizch"],
        ['text' => $buttonValues['plan_name'], 'callback_data' => "wizwizch"],
    ];

    /*
    $keyboard[] = [
        ['text' => jdate("Y-m-d H:i", $order['date']), 'callback_data' => "wizwizch"],
        ['text' => $buttonValues['buy_date'], 'callback_data' => "wizwizch"],
    ];
    */

    $keyboard[] = [
        ['text' => $expire_date, 'callback_data' => "wizwizch"],
        ['text' => $buttonValues['expire_date'], 'callback_data' => "wizwizch"],
    ];

    $keyboard[] = [
        ['text' => $leftgb, 'callback_data' => "wizwizch"],
        ['text' => $buttonValues['volume_left'], 'callback_data' => "wizwizch"],
    ];

    // protocol label
    if ($serverType != "marzban") {
        $keyboard[] = [
            ['text' => $buttonValues['selected_protocol'], 'callback_data' => "wizwizch"],
        ];
    }

    if (($botState['renewAutoState'] ?? "off") == "on") {
        $keyboard[] = [[
            'text' => (($autoRenew ?? 'off') == "on" ? "🟢 تمدید خودکار روشن" : "🔴 تمدید خودکار خاموش"),
            'callback_data' => "toggleAutoRenew{$id}"
        ]];
    }

    foreach (buildUserProtocolButtons(
        $protocol,
        $traffic['netType'],
        $traffic['security'],
        $rahgozar,
        $inbound_id,
        $serverType,
        $botState,
        $fid,
        $id
    ) as $row) {
        $keyboard[] = $row;
    }

    // ---- Renewal + SwitchLocation
    if ($found) {
        $temp = [];

        if (
            ($botState['sellState'] ?? "off") == "on" &&
            ($price != 0 || $agentBought == true) &&
            ($botState['renewAccountState'] ?? "off") == "on"
        ) {
            $temp[] = ['text' => $buttonValues['renew_config'], 'callback_data' => "renewAccountAsk{$id}"];
        }

        if (
            ($price != 0 || $agentBought == true) &&
            ($botState['switchLocationState'] ?? "off") == "on" &&
            $rahgozar != true
        ) {
            $temp[] = ['text' => $buttonValues['change_config_location'], 'callback_data' => "switchLocation{$id}_{$server_id}_{$leftgb}_".$order['expire_date']];
        }

        if (!empty($temp)) {
            $keyboard[] = $temp;
        }
    }

    // ---- User Actions
    if ($found) {

        $flatButtons = [];

        if (
            ($botState['sellState'] ?? "off") == "on" &&
            ($botState['increaseVolumeState'] ?? "off") == "on" &&
            ($price != 0 || $agentBought)
        ) {
            $flatButtons[] = ['text' => $buttonValues['increase_config_volume'], 'callback_data' => "increaseAVolume{$id}"];
        }

        if (
            ($botState['sellState'] ?? "off") == "on" &&
            ($botState['increaseTimeState'] ?? "off") == "on" &&
            ($price != 0 || $agentBought)
        ) {
            $flatButtons[] = ['text' => $buttonValues['increase_config_days'], 'callback_data' => "increaseADay{$id}"];
        }

        if (($botState['renewConfigLinkState'] ?? "off") == "on")
            $flatButtons[] = ['text' => $buttonValues['renew_connection_link'], 'callback_data' => "changAccountConnectionLink{$id}"];

        if (($botState['updateConfigLinkState'] ?? "off") == "on")
            $flatButtons[] = ['text' => $buttonValues['update_config_connection'], 'callback_data' => "updateConfigConnectionLink{$id}"];

        $flatButtons[] = ['text' => $buttonValues['change_plan'], 'callback_data' => "changePlan_{$id}"];

        if (($botState['qrConfigState'] ?? "off") == "on")
            $flatButtons[] = ['text' => $buttonValues['qr_config'], 'callback_data' => "showQrConfig{$id}"];

        if (($botState['qrSubState'] ?? "off") == "on")
            $flatButtons[] = ['text' => $buttonValues['qr_sub'], 'callback_data' => "showQrSub{$id}"];

        // همکار enable/disable
        if (!empty($userInfo['is_agent']) && (int)$userInfo['is_agent'] == 1) {
            $flatButtons[] = [
                'text' => ($enableBool ? $buttonValues['disable_config'] : $buttonValues['enable_config']),
                'callback_data' => "agentChangeUserConfigState{$id}"
            ];
        }

        for ($i = 0; $i < count($flatButtons) - 1; $i += 2) {
            $keyboard[] = [$flatButtons[$i], $flatButtons[$i + 1]];
        }

        $lastButton = (count($flatButtons) % 2 == 1) ? $flatButtons[count($flatButtons) - 1] : null;

        // delete
        $deleteButton = ['text' => $buttonValues['delete_config'], 'callback_data' => "deleteMyConfig{$id}"];

        if ($lastButton) {
            $keyboard[] = [$lastButton, $deleteButton];
        } else {
            $keyboard[] = [$deleteButton];
        }
    }

    $keyboard[] = [[
        'text' => $buttonValues['back_button'],
        'callback_data' => ($agentBought == true ? "agentConfigsList" : "mySubscriptions")
    ]];

    return [
        "keyboard" => json_encode(['inline_keyboard' => $keyboard]),
        "msg"      => $msg
    ];
}

if (preg_match('/^(toggleAutoRenew|adminToggleAutoRenew)(\d+)$/', $data, $match)) {

    global $connection, $admin, $userInfo;

    $mode    = $match[1];
    $orderId = (int)$match[2];

    // گرفتن سفارش
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id`=?");
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$order) {
        alert("❌ سفارش مورد نظر یافت نشد.", true);
        return;
    }

    if ((int)$order['amount'] === 0) {
        alert("⚠️ امکان فعالسازی تمدید خودکار برای این نوع اشتراک وجود ندارد.", true);
        return;
    }

    $isAdmin = (
        (int)$from_id === (int)$admin ||
        (!empty($userInfo['isAdmin']) && $userInfo['isAdmin'] == true)
    );

    // کنترل دسترسی
    if ($mode === "toggleAutoRenew") {
        if ((int)$order['userid'] !== (int)$from_id) {
            alert("⚠️ شما اجازه تغییر این سفارش را ندارید.", true);
            return;
        }
    } else {
        if (!$isAdmin) {
            alert("⚠️ دسترسی ادمین ندارید.", true);
            return;
        }
    }

    // تغییر وضعیت
    $current = $order['autoRenew'] ?? 'off';
    $new     = ($current === 'on') ? 'off' : 'on';

    if ($new === 'on') {

        $fileId = (int)($order['fileid'] ?? 0);
        if ($fileId <= 0) {
            alert("⚠️ پلن این سفارش مشخص نیست (fileid نامعتبر است).", true);
            return;
        }

        $stmt = $connection->prepare("SELECT `price` FROM `server_plans` WHERE `id`=? LIMIT 1");
        $stmt->bind_param("i", $fileId);
        $stmt->execute();
        $plan = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$plan) {
            alert("⚠️ پلن مربوط به این سفارش در server_plans پیدا نشد.", true);
            return;
        }

        if ((float)$plan['price'] <= 0) {
            alert("⚠️ امکان فعالسازی تمدید خودکار برای این نوع اشتراک وجود ندارد.", true);
            return;
        }
    }

    $stmt = $connection->prepare("UPDATE `orders_list` SET `autoRenew`=? WHERE `id`=?");
    $stmt->bind_param("si", $new, $orderId);
    $stmt->execute();
    $stmt->close();

    if ($mode === "adminToggleAutoRenew") {
        $keys = getUserOrderDetailKeys($orderId);
    } else {
        $keys = getOrderDetailKeys($order['userid'], $orderId);
    }

    if (!$keys || empty($keys['msg']) || empty($keys['keyboard'])) {
        alert("⚠️ خطایی در بروزرسانی صفحه رخ داد. دوباره امتحان کنید.", true);
        return;
    }

    editText($message_id, $keys['msg'], $keys['keyboard'], "HTML");

    alert(
        "🔄 وضعیت تمدید خودکار سفارش «{$order['remark']}» به حالت " .
        ($new === 'on' ? 'روشن' : 'خاموش') .
        " تغییر کرد.",
        true
    );
}
// END create user and admin get order detail

function RandomString($count = 9, $type = "all") {
    if($type == "all") $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz123456789';
    elseif($type == "small") $characters = 'abcdef123456789';
    elseif($type == "domain") $characters = 'abcdefghijklmnopqrstuvwxyz';
    
    $randstring = null;
    for ($i = 0; $i < $count; $i++) {
        $randstring .= $characters[
            rand(0, strlen($characters)-1)
        ];
    }
    return $randstring;
}
function generateUID(){
    $randomString = openssl_random_pseudo_bytes(16);
    $time_low = bin2hex(substr($randomString, 0, 4));
    $time_mid = bin2hex(substr($randomString, 4, 2));
    $time_hi_and_version = bin2hex(substr($randomString, 6, 2));
    $clock_seq_hi_and_reserved = bin2hex(substr($randomString, 8, 2));
    $node = bin2hex(substr($randomString, 10, 6));

    $time_hi_and_version = hexdec($time_hi_and_version);
    $time_hi_and_version = $time_hi_and_version >> 4;
    $time_hi_and_version = $time_hi_and_version | 0x4000;

    $clock_seq_hi_and_reserved = hexdec($clock_seq_hi_and_reserved);
    $clock_seq_hi_and_reserved = $clock_seq_hi_and_reserved >> 2;
    $clock_seq_hi_and_reserved = $clock_seq_hi_and_reserved | 0x8000;

    return sprintf('%08s-%04s-%04x-%04x-%012s', $time_low, $time_mid, $time_hi_and_version, $clock_seq_hi_and_reserved, $node);
}
function checkStep($table){
    global $connection;
    
    if($table == "server_plans") $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `active` = 0");
    if($table == "server_categories") $stmt = $connection->prepare("SELECT * FROM `server_categories` WHERE `active` = 0");
    
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $res['step']; 
}
function setUser($value = 'none', $field = 'step'){
    global $connection, $from_id, $username, $first_name;

    $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid`=?");
    $stmt->bind_param("i", $from_id);
    $stmt->execute();
    $uinfo = $stmt->get_result();
    $stmt->close();

    
    if($uinfo->num_rows == 0){
        $stmt = $connection->prepare("INSERT INTO `users` (`userid`, `name`, `username`, `refcode`, `wallet`, `date`)
                            VALUES (?,?,?, 0,0,?)");
        $time = time();
        $stmt->bind_param("issi", $from_id, $first_name, $username, $time);
        $stmt->execute();
        $stmt->close();
    }
    
    if($field == "wallet") $stmt = $connection->prepare("UPDATE `users` SET `wallet` = ? WHERE `userid` = ?");
    elseif($field == "phone") $stmt = $connection->prepare("UPDATE `users` SET `phone` = ? WHERE `userid` = ?");
    elseif($field == "refered_by") $stmt = $connection->prepare("UPDATE `users` SET `refered_by` = ? WHERE `userid` = ?");
    elseif($field == "step") $stmt = $connection->prepare("UPDATE `users` SET `step` = ? WHERE `userid` = ?");
    elseif($field == "freetrial") $stmt = $connection->prepare("UPDATE `users` SET `freetrial` = ? WHERE `userid` = ?");
    elseif($field == "isAdmin") $stmt = $connection->prepare("UPDATE `users` SET `isAdmin` = ? WHERE `userid` = ?");
    elseif($field == "first_start") $stmt = $connection->prepare("UPDATE `users` SET `first_start` = ? WHERE `userid` = ?");
    elseif($field == "temp") $stmt = $connection->prepare("UPDATE `users` SET `temp` = ? WHERE `userid` = ?");
    elseif($field == "is_agent") $stmt = $connection->prepare("UPDATE `users` SET `is_agent` = ? WHERE `userid` = ?");
    elseif($field == "discount_percent") $stmt = $connection->prepare("UPDATE `users` SET `discount_percent` = ? WHERE `userid` = ?");
    elseif($field == "agent_date") $stmt = $connection->prepare("UPDATE `users` SET `agent_date` = ? WHERE `userid` = ?");
    elseif($field == "spam_info") $stmt = $connection->prepare("UPDATE `users` SET `spam_info` = ? WHERE `userid` = ?");
    
    $stmt->bind_param("si", $value, $from_id);
    $stmt->execute();
    $stmt->close();
}
function generateRandomString($length, $protocol) {
    return ($protocol == 'trojan') ? substr(md5(time()),5,15) : generateUID();
}
function addBorderImage($add){
    $border = 30;
    $im = ImageCreateFromPNG($add);
    $width = ImageSx($im);
    $height = ImageSy($im);
    $img_adj_width = $width + 2 * $border;
    $img_adj_height = $height + 2 * $border;
    $newimage = imagecreatetruecolor($img_adj_width, $img_adj_height);
    $border_color = imagecolorallocate($newimage, 255, 255, 255);
    imagefilledrectangle($newimage, 0, 0, $img_adj_width, $img_adj_height, $border_color);
    imageCopyResized($newimage, $im, $border, $border, 0, 0, $width, $height, $width, $height);
    ImagePNG($newimage, $add, 5);
}
function sumerize($amount){
    require_once __DIR__ . '/src/Subscription/Presentation.php';
    return \AchBot\Subscription\Presentation\formatTraffic($amount);
}

function sumerize2($amount){
    require_once __DIR__ . '/src/Subscription/Presentation.php';
    return \AchBot\Subscription\Presentation\trafficInGigabytes($amount);
}
function deleteClient($server_id, $inbound_id, $uuid, $delete = 0){
    global $connection;
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $panel_url = $server_info['panel_url'];
    $serverType = $server_info['type'];

    $response = getJson($server_id);
    if(!$response) return null;
    $response = $response->obj;
    $old_data = []; $oldclientstat = [];
    foreach($response as $row){
        if($row->id == $inbound_id) {
            $settings = json_decode($row->settings);
            $clients = $settings->clients;

            $clientsStates = $row->clientStats;
            foreach($clients as $key => $client){
                if($client->id == $uuid || $client->password == $uuid){
                    $old_data = $client;
                    unset($clients[$key]);
                    $email = $client->email;
                    $emails = array_column($clientsStates,'email');
                    $emailKey = array_search($email,$emails);
                    
                    $total = $clientsStates[$emailKey]->total;
                    $up = $clientsStates[$emailKey]->up;
                    $enable = $clientsStates[$emailKey]->enable;
                    $down = $clientsStates[$emailKey]->down; 
                    break;
                }
            }
        }
    }
    $settings->clients = $clients;
    $settings = json_encode($settings);
	
    if($delete == 1){
        $dataArr = array('up' => $row->up,'down' => $row->down,'total' => $row->total,'remark' => $row->remark,'enable' => 'true',
        'expiryTime' => $row->expiryTime, 'listen' => '','port' => $row->port,'protocol' => $row->protocol,'settings' => $settings,
        'streamSettings' => $row->streamSettings, 'sniffing' => $row->sniffing);

        $serverName = $server_info['username'];
        $serverPass = $server_info['password'];
        
        $loginUrl = $panel_url . '/login';
        
        $postFields = array(
            "username" => $serverName,
            "password" => $serverPass
            );
            
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $loginUrl);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_POST, 1);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($curl, CURLOPT_TIMEOUT, 3); 
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($postFields));
        curl_setopt($curl, CURLOPT_HEADER, 1);
        $response = curl_exec($curl);
        
        $header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        $header = substr($response, 0, $header_size);
        $body = substr($response, $header_size);
        preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $header, $matches);
        $cookies = array();
        foreach($matches[1] as $item) {
            parse_str($item, $cookie);
            $cookies = array_merge($cookies, $cookie);
        }
        
        $loginResponse = json_decode($body,true);
        
        if(!$loginResponse['success']){
            curl_close($curl);
            return $loginResponse;
        }
        
        if($serverType == "sanaei" || $serverType == "alireza"){
            if($serverType == "sanaei") $url = "$panel_url/panel/inbound/" . $inbound_id . "/delClient/" . rawurlencode($uuid);
            elseif($serverType == "alireza") $url = "$panel_url/xui/inbound/" . $inbound_id . "/delClient/" . rawurlencode($uuid);

            curl_setopt_array($curl, array(
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => $dataArr,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_HEADER => false,
                CURLOPT_HTTPHEADER => array(
                    'User-Agent:  Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:108.0) Gecko/20100101 Firefox/108.0',
                    'Accept:  application/json, text/plain, */*',
                    'Accept-Language:  en-US,en;q=0.5',
                    'Accept-Encoding:  gzip, deflate',
                    'X-Requested-With:  XMLHttpRequest',
                    'Cookie: ' . array_keys($cookies)[0] . "=" . $cookies[array_keys($cookies)[0]]
                )
            ));
        }else{
            curl_setopt_array($curl, array(
                CURLOPT_URL => "$panel_url/xui/inbound/update/$inbound_id",
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_CONNECTTIMEOUT => 15,  
                CURLOPT_TIMEOUT => 15,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => $dataArr,
                CURLOPT_HEADER => false,
                CURLOPT_HTTPHEADER => array(
                    'User-Agent:  Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:108.0) Gecko/20100101 Firefox/108.0',
                    'Accept:  application/json, text/plain, */*',
                    'Accept-Language:  en-US,en;q=0.5',
                    'Accept-Encoding:  gzip, deflate',
                    'X-Requested-With:  XMLHttpRequest',
                    'Cookie: ' . array_keys($cookies)[0] . "=" . $cookies[array_keys($cookies)[0]]
                )
            ));
        }
        
        $response = curl_exec($curl);
        curl_close($curl);
    }	
    return ['id' => $old_data->id,'expiryTime' => $old_data->expiryTime, 'limitIp' => $old_data->limitIp, 'flow' => $old_data->flow, 'total' => $total, 'up' => $up, 'down' => $down,];

}
function editInboundRemark($server_id, $uuid, $newRemark){
    global $connection;
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $panel_url = $server_info['panel_url'];
    $serverType = $server_info['type'];

    $response = getJson($server_id);
    if(!$response) return null;
    $response = $response->obj;
    foreach($response as $row){
        $clients = json_decode($row->settings)->clients;
        if($clients[0]->id == $uuid || $clients[0]->password == $uuid) {
            $inbound_id = $row->id;
            $total = $row->total;
            $up = $row->up;
            $down = $row->down;
            $expiryTime = $row->expiryTime;
            $port = $row->port;
            $netType = json_decode($row->streamSettings)->network;
            break;
        }
    }


    $dataArr = array('up' => $up,'down' => $down,'total' => $total,'remark' => $newRemark,'enable' => 'true',
        'expiryTime' => $row->expiryTime, 'listen' => '','port' => $row->port,'protocol' => $row->protocol,'settings' => $row->settings,
        'streamSettings' => $row->streamSettings, 'sniffing' => $row->sniffing);


    $serverName = $server_info['username'];
    $serverPass = $server_info['password'];
    
    $loginUrl = $panel_url . '/login';
    
    $postFields = array(
        "username" => $serverName,
        "password" => $serverPass
        );
        
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $loginUrl);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_POST, 1);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($curl, CURLOPT_TIMEOUT, 3); 
    curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($postFields));
    curl_setopt($curl, CURLOPT_HEADER, 1);
    $response = curl_exec($curl);

    $header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $header = substr($response, 0, $header_size);
    $body = substr($response, $header_size);
    preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $header, $matches);
    $cookies = array();
    foreach($matches[1] as $item) {
        parse_str($item, $cookie);
        $cookies = array_merge($cookies, $cookie);
    }
    
    $loginResponse = json_decode($body,true);
    if(!$loginResponse['success']){
        curl_close($curl);
        return $loginResponse;
    }

    if($serverType == "sanaei") $url = "$panel_url/panel/inbound/update/$inbound_id";
    else $url = "$panel_url/xui/inbound/update/$inbound_id";

    curl_setopt_array($curl, array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_CONNECTTIMEOUT => 15,      // timeout on connect
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => $dataArr,
        CURLOPT_HEADER => false,
        CURLOPT_HTTPHEADER => array(
            'User-Agent:  Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:108.0) Gecko/20100101 Firefox/108.0',
            'Accept:  application/json, text/plain, */*',
            'Accept-Language:  en-US,en;q=0.5',
            'Accept-Encoding:  gzip, deflate',
            'X-Requested-With:  XMLHttpRequest',
            'Cookie: ' . array_keys($cookies)[0] . "=" . $cookies[array_keys($cookies)[0]]
        )
    ));

    $response = curl_exec($curl);
    curl_close($curl);
    return $response = json_decode($response);
}
function editInboundTraffic($server_id, $uuid, $volume, $days, $editType = null){
    global $connection;
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $panel_url = $server_info['panel_url'];
    $serverType = $server_info['type'];

    $response = getJson($server_id);
    if(!$response) return null;
    $response = $response->obj;
    foreach($response as $row){
        $clients = json_decode($row->settings)->clients;
        if($clients[0]->id == $uuid || $clients[0]->password == $uuid) {
            $inbound_id = $row->id;
            $total = $row->total;
            $up = $row->up;
            $down = $row->down;
            $expiryTime = $row->expiryTime;
            $port = $row->port;
            $netType = json_decode($row->streamSettings)->network;
            
            $email = $clients[0]->email;

            break;
        }
    }
    if($days != 0) {
        $now_microdate = floor(microtime(true) * 1000);
        $extend_date = (864000 * $days * 100);
        if($editType == "renew") $expire_microdate = $now_microdate + $extend_date;
        else $expire_microdate = ($now_microdate > $expiryTime) ? $now_microdate + $extend_date : $expiryTime + $extend_date;
    }

    if($volume != 0){
        $leftGB = $total - $up - $down;
        $extend_volume = floor($volume * 1073741824);
        if($editType == "renew"){
            $total = $extend_volume;
            $up = 0;
            $down = 0;
            $volume = $extend_volume;
            if($serverType == "sanaei" || $serverType == "alireza") resetClientTraffic($server_id, $email, $inbound_id);
            else resetClientTraffic($server_id, $email);
        }
        else $total = ($leftGB > 0) ? $total + $extend_volume : $extend_volume;
    }

    $dataArr = array('up' => $up,'down' => $down,'total' => is_null($total) ? $row->total : $total,'remark' => $row->remark,'enable' => 'true',
        'expiryTime' => is_null($expire_microdate) ? $row->expiryTime : $expire_microdate, 'listen' => '','port' => $row->port,'protocol' => $row->protocol,'settings' => $row->settings,
        'streamSettings' => $row->streamSettings, 'sniffing' => $row->sniffing);


    $serverName = $server_info['username'];
    $serverPass = $server_info['password'];
    
    $loginUrl = $panel_url . '/login';
    
    $postFields = array(
        "username" => $serverName,
        "password" => $serverPass
        );
        
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $loginUrl);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_POST, 1);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($curl, CURLOPT_TIMEOUT, 3); 
    curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($postFields));
    curl_setopt($curl, CURLOPT_HEADER, 1);
    $response = curl_exec($curl);
    
    $header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $header = substr($response, 0, $header_size);
    $body = substr($response, $header_size);
    preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $header, $matches);
    $cookies = array();
    foreach($matches[1] as $item) {
        parse_str($item, $cookie);
        $cookies = array_merge($cookies, $cookie);
    }

    $loginResponse = json_decode($body,true);
    if(!$loginResponse['success']){
        curl_close($curl);
        return $loginResponse;
    }

    if($serverType == "sanaei") $url = "$panel_url/panel/inbound/update/$inbound_id";
    else $url = "$panel_url/xui/inbound/update/$inbound_id";

    $phost = str_ireplace('https://','',str_ireplace('http://','',$panel_url));
    curl_setopt_array($curl, array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_CONNECTTIMEOUT => 15,      // timeout on connect
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => $dataArr,
        CURLOPT_HEADER => false,
        CURLOPT_HTTPHEADER => array(
            'User-Agent:  Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:108.0) Gecko/20100101 Firefox/108.0',
            'Accept:  application/json, text/plain, */*',
            'Accept-Language:  en-US,en;q=0.5',
            'Accept-Encoding:  gzip, deflate',
            'X-Requested-With:  XMLHttpRequest',
            'Cookie: ' . array_keys($cookies)[0] . "=" . $cookies[array_keys($cookies)[0]]
        )
    ));

    $response = curl_exec($curl);
    curl_close($curl);
    resetIpLog($server_id, $email);
    return $response = json_decode($response);
}
function changeInboundState($server_id, $uuid){
    global $connection;
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $panel_url = $server_info['panel_url'];
    $serverType = $server_info['type'];

    $response = getJson($server_id);
    if(!$response) return null;
    $response = $response->obj;
    foreach($response as $row){
        $settings = json_decode($row->settings, true);
        $clients = $settings['clients'];
        if($clients[0]['id'] == $uuid || $clients[0]['password'] == $uuid) {
            $inbound_id = $row->id;
            $enable = $row->enable;
            break;
        }
    }
    
    if(!isset($settings['clients'][0]['subId']) && ($serverType == "sanaei" || $serverType == "alireza")) $settings['clients'][0]['subId'] = RandomString(16);
    if(!isset($settings['clients'][0]['enable']) && ($serverType == "sanaei" || $serverType == "alireza")) $settings['clients'][0]['enable'] = true;

    $editedClient = $settings['clients'][$client_key];
    $settings['clients'] = array_values($settings['clients']);
    $settings = json_encode($settings,488);

    $newEnable = $enable == true?false:true;
    
    $dataArr = array('up' => $row->up,'down' => $row->down,'total' => $row->total,'remark' => $row->remark,'enable' => $newEnable,
        'expiryTime' => $row->expiryTime, 'listen' => '','port' => $row->port,'protocol' => $row->protocol,'settings' => $settings,
        'streamSettings' => $row->streamSettings, 'sniffing' => $row->sniffing);


    $serverName = $server_info['username'];
    $serverPass = $server_info['password'];
    
    $loginUrl = $panel_url . '/login';
    
    $postFields = array(
        "username" => $serverName,
        "password" => $serverPass
        );
        
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $loginUrl);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_POST, 1);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($curl, CURLOPT_TIMEOUT, 3); 
    curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($postFields));
    curl_setopt($curl, CURLOPT_HEADER, 1);
    $response = curl_exec($curl);
    
    $header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $header = substr($response, 0, $header_size);
    $body = substr($response, $header_size);
    preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $header, $matches);
    $cookies = array();
    foreach($matches[1] as $item) {
        parse_str($item, $cookie);
        $cookies = array_merge($cookies, $cookie);
    }


    $loginResponse = json_decode($body,true);
    if(!$loginResponse['success']){
        curl_close($curl);
        return $loginResponse;
    }

    if($serverType == "sanaei") $url = "$panel_url/panel/inbound/update/$inbound_id";
    else $url = "$panel_url/xui/inbound/update/$inbound_id";

    $phost = str_ireplace('https://','',str_ireplace('http://','',$panel_url));
    curl_setopt_array($curl, array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_CONNECTTIMEOUT => 15,      // timeout on connect
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => $dataArr,
        CURLOPT_HEADER => false,
        CURLOPT_HTTPHEADER => array(
            'User-Agent:  Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:108.0) Gecko/20100101 Firefox/108.0',
            'Accept:  application/json, text/plain, */*',
            'Accept-Language:  en-US,en;q=0.5',
            'Accept-Encoding:  gzip, deflate',
            'X-Requested-With:  XMLHttpRequest',
            'Cookie: ' . array_keys($cookies)[0] . "=" . $cookies[array_keys($cookies)[0]]
        )
    ));

    $response = curl_exec($curl);
    curl_close($curl);

    $response = json_decode($response);
    return $response;

}
function renewInboundUuid($server_id, $uuid){
    global $connection;
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $panel_url = $server_info['panel_url'];
    $serverType = $server_info['type'];

    $response = getJson($server_id);
    if(!$response) return null;
    $response = $response->obj;
    foreach($response as $row){
        $settings = json_decode($row->settings, true);
        $clients = $settings['clients'];
        if($clients[0]['id'] == $uuid || $clients[0]['password'] == $uuid) {
            $inbound_id = $row->id;
            $total = $row->total;
            $up = $row->up;
            $down = $row->down;
            $expiryTime = $row->expiryTime;
            $port = $row->port;
            $protocol = $row->protocol;
            $netType = json_decode($row->streamSettings)->network;
            break;
        }
    }
    
    $newUuid = generateRandomString(42,$protocol); 
    if($protocol == "trojan") $settings['clients'][0]['password'] = $newUuid;
    else $settings['clients'][0]['id'] = $newUuid;
    if(!isset($settings['clients'][0]['subId']) && ($serverType == "sanaei" || $serverType == "alireza")) $settings['clients'][0]['subId'] = RandomString(16);
    if(!isset($settings['clients'][0]['enable']) && ($serverType == "sanaei" || $serverType == "alireza")) $settings['clients'][0]['enable'] = true;

    $editedClient = $settings['clients'][$client_key];
    $settings['clients'] = array_values($settings['clients']);
    $settings = json_encode($settings,488);


    $dataArr = array('up' => $row->up,'down' => $row->down,'total' => $row->total,'remark' => $row->remark,'enable' => 'true',
        'expiryTime' => $row->expiryTime, 'listen' => '','port' => $row->port,'protocol' => $row->protocol,'settings' => $settings,
        'streamSettings' => $row->streamSettings, 'sniffing' => $row->sniffing);


    $serverName = $server_info['username'];
    $serverPass = $server_info['password'];
    
    $loginUrl = $panel_url . '/login';
    
    $postFields = array(
        "username" => $serverName,
        "password" => $serverPass
        );
        
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $loginUrl);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_POST, 1);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($curl, CURLOPT_TIMEOUT, 3); 
    curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($postFields));
    curl_setopt($curl, CURLOPT_HEADER, 1);
    $response = curl_exec($curl);

    $header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $header = substr($response, 0, $header_size);
    $body = substr($response, $header_size);
    preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $header, $matches);
    $cookies = array();
    foreach($matches[1] as $item) {
        parse_str($item, $cookie);
        $cookies = array_merge($cookies, $cookie);
    }

    $loginResponse = json_decode($body,true);
    if(!$loginResponse['success']){
        curl_close($curl);
        return $loginResponse;
    }

    if($serverType == "sanaei") $url = "$panel_url/panel/inbound/update/$inbound_id";
    else $url = "$panel_url/xui/inbound/update/$inbound_id";

    $phost = str_ireplace('https://','',str_ireplace('http://','',$panel_url));
    curl_setopt_array($curl, array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_CONNECTTIMEOUT => 15,      // timeout on connect
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => $dataArr,
        CURLOPT_HEADER => false,
        CURLOPT_HTTPHEADER => array(
            'User-Agent:  Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:108.0) Gecko/20100101 Firefox/108.0',
            'Accept:  application/json, text/plain, */*',
            'Accept-Language:  en-US,en;q=0.5',
            'Accept-Encoding:  gzip, deflate',
            'X-Requested-With:  XMLHttpRequest',
            'Cookie: ' . array_keys($cookies)[0] . "=" . $cookies[array_keys($cookies)[0]]
        )
    ));

    $response = curl_exec($curl);
    curl_close($curl);
    $response = json_decode($response);
    $response->newUuid = $newUuid;
    return $response;

}
function changeClientState($server_id, $inbound_id, $uuid){
    global $connection;
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $panel_url = $server_info['panel_url'];
    $serverType = $server_info['type'];

    $response = getJson($server_id);
    if(!$response) return null;
    $response = $response->obj;
    $client_key = -1;
    foreach($response as $row){
        if($row->id == $inbound_id) {
            $settings = json_decode($row->settings, true);
            $clients = $settings['clients'];
            
            foreach($clients as $key => $client){
                if($client['id'] == $uuid || $client['password'] == $uuid){
                    $client_key = $key;
                    $enable = $client['enable'];
                    break;
                }
            }
        }
    }
    if($client_key == -1) return null;
    
    if(!isset($settings['clients'][$client_key]['subId']) && ($serverType == "sanaei" || $serverType == "alireza")) $settings['clients'][$client_key]['subId'] = RandomString(16);
    $settings['clients'][$client_key]['enable'] = $enable == true?false:true;

    $editedClient = $settings['clients'][$client_key];
    $settings['clients'] = array_values($settings['clients']);
    $settings = json_encode($settings,488);
    $dataArr = array('up' => $row->up,'down' => $row->down,'total' => $row->total,'remark' => $row->remark,'enable' => 'true',
        'expiryTime' => $row->expiryTime, 'listen' => '','port' => $row->port,'protocol' => $row->protocol,'settings' => $settings,
        'streamSettings' => $row->streamSettings, 'sniffing' => $row->sniffing);

    $serverName = $server_info['username'];
    $serverPass = $server_info['password'];
    
    $loginUrl = $panel_url . '/login';
    
    $postFields = array(
        "username" => $serverName,
        "password" => $serverPass
        );
        
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $loginUrl);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_POST, 1);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($curl, CURLOPT_TIMEOUT, 3); 
    curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($postFields));
    curl_setopt($curl, CURLOPT_HEADER, 1);
    $response = curl_exec($curl);

    $header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $header = substr($response, 0, $header_size);
    $body = substr($response, $header_size);
    preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $header, $matches);
    $cookies = array();
    foreach($matches[1] as $item) {
        parse_str($item, $cookie);
        $cookies = array_merge($cookies, $cookie);
    }

    $loginResponse = json_decode($body,true);
    if(!$loginResponse['success']){
        curl_close($curl);
        return $loginResponse;
    }

    if($serverType == "sanaei" || $serverType == "alireza"){
        
        $newSetting = array();
        $newSetting['clients'][] = $editedClient;
        $newSetting = json_encode($newSetting);

        $dataArr = array(
            "id"=>$inbound_id,
            "settings" => $newSetting
            );
            
        if($serverType == "sanaei") $url = "$panel_url/panel/inbound/updateClient/" . rawurlencode($uuid);
        else $url = "$panel_url/xui/inbound/updateClient/" . rawurlencode($uuid);
        
        curl_setopt_array($curl, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $dataArr,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HEADER => false,
            CURLOPT_HTTPHEADER => array(
                'User-Agent:  Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:108.0) Gecko/20100101 Firefox/108.0',
                'Accept:  application/json, text/plain, */*',
                'Accept-Language:  en-US,en;q=0.5',
                'Accept-Encoding:  gzip, deflate',
                'X-Requested-With:  XMLHttpRequest',
                'Cookie: ' . array_keys($cookies)[0] . "=" . $cookies[array_keys($cookies)[0]]
            )
        ));
    }else{
        curl_setopt_array($curl, array(
            CURLOPT_URL => "$panel_url/xui/inbound/update/$inbound_id",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $dataArr,
            CURLOPT_HEADER => false,
            CURLOPT_HTTPHEADER => array(
                'User-Agent:  Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:108.0) Gecko/20100101 Firefox/108.0',
                'Accept:  application/json, text/plain, */*',
                'Accept-Language:  en-US,en;q=0.5',
                'Accept-Encoding:  gzip, deflate',
                'X-Requested-With:  XMLHttpRequest',
                'Cookie: ' . array_keys($cookies)[0] . "=" . $cookies[array_keys($cookies)[0]]
            )
        ));
    }

    $response = curl_exec($curl);
    $response = json_decode($response);
    curl_close($curl);
    return $response;

}
function renewClientUuid($server_id, $inbound_id, $uuid){
    global $connection;
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $panel_url = $server_info['panel_url'];
    $serverType = $server_info['type'];

    $response = getJson($server_id);
    if(!$response) return null;
    $response = $response->obj;
    $client_key = -1;
    foreach($response as $row){
        if($row->id == $inbound_id) {
            $settings = json_decode($row->settings, true);
            $clients = $settings['clients'];
            
            foreach($clients as $key => $client){
                if($client['id'] == $uuid || $client['password'] == $uuid){
                    $protocol = $row->protocol;
                    $client_key = $key;
                    break;
                }
            }
        }
    }
    if($client_key == -1) return null;
    
    $newUuid = generateRandomString(42,$protocol); 
    if($protocol == "trojan") $settings['clients'][$client_key]['password'] = $newUuid;
    else $settings['clients'][$client_key]['id'] = $newUuid;
    if(!isset($settings['clients'][$client_key]['subId']) && ($serverType == "sanaei" || $serverType == "alireza")) $settings['clients'][$client_key]['subId'] = RandomString(16);
    if(!isset($settings['clients'][$client_key]['enable']) && ($serverType == "sanaei" || $serverType == "alireza")) $settings['clients'][$client_key]['enable'] = true;

    $editedClient = $settings['clients'][$client_key];
    $settings['clients'] = array_values($settings['clients']);
    $settings = json_encode($settings,488);
    $dataArr = array('up' => $row->up,'down' => $row->down,'total' => $row->total,'remark' => $row->remark,'enable' => 'true',
        'expiryTime' => $row->expiryTime, 'listen' => '','port' => $row->port,'protocol' => $row->protocol,'settings' => $settings,
        'streamSettings' => $row->streamSettings, 'sniffing' => $row->sniffing);

    $serverName = $server_info['username'];
    $serverPass = $server_info['password'];
    
    $loginUrl = $panel_url . '/login';
    
    $postFields = array(
        "username" => $serverName,
        "password" => $serverPass
        );
        
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $loginUrl);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_POST, 1);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($curl, CURLOPT_TIMEOUT, 3); 
    curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($postFields));
    curl_setopt($curl, CURLOPT_HEADER, 1);
    $response = curl_exec($curl);

    $header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $header = substr($response, 0, $header_size);
    $body = substr($response, $header_size);
    preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $header, $matches);
    $cookies = array();
    foreach($matches[1] as $item) {
        parse_str($item, $cookie);
        $cookies = array_merge($cookies, $cookie);
    }

    $loginResponse = json_decode($body,true);
    if(!$loginResponse['success']){
        curl_close($curl);
        return $loginResponse;
    }

    if($serverType == "sanaei" || $serverType == "alireza"){
        
        $newSetting = array();
        $newSetting['clients'][] = $editedClient;
        $newSetting = json_encode($newSetting);

        $dataArr = array(
            "id"=>$inbound_id,
            "settings" => $newSetting
            );
            
        if($serverType == "sanaei") $url = "$panel_url/panel/inbound/updateClient/" . rawurlencode($uuid);
        else $url = "$panel_url/xui/inbound/updateClient/" . rawurlencode($uuid);
        
        curl_setopt_array($curl, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $dataArr,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HEADER => false,
            CURLOPT_HTTPHEADER => array(
                'User-Agent:  Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:108.0) Gecko/20100101 Firefox/108.0',
                'Accept:  application/json, text/plain, */*',
                'Accept-Language:  en-US,en;q=0.5',
                'Accept-Encoding:  gzip, deflate',
                'X-Requested-With:  XMLHttpRequest',
                'Cookie: ' . array_keys($cookies)[0] . "=" . $cookies[array_keys($cookies)[0]]
            )
        ));
    }else{
        curl_setopt_array($curl, array(
            CURLOPT_URL => "$panel_url/xui/inbound/update/$inbound_id",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $dataArr,
            CURLOPT_HEADER => false,
            CURLOPT_HTTPHEADER => array(
                'User-Agent:  Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:108.0) Gecko/20100101 Firefox/108.0',
                'Accept:  application/json, text/plain, */*',
                'Accept-Language:  en-US,en;q=0.5',
                'Accept-Encoding:  gzip, deflate',
                'X-Requested-With:  XMLHttpRequest',
                'Cookie: ' . array_keys($cookies)[0] . "=" . $cookies[array_keys($cookies)[0]]
            )
        ));
    }

    $response = curl_exec($curl);
    $response = json_decode($response);
    $response->newUuid = $newUuid;

    curl_close($curl);
    return $response;

}
function editClientRemark($server_id, $inbound_id, $uuid, $newRemark){
    global $connection;
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $panel_url = $server_info['panel_url'];
    $serverType = $server_info['type'];

    $response = getJson($server_id);
    if(!$response) return null;
    $response = $response->obj;
    $client_key = 0;
    foreach($response as $row){
        if($row->id == $inbound_id) {
            $settings = json_decode($row->settings, true);
            $clients = $settings['clients'];
            
            $clientsStates = $row->clientStats;
            foreach($clients as $key => $client){
                if($client['id'] == $uuid || $client['password'] == $uuid){
                    $client_key = $key;
                    $email = $client['email'];
                    $emails = array_column($clientsStates,'email');
                    $emailKey = array_search($email,$emails);
                    
                    $total = $clientsStates[$emailKey]->total;
                    $up = $clientsStates[$emailKey]->up;
                    $enable = $clientsStates[$emailKey]->enable;
                    $down = $clientsStates[$emailKey]->down; 
                    break;
                }
            }
        }
    }
    $settings['clients'][$client_key]['email'] = $newRemark;
    if(!isset($settings['clients'][$client_key]['subId']) && ($serverType == "sanaei" || $serverType == "alireza")) $settings['clients'][$client_key]['subId'] = RandomString(16);
    if(!isset($settings['clients'][$client_key]['enable']) && ($serverType == "sanaei" || $serverType == "alireza")) $settings['clients'][$client_key]['enable'] = true;

    $editedClient = $settings['clients'][$client_key];
    $settings['clients'] = array_values($settings['clients']);
    $settings = json_encode($settings);
    $dataArr = array('up' => $row->up,'down' => $row->down,'total' => $row->total,'remark' => $row->remark,'enable' => 'true',
        'expiryTime' => $row->expiryTime, 'listen' => '','port' => $row->port,'protocol' => $row->protocol,'settings' => $settings,
        'streamSettings' => $row->streamSettings, 'sniffing' => $row->sniffing);

    $serverName = $server_info['username'];
    $serverPass = $server_info['password'];
    
    $loginUrl = $panel_url . '/login';
    
    $postFields = array(
        "username" => $serverName,
        "password" => $serverPass
        );
         
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $loginUrl);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_POST, 1);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($curl, CURLOPT_TIMEOUT, 3); 
    curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($postFields));
    curl_setopt($curl, CURLOPT_HEADER, 1);
    $response = curl_exec($curl);

    $header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $header = substr($response, 0, $header_size);
    $body = substr($response, $header_size);
    preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $header, $matches);
    $cookies = array();
    foreach($matches[1] as $item) {
        parse_str($item, $cookie);
        $cookies = array_merge($cookies, $cookie);
    }

    $loginResponse = json_decode($body,true);
    if(!$loginResponse['success']){
        curl_close($curl);
        return $loginResponse; 
    } 

    if($serverType == "sanaei" || $serverType == "alireza"){
        
        $newSetting = array();
        $newSetting['clients'][] = $editedClient;
        $newSetting = json_encode($newSetting);

        $dataArr = array(
            "id"=>$inbound_id,
            "settings" => $newSetting
            );
            
        if($serverType == "sanaei") $url = "$panel_url/panel/inbound/updateClient/" . rawurlencode($uuid);
        else $url = "$panel_url/xui/inbound/updateClient/" . rawurlencode($uuid);
        
        curl_setopt_array($curl, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $dataArr,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HEADER => false,
            CURLOPT_HTTPHEADER => array(
                'User-Agent:  Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:108.0) Gecko/20100101 Firefox/108.0',
                'Accept:  application/json, text/plain, */*',
                'Accept-Language:  en-US,en;q=0.5',
                'Accept-Encoding:  gzip, deflate',
                'X-Requested-With:  XMLHttpRequest',
                'Cookie: ' . array_keys($cookies)[0] . "=" . $cookies[array_keys($cookies)[0]]
            )
        ));
    }else{
        curl_setopt_array($curl, array(
            CURLOPT_URL => "$panel_url/xui/inbound/update/$inbound_id",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $dataArr,
            CURLOPT_HEADER => false,
            CURLOPT_HTTPHEADER => array(
                'User-Agent:  Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:108.0) Gecko/20100101 Firefox/108.0',
                'Accept:  application/json, text/plain, */*',
                'Accept-Language:  en-US,en;q=0.5',
                'Accept-Encoding:  gzip, deflate',
                'X-Requested-With:  XMLHttpRequest',
                'Cookie: ' . array_keys($cookies)[0] . "=" . $cookies[array_keys($cookies)[0]]
            )
        ));
    }

    $response = curl_exec($curl);
    curl_close($curl);
    return $response = json_decode($response);

}
function editClientTraffic($server_id, $inbound_id, $uuid, $volume, $days, $editType = null){
    global $connection;
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $panel_url = $server_info['panel_url'];
    $serverType = $server_info['type'];

    $response = getJson($server_id);
    if(!$response) return null;
    $response = $response->obj;
    $client_key = 0;
    foreach($response as $row){
        if($row->id == $inbound_id) {
            $settings = json_decode($row->settings, true);
            $clients = $settings['clients'];
            
            $clientsStates = $row->clientStats;
            foreach($clients as $key => $client){
                if($client['id'] == $uuid || $client['password'] == $uuid){
                    $client_key = $key;
                    $email = $client['email'];
                    $emails = array_column($clientsStates,'email');
                    $emailKey = array_search($email,$emails);
                    
                    $total = $clientsStates[$emailKey]->total;
                    $up = $clientsStates[$emailKey]->up;
                    $enable = $clientsStates[$emailKey]->enable;
                    $down = $clientsStates[$emailKey]->down; 
                    break;
                }
            }
        }
    }
    if($volume != 0){
        $client_total = $settings['clients'][$client_key]['totalGB'];// - $up - $down;
        $extend_volume = floor($volume * 1073741824);
        $volume = ($client_total > 0) ? $client_total + $extend_volume : $extend_volume;
        if($editType == "renew"){
            $volume = $extend_volume;
            if($serverType == "sanaei" || $serverType == "alireza") resetClientTraffic($server_id, $email, $inbound_id);
            else resetClientTraffic($server_id, $email);
        }
        $settings['clients'][$client_key]['totalGB'] = $volume;
        if(!isset($settings['clients'][$client_key]['subId']) && ($serverType == "sanaei" || $serverType == "alireza")) $settings['clients'][$client_key]['subId'] = RandomString(16);
        if(!isset($settings['clients'][$client_key]['enable']) && ($serverType == "sanaei" || $serverType == "alireza")) $settings['clients'][$client_key]['enable'] = true;
    }
    
    if($days != 0){
        $expiryTime = $settings['clients'][$client_key]['expiryTime'];
        $now_microdate = floor(microtime(true) * 1000);
        $extend_date = (864000 * $days * 100);
        if($editType == "renew") $expire_microdate = $now_microdate + $extend_date;
        else $expire_microdate = ($now_microdate > $expiryTime) ? $now_microdate + $extend_date : $expiryTime + $extend_date;
        $settings['clients'][$client_key]['expiryTime'] = $expire_microdate;
        if(!isset($settings['clients'][$client_key]['subId']) && ($serverType == "sanaei" || $serverType == "alireza")) $settings['clients'][$client_key]['subId'] = RandomString(16);
        if(!isset($settings['clients'][$client_key]['enable']) && ($serverType == "sanaei" || $serverType == "alireza")) $settings['clients'][$client_key]['enable'] = true;
    }
    $editedClient = $settings['clients'][$client_key];
    $settings['clients'] = array_values($settings['clients']);
    $settings = json_encode($settings);
    $dataArr = array('up' => $row->up,'down' => $row->down,'total' => $row->total,'remark' => $row->remark,'enable' => 'true',
        'expiryTime' => $row->expiryTime, 'listen' => '','port' => $row->port,'protocol' => $row->protocol,'settings' => $settings,
        'streamSettings' => $row->streamSettings, 'sniffing' => $row->sniffing);

    $serverName = $server_info['username'];
    $serverPass = $server_info['password'];
    
    $loginUrl = $panel_url . '/login';
    
    $postFields = array(
        "username" => $serverName,
        "password" => $serverPass
        );
         
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $loginUrl);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_POST, 1);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($curl, CURLOPT_TIMEOUT, 3); 
    curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($postFields));
    curl_setopt($curl, CURLOPT_HEADER, 1);
    $response = curl_exec($curl);
    
    $header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $header = substr($response, 0, $header_size);
    $body = substr($response, $header_size);
    preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $header, $matches);
    $cookies = array();
    foreach($matches[1] as $item) {
        parse_str($item, $cookie);
        $cookies = array_merge($cookies, $cookie);
    }

    $loginResponse = json_decode($body,true);
    if(!$loginResponse['success']){
        curl_close($curl);
        return $loginResponse; 
    } 

    if($serverType == "sanaei" || $serverType == "alireza"){
        
        $newSetting = array();
        $newSetting['clients'][] = $editedClient;
        $newSetting = json_encode($newSetting);

        $dataArr = array(
            "id"=>$inbound_id,
            "settings" => $newSetting
            );
            
        if($serverType == "sanaei") $url = "$panel_url/panel/inbound/updateClient/" . rawurlencode($uuid);
        else $url = "$panel_url/xui/inbound/updateClient/" . rawurlencode($uuid);
        
        curl_setopt_array($curl, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $dataArr,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HEADER => false,
            CURLOPT_HTTPHEADER => array(
                'User-Agent:  Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:108.0) Gecko/20100101 Firefox/108.0',
                'Accept:  application/json, text/plain, */*',
                'Accept-Language:  en-US,en;q=0.5',
                'Accept-Encoding:  gzip, deflate',
                'X-Requested-With:  XMLHttpRequest',
                'Cookie: ' . array_keys($cookies)[0] . "=" . $cookies[array_keys($cookies)[0]]
            )
        ));
    }else{
        curl_setopt_array($curl, array(
            CURLOPT_URL => "$panel_url/xui/inbound/update/$inbound_id",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $dataArr,
            CURLOPT_HEADER => false,
            CURLOPT_HTTPHEADER => array(
                'User-Agent:  Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:108.0) Gecko/20100101 Firefox/108.0',
                'Accept:  application/json, text/plain, */*',
                'Accept-Language:  en-US,en;q=0.5',
                'Accept-Encoding:  gzip, deflate',
                'X-Requested-With:  XMLHttpRequest',
                'Cookie: ' . array_keys($cookies)[0] . "=" . $cookies[array_keys($cookies)[0]]
            )
        ));
    }

    $response = curl_exec($curl);
    curl_close($curl);
    resetIpLog($server_id, $email);
    return $response = json_decode($response);

}
function deleteInbound($server_id, $uuid, $delete = 0){
    global $connection;
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $panel_url = $server_info['panel_url'];
    $serverType = $server_info['type'];

    $response = getJson($server_id);
    if(!$response) return null;
    $response = $response->obj;
    foreach($response as $row){
        $clients = json_decode($row->settings)->clients;
        if($clients[0]->id == $uuid || $clients[0]->password == $uuid) {
            $inbound_id = $row->id;
            $protocol = $row->protocol;
            $uniqid = ($protocol == 'trojan') ? json_decode($row->settings)->clients[0]->password : json_decode($row->settings)->clients[0]->id;
            $netType = json_decode($row->streamSettings)->network;
            $oldData = [
                'total' => $row->total,
                'up' => $row->up,
                'down' => $row->down,
                'volume' => ((int)$row->total - (int)$row->up - (int)$row->down),
                'port' => $row->port,
                'protocol' => $protocol,
                'expiryTime' => $row->expiryTime,
                'uniqid' => $uniqid,
                'netType' => $netType,
                'security' => json_decode($row->streamSettings)->security,
            ];
            break;
        }
    }
    if($delete == 1){
        $serverName = $server_info['username'];
        $serverPass = $server_info['password'];
        
        $loginUrl = $panel_url . '/login';
        
        $postFields = array(
            "username" => $serverName,
            "password" => $serverPass
            );
            
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $loginUrl);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_POST, 1);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($curl, CURLOPT_TIMEOUT, 3); 
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($postFields));
        curl_setopt($curl, CURLOPT_HEADER, 1);
        $response = curl_exec($curl);

        $header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        $header = substr($response, 0, $header_size);
        $body = substr($response, $header_size);
        preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $header, $matches);
        $cookies = array();
        foreach($matches[1] as $item) {
            parse_str($item, $cookie);
            $cookies = array_merge($cookies, $cookie);
        }

        $loginResponse = json_decode($body,true);
        if(!$loginResponse['success']){
            curl_close($curl);
            return $loginResponse;
        }
        
        if($serverType == "sanaei") $url = "$panel_url/panel/inbound/del/$inbound_id";
        else $url = "$panel_url/xui/inbound/del/$inbound_id";
       
        curl_setopt_array($curl, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_HEADER => false,
            CURLOPT_HTTPHEADER => array(
                'User-Agent:  Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:108.0) Gecko/20100101 Firefox/108.0',
                'Accept:  application/json, text/plain, */*',
                'Accept-Language:  en-US,en;q=0.5',
                'Accept-Encoding:  gzip, deflate',
                'X-Requested-With:  XMLHttpRequest',
                'Cookie: ' . array_keys($cookies)[0] . "=" . $cookies[array_keys($cookies)[0]]
            )
        ));
        $response = curl_exec($curl);
        curl_close($curl);
    }
    return $oldData;
}
function resetIpLog($server_id, $remark){
    global $connection;
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $panel_url = $server_info['panel_url'];
    $serverType = $server_info['type'];


    $serverName = $server_info['username'];
    $serverPass = $server_info['password'];
    
    $loginUrl = $panel_url . '/login';
    
    $postFields = array(
        "username" => $serverName,
        "password" => $serverPass
        );
        
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $loginUrl);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_POST, 1);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($curl, CURLOPT_TIMEOUT, 3); 
    curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($postFields));
    curl_setopt($curl, CURLOPT_HEADER, 1);
    $response = curl_exec($curl);

    $header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $header = substr($response, 0, $header_size);
    $body = substr($response, $header_size);
    preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $header, $matches);
    $cookies = array();
    foreach($matches[1] as $item) {
        parse_str($item, $cookie);
        $cookies = array_merge($cookies, $cookie);
    }

    $loginResponse = json_decode($body,true);
    if(!$loginResponse['success']){
        curl_close($curl);
        return $loginResponse;
    }
    
    if($serverType == "sanaei") $url = $panel_url. "/panel/inbound/clearClientIps/" . urlencode($remark);
    else $url = $panel_url. "/xui/inbound/clearClientIps/" . urlencode($remark);

    curl_setopt_array($curl, array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_HEADER => false,
        CURLOPT_HTTPHEADER => array(
            'User-Agent:  Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:108.0) Gecko/20100101 Firefox/108.0',
            'Accept:  application/json, text/plain, */*',
            'Accept-Language:  en-US,en;q=0.5',
            'Accept-Encoding:  gzip, deflate',
            'X-Requested-With:  XMLHttpRequest',
            'Cookie: ' . array_keys($cookies)[0] . "=" . $cookies[array_keys($cookies)[0]]
        )
    ));

    $response = curl_exec($curl);
    curl_close($curl);
    return $response = json_decode($response);
}
function resetClientTraffic($server_id, $remark, $inboundId = null){
    global $connection;
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $panel_url = $server_info['panel_url'];
    $serverType = $server_info['type'];


    $serverName = $server_info['username'];
    $serverPass = $server_info['password'];
    
    $loginUrl = $panel_url . '/login';
    
    $postFields = array(
        "username" => $serverName,
        "password" => $serverPass
        );
        
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $loginUrl);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_POST, 1);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($curl, CURLOPT_TIMEOUT, 3); 
    curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($postFields));
    curl_setopt($curl, CURLOPT_HEADER, 1);
    $response = curl_exec($curl);

    $header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $header = substr($response, 0, $header_size);
    $body = substr($response, $header_size);
    preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $header, $matches);
    $cookies = array();
    foreach($matches[1] as $item) {
        parse_str($item, $cookie);
        $cookies = array_merge($cookies, $cookie);
    }

    $loginResponse = json_decode($body,true);
    if(!$loginResponse['success']){
        curl_close($curl);
        return $loginResponse;
    }
    if($serverType == "sanaei") $url = "$panel_url/panel/inbound/$inboundId/resetClientTraffic/" . rawurlencode($remark);
    elseif($inboundId == null) $url = "$panel_url/xui/inbound/resetClientTraffic/" . rawurlencode($remark);
    else $url = "$panel_url/xui/inbound/$inboundId/resetClientTraffic/" . rawurlencode($remark);
    curl_setopt_array($curl, array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_HEADER => false,
        CURLOPT_HTTPHEADER => array(
            'User-Agent:  Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:108.0) Gecko/20100101 Firefox/108.0',
            'Accept:  application/json, text/plain, */*',
            'Accept-Language:  en-US,en;q=0.5',
            'Accept-Encoding:  gzip, deflate',
            'X-Requested-With:  XMLHttpRequest',
            'Cookie: ' . array_keys($cookies)[0] . "=" . $cookies[array_keys($cookies)[0]]
        )
    ));

    $response = curl_exec($curl);
    curl_close($curl);
    return $response = json_decode($response);
}
function addInboundAccount($server_id, $client_id, $inbound_id, $expiryTime, $remark, $volume, $limitip = 1, $newarr = '', $planId = null){
    global $connection;
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $panel_url = $server_info['panel_url'];
    $serverType = $server_info['type'];
    $reality = $server_info['reality'];
    $volume = ($volume == 0) ? 0 : floor($volume * 1073741824);

    $response = getJson($server_id);
    if(!$response) return null;
    $response = $response->obj;
    foreach($response as $row){
        if($row->id == $inbound_id) {
            $iid = $row->id;
            $protocol = $row->protocol;
            break;
        }
    }
    if(!intval($iid)) return "inbound not Found";

    $settings = json_decode($row->settings, true);
    $id_label = $protocol == 'trojan' ? 'password' : 'id';
    if($newarr == ''){
		if($serverType == "sanaei" || $serverType == "alireza"){
		    if($reality == "true"){
                $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
                $stmt->bind_param("i", $planId);
                $stmt->execute();
                $file_detail = $stmt->get_result()->fetch_assoc();
                $stmt->close();
            
                $flow = isset($file_detail['flow']) && $file_detail['flow'] != "None" ? $file_detail['flow'] : "";
                
                $newClient = [
                    "$id_label" => $client_id,
                    "enable" => true,
                    "email" => $remark,
                    "limitIp" => $limitip,
                    "flow" => $flow,
                    "totalGB" => $volume,
                    "expiryTime" => $expiryTime,
                    "subId" => RandomString(16)
                ];
		    }else{
                $newClient = [
                    "$id_label" => $client_id,
                    "enable" => true,
                    "email" => $remark,
                    "limitIp" => $limitip,
                    "totalGB" => $volume,
                    "expiryTime" => $expiryTime,
                    "subId" => RandomString(16)
                ];
		    }
    	}else{
            $newClient = [
                "$id_label" => $client_id,
                "flow" => "",
                "email" => $remark,
                "limitIp" => $limitip,
                "totalGB" => $volume,
                "expiryTime" => $expiryTime
            ];
		}
        $settings['clients'][] = $newClient;
    }elseif(is_array($newarr)) $settings['clients'][] = $newarr;

    $settings['clients'] = array_values($settings['clients']);
    $settings = json_encode($settings);

    $dataArr = array('up' => $row->up,'down' => $row->down,'total' => $row->total,'remark' => $row->remark,'enable' => 'true',
        'expiryTime' => $row->expiryTime, 'listen' => '','port' => $row->port,'protocol' => $row->protocol,'settings' => $settings,
        'streamSettings' => $row->streamSettings, 'sniffing' => $row->sniffing);

    $serverName = $server_info['username'];
    $serverPass = $server_info['password'];
    
    $loginUrl = $panel_url . '/login';
    
    $postFields = array(
        "username" => $serverName,
        "password" => $serverPass
        );
        
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $loginUrl);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_POST, 1);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($curl, CURLOPT_TIMEOUT, 3); 
    curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($postFields));
    curl_setopt($curl, CURLOPT_HEADER, 1);
    $response = curl_exec($curl);

    $header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $header = substr($response, 0, $header_size);
    $body = substr($response, $header_size);
    preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $header, $matches);
    $cookies = array();
    foreach($matches[1] as $item) {
        parse_str($item, $cookie);
        $cookies = array_merge($cookies, $cookie);
    }

    $loginResponse = json_decode($body,true);
    if(!$loginResponse['success']){
        curl_close($curl);
        return $loginResponse;
    }
    
    if($serverType == "sanaei" || $serverType == "alireza"){
        $newSetting = array();
        if($newarr == '')$newSetting['clients'][] = $newClient;
        elseif(is_array($newarr)) $newSetting['clients'][] = $newarr;
        
        $newSetting = json_encode($newSetting);
        $dataArr = array(
            "id"=>$inbound_id,
            "settings" => $newSetting
            );
            
        if($serverType == "sanaei") $url = "$panel_url/panel/inbound/addClient/";
        else $url = "$panel_url/xui/inbound/addClient/";

        curl_setopt_array($curl, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $dataArr,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HEADER => false,
            CURLOPT_HTTPHEADER => array(
                'User-Agent:  Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:108.0) Gecko/20100101 Firefox/108.0',
                'Accept:  application/json, text/plain, */*',
                'Accept-Language:  en-US,en;q=0.5',
                'Accept-Encoding:  gzip, deflate',
                'X-Requested-With:  XMLHttpRequest',
                'Cookie: ' . array_keys($cookies)[0] . "=" . $cookies[array_keys($cookies)[0]]
            )
        ));
    }else{
        curl_setopt_array($curl, array(
            CURLOPT_URL => "$panel_url/xui/inbound/update/$iid",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $dataArr,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HEADER => false,
            CURLOPT_HTTPHEADER => array(
                'User-Agent:  Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:108.0) Gecko/20100101 Firefox/108.0',
                'Accept:  application/json, text/plain, */*',
                'Accept-Language:  en-US,en;q=0.5',
                'Accept-Encoding:  gzip, deflate',
                'X-Requested-With:  XMLHttpRequest',
                'Cookie: ' . array_keys($cookies)[0] . "=" . $cookies[array_keys($cookies)[0]]
            )
        ));
    }

    $response = curl_exec($curl);
    curl_close($curl);
    return $response = json_decode($response);

}
function getNewHeaders($netType, $request_header, $response_header, $type){
    global $connection;
    $input = explode(':', $request_header);
    $key = $input[0];
    $value = $input[1];

    $input = explode(':', $response_header);
    $reskey = $input[0];
    $resvalue = $input[1];

    $headers = '';
    if( $netType == 'tcp'){
        if($type == 'none') {
            $headers = '{
              "type": "none"
            }';
        }else {
            $headers = '{
              "type": "http",
              "request": {
                "method": "GET",
                "path": [
                  "/"
                ],
                "headers": {
                   "'.$key.'": [
                     "'.$value.'"
                  ]
                }
              },
              "response": {
                "version": "1.1",
                "status": "200",
                "reason": "OK",
                "headers": {
                   "'.$reskey.'": [
                     "'.$resvalue.'"
                  ]
                }
              }
            }';
        }

    }elseif( $netType == 'ws'){
        if($type == 'none') {
            $headers = '{}';
        }else {
            $headers = '{
              "'.$key.'": "'.$value.'"
            }';
        }
    }
    return $headers;

}
function getConnectionLink($server_id, $uniqid, $protocol, $remark, $port, $netType, $inbound_id = 0, $rahgozar = false, $customPath = false, $customPort = 0, $customSni = null){
    global $connection;
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $panel_url = $server_info['panel_url'];
    $server_ip = $server_info['ip'];
    $sni = $server_info['sni'];
    $header_type = $server_info['header_type'];
    $request_header = $server_info['request_header'];
    $response_header = $server_info['response_header'];
    $serverType = $server_info['type'];
    preg_match("/^Host:(.*)/i",$request_header,$hostMatch);

    $panel_url = str_ireplace('http://','',$panel_url);
    $panel_url = str_ireplace('https://','',$panel_url);
    $panel_url = strtok($panel_url,":");
    if($server_ip == '') $server_ip = $panel_url;

    $response = getJson($server_id)->obj;
    foreach($response as $row){
        if($inbound_id == 0){
            $clients = json_decode($row->settings)->clients;
            if($clients[0]->id == $uniqid || $clients[0]->password == $uniqid) {
                if($serverType == "sanaei" || $serverType == "alireza"){
                    $settings = json_decode($row->settings,true);
                    $email = $settings['clients'][0]['email'];
                    // $remark = (!empty($row->remark)?($row->remark . "-"):"") . $email;
                    $remark = $row->remark;
                }
                $tlsStatus = json_decode($row->streamSettings)->security;
                $tlsSetting = json_decode($row->streamSettings)->tlsSettings;
                $xtlsSetting = json_decode($row->streamSettings)->xtlsSettings;
                $netType = json_decode($row->streamSettings)->network;
                if($netType == 'tcp') {
                    $header_type = json_decode($row->streamSettings)->tcpSettings->header->type;
                    $path = json_decode($row->streamSettings)->tcpSettings->header->request->path[0];
                    $host = json_decode($row->streamSettings)->tcpSettings->header->request->headers->Host[0];
                    
                    if($tlsStatus == "reality"){
                        $realitySettings = json_decode($row->streamSettings)->realitySettings;
                        $fp = $realitySettings->settings->fingerprint;
                        $spiderX = $realitySettings->settings->spiderX;
                        $pbk = $realitySettings->settings->publicKey;
                        $sni = $realitySettings->serverNames[0];
                        $flow = $settings['clients'][0]['flow'];
                        $sid = $realitySettings->shortIds[0];
                    }
                }
                if($netType == 'ws') {
                    $header_type = json_decode($row->streamSettings)->wsSettings->header->type;
                    $path = json_decode($row->streamSettings)->wsSettings->path;
                    $host = json_decode($row->streamSettings)->wsSettings->headers->Host;
                }
                if($header_type == 'http' && empty($host)){
                    $request_header = explode(':', $request_header);
                    $host = $request_header[1];
                }
                if($netType == 'grpc') {
                    if($tlsStatus == 'tls'){
                        $alpn = $tlsSetting->certificates->alpn;
						if(isset($tlsSetting->serverName)) $sni = $tlsSetting->serverName;
						if(isset($tlsSetting->settings->serverName)) $sni = $tlsSetting->settings->serverName;
                    } 
                    elseif($tlsStatus == "reality"){
                        $realitySettings = json_decode($row->streamSettings)->realitySettings;
                        $fp = $realitySettings->settings->fingerprint;
                        $spiderX = $realitySettings->settings->spiderX;
                        $pbk = $realitySettings->settings->publicKey;
                        $sni = $realitySettings->serverNames[0];
                        $flow = $settings['clients'][0]['flow'];
                        $sid = $realitySettings->shortIds[0];
                    }
                    $serviceName = json_decode($row->streamSettings)->grpcSettings->serviceName;
                    $grpcSecurity = json_decode($row->streamSettings)->security;
                }
                if($tlsStatus == 'tls'){
                    $serverName = $tlsSetting->serverName;
					if(isset($tlsSetting->serverName)) $sni = $tlsSetting->serverName;
                    if(isset($tlsSetting->settings->serverName)) $sni = $tlsSetting->settings->serverName;
                }
                if($tlsStatus == "xtls"){
                    $serverName = $xtlsSetting->serverName;
                    $alpn = $xtlsSetting->alpn;
					if(isset($xtlsSetting->serverName)) $sni = $xtlsSetting->serverName;
                    if(isset($xtlsSetting->settings->serverName)) $sni = $xtlsSetting->settings->serverName;
                }
                if($netType == 'kcp'){
                    $kcpSettings = json_decode($row->streamSettings)->kcpSettings;
                    $kcpType = $kcpSettings->header->type;
                    $kcpSeed = $kcpSettings->seed;
                }
                
                break;
            }
        }else{
            if($row->id == $inbound_id) {
                if($serverType == "sanaei" || $serverType == "alireza"){
                    $settings = json_decode($row->settings);
                    $clients = $settings->clients;
                    foreach($clients as $key => $client){
                        if($client->id == $uniqid || $client->password == $uniqid){
                            $flow = $client->flow;
                            break;
                        }
                    }
                    // $remark = (!empty($row->remark)?($row->remark . "-"):"") . $remark;
                    $remark = $remark;
                }
                
                $port = $row->port;
                $tlsStatus = json_decode($row->streamSettings)->security;
                $tlsSetting = json_decode($row->streamSettings)->tlsSettings;
                $xtlsSetting = json_decode($row->streamSettings)->xtlsSettings;
                $netType = json_decode($row->streamSettings)->network;
                if($netType == 'tcp') {
                    $header_type = json_decode($row->streamSettings)->tcpSettings->header->type;
                    $path = json_decode($row->streamSettings)->tcpSettings->header->request->path[0];
                    $host = json_decode($row->streamSettings)->tcpSettings->header->request->headers->Host[0];
                    
                    if($tlsStatus == "reality"){
                        $realitySettings = json_decode($row->streamSettings)->realitySettings;
                        $fp = $realitySettings->settings->fingerprint;
                        $spiderX = $realitySettings->settings->spiderX;
                        $pbk = $realitySettings->settings->publicKey;
                        $sni = $realitySettings->serverNames[0];
                        $sid = $realitySettings->shortIds[0];
                    }
                }elseif($netType == 'ws') {
                    $header_type = json_decode($row->streamSettings)->wsSettings->header->type;
                    $path = json_decode($row->streamSettings)->wsSettings->path;
                    $host = json_decode($row->streamSettings)->wsSettings->headers->Host;
                }elseif($netType == 'grpc') {
                    if($tlsStatus == 'tls'){
                        $alpn = $tlsSetting->alpn;
						if(isset($tlsSetting->serverName)) $sni = $tlsSetting->serverName;
                        if(isset($tlsSetting->settings->serverName)) $sni = $tlsSetting->settings->serverName;
                    }
                    elseif($tlsStatus == "reality"){
                        $realitySettings = json_decode($row->streamSettings)->realitySettings;
                        $fp = $realitySettings->settings->fingerprint;
                        $spiderX = $realitySettings->settings->spiderX;
                        $pbk = $realitySettings->settings->publicKey;
                        $sni = $realitySettings->serverNames[0];
                        $sid = $realitySettings->shortIds[0];
                    }
                    $grpcSecurity = json_decode($row->streamSettings)->security;
                    $serviceName = json_decode($row->streamSettings)->grpcSettings->serviceName;
                }elseif($netType == 'kcp'){
                    $kcpSettings = json_decode($row->streamSettings)->kcpSettings;
                    $kcpType = $kcpSettings->header->type;
                    $kcpSeed = $kcpSettings->seed;
                }
                if($tlsStatus == 'tls'){
                    $serverName = $tlsSetting->serverName;
					if(isset($tlsSetting->serverName)) $sni = $tlsSetting->serverName;
                    if(isset($tlsSetting->settings->serverName)) $sni = $tlsSetting->settings->serverName;
                }
                if($tlsStatus == "xtls"){
                    $serverName = $xtlsSetting->serverName;
                    $alpn = $xtlsSetting->alpn;
					if(isset($xtlsSetting->serverName)) $sni = $xtlsSetting->serverName;
                    if(isset($xtlsSetting->settings->serverName)) $sni = $xtlsSetting->settings->serverName;
                }

                break;
            }
        }


    }
    $protocol = strtolower($protocol);
    $serverIp = explode("\n",$server_ip);
    $outputLink = array();
    foreach($serverIp as $server_ip){
        $server_ip = str_replace("\r","",($server_ip));
        if($inbound_id == 0) {
            if($protocol == 'vless'){
                if($rahgozar == true){
                    if(empty($host) && isset($hostMatch[1])) $host = $hostMatch[1];
                    
                    if(!empty($host)){
                        $parseAdd = parse_url($host);
                        $parseAdd = $parseAdd['host']??$parseAdd['path'];
                        $explodeAdd = explode(".", $parseAdd);
                        $subDomain = RandomString(4,"domain");
                        if($customSni != null) $sni = $customSni;
                        else{
                            if(count($explodeAdd) >= 3) $sni = $uniqid . "." . $explodeAdd[1] . "." . $explodeAdd[2];
                            else $sni = $uniqid . "." . $host;
                        }
                    }
                }
                $psting = '';
                if(($header_type == 'http' && $rahgozar != true && $netType != "grpc") || ($netType == "ws" && !empty($host) && $rahgozar != true)) $psting .= "&path=/&host=$host";;
                if($netType == 'tcp' and $header_type == 'http') $psting .= '&headerType=http';
                if(strlen($sni) > 1 && $tlsStatus != "reality") $psting .= "&sni=$sni";
                if(strlen($serverName)>1 && $tlsStatus=="xtls") $server_ip = $serverName;
                if($tlsStatus == "xtls" && $netType == "tcp") $psting .= "&flow=xtls-rprx-direct";
                if($tlsStatus=="reality") $psting .= "&fp=$fp&pbk=$pbk&sni=$sni" . ($flow != ""?"&flow=$flow":"") . "&sid=$sid&spx=$spiderX";
                if($rahgozar == true) $psting .= "&path=" . rawurlencode($path . ($customPath == true?"?ed=2048":"")) . "&encryption=none&host=$host";
                $outputlink = "$protocol://$uniqid@$server_ip:" . ($rahgozar == true?($customPort!="0"?$customPort:"443"):$port) . "?type=$netType&security=" . ($rahgozar==true?"tls":$tlsStatus) . "{$psting}#$remark";
                if($netType == 'grpc' && $tlsStatus != "reality"){
                    if($tlsStatus == 'tls'){
                        $outputlink = "$protocol://$uniqid@$server_ip:$port?type=$netType&security=$tlsStatus&serviceName=$serviceName&sni=$sni#$remark";
                    }else{
                        $outputlink = "$protocol://$uniqid@$server_ip:$port?type=$netType&security=$tlsStatus&serviceName=$serviceName#$remark";
                    }
    
                }
            }
    
            if($protocol == 'trojan'){
                $psting = '';
                if($header_type == 'http') $psting .= "&path=/&host=$host";
                if($netType == 'tcp' and $header_type == 'http') $psting .= '&headerType=http';
                if(strlen($sni) > 1) $psting .= "&sni=$sni";
                $outputlink = "$protocol://$uniqid@$server_ip:$port?type=$netType&security=$tlsStatus{$psting}#$remark";
                
                if($netType == 'grpc'){
                    if($tlsStatus == 'tls'){
                        $outputlink = "$protocol://$uniqid@$server_ip:$port?type=$netType&security=$tlsStatus&serviceName=$serviceName&sni=$sni#$remark";
                    }else{
                        $outputlink = "$protocol://$uniqid@$server_ip:$port?type=$netType&security=$tlsStatus&serviceName=$serviceName#$remark";
                    }
    
                }
            }elseif($protocol == 'vmess'){
                $vmessArr = [
                    "v"=> "2",
                    "ps"=> $remark,
                    "add"=> $server_ip,
                    "port"=> $rahgozar == true?($customPort!=0?$customPort:443):$port,
                    "id"=> $uniqid,
                    "aid"=> 0,
                    "net"=> $netType,
                    "type"=> $kcpType ? $kcpType : "none",
                    "host"=> ($rahgozar == true && empty($host))? $server_ip:(is_null($host) ? '' : $host),
                    "path"=> ($rahgozar == true)?($path . ($customPath == true?"?ed=2048":"")):((is_null($path) and $path != '') ? '/' : (is_null($path) ? '' : $path)),
                    "tls"=> $rahgozar == true?"tls":((is_null($tlsStatus)) ? 'none' : $tlsStatus)
                ];
                
                if($rahgozar == true){
                    if(empty($host) && isset($hostMatch[1])) $host = $hostMatch[1];
                    
                    if(!empty($host)){
                        $parseAdd = parse_url($host);
                        $parseAdd = $parseAdd['host']??$parseAdd['path'];
                        $explodeAdd = explode(".", $parseAdd);
                        $subDomain = RandomString(4,"domain");
                        if($customSni != null) $sni = $customSni;
                        else{
                            if(count($explodeAdd) >= 3) $sni = $uniqid . "." . $explodeAdd[1] . "." . $explodeAdd[2];
                            else $sni = $uniqid . "." . $host;
                        }
    
                        $vmessArr['alpn'] = 'http/1.1';
                    }
                }
                if($header_type == 'http' && $rahgozar != true){
                    $vmessArr['path'] = "/";
                    $vmessArr['type'] = $header_type;
                    $vmessArr['host'] = $host;
                }
                if($netType == 'grpc'){
                    if(!is_null($alpn) and json_encode($alpn) != '[]' and $alpn != '') $vmessArr['alpn'] = $alpn;
                    if(strlen($serviceName) > 1) $vmessArr['path'] = $serviceName;
    				$vmessArr['type'] = $grpcSecurity;
                    $vmessArr['scy'] = 'auto';
                }
                if($netType == 'kcp'){
                    $vmessArr['path'] = $kcpSeed ? $kcpSeed : $vmessArr['path'];
    	        }
                if(strlen($sni) > 1) $vmessArr['sni'] = $sni;
                $urldata = base64_encode(json_encode($vmessArr,JSON_UNESCAPED_SLASHES,JSON_PRETTY_PRINT));
                $outputlink = "vmess://$urldata";
            }
        }else { 
            if($protocol == 'vless'){
                if($rahgozar == true){
                    if(empty($host) && isset($hostMatch[1])) $host = $hostMatch[1];
                    
                    if(!empty($host)){
                        $parseAdd = parse_url($host);
                        $parseAdd = $parseAdd['host']??$parseAdd['path'];
                        $explodeAdd = explode(".", $parseAdd);
                        $subDomain = RandomString(4,"domain");
                        if($customSni != null) $sni = $customSni;
                        else{
                            if(count($explodeAdd) >= 3) $sni = $uniqid . "." . $explodeAdd[1] . "." . $explodeAdd[2];
                            else $sni = $uniqid . "." .$host;
                        }
                    }
                }
                
                if(strlen($sni) > 1 && $tlsStatus != "reality") $psting = "&sni=$sni"; else $psting = '';
                if($netType == 'tcp'){
                    if($netType == 'tcp' and $header_type == 'http') $psting .= '&headerType=http';
                    if($tlsStatus=="xtls") $psting .= "&flow=xtls-rprx-direct";
                    if($tlsStatus=="reality") $psting .= "&fp=$fp&pbk=$pbk&sni=$sni" . ($flow != ""?"&flow=$flow":"") . "&sid=$sid&spx=$spiderX";
                    if($header_type == "http") $psting .= "&path=/&host=$host";
                    $outputlink = "$protocol://$uniqid@$server_ip:$port?type=$netType&security=$tlsStatus{$psting}#$remark";
                }elseif($netType == 'ws'){
                    if($rahgozar == true)$outputlink = "$protocol://$uniqid@$server_ip:" . ($customPort!=0?$customPort:"443") . "?type=$netType&security=tls&path=" . rawurlencode($path . ($customPath == true?"?ed=2048":"")) . "&encryption=none&host=$host{$psting}#$remark";
                    else $outputlink = "$protocol://$uniqid@$server_ip:$port?type=$netType&security=$tlsStatus&path=/&host=$host{$psting}#$remark";
                }
                elseif($netType == 'kcp')
                    $outputlink = "$protocol://$uniqid@$server_ip:$port?type=$netType&security=$tlsStatus&headerType=$kcpType&seed=$kcpSeed#$remark";
                elseif($netType == 'grpc'){
                    if($tlsStatus == 'tls'){
                        $outputlink = "$protocol://$uniqid@$server_ip:$port?type=$netType&security=$tlsStatus&serviceName=$serviceName&sni=$sni#$remark";
                    }
                    elseif($tlsStatus=="reality"){
                        $outputlink = "$protocol://$uniqid@$server_ip:$port?type=$netType&security=$tlsStatus&serviceName=$serviceName&fp=$fp&pbk=$pbk&sni=$sni" . ($flow != ""?"&flow=$flow":"") . "&sid=$sid&spx=$spiderX#$remark";
                    }
                    else{
                        $outputlink = "$protocol://$uniqid@$server_ip:$port?type=$netType&security=$tlsStatus&serviceName=$serviceName#$remark";
                    }
                }
            }elseif($protocol == 'trojan'){                
                $psting = '';
                if($header_type == 'http') $psting .= "&path=/&host=$host";
                if($netType == 'tcp' and $header_type == 'http') $psting .= '&headerType=http';
                if(strlen($sni) > 1) $psting .= "&sni=$sni";
                $outputlink = "$protocol://$uniqid@$server_ip:$port?type=$netType&security=$tlsStatus{$psting}#$remark";
                
                if($netType == 'grpc'){
                    if($tlsStatus == 'tls'){
                        $outputlink = "$protocol://$uniqid@$server_ip:$port?type=$netType&security=$tlsStatus&serviceName=$serviceName&sni=$sni#$remark";
                    }else{
                        $outputlink = "$protocol://$uniqid@$server_ip:$port?type=$netType&security=$tlsStatus&serviceName=$serviceName#$remark";
                    }
    
                }
            }elseif($protocol == 'vmess'){
                $vmessArr = [
                    "v"=> "2",
                    "ps"=> $remark,
                    "add"=> $server_ip,
                    "port"=> $rahgozar == true?($customPort!=0?$customPort:443):$port,
                    "id"=> $uniqid,
                    "aid"=> 0,
                    "net"=> $netType,
                    "type"=> ($header_type) ? $header_type : ($kcpType ? $kcpType : "none"),
                    "host"=> ($rahgozar == true && empty($host))?$server_ip:(is_null($host) ? '' : $host),
                    "path"=> ($rahgozar == true)?($path . ($customPath == true?"?ed=2048":"")) :((is_null($path) and $path != '') ? '/' : (is_null($path) ? '' : $path)),
                    "tls"=> $rahgozar == true?"tls":((is_null($tlsStatus)) ? 'none' : $tlsStatus)
                ];
                if($rahgozar == true){
                    if(empty($host) && isset($hostMatch[1])) $host = $hostMatch[1];
                    
                    if(!empty($host)){
                        $subDomain = RandomString(4, "domain");
                        $parseAdd = parse_url($host);
                        $parseAdd = $parseAdd['host']??$parseAdd['path'];
                        $explodeAdd = explode(".", $parseAdd);
                        if($customSni != null) $sni = $customSni;
                        else{
                            if(count($explodeAdd) >= 3) $sni = $uniqid . "." . $explodeAdd[1] . "." .$explodeAdd[2];
                            else $sni = $uniqid . "." . $host;
                        }
                        
                        $vmessArr['alpn'] = 'http/1.1';
                    }
                }
                if($netType == 'grpc'){
                    if(!is_null($alpn) and json_encode($alpn) != '[]' and $alpn != '') $vmessArr['alpn'] = $alpn;
                    if(strlen($serviceName) > 1) $vmessArr['path'] = $serviceName;
                    $vmessArr['type'] = $grpcSecurity;
                    $vmessArr['scy'] = 'auto';
                }
                if($netType == 'kcp'){
                    $vmessArr['path'] = $kcpSeed ? $kcpSeed : $vmessArr['path'];
    	        }
    
                if(strlen($sni) > 1) $vmessArr['sni'] = $sni;
                $urldata = base64_encode(json_encode($vmessArr,JSON_UNESCAPED_SLASHES,JSON_PRETTY_PRINT));
                $outputlink = "vmess://$urldata";
            }
        }
        $outputLink[] = $outputlink;
    }

    return $outputLink;
}
function updateConfig($server_id, $inboundId, $protocol, $netType = 'tcp', $security = 'none', $rahgozar = false){
    global $connection;
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $panel_url = $server_info['panel_url'];
    $security = $server_info['security'];
    $tlsSettings = $server_info['tlsSettings'];
    $header_type = $server_info['header_type'];
    $request_header = $server_info['request_header'];
    $response_header = $server_info['response_header'];
    $serverType = $server_info['type'];
    $xtlsTitle = ($serverType == "sanaei" || $serverType == "alireza")?"XTLSSettings":"xtlsSettings";
    $sni = $server_info['sni'];
    if(!empty($sni) && ($serverType == "sanaei" || $serverType == "alireza")){
        $tlsSettings = json_decode($tlsSettings,true);
        $tlsSettings['serverName'] = $sni;
        $tlsSettings = json_encode($tlsSettings,488|JSON_UNESCAPED_UNICODE);
    }
    
    $response = getJson($server_id);
    if(!$response) return null;
    $response = $response->obj;
    foreach($response as $row){
        if($row->id == $inboundId) {
            $iid = $row->id;
            $remark = $row->remark;
            $streamSettings = $row->streamSettings;
            $settings = $row->settings;
            break;
        }
    }
    if(!intval($iid)) return;
    $headers = getNewHeaders($netType, $request_header, $response_header, $header_type);
    $headers = empty($headers)?"{}":$headers;

    if($protocol == 'trojan'){
        if($security == 'none'){
            $tcpSettings = '{
        	  "network": "tcp",
        	  "security": "'.$security.'",
        	  "tlsSettings": '.$tlsSettings.',
        	  "tcpSettings": {
                "header": '.$headers.'
              }
        	}';
                $wsSettings = '{
              "network": "ws",
              "security": "'.$security.'",
        	  "tlsSettings": '.$tlsSettings.',
              "wsSettings": {
                "path": "/",
                "headers": '.$headers.'
              }
            }';

        }elseif($security == 'xtls' && $serverType != "sanaei" && $serverType != "alireza") {
            
            $tcpSettings = '{
        	  "network": "tcp",
        	  "security": "'.$security.'",
        	  "' . $xtlsTitle . '": '.$tlsSettings.',
        	  "tcpSettings": {
                "header": '.$headers.'
              }
        	}';
                $wsSettings = '{
              "network": "ws",
              "security": "'.$security.'",
        	  "' . $xtlsTitle . '": '.$tlsSettings.',
              "wsSettings": {
                "path": "/",
                "headers": '.$headers.'
              }
            }';
        }
        else{
            $tcpSettings = '{
        	  "network": "tcp",
        	  "security": "'.$security.'",
        	  "tlsSettings": '.$tlsSettings.',
        	  "tcpSettings": {
                "header": '.$headers.'
              }
        	}';
            $wsSettings = '{
              "network": "ws",
              "security": "'.$security.'",
        	  "tlsSettings": '.$tlsSettings.',
              "wsSettings": {
                "path": "/",
                "headers": '.$headers.'
              }
            }';
        }
        
        
                $streamSettings = ($netType == 'tcp') ? $tcpSettings : $wsSettings;
		if($netType == 'grpc'){
		    $keyFileInfo = json_decode($tlsSettings,true);
		    $certificateFile = "/root/cert.crt";
		    $keyFile = '/root/private.key';
		    
		    if(isset($keyFileInfo['certificates'])){
		        $certificateFile = $keyFileInfo['certificates'][0]['certificateFile'];
		        $keyFile = $keyFileInfo['certificates'][0]['keyFile'];
		    }
			if($security == 'tls') {
				$streamSettings = '{
  "network": "grpc",
  "security": "tls",
  "tlsSettings": {
    "serverName": "' .
    (!empty($sni) && ($serverType == "sanaei" || $serverType == "alireza") ?  $sni: parse_url($panel_url, PHP_URL_HOST))
     . '",
    "certificates": [
      {
        "certificateFile": "' . $certificateFile . '",
        "keyFile": "' . $keyFile . '"
      }
    ],
    "alpn": []'
    .'
  },
  "grpcSettings": {
    "serviceName": ""
  }
}';
		    }else{
			$streamSettings = '{
  "network": "grpc",
  "security": "none",
  "grpcSettings": {
    "serviceName": "' . parse_url($panel_url, PHP_URL_HOST) . '"
  }
}';
		}
	    }


        $dataArr = array('up' => $row->up,'down' => $row->down,'total' => $row->total,'remark' => $remark,'enable' => 'true',
            'expiryTime' => $row->expiryTime,'listen' => '','port' => $row->port,'protocol' => $protocol,'settings' => $settings,'streamSettings' => $streamSettings,
            'sniffing' => $row->sniffing);
    }else{
        if($netType != "grpc"){
            if($rahgozar == true){
                $wsSettings = '{
                      "network": "ws",
                      "security": "none",
                      "wsSettings": {
                        "path": "/wss' . $row->port . '",
                        "headers": {}
                      }
                    }';
            }
            else{
                if($security == 'tls') {
                    $tcpSettings = '{
            	  "network": "tcp",
            	  "security": "'.$security.'",
            	  "tlsSettings": '.$tlsSettings.',
            	  "tcpSettings": {
                    "header": '.$headers.'
                  }
            	}';
                    $wsSettings = '{
                  "network": "ws",
                  "security": "'.$security.'",
            	  "tlsSettings": '.$tlsSettings.',
                  "wsSettings": {
                    "path": "/",
                    "headers": '.$headers.'
                  }
                }';
                }
                elseif($security == 'xtls' && $serverType != "sanaei" && $serverType != "alireza") {
                    $tcpSettings = '{
            	  "network": "tcp",
            	  "security": "'.$security.'",
            	  "' . $xtlsTitle . '": '.$tlsSettings.',
            	  "tcpSettings": {
                    "header": '.$headers.'
                  }
            	}';
                    $wsSettings = '{
                  "network": "ws",
                  "security": "'.$security.'",
            	  "' . $xtlsTitle . '": '.$tlsSettings.',
                  "wsSettings": {
                    "path": "/",
                    "headers": '.$headers.'
                  }
                }';
                }
                else {
                    $tcpSettings = '{
            	  "network": "tcp",
            	  "security": "none",
            	  "tcpSettings": {
            		"header": '.$headers.'
            	  }
            	}';
                    $wsSettings = '{
                  "network": "ws",
                  "security": "none",
                  "wsSettings": {
                    "path": "/",
                    "headers": {}
                  }
                }';
                }
            }
            $streamSettings = ($netType == 'tcp') ? $tcpSettings : $wsSettings;
        }

        $dataArr = array('up' => $row->up,'down' => $row->down,'total' => $row->total,'remark' => $remark,'enable' => 'true',
            'expiryTime' => $row->expiryTime,'listen' => '','port' => $row->port,'protocol' => $protocol,'settings' => $settings,
            'streamSettings' => $streamSettings,
            'sniffing' => $row->sniffing);
    }

    $serverName = $server_info['username'];
    $serverPass = $server_info['password'];
    
    $loginUrl = $panel_url . '/login';
    
    $postFields = array(
        "username" => $serverName,
        "password" => $serverPass
        );
        
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $loginUrl);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_POST, 1);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($curl, CURLOPT_TIMEOUT, 3); 
    curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($postFields));
    curl_setopt($curl, CURLOPT_HEADER, 1);
    $response = curl_exec($curl);

    $header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $header = substr($response, 0, $header_size);
    $body = substr($response, $header_size);
    preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $header, $matches);
    $cookies = array();
    foreach($matches[1] as $item) {
        parse_str($item, $cookie);
        $cookies = array_merge($cookies, $cookie);
    }

    $loginResponse = json_decode($body,true);
    if(!$loginResponse['success']){
        curl_close($curl);
        return $loginResponse;
    }
    
    if($serverType == "sanaei") $url = "$panel_url/panel/inbound/update/$iid";
    else $url = "$panel_url/xui/inbound/update/$iid";
    curl_setopt_array($curl, array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => $dataArr,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HEADER => false,
        CURLOPT_HTTPHEADER => array(
            'User-Agent:  Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:108.0) Gecko/20100101 Firefox/108.0',
            'Accept:  application/json, text/plain, */*',
            'Accept-Language:  en-US,en;q=0.5',
            'Accept-Encoding:  gzip, deflate',
            'X-Requested-With:  XMLHttpRequest',
            'Cookie: ' . array_keys($cookies)[0] . "=" . $cookies[array_keys($cookies)[0]]
        )
    ));

    $response = curl_exec($curl);
    curl_close($curl);
    return $response = json_decode($response);
}
function editInbound($server_id, $uniqid, $uuid, $protocol, $netType = 'tcp', $security = 'none', $rahgozar = false){
    global $connection;
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $panel_url = $server_info['panel_url'];
    $security = $server_info['security'];
    $tlsSettings = $server_info['tlsSettings'];
    $header_type = $server_info['header_type'];
    $request_header = $server_info['request_header'];
    $response_header = $server_info['response_header'];
    $serverType = $server_info['type'];
    $xtlsTitle = ($serverType == "sanaei" || $serverType == "alireza")?"XTLSSettings":"xtlsSettings";
    $sni = $server_info['sni'];
    if(!empty($sni) && ($serverType == "sanaei" || $serverType == "alireza")){
        $tlsSettings = json_decode($tlsSettings,true);
        $tlsSettings['serverName'] = $sni;
        $tlsSettings = json_encode($tlsSettings);
    }

    $response = getJson($server_id);
    if(!$response) return null;
    $response = $response->obj;
    foreach($response as $row){
        $clients = json_decode($row->settings)->clients;
        if($clients[0]->id == $uuid || $clients[0]->password == $uuid) {
            $iid = $row->id;
            $remark = $row->remark;
            $streamSettings = $row->streamSettings;
            $settings = $row->settings;
            break;
        }
    }
    if(!intval($iid)) return;

    $headers = getNewHeaders($netType, $request_header, $response_header, $header_type);
    $headers = empty($headers)?"{}":$headers;

    if($protocol == 'trojan'){
        if($security == 'none'){
            $tcpSettings = '{
        	  "network": "tcp",
        	  "security": "'.$security.'",
        	  "tlsSettings": '.$tlsSettings.',
        	  "tcpSettings": {
                "header": '.$headers.'
              }
        	}';
                $wsSettings = '{
              "network": "ws",
              "security": "'.$security.'",
        	  "tlsSettings": '.$tlsSettings.',
              "wsSettings": {
                "path": "/",
                "headers": '.$headers.'
              }
            }';

    	if($serverType == "sanaei" || $serverType == "alireza"){
            $settings = '{
        	  "clients": [
        		{
        		  "id": "'.$uniqid.'",
                  "enable": true,
        		  "email": "' . $remark. '",
                  "limitIp": 0,
                  "totalGB": 0,
                  "expiryTime": 0,
                  "subId": "' . RandomString(16) . '"
        		}
        	  ],
        	  "decryption": "none",
        	  "fallbacks": []
        	}';
    	}else{
            $settings = '{
        	  "clients": [
        		{
        		  "id": "'.$uniqid.'",
        		  "flow": "",
        		  "email": "' . $remark. '"
        		}
        	  ],
        	  "decryption": "none",
        	  "fallbacks": []
        	}';
    	}
        }elseif($security == 'xtls' && $serverType != "sanaei" && $serverType != "alireza") {
            
            $tcpSettings = '{
        	  "network": "tcp",
        	  "security": "'.$security.'",
        	  "' . $xtlsTitle . '": '.$tlsSettings.',
        	  "tcpSettings": {
                "header": '.$headers.'
              }
        	}';
                $wsSettings = '{
              "network": "ws",
              "security": "'.$security.'",
        	  "' . $xtlsTitle . '": '.$tlsSettings.',
              "wsSettings": {
                "path": "/",
                "headers": '.$headers.'
              }
            }';

                $settings = '{
              "clients": [
                {
                  "id": "'.$uniqid.'",
    			  "flow": "xtls-rprx-direct".
    			  "email": "' . $remark. '"
                }
              ],
              "decryption": "none",
        	  "fallbacks": []
            }';
        }
        else{
            $tcpSettings = '{
        	  "network": "tcp",
        	  "security": "'.$security.'",
        	  "tlsSettings": '.$tlsSettings.',
        	  "tcpSettings": {
                "header": '.$headers.'
              }
        	}';
            $wsSettings = '{
              "network": "ws",
              "security": "'.$security.'",
        	  "tlsSettings": '.$tlsSettings.',
              "wsSettings": {
                "path": "/",
                "headers": '.$headers.'
              }
            }';
		if($serverType == "sanaei" || $serverType == "alireza"){
            $settings = '{
		  "clients": [
			{
			  "password": "'.$uniqid.'",
              "enable": true,
			  "email": "' . $remark. '",
              "limitIp": 0,
              "totalGB": 0,
              "expiryTime": 0,
              "subId": "' . RandomString(16) . '"
			}
		  ],
		  "fallbacks": []
		}';
		}else{
            $settings = '{
		  "clients": [
			{
			  "password": "'.$uniqid.'",
			  "flow": "",
			  "email": "' . $remark. '"
			}
		  ],
		  "fallbacks": []
		}';
		}
        }
        
        
                $streamSettings = ($netType == 'tcp') ? $tcpSettings : $wsSettings;
		if($netType == 'grpc'){
		    $keyFileInfo = json_decode($tlsSettings,true);
		    $certificateFile = "/root/cert.crt";
		    $keyFile = '/root/private.key';
		    
		    if(isset($keyFileInfo['certificates'])){
		        $certificateFile = $keyFileInfo['certificates'][0]['certificateFile'];
		        $keyFile = $keyFileInfo['certificates'][0]['keyFile'];
		    }

			if($security == 'tls') {
				$streamSettings = '{
  "network": "grpc",
  "security": "tls",
  "tlsSettings": {
    "serverName": "' .
    (!empty($sni) && ($serverType == "sanaei" || $serverType == "alireza") ?  $sni: parse_url($panel_url, PHP_URL_HOST))
     . '",
    "certificates": [
      {
        "certificateFile": "' . $certificateFile . '",
        "keyFile": "' . $keyFile . '"
      }
    ],
    "alpn": []'
    .'
  },
  "grpcSettings": {
    "serviceName": ""
  }
}';
		    }else{
			$streamSettings = '{
  "network": "grpc",
  "security": "none",
  "grpcSettings": {
    "serviceName": "' . parse_url($panel_url, PHP_URL_HOST) . '"
  }
}';
		}
	    }


        $dataArr = array('up' => $row->up,'down' => $row->down,'total' => $row->total,'remark' => $remark,'enable' => 'true',
            'expiryTime' => $row->expiryTime,'listen' => '','port' => $row->port,'protocol' => $protocol,'settings' => $settings,'streamSettings' => $streamSettings,
            'sniffing' => $row->sniffing);
    }else{
        if($netType != "grpc"){
            if($rahgozar == true){
                $wsSettings = '{
                      "network": "ws",
                      "security": "none",
                      "wsSettings": {
                        "path": "/wss' . $row->port . '",
                        "headers": {}
                      }
                    }';
                if($serverType == "sanaei" || $serverType == "alireza"){
                    $settings = '{
            	  "clients": [
            		{
            		  "id": "'.$client_id.'",
                      "enable": true,
            		  "email": "' . $remark. '",
                      "limitIp": 0,
                      "totalGB": 0,
                      "expiryTime": 0
                      "subId": "' . RandomString(16) . '"
            		}
            	  ],
            	  "decryption": "none",
            	  "fallbacks": []
            	}';
                }else{
                $settings = '{
        	  "clients": [
        		{
        		  "id": "'.$client_id.'",
        		  "flow": "",
        		  "email": "' . $remark. '"
        		}
        	  ],
        	  "decryption": "none",
        	  "fallbacks": []
        	}';
            }
            }
            else{
                if($security == 'tls') {
                    $tcpSettings = '{
            	  "network": "tcp",
            	  "security": "'.$security.'",
            	  "tlsSettings": '.$tlsSettings.',
            	  "tcpSettings": {
                    "header": '.$headers.'
                  }
            	}';
                    $wsSettings = '{
                  "network": "ws",
                  "security": "'.$security.'",
            	  "tlsSettings": '.$tlsSettings.',
                  "wsSettings": {
                    "path": "/",
                    "headers": '.$headers.'
                  }
                }';
                if($serverType == "sanaei" || $serverType == "alireza"){
                    $settings = '{
                  "clients": [
                    {
                      "id": "'.$uniqid.'",
                      "enable": true,
                      "email": "' . $remark. '",
                      "limitIp": 0,
                      "totalGB": 0,
                      "expiryTime": 0,
                      "subId": "' . RandomString(16) . '"
                    }
                  ],
                  "decryption": "none",
            	  "fallbacks": []
                }';
                }else{
                    $settings = '{
                  "clients": [
                    {
                      "id": "'.$uniqid.'",
                      "alterId": 0
                    }
                  ],
                  "decryption": "none",
            	  "fallbacks": []
                }';
                }
                }
                elseif($security == 'xtls' && $serverType != "sanaei" && $serverType != "alireza") {
                    $tcpSettings = '{
            	  "network": "tcp",
            	  "security": "'.$security.'",
            	  "' . $xtlsTitle . '": '.$tlsSettings.',
            	  "tcpSettings": {
                    "header": '.$headers.'
                  }
            	}';
                    $wsSettings = '{
                  "network": "ws",
                  "security": "'.$security.'",
            	  "' . $xtlsTitle . '": '.$tlsSettings.',
                  "wsSettings": {
                    "path": "/",
                    "headers": '.$headers.'
                  }
                }';
                if($serverType == "sanaei" || $serverType == "alireza"){
                    $settings = '{
                  "clients": [
                    {
                      "id": "'.$uniqid.'",
                      "enable": true,
                      "email": "' . $remark. '",
                      "limitIp": 0,
                      "totalGB": 0,
                      "expiryTime": 0,
                      "subId": "' . RandomString(16) . '"
                    }
                  ],
                  "decryption": "none",
            	  "fallbacks": []
                }';
                }else{
                    $settings = '{
                  "clients": [
                    {
                      "id": "'.$uniqid.'",
        			  "flow": "",
        			  "email": "' . $remark. '"
                    }
                  ],
                  "decryption": "none",
            	  "fallbacks": []
                }';
                }
                }
                else {
                    $tcpSettings = '{
            	  "network": "tcp",
            	  "security": "none",
            	  "tcpSettings": {
            		"header": '.$headers.'
            	  }
            	}';
                    $wsSettings = '{
                  "network": "ws",
                  "security": "none",
                  "wsSettings": {
                    "path": "/",
                    "headers": {}
                  }
                }';
                if($serverType == "sanaei" || $serverType == "alireza"){
                    $settings = '{
            	  "clients": [
            		{
            		  "id": "'.$uniqid.'",
                      "enable": true,
            		  "email": "' . $remark. '",
                      "limitIp": 0,
                      "totalGB": 0,
                      "expiryTime": 0,
                      "subId": "' . RandomString(16) . '"
            		}
            	  ],
            	  "decryption": "none",
            	  "fallbacks": []
            	}';
                }else{
                    $settings = '{
            	  "clients": [
            		{
            		  "id": "'.$uniqid.'",
            		  "flow": "",
            		  "email": "' . $remark. '"
            		}
            	  ],
            	  "decryption": "none",
            	  "fallbacks": []
            	}';
                }
                }
            }
            $streamSettings = ($netType == 'tcp') ? $tcpSettings : $wsSettings;
        }


        $dataArr = array('up' => $row->up,'down' => $row->down,'total' => $row->total,'remark' => $remark,'enable' => 'true',
            'expiryTime' => $row->expiryTime,'listen' => '','port' => $row->port,'protocol' => $protocol,'settings' => $settings,
            'streamSettings' => $streamSettings,
            'sniffing' => $row->sniffing);
    }



    $serverName = $server_info['username'];
    $serverPass = $server_info['password'];
    
    $loginUrl = $panel_url . '/login';
    
    $postFields = array(
        "username" => $serverName,
        "password" => $serverPass
        );
        
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $loginUrl);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_POST, 1);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($curl, CURLOPT_TIMEOUT, 3); 
    curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($postFields));
    curl_setopt($curl, CURLOPT_HEADER, 1);
    $response = curl_exec($curl);

    $header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $header = substr($response, 0, $header_size);
    $body = substr($response, $header_size);
    preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $header, $matches);
    $cookies = array();
    foreach($matches[1] as $item) {
        parse_str($item, $cookie);
        $cookies = array_merge($cookies, $cookie);
    }

    $loginResponse = json_decode($body,true);
    if(!$loginResponse['success']){
        curl_close($curl);
        return $loginResponse;
    }
    
    if($serverType == "sanaei") $url = "$panel_url/panel/inbound/update/$iid";
    else $url = "$panel_url/xui/inbound/update/$iid";
    
    curl_setopt_array($curl, array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => $dataArr,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HEADER => false,
        CURLOPT_HTTPHEADER => array(
            'User-Agent:  Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:108.0) Gecko/20100101 Firefox/108.0',
            'Accept:  application/json, text/plain, */*',
            'Accept-Language:  en-US,en;q=0.5',
            'Accept-Encoding:  gzip, deflate',
            'X-Requested-With:  XMLHttpRequest',
            'Cookie: ' . array_keys($cookies)[0] . "=" . $cookies[array_keys($cookies)[0]]
        )
    ));

    $response = curl_exec($curl);
    curl_close($curl);
    return $response = json_decode($response);
}
function getMarzbanToken($server_id){
    global $connection;
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id = ?");
    $stmt->bind_param('i', $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $panel_url = $server_info['panel_url'];
    $username = $server_info['username'];
    $password = $server_info['password'];
    
    $loginUrl = $panel_url .'/api/admin/token';
    $postFields = array(
        'username' => $username,
        'password' => $password
    );
    
    
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $loginUrl);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_POST, 1);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($curl, CURLOPT_TIMEOUT, 3); 
    curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($postFields));
    curl_setopt($curl, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/x-www-form-urlencoded',
            'accept: application/json'
        ));
    $response = curl_exec($curl);
    if (curl_error($curl)) {
        return (object) ['success'=>false, 'detail'=>curl_error($curl)];
    }
    curl_close($curl);

    return json_decode($response);
}
function getMarzbanJson($server_id, $token = null){
    global $connection;
    
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $panel_url = $server_info['panel_url'];

    if($token == null) $token = getMarzbanToken($server_id);
    if(isset($token->detail)){return (object) ['success'=>false, 'msg'=>$token->detail];}
    $panel_url .= '/api/users';
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $panel_url);
    curl_setopt($curl, CURLOPT_HTTPGET, true);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HTTPHEADER, array(
        'Accept: application/json',
        'Authorization: Bearer ' . $token->access_token
    ));

    $response = json_decode(curl_exec($curl));
    curl_close($curl);

    return $response;
}
function getMarzbanUserInfo($server_id, $remark){
    global $connection;
    
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $panel_url = $server_info['panel_url'];

    $configInfo = array();
    $curl = curl_init();
    for($i = 0; $i <= 10; $i++){
        $info = getMarzbanUser($server_id, $remark);
		$subLink = "/sub/" . (explode("/sub/", $info->subscription_url)[1]);
		$info->subscription_url = $subLink;
        curl_setopt($curl, CURLOPT_URL, $panel_url . $info->subscription_url);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_TIMEOUT, 3); 
        $response = curl_exec($curl);
        if($response && !curl_error($curl)){
            $configInfo = $info;
            break;
        }
		if($i == 10) $configInfo = $info;
    }
    curl_close($curl);

    return (object) $configInfo;
}
function getMarzbanUser($server_id, $remark, $token = null){
    global $connection;
    
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $panel_url = $server_info['panel_url'];

    if($token == null) $token = getMarzbanToken($server_id);
    if(isset($token->detail)){return (object) ['success'=>false, 'msg'=>$token->detail];}
    
    $panel_url .= '/api/user/' . urlencode($remark);

    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $panel_url);
    curl_setopt($curl, CURLOPT_HTTPGET, true);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HTTPHEADER, array(
        'Accept: application/json',
        'Authorization: Bearer ' . $token->access_token
    ));

    $response = json_decode(curl_exec($curl));
    
    curl_close($curl);
    return $response;
}
function getMarzbanHosts($server_id){
    global $connection;
    
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $panel_url = $server_info['panel_url'];

    $token = getMarzbanToken($server_id);
    if(isset($token->detail)){return (object) ['success'=>false, 'msg'=>$token->detail];}

    $panel_url .= '/api/core/config';

    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $panel_url);
    curl_setopt($curl, CURLOPT_HTTPGET, true);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HTTPHEADER, array(
        'Accept: application/json',
        'Authorization: Bearer ' . $token->access_token
    ));

    $response = json_decode(curl_exec($curl));
    
    curl_close($curl);
    return $response;
}
function addMarzbanUser($server_id, $remark, $volume, $days, $plan_id){
    global $connection;
    
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $panel_url = $server_info['panel_url'];
    $serverName = $server_info['username'];
    $serverPass = $server_info['password'];
    $serverType = $server_info['type'];
    
    
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id` = ?");
    $stmt->bind_param('i', $plan_id);
    $stmt->execute();
    $planInfo = json_decode($stmt->get_result()->fetch_assoc()['custom_sni'],true);
    $stmt->close();

    $token = getMarzbanToken($server_id);
    if(isset($token->detail)){return (object) ['success'=>false, 'msg'=>$token->detail];}
	$dataproxy = array(
			"vmess" => new stdClass(),
			"vless" => new stdClass(),
			"trojan" => new stdClass(),
			"shadowsocks" => new stdClass()
    );
    $postFields = array(
        "inbounds" => $planInfo['inbounds'],
        "proxies" =>  $dataproxy,
        "expire" => time() + (86400 * $days),
        "data_limit" => $volume * 1073741824,
        "username" => urlencode($remark)
    );

    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $panel_url . "/api/user");
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HTTPHEADER, array(
        'Accept: application/json',
        'Authorization: Bearer ' .  $token->access_token,
        'Content-Type: application/json'
    ));
    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($postFields));

    $response = json_decode(curl_exec($curl));
    curl_close($curl);
    if(isset($response->detail) || !isset($response->links)){
		$detail = $response->detail;
        return (object) ['success'=>false, 'msg' => is_object($detail)?implode("-", (array) $detail):$detail];
    }
    $userInfo = getMarzbanUserInfo($server_id, $remark);

    return (object) [
        'success'=>true,
        'sub_link'=> $userInfo->subscription_url,
        'vray_links' => $response->links
        ];
}
function editMarzbanConfig($server_id,$info){
    global $connection;
    
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $panel_url = $server_info['panel_url'];
    $serverName = $server_info['username'];
    $serverPass = $server_info['password'];

    $token = getMarzbanToken($server_id);
    if(isset($token->detail)){return (object) ['success'=>false, 'msg'=>$token->error];}

    $remark = $info['remark'];
    $configInfo = getMarzbanUser($server_id, $remark, $token);
    
    
    $expireTime = $configInfo->expire;
    $volume = $configInfo->data_limit;
    $configState = $configInfo->status;
    
    if(isset($info['plus_day'])){
        if($expireTime < time()) $expireTime = time() + (86400 * $info['plus_day']);
        else $expireTime += (86400 * $info['plus_day']);
    }
    elseif(isset($info['days'])) $expireTime = time() + (86400 * $info['days']);
    
    if(isset($info['plus_volume'])) $volume += $info['plus_volume'] * 1073741824;
    elseif(isset($info['volume'])){
        $volume = $info['volume'] * 1073741824;
        $response = resetMarzbanTraffic($server_id, $remark, $token);
        
        if(!$response->success) return $response;
    }
    
    $postFields = array(
        "inbounds" => $configInfo->inbounds,
        "proxies" => $configInfo->proxies,
        "expire" => $expireTime,
        "data_limit" => $volume,
        "username" => urlencode($remark),
        "note" => $configInfo->note,
        "data_limit_reset_strategy"=> $configInfo->data_limit_reset_strategy,
        "status" => "active"
    );
    
    $panel_url .=  '/api/user/'. $remark;
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $panel_url);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'PUT');
    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($postFields));
    curl_setopt($curl, CURLOPT_HTTPHEADER, array(
        'Accept: application/json',
        'Authorization: Bearer ' .  $token->access_token,
        'Content-Type: application/json'
        ));
    
    $response = curl_exec($curl);
    curl_close($curl);
    if(isset($response->detail)){
		$detail = $response->detail;
        return (object) ['success'=>false, 'msg' => is_object($detail)?implode("-", (array) $detail):$detail];
    }
    return (object) ['success'=>true];
}
function resetMarzbanTraffic($server_id, $remark, $token){
    global $connection;
    
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $panel_url = $server_info['panel_url'];
    $serverName = $server_info['username'];
    $serverPass = $server_info['password'];

    if($token == null) $token = getMarzbanToken($server_id);
    if(isset($token->detail)){return (object) ['success'=>false, 'msg'=>$token->detail];}

    $panel_url .=  '/api/user/' . $remark .'/reset';

    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $panel_url);
    curl_setopt($curl, CURLOPT_POST , true);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HTTPHEADER, array(
        'Accept: application/json',
        'Authorization: Bearer ' .  $token->access_token
    ));

    $response = curl_exec($curl);
    curl_close($curl);
    if(isset($response->detail)){
		$detail = $response->detail;
        return (object) ['success'=>false, 'msg' => is_object($detail)?implode("-", (array) $detail):$detail];
    }
    return (object) ['success'=>true];
}
function renewMarzbanUUID($server_id,$remark){
    global $connection;
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $panel_url = $server_info['panel_url'];
    $token = getMarzbanToken($server_id);
    if(isset($token->detail)){return (object) ['success'=>false, 'msg'=>$token->detail];}
    $panel_url .= '/api/user/' . $remark .'/revoke_sub';

    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $panel_url);
    curl_setopt($curl, CURLOPT_POST , true);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HTTPHEADER, array(
        'Accept: application/json',
        'Authorization: Bearer ' .  $token->access_token
    ));

    $response = json_decode(curl_exec($curl));
    curl_close($curl);
    if(isset($response->detail)){
		$detail = $response->detail;
        return (object) ['success'=>false, 'msg' => is_object($detail)?implode("-", (array) $detail):$detail];
    }
    $response = getMarzbanUserInfo($server_id, $remark);
    return $response;
}

function deleteMarzban($server_id,$remark){
    global $connection;
    
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $panel_url = $server_info['panel_url'];
    $serverName = $server_info['username'];
    $serverPass = $server_info['password'];
    $serverType = $server_info['type'];
    
    $token = getMarzbanToken($server_id);
    if(isset($token->detail)){return (object) ['success'=>false, 'msg'=>$token->detail];}
    $panel_url .=  '/api/user/'. urlencode($remark);

    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $panel_url);
    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'DELETE');
    curl_setopt($curl, CURLOPT_HTTPGET, true);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HTTPHEADER, array(
        'Accept: application/json',
        'Authorization: Bearer ' .  $token->access_token
    ));

    $response = json_decode(curl_exec($curl));
    curl_close($curl);
    
    if(isset($response->detail)){
		$detail = $response->detail;
        return (object) ['success'=>false, 'msg' => is_object($detail)?implode("-", (array) $detail):$detail];
    }
    
    return (object) ['success'=>true];
}
function changeMarzbanState($server_id,$remark){
    global $connection;
    
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $panel_url = $server_info['panel_url'];
    $serverName = $server_info['username'];
    $serverPass = $server_info['password'];
    $serverType = $server_info['type'];
    
    $token = getMarzbanToken($server_id);
    if(isset($token->detail)){return (object) ['success'=>false, 'msg'=>$token->detail];}
    $configInfo = getMarzbanUser($server_id, $remark, $token);

    $panel_url .=  '/api/user/'. $remark;

    $postFields = array(
        "inbounds" => $configInfo->inbounds,
        "proxies" => $configInfo->proxies,
        "expire" => $configInfo->expire,
        "data_limit" => $configInfo->data_limit,
        "username" => urlencode($remark),
        "note" => $configInfo->note,
        "data_limit_reset_strategy"=> $configInfo->data_limit_reset_strategy,
        "status" => $configInfo->status == "active"?"disabled":"active"
    );


    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $panel_url);
    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'PUT');
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HTTPHEADER, array(
        'Accept: application/json',
        'Authorization: Bearer ' .  $token->access_token,
        'Content-Type: application/json'
    ));
    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($postFields));

    $response = json_decode(curl_exec($curl));
    curl_close($curl);

    if(isset($response->detail)){
		$detail = $response->detail;
        return (object) ['success'=>false, 'msg' => is_object($detail)?implode("-", (array) $detail):$detail];
    }
    return (object) ['success'=>true];
}
function getJson($server_id){
    global $connection;
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $panel_url = $server_info['panel_url'];

    $serverName = $server_info['username'];
    $serverPass = $server_info['password'];
    $serverType = $server_info['type'];

    $loginUrl = $panel_url . '/login';
    
    $postFields = array(
        "username" => $serverName,
        "password" => $serverPass
        );
        
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $loginUrl);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_POST, 1);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($curl, CURLOPT_TIMEOUT, 3); 
    curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($postFields));
    curl_setopt($curl, CURLOPT_HEADER, 1);
    $response = curl_exec($curl);
    
    $header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $header = substr($response, 0, $header_size);
    $body = substr($response, $header_size);
    preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $header, $matches);
    $cookies = array();
    foreach($matches[1] as $item) {
        parse_str($item, $cookie);
        $cookies = array_merge($cookies, $cookie);
    }

    $loginResponse = json_decode($body,true);
    
    if(!$loginResponse['success']){
        curl_close($curl);
        return $loginResponse;
    }
    if($serverType == "sanaei") $url = "$panel_url/panel/inbound/list";
    else $url = "$panel_url/xui/inbound/list";
    curl_setopt_array($curl, array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_HEADER => false,
        CURLOPT_HTTPHEADER => array(
            'User-Agent:  Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:108.0) Gecko/20100101 Firefox/108.0',
            'Accept:  application/json, text/plain, */*',
            'Accept-Language:  en-US,en;q=0.5',
            'Accept-Encoding:  gzip, deflate',
            'X-Requested-With:  XMLHttpRequest',
            'Cookie: ' . array_keys($cookies)[0] . "=" . $cookies[array_keys($cookies)[0]]
        ),
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_SSL_VERIFYPEER => false,
    ));
    
    $response = curl_exec($curl);
    curl_close($curl);
    return json_decode($response);
}
function getNewCert($server_id){
    global $connection;
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $panel_url = $server_info['panel_url'];

    $serverName = $server_info['username'];
    $serverPass = $server_info['password'];
    
    $loginUrl = $panel_url . '/login';
    
    $postFields = array(
        "username" => $serverName,
        "password" => $serverPass
        );
        
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $loginUrl);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_POST, 1);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($curl, CURLOPT_TIMEOUT, 3); 
    curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($postFields));
    curl_setopt($curl, CURLOPT_HEADER, 1);
    $response = curl_exec($curl);

    $header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $header = substr($response, 0, $header_size);
    $body = substr($response, $header_size);
    preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $header, $matches);
    $cookies = array();
    foreach($matches[1] as $item) {
        parse_str($item, $cookie);
        $cookies = array_merge($cookies, $cookie);
    }

    $loginResponse = json_decode($body,true);
    if(!$loginResponse['success']){
        curl_close($curl);
        return $loginResponse;
    }
    
    curl_setopt_array($curl, array(
        CURLOPT_URL => "$panel_url/server/getNewX25519Cert",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HEADER => false,
        CURLOPT_HTTPHEADER => array(
            'User-Agent:  Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:108.0) Gecko/20100101 Firefox/108.0',
            'Accept:  application/json, text/plain, */*',
            'Accept-Language:  en-US,en;q=0.5',
            'Accept-Encoding:  gzip, deflate',
            'X-Requested-With:  XMLHttpRequest',
            'Cookie: ' . array_keys($cookies)[0] . "=" . $cookies[array_keys($cookies)[0]]
        )
    ));

    $response = curl_exec($curl);
    curl_close($curl);
    return $response = json_decode($response);
}
function addUser($server_id, $client_id, $protocol, $port, $expiryTime, $remark, $volume, $netType, $security = 'none', $rahgozar = false, $planId = null){
    global $connection;
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $panel_url = $server_info['panel_url'];
    $security = $server_info['security'];
    $tlsSettings = $server_info['tlsSettings'];
    $header_type = $server_info['header_type'];
    $request_header = $server_info['request_header'];
    $response_header = $server_info['response_header'];
    $sni = $server_info['sni'];
    $serverType = $server_info['type'];
    $xtlsTitle = ($serverType == "sanaei" || $serverType == "alireza")?"XTLSSettings":"xtlsSettings";
    $reality = $server_info['reality'];

    if(!empty($sni) && ($serverType == "sanaei" || $serverType == "alireza")){
        $tlsSettings = json_decode($tlsSettings,true);
        $tlsSettings['serverName'] = $sni;
        $tlsSettings = json_encode($tlsSettings);
    }
    
    $volume = ($volume == 0) ? 0 : floor($volume * 1073741824);
    $headers = getNewHeaders($netType, $request_header, $response_header, $header_type);
//---------------------------------------Trojan------------------------------------//
    if($protocol == 'trojan'){
        // protocol trojan
        if($security == 'none'){
            
            $tcpSettings = '{
        	  "network": "tcp",
        	  "security": "'.$security.'",
        	  "tlsSettings": '.$tlsSettings.',
        	  "tcpSettings": {
                "header": '.$headers.'
              }
        	}';
            $wsSettings = '{
              "network": "ws",
              "security": "'.$security.'", 
        	  "tlsSettings": '.$tlsSettings.',
              "wsSettings": {
                "path": "/",
                "headers": '.$headers.'
              }
            }';
            
        	if($serverType == "sanaei" || $serverType == "alireza"){
                $settings = '{
        	  "clients": [
        		{
        		  "id": "'.$client_id.'",
                  "enable": true,
                  "email": "' . $remark. '",
                  "limitIp": 0,
                  "totalGB": 0,
                  "expiryTime": 0,
                  "subId": "' . RandomString(16) . '"
        		}
        	  ],
        	  "decryption": "none",
        	  "fallbacks": []
        	}';
        	}else{
                $settings = '{
        	  "clients": [
        		{
        		  "id": "'.$client_id.'",
        		  "flow": "",
        		  "email": "' . $remark. '"
        		}
        	  ],
        	  "decryption": "none",
        	  "fallbacks": []
        	}';
        	}
        }elseif($security == 'xtls' && $serverType != "sanaei" && $serverType != "alireza") {
                    $tcpSettings = '{
                	  "network": "tcp",
                	  "security": "'.$security.'",
                	  "' . $xtlsTitle . '": '.$tlsSettings.',
                	  "tcpSettings": {
                        "header": '.$headers.'
                      }
                	}';

                    $wsSettings = '{
                  "network": "ws",
                  "security": "'.$security.'",
            	  "' . $xtlsTitle .'": '.$tlsSettings.',
                  "wsSettings": {
                    "path": "/",
                    "headers": '.$headers.'
                  }
                }';
                    $settings = '{
                  "clients": [
                    {
                      "id": "'.$uniqid.'",
                      "alterId": 0
                    }
                  ],
                  "decryption": "none",
            	  "fallbacks": []
                }';
                }
        
        else{
            $tcpSettings = '{
        	  "network": "tcp",
        	  "security": "'.$security.'",
        	  "tlsSettings": '.$tlsSettings.',
        	  "tcpSettings": {
                "header": '.$headers.'
              }
        	}';
                $wsSettings = '{
              "network": "ws",
              "security": "'.$security.'",
        	  "tlsSettings": '.$tlsSettings.',
              "wsSettings": {
                "path": "/",
                "headers": '.$headers.'
              }
            }';
		if($serverType == "sanaei" || $serverType == "alireza"){
            $settings = '{
		  "clients": [
			{
			  "password": "'.$client_id.'",
              "enable": true,
              "email": "' . $remark. '",
              "limitIp": 0,
              "totalGB": 0,
              "expiryTime": 0,
              "subId": "' . RandomString(16) . '"
			}
		  ],
		  "fallbacks": []
		}';
		}else{
            $settings = '{
		  "clients": [
			{
			  "password": "'.$client_id.'",
			  "flow": "",
			  "email": "' . $remark. '"
			}
		  ],
		  "fallbacks": []
		}';
		}
        }



        $streamSettings = ($netType == 'tcp') ? $tcpSettings : $wsSettings;
		if($netType == 'grpc'){
		    $keyFileInfo = json_decode($tlsSettings,true);
		    $certificateFile = "/root/cert.crt";
		    $keyFile = '/root/private.key';
		    
		    if(isset($keyFileInfo['certificates'])){
		        $certificateFile = $keyFileInfo['certificates'][0]['certificateFile'];
		        $keyFile = $keyFileInfo['certificates'][0]['keyFile'];
		    }

			if($security == 'tls') {
				$streamSettings = '{
  "network": "grpc",
  "security": "tls",
  "tlsSettings": {
    "serverName": "' .
    (!empty($sni) && ($serverType == "sanaei" || $serverType == "alireza") ?  $sni: parse_url($panel_url, PHP_URL_HOST))
     . '",
    "certificates": [
      {
        "certificateFile": "' . $certificateFile . '",
        "keyFile": "' . $keyFile . '"
      }
    ],
    "alpn": []'
    .'
  },
  "grpcSettings": {
    "serviceName": ""
  }
}';
		    }else{
			$streamSettings = '{
  "network": "grpc",
  "security": "none",
  "grpcSettings": {
    "serviceName": "' . parse_url($panel_url, PHP_URL_HOST) . '"
  }
}';
		}
	    }




        // trojan
        $dataArr = array('up' => '0','down' => '0','total' => $volume,'remark' => $remark,'enable' => 'true','expiryTime' => $expiryTime,'listen' => '','port' => $port,'protocol' => $protocol,'settings' => $settings,'streamSettings' => $streamSettings,
            'sniffing' => '{
      "enabled": true,
      "destOverride": [
        "http",
        "tls"
      ]
    }');
    }else {
//-------------------------------------- vmess vless -------------------------------//
        if($rahgozar == true){
            $wsSettings = '{
                  "network": "ws",
                  "security": "none",
                  "wsSettings": {
                    "path": "/wss' . $port . '",
                    "headers": {}
                  }
                }';
            if($serverType == "sanaei" || $serverType == "alireza"){
                $settings = '{
        	  "clients": [
        		{
        		  "id": "'.$client_id.'",
                  "enable": true,
        		  "email": "' . $remark. '",
                  "limitIp": 0,
                  "totalGB": 0,
                  "expiryTime": 0,
                  "subId": "' . RandomString(16) . '"
        		}
        	  ],
        	  "decryption": "none",
        	  "fallbacks": []
        	}';
            }else{
                $settings = '{
        	  "clients": [
        		{
        		  "id": "'.$client_id.'",
        		  "flow": "",
        		  "email": "' . $remark. '"
        		}
        	  ],
        	  "decryption": "none",
        	  "fallbacks": []
        	}';
            }
        }else{
            if($security == 'tls') {
                $tcpSettings = '{
        	  "network": "tcp",
        	  "security": "'.$security.'",
        	  "tlsSettings": '.$tlsSettings.',
        	  "tcpSettings": {
                "header": '.$headers.'
              }
        	}';
                $wsSettings = '{
              "network": "ws",
              "security": "'.$security.'",
        	  "tlsSettings": '.$tlsSettings.',
              "wsSettings": {
                "path": "/",
                "headers": '.$headers.'
              }
            }';
            if($serverType == "sanaei" || $serverType == "alireza"){
                $settings = '{
              "clients": [
                {
                  "id": "'.$client_id.'",
                  "enable": true,
                  "email": "' . $remark. '",
                  "limitIp": 0,
                  "totalGB": 0,
                  "expiryTime": 0,
                  "subId": "' . RandomString(16) . '"
                }
              ],
              "disableInsecureEncryption": false
            }';
            }else{
                $settings = '{
              "clients": [
                {
                  "id": "'.$client_id.'",
                  "alterId": 0
                }
              ],
              "disableInsecureEncryption": false
            }';
            }
            }elseif($security == 'xtls' && $serverType != "sanaei" && $serverType != "alireza") {
                $tcpSettings = '{
        	  "network": "tcp",
        	  "security": "'.$security.'",
        	  "' . $xtlsTitle . '": '.$tlsSettings.',
        	  "tcpSettings": {
                "header": '.$headers.'
              }
        	}';
                $wsSettings = '{
              "network": "ws",
              "security": "'.$security.'",
        	  "' . $xtlsTitle . '": '.$tlsSettings.',
              "wsSettings": {
                "path": "/",
                "headers": '.$headers.'
              }
            }';
                $settings = '{
              "clients": [
                {
                  "id": "'.$client_id.'",
                  "alterId": 0
                }
              ],
              "disableInsecureEncryption": false
            }';
            }else {
                $tcpSettings = '{
        	  "network": "tcp",
        	  "security": "none",
        	  "tcpSettings": {
        		"header": '.$headers.'
        	  }
        	}';
                $wsSettings = '{
              "network": "ws",
              "security": "none",
              "wsSettings": {
                "path": "/",
                "headers": '.$headers.'
              }
            }';
            if($serverType == "sanaei" || $serverType == "alireza"){
                $settings = '{
        	  "clients": [
        		{
        		  "id": "'.$client_id.'",
        		  "enable": true,
        		  "email": "' . $remark. '",
                  "limitIp": 0,
                  "totalGB": 0,
                  "expiryTime": 0,
                  "subId": "' . RandomString(16) . '"
        		}
        	  ],
        	  "decryption": "none",
        	  "fallbacks": []
        	}';
            }else{
                $settings = '{
        	  "clients": [
        		{
        		  "id": "'.$client_id.'",
        		  "flow": "",
        		  "email": "' . $remark. '"
        		}
        	  ],
        	  "decryption": "none",
        	  "fallbacks": []
        	}';
            }
            }
        }
        
        
		if($protocol == 'vless'){
		    if($serverType =="sanaei" || $serverType == "alireza"){
		        if($reality == "true"){
	                $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
                    $stmt->bind_param("i", $planId);
                    $stmt->execute();
                    $file_detail = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                
                    $dest = !empty($file_detail['dest'])?$file_detail['dest']:"yahoo.com";
                    $serverNames = !empty($file_detail['serverNames'])?$file_detail['serverNames']:
                                '[
                                    "yahoo.com",
                                    "www.yahoo.com"
                                ]';
                    $spiderX = !empty($file_detail['spiderX'])?$file_detail['spiderX']:"";
                    $flow = isset($file_detail['flow']) && $file_detail['flow'] != "None" ? $file_detail['flow'] : "";
                    


		            $certInfo = getNewCert($server_id)->obj;
		            $publicKey = $certInfo->publicKey;
		            $privateKey = $certInfo->privateKey;
		            $shortId = RandomString(8, "small");
		            $serverName = json_decode($tlsSettings,true)['serverName'];
		            if($netType == "grpc"){
    		            $tcpSettings = '{
                          "network": "grpc",
                          "security": "reality",
                          "realitySettings": {
                            "show": false,
                            "xver": 0,
                            "dest": "' . $dest . '",
                            "serverNames":' . $serverNames . ',
                            "privateKey": "' . $privateKey . '",
                            "minClient": "",
                            "maxClient": "",
                            "maxTimediff": 0,
                            "shortIds": [
                              "' . $shortId .'"
                            ],
                            "settings": {
                              "publicKey": "' . $publicKey . '",
                              "fingerprint": "firefox",
                              "serverName": "' . $serverName . '",
                              "spiderX": "' . $spiderX . '"
                            }
                          },
                          "grpcSettings": {
                            "serviceName": "",
                    		"multiMode": false
                          }
                        }';
		            }else{
    		            $tcpSettings = '{
                          "network": "tcp",
                          "security": "reality",
                          "realitySettings": {
                            "show": false,
                            "xver": 0,
                            "dest": "' . $dest . '",
                            "serverNames":' . $serverNames . ',
                            "privateKey": "' . $privateKey . '",
                            "minClient": "",
                            "maxClient": "",
                            "maxTimediff": 0,
                            "shortIds": [
                              "' . $shortId .'"
                            ],
                            "settings": {
                              "publicKey": "' . $publicKey . '",
                              "fingerprint": "firefox",
                              "serverName": "' . $serverName . '",
                              "spiderX": "' . $spiderX . '"
                            }
                          },
                          "tcpSettings": {
                            "acceptProxyProtocol": false,
                    		"header": '.$headers.'
                          }
                        }';
		            }
    			    $settings = '{
        			  "clients": [
        				{
        				  "id": "'.$client_id.'",
        				  "enable": true,
                          "email": "' . $remark. '",
                          "flow": "' . $flow .'",
                          "limitIp": 0,
                          "totalGB": 0,
                          "expiryTime": 0,
                          "subId": "' . RandomString(16) . '"
        				}
        			  ],
        			  "decryption": "none",
        			  "fallbacks": []
        			}';
		            $netType = "tcp";
		        }else{
    			    $settings = '{
        			  "clients": [
        				{
        				  "id": "'.$client_id.'",
        				  "enable": true,
                          "email": "' . $remark. '",
                          "limitIp": 0,
                          "totalGB": 0,
                          "expiryTime": 0,
                          "subId": "' . RandomString(16) . '"
        				}
        			  ],
        			  "decryption": "none",
        			  "fallbacks": []
        			}';
		        }
		    }else{
			$settings = '{
			  "clients": [
				{
				  "id": "'.$client_id.'",
				  "flow": "",
				  "email": "' . $remark. '"
				}
			  ],
			  "decryption": "none",
			  "fallbacks": []
			}';
		    }
		}

        $streamSettings = ($netType == 'tcp') ? $tcpSettings : $wsSettings;
		if($netType == 'grpc' && $reality != "true"){
		    $keyFileInfo = json_decode($tlsSettings,true);
		    $certificateFile = "/root/cert.crt";
		    $keyFile = '/root/private.key';
		    
		    if(isset($keyFileInfo['certificates'])){
		        $certificateFile = $keyFileInfo['certificates'][0]['certificateFile'];
		        $keyFile = $keyFileInfo['certificates'][0]['keyFile'];
		    }

			if($security == 'tls') {
				$streamSettings = '{
  "network": "grpc",
  "security": "tls",
  "tlsSettings": {
    "serverName": "' . parse_url($panel_url, PHP_URL_HOST) . '",
    "certificates": [
      {
        "certificateFile": "' . $certificateFile . '",
        "keyFile": "' . $keyFile . '"
      }
    ],
    "alpn": []
  },
  "grpcSettings": {
    "serviceName": ""
  }
}';
		    }else{
			$streamSettings = '{
  "network": "grpc",
  "security": "none",
  "grpcSettings": {
    "serviceName": "' . parse_url($panel_url, PHP_URL_HOST) . '"
  }
}';
		}
	    }

        if(($serverType == "sanaei" || $serverType == "alireza") && $reality == "true"){
            $sniffing = '{
              "enabled": true,
              "destOverride": [
                "http",
                "tls",
                "quic"
              ]
            }';
        }else{
            $sniffing = '{
        	  "enabled": true,
        	  "destOverride": [
        		"http",
        		"tls"
        	  ]
        	}';
        }
        // vmess - vless
        $dataArr = array('up' => '0','down' => '0','total' => $volume, 'remark' => $remark,'enable' => 'true','expiryTime' => $expiryTime,'listen' => '','port' => $port,'protocol' => $protocol,'settings' => $settings,'streamSettings' => $streamSettings
        ,'sniffing' => $sniffing);
    }
    
    $phost = str_ireplace('https://','',str_ireplace('http://','',$panel_url));
    $serverName = $server_info['username'];
    $serverPass = $server_info['password'];
    
    $loginUrl = $panel_url . '/login';
    
    $postFields = array(
        "username" => $serverName,
        "password" => $serverPass
        );
        
        
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $loginUrl);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_POST, 1);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($curl, CURLOPT_TIMEOUT, 3); 
    curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($postFields));
    curl_setopt($curl, CURLOPT_HEADER, 1);
    $response = curl_exec($curl);
sendMessage(curl_error($curl));

    $header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $header = substr($response, 0, $header_size);
    $body = substr($response, $header_size);
    preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $header, $matches);
    $cookies = array();
    foreach($matches[1] as $item) {
        parse_str($item, $cookie);
        $cookies = array_merge($cookies, $cookie);
    }

    $loginResponse = json_decode($body,true);

    if(!$loginResponse['success']){
        curl_close($curl);
        return $loginResponse;
    }
    
    if($serverType == "sanaei") $url = "$panel_url/panel/inbound/add";
    else $url = "$panel_url/xui/inbound/add";
    
    curl_setopt_array($curl, array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_CONNECTTIMEOUT => 15, 
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => $dataArr,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_SSL_VERIFYPEER => false, 
        CURLOPT_HEADER => false,
        CURLOPT_HTTPHEADER => array(
            'User-Agent:  Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:108.0) Gecko/20100101 Firefox/108.0',
            'Accept:  application/json, text/plain, */*',
            'Accept-Language:  en-US,en;q=0.5',
            'Accept-Encoding:  gzip, deflate',
            'X-Requested-With:  XMLHttpRequest',
            'Cookie: ' . array_keys($cookies)[0] . "=" . $cookies[array_keys($cookies)[0]]
        )
    ));
    $response = curl_exec($curl);
    curl_close($curl);
    return json_decode($response);
}

// Added by ACh

function sendToAllAdmins($method, $data, $pay_hash_id = null, $options = []){
    global $admins_list, $admin, $connection;

    $adminMessages = [];

    foreach ($admins_list as $adminId) {
        if ($adminId != $admin) {
            $data['chat_id'] = $adminId;

            // اگر بخوایم پیام ریپلای باشه (اختیاری)
            if (isset($options['reply_to_message_id'])) {
                $data['reply_to_message_id'] = $options['reply_to_message_id'];
            }

            // سایر گزینه های اضافی (مثل disable_notification و...)
            if (!empty($options['extra'])) {
                foreach ($options['extra'] as $key => $value) {
                    $data[$key] = $value;
                }
            }

            // ارسال پیام
            $res = bot($method, $data);

            // ذخیره پیام اگر نیاز باشه
            if ($pay_hash_id && $res && isset($res->result->message_id)) {
                $adminMessages[] = [
                    'chat_id' => $adminId,
                    'message_id' => $res->result->message_id
                ];
            }
        }
    }

    // ذخیره در دیتابیس اگر hash_id داده شده
    if ($pay_hash_id && !empty($adminMessages)) {
        $json = json_encode($adminMessages, JSON_UNESCAPED_UNICODE);
        $stmt = $connection->prepare("UPDATE `pays` SET `admins_messages` = ? WHERE `hash_id` = ?");
        $stmt->bind_param("ss", $json, $pay_hash_id);
        $stmt->execute();
        $stmt->close();
    }
}


$buttons_only_for_special_admins = [
    'bot_reports',
    'server_settings',
    'gateways_settings',
    'bot_settings',
	'categories_settings',
	'plan_settings'
];

function canSeeButton($key, $userId) {
    global $buttons_only_for_special_admins, $special_admins;

    if (in_array($key, $buttons_only_for_special_admins)) {
        return in_array($userId, $special_admins);
    }
    return true;
}

?>
