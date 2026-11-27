<?php
/** One current discount flow for the original owned checkout draft. */
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {http_response_code(404);exit;}
require_once __DIR__.'/../../Purchase/CheckoutPresentation.php';
require_once __DIR__.'/../Discounts.php';
require_once __DIR__.'/../../Telegram/Conversation.php';

if (preg_match('/\AhaveDiscountCheckout_([1-9][0-9]*)\z/D',(string)$data,$match)) {
    $frame=$update->callback_query??null;
    if (($frame->from->id??null)!==(int)$from_id || ($frame->message->chat->id??null)!==(int)$from_id ||
        ($frame->message->chat->type??null)!=='private') return null;
    $view=achbotCheckoutDraftView($connection,(int)$match[1],(int)$from_id,$botState);
    if ($view===null || (int)$view['pay']['price']<=0 || !empty($view['context']['discount_applied'])) {
        alert(achbotMessage('legacy_messages.not_valid_discount_code'),true);
        return null;
    }
    if (!\AchBot\Telegram\Conversation\transitionEditor($connection,(int)$from_id,$userInfo,'discountCheckout_'.$match[1])) return null;
    $prompt=sendMessage(achbotMessage('legacy_messages.insert_discount_code'),$cancelKey,'HTML');
    if (\AchBot\Telegram\Conversation\acknowledgedMessage($prompt,(int)$from_id)!==null)
        try {delMessage();}catch(Throwable $ignored) {}
    return null;
}
if (preg_match('/\AdiscountCheckout_([1-9][0-9]*)\z/D',(string)($userInfo['step']??''),$match) &&
    isset($update->message->text) && !isset($update->callback_query) &&
    $text!==achbotMessage('legacy_buttons.cancel')) {
    $id=(int)$match[1];$actor=(int)$from_id;
    if (($update->message->from->id??null)!==$actor || ($update->message->chat->id??null)!==$actor ||
        ($update->message->chat->type??null)!=='private') return null;
    $view=achbotCheckoutDraftView($connection,$id,$actor,$botState);
    if ($view===null || (int)$view['pay']['price']<=0 || !empty($view['context']['discount_applied'])) {
        if (!\AchBot\Telegram\Conversation\transitionEditor($connection,$actor,$userInfo)) return null;
        sendMessage(achbotMessage('legacy_messages.not_valid_discount_code'),$removeKeyboard,'HTML');
        return null;
    }
    $result=applyDiscountToPay($connection,$id,$text,$actor,$username,$first_name,$admin,$botState);
    if (!\AchBot\Telegram\Conversation\transitionEditor($connection,$actor,$userInfo)) return null;
    $view=$result['view']??achbotCheckoutDraftView($connection,$id,$actor,$botState);
    $noMethod=($result['reason']??null)==='discount_no_monetary_method';
    if ($view!==null) {
        \AchBot\Telegram\Conversation\removeReplyKeyboard(achbotMessage('legacy_messages.receving_information'));
        achbotRenderCheckoutDraft($connection,$view,$botUrl,false,$noMethod);
    } elseif ($noMethod) sendMessage(achbotMessage('payment.discounts.no_monetary_method'),$removeKeyboard,'HTML');
    else sendMessage(achbotMessage('legacy_messages.not_valid_discount_code'),$removeKeyboard,'HTML');
    return null;
}
