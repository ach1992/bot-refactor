<?php
/** Post-V9 safety gate: refuse legacy DB-only location switching. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

// Historical switchServer changed only orders_list.server_id. It never
// provisioned or removed a Marzban account and could corrupt the mapping.
// This is safe containment, not completed physical relocation. Re-enable
// only after the Owner accepts a recoverable two-provider transfer policy
// and both success and rollback are verified with original-schema E2E.
if (preg_match('/^(?:switchLocation.+_.+_.+_.+|switchServer.+_.+)$/', (string)($data ?? ''))) {
    alert("⛔️ انتقال سرور فعلاً غیرفعال است؛ هیچ تغییری روی سرویس شما انجام نشد. با پشتیبانی تماس بگیرید.", true);
    return;
}
