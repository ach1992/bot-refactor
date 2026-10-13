<?php

require_once 'config.php';
require_once 'buy_helpers.php';
check();

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

// Pre Pay Check By ACh

if (preg_match('/^prePayCheck_([a-zA-Z0-9]+)_([a-zA-Z0-9]+)_([a-zA-Z0-9]+)/', $data, $match)) {
    $actionType = $match[1];
    $payMethod  = strtolower($match[2]);
    $hash_id    = $match[3];

    $stmt = $connection->prepare("SELECT * FROM pays WHERE hash_id = ?");
    $stmt->bind_param("s", $hash_id);
    $stmt->execute();
    $payInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$payInfo) {
        delMessage();
        sendMessage("❌ سفارش یافت نشد یا حذف شده است.", getMainKeys());
        exit;
    }

    $now = time();
    if ($now - $payInfo['request_date'] > 10800) {
		if ($payInfo['state'] === 'pending') {
			$stmt = $connection->prepare("UPDATE pays SET state = 'expired' WHERE id = ?");
			$stmt->bind_param("i", $payInfo['id']);
			$stmt->execute();
			$stmt->close();
		}
    
		delMessage();
		sendMessage("⏳ مهلت پرداخت این سفارش به پایان رسیده است.\n🔄 لطفاً دوباره اقدام کنید.", getMainKeys());
		exit;
	}

    // چک پلن فقط برای تمدید
    if ($payInfo['type'] === 'RENEW_ACCOUNT') {
        $oid = $payInfo['order_id'];
        $savedFid = $payInfo['plan_id'];

        $stmt = $connection->prepare("SELECT fileid FROM orders_list WHERE id = ?");
        $stmt->bind_param("i", $oid);
        $stmt->execute();
        $order = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$order || $order['fileid'] != $savedFid) {
            delMessage();
            sendMessage("❗️ پلن فعلی شما تغییر کرده است.\n♻️ لطفاً دوباره روی دکمه تمدید اشتراک بزنید تا قیمت جدید محاسبه شود.", getMainKeys());
            $stmt = $connection->prepare("UPDATE pays SET state = 'mismatched_plan' WHERE id = ?");
            $stmt->bind_param("i", $payInfo['id']);
            $stmt->execute();
            $stmt->close();
            exit;
        }
    }

    // اگر روش پرداخت ریدایرکتی بود
	if (in_array($payMethod, ["zarinpal", "nowpayment", "nextpay"])) {
		$redirectUrl = $botUrl . "pay/?$payMethod&hash_id=$hash_id";

		// دکمه با لینک مستقیم به درگاه
		$button = [
			'inline_keyboard' => [
				[['text' => "🔗 رفتن به درگاه پرداخت", 'url' => $redirectUrl]]
			]
		];

		answerCallbackQuery($callbackId, "در حال آماده‌سازی لینک پرداخت...");
		sendMessage("⚠️ دقت کنید هنگام پرداخت کارمزد را در نظر بگیرید که از مقدار پرداختی شما کسر نشود.
✅ بعد پرداخت باید صفحه را باز نگه دارید تا تایید نهایی شود و بعد به ربات منتقل شوید.
🔰 حتما PaymentID یا شناسه پرداخت خودتان در صفحه باز شده را یادداشت کنید.
💳 برای ادامه پرداخت روی دکمه زیر بزنید.
⏳ اعتبار این لینک 30 دقیقه می باشد.", json_encode($button), "html", $from_id);
		exit;
	}

    // بازنویسی $data برای هدایت به هندلر اصلی
    $map = [
        "wallet"      => "pay{$actionType}WithWallet$hash_id",
        "carttocart"  => "pay{$actionType}WithCartToCart$hash_id",
        "tronwallet"  => "payWithTronWallet$hash_id",
        "weswap"      => "payWithWeSwap$hash_id"
    ];

    if (!isset($map[$payMethod])) {
        delMessage();
        sendMessage("⛔️ روش پرداخت ناشناخته است.", getMainKeys());
        exit;
    }

    $data = $map[$payMethod];
}

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

