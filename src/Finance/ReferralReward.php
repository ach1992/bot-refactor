<?php
/** AChBot V8: source-pinned financial compatibility module. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

if (!function_exists('giveReferralReward')) {
    function giveReferralReward($connection, $buyerUserId, $inviterId, $admin, $botState, $paymentMethod = null)
    {
        if ($paymentMethod !== null && strtolower((string)$paymentMethod) === 'wallet') {
            return;
        }

        $stmt = $connection->prepare("SELECT `value` FROM `setting` WHERE `type` = 'INVITE_BANNER_AMOUNT'");
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $inviteAmount = isset($row['value']) ? (int)$row['value'] : 0;
        if ($inviteAmount <= 0) {
            return;
        }

        $stmt = $connection->prepare("SELECT `wallet` FROM `users` WHERE `userid` = ?");
        $stmt->bind_param("i", $inviterId);
        $stmt->execute();
        $userRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $currentWallet = isset($userRow['wallet']) ? (int)$userRow['wallet'] : 0;
        $newWallet = $currentWallet + $inviteAmount;

        $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` + ? WHERE `userid` = ?");
        $stmt->bind_param("ii", $inviteAmount, $inviterId);
        $stmt->execute();
        $stmt->close();

        $userMessage = "🎁 تبریک! یکی از زیرمجموعه‌های شما خرید انجام داد. شما مبلغ " . number_format($inviteAmount) . " تومان جایزه دریافت کردید.\n\n"
            . "💰 موجودی قدیم: " . number_format($currentWallet) . " تومان\n"
            . "💵 موجودی جدید: " . number_format($newWallet) . " تومان";

        sendMessage($userMessage, null, null, $inviterId);

        $channelMessage = "👥 کاربر $buyerUserId زیرمجموعه کاربر $inviterId یک خرید انجام داد.\n\n"
            . "💰 موجودی قدیم: " . number_format($currentWallet) . " تومان\n"
            . "💵 موجودی جدید: " . number_format($newWallet) . " تومان";

        sendMessage($channelMessage, null, "html", $admin);

        $rewardChannel = $botState['rewardChannel'] ?? null;
        if (!empty($rewardChannel)) {
            sendMessage($channelMessage, null, "html", $rewardChannel);
        }
    }
}

