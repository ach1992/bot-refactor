<?php
/** AChBot: idempotent automatic renewal, source-only 004 migration required. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

/**
 * Atomic local reservation BEFORE the first non-idempotent Marzban operation.
 * The source of truth is the same original orders_list row/cycle, even after
 * the old Telegram callback or a second Cron process is replayed.
 *
 * - User debt remains signed; a routine renewal NEVER deepens debt.
 * - The pays record and debit are committed together with the durable claim.
 * - A provider timeout/ambiguous result can NEVER trigger an automatic retry.
 * - The 004 migration is mandatory; its absence fails closed without a debit.
 */
function achbotAutoRenewReserve(mysqli $db, int $orderId, int $userId): array {
    $outcome=['status'=>'review'];
    if ($orderId<=0 || $userId<=0) return $outcome;
    try {
        if ($db->begin_transaction()!==true) return $outcome;
        $stmt=$db->prepare("SELECT id,userid,fileid,server_id,inbound_id,remark,
                                   expire_date,status,notif,autoRenew
            FROM orders_list WHERE id=? FOR UPDATE");
        $stmt->bind_param('i',$orderId);
        $stmt->execute();
        $order=$stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$order || (int)$order['userid']!==$userId ||
            (int)$order['status']!==1 || (int)$order['notif']===0 ||
            (string)$order['autoRenew']!=='on' ||
            !achbotSupportsMarzbanOrder($db,$orderId,(int)$order['fileid'],$userId)) {
            $db->rollback(); return ['status'=>'ineligible'];
        }

        // Mandatory durable schema: never bypass a missing or damaged journal.
        $stmt=$db->prepare("SELECT id FROM automatic_renewal_attempts
            WHERE order_id=? AND stage<>'done' LIMIT 2 FOR UPDATE");
        $stmt->bind_param('i',$orderId);
        $stmt->execute();
        $pending=$stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($pending) {
            $db->rollback(); return ['status'=>'already_claimed'];
        }
        $planId=(int)$order['fileid'];
        $stmt=$db->prepare("SELECT price,days,volume,server_id,inbound_id
            FROM server_plans WHERE id=? AND active=1 LIMIT 1");
        $stmt->bind_param('i',$planId);
        $stmt->execute();
        $plan=$stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$plan || (int)$plan['inbound_id']!==0 || (int)$order['inbound_id']!==0 ||
            (string)$order['remark']==='') {
            $db->rollback(); return ['status'=>'ineligible'];
        }
        $amount=(int)$plan['price'];
        $days=(int)$plan['days'];
        $volume=(int)$plan['volume'];
        $now=time();
        if ($amount<=0 || $days<=0 || $days>3650 || $volume<0 ||
            $volume>intdiv(PHP_INT_MAX,1073741824) ||
            $now>2147483647-$days*86400) {
            $db->rollback(); return ['status'=>'invalid_plan'];
        }

        // The legacy userid index is not unique. Never debit an ambiguous ID.
        $stmt=$db->prepare('SELECT id,wallet FROM users WHERE userid=? FOR UPDATE');
        $stmt->bind_param('i',$userId);
        $stmt->execute();
        $users=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        if (count($users)!==1) {
            $db->rollback(); return ['status'=>'identity_unverified'];
        }
        $wallet=(int)$users[0]['wallet'];
        if ($wallet<$amount) {
            $stmt=$db->prepare("UPDATE orders_list SET autoRenew='off'
                WHERE id=? AND userid=? AND autoRenew='on'");
            $stmt->bind_param('ii',$orderId,$userId);
            $stmt->execute();
            $stmt->close();
            if ($db->commit()!==true) throw new RuntimeException('low_wallet_commit_unconfirmed');
            return ['status'=>'insufficient_funds','user_id'=>$userId,
                'amount'=>$amount,'wallet'=>$wallet];
        }

        $ref='rn_'.bin2hex(random_bytes(16));
        $state='processing_wallet';
        $description='automatic_renewal';
        $empty='';
        $newExpire=$now+$days*86400;
        $cycle=(int)$order['expire_date'];
        $serverId=(int)$order['server_id'];
        $remark=(string)$order['remark'];
        $stmt=$db->prepare("INSERT INTO pays
            (hash_id,description,payid,user_id,type,plan_id,server_id,volume,
             day,price,request_date,state,order_id)
            VALUES (?,?,?,?,'RENEW_ACCOUNT',?,?,?,?,?,?,?,?)");
        $typeParams='sssiiiiiiisi';
        $stmt->bind_param($typeParams,$ref,$description,$empty,$userId,$planId,
            $serverId,$volume,$days,$amount,$now,$state,$orderId);
        $stmt->execute();
        $payId=(int)$db->insert_id;
        $stmt->close();
        if ($payId<=0) throw new RuntimeException('renewal_pay_claim_failed');
        $stmt=$db->prepare("INSERT INTO automatic_renewal_attempts
            (order_id,cycle_expire,pay_row_id,user_id,plan_id,server_id,
             account_remark,price_toman,duration_days,volume_gb,new_expire,stage)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,'reserved')");
        $stmt->bind_param('iiiiiisiiii',$orderId,$cycle,$payId,$userId,$planId,
            $serverId,$remark,$amount,$days,$volume,$newExpire);
        $stmt->execute();
        $attemptId=(int)$db->insert_id;
        $stmt->close();
        if ($attemptId<=0) throw new RuntimeException('renewal_journal_claim_failed');
        $userPk=(int)$users[0]['id'];
        $stmt=$db->prepare("UPDATE users SET wallet=wallet-?
            WHERE id=? AND userid=? AND wallet>=?");
        $stmt->bind_param('iiii',$amount,$userPk,$userId,$amount);
        $stmt->execute();
        $changed=$stmt->affected_rows;
        $stmt->close();
        if ($changed!==1 || $db->commit()!==true)
            throw new RuntimeException('renewal_funds_reservation_unconfirmed');
        return ['status'=>'reserved','id'=>$attemptId,'pay_id'=>$payId,
            'ref'=>$ref,'order_id'=>$orderId,'cycle_expire'=>$cycle,
            'user_id'=>$userId,'server_id'=>$serverId,'remark'=>$remark,
            'days'=>$days,'volume'=>$volume,'amount'=>$amount,
            'new_expire'=>$newExpire,'wallet_before'=>$wallet];
    } catch (Throwable $error) {
        try {$db->rollback();} catch (Throwable $ignored) {}
        error_log('achbot_auto_renew_reservation_deferred');
        return $outcome;
    }
}

/** Persist remote-effect intent BEFORE calling the external provider. */
function achbotAutoRenewMarkRemoteStarted(mysqli $db, int $attemptId): bool {
    try {
        $stmt=$db->prepare("UPDATE automatic_renewal_attempts
            SET stage='remote_started' WHERE id=? AND stage='reserved'");
        $stmt->bind_param('i',$attemptId);
        $stmt->execute();
        $changed=$stmt->affected_rows;
        $stmt->close();
        return $changed===1;
    } catch (Throwable $error) { return false; }
}

/** Never release the user's reserved money on ambiguous external evidence. */
function achbotAutoRenewNeedsReview(mysqli $db, int $attemptId): void {
    try {
        $stmt=$db->prepare("UPDATE automatic_renewal_attempts
            SET stage='review',reason_code='remote_unconfirmed'
            WHERE id=? AND stage='remote_started'");
        $stmt->bind_param('i',$attemptId);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $ignored) {
        // The durable remote_started marker is itself actionable for review.
    }
}

/**
 * Remote success is independently verified by editMarzbanConfig, but never
 * blindly replayable. Commit local order, payment and accounting all together.
 */
function achbotAutoRenewFinalize(mysqli $db, array $claim): bool {
    $orderId=(int)$claim['order_id'];
    $attemptId=(int)$claim['id'];
    try {
        $db->begin_transaction();
        $stmt=$db->prepare("SELECT expire_date,userid FROM orders_list
            WHERE id=? FOR UPDATE");
        $stmt->bind_param('i',$orderId);
        $stmt->execute();
        $order=$stmt->get_result()->fetch_assoc();
        $stmt->close();
        $stmt=$db->prepare("SELECT id,stage,pay_row_id,user_id,price_toman,
                cycle_expire,new_expire FROM automatic_renewal_attempts
            WHERE id=? AND order_id=? FOR UPDATE");
        $stmt->bind_param('ii',$attemptId,$orderId);
        $stmt->execute();
        $attempt=$stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$order || !$attempt || $attempt['stage']!=='remote_started' ||
            (int)$order['expire_date']!==(int)$claim['cycle_expire'] ||
            (int)$attempt['cycle_expire']!==(int)$claim['cycle_expire'] ||
            (int)$attempt['new_expire']!==(int)$claim['new_expire'] ||
            (int)$order['userid']!==(int)$claim['user_id'] ||
            (int)$attempt['user_id']!==(int)$claim['user_id'] ||
            (int)$attempt['price_toman']!==(int)$claim['amount'] ||
            (int)$attempt['pay_row_id']!==(int)$claim['pay_id']) {
            $db->rollback(); return false;
        }
        $stmt=$db->prepare("UPDATE pays SET state='paid_with_wallet'
            WHERE id=? AND user_id=? AND price=? AND state='processing_wallet'
              AND type='RENEW_ACCOUNT' AND order_id=?");
        $stmt->bind_param('iiii',$claim['pay_id'],$claim['user_id'],
            $claim['amount'],$orderId);
        $stmt->execute();
        $saved=$stmt->affected_rows;
        $stmt->close();
        if ($saved!==1) { $db->rollback(); return false; }
        $stmt=$db->prepare("INSERT INTO increase_order
            (userid,server_id,inbound_id,remark,amount,date)
            VALUES (?,?,?,?,?,?)");
        $zero=0;
        $now=time();
        $stmt->bind_param('iiisii',$claim['user_id'],$claim['server_id'],
            $zero,$claim['remark'],$claim['amount'],$now);
        $stmt->execute();
        $stmt->close();
        $stmt=$db->prepare("UPDATE orders_list SET expire_date=?,notif=0
            WHERE id=? AND expire_date=? AND userid=?");
        $stmt->bind_param('iiii',$claim['new_expire'],$orderId,
            $claim['cycle_expire'],$claim['user_id']);
        $stmt->execute();
        $saved=$stmt->affected_rows;
        $stmt->close();
        if ($saved!==1) { $db->rollback(); return false; }
        $stmt=$db->prepare("UPDATE automatic_renewal_attempts SET stage='done'
            WHERE id=? AND stage='remote_started'");
        $stmt->bind_param('i',$attemptId);
        $stmt->execute();
        $saved=$stmt->affected_rows;
        $stmt->close();
        if ($saved!==1 || $db->commit()!==true)
            throw new RuntimeException('renewal_commit_unconfirmed');
        return true;
    } catch (Throwable $error) {
        try {$db->rollback();} catch (Throwable $ignored) {}
        error_log('achbot_auto_renew_finalization_deferred');
        return false;
    }
}

/** A duplicate invocation only sees the durable claim and does no work. */
function handleAutoRenewCallback($data): void {
    global $connection,$botState,$admin,$mainValues,$buttonValues;
    if (($botState['renewAutoState']??'off')!=='on' ||
        !preg_match('/^renewAuto_with_wallet\|([1-9][0-9]*)\|([1-9][0-9]*)$/D',
                    (string)$data,$m)) return;
    $userId=(int)$m[1];
    $orderId=(int)$m[2];
    $claim=achbotAutoRenewReserve($connection,$orderId,$userId);
    if ($claim['status']==='insufficient_funds') {
        try {
            sendMessage("❌ موجودی کیف پول برای تمدید خودکار کافی نیست؛ تمدید خودکار این اشتراک غیرفعال شد. لطفاً موجودی را افزایش دهید و سپس آن را دوباره فعال کنید.",
                null,null,$userId);
        } catch (Throwable $ignored) {}
        return;
    }
    if ($claim['status']!=='reserved') return;
    if (!achbotAutoRenewMarkRemoteStarted($connection,(int)$claim['id'])) {
        // Safe: no remote request started; durable reservation needs review.
        return;
    }
    try {
        $reply=editMarzbanConfig($claim['server_id'],[
            'remark'=>$claim['remark'],'days'=>$claim['days'],
            'volume'=>$claim['volume']
        ]);
        $success=(is_object($reply) && ($reply->success??false)===true)
            || (is_array($reply) && ($reply['success']??false)===true);
    } catch (Throwable $error) { $success=false; }
    if (!$success) {
        achbotAutoRenewNeedsReview($connection,(int)$claim['id']);
        return;
    }
    if (!achbotAutoRenewFinalize($connection,$claim)) {
        achbotAutoRenewNeedsReview($connection,(int)$claim['id']);
        return;
    }
    // Telegram delivery cannot revert a completed remote+financial outcome.
    try {
        sendMessage("✅ تمدید خودکار اشتراک انجام شد؛ مبلغ ".number_format($claim['amount']).
            " تومان از کیف پول شما کسر شد.",null,null,$userId);
    } catch (Throwable $ignored) {}
}

/** Alert authorized operator about durable reservation/reconciliation holds. */
function achbotAutoRenewAlertReview(mysqli $db, $adminChatId): void {
    if (!is_scalar($adminChatId) ||
        !preg_match('/^[1-9][0-9]*$/D',(string)$adminChatId)) return;
    try {
        $rows=$db->query("SELECT id,order_id,stage,reason_code
            FROM automatic_renewal_attempts
            WHERE stage IN ('reserved','remote_started','review')
              AND created_at < NOW() - INTERVAL 5 MINUTE
              AND (review_notified_at IS NULL OR
                   review_notified_at < NOW() - INTERVAL 24 HOUR)
            ORDER BY updated_at ASC LIMIT 3");
        while ($rows && ($item=$rows->fetch_assoc())) {
            $id=(int)$item['id'];
            $token=bin2hex(random_bytes(16));
            $stmt=$db->prepare("UPDATE automatic_renewal_attempts
                SET review_claim_token=?,review_claimed_at=NOW()
                WHERE id=? AND stage=? AND
                  (review_claim_token IS NULL OR
                   review_claimed_at < NOW() - INTERVAL 5 MINUTE)
                  AND (review_notified_at IS NULL OR
                       review_notified_at < NOW() - INTERVAL 24 HOUR)");
            $stmt->bind_param('sis',$token,$id,$item['stage']);
            $stmt->execute();
            $claimed=$stmt->affected_rows===1;
            $stmt->close();
            if (!$claimed) continue;
            $reason=preg_replace('/[^a-z_]/','',(string)($item['reason_code']??'none'));
            $msg="AChBot automatic renewal requires operator reconciliation\n".
                "Attempt: ".$id."\nOrder: ".(int)$item['order_id'].
                "\nStage: ".$item['stage']."\nReason: ".$reason.
                "\nDo not replay remote renewal or refund until checked.";
            $sent=false;
            try {
                $reply=sendMessage($msg,null,null,(int)$adminChatId);
                $sent=is_object($reply) && ($reply->ok??false)===true;
            } catch (Throwable $ignored) {}
            if ($sent) {
                $stmt=$db->prepare("UPDATE automatic_renewal_attempts
                    SET review_notified_at=NOW(),review_claim_token=NULL,
                        review_claimed_at=NULL
                    WHERE id=? AND stage=? AND review_claim_token=?");
                $stmt->bind_param('iss',$id,$item['stage'],$token);
                $stmt->execute();
                $stmt->close();
            } else {
                $stmt=$db->prepare("UPDATE automatic_renewal_attempts
                    SET review_claim_token=NULL,review_claimed_at=NULL
                    WHERE id=? AND review_claim_token=?");
                $stmt->bind_param('is',$id,$token);
                $stmt->execute();
                $stmt->close();
            }
        }
    } catch (Throwable $ignored) {
        error_log('achbot_auto_renew_review_alert_deferred');
    }
}

/**
 * The preexisting offset is a pagination cursor, not a success count: advance
 * it for *every* examined row, including unavailable/retired accounts.
 */
function achbotAutoRenewSweep(mysqli $db, array $botState): void {
    if (($botState['renewAutoState']??'off')!=='on') return;
    $stmt=$db->prepare("SELECT value FROM setting WHERE type='AutoRenewCheck' LIMIT 1");
    $stmt->execute();
    $row=$stmt->get_result()->fetch_assoc();
    $stmt->close();
    $offset=max(0,(int)($row['value']??0));
    $stmt=$db->prepare("SELECT id,userid,server_id,remark
        FROM orders_list WHERE status=1 AND notif<>0 AND autoRenew='on'
        ORDER BY id ASC LIMIT 50 OFFSET ?");
    $stmt->bind_param('i',$offset);
    $stmt->execute();
    $orders=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($orders as $order) {
        $orderId=(int)$order['id'];
        if (!achbotSupportsMarzbanOrder($db,$orderId)) continue;
        $remote=getMarzbanUser((int)$order['server_id'],(string)$order['remark']);
        if (!is_object($remote) || !isset($remote->username,$remote->expire,
            $remote->data_limit,$remote->used_traffic) ||
            (string)$remote->username!==(string)$order['remark'] ||
            !is_numeric($remote->expire) || !is_numeric($remote->data_limit) ||
            !is_numeric($remote->used_traffic)) continue;
        $expires=(int)$remote->expire;
        $remaining=(float)$remote->data_limit-(float)$remote->used_traffic;
        if ($expires>0 && ($expires<time()+7200 ||
            ((float)$remote->data_limit>0 && $remaining<0.2*1073741824))) {
            handleAutoRenewCallback('renewAuto_with_wallet|'.$order['userid'].'|'.$orderId);
        }
    }
    // Reproduce the preexisting offset contract with correct row counting.
    $next=count($orders)<50 ? 0 : $offset+count($orders);
    $stringNext=(string)$next;
    if ($row) {
        $stmt=$db->prepare("UPDATE setting SET value=?
            WHERE type='AutoRenewCheck'");
        $stmt->bind_param('s',$stringNext);
    } else {
        $stmt=$db->prepare("INSERT INTO setting (type,value)
            VALUES ('AutoRenewCheck',?)");
        $stmt->bind_param('s',$stringNext);
    }
    $stmt->execute();
    $stmt->close();
}

try {
    // Do not activate on an unmigrated instance; in particular, the existing
    // disabled auto-renewal default must remain inert and non-noisy.
    $schema=$connection->query("SHOW TABLES LIKE 'automatic_renewal_attempts'");
    $ready=$schema instanceof mysqli_result && $schema->num_rows===1;
    if ($ready) {
        // Alert even with renewal OFF: an earlier reserved effect still needs
        // reconciliation after an incident shutdown.
        achbotAutoRenewAlertReview($connection,$admin??null);
        achbotAutoRenewSweep($connection,$botState??[]);
    } elseif (($botState['renewAutoState']??'off')==='on') {
        error_log('achbot_auto_renew_migration_required');
    }
} catch (Throwable $error) {
    // SQL/schema/connection failure cannot trigger a debit or remote edit.
    error_log('achbot_auto_renew_sweep_deferred');
}
