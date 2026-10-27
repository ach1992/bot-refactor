<?php
/**
 * AchBot presentation helpers. No bootstrap, database or network effects.
 * Legacy coercions, strings and edge behavior are intentional in V1.
 */
namespace AchBot\Presentation\Labels;
require_once __DIR__ . '/../../messages/loader.php'; // ACHBOT_TEXT_CATALOG


function audience($g){
    if($g === 'has') return \achbotMessage('labels.labels.audience.label.7550933a0a');
    if($g === 'none') return \achbotMessage('labels.labels.audience.label.06899237ef');
    if($g === 'agents') return \achbotMessage('labels.labels.audience.label.452a2d6fed');
    return \achbotMessage('labels.labels.audience.label.ee7ae9b315');
}

function broadcastState($s){
    if($s == 1) return \achbotMessage('labels.labels.broadcast_state.label.1ebfb10a5f');
    if($s == 2) return \achbotMessage('labels.labels.broadcast_state.label.53bf8bd91a');
    if($s == 3) return \achbotMessage('labels.labels.broadcast_state.label.73893d18a4');
    if($s == 4) return \achbotMessage('labels.labels.broadcast_state.label.154d1cae4b');
    return \achbotMessage('labels.labels.broadcast_state.label.410c47c7f8');
}

function paymentType($payType)
    {
        if ($payType === "BUY_SUB") return \achbotMessage('labels.labels.payment_type.label.e6343571a4');
        if ($payType === "RENEW_ACCOUNT") return \achbotMessage('labels.labels.payment_type.label.df1c62b99e');
        if ($payType === "RENEW_SCONFIG") return \achbotMessage('labels.labels.payment_type.label.df1c62b99e');
        if ($payType === "INCREASE_WALLET") return \achbotMessage('labels.labels.payment_type.label.991ae08d64');
        if (preg_match('/^INCREASE_DAY_(\d+)_(\d+)/', $payType)) return \achbotMessage('labels.labels.payment_type.label.884cfdce97');
        if (preg_match('/^INCREASE_VOLUME_(\d+)_(\d+)/', $payType)) return \achbotMessage('labels.labels.payment_type.label.160a34aa6d');
        return \achbotMessage('labels.labels.payment_type.label.1af21c4ced');
    }

function cardToCardMessageKey(string $messageType): string {
    if ($messageType === 'usdt') {
        return 'brw_cart_to_cart_usdt';
    }

    if ($messageType === 'gift') {
        return 'brw_cart_to_cart_gift';
    }

    return 'brw_cart_to_cart_detail';
}
