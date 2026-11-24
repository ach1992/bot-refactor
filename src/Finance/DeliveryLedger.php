<?php
/**
 * Issue #117: immutable, payment-bound customer delivery intent and
 * one-way Telegram acknowledgement journal. No merchant/native calls here.
 * 018 is an explicitly gated forward migration; no runtime DDL or backfill.
 */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }
require_once __DIR__.'/PaymentEvidence.php';
require_once __DIR__.'/../Admin/Access.php';

/** Fail closed before ANY new external service effect if 018 is unavailable. */
function achbotDeliverySchemaReady(mysqli $db): bool {
    try {
        // The pre-CREATE gate must prove crypto readiness, not just the
        // presence of tables. Never CREATE a paid remote account when the
        // original packet cannot be sealed before terminal COMMIT.
        if (!function_exists('openssl_encrypt') || !function_exists('openssl_decrypt') ||
            strlen(achbotPaymentEvidenceKey())!==32) return false;
        $probe=$db->query('SELECT id,pay_row_id,ordinal,attempt_id,effect_id,order_id,user_id,server_id,'.
            'pay_type,pay_fingerprint,source_digest,native_operation,native_target_key,'.
            'sealed_payload,payload_sha256,state,attempt_count,dispatch_token,dispatched_at,'.
            'acknowledged_at,last_actor,last_result FROM payment_delivery_intents LIMIT 0');
        if (!$probe) return false;
        $probe->free();
        $engine=$db->query("SHOW TABLE STATUS WHERE Name='payment_delivery_intents'");
        if (!$engine || strtolower((string)($engine->fetch_assoc()['Engine']??''))!=='innodb')
            return false;
        $keys=[];
        $indexes=$db->query('SHOW INDEX FROM payment_delivery_intents');
        while ($indexes && ($r=$indexes->fetch_assoc())) {
            if ((int)$r['Non_unique']===0)
                $keys[(string)$r['Key_name']][(int)$r['Seq_in_index']]=(string)$r['Column_name'];
        }
        foreach (['uq_delivery_pay_ordinal'=>['pay_row_id','ordinal'],
                  'uq_delivery_effect'=>['effect_id']] as $key=>$fields) {
            if (!isset($keys[$key])) return false;
            ksort($keys[$key]);
            if (array_values($keys[$key])!==$fields) return false;
        }
        $probe=$db->query('SELECT id,intent_id,attempt_no,dispatch_token,actor_userid,'.
            'result,started_at,finished_at FROM payment_delivery_attempts LIMIT 0');
        if (!$probe) return false;
        $probe->free();
        $engine=$db->query("SHOW TABLE STATUS WHERE Name='payment_delivery_attempts'");
        if (!$engine || strtolower((string)($engine->fetch_assoc()['Engine']??''))!=='innodb')
            return false;
        return true;
    } catch (Throwable $error) { return false; }
}
function achbotDeliveryPurpose(int $payId,int $ordinal,int $effectId,int $orderId): string {
    return "customer-delivery:v1:$payId:$ordinal:$effectId:$orderId";
}
/**
 * Called with pays locked, inside the SAME financial/order COMMIT. The sealed
 * packet is immutable; only state/attempt metadata changes afterward.
 */
function achbotDeliveryInsertLocked(mysqli $db,array $pay,array $attempt,int $ordinal,
                                    int $effectId,int $orderId,int $serverId,
                                    string $sourceDigest,string $nativeOperation,
                                    string $nativeTargetKey,array $packet): void {
    $id=(int)($pay['id']??0);$user=(int)($pay['user_id']??0);
    if ($id<=0 || $user<=0 || $ordinal<=0 || $serverId<0 ||
        ($ordinal>1 && $effectId<=0) ||
        ($effectId>0 && ($orderId<=0 || $serverId<=0 || $nativeOperation==='' || $nativeTargetKey==='')) ||
        (int)($attempt['pay_row_id']??0)!==$id || (int)($attempt['id']??0)<=0 ||
        !in_array($packet['kind']??null,['qr','text'],true) ||
        (int)($packet['uid']??0)!==$user)
        throw new RuntimeException('delivery_intent_identity_invalid');
    if ($packet['kind']==='qr') {
        if ($effectId<=0 || !is_string($packet['qr']??null) ||
            !is_string($packet['caption']??null) || trim($packet['caption'])==='' ||
            !is_string($packet['keys']??null) || !is_string($packet['bot_url']??null))
            throw new RuntimeException('delivery_intent_qr_missing');
    } elseif ($effectId!==0 || !is_string($packet['text']??null) ||
              trim($packet['text'])==='' || !in_array($packet['mode']??null,['HTML','plain'],true))
        throw new RuntimeException('delivery_intent_text_missing');
    $fingerprint=achbotPaymentEvidencePayFingerprint($pay);
    $body=[
        'v'=>1,'pay_id'=>$id,'ordinal'=>$ordinal,'attempt_id'=>(int)$attempt['id'],
        'provider'=>(string)$attempt['provider'],'invoice'=>(string)$attempt['order_hash'],
        'user_id'=>$user,'pay_type'=>(string)$pay['type'],
        'pay_fingerprint'=>$fingerprint,'source_digest'=>$sourceDigest,
        'effect_id'=>$effectId,'order_id'=>$orderId,'server_id'=>$serverId,
        'native_operation'=>$nativeOperation,'native_target_key'=>$nativeTargetKey,
        'packet'=>$packet,
    ];
    $sealed=achbotPaymentEvidenceProtect(achbotDeliveryPurpose($id,$ordinal,$effectId,$orderId),$body);
    $digest=hash('sha256',$sealed);
    $aid=(int)$attempt['id'];$ptype=(string)$pay['type'];
    $e=$effectId?:null;$o=$orderId?:null;
    $operation=$nativeOperation!==''?$nativeOperation:null;
    $target=$nativeTargetKey!==''?$nativeTargetKey:null;
    $q=$db->prepare("INSERT INTO payment_delivery_intents
        (pay_row_id,ordinal,attempt_id,effect_id,order_id,user_id,server_id,pay_type,
         pay_fingerprint,source_digest,native_operation,native_target_key,sealed_payload,
         payload_sha256,state,attempt_count)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,'pending',0)");
    if (!$q) throw new RuntimeException('delivery_intent_prepare');
    $q->bind_param('iiiiiiisssssss',$id,$ordinal,$aid,$e,$o,$user,$serverId,
        $ptype,$fingerprint,$sourceDigest,$operation,$target,$sealed,$digest);
    if (!$q->execute() || $q->affected_rows!==1)
        throw new RuntimeException('delivery_intent_insert');
    $q->close();
}

/** Call after 007 CREATE is linked to a newly inserted 023 order. */
function achbotDeliveryInsertBuyLocked(mysqli $db,array $pay,array $attempt,array $effect,
                                       int $ordinal,int $orderId,array $packet,
                                       string $invoiceSha): void {
    if ($orderId<=0 || !hash_equals($invoiceSha,achbotBankBuyInvoiceFingerprint($pay)))
        throw new RuntimeException('delivery_buy_invoice_invalid');
    foreach (['operation_id','target_key','endpoint_sha256','request_sha256','remote_username'] as $key)
        if (!is_string($effect[$key]??null) || $effect[$key]==='')
            throw new RuntimeException('delivery_native_pin_missing');
    $packet['kind']='qr';
    achbotDeliveryInsertLocked($db,$pay,$attempt,$ordinal,(int)$effect['id'],$orderId,
        (int)$effect['target_server_id'],
        hash('sha256',$invoiceSha.'|'.(string)$effect['request_sha256'].'|'.
            (string)$effect['endpoint_sha256'].'|'.(string)$effect['remote_username']),
        (string)$effect['operation_id'],(string)$effect['target_key'],$packet);
}

