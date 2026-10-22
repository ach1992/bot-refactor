<?php
/** AChBot V8: source-pinned financial compatibility module. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

if (!function_exists('giveReferralReward')) {
    /**
     * Return true only for a confirmed credit (or deliberately disabled award).
     * This is called after the service payment COMMIT. Unknown/failed credit
     * outcomes must be reconciled using the original payment/user identities;
     * never retry an ambiguous wallet write from an old callback.
     */
    function giveReferralReward($connection, $buyerUserId, $inviterId, $admin,
                                $botState, $paymentMethod = null): bool {
        if ($paymentMethod !== null &&
            strtolower((string)$paymentMethod)==='wallet') return true;
        $inviterId=(int)$inviterId;
        if ($inviterId<=0) return false;
        try {
            $res=$connection->query("SELECT value FROM setting
                WHERE type='INVITE_BANNER_AMOUNT'");
            if (!$res) throw new RuntimeException('referral_setting_read_failed');
            $settings=$res->fetch_assoc();
            if (!$settings || (string)($settings['value']??'')==='0') return true;
            $rawAmount=(string)$settings['value'];
            if (!preg_match('/^[1-9][0-9]*$/D',$rawAmount) ||
                filter_var($rawAmount,FILTER_VALIDATE_INT)===false ||
                (int)$rawAmount>2147483647)
                throw new RuntimeException('referral_amount_invalid');
            $reward=(int)$rawAmount;
            $stmt=$connection->prepare("SELECT id,wallet FROM users WHERE userid=?");
            if (!$stmt) throw new RuntimeException('referral_inviter_prepare_failed');
            $stmt->bind_param('i',$inviterId);
            if (!$stmt->execute()) throw new RuntimeException('referral_inviter_read_failed');
            $rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            // Legacy userid is not uniquely indexed. No ambiguous credits.
            if (count($rows)!==1) throw new RuntimeException('referral_inviter_identity_unverified');
            $pk=(int)$rows[0]['id'];
            $old=(int)$rows[0]['wallet'];
            if ($old>2147483647-$reward)
                throw new RuntimeException('referral_wallet_overflow');
            $maximum=2147483647-$reward;
            $stmt=$connection->prepare("UPDATE users SET wallet=wallet+?
                WHERE id=? AND userid=? AND wallet=? AND wallet<=?");
            if (!$stmt) throw new RuntimeException('referral_update_prepare_failed');
            $stmt->bind_param('iiiii',$reward,$pk,$inviterId,$old,$maximum);
            $saved=$stmt->execute();
            $changed=$stmt->affected_rows;
            $stmt->close();
            if ($saved!==true || $changed!==1)
                throw new RuntimeException('referral_credit_unconfirmed');
        } catch (Throwable $error) {
            // Credit is not retried here. Failure could be ambiguous under
            // a broken/unknown database transport, so send no success notice.
            error_log('achbot_referral_credit_needs_review buyer='.(int)$buyerUserId.
                ' inviter='.$inviterId.' code='.get_class($error));
            return false;
        }
        $now=$old+$reward;
        $customer="🎁 تبریک! یکی از زیرمجموعه‌های شما خرید انجام داد. شما مبلغ ".
            number_format($reward)." تومان جایزه دریافت کردید.\n\n".
            "💰 موجودی قدیم: ".number_format($old)." تومان\n".
            "💵 موجودی جدید: ".number_format($now)." تومان";
        $notice="👥 کاربر ".(int)$buyerUserId." زیرمجموعه کاربر ".$inviterId.
            " یک خرید انجام داد.\n\n".
            "💰 موجودی قدیم: ".number_format($old)." تومان\n".
            "💵 موجودی جدید: ".number_format($now)." تومان";
        // Financial confirmation is independent from Telegram delivery. Never
        // turn a confirmed wallet credit into an "unknown credit" merely
        // because a notice failed. Continue attempting each independent
        // recipient; none may trigger a second credit.
        $targets=[
            ['inviter',$customer,$inviterId,null],
            ['admin',$notice,$admin,'html']
        ];
        $rewardChannel=$botState['rewardChannel']??null;
        if (!empty($rewardChannel)) {
            $targets[]=['channel',$notice,$rewardChannel,'html'];
        }
        foreach ($targets as [$role,$body,$destination,$format]) {
            if (!$destination) continue;
            try {
                $response=sendMessage($body,null,$format,$destination);
                if (!is_object($response) || ($response->ok??false)!==true)
                    throw new RuntimeException('referral_notification_unconfirmed');
            } catch (Throwable $error) {
                error_log('achbot_referral_notification_failed buyer='.(int)$buyerUserId.
                    ' inviter='.$inviterId.' target='.$role.
                    ' code='.get_class($error));
            }
        }
        return true;
    }
}
