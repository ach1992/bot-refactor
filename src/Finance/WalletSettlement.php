<?php
/** AChBot V8: source-pinned financial compatibility module. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

if (!function_exists('processWalletPayment')) {
    function processWalletPayment($connection, $hashId, $handler)
    {
        global $from_id, $admin;

        $connection->begin_transaction();

        try {
            $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ? FOR UPDATE");
            $stmt->bind_param("s", $hashId);
            $stmt->execute();
            $payRes = $stmt->get_result();
            $stmt->close();

            if ($payRes->num_rows == 0) {
                $connection->rollback();
                return [
                    'status'       => 'not_found',
                    'payInfo'      => null,
                    'userRow'      => null,
                    'userReferrer' => null,
                    'uid'          => null,
                    'price'        => null,
                ];
            }

            $payInfo = $payRes->fetch_assoc();

            // This is a customer callback, not an administrator settlement.
            if ((int)$payInfo['user_id'] <= 0 || (int)$payInfo['user_id'] !== (int)$from_id) {
                $connection->rollback();
                alert("⛔️ این سفارش متعلق به شما نیست.", true);
                return ['status'=>'invalid_owner','payInfo'=>null];
            }


            if ($payInfo['state'] === 'paid_with_wallet') {
                $connection->rollback();
                return [
                    'status'       => 'already_paid',
                    'payInfo'      => $payInfo,
                    'userRow'      => null,
                    'userReferrer' => null,
                    'uid'          => null,
                    'price'        => null,
                ];
            }

            if ($payInfo['state'] === 'processing_wallet') {
                $connection->rollback();
                alert("⏳ وضعیت درخواست هنوز در حال بررسی است. لطفاً وضعیت سفارش را کنترل کنید و پیش از ثبت دوباره با پشتیبانی هماهنگ شوید.", true);
                return [
                    'status'       => 'in_progress',
                    'payInfo'      => $payInfo,
                    'userRow'      => null,
                    'userReferrer' => null,
                    'uid'          => null,
                    'price'        => null,
                ];
            }

            // Old Telegram wallet buttons bypass the pre-payment menu.
            // Serializing on pays, reject every durable competing provider.
            if (!in_array((string)$payInfo['state'], ['pending','send','low_wallet'], true) ||
                (string)($payInfo['payid'] ?? '') !== '') {
                $connection->rollback();
                alert("⛔️ روش پرداخت این سفارش قبلاً تعیین شده یا در حال بررسی است.", true);
                return ['status'=>'invalid_state','payInfo'=>$payInfo];
            }
            $blockedStates = ['approved', 'declined', 'expired_have_sent'];
			if (in_array($payInfo['state'], $blockedStates, true)) {
				$connection->rollback();
				alert("⛔️ پرداخت این سفارش از کیف پول در حال حاضر امکان‌پذیر نیست. وضعیت سفارش را بررسی کنید.", true);
				return [
					'status'       => 'invalid_state',
					'payInfo'      => $payInfo,
					'userRow'      => null,
					'userReferrer' => null,
					'uid'          => null,
					'price'        => null,
				];
			}

            // Reject retired or unknown panel plans before marking a pending
            // wallet payment processing or debiting the customer. The outbound
            // provider call is not the first acceptable guard: by then wallet
            // mutations may already have occurred within this transaction.
            // Uses the original schema; no migrations or new payment policy.
            if (!achbotSupportsPaymentSubject($connection, $payInfo)) {
                $connection->rollback();
                alert("⛔️ پرداخت این اشتراک در حال حاضر امکان‌پذیر نیست؛ مبلغی از کیف پول کسر نشد.", true);
                return [
                    'status' => 'unsupported_panel',
                    'payInfo' => $payInfo,
                    'userRow' => null,
                    'userReferrer' => null,
                    'uid' => null,
                    'price' => null,
                ];
            }

            // Keep unsupported-plan preflight unchanged; only viable
            // customer orders need the shared-ledger exclusivity lookup.
            if (!achbotWalletDirectAllowedLocked($connection, $payInfo, (int)$from_id)) {
                $connection->rollback();
                alert("⛔️ این سفارش قبلاً با روش دیگری آغاز شده است؛ پرداخت دوباره انجام ندهید.", true);
                return ['status'=>'claimed_provider','payInfo'=>$payInfo];
            }

            $uid   = (int)($payInfo['user_id'] ?: $from_id);
            $price = (int)$payInfo['price'];

            $stmt = $connection->prepare("SELECT `wallet`, `refered_by` FROM `users` WHERE `userid` = ? FOR UPDATE");
            $stmt->bind_param("i", $uid);
            $stmt->execute();
            $userRow = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $userWallet   = isset($userRow['wallet']) ? (int)$userRow['wallet'] : 0;
            $userReferrer = $userRow['refered_by'] ?? null;

            if ($userWallet < $price) {
                $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'low_wallet' WHERE `hash_id` = ? LIMIT 1");
                $stmt->bind_param("s", $hashId);
                $stmt->execute();
                $stmt->close();

                $connection->commit();

                alert(
                    "❌ موجودی کیف پول شما کافی نیست.\n" .
                    "💵 موجودی فعلی: " . number_format($userWallet) . " تومان\n" .
                    "🔺 مورد نیاز: " . number_format($price - $userWallet) . " تومان",
                    true
                );

                return [
                    'status'       => 'low_wallet',
                    'payInfo'      => $payInfo,
                    'userRow'      => $userRow,
                    'userReferrer' => $userReferrer,
                    'uid'          => $uid,
                    'price'        => $price,
                ];
            }

            $stmt = $connection->prepare("UPDATE `pays` SET `state`='processing_wallet' WHERE `hash_id`=? LIMIT 1");
            $stmt->bind_param("s", $hashId);
            $stmt->execute();
            $stmt->close();

            $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` - ? WHERE `userid` = ? LIMIT 1");
            $stmt->bind_param("ii", $price, $uid);
            $stmt->execute();
            $stmt->close();

            $businessOk = call_user_func($handler, $payInfo, $userRow);

            if (!$businessOk) {
                $connection->rollback();
                return [
                    'status'       => 'business_failed',
                    'payInfo'      => $payInfo,
                    'userRow'      => $userRow,
                    'userReferrer' => $userReferrer,
                    'uid'          => $uid,
                    'price'        => $price,
                ];
            }

            $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'paid_with_wallet' WHERE `hash_id` = ? LIMIT 1");
            $stmt->bind_param("s", $hashId);
            $stmt->execute();
            $stmt->close();

            $connection->commit();

            $payInfo['state'] = 'paid_with_wallet';

            return [
                'status'       => 'ok',
                'payInfo'      => $payInfo,
                'userRow'      => $userRow,
                'userReferrer' => $userReferrer,
                'uid'          => $uid,
                'price'        => $price,
            ];

        } catch (Exception $e) {
            $connection->rollback();

            $adminErr = "⚠️ خطا در پرداخت با کیف پول\n\n"
                . "🆔 Hash ID: {$hashId}\n"
                . "👤 User: {$from_id}\n"
                . "⚠️ External effect: unverified; verify before retry or compensation\n📄 Reason (sanitized): " . achbotFinanceDiagnosticReason($e);
            sendMessage($adminErr, null, null, $admin);

            alert("⚠️ وضعیت درخواست پرداخت نیاز به بررسی دارد. لطفاً وضعیت سفارش و پرداخت را بررسی کنید و پیش از تکرار با پشتیبانی هماهنگ شوید.", true);

            return [
                'status'       => 'error',
                'payInfo'      => null,
                'userRow'      => null,
                'userReferrer' => null,
                'uid'          => null,
                'price'        => null,
            ];
        }
    }
}

