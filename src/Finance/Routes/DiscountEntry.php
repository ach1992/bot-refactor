<?php
/** One current discount flow for the original owned checkout draft. */
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {http_response_code(404);exit;}
require_once __DIR__.'/../../Purchase/CheckoutPresentation.php';
require_once __DIR__.'/../Discounts.php';
require_once __DIR__.'/../../Telegram/Conversation.php';

if (preg_match('/\AhaveDiscountCheckout_([1-9][0-9]*)\z/D',(string)$data,$match)) {
    if (!\AchBot\Telegram\Conversation\source($update,(int)$from_id,true)) return null;
    $view=achbotCheckoutDraftView($connection,(int)$match[1],(int)$from_id,$botState);
    if ($view===null || (int)$view['pay']['price']<=0 || !empty($view['context']['discount_applied'])) {
        alert(achbotMessage('legacy_messages.not_valid_discount_code'),true);
        return null;
    }
    if (!\AchBot\Telegram\Conversation\transitionEditor($connection,(int)$from_id,$userInfo,'discountCheckout_'.$match[1],update:$update)) return null;
    $prompt=sendMessage(achbotMessage('legacy_messages.insert_discount_code'),$cancelKey,'HTML');
    if (\AchBot\Telegram\Conversation\acknowledgedMessage($prompt,(int)$from_id)!==null)
        try {delMessage();}catch(Throwable $ignored) {}
    return null;
}
if (preg_match('/\AdiscountCheckout_([1-9][0-9]*)\z/D',(string)($userInfo['step']??''),$match) &&
    isset($update->message->text) && !isset($update->callback_query) &&
    $text!==achbotMessage('legacy_buttons.cancel')) {
    $id=(int)$match[1];$actor=(int)$from_id;
    if (!\AchBot\Telegram\Conversation\source($update,$actor,false)) return null;
    $result=achbotDiscountRedeem($connection,$id,$text,$actor,$botState,$userInfo);
    $reason=$result['reason'];
    if ($reason==='stale_editor') return null;
    if ($reason==='unconfirmed') {
        sendMessage(achbotMessage('bot.bootstrap.unavailable'),null,'HTML');
        return null;
    }
    $unit=achbotMessage('payment.discounts.apply_discount_to_pay.text.2cc98f7f7c');
    $discountText=$result['success']?number_format($result['discount_value']).$unit:'';
    $notice=match($reason) {
        'redeemed'=>strtr(achbotMessage('legacy_messages.valid_discount_code'),['AMOUNT'=>$discountText]),
        'discount_not_found'=>achbotMessage('legacy_messages.discount_not_found'),
        'discount_not_for_user'=>achbotMessage('legacy_messages.discount_not_for_user'),
        'discount_not_for_service'=>achbotMessage('legacy_messages.discount_not_for_service'),
        'discount_min_price'=>strtr(achbotMessage('legacy_messages.discount_min_price'),
            ['MINPRICE'=>number_format($result['minimum']).$unit]),
        'discount_user_limit'=>achbotMessage('legacy_messages.discount_user_limit'),
        'discount_no_monetary_method'=>achbotMessage('payment.discounts.no_monetary_method'),
        default=>achbotMessage('legacy_messages.not_valid_discount_code'),
    };
    \AchBot\Telegram\Conversation\removeReplyKeyboard($notice);
    if ($result['view']!==null)
        achbotRenderCheckoutDraft($connection,$result['view'],$botUrl,false,$reason==='discount_no_monetary_method');
    if ($result['success']) {
        $safe=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $msg=strtr(achbotMessage('legacy_messages.used_discount_code'),[
            'USERID'=>(string)$actor,'USERNAME'=>$safe($username),'NAME'=>$safe($first_name),
            'AMOUNT'=>$discountText,'DISCOUNTCODE'=>$safe($text)]);
        $keys=json_encode(['inline_keyboard'=>[[[
            'text'=>achbotMessage('payment.discounts.apply_discount_to_pay.button.16e81418a3'),
            'callback_data'=>'wizwizch']]]],JSON_THROW_ON_ERROR);
        try {achbotNotifyAdmins('sendMessage',['text'=>$msg,'reply_markup'=>$keys],['report_parse_mode'=>'html']);}
        catch (Throwable $error) {error_log('achbot_discount_report_deferred pay_id='.$id.' class='.get_class($error));}
    }
    return null;
}
