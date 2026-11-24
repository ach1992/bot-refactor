<?php
/**
 * Provider implementations of the same original-payment recovery contract.
 *
 * Finance, staff UI and renewal scheduling never branch on provider names.
 * Each adapter owns only its Native protocol, identity and mutation evidence.
 * No adapter charges money, edits a payment, or sends a customer message.
 */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__.'/../Diagnostics/EventLog.php';

/**
 * Originating CREATE/IMPORT rows pin their one local order. Historical
 * migrations keep a NULL origin and admit either an immutable old customer
 * reference OR a later independently proven PasarGuard external attachment.
 * The shared account-by-order validator owns both proofs and rechecks the
 * current owner, Native incarnation, imported origin and provider account.
 * Never rewrite the migration reference list or invent an originating order.
 */
function achbotPgRecoveryOrderOriginMatches(mysqli $db,array $ctx,int $orderId): bool {
    $account=$ctx['account']??null;
    $order=$ctx['order']??null;
    if ($orderId<=0 || !is_array($account) || !is_array($order) ||
        (int)($order['id']??0)!==$orderId ||
        (int)($order['provider_effect_id']??0)!==(int)($account['effect_id']??0))
        return false;
    if ($account['order_id']!==null)
        return (int)$account['order_id']===$orderId;
    try {
        // Already used by achbotPgOrderContext(): includes the immutable
        // migration refs AND modern ACHBOT_SERVICE_ORIGIN PasarGuard imports.
        // Recheck at recovery admission; the returned account must still be
        // precisely the same physical account captured by this payment.
        $validated=achbotProviderAccountByOrder($db,$orderId);
        return $validated!==null && $validated['order_id']===null &&
            $validated['id']===$account['id'] &&
            $validated['effect_id']===$account['effect_id'] &&
            $validated['panel_id']===$account['panel_id'] &&
            $validated['instance_key']===$account['instance_key'] &&
            $validated['endpoint_sha256']===$account['endpoint_sha256'] &&
            $validated['remote_user_id']===$account['remote_user_id'] &&
            $validated['remote_created_at']===$account['remote_created_at'] &&
            $validated['remote_username']===$account['remote_username'] &&
            $validated['operation_marker']===$account['operation_marker'];
    } catch (Throwable $error) {
        return false;
    }
}
