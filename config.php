<?php
// config.php is an included library, not a standalone HTTP endpoint.
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/messages/loader.php'; // ACHBOT_TEXT_CATALOG


$mainValues=achbotMessages('legacy_messages');
$buttonValues=achbotMessages('legacy_buttons');
include_once 'settings/jdf.php';
require_once 'baseInfo.php';

// Fail closed before any query or outgoing request; diagnostics contain no inputs.
$bootstrapStage = 'connection';
try {
    $connection = @new mysqli('localhost',$dbUserName,$dbPassword,$dbName);
    if ($connection->connect_error) throw new RuntimeException('bootstrap_connection_failed');
    $bootstrapStage = 'charset';
    if (@$connection->set_charset('utf8mb4') !== true)
        throw new RuntimeException('bootstrap_charset_failed');
} catch (Throwable $ignored) {
    error_log('AChBot bootstrap_unavailable stage=' . $bootstrapStage);
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo \achbotMessage('bot.bootstrap.unavailable');
    exit(1);
}
unset($bootstrapStage);
require_once __DIR__ . '/src/Purchase/Core.php';
require_once __DIR__ . '/src/Finance/PaymentLifecycle.php';
require_once __DIR__ . '/src/Finance/GatewayRuleAdmin.php';
require_once __DIR__ . '/src/Finance/PaymentKeyAdmin.php';
require_once __DIR__.'/src/Admin/Access.php';

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
    global $from_id, $message_id, $update, $admin, $userInfo;
    // AChBot keeps administrator-authored input in the conversation. A callback
    // refers to a bot message; its normal menu cleanup is a different target.
    $incoming=$update->message??null;
    if (is_object($incoming) && empty($incoming->from->is_bot) &&
        isset($incoming->from->id,$incoming->chat->id,$incoming->message_id) &&
        (string)$incoming->from->id===(string)$from_id &&
        (($admin!==null && $from_id==$admin) || !empty($userInfo['isAdmin'])) &&
        (string)($chat_id??$from_id)===(string)$incoming->chat->id &&
        (string)($msg??$message_id)===(string)$incoming->message_id) return null;
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

    die(\achbotMessage('admin_settings.config.check.you_do_not_have_access.816f78fb2c'));

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

// bot.php has already verified the Telegram webhook-secret header.
// Apply the existing IP allowlist before any configuration-time callback.
if (defined('ACHBOT_VERIFIED_WEBHOOK_ENTRYPOINT')) check();
$time = time();
// Other includes (payment return pages / Cron) are not Telegram webhooks.
// Their arbitrary request bodies must never become privileged bot callbacks.
$update = defined('ACHBOT_VERIFIED_WEBHOOK_ENTRYPOINT')
    ? json_decode(file_get_contents("php://input")) : null;
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

// Enforce the same staff level before bootstrap callbacks or outgoing reads.
if (defined('ACHBOT_VERIFIED_WEBHOOK_ENTRYPOINT') &&
    !achbotAdminRequestAllowed(is_string($data??null)?$data:null,is_string($text??null)?$text:null,(string)($userInfo['step']??''))) {
    achbotAdminDeny();exit;
}

$supportID = $botState['supportID'];
$channelLock = $botState['lockChannel'];
$joniedState= bot('getChatMember', ['chat_id' => $channelLock,'user_id' => $from_id])->result->status;

// Channel exemption is dispatched by bot.php AFTER webhook/IP verification.
// No privileged Telegram update may change EXEMPTED_USERS during bootstrap.

// تابع مشترک ساخت درخواست پرداخت
require_once __DIR__ . '/src/Finance/WalletStart.php';
function replaceAllPlaceholdersInMainValues(array $mainValues, array $botState, $userId = '') {
    require_once __DIR__ . '/src/Presentation/Text.php';
    // Canonicalize the historical join slot while it is still template source.
    // Never replace CHANNEL-ID after a literal destination has been inserted.
    if (is_string($mainValues['join_channel_message']??null))
        $mainValues['join_channel_message']=str_replace('CHANNEL-ID','LOCKCHANNEL',$mainValues['join_channel_message']);
    // Match the actual current consumers: two legacy Markdown messages,
    // HTML mandatory-join delivery, and plain agency notices/alerts.
    return \AchBot\Presentation\Text\replacePlaceholders($mainValues, $botState, $userId, [
        'banned'=>'Markdown','send_config_uuid'=>'Markdown',
        'join_channel_message'=>'HTML','agency_request_declined'=>null
    ]);
}
$mainValues = replaceAllPlaceholdersInMainValues($mainValues, $botState, $from_id ?? '');
require_once __DIR__ . '/src/Admin/InstanceContent.php';
$mainValues = achbotInstanceContentValues($mainValues, $botState, $from_id ?? '');

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
    $cooperationUrl = \AchBot\Presentation\Text\configuredDestination($botState,'cooperationUrl',true);
    $helpChannelUrl = \AchBot\Presentation\Text\configuredDestination($botState,'helpChannel');

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
		(($from_id != $admin && $userInfo['isAdmin'] != true && $cooperationUrl!=='') ?
			[['text' => \achbotMessage('admin_settings.config.get_main_keys.button.b7d6aa7173'), 'url' => $cooperationUrl]] : []
		),
		(($from_id != $admin && $userInfo['isAdmin'] != true) ?
			[['text'=>$buttonValues['invite_friends'],'callback_data'=>"inviteFriends"]] : []
		),
        (($botState['searchState']=="on" || $from_id == $admin || $userInfo['isAdmin'] == true)?
            [['text'=>$buttonValues['search_config'],'callback_data'=>"showUUIDLeft"]] : []
        ),
    ]);

    if ($helpChannelUrl!=='') $mainKeys[]=[['text'=>achbotMessage('instance_content.help_button'),'url'=>$helpChannelUrl]];

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
        array_push($mainKeys, [['text' => \achbotMessage('admin_settings.config.get_main_keys.button.11b0188fcb'), 'callback_data' => "searchUsersConfig"]]);
        array_push($mainKeys, [['text' => \achbotMessage('admin_settings.config.get_main_keys.button.d9c51cced4'), 'callback_data' => "managePanel"]]);
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
    global $buttonValues;
    $level=achbotAdminLevel();
    $rows=[
        [['bot_reports','botReports']],
        [['user_reports','userReports'],['message_to_user','messageToSpeceficUser']],
        [['increase_wallet','increaseUserWallet'],['decrease_wallet','decreaseUserWallet']],
        [['create_account','createMultipleAccounts'],['gift_volume_day','giftVolumeAndDay']],
        [['ban_user','banUser'],['unban_user','unbanUser']],
        [['manage_order_database','manageorderdatabase']],
        [['server_settings','serversSetting']],
        [['categories_settings','categoriesSetting'],['plan_settings','backplan']],
        [['discount_settings','discount_codes'],['main_button_settings','mainMenuButtons']],
        [['tickets_list','ticketsList'],['message_to_all','message2All']],
        [['agent_list','agentsList'],['pin_manage_all','pinManageAll']],
        [['admins_list','adminsList']],
        [['__review','attentionInbox']],
        [['gateways_settings','gateWays_Channels'],['bot_settings','botSettings']],
    ];
    $keyboard=[];
    foreach ($rows as $row) {
        $line=[];
        foreach ($row as [$key,$callback]) {
            $capability=$key==='__review'?'review':achbotAdminButtonCapability($key);
            if (!achbotAdminLevelCan($level,$capability)) continue;
            $line[]=['text'=>$key==='__review'?achbotMessage('admin_settings.config.get_admin_keys.button.f5a816a832'):$buttonValues[$key],
                'callback_data'=>$callback];
        }
        if ($line) $keyboard[]=$line;
    }
    $keyboard[]=[['text'=>$buttonValues['back_to_main'],'callback_data'=>'mainMenu']];
    return json_encode(['inline_keyboard'=>$keyboard],JSON_UNESCAPED_UNICODE);
}


/**
 * Legacy BOT_STATES.type is not unique. Serialize all application writes,
 * read the persisted document FOR UPDATE, and change only the intended field.
 * Financially significant audience changes cannot be lost to stale requests.
 */
function achbotMutateBotStates(callable $mutate, ?callable $beforeStateLock = null): bool {
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
        // Privileged gateway callbacks lock their CURRENT actor PK first.
        // Financial owner -> setting readers depend on this lock ordering.
        if ($beforeStateLock !== null) $beforeStateLock($connection);
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
    // The persistence boundary is explicit, not a denylist of retired methods.
    // A future accidental writer cannot resurrect unsupported BOT_STATES flags.
    static $editable = [
        'agencyState','agencyPlanDiscount','individualExistence',
        'sharedExistence','testAccount','changeProtocolState',
        'renewAccountState','renewAutoState','plandelkhahState',
        'switchLocationState','increaseTimeState','increaseVolumeState',
        'requirePhone','requireIranPhone','sellState','botState',
        'searchState',
        'renewConfigLinkState','updateConfigLinkState','qrConfigState',
        'qrSubState','cartToCartAutoAcceptState','cartToCartAutoAcceptType',
        'giftCardState','manualUsdtState','cartToCartState',
        'nextpay','zarinpal','nowPaymentWallet','nowPaymentOther',
        'walletState','updateConnectionState','remark','rewaredTime',
        'cartToCartAutoAcceptTime','gbPrice','dayPrice',
        'rewardChannel','lockChannel','supportID','lastRewardMessage',
        'instanceMessages','cooperationUrl','helpChannel','lockChannelJoinUrl',
        'gatewayAudience',
        'paymentDraftMinutes','paymentManualHours','paymentHostedHours',
        'receiptApprovedMinutes','receiptApprovedOtherMinutes',
        'receiptReviewMinutes','receiptDeclinedMinutes'
    ];
    if (!is_string($field) || !in_array($field,$editable,true)) return false;
    return achbotMutateBotStates(static function(array $state) use($field,$value): array {
        $state[$field]=$value;
        return $state;
    });
}
/**
 * Source-ordered administrator payment availability and cohort changes.
 *
 * Existing in-app BOT_STATES writers remain supported through setSettings().
 * Only signed, fresh private callbacks with CURRENT administrator privileges
 * may reach the new privileged path. The named writer lock is shared with
 * every settings writer; this path additionally persists a GLOBAL Telegram
 * update-id watermark in the same transaction as BOT_STATES. It rejects an
 * older admin's delayed callback even when another admin edited a different
 * payment option in the meantime. No schema change, no provider effect.
 */
