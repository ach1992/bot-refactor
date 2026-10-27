<?php
/** AChBot Persian copy; keys are stable module/context/purpose identities. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {http_response_code(404);exit;}
return [
    'warnings.send.1e5349d9b2' => '1 روز',
    'warnings.send.0458ad0612' => '0.5 گیگ',
    'warnings.msg.804056ecc4' => ['text' => '💡 کاربر گرامی، اشتراک  <code>{remark}</code>  به زودی تمام می شود. کمتر از  <b>{send}</b>  مانده است. ' . "\n" . '🔰 می‌توانید از ← 🔄 <b>مشاهده اشتراک ها</b> آن را تمدید کنید یا اشتراک جدید خریداری کنید.  ' . "\n" . '🛑 بعد از اتمام در صورتی که <b>تا 3 روز</b> تمدید نکنید از لیست اشتراک های شما حذف می شود.' . "\n" . '💳 می توانید <b>قبل از اتمام، کیف پول خودتان را شارژ کنید</b> و از بخش <b>مشاهده اشتراک ها تمدید خودکار را روشن کنید</b> تا به صورت خودکار وقتی اشتراک تمام شد تمدید شود.', 'parameters' => ['remark', 'send']],
    'warnings.msg.c7907b6342' => ['text' => '💡 کاربر گرامی، اشتراک  <code>{remark}</code>  تمام شده بود و از لیست اشتراک ها حذف گردید. لطفا اشتراک جدید خریداری کنید.', 'parameters' => ['remark']],
    'warnings.channel_message.5e59a4a33e' => ['text' => '' . "\n" . '❌ اشتراک منقضی شده حذف شد.' . "\n" . '' . "\n" . '👤 آیدی کاربر: {from_id}' . "\n" . '🎈 نام کاربری سرویس: {remark}' . "\n" . '', 'parameters' => ['from_id', 'remark']],
];
