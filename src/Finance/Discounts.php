<?php
/** AChBot V8: source-pinned financial compatibility module. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../messages/loader.php'; // ACHBOT_TEXT_CATALOG


/** Preview and definitive redemption share the same audience predicate.
 * The existing order-based audiences mean a stored order reference, not
 * the gateway's active-entitlement category. Keep that distinction. */
function achbotDiscountAudienceAllowed(mysqli $db,array $discount,int $userId,bool $final): bool {
    require_once __DIR__.'/ServiceHistory.php';
    $mode=$discount['audience_type']?:'all';
    if ($mode==='all') return true;
    if ($mode==='user') return (int)$discount['audience_user_id']===$userId;
    if ($mode==='buyers' || $mode==='non_buyers') {
        $count=achbotCustomerHistoryCount($db,$userId,'buy',0,'older',$final,time());
        return $count!==null && ($count>0)===($mode==='buyers');
    }
    $lock=$final?' FOR UPDATE':'';
    if ($mode==='agents' || $mode==='non_agents') {
        $user=achbotProviderRow($db,'SELECT is_agent FROM users WHERE userid=?'.$lock,'i',[$userId]);
        return $user!==null && ((int)$user['is_agent']===1)===($mode==='agents');
    }
    if ($mode==='has_active_order' || $mode==='no_active_order') {
        $order=achbotProviderRow($db,'SELECT id FROM orders_list'.
            ($final?' FORCE INDEX (idx_orders_userid_v1)':'').' WHERE userid=? LIMIT 1'.$lock,'i',[$userId]);
        return ($order!==null)===($mode==='has_active_order');
    }
    return false;
}

function achbotDiscountTargetAllowed(mysqli $db,array $discount,int $planId,bool $final): bool {
    $mode=$discount['target_type']?:'all';
    $id=(int)$discount['target_id'];
    if ($mode==='all') return true;
    if ($planId<=0) return false;
    if ($mode==='plan') return $planId===$id;
    if ($mode!=='server' && $mode!=='category') return false;
    $plan=achbotProviderRow($db,'SELECT server_id,catid FROM server_plans WHERE id=?'.
        ($final?' FOR UPDATE':''),'i',[$planId]);
    return $plan!==null && (int)$plan[$mode==='server'?'server_id':'catid']===$id;
}