/**
 * Immutable original hosted BUY_SUB delivery from already committed orders.
 * An original 003 paid claim is required; no merchant/financial replay.
 */
function achbotDeliveryHostedBuyDigest(
    array $pay,array $attempt,array $effect,int $ordinal
): ?string {
    $id=(int)($pay['id']??0);$count=max(1,(int)($pay['agent_count']??0));
    $amount=(int)($pay['price']??0);
    if ($id<=0 || $amount<=0 || (int)($pay['agent_count']??0)<0 ||
        ($pay['type']??null)!=='BUY_SUB' || ($pay['state']??null)!=='paid' ||
        !in_array((string)($attempt['provider']??''),['nextpay','zarinpal'],true) ||
        (int)($attempt['pay_row_id']??0)!==$id ||
        (string)($attempt['order_hash']??'')!==(string)($pay['hash_id']??'') ||
        (string)($attempt['provider_reference']??'')==='' ||
        (string)$attempt['provider_reference']!==(string)($pay['payid']??'') ||
        (string)($attempt['quote_currency']??'')!=='toman' ||
        (string)($attempt['quote_amount']??'')!==sprintf('%d.00000000',$amount) ||
        (int)($attempt['local_amount_toman']??0)!==$amount ||
        ($attempt['payment_id']??null)!==null ||
        ($attempt['evidence_profile']??null)!==null ||
        (int)($effect['pay_row_id']??0)!==$id ||
        !in_array((string)($effect['effect_kind']??''),['create','replacement'],true) ||
        (int)($effect['ordinal']??0)!==$ordinal ||
        $ordinal<=0 || $ordinal>$count ||
        ($effect['state']??null)!=='local_recorded' ||
        (int)($effect['order_id']??0)<=0 ||
        (($effect['effect_kind']??'')==='create'
            ?(int)($effect['reserved_count']??-1)!==($ordinal===1?$count:0)
            :($ordinal===1
                ?!in_array((int)($effect['reserved_count']??-1),[0,$count],true)
                :(int)($effect['reserved_count']??-1)!==0)) ||
        (int)($effect['target_server_id']??0)!==(int)($pay['server_id']??0) ||
        (int)($effect['plan_id']??0)!==(int)($pay['plan_id']??0))
        return null;
    foreach (['operation_id','request_sha256','target_key','endpoint_sha256',
        'remote_username'] as $key)
        if (!is_string($effect[$key]??null) || $effect[$key]==='') return null;
    $replacement=(string)$effect['effect_kind']==='replacement';
    if ($replacement && ((int)($effect['actor_userid']??0)<=0 ||
        !in_array((string)($effect['reconciliation_reason']??''),
            ['original_missing','original_missing_proven','original_unavailable',
             'original_mismatch','original_invalid'],true) ||
        preg_match('/^[a-f0-9]{64}$/D',
            (string)($effect['reconciliation_fingerprint']??''))!==1)) return null;
    $digestParts=[
        $replacement?'hosted-replacement:v1':'hosted-linked-buy:v1',
        achbotPaymentEvidencePayFingerprint($pay),
        achbotPaymentEvidenceQuoteFingerprint($attempt),
        $ordinal,$count,(string)$effect['id'],(string)$effect['operation_id'],
        (string)$effect['request_sha256'],(string)$effect['endpoint_sha256'],
        (string)$effect['target_key'],(string)$effect['remote_username'],
        (string)$effect['requested_expire'],(string)$effect['requested_bytes']
    ];
    // Existing 018 packets retain their original v1 digest *exactly*.
    // Only the distinct replacement uses the additional immutable evidence.
    if ($replacement) $digestParts[]=[(string)$effect['replaces_effect_id'],
        (string)$effect['reconciliation_fingerprint'],
        (string)$effect['reconciliation_reason'],
        (string)$effect['actor_userid']];
    return hash('sha256',json_encode($digestParts,JSON_THROW_ON_ERROR));
}
/** Original human receipt, not a merchant attestation. The same 003
 * method/quote and 007 manual capacity hold must survive until delivery.
 * Caller owns the payment transaction when lock=true. No provider HTTP.
 */
