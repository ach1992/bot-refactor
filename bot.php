<?php
require_once __DIR__ . '/messages/loader.php'; // ACHBOT_TEXT_CATALOG


// ACHBOT_WEBHOOK_INGRESS_BEGIN
// All webhook-driven mutations must be authenticated BEFORE loading config.php:
// config.php initializes routes and may read request-derived state.
// Load only this installation's trusted private variables in global scope.
// config.php later uses require_once, so these bindings must not be function-local.
if (is_file(__DIR__ . '/baseInfo.php')) {
    require_once __DIR__ . '/baseInfo.php';
}
function achbotWebhookIngressAuthenticated(): bool {
    $secret=$GLOBALS['telegramWebhookSecret']??null;
    $received=$_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']??null;
    return ($_SERVER['REQUEST_METHOD']??null)==='POST' &&
        is_string($secret) && strlen($secret)>=16 && strlen($secret)<=256 &&
        preg_match('/\A[A-Za-z0-9_-]+\z/D',$secret)===1 &&
        is_string($received) && hash_equals($secret,$received);
}
if (!achbotWebhookIngressAuthenticated()) {
    http_response_code(403);
    error_log('achbot_webhook_ingress_unverified');
    return;
}
// ACHBOT_WEBHOOK_INGRESS_END
// Included configuration can run old request routes during initialization;
// it must enforce the existing Telegram IP allowlist before those routes.
define('ACHBOT_VERIFIED_WEBHOOK_ENTRYPOINT', true);

require_once 'config.php';
require_once 'buy_helpers.php';
check();
require_once __DIR__.'/src/Subscription/ServerDisplaySettings.php';
if (achbotHandleServerDisplayAdmin()) return;
require_once __DIR__.'/src/Panel/ProviderAdmin.php';
if (achbotHandleProviderAdmin()) return;
// Source-authenticated and IP-checked before any administrative moderation.
if (handleToggleCallbacks($data??null,$chat_id??null,$message_id??null)) return;
// Channel-exemption settings are admin-only and cannot execute before check().
if (achbotHandleChannelExemption($data??null,$text??null,$userInfo??null)) return;
// Privileged exemption changes are never allowed inside config bootstrap.
if (achbotHandleChannelExemption($data??null,$text??null,$userInfo??null)) return;

/*
$log_data = "📩 New Update at " . date("Y-m-d H:i:s") . "\n";
$log_data .= "🔢 From ID: " . ($from_id ?? 'N/A') . "\n";
$log_data .= "👤 Username: @" . ($username ?? 'N/A') . "\n";
$log_data .= "💬 Text: " . ($text ?? 'N/A') . "\n";
$log_data .= "📦 Callback Data: " . ($data ?? 'N/A') . "\n";
$log_data .= "🧾 Step: " . ($userInfo['step'] ?? 'N/A') . "\n";
$log_data .= "📄 Full Update: " . print_r(json_decode(file_get_contents("php://input"), true), true);
$log_data .= "--------------------------\n";

// ذخیره در فایل لاگ
file_put_contents(__DIR__ . "/full_log.txt", $log_data, FILE_APPEND);
*/

$robotState = $botState['botState']??"on";

GOTOSTART:
if ($userInfo['step'] == "banned" && $from_id != $admin && $userInfo['isAdmin'] != true) {
    sendMessage($mainValues['banned']);
    exit();
}

$checkSpam = checkSpam();
if(is_numeric($checkSpam)){
    $time = jdate("Y-m-d H:i:s", $checkSpam);
    sendMessage(\achbotMessage('bot.bot.webhook_ingress_authenticated.message.5e45680470', ['time' => $time]));
    exit();
}

// Reject malformed direct wallet and manual-choice callback frames before
// another payment route can interpret a substring as a different action.
if (is_string($data) && preg_match(
    '/\A(?:pay(?:Buy|Custom|Renew|IncreaseDay|Increase)With(?:Wallet|CartToCart|GiftCard|ManualUsdt)|payIncreaseWalletWith(?:CartToCart|GiftCard|ManualUsdt))/',
    $data, $paymentAction)) {
    if (strlen($data) > 64 || !preg_match(
        '/\A' . preg_quote($paymentAction[0], '/') . '[A-Za-z0-9_-]{1,50}\z/D',
        $data)) {
        answerCallbackQuery($callbackId,
            \achbotMessage('bot.bot.webhook_ingress_authenticated.alert.d4b0660226'), true);
        return;
    }
}

if (($achbotV8Result = require __DIR__ . '/src/Finance/Routes/PrePayment.php') === null) return;
if (($achbotManualCryptoResult = require __DIR__ . '/src/Finance/Routes/ManualCrypto.php') === null) return;
// END Pre Pay Check By ACh

if (preg_match("/^haveJoined(.*)/", $data, $match)) {
    if ($joniedState == "kicked" || $joniedState == "left") {
        alert($mainValues['not_joine_yet']);
        exit();
    } else {
        delMessage();
        $text = isset($match[1]) ? $match[1] : '';
    }
}

if (($joniedState == "kicked" || $joniedState == "left") && $from_id != $admin) {

    $stmt = $connection->prepare("SELECT `value` FROM `setting` WHERE `type` = 'EXEMPTED_USERS'");
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $exemptedUsers = json_decode($row['value'], true);
    if (!is_array($exemptedUsers)) $exemptedUsers = [];

    if (!in_array($from_id, $exemptedUsers)) {

        $lockChannelValue = trim($botState['lockChannel'] ?? '');
        $joinUrl = $joinLink;

        if ($lockChannelValue !== '') {
            if (preg_match('~^https?://t\.me/~i', $lockChannelValue)) {
                $joinUrl = $lockChannelValue;

            } else {
                if ($lockChannelValue[0] === '@') {
                    $username = ltrim($lockChannelValue, '@');
                    $joinUrl = "https://t.me/" . $username;

                } else {
                    if (preg_match('/^[A-Za-z0-9_]{5,32}$/', $lockChannelValue)) {
                        $joinUrl = "https://t.me/" . $lockChannelValue;

                    } else {
                        if ($lockChannelValue[0] === '+') {
                            $joinUrl = "https://t.me/" . $lockChannelValue;

                        } else {
                            $joinUrl = $joinLink;
                        }
                    }
                }
            }
        }

        sendMessage(
            str_replace("CHANNEL-ID", $channelLock, $mainValues['join_channel_message']),
            json_encode([
                'inline_keyboard' => [
                    [
                        ['text' => $buttonValues['join_channel'], 'url' => $joinUrl]
                    ],
                    [
                        ['text' => $buttonValues['have_joined'], 'callback_data' => 'haveJoined' . (isset($text) ? $text : '')]
                    ]
                ]
            ]),
            "HTML"
        );
        exit;
    }
}

if($robotState == "off" && $from_id != $admin){
    sendMessage($mainValues['bot_is_updating']);
    exit();
}

if(strstr($text, "/start ")){
    $inviter = str_replace("/start ", "", $text);
    if($inviter < 0) exit();

    if($uinfo->num_rows == 0 && $inviter != $from_id){
        $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid` = ?");
        $stmt->bind_param("i", $inviter);
        $stmt->execute();
        $inviterInfo = $stmt->get_result();
        $stmt->close();

        if($inviterInfo->num_rows > 0){
            $inviterData = $inviterInfo->fetch_assoc();

            $first_name = !empty($first_name) ? $first_name : " ";
            $username = !empty($username) ? $username : " ";

            // ثبت کاربر جدید
            $time = time();
            $sql = "INSERT INTO `users` (`userid`, `name`, `username`, `refcode`, `wallet`, `date`, `refered_by`)
                    VALUES (?,?,?, 0,0,?,?)";
            $stmt = $connection->prepare($sql);
            $stmt->bind_param("issii", $from_id, $first_name, $username, $time, $inviter);
            $stmt->execute();
            $stmt->close();

				if ($inviteReward > 0) {

					// موجودی قدیم دعوت‌کننده
					$oldWallet = $inviterData['wallet'];
					$newWallet = $oldWallet + $inviteReward;

					// آپدیت موجودی
					$stmt = $connection->prepare("UPDATE `users` SET `wallet` = ? WHERE `userid` = ?");
					$stmt->bind_param("ii", $newWallet, $inviter);
					$stmt->execute();
					$stmt->close();

					// ارسال پیام به دعوت‌کننده
					$inviterMsg = \achbotMessage('bot.bot.webhook_ingress_authenticated.inviter_msg.0a64da8ef5')
						. \achbotMessage('bot.bot.webhook_ingress_authenticated.text.750b1aaad1') . number_format($inviteReward) . \achbotMessage('bot.bot.webhook_ingress_authenticated.text.81a62ff6c6')
						. \achbotMessage('bot.bot.webhook_ingress_authenticated.text.87d0794e8e') . number_format($oldWallet) . \achbotMessage('bot.bot.webhook_ingress_authenticated.text.81a62ff6c6')
						. \achbotMessage('bot.bot.webhook_ingress_authenticated.text.447278518a') . number_format($newWallet) . \achbotMessage('bot.bot.webhook_ingress_authenticated.text.2cc98f7f7c');
					sendMessage($inviterMsg, null, "html", $inviter);

					// ارسال پیام به ادمین
					$adminMsg = \achbotMessage('bot.bot.webhook_ingress_authenticated.admin_msg.a511ccf2ec', ['inviter' => $inviter])
						. \achbotMessage('bot.bot.webhook_ingress_authenticated.text.c69814bacf', ['from_id' => $from_id])
						. \achbotMessage('bot.bot.webhook_ingress_authenticated.text.5f8f2b70e9') . number_format($inviteReward) . \achbotMessage('bot.bot.webhook_ingress_authenticated.text.81a62ff6c6')
						. \achbotMessage('bot.bot.webhook_ingress_authenticated.text.87d0794e8e') . number_format($oldWallet) . \achbotMessage('bot.bot.webhook_ingress_authenticated.text.81a62ff6c6')
						. \achbotMessage('bot.bot.webhook_ingress_authenticated.text.447278518a') . number_format($newWallet) . \achbotMessage('bot.bot.webhook_ingress_authenticated.text.2cc98f7f7c');
					sendMessage($adminMsg, null, "html", $admin);

					// ارسال به کانال گزارشات
				if (!empty($botState['rewardChannel'])) {
					sendMessage($adminMsg, null, "html", $botState['rewardChannel']);
				}
			}

            setUser("referedBy" . $inviter);
            $userInfo['step'] = "referedBy" . $inviter;
        }
    }

    $text = "/start";
}

if($userInfo['phone'] == null && $from_id != $admin && $userInfo['isAdmin'] != true && $botState['requirePhone'] == "on"){
    if(isset($update->message->contact)){
        $contact = $update->message->contact;
        $phone_number = $contact->phone_number;
        $phone_id = $contact->user_id;
        if($phone_id != $from_id){
            sendMessage($mainValues['please_select_from_below_buttons']);
            exit();
        }else{
            if(!preg_match('/^\+98(\d+)/',$phone_number) && !preg_match('/^98(\d+)/',$phone_number) && !preg_match('/^0098(\d+)/',$phone_number) && $botState['requireIranPhone'] == 'on'){
                sendMessage($mainValues['use_iranian_number_only']);
                exit();
            }
            setUser($phone_number, 'phone');

            sendMessage($mainValues['phone_confirmed'],$removeKeyboard);
            $text = "/start";

            $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid`=?");
            $stmt->bind_param("i", $from_id);
            $stmt->execute();
            $uinfo = $stmt->get_result();
            $userInfo = $uinfo->fetch_assoc();
            $stmt->close();
        }
    }else{
        sendMessage($mainValues['send_your_phone_number'], json_encode([
			'keyboard' => [[[
					'text' => $buttonValues['send_phone_number'],
					'request_contact' => true,
				]]],
			'resize_keyboard' => true
		]));
		exit();
    }
}

$paymentHomeMatch=[];
$paymentHome=is_string($data) && preg_match('/^ph_([A-Za-z0-9_-]{1,50})$/D',$data,$paymentHomeMatch)===1;
if (preg_match('/^\/([Ss]tart)/', $text) || $text == $buttonValues['back_to_main'] || $data == 'mainMenu' || $data == 'evidenceHome' || $paymentHome) {

    // Config has an early cached userInfo. A newer moderation ban or an
    // active DirectGift wizard takes precedence over old menu callbacks.
    $menuActor=(int)$from_id;
    $menu=$connection->prepare('SELECT id,step FROM users WHERE userid=? LIMIT 2');
    if (!$menu) return;
    $menu->bind_param('i',$menuActor);
    $menuOk=$menu->execute();
    $menuRows=$menuOk?$menu->get_result()->fetch_all(MYSQLI_ASSOC):[];
    $menu->close();
    if (!$menuOk || count($menuRows)!==1 ||
        (string)$menuRows[0]['step']==='banned' ||
        str_starts_with((string)$menuRows[0]['step'],'achbotDirectGift') ||
        str_starts_with((string)$menuRows[0]['step'],'achbotExempt:')) return;

    setUser();
    setUser("", "temp");

    $isRealAdmin  = ($from_id == $admin || (!empty($userInfo['isAdmin']) && $userInfo['isAdmin'] == true));
    $isFirstStart = ($from_id != $admin && empty($userInfo['first_start']));
    $canBuy       = (($botState['sellState'] ?? 'off') == "on" || $isRealAdmin);

    if (isset($data) && ($data == "mainMenu" || $data == "evidenceHome" || $paymentHome)) {
        // Legacy Home buttons can be on untracked financial/support evidence.
        // Always send the actor's own menu; never edit/delete the source or
        // read another customer's invoice merely to navigate Home.
        sendMessage($mainValues['start_message'], getMainKeys());

    } else {

        if ($isFirstStart) {

            setUser('sent', 'first_start');

            $keys = json_encode([
                'inline_keyboard' => [
                    [
                        ['text' => $buttonValues['send_message_to_user'], 'callback_data' => 'sendMessageToUser' . $from_id]
                    ]
                ]
            ]);

            $msg = str_replace(
                ["FULLNAME", "USERNAME", "USERID"],
                [
                    "<a href='tg://user?id=$from_id'>$first_name</a>",
                    $username,
                    $from_id
                ],
                $mainValues['new_member_joined']
            );

            sendMessage($msg, $keys, "html", $admin);
			//sendToAllAdmins('sendMessage', ['text' => $msg,'reply_markup' => $keys,'parse_mode' => "html"]);

            $rewardChannel = $botState['rewardChannel'] ?? '';
            if (!empty($rewardChannel)) {
                sendMessage($msg, $keys, "html", $rewardChannel);
            }
        }

        sendMessage($mainValues['start_message'], getMainKeys());

        if ($isFirstStart) {

            $userMessage = $mainValues['user_welcome_message'];

            $secondButton = $canBuy
                ? ['text' => $buttonValues['buy_subscriptions'], 'callback_data' => 'buySubscription']
                : ['text' => $buttonValues['back_to_main'], 'callback_data' => 'mainMenu'];

            $keyboard = [
                [
                    ['text' => $buttonValues['my_subscriptions'], 'callback_data' => 'mySubscriptions'],
                    $secondButton
                ]
            ];

            sendMessage(
                $userMessage,
                json_encode(['inline_keyboard' => $keyboard]),
                "html",
                $from_id
            );
        }
    }

    exit;
}

