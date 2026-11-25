<?php
/** AChBot V8: source-pinned financial compatibility module. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../messages/loader.php'; // ACHBOT_TEXT_CATALOG


require_once __DIR__.'/CheckoutAdmission.php';
require_once __DIR__.'/HostedReceipt.php';

function achbotHostedBrowserReturn(mysqli $db,string $method,string $reference,string $hash): void {
    require_once __DIR__.'/HostedReceipt.php';
    $state='review';
    try {
        achbotCheckoutRequireSchema($db);
        if ($method==='nowpayments') {
            // A browser never establishes a new child identity. Only the
            // authenticated merchant IPN can admit the child for verification.
            $p=achbotProviderRow($db,"SELECT p.state,a.wallet_credit_entry_id
                FROM pays p JOIN payment_attempts a ON a.pay_row_id=p.id
                WHERE p.hash_id=? AND a.order_hash=? AND a.provider='nowpayments'",'ss',[$hash,$hash]);
            if ($p && $p['wallet_credit_entry_id']!==null && $p['state']==='paid') $state='paid';
            elseif ($p && in_array($p['state'],['pending','send'],true)) $state='pending';
        } elseif (in_array($method,['zarinpal','nextpay'],true) &&
            achbotHostedReferenceSyntax($reference) &&
            ($method!=='zarinpal' || $hash!=='')) {
            $s=achbotProviderStatement($db,"SELECT p.id,p.user_id,p.hash_id FROM pays p
                JOIN payment_attempts a ON a.pay_row_id=p.id
                WHERE a.provider=? AND a.provider_reference=? LIMIT 2",'ss',[$method,$reference]);
            $rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
            if (count($rows)===1 && ($hash==='' || $rows[0]['hash_id']===$hash)) {
                $p=$rows[0];$id=(int)$p['id'];
                $lease=achbotOriginalDispatchLeaseBegin($db,$id);
                if (in_array($lease,['owned','borrowed'],true)) {
                    try {
                        $r=achbotHostedReceiptVerify($db,$id,(int)$p['user_id'],$method,$reference);
                        if ($r['status']==='credited') $state='paid';
                    } finally {achbotOriginalDispatchLeaseEnd($db,$id,$lease);}
                }
            }
        }
    } catch (Throwable $e) {
        achbotDiagnosticEvent('achbot.finance.hosted_browser',['provider'=>$method,'reason'=>'original_merchant_return_deferred','class'=>get_class($e)],'warning');
    }
    if ($state==='paid')
        showForm(achbotMessage('gateway.gateway_return.np_reconcile_batch.message.0116c30fc4'),
            achbotMessage('gateway.gateway_return.np_reconcile_batch.message.5cf3e172d8'),true,'review');
    elseif ($state==='pending')
        showForm(achbotMessage('gateway.gateway_return.np_reconcile_batch.message.e46087af54'),
            achbotMessage('gateway.gateway_return.np_reconcile_batch.message.f71cd91dad'),true,'pending');
    else showForm(achbotMessage('gateway.gateway_return.np_reconcile_batch.message.c4374c5a30'),
            achbotMessage('gateway.gateway_return.np_reconcile_batch.message.da0bc406d8'),false,'review');
}


if (!defined('ACHBOT_NP_RECONCILE_ONLY')) {
    if (isset($_GET['nowpayment_ipn'])) {
        $code=405;
        if (($_SERVER['REQUEST_METHOD']??'')==='POST') {
            try {
                achbotCheckoutRequireSchema($connection);
                $code=achbotHostedReceiptIngestIpn($connection,
                    (string)file_get_contents('php://input'),
                    (string)($_SERVER['HTTP_X_NOWPAYMENTS_SIG']??''));
            } catch (Throwable $e) {
                achbotDiagnosticEvent('achbot.finance.hosted_ipn',
                    ['reason'=>'schema_or_connection_unavailable','class'=>get_class($e)],'error');
                $code=503;
            }
        }
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['accepted'=>$code===200],JSON_UNESCAPED_SLASHES);
    } elseif (isset($_GET['nowpayment'])) {
        achbotHostedBrowserReturn($connection,'nowpayments',(string)($_GET['NP_id']??''),(string)($_GET['hash_id']??''));
    } elseif (isset($_GET['zarinpal'])) {
        achbotHostedBrowserReturn($connection,'zarinpal',(string)($_GET['Authority']??''),(string)($_GET['hash_id']??''));
    } elseif (isset($_GET['nextpay'])) {
        achbotHostedBrowserReturn($connection,'nextpay',(string)($_GET['trans_id']??''),(string)($_GET['hash_id']??''));
    } else {
        showForm(achbotMessage('gateway.gateway_return.np_reconcile_batch.message.1d15981671'),
            achbotMessage('gateway.gateway_return.np_reconcile_batch.message.f48a802b77'));
    }
}

/**
 * Shared browser feedback surface for every supported redirect gateway.
 * Rendering a return page is never itself merchant settlement evidence.
 */
function showForm($msg, $type = "", $state = false, $presentation = null) {
    // A merchant browser return is a presentation context; Cron and the
    // owner's private Telegram callback are not. Suppress BOTH body and
    // payment-page HTTP headers at this boundary, not only output buffering.
    if (defined('ACHBOT_NP_RECONCILE_ONLY')) return;
    require_once __DIR__ . '/PaymentPage.php';
    // $presentation is derived from the caller's already-trusted outcome.
    // The legacy $state and browser-facing message are not proof of delivery.
    achbotRenderPaymentPage($msg, $type, $state, $presentation);
}
