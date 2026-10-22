<?php
/**
 * AChBot #26: a purchase-scoped referral award, never an uncorrelated
 * user-wallet increment. Migration 008 is required for new paid awards.
 */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

if (!function_exists('achbotReferralSchemaReady')) {
    function achbotReferralSchemaReady(mysqli $db): bool {
        try {
            $q=$db->query("SHOW TABLE STATUS LIKE 'referral_awards'");
            $row=$q?$q->fetch_assoc():null;
            if (!$row || strtoupper((string)$row['Engine'])!=='INNODB') return false;
            $idx=$db->query("SHOW INDEX FROM referral_awards
                WHERE Key_name='uq_referral_award_payment'");
            $columns=[];
            if (!$idx) return false;
            while ($r=$idx->fetch_assoc()) {
                if ((int)$r['Non_unique']!==0) return false;
                $columns[(int)$r['Seq_in_index']]=$r['Column_name'];
            }
            ksort($columns);
            if (array_values($columns)!==['pay_row_id']) return false;
            // Validate columns used by the money transaction, not just the
            // existence of a similarly named incompatible table.
            return $db->query("SELECT id,pay_row_id,buyer_userid,inviter_userid,
                amount_toman,state,before_balance,after_balance,confirmed_at,
                review_at FROM referral_awards LIMIT 0") instanceof mysqli_result;
        } catch (Throwable $error) {
            return false;
        }
    }
}

if (!function_exists('achbotReferralAwardStatus')) {
    /** Read only; no retry, no compensation and no personal balance exposed. */
    function achbotReferralAwardStatus(mysqli $db, int $payId): string {
        try {
            $stmt=$db->prepare('SELECT state FROM referral_awards WHERE pay_row_id=?');
            if (!$stmt) return 'UNKNOWN';
            $stmt->bind_param('i',$payId);
            if (!$stmt->execute()) return 'UNKNOWN';
            $rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
            return count($rows)===1 ? (string)$rows[0]['state'] : 'UNKNOWN';
        } catch (Throwable $ignored) { return 'UNKNOWN'; }
    }
}

if (!function_exists('achbotReferralMarkReview')) {
    function achbotReferralMarkReview(mysqli $db, int $payId): void {
        try {
            // Never downgrade a committed CONFIRMED credit after an
            // uncertain acknowledgement or a later failed notification.
            $stmt=$db->prepare("UPDATE referral_awards
                SET state='NEEDS_REVIEW',review_at=NOW()
                WHERE pay_row_id=? AND state='NOT_ATTEMPTED'");
            if (!$stmt) return;
            $stmt->bind_param('i',$payId);
            $stmt->execute();
            $stmt->close();
        } catch (Throwable $ignored) {}
    }
}

if (!function_exists('achbotReferralWarnOperator')) {
    /** Administrator-only uncertainty notice; never rearm financial credit. */
    function achbotReferralWarnOperator(int $admin, int $payId): void {
        if ($admin<=0 || $payId<=0 || !function_exists('sendMessage')) return;
        try {
            $ack=sendMessage(
                '⚠️ وضعیت پاداش معرفی نیاز به بررسی دارد.'.
                "\n🆔 Pay ID: ".$payId.
                "\nقبل از هر اصلاح، Ledger و موجودی را تطبیق دهید؛".
                ' واریز مجدد خودکار انجام ندهید.',
                null,null,$admin);
            if (!is_object($ack) || ($ack->ok??false)!==true)
                throw new RuntimeException('referral_review_notice_unconfirmed');
        } catch (Throwable $error) {
            error_log('achbot_referral_owner_notice_failed pay_id='.$payId.
                ' class='.get_class($error));
        }
    }
}

/**
 * Run immediately after the payment + complete service-order COMMIT,
 * BEFORE any fallible QR, user/admin message, edit or presentation operation.
 * This function can never reopen the settlement or repeat remote CREATE.
 *
 * The 008 payment-keyed claim is durable inside giveReferralReward. If the
 * claim/credit cannot be proven, preserve an owner-visible review signal.
 */
if (!function_exists('achbotReferralAfterCompletedPurchase')) {
    function achbotReferralAfterCompletedPurchase(
        mysqli $db, int $buyer, int $payId, int $admin, array $botState
    ): array {
        try {
            $stmt=$db->prepare('SELECT * FROM users WHERE userid=? LIMIT 1');
            if (!$stmt) throw new RuntimeException('referral_followup_buyer_prepare');
            $stmt->bind_param('i',$buyer);
            if (!$stmt->execute())
                throw new RuntimeException('referral_followup_buyer_read');
            $rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            if (count($rows)!==1)
                throw new RuntimeException('referral_followup_buyer_missing');
            $user=$rows[0];
            $inviter=(int)($user['refered_by']??0);
            if ($inviter>0 &&
                !giveReferralReward($db,$buyer,$inviter,$admin,$botState,null,$payId)) {
                error_log('achbot_referral_completed_purchase_review pay_id='.$payId);
                achbotReferralWarnOperator($admin,$payId);
            }
            return $user;
        } catch (Throwable $error) {
            error_log('achbot_referral_completed_purchase_review pay_id='.$payId.
                ' class='.get_class($error));
            achbotReferralWarnOperator($admin,$payId);
            return [];
        }
    }
}

if (!function_exists('giveReferralReward')) {
    /**
     * True means a newly confirmed credit or an explicitly disabled policy.
     * A known previous confirmation returns false: never announce a repeat.
     * False/ambiguous is operator-owned; no route may retry the credit.
     */
    function giveReferralReward($connection, $buyerUserId, $inviterId, $admin,
                                $botState, $paymentMethod = null,
                                $payId = null): bool {
        if ($paymentMethod!==null &&
            strtolower((string)$paymentMethod)==='wallet') return true;
        $db=$connection;
        $payId=(int)$payId; $buyer=(int)$buyerUserId; $inviter=(int)$inviterId;
        if (!($db instanceof mysqli) || $payId<=0 || $buyer<=0 ||
            $inviter<=0 || $buyer===$inviter ||
            !achbotReferralSchemaReady($db)) {
            error_log('achbot_referral_preflight_unavailable pay_id='.$payId);
            return false;
        }
        $started=false;
        try {
            $q=$db->query("SELECT value FROM setting
                WHERE type='INVITE_BANNER_AMOUNT'");
            if (!$q) throw new RuntimeException('referral_policy_unavailable');
            $rows=$q->fetch_all(MYSQLI_ASSOC);
            $disabled=count($rows)===0 || (count($rows)===1 &&
                (string)$rows[0]['value']==='0');
            if (!$disabled && count($rows)!==1)
                throw new RuntimeException('referral_policy_ambiguous');
            $amount=0;
            if (!$disabled) {
                $raw=(string)$rows[0]['value'];
                if (!preg_match('/^[1-9][0-9]*$/D',$raw) ||
                    filter_var($raw,FILTER_VALIDATE_INT)===false ||
                    (int)$raw>2147483647)
                    throw new RuntimeException('referral_policy_invalid');
                $amount=(int)$raw;
            }

            // Durable no-replay claim FIRST, before any credit. All locks use
            // buyer -> payment -> inviter, compatible with existing owner-first
            // customer payment writes.
            if ($db->begin_transaction()!==true)
                throw new RuntimeException('referral_reserve_begin');
            $started=true;
            $stmt=$db->prepare('SELECT userid,refered_by FROM users WHERE userid=? FOR UPDATE');
            if (!$stmt) throw new RuntimeException('referral_buyer_prepare');
            $stmt->bind_param('i',$buyer);
            if (!$stmt->execute()) throw new RuntimeException('referral_buyer_read');
            $buyers=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
            if (count($buyers)!==1 || (int)$buyers[0]['refered_by']!==$inviter)
                throw new RuntimeException('referral_buyer_identity_changed');

            $stmt=$db->prepare("SELECT id,user_id,type,state,agent_count
                FROM pays WHERE id=? FOR UPDATE");
            if (!$stmt) throw new RuntimeException('referral_pay_prepare');
            $stmt->bind_param('i',$payId);
            if (!$stmt->execute()) throw new RuntimeException('referral_pay_read');
            $pays=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
            $pay=$pays[0]??null;
            if (count($pays)!==1 || (int)$pay['user_id']!==$buyer ||
                $pay['type']!=='BUY_SUB' ||
                !in_array($pay['state'],['approved','paid','auto_cart_approved'],true))
                throw new RuntimeException('referral_payment_unconfirmed');

            // Source-only 007 journal is the existing canonical remote
            // service identity: do not award for a merely paid but unfulfilled
            // BUY_SUB. No aggregate panel-user-count inference.
            $stmt=$db->prepare("SELECT e.ordinal,e.state,e.order_id,
                o.id AS valid_order
                FROM service_effects e
                LEFT JOIN orders_list o ON o.id=e.order_id
                    AND o.userid=? AND o.fileid=e.plan_id
                    AND o.server_id=e.target_server_id
                    AND o.remark=e.remote_username
                WHERE e.pay_row_id=? AND e.effect_kind='create'
                ORDER BY e.ordinal FOR UPDATE");
            if (!$stmt) throw new RuntimeException('referral_effect_prepare');
            $stmt->bind_param('ii',$buyer,$payId);
            if (!$stmt->execute()) throw new RuntimeException('referral_effect_read');
            $effects=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
            $n=max(1,(int)$pay['agent_count']);
            if (count($effects)!==$n)
                throw new RuntimeException('referral_effect_count_unconfirmed');
            foreach ($effects as $i=>$effect) {
                if ((int)$effect['ordinal']!==$i+1 ||
                    $effect['state']!=='local_recorded' ||
                    (int)$effect['order_id']<=0 ||
                    $effect['valid_order']===null)
                    throw new RuntimeException('referral_effect_unconfirmed');
            }

            // Unique pay_row_id is the authoritative deduplication fence.
            // Never apply a second reward to an existing NOT_ATTEMPTED,
            // CONFIRMED or NEEDS_REVIEW payment, even after an unknown COMMIT.
            $awardState=$disabled?'DISABLED':'NOT_ATTEMPTED';
            $stmt=$db->prepare("INSERT INTO referral_awards
                (pay_row_id,buyer_userid,inviter_userid,amount_toman,state)
                VALUES (?,?,?,?,?)");
            if (!$stmt) throw new RuntimeException('referral_reserve_prepare');
            $stmt->bind_param('iiiis',$payId,$buyer,$inviter,$amount,$awardState);
            if (!$stmt->execute() || $stmt->affected_rows!==1)
                throw new RuntimeException('referral_reserve_unconfirmed');
            $stmt->close();
            if ($db->commit()!==true)
                throw new RuntimeException('referral_reserve_commit_unknown');
            $started=false;
            // Preserve a durable disabled-policy decision so an old paid
            // order cannot receive retroactive credit if settings change.
            if ($disabled) return true;
        } catch (Throwable $error) {
            if ($started) {
                try {$db->rollback();} catch (Throwable $ignored) {}
            }
            error_log('achbot_referral_reservation_review pay_id='.$payId.
                ' code='.get_class($error));
            return false;
        }

        $started=false;
        try {
            if ($db->begin_transaction()!==true)
                throw new RuntimeException('referral_credit_begin');
            $started=true;
            $stmt=$db->prepare('SELECT userid FROM users WHERE userid=? FOR UPDATE');
            if (!$stmt) throw new RuntimeException('referral_credit_buyer_prepare');
            $stmt->bind_param('i',$buyer);
            if (!$stmt->execute()) throw new RuntimeException('referral_credit_buyer_read');
            $buyers=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
            if (count($buyers)!==1)
                throw new RuntimeException('referral_credit_buyer_missing');
            $stmt=$db->prepare("SELECT state FROM pays
                WHERE id=? AND user_id=? AND type='BUY_SUB' FOR UPDATE");
            if (!$stmt) throw new RuntimeException('referral_credit_pay_prepare');
            $stmt->bind_param('ii',$payId,$buyer);
            if (!$stmt->execute()) throw new RuntimeException('referral_credit_pay_read');
            $paid=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
            if (count($paid)!==1 ||
                !in_array($paid[0]['state'],['approved','paid','auto_cart_approved'],true))
                throw new RuntimeException('referral_credit_payment_changed');
            $stmt=$db->prepare("SELECT state,inviter_userid,amount_toman
                FROM referral_awards WHERE pay_row_id=? FOR UPDATE");
            if (!$stmt) throw new RuntimeException('referral_credit_claim_prepare');
            $stmt->bind_param('i',$payId);
            if (!$stmt->execute()) throw new RuntimeException('referral_credit_claim_read');
            $rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
            if (count($rows)!==1 || $rows[0]['state']!=='NOT_ATTEMPTED' ||
                (int)$rows[0]['inviter_userid']!==$inviter ||
                (int)$rows[0]['amount_toman']!==$amount)
                throw new RuntimeException('referral_credit_claim_changed');
            $stmt=$db->prepare('SELECT id,wallet FROM users WHERE userid=? FOR UPDATE');
            if (!$stmt) throw new RuntimeException('referral_credit_inviter_prepare');
            $stmt->bind_param('i',$inviter);
            if (!$stmt->execute()) throw new RuntimeException('referral_credit_inviter_read');
            $inviterRows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
            if (count($inviterRows)!==1)
                throw new RuntimeException('referral_credit_inviter_ambiguous');
            $pk=(int)$inviterRows[0]['id'];$before=(int)$inviterRows[0]['wallet'];
            if ($pk<=0 || $before>2147483647-$amount)
                throw new RuntimeException('referral_credit_wallet_overflow');
            $max=2147483647-$amount;
            $stmt=$db->prepare('UPDATE users SET wallet=wallet+?
                WHERE id=? AND userid=? AND wallet=? AND wallet<=?');
            if (!$stmt) throw new RuntimeException('referral_credit_update_prepare');
            $stmt->bind_param('iiiii',$amount,$pk,$inviter,$before,$max);
            if (!$stmt->execute() || $stmt->affected_rows!==1)
                throw new RuntimeException('referral_credit_update_unconfirmed');
            $stmt->close();
            $after=$before+$amount;
            $stmt=$db->prepare("UPDATE referral_awards
                SET state='CONFIRMED',before_balance=?,after_balance=?,confirmed_at=NOW()
                WHERE pay_row_id=? AND state='NOT_ATTEMPTED'");
            if (!$stmt) throw new RuntimeException('referral_credit_confirm_prepare');
            $stmt->bind_param('iii',$before,$after,$payId);
            if (!$stmt->execute() || $stmt->affected_rows!==1)
                throw new RuntimeException('referral_credit_confirm_unconfirmed');
            $stmt->close();
            if ($db->commit()!==true)
                throw new RuntimeException('referral_credit_commit_unknown');
            $started=false;
        } catch (Throwable $error) {
            if ($started) {
                try {$db->rollback();} catch (Throwable $ignored) {}
            }
            achbotReferralMarkReview($db,$payId);
            error_log('achbot_referral_credit_review pay_id='.$payId.
                ' code='.get_class($error));
            achbotReferralWarnOperator((int)$admin,$payId);
            return false;
        }

        $buyerMsg="🎁 پاداش معرفی شما به مبلغ ".number_format($amount).
            " تومان ثبت شد.\n💰 موجودی قبلی: ".number_format($before).
            " تومان\n💵 موجودی جدید: ".number_format($after)." تومان";
        $operatorMsg="👥 پاداش معرفی خرید ثبت شد.\nشناسه پرداخت: ".$payId.
            "\nشناسه خریدار: ".$buyer."\nشناسه معرف: ".$inviter.
            "\nمبلغ: ".number_format($amount)." تومان";
        $targets=[['inviter',$inviter,$buyerMsg,null],
                  ['admin',$admin,$operatorMsg,'html']];
        $channel=$botState['rewardChannel']??null;
        if ($channel) $targets[]=['channel',$channel,$operatorMsg,'html'];
        foreach ($targets as [$role,$recipient,$message,$format]) {
            if (!$recipient) continue;
            try {
                $ack=sendMessage($message,null,$format,$recipient);
                if (!is_object($ack) || ($ack->ok??false)!==true)
                    throw new RuntimeException('referral_notice_unconfirmed');
            } catch (Throwable $error) {
                // Transaction is already CONFIRMED. Message failure cannot
                // alter the credit or reopen the journal for re-issuance.
                error_log('achbot_referral_notice_failed pay_id='.$payId.
                    ' target='.$role.' class='.get_class($error));
            }
        }
        return true;
    }
}
