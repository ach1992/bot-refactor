<?php
/** AChBot V8: source-pinned financial compatibility module. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../messages/loader.php'; // ACHBOT_TEXT_CATALOG


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
            $msg = str_replace("MINPRICE", number_format($minPrice) . \achbotMessage('payment.discounts.apply_discount_to_pay.text.2cc98f7f7c'), $mainValues['discount_min_price']);
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

    $discountText = number_format($discountValue) . \achbotMessage('payment.discounts.apply_discount_to_pay.text.2cc98f7f7c');

    if (!empty($mainValues['valid_discount_code'])) {
        sendMessage(str_replace("AMOUNT", $discountText, $mainValues['valid_discount_code']));
    } else {
        sendMessage(\achbotMessage('payment.discounts.apply_discount_to_pay.message.e3c216313a') . $discountText);
    }

    // پیام به ادمین و کانال گزارش

    $keys = json_encode(['inline_keyboard'=>[
        [
            ['text'=>\achbotMessage('payment.discounts.apply_discount_to_pay.button.16e81418a3'), "callback_data"=>"wizwizch"]
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
                'text' => \achbotMessage('payment.discounts.get_discount_code_keys.button.54f0bc3a22', ['page' => $page]),
                'callback_data' => "wizwizch"
            ]
        ];

        $keys[] = [
            ['text' => \achbotMessage('payment.discounts.get_discount_code_keys.button.7f34ceb3ad'),      'callback_data' => "wizwizch"],
            ['text' => \achbotMessage('payment.discounts.get_discount_code_keys.button.70fad032e5'),  'callback_data' => "wizwizch"],
            ['text' => \achbotMessage('payment.discounts.get_discount_code_keys.button.68d7eb08ef'),  'callback_data' => "wizwizch"],
            ['text' => \achbotMessage('payment.discounts.get_discount_code_keys.button.d6aa12d559'), 'callback_data' => "wizwizch"],
            ['text' => \achbotMessage('payment.discounts.get_discount_code_keys.button.59ca629220'),    'callback_data' => "wizwizch"],
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
                $amountText = number_format($row['amount']) . \achbotMessage('payment.discounts.apply_discount_to_pay.text.2cc98f7f7c');
            }

            $isExpiredDate   = ($expireDate != 0 && $expireDate < $now);
			$isCountFinished = ($expireCount == 0);

			if ($isExpiredDate) {
				$statusText = \achbotMessage('payment.discounts.get_discount_code_keys.status_text.83f88b09cc');
			}
			elseif ($isCountFinished) {
				$statusText = \achbotMessage('payment.discounts.get_discount_code_keys.status_text.5136ccff12');
			}
			else {
				$statusText = $isActiveFlag ? \achbotMessage('payment.discounts.get_discount_code_keys.text.96befbea77') : \achbotMessage('payment.discounts.get_discount_code_keys.text.278aabbf79');
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
                    'text' => \achbotMessage('payment.discounts.get_discount_code_keys.button.dc31579458'),
                    'callback_data' => "editDiscount" . $id
                ],
                // حذف (با تأیید)
                [
                    'text' => \achbotMessage('payment.agent_discounts.get_agent_discounts.button.2b2966a54e'),
                    'callback_data' => "delDiscountConfirm" . $id
                ],
            ];
        }

        if($totalPages > 1){
            $navRow = [];

            if($page > 1){
                $prevPage = $page - 1;
                $navRow[] = [
                    'text' => \achbotMessage('payment.discounts.get_discount_code_keys.button.68f5beb4a0'),
                    'callback_data' => "discount_codes_" . $prevPage
                ];
            }

            if($page < $totalPages){
                $nextPage = $page + 1;
                $navRow[] = [
                    'text' => \achbotMessage('payment.discounts.get_discount_code_keys.button.b32013f76e'),
                    'callback_data' => "discount_codes_" . $nextPage
                ];
            }

            if(!empty($navRow)){
                $keys[] = $navRow;
            }

            $keys[] = [
                [
                    'text' => \achbotMessage('payment.discounts.get_discount_code_keys.button.b5534c44c8', ['page' => $page, 'totalPages' => $totalPages]),
                    'callback_data' => "wizwizch"
                ]
            ];
        }

    }else{
        $keys[] = [
            [
                'text'=>\achbotMessage('payment.discounts.get_discount_code_keys.button.8817b56474'),'callback_data'=>"wizwizch"
            ]
        ];
    }

    // دکمه افزودن + برگشت
    $keys[] = [
        ['text'=>\achbotMessage('payment.discounts.get_discount_code_keys.button.87e5644bfa'), 'callback_data'=>"addDiscountCode"]
    ];
    $keys[] = [
        ['text'=>$buttonValues['back_button'],'callback_data'=>"managePanel"]
    ];

    return json_encode(['inline_keyboard'=>$keys]);
}

