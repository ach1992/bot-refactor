<?php
/** AChBot current payment route and shared receipt presentation. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../messages/loader.php'; // ACHBOT_TEXT_CATALOG


require_once __DIR__.'/../Admin/Access.php';
require_once __DIR__.'/ManualReceiptIntent.php';
require_once __DIR__.'/ReceiptMethods.php';
require_once __DIR__.'/../Panel/ProviderRuntime.php';
require_once __DIR__.'/../Purchase/OrderWrites.php';
require_once __DIR__.'/../Services/Ownership.php';


if (!function_exists('payTypeToDescription')) {
    function payTypeToDescription($payType)
    {
        require_once dirname(__DIR__, 2) . '/src/Presentation/Labels.php';
        return \AchBot\Presentation\Labels\paymentType($payType);
    }
}

function dbFetchPayByHash(mysqli $connection, string $hashId): ?array {
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ? LIMIT 1");
    $stmt->bind_param("s", $hashId);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    if (!$res || $res->num_rows == 0) return null;
    return $res->fetch_assoc();
}

function prepareStartFallback() {
    global $text, $data, $callbackId;
    if (is_string($callbackId??null) && $callbackId!=='') answerCallbackQuery($callbackId);
    $text = '';
    $data = 'mainMenu';
}

function inlineKeyboard(array $rows): string {
    require_once dirname(__DIR__, 2) . '/src/Telegram/Markup.php';
    return \AchBot\Telegram\Markup\inlineKeyboard($rows);
}

function generateHigherRandomizedAmount(int $amountToman): array {
    $amountToman = max(1, $amountToman);

    do {
        $lastThree = rand(100, 999);
    } while ($lastThree % 10 == 0 || $lastThree % 100 == 0);

    $randomizedToman = $amountToman + $lastThree;
    $randomizedRial = $randomizedToman * 10;

    return [
        'toman' => $randomizedToman,
        'rial'  => $randomizedRial,
    ];
}

function achbotManualLabel(string $method): string {
    return achbotCreditMethodLabel($method);
}

/** Validated explicit bot-setting rate; no private or network fallback. */
function achbotUsdtRateValue($raw): ?float {
    if ((!is_string($raw) && !is_int($raw) && !is_float($raw)) ||
        !is_numeric($raw)) return null;
    $rate=(float)$raw;
    return is_finite($rate) && $rate>=100 && $rate<=1000000000 ? $rate : null;
}

/** One explicit USDT rate for every account; retired overrides are ignored. */
function achbotUsdtRate(?array $keys=null): ?float {
    $keys=$keys ?? (is_array($GLOBALS['paymentKeys']??null) ? $GLOBALS['paymentKeys'] : []);
    return achbotUsdtRateValue($keys['manualUsdtRateToman']??null);
}

/**
 * All available manual-crypto destinations are independent. Read only the
 * current per-network configuration, with no retired setting fallback.
 *
 * A rate and destination are required before an option appears. Neither a
 * screenshot nor an operator click is automatic blockchain settlement proof.
 */
function achbotManualUsdtConfiguration(string $network,?array $keys=null): ?array {
    global $paymentKeys;
    $keys ??= is_array($paymentKeys ?? null) ? $paymentKeys : [];
    if (!in_array($network, ['BEP20','TRC20','ERC20'], true)) return null;
    $field = 'manualUsdtWallet' . $network;
    $rawWallet=$keys[$field]??null;
    if (!is_string($rawWallet)) return null;
    $wallet = trim($rawWallet);
    if (!achbotManualUsdtWalletValid($wallet, $network)) return null;
    $rate=achbotUsdtRate($keys);
    if ($rate===null) return null;
    return ['network'=>$network, 'wallet'=>$wallet, 'rate'=>$rate];
}

/** Independent, explicit TRX/Toman rate; never infer it from legacy checker. */
function achbotManualTrxConfiguration(?array $keys=null): ?array {
    global $paymentKeys;
    $keys ??= is_array($paymentKeys ?? null) ? $paymentKeys : [];
    $rawWallet=$keys['manualTrxWallet']??null;
    if (!is_string($rawWallet)) return null;
    $wallet = trim($rawWallet);
    $rawRate = $keys['manualTrxRateToman'] ?? null;
    if (!achbotManualTronAddressValid($wallet) ||
        !is_scalar($rawRate) ||
        !preg_match('/^[1-9][0-9]{0,8}$/D', (string)$rawRate)) return null;
    $rate = (float)$rawRate;
    if (!is_finite($rate) || $rate <= 0) return null;
    return ['network'=>'TRON', 'wallet'=>$wallet,
            'rate'=>$rate];
}

/** One configured destination list, shared by the menu and actual quote.
 * Never return these destination details to the method-menu snapshot. */
function achbotManualConfigurations(string $method,array $keys): array {
    if ($method==='bank_transfer') {
        $value=achbotManualBankIdentity($keys);
        return $value===null?[]:[''=>$value];
    }
    if ($method==='gift_card') {
        $value=achbotManualSellerUrl($keys);
        return $value===null?[]:[''=>['seller_url'=>$value]];
    }
    if ($method==='manual_trx') {
        $value=achbotManualTrxConfiguration($keys);
        return $value===null?[]:['TRON'=>$value];
    }
    $available=[];
    if ($method==='manual_usdt') {
        foreach (['BEP20','TRC20','ERC20'] as $network)
            if (($value=achbotManualUsdtConfiguration($network,$keys))!==null)
                $available[$network]=$value;
    }
    return $available;
}

function achbotManualCryptoTag(string $network): ?string {
    return match($network) {'BEP20'=>'b','TRC20'=>'c','ERC20'=>'e','TRON'=>'t',default=>null};
}
/** Input is the safe current method/network snapshot, not request globals. */
function achbotManualCryptoOptions(array $methods): array {
    $available=[];
    foreach (['manual_usdt','manual_trx'] as $method) {
        foreach ($methods[$method]??[] as $network) {
            $tag=achbotManualCryptoTag($network);
            if ($tag===null) continue;
            $available[$tag]=['provider'=>$method,'network'=>$network,
                'label'=>$method==='manual_trx'?\achbotMessage('manual_payment.receipt_helpers.manual_crypto_options.t_r_x__t_r_o_n.e3a2653517'):
                    \achbotMessage('manual_payment.receipt_helpers.manual_crypto_options.u_s_d_t.1974a6095a').$network];
        }
    }
    return $available;
}

/** No implicit recipient or gift seller: invalid settings disable initiation. */
function achbotManualBankIdentity(?array $keys=null): ?array {
    global $paymentKeys;
    $keys ??= is_array($paymentKeys ?? null) ? $paymentKeys : [];
    $rawAccount=$keys['bankAccount']??null;
    $rawHolder=$keys['holderName']??null;
    if ((!is_string($rawAccount) && !is_int($rawAccount)) || !is_string($rawHolder)) return null;
    $account = trim((string)$rawAccount);
    $holder = trim($rawHolder);
    if ($account === '' || $holder === '' || strlen($account) > 150 || strlen($holder) > 150) return null;
    return ['account' => $account, 'holder' => $holder];
}

/** One validation rule for the editor, administrator label and checkout. */
function achbotManualGiftSellerUrlValid(string $rawUrl): bool {
    $url = trim($rawUrl);
    if ($url === '' || strlen($url) > 350 ||
        filter_var($url, FILTER_VALIDATE_URL) === false) return false;
    $parts = parse_url($url);
    return is_array($parts) &&
        strtolower((string)($parts['scheme'] ?? '')) === 'https' &&
        !empty($parts['host']) &&
        !isset($parts['user']) && !isset($parts['pass']);
}
function achbotManualSellerUrl(?array $keys=null): ?string {
    global $paymentKeys;
    $keys ??= is_array($paymentKeys ?? null) ? $paymentKeys : [];
    $rawUrl=$keys['giftCardUrl']??null;
    if (!is_string($rawUrl)) return null;
    $url = trim($rawUrl);
    return achbotManualGiftSellerUrlValid($url) ? $url : null;
}

