<?php
/** AChBot post-V9: safe reassignment between logical entries of one Marzban panel. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../messages/loader.php'; // ACHBOT_TEXT_CATALOG


// Keep historic callback shapes. Values embedded in callback_data are NEVER
// used as authority; user ownership, current source and panel identity
// are re-read from the database each time.
if (preg_match('/^switchLocation([0-9]+)_([0-9]+)_[^_]{1,40}_[^_]{1,40}$/D', (string)($data ?? ''), $match)) {
    if (($botState['switchLocationState'] ?? 'off') !== 'on') {
        alert(\achbotMessage('subscription.account_transfers.alert.6b5b63fb0f'), true);
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
        !achbotSupportsOrder($connection, $orderId)) {
        alert(\achbotMessage('subscription.account_transfers.alert.2c082acb46'), true);
        return;
    }

    $targets = achbotProviderRelocationTargets($connection, $sourceId);
    if (!$targets) {
        alert(\achbotMessage('subscription.account_transfers.alert.33278b6a77'), true);
        return;
    }
    $buttons = [];
    foreach ($targets as $server) {
        $buttons[] = ['text'=>$server['title'],
                      'callback_data'=>'switchServer' . (int)$server['id'] . '_' . $orderId];
    }
    $keyboard = array_chunk($buttons, 2);
    $keyboard[] = [['text'=>$buttonValues['back_button'] ?? \achbotMessage('subscription.account_transfers.text.b233883d0d'),
                    'callback_data'=>'orderDetails' . $orderId]];
    editText($message_id,
        \achbotMessage('subscription.account_transfers.message.3f6bd6c3c8'),
        json_encode(['inline_keyboard'=>$keyboard], JSON_UNESCAPED_UNICODE));
    return;
}

if (preg_match('/^switchServer([0-9]+)_([0-9]+)$/D', (string)($data ?? ''), $match)) {
    if (($botState['switchLocationState'] ?? 'off') !== 'on') {
        alert(\achbotMessage('subscription.account_transfers.alert.6b5b63fb0f'), true);
        return;
    }
    $targetId = (int)$match[1];
    $orderId = (int)$match[2];
    $result = achbotReassignLogicalServer(
        $connection, $orderId, $targetId, $from_id, false
    );
    if (!$result) {
        alert(\achbotMessage('subscription.account_transfers.alert.4da40d7d6f'), true);
        return;
    }

    $title = htmlspecialchars((string)$result['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $remark = htmlspecialchars((string)$result['remark'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    editText($message_id, \achbotMessage('subscription.account_transfers.message.9d93daa0a7', ['remark' => $remark, 'title' => $title]),
        json_encode(['inline_keyboard'=>[[['text'=>$buttonValues['back_button'] ?? \achbotMessage('subscription.account_transfers.text.b233883d0d'),
        'callback_data'=>'orderDetails' . $orderId]]]], JSON_UNESCAPED_UNICODE));
    $adminMessage = \achbotMessage('subscription.account_transfers.admin_message.6645c1fd4d')
        . "UserID: " . (int)$result['userid'] . "\n"
        . "OrderID: " . (int)$result['id'] . "\n"
        . "Server: " . (int)$result['source_id'] . \achbotMessage('subscription.account_transfers.text.592f6fd075') . (int)$result['target_id'];
    sendToAllAdmins('sendMessage', ['text'=>$adminMessage]);
    return;
}
