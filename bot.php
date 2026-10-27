<?php

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
    sendMessage("اکانت شما به دلیل اسپم مسدود شده است.\nزمان آزادسازی اکانت شما: \n$time");
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
            'این دکمهٔ پرداخت معتبر نیست؛ لطفاً از فاکتور جدید استفاده کنید.', true);
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
					$inviterMsg = "🎉 تبریک! کاربر جدیدی با لینک دعوت شما عضو شد.\n\n"
						. "💰 مبلغ هدیه شما: " . number_format($inviteReward) . " تومان\n"
						. "💼 موجودی قبل: " . number_format($oldWallet) . " تومان\n"
						. "💵 موجودی جدید: " . number_format($newWallet) . " تومان";
					sendMessage($inviterMsg, null, "html", $inviter);

					// ارسال پیام به ادمین
					$adminMsg = "👥 <b>دعوت موفق توسط <code>$inviter</code> انجام شد.</b>\n\n"
						. "🎫 شناسه کاربر جدید: <code>$from_id</code>\n"
						. "💸 پاداش داده‌شده: " . number_format($inviteReward) . " تومان\n"
						. "💼 موجودی قبل: " . number_format($oldWallet) . " تومان\n"
						. "💵 موجودی جدید: " . number_format($newWallet) . " تومان";
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
    editText($message_id,'✉️ لطفا پیام خود را بنویسید.');
    setUser($data);
}
if(preg_match('/^sendMessageToUser(\d+)/',$userInfo['step'],$match) && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    sendMessage($text,null,null,$match[1]);
	sendMessage("پیام شما برای کاربر <code>{$match[1]}</code> ارسال شد.", $removeKeyboard, "html");
    sendMessage($mainValues['reached_main_menu'],getAdminKeys());
    setUser();
}