/** Method inquiry for customer/status only; never use null as bank auto-eligibility. */
function achbotManualSelectedMethod(mysqli $connection, array $payInfo): ?string {
    try {
        $stmt = $connection->prepare('SELECT provider FROM payment_attempts WHERE pay_row_id=? LIMIT 1');
        $id = (int)$payInfo['id'];
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $method = (string)($row['provider'] ?? '');
        return in_array($method, ['bank_transfer', 'gift_card', 'manual_usdt','manual_trx'], true) ? $method : null;
    } catch (Throwable $error) {
        return null;
    }
}


/** Read the provider from the immutable order-claim, not from a stale button. */
function achbotManualCryptoReceiptProviderByHash(mysqli $db, string $hashId): string {
    if (!preg_match('/^[A-Za-z0-9_-]{1,50}$/D', $hashId)) return '';
    try {
        $stmt=$db->prepare('SELECT provider FROM payment_attempts WHERE order_hash=? LIMIT 1');
        $stmt->bind_param('s',$hashId);$stmt->execute();
        $attempt=$stmt->get_result()->fetch_assoc();$stmt->close();
        $method=(string)($attempt['provider']??'');
        return in_array($method,['manual_usdt','manual_trx'],true) ? $method : '';
    } catch (Throwable $ignored) {
        return '';
    }
}

function achbotManualClaim(mysqli $connection, array $payInfo, string $method, int $userId,
                           ?string $chosenNetwork = null,?string $expectedMode=null): bool {
    if (!in_array($method, ['bank_transfer', 'gift_card', 'manual_usdt','manual_trx'], true) ||
        (int)$payInfo['user_id'] !== $userId) return false;
    try {
        require_once __DIR__.'/CheckoutAdmission.php';
        achbotCheckoutRequireSchema($connection);
        require_once __DIR__.'/ManualReceipt.php';
        $result=achbotManualReceiptIssue($connection,(int)$payInfo['id'],$userId,$method,
            null,$chosenNetwork,(array)($GLOBALS['botState']??[]),$expectedMode);
        return $result['status']==='issued';
    } catch (Throwable $e) {
        achbotDiagnosticEvent('achbot.finance.checkout_manual_admission_deferred',[
            'pay_id'=>(int)$payInfo['id'],'actor_id'=>$userId,'method'=>$method
        ]+achbotDiagnosticLocalFailure($e),'warning');
        return false;
    }
}