function applyDiscountToPay($connection, $rowId, $discountCode, $from_id, $username, $first_name, $admin, $botState){
    // گرفتن اطلاعات پرداخت
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `id` = ?");
    $stmt->bind_param("i", $rowId);
    $stmt->execute();
    $payInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if(!$payInfo || (int)$payInfo['user_id']!==(int)$from_id){
        // پرداخت یافت نشد
        sendMessage(\achbotMessage('legacy_messages.not_valid_discount_code'), null, 'HTML');
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
        if (!empty(\achbotMessage('legacy_messages.discount_not_found'))) {
            sendMessage(\achbotMessage('legacy_messages.discount_not_found'), null, 'HTML');
        } else {
            sendMessage(\achbotMessage('legacy_messages.not_valid_discount_code'), null, 'HTML');
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

    try {
        require_once __DIR__.'/../Panel/ProviderStorage.php';
        $allowedForUser=achbotDiscountAudienceAllowed($connection,$discountInfo,(int)$from_id,false);
    } catch (Throwable $e) {
        $allowedForUser=false;
        error_log('achbot_discount_audience_deferred user_id='.(int)$from_id.' class='.get_class($e));
    }

    if (!$allowedForUser) {
        // این کد مخصوص این کاربر/شرایط نیست
        if (!empty(\achbotMessage('legacy_messages.discount_not_for_user'))) {
            sendMessage(\achbotMessage('legacy_messages.discount_not_for_user'), null, 'HTML');
        } else {
            sendMessage(\achbotMessage('legacy_messages.not_valid_discount_code'), null, 'HTML');
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

    try {
        $allowedForPlan=achbotDiscountTargetAllowed($connection,$discountInfo,$planId,false);
    } catch (Throwable $e) {
        $allowedForPlan=false;
        error_log('achbot_discount_target_deferred user_id='.(int)$from_id.' class='.get_class($e));
    }

    if (!$allowedForPlan) {
        // این کد برای این پلن/سرور/دسته معتبر نیست
        if (!empty(\achbotMessage('legacy_messages.discount_not_for_service'))) {
            sendMessage(\achbotMessage('legacy_messages.discount_not_for_service'), null, 'HTML');
        } else {
            sendMessage(\achbotMessage('legacy_messages.not_valid_discount_code'), null, 'HTML');
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
        if (!empty(\achbotMessage('legacy_messages.discount_min_price'))) {
            $msg = str_replace("MINPRICE", number_format($minPrice) . \achbotMessage('payment.discounts.apply_discount_to_pay.text.2cc98f7f7c'), \achbotMessage('legacy_messages.discount_min_price'));
            sendMessage($msg);
        } else {
            sendMessage(\achbotMessage('legacy_messages.not_valid_discount_code'), null, 'HTML');
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
        if (!empty(\achbotMessage('legacy_messages.discount_user_limit'))) {
            sendMessage(\achbotMessage('legacy_messages.discount_user_limit'), null, 'HTML');
        } else {
            sendMessage(\achbotMessage('legacy_messages.not_valid_discount_code'), null, 'HTML');
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

    // Revalidate both source rows under the same owner/checkout lock.
    // A failed or concurrent redemption must never consume a discount
    // independently of the original unpaid invoice.
    require_once __DIR__.'/CheckoutAdmission.php';
    $started=false;
    try {
        achbotCheckoutRequireSchema($connection);
        if (!$connection->begin_transaction()) throw new RuntimeException('discount_begin_failed');
        $started=true;
        achbotPayUxLockDraftOwner($connection,(int)$from_id);
        $current=achbotProviderRow($connection,'SELECT * FROM pays WHERE id=? FOR UPDATE','i',[(int)$rowId]);
        if (!$current || $current!==$payInfo || $current['checkout_operation_id']!==null ||
            !achbotPayUxCurrentDraft($current) ||
            !achbotPayUxUnclaimedDraftUsable($connection,$current,true) ||
            achbotProviderRow($connection,'SELECT id FROM payment_attempts WHERE pay_row_id=? LIMIT 1 FOR UPDATE','i',[(int)$rowId]) ||
            achbotProviderRow($connection,'SELECT id FROM service_effects WHERE pay_row_id=? LIMIT 1 FOR UPDATE','i',[(int)$rowId]))
            throw new DomainException('discount_draft_closed');
        $locked=achbotProviderRow($connection,'SELECT * FROM discounts WHERE id=? FOR UPDATE','i',[(int)$discountInfo['id']]);
        if (!$locked || $locked!==$discountInfo ||
            ((int)$locked['expire_date']!==0 && (int)$locked['expire_date']<=time()) ||
            (int)$locked['is_active']!==1)
            throw new DomainException('discount_changed');
        if (!achbotDiscountAudienceAllowed($connection,$locked,(int)$from_id,true))
            throw new DomainException('discount_audience_changed');
        if (!achbotDiscountTargetAllowed($connection,$locked,(int)$current['plan_id'],true))
            throw new DomainException('discount_target_changed');
        $usedBy[] = $from_id;
        $encodeUsedBy = json_encode($usedBy, JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $discountValue=$type==='percent'?(int)floor($price*$amount/100):$amount;
        if ($maxDiscount>0) $discountValue=min($maxDiscount,$discountValue);
        $discountValue=max(0,min($price,$discountValue));
        $newPrice=$price-$discountValue;
        $query=$count!=-1
            ? 'UPDATE discounts SET expire_count=expire_count-1,used_by=? WHERE id=?'
            : 'UPDATE discounts SET used_by=? WHERE id=?';
        $stmt=achbotProviderStatement($connection,$query,'si',[$encodeUsedBy,(int)$discountInfo['id']]);
        $n=$stmt->affected_rows;$stmt->close();
        if ($n!==1) throw new RuntimeException('discount_counter_failed');
        $stmt=achbotProviderStatement($connection,'UPDATE pays SET price=? WHERE id=?','ii',[$newPrice,(int)$rowId]);
        $stmt->close();
        if (!$connection->commit()) throw new RuntimeException('discount_commit_unknown');
        $started=false;
        $price=$newPrice;
    } catch (Throwable $e) {
        if ($started) {try {$connection->rollback();} catch (Throwable $ignored) {}}
        sendMessage(\achbotMessage('legacy_messages.not_valid_discount_code'),null,'HTML');
        return ['success'=>false,'price'=>0,'hash_id'=>'','discount_value'=>0,'discount_text'=>'','payInfo'=>null];
    }

    // پیام به کاربر

    $discountText = number_format($discountValue) . \achbotMessage('payment.discounts.apply_discount_to_pay.text.2cc98f7f7c');

    if (!empty(\achbotMessage('legacy_messages.valid_discount_code'))) {
        sendMessage(str_replace("AMOUNT", $discountText, \achbotMessage('legacy_messages.valid_discount_code')));
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
        \achbotMessage('legacy_messages.used_discount_code')
    );

    // ارسال پیام به ادمین خاص
    achbotNotifyAdmins('sendMessage', ['text'=>$msg,'reply_markup'=>$keys],
        ['report_parse_mode'=>'html']);


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
    global $connection;
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
        ['text'=>\achbotMessage('legacy_buttons.back_button'),'callback_data'=>"managePanel"]
    ];

    return json_encode(['inline_keyboard'=>$keys]);
}