// هندلر فروش در بازه دلخواه
// این بخش باید در bot.php باشد تا بعد از require_once config.php و در مسیر اصلی ربات اجرا شود.
if (isset($data) && $data == "custom_sales_date" && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    setUser("awaitingDateRange", "step");
    sendMessage(
        "📅 لطفاً تاریخ شروع و پایان فروش را وارد کنید:\n\n" .
        "فرمت نمونه:\n<code>2026-05-21 to 2026-05-22</code>\n" .
        "یا\n<code>2026/05/21 تا 2026/05/22</code>",
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
        sendMessage("❌ عملیات لغو شد.", $removeKeyboard, "HTML");
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
            "❌ فرمت تاریخ درست نیست. لطفاً دقیقاً مثل نمونه وارد کنید:\n<code>2026-05-21 to 2026-05-22</code>",
            $cancelKey,
            "HTML"
        );
        exit;
    }

    $startDate = $dates[0];
    $endDate = $dates[1];

    if (strtotime($startDate . ' 00:00:00') > strtotime($endDate . ' 23:59:59')) {
        sendMessage("❌ تاریخ شروع نباید بعد از تاریخ پایان باشد.", $cancelKey, "HTML");
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
        'stats_users' => "👤 آمار کاربران",
        'stats_orders' => "📦 آمار اشتراک‌ها",
        'stats_sales' => "💰 آمار فروش",
    ];

    // انتخاب عنوان بر اساس کلید
    $title = $titles[$statsKey] ?? "📊 لطفاً یکی از بخش‌های آماری زیر را انتخاب کنید:";

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
            'subLinkState','configLinkState','searchState',
            'renewConfigLinkState','updateConfigLinkState','qrConfigState',
            'qrSubState','cartToCartAutoAcceptState','cartToCartAutoAcceptType'
        ];
        if (!in_array($match[1],$editableBotToggles,true)) {
            answerCallbackQuery($callbackId,'این تنظیم قدیمی یا ناشناخته قابل تغییر نیست.',true);
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
        answerCallbackQuery($callbackId,'دسترسی مدیریت پرداخت تأیید نشد.',true);
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
            answerCallbackQuery($callbackId,'این درگاه قدیمی یا ناشناخته قابل تغییر نیست.',true);
            return;
        }
        if (!achbotAdminGatewayChange($update,(int)$from_id,
                (int)$chat_id,(int)$message_id,(int)$admin,
                'toggle',$match[1])) {
            answerCallbackQuery($callbackId,
                'تنظیم تغییر نکرد؛ دسترسی یا اعتبار درخواست را بررسی کنید.',true);
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
        answerCallbackQuery($callbackId,'دسترسی مدیریت پرداخت تأیید نشد.',true);
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
            'تغییر ذخیره نشد؛ ممکن است دکمه مربوط به نسخهٔ قبلی قوانین باشد.',true);
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
                "⚠️ تغییری ذخیره نشد. اطلاعات دکمه ممکن است قدیمی باشد؛ وضعیت فعلی:\n".
                $page['text'],$page['keyboard']);
        }
        return;
    }
    if ($operation['action']==='n') {
        sendMessage("✏️ عدد صحیح دلخواه (از ۰ به بالا) وارد کنید.\n".
            "۰ روز یعنی تمام سابقه؛ ۰ تراکنش با روز مثبت یعنی حداقل یک رویداد در بازه.\n".
            "برای انصراف از دکمهٔ لغو استفاده کنید.",$cancelKey);
        return;
    }
    if (in_array($operation['action'],['s','x'],true)) {
        sendMessage($operation['action']==='s'?'✅ مقدار ذخیره شد.':'ویرایش لغو شد.');
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
    editText($message_id,'✅ تنظیم به‌روزرسانی شد.'."\n".$page['text'],$page['keyboard']);
    return;
}
if (is_string($userInfo['step']??null) &&
    str_starts_with($userInfo['step'],'gr|edit|')) {
    if (!achbotAdminGatewayChange($update,(int)$from_id,
            (int)$chat_id,(int)$message_id,(int)$admin,'number',
            $userInfo['step'],(string)$text)) {
        sendMessage('⛔️ عدد معتبر وارد کنید؛ تغییر اعمال نشد. / در صورت مشکل به فهرست مدیریت دسترسی بازگردید.',
                    $cancelKey);
        return;
    }
    if ($text===$buttonValues['cancel']) {
        sendMessage('ویرایش لغو شد.');
        return;
    }
    $sourceId=(int)($update->update_id??0);
    sendMessage("🔎 مقدار پیشنهادی: ".$text."\nتا انتخاب «ذخیره»، قانون قبلی برقرار است.",
        json_encode(['inline_keyboard'=>[
            [['text'=>'💾 ذخیره مقدار','callback_data'=>'gr|s|'.$sourceId]],
            [['text'=>'❌ انصراف','callback_data'=>'gr|x|'.$sourceId]]
        ]],JSON_UNESCAPED_UNICODE));
    return;
}
// Per-gateway audience restrictions. Re-check the current setting on every
// callback; a forwarded stale administrator button cannot pick arbitrary keys.
if (($from_id == $admin || (int)($userInfo['isAdmin']??0)===1) &&
    $data==='gatewayAudienceSettings') {
    editText($message_id,'👥 دسترسی درگاه‌ها برای گروه‌های مختلف کاربران',
             achbotGatewayAudienceKeyboard());
    return;
}
if (($from_id == $admin || (int)($userInfo['isAdmin']??0)===1) &&
    preg_match('/^cycleGatewayAudience_([a-z_]+)$/D',(string)$data,$policyMatch)) {
    $method=$policyMatch[1];
    if (!isset(achbotPayUxAudienceMethods()[$method])) {
        answerCallbackQuery($callbackId,'روش پرداخت ناشناخته است.',true);
        return;
    }
    if (!achbotAdminGatewayChange($update,(int)$from_id,
            (int)$chat_id,(int)$message_id,(int)$admin,
            'audience',$method)) {
        answerCallbackQuery($callbackId,
            'تنظیم ذخیره نشد؛ دسترسی یا اعتبار درخواست را بررسی کنید.',true);
        return;
    }
    editText($message_id,'👥 دسترسی درگاه‌ها برای گروه‌های مختلف کاربران',
             achbotGatewayAudienceKeyboard());
    return;
}
// Only actual administrators may change invoice and anti-duplicate windows.
if (($from_id == $admin || (int)($userInfo['isAdmin']??0)===1) &&
    $data==='paymentPolicySettings') {
    editText($message_id,'⏱ <b>مهلت فاکتورها و محافظ‌های رسید تکراری</b>',
        achbotPaymentPolicyKeyboard(),'HTML',$from_id);
    return;
}
if (($from_id == $admin || (int)($userInfo['isAdmin']??0)===1) &&
    preg_match('/^editPaymentPolicy_([A-Za-z]+)$/D',(string)$data,$policyMatch)) {
    $fields=achbotPaymentPolicyFields();$key=$policyMatch[1];
    if (!isset($fields[$key])) {
        answerCallbackQuery($callbackId,'تنظیم ناشناخته است.',true);return;
    }
    $field=$fields[$key];
    setUser('paymentPolicyValue_'.$key);
    sendMessage($field['label']."\nیک عدد بین ".$field['min']." و ".$field['max']." وارد کنید.",$cancelKey);
    return;
}
if (($from_id == $admin || (int)($userInfo['isAdmin']??0)===1) &&
    preg_match('/^paymentPolicyValue_([A-Za-z]+)$/D',(string)($userInfo['step']??''),$policyMatch) &&
    $text!==$buttonValues['cancel']) {
    $fields=achbotPaymentPolicyFields();$key=$policyMatch[1];
    if (!isset($fields[$key]) || !preg_match('/^[0-9]{1,5}$/D',(string)$text) ||
        (int)$text<$fields[$key]['min'] || (int)$text>$fields[$key]['max']) {
        sendMessage('⛔️ مقدار واردشده نامعتبر است؛ در بازهٔ نمایش‌داده‌شده وارد کنید.', $cancelKey);
        return;
    }
    if (!setSettings($key,(int)$text)) {
        sendMessage('⛔️ تنظیم ذخیره نشد؛ لطفاً دوباره وضعیت را بررسی کنید.', $cancelKey);
        return;
    }
    setUser();
    sendMessage('✅ تنظیم ذخیره شد.',achbotPaymentPolicyKeyboard());
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
        answerCallbackQuery($callbackId, 'این تنظیم شناخته‌شده نیست.', true);
        return;
    }
    if (!achbotAdminPaymentKeyChange($update,(int)$from_id,(int)$chat_id,
            (int)$message_id,(int)$admin,'start',$match[1])) {
        answerCallbackQuery($callbackId,
            'تنظیم شروع نشد؛ دسترسی یا اطلاعات فعلی را بررسی کنید.',true);
        return;
    }
    delMessage();
    switch($match[1]){
        case "nextpay":
            $gate = "کد جدید درگاه نکست پی";
            break;
        case "nowpayment":
            $gate = "کد جدید درگاه nowPayment";
            break;
        case "zarinpal":
            $gate = "کد جدید درگاه زرین پال";
            break;
        case "bankAccount":
            $gate = "شماره حساب جدید";
            break;
        case "holderName":
            $gate = "اسم دارنده حساب";
            break;
        case "giftCardUrl":
            $gate = "لینک HTTPS معتبر خرید گیفت‌کارت (حداکثر ۳۵۰ بایت)";
            break;
        case "manualUsdtWalletBEP20":
        case "manualUsdtWalletTRC20":
        case "manualUsdtWalletERC20":
            $network = substr($match[1], strlen('manualUsdtWallet'));
            $gate = "آدرس عمومی USDT برای شبکهٔ $network (برای حذف /clear بفرستید)";
            break;
        case "manualTrxWallet":
            $gate = "آدرس عمومی TRX در شبکهٔ TRON (برای حذف /clear بفرستید)";
            break;
        case "manualUsdtRateToman":
            $gate = "قیمت هر USDT به تومان، عدد صحیح مثبت (برای حذف /clear بفرستید)";
            break;
        case "manualTrxRateToman":
            $gate = "قیمت مستقل هر TRX به تومان، عدد صحیح مثبت (برای حذف /clear بفرستید)";
            break;
        default:
            answerCallbackQuery($callbackId, 'این تنظیم شناخته‌شده نیست.', true);
            return;
    }
    sendMessage("🔘 لطفا $gate را وارد کنید", $cancelKey);
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
        sendMessage('⛔️ ویرایش قبلی معتبر نیست؛ از منوی درگاه‌ها دوباره شروع کنید.');
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
            '⛔️ تنظیم ذخیره نشد؛ ممکن است دسترسی یا مقدار قبلی تغییر کرده باشد.',
            $cancelKey);
        return;
    }
    if ($kind==='cancel') {
        sendMessage('ویرایش لغو شد.', $removeKeyboard);
    } else {
        sendMessage($mainValues['saved_successfuly'],$removeKeyboard);
    }
    sendMessage($mainValues['change_bot_settings_message'],getGateWaysKeys());
    return;
}
require __DIR__ . '/src/Agent/Discounts.php';
if(preg_match('/^edit(RewaredTime|cartToCartAutoAcceptTime)/', $data, $match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    if($match[1] == "RewaredTime") $txt = "🙃 لطفا زمان تأخیر در ارسال گزارش رو به ساعت وارد کن\n\nنکته: هر n ساعت گزارش به ربات ارسال میشه! ";
    else $txt = "لطفا زمان مورد نظر را به دقیقه وارد کنید.";

    sendMessage($txt,$cancelKey);
    setUser($data);
}
if ($data == "userReports" && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    delMessage();
    sendMessage("🔢 لطفا شناسه عددی کاربر رو وارد کنید.", $cancelKey);
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
			sendMessage("📊 اطلاعات کاربر\n\n<code>🆔 $text</code>", $keys, "html");
			setUser();
		} else {
            sendMessage("کاربری با این آیدی یافت نشد.");
        }
    } else {
        sendMessage("😡 لطفا فقط عدد ارسال کنید.");
    }
}

