<?php
/**
 * Legacy bulk gift worker, source-only remediation for PR #66 R1.
 *
 * A negative gift_list.offset is a durable, fail-closed operator hold:
 *   held recipient index = -offset - 1.
 * Its exact recipient identity and requested units are in a setting row with
 * type ACHBOT_GIFT_HOLD_<gift id>. Never auto-restart a held external edit.
 * No schema changes and no unattended provider reconciliation are performed.
 */
include_once __DIR__ . '/../baseInfo.php';
include_once __DIR__ . '/../config.php';

$stmt=$connection->prepare('SELECT * FROM gift_list ORDER BY id LIMIT 1');
if (!$stmt || !$stmt->execute()) return;
$gift=$stmt->get_result()->fetch_assoc();$stmt->close();
if (!$gift) return;

$rowId=(int)$gift['id'];
$serverId=(int)$gift['server_id'];
$volume=(int)$gift['volume'];
$day=(int)$gift['day'];
$offset=(int)$gift['offset'];
$holdType='ACHBOT_GIFT_HOLD_'.$rowId;

// Even if an operator manually resets the offset, an unresolved hold record
// prevents a second external edit. Only a separately audited reconciliation
// can safely clear both the hold and its corresponding cursor.
$check=$connection->prepare('SELECT id FROM setting WHERE type=? LIMIT 1');
if (!$check) return;
$check->bind_param('s',$holdType);
if (!$check->execute()) { $check->close(); return; }
$unresolved=(bool)$check->get_result()->fetch_assoc();
$check->close();
if ($offset<0 || $unresolved) return;

$notifyHold=static function(string $remark,int $index,string $reason) use (
    $admin,$rowId,$serverId,$holdType
): void {
    $who=$remark===''?'(invalid job parameters)':$remark;
    // Exact row/index/user are operator-only. Never announce unverified
    // provider effects to the customer or expose provider credentials.
    sendMessage(
        "بررسی دستی هدیه لازم است. شناسه: {$rowId}؛ سرور: {$serverId}؛ ".
        "ردیف: {$index}؛ کاربر: {$who}؛ علت: {$reason}. ".
        "تکرار خودکار متوقف شد. رکورد: $holdType",
        null,null,$admin
    );
};

