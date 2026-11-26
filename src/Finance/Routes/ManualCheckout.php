<?php
/** Shared current manual checkout selection, receipt prompt and evidence route.
 * Runs after the same registration/membership/phone/Home gates as purchase. */
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {http_response_code(404);exit;}
require_once __DIR__.'/../ManualCheckoutPresentation.php';

$action=(string)($data??'');
$receiptStep=(string)($userInfo['step']??'');
$receipt=achbotReceiptStep($receiptStep);
$reserved=preg_match('/\A(?:pm_|mc_|mcm_|rs_|rc_|dc_)/D',$action)===1;
if ($reserved) {
    $source=\AchBot\Telegram\Conversation\source($update??null,(int)$from_id,true);
    if (!$source || $source->message_id!==(int)$message_id ||
        ($update->callback_query->data??null)!==$action) return;
}
if (preg_match('/\A(pm_([bg])|rs|rc|dc)_([A-Za-z0-9_-]{1,50})\z/D',$action,$manual)) {
    $actor=(int)$from_id;$hash=$manual[3];
    try {
        $pay=achbotProviderRow($connection,'SELECT * FROM pays WHERE hash_id=? AND user_id=?','si',[$hash,$actor]);
        if (!$pay) {alert(achbotMessage('payment.checkout.alert.d06bc731eb'),true);return;}
        if ($manual[1]==='rs') {
            $ref=achbotPayUxRecoveryRef($connection,(int)$pay['id'],$actor,(int)$message_id,$update);
            if (!$ref || !in_array($ref['purpose'],['invoice','status','receipt'],true)) return;
            achbotReceiptOpenPrompt($connection,$pay,$actor,$receiptStep);
        } elseif ($manual[1]==='rc') {
            // Only this current prompt owns cancellation, not the invoice.
            if ($receipt===null || $receipt['hash']!==$hash ||
                $receipt['prompt']!==(int)$message_id ||
                !achbotReceiptStepChange($connection,$actor,$receiptStep,'none')) return;
            answerCallbackQuery($callbackId);
            \AchBot\Telegram\Conversation\replaceTransient($actor,(int)$message_id,
                achbotMessage('legacy_messages.operation_canceled')."\n\n".achbotCurrentMessage('reached_main_menu'),getMainKeys());
        } elseif ($manual[1]==='dc') {
            // Retire before the transport, under the same lease as Cron.
            // Even an unknown edit response cannot let Cron rewrite Home.
            $done=achbotPayUxWithLease($connection,(int)$pay['id'],static function() use ($connection,$pay,$actor,$message_id,$update,$receipt,$receiptStep,$hash): bool {
                $ref=achbotPayUxSelectorRef($connection,(int)$pay['id'],$actor,(int)$message_id,$update);
                if (!$ref || !achbotPayUxRetire($connection,$ref,'navigation')) return false;
                $body=achbotMessage('legacy_messages.operation_canceled')."\n\n".achbotCurrentMessage('reached_main_menu');
                \AchBot\Telegram\Conversation\replaceTransient($actor,(int)$message_id,$body,getMainKeys());
                if (($receipt['hash']??null)===$hash && achbotReceiptStepChange($connection,$actor,$receiptStep,'none') &&
                    $receipt['prompt']>0 && $receipt['prompt']!==(int)$message_id)
                    delMessage($receipt['prompt'],$actor);
                return true;
            },false);
            if ($done) answerCallbackQuery($callbackId);
        } else {
            achbotManualPresentInvoice($connection,$pay,$actor,$manual[2]==='b'?'bank_transfer':'gift_card',null,(int)$message_id);
        }
    } catch (Throwable $error) {
        achbotDiagnosticEvent('achbot.finance.manual_checkout_presentation_deferred',
            ['actor_id'=>$actor]+achbotDiagnosticLocalFailure($error),'warning');
        alert(achbotMessage('payment.checkout.alert.1a885aaca4'),true);
    }
    return;
}
$isMenu = preg_match('/^mcm_([A-Za-z0-9_-]{1,50})$/D', $action, $menuMatch) === 1;
$isPick = preg_match('/^mc_([bcet])_([A-Za-z0-9_-]{1,50})$/D', $action, $pickMatch) === 1;
if ($isMenu || $isPick) {
    $hashId = $isMenu ? $menuMatch[1] : $pickMatch[2];
    try {
        $stmt=$connection->prepare('SELECT * FROM pays
            WHERE hash_id=? LIMIT 1');
        $stmt->bind_param('s',$hashId);$stmt->execute();
        $selectedPay=$stmt->get_result()->fetch_assoc();$stmt->close();
        if (!$selectedPay || (int)$selectedPay['user_id'] !== (int)$from_id ||
            $selectedPay['state'] !== 'pending' || (string)($selectedPay['payid'] ?? '') !== '') {
            answerCallbackQuery($callbackId, \achbotMessage('manual_payment.manual_crypto.alert.d0d07ef447'), true);
            return;
        }
        if (!achbotPayUxCurrentDraft($selectedPay)) {
            alert(achbotMessage('manual_payment.manual_crypto.alert.d0d07ef447'),true);
            return;
        }
        $stmt=$connection->prepare('SELECT provider,stage,checkout_url,wallet_invoice_sha256,invoice_expires_at FROM payment_attempts
            WHERE pay_row_id=? LIMIT 1');
        $payId=(int)$selectedPay['id'];
        $stmt->bind_param('i',$payId);$stmt->execute();
        $previous=$stmt->get_result()->fetch_assoc();$stmt->close();
        if ($previous && (!achbotCurrentReceiptPin($previous) || !in_array((string)$previous['provider'],
                                   ['manual_usdt','manual_trx'],true) ||
                          (string)$previous['stage']!=='pending')) {
            answerCallbackQuery($callbackId, \achbotMessage('manual_payment.manual_crypto.alert.4d788a75be'), true);
            return;
        }
        require_once __DIR__.'/../CheckoutAdmission.php';
        $options=$previous?[]:achbotManualCryptoOptions(
            achbotCheckoutDraftPaymentOptions($connection,$payId,(int)$from_id));
        if ($previous) {
            $intent=achbotManualIntentDecode((string)$previous['checkout_url'],
                                             (string)$previous['provider']);
            if (!$intent) {
                answerCallbackQuery($callbackId, \achbotMessage('manual_payment.manual_crypto.alert.5ce97176e4'), true);
                return;
            }
            // Do not substitute a newly configured wallet for a pinned one.
            $originalNetwork=(string)($intent['network'] ?? '');
            $tag=achbotManualCryptoTag($originalNetwork);
            if ($tag===null) {
                answerCallbackQuery($callbackId, \achbotMessage('manual_payment.manual_crypto.alert.5ce97176e4'), true);
                return;
            }
            $options=[$tag=>['provider'=>(string)$previous['provider'],
                             'network'=>$originalNetwork,
                             'label'=>($tag==='t'?\achbotMessage('manual_payment.manual_crypto.t_r_x.a23d6fad8d'):\achbotMessage('manual_payment.receipt_helpers.manual_crypto_options.u_s_d_t.1974a6095a')).$originalNetwork]];
        }
        if ($isMenu) {
            if (!$options) {
                answerCallbackQuery($callbackId, \achbotMessage('manual_payment.manual_crypto.alert.26fc4467a8'), true);
                return;
            }
            $keyboard=[];
            foreach ($options as $tag=>$option) {
                $keyboard[]=[['text'=>$option['label'],
                              'callback_data'=>'mc_'.$tag.'_'.$hashId]];
            }
            $keyboard[]=[['text'=>\achbotMessage('manual_payment.manual_crypto.button.1121ffbf88'),'callback_data'=>'dc_'.$hashId]];
            $message=\achbotMessage('manual_payment.manual_crypto.message.d05b7cfc7d').
                \achbotMessage('manual_payment.manual_crypto.text.0a49531de8').
                \achbotMessage('manual_payment.manual_crypto.text.ee4ae4b0c7');
            $done=achbotPayUxWithLease($connection,$payId,static function() use ($connection,$payId,$from_id,$message_id,$update,$message,$keyboard): bool {
                $ref=achbotPayUxSelectorRef($connection,$payId,(int)$from_id,(int)$message_id,$update);
                if (!$ref) return false;
                $keys=json_encode(['inline_keyboard'=>$keyboard],JSON_UNESCAPED_UNICODE);
                $result=achbotPayUxApplyProjection($connection,$ref,$message,$keys);
                if ($result==='retired') {
                    $sent=sendMessage($message,$keys,'HTML',(int)$from_id);
                    achbotPayUxTrackMessage($connection,$payId,(int)$from_id,$sent,'selector');
                }
                return true;
            },false);
            if ($done) answerCallbackQuery($callbackId);
            return;
        }
        $option=$options[$pickMatch[1]] ?? null;
        if (!$option) {
            answerCallbackQuery($callbackId, \achbotMessage('manual_payment.manual_crypto.alert.93decba032'), true);
            return;
        }
        achbotManualPresentInvoice($connection,$selectedPay,(int)$from_id,$option['provider'],$option['network'],(int)$message_id);
        return;
    } catch (Throwable $error) {
        answerCallbackQuery($callbackId, \achbotMessage('manual_payment.manual_crypto.alert.5216f25f85'), true);
        return;
    }
}

if ($reserved) return; // Malformed actions in this namespace cannot fall through.

// An unrelated callback or navigation input is never receipt evidence.
if ($receipt!==null && $action==='' &&
    (string)($text??'')!==achbotMessage('legacy_buttons.cancel') &&
    \AchBot\Telegram\Conversation\source($update??null,(int)$from_id,false)) {
    $pay=achbotProviderRow($connection,'SELECT * FROM pays WHERE hash_id=? AND user_id=?',
        'si',[$receipt['hash'],(int)$from_id]);
    if ($pay) achbotReceiptSubmit($connection,$pay,(int)$from_id,$userInfo,$receiptStep,$update);
    return;
}
return 1;
