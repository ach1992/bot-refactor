<?php
/** Current discount redemption and shared catalogue policy. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../messages/loader.php'; // ACHBOT_TEXT_CATALOG
require_once __DIR__.'/../Purchase/AgentPricing.php';


/** Definitive redemption uses the current locked audience.
 * The existing order-based audiences mean a stored order reference, not
 * the gateway's active-entitlement category. Keep that distinction. */
function achbotDiscountAudienceAllowed(mysqli $db,array $discount,int $userId): bool {
    require_once __DIR__.'/ServiceHistory.php';
    $mode=$discount['audience_type']?:'all';
    if ($mode==='all') return true;
    if ($mode==='user') return (int)$discount['audience_user_id']===$userId;
    if ($mode==='buyers' || $mode==='non_buyers') {
        $count=achbotCustomerHistoryCount($db,$userId,'buy',0,'older',true,time());
        return $count!==null && ($count>0)===($mode==='buyers');
    }
    if ($mode==='agents' || $mode==='non_agents') {
        $user=achbotProviderRow($db,'SELECT is_agent FROM users FORCE INDEX (idx_users_userid_v1) WHERE userid=? FOR UPDATE','i',[$userId]);
        return $user!==null && ((int)$user['is_agent']===1)===($mode==='agents');
    }
    if ($mode==='has_active_order' || $mode==='no_active_order') {
        $order=achbotProviderRow($db,'SELECT id FROM orders_list FORCE INDEX (idx_orders_userid_v1) WHERE userid=? LIMIT 1 FOR UPDATE','i',[$userId]);
        return ($order!==null)===($mode==='has_active_order');
    }
    return false;
}

/** The checkout view already holds the exact current plan row. */
function achbotDiscountTargetAllowed(array $discount,array $plan): bool {
    $mode=$discount['target_type']?:'all';
    $id=(int)$discount['target_id'];
    return match($mode) {
        'all'=>true,
        'plan'=>(int)$plan['id']===$id,
        'server'=>(int)$plan['server_id']===$id,
        'category'=>(int)$plan['catid']===$id,
        default=>false,
    };
}

/** One current redemption transaction. Consumption by another eligible
 * buyer is not itself a conflict. When a conversational editor is supplied,
 * its exact ownership and terminal state share this commit. */