$reserve=static function(int $index,string $remark,string $reason) use (
    $connection,$rowId,$serverId,$volume,$day,$holdType
): array {
    if ($index<0 || $index>=2147483647)
        throw new RuntimeException('gift_cursor_out_of_range');
    $held=-$index-1;
    $details=json_encode([
        'gift_id'=>$rowId,'server_id'=>$serverId,'recipient_index'=>$index,
        'username'=>$remark,'volume_mb'=>$volume,'days'=>$day,
        'stage'=>'before_external_effect','reason'=>$reason,
        'created_at'=>gmdate('c')
    ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
    if (!$connection->begin_transaction())
        throw new RuntimeException('gift_hold_begin_failed');
    try {
        $check=$connection->prepare('SELECT id FROM setting WHERE type=? LIMIT 1 FOR UPDATE');
        if (!$check) throw new RuntimeException('gift_hold_check_prepare');
        $check->bind_param('s',$holdType);
        $ok=$check->execute();$existing=$ok?$check->get_result()->fetch_assoc():true;
        $check->close();
        if ($existing) throw new RuntimeException('gift_hold_already_exists');

        $stmt=$connection->prepare('UPDATE gift_list SET `offset`=? WHERE id=? AND `offset`=?');
        if (!$stmt) throw new RuntimeException('gift_hold_prepare');
        $stmt->bind_param('iii',$held,$rowId,$index);
        $ok=$stmt->execute();$count=$stmt->affected_rows;$stmt->close();
        if (!$ok || $count!==1) throw new RuntimeException('gift_hold_cursor_changed');

        $stmt=$connection->prepare('INSERT INTO setting (type,value) VALUES (?,?)');
        if (!$stmt) throw new RuntimeException('gift_hold_journal_prepare');
        $stmt->bind_param('ss',$holdType,$details);
        $ok=$stmt->execute();$holdId=(int)$stmt->insert_id;$stmt->close();
        if (!$ok || $holdId<=0) throw new RuntimeException('gift_hold_journal_failed');
        if ($connection->commit()!==true)
            throw new RuntimeException('gift_hold_commit_unconfirmed');
        return [$held,$holdId];
    } catch (Throwable $e) {
        try { $connection->rollback(); } catch (Throwable $ignored) {}
        // An uncertain COMMIT may have made this hold durable. No external
        // request is permitted if the hold acknowledgement was not confirmed.
        throw $e;
    }
};

$finish=static function(
    int $held,int $holdId,int $next,?array $order,?int $absoluteExpire
) use ($connection,$rowId,$serverId,$holdType): void {
    if (!$connection->begin_transaction())
        throw new RuntimeException('gift_finish_begin_failed');
    try {
        if ($order!==null && $absoluteExpire!==null) {
            if ($absoluteExpire<=0 || $absoluteExpire>2147483647)
                throw new RuntimeException('gift_expiry_unrepresentable');
            $stmt=$connection->prepare(
                'UPDATE orders_list SET expire_date=? WHERE id=? AND userid=? AND server_id=? AND remark=? AND expire_date=?');
            if (!$stmt) throw new RuntimeException('gift_local_update_prepare');
            $id=(int)$order['id'];$uid=(int)$order['userid'];
            $remark=(string)$order['remark'];
            $beforeExpire=(int)$order['expire_date'];
            $stmt->bind_param('iiiisi',$absoluteExpire,$id,$uid,$serverId,$remark,$beforeExpire);
            $ok=$stmt->execute();$count=$stmt->affected_rows;$stmt->close();
            // A missing or already changed row requires operator review,
            // never a blind replay of the successfully applied external edit.
            if (!$ok || $count!==1)
                throw new RuntimeException('gift_local_update_unconfirmed');
        }

        $stmt=$connection->prepare('UPDATE gift_list SET `offset`=? WHERE id=? AND `offset`=?');
        if (!$stmt) throw new RuntimeException('gift_finish_cursor_prepare');
        $stmt->bind_param('iii',$next,$rowId,$held);
        $ok=$stmt->execute();$count=$stmt->affected_rows;$stmt->close();
        if (!$ok || $count!==1)
            throw new RuntimeException('gift_finish_cursor_unconfirmed');

        $stmt=$connection->prepare('DELETE FROM setting WHERE id=? AND type=?');
        if (!$stmt) throw new RuntimeException('gift_finish_hold_prepare');
        $stmt->bind_param('is',$holdId,$holdType);
        $ok=$stmt->execute();$count=$stmt->affected_rows;$stmt->close();
        if (!$ok || $count!==1)
            throw new RuntimeException('gift_finish_hold_unconfirmed');
        if ($connection->commit()!==true)
            throw new RuntimeException('gift_finish_commit_unconfirmed');
    } catch (Throwable $e) {
        try { $connection->rollback(); } catch (Throwable $ignored) {}
        throw $e;
    }
};

$stmt=$connection->prepare('SELECT title FROM server_info WHERE id=?');
if (!$stmt) return;
$stmt->bind_param('i',$serverId);
if (!$stmt->execute()) { $stmt->close(); return; }
$serverName=(string)($stmt->get_result()->fetch_assoc()['title']??'');
$stmt->close();

$stmt=$connection->prepare('SELECT type FROM server_config WHERE id=?');
if (!$stmt) return;
$stmt->bind_param('i',$serverId);
if (!$stmt->execute()) { $stmt->close(); return; }
$serverType=(string)($stmt->get_result()->fetch_assoc()['type']??'');
$stmt->close();

if (!achbotSupportsPanel($serverType)) {
    sendMessage('پنل انتخابی پشتیبانی نمی‌شود؛ عملیات هدیه متوقف شد.',null,null,$admin);
    return;
}

if ($volume<0 || $day<0 || ($volume===0 && $day===0) ||
    ($volume>0 && $volume%1024!==0)) {
    try {
        $reserve($offset,'','gift_units_unsupported');
        $notifyHold('',$offset,'gift_units_unsupported');
    } catch (Throwable $e) {
        sendMessage('امکان ثبت توقف امن هدیه وجود ندارد؛ نیاز به بررسی مدیر.',null,null,$admin);
    }
    return;
}

$change=['remark'=>''];
if ($volume>0) $change['plus_volume']=intdiv($volume,1024);
if ($day>0) $change['plus_day']=$day;

$listed=getMarzbanJson($serverId);
if (!is_object($listed) || !is_array($listed->users??null)) {
    sendMessage('فهرست کاربران هدیه تأیید نشد؛ هیچ تغییری ثبت نشد.',null,null,$admin);
    return;
}
if ($offset===0) {
    sendMessage("عملیات هدیه حجم و زمان سرور $serverName شروع شد.",null,null,$admin);
    sendToAllAdmins('sendMessage',['text'=>"عملیات هدیه حجم و زمان سرور $serverName شروع شد.",
        'reply_markup'=>null,'parse_mode'=>null]);
}

$found=false;
foreach ($listed->users as $index=>$user) {
    if (!is_int($index) || $index<$offset) continue;
    $found=true;
    if ($index!==$offset) {
        // Never silently skip a missing or reordered cursor position.
        sendMessage('فهرست کاربران هدیه جابه‌جا شده؛ بررسی دستی لازم است.',null,null,$admin);
        return;
    }
    $remark=(string)($user->username??'');
    $eligible=$remark!=='' && is_numeric($user->data_limit??null) &&
        is_numeric($user->expire??null) &&
        (float)$user->data_limit>0 && (float)$user->expire>0 &&
        ($user->status??null)==='active';
    if (!$eligible) {
        $next=$index+1;
        $skip=$connection->prepare('UPDATE gift_list SET `offset`=? WHERE id=? AND `offset`=?');
        if (!$skip) return;
        $skip->bind_param('iii',$next,$rowId,$index);
        $ok=$skip->execute();$count=$skip->affected_rows;$skip->close();
        if (!$ok || $count!==1) return;
        $offset=$next;
        continue;
    }

    $orderStmt=$connection->prepare(
        'SELECT id,userid,remark,expire_date FROM orders_list WHERE server_id=? AND inbound_id=0 AND remark=? ORDER BY id LIMIT 2');
    if (!$orderStmt) return;
    $orderStmt->bind_param('is',$serverId,$remark);
    $ok=$orderStmt->execute();
    $orders=$ok?$orderStmt->get_result()->fetch_all(MYSQLI_ASSOC):null;
    $orderStmt->close();
    if ($orders===null || count($orders)>1) {
        sendMessage('هویت سفارش هدیه مبهم است؛ عملیات متوقف شد.',null,null,$admin);
        return;
    }
    $order=$orders[0]??null;

    try {
        [$held,$holdId]=$reserve($index,$remark,'pending_service_edit');
    } catch (Throwable $e) {
        // No outbound edit without a confirmed durable review hold.
        sendMessage('ثبت توقف امن هدیه تأیید نشد؛ سرویس تغییر نکرد.',null,null,$admin);
        return;
    }

    try {
        $change['remark']=$remark;
        $result=achbotApplyServiceEdit($connection,$serverId,$change);
        $verified=is_object($result) &&
            ($result->success??false)===true &&
            ($result->verified??false)===true &&
            ($result->uncertain??false)!==true &&
            is_int($result->applied_expire??null) &&
            is_int($result->applied_bytes??null) &&
            $result->applied_expire>0 && $result->applied_bytes>=0;
        if (!$verified) {
            if (is_object($result) &&
                ($result->success??null)===false &&
                ($result->uncertain??null)===false) {
                // A validated deterministic refusal has no remote effect.
                $finish($held,$holdId,$index+1,null,null);
                sendMessage("اعمال هدیه برای ردیف $index رد شد؛ هیچ موفقیتی اعلام نشد.",
                            null,null,$admin);
                $offset=$index+1;
                continue;
            }
            throw new RuntimeException('gift_remote_effect_unverified');
        }

        $absoluteExpire=$day>0 ? $result->applied_expire : null;
        // Absolute verified readback, not an unchecked local +days guess.
        $finish($held,$holdId,$index+1,$order,$absoluteExpire);
        $offset=$index+1;

        if ($order!==null) {
            $giftText='';
            if ($volume>0) $giftText.="$volume مگابایت حجم";
            if ($day>0) $giftText.=($giftText===''?'':' و ')." $day روز زمان";
            // Telegram delivery can fail after a confirmed provider effect
            // and local COMMIT. It cannot turn a successful gift into a
            // pending external-effect hold or authorize another edit.
            try {
                $notice=sendMessage("به اشتراک $remark شما $giftText اضافه شد",
                    null,null,(int)$order['userid']);
                if (!is_object($notice) || ($notice->ok??false)!==true)
                    sendMessage("هدیه ثبت شد اما اعلان کاربر تأیید نشد. ".
                        "شناسه: {$rowId}؛ کاربر: {$remark}",null,null,$admin);
            } catch (Throwable $notificationError) {
                try {
                    sendMessage("هدیه ثبت شد اما ارسال اعلان کاربر ناموفق بود. ".
                        "شناسه: {$rowId}؛ کاربر: {$remark}",null,null,$admin);
                } catch (Throwable $ignored) {}
            }
        }
    } catch (Throwable $e) {
        // The negative cursor and exact-recipient hold remain persisted.
        // Never retry an unknown external effect during the next Cron tick.
        $notifyHold($remark,$index,'gift_requires_operator_reconciliation');
        return;
    }
}
if (!$found) {
    $stmt=$connection->prepare('DELETE FROM gift_list WHERE id=? AND `offset`=?');
    if (!$stmt) return;
    $stmt->bind_param('ii',$rowId,$offset);
    $ok=$stmt->execute();$count=$stmt->affected_rows;$stmt->close();
    if ($ok && $count===1) {
        sendMessage("عملیات هدیه حجم و زمان سرور $serverName به اتمام رسید.",
                    null,null,$admin);
        sendToAllAdmins('sendMessage',['text'=>"عملیات هدیه حجم و زمان سرور $serverName به اتمام رسید.",
            'reply_markup'=>null,'parse_mode'=>null]);
    }
}
