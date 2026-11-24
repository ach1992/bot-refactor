<?php
/**
 * AChBot #26: a purchase-scoped referral award, never an uncorrelated
 * user-wallet increment. Migration 008 is required for new paid awards.
 */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../messages/loader.php'; // ACHBOT_TEXT_CATALOG


/** The accepted fixed reward is per original qualifying purchase, not per
 * ordinal, and zero/missing configuration disables it. */
function achbotPurchaseReferralPolicyAmount(mysqli $db,string $prefix='referral_policy'): int {
    $q=$db->query("SELECT value FROM setting WHERE type='INVITE_BANNER_AMOUNT'");
    if (!$q) throw new RuntimeException($prefix.'_unavailable');
    $settings=$q->fetch_all(MYSQLI_ASSOC);$q->free();
    if (!$settings || (count($settings)===1 && (string)$settings[0]['value']==='0')) return 0;
    if (count($settings)!==1) throw new RuntimeException($prefix.'_ambiguous');
    $raw=(string)$settings[0]['value'];
    if (!preg_match('/^[1-9][0-9]*$/D',$raw) ||
        filter_var($raw,FILTER_VALIDATE_INT)===false || (int)$raw>2147483647)
        throw new RuntimeException($prefix.'_invalid');
    return (int)$raw;
}

/** Stage exactly the existing referral notice and authorized report fanout
 * with the credit. The optional service ID identifies the real completed
 * source, while the displayed pay ID is the real funding receipt. */
function achbotPurchaseReferralStageReportsLocked(mysqli $db,array $pay,int $buyer,int $inviter,
    int $amount,int $before,int $after,int $owner,?int $serviceId=null): void {
    $payId=(int)$pay['id'];
    $buyerMsg=\achbotMessage('payment.referral_reward.give_referral_reward.buyer_msg.51caba68b5').
        number_format($amount).
        \achbotMessage('payment.referral_reward.give_referral_reward.text.b87aec1eb7').
        number_format($before).
        \achbotMessage('payment.referral_reward.give_referral_reward.text.c0780ce268').
        number_format($after).
        \achbotMessage('payment.discounts.apply_discount_to_pay.text.2cc98f7f7c');
    $operatorMsg=\achbotMessage('payment.referral_reward.give_referral_reward.operator_msg.7c8cfd9d4f').
        $payId.\achbotMessage('payment.referral_reward.give_referral_reward.text.23d0786d9b').
        $buyer.\achbotMessage('payment.referral_reward.give_referral_reward.text.6aaba1c235').
        $inviter.\achbotMessage('payment.referral_reward.give_referral_reward.text.534fd54cb9').
        number_format($amount).\achbotMessage('payment.discounts.apply_discount_to_pay.text.2cc98f7f7c');
    achbotFollowupInsertLocked($db,$pay,'referral_reward','referral_inviter',
        'send',(string)$inviter,null,['text'=>$buyerMsg,'keys'=>null,'mode'=>null],$serviceId);
    $complete=false;$staff=achbotAdminRecipientCandidates($db,$owner,$complete);
    if (!$complete) throw new RuntimeException('manual_referral_staff_targets_uncertain');
    achbotFollowupInsertLocked($db,$pay,'referral_reward','referral_admin',
        'send',(string)$owner,null,['text'=>$operatorMsg,'keys'=>null,'mode'=>'HTML'],$serviceId);
    $seen=[(string)$owner=>true];
    foreach ($staff as $id) {
        $ok=true;
        $recipient=(string)(int)$id;
        if (achbotAdminNotificationAllowed($db,$id,$owner,'general',$ok) && !isset($seen[$recipient])) {
            achbotFollowupInsertLocked($db,$pay,'referral_reward','referral_admin',
                'send',$recipient,null,['text'=>$operatorMsg,'keys'=>null,'mode'=>'HTML'],$serviceId);
            $seen[$recipient]=true;
        }
        if (!$ok) throw new RuntimeException('manual_referral_staff_permission_uncertain');
    }
    $channel=(string)($GLOBALS['botState']['rewardChannel']??'');
    if ($channel!=='' && !isset($seen[$channel]))
        achbotFollowupInsertLocked($db,$pay,'referral_reward','referral_channel',
            'send',$channel,null,['text'=>$operatorMsg,'keys'=>null,'mode'=>'HTML'],$serviceId);
}