if (preg_match('/^\/([Ss]tart)/', $text) || $text == $buttonValues['back_to_main'] || $data == 'mainMenu') {

    setUser();
    setUser("", "temp");

    $isRealAdmin  = ($from_id == $admin || (!empty($userInfo['isAdmin']) && $userInfo['isAdmin'] == true));
    $isFirstStart = ($from_id != $admin && empty($userInfo['first_start']));
    $canBuy       = (($botState['sellState'] ?? 'off') == "on" || $isRealAdmin);

    if (isset($data) && $data == "mainMenu") {

        $res = editText($message_id, $mainValues['start_message'], getMainKeys());
        if (!$res->ok) {
            sendMessage($mainValues['start_message'], getMainKeys());
        }

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
if(($data=="botSettings" or preg_match("/^changeBot(\w+)/",$data,$match)) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if($data!="botSettings"){
        if($match[1] == "cartToCartAutoAcceptType") $newValue = $botState[$match[1]] == "0"?"1":($botState[$match[1]] == "1"?"2":0);
        else $newValue = $botState[$match[1]]=="on"?"off":"on";
        setSettings($match[1], $newValue);
    }
    editText($message_id,$mainValues['change_bot_settings_message'],getBotSettingKeys());
}
if($data=="changeUpdateConfigLinkState" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $newValue = $botState['updateConnectionState']=="robot"?"site":"robot";
    setSettings('updateConnectionState', $newValue);
    editText($message_id,$mainValues['change_bot_settings_message'],getBotSettingKeys());
}
if(($data=="gateWays_Channels" or preg_match("/^changeGateWays(\w+)/",$data,$match)) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if($data!="gateWays_Channels"){
        $newValue = $botState[$match[1]]=="on"?"off":"on";
        setSettings($match[1], $newValue);
    }
    editText($message_id,$mainValues['change_bot_settings_message'],getGateWaysKeys());
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
if(preg_match('/^changePaymentKeys(\w+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
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
        case "tronwallet":
            $gate = "آدرس والت ترون";
            break;
    }
    sendMessage("🔘 لطفا $gate را وارد کنید", $cancelKey);
    setUser($data);
}
if(preg_match('/^changePaymentKeys(\w+)/',$userInfo['step'],$match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){

    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'PAYMENT_KEYS'");
    $stmt->execute();
    $paymentInfo = $stmt->get_result();
    $stmt->close();
    $paymentKeys = json_decode($paymentInfo->fetch_assoc()['value'],true)??array();
    $paymentKeys[$match[1]] = $text;
    $paymentKeys = json_encode($paymentKeys);
    
    if($paymentInfo->num_rows > 0) $stmt = $connection->prepare("UPDATE `setting` SET `value` = ? WHERE `type` = 'PAYMENT_KEYS'");
    else $stmt = $connection->prepare("INSERT INTO `setting` (`type`, `value`) VALUES ('PAYMENT_KEYS', ?)");
    $stmt->bind_param("s", $paymentKeys);
    $stmt->execute(); 
    $stmt->close();
    

    sendMessage($mainValues['saved_successfuly'],$removeKeyboard);
    sendMessage($mainValues['change_bot_settings_message'],getGateWaysKeys());
    setUser();
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
if($data=="transferMyWallet"){
    if($userInfo['wallet'] > 0 ){
        delMessage();
        sendMessage("🔀 در صورتی که قصد انتقال مبلغ دلخواه از موجودی خودتان به کاربری دیگری را دارید از اینجا می توانید انجام دهید.
🔢 لطفا شناسه عددی کاربری که می خواهید موجودی به حساب آن منتقل کنید را وارد کنید.",$cancelKey);
        setUser($data);
    }else alert("⛔️ موجودی حساب شما کم است. موجودی حساب شما باید بیشتر از 0 باشد.");
}
if($userInfo['step'] =="transferMyWallet" && $text != $buttonValues['cancel']){
    if(is_numeric($text)){
        if($text != $from_id){
            $stmt= $connection->prepare("SELECT * FROM `users` WHERE `userid` = ?");
            $stmt->bind_param("i", $text);
            $stmt->execute();
            $checkExist = $stmt->get_result();
            $stmt->close();
            
            if($checkExist->num_rows > 0){
                setUser("tranfserUserAmount" . $text);
                sendMessage("لطفا مبلغ مورد نظر را به تومان و با اعداد انگلیسی وارد کنید.");
            }else sendMessage("⛔️ کاربری با این شناسه عددی یافت نشد.");
        }else sendMessage("⚠️ لطفا شناسه عددی کاربری غیر از خودتان را ارسال کنید.");
    }else sendMessage($mainValues['send_only_number']);
}
if (preg_match('/^tranfserUserAmount(\d+)/', $userInfo['step'], $match) && $text != $buttonValues['cancel']) {

    if (!is_numeric($text)) {
        sendMessage($mainValues['send_only_number']);
        exit;
    }

    $amount = (int)$text;
    $to_id  = (int)$match[1];

    if ($amount <= 0) {
        sendMessage("لطفا عددی بزرگتر از صفر وارد کنید.");
        exit;
    }

    if ($to_id === (int)$from_id) {
        sendMessage("⚠️ لطفا شناسه عددی کاربری غیر از خودتان را ارسال کنید.");
        exit;
    }

    $connection->begin_transaction();

    try {
        // مبدأ (قبل) - قفل رکورد
        $stmt = $connection->prepare("SELECT `userid`,`name`,`username`,`wallet` FROM `users` WHERE `userid` = ? FOR UPDATE");
        $stmt->bind_param("i", $from_id);
        $stmt->execute();
        $sender = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        // مقصد (قبل) - قفل رکورد
        $stmt = $connection->prepare("SELECT `userid`,`name`,`username`,`wallet` FROM `users` WHERE `userid` = ? FOR UPDATE");
        $stmt->bind_param("i", $to_id);
        $stmt->execute();
        $receiver = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$sender) {
            throw new Exception("Sender not found");
        }
        if (!$receiver) {
            $connection->rollback();
            sendMessage("⛔️ کاربری با این شناسه عددی یافت نشد.");
            exit;
        }

        $sender_before   = (int)$sender['wallet'];
        $receiver_before = (int)$receiver['wallet'];

        if ($sender_before < $amount) {
            $connection->rollback();
            sendMessage("موجودی حساب شما کم است.");
            exit;
        }

        // انجام انتقال
        $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` + ? WHERE `userid` = ?");
        $stmt->bind_param("ii", $amount, $to_id);
        $stmt->execute();
        $stmt->close();

        $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` - ? WHERE `userid` = ?");
        $stmt->bind_param("ii", $amount, $from_id);
        $stmt->execute();
        $stmt->close();

        // موجودی بعد از انتقال (به دلیل قفل، قابل اطمینانه)
        $stmt = $connection->prepare("SELECT `wallet` FROM `users` WHERE `userid` = ?");
        $stmt->bind_param("i", $from_id);
        $stmt->execute();
        $sender_after = (int)($stmt->get_result()->fetch_assoc()['wallet'] ?? 0);
        $stmt->close();

        $stmt = $connection->prepare("SELECT `wallet` FROM `users` WHERE `userid` = ?");
        $stmt->bind_param("i", $to_id);
        $stmt->execute();
        $receiver_after = (int)($stmt->get_result()->fetch_assoc()['wallet'] ?? 0);
        $stmt->close();

        $connection->commit();

        // ساخت نام و یوزرنیم
        $sender_name = !empty($sender['name']) ? $sender['name'] : '—';
        $receiver_name = !empty($receiver['name']) ? $receiver['name'] : '—';

        $sender_username = !empty($sender['username']) ? '@' . $sender['username'] : '—';
        $receiver_username = !empty($receiver['username']) ? '@' . $receiver['username'] : '—';

        // پیام برای مقصد
        sendMessage(
            "✅ مبلغ " . number_format($amount) . " تومان به کیف پول شما توسط کاربر $from_id انتقال یافت.",
            null,
            null,
            $to_id
        );

        // گزارش برای ادمین/کانال
        $channelMessage =
            "🔀 <b>گزارش جابجایی موجودی</b>\n"
            . "━━━━━━━━━━━━\n"
            . "🎫 <b>مبدأ</b>: <code>$from_id</code>\n"
            . "🧾 نام: <b>" . htmlspecialchars($sender_name) . "</b>\n"
            . "🔰 نام کاربری: <b>" . htmlspecialchars($sender_username) . "</b>\n"
            . "💼 قبل: <b>" . number_format($sender_before) . "</b>\n"
            . "💼 بعد: <b>" . number_format($sender_after) . "</b>\n"
            . "━━━━━━━━━━━━\n"
            . "🎫 <b>مقصد</b>: <code>$to_id</code>\n"
            . "🧾 نام: <b>" . htmlspecialchars($receiver_name) . "</b>\n"
            . "🔰 نام کاربری: <b>" . htmlspecialchars($receiver_username) . "</b>\n"
            . "💼 قبل: <b>" . number_format($receiver_before) . "</b>\n"
            . "💼 بعد: <b>" . number_format($receiver_after) . "</b>\n"
            . "━━━━━━━━━━━━\n"
            . "💰 مبلغ انتقال: <b>" . number_format($amount) . "</b> تومان";

        // ارسال به ادمین
        sendMessage($channelMessage, null, "html", $admin);

        // ارسال به کانال گزارشات (در صورت تنظیم)
        $reportChannel = $botState['rewardChannel'] ?? null;
        if (!empty($reportChannel)) {
            sendMessage($channelMessage, null, "html", $reportChannel);
        }

        // پیام برای مبدأ
        setUser();
        sendMessage("✅ مبلغ " . number_format($amount) . " تومان به کیف پول کاربر مورد نظر شما انتقال یافت.", $removeKeyboard);
        sendMessage("لطفا یکی از کلید های زیر را انتخاب کنید.", getMainKeys());

    } catch (Exception $e) {
        $connection->rollback();
        sendMessage("❌ خطایی رخ داد. لطفاً دوباره تلاش کنید.");
    }
}

if($data == "increaseMyWallet") { 
    delMessage(); 

    $textMsg = 
"✅ با شارژ کیف پول می‌توانید هنگام خرید از <b>دکمه پرداخت با موجودی</b> استفاده کنید و منتظر تأیید ادمین <b>نمانید</b>.

❇️ لطفاً مبلغ شارژ کیف پول را انتخاب کنید:";

    $keyboard = [
        [
            ['text' => '100,000 تومان',  'callback_data' => 'increaseWallet_100000'],
            ['text' => '200,000 تومان', 'callback_data' => 'increaseWallet_200000'],
        ],
        [
            ['text' => '300,000 تومان', 'callback_data' => 'increaseWallet_300000'],
            ['text' => '500,000 تومان', 'callback_data' => 'increaseWallet_500000'],
        ],
        [
            ['text' => '1,000,000 تومان',  'callback_data' => 'increaseWallet_1000000'],
            ['text' => '2,000,000 تومان',  'callback_data' => 'increaseWallet_2000000'],
        ],
        [
            ['text' => '5,000,000 تومان',  'callback_data' => 'increaseWallet_5000000'],
        ],
        [
            ['text' => '✏️ مبلغ دلخواه', 'callback_data' => 'increaseWalletCustom'],
        ],
        [
            ['text' => $buttonValues['cancel'], 'callback_data' => 'mainMenu'],
        ],
    ];

    $keys = json_encode(['inline_keyboard' => $keyboard], JSON_UNESCAPED_UNICODE);

    sendMessage($textMsg, $keys, 'HTML');
}

if(strpos($data, "increaseWallet_") === 0){ 
    delMessage();

    $amount = (int)str_replace("increaseWallet_", "", $data);

    if($amount > 10000000){
        sendMessage("⚠️ حداکثر مبلغ قابل شارژ کیف پول 10,000,000 تومان می‌باشد.");
        exit();
    }

    startWalletPayment($connection, $botState, $buttonValues, $botUrl, $from_id, $removeKeyboard, $amount);
    setUser();
}

if($data == "increaseWalletCustom"){ 
    delMessage();

    sendMessage("💰 لطفاً مبلغ شارژ را وارد کنید.
✅ حداقل مبلغ قابل شارژ <b>100,000 تومان</b> می باشد.
✅ حداکثر مبلغ قابل شارژ <b>10,000,000 تومان</b> می باشد.
✅ مبلغ باید فقط مضربی از <b>50,000 تومان</b> باشد.
🔢 لطفاً فقط اعداد انگلیسی وارد کنید.", $cancelKey, 'HTML');

    setUser("increaseMyWallet");
}

if($userInfo['step'] == "increaseMyWallet" && $text != $buttonValues['cancel']){ 
    
    if(!is_numeric($text)){ 
        sendMessage($mainValues['send_only_number']); 
        exit(); 
    }

    $amount = (int)$text;

    if($amount < 100000){ 
        sendMessage("⚠️ لطفا مقداری بیشتر یا مساوی 100,000 تومان وارد کنید."); 
        exit(); 
    }

    if($amount > 10000000){
        sendMessage("⚠️ حداکثر مبلغ قابل شارژ کیف پول 10,000,000 تومان می‌باشد.");
        exit();
    }

    if($amount % 50000 != 0){
        sendMessage("⚠️ مبلغ واردشده باید مضربی از 50,000 تومان باشد.");
        exit();
    }

    startWalletPayment($connection, $botState, $buttonValues, $botUrl, $from_id, $removeKeyboard, $amount);

    setUser();
}

if (preg_match('/payIncreaseWalletWithCartToCart(?<hashId>.*)/', $data, $match)) {

    $payInfo = dbFetchPayByHash($connection, $match['hashId']);
    if (!$payInfo) {
        prepareStartFallback();
        delMessage();
        goto GOTOSTART;
    }

    delMessage();
    setUser("payIncreaseWalletWithCartToCart{$match['hashId']}");

	// $msg = buildCartToCartInfoMessage($paymentKeys, $mainValues, (int)$payInfo['price']);
	$msg = buildCartToCartInfoMessage($paymentKeys, $mainValues, (int)$payInfo['price'], (int)$payInfo['user_id']);
    $keyboard = kbSendReceiptAndCancel("sendIncreaseWalletReceipt", "cancelIncreaseWalletReceipt", $match['hashId']);

    sendMessage($msg, $keyboard, "HTML");
    exit;
}

if (preg_match('/sendIncreaseWalletReceipt(?<hashId>.*)/', $data, $match)) {

    $payInfo = dbFetchPayByHash($connection, $match['hashId']);
    if (!$payInfo) {
        prepareStartFallback();
        delMessage();
        goto GOTOSTART;
    }

    $guardMsg = duplicateReceiptGuard($connection, $payInfo, time());
    if ($guardMsg) { alert($guardMsg, true); exit; }

    delMessage();
    setUser("waitIncreaseWalletReceipt{$match['hashId']}");

    $keyboard = kbOnlyCancel("cancelIncreaseWalletReceipt", $match['hashId']);
    sendMessage($mainValues['please_send_receipt_image'], $keyboard, "HTML");
    exit;
}

if (preg_match('/cancelIncreaseWalletReceipt(?<hashId>.*)/', $data, $match)) {
    handleCancelFlow($mainValues, $removeKeyboard);
}

if (preg_match('/waitIncreaseWalletReceipt(.*)/', $userInfo['step'], $match)) {

    $fileid = extractPhotoFileId($update);
    if (!$fileid) {
        $keyboard = kbOnlyCancel("cancelIncreaseWalletReceipt", $match[1]);
        sendMessage($mainValues['please_send_only_image'], $keyboard, "HTML");
        exit;
    }

    setUser();
    $uid = $userInfo['userid'];
    $name = $userInfo['name'];
    $username = $userInfo['username'];

    $payInfo = dbFetchPayByHash($connection, $match[1]);
    if (!$payInfo) {
        prepareStartFallback();
        delMessage();
        goto GOTOSTART;
    }

	// $price = (int)$payInfo['price'];
	$rawPrice = (int)$payInfo['price'];
	$price = number_format($rawPrice);

    sendWithStartButton($mainValues['order_submitted'], $buttonValues['start_bot'], $botUsername, 'mainMenu', 'HTML');

    $msg = str_replace(
        ['PRICE', 'USERNAME', 'NAME', 'USER-ID'],
        [$price, $username, $name, $from_id],
        $mainValues['increase_wallet_request_message']
    );

    $adminKeyboard = buildAdminKeyboardWithStats(
        $connection,
        $buttonValues,
        "approvePayment{$match[1]}",
        "declineAction_{$match[1]}",
        $match[1],
        (int)$payInfo['user_id']
    );

    sendReceiptToAdminsAndUpdatePay($connection, $fileid, $msg, $adminKeyboard, $admin, $match[1]);
    exit;
}

if (preg_match('/^approvePayment(.*)/', $data, $match) && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $hash_id = $match[1];
    // دریافت اطلاعات پرداخت
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $payInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $price = $payInfo['price'];
    $userId = $payInfo['user_id'];

    if (!$payInfo || ($payInfo['state'] === 'expired_have_sent')) {
        alert(!$payInfo ? "⛔️ اطلاعات پرداخت پیدا نشد." : "⛔️ این درخواست منقضی شده است.");
        return;
    }
    if ($payInfo['state'] === 'approved' || $payInfo['state'] === 'declined') {
        alert("⛔️ این درخواست قبلاً بررسی شده است.");
        return;
    }

    // دریافت موجودی فعلی کیف پول
    $stmt = $connection->prepare("SELECT `wallet` FROM `users` WHERE `userid` = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $currentWallet = $stmt->get_result()->fetch_assoc()['wallet'] ?? 0;
    $stmt->close();

    // ثبت وضعیت تایید و ادمین تاییدکننده
    $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'approved', `approved_by` = ? WHERE `hash_id` = ?");
    $stmt->bind_param("is", $from_id, $match[1]);
    $stmt->execute();
    $stmt->close();

    // افزایش موجودی کیف پول
    $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` + ? WHERE `userid` = ?");
    $stmt->bind_param("ii", $price, $userId);
    $stmt->execute();
    $stmt->close();

    // محاسبه موجودی جدید
    $newWallet = $currentWallet + $price;

    $keys = json_encode([
        'inline_keyboard' => [
            [['text' => "✅ تایید شد توسط $from_id", 'callback_data' => "wizwizch"]],
            [['text' => "🆔 $hash_id", 'callback_data' => "noop_$hash_id"]]
        ]
    ], 488);

    // ویرایش پیام ادمین اصلی
    editKeys($keys, $payInfo['message_id'], $payInfo['chat_id']);

    // ویرایش پیام سایر ادمین‌ها
    $adminMessages = json_decode($payInfo['admins_messages'], true);
    if (is_array($adminMessages)) {
        foreach ($adminMessages as $msgInfo) {
            if (
                isset($msgInfo['chat_id'], $msgInfo['message_id']) &&
                $msgInfo['chat_id'] != $payInfo['chat_id']
            ) {
                editKeys($keys, $msgInfo['message_id'], $msgInfo['chat_id']);
            }
        }
    }

    // ارسال پیام به ادمین‌ها
    $adminMessages = "💳 افزایش موجودی کاربر $userId با موفقیت انجام شد.

💰 موجودی قدیم: " . number_format($currentWallet) . " تومان
💵 موجودی جدید: " . number_format($newWallet) . " تومان
🔓 شناسه پرداخت: $hash_id";
    sendToAllAdmins('sendMessage', ['text' => $adminMessages, 'parse_mode' => "html"]);
    
    // ارسال پیام به ادمین خاص
    sendMessage($adminMessages, null, "html", $admin);

    // ارسال پیام به کاربر
    sendMessage("✅ افزایش حساب شما با موفقیت تایید شد.\n💰 مبلغ <b>" . number_format($price) . " تومان</b> به کیف پول شما اضافه شد.\n\n<blockquote>
⚠️ لطفا دقت کنید شما فقط کیف پول خودتان را شارژ کرده‌اید، برای خرید یا تمدید باید از دکمه های زیر استفاده کنید:
🛒 <b>خرید اشتراک جدید</b>
یا <b>🔄 تمدید یا مشاهده اشتراک ها</b>
و در مرحله پرداخت روی دکمه زیر بزنید:
💰 <b>پرداخت با موجودی</b>
</blockquote>
📨 پشتیبانی:
🆔 $supportID", null, "HTML", $userId);
}

// Decline Cart To Cart by ACh

if (preg_match('/^noop_(.+)$/', $data, $m)) {
	$hash_id = $m[1];
	sendMessage("🆔 Hash ID: <code>$hash_id</code>", null, "HTML", $from_id);
	exit;
}

if (preg_match('/^declineAction_(.+)$/', $data, $match) && ($from_id == $admin || !empty($userInfo['isAdmin']))) {
    $hash_id = $match[1];

    // بررسی وجود و وضعیت پرداخت
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ?");
    $stmt->bind_param("s", $hash_id);
    $stmt->execute();
    $payInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();

	if (!$payInfo || ($payInfo['state'] === 'expired_have_sent')) {
		alert(!$payInfo ? "⛔️ اطلاعات پرداخت پیدا نشد." : "⛔️ این درخواست منقضی شده است.");
		return;
	}

    if ($payInfo['state'] === 'approved' || $payInfo['state'] === 'declined') {
        alert("⛔️ این درخواست قبلاً بررسی شده است.");
        return;
    }

    setUser("declineActionReason_" . $hash_id . "_" . $message_id);
    sendMessage("📝 لطفاً دلیل رد این درخواست را با جزئیات بنویسید تا به کاربر اطلاع داده شود:", $cancelKey);
}

if (preg_match('/^declineActionReason_(.+)_(\d+)$/', $userInfo['step'], $match) && $text != $buttonValues['cancel']) {
    setUser();
    $hash_id = $match[1];
    $message_id = $match[2];

    // دریافت اطلاعات پرداخت
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ?");
    $stmt->bind_param("s", $hash_id);
    $stmt->execute();
    $payInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
	
	if (!$payInfo || ($payInfo['state'] === 'expired_have_sent')) {
		alert(!$payInfo ? "⛔️ اطلاعات پرداخت پیدا نشد." : "⛔️ این درخواست منقضی شده است.");
		return;
	}

    if ($payInfo['state'] === 'approved' || $payInfo['state'] === 'declined') {
		alert("⛔️ این درخواست قبلاً بررسی شده است.");
		return;
	}

    $uid = $payInfo['user_id'];
    $price = !empty($payInfo['price']) ? number_format($payInfo['price']) . " تومان" : "---";
    $reason = $text;
	$remark = "---";
	
	// تلاش برای گرفتن ریمارک دقیق
	if (!empty($payInfo['order_id'])) {
		$stmt = $connection->prepare("SELECT `remark` FROM `orders_list` WHERE `id` = ?");
		$stmt->bind_param("i", $payInfo['order_id']);
		$stmt->execute();
		$stmt->bind_result($remarkFromOrder);
		if ($stmt->fetch()) {
			$remark = $remarkFromOrder ?: "---";
		}
		$stmt->close();
	} elseif (!empty($payInfo['description'])) {
		$remark = $payInfo['description'];
	}

    // ثبت در دیتابیس
    $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'declined', `approved_by` = ? WHERE `hash_id` = ?");
    $stmt->bind_param("is", $from_id, $hash_id);
    $stmt->execute();
    $stmt->close();
	
    $keys = json_encode(['inline_keyboard' => [
        [['text' => "❌ رد شد توسط $from_id", 'callback_data' => "wizwizch"]],
		[['text' => "🆔 $hash_id", 'callback_data' => "noop_$hash_id"]]
    ]]);

    // ویرایش دکمه ادمین اصلی
    editKeys($keys, $payInfo['message_id'], $payInfo['chat_id']);

    // ویرایش دکمه سایر ادمین ها
    $adminMessages = json_decode($payInfo['admins_messages'], true);
    if (is_array($adminMessages)) {
        foreach ($adminMessages as $msgInfo) {
            if (
                isset($msgInfo['chat_id'], $msgInfo['message_id']) &&
                $msgInfo['chat_id'] != $payInfo['chat_id']
            ) {
                editKeys($keys, $msgInfo['message_id'], $msgInfo['chat_id']);
            }
        }
    }

	// ارسال پیام برای کاربر
	$retryButton = json_encode([
		'inline_keyboard' => [
			[
				[
					'text' => "🔄 درخواست دوباره",
					'url'  => "https://t.me/$botUsername?start=restart_$hash_id"
				]
			]
		]
	]);
    $message = "❌ درخواست شما به دلایل زیر رد شد:\n\n<blockquote>$reason</blockquote>\n\n⚠️ اطلاعات درخواست:\n";
    $message .= "💰 مبلغ: <b>$price</b>\n";
    $message .= "♾️ نام اشتراک: <code>$remark</code>\n";
	$message .= "💳 شناسه پرداخت: <code>$hash_id</code>";
    sendMessage($message, $retryButton, "HTML", $uid);

	$adminAfterSendKeys = json_encode([
		'inline_keyboard' => [
			[
				['text' => "🚫 مسدود کردن کاربر", 'callback_data' => "banUserDirect_$uid"]
			]
		]
	]);

	sendMessage(
		"📩 پیام شما برای کاربر <code>$uid</code> ارسال شد.\n\n⚠️ جزئیات درخواست کاربر:\n💰 مبلغ: <b>$price</b>\n♾️ نام اشتراک: <code>$remark</code>\n💳 شناسه پرداخت: <code>$hash_id</code>",
		$adminAfterSendKeys,
		"html"
	);
}

if (preg_match('/^banUserDirect_(\d+)$/', $data, $m) && ($from_id == $admin || $userInfo['isAdmin'] == true)) {

    $targetUserId = (int)$m[1];
	
    // delMessage();

    $stmt = $connection->prepare("SELECT `step` FROM `users` WHERE `userid` = ?");
    $stmt->bind_param("i", $targetUserId);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    if ($res->num_rows > 0) {
        $u = $res->fetch_assoc();

        if ($u['step'] != "banned") {
            $stmt = $connection->prepare("UPDATE `users` SET `step` = 'banned' WHERE `userid` = ?");
            $stmt->bind_param("i", $targetUserId);
            $stmt->execute();
            $stmt->close();

            alert("✅ کاربر $targetUserId مسدود شد.");
        } else {
            alert("☑️ این کاربر از قبل مسدود بود.");
        }
    } else {
        alert("⛔️ کاربری با این آیدی یافت نشد.");
    }

    $doneKeys = json_encode([
        'inline_keyboard' => [
            [
                ['text' => "✅ مسدود شد: $targetUserId", 'callback_data' => "wizwizch"]
            ]
        ]
    ]);
    editKeys($doneKeys, $message_id, $chat_id);

}

// END Decline Cart To Cart by ACh

if($data=="increaseUserWallet" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage($mainValues['send_user_id'],$cancelKey);
    setUser($data);
}
if($userInfo['step'] == "increaseUserWallet" && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    if(is_numeric($text)){
        $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid` = ?");
        $stmt->bind_param("i", $text);
        $stmt->execute();
        $userCount = $stmt->get_result()->num_rows;
        $stmt->close();
        if($userCount > 0){
            setUser("increaseWalletUser" . $text);
            sendMessage($mainValues['enter_increase_amount']);
        }
        else{
            setUser();
            sendMessage($mainValues['user_not_found'], $removeKeyboard);
            sendMessage($mainValues['reached_main_menu'],getMainKeys());
        }
    }else{
        sendMessage($mainValues['send_only_number']);
    }
}
if(preg_match('/^increaseWalletUser(\d+)/',$userInfo['step'], $match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(is_numeric($text)){
        $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` + ? WHERE `userid` = ?");
        $stmt->bind_param("ii", $text, $match[1]);
        $stmt->execute();
        $stmt->close();
    
        sendMessage("✅ مبلغ " . number_format($text). " تومان به حساب شما اضافه شد.",null,null,$match[1]);
        sendMessage("✅ مبلغ " . number_format($text) . " تومان به کیف پول کاربر مورد نظر اضافه شد.",$removeKeyboard);
        sendMessage($mainValues['reached_main_menu'],getMainKeys());
        setUser();
    }else{
        sendMessage($mainValues['send_only_number']);
    }
}
if($data=="decreaseUserWallet" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage($mainValues['send_user_id'],$cancelKey);
    setUser($data);
}
if($userInfo['step'] == "decreaseUserWallet" && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    if(is_numeric($text)){
        $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid` = ?");
        $stmt->bind_param("i", $text);
        $stmt->execute();
        $userCount = $stmt->get_result()->num_rows;
        $stmt->close();
        if($userCount > 0){
            setUser("decreaseWalletUser" . $text);
            sendMessage($mainValues['enter_decrease_amount']);
        }
        else{
            setUser();
            sendMessage($mainValues['user_not_found'], $removeKeyboard);
            sendMessage($mainValues['reached_main_menu'],getMainKeys());
        }
    }else{
        sendMessage($mainValues['send_only_number']);
    }
}
if(preg_match('/^decreaseWalletUser(\d+)/',$userInfo['step'], $match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(is_numeric($text)){
        $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` - ? WHERE `userid` = ?");
        $stmt->bind_param("ii", $text, $match[1]);
        $stmt->execute();
        $stmt->close();
    
        sendMessage(str_replace("AMOUNT", number_format($text), $mainValues['amount_decreased_from_your_wallet']),null,null,$match[1]);
        sendMessage(str_replace("AMOUNT", number_format($text), $mainValues['amount_decreased_from_user_wallet']),$removeKeyboard);
        sendMessage($mainValues['reached_main_menu'],getMainKeys());
        setUser();
    }else{
        sendMessage($mainValues['send_only_number']);
    }
}
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

            if ($botState['cartToCartState'] == "off" && $botState['walletState'] == "off") {
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
if(preg_match('/payWithTronWallet(.*)/',$data,$match)) {
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $payInfo = $stmt->get_result();
    $stmt->close();

    if($payInfo->num_rows == 0){
        $text = "/start";
        $data = "";
        delMessage();
        goto GOTOSTART;
    }
    $payInfo = $payInfo->fetch_assoc();

    $fid = $payInfo['plan_id'];
    $type = $payInfo['type'];
    
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
    $stmt->bind_param("i", $fid);
    $stmt->execute();
    $file_detail = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $server_id = $file_detail['server_id'];
    $acount = $file_detail['acount'];
    $inbound_id = $file_detail['inbound_id'];

    if($type != "INCREASE_WALLET" && $type != "RENEW_ACCOUNT"){
        if($acount <= 0 and $inbound_id != 0){
            alert($mainValues['out_of_connection_capacity']);
            exit;
        }
        if($inbound_id == 0) {
            $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
            $stmt->bind_param("i", $server_id);
            $stmt->execute();
            $server_info = $stmt->get_result()->fetch_assoc();
            $stmt->close();
    
            if($server_info['ucount'] <= 0) {
                alert($mainValues['out_of_server_capacity']);
                exit; 
            }
        }else{
            if($acount <= 0){
                alert($mainValues['out_of_server_capacity']);
                exit();
            }
        }
    }
    
    if($type == "RENEW_ACCOUNT"){
        $oid = $payInfo['order_id'];
        
        $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
        $stmt->bind_param("i", $oid);
        $stmt->execute();
        $order = $stmt->get_result();
        $stmt->close();
        if($order->num_rows == 0){
            delMessage();
            sendMessage($mainValues['config_not_found'], getMainKeys());
            exit();
        }

    }
    
    delMessage();
    
    $price = $payInfo['price'];
    $priceInTrx = round($price / $botState['TRXRate'],2);
    
    $stmt = $connection->prepare("UPDATE `pays` SET `tron_price` = ? WHERE `hash_id` = ?");
    $stmt->bind_param("ds", $priceInTrx, $match[1]);
    $stmt->execute();
    $stmt->close();
    
    sendMessage(str_replace(["AMOUNT", "TRON-WALLET"], [$priceInTrx, $paymentKeys['tronwallet']], $mainValues['pay_with_tron_wallet']), $cancelKey, "html");
    setUser($data);
}
if(preg_match('/^payWithTronWallet(.*)/',$userInfo['step'], $match) && $text != $buttonValues['cancel']){
    if(!preg_match('/^[0-9a-f]{64}$/i',$text)){
        sendMessage($mainValues['incorrect_tax_id']);
        exit(); 
    }else{
        $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `payid` = ?");
        $stmt->bind_param("s", $text);
        $stmt->execute();
        $checkExist = $stmt->get_result();
        $stmt->close();
        
        if($checkExist->num_rows == 0){
            $stmt = $connection->prepare("UPDATE `pays` SET `payid` = ?, `state` = '0' WHERE `hash_id` = ?");
            $stmt->bind_param("ss", $text, $match[1]);
            $stmt->execute();
            $stmt->close();
            
            sendMessage($mainValues['in_review_tax_id'], $removeKeyboard);
            setUser();
            sendMessage($mainValues['reached_main_menu'],getMainKeys());
        }else sendMessage($mainValues['used_tax_id']);
    }

}
if(preg_match('/payWithWeSwap(.*)/',$data,$match)) {
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $payInfo = $stmt->get_result();
    $stmt->close();

    if($payInfo->num_rows == 0){
        $text = "/start";
        $data = "";
        delMessage();
        goto GOTOSTART;
    }
    $payInfo = $payInfo->fetch_assoc();

    $fid = $payInfo['plan_id'];
    $type = $payInfo['type'];
    
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
    $stmt->bind_param("i", $fid);
    $stmt->execute();
    $file_detail = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $server_id = $file_detail['server_id'];
    $acount = $file_detail['acount'];
    $inbound_id = $file_detail['inbound_id'];

    if($type != "INCREASE_WALLET" && $type != "RENEW_ACCOUNT"){
        if($acount <= 0 and $inbound_id != 0){
            alert($mainValues['out_of_connection_capacity']);
            exit;
        }
        if($inbound_id == 0) {
            $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
            $stmt->bind_param("i", $server_id);
            $stmt->execute();
            $server_info = $stmt->get_result()->fetch_assoc();
            $stmt->close();
    
            if($server_info['ucount'] <= 0) {
                alert($mainValues['out_of_server_capacity']);
                exit; 
            }
        }else{
            if($acount <= 0){
                alert($mainValues['out_of_server_capacity']);
                exit();
            }
        }
    }
    
    if($type == "RENEW_ACCOUNT"){
        $oid = $payInfo['order_id'];
        
        $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
        $stmt->bind_param("i", $oid);
        $stmt->execute();
        $order = $stmt->get_result();
        $stmt->close();
        if($order->num_rows == 0){
            delMessage();
            sendMessage($mainValues['config_not_found'], getMainKeys());
            exit();
        }

    }
    
    delMessage();
    sendMessage($mainValues['please_wait_message'],$removeKeyboard);
    
    
    $price = $payInfo['price'];
    $priceInUSD = round($price / $botState['USDRate'],2);
    $priceInTrx = round($price / $botState['TRXRate'],2);
    $pay = NOWPayments('POST', 'payment', [
        'price_amount' => $priceInUSD,
        'price_currency' => 'usd',
        'pay_currency' => 'trx'
    ]);
    if(isset($pay->pay_address)){
        $payAddress = $pay->pay_address;
        
        $payId = $pay->payment_id;
        
        $stmt = $connection->prepare("UPDATE `pays` SET `payid` = ? WHERE `hash_id` = ?");
        $stmt->bind_param("is", $payId, $match[1]);
        $stmt->execute();
        $stmt->close();
        
        $keys = json_encode(['inline_keyboard'=>[
            [['text'=>"پرداخت با درگاه ارزی ریالی",'url'=>"https://changeto.technology/quick?amount=$priceInTrx&currency=TRX&address=$payAddress"]],
            [['text'=>"پرداخت کردم ✅",'callback_data'=>"havePaiedWeSwap" . $match[1]]]
            ]]);
sendMessage("
✅ لینک پرداخت با موفقیت ایجاد شد

💰 مبلغ: " . $priceInTrx . " ترون

✔️ بعد از پرداخت حدود 1 الی 15 دقیقه صبر کنید تا پرداخت به صورت کامل انجام شود سپس روی پرداخت کردم کلیک کنید.
",$keys);
    }else{
        if($pay->statusCode == 400){
            sendMessage("مقدار انتخاب شده کمتر از حد مجاز است.");
        }else{
            sendMessage("مشکلی رخ داده است، لطفا به پشتیبانی اطلاع بدهید.");
        }
        sendMessage("لطفا یکی از کلید های زیر را انتخاب کنید.",getMainKeys());
    }
}

if (preg_match('/havePaiedWeSwap(.*)/', $data, $match)) {

    $hash_id = trim($match[1]);

    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ?");
    $stmt->bind_param("s", $hash_id);
    $stmt->execute();
    $payRes = $stmt->get_result();
    $stmt->close();

    if ($payRes->num_rows == 0) {
        $text = "/start";
        $data = "";
        delMessage();
        goto GOTOSTART;
    }

    $payInfo = $payRes->fetch_assoc();

    if ($payInfo['state'] != "pending") {
        alert("این لینک پرداخت منقضی شده است.");
        return;
    }

    $payid   = $payInfo['payid'];
    $payType = $payInfo['type'];
    $price   = (int)$payInfo['price'];

    $request_json = NOWPayments('GET', 'payment', $payid);

    if (
        $request_json->payment_status == 'finished' ||
        $request_json->payment_status == 'confirmed' ||
        $request_json->payment_status == 'sending'
    ) {
        $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'approved' WHERE `hash_id` = ?");
        $stmt->bind_param("s", $hash_id);
        $stmt->execute();
        $stmt->close();

        if ($payType == "INCREASE_WALLET") {

            $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` + ? WHERE `userid` = ?");
            $stmt->bind_param("ii", $price, $from_id);
            $stmt->execute();
            $stmt->close();

            sendMessage(
                "✅ افزایش حساب شما با موفقیت تایید شد.\n" .
                "✅ مبلغ " . number_format($price) . " تومان به حساب شما اضافه شد.\n\n" .
                "<blockquote>
⚠️ لطفا دقت کنید شما فقط کیف پول خودتان را شارژ کرده‌اید، برای خرید یا تمدید باید از دکمه های زیر استفاده کنید:

🛒 <b>خرید اشتراک جدید</b>
یا <b>🔄 تمدید یا مشاهده اشتراک ها</b>

و در مرحله پرداخت روی دکمه زیر بزنید:

💰 <b>پرداخت با موجودی</b>

</blockquote>
📨 پشتیبانی:

🆔 $supportID"
            );

            sendMessage(
                "✅ مبلغ " . number_format($price) . " تومان به کیف پول کاربر $from_id توسط درگاه ارزی ریالی اضافه شد.",
                null,
                null,
                $admin
            );
            sendToAllAdmins('sendMessage', [
                'text'        => "✅ مبلغ " . number_format($price) . " تومان به کیف پول کاربر $from_id توسط درگاه ارزی ریالی اضافه شد.",
                'reply_markup'=> null,
                'parse_mode'  => null
            ]);
        }

        elseif ($payType == "BUY_SUB") {

            $uid         = $from_id;
            $fid         = (int)$payInfo['plan_id'];
            $volume      = (int)$payInfo['volume'];
            $days        = (int)$payInfo['day'];
            $description = $payInfo['description'];

            $planDetails = getPlanDetails($connection, $fid);
            $plan        = $planDetails['plan'];

            if ($volume == 0 && $days == 0) {
                $volume = (int)$plan['volume'];
                $days   = (int)$plan['days'];
            }

            $date             = time();
            $expire_microdate = floor(microtime(true) * 1000) + (864000 * $days * 100);
            $expire_date      = $date + (86400 * $days);

            $protocol   = $plan['protocol'];
            $server_id  = (int)$plan['server_id'];
            $netType    = $plan['type'];
            $acount     = (int)$plan['acount'];
            $inbound_id = (int)$plan['inbound_id'];
            $limitip    = $plan['limitip'];
            $rahgozar   = $plan['rahgozar'];
            $customPath = $plan['custom_path'];
            $customPort = $plan['custom_port'];
            $customSni  = $plan['custom_sni'];

            $accountCount = ($payInfo['agent_count'] ?? 0) != 0
                ? (int)$payInfo['agent_count']
                : 1;
            $eachPrice = $accountCount > 0 ? ($price / $accountCount) : $price;

            ensureServerCapacity($connection, $server_id, $inbound_id, $acount, $mainValues);

            [$serverInfo, $serverConfig] = getServerInfoAndConfig($connection, $server_id);
            $srv_remark = $serverInfo['remark'];
            $serverTitle = $serverInfo['title'];
            $serverType = $serverConfig['type'];
            $portType   = $serverConfig['port_type'];

            include_once 'phpqrcode/qrlib.php';

            alert($mainValues['sending_config_to_user']);

            $agent_bought = (int)($payInfo['agent_bought'] ?? 0);

            for ($i = 1; $i <= $accountCount; $i++) {

                $uniqid = generateRandomString(42, $protocol);

                [$port] = getNextPortAndIncrease('settings/temp.txt', $portType);

                $remark = buildRemark(
                    $botState,
                    $srv_remark,
                    $payInfo['description'],
                    $from_id
                );
                if (!empty($description)) {
                    $remark = $description;
                }

                [$response, $remark, $port] = createServerAccount(
                    $serverType,
                    $server_id,
                    $inbound_id,
                    $fid,
                    $remark,
                    $volume,
                    $days,
                    $uniqid,
                    $protocol,
                    $port,
                    $expire_microdate,
                    $netType,
                    $rahgozar,
                    $limitip
                );

                ensureAccountCreationSucceeded($response, $serverInfo, $inbound_id, $admin);

                [$token, $vray_link, $uniqid] = buildLinksAndSendQr(
                    $serverType,
                    $serverConfig,
                    $botState,
                    $botUrl,
                    $server_id,
                    $uniqid,
                    $protocol,
                    $remark,
                    $port,
                    $netType,
                    $inbound_id,
                    $rahgozar,
                    $customPath,
                    $customPort,
                    $customSni,
                    $response,
                    $uid,
                    $volume,
                    $days,
                    $buttonValues,
                    $linkIndices,
                    $hideConfigIfMany
                );

                insertOrder(
                    $connection,
                    $uid,
                    $token,
                    $fid,
                    $server_id,
                    $inbound_id,
                    $remark,
                    $uniqid,
                    $protocol,
                    $expire_date,
                    $vray_link,
                    $eachPrice,
                    $date,
                    $rahgozar,
                    $agent_bought
                );
            }

            $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid` = ?");
            $stmt->bind_param("i", $uid);
            $stmt->execute();
            $user_detail = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!empty($user_detail['refered_by'])) {
                $inviterId = (int)$user_detail['refered_by'];

                giveReferralReward($connection, $user_detail['userid'], $inviterId, $admin, $botState);

                if ($admin != $from_id) {
                    $uname     = $user_detail['name'];
                    $user_name = $user_detail['username'];

                    $keysInvite = json_encode([
                        'inline_keyboard' => [
                            [
                                ['text' => "🛍 خرید اشتراک جدید", 'callback_data' => "wizwizch"]
                            ],
                        ]
                    ]);

                    $msgInvite = str_replace(
                        ['USER-ID', 'USERNAME', 'NAME', 'PRICE', 'REMARK', 'HASHID'],
                        [$uid, $user_name, $uname, $price, $remark, $hash_id],
                        $mainValues['invite_buy_new_account']
                    );

                    sendMessage($msgInvite, $keysInvite, "html", $admin);

                    $rewardChannel = $botState['rewardChannel'] ?? null;
                    if (!empty($rewardChannel)) {
                        sendMessage($msgInvite, $keysInvite, "html", $rewardChannel);
                    }
                }
            }

            decreaseServerCapacity($connection, $server_id, $inbound_id, $fid, $accountCount);

            $keysAdmin = json_encode([
                'inline_keyboard' => [
                    [
                        ['text' => "🛍 خرید اشتراک جدید", 'callback_data' => "wizwizch"]
                    ],
                    [
                        ['text' => "🆔 $hash_id", 'callback_data' => "noop_$hash_id"]
                    ]
                ]
            ]);

            $msgAdmin = str_replace(
                ['SERVERNAME', 'TYPE', 'USER-ID', 'USERNAME', 'NAME', 'PRICE', 'REMARK', 'VOLUME', 'DAYS'],
                [$serverTitle, 'ارزی ریالی', $from_id, $username, $first_name, $price, $remark, $volume, $days],
                $mainValues['buy_new_account_request']
            );

            sendMessage($msgAdmin, $keysAdmin, "html", $admin);
            sendToAllAdmins('sendMessage', [
                'text'        => $msgAdmin,
                'reply_markup'=> $keysAdmin,
                'parse_mode'  => "html"
            ]);
        }

        elseif ($payType == "RENEW_ACCOUNT") {

            $oid = (int)$payInfo['order_id'];

            $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
            $stmt->bind_param("i", $oid);
            $stmt->execute();
            $order = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$order) {
                alert("⛔️ سفارش مربوطه پیدا نشد.", true);
                return;
            }

            $fid        = (int)$order['fileid'];
            $remark     = $order['remark'];
            $uuid       = $order['uuid'] ?? "0";
            $server_id  = (int)$order['server_id'];
            $inbound_id = (int)$order['inbound_id'];

            $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id` = ? AND `active` = 1");
            $stmt->bind_param("i", $fid);
            $stmt->execute();
            $respd = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$respd) {
                alert("⛔️ پلن برای تمدید پیدا نشد.", true);
                return;
            }

            $name   = $respd['title'];
            $days   = (int)$respd['days'];
            $volume = (int)$respd['volume'];
            $price  = (int)$payInfo['price'];

            $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id` = ?");
            $stmt->bind_param("i", $server_id);
            $stmt->execute();
            $server_info = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $serverType = $server_info['type'];

            if ($serverType == "marzban") {
                $response = editMarzbanConfig($server_id, [
                    'remark' => $remark,
                    'days'   => $days,
                    'volume' => $volume
                ]);
            } else {
                if ($inbound_id > 0) {
                    $response = editClientTraffic($server_id, $inbound_id, $uuid, $volume, $days, "renew");
                } else {
                    $response = editInboundTraffic($server_id, $uuid, $volume, $days, "renew");
                }
            }

            if (is_null($response)) {
                alert('⚠️ مشکل فنی در اتصال به سرور، لطفا به پشتیبانی اطلاع دهید.', true);
                return;
            }

            $newExpire = $time + $days * 86400;
            $stmt = $connection->prepare("UPDATE `orders_list` SET `expire_date` = ?, `notif` = 0 WHERE `id` = ?");
            $stmt->bind_param("ii", $newExpire, $oid);
            $stmt->execute();
            $stmt->close();

            $uid = $from_id;
            $stmt = $connection->prepare(
                "INSERT INTO `increase_order` (`userid`, `server_id`, `inbound_id`, `remark`, `amount`, `date`) 
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param("iiisii", $uid, $server_id, $inbound_id, $remark, $price, $time);
            $stmt->execute();
            $stmt->close();

            sendMessage("✅ اشتراک $remark با موفقیت تمدید شد.", getMainKeys());

            $keysRenew = json_encode([
                'inline_keyboard' => [
                    [
                        ['text' => "♻️ تمدید اشتراک", 'callback_data' => "wizwizch"]
                    ],
                    [
                        ['text' => "🆔 $hash_id", 'callback_data' => "noop_$hash_id"]
                    ]
                ]
            ]);

            $msg = str_replace(
                ['TYPE', "USER-ID", "USERNAME", "NAME", "PRICE", "REMARK", "VOLUME", "DAYS"],
                ['کیف پول', $from_id, $username, $first_name, $price, $remark, $volume, $days],
                $mainValues['renew_account_request_message']
            );

            sendMessage($msg, $keysRenew, "html", $admin);
            sendToAllAdmins('sendMessage', [
                'text'        => $msg,
                'reply_markup'=> $keysRenew,
                'parse_mode'  => "html"
            ]);

            $rewardChannel = $botState['rewardChannel'] ?? null;
            if (!empty($rewardChannel)) {
                sendMessage($msg, $keysRenew, "html", $rewardChannel);
            }
        }

        elseif (preg_match('/^INCREASE_DAY_(\d+)_(\d+)/', $payType, $increaseInfo)) {

            $orderId = (int)$increaseInfo[1];

            $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
            $stmt->bind_param("i", $orderId);
            $stmt->execute();
            $orderInfo = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$orderInfo) {
                alert("⛔️ سفارش برای افزایش زمان یافت نشد.", true);
                return;
            }

            $server_id  = (int)$orderInfo['server_id'];
            $inbound_id = (int)$orderInfo['inbound_id'];
            $remark     = $orderInfo['remark'];
            $uuid       = $orderInfo['uuid'] ?? "0";

            $planid = (int)$increaseInfo[2];

            $stmt = $connection->prepare("SELECT * FROM `increase_day` WHERE `id` = ?");
            $stmt->bind_param("i", $planid);
            $stmt->execute();
            $res = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$res) {
                alert("⛔️ پلن افزایش روز یافت نشد.", true);
                return;
            }

            $price  = (int)$payInfo['price'];
            $volume = (int)$res['volume']; // تعداد روز

            $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id` = ?");
            $stmt->bind_param("i", $server_id);
            $stmt->execute();
            $server_info = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $serverType = $server_info['type'];

            if ($serverType == "marzban") {
                $response = editMarzbanConfig($server_id, ['remark' => $remark, 'plus_day' => $volume]);
            } else {
                if ($inbound_id > 0) {
                    $response = editClientTraffic($server_id, $inbound_id, $uuid, 0, $volume);
                } else {
                    $response = editInboundTraffic($server_id, $uuid, 0, $volume);
                }
            }

            if (!is_null($response) && !empty($response->success)) {

                // افزایش expire_date
                $newVolume = $volume * 86400;
                $stmt = $connection->prepare(
                    "UPDATE `orders_list` SET `expire_date` = `expire_date` + ?, `notif` = 0 WHERE `uuid` = ?"
                );
                $stmt->bind_param("is", $newVolume, $uuid);
                $stmt->execute();
                $stmt->close();

                // ثبت در increase_order
                $stmt = $connection->prepare(
                    "INSERT INTO `increase_order` (`userid`, `server_id`, `inbound_id`, `remark`, `amount`, `date`, `day`) 
                     VALUES (?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt->bind_param("iiisiii", $from_id, $server_id, $inbound_id, $remark, $price, $time, $volume);
                $stmt->execute();
                $stmt->close();

                sendMessage("✅ $volume روز به مدت زمان اشتراک شما اضافه شد", getMainKeys());

                $keys = json_encode([
                    'inline_keyboard' => [
                        [
                            ['text' => "⏳ افزایش زمان اشتراک", 'callback_data' => "wizwizch"]
                        ],
                        [
                            ['text' => "🆔 $hash_id", 'callback_data' => "noop_$hash_id"]
                        ]
                    ]
                ]);

                $msg = "
🔋|💰 افزایش زمان با (کیف پول)

▫️شناسه عددی: $from_id
👨 اسم کاربر: $first_name
⚡️ نام کاربری: @$username
🎈 نام کاربری اشتراک: $remark
⏰ مدت افزایش: $volume روز
💰 قیمت: $price تومان
";
                sendMessage($msg, $keys, "html", $admin);

                $rewardChannel = $botState['rewardChannel'] ?? null;
                if (!empty($rewardChannel)) {
                    sendMessage($msg, $keys, "html", $rewardChannel);
                }
            } else {
                alert("به دلیل مشکل فنی امکان افزایش حجم نیست. لطفا به مدیریت اطلاع بدید یا 5 دقیقه دیگر دوباره تست کنید.", true);
            }
        }

        elseif (preg_match('/^INCREASE_VOLUME_(\d+)_(\d+)/', $payType, $increaseInfo)) {

            $orderId = (int)$increaseInfo[1];

            $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
            $stmt->bind_param("i", $orderId);
            $stmt->execute();
            $orderInfo = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$orderInfo) {
                alert("⛔️ سفارش برای افزایش حجم یافت نشد.", true);
                return;
            }

            $server_id  = (int)$orderInfo['server_id'];
            $inbound_id = (int)$orderInfo['inbound_id'];
            $remark     = $orderInfo['remark'];
            $uuid       = $orderInfo['uuid'] ?? "0";

            $planid = (int)$increaseInfo[2];

            $stmt = $connection->prepare("SELECT * FROM `increase_plan` WHERE `id` = ?");
            $stmt->bind_param("i", $planid);
            $stmt->execute();
            $res = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$res) {
                alert("⛔️ پلن افزایش حجم یافت نشد.", true);
                return;
            }

            $price  = (int)$payInfo['price'];
            $volume = (int)$res['volume'];

            $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id` = ?");
            $stmt->bind_param("i", $server_id);
            $stmt->execute();
            $server_info = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $serverType = $server_info['type'];

            if ($serverType == "marzban") {
                $response = editMarzbanConfig($server_id, ['remark' => $remark, 'plus_volume' => $volume]);
            } else {
                if ($inbound_id > 0) {
                    $response = editClientTraffic($server_id, $inbound_id, $uuid, $volume, 0);
                } else {
                    $response = editInboundTraffic($server_id, $uuid, $volume, 0);
                }
            }

            if (!is_null($response) && !empty($response->success)) {

                $stmt = $connection->prepare("UPDATE `orders_list` SET `notif` = 0 WHERE `uuid` = ?");
                $stmt->bind_param("s", $uuid);
                $stmt->execute();
                $stmt->close();

                $stmt = $connection->prepare(
                    "INSERT INTO `increase_order` (`userid`, `server_id`, `inbound_id`, `remark`, `amount`, `date`, `volume`) 
                     VALUES (?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt->bind_param("iiisiii", $from_id, $server_id, $inbound_id, $remark, $price, $time, $volume);
                $stmt->execute();
                $stmt->close();

                $keys = json_encode([
                    'inline_keyboard' => [
                        [
                            ['text' => "🔋 افزایش حجم اشتراک", 'callback_data' => "wizwizch"]
                        ],
                        [
                            ['text' => "🆔 $hash_id", 'callback_data' => "noop_$hash_id"]
                        ]
                    ]
                ]);

                $msg = "
🔋|💰 افزایش حجم با (کیف پول)

🎫 شناسه عددی: $from_id
👨 اسم کاربر: $first_name
⚡️ نام کاربری: @$username
🎈 نام کاربری اشتراک: $remark
⏰ مقدار افزایش: $volume گیگ
💰 قیمت: $price تومان
";
                sendMessage($msg, $keys, "html", $admin);

                $rewardChannel = $botState['rewardChannel'] ?? null;
                if (!empty($rewardChannel)) {
                    sendMessage($msg, $keys, "html", $rewardChannel);
                }

                sendMessage("✅ $volume گیگ به حجم اشتراک شما اضافه شد.", getMainKeys());
            } else {
                alert("به دلیل مشکل فنی امکان افزایش حجم نیست. لطفا به مدیریت اطلاع بدید یا 5 دقیقه دیگر دوباره تست کنید.", true);
            }
        }

        elseif ($payType == "RENEW_SCONFIG") {

            $uid = $from_id;
            $fid = (int)$payInfo['plan_id'];

            // پلن
            $planDetails = getPlanDetails($connection, $fid);
            $plan        = $planDetails['plan'];
            $volume      = $planDetails['volume'];
            $days        = $planDetails['days'];

            $price     = (int)$payInfo['price'];
            $server_id = (int)$plan['server_id'];

            $configInfo = json_decode($payInfo['description'], true) ?: [];
            $remark     = $configInfo['remark'] ?? '';
            $uuid       = $configInfo['uuid']   ?? '';
            $isMarzban  = !empty($configInfo['marzban']);

            $inbound_id = (int)$payInfo['volume'];

            $response = renewConfigOnServer(
                $connection,
                $server_id,
                $inbound_id,
                $uuid,
                $remark,
                $days,
                $volume,
                $isMarzban
            );

            if (is_null($response)) {
                alert('🔻مشکل فنی در اتصال به سرور، لطفا به مدیریت اطلاع بدید.', true);
                return;
            }

            $stmt = $connection->prepare(
                "INSERT INTO `increase_order` (`userid`, `server_id`, `inbound_id`, `remark`, `amount`, `date`) 
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param("iiisii", $uid, $server_id, $inbound_id, $remark, $price, $time);
            $stmt->execute();
            $stmt->close();

            $keys = json_encode([
                'inline_keyboard' => [
                    [
                        ['text' => "♻️ تمدید اشتراک", 'callback_data' => "wizwizch"]
                    ],
                    [
                        ['text' => "🆔 $hash_id", 'callback_data' => "noop_$hash_id"]
                    ]
                ]
            ]);

            $msg = "
🔋|💰 تمدید اشتراک با (کیف پول)

🎫 شناسه عددی: $from_id
👨 اسم کاربر: $first_name
⚡️ نام کاربری: @$username
🎈 نام کاربری اشتراک: $remark
⏰ مدت اشتراک: $days روز
⬇️ حجم اشتراک: $volume گیگ
💰 قیمت: $price تومان
";
            sendMessage($msg, $keys, "html", $admin);
            sendToAllAdmins('sendMessage', [
                'text'        => $msg,
                'reply_markup'=> $keys,
                'parse_mode'  => "html"
            ]);
        }

        editKeys(json_encode([
            'inline_keyboard' => [
                [
                    ['text' => "پرداخت انجام شد.", 'callback_data' => "wizwizch"]
                ]
            ]
        ]));
    } else {
        // پرداخت هنوز کامل نیست
        if ($request_json->payment_status == 'partially_paid') {
            $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'partiallyPaied' WHERE `hash_id` = ?");
            $stmt->bind_param("s", $hash_id);
            $stmt->execute();
            $stmt->close();

            alert("شما هزینه کمتری پرداخت کردید، لطفا به پشتیبانی پیام بدهید.");
        } else {
            alert("پرداخت مورد نظر هنوز تکمیل نشده!");
        }
    }
}

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
if(preg_match('/^haveDiscount(.+?)_(.*)/',$data,$match)){
    delMessage();
    sendMessage($mainValues['insert_discount_code'],$cancelKey);
    if($match[1] == "Custom") setUser('discountCustomPlanDay' . $match[2]);
    elseif($match[1] == "SelectPlan") setUser('discountSelectPlan' . $match[2]);
    elseif($match[1] == "Renew") setUser('discountRenew' . $match[2]);
}
if ((require __DIR__ . '/src/Purchase/TrialCatalogue.php') === null) return;
if ((require __DIR__ . '/src/Purchase/SelectPlan.php') === null) return;
if (preg_match('/payCustomWithWallet(.*)/', $data, $match)) {
    setUser();

    $hashId = trim($match[1] ?? '');

    $result = processWalletPayment($connection, $hashId, function($payInfo, $userRow) use (
        $connection,
        $mainValues,
        $botState,
        $botUrl,
        $buttonValues,
        $linkIndices,
        $hideConfigIfMany,
        $admin,
        $userInfo,
        $username,
        $first_name,
        $from_id
    ) {
        $uid    = (int)($payInfo['user_id'] ?: $from_id);
        $fid    = (int)$payInfo['plan_id'];
        $volume = (int)$payInfo['volume'];
        $days   = (int)$payInfo['day'];
        $remark = $payInfo['description'];
        $price  = (int)$payInfo['price'];
        $hashIdLocal = $payInfo['hash_id'];

        $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
        $stmt->bind_param("i", $fid);
        $stmt->execute();
        $plan = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$plan) {
            alert('❌ پلن مرتبط با این پرداخت یافت نشد.');
            return false;
        }

        $date             = time();
        $expire_microdate = floor(microtime(true) * 1000) + (864000 * $days * 100);
        $expire_date      = $date + (86400 * $days);

        $type       = $plan['type'];
        $protocol   = $plan['protocol'];
        $server_id  = (int)$plan['server_id'];
        $netType    = $plan['type'];
        $acount     = (int)$plan['acount'];
        $inbound_id = (int)$plan['inbound_id'];
        $limitip    = $plan['limitip'];
        $rahgozar   = $plan['rahgozar'];
        $customPath = $plan['custom_path'];
        $customPort = $plan['custom_port'];
        $customSni  = $plan['custom_sni'];

        ensureServerCapacity($connection, $server_id, $inbound_id, $acount, $mainValues);

        $uniqid = generateRandomString(42, $protocol);

        // اطلاعات سرور + تنظیمات پنل
        [$serverInfo, $serverConfig] = getServerInfoAndConfig($connection, $server_id);
        $srv_remark  = $serverInfo['remark'];
        $serverType  = $serverConfig['type'];
        $portType    = $serverConfig['port_type'];

        [$port, $last_num] = getNextPortAndIncrease('settings/temp.txt', $portType);

        [$response, $remark, $port] = createServerAccount(
            $serverType,
            $server_id,
            $inbound_id,
            $fid,
            $remark,
            $volume,
            $days,
            $uniqid,
            $protocol,
            $port,
            $expire_microdate,
            $netType,
            $rahgozar,
            $limitip
        );

        ensureAccountCreationSucceeded($response, $serverInfo, $inbound_id, $admin);

        alert($mainValues['sending_config_to_user']);

        [$token, $vray_link, $uniqid] = buildLinksAndSendQr(
            $serverType,
            $serverConfig,
            $botState,
            $botUrl,
            $server_id,
            $uniqid,
            $protocol,
            $remark,
            $port,
            $netType,
            $inbound_id,
            $rahgozar,
            $customPath,
            $customPort,
            $customSni,
            $response,
            $uid,
            $volume,
            $days,
            $buttonValues,
            $linkIndices,
            $hideConfigIfMany
        );

        $inviterId = $userRow['refered_by'] ?? null;
        if (!empty($inviterId)) {
			giveReferralReward($connection, $uid, $inviterId, $admin, $botState, 'wallet');
        }

        $agentBought = (int)($payInfo['agent_bought'] ?? 0);

        insertOrder(
            $connection,
            $uid,
            $token,
            $fid,
            $server_id,
            $inbound_id,
            $remark,
            $uniqid,
            $protocol,
            $expire_date,
            $vray_link,
            $price,
            $date,
            $rahgozar,
            $agentBought
        );

        decreaseServerCapacity($connection, $server_id, $inbound_id, $fid, 1);

        $keys = json_encode([
            'inline_keyboard' => [
                [
                    ['text' => "🛍 خرید اشتراک جدید", 'callback_data' => "wizwizch"]
                ],
                [
                    ['text' => "🆔 $hashIdLocal", 'callback_data' => "noop_$hashIdLocal"]
                ]
            ]
        ]);

        $msg = str_replace(
            ['TYPE', 'USER-ID', 'USERNAME', 'NAME', 'PRICE', 'REMARK', 'VOLUME', 'DAYS'],
            ['کیف پول', $uid, $username, $first_name, $price, $remark, $volume, $days],
            $mainValues['buy_custom_account_request']
        );

        sendMessage($msg, $keys, "html", $admin);
        sendToAllAdmins('sendMessage', ['text' => $msg, 'reply_markup' => $keys, 'parse_mode' => "html"]);

        return true;
    });

    if ($result['status'] === 'not_found') {
        $text = "/start";
        $data = "";
        delMessage();
        goto GOTOSTART;
    }

    if ($result['status'] === 'already_paid') {
        return;
    }
    return;
}

if (preg_match('/^showQr(Sub|Config)(\d+)/', $data, $match)) {
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `userid`=? AND `id`=?");
    $stmt->bind_param("ii", $from_id, $match[2]);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    include_once 'phpqrcode/qrlib.php';

    if (!defined('IMAGE_WIDTH'))  define('IMAGE_WIDTH', 540);
    if (!defined('IMAGE_HEIGHT')) define('IMAGE_HEIGHT', 540);

    if ($match[1] == "Sub") {
        $subLink = $botUrl . "settings/subLink.php?token=" . $order['token'];

        $file = RandomString() . ".png";
        $ecc = 'L';
        $pixel_Size = 11;
        $frame_Size = 4;

        QRcode::png($subLink, $file, $ecc, $pixel_Size, $frame_Size);

        sendPhoto(
            $botUrl . $file,
            $acc_text,
            json_encode(['inline_keyboard' => [[['text' => $buttonValues['back_to_main'], 'callback_data' => "mainMenu"]]]]),
            "HTML",
            $uid
        );
        unlink($file);
    }
    elseif ($match[1] == "Config") {

        $vraylink = json_decode($order['link'], true);

        foreach ($vraylink as $vray_link) {
            $file = RandomString() . ".png";
            $ecc = 'L';
            $pixel_Size = 11;
            $frame_Size = 4;

            QRcode::png($vray_link, $file, $ecc, $pixel_Size, $frame_Size);

            sendPhoto(
                $botUrl . $file,
                $acc_text,
                json_encode(['inline_keyboard' => [[['text' => $buttonValues['back_to_main'], 'callback_data' => "mainMenu"]]]]),
                "HTML",
                $uid
            );
            unlink($file);
        }
    }
}

if (preg_match('/payCustomWithCartToCart(.*)/', $data, $match)) {

    $payInfo = dbFetchPayByHash($connection, $match[1]);
    if (!$payInfo) {
        prepareStartFallback();
        delMessage();
        goto GOTOSTART;
    }

    $fid = (int)$payInfo['plan_id'];

    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
    $stmt->bind_param("i", $fid);
    $stmt->execute();
    $file_detail = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $server_id  = (int)$file_detail['server_id'];
    $acount     = (int)$file_detail['acount'];
    $inbound_id = (int)$file_detail['inbound_id'];

    if ($acount == 0 && $inbound_id != 0) {
        alert($mainValues['out_of_connection_capacity'], true);
        exit;
    }

    if ($inbound_id == 0) {
        $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $server_info = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ((int)$server_info['ucount'] <= 0) {
            alert($mainValues['out_of_server_capacity'], true);
            exit;
        }
    } else {
        if ($acount != 0 && $acount <= 0) {
            sendMessage(str_replace("AMOUNT", $acount, $mainValues['can_create_specific_account']));
            exit;
        }
    }

    delMessage();
    setUser("payCustomWithCartToCart{$match[1]}");

	// $msg = buildCartToCartInfoMessage($paymentKeys, $mainValues, (int)$payInfo['price']);
	$msg = buildCartToCartInfoMessage($paymentKeys, $mainValues, (int)$payInfo['price'], (int)$payInfo['user_id']);
    $keyboard = kbSendReceiptAndCancel("sendCustomCartReceipt", "cancelCustomCartReceipt", $match[1]);

    sendMessage($msg, $keyboard, "HTML");
    exit;
}

if (preg_match('/sendCustomCartReceipt(?<hashId>.*)/', $data, $match)) {

    $payInfo = dbFetchPayByHash($connection, $match['hashId']);
    if (!$payInfo) {
        prepareStartFallback();
        delMessage();
        goto GOTOSTART;
    }

    $guardMsg = duplicateReceiptGuard($connection, $payInfo, time());
    if ($guardMsg) { alert($guardMsg, true); exit; }

    delMessage();
    setUser("waitCustomCartReceipt{$match['hashId']}");

    $keyboard = kbOnlyCancel("cancelCustomCartReceipt", $match['hashId']);
    sendMessage($mainValues['please_send_receipt_image'], $keyboard, "HTML");
    exit;
}

if (preg_match('/cancelCustomCartReceipt(?<hashId>.*)/', $data, $match)) {
    handleCancelFlow($mainValues, $removeKeyboard);
}

if (preg_match('/waitCustomCartReceipt(.*)/', $userInfo['step'], $match)) {

    $fileid = extractPhotoFileId($update);
    if (!$fileid) {
        $keyboard = kbOnlyCancel("cancelCustomCartReceipt", $match[1]);
        sendMessage($mainValues['please_send_only_image'], $keyboard, "HTML");
        exit;
    }

    $payInfo = dbFetchPayByHash($connection, $match[1]);
    if (!$payInfo) {
        prepareStartFallback();
        delMessage();
        goto GOTOSTART;
    }

    $fid    = (int)$payInfo['plan_id'];
    $volume = $payInfo['volume'];
    $days   = $payInfo['day'];

    setUser();
    $uid = $userInfo['userid'];
    $name = $userInfo['name'];
    $username = $userInfo['username'];

    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
    $stmt->bind_param("i", $fid);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $stmt = $connection->prepare("SELECT * FROM `server_categories` WHERE `id`=?");
    $stmt->bind_param("i", $res['catid']);
    $stmt->execute();
    $catname = $stmt->get_result()->fetch_assoc()['title'];
    $stmt->close();

    $filename = $catname . " " . $res['title'];
    $fileprice = $payInfo['price'];
    $remark = $payInfo['description'];

    sendWithStartButton($mainValues['order_submitted'], $buttonValues['start_bot'], $botUsername, 'mainMenu', 'HTML');

    $msg = str_replace(
        ['TYPE', 'USER-ID', 'USERNAME', 'NAME', 'PRICE', 'REMARK', 'VOLUME', 'DAYS'],
        ["کارت به کارت", $from_id, $username, $first_name, $fileprice, $remark, $volume, $days],
        $mainValues['buy_custom_account_request']
    );

    $adminKeyboard = buildAdminKeyboardWithStats(
        $connection,
        $buttonValues,
        "accCustom{$match[1]}",
        "declineAction_{$match[1]}",
        $match[1],
        (int)$payInfo['user_id']
    );

    sendReceiptToAdminsAndUpdatePay($connection, $fileid, $msg, $adminKeyboard, $admin, $match[1]);
    exit;
}

if (preg_match('/accCustom(.*)/', $data, $match) && $text != $buttonValues['cancel']) {

    setUser();
    $hash_id = trim($match[1]);

    $connection->begin_transaction();

    try {
        $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ? FOR UPDATE");
        $stmt->bind_param("s", $hash_id);
        $stmt->execute();
        $payInfo = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$payInfo || $payInfo['state'] === 'expired_have_sent') {
            alert(!$payInfo ? "⛔️ اطلاعات پرداخت پیدا نشد." : "⛔️ این درخواست منقضی شده است.");
            throw new Exception("invalid_or_expired");
        }

        if ($payInfo['state'] === 'approved' || $payInfo['state'] === 'declined') {
            alert("⛔️ این درخواست قبلاً بررسی شده است.");
            throw new Exception("already_processed");
        }

        $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'approved', `approved_by` = ? WHERE `hash_id` = ?");
        $stmt->bind_param("is", $from_id, $hash_id);
        $stmt->execute();
        $stmt->close();

        $fid    = (int)$payInfo['plan_id'];
        $uid    = (int)$payInfo['user_id'];
        $days   = (int)$payInfo['day'];
        $volume = (int)$payInfo['volume'];
        $price  = (int)$payInfo['price'];
        $remark = $payInfo['description'];

        $planDetails = getPlanDetails($connection, $fid);
        $plan        = $planDetails['plan'];

        $date = time();
        $expire_microdate = floor(microtime(true) * 1000) + (864000 * $days * 100);
        $expire_date      = $date + (86400 * $days);

        $type       = $plan['type'];
        $protocol   = $plan['protocol'];
        $server_id  = (int)$plan['server_id'];
        $netType    = $plan['type'];
        $acount     = (int)$plan['acount'];
        $inbound_id = (int)$plan['inbound_id'];
        $limitip    = $plan['limitip'];
        $rahgozar   = $plan['rahgozar'];
        $customPath = $plan['custom_path'];
        $customPort = $plan['custom_port'];
        $customSni  = $plan['custom_sni'];

        ensureServerCapacity($connection, $server_id, $inbound_id, $acount, $mainValues);

        [$serverInfo, $serverConfig] = getServerInfoAndConfig($connection, $server_id);
        $srv_remark  = $serverInfo['remark'];
        $serverType  = $serverConfig['type'];
        $portType    = $serverConfig['port_type'];

        $uniqid = generateRandomString(42, $protocol);
        [$port] = getNextPortAndIncrease('settings/temp.txt', $portType);

        // اگر خواستی fallback برای remark داشته باشی:
        // $remark = buildRemark($botState, $srv_remark, $payInfo['description'], $uid, $payInfo['description']);

        [$response, $remark, $port] = createServerAccount(
            $serverType,
            $server_id,
            $inbound_id,
            $fid,
            $remark,
            $volume,
            $days,
            $uniqid,
            $protocol,
            $port,
            $expire_microdate,
            $netType,
            $rahgozar,
            $limitip
        );

        ensureAccountCreationSucceeded($response, $serverInfo, $inbound_id, $admin, true);

        alert($mainValues['sending_config_to_user']);

        [$token, $vray_link, $uniqid] = buildLinksAndSendQr(
            $serverType,
            $serverConfig,
            $botState,
            $botUrl,
            $server_id,
            $uniqid,
            $protocol,
            $remark,
            $port,
            $netType,
            $inbound_id,
            $rahgozar,
            $customPath,
            $customPort,
            $customSni,
            $response,
            $uid,
            $volume,
            $days,
            $buttonValues,
            $linkIndices,
            $hideConfigIfMany
        );

        $agentBought = (int)($payInfo['agent_bought'] ?? 0);
        insertOrder(
            $connection,
            $uid,
            $token,
            $fid,
            $server_id,
            $inbound_id,
            $remark,
            $uniqid,
            $protocol,
            $expire_date,
            $vray_link,
            $price,
            $date,
            $rahgozar,
            $agentBought
        );

        decreaseServerCapacity($connection, $server_id, $inbound_id, $fid, 1);

        $connection->commit();

    } catch (Exception $e) {
        $connection->rollback();
        return;
    }

    sendMessage('✅ اشتراک برای کاربر ارسال شد.', getMainKeys());

    $keys = json_encode([
        'inline_keyboard' => [
            [['text' => "✅ تایید شد توسط $from_id", 'callback_data' => "wizwizch"]],
            [['text' => "🔄 $remark",                'callback_data' => "noop"]],
            [['text' => "🆔 $hash_id",              'callback_data' => "noop_$hash_id"]],
        ]
    ], 488);

    editKeys($keys, $payInfo['message_id'], $payInfo['chat_id']);

    $adminMessages = json_decode($payInfo['admins_messages'], true);
    if (is_array($adminMessages)) {
        foreach ($adminMessages as $msgInfo) {
            if (
                isset($msgInfo['chat_id'], $msgInfo['message_id']) &&
                $msgInfo['chat_id'] != $payInfo['chat_id']
            ) {
                editKeys($keys, $msgInfo['message_id'], $msgInfo['chat_id']);
            }
        }
    }

    $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid`=?");
    $stmt->bind_param("i", $uid);
    $stmt->execute();
    $user_detail = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!empty($user_detail['refered_by'])) {
        $inviterId = (int)$user_detail['refered_by'];

        giveReferralReward($connection, $user_detail['userid'], $inviterId, $admin, $botState);

        if ($admin != $from_id) {
            $uname     = $user_detail['name'];
            $user_name = $user_detail['username'];

            $keys2 = json_encode([
                'inline_keyboard' => [
                    [
                        ['text' => "🛍 خرید اشتراک جدید", 'callback_data' => "wizwizch"]
                    ],
                ]
            ]);

            $msg = str_replace(
                ['USER-ID', 'USERNAME', 'NAME', 'PRICE', 'REMARK', 'HASHID'],
                [$uid, $user_name, $uname, $price, $remark, $hash_id],
                $mainValues['invite_buy_new_account']
            );

            sendMessage($msg, $keys2, "html", $admin);

            $rewardChannel = $botState['rewardChannel'] ?? null;
            if (!empty($rewardChannel)) {
                sendMessage($msg, $keys2, "html", $rewardChannel);
            }
        }
    }
}

if (preg_match('/payBuyWithWallet(.*)/', $data, $match)) {

    setUser();

    $hashId = trim($match[1] ?? '');
    $deferredRenewSuccess = null;
    $pendingUserQrs = [];
    $msgToDeleteAfterOk = null;
    $deferredReferral = null;

    $lastRemark = '';
    $serverTitle = '-';

    $result = processWalletPayment(
        $connection,
        $hashId,
        function($payInfo, $userRow) use (
            $connection,
            $mainValues,
            $botState,
            $botUrl,
            $buttonValues,
            $linkIndices,
            $hideConfigIfMany,
            $admin,
            $from_id,
            $message_id,
            $time,
            &$deferredRenewSuccess,
            &$pendingUserQrs,
            &$msgToDeleteAfterOk,
            &$deferredReferral,
            &$lastRemark,
            &$serverTitle
        ) {

            $uid     = (int)($payInfo['user_id'] ?: $from_id);
            $fid     = (int)$payInfo['plan_id'];
            $price   = (int)$payInfo['price'];
            $payType = $payInfo['type'];
            $userReferrer = $userRow['refered_by'] ?? null;
            $hashIdLocal  = $payInfo['hash_id'];

            if ($payType === "RENEW_SCONFIG") {

                $planDetails = getPlanDetails($connection, $fid);
                $plan        = $planDetails['plan'];

                $days   = $planDetails['days'];
                $volume = $planDetails['volume'];

                $server_id  = (int)$plan['server_id'];
                $inbound_id = (int)$payInfo['volume'];

                $configInfo = json_decode($payInfo['description'], true) ?: [];
                $uuid       = $configInfo['uuid'] ?? '';
                $remark     = $configInfo['remark'] ?? '';
                $isMarzban  = !empty($configInfo['marzban']);

                [$serverInfo] = getServerInfoAndConfig($connection, $server_id);

                $response = renewConfigOnServer(
                    $connection,
                    $server_id,
                    $inbound_id,
                    $uuid,
                    $remark,
                    $days,
                    $volume,
                    $isMarzban
                );

                ensureAccountCreationSucceeded($response, $serverInfo, $inbound_id, $admin, true);

                createIncreaseOrder(
                    $connection,
                    $uid,
                    $server_id,
                    $inbound_id,
                    $remark,
                    $price,
                    $time,
                    $volume,
                    $days
                );

                $keys = json_encode([
                    'inline_keyboard' => [
                        [ ['text' => $buttonValues['back_to_main'], 'callback_data' => "mainMenu"] ]
                    ]
                ], JSON_UNESCAPED_UNICODE);

                $deferredRenewSuccess = [
                    $message_id,
                    "✅ اشتراک $remark با موفقیت تمدید شد.",
                    $keys
                ];

                $lastRemark = $remark;

                if (!empty($userReferrer)) {
                    $deferredReferral = [$uid, (int)$userReferrer];
                }

                return true;
            }

            $accountCount = (int)($payInfo['agent_count'] ?? 1);
            $accountCount = max(1, $accountCount);

            $eachPrice = ($accountCount > 0)
                        ? ($price / $accountCount)
                        : $price;

            $planDetails = getPlanDetails($connection, $fid);
            $plan        = $planDetails['plan'];

            $days             = $planDetails['days'];
            $volume           = $planDetails['volume'];
            $date             = $planDetails['date'];
            $expire_microdate = $planDetails['expire_microdate'];
            $expire_date      = $planDetails['expire_date'];

            $server_id  = (int)$plan['server_id'];
            $acount     = (int)$plan['acount'];
            $inbound_id = (int)$plan['inbound_id'];
            $protocol   = $plan['protocol'];
            $limitip    = $plan['limitip'];
            $netType    = $plan['type'];
            $rahgozar   = $plan['rahgozar'];
            $customPath = $plan['custom_path'];
            $customPort = $plan['custom_port'];
            $customSni  = $plan['custom_sni'];

            ensureServerCapacity($connection, $server_id, $inbound_id, $acount, $mainValues);

            [$serverInfo, $serverConfig] = getServerInfoAndConfig($connection, $server_id);
            $serverTitle = $serverInfo['title'] ?? '-';
            $srv_remark  = $serverInfo['remark'];
            $serverType  = $serverConfig['type'];
            $portType    = $serverConfig['port_type'];

            $agent_bought = (int)($payInfo['agent_bought'] ?? 0);

            alert($mainValues['sending_config_to_user']);

            $msgToDeleteAfterOk = $message_id;

            for ($i = 1; $i <= $accountCount; $i++) {

                $uniqid = generateRandomString(42, $protocol);
                [$port] = getNextPortAndIncrease('settings/temp.txt', $portType);

                $remark = buildRemark(
                    $botState,
                    $srv_remark,
                    $payInfo['description'],
                    $uid
                );

                [$response, $remark, $port] = createServerAccount(
                    $serverType,
                    $server_id,
                    $inbound_id,
                    $fid,
                    $remark,
                    $volume,
                    $days,
                    $uniqid,
                    $protocol,
                    $port,
                    $expire_microdate,
                    $netType,
                    $rahgozar,
                    $limitip
                );

                ensureAccountCreationSucceeded($response, $serverInfo, $inbound_id, $admin, true);

                $lastRemark = $remark;

                [$token, $vray_link, $uniqid2, $acc_text, $userKeys, $qrLink] = buildLinksAndSendQr(
                    $serverType,
                    $serverConfig,
                    $botState,
                    $botUrl,
                    $server_id,
                    $uniqid,
                    $protocol,
                    $remark,
                    $port,
                    $netType,
                    $inbound_id,
                    $rahgozar,
                    $customPath,
                    $customPort,
                    $customSni,
                    $response,
                    $uid,
                    $volume,
                    $days,
                    $buttonValues,
                    $linkIndices,
                    $hideConfigIfMany,
                    '',
                    null,
                    false
                );

                insertOrder(
                    $connection,
                    $uid,
                    $token,
                    $fid,
                    $server_id,
                    $inbound_id,
                    $remark,
                    $uniqid2 ?: $uniqid,
                    $protocol,
                    $expire_date,
                    $vray_link,
                    $eachPrice,
                    $date,
                    $rahgozar,
                    $agent_bought
                );

                $pendingUserQrs[] = [
                    'uid'      => $uid,
                    'qrLink'   => $qrLink,
                    'acc_text' => $acc_text,
                    'keys'     => $userKeys,
                ];
            }

            decreaseServerCapacity($connection, $server_id, $inbound_id, $fid, $accountCount);

            if (!empty($userReferrer)) {
                $deferredReferral = [$uid, (int)$userReferrer];
            }

            return true;
        }
    );

    if ($result['status'] === 'not_found') {
        $text = "/start";
        $data = "";
        delMessage();
        goto GOTOSTART;
    }

    if ($result['status'] === 'already_paid') {
        return;
    }

    if ($result['status'] === 'in_progress') {
        return;
    }

    if ($result['status'] !== 'ok') {
        return;
    }

    $payInfo = $result['payInfo'];
    $uid     = $result['uid'];
    $price   = $result['price'];
    $payType = $payInfo['type'];
    $fid     = (int)$payInfo['plan_id'];
    $hashId  = $payInfo['hash_id'];

    if (($payInfo['state'] ?? '') !== 'paid_with_wallet') {
		$uidForLog = (int)($payInfo['user_id'] ?? $from_id);

		sendMessage(
			"⚠️ پرداخت هنوز نهایی نشده؛ ارسال انجام نشد.\n" .
			"👤 UserID: {$uidForLog}\n" .
			"🆔 $hashId",
			null,
			null,
			$admin
		);
		return;
	}

    if (!empty($deferredRenewSuccess)) {
        editText(
            $deferredRenewSuccess[0],
            $deferredRenewSuccess[1],
            $deferredRenewSuccess[2]
        );
    }

    if (!empty($pendingUserQrs)) {
        foreach ($pendingUserQrs as $pq) {
            sendQrFromLink(
                $pq['qrLink'],
                $pq['acc_text'],
                $pq['keys'],
                $botUrl,
                (int)$pq['uid'],
                '',
                null
            );
        }
    }

    if (!empty($msgToDeleteAfterOk)) {
        delMessage($msgToDeleteAfterOk);
    }

    if (!empty($deferredReferral) && is_array($deferredReferral) && count($deferredReferral) === 2) {
        // giveReferralReward($connection, (int)$deferredReferral[0], (int)$deferredReferral[1], $admin, $botState);
		giveReferralReward($connection, (int)$deferredReferral[0], (int)$deferredReferral[1], $admin, $botState, 'wallet');
    }

    $planDetails = getPlanDetails($connection, $fid);
    $days        = $planDetails['days'];
    $volume      = $planDetails['volume'];

    $keys = json_encode([
        'inline_keyboard' => [
            [ ['text' => "🛍 خرید اشتراک جدید", 'callback_data' => "wizwizch"] ],
            [ ['text' => "🆔 $hashId",          'callback_data' => "noop_$hashId"] ]
        ]
    ], JSON_UNESCAPED_UNICODE);

    if ($payType === "RENEW_SCONFIG") {
        $msg = str_replace(
            ['TYPE', 'USER-ID', 'USERNAME', 'NAME', 'PRICE', 'REMARK', 'VOLUME', 'DAYS'],
            ['کیف پول', $from_id, $username, $first_name, $price, $lastRemark, $volume, $days],
            $mainValues['renew_account_request_message']
        );
    } else {
        $msg = str_replace(
            ['SERVERNAME', 'TYPE', 'USER-ID', 'USERNAME', 'NAME', 'PRICE', 'REMARK', 'VOLUME', 'DAYS'],
            [$serverTitle, 'کیف پول', $from_id, $username, $first_name, $price, $lastRemark, $volume, $days],
            $mainValues['buy_new_account_request']
        );
    }

    sendMessage($msg, $keys, "html", $admin);

    $rewardChannel = $botState['rewardChannel'] ?? null;
    if (!empty($rewardChannel)) {
        sendMessage($msg, $keys, "html", $rewardChannel);
    }
}

if (preg_match('/payBuyWithCartToCart(.*)/', $data, $match)) {

    $payInfo = dbFetchPayByHash($connection, $match[1]);
    if (!$payInfo) {
        prepareStartFallback();
        delMessage();
        goto GOTOSTART;
    }

    $fid = (int)$payInfo['plan_id'];

    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
    $stmt->bind_param("i", $fid);
    $stmt->execute();
    $file_detail = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $server_id  = (int)$file_detail['server_id'];
    $acount     = (int)$file_detail['acount'];
    $inbound_id = (int)$file_detail['inbound_id'];

    if ($payInfo['type'] != "RENEW_SCONFIG") {

        if ($acount == 0 && $inbound_id != 0) {
            alert($mainValues['out_of_connection_capacity'], true);
            exit;
        }

        if ($inbound_id == 0) {
            $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
            $stmt->bind_param("i", $server_id);
            $stmt->execute();
            $server_info = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ((int)$server_info['ucount'] <= 0) {
                alert($mainValues['out_of_server_capacity'], true);
                exit;
            }
        } else {
            if ($acount <= 0) {
                alert(str_replace("AMOUNT", $acount, $mainValues['can_create_specific_account']), true);
                exit;
            }
        }
    }

    delMessage();
    setUser("payBuyWithCartToCart{$match[1]}");

	// $msg = buildCartToCartInfoMessage($paymentKeys, $mainValues, (int)$payInfo['price']);
	$msg = buildCartToCartInfoMessage($paymentKeys, $mainValues, (int)$payInfo['price'], (int)$payInfo['user_id']);
    $keyboard = kbSendReceiptAndCancel("sendBuyCartReceipt", "cancelBuyCartReceipt", $match[1]);

    sendMessage($msg, $keyboard, "HTML");
    exit;
}

if (preg_match('/sendBuyCartReceipt(?<hashId>.*)/', $data, $match)) {

    $payInfo = dbFetchPayByHash($connection, $match['hashId']);
    if (!$payInfo) {
        prepareStartFallback();
        delMessage();
        goto GOTOSTART;
    }

    $guardMsg = duplicateReceiptGuard($connection, $payInfo, time());
    if ($guardMsg) { alert($guardMsg, true); exit; }

    delMessage();
    setUser("waitBuyCartReceipt{$match['hashId']}");

    $keyboard = kbOnlyCancel("cancelBuyCartReceipt", $match['hashId']);
    sendMessage($mainValues['please_send_receipt_image'], $keyboard, "HTML");
    exit;
}

if (preg_match('/cancelBuyCartReceipt(?<hashId>.*)/', $data, $match)) {
    handleCancelFlow($mainValues, $removeKeyboard);
}

if (preg_match('/waitBuyCartReceipt(.*)/', $userInfo['step'], $match)) {

    $fileid = extractPhotoFileId($update);
    if (!$fileid) {
        $keyboard = kbOnlyCancel("cancelBuyCartReceipt", $match[1]);
        sendMessage($mainValues['please_send_only_image'], $keyboard, "HTML");
        exit;
    }

    $payInfo = dbFetchPayByHash($connection, $match[1]);
    if (!$payInfo) {
        prepareStartFallback();
        delMessage();
        goto GOTOSTART;
    }

    $fid = (int)$payInfo['plan_id'];

    setUser();
    $uid = $userInfo['userid'];
    $name = $userInfo['name'];
    $username = $userInfo['username'];

    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
    $stmt->bind_param("i", $fid);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $days = $res['days'];
    $volume = $res['volume'];

    $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
    $stmt->bind_param("i", $res['server_id']);
    $stmt->execute();
    $serverInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $serverTitle = $serverInfo['title'];

    if ($payInfo['type'] == "RENEW_SCONFIG") {
        $configInfo = json_decode($payInfo['description'], true);
        $filename = $configInfo['remark'];
    } else {
        $stmt = $connection->prepare("SELECT * FROM `server_categories` WHERE `id`=?");
        $stmt->bind_param("i", $res['catid']);
        $stmt->execute();
        $catname = $stmt->get_result()->fetch_assoc()['title'];
        $stmt->close();
        $filename = $catname . " " . $res['title'];
    }

    $fileprice = $payInfo['price'];

    sendWithStartButton($mainValues['order_submitted'], $buttonValues['start_bot'], $botUsername, 'mainMenu', 'HTML');

    if ((int)$payInfo['agent_count'] != 0) {
        $msg = str_replace(
            ['ACCOUNT-COUNT', 'TYPE', 'USER-ID', "USERNAME", "NAME", "PRICE", "REMARK"],
            [$payInfo['agent_count'], 'کارت به کارت', $from_id, $username, $name, $fileprice, $filename],
            $mainValues['buy_new_much_account_request']
        );
    } else {
        $msg = str_replace(
            ['SERVERNAME', 'TYPE', 'USER-ID', "USERNAME", "NAME", "PRICE", "REMARK", "VOLUME", "DAYS"],
            [$serverTitle, 'کارت به کارت', $from_id, $username, $name, $fileprice, $filename, $volume, $days],
            $mainValues['buy_new_account_request']
        );
    }

    $adminKeyboard = buildAdminKeyboardWithStats(
        $connection,
        $buttonValues,
        "accept{$match[1]}",
        "declineAction_{$match[1]}",
        $match[1],
        (int)$payInfo['user_id']
    );

    setUser('', 'temp');

    sendReceiptToAdminsAndUpdatePay($connection, $fileid, $msg, $adminKeyboard, $admin, $match[1]);
    exit;
}

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
if (
    preg_match('/accept(.*)/', $data, $match) &&
    $text != $buttonValues['cancel'] &&
    ($from_id == $admin || ($userInfo['isAdmin'] ?? false) == true)
) {
    setUser();
    $hash_id = trim($match[1]);

    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ? LIMIT 1");
    $stmt->bind_param("s", $hash_id);
    $stmt->execute();
    $payInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$payInfo) {
        alert("⛔️ اطلاعات پرداخت پیدا نشد.");
        return;
    }

    if ($payInfo['state'] === 'expired_have_sent') {
        alert("⛔️ این درخواست منقضی شده است.");
        return;
    }
    if ($payInfo['state'] === 'approved' || $payInfo['state'] === 'declined') {
        alert("⛔️ این درخواست قبلاً بررسی شده است.");
        return;
    }

    if ($payInfo['state'] !== 'have_sent') {
        alert("⛔️ این درخواست در وضعیت قابل تایید نیست: " . $payInfo['state']);
        return;
    }

    $stmt = $connection->prepare("
        UPDATE `pays`
        SET `state`='processing', `approved_by`=?
        WHERE `hash_id`=? AND `state`='have_sent'
        LIMIT 1
    ");
    $stmt->bind_param("is", $from_id, $hash_id);
    $stmt->execute();

    if ($stmt->affected_rows !== 1) {
        $stmt->close();
        alert("⛔️ این درخواست هم‌اکنون در حال پردازش است.");
        return;
    }
    $stmt->close();

    try {
        $uid     = (int)$payInfo['user_id'];
        $fid     = (int)$payInfo['plan_id'];
        $price   = (int)$payInfo['price'];
        $payType = (string)$payInfo['type'];

        if ($payType === "RENEW_SCONFIG") {

            $planDetails = getPlanDetails($connection, $fid);
            $plan        = $planDetails['plan'];

            $days   = $planDetails['days'];
            $volume = $planDetails['volume'];

            $server_id  = (int)$plan['server_id'];
            $inbound_id = (int)$payInfo['volume'];

            $configInfo = json_decode($payInfo['description'], true) ?: [];
            $uuid       = $configInfo['uuid']   ?? '';
            $remark     = $configInfo['remark'] ?? '';
            $isMarzban  = !empty($configInfo['marzban']);

            [$serverInfo, $serverConfig] = getServerInfoAndConfig($connection, $server_id);

            $response = renewConfigOnServer(
                $connection,
                $server_id,
                $inbound_id,
                $uuid,
                $remark,
                $days,
                $volume,
                $isMarzban
            );

            ensureAccountCreationSucceeded($response, $serverInfo, $inbound_id, $admin);

            $connection->begin_transaction();
            try {
                createIncreaseOrder(
                    $connection,
                    $uid,
                    $server_id,
                    $inbound_id,
                    $remark,
                    $price,
                    $time,
                    null,
                    null
                );

                $stmt = $connection->prepare("UPDATE `pays` SET `state`='approved', `approved_by`=? WHERE `hash_id`=? LIMIT 1");
                $stmt->bind_param("is", $from_id, $hash_id);
                $stmt->execute();
                $stmt->close();

                $connection->commit();
            } catch (Exception $e) {
                $connection->rollback();

                $stmt = $connection->prepare("UPDATE `pays` SET `state`='have_sent' WHERE `hash_id`=? LIMIT 1");
                $stmt->bind_param("s", $hash_id);
                $stmt->execute();
                $stmt->close();

                sendMessage("⚠️ تمدید انجام شد اما ثبت دیتابیس خطا خورد.\n🆔 $hash_id\nError: ".$e->getMessage(), null, null, $admin);
                return;
            }

            sendMessage(
                str_replace(["REMARK","VOLUME","DAYS"], [$remark,$volume,$days], $mainValues['renewed_config_to_user']),
                getMainKeys(),
                null,
                null
            );
            sendMessage("✅ اشتراک $remark با موفقیت تمدید شد.", null, null, $uid);

            $keys = json_encode([
                'inline_keyboard' => [
                    [['text' => "✅ تایید شد توسط $from_id", 'callback_data' => "wizwizch"]],
                    [['text' => "🔄 $remark",                'callback_data' => "noop"]],
                    [['text' => "🆔 $hash_id",              'callback_data' => "noop_$hash_id"]],
                ]
            ], 488);

            try { editKeys($keys, $payInfo['message_id'], $payInfo['chat_id']); } catch (Exception $e) {
                sendMessage("⚠️ editKeys failed\n🆔 $hash_id\nError: ".$e->getMessage(), null, null, $admin);
            }

            $adminMessages = json_decode($payInfo['admins_messages'], true);
            if (is_array($adminMessages)) {
                foreach ($adminMessages as $msgInfo) {
                    if (isset($msgInfo['chat_id'], $msgInfo['message_id']) && $msgInfo['chat_id'] != $payInfo['chat_id']) {
                        try { editKeys($keys, $msgInfo['message_id'], $msgInfo['chat_id']); } catch (Exception $e) {}
                    }
                }
            }

            return;
        }

        $accountCount = (int)($payInfo['agent_count'] ?? 1);
        $accountCount = max(1, $accountCount);
        $eachPrice    = ($accountCount > 0) ? ($price / $accountCount) : $price;

        $planDetails = getPlanDetails($connection, $fid);
        $plan        = $planDetails['plan'];

        $days             = $planDetails['days'];
        $volume           = $planDetails['volume'];
        $date             = $planDetails['date'];
        $expire_microdate = $planDetails['expire_microdate'];
        $expire_date      = $planDetails['expire_date'];

        $protocol   = $plan['protocol'];
        $server_id  = (int)$plan['server_id'];
        $netType    = $plan['type'];
        $acount     = (int)$plan['acount'];
        $inbound_id = (int)$plan['inbound_id'];
        $limitip    = $plan['limitip'];
        $rahgozar   = $plan['rahgozar'];
        $customPath = $plan['custom_path'];
        $customPort = $plan['custom_port'];
        $customSni  = $plan['custom_sni'];

        ensureServerCapacity($connection, $server_id, $inbound_id, $acount, $mainValues);

        [$serverInfo, $serverConfig] = getServerInfoAndConfig($connection, $server_id);
        $srv_remark  = $serverInfo['remark'];
        $serverType  = $serverConfig['type'];
        $portType    = $serverConfig['port_type'];

        alert($mainValues['sending_config_to_user']);

        $agent_bought = (int)($payInfo['agent_bought'] ?? 0);

        $pendingUserQrs = [];
        $toInsertOrders = [];
        $lastRemarkForAdminMsg = '';

        for ($i = 1; $i <= $accountCount; $i++) {

            $uniqid = generateRandomString(42, $protocol);
            [$port] = getNextPortAndIncrease('settings/temp.txt', $portType);

            $remark = buildRemark($botState, $srv_remark, $payInfo['description'], $uid);
            $lastRemarkForAdminMsg = $remark;

            [$response, $remark, $port] = createServerAccount(
                $serverType,
                $server_id,
                $inbound_id,
                $fid,
                $remark,
                $volume,
                $days,
                $uniqid,
                $protocol,
                $port,
                $expire_microdate,
                $netType,
                $rahgozar,
                $limitip
            );

            ensureAccountCreationSucceeded($response, $serverInfo, $inbound_id, $admin);

            [$token, $vray_link, $uniqid2, $acc_text, $userKeys, $qrLink] = buildLinksAndSendQr(
                $serverType,
                $serverConfig,
                $botState,
                $botUrl,
                $server_id,
                $uniqid,
                $protocol,
                $remark,
                $port,
                $netType,
                $inbound_id,
                $rahgozar,
                $customPath,
                $customPort,
                $customSni,
                $response,
                $uid,
                $volume,
                $days,
                $buttonValues,
                $linkIndices,
                $hideConfigIfMany,
                '',
                null,
                false
            );

            $toInsertOrders[] = [
                'uid'          => $uid,
                'token'        => $token,
                'fid'          => $fid,
                'server_id'    => $server_id,
                'inbound_id'   => $inbound_id,
                'remark'       => $remark,
                'uniqid'       => $uniqid2 ?: $uniqid,
                'protocol'     => $protocol,
                'expire_date'  => $expire_date,
                'vray_link'    => $vray_link,
                'amount'       => $eachPrice,
                'date'         => $date,
                'rahgozar'     => $rahgozar,
                'agent_bought' => $agent_bought,
            ];

            $pendingUserQrs[] = [
                'uid'      => $uid,
                'qrLink'   => $qrLink,
                'acc_text' => $acc_text,
                'keys'     => $userKeys,
            ];
        }

        if (count($toInsertOrders) === 0) {
            $stmt = $connection->prepare("UPDATE `pays` SET `state`='have_sent' WHERE `hash_id`=? LIMIT 1");
            $stmt->bind_param("s", $hash_id);
            $stmt->execute();
            $stmt->close();

            sendMessage("⚠️ هیچ اکانتی ساخته نشد؛ پرداخت به حالت have_sent برگشت.\n🆔 $hash_id", null, null, $admin);
            return;
        }

        $connection->begin_transaction();
        try {
            foreach ($toInsertOrders as $o) {
                insertOrder(
                    $connection,
                    $o['uid'],
                    $o['token'],
                    $o['fid'],
                    $o['server_id'],
                    $o['inbound_id'],
                    $o['remark'],
                    $o['uniqid'],
                    $o['protocol'],
                    $o['expire_date'],
                    $o['vray_link'],
                    $o['amount'],
                    $o['date'],
                    $o['rahgozar'],
                    $o['agent_bought']
                );
            }

            decreaseServerCapacity($connection, $server_id, $inbound_id, $fid, $accountCount);

            $stmt = $connection->prepare("UPDATE `pays` SET `state`='approved', `approved_by`=? WHERE `hash_id`=? LIMIT 1");
            $stmt->bind_param("is", $from_id, $hash_id);
            $stmt->execute();
            $stmt->close();

            $connection->commit();
        } catch (Exception $e) {
            $connection->rollback();

            $stmt = $connection->prepare("UPDATE `pays` SET `state`='have_sent' WHERE `hash_id`=? LIMIT 1");
            $stmt->bind_param("s", $hash_id);
            $stmt->execute();
            $stmt->close();

            sendMessage(
                "⚠️ خطا در ثبت سفارش/کاهش ظرفیت؛ پرداخت به حالت have_sent برگشت تا دوباره تایید شود.\n🆔 $hash_id\nError: " . $e->getMessage(),
                null,
                null,
                $admin
            );
            return;
        }

        foreach ($pendingUserQrs as $pq) {
            sendQrFromLink(
                $pq['qrLink'],
                $pq['acc_text'],
                $pq['keys'],
                $botUrl,
                (int)$pq['uid'],
                '',
                null
            );
        }

        sendMessage(
            str_replace(
                ["USERID", "REMARK", "VOLUME", "DAYS", "PRICE", "HASHID"],
                [$uid, $lastRemarkForAdminMsg, $volume, $days, $price, $hash_id],
                $mainValues['sent_config_to_user']
            ),
            getMainKeys()
        );

        $keys = json_encode([
            'inline_keyboard' => [
                [['text' => "✅ تایید شد توسط $from_id", 'callback_data' => "wizwizch"]],
                [['text' => "🔄 $lastRemarkForAdminMsg",  'callback_data' => "noop"]],
                [['text' => "🆔 $hash_id",               'callback_data' => "noop_$hash_id"]],
            ]
        ], 488);

        try { editKeys($keys, $payInfo['message_id'], $payInfo['chat_id']); } catch (Exception $e) {
            sendMessage("⚠️ editKeys failed\n🆔 $hash_id\nError: ".$e->getMessage(), null, null, $admin);
        }

        $adminMessages = json_decode($payInfo['admins_messages'], true);
        if (is_array($adminMessages)) {
            foreach ($adminMessages as $msgInfo) {
                if (isset($msgInfo['chat_id'], $msgInfo['message_id']) && $msgInfo['chat_id'] != $payInfo['chat_id']) {
                    try { editKeys($keys, $msgInfo['message_id'], $msgInfo['chat_id']); } catch (Exception $e) {}
                }
            }
        }

        $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid`=?");
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $user_detail = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!empty($user_detail['refered_by'])) {
            $inviterId = (int)$user_detail['refered_by'];

            giveReferralReward($connection, $user_detail['userid'], $inviterId, $admin, $botState);

            if ($admin != $from_id) {
                $uname     = $user_detail['name'];
                $user_name = $user_detail['username'];

                $keys2 = json_encode([
                    'inline_keyboard' => [
                        [
                            ['text' => "🛍 خرید اشتراک جدید", 'callback_data' => "wizwizch"]
                        ],
                    ]
                ]);

                $msg = str_replace(
                    ['USER-ID', 'USERNAME', 'NAME', 'PRICE', 'REMARK', 'HASHID'],
                    [$uid, $user_name, $uname, $price, $lastRemarkForAdminMsg, $hash_id],
                    $mainValues['invite_buy_new_account']
                );

                sendMessage($msg, $keys2, "html", $admin);

                $rewardChannel = $botState['rewardChannel'] ?? null;
                if (!empty($rewardChannel)) {
                    sendMessage($msg, $keys2, "html", $rewardChannel);
                }
            }
        }

    } catch (Exception $e) {
        try {
            $stmt = $connection->prepare("UPDATE `pays` SET `state`='have_sent' WHERE `hash_id`=? AND `state`='processing' LIMIT 1");
            $stmt->bind_param("s", $hash_id);
            $stmt->execute();
            $stmt->close();
        } catch (Exception $ignored) {}

        sendMessage("⚠️ خطا در تایید\n🆔 $hash_id\nError: ".$e->getMessage(), null, null, $admin);
        return;
    }
}

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

if(preg_match('/sConfigRenew(\d+)/', $data,$match)){
    if($botState['sellState']=="off" && $from_id !=$admin){ alert($mainValues['bot_is_updating']); exit(); }
    
    alert($mainValues['please_wait_message']);
    $server_id = $match[1];
    if(empty($userInfo['temp'])){delMessage(); exit();}
    
    $configInfo = json_decode($userInfo['temp'],true);
    $inboundId = $configInfo['id']??0;
    $uuid = $configInfo['uuid'];
    $remark = $configInfo['remark'];

    if(isset($configInfo['marzban'])){
        $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `server_id` = ? AND `custom_sni` LIKE '%inbounds%' AND `active` = 1 AND `price` != 0 ORDER BY id ASC");
        $stmt->bind_param("i", $server_id);
    }else{
        $response = getJson($server_id)->obj;
        if($response == null){delMessage(); exit();}
        if($inboundId == 0){
            foreach($response as $row){
                $clients = json_decode($row->settings)->clients;
                if($clients[0]->id == $uuid || $clients[0]->password == $uuid) {
                    $port = $row->port;
                    $protocol = $row->protocol;
                    $configReality = json_decode($row->streamSettings)->security == "reality"?"true":"false";
                    break;
                }
            }
            $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `server_id` = ? AND `inbound_id` = 0 AND `protocol` = ? AND `active` = 1 AND `price` != 0 AND `rahgozar` = 0 ORDER BY id ASC");
        }else{
            foreach($response as $row){
                if($row->id == $inboundId) {
                    $port = $row->port;
                    $protocol = $row->protocol;
                    $configReality = json_decode($row->streamSettings)->security == "reality"?"true":"false";
                    break;
                }
            }
            $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `server_id` = ? AND `inbound_id` != 0 AND `protocol` = ? AND `active` = 1 AND `price` != 0 AND `rahgozar` = 0 ORDER BY id ASC");
        }
        $stmt->bind_param("is", $server_id, $protocol);
    }
    
    $stmt->execute();
    $plans = $stmt->get_result();
    $stmt->close();
    if($plans->num_rows > 0){
        $keyboard = [];
        while($file = $plans->fetch_assoc()){ 
            $add = false;
            
            if(isset($configInfo['marzban'])) $add = true;
            else{
                $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id` = ?");
                $stmt->bind_param("i", $server_id);
                $stmt->execute();
                $isReality = $stmt->get_result()->fetch_assoc()['reality'];
                $stmt->close();
                
                if($isReality == $configReality) $add = true;
            }
            
            if($add){
                $id = $file['id'];
                $name = $file['title'];
                $price = $file['price'];
                $price = ($price == 0) ? 'رایگان' : number_format($price).' تومان ';
                $keyboard[] = ['text' => "$name - $price", 'callback_data' => "sConfigRenewPlan{$id}_{$inboundId}"];
            }
        }
        $keyboard[] = ['text' => $buttonValues['back_to_main'], 'callback_data' => "mainMenu"];
        $keyboard = array_chunk($keyboard,1);
        editText($message_id, "3️⃣ مرحله سه

🤲 🕋 یکی از پلن هارو انتخاب کنید.
➕ اگر سرویس مورد نظر شما در لیست زیر نیست لازم هست اول اشتراک را به لیست اشتراک های خودتان اضافه کنید و بعد با زدن دکمه ← 🔄 تغییر پلن، پلن انتخاب کنید تا بتوانید اشتراک خودتان را تمدید و یا شارژ کنید. ", json_encode(['inline_keyboard'=>$keyboard]));
    }else sendMessage("💡 پلنی در این دسته بندی وجود ندارد. ");
}

if ((require __DIR__ . '/src/Subscription/ManualAccounts.php') === null) return;

if ((require __DIR__ . '/src/Subscription/OrderManagement.php') === null) return;

if(preg_match('/sConfigRenewPlan(\d+)_(\d+)/',$data, $match) && ($botState['sellState']=="on" ||$from_id ==$admin) && $text != $buttonValues['cancel']){
    $id = $match[1];
	$inbound_id = $match[2];


    if(empty($userInfo['temp'])){delMessage(); exit();}
    
    $configInfo = json_decode($userInfo['temp'],true);
    $uuid = $configInfo['uuid'];
    $remark = $configInfo['remark'];

    alert($mainValues['receving_information']);
    delMessage();
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=? and `active`=1");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $respd = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $stmt = $connection->prepare("SELECT * FROM `server_categories` WHERE `id`=?");
    $stmt->bind_param("i", $respd['catid']);
    $stmt->execute();
    $catname = $stmt->get_result()->fetch_assoc()['title'];
    $stmt->close();
    
    $name = $catname." ".$respd['title'];
    $desc = $respd['descr'];
	$sid = $respd['server_id'];
	$keyboard = array();
    $price =  $respd['price'];
    $token = base64_encode("{$from_id}.{$id}");
    
    $hash_id = RandomString();
    $stmt = $connection->prepare("DELETE FROM `pays` WHERE `user_id` = ? AND `type` = 'RENEW_SCONFIG' AND `state` = 'pending'");
    $stmt->bind_param("i", $from_id);
    $stmt->execute();
    $stmt->close();

    setUser('', 'temp');
    $description = json_encode(["uuid"=>$uuid, "remark"=>$remark, 'marzban' => isset($configInfo['marzban'])],488);
    $time = time();
    $stmt = $connection->prepare("INSERT INTO `pays` (`hash_id`, `description`, `user_id`, `type`, `plan_id`, `volume`, `day`, `price`, `request_date`, `state`)
                                VALUES (?, ?, ?, 'RENEW_SCONFIG', ?, ?, '0', ?, ?, 'pending')");
    $stmt->bind_param("ssiiiii", $hash_id, $description, $from_id, $id, $inbound_id, $price, $time);
    $stmt->execute();
    $rowId = $stmt->insert_id;
    $stmt->close();

    
    if($botState['cartToCartState'] == "on") $keyboard[] = [['text' => $buttonValues['cart_to_cart'],  'callback_data' => "payBuyWithCartToCart$hash_id"]];
    if($botState['nowPaymentOther'] == "on") $keyboard[] = [['text' => $buttonValues['now_payment_gateway'],  'url' => $botUrl . "pay/?nowpayment&hash_id=" . $hash_id]];
    if($botState['zarinpal'] == "on") $keyboard[] = [['text' => $buttonValues['zarinpal_gateway'],  'url' => $botUrl . "pay/?zarinpal&hash_id=" . $hash_id]];
    if($botState['nextpay'] == "on") $keyboard[] = [['text' => $buttonValues['nextpay_gateway'],  'url' => $botUrl . "pay/?nextpay&hash_id=" . $hash_id]];
    if($botState['weSwapState'] == "on") $keyboard[] = [['text' => $buttonValues['weswap_gateway'],  'callback_data' => "payWithWeSwap" . $hash_id]];
    if($botState['walletState'] == "on") $keyboard[] = [['text' => $buttonValues['pay_with_wallet'],  'callback_data' => "payBuyWithWallet$hash_id"]];
    if($botState['tronWallet'] == "on") $keyboard[] = [['text' => $buttonValues['tron_gateway'],  'callback_data' => "payWithTronWallet" . $hash_id]];

	$keyboard[] = [['text' => $buttonValues['back_to_main'], 'callback_data' => "mainMenu"]];
    sendMessage(str_replace(['PLAN-NAME', 'PRICE', 'DESCRIPTION'], [$name, $price, $desc], $mainValues['buy_subscription_detail']), json_encode(['inline_keyboard'=>$keyboard]), "HTML");
}

if ((require __DIR__ . '/src/Subscription/ConfigRefresh.php') === null) return;

if ((require __DIR__ . '/src/Plans/Administration.php') === null) return;
if ((require __DIR__ . '/src/Subscription/CustomerRoutes.php') === null) return;

if (preg_match('/^renewAccountAsk(\d+)$/', $data, $match)) {
    $oid = (int)$match[1];

    $keyboard = json_encode([
        'inline_keyboard' => [
            [
                ['text' => "✅ تأیید تمدید", 'callback_data' => "renewAccount{$oid}"]
            ],
            [
                ['text' => "❌ انصراف", 'callback_data' => "mySubscriptions"]
            ]
        ]
    ]);

    sendMessage("⚠️ دقت کنید با تمدید اشتراک، در صورتی که حجم باقی مانده داشته باشید منتقل نمی شود.\n✅ می توانید کیف پول خود را شارژ کنید و گزینه تمدید خودکار را روشن کنید تا هر زمان اشتراک شما تمام شد خودکار تمدید شود.\n🔰 در صورت تمایل به ادامه روی دکمه «تایید تمدید» بزنید.", $keyboard);
    exit();
}

if (
    (
        preg_match('/^discountRenew(\d+)_(\d+)$/', $userInfo['step'], $match) ||
        preg_match('/^renewAccount(\d+)$/', $data, $match)
    ) &&
    $text != $buttonValues['cancel']
) {
    
    // اضافه کردن بررسی برای ستون fileid
    $oid = $match[1];
    
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $order = $stmt->get_result();
    $stmt->close();
    
    if($order->num_rows == 0){
        delMessage();
        sendMessage($mainValues['config_not_found'], getMainKeys());
        exit();
    }
    
    $order = $order->fetch_assoc();
    $fid = $order['fileid'];
    // بررسی اگر fileid برابر 0 باشد
    if($fid == 0){
        sendMessage("✋ لطفاً ابتدا با زدن دکمه: 
        ← 🔄 تغییر پلن
یک پلن برای اشتراک خود انتخاب کنید و بعد اقدام به تمدید نمایید.");
        exit();
    }
	
	$serverId = $order['server_id'];
    $agentBought = $order['agent_bought'];

    if(preg_match('/^discountRenew/', $userInfo['step'])){
        $rowId = $match[2];
        
        // استفاده از تابع مشترک تخفیف
        $discountResult = applyDiscountToPay(
            $connection,
            $rowId,
            $text,
            $from_id,
            $username,
            $first_name,
            $admin,
            $mainValues,
            $botState
        );

        // قیمت بعد از تخفیف (یا همان قیمت قبلی در صورت نامعتبر بودن کد)
        $afterDiscount = $discountResult['price'];
		$hash_id = $discountResult['hash_id'];

        setUser();
    }else delMessage();

    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id` = ? AND `active` = 1");
    $stmt->bind_param("i", $fid);
    $stmt->execute();
    $respd = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $price = $respd['price'];
    if($agentBought == true){
        $discounts = json_decode($userInfo['discount_percent'],true);
        if($botState['agencyPlanDiscount']=="on") $discount = $discounts['plans'][$fid]?? $discounts['normal'];
        else $discount = $discounts['servers'][$serverId]?? $discounts['normal'];
        $price -= floor($price * $discount / 100);
    }
    if(!preg_match('/^discountRenew/', $userInfo['step'])){
        $hash_id = RandomString();
        $stmt = $connection->prepare("DELETE FROM `pays` WHERE `user_id` = ? AND `type` = 'RENEW_ACCOUNT' AND `state` = 'pending'");
        $stmt->bind_param("i", $from_id);
        $stmt->execute();
        $stmt->close();
        
        $time = time();
		$stmt = $connection->prepare("INSERT INTO `pays` (`hash_id`, `user_id`, `type`, `plan_id`, `server_id`, `volume`, `day`, `price`, `request_date`, `state`, `order_id`)
									VALUES (?, ?, 'RENEW_ACCOUNT', ?, ?, '0', '0', ?, ?, 'pending', ?)");
		$stmt->bind_param("siiiiii", $hash_id, $from_id, $fid, $serverId, $price, $time, $oid);
        $stmt->execute();
        $rowId = $stmt->insert_id;
        $stmt->close();
    }else $price = $afterDiscount;

    if($price == 0) $price = "رایگان";
    else $price .= " تومان";
	
	$keyboard = [];
	$actionType = "Renew";
	if ($botState['cartToCartState'] == "on") $keyboard[] = [['text' => "💳 کارت به کارت مبلغ $price", 'callback_data' => "prePayCheck_{$actionType}_CartToCart_{$hash_id}"]];
	if ($botState['nowPaymentOther'] == "on") $keyboard[] = [['text' => $buttonValues['now_payment_gateway'], 'callback_data' => "prePayCheck_{$actionType}_nowpayment_{$hash_id}"]];
	if ($botState['zarinpal'] == "on") $keyboard[] = [['text' => $buttonValues['zarinpal_gateway'], 'callback_data' => "prePayCheck_{$actionType}_zarinpal_{$hash_id}"]];
	if ($botState['nextpay'] == "on") $keyboard[] = [['text' => $buttonValues['nextpay_gateway'], 'callback_data' => "prePayCheck_{$actionType}_nextpay_{$hash_id}"]];
	if ($botState['weSwapState'] == "on") $keyboard[] = [['text' => $buttonValues['weswap_gateway'], 'callback_data' => "prePayCheck_{$actionType}_WeSwap_{$hash_id}"]];
	if ($botState['walletState'] == "on") $keyboard[] = [['text' => "💰پرداخت با موجودی مبلغ $price", 'callback_data' => "prePayCheck_{$actionType}_Wallet_{$hash_id}"]];
	if ($botState['tronWallet'] == "on") $keyboard[] = [['text' => $buttonValues['tron_gateway'], 'callback_data' => "prePayCheck_{$actionType}_TronWallet_{$hash_id}"]];
	if (!preg_match('/^discountRenew/', $userInfo['step'])) $keyboard[] = [['text' => " 🎟 افزودن کد تخفیف ", 'callback_data' => "haveDiscountRenew_{$match[1]}_{$rowId}"]];
	
	$keyboard[] = [['text' => $buttonValues['cancel'], 'callback_data' => "mainMenu"]];
	
	sendMessage("🔰 لطفا یکی از روش های زیر را انتخاب کنید و روی آن بزنید:", json_encode(['inline_keyboard' => $keyboard]));
}

if (preg_match('/payRenewWithCartToCart(.*)/', $data, $match)) {

    $payInfo = dbFetchPayByHash($connection, $match[1]);
    if (!$payInfo) {
        prepareStartFallback();
        delMessage();
        goto GOTOSTART;
    }

    $oid = (int)$payInfo['order_id'];

    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $orderRes = $stmt->get_result();
    $stmt->close();

    if (!$orderRes || $orderRes->num_rows == 0) {
        delMessage();
        sendMessage($mainValues['config_not_found'], getMainKeys());
        exit;
    }

    delMessage();
    setUser("payRenewWithCartToCart{$match[1]}");

	// $msg = buildCartToCartInfoMessage($paymentKeys, $mainValues, (int)$payInfo['price']);
	$msg = buildCartToCartInfoMessage($paymentKeys, $mainValues, (int)$payInfo['price'], (int)$payInfo['user_id']);
    $keyboard = kbSendReceiptAndCancel("sendRenewCartReceipt", "cancelRenewCartReceipt", $match[1]);

    sendMessage($msg, $keyboard, "HTML");
    exit;
}

if (preg_match('/sendRenewCartReceipt(?<hashId>.*)/', $data, $match)) {

    $payInfo = dbFetchPayByHash($connection, $match['hashId']);
    if (!$payInfo) {
        prepareStartFallback();
        delMessage();
        goto GOTOSTART;
    }

    $guardMsg = duplicateReceiptGuard($connection, $payInfo, time());
    if ($guardMsg) { alert($guardMsg, true); exit; }

    delMessage();
    setUser("waitRenewCartReceipt{$match['hashId']}");

    $keyboard = kbOnlyCancel("cancelRenewCartReceipt", $match['hashId']);
    sendMessage($mainValues['please_send_receipt_image'], $keyboard, "HTML");
    exit;
}

if (preg_match('/cancelRenewCartReceipt(?<hashId>.*)/', $data, $match)) {
    handleCancelFlow($mainValues, $removeKeyboard);
}

if (preg_match('/waitRenewCartReceipt(.*)/', $userInfo['step'], $match)) {

    $fileid = extractPhotoFileId($update);
    if (!$fileid) {
        $keyboard = kbOnlyCancel("cancelRenewCartReceipt", $match[1]);
        sendMessage($mainValues['please_send_only_image'], $keyboard, "HTML");
        exit;
    }

    $payInfo = dbFetchPayByHash($connection, $match[1]);
    if (!$payInfo) {
        prepareStartFallback();
        delMessage();
        goto GOTOSTART;
    }

    $hash_id = $payInfo['hash_id'];

    $now = time();
    $requestTime = (int)$payInfo['request_date'];
    if ($now - $requestTime > 10800) {
        delMessage();
        sendMessage("⏳ مهلت این خرید به پایان رسیده است.\n♻️ لطفاً مجدداً روی دکمه تمدید اشتراک کلیک کرده و خرید جدیدی ثبت کنید.");
        setUser();
        sendMessage($mainValues['reached_main_menu'], getMainKeys());
        exit;
    }

    $oid = (int)$payInfo['order_id'];

    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $orderRes = $stmt->get_result();
    $stmt->close();

    if (!$orderRes || $orderRes->num_rows == 0) {
        delMessage();
        sendMessage($mainValues['config_not_found'], getMainKeys());
        exit;
    }

    $order = $orderRes->fetch_assoc();

    $fid = (int)$order['fileid'];
    $savedFid = (int)$payInfo['plan_id'];

    if ($fid != $savedFid) {
        delMessage();
        sendMessage("❗️پلن شما از زمان شروع پرداخت تا ارسال رسید تغییر کرده است.\n♻️ لطفاً دوباره روی دکمه تمدید اشتراک کلیک کرده و پرداخت جدید انجام دهید.");
        setUser();
        sendMessage($mainValues['reached_main_menu'], getMainKeys());
        exit;
    }

    $remark = $order['remark'];

    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id` = ? AND `active` = 1");
    $stmt->bind_param("i", $fid);
    $stmt->execute();
    $respd = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $price  = $payInfo['price'];
    $volume = $respd['volume'];
    $days   = $respd['days'];

    sendWithStartButton($mainValues['order_submitted'], $buttonValues['start_bot'], $botUsername, 'mainMenu', 'HTML');

    $msg = str_replace(
        ['TYPE', "USER-ID", "USERNAME", "NAME", "PRICE", "REMARK", "VOLUME", "DAYS"],
        ['کارت به کارت', $from_id, $username, $first_name, $price, $remark, $volume, $days],
        $mainValues['renew_account_request_message']
    );

    $adminKeyboard = buildAdminKeyboardWithStats(
        $connection,
        $buttonValues,
        "approveRenewAcc{$hash_id}",
        "declineAction_{$hash_id}",
        $hash_id,
        (int)$payInfo['user_id']
    );

    $resPhoto = sendPhoto($fileid, $msg, $adminKeyboard, "HTML", $admin);
    sendToAllAdmins('sendPhoto', [
        'photo' => $fileid,
        'caption' => $msg,
        'reply_markup' => $adminKeyboard,
        'parse_mode' => "HTML"
    ], $match[1]);

    $msgId = $resPhoto->result->message_id ?? null;
    setUser();

    if ($msgId) {
        $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'have_sent', `message_id` = ?, `chat_id` = ? WHERE `hash_id` = ?");
        $stmt->bind_param("iis", $msgId, $admin, $match[1]);
        $stmt->execute();
        $stmt->close();
    }

    exit;
}

if(preg_match('/approveRenewAcc(.*)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){ 
    $connection->begin_transaction();
    try {
        $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ? FOR UPDATE"); 
        $stmt->bind_param("s", $match[1]); 
        $stmt->execute(); 
        $payInfo = $stmt->get_result()->fetch_assoc(); 
        $stmt->close(); 

        if (!$payInfo || ($payInfo['state'] === 'expired_have_sent')) { 
            $connection->rollback();
            alert(!$payInfo ? "⛔️ اطلاعات پرداخت پیدا نشد." : "⛔️ این درخواست منقضی شده است."); 
            return; 
        } 

        if ($payInfo['state'] === 'approved' || $payInfo['state'] === 'declined') { 
            $connection->rollback();
            alert("⛔️ این درخواست قبلاً بررسی شده است."); 
            return; 
        } 

        $hash_id = $payInfo['hash_id']; 
        $uid     = intval($payInfo['user_id']); 
        $oid     = intval($payInfo['order_id']); 
        $price   = intval($payInfo['price']);

        $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?"); 
        $stmt->bind_param("i", $oid); 
        $stmt->execute(); 
        $order = $stmt->get_result()->fetch_assoc(); 
        $stmt->close(); 

        if (!$order){
            $connection->rollback();
            delMessage();
            sendMessage($mainValues['config_not_found'], getMainKeys(), null, $uid);
            return;
        }

        $fid        = intval($order['fileid']); 
        $remark     = $order['remark']; 
        $uuid       = $order['uuid'] ?? "0"; 
        $server_id  = intval($order['server_id']); 
        $inbound_id = intval($order['inbound_id']); 

        $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id` = ? AND `active` = 1"); 
        $stmt->bind_param("i", $fid); 
        $stmt->execute(); 
        $respd = $stmt->get_result()->fetch_assoc(); 
        $stmt->close(); 
        if(!$respd){
            $connection->rollback();
            sendMessage($mainValues['config_not_found'], getMainKeys(), null, $uid);
            return;
        }
        $name   = $respd['title']; 
        $days   = intval($respd['days']); 
        $volume = intval($respd['volume']); 

        $stmt = $connection->prepare("SELECT * FROM server_config WHERE id = ?"); 
        $stmt->bind_param("i", $server_id); 
        $stmt->execute(); 
        $server_info = $stmt->get_result()->fetch_assoc(); 
        $stmt->close(); 
        if(!$server_info){
            throw new Exception("server_config_missing");
        }
        $serverType = $server_info['type']; 

        $deferredAdminKeys = [
            'chat_id'    => $payInfo['chat_id'],
            'message_id' => $payInfo['message_id'],
            'keys'       => json_encode([
                'inline_keyboard' => [
                    [['text' => "✅ تایید شد توسط $from_id", 'callback_data' => "wizwizch"]],
                    [['text' => "🆔 $hash_id", 'callback_data' => "noop_$hash_id"]]
                ]
            ], 488),
            'admins_messages' => $payInfo['admins_messages']
        ];

        if ($serverType == "marzban") { 
            $response = editMarzbanConfig($server_id, ['remark'=>$remark, 'days'=>$days, 'volume' => $volume]); 
        } else { 
            if ($inbound_id > 0) 
                $response = editClientTraffic($server_id, $inbound_id, $uuid, $volume, $days, "renew"); 
            else 
                $response = editInboundTraffic($server_id, $uuid, $volume, $days, "renew"); 
        } 

        if (is_null($response)) { 
            throw new Exception('server_connection_null'); 
        } 
        if (!is_object($response) && !is_array($response)) {
            throw new Exception('invalid_response_type');
        }
        $success = (is_object($response) && !empty($response->success))
                || (is_array($response)  && !empty($response['success']));
        if (!$success) {
            $msg = is_object($response) ? ($response->msg ?? 'no_msg')
                 : ($response['msg'] ?? 'no_msg');
            throw new Exception("server_response_failed: ".$msg);
        }

        // آپدیت expire + notif
        $newExpire = time() + $days * 86400; 
        $stmt = $connection->prepare("UPDATE `orders_list` SET `expire_date` = ?, `notif` = 0 WHERE `id` = ?"); 
        $stmt->bind_param("ii", $newExpire, $oid); 
        $stmt->execute(); 
        $stmt->close(); 

        // ثبت در increase_order
        $now = time();
        $stmt = $connection->prepare("INSERT INTO `increase_order` (`userid`, `server_id`, `inbound_id`, `remark`, `amount`, `date`) VALUES (?, ?, ?, ?, ?, ?)"); 
        $stmt->bind_param("iiisii", $uid, $server_id, $inbound_id, $remark, $price, $now); 
        $stmt->execute(); 
        $stmt->close(); 

        // پرداخت را نهایتاً approved کن (فقط پس از موفقیت همه مراحل)
        $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'approved', `approved_by` = ? WHERE `hash_id` = ?"); 
        $stmt->bind_param("is", $from_id, $hash_id); 
        $stmt->execute(); 
        $stmt->close(); 

        // COMMIT
        $connection->commit();

        editKeys($deferredAdminKeys['keys'], $deferredAdminKeys['message_id'], $deferredAdminKeys['chat_id']); 
        // ویرایش پیام سایر ادمین‌ها
        $adminMessages = json_decode($deferredAdminKeys['admins_messages'], true); 
        if (is_array($adminMessages)) { 
            foreach ($adminMessages as $msgInfo) { 
                if (isset($msgInfo['chat_id'], $msgInfo['message_id']) && $msgInfo['chat_id'] != $deferredAdminKeys['chat_id']) { 
                    editKeys($deferredAdminKeys['keys'], $msgInfo['message_id'], $msgInfo['chat_id']); 
                } 
            } 
        } 

        // پیام موفقیت به کاربر
        sendMessage(str_replace(["USERID", "REMARK", "VOLUME", "DAYS", "PRICE", "HASHID"],
                    [$uid, $remark, $volume, $days, $price, $hash_id], $mainValues['renewed_config_to_user']),
                    getMainKeys(), null, null); 
        sendMessage("✅ اشتراک <code>$remark</code> با موفقیت تمدید شد.", null, "HTML", $uid); 
        return;

    } catch (Exception $e) {
        $connection->rollback();

        $err = "⚠️ خطا در تایید تمدید کارت‌به‌کارت\n\n"
             . "🆔 Hash ID: {$match[1]}\n"
             . "👤 Admin: {$from_id}\n"
             . "📄 Error: " . $e->getMessage();
        sendMessage($err, null, null, $admin);

        alert('❌ تمدید انجام نشد. لطفاً وضعیت سرور یا اطلاعات را بررسی کنید.', true);
        return;
    }
}

if(preg_match('/payRenewWithWallet(.*)/', $data, $match)){
    $connection->begin_transaction();
    try {
        $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ? FOR UPDATE");
        $stmt->bind_param("s", $match[1]);
        $stmt->execute();
        $payRes = $stmt->get_result();
        $stmt->close();

        if ($payRes->num_rows == 0){
            $connection->rollback();
            $text = "/start";
            $data = "";
            delMessage();
            goto GOTOSTART;
        }

        $payInfo = $payRes->fetch_assoc();
        $hash_id   = $payInfo['hash_id'];
        $oid       = intval($payInfo['order_id']);
        $price     = intval($payInfo['price']);
        $uid       = intval($payInfo['user_id']);

        if ($payInfo['state'] === "paid_with_wallet") {
            $connection->rollback();
            return;
        }

        $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
        $stmt->bind_param("i", $oid);
        $stmt->execute();
        $orderRes = $stmt->get_result();
        $stmt->close();

        if ($orderRes->num_rows == 0){
            $connection->rollback();
            delMessage();
            sendMessage($mainValues['config_not_found'], getMainKeys(), null, $uid);
            return;
        }

        $order = $orderRes->fetch_assoc();
        $fid         = intval($order['fileid']);
        $remark      = $order['remark'];
        $uuid        = $order['uuid'] ?? "0";
        $server_id   = intval($order['server_id']);
        $inbound_id  = intval($order['inbound_id']);

        $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id` = ? AND `active` = 1");
        $stmt->bind_param("i", $fid);
        $stmt->execute();
        $plan = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$plan){
            $connection->rollback();
            sendMessage($mainValues['config_not_found'], getMainKeys(), null, $uid);
            return;
        }

        $name   = $plan['title'];
        $days   = intval($plan['days']);
        $volume = intval($plan['volume']);

        $stmt = $connection->prepare("SELECT `wallet` FROM `users` WHERE `userid` = ? FOR UPDATE");
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $wRes = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $userwallet = intval($wRes['wallet'] ?? 0);
        if ($userwallet < $price) {
            $needamount = $price - $userwallet;

            $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'low_wallet' WHERE `hash_id` = ?");
            $stmt->bind_param("s", $hash_id);
            $stmt->execute();
            $stmt->close();

            $connection->commit(); // همین تغییر state ثبت شود
            alert("❌ موجودی کیف پول کافی نیست.\n💵 موجودی فعلی: " . number_format($userwallet) . " تومان\n🔺 مورد نیاز: " . number_format($needamount) . " تومان", true);
            return;
        }

        $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $server_info = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$server_info){
            throw new Exception("server_config_missing");
        }

        $serverType = $server_info['type'];

        if($serverType == "marzban"){
            $response = editMarzbanConfig($server_id, ['remark'=>$remark, 'days'=>$days, 'volume' => $volume]);
        } else {
            if($inbound_id > 0)
                $response = editClientTraffic($server_id, $inbound_id, $uuid, $volume, $days, "renew");
            else
                $response = editInboundTraffic($server_id, $uuid, $volume, $days, "renew");
        }

        if (is_null($response)) {
            throw new Exception("server_null_response");
        }
        if (!is_object($response) && !is_array($response)) {
            throw new Exception("invalid_response_type");
        }
        $success = (is_object($response) && !empty($response->success))
                || (is_array($response)  && !empty($response['success']));
        if (!$success) {
            $msg = is_object($response) ? ($response->msg ?? 'no_msg')
                 : ($response['msg'] ?? 'no_msg');
            throw new Exception("server_response_failed: ".$msg);
        }

        $timeNow  = time();
        $newExpire = $timeNow + ($days * 86400);
        $stmt = $connection->prepare("UPDATE `orders_list` SET `expire_date` = ?, `notif` = 0 WHERE `id` = ?");
        $stmt->bind_param("ii", $newExpire, $oid);
        $stmt->execute();
        $stmt->close();

        $stmt = $connection->prepare("INSERT INTO `increase_order` (`userid`, `server_id`, `inbound_id`, `remark`, `amount`, `date`) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iiisii", $uid, $server_id, $inbound_id, $remark, $price, $timeNow);
        $stmt->execute();
        $stmt->close();

        $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` - ? WHERE `userid` = ?");
        $stmt->bind_param("ii", $price, $uid);
        $stmt->execute();
        $stmt->close();

        $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'paid_with_wallet' WHERE `hash_id` = ?");
        $stmt->bind_param("s", $hash_id);
        $stmt->execute();
        $stmt->close();

        $connection->commit();
        editText($message_id, "✅ اشتراک $remark با موفقیت تمدید شد.", getMainKeys());

        $keys = json_encode(['inline_keyboard'=>[
            [
                ['text'=>"♻️ تمدید اشتراک",'callback_data'=>"wizwizch"]
            ],
            [
                ['text'=>"🆔 $hash_id", 'callback_data'=>"noop_$hash_id"]
            ]
        ]]);

        $msg = str_replace(
            ['TYPE', "USER-ID", "USERNAME", "NAME", "PRICE", "REMARK", "VOLUME", "DAYS"],
            ['کیف پول', $from_id, $username, $first_name, $price, $remark, $volume, $days],
            $mainValues['renew_account_request_message']
        );
        sendMessage($msg, $keys, "html", $admin);
		//sendToAllAdmins('sendMessage', ['text' => $msg, 'reply_markup' => $keys, 'parse_mode' => "html"]);
		
		// ارسال پیام به کانال گزارشات
        $rewardChannel = $botState['rewardChannel'];
        if (!empty($rewardChannel)) {
            sendMessage($msg, $keys, "html", $rewardChannel);
        }

        return;

    } catch (Exception $e) {
        $connection->rollback();

        // پیام فنی به ادمین
        $adminErr = "⚠️ خطا در تمدید با کیف پول\n\n"
                  . "🆔 Hash ID: {$match[1]}\n"
                  . "👤 User: {$from_id}\n"
                  . "📄 Error: ".$e->getMessage();
        sendMessage($adminErr, null, null, $admin);

        sendMessage("❌ مشکلی پیش اومد.\n🔁 لطفاً ۳۰ ثانیه بعد دوباره تلاش کنید.\nاگر مشکل ادامه داشت، به مدیریت اطلاع دهید.", null, null, $uid);

        return;
    }
}if ((require __DIR__ . '/src/Subscription/AccountTransfers.php') === null) return;

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
if(preg_match('/^giftToUser(\d+)/', $data, $match) && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    sendMessage("نام کاربری (remark) کاربر موردنظر را وارد کن:", $cancelKey);
    setUser("giftToUserRemark" . $match[1]);
}

if(preg_match('/^giftToUserRemark(\d+)/', $userInfo['step'], $match) && $text != $buttonValues['cancel']){
    $sid = $match[1];
    $remark = trim($text);
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `remark` = ? AND `server_id` = ?");
    $stmt->bind_param("si", $remark, $sid);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();
    if($result->num_rows > 0){
        setUser("giftUserDay{$sid}_{$remark}");
        sendMessage("مدت زمان هدیه را به روز وارد کن (برای صفر، عدد 0 را وارد کن):");
    } else {
        sendMessage("هیچ کاربری با این نام کاربری در این سرور پیدا نشد.");
    }
}

if(preg_match('/^giftUserDay(\d+)_(.*)/', $userInfo['step'], $match) && $text != $buttonValues['cancel']){
    if(is_numeric($text) && $text >= 0){
        setUser("giftUserVolume{$match[1]}_{$match[2]}_{$text}");
        sendMessage("حجم هدیه را به مگابایت وارد کن (برای صفر، عدد 0 را وارد کن):");
    } else {
        sendMessage("فقط عدد معتبر وارد کن (0 یا بالاتر)." );
    }
}

if(preg_match('/^giftUserVolume(\d+)_(.+)_(\d+)/', $userInfo['step'], $match) && $text != $buttonValues['cancel']){
    if(is_numeric($text) && $text >= 0){
        $sid = $match[1];
        $remark = $match[2];
        $day = $match[3];
        $volume = $text;

        $editReponse = editMarzbanConfig($sid, [
            'remark' => $remark,
            'plus_volume' => $volume / 1024,
            'plus_day' => $day
        ]);

        if(!is_null($editReponse)){
            $expire_date = $day * 86400;
            $stmt = $connection->prepare("UPDATE `orders_list` SET `expire_date` = `expire_date` + ? WHERE `server_id` = ? AND `remark` = ?");
            $stmt->bind_param("iis", $expire_date, $sid, $remark);
            $stmt->execute();
            $stmt->close();

            sendMessage("🎉 هدیه با موفقیت به کاربر $remark داده شد.", $removeKeyboard);
        } else {
            sendMessage("❌ خطا در اعمال هدیه، لطفا بررسی کن.");
        }

        sendMessage($mainValues['reached_main_menu'], getMainKeys());
        setUser();
    } else {
        sendMessage("فقط عدد معتبر وارد کن (0 یا بالاتر)." );
    }
}
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
            $stmt = $connection->prepare("INSERT INTO `gift_list` (`server_id`, `volume`, `day`) VALUES (?, ?, ?)");
            $stmt->bind_param("iii", $match[1], $text, $match[2]);
            $stmt->execute();
            $stmt->close();
            
            sendMessage($mainValues['saved_successfuly'],$removeKeyboard);
            sendMessage($mainValues['reached_main_menu'],getMainKeys());

            setUser();
        }else sendMessage("عددی بزرگتر و یا مساوی 0 واردکنید.");
    }else sendMessage($mainValues['send_only_number']);
}
else {
    require __DIR__ . '/src/Subscription/AccountDeletion.php';
}
if(preg_match('/increaseADay(.*)/', $data, $match)){
    $stmt = $connection->prepare("SELECT * FROM `increase_day`");
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $orderInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $agentBought = $orderInfo['agent_bought'];

    if($res->num_rows == 0){
        alert("در حال حاضر هیچ پلنی برای افزایش مدت زمان اشتراک وجود ندارد.");
        exit;
    }
    $keyboard = [];
    while ($cat = $res->fetch_assoc()){
        $id = $cat['id'];
        $title = $cat['volume'];
        $price = $cat['price'];
        if($agentBought == true){
            $discounts = json_decode($userInfo['discount_percent'],true);
            if($botState['agencyPlanDiscount']=="on") $discount = $discounts['plans'][$orderInfo['fileid']]?? $discounts['normal'];
            else $discount = $discounts['servers'][$orderInfo['server_id']]?? $discounts['normal'];
            $price -= floor($price * $discount / 100);
        }
        if($price == 0) $price = "رایگان";
        else $price = number_format($price) . " تومان";
        $keyboard[] = ['text' => "$title روز $price", 'callback_data' => "selectPlanDayIncrease{$match[1]}_$id"];
    }
    $keyboard = array_chunk($keyboard,2);
    $keyboard[] = [['text' => $buttonValues['back_to_main'], 'callback_data' => "mainMenu"]];
    editText($message_id, "لطفا یکی از پلن های افزایشی را انتخاب کنید:", json_encode([
            'inline_keyboard' => $keyboard
        ]));
}
if(preg_match('/selectPlanDayIncrease(?<orderId>.+)_(?<dayId>.+)/',$data,$match)){
    $data = str_replace('selectPlanDayIncrease','',$data);
    $pid = $match['dayId'];
    $stmt = $connection->prepare("SELECT * FROM `increase_day` WHERE `id` = ?");
    $stmt->bind_param("i", $pid);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $planprice = $res['price'];
    
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $match['orderId']);
    $stmt->execute();
    $orderInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $agentBought = $orderInfo['agent_bought'];
    
    if($agentBought == true){
        $discounts = json_decode($userInfo['discount_percent'],true);
        if($botState['agencyPlanDiscount']=="on") $discount = $discounts['plans'][$orderInfo['fileid']]?? $discounts['normal'];
        else $discount = $discounts['servers'][$orderInfo['server_id']]?? $discounts['normal'];

        $planprice -= floor($planprice * $discount / 100);
    }
    
    
    $hash_id = RandomString();
    $stmt = $connection->prepare("DELETE FROM `pays` WHERE `user_id` = ? AND `type` LIKE '%INCREASE_DAY%' AND `state` = 'pending'");
    $stmt->bind_param("i", $from_id);
    $stmt->execute();
    $stmt->close();
	
    $time = time();
    $plan_id = $orderInfo['fileid'];
	$server_id = $orderInfo['server_id'];
	$order_id = $match['orderId'];

	$stmt = $connection->prepare("INSERT INTO `pays` (`hash_id`, `user_id`, `type`, `plan_id`, `server_id`, `order_id`, `volume`, `day`, `price`, `request_date`, `state`)
								VALUES (?, ?, ?, ?, ?, ?, '0', '0', ?, ?, 'pending')");
    $type = "INCREASE_DAY_$data";
	$stmt->bind_param("sisiiiii", $hash_id, $from_id, $type, $plan_id, $server_id, $order_id, $planprice, $time);
    $stmt->execute();
    $stmt->close();

    
    $keyboard = array();
    if($botState['cartToCartState'] == "on") $keyboard[] = [['text' => $buttonValues['cart_to_cart'],  'callback_data' => "payIncreaseDayWithCartToCart$hash_id"]];
    if($botState['nowPaymentOther'] == "on") $keyboard[] = [['text' => $buttonValues['now_payment_gateway'],  'url' => $botUrl . "pay/?nowpayment&hash_id=" . $hash_id]];
    if($botState['zarinpal'] == "on") $keyboard[] = [['text' => $buttonValues['zarinpal_gateway'],  'url' => $botUrl . "pay/?zarinpal&hash_id=" . $hash_id]];
    if($botState['nextpay'] == "on") $keyboard[] = [['text' => $buttonValues['nextpay_gateway'],  'url' => $botUrl . "pay/?nextpay&hash_id=" . $hash_id]];
    if($botState['weSwapState'] == "on") $keyboard[] = [['text' => $buttonValues['weswap_gateway'],  'callback_data' => "payWithWeSwap" . $hash_id]];
    if($botState['walletState'] == "on") $keyboard[] = [['text' => $buttonValues['pay_with_wallet'],  'callback_data' => "payIncreaseDayWithWallet$hash_id"]];
    if($botState['tronWallet'] == "on") $keyboard[] = [['text' => $buttonValues['tron_gateway'],  'callback_data' => "payWithTronWallet" . $hash_id]];

    $keyboard[] = [['text'=>$buttonValues['cancel'], 'callback_data'=> "mainMenu"]];
    editText($message_id, "🔰 لطفا یکی از روش های زیر را انتخاب کنید و روی آن بزنید:",json_encode(['inline_keyboard' => $keyboard]));
}

if (preg_match('/payIncreaseDayWithCartToCart(.*)/', $data, $match)) {

    $payInfo = dbFetchPayByHash($connection, $match[1]);
    if (!$payInfo) {
        prepareStartFallback();
        delMessage();
        goto GOTOSTART;
    }

    delMessage();
    setUser("payIncreaseDayWithCartToCart{$match[1]}");

	// $msg = buildCartToCartInfoMessage($paymentKeys, $mainValues, (int)$payInfo['price']);
	$msg = buildCartToCartInfoMessage($paymentKeys, $mainValues, (int)$payInfo['price'], (int)$payInfo['user_id']);
    $keyboard = kbSendReceiptAndCancel("sendIncreaseDayReceipt", "cancelIncreaseDayReceipt", $match[1]);

    sendMessage($msg, $keyboard, "HTML");
    exit;
}

if (preg_match('/sendIncreaseDayReceipt(?<hashId>.*)/', $data, $match)) {

    $payInfo = dbFetchPayByHash($connection, $match['hashId']);
    if (!$payInfo) {
        prepareStartFallback();
        delMessage();
        goto GOTOSTART;
    }

    $guardMsg = duplicateReceiptGuard($connection, $payInfo, time());
    if ($guardMsg) { alert($guardMsg, true); exit; }

    delMessage();
    setUser("waitIncreaseDayReceipt{$match['hashId']}");

    $keyboard = kbOnlyCancel("cancelIncreaseDayReceipt", $match['hashId']);
    sendMessage($mainValues['please_send_receipt_image'], $keyboard, "HTML");
    exit;
}

if (preg_match('/cancelIncreaseDayReceipt(?<hashId>.*)/', $data, $match)) {
    handleCancelFlow($mainValues, $removeKeyboard);
}

if (preg_match('/waitIncreaseDayReceipt(.*)/', $userInfo['step'], $match)) {

    $fileid = extractPhotoFileId($update);
    if (!$fileid) {
        $keyboard = kbOnlyCancel("cancelIncreaseDayReceipt", $match[1]);
        sendMessage($mainValues['please_send_only_image'], $keyboard, "HTML");
        exit;
    }

    $payInfoRes = dbFetchPayByHash($connection, $match[1]);
    if (!$payInfoRes) {
        prepareStartFallback();
        delMessage();
        goto GOTOSTART;
    }

    $payType = $payInfoRes['type'];

    preg_match('/^INCREASE_DAY_(\d+)_(\d+)/', $payType, $increaseInfo);
    $orderId = (int)$increaseInfo[1];
    $planid  = (int)$increaseInfo[2];

    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $orderInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $remark = $orderInfo['remark'];

    $stmt = $connection->prepare("SELECT * FROM `increase_day` WHERE `id` = ?");
    $stmt->bind_param("i", $planid);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $price  = $payInfoRes['price'];
    $volume = $res['volume'];

    sendWithStartButton($mainValues['order_submitted'], $buttonValues['start_bot'], $botUsername, 'mainMenu', 'HTML');

    $msg = str_replace(
        ['INCREASE', 'TYPE', "USER-ID", "USERNAME", "NAME", "PRICE", "REMARK"],
        [$volume, 'زمان', $from_id, $username, $first_name, $price, $remark],
        $mainValues['increase_account_request_message']
    );

    $adminKeyboard = buildAdminKeyboardWithStats(
        $connection,
        $buttonValues,
        "approveIncreaseDay{$match[1]}",
        "declineAction_{$match[1]}",
        $match[1],
        (int)$payInfoRes['user_id']
    );

    sendReceiptToAdminsAndUpdatePay($connection, $fileid, $msg, $adminKeyboard, $admin, $match[1]);
    setUser();
    exit;
}

if (preg_match('/approveIncreaseDay(.*)/', $data, $match) && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $connection->begin_transaction();
    try {
        $hash_id = $match[1];

        $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ? FOR UPDATE");
        $stmt->bind_param("s", $hash_id);
        $stmt->execute();
        $payParam = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$payParam || ($payParam['state'] === 'expired_have_sent')) {
            $connection->rollback();
            alert(!$payParam ? "⛔️ اطلاعات پرداخت پیدا نشد." : "⛔️ این درخواست منقضی شده است.");
            return;
        }

        if ($payParam['state'] === 'approved' || $payParam['state'] === 'declined') {
            $connection->rollback();
            alert("⛔️ این درخواست قبلاً بررسی شده است.");
            return;
        }

        $payType = $payParam['type'];

        if (!preg_match('/^INCREASE_DAY_(\d+)_(\d+)/', $payType, $increaseInfo)) {
            throw new Exception("invalid_increase_type");
        }
        $orderId = intval($increaseInfo[1]);
        $planid  = intval($increaseInfo[2]);
        $uid     = intval($payParam['user_id']);

        $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
        $stmt->bind_param("i", $orderId);
        $stmt->execute();
        $orderInfo = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$orderInfo) {
            throw new Exception("order_not_found");
        }

        $server_id  = intval($orderInfo['server_id']);
        $inbound_id = intval($orderInfo['inbound_id']);
        $remark     = $orderInfo['remark'];
        $uuid       = $orderInfo['uuid'] ?? "0";

        $stmt = $connection->prepare("SELECT * FROM `increase_day` WHERE `id` = ?");
        $stmt->bind_param("i", $planid);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$res) {
            throw new Exception("increase_day_plan_not_found");
        }

        $price  = intval($res['price']);
        $volume = intval($res['volume']); 

        $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $server_info = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$server_info) {
            throw new Exception("server_config_missing");
        }

        $serverType = $server_info['type'];

        unset($markup[count($markup)-1]);

        if ($serverType == "marzban") {
            $response = editMarzbanConfig($server_id, ['remark' => $remark, 'plus_day' => $volume]);
        } else {
            if ($inbound_id > 0) {
                $response = editClientTraffic($server_id, $inbound_id, $uuid, 0, $volume);
            } else {
                $response = editInboundTraffic($server_id, $uuid, 0, $volume);
            }
        }

        if (is_null($response)) {
            throw new Exception("server_connection_null");
        }
        if (!is_object($response) && !is_array($response)) {
            throw new Exception("invalid_response_type");
        }
        $success = (is_object($response) && !empty($response->success))
                || (is_array($response)  && !empty($response['success']));
        if (!$success) {
            $msg = is_object($response) ? ($response->msg ?? 'no_msg')
                 : ($response['msg'] ?? 'no_msg');
            throw new Exception("server_response_failed: ".$msg);
        }

        $addSeconds = $volume * 86400;
        $stmt = $connection->prepare("UPDATE `orders_list` SET `expire_date` = `expire_date` + ?, `notif` = 0 WHERE `uuid` = ?");
        $stmt->bind_param("is", $addSeconds, $uuid);
        $stmt->execute();
        $stmt->close();

        $timeNow = time();
        $stmt = $connection->prepare("INSERT INTO `increase_order` (`userid`, `server_id`, `inbound_id`, `remark`, `amount`, `date`, `day`) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iiisiii", $uid, $server_id, $inbound_id, $remark, $price, $timeNow, $volume);
        $stmt->execute();
        $stmt->close();

        $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'approved', `approved_by` = ? WHERE `hash_id` = ?");
        $stmt->bind_param("is", $from_id, $hash_id);
        $stmt->execute();
        $stmt->close();

        $connection->commit();

        $keys = json_encode([
            'inline_keyboard' => [
                [['text' => "✅ تایید شد توسط $from_id", 'callback_data' => "wizwizch"]],
                [['text' => "🆔 $hash_id", 'callback_data' => "noop_$hash_id"]]
            ]
        ], 488);

        // ویرایش پیام ادمین اصلی
        editKeys($keys, $payParam['message_id'], $payParam['chat_id']);

        // ویرایش پیام سایر ادمین‌ها
        $adminMessages = json_decode($payParam['admins_messages'], true);
        if (is_array($adminMessages)) {
            foreach ($adminMessages as $msgInfo) {
                if (isset($msgInfo['chat_id'], $msgInfo['message_id']) && $msgInfo['chat_id'] != $payParam['chat_id']) {
                    editKeys($keys, $msgInfo['message_id'], $msgInfo['chat_id']);
                }
            }
        }

        sendMessage("✅ <b>$volume</b> روز به مدت زمان اشتراک <code>$remark</code> اضافه شد.", null, "HTML", $uid);
        return;

    } catch (Exception $e) {
        $connection->rollback();

        // پیام فنی برای ادمین اقدام‌کننده
        $err = "⚠️ خطا در تایید افزایش زمان (کارت‌به‌کارت)\n\n"
             . "🆔 Hash ID: {$match[1]}\n"
             . "👤 Admin: {$from_id}\n"
             . "📄 Error: " . $e->getMessage();
        sendMessage($err, null, null, $admin);

        alert("❌ افزایش زمان انجام نشد. لطفاً وضعیت سرور/اطلاعات را بررسی کنید.", true);
        return;
    }
}

if(preg_match('/payIncreaseDayWithWallet(.*)/', $data, $match)){
    $connection->begin_transaction();
    try {
        $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ? FOR UPDATE");
        $stmt->bind_param("s", $match[1]);
        $stmt->execute();
        $payRes = $stmt->get_result();
        $stmt->close();

        if($payRes->num_rows == 0){
            $connection->rollback();
            $text = "/start";
            $data = "";
            delMessage();
            goto GOTOSTART;
        }

        $payParam = $payRes->fetch_assoc();
        $hash_id  = $payParam['hash_id'];

        if ($payParam['state'] === "paid_with_wallet") {
            $connection->rollback();
            return;
        }

        $payType = $payParam['type'];
        if (!preg_match('/^INCREASE_DAY_(\d+)_(\d+)/', $payType, $increaseInfo)) {
            throw new Exception("invalid_increase_type");
        }
        $orderId = intval($increaseInfo[1]);
        $planid  = intval($increaseInfo[2]);
        $uid     = intval($payParam['user_id'] ?? $from_id);

        $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
        $stmt->bind_param("i", $orderId);
        $stmt->execute();
        $orderInfo = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$orderInfo){
            throw new Exception("order_not_found");
        }

        $server_id  = intval($orderInfo['server_id']);
        $inbound_id = intval($orderInfo['inbound_id']);
        $remark     = $orderInfo['remark'];
        $uuid       = $orderInfo['uuid'] ?? "0";

        $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id` = ?");
        $stmt->bind_param('i', $server_id);
        $stmt->execute();
        $serverConfig = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if(!$serverConfig){
            throw new Exception("server_config_missing");
        }
        $serverType = $serverConfig['type'];

        $stmt = $connection->prepare("SELECT * FROM `increase_day` WHERE `id` = ?");
        $stmt->bind_param("i", $planid);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if(!$res){
            throw new Exception("increase_day_plan_not_found");
        }

        $price  = intval($payParam['price']);
        $volume = intval($res['volume']);

        $stmt = $connection->prepare("SELECT `wallet` FROM `users` WHERE `userid` = ? FOR UPDATE");
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $uRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $userwallet = intval($uRow['wallet'] ?? 0);
        if($userwallet < $price) {
            $needamount = $price - $userwallet;

            $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'low_wallet' WHERE `hash_id` = ?");
            $stmt->bind_param("s", $hash_id);
            $stmt->execute();
            $stmt->close();

            $connection->commit();

            alert("❌ موجودی کیف پول کافی نیست.\n💵 موجودی فعلی: " . number_format($userwallet) . " تومان\n🔺 مورد نیاز: " . number_format($needamount) . " تومان", true);
            return;
        }

        if($serverType == "marzban"){
            $response = editMarzbanConfig($server_id, ['remark'=>$remark, 'plus_day'=>$volume]);
        } else {
            if($inbound_id > 0)
                $response = editClientTraffic($server_id, $inbound_id, $uuid, 0, $volume);
            else
                $response = editInboundTraffic($server_id, $uuid, 0, $volume);
        }

        if (is_null($response)) {
            throw new Exception("server_connection_null");
        }
        if (!is_object($response) && !is_array($response)) {
            throw new Exception("invalid_response_type");
        }
        $success = (is_object($response) && !empty($response->success))
                || (is_array($response)  && !empty($response['success']));
        if (!$success) {
            $msg = is_object($response) ? ($response->msg ?? 'no_msg')
                 : ($response['msg'] ?? 'no_msg');
            throw new Exception("server_response_failed: ".$msg);
        }

        $addSeconds = $volume * 86400;
        $stmt = $connection->prepare("UPDATE `orders_list` SET `expire_date` = `expire_date` + ?, `notif` = 0 WHERE `uuid` = ?");
        $stmt->bind_param("is", $addSeconds, $uuid);
        $stmt->execute();
        $stmt->close();

        $timeNow = time();
        $stmt = $connection->prepare("INSERT INTO `increase_order` (`userid`, `server_id`, `inbound_id`, `remark`, `amount`, `date`, `day`) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iiisiii", $uid, $server_id, $inbound_id, $remark, $price, $timeNow, $volume);
        $stmt->execute();
        $stmt->close();

        $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` - ? WHERE `userid` = ?");
        $stmt->bind_param("ii", $price, $uid);
        $stmt->execute();
        $stmt->close();

        $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'paid_with_wallet' WHERE `hash_id` = ?");
        $stmt->bind_param("s", $hash_id);
        $stmt->execute();
        $stmt->close();

        $connection->commit();

        editText($message_id, "✅ $volume روز به مدت زمان اشتراک شما اضافه شد.", getMainKeys());

        $keys = json_encode(['inline_keyboard'=>[
            [
                ['text'=>"⏳ افزایش زمان اشتراک",'callback_data'=>"wizwizch"]
            ],
            [
                ['text'=>"🆔 $hash_id", 'callback_data'=>"noop_$hash_id"]
            ]
        ]]);

        $msg = "
🔋|💰 افزایش زمان با (کیف پول)

🎫 شناسه عددی: $from_id
👨 اسم کاربر: $first_name
⚡️ نام کاربری: @$username
🎈 نام کاربری اشتراک: $remark
⏰ مدت افزایش: $volume روز
💰 قیمت: $price تومان
";
        sendMessage($msg, $keys,"html", $admin);
        //sendToAllAdmins('sendMessage', ['text' => $msg,'reply_markup' => $keys,'parse_mode' => "html"]);
		
		// ارسال پیام به کانال گزارشات
        $rewardChannel = $botState['rewardChannel'];
        if (!empty($rewardChannel)) {
            sendMessage($msg, $keys, "html", $rewardChannel);
        }

        return;

    } catch (Exception $e) {
        $connection->rollback();

        // پیام فنی برای ادمین
        $adminErr = "⚠️ خطا در افزایش زمان با کیف پول\n\n"
                  . "🆔 Hash ID: {$match[1]}\n"
                  . "👤 User: {$from_id}\n"
                  . "📄 Error: ".$e->getMessage();
        sendMessage($adminErr, null, null, $admin);

        sendMessage("❌ مشکلی پیش اومد.\n🔁 لطفاً ۳۰ ثانیه بعد دوباره تلاش کنید.\nاگر مشکل ادامه داشت، به مدیریت اطلاع دهید.", null, null, $uid);

        return;
    }
}

if (preg_match('/^changePlan_(\d+)/', $data, $match)) {
    $orderId = $match[1];

    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $orderInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
	
	if ((int)$orderInfo['amount'] === 0) {
		alert("⚠️ امکان تغییر پلن برای این نوع اشتراک وجود ندارد.", true);
		return;
	}

    $remark = $orderInfo['remark'];
    $serverId = $orderInfo['server_id'];

	$stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `server_id` = ? AND `price` > 0 ORDER BY `id` ASC");
    $stmt->bind_param("i", $serverId);
    $stmt->execute();
    $plans = $stmt->get_result();
    $stmt->close();

    $buttons = [];
    while ($plan = $plans->fetch_assoc()) {
        $buttons[] = [['text' => $plan['title'], 'callback_data' => "confirmChangePlan_" . $orderId . "_" . $plan['id']]];
    }

    $buttons[] = [['text' => $buttonValues['back_button'], 'callback_data' => "mainMenu"]];

    if (count($buttons) === 1) {
        sendMessage("هیچ پلنی برای این سرور وجود ندارد.");
    } else {
        sendMessage("🎲 لطفاً یکی از پلن‌های زیر را انتخاب کنید.
♻️ بعد از تغییر پلن می توانید اشتراک خودتان را مطابق پلن جدید تمدید کنید.", json_encode(['inline_keyboard' => $buttons]), "html");
    }

    exit;
}

if (preg_match('/^confirmChangePlan_(\d+)_(\d+)/', $data, $match)) {
    $orderId = $match[1];
    $newPlanId = $match[2];

    // Get current order info
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $orderInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($orderInfo['fileid'] == $newPlanId) {
        sendMessage("❗️پلن فعلی شما همین است. لطفاً پلن متفاوتی انتخاب کنید.");
        exit;
    }

    // Update plan in orders_list
    $stmt = $connection->prepare("UPDATE `orders_list` SET `fileid` = ? WHERE `id` = ?");
    $stmt->bind_param("ii", $newPlanId, $orderId);
    $stmt->execute();
    $stmt->close();

    editText($message_id, "✅ پلن اشتراک شما با موفقیت تغییر یافت.");

    // Get new plan title
    $stmt = $connection->prepare("SELECT title FROM `server_plans` WHERE `id` = ?");
    $stmt->bind_param("i", $newPlanId);
    $stmt->execute();
    $result = $stmt->get_result();
    $planInfo = $result->fetch_assoc();
    $stmt->close();

    $planTitle = $planInfo['title'];

    $channelMessage = "
📦 تغییر پلن اشتراک توسط کاربر

🔐 شناسه عددی: <code>{$orderInfo['userid']}</code>
👨 اسم کاربر: $first_name
⚡️ نام کاربری: @$username
🎈 نام کاربری اشتراک: <code>{$orderInfo['remark']}</code>
🔄 پلن جدید: $planTitle
🔖 آیدی پلن جدید: $newPlanId
";

    sendMessage($channelMessage, null, "html", $admin);
    //sendToAllAdmins('sendMessage', ['text' => $channelMessage,'parse_mode' => "html"]);
	
	// ارسال پیام به کانال گزارشات
	$rewardChannel = $botState['rewardChannel'];
		if (!empty($rewardChannel)) {
		sendMessage($channelMessage, null, "html", $rewardChannel);
	}
    exit;
}
if (preg_match('/^increaseAVolume(.*)/', $data, $match)) {
    // بررسی تاریخ انقضا
    $stmt = $connection->prepare("SELECT `expire_date` FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $orderInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $expireDate = (int) $orderInfo['expire_date']; // تبدیل به عدد صحیح
    $currentDate = time();

    // بررسی اینکه آیا تاریخ معتبر است یا خیر
    if ($expireDate === false) {
        alert("📆 تاریخ انقضا برای این سفارش نامعتبر است.");
        exit;
    }
	
    // اگر تاریخ انقضا کمتر از تاریخ و ساعت فعلی باشد
    if ($expireDate < $currentDate) {
        alert("📆 اشتراک شما منقضی شده است. لطفاً آن را تمدید کنید تا بتوانید افزایش حجم دهید.");
        exit;
    }
    
    $stmt = $connection->prepare("SELECT * FROM `increase_plan`");
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    
    if ($res->num_rows == 0) {
        alert("در حال حاضر هیچ پلن حجمی وجود ندارد.");
        exit;
    }
    $keyboard = [];
    while ($cat = $res->fetch_assoc()) {
        $id = $cat['id'];
        $title = $cat['volume'];
        $price = $cat['price'];
        if ($agentBought == true) {
            $discounts = json_decode($userInfo['discount_percent'], true);
            if ($botState['agencyPlanDiscount'] == "on") 
                $discount = $discounts['plans'][$orderInfo['fileid']] ?? $discounts['normal'];
            else 
                $discount = $discounts['servers'][$orderInfo['server_id']] ?? $discounts['normal'];
            $price -= floor($price * $discount / 100);
        }
        if ($price == 0) 
            $price = "رایگان";
        else 
            $price = number_format($price) .  ' تومان';
        
        $keyboard[] = ['text' => "$title گیگ $price", 'callback_data' => "increaseVolumePlan{$match[1]}_{$id}"];
    }
    $keyboard = array_chunk($keyboard, 2);
    $keyboard[] = [['text' => "⤵️ برگرد به منوی اصلی", 'callback_data' => "mainMenu"]];
    $res = editText($message_id, "لطفا یکی از پلن های حجمی را انتخاب کنید:", json_encode([
        'inline_keyboard' => $keyboard
    ]));
}

if(preg_match('/increaseVolumePlan(?<orderId>.+)_(?<volumeId>.+)/',$data,$match)){
    $data = str_replace('increaseVolumePlan','',$data);
    $stmt = $connection->prepare("SELECT * FROM `increase_plan` WHERE `id` = ?");
    $stmt->bind_param("i", $match['volumeId']);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $planprice = $res['price'];
    $plangb = $res['volume'];
    
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $match['orderId']);
    $stmt->execute();
    $orderInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $agentBought = $orderInfo['agent_bought'];
 
    if($agentBought == true){
        $discounts = json_decode($userInfo['discount_percent'],true);
        if($botState['agencyPlanDiscount']=="on") $discount = $discounts['plans'][$orderInfo['fileid']]?? $discounts['normal'];
        else $discount = $discounts['servers'][$orderInfo['server_id']]?? $discounts['normal'];
        
        $planprice -= floor($planprice * $discount / 100);
    }

    $hash_id = RandomString();
    $stmt = $connection->prepare("DELETE FROM `pays` WHERE `user_id` = ? AND `type` LIKE '%INCREASE_VOLUME%' AND `state` = 'pending'");
    $stmt->bind_param("i", $from_id);
    $stmt->execute();
    $stmt->close();
    
    $time = time();
    $plan_id = $orderInfo['fileid'];
	$server_id = $orderInfo['server_id'];
	$order_id = $match['orderId'];
	
	$stmt = $connection->prepare("INSERT INTO `pays` (`hash_id`, `user_id`, `type`, `plan_id`, `server_id`, `order_id`, `volume`, `day`, `price`, `request_date`, `state`)
								VALUES (?, ?, ?, ?, ?, ?, '0', '0', ?, ?, 'pending')");
	$type = "INCREASE_VOLUME_$data";
	$stmt->bind_param("sisiiiii", $hash_id, $from_id, $type, $plan_id, $server_id, $order_id, $planprice, $time);
	$stmt->execute();
	$stmt->close();
    
    $keyboard = array();
    
    if($planprice == 0) $planprice = ' رایگان';
    else $planprice = " " . number_format($planprice) . " تومان";
    
    
    if($botState['cartToCartState'] == "on") $keyboard[] = [['text' => $buttonValues['cart_to_cart'] . $planprice,  'callback_data' => "payIncreaseWithCartToCart$hash_id"]];
    if($botState['nowPaymentOther'] == "on") $keyboard[] = [['text' => $buttonValues['now_payment_gateway'],  'url' => $botUrl . "pay/?nowpayment&hash_id=" . $hash_id]];
    if($botState['zarinpal'] == "on") $keyboard[] = [['text' => $buttonValues['zarinpal_gateway'],  'url' => $botUrl . "pay/?zarinpal&hash_id=" . $hash_id]];
    if($botState['nextpay'] == "on") $keyboard[] = [['text' => $buttonValues['nextpay_gateway'],  'url' => $botUrl . "pay/?nextpay&hash_id=" . $hash_id]];
    if($botState['weSwapState'] == "on") $keyboard[] = [['text' => $buttonValues['weswap_gateway'],  'callback_data' => "payWithWeSwap" . $hash_id]];
    if($botState['walletState'] == "on") $keyboard[] = [['text' => "💰پرداخت با موجودی" . $planprice,  'callback_data' => "payIncreaseWithWallet$hash_id"]];
    if($botState['tronWallet'] == "on") $keyboard[] = [['text' => $buttonValues['tron_gateway'],  'callback_data' => "payWithTronWallet" . $hash_id]];

    $keyboard[] = [['text'=>$buttonValues['cancel'], 'callback_data'=> "mainMenu"]];
    editText($message_id, "🔰 لطفا یکی از روش های زیر را انتخاب کنید و روی آن بزنید:",json_encode(['inline_keyboard' => $keyboard]));
}

if (preg_match('/payIncreaseWithCartToCart(.*)/', $data, $match)) {

    $payInfo = dbFetchPayByHash($connection, $match[1]);
    if (!$payInfo) {
        prepareStartFallback();
        delMessage();
        goto GOTOSTART;
    }

    delMessage();
    setUser("payIncreaseWithCartToCart{$match[1]}");

	// $msg = buildCartToCartInfoMessage($paymentKeys, $mainValues, (int)$payInfo['price']);
	$msg = buildCartToCartInfoMessage($paymentKeys, $mainValues, (int)$payInfo['price'], (int)$payInfo['user_id']);
    $keyboard = kbSendReceiptAndCancel("sendIncreaseVolumeReceipt", "cancelIncreaseVolumeReceipt", $match[1]);

    sendMessage($msg, $keyboard, "HTML");
    exit;
}

if (preg_match('/sendIncreaseVolumeReceipt(?<hashId>.*)/', $data, $match)) {

    $payInfo = dbFetchPayByHash($connection, $match['hashId']);
    if (!$payInfo) {
        prepareStartFallback();
        delMessage();
        goto GOTOSTART;
    }

    $guardMsg = duplicateReceiptGuard($connection, $payInfo, time());
    if ($guardMsg) { alert($guardMsg, true); exit; }

    delMessage();
    setUser("waitIncreaseVolumeReceipt{$match['hashId']}");

    $keyboard = kbOnlyCancel("cancelIncreaseVolumeReceipt", $match['hashId']);
    sendMessage($mainValues['please_send_receipt_image'], $keyboard, "HTML");
    exit;
}

if (preg_match('/cancelIncreaseVolumeReceipt(?<hashId>.*)/', $data, $match)) {
    handleCancelFlow($mainValues, $removeKeyboard);
}

if (preg_match('/waitIncreaseVolumeReceipt(.*)/', $userInfo['step'], $match)) {

    $fileid = extractPhotoFileId($update);
    if (!$fileid) {
        $keyboard = kbOnlyCancel("cancelIncreaseVolumeReceipt", $match[1]);
        sendMessage($mainValues['please_send_only_image'], $keyboard, "HTML");
        exit;
    }

    $payParam = dbFetchPayByHash($connection, $match[1]);
    if (!$payParam) {
        prepareStartFallback();
        delMessage();
        goto GOTOSTART;
    }

    $payType = $payParam['type'];

    preg_match('/^INCREASE_VOLUME_(\d+)_(\d+)/', $payType, $increaseInfo);
    $orderId = (int)$increaseInfo[1];
    $planid  = (int)$increaseInfo[2];

    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $orderInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $remark = $orderInfo['remark'];

    $stmt = $connection->prepare("SELECT * FROM `increase_plan` WHERE `id` = ?");
    $stmt->bind_param("i", $planid);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $price  = $payParam['price'];
    $volume = $res['volume'];

    sendWithStartButton($mainValues['order_submitted'], $buttonValues['start_bot'], $botUsername, 'mainMenu', 'HTML');

    $msg = str_replace(
        ['INCREASE', 'TYPE', "USER-ID", "USERNAME", "NAME", "PRICE", "REMARK"],
        [$volume, 'حجم', $from_id, $username, $first_name, $price, $remark],
        $mainValues['increase_account_request_message']
    );

    $adminKeyboard = buildAdminKeyboardWithStats(
        $connection,
        $buttonValues,
        "approveIncreaseVolume{$match[1]}",
        "declineAction_{$match[1]}",
        $match[1],
        (int)$payParam['user_id']
    );

    sendReceiptToAdminsAndUpdatePay($connection, $fileid, $msg, $adminKeyboard, $admin, $match[1]);
    setUser();
    exit;
}

if (preg_match('/approveIncreaseVolume(.*)/', $data, $match) && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $connection->begin_transaction();
    try {
        $hash_id = $match[1];
        $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ? FOR UPDATE");
        $stmt->bind_param("s", $hash_id);
        $stmt->execute();
        $payParam = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$payParam || ($payParam['state'] === 'expired_have_sent')) {
            $connection->rollback();
            alert(!$payParam ? "⛔️ اطلاعات پرداخت پیدا نشد." : "⛔️ این درخواست منقضی شده است.");
            return;
        }

        if ($payParam['state'] === 'approved' || $payParam['state'] === 'declined') {
            $connection->rollback();
            alert("⛔️ این درخواست قبلاً بررسی شده است.");
            return;
        }

        $payType = $payParam['type'];
        if (!preg_match('/^INCREASE_VOLUME_(\d+)_(\d+)/', $payType, $increaseInfo)) {
            throw new Exception("invalid_increase_type");
        }

        $orderId = intval($increaseInfo[1]);
        $planid  = intval($increaseInfo[2]);
        $uid     = intval($payParam['user_id']);

        $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
        $stmt->bind_param("i", $orderId);
        $stmt->execute();
        $orderInfo = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$orderInfo) {
            throw new Exception("order_not_found");
        }

        $server_id  = intval($orderInfo['server_id']);
        $inbound_id = intval($orderInfo['inbound_id']);
        $remark     = $orderInfo['remark'];
        $uuid       = $orderInfo['uuid'] ?? "0";

        $stmt = $connection->prepare("SELECT * FROM `increase_plan` WHERE `id` = ?");
        $stmt->bind_param("i", $planid);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$res) {
            throw new Exception("increase_plan_not_found");
        }

        $price  = intval($res['price']);
        $volume = intval($res['volume']);

        $stmt = $connection->prepare("SELECT * FROM server_config WHERE id = ?");
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $server_info = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$server_info) {
            throw new Exception("server_config_missing");
        }

        $serverType = $server_info['type'];

        if ($serverType == "marzban") {
            $response = editMarzbanConfig($server_id, ['remark' => $remark, 'plus_volume' => $volume]);
        } else {
            if ($inbound_id > 0) {
                $response = editClientTraffic($server_id, $inbound_id, $uuid, $volume, 0);
            } else {
                $response = editInboundTraffic($server_id, $uuid, $volume, 0);
            }
        }

        if (is_null($response)) {
            throw new Exception("server_connection_null");
        }
        if (!is_object($response) && !is_array($response)) {
            throw new Exception("invalid_response_type");
        }
        $success = (is_object($response) && !empty($response->success))
                || (is_array($response)  && !empty($response['success']));
        if (!$success) {
            $msg = is_object($response) ? ($response->msg ?? 'no_msg')
                 : ($response['msg'] ?? 'no_msg');
            throw new Exception("server_response_failed: ".$msg);
        }

        $now = time();
        $stmt = $connection->prepare("INSERT INTO `increase_order` (`userid`, `server_id`, `inbound_id`, `remark`, `amount`, `date`, `volume`) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iiisiii", $uid, $server_id, $inbound_id, $remark, $price, $now, $volume);
        $stmt->execute();
        $stmt->close();

        $stmt = $connection->prepare("UPDATE `orders_list` SET `notif` = 0 WHERE `uuid` = ?");
        $stmt->bind_param("s", $uuid);
        $stmt->execute();
        $stmt->close();

        $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'approved', `approved_by` = ? WHERE `hash_id` = ?");
        $stmt->bind_param("is", $from_id, $hash_id);
        $stmt->execute();
        $stmt->close();

        $connection->commit();

        $keys = json_encode([
            'inline_keyboard' => [
                [['text' => "✅ تایید شد توسط $from_id", 'callback_data' => "wizwizch"]],
                [['text' => "🆔 $hash_id", 'callback_data' => "noop_$hash_id"]]
            ]
        ], 488);

        // ویرایش پیام ادمین اصلی
        editKeys($keys, $payParam['message_id'], $payParam['chat_id']);

        // ویرایش پیام سایر ادمین ها
        $adminMessages = json_decode($payParam['admins_messages'], true);
        if (is_array($adminMessages)) {
            foreach ($adminMessages as $msgInfo) {
                if (
                    isset($msgInfo['chat_id'], $msgInfo['message_id']) &&
                    $msgInfo['chat_id'] != $payParam['chat_id']
                ) {
                    editKeys($keys, $msgInfo['message_id'], $msgInfo['chat_id']);
                }
            }
        }

        sendMessage("✅ <b>$volume</b> گیگ به حجم اشتراک <code>$remark</code> اضافه شد.", null, "HTML", $uid);
        return;

    } catch (Exception $e) {
        $connection->rollback();

        // پیام فنی برای ادمین اقدام‌کننده
        $err = "⚠️ خطا در تایید افزایش حجم (کارت‌به‌کارت)\n\n"
             . "🆔 Hash ID: {$match[1]}\n"
             . "👤 Admin: {$from_id}\n"
             . "📄 Error: " . $e->getMessage();
        sendMessage($err, null, null, $admin);

        alert("❌ افزایش حجم انجام نشد. لطفاً وضعیت سرور/اطلاعات را بررسی کنید.", true);
        return;
    }
}

if(preg_match('/payIncreaseWithWallet(.*)/', $data, $match)){
    $connection->begin_transaction();
    try {
        $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ? FOR UPDATE");
        $stmt->bind_param("s", $match[1]);
        $stmt->execute();
        $payRes = $stmt->get_result();
        $stmt->close();

        if ($payRes->num_rows == 0){
            $connection->rollback();
            $text = "/start";
            $data = "";
            delMessage();
            goto GOTOSTART;
        }

        $payParam = $payRes->fetch_assoc();
        $hash_id  = $payParam['hash_id'];

        if ($payParam['state'] === "paid_with_wallet") {
            $connection->rollback();
            return;
        }

        $payType = $payParam['type'];
        if (!preg_match('/^INCREASE_VOLUME_(\d+)_(\d+)/', $payType, $increaseInfo)) {
            throw new Exception("invalid_increase_type");
        }
        $orderId = intval($increaseInfo[1]);
        $planid  = intval($increaseInfo[2]);

        $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
        $stmt->bind_param("i", $orderId);
        $stmt->execute();
        $orderInfo = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$orderInfo){
            throw new Exception("order_not_found");
        }

        $server_id  = intval($orderInfo['server_id']);
        $inbound_id = intval($orderInfo['inbound_id']);
        $remark     = $orderInfo['remark'];
        $uuid       = $orderInfo['uuid'] ?? "0";

        $stmt = $connection->prepare("SELECT * FROM `increase_plan` WHERE `id` = ?");
        $stmt->bind_param("i", $planid);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$res){
            throw new Exception("increase_plan_not_found");
        }

        $price  = intval($payParam['price']);
        $volume = intval($res['volume']);

        $uid = intval($payParam['user_id'] ?? $from_id);
        $stmt = $connection->prepare("SELECT `wallet` FROM `users` WHERE `userid` = ? FOR UPDATE");
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $wRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $userwallet = intval($wRow['wallet'] ?? 0);
        if ($userwallet < $price) {
            $needamount = $price - $userwallet;

            // وضعیت پرداخت کمبود موجودی
            $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'low_wallet' WHERE `hash_id` = ?");
            $stmt->bind_param("s", $hash_id);
            $stmt->execute();
            $stmt->close();

            $connection->commit();
            alert("❌ موجودی کیف پول کافی نیست.\n💵 موجودی فعلی: " . number_format($userwallet) . " تومان\n🔺 مورد نیاز: " . number_format($needamount) . " تومان", true);
            return;
        }

        $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $server_info = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$server_info){
            throw new Exception("server_config_missing");
        }

        $serverType = $server_info['type'];

        if($serverType == "marzban"){
            $response = editMarzbanConfig($server_id, ['remark'=>$remark, 'plus_volume'=>$volume]);
        } else {
            if($inbound_id > 0)
                $response = editClientTraffic($server_id, $inbound_id, $uuid, $volume, 0);
            else
                $response = editInboundTraffic($server_id, $uuid, $volume, 0);
        }

        if (is_null($response)) {
            throw new Exception("server_connection_null");
        }
        if (!is_object($response) && !is_array($response)) {
            throw new Exception("invalid_response_type");
        }
        $success = (is_object($response) && !empty($response->success))
                || (is_array($response)  && !empty($response['success']));
        if (!$success) {
            $msg = is_object($response) ? ($response->msg ?? 'no_msg')
                 : ($response['msg'] ?? 'no_msg');
            throw new Exception("server_response_failed: ".$msg);
        }

        $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` - ? WHERE `userid` = ?");
        $stmt->bind_param("ii", $price, $uid);
        $stmt->execute();
        $stmt->close();

        $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'paid_with_wallet' WHERE `hash_id` = ?");
        $stmt->bind_param("s", $hash_id);
        $stmt->execute();
        $stmt->close();

        $stmt = $connection->prepare("UPDATE `orders_list` SET `notif` = 0 WHERE `uuid` = ?");
        $stmt->bind_param("s", $uuid);
        $stmt->execute();
        $stmt->close();

        $timeNow = time();
        $stmt = $connection->prepare("INSERT INTO `increase_order` (`userid`, `server_id`, `inbound_id`, `remark`, `amount`, `date`, `volume`) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iiisiii", $uid, $server_id, $inbound_id, $remark, $price, $timeNow, $volume);
        $stmt->execute();
        $stmt->close();

        // COMMIT
        $connection->commit();

        editText($message_id, "✅ $volume گیگ به حجم اشتراک شما اضافه شد.", getMainKeys());

        $keys = json_encode(['inline_keyboard'=>[
            [
                ['text'=>"🔋 افزایش حجم اشتراک",'callback_data'=>"wizwizch"]
            ],
            [
                ['text'=>"🆔 $hash_id", 'callback_data'=>"noop_$hash_id"]
            ]
        ]]);

        $msg = "
🔋|💰 افزایش حجم با (کیف پول)

🎫 شناسه عددی: $from_id
👨 اسم کاربر: $first_name
⚡️ نام کاربری: @$username
🎈 نام کاربری اشتراک: $remark
🔝 مقدار افزایش: $volume گیگ
💰 قیمت: $price تومان
";
        sendMessage($msg, $keys, "html", $admin);
        //sendToAllAdmins('sendMessage', ['text' => $msg, 'reply_markup' => $keys, 'parse_mode' => "html"]);
		
		// ارسال پیام به کانال گزارشات
        $rewardChannel = $botState['rewardChannel'];
        if (!empty($rewardChannel)) {
            sendMessage($msg, $keys, "html", $rewardChannel);
        }

        return;

    } catch (Exception $e) {
        // بازگردانی تغییرات
        $connection->rollback();

        // پیام فنی برای ادمین
        $adminErr = "⚠️ خطا در افزایش حجم با کیف پول\n\n"
                  . "🆔 Hash ID: {$match[1]}\n"
                  . "👤 User: {$from_id}\n"
                  . "📄 Error: ".$e->getMessage();
        sendMessage($adminErr, null, null, $admin);

        sendMessage("❌ مشکلی پیش اومد.\n🔁 لطفاً ۳۰ ثانیه بعد دوباره تلاش کنید.\nاگر مشکل ادامه داشت، به مدیریت اطلاع دهید.", null, null, $uid);

        return;
    }
}

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
if(preg_match('/^changePortType(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("UPDATE `server_config` SET `port_type` = IF(`port_type` = 'auto', 'random', 'auto') WHERE `id`=?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $stmt->close();
    alert("نوع پورت سرور مورد نظر با موفقیت تغییر کرد.");
    
    $keys = getServerConfigKeys($match[1]);
    editText($message_id,"☑️ مدیریت سرور ها: $cname",$keys);
    
    exit();
}
if(preg_match('/^changeRealityState(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("UPDATE `server_config` SET `reality` = IF(`reality` = 'true', 'false', 'true') WHERE `id` = ?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $stmt->close();
    
    $keys = getServerConfigKeys($match[1]);
    editText($message_id,"☑️ مدیریت سرور ها: $cname",$keys);
    
    exit();
}
if(preg_match('/^changeServerType(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    editText($message_id,"
    
🔰 نکته مهم: ( پنل x-ui خود را به آخرین نسخه آپدیت کنید ) 

❤️ اگر از پنل سنایی استفاده میکنید لطفا نوع پنل را ( سنایی ) انتخاب کنید.
🧡 اگر از پنل علیرضا استفاده میکنید لطفا نوع پنل را ( علیرضا ) انتخاب کنید.
💚 اگر از پنل نیدوکا استفاده میکنید لطفا نوع پنل را ( ساده ) انتخاب کنید. 
💙 اگر از پنل چینی استفاده میکنید لطفا نوع پنل را ( ساده ) انتخاب کنید. 
⁮⁮ ⁮⁮ ⁮⁮ ⁮⁮
📣 حتما نوع پنل را انتخاب کنید وگرنه براتون مشکل ساز میشه!
⁮⁮ ⁮⁮ ⁮⁮ ⁮⁮
",json_encode(['inline_keyboard'=>[
        [['text'=>"ساده",'callback_data'=>"chhangeServerTypenormal_" . $match[1]],['text'=>"سنایی",'callback_data'=>"chhangeServerTypesanaei_" . $match[1]]],
        [['text'=>"علیرضا",'callback_data'=>"chhangeServerTypealireza_" . $match[1]]]
        ]]));
    exit();
}
if(preg_match('/^chhangeServerType(\w+)_(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    alert($mainValues['saved_successfuly']);
    $stmt = $connection->prepare("UPDATE `server_config` SET `type` = ? WHERE `id`=?");
    $stmt->bind_param("si",$match[1], $match[2]);
    $stmt->execute();
    $stmt->close();
    
    $keys = getServerConfigKeys($match[2]);
    editText($message_id, "☑️ مدیریت سرور ها: $cname",$keys);
}
if(($data == "addNewMarzbanPanel" || $data=='addNewServer') and ($from_id == $admin || $userInfo['isAdmin'] == true)){
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

▪️ لطفا آدرس پنل x-ui رو به صورت مثال زیر وارد کن:

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
    if($userInfo['temp'] == "addNewMarzbanPanel"){
        $data['panel_ip'] = "/empty";
        $data['sni'] = "/empty";
        $data['header_type'] = "/empty";
        $data['response_header'] = "/empty";
        $data['request_header'] = "/empty";
        $data['security'] = "/empty";
        $data['tls_setting'] = "/empty";
        
        setUser('addServerPanelUser' . json_encode($data, JSON_UNESCAPED_UNICODE));
        sendMessage( "مرحله ششم: 
    ▪️ لطفا یوزر پنل را وارد کنید:");
    
        exit();
    }else{
        setUser('addServerIp' . json_encode($data,JSON_UNESCAPED_UNICODE));
        sendMessage( "🔅 لطفا ip یا دامنه تانل شده پنل را وارد کنید:
    
    نمونه: 
    91.257.142.14
    sub.domain.com
    ❗️ در صورتی که میخواید چند دامنه یا ip کانفیگ بگیرید باید زیر هم بنویسید و برای ربات بفرستین:
        \n\n🔻برای خالی گذاشتن متن /empty را وارد کنید.");
        exit();
    }
}
if(preg_match('/^addServerIp(.*)/',$userInfo['step'],$match) and $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $data = json_decode($match[1],true);
    $data['panel_ip'] = $text;
    setUser('addServerSni' . json_encode($data, JSON_UNESCAPED_UNICODE));
    sendMessage( "🔅 لطفا sni پنل را وارد کنید\n\n🔻برای خالی گذاشتن متن /empty را وارد کنید.");
    exit();
}
if(preg_match('/^addServerSni(.*)/',$userInfo['step'],$match) and $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $data = json_decode($match[1],true);
    $data['sni'] = $text;
    setUser('addServerHeaderType' . json_encode($data, JSON_UNESCAPED_UNICODE));
    sendMessage( "🔅 اگر از header type استفاده میکنید لطفا http را تایپ کنید:\n\n🔻برای خالی گذاشتن متن /empty را وارد کنید.");
    exit();
}
if(preg_match('/^addServerHeaderType(.*)/',$userInfo['step'],$match) and $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $data = json_decode($match[1],true);
    $data['header_type'] = $text;
    setUser('addServerRequestHeader' . json_encode($data, JSON_UNESCAPED_UNICODE));
    sendMessage( "🔅 اگر از هدر استفاده میکنید لطفا آدرس رو به این صورت Host:test.com وارد کنید و به جای test.com آدرس دلخواه بزنید:\n\n🔻برای خالی گذاشتن متن /empty را وارد کنید.");
    exit();
}
if(preg_match('/^addServerRequestHeader(.*)/',$userInfo['step'],$match) and $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $data = json_decode($match[1],true);
    $data['request_header'] = $text;
    setUser('addServerResponseHeader' . json_encode($data, JSON_UNESCAPED_UNICODE));
    sendMessage( "🔅 لطفا response header پنل را وارد کنید\n\n🔻برای خالی گذاشتن متن /empty را وارد کنید.");
    exit();
}
if(preg_match('/^addServerResponseHeader(.*)/',$userInfo['step'],$match) and $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $data = json_decode($match[1],true);
    $data['response_header'] = $text;
    setUser('addServerSecurity' . json_encode($data, JSON_UNESCAPED_UNICODE));
    sendMessage( "🔅 لطفا security پنل را وارد کنید

⚠️ توجه: برای استفاده از tls یا xtls لطفا کلمه tls یا xtls رو تایپ کنید در غیر این صورت 👇
\n🔻برای خالی گذاشتن متن /empty را وارد کنید.");
exit();
}
if(preg_match('/^addServerSecurity(.*)/',$userInfo['step'],$match) and $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $data = json_decode($match[1],true);
    $data['security'] = $text;
    setUser('addServerTlsSetting' . json_encode($data, JSON_UNESCAPED_UNICODE));
    sendMessage("
    🔅 لطفا tls|xtls setting پنل را وارد کنید🔻برای  خالی گذاشتن متن /empty را وارد کنید.

⚠️ لطفا تنظیمات سرتیفیکیت رو با دقت انجام بدید مثال:
▫️serverName: yourdomain
▫️certificateFile: /root/cert.crt
▫️keyFile: /root/private.key
\n
"
        .'<b>tls setting:</b> <code>{"serverName": "","certificates": [{"certificateFile": "","keyFile": ""}]}</code>' . "\n"
        .'<b>xtls setting:</b> <code>{"serverName": "","certificates": [{"certificateFile": "","keyFile": ""}],"alpn": []}</code>', null, "HTML");

    exit();
}
if(preg_match('/^addServerTlsSetting(.*)/',$userInfo['step'],$match) and $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $data = json_decode($match[1],true);
    $data['tls_setting'] = $text;
    setUser('addServerPanelUser' . json_encode($data, JSON_UNESCAPED_UNICODE));
    sendMessage( "مرحله ششم: 
▪️لطفا یوزر پنل را وارد کنید:");

    exit();
}
if(preg_match('/^addServerPanelUser(.*)/',$userInfo['step'],$match) and $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $data = json_decode($match[1],true);
    $data['panel_user'] = $text;
    setUser('addServerPanePassword' . json_encode($data, JSON_UNESCAPED_UNICODE));
    sendMessage( "مرحله هفتم: 
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
    
    
    $loginResponse['success'] = false;
    if($userInfo['temp'] == "addNewMarzbanPanel"){
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
    }else{
        $loginUrl = $panel_url . '/login';
        $postFields = array(
            "username" => $serverName,
            "password" => $serverPass
            );
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $loginUrl);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15); 
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
        $loginResponse = json_decode(curl_exec($ch),true);
        curl_close($ch);
        
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
    $stmt = $connection->prepare("INSERT INTO `server_info` (`title`, `ucount`, `remark`, `flag`, `active`)
                                                    VALUES (?,?,?,?,1)");
    $stmt->bind_param("siss", $title, $ucount, $remark, $flag);
    $stmt->execute();
    $rowId = $stmt->insert_id;
    $stmt->close();

    $stmt = $connection->prepare("INSERT INTO `server_config` (`id`, `panel_url`, `ip`, `sni`, `header_type`, `request_header`, `response_header`, `security`, `tlsSettings`, `username`, `password`)
                                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("issssssssss", $rowId, $panel_url, $ip, $sni, $header_type, $request_header, $response_header, $security, $tlsSettings, $serverName, $serverPass);
    $stmt->execute();
    $rowId = $stmt->insert_id;
    $stmt->close();

    sendMessage(" تبریک، سرورت رو ثبت کردی 🥹",$removeKeyboard);
    if($userInfo['temp'] == "addNewMarzbanPanel"){
        $stmt = $connection->prepare("UPDATE `server_config` SET `type` = 'marzban' WHERE `id`=?");
        $stmt->bind_param("i",$rowId);
        $stmt->execute();
        $stmt->close();
        
        $keys = getServerListKeys();
        sendMessage("☑️ مدیریت سرور ها",$keys);
    }else{
        sendMessage("
    
🔰 نکته مهم: ( پنل x-ui خود را به آخرین نسخه آپدیت کنید ) 

❤️ اگر از پنل سنایی استفاده میکنید لطفا نوع پنل را ( سنایی ) انتخاب کنید.
🧡 اگر از پنل علیرضا استفاده میکنید لطفا نوع پنل را ( علیرضا ) انتخاب کنید.
💚 اگر از پنل نیدوکا استفاده میکنید لطفا نوع پنل را ( ساده ) انتخاب کنید. 
💙 اگر از پنل چینی استفاده میکنید لطفا نوع پنل را ( ساده ) انتخاب کنید. 
⁮⁮ ⁮⁮ ⁮⁮ ⁮⁮
📣 حتما نوع پنل را انتخاب کنید وگرنه براتون مشکل ساز میشه!
⁮⁮ ⁮⁮ ⁮⁮ ⁮⁮
    ",json_encode(['inline_keyboard'=>[
            [['text'=>"ساده",'callback_data'=>"chhangeServerTypenormal_" . $rowId],['text'=>"سنایی",'callback_data'=>"chhangeServerTypesanaei_" . $rowId]],
            [['text'=>"علیرضا",'callback_data'=>"chhangeServerTypealireza_" . $rowId]]
            ]]));
    }
    setUser();
    exit();
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
    $serverType = $serverInfo['type'];
    $loginResponse['success'] = false;
    
    if($serverType == "marzban"){
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
    }else{
        $loginUrl = $panel_url . '/login';
        $postFields = array(
            "username" => $serverName,
            "password" => $serverPass
            );
    
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $loginUrl);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15); 
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
        $loginResponse = json_decode(curl_exec($ch),true);
        curl_close($ch);
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

// BEGIN Manage discount
if(preg_match('/^discount_codes(_(\d+))?$/', $data, $m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){ 
    $page = isset($m[2]) ? (int)$m[2] : 1;
    if($page < 1) $page = 1;

    editText($message_id, "🎁 مدیریت کدهای تخفیف", getDiscountCodeKeys($page)); 
}

// شروع ساخت کد تخفیف جدید
if($data=="addDiscountCode" && ($from_id == $admin || $userInfo['isAdmin'] == true)){ 
    delMessage(); 
    sendMessage(
        "🔘 لطفا کد تخفیف دلخواه خود را وارد کنید.\n".
        "▪️ فقط از حروف انگلیسی و اعداد استفاده کنید (مثال: OFF50)",
        $cancelKey
    ); 
    // مرحله اول: منتظر دریافت متن کد
    setUser("addDiscountCode"); 
} 

// دریافت متن کد تخفیف از ادمین
if($userInfo['step'] == "addDiscountCode" && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){ 
    
    $code = trim($text);

    // فقط حروف انگلیسی، عدد و زیرخط
    if(!preg_match('/^[A-Za-z0-9_]+$/', $code)){
        sendMessage("🔘 لطفا فقط از حروف انگلیسی و اعداد (بدون فاصله و حروف فارسی) استفاده کنید.");
        return;
    }

    // چک یکتا بودن کد تخفیف
    $stmt = $connection->prepare("SELECT `id` FROM `discounts` WHERE `hash_id` = ? LIMIT 1");
    $stmt->bind_param("s", $code);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    if($res->num_rows > 0){
        sendMessage("🔘 این کد قبلا استفاده شده است، لطفا یک کد دیگر انتخاب کنید.");
        return;
    }

    // ذخیره موقت اطلاعات کد
    $dInfo = array();
    $dInfo['hash_id'] = $code;

    // رفتن به مرحله بعد: مقدار تخفیف
    setUser("addDiscountAmount" . json_encode($dInfo, JSON_UNESCAPED_UNICODE)); 
    sendMessage(
        "🔘 لطفا مقدار تخفیف را وارد کنید.\n".
        "برای درصد، علامت % را کنار عدد بگذارید (مثال: 20%)\n".
        "در غیر این صورت مقدار تخفیف به تومان محاسبه می‌شود.",
        $cancelKey
    ); 
} 

// مقدار تخفیف (درصدی یا مبلغ ثابت)
if(preg_match('/^addDiscountAmount(.*)/',$userInfo['step'],$match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){ 
    
    $dInfo = json_decode($match[1], true);
    if(!is_array($dInfo)) $dInfo = array();

    $dInfo['type'] = 'amount'; 
    if(strstr($text, "%")) $dInfo['type'] = 'percent'; 

    $text = trim(str_replace("%", "", $text)); 

    if(is_numeric($text)){ 
        $dInfo['amount'] = (int)$text; 

        setUser("addDiscountDate" . json_encode($dInfo, JSON_UNESCAPED_UNICODE)); 
        sendMessage("🔘 لطفا مدت زمان این تخفیف را به روز وارد کنید.\nبرای نامحدود بودن 0 وارد کنید.", $cancelKey); 
    }else{
        sendMessage("🔘 لطفا فقط عدد و یا درصد بفرستید."); 
    }
} 

// مدت زمان (تاریخ انقضا بر حسب روز)
if(preg_match('/^addDiscountDate(.*)/',$userInfo['step'],$match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){ 
    if(is_numeric($text)){ 
        $dInfo = json_decode($match[1],true); 
        if(!is_array($dInfo)) $dInfo = array();

        $days = (int)$text;
        $dInfo['date'] = $days != 0 ? time() + ($days * 24 * 60 * 60) : 0; 
         
        setUser("addDiscountCount" . json_encode($dInfo,JSON_UNESCAPED_UNICODE)); 
        sendMessage("🔘 لطفا تعداد استفاده این تخفیف را وارد کنید.\nبرای نامحدود بودن 0 وارد کنید.", $cancelKey); 
    }else{
        sendMessage("🔘 لطفا فقط عدد بفرستید."); 
    }
} 

// تعداد کل استفاده از کد
if(preg_match('/^addDiscountCount(.*)/',$userInfo['step'],$match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){ 
    if(is_numeric($text)){  
        $dInfo = json_decode($match[1],true); 
        if(!is_array($dInfo)) $dInfo = array();

        $count = (int)$text;
        // 0 یا کمتر = نامحدود → -1
        $dInfo['count'] = $count > 0 ? $count : -1; 
         
        setUser('addDiscountCanUse' . json_encode($dInfo,JSON_UNESCAPED_UNICODE)); 
        sendMessage("🔘 لطفا تعداد استفاده هر کاربر از این کد را وارد کنید.\nبرای نامحدود بودن 0 وارد کنید.", $cancelKey); 
    }else{
        sendMessage("🔘 لطفا فقط عدد بفرستید."); 
    }
} 

// تعداد استفاده برای هر کاربر → بعدش انتخاب نوع کاربر (audience_type)
if(preg_match('/^addDiscountCanUse(.*)/',$userInfo['step'],$match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){ 
    if(is_numeric($text)){  
        $dInfo = json_decode($match[1],true); 
        if(!is_array($dInfo)) $dInfo = array();

        $perUser = (int)$text;
        $dInfo['can_use'] = $perUser > 0 ? $perUser : -1; 

        // رفتن به مرحله انتخاب نوع کاربران هدف
        setUser('addDiscountAudience' . json_encode($dInfo, JSON_UNESCAPED_UNICODE)); 

        $keys = [
            'inline_keyboard' => [
                [
                    ['text' => 'همه کاربران',              'callback_data' => 'setDiscountAudience_all'],
                ],
                [
                    ['text' => 'یک کاربر خاص',             'callback_data' => 'setDiscountAudience_user'],
                ],
                [
                    ['text' => 'همکاران (agents)',         'callback_data' => 'setDiscountAudience_agents'],
                ],
				[
					['text' => 'کاربران غیر همکار',        'callback_data' => 'setDiscountAudience_non_agents'],
				],
                [
                    ['text' => 'کاربران دارای خرید',       'callback_data' => 'setDiscountAudience_buyers'],
                ],
                [
                    ['text' => 'کاربران بدون خرید',        'callback_data' => 'setDiscountAudience_non_buyers'],
                ],
                [
                    ['text' => 'کاربران دارای سفارش',      'callback_data' => 'setDiscountAudience_has_active_order'],
                ],
                [
                    ['text' => 'کاربران بدون سفارش',       'callback_data' => 'setDiscountAudience_no_active_order'],
                ],
            ]
        ];

        sendMessage(
            "🔘 لطفا نوع کاربران هدف برای این کد تخفیف را انتخاب کنید:",
            json_encode($keys)
        );
    }else{
        sendMessage("🔘 لطفا فقط عدد بفرستید."); 
    }
} 

// هندل کردن انتخاب audience با دکمه اینلاین
if(preg_match('/^addDiscountAudience(.*)/',$userInfo['step'],$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){

    if(preg_match('/^setDiscountAudience_(.+)$/', $data, $m)){
        $choice = $m[1];

        $dInfo = json_decode($match[1], true);
        if(!is_array($dInfo)) $dInfo = array();

        // user → باید userid بپرسیم
        if($choice == 'user'){
            $dInfo['audience_type']    = 'user';
            $dInfo['audience_user_id'] = 0;

            setUser('addDiscountAudienceUser' . json_encode($dInfo, JSON_UNESCAPED_UNICODE));
            sendMessage("🔘 لطفا شناسه عددی کاربر مورد نظر را وارد کنید:", $cancelKey);
            return;
        }

        switch($choice){
            case 'all':
                $dInfo['audience_type'] = 'all';
                break;
            case 'agents':
                $dInfo['audience_type'] = 'agents';
                break;
			case 'non_agents':
				$dInfo['audience_type'] = 'non_agents';
				break;
            case 'buyers':
                $dInfo['audience_type'] = 'buyers';
                break;
            case 'non_buyers':
                $dInfo['audience_type'] = 'non_buyers';
                break;
            case 'has_active_order':
                $dInfo['audience_type'] = 'has_active_order';
                break;
            case 'no_active_order':
                $dInfo['audience_type'] = 'no_active_order';
                break;
            default:
                $dInfo['audience_type'] = 'all';
        }

        $dInfo['audience_user_id'] = isset($dInfo['audience_user_id']) ? (int)$dInfo['audience_user_id'] : 0;

        setUser('addDiscountTargetType' . json_encode($dInfo, JSON_UNESCAPED_UNICODE));

        $keys = [
            'inline_keyboard' => [
                [
                    ['text' => 'همه پلن‌ها',       'callback_data' => 'setDiscountTarget_all'],
                ],
                [
                    ['text' => 'فقط یک سرور',      'callback_data' => 'setDiscountTarget_server'],
                ],
                [
                    ['text' => 'فقط یک دسته‌بندی', 'callback_data' => 'setDiscountTarget_category'],
                ],
                [
                    ['text' => 'فقط یک پلن',       'callback_data' => 'setDiscountTarget_plan'],
                ],
            ]
        ];

        sendMessage(
            "🔘 این کد تخفیف برای چه چیزی معتبر باشد؟",
            json_encode($keys)
        );
        return;
    }
}

// اگر audience_type = user باشد → گرفتن userid کاربر
if(preg_match('/^addDiscountAudienceUser(.*)/',$userInfo['step'],$match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    
    if(!is_numeric($text)){
        sendMessage("🔘 لطفا فقط عدد (userid) کاربر را وارد کنید.");
        return;
    }

    $dInfo = json_decode($match[1], true);
    if(!is_array($dInfo)) $dInfo = array();

    $dInfo['audience_type']    = 'user';
    $dInfo['audience_user_id'] = (int)$text;

    setUser('addDiscountTargetType' . json_encode($dInfo, JSON_UNESCAPED_UNICODE));

    $keys = [
        'inline_keyboard' => [
            [
                ['text' => 'همه پلن‌ها',       'callback_data' => 'setDiscountTarget_all'],
            ],
            [
                ['text' => 'فقط یک سرور',      'callback_data' => 'setDiscountTarget_server'],
            ],
            [
                ['text' => 'فقط یک دسته‌بندی', 'callback_data' => 'setDiscountTarget_category'],
            ],
            [
                ['text' => 'فقط یک پلن',       'callback_data' => 'setDiscountTarget_plan'],
            ],
        ]
    ];

    sendMessage(
        "🔘 این کد تخفیف برای چه چیزی معتبر باشد؟",
        json_encode($keys)
    );
}

// انتخاب نوع هدف سرویس (target_type)
if(preg_match('/^addDiscountTargetType(.*)/',$userInfo['step'],$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    
    if(preg_match('/^setDiscountTarget_(.+)$/', $data, $m)){
        $choice = $m[1];

        $dInfo = json_decode($match[1], true);
        if(!is_array($dInfo)) $dInfo = array();

        if($choice == 'all'){
            $dInfo['target_type'] = 'all';
            $dInfo['target_id']   = 0;

            // میریم سراغ حداقل مبلغ
            setUser('addDiscountMinPrice' . json_encode($dInfo, JSON_UNESCAPED_UNICODE));
            sendMessage("🔘 حداقل مبلغ سفارش برای استفاده از این کد را به تومان وارد کنید.\nبرای بدون محدودیت 0 را وارد کنید.", $cancelKey);
            return;
        }

        // اگر نیاز به انتخاب سرور / دسته / پلن داریم:
        if($choice == 'server'){
            $dInfo['target_type'] = 'server';
            setUser('addDiscountTargetServer' . json_encode($dInfo, JSON_UNESCAPED_UNICODE));

            $keys = ['inline_keyboard' => []];
            $stmt = $connection->prepare("SELECT `id`,`title` FROM `server_info` WHERE `active` = 1");
            $stmt->execute();
            $res = $stmt->get_result();
            $stmt->close();

            if($res->num_rows > 0){
                while($row = $res->fetch_assoc()){
                    $keys['inline_keyboard'][] = [
                        ['text' => $row['title'], 'callback_data' => 'setDiscountTargetServerId_' . $row['id']]
                    ];
                }
            }else{
                $keys['inline_keyboard'][] = [
                    ['text' => 'سروری یافت نشد.', 'callback_data' => 'wizwizch']
                ];
            }

            sendMessage("🔘 لطفا سرور مورد نظر را انتخاب کنید:", json_encode($keys));
            return;
        }

        if($choice == 'category'){
            $dInfo['target_type'] = 'category';
            setUser('addDiscountTargetCategory' . json_encode($dInfo, JSON_UNESCAPED_UNICODE));

            $keys = ['inline_keyboard' => []];
            $stmt = $connection->prepare("SELECT `id`,`title` FROM `server_categories` WHERE `active` = 1");
            $stmt->execute();
            $res = $stmt->get_result();
            $stmt->close();

            if($res->num_rows > 0){
                while($row = $res->fetch_assoc()){
                    $keys['inline_keyboard'][] = [
                        ['text' => $row['title'], 'callback_data' => 'setDiscountTargetCategoryId_' . $row['id']]
                    ];
                }
            }else{
                $keys['inline_keyboard'][] = [
                    ['text' => 'دسته‌ای یافت نشد.', 'callback_data' => 'wizwizch']
                ];
            }

            sendMessage("🔘 لطفا دسته‌بندی مورد نظر را انتخاب کنید:", json_encode($keys));
            return;
        }

        if($choice == 'plan'){
            $dInfo['target_type'] = 'plan';
            setUser('addDiscountTargetPlan' . json_encode($dInfo, JSON_UNESCAPED_UNICODE));

            $keys = ['inline_keyboard' => []];
            $stmt = $connection->prepare("SELECT `id`,`title` FROM `server_plans` WHERE `active` = 1");
            $stmt->execute();
            $res = $stmt->get_result();
            $stmt->close();

            if($res->num_rows > 0){
                while($row = $res->fetch_assoc()){
                    $keys['inline_keyboard'][] = [
                        ['text' => $row['title'], 'callback_data' => 'setDiscountTargetPlanId_' . $row['id']]
                    ];
                }
            }else{
                $keys['inline_keyboard'][] = [
                    ['text' => 'پلنی یافت نشد.', 'callback_data' => 'wizwizch']
                ];
            }

            sendMessage("🔘 لطفا پلن مورد نظر را انتخاب کنید:", json_encode($keys));
            return;
        }
    }
}

// انتخاب سرور
if(preg_match('/^addDiscountTargetServer(.*)/',$userInfo['step'],$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(preg_match('/^setDiscountTargetServerId_(\d+)$/', $data, $m)){
        $dInfo = json_decode($match[1], true);
        if(!is_array($dInfo)) $dInfo = array();

        $dInfo['target_id'] = (int)$m[1];

        setUser('addDiscountMinPrice' . json_encode($dInfo, JSON_UNESCAPED_UNICODE));
        sendMessage("🔘 حداقل مبلغ سفارش برای استفاده از این کد را به تومان وارد کنید.\nبرای بدون محدودیت 0 را وارد کنید.", $cancelKey);
    }
}

// انتخاب دسته‌بندی
if(preg_match('/^addDiscountTargetCategory(.*)/',$userInfo['step'],$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(preg_match('/^setDiscountTargetCategoryId_(\d+)$/', $data, $m)){
        $dInfo = json_decode($match[1], true);
        if(!is_array($dInfo)) $dInfo = array();

        $dInfo['target_id'] = (int)$m[1];

        setUser('addDiscountMinPrice' . json_encode($dInfo, JSON_UNESCAPED_UNICODE));
        sendMessage("🔘 حداقل مبلغ سفارش برای استفاده از این کد را به تومان وارد کنید.\nبرای بدون محدودیت 0 را وارد کنید.", $cancelKey);
    }
}

// انتخاب پلن
if(preg_match('/^addDiscountTargetPlan(.*)/',$userInfo['step'],$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(preg_match('/^setDiscountTargetPlanId_(\d+)$/', $data, $m)){
        $dInfo = json_decode($match[1], true);
        if(!is_array($dInfo)) $dInfo = array();

        $dInfo['target_id'] = (int)$m[1];

        setUser('addDiscountMinPrice' . json_encode($dInfo, JSON_UNESCAPED_UNICODE));
        sendMessage("🔘 حداقل مبلغ سفارش برای استفاده از این کد را به تومان وارد کنید.\nبرای بدون محدودیت 0 را وارد کنید.", $cancelKey);
    }
}

// تعیین حداقل مبلغ سفارش
if(preg_match('/^addDiscountMinPrice(.*)/',$userInfo['step'],$match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){

    if(!is_numeric($text)){
        sendMessage("🔘 لطفا فقط عدد به تومان وارد کنید یا 0 برای بدون محدودیت وارد کنید.");
        return;
    }

    $dInfo = json_decode($match[1], true);
    if(!is_array($dInfo)) $dInfo = array();

    $minPrice = (int)$text;
    $dInfo['min_price'] = $minPrice > 0 ? $minPrice : 0;

    setUser('addDiscountMaxDiscount' . json_encode($dInfo, JSON_UNESCAPED_UNICODE));
    sendMessage("🔘 اگر می‌خواهید سقف مبلغ تخفیف را مشخص کنید، آن را به تومان وارد کنید.\nبرای بدون سقف 0 را وارد کنید.", $cancelKey);
}

// تعیین سقف تخفیف و ثبت نهایی
if(preg_match('/^addDiscountMaxDiscount(.*)/',$userInfo['step'],$match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){

    if(!is_numeric($text)){
        sendMessage("🔘 لطفا فقط عدد به تومان وارد کنید یا 0 برای بدون سقف وارد کنید.");
        return;
    }

    $dInfo = json_decode($match[1], true);
    if(!is_array($dInfo)) $dInfo = array();

    $maxDiscount = (int)$text;
    $dInfo['max_discount'] = $maxDiscount > 0 ? $maxDiscount : 0;

    // ثبت نهایی در دیتابیس
    $hashId   = $dInfo['hash_id']; 
    $type     = $dInfo['type'];
    $amount   = (int)$dInfo['amount'];
    $date     = (int)$dInfo['date'];
    $count    = (int)$dInfo['count'];
    $canUse   = (int)$dInfo['can_use'];

    $audienceType   = isset($dInfo['audience_type'])    ? $dInfo['audience_type']    : 'all';
    $audienceUserId = isset($dInfo['audience_user_id']) ? (int)$dInfo['audience_user_id'] : 0;
    $targetType     = isset($dInfo['target_type'])      ? $dInfo['target_type']      : 'all';
    $targetId       = isset($dInfo['target_id'])        ? (int)$dInfo['target_id']   : 0;
    $minPrice       = isset($dInfo['min_price'])        ? (int)$dInfo['min_price']   : 0;
    $maxDiscount    = isset($dInfo['max_discount'])     ? (int)$dInfo['max_discount'] : 0;

    $stmt = $connection->prepare("
        INSERT INTO `discounts` 
            (`hash_id`, `type`, `amount`, `expire_date`, `expire_count`, `used_by`, `can_use`,
             `audience_type`, `audience_user_id`, `target_type`, `target_id`, `min_price`, `max_discount`)
        VALUES (?,?,?,?,?,NULL,?,?,?,?,?,?,?)
    "); 

    $stmt->bind_param(
        "ssiiiisisiii",
        $hashId,
        $type,
        $amount,
        $date,
        $count,
        $canUse,
        $audienceType,
        $audienceUserId,
        $targetType,
        $targetId,
        $minPrice,
        $maxDiscount
    );

    $stmt->execute(); 
    $stmt->close(); 

    sendMessage("✅ کد تخفیف جدید (<code>$hashId</code>) با موفقیت ساخته شد.", $removeKeyboard, "HTML"); 
    setUser(); 
    sendMessage("🎁 مدیریت کدهای تخفیف", getDiscountCodeKeys()); 
} 

// ❓ درخواست تأیید حذف
if(preg_match('/^delDiscountConfirm(\d+)/', $data, $m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $discountId = (int)$m[1];

    $keys = [
        'inline_keyboard' => [
            [
                ['text' => '✅ بله، حذف شود', 'callback_data' => "delDiscount" . $discountId],
                ['text' => '❌ خیر، بازگشت', 'callback_data' => "discount_codes"],
            ]
        ]
    ];

    sendMessage("⚠️ آیا از حذف این کد تخفیف مطمئن هستید؟", json_encode($keys));
}

// 🗑 حذف نهایی کد تخفیف
if(preg_match('/^delDiscount(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){ 
    $discountId = (int)$match[1];

    $stmt = $connection->prepare("DELETE FROM `discounts` WHERE `id` = ?"); 
    $stmt->bind_param("i", $discountId); 
    $stmt->execute(); 
    $stmt->close(); 
     
    alert("کد تخفیف مورد نظر با موفقیت حذف شد."); 
    editText($message_id,"🎁 مدیریت کدهای تخفیف",getDiscountCodeKeys(1)); 
}

// ✏️ نمایش منوی ویرایش برای یک کد تخفیف
if(preg_match('/^editDiscount(\d+)/', $data, $m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){

    $discountId = (int)$m[1];

    $stmt = $connection->prepare("SELECT * FROM `discounts` WHERE `id` = ? LIMIT 1");
    $stmt->bind_param("i", $discountId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if(!$row){
        alert("کد تخفیف یافت نشد.");
        return;
    }

    $hashId = $row['hash_id'];

    // ساخت خلاصه برای نمایش
    $dateText  = $row['expire_date'] != 0 ? jdate("Y/n/j H:i", $row['expire_date']) : "نامحدود";
    $countText = $row['expire_count'] != -1 ? $row['expire_count'] : "نامحدود";
    $canUse    = $row['can_use'];
    $canUseText = $canUse != -1 ? $canUse : "نامحدود";

    if($row['type'] == 'percent'){
        $amountText = $row['amount'] . "%";
    }else{
        $amountText = number_format($row['amount']) . " تومان";
    }

    $minPrice     = isset($row['min_price']) ? (int)$row['min_price'] : 0;
    $minPriceText = $minPrice > 0 ? number_format($minPrice) . " تومان" : "بدون حداقل";

    $maxDiscount     = isset($row['max_discount']) ? (int)$row['max_discount'] : 0;
    $maxDiscountText = $maxDiscount > 0 ? number_format($maxDiscount) . " تومان" : "بدون سقف";

    $audienceType   = isset($row['audience_type']) ? $row['audience_type'] : 'all';
    $audienceUserId = isset($row['audience_user_id']) ? (int)$row['audience_user_id'] : 0;

    switch($audienceType){
        case 'user':
            $audienceText = "کاربر خاص (#{$audienceUserId})";
            break;
        case 'agents':
            $audienceText = "همکاران";
            break;
        case 'non_agents':
            $audienceText = "کاربران غیر همکار";
            break;
        case 'buyers':
            $audienceText = "دارای خرید";
            break;
        case 'non_buyers':
            $audienceText = "بدون خرید";
            break;
        case 'has_active_order':
            $audienceText = "دارای سفارش";
            break;
        case 'no_active_order':
            $audienceText = "بدون سفارش";
            break;
        case 'all':
        default:
            $audienceText = "همه کاربران";
    }

    $targetType = isset($row['target_type']) ? $row['target_type'] : 'all';
    $targetId   = isset($row['target_id']) ? (int)$row['target_id'] : 0;
    $targetText = "همه پلن‌ها";

    if($targetType == 'server' && $targetId > 0){
        $stmt2 = $connection->prepare("SELECT `title` FROM `server_info` WHERE `id` = ? LIMIT 1");
        $stmt2->bind_param("i", $targetId);
        $stmt2->execute();
        $res2 = $stmt2->get_result()->fetch_assoc();
        $stmt2->close();
        $title = $res2 ? $res2['title'] : ("ID " . $targetId);
        $targetText = "سرور: " . $title;
    }elseif($targetType == 'category' && $targetId > 0){
        $stmt2 = $connection->prepare("SELECT `title` FROM `server_categories` WHERE `id` = ? LIMIT 1");
        $stmt2->bind_param("i", $targetId);
        $stmt2->execute();
        $res2 = $stmt2->get_result()->fetch_assoc();
        $stmt2->close();
        $title = $res2 ? $res2['title'] : ("ID " . $targetId);
        $targetText = "دسته: " . $title;
    }elseif($targetType == 'plan' && $targetId > 0){
        $stmt2 = $connection->prepare("SELECT `title` FROM `server_plans` WHERE `id` = ? LIMIT 1");
        $stmt2->bind_param("i", $targetId);
        $stmt2->execute();
        $res2 = $stmt2->get_result()->fetch_assoc();
        $stmt2->close();
        $title = $res2 ? $res2['title'] : ("ID " . $targetId);
        $targetText = "پلن: " . $title;
    }

    $text = "✏️ ویرایش کد تخفیف <code>{$hashId}</code>\n\n".
            "▪️ مقدار تخفیف: <b>{$amountText}</b>\n".
            "▪️ تاریخ انقضا: <b>{$dateText}</b>\n".
            "▪️ تعداد کل قابل استفاده: <b>{$countText}</b>\n".
            "▪️ محدودیت استفاده هر کاربر: <b>{$canUseText}</b>\n".
            "▪️ گروه کاربران: <b>{$audienceText}</b>\n".
            "▪️ هدف سرویس: <b>{$targetText}</b>\n".
            "▪️ حداقل مبلغ: <b>{$minPriceText}</b>\n".
            "▪️ سقف تخفیف: <b>{$maxDiscountText}</b>";

    $keys = [
        'inline_keyboard' => [
            [
                ['text' => 'مقدار تخفیف',    'callback_data' => "editD_{$discountId}_amount"],
                ['text' => 'تاریخ انقضا',    'callback_data' => "editD_{$discountId}_expire"],
            ],
            [
                ['text' => 'تعداد کل',       'callback_data' => "editD_{$discountId}_count"],
                ['text' => 'محدودیت استفاده', 'callback_data' => "editD_{$discountId}_canuse"],
            ],
            [
                ['text' => 'گروه کاربران',   'callback_data' => "editD_{$discountId}_audience"],
                ['text' => 'هدف سرویس',      'callback_data' => "editD_{$discountId}_target"],
            ],
            [
                ['text' => 'حداقل مبلغ',     'callback_data' => "editD_{$discountId}_minprice"],
                ['text' => 'سقف تخفیف',      'callback_data' => "editD_{$discountId}_maxdiscount"],
            ],
            [
                ['text' => 'حذف کاربر از استفاده کنندگان', 'callback_data' => "editDRemoveUser{$discountId}"],
            ],
            [
                ['text' => '↪️ بازگشت به لیست', 'callback_data' => "discount_codes"],
            ],
        ]
    ];

    editText($message_id, $text, json_encode($keys), "HTML");
}

// ویرایش مقدار تخفیف
if(preg_match('/^editD_(\d+)_amount$/', $data, $m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $discountId = (int)$m[1];

    setUser("editDiscountAmount{$discountId}");
    sendMessage(
        "🔘 مقدار تخفیف جدید را وارد کنید.\n".
        "برای درصد، علامت % را کنار عدد بگذارید (مثال: 20%)\n".
        "در غیر این صورت مقدار تخفیف به تومان محاسبه می‌شود.",
        $cancelKey
    );
}

if(preg_match('/^editDiscountAmount(\d+)$/', $userInfo['step'], $m) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $discountId = (int)$m[1];

    $newType = 'amount';
    if(strstr($text, "%")) $newType = 'percent';

    $val = trim(str_replace("%", "", $text));

    if(!is_numeric($val)){
        sendMessage("🔘 لطفا فقط عدد و یا درصد بفرستید.");
        return;
    }

    $amount = (int)$val;

    $stmt = $connection->prepare("UPDATE `discounts` SET `type` = ?, `amount` = ? WHERE `id` = ?");
    $stmt->bind_param("sii", $newType, $amount, $discountId);
    $stmt->execute();
    $stmt->close();

    setUser();

    sendMessage("✅ مقدار تخفیف با موفقیت بروزرسانی شد.");
    $data = "editDiscount".$discountId;
}

// ویرایش تاریخ انقضا
if(preg_match('/^editD_(\d+)_expire$/', $data, $m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $discountId = (int)$m[1];

    setUser("editDiscountExpire{$discountId}");
    sendMessage(
        "🔘 مدت زمان جدید این تخفیف را به روز وارد کنید.\n".
        "برای نامحدود بودن 0 را وارد کنید.",
        $cancelKey
    );
}

if(preg_match('/^editDiscountExpire(\d+)$/', $userInfo['step'], $m) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $discountId = (int)$m[1];

    if(!is_numeric($text)){
        sendMessage("🔘 لطفا فقط عدد بفرستید.");
        return;
    }

    $days = (int)$text;
    $newDate = $days != 0 ? time() + ($days * 24 * 60 * 60) : 0;

    $stmt = $connection->prepare("UPDATE `discounts` SET `expire_date` = ? WHERE `id` = ?");
    $stmt->bind_param("ii", $newDate, $discountId);
    $stmt->execute();
    $stmt->close();

    setUser();
    sendMessage("✅ تاریخ انقضا با موفقیت بروزرسانی شد.");
    $data = "editDiscount".$discountId;
}

// ویرایش تعداد کل استفاده
if(preg_match('/^editD_(\d+)_count$/', $data, $m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $discountId = (int)$m[1];

    setUser("editDiscountCount{$discountId}");
    sendMessage(
        "🔘 تعداد جدید استفاده برای این کد را وارد کنید.\n".
        "برای نامحدود بودن 0 وارد کنید.",
        $cancelKey
    );
}

if(preg_match('/^editDiscountCount(\d+)$/', $userInfo['step'], $m) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $discountId = (int)$m[1];

    if(!is_numeric($text)){
        sendMessage("🔘 لطفا فقط عدد بفرستید.");
        return;
    }

    $count = (int)$text;
    $newCount = $count > 0 ? $count : -1;

    $stmt = $connection->prepare("UPDATE `discounts` SET `expire_count` = ? WHERE `id` = ?");
    $stmt->bind_param("ii", $newCount, $discountId);
    $stmt->execute();
    $stmt->close();

    setUser();
    sendMessage("✅ تعداد کل استفاده با موفقیت بروزرسانی شد.");
    $data = "editDiscount".$discountId;
}

// ویرایش تعداد استفاده هر کاربر
if(preg_match('/^editD_(\d+)_canuse$/', $data, $m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $discountId = (int)$m[1];

    setUser("editDiscountCanUse{$discountId}");
    sendMessage(
        "🔘 تعداد استفاده هر کاربر از این کد را وارد کنید.\n".
        "برای نامحدود بودن 0 وارد کنید.",
        $cancelKey
    );
}

if(preg_match('/^editDiscountCanUse(\d+)$/', $userInfo['step'], $m) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $discountId = (int)$m[1];

    if(!is_numeric($text)){
        sendMessage("🔘 لطفا فقط عدد بفرستید.");
        return;
    }

    $v = (int)$text;
    $newVal = $v > 0 ? $v : -1;

    $stmt = $connection->prepare("UPDATE `discounts` SET `can_use` = ? WHERE `id` = ?");
    $stmt->bind_param("ii", $newVal, $discountId);
    $stmt->execute();
    $stmt->close();

    setUser();
    sendMessage("✅ تعداد استفاده هر کاربر با موفقیت بروزرسانی شد.");
    $data = "editDiscount".$discountId;
}

// شروع ویرایش گروه کاربران
if(preg_match('/^editD_(\d+)_audience$/', $data, $m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $discountId = (int)$m[1];

    $keys = [
        'inline_keyboard' => [
            [
                ['text' => 'همه کاربران',        'callback_data' => "editDAud_{$discountId}_all"],
            ],
            [
                ['text' => 'یک کاربر خاص',       'callback_data' => "editDAud_{$discountId}_user"],
            ],
            [
                ['text' => 'همکاران (agents)',   'callback_data' => "editDAud_{$discountId}_agents"],
            ],
			[
				['text' => 'کاربران غیر همکار',  'callback_data' => "editDAud_{$discountId}_non_agents"],
			],
            [
                ['text' => 'کاربران دارای خرید', 'callback_data' => "editDAud_{$discountId}_buyers"],
            ],
            [
                ['text' => 'کاربران بدون خرید',  'callback_data' => "editDAud_{$discountId}_non_buyers"],
            ],
            [
                ['text' => 'دارای سفارش',        'callback_data' => "editDAud_{$discountId}_has_active_order"],
            ],
            [
                ['text' => 'بدون سفارش',         'callback_data' => "editDAud_{$discountId}_no_active_order"],
            ],
        ]
    ];

    sendMessage("🔘 گروه کاربران جدید برای این کد را انتخاب کنید:", json_encode($keys));
}

// ست کردن audience (به جز حالت user)
if(preg_match('/^editDAud_(\d+)_(.+)$/', $data, $m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $discountId = (int)$m[1];
    $choice     = $m[2];

    if($choice == 'user'){
        setUser("editDiscountAudienceUser{$discountId}");
        sendMessage("🔘 لطفا شناسه عددی کاربر مورد نظر را وارد کنید:", $cancelKey);
        return;
    }

    $audienceType = 'all';
    switch($choice){
        case 'all':               $audienceType = 'all'; break;
        case 'agents':            $audienceType = 'agents'; break;
		case 'non_agents':        $audienceType = 'non_agents'; break;
        case 'buyers':            $audienceType = 'buyers'; break;
        case 'non_buyers':        $audienceType = 'non_buyers'; break;
        case 'has_active_order':  $audienceType = 'has_active_order'; break;
        case 'no_active_order':   $audienceType = 'no_active_order'; break;
    }

    $audienceUserId = 0;

    $stmt = $connection->prepare("UPDATE `discounts` SET `audience_type` = ?, `audience_user_id` = ? WHERE `id` = ?");
    $stmt->bind_param("sii", $audienceType, $audienceUserId, $discountId);
    $stmt->execute();
    $stmt->close();

    setUser();
    sendMessage("✅ گروه کاربران با موفقیت بروزرسانی شد.");
    $data = "editDiscount".$discountId;
}

// گرفتن userid برای audience = user
if(preg_match('/^editDiscountAudienceUser(\d+)$/', $userInfo['step'], $m) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $discountId = (int)$m[1];

    if(!is_numeric($text)){
        sendMessage("🔘 لطفا فقط عدد (userid) وارد کنید.");
        return;
    }

    $uid = (int)$text;

    $audienceType = 'user';
    $stmt = $connection->prepare("UPDATE `discounts` SET `audience_type` = ?, `audience_user_id` = ? WHERE `id` = ?");
    $stmt->bind_param("sii", $audienceType, $uid, $discountId);
    $stmt->execute();
    $stmt->close();

    setUser();
    sendMessage("✅ این کد فقط برای کاربر #{$uid} فعال شد.");
    $data = "editDiscount".$discountId;
}

// شروع ویرایش هدف سرویس
if(preg_match('/^editD_(\d+)_target$/', $data, $m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $discountId = (int)$m[1];

    $keys = [
        'inline_keyboard' => [
            [
                ['text' => 'همه پلن‌ها',       'callback_data' => "editDT_{$discountId}_all"],
            ],
            [
                ['text' => 'فقط یک سرور',      'callback_data' => "editDT_{$discountId}_server"],
            ],
            [
                ['text' => 'فقط یک دسته‌بندی', 'callback_data' => "editDT_{$discountId}_category"],
            ],
            [
                ['text' => 'فقط یک پلن',       'callback_data' => "editDT_{$discountId}_plan"],
            ],
        ]
    ];

    sendMessage("🔘 هدف سرویس جدید را انتخاب کنید:", json_encode($keys));
}

if(preg_match('/^editDT_(\d+)_(.+)$/', $data, $m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $discountId = (int)$m[1];
    $choice     = $m[2];

    if($choice == 'all'){
        $targetType = 'all';
        $targetId   = 0;

        $stmt = $connection->prepare("UPDATE `discounts` SET `target_type` = ?, `target_id` = ? WHERE `id` = ?");
        $stmt->bind_param("sii", $targetType, $targetId, $discountId);
        $stmt->execute();
        $stmt->close();

        sendMessage("✅ این کد برای همه پلن‌ها معتبر شد.");
        $data = "editDiscount".$discountId;
        return;
    }

    // برای حالت‌های server/category/plan باید لیست را نشان دهیم
    if($choice == 'server'){
        // لیست سرورها
        $keys = ['inline_keyboard' => []];
        $stmt = $connection->prepare("SELECT `id`,`title` FROM `server_info` WHERE `active` = 1");
        $stmt->execute();
        $res = $stmt->get_result();
        $stmt->close();
        if($res->num_rows > 0){
            while($row = $res->fetch_assoc()){
                $keys['inline_keyboard'][] = [
                    ['text' => $row['title'], 'callback_data' => "editDTServer_{$discountId}_".$row['id']]
                ];
            }
        }else{
            $keys['inline_keyboard'][] = [
                ['text' => 'سروری یافت نشد.', 'callback_data' => 'wizwizch']
            ];
        }

        sendMessage("🔘 لطفا سرور مورد نظر را انتخاب کنید:", json_encode($keys));
        return;
    }

    if($choice == 'category'){
        $keys = ['inline_keyboard' => []];
        $stmt = $connection->prepare("SELECT `id`,`title` FROM `server_categories` WHERE `active` = 1");
        $stmt->execute();
        $res = $stmt->get_result();
        $stmt->close();
        if($res->num_rows > 0){
            while($row = $res->fetch_assoc()){
                $keys['inline_keyboard'][] = [
                    ['text' => $row['title'], 'callback_data' => "editDTCategory_{$discountId}_".$row['id']]
                ];
            }
        }else{
            $keys['inline_keyboard'][] = [
                ['text' => 'دسته‌ای یافت نشد.', 'callback_data' => 'wizwizch']
            ];
        }

        sendMessage("🔘 لطفا دسته‌بندی مورد نظر را انتخاب کنید:", json_encode($keys));
        return;
    }

    if($choice == 'plan'){
        $keys = ['inline_keyboard' => []];
        $stmt = $connection->prepare("SELECT `id`,`title` FROM `server_plans` WHERE `active` = 1");
        $stmt->execute();
        $res = $stmt->get_result();
        $stmt->close();
        if($res->num_rows > 0){
            while($row = $res->fetch_assoc()){
                $keys['inline_keyboard'][] = [
                    ['text' => $row['title'], 'callback_data' => "editDTPlan_{$discountId}_".$row['id']]
                ];
            }
        }else{
            $keys['inline_keyboard'][] = [
                ['text' => 'پلنی یافت نشد.', 'callback_data' => 'wizwizch']
            ];
        }

        sendMessage("🔘 لطفا پلن مورد نظر را انتخاب کنید:", json_encode($keys));
        return;
    }
}

// انتخاب سرور
if(preg_match('/^editDTServer_(\d+)_(\d+)$/', $data, $m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $discountId = (int)$m[1];
    $serverId   = (int)$m[2];

    $targetType = 'server';
    $stmt = $connection->prepare("UPDATE `discounts` SET `target_type` = ?, `target_id` = ? WHERE `id` = ?");
    $stmt->bind_param("sii", $targetType, $serverId, $discountId);
    $stmt->execute();
    $stmt->close();

    sendMessage("✅ این کد فقط برای سرور انتخاب شده معتبر شد.");
    $data = "editDiscount".$discountId;
}

// انتخاب دسته
if(preg_match('/^editDTCategory_(\d+)_(\d+)$/', $data, $m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $discountId = (int)$m[1];
    $catId      = (int)$m[2];

    $targetType = 'category';
    $stmt = $connection->prepare("UPDATE `discounts` SET `target_type` = ?, `target_id` = ? WHERE `id` = ?");
    $stmt->bind_param("sii", $targetType, $catId, $discountId);
    $stmt->execute();
    $stmt->close();

    sendMessage("✅ این کد فقط برای دسته‌بندی انتخاب شده معتبر شد.");
    $data = "editDiscount".$discountId;
}

// انتخاب پلن
if(preg_match('/^editDTPlan_(\d+)_(\d+)$/', $data, $m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $discountId = (int)$m[1];
    $planId     = (int)$m[2];

    $targetType = 'plan';
    $stmt = $connection->prepare("UPDATE `discounts` SET `target_type` = ?, `target_id` = ? WHERE `id` = ?");
    $stmt->bind_param("sii", $targetType, $planId, $discountId);
    $stmt->execute();
    $stmt->close();

    sendMessage("✅ این کد فقط برای پلن انتخاب شده معتبر شد.");
    $data = "editDiscount".$discountId;
}

// شروع ویرایش حداقل مبلغ
if(preg_match('/^editD_(\d+)_minprice$/', $data, $m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $discountId = (int)$m[1];

    setUser("editDiscountMinPrice{$discountId}");
    sendMessage(
        "🔘 حداقل مبلغ سفارش برای استفاده از این کد را به تومان وارد کنید.\n".
        "برای بدون محدودیت 0 را وارد کنید.",
        $cancelKey
    );
}

if(preg_match('/^editDiscountMinPrice(\d+)$/', $userInfo['step'], $m) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $discountId = (int)$m[1];

    if(!is_numeric($text)){
        sendMessage("🔘 لطفا فقط عدد به تومان وارد کنید.");
        return;
    }

    $v = (int)$text;
    $minPrice = $v > 0 ? $v : 0;

    $stmt = $connection->prepare("UPDATE `discounts` SET `min_price` = ? WHERE `id` = ?");
    $stmt->bind_param("ii", $minPrice, $discountId);
    $stmt->execute();
    $stmt->close();

    setUser();
    sendMessage("✅ حداقل مبلغ سفارش با موفقیت بروزرسانی شد.");
    $data = "editDiscount".$discountId;
}

// شروع ویرایش سقف تخفیف
if(preg_match('/^editD_(\d+)_maxdiscount$/', $data, $m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $discountId = (int)$m[1];

    setUser("editDiscountMaxDiscount{$discountId}");
    sendMessage(
        "🔘 اگر می‌خواهید سقف مبلغ تخفیف را مشخص کنید، آن را به تومان وارد کنید.\n".
        "برای بدون سقف 0 را وارد کنید.",
        $cancelKey
    );
}

if(preg_match('/^editDiscountMaxDiscount(\d+)$/', $userInfo['step'], $m) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $discountId = (int)$m[1];

    if(!is_numeric($text)){
        sendMessage("🔘 لطفا فقط عدد به تومان وارد کنید.");
        return;
    }

    $v = (int)$text;
    $maxDiscount = $v > 0 ? $v : 0;

    $stmt = $connection->prepare("UPDATE `discounts` SET `max_discount` = ? WHERE `id` = ?");
    $stmt->bind_param("ii", $maxDiscount, $discountId);
    $stmt->execute();
    $stmt->close();

    setUser();
    sendMessage("✅ سقف مبلغ تخفیف با موفقیت بروزرسانی شد.");
    $data = "editDiscount".$discountId;
}

if (preg_match('/^editDRemoveUser(\d+)$/', $data, $m) && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $discountId = (int)$m[1];

    // ذخیره step برای ادمین
    setUser("removeDiscountUser" . $discountId);

    global $cancelKey;

    sendMessage(
        "لطفاً شناسه کاربر (userid تلگرام) که می‌خواهید از لیست استفاده‌کنندگان این کد حذف شود را ارسال کنید.\n\n".
        "✅ فقط عدد بفرستید.\n".
        "❌ برای انصراف دکمه لغو را بزنید.",
        $cancelKey
    );
}

// 🧹 گرفتن userid و حذفش از used_by
if (preg_match('/^removeDiscountUser(\d+)$/', $userInfo['step'], $m) 
    && $text != $buttonValues['cancel'] 
    && ($from_id == $admin || $userInfo['isAdmin'] == true)
) {
    $discountId   = (int)$m[1];
    $userToRemove = trim($text);

    if (!ctype_digit($userToRemove)) {
        sendMessage("❌ لطفاً فقط شناسه کاربر کاربر را وارد کنید.");
        return;
    }

    $userToRemove = (int)$userToRemove;

    // گرفتن used_by و expire_count از جدول discounts
    $stmt = $connection->prepare("SELECT `used_by`, `expire_count` FROM `discounts` WHERE `id` = ? LIMIT 1");
    $stmt->bind_param("i", $discountId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        sendMessage("❌ کد تخفیف مورد نظر یافت نشد.");
        setUser();
        return;
    }

    // تبدیل used_by به آرایه
    $usedBy = !is_null($row['used_by']) ? json_decode($row['used_by'], true) : [];
    if (!is_array($usedBy)) $usedBy = [];

    $beforeCount = count($usedBy);

    $filtered = [];
    foreach ($usedBy as $uid) {
        if ((int)$uid !== $userToRemove) {
            $filtered[] = $uid;
        }
    }

    $afterCount   = count($filtered);
    $removedCount = $beforeCount - $afterCount;

    if ($removedCount <= 0) {
        sendMessage("ℹ️ این کاربر هیچ استفاده‌ای از این کد ثبت نکرده است.");
        setUser();
        return;
    }

    // آپدیت used_by
    $encodeUsedBy = json_encode($filtered, JSON_UNESCAPED_UNICODE);

    $expireCount = (int)$row['expire_count'];
    if ($expireCount != -1) { 
        $expireCount += $removedCount;
        if ($expireCount < 0) $expireCount = 0;
    }

    $stmt = $connection->prepare("UPDATE `discounts` SET `used_by` = ?, `expire_count` = ? WHERE `id` = ?");
    $stmt->bind_param("sii", $encodeUsedBy, $expireCount, $discountId);
    $stmt->execute();
    $stmt->close();

    sendMessage(
        "✅ کاربر با شناسه <code>{$userToRemove}</code> در <b>{$removedCount}</b> استفاده از این کد حذف شد.\n".
        "ظرفیت استفاده کلی کد نیز به‌روزرسانی شد.",
        null,
        "HTML"
    );

    setUser();
}

// 🔄 فعال / غیرفعال کردن کد تخفیف
if(preg_match('/^toggleDiscount(\d+)$/', $data, $m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $discountId = (int)$m[1];

    // گرفتن وضعیت فعلی
    $stmt = $connection->prepare("SELECT `is_active` FROM `discounts` WHERE `id` = ? LIMIT 1");
    $stmt->bind_param("i", $discountId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if(!$row){
        alert("کد تخفیف یافت نشد.");
        return;
    }

    $current = (int)$row['is_active'];
    $newVal  = $current ? 0 : 1;

    $stmt = $connection->prepare("UPDATE `discounts` SET `is_active` = ? WHERE `id` = ?");
    $stmt->bind_param("ii", $newVal, $discountId);
    $stmt->execute();
    $stmt->close();

    if($newVal){
        alert("✅ کد تخفیف فعال شد.");
    }else{
        alert("⛔️ کد تخفیف غیرفعال شد.");
    }
	
    editText($message_id, "🎁 مدیریت کدهای تخفیف", getDiscountCodeKeys(1));
}

if(preg_match('/^copyHash(.*)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    sendMessage("<code>" . $match[1] . "</code>",null,"HTML");
}
// END Manage discount

if($data == "managePanel" and (($from_id == $admin || $userInfo['isAdmin'] == true))){
    
    setUser();
    $msg = "
👤 به بخش مدیریت خوش اومدی $first_name

🚪 /start
";
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
    setUser();
    $stmt = $connection->prepare("DELETE FROM `server_plans` WHERE `active`=0");
    $stmt->execute();
    $stmt->close();

    sendMessage($mainValues['waiting_message'], $removeKeyboard);
    sendMessage($mainValues['reached_main_menu'],getMainKeys());
}
?>