function achbotDeliveryManualReceiptLocked(mysqli $db,array $pay,array $attempt,
                                               bool $allowReserved=false,
                                               bool $preclaim=false): bool {
    $payId=(int)($pay['id']??0);
    if ($payId<=0 || ($pay['type']??null)!=='BUY_SUB' ||
        !in_array((string)($pay['state']??''),
            $preclaim?['have_sent']:['processing','approved'],true) ||
        (!$preclaim && (int)($pay['approved_by']??0)<=0) ||
        (int)($pay['user_id']??0)<=0 ||
        (int)($pay['message_id']??0)<=0 || (int)($pay['chat_id']??0)===0 ||
        (string)($pay['payid']??'')!=='' ||
        (int)($attempt['pay_row_id']??0)!==$payId ||
        (string)($attempt['order_hash']??'')!==(string)($pay['hash_id']??'') ||
        (int)($attempt['local_amount_toman']??0)!==(int)($pay['price']??0) ||
        (string)($attempt['stage']??'')!=='review' ||
        !in_array((string)($attempt['provider']??''),
            ['bank_transfer','gift_card','manual_usdt','manual_trx'],true) ||
        !function_exists('achbotManualIntentDecode')) return false;
    $provider=(string)$attempt['provider'];
    $intent=achbotManualIntentDecode((string)($attempt['checkout_url']??''),$provider);
    if (!is_array($intent) ||
        (empty($intent['receipt_file_id']) &&
         (!function_exists('achbotPaymentEvidenceHasTypedReviewLocked') ||
          !achbotPaymentEvidenceHasTypedReviewLocked($db,$pay)))) return false;
    if ($provider==='bank_transfer') {
        if (!function_exists('achbotBankBuyClaimMatches') ||
            !function_exists('achbotBankBuyAttemptMatches')) return false;
        $q=$db->prepare('SELECT * FROM bank_buy_invoice_claims WHERE pay_row_id=? FOR UPDATE');
        $q->bind_param('i',$payId);$q->execute();$claim=$q->get_result()->fetch_assoc();$q->close();
        if (!achbotBankBuyClaimMatches($pay,$claim) ||
            !achbotBankBuyAttemptMatches($pay,$attempt,$claim)) return false;
    }
    // Check signed/original receipt and invoice identity BEFORE capacity
    // reservation. No remote CREATE may reach a missing 003 attempt.
    if ($preclaim) return true;
    $q=$db->prepare("SELECT state,reserved_count FROM service_effects
        WHERE pay_row_id=? AND ordinal=0 AND effect_kind='manual_hold' FOR UPDATE");
    $q->bind_param('i',$payId);$q->execute();$hold=$q->get_result()->fetch_assoc();$q->close();
    return $hold && ($hold['state']==='consumed' || ($allowReserved && $hold['state']==='reserved')) &&
        (int)$hold['reserved_count']===max(1,(int)($pay['agent_count']??0));
}
function achbotDeliveryManualBuyDigest(array $pay,array $attempt,array $effect): string {
    return hash('sha256','manual-buy:v1|'.achbotPaymentEvidencePayFingerprint($pay).'|'.
        achbotPaymentEvidenceQuoteFingerprint($attempt).'|'.
        (string)$effect['request_sha256'].'|'.(string)$effect['endpoint_sha256'].'|'.
        (string)$effect['remote_username']);
}
/** Insert in the SAME COMMIT as the linked order and approved pay row. */
function achbotDeliveryInsertManualBuyLocked(mysqli $db,array $pay,array $attempt,
        array $effect,int $ordinal,int $orderId,array $packet): void {
    if ($orderId<=0 || !achbotDeliveryManualReceiptLocked($db,$pay,$attempt))
        throw new RuntimeException('delivery_manual_receipt_invalid');
    foreach (['operation_id','target_key','endpoint_sha256','request_sha256','remote_username'] as $key)
        if (!is_string($effect[$key]??null) || $effect[$key]==='')
            throw new RuntimeException('delivery_manual_effect_invalid');
    $packet['kind']='qr';
    achbotDeliveryInsertLocked($db,$pay,$attempt,$ordinal,(int)$effect['id'],$orderId,
        (int)$effect['target_server_id'],
        achbotDeliveryManualBuyDigest($pay,$attempt,$effect),
        (string)$effect['operation_id'],(string)$effect['target_key'],$packet);
}

/**
 * Locally debited wallet BUY_SUB proof, with no merchant-attestation claim.
 * The original ordinal-0 hold was committed with wallet debit and capacity;
 * every CREATE is an already-dispatched, exactly pinned operation.
 */
function achbotDeliveryWalletBuyDigest(
    array $pay,array $attempt,array $effect,array $hold,int $ordinal
): ?string {
    $payId=(int)($pay['id']??0);$count=max(1,(int)($pay['agent_count']??0));
    $price=(int)($pay['price']??0);
    if ($payId<=0 || $price<=0 || (int)($pay['agent_count']??0)<0 ||
        ($pay['type']??'')!=='BUY_SUB' ||
        !in_array((string)($pay['state']??''),['processing_wallet','paid_with_wallet'],true) ||
        (int)($pay['user_id']??0)<=0 ||
        (int)($attempt['pay_row_id']??0)!==$payId ||
        ($attempt['provider']??'')!=='wallet' ||
        ($attempt['order_hash']??null)!==($pay['hash_id']??null) ||
        ($attempt['quote_currency']??null)!=='toman' ||
        ($attempt['quote_amount']??null)!==sprintf('%d.00000000',$price) ||
        (int)($attempt['local_amount_toman']??-1)!==$price ||
        ($attempt['stage']??null)!=='completed' ||
        ($attempt['provider_status']??null)!=='wallet_debited' ||
        ($attempt['provider_reference']??null)!==null ||
        ($attempt['payment_id']??null)!==null ||
        (int)($hold['pay_row_id']??0)!==$payId ||
        ($hold['effect_kind']??null)!=='wallet_hold' ||
        (int)($hold['ordinal']??-1)!==0 ||
        ($hold['state']??null)!=='consumed' ||
        (int)($hold['reserved_count']??0)!==$count ||
        $hold['order_id']!==null ||
        (int)($effect['pay_row_id']??0)!==$payId ||
        ($effect['effect_kind']??null)!=='create' ||
        (int)($effect['ordinal']??0)!==$ordinal ||
        $ordinal<=0 || $ordinal>$count ||
        !in_array((string)($effect['state']??''),['verified','local_recorded'],true) ||
        (int)($effect['reserved_count']??-1)!==0 ||
        (int)($effect['target_server_id']??0)!==(int)($pay['server_id']??0) ||
        (int)($effect['plan_id']??0)!==(int)($pay['plan_id']??0) ||
        (int)($hold['target_server_id']??0)!==(int)($effect['target_server_id']??0) ||
        (int)($hold['plan_id']??0)!==(int)($effect['plan_id']??0) ||
        $hold['target_key']!==$effect['target_key'] ||
        $hold['target_group_id']!==$effect['target_group_id'] ||
        $hold['endpoint_sha256']!==$effect['endpoint_sha256'] ||
        (int)($effect['requested_expire']??0)<=0 ||
        (int)($effect['requested_bytes']??0)<=0 ||
        (int)($hold['requested_expire']??-1)!==0 ||
        (int)($hold['requested_bytes']??-1)!==0) return null;
    return hash('sha256',json_encode([
        'wallet-buy:v1',achbotPaymentEvidencePayFingerprint($pay),
        achbotPaymentEvidenceQuoteFingerprint($attempt),$ordinal,$count,
        (string)$hold['id'],(string)$hold['operation_id'],
        (string)$hold['request_sha256'],(string)$hold['target_key'],
        (string)$effect['id'],(string)$effect['operation_id'],
        (string)$effect['request_sha256'],(string)$effect['endpoint_sha256'],
        (string)$effect['remote_username'],(string)$effect['requested_expire'],
        (string)$effect['requested_bytes']
    ],JSON_THROW_ON_ERROR));
}

/** Same transaction as the original wallet's complete local-order finality. */
function achbotDeliveryInsertWalletBuyLocked(
    mysqli $db,array $pay,array $attempt,array $effect,array $hold,
    int $ordinal,int $orderId,array $packet
): void {
    $digest=achbotDeliveryWalletBuyDigest($pay,$attempt,$effect,$hold,$ordinal);
    if ($digest===null || $orderId<=0)
        throw new RuntimeException('delivery_wallet_original_proof_invalid');
    $packet['kind']='qr';
    achbotDeliveryInsertLocked($db,$pay,$attempt,$ordinal,(int)$effect['id'],$orderId,
        (int)$effect['target_server_id'],$digest,(string)$effect['operation_id'],
        (string)$effect['target_key'],$packet);
}

/** For wallet / API service edit, invoked under the SAME terminal transaction. */
function achbotDeliveryInsertTextLocked(mysqli $db,array $pay,array $attempt,string $text,
                                        string $mode,int $orderId,int $serverId,
                                        string $effectDigest): void {
    if ($effectDigest==='' || !achbotDeliverySchemaReady($db))
        throw new RuntimeException('delivery_text_schema_or_proof');
    achbotDeliveryInsertLocked($db,$pay,$attempt,1,0,$orderId,$serverId,
        $effectDigest,'','',['kind'=>'text','text'=>$text,'mode'=>$mode,'uid'=>(int)$pay['user_id']]);
}
/**
 * Verify real financial, customer, account and pinned native identities under
 * row locks before exposing encrypted material to a delivery transport.
 * Never rebuild from mutable plan/config data.
 */
function achbotDeliveryValidateLocked(mysqli $db,array $pay,array $d): ?array {
    $id=(int)$pay['id'];$ordinal=(int)$d['ordinal'];
    if ($id!==(int)$d['pay_row_id'] || (int)$pay['user_id']!==(int)$d['user_id'] ||
        (string)$pay['type']!==(string)$d['pay_type'] ||
        !hash_equals((string)$d['pay_fingerprint'],achbotPaymentEvidencePayFingerprint($pay)) ||
        !in_array((string)$pay['state'],['approved','paid','auto_cart_approved','paid_with_wallet'],true) ||
        !hash_equals((string)$d['payload_sha256'],hash('sha256',(string)$d['sealed_payload'])))
        return null;
    $a=$db->prepare('SELECT * FROM payment_attempts WHERE pay_row_id=? FOR UPDATE');
    $a->bind_param('i',$id);$a->execute();$attempt=$a->get_result()->fetch_assoc();$a->close();
    // A terminal operator Archive cannot initiate any later delivery send;
    // read-only historical validation remains available before Archive.
    if (!$attempt || (int)$attempt['id']!==(int)$d['attempt_id'] ||
        ($attempt['review_archived_at']??null)!==null) return null;
    $effectId=(int)($d['effect_id']??0);$orderId=(int)($d['order_id']??0);
    $body=achbotPaymentEvidenceUnprotect(
        achbotDeliveryPurpose($id,$ordinal,$effectId,$orderId),(string)$d['sealed_payload']);
    if (!$body || ($body['v']??null)!==1) return null;
    foreach (['pay_id'=>$id,'ordinal'=>$ordinal,'attempt_id'=>(int)$attempt['id'],
        'user_id'=>(int)$d['user_id'],'effect_id'=>$effectId,'order_id'=>$orderId,
        'server_id'=>(int)$d['server_id']] as $k=>$v)
        if (($body[$k]??null)!==$v) return null;
    foreach (['provider'=>(string)$attempt['provider'],
        'invoice'=>(string)$attempt['order_hash'],'pay_type'=>(string)$d['pay_type'],
        'pay_fingerprint'=>(string)$d['pay_fingerprint'],
        'source_digest'=>(string)$d['source_digest'],
        'native_operation'=>(string)($d['native_operation']??''),
        'native_target_key'=>(string)($d['native_target_key']??'')] as $k=>$v)
        if (!is_string($body[$k]??null) || !hash_equals($v,$body[$k])) return null;
    if ($effectId>0) {
        // Historical RC26 replacement evidence may have extra 021 columns.
        // SELECT * also works on a fresh schema that has no 021 columns.
        $q=$db->prepare('SELECT * FROM service_effects WHERE id=? FOR UPDATE');
        $q->bind_param('i',$effectId);$q->execute();$effect=$q->get_result()->fetch_assoc();$q->close();
        if (!$effect || (int)$effect['pay_row_id']!==$id ||
            (int)$effect['ordinal']!==$ordinal || (string)$effect['state']!=='local_recorded' ||
            (int)$effect['order_id']!==$orderId || (int)$effect['target_server_id']!==(int)$d['server_id'] ||
            !hash_equals((string)$d['native_operation'],(string)$effect['operation_id']) ||
            !hash_equals((string)$d['native_target_key'],(string)$effect['target_key']))
            return null;
        if (($attempt['provider']??null)==='wallet') {
            $holdQuery=$db->prepare("SELECT * FROM service_effects WHERE pay_row_id=?
                AND effect_kind='wallet_hold' AND ordinal=0 FOR UPDATE");
            $holdQuery->bind_param('i',$id);$holdQuery->execute();
            $hold=$holdQuery->get_result()->fetch_assoc();$holdQuery->close();
            $walletDigest=$hold?achbotDeliveryWalletBuyDigest(
                $pay,$attempt,$effect,$hold,$ordinal):null;
            if ($walletDigest===null ||
                !hash_equals((string)$d['source_digest'],$walletDigest)) return null;
        } elseif (in_array((string)$attempt['provider'],['nextpay','zarinpal'],true)) {
            // Read-only compatibility for already-sealed RC26 hosted packets.
            $digest=achbotDeliveryHostedBuyDigest($pay,$attempt,$effect,$ordinal);
            if (($attempt['stage']??null)!=='done' ||
                ($attempt['provider_status']??null)!=='delivered' ||
                $digest===null ||
                !hash_equals((string)$d['source_digest'],$digest)) return null;
        } elseif (in_array((string)$attempt['provider'],
                ['bank_transfer','gift_card','manual_usdt','manual_trx'],true) &&
            (int)($pay['approved_by']??0)>0) {
            if (!achbotDeliveryManualReceiptLocked($db,$pay,$attempt) ||
                !hash_equals((string)$d['source_digest'],
                    achbotDeliveryManualBuyDigest($pay,$attempt,$effect))) return null;
        } else {
        $claim=$db->prepare('SELECT invoice_sha256 FROM bank_buy_invoice_claims WHERE pay_row_id=? FOR UPDATE');
        $claim->bind_param('i',$id);$claim->execute();$invoice=$claim->get_result()->fetch_assoc();$claim->close();
        $issuedFingerprint=achbotBankBuyInvoiceFingerprint($pay);
        if ($invoice) {
            // Original card-transfer invoice 009 exists, never reconstruct it.
            if (!hash_equals((string)$invoice['invoice_sha256'],$issuedFingerprint))
                return null;
        } else {
            // A gift native BUY has immutable API issuance, but no bank-only
            // 009 row. Require exact settlement proof, never inferred intent.
            $profile=achbotPaymentEvidenceSnapshot($attempt);
            $proof=achbotPaymentEvidenceGetProof($db,$id);
            if (!$profile || ($profile['pay_sha256']??null)!==achbotPaymentEvidencePayFingerprint($pay) ||
                !$proof || !achbotPaymentEvidenceValidateProofLocked($db,$pay,$proof) ||
                (int)$proof['attempt_id']!==(int)$attempt['id'])
                return null;
        }
        if (($attempt['evidence_profile']??null)!==null) {
            $proof=achbotPaymentEvidenceGetProof($db,$id);
            if (!$proof || !achbotPaymentEvidenceValidateProofLocked($db,$pay,$proof))
                return null;
        }
        if (!hash_equals((string)$d['source_digest'],
                hash('sha256',$issuedFingerprint.'|'.
                    (string)$effect['request_sha256'].'|'.(string)$effect['endpoint_sha256'].'|'.
                    (string)$effect['remote_username'])))
            return null;
        }
    } elseif ((string)$d['pay_type']==='INCREASE_WALLET') {
        if ($orderId!==0 || (int)$d['server_id']!==0 ||
            !hash_equals((string)$d['source_digest'],
                hash('sha256','wallet:'.(string)$attempt['id'])))
            return null;
    } else {
        if ($orderId<0 || (int)$d['server_id']<=0 ||
            (string)$attempt['evidence_fulfillment_state']==='reserved' ||
            !is_string($attempt['evidence_fulfillment_payload']??null) ||
            !hash_equals((string)$d['source_digest'],
                hash('sha256',(string)$attempt['evidence_fulfillment_payload'])))
            return null;
    }
    if ($orderId>0) {
        $q=$db->prepare('SELECT id,userid,server_id FROM orders_list WHERE id=? FOR UPDATE');
        $q->bind_param('i',$orderId);$q->execute();$order=$q->get_result()->fetch_assoc();$q->close();
        if (!$order || (int)$order['userid']!==(int)$d['user_id'] ||
            (int)$order['server_id']!==(int)$d['server_id'])
            return null;
    }
    $packet=$body['packet']??null;
    if (!is_array($packet) || (int)($packet['uid']??0)!==(int)$d['user_id']) return null;
    if ($effectId>0) {
        if (($packet['kind']??null)!=='qr' ||
            !is_string($packet['qr']??null) ||
            !is_string($packet['caption']??null) || trim($packet['caption'])==='' ||
            !is_string($packet['keys']??null) || !is_string($packet['bot_url']??null))
            return null;
    } elseif (($packet['kind']??null)!=='text' ||
              !is_string($packet['text']??null) || trim($packet['text'])==='' ||
              !in_array($packet['mode']??null,['HTML','plain'],true)) return null;
    return $packet;
}
/** Reuse the sealed ORIGINAL Telegram packet in both dispatch paths.
 * The per-request context is diagnostic only, never input to the message.
 */
function achbotDeliverySendPacket(array $packet,int $payId,int $ordinal,
                                  int $attemptNo,int $actor,?int $serviceId=null): bool {
    $old=$GLOBALS['achbotTelegramDiagnosticContext']??null;
    $GLOBALS['achbotTelegramDiagnosticContext']=[
        'delivery_ordinal'=>$ordinal,
        'delivery_attempt'=>$attemptNo,'delivery_actor'=>$actor,
    ]+($serviceId===null?['pay_id'=>$payId]:['service_operation_id'=>$serviceId]);
    try {
        if ($packet['kind']==='qr')
            return sendQrFromLink($packet['qr'],$packet['caption'],$packet['keys'],
                $packet['bot_url'],(int)$packet['uid'])===true;
        $mode=$packet['mode']==='plain'?null:'HTML';
        $answer=sendMessage($packet['text'],null,$mode,(int)$packet['uid']);
        return $answer===true ||
            (is_object($answer)&&($answer->ok??false)===true) ||
            (is_array($answer)&&($answer['ok']??false)===true);
    } finally {
        if ($old===null) unset($GLOBALS['achbotTelegramDiagnosticContext']);
        else $GLOBALS['achbotTelegramDiagnosticContext']=$old;
    }
}
/** All automatic deliveries use these same local journal transitions.
 * The source-specific owner has already validated and locked its intent.
 * The caller commits before transport and never holds SQL locks across it. */
function achbotDeliveryClaimLocked(mysqli $db,array $d): array {
    if ($d['state']!=='pending') throw new RuntimeException('delivery_not_pending');
    $id=(int)$d['id'];$count=(int)$d['attempt_count'];
    $token=bin2hex(random_bytes(16));$digest=(string)$d['payload_sha256'];
    $q=$db->prepare("UPDATE payment_delivery_intents SET state='dispatching',attempt_count=attempt_count+1,
        dispatch_token=?,dispatched_at=NOW(),last_actor=0,last_result=NULL
        WHERE id=? AND state='pending' AND attempt_count=? AND payload_sha256=?");
    if (!$q) throw new RuntimeException('delivery_claim_prepare');
    $q->bind_param('siis',$token,$id,$count,$digest);
    $ok=$q->execute() && $q->affected_rows===1;$q->close();
    if (!$ok) throw new RuntimeException('delivery_claim_not_saved');
    $next=$count+1;
    $a=$db->prepare("INSERT INTO payment_delivery_attempts
        (intent_id,attempt_no,dispatch_token,actor_userid) VALUES (?,?,?,0)");
    if (!$a) throw new RuntimeException('delivery_attempt_prepare');
    $a->bind_param('iis',$id,$next,$token);
    $ok=$a->execute() && $a->affected_rows===1;$a->close();
    if (!$ok) throw new RuntimeException('delivery_attempt_not_saved');
    return ['id'=>$id,'token'=>$token,'attempt'=>$next];
}
function achbotDeliveryFinishClaimLocked(mysqli $db,array $claim,bool $ack): string {
    $id=(int)$claim['id'];$token=$claim['token'];$next=(int)$claim['attempt'];
    $state=$ack?'acknowledged':'unknown';$result=$ack?'ack':'unknown';
    $q=$db->prepare("UPDATE payment_delivery_intents SET state=?,dispatch_token=NULL,
        acknowledged_at=IF(?='ack',NOW(),NULL),last_result=?
        WHERE id=? AND state='dispatching' AND dispatch_token=? AND attempt_count=?");
    if (!$q) throw new RuntimeException('delivery_finalize_prepare');
    $q->bind_param('sssisi',$state,$result,$result,$id,$token,$next);
    $ok=$q->execute() && $q->affected_rows===1;$q->close();
    if (!$ok) throw new RuntimeException('delivery_finalize_token_mismatch');
    $a=$db->prepare("UPDATE payment_delivery_attempts SET result=?,finished_at=NOW()
        WHERE intent_id=? AND attempt_no=? AND dispatch_token=? AND result='dispatching'");
    if (!$a) throw new RuntimeException('delivery_finalize_attempt_prepare');
    $a->bind_param('siis',$state,$id,$next,$token);
    $ok=$a->execute() && $a->affected_rows===1;$a->close();
    if (!$ok) throw new RuntimeException('delivery_finalize_attempt_unknown');
    return $state;
}
/** One CAS winner is allowed to emit; ambiguous outcome is never retried. */
function achbotDeliveryDispatch(mysqli $db,int $payId,int $ordinal): string {
    if (!achbotDeliverySchemaReady($db)) return 'unavailable';
    require_once __DIR__.'/GatewayClient.php';
    $lease=achbotOriginalDispatchLeaseBegin($db,$payId);
    if ($lease!=='owned' && $lease!=='borrowed') {
        achbotDiagnosticEvent('achbot.delivery.dispatch_deferred',
            ['pay_id'=>$payId,'ordinal'=>$ordinal,'reason'=>'lease_'.$lease],'info');
        return $lease;
    }
    try {
    $token='';$packet=null;$begun=false;
    try {
        if (!$db->begin_transaction()) return 'unavailable';$begun=true;
        $lock=$db->prepare('SELECT * FROM pays WHERE id=? FOR UPDATE');
        $lock->bind_param('i',$payId);$lock->execute();
        $pay=$lock->get_result()->fetch_assoc();$lock->close();
        $q=$db->prepare('SELECT * FROM payment_delivery_intents WHERE pay_row_id=? AND ordinal=? FOR UPDATE');
        $q->bind_param('ii',$payId,$ordinal);$q->execute();$d=$q->get_result()->fetch_assoc();$q->close();
        if (!$pay || !$d) throw new RuntimeException('delivery_missing');
        // No operator resend surface: an uncertain Telegram ACK never triggers
        // another copy, while a never-started PENDING packet may dispatch once.
        if ($d['state']!=='pending') throw new RuntimeException('delivery_not_pending');
        $packet=achbotDeliveryValidateLocked($db,$pay,$d);
        if ($packet===null) throw new RuntimeException('delivery_identity_or_payload_invalid');
        $claim=achbotDeliveryClaimLocked($db,$d);
        $next=$claim['attempt'];
        if (!$db->commit()) throw new RuntimeException('delivery_claim_unknown');
        $begun=false;
    } catch(Throwable $e) {
        if ($begun) try {$db->rollback();}catch(Throwable $ignored){}
        achbotDiagnosticEvent('achbot.delivery.claim_deferred',
            ['pay_id'=>$payId,'ordinal'=>$ordinal,
                'class'=>get_class($e),'reason'=>$e->getMessage()],'warning');
        error_log('achbot_delivery_claim_deferred pay_id='.$payId.' ordinal='.$ordinal.' reason='.get_class($e));
        return 'review';
    }
    $ack=false;
    try {
        $ack=achbotDeliverySendPacket($packet,$payId,$ordinal,$next,0);
    } catch(Throwable $e) {
        error_log('achbot_delivery_telegram_uncertain pay_id='.$payId.' ordinal='.$ordinal.
            ' class='.get_class($e));
    }
    $result=$ack?'ack':'unknown';
    $state=$ack?'acknowledged':'unknown';
    achbotDiagnosticEvent('achbot.delivery.telegram_attempt',
        ['pay_id'=>$payId,'ordinal'=>$ordinal,'result'=>$state,
            'request_attempt'=>$next],$ack?'info':'warning');
    $finished=false;
    try {
        if (!$db->begin_transaction()) return 'unknown';
        achbotDeliveryFinishClaimLocked($db,$claim,$ack);
        if (!$db->commit()) throw new RuntimeException('delivery_finalize_unknown');
        $finished=true;
    } catch(Throwable $e) {
        try {$db->rollback();} catch(Throwable $ignored) {}
        achbotDiagnosticEvent('achbot.delivery.finalize_deferred',
            ['pay_id'=>$payId,'ordinal'=>$ordinal,
                'class'=>get_class($e),'reason'=>$e->getMessage()],'error');
        error_log('achbot_delivery_finalize_unknown pay_id='.$payId.' ordinal='.$ordinal);
    }
    achbotDiagnosticEvent('achbot.delivery.dispatch_result',
        ['pay_id'=>$payId,'ordinal'=>$ordinal,
            'result'=>$finished?$state:'unknown'],'info');
    return $finished?$state:'unknown';
    } finally {
        achbotOriginalDispatchLeaseEnd($db,$payId,$lease);
    }
}
/** Read-only gate for a SINGLE deliberate operator resend of an UNKNOWN
 * original packet. This is distinct from the pending-only automated worker.
 * An existing human attempt permanently consumes the one manual opportunity;
 * the original UNKNOWN acknowledgement is never rewritten or inferred.
 */
function achbotDeliveryManualCandidate(mysqli $db,int $payId,int $ordinal=0): ?array {
    if ($payId<=0 || $ordinal<0 || !achbotDeliverySchemaReady($db)) return null;
    try {
        $q=$db->prepare("SELECT d.id,d.ordinal,d.user_id,d.attempt_count,d.payload_sha256
            FROM pays p
            JOIN payment_attempts a ON a.pay_row_id=p.id
            JOIN payment_delivery_intents d ON d.pay_row_id=p.id AND d.attempt_id=a.id
            WHERE p.id=? AND (?=0 OR d.ordinal=?)
              AND p.state IN ('approved','paid','auto_cart_approved','paid_with_wallet')
              AND a.review_archived_at IS NULL AND d.state='unknown'
              AND d.attempt_count>0 AND NOT EXISTS
                (SELECT 1 FROM payment_delivery_attempts h
                 WHERE h.intent_id=d.id AND h.actor_userid>0)
            ORDER BY d.ordinal LIMIT 1");
        // Non-exception mysqli modes are also supported; optional 022 must
        // fail closed rather than dereference a false prepare result.
        if (!$q) {
            if ((int)$db->errno===1054) return null;
            throw new RuntimeException('manual_redrive_lookup_prepare_failed');
        }
        $q->bind_param('iii',$payId,$ordinal,$ordinal);
        $q->execute();$candidate=$q->get_result()->fetch_assoc();$q->close();
        return $candidate?:null;
    } catch(Throwable $e) {
        // 022 Archive is optional on older installations. The shared inbox
        // cannot serve this action there, without noisy 1054 log spam.
        if ($e instanceof mysqli_sql_exception && (int)$e->getCode()===1054)
            return null;
        error_log('achbot_delivery_manual_lookup_unavailable pay_id='.$payId.
            ' class='.get_class($e));
        return null;
    }
}

/** Stateless, actor-bound proof that the duplicate-warning preview was
 * rendered for THIS original intent/version. Its callback is never an
 * authorization by itself: the execution path rechecks staff roles, payment
 * and immutable packet under the original database locks.
 */
function achbotDeliveryManualConfirmationToken(array $candidate,int $payId,int $actor): string {
    if ($payId<=0 || $actor<=0 || (int)($candidate['id']??0)<=0 ||
        (int)($candidate['ordinal']??0)<=0 ||
        !preg_match('/^[a-f0-9]{64}$/D',(string)($candidate['payload_sha256']??'')))
        throw new RuntimeException('manual_redrive_preview_identity_invalid');
    $proof=implode('|',['manual-redrive-preview-v1',$actor,$payId,
        (int)$candidate['id'],(int)$candidate['ordinal'],
        (int)$candidate['attempt_count'],(string)$candidate['payload_sha256']]);
    // 80-bit MAC leaves room for max-size current SQL ids inside Telegram's
    // actual 64-byte callback_data limit; no arbitrary preview TTL.
    return substr(hash_hmac('sha256',$proof,achbotPaymentEvidenceKey()),0,20);
}

/** Explicitly confirmed by a current payments-capable administrator.
 * Never calls the original financial, Native or merchant coordinator.
 * One human dispatch at most per original UNKNOWN intent. The original
 * session lease covers network send and the ACK/UNKNOWN DB journal.
 */
function achbotDeliveryManualRedrive(mysqli $db,int $payId,int $ordinal,
                                    int $actor,int $primary): string {
    if ($payId<=0 || $ordinal<=0 || $actor<=0 || $primary<=0 ||
        !achbotDeliverySchemaReady($db)) return 'unavailable';
    require_once __DIR__.'/GatewayClient.php';
    $lease=achbotOriginalDispatchLeaseBegin($db,$payId);
    // A human callback must own its session lease, never borrow a financial
    // worker's lock even if a parent happened to share the same connection.
    if ($lease!=='owned') return $lease==='busy'||$lease==='borrowed'?'busy':'unavailable';
    try {
        $begun=false;$token='';$packet=null;$next=0;
        try {
            if (!$db->begin_transaction()) return 'unavailable';
            $begun=true;
            $q=$db->prepare('SELECT * FROM pays WHERE id=? FOR UPDATE');
            $q->bind_param('i',$payId);$q->execute();
            $pay=$q->get_result()->fetch_assoc();$q->close();
            if (!$pay || !in_array((string)$pay['state'],
                ['approved','paid','auto_cart_approved','paid_with_wallet'],true))
                throw new RuntimeException('manual_redrive_not_terminal');
            $q=$db->prepare('SELECT * FROM payment_attempts WHERE pay_row_id=? FOR UPDATE');
            $q->bind_param('i',$payId);$q->execute();
            $attempt=$q->get_result()->fetch_assoc();$q->close();
            if (!$attempt || ($attempt['review_archived_at']??null)!==null)
                throw new RuntimeException('manual_redrive_archived_or_missing');
            $q=$db->prepare('SELECT * FROM payment_delivery_intents
                WHERE pay_row_id=? AND ordinal=? FOR UPDATE');
            $q->bind_param('ii',$payId,$ordinal);$q->execute();
            $d=$q->get_result()->fetch_assoc();$q->close();
            if (!$d || (int)$d['attempt_id']!==(int)$attempt['id'] ||
                $d['state']!=='unknown' || (int)$d['attempt_count']<1)
                throw new RuntimeException('manual_redrive_not_unknown');
            if (!achbotAdminCanLocked($db,$actor,$primary,'payments'))
                throw new RuntimeException('manual_redrive_denied');
            $q=$db->prepare('SELECT id FROM payment_delivery_attempts
                WHERE intent_id=? AND actor_userid>0 LIMIT 1');
            $did=(int)$d['id'];$q->bind_param('i',$did);$q->execute();
            $prior=$q->get_result()->fetch_assoc();$q->close();
            if ($prior) throw new RuntimeException('manual_redrive_already_attempted');
            $packet=achbotDeliveryValidateLocked($db,$pay,$d);
            if ($packet===null)
                throw new RuntimeException('manual_redrive_original_proof_invalid');
            $token=bin2hex(random_bytes(16));
            $count=(int)$d['attempt_count'];$next=$count+1;
            $digest=(string)$d['payload_sha256'];
            $q=$db->prepare("UPDATE payment_delivery_intents SET
                state='dispatching',attempt_count=attempt_count+1,
                dispatch_token=?,dispatched_at=NOW(),last_actor=?,last_result=NULL
                WHERE id=? AND state='unknown' AND attempt_count=? AND payload_sha256=?");
            $q->bind_param('siiis',$token,$actor,$did,$count,$digest);
            $ok=$q->execute() && $q->affected_rows===1;$q->close();
            if (!$ok) throw new RuntimeException('manual_redrive_claim_conflict');
            $q=$db->prepare('INSERT INTO payment_delivery_attempts
                (intent_id,attempt_no,dispatch_token,actor_userid) VALUES(?,?,?,?)');
            $q->bind_param('iisi',$did,$next,$token,$actor);
            $ok=$q->execute() && $q->affected_rows===1;$q->close();
            if (!$ok || !$db->commit())
                throw new RuntimeException('manual_redrive_claim_unconfirmed');
            $begun=false;
        } catch(Throwable $e) {
            if ($begun) try {$db->rollback();}catch(Throwable $ignored){}
            error_log('achbot_delivery_manual_claim_held pay_id='.$payId.
                ' ordinal='.$ordinal.' actor='.$actor.' class='.get_class($e).
                ' code='.preg_replace('/[^a-z0-9_]/','',
                     substr($e->getMessage(),0,80)));
            return match($e->getMessage()) {
                'manual_redrive_denied'=>'denied',
                'manual_redrive_not_terminal',
                'manual_redrive_archived_or_missing',
                'manual_redrive_not_unknown',
                'manual_redrive_already_attempted',
                'manual_redrive_original_proof_invalid',
                'manual_redrive_claim_conflict'=>'stale',
                default=>'unavailable',
            };
        }
        error_log('achbot_delivery_manual_attempt_started pay_id='.$payId.
            ' ordinal='.$ordinal.' attempt='.$next.' actor='.$actor);
        $ack=false;
        try {
            $ack=achbotDeliverySendPacket($packet,$payId,$ordinal,$next,$actor);
        } catch(Throwable $e) {
            error_log('achbot_delivery_manual_telegram_uncertain pay_id='.$payId.
                ' ordinal='.$ordinal.' attempt='.$next.' actor='.$actor.
                ' class='.get_class($e));
        }
        $result=$ack?'ack':'unknown';$state=$ack?'acknowledged':'unknown';
        $finished=false;
        try {
            if (!$db->begin_transaction()) return 'unknown';
            $q=$db->prepare("UPDATE payment_delivery_intents SET
                state=?,dispatch_token=NULL,acknowledged_at=IF(?='ack',NOW(),NULL),
                last_result=? WHERE pay_row_id=? AND ordinal=?
                AND state='dispatching' AND dispatch_token=?");
            $q->bind_param('sssiis',$state,$result,$result,$payId,$ordinal,$token);
            $ok=$q->execute() && $q->affected_rows===1;$q->close();
            if (!$ok) throw new RuntimeException('manual_redrive_finalize_conflict');
            $q=$db->prepare("UPDATE payment_delivery_attempts SET
                result=?,finished_at=NOW()
                WHERE dispatch_token=? AND actor_userid=? AND result='dispatching'");
            $q->bind_param('ssi',$state,$token,$actor);
            $ok=$q->execute() && $q->affected_rows===1;$q->close();
            if (!$ok || !$db->commit())
                throw new RuntimeException('manual_redrive_finalize_unconfirmed');
            $finished=true;
        } catch(Throwable $e) {
            try {$db->rollback();}catch(Throwable $ignored){}
            error_log('achbot_delivery_manual_finalize_unknown pay_id='.$payId.
                ' ordinal='.$ordinal.' attempt='.$next.' actor='.$actor.
                ' class='.get_class($e));
        }
        if ($finished) {
            achbotDeliverySyncAggregate($db,$payId);
            error_log('achbot_delivery_manual_attempt_result pay_id='.$payId.
                ' ordinal='.$ordinal.' attempt='.$next.' actor='.$actor.
                ' result='.$state);
        }
        return $finished?$state:'unknown';
    } finally {
        achbotOriginalDispatchLeaseEnd($db,$payId,$lease);
    }
}
/** Safe to call on a repeated terminal callback: only PENDING emits.
 * Page with monotone ordinals, not a hidden account-count ceiling.
 * A future explicit coordinator may bound walltime without losing due rows.
 */
function achbotDeliveryDispatchPending(mysqli $db,int $payId): array {
    if (!achbotDeliverySchemaReady($db)) return [];
    $cursor=0;$out=[];
    while (true) {
        $q=$db->prepare("SELECT ordinal FROM payment_delivery_intents
            WHERE pay_row_id=? AND state='pending' AND ordinal>?
            ORDER BY ordinal LIMIT 100");
        $q->bind_param('ii',$payId,$cursor);$q->execute();
        $rows=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
        if (!$rows) break;
        foreach ($rows as $d) {
            $cursor=(int)$d['ordinal'];
            $out[$cursor]=achbotDeliveryDispatch($db,$payId,$cursor);
        }
    }
    return $out;
}
/**
 * Recover only first, never-attempted customer delivery after a committed
 * payment/order.  The 018 journal already has a unique per-ordinal claim and
 * a CAS-based dispatch history; this sweep never changes money or Native
 * provider state and never resends UNKNOWN/dispatching packets.
 *
 * A quiet two-minute window lets the original callback finish first.  A
 * deferred preflight merely rotates updated_at to avoid starving later valid
 * packets; it is NOT a Telegram attempt or permission to rebuild the packet.
 * This worker has a bounded batch and soft wall-time budget (one transport
 * call can still take longer than the budget).
 */
function achbotDeliveryMaintainPending(mysqli $db,int $limit=4,float $budgetSeconds=8.0): array {
    $stats=['checked'=>0,'acknowledged'=>0,'unknown'=>0,'deferred'=>0];
    if (!achbotDeliverySchemaReady($db)) return $stats;
    $limit=max(1,min(8,$limit));
    $budgetSeconds=max(1.0,min(15.0,$budgetSeconds));
    $start=microtime(true);
    try {
        // idx_delivery_work(state,updated_at) is part of migration 018.
        // The terminal state is necessary but insufficient: dispatch itself
        // verifies the immutable financial/native/order/delivery provenance
        // again under row locks before contacting Telegram.
        $column=$db->query("SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payment_delivery_intents'
              AND COLUMN_NAME='service_operation_id'");
        if (!$column) throw new RuntimeException('delivery_maintenance_schema_unavailable');
        $hasService=$column->num_rows===1;$column->free();
        if ($hasService) {
            require_once __DIR__.'/ServiceDelivery.php';
            if (!achbotServiceDeliverySchemaReady($db))
                throw new RuntimeException('delivery_service_schema_invalid');
            $q=$db->query("SELECT d.id,d.pay_row_id,d.ordinal,d.service_operation_id
                FROM payment_delivery_intents d
                LEFT JOIN pays p ON p.id=d.pay_row_id
                LEFT JOIN service_operations o ON o.id=d.service_operation_id
                WHERE d.state IN ('pending','dispatching')
                  AND d.updated_at<=DATE_SUB(NOW(),INTERVAL 2 MINUTE)
                  AND ((d.service_operation_id IS NULL AND d.state='pending' AND d.attempt_count=0
                      AND p.state IN ('approved','paid','auto_cart_approved','paid_with_wallet'))
                    OR (o.stage='completed' AND d.service_operation_id IS NOT NULL
                      AND ((d.state='pending' AND d.attempt_count=0) OR d.state='dispatching')))
                ORDER BY d.updated_at,d.id LIMIT {$limit}");
        } else {
            // The original 018 schema remains readable until the approved
            // forward migration. No new service row exists in that schema.
            $q=$db->query("SELECT d.id,d.pay_row_id,d.ordinal
                FROM payment_delivery_intents d
                JOIN pays p ON p.id=d.pay_row_id
                WHERE d.state='pending' AND d.attempt_count=0
                  AND p.state IN ('approved','paid','auto_cart_approved','paid_with_wallet')
                  AND d.updated_at<=DATE_SUB(NOW(),INTERVAL 2 MINUTE)
                ORDER BY d.updated_at,d.id LIMIT {$limit}");
        }
        if (!$q) throw new RuntimeException('delivery_maintenance_scan_failed');
        $rows=$q->fetch_all(MYSQLI_ASSOC);
        $q->free();
    } catch (Throwable $error) {
        achbotDiagnosticEvent('achbot.delivery.maintenance_error',
            ['class'=>get_class($error),'reason'=>$error->getMessage(),
             'stage'=>'scan'],'warning');
        error_log('achbot_delivery_maintenance_scan_deferred class='.get_class($error));
        return $stats;
    }
    $started=microtime(true);
    foreach ($rows as $d) {
        if (microtime(true)-$started >= $budgetSeconds) break;
        $id=(int)$d['id'];$payId=(int)$d['pay_row_id'];
        $ordinal=(int)$d['ordinal'];
        $stats['checked']++;
        try {
            $outcome=($d['service_operation_id']??null)!==null?
                achbotServiceDeliveryDispatch($db,$id):achbotDeliveryDispatch($db,$payId,$ordinal);
        } catch (Throwable $error) {
            error_log('achbot_delivery_maintenance_dispatch_deferred pay_id='.$payId.
                ' ordinal='.$ordinal.' class='.get_class($error));
            $outcome='review';
        }
        if ($outcome==='acknowledged') {
            $stats['acknowledged']++;
        } elseif ($outcome==='unknown') {
            // One attempted send may already have succeeded.  Keep its
            // uncertainty in the 018/attempt ledgers; never retry from Cron.
            $stats['unknown']++;
        } else {
            $stats['deferred']++;
            try {
                // No packet/state/attempt/actor changes.  A failed readiness
                // check is retried no sooner than another two-minute window
                // and cannot monopolize the head of the bounded queue.
                // Also defer abandoned service claim reconciliation on a
                // transient SQL/ownership error, without changing its token.
                $where=($d['service_operation_id']??null)!==null?
                    "((state='pending' AND attempt_count=0) OR state='dispatching')":
                    "(state='pending' AND attempt_count=0)";
                $q=$db->prepare("UPDATE payment_delivery_intents
                    SET updated_at=NOW() WHERE id=? AND ".$where);
                $q->bind_param('i',$id);
                $q->execute();$q->close();
            } catch (Throwable $error) {
                error_log('achbot_delivery_maintenance_defer_failed pay_id='.$payId.
                    ' ordinal='.$ordinal.' class='.get_class($error));
            }
        }
    }
    achbotDiagnosticEvent('achbot.delivery.maintenance_tick',
        ['checked'=>$stats['checked'],'success'=>$stats['acknowledged'],
            'failure'=>$stats['unknown'],'deferred'=>$stats['deferred'],
            'selected'=>count($rows),
            'elapsed_ms'=>(int)round((microtime(true)-$start)*1000)],'info');
    return $stats;
}

function achbotDeliveryAggregate(mysqli $db,int $payId): ?array {
    if (!achbotDeliverySchemaReady($db)) return null;
    $q=$db->prepare("SELECT COUNT(*) AS total,
        COALESCE(SUM(state='acknowledged'),0) AS ack,
        COALESCE(SUM(state='pending'),0) AS pending,
        COALESCE(SUM(state='unknown'),0) AS unknown,
        COALESCE(SUM(state='dispatching'),0) AS dispatching
        FROM payment_delivery_intents WHERE pay_row_id=?");
    $q->bind_param('i',$payId);$q->execute();
    $row=$q->get_result()->fetch_assoc();$q->close();
    return $row?:null;
}
/** Projection is advisory; independent per-line ACKs remain authoritative. */
function achbotDeliverySyncAggregate(mysqli $db,int $payId): void {
    $a=achbotDeliveryAggregate($db,$payId);
    if (!$a || (int)$a['total']<1) return;
    $state=(int)$a['ack']===(int)$a['total']?'done':'delivery_unconfirmed';
    $q=$db->prepare("UPDATE payment_attempts a JOIN pays p ON p.id=a.pay_row_id
        SET a.evidence_fulfillment_state=? WHERE p.id=?
          AND p.state IN ('approved','paid','auto_cart_approved','paid_with_wallet')
          AND a.evidence_profile IS NOT NULL
          AND (a.evidence_fulfillment_state IS NULL OR a.evidence_fulfillment_state IN
            ('verified','delivery_unconfirmed','delivery_dispatching','done','reserved'))");
    $q->bind_param('si',$state,$payId);$q->execute();$q->close();
}