if($data=="inviteSetting" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'INVITE_BANNER_AMOUNT'");
    $stmt->execute();
    $inviteAmount = number_format($stmt->get_result()->fetch_assoc()['value']??0) . " تومان";
    $stmt->close();
    setUser();
    $keys = json_encode(['inline_keyboard'=>[
        [['text'=>"❗️بنر دعوت",'callback_data'=>"inviteBanner"]],
        [
            ['text'=>$inviteAmount,'callback_data'=>"editInviteAmount"],
            ['text'=>"مقدار پورسانت",'callback_data'=>"wizwizch"]
            ],
        [
            ['text'=>$buttonValues['back_button'],'callback_data'=>"botSettings"]
            ],
        ]]);
    $res = editText($message_id,"✅ تنظیمات بازاریابی",$keys);
    if(!$res->ok){
        delMessage();
        sendMessage("✅ تنظیمات بازاریابی",$keys);
    }
}
if($data=="inviteBanner" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'INVITE_BANNER_TEXT'");
    $stmt->execute();
    $inviteText = $stmt->get_result()->fetch_assoc()['value'];
    $inviteText = $inviteText != null?json_decode($inviteText,true):array('type'=>'text');
    $stmt->close();
    $keys = json_encode(['inline_keyboard'=>[
        [['text'=>"ویرایش",'callback_data'=>'editInviteBannerText']],
        [['text'=>$buttonValues['back_button'],'callback_data'=>'inviteSetting']]
        ]]);
    if($inviteText['type'] == "text"){
        editText($message_id,"بنر فعلی: \n" . $inviteText['text'],$keys);
    }else{
        delMessage();
        $res = sendPhoto($inviteText['file_id'], $inviteText['caption'], $keys,null);
        if(!$res->ok){
            sendMessage("تصویر فعلی یافت نشد، لطفا اقدام به ویرایش بنر کنید",$keys);
        }
    }
    setUser();
}
if($data=="editInviteBannerText" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage("🪧 لطفا بنر جدید را بفرستید از متن LINK برای نمایش لینک دعوت استفاده کنید.",$cancelKey);
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
        sendMessage("🥺 بنر ارسال شده پشتیبانی نمی شود.");
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
        [['text'=>"ویرایش",'callback_data'=>'editInviteBannerText']],
        [['text'=>$buttonValues['back_button'],'callback_data'=>'inviteSetting']]
        ]]);
    if(isset($update->message->text)){
        sendMessage("بنر فعلی: \n" . $text,$keys);
    }else{
        sendPhoto($fileid, $caption, $keys);
    }
    setUser();
}
if($data=="editInviteAmount" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage("لطفا مبلغ پورسانت را به تومان وارد کنید.",$cancelKey);
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
            [['text'=>"❗️بنر دعوت",'callback_data'=>"inviteBanner"]],
            [
                ['text'=>number_format($text) . " تومان",'callback_data'=>"editInviteAmount"],
                ['text'=>"مقدار پورسانت",'callback_data'=>"wizwizch"]
                ],
            [
                ['text'=>$buttonValues['back_button'],'callback_data'=>"botSettings"]
                ],
            ]]);
        sendMessage("✅ تنظیمات بازاریابی",$keys);
        setUser();
    }else sendMessage($mainValues['send_only_number']);
}
if(preg_match('/^edit(RewaredTime|cartToCartAutoAcceptTime)/', $userInfo['step'], $match) && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    if(!is_numeric($text)){
        sendMessage("لطفا عدد بفرستید.");
        exit();
    }
    elseif($text <0 ){
        sendMessage("مقدار وارد شده معتبر نیست.");
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
        $inviteAmount = number_format($stmt->get_result()->fetch_assoc()['value'] ?? 0) . " تومان";
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
"🎁 هدیه هر دعوت: <b>$inviteReward تومان</b>\n" .
"💵 پورسانت هر خرید: <b>$inviteAmount</b>";

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
        alert("این قسمت غیر فعال است.");
    }
}

if($data=="myInfo"){
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `userid` = ?");
    $stmt->bind_param("i", $from_id);
    $stmt->execute();
    $totalBuys = $stmt->get_result()->num_rows;
    $stmt->close();

    $myWallet = number_format($userInfo['wallet']) . " تومان";

    $keys = json_encode(['inline_keyboard'=>[
        [
            ['text'=>"شارژ کیف پول 💵",'callback_data'=>"increaseMyWallet"],
            ['text'=>"🔀 جابجایی موجودی",'callback_data'=>"transferMyWallet"]
        ],
        [
            ['text'=>$buttonValues['back_button'],'callback_data'=>"mainMenu"]
            ]
        ]]);
    editText($message_id, "
📊 اطلاعات حساب شما:

🎫 شناسه عددی: <code>$from_id</code>
🔰 نام کاربری: @$username
👤 اسم: $first_name
💰 موجودی: <b>$myWallet</b>

🌐 تعداد کل اشتراک ها: <b>$totalBuys</b> عدد

💡 پیشنهاد می کنیم همیشه کیف پول خودتون رو قبل از پایان اکانت یا تمام شدن حجم شارژ کنید تا هر زمان که خواستید بدون نیاز به تایید ادمین بتوانید اکانت خود را تمدید کنید.
",
            $keys,"html");
}
if (($achbotV8Result = require __DIR__ . '/src/Finance/Routes/WalletTransfer.php') === null) return;
if (($achbotV8Result = require __DIR__ . '/src/Finance/Routes/WalletRecharge.php') === null) return;
if ($achbotV8Result === '__ACHBOT_V8_GOTO_START__') goto GOTOSTART;
if (($achbotV8Result = require __DIR__ . '/src/Finance/Routes/ManualReceipts.php') === null) return;
if (($achbotV8Result = require __DIR__ . '/src/Finance/Routes/AdminWallet.php') === null) return;
if($data=="editRewardChannel" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage("⚠️ لطفا ربات را در کانال ادمین کنید و آیدی کانال را بفرستید.",$cancelKey);
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
        sendMessage($mainValues['network_error_try_again'] ?? "❗️اختلال شبکه؛ لطفاً دوباره تلاش کنید.");
        exit;
    }
    $botId = $me->result->id;

    $chat = bot('getChat', ['chat_id' => $text]);
    if (!$chat || empty($chat->ok) || empty($chat->result->id)) {
        sendMessage($mainValues['invalid_channel_id'] ?? "❗️آیدی کانال صحیح نیست یا ربات به کانال دسترسی ندارد.");
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

    sendMessage("😡 ای بابا، ربات هنوز تو کانال عضو/ادمین نشده، اول ربات رو تو کانال ادمین کن و آیدیش رو بفرست.");
}

