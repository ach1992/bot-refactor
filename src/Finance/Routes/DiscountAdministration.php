<?php
/** AChBot V8: source-pinned financial compatibility module. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
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

