<?php
/** AChBot V8: source-pinned financial compatibility module. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../messages/loader.php'; // ACHBOT_TEXT_CATALOG
require_once __DIR__.'/../Admin/Access.php';


require_once __DIR__.'/CheckoutAdmission.php';
achbotCheckoutRequireSchema($connection);
if (($botState['cartToCartAutoAcceptState']??'off')==='on') {
    $q=$connection->query("SELECT p.id,p.user_id FROM pays p JOIN payment_attempts a ON a.pay_row_id=p.id
        WHERE p.state='have_sent' AND a.provider='bank_transfer' AND a.stage='review'
          AND a.wallet_invoice_sha256<>'' AND a.wallet_credit_entry_id IS NULL AND a.evidence_profile IS NULL
          AND a.review_archived_at IS NULL
        ORDER BY COALESCE(a.last_checked_at,a.created_at),a.id LIMIT 12");
    foreach ($q->fetch_all(MYSQLI_ASSOC) as $row) {
        $id=(int)$row['id'];$uid=(int)$row['user_id'];
        try {
            $s=achbotProviderStatement($connection,'UPDATE payment_attempts SET last_checked_at=NOW() WHERE pay_row_id=?','i',[$id]);$s->close();
            if (!$connection->begin_transaction()) throw new RuntimeException('bank_timer_begin');
            [$pay,$attempt]=achbotCreditInvoiceLocked($connection,$id,$uid);
            if (achbotCreditBankTimerEligibleLocked($connection,$pay,$attempt))
                achbotCheckoutSettleReceiptLocked($connection,$id,$uid,'bank_timer');
            if (!$connection->commit()) throw new RuntimeException('bank_timer_commit');
        } catch (Throwable $e) {
            $connection->rollback();
            achbotDiagnosticEvent('achbot.finance.bank_timer',['pay_id'=>$id,'reason'=>'checkout_timer_deferred','class'=>get_class($e)],'warning');
        }
    }
}
