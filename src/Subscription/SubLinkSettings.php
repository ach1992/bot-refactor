<?php
/** AChBot V6: original Telegram account/subscription routes; keep caller scope. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

if(preg_match('/^editSubLinks(\d+)/', $data, $match) && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    delMessage();
    setUser("subLinksInput" . $match[1]);
    sendMessage("🔗 لطفاً لینک‌های کمکی اشتراک سرور را وارد کنید.\n📌 لینک‌ها را با کاما (,) از هم جدا کنید.\n\nمثال:\n`https://sub1.domain.com,https://sub2.domain.com`", $cancelKey, "Markdown");
    exit();
}
if(preg_match('/^subLinksInput(\d+)/', $userInfo['step'], $match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $rowId = $match[1];
    $subLinks = trim($text);

    // ذخیره در دیتابیس
    $stmt = $connection->prepare("UPDATE `server_config` SET `sub_links` = ? WHERE `id` = ?");
    $stmt->bind_param("si", $subLinks, $rowId);
    $stmt->execute();
    $stmt->close();

    sendMessage("✅ لینک‌های کمکی با موفقیت ذخیره شدند.", $removeKeyboard);

    // برگشت به منوی سرور
    $keys = getServerConfigKeys($rowId);
    sendMessage("☑️ مدیریت سرور:", $keys);
    setUser();
    exit();
}

