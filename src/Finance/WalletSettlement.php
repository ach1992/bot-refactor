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
                alert("⏳ درخواست شما در حال پردازش است. کمی بعد دوباره تلاش کنید.", true);
                return [
                    'status'       => 'in_progress',
                    'payInfo'      => $payInfo,
                    'userRow'      => null,
                    'userReferrer' => null,
                    'uid'          => null,
                    'price'        => null,
                ];
            }

            $blockedStates = ['approved', 'declined', 'expired_have_sent'];
			if (in_array($payInfo['state'], $blockedStates, true)) {
				$connection->rollback();
				alert("⛔️ این پرداخت قابل پرداخت با کیف پول نیست: " . $payInfo['state'], true);
				return [
					'status'       => 'invalid_state',
					'payInfo'      => $payInfo,
					'userRow'      => null,
					'userReferrer' => null,
					'uid'          => null,
					'price'        => null,
				];
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
                . "📄 Error: " . $e->getMessage();
            sendMessage($adminErr, null, null, $admin);

            alert("❌ مشکلی پیش اومد.\n🔁 لطفاً کمی بعد دوباره تلاش کنید.\nاگر مشکل ادامه داشت، به مدیریت اطلاع دهید.", true);

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

