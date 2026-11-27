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


/** The single representation/domain contract for purchase referral rewards.
 * Zero disables the reward; the existing signed money domain is unchanged. */
function achbotPurchaseReferralAmount(string $raw): ?int {
    if (!preg_match('/^(?:0|[1-9][0-9]*)$/D',$raw) ||
        filter_var($raw,FILTER_VALIDATE_INT)===false || (int)$raw>2147483647)
        return null;
    return (int)$raw;
}

/** The accepted fixed reward is per original qualifying purchase, not per
 * ordinal, and zero/missing configuration disables it. */
function achbotPurchaseReferralPolicyAmount(mysqli $db,string $prefix='referral_policy'): int {
    $q=$db->query("SELECT value FROM setting WHERE type='INVITE_BANNER_AMOUNT'");
    if (!$q) throw new RuntimeException($prefix.'_unavailable');
    $settings=$q->fetch_all(MYSQLI_ASSOC);$q->free();
    if (!$settings) return 0;
    if (count($settings)!==1) throw new RuntimeException($prefix.'_ambiguous');
    $amount=achbotPurchaseReferralAmount((string)$settings[0]['value']);
    if ($amount===null) throw new RuntimeException($prefix.'_invalid');
    return $amount;
}

/** Stage exactly the existing referral notice and authorized report fanout
 * with the credit. The optional service ID identifies the real completed
 * source, while the displayed pay ID is the real funding receipt. */
function achbotPurchaseReferralStageReportsLocked(mysqli $db,array $pay,int $buyer,int $inviter,
    int $amount,int $before,int $after,int $owner,int $serviceId,int $walletId,int $creditActor): void {
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
    require_once __DIR__.'/WalletReports.php';
    $operatorMsg.="\n".achbotWalletReportFacts(achbotWalletReportEntryLocked($db,$walletId)).
        "\n".achbotMessageHtml('operational_reports.source_reference',['reference'=>'P'.$payId.' / '.$pay['hash_id'].' / svc_'.$serviceId]);
    if ($creditActor>0) $operatorMsg.="\n".achbotMessageHtml('operational_reports.actor',['actor'=>$creditActor]);
    achbotFollowupInsertLocked($db,$pay,'referral_reward','referral_inviter',
        'send',(string)$inviter,null,['text'=>$buyerMsg,'keys'=>null,'mode'=>null],$serviceId);
    achbotFollowupStageAdminReportLocked($db,$payId,$serviceId,'referral_reward',
        achbotPaymentEvidencePayFingerprint($pay),$owner,(string)($GLOBALS['botState']['rewardChannel']??''),
        ['text'=>$operatorMsg,'keys'=>null,'mode'=>'HTML'],$creditActor);
}