if(preg_match('/^sendMessageToUser(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    editText($message_id,\achbotMessage('bot.bot.webhook_ingress_authenticated.message.305cb98154'));
    setUser($data);
}
if(preg_match('/^sendMessageToUser(\d+)/',$userInfo['step'],$match) && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    sendMessage($text,null,null,$match[1]);
	sendMessage(\achbotMessage('bot.bot.webhook_ingress_authenticated.message.21095e090c', ['match_1' => $match[1]]), $removeKeyboard, "html");
    sendMessage($mainValues['reached_main_menu'],getAdminKeys());
    setUser();
}


// هندلر فروش در بازه دلخواه
// این بخش باید در bot.php باشد تا بعد از require_once config.php و در مسیر اصلی ربات اجرا شود.
if (isset($data) && $data == "custom_sales_date" && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    setUser("awaitingDateRange", "step");
    sendMessage(
        \achbotMessage('bot.bot.webhook_ingress_authenticated.message.4e0fd88f4b') .
        \achbotMessage('bot.bot.webhook_ingress_authenticated.message.9c3afd43bd') .
        \achbotMessage('bot.bot.webhook_ingress_authenticated.message.312da09ba8'),
        $cancelKey,
        "HTML"
    );
    exit;
}

if (
    isset($userInfo['step']) &&
    $userInfo['step'] == "awaitingDateRange" &&
    ($from_id == $admin || $userInfo['isAdmin'] == true)
) {
    if (isset($text) && $text == $buttonValues['cancel']) {
        setUser("none", "step");
        sendMessage(\achbotMessage('bot.bot.webhook_ingress_authenticated.message.0108e71350'), $removeKeyboard, "HTML");
        exit;
    }

    // اگر آپدیت فعلی callback بود، این بخش نباید متن تاریخ را پردازش کند.
    if (isset($data) && !empty($data)) {
        exit;
    }

    $dateRange = trim($text ?? '');
    $dates = parseStatsDateRange($dateRange);

    if (!$dates) {
        sendMessage(
            \achbotMessage('bot.bot.webhook_ingress_authenticated.message.f438b1a27b'),
            $cancelKey,
            "HTML"
        );
        exit;
    }

    $startDate = $dates[0];
    $endDate = $dates[1];

    if (strtotime($startDate . ' 00:00:00') > strtotime($endDate . ' 23:59:59')) {
        sendMessage(\achbotMessage('bot.bot.webhook_ingress_authenticated.message.4684e19e66'), $cancelKey, "HTML");
        exit;
    }

    calculateSalesBetweenDates($startDate, $endDate);
    setUser("none", "step");
    exit;
}


// تعریف آرایه‌ای برای تمام گزینه‌های آماری
$statOptions = [
    'botReports',
    'show_stats_menu',
    'stats_users',
    'stats_orders',
    'stats_sales',
    'top_50_wallet',
    'top_50_orders',
    'top_50_users_pays',
    'agent_users_list',
    'plan_sales',
    'top_50_pays',
    'top_50_pays_month',
    'top_50_pays_week',
    'top_50_pays_24h'
];

// بررسی اگر داده در آرایه است و کاربر ادمین است
if (in_array($data, $statOptions) && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    // تعیین کلید آمار
    $statsKey = ($data == 'botReports') ? 'show_stats_menu' : $data;

    // آرایه برای عنوان‌ها
    $titles = [
        'stats_users' => \achbotMessage('bot.bot.webhook_ingress_authenticated.text.d4b423ba4f'),
        'stats_orders' => \achbotMessage('bot.bot.webhook_ingress_authenticated.text.2ef1b34354'),
        'stats_sales' => \achbotMessage('bot.bot.webhook_ingress_authenticated.text.adb2cb111d'),
    ];

    // انتخاب عنوان بر اساس کلید
    $title = $titles[$statsKey] ?? \achbotMessage('bot.bot.webhook_ingress_authenticated.text.cab0824f0a');

    $replyMarkup = getBotReportKeys($statsKey);

    if ($replyMarkup !== false && $replyMarkup !== null) {
        editText($message_id, $title, $replyMarkup);
    }

    exit;
}

require __DIR__ . '/src/Admin/Roles.php';
if(($data=="botSettings" or preg_match('/^changeBot([A-Za-z0-9_]+)$/D',$data,$match)) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if($data!="botSettings"){
        // Never persist callback-provided arbitrary BOT_STATES keys.
        // Keep this explicit list in sync with getBotSettingKeys().
        $editableBotToggles=[
            'agencyState','agencyPlanDiscount','individualExistence',
            'sharedExistence','testAccount','changeProtocolState',
            'renewAccountState','renewAutoState','plandelkhahState',
            'switchLocationState','increaseTimeState','increaseVolumeState',
            'requirePhone','requireIranPhone','sellState','botState',
            'searchState',
            'renewConfigLinkState','updateConfigLinkState','qrConfigState',
            'qrSubState','cartToCartAutoAcceptState','cartToCartAutoAcceptType'
        ];
        if (!in_array($match[1],$editableBotToggles,true)) {
            answerCallbackQuery($callbackId,\achbotMessage('bot.bot.webhook_ingress_authenticated.alert.c0889a8ad7'),true);
            return;
        }
        if($match[1] == "cartToCartAutoAcceptType") $newValue = $botState[$match[1]] == "0"?"1":($botState[$match[1]] == "1"?"2":0);
        else $newValue = ($botState[$match[1]] ?? 'off')==="on"?"off":"on";
        setSettings($match[1], $newValue);
    }
    editText($message_id,$mainValues['change_bot_settings_message'],getBotSettingKeys());
}
if($data=="changeUpdateConfigLinkState" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $newValue = $botState['updateConnectionState']=="robot"?"site":"robot";
    setSettings('updateConnectionState', $newValue);
    editText($message_id,$mainValues['change_bot_settings_message'],getBotSettingKeys());
}
if(($data=="gateWays_Channels" or preg_match('/^changeGateWays([A-Za-z0-9_]+)$/D',$data,$match)) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    // Merchant/public-destination menu also requires CURRENT authorization,
    // not merely a stale cached admin bit.
    if ($data==="gateWays_Channels" && !achbotGatewayAdminCanView(
            $update,(int)$from_id,(int)$chat_id,(int)$message_id,
            (int)$admin,$data)) {
        answerCallbackQuery($callbackId,\achbotMessage('bot.bot.webhook_ingress_authenticated.alert.18796a4549'),true);
        return;
    }
    if($data!="gateWays_Channels"){
        // Only currently displayed, supported gateway flags are editable.
        // Unknown or cross-surface callback identifiers cannot mutate BOT_STATES.
        $editableGatewayToggles=[
            'giftCardState','manualUsdtState','cartToCartState',
            'nextpay','zarinpal','nowPaymentWallet','nowPaymentOther',
            'walletState'
        ];
        if (!in_array($match[1],$editableGatewayToggles,true)) {
            answerCallbackQuery($callbackId,\achbotMessage('bot.bot.webhook_ingress_authenticated.alert.9eac3467d5'),true);
            return;
        }
        if (!achbotAdminGatewayChange($update,(int)$from_id,
                (int)$chat_id,(int)$message_id,(int)$admin,
                'toggle',$match[1])) {
            answerCallbackQuery($callbackId,
                \achbotMessage('bot.bot.webhook_ingress_authenticated.alert.31513d032b'),true);
            return;
        }
    }
    editText($message_id,$mainValues['change_bot_settings_message'],getGateWaysKeys());
}
// Strict, source-authenticated private operator rules. The older audience
// keyboard remains an alias, but new methods have independent category rules.
if (is_string($data??null) &&
    (str_starts_with($data,'gr|') || $data==='gatewayAudienceSettings')) {
    if (!achbotGatewayAdminCanView($update,(int)$from_id,
            (int)$chat_id,(int)$message_id,(int)$admin,$data)) {
        answerCallbackQuery($callbackId,\achbotMessage('bot.bot.webhook_ingress_authenticated.alert.18796a4549'),true);
        return;
    }
    $parts=explode('|',$data);
    $action=$parts[1]??'l';
    if ($data==='gatewayAudienceSettings') $data='gr|l';
    if (in_array($action,['l','m','r','f'],true)) {
        $page=achbotGatewayAdminPage($data);
        editText($message_id,$page['text'],$page['keyboard']);
        return;
    }
    $operation=achbotGatewayRuleCallback($data);
    if ($operation===null ||
        !achbotAdminGatewayChange($update,(int)$from_id,
           (int)$chat_id,(int)$message_id,(int)$admin,'rule',$data)) {
        answerCallbackQuery($callbackId,
            \achbotMessage('bot.bot.gateway_audience_settings.alert.ce46e4b2e2'),true);
        // A rejection must never leave a stale editor that invites more
        // unsafe clicks. Re-validate authorization before showing fresh data.
        if ($operation!==null &&
            in_array($operation['action'],['t','v','c','n'],true) &&
            achbotGatewayAdminCanView($update,(int)$from_id,
                (int)$chat_id,(int)$message_id,(int)$admin,$data)) {
            $refresh=in_array($operation['action'],['v','c'],true)
                ? 'gr|f|'.$operation['method'].'|'.$operation['category'].'|'.$operation['metric']
                : 'gr|r|'.$operation['method'].'|'.$operation['category'];
            $page=achbotGatewayAdminPage($refresh);
            editText($message_id,
                \achbotMessage('bot.bot.gateway_audience_settings.message.553d718ccf').
                $page['text'],$page['keyboard']);
        }
        return;
    }
    if ($operation['action']==='n') {
        sendMessage(\achbotMessage('bot.bot.gateway_audience_settings.message.56c51082c2').
            \achbotMessage('bot.bot.gateway_audience_settings.message.dff975b57d').
            \achbotMessage('bot.bot.gateway_audience_settings.message.8f166cf7c0'),$cancelKey);
        return;
    }
    if (in_array($operation['action'],['s','x'],true)) {
        sendMessage($operation['action']==='s'?\achbotMessage('bot.bot.gateway_audience_settings.message.fdfbaf2a40'):\achbotMessage('bot.bot.gateway_audience_settings.message.74d707fe02'));
        $page=achbotGatewayAdminPage('gr|l');
        sendMessage($page['text'],$page['keyboard']);
        return;
    }
    $target=$operation['action']==='d'
        ? 'gr|m|'.$operation['method']
        : ($operation['action']==='q' ? 'gr|q|'.$operation['method'].'|'.$operation['category']
        : (in_array($operation['action'],['v','c'],true)
           ? 'gr|f|'.$operation['method'].'|'.$operation['category'].'|'.$operation['metric']
           : 'gr|r|'.$operation['method'].'|'.$operation['category']));
    $page=achbotGatewayAdminPage($target,
        $operation['action']==='q'?(int)$update->update_id:null);
    editText($message_id,\achbotMessage('bot.bot.gateway_audience_settings.message.4ce38d7e7f')."\n".$page['text'],$page['keyboard']);
    return;
}
if (is_string($userInfo['step']??null) &&
    str_starts_with($userInfo['step'],'gr|edit|')) {
    if (!achbotAdminGatewayChange($update,(int)$from_id,
            (int)$chat_id,(int)$message_id,(int)$admin,'number',
            $userInfo['step'],(string)$text)) {
        sendMessage(\achbotMessage('bot.bot.gateway_audience_settings.message.f621efb0b3'),
                    $cancelKey);
        return;
    }
    if ($text===$buttonValues['cancel']) {
        sendMessage(\achbotMessage('bot.bot.gateway_audience_settings.message.74d707fe02'));
        return;
    }
    $sourceId=(int)($update->update_id??0);
    sendMessage(\achbotMessage('bot.bot.gateway_audience_settings.message.397d3e91b6').$text.\achbotMessage('bot.bot.gateway_audience_settings.message.c5bdeadc6b'),
        json_encode(['inline_keyboard'=>[
            [['text'=>\achbotMessage('bot.bot.gateway_audience_settings.button.9b026e4934'),'callback_data'=>'gr|s|'.$sourceId]],
            [['text'=>\achbotMessage('bot.bot.gateway_audience_settings.button.1fe862ea18'),'callback_data'=>'gr|x|'.$sourceId]]
        ]],JSON_UNESCAPED_UNICODE));
    return;
}
// Per-gateway audience restrictions. Re-check the current setting on every
// callback; a forwarded stale administrator button cannot pick arbitrary keys.
if (($from_id == $admin || (int)($userInfo['isAdmin']??0)===1) &&
    $data==='gatewayAudienceSettings') {
    editText($message_id,\achbotMessage('bot.bot.gateway_audience_settings.message.e2238b091f'),
             achbotGatewayAudienceKeyboard());
    return;
}
if (($from_id == $admin || (int)($userInfo['isAdmin']??0)===1) &&
    preg_match('/^cycleGatewayAudience_([a-z_]+)$/D',(string)$data,$policyMatch)) {
    $method=$policyMatch[1];
    if (!isset(achbotPayUxAudienceMethods()[$method])) {
        answerCallbackQuery($callbackId,\achbotMessage('bot.bot.gateway_audience_settings.alert.a677e95601'),true);
        return;
    }
    if (!achbotAdminGatewayChange($update,(int)$from_id,
            (int)$chat_id,(int)$message_id,(int)$admin,
            'audience',$method)) {
        answerCallbackQuery($callbackId,
            \achbotMessage('bot.bot.gateway_audience_settings.alert.0b06afc291'),true);
        return;
    }
    editText($message_id,\achbotMessage('bot.bot.gateway_audience_settings.message.e2238b091f'),
             achbotGatewayAudienceKeyboard());
    return;
}
// Only actual administrators may change invoice and anti-duplicate windows.
if (($from_id == $admin || (int)($userInfo['isAdmin']??0)===1) &&
    $data==='paymentPolicySettings') {
    editText($message_id,\achbotMessage('bot.bot.payment_policy_settings.message.e0a3b58e1e'),
        achbotPaymentPolicyKeyboard(),'HTML',$from_id);
    return;
}
if (($from_id == $admin || (int)($userInfo['isAdmin']??0)===1) &&
    preg_match('/^editPaymentPolicy_([A-Za-z]+)$/D',(string)$data,$policyMatch)) {
    $fields=achbotPaymentPolicyFields();$key=$policyMatch[1];
    if (!isset($fields[$key])) {
        answerCallbackQuery($callbackId,\achbotMessage('bot.bot.payment_policy_settings.alert.ddd4bda562'),true);return;
    }
    $field=$fields[$key];
    setUser('paymentPolicyValue_'.$key);
    sendMessage($field['label'].\achbotMessage('bot.bot.payment_policy_settings.message.0aef5ea61e').$field['min'].\achbotMessage('bot.bot.payment_policy_settings.message.1a51e219ec').$field['max'].\achbotMessage('bot.bot.payment_policy_settings.message.121434bafe'),$cancelKey);
    return;
}
if (($from_id == $admin || (int)($userInfo['isAdmin']??0)===1) &&
    preg_match('/^paymentPolicyValue_([A-Za-z]+)$/D',(string)($userInfo['step']??''),$policyMatch) &&
    $text!==$buttonValues['cancel']) {
    $fields=achbotPaymentPolicyFields();$key=$policyMatch[1];
    if (!isset($fields[$key]) || !preg_match('/^[0-9]{1,5}$/D',(string)$text) ||
        (int)$text<$fields[$key]['min'] || (int)$text>$fields[$key]['max']) {
        sendMessage(\achbotMessage('bot.bot.payment_policy_settings.message.bd6943ee9d'), $cancelKey);
        return;
    }
    if (!setSettings($key,(int)$text)) {
        sendMessage(\achbotMessage('bot.bot.payment_policy_settings.message.ed51b4cad2'), $cancelKey);
        return;
    }
    setUser();
    sendMessage(\achbotMessage('bot.bot.payment_policy_settings.message.8525c6215f'),achbotPaymentPolicyKeyboard());
    return;
}
if($data=="changeConfigRemarkType"){
    switch($botState['remark']){
        case "digits":
            $newValue = "manual";
            break;
        case "manual":
            $newValue = "idanddigits";
            break;
        default:
            $newValue = "digits";
            break;
    }
    setSettings('remark', $newValue);
    editText($message_id,$mainValues['change_bot_settings_message'],getBotSettingKeys());
}
// Privileged PAYMENT_KEYS editor: signed private source + current actor
// role, current owned step, field-level CAS, and a serialized atomic write.
// Cached $userInfo / $paymentKeys are NEVER authorization/write authority.
if (is_string($data??null) && str_starts_with($data,'changePaymentKeys')) {
    if (!preg_match('/\AchangePaymentKeys([A-Za-z0-9]+)\z/D',$data,$match) ||
        !in_array($match[1],achbotPaymentKeyAdminFields(),true)) {
        answerCallbackQuery($callbackId, \achbotMessage('bot.bot.change_config_remark_type.alert.b1f29d520c'), true);
        return;
    }
    if (!achbotAdminPaymentKeyChange($update,(int)$from_id,(int)$chat_id,
            (int)$message_id,(int)$admin,'start',$match[1])) {
        answerCallbackQuery($callbackId,
            \achbotMessage('bot.bot.change_config_remark_type.alert.f73d7912f7'),true);
        return;
    }
    delMessage();
    switch($match[1]){
        case "nextpay":
            $gate = \achbotMessage('bot.bot.change_config_remark_type.gate.44b818af28');
            break;
        case "nowpayment":
            $gate = \achbotMessage('bot.bot.change_config_remark_type.gate.55d4692d90');
            break;
        case "zarinpal":
            $gate = \achbotMessage('bot.bot.change_config_remark_type.gate.6dc3d1ffc2');
            break;
        case "bankAccount":
            $gate = \achbotMessage('bot.bot.change_config_remark_type.gate.4267cb9eda');
            break;
        case "holderName":
            $gate = \achbotMessage('bot.bot.change_config_remark_type.gate.a77ae56406');
            break;
        case "giftCardUrl":
            $gate = \achbotMessage('bot.bot.change_config_remark_type.gate.9d8c0fcdf4');
            break;
        case "manualUsdtWalletBEP20":
        case "manualUsdtWalletTRC20":
        case "manualUsdtWalletERC20":
            $network = substr($match[1], strlen('manualUsdtWallet'));
            $gate = \achbotMessage('bot.bot.change_config_remark_type.gate.7602e74547', ['network' => $network]);
            break;
        case "manualTrxWallet":
            $gate = \achbotMessage('bot.bot.change_config_remark_type.gate.7e0248ed23');
            break;
        case "manualUsdtRateToman":
            $gate = \achbotMessage('bot.bot.change_config_remark_type.gate.e152f81bc6');
            break;
        case "usdtAgentRateToman":
            $gate = \achbotMessage('bot.bot.change_config_remark_type.gate.ce14a45981');
            break;
        case "manualTrxRateToman":
            $gate = \achbotMessage('bot.bot.change_config_remark_type.gate.4f73f2daea');
            break;
        default:
            answerCallbackQuery($callbackId, \achbotMessage('bot.bot.change_config_remark_type.alert.b1f29d520c'), true);
            return;
    }
    sendMessage(\achbotMessage('bot.bot.change_config_remark_type.message.8f40ac53e7', ['gate' => $gate]), $cancelKey);
    return;
}
$paymentKeyStep=(string)($userInfo['step']??'');
if (str_starts_with($paymentKeyStep,'achbotPaymentKey|') ||
    str_starts_with($paymentKeyStep,'changePaymentKeys')) {
    // A message must own the NEW baseline-bound wizard. Old pre-fix
    // changePaymentKeys* steps never authorize a write or reset the step.
    if (!is_object($update->message??null) || !is_string($text??null)) return;
    $ownedStep=achbotPaymentKeyAdminStep($paymentKeyStep);
    if ($ownedStep===null) {
        sendMessage(\achbotMessage('bot.bot.change_config_remark_type.message.1bc265a941'));
        return;
    }
    $kind=$text===$buttonValues['cancel']?'cancel':'save';
    if (!achbotAdminPaymentKeyChange($update,(int)$from_id,(int)$chat_id,
            (int)$message_id,(int)$admin,$kind,$ownedStep['field'],
            (string)$text)) {
        $hint=$kind==='save'
            ? achbotPaymentKeyAdminInputHint($ownedStep['field'],(string)$text)
            : null;
        sendMessage($hint ??
            \achbotMessage('bot.bot.change_config_remark_type.message.28b20d4ea7'),
            $cancelKey);
        return;
    }
    if ($kind==='cancel') {
        sendMessage(\achbotMessage('bot.bot.gateway_audience_settings.message.74d707fe02'), $removeKeyboard);
    } else {
        sendMessage($mainValues['saved_successfuly'],$removeKeyboard);
    }
    sendMessage($mainValues['change_bot_settings_message'],getGateWaysKeys());
    return;
}
require __DIR__ . '/src/Agent/Discounts.php';
if(preg_match('/^edit(RewaredTime|cartToCartAutoAcceptTime)/', $data, $match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    if($match[1] == "RewaredTime") $txt = \achbotMessage('bot.bot.change_config_remark_type.txt.d867040876');
    else $txt = \achbotMessage('bot.bot.change_config_remark_type.txt.fcd2e548b6');

    sendMessage($txt,$cancelKey);
    setUser($data);
}
if ($data == "userReports" && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    delMessage();
    sendMessage(\achbotMessage('bot.bot.user_reports.message.b1c793c917'), $cancelKey);
    setUser($data);
}

if ($userInfo['step'] == "userReports" && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    if (is_numeric($text)) {
        $keysData = getUserInfoKeys($text);

		if ($keysData != null) {
			sendMessage($mainValues['please_wait_message'], $removeKeyboard);

			$keys = json_encode([
				'inline_keyboard' => $keysData['inline_keyboard']
			]);
			sendMessage(\achbotMessage('bot.bot.user_reports.message.e55e5591d2', ['text' => $text]), $keys, "html");
			setUser();
		} else {
            sendMessage(\achbotMessage('bot.bot.user_reports.message.c1d7ab477b'));
        }
    } else {
        sendMessage(\achbotMessage('bot.bot.user_reports.message.a5e5f6a89b'));
    }
}

if($data=="inviteSetting" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'INVITE_BANNER_AMOUNT'");
    $stmt->execute();
    $inviteAmount = number_format($stmt->get_result()->fetch_assoc()['value']??0) . \achbotMessage('bot.bot.webhook_ingress_authenticated.text.2cc98f7f7c');
    $stmt->close();
    setUser();
    $keys = json_encode(['inline_keyboard'=>[
        [['text'=>\achbotMessage('bot.bot.invite_setting.button.e5798de28e'),'callback_data'=>"inviteBanner"]],
        [
            ['text'=>$inviteAmount,'callback_data'=>"editInviteAmount"],
            ['text'=>\achbotMessage('bot.bot.invite_setting.button.0854688c71'),'callback_data'=>"wizwizch"]
            ],
        [
            ['text'=>$buttonValues['back_button'],'callback_data'=>"botSettings"]
            ],
        ]]);
    $res = editText($message_id,\achbotMessage('bot.bot.invite_setting.message.554f354e70'),$keys);
    if(!$res->ok){
        delMessage();
        sendMessage(\achbotMessage('bot.bot.invite_setting.message.554f354e70'),$keys);
    }
}
if($data=="inviteBanner" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'INVITE_BANNER_TEXT'");
    $stmt->execute();
    $inviteText = $stmt->get_result()->fetch_assoc()['value'];
    $inviteText = $inviteText != null?json_decode($inviteText,true):array('type'=>'text');
    $stmt->close();
    $keys = json_encode(['inline_keyboard'=>[
        [['text'=>\achbotMessage('bot.bot.invite_banner.button.d6aa12d559'),'callback_data'=>'editInviteBannerText']],
        [['text'=>$buttonValues['back_button'],'callback_data'=>'inviteSetting']]
        ]]);
    if($inviteText['type'] == "text"){
        editText($message_id,\achbotMessage('bot.bot.invite_banner.message.379af3c42e') . $inviteText['text'],$keys);
    }else{
        delMessage();
        $res = sendPhoto($inviteText['file_id'], $inviteText['caption'], $keys,null);
        if(!$res->ok){
            sendMessage(\achbotMessage('bot.bot.invite_banner.message.e5b34cbee4'),$keys);
        }
    }
    setUser();
}
if($data=="editInviteBannerText" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage(\achbotMessage('bot.bot.edit_invite_banner_text.message.28f265f083'),$cancelKey);
    setUser($data);
}
if($userInfo['step']=="editInviteBannerText" && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    $data = array();
    if(isset($update->message->photo)){
        $data['type'] = 'photo';
        $data['caption'] = $caption;
        $data['file_id'] = $fileid;
    }
    elseif(isset($update->message->text)){
        $data['type'] = 'text';
        $data['text'] = $text;
    }else{
        sendMessage(\achbotMessage('bot.bot.edit_invite_banner_text.message.155c179a84'));
        exit();
    }

    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'INVITE_BANNER_TEXT'");
    $stmt->execute();
    $checkExist = $stmt->get_result();
    $stmt->close();
    $data = json_encode($data);
    if($checkExist->num_rows > 0){
        $stmt = $connection->prepare("UPDATE `setting` SET `value` = ? WHERE `type` = 'INVITE_BANNER_TEXT'");
        $stmt->bind_param("s", $data);
        $stmt->execute();
        $checkExist = $stmt->get_result();
        $stmt->close();
    }else{
        $stmt = $connection->prepare("INSERT INTO `setting` (`value`, `type`) VALUES (?, 'INVITE_BANNER_TEXT')");
        $stmt->bind_param("s", $data);
        $stmt->execute();
        $checkExist = $stmt->get_result();
        $stmt->close();
    }

    sendMessage($mainValues['saved_successfuly'],$removeKeyboard);
    $keys = json_encode(['inline_keyboard'=>[
        [['text'=>\achbotMessage('bot.bot.invite_banner.button.d6aa12d559'),'callback_data'=>'editInviteBannerText']],
        [['text'=>$buttonValues['back_button'],'callback_data'=>'inviteSetting']]
        ]]);
    if(isset($update->message->text)){
        sendMessage(\achbotMessage('bot.bot.invite_banner.message.379af3c42e') . $text,$keys);
    }else{
        sendPhoto($fileid, $caption, $keys);
    }
    setUser();
}
if($data=="editInviteAmount" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage(\achbotMessage('bot.bot.edit_invite_amount.message.0372e8b9cd'),$cancelKey);
    setUser($data);
}
if($userInfo['step'] == "editInviteAmount" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(is_numeric($text)){
        $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'INVITE_BANNER_AMOUNT'");
        $stmt->execute();
        $checkExist = $stmt->get_result();
        $stmt->close();

        if($checkExist->num_rows > 0){
            $stmt = $connection->prepare("UPDATE `setting` SET `value` = ? WHERE `type` = 'INVITE_BANNER_AMOUNT'");
            $stmt->bind_param("s", $text);
            $stmt->execute();
            $checkExist = $stmt->get_result();
            $stmt->close();
        }else{
            $stmt = $connection->prepare("INSERT INTO `setting` (`value`, `type`) VALUES (?, 'INVITE_BANNER_AMOUNT')");
            $stmt->bind_param("s", $text);
            $stmt->execute();
            $checkExist = $stmt->get_result();
            $stmt->close();
        }
        sendMessage($mainValues['saved_successfuly'],$removeKeyboard);

        $keys = json_encode(['inline_keyboard'=>[
            [['text'=>\achbotMessage('bot.bot.invite_setting.button.e5798de28e'),'callback_data'=>"inviteBanner"]],
            [
                ['text'=>number_format($text) . \achbotMessage('bot.bot.webhook_ingress_authenticated.text.2cc98f7f7c'),'callback_data'=>"editInviteAmount"],
                ['text'=>\achbotMessage('bot.bot.invite_setting.button.0854688c71'),'callback_data'=>"wizwizch"]
                ],
            [
                ['text'=>$buttonValues['back_button'],'callback_data'=>"botSettings"]
                ],
            ]]);
        sendMessage(\achbotMessage('bot.bot.invite_setting.message.554f354e70'),$keys);
        setUser();
    }else sendMessage($mainValues['send_only_number']);
}
if(preg_match('/^edit(RewaredTime|cartToCartAutoAcceptTime)/', $userInfo['step'], $match) && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    if(!is_numeric($text)){
        sendMessage(\achbotMessage('bot.bot.edit_invite_amount.message.d86a0557a7'));
        exit();
    }
    elseif($text <0 ){
        sendMessage(\achbotMessage('bot.bot.edit_invite_amount.message.736a04e908'));
        exit();
    }

    setSettings(lcfirst($match[1]), $text);
    sendMessage($mainValues['change_bot_settings_message'],getBotSettingKeys());
    setUser();
    exit();
}

if($data=="inviteFriends"){
    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'INVITE_BANNER_TEXT'");
    $stmt->execute();
    $inviteText = $stmt->get_result()->fetch_assoc()['value'];

    if($inviteText != null){
        delMessage();
        $inviteText = json_decode($inviteText, true);

        $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'INVITE_BANNER_AMOUNT'");
        $stmt->execute();
        $inviteAmount = number_format($stmt->get_result()->fetch_assoc()['value'] ?? 0) . \achbotMessage('bot.bot.webhook_ingress_authenticated.text.2cc98f7f7c');
        $stmt->close();

        $getBotInfo = json_decode(file_get_contents("http://api.telegram.org/bot" . $botToken . "/getMe"), true);
        $botId = $getBotInfo['result']['username'];

        $link = "t.me/$botId?start=" . $from_id;

        if($inviteText['type'] == "text"){
            $txt = str_replace('LINK', $link, $inviteText['text']);
            $res = sendMessage($txt, null, "HTML");
        } else {
            $txt = str_replace('LINK', $link, $inviteText['caption']);
            $res = sendPhoto($inviteText['file_id'], $txt, null, "HTML");
        }

        $msgId = $res->result->message_id;

        $secondText = str_replace(
            ['{INVITE_REWARD}', '{INVITE_AMOUNT}'],
            [$inviteReward, $inviteAmount],
            $mainValues['invite_second_text']
        );

        $keyboard = json_encode([
            'inline_keyboard' => [
                [
                    ['text' => $buttonValues['back_to_main'], 'callback_data' => "mainMenu"]
                ]
            ]
        ]);

        $photoPath = __DIR__ . "/affiliate.jpg";

        if(file_exists($photoPath) && is_readable($photoPath)){
            $caption =
\achbotMessage('bot.bot.invite_friends.caption.16ca7a4696', ['inviteReward' => $inviteReward]) .
\achbotMessage('bot.bot.invite_friends.text.c14f10907e', ['inviteAmount' => $inviteAmount]);

            $photoFile = function_exists('curl_file_create')
                ? curl_file_create($photoPath)
                : new CURLFile($photoPath);

            sendPhoto(
                $photoFile,
                $caption,
                $keyboard,
                "HTML",
                $from_id,
                $msgId
            );
        } else {
            sendMessage(
                $secondText,
                $keyboard,
                "HTML",
                null,
                $msgId
            );
        }

    } else {
        alert(\achbotMessage('bot.bot.invite_friends.alert.56fd80c6d5'));
    }
}

if($data=="myInfo"){
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `userid` = ?");
    $stmt->bind_param("i", $from_id);
    $stmt->execute();
    $totalBuys = $stmt->get_result()->num_rows;
    $stmt->close();

    $myWallet = number_format($userInfo['wallet']) . \achbotMessage('bot.bot.webhook_ingress_authenticated.text.2cc98f7f7c');

    $keys = json_encode(['inline_keyboard'=>[
        [
            ['text'=>\achbotMessage('bot.bot.my_info.button.3d0ddff816'),'callback_data'=>"increaseMyWallet"],
            ['text'=>\achbotMessage('bot.bot.my_info.button.2aa445c2af'),'callback_data'=>"transferMyWallet"]
        ],
        [
            ['text'=>$buttonValues['back_button'],'callback_data'=>"mainMenu"]
            ]
        ]]);
    editText($message_id, \achbotMessage('bot.bot.my_info.message.4fb6af58ae', ['from_id' => $from_id, 'username' => $username, 'first_name' => $first_name, 'myWallet' => $myWallet, 'totalBuys' => $totalBuys]),
            $keys,"html");
}
if (($achbotV8Result = require __DIR__ . '/src/Finance/Routes/WalletTransfer.php') === null) return;
if (($achbotV8Result = require __DIR__ . '/src/Finance/Routes/WalletRecharge.php') === null) return;
if ($achbotV8Result === '__ACHBOT_V8_GOTO_START__') goto GOTOSTART;
if (($achbotV8Result = require __DIR__ . '/src/Finance/Routes/ManualReceipts.php') === null) return;
if (($achbotV8Result = require __DIR__ . '/src/Finance/Routes/AdminWallet.php') === null) return;
if($data=="editRewardChannel" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage(\achbotMessage('bot.bot.edit_reward_channel.message.1f35a73f71'),$cancelKey);
    setUser($data);
}
// Edited by ACh
if (
    $userInfo['step'] == "editRewardChannel" &&
    ($from_id == $admin || $userInfo['isAdmin'] == true) &&
    $text != $buttonValues['cancel']
) {
    $text = trim($text);

    $me = bot('getMe');
    if (!$me || empty($me->ok)) {
        sendMessage($mainValues['network_error_try_again'] ?? \achbotMessage('bot.bot.edit_reward_channel.message.eef6d10f94'));
        exit;
    }
    $botId = $me->result->id;

    $chat = bot('getChat', ['chat_id' => $text]);
    if (!$chat || empty($chat->ok) || empty($chat->result->id)) {
        sendMessage($mainValues['invalid_channel_id'] ?? \achbotMessage('bot.bot.edit_reward_channel.message.2f497eef41'));
        exit;
    }
    $chatId = $chat->result->id;

    $result = bot('getChatMember', ['chat_id' => $chatId, 'user_id' => $botId]);
    if ($result && !empty($result->ok) && isset($result->result->status) && $result->result->status === "administrator") {
        setSettings('rewardChannel', $text);
        sendMessage($mainValues['change_bot_settings_message'], getGateWaysKeys());
        setUser();
        exit;
    }

    sendMessage(\achbotMessage('bot.bot.edit_reward_channel.message.f400fa3a23'));
}

if ($data == "editLockChannel" && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    delMessage();
    sendMessage(\achbotMessage('bot.bot.edit_lock_channel.message.b324b87609'), $cancelKey);
    setUser($data);
}

if (
    $userInfo['step'] == "editLockChannel" &&
    ($from_id == $admin || $userInfo['isAdmin'] == true) &&
    $text != $buttonValues['cancel']
) {
    $text = trim($text);

    $me = bot('getMe');
    if (!$me || empty($me->ok)) {
        sendMessage($mainValues['network_error_try_again'] ?? \achbotMessage('bot.bot.edit_reward_channel.message.eef6d10f94'));
        exit;
    }
    $botId = $me->result->id;

    $chat = bot('getChat', ['chat_id' => $text]);
    if (!$chat || empty($chat->ok) || empty($chat->result->id)) {
        sendMessage($mainValues['invalid_channel_id'] ?? \achbotMessage('bot.bot.edit_reward_channel.message.2f497eef41'));
        exit;
    }
    $chatId = $chat->result->id;

    $result = bot('getChatMember', ['chat_id' => $chatId, 'user_id' => $botId]);
    if ($result && !empty($result->ok) && isset($result->result->status) && $result->result->status === "administrator") {
        setSettings("lockChannel", $text);
        sendMessage($mainValues['change_bot_settings_message'], getGateWaysKeys());
        setUser();
        exit;
    }

    sendMessage($mainValues['the_bot_in_not_admin'] ?? \achbotMessage('bot.bot.edit_lock_channel.message.949fda751f'));
}
// End Edited by ACh

if($data=="editSupportID" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage(\achbotMessage('bot.bot.edit_support_i_d.message.2e136a38a2'),$cancelKey);
    setUser($data);
}

if($userInfo['step'] == "editSupportID" && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    setSettings('supportID', $text);
    sendMessage(\achbotMessage('bot.bot.edit_support_i_d.message.6279ae207e'), getGateWaysKeys());
    setUser();
    exit();
}

// Command Created by ACh

function processCallbackQuery($data, $chat_id, $from_id) {
    global $connection, $userInfo, $from_id, $admin, $botState, $mainValues, $buttonValues;

    if ($data == 'buySubscription') {

        $isRealAdmin = ($from_id == $admin || $userInfo['isAdmin'] == true);

        if ($botState['sellState'] == "on" || $isRealAdmin) {

            if (($botState['cartToCartState'] ?? 'off') == "off" && ($botState['giftCardState'] ?? 'off') == "off" && ($botState['manualUsdtState'] ?? 'off') == "off" && ($botState['walletState'] ?? 'off') == "off") {
                sendMessage(\achbotMessage('bot.bot.process_callback_query.message.616c172303'), null, "MarkDown", $chat_id);
                exit();
            }

            $buyType = "none";

            $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `active`=1 AND `state`=1 AND `ucount`>0 ORDER BY `id` ASC");
            $stmt->execute();
            $respd = $stmt->get_result();
            $stmt->close();

            if ($respd->num_rows == 0) {
                sendMessage($mainValues['no_server_available'], null, "MarkDown", $chat_id);
                exit();
            }

            $keyboard = [];
            while ($cat = $respd->fetch_assoc()) {
                $id   = $cat['id'];
                $name = $cat['title'];
                $flag = $cat['flag'];

                $keyboard[] = [
                    'text' => "$flag $name",
                    'callback_data' => "selectServer{$id}_{$buyType}"
                ];
            }

            $keyboard = array_chunk($keyboard, 1);
            $keyboard[] = [['text' => $buttonValues['back_to_main'], 'callback_data' => "mainMenu"]];

            $textToSend = \achbotMessage('bot.bot.process_callback_query.text_to_send.6f1f09466a');

            sendMessage($textToSend, json_encode(['inline_keyboard' => $keyboard]), "MarkDown", $chat_id);

        } else {
            sendMessage(\achbotMessage('bot.bot.process_callback_query.message.616c172303'), null, "MarkDown", $chat_id);
        }
    }

    elseif (
        $data == 'mySubscriptions' ||
        $data == "agentConfigsList" ||
        preg_match('/(changeAgentOrder|changeOrdersPage)(\d+)/', $data, $match)
    ) {
        $results_per_page = 50;

        if (!isset($match) || !is_array($match)) {
            $match = [];
        }

        $page = isset($match[2]) ? (int)$match[2] : 1;
        if ($page < 1) {
            $page = 1;
        }

        $isAgentView = (
            $data == "agentConfigsList" ||
            (isset($match[1]) && $match[1] == "changeAgentOrder")
        );

        $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `userid`=? AND `status`=1");
        $stmt->bind_param("i", $from_id);
        $stmt->execute();
        $number_of_result = $stmt->get_result()->num_rows;
        $stmt->close();

        $number_of_page = ceil($number_of_result / $results_per_page);
        $page_first_result = ($page - 1) * $results_per_page;

        $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `userid`=? AND `status`=1 ORDER BY `id` DESC LIMIT ?, ?");
        $stmt->bind_param("iii", $from_id, $page_first_result, $results_per_page);
        $stmt->execute();
        $orders = $stmt->get_result();
        $stmt->close();

        if ($orders->num_rows == 0) {
            sendMessage($mainValues['you_dont_have_config'], null, "MarkDown", $chat_id);
            exit;
        }

        $keyboard = [];
        while ($cat = $orders->fetch_assoc()) {
            $id     = $cat['id'];
            $remark = $cat['remark'];

            $keyboard[] = [
                'text' => $remark,
                'callback_data' => "orderDetails$id"
            ];
        }

        $keyboard = array_chunk($keyboard, 1);

        $prev = $page - 1;
        $next = $page + 1;

        $buttons = [];

        if ($prev > 0) {
            $buttons[] = [
                'text' => \achbotMessage('bot.bot.process_callback_query.button.68f5beb4a0'),
                'callback_data' => $isAgentView ? "changeAgentOrder$prev" : "changeOrdersPage$prev"
            ];
        }

        if ($page < $number_of_page) {
            $buttons[] = [
                'text' => \achbotMessage('bot.bot.process_callback_query.button.b32013f76e'),
                'callback_data' => $isAgentView ? "changeAgentOrder$next" : "changeOrdersPage$next"
            ];
        }

        if (!empty($buttons)) {
            $keyboard[] = $buttons;
        }

        if ($isAgentView) {
            $keyboard[] = [[
                'text' => $buttonValues['search_agent_config'],
                'callback_data' => "searchAgentConfig"
            ]];
        }

        $keyboard[] = [[
            'text' => $buttonValues['back_to_main'],
            'callback_data' => "mainMenu"
        ]];

        $textToSend   = $mainValues['select_one_to_show_detail'];
        $keyboardData = json_encode(['inline_keyboard' => $keyboard]);

        sendMessage($textToSend, $keyboardData, "MarkDown", $chat_id);
        exit;
    }
}

// End Command Created by ACh

if ((require __DIR__ . '/src/Purchase/EntryAndBulk.php') === null) return;
if($data=="messageToSpeceficUser" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage($mainValues['send_user_id'], $cancelKey);
    setUser($data);
}
if($userInfo['step'] == "messageToSpeceficUser" && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(!is_numeric($text)){
        sendMessage($mainValues['send_only_number']);
        exit();
    }
    $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid` = ?");
    $stmt->bind_param("i", $text);
    $stmt->execute();
    $usersCount = $stmt->get_result()->num_rows;
    $stmt->close();

    if($usersCount > 0 ){
        sendMessage(\achbotMessage('bot.bot.process_callback_query.message.d57aef02e2'));
        setUser("sendMessageToUser" . $text);
    }else{
        sendMessage($mainValues['user_not_found']);
    }
}

if ((require __DIR__ . '/src/Broadcast/Routes.php') === null) return;

if ((require __DIR__ . '/src/Purchase/Catalogue.php') === null) return;
if (($achbotV8Result = require __DIR__ . '/src/Finance/Routes/DiscountEntry.php') === null) return;
if ((require __DIR__ . '/src/Purchase/TrialCatalogue.php') === null) return;
if ((require __DIR__ . '/src/Purchase/SelectPlan.php') === null) return;
if (($achbotV8Result = require __DIR__ . '/src/Finance/Routes/Checkout.php') === null) return;
if ($achbotV8Result === '__ACHBOT_V8_GOTO_START__') goto GOTOSTART;
if($data=="availableServers"){
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `acount` != 0 AND `inbound_id` != 0");
    $stmt->execute();
    $serversList = $stmt->get_result();
    $stmt->close();

    $keys = array();
    $keys[] = [
        ['text'=>\achbotMessage('bot.bot.process_callback_query.button.423ff3a159'),'callback_data'=>"wizwizch"],
        ['text'=>\achbotMessage('bot.bot.process_callback_query.button.d9772fd001'),'callback_data'=>"wizwizch"],
        ['text'=>\achbotMessage('bot.bot.process_callback_query.button.0b29754285'),'callback_data'=>"wizwizch"]
        ];
    while($file_detail = $serversList->fetch_assoc()){
        $days = $file_detail['days'];
        $title = $file_detail['title'];
        $server_id = $file_detail['server_id'];
        $acount = $file_detail['acount'];
        $inbound_id = $file_detail['inbound_id'];
        $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id` = ?");
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $name = $stmt->get_result();
        $stmt->close();

        if($name->num_rows>0){
            $name = $name->fetch_assoc()['title'];

            $keys[] = [
                ['text'=>$acount . \achbotMessage('bot.bot.process_callback_query.text.d789169bd3'),'callback_data'=>"wizwizch"],
                ['text'=>$title??" ",'callback_data'=>"wizwizch"],
                ['text'=>$name??" ",'callback_data'=>"wizwizch"]
                ];
        }
    }
    $keys[] = [['text'=>$buttonValues['back_button'],'callback_data'=>"mainMenu"]];
    $keys = json_encode(['inline_keyboard'=>$keys]);
    editText($message_id, \achbotMessage('bot.bot.process_callback_query.message.dda719f1cb'), $keys);
}
if($data=="availableServers2"){
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `inbound_id` = 0");
    $stmt->execute();
    $serversList = $stmt->get_result();
    $stmt->close();

    $keys = array();
    $keys[] = [
        ['text'=>\achbotMessage('bot.bot.process_callback_query.button.423ff3a159'),'callback_data'=>"wizwizch"],
        ['text'=>\achbotMessage('bot.bot.process_callback_query.button.0b29754285'),'callback_data'=>"wizwizch"]
        ];
    while($file_detail2 = $serversList->fetch_assoc()){
        $days2 = $file_detail2['days'];
        $title2 = $file_detail2['title'];
        $server_id2 = $file_detail2['server_id'];
        $inbound_id2 = $file_detail2['inbound_id'];

        $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id` = ?");
        $stmt->bind_param("i", $server_id2);
        $stmt->execute();
        $name = $stmt->get_result();
        $stmt->close();

        if($name->num_rows>0){
            $sInfo = $name->fetch_assoc();
            $name = $sInfo['title'];
            $acount2 = $sInfo['ucount'];

            $keys[] = [
                ['text'=>$acount2 . \achbotMessage('bot.bot.process_callback_query.text.d789169bd3'),'callback_data'=>"wizwizch"],
                ['text'=>$title2??" ",'callback_data'=>"wizwizch"],
                ];
        }
    }
    $keys[] = [['text'=>$buttonValues['back_button'],'callback_data'=>"mainMenu"]];
    $keys = json_encode(['inline_keyboard'=>$keys]);
    editText($message_id, \achbotMessage('bot.bot.process_callback_query.message.e8f25aae49'), $keys);
}
require __DIR__ . '/src/Agent/Applications.php';
if (($achbotV8Result = require __DIR__ . '/src/Finance/Routes/ManualApproval.php') === null) return;
require __DIR__ . '/src/Support/Entry.php';
if ((require __DIR__ . '/src/Plans/Intervals.php') === null) return;
require __DIR__ . '/src/Support/CreateTicket.php';
require __DIR__ . '/src/Support/CustomerConversation.php';
require __DIR__ . '/src/Support/Categories.php';
require __DIR__ . '/src/Support/QueueAndHistory.php';
require __DIR__ . '/src/Admin/Bans.php';
if($data=="mainMenuButtons" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    editText($message_id,\achbotMessage('bot.bot.process_callback_query.message.70afb19fd8'),getMainMenuButtonsKeys());
}
if(preg_match('/^delMainButton(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("DELETE FROM `setting` WHERE `id` = ?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $stmt->close();

    alert(\achbotMessage('bot.bot.process_callback_query.alert.e1e7245408'));
    editText($message_id,\achbotMessage('bot.bot.process_callback_query.message.70afb19fd8'),getMainMenuButtonsKeys());
}
if($data == "addNewMainButton" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage(\achbotMessage('bot.bot.process_callback_query.message.ed098f7f93'),$cancelKey);
    setUser($data);
}
if($userInfo['step'] == "addNewMainButton" && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(!isset($update->message->text)){
        sendMessage(\achbotMessage('bot.bot.process_callback_query.message.0caee5986d'));
        exit();
    }
    sendMessage(\achbotMessage('bot.bot.process_callback_query.message.ea8c224138'));
    setUser("setMainButtonAnswer" . $text);
}

if (preg_match('/^setMainButtonAnswer(.*)/', $userInfo['step'], $match) && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    if (!isset($update->message->text)) {
        sendMessage(\achbotMessage('bot.bot.process_callback_query.message.0caee5986d'));
        exit();
    }
    setUser();

    $btn = "MAIN_BUTTONS" . trim($match[1]);

    $encoded = json_encode($text);
    $encoded = substr($encoded, 1, -1);

    $stmt = $connection->prepare("INSERT INTO `setting` (`type`, `value`) VALUES (?, ?)");
    $stmt->bind_param("ss", $btn, $encoded);
    $stmt->execute();
    $stmt->close();

    sendMessage(\achbotMessage('bot.bot.process_callback_query.message.70afb19fd8'), getMainMenuButtonsKeys());
}

require __DIR__ . '/src/Admin/Unban.php';
require __DIR__ . '/src/Support/AdminReply.php';
if ((require __DIR__ . '/src/Purchase/TrialProvision.php') === null) return;
if (preg_match('/^showMainButtonAns(\d+)/', $data, $match)) {
    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `id` = ?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $keys = json_encode([
        'inline_keyboard' => [
            [
                ['text' => $buttonValues['back_to_main'], 'callback_data' => "mainMenu"],
                ['text' => $buttonValues['invite_friends'], 'callback_data' => "inviteFriends"]
            ]
        ]
    ], JSON_UNESCAPED_UNICODE);

    $raw = $info['value'] ?? '';
    $decoded = json_decode('"' . $raw . '"');
    if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
        $decoded = $raw;
    }
    $decoded = str_replace(["<br />", "<br/>", "<br>"], "\n", $decoded);

    $res = editText($message_id, $decoded, $keys, 'HTML');
    if (!$res || (isset($res->ok) && !$res->ok)) {
        delMessage();
        $res2 = sendMessage($decoded, $keys, 'HTML');
        if (!$res2 || (isset($res2->ok) && !$res2->ok)) {
            sendMessage($decoded, $keys);
        }
    }
}

if(preg_match('/^marzbanHostSettings(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id` = ?");
    $stmt->bind_param('i', $match[1]);
    $stmt->execute();
    $serverId = $stmt->get_result()->fetch_assoc()['server_id'];
    $stmt->close();

    $hosts = getMarzbanHosts($serverId)->inbounds;
    $networkType = array();
    foreach($hosts as $key => $inbound){
        $networkType[] = [['text'=>$inbound->tag, 'callback_data'=>"selectHost{$match[1]}*_*{$inbound->protocol}*_*{$inbound->tag}"]];
    }
    $networkType[] = [['text'=>$buttonValues['cancel'], 'callback_data'=>"planDetails" . $match[1]]];
    $networkType = json_encode(['inline_keyboard'=>$networkType]);
    editText($message_id, \achbotMessage('bot.bot.process_callback_query.message.2c4ec0c4cc'),$networkType);
}
if(preg_match('/^selectHost(?<planId>\d+)\*_\*(?<protocol>.+)\*_\*(?<tag>.*)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $saveBtn = \achbotMessage('bot.bot.process_callback_query.save_btn.b3a82cd642');
    unset($markup[count($markup)-1]);
    if($markup[count($markup)-1][0]['text'] == $saveBtn) unset($markup[count($markup)-1]);
    foreach($markup as $key => $keyboard){
        if($keyboard[0]['callback_data'] == $data) $markup[$key][0]['text'] = $keyboard['0']['text'] == $match['tag'] . \achbotMessage('bot.bot.process_callback_query.text.64554c955e') ? $match['tag']:$match['tag'] . \achbotMessage('bot.bot.process_callback_query.text.64554c955e');
    }

    if(strstr(json_encode($markup,JSON_UNESCAPED_UNICODE), \achbotMessage('bot.bot.process_callback_query.text.1d456424c3')) && !strstr(json_encode($markup,JSON_UNESCAPED_UNICODE), $saveBtn)){
        $markup[] = [['text'=>$saveBtn,'callback_data'=>"saveServerHost" . $match['planId']]];
    }
    $markup[] = [['text'=>$buttonValues['cancel'], 'callback_data'=>"planDetails" . $match['planId']]];
    $markup = json_encode(['inline_keyboard'=>array_values($markup)]);
    editKeys($markup);
}
if(preg_match('/^saveServerHost(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $inbounds = array();
    $proxies = array();
    unset($markup[count($markup)-1]);
    unset($markup[count($markup)-1]);

    foreach($markup as $key=>$value){
        $tag = trim(str_replace(\achbotMessage('bot.bot.process_callback_query.text.1d456424c3'), "", $value[0]['text'], $state));
        if($state > 0){
            preg_match('/^selectHost(?<serverId>\d+)\*_\*(?<protocol>.+)\*_\*(?<tag>.*)/',$value[0]['callback_data'],$info);
            $inbounds[$info['protocol']][] = $tag;
            $proxies[$info['protocol']] = array();

            if($info['protocol'] == "vless"){
                $proxies["vless"] = ["flow" => ""];
            }
            elseif($info['protocol'] == "shadowsocks"){
                $proxies["shadowsocks"] = ['method' => "chacha20-ietf-poly1305"];
            }
        }
    }
    $info = json_encode(['inbounds'=>$inbounds, 'proxies'=>$proxies]);
    $stmt = $connection->prepare("UPDATE `server_plans` SET `custom_sni`=? WHERE `id`=?");
    $stmt->bind_param("si", $info, $match[1]);
    $stmt->execute();
    $stmt->close();

    editText($message_id, \achbotMessage('bot.bot.process_callback_query.message.e3bd301365'),getPlanDetailsKeys($match[1]));
    setUser();
}
require __DIR__ . '/src/Agent/Rejected.php';
if ((require __DIR__ . '/src/Subscription/ExternalAccounts.php') === null) return;

// END Edit by ACh

if (($achbotV8Result = require __DIR__ . '/src/Finance/Routes/RenewalSelection.php') === null) return;
if ((require __DIR__ . '/src/Subscription/ManualAccounts.php') === null) return;

if ((require __DIR__ . '/src/Subscription/OrderManagement.php') === null) return;

if (($achbotV8Result = require __DIR__ . '/src/Finance/Routes/RenewalPlan.php') === null) return;
if ((require __DIR__ . '/src/Subscription/ConfigRefresh.php') === null) return;

if ((require __DIR__ . '/src/Plans/Administration.php') === null) return;
if ((require __DIR__ . '/src/Subscription/CustomerRoutes.php') === null) return;

if (($achbotV8Result = require __DIR__ . '/src/Finance/Routes/RenewalCheckout.php') === null) return;
if ($achbotV8Result === '__ACHBOT_V8_GOTO_START__') goto GOTOSTART;
if ((require __DIR__ . '/src/Subscription/AccountTransfers.php') === null) return;

if($data=="giftVolumeAndDay" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `active` = 1 and `state` = 1");
    $stmt->execute();
    $respd = $stmt->get_result();
    $stmt->close();
    if($respd->num_rows == 0){
        alert(\achbotMessage('bot.bot.process_callback_query.alert.c0aaf0af38'),true);
        exit;
    }
    $keyboard = [];
    while($cat = $respd->fetch_assoc()){
        $sid = $cat['id'];
        $name = $cat['title'];
        $keyboard[] = ['text' => "$name", 'callback_data' => "giftToServer{$sid}"];
    }
    $keyboard = array_chunk($keyboard,2);
    $keyboard[] = [['text' => $buttonValues['back_button'], 'callback_data' => "managePanel"]];
    editText($message_id, \achbotMessage('bot.bot.process_callback_query.message.997b4e1b83'),json_encode([
            'inline_keyboard' => $keyboard
        ]));
}
if(preg_match('/^giftToServer(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    $sid = $match[1];
    $keyboard = [
        [['text' => \achbotMessage('bot.bot.process_callback_query.button.e2a9773071'), 'callback_data' => "giftToAll{$sid}"]],
        [['text' => \achbotMessage('bot.bot.process_callback_query.button.b80add87e4'), 'callback_data' => "giftToUser{$sid}"]],
        [['text' => $buttonValues['back_button'], 'callback_data' => "giftVolumeAndDay"]]
    ];
    sendMessage(\achbotMessage('bot.bot.process_callback_query.message.41aa3dcaf2'), json_encode(['inline_keyboard' => $keyboard]));
}
if(preg_match('/^giftToAll(\d+)/', $data, $match) && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    sendMessage(\achbotMessage('bot.bot.process_callback_query.message.dc1943b7b1'), $cancelKey);
    setUser('giftServerDay' . $match[1]);
}
// ACHBOT_DIRECT_GIFT_ROUTE_BEGIN
// Source-authenticated private Telegram input only. The direct-gift module
// owns the entire privilege/step/claim lifecycle; do not call setUser() here.
require_once __DIR__ . '/src/Subscription/DirectGift.php';
if (is_string($data) &&
    preg_match('/\AgiftToUser([1-9][0-9]*)\z/D',$data,$directGiftMatch)) {
    try {
        $directSource=achbotDirectGiftWebhook(
            $update,(int)$from_id,(int)$chat_id,(int)$message_id,$data,'callback');
    } catch (Throwable $e) {
        error_log('direct_gift_callback_untrusted');
        return;
    }
    try {
        $created=achbotDirectGiftStart($connection,$directSource,
            (int)$directGiftMatch[1],(int)$admin);
        if ($created) {
            sendMessage(\achbotMessage('bot.bot.process_callback_query.message.8386c28679'),$cancelKey);
        } else {
            sendMessage(\achbotMessage('bot.bot.process_callback_query.message.a4712f82bb'));
        }
    } catch (Throwable $e) {
        error_log('direct_gift_start_unconfirmed');
        try {sendMessage(\achbotMessage('bot.bot.process_callback_query.message.97447b213e'));}
        catch (Throwable $ignored) {}
    }
    return;
}
if (is_string($userInfo['step']??null) &&
    str_starts_with($userInfo['step'],'achbotDirectGift')) {
    $directStep=$userInfo['step'];
    try {
        $directSource=achbotDirectGiftWebhook(
            $update,(int)$from_id,(int)$chat_id,(int)$message_id,$text,'message');
    } catch (Throwable $e) {
        error_log('direct_gift_message_untrusted');
        return;
    }
    if ($text===$buttonValues['cancel']) {
        try {
            achbotDirectGiftCancel($connection,$directSource,(int)$admin,$directStep);
            sendMessage(\achbotMessage('bot.bot.gift_volume_and_day.message.ac0da574b4'),$removeKeyboard);
        } catch (Throwable $e) {
            error_log('direct_gift_cancel_unconfirmed');
            try {sendMessage(\achbotMessage('bot.bot.gift_volume_and_day.message.e0e7fe8fcb'));}
            catch (Throwable $ignored) {}
        }
        return;
    }
    try {
        if (str_starts_with($directStep,'achbotDirectGiftRemark:')) {
            achbotDirectGiftAdvance($connection,$directSource,(int)$admin,
                $directStep,$text);
            sendMessage(\achbotMessage('bot.bot.gift_volume_and_day.message.ceaba67bc5'));
            return;
        }
        if (str_starts_with($directStep,'achbotDirectGiftDay:')) {
            achbotDirectGiftAdvance($connection,$directSource,(int)$admin,
                $directStep,$text);
            sendMessage(\achbotMessage('bot.bot.gift_volume_and_day.message.2ff3f6e643'));
            return;
        }
        if (!str_starts_with($directStep,'achbotDirectGiftVolume:'))
            throw new RuntimeException('direct_gift_step_unknown');
        $directClaim=achbotDirectGiftClaim($connection,$directSource,(int)$admin,
                                          $directStep,$text);
    } catch (InvalidArgumentException $e) {
        try {sendMessage(\achbotMessage('bot.bot.gift_volume_and_day.message.430119925b'));}
        catch (Throwable $ignored) {}
        return;
    } catch (Throwable $e) {
        if ($e->getMessage()==='direct_gift_duplicate_source_conflict')
            error_log('direct_gift_source_provenance_conflict update_id='.(int)$directSource['update_id']);
        else error_log('direct_gift_claim_or_step_unconfirmed');
        try {sendMessage(\achbotMessage('bot.bot.gift_volume_and_day.message.e411fd7a97'));}
        catch (Throwable $ignored) {}
        return;
    }
    if (!$directClaim['claimed']) {
        try {
            if ($directClaim['reason']==='hold') {
                sendMessage(\achbotMessage('bot.bot.gift_volume_and_day.message.71a178ae08').
                    $directClaim['id'].\achbotMessage('bot.bot.gift_volume_and_day.message.f988eb99dc'));
            } else {
                sendMessage(\achbotMessage('bot.bot.gift_volume_and_day.message.8c36c4327a'));
            }
        } catch (Throwable $ignored) {}
        return;
    }
    $directChange=['remark'=>$directClaim['order']['remark']];
    if ($directClaim['days']>0) $directChange['plus_day']=$directClaim['days'];
    if ($directClaim['volume_mb']>0)
        $directChange['plus_volume']=intdiv($directClaim['volume_mb'],1024);
    $directFailure='direct_gift_provider_unverified';
    try {
        // A claim was committed before this potentially non-idempotent edit.
        // Neither a failed result nor a lost response authorizes a retry.
        achbotDirectGiftPreEffect($connection,$directClaim,
            (int)$directSource['actor_id'],(int)$admin);
        $directResult=achbotApplyServiceEdit($connection,
            $directClaim['order']['server_id'],$directChange,$directClaim['target']);
        if (!is_object($directResult))
            throw new RuntimeException('direct_gift_result_invalid');
        // Preserve safe machine-readable provider codes for operator review
        // without emitting raw HTTP, database or credential details.
        $directProviderCode=$directResult->msg??null;
        if (is_string($directProviderCode) &&
            preg_match('/\A[a-z0-9_]{1,70}\z/D',$directProviderCode)===1)
            $directFailure='provider_'.$directProviderCode;
        achbotDirectGiftComplete($connection,$directClaim,$directResult);
        try {
            sendMessage(\achbotMessage('bot.bot.gift_volume_and_day.message.df8d2180ae').
                $directClaim['id'].').',$removeKeyboard);
            sendMessage($mainValues['reached_main_menu'],getMainKeys());
        } catch (Throwable $noticeFailure) {
            error_log('direct_gift_success_notice_unconfirmed');
        }
    } catch (Throwable $e) {
        if ($e->getMessage()==='direct_gift_result_unverified' &&
            $directFailure!=='direct_gift_provider_unverified') {
            // Retain the validated provider result code for reconciliation.
        } elseif (in_array($e->getMessage(),[
            'direct_gift_preflight_order_changed',
            'direct_gift_preflight_target_changed',
            'direct_gift_admin_revoked','direct_gift_order_changed',
            'direct_gift_result_invalid','direct_gift_result_unverified',
            'direct_gift_expiry_did_not_increase',
            'direct_gift_local_identity_changed'
        ],true)) $directFailure=$e->getMessage();
        else $directFailure='direct_gift_effect_or_sql_unconfirmed';
        error_log('direct_gift_review source_id='.
            (int)$directClaim['id'].' reason='.$directFailure);
        achbotDirectGiftReview($connection,$directClaim,$directFailure);
        try {
            sendMessage(\achbotMessage('bot.bot.gift_volume_and_day.message.5999ce86f9').
                $directClaim['id'].\achbotMessage('bot.bot.gift_volume_and_day.message.e36a2dd90a'));
        } catch (Throwable $ignored) {
            error_log('direct_gift_review_notice_unconfirmed');
        }
    }
    return;
}
// The inherited non-nonced direct-gift steps are intentionally inert.
if (is_string($userInfo['step']??null) &&
    preg_match('/\A(?:giftToUserRemark|giftUserDay|giftUserVolume)/',
               $userInfo['step'])) {
    return;
}
// ACHBOT_DIRECT_GIFT_ROUTE_END

if(preg_match('/^giftServerDay(\d+)/',$userInfo['step'], $match) && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    if(is_numeric($text)){
        if($text >= 0){
            sendMessage(\achbotMessage('bot.bot.gift_volume_and_day.message.9ab673c836'));
            setUser('giftServerVolume' . $match[1] . "_" . $text);
        }else sendMessage(\achbotMessage('bot.bot.gift_volume_and_day.message.40747d2ee6'));
    }else sendMessage($mainValues['send_only_number']);
}
if(preg_match('/^giftServerVolume(\d+)_(\d+)/',$userInfo['step'],$match) && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    if(is_numeric($text)){
        if($text >= 0){
            // Bind the new-format job provenance atomically with its queue
            // row. Old zero-offset jobs may already have remote effects.
            $volume=filter_var((string)$text,FILTER_VALIDATE_INT,
                ['options'=>['min_range'=>0,'max_range'=>2147483647]]);
            $days=filter_var($match[2],FILTER_VALIDATE_INT,
                ['options'=>['min_range'=>0,'max_range'=>2147483647]]);
            $sid=filter_var($match[1],FILTER_VALIDATE_INT,
                ['options'=>['min_range'=>1,'max_range'=>2147483647]]);
            if (!preg_match('/^(0|[1-9][0-9]*)$/D',(string)$text) ||
                $volume===false || $days===false || $sid===false ||
                ($volume===0 && $days===0) ||
                ($volume>0 && $volume%1024!==0)) {
                sendMessage(\achbotMessage('bot.bot.gift_volume_and_day.message.dda8b45a3f'));
            } else {
                // Source authority comes from THIS received Telegram text
                // update, not cached step text or a callback's old bot text.
                // One source can own only one permanent batch identity.
                $giftStep='giftServerVolume'.$sid.'_'.$days;
                try {
                    require_once __DIR__ . '/src/Subscription/GiftBatch.php';
                    $giftSource=achbotGiftTelegramSource(
                        $update,$from_id,$chat_id,$message_id,$text);
                    $giftEnqueue=achbotGiftNewBatch(
                        $connection,$sid,$volume,$days,
                        $giftSource,(int)$admin,$giftStep);
                } catch (Throwable $giftEnqueueError) {
                    // An unknown COMMIT acknowledgement can mean the batch
                    // exists. Never submit a replacement job with a new
                    // source identity or claim failed SQL is absent.
                    sendMessage(\achbotMessage('bot.bot.gift_volume_and_day.message.6463835f72'));
                    return;
                }
                // The one-time wizard step was consumed atomically with
                // a NEW job; replay of an existing source never modifies
                // administrator state. Only notifications follow COMMIT.
                try {
                    if ($giftEnqueue['created']) {
                        sendMessage($mainValues['saved_successfuly'],$removeKeyboard);
                    } else {
                        sendMessage(\achbotMessage('bot.bot.gift_volume_and_day.message.b155540ffb'),
                                    $removeKeyboard);
                    }
                    sendMessage($mainValues['reached_main_menu'],getMainKeys());
                } catch (Throwable $giftNoticeError) {
                    error_log('gift_enqueue_telegram_notice_unconfirmed');
                }
            }
        }else sendMessage(\achbotMessage('bot.bot.gift_volume_and_day.message.40747d2ee6'));
    }else sendMessage($mainValues['send_only_number']);
}
else {
    require __DIR__ . '/src/Subscription/AccountDeletion.php';
}
if (($achbotV8Result = require __DIR__ . '/src/Finance/Routes/Extensions.php') === null) return;
if ($achbotV8Result === '__ACHBOT_V8_GOTO_START__') goto GOTOSTART;
if($data == 'cantEditTrojan'){
    alert(\achbotMessage('bot.bot.cant_edit_trojan.alert.4d3c064114'));
    exit;
}
if ((require __DIR__ . '/src/Plans/Categories.php') === null) return;
if(($data=='serversSetting' || preg_match('/^nextServerPage(\d+)/',$data,$match)) and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(isset($match[1])) $keys = getServerListKeys($match[1]);
    else $keys = getServerListKeys();

    editText($message_id,\achbotMessage('bot.bot.servers_setting.message.4c0e6ed6fc'),$keys);
}
if(preg_match('/^toggleServerState(\d+)_(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("UPDATE `server_info` SET `state` = IF(`state` = 0,1,0) WHERE `id`=?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $cats= $stmt->get_result();
    $stmt->close();

    alert(\achbotMessage('bot.bot.servers_setting.alert.9f4e68eda7'));

    $keys = getServerListKeys($match[2]);
    editText($message_id,\achbotMessage('bot.bot.servers_setting.message.4c0e6ed6fc'),$keys);
}
if(preg_match('/^showServerSettings(\d+)_(\d+)/',$data,$match) and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $keys = getServerConfigKeys($match[1], $match[2]);
    editText($message_id,\achbotMessage('bot.bot.servers_setting.message.ac9cd6d52a', ['cname' => $cname]),$keys);
}
if(preg_match('/^changesServerIp(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id`=?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $serverIp= $stmt->get_result()->fetch_assoc()['ip']??\achbotMessage('bot.bot.servers_setting.text.7267d348aa');
    $stmt->close();

    delMessage();
    sendMessage(\achbotMessage('bot.bot.servers_setting.message.47c8f910e4', ['serverIp' => $serverIp]),$cancelKey,null,null,null);
    setUser($data);
    exit();
}
if(preg_match('/^changesServerIp(\d+)/',$userInfo['step'],$match) && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    $stmt = $connection->prepare("UPDATE `server_config` SET `ip` = ? WHERE `id`=?");
    if($text == "/empty") $text = "";
    $stmt->bind_param("si", $text, $match[1]);
    $stmt->execute();
    $stmt->close();
    sendMessage($mainValues['saved_successfuly'],$removeKeyboard);
    setUser();

    $keys = getServerConfigKeys($match[1]);
    sendMessage(\achbotMessage('bot.bot.servers_setting.message.ac9cd6d52a', ['cname' => $cname]),$keys);
    exit();
}
// Old buttons can still arrive from a Telegram message sent before
// upgrading. Respond safely instead of silently dropping the callback.
if ($data === 'addNewServer' && ($from_id == $admin || ($userInfo['isAdmin'] ?? false) == true)) {
    alert(\achbotMessage('bot.bot.add_new_server.alert.649a7042c3'), true);
    return;
}
// Obsolete X-UI transport/Reality callbacks are intentionally retired.
if(preg_match('/^(?:changePortType|changeRealityState)\d+$/', (string)$data)){
    alert(\achbotMessage('bot.bot.add_new_server.alert.e9e9974e92'), true);
    return;
}
// Previously sent legacy admin panel-selector callbacks must never
// mutate provider type or accidentally switch a Marzban server to X-UI.
if(preg_match('/^(?:changeServerType\d+|chhangeServerType\w+_\d+)$/', (string)$data)){
    alert(\achbotMessage('bot.bot.add_new_server.alert.3340eb9465'), true);
    return;
}
// Define real-panel identity groups centrally, before assigning them to
// logical server entries. A group is trusted admin configuration, never
// inferred from endpoint aliases or Marzban admin credential equality.
if ($data === 'manageMarzbanPanelGroups' &&
    ($from_id == $admin || ($userInfo['isAdmin'] ?? false))) {
    editText($message_id, \achbotMessage('bot.bot.manage_marzban_panel_groups.message.59d5170317'),
        getMarzbanPanelGroupsManagementKeys());
    return;
}
if ($data === 'addMarzbanPanelGroup' &&
    ($from_id == $admin || ($userInfo['isAdmin'] ?? false))) {
    setUser('newMarzbanPanelGroup');
    sendMessage(\achbotMessage('bot.bot.add_marzban_panel_group.message.01f02c2ac6')
        . \achbotMessage('bot.bot.add_marzban_panel_group.message.31116063c0'), $cancelKey);
    return;
}
if (($userInfo['step'] ?? '') === 'newMarzbanPanelGroup' &&
    $text != $buttonValues['cancel'] &&
    ($from_id == $admin || ($userInfo['isAdmin'] ?? false))) {
    if (!achbotMarzbanGroupValid((string)$text)) {
        sendMessage(\achbotMessage('bot.bot.add_marzban_panel_group.message.c697bdb683'));
        return;
    }
    try {
        // Creation owns the transaction and cross-connection catalog lock.
        $groupId = achbotCreateMarzbanPanelGroup($connection, $text);
        if ($groupId === null) {
            sendMessage(\achbotMessage('bot.bot.add_marzban_panel_group.message.cfb10ede6f'));
            return;
        }
    } catch (\Throwable $e) {
        sendMessage(\achbotMessage('bot.bot.add_marzban_panel_group.message.7c6b361e59'));
        return;
    }
    setUser();
    sendMessage(\achbotMessage('bot.bot.add_marzban_panel_group.message.1de4f68601'), getMarzbanPanelGroupsManagementKeys());
    return;
}
if (preg_match('/^deleteMarzbanPanelGroup_(\d+)$/D', (string)($data ?? ''), $groupMatch) &&
    ($from_id == $admin || ($userInfo['isAdmin'] ?? false))) {
    $connection->begin_transaction();
    try {
        $stmt = $connection->prepare(
            "SELECT id FROM setting WHERE id = ? AND type = 'ACHBOT_MARZBAN_PANEL_GROUP' FOR UPDATE"
        );
        $gid = (int)$groupMatch[1];
        $stmt->bind_param('i', $gid);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows === 1;
        $stmt->close();
        $removed = $exists && achbotDeleteMarzbanPanelGroup($connection, $gid);
        $connection->commit();
    } catch (\Throwable $e) {
        $connection->rollback();
        alert(\achbotMessage('bot.bot.add_marzban_panel_group.alert.a77f2c705d'), true);
        return;
    }
    alert($removed ? \achbotMessage('bot.bot.add_marzban_panel_group.alert.2b2b180a96') :
        \achbotMessage('bot.bot.add_marzban_panel_group.alert.cc8538eba9'), true);
    editText($message_id, \achbotMessage('bot.bot.add_marzban_panel_group.message.def9f141f2'), getMarzbanPanelGroupsManagementKeys());
    return;
}
if($data == "addNewMarzbanPanel" and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    setUser($data, 'temp');
    setUser('addserverName');
    sendMessage(\achbotMessage('bot.bot.add_new_marzban_panel.message.2dcf34731e'),$cancelKey);
    exit();
}
if($userInfo['step'] == 'addserverName' and $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
	sendMessage(\achbotMessage('bot.bot.addserver_name.message.3dd8c855bc'));
    $data = array();
    $data['title'] = $text;

    setUser('addServerUCount' . json_encode($data,JSON_UNESCAPED_UNICODE));
    exit();
}
if(preg_match('/^addServerUCount(.*)/',$userInfo['step'],$match) and $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $data = json_decode($match[1],true);
    $data['ucount'] = $text;

    sendMessage(\achbotMessage('bot.bot.addserver_name.message.ae25347c18'));
    setUser('addServerRemark' . json_encode($data,JSON_UNESCAPED_UNICODE));
    exit();
}
if(preg_match('/^addServerRemark(.*)/',$userInfo['step'], $match) and $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $data = json_decode($match[1], true);
    $data['remark'] = $text;

    sendMessage(\achbotMessage('bot.bot.addserver_name.message.ba9445a606'));
    setUser('addServerFlag' . json_encode($data,JSON_UNESCAPED_UNICODE));
    exit();
}
if(preg_match('/^addServerFlag(.*)/',$userInfo['step'], $match) and $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $data = json_decode($match[1],true);
    $data['flag'] = $text;
    sendMessage(\achbotMessage('bot.bot.addserver_name.message.63c645dbbd'));
    setUser('addServerPanelUrl' . json_encode($data,JSON_UNESCAPED_UNICODE));
    exit();
}
if(preg_match('/^addServerPanelUrl(.*)/',$userInfo['step'],$match) and $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $data = json_decode($match[1],true);
    $data['panel_url'] = $text;
    if(($userInfo['temp'] ?? '') !== "addNewMarzbanPanel"){
        alert(\achbotMessage('bot.bot.addserver_name.alert.f1fb84ec27'), true);
        return;
    }
    $data['panel_ip'] = "/empty";
    $data['sni'] = "/empty";
    $data['header_type'] = "/empty";
    $data['response_header'] = "/empty";
    $data['request_header'] = "/empty";
    $data['security'] = "/empty";
    $data['tls_setting'] = "/empty";

    setUser('addServerPanelGroup' . json_encode($data, JSON_UNESCAPED_UNICODE));
    sendMessage(\achbotMessage('bot.bot.addserver_name.message.8da737cc0d')
        . \achbotMessage('bot.bot.addserver_name.message.3de3b9cb9e')
        . \achbotMessage('bot.bot.addserver_name.message.9a519033ca')
        . \achbotMessage('bot.bot.addserver_name.text.395b086745'),
        getMarzbanPanelGroupKeys('add'));
    exit();
}
if (preg_match('/^selectNewPanelGroup_(\d+)$/D', (string)($data ?? ''), $select) &&
    ($from_id == $admin || ($userInfo['isAdmin'] ?? false))) {
    if (!preg_match('/^addServerPanelGroup(.*)$/s', (string)($userInfo['step'] ?? ''), $match) ||
        ($userInfo['temp'] ?? '') !== 'addNewMarzbanPanel') {
        alert(\achbotMessage('bot.bot.addserver_name.alert.f07dd5dcd6'), true);
        return;
    }
    $wizard = json_decode($match[1], true);
    $groupId = (int)$select[1];
    $found = $groupId === 0;
    foreach (achbotMarzbanPanelGroups($connection) as $group) {
        if ((int)$group['id'] === $groupId) $found = true;
    }
    if (!is_array($wizard) || !$found) {
        alert(\achbotMessage('bot.bot.addserver_name.alert.55b6ebf4b1'), true);
        return;
    }
    $wizard['panel_group_id'] = $groupId;
    setUser('addServerPanelUser' . json_encode($wizard, JSON_UNESCAPED_UNICODE));
    sendMessage(\achbotMessage('bot.bot.addserver_name.message.cd5fbb01df'));
    return;
}
if(preg_match('/^addServerPanelUser(.*)/',$userInfo['step'],$match) and $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $data = json_decode($match[1],true);
    $data['panel_user'] = $text;
    setUser('addServerPanePassword' . json_encode($data, JSON_UNESCAPED_UNICODE));
    sendMessage( \achbotMessage('bot.bot.addserver_name.message.cf8b6b8420'));
exit();
}
if(preg_match('/^addServerPanePassword(.*)/',$userInfo['step'],$match) and $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    sendMessage(\achbotMessage('bot.bot.addserver_name.message.4a29ff45e6'));
    $data = json_decode($match[1],true);
    $title = $data['title'];
    $ucount = $data['ucount'];
    $remark = $data['remark'];
    $flag = $data['flag'];

    $panel_url = $data['panel_url'];
    $ip = $data['panel_ip']!="/empty"?$data['panel_ip']:"";
    $sni = $data['sni']!="/empty"?$data['sni']:"";
    $header_type = $data['header_type']!="/empty"?$data['header_type']:"none";
    $request_header = $data['request_header']!="/empty"?$data['request_header']:"";
    $response_header = $data['response_header']!="/empty"?$data['response_header']:"";
    $security = $data['security']!="/empty"?$data['security']:"none";
    $tlsSettings = $data['tls_setting']!="/empty"?$data['tls_setting']:"";
    $serverName = $data['panel_user'];
    $serverPass = $text;
    $panelGroupId = (int)($data['panel_group_id'] ?? 0);
    if ($panelGroupId < 0) {
        alert(\achbotMessage('bot.bot.addserver_name.alert.6558e924a9'), true);
        return;
    }

    $loginResponse['success'] = false;
    if(($userInfo['temp'] ?? '') !== 'addNewMarzbanPanel'){
        alert(\achbotMessage('bot.bot.addserver_name.alert.f1fb84ec27'), true);
        return;
    }
    $loginResponse['success'] = false;

        $loginUrl = $panel_url .'/api/admin/token';
        $postFields = array(
            'username' => $serverName,
            'password' => $serverPass
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
        $response = json_decode(curl_exec($curl),true);

        if(curl_error($curl)){
            $loginResponse = ['success' => false, 'error'=>curl_error($curl)];
        }
        curl_close($curl);

        if(isset($response['access_token'])){
            $loginResponse['success'] = true;
        }

    if(!$loginResponse['success']){
        setUser('addServerPanelUser' . json_encode($data, JSON_UNESCAPED_UNICODE));
        sendMessage( \achbotMessage('bot.bot.addserver_name.message.aaacd0e81b'));
        exit();
    }
    // One atomic original-schema write: a failed group setting cannot
    // leave an orphan logical server with uncertain panel identity.
    $connection->begin_transaction();
    try {
        $stmt = $connection->prepare("INSERT INTO `server_info` (`title`, `ucount`, `remark`, `flag`, `active`)
                                                        VALUES (?,?,?,?,1)");
        $stmt->bind_param("siss", $title, $ucount, $remark, $flag);
        $stmt->execute();
        $rowId = $stmt->insert_id;
        $stmt->close();

        $stmt = $connection->prepare("INSERT INTO `server_config` (`id`, `panel_url`, `ip`, `sni`, `header_type`, `request_header`, `response_header`, `security`, `tlsSettings`, `username`, `password`, `type`)
                                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'marzban')");
        $stmt->bind_param("issssssssss", $rowId, $panel_url, $ip, $sni, $header_type, $request_header, $response_header, $security, $tlsSettings, $serverName, $serverPass);
        $stmt->execute();
        $stmt->close();
        achbotSetMarzbanGroup($connection, $rowId, $panelGroupId);
        $connection->commit();
    } catch (\Throwable $e) {
        $connection->rollback();
        sendMessage(\achbotMessage('bot.bot.addserver_name.message.b1a72d260a'));
        return;
    }

    sendMessage(\achbotMessage('bot.bot.addserver_name.message.8e97362beb'),$removeKeyboard);
    $keys = getServerListKeys();
    sendMessage(\achbotMessage('bot.bot.addserver_name.message.c93e2f0866'),$keys);

    setUser();
    exit();
}
// Admin server settings: select an EXISTING panel group, never a
// free-text value or automatic URL/username-based identity.
if (preg_match('/^editMarzbanGroup(\d+)$/D', (string)($data ?? ''), $groupMatch) &&
    ($from_id == $admin || ($userInfo['isAdmin'] ?? false))) {
    $id = (int)$groupMatch[1];
    $stmt = $connection->prepare("SELECT type FROM server_config WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $panel = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!achbotSupportsPanel($panel['type'] ?? null)) {
        alert(\achbotMessage('bot.bot.addserver_name.alert.27e6f9c466'), true);
        return;
    }
    sendMessage(\achbotMessage('bot.bot.addserver_name.message.c31e264971'),
        getMarzbanPanelGroupKeys('edit', $id));
    return;
}
if (preg_match('/^setServerPanelGroup_(\d+)_(\d+)$/D', (string)($data ?? ''), $groupMatch) &&
    ($from_id == $admin || ($userInfo['isAdmin'] ?? false))) {
    $id = (int)$groupMatch[1];
    $groupId = (int)$groupMatch[2];
    if ($id <= 0) {
        alert(\achbotMessage('bot.bot.addserver_name.alert.48259ae799'), true);
        return;
    }
    $connection->begin_transaction();
    try {
        $stmt = $connection->prepare("SELECT type FROM server_config WHERE id = ? FOR UPDATE");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $panel = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!achbotSupportsPanel($panel['type'] ?? null)) {
            $connection->rollback();
            alert(\achbotMessage('bot.bot.addserver_name.alert.9ee9f25355'), true);
            return;
        }
        achbotSetMarzbanGroup($connection, $id, $groupId);
        $connection->commit();
    } catch (\Throwable $e) {
        $connection->rollback();
        alert(\achbotMessage('bot.bot.addserver_name.alert.a5625b915f'), true);
        return;
    }
    sendMessage(\achbotMessage('bot.bot.addserver_name.message.9495c6622a'), getServerConfigKeys($id));
    return;
}
// Refuse an older in-flight R3 free-text group step rather than
// accepting an arbitrary new group not defined by the administrator.
if (preg_match('/^editMarzbanGroup\d+$/D', (string)($userInfo['step'] ?? '')) &&
    $text != $buttonValues['cancel'] &&
    ($from_id == $admin || ($userInfo['isAdmin'] ?? false))) {
    setUser();
    sendMessage(\achbotMessage('bot.bot.addserver_name.message.8dc219842f'));
    return;
}
if(preg_match('/^changesServerLoginInfo(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    delMessage();
    setUser($data);
    sendMessage( \achbotMessage('bot.bot.addserver_name.message.adf1de6a42'),$cancelKey);
}
if(preg_match('/^changesServerLoginInfo(\d+)/',$userInfo['step'],$match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $data = array();
    $data['rowId'] = $match[1];
    $data['panel_url'] = $text;
    setUser('editServerPaneUser' . json_encode($data, JSON_UNESCAPED_UNICODE));
    sendMessage( \achbotMessage('bot.bot.addserver_name.message.bae567d2c7'),$cancelKey);
    exit();
}
if(preg_match('/^editServerPaneUser(.*)/',$userInfo['step'],$match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $data = json_decode($match[1],true);
    $data['panel_user'] = $text;
    setUser('editServerPanePassword' . json_encode($data, JSON_UNESCAPED_UNICODE));
    sendMessage( \achbotMessage('bot.bot.addserver_name.message.530a9525e2'));
    exit();
}
if(preg_match('/^editServerPanePassword(.*)/',$userInfo['step'],$match) and $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    sendMessage(\achbotMessage('bot.bot.addserver_name.message.4a29ff45e6'));
    $data = json_decode($match[1],true);

    $rowId = $data['rowId'];
    $panel_url = $data['panel_url'];
    $serverName = $data['panel_user'];
    $serverPass = $text;


    $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id` = ?");
    $stmt->bind_param('i', $rowId);
    $stmt->execute();
    $serverInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if(!achbotSupportsPanel($serverInfo['type'] ?? null)){
        alert(\achbotMessage('bot.bot.addserver_name.alert.f1fb84ec27'), true);
        setUser();
        return;
    }
    $serverType = $serverInfo['type'];
    $loginResponse['success'] = false;


        $loginUrl = $panel_url .'/api/admin/token';
        $postFields = array(
            'username' => $serverName,
            'password' => $serverPass
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
        $response = json_decode(curl_exec($curl),true);

        if(curl_error($curl)){
            $loginResponse = ['success' => false, 'error'=>curl_error($curl)];
        }
        curl_close($curl);

        if(isset($response['access_token'])){
            $loginResponse['success'] = true;
        }


    if(!$loginResponse['success']) sendMessage( \achbotMessage('bot.bot.addserver_name.message.c7ea45277d'));
    else{
        $stmt = $connection->prepare("UPDATE `server_config` SET `panel_url` = ?, `username` = ?, `password` = ? WHERE `id` = ?");
        $stmt->bind_param("sssi", $panel_url, $serverName, $serverPass, $rowId);
        $stmt->execute();
        $stmt->close();

        sendMessage(\achbotMessage('bot.bot.addserver_name.message.bf4ac7e119'),$removeKeyboard);
    }
    $keys = getServerConfigKeys($rowId);
    sendMessage(\achbotMessage('bot.bot.servers_setting.message.4c0e6ed6fc'),$keys);
    setUser();
}

if ((require __DIR__ . '/src/Subscription/SubLinkSettings.php') === null) return;

if(preg_match('/^wizwizdeleteserver(\d+)/',$data,$match) and ($from_id == $admin || ($userInfo['isAdmin'] == true && $permissions['servers']))){
    editText($message_id,\achbotMessage('bot.bot.addserver_name.message.ead7addb70'),json_encode(['inline_keyboard'=>[
        [['text'=>\achbotMessage('bot.bot.addserver_name.button.13159f8c74'),'callback_data'=>"yesDeleteServer" . $match[1]],['text'=>\achbotMessage('bot.bot.addserver_name.button.f47dddc181'),'callback_data'=>"showServerSettings" . $match[1] . "_0"]]
        ]]));
}
if(preg_match('/^yesDeleteServer(\d+)/',$data,$match) && ($from_id == $admin || ($userInfo['isAdmin'] == true && $permissions['servers']))){
    $stmt = $connection->prepare("DELETE FROM `server_info` WHERE `id`=?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $stmt->close();

    $stmt = $connection->prepare("DELETE FROM `server_config` WHERE `id`=?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $stmt->close();
    // The group is a nullable server_info field and is removed with its row.

    alert(\achbotMessage('bot.bot.addserver_name.alert.601504ae47'));


    $keys = getServerListKeys();
    if($keys == null) editText($message_id,\achbotMessage('bot.bot.addserver_name.message.756c3005d3'));
    else editText($message_id,\achbotMessage('bot.bot.servers_setting.message.4c0e6ed6fc'),$keys);
}
if(preg_match('/^editServer(\D+)(\d+)/',$data,$match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    switch($match[1]){
        case "Name":
            $txt =\achbotMessage('bot.bot.addserver_name.txt.437acc9f7f');
            break;
        case "Max":
            $txt = \achbotMessage('bot.bot.addserver_name.txt.57400196e6');
            break;
        case "Remark":
            $txt =\achbotMessage('bot.bot.addserver_name.txt.f4e3596bd2');
            break;
        case "Flag":
            $txt = \achbotMessage('bot.bot.addserver_name.txt.fe242ccc0e');
            break;
        default:
            $txt = str_replace("_", " ", $match[1]);
            $end = \achbotMessage('bot.bot.addserver_name.end.42b150373f');
            break;
    }
    delMessage();
    sendMessage(\achbotMessage('bot.bot.addserver_name.message.8f3366957d') . $txt . \achbotMessage('bot.bot.addserver_name.message.035f46db23') . $end,$cancelKey);
    setUser($data);
    exit();
}
if(preg_match('/^editServer(\D+)(\d+)/',$userInfo['step'],$match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    switch($match[1]){
        case "Name":
            $sql = "UPDATE `server_info` SET `title`";
            break;
        case "Flag":
            $sql = "UPDATE `server_info` SET `flag`";
            break;
        case "Remark":
            $sql = "UPDATE `server_info` SET `remark`";
            break;
        case "Max":
            $sql = "UPDATE `server_info` SET `ucount`";
            break;
    }

    if($text == "/empty"){
        $stmt = $connection->prepare("$sql IS NULL WHERE `id`=?");
        $stmt->bind_param("i", $match[2]);
        $stmt->execute();
        $stmt->close();
    }else{
        $stmt = $connection->prepare("$sql=? WHERE `id`=?");
        $stmt->bind_param("si",$text, $match[2]);
        $stmt->execute();
        $stmt->close();
    }

    sendMessage($mainValues['saved_successfuly'],$removeKeyboard);
    setUser();

    $keys = getServerConfigKeys($match[2]);
    sendMessage(\achbotMessage('bot.bot.addserver_name.message.664df12986', ['cname' => $cname]),$keys);
    exit();
}
if(preg_match('/^editsServer(\D+)(\d+)/',$data,$match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $txt = str_replace("_", " ", $match[1]);
    delMessage();
    sendMessage(\achbotMessage('bot.bot.addserver_name.message.8f3366957d') . $txt . \achbotMessage('bot.bot.addserver_name.message.25ce426865'),$cancelKey);
    setUser($data);
    exit();
}
if(preg_match('/^editsServer(\D+)(\d+)/',$userInfo['step'],$match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if($text == "/empty"){
        if($match[1] == "sni") $stmt = $connection->prepare("UPDATE `server_config` SET `sni` = '' WHERE `id`=?");
        elseif($match[1] == "header_type") $stmt = $connection->prepare("UPDATE `server_config` SET `header_type` = 'none' WHERE `id`=?");
        elseif($match[1] == "request_header") $stmt = $connection->prepare("UPDATE `server_config` SET `request_header` = '' WHERE `id`=?");
        elseif($match[1] == "response_header") $stmt = $connection->prepare("UPDATE `server_config` SET `response_header` = '' WHERE `id`=?");
        elseif($match[1] == "security") $stmt = $connection->prepare("UPDATE `server_config` SET `security` = 'none' WHERE `id`=?");
        elseif($match[1] == "tlsSettings") $stmt = $connection->prepare("UPDATE `server_config` SET `tlsSettings` = '' WHERE `id`=?");

        $stmt->bind_param("i", $match[2]);
    }else{
        if($match[1] == "sni") $stmt = $connection->prepare("UPDATE `server_config` SET `sni`=? WHERE `id`=?");
        elseif($match[1] == "header_type"){
            if($text != "http" && $text != "none"){
                sendMessage(\achbotMessage('bot.bot.addserver_name.message.cac7dafe0d'));
                exit();
            }else $stmt = $connection->prepare("UPDATE `server_config` SET `header_type`=? WHERE `id`=?");
        }
        elseif($match[1] == "request_header") $stmt = $connection->prepare("UPDATE `server_config` SET `request_header`=? WHERE `id`=?");
        elseif($match[1] == "response_header") $stmt = $connection->prepare("UPDATE `server_config` SET `response_header`=? WHERE `id`=?");
        elseif($match[1] == "security"){
            if($text != "tls" && $text != "none" && $text != "xtls"){
                sendMessage(\achbotMessage('bot.bot.addserver_name.message.d763b5be93'));
                exit();
            }else $stmt = $connection->prepare("UPDATE `server_config` SET `security`=? WHERE `id`=?");
        }
        elseif($match[1] == "tlsSettings") $stmt = $connection->prepare("UPDATE `server_config` SET `tlsSettings`=? WHERE `id`=?");
        $stmt->bind_param("si",$text, $match[2]);
    }
    $stmt->execute();
    $stmt->close();

    sendMessage($mainValues['saved_successfuly'],$removeKeyboard);
    setUser();

    $keys = getServerConfigKeys($match[2]);
    sendMessage(\achbotMessage('bot.bot.addserver_name.message.664df12986', ['cname' => $cname]),$keys);
    exit();
}
if(preg_match('/^editServer(\D+)(\d+)/',$data,$match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    switch($match[1]){
        case "Name":
            $txt =\achbotMessage('bot.bot.addserver_name.txt.437acc9f7f');
            break;
        case "Max":
            $txt = \achbotMessage('bot.bot.addserver_name.txt.57400196e6');
            break;
        case "Remark":
            $txt =\achbotMessage('bot.bot.addserver_name.txt.f4e3596bd2');
            break;
        case "Flag":
            $txt = \achbotMessage('bot.bot.addserver_name.txt.fe242ccc0e');
            break;
    }
    delMessage();
    sendMessage(\achbotMessage('bot.bot.addserver_name.message.8f3366957d') . $txt . \achbotMessage('bot.bot.addserver_name.message.035f46db23'),$cancelKey);
    setUser($data);
}
if(preg_match('/^editServer(\D+)(\d+)/',$userInfo['step'],$match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    switch($match[1]){
        case "Name":
            $stmt = $connection->prepare("UPDATE `server_info` SET `title`=? WHERE `id`=?");
            break;
        case "Max":
            $stmt = $connection->prepare("UPDATE `server_info` SET `ucount`=? WHERE `id`=?");
            break;
        case "Remark":
            $stmt = $connection->prepare("UPDATE `server_info` SET `remark`=? WHERE `id`=?");
            break;
        case "Flag":
            $stmt = $connection->prepare("UPDATE `server_info` SET `flag`=? WHERE `id`=?");
            break;
    }

    $stmt->bind_param("si",$text, $match[2]);
    $stmt->execute();
    $stmt->close();

    sendMessage($mainValues['saved_successfuly'],$removeKeyboard);
    setUser();

    $keys = getServerConfigKeys($match[2]);
    sendMessage(\achbotMessage('bot.bot.addserver_name.message.664df12986', ['cname' => $cname]),$keys);
}

if (($achbotV8Result = require __DIR__ . '/src/Finance/Routes/DiscountAdministration.php') === null) return;
if($data == "managePanel" and (($from_id == $admin || $userInfo['isAdmin'] == true))){

    // Cached $userInfo may predate a concurrent financial commit. For any
    // pending adjustment, cancellation must acquire the SAME operator lock
    // and claim the exact step before saying funds were not moved.
    $walletStepBeforePanel = (string)($userInfo['step'] ?? '');
    $walletPanelCancellation = achbotAdminWalletIsPendingStep($walletStepBeforePanel);
    $walletPanelOutcome = null;
    if ($walletPanelCancellation) {
        $walletPanelOutcome = achbotCancelAdminWalletPendingStep(
            $connection, (int)$from_id, $walletStepBeforePanel, (int)$admin
        );
    } else {
        // Preserve the existing nonfinancial panel-navigation behavior.
        setUser();
    }
    $msg = \achbotMessage('bot.bot.manage_panel.msg.5db5d59c90', ['first_name' => $first_name]);
    if ($walletPanelOutcome === 'canceled') {
        $msg .= \achbotMessage('bot.bot.manage_panel.msg.7d8f330c92');
    } elseif ($walletPanelCancellation) {
        // Stale/ambiguous database state is NOT proof of cancellation.
        $msg .= \achbotMessage('bot.bot.manage_panel.msg.19a94a73a2');
    }
    editText($message_id, $msg, getAdminKeys());
}
if($data == 'reciveApplications') {
    $stmt = $connection->prepare("SELECT * FROM `needed_sofwares` WHERE `status`=1");
    $stmt->execute();
    $respd= $stmt->get_result();
    $stmt->close();

    $keyboard = [];
    while($file =  $respd->fetch_assoc()){
        $link = $file['link'];
        $title = $file['title'];
        $keyboard[] = ['text' => "$title", 'url' => $link];
    }
	$keyboard[] = ['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"];
    $keyboard = array_chunk($keyboard,1);
    editText($message_id, \achbotMessage('bot.bot.recive_applications.message.ec86dead1a', ['supportID' => $supportID]), json_encode(['inline_keyboard'=>$keyboard]), "html");
}
if ($text == $buttonValues['cancel']) {
    // config.php loaded userInfo before any concurrent direct-gift callback.
    // Never let a stale generic CANCEL erase a newer nonce-protected step.
    try {
        $legacyCancelAccepted=achbotDirectGiftGuardLegacyCancel(
            $connection,(int)$from_id,$userInfo);
    } catch (Throwable $e) {
        error_log('legacy_cancel_commit_or_identity_unconfirmed');
        return;
    }
    if (!$legacyCancelAccepted) {
        error_log('legacy_cancel_stale_cached_step');
        return;
    }
    $stmt = $connection->prepare("DELETE FROM `server_plans` WHERE `active`=0");
    $stmt->execute();
    $stmt->close();

    sendMessage($mainValues['waiting_message'], $removeKeyboard);
    sendMessage($mainValues['reached_main_menu'],getMainKeys());
}
?>
