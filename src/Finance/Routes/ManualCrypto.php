<?php
/**
 * AChBot manual crypto choices. No live chain/provider calls or money effects.
 * Resolve a one-letter network callback to the existing guarded manual receipt
 * route. All legacy token-transfer buttons are converted to the safe selector.
 */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../PaymentLifecycle.php';

// A legacy USDT button is not a choice of chain; require explicit selection.
if (preg_match('/^pay(?:Buy|Custom|Renew|IncreaseWallet|IncreaseDay|Increase)WithManualUsdt([A-Za-z0-9_-]{1,50})$/D',
               $data, $legacyCrypto)) {
    $data = 'mcm_' . $legacyCrypto[1];
}

$isMenu = preg_match('/^mcm_([A-Za-z0-9_-]{1,50})$/D', $data, $menuMatch) === 1;
$isPick = preg_match('/^mc_([bcet])_([A-Za-z0-9_-]{1,50})$/D', $data, $pickMatch) === 1;
if ($isMenu || $isPick) {
    $hashId = $isMenu ? $menuMatch[1] : $pickMatch[2];
    try {
        $stmt=$connection->prepare('SELECT id,user_id,type,state,payid,day,volume FROM pays
            WHERE hash_id=? LIMIT 1');
        $stmt->bind_param('s',$hashId);$stmt->execute();
        $selectedPay=$stmt->get_result()->fetch_assoc();$stmt->close();
        if (!$selectedPay || (int)$selectedPay['user_id'] !== (int)$from_id ||
            $selectedPay['state'] !== 'pending' || (string)($selectedPay['payid'] ?? '') !== '') {
            answerCallbackQuery($callbackId, 'این سفارش در انتظار پرداخت نیست یا متعلق به شما نیست.', true);
            return;
        }
        $stmt=$connection->prepare('SELECT provider,stage,checkout_url FROM payment_attempts
            WHERE pay_row_id=? LIMIT 1');
        $payId=(int)$selectedPay['id'];
        $stmt->bind_param('i',$payId);$stmt->execute();
        $previous=$stmt->get_result()->fetch_assoc();$stmt->close();
        if ($previous && (!in_array((string)$previous['provider'],
                                   ['manual_usdt','manual_trx'],true) ||
                          (string)$previous['stage']!=='pending')) {
            answerCallbackQuery($callbackId, 'این سفارش قبلاً با روشی دیگر ثبت یا وارد بررسی شده است.', true);
            return;
        }
        $options=achbotManualCryptoOptions();
        // A current grant is needed to create a new claim. Existing pinned
        // claims remain visible for receipt/support even if policy changes.
        if (!$previous) {
            foreach ($options as $networkKey=>$candidate) {
                if (!achbotPayUxAudienceAllowed($connection,
                    (string)$candidate['provider'],(int)$from_id))
                    unset($options[$networkKey]);
            }
        }
        if ($previous) {
            $intent=achbotManualIntentDecode((string)$previous['checkout_url'],
                                             (string)$previous['provider']);
            if (!$intent) {
                answerCallbackQuery($callbackId, 'اطلاعات پرداخت قبلی نیازمند بررسی است.', true);
                return;
            }
            // Do not substitute a newly configured wallet for a pinned one.
            $originalNetwork=(string)($intent['network'] ?? '');
            $tag=match ($originalNetwork) {
                'BEP20'=>'b','TRC20'=>'c','ERC20'=>'e','TRON'=>'t',default=>null
            };
            if ($tag===null) {
                answerCallbackQuery($callbackId, 'اطلاعات پرداخت قبلی نیازمند بررسی است.', true);
                return;
            }
            $options=[$tag=>['provider'=>(string)$previous['provider'],
                             'network'=>$originalNetwork,
                             'label'=>($tag==='t'?'🔴 TRX — ':'💎 USDT — ').$originalNetwork]];
        }
        if ($isMenu) {
            if (!$options) {
                answerCallbackQuery($callbackId, 'هیچ شبکه‌ای با آدرس و نرخ معتبر فعال نیست.', true);
                return;
            }
            $keyboard=[];
            foreach ($options as $tag=>$option) {
                $keyboard[]=[['text'=>$option['label'],
                              'callback_data'=>'mc_'.$tag.'_'.$hashId]];
            }
            $keyboard[]=[['text'=>'🏠 منوی اصلی','callback_data'=>'mainMenu']];
            $message="💎 <b>پرداخت رمزارزی دستی</b>\n\n".
                "ارز و شبکه انتقال را انتخاب کنید. فقط به آدرس همان شبکه واریز کنید.\n".
                "ارسال رسید به معنی تأیید پرداخت نیست؛ بررسی مدیر لازم است.";
            answerCallbackQuery($callbackId);
            // Some older callback messages may be media, not editable text.
            $edited=editText($message_id,$message,
                             json_encode(['inline_keyboard'=>$keyboard],
                                         JSON_UNESCAPED_UNICODE),'HTML',$from_id);
            if (!is_object($edited) || empty($edited->ok)) {
                sendMessage($message,json_encode(['inline_keyboard'=>$keyboard],
                            JSON_UNESCAPED_UNICODE),'HTML',$from_id);
            }
            return;
        }
        $option=$options[$pickMatch[1]] ?? null;
        if (!$option) {
            answerCallbackQuery($callbackId, 'این شبکه برای سفارش در دسترس نیست.', true);
            return;
        }
        $type=(string)$selectedPay['type'];
        $target=match(true) {
            $type==='INCREASE_WALLET' => 'payIncreaseWalletWithManualUsdt',
            $type==='RENEW_ACCOUNT' => 'payRenewWithManualUsdt',
            $type==='BUY_SUB' && ((int)$selectedPay['day']>0 || (int)$selectedPay['volume']>0)
                => 'payCustomWithManualUsdt',
            $type==='BUY_SUB', $type==='RENEW_SCONFIG' => 'payBuyWithManualUsdt',
            preg_match('/^INCREASE_DAY_[0-9]+_[0-9]+$/D',$type)===1
                => 'payIncreaseDayWithManualUsdt',
            preg_match('/^INCREASE_VOLUME_[0-9]+_[0-9]+$/D',$type)===1
                => 'payIncreaseWithManualUsdt',
            default => null
        };
        if ($target===null) {
            answerCallbackQuery($callbackId, 'این نوع سفارش از این پرداخت پشتیبانی نمی‌کند.', true);
            return;
        }
        $GLOBALS['achbotManualCryptoChoice']=$option['network'];
        $data=$target.$hashId;
        // A verified mc_[bcet]_<hash> Telegram callback (<=55 bytes) can
        // expand into a longer internal route action. Never relax the 64-byte
        // external callback limit without this exact trusted dispatch marker.
        $GLOBALS['achbotManualCryptoVerifiedDispatch']=$data;
        answerCallbackQuery($callbackId);
        // The existing purchase/renewal/top-up route owns the one-attempt
        // transaction, quote, receipt and guarded human approval.
    } catch (Throwable $error) {
        answerCallbackQuery($callbackId, 'وضعیت سفارش قابل خواندن نیست؛ لطفاً بعداً تلاش کنید.', true);
        return;
    }
}
