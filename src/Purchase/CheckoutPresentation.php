<?php
declare(strict_types=1);
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {http_response_code(404);exit;}
require_once __DIR__.'/../Finance/CheckoutAdmission.php';

function achbotRenderCheckoutDraft(mysqli $db,array $view,array $states,string $botUrl,bool $offerDiscount): void {
    $pay=$view['pay'];$q=$view['quote'];$plan=$view['plan'];$mode=$view['mode'];
    $hash_id=$pay['hash_id'];$actor=(int)$pay['user_id'];$amount=(int)$pay['price'];
    $renew=$mode==='renew';$prefix=match($mode){'standard'=>'payBuy','custom'=>'payCustom','renew'=>'payRenew'};
    $price=$amount===0?($renew?achbotMessage('renewal.extensions.price.dda7ff2974'):achbotMessage('purchase.catalogue.text.dda7ff2974')):
        ($renew?(string)$amount:number_format($amount)).($renew?achbotMessage('renewal.extensions.text.2cc98f7f7c'):achbotMessage('purchase.catalogue.text.bfb7221456'));
    $keyboard=[];
    if ($amount>0) {
        if (achbotManualEnabled('bank_transfer') && achbotPayUxAudienceAllowed($db,'bank_transfer',$actor))
            $keyboard[]=[['text'=>$renew?achbotMessage('renewal.renewal_checkout.button.5b3252c790',['price'=>$price]):achbotMessage('legacy_buttons.cart_to_cart'),'callback_data'=>$prefix.'WithCartToCart'.$hash_id]];
        if (achbotManualEnabled('gift_card') && achbotPayUxAudienceAllowed($db,'gift_card',$actor))
            $keyboard[]=[['text'=>$renew?achbotMessage('renewal.renewal_checkout.button.cf580700a3',['price'=>$price]):achbotMessage('legacy_buttons.gift_card'),'callback_data'=>$prefix.'WithGiftCard'.$hash_id]];
        if (achbotManualCryptoEnabled($actor) && (achbotPayUxAudienceAllowed($db,'manual_usdt',$actor)||achbotPayUxAudienceAllowed($db,'manual_trx',$actor)))
            $keyboard[]=[['text'=>$renew?achbotMessage('renewal.renewal_checkout.button.73f7c8deeb',['price'=>$price]):achbotMessage('purchase.catalogue.button.e729457ba2'),'callback_data'=>'mcm_'.$hash_id]];
        foreach (['nowpayment'=>['nowPaymentOther','nowpayments',achbotMessage('legacy_buttons.now_payment_gateway')],
            'zarinpal'=>['zarinpal','zarinpal',achbotMessage('legacy_buttons.zarinpal_gateway')],
            'nextpay'=>['nextpay','nextpay',achbotMessage('legacy_buttons.nextpay_gateway')]] as $gateway=>[$flag,$method,$label]) {
            if (($states[$flag]??'off')!=='on' || !achbotPayUxAudienceAllowed($db,$method,$actor) ||
                ($method==='nowpayments' && achbotUsdtRateForUser($db,$actor)===null)) continue;
            $button=['text'=>$label];
            if ($renew) $button['callback_data']='renewPay_'.$gateway.'_'.$hash_id;
            else $button['url']=$botUrl.'pay/checkout.php?'.$gateway.'&hash_id='.$hash_id;
            $keyboard[]=[$button];
        }
    }
    if (($states['walletState']??'off')==='on' && achbotPayUxAudienceAllowed($db,'wallet',$actor))
        $keyboard[]=[['text'=>$renew?achbotMessage('renewal.renewal_checkout.button.0eed1dd8e8',['price'=>$price]):achbotMessage('legacy_buttons.pay_with_wallet'),'callback_data'=>$prefix.'WithWallet'.$hash_id]];
    if ($offerDiscount && $amount>0 && empty($view['context']['discount_applied']))
        $keyboard[]=[['text'=>($renew?achbotMessage('renewal.renewal_checkout.button.05c3a1e3f4'):achbotMessage('purchase.catalogue.button.05c3a1e3f4')),'callback_data'=>'haveDiscountCheckout_'.$pay['id']]];
    if ($mode==='standard') {
        $buyType=(int)$pay['agent_bought']===1?((int)$pay['agent_count']>0?'much':'one'):'none';
        $back='selectCategory'.$view['context']['category_id'].'_'.$q['server_id'].'_'.$buyType;
        $keyboard[]=[['text'=>achbotMessage('legacy_buttons.back_to_main'),'callback_data'=>$back]];
    } else {
        $keyboard[]=[['text'=>achbotMessage('legacy_buttons.cancel'),'callback_data'=>'mainMenu']];
    }
    if ($renew) $msg=achbotMessage('renewal.renewal_checkout.message.24ae8c4399');
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
    $sent=sendMessage($msg,json_encode(['inline_keyboard'=>$keyboard]),'HTML');
    achbotPayUxTrackByHash($db,(string)$hash_id,$actor,$sent);
}

function achbotRenderNewCheckoutDraft(mysqli $db,int $id,int $actor,array $states,string $botUrl): bool {
    $view=achbotCheckoutDraftView($db,$id,$actor,$states);
    if ($view===null) return false;
    achbotRenderCheckoutDraft($db,$view,$states,$botUrl,true);
    return true;
}
