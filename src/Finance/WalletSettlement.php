<?php
/** AChBot V8: source-pinned financial compatibility module. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../messages/loader.php'; // ACHBOT_TEXT_CATALOG


if (!function_exists('processWalletPayment')) {
    function processWalletPayment($connection, $hashId, $handler, ?string $expectedBuyMode = null)
    {
        global $from_id, $admin, $botState;
        // A confirmed commit cannot be undone after calling Marzban.
        $heldDebit=false;

        try {
            // The preview is an autocommit read OUTSIDE the financial
            // transaction. Otherwise REPEATABLE READ pins a stale snapshot
            // before the owner lock, including for audience/plan checks.
            // An unsupported/retired panel can be REJECTED by a safe
            // snapshot preflight without acquiring a financial lock.
            // This preview NEVER authorizes payment: every accepted path
            // reacquires owner -> pays -> attempt locks and rechecks the
            // current order/plan before wallet debit or fulfillment.
            $preview=$connection->prepare("SELECT * FROM `pays` WHERE `hash_id`=? LIMIT 1");
            $preview->bind_param('s',$hashId);
            if (!$preview->execute())
                throw new RuntimeException('wallet_preview_read_failed');
            $previewPay=$preview->get_result()->fetch_assoc();
            $preview->close();
            if ($previewPay && (int)($previewPay['user_id']??0)===(int)$from_id &&
                !achbotSupportsPaymentSubject($connection,$previewPay)) {
                alert(\achbotMessage('wallet.wallet_settlement.process_wallet_payment.alert.244ca46c8f'), true);
                return [
                    'status'=>'unsupported_panel',
                    'payInfo'=>$previewPay,
                    'userRow'=>null,
                    'userReferrer'=>null,
                    'uid'=>null,
                    'price'=>null,
                ];
            }
            // Begin a NEW financial transaction only AFTER the reject-only
            // preview has completed. No pre-lock read view survives.
            if (!$connection->begin_transaction())
                throw new RuntimeException('wallet_begin_failed');
            // Serialize the FINAL new wallet method selection against the
            // customer draft writers and hosted/manual claims. The shared
            // invariant is owner -> pays -> provider attempt, NEVER pays ->
            // owner. Preflight or an older Telegram button is not authority
            // to debit money or invoke fulfillment.
            require_once __DIR__ . '/PaymentLifecycle.php';
            achbotPayUxLockDraftOwner($connection,(int)$from_id);
            $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ? FOR UPDATE");
            $stmt->bind_param("s", $hashId);
            if (!$stmt->execute()) throw new RuntimeException('wallet_pay_lock_failed');
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
                alert(\achbotMessage('wallet.wallet_settlement.process_wallet_payment.alert.669afe458a'), true);
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
                alert(\achbotMessage('wallet.wallet_settlement.process_wallet_payment.alert.978c658074'), true);
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
                alert(\achbotMessage('wallet.wallet_settlement.process_wallet_payment.alert.86fa6b6506'), true);
                return ['status'=>'invalid_state','payInfo'=>$payInfo];
            }
            $blockedStates = ['approved', 'declined', 'expired_have_sent'];
			if (in_array($payInfo['state'], $blockedStates, true)) {
				$connection->rollback();
				alert(\achbotMessage('wallet.wallet_settlement.process_wallet_payment.alert.98b1616048'), true);
				return [
					'status'       => 'invalid_state',
					'payInfo'      => $payInfo,
					'userRow'      => null,
					'userReferrer' => null,
					'uid'          => null,
					'price'        => null,
				];
			}

            $price = (int)($payInfo['price'] ?? 0);
            // Invalid/negative prices never become credits or free fulfillment.
            if ($price <= 0) {
                $connection->rollback();
                alert(\achbotMessage('wallet.wallet_settlement.process_wallet_payment.alert.2d688eba81'), true);
                return ['status'=>'invalid_amount','payInfo'=>$payInfo];
            }

            // Keep unsupported-plan preflight unchanged; only viable
            // customer orders need the shared-ledger exclusivity lookup.
            if (!achbotWalletDirectAllowedLocked($connection, $payInfo, (int)$from_id)) {
                $connection->rollback();
                alert(\achbotMessage('wallet.wallet_settlement.process_wallet_payment.alert.b691af4dc7'), true);
                return ['status'=>'claimed_provider','payInfo'=>$payInfo];
            }

            // Reject retired or unknown panel plans before marking a pending
            // wallet payment processing or debiting the customer. The outbound
            // provider call is not the first acceptable guard: by then wallet
            // mutations may already have occurred within this transaction.
            // Uses the original schema; no migrations or new payment policy.
            if (!achbotSupportsPaymentSubject($connection, $payInfo, true)) {
                $connection->rollback();
                alert(\achbotMessage('wallet.wallet_settlement.process_wallet_payment.alert.244ca46c8f'), true);
                return [
                    'status' => 'unsupported_panel',
                    'payInfo' => $payInfo,
                    'userRow' => null,
                    'userReferrer' => null,
                    'uid' => null,
                    'price' => null,
                ];
            }

            // The controller selects a concrete handler, but the LOCKED
            // payment row authorizes which BUY_SUB mode may fulfill it.
            // Standard rows are the 0/0 plan-template shape; custom quotes
            // require BOTH saved dimensions to be positive. Neither callback
            // can claim the other mode, even when the provider request would
            // otherwise pass its own generic dimensions validation.
            $paidCreate=(($payInfo['type']??'')==='BUY_SUB');
            $quotedDay=(int)($payInfo['day']??0);
            $quotedVolume=(int)($payInfo['volume']??0);
            $modeMatchesLockedRequest=$paidCreate
                ? (($expectedBuyMode==='standard' &&
                    $quotedDay===0 && $quotedVolume===0) ||
                   ($expectedBuyMode==='custom' &&
                    $quotedDay>0 && $quotedVolume>0))
                : ($expectedBuyMode===null || $expectedBuyMode==='standard');
            if (!$modeMatchesLockedRequest) {
                $connection->rollback();
                alert(\achbotMessage('wallet.wallet_settlement.process_wallet_payment.alert.5d2e89e5ed'),true);
                return ['status'=>'invalid_request','payInfo'=>$payInfo];
            }

            $uid   = (int)($payInfo['user_id'] ?: $from_id);
            $price = (int)$payInfo['price'];

            $stmt = $connection->prepare("SELECT `wallet`, `refered_by` FROM `users` WHERE `userid` = ? FOR UPDATE");
            $stmt->bind_param("i", $uid);
            if (!$stmt->execute()) throw new RuntimeException('wallet_balance_read_failed');
            $userRow = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $userWallet   = isset($userRow['wallet']) ? (int)$userRow['wallet'] : 0;
            $userReferrer = $userRow['refered_by'] ?? null;

            if ($userWallet < $price) {
                $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'low_wallet' WHERE `hash_id` = ? LIMIT 1");
                $stmt->bind_param("s", $hashId);
                if (!$stmt->execute()) throw new RuntimeException('wallet_low_balance_status_failed');
                $stmt->close();
                if (!$connection->commit()) throw new RuntimeException('wallet_low_balance_commit_unknown');

                alert(
                    \achbotMessage('wallet.wallet_settlement.process_wallet_payment.alert.ae55877021') .
                    \achbotMessage('wallet.wallet_settlement.process_wallet_payment.alert.4092b19ef1') . number_format($userWallet) . \achbotMessage('wallet.admin_wallet.admin_wallet_report_result.text.81a62ff6c6') .
                    \achbotMessage('wallet.wallet_settlement.process_wallet_payment.alert.f82b5605a9') . number_format($price - $userWallet) . \achbotMessage('wallet.admin_wallet.admin_wallet_report_result.text.2cc98f7f7c'),
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

            if ($paidCreate) {
                require_once __DIR__.'/ServiceEffects.php';
                // Missing/malformed 007 must never debit before validation.
                achbotEffectRequireUniqueLine($connection);
                if (!($connection->query('SELECT reserved_count FROM service_effects LIMIT 0')
                      instanceof mysqli_result))
                    throw new RuntimeException('wallet_create_journal_missing');
                $quantity=max(1,(int)($payInfo['agent_count']??0));
                $customDay=(int)($payInfo['day']??0);
                $customVolume=(int)($payInfo['volume']??0);
                $isCustom=($expectedBuyMode==='custom');
                $manualRemark=(($botState['remark']??'')==='manual');
                $persistedRemark=(string)($payInfo['description']??'');
                // Reject deterministic request errors while still in the
                // owner/pay/user lock transaction and BEFORE financial debit.
                // Even an old or tampered pays row cannot skip this guard.
                if (($isCustom &&
                     ($customDay<=0 || $customDay>36500 ||
                      $customVolume<=0 ||
                      $customVolume>intdiv(PHP_INT_MAX,1073741824) ||
                      !achbotEffectValidUsername($persistedRemark))) ||
                    (!$isCustom && $manualRemark &&
                     !achbotEffectValidUsername($persistedRemark))) {
                    $connection->rollback();
                    alert(\achbotMessage('wallet.wallet_settlement.process_wallet_payment.alert.95b8db0b7f'),true);
                    return ['status'=>'invalid_request','payInfo'=>$payInfo];
                }
                $planId=(int)($payInfo['plan_id']??0);
                $serverId=(int)($payInfo['server_id']??0);
                $planCheck=$connection->prepare("SELECT s.*,
                    i.ucount,i.remark AS server_remark,
                    i.active AS server_active,i.state AS server_state,
                    c.type AS panel_type
                    FROM server_plans s JOIN server_info i ON i.id=s.server_id
                    JOIN server_config c ON c.id=i.id
                    WHERE s.id=? AND s.active=1 LIMIT 1 FOR UPDATE");
                if (!$planCheck) throw new RuntimeException('wallet_create_capacity_prepare');
                $planCheck->bind_param('i',$planId);
                if (!$planCheck->execute()) throw new RuntimeException('wallet_create_capacity_read');
                $plan=$planCheck->get_result()->fetch_assoc();$planCheck->close();
                // The final dispatcher validates deterministic local fields.
                // Perform the same admissibility checks here while the
                // payment and plan are locked, before the wallet hold/debit.
                $planDays=(int)($plan['days']??0);
                $planVolume=(int)($plan['volume']??0);
                $serverRemark=(string)($plan['server_remark']??'');
                $remarkMode=(string)($botState['remark']??'');
                $prefixSuffix=$remarkMode==='digits' ? '-99999' :
                    '-'.$uid.'-99999';
                $generatedValid=$isCustom || $manualRemark ||
                    achbotEffectValidUsername($serverRemark.$prefixSuffix);
                if (!$plan || (int)$plan['server_id']!==$serverId ||
                    (int)$plan['inbound_id']!==0 ||
                    (int)$plan['server_active']!==1 ||
                    (int)$plan['server_state']!==1 ||
                    !achbotSupportsAccount($plan['panel_type']??'',0) ||
                    (!$isCustom && ($planDays<=0 || $planDays>36500 ||
                     $planVolume<=0 ||
                     $planVolume>intdiv(PHP_INT_MAX,1073741824))) ||
                    ($isCustom && $quantity!==1) || !$generatedValid) {
                    $connection->rollback();
                    alert(\achbotMessage('wallet.wallet_settlement.process_wallet_payment.alert.b05ed79353'),true);
                    return ['status'=>'invalid_request','payInfo'=>$payInfo];
                }
                if ((int)$plan['ucount']<$quantity) {
                    $connection->rollback();
                    alert(\achbotMessage('wallet.wallet_settlement.process_wallet_payment.alert.0b74ab3f2e'),true);
                    return ['status'=>'insufficient_capacity','payInfo'=>$payInfo];
                }
                // The full plan row was locked and validated before debit.
                // Pass the immutable request snapshot across COMMIT instead
                // of trusting a later, unlocked plan reread.
                $payInfo['_achbot_wallet_locked_plan']=$plan;
            }

            $stmt = $connection->prepare("UPDATE `pays` SET `state`='processing_wallet' WHERE `hash_id`=? LIMIT 1");
            $stmt->bind_param("s", $hashId);
            $marked=$stmt->execute();
            $markedRows=$stmt->affected_rows;
            $stmt->close();
            if ($marked!==true || $markedRows!==1)
                throw new RuntimeException('wallet_processing_mark_failed');

            $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` - ? WHERE `userid` = ? LIMIT 1");
            $stmt->bind_param("ii", $price, $uid);
            $debited=$stmt->execute();
            $debitedRows=$stmt->affected_rows;
            $stmt->close();
            if ($debited!==true || $debitedRows!==1)
                throw new RuntimeException('wallet_debit_failed');

            if ($paidCreate) {
                // This one wallet transaction commits debit, processing
                // state and ALL capacity together before external HTTP.
                achbotEffectReserveWalletCapacityLocked($connection,$payInfo);
            }

            if ($paidCreate) {
                // Durable payment state and debit COMMIT before HTTP.
                // The effect helper commits full-count capacity + operation
                // identity separately before it sends the first POST.
                if ($connection->commit()!==true)
                    throw new RuntimeException('wallet_hold_commit_unknown');
                $heldDebit=true;
                $payInfo['state']='processing_wallet';
                if (!call_user_func($handler,$payInfo,$userRow))
                    throw new RuntimeException('wallet_paid_create_unconfirmed');
                if ($connection->begin_transaction()!==true)
                    throw new RuntimeException('wallet_finish_begin');
                $finish=$connection->prepare("UPDATE pays SET state='paid_with_wallet'
                    WHERE id=? AND user_id=? AND type='BUY_SUB'
                      AND state='processing_wallet'");
                if (!$finish) throw new RuntimeException('wallet_finish_prepare');
                $payId=(int)$payInfo['id'];
                $finish->bind_param('ii',$payId,$uid);
                if (!$finish->execute() || $finish->affected_rows!==1)
                    throw new RuntimeException('wallet_finish_state_unconfirmed');
                $finish->close();
                if ($connection->commit()!==true)
                    throw new RuntimeException('wallet_finish_commit_unknown');
            } else {
                // Existing non-CREATE financial workflow remains unchanged.
                if (!call_user_func($handler,$payInfo,$userRow)) {
                    $connection->rollback();
                    return [
                        'status'=>'business_failed','payInfo'=>$payInfo,
                        'userRow'=>$userRow,'userReferrer'=>$userReferrer,
                        'uid'=>$uid,'price'=>$price
                    ];
                }
                $stmt=$connection->prepare("UPDATE pays SET state='paid_with_wallet'
                    WHERE hash_id=? AND state='processing_wallet'");
                if (!$stmt) throw new RuntimeException('wallet_finish_prepare');
                $stmt->bind_param('s',$hashId);
                if (!$stmt->execute() || $stmt->affected_rows!==1)
                    throw new RuntimeException('wallet_commit_unverified');
                $stmt->close();
                if ($connection->commit()!==true)
                    throw new RuntimeException('wallet_commit_unverified');
            }
            $payInfo['state']='paid_with_wallet';

            return [
                'status'       => 'ok',
                'payInfo'      => $payInfo,
                'userRow'      => $userRow,
                'userReferrer' => $userReferrer,
                'uid'          => $uid,
                'price'        => $price,
            ];

        } catch (Throwable $e) {
            // The SQL commit or remote effect may be ambiguous. A missing
            // connection can also make ROLLBACK fail: never report success.
            try { $connection->rollback(); } catch (Throwable $ignored) {}

            if ($heldDebit)
                error_log('achbot_wallet_held_debit_review hash='.
                    preg_replace('/[^A-Za-z0-9_-]/','',(string)$hashId));
            $adminErr = \achbotMessage('wallet.wallet_settlement.process_wallet_payment.admin_err.51996ec850')
                . \achbotMessage('wallet.wallet_settlement.process_wallet_payment.hash__i_d_hash_id.e73fee2172', ['hashId' => $hashId])
                . \achbotMessage('wallet.wallet_settlement.process_wallet_payment.user_from_id.45aa9ea684', ['from_id' => $from_id])
                . \achbotMessage('wallet.wallet_settlement.process_wallet_payment.external_effect_unverified_verify_before.10e2ccf8a3') . (function_exists('achbotFinanceDiagnosticReason')
                ? achbotFinanceDiagnosticReason($e)
                : 'wallet_transaction_guard_error_' . (int)$e->getCode());
            try { achbotNotifyAdmins('sendMessage', ['text'=>$adminErr], ['report_channel'=>null]); }
            catch (Throwable $ignored) {
                error_log('achbot_wallet_failure_operator_notice_deferred');
            }

            alert(\achbotMessage('wallet.wallet_settlement.process_wallet_payment.alert.9538721ade'), true);

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