function achbotDiscountRedeem(mysqli $db,int $id,string $code,int $actor,array $states,?array $editor=null): array {
    require_once __DIR__.'/CheckoutAdmission.php';
    require_once __DIR__.'/../Telegram/Conversation.php';
    $begun=false;$result=['success'=>false,'reason'=>'invalid_draft','view'=>null];
    try {
        achbotCheckoutRequireSchema($db);
        achbotPaymentEvidenceRequireAutocommit($db);
        if (!$db->begin_transaction()) throw new RuntimeException('discount_begin_failed');
        $begun=true;
        $ownedEditor=null;
        if ($editor!==null) {
            if (($editor['step']??null)!=='discountCheckout_'.$id)
                throw new DomainException('discount_editor_changed');
            $ownedEditor=\AchBot\Telegram\Conversation\lockEditor($db,$actor,$editor);
        } else achbotPayUxLockDraftOwner($db,$actor);
        $pay=achbotProviderRow($db,'SELECT * FROM pays WHERE id=? AND user_id=? FOR UPDATE','ii',[$id,$actor]);
        if ($pay) {
            try {$view=achbotCheckoutDraftViewLocked($db,$pay,$actor,$states);}
            catch (DomainException $e) {$view=null;}
            if ($view!==null && (int)$pay['price']>0 && empty($view['context']['discount_applied'])) {
                // Locate the eligible code, then lock only its actual PK.
                // Do not lock every row through an unindexed code scan.
                $q=achbotProviderStatement($db,'SELECT id FROM discounts WHERE hash_id=?
                    AND is_active=1 AND (expire_date=0 OR expire_date>UNIX_TIMESTAMP())
                    AND (expire_count=-1 OR expire_count>0) LIMIT 2','s',[$code]);
                $locators=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
                $discount=count($locators)===1?achbotProviderRow($db,
                    'SELECT * FROM discounts WHERE id=? AND hash_id=? FOR UPDATE','is',
                    [(int)$locators[0]['id'],$code]):null;
                $price=(int)$pay['price'];
                $result=['success'=>false,'reason'=>'discount_not_found','price'=>$price,'view'=>$view];
                if ($discount && (int)$discount['is_active']===1 &&
                    ((int)$discount['expire_date']===0 || (int)$discount['expire_date']>time()) &&
                    ((int)$discount['expire_count']===-1 || (int)$discount['expire_count']>0))
                    $result=achbotDiscountApplyLocked($db,$pay,$view,$discount,$actor);
                if (!$result['success'])
                    $result['view']['payment_methods']=array_keys(achbotCheckoutPaymentOptionsLocked($db,$actor,$pay['type'],$price));
            }
        }
        if ($ownedEditor!==null)
            \AchBot\Telegram\Conversation\writeEditorLocked($db,$ownedEditor,'none',null);
        if (!$db->commit()) throw new RuntimeException('discount_commit_unknown');
        $begun=false;
        return $result;
    } catch (Throwable $e) {
        if ($begun) try {$db->rollback();} catch (Throwable $ignored) {}
        if ($e instanceof DomainException && in_array($e->getMessage(),
            ['discount_editor_changed','conversation_editor_changed','conversation_actor_unverified'],true))
            return ['success'=>false,'reason'=>'stale_editor','view'=>null];
        if (!($e instanceof DomainException))
            achbotDiagnosticEvent('achbot.finance.discount_deferred',
                ['pay_id'=>$id,'actor_id'=>$actor]+achbotDiagnosticLocalFailure($e),'warning');
        return ['success'=>false,'reason'=>'unconfirmed','view'=>null];
    }
}

/** Original owner, quote and discount are already locked. No messaging or
 * independent transaction; evaluate current policy, counters and quote. */
function achbotDiscountApplyLocked(mysqli $db,array $pay,array $view,array $discount,int $actor): array {
    $price=(int)$pay['price'];$min=(int)$discount['min_price'];
    $result=['success'=>false,'price'=>$price,'view'=>$view];
    if (!achbotDiscountAudienceAllowed($db,$discount,$actor))
        return $result+['reason'=>'discount_not_for_user'];
    if (!achbotDiscountTargetAllowed($discount,$view['plan']))
        return $result+['reason'=>'discount_not_for_service'];
    if ($min>0 && $price<$min)
        return $result+['reason'=>'discount_min_price','minimum'=>$min];
    $uses=$discount['used_by']===null?[]:json_decode($discount['used_by'],true,64,JSON_THROW_ON_ERROR);
    if (!is_array($uses) || !array_is_list($uses)) throw new RuntimeException('discount_history_invalid');
    $used=0;
    foreach ($uses as $uid) {
        if (!is_int($uid) && !is_string($uid)) throw new RuntimeException('discount_history_invalid');
        if ((string)$uid===(string)$actor) ++$used;
    }
    $limit=(int)$discount['can_use'];
    if ($limit!==-1 && $used>=$limit) return $result+['reason'=>'discount_user_limit'];
    $amount=(int)$discount['amount'];$maximum=(int)$discount['max_discount'];
    $value=$discount['type']==='percent'?achbotPercentageAmount($price,max(0,min(100,$amount))):$amount;
    if ($maximum>0) $value=min($maximum,$value);
    $value=max(0,min($price,$value));$newPrice=$price-$value;
    $methods=array_keys(achbotCheckoutPaymentOptionsLocked($db,$actor,$pay['type'],$newPrice));
    if ($newPrice>0 && !$methods) return $result+['reason'=>'discount_no_monetary_method'];
    $uses[]=$actor;
    $encoded=json_encode($uses,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    $view['context']['discount_applied']=true;
    $context=achbotServiceJson($view['context']);
    $q=achbotProviderStatement($db,(int)$discount['expire_count']!==-1
        ?'UPDATE discounts SET expire_count=expire_count-1,used_by=? WHERE id=?'
        :'UPDATE discounts SET used_by=? WHERE id=?','si',[$encoded,(int)$discount['id']]);
    $n=$q->affected_rows;$q->close();
    if ($n!==1) throw new RuntimeException('discount_counter_failed');
    $q=achbotProviderStatement($db,'UPDATE pays SET price=?,checkout_draft_context=? WHERE id=?','isi',
        [$newPrice,$context,(int)$pay['id']]);$q->close();
    $pay['price']=$newPrice;$pay['checkout_draft_context']=$context;
    $view['pay']=$pay;$view['payment_methods']=$methods;
    return ['success'=>true,'reason'=>'redeemed','price'=>$newPrice,'view'=>$view,'discount_value'=>$value];
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
            ['text' => \achbotMessage('labels.status'),  'callback_data' => "wizwizch"],
            ['text' => \achbotMessage('labels.edit'), 'callback_data' => "wizwizch"],
            ['text' => \achbotMessage('labels.delete'),    'callback_data' => "wizwizch"],
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
                $amountText = number_format($row['amount']) . \achbotMessage('labels.currency_toman_suffix');
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