if ($data == "editLockChannel" && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    delMessage();
    sendMessage("🤗 لطفاً ربات رو در کانال ادمین کن و آیدی کانال رو بفرست", $cancelKey);
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
        sendMessage($mainValues['network_error_try_again'] ?? "❗️اختلال شبکه؛ لطفاً دوباره تلاش کنید.");
        exit;
    }
    $botId = $me->result->id;

    $chat = bot('getChat', ['chat_id' => $text]);
    if (!$chat || empty($chat->ok) || empty($chat->result->id)) {
        sendMessage($mainValues['invalid_channel_id'] ?? "❗️آیدی کانال صحیح نیست یا ربات به کانال دسترسی ندارد.");
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

    sendMessage($mainValues['the_bot_in_not_admin'] ?? "❗️ربات در کانال ادمین نیست یا دسترسی کافی ندارد.");
}
// End Edited by ACh

if($data=="editSupportID" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage("🤗 لطفا آیدی پشتیبانی رو وارد کنید.",$cancelKey);
    setUser($data);
}

if($userInfo['step'] == "editSupportID" && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    setSettings('supportID', $text);
    sendMessage("✅ آیدی پشتیبانی با موفقیت ذخیره شد.", getGateWaysKeys());
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
                sendMessage("⛔️ سیستم فروش غیرفعال است.", null, "MarkDown", $chat_id);
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

            $textToSend = "1️⃣ مرحله یک

➕ لطفا یک مورد را انتخاب کنید.
💡 شما می توانید کیف پول خودتون رو قبل از پایان اکانت یا اتمام حجم شارژ کنید تا هر زمان که خواستید بدون نیاز به تایید ادمین بتوانید اکانت جدید خریداری کنید یا تمدید کنید.";

            sendMessage($textToSend, json_encode(['inline_keyboard' => $keyboard]), "MarkDown", $chat_id);

        } else {
            sendMessage("⛔️ سیستم فروش غیرفعال است.", null, "MarkDown", $chat_id);
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
                'text' => "⬅️",
                'callback_data' => $isAgentView ? "changeAgentOrder$prev" : "changeOrdersPage$prev"
            ];
        }

        if ($page < $number_of_page) {
            $buttons[] = [
                'text' => "➡️",
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
        sendMessage("📨 پیامی که می خواهید کاربر دریافت کند را ارسال کنید.");
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
        ['text'=>"تعداد باقیمانده",'callback_data'=>"wizwizch"],
        ['text'=>"پلن",'callback_data'=>"wizwizch"],
        ['text'=>'سرور','callback_data'=>"wizwizch"]
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
                ['text'=>$acount . " اکانت",'callback_data'=>"wizwizch"],
                ['text'=>$title??" ",'callback_data'=>"wizwizch"],
                ['text'=>$name??" ",'callback_data'=>"wizwizch"]
                ];
        }
    }
    $keys[] = [['text'=>$buttonValues['back_button'],'callback_data'=>"mainMenu"]];
    $keys = json_encode(['inline_keyboard'=>$keys]);
    editText($message_id, "🟢 موجودی پلن اشتراکی:", $keys);
}
if($data=="availableServers2"){
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `inbound_id` = 0");
    $stmt->execute();
    $serversList = $stmt->get_result();
    $stmt->close();

    $keys = array();
    $keys[] = [
        ['text'=>"تعداد باقیمانده",'callback_data'=>"wizwizch"],
        ['text'=>'سرور','callback_data'=>"wizwizch"]
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
                ['text'=>$acount2 . " اکانت",'callback_data'=>"wizwizch"],
                ['text'=>$title2??" ",'callback_data'=>"wizwizch"],
                ];
        }
    }
    $keys[] = [['text'=>$buttonValues['back_button'],'callback_data'=>"mainMenu"]];
    $keys = json_encode(['inline_keyboard'=>$keys]);
    editText($message_id, "🟢 موجودی پلن اختصاصی:", $keys);
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
    editText($message_id,"🧮 مدیریت دکمه ها",getMainMenuButtonsKeys());
}
if(preg_match('/^delMainButton(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("DELETE FROM `setting` WHERE `id` = ?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $stmt->close();

    alert("با موفقیت حذف شد.");
    editText($message_id,"🧮 مدیریت دکمه ها",getMainMenuButtonsKeys());
}
if($data == "addNewMainButton" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage("لطفا اسم دکمه را وارد کنید",$cancelKey);
    setUser($data);
}
if($userInfo['step'] == "addNewMainButton" && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(!isset($update->message->text)){
        sendMessage("لطفا فقط متن بفرستید");
        exit();
    }
    sendMessage("لطفا پاسخ دکمه را وارد کنید");
    setUser("setMainButtonAnswer" . $text);
}

if (preg_match('/^setMainButtonAnswer(.*)/', $userInfo['step'], $match) && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    if (!isset($update->message->text)) {
        sendMessage("لطفا فقط متن بفرستید");
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

    sendMessage("🧮 مدیریت دکمه ها", getMainMenuButtonsKeys());
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
    editText($message_id, "لطفا نوع شبکه های این پلن را انتخاب کنید.",$networkType);
}
if(preg_match('/^selectHost(?<planId>\d+)\*_\*(?<protocol>.+)\*_\*(?<tag>.*)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $saveBtn = "ذخیره ✅";
    unset($markup[count($markup)-1]);
    if($markup[count($markup)-1][0]['text'] == $saveBtn) unset($markup[count($markup)-1]);
    foreach($markup as $key => $keyboard){
        if($keyboard[0]['callback_data'] == $data) $markup[$key][0]['text'] = $keyboard['0']['text'] == $match['tag'] . " ✅" ? $match['tag']:$match['tag'] . " ✅";
    }

    if(strstr(json_encode($markup,JSON_UNESCAPED_UNICODE), "✅") && !strstr(json_encode($markup,JSON_UNESCAPED_UNICODE), $saveBtn)){
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
        $tag = trim(str_replace("✅", "", $value[0]['text'], $state));
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

    editText($message_id, "با موفقیت ذخیره شد.",getPlanDetailsKeys($match[1]));
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
        alert('در حال حاضر هیچ سرور فعالی برای هدیه دادن وجود ندارد.',true);
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
    editText($message_id, ' 📍 لطفا برای هدیه دادن, یکی از سرورها را انتخاب کنید👇',json_encode([
            'inline_keyboard' => $keyboard
        ]));
}
if(preg_match('/^giftToServer(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    $sid = $match[1];
    $keyboard = [
        [['text' => "🎁 هدیه به همه کاربران", 'callback_data' => "giftToAll{$sid}"]],
        [['text' => "👤 هدیه به کاربر خاص", 'callback_data' => "giftToUser{$sid}"]],
        [['text' => $buttonValues['back_button'], 'callback_data' => "giftVolumeAndDay"]]
    ];
    sendMessage("لطفا نوع هدیه دادن را انتخاب کن:", json_encode(['inline_keyboard' => $keyboard]));
}
if(preg_match('/^giftToAll(\d+)/', $data, $match) && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    sendMessage("مدت زمان هدیه را به روز وارد کن (برای صفر، عدد 0 را وارد کن):", $cancelKey);
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
            sendMessage('نام کاربری اشتراک موردنظر را وارد کنید:',$cancelKey);
        } else {
            sendMessage('این درخواست قبلاً ثبت شده است. برای شروع جدید دوباره دکمه را انتخاب کنید.');
        }
    } catch (Throwable $e) {
        error_log('direct_gift_start_unconfirmed');
        try {sendMessage('شروع هدیه تأیید نشد؛ پیش از تلاش مجدد وضعیت را بررسی کنید.');}
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
            sendMessage('درخواست هدیه لغو شد.',$removeKeyboard);
        } catch (Throwable $e) {
            error_log('direct_gift_cancel_unconfirmed');
            try {sendMessage('لغو درخواست تأیید نشد؛ وضعیت را بررسی کنید.');}
            catch (Throwable $ignored) {}
        }
        return;
    }
    try {
        if (str_starts_with($directStep,'achbotDirectGiftRemark:')) {
            achbotDirectGiftAdvance($connection,$directSource,(int)$admin,
                $directStep,$text);
            sendMessage('تعداد روز هدیه را به عدد صحیح وارد کنید (صفر مجاز است):');
            return;
        }
        if (str_starts_with($directStep,'achbotDirectGiftDay:')) {
            achbotDirectGiftAdvance($connection,$directSource,(int)$admin,
                $directStep,$text);
            sendMessage('حجم هدیه را به مگابایت وارد کنید؛ عدد صحیح و مضرب 1024 (صفر مجاز است):');
            return;
        }
        if (!str_starts_with($directStep,'achbotDirectGiftVolume:'))
            throw new RuntimeException('direct_gift_step_unknown');
        $directClaim=achbotDirectGiftClaim($connection,$directSource,(int)$admin,
                                          $directStep,$text);
    } catch (InvalidArgumentException $e) {
        try {sendMessage('ورودی نامعتبر است. روز باید عدد صحیح 0 تا 36500 باشد؛ حجم باید عدد صحیح غیرمنفی و مضرب 1024 مگابایت باشد و هر دو نمی‌توانند صفر باشند.');}
        catch (Throwable $ignored) {}
        return;
    } catch (Throwable $e) {
        if ($e->getMessage()==='direct_gift_duplicate_source_conflict')
            error_log('direct_gift_source_provenance_conflict update_id='.(int)$directSource['update_id']);
        else error_log('direct_gift_claim_or_step_unconfirmed');
        try {sendMessage('وضعیت درخواست تأیید نشد. از ارسال دوباره تا بررسی وضعیت خودداری کنید.');}
        catch (Throwable $ignored) {}
        return;
    }
    if (!$directClaim['claimed']) {
        try {
            if ($directClaim['reason']==='hold') {
                sendMessage('این اشتراک هدیهٔ در انتظار بررسی دارد (شناسه '.
                    $directClaim['id'].'). پیش از درخواست جدید، وضعیت ثبت و اثر واقعی را دستی تطبیق دهید.');
            } else {
                sendMessage('این پیام قبلاً بررسی شده است؛ هیچ هدیهٔ جدیدی اعمال نشد.');
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
            sendMessage('✅ هدیهٔ اشتراک با نتیجهٔ قطعی ثبت شد (شناسه '.
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
            sendMessage('نتیجهٔ هدیه قطعی نیست (شناسه '.
                $directClaim['id'].'). درخواست تکرار نمی‌شود؛ قبل از هر اقدام، اثر واقعی اشتراک و ثبت محلی را دستی تطبیق دهید.');
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
            sendMessage("حجم هدیه را به مگابایت وارد کن (برای صفر، عدد 0 را وارد کن):");
            setUser('giftServerVolume' . $match[1] . "_" . $text);
        }else sendMessage("عددی بزرگتر و یا مساوی 0 واردکنید.");
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
                sendMessage('حجم باید مضرب 1024 مگابایت و حداقل یکی از حجم یا روز مثبت باشد.');
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
                    sendMessage('وضعیت ثبت هدیه تأیید نشد؛ پیش از هر اقدام جدید صف هدایا را بررسی کنید.');
                    return;
                }
                // The one-time wizard step was consumed atomically with
                // a NEW job; replay of an existing source never modifies
                // administrator state. Only notifications follow COMMIT.
                try {
                    if ($giftEnqueue['created']) {
                        sendMessage($mainValues['saved_successfuly'],$removeKeyboard);
                    } else {
                        sendMessage('این پیام قبلاً برای هدیه ثبت شده و درخواست تکراری ایجاد نشد.',
                                    $removeKeyboard);
                    }
                    sendMessage($mainValues['reached_main_menu'],getMainKeys());
                } catch (Throwable $giftNoticeError) {
                    error_log('gift_enqueue_telegram_notice_unconfirmed');
                }
            }
        }else sendMessage("عددی بزرگتر و یا مساوی 0 واردکنید.");
    }else sendMessage($mainValues['send_only_number']);
}
else {
    require __DIR__ . '/src/Subscription/AccountDeletion.php';
}
if (($achbotV8Result = require __DIR__ . '/src/Finance/Routes/Extensions.php') === null) return;
if ($achbotV8Result === '__ACHBOT_V8_GOTO_START__') goto GOTOSTART;
if($data == 'cantEditTrojan'){
    alert("پروتکل تروجان فقط نوع شبکه TCP را دارد");
    exit;
}
if ((require __DIR__ . '/src/Plans/Categories.php') === null) return;
if(($data=='serversSetting' || preg_match('/^nextServerPage(\d+)/',$data,$match)) and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(isset($match[1])) $keys = getServerListKeys($match[1]);
    else $keys = getServerListKeys();

    editText($message_id,"☑️ مدیریت سرور ها:",$keys);
}
if(preg_match('/^toggleServerState(\d+)_(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("UPDATE `server_info` SET `state` = IF(`state` = 0,1,0) WHERE `id`=?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $cats= $stmt->get_result();
    $stmt->close();

    alert("وضعیت سرور با موفقیت تغییر کرد.");

    $keys = getServerListKeys($match[2]);
    editText($message_id,"☑️ مدیریت سرور ها:",$keys);
}
if(preg_match('/^showServerSettings(\d+)_(\d+)/',$data,$match) and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $keys = getServerConfigKeys($match[1], $match[2]);
    editText($message_id,"☑️ مدیریت سرور ها: $cname",$keys);
}
if(preg_match('/^changesServerIp(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id`=?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $serverIp= $stmt->get_result()->fetch_assoc()['ip']??"اطلاعاتی یافت نشد";
    $stmt->close();

    delMessage();
    sendMessage("لیست آیپی های فعلی: \n$serverIp\nلطفا آیپی های جدید را در خط های جدا بفرستید\n\nبرای خالی کردن متن /empty را وارد کنید",$cancelKey,null,null,null);
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
    sendMessage("☑️ مدیریت سرور ها: $cname",$keys);
    exit();
}
// Old buttons can still arrive from a Telegram message sent before
// upgrading. Respond safely instead of silently dropping the callback.
if ($data === 'addNewServer' && ($from_id == $admin || ($userInfo['isAdmin'] ?? false) == true)) {
    alert("ثبت پنل‌های قدیمی حذف شده است؛ از ثبت سرور مرزبان استفاده کنید.", true);
    return;
}
// Obsolete X-UI transport/Reality callbacks are intentionally retired.
if(preg_match('/^(?:changePortType|changeRealityState)\d+$/', (string)$data)){
    alert("تنظیمات پنل‌های قدیمی دیگر پشتیبانی نمی‌شود.", true);
    return;
}
// Previously sent legacy admin panel-selector callbacks must never
// mutate provider type or accidentally switch a Marzban server to X-UI.
if(preg_match('/^(?:changeServerType\d+|chhangeServerType\w+_\d+)$/', (string)$data)){
    alert("پنل‌های قدیمی حذف شده‌اند؛ تنها مرزبان پشتیبانی می‌شود.", true);
    return;
}
// Define real-panel identity groups centrally, before assigning them to
// logical server entries. A group is trusted admin configuration, never
// inferred from endpoint aliases or Marzban admin credential equality.
if ($data === 'manageMarzbanPanelGroups' &&
    ($from_id == $admin || ($userInfo['isAdmin'] ?? false))) {
    editText($message_id, "🏷 گروه‌های پنل مرزبان؛ فقط سرورهایی که به یک پنل واقعی متصل‌اند باید گروه مشترک داشته باشند.",
        getMarzbanPanelGroupsManagementKeys());
    return;
}
if ($data === 'addMarzbanPanelGroup' &&
    ($from_id == $admin || ($userInfo['isAdmin'] ?? false))) {
    setUser('newMarzbanPanelGroup');
    sendMessage("نام گروه پنل جدید را وارد کنید (۱ تا ۶۰ حرف؛ فارسی مجاز است).\n"
        . "توجه: این گروه صرفاً برای ورودی‌های متعلق به یک پنل واقعی مرزبان استفاده شود.", $cancelKey);
    return;
}
if (($userInfo['step'] ?? '') === 'newMarzbanPanelGroup' &&
    $text != $buttonValues['cancel'] &&
    ($from_id == $admin || ($userInfo['isAdmin'] ?? false))) {
    if (!achbotMarzbanGroupValid((string)$text)) {
        sendMessage("نام گروه نامعتبر است؛ حداکثر ۶۰ کاراکتر و بدون خط جدید.");
        return;
    }
    try {
        // Creation owns the transaction and cross-connection catalog lock.
        $groupId = achbotCreateMarzbanPanelGroup($connection, $text);
        if ($groupId === null) {
            sendMessage("این نام قبلاً تعریف شده است یا معتبر نیست؛ نام دیگری وارد کنید.");
            return;
        }
    } catch (\Throwable $e) {
        sendMessage("ذخیره گروه پنل انجام نشد.");
        return;
    }
    setUser();
    sendMessage("✅ گروه پنل ثبت شد.", getMarzbanPanelGroupsManagementKeys());
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
        alert("حذف گروه پنل ناموفق بود.", true);
        return;
    }
    alert($removed ? "گروه استفاده‌نشده حذف شد." :
        "گروه پیدا نشد یا هنوز به سرور متصل است؛ ابتدا سرورها را از آن خارج کنید.", true);
    editText($message_id, "🏷 مدیریت گروه‌های پنل مرزبان", getMarzbanPanelGroupsManagementKeys());
    return;
}
if($data == "addNewMarzbanPanel" and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    setUser($data, 'temp');
    setUser('addserverName');
    sendMessage("مرحله اول:
▪️ یه اسم برا سرورت انتخاب کن:",$cancelKey);
    exit();
}
if($userInfo['step'] == 'addserverName' and $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
	sendMessage('مرحله دوم:
▪️ ظرفیت تعداد ساخت اشتراک رو برای سرورت مشخص کن ( عدد باشه )');
    $data = array();
    $data['title'] = $text;

    setUser('addServerUCount' . json_encode($data,JSON_UNESCAPED_UNICODE));
    exit();
}
if(preg_match('/^addServerUCount(.*)/',$userInfo['step'],$match) and $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $data = json_decode($match[1],true);
    $data['ucount'] = $text;

    sendMessage("مرحله سوم:
▪️ یه اسم (نام کاربری) برای اشتراک انتخاب کن:
 ( به صورت انگیلیسی و بدون فاصله )
");
    setUser('addServerRemark' . json_encode($data,JSON_UNESCAPED_UNICODE));
    exit();
}
if(preg_match('/^addServerRemark(.*)/',$userInfo['step'], $match) and $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $data = json_decode($match[1], true);
    $data['remark'] = $text;

    sendMessage("مرحله چهارم:
▪️ لطفا یه ( ایموجی پرچم 🇮🇷 ) برا سرورت انتخاب کن:");
    setUser('addServerFlag' . json_encode($data,JSON_UNESCAPED_UNICODE));
    exit();
}
if(preg_match('/^addServerFlag(.*)/',$userInfo['step'], $match) and $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $data = json_decode($match[1],true);
    $data['flag'] = $text;
    sendMessage("مرحله پنجم:

▪️ لطفا آدرس پنل مرزبان رو به صورت مثال زیر وارد کن:

❕https://yourdomain.com:54321
❕https://yourdomain.com:54321/path
❗️http://125.12.12.36:54321
❗️http://125.12.12.36:54321/path

اگر سرور مورد نظر با دامنه و ssl هست از مثال ( ❕) استفاده کنید.
اگر سرور مورد نظر با ip و بدون ssl هست از مثال ( ❗️) استفاده کنید.
⁮⁮ ⁮⁮ ⁮⁮ ⁮⁮
");
    setUser('addServerPanelUrl' . json_encode($data,JSON_UNESCAPED_UNICODE));
    exit();
}
if(preg_match('/^addServerPanelUrl(.*)/',$userInfo['step'],$match) and $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $data = json_decode($match[1],true);
    $data['panel_url'] = $text;
    if(($userInfo['temp'] ?? '') !== "addNewMarzbanPanel"){
        alert("این نوع پنل دیگر پشتیبانی نمی‌شود.", true);
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
    sendMessage("مرحله ششم: 🏷 گروه پنل واقعی را انتخاب کنید.\n"
        . "این گروه فقط برای سرورهایی یکسان باشد که واقعاً یک پنل مرزبان هستند؛"
        . " حتی اگر آدرس API و نام کاربری مدیریتی آنها متفاوت باشد.\n"
        . "برای تعریف گروه جدید از «مدیریت گروه‌های پنل» در تنظیمات سرورها استفاده کنید.",
        getMarzbanPanelGroupKeys('add'));
    exit();
}
if (preg_match('/^selectNewPanelGroup_(\d+)$/D', (string)($data ?? ''), $select) &&
    ($from_id == $admin || ($userInfo['isAdmin'] ?? false))) {
    if (!preg_match('/^addServerPanelGroup(.*)$/s', (string)($userInfo['step'] ?? ''), $match) ||
        ($userInfo['temp'] ?? '') !== 'addNewMarzbanPanel') {
        alert("مراحل ثبت سرور منقضی شده است.", true);
        return;
    }
    $wizard = json_decode($match[1], true);
    $groupId = (int)$select[1];
    $found = $groupId === 0;
    foreach (achbotMarzbanPanelGroups($connection) as $group) {
        if ((int)$group['id'] === $groupId) $found = true;
    }
    if (!is_array($wizard) || !$found) {
        alert("گروه انتخابی وجود ندارد؛ لطفاً دوباره انتخاب کنید.", true);
        return;
    }
    $wizard['panel_group_id'] = $groupId;
    setUser('addServerPanelUser' . json_encode($wizard, JSON_UNESCAPED_UNICODE));
    sendMessage("مرحله هفتم: ▪️ لطفا نام کاربری پنل مرزبان را وارد کنید:");
    return;
}
if(preg_match('/^addServerPanelUser(.*)/',$userInfo['step'],$match) and $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $data = json_decode($match[1],true);
    $data['panel_user'] = $text;
    setUser('addServerPanePassword' . json_encode($data, JSON_UNESCAPED_UNICODE));
    sendMessage( "مرحله هشتم:
▪️لطفا پسورد پنل را وارد کنید:");
exit();
}
if(preg_match('/^addServerPanePassword(.*)/',$userInfo['step'],$match) and $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    sendMessage("⏳ در حال ورود به اکانت ...");
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
        alert("شناسه گروه پنل معتبر نیست.", true);
        return;
    }

    $loginResponse['success'] = false;
    if(($userInfo['temp'] ?? '') !== 'addNewMarzbanPanel'){
        alert("این نوع پنل دیگر پشتیبانی نمی‌شود.", true);
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
        sendMessage( "
⚠️ با خطا مواجه شدی!

برای رفع این مشکل روی لینک زیر بزن و ویس رو با دقت گوش کن 👇

⛔️🔗 https://t.me/wizwizch/186

مجدد نام کاربری پنل را وارد کنید:
⁮⁮ ⁮⁮
        ");
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
        sendMessage("ثبت سرور انجام نشد؛ هیچ سرور ناقصی در دیتابیس ذخیره نشد.");
        return;
    }

    sendMessage(" تبریک، سرورت رو ثبت کردی 🥹",$removeKeyboard);
    $keys = getServerListKeys();
    sendMessage("☑️ مدیریت سرور ها",$keys);

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
        alert("تنها پنل مرزبان پشتیبانی می‌شود.", true);
        return;
    }
    sendMessage("🏷 گروه پنل این سرور را انتخاب کنید. فقط سرورهای متعلق به یک پنل واقعی مرزبان باید گروه مشترک داشته باشند.",
        getMarzbanPanelGroupKeys('edit', $id));
    return;
}
if (preg_match('/^setServerPanelGroup_(\d+)_(\d+)$/D', (string)($data ?? ''), $groupMatch) &&
    ($from_id == $admin || ($userInfo['isAdmin'] ?? false))) {
    $id = (int)$groupMatch[1];
    $groupId = (int)$groupMatch[2];
    if ($id <= 0) {
        alert("شناسه سرور معتبر نیست.", true);
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
            alert("پنل مرزبان معتبر نیست.", true);
            return;
        }
        achbotSetMarzbanGroup($connection, $id, $groupId);
        $connection->commit();
    } catch (\Throwable $e) {
        $connection->rollback();
        alert("تغییر گروه مجاز نیست: گروه مقصد نامعتبر است یا سفارش‌های وابسته را ناسازگار می‌کند.", true);
        return;
    }
    sendMessage("✅ گروه پنل سرور به‌روزرسانی شد.", getServerConfigKeys($id));
    return;
}
// Refuse an older in-flight R3 free-text group step rather than
// accepting an arbitrary new group not defined by the administrator.
if (preg_match('/^editMarzbanGroup\d+$/D', (string)($userInfo['step'] ?? '')) &&
    $text != $buttonValues['cancel'] &&
    ($from_id == $admin || ($userInfo['isAdmin'] ?? false))) {
    setUser();
    sendMessage("روش گروه‌بندی تغییر کرده است؛ گروه را از فهرست تعریف‌شده انتخاب کنید.");
    return;
}
if(preg_match('/^changesServerLoginInfo(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    delMessage();
    setUser($data);
    sendMessage( "▪️ لطفا آدرس پنل را وارد کنید:",$cancelKey);
}
if(preg_match('/^changesServerLoginInfo(\d+)/',$userInfo['step'],$match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $data = array();
    $data['rowId'] = $match[1];
    $data['panel_url'] = $text;
    setUser('editServerPaneUser' . json_encode($data, JSON_UNESCAPED_UNICODE));
    sendMessage( "▪️ لطفا یوزر پنل را وارد کنید:",$cancelKey);
    exit();
}
if(preg_match('/^editServerPaneUser(.*)/',$userInfo['step'],$match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $data = json_decode($match[1],true);
    $data['panel_user'] = $text;
    setUser('editServerPanePassword' . json_encode($data, JSON_UNESCAPED_UNICODE));
    sendMessage( "▪️ لطفا پسورد پنل را وارد کنید:");
    exit();
}
if(preg_match('/^editServerPanePassword(.*)/',$userInfo['step'],$match) and $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    sendMessage("⏳ در حال ورود به اکانت ...");
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
        alert("این نوع پنل دیگر پشتیبانی نمی‌شود.", true);
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


    if(!$loginResponse['success']) sendMessage( "اطلاعاتی که وارد کردی اشتباهه 😂");
    else{
        $stmt = $connection->prepare("UPDATE `server_config` SET `panel_url` = ?, `username` = ?, `password` = ? WHERE `id` = ?");
        $stmt->bind_param("sssi", $panel_url, $serverName, $serverPass, $rowId);
        $stmt->execute();
        $stmt->close();

        sendMessage("اطلاعات ورود سرور با موفقیت عوض شد.",$removeKeyboard);
    }
    $keys = getServerConfigKeys($rowId);
    sendMessage('☑️ مدیریت سرور ها:',$keys);
    setUser();
}

if ((require __DIR__ . '/src/Subscription/SubLinkSettings.php') === null) return;

if(preg_match('/^wizwizdeleteserver(\d+)/',$data,$match) and ($from_id == $admin || ($userInfo['isAdmin'] == true && $permissions['servers']))){
    editText($message_id,"از حذف سرور مطمئنی؟",json_encode(['inline_keyboard'=>[
        [['text'=>"بله",'callback_data'=>"yesDeleteServer" . $match[1]],['text'=>"نخير",'callback_data'=>"showServerSettings" . $match[1] . "_0"]]
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

    alert("🙂 سرور رو چرا حذف کردی اخه ...");


    $keys = getServerListKeys();
    if($keys == null) editText($message_id,"موردی یافت نشد.");
    else editText($message_id,"☑️ مدیریت سرور ها:",$keys);
}
if(preg_match('/^editServer(\D+)(\d+)/',$data,$match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    switch($match[1]){
        case "Name":
            $txt ="اسم";
            break;
        case "Max":
            $txt = "ظرفیت";
            break;
        case "Remark":
            $txt ="ریمارک";
            break;
        case "Flag":
            $txt = "پرچم";
            break;
        default:
            $txt = str_replace("_", " ", $match[1]);
            $end = "برای خالی کردن متن /empty را وارد کنید";
            break;
    }
    delMessage();
    sendMessage("🔘 لطفا " . $txt . " جدید را وارد کنید." . $end,$cancelKey);
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
    sendMessage("مدیریت سرور $cname",$keys);
    exit();
}
if(preg_match('/^editsServer(\D+)(\d+)/',$data,$match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $txt = str_replace("_", " ", $match[1]);
    delMessage();
    sendMessage("🔘 لطفا " . $txt . " جدید را وارد کنید.\nبرای خالی کردن متن /empty را وارد کنید.",$cancelKey);
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
                sendMessage("برای نوع header type فقط none و یا http مجاز است.");
                exit();
            }else $stmt = $connection->prepare("UPDATE `server_config` SET `header_type`=? WHERE `id`=?");
        }
        elseif($match[1] == "request_header") $stmt = $connection->prepare("UPDATE `server_config` SET `request_header`=? WHERE `id`=?");
        elseif($match[1] == "response_header") $stmt = $connection->prepare("UPDATE `server_config` SET `response_header`=? WHERE `id`=?");
        elseif($match[1] == "security"){
            if($text != "tls" && $text != "none" && $text != "xtls"){
                sendMessage("برای نوع security فقط tls یا xtls و یا هم none مجاز است.");
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
    sendMessage("مدیریت سرور $cname",$keys);
    exit();
}
if(preg_match('/^editServer(\D+)(\d+)/',$data,$match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    switch($match[1]){
        case "Name":
            $txt ="اسم";
            break;
        case "Max":
            $txt = "ظرفیت";
            break;
        case "Remark":
            $txt ="ریمارک";
            break;
        case "Flag":
            $txt = "پرچم";
            break;
    }
    delMessage();
    sendMessage("🔘 لطفا " . $txt . " جدید را وارد کنید.",$cancelKey);
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
    sendMessage("مدیریت سرور $cname",$keys);
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
    $msg = "
👤 به بخش مدیریت خوش اومدی $first_name

🚪 /start
";
    if ($walletPanelOutcome === 'canceled') {
        $msg .= "\n⚠️ درخواست تغییر موجودی نیمه‌تمام لغو شد؛ مبلغی از این درخواست اعمال نشد.\n";
    } elseif ($walletPanelCancellation) {
        // Stale/ambiguous database state is NOT proof of cancellation.
        $msg .= "\n⚠️ وضعیت درخواست تغییر موجودی در حال بررسی است؛ ممکن است قبلاً پردازش شده باشد. قبل از اقدام مجدد، موجودی و سوابق تغییرات را بررسی کنید.\n";
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
    editText($message_id, "
⁉️  <b>نرم افزارها و آموزش های لازم جهت اتصال 🔌 به سرویس</b>

🌐 برای اتصال به کمک لینک اشتراک باید از نرم افزارهایی که کانال معرفی شده اند استفاده کنید.

⭕️ لطفا <b>حداقل 2 تا</b> از نرم افزارهای گفته شده را نصب داشته باشید، با توجه به محدودیت هایی که از سمت اپراتورها گذاشته شده و تفاوت تکنولوژی عبور از تحریم نرم افزارها، ممکن است یک کانفیگ روی یک نرم افزار مشکل داشته باشد ولی روی نرم افزار بعدی بدون مشکل و با سرعت بسیار خوب متصل شود.

💡تمامی نرم افزارها <b>رایگان</b> هستند.

📥 برای <b>مشاهد آموزش ها و دانلود نرم افزارها</b> لطفا یکی از دکمه های زیر را انتخاب کنید.

🛒 جهت پشتیبانی یا سوال در مورد خرید به آی دی زیر پیام دهید:

🆔 $supportID
", json_encode(['inline_keyboard'=>$keyboard]), "html");
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
