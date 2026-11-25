<?php
declare(strict_types=1);
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {http_response_code(404);exit;}
require_once __DIR__.'/../Finance/CheckoutAdmission.php';

/** Shared payment rows for every current checkout. Only established
 * mode-specific labels and navigation differ; eligibility is decided once
 * by CheckoutAdmission. No transport or monetary effect is owned here. */
function achbotCheckoutPaymentKeyboard(string $mode,string $hash,int $amount,array $methods,string $botUrl): array {
    $prefix=achbotCheckoutCallbackPrefix($mode);
    if ($prefix===null) throw new InvalidArgumentException('checkout_mode_invalid');
    if ($amount===0 && $mode!=='topup') {
        $label=match($mode) {
            'standard','custom'=>achbotMessage('purchase.select_plan.button.8719396e1e'),
            'renew'=>achbotMessage('renewal.free_confirmation.renew'),
            'day'=>achbotMessage('renewal.free_confirmation.day'),
            'volume'=>achbotMessage('renewal.free_confirmation.volume')
        };
        return [[['text'=>$label,'callback_data'=>$prefix.'WithWallet'.$hash]]];
    }
    if ($amount<=0) return [];
    $renew=$mode==='renew';
    $price=($renew?(string)$amount:' '.number_format($amount)).achbotMessage('renewal.extensions.text.2cc98f7f7c');
    $keyboard=[];
    if (in_array('bank_transfer',$methods,true))
        $keyboard[]=[['text'=>$renew?achbotMessage('renewal.renewal_checkout.button.5b3252c790',['price'=>$price]):
            achbotMessage('legacy_buttons.cart_to_cart').($mode==='volume'?$price:''),'callback_data'=>$prefix.'WithCartToCart'.$hash]];
    if (in_array('gift_card',$methods,true))
        $keyboard[]=[['text'=>$renew?achbotMessage('renewal.renewal_checkout.button.cf580700a3',['price'=>$price]):achbotMessage('legacy_buttons.gift_card'),
            'callback_data'=>$prefix.'WithGiftCard'.$hash]];
    if (in_array('manual_usdt',$methods,true) || in_array('manual_trx',$methods,true)) {
        $label=match($mode) {
            'renew'=>achbotMessage('renewal.renewal_checkout.button.73f7c8deeb',['price'=>$price]),
            'day','volume'=>achbotMessage('renewal.extensions.button.e729457ba2'),
            'topup'=>achbotMessage('wallet.wallet_start.start_wallet_payment.button.e729457ba2'),
            default=>achbotMessage('purchase.catalogue.button.e729457ba2')
        };
        $keyboard[]=[['text'=>$label,'callback_data'=>'mcm_'.$hash]];
    }
    foreach (['nowpayment'=>['nowpayments',achbotMessage('legacy_buttons.now_payment_gateway')],
        'zarinpal'=>['zarinpal',achbotMessage('legacy_buttons.zarinpal_gateway')],
        'nextpay'=>['nextpay',achbotMessage('legacy_buttons.nextpay_gateway')]] as $gateway=>[$method,$label]) {
        if (!in_array($method,$methods,true)) continue;
        $button=['text'=>$label];
        if ($renew) $button['callback_data']='renewPay_'.$gateway.'_'.$hash;
        else $button['url']=$botUrl.'pay/checkout.php?'.$gateway.'&hash_id='.$hash;
        $keyboard[]=[$button];
    }
    if ($mode!=='topup' && in_array('wallet',$methods,true)) {
        $label=match($mode) {
            'renew'=>achbotMessage('renewal.renewal_checkout.button.0eed1dd8e8',['price'=>$price]),
            'volume'=>achbotMessage('renewal.extensions.button.5adbd64a32').$price,
            default=>achbotMessage('legacy_buttons.pay_with_wallet')
        };
        $keyboard[]=[['text'=>$label,'callback_data'=>$prefix.'WithWallet'.$hash]];
    }
    return $keyboard;
}

