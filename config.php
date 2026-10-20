<?php

include_once "settings/values.php";
include_once 'settings/jdf.php';
require_once 'baseInfo.php';

$connection = new mysqli('localhost',$dbUserName,$dbPassword,$dbName);
if($connection->connect_error){
    exit("error " . $connection->connect_error);  
}
$connection->set_charset("utf8mb4");
require_once __DIR__ . '/src/Purchase/Core.php';
require_once __DIR__ . '/src/Finance/PaymentLifecycle.php';

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
    require_once __DIR__ . '/src/Backup/Database.php';
    return \AchBot\Backup\Database\send(
        $connection, $dbUserName, $dbPassword, $dbName, $channelId,
        'sendDocument', __DIR__
    );
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
require_once __DIR__ . '/src/Finance/WalletStart.php';
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
        [[['create_wallet_debt','createUserWalletDebt']]],
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

/**
 * Legacy BOT_STATES.type is not unique. Serialize all application writes,
 * read the persisted document FOR UPDATE, and change only the intended field.
 * Financially significant audience changes cannot be lost to stale requests.
 */
function achbotMutateBotStates(callable $mutate): bool {
    global $connection, $botState;
    $lockName='achbot_bot_states_write';
    $ownsLock=false;$started=false;
    try {
        $stmt=$connection->prepare('SELECT GET_LOCK(?, 10) AS acquired');
        if (!$stmt) throw new RuntimeException('settings_lock_prepare');
        $stmt->bind_param('s',$lockName);
        if (!$stmt->execute()) throw new RuntimeException('settings_lock_execute');
        $ownsLock=(int)($stmt->get_result()->fetch_assoc()['acquired']??0)===1;
        $stmt->close();
        if (!$ownsLock) throw new RuntimeException('settings_lock_unavailable');
        if (!$connection->begin_transaction()) throw new RuntimeException('settings_begin_failed');
        $started=true;
        // Historical type has no index. Do not FOR UPDATE a full-table
        // scan: it can lock unrelated Marzban group records. The named
        // writer lock serializes in-app inserts; existing documents retain
        // their stable primary key, locked exclusively only by ID.
        $stmt=$connection->prepare("SELECT id FROM setting WHERE type='BOT_STATES' ORDER BY id LIMIT 2");
        if (!$stmt || !$stmt->execute()) throw new RuntimeException('settings_locate_failed');
        $ids=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        if (count($ids)>1) throw new RuntimeException('settings_ambiguous');
        $rows=[];
        if ($ids) {
            $id=(int)$ids[0]['id'];
            $stmt=$connection->prepare("SELECT id,type,value FROM setting WHERE id=? FOR UPDATE");
            if (!$stmt) throw new RuntimeException('settings_lock_prepare');
            $stmt->bind_param('i',$id);
            if (!$stmt->execute()) throw new RuntimeException('settings_lock_failed');
            $row=$stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$row || $row['type']!=='BOT_STATES')
                throw new RuntimeException('settings_row_changed');
            $rows=[$row];
        }
        $fresh=$rows===[] ? [] : json_decode((string)$rows[0]['value'],true);
        if (!is_array($fresh)) throw new RuntimeException('settings_malformed');
        $next=$mutate($fresh);
        if (!is_array($next)) throw new RuntimeException('settings_invalid_update');
        $json=json_encode($next,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        if ($rows) {
            $stmt=$connection->prepare("UPDATE setting SET value=? WHERE id=? AND type='BOT_STATES'");
            if (!$stmt) throw new RuntimeException('settings_write_prepare');
            $rowId=(int)$rows[0]['id'];
            $stmt->bind_param('si',$json,$rowId);
        } else {
            $stmt=$connection->prepare("INSERT INTO setting (type,value) VALUES ('BOT_STATES',?)");
            if (!$stmt) throw new RuntimeException('settings_insert_prepare');
            $stmt->bind_param('s',$json);
        }
        if (!$stmt->execute()) throw new RuntimeException('settings_write_failed');
        $stmt->close();
        if (!$connection->commit()) throw new RuntimeException('settings_commit_uncertain');
        $started=false;
        $botState=$next;
        return true;
    } catch (Throwable $error) {
        if ($started) {try {$connection->rollback();} catch(Throwable $ignored) {}}
        error_log('achbot_bot_states_update_deferred code='.(int)$error->getCode());
        return false;
    } finally {
        if ($ownsLock) {
            try {
                $stmt=$connection->prepare('SELECT RELEASE_LOCK(?)');
                $stmt->bind_param('s',$lockName);
                $stmt->execute();$stmt->close();
            } catch (Throwable $ignored) {}
        }
    }
}
function setSettings($field, $value): bool {
    if (in_array($field,['weSwapState','tronWallet'],true)) return false;
    return achbotMutateBotStates(static function(array $state) use($field,$value): array {
        $state[$field]=$value;
        return $state;
    });
}
/** Cycle a single audience method using the freshly persisted policy map. */
function achbotCycleGatewayAudience(string $method): bool {
    if (!array_key_exists($method,achbotPayUxAudienceMethods())) return false;
    return achbotMutateBotStates(static function(array $state) use($method): array {
        $map=array_key_exists('gatewayAudience',$state)
            ? $state['gatewayAudience'] : [];
        if (!is_array($map)) throw new RuntimeException('settings_invalid_audience_map');
        $modes=array_keys(achbotPayUxAudienceModes());
        $mode=array_key_exists($method,$map) ? $map[$method] : 'all';
        $position=is_string($mode) ? array_search($mode,$modes,true) : false;
        // Malformed explicit method stays blocked rather than resetting to all.
        $map[$method]=$position===false ? 'off'
            : $modes[($position+1)%count($modes)];
        $state['gatewayAudience']=$map;
        return $state;
    });
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
require_once __DIR__ . '/src/Finance/AgentDiscounts.php';
require_once __DIR__ . '/src/Finance/GatewayClient.php';
function getMarzbanPanelGroupKeys($mode, $serverId = 0) {
    global $connection, $buttonValues;
    $keys = [];
    foreach (achbotMarzbanPanelGroups($connection) as $group) {
        $id = (int)$group['id'];
        $cb = $mode === 'add'
            ? "selectNewPanelGroup_$id"
            : "setServerPanelGroup_{$serverId}_{$id}";
        $keys[] = [['text'=>'🏷 ' . $group['name'], 'callback_data'=>$cb]];
    }
    $none = $mode === 'add'
        ? 'selectNewPanelGroup_0'
        : "setServerPanelGroup_{$serverId}_0";
    $keys[] = [['text'=>'⭕ بدون گروه (انتقال غیرفعال)', 'callback_data'=>$none]];
    $keys[] = [['text'=>$buttonValues['back_button'] ?? '🔙 بازگشت',
                'callback_data'=>$mode === 'add' ? 'serversSetting' : "showServerSettings{$serverId}_0"]];
    return json_encode(['inline_keyboard'=>$keys], JSON_UNESCAPED_UNICODE);
}
function getMarzbanPanelGroupsManagementKeys() {
    global $connection, $buttonValues;
    $keys = [];
    foreach (achbotMarzbanPanelGroups($connection) as $group) {
        $id = (int)$group['id'];
        $keys[] = [
            ['text'=>'🏷 ' . $group['name'], 'callback_data'=>'wizwizch'],
            ['text'=>'🗑 حذف گروه بدون سرور', 'callback_data'=>"deleteMarzbanPanelGroup_$id"],
        ];
    }
    if (!$keys) $keys[] = [['text'=>'هنوز گروهی تعریف نشده', 'callback_data'=>'wizwizch']];
    $keys[] = [['text'=>'➕ تعریف گروه پنل جدید', 'callback_data'=>'addMarzbanPanelGroup']];
    $keys[] = [['text'=>$buttonValues['back_button'] ?? '🔙 بازگشت', 'callback_data'=>'serversSetting']];
    return json_encode(['inline_keyboard'=>$keys], JSON_UNESCAPED_UNICODE);
}
function getServerConfigKeys($serverId,$offset = 0){
    global $connection, $buttonValues;
    $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
    $stmt->bind_param("i", $serverId);
    $stmt->execute();
    $info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id`=?");
    $stmt->bind_param("i", $serverId);
    $stmt->execute();
    $config = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $back = [['text' => $buttonValues['back_button'], 'callback_data' => "nextServerPage" . $offset]];
    if (!$info || !achbotSupportsPanel($config['type'] ?? null)) {
        return json_encode(['inline_keyboard' => [
            [['text'=>'پنل پشتیبانی نمی‌شود', 'callback_data'=>'wizwizch']],
            $back,
        ]]);
    }
    $id = $info['id'];
    $panelGroup = achbotMarzbanGroup($connection, $id);
    $groupLabel = $panelGroup === null ? 'بدون گروه' : $panelGroup['name'];
    $keys = [
        [['text'=>$config['panel_url'], 'callback_data'=>'wizwizch']],
        [['text'=>$info['title'], 'callback_data'=>"editServerName$id"], ['text'=>'❕نام سرور', 'callback_data'=>'wizwizch']],
        [['text'=>$info['flag'], 'callback_data'=>"editServerFlag$id"], ['text'=>'🚩 پرچم سرور', 'callback_data'=>'wizwizch']],
        [['text'=>$groupLabel, 'callback_data'=>"editMarzbanGroup$id"], ['text'=>'🏷 گروه پنل مشترک', 'callback_data'=>'wizwizch']],
        [['text'=>$info['remark'], 'callback_data'=>"editServerRemark$id"], ['text'=>'📣 ریمارک سرور', 'callback_data'=>'wizwizch']],
        [['text'=>'مرزبان', 'callback_data'=>'wizwizch'], ['text'=>'نوع سرور', 'callback_data'=>'wizwizch']],
        [['text'=>$info['ucount'], 'callback_data'=>"editServerMax$id"], ['text'=>'ظرفیت سرور', 'callback_data'=>'wizwizch']],
        [['text'=>'🔅 تغییر اطلاعات ورود', 'callback_data'=>"changesServerLoginInfo$id"]],
        [['text'=>'🔗 لینک اشتراک کمکی', 'callback_data'=>"editSubLinks$id"]],
        [['text'=>'✂️ حذف سرور', 'callback_data'=>"wizwizdeleteserver$id"]],
        $back,
    ];
    return json_encode(['inline_keyboard'=>$keys]);
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
            $serverType = achbotSupportsPanel($serverTypeInfo['type'] ?? null)
                ? 'مرزبان' : 'پشتیبانی نمی‌شود';
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
        ['text'=>"➕ ثبت سرور مرزبان",'callback_data'=>"addNewMarzbanPanel"]
        ];
    $keys[] = [['text'=>'🏷 مدیریت گروه‌های پنل مرزبان', 'callback_data'=>'manageMarzbanPanelGroups']];
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
/** Strictly bounded per-instance financial UX policy editor. */
function achbotPaymentPolicyFields(): array {
    return [
        'paymentDraftMinutes'=>['label'=>'مهلت پیش‌نویس (دقیقه)','default'=>30,'min'=>5,'max'=>180],
        'paymentManualHours'=>['label'=>'فاکتور دستی بدون شروع رسید (ساعت)','default'=>6,'min'=>1,'max'=>72],
        'paymentHostedHours'=>['label'=>'مهلت لینک آنلاین پیش از بررسی (ساعت)','default'=>6,'min'=>1,'max'=>72],
        'receiptApprovedMinutes'=>['label'=>'تکرار خرید/شارژ تأییدشده (دقیقه)','default'=>10,'min'=>1,'max'=>1440],
        'receiptApprovedOtherMinutes'=>['label'=>'تکرار سایر پرداخت‌های تأییدشده (دقیقه)','default'=>60,'min'=>1,'max'=>1440],
        'receiptReviewMinutes'=>['label'=>'رسید مشابه در حال بررسی (دقیقه)','default'=>360,'min'=>1,'max'=>1440],
        'receiptDeclinedMinutes'=>['label'=>'تکرار رسید ردشده (دقیقه)','default'=>3,'min'=>1,'max'=>1440],
    ];
}
function achbotPaymentPolicyKeyboard(): string {
    global $botState;
    $rows=[];
    $states=is_array($botState??null)?$botState:[];
    foreach (achbotPaymentPolicyFields() as $key=>$field) {
        $value=$states[$key]??$field['default'];
        if (!is_scalar($value) || !preg_match('/^[0-9]{1,5}$/D',(string)$value) ||
            (int)$value<$field['min'] || (int)$value>$field['max'])
            $value=$field['default'];
        $rows[]=[['text'=>$field['label'].': '.$value,
                 'callback_data'=>'editPaymentPolicy_'.$key]];
    }
    $rows[]=[['text'=>'🔙 بازگشت به درگاه‌ها','callback_data'=>'gateWays_Channels']];
    return json_encode(['inline_keyboard'=>$rows],JSON_UNESCAPED_UNICODE);
}
/** Admin-only audience selectors; stored under BOT_STATES.gatewayAudience. */
function achbotGatewayAudienceKeyboard(): string {
    global $botState;
    $states=is_array($botState??null)?$botState:[];
    $policy=array_key_exists('gatewayAudience',$states)
        ? $states['gatewayAudience'] : [];
    $labels=achbotPayUxAudienceModes();
    $rows=[];
    foreach (achbotPayUxAudienceMethods() as $name=>$methodLabel) {
        $selected=is_array($policy)
            ? (array_key_exists($name,$policy) ? $policy[$name] : 'all') : null;
        $group=is_string($selected) && array_key_exists($selected,$labels)
            ? $labels[$selected] : '⚠️ سیاست نامعتبر (مسدود)';
        $rows[]=[['text'=>$methodLabel.' — '.$group,
                  'callback_data'=>'cycleGatewayAudience_'.$name]];
    }
    $rows[]=[['text'=>'🔙 بازگشت به درگاه‌ها','callback_data'=>'gateWays_Channels']];
    return json_encode(['inline_keyboard'=>$rows],JSON_UNESCAPED_UNICODE);
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
    $giftCardState = ($botState['giftCardState'] ?? 'off')==='on'?$buttonValues['on']:$buttonValues['off'];
    $manualUsdtState = ($botState['manualUsdtState'] ?? 'off')==='on'?$buttonValues['on']:$buttonValues['off'];
    $robotState = $botState['botState']=="on"?$buttonValues['on']:$buttonValues['off'];
    $nowPaymentWallet = $botState['nowPaymentWallet']=="on"?$buttonValues['on']:$buttonValues['off'];
    $nowPaymentOther = $botState['nowPaymentOther']=="on"?$buttonValues['on']:$buttonValues['off'];
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
    require_once __DIR__ . '/src/Finance/ReceiptHelpers.php';
    $giftSellerUrl = trim((string)($paymentKeys['giftCardUrl'] ?? ''));
    $giftSellerStatus = $giftSellerUrl === '' ? "⚠️ تنظیم‌نشده" :
        (achbotManualGiftSellerUrlValid($giftSellerUrl) ? "✅ تنظیم‌شده" : "⚠️ نامعتبر");
    $cryptoRows=[];
    foreach (['BEP20','TRC20','ERC20'] as $network) {
        $field='manualUsdtWallet'.$network;
        $value=array_key_exists($field,$paymentKeys)
            ? trim((string)$paymentKeys[$field])
            : (((string)($paymentKeys['manualUsdtNetwork'] ?? 'BEP20'))===$network
                ? trim((string)($paymentKeys['manualUsdtWallet'] ?? '')) : '');
        $label=achbotManualUsdtWalletValid($value,$network) ? $value : '⚠️ تنظیم‌نشده';
        $cryptoRows[]=[
            ['text'=>$label,'callback_data'=>'changePaymentKeys'.$field],
            ['text'=>'آدرس USDT '.$network,'callback_data'=>'wizwizch']
        ];
    }
    $trxWallet=trim((string)($paymentKeys['manualTrxWallet']??''));
    $cryptoRows[]=[
        ['text'=>achbotManualTronAddressValid($trxWallet) ? $trxWallet : '⚠️ تنظیم‌نشده',
         'callback_data'=>'changePaymentKeysmanualTrxWallet'],
        ['text'=>'آدرس TRX — TRON','callback_data'=>'wizwizch']
    ];
    $cryptoRows[]=[
        ['text'=>(!empty($paymentKeys['manualUsdtRateToman']) ?
            number_format((int)$paymentKeys['manualUsdtRateToman']).' تومان' : 'نرخ پایه USDT'),
         'callback_data'=>'changePaymentKeysmanualUsdtRateToman'],
        ['text'=>'قیمت هر USDT','callback_data'=>'wizwizch']
    ];
    $cryptoRows[]=[
        ['text'=>(!empty($paymentKeys['manualTrxRateToman']) ?
            number_format((int)$paymentKeys['manualTrxRateToman']).' تومان' : '⚠️ تنظیم‌نشده'),
         'callback_data'=>'changePaymentKeysmanualTrxRateToman'],
        ['text'=>'قیمت هر TRX','callback_data'=>'wizwizch']
    ];
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
            ['text'=>$giftSellerStatus,'callback_data'=>"changePaymentKeysgiftCardUrl"],
            ['text'=>"لینک خرید گیفت‌کارت",'callback_data'=>"wizwizch"]
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
        ...$cryptoRows,
        [
            ['text'=>$giftCardState,'callback_data'=>"changeGateWaysgiftCardState"],
            ['text'=>"🎁 پرداخت با گیفت‌کارت",'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$manualUsdtState,'callback_data'=>"changeGateWaysmanualUsdtState"],
            ['text'=>"💎 پرداخت رمزارزی دستی (USDT/TRX)",'callback_data'=>"wizwizch"]
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
        [['text'=>'⏱ مهلت فاکتورها و محدودیت رسید تکراری',
          'callback_data'=>'paymentPolicySettings']],
        [['text'=>'👥 دسترسی درگاه‌ها بر اساس گروه کاربران',
          'callback_data'=>'gatewayAudienceSettings']],
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

            if (($botState['cartToCartState'] ?? 'off') == "off" && ($botState['giftCardState'] ?? 'off') == "off" && ($botState['manualUsdtState'] ?? 'off') == "off" && ($botState['walletState'] ?? 'off') == "off") {
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

            if (($botState['cartToCartState'] ?? 'off') == "off" && ($botState['giftCardState'] ?? 'off') == "off" && ($botState['manualUsdtState'] ?? 'off') == "off" && ($botState['walletState'] ?? 'off') == "off") {
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
require_once __DIR__ . '/src/Finance/Discounts.php';
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
    require_once __DIR__ . '/src/Subscription/AccountDetails.php';
    return \AchBot\Subscription\AccountDetails\findOrder($id, $userId);
}
function getPlanFullName($fid, $fallbackId) {
    require_once __DIR__ . '/src/Subscription/AccountDetails.php';
    return \AchBot\Subscription\AccountDetails\getPlanFullName($fid, $fallbackId);
}
function getServerConfigById($server_id) {
    require_once __DIR__ . '/src/Subscription/AccountDetails.php';
    return \AchBot\Subscription\AccountDetails\getServerConfigById($server_id);
}
function getOrderTrafficInfo($serverType, $server_id, $remark, $uuid, $inbound_id, $rahgozar=false) {
    require_once __DIR__ . '/src/Subscription/AccountDetails.php';
    return \AchBot\Subscription\AccountDetails\getOrderTrafficInfo($serverType, $server_id, $remark, $uuid, $inbound_id, $rahgozar);
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
    require_once __DIR__ . '/src/Subscription/AccountDetails.php';
    return \AchBot\Subscription\AccountDetails\getProtocolNamesRow($protocol, $netType, $security, $rahgozar, $inbound_id);
}
function buildAdminProtocolButtons($protocol, $netType, $security, $rahgozar, $inbound_id) {
    require_once __DIR__ . '/src/Subscription/AccountDetails.php';
    return \AchBot\Subscription\AccountDetails\buildAdminProtocolButtons($protocol, $netType, $security, $rahgozar, $inbound_id);
}
function buildUserProtocolButtons($protocol, $netType, $security, $rahgozar, $inbound_id, $serverType, $botState, $fid, $id) {
    require_once __DIR__ . '/src/Subscription/AccountDetails.php';
    return \AchBot\Subscription\AccountDetails\buildUserProtocolButtons($protocol, $netType, $security, $rahgozar, $inbound_id, $serverType, $botState, $fid, $id);
}
function getUserOrderDetailKeys($id, $offset = 0) {
    require_once __DIR__ . '/src/Subscription/AccountDetails.php';
    return \AchBot\Subscription\AccountDetails\getUserOrderDetailKeys($id, $offset);
}
function getOrderDetailKeys($from_id, $id, $offset = 0) {
    require_once __DIR__ . '/src/Subscription/AccountDetails.php';
    return \AchBot\Subscription\AccountDetails\getOrderDetailKeys($from_id, $id, $offset);
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

function RandomString($count = 20, $type = "all") {
    // Payment hashes are bearer-capability references in hosted URLs and
    // Telegram callbacks. Never derive them from predictable rand()/mt_rand().
    // Twenty alphabet characters provide over 110 bits of online guessing
    // resistance, while fitting the longest supported callback_data (<64 B).
    if($type == "all") $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz123456789';
    elseif($type == "small") $characters = 'abcdef123456789';
    elseif($type == "domain") $characters = 'abcdefghijklmnopqrstuvwxyz';
    else throw new InvalidArgumentException('invalid_random_string_type');
    if (!is_int($count) || $count < 1 || $count > 64) {
        throw new InvalidArgumentException('invalid_random_string_length');
    }

    $randstring = '';
    $lastIndex = strlen($characters) - 1;
    for ($i = 0; $i < $count; $i++) {
        $randstring .= $characters[random_int(0, $lastIndex)];
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
// Post-V9: legacy X-UI/Sanaei/Alireza facades have been removed.
// Marzban remains the sole supported panel for this candidate.
function achbotSupportsPanel($type) {
    return $type === 'marzban';
}

function getMarzbanToken($server_id) {
    require_once __DIR__ . '/src/Panel/Accounts.php';
    return \AchBot\Panel\Accounts\getMarzbanToken($server_id);
}
function getMarzbanJson($server_id, $token = null) {
    require_once __DIR__ . '/src/Panel/Accounts.php';
    return \AchBot\Panel\Accounts\getMarzbanJson($server_id, $token);
}
function getMarzbanUserInfo($server_id, $remark) {
    require_once __DIR__ . '/src/Panel/Accounts.php';
    return \AchBot\Panel\Accounts\getMarzbanUserInfo($server_id, $remark);
}
function getMarzbanUser($server_id, $remark, $token = null) {
    require_once __DIR__ . '/src/Panel/Accounts.php';
    return \AchBot\Panel\Accounts\getMarzbanUser($server_id, $remark, $token);
}
function getMarzbanHosts($server_id) {
    require_once __DIR__ . '/src/Panel/Accounts.php';
    return \AchBot\Panel\Accounts\getMarzbanHosts($server_id);
}
function addMarzbanUser($server_id, $remark, $volume, $days, $plan_id) {
    require_once __DIR__ . '/src/Panel/Accounts.php';
    return \AchBot\Panel\Accounts\addMarzbanUser($server_id, $remark, $volume, $days, $plan_id);
}
function editMarzbanConfig($server_id,$info) {
    require_once __DIR__ . '/src/Panel/Accounts.php';
    return \AchBot\Panel\Accounts\editMarzbanConfig($server_id, $info);
}
function resetMarzbanTraffic($server_id, $remark, $token) {
    require_once __DIR__ . '/src/Panel/Accounts.php';
    return \AchBot\Panel\Accounts\resetMarzbanTraffic($server_id, $remark, $token);
}
function renewMarzbanUUID($server_id,$remark) {
    require_once __DIR__ . '/src/Panel/Accounts.php';
    return \AchBot\Panel\Accounts\renewMarzbanUUID($server_id, $remark);
}
function deleteMarzban($server_id,$remark) {
    require_once __DIR__ . '/src/Panel/Accounts.php';
    return \AchBot\Panel\Accounts\deleteMarzban($server_id, $remark);
}
function changeMarzbanState($server_id,$remark) {
    require_once __DIR__ . '/src/Panel/Accounts.php';
    return \AchBot\Panel\Accounts\changeMarzbanState($server_id, $remark);
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
