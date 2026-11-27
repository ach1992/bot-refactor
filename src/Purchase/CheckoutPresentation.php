<?php
declare(strict_types=1);
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {http_response_code(404);exit;}
require_once __DIR__.'/../Finance/CheckoutAdmission.php';
require_once __DIR__.'/../Admin/InstanceContent.php';
require_once __DIR__.'/../Telegram/Conversation.php';

/** Shared payment rows for every current checkout. Only established
 * mode-specific labels and navigation differ; eligibility is decided once
 * by CheckoutAdmission. No transport or monetary effect is owned here. */
function achbotCheckoutPaymentKeyboard(string $mode,string $hash,int $amount,array $methods,string $botUrl): array {
    if (!in_array($mode,['standard','custom','renew','day','volume','topup'],true)) throw new InvalidArgumentException('checkout_mode_invalid');
    if ($amount===0 && $mode!=='topup') {
        $label=match($mode) {
            'standard','custom'=>achbotMessage('purchase.select_plan.button.8719396e1e'),
            'renew'=>achbotMessage('renewal.free_confirmation.renew'),
            'day'=>achbotMessage('renewal.free_confirmation.day'),
            'volume'=>achbotMessage('renewal.free_confirmation.volume')
        };
        return [[['text'=>$label,'callback_data'=>'pw_'.$hash]]];
    }
    if ($amount<=0) return [];
    $keyboard=[];
    if (in_array('bank_transfer',$methods,true))
        $keyboard[]=[['text'=>achbotMessage('legacy_buttons.cart_to_cart'),'callback_data'=>'pm_b_'.$hash]];
    if (in_array('gift_card',$methods,true))
        $keyboard[]=[['text'=>achbotMessage('legacy_buttons.gift_card'),
            'callback_data'=>'pm_g_'.$hash]];
    if (in_array('manual_usdt',$methods,true) || in_array('manual_trx',$methods,true)) {
        $label=achbotMessage('purchase.catalogue.button.e729457ba2');
        $keyboard[]=[['text'=>$label,'callback_data'=>'mcm_'.$hash]];
    }
    foreach (['nowpayments'=>achbotMessage('legacy_buttons.now_payment_gateway'),
        'zarinpal'=>achbotMessage('legacy_buttons.zarinpal_gateway'),
        'nextpay'=>achbotMessage('legacy_buttons.nextpay_gateway')] as $method=>$label) {
        if (!in_array($method,$methods,true)) continue;
        $url=achbotPayUxHostedLink($method,$hash,$botUrl);
        if ($url!==null) $keyboard[]=[['text'=>$label,'url'=>$url]];
    }
    if ($mode!=='topup' && in_array('wallet',$methods,true)) {
        $label=achbotMessage('legacy_buttons.pay_with_wallet');
        $keyboard[]=[['text'=>$label,'callback_data'=>'pw_'.$hash]];
    }
    return $keyboard;
}

function achbotRenderCheckoutDraft(mysqli $db,array $view,string $botUrl,bool $offerDiscount,bool $discountRejectionAlreadyShown=false): bool {
    $pay=$view['pay'];$q=$view['quote'];$plan=$view['plan'];$mode=$view['mode'];
    $hash_id=$pay['hash_id'];$actor=(int)$pay['user_id'];$amount=(int)$pay['price'];
    $renew=$mode==='renew';
    $price=$amount===0?($renew?achbotMessage('renewal.extensions.price.dda7ff2974'):achbotMessage('purchase.catalogue.text.dda7ff2974')):
        number_format($amount).($renew?achbotMessage('renewal.extensions.text.2cc98f7f7c'):achbotMessage('purchase.catalogue.text.bfb7221456'));
    $methods=$view['payment_methods'];
    $noMethod=$amount>0 && !$methods;
    $keyboard=achbotCheckoutPaymentKeyboard($mode,$hash_id,$amount,$methods,$botUrl);
    if (($offerDiscount || $noMethod || $discountRejectionAlreadyShown) && $amount>0 && empty($view['context']['discount_applied']))
        $keyboard[]=[['text'=>achbotMessage('purchase.catalogue.button.05c3a1e3f4'),'callback_data'=>'haveDiscountCheckout_'.$pay['id']]];
    if ($mode==='standard') {
        $buyType=(int)$pay['agent_bought']===1?((int)$pay['agent_count']>0?'much':'one'):'none';
        $back='selectCategory'.$view['context']['category_id'].'_'.$q['server_id'].'_'.$buyType;
        $keyboard[]=[['text'=>achbotMessage('legacy_buttons.back_to_main'),'callback_data'=>$back]];
    } else {
        $keyboard[]=[['text'=>achbotMessage('legacy_buttons.cancel'),'callback_data'=>'dc_'.$hash_id]];
    }
    $notice=achbotMessage('payment.discounts.no_monetary_method');
    if ($renew) $msg=$amount===0?achbotMessage('renewal.free_confirmation.prompt'):
        (achbotMessageHtml('renewal.renewal_checkout.message.24ae8c4399',['amount'=>number_format($amount)]) . ($noMethod && !$discountRejectionAlreadyShown?"\n\n".$notice:''));
    else {
        // Catalog titles and descriptions are literal stored data, not HTML
        // templates. Preserve the separately authored instance note and
        // replace tokens in one pass (never re-expand a dynamic value).
        $safe=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $values=['PLAN-NAME'=>$safe($view['category']['title'].' '.$plan['title']),
            'DESCRIPTION'=>$safe($plan['descr']),'PRICE'=>$price];
        $template=achbotMessage('legacy_messages.buy_subscription_detail');
        if ($mode==='standard' && (int)$pay['agent_count']>0) {
            $template=achbotMessage('legacy_messages.buy_much_subscription_detail');
            $values['ACCOUNT-COUNT']=(string)$q['quantity'];
            $values['TOTAL-PRICE']=$price;
            $values['PRICE']=number_format($amount/$q['quantity']).achbotMessage('purchase.select_plan.text.2cc98f7f7c');
        } elseif ($mode==='custom') {
            $values['VOLUME']=(string)$q['volume'];$values['DAYS']=(string)$q['days'];
        }
        $msg=rtrim(strtr($template,$values))."\n\n".
            achbotInstanceMessage((array)($GLOBALS['botState']??[]),'purchase_note',$actor);
    }
    if ($noMethod && !$renew && !$discountRejectionAlreadyShown) $msg.="\n\n".$notice;
    $sent=sendMessage($msg,json_encode(['inline_keyboard'=>$keyboard]),'HTML');
    achbotPayUxTrackByHash($db,(string)$hash_id,$actor,$sent,'selector');
    return \AchBot\Telegram\Conversation\acknowledgedMessage($sent,$actor)!==null;
}

function achbotRenderNewCheckoutDraft(mysqli $db,int $id,int $actor,array $states,string $botUrl): bool {
    $view=achbotCheckoutDraftView($db,$id,$actor,$states);
    if ($view===null) return false;
    return achbotRenderCheckoutDraft($db,$view,$botUrl,true);
}
