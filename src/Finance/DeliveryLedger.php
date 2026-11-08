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
function achbotDeliveryInsertHostedBuyLocked(
    mysqli $db,array $pay,array $attempt,array $effect,int $ordinal,
    int $orderId,array $packet
): void {
    if (($attempt['stage']??null)!=='review' ||
        ($attempt['provider_status']??null)!=='fulfillment_started' ||
        (int)($effect['order_id']??0)!==$orderId)
        throw new RuntimeException('delivery_hosted_original_claim_unavailable');
    $digest=achbotDeliveryHostedBuyDigest($pay,$attempt,$effect,$ordinal);
    if ($digest===null) throw new RuntimeException('delivery_hosted_original_proof_invalid');
    $packet['kind']='qr';
    achbotDeliveryInsertLocked($db,$pay,$attempt,$ordinal,(int)$effect['id'],
        $orderId,(int)$effect['target_server_id'],$digest,
        (string)$effect['operation_id'],(string)$effect['target_key'],$packet);
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
    if (!$attempt || (int)$attempt['id']!==(int)$d['attempt_id']) return null;
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
        $q=$db->prepare('SELECT id,pay_row_id,ordinal,state,order_id,target_server_id,'.
            'operation_id,target_key,target_group_id,request_sha256,endpoint_sha256,'.
              'remote_username,effect_kind,plan_id,requested_expire,requested_bytes,reserved_count '.
            'FROM service_effects WHERE id=? FOR UPDATE');
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
        } elseif (in_array((string)$attempt['provider'],
                ['nextpay','zarinpal'],true)) {
            // Existing original paid + linked native effect. Sending uses
            // the sealed packet and never repeats merchant/native effects.
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
/** One CAS winner is allowed to emit; ambiguous outcome is never retried. */
function achbotDeliveryDispatch(mysqli $db,int $payId,int $ordinal,int $actor=0,
                                ?string $challenge=null): string {
    if (!achbotDeliverySchemaReady($db)) return 'unavailable';
    if ($actor<0 || ($actor>0 && ($actor!==(int)($GLOBALS['admin']??0) ||
        !achbotAdminCanFor($db,$actor,(int)$GLOBALS['admin'],'review')))) return 'denied';
    $token='';$packet=null;$begun=false;
    try {
        if (!$db->begin_transaction()) return 'unavailable';$begun=true;
        $lock=$db->prepare('SELECT * FROM pays WHERE id=? FOR UPDATE');
        $lock->bind_param('i',$payId);$lock->execute();
        $pay=$lock->get_result()->fetch_assoc();$lock->close();
        $q=$db->prepare('SELECT * FROM payment_delivery_intents WHERE pay_row_id=? AND ordinal=? FOR UPDATE');
        $q->bind_param('ii',$payId,$ordinal);$q->execute();$d=$q->get_result()->fetch_assoc();$q->close();
        if (!$pay || !$d) throw new RuntimeException('delivery_missing');
        if ($actor===0 && $d['state']!=='pending') throw new RuntimeException('delivery_not_pending');
        if ($actor>0 && (!in_array($d['state'],['pending','unknown'],true) ||
            !achbotDeliveryChallengeValid($d,$actor,(string)$challenge)))
            throw new RuntimeException('delivery_challenge_invalid');
        $packet=achbotDeliveryValidateLocked($db,$pay,$d);
        if ($packet===null) throw new RuntimeException('delivery_identity_or_payload_invalid');
        $token=bin2hex(random_bytes(16));
        $id=(int)$d['id'];$state=(string)$d['state'];$count=(int)$d['attempt_count'];
        $q=$db->prepare("UPDATE payment_delivery_intents SET state='dispatching',attempt_count=attempt_count+1,
            dispatch_token=?,dispatched_at=NOW(),last_actor=?,last_result=NULL
            WHERE id=? AND state=? AND attempt_count=? AND payload_sha256=?");
        $digest=(string)$d['payload_sha256'];
        $q->bind_param('siisis',$token,$actor,$id,$state,$count,$digest);
        $ok=$q->execute() && $q->affected_rows===1;$q->close();
        if (!$ok) throw new RuntimeException('delivery_claim_not_saved');
        $next=$count+1;
        $a=$db->prepare("INSERT INTO payment_delivery_attempts
            (intent_id,attempt_no,dispatch_token,actor_userid)
            VALUES (?,?,?,?)");
        $a->bind_param('iisi',$id,$next,$token,$actor);
        $ok=$a->execute() && $a->affected_rows===1;$a->close();
        if (!$ok || !$db->commit()) throw new RuntimeException('delivery_claim_unknown');
        $begun=false;
    } catch(Throwable $e) {
        if ($begun) try {$db->rollback();}catch(Throwable $ignored){}
        error_log('achbot_delivery_claim_deferred pay_id='.$payId.' ordinal='.$ordinal.' reason='.get_class($e));
        return 'review';
    }
    $ack=false;
    try {
        if ($packet['kind']==='qr') {
            $ack=sendQrFromLink($packet['qr'],$packet['caption'],$packet['keys'],
                $packet['bot_url'],(int)$packet['uid'])===true;
        } else {
            $mode=$packet['mode']==='plain'?null:'HTML';
            $answer=sendMessage($packet['text'],null,$mode,(int)$packet['uid']);
            $ack=($answer===true || (is_object($answer)&&($answer->ok??false)===true)
                || (is_array($answer)&&($answer['ok']??false)===true));
        }
    } catch(Throwable $e) {
        error_log('achbot_delivery_telegram_uncertain pay_id='.$payId.' ordinal='.$ordinal.
            ' class='.get_class($e));
    }
    $result=$ack?'ack':'unknown';
    $state=$ack?'acknowledged':'unknown';
    $finished=false;
    try {
        if (!$db->begin_transaction()) return 'unknown';
        $q=$db->prepare("UPDATE payment_delivery_intents SET
            state=?,dispatch_token=NULL,acknowledged_at=IF(?='ack',NOW(),NULL),last_result=?
            WHERE pay_row_id=? AND ordinal=? AND state='dispatching' AND dispatch_token=?");
        $q->bind_param('sssiis',$state,$result,$result,$payId,$ordinal,$token);
        $ok=$q->execute() && $q->affected_rows===1;$q->close();
        if (!$ok) throw new RuntimeException('delivery_finalize_token_mismatch');
        $a=$db->prepare("UPDATE payment_delivery_attempts SET result=?,finished_at=NOW()
            WHERE dispatch_token=? AND result='dispatching'");
        $a->bind_param('ss',$state,$token);
        $ok=$a->execute() && $a->affected_rows===1;$a->close();
        if (!$ok || !$db->commit()) throw new RuntimeException('delivery_finalize_unknown');
        $finished=true;
    } catch(Throwable $e) {
        try {$db->rollback();} catch(Throwable $ignored) {}
        error_log('achbot_delivery_finalize_unknown pay_id='.$payId.' ordinal='.$ordinal);
    }
    return $finished?$state:'unknown';
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
/** Bounded operator-visible projection; never return private sealed packets. */
function achbotDeliveryInspect(mysqli $db,int $payId,int $after=0): array {
    if (!achbotDeliverySchemaReady($db) || $after<0) return [];
    $q=$db->prepare("SELECT pay_row_id,ordinal,user_id,effect_id,order_id,state,
        attempt_count,dispatched_at,acknowledged_at,last_actor,last_result
        FROM payment_delivery_intents WHERE pay_row_id=? AND ordinal>?
        ORDER BY ordinal LIMIT 11");
    $q->bind_param('ii',$payId,$after);$q->execute();
    $rows=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
    return $rows;
}
function achbotDeliveryInspectOne(mysqli $db,int $payId,int $ordinal): ?array {
    if (!achbotDeliverySchemaReady($db)) return null;
    $q=$db->prepare("SELECT pay_row_id,ordinal,user_id,effect_id,order_id,state,
        attempt_count,dispatched_at,acknowledged_at,last_actor,last_result
        FROM payment_delivery_intents WHERE pay_row_id=? AND ordinal=?");
    $q->bind_param('ii',$payId,$ordinal);$q->execute();
    $row=$q->get_result()->fetch_assoc();$q->close();
    return $row?:null;
}
/** Stage-specific, passive integrity check; never an evidence-free re-send. */
function achbotDeliveryCheck(mysqli $db,int $payId,int $ordinal): string {
    if (!achbotDeliverySchemaReady($db)) return 'unavailable';
    try {
        if (!$db->begin_transaction()) return 'unavailable';
        $q=$db->prepare('SELECT * FROM pays WHERE id=? FOR UPDATE');
        $q->bind_param('i',$payId);$q->execute();$pay=$q->get_result()->fetch_assoc();$q->close();
        $q=$db->prepare('SELECT * FROM payment_delivery_intents WHERE pay_row_id=? AND ordinal=? FOR UPDATE');
        $q->bind_param('ii',$payId,$ordinal);$q->execute();$d=$q->get_result()->fetch_assoc();$q->close();
        $result=($pay && $d && achbotDeliveryValidateLocked($db,$pay,$d)!==null)
            ? (string)$d['state']:'identity_or_payload_invalid';
        $db->rollback();
        return $result;
    } catch(Throwable $e) {
        try {$db->rollback();}catch(Throwable $ignored){}
        error_log('achbot_delivery_integrity_check_unavailable pay_id='.$payId.' ordinal='.$ordinal);
        return 'unavailable';
    }
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
function achbotDeliveryMarkStaleUnknown(mysqli $db,int $payId,int $ordinal): void {
    if (!achbotDeliverySchemaReady($db)) return;
    if (!$db->begin_transaction()) return;
    try {
        // The exact old lease token is preserved in the attempt history,
        // even though the summary is released to permit a warned resend.
        $q=$db->prepare("SELECT id,dispatch_token FROM payment_delivery_intents
            WHERE pay_row_id=? AND ordinal=? AND state='dispatching'
            AND dispatched_at < DATE_SUB(NOW(),INTERVAL 10 MINUTE) FOR UPDATE");
        $q->bind_param('ii',$payId,$ordinal);$q->execute();
        $row=$q->get_result()->fetch_assoc();$q->close();
        if ($row && is_string($row['dispatch_token']) && $row['dispatch_token']!=='') {
            $q=$db->prepare("UPDATE payment_delivery_intents SET state='unknown',
                dispatch_token=NULL,last_result='process_lost' WHERE id=? AND dispatch_token=?");
            $q->bind_param('is',$row['id'],$row['dispatch_token']);$q->execute();
            $ok=$q->affected_rows===1;$q->close();
            $a=$db->prepare("UPDATE payment_delivery_attempts SET result='unknown',
                finished_at=NOW() WHERE intent_id=? AND dispatch_token=? AND result='dispatching'");
            $a->bind_param('is',$row['id'],$row['dispatch_token']);$a->execute();
            $ok=$ok && $a->affected_rows===1;$a->close();
            if (!$ok) throw new RuntimeException('delivery_stale_lease_audit_mismatch');
        }
        if (!$db->commit()) throw new RuntimeException('delivery_stale_lease_commit_unknown');
    } catch(Throwable $e) {
        try {$db->rollback();}catch(Throwable $ignored){}
        error_log('achbot_delivery_stale_check_deferred pay_id='.$payId.' ordinal='.$ordinal);
    }
}
/** Stateless owner-private two-step signed approval, invalidated by any attempt. */
function achbotDeliveryChallenge(array $row,int $actor): string {
    $minute=intdiv(time(),60);
    $data=(int)$row['pay_row_id'].'|'.(int)$row['ordinal'].'|'.$actor.'|'.
        (string)$row['state'].'|'.(int)$row['attempt_count'].'|'.$minute;
    $sig=substr(hash_hmac('sha256','achbot-delivery/confirm/v1/'.$data,
        achbotPaymentEvidenceKey()),0,12);
    return $minute.'_'.$sig;
}
function achbotDeliveryChallengeValid(array $row,int $actor,string $challenge): bool {
    if ($actor<=0 || !preg_match('/^([1-9][0-9]{0,10})_([a-f0-9]{12})$/D',$challenge,$m)) return false;
    $minute=(int)$m[1];$now=intdiv(time(),60);
    if ($minute>$now || $minute<$now-2) return false;
    $data=(int)$row['pay_row_id'].'|'.(int)$row['ordinal'].'|'.$actor.'|'.
        (string)$row['state'].'|'.(int)$row['attempt_count'].'|'.$minute;
    return hash_equals(substr(hash_hmac('sha256','achbot-delivery/confirm/v1/'.$data,
        achbotPaymentEvidenceKey()),0,12),$m[2]);
}