function achbotRenderCheckoutDraft(mysqli $db,array $view,string $botUrl,bool $offerDiscount,bool $discountRejectedNoMethod=false): void {
    $pay=$view['pay'];$q=$view['quote'];$plan=$view['plan'];$mode=$view['mode'];
    $hash_id=$pay['hash_id'];$actor=(int)$pay['user_id'];$amount=(int)$pay['price'];
    $renew=$mode==='renew';
    $price=$amount===0?($renew?achbotMessage('renewal.extensions.price.dda7ff2974'):achbotMessage('purchase.catalogue.text.dda7ff2974')):
        ($renew?(string)$amount:number_format($amount)).($renew?achbotMessage('renewal.extensions.text.2cc98f7f7c'):achbotMessage('purchase.catalogue.text.bfb7221456'));
    $methods=$view['payment_methods'];
    $noMethod=$amount>0 && !$methods;
    $keyboard=achbotCheckoutPaymentKeyboard($mode,$hash_id,$amount,$methods,$botUrl);
    if (($offerDiscount || $noMethod || $discountRejectedNoMethod) && $amount>0 && empty($view['context']['discount_applied']))
        $keyboard[]=[['text'=>($renew?achbotMessage('renewal.renewal_checkout.button.05c3a1e3f4'):achbotMessage('purchase.catalogue.button.05c3a1e3f4')),'callback_data'=>'haveDiscountCheckout_'.$pay['id']]];
    if ($mode==='standard') {
        $buyType=(int)$pay['agent_bought']===1?((int)$pay['agent_count']>0?'much':'one'):'none';
        $back='selectCategory'.$view['context']['category_id'].'_'.$q['server_id'].'_'.$buyType;
        $keyboard[]=[['text'=>achbotMessage('legacy_buttons.back_to_main'),'callback_data'=>$back]];
    } else {
        $keyboard[]=[['text'=>achbotMessage('legacy_buttons.cancel'),'callback_data'=>'mainMenu']];
    }
    $notice=achbotMessage('payment.discounts.no_monetary_method');
    if ($renew) $msg=$amount===0?achbotMessage('renewal.free_confirmation.prompt'):
        ($noMethod?$notice:achbotMessage('renewal.renewal_checkout.message.24ae8c4399'));
    else {
        $name=$view['category']['title'].' '.$plan['title'];$desc=$plan['descr'];
        if ($mode==='standard' && (int)$pay['agent_count']>0) {
            $eachPrice=number_format($amount/$q['quantity']).achbotMessage('purchase.select_plan.text.2cc98f7f7c');
            $msg=str_replace(['ACCOUNT-COUNT','TOTAL-PRICE','PLAN-NAME','PRICE','DESCRIPTION'],
                [$q['quantity'],$price,$name,$eachPrice,$desc],achbotCurrentMessage('buy_much_subscription_detail'));
        } elseif ($mode==='custom') {
            $msg=str_replace(['VOLUME','DAYS','PLAN-NAME','PRICE','DESCRIPTION'],
                [$q['volume'],$q['days'],$name,$price,$desc],achbotCurrentMessage('buy_subscription_detail'));
        } else {
            $msg=str_replace(['PLAN-NAME','PRICE','DESCRIPTION'],[$name,$price,$desc],achbotCurrentMessage('buy_subscription_detail'));
        }
    }
    if (($noMethod && !$renew) || ($discountRejectedNoMethod && !$noMethod)) $msg.="\n\n".$notice;
    $sent=sendMessage($msg,json_encode(['inline_keyboard'=>$keyboard]),'HTML');
    achbotPayUxTrackByHash($db,(string)$hash_id,$actor,$sent);
}

function achbotRenderNewCheckoutDraft(mysqli $db,int $id,int $actor,array $states,string $botUrl): bool {
    $view=achbotCheckoutDraftView($db,$id,$actor,$states);
    if ($view===null) return false;
    achbotRenderCheckoutDraft($db,$view,$botUrl,true);
    return true;
}
