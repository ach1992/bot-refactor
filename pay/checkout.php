<?php
/** AChBot canonical public checkout controller and directly includable initiation implementation. */
require_once __DIR__ . '/../messages/loader.php'; // ACHBOT_TEXT_CATALOG

// Browser initiation is GET-only. In particular, HEAD must never create an
// invoice, and payment-provider POST/IPN remains owned by pay/back.php.
// Indirect library/test includes already supply their authenticated context.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        http_response_code(405);
        header('Allow: GET');
        header('Cache-Control: private, no-store, max-age=0');
        exit;
    }
    $checkoutRoot=dirname(__DIR__);
    if (!is_file($checkoutRoot.'/baseInfo.php') || !is_readable($checkoutRoot.'/baseInfo.php') ||
        !is_file($checkoutRoot.'/config.php') || !is_readable($checkoutRoot.'/config.php')) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: private, no-store, max-age=0');
        echo \achbotMessage('bot.bootstrap.unavailable');
        exit;
    }
    include_once $checkoutRoot.'/baseInfo.php';
    include_once $checkoutRoot.'/config.php';
    unset($checkoutRoot);
}

require_once __DIR__ . '/../src/Finance/PaymentLifecycle.php';

//====================//  Get  //==============================
$hash_id = (string)($_GET['hash_id'] ?? '');
if(!isset($_GET['zarinpal']) && !isset($_GET['nowpayment']) && !isset($_GET['nextpay'])){
    showForm(\achbotMessage('gateway.gateway_initiation.mark_checkout_uncertain.message.1f5b86dd86'));
    exit();
}

$stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ? AND `state` = 'pending'");
$stmt->bind_param("s", $hash_id);
$stmt->execute();
$payInfo = $stmt->get_result();
$stmt->close();

if(mysqli_num_rows($payInfo)==0){
    if (isset($_GET['nowpayment']) && $hash_id !== '') {
        $stmt = $connection->prepare("SELECT state FROM pays WHERE hash_id=? LIMIT 1");
        $stmt->bind_param('s',$hash_id);
        $stmt->execute();
        $existing=$stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (($existing['state'] ?? '')==='paid') {
            // A paid local row does not prove customer Telegram delivery.
            showForm(\achbotMessage('gateway.gateway_initiation.mark_checkout_uncertain.message.32264671e4'), 'review');
        } elseif ($existing) {
            // 'send' is in progress; other nonpending states require review.
            showForm(\achbotMessage('gateway.gateway_initiation.mark_checkout_uncertain.message.33526fc91c'),
                ($existing['state'] ?? '') === 'send' ? 'pending' : 'review');
        } else {
            showForm(\achbotMessage('gateway.gateway_initiation.mark_checkout_uncertain.message.2d0a6d7c50'));
        }
    } else {
        showForm(\achbotMessage('gateway.gateway_initiation.mark_checkout_uncertain.message.2d0a6d7c50'));
    }
}else{
    $payParam = $payInfo->fetch_assoc();
    // The URL is only a guarded *request*, never an irrevocable provider link.
    // Refuse stale drafts and older invoices before any checkout side effect.
    if (achbotPayUxExpireDraft($connection,(int)$payParam['id'])) {
        showForm(\achbotMessage('gateway.gateway_initiation.mark_checkout_uncertain.message.24fe31bc43'));
        exit;
    }
    try {
        if (achbotPayUxHasNewer($connection,$payParam)) {
            showForm(\achbotMessage('gateway.gateway_initiation.mark_checkout_uncertain.message.8cdb71a59f'), 'review');
            exit;
        }
    } catch (Throwable $ignored) {
        showForm(\achbotMessage('gateway.gateway_initiation.mark_checkout_uncertain.message.2186b9a9dd'), 'review');
        exit;
    }
    // Payment-audience restrictions apply at the exact first-party HTTP
    // checkout route, not merely in Telegram menus. A previously issued
    // provider claim remains available only for status/reconciliation.
    $checkoutMethod=isset($_GET['nowpayment']) ? 'nowpayments' :
        (isset($_GET['zarinpal']) ? 'zarinpal' : 'nextpay');
    $stateFlag=$checkoutMethod==='nowpayments'
        ? (((string)$payParam['type']==='INCREASE_WALLET') ? 'nowPaymentWallet' : 'nowPaymentOther')
        : $checkoutMethod;
    if (($botState[$stateFlag]??'off')!=='on' ||
        !achbotPayUxAudienceAllowed($connection,$checkoutMethod,(int)$payParam['user_id'])) {
        showForm(\achbotMessage('gateway.gateway_initiation.mark_checkout_uncertain.message.32573bc25a'));
        exit;
    }
    // A hosted invoice can remain payable upstream after a stale bot link
    // is removed. Stop showing/redirecting to it at the attention deadline,
    // but preserve the exact provider claim for asynchronous reconciliation.
    try {
        if (achbotPayUxHostedAttentionElapsed($connection,(int)$payParam['id'])) {
            showForm(\achbotMessage('gateway.gateway_initiation.mark_checkout_uncertain.message.2d6b333856'), 'review');
            exit;
        }
    } catch (Throwable $ignored) {
        showForm(\achbotMessage('gateway.gateway_initiation.mark_checkout_uncertain.message.eaf7a98f3e'), 'review');
        exit;
    }
    require_once __DIR__.'/../src/Finance/CheckoutAdmission.php';
    require_once __DIR__.'/../src/Finance/HostedReceipt.php';
    require_once __DIR__.'/../src/Finance/ReceiptHelpers.php';
    try {
        achbotCheckoutRequireSchema($connection);
        if (mysqli_num_rows($payInfo)!==1)
            throw new RuntimeException('checkout_original_hash_ambiguous');
        $originalAttempt=achbotProviderRow($connection,'SELECT provider FROM payment_attempts WHERE pay_row_id=?','i',[(int)$payParam['id']]);
        $s=achbotProviderStatement($connection,"SELECT value FROM setting WHERE type='PAYMENT_KEYS' LIMIT 2",'',[]);
        $keysRows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
        if (count($keysRows)!==1) throw new RuntimeException('checkout_merchant_config_ambiguous');
        $keys=json_decode($keysRows[0]['value'],true,32,JSON_THROW_ON_ERROR);
        if (!is_array($keys)) throw new RuntimeException('checkout_merchant_config_invalid');
        $price=(int)$payParam['price'];$quote=['local_price'=>$price];
        if ($checkoutMethod==='nowpayments' && $originalAttempt===null)
            $quote=achbotNowpaymentsQuoteInput($price,$keys,get_defined_vars());
        $r=achbotHostedReceiptIssue($connection,(int)$payParam['id'],(int)$payParam['user_id'],
            $checkoutMethod,null,$quote,$keys,$botUrl,payTypeToDescription($payParam['type']),
            $botState,(string)($nowpaymentsIpnSecret??''));
        if ($r['status']==='issued' && is_string($r['url']??null) && $r['url']!=='') {
            if ($checkoutMethod==='nowpayments' && !empty($r['newly_issued']))
                achbotCheckoutNotifyHostedInvoice($connection,$payParam,$botUrl,$hash_id);
            header('Location: '.$r['url'],true,302);
            exit;
        }
        showForm(achbotMessage('gateway.gateway_initiation.mark_checkout_uncertain.message.33526fc91c'),'review');
    } catch (Throwable $e) {
        achbotDiagnosticEvent('achbot.finance.hosted_issue',['pay_id'=>(int)$payParam['id'],
            'provider'=>$checkoutMethod,'reason'=>'original_checkout_deferred','class'=>get_class($e)],'warning');
        showForm(achbotMessage('gateway.gateway_initiation.mark_checkout_uncertain.message.eaf7a98f3e'),'review');
    }
    exit;
}

