<?php
/** AChBot V8: source-pinned financial compatibility module. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../../messages/loader.php'; // ACHBOT_TEXT_CATALOG


// BEGIN Manage discount
if(preg_match('/^discount_codes(_(\d+))?$/', $data, $m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){ 
    $page = isset($m[2]) ? (int)$m[2] : 1;
    if($page < 1) $page = 1;

    editText($message_id, \achbotMessage('payment.discount_administration.message.bfaa0cb7fb'), getDiscountCodeKeys($page));
}

// شروع ساخت کد تخفیف جدید
if($data=="addDiscountCode" && ($from_id == $admin || $userInfo['isAdmin'] == true)){ 
    delMessage(); 
    sendMessage(
        \achbotMessage('payment.discount_administration.add_discount_code.message.4c228aa43c').
        \achbotMessage('payment.discount_administration.add_discount_code.message.6d4dae8c41'),
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
        sendMessage(\achbotMessage('payment.discount_administration.add_discount_code.message.3a4d5b3bcb'));
        return;
    }

    // چک یکتا بودن کد تخفیف
    $stmt = $connection->prepare("SELECT `id` FROM `discounts` WHERE `hash_id` = ? LIMIT 1");
    $stmt->bind_param("s", $code);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    if($res->num_rows > 0){
        sendMessage(\achbotMessage('payment.discount_administration.add_discount_code.message.d4e0464259'));
        return;
    }

    // ذخیره موقت اطلاعات کد
    $dInfo = array();
    $dInfo['hash_id'] = $code;

    // رفتن به مرحله بعد: مقدار تخفیف
    setUser("addDiscountAmount" . json_encode($dInfo, JSON_UNESCAPED_UNICODE)); 
    sendMessage(
        \achbotMessage('payment.discount_administration.add_discount_code.message.75190ca5af').
        \achbotMessage('payment.discount_administration.add_discount_code.message.78fa15f8f0').
        \achbotMessage('payment.discount_administration.add_discount_code.message.90e96f0615'),
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
        sendMessage(\achbotMessage('payment.discount_administration.add_discount_code.message.1658fb52d4'), $cancelKey);
    }else{
        sendMessage(\achbotMessage('payment.discount_administration.add_discount_code.message.84d623bbac'));
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
        sendMessage(\achbotMessage('payment.discount_administration.add_discount_code.message.1415403ff4'), $cancelKey);
    }else{
        sendMessage(\achbotMessage('payment.discount_administration.add_discount_code.message.50cd9cde9a'));
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
        sendMessage(\achbotMessage('payment.discount_administration.add_discount_code.message.4359ee21ce'), $cancelKey);
    }else{
        sendMessage(\achbotMessage('payment.discount_administration.add_discount_code.message.50cd9cde9a'));
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
                    ['text' => \achbotMessage('payment.discount_administration.add_discount_code.button.ee7ae9b315'),              'callback_data' => 'setDiscountAudience_all'],
                ],
                [
                    ['text' => \achbotMessage('payment.discount_administration.add_discount_code.button.8d713207b6'),             'callback_data' => 'setDiscountAudience_user'],
                ],
                [
                    ['text' => \achbotMessage('payment.discount_administration.add_discount_code.button.921ac284c7'),         'callback_data' => 'setDiscountAudience_agents'],
                ],
				[
					['text' => \achbotMessage('payment.discount_administration.add_discount_code.button.04640cdbe9'),        'callback_data' => 'setDiscountAudience_non_agents'],
				],
                [
                    ['text' => \achbotMessage('payment.discount_administration.add_discount_code.button.3f8deb4819'),       'callback_data' => 'setDiscountAudience_buyers'],
                ],
                [
                    ['text' => \achbotMessage('payment.discount_administration.add_discount_code.button.2e948917ed'),        'callback_data' => 'setDiscountAudience_non_buyers'],
                ],
                [
                    ['text' => \achbotMessage('payment.discount_administration.add_discount_code.button.69b656b3b1'),      'callback_data' => 'setDiscountAudience_has_active_order'],
                ],
                [
                    ['text' => \achbotMessage('payment.discount_administration.add_discount_code.button.3decf2dd44'),       'callback_data' => 'setDiscountAudience_no_active_order'],
                ],
            ]
        ];

        sendMessage(
            \achbotMessage('payment.discount_administration.add_discount_code.message.2262c64bde'),
            json_encode($keys)
        );
    }else{
        sendMessage(\achbotMessage('payment.discount_administration.add_discount_code.message.50cd9cde9a'));
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
            sendMessage(\achbotMessage('payment.discount_administration.add_discount_code.message.9480571c47'), $cancelKey);
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
                    ['text' => \achbotMessage('payment.discount_administration.add_discount_code.button.cd7a54c9e2'),       'callback_data' => 'setDiscountTarget_all'],
                ],
                [
                    ['text' => \achbotMessage('payment.discount_administration.add_discount_code.button.ca013ac6e2'),      'callback_data' => 'setDiscountTarget_server'],
                ],
                [
                    ['text' => \achbotMessage('payment.discount_administration.add_discount_code.button.9cc8fb5765'), 'callback_data' => 'setDiscountTarget_category'],
                ],
                [
                    ['text' => \achbotMessage('payment.discount_administration.add_discount_code.button.14a5228ae9'),       'callback_data' => 'setDiscountTarget_plan'],
                ],
            ]
        ];

        sendMessage(
            \achbotMessage('payment.discount_administration.add_discount_code.message.075324686f'),
            json_encode($keys)
        );
        return;
    }
}

// اگر audience_type = user باشد → گرفتن userid کاربر
if(preg_match('/^addDiscountAudienceUser(.*)/',$userInfo['step'],$match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    
    if(!is_numeric($text)){
        sendMessage(\achbotMessage('payment.discount_administration.add_discount_code.message.5472913e1d'));
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
                ['text' => \achbotMessage('payment.discount_administration.add_discount_code.button.cd7a54c9e2'),       'callback_data' => 'setDiscountTarget_all'],
            ],
            [
                ['text' => \achbotMessage('payment.discount_administration.add_discount_code.button.ca013ac6e2'),      'callback_data' => 'setDiscountTarget_server'],
            ],
            [
                ['text' => \achbotMessage('payment.discount_administration.add_discount_code.button.9cc8fb5765'), 'callback_data' => 'setDiscountTarget_category'],
            ],
            [
                ['text' => \achbotMessage('payment.discount_administration.add_discount_code.button.14a5228ae9'),       'callback_data' => 'setDiscountTarget_plan'],
            ],
        ]
    ];

    sendMessage(
        \achbotMessage('payment.discount_administration.add_discount_code.message.075324686f'),
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
            sendMessage(\achbotMessage('payment.discount_administration.add_discount_code.message.ffc049ded2'), $cancelKey);
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
                    ['text' => \achbotMessage('payment.discount_administration.add_discount_code.button.7f3ca89659'), 'callback_data' => 'wizwizch']
                ];
            }

            sendMessage(\achbotMessage('payment.discount_administration.add_discount_code.message.4554eff310'), json_encode($keys));
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
                    ['text' => \achbotMessage('payment.discount_administration.add_discount_code.button.0a243a6b6f'), 'callback_data' => 'wizwizch']
                ];
            }

            sendMessage(\achbotMessage('payment.discount_administration.add_discount_code.message.3008556faf'), json_encode($keys));
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
                    ['text' => \achbotMessage('payment.discount_administration.add_discount_code.button.b9342f92d9'), 'callback_data' => 'wizwizch']
                ];
            }

            sendMessage(\achbotMessage('payment.discount_administration.add_discount_code.message.b4a5143f89'), json_encode($keys));
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
        sendMessage(\achbotMessage('payment.discount_administration.add_discount_code.message.ffc049ded2'), $cancelKey);
    }
}

// انتخاب دسته‌بندی
if(preg_match('/^addDiscountTargetCategory(.*)/',$userInfo['step'],$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(preg_match('/^setDiscountTargetCategoryId_(\d+)$/', $data, $m)){
        $dInfo = json_decode($match[1], true);
        if(!is_array($dInfo)) $dInfo = array();

        $dInfo['target_id'] = (int)$m[1];

        setUser('addDiscountMinPrice' . json_encode($dInfo, JSON_UNESCAPED_UNICODE));
        sendMessage(\achbotMessage('payment.discount_administration.add_discount_code.message.ffc049ded2'), $cancelKey);
    }
}

// انتخاب پلن
if(preg_match('/^addDiscountTargetPlan(.*)/',$userInfo['step'],$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(preg_match('/^setDiscountTargetPlanId_(\d+)$/', $data, $m)){
        $dInfo = json_decode($match[1], true);
        if(!is_array($dInfo)) $dInfo = array();

        $dInfo['target_id'] = (int)$m[1];

        setUser('addDiscountMinPrice' . json_encode($dInfo, JSON_UNESCAPED_UNICODE));
        sendMessage(\achbotMessage('payment.discount_administration.add_discount_code.message.ffc049ded2'), $cancelKey);
    }
}

// تعیین حداقل مبلغ سفارش
if(preg_match('/^addDiscountMinPrice(.*)/',$userInfo['step'],$match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){

    if(!is_numeric($text)){
        sendMessage(\achbotMessage('payment.discount_administration.add_discount_code.message.475dad85d1'));
        return;
    }

    $dInfo = json_decode($match[1], true);
    if(!is_array($dInfo)) $dInfo = array();

    $minPrice = (int)$text;
    $dInfo['min_price'] = $minPrice > 0 ? $minPrice : 0;

    setUser('addDiscountMaxDiscount' . json_encode($dInfo, JSON_UNESCAPED_UNICODE));
    sendMessage(\achbotMessage('payment.discount_administration.add_discount_code.message.685284b2e1'), $cancelKey);
}

// تعیین سقف تخفیف و ثبت نهایی
if(preg_match('/^addDiscountMaxDiscount(.*)/',$userInfo['step'],$match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){

    if(!is_numeric($text)){
        sendMessage(\achbotMessage('payment.discount_administration.add_discount_code.message.ed39e0ee1b'));
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

    sendMessage(\achbotMessage('payment.discount_administration.add_discount_code.message.f12309f24f', ['hashId' => $hashId]), $removeKeyboard, "HTML");
    setUser(); 
    sendMessage(\achbotMessage('payment.discount_administration.message.bfaa0cb7fb'), getDiscountCodeKeys());
} 

// ❓ درخواست تأیید حذف
if(preg_match('/^delDiscountConfirm(\d+)/', $data, $m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $discountId = (int)$m[1];

    $keys = [
        'inline_keyboard' => [
            [
                ['text' => \achbotMessage('payment.discount_administration.add_discount_code.button.689da8336a'), 'callback_data' => "delDiscount" . $discountId],
                ['text' => \achbotMessage('payment.discount_administration.add_discount_code.button.a766691671'), 'callback_data' => "discount_codes"],
            ]
        ]
    ];

    sendMessage(\achbotMessage('payment.discount_administration.add_discount_code.message.e8830dbcad'), json_encode($keys));
}

// 🗑 حذف نهایی کد تخفیف
if(preg_match('/^delDiscount(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){ 
    $discountId = (int)$match[1];

    $stmt = $connection->prepare("DELETE FROM `discounts` WHERE `id` = ?"); 
    $stmt->bind_param("i", $discountId); 
    $stmt->execute(); 
    $stmt->close(); 
     
    alert(\achbotMessage('payment.discount_administration.add_discount_code.alert.f007c663c6'));
    editText($message_id,\achbotMessage('payment.discount_administration.message.bfaa0cb7fb'),getDiscountCodeKeys(1));
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
        alert(\achbotMessage('payment.discount_administration.add_discount_code.alert.fa58f63a4f'));
        return;
    }

    $hashId = $row['hash_id'];

    // ساخت خلاصه برای نمایش
    $dateText  = $row['expire_date'] != 0 ? jdate("Y/n/j H:i", $row['expire_date']) : \achbotMessage('payment.discount_administration.add_discount_code.text.5e6ee5eaa1');
    $countText = $row['expire_count'] != -1 ? $row['expire_count'] : \achbotMessage('payment.discount_administration.add_discount_code.text.5e6ee5eaa1');
    $canUse    = $row['can_use'];
    $canUseText = $canUse != -1 ? $canUse : \achbotMessage('payment.discount_administration.add_discount_code.text.5e6ee5eaa1');

    if($row['type'] == 'percent'){
        $amountText = $row['amount'] . "%";
    }else{
        $amountText = number_format($row['amount']) . \achbotMessage('payment.discounts.apply_discount_to_pay.text.2cc98f7f7c');
    }

    $minPrice     = isset($row['min_price']) ? (int)$row['min_price'] : 0;
    $minPriceText = $minPrice > 0 ? number_format($minPrice) . \achbotMessage('payment.discounts.apply_discount_to_pay.text.2cc98f7f7c') : \achbotMessage('payment.discount_administration.add_discount_code.text.21799b16ee');

    $maxDiscount     = isset($row['max_discount']) ? (int)$row['max_discount'] : 0;
    $maxDiscountText = $maxDiscount > 0 ? number_format($maxDiscount) . \achbotMessage('payment.discounts.apply_discount_to_pay.text.2cc98f7f7c') : \achbotMessage('payment.discount_administration.add_discount_code.text.b86208d3b3');

    $audienceType   = isset($row['audience_type']) ? $row['audience_type'] : 'all';
    $audienceUserId = isset($row['audience_user_id']) ? (int)$row['audience_user_id'] : 0;

    switch($audienceType){
        case 'user':
            $audienceText = \achbotMessage('payment.discount_administration.add_discount_code.audience_text.c193cf44ad', ['audienceUserId' => $audienceUserId]);
            break;
        case 'agents':
            $audienceText = \achbotMessage('payment.payment_lifecycle.pay_ux_audience_modes.text.452a2d6fed');
            break;
        case 'non_agents':
            $audienceText = \achbotMessage('payment.discount_administration.add_discount_code.button.04640cdbe9');
            break;
        case 'buyers':
            $audienceText = \achbotMessage('payment.discount_administration.add_discount_code.audience_text.c94f068cde');
            break;
        case 'non_buyers':
            $audienceText = \achbotMessage('payment.payment_lifecycle.pay_ux_audience_modes.text.711a7d07d3');
            break;
        case 'has_active_order':
            $audienceText = \achbotMessage('payment.discount_administration.add_discount_code.audience_text.515071f607');
            break;
        case 'no_active_order':
            $audienceText = \achbotMessage('payment.discount_administration.add_discount_code.audience_text.499d8f3057');
            break;
        case 'all':
        default:
            $audienceText = \achbotMessage('payment.discount_administration.add_discount_code.button.ee7ae9b315');
    }

    $targetType = isset($row['target_type']) ? $row['target_type'] : 'all';
    $targetId   = isset($row['target_id']) ? (int)$row['target_id'] : 0;
    $targetText = \achbotMessage('payment.discount_administration.add_discount_code.button.cd7a54c9e2');

    if($targetType == 'server' && $targetId > 0){
        $stmt2 = $connection->prepare("SELECT `title` FROM `server_info` WHERE `id` = ? LIMIT 1");
        $stmt2->bind_param("i", $targetId);
        $stmt2->execute();
        $res2 = $stmt2->get_result()->fetch_assoc();
        $stmt2->close();
        $title = $res2 ? $res2['title'] : ("ID " . $targetId);
        $targetText = \achbotMessage('payment.discount_administration.add_discount_code.target_text.415cdbdd27') . $title;
    }elseif($targetType == 'category' && $targetId > 0){
        $stmt2 = $connection->prepare("SELECT `title` FROM `server_categories` WHERE `id` = ? LIMIT 1");
        $stmt2->bind_param("i", $targetId);
        $stmt2->execute();
        $res2 = $stmt2->get_result()->fetch_assoc();
        $stmt2->close();
        $title = $res2 ? $res2['title'] : ("ID " . $targetId);
        $targetText = \achbotMessage('payment.discount_administration.add_discount_code.target_text.88c387668b') . $title;
    }elseif($targetType == 'plan' && $targetId > 0){
        $stmt2 = $connection->prepare("SELECT `title` FROM `server_plans` WHERE `id` = ? LIMIT 1");
        $stmt2->bind_param("i", $targetId);
        $stmt2->execute();
        $res2 = $stmt2->get_result()->fetch_assoc();
        $stmt2->close();
        $title = $res2 ? $res2['title'] : ("ID " . $targetId);
        $targetText = \achbotMessage('payment.discount_administration.add_discount_code.target_text.f172411291') . $title;
    }

    $text = \achbotMessage('payment.discount_administration.add_discount_code.text.3a4c0517b4', ['hashId' => $hashId]).
            \achbotMessage('payment.discount_administration.add_discount_code.text.66fafb9fd4', ['amountText' => $amountText]).
            \achbotMessage('payment.discount_administration.add_discount_code.text.d4169dd62d', ['dateText' => $dateText]).
            \achbotMessage('payment.discount_administration.add_discount_code.text.9cd9d5658f', ['countText' => $countText]).
            \achbotMessage('payment.discount_administration.add_discount_code.text.5919cd752d', ['canUseText' => $canUseText]).
            \achbotMessage('payment.discount_administration.add_discount_code.text.b7cf332a32', ['audienceText' => $audienceText]).
            \achbotMessage('payment.discount_administration.add_discount_code.text.77f1958ae0', ['targetText' => $targetText]).
            \achbotMessage('payment.discount_administration.add_discount_code.text.d2d2b624b9', ['minPriceText' => $minPriceText]).
            \achbotMessage('payment.discount_administration.add_discount_code.text.0b9a6e5bc7', ['maxDiscountText' => $maxDiscountText]);

    $keys = [
        'inline_keyboard' => [
            [
                ['text' => \achbotMessage('payment.discount_administration.add_discount_code.button.6928c28094'),    'callback_data' => "editD_{$discountId}_amount"],
                ['text' => \achbotMessage('payment.discount_administration.add_discount_code.button.500a41c19c'),    'callback_data' => "editD_{$discountId}_expire"],
            ],
            [
                ['text' => \achbotMessage('payment.discount_administration.add_discount_code.button.2a9c76274d'),       'callback_data' => "editD_{$discountId}_count"],
                ['text' => \achbotMessage('payment.discount_administration.add_discount_code.button.aa2cf0b48b'), 'callback_data' => "editD_{$discountId}_canuse"],
            ],
            [
                ['text' => \achbotMessage('payment.discount_administration.add_discount_code.button.de8e46e3ed'),   'callback_data' => "editD_{$discountId}_audience"],
                ['text' => \achbotMessage('payment.discount_administration.add_discount_code.button.81302c92dd'),      'callback_data' => "editD_{$discountId}_target"],
            ],
            [
                ['text' => \achbotMessage('payment.discount_administration.add_discount_code.button.c74b84ea09'),     'callback_data' => "editD_{$discountId}_minprice"],
                ['text' => \achbotMessage('payment.discount_administration.add_discount_code.button.39009afb83'),      'callback_data' => "editD_{$discountId}_maxdiscount"],
            ],
            [
                ['text' => \achbotMessage('payment.discount_administration.add_discount_code.button.e6eabe4b9d'), 'callback_data' => "editDRemoveUser{$discountId}"],
            ],
            [
                ['text' => \achbotMessage('payment.discount_administration.add_discount_code.button.6b16e7f231'), 'callback_data' => "discount_codes"],
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
        \achbotMessage('payment.discount_administration.add_discount_code.message.d2ff3672fa').
        \achbotMessage('payment.discount_administration.add_discount_code.message.78fa15f8f0').
        \achbotMessage('payment.discount_administration.add_discount_code.message.90e96f0615'),
        $cancelKey
    );
}

if(preg_match('/^editDiscountAmount(\d+)$/', $userInfo['step'], $m) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $discountId = (int)$m[1];

    $newType = 'amount';
    if(strstr($text, "%")) $newType = 'percent';

    $val = trim(str_replace("%", "", $text));

    if(!is_numeric($val)){
        sendMessage(\achbotMessage('payment.discount_administration.add_discount_code.message.84d623bbac'));
        return;
    }

    $amount = (int)$val;

    $stmt = $connection->prepare("UPDATE `discounts` SET `type` = ?, `amount` = ? WHERE `id` = ?");
    $stmt->bind_param("sii", $newType, $amount, $discountId);
    $stmt->execute();
    $stmt->close();

    setUser();

    sendMessage(\achbotMessage('payment.discount_administration.add_discount_code.message.04d142737e'));
    $data = "editDiscount".$discountId;
}

// ویرایش تاریخ انقضا
if(preg_match('/^editD_(\d+)_expire$/', $data, $m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $discountId = (int)$m[1];

    setUser("editDiscountExpire{$discountId}");
    sendMessage(
        \achbotMessage('payment.discount_administration.add_discount_code.message.a12d0d9f77').
        \achbotMessage('payment.discount_administration.add_discount_code.message.615519a0c3'),
        $cancelKey
    );
}

if(preg_match('/^editDiscountExpire(\d+)$/', $userInfo['step'], $m) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $discountId = (int)$m[1];

    if(!is_numeric($text)){
        sendMessage(\achbotMessage('payment.discount_administration.add_discount_code.message.50cd9cde9a'));
        return;
    }

    $days = (int)$text;
    $newDate = $days != 0 ? time() + ($days * 24 * 60 * 60) : 0;

    $stmt = $connection->prepare("UPDATE `discounts` SET `expire_date` = ? WHERE `id` = ?");
    $stmt->bind_param("ii", $newDate, $discountId);
    $stmt->execute();
    $stmt->close();

    setUser();
    sendMessage(\achbotMessage('payment.discount_administration.add_discount_code.message.15c9736bbe'));
    $data = "editDiscount".$discountId;
}

// ویرایش تعداد کل استفاده
if(preg_match('/^editD_(\d+)_count$/', $data, $m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $discountId = (int)$m[1];

    setUser("editDiscountCount{$discountId}");
    sendMessage(
        \achbotMessage('payment.discount_administration.add_discount_code.message.0e04aa052a').
        \achbotMessage('payment.discount_administration.add_discount_code.message.ccba40e01f'),
        $cancelKey
    );
}

if(preg_match('/^editDiscountCount(\d+)$/', $userInfo['step'], $m) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $discountId = (int)$m[1];

    if(!is_numeric($text)){
        sendMessage(\achbotMessage('payment.discount_administration.add_discount_code.message.50cd9cde9a'));
        return;
    }

    $count = (int)$text;
    $newCount = $count > 0 ? $count : -1;

    $stmt = $connection->prepare("UPDATE `discounts` SET `expire_count` = ? WHERE `id` = ?");
    $stmt->bind_param("ii", $newCount, $discountId);
    $stmt->execute();
    $stmt->close();

    setUser();
    sendMessage(\achbotMessage('payment.discount_administration.add_discount_code.message.ca05319dfd'));
    $data = "editDiscount".$discountId;
}

// ویرایش تعداد استفاده هر کاربر
if(preg_match('/^editD_(\d+)_canuse$/', $data, $m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $discountId = (int)$m[1];

    setUser("editDiscountCanUse{$discountId}");
    sendMessage(
        \achbotMessage('payment.discount_administration.add_discount_code.message.dfbb7e73b3').
        \achbotMessage('payment.discount_administration.add_discount_code.message.ccba40e01f'),
        $cancelKey
    );
}

if(preg_match('/^editDiscountCanUse(\d+)$/', $userInfo['step'], $m) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $discountId = (int)$m[1];

    if(!is_numeric($text)){
        sendMessage(\achbotMessage('payment.discount_administration.add_discount_code.message.50cd9cde9a'));
        return;
    }

    $v = (int)$text;
    $newVal = $v > 0 ? $v : -1;

    $stmt = $connection->prepare("UPDATE `discounts` SET `can_use` = ? WHERE `id` = ?");
    $stmt->bind_param("ii", $newVal, $discountId);
    $stmt->execute();
    $stmt->close();

    setUser();
    sendMessage(\achbotMessage('payment.discount_administration.add_discount_code.message.52e38e4607'));
    $data = "editDiscount".$discountId;
}

// شروع ویرایش گروه کاربران
if(preg_match('/^editD_(\d+)_audience$/', $data, $m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $discountId = (int)$m[1];

    $keys = [
        'inline_keyboard' => [
            [
                ['text' => \achbotMessage('payment.discount_administration.add_discount_code.button.ee7ae9b315'),        'callback_data' => "editDAud_{$discountId}_all"],
            ],
            [
                ['text' => \achbotMessage('payment.discount_administration.add_discount_code.button.8d713207b6'),       'callback_data' => "editDAud_{$discountId}_user"],
            ],
            [
                ['text' => \achbotMessage('payment.discount_administration.add_discount_code.button.921ac284c7'),   'callback_data' => "editDAud_{$discountId}_agents"],
            ],
			[
				['text' => \achbotMessage('payment.discount_administration.add_discount_code.button.04640cdbe9'),  'callback_data' => "editDAud_{$discountId}_non_agents"],
			],
            [
                ['text' => \achbotMessage('payment.discount_administration.add_discount_code.button.3f8deb4819'), 'callback_data' => "editDAud_{$discountId}_buyers"],
            ],
            [
                ['text' => \achbotMessage('payment.discount_administration.add_discount_code.button.2e948917ed'),  'callback_data' => "editDAud_{$discountId}_non_buyers"],
            ],
            [
                ['text' => \achbotMessage('payment.discount_administration.add_discount_code.audience_text.515071f607'),        'callback_data' => "editDAud_{$discountId}_has_active_order"],
            ],
            [
                ['text' => \achbotMessage('payment.discount_administration.add_discount_code.audience_text.499d8f3057'),         'callback_data' => "editDAud_{$discountId}_no_active_order"],
            ],
        ]
    ];

    sendMessage(\achbotMessage('payment.discount_administration.add_discount_code.message.9c822477c9'), json_encode($keys));
}

// ست کردن audience (به جز حالت user)
if(preg_match('/^editDAud_(\d+)_(.+)$/', $data, $m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $discountId = (int)$m[1];
    $choice     = $m[2];

    if($choice == 'user'){
        setUser("editDiscountAudienceUser{$discountId}");
        sendMessage(\achbotMessage('payment.discount_administration.add_discount_code.message.9480571c47'), $cancelKey);
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
    sendMessage(\achbotMessage('payment.discount_administration.add_discount_code.message.2cdb116acd'));
    $data = "editDiscount".$discountId;
}

// گرفتن userid برای audience = user
if(preg_match('/^editDiscountAudienceUser(\d+)$/', $userInfo['step'], $m) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $discountId = (int)$m[1];

    if(!is_numeric($text)){
        sendMessage(\achbotMessage('payment.discount_administration.add_discount_code.message.575c043c0e'));
        return;
    }

    $uid = (int)$text;

    $audienceType = 'user';
    $stmt = $connection->prepare("UPDATE `discounts` SET `audience_type` = ?, `audience_user_id` = ? WHERE `id` = ?");
    $stmt->bind_param("sii", $audienceType, $uid, $discountId);
    $stmt->execute();
    $stmt->close();

    setUser();
    sendMessage(\achbotMessage('payment.discount_administration.add_discount_code.message.12c329225f', ['uid' => $uid]));
    $data = "editDiscount".$discountId;
}

// شروع ویرایش هدف سرویس
if(preg_match('/^editD_(\d+)_target$/', $data, $m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $discountId = (int)$m[1];

    $keys = [
        'inline_keyboard' => [
            [
                ['text' => \achbotMessage('payment.discount_administration.add_discount_code.button.cd7a54c9e2'),       'callback_data' => "editDT_{$discountId}_all"],
            ],
            [
                ['text' => \achbotMessage('payment.discount_administration.add_discount_code.button.ca013ac6e2'),      'callback_data' => "editDT_{$discountId}_server"],
            ],
            [
                ['text' => \achbotMessage('payment.discount_administration.add_discount_code.button.9cc8fb5765'), 'callback_data' => "editDT_{$discountId}_category"],
            ],
            [
                ['text' => \achbotMessage('payment.discount_administration.add_discount_code.button.14a5228ae9'),       'callback_data' => "editDT_{$discountId}_plan"],
            ],
        ]
    ];

    sendMessage(\achbotMessage('payment.discount_administration.add_discount_code.message.44690baef6'), json_encode($keys));
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

        sendMessage(\achbotMessage('payment.discount_administration.add_discount_code.message.d0ed7fc76f'));
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
                ['text' => \achbotMessage('payment.discount_administration.add_discount_code.button.7f3ca89659'), 'callback_data' => 'wizwizch']
            ];
        }

        sendMessage(\achbotMessage('payment.discount_administration.add_discount_code.message.4554eff310'), json_encode($keys));
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
                ['text' => \achbotMessage('payment.discount_administration.add_discount_code.button.0a243a6b6f'), 'callback_data' => 'wizwizch']
            ];
        }

        sendMessage(\achbotMessage('payment.discount_administration.add_discount_code.message.3008556faf'), json_encode($keys));
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
                ['text' => \achbotMessage('payment.discount_administration.add_discount_code.button.b9342f92d9'), 'callback_data' => 'wizwizch']
            ];
        }

        sendMessage(\achbotMessage('payment.discount_administration.add_discount_code.message.b4a5143f89'), json_encode($keys));
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

    sendMessage(\achbotMessage('payment.discount_administration.add_discount_code.message.e8670a5dc8'));
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

    sendMessage(\achbotMessage('payment.discount_administration.add_discount_code.message.7c7e281370'));
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

    sendMessage(\achbotMessage('payment.discount_administration.add_discount_code.message.cbc249f44a'));
    $data = "editDiscount".$discountId;
}

// شروع ویرایش حداقل مبلغ
if(preg_match('/^editD_(\d+)_minprice$/', $data, $m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $discountId = (int)$m[1];

    setUser("editDiscountMinPrice{$discountId}");
    sendMessage(
        \achbotMessage('payment.discount_administration.add_discount_code.message.06fba4d16a').
        \achbotMessage('payment.discount_administration.add_discount_code.message.2bf46895aa'),
        $cancelKey
    );
}

if(preg_match('/^editDiscountMinPrice(\d+)$/', $userInfo['step'], $m) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $discountId = (int)$m[1];

    if(!is_numeric($text)){
        sendMessage(\achbotMessage('payment.discount_administration.add_discount_code.message.081d45bfa6'));
        return;
    }

    $v = (int)$text;
    $minPrice = $v > 0 ? $v : 0;

    $stmt = $connection->prepare("UPDATE `discounts` SET `min_price` = ? WHERE `id` = ?");
    $stmt->bind_param("ii", $minPrice, $discountId);
    $stmt->execute();
    $stmt->close();

    setUser();
    sendMessage(\achbotMessage('payment.discount_administration.add_discount_code.message.e996f76ad0'));
    $data = "editDiscount".$discountId;
}

// شروع ویرایش سقف تخفیف
if(preg_match('/^editD_(\d+)_maxdiscount$/', $data, $m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $discountId = (int)$m[1];

    setUser("editDiscountMaxDiscount{$discountId}");
    sendMessage(
        \achbotMessage('payment.discount_administration.add_discount_code.message.516eca7a7b').
        \achbotMessage('payment.discount_administration.add_discount_code.message.ef6e2a0bbd'),
        $cancelKey
    );
}

if(preg_match('/^editDiscountMaxDiscount(\d+)$/', $userInfo['step'], $m) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $discountId = (int)$m[1];

    if(!is_numeric($text)){
        sendMessage(\achbotMessage('payment.discount_administration.add_discount_code.message.081d45bfa6'));
        return;
    }

    $v = (int)$text;
    $maxDiscount = $v > 0 ? $v : 0;

    $stmt = $connection->prepare("UPDATE `discounts` SET `max_discount` = ? WHERE `id` = ?");
    $stmt->bind_param("ii", $maxDiscount, $discountId);
    $stmt->execute();
    $stmt->close();

    setUser();
    sendMessage(\achbotMessage('payment.discount_administration.add_discount_code.message.81f7d3347d'));
    $data = "editDiscount".$discountId;
}

if (preg_match('/^editDRemoveUser(\d+)$/', $data, $m) && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $discountId = (int)$m[1];

    // ذخیره step برای ادمین
    setUser("removeDiscountUser" . $discountId);

    global $cancelKey;

    sendMessage(
        \achbotMessage('payment.discount_administration.add_discount_code.message.b42c09006d').
        \achbotMessage('payment.discount_administration.add_discount_code.message.b0bd504d42').
        \achbotMessage('payment.discount_administration.add_discount_code.message.a94390879d'),
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
        sendMessage(\achbotMessage('payment.discount_administration.add_discount_code.message.56603da0fa'));
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
        sendMessage(\achbotMessage('payment.discount_administration.add_discount_code.message.f145bd0b48'));
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
        sendMessage(\achbotMessage('payment.discount_administration.add_discount_code.message.dcce341470'));
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
        \achbotMessage('payment.discount_administration.add_discount_code.message.660c08da0a', ['userToRemove' => $userToRemove, 'removedCount' => $removedCount]).
        \achbotMessage('payment.discount_administration.add_discount_code.message.31e02058f4'),
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
        alert(\achbotMessage('payment.discount_administration.add_discount_code.alert.fa58f63a4f'));
        return;
    }

    $current = (int)$row['is_active'];
    $newVal  = $current ? 0 : 1;

    $stmt = $connection->prepare("UPDATE `discounts` SET `is_active` = ? WHERE `id` = ?");
    $stmt->bind_param("ii", $newVal, $discountId);
    $stmt->execute();
    $stmt->close();

    if($newVal){
        alert(\achbotMessage('payment.discount_administration.add_discount_code.alert.8b32b4f3d7'));
    }else{
        alert(\achbotMessage('payment.discount_administration.add_discount_code.alert.979e079c2c'));
    }
	
    editText($message_id, \achbotMessage('payment.discount_administration.message.bfaa0cb7fb'), getDiscountCodeKeys(1));
}

if(preg_match('/^copyHash(.*)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    sendMessage("<code>" . $match[1] . "</code>",null,"HTML");
}
// END Manage discount

