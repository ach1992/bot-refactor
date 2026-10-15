<?php
/** AChBot post-V9: safe reassignment between logical entries of one Marzban panel. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

// Keep historic callback shapes. Values embedded in callback_data are NEVER
// used as authority; user ownership, current source and panel identity
// are re-read from the database each time.
if (preg_match('/^switchLocation([0-9]+)_([0-9]+)_[^_]{1,40}_[^_]{1,40}$/D', (string)($data ?? ''), $match)) {
    if (($botState['switchLocationState'] ?? 'off') !== 'on') {
        alert("تغییر سرور غیرفعال است.", true);
        return;
    }
    $orderId = (int)$match[1];
    $sourceId = (int)$match[2];
    $stmt = $connection->prepare(
        "SELECT `id`, `server_id`, `inbound_id` FROM `orders_list`
         WHERE `id` = ? AND `userid` = ? AND `status` = 1 LIMIT 1"
    );
    $stmt->bind_param('ii', $orderId, $from_id);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$order || (int)$order['server_id'] !== $sourceId ||
        (int)$order['inbound_id'] !== 0 ||
        !achbotSupportsMarzbanOrder($connection, $orderId)) {
        alert("⛔️ سفارش یا سرور فعلی معتبر نیست.", true);
        return;
    }

    $targets = achbotMarzbanRelocationTargets($connection, $sourceId);
    if (!$targets) {
        alert("در حال حاضر سرور دیگری با همین پنل مرزبان برای تغییر سرویس موجود نیست.", true);
        return;
    }
    $buttons = [];
    foreach ($targets as $server) {
        $buttons[] = ['text'=>$server['title'],
                      'callback_data'=>'switchServer' . (int)$server['id'] . '_' . $orderId];
    }
    $keyboard = array_chunk($buttons, 2);
    $keyboard[] = [['text'=>$buttonValues['back_button'] ?? 'بازگشت',
                    'callback_data'=>'orderDetails' . $orderId]];
    editText($message_id,
        "📍 سرور مقصد را انتخاب کنید. فقط نام سرور در ربات تغییر می‌کند؛ حساب واقعی و کانفیگ‌های مرزبان تغییر نمی‌کنند.",
        json_encode(['inline_keyboard'=>$keyboard], JSON_UNESCAPED_UNICODE));
    return;
}

if (preg_match('/^switchServer([0-9]+)_([0-9]+)$/D', (string)($data ?? ''), $match)) {
    if (($botState['switchLocationState'] ?? 'off') !== 'on') {
        alert("تغییر سرور غیرفعال است.", true);
        return;
    }
    $targetId = (int)$match[1];
    $orderId = (int)$match[2];
    $result = achbotReassignMarzbanLogicalServer(
        $connection, $orderId, $targetId, $from_id, false
    );
    if (!$result) {
        alert("⛔️ انتقال مجاز نیست؛ سفارش باید متعلق به شما و هر دو سرور مربوط به یک پنل مرزبان باشند.", true);
        return;
    }

    $title = htmlspecialchars((string)$result['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $remark = htmlspecialchars((string)$result['remark'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    editText($message_id, "✅ سرویس {$remark} در ربات به سرور {$title} اختصاص یافت. تنظیمات حساب در مرزبان بدون تغییر باقی ماند.",
        json_encode(['inline_keyboard'=>[[['text'=>$buttonValues['back_button'] ?? 'بازگشت',
        'callback_data'=>'orderDetails' . $orderId]]]], JSON_UNESCAPED_UNICODE));
    $adminMessage = "🌐 تغییر سرور منطقی مرزبان در ربات\n"
        . "UserID: " . (int)$result['userid'] . "\n"
        . "OrderID: " . (int)$result['id'] . "\n"
        . "Server: " . (int)$result['source_id'] . " → " . (int)$result['target_id'];
    sendToAllAdmins('sendMessage', ['text'=>$adminMessage]);
    return;
}