// The controller alone can supply the payment disposition. Free-form
// customer text and failed checkout attempts cannot prove failed settlement.
function achbotCheckoutNotifyHostedInvoice(mysqli $connection,array $payParam,string $botUrl,string $hash_id): void {
    $orderId=(int)$payParam['id'];$userId=(int)$payParam['user_id'];
        // The merchant can recover this exact provider invoice on every
        // subsequent click; also preserve a direct button in Telegram.
        if ($userId > 0) {
            $keyboard = json_encode(['inline_keyboard'=>[
                [['text'=>\achbotMessage('gateway.gateway_initiation.mark_checkout_uncertain.button.f81a8cfcdd'),
                  'url'=>$botUrl.'pay/checkout.php?nowpayment&hash_id='.urlencode($hash_id)]],
                [['text'=>\achbotMessage('gateway.gateway_initiation.mark_checkout_uncertain.button.f0137e1007'),'callback_data'=>'ps_'.$hash_id]]
            ]], JSON_UNESCAPED_UNICODE);
            try {
                $notice=sendMessage(\achbotMessage('gateway.gateway_initiation.mark_checkout_uncertain.message.cd27d14216').achbotPayUxReference($payParam)
                    .\achbotMessage('gateway.gateway_initiation.mark_checkout_uncertain.message.0c4a9612f7')
                    .\achbotMessage('gateway.gateway_initiation.mark_checkout_uncertain.message.0a2e0bc7bc'),
                    $keyboard, 'HTML', $userId);
                achbotPayUxTrackMessage($connection,(int)$orderId,(int)$userId,$notice);
            } catch (Throwable $ignored) {
                // Durable invoice persists even if a Telegram send fails.
            }
        }
}

function showForm($msg, $presentation = null){
    require_once __DIR__ . '/../src/Finance/PaymentPage.php';
    achbotRenderPaymentPage($msg, '', false, $presentation);
}
