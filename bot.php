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

if(($data == "agentOneBuy" || $data=='buySubscription' || $data == "agentMuchBuy") && ($botState['sellState']=="on" || ($from_id == $admin || $userInfo['isAdmin'] == true))){
    if($botState['cartToCartState'] == "off" && $botState['walletState'] == "off"){
        alert($mainValues['selling_is_off']);
        exit();
    }
    if($data=="buySubscription") $buyType = "none";
    elseif($data=="agentOneBuy") $buyType = "one";
    elseif($data== "agentMuchBuy") $buyType = "much";
    
    $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `active`=1 and `state` = 1 and `ucount` > 0 ORDER BY `id` ASC");
    $stmt->execute();
    $respd = $stmt->get_result();
    $stmt->close();
    if($respd->num_rows==0){
        alert($mainValues['no_server_available']);
        exit;
    }
    $keyboard = [];
    while($cat = $respd->fetch_assoc()){
        $id = $cat['id'];
        $name = $cat['title'];
        $flag = $cat['flag'];
        $keyboard[] = ['text' => "$flag $name", 'callback_data' => "selectServer{$id}_{$buyType}"];
    }
    $keyboard = array_chunk($keyboard,1);
    $keyboard[] = [['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"]];
    editText($message_id, $mainValues['buy_sub_select_location'], json_encode(['inline_keyboard'=>$keyboard]));
}

if($data=='createMultipleAccounts' && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `active`=1 and `ucount` > 0 ORDER BY `id` ASC");
    $stmt->execute();
    $respd = $stmt->get_result();
    $stmt->close();
    if($respd->num_rows==0){
        sendMessage($mainValues['no_server_available']);
        exit;
    }
    $keyboard = [];
    while($cat = $respd->fetch_assoc()){
        $id = $cat['id'];
        $name = $cat['title'];
        $flag = $cat['flag'];
        $keyboard[] = ['text' => "$flag $name", 'callback_data' => "createAccServer$id"];
    }
    $keyboard[] = ['text'=>$buttonValues['back_to_main'],'callback_data'=>"managePanel"];
    $keyboard = array_chunk($keyboard,1);
    editText($message_id, $mainValues['buy_sub_select_location'], json_encode(['inline_keyboard'=>$keyboard]));
    

}
if(preg_match('/createAccServer(\d+)/',$data, $match) && ($from_id == $admin || $userInfo['isAdmin'] == true) ) {
    $sid = $match[1];
        
    $stmt = $connection->prepare("SELECT * FROM `server_categories` WHERE `parent`=0 order by `id` asc");
    $stmt->execute();
    $respd = $stmt->get_result();
    $stmt->close();
    if($respd->num_rows == 0){
        alert("هیچ دسته بندی برای این سرور وجود ندارد.");
    }else{
        
        $keyboard = [];
        while ($file = $respd->fetch_assoc()){
            $id = $file['id'];
            $name = $file['title'];
            $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `server_id`=? and `catid`=? and `active`=1");
            $stmt->bind_param("ii", $sid, $id);
            $stmt->execute();
            $rowcount = $stmt->get_result()->num_rows; 
            $stmt->close();
            if($rowcount>0) $keyboard[] = ['text' => "$name", 'callback_data' => "createAccCategory{$id}_{$sid}"];
        }
        if(empty($keyboard)){
            alert("هیچ دسته بندی برای این سرور وجود ندارد.");exit;
        }
        alert("♻️ دریافت دسته بندی ...");
        $keyboard[] = ['text' => $buttonValues['back_to_main'], 'callback_data' => "createMultipleAccounts"];
        $keyboard = array_chunk($keyboard,1);
        editText($message_id, "2️⃣ مرحله دو

دسته بندی مورد نظرت رو انتخاب کن 🤭", json_encode(['inline_keyboard'=>$keyboard]));
    }

}
if(preg_match('/createAccCategory(\d+)_(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $call_id = $match[1];
    $sid = $match[2];
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `server_id`=? and `catid`=? and `active`=1 order by `id` asc");
    $stmt->bind_param("ii", $sid, $call_id);
    $stmt->execute();
    $respd = $stmt->get_result();
    $stmt->close();
    if($respd->num_rows==0){
        alert("💡 پلنی در این دسته بندی وجود ندارد. ");
    }else{
        alert("📍 در حال دریافت لیست پلن ها");
        $keyboard = [];
        while($file = $respd->fetch_assoc()){
            $id = $file['id'];
            $name = $file['title'];
            $keyboard[] = ['text' => "$name", 'callback_data' => "createAccPlan{$id}"];
        }
        $keyboard[] = ['text' => $buttonValues['back_to_main'], 'callback_data' => "createAccServer$sid"];
        $keyboard = array_chunk($keyboard,1);
        editText($message_id, "3️⃣ مرحله سه

یکی از پلن هارو انتخاب کن و برو برای پرداختش 🤲 🕋", json_encode(['inline_keyboard'=>$keyboard]));
    }

}
if(preg_match('/^createAccPlan(\d+)/',$data,$match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage("❗️ لطفا مدت زمان اکانت را به ( روز ) وارد کن:",$cancelKey);
    setUser('createAccDate' . $match[1]);
}
if(preg_match('/^createAccDate(\d+)/',$userInfo['step'],$match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(is_numeric($text)){
        if($text >0){
            sendMessage("❕ حجم اکانت ها رو به گیگابایت ( GB ) وارد کن:");
            setUser('createAccVolume' . $match[1] . "_" . $text);
        }else{
            sendMessage("عدد باید بیشتر از 0 باشه");
        }
    }else{
        sendMessage('😡 مگه نمیگم فقط عدد بفرس نمیفهمی؟ یا خودتو زدی به نفهمی؟');
    }
}
if(preg_match('/^createAccVolume(\d+)_(\d+)/',$userInfo['step'],$match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(!is_numeric($text)){
        sendMessage($mainValues['send_only_number']);
        exit();
    }elseif($text <=0){
        sendMessage("مقداری بزرگتر از 0 وارد کن");
        exit();
    }
    sendMessage($mainValues['enter_account_amount']);
    setUser("createAccAmount" . $match[1] . "_" . $match[2] . "_" . $text);
}

if (
    preg_match('/^createAccAmount(\d+)_(\d+)_(\d+)/', $userInfo['step'], $match) &&
    $text != $buttonValues['cancel'] &&
    ($from_id == $admin || ($userInfo['isAdmin'] ?? false) == true)
) {

    setUser();

    if (!is_numeric($text)) {
        sendMessage($mainValues['send_only_number']);
        exit();
    }
    $count = (int)$text;
    if ($count <= 0) {
        sendMessage("مقداری بزرگتر از 0 وارد کن");
        exit();
    }

    $uid = (int)$from_id;
    $fidFromStep    = (int)$match[1];
    $daysFromStep   = (int)$match[2];
    $volumeFromStep = (int)$match[3];

    $planDetails = getPlanDetails($connection, $fidFromStep);
    $plan        = $planDetails['plan'];

    $days   = $daysFromStep;
    $volume = $volumeFromStep;

    $date             = time();
    $expire_microdate = floor(microtime(true) * 1000) + (864000 * $days * 100);
    $expire_date      = $date + (86400 * $days);

    $price      = isset($plan['price']) ? (int)$plan['price'] : 0;
    $server_id  = (int)$plan['server_id'];
    $inbound_id = (int)$plan['inbound_id'];
    $acount     = (int)$plan['acount'];

    $protocol   = $plan['protocol'];
    $netType    = $plan['type'];
    $rahgozar   = $plan['rahgozar'];
    $customPath = $plan['custom_path'];
    $customPort = $plan['custom_port'];
    $customSni  = $plan['custom_sni'];
    $limitip    = $plan['limitip'];

    ensureServerCapacity($connection, $server_id, $inbound_id, $acount, $mainValues);

    if ($inbound_id != 0 && $acount < $count) {
        sendMessage(str_replace("AMOUNT", $acount, $mainValues['can_create_specific_account']));
        exit();
    }

    [$serverInfo, $serverConfig] = getServerInfoAndConfig($connection, $server_id);

    $srv_remark = $serverInfo['remark'] ?? '';
    $serverType = $serverConfig['type'] ?? '';
    $portType   = $serverConfig['port_type'] ?? 'rand';

    alert($mainValues['sending_config_to_user']);

    $lastRemark = '';

    for ($i = 1; $i <= $count; $i++) {

        $uniqid = generateRandomString(42, $protocol);

        [$port] = getNextPortAndIncrease('settings/temp.txt', $portType);

        $remark = buildRemark(
            $botState,
            $srv_remark,
            $plan['description'] ?? '',
            $uid,
            null
        );

        [$response, $remark, $port] = createServerAccount(
            $serverType,
            $server_id,
            $inbound_id,
            $fidFromStep,
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

        $lastRemark = $remark;

        [$token, $vray_link, $uniqidReturned] = buildLinksAndSendQr(
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

        // ثبت سفارش
        insertOrder(
            $connection,
            $uid,
            $token,
            $fidFromStep,
            $server_id,
            $inbound_id,
            $remark,
            $uniqidReturned,
            $protocol,
            $expire_date,
            $vray_link,
            $price,
            $date,
            $rahgozar,
            0
        );
    }

    decreaseServerCapacity($connection, $server_id, $inbound_id, $fidFromStep, $count);

    sendMessage("☑️|❤️ اکانت های جدید با موفقیت ساخته شد.", getMainKeys());
    setUser();
}

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

if(preg_match('/selectServer(?<serverId>\d+)_(?<buyType>\w+)/',$data, $match) && ($botState['sellState']=="on" || ($from_id == $admin || $userInfo['isAdmin'] == true)) ) {
    $sid = $match['serverId'];
        
    $stmt = $connection->prepare("SELECT * FROM `server_categories` WHERE `parent`=0 order by `id` asc");
    $stmt->execute();
    $respd = $stmt->get_result();
    $stmt->close();
    if($respd->num_rows == 0){
        alert($mainValues['category_not_avilable']);
    }else{
        
        $keyboard = [];
        while ($file = $respd->fetch_assoc()){
            $id = $file['id'];
            $name = $file['title'];
            $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `server_id`=? and `catid`=? and `active`=1");
            $stmt->bind_param("ii", $sid, $id);
            $stmt->execute();
            $rowcount = $stmt->get_result()->num_rows; 
            $stmt->close();
            if($rowcount>0) $keyboard[] = ['text' => "$name", 'callback_data' => "selectCategory{$id}_{$sid}_{$match['buyType']}"];
        }
        if(empty($keyboard)){
            alert($mainValues['category_not_avilable']);exit;
        }
        alert($mainValues['receive_categories']);

        $keyboard[] = ['text' => $buttonValues['back_to_main'], 'callback_data' => 
        ($match['buyType'] == "one"?"agentOneBuy":($match['buyType'] == "much"?"agentMuchBuy":"buySubscription"))];
        $keyboard = array_chunk($keyboard,1);
        editText($message_id,$mainValues['buy_sub_select_category'], json_encode(['inline_keyboard'=>$keyboard]));
    }

}
if(preg_match('/selectCategory(?<categoryId>\d+)_(?<serverId>\d+)_(?<buyType>\w+)/',$data,$match) && ($botState['sellState']=="on" || $from_id == $admin || $userInfo['isAdmin'] == true)) {
    $call_id = $match['categoryId'];
    $sid = $match['serverId'];
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `server_id`=? and `price` != 0 and `catid`=? and `active`=1 order by `id` asc");
    $stmt->bind_param("ii", $sid, $call_id);
    $stmt->execute();
    $respd = $stmt->get_result();
    $stmt->close();
    if($respd->num_rows==0){
        alert($mainValues['no_plan_available']); 
    }else{
        alert($mainValues['receive_plans']);
        $keyboard = [];
        while($file = $respd->fetch_assoc()){
            $id = $file['id'];
            $name = $file['title'];
            $price = $file['price'];
            if($userInfo['is_agent'] == true && ($match['buyType'] == "one" || $match['buyType'] == "much")){
                $discounts = json_decode($userInfo['discount_percent'],true);
                if($botState['agencyPlanDiscount']=="on") $discount = $discounts['plans'][$id]?? $discounts['normal'];
                else $discount = $discounts['servers'][$sid]?? $discounts['normal'];
                
                $price -= floor($price * $discount / 100);
            }
            $price = ($price == 0) ? 'رایگان' : number_format($price).' تومان ';
            $keyboard[] = ['text' => "$name - $price", 'callback_data' => "selectPlan{$id}_{$call_id}_{$match['buyType']}"];
        }
        if($botState['plandelkhahState'] == "on" && $match['buyType'] != "much"){
	        $keyboard[] = ['text' => $mainValues['buy_custom_plan'], 'callback_data' => "selectCustomPlan{$call_id}_{$sid}_{$match['buyType']}"];
        }
        $keyboard[] = ['text' => $buttonValues['back_to_main'], 'callback_data' => "selectServer{$sid}_{$match['buyType']}"];
        $keyboard = array_chunk($keyboard,1);
        editText($message_id,$mainValues['buy_sub_select_plan'], json_encode(['inline_keyboard'=>$keyboard]));
    }

}
if(preg_match('/selectCustomPlan(?<categoryId>\d+)_(?<serverId>\d+)_(?<buyType>\w+)/',$data,$match) && ($botState['sellState']=="on" || $from_id == $admin || $userInfo['isAdmin'] == true)) {
    $call_id = $match['categoryId'];
    $sid = $match['serverId'];
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `server_id`=? and `catid`=? and `active`=1 order by `id` asc");
    $stmt->bind_param("ii", $sid, $call_id);
    $stmt->execute();
    $respd = $stmt->get_result();
    $stmt->close();
    alert($mainValues['receive_plans']);
    $keyboard = [];
    while($file = $respd->fetch_assoc()){
        $id = $file['id'];
        $name = preg_replace("/پلن\s(\d+)\sگیگ\s/","",$file['title']);
        $keyboard[] = ['text' => "$name", 'callback_data' => "selectCustomePlan{$id}_{$call_id}_{$match['buyType']}"];
    }
    $keyboard[] = ['text' => $buttonValues['back_to_main'], 'callback_data' => "selectServer{$sid}_{$match['buyType']}"];
    $keyboard = array_chunk($keyboard,1);
    editText($message_id, $mainValues['select_one_plan_to_edit'], json_encode(['inline_keyboard'=>$keyboard]));

}
if(preg_match('/selectCustomePlan(?<planId>\d+)_(?<categoryId>\d+)_(?<buyType>\w+)/',$data, $match) && ($botState['sellState']=="on" ||$from_id == $admin)){
	delMessage();
	$price = $botState['gbPrice'];
	if($match['buyType'] == "one" && $userInfo['is_agent'] == true){ 
        $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id` = ?");
        $stmt->bind_param("i", $match[1]);
        $stmt->execute();
        $serverId = $stmt->get_result()->fetch_assoc()['server_id'];
        $stmt->close();

        $discounts = json_decode($userInfo['discount_percent'],true);
        if($botState['agencyPlanDiscount']=="on") $discount = $discounts['plans'][$match[1]]?? $discounts['normal'];
        else $discount = $discounts['servers'][$serverId]?? $discounts['normal'];
        $price -= floor($price * $discount / 100);
	}
	sendMessage(str_replace("VOLUME-PRICE", $price, $mainValues['customer_custome_plan_volume']),$cancelKey);
	setUser("selectCustomPlanGB" . $match[1] . "_" . $match[2] . "_" . $match['buyType']);
}
if(preg_match('/selectCustomPlanGB(?<planId>\d+)_(?<categoryId>\d+)_(?<buyType>\w+)/',$userInfo['step'], $match) && ($botState['sellState']=="on" ||$from_id == $admin) && $text != $buttonValues['cancel']){
    if(!is_numeric($text)){
        sendMessage("😡 لطفا فقط عدد ارسال کن");
        exit();
    }
    elseif($text <1){
        sendMessage("لطفا عددی بزرگتر از 0 وارد کن");
        exit();
    }
    elseif(strstr($text,".")){
        sendMessage(" عدد اعشاری مجاز نیست");
        exit();
    }
    elseif(substr($text, 0, 1) == '0'){
        sendMessage("❌ عدد وارد شده نمیتواند با 0 شروع شود!");
        exit();
    }
    
    $id = $match['planId'];
    $price = $botState['dayPrice'];
	if($match['buyType'] == "one" && $userInfo['is_agent'] == true){
        $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id` = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $serverId = $stmt->get_result()->fetch_assoc()['server_id'];
        $stmt->close();

        $discounts = json_decode($userInfo['discount_percent'],true);
        if($botState['agencyPlanDiscount']=="on") $discount = $discounts['plans'][$id]?? $discounts['normal'];
        else $discount = $discounts['servers'][$serverId]?? $discounts['normal'];
        $price -= floor($price * $discount / 100);
	}
    
	sendMessage(str_replace("DAY-PRICE", $price, $mainValues['customer_custome_plan_day']));
	setUser("selectCustomPlanDay" . $id . "_" . $match['categoryId'] . "_" . $text . "_" . $match['buyType']);
}
if((preg_match('/selectCustomPlanDay(?<planId>\d+)_(?<categoryId>\d+)_(?<accountCount>\d+)_(?<buyType>\w+)/',$userInfo['step'], $match)) && ($botState['sellState']=="on" ||$from_id == $admin) && $text != $buttonValues['cancel']){
    if(!is_numeric($text)){
        sendMessage("😡 لطفا فقط عدد ارسال کن");
        exit();
    }
    elseif($text <1){
        sendMessage("لطفا عددی بزرگتر از 0 وارد کن");
        exit();
    }
    elseif(strstr($text,".")){
        sendMessage("عدد اعشاری مجاز نیست");
        exit();
    }
    elseif(substr($text, 0, 1) == '0'){
        sendMessage("❌ عدد وارد شده نمیتواند با 0 شروع شود!");
        exit();
    }

	sendMessage($mainValues['customer_custome_plan_name']);
	setUser("enterCustomPlanName" . $match['planId'] . "_" . $match['categoryId'] . "_" . $match['accountCount'] . "_" . $text . "_" . $match['buyType']);
}

if((preg_match('/^discountCustomPlanDay(\d+)/',$userInfo['step'], $match) || preg_match('/enterCustomPlanName(\d+)_(\d+)_(\d+)_(\d+)_(?<buyType>\w+)/',$userInfo['step'], $match)) && ($botState['sellState']=="on" ||$from_id ==$admin) && $text != $buttonValues['cancel']){ 
    if(preg_match('/^discountCustomPlanDay/', $userInfo['step'])){ 
        $rowId = $match[1];

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

        // در هر صورت (موفق یا ناموفق)، اطلاعات pays را از خروجی می‌گیریم
        if(isset($discountResult['payInfo']) && is_array($discountResult['payInfo'])) {
            $payInfo = $discountResult['payInfo'];
            $hash_id = $discountResult['hash_id'];
            $price   = $discountResult['price'];
            $id      = $payInfo['type'];
            $volume  = $payInfo['volume'];
            $days    = $payInfo['day'];
        } else {
            // اگر به هر دلیلی payInfo برنگشت، به عنوان کد نامعتبر رفتار می‌کنیم
            sendMessage($mainValues['not_valid_discount_code']);
            exit();
        }
    }else{ 
        $id = $match[1]; 
    	$call_id = $match[2]; 
    	$volume = $match[3]; 
        $days = $match[4]; 
        if($match['buyType'] != "much"){ 
            if(preg_match('/^[a-z]+[0-9]+$/',$text)){} else{ 
                sendMessage($mainValues['incorrect_config_name']); 
                exit(); 
            } 
        } 
    } 
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
    $token = base64_encode("{$from_id}.{$id}"); 
 
    if(!preg_match('/^discountCustomPlanDay/', $userInfo['step'])){ 
        $discountPrice = 0; 
        $gbPrice = $botState['gbPrice']; 
        $dayPrice = $botState['dayPrice']; 
         
        if($userInfo['is_agent'] == true && $match['buyType'] == "one") { 
            $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id` = ?"); 
            $stmt->bind_param("i", $match[1]); 
            $stmt->execute(); 
            $serverId = $stmt->get_result()->fetch_assoc()['server_id']; 
            $stmt->close(); 
             
            $discounts = json_decode($userInfo['discount_percent'],true); 
            if($botState['agencyPlanDiscount']=="on") $discount = $discounts['plans'][$id]?? $discounts['normal']; 
            else $discount = $discounts['servers'][$sid]?? $discounts['normal']; 
             
            $gbPrice -= floor($gbPrice * $discount /100); 
            $dayPrice -= floor($dayPrice * $discount / 100); 
        } 
         
        $agentBought = false; 
        if($userInfo['is_agent'] == 1 && ($match['buyType'] == "one" || $match['buyType'] == "much")) { 
            $agentBought = true; 
        } 
         
        $price =  ($volume * $gbPrice) + ($days * $dayPrice); 
        $hash_id = RandomString(); 
        $stmt = $connection->prepare("DELETE FROM `pays` WHERE `user_id` = ? AND `type` = 'BUY_SUB' AND `state` = 'pending'"); 
        $stmt->bind_param("i", $from_id); 
        $stmt->execute(); 
        $stmt->close(); 
         
        $time = time(); 
        $stmt = $connection->prepare("INSERT INTO `pays` (`hash_id`, `description`, `user_id`, `type`, `plan_id`, `server_id`, `volume`, `day`, `price`, `request_date`, `state`, `agent_bought`) 
									VALUES (?, ?, ?, 'BUY_SUB', ?, ?, ?, ?, ?, ?, 'pending', ?)"); 
		$stmt->bind_param("ssiiiiiiii", $hash_id, $text, $from_id, $id, $sid, $volume, $days, $price, $time, $agentBought); 
        $stmt->execute(); 
        $rowId = $stmt->insert_id; 
        $stmt->close(); 
    } 
     
     
    if($botState['cartToCartState'] == "on") $keyboard[] = [['text' => $buttonValues['cart_to_cart'],  'callback_data' => "payCustomWithCartToCart$hash_id"]]; 
    if($botState['nowPaymentOther'] == "on") $keyboard[] = [['text' => $buttonValues['now_payment_gateway'],  'url' => $botUrl . "pay/?nowpayment&hash_id=" . $hash_id]]; 
    if($botState['zarinpal'] == "on") $keyboard[] = [['text' => $buttonValues['zarinpal_gateway'],  'url' => $botUrl . "pay/?zarinpal&hash_id=" . $hash_id]]; 
    if($botState['nextpay'] == "on") $keyboard[] = [['text' => $buttonValues['nextpay_gateway'],  'url' => $botUrl . "pay/?nextpay&hash_id=" . $hash_id]]; 
    if($botState['weSwapState'] == "on") $keyboard[] = [['text' => $buttonValues['weswap_gateway'],  'callback_data' => "payWithWeSwap" . $hash_id]]; 
    if($botState['walletState'] == "on") $keyboard[] = [['text' => $buttonValues['pay_with_wallet'],  'callback_data' => "payCustomWithWallet$hash_id"]]; 
    if($botState['tronWallet'] == "on") $keyboard[] = [['text' => $buttonValues['tron_gateway'],  'callback_data' => "payWithTronWallet" . $hash_id]]; 
 
    if(!preg_match('/^discountCustomPlanDay/', $userInfo['step'])) $keyboard[] = [['text' => " 🎟 افزودن کد تخفیف ",  'callback_data' => "haveDiscountCustom_" . $rowId]]; 
	$keyboard[] = [['text' => $buttonValues['cancel'], 'callback_data' => "mainMenu"]]; 
    $price = ($price == 0) ? 'رایگان' : number_format($price).' تومان '; 
    sendMessage(str_replace(['VOLUME', 'DAYS', 'PLAN-NAME', 'PRICE', 'DESCRIPTION'], [$volume, $days, $name, $price, $desc], $mainValues['buy_subscription_detail']),json_encode(['inline_keyboard'=>$keyboard]), "HTML"); 
    setUser(); 
}

if(preg_match('/^haveDiscount(.+?)_(.*)/',$data,$match)){
    delMessage();
    sendMessage($mainValues['insert_discount_code'],$cancelKey);
    if($match[1] == "Custom") setUser('discountCustomPlanDay' . $match[2]);
    elseif($match[1] == "SelectPlan") setUser('discountSelectPlan' . $match[2]);
    elseif($match[1] == "Renew") setUser('discountRenew' . $match[2]);
}
if($data=="getTestAccount"){
    if($userInfo['freetrial'] != null && $from_id != $admin && $userInfo['isAdmin'] != true){
        alert("شما اکانت تست را قبلا استفاده کرده اید.");
        exit();
    }
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `price`=0");
    $stmt->execute();
    $respd = $stmt->get_result();
    $stmt->close();
    
    if($respd->num_rows > 0){
        alert($mainValues['receving_information']);
    	$keyboard = array();
        while ($row = $respd->fetch_assoc()){
            $id = $row['id'];
            $catInfo = $connection->prepare("SELECT * FROM `server_categories` WHERE `id`=?");
            $catInfo->bind_param("i", $row['catid']);
            $catInfo->execute();
            $catname = $catInfo->get_result()->fetch_assoc()['title'];
            $catInfo->close();
            
            $name = $catname." ".$row['title'];
            $price =  $row['price'];
            $desc = $row['descr'];
        	$sid = $row['server_id'];

            $keyboard[] = [['text' => $name, 'callback_data' => "freeTrial{$id}_normal"]];

        }
    	$keyboard[] = [['text' => $buttonValues['back_to_main'], 'callback_data' => "mainMenu"]];
        editText($message_id,"لطفا یکی از کلید های زیر را انتخاب کنید.", json_encode(['inline_keyboard'=>$keyboard]), "HTML");
    }else alert("این بخش موقتا غیر فعال است.");
}

if((preg_match('/^discountSelectPlan(\d+)_(\d+)_(\d+)/',$userInfo['step'],$match) ||  
    preg_match('/selectPlan(\d+)_(\d+)_(?<buyType>\w+)/',$userInfo['step'], $match) ||  
    preg_match('/enterAccountName(\d+)_(\d+)_(?<buyType>\w+)/',$userInfo['step'], $match) ||  
    preg_match('/selectPlan(\d+)_(\d+)_(?<buyType>\w+)/',$data, $match)) &&  
    ($botState['sellState']=="on" ||$from_id ==$admin) &&  
    $text != $buttonValues['cancel']){ 
    if(preg_match('/^discountSelectPlan/', $userInfo['step'])){ 
        $rowId = $match[3]; 

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
    }elseif(isset($data)) delMessage(); 
 
 
    if($botState['remark'] ==  "manual" && preg_match('/^selectPlan/',$data) && $match['buyType'] != "much"){ 
        sendMessage($mainValues['customer_custome_plan_name'], $cancelKey); 
        setUser('enterAccountName' . $match[1] . "_" . $match[2] . "_" . $match['buyType']); 
        exit(); 
    } 
 
    $remark = ""; 
    if(preg_match("/selectPlan(\d+)_(\d+)_(\w+)/",$userInfo['step'])){ 
        if($match['buyType'] == "much"){ 
            if(is_numeric($text)){ 
                if($text > 0){ 
                    $accountCount = $text; 
                    setUser(); 
                }else{sendMessage( $mainValues['send_positive_number']); exit(); } 
            }else{ sendMessage($mainValues['send_only_number']); exit(); } 
        }         
    } 
    elseif(preg_match("/enterAccountName(\d+)_(\d+)/",$userInfo['step'])){ 
        if(preg_match('/^[a-z]+[0-9]+$/',$text)){ 
            $remark = $text; 
            setUser(); 
        } else{ 
            sendMessage($mainValues['incorrect_config_name']); 
            exit(); 
        } 
    } 
    else{ 
        if($match['buyType'] == "much"){ 
            setUser($data); 
            sendMessage($mainValues['enter_account_amount'], $cancelKey); 
            exit(); 
        } 
    } 
     
     
    $id = $match[1]; 
	$call_id = $match[2]; 
    alert($mainValues['receving_information']); 
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
    if(isset($accountCount)) $price *= $accountCount; 
     
    $agentBought = false; 
    if($userInfo['is_agent'] == true && ($match['buyType'] == "one" || $match['buyType'] == "much")){ 
        $discounts = json_decode($userInfo['discount_percent'],true); 
        if($botState['agencyPlanDiscount']=="on") $discount = $discounts['plans'][$id]?? $discounts['normal']; 
        else $discount = $discounts['servers'][$sid]?? $discounts['normal']; 
        $price -= floor($price * $discount / 100); 
 
        $agentBought = true; 
    } 
    if($price == 0 or ($from_id == $admin)){ 
        $keyboard[] = [['text' => '📥 دریافت رایگان', 'callback_data' => "freeTrial{$id}_{$match['buyType']}"]]; 
        setUser($remark, 'temp'); 
    }else{ 
        $token = base64_encode("{$from_id}.{$id}"); 
         
        if(!preg_match('/^discountSelectPlan/', $userInfo['step'])){ 
            $hash_id = RandomString(); 
            $stmt = $connection->prepare("DELETE FROM `pays` WHERE `user_id` = ? AND `type` = 'BUY_SUB' AND `state` = 'pending'"); 
            $stmt->bind_param("i", $from_id); 
            $stmt->execute(); 
            $stmt->close(); 
             
            $time = time(); 
            if(isset($accountCount)){ 
				$stmt = $connection->prepare("INSERT INTO `pays` (`hash_id`, `user_id`, `type`, `plan_id`, `server_id`, `volume`, `day`, `price`, `request_date`, `state`, `agent_bought`, `agent_count`) 
											VALUES (?, ?, 'BUY_SUB', ?, ?, '0', '0', ?, ?, 'pending', ?, ?)"); 
				$stmt->bind_param("siiiiiii", $hash_id, $from_id, $id, $sid, $price, $time, $agentBought, $accountCount); 
            }else{ 
				$stmt = $connection->prepare("INSERT INTO `pays` (`hash_id`, `description`, `user_id`, `type`, `plan_id`, `server_id`, `volume`, `day`, `price`, `request_date`, `state`, `agent_bought`) 
											VALUES (?, ?, ?, 'BUY_SUB', ?, ?, '0', '0', ?, ?, 'pending', ?)"); 
				$stmt->bind_param("ssiiiiii", $hash_id, $remark, $from_id, $id, $sid, $price, $time, $agentBought); 
            } 
            $stmt->execute(); 
            $rowId = $stmt->insert_id; 
            $stmt->close(); 
        }else{ 
            $price = $afterDiscount; 
        } 
         
        if($botState['cartToCartState'] == "on") $keyboard[] = [['text' => $buttonValues['cart_to_cart'],  'callback_data' => "payBuyWithCartToCart$hash_id"]]; 
        if($botState['nowPaymentOther'] == "on") $keyboard[] = [['text' => $buttonValues['now_payment_gateway'],  'url' => $botUrl . "pay/?nowpayment&hash_id=" . $hash_id]]; 
        if($botState['zarinpal'] == "on") $keyboard[] = [['text' => $buttonValues['zarinpal_gateway'],  'url' => $botUrl . "pay/?zarinpal&hash_id=" . $hash_id]]; 
        if($botState['nextpay'] == "on") $keyboard[] = [['text' => $buttonValues['nextpay_gateway'],  'url' => $botUrl . "pay/?nextpay&hash_id=" . $hash_id]]; 
        if($botState['weSwapState'] == "on") $keyboard[] = [['text' => $buttonValues['weswap_gateway'],  'callback_data' => "payWithWeSwap" . $hash_id]]; 
        if($botState['walletState'] == "on") $keyboard[] = [['text' => $buttonValues['pay_with_wallet'],  'callback_data' => "payBuyWithWallet$hash_id"]]; 
        if($botState['tronWallet'] == "on") $keyboard[] = [['text' => $buttonValues['tron_gateway'],  'callback_data' => "payWithTronWallet" . $hash_id]]; 
         
        if(!preg_match('/^discountSelectPlan/', $userInfo['step'])) $keyboard[] = [['text' => " 🎟 افزودن کد تخفیف ",  'callback_data' => "haveDiscountSelectPlan_" . $match[1] . "_" . $match[2] . "_" . $rowId]]; 
 
    } 
	$keyboard[] = [['text' => $buttonValues['back_to_main'], 'callback_data' => "selectCategory{$call_id}_{$sid}_{$match['buyType']}"]]; 
    $priceC = ($price == 0) ? 'رایگان' : number_format($price).' تومان '; 
    if(isset($accountCount)){ 
        $eachPrice = number_format($price / $accountCount) . " تومان"; 
        $msg = str_replace(['ACCOUNT-COUNT', 'TOTAL-PRICE', 'PLAN-NAME', 'PRICE', 'DESCRIPTION'], [$accountCount, $priceC, $name, $eachPrice, $desc], $mainValues['buy_much_subscription_detail']); 
    } 
    else $msg = str_replace(['PLAN-NAME', 'PRICE', 'DESCRIPTION'], [$name, $priceC, $desc], $mainValues['buy_subscription_detail']); 
    sendMessage($msg, json_encode(['inline_keyboard'=>$keyboard]), "HTML"); 
}

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
if($data == 'dayPlanSettings' and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `increase_day`");
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    if($res->num_rows == 0){
       editText($message_id, 'لیست پلن های زمانی خالی است.',json_encode([
                'inline_keyboard' => [
                    [['text' => "افزودن پلن زمانی جدید", 'callback_data' =>"addNewDayPlan"]],
                    [['text'=>$buttonValues['back_button'],'callback_data'=>"backplan"]]
                ]
            ]));
        exit;
    }
    $keyboard = [];
    $keyboard[] = [['text'=>"حذف",'callback_data'=>"wizwizch"],['text'=>"قیمت",'callback_data'=>"wizwizch"],['text'=>"تعداد روز",'callback_data'=>"wizwizch"]];
    while($cat = $res->fetch_assoc()){
        $id = $cat['id'];
        $title = $cat['volume'];
        $price=number_format($cat['price']) . " تومان";
        $acount =$cat['acount'];

        $keyboard[] = [['text'=>"❌",'callback_data'=>"deleteDayPlan" . $id],['text'=>$price,'callback_data'=>"changeDayPlanPrice" . $id],['text'=>$title,'callback_data'=>"changeDayPlanDay" . $id]];
    }
    $keyboard[] = [['text' => "افزودن پلن زمانی جدید", 'callback_data' =>"addNewDayPlan"]];
    $keyboard[] = [['text' => $buttonValues['back_button'], 'callback_data' => "backplan"]];
    $msg = ' 📍 برای دیدن جزییات پلن زمانی روی آن بزنید👇';
    
    editText($message_id,$msg,json_encode([
            'inline_keyboard' => $keyboard
        ]));

    exit;
}
if($data=='addNewDayPlan' and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    setUser($data);
    delMessage();
    sendMessage("تعداد روز و قیمت آن را بصورت زیر وارد کنید:
10-30000

مقدار اول مدت زمان (10) روز
مقدار دوم قیمت (30000) تومان
 ",$cancelKey);exit;
}
if($userInfo['step'] == "addNewDayPlan" and $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $input = explode('-',$text); 
    $volume = $input[0];
    $price = $input[1];
    $stmt = $connection->prepare("INSERT INTO `increase_day` VALUES (NULL, ?, ?)");
    $stmt->bind_param("ii", $volume, $price);
    $stmt->execute();
    $stmt->close();
    
    sendMessage("پلن زمانی جدید با موفقیت اضافه شد",$removeKeyboard);
    sendMessage($mainValues['reached_main_menu'],getAdminKeys());
    setUser();
}
if(preg_match('/^deleteDayPlan(\d+)/',$data,$match) and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("DELETE FROM `increase_day` WHERE `id` = ?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $stmt->close();
    alert("پلن موردنظر با موفقیت حذف شد.");
    
    
    $stmt = $connection->prepare("SELECT * FROM `increase_day`");
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    if($res->num_rows == 0){
       editText($message_id, 'لیست پلن های زمانی خالی است.',json_encode([
                'inline_keyboard' => [
                    [['text' => "افزودن پلن زمانی جدید", 'callback_data' =>"addNewDayPlan"]],
                    [['text'=>$buttonValues['back_button'],'callback_data'=>"managePanel"]]
                ]
            ]));
        exit;
    }
    $keyboard = [];
    $keyboard[] = [['text'=>"حذف",'callback_data'=>"wizwizch"],['text'=>"قیمت",'callback_data'=>"wizwizch"],['text'=>"تعداد روز",'callback_data'=>"wizwizch"]];
    while($cat = $res->fetch_assoc()){
        $id = $cat['id'];
        $title = $cat['volume'];
        $price=number_format($cat['price']) . " تومان";
        $acount =$cat['acount'];

        $keyboard[] = [['text'=>"❌",'callback_data'=>"deleteDayPlan" . $id],['text'=>$price,'callback_data'=>"changeDayPlanPrice" . $id],['text'=>$title,'callback_data'=>"changeDayPlanDay" . $id]];
    }
    $keyboard[] = [['text' => "افزودن پلن زمانی جدید", 'callback_data' =>"addNewDayPlan"]];
    $keyboard[] = [['text' => $buttonValues['back_button'], 'callback_data' => "managePanel"]];
    $msg = ' 📍 برای دیدن جزییات پلن زمانی روی آن بزنید👇';
    
    editText($message_id,$msg,json_encode([
            'inline_keyboard' => $keyboard
        ]));

    exit;
}
if(preg_match('/^changeDayPlanPrice(\d+)/',$data,$match) and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    setUser($data);
    delMessage();
    sendMessage("قیمت جدید را وارد کنید:", $cancelKey);
    exit;
}
if(preg_match('/^changeDayPlanPrice(\d+)/',$userInfo['step'],$match) and $text != $buttonValues['cancel']){
    if(is_numeric($text)){
        setUser();
        $stmt = $connection->prepare("UPDATE `increase_day` SET `price` = ? WHERE `id` = ?");
        $stmt->bind_param("ii", $text, $match[1]);
        $stmt->execute();
        $stmt->close();
        
        sendMessage("✅ عملیات با موفقیت انجام شد.",$removeKeyboard);
        
        $stmt = $connection->prepare("SELECT * FROM `increase_day`");
        $stmt->execute();
        $res = $stmt->get_result();
        $stmt->close();
    
        if($res->num_rows == 0){
           sendMessage( 'لیست پلن های زمانی خالی است.',json_encode([
                    'inline_keyboard' => [
                        [['text' => "افزودن پلن زمانی جدید", 'callback_data' =>"addNewDayPlan"]],
                        [['text'=>$buttonValues['back_button'],'callback_data'=>"managePanel"]]
                    ]
                ]));
            exit;
        }
        $keyboard = [];
        $keyboard[] = [['text'=>"حذف",'callback_data'=>"wizwizch"],['text'=>"قیمت",'callback_data'=>"wizwizch"],['text'=>"تعداد روز",'callback_data'=>"wizwizch"]];
        while($cat = $res->fetch_assoc()){
            $id = $cat['id'];
            $title = $cat['volume'];
            $price=number_format($cat['price']) . " تومان";
            $acount =$cat['acount'];
    
            $keyboard[] = [['text'=>"❌",'callback_data'=>"deleteDayPlan" . $id],['text'=>$price,'callback_data'=>"changeDayPlanPrice" . $id],['text'=>$title,'callback_data'=>"changeDayPlanDay" . $id]];
        }
        $keyboard[] = [['text' => "افزودن پلن زمانی جدید", 'callback_data' =>"addNewDayPlan"]];
        $keyboard[] = [['text' => $buttonValues['back_button'], 'callback_data' => "managePanel"]];
        $msg = ' 📍 برای دیدن جزییات پلن زمانی روی آن بزنید 👇';
        
        sendMessage($msg,json_encode([
                'inline_keyboard' => $keyboard
            ]));
    
        
    }else{
        sendMessage("یک مقدار عددی و صحیح وارد کنید");
    }
}
if(preg_match('/^changeDayPlanDay(\d+)/',$data,$match) and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    setUser($data);
    delMessage();
    sendMessage("روز جدید را وارد کنید:", $cancelKey);
    exit;
}
if(preg_match('/^changeDayPlanDay(\d+)/',$userInfo['step'],$match) && ($from_id == $admin || $userInfo['isAdmin'] == true) and $text != $buttonValues['cancel']) {
    setUser();
    $stmt = $connection->prepare("UPDATE `increase_day` SET `volume` = ? WHERE `id` = ?");
    $stmt->bind_param("ii", $text, $match[1]);
    $stmt->execute();
    $stmt->close();

    sendMessage("✅ عملیات با موفقیت انجام شد.",$removeKeyboard);
    
    $stmt = $connection->prepare("SELECT * FROM `increase_day`");
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    if($res->num_rows == 0){
       sendMessage( 'لیست پلن های زمانی خالی است.',json_encode([
                'inline_keyboard' => [
                    [['text' => "افزودن پلن زمانی جدید", 'callback_data' =>"addNewDayPlan"]],
                    [['text'=>$buttonValues['back_button'],'callback_data'=>"managePanel"]]
                ]
            ]));
        exit;
    }
    $keyboard = [];
    $keyboard[] = [['text'=>"حذف",'callback_data'=>"wizwizch"],['text'=>"قیمت",'callback_data'=>"wizwizch"],['text'=>"تعداد روز",'callback_data'=>"wizwizch"]];
    while($cat = $res->fetch_assoc()){
        $id = $cat['id'];
        $title = $cat['volume'];
        $price=number_format($cat['price']) . " تومان";
        $acount =$cat['acount'];

        $keyboard[] = [['text'=>"❌",'callback_data'=>"deleteDayPlan" . $id],['text'=>$price,'callback_data'=>"changeDayPlanPrice" . $id],['text'=>$title,'callback_data'=>"changeDayPlanDay" . $id]];
    }
    $keyboard[] = [['text' => "افزودن پلن زمانی جدید", 'callback_data' =>"addNewDayPlan"]];
    $keyboard[] = [['text' => $buttonValues['back_button'], 'callback_data' => "managePanel"]];
    $msg = ' 📍 برای دیدن جزییات پلن زمانی روی آن بزنید👇';
    
    sendMessage($msg,json_encode([
            'inline_keyboard' => $keyboard
        ]));

    
}
if($data == 'volumePlanSettings' and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `increase_plan`");
    $stmt->execute();
    $plans = $stmt->get_result();
    $stmt->close();
    
    if($plans->num_rows == 0){
       editText($message_id, 'لیست پلن های حجمی خالی است.',json_encode([
                'inline_keyboard' => [
                    [['text' => "افزودن پلن حجمی جدید", 'callback_data' =>"addNewVolumePlan"]],
                    [['text' => $buttonValues['back_button'],'callback_data'=>"backplan"]]
                    ]]));
        exit;
    }
    $keyboard = [];
    $keyboard[] = [['text'=>"حذف",'callback_data'=>"wizwizch"],['text'=>"قیمت",'callback_data'=>"wizwizch"],['text'=>"مقدار حجم",'callback_data'=>"wizwizch"]];
    while ($cat = $plans->fetch_assoc()){
        $id = $cat['id'];
        $title = $cat['volume'];
        $price=number_format($cat['price']) . " تومان";
        
        $keyboard[] = [['text'=>"❌",'callback_data'=>"deleteVolumePlan" . $id],['text'=>$price,'callback_data'=>"changeVolumePlanPrice" . $id],['text'=>$title,'callback_data'=>"changeVolumePlanVolume" . $id]];
    }
    $keyboard[] = [['text' => "افزودن پلن حجمی جدید", 'callback_data' =>"addNewVolumePlan"]];
    $keyboard[] = [['text' =>$buttonValues['back_button'], 'callback_data' => "backplan"]];
    $msg = ' 📍 برای دیدن جزییات پلن حجمی روی آن بزنید👇';
    
    $res = editText($message_id, $msg,json_encode([
            'inline_keyboard' => $keyboard
        ]));
    exit;
}
if($data=='addNewVolumePlan' and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    setUser($data);
    delMessage();
    sendMessage("حجم و قیمت آن را بصورت زیر وارد کنید:
10-30000

مقدار اول حجم (10) گیگابایت
مقدار دوم قیمت (30000) تومان
 ",$cancelKey);
 exit;
}
if($userInfo['step'] == "addNewVolumePlan" and $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $input = explode('-',$text); 
    $volume = $input[0];
    $price = $input[1];
    $stmt = $connection->prepare("INSERT INTO `increase_plan` VALUES (NULL, ? ,?)");
    $stmt->bind_param("ii",$volume,$price);
    $stmt->execute();
    $stmt->close();
    
    sendMessage("پلن حجمی جدید با موفقیت اضافه شد.",$removeKeyboard);
    sendMessage($mainValues['reached_main_menu'],getAdminKeys());
    setUser();
}
if(preg_match('/^deleteVolumePlan(\d+)/',$data,$match) and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("DELETE FROM `increase_plan` WHERE `id` = ?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $stmt->close();
    alert("پلن مورد نظر با موفقیت حذف شد.");
    
    
    $stmt = $connection->prepare("SELECT * FROM `increase_plan`");
    $stmt->execute();
    $plans = $stmt->get_result();
    $stmt->close();
    
    if($plans->num_rows == 0){
       editText($message_id, 'لیست پلن های حجمی خالی است.',json_encode([
                'inline_keyboard' => [
                    [['text' => "افزودن پلن حجمی جدید", 'callback_data' =>"addNewVolumePlan"]],
                    [['text' => $buttonValues['back_button'],'callback_data'=>"managePanel"]]
                    ]]));
        exit;
    }
    $keyboard = [];
    $keyboard[] = [['text'=>"حذف",'callback_data'=>"wizwizch"],['text'=>"قیمت",'callback_data'=>"wizwizch"],['text'=>"مقدار حجم",'callback_data'=>"wizwizch"]];
    while ($cat = $plans->fetch_assoc()){
        $id = $cat['id'];
        $title = $cat['volume'];
        $price=number_format($cat['price']) . " تومان";
        
        $keyboard[] = [['text'=>"❌",'callback_data'=>"deleteVolumePlan" . $id],['text'=>$price,'callback_data'=>"changeVolumePlanPrice" . $id],['text'=>$title,'callback_data'=>"changeVolumePlanVolume" . $id]];
    }
    $keyboard[] = [['text' => "افزودن پلن حجمی جدید", 'callback_data' =>"addNewVolumePlan"]];
    $keyboard[] = [['text' =>$buttonValues['back_button'], 'callback_data' => "managePanel"]];
    $msg = ' 📍 برای دیدن جزییات پلن حجمی روی آن بزنید👇';
    
    $res = editText($message_id, $msg,json_encode([
            'inline_keyboard' => $keyboard
        ]));
}
if(preg_match('/^changeVolumePlanPrice(\d+)/',$data,$match) and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    setUser($data);
    delMessage();
    sendMessage("قیمت جدید را وارد کنید:", $cancelKey);
    exit;
}
if(preg_match('/^changeVolumePlanPrice(\d+)/',$userInfo['step'],$match) and $text != $buttonValues['cancel'] and ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $pid=$match[1];
    if(is_numeric($text)){
        $stmt = $connection->prepare("UPDATE `increase_plan` SET `price` = ? WHERE `id` = ?");
        $stmt->bind_param("ii", $text, $pid);
        $stmt->execute();
        $stmt->close();
        sendMessage("عملیات با موفقیت انجام شد.",$removeKeyboard);
        
        setUser();
        $stmt = $connection->prepare("SELECT * FROM `increase_plan`");
        $stmt->execute();
        $plans = $stmt->get_result();
        $stmt->close();
        
        if($plans->num_rows == 0){
           sendMessage( 'لیست پلن های حجمی خالی است.',json_encode([
                    'inline_keyboard' => [
                        [['text' => "افزودن پلن حجمی جدید", 'callback_data' =>"addNewVolumePlan"]],
                        [['text' => $buttonValues['back_button'],'callback_data'=>"managePanel"]]
                        ]]));
            exit;
        }
        $keyboard = [];
        $keyboard[] = [['text'=>"حذف",'callback_data'=>"wizwizch"],['text'=>"قیمت",'callback_data'=>"wizwizch"],['text'=>"مقدار حجم",'callback_data'=>"wizwizch"]];
        while ($cat = $plans->fetch_assoc()){
            $id = $cat['id'];
            $title = $cat['volume'];
            $price=number_format($cat['price']) . " تومان";
            
            $keyboard[] = [['text'=>"❌",'callback_data'=>"deleteVolumePlan" . $id],['text'=>$price,'callback_data'=>"changeVolumePlanPrice" . $id],['text'=>$title,'callback_data'=>"changeVolumePlanVolume" . $id]];
        }
        $keyboard[] = [['text' => "افزودن پلن حجمی جدید", 'callback_data' =>"addNewVolumePlan"]];
        $keyboard[] = [['text' =>$buttonValues['back_button'], 'callback_data' => "managePanel"]];
        $msg = ' 📍 برای دیدن جزییات پلن حجمی روی آن بزنید 👇';
        
        $res = sendMessage($msg,json_encode([
                'inline_keyboard' => $keyboard
            ]));
    }else{
        sendMessage("یک مقدار عددی و صحیح وارد کنید");
    }
}
if(preg_match('/^changeVolumePlanVolume(\d+)/',$data) and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    setUser($data);
    delMessage();
    sendMessage("حجم جدید را وارد کنید:", $cancelKey);
    exit;
}
if(preg_match('/^changeVolumePlanVolume(\d+)/',$userInfo['step'], $match) and $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $pid=$match[1];
    $stmt = $connection->prepare("UPDATE `increase_plan` SET `volume` = ? WHERE `id` = ?");
    $stmt->bind_param("ii", $text, $pid);
    $stmt->execute();
    $stmt->close();
    sendMessage("✅ عملیات با موفقیت انجام شد.",$removeKeyboard);
    setUser();

    $stmt = $connection->prepare("SELECT * FROM `increase_plan`");
    $stmt->execute();
    $plans = $stmt->get_result();
    $stmt->close();
    
    if($plans->num_rows == 0){
       sendMessage( 'لیست پلن های حجمی خالی است.',json_encode([
                'inline_keyboard' => [
                    [['text' => "افزودن پلن حجمی جدید", 'callback_data' =>"addNewVolumePlan"]],
                    [['text' => $buttonValues['back_button'],'callback_data'=>"managePanel"]]
                    ]]));
        exit;
    }
    $keyboard = [];
    $keyboard[] = [['text'=>"حذف",'callback_data'=>"wizwizch"],['text'=>"قیمت",'callback_data'=>"wizwizch"],['text'=>"مقدار حجم",'callback_data'=>"wizwizch"]];
    while ($cat = $plans->fetch_assoc()){
        $id = $cat['id'];
        $title = $cat['volume'];
        $price=number_format($cat['price']) . " تومان";
        
        $keyboard[] = [['text'=>"❌",'callback_data'=>"deleteVolumePlan" . $id],['text'=>$price,'callback_data'=>"changeVolumePlanPrice" . $id],['text'=>$title,'callback_data'=>"changeVolumePlanVolume" . $id]];
    }
    $keyboard[] = [['text' => "افزودن پلن حجمی جدید", 'callback_data' =>"addNewVolumePlan"]];
    $keyboard[] = [['text' =>$buttonValues['back_button'], 'callback_data' => "managePanel"]];
    $msg = ' 📍 برای دیدن جزییات پلن حجمی روی آن بزنید👇';
    
    $res = sendMessage( $msg,json_encode([
            'inline_keyboard' => $keyboard
        ]));
    
}
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
if (preg_match('/freeTrial(\d+)_(?<buyType>\w+)/', $data, $match)) {
    $planId  = (int)$match[1];
    $buyType = $match['buyType'] ?? '';
    $discountsRaw  = $userInfo['discount_percent'] ?? '{}';
    $discountsData = json_decode($discountsRaw, true);
    if (!is_array($discountsData)) {
        $discountsData = [];
    }
    $normalDiscount = isset($discountsData['normal']) ? (string)$discountsData['normal'] : null;

    if (
        ($userInfo['freetrial'] ?? '') === 'used' &&
        $from_id != $admin &&
        $normalDiscount !== "100"
    ) {
        alert('⚠️ شما قبلا هدیه رایگان خود را دریافت کردید.');
        exit;
    }

    delMessage();

    $planDetails = getPlanDetails($connection, $planId);
    $plan        = $planDetails['plan'];

    $days             = $planDetails['days'];
    $volume           = $planDetails['volume'];
    $date             = $planDetails['date'];
    $expire_microdate = $planDetails['expire_microdate'];
    $expire_date      = $planDetails['expire_date'];

    $type       = $plan['type'];
    $protocol   = $plan['protocol'];
    $price      = (int)$plan['price'];
    $server_id  = (int)$plan['server_id'];
    $acount     = (int)$plan['acount'];
    $inbound_id = (int)$plan['inbound_id'];
    $limitip    = $plan['limitip'];
    $netType    = $plan['type'];
    $rahgozar   = $plan['rahgozar'];
    $customPath = $plan['custom_path'];
    $customPort = $plan['custom_port'];
    $customSni  = $plan['custom_sni'];

    [$price, $agentBought, $discountPercent] = calculateAgentPrice(
        $userInfo,
        $botState,
        $planId,
        $server_id,
        $buyType,
        $price
    );

    ensureServerCapacity($connection, $server_id, $inbound_id, $acount, $mainValues);
    $uniqid = generateRandomString(42, $protocol);

    [$serverInfo, $serverConfig] = getServerInfoAndConfig($connection, $server_id);
    $srv_remark = $serverInfo['remark'];

    $serverType = $serverConfig['type'];
    $portType   = $serverConfig['port_type'];

    // تعیین remark (با امکان override توسط ادمین از temp)
    $forcedRemark = null;
    if ($from_id == $admin && !empty($userInfo['temp'])) {
        $forcedRemark = $userInfo['temp'];
        setUser('', 'temp');
    }

    $remarkUserId = $from_id;
    $remark       = buildRemark($botState, $srv_remark, '', $remarkUserId, $forcedRemark);

    // گرفتن پورت (auto / random) از temp.txt
    [$port, $last_num] = getNextPortAndIncrease('settings/temp.txt', $portType);

    [$response, $remark, $port] = createServerAccount(
        $serverType,
        $server_id,
        $inbound_id,
        $planId,
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

    // چک موفقیت ساخت اکانت
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
        $from_id,
        $volume,
        $days,
        $buttonValues,
        $linkIndices,
        $hideConfigIfMany
    );

    insertOrder(
        $connection,
        $from_id,     // userid
        $token,
        $planId,
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
        $agentBought ? 1 : 0
    );

    decreaseServerCapacity($connection, $server_id, $inbound_id, $planId, 1);

    $userId   = $userInfo['userid'] ?? $from_id;
    $fullName = $userInfo['name'] ?? '---';
    $username = $userInfo['username'] ?? '';

    $usernameText = !empty($username) ? '@' . $username : 'ندارد';

    $channelMessage = "
🔄 اشتراک تست ایجاد شد.

🎫 شناسه عددی: <code>{$userId}</code>
👨 اسم کاربر: {$fullName}
⚡️ نام کاربری: {$usernameText}
🎈 نام کاربری اشتراک: <code>{$remark}</code>
🔋 حجم اولیه اشتراک: {$volume} گیگ
⏰ مدت اشتراک: {$days} روز
";

    // ارسال پیام به ادمین اصلی
    sendMessage($channelMessage, null, "html", $admin);

    // ارسال پیام به کانال گزارشات (در صورت تنظیم)
    $reportChannel = $botState['rewardChannel'] ?? null;
    if (!empty($reportChannel)) {
        sendMessage($channelMessage, null, "html", $reportChannel);
    }

    setUser('used', 'freetrial');
}

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
// BEGIN Edit by ACh
if($data == "showUUIDLeft" && ($botState['searchState'] == "on" || $from_id == $admin || $userInfo['isAdmin'] == true)){ 
    delMessage(); 
    sendMessage($mainValues['send_config_uuid'], $cancelKey); 
    setUser('showAccount'); 
} 

function extractUsernameFromLink($text) {
    $text = trim($text);

    if (!preg_match('~^https?://[^/]+/sub/([^?#\s]+)~', $text, $match)) {
        return null;
    }

    $token = rawurldecode(trim($match[1]));
    $token = strtr($token, '-_', '+/');
    $token = preg_replace('/\s+/', '', $token);

    for ($cut = 0; $cut <= 4; $cut++) {
        $candidate = $cut > 0 ? substr($token, 0, -$cut) : $token;
        if ($candidate === '') {
            continue;
        }

        $mod = strlen($candidate) % 4;

        if ($mod == 1) {
            continue;
        }

        if ($mod > 0) {
            $candidate .= str_repeat('=', 4 - $mod);
        }

        $decoded = base64_decode($candidate, true);
        if ($decoded === false || $decoded === '') {
            continue;
        }

        if (strpos($decoded, ',') !== false) {
            $parts = explode(',', $decoded, 2);
            $username = trim($parts[0]);

            if ($username !== '') {
                return $username;
            }
        }

        // fallback
        $decoded = preg_replace('/[^\x20-\x7E]/', '', $decoded);
        if ($decoded !== '' && strpos($decoded, ',') !== false) {
            $parts = explode(',', $decoded, 2);
            $username = trim($parts[0]);

            if ($username !== '') {
                return $username;
            }
        }
    }

    return null;
}

if ($userInfo['step'] == "showAccount" && $text != $buttonValues['cancel']) { 
    date_default_timezone_set('Asia/Tehran'); 

    $originalLink = trim($text); 
    $addedBy = $from_id; 
    $currentTime = date('Y-m-d H:i:s'); 

    $username = extractUsernameFromLink($originalLink); 
    $token = null; 

    if (preg_match('~^https?://[^/]+/sub/([^?\s]+)~', $originalLink, $match)) {
		$token = trim(explode('#', explode('?', $match[1])[0])[0]);
	}

    if (!$username || !$token) { 
        sendMessage("❌ لینک نامعتبر است.", $cancelKey); 
        exit(); 
    } 

    $stmt = $connection->prepare("INSERT INTO `add_link` (`remark`, `token`, `added_by`, `created_at`) VALUES (?, ?, ?, ?)"); 
    $stmt->bind_param("ssis", $username, $token, $addedBy, $currentTime); 
    $stmt->execute(); 
    $stmt->close(); 

    sendMessage("🔍 در حال بررسی اکانت، لطفاً صبر کنید...", $removeKeyboard); 

    $stmt = $connection->prepare("SELECT * FROM server_config WHERE type = 'marzban'"); 
    $stmt->execute(); 
    $serversList = $stmt->get_result(); 
    $stmt->close(); 

    $found = false; 
    $config = null;
    $serverId = 0;

    while ($row = $serversList->fetch_assoc()) { 
        $currentServerId = (int)$row['id']; 
        $userData = getMarzbanUser($currentServerId, $username); 

        if (!empty($userData) && !isset($userData->detail) && !empty($userData->username)) { 
            $found = true; 
            $config = $userData; 
            $serverId = $currentServerId; 
            break; 
        } 
    } 

    if (!$found || empty($config)) { 
        sendMessage("❌ اشتراک شما در پنل ما پیدا نشد. دقت کنید اگر زمان یا حجم اشتراک تمام شده باشد و بیشتر از 15 روز گذشته باشد حذف می شود.", $cancelKey); 
        exit(); 
    } 

    $remark = $config->username ?? $username; 
    $state = (($config->status ?? '') == "active") ? $buttonValues['active'] : $buttonValues['deactive']; 

    $dataLimit = $config->data_limit ?? 0; 
    $usedTraffic = $config->used_traffic ?? 0; 
    $expire = $config->expire ?? 0; 

    $total = $dataLimit != 0 ? sumerize($dataLimit) : "نامحدود"; 
    $totalUsed = sumerize($usedTraffic); 
    $leftMb = $dataLimit != 0 ? sumerize(max(0, $dataLimit - $usedTraffic)) : "نامحدود"; 
    $expiryTime = $expire != 0 ? jdate("Y-m-d H:i:s", $expire) : "نامحدود"; 
    $expiryDay = $expire != 0 ? floor(($expire - time()) / (60 * 60 * 24)) : "نامحدود"; 

    if (is_numeric($expiryDay) && $expiryDay < 0) { 
        $expiryDay = 0; 
    } 

    // استخراج شناسه اتصال برای ذخیره موقت
    $uuid = ''; 

    if (!empty($config->proxies->vmess->id)) { 
        $uuid = $config->proxies->vmess->id; 
    } elseif (!empty($config->proxies->vless->id)) { 
        $uuid = $config->proxies->vless->id; 
    } elseif (!empty($config->proxies->trojan->password)) { 
        $uuid = $config->proxies->trojan->password; 
    } elseif (!empty($config->uuid)) { 
        $uuid = $config->uuid; 
    } 

    $configLocation = [
        "remark" => $remark,
        "uuid" => $uuid,
        "username" => $remark,
        "server_id" => $serverId,
        "marzban" => true
    ];

    $keyboard = [ 
        [ 
            ['text' => $state ?? " ", 'callback_data' => "wizwizch"], 
            ['text' => "🔘 وضعیت اکانت 🔘", 'callback_data' => "wizwizch"], 
        ], 
        [ 
            ['text' => $remark ?? " ", 'callback_data' => "wizwizch"], 
            ['text' => "👤 نام کاربری", 'callback_data' => "wizwizch"], 
        ], 
        [ 
            ['text' => $totalUsed ?? " ", 'callback_data' => "wizwizch"], 
            ['text' => "↕️ آپلود + دانلود", 'callback_data' => "wizwizch"], 
        ], 
        [ 
            ['text' => $total ?? " ", 'callback_data' => "wizwizch"], 
            ['text' => "🔋 حجم کلی", 'callback_data' => "wizwizch"], 
        ], 
        [ 
            ['text' => $leftMb ?? " ", 'callback_data' => "wizwizch"], 
            ['text' => "🪫 حجم باقیمانده ", 'callback_data' => "wizwizch"], 
        ], 
        [ 
            ['text' => $expiryTime ?? " ", 'callback_data' => "wizwizch"], 
            ['text' => "📆 تاریخ اتمام", 'callback_data' => "wizwizch"], 
        ], 
        [ 
            ['text' => $expiryDay ?? " ", 'callback_data' => "wizwizch"], 
            ['text' => "🔄 تعداد روز باقیمانده", 'callback_data' => "wizwizch"], 
        ], 
    ]; 

    /*
    // دکمه های مربوط به تمدید یا بروزرسانی لینک
    if ($botState['renewAccountState'] == "on") {
        $keyboard[] = [
            ['text' => $buttonValues['renew_config'], 'callback_data' => "sConfigRenew" . $serverId],
        ];
    } elseif ($botState['updateConfigLinkState'] == "on") {
        $keyboard[] = [
            ['text' => $buttonValues['update_config_connection'], 'callback_data' => "sConfigUpdate" . $serverId],
        ];
    }
    */

    if ($from_id == $admin || $userInfo['isAdmin'] == true) { 
        if (empty($userInfo['addToCustomerClicked']) || $userInfo['addToCustomerClicked'] != true) { 
            $keyboard[] = [['text' => "➕ افزودن به حساب مشتری ➕", 'callback_data' => "addSubscriptionToCustomer" . $serverId]]; 
        } 
    } else { 
        $keyboard[] = [['text' => "➕ افزودن به مشاهده اشتراک ها ➕", 'callback_data' => "addToOrders" . $serverId]]; 
    } 

    $keyboard[] = [['text' => "↪️ برگشت به منوی اصلی", 'callback_data' => "mainMenu"]]; 
    $keys = json_encode(['inline_keyboard' => $keyboard]); 

    setUser(); 
    setUser(json_encode($configLocation, JSON_UNESCAPED_UNICODE), "temp"); 
    $userInfo['temp'] = json_encode($configLocation, JSON_UNESCAPED_UNICODE); 

    sendMessage("🔰 مشخصات اشتراک ارسالی 🔰 
🔄 در اینجا می توانید اشتراکی که برای خودتان یا دیگران است را تمدید کنید. 
🛑 لطفا دقت کنید فقط اشتراکی که برای خودتان است را به لیست اشتراک ها اضافه کنید و اشتراک دیگران را اضافه نکنید. 
➕ <b>نکته مهم:</b> اگر اشتراک برای خودتان هست قبل از تمدید اول آن را به ربات اضافه کنید و بعد از بخش مشاهده اشتراک ها پلن خودتان را انتخاب و تمدید کنید. 
", $keys, "HTML"); 
}

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

//Added by ACh

if (preg_match("/^addSubscriptionToCustomer(\d+)/", $data, $match)) {
    $serverId = intval($match[1]);
    $tempData = json_decode($userInfo['temp'], true) ?: [];
    
    if ($from_id != $admin && $userInfo['isAdmin'] != true) {
        alert("❌ شما دسترسی لازم برای این عملیات را ندارید.");
        exit();
    }

    $tempData['awaiting_userid_for_add'] = true;
    $tempData['selected_server_id'] = $serverId;
    $tempData['addToCustomerClicked'] = true;

    $stmt = $connection->prepare("UPDATE `users` SET `temp` = ? WHERE `userid` = ?");
    $tempJson = json_encode($tempData, JSON_UNESCAPED_UNICODE);
    $stmt->bind_param("si", $tempJson, $from_id);
    $stmt->execute();
    $stmt->close();

    $msg = "لطفاً شناسه عددی کاربر (user_id) را وارد کنید:";
    $keyboard = [
        [['text' => $buttonValues['back_to_main'], 'callback_data' => 'mainMenu']]
    ];

    sendMessage($msg, json_encode(['inline_keyboard' => $keyboard]), "html", $from_id);
    setUser("awaiting_userid_input", "step");
    exit();
}

$tempData = json_decode($userInfo['temp'], true) ?: [];

if ($userInfo['step'] == "awaiting_userid_input" && is_numeric($text)) {
    $customUserId = intval($text);
    $tempData['custom_userid'] = $customUserId;
    unset($tempData['awaiting_userid_for_add']);

    $stmt = $connection->prepare("UPDATE `users` SET `temp` = ?, `step` = '' WHERE `userid` = ?");
    $tempJson = json_encode($tempData, JSON_UNESCAPED_UNICODE);
    $stmt->bind_param("si", $tempJson, $from_id);
    $stmt->execute();
    $stmt->close();

    $serverId = intval($tempData['selected_server_id'] ?? 0);

    $msg = "✅ شناسه عددی <b>$customUserId</b> با موفقیت ثبت شد.";
    $keyboard = [
        [['text' => "➕ افزودن به اشتراک های کاربر ➕", 'callback_data' => "addToOrders" . $serverId]],
        [['text' => "🔍 جستجوی اشتراک کاربر", 'callback_data' => "searchUsersConfig"]],
        [['text' => $buttonValues['back_to_main'], 'callback_data' => 'mainMenu']]
    ];

    sendMessage($msg, json_encode(['inline_keyboard' => $keyboard]), "html", $from_id);
    exit();
}

function detectServerIdFromRemark($connection, $remark, $defaultServerId = 0) {
    $remark = mb_strtolower(trim((string)$remark));
    if ($remark === '') {
        return $defaultServerId;
    }

    $stmt = $connection->prepare("
        SELECT `id`, `remark`
        FROM `server_info`
        WHERE `active` = 1
        ORDER BY CHAR_LENGTH(`remark`) DESC, `id` ASC
    ");
    $stmt->execute();
    $result = $stmt->get_result();

    $firstActiveServerId = 0;

    while ($row = $result->fetch_assoc()) {
        $serverId = (int)$row['id'];
        $prefixRaw = trim((string)$row['remark']);

        if ($firstActiveServerId === 0) {
            $firstActiveServerId = $serverId;
        }

        if ($prefixRaw === '') {
            continue;
        }

        $prefixes = preg_split('/[\s,|]+/u', $prefixRaw, -1, PREG_SPLIT_NO_EMPTY);

        foreach ($prefixes as $prefix) {
            $prefix = mb_strtolower(trim($prefix));
            if ($prefix === '') {
                continue;
            }

            if (mb_substr($remark, 0, mb_strlen($prefix)) === $prefix) {
                $stmt->close();
                return $serverId;
            }
        }
    }

    $stmt->close();

    if ($firstActiveServerId > 0) {
        return $firstActiveServerId;
    }

    return $defaultServerId;
}

if (preg_match("/^addToOrders(\d+)/", $data, $match)) {
    $tempData = json_decode($userInfo['temp'], true) ?: [];

    $uuid = trim($tempData['uuid'] ?? '');
    $remark = trim($tempData['remark'] ?? '');
    $serverIdFromTemp = intval($tempData['server_id'] ?? 0);

    $finalUserId = !empty($tempData['custom_userid']) ? intval($tempData['custom_userid']) : $from_id;

    if (empty($remark)) {
        alert("❌ اطلاعات اشتراک یافت نشد.");
        exit();
    }

    $serverId = detectServerIdFromRemark(
        $connection,
        $remark,
        $serverIdFromTemp > 0 ? $serverIdFromTemp : 2
    );

    $stmt = $connection->prepare("SELECT `id` FROM `orders_list` WHERE `userid` = ? AND `remark` = ? LIMIT 1");
    $stmt->bind_param("is", $finalUserId, $remark);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    if ($exists) {
        alert("✅ این اشتراک قبلاً اضافه شده است.");
        exit();
    }

    $stmt = $connection->prepare("
        SELECT `token`
        FROM `add_link`
        WHERE `added_by` = ? AND `remark` = ?
        ORDER BY `created_at` DESC
        LIMIT 1
    ");
    $stmt->bind_param("is", $from_id, $remark);
    $stmt->execute();
    $result = $stmt->get_result();

    $token = '';
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $token = $row['token'];
    }
    $stmt->close();

    if (empty($token)) {
        alert("❌ توکن یافت نشد.");
        exit();
    }

    $connection->begin_transaction();

    try {
        $now = time();
        $expire_date = ($serverId == 3) ? $now + (5 * 365 * 86400) : $now + (30 * 86400);

        $userid = strval($finalUserId);
        $transid = "";
        $fileid = 0;
        $inbound_id = 0;
        $protocol = "";
        $link = '[""]';
        $amount = 100;
        $status = 1;
        $date = time() - (1 * 30 * 86400);
        $notif = 0;
        $rahgozar = 0;
        $agent_bought = 0;

        $stmt = $connection->prepare("
            INSERT INTO `orders_list`
            (`userid`, `token`, `transid`, `fileid`, `server_id`, `inbound_id`, `remark`, `uuid`, `protocol`, `expire_date`, `link`, `amount`, `status`, `date`, `notif`, `rahgozar`, `agent_bought`)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->bind_param(
            "sssisississsiiiii",
            $userid,
            $token,
            $transid,
            $fileid,
            $serverId,
            $inbound_id,
            $remark,
            $uuid,
            $protocol,
            $expire_date,
            $link,
            $amount,
            $status,
            $date,
            $notif,
            $rahgozar,
            $agent_bought
        );

        $stmt->execute();
        $stmt->close();

        $connection->commit();

        alert("✅ اشتراک با موفقیت به لیست اضافه شد.");

        $msg = "⚠️ اشتراک  <code>$remark</code>  با موفقیت برای شما در ربات ثبت شد. از پایین بزنید روی دکمه:

 ← 🔄 <b>مشاهده اشتراک ها</b> 

🔰 از لیست اشتراک خودتان را انتخاب کنید و در صفحه بعد اگر قصد تمدید دارید بزنید روی دکمه: 

← ♻️ <b>تمدید اشتراک</b>

💰 وقتی مبلغ نمایش داده شد روی دکمه زیر بزنید:

← 💳 <b>کارت به کارت</b>

⬆️ حالا بروید <b>مبلغ را کارت به کارت کنید</b> و بیایید رسید آن را در داخل ربات بفرستید.";

        $keyboard = [
            [
                ['text' => $buttonValues['my_subscriptions'], 'callback_data' => 'mySubscriptions'],
                ['text' => $buttonValues['back_to_main'], 'callback_data' => 'mainMenu']
            ]
        ];

        sendMessage($msg, json_encode(['inline_keyboard' => $keyboard]), "html", $finalUserId);

        $stmt = $connection->prepare("SELECT `id` FROM `orders_list` WHERE `userid` = ? AND `remark` = ? ORDER BY `id` DESC LIMIT 1");
        $stmt->bind_param("is", $finalUserId, $remark);
        $stmt->execute();
        $result = $stmt->get_result();
        $orderRow = $result->fetch_assoc();
        $orderId = $orderRow['id'] ?? 0;
        $stmt->close();

        if ($orderId > 0) {
            $inlineKeyboard = json_encode([
                'inline_keyboard' => [
                    [['text' => "💡مشاهده مشخصات اشتراک", 'callback_data' => "quickUserOrderDetails_{$orderId}"]]
                ]
            ]);

            $adminMessage = "
➕ اشتراک جدید اضافه شد

👤 شناسه عددی: <code>$finalUserId</code>
👨 اسم کاربر: $first_name
⚡️ نام کاربری: @$username
🎈 نام کاربری اشتراک: <code>$remark</code>
";

            sendMessage($adminMessage, $inlineKeyboard, "html", $admin);
            sendToAllAdmins('sendMessage', [
                'text' => $adminMessage,
                'parse_mode' => "html",
                'reply_markup' => $inlineKeyboard
            ]);
        }

        unset($tempData['custom_userid']);
        unset($tempData['awaiting_userid_for_add']);
        unset($tempData['selected_server_id']);
        unset($tempData['addToCustomerClicked']);

        $stmt = $connection->prepare("UPDATE `users` SET `temp` = ? WHERE `userid` = ?");
        $tempJson = json_encode($tempData, JSON_UNESCAPED_UNICODE);
        $stmt->bind_param("si", $tempJson, $from_id);
        $stmt->execute();
        $stmt->close();

    } catch (Exception $e) {
        $connection->rollback();
        alert("❌ ثبت اشتراک با خطا مواجه شد.");
        exit();
    }

    exit();
}

// هندل دکمه نمایش اشتراک های کاربر
if (preg_match('/^showUserSubscriptions(\d+)/', $data, $match)) {
    $userId = $match[1];
    $userInfo = getUserInfoKeys($userId);
    
    if (isset($userInfo['subscriptionMessages']) && count($userInfo['subscriptionMessages']) > 0) {
        $subscriptionMessages = $userInfo['subscriptionMessages'];
        
        $title = "🔄 <b>لیست اشتراک های کاربر</b>\n🔐 شناسه عددی: <code>$userId</code>";
        $keyboard = json_encode([
            'inline_keyboard' => [
                [['text' => "🔍 جستجوی اشتراک کاربر", 'callback_data' => "searchUsersConfig"]]
            ]
        ]);

        foreach ($subscriptionMessages as $message) {
            $fullMessage = $title . "\n\n" . $message;
            sendMessage($fullMessage, $keyboard, 'HTML', $callback_query['message']['chat']['id']);
        }
    } else {
        sendMessage("هیچ اشتراکی برای این کاربر موجود نیست.", null, 'HTML', $callback_query['message']['chat']['id']);
    }
}

// Begin of Manage a specific order
if ($data == "manageorderdatabase" && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    delMessage();
    sendMessage("✏️ لطفا `remark` مربوط به سفارش را وارد کنید:", $cancelKey, "Markdown");
    setUser("awaitingOrderRemarkOnly");
}
if ($userInfo['step'] == "awaitingOrderRemarkOnly" && $text != $buttonValues['cancel']) {
    $remark = trim($text);
    $remark = mysqli_real_escape_string($connection, $remark);

    // بررسی کاراکترهای معتبر
    if (!preg_match('/^[a-zA-Z0-9\s\-_]+$/', $remark)) {
        sendMessage("❌ ریمارک شامل کاراکترهای نامعتبر است. لطفاً ریمارک معتبر وارد کنید.", $cancelKey);
        setUser();
        return;
    }

    $stmt = $connection->prepare("SELECT * FROM orders_list WHERE remark = ?");
    if (!$stmt) {
        sendMessage("❌ خطایی در ارتباط با دیتابیس رخ داد.", $cancelKey);
        setUser();
        return;
    }
    $stmt->bind_param("s", $remark);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();

    $count = $result->num_rows;

    if ($count == 0) {
        $keyboard = json_encode([
            'inline_keyboard' => [
                [['text' => "🔂 ارسال remark جدید", 'callback_data' => "manageorderdatabase"]]
            ]
        ]);
        sendMessage("❌ سفارشی با این remark یافت نشد.", $keyboard);
        setUser();
        return;
    }

    if ($count == 1) {
        $order = $result->fetch_assoc();
        setUser("manageOrderById_" . $order['id']);
        showOrderManagementPanel($order);
        return;
    }

    $keyboard = ['inline_keyboard' => []];
    $useridList = [];
    $maxResults = 10;
    $counter = 0;

    $tempRemarkId = uniqid(); // شناسه موقت برای ریمارک
    while ($row = $result->fetch_assoc()) {
        if (!in_array($row['userid'], $useridList)) {
            if ($counter >= $maxResults) {
                sendMessage("⚠️ تعداد نتایج زیاد است. لطفاً ریمارک دقیق‌تری وارد کنید.", $cancelKey);
                setUser();
                return;
            }
            $useridList[] = $row['userid'];
            $callbackData = "selectOrderByRemark_userid_{$row['userid']}_tempId_" . $tempRemarkId;
            if (strlen($callbackData) > 64) {
                sendMessage("❌ خطا: داده‌های callback خیلی طولانی هستند.", $cancelKey);
                setUser();
                return;
            }
            $keyboard['inline_keyboard'][] = [['text' => "{$row['userid']}", 'callback_data' => $callbackData]];
            $counter++;
        }
    }
    $keyboard['inline_keyboard'][] = [['text' => "🔂 ارسال remark جدید", 'callback_data' => "manageorderdatabase"]];

    // ارسال پیام با مدیریت خطا
    $response = sendMessage("👤 چند سفارش با این remark یافت شد. لطفاً یکی از userID‌ها را انتخاب کنید:", json_encode($keyboard));
    if (!$response) {
        sendMessage("❌ خطایی در ارسال پیام رخ داد. لطفاً دوباره تلاش کنید.", $cancelKey);
        setUser();
        return;
    }

    // ذخیره حالت کاربر و ریمارک موقت
    setUser("awaitingUserIdSelection_" . $tempRemarkId . "_" . $remark);
}
// مدیریت انتخاب userID
if (preg_match('/^selectOrderByRemark_userid_(\d+)_tempId_(.+)/', $data, $match)) {
    $userid = intval($match[1]);
    $tempRemarkId = $match[2];

    // بازیابی ریمارک از حالت کاربر یا دیتابیس
    if (preg_match('/^awaitingUserIdSelection_' . $tempRemarkId . '_(.+)/', $userInfo['step'], $remarkMatch)) {
        $remark = $remarkMatch[1];
    } else {
        sendMessage("❌ خطا: ریمارک موقت نامعتبر است.", $cancelKey);
        setUser();
        return;
    }

    $stmt = $connection->prepare("SELECT * FROM orders_list WHERE userid = ? AND remark = ? LIMIT 1");
    if (!$stmt) {
        sendMessage("❌ خطایی در ارتباط با دیتابیس رخ داد.", $cancelKey);
        setUser();
        return;
    }
    $stmt->bind_param("is", $userid, $remark);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();

    if ($result->num_rows == 0) {
        $keyboard = json_encode([
            'inline_keyboard' => [
                [['text' => "🔂 ارسال remark جدید", 'callback_data' => "manageorderdatabase"]]
            ]
        ]);
        sendMessage("❌ سفارشی با این مشخصات پیدا نشد.", $keyboard);
        setUser();
        return;
    }

    $order = $result->fetch_assoc();

    setUser("manageOrderById_" . $order['id']);
    showOrderManagementPanel($order);
}

function showOrderManagementPanel($order, $msgId = null){
	
    $notifStatus = $order['notif'] == 0 ? "🔘 ارسال نشده" : "✅ ارسال شده";
	$agentStatus = $order['agent_bought'] == 0 ? "🔘 نیست" : "✅ هست";

    $keyboard = [
        [["text" => "🗑 حذف سفارش", "callback_data" => "deleteOrder_{$order['id']}"]],
        [["text" => "📅 تغییر تاریخ انقضا", "callback_data" => "changeExpireDate_{$order['id']}"]],
        [["text" => "🔑 تغییر Token", "callback_data" => "changeToken_{$order['id']}"]],
        [["text" => "🖥 تغییر Server ID", "callback_data" => "changeServerId_{$order['id']}"]],
        [["text" => "📦 تغییر Plan", "callback_data" => "changeFileId_{$order['id']}_{$order['server_id']}"]],
        [["text" => "🧬 تغییر UUID", "callback_data" => "changeUUID_{$order['id']}"]],
		[["text" => "💎 خرید همکار ←  $agentStatus", "callback_data" => "toggleAgent_{$order['id']}"]],
        [["text" => "🔔 وضعیت Notif ←  $notifStatus", "callback_data" => "toggleNotif_{$order['id']}"]],
		[["text" => "💡 مشاهده مشخصات اشتراک", "callback_data" => "quickUserOrderDetails_{$order['id']}"]],
        [["text" => "⚙️ بازگشت به مدیریت", "callback_data" => "managePanel"]],
    ];

    $msg = "🔧 مدیریت سفارش مربوط به:\n\n👤 UserID: <code>{$order['userid']}</code>\n📛 Remark: <code>{$order['remark']}</code>";

    if ($msgId) {
        editText($msgId, $msg, json_encode(['inline_keyboard' => $keyboard]), "html");
    } else {
        sendMessage($msg, json_encode(['inline_keyboard' => $keyboard]), "html");
    }
}

if (preg_match('/^returnToPanel_(\\d+)/', $data, $match)) {
    $id = intval($match[1]);
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();
    if ($result->num_rows > 0) {
        $order = $result->fetch_assoc();
        showOrderManagementPanel($order, $message_id);
    }
    setUser("manageOrderById_" . $id);
}

if (preg_match('/^deleteOrder_(\\d+)/', $data, $match)) {
    $id = intval($match[1]);

    // دریافت اطلاعات قبل از حذف
    $stmt = $connection->prepare("SELECT userid, remark FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();

    if ($result->num_rows == 0) {
        alert("❌ سفارشی برای حذف پیدا نشد.");
        return;
    }

    $row = $result->fetch_assoc();
    $userid = $row['userid'];
    $remark = $row['remark'];

    // حذف رکورد
    $stmt = $connection->prepare("DELETE FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();

    // پیام برای کاربر
    alert("✅ سفارش با موفقیت حذف شد.");
    sendMessage("✅ سفارش مربوط به:\n\n👤 UserID: <code>$userid</code>\n📛 Remark: <code>$remark</code>\n\nبا موفقیت حذف شد.\nبرای مدیریت سفارش دیگری، دوباره دکمه مدیریت سفارش در دیتابیس را فشار دهید.", null, "html");

    // پیام برای ادمین ها
    $adminMessage = "🚨 سفارش حذف شد:\n\n👤 UserID: <code>$userid</code>\n📛 Remark: <code>$remark</code>\n\n🗑 توسط: <code>$from_id</code>";
    //sendToAllAdmins('sendMessage', ['text' => $adminMessage,'parse_mode' => 'html']);
	
	// ارسال پیام به کانال گزارشات
	$rewardChannel = $botState['rewardChannel'];
		if (!empty($rewardChannel)) {
		sendMessage($adminMessage, null, "html", $rewardChannel);
	}

    setUser();
}

if (preg_match('/^toggleAgent_(\\d+)/', $data, $match)) {
    $id = intval($match[1]);
    $stmt = $connection->prepare("SELECT `agent_bought` FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $newAgent = $row['agent_bought'] == 0 ? 1 : 0;
        $stmt = $connection->prepare("UPDATE `orders_list` SET `agent_bought` = ? WHERE `id` = ?");
        $stmt->bind_param("ii", $newAgent, $id);
        $stmt->execute();
        $stmt->close();

        $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $stmt->close();
        if ($result->num_rows > 0) {
            $order = $result->fetch_assoc();
            showOrderManagementPanel($order, $message_id);
        }
    }
}

if (preg_match('/^toggleNotif_(\\d+)/', $data, $match)) {
    $id = intval($match[1]);
    $stmt = $connection->prepare("SELECT `notif` FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $newNotif = $row['notif'] == 0 ? -1 : 0;
        $stmt = $connection->prepare("UPDATE `orders_list` SET `notif` = ? WHERE `id` = ?");
        $stmt->bind_param("ii", $newNotif, $id);
        $stmt->execute();
        $stmt->close();

        $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $stmt->close();
        if ($result->num_rows > 0) {
            $order = $result->fetch_assoc();
            showOrderManagementPanel($order, $message_id);
        }
    }
}

if (preg_match('/^changeExpireDate_(\\d+)/', $data, $match)) {
    $id = intval($match[1]);
    sendMessage("🔁 لطفا تعداد روزی که می‌خواهید به تاریخ انقضا اضافه یا کم شود را وارد کنید.\n\nمثال: `+10` یا `-5`", $cancelKey, "Markdown");
    setUser("awaitingExpireChange_$id");
}

if (preg_match('/^awaitingExpireChange_(\\d+)/', $userInfo['step'], $match) && is_numeric($text)) {
    $id = intval($match[1]);
    $days = intval($text);
    $stmt = $connection->prepare("UPDATE `orders_list` SET `expire_date` = `expire_date` + (? * 86400) WHERE `id` = ?");
    $stmt->bind_param("ii", $days, $id);
    $stmt->execute();
    $stmt->close();

    sendMessage("✅ تاریخ انقضا با موفقیت ویرایش شد.");
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();
    $order = $result->fetch_assoc();
    showOrderManagementPanel($order, $message_id);
    setUser();
}

if (preg_match('/^changeToken_(\\d+)/', $data, $match)) {
    $id = intval($match[1]);
    sendMessage("🔐 لطفا مقدار جدید Token را وارد کنید:", $cancelKey);
    setUser("awaitingToken_$id");
}

if (preg_match('/^awaitingToken_(\\d+)/', $userInfo['step'], $match) && $text != $buttonValues['cancel']) {
    $id = intval($match[1]);
    $stmt = $connection->prepare("UPDATE `orders_list` SET `token` = ? WHERE `id` = ?");
    $stmt->bind_param("si", $text, $id);
    $stmt->execute();
    $stmt->close();
    sendMessage("✅ مقدار Token با موفقیت تغییر یافت.");
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();
    $order = $result->fetch_assoc();
    showOrderManagementPanel($order, $message_id);
    setUser();
}

if (preg_match('/^changeUUID_(\\d+)/', $data, $match)) {
    $id = intval($match[1]);
    sendMessage("🧬 لطفا مقدار جدید UUID را وارد کنید:", $cancelKey);
    setUser("awaitingUUID_$id");
}

if (preg_match('/^awaitingUUID_(\\d+)/', $userInfo['step'], $match) && $text != $buttonValues['cancel']) {
    $id = intval($match[1]);
    $stmt = $connection->prepare("UPDATE `orders_list` SET `uuid` = ? WHERE `id` = ?");
    $stmt->bind_param("si", $text, $id);
    $stmt->execute();
    $stmt->close();
    sendMessage("✅ مقدار UUID با موفقیت تغییر یافت.");
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();
    $order = $result->fetch_assoc();
    showOrderManagementPanel($order, $message_id);
    setUser();
}

if (preg_match('/^changeServerId_(\\d+)/', $data, $match)) {
    $id = intval($match[1]);
    $stmt = $connection->query("SELECT * FROM server_info");
    $keyboard = [];
    while ($row = $stmt->fetch_assoc()) {
        $keyboard[] = [["text" => $row['title'], "callback_data" => "selectServerId_{$id}_{$row['id']}"]];
    }
    $keyboard[] = [["text" => "🔙 بازگشت", "callback_data" => "returnToPanel_$id"]];
    sendMessage("🌐 لطفا سرور جدید را انتخاب کنید:", json_encode(['inline_keyboard' => $keyboard]));
}

if (preg_match('/^selectServerId_(\\d+)_(\\d+)/', $data, $match)) {
    $id = intval($match[1]);
    $newServerId = intval($match[2]);
    $stmt = $connection->prepare("UPDATE `orders_list` SET `server_id` = ? WHERE `id` = ?");
    $stmt->bind_param("ii", $newServerId, $id);
    $stmt->execute();
    $stmt->close();
    sendMessage("✅ مقدار Server ID با موفقیت تغییر یافت.");
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();
    $order = $result->fetch_assoc();
    showOrderManagementPanel($order, $message_id);
    setUser();
}

if (preg_match('/^changeFileId_(\\d+)_(\\d+)/', $data, $match)) {
    $id = intval($match[1]);
    $serverId = intval($match[2]);
    $stmt = $connection->prepare("SELECT * FROM server_plans WHERE server_id = ?");
    $stmt->bind_param("i", $serverId);
    $stmt->execute();
    $result = $stmt->get_result();
    $keyboard = [];
    while ($row = $result->fetch_assoc()) {
        $keyboard[] = [["text" => $row['title'], "callback_data" => "selectFileId_{$id}_{$row['id']}"]];
    }
    $keyboard[] = [["text" => "🔙 بازگشت", "callback_data" => "returnToPanel_$id"]];
    sendMessage("📦 لطفا پلن جدید را انتخاب کنید:", json_encode(['inline_keyboard' => $keyboard]));
    $stmt->close();
}

if (preg_match('/^selectFileId_(\\d+)_(\\d+)/', $data, $match)) {
    $id = intval($match[1]);
    $newFileId = intval($match[2]);
    $stmt = $connection->prepare("UPDATE `orders_list` SET `fileid` = ? WHERE `id` = ?");
    $stmt->bind_param("ii", $newFileId, $id);
    $stmt->execute();
    $stmt->close();
    sendMessage("✅ مقدار Fileid (پلن) با موفقیت تغییر یافت.");
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();
    $order = $result->fetch_assoc();
    showOrderManagementPanel($order, $message_id);
    setUser();
}

if (preg_match('/^quickUserOrderDetails_(\d+)/', $data, $match)) {
    $orderId = intval($match[1]);

    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();

    if ($result->num_rows == 0) {
        alert("❌ اطلاعات سفارش یافت نشد.");
        return;
    }

    $order = $result->fetch_assoc();
    $keys = getUserOrderDetailKeys($orderId, 0); // فرض: 0 یعنی admin دیده

    if ($keys == null) {
        alert("❌ جزئیاتی برای این سفارش یافت نشد.");
        return;
    }

    sendMessage($keys['msg'], $keys['keyboard'], "HTML");
}

// End of Manage a specific order
//End by ACh

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

if (preg_match('/sConfigUpdate(\d+)/', $data, $match)) {
    alert($mainValues['please_wait_message']);
    $server_id = $match[1];
    if (empty($userInfo['temp'])) { delMessage(); exit(); }

    $configInfo = json_decode($userInfo['temp'], true);
    $inboundId = $configInfo['id'] ?? 0;
    $uuid = $configInfo['uuid'];
    $remark = $configInfo['remark'];

    if (isset($configInfo['marzban'])) {
        $info = getMarzbanUserInfo($server_id, $remark);
        $vraylink = $info->links;
    } else {
        $response = getJson($server_id)->obj;
        if ($response == null) { delMessage(); exit(); }

        if ($inboundId == 0) {
            foreach ($response as $row) {
                $clients = json_decode($row->settings)->clients;
                if ($clients[0]->id == $uuid || $clients[0]->password == $uuid) {
                    $port = $row->port;
                    $protocol = $row->protocol;
                    $netType = json_decode($row->streamSettings)->network;
                    break;
                }
            }
        } else {
            foreach ($response as $row) {
                if ($row->id == $inboundId) {
                    $port = $row->port;
                    $protocol = $row->protocol;
                    $netType = json_decode($row->streamSettings)->network;
                    break;
                }
            }
        }

        if ($uuid == null) { delMessage(); exit(); }
        $vraylink = getConnectionLink($server_id, $uuid, $protocol, $remark, $port, $netType, $inboundId);
    }

    if ($vraylink == null) { delMessage(); exit(); }

    include_once 'phpqrcode/qrlib.php';

    if (!defined('IMAGE_WIDTH'))  define('IMAGE_WIDTH', 540);
    if (!defined('IMAGE_HEIGHT')) define('IMAGE_HEIGHT', 540);

    foreach ($vraylink as $vray_link) {
        $acc_text = ($botState['configLinkState'] != "off") ? "<code>$vray_link</code>" : ".";

        $ecc = 'L';
        $pixel_Size = 11;
        $frame_Size = 4;

        $file = RandomString() . ".png";
        QRcode::png($vray_link, $file, $ecc, $pixel_Size, $frame_Size);

        sendPhoto($botUrl . $file, $acc_text, null, "HTML");
        unlink($file);
    }
}

if (($data == 'addNewPlan' || $data=="addNewRahgozarPlan" || $data == "addNewMarzbanPlan") and (($from_id == $admin || $userInfo['isAdmin'] == true))){
    setUser($data);
    $stmt = $connection->prepare("DELETE FROM `server_plans` WHERE `active`=0");
    $stmt->execute();
    $stmt->close();
    if($data=="addNewPlan" || $data == "addNewMarzbanPlan"){
        $sql = "INSERT INTO `server_plans` (`fileid`, `catid`, `server_id`, `inbound_id`, `acount`, `limitip`, `title`, `protocol`, `days`, `volume`, `type`, `price`, `descr`, `pic`, `active`, `step`, `date`)
                                            VALUES ('', 0,0,0,0, 1, '', '', 0, 0, '', 0, '', '',0,1, ?);";
    }elseif($data=="addNewRahgozarPlan"){
        $sql = "INSERT INTO `server_plans` (`fileid`, `catid`, `server_id`, `inbound_id`, `acount`, `limitip`, `title`, `protocol`, `days`, `volume`, `type`, `price`, `descr`, `pic`, `active`, `step`, `date`, `rahgozar`)
                    VALUES ('', 0,0,0,0, 1, '', '', 0, 0, '', 0, '', '',0,1, ?, 1);";
    }
    $stmt = $connection->prepare($sql);
    $stmt->bind_param("i", $time);
    $stmt->execute();
    $stmt->close();
    delMessage();
    $msg = '❗️یه عنوان برا پلن انتخاب کن:';
    sendMessage($msg,$cancelKey);
    exit;
}
if(preg_match('/(addNewRahgozarPlan|addNewPlan|addNewMarzbanPlan)/',$userInfo['step']) and $text!=$buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $catkey = [];
    $stmt = $connection->prepare("SELECT * FROM `server_categories` WHERE `parent` =0 and `active`=1");
    $stmt->execute();
    $cats = $stmt->get_result();
    $stmt->close();

    while ($cat = $cats->fetch_assoc()){
        $id = $cat['id'];
        $name = $cat['title'];
        $catkey[] = ["$id - $name"];
    }
    $catkey[] = [$buttonValues['cancel']];

    $step = checkStep('server_plans');

    if($step==1 and $text!=$buttonValues['cancel']){
        $msg = '🔰 لطفا قیمت پلن رو به تومان وارد کنید!';
        if(strlen($text)>1){
            $stmt = $connection->prepare("UPDATE `server_plans` SET `title`=?,`step`=2 WHERE `active`=0 and `step`=1");
            $stmt->bind_param("s", $text);
            $stmt->execute();
            $stmt->close();
            sendMessage($msg,$cancelKey);
        }
    } 
    if($step==2 and $text!=$buttonValues['cancel']){
        $msg = '🔰 لطفا یه دسته از لیست زیر برا پلن انتخاب کن ';
        if(is_numeric($text)){
            $stmt = $connection->prepare("UPDATE `server_plans` SET `price`=?,`step`=3 WHERE `active`=0");
            $stmt->bind_param("s", $text);
            $stmt->execute();
            $stmt->close();
            sendMessage($msg,json_encode(['keyboard'=>$catkey,'resize_keyboard'=>true]));
        }else{
            $msg = '‼️ لطفا یک مقدار عددی وارد کنید.';
            sendMessage($msg,$cancelKey);
        }
    } 
    if($step==3 and $text!=$buttonValues['cancel']){
        $srvkey = [];

        $stmt = $connection->prepare("SELECT `id` FROM `server_config` WHERE `type` = 'marzban'");
        $stmt->execute();
        $info = $stmt->get_result()->fetch_all();
        $stmt->close();
        
        
        
        $marzbanList = array_column($info, 0); 
        if(count($marzbanList) > 0) $condition  = " AND `id` " .($userInfo['step'] == "addNewMarzbanPlan"?"IN":"NOT IN") . " (" . implode(", ", $marzbanList) . ")";
        else $condition = "";


        $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `active`=1 $condition");
        $stmt->execute();
        
        $srvs = $stmt->get_result();
        $stmt->close();
        sendMessage($mainValues['please_wait_message'],$cancelKey);
        while($srv = $srvs->fetch_assoc()){
            $id = $srv['id'];
            $title = $srv['title'];
            $srvkey[] = ['text' => "$title", 'callback_data' => "selectNewPlanServer$id"];
        }
        $srvkey = array_chunk($srvkey,2);
        sendMessage("لطفا یکی از سرورها رو انتخاب کن 👇 ", json_encode([
                'inline_keyboard' => $srvkey]), "HTML");
        $inarr = 0;
        foreach ($catkey as $op) {
            if (in_array($text, $op) and $text != $buttonValues['cancel']) {
                $inarr = 1;
            }
        }
        if( $inarr==1 ){
            $input = explode(' - ',$text);
            $catid = $input[0];
            $stmt = $connection->prepare("UPDATE `server_plans` SET `catid`=?,`step`=50 WHERE `active`=0");
            $stmt->bind_param("i", $catid);
            $stmt->execute();
            $stmt->close();

            sendMessage($msg,$cancelKey);
        }else{
            $msg = '‼️ لطفا فقط یکی از گزینه های پیشنهادی زیر را انتخاب کنید.';
            sendMessage($msg,$catkey);
        }
    } 
    if($step==50 and $text!=$buttonValues['cancel'] and preg_match('/selectNewPlanServer(\d+)/', $data,$match)){
        $newStep = $userInfo['step'] == "addNewMarzbanPlan"?53:51;
        
        $stmt = $connection->prepare("UPDATE `server_plans` SET `server_id`=?,`step`=? WHERE `active`=0");
        $stmt->bind_param("ii", $match[1], $newStep);
        $stmt->execute();
        $stmt->close();

        $keys = json_encode(['inline_keyboard'=>[
            [['text'=>"🎖 پورت اختصاصی",'callback_data'=>"withSpecificPort"]],
            [['text'=>"🎗 پورت اشتراکی",'callback_data'=>"withSharedPort"]]
            ]]);
        if($userInfo['step'] != "addNewMarzbanPlan") editText($message_id, "لطفا نوع پورت پنل رو انتخاب کنید.", $keys);
        else editText($message_id, "📅 لطفا تعداد روز های اعتبار این پلن را وارد کنید:");
    }
    if($step==51 and $text!=$buttonValues['cancel'] and preg_match('/^with(Specific|Shared)Port/',$data,$match)){
        if($userInfo['step'] == "addNewRahgozarPlan") $msg =  "📡 لطفا پروتکل پلن مورد نظر را وارد کنید (vless | vmess)";
        else $msg =  "📡 لطفا پروتکل پلن مورد نظر را وارد کنید (vless | vmess | trojan)";
        editText($message_id,$msg);
        if($match[1] == "Shared"){
            $stmt = $connection->prepare("UPDATE `server_plans` SET `step`=60 WHERE `active`=0");
            $stmt->execute();
            $stmt->close();
        }
        elseif($match[1] == "Specific"){
            $stmt = $connection->prepare("UPDATE server_plans SET step=52 WHERE active=0");
            $stmt->execute();
            $stmt->close();
        }
    }
    if($step==60 and $text!=$buttonValues['cancel']){
        if($text != "vless" && $text != "vmess" && $text != "trojan" && $userInfo['step'] == "addNewPlan"){
            sendMessage("لطفا فقط پروتکل های vless و vmess را وارد کنید",$cancelKey);
            exit();
        }
        elseif($text != "vless" && $text != "vmess" && $userInfo['step'] == "addNewRahgozarPlan"){
            sendMessage("لطفا فقط پروتکل های vless و vmess را وارد کنید.",$cancelKey);
            exit();
        }
        
        $stmt = $connection->prepare("UPDATE `server_plans` SET `protocol`=?,`step`=61 WHERE `active`=0");
        $stmt->bind_param("s", $text);
        $stmt->execute();
        $stmt->close();
        sendMessage("📅 لطفا تعداد روز های اعتبار این پلن را وارد کنید:");
    }
    if($step==61 and $text!=$buttonValues['cancel']){
        if(!is_numeric($text)){
            sendMessage($mainValues['send_only_number']);
            exit();
        }
        
        $stmt = $connection->prepare("UPDATE `server_plans` SET `days`=?,`step`=62 WHERE `active`=0");
        $stmt->bind_param("i", $text);
        $stmt->execute();
        $stmt->close();

        sendMessage("🔋 لطفا مقدار حجم به GB این پلن را وارد کنید:");
    }
    if($step==62 and $text!=$buttonValues['cancel']){
        if(!is_numeric($text)){
            sendMessage($mainValues['send_only_number']);
            exit();
        }
        
        $stmt = $connection->prepare("UPDATE `server_plans` SET `volume`=?,`step`=63 WHERE `active`=0");
        $stmt->bind_param("d", $text);
        $stmt->execute();
        $stmt->close();
        sendMessage("🛡 لطفا آیدی سطر کانکشن در پنل را وارد کنید:");
    }
    if($step==63 and $text!=$buttonValues['cancel']){
        if(!is_numeric($text)){
            sendMessage($mainValues['send_only_number']);
            exit();
        }
        
        $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `active` = 0");
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        
        $response = getJson($res['server_id'])->obj;
        foreach($response as $row){
            if($row->id == $text) {
                $netType = json_decode($row->streamSettings)->network;
            }
        }        
        if(is_null($netType)){
            sendMessage("اشتراکی با این سطر آیدی یافت نشد.");
            exit();
        }
        
        $stmt = $connection->prepare("UPDATE `server_plans` SET `type` = ?, `inbound_id`=?,`step`=64 WHERE `active`=0");
        $stmt->bind_param("si", $netType, $text);
        $stmt->execute();
        $stmt->close();

        sendMessage("لطفا ظرفیت تعداد اکانت رو پورت مورد نظر را وارد کنید.");
    }
    if($step==64 and $text!=$buttonValues['cancel']){
        if(!is_numeric($text)){
            sendMessage($mainValues['send_only_number']);
            exit();
        }
        
        $stmt = $connection->prepare("UPDATE `server_plans` SET `acount`=?,`step`=65 WHERE `active`=0");
        $stmt->bind_param("i", $text);
        $stmt->execute();
        $stmt->close();

        sendMessage("🧲 لطفا تعداد چند کاربره این پلن را وارد کنید ( 0 نامحدود است )");
    }
    if($step==65 and $text!=$buttonValues['cancel']){
        if(!is_numeric($text)){
            sendMessage($mainValues['send_only_number']);
            exit();
        }
        $stmt = $connection->prepare("UPDATE `server_plans` SET `limitip`=?,`step`=4 WHERE `active`=0");
        $stmt->bind_param("s", $text);
        $stmt->execute();
        $stmt->close();

        $msg = '🔻یه توضیح برای پلن مورد نظرت بنویس:';
        sendMessage($msg,$cancelKey); 
    }
    if($step==52 and $text!=$buttonValues['cancel']){
        if($userInfo['step'] == "addNewPlan" && $text != "vless" && $text != "vmess" && $text != "trojan"){
            sendMessage("لطفا فقط پروتکل های vless و vmess را وارد کنید",$cancelKey);
            exit();
        }elseif($userInfo['step'] == "addNewRahgozarPlan" && $text != "vless" && $text != "vmess"){
            sendMessage("لطفا فقط پروتکل های vless و vmess را وارد کنید",$cancelKey);
            exit();
        }
        
        $stmt = $connection->prepare("UPDATE `server_plans` SET `protocol`=?,`step`=53 WHERE `active`=0");
        $stmt->bind_param("s", $text);
        $stmt->execute();
        $stmt->close();

        sendMessage("📅 لطفا تعداد روز های اعتبار این پلن را وارد کنید:");
    }
    if($step==53 and $text!=$buttonValues['cancel']){
        if(!is_numeric($text)){
            sendMessage($mainValues['send_only_number']);
            exit();
        }
        
        $stmt = $connection->prepare("UPDATE `server_plans` SET `days`=?,`step`=54 WHERE `active`=0");
        $stmt->bind_param("i", $text);
        $stmt->execute();
        $stmt->close();

        sendMessage("🔋 لطفا مقدار حجم به GB این پلن را وارد کنید:");
    }
    if($step==54 and $text!=$buttonValues['cancel']){
        if(!is_numeric($text)){
            sendMessage($mainValues['send_only_number']);
            exit();
        }
        
        if($userInfo['step'] == "addNewPlan"){
            $sql = ("UPDATE `server_plans` SET `volume`=?,`step`=55 WHERE `active`=0");
            $msg = "🔉 لطفا نوع شبکه این پلن را در انتخاب کنید  (ws | tcp | grpc):";
        }elseif($userInfo['step'] == "addNewRahgozarPlan" || $userInfo['step'] == "addNewMarzbanPlan"){
            $sql = ("UPDATE `server_plans` SET `volume`=?, `type`='ws', `step`=4 WHERE `active`=0");
            $msg = '🔻یه توضیح برای پلن مورد نظرت بنویس:';
        }
        $stmt = $connection->prepare($sql);
        $stmt->bind_param("d", $text);
        $stmt->execute();
        $stmt->close();

        sendMessage($msg);
    }
    if($step==55 and $text!=$buttonValues['cancel']){
        if($text != "tcp" && $text != "ws" && $text != "grpc"){
            sendMessage("لطفا فقط نوع (ws | tcp | grpc) را وارد کنید.");
            exit();
        }
        $stmt = $connection->prepare("UPDATE `server_plans` SET `type`=?,`step`=4 WHERE `active`=0");
        $stmt->bind_param("s", $text);
        $stmt->execute();
        $stmt->close();


        $msg = '🔻یه توضیح برای پلن مورد نظرت بنویس:';
        sendMessage($msg,$cancelKey); 
    }
    
    if($step==4 and $text!=$buttonValues['cancel']){
        
        if($userInfo['step'] == "addNewMarzbanPlan"){
            $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `active` = 0 AND `step` = 4");
            $stmt->execute();
            $serverId = $stmt->get_result()->fetch_assoc()['server_id'];
            $stmt->close();
        
            $hosts = getMarzbanHosts($serverId)->inbounds;
            $networkType = array();
            foreach($hosts as $key => $inbound){
                $networkType[] = [['text'=>$inbound->tag, 'callback_data'=>"planNetworkType{$inbound->protocol}*_*{$inbound->tag}"]];
            }
            $networkType = json_encode(['inline_keyboard'=>$networkType]);

            $stmt = $connection->prepare("UPDATE `server_plans` SET `descr`=?, `step` = 5 WHERE `step` = 4");
            sendMessage("لطفا نوع شبکه های این پلن را انتخاب کنید.",$networkType);
        }
        else{
            $stmt = $connection->prepare("UPDATE `server_plans` SET `descr`=?, `active`=1,`step`=10 WHERE `step`=4");
            $imgtxt = '☑️ پنل با موفقیت ثبت و ایجاد شد.';
            
            sendMessage($imgtxt,$removeKeyboard);
            sendMessage($mainValues['reached_main_menu'],getAdminKeys());
            setUser();
        }
        $stmt->bind_param("s", $text);
        $stmt->execute();
        $stmt->close();

    } 
    elseif($step == 5 and $text != $buttonValues['cancel'] && preg_match('/^planNetworkType(?<protocol>.+)\*_\*(?<tag>.*)/',$data,$match)){
        $saveBtn = "ذخیره ✅";
        if($markup[count($markup)-1][0]['text'] == $saveBtn) unset($markup[count($markup)-1]);

        foreach($markup as $key => $keyboard){
            if($keyboard[0]['callback_data'] == $data) $markup[$key][0]['text'] = $keyboard['0']['text'] == $match['tag'] . " ✅" ? $match['tag']:$match['tag'] . " ✅";
        }

        if(strstr(json_encode($markup,JSON_UNESCAPED_UNICODE), "✅") && !strstr(json_encode($markup,JSON_UNESCAPED_UNICODE), $saveBtn)){
            $markup[] = [['text'=>$saveBtn,'callback_data'=>"savePlanNetworkType"]];
        }
        $markup = json_encode(['inline_keyboard'=>array_values($markup)]);
        
        editKeys($markup);
    }
    elseif($step == 5 && $text != $buttonValues['cancel'] && $data == "savePlanNetworkType"){
        delMessage();
        $inbounds = array();
        $proxies = array();
        unset($markup[count($markup)-1]);

        foreach($markup as $key=>$value){
            $tag = trim(str_replace("✅", "", $value[0]['text'], $state));
            if($state > 0){
                preg_match('/^planNetworkType(?<protocol>.+)\*_\*(?<tag>.*)/',$value[0]['callback_data'],$info);
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
        $stmt = $connection->prepare("UPDATE `server_plans` SET `custom_sni`=?, `active`=1,`step`=10 WHERE `step`=5");
        $stmt->bind_param("s", $info);
        $stmt->execute();
        $stmt->close();
        
        $imgtxt = '☑️ پنل با موفقیت ثبت و ایجاد شد.';
        sendMessage($imgtxt,$removeKeyboard);
        sendMessage($mainValues['reached_main_menu'],getAdminKeys());
        setUser();
    }
}
if($data == 'backplan' and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `active`=1");
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    $keyboard = [];
    while($cat = $res->fetch_assoc()){
        $id = $cat['id'];
        $title = $cat['title'];
        $keyboard[] = ['text' => "$title", 'callback_data' => "plansList$id"];
    }
    $keyboard = array_chunk($keyboard,2);
    $keyboard[] = [['text'=>"➖➖➖",'callback_data'=>"wizwizch"]];
    $keyboard[] = [['text'=>'➕ افزودن پلن اختصاصی و اشتراکی','callback_data'=>"addNewPlan"]];
    $keyboard[] = [
        ['text'=>'➕ افزودن پلن رهگذر','callback_data'=>"addNewRahgozarPlan"],
        ['text'=>"➕ افزودن پلن مرزبان",'callback_data'=>"addNewMarzbanPlan"]
                    ];
    $keyboard[] = [['text'=>'➕ افزودن پلن حجمی','callback_data'=>"volumePlanSettings"],['text'=>'➕ افزودن پلن زمانی','callback_data'=>"dayPlanSettings"]];
    $keyboard[] = [['text' => "➕ افزودن پلن دلخواه", 'callback_data' => "editCustomPlan"]];
    $keyboard[] = [['text' => $buttonValues['back_button'], 'callback_data' => "managePanel"]];

    $msg = '☑️ مدیریت پلن ها:';
    
    if(isset($data) and $data=='backplan') {
        editText($message_id, $msg, json_encode(['inline_keyboard'=>$keyboard]));
    }else { sendAction('typing');
        sendmessage($msg, json_encode(['inline_keyboard'=>$keyboard]));
    }
    
    
    exit;
}
if(($data=="editCustomPlan" || preg_match('/^editCustom(gbPrice|dayPrice)/',$userInfo['step'],$match)) && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    if(!isset($data)){
        if(is_numeric($text)){
            setSettings($match[1], $text);
            sendMessage($mainValues['saved_successfuly'],$removeKeyboard); 
        }else{
            sendMessage("فقط عدد ارسال کن");
            exit();
        }
    }
    $gbPrice=number_format($botState['gbPrice']??0) . " تومان";
    $dayPrice=number_format($botState['dayPrice']??0) . " تومان";
    
    $keys = json_encode(['inline_keyboard'=>[
        [
            ['text'=>$gbPrice,'callback_data'=>"editCustomgbPrice"],
            ['text'=>"هزینه هر گیگ",'callback_data'=>"wizwizch"]
            ],
        [
            ['text'=>$dayPrice,'callback_data'=>"editCustomdayPrice"],
            ['text'=>"هزینه هر روز",'callback_data'=>"wizwizch"]
            ],
        [
            ['text'=>$buttonValues['back_button'],'callback_data'=>"backplan"]
            ]
            
        ]]);
    if(!isset($data)){
        sendMessage("تنظیمات پلن دلخواه",$keys);
        setUser();
    }else{
        editText($message_id,"تنظیمات پلن دلخواه",$keys);
    }
}
if(preg_match('/^editCustom(gbPrice|dayPrice)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    $title = $match[1] == "dayPrice"?"هر روز":"هر گیگ";
    sendMessage("لطفا هزینه " . $title . " را به تومان وارد کنید.",$cancelKey);
    setUser($data);
}
if(preg_match('/plansList(\d+)/', $data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `server_id`=? ORDER BY`id` ASC");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    if($res->num_rows==0){
        alert("متاسفانه، هیچ پلنی براش انتخاب نکردی 😑");
        exit;
    }else {
        $keyboard = [];
        while($cat = $res->fetch_assoc()){
            $id = $cat['id'];
            $title = $cat['title'];
            $keyboard[] = ['text' => "#$id $title", 'callback_data' => "planDetails$id"];
        }
        $keyboard = array_chunk($keyboard,2);
        $keyboard[] = [['text' => $buttonValues['back_button'], 'callback_data' => "backplan"],];
        $msg = ' ▫️ یه پلن رو انتخاب کن بریم برای ویرایش:';
        editText($message_id, $msg, json_encode(['inline_keyboard'=>$keyboard]), "HTML");
    }
    exit();
}
if(preg_match('/planDetails(\d+)/', $data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $keys = getPlanDetailsKeys($match[1]);
    if($keys == null){
        alert("موردی یافت نشد.");
        exit;
    }else editText($message_id, "ویرایش تنظیمات پلن:", $keys, "HTML");
}
if(preg_match('/^wizwizplanacclist(\d+)/',$data,$match) and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `status`=1 AND `fileid`=?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    if($res->num_rows == 0){
        alert('لیست خالی است.');
        exit;
    }
    $txt = '';
    while($order = $res->fetch_assoc()){
		$suid = $order['userid'];
		$stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid`=?");
        $stmt->bind_param("i", $suid);
        $stmt->execute();
        $ures = $stmt->get_result()->fetch_assoc();
        $stmt->close();


        $date = $order['date'];
        $remark = $order['remark'];
        $date = jdate('Y-m-d H:i', $date);
        $uname = $ures['name'];
        $sold = " 🚀 ".$uname. " ($date)";
        $accid = $order['id'];
        $orderLink = json_decode($order['link'],true);
        $txt = "$sold \n  ☑️ $remark ";
        foreach($orderLink as $link){
            $txt .= $botState['configLinkState'] != "off"?"<code>".$link."</code> \n":"";
        }
        $txt .= "\n ❗ $channelLock \n";
        sendMessage($txt, null, "HTML");
    }
}
if(preg_match('/^wizwizplandelete(\d+)/',$data,$match) and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("DELETE FROM `server_plans` WHERE `id`=?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $stmt->close();
    alert("پلن رو برات حذفش کردم ☹️☑️");
    
    editText($message_id,"لطفا یکی از کلید های زیر را انتخاب کنید.",getMainKeys());
}
if(preg_match('/^wizwizplanname(\d+)/',$data) and ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    setUser($data);
    delMessage();
    sendMessage("🔅 یه اسم برا پلن جدید انتخاب کن:",$cancelKey);exit;
}
if(preg_match('/^wizwizplanname(\d+)/',$userInfo['step'], $match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("UPDATE `server_plans` SET `title`=? WHERE `id`=?");
    $stmt->bind_param("si", $text, $match[1]);
    $stmt->execute();
    $stmt->close();

    sendMessage("☑️ با موفقیت برات تغییر دادم.");
    setUser();
    
    $keys = getPlanDetailsKeys($match[1]);
    if($keys == null){
        alert("موردی یافت نشد.");
        exit;
    }else sendMessage("ویرایش تنظیمات پلن:", $keys);
}
if(preg_match('/^wizwizplanslimit(\d+)/',$data) and ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    setUser($data);
    delMessage();
    sendMessage("🔅 ظرفیت جدید برای پلن انتخاب کن:",$cancelKey);exit;
}
if(preg_match('/^wizwizplanslimit(\d+)/',$userInfo['step'], $match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("UPDATE `server_plans` SET `acount`=? WHERE `id`=?");
    $stmt->bind_param("ii", $text, $match[1]);
    $stmt->execute();
    $stmt->close();

    sendMessage("☑️ با موفقیت برات تغییر دادم.");
    setUser();
    
    $keys = getPlanDetailsKeys($match[1]);
    if($keys == null){
        alert("موردی یافت نشد.");
        exit;
    }else sendMessage("ویرایش تنظیمات پلن:", $keys, "HTML");
}
if(preg_match('/^wizwizplansinobundid(\d+)/',$data) and ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    setUser($data);
    delMessage();
    sendMessage("🔅 سطر جدید برای پلن انتخاب کن:",$cancelKey);exit;
}
if(preg_match('/^wizwizplansinobundid(\d+)/',$userInfo['step'], $match) && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    $stmt = $connection->prepare("UPDATE `server_plans` SET `inbound_id`=? WHERE `id`=?");
    $stmt->bind_param("ii", $text, $match[1]);
    $stmt->execute();
    $stmt->close();

    sendMessage("☑️ با موفقیت برات تغییر دادم.");
    setUser();
    
    $keys = getPlanDetailsKeys($match[1]);
    if($keys == null){
        alert("موردی یافت نشد.");
        exit;
    }else sendMessage("ویرایش تنظیمات پلن:", $keys, "HTML");
}
if(preg_match('/^wizwizplaneditdes(\d+)/',$data) and ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    setUser($data);
    delMessage();
    sendMessage("🎯 توضیحاتت رو برام وارد کن:",$cancelKey);exit;
}
if(preg_match('/^wizwizplaneditdes(\d+)/',$userInfo['step'], $match) && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    $stmt = $connection->prepare("UPDATE `server_plans` SET `descr`=? WHERE `id`=?");
    $stmt->bind_param("si", $text, $match[1]);
    $stmt->execute();
    $stmt->close();


    sendMessage("☑️ با موفقیت برات تغییر دادم.");
    setUser();
    
    $keys = getPlanDetailsKeys($match[1]);
    if($keys == null){
        alert("موردی یافت نشد.");
        exit;
    }else sendMessage("ویرایش تنظیمات پلن:", $keys, "HTML");
}
if(preg_match('/^editDestName(\d+)/',$data) and ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    setUser($data);
    delMessage();
    sendMessage("🎯 dest رو برام وارد کن:\nبرای حذف کردن متن /empty رو وارد کن",$cancelKey);exit;
}
if(preg_match('/^editDestName(\d+)/',$userInfo['step'], $match) && ($from_id == $admin || $userInfo['isAdmin'] == true) &&  $text != $buttonValues['cancel']){
    if($text == "/empty"){
        $stmt = $connection->prepare("UPDATE `server_plans` SET `dest` = NULL WHERE `id`=?");
        $stmt->bind_param("i", $match[1]);
    }else{
        $stmt = $connection->prepare("UPDATE `server_plans` SET `dest`=? WHERE `id`=?");
        $stmt->bind_param("si", $text, $match[1]);
    }
    $stmt->execute();
    $stmt->close();


    sendMessage("☑️ با موفقیت برات تغییر دادم.");
    setUser();
    
    $keys = getPlanDetailsKeys($match[1]);
    if($keys == null){
        alert("موردی یافت نشد.");
        exit;
    }else sendMessage("ویرایش تنظیمات پلن:", $keys, "HTML");
}
if(preg_match('/^editSpiderX(\d+)/',$data) and ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    setUser($data);
    delMessage();
    sendMessage("🎯 spiderX رو برام وارد کن\nبرای حذف کردن متن /empty رو وارد کن",$cancelKey);exit;
}
if(preg_match('/^editSpiderX(\d+)/',$userInfo['step'], $match) && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    if($text == "/empty"){
        $stmt = $connection->prepare("UPDATE `server_plans` SET `spiderX`=NULL WHERE `id`=?");
        $stmt->bind_param("s", $match[1]);
    }else{
        $stmt = $connection->prepare("UPDATE `server_plans` SET `spiderX`=? WHERE `id`=?");
        $stmt->bind_param("si", $text, $match[1]);
    }
    $stmt->execute();
    $stmt->close();


    sendMessage("☑️ با موفقیت برات تغییر دادم.");
    setUser();
    
    $keys = getPlanDetailsKeys($match[1]);
    if($keys == null){
        alert("موردی یافت نشد.");
        exit;
    }else sendMessage("ویرایش تنظیمات پلن:", $keys, "HTML");
}
if(preg_match('/^editServerNames(\d+)/',$data) and ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    setUser($data);
    delMessage();
    sendMessage("🎯 serverNames رو به صورت زیر برام وارد کن:\n
`[
  \"yahoo.com\",
  \"www.yahoo.com\"
]`
    \n\nبرای حذف کردن متن /empty رو وارد کن",$cancelKey);exit;
}
if(preg_match('/^editServerNames(\d+)/',$userInfo['step'], $match) && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    if($text == "/empty"){
        $stmt = $connection->prepare("UPDATE `server_plans` SET `serverNames`=NULL WHERE `id`=?");
        $stmt->bind_param("s", $match[1]);
    }else{
        $stmt = $connection->prepare("UPDATE `server_plans` SET `serverNames`=? WHERE `id`=?");
        $stmt->bind_param("si", $text, $match[1]);
    }
    $stmt->execute();
    $stmt->close();


    sendMessage("☑️ با موفقیت برات تغییر دادم.");
    setUser();
    
    $keys = getPlanDetailsKeys($match[1]);
    if($keys == null){
        alert("موردی یافت نشد.");
        exit;
    }else sendMessage("ویرایش تنظیمات پلن:", $keys, "HTML");
}
if(preg_match('/^editFlow(\d+)/',$data, $match) and ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    setUser($data);
    delMessage();
    $keys = json_encode(['inline_keyboard'=>[
        [['text'=>"None", 'callback_data'=>"editPFlow" . $match[1] . "_None"]],
        [['text'=>"xtls-rprx-vision", 'callback_data'=>"editPFlow" . $match[1] . "_xtls-rprx-vision"]],
        ]]);
    sendMessage("🎯 لطفا یکی از موارد زیر رو انتخاب کن",$keys);exit;
}
if(preg_match('/^editPFlow(\d+)_(.*)/',$data, $match) && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    $stmt = $connection->prepare("UPDATE `server_plans` SET `flow`=? WHERE `id`=?");
    $stmt->bind_param("si", $match[2], $match[1]);
    $stmt->execute();
    $stmt->close();

    alert("☑️ با موفقیت برات تغییر دادم.");
    setUser();
    
    $keys = getPlanDetailsKeys($match[1]);
    editText($message_id, "ویرایش تنظیمات پلن:", $keys, "HTML");
}
if(preg_match('/^wizwizplanrial(\d+)/',$data) and ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    setUser($data);
    delMessage();
    sendMessage("🎯 لطفا قیمت جدید را وارد کنید:",$cancelKey);exit;
}
if(preg_match('/^wizwizplanrial(\d+)/',$userInfo['step'], $match) && ($from_id == $admin || $userInfo['isAdmin'] == true)&& $text != $buttonValues['cancel']){
    if(is_numeric($text)){
        $stmt = $connection->prepare("UPDATE `server_plans` SET `price`=? WHERE `id`=?");
        $stmt->bind_param("ii", $text, $match[1]);
        $stmt->execute();
        $stmt->close();

        sendMessage("☑️ با موفقیت برات تغییر دادم.");
        setUser();
        
        $keys = getPlanDetailsKeys($match[1]);
        if($keys == null){
            alert("موردی یافت نشد.");
            exit;
        }else sendMessage("ویرایش تنظیمات پلن:", $keys, "HTML");
    }else{
        sendMessage("لطفا قیمت را به صورت عددی وارد کنید.");
    }
}

// ✅ تابع کمکی کیبورد لغو برای حالت "یافت نشد"
function cancelSearchKeyboard() {
    return json_encode([
        'inline_keyboard' => [
            [
                ['text' => '❌ لغو عملیات', 'callback_data' => 'cancelSearchConfig']
            ]
        ]
    ]);
}

// ✅ هندلر دکمه لغو عملیات برای سرچ
if ($data == "cancelSearchConfig") {
    delMessage();
    setUser();
    sendMessage($mainValues['reached_main_menu'], getMainKeys());
    exit;
}

if (
    $data == 'mySubscriptions' ||
    $data == 'agentConfigsList' ||
    preg_match('/(changeAgentOrder|changeOrdersPage)(\d+)/', $data, $match)
) {
    $results_per_page = 50;
    $action = $match[1] ?? null;
    $page = $match[2] ?? 1;

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
        alert($mainValues['you_dont_have_config']);
        exit;
    }

    $keyboard = [];
    while ($cat = $orders->fetch_assoc()) {
        $id = $cat['id'];
        $remark = $cat['remark'];
        $keyboard[] = ['text' => $remark, 'callback_data' => "orderDetails$id"];
    }

    $keyboard = array_chunk($keyboard, 1);

    $prev = $page - 1;
    $next = $page + 1;

    $isAgentConfig = ($data == "agentConfigsList" || $action == "changeAgentOrder");

    $buttons = [];
    if ($prev > 0) {
        $buttons[] = [
            'text' => "◀",
            'callback_data' => $isAgentConfig ? "changeAgentOrder$prev" : "changeOrdersPage$prev"
        ];
    }

    if ($page < $number_of_page) {
        $buttons[] = [
            'text' => "➡",
            'callback_data' => $isAgentConfig ? "changeAgentOrder$next" : "changeOrdersPage$next"
        ];
    }

    if (!empty($buttons)) {
        $keyboard[] = $buttons;
    }

    if ($isAgentConfig) {
        $keyboard[] = [[
            'text' => $buttonValues['search_agent_config'],
            'callback_data' => "searchAgentConfig"
        ]];
    }
    // else {
    //     $keyboard[] = [[
    //         'text' => $buttonValues['search_agent_config'],
    //         'callback_data' => "searchMyConfig"
    //     ]];
    // }

    $keyboard[] = [[
        'text' => $buttonValues['back_to_main'],
        'callback_data' => "mainMenu"
    ]];

    if (isset($data)) {
        editText(
            $message_id,
            $mainValues['select_one_to_show_detail'],
            json_encode(['inline_keyboard' => $keyboard])
        );
    } else {
        sendAction('typing');
        sendMessage(
            $mainValues['select_one_to_show_detail'],
            json_encode(['inline_keyboard' => $keyboard])
        );
    }

    exit;
}

if($data=="searchAgentConfig" || $data == "searchMyConfig" || $data=="searchUsersConfig"){
    //delMessage();
    sendMessage($mainValues['send_config_remark'],$cancelKey);
    setUser($data);
}


if(($userInfo['step'] == "searchAgentConfig" || $userInfo['step'] == "searchMyConfig") && $text != $buttonValues['cancel']){
    sendMessage($mainValues['please_wait_message'], $removeKeyboard);

    if($userInfo['step'] == "searchMyConfig") $condition = "AND `agent_bought` = 0";
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `userid` = ? AND `remark` LIKE CONCAT('%', ?, '%') $condition");
    $stmt->bind_param("is", $from_id, $text);
    $stmt->execute();
    $orderId = $stmt->get_result()->fetch_assoc()['id'];
    $stmt->close();
    
    $keys = getOrderDetailKeys($from_id, $orderId);

    if($keys == null) sendMessage($mainValues['no_order_found'], cancelSearchKeyboard()); 
    else {
        sendMessage($keys['msg'], $keys['keyboard'], "HTML");
        setUser();
    }
}

if(($userInfo['step'] == "searchUsersConfig" && $text != $buttonValues['cancel']) || preg_match('/^userOrderDetails(\d+)_(\d+)/',$data,$match)){
    if(isset($data)){
        $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
        $stmt->bind_param("i", $match[1]);
    }
    else{
        sendMessage($mainValues['please_wait_message'], $removeKeyboard); 
        $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `remark` LIKE CONCAT('%', ?, '%')");
        $stmt->bind_param("s", $text);
    }

    $stmt->execute();
    $orderInfo = $stmt->get_result();
    $stmt->close();

    if($orderInfo->num_rows == 0) sendMessage($mainValues['no_order_found'], cancelSearchKeyboard()); 
    else {
        $orderId = $orderInfo->fetch_assoc()['id'];
        $keys = getUserOrderDetailKeys($orderId, isset($data)?$match[2]:0);

        if($keys == null) sendMessage($mainValues['no_order_found'], cancelSearchKeyboard()); 
        else{
            if(!isset($data)) sendMessage($keys['msg'], $keys['keyboard'], "HTML");
            else editText($message_id, $keys['msg'], $keys['keyboard'], "HTML");
            setUser();
        }
    }
}

if(preg_match('/^orderDetails(\d+)(_|)(?<offset>\d+|)/', $data, $match)){
    $keys = getOrderDetailKeys(
        $from_id,
        $match[1],
        !empty($match['offset']) ? $match['offset'] : 0
    );

    if ($keys == null) {
        alert($mainValues['no_order_found'], true);
        exit;
    } else {
        editText($message_id, $keys['msg'], $keys['keyboard'], "HTML");
        exit;
    }
}

if($data=="cantEditGrpc"){
    alert("نمی توانید نوع این اشتراک را تغییر دهید.");
    exit();
}
if(preg_match('/^changeCustomPort(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage("لطفا پورت مورد نظر خود را وارد کنید.\nبرای حذف پورت دلخواه عدد 0 را وارد کنید.", $cancelKey);
    setUser($data);
}
if(preg_match('/^changeCustomPort(\d+)/',$userInfo['step'],$match) && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    if(is_numeric($text)){
        $stmt = $connection->prepare("UPDATE `server_plans` SET `custom_port`= ? WHERE `id` = ?");
        $stmt->bind_param("ii", $text, $match[1]);
        $stmt->execute();
        $stmt->close();  
        sendMessage($mainValues['saved_successfuly'],$removeKeyboard);
         
        sendMessage("ویرایش تنظیمات پلن:", getPlanDetailsKeys($match[1]));
        setUser();
    }else sendMessage($mainValues['send_only_number']);
}
if(preg_match('/^changeCustomSni(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage("لطفا sni مورد نظر خود را وارد کنید.\nبرای حذف متن /empty را وارد کنید.", $cancelKey);
    setUser($data);
}
if(preg_match('/^changeCustomSni(\d+)/',$userInfo['step'],$match) && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    if($text == "/empty"){
        $stmt = $connection->prepare("UPDATE `server_plans` SET `custom_sni`= NULL WHERE `id` = ?");
        $stmt->bind_param("i", $match[1]);
    }
    else {
        $stmt = $connection->prepare("UPDATE `server_plans` SET `custom_sni`= ? WHERE `id` = ?");
        $stmt->bind_param("si", $text, $match[1]);
    }
    $stmt->execute();
    $stmt->close();  
    sendMessage($mainValues['saved_successfuly'],$removeKeyboard);
     
    sendMessage("ویرایش تنظیمات پلن:", getPlanDetailsKeys($match[1]));
    setUser();
}
if(preg_match('/^changeCustomPath(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("UPDATE `server_plans` SET `custom_path` = IF(`custom_path` = 1, 0, 1) WHERE `id` = ?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $stmt->close();
    
    editKeys(getPlanDetailsKeys($match[1]));
}
if(preg_match('/changeNetworkType(\d+)_(\d+)/', $data, $match)){
    $fid = $match[1];
    $oid = $match[2];
    
	$stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=? AND `active`=1"); 
    $stmt->bind_param("i", $fid);
    $stmt->execute();
    $respd = $stmt->get_result();
    $stmt->close();


	if($respd){
		$respd = $respd->fetch_assoc(); 
		$stmt = $connection->prepare("SELECT * FROM `server_categories` WHERE `id`=?");
        $stmt->bind_param("i", $respd['catid']);
        $stmt->execute();
        $cadquery = $stmt->get_result();
        $stmt->close();


		if($cadquery) {
			$catname = $cadquery->fetch_assoc()['title'];
			$name = $catname." ".$respd['title'];
		}else $name = "$oid";
		
	}else $name = "$oid";

    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id`=?");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();


    $date = jdate("Y-m-d H:i",$order['date']);
    $expire_date = jdate("Y-m-d H:i",$order['expire_date']);
    $remark = $order['remark'];
    $uuid = $order['uuid']??"0";
    $acc_link = $order['link'];
    $protocol = $order['protocol'];
    $server_id = $order['server_id'];
    $price = $order['amount'];
    
    $response = getJson($server_id)->obj;
    foreach($response as $row){
        $clients = json_decode($row->settings)->clients;
        if($clients[0]->id == $uuid || $clients[0]->password == $uuid) {
            $total = $row->total;
            $up = $row->up;
            $down = $row->down;
            $port = $row->port;
            $netType = json_decode($row->streamSettings)->network; 
            $security = json_decode($row->streamSettings)->security;
            $netType = ($netType == 'tcp') ? 'ws' : 'tcp';
        break;
        }
    }

    if($protocol == 'trojan') $netType = 'tcp';

    $update_response = editInbound($server_id, $uuid, $uuid, $protocol, $netType);
    $vraylink = getConnectionLink($server_id, $uuid, $protocol, $remark, $port, $netType);

    $vray_link = json_encode($vraylink);
    $stmt = $connection->prepare("UPDATE `orders_list` SET `protocol`=?,`link`=? WHERE `id`=?");
    $stmt->bind_param("ssi", $protocol, $vray_link, $oid);
    $stmt->execute();
    $stmt->close();
    
    $keys = getOrderDetailKeys($from_id, $oid);
    editText($message_id, $keys['msg'], $keys['keyboard'], "HTML");
}
if($data=="changeProtocolIsDisable"){
    alert("تغییر پروتکل غیر فعال است.");
}

if(preg_match('/updateConfigConnectionLink(\d+)/', $data,$match)){
    alert($mainValues['please_wait_message']);
    $oid = $match[1];

    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id`=?");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $remark = $order['remark'];
    $uuid = $order['uuid']??"0";
    $inboundId = $order['inbound_id'];
    $server_id = $order['server_id'];
    $file_id = $order['fileid'];
    
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
    $stmt->bind_param("i", $file_id);
    $stmt->execute();
    $file_detail = $stmt->get_result()->fetch_assoc();

    $rahgozar = $order['rahgozar'];
    $customPath = $file_detail['custom_path'];
    $customPort = $file_detail['custom_port'];
    $customSni = $file_detail['custom_sni'];
    
    $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id`=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_config = $stmt->get_result()->fetch_assoc();
    $serverType = $server_config['type'];
    $netType = $file_detail['type'];
    $protocol = $file_detail['protocol'];
    $security = $server_config['security'];
    
    $success = false; // Flag برای موفقیت آمیز بودن عملیات
    
    if($serverType == "marzban"){
        $info = getMarzbanUser($server_id, $remark);
        if(isset($info->links) && !empty($info->links)) {
            $vraylink = $info->links;
            $success = true;
        } else {
            $vraylink = "خطا در دریافت لینک";
        }
    } else {
        $response = getJson($server_id)->obj;
        if($inboundId == 0){
            foreach($response as $row){
                $clients = json_decode($row->settings)->clients;
                if($clients[0]->id == $uuid || $clients[0]->password == $uuid) {
                    $inboundRemark = $row->remark;
                    $iId = $row->id;
                    $port = $row->port;
                    $protocol = $row->protocol;
                    $netType = json_decode($row->streamSettings)->network;
                    break;
                }
            }
        } else {
            foreach($response as $row){
                if($row->id == $inboundId) {
                    $iId = $row->id;
                    $inboundRemark = $row->remark;
                    $port = $row->port;
                    $protocol = $row->protocol;
                    $netType = json_decode($row->streamSettings)->network;
                    break;
                }
            }
        }
    
        if($botState['updateConnectionState'] == "robot"){
            $updateResult = updateConfig($server_id, $iId, $protocol, $netType, $security, $rahgozar);
            if($updateResult) {
                $success = true;
            }
        }
        
        $vraylink = getConnectionLink($server_id, $uuid, $protocol, $remark, $port, $netType, $inboundId, $rahgozar, $customPath, $customPort, $customSni);
        if(!empty($vraylink) && $vraylink != "خطا") {
            $success = true;
        }
    }
    
    if($success) {
        // ذخیره لینک در دیتابیس
        $vray_link = json_encode($vraylink);
        $stmt = $connection->prepare("UPDATE `orders_list` SET `link`=? WHERE `id`=?");
        $stmt->bind_param("si", $vray_link, $oid);
        $stmt->execute();
        $stmt->close();
        
        // ارسال پیام موفقیت با HTML
        $successMessage = "<b>✅ بروزرسانی موفق بود!</b>\n\n"
            . "⭕️ دقت کنید باید لینک اشتراک را دوباره وارد برنامه کنید یا داخل برنامه نیز بروزرسانی بزنید.\n\n"
            . "🔄 <b>در صورتی که کانفیگ ها با لینک اشتراک اصلی یا کمکی برای شما لود نمی شوند، لطفاً مراحل زیر را انجام دهید:</b>\n\n"
            . "1. کانفیگ های قدیمی را از نرم‌افزار خود حذف کنید.\n"
            . "2. کانفیگ های جدید را از قسمت بالا کپی کنید.\n"
            . "3. کانفیگ ها را به نرم‌افزار اضافه کنید.\n\n"
            . "📱 <i>برای اتصال موفق، حتماً از آخرین نسخه نرم‌افزار استفاده نمایید.</i>\n"
			. "⚠️ <b>بعد اتصال به کمک کانفیگ های تکی، برای اینکه همه کانفیگ ها را داشته باشید حتما دوباره با لینک اشتراک وارد کنید.</b>";
        
        // استفاده از parse_mode: "HTML" برای نمایش متن بولد شده
        sendMessage($successMessage, null, "HTML");
        
    } else {
        // ارسال پیام خطا
        $errorMessage = "<b>❌ خطا در بروزرسانی</b>\n\n"
            . "متأسفانه عملیات بروزرسانی با مشکل مواجه شد.\n"
            . "لطفاً مجدداً تلاش کنید یا با پشتیبانی تماس بگیرید.";
        
        sendMessage($errorMessage, null, "HTML");
    }
    
    $keys = getOrderDetailKeys($from_id, $oid);
    editText($message_id, $keys['msg'], $keys['keyboard'], "HTML");
}

if(preg_match('/changAccountConnectionLink(\d+)/', $data,$match)){ 
    alert($mainValues['please_wait_message']); 
    $oid = $match[1]; 
 
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id`=?"); 
    $stmt->bind_param("i", $oid); 
    $stmt->execute(); 
    $order = $stmt->get_result()->fetch_assoc(); 
    $stmt->close(); 
 
    $date = jdate("Y-m-d H:i",$order['date']); 
    $expire_date = jdate("Y-m-d H:i",$order['expire_date']); 
    $remark = $order['remark']; 
    $uuid = $order['uuid']??"0"; 
    $inboundId = $order['inbound_id']; 
    $acc_link = $order['link']; 
    $server_id = $order['server_id']; 
    $rahgozar = $order['rahgozar']; 
     
    $file_id = $order['fileid']; 
     
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?"); 
    $stmt->bind_param("i", $file_id); 
    $stmt->execute(); 
    $file_detail = $stmt->get_result()->fetch_assoc(); 
    $customPath = $file_detail['custom_path']; 
    $customPort = $file_detail['custom_port']; 
    $customSni = $file_detail['custom_sni']; 
    $stmt->close();
     
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?"); 
    $stmt->bind_param("i", $server_id); 
    $stmt->execute(); 
    $server_info = $stmt->get_result()->fetch_assoc(); 
    $stmt->close(); 
     
    $serverType = $server_info['type']; 
 
    if($serverType == "marzban"){ 
        $res = renewMarzbanUUID($server_id, $remark); 
        $vraylink = $res->links; 
        $newUuid = $newToken = str_replace("/sub/", "", $res->subscription_url); 
    }else{ 
        $response = getJson($server_id)->obj; 
        if($inboundId == 0){ 
            foreach($response as $row){ 
                $clients = json_decode($row->settings)->clients; 
                if($clients[0]->id == $uuid || $clients[0]->password == $uuid) { 
                    $port = $row->port; 
                    $protocol = $row->protocol; 
                    $netType = json_decode($row->streamSettings)->network; 
                    break; 
                } 
            } 
             
            $update_response = renewInboundUuid($server_id, $uuid); 
        }else{ 
            foreach($response as $row){ 
                if($row->id == $inboundId) { 
                    $port = $row->port;  
                    $protocol = $row->protocol; 
                    $netType = json_decode($row->streamSettings)->network; 
                    break; 
                } 
            } 
            $update_response = renewClientUuid($server_id, $inboundId, $uuid); 
        } 
        $newUuid = $update_response->newUuid; 
        $vraylink = getConnectionLink($server_id, $newUuid, $protocol, $remark, $port, $netType, $inboundId, $rahgozar, $customPath, $customPort, $customSni); 
        $newToken = RandomString(30); 
    } 
 
    $vray_link = json_encode($vraylink); 
    $stmt = $connection->prepare("UPDATE `orders_list` SET `link`=?, `uuid` = ?, `token` = ? WHERE `id`=?"); 
    $stmt->bind_param("sssi", $vray_link, $newUuid, $newToken, $oid); 
    $stmt->execute(); 
    $stmt->close(); 

    // ✅ ارسال پیام به کاربر بعد از تغییر لینک
    $successMessage = "<b>✅ لینک اشتراک شما با موفقیت تغییر کرد!</b>\n\n"
        . "♻️ لینک اشتراک و کانفیگ‌های شما مجدد ساخته شد.\n\n"
        . "🔄 <b>برای استفاده از لینک جدید این مراحل را انجام دهید:</b>\n\n"
        . "1) لینک اشتراک قبلی و کانفیگ‌های قبلی را از نرم‌افزار کامل حذف کنید.\n"
        . "2) لینک اشتراک جدید را از پیام بالا که شامل اطلاعات اشتراک هست کپی کنید.\n"
        . "3) لینک را وارد نرم افزار کنید تا کانفیگ ها لود شوند اگر لینک اصلی کار نکرد از لینک کمکی استفاده کنید.\n\n"
        . "📱 <i>پیشنهاد می کنیم از نرم‌افزار Karing استفاده کنید.</i>\n"
        . "⚠️ <b>اگر می‌خواهید همه کانفیگ‌ها را تک تک داشته باشید، بزنید روی دکمه کیوآر کانفیگ تا تک تک ارسال شود.</b>";

    sendMessage($successMessage, null, "HTML"); 
 
    $keys = getOrderDetailKeys($from_id, $oid); 
    editText($message_id, $keys['msg'], $keys['keyboard'],"HTML"); 
}

if(preg_match('/changeUserConfigState(\d+)/', $data,$match)){
    alert($mainValues['please_wait_message']);
    $oid = $match[1];

    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id`=?");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $userId = $order['userid'];
    $uuid = $order['uuid']??"0";
    $inboundId = $order['inbound_id'];
    $server_id = $order['server_id'];
    $remark = $order['remark'];
    
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $serverType = $server_info['type'];

    
    if($inboundId == 0){
        if($serverType == "marzban") $update_response = changeMarzbanState($server_id, $remark);
        else $update_response = changeInboundState($server_id, $uuid);
    }else{
        $update_response = changeClientState($server_id, $inboundId, $uuid);
    }
    
    if($update_response->success){
        alert($mainValues['please_wait_message']);
    
        $keys = getUserOrderDetailKeys($oid);
        editText($message_id, $keys['msg'], $keys['keyboard'], "HTML");
    }else sendMessage("عملیات مورد نظر با مشکل روبرو شد.\n" . $update_response->msg);
}

if (preg_match('/agentChangeUserConfigState(\d+)/', $data, $match)) {
    alert($mainValues['please_wait_message']);

    $oid = $match[1];

    // گرفتن اطلاعات سفارش
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id`=?");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$order) {
        sendMessage("سفارش مورد نظر یافت نشد.");
        return;
    }

    $uuid = $order['uuid'] ?? "0";
    $inboundId = $order['inbound_id'];
    $server_id = $order['server_id'];
    $remark = $order['remark'];

    // گرفتن اطلاعات سرور
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$server_info) {
        sendMessage("اطلاعات سرور یافت نشد.");
        return;
    }

    $serverType = $server_info['type'];

    // تغییر وضعیت کانفیگ
    if ($inboundId == 0) {
        if ($serverType == "marzban") {
            $update_response = changeMarzbanState($server_id, $remark);
        } else {
            $update_response = changeInboundState($server_id, $uuid);
        }
    } else {
        $update_response = changeClientState($server_id, $inboundId, $uuid);
    }

    // بروزرسانی موفق بود
    if ($update_response->success) {
        alert($mainValues['please_wait_message']);

        $keys = getOrderDetailKeys($order['userid'], $oid);

        editText($message_id, $keys['msg'], $keys['keyboard'], "HTML");

    } else {
        sendMessage("عملیات مورد نظر با مشکل روبرو شد.\n" . $update_response->msg);
    }
}

if(preg_match('/changeAccProtocol(\d+)_(\d+)_(.*)/', $data,$match)){
    $fid = $match[1];
    $oid = $match[2];
    $protocol = $match[3];

	$stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=? AND `active`=1"); 
    $stmt->bind_param("i", $fid);
    $stmt->execute();
    $respd = $stmt->get_result();
    $stmt->close();


	if($respd){
		$respd = $respd->fetch_assoc(); 
		$stmt= $connection->prepare("SELECT * FROM `server_categories` WHERE `id`=?");
        $stmt->bind_param("i", $respd['catid']);
        $stmt->execute();
        $cadquery = $stmt->get_result();
        $stmt->close();


		if($cadquery) {
			$catname = $cadquery->fetch_assoc()['title'];
			$name = $catname." ".$respd['title'];
		}else $name = "$id";
		
	}else $name = "$id";

    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id`=?");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();


    $date = jdate("Y-m-d H:i",$order['date']);
    $expire_date = jdate("Y-m-d H:i",$order['expire_date']);
    $remark = $order['remark'];
    $uuid = $order['uuid']??"0";
    $acc_link = $order['link'];
    $server_id = $order['server_id'];
    $price = $order['amount'];
    $rahgozar = $order['rahgozar'];
    $file_id = $order['fileid'];
    
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
    $stmt->bind_param("i", $file_id);
    $stmt->execute();
    $file_detail = $stmt->get_result()->fetch_assoc();
    $customPath = $file_detail['custom_path'];
    $customPort = $file_detail['custom_port'];
    $customSni = $file_detail['custom_sni'];
    
    $response = getJson($server_id)->obj;
    foreach($response as $row){
        $clients = json_decode($row->settings)->clients;
        if($clients[0]->id == $uuid || $clients[0]->password == $uuid) {
            $total = $row->total;
            $up = $row->up;
            $down = $row->down;
            $port = $row->port;
            $netType = json_decode($row->streamSettings)->network;
            $security = json_decode($row->streamSettings)->security;
            break;
        }
    }
    if($protocol == 'trojan') $netType = 'tcp';
    $uniqid = generateRandomString(42,$protocol); 
    $leftgb = round( ($total - $up - $down) / 1073741824, 2) . " GB"; 
    $update_response = editInbound($server_id, $uniqid, $uuid, $protocol, $netType, $security, $rahgozar);
    $vraylink = getConnectionLink($server_id, $uniqid, $protocol, $remark, $port, $netType, 0, $rahgozar, $customPath, $customPort, $customSni);
    
    $vray_link = json_encode($vraylink);
    $stmt = $connection->prepare("UPDATE `orders_list` SET `protocol`=?,`link`=?, `uuid` = ? WHERE `id`=?");
    $stmt->bind_param("sssi", $protocol, $vray_link, $uniqid, $oid);
    $stmt->execute();
    $stmt->close();
    $keys = getOrderDetailKeys($from_id, $oid);
    editText($message_id, $keys['msg'], $keys['keyboard'],"HTML");
}

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
}

if(preg_match('/switchLocation(.+)_(.+)_(.+)_(.+)/', $data,$match)){
    $order_id = $match[1];
    $server_id = $match[2];
    $leftgp = $match[3];
    $expire = $match[4]; 
    /*if($expire < time() or $leftgp <= 0) {
        alert("سرویس شما غیرفعال است. لطفا ابتدا آن را تمدید کنید.",true);exit;
    }*/
    $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `active` = 1 and `state` = 1 and ucount > 0 AND `id` != ?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $respd = $stmt->get_result();
    $stmt->close();
    if($respd->num_rows == 0){
        alert('در حال حاضر هیچ سرور فعالی برای تغییر سرویس وجود ندارد.',true);
        exit;
    }
    $keyboard = [];
    while($cat = $respd->fetch_assoc()){
        $sid = $cat['id'];
        $name = $cat['title'];
        $keyboard[] = ['text' => "$name", 'callback_data' => "switchServer{$sid}_{$order_id}"];
    }
    $keyboard = array_chunk($keyboard,2);
    $keyboard[] = [['text' => $buttonValues['back_button'], 'callback_data' => "mainMenu"]];
    editText($message_id, ' 📍 لطفا برای تغییر سرویس فعلی، یکی از سرورها را انتخاب کنید👇',json_encode([
            'inline_keyboard' => $keyboard
        ]));
}
if(preg_match('/switchLocation(.+)_(.+)_(.+)_(.+)/', $data,$match)){
    $order_id = $match[1];
    $server_id = $match[2];
    $leftgp = $match[3];
    $expire = $match[4]; 
    /*if($expire < time() or $leftgp <= 0) {
        alert("سرویس شما غیرفعال است. لطفا ابتدا آن را تمدید کنید.",true);exit;
    }*/
    $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `active` = 1 and `state` = 1 and ucount > 0 AND `id` != ?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $respd = $stmt->get_result();
    $stmt->close();
    if($respd->num_rows == 0){
        alert('در حال حاضر هیچ سرور فعالی برای تغییر لوکیشن وجود ندارد.',true);
        exit;
    }
    $keyboard = [];
    while($cat = $respd->fetch_assoc()){
        $sid = $cat['id'];
        $name = $cat['title'];
        $keyboard[] = ['text' => "$name", 'callback_data' => "switchServer{$sid}_{$order_id}"];
    }
    $keyboard = array_chunk($keyboard,2);
    $keyboard[] = [['text' => $buttonValues['back_button'], 'callback_data' => "mainMenu"]];
    editText($message_id, ' 📍 لطفا برای تغییر لوکیشن سرویس فعلی، یکی از سرورها را انتخاب کنید👇',json_encode([
            'inline_keyboard' => $keyboard
        ]));
}

if(preg_match('/switchServer(.+)_(.+)/', $data, $match)){
    $sid = $match[1];  // ID جدید سرور
    $oid = $match[2];  // ID سفارش

    // گرفتن اطلاعات سفارش
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // گرفتن آی‌دی یوزر و ریمارک
    $user_id = $order['userid'];
    $remark = $order['remark'];

    // فقط تغییر آی‌دی سرور در جدول سفارش
    $stmt = $connection->prepare("UPDATE `orders_list` SET `server_id` = ? WHERE `id` = ?");
    $stmt->bind_param("ii", $sid, $oid);
    $stmt->execute();
    $stmt->close();

    // در صورتی که آی‌دی جدید سرور به درستی ذخیره شد
    $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id` = ?");
    $stmt->bind_param("i", $sid);
    $stmt->execute();
    $server_title = $stmt->get_result()->fetch_assoc()['title'];
    $stmt->close();

    // ارسال پیام تایید تغییرات
    $msg = " 📍لوکیشن سرویس $remark به سرور جدید با نام $server_title تغییر یافت.\n";
    $adminMessage = "
🌐 تغییر نوع سرویس توسط ادمین

🔐 شناسه عددی: $user_id
🎈 نام کاربری اشتراک: $remark
🔄 سرور جدید: $server_title
";
    sendMessage($adminMessage, null, "html", $admin);
    sendToAllAdmins('sendMessage', ['text' => $adminMessage,'parse_mode' => "html"]);
    editText($message_id, $msg);
    exit();
}
/*
if(preg_match('/switchServer(.+)_(.+)/',$data,$match)){
    $sid = $match[1];
    $oid = $match[2];
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $inbound_id = $order['inbound_id'];
    $server_id = $order['server_id'];
    $remark = $order['remark'];
    $uuid = $order['uuid']??"0";
    $fid = $order['fileid'];
    $protocol = $order['protocol'];
	$link = json_decode($order['link'])[0];
	
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
    $stmt->bind_param("i", $fid); 
    $stmt->execute();
    $file_detail = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $flow = $file_detail['flow'] == "None"?"":$file_detail['flow'];
	
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $reality = $server_info['reality'];
    $serverType = $server_info['type'];
    $panelUrl = $server_info['panel_url'];

    $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
    $stmt->bind_param("i", $sid);
    $stmt->execute();
    $srv_remark = $stmt->get_result()->fetch_assoc()['remark'];


    if($botState['remark'] == "digits"){
        $rnd = rand(10000,99999);
        $newRemark = "{$srv_remark}-{$rnd}";
    }else{
        $rnd = rand(1111,99999);
        $newRemark = "{$srv_remark}-{$from_id}-{$rnd}";
    }
	
    if(preg_match('/vmess/',$link)){
        $link_info = json_decode(base64_decode(str_replace('vmess://','',$link)));
        $uniqid = $link_info->id;
        $port = $link_info->port;
        $netType = $link_info->net;
    }else{
        $link_info = parse_url($link);
        $panel_ip = $link_info['host'];
        $uniqid = $link_info['user'];
        $protocol = $link_info['scheme'];
        $port = $link_info['port'];
        $netType = explode('type=',$link_info['query'])[1]; 
        $netType = explode('&',$netType)[0];
    }

    if($inbound_id > 0) {
        $remove_response = deleteClient($server_id, $inbound_id, $uuid);
		if(is_null($remove_response)){
			alert('🔻اتصال به سرور برقرار نیست. لطفا به مدیریت اطلاع بدید',true);
			exit;
		}
        if($remove_response){
            $total = $remove_response['total'];
            $up = $remove_response['up'];
            $down = $remove_response['down'];
			$id_label = $protocol == 'trojan' ? 'password' : 'id';
			if($serverType == "sanaei" || $serverType == "alireza"){
			    if($reality == "true"){
                    $newArr = [
                      "$id_label" => $uniqid,
                      "email" => $newRemark,
                      "enable" => true,
                      "flow" => $flow,
                      "limitIp" => $remove_response['limitIp'],
                      "totalGB" => $total - $up - $down,
                      "expiryTime" => $remove_response['expiryTime'],
                      "subId" => RandomString(16)
                    ];			        
			    }else{
                    $newArr = [
                      "$id_label" => $uniqid,
                      "email" => $newRemark,
                      "enable" => true,
                      "limitIp" => $remove_response['limitIp'],
                      "totalGB" => $total - $up - $down,
                      "expiryTime" => $remove_response['expiryTime'],
                      "subId" => RandomString(16)
                    ];
			    }
			}else{
                $newArr = [
                  "$id_label" => $uniqid,
                  "flow" => $remove_response['flow'],
                  "email" => $newRremark,
                  "limitIp" => $remove_response['limitIp'],
                  "totalGB" => $total - $up - $down,
                  "expiryTime" => $remove_response['expiryTime']
                ];
			}
            
            $response = addInboundAccount($sid, '', $inbound_id, 1, $newRemark, 0, 1, $newArr); 
            if(is_null($response)){
                alert('🔻اتصال به سرور برقرار نیست. لطفا به مدیریت اطلاع بدید',true);
                exit;
            }
			if($response == "inbound not Found"){
                alert("🔻سطر (inbound) با آیدی $inbound_id در این سرور یافت نشد. لطفا به مدیریت اطلاع بدید",true);
                exit;
            }
			if(!$response->success){
				alert('🔻خطا در ساخت اشتراک، لطفا به مدیریت اطلاع بدید',true);
				exit;
			}
			$vray_link = getConnectionLink($sid, $uniqid, $protocol, $newRemark, $port, $netType, $inbound_id);
			deleteClient($server_id, $inbound_id, $uuid, 1);
        }
    }else{
        $response = deleteInbound($server_id, $uuid);
		if(is_null($response)){
			alert('🔻اتصال به سرور برقرار نیست. لطفا به مدیریت اطلاع بدید',true);
			exit;
		}
        if($response){
            if($serverType == "marzban"){
                $response = addMarzbanUser($server_id, $newRemark, $volume, $days, $fid);
                if(!$response->success){
                    if($response->msg == "User already exists"){
                        $newRemark .= rand(1111,99999);
                        $response = addMarzbanUser($server_id, $newRemark, $volume, $days, $fid);
                    }
                }
                $uniqid = $token = str_replace("/sub/", "", $response->sub_link);
                $subLink = $botState['subLinkState'] == "on"?$panelUrl . $response->sub_link:"";
                $vraylink = $response->vray_links;

                $stmt = $connection->prepare("UPDATE `orders_list` SET `token` = ?, `uuid` =? WHERE `id` = ?");
                $stmt->bind_param("ssi", $token, $uniqid, $oid);
                $stmt->execute();
                $stmt->close();

            }else{
                $res = addUser($sid, $response['uniqid'], $response['protocol'], $response['port'], $response['expiryTime'], $newRemark, $response['volume'] / 1073741824, $response['netType'], $response['security']);
                $vray_link = getConnectionLink($sid, $response['uniqid'], $response['protocol'], $newRemark, $response['port'], $response['netType'], $inbound_id);
            }
            deleteInbound($server_id, $uuid, 1);
        }
    }
    $stmt = $connection->prepare("UPDATE `server_info` SET `ucount` = `ucount` + 1 WHERE `id` = ?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $stmt->close();

    $stmt = $connection->prepare("UPDATE `server_info` SET `ucount` = `ucount` - 1 WHERE `id` = ?");
    $stmt->bind_param("i", $sid);
    $stmt->execute();
    $stmt->close();

    $vray_link = json_encode($vray_link);
    $stmt = $connection->prepare("UPDATE `orders_list` SET `server_id` = ?, `link`=?, `remark` = ? WHERE `id` = ?");
    $stmt->bind_param("issi", $sid, $vray_link, $newRemark, $oid);
    $stmt->execute();
    $stmt->close();

    $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id` = ?");
    $stmt->bind_param("i", $sid);
    $stmt->execute();
    $server_title = $stmt->get_result()->fetch_assoc()['title'];
    $stmt->close();
    
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `userid` = ? AND `status` = 1 ORDER BY `id` DESC");
    $stmt->bind_param("i", $from_id);
    $stmt->execute();
    $orders = $stmt->get_result();
    $stmt->close();
    
    $keyboard = [];
    while($cat = $orders->fetch_assoc()){
        $id = $cat['id'];
        $cremark = $cat['remark'];
        $keyboard[] = ['text' => "$cremark", 'callback_data' => "orderDetails$id"];
    }
    $keyboard = array_chunk($keyboard,2);
    $keyboard[] = [['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"]];
    $msg = " 📍لوکیشن سرویس $remark به $server_title با نام کاربری $newRemark تغییر یافت.\n لطفا برای مشاهده مشخصات، روی آن بزنید👇";
    
    editText($message_id, $msg,json_encode([
            'inline_keyboard' => $keyboard
        ]));
    exit();
}
*/
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
elseif(preg_match('/^deleteMyConfig(\d+)/', $data, $match)){
    $oid = $match[1];
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $remark = $order['remark'];

    editText($message_id,
        "⚠️ آیا از حذف اشتراک <code>$remark</code> مطمئن هستید؟
🛑 دقت کنید با انتخاب گزینه <b>بله</b> اشتراک شما برای همیشه حذف می‌شود، این عمل غیرقابل برگشت بوده و در صورت فعال بودن اشتراک هزینه آن به شما برگشت داده نمی‌شود.",
        json_encode([
            'inline_keyboard' => [
                [['text'=>"بله",'callback_data'=>"yesDeleteConfig" . $match[1]],['text'=>"خیر",'callback_data'=>"noDontDelete"]]
            ]
        ]),
        "HTML"
    );
}

elseif($data=="noDontDelete"){
    editText($message_id, "عملیات مورد نظر لغو شد.",json_encode([
        'inline_keyboard' => [
            [['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"]]
            ]
    ]));
}
elseif(preg_match('/^yesDeleteConfig(\d+)/',$data,$match)){
    $oid = $match[1];
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $inbound_id = $order['inbound_id'];
    $server_id = $order['server_id'];
    $remark = $order['remark'];
    $uuid = $order['uuid']??"0";
    $fileid = $order['fileid'];
    
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id` = ?");
    $stmt->bind_param("i", $fileid);
    $stmt->execute();
    $planDetail = $stmt->get_result()->fetch_assoc();
    $stmt->close();
	$volume = $planDetail['volume'];
	$days = $planDetail['days'];
	
    $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id` = ?");
    $stmt->bind_param('i', $server_id);
    $stmt->execute();
    $serverConfig = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $serverType = $serverConfig['type'];

	
	if($serverType != "marzban"){
        if($inbound_id > 0) $res = deleteClient($server_id, $inbound_id, $uuid, 1);
        else $res = deleteInbound($server_id, $uuid, 1);
        
        $leftMb = sumerize($res['total'] - $res['up'] - $res['down']);
        $expiryDay = $res['expiryTime'] != 0?
            floor(
                (substr($res['expiryTime'],0,-3)-time())/(60 * 60 * 24))
                :
                "نامحدود";
	}else{
	    $configInfo = getMarzbanUser($server_id, $remark);
	    deleteMarzban($server_id, $remark);
	    $leftMb = sumerize($configInfo->data_limit - $configInfo->used_traffic);
	    $expiryDay = $configInfo->expire != 0?
	        floor(($configInfo->expire - time())/ 86400):"نامحدود";
	}

    
    if(is_numeric($expiryDay)){
        if($expiryDay<0) $expiryDay = 0;
    }

    $stmt = $connection->prepare("UPDATE `server_info` SET `ucount` = `ucount` + 1 WHERE `id` = ?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $stmt->close();

    $vray_link = json_encode($vray_link);
    $stmt = $connection->prepare("DELETE FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $stmt->close();

    editText($message_id, "اشتراک $remark با موفقیت حذف شد.",json_encode([
            'inline_keyboard' => [
                [['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"]]
                ]
        ]));
        
$channelMessage = "🔋 حذف اشتراک توسط کاربر

🎫 شناسه عددی: <code>$from_id</code>
👨 اسم کاربر: $first_name
⚡️ نام کاربری: @$username
🎈 نام کاربری اشتراک: $remark
🔋 حجم اولیه اشتراک: $volume گیگ
⏰ مدت اشتراک: $days روز
";

// ارسال پیام به ادمین خاص
sendMessage($channelMessage, null, "html", $admin);

// ارسال پیام به تمام ادمین‌ها
//sendToAllAdmins('sendMessage', ['text' => $channelMessage,'reply_markup' => null,'parse_mode' => "html"]);
	// ارسال پیام به کانال گزارشات
	$rewardChannel = $botState['rewardChannel'];
		if (!empty($rewardChannel)) {
		sendMessage($channelMessage, null, "html", $rewardChannel);
	}
    exit();
}
elseif(preg_match('/^delUserConfig(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $oid = $match[1];
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $remark = $order['remark'];

    editText($message_id, "آیا از حذف اشتراک $remark مطمئن هستید؟",json_encode([
        'inline_keyboard' => [
            [['text'=>"بله",'callback_data'=>"yesDeleteUserConfig" . $match[1]],['text'=>"خیر",'callback_data'=>"noDontDelete"]]
            ]
    ]));
}
elseif(preg_match('/^yesDeleteUserConfig(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $oid = $match[1];
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $userId = $order['userid'];
    $inbound_id = $order['inbound_id'];
    $server_id = $order['server_id'];
    $remark = $order['remark'];
    $uuid = $order['uuid']??"0";
    
    $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id` = ?");
    $stmt->bind_param('i', $server_id);
    $stmt->execute();
    $serverConfig = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $serverType = $serverConfig['type'];
    
	
	if($serverType != "marzban"){
        if($inbound_id > 0) $res = deleteClient($server_id, $inbound_id, $uuid, 1);
        else $res = deleteInbound($server_id, $uuid, 1);
	}else{
	    $res = deleteMarzban($server_id, $remark);
	}
    

    $stmt = $connection->prepare("UPDATE `server_info` SET `ucount` = `ucount` + 1 WHERE `id` = ?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $stmt->close();

    $vray_link = json_encode($vray_link);
    $stmt = $connection->prepare("DELETE FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $stmt->close();

    editText($message_id, "اشتراک $remark با موفقیت حذف شد.",json_encode([
            'inline_keyboard' => [
                [['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"]]
                ]
	]));
	// ارسال پیام به ادمین
	$adminMessage = "
❌ اشتراک توسط ادمین حذف شد.

👤 شناسه عددی: $userId
🎈 نام کاربری اشتراک: $remark
";
	sendMessage($adminMessage, null, "html", $admin);
	sendToAllAdmins('sendMessage', ['text' => $adminMessage,'parse_mode' => "html"]);
        
    exit();
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
if(($data=='categoriesSetting' || preg_match('/^nextCategoryPage(\d+)/',$data,$match)) and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(isset($match[1])) $keys = getCategoriesKeys($match[1]);
    else $keys = getCategoriesKeys();
    
    editText($message_id,"☑️ مدیریت دسته ها:", $keys);
}
if($data=='addNewCategory' and (($from_id == $admin || $userInfo['isAdmin'] == true))){
    setUser($data);
    delMessage();
    $stmt = $connection->prepare("DELETE FROM `server_categories` WHERE `active`=0");
    $stmt->execute();
    $stmt->close();


    $sql = "INSERT INTO `server_categories` VALUES (NULL, 0, '', 0,2,0);";
    $stmt = $connection->prepare($sql);
    $stmt->execute();
    $stmt->close();


    $msg = '▪️ یه اسم برای دسته بندی وارد کن:';
    sendMessage($msg,$cancelKey);
    exit;
}
if(preg_match('/^addNewCategory/',$userInfo['step']) and $text!=$buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $step = checkStep('server_categories');
    if($step==2 and $text!=$buttonValues['cancel'] ){
        
        $stmt = $connection->prepare("UPDATE `server_categories` SET `title`=?,`step`=4,`active`=1 WHERE `active`=0");
        $stmt->bind_param("s", $text);
        $stmt->execute();
        $stmt->close();


        $msg = 'یه دسته بندی جدید برات ثبت کردم 🙂☑️';
        sendMessage($msg,$removeKeyboard);
        sendMessage($mainValues['reached_main_menu'],getCategoriesKeys());
    }
}
if(preg_match('/^wizwizcategorydelete(\d+)_(\d+)/',$data, $match) and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("DELETE FROM `server_categories` WHERE `id`=?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $stmt->close();

    alert("دسته بندی رو برات حذفش کردم ☹️☑️");
    
    $stmt = $connection->prepare("SELECT * FROM `server_categories` WHERE `active`=1 AND `parent`=0");
    $stmt->execute();
    $cats = $stmt->get_result();
    $stmt->close();

    $keys = getCategoriesKeys($match[2]);
    editText($message_id,"☑️ مدیریت دسته ها:", $keys);
}
if(preg_match('/^wizwizcategoryedit/',$data) and ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    setUser($data);
    delMessage();
    sendMessage("〽️ یه اسم جدید برا دسته بندی انتخاب کن:",$cancelKey);exit;
}
if(preg_match('/wizwizcategoryedit(\d+)_(\d+)/',$userInfo['step'], $match) && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    $stmt = $connection->prepare("UPDATE `server_categories` SET `title`=? WHERE `id`=?");
    $stmt->bind_param("si", $text, $match[1]);
    $stmt->execute();
    $stmt->close();

    sendMessage("☑️ با موفقیت برات تغییر دادم.");
    setUser();
    
    sendMessage("☑️ مدیریت دسته ها:", getCategoriesKeys($match[2]));
}
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

if(preg_match('/^editSubLinks(\d+)/', $data, $match) && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    delMessage();
    setUser("subLinksInput" . $match[1]);
    sendMessage("🔗 لطفاً لینک‌های کمکی اشتراک سرور را وارد کنید.\n📌 لینک‌ها را با کاما (,) از هم جدا کنید.\n\nمثال:\n`https://sub1.domain.com,https://sub2.domain.com`", $cancelKey, "Markdown");
    exit();
}
if(preg_match('/^subLinksInput(\d+)/', $userInfo['step'], $match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $rowId = $match[1];
    $subLinks = trim($text);

    // ذخیره در دیتابیس
    $stmt = $connection->prepare("UPDATE `server_config` SET `sub_links` = ? WHERE `id` = ?");
    $stmt->bind_param("si", $subLinks, $rowId);
    $stmt->execute();
    $stmt->close();

    sendMessage("✅ لینک‌های کمکی با موفقیت ذخیره شدند.", $removeKeyboard);

    // برگشت به منوی سرور
    $keys = getServerConfigKeys($rowId);
    sendMessage("☑️ مدیریت سرور:", $keys);
    setUser();
    exit();
}

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