function achbotAdminGatewayChange($update,int $actor,int $chat,int $message,
                                  int $primary,string $kind,string $key,?string $value=null): bool {
    global $connection;
    static $flags=[
        'giftCardState','manualUsdtState','cartToCartState','nextpay',
        'zarinpal','nowPaymentWallet','nowPaymentOther','walletState'
    ];
    try {
        if ($actor<=0 || $primary<=0 || $chat!==$actor || $message<=0 ||
            !in_array($kind,['toggle','audience','rule','number'],true))
            throw new RuntimeException('gateway_actor_or_operation_invalid');
        if ($kind==='toggle' && !in_array($key,$flags,true))
            throw new RuntimeException('gateway_toggle_unknown');
        if ($kind==='audience' &&
            !array_key_exists($key,achbotPayUxAudienceMethods()))
            throw new RuntimeException('gateway_audience_unknown');
        if ($kind==='rule' && achbotGatewayRuleCallback($key)===null)
            throw new RuntimeException('gateway_rule_callback_invalid');
        if ($kind==='number' && (achbotGatewayRuleStep($key)===null ||
            $value===null)) throw new RuntimeException('gateway_rule_message_invalid');
        $bound=$kind==='toggle' ? 'changeGateWays'.$key
              : ($kind==='audience' ? 'cycleGatewayAudience_'.$key
              : ($kind==='rule' ? $key : (string)$value));
        if (strlen($bound)>64 || !function_exists('achbotWebhookIngressAuthenticated') ||
            !achbotWebhookIngressAuthenticated())
            throw new RuntimeException('gateway_webhook_not_authenticated');
        if (!function_exists('achbotDirectGiftWebhook'))
            require_once __DIR__.'/src/Subscription/DirectGift.php';
        $frame=achbotDirectGiftWebhook($update,$actor,$chat,$message,
                                       $bound,$kind==='number'?'message':'callback');
        $sourceId=(int)$frame['update_id'];
        // Find the exact actor PK before BEGIN to avoid a REPEATABLE READ
        // snapshot pinned ahead of the first current row lock.
        $findActor=static function(mysqli $db,int $actor): int {
            $stmt=$db->prepare('SELECT id FROM users WHERE userid=? LIMIT 2');
            if (!$stmt) throw new RuntimeException('gateway_actor_find_prepare');
            $stmt->bind_param('i',$actor);
            $ok=$stmt->execute();
            $rows=$ok?$stmt->get_result()->fetch_all(MYSQLI_ASSOC):[];
            $stmt->close();
            if (!$ok || count($rows)!==1 || (int)$rows[0]['id']<=0)
                throw new RuntimeException('gateway_actor_ambiguous');
            return (int)$rows[0]['id'];
        };
        $pk=$findActor($connection,$actor);
        $currentStep=null;
        $authorize=static function(mysqli $db) use(
            $actor,$primary,$pk,$findActor,&$currentStep
        ): void {
            // FIRST read in the transaction: lock CURRENT actor privileges.
            $stmt=$db->prepare('SELECT id,userid,isAdmin,step FROM users WHERE id=? FOR UPDATE');
            if (!$stmt) throw new RuntimeException('gateway_actor_lock_prepare');
            $stmt->bind_param('i',$pk);
            $ok=$stmt->execute();
            $current=$ok?$stmt->get_result()->fetch_assoc():null;
            $stmt->close();
            if (!$ok || !$current || (int)$current['id']!==$pk ||
                (int)$current['userid']!==$actor ||
                ($actor!==$primary && (int)$current['isAdmin']!==1) ||
                (string)$current['step']==='banned' ||
                $findActor($db,$actor)!==$pk)
                throw new RuntimeException('gateway_actor_no_longer_authorized');
            if (!achbotAdminCanLocked($db,$actor,$primary,'gateways'))
                throw new RuntimeException('gateway_actor_no_longer_authorized');
            $currentStep=(string)$current['step'];
        };
        $apply=static function(array $state) use(
            $connection,$kind,$key,$sourceId,$actor,$message,$value,$pk,&$currentStep
        ): array {
            // GET_LOCK in achbotMutateBotStates serializes all in-app writers.
            // setting.type is historically nonunique: refuse ambiguous rows.
            $type='ACHBOT_ADMIN_GATEWAY_SOURCE';
            $stmt=$connection->prepare(
                'SELECT id,value FROM setting WHERE type=? ORDER BY id LIMIT 2');
            if (!$stmt) throw new RuntimeException('gateway_source_find_prepare');
            $stmt->bind_param('s',$type);
            $ok=$stmt->execute();
            $markers=$ok?$stmt->get_result()->fetch_all(MYSQLI_ASSOC):[];
            $stmt->close();
            if (!$ok || count($markers)>1)
                throw new RuntimeException('gateway_source_ambiguous');
            if ($markers) {
                $old=json_decode((string)$markers[0]['value'],true,512,JSON_THROW_ON_ERROR);
                if (!is_array($old) || ($old['version']??null)!==1 ||
                    !is_int($old['last_update_id']??null) ||
                    !is_int($old['actor_id']??null) ||
                    $sourceId<=$old['last_update_id'])
                    throw new RuntimeException('gateway_source_stale_or_invalid');
            }
            if ($kind==='toggle') {
                $current=$state[$key]??'off';
                if (!in_array($current,['on','off'],true))
                    throw new RuntimeException('gateway_flag_invalid');
                $state[$key]=$current==='on'?'off':'on';
            } elseif ($kind==='audience') {
                if (isset($state['gatewayRules'])) {
                    $doc=achbotGatewayRulesDecode($state['gatewayRules']);
                    if ($doc===null || array_key_exists($key,$doc['methods']))
                        throw new RuntimeException('gateway_legacy_policy_superseded');
                }
                $map=$state['gatewayAudience']??[];
                if (!is_array($map))
                    throw new RuntimeException('gateway_audience_map_invalid');
                $modes=array_keys(achbotPayUxAudienceModes());
                $now=$map[$key]??'all';
                $position=is_string($now) ? array_search($now,$modes,true) : false;
                // A corrupted method cannot silently become unrestricted.
                $map[$key]=$position===false?'off'
                    :$modes[($position+1)%count($modes)];
                $state['gatewayAudience']=$map;
            }
            $newStep=null;
            if ($kind==='rule' || $kind==='number') {
                $state=achbotGatewayRuleMutate($state,$kind,$key,$value,
                    $currentStep??'',$sourceId,$newStep);
            }
            if ($newStep!==null) {
                $stepStmt=$connection->prepare('UPDATE users SET step=? WHERE id=? AND step=?');
                if (!$stepStmt) throw new RuntimeException('gateway_rule_step_prepare');
                $stepStmt->bind_param('sis',$newStep,$pk,$currentStep);
                $stepOk=$stepStmt->execute();$affected=$stepStmt->affected_rows;
                $stepStmt->close();
                if (!$stepOk || $affected!==1)
                    throw new RuntimeException('gateway_rule_step_stale');
            }
            $markerValue=json_encode([
                'version'=>1,'last_update_id'=>$sourceId,'actor_id'=>$actor,
                'last_message_id'=>$message,'kind'=>$kind,'key'=>$key
            ],JSON_THROW_ON_ERROR);
            if ($markers) {
                $id=(int)$markers[0]['id'];$before=(string)$markers[0]['value'];
                $stmt=$connection->prepare('UPDATE setting SET value=?
                    WHERE id=? AND type=? AND value=?');
                if (!$stmt) throw new RuntimeException('gateway_source_update_prepare');
                $stmt->bind_param('siss',$markerValue,$id,$type,$before);
            } else {
                $stmt=$connection->prepare('INSERT INTO setting(type,value) VALUES (?,?)');
                if (!$stmt) throw new RuntimeException('gateway_source_insert_prepare');
                $stmt->bind_param('ss',$type,$markerValue);
            }
            $ok=$stmt->execute();$changed=$stmt->affected_rows;$stmt->close();
            if (!$ok || $changed!==1)
                throw new RuntimeException('gateway_source_commit_unconfirmed');
            return $state;
        };
        return achbotMutateBotStates($apply,$authorize);
    } catch (Throwable $error) {
        $reason=($error instanceof RuntimeException &&
            preg_match('/\A[a-z0-9_]{1,100}\z/D',$error->getMessage())===1)
            ? $error->getMessage() : 'unclassified';
        error_log('achbot_admin_gateway_change_deferred kind='.$kind.
            ' reason='.$reason.' code='.(int)$error->getCode().
            ' class='.get_class($error));
        return false;
    }
}

function getRejectedAgentList(){
    global $connection, $mainValues, $buttonValues;
    
    $stmt = $connection->prepare("SELECT * FROM `users` WHERE `is_agent` = 2");
    $stmt->execute();
    $list = $stmt->get_result();
    $stmt->close();
    
    if($list->num_rows>0){
        $keys = array();
        $keys[] = [['text'=>\achbotMessage('admin_settings.config.get_rejected_agent_list.button.df8e33a61a'),'callback_data'=>"wizwizch"],['text'=>\achbotMessage('admin_settings.config.get_rejected_agent_list.button.c9b4331f4f'),'callback_data'=>'wizwizch'],['text'=>\achbotMessage('admin_settings.config.get_rejected_agent_list.button.2dc9dcf8cd'),'callback_data'=>"wizwizch"]];
        while($row = $list->fetch_assoc()){
            $userId = $row['userid'];
            
            $userDetail = bot('getChat',['chat_id'=>$userId])->result;
            $fullName = $userDetail->first_name . " " . $userDetail->last_name;
            
            $keys[] = [['text'=>\achbotMessage('admin_settings.config.get_rejected_agent_list.button.1d456424c3'),'callback_data'=>"releaseRejectedAgent" . $userId],['text'=>$fullName,'callback_data'=>"wizwizch"],['text'=>$userId,'callback_data'=>"wizwizch"]];
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
            ['text'=>\achbotMessage('admin_settings.config.get_agent_details.button.5a788e64cb'),'callback_data'=>'wizwizch']
            ],
        [
            ['text'=>"(" . $yesterdayIncome['count'] . ") " . number_format($yesterdayIncome['total']),'callback_data'=>"wizwizch"],
            ['text'=>\achbotMessage('admin_settings.config.get_agent_details.button.4ad1c24edd'),'callback_data'=>"wizwizch"]
            ],
        [
            ['text'=>"(" . $lastWeekIncome['count'] . ") " . number_format($lastWeekIncome['total']),'callback_data'=>"wizwizch"],
            ['text'=>\achbotMessage('admin_settings.config.get_agent_details.button.b939f33fef'),'callback_data'=>"wizwizch"]
            ],
        [
            ['text'=>"(" . $lastMonthIncome['count'] . ") " . number_format($lastMonthIncome['total']),'callback_data'=>"wizwizch"],
            ['text'=>\achbotMessage('admin_settings.config.get_agent_details.button.12c33cffe7'),'callback_data'=>"wizwizch"]
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
    
    $keys[] = [['text'=>\achbotMessage('admin_settings.config.get_agents_list.button.59ca629220'),'callback_data'=>"wizwizch"],['text'=>\achbotMessage('admin_settings.config.get_agents_list.button.9f7ae9d87f'),'callback_data'=>"wizwizch"],['text'=>\achbotMessage('admin_settings.config.get_agents_list.button.83a20e2f87'),'callback_data'=>"wizwizch"],['text'=>\achbotMessage('admin_settings.config.get_agents_list.button.1882d627a1'),'callback_data'=>"wizwizch"],['text'=>\achbotMessage('admin_settings.config.get_rejected_agent_list.button.2dc9dcf8cd'),'callback_data'=>"wizwizch"]];
    if($agentList->num_rows > 0){
        while($row = $agentList->fetch_assoc()){
            $userId = $row['userid'];
            
            $userDetail = bot('getChat',['chat_id'=>$userId])->result;
            $userUserName = $userDetail->username;
            $fullName = $userDetail->first_name . " " . $userDetail->last_name;
            $joinedDate = jdate("Y-m-d H:i",$row['agent_date']);

            $keys[] = [['text'=>\achbotMessage('admin_settings.config.get_agents_list.button.2b2966a54e'),'callback_data'=>"removeAgent" . $userId],['text'=>\achbotMessage('admin_settings.config.get_agents_list.button.bee87e34d1'),'callback_data'=>"agentPercentDetails" . $userId],['text'=>$joinedDate,'callback_data'=>"wizwizch"],['text'=>$fullName,'callback_data'=>"agentDetails" . $userId],['text'=>$userId,'callback_data'=>"agentDetails" . $userId]];
        }
    }
    if($offset == 0 && $limit <= $agentList->num_rows)
        $keys[] = [
            ['text'=>\achbotMessage('admin_settings.config.get_agents_list.button.6b88de15f3'),'callback_data'=>"nextAgentList" . ($offset + $limit)]
            ];
    elseif($limit <= $agentList->num_rows)
        $keys[] = [
            ['text'=>\achbotMessage('admin_settings.config.get_agents_list.button.6b88de15f3'),'callback_data'=>"nextAgentList" . ($offset + $limit)],
            ['text'=>\achbotMessage('admin_settings.config.get_agents_list.button.78d753bffb'),'callback_data'=>"nextAgentList" . ($offset - $limit)]
            ];
    elseif($offset != 0)
        $keys[] = [
            ['text'=>\achbotMessage('admin_settings.config.get_agents_list.button.78d753bffb'),'callback_data'=>"nextAgentList" . ($offset - $limit)]
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
        $keys[] = [['text'=>\achbotMessage('admin_settings.config.get_marzban_panel_group_keys.button.62b58eccfe') . $group['name'], 'callback_data'=>$cb]];
    }
    $none = $mode === 'add'
        ? 'selectNewPanelGroup_0'
        : "setServerPanelGroup_{$serverId}_0";
    $keys[] = [['text'=>\achbotMessage('admin_settings.config.get_marzban_panel_group_keys.button.9bdffdaedc'), 'callback_data'=>$none]];
    $keys[] = [['text'=>$buttonValues['back_button'] ?? \achbotMessage('admin_settings.config.get_marzban_panel_group_keys.text.b35a2352f5'),
                'callback_data'=>$mode === 'add' ? 'serversSetting' : "showServerSettings{$serverId}_0"]];
    return json_encode(['inline_keyboard'=>$keys], JSON_UNESCAPED_UNICODE);
}
function getMarzbanPanelGroupsManagementKeys() {
    global $connection, $buttonValues;
    $keys = [];
    foreach (achbotMarzbanPanelGroups($connection) as $group) {
        $id = (int)$group['id'];
        $keys[] = [
            ['text'=>\achbotMessage('admin_settings.config.get_marzban_panel_group_keys.button.62b58eccfe') . $group['name'], 'callback_data'=>'wizwizch'],
            ['text'=>\achbotMessage('admin_settings.config.get_marzban_panel_groups_management_keys.button.eb25cfbc94'), 'callback_data'=>"deleteMarzbanPanelGroup_$id"],
        ];
    }
    if (!$keys) $keys[] = [['text'=>\achbotMessage('admin_settings.config.get_marzban_panel_groups_management_keys.button.d376787114'), 'callback_data'=>'wizwizch']];
    $keys[] = [['text'=>\achbotMessage('admin_settings.config.get_marzban_panel_groups_management_keys.button.a6b2138672'), 'callback_data'=>'addMarzbanPanelGroup']];
    $keys[] = [['text'=>$buttonValues['back_button'] ?? \achbotMessage('admin_settings.config.get_marzban_panel_group_keys.text.b35a2352f5'), 'callback_data'=>'serversSetting']];
    return json_encode(['inline_keyboard'=>$keys], JSON_UNESCAPED_UNICODE);
}
function getServerConfigKeys($serverId,$offset = 0){
    global $connection, $buttonValues;
    $serverId=(int)$serverId;$offset=max(0,(int)$offset);
    $stmt=$connection->prepare("SELECT * FROM server_info WHERE id=?");
    $stmt->bind_param('i',$serverId);$stmt->execute();
    $info=$stmt->get_result()->fetch_assoc();$stmt->close();
    $stmt=$connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param('i',$serverId);$stmt->execute();
    $config=$stmt->get_result()->fetch_assoc();$stmt->close();
    $back=[['text'=>$buttonValues['back_button'],'callback_data'=>'nextServerPage'.$offset]];
    if (!$info || !achbotSupportsPanel($config['type']??null)) {
        return json_encode(['inline_keyboard'=>[
            [['text'=>\achbotMessage('admin_settings.config.get_server_config_keys.button.a726d3f27d'),'callback_data'=>'wizwizch']],$back
        ]],JSON_UNESCAPED_UNICODE);
    }
    if ($config['type']==='pasarguard') {
        require_once __DIR__.'/src/Panel/ProviderAdmin.php';
        return achbotPgServerAdminKeys($info,$config,$offset);
    }
    $id=(int)$info['id'];$group=achbotMarzbanGroup($connection,$id);
    $keys=[
        [['text'=>$config['panel_url'],'callback_data'=>'wizwizch']],
        [['text'=>$info['title'],'callback_data'=>'editServerName'.$id],
         ['text'=>\achbotMessage('admin_settings.config.get_server_config_keys.button.2f6b3d0d80'),'callback_data'=>'wizwizch']],
        [['text'=>$info['flag'],'callback_data'=>'editServerFlag'.$id],
         ['text'=>\achbotMessage('admin_settings.config.get_server_config_keys.button.d004e8d654'),'callback_data'=>'wizwizch']],
        [['text'=>\achbotMessage('admin_settings.management.capacity',['value'=>$info['ucount']]),'callback_data'=>'editServerMax'.$id]],
        [['text'=>\achbotMessage('admin_settings.management.remark',['value'=>$info['remark']]),'callback_data'=>'editServerRemark'.$id]],
        [['text'=>$group['name']??\achbotMessage('admin_settings.config.get_server_config_keys.text.176dbab285'),'callback_data'=>'editMarzbanGroup'.$id],
         ['text'=>\achbotMessage('admin_settings.config.get_server_config_keys.button.e2c055db59'),'callback_data'=>'wizwizch']],
        [['text'=>\achbotMessage('admin_settings.management.connection'),'callback_data'=>'changesServerLoginInfo'.$id]],
        [['text'=>\achbotMessage('admin_settings.management.display'),'callback_data'=>'serverDisplay'.$id]],
        [['text'=>\achbotMessage('admin_settings.config.get_server_config_keys.button.7ae29940f8'),'callback_data'=>'editSubLinks'.$id]],
        [['text'=>\achbotMessage('admin_settings.management.plans'),'callback_data'=>'plansList'.$id],
         ['text'=>\achbotMessage('admin_settings.management.add_plan'),'callback_data'=>'newPlanForServer'.$id]],
        [['text'=>\achbotMessage('admin_settings.management.server_delete'),'callback_data'=>'wizwizdeleteserver'.$id]],
        $back
    ];
    return json_encode(['inline_keyboard'=>$keys],JSON_UNESCAPED_UNICODE);
}
function getServerListKeys($offset = 0){
    global $connection, $mainValues, $buttonValues;
    
    $limit = 15;
    $offset=max(0,(int)$offset);
    
    $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `active`=1 ORDER BY `id` ASC LIMIT ? OFFSET ?");
    $stmt->bind_param('ii', $limit, $offset);
    $stmt->execute();
    $cats= $stmt->get_result();
    $stmt->close();


    $keys = array();
    $keys[] = [['text'=>\achbotMessage('admin_settings.config.get_server_list_keys.button.68d7eb08ef'),'callback_data'=>"wizwizch"],['text'=>\achbotMessage('admin_settings.config.get_server_list_keys.button.6badcac8c1'),'callback_data'=>"wizwizch"],['text'=>\achbotMessage('admin_settings.config.get_server_list_keys.button.b55501074d'),'callback_data'=>"wizwizch"],['text'=>\achbotMessage('admin_settings.config.get_server_list_keys.button.0b29754285'),'callback_data'=>"wizwizch"]];
    if($cats->num_rows == 0){
        $keys[] = [['text'=>\achbotMessage('admin_settings.config.get_server_list_keys.button.5a370aba9d'),'callback_data'=>"wizwizch"]];
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
                ? achbotProviderLabel($serverTypeInfo['type']??'') : \achbotMessage('admin_settings.config.get_server_list_keys.text.3d978ef5e3');
            $keys[] = [['text'=>$state,'callback_data'=>'toggleServerState' . $id . "_" . $offset],['text'=>\achbotMessage('admin_settings.config.get_agents_list.button.bee87e34d1'),'callback_data'=>"showServerSettings" . $id . "_" . $offset],['text'=>$serverType??" ",'callback_data'=>"wizwizch"],['text'=>$cname,'callback_data'=>"showServerSettings" . $id . "_" . $offset]];
        } 
    }
    if($offset == 0 && $cats->num_rows >= $limit){
        $keys[] = [['text'=>\achbotMessage('admin_settings.config.get_agents_list.button.6b88de15f3'),'callback_data'=>"nextServerPage" . ($offset + $limit)]];
    }
    elseif($cats->num_rows >= $limit){
        $keys[] = [
            ['text'=>\achbotMessage('admin_settings.config.get_agents_list.button.6b88de15f3'),'callback_data'=>"nextServerPage" . ($offset + $limit)],
            ['text'=>\achbotMessage('admin_settings.config.get_agents_list.button.78d753bffb'),'callback_data'=>"nextServerPage" . ($offset - $limit)]
            ];
    }
    elseif($offset != 0){
        $keys[] = [['text'=>\achbotMessage('admin_settings.config.get_agents_list.button.78d753bffb'),'callback_data'=>"nextServerPage" . ($offset - $limit)]];
    }
    $keys[] = [['text'=>\achbotMessage('admin_settings.management.add_server'),'callback_data'=>'addManagedServer']];
    $keys[] = [['text'=>\achbotMessage('admin_settings.config.get_server_list_keys.button.def9f141f2'), 'callback_data'=>'manageMarzbanPanelGroups']];
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
    $keys[] = [['text'=>\achbotMessage('admin_settings.config.get_agents_list.button.59ca629220'),'callback_data'=>"wizwizch"],['text'=>\achbotMessage('admin_settings.config.get_categories_keys.button.47ccdcb5f1'),'callback_data'=>"wizwizch"]];
    if($cats->num_rows == 0){
        $keys[] = [['text'=>\achbotMessage('admin_settings.config.get_categories_keys.button.fba2c6927b'),'callback_data'=>"wizwizch"]];
    }else {
        while($cty = $cats->fetch_assoc()){
            $id = $cty['id'];
            $cname = $cty['title'];
            $keys[] = [['text'=>\achbotMessage('admin_settings.config.get_agents_list.button.2b2966a54e'),'callback_data'=>"wizwizcategorydelete$id" . "_" . $offset],['text'=>$cname,'callback_data'=>"wizwizcategoryedit$id" . "_" . $offset]];
        }
    }
    
    if($offset == 0 && $cats->num_rows >= $limit){
        $keys[] = [['text'=>\achbotMessage('admin_settings.config.get_agents_list.button.6b88de15f3'),'callback_data'=>"nextCategoryPage" . ($offset + $limit)]];
    }
    elseif($cats->num_rows >= $limit){
        $keys[] = [
            ['text'=>\achbotMessage('admin_settings.config.get_agents_list.button.6b88de15f3'),'callback_data'=>"nextCategoryPage" . ($offset + $limit)],
            ['text'=>\achbotMessage('admin_settings.config.get_agents_list.button.78d753bffb'),'callback_data'=>"nextCategoryPage" . ($offset - $limit)]
            ];
    }
    elseif($offset != 0){
        $keys[] = [['text'=>\achbotMessage('admin_settings.config.get_agents_list.button.78d753bffb'),'callback_data'=>"nextCategoryPage" . ($offset - $limit)]];
    }
    
    $keys[] = [['text'=>\achbotMessage('admin_settings.config.get_categories_keys.button.5c2a29a6ab'),'callback_data'=>"addNewCategory"]];
    $keys[] = [['text' => $buttonValues['back_button'], 'callback_data' => "managePanel"]];
    return json_encode(['inline_keyboard'=>$keys]);
}
/** Strictly bounded per-instance financial UX policy editor. */
function achbotPaymentPolicyFields(): array {
    return [
        'paymentDraftMinutes'=>['label'=>\achbotMessage('admin_settings.config.payment_policy_fields.text.ca36f51ec1'),'default'=>30,'min'=>5,'max'=>180],
        'paymentManualHours'=>['label'=>\achbotMessage('admin_settings.config.payment_policy_fields.text.9bd7de27ab'),'default'=>6,'min'=>1,'max'=>72],
        'paymentHostedHours'=>['label'=>\achbotMessage('admin_settings.config.payment_policy_fields.text.7763855ebb'),'default'=>6,'min'=>1,'max'=>72],
        'receiptApprovedMinutes'=>['label'=>\achbotMessage('admin_settings.config.payment_policy_fields.text.99467f1d89'),'default'=>10,'min'=>1,'max'=>1440],
        'receiptApprovedOtherMinutes'=>['label'=>\achbotMessage('admin_settings.config.payment_policy_fields.text.c23b59a9e3'),'default'=>60,'min'=>1,'max'=>1440],
        'receiptReviewMinutes'=>['label'=>\achbotMessage('admin_settings.config.payment_policy_fields.text.7b27bba920'),'default'=>360,'min'=>1,'max'=>1440],
        'receiptDeclinedMinutes'=>['label'=>\achbotMessage('admin_settings.config.payment_policy_fields.text.5fea8ef738'),'default'=>3,'min'=>1,'max'=>1440],
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
    $rows[]=[['text'=>\achbotMessage('admin_settings.config.payment_policy_keyboard.button.264663a645'),'callback_data'=>'gateWays_Channels']];
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
            ? $labels[$selected] : \achbotMessage('admin_settings.config.gateway_audience_keyboard.text.256b1382f5');
        $rows[]=[['text'=>$methodLabel.\achbotMessage('admin_settings.config.gateway_audience_keyboard.text.3b2e8119b6').$group,
                  'callback_data'=>'cycleGatewayAudience_'.$name]];
    }
    $rows[]=[['text'=>\achbotMessage('admin_settings.config.payment_policy_keyboard.button.264663a645'),'callback_data'=>'gateWays_Channels']];
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
    $giftSellerStatus = $giftSellerUrl === '' ? \achbotMessage('admin_settings.config.get_gate_ways_keys.text.9fec3d676e') :
        (achbotManualGiftSellerUrlValid($giftSellerUrl) ? \achbotMessage('admin_settings.config.get_gate_ways_keys.text.ad271ae97c') : \achbotMessage('admin_settings.config.get_gate_ways_keys.text.213a608ab9'));
    $cryptoRows=[];
    foreach (['BEP20','TRC20','ERC20'] as $network) {
        $field='manualUsdtWallet'.$network;
        $value=array_key_exists($field,$paymentKeys)
            ? trim((string)$paymentKeys[$field])
            : (((string)($paymentKeys['manualUsdtNetwork'] ?? 'BEP20'))===$network
                ? trim((string)($paymentKeys['manualUsdtWallet'] ?? '')) : '');
        $label=achbotManualUsdtWalletValid($value,$network) ? $value : \achbotMessage('admin_settings.config.get_gate_ways_keys.text.9fec3d676e');
        $cryptoRows[]=[
            ['text'=>$label,'callback_data'=>'changePaymentKeys'.$field],
            ['text'=>\achbotMessage('admin_settings.config.get_gate_ways_keys.button.e1971f4d7a').$network,'callback_data'=>'wizwizch']
        ];
    }
    $trxWallet=trim((string)($paymentKeys['manualTrxWallet']??''));
    $cryptoRows[]=[
        ['text'=>achbotManualTronAddressValid($trxWallet) ? $trxWallet : \achbotMessage('admin_settings.config.get_gate_ways_keys.text.9fec3d676e'),
         'callback_data'=>'changePaymentKeysmanualTrxWallet'],
        ['text'=>\achbotMessage('admin_settings.config.get_gate_ways_keys.button.99a296169c'),'callback_data'=>'wizwizch']
    ];
    $rate=achbotUsdtRate($paymentKeys);
    $rateRows=[[
        ['text'=>$rate!==null ? number_format($rate).\achbotMessage('admin_settings.config.get_gate_ways_keys.text.2cc98f7f7c') :
            \achbotMessage('admin_settings.config.get_gate_ways_keys.text.8da09df26e'),
         'callback_data'=>'changePaymentKeysmanualUsdtRateToman'],
        ['text'=>\achbotMessage('admin_settings.config.get_gate_ways_keys.text.f87b607b2d'),'callback_data'=>'wizwizch']
    ]];
    $cryptoRows[]=[
        ['text'=>(!empty($paymentKeys['manualTrxRateToman']) ?
            number_format((int)$paymentKeys['manualTrxRateToman']).\achbotMessage('admin_settings.config.get_gate_ways_keys.text.2cc98f7f7c') : \achbotMessage('admin_settings.config.get_gate_ways_keys.text.9fec3d676e')),
         'callback_data'=>'changePaymentKeysmanualTrxRateToman'],
        ['text'=>\achbotMessage('admin_settings.config.get_gate_ways_keys.button.2f0a69097a'),'callback_data'=>'wizwizch']
    ];
    return json_encode(['inline_keyboard'=>[
        [
            ['text'=>(!empty($paymentKeys['bankAccount'])?$paymentKeys['bankAccount']:" "),'callback_data'=>"changePaymentKeysbankAccount"],
            ['text'=>\achbotMessage('admin_settings.config.get_gate_ways_keys.button.7879419f7f'),'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>(!empty($paymentKeys['holderName'])?$paymentKeys['holderName']:" "),'callback_data'=>"changePaymentKeysholderName"],
            ['text'=>\achbotMessage('admin_settings.config.get_gate_ways_keys.button.b4ed07bc66'),'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$giftSellerStatus,'callback_data'=>"changePaymentKeysgiftCardUrl"],
            ['text'=>\achbotMessage('admin_settings.config.get_gate_ways_keys.button.ae176aeaf4'),'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>achbotPaymentKeyAdminMerchantLabel($paymentKeys,'nowpayment'),'callback_data'=>"changePaymentKeysnowpayment"],
            ['text'=>\achbotMessage('admin_settings.config.get_gate_ways_keys.button.480677fee4'),'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>achbotPaymentKeyAdminMerchantLabel($paymentKeys,'zarinpal'),'callback_data'=>"changePaymentKeyszarinpal"],
            ['text'=>\achbotMessage('admin_settings.config.get_gate_ways_keys.button.fd8662735a'),'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>achbotPaymentKeyAdminMerchantLabel($paymentKeys,'nextpay'),'callback_data'=>"changePaymentKeysnextpay"],
            ['text'=>\achbotMessage('admin_settings.config.get_gate_ways_keys.button.02de54ccc3'),'callback_data'=>"wizwizch"]
        ],
        ...$rateRows,
        ...$cryptoRows,
        [
            ['text'=>$giftCardState,'callback_data'=>"changeGateWaysgiftCardState"],
            ['text'=>\achbotMessage('admin_settings.config.get_gate_ways_keys.button.d71108ddb3'),'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$manualUsdtState,'callback_data'=>"changeGateWaysmanualUsdtState"],
            ['text'=>\achbotMessage('admin_settings.config.get_gate_ways_keys.button.986ea8b25b'),'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$cartToCartState,'callback_data'=>"changeGateWayscartToCartState"],
            ['text'=>\achbotMessage('admin_settings.config.get_gate_ways_keys.button.04029e7ea7'),'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$nextpay,'callback_data'=>"changeGateWaysnextpay"],
            ['text'=>\achbotMessage('admin_settings.config.get_gate_ways_keys.button.26003a476f'),'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$zarinpal,'callback_data'=>"changeGateWayszarinpal"],
            ['text'=>\achbotMessage('admin_settings.config.get_gate_ways_keys.button.c0199d5243'),'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$nowPaymentWallet,'callback_data'=>"changeGateWaysnowPaymentWallet"],
            ['text'=>\achbotMessage('admin_settings.config.get_gate_ways_keys.button.6e3e91d426'),'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$nowPaymentOther,'callback_data'=>"changeGateWaysnowPaymentOther"],
            ['text'=>\achbotMessage('admin_settings.config.get_gate_ways_keys.button.a0c8498d77'),'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$walletState,'callback_data'=>"changeGateWayswalletState"],
            ['text'=>\achbotMessage('admin_settings.config.get_gate_ways_keys.button.6295c35212'),'callback_data'=>"wizwizch"]
        ],
		[
			['text'=>$supportID, 'callback_data' => 'editSupportID'],
			['text'=>\achbotMessage('admin_settings.config.get_gate_ways_keys.button.179f3c4369'),'callback_data'=>'wizwizch']
		],
        [
            ['text'=>$rewaredChannel,'callback_data'=>'editRewardChannel'],
            ['text'=>\achbotMessage('admin_settings.config.get_gate_ways_keys.button.536c328504'),'callback_data'=>'wizwizch']
		],
        [
            ['text'=>$lockChannel,'callback_data'=>'editLockChannel'],
            ['text'=>\achbotMessage('admin_settings.config.get_gate_ways_keys.button.ea01411739'),'callback_data'=>'wizwizch']
		],
		[
			['text'=>\achbotMessage('admin_settings.config.get_gate_ways_keys.button.16aec30ea0'),'callback_data'=>"addExemptUser"]
		],
        [['text'=>\achbotMessage('admin_settings.config.get_gate_ways_keys.button.fff77fef22'),
          'callback_data'=>'paymentPolicySettings']],
        [['text'=>\achbotMessage('admin_settings.config.get_gate_ways_keys.button.2110b39744'),
          'callback_data'=>'gr|l']],
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
    $updateConnectionState = $botState['updateConnectionState']=="robot"?\achbotMessage('admin_settings.config.get_bot_setting_keys.text.0fbe848798'):\achbotMessage('admin_settings.config.get_bot_setting_keys.text.c840773241');
    $rewaredTime = ($botState['rewaredTime']??0) . \achbotMessage('admin_settings.config.get_bot_setting_keys.text.69419edd93');
    switch($botState['remark']){
        case "digits":
            $remarkType = \achbotMessage('admin_settings.config.get_bot_setting_keys.remark_type.af25c56d42');
            break;
        case "manual":
            $remarkType = \achbotMessage('admin_settings.config.get_bot_setting_keys.remark_type.af52103a2f');
            break;
        default:
            $remarkType = \achbotMessage('admin_settings.config.get_bot_setting_keys.remark_type.8ede2b5100');
            break;
    }
    
    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'PAYMENT_KEYS'");
    $stmt->execute();
    $paymentKeys = $stmt->get_result()->fetch_assoc()['value'];
    if(!is_null($paymentKeys)) $paymentKeys = json_decode($paymentKeys,true);
    else $paymentKeys = array();
    $stmt->close();
    return json_encode(['inline_keyboard'=>[
        [['text'=>achbotMessage('instance_content.settings_button'),'callback_data'=>'ic|m']],
        [
            ['text'=>\achbotMessage('admin_settings.config.get_bot_setting_keys.button.f386be17f5'),'callback_data'=>"inviteSetting"]
            ],
        [
            ['text'=> $updateConnectionState,'callback_data'=>"changeUpdateConfigLinkState"],
            ['text'=>\achbotMessage('admin_settings.config.get_bot_setting_keys.button.fc7f747159'),'callback_data'=>"wizwizch"]
            ],
        [
            ['text'=> $agency,'callback_data'=>"changeBotagencyState"],
            ['text'=>\achbotMessage('admin_settings.config.get_bot_setting_keys.button.3b878712ee'),'callback_data'=>"wizwizch"]
            ],
        [
            ['text'=> $agencyPlanDiscount,'callback_data'=>"changeBotagencyPlanDiscount"],
            ['text'=>\achbotMessage('admin_settings.config.get_bot_setting_keys.button.c2df8234c5'),'callback_data'=>"wizwizch"]
            ],
        [
            ['text'=>$individualExistence,'callback_data'=>"changeBotindividualExistence"],
            ['text'=>\achbotMessage('admin_settings.config.get_bot_setting_keys.button.546c2c8acc'),'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$sharedExistence,'callback_data'=>"changeBotsharedExistence"],
            ['text'=>\achbotMessage('admin_settings.config.get_bot_setting_keys.button.bbc39b9150'),'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$testAccount,'callback_data'=>"changeBottestAccount"],
            ['text'=>\achbotMessage('admin_settings.config.get_bot_setting_keys.button.11ee94f26d'),'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$changeProtocole,'callback_data'=>"changeBotchangeProtocolState"],
            ['text'=>\achbotMessage('admin_settings.config.get_bot_setting_keys.button.5705eee73b'),'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$renewAccount,'callback_data'=>"changeBotrenewAccountState"],
            ['text'=>\achbotMessage('admin_settings.config.get_bot_setting_keys.button.df1c62b99e'),'callback_data'=>"wizwizch"]
        ],
        [
            ['text' => $renewAuto, 'callback_data' => "changeBotrenewAutoState"],
            ['text'=>\achbotMessage('admin_settings.config.get_bot_setting_keys.button.f9a77f614a'),'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$plandelkhahwiz,'callback_data'=>"changeBotplandelkhahState"],
            ['text'=>\achbotMessage('admin_settings.config.get_bot_setting_keys.button.8ef9c2184e'),'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$switchLocation,'callback_data'=>"changeBotswitchLocationState"],
            ['text'=>\achbotMessage('admin_settings.config.get_bot_setting_keys.button.c6b7f26615'),'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$increaseTime,'callback_data'=>"changeBotincreaseTimeState"],
            ['text'=>\achbotMessage('admin_settings.config.get_bot_setting_keys.button.070602773a'),'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$increaseVolume,'callback_data'=>"changeBotincreaseVolumeState"],
            ['text'=>\achbotMessage('admin_settings.config.get_bot_setting_keys.button.4bbbd988ad'),'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$requirePhone,'callback_data'=>"changeBotrequirePhone"],
            ['text'=>\achbotMessage('admin_settings.config.get_bot_setting_keys.button.bb9acf5637'),'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$requireIranPhone,'callback_data'=>"changeBotrequireIranPhone"],
            ['text'=>\achbotMessage('admin_settings.config.get_bot_setting_keys.button.354012a9d5'),'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$sellState,'callback_data'=>"changeBotsellState"],
            ['text'=>\achbotMessage('admin_settings.config.get_bot_setting_keys.button.3cd98f07be'),'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$robotState,'callback_data'=>"changeBotbotState"],
            ['text'=>\achbotMessage('admin_settings.config.get_bot_setting_keys.button.5a3a8ecb3c'),'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$searchState,'callback_data'=>"changeBotsearchState"],
            ['text'=>\achbotMessage('admin_settings.config.get_bot_setting_keys.button.c56b392566'),'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$renewConfigLink,'callback_data'=>"changeBotrenewConfigLinkState"],
            ['text'=>\achbotMessage('admin_settings.config.get_bot_setting_keys.button.242b408ae2'),'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$updateConfigLink,'callback_data'=>"changeBotupdateConfigLinkState"],
            ['text'=>\achbotMessage('admin_settings.config.get_bot_setting_keys.button.fb0475aeb4'),'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$qrConfig,'callback_data'=>"changeBotqrConfigState"],
            ['text'=>\achbotMessage('admin_settings.config.get_bot_setting_keys.button.59be548183'),'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$qrSub,'callback_data'=>"changeBotqrSubState"],
            ['text'=>\achbotMessage('admin_settings.config.get_bot_setting_keys.button.ae54bb8cbe'),'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$remarkType,'callback_data'=>"changeConfigRemarkType"],
            ['text'=>\achbotMessage('admin_settings.config.get_bot_setting_keys.button.8855cd3ee5'),'callback_data'=>"wizwizch"]
        ],
        [
            ['text'=>$rewaredTime,'callback_data'=>'editRewaredTime'],
            ['text'=>\achbotMessage('admin_settings.config.get_bot_setting_keys.button.2d0dc535a6'), 'callback_data'=>'wizwizch']
            ],
        [
            ['text'=>$botState['cartToCartAutoAcceptState']=="on"?$buttonValues['on']:$buttonValues['off'],'callback_data'=>"changeBotcartToCartAutoAcceptState"],
            ['text'=>\achbotMessage('admin_settings.config.get_bot_setting_keys.button.f5d3c64f2a'),'callback_data'=>"wizwizch"]
        ],
        ($botState['cartToCartAutoAcceptState']=="on"?[
            ['text'=>($botState['cartToCartAutoAcceptType'] == "0"?\achbotMessage('admin_settings.config.get_bot_setting_keys.text.62c92fb90c'):($botState['cartToCartAutoAcceptType'] == "1"?\achbotMessage('admin_settings.config.get_bot_setting_keys.text.5bf4b36161'):\achbotMessage('admin_settings.config.get_bot_setting_keys.text.15270886ea'))),'callback_data'=>"changeBotcartToCartAutoAcceptType"],
            ['text'=>\achbotMessage('admin_settings.config.get_bot_setting_keys.button.aaeffd7e20'),'callback_data'=>"wizwizch"]
        ]:[]),
        ($botState['cartToCartAutoAcceptState']=="on"?[
            ['text'=>($botState['cartToCartAutoAcceptTime']??"10") . \achbotMessage('admin_settings.config.get_bot_setting_keys.text.5e2c83ade1'),'callback_data'=>"editcartToCartAutoAcceptTime"],
            ['text'=>\achbotMessage('admin_settings.config.get_bot_setting_keys.button.333513e744'),'callback_data'=>"wizwizch"]
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
            $userId = isset($row['user_id']) ? $row['user_id'] : (isset($row['userid']) ? $row['userid'] : \achbotMessage('admin_settings.config.send_chunked_messages.text.e2f79e5b60'));
            $amount = number_format($row['wallet'] ?? $row['total_orders'] ?? $row['total_paid']);
            
            $text .= \achbotMessage('admin_settings.config.send_chunked_messages.text.833406bb32', ['userId' => $userId]);
            
            if (isset($row['wallet'])) {
                $text .= \achbotMessage('admin_settings.config.send_chunked_messages.text.60cf33455d', ['amount' => $amount]);
            } elseif (isset($row['total_orders'])) {
                $text .= \achbotMessage('admin_settings.config.send_chunked_messages.text.5a5302e156', ['amount' => $amount]);
            } else {
                $text .= \achbotMessage('admin_settings.config.send_chunked_messages.text.bffd2d6f62', ['amount' => $amount]);
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
            [['text' => \achbotMessage('admin_settings.config.get_bot_report_keys.button.6d1fec5b8b'), 'callback_data' => 'stats_users']],
            [['text' => \achbotMessage('admin_settings.config.get_bot_report_keys.button.2ef1b34354'), 'callback_data' => 'stats_orders']],
            [['text' => \achbotMessage('admin_settings.config.get_bot_report_keys.button.adb2cb111d'), 'callback_data' => 'stats_sales']],
            [['text' => \achbotMessage('admin_settings.config.get_bot_report_keys.button.255cc89ba7'), 'callback_data' => 'managePanel']]
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
                sendChunkedMessages($users, \achbotMessage('admin_settings.config.get_bot_report_keys.text.e9de3103c2'));
            } elseif ($data == 'top_50_orders') {
                sendChunkedMessages($users, \achbotMessage('admin_settings.config.get_bot_report_keys.text.cc383ee7c0'));
            } elseif ($data == 'top_50_users_pays') {
                sendChunkedMessages($users, \achbotMessage('admin_settings.config.get_bot_report_keys.text.178803da84'));
            }
        } else {
            sendMessage(\achbotMessage('admin_settings.config.get_bot_report_keys.message.eafbeaf37f'), null, "html", $from_id);
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
                $text = \achbotMessage('admin_settings.config.get_bot_report_keys.text.900e008efe');
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
                    $text .= \achbotMessage('admin_settings.config.send_chunked_messages.text.833406bb32', ['userId' => $userId]);
                    $text .= \achbotMessage('admin_settings.config.get_bot_report_keys.text.fa6bc48623', ['totalOrders' => $totalOrders]);
                    $text .= \achbotMessage('admin_settings.config.get_bot_report_keys.text.9a3a995798', ['totalPaid' => $totalPaid]);
                    $text .= \achbotMessage('admin_settings.config.get_bot_report_keys.text.8fdd85c8a9', ['formattedDate' => $formattedDate]);
                }
                sendMessage($text, null, "html", $from_id);
            }
        } else {
            sendMessage(\achbotMessage('admin_settings.config.get_bot_report_keys.message.3bb6042c9e'), null, "html", $from_id);
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
            [['text' => "$allUsers", 'callback_data' => 'wizwizch'], ['text' => \achbotMessage('admin_settings.config.get_bot_report_keys.button.674417d275'), 'callback_data' => 'wizwizch']],
            [['text' => "$allOrders", 'callback_data' => 'wizwizch'], ['text' => \achbotMessage('admin_settings.config.get_bot_report_keys.button.a99b0b7e93'), 'callback_data' => 'wizwizch']],
            [['text' => "$usersWithOrders", 'callback_data' => 'wizwizch'], ['text' => \achbotMessage('admin_settings.config.get_bot_report_keys.button.1dec5ab169'), 'callback_data' => 'wizwizch']],
            [['text' => "$usersWithoutOrders", 'callback_data' => 'wizwizch'], ['text' => \achbotMessage('admin_settings.config.get_bot_report_keys.button.31f6ac9005'), 'callback_data' => 'wizwizch']],
            [['text' => \achbotMessage('admin_settings.config.get_bot_report_keys.button.db5e8c8ec7', ['walletSum' => $walletSum]), 'callback_data' => 'wizwizch'], ['text' => \achbotMessage('admin_settings.config.get_bot_report_keys.button.c86863d416'), 'callback_data' => 'wizwizch']],
            [['text' => "$agentUsers", 'callback_data' => 'wizwizch'], ['text' => \achbotMessage('admin_settings.config.get_bot_report_keys.button.b04b9eb38d'), 'callback_data' => 'wizwizch']],
            [['text' => "$newToday", 'callback_data' => 'wizwizch'], ['text' => \achbotMessage('admin_settings.config.get_bot_report_keys.button.ed72e83e54'), 'callback_data' => 'wizwizch']],
            [['text' => "$newWeek", 'callback_data' => 'wizwizch'], ['text' => \achbotMessage('admin_settings.config.get_bot_report_keys.button.4d7b442a7e'), 'callback_data' => 'wizwizch']],
            [['text' => "$newMonth", 'callback_data' => 'wizwizch'], ['text' => \achbotMessage('admin_settings.config.get_bot_report_keys.button.b1030d4034'), 'callback_data' => 'wizwizch']],
            [['text' => "$new3Month", 'callback_data' => 'wizwizch'], ['text' => \achbotMessage('admin_settings.config.get_bot_report_keys.button.93735d95b2'), 'callback_data' => 'wizwizch']],
            [['text' => \achbotMessage('admin_settings.config.get_bot_report_keys.button.f4b4f9e83b'), 'callback_data' => 'top_50_wallet']],
            [['text' => \achbotMessage('admin_settings.config.get_bot_report_keys.button.e484192c7b'), 'callback_data' => 'top_50_orders']],
            [['text' => \achbotMessage('admin_settings.config.get_bot_report_keys.button.7893f23dd9'), 'callback_data' => 'top_50_users_pays']],
            [['text' => \achbotMessage('admin_settings.config.get_bot_report_keys.button.493393af17'), 'callback_data' => 'agent_users_list']],
            [['text' => \achbotMessage('admin_settings.config.get_bot_report_keys.button.c79b329841'), 'callback_data' => 'show_stats_menu']]
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
				$text = \achbotMessage('admin_settings.config.get_bot_report_keys.text.696814adc4');
				foreach ($group as $row) {
					$planId = $row['fileid'];
					$planCount = $row['plan_count'];

					// استخراج نام پلن از جدول server_plans
					$planNameResult = fetchData("SELECT title FROM server_plans WHERE id = ?", ["i", $planId])->fetch_assoc();
					$planName = $planNameResult ? $planNameResult['title'] : \achbotMessage('admin_settings.config.get_bot_report_keys.text.b91a8cfa14');

					// افزودن اطلاعات به متن پیام
					$text .= \achbotMessage('admin_settings.config.get_bot_report_keys.text.549ee88ce8', ['planName' => $planName]);
					$text .= \achbotMessage('admin_settings.config.get_bot_report_keys.text.0ad85f8c38', ['planCount' => $planCount]);
				}

				// ارسال پیام برای هر بخش از دکمه‌ها
				sendMessage($text, null, "html", $from_id);
			}
		} else {
			sendMessage(\achbotMessage('admin_settings.config.get_bot_report_keys.message.7acd771562'), null, "html", $from_id);
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
			$serverButtons[] = ['text' => \achbotMessage('admin_settings.config.get_bot_report_keys.button.e6e825f918', ['serverSubscriptions' => $serverSubscriptions]), 'callback_data' => "server_$serverId"."_subscriptions"];
            $serverButtons[] = ['text' => "$serverTitle", 'callback_data' => "server_$serverId"];
        }
		$serverButtonsChunked = array_chunk($serverButtons, 2);
	
        return json_encode([
            'inline_keyboard' => [
                [['text' => "$serverCount", 'callback_data' => 'wizwizch'], ['text' => \achbotMessage('admin_settings.config.get_bot_report_keys.button.fc04241ae0'), 'callback_data' => 'wizwizch']],
                [['text' => "$categoryCount", 'callback_data' => 'wizwizch'], ['text' => \achbotMessage('admin_settings.config.get_bot_report_keys.button.9c208df2f7'), 'callback_data' => 'wizwizch']],
                [['text' => "$planCount", 'callback_data' => 'wizwizch'], ['text' => \achbotMessage('admin_settings.config.get_bot_report_keys.button.8e7b64fdf1'), 'callback_data' => 'wizwizch']],
				[['text' => \achbotMessage('admin_settings.config.get_bot_report_keys.button.00b2a29f8d'), 'callback_data' => 'wizwizch']],
                [['text' => \achbotMessage('admin_settings.config.get_bot_report_keys.button.16da81c599', ['ordersToday' => $ordersToday]), 'callback_data' => 'wizwizch'], ['text' => \achbotMessage('admin_settings.config.get_bot_report_keys.button.ee7b776736'), 'callback_data' => 'wizwizch']],
                [['text' => \achbotMessage('admin_settings.config.get_bot_report_keys.button.c39f78b6aa', ['ordersWeek' => $ordersWeek]), 'callback_data' => 'wizwizch'], ['text' => \achbotMessage('admin_settings.config.get_bot_report_keys.button.8b95706e47'), 'callback_data' => 'wizwizch']],
                [['text' => \achbotMessage('admin_settings.config.get_bot_report_keys.button.e6153b95de', ['ordersMonth' => $ordersMonth]), 'callback_data' => 'wizwizch'], ['text' => \achbotMessage('admin_settings.config.get_bot_report_keys.button.5c6b1b3ffa'), 'callback_data' => 'wizwizch']],
                [['text' => \achbotMessage('admin_settings.config.get_bot_report_keys.button.71532993a2', ['ordersThreeMonth' => $ordersThreeMonth]), 'callback_data' => 'wizwizch'], ['text' => \achbotMessage('admin_settings.config.get_bot_report_keys.button.ae5e1f25b3'), 'callback_data' => 'wizwizch']],
				[['text' => \achbotMessage('admin_settings.config.get_bot_report_keys.button.6cb051aad0'), 'callback_data' => 'wizwizch']],
                [['text' => \achbotMessage('admin_settings.config.get_bot_report_keys.button.b71f3ef283', ['renewalsToday' => $renewalsToday]), 'callback_data' => 'wizwizch'], ['text' => \achbotMessage('admin_settings.config.get_bot_report_keys.button.465e469987'), 'callback_data' => 'wizwizch']],
                [['text' => \achbotMessage('admin_settings.config.get_bot_report_keys.button.5f0e984ed8', ['renewalsWeek' => $renewalsWeek]), 'callback_data' => 'wizwizch'], ['text' => \achbotMessage('admin_settings.config.get_bot_report_keys.button.3bc1627f8e'), 'callback_data' => 'wizwizch']],
                [['text' => \achbotMessage('admin_settings.config.get_bot_report_keys.button.cbeabf852f', ['renewalsMonth' => $renewalsMonth]), 'callback_data' => 'wizwizch'], ['text' => \achbotMessage('admin_settings.config.get_bot_report_keys.button.3c901f5f22'), 'callback_data' => 'wizwizch']],
                [['text' => \achbotMessage('admin_settings.config.get_bot_report_keys.button.141b60b894', ['renewalsThreeMonth' => $renewalsThreeMonth]), 'callback_data' => 'wizwizch'], ['text' => \achbotMessage('admin_settings.config.get_bot_report_keys.button.e9e6fdc049'), 'callback_data' => 'wizwizch']],
				[['text' => \achbotMessage('admin_settings.config.get_bot_report_keys.button.90081b08bc'), 'callback_data' => 'wizwizch']],
                ...$serverButtonsChunked,
				[['text' => \achbotMessage('admin_settings.config.get_bot_report_keys.button.6a2304367f'), 'callback_data' => 'plan_sales']],
				[['text' => \achbotMessage('admin_settings.config.get_bot_report_keys.button.c79b329841'), 'callback_data' => 'show_stats_menu']]
            ]
        ]);
    }
	
	// بررسی دکمه‌های 50 پرداخت برتر
	// این بخش عمداً شبیه هندلر قبلی نوشته شده تا دکمه قبلی هم بدون تغییر کار کند.
	if (in_array($data, ['top_50_pays', 'top_50_pays_month', 'top_50_pays_week', 'top_50_pays_24h'])) {
		$endTimestamp = time();
		$params = [];
		$title = \achbotMessage('admin_settings.config.get_bot_report_keys.title.cbbecc9fa5');
		$sql = "SELECT price, user_id, request_date FROM pays WHERE state IN ('paid', 'approved', 'auto_cart_approved')";

		if ($data == 'top_50_pays_month') {
			$startTimestamp = strtotime('-30 days');
			$sql .= " AND request_date BETWEEN ? AND ?";
			$params = ["ii", $startTimestamp, $endTimestamp];
			$title = \achbotMessage('admin_settings.config.get_bot_report_keys.title.d8d034561a');
		} elseif ($data == 'top_50_pays_week') {
			$startTimestamp = strtotime('-7 days');
			$sql .= " AND request_date BETWEEN ? AND ?";
			$params = ["ii", $startTimestamp, $endTimestamp];
			$title = \achbotMessage('admin_settings.config.get_bot_report_keys.title.f1fb3841fc');
		} elseif ($data == 'top_50_pays_24h') {
			$startTimestamp = strtotime('-24 hours');
			$sql .= " AND request_date BETWEEN ? AND ?";
			$params = ["ii", $startTimestamp, $endTimestamp];
			$title = \achbotMessage('admin_settings.config.get_bot_report_keys.title.3e55e6fad3');
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
					$payDate = !empty($row['request_date']) ? date("Y-m-d H:i", (int)$row['request_date']) : \achbotMessage('admin_settings.config.get_bot_report_keys.text.f07a81a062');

					$text .= \achbotMessage('admin_settings.config.get_bot_report_keys.text.540ff4775c', ['amount' => $amount]);
					$text .= \achbotMessage('admin_settings.config.send_chunked_messages.text.833406bb32', ['userId' => $userId]);
					$text .= \achbotMessage('admin_settings.config.get_bot_report_keys.text.7c8cf9ce1b', ['payDate' => $payDate]);
				}

				sendMessage($text, null, "html", $from_id);
			}
		} else {
			sendMessage(\achbotMessage('admin_settings.config.get_bot_report_keys.message.51e32ebcd1'), null, "html", $from_id);
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
					return \achbotMessage('admin_settings.config.get_plan_title.unknown__plan.53f2a99925');  // اگر نام پلن یافت نشد
				}
			} else {
				return \achbotMessage('admin_settings.config.get_plan_title.error.54a0e8c17e');
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
		$unfinishedPurchases = fetchData("SELECT COUNT(DISTINCT user_id) as total FROM pays WHERE state IN ('expired_pending', 'expired') AND user_id NOT IN (SELECT user_id FROM pays WHERE state IN ('paid', 'approved', 'auto_cart_approved', 'paid_with_wallet'))")->fetch_assoc()['total'] ?? 0;


        return json_encode([
            'inline_keyboard' => [
                [['text' => number_format($salesTotal) . \achbotMessage('admin_settings.config.get_gate_ways_keys.text.2cc98f7f7c'), 'callback_data' => 'wizwizch'], ['text' => \achbotMessage('admin_settings.config.get_plan_title.button.878127aca8'), 'callback_data' => 'wizwizch']],
                [['text' => number_format($salesToday) . \achbotMessage('admin_settings.config.get_gate_ways_keys.text.2cc98f7f7c'), 'callback_data' => 'wizwizch'], ['text' => \achbotMessage('admin_settings.config.get_plan_title.button.643203ba32'), 'callback_data' => 'wizwizch']],
                [['text' => number_format($salesWeek) . \achbotMessage('admin_settings.config.get_gate_ways_keys.text.2cc98f7f7c'), 'callback_data' => 'wizwizch'], ['text' => \achbotMessage('admin_settings.config.get_plan_title.button.4d01a2bc86'), 'callback_data' => 'wizwizch']],
                [['text' => number_format($salesMonth) . \achbotMessage('admin_settings.config.get_gate_ways_keys.text.2cc98f7f7c'), 'callback_data' => 'wizwizch'], ['text' => \achbotMessage('admin_settings.config.get_plan_title.button.85093d3e2a'), 'callback_data' => 'wizwizch']],
                [['text' => number_format($salesThreeMonth) . \achbotMessage('admin_settings.config.get_gate_ways_keys.text.2cc98f7f7c'), 'callback_data' => 'wizwizch'], ['text' => \achbotMessage('admin_settings.config.get_plan_title.button.4fdc585861'), 'callback_data' => 'wizwizch']],
                [['text' => number_format($salesSixMonth) . \achbotMessage('admin_settings.config.get_gate_ways_keys.text.2cc98f7f7c'), 'callback_data' => 'wizwizch'], ['text' => \achbotMessage('admin_settings.config.get_plan_title.button.a033e66e78'), 'callback_data' => 'wizwizch']],
                [['text' => number_format($salesYear) . \achbotMessage('admin_settings.config.get_gate_ways_keys.text.2cc98f7f7c'), 'callback_data' => 'wizwizch'], ['text' => \achbotMessage('admin_settings.config.get_plan_title.button.1b9161ba4e'), 'callback_data' => 'wizwizch']],
				[['text' => \achbotMessage('admin_settings.config.get_plan_title.button.1090ae2ef1', ['totalPaidWithWalletCount' => $totalPaidWithWalletCount]), 'callback_data' => 'wizwizch'], ['text' => \achbotMessage('admin_settings.config.get_plan_title.button.4992ee1ce3'), 'callback_data' => 'wizwizch']],
				[['text' => number_format($totalPaidWithWalletSum) . \achbotMessage('admin_settings.config.get_gate_ways_keys.text.2cc98f7f7c'), 'callback_data' => 'wizwizch'], ['text' => \achbotMessage('admin_settings.config.get_plan_title.button.3e6902a0a5'), 'callback_data' => 'wizwizch']],
				[['text' => \achbotMessage('admin_settings.config.get_plan_title.button.9170a0b384', ['unfinishedPurchases' => $unfinishedPurchases]), 'callback_data' => 'wizwizch'], ['text' => \achbotMessage('admin_settings.config.get_plan_title.button.4a9dde94ce'), 'callback_data' => 'wizwizch']],
				[['text' => \achbotMessage('admin_settings.config.get_plan_title.button.ec06326997'), 'callback_data' => 'wizwizch']],
				[['text' => number_format($agentSalesToday) . \achbotMessage('admin_settings.config.get_gate_ways_keys.text.2cc98f7f7c'), 'callback_data' => 'wizwizch'], ['text' => \achbotMessage('admin_settings.config.get_plan_title.button.94e51af9f2'), 'callback_data' => 'wizwizch']],
                [['text' => number_format($agentSalesWeek) . \achbotMessage('admin_settings.config.get_gate_ways_keys.text.2cc98f7f7c'), 'callback_data' => 'wizwizch'], ['text' => \achbotMessage('admin_settings.config.get_plan_title.button.bec5bd798a'), 'callback_data' => 'wizwizch']],
                [['text' => number_format($agentSalesMonth) . \achbotMessage('admin_settings.config.get_gate_ways_keys.text.2cc98f7f7c'), 'callback_data' => 'wizwizch'], ['text' => \achbotMessage('admin_settings.config.get_plan_title.button.95e6d0f605'), 'callback_data' => 'wizwizch']],
                [['text' => number_format($agentSalesThreeMonth) . \achbotMessage('admin_settings.config.get_gate_ways_keys.text.2cc98f7f7c'), 'callback_data' => 'wizwizch'], ['text' => \achbotMessage('admin_settings.config.get_plan_title.button.8533ebdb23'), 'callback_data' => 'wizwizch']],
                [['text' => number_format($agentSalesSixMonth) . \achbotMessage('admin_settings.config.get_gate_ways_keys.text.2cc98f7f7c'), 'callback_data' => 'wizwizch'], ['text' => \achbotMessage('admin_settings.config.get_plan_title.button.93640c9789'), 'callback_data' => 'wizwizch']],
                [['text' => number_format($agentSalesYear) . \achbotMessage('admin_settings.config.get_gate_ways_keys.text.2cc98f7f7c'), 'callback_data' => 'wizwizch'], ['text' => \achbotMessage('admin_settings.config.get_plan_title.button.94b1f2942e'), 'callback_data' => 'wizwizch']],
				[['text' => \achbotMessage('admin_settings.config.get_plan_title.button.e24c37f91d'), 'callback_data' => 'wizwizch']],
				// دکمه های سرورها
				...array_map(function ($server) use ($serverSales) {
					return [
						[
							'text' => number_format($serverSales[$server['id']]) . \achbotMessage('admin_settings.config.get_gate_ways_keys.text.2cc98f7f7c'), // میزان فروش کلی
							'callback_data' => 'server_sales_' . $server['id']
						],
						[
							'text' => $server['title'], // عنوان سرور
							'callback_data' => 'server_sales_' . $server['id']
						]
					];
				}, $servers),
				[['text' => \achbotMessage('admin_settings.config.get_plan_title.button.b5fda480a4'), 'callback_data' => 'wizwizch']],
				// دکمه های فروش ماهانه سرورها
				...array_map(function ($server) use ($serverSalesMonth) {
					return [
						[
							'text' => number_format($serverSalesMonth[$server['id']]) . \achbotMessage('admin_settings.config.get_gate_ways_keys.text.2cc98f7f7c'), // فروش ماهانه به تومان
							'callback_data' => 'server_sales_month_' . $server['id']
						],
						[
							'text' => $server['title'] . '', // عنوان سرور + ماهانه
							'callback_data' => 'server_sales_month_' . $server['id']
						]
					];
				}, $servers),
				[['text' => \achbotMessage('admin_settings.config.get_plan_title.button.ee3938f6be'), 'callback_data' => 'wizwizch']],
				// دکمه های پلن های برتر کل تاریخ
				...array_map(function ($plan) {
					$planTitle = getPlanTitle($plan['plan_id']);  // استخراج عنوان پلن
					return [
						[
							'text' => number_format($plan['total']) . \achbotMessage('admin_settings.config.get_gate_ways_keys.text.2cc98f7f7c'),  // فروش کل پلن به تومان
							'callback_data' => 'plan_sales_' . $plan['plan_id']
						],
						[
							'text' => $planTitle,  // عنوان پلن
							'callback_data' => 'plan_sales_' . $plan['plan_id']
						]
					];
				}, $topPlans),
				[['text' => \achbotMessage('admin_settings.config.get_plan_title.button.a2b1f547e5'), 'callback_data' => 'wizwizch']],
				// دکمه های پلن های برتر ماه
				...array_map(function ($plan) {
					$planTitle = getPlanTitle($plan['plan_id']);  // استخراج عنوان پلن
					return [
						[
							'text' => number_format($plan['total']) . \achbotMessage('admin_settings.config.get_gate_ways_keys.text.2cc98f7f7c'),  // فروش ماهانه پلن به تومان
							'callback_data' => 'plan_sales_month_' . $plan['plan_id']
						],
						[
							'text' => $planTitle . '',  // عنوان پلن + ماهانه
							'callback_data' => 'plan_sales_month_' . $plan['plan_id']
						]
					];
				}, $topPlansMonth),
                [['text' => \achbotMessage('admin_settings.config.get_plan_title.button.214362e879'), 'callback_data' => 'top_50_pays']],
                [['text' => \achbotMessage('admin_settings.config.get_plan_title.button.e5784b9b3b'), 'callback_data' => 'top_50_pays_month']],
                [['text' => \achbotMessage('admin_settings.config.get_plan_title.button.69efb97897'), 'callback_data' => 'top_50_pays_week']],
                [['text' => \achbotMessage('admin_settings.config.get_plan_title.button.4b6a5b2ff6'), 'callback_data' => 'top_50_pays_24h']],
                [['text' => \achbotMessage('admin_settings.config.get_plan_title.button.fc75a7a82f'), 'callback_data' => 'custom_sales_date']],
                [['text' => \achbotMessage('admin_settings.config.get_bot_report_keys.button.c79b329841'), 'callback_data' => 'show_stats_menu']]
            ]
        ]);
    }
}

if (!function_exists('convertPersianDigitsToEnglishForStats')) {
    function convertPersianDigitsToEnglishForStats($string) {
        $persian = ["\u{06F0}","\u{06F1}","\u{06F2}","\u{06F3}","\u{06F4}","\u{06F5}","\u{06F6}","\u{06F7}","\u{06F8}","\u{06F9}","\u{0660}","\u{0661}","\u{0662}","\u{0663}","\u{0664}","\u{0665}","\u{0666}","\u{0667}","\u{0668}","\u{0669}"];
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
        $dateRange = str_replace(["\u{2013}", "\u{2014}", "\u{2212}", '.', "\\"], ['-', '-', '-', '/', '/'], $dateRange);
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
        sendMessage(\achbotMessage('admin_settings.config.calculate_sales_between_dates.message.91315a4cbe'), $cancelKey, "HTML");
        return;
    }

    $startTimestamp = strtotime($startDate . ' 00:00:00');
    $endTimestamp = strtotime($endDate . ' 23:59:59');

    if ($startTimestamp === false || $endTimestamp === false) {
        sendMessage(\achbotMessage('admin_settings.config.calculate_sales_between_dates.message.1e138ee7c6'), $cancelKey, "HTML");
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
        sendMessage(\achbotMessage('admin_settings.config.calculate_sales_between_dates.message.85858f5676'), $cancelKey, "HTML");
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
        \achbotMessage('admin_settings.config.calculate_sales_between_dates.message.020a3bcea9') . date("Y-m-d", $startTimestamp) . \achbotMessage('admin_settings.config.calculate_sales_between_dates.message.bd8ed16d1e') . date("Y-m-d", $endTimestamp) . \achbotMessage('admin_settings.config.calculate_sales_between_dates.message.28ec14e2c0') . number_format($totalSales) . \achbotMessage('admin_settings.config.calculate_sales_between_dates.text.9944ee4dd6') .
        \achbotMessage('admin_settings.config.calculate_sales_between_dates.text.dd92eb2ae0') . number_format($totalPayments) . "</b>",
        $removeKeyboard,
        "HTML"
    );
}

// در صورتی که دکمه لغو (Cancel) فشرده شد
if ($data == 'cancel_operation') {
    setUser("none", "step");
    sendMessage(\achbotMessage('admin_settings.config.calculate_sales_between_dates.message.0108e71350'), $removeKeyboard, "HTML");
}
// انتهای بخش آمار ربات

function achbotAdminRoleKeys(int $target): string {
    global $buttonValues;
    $rows=[];
    foreach (['manager','operator','support'] as $level)
        $rows[]=[['text'=>achbotAdminLevelLabel($level),'callback_data'=>'adminAssign|'.$target.'|'.$level]];
    $rows[]=[['text'=>$buttonValues['back_button'],'callback_data'=>'adminsList']];
    return json_encode(['inline_keyboard'=>$rows],JSON_UNESCAPED_UNICODE);
}
function getAdminsKeys(){
    global $connection,$buttonValues,$admin;
    if (!achbotAdminCan('admins')) return json_encode(['inline_keyboard'=>[]]);
    $keys=[[['text'=>achbotMessage('admin.roles.owner_row',['userId'=>(string)$admin]),'callback_data'=>'wizwizch']]];
    $stmt=$connection->prepare('SELECT userid,name FROM users WHERE isAdmin=1 AND userid<>? ORDER BY id');
    $owner=(int)$admin;$stmt->bind_param('i',$owner);$stmt->execute();$users=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
    foreach ($users as $user) {
        $target=(int)$user['userid'];$level=achbotAdminLevel($target);
        $label=achbotAdminLevelLabel($level);
        $keys[]=[
            ['text'=>achbotMessage('admin_settings.config.get_agents_list.button.2b2966a54e'),'callback_data'=>'delAdmin'.$target],
            ['text'=>$label,'callback_data'=>'adminRole|'.$target],
            ['text'=>(string)($user['name']?:$target),'callback_data'=>'adminRole|'.$target],
        ];
        $notify=achbotAdminNotifyEnabled($connection,$target,(int)$admin);
        $keys[]=[['text'=>$notify?achbotMessage('admin.roles.notifications_on'):achbotMessage('admin.roles.notifications_off'),
            'callback_data'=>'adminNotify|'.$target.'|'.($notify?'off':'on')]];
    }
    $keys[]=[['text'=>achbotMessage('admin_settings.config.get_admins_keys.button.bc56086ddd'),'callback_data'=>'addNewAdmin']];
    $keys[]=[['text'=>$buttonValues['back_button'],'callback_data'=>'managePanel']];
    return json_encode(['inline_keyboard'=>$keys],JSON_UNESCAPED_UNICODE);
}

// BEGIN By ACh

function getUserInfoKeys($userId) { 
    global $connection, $mainValues, $buttonValues, $from_id, $admin;
    if (function_exists('achbotAdminCan') &&
        !achbotAdminCan('customers', (int)$from_id)) return null;
    require_once __DIR__.'/src/Presentation/Text.php';
 
    // گرفتن اطلاعات کاربر از جدول users 
    $stmt = $connection->prepare("SELECT * FROM users WHERE userid = ?"); 
    $stmt->bind_param("i", $userId); 
    $stmt->execute(); 
    $userCount = $stmt->get_result(); 
    $stmt->close(); 
 
    if ($userCount->num_rows > 0) { 
        $userInfos = $userCount->fetch_assoc(); 
        $userWallet = number_format($userInfos['wallet'] ?? 0) . \achbotMessage('admin_settings.config.get_gate_ways_keys.text.2cc98f7f7c');
 
        // وضعیت همکاری و مسدودی
        $isAgent = $userInfos['is_agent'] ?? 0;
        $step = $userInfos['step'] ?? 'none';
        $agentStatus = ($isAgent == 1) ? \achbotMessage('admin_settings.config.get_user_info_keys.text.e0b20b49a2') : \achbotMessage('admin_settings.config.get_user_info_keys.text.3a66d0d476');
        $canManageAgents = function_exists('achbotAdminCan')
            ? achbotAdminCan('agents', (int)$from_id)
            : ((int)$from_id === (int)$admin || !empty($GLOBALS['userInfo']['isAdmin']));
        $rawDiscounts = trim((string)($userInfos['discount_percent'] ?? ''));
        $discounts = $rawDiscounts === '' ? [] : json_decode($rawDiscounts, true);
        $normal = is_array($discounts) ? ($discounts['normal'] ?? 0) : null;
        $normalValid = (is_int($normal) || is_string($normal)) &&
            preg_match('/\A(?:0|[1-9][0-9]?|100)\z/D', (string)$normal) === 1;
        $agentPercent = $normalValid
            ? \achbotMessage('admin_settings.user_info.agent_percent_value', ['percent'=>(string)$normal])
            : \achbotMessage('admin_settings.user_info.agent_percent_unset');
        $banStatus = ($step == 'banned') ? \achbotMessage('admin_settings.config.get_user_info_keys.text.160244c35f') : \achbotMessage('admin_settings.config.get_user_info_keys.text.0173cd6574');
 
        // گرفتن تعداد و مجموع خرید از جدول orders_list 
        $stmt = $connection->prepare("SELECT COUNT(*) as count, SUM(amount) as total FROM orders_list WHERE userid = ?");
        $stmt->bind_param("i", $userId); 
        $stmt->execute(); 
        $info = $stmt->get_result()->fetch_assoc(); 
        $stmt->close(); 
 
        $boughtService = $info['count'] ?? 0; 
        $totalBoughtPrice = $info['total'] ?? 0; 
 
        // گرفتن اطلاعات کاربر از تلگرام 
        $chatResponse = bot('getChat', ['chat_id' => $userId]);
        $userDetail = is_object($chatResponse) && is_object($chatResponse->result ?? null)
            ? $chatResponse->result : null;
        $userUserName = $userDetail->username ?? ($userInfos['username'] ?? '');
        $userUserName = preg_match('/\A[A-Za-z0-9_]{1,100}\z/D', (string)$userUserName)
            ? (string)$userUserName : '';
        $fullName = $userDetail !== null
            ? trim(($userDetail->first_name ?? '') . ' ' . ($userDetail->last_name ?? ''))
            : html_entity_decode((string)($userInfos['name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
 
        // Incoming paid funds only: wallet spending was already funded by a
        // top-up and must not be counted again as another customer payment.
        // The three external-success states remain current; pending/review,
        // failed/expired and paid_with_wallet are deliberately excluded.
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
        $totalApprovedPayments = number_format($val) . \achbotMessage('admin_settings.config.get_gate_ways_keys.text.2cc98f7f7c');
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
 
            $stmt2 = $connection->prepare("SELECT o.expire_date,o.inbound_id,c.type FROM orders_list o LEFT JOIN server_config c ON c.id=o.server_id WHERE o.userid = ? AND o.remark = ?");
            $stmt2->bind_param("is", $userId, $remark); 
            $stmt2->execute(); 
            $expireResult = $stmt2->get_result(); 
            $stmt2->close(); 
 
            while ($expireRow = $expireResult->fetch_assoc()) { 
                $expire_date=\AchBot\Presentation\Text\serviceExpiryLabel(
                    $expireRow['expire_date'],static fn(int $timestamp)=>date('Y-m-d H:i:s',$timestamp),
                    \achbotMessage('subscription.account_deletion.no_dont_delete.text.5e6ee5eaa1'),
                    \achbotMessage('admin_settings.config.get_bot_report_keys.text.f07a81a062'),
                    achbotSupportsAccount($expireRow['type']??'', $expireRow['inbound_id']));
                $subscriptions[] = \achbotMessage('admin_settings.config.get_user_info_keys.text.609b681dab', ['remark' => $remark, 'expire_date' => $expire_date]);
            } 
 
            $stmt2 = $connection->prepare("SELECT SUM(amount) as totalIncreaseAmount FROM increase_order WHERE userid = ? AND remark = ?"); 
            $stmt2->bind_param("is", $userId, $remark); 
            $stmt2->execute(); 
            $increaseAmountResult = $stmt2->get_result()->fetch_assoc(); 
            $stmt2->close(); 
 
            $increaseAmountTotal += $increaseAmountResult['totalIncreaseAmount'] ?? 0; 
        } 
 
        $totalAmountWithIncrease = $totalBoughtPrice + $increaseAmountTotal; 
        $totalAmountWithIncreaseFormatted = number_format($totalAmountWithIncrease) . \achbotMessage('admin_settings.config.get_gate_ways_keys.text.2cc98f7f7c');
 
        // آماده سازی لیست اشتراک ها 
        $chunkedSubscriptions = array_chunk($subscriptions, 20); 
        $subscriptionMessages = []; 
        foreach ($chunkedSubscriptions as $chunk) { 
            $subscriptionMessages[] = implode("\n\n", $chunk); 
        } 
 
        return [ 
            'inline_keyboard' => [ 
                [ 
                    $userUserName !== ''
                        ? ['text' => $userUserName, 'url' => "https://t.me/$userUserName"]
                        : ['text' => trim(\achbotMessage('telegram.update_bindings.text.32f665d21c')), 'callback_data' => "wizwizch"],
                    ['text' => \achbotMessage('admin_settings.config.get_user_info_keys.button.173fec6019'), 'callback_data' => "wizwizch"]
                ], 
                [ 
                    ['text' => $fullName ?: " ", 'callback_data' => "wizwizch"], 
                    ['text' => \achbotMessage('admin_settings.config.get_user_info_keys.button.69c1e5b1fe'), 'callback_data' => "wizwizch"]
                ], 
                [ 
                    ['text' => $boughtService, 'callback_data' => "wizwizch"], 
                    ['text' => \achbotMessage('admin_settings.config.get_user_info_keys.button.f2d07fafb5'), 'callback_data' => "wizwizch"]
                ], 
                [ 
                    ['text' => $countWeek, 'callback_data' => "wizwizch"], 
                    ['text' => \achbotMessage('admin_settings.config.get_user_info_keys.button.a07470ed6e'), 'callback_data' => "wizwizch"]
                ], 
                [ 
                    ['text' => $countMonth, 'callback_data' => "wizwizch"], 
                    ['text' => \achbotMessage('admin_settings.config.get_user_info_keys.button.37886dfbc2'), 'callback_data' => "wizwizch"]
                ], 
                [ 
                    ['text' => $countThreeMonths, 'callback_data' => "wizwizch"], 
                    ['text' => \achbotMessage('admin_settings.config.get_user_info_keys.button.e924268a40'), 'callback_data' => "wizwizch"]
                ], 
                [ 
                    ['text' => $countSixMonths, 'callback_data' => "wizwizch"], 
                    ['text' => \achbotMessage('admin_settings.config.get_user_info_keys.button.653fd00888'), 'callback_data' => "wizwizch"]
                ], 
                [ 
                    ['text' => $totalAmountWithIncreaseFormatted, 'callback_data' => "wizwizch"], 
                    ['text' => \achbotMessage('admin_settings.config.get_user_info_keys.button.ceb490f817'), 'callback_data' => "wizwizch"]
                ], 
                [ 
                    ['text' => $approvedPayments, 'callback_data' => "wizwizch"], 
                    ['text' => \achbotMessage('admin_settings.config.get_user_info_keys.button.3c25eb15a8'), 'callback_data' => "wizwizch"]
                ], 
                [ 
                    ['text' => $totalApprovedPayments, 'callback_data' => "wizwizch"], 
                    ['text' => \achbotMessage('admin_settings.config.get_user_info_keys.button.513b143e10'), 'callback_data' => "wizwizch"]
                ], 
                [ 
                    ['text' => $totalWeekPayments, 'callback_data' => "wizwizch"], 
                    ['text' => \achbotMessage('admin_settings.config.get_user_info_keys.button.a07470ed6e'), 'callback_data' => "wizwizch"]
                ], 
                [ 
                    ['text' => $totalMonthPayments, 'callback_data' => "wizwizch"], 
                    ['text' => \achbotMessage('admin_settings.config.get_user_info_keys.button.37886dfbc2'), 'callback_data' => "wizwizch"]
                ], 
                [ 
                    ['text' => $totalThreeMonthsPayments, 'callback_data' => "wizwizch"], 
                    ['text' => \achbotMessage('admin_settings.config.get_user_info_keys.button.e924268a40'), 'callback_data' => "wizwizch"]
                ], 
                [ 
                    ['text' => $totalSixMonthsPayments, 'callback_data' => "wizwizch"], 
                    ['text' => \achbotMessage('admin_settings.config.get_user_info_keys.button.653fd00888'), 'callback_data' => "wizwizch"]
                ], 
                [ 
                    ['text' => $userWallet, 'callback_data' => "wizwizch"], 
                    ['text' => \achbotMessage('admin_settings.config.get_user_info_keys.button.88bf6cf5c6'), 'callback_data' => "wizwizch"]
                ],
                [
                    ['text' => $agentStatus, 'callback_data' => $canManageAgents ? "toggleAgent_{$userId}" : "wizwizch"],
                    ['text' => \achbotMessage('admin_settings.config.get_bot_setting_keys.text.62c92fb90c'), 'callback_data' => "wizwizch"]
                ],
                ...($canManageAgents ? [[
                    ['text' => $agentPercent, 'callback_data' => "editAgentDiscountNormal{$userId}_userinfo"],
                    ['text' => \achbotMessage('admin_settings.user_info.agent_percent'), 'callback_data' => "wizwizch"]
                ]] : []),
                [
                    ['text' => $banStatus, 'callback_data' => "toggleBan_{$userId}"],
                    ['text' => \achbotMessage('admin_settings.config.get_user_info_keys.button.05b7dda9cc'), 'callback_data' => "wizwizch"]
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
    return number_format($result) . \achbotMessage('admin_settings.config.get_gate_ways_keys.text.2cc98f7f7c');
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
/**
 * Source-authenticated administrative moderation. bot.php calls this only
 * after the ingress secret and legacy IP guard; revalidate both here.
 *
 * Policy: a fresh explicit authorized ban may preempt an active gift wizard,
 * but a callback predating that wizard may NOT do so. Agent toggles never
 * modify a wizard step. A per-operator source marker makes duplicate/out-of-
 * order Telegram callbacks idempotent on the nonunique historical schema.
 */
/**
 * Administrator channel-exemption wizard, reachable only from bot.php
 * AFTER verified Telegram ingress AND its IP check.
 *
 * Source timestamps are carried by the nonce-bearing step and a durable
 * per-actor source marker in original setting. Both actor and policy writes
 * are serialized by the primary administrator PK to work with historical
 * nonunique users.userid and setting.type (no schema migration).
 */
function achbotHandleChannelExemption($data, $text, $cached): bool {
    global $connection, $admin, $from_id, $chat_id, $message_id, $update, $removeKeyboard;
    $start=($data==='addExemptUser');
    $step=is_array($cached)?(string)($cached['step']??''):'';
    $continuing=str_starts_with($step,'achbotExempt:');
    $legacy=($step==='awaitingExemptUserID');
    if (!$start && !$continuing && !$legacy) return false;

    // An installed pre-upgrade wizard has no verifiable update/nonce
    // provenance. Require a fresh authenticated admin callback to restart.
    if ($legacy && !$start) return true;
    $begun=false;
    try {
        if (!function_exists('achbotWebhookIngressAuthenticated') ||
            !achbotWebhookIngressAuthenticated() ||
            !is_int($from_id) && !ctype_digit((string)$from_id))
            throw new RuntimeException('exempt_ingress_unverified');
        $actor=(int)$from_id;
        if ($actor<=0 || !is_object($update) ||
            !is_int($update->update_id??null) || $update->update_id<=0)
            throw new RuntimeException('exempt_update_invalid');
        if ($start) {
            require_once __DIR__.'/src/Subscription/DirectGift.php';
            $source=achbotDirectGiftWebhook(
                $update,$actor,(int)$chat_id,(int)$message_id,
                'addExemptUser','callback');
            $targetId=null;
        } else {
            if (!is_string($text) ||
                preg_match('/\A[1-9][0-9]{0,18}\z/D',$text)!==1)
                throw new RuntimeException('exempt_target_invalid');
            $targetId=filter_var($text,FILTER_VALIDATE_INT,
                ['options'=>['min_range'=>1,'max_range'=>PHP_INT_MAX]]);
            if ($targetId===false)
                throw new RuntimeException('exempt_target_overflow');
            require_once __DIR__.'/src/Subscription/GiftBatch.php';
            $source=achbotGiftTelegramSource(
                $update,$actor,(int)$chat_id,(int)$message_id,$text);
            if (preg_match('/\AachbotExempt:([1-9][0-9]*):([1-9][0-9]*):[a-f0-9]{32}\z/D',$step,$m)!==1 ||
                $source['update_id']<=(int)$m[1] ||
                $source['message_id']<=(int)$m[2])
                throw new RuntimeException('exempt_message_stale');
        }
        $actorPk=(int)($cached['id']??0);
        if ($actorPk<=0 || (int)($cached['userid']??0)!==$actor)
            throw new RuntimeException('exempt_cached_actor_invalid');

        // Resolve primary-admin PK and exact actor PKs before BEGIN. The
        // first transactional SELECT must lock the primary-admin PK so a
        // REPEATABLE READ snapshot is not frozen before serialization.
        $ids=[];
        foreach (array_unique([(int)$admin,$actor]) as $id) {
            if ($id<=0) throw new RuntimeException('exempt_admin_identity_invalid');
            $stmt=$connection->prepare('SELECT id FROM users WHERE userid=? LIMIT 2');
            if (!$stmt) throw new RuntimeException('exempt_lookup_prepare');
            $stmt->bind_param('i',$id);
            $ok=$stmt->execute();
            $rows=$ok?$stmt->get_result()->fetch_all(MYSQLI_ASSOC):[];
            $stmt->close();
            if (!$ok || count($rows)!==1)
                throw new RuntimeException('exempt_identity_ambiguous');
            $ids[$id]=(int)$rows[0]['id'];
        }
        if ($ids[$actor]!==$actorPk)
            throw new RuntimeException('exempt_cached_pk_drift');
        if (!$connection->begin_transaction())
            throw new RuntimeException('exempt_begin_failed');
        $begun=true;

        $ownerPk=$ids[(int)$admin];
        $stmt=$connection->prepare('SELECT id,userid FROM users WHERE id=? FOR UPDATE');
        if (!$stmt) throw new RuntimeException('exempt_owner_lock_prepare');
        $stmt->bind_param('i',$ownerPk);
        $ok=$stmt->execute();
        $owner=$ok?$stmt->get_result()->fetch_assoc():null;
        $stmt->close();
        if (!$ok || !$owner || (int)$owner['userid']!==(int)$admin)
            throw new RuntimeException('exempt_owner_lock_failed');

        $stmt=$connection->prepare('SELECT id,userid,isAdmin,step FROM users WHERE id=? FOR UPDATE');
        if (!$stmt) throw new RuntimeException('exempt_actor_lock_prepare');
        $stmt->bind_param('i',$actorPk);
        $ok=$stmt->execute();
        $current=$ok?$stmt->get_result()->fetch_assoc():null;
        $stmt->close();
        if (!$ok || !$current || (int)$current['userid']!==$actor ||
            ($actor!==(int)$admin && (int)$current['isAdmin']!==1))
            throw new RuntimeException('exempt_admin_revoked');
        // Reject duplicated userids even when they were introduced between
        // pretransaction discovery and acquisition of the owner PK lock.
        $stmt=$connection->prepare('SELECT id FROM users WHERE userid=? LIMIT 2');
        if (!$stmt) throw new RuntimeException('exempt_actor_recheck_prepare');
        $stmt->bind_param('i',$actor);
        $ok=$stmt->execute();
        $rows=$ok?$stmt->get_result()->fetch_all(MYSQLI_ASSOC):[];
        $stmt->close();
        if (!$ok || count($rows)!==1 || (int)$rows[0]['id']!==$actorPk)
            throw new RuntimeException('exempt_actor_duplicate');

        $nowStep=(string)$current['step'];
        if ($nowStep==='banned' || str_starts_with($nowStep,'achbotDirectGift'))
            throw new RuntimeException('exempt_state_protected');
        if (!$start && $nowStep!==$step)
            throw new RuntimeException('exempt_cached_step_stale');
        if ($start && str_starts_with($nowStep,'achbotExempt:')) {
            if (preg_match('/\AachbotExempt:([1-9][0-9]*):/D',$nowStep,$m)!==1 ||
                $source['update_id']<=(int)$m[1])
                throw new RuntimeException('exempt_old_callback');
        }

        $markerType='ACHBOT_EXEMPT_SOURCE_'.hash('sha256',(string)$actor);
        $stmt=$connection->prepare('SELECT id,value FROM setting WHERE type=? LIMIT 2');
        if (!$stmt) throw new RuntimeException('exempt_marker_prepare');
        $stmt->bind_param('s',$markerType);
        $ok=$stmt->execute();
        $markers=$ok?$stmt->get_result()->fetch_all(MYSQLI_ASSOC):[];
        $stmt->close();
        if (!$ok || count($markers)>1)
            throw new RuntimeException('exempt_marker_ambiguous');
        if ($markers) {
            $old=json_decode((string)$markers[0]['value'],true,512,JSON_THROW_ON_ERROR);
            if (!is_array($old) || ($old['version']??null)!==1 ||
                ($old['actor_id']??null)!==$actor ||
                !is_int($old['last_update_id']??null) ||
                !is_int($old['last_message_id']??null) ||
                // update_id is globally ordered. Callback message_id names
                // an older BOT menu message and need not be monotonic.
                $source['update_id']<=$old['last_update_id'] ||
                (!$start && $source['message_id']<$old['last_message_id']))
                throw new RuntimeException('exempt_marker_stale_or_corrupt');
        }
        $already=false;
        $newStep=$start?
            'achbotExempt:'.$source['update_id'].':'.$source['message_id'].':'.bin2hex(random_bytes(16)):
            'none';
        if (!$start) {
            // Load the nonunique global policy only AFTER the owner PK lock.
            // All current exemption writers must take this same lock first.
            $type='EXEMPTED_USERS';
            $stmt=$connection->prepare('SELECT id,value FROM setting WHERE type=? LIMIT 2');
            if (!$stmt) throw new RuntimeException('exempt_policy_prepare');
            $stmt->bind_param('s',$type);
            $ok=$stmt->execute();
            $records=$ok?$stmt->get_result()->fetch_all(MYSQLI_ASSOC):[];
            $stmt->close();
            if (!$ok || count($records)>1)
                throw new RuntimeException('exempt_policy_ambiguous');
            $members=$records?
                json_decode((string)$records[0]['value'],true,512,JSON_THROW_ON_ERROR):[];
            if (!is_array($members) || !array_is_list($members))
                throw new RuntimeException('exempt_policy_corrupt');
            foreach ($members as $member) {
                if ((!is_int($member) && (!is_string($member) ||
                    preg_match('/\A[1-9][0-9]*\z/D',$member)!==1)) ||
                    (int)$member<=0)
                    throw new RuntimeException('exempt_policy_member_invalid');
                if ((int)$member===$targetId) $already=true;
            }
            if (!$already) {
                // Preserve existing string-format userids for compatibility
                // with bot.php in_array and the historical JSON policy.
                $members[]=(string)$targetId;
                $newValue=json_encode($members,JSON_THROW_ON_ERROR);
                if ($records) {
                    $rid=(int)$records[0]['id'];
                    $previous=(string)$records[0]['value'];
                    $stmt=$connection->prepare('UPDATE setting SET value=? WHERE id=? AND type=? AND value=?');
                    if (!$stmt) throw new RuntimeException('exempt_policy_update_prepare');
                    $stmt->bind_param('siss',$newValue,$rid,$type,$previous);
                } else {
                    $stmt=$connection->prepare('INSERT INTO setting(type,value) VALUES(?,?)');
                    if (!$stmt) throw new RuntimeException('exempt_policy_insert_prepare');
                    $stmt->bind_param('ss',$type,$newValue);
                }
                $ok=$stmt->execute();$affected=$stmt->affected_rows;$stmt->close();
                if (!$ok || $affected!==1)
                    throw new RuntimeException('exempt_policy_write_unconfirmed');
            }
        }

        $stmt=$connection->prepare('UPDATE users SET step=? WHERE id=? AND userid=? AND step=?');
        if (!$stmt) throw new RuntimeException('exempt_step_cas_prepare');
        $stmt->bind_param('siis',$newStep,$actorPk,$actor,$nowStep);
        $ok=$stmt->execute();$affected=$stmt->affected_rows;$stmt->close();
        if (!$ok || $affected!==1)
            throw new RuntimeException('exempt_step_cas_unconfirmed');

        $markerValue=json_encode([
            'version'=>1,'actor_id'=>$actor,
            'last_update_id'=>$source['update_id'],
            'last_message_id'=>$source['message_id'],
        ],JSON_THROW_ON_ERROR);
        if ($markers) {
            $rid=(int)$markers[0]['id'];
            $previous=(string)$markers[0]['value'];
            $stmt=$connection->prepare('UPDATE setting SET value=? WHERE id=? AND type=? AND value=?');
            if (!$stmt) throw new RuntimeException('exempt_marker_update_prepare');
            $stmt->bind_param('siss',$markerValue,$rid,$markerType,$previous);
        } else {
            $stmt=$connection->prepare('INSERT INTO setting(type,value) VALUES(?,?)');
            if (!$stmt) throw new RuntimeException('exempt_marker_insert_prepare');
            $stmt->bind_param('ss',$markerType,$markerValue);
        }
        $ok=$stmt->execute();$affected=$stmt->affected_rows;$stmt->close();
        if (!$ok || $affected!==1)
            throw new RuntimeException('exempt_marker_write_unconfirmed');
        if (!$connection->commit())
            throw new RuntimeException('exempt_commit_unconfirmed');
        $begun=false;
    } catch (Throwable $error) {
        if ($begun) {
            try {$connection->rollback();} catch (Throwable $ignored) {}
        }
        error_log('admin_channel_exemption_source_or_commit_unconfirmed');
        return true;
    }
    if ($start) {
        sendMessage(\achbotMessage('admin_settings.config.handle_channel_exemption.message.f4395ba7cb'),$removeKeyboard);
    } elseif ($already) {
        sendMessage(\achbotMessage('admin_settings.config.handle_channel_exemption.message.cca4b2908c'),$removeKeyboard,"HTML");
    } else {
        sendMessage(\achbotMessage('admin_settings.config.handle_channel_exemption.message.4a4db9f14a', ['targetId' => $targetId]),$removeKeyboard,"HTML");
    }
    return true;
}

function handleToggleCallbacks($data, $chat_id, $message_id): bool {
    global $connection, $admin, $from_id, $update, $callbackId;
    if (!is_string($data) ||
        !preg_match('/\Atoggle(?:Ban|Agent)_/D',$data))
        return false;

    $inTransaction=false;
    try {
        if (strlen($data)>55 ||
            preg_match('/\Atoggle(Ban|Agent)_([1-9][0-9]*)\z/D',$data,$m)!==1 ||
            !function_exists('achbotWebhookIngressAuthenticated') ||
            !achbotWebhookIngressAuthenticated())
            throw new RuntimeException('moderation_source_invalid');
        $targetId=filter_var($m[2],FILTER_VALIDATE_INT,
            ['options'=>['min_range'=>1,'max_range'=>PHP_INT_MAX]]);
        if ($targetId===false || !is_object($update) ||
            !is_int($update->update_id??null) || $update->update_id<=0)
            throw new RuntimeException('moderation_source_invalid');

        $keys=array_keys(get_object_vars($update));sort($keys);
        if ($keys!==['callback_query','update_id'] ||
            !is_object($update->callback_query??null))
            throw new RuntimeException('moderation_callback_frame_invalid');
        $callback=$update->callback_query;
        $sender=$callback->from??null;
        $message=$callback->message??null;
        $chat=$message->chat??null;
        if (!is_object($sender) || !is_object($message) || !is_object($chat) ||
            !is_int($sender->id??null) || $sender->id<=0 ||
            ($sender->is_bot??false)===true ||
            !is_int($chat->id??null) || $chat->id!==$sender->id ||
            ($chat->type??null)!=='private' ||
            !is_int($message->message_id??null) ||
            $message->message_id<=0 ||
            !is_string($callback->data??null) || $callback->data!==$data ||
            !is_string($callback->id??null) || $callback->id==='' ||
            $sender->id!==(int)$from_id ||
            $chat->id!==(int)$chat_id ||
            $message->message_id!==(int)$message_id)
            throw new RuntimeException('moderation_callback_identity_invalid');
        $actorId=$sender->id;
        // users.userid is NOT UNIQUE in the original 23-table schema.
        // Discover the exact actor and target PKs before starting a transaction
        // so the first transactional reads can acquire current PK row locks.
        $getPks=static function(int $userid) use ($connection): array {
            $stmt=$connection->prepare('SELECT id FROM users WHERE userid=? LIMIT 2');
            if (!$stmt) throw new RuntimeException('moderation_pk_lookup_prepare');
            $stmt->bind_param('i',$userid);
            $ok=$stmt->execute();
            $rows=$ok?$stmt->get_result()->fetch_all(MYSQLI_ASSOC):[];
            $stmt->close();
            if (!$ok || count($rows)!==1)
                throw new RuntimeException('moderation_user_identity_ambiguous');
            return $rows[0];
        };
        $actorPk=(int)$getPks($actorId)['id'];
        $targetPk=(int)$getPks($targetId)['id'];
        if ($actorPk<=0 || $targetPk<=0)
            throw new RuntimeException('moderation_pk_invalid');

        if (!$connection->begin_transaction())
            throw new RuntimeException('moderation_begin_failed');
        $inTransaction=true;
        $stmt=$connection->prepare('SELECT id,userid,isAdmin,step,is_agent
             FROM users WHERE id IN (?,?) ORDER BY id FOR UPDATE');
        if (!$stmt) throw new RuntimeException('moderation_lock_prepare');
        $stmt->bind_param('ii',$actorPk,$targetPk);
        $ok=$stmt->execute();
        $rows=$ok?$stmt->get_result()->fetch_all(MYSQLI_ASSOC):[];
        $stmt->close();
        if (!$ok) throw new RuntimeException('moderation_lock_failed');
        $actorRow=null;$targetRow=null;
        foreach ($rows as $row) {
            if ((int)$row['id']===$actorPk) $actorRow=$row;
            if ((int)$row['id']===$targetPk) $targetRow=$row;
        }
        if (!$actorRow || !$targetRow ||
            (int)$actorRow['userid']!==$actorId ||
            (int)$targetRow['userid']!==$targetId ||
            ($actorId!==(int)$admin && (int)$actorRow['isAdmin']!==1))
            throw new RuntimeException('moderation_actor_revoked_or_target_drift');
        // Detect newly introduced ambiguous userid rows even if the original
        // nonunique userid lookup and the PK lock were separated in time.
        foreach (array_unique([$actorId,$targetId]) as $identity) {
            $checked=$getPks($identity);
            if ((int)$checked['id']!==($identity===$actorId?$actorPk:$targetPk))
                throw new RuntimeException('moderation_user_identity_drift');
        }

        // Deliberate moderation precedence: only a NEWER signed source may
        // ban an operator who has an active gift or exemption wizard.
        // Older/malformed callback sources cannot erase either wizard.
        if (str_starts_with((string)$targetRow['step'],'achbotDirectGift')) {
            require_once __DIR__.'/src/Subscription/DirectGift.php';
            $latestGift=achbotDirectGiftStepUpdate((string)$targetRow['step']);
            if ($latestGift===null || $update->update_id<=$latestGift)
                throw new RuntimeException('moderation_stale_before_gift');
        }
        if (str_starts_with((string)$targetRow['step'],'achbotExempt:')) {
            if (preg_match('/\AachbotExempt:([1-9][0-9]*):/D',(string)$targetRow['step'],$sourceMatch)!==1 ||
                $update->update_id<=(int)$sourceMatch[1])
                throw new RuntimeException('moderation_stale_before_exemption');
        }

        $type='ACHBOT_ADMIN_MOD_SOURCE_'.hash('sha256',(string)$actorId);
        $stmt=$connection->prepare('SELECT id,value FROM setting WHERE type=? LIMIT 2');
        if (!$stmt) throw new RuntimeException('moderation_journal_lookup_prepare');
        $stmt->bind_param('s',$type);
        $ok=$stmt->execute();
        $records=$ok?$stmt->get_result()->fetch_all(MYSQLI_ASSOC):[];
        $stmt->close();
        if (!$ok || count($records)>1)
            throw new RuntimeException('moderation_source_ambiguous');
        if ($records) {
            $old=json_decode((string)$records[0]['value'],true,512,JSON_THROW_ON_ERROR);
            if (!is_array($old) || ($old['version']??null)!==1 ||
                ($old['actor_id']??null)!==$actorId ||
                !is_int($old['last_update_id']??null) ||
                !is_int($old['last_message_id']??null) ||
                // A user may legitimately click a different older bot
                // menu. Callback message_id is not ordered by update_id.
                $update->update_id<=$old['last_update_id'])
                throw new RuntimeException('moderation_stale_source_or_corrupt_journal');
        }

        if ($m[1]==='Ban') {
            $before=(string)$targetRow['step'];
            $next=$before==='banned'?'none':'banned';
            // Shared target chronology covers old text/receipt Ban and
            // authorized numeric Unban across DIFFERENT administrators.
            achbotModerationTargetJournal(
                $connection,$targetId,$actorId,$update->update_id,
                $message->message_id,$next==='banned'?'Ban':'Unban');
            $stmt=$connection->prepare('UPDATE users SET step=?
                WHERE id=? AND userid=? AND step=?');
            if (!$stmt) throw new RuntimeException('moderation_ban_update_prepare');
            $stmt->bind_param('siis',$next,$targetPk,$targetId,$before);
        } else {
            $before=(int)$targetRow['is_agent'];
            $next=$before===1?0:1;
            $stmt=$connection->prepare('UPDATE users SET is_agent=?
                WHERE id=? AND userid=? AND is_agent=?');
            if (!$stmt) throw new RuntimeException('moderation_agent_update_prepare');
            $stmt->bind_param('iiii',$next,$targetPk,$targetId,$before);
        }
        $ok=$stmt->execute();$affected=$stmt->affected_rows;$stmt->close();
        if (!$ok || $affected!==1)
            throw new RuntimeException('moderation_target_cas_unconfirmed');
        $value=json_encode([
            'version'=>1,'actor_id'=>$actorId,
            'last_update_id'=>$update->update_id,
            'last_message_id'=>$message->message_id,
            'action'=>$m[1],'target_userid'=>$targetId
        ],JSON_THROW_ON_ERROR);
        if ($records) {
            $recordId=(int)$records[0]['id'];
            $oldValue=(string)$records[0]['value'];
            $stmt=$connection->prepare('UPDATE setting SET value=?
                WHERE id=? AND type=? AND value=?');
            if (!$stmt) throw new RuntimeException('moderation_journal_update_prepare');
            $stmt->bind_param('siss',$value,$recordId,$type,$oldValue);
        } else {
            $stmt=$connection->prepare('INSERT INTO setting(type,value) VALUES(?,?)');
            if (!$stmt) throw new RuntimeException('moderation_journal_insert_prepare');
            $stmt->bind_param('ss',$type,$value);
        }
        $ok=$stmt->execute();$affected=$stmt->affected_rows;$stmt->close();
        if (!$ok || $affected!==1)
            throw new RuntimeException('moderation_journal_write_unconfirmed');
        if (!$connection->commit())
            throw new RuntimeException('moderation_commit_unknown');
        $inTransaction=false;
    } catch (Throwable $error) {
        if ($inTransaction) {
            try {$connection->rollback();} catch (Throwable $ignored) {}
        }
        error_log('admin_moderation_source_or_commit_unconfirmed');
        return true;
    }
    // Post-commit UI only. Never emit success on a rejected/uncertain mutation.
    try {
        bot('editMessageReplyMarkup',[
            'chat_id'=>$chat_id,'message_id'=>$message_id,
            'reply_markup'=>json_encode(getUserInfoKeys($targetId))
        ]);
    } catch (Throwable $error) {
        error_log('admin_moderation_notice_unconfirmed');
    }
    return true;
}


/**
 * Original-schema moderation provenance. users.userid and setting.type are
 * not unique. Callers must lock the exact target PK before reading/writing a
 * target watermark, and acquire all involved user PK locks before any
 * consistent read in a transaction (including under REPEATABLE READ).
 */
function achbotModerationUniquePk(mysqli $db,int $userid): int {
    if ($userid<=0) throw new RuntimeException('moderation_identity_invalid');
    $stmt=$db->prepare('SELECT id FROM users WHERE userid=? LIMIT 2');
    if (!$stmt) throw new RuntimeException('moderation_identity_prepare');
    $stmt->bind_param('i',$userid);
    $ok=$stmt->execute();
    $rows=$ok?$stmt->get_result()->fetch_all(MYSQLI_ASSOC):[];
    $stmt->close();
    if (!$ok || count($rows)!==1 || (int)$rows[0]['id']<=0)
        throw new RuntimeException('moderation_identity_ambiguous');
    return (int)$rows[0]['id'];
}

function achbotModerationActorJournal(mysqli $db,int $actor,int $updateId,
                                       int $messageId,string $action,int $target): void {
    $type='ACHBOT_ADMIN_MOD_SOURCE_'.hash('sha256',(string)$actor);
    $stmt=$db->prepare('SELECT id,value FROM setting WHERE type=? LIMIT 2');
    if (!$stmt) throw new RuntimeException('moderation_actor_marker_prepare');
    $stmt->bind_param('s',$type);
    $ok=$stmt->execute();
    $records=$ok?$stmt->get_result()->fetch_all(MYSQLI_ASSOC):[];
    $stmt->close();
    if (!$ok || count($records)>1)
        throw new RuntimeException('moderation_actor_marker_ambiguous');
    if ($records) {
        $old=json_decode((string)$records[0]['value'],true,512,JSON_THROW_ON_ERROR);
        if (!is_array($old) || ($old['version']??null)!==1 ||
            ($old['actor_id']??null)!==$actor ||
            !is_int($old['last_update_id']??null) ||
            !is_int($old['last_message_id']??null) ||
            $updateId<=$old['last_update_id'])
            throw new RuntimeException('moderation_actor_source_stale');
    }
    $value=json_encode([
        'version'=>1,'actor_id'=>$actor,'last_update_id'=>$updateId,
        'last_message_id'=>$messageId,'action'=>$action,'target_userid'=>$target
    ],JSON_THROW_ON_ERROR);
    if ($records) {
        $pk=(int)$records[0]['id'];$before=(string)$records[0]['value'];
        $stmt=$db->prepare('UPDATE setting SET value=?
            WHERE id=? AND type=? AND value=?');
        if (!$stmt) throw new RuntimeException('moderation_actor_marker_update_prepare');
        $stmt->bind_param('siss',$value,$pk,$type,$before);
    } else {
        $stmt=$db->prepare('INSERT INTO setting(type,value) VALUES(?,?)');
        if (!$stmt) throw new RuntimeException('moderation_actor_marker_insert_prepare');
        $stmt->bind_param('ss',$type,$value);
    }
    $ok=$stmt->execute();$changed=$stmt->affected_rows;$stmt->close();
    if (!$ok || $changed!==1)
        throw new RuntimeException('moderation_actor_marker_unconfirmed');
}

/**
 * Cross-actor target chronology. Every BAN/UNBAN writer in this runtime
 * participates, including the source-verified toggle callback. Global
 * Telegram update_id, rather than a bot menu message_id, orders events.
 */
function achbotModerationTargetJournal(mysqli $db,int $target,int $actor,
                                        int $updateId,int $messageId,
                                        string $action): void {
    $type='ACHBOT_ADMIN_TARGET_MOD_'.hash('sha256',(string)$target);
    $stmt=$db->prepare('SELECT id,value FROM setting WHERE type=? LIMIT 2');
    if (!$stmt) throw new RuntimeException('moderation_target_marker_prepare');
    $stmt->bind_param('s',$type);
    $ok=$stmt->execute();
    $records=$ok?$stmt->get_result()->fetch_all(MYSQLI_ASSOC):[];
    $stmt->close();
    if (!$ok || count($records)>1)
        throw new RuntimeException('moderation_target_marker_ambiguous');
    if ($records) {
        $old=json_decode((string)$records[0]['value'],true,512,JSON_THROW_ON_ERROR);
        if (!is_array($old) || ($old['version']??null)!==1 ||
            ($old['target_userid']??null)!==$target ||
            !is_int($old['actor_id']??null) || $old['actor_id']<=0 ||
            !is_int($old['last_update_id']??null) ||
            !is_int($old['last_message_id']??null) ||
            !in_array($old['action']??null,['Ban','Unban'],true) ||
            $updateId<=$old['last_update_id'])
            throw new RuntimeException('moderation_target_source_stale');
    }
    $value=json_encode([
        'version'=>1,'target_userid'=>$target,'actor_id'=>$actor,
        'last_update_id'=>$updateId,'last_message_id'=>$messageId,
        'action'=>$action
    ],JSON_THROW_ON_ERROR);
    if ($records) {
        $pk=(int)$records[0]['id'];$before=(string)$records[0]['value'];
        $stmt=$db->prepare('UPDATE setting SET value=?
            WHERE id=? AND type=? AND value=?');
        if (!$stmt) throw new RuntimeException('moderation_target_marker_update_prepare');
        $stmt->bind_param('siss',$value,$pk,$type,$before);
    } else {
        $stmt=$db->prepare('INSERT INTO setting(type,value) VALUES(?,?)');
        if (!$stmt) throw new RuntimeException('moderation_target_marker_insert_prepare');
        $stmt->bind_param('ss',$type,$value);
    }
    $ok=$stmt->execute();$changed=$stmt->affected_rows;$stmt->close();
    if (!$ok || $changed!==1)
        throw new RuntimeException('moderation_target_marker_unconfirmed');
}

/**
 * Legacy Ban/Unban wizard start, private numeric completion and receipt Ban
 * share an authenticated, current-role, source-ordered transaction boundary.
 * This is invoked by existing bot.php includes only after its ingress/IP
 * gates; no provider, payment or financial tables are ever modified here.
 *
 * Returns started | changed | already | rejected. Rejected and unknown COMMIT
 * have NO success/cleanup notices; caller may display a generic rejection.
 */
function achbotLegacyModerationTransition(
    mysqli $db,$update,int $actor,int $chat,int $message,
    $cached,int $primary,string $operation,string $targetRaw=''
): string {
    if (!in_array($operation,['startBan','startUnban','ban','unban','receiptBan'],true))
        return 'rejected';
    $started=false;
    try {
        if (!function_exists('achbotWebhookIngressAuthenticated') ||
            !achbotWebhookIngressAuthenticated() || $actor<=0 || $primary<=0 ||
            !is_array($cached) || (int)($cached['userid']??0)!==$actor ||
            (int)($cached['id']??0)<=0)
            throw new RuntimeException('legacy_moderation_ingress_or_actor_invalid');
        require_once __DIR__.'/src/Subscription/DirectGift.php';
        $start=in_array($operation,['startBan','startUnban'],true);
        $receipt=$operation==='receiptBan';
        $bound=$start?($operation==='startBan'?'banUser':'unbanUser'):
            ($receipt?'banUserDirect_'.$targetRaw:$targetRaw);
        $source=achbotDirectGiftWebhook(
            $update,$actor,$chat,$message,$bound,
            ($start || $receipt)?'callback':'message');
        $updateId=(int)$source['update_id'];
        $messageId=(int)$source['message_id'];
        if ($updateId<=0 || $messageId<=0)
            throw new RuntimeException('legacy_moderation_source_invalid');
        $action=($operation==='ban' || $receipt)?'Ban':'Unban';
        $target=0;
        if (!$start) {
            if (preg_match('/\A[1-9][0-9]{0,18}\z/D',$targetRaw)!==1)
                throw new RuntimeException('legacy_moderation_target_invalid');
            $parsed=filter_var($targetRaw,FILTER_VALIDATE_INT,
                ['options'=>['min_range'=>1,'max_range'=>PHP_INT_MAX]]);
            if ($parsed===false)
                throw new RuntimeException('legacy_moderation_target_overflow');
            $target=$parsed;
        }
        // Pre-resolve nonunique userids before BEGIN. No consistent read
        // precedes the unique-PK FOR UPDATE locks inside the transaction.
        $actorPk=achbotModerationUniquePk($db,$actor);
        $targetPk=$start?$actorPk:achbotModerationUniquePk($db,$target);
        if ($actorPk!==(int)$cached['id'])
            throw new RuntimeException('legacy_moderation_cached_pk_drift');
        if (!$db->begin_transaction())
            throw new RuntimeException('legacy_moderation_begin_failed');
        $started=true;
        $stmt=$db->prepare('SELECT id,userid,isAdmin,step FROM users
            WHERE id IN (?,?) ORDER BY id FOR UPDATE');
        if (!$stmt) throw new RuntimeException('legacy_moderation_lock_prepare');
        $stmt->bind_param('ii',$actorPk,$targetPk);
        $ok=$stmt->execute();
        $rows=$ok?$stmt->get_result()->fetch_all(MYSQLI_ASSOC):[];
        $stmt->close();
        if (!$ok) throw new RuntimeException('legacy_moderation_lock_failed');
        $actorRow=null;$targetRow=null;
        foreach ($rows as $row) {
            if ((int)$row['id']===$actorPk) $actorRow=$row;
            if ((int)$row['id']===$targetPk) $targetRow=$row;
        }
        if (!$actorRow || !$targetRow ||
            (int)$actorRow['userid']!==$actor ||
            (int)$targetRow['userid']!==($start?$actor:$target) ||
            ($actor!==$primary && (int)$actorRow['isAdmin']!==1))
            throw new RuntimeException('legacy_moderation_role_revoked_or_target_drift');
        foreach (array_unique([$actor,$target]) as $userid) {
            if ($userid<=0) continue;
            $expectedPk=$userid===$actor?$actorPk:$targetPk;
            if (achbotModerationUniquePk($db,$userid)!==$expectedPk)
                throw new RuntimeException('legacy_moderation_duplicate_userid');
        }
        $actorStep=(string)$actorRow['step'];
        if ($start) {
            if ($actorStep==='banned' ||
                str_starts_with($actorStep,'achbotDirectGift') ||
                str_starts_with($actorStep,'achbotExempt:'))
                throw new RuntimeException('legacy_moderation_start_protected');
            $next=$operation==='startBan'?'banUser':'unbanUser';
            if ($next!==$actorStep) {
                $stmt=$db->prepare('UPDATE users SET step=?
                    WHERE id=? AND userid=? AND step=?');
                if (!$stmt) throw new RuntimeException('legacy_moderation_start_prepare');
                $stmt->bind_param('siis',$next,$actorPk,$actor,$actorStep);
                $ok=$stmt->execute();$changed=$stmt->affected_rows;$stmt->close();
                if (!$ok || $changed!==1)
                    throw new RuntimeException('legacy_moderation_start_cas');
            }
            achbotModerationActorJournal(
                $db,$actor,$updateId,$messageId,$operation,0);
            $status='started';
        } else {
            $expected=$operation==='ban'?'banUser':
                ($operation==='unban'?'unbanUser':null);
            if (!$receipt && ($expected===null ||
                $actorStep!==$expected ||
                (string)($cached['step']??'')!==$expected))
                throw new RuntimeException('legacy_moderation_step_stale');
            $targetStep=(string)$targetRow['step'];
            if (str_starts_with($targetStep,'achbotDirectGift') ||
                str_starts_with($targetStep,'achbotExempt:'))
                throw new RuntimeException('legacy_moderation_target_protected');
            // Acquire and validate both source records while the exact
            // actor/target PKs are held. A different moderator's newer Ban
            // must defeat an older in-flight Unban, even after revocation.
            achbotModerationActorJournal(
                $db,$actor,$updateId,$messageId,$operation,$target);
            achbotModerationTargetJournal(
                $db,$target,$actor,$updateId,$messageId,$action);
            $desired=$action==='Ban'?'banned':'none';
            $status=$targetStep===$desired?'already':'changed';
            if ($status==='changed') {
                $stmt=$db->prepare('UPDATE users SET step=?
                    WHERE id=? AND userid=? AND step=?');
                if (!$stmt) throw new RuntimeException('legacy_moderation_target_prepare');
                $stmt->bind_param('siis',$desired,$targetPk,$target,$targetStep);
                $ok=$stmt->execute();$changed=$stmt->affected_rows;$stmt->close();
                if (!$ok || $changed!==1)
                    throw new RuntimeException('legacy_moderation_target_cas');
            }
            // A user can deliberately Ban their own privileged account.
            // In that case the target transition is also their actor step;
            // resetting the actor afterward would silently undo the Ban.
            if (!$receipt && !($actor===$target && $desired==='banned')) {
                $actorNext='none';
                $stmt=$db->prepare('UPDATE users SET step=?
                    WHERE id=? AND userid=? AND step=?');
                if (!$stmt) throw new RuntimeException('legacy_moderation_actor_finish_prepare');
                $stmt->bind_param('siis',$actorNext,$actorPk,$actor,$expected);
                $ok=$stmt->execute();$changed=$stmt->affected_rows;$stmt->close();
                if (!$ok || $changed!==1)
                    throw new RuntimeException('legacy_moderation_actor_finish_cas');
            }
        }
        if (!$db->commit())
            throw new RuntimeException('legacy_moderation_commit_unknown');
        $started=false;
        return $status;
    } catch (Throwable $error) {
        if ($started) {
            try {$db->rollback();} catch (Throwable $ignored) {}
        }
        error_log('legacy_moderation_source_or_commit_unconfirmed');
        return 'rejected';
    }
}

//---

function getCallbackFromCommand($command) {
    global $connection, $userInfo, $from_id, $admin, $botState, $buttonValues, $chat_id;

    $isRealAdmin = ($from_id == $admin || !empty($userInfo['isAdmin']));

    switch ($command) {
        case '/buy':
            if (($botState['sellState'] ?? 'off') !== "on" && !$isRealAdmin) {
                sendMessage(\achbotMessage('admin_settings.config.get_callback_from_command.message.616c172303'), null, "MarkDown", $chat_id);
                exit();
            }

            if (($botState['cartToCartState'] ?? 'off') == "off" && ($botState['giftCardState'] ?? 'off') == "off" && ($botState['manualUsdtState'] ?? 'off') == "off" && ($botState['walletState'] ?? 'off') == "off") {
                sendMessage(\achbotMessage('admin_settings.config.get_callback_from_command.message.616c172303'), null, "MarkDown", $chat_id);
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
                    \achbotMessage('admin_settings.config.get_callback_from_command.message.ce7759512b'),
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

// Reuse the authenticated update decoded above. Never reparse arbitrary
// payment/Cron request bodies as Telegram messages or callbacks.
// Administrative toggle dispatch is intentionally deferred to bot.php AFTER check().

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
                sendMessage(\achbotMessage('admin_settings.config.get_callback_from_command.message.616c172303'), null, "MarkDown", $chat_id);
                exit;
            }

            if (($botState['cartToCartState'] ?? 'off') == "off" && ($botState['giftCardState'] ?? 'off') == "off" && ($botState['manualUsdtState'] ?? 'off') == "off" && ($botState['walletState'] ?? 'off') == "off") {
                sendMessage(\achbotMessage('admin_settings.config.get_callback_from_command.message.616c172303'), null, "MarkDown", $chat_id);
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
                        ['text'=>\achbotMessage('admin_settings.config.get_agents_list.button.2b2966a54e'),'callback_data'=>"delMainButton" . $rowId],
                        ['text'=>$title??" " ,'callback_data'=>"wizwizch"]];
        }
    }else{
        $keys[] = [['text'=>\achbotMessage('admin_settings.config.get_main_menu_buttons_keys.button.e8debfae48'),'callback_data'=>"wizwizch"]];
    }
    $keys[] = [['text'=>\achbotMessage('admin_settings.config.get_main_menu_buttons_keys.button.6c3bcb48ce'),'callback_data'=>"addNewMainButton"]];
    $keys[] = [['text'=>$buttonValues['back_button'],'callback_data'=>"managePanel"]];
    return json_encode(['inline_keyboard'=>$keys]);
}
function getPlanDetailsKeys($planId){
    global $connection,$buttonValues;
    require_once __DIR__.'/src/Presentation/Text.php';
    $planId=(int)$planId;
    $stmt=$connection->prepare("SELECT * FROM server_plans WHERE id=?");
    $stmt->bind_param('i',$planId);$stmt->execute();$plan=$stmt->get_result()->fetch_assoc();$stmt->close();
    if (!$plan || (int)$plan['inbound_id']!==0) return null;
    $serverId=(int)$plan['server_id'];
    $stmt=$connection->prepare("SELECT type FROM server_config WHERE id=?");
    $stmt->bind_param('i',$serverId);$stmt->execute();$server=$stmt->get_result()->fetch_assoc();$stmt->close();
    if (!achbotSupportsPanel($server['type']??null)) return null;
    $stmt=$connection->prepare("SELECT COUNT(*) AS total FROM orders_list WHERE status=1 AND fileid=?");
    $stmt->bind_param('i',$planId);$stmt->execute();$count=(int)$stmt->get_result()->fetch_assoc()['total'];$stmt->close();
    $days=\AchBot\Presentation\Text\serviceAllowanceState($plan['days'],true);
    $volume=\AchBot\Presentation\Text\serviceAllowanceState($plan['volume'],true);
    $dayLabel=match($days['state']) {
        'unlimited'=>\achbotMessage('admin_settings.management.plan_days_unlimited'),
        'finite'=>\achbotMessage('admin_settings.management.plan_days_label',['value'=>$days['display']]),
        default=>\achbotMessage('admin_settings.management.plan_days_unknown')
    };
    $volumeLabel=match($volume['state']) {
        'unlimited'=>\achbotMessage('admin_settings.management.plan_volume_unlimited'),
        'finite'=>\achbotMessage('admin_settings.management.plan_volume_label',['value'=>$volume['display']]),
        default=>\achbotMessage('admin_settings.management.plan_volume_unknown')
    };
    $keys=[
        [['text'=>$plan['title'],'callback_data'=>'wizwizplanname'.$planId],
         ['text'=>\achbotMessage('admin_settings.config.get_plan_details_keys.button.e1615aff08'),'callback_data'=>'wizwizch']],
        [['text'=>number_format($plan['price']).\achbotMessage('admin_settings.config.get_gate_ways_keys.text.2cc98f7f7c'),'callback_data'=>'wizwizplanrial'.$planId],
         ['text'=>\achbotMessage('admin_settings.config.get_plan_details_keys.button.433f647c69'),'callback_data'=>'wizwizch']],
        [['text'=>$dayLabel,'callback_data'=>'planDays'.$planId],
         ['text'=>$volumeLabel,'callback_data'=>'planVolume'.$planId]],
        [['text'=>\achbotMessage('admin_settings.config.get_plan_details_keys.button.f2662557fe'),'callback_data'=>'wizwizplaneditdes'.$planId]],
        [['text'=>$count,'callback_data'=>'wizwizch'],
         ['text'=>\achbotMessage('admin_settings.config.get_plan_details_keys.button.a3ec946c94'),'callback_data'=>'wizwizch']],
        [['text'=>\achbotMessage('admin_settings.config.get_plan_details_keys.button.c1c11f088f'),'callback_data'=>'wizwizplanacclist'.$planId]],
        $server['type']==='marzban'?
            [['text'=>\achbotMessage('admin_settings.config.get_plan_details_keys.button.51a75fa72c'),'callback_data'=>'marzbanHostSettings'.$planId]]:
            [['text'=>\achbotMessage('admin_settings.config.get_plan_details_keys.button.6399e067cb'),'callback_data'=>'pgPlanGroups'.$planId]],
        [['text'=>\achbotMessage('admin_settings.config.get_plan_details_keys.button.c3a5bc0cbd'),'callback_data'=>'wizwizplandelete'.$planId]],
        [['text'=>$buttonValues['back_button'],'callback_data'=>'plansList'.$serverId]]
    ];
    return json_encode(['inline_keyboard'=>$keys],JSON_UNESCAPED_UNICODE);
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
function getOrderTrafficInfo($serverType, $server_id, $remark, $uuid, $inbound_id) {
    require_once __DIR__ . '/src/Subscription/AccountDetails.php';
    return \AchBot\Subscription\AccountDetails\getOrderTrafficInfo($serverType, $server_id, $remark, $uuid, $inbound_id);
}
function buildSubscriptionPresentation($provider, $native, $botState, $options = [], $context = 'view', $serverId = 0) {
    global $connection;
    require_once __DIR__ . '/src/Subscription/ServerDisplaySettings.php';
    // Keep the established facade signature; caller/private overrides are retired.
    $resolved = achbotServerDisplayOptions($connection ?? null, (int)$serverId, (string)$provider);
    return \AchBot\Subscription\Presentation\present($provider, $native, $botState, $resolved, $context);
}

function buildConfigLinks($acc_link, $botState, $unusedIndexes = [], $serverId = 0) {
    return buildSubscriptionPresentation('marzban', ['links' => $acc_link], $botState,
        [], 'view', $serverId)['config_html'];
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
    global $mainValues;
    require_once __DIR__ . '/src/Subscription/Presentation.php';
    return \AchBot\Subscription\Presentation\configMessage(
        $mainValues['config_details_message'], $enableText, $remark,
        $configLinks, $subLink, $backupSection
    );
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
    require_once __DIR__.'/src/Subscription/AccountDetails.php';
    $mode=$match[1];$orderId=(int)$match[2];$begun=false;$commitAttempted=false;
    try {
        $actor=\AchBot\Subscription\AccountDetails\currentOrderDetailActor();
        $callback=$update->callback_query??null;$actorId=(int)($actor['userid']??0);
        if ($actor===null || $orderId<=0 || $orderId>2147483647 || strlen($data)>64 ||
            !is_object($callback) || ($callback->data??null)!==$data ||
            (int)($callback->from->id??0)!==$actorId ||
            (int)($callback->message->chat->id??0)!==$actorId ||
            ($callback->message->chat->type??'')!=='private')
            throw new RuntimeException('renew_consent_actor_unavailable');
        $actorPk=(int)$actor['id'];
        if (!$connection->begin_transaction()) throw new RuntimeException('renew_consent_begin_failed');
        $begun=true;
        $stmt=$connection->prepare('SELECT * FROM orders_list WHERE id=? FOR UPDATE');
        $stmt->bind_param('i',$orderId);$stmt->execute();$order=$stmt->get_result()->fetch_assoc();$stmt->close();
        $stmt=$connection->prepare('SELECT id,userid,isAdmin,step FROM users WHERE id=? AND userid=? FOR UPDATE');
        $stmt->bind_param('ii',$actorPk,$actorId);$stmt->execute();$freshActor=$stmt->get_result()->fetch_assoc();$stmt->close();
        if (!$order || (int)$order['status']!==1 || !$freshActor || $freshActor['step']==='banned' ||
            ($mode==='toggleAutoRenew' && (int)$order['userid']!==$actorId) ||
            ($mode==='adminToggleAutoRenew' && $actorId!==(int)$admin && (int)$freshActor['isAdmin']!==1))
            throw new RuntimeException('renew_consent_forbidden');
        $current=(string)($order['autoRenew']??'off');
        if (!in_array($current,['on','off'],true)) throw new RuntimeException('renew_consent_state_unverified');
        $new=$current==='on'?'off':'on';
        // Disabling consent remains possible even if a former product is no
        // longer available. Enabling requires real origin and a valid paid plan.
        if ($new==='on' && (($botState['renewAutoState']??'off')!=='on' ||
            \AchBot\Subscription\AccountDetails\selectedPaidPlan($connection,$order,true)===null ||
            !achbotSupportsOrder($connection,$orderId,(int)$order['fileid'],(int)$order['userid'],true))) {
            $connection->rollback();$begun=false;
            alert(\achbotMessage('admin_settings.config.get_order_detail_keys.alert.8338c921ca'),true);return;
        }
        $owner=(int)$order['userid'];
        $stmt=$connection->prepare('UPDATE orders_list SET autoRenew=? WHERE id=? AND userid=? AND autoRenew=? AND status=1');
        $stmt->bind_param('siis',$new,$orderId,$owner,$current);
        if (!$stmt->execute() || $stmt->affected_rows!==1) {$stmt->close();throw new RuntimeException('renew_consent_update_unconfirmed');}
        $stmt->close();$commitAttempted=true;
        if (!$connection->commit()) throw new RuntimeException('renew_consent_commit_unknown');
        $begun=false;
    } catch (Throwable $error) {
        if ($begun) {try {$connection->rollback();}catch(Throwable $ignored) {}}
        error_log('achbot_renew_consent_unverified order_id='.$orderId);
        alert(\achbotMessage('subscription.account_details.auto_renew_update_unverified'),true);
        return;
    }
    $keys=$mode==='adminToggleAutoRenew'?getUserOrderDetailKeys($orderId):getOrderDetailKeys($owner,$orderId);
    if (!$keys || empty($keys['msg']) || empty($keys['keyboard'])) {
        alert(\achbotMessage('admin_settings.config.get_order_detail_keys.alert.50f82fc4e9'),true);return;
    }
    editText($message_id,$keys['msg'],$keys['keyboard'],'HTML');
    alert(\achbotMessage('admin_settings.config.get_order_detail_keys.alert.d0438c36e0',[
        'order_remark'=>$order['remark']]).
        ($new==='on'?\achbotMessage('admin_settings.config.get_order_detail_keys.alert.be9fd3bd1b'):
            \achbotMessage('admin_settings.config.get_order_detail_keys.alert.841b8cb341')).
        \achbotMessage('admin_settings.config.get_order_detail_keys.alert.c826d2d1f7'),true);
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
    // The guarded direct-gift wizard exclusively owns its active nonce step.
    // Generic legacy routes have neither a verified ban/unban source nor
    // ownership of an active direct-gift generation. Only the dedicated
    // authorized unban endpoints may consume 'banned'.
    // Other legacy routes use cached userInfo and must not overwrite it.
    elseif($field == "step") $stmt = $connection->prepare("UPDATE `users` SET `step` = ? WHERE `userid` = ? AND `step` <> 'banned' AND `step` NOT LIKE 'achbotDirectGift%' AND `step` NOT LIKE 'achbotExempt:%'");
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
// The closed provider contract controls current support; unknown types fail closed.
function achbotSupportsPanel($type) {
    return achbotSupportsProviderType($type);
}

function getMarzbanToken($server_id, ?string $expectedPanelUrl = null, ?array $effectTarget = null) {
    require_once __DIR__ . '/src/Panel/Accounts.php';
    return \AchBot\Panel\Accounts\getMarzbanToken($server_id, $expectedPanelUrl, $effectTarget);
}
function getMarzbanJson($server_id, $token = null, ?array $giftTarget=null) {
    require_once __DIR__ . '/src/Panel/Accounts.php';
    return \AchBot\Panel\Accounts\getMarzbanJson($server_id,$token,$giftTarget);
}
function getMarzbanUserInfo($server_id, $remark) {
    require_once __DIR__ . '/src/Panel/Accounts.php';
    return \AchBot\Panel\Accounts\getMarzbanUserInfo($server_id, $remark);
}
function getMarzbanUser($server_id, $remark, $token = null, bool $strict = false, ?string $expectedPanelUrl = null, ?array $effectTarget = null) {
    require_once __DIR__ . '/src/Panel/Accounts.php';
    return \AchBot\Panel\Accounts\getMarzbanUser(
        $server_id, $remark, $token, $strict, $expectedPanelUrl, $effectTarget);
}
function getMarzbanHosts($server_id) {
    require_once __DIR__ . '/src/Panel/Accounts.php';
    return \AchBot\Panel\Accounts\getMarzbanHosts($server_id);
}
function addMarzbanUser($server_id, $remark, $volume, $days, $plan_id, ?string $operationNote = null, ?int $fixedExpire = null, ?array $effectTarget = null) {
    require_once __DIR__ . '/src/Panel/Accounts.php';
    return \AchBot\Panel\Accounts\addMarzbanUser(
        $server_id,$remark,$volume,$days,$plan_id,$operationNote,$fixedExpire,$effectTarget);
}
function editMarzbanConfig($server_id,$info,?array $giftTarget=null) {
    require_once __DIR__ . '/src/Panel/Accounts.php';
    return \AchBot\Panel\Accounts\editMarzbanConfig($server_id,$info,$giftTarget);
}
function resetMarzbanTraffic($server_id, $remark, $token) {
    require_once __DIR__ . '/src/Panel/Accounts.php';
    return \AchBot\Panel\Accounts\resetMarzbanTraffic($server_id, $remark, $token);
}
function renewMarzbanUUID($server_id,$remark) {
    require_once __DIR__ . '/src/Panel/Accounts.php';
    return \AchBot\Panel\Accounts\renewMarzbanUUID($server_id, $remark);
}
function deleteMarzban($server_id,$remark,?array $expectedTarget=null) {
    require_once __DIR__ . '/src/Panel/Accounts.php';
    return \AchBot\Panel\Accounts\deleteMarzban($server_id, $remark,$expectedTarget);
}
function changeMarzbanState($server_id,$remark) {
    require_once __DIR__ . '/src/Panel/Accounts.php';
    return \AchBot\Panel\Accounts\changeMarzbanState($server_id, $remark);
}
// Added by ACh

function sendToAllAdmins($method, $data, $pay_hash_id = null, $options = []){
    global $admin, $connection;

    $adminMessages = [];

    foreach (achbotAdminRecipientCandidates($connection,(int)$admin) as $adminId) {
        if ($adminId != $admin &&
            achbotAdminRecipientAllowed($connection,$adminId,(int)$admin)) {
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

            // Options never redirect privileged content outside the checked recipient.
            $data['chat_id'] = (int)$adminId;
            $res = bot($method, $data);

            // ذخیره پیام اگر نیاز باشه
            if ($pay_hash_id && $res && isset($res->result->message_id)) {
                $adminMessages[] = [
                    'chat_id' => $data['chat_id'],
                    'message_id' => $res->result->message_id
                ];
            }
        }
    }

    // ذخیره در دیتابیس اگر hash_id داده شده
    if ($pay_hash_id && !empty($adminMessages)) {
        $json = json_encode($adminMessages, JSON_UNESCAPED_UNICODE);
        // Keep prior delivery evidence, including refs to subsequently demoted staff.
        // The existing UPDATE appends atomically; no transaction spans Telegram.
        $stmt = $connection->prepare("UPDATE `pays` SET `admins_messages` = CASE
            WHEN admins_messages IS NULL OR admins_messages='' THEN ?
            WHEN JSON_VALID(admins_messages) THEN CASE
                WHEN JSON_TYPE(admins_messages)='ARRAY'
                THEN JSON_MERGE_PRESERVE(admins_messages,?)
                ELSE admins_messages END
            ELSE admins_messages END WHERE `hash_id` = ?");
        $stmt->bind_param("sss", $json, $json, $pay_hash_id);
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
    return achbotAdminCan(achbotAdminButtonCapability((string)$key),(int)$userId);
}

?>
