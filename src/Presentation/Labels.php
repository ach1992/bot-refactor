<?php
/**
 * AchBot presentation helpers. No bootstrap, database or network effects.
 * Legacy coercions, strings and edge behavior are intentional in V1.
 */
namespace AchBot\Presentation\Labels;

function audience($g){
    if($g === 'has') return "دارای اشتراک";
    if($g === 'none') return "بدون اشتراک";
    if($g === 'agents') return "همکاران";
    return "همه کاربران";
}

function broadcastState($s){
    if($s == 1) return "در صف";
    if($s == 2) return "در حال ارسال";
    if($s == 3) return "کامل شده";
    if($s == 4) return "لغو شده";
    return "پیش‌نویس";
}

function paymentType($payType)
    {
        if ($payType === "BUY_SUB") return "خرید اشتراک";
        if ($payType === "RENEW_ACCOUNT") return "تمدید اشتراک";
        if ($payType === "RENEW_SCONFIG") return "تمدید اشتراک";
        if ($payType === "INCREASE_WALLET") return "شارژ کیف پول";
        if (preg_match('/^INCREASE_DAY_(\d+)_(\d+)/', $payType)) return "افزایش زمان اشتراک";
        if (preg_match('/^INCREASE_VOLUME_(\d+)_(\d+)/', $payType)) return "افزایش حجم اشتراک";
        return "پرداخت";
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