function achbotManualReceiptAllowed(mysqli $connection, array $payInfo, string $method, int $userId,?string $expectedMode=null): bool {
    if ((int)$payInfo['user_id'] !== $userId ||
        !in_array((string)$payInfo['state'],['pending','service_superseded'],true) ||
        (string)($payInfo['payid'] ?? '') !== '' ||
        !achbotCreditMethodHas($method,'manual_quote')) return false;
    try {
        require_once __DIR__ . '/PaymentLifecycle.php';
        require_once __DIR__.'/CheckoutAdmission.php';
        if ($expectedMode!==null && !achbotCheckoutModeMatchesRequest($payInfo,$expectedMode)) return false;
        $api=function_exists('achbotApiPaymentContext') ? achbotApiPaymentContext($connection,(string)$payInfo['hash_id'],$userId) : null;
        if (function_exists('achbotApiPaymentPinned') && achbotApiPaymentPinned($connection,(int)$payInfo['id']) && !$api) return false;

        $stmt = $connection->prepare('SELECT provider,stage,checkout_url,provider_status,wallet_invoice_sha256,
            TIMESTAMPDIFF(SECOND,created_at,NOW()) AS age_seconds
            FROM payment_attempts WHERE pay_row_id=? LIMIT 1');
        $id = (int)$payInfo['id'];
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $attempt = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $validWindow=$attempt && achbotCurrentReceiptPin($attempt) && (
            $api!==null || $attempt['stage']==='receiving' ||
            (string)($attempt['provider_status']??'')==='receipt_started' ||
            ((string)($attempt['provider_status']??'')==='' &&
             (int)$attempt['age_seconds']<achbotPayUxManualDeadlineSeconds())
        );
        return $validWindow &&
            ($payInfo['state']==='pending' || $attempt['stage']==='receiving' ||
                (string)($attempt['provider_status']??'')==='receipt_started') &&
            (string)$attempt['provider'] === $method &&
            in_array((string)$attempt['stage'], ['pending','receiving'], true) &&
            achbotManualIntentDecode((string)$attempt['checkout_url'], $method) !== null;
    } catch (Throwable $error) {
        return false;
    }
}

/**
 * Save Telegram's reusable file identifier before delivery. A 2-minute
 * delivery lease prevents simultaneous retries; stale leases can be reclaimed
 * using the SAME evidence, not replaced by a different image.
 */
function achbotManualBeginEvidence(mysqli $connection, array $payInfo, string $method,
                                   int $userId, string $fileId,?string $expectedMode=null): ?string {
    if (!achbotManualReceiptAllowed($connection,$payInfo,$method,$userId,$expectedMode)) return null;
    $typed=achbotManualTypedGift($connection,$payInfo,$method);
    if ($userId <= 0 || $fileId === '' || strlen($fileId) > ($typed?4096:1200) ||
        !achbotCreditMethodHas($method,'manual_quote')) return null;
    try {
        $claimToken = bin2hex(random_bytes(16));
        if (!$connection->begin_transaction()) throw new RuntimeException('manual_evidence_transaction_failed');
        $id = (int)$payInfo['id'];
        $stmt = $connection->prepare('SELECT * FROM pays WHERE id=? FOR UPDATE');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $pay = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$pay || (int)$pay['user_id'] !== $userId ||
            ($expectedMode!==null && !achbotCheckoutModeMatchesRequest($pay,$expectedMode)) || !in_array($pay['state'],['pending','service_superseded'],true) ||
            (string)($pay['payid'] ?? '') !== '') { $connection->rollback(); return null; }
        $stmt = $connection->prepare('SELECT *,
            TIMESTAMPDIFF(SECOND,created_at,NOW()) AS age_seconds
            FROM payment_attempts WHERE pay_row_id=? LIMIT 1 FOR UPDATE');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $attempt = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$attempt || !achbotCurrentReceiptPin($attempt) || (string)$attempt['provider'] !== $method ||
            !in_array((string)$attempt['stage'], ['pending','receiving'], true) ||
            ($pay['state']==='service_superseded' && $attempt['stage']!=='receiving' &&
                (string)($attempt['provider_status']??'')!=='receipt_started')) {
            $connection->rollback(); return null;
        }
        require_once __DIR__ . '/PaymentLifecycle.php';
        $api=function_exists('achbotApiPaymentContext') ? achbotApiPaymentContext($connection,(string)$payInfo['hash_id'],$userId) : null;
        if (function_exists('achbotApiPaymentPinned') && achbotApiPaymentPinned($connection,(int)$payInfo['id']) && !$api) {$connection->rollback();return null;}

        if (!$api && $attempt['stage']==='pending' &&
            (string)($attempt['provider_status']??'')!== 'receipt_started' &&
            (int)$attempt['age_seconds']>=achbotPayUxManualDeadlineSeconds()) {
            $connection->rollback(); return null;
        }
        $intent = achbotManualIntentDecode((string)$attempt['checkout_url'], $method);
        if ($intent === null) { $connection->rollback(); return null; }
        if($typed) {
            // Code and receiving claim are one transaction. Neither a plan
            // change nor a failed claim can strand a saved code in pending.
            $saved=achbotPaymentEvidenceSaveGiftCodeLocked($connection,$pay,$attempt,$userId,$fileId);
            if(!in_array($saved['status']??'',['saved','same'],true) ||
                achbotPaymentEvidenceReadGiftCode($connection,$id)!==$fileId) {
                $connection->rollback();return null;
            }
        } elseif ($attempt['stage'] === 'receiving') {
            if (($intent['receipt_file_id'] ?? null) !== $fileId) {
                $connection->rollback(); return null;
            }
        } else {
            if (isset($intent['receipt_file_id'])) { $connection->rollback(); return null; }
            $intent['receipt_file_id'] = $fileId;
        }
        $encoded = achbotManualIntentEncode($intent);
        if ($encoded === null) { $connection->rollback(); return null; }
        $attemptId = (int)$attempt['id'];
        $stmt = $connection->prepare("UPDATE payment_attempts
            SET stage='receiving', checkout_url=?, review_notice_claim_token=?,
                review_notice_claimed_at=NOW()
            WHERE id=? AND stage IN ('pending','receiving') AND
                (review_notice_claim_token IS NULL OR review_notice_claimed_at IS NULL OR
                 review_notice_claimed_at < DATE_SUB(NOW(), INTERVAL 2 MINUTE))");
        $stmt->bind_param('ssi', $encoded, $claimToken, $attemptId);
        $stmt->execute();
        $changed = $stmt->affected_rows;
        $stmt->close();
        if ($changed !== 1) { $connection->rollback(); return null; }
        if (!$connection->commit()) throw new RuntimeException('manual_evidence_reserve_failed');
        return $claimToken;
    } catch (Throwable $error) {
        try { $connection->rollback(); } catch (Throwable $ignored) {}
        return null;
    }
}

function achbotManualReleaseEvidenceLease(mysqli $connection, string $hashId, string $token): void {
    try {
        $stmt = $connection->prepare("UPDATE payment_attempts a
            JOIN pays p ON p.id=a.pay_row_id
            SET a.review_notice_claim_token=NULL, a.review_notice_claimed_at=NULL
            WHERE p.hash_id=? AND p.state IN ('pending','service_superseded')
              AND a.stage='receiving' AND a.review_notice_claim_token=?");
        $stmt->bind_param('ss', $hashId, $token);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $ignored) {
        // Lease expiry permits a later retry even if connection was lost.
    }
}

/** Transactional expected-state transition after Telegram returned a message ID. */
function achbotManualFinishEvidence(mysqli $connection, string $hashId, string $fileId,
                                    string $token, int $messageId, int $adminChatId): bool {
    if ($messageId <= 0 || $adminChatId <= 0 || $token === '') return false;
    try {
        if (!$connection->begin_transaction()) throw new RuntimeException('manual_evidence_transaction_failed');
        $stmt = $connection->prepare('SELECT * FROM pays WHERE hash_id=? FOR UPDATE');
        $stmt->bind_param('s', $hashId);
        $stmt->execute();
        $pay = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$pay || !in_array($pay['state'],['pending','service_superseded'],true) || (string)($pay['payid'] ?? '') !== '') {
            $connection->rollback(); return false;
        }
        $id = (int)$pay['id'];
        $stmt = $connection->prepare('SELECT id,provider,stage,checkout_url,review_notice_claim_token,wallet_invoice_sha256
            FROM payment_attempts WHERE pay_row_id=? LIMIT 1 FOR UPDATE');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $attempt = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$attempt || !achbotCurrentReceiptPin($attempt) || !achbotCreditMethodHas((string)$attempt['provider'],'manual_quote') ||
            $attempt['stage'] !== 'receiving' ||
            (string)$attempt['review_notice_claim_token'] !== $token) {
            $connection->rollback(); return false;
        }
        $intent = achbotManualIntentDecode((string)$attempt['checkout_url'], (string)$attempt['provider']);
        $typed=achbotManualTypedGift($connection,$pay,(string)$attempt['provider']);
        if (!$intent || ($typed ? (!achbotPaymentEvidenceGiftCodeFormatValid($fileId) ||
                achbotPaymentEvidenceReadGiftCode($connection,$id)!==$fileId) : ($intent['receipt_file_id'] ?? null) !== $fileId)) {
            $connection->rollback(); return false;
        }
        $stmt = $connection->prepare("UPDATE pays SET state=IF(state='pending','have_sent',state),message_id=?,chat_id=?
            WHERE id=? AND state IN ('pending','service_superseded') AND (payid IS NULL OR payid='')");
        $stmt->bind_param('iii', $messageId, $adminChatId, $id);
        $stmt->execute();
        $changed = $stmt->affected_rows;
        $stmt->close();
        if ($changed !== 1) { $connection->rollback(); return false; }
        $attemptId = (int)$attempt['id'];
        $stmt = $connection->prepare("UPDATE payment_attempts
            SET stage='review',review_notice_claim_token=NULL,review_notice_claimed_at=NULL
            WHERE id=? AND stage='receiving' AND review_notice_claim_token=?");
        $stmt->bind_param('is', $attemptId, $token);
        $stmt->execute();
        $changed = $stmt->affected_rows;
        $stmt->close();
        if($typed){$q=$connection->prepare('UPDATE payment_attempts SET evidence_review_sent_at=NOW() WHERE id=?');$q->bind_param('i',$attemptId);if(!$q->execute())throw new RuntimeException('manual_evidence_review_stamp_failed');$q->close();}
        if ($changed !== 1 || !$connection->commit()) throw new RuntimeException('manual_evidence_commit_failed');
        return true;
    } catch (Throwable $error) {
        try { $connection->rollback(); } catch (Throwable $ignored) {}
        return false;
    }
}


function achbotManualOriginalServiceQuote(mysqli $db,array $pay,int $actor,string $mode): ?array {
    require_once __DIR__.'/CheckoutAdmission.php';
    $begun=false;
    try {
        if (!$db->begin_transaction()) throw new RuntimeException('manual_original_quote_begin');
        $begun=true;
        [$current,$attempt]=achbotCreditInvoiceLocked($db,(int)$pay['id'],$actor);
        if (!achbotCheckoutModeMatchesRequest($current,$mode) ||
            !is_string($attempt['wallet_invoice_sha256']??null) ||
            !hash_equals($attempt['wallet_invoice_sha256'],achbotCreditInvoiceFingerprint($current,$attempt)) ||
            (int)$attempt['service_operation_id']!==(int)$current['checkout_operation_id'])
            throw new RuntimeException('manual_original_quote_identity');
        [, $quote]=achbotCheckoutOriginalCommandLocked($db,$current);
        $context=json_decode((string)$current['checkout_draft_context'],true,32,JSON_THROW_ON_ERROR);
        $q=$quote['request'];
        if ($mode==='standard') {
            $labels=$context['labels']??null;
            if (!is_array($labels) || !is_string($labels['category']??null) ||
                !is_string($labels['plan']??null) || !is_string($labels['server']??null))
                throw new RuntimeException('manual_original_quote_labels');
            $q['labels']=$labels;
        }
        $db->rollback();$begun=false;
        return $q;
    } catch (Throwable $e) {
        if ($begun) try {$db->rollback();}catch(Throwable $ignored){}
        error_log('achbot_manual_original_quote_deferred pay_id='.(int)$pay['id'].' actor='.$actor.' class='.get_class($e));
        return null;
    }
}

function achbotManualCaption(mysqli $connection, array $payInfo, string $caption): string {
    // Compose the trusted template before substituting customer values.
    // PRICE is the existing implementation token, never customer input.
    $method = achbotManualSelectedMethod($connection, $payInfo);
    $quoteLine = '';
    if (in_array($method, ['manual_usdt','manual_trx','bank_transfer'], true)) {
        try {
            $stmt = $connection->prepare('SELECT provider,quote_amount,quote_currency,
                checkout_url FROM payment_attempts WHERE pay_row_id=? LIMIT 1');
            $id = (int)$payInfo['id'];
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $details = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $intent = $details ? achbotManualIntentDecode((string)$details['checkout_url'], $method) : null;
            if ($intent && in_array($method, ['manual_usdt','manual_trx'], true)) {
                $network = (string)($intent['network'] ?? '');
                $address = (string)($intent['wallet'] ?? '');
                $asset = $method === 'manual_trx' ? 'TRX' : 'USDT';
                $valid = $method === 'manual_trx'
                    ? $network === 'TRON' && achbotManualTronAddressValid($address)
                    : achbotManualUsdtWalletValid($address, $network);
                if ($details['quote_currency'] === $asset && $valid) {
                    $quoteLine = \achbotMessage('manual_payment.receipt_helpers.manual_caption.header.cc6f426145') .
                        htmlspecialchars((string)$details['quote_amount'], ENT_QUOTES, 'UTF-8') .
                        ' ' . $asset . \achbotMessage('manual_payment.receipt_helpers.manual_caption.text.1c777cb98d') .
                        htmlspecialchars($network, ENT_QUOTES, 'UTF-8') . "</b>\n";
                }
            } elseif ($intent && $method === 'bank_transfer' &&
                      $details['quote_currency'] === 'toman') {
                $quoteLine = \achbotMessage('manual_payment.receipt_helpers.manual_caption.header.12c0babe12') .
                    number_format((int)$details['quote_amount']) .
                    \achbotMessage('manual_payment.receipt_helpers.manual_caption.text.de6a1a6756');
            }
        } catch (Throwable $error) {
            // Missing quote evidence must not fabricate an amount or destination.
        }
    }
    if ($quoteLine === '') return $caption;
    $priceAt = strpos($caption, 'PRICE');
    if ($priceAt !== false) {
        $lineEnd = strpos($caption, "\n", $priceAt);
        if ($lineEnd === false) return $caption . "\n" . $quoteLine;
        return substr($caption, 0, $lineEnd + 1) . $quoteLine .
            substr($caption, $lineEnd + 1);
    }
    // Preserve direct older callers that supply an already-rendered caption.
    return $quoteLine . $caption;
}

/** The private staff review uses the exact signed v2 /verify observation.
 * `order_number` belongs to the WooCommerce purchase; `created_at` is the
 * gift-card creation time (NOT `order_created_at` or the bot invoice time).
 */
function achbotManualGiftReviewText(string $caption,string $code,?array $screen=null): string {
    $missing=achbotMessage('manual_payment.api_payment.gift_not_provided');
    $unit=($screen['amount_unit']??null)==='rial'?achbotMessage('manual_payment.api_payment.currency_rial'):achbotMessage('manual_payment.api_payment.currency_toman');
    $amount=is_int($screen['redeem_amount']??null) && $screen['redeem_amount']>0
        ? number_format($screen['redeem_amount']).' '.$unit
        : achbotMessage('manual_payment.api_payment.gift_not_verified');
    // No API details: show the code-integrity hint to staff, not on verified cards.
    $unrecognized=($screen['redeem_amount']??null)===null &&
        ($screen['order_number']??null)===null &&
        ($screen['created_at']??null)===null;
    $codeKey=$unrecognized
        ? 'manual_payment.api_payment.code_evidence_unrecognized'
        : 'manual_payment.api_payment.code_evidence';
    return $caption."\n\n".
        achbotMessageHtml($codeKey,['code'=>$code])."\n".
        achbotMessageHtml('manual_payment.api_payment.gift_details',[
            'amount'=>$amount,
            'order'=>$screen['order_number']??$missing,
            'created'=>$screen['created_at']??$missing]);
}
function achbotManualGiftReviewFits(string $caption,string $code,?array $screen=null): bool {
    // Verify the ACTUAL full Telegram message before saving evidence; the
    // escaping and supplementary-codepoint calculation match staff delivery.
    $plain=html_entity_decode(strip_tags(achbotManualGiftReviewText($caption,$code,
        $screen)),ENT_QUOTES|ENT_HTML5,'UTF-8');
    $characters=preg_match_all('/./us',$plain);
    $supplementary=preg_match_all('/[\x{10000}-\x{10ffff}]/u',$plain);
    return $characters!==false && $supplementary!==false && $characters+$supplementary<=4096;
}
/** A read-only verification BEFORE the original manual receipt lease and
 * Telegram fanout. Unknown/unavailable evidence keeps genuine human review;
 * a definite invalid/used/wrong-value card never reaches staff.
 */
function achbotManualGiftSubmissionPreflight(mysqli $db,array $pay,string $method,
                                            string $code): array {
    $accepted=['accepted'=>true,'redeem_amount'=>null,'amount_unit'=>null,
        'order_number'=>null,'created_at'=>null];
    if (!achbotManualTypedGift($db,$pay,$method))return $accepted;
    // No API call, SQL evidence or staff disclosure for plainly invalid text.
    if (!achbotPaymentEvidenceGiftCodeFormatValid($code))
        return array_replace($accepted,['accepted'=>false,'reason'=>'invalid']);
    try {
        $c=achbotApiPaymentContext($db,(string)$pay['hash_id'],(int)$pay['user_id']);
        if (!$c || ($c['attempt']['provider']??'')!=='gift_card' ||
            ($c['pay']['state']??'')!=='pending')return $accepted;
        $verified=achbotPaymentEvidenceGiftVerify($c['profile'],$code);
        $status=(string)($verified['status']??'unavailable');
        if (in_array($status,['not_found','invalid','used','revoked','expired'],true))
            return array_replace($accepted,['accepted'=>false,'reason'=>$status]);
        if (!empty($verified['ok']) && $status==='active') {
            $data=$verified['data'];
            $e=achbotPaymentEvidenceGiftNormalize($data,$c['profile'],$c['attempt']);
            if ($e!==null) {
                // The v2 contract permits null order details when the
                // WooCommerce order is inaccessible. Do not substitute the
                // numeric order_id or any local invoice timestamp.
                $order=$data['order_number']??null;
                $order=is_string($order)?trim($order):null;
                if ($order==='' || (is_string($order) &&
                    (strlen($order)>512 || preg_match('/[\x00-\x1f\x7f]/',$order))))
                    $order=null;
                $created=$data['created_at']??null;
                if (!is_string($created) || strlen($created)>40) $created=null;
                if ($created!==null) {
                    try {
                        $parsed=DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM,$created);
                        $created=$parsed!==false && DateTimeImmutable::getLastErrors()===false
                            ? $parsed->format('Y-m-d H:i:s P') : null;
                    } catch (Throwable $e) {
                        // A broken optional date must not hide the verified
                        // amount/order number or block genuine staff review.
                        $created=null;
                    }
                }
                return array_replace($accepted,[
                    'redeem_amount'=>achbotPaymentEvidenceInteger($data['redeem_amount']),
                    'amount_unit'=>(string)$c['profile']['amount_unit'],
                    'order_number'=>$order,
                    'created_at'=>$created]);
            }
            if (achbotPaymentEvidenceGiftNormalizationStatus($data,
                    $c['profile'],$c['attempt'])==='mismatch')
                return array_replace($accepted,['accepted'=>false,'reason'=>'mismatch']);
        }
    } catch (Throwable $e) {
        // The API/DB observation was inconclusive: keep normal human review.
    }
    return $accepted;
}
function achbotManualGiftRetryMessage(string $reason): string {
    $key=match($reason) {
        'not_found'=>'payment.api_payment.code_not_found_retry',
        'invalid'=>'payment.api_payment.code_invalid_retry',
        'used'=>'payment.api_payment.code_used_retry',
        'revoked'=>'payment.api_payment.code_revoked_retry',
        'expired'=>'payment.api_payment.code_expired_retry',
        'mismatch'=>'payment.api_payment.code_mismatch_retry',
        default=>'payment.api_payment.code_invalid_retry'
    };
    return achbotMessage($key);
}
function achbotManualTypedGift(mysqli $db,array $pay,string $method): bool {
    return $method==='gift_card' && function_exists('achbotApiPaymentContext') &&
        achbotApiPaymentContext($db,(string)($pay['hash_id']??''))!==null;
}
function achbotManualEvidenceInput($update,mysqli $db,array $pay,string $method): ?string {
    if(!achbotManualTypedGift($db,$pay,$method))return extractPhotoFileId($update);
    $code=$update->message->text??null;
    if(!is_string($code))return null;$code=trim($code);
    return achbotPaymentEvidenceGiftCodeFormatValid($code)?$code:null;
}
function achbotManualEvidencePrompt(mysqli $db,array $pay,string $method,bool $invalid=false): string {
    if(achbotManualTypedGift($db,$pay,$method))
        return achbotMessage($invalid?'payment.api_payment.code_invalid_retry':'payment.api_payment.code_prompt');
    return $invalid?\achbotMessage('legacy_messages.please_send_only_image'):achbotManualReceiptPrompt($method);
}

function achbotManualReceiptPrompt(string $method): string {
    $key = match ($method) {
        'gift_card' => 'please_send_gift_card_image',
        'manual_usdt' => 'please_send_usdt_image',
        'manual_trx' => 'please_send_trx_image',
        default => 'please_send_receipt_image'
    };
    return \achbotMessage('legacy_messages.'.$key);
}

function achbotManualAcknowledgement(array $payInfo, string $method): string {
    require_once __DIR__.'/PaymentLifecycle.php';
    $db=$GLOBALS['connection']??null;
    if ($db instanceof mysqli && function_exists('achbotApiPaymentContext')) {
        $api=achbotApiPaymentContext($db,(string)$payInfo['hash_id']);
        if ($api && achbotApiPaymentTerminal($api['pay'])) return achbotMessage('payment.api_payment.confirmed');
    }
    if($db instanceof mysqli && achbotManualTypedGift($db,$payInfo,$method))return achbotMessage('payment.api_payment.code_acknowledgement')."\n".achbotPayUxReference($payInfo);
    return \achbotMessage('manual_payment.receipt_helpers.manual_acknowledgement.label.a5f27211f7') . achbotManualLabel($method) . \achbotMessage('manual_payment.receipt_helpers.manual_acknowledgement.text.29e14cf62e') .
        achbotPayUxReference($payInfo) .
        \achbotMessage('manual_payment.receipt_helpers.manual_acknowledgement.text.62e569e922');
}

/** Read only the original pinned manual quote and destination, never changed settings. */
function buildCartToCartInfoMessage(array $paymentKeys, int $priceToman,
                                    int $userId = 0, string $method = 'bank_transfer',
                                    string $hashId = ''): string {
    global $connection;
    if (!achbotCreditMethodHas($method,'manual_quote') ||
        $hashId === '' || !($connection instanceof mysqli)) return '';
    try {
        require_once __DIR__.'/PaymentLifecycle.php';
        $pay=achbotPayUxOwnedReference($connection,$hashId,$userId);
        if (!$pay) return '';
        $stmt = $connection->prepare("SELECT provider,quote_amount,quote_currency,
            local_amount_toman,settlement_currency,checkout_url
            FROM payment_attempts WHERE order_hash=? AND provider=? LIMIT 1");
        $stmt->bind_param('ss', $hashId, $method);
        $stmt->execute();
        $attempt = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$attempt || (int)$attempt['local_amount_toman'] !== $priceToman) return '';
        $intent = achbotManualIntentDecode((string)$attempt['checkout_url'], $method);
        if (!$intent) return '';
        if (in_array($method, ['manual_usdt','manual_trx'], true)) {
            $network = (string)($intent['network'] ?? '');
            $wallet = (string)($intent['wallet'] ?? '');
            $asset = $method === 'manual_trx' ? 'TRX' : 'USDT';
            $addressValid = $method === 'manual_trx'
                ? $network === 'TRON' && achbotManualTronAddressValid($wallet)
                : achbotManualUsdtWalletValid($wallet, $network);
            if ((string)$attempt['quote_currency'] !== $asset ||
                (string)$attempt['settlement_currency'] !== $network ||
                !$addressValid ||
                !preg_match('/^[0-9]{1,15}\.[0-9]{2,8}$/D', (string)$attempt['quote_amount']) ||
                (float)$attempt['quote_amount'] <= 0) return '';
            $template = \achbotMessage('legacy_messages.'.($method === 'manual_trx'
                ? 'brw_cart_to_cart_trx' : 'brw_cart_to_cart_usdt'));
            return strtr($template, [
                'PRICE-TOMAN-ROUND' => number_format($priceToman),
                'PRICE-USDT' => number_format((float)$attempt['quote_amount'], 2, '.', ''),
                'USDT-NETWORK' => htmlspecialchars($network, ENT_QUOTES, 'UTF-8'),
                'USDT-WALLET' => htmlspecialchars($wallet, ENT_QUOTES, 'UTF-8'),
                'PRICE-TRX' => number_format((float)$attempt['quote_amount'], 6, '.', ''),
                'TRX-WALLET' => htmlspecialchars($wallet, ENT_QUOTES, 'UTF-8'),
            ]);
        }
        if ((string)$attempt['quote_currency'] !== 'toman') return '';
        $amount = (int)$attempt['quote_amount'];
        if ($amount <= 0) return '';
        if ($method === 'bank_transfer' &&
            (trim((string)($intent['account'] ?? '')) === '' ||
             trim((string)($intent['holder'] ?? '')) === '')) return '';
        if(achbotManualTypedGift($connection,$pay,$method))return achbotMessageHtml('payment.api_payment.gift_invoice',['amount'=>number_format($amount)]);
        $template = \achbotMessage('legacy_messages.'.($method === 'gift_card' ?
            'brw_cart_to_cart_gift' : 'brw_cart_to_cart_detail'));
        return strtr($template, [
            'PRICE-TOMAN-ROUND' => number_format($priceToman),
            'PRICE-TOMAN' => number_format($amount),
            'PRICE-RIAL' => number_format($amount * 10),
            'ACCOUNT-NUMBER' => htmlspecialchars((string)($intent['account'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            'HOLDER-NAME' => htmlspecialchars((string)($intent['holder'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        ]);
    } catch (Throwable $error) {
        return '';
    }
}


function kbSendReceiptAndCancel(string $sendPrefix, string $cancelPrefix, string $hashId, string $method = 'bank_transfer'): string {
    global $connection;
    require_once dirname(__DIR__, 2) . '/src/Telegram/Markup.php';
    $api=$connection instanceof mysqli && function_exists('achbotApiPaymentContext') ? achbotApiPaymentContext($connection,$hashId) : null;
    if ($api) return achbotApiPaymentKeyboard($api,$sendPrefix,$cancelPrefix);
    $url = null;
    if ($method === 'gift_card' && $connection instanceof mysqli) {
        try {
            $stmt = $connection->prepare('SELECT checkout_url FROM payment_attempts
                WHERE order_hash=? AND provider=? LIMIT 1');
            $stmt->bind_param('ss', $hashId, $method);
            $stmt->execute();
            $attempt = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $intent = $attempt ? achbotManualIntentDecode((string)$attempt['checkout_url'], $method) : null;
            $url = $intent['seller_url'] ?? null;
            // Historical pinned destinations remain immutable; suppress only
            // the unsafe outward link when it violates current URL rules.
            if (!is_string($url) || !achbotManualGiftSellerUrlValid($url)) $url = null;
        } catch (Throwable $ignored) { $url = null; }
    }
    return \AchBot\Telegram\Markup\receiptAndCancel($sendPrefix, $cancelPrefix, $hashId, $method, $url);
}

function kbOnlyCancel(string $cancelPrefix, string $hashId): string {
    require_once dirname(__DIR__, 2) . '/src/Telegram/Markup.php';
    return \AchBot\Telegram\Markup\onlyCancel($cancelPrefix, $hashId);
}

function handleCancelFlow($removeKeyboard) {
    delMessage();
    setUser();
    sendMessage(\achbotMessage('legacy_messages.operation_canceled'), $removeKeyboard, "HTML");
    sendMessage(\achbotCurrentMessage('reached_main_menu'), getMainKeys(), 'HTML');
    exit;
}

function extractPhotoFileId($update): ?string {
    require_once dirname(__DIR__, 2) . '/src/Telegram/Response.php';
    return \AchBot\Telegram\Response\photoFileId($update);
}

function duplicateReceiptGuard(mysqli $connection, array $payInfo, int $nowTs): ?string {
    require_once __DIR__.'/PaymentLifecycle.php';
    $id=(int)$payInfo['id'];$userId=(int)$payInfo['user_id'];
    // Original receipt recovery is not a new payment.
    $own=achbotProviderRow($connection,'SELECT stage,wallet_invoice_sha256 FROM payment_attempts WHERE pay_row_id=?','i',[$id]);
    if ($own && achbotCurrentReceiptPin($own) && $own['stage']==='receiving') return null;
    if (in_array($payInfo['state'],['have_sent','paid'],true))
        return \achbotMessage('manual_payment.receipt_helpers.duplicate_receipt_guard.label.2ea72e23b7');

    $type=(string)$payInfo['type'];
    $special=in_array($type,['BUY_SUB','INCREASE_WALLET'],true);
    $approved=60*($special
        ? achbotPayUxPolicyNumber('receiptApprovedMinutes')
        : achbotPayUxPolicyNumber('receiptApprovedOtherMinutes'));
    $review=60*achbotPayUxPolicyNumber('receiptReviewMinutes');
    $declined=60*achbotPayUxPolicyNumber('receiptDeclinedMinutes');
    $minTs=$nowTs-max($approved,$review,$declined);
    $methods=achbotCreditMethodSqlList('manual_quote');
    // Preserve configured cooldowns using current pinned receipt facts,
    // not historical states or automatic/Wallet-only settlements.
    $where='p.user_id=? AND p.id<>? AND p.type=? AND IFNULL(p.price,0)=? AND p.request_date>=?';
    $types='iisii';$values=[$userId,$id,$type,(int)$payInfo['price'],$minTs];
    if ($type!=='INCREASE_WALLET') {
        $where.=' AND IFNULL(p.plan_id,0)=? AND IFNULL(p.server_id,0)=?';
        $types.='ii';$values[]=(int)($payInfo['plan_id']??0);$values[]=(int)($payInfo['server_id']??0);
        if ($type!=='BUY_SUB') {
            $where.=' AND IFNULL(p.order_id,0)=?';
            $types.='i';$values[]=(int)($payInfo['order_id']??0);
        }
    }
    $row=achbotProviderRow($connection,"SELECT
        MAX(CASE WHEN p.state='have_sent' AND a.stage='review' THEN p.request_date END) AS review_at,
        MAX(CASE WHEN p.state='paid' AND a.wallet_credit_entry_id IS NOT NULL AND a.wallet_credit_basis='manual' THEN p.request_date END) AS approved_at,
        MAX(CASE WHEN p.state='declined' THEN p.request_date END) AS declined_at
        FROM pays p JOIN payment_attempts a ON a.pay_row_id=p.id
        WHERE $where AND a.provider IN ($methods)
          AND a.wallet_invoice_sha256 IS NOT NULL AND a.wallet_invoice_sha256<>''",$types,$values);
    if ($row['review_at']!==null && $nowTs-(int)$row['review_at']<$review)
        return \achbotMessage('manual_payment.receipt_helpers.duplicate_receipt_guard.label.2f5e71511d',
            ['mins'=>(int)ceil(($review-$nowTs+(int)$row['review_at'])/60)]);
    if ($row['approved_at']!==null && $nowTs-(int)$row['approved_at']<$approved)
        return \achbotMessage('manual_payment.receipt_helpers.duplicate_receipt_guard.label.c571825a4a',
            ['mins'=>(int)ceil(($approved-$nowTs+(int)$row['approved_at'])/60)]);
    if ($row['declined_at']!==null && $nowTs-(int)$row['declined_at']<$declined)
        return \achbotMessage('manual_payment.receipt_helpers.duplicate_receipt_guard.label.a2fecf9749',
            ['remain'=>$declined-$nowTs+(int)$row['declined_at']]);
    return null;
}

function countUserPaysByState(mysqli $connection, int $userId, string $state): int {
    $stmt = $connection->prepare("SELECT COUNT(*) AS c FROM `pays` WHERE `user_id`=? AND `state`=?");
    $stmt->bind_param("is", $userId, $state);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['c'] ?? 0);
}

function sumUserPaysByState(mysqli $connection, int $userId, string $state): int {
    $stmt = $connection->prepare(
        "SELECT COALESCE(SUM(`price`), 0) AS total
         FROM `pays`
         WHERE `user_id`=? AND `state`=?"
    );
    $stmt->bind_param("is", $userId, $state);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int)($row['total'] ?? 0);
}

function buildAdminKeyboardWithStats(
    mysqli $connection,
    string $approveCb,
    string $declineCb,
    string $hashId,
    int $userId
): string {

    $approvedCount = countUserPaysByState($connection, $userId, 'paid');
    $declinedCount = countUserPaysByState($connection, $userId, 'declined');
    $approvedSum   = sumUserPaysByState($connection, $userId, 'paid');

    return inlineKeyboard([
        [
            ['text' => \achbotMessage('legacy_buttons.decline'), 'callback_data' => $declineCb],
            ['text' => \achbotMessage('legacy_buttons.approve'), 'callback_data' => $approveCb],
        ],
        [
            ['text' => $approvedCount . \achbotMessage('manual_payment.receipt_helpers.build_admin_keyboard_with_stats.text.839cad204e'), 'callback_data' => "noop_okc_{$hashId}"],
            ['text' => \achbotMessage('manual_payment.receipt_helpers.build_admin_keyboard_with_stats.button.30b0414601'), 'callback_data' => "noop_ok_{$hashId}"],
        ],
        [
            ['text' => $declinedCount . \achbotMessage('manual_payment.receipt_helpers.build_admin_keyboard_with_stats.text.839cad204e'), 'callback_data' => "noop_badc_{$hashId}"],
            ['text' => \achbotMessage('manual_payment.receipt_helpers.build_admin_keyboard_with_stats.button.c2c33e1499'), 'callback_data' => "noop_bad_{$hashId}"],
        ],
        [
            ['text' => number_format($approvedSum) . \achbotMessage('manual_payment.automatic_receipts.text.2cc98f7f7c'), 'callback_data' => "noop_sumv_{$hashId}"],
			['text' => \achbotMessage('manual_payment.receipt_helpers.build_admin_keyboard_with_stats.button.e892623fb9'), 'callback_data' => "noop_sum_{$hashId}"],
        ],
        [
            ['text' => \achbotMessage('manual_payment.receipt_helpers.build_admin_keyboard_with_stats.hash_id.286c7d3faf', ['hashId' => $hashId]), 'callback_data' => "noop_{$hashId}"]
        ],
    ]);
}


/**
 * Delivery failure never turns a pending invoice into an approved payment.
 * Only the original leased photo may be retried; the source file ID is
 * already durable in the attempt before any outbound Telegram request.
 */
function sendReceiptToAdminsAndUpdatePay(
    mysqli $connection,
    string $fileid,
    string $caption,
    string $adminKeyboard,
    int $adminChatId,
    string $hashId,
    string $claimToken,
    ?array $giftScreen=null
): bool {
    try {
        $pay=dbFetchPayByHash($connection,$hashId);
        $typed=$pay && achbotManualTypedGift($connection,$pay,'gift_card') && achbotManualSelectedMethod($connection,$pay)==='gift_card';
        if($typed){$code=achbotPaymentEvidenceReadGiftCode($connection,(int)$pay['id']);if($code!==$fileid || !achbotPaymentEvidenceGiftCodeFormatValid($code))throw new RuntimeException('typed_receipt_evidence_mismatch');if(!achbotManualGiftReviewFits($caption,$code,$giftScreen))throw new RuntimeException('typed_receipt_message_too_long');$caption=achbotManualGiftReviewText($caption,$code,$giftScreen);}
        $response = $typed?sendMessage($caption,$adminKeyboard,'HTML',$adminChatId):sendPhoto($fileid, $caption, $adminKeyboard, 'HTML', $adminChatId);
        $msgId = ($response->ok??false)===true ? (int)($response->result->message_id ?? 0) : 0;
        if ($msgId <= 0 ||
            !achbotManualFinishEvidence($connection, $hashId, $fileid, $claimToken,
                                        $msgId, $adminChatId)) {
            achbotManualReleaseEvidenceLease($connection, $hashId, $claimToken);
            if ($msgId>0 && function_exists('achbotApiPaymentContext')) {
                $api=achbotApiPaymentContext($connection,$hashId);
                if ($api && achbotApiPaymentTerminal($api['pay'])) {
                    achbotApiPaymentNotice($connection,array_replace($api['pay'],['chat_id'=>$adminChatId,'message_id'=>$msgId]),'confirmed',false);
                    return true;
                }
            }
            return false;
        }
    } catch (Throwable $error) {
        achbotManualReleaseEvidenceLease($connection, $hashId, $claimToken);
        return false;
    }
    try {
        sendToAllAdmins($typed?'sendMessage':'sendPhoto', [
            ...($typed?['text'=>$caption]:['photo'=>$fileid,'caption'=>$caption]),
            'reply_markup' => $adminKeyboard,
            'parse_mode' => 'HTML'
        ], $hashId, ['event'=>'manual_review']);
    } catch (Throwable $ignored) {
        // The primary operator message and protected DB transition succeeded.
    }
    if (function_exists('achbotApiPaymentContext')) {
        $api=achbotApiPaymentContext($connection,$hashId);
        if ($api) {
            if(achbotApiPaymentTerminal($api['pay']))achbotApiPaymentNotice($connection,$api['pay'],'confirmed',false);
        }
    }
    return true;
}

/** Keep the familiar customer acknowledgement before optional verification. */
function achbotManualAcknowledgeAndVerify(mysqli $db,array $pay,string $method,string $buttonText,string $botUsername): void {
    $current=function_exists('achbotApiPaymentContext')?achbotApiPaymentContext($db,(string)$pay['hash_id']):null;
    if($current && !in_array($current['pay']['state'],['pending','have_sent'],true)){
        if ($current['pay']['state']==='service_superseded' &&
            achbotPaymentEvidenceReviewReady($current['pay'],$current['attempt']))
            achbotApiPaymentCheck($db,(int)$pay['id']);
        else achbotApiPaymentSyncInvoice($db,$current);
        return;
    }
    try {
        sendWithStartButton(achbotManualAcknowledgement($pay,$method),$buttonText,$botUsername,'mainMenu','HTML',(string)$pay['hash_id']);
    } finally {
        $api=function_exists('achbotApiPaymentContext')?achbotApiPaymentContext($db,(string)$pay['hash_id']):null;
        if($api && !achbotApiPaymentTerminal($api['pay']))achbotApiPaymentCheck($db,(int)$pay['id']);
    }
}

function sendWithStartButton($text, $buttonText, $botUsername, $startPayload = 'mainMenu', $parseMode = 'HTML', string $paymentHash = '')
{
    $buttons = [];
    if ($paymentHash !== '' && preg_match('/^[A-Za-z0-9_-]{1,50}$/D', $paymentHash)) {
        $buttons[] = [['text'=>\achbotMessage('manual_payment.receipt_helpers.send_with_start_button.button.dcb9c20928'), 'callback_data'=>'ps_'.$paymentHash]];
    }
    $buttons[] = [[
        'text' => $buttonText,
        'url'  => "https://t.me/{$botUsername}?start={$startPayload}"
    ]];
    $keyboard = json_encode(['inline_keyboard'=>$buttons], JSON_UNESCAPED_UNICODE);

    // A start deep link already sends a NEW menu. Enrich evidence before send,
    // using persisted ownership rather than a callback-supplied numeric ID.
    if ($paymentHash!=='' && ($GLOBALS['connection']??null) instanceof mysqli) {
        require_once __DIR__.'/PaymentLifecycle.php';
        $pay=achbotPayUxOwnedReference($GLOBALS['connection'],$paymentHash,(int)($GLOBALS['from_id']??0));
        if ($pay && !str_contains($text,achbotPayUxReference($pay)))
            $text=achbotPayUxReference($pay).$text;
    }
    $sent=sendMessage($text, $keyboard, $parseMode);
    if ($paymentHash!=='' && isset($GLOBALS['connection']) &&
        $GLOBALS['connection'] instanceof mysqli &&
        isset($GLOBALS['from_id']) && (int)$GLOBALS['from_id']>0) {
        require_once __DIR__ . '/PaymentLifecycle.php';
        achbotPayUxTrackByHash($GLOBALS['connection'],$paymentHash,
            (int)$GLOBALS['from_id'],$sent);
    }
    return $sent;
}


/** Never infer physical panel identity from URLs or credentials. */
function achbotAutoRenewSameAccount(mysqli $db, int $serverA, int $serverB): bool {
    return $serverA>0 && $serverB>0 &&
        ($serverA===$serverB || achbotSameProviderPanel($db,$serverA,$serverB));
}

/** Current locking reads identify references; their mere presence never denies service. */
function achbotSharedAccountReferencesLocked(mysqli $db,int $orderId,int $serverId,string $remark): array {
    if ($orderId<=0 || $serverId<=0 || $remark==='') throw new RuntimeException('shared_account_identity_invalid');
    $q=$db->prepare('SELECT * FROM orders_list WHERE BINARY remark=BINARY ? ORDER BY id FOR UPDATE');
    $q->bind_param('s',$remark);$q->execute();$rows=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
    $selected=null;
    foreach ($rows as $row) if ((int)$row['id']===$orderId && (int)$row['server_id']===$serverId) $selected=$row;
    if ($selected===null) throw new RuntimeException('shared_account_reference_missing');
    $type=achbotProviderType($db,$serverId);$physical=null;
    if ($type==='pasarguard') {
        achbotPgLoad();$physical=achbotProviderAccountByOrder($db,$orderId,true);
        if (!$physical) throw new RuntimeException('shared_account_identity_unverified');
    }
    $peers=[];
    foreach ($rows as $row) {
        if (!achbotAutoRenewSameAccount($db,$serverId,(int)$row['server_id'])) continue;
        if ($type==='pasarguard') {
            $candidate=achbotProviderAccountByOrder($db,(int)$row['id'],true);
            if (!$candidate) throw new RuntimeException('shared_account_identity_unverified');
            if ((int)$candidate['id']!==(int)$physical['id']) continue;
        } else {
            // Present invalid origin is never silently treated as a legacy paid reference.
            achbotOrderImportOrigin($db,$row,true);
        }
        $peers[]=$row;
    }
    if (!$peers) throw new RuntimeException('shared_account_reference_missing');
    return $peers;
}

/** Pending claims serialize a shared account; completed references are not an ownership restriction. */
function achbotSharedAccountWorkSettledLocked(mysqli $db,array $peers): bool {
    if (!$peers) return false;
    foreach ($peers as $row)
        if ((int)($row['id']??0)<=0 || (int)($row['status']??0)!==1) return false;
    if (!achbotServiceSubjectWorkSettledLocked($db,$peers)) return false;
    // The service operation owns monetary work. Other actual in-flight
    // Native effects (for example nonpaid creation) still own their claim.
    $result=$db->query("SELECT TABLE_TYPE,ENGINE FROM information_schema.TABLES
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='service_effects'");
    if (!($result instanceof mysqli_result)) return false;
    $table=$result->fetch_assoc();
    if ($table===null || $result->num_rows!==1 || $table['TABLE_TYPE']!=='BASE TABLE' ||
        strcasecmp((string)$table['ENGINE'],'InnoDB')!==0) return false;
    $remark=(string)$peers[0]['remark'];
    $q=$db->prepare("SELECT target_server_id FROM service_effects
        WHERE BINARY remote_username=BINARY ? AND state NOT IN ('local_recorded','cancelled') FOR UPDATE");
    $q->bind_param('s',$remark);$q->execute();$effects=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
    foreach ($effects as $effect)
        if (achbotAutoRenewSameAccount($db,(int)$peers[0]['server_id'],(int)$effect['target_server_id'])) return false;
    return true;
}

/** Original order deletion gate: optionally atomically claim the Native DELETE. */
function achbotAutoRenewPrepareDeletion(mysqli $db, int $orderId,?array $expected=null,?callable $nativeClaim=null): bool {
    if ($orderId<=0) return false;
    try {
        $db->begin_transaction();
        $stmt=$db->prepare("SELECT id,server_id,remark,status,notif,expire_date FROM orders_list
            WHERE id=? FOR UPDATE");
        $stmt->bind_param('i',$orderId);
        $stmt->execute();
        $order=$stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$order || (int)$order['status']!==1 ||
            ($expected!==null && ((int)$order['expire_date']!==(int)$expected['expire_date'] ||
                (int)$order['notif']!==(int)$expected['notif'] ||
                (int)$order['server_id']!==(int)$expected['server_id'] ||
                $order['remark']!==$expected['remark']))) {
            $db->rollback(); return false;
        }
        $peerOrders=achbotSharedAccountReferencesLocked($db,$orderId,
            (int)$order['server_id'],(string)$order['remark']);
        if (!achbotSharedAccountWorkSettledLocked($db,$peerOrders)) {
            $db->rollback();return false;
        }
        // For PasarGuard, atomically persist the original Native claim with
        // this local fence. A crash before remote preflight/DELETE is resumable
        // without guessing whether an unmarked external write happened.
        if (!achbotServiceSubjectWorkSettledLocked($db,array_values(array_filter($peerOrders,
            static fn($r)=>(int)$r['id']===$orderId)),true)) {
            $db->rollback();return false;
        }
        if ($nativeClaim!==null) $nativeClaim($db,$order);
        // Durable deletion fence before remote DELETE. Any uncertain remote
        // result cannot cause an unattended repeated delete or new renewal.
        $stmt=$db->prepare("UPDATE orders_list SET status=0,autoRenew='off'
            WHERE id=? AND status=1");
        $stmt->bind_param('i',$orderId);
        $stmt->execute();
        $changed=$stmt->affected_rows;
        $stmt->close();
        if ($changed!==1 || $db->commit()!==true) throw new RuntimeException('delete_guard_commit_unknown');
        return true;
    } catch (Throwable $error) {
        try {$db->rollback();} catch (Throwable $ignored) {}
        $reason=(string)$error->getMessage();
        if (preg_match('/^pg_[a-z0-9_]{2,85}$/D',$reason)!==1)
            $reason='delete_guard_unverified';
        error_log('achbot_auto_renew_deletion_guard_deferred order_id='.$orderId.
            ' native_claim='.($nativeClaim===null?'no':'yes').' code='.$reason);
        return false;
    }
}

/** Resolve the actual restoration target, not a guess from current plan
 * or current server. Historical orders keep their established disposition. */
function achbotOrderCapacityServerLocked(mysqli $db,array $order,bool $finalizing=false): ?int {
    if ((int)($order['id']??0)<=0 || (int)($order['server_id']??0)<=0) return null;
    $server=(int)$order['server_id'];
    // Explicit nonfinancial origin survives plan assignment even without007.
    $origin=achbotOrderImportOrigin($db,$order,true);
    $positivePlan=(int)($order['fileid']??0)>0;
    try {
        $r=$db->query("SELECT TABLE_TYPE,ENGINE FROM information_schema.TABLES
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='service_effects'");
        if (!($r instanceof mysqli_result)) throw new RuntimeException('deletion_capacity_schema_unavailable');
        $table=$r->fetch_assoc();
        if ($table===null || $r->num_rows!==1 || $table['TABLE_TYPE']!=='BASE TABLE' ||
            strcasecmp((string)$table['ENGINE'],'InnoDB')!==0) throw new RuntimeException('deletion_capacity_schema_invalid');
        $id=(int)$order['id'];
        $q=$db->prepare("SELECT * FROM service_effects WHERE order_id=? LIMIT 2 FOR UPDATE");
        if (!$q) throw new RuntimeException('deletion_capacity_read_unavailable');
        $q->bind_param('i',$id);
        if (!$q->execute()) {$q->close();throw new RuntimeException('deletion_capacity_read_unavailable');}
        $rows=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
        if (!$rows) {
            // Historical Marzban->PasarGuard adoption keeps its original
            // physical service effect with order_id=NULL and may later gain
            // a positive plan. It NEVER reserved a bot capacity slot. The
            // ordinary order_id lookup above cannot see this immutable link.
            $linkedId=(int)($order['provider_effect_id']??0);
            if ($linkedId>0) {
                $q=$db->prepare('SELECT * FROM service_effects WHERE id=? FOR UPDATE');
                $q->bind_param('i',$linkedId);$q->execute();
                $linked=$q->get_result()->fetch_assoc();$q->close();
                if (!$linked) throw new RuntimeException('deletion_capacity_link_missing');
                // A later imported reference to an original bot-created
                // account did not reserve a slot of its own.
                if ($linked['source_kind']==='service_operation' && $origin!==null &&
                    ($origin['provider_account_id']??null)===(int)$linked['provider_reference_id'] &&
                    ($origin['native_identity']['remote_user_id']??null)===(int)$linked['provider_remote_user_id'] &&
                    ($origin['native_identity']['remote_created_at']??null)===$linked['provider_remote_created_at'] &&
                    ($origin['native_identity']['remote_username']??null)===$linked['remote_username'])
                    return null;
                if (in_array($linked['source_kind'],['legacy_migration','external_import'],true) &&
                    $linked['effect_kind']==='adopt' && $linked['state']==='local_recorded' &&
                    $linked['order_id']===null && $linked['pay_row_id']===null &&
                    (int)$linked['reserved_count']===0) return null;
                throw new RuntimeException('deletion_capacity_link_unverified');
            }
            return $origin===null && $positivePlan?$server:null;
        }
        if (count($rows)!==1) throw new RuntimeException('deletion_capacity_identity_ambiguous');
        $effect=$rows[0];
        if ($effect['state']!=='local_recorded') throw new RuntimeException('deletion_capacity_effect_unconfirmed');
        if ($effect['source_kind']==='service_operation') {
            if ($origin!==null) throw new RuntimeException('deletion_capacity_original_buy_origin_conflict');
            return achbotServiceBuyCapacityServerLocked($db,$order,$effect,$finalizing);
        }
        if ($effect['effect_kind']==='adopt' && $effect['source_kind']==='external_import' &&
            $effect['pay_row_id']===null && (int)$effect['reserved_count']===0) return null;
        // Native accounts created for trials and administrative bulk work
        // consumed one real slot despite their nonfinancial source kind.
        // Preserve the historical CREATE rule while distinguishing the paid,
        // auditable replacement journal from all imported/adopted accounts.
        if ($origin===null && $positivePlan && $effect['effect_kind']==='create' &&
            $effect['source_kind']!=='external_import') return $server;
        if ($origin===null && $positivePlan && $effect['effect_kind']==='replacement' &&
            $effect['source_kind']===null && (int)$effect['pay_row_id']>0)
            return $server;
        throw new RuntimeException('deletion_capacity_effect_invalid');
    } catch (Throwable $error) {
        throw new RuntimeException('deletion_capacity_provenance_unavailable',0,$error);
    }
}

/** Legacy unpinned DELETE classification needs only the yes/no answer. */
function achbotOrderRestoresCapacity(mysqli $db,array $order): bool {
    return achbotOrderCapacityServerLocked($db,$order)!==null;
}

/**
 * Complete an already-authorized remote deletion atomically in local SQL.
 * A failure leaves the order status=0 for operator reconciliation, never
 * eligible for a Cron-driven second remote deletion.
 */
function achbotAutoRenewFinalizeDeletion(
    mysqli $db, int $orderId, int $serverId, bool $restoreCapacity,string $reportMode,int $reportActor=0
): bool {
    if ($orderId<=0 || $serverId<=0) return false;
    try {
        $db->begin_transaction();
        $stmt=$db->prepare("SELECT * FROM orders_list WHERE id=? FOR UPDATE");
        $stmt->bind_param('i',$orderId);
        $stmt->execute();
        $row=$stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row || (int)$row['status']!==0 ||
            (int)$row['server_id']!==$serverId) {
            $db->rollback(); return false;
        }
        $restoreServer=$restoreCapacity?achbotOrderCapacityServerLocked($db,$row,true):null;
        $stmt=$db->prepare("DELETE FROM orders_list WHERE id=? AND status=0");
        $stmt->bind_param('i',$orderId);
        $stmt->execute();
        $removed=$stmt->affected_rows;
        $stmt->close();
        if ($removed!==1) { $db->rollback(); return false; }
        if ($restoreServer!==null) {
            $stmt=$db->prepare("UPDATE server_info SET ucount=ucount+1
                WHERE id=? AND ucount<2147483647");
            $stmt->bind_param('i',$restoreServer);
            $stmt->execute();
            $saved=$stmt->affected_rows;
            $stmt->close();
            if ($saved!==1) { $db->rollback(); return false; }
        }
        require_once __DIR__.'/OperationalReports.php';
        achbotDeletionReportStageLocked($db,$row,$reportMode,$reportActor);
        if ($db->commit()!==true) throw new RuntimeException('delete_commit_unknown');
        return true;
    } catch (Throwable $error) {
        try {$db->rollback();} catch (Throwable $ignored) {}
        $reason=$error->getMessage();
        if (preg_match('/^[a-z][a-z0-9_]{2,90}$/D',$reason)!==1) $reason='local_deletion_failed';
        error_log('achbot_auto_renew_local_deletion_deferred order_id='.$orderId.' code='.$reason);
        return false;
    }
}
