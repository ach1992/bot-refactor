<?php
/** Optional existing-API evidence inside the original manual payment ledger. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }
require_once __DIR__.'/../Diagnostics/EventLog.php';
require_once __DIR__.'/ReceiptHelpers.php';
require_once __DIR__.'/PaymentLifecycle.php';
require_once __DIR__.'/ApiPaymentPresentation.php';
if (is_file(__DIR__.'/PaymentEvidence.php')) require_once __DIR__.'/PaymentEvidence.php';

/** Protect each original API payment throughout evidence redemption,
 * native fulfillment and pending message delivery. Archive checks the same
 * DB-scoped session lock. Nested settlement in a poll shares the existing lease.
 */
function achbotApiPaymentWithWorkerLock(mysqli $db,int $payId,callable $action): string {
    if ($payId<=0) return 'review';
    require_once __DIR__.'/GatewayClient.php';
    static $held=[];
    $key=spl_object_id($db).':'.$payId;
    if (isset($held[$key])) return $action();
    try {
        $name=achbotHostedWorkerLockName($db,$payId);
        $q=$db->prepare('SELECT GET_LOCK(?,0) AS acquired');
        $q->bind_param('s',$name);$q->execute();
        $row=$q->get_result()->fetch_assoc();$q->close();
        if ((int)($row['acquired']??0)!==1) {
            achbotDiagnosticEvent('achbot.finance.worker_lock',
                ['pay_id'=>$payId,'reason'=>'worker_busy'],'info');
            return 'review';
        }
    } catch (Throwable $error) {
        error_log('achbot_api_worker_lock_unavailable pay_id='.$payId.
            ' class='.get_class($error));
        return 'review';
    }
    $held[$key]=true;
    try {return $action();}
    finally {
        unset($held[$key]);
        try {
            $q=$db->prepare('SELECT RELEASE_LOCK(?) AS released');
            $q->bind_param('s',$name);$q->execute();$q->close();
        } catch (Throwable $error) {
            error_log('achbot_api_worker_unlock_unavailable pay_id='.$payId.
                ' class='.get_class($error));
        }
    }
}

/** Display-only proof of a safe original API continuation; settlement
 * repeats all immutable evidence and native-effect checks under its locks.
 * A processing service belongs to technical recovery, not financial review.
 */
function achbotApiPaymentCanResume(mysqli $db,array $pay): bool {
    $provider=(string)($pay['attempt']['provider']??'');
    $state=(string)($pay['state']??'');
    $id=(int)($pay['id']??0);
    if (($pay['attempt']['review_archived_at']??null)!==null) return false;
    if ($id<=0 || !in_array($provider,['bank_transfer','gift_card'],true) ||
        !in_array($state,['pending','have_sent','processing'],true) ||
        ($state==='processing' && (int)($pay['approved_by']??-1)!==0)) return false;
    $proof=achbotPaymentEvidenceGetProof($db,$id);
    if (($proof['mode']??null)!=='settle') return false;
    if ($state!=='processing' || (string)($pay['type']??'')==='BUY_SUB') return true;
    $attempt=achbotApiPaymentAttempt($db,$id);
    // A committed Native dispatch is precisely where an administrator must
    // have an available completion action, not a permanently hidden button.
    return in_array((string)($attempt['evidence_fulfillment_state']??''),
        ['reserved','dispatched','verified'],true);
}


/**
 * Staff confirmation applies ONLY when an original Native operation may
 * already have been sent. Mere receipt verification/review has no such
 * re-dispatch authority. Same rule for CREATE, renewal and add-ons.
 */
function achbotApiPaymentNeedsOperatorConfirmation(mysqli $db,array $pay,
    array $attempt): bool {
    if (($pay['state']??null)!=='processing') return false;
    if (($attempt['evidence_fulfillment_state']??null)==='dispatched') return true;
    $q=$db->prepare("SELECT id FROM service_effects
        WHERE pay_row_id=? AND effect_kind='create'
          AND state IN ('dispatching','reserved') LIMIT 1");
    $payId=(int)$pay['id'];$q->bind_param('i',$payId);$q->execute();
    $held=$q->get_result()->num_rows>0;$q->close();
    return $held;
}
function achbotApiPaymentOperatorToken(array $pay,array $attempt,
    array $proof,int $actor): string {
    if ($actor<=0 || (int)($pay['id']??0)<=0 ||
        ($proof['mode']??null)!=='settle')
        throw new RuntimeException('api_operator_confirmation_invalid');
    $bound=implode('|',[
        'original-service-confirm-v1',$actor,(int)$pay['id'],
        achbotPaymentEvidencePayFingerprint($pay),
        (string)($attempt['evidence_fulfillment_state']??''),
        (string)$proof['operation']
    ]);
    return substr(hash_hmac('sha256',$bound,achbotPaymentEvidenceKey()),0,20);
}
/**
 * Staff-only GET preview of a proven original edit whose PasarGuard Native
 * state has independently drifted. Preview never mutates payment or Native;
 * it uses the same original paid intent and recovery identity as execution.
 */
function achbotApiPaymentOperatorEditPreview(mysqli $db,array $pay,
    array $attempt,array $proof): ?array {
    $id=(int)($pay['id']??0);
    $state=(string)($attempt['evidence_fulfillment_state']??'');
    if ($id<=0 || ($pay['state']??null)!=='processing' ||
        (int)($pay['approved_by']??-1)!==0 ||
        $state!=='dispatched' ||
        !in_array((string)($attempt['provider']??''),['bank_transfer','gift_card'],true) ||
        ($proof['mode']??null)!=='settle') return null;
    require_once __DIR__.'/../Panel/ProviderRuntime.php';
    $server=(int)($pay['server_id']??0);
    // Only the provider implementing an exact-fenced recovery preview
    // currently supports this optional operator resolution.
    if ($server<=0 || achbotProviderType($db,$server)!=='pasarguard') return null;
    $payload=achbotPaymentEvidenceUnprotect(
        'fulfillment:'.$id.':'.$proof['operation'],
        (string)($attempt['evidence_fulfillment_payload']??''));
    if (!is_array($payload) ||
        ($payload['operation']??null)!==$proof['operation'] ||
        ($payload['pay_sha256']??null)!==achbotPaymentEvidencePayFingerprint($pay) ||
        !in_array((string)($payload['kind']??''),['renew','day','volume'],true) ||
        (int)($payload['server']??0)!==$server ||
        !is_array($payload['params']??null) ||
        (int)($payload['params']['pay_id']??0)!==$id)
        return null;
    $generation=$payload['operator_override_generation']??0;
    if (!is_int($generation) || $generation<0 || $generation>=PHP_INT_MAX)
        return null;
    $source=$payload;
    $source['preview_only']=true;
    $response=achbotProviderRecoverEdit($db,$server,$payload['params'],$source,true);
    if (($response->success??false)!==true ||
        ($response->preview??false)!==true ||
        !in_array($response->disposition??null,['drift','baseline','applied'],true) ||
        !is_string($response->entitlement_digest??null) ||
        preg_match('/^[a-f0-9]{64}$/D',$response->entitlement_digest)!==1)
        return null;
    return ['disposition'=>$response->disposition,
        'native_expire'=>$response->native_expire,
        'native_bytes'=>$response->native_bytes,
        'native_status'=>$response->native_status,
        'native_groups'=>$response->native_groups,
        'target_expire'=>$response->target_expire,
        'target_bytes'=>$response->target_bytes,
        'entitlement_digest'=>$response->entitlement_digest,
        'generation'=>$generation];
}
function achbotApiPaymentOperatorOverrideToken(array $pay,array $attempt,
    array $proof,int $actor,array $preview): string {
    $digest=$preview['entitlement_digest']??null;
    $generation=$preview['generation']??null;
    $expiry=$preview['target_expire']??null;
    $bytes=$preview['target_bytes']??null;
    if ($actor<=0 || !is_string($digest) ||
        preg_match('/^[a-f0-9]{64}$/D',$digest)!==1 ||
        !is_int($generation) || $generation<0 ||
        !is_int($expiry) || $expiry<=0 ||
        !is_int($bytes) || $bytes<0 ||
        (int)($pay['id']??0)<=0 || ($proof['mode']??null)!=='settle')
        throw new RuntimeException('api_operator_override_token_invalid');
    return substr(hash_hmac('sha256',implode('|',[
        'original-native-operator-override-v2',$actor,(int)$pay['id'],
        achbotPaymentEvidencePayFingerprint($pay),
        (string)($attempt['evidence_fulfillment_state']??''),
        (string)$proof['operation'],$digest,$expiry,$bytes,$generation,
    ]),achbotPaymentEvidenceKey()),0,20);
}
function achbotApiPaymentReady(mysqli $db): bool {
    return function_exists('achbotPaymentEvidenceSchemaReady') && achbotPaymentEvidenceSchemaReady($db);
}
/** Presence is independent of decryptability: a broken pin never becomes legacy. */
function achbotApiPaymentPinned(mysqli $db,int $id): bool {
    try {$q=$db->prepare('SELECT evidence_profile FROM payment_attempts WHERE pay_row_id=?');$q->bind_param('i',$id);$q->execute();$a=$q->get_result()->fetch_assoc();$q->close();return is_array($a)&&is_string($a['evidence_profile'])&&$a['evidence_profile']!=='';}catch(Throwable $e){return false;}
}
function achbotApiPaymentRow(mysqli $db,int $id,bool $locked=false): ?array {
    $q=$db->prepare('SELECT * FROM pays WHERE id=?'.($locked?' FOR UPDATE':''));
    $q->bind_param('i',$id);$q->execute();$row=$q->get_result()->fetch_assoc();$q->close();
    return $row?:null;
}
function achbotApiPaymentAttempt(mysqli $db,int $id,bool $locked=false): ?array {
    $q=$db->prepare('SELECT *,TIMESTAMPDIFF(SECOND,created_at,NOW()) AS age_seconds FROM payment_attempts WHERE pay_row_id=?'.($locked?' FOR UPDATE':''));
    $q->bind_param('i',$id);$q->execute();$row=$q->get_result()->fetch_assoc();$q->close();return $row?:null;
}
/** Called only during ORIGINAL issuance, inside its existing transaction. */
function achbotApiPaymentPinLocked(mysqli $db,array $pay,array $attempt): void {
    if (!function_exists('achbotPaymentEvidenceProfile')) return;
    $profile=achbotPaymentEvidenceProfile((string)$attempt['provider']);
    if ($profile===null) return;
    if (!achbotApiPaymentReady($db)) throw new RuntimeException('api_payment_schema_unavailable');
    $subject=[];$type=(string)$pay['type'];
    if (in_array($type,['BUY_SUB','RENEW_ACCOUNT','RENEW_SCONFIG'],true)) {
        $id=(int)$pay['plan_id'];
        $q=$db->prepare('SELECT server_id,inbound_id,days,volume,active FROM server_plans WHERE id=? FOR UPDATE');
        $q->bind_param('i',$id);$q->execute();$plan=$q->get_result()->fetch_assoc();$q->close();
        if (!$plan || ($type!=='RENEW_ACCOUNT' && (int)$plan['active']!==1) ||
            (int)$plan['inbound_id']!==0 ||
            ($type!=='RENEW_ACCOUNT' && (int)$plan['server_id']!==(int)$pay['server_id']))
            throw new RuntimeException('api_payment_subject_unavailable');
        $custom=$type==='BUY_SUB' && ((int)$pay['day']>0 || (int)$pay['volume']>0);
        $subject=['days'=>$custom?(int)$pay['day']:(int)$plan['days'],
            'volume'=>$custom?(int)$pay['volume']:(int)$plan['volume'],
            'count'=>max(1,(int)($pay['agent_count']??0))];
        if ($subject['days']<=0 || $subject['days']>36500 ||
            ($type==='BUY_SUB' ? $subject['volume']<=0 : $subject['volume']<0) ||
            $subject['volume']>intdiv(PHP_INT_MAX,1073741824) || $subject['count']>(int)$pay['price'])
            throw new RuntimeException('api_payment_entitlement_invalid');
    } elseif (preg_match('/^INCREASE_(DAY|VOLUME)_/', $type)) {
        $units=achbotPinnedAddonUnits($pay);
        if ($units===null) throw new RuntimeException('api_payment_units_invalid');
        $subject=['units'=>$units];
    } elseif ($type!=='INCREASE_WALLET') throw new RuntimeException('api_payment_subject_unsupported');
    $profile['subject']=$subject;
    if (!achbotPaymentEvidencePinLocked($db,$pay,$attempt,$profile))
        throw new RuntimeException('api_payment_pin_unconfirmed');
}
function achbotApiPaymentContext(mysqli $db,string $hash,int $actor=0): ?array {
    if (!achbotApiPaymentReady($db)) return null;
    try {
        $q=$db->prepare('SELECT * FROM pays WHERE hash_id=? LIMIT 1');$q->bind_param('s',$hash);
        $q->execute();$pay=$q->get_result()->fetch_assoc();$q->close();
        if (!$pay || ($actor>0 && (int)$pay['user_id']!==$actor)) return null;
        $attempt=achbotApiPaymentAttempt($db,(int)$pay['id']);
        $profile=$attempt?achbotPaymentEvidenceSnapshot($attempt):null;
        return $profile===null?null:['pay'=>$pay,'attempt'=>$attempt,'profile'=>$profile];
    } catch (Throwable $e) {return null;}
}
function achbotApiPaymentTerminal(array $pay): bool {
    return in_array((string)($pay['state']??''),['approved','paid','auto_cart_approved','paid_with_wallet'],true);
}
/** Original invoice callbacks, never inferred from an arbitrary submitted callback. */
function achbotApiPaymentReceiptPrefixes(array $pay,string $method='bank_transfer'): array {
    $t=(string)$pay['type'];$gift=$method==='gift_card';
    if ($t==='INCREASE_WALLET') return [$gift?'sendIncreaseWalletGiftReceipt':'sendIncreaseWalletReceipt','cancelIncreaseWalletReceipt'];
    if ($t==='RENEW_ACCOUNT') return [$gift?'sendRenewGiftReceipt':'sendRenewCartReceipt','cancelRenewCartReceipt'];
    if ($t==='RENEW_SCONFIG') return [$gift?'sendBuyGiftReceipt':'sendBuyCartReceipt','cancelBuyCartReceipt'];
    if (str_starts_with($t,'INCREASE_DAY_')) return [$gift?'sendIncreaseDayGiftReceipt':'sendIncreaseDayReceipt','cancelIncreaseDayReceipt'];
    if (str_starts_with($t,'INCREASE_VOLUME_')) return [$gift?'sendIncreaseVolumeGiftReceipt':'sendIncreaseVolumeReceipt','cancelIncreaseVolumeReceipt'];
    if ((int)$pay['day']>0 || (int)$pay['volume']>0) return [$gift?'sendCustomGiftReceipt':'sendCustomCartReceipt','cancelCustomCartReceipt'];
    return [$gift?'sendBuyGiftReceipt':'sendBuyCartReceipt','cancelBuyCartReceipt'];
}
/** Customer evidence submission follows the established manual journey. */
function achbotApiPaymentInitialStep(mysqli $db,string $hash,string $legacy): string {
    return $legacy;
}
function achbotApiPaymentKeyboard(array $context,?string $sendPrefix=null,?string $cancelPrefix=null): string {
    $pay=$context['pay'];$hash=(string)$pay['hash_id'];
    if (achbotApiPaymentTerminal($pay) || !in_array($pay['state'],['pending','have_sent'],true))
        return achbotPayUxStatusKeyboard($hash);
    if ($pay['state']==='have_sent') return achbotPayUxStatusKeyboard($hash);
    [$send,$cancel]=achbotApiPaymentReceiptPrefixes($pay,(string)$context['attempt']['provider']);
    $send=$sendPrefix??$send;$cancel=$cancelPrefix??$cancel;
    $gift=$context['attempt']['provider']==='gift_card';
    $rows=[[['text'=>$gift?achbotMessage('payment.api_payment.code'):achbotMessage('telegram.markup.receipt_and_cancel.button.ae15b282fd'),'callback_data'=>$send.$hash]]];
    if($gift){$intent=achbotManualIntentDecode((string)$context['attempt']['checkout_url'],'gift_card');$url=$intent['seller_url']??null;if(is_string($url)&&achbotManualGiftSellerUrlValid($url))$rows[0][]=['text'=>achbotMessage('telegram.markup.receipt_and_cancel.button.4595a70ab5'),'url'=>$url];}
    $rows[]=[['text'=>achbotMessage('telegram.markup.receipt_and_cancel.button.cc357a4c9f'),'callback_data'=>$cancel.$hash]];
    return inlineKeyboard($rows);
}
function achbotApiPaymentInvoiceText(array $c): string {
    $p=$c['pay'];
    if ($p['state']==='have_sent') return achbotManualAcknowledgement($p,(string)$c['attempt']['provider']);
    return buildCartToCartInfoMessage($GLOBALS['paymentKeys']??[],$GLOBALS['mainValues']??achbotMessages('legacy_messages'),(int)$p['price'],(int)$p['user_id'],(string)$c['attempt']['provider'],(string)$p['hash_id']);
}
/** Post-send source-real reference; first successful invoice response wins. */
function achbotApiPaymentTrackInvoice(mysqli $db,string $hash,int $actor,$response): void {
    $message=(int)($response->result->message_id??0);
    if ($message<=0 || $actor<=0 || !achbotApiPaymentReady($db)) return;
    try {
        $q=$db->prepare('UPDATE payment_attempts a JOIN pays p ON p.id=a.pay_row_id
            SET a.evidence_invoice_chat_id=?,a.evidence_invoice_message_id=?
            WHERE p.hash_id=? AND p.user_id=? AND a.evidence_profile IS NOT NULL
              AND a.evidence_invoice_message_id IS NULL');
        $q->bind_param('iisi',$actor,$message,$hash,$actor);$q->execute();$q->close();
        // If settlement won while Telegram was sending, immediately converge.
        $c=achbotApiPaymentContext($db,$hash,$actor);if ($c) achbotApiPaymentSyncInvoice($db,$c);
    } catch (Throwable $e) {error_log('achbot_api_invoice_reference_deferred');}
}
function achbotApiPaymentSyncInvoice(mysqli $db,array $c): bool {
    $id=(int)($c['pay']['id']??0);
    // A customer invoice edit is itself a bot-owned post-payment effect.
    // Serialize its whole Telegram request against original operator Archive,
    // just as bank/gift API settlement and Native dispatch already are.
    return achbotApiPaymentWithWorkerLock($db,$id,
        static fn():string=>achbotApiPaymentSyncInvoiceLocked($db,$c)
            ?'synchronized':'unchanged')==='synchronized';
}
function achbotApiPaymentSyncInvoiceLocked(mysqli $db,array $c): bool {
    $p=$c['pay'];$a=$c['attempt'];$id=(int)$p['id'];
    // A pre-lock read cannot authorize a post-Archive Telegram request.
    $fresh=achbotApiPaymentAttempt($db,$id);
    if (!$fresh || ($fresh['review_archived_at']??null)!==null ||
        ($a['review_archived_at']??null)!==null) return false;
    $chat=(int)($a['evidence_invoice_chat_id']??0);$message=(int)($a['evidence_invoice_message_id']??0);
    if ($chat!==(int)$p['user_id'] || $chat<=0 || $message<=0 || !function_exists('bot')) return false;
    if (achbotApiPaymentTerminal($p) || !in_array($p['state'],['pending','have_sent'],true))
        $body=achbotPayUxStatusText($p,$a);
    else {
        $body=achbotApiPaymentInvoiceText($c);
        if ($body==='') return false;
    }
    $keys=achbotApiPaymentKeyboard($c);$digest=hash('sha256',$body."\n".$keys);
    if (($a['evidence_invoice_digest']??null)===$digest) return true;
    try {
        $response=bot('editMessageText',['chat_id'=>$chat,'message_id'=>$message,'text'=>$body,'parse_mode'=>'HTML','reply_markup'=>$keys,'disable_web_page_preview'=>true]);
        if (!is_object($response) || ($response->ok??false)!==true) return false;
        // Compare current financial state after the asynchronous edit. A stale
        // pending edit may never stamp a terminal message as synchronized.
        $fresh=achbotApiPaymentRow($db,$id);
        if (!$fresh || $fresh['state']!==$p['state']) {
            $again=achbotApiPaymentContext($db,(string)$p['hash_id']);
            if ($again) return achbotApiPaymentSyncInvoice($db,$again);
            return false;
        }
        $q=$db->prepare('UPDATE payment_attempts SET evidence_invoice_digest=? WHERE pay_row_id=?');
        $q->bind_param('si',$digest,$id);$q->execute();$q->close();return true;
    } catch (Throwable $e) {error_log('achbot_api_invoice_edit_deferred pay_id='.$id);return false;}
}
/**
 * Persist an already acknowledged staff keyboard only while its financial
 * state is still current. Finance holds pays before payment_attempts; the
 * former UPDATE ... JOIN could lock those rows in the opposite order and
 * deadlock with an API/human settlement racing the staff projection.
 *
 * Only local SQL belongs in this transaction. Telegram has already been
 * called; a failed digest stamp is left retryable by normal projection.
 */
function achbotApiPaymentStampStaffDigest(mysqli $db,int $id,string $state,?int $actor,string $digest): bool {
    $started=false;$phase='begin';
    try {
        achbotPaymentEvidenceRequireAutocommit($db);
        if (!$db->begin_transaction()) throw new RuntimeException('api_staff_digest_begin_failed');
        $started=true;
        $phase='lock_payment';
        $q=$db->prepare('SELECT state,approved_by FROM pays WHERE id=? FOR UPDATE');
        $q->bind_param('i',$id);$q->execute();$current=$q->get_result()->fetch_assoc();$q->close();
        $currentActor=$current && $current['approved_by']!==null?(int)$current['approved_by']:null;
        $matches=$current && $current['state']===$state && $currentActor===$actor;
        if ($matches) {
            $phase='stamp_attempt';
            $q=$db->prepare('UPDATE payment_attempts SET evidence_staff_digest=? WHERE pay_row_id=?');
            $q->bind_param('si',$digest,$id);$q->execute();$q->close();
        }
        $phase='commit';
        if (!$db->commit()) throw new RuntimeException('api_staff_digest_commit_unconfirmed');
        return (bool)$matches;
    } catch (Throwable $e) {
        if ($started) {
            try {$db->rollback();} catch (Throwable $ignored) {}
        }
        error_log('achbot_api_staff_digest_deferred pay_id='.$id.
            ' phase='.$phase.' exception='.get_class($e).' code='.(int)$e->getCode());
        return false;
    }
}

/** No general report reaches Operator/Support; existing copies still converge. */
function achbotApiPaymentAck($response): bool {
    return $response===true || (is_object($response)&&($response->ok??false)===true) || (is_array($response)&&($response['ok']??false)===true);
}
function achbotApiPaymentNotice(mysqli $db,array $pay,string $status,bool $report=true): void {
    $id=(int)$pay['id'];$incoming=$pay;$fresh=achbotApiPaymentRow($db,$id);if(!$fresh)return;
    $incomingRefs=json_decode((string)($incoming['admins_messages']??''),true);$incomingRefs=is_array($incomingRefs)?$incomingRefs:[];
    $incomingRefs[]=['chat_id'=>$incoming['chat_id']??0,'message_id'=>$incoming['message_id']??0];
    $pay=$fresh;$storedRefs=json_decode((string)($pay['admins_messages']??''),true);$storedRefs=is_array($storedRefs)?$storedRefs:[];
    $pay['admins_messages']=json_encode(array_values(array_unique(array_merge($storedRefs,$incomingRefs),SORT_REGULAR)));
    $hash=(string)$pay['hash_id'];$a=achbotApiPaymentAttempt($db,$id);if(!$a)return;
    $proof=achbotPaymentEvidenceGetProof($db,$id);$pending=$proof?null:achbotPaymentEvidencePending($db,$id);
    $evidence=$proof['operation']??($pending['operation']??('invoice-'.$id));
    if(achbotApiPaymentTerminal($pay) && in_array($status,['used','revoked','expired','mismatch'],true))$status='settled';
    $invalidCode=in_array($status,['used','revoked','expired','mismatch'],true);
    $status=in_array($status,['confirmed','settled','review','uncertain','evidence_only','used','revoked','expired','mismatch'],true)?$status:'review';
    $label=$invalidCode?achbotMessage('manual_payment.api_payment.code_'.$status):achbotMessage('manual_payment.api_payment.status_'.$status);
    $archived=($a['review_archived_at']??null)!==null;
    require_once __DIR__.'/GatewayClient.php';
    $actionable=!$archived && achbotGatewayAttentionCaseOpen($db,$id);
    $automatic=achbotApiPaymentTerminal($pay) && (int)($pay['approved_by']??-1)===0 && ($proof['mode']??'')==='settle';
    if($automatic)$label=achbotMessage('manual_payment.api_payment.auto_approved');
    if($pay['state']==='declined')$label=achbotMessage('manual_payment.manual_receipts.button.51fdc91d69',['from_id'=>(int)$pay['approved_by']]);
    if($archived)$label=achbotMessage('automatic_gateway_review.status_archived');
    $presentation=achbotApiPaymentPresentation($db,$pay,$a);
    $amount=achbotPaymentEvidenceIntegralQuote($a['quote_amount'])??(string)$a['quote_amount'];
    $body=achbotMessageHtml('manual_payment.api_payment.report',[
        'reference'=>$hash.' (#'.$id.')','userId'=>(string)$pay['user_id'],'amount'=>$amount,'unit'=>trim(achbotMessage('bot.bot.webhook_ingress_authenticated.text.2cc98f7f7c')),
        'method'=>achbotManualLabel((string)$a['provider']),'evidence'=>$evidence,
        'subject'=>payTypeToDescription((string)$pay['type']).
            ($presentation['details']==='' && $presentation['subject']!==payTypeToDescription((string)$pay['type'])
                ?' | '.$presentation['subject']:''),'status'=>$label]);
    $body=achbotApiPaymentRichReport($pay,$body,$presentation);
    // Review details remain available from the original evidence/report
    // presenter. Owner opens the review workbench voluntarily: a background
    // provider poll must not send additional standalone admin messages.
    // Original receipt keyboards are still edited in place below.
    $refs=json_decode((string)($pay['admins_messages']??''),true);$refs=is_array($refs)?$refs:[];
    $refs[]=['chat_id'=>$pay['chat_id']??0,'message_id'=>$pay['message_id']??0];$seen=[];
    $keys=inlineKeyboard([[['text'=>$label,'callback_data'=>'noop_'.$hash]]]);
    if(achbotApiPaymentTerminal($pay) && !$archived)
        $keys=achbotApiPaymentFinalKeyboard($pay,$label,$automatic,$presentation);
    if($invalidCode && $pay['state']==='have_sent' && !$archived){
        $rows=json_decode(achbotApiPaymentReviewKeyboard($pay),true)['inline_keyboard'];
        $rows[]=[['text'=>$label,'callback_data'=>'noop_'.$hash]];$keys=inlineKeyboard($rows);
    }
    if ($actionable) {
        $rows=json_decode($keys,true)['inline_keyboard']??[];
        $open=['text'=>achbotMessage('automatic_gateway_review.open_case'),
            'callback_data'=>'attentionCase_'.$id];
        if (!achbotApiPaymentTerminal($pay)) {
            // A failed automatic API receipt must not keep a dead "needs
            // operator review" noop button. Keep genuine manual Approve /
            // Reject controls when still eligible and add the one real route.
            $rows=$invalidCode && $pay['state']==='have_sent'
                ?array_values(array_filter($rows,static function(array $line):bool {
                    foreach ($line as $button)
                        if (!str_starts_with((string)($button['callback_data']??''),'noop_'))
                            return true;
                    return false;
                })):[];
        }
        $rows[]=[$open];
        $keys=inlineKeyboard($rows);
    }
    $staffDigest=hash('sha256',$keys.json_encode($refs));
    if((achbotApiPaymentTerminal($pay) || in_array($pay['state'],['processing','declined'],true) ||
        ($invalidCode && $pay['state']==='have_sent') || $actionable || $archived) &&
        ($a['evidence_staff_digest']??null)!==$staffDigest) {
        $ack=true;
        foreach($refs as $ref) {
            $chat=(int)($ref['chat_id']??0);$msg=(int)($ref['message_id']??0);$key=$chat.':'.$msg;
            if($msg<=0||isset($seen[$key]))continue;
            $seen[$key]=true;$complete=true;
            // API projection uses the existing bounded current read. Legacy
            // stored-receipt callers retain their default locking semantics.
            $allowed=achbotAdminStoredRecipientAllowed($db,$chat,(int)($GLOBALS['admin']??0),true,$complete);
            if(!$complete)$ack=false;
            if(!$allowed)continue;try{if(!achbotApiPaymentAck(editKeys($keys,$msg,$chat)))$ack=false;}catch(Throwable $e){$ack=false;}
        }
        // Telegram may return after a real human/API winner committed. Never
        // acknowledge a stale action keyboard; converge every stored copy.
        $latest=achbotApiPaymentRow($db,$id);
        if(!$latest)return;
        if($latest['state']!==$pay['state'] || ($latest['approved_by']??null)!==($pay['approved_by']??null)){
            $latest['admins_messages']=json_encode($refs);
            achbotApiPaymentNotice($db,$latest,achbotApiPaymentTerminal($latest)?'settled':'review',false);return;
        }
        if($ack) achbotApiPaymentStampStaffDigest($db,$id,(string)$pay['state'],
            $pay['approved_by']===null?null:(int)$pay['approved_by'],$staffDigest);
    }
    $c=achbotApiPaymentContext($db,$hash);if($c)achbotApiPaymentSyncInvoice($db,$c);
}
/** Valid only with a genuine recoverable typed-code review, never a fake photo. */
function achbotApiPaymentTypedReviewLocked(mysqli $db,array $pay): bool {
    return function_exists('achbotPaymentEvidenceHasTypedReviewLocked') && achbotPaymentEvidenceHasTypedReviewLocked($db,$pay);
}
/** Called under the actual locked pays claim, also used by CREATE ownership. */
function achbotApiPaymentProcessingProofLocked(mysqli $db,int $id): bool {
    if (!achbotApiPaymentReady($db)) return false;
    $pay=achbotApiPaymentRow($db,$id,true);
    if (!$pay || $pay['state']!=='processing' || (int)($pay['approved_by']??-1)!==0) return false;
    $proof=achbotPaymentEvidenceGetProof($db,$id);
    return $proof!==null && ($proof['mode']??'')==='settle' && achbotPaymentEvidenceValidateProofLocked($db,$pay,$proof);
}
/** Short preflight only. Never retain SQL locks across a provider request. */
function achbotApiPaymentValidateProof(mysqli $db,array $pay,array $proof): bool {
    $tx=$db->query('SELECT @@in_transaction');$inside=$tx&&(int)$tx->fetch_row()[0]===1;
    if($inside)return achbotPaymentEvidenceValidateProofLocked($db,$pay,$proof);
    try {
        if(!$db->begin_transaction())return false;
        $fresh=achbotApiPaymentRow($db,(int)$pay['id'],true);
        $valid=$fresh && achbotPaymentEvidencePayFingerprint($fresh)===achbotPaymentEvidencePayFingerprint($pay) && achbotPaymentEvidenceValidateProofLocked($db,$fresh,$proof);
        $db->rollback();return (bool)$valid;
    }catch(Throwable $e){try{$db->rollback();}catch(Throwable $ignored){}return false;}
}
/** Canonical purchase dimensions read from the ORIGINAL encrypted issuance pin. */
function achbotApiPaymentPurchaseIntent(mysqli $db,array $pay,array $proof): ?array {
    if (!achbotApiPaymentValidateProof($db,$pay,$proof) || ($proof['mode']??'')!=='settle') return null;
    $a=achbotApiPaymentAttempt($db,(int)$pay['id'],true);$p=$a?achbotPaymentEvidenceSnapshot($a):null;$s=$p['subject']??null;
    if(($a['provider']??'')==='bank_transfer') {
        $id=(int)$pay['id'];$q=$db->prepare('SELECT * FROM bank_buy_invoice_claims WHERE pay_row_id=? FOR UPDATE');$q->bind_param('i',$id);$q->execute();$original=$q->get_result()->fetch_assoc();$q->close();
        if(!achbotBankBuyClaimMatches($pay,$original)||!achbotBankBuyAttemptMatches($pay,$a,$original))return null;
    }

    if (!is_array($s) || (int)($s['days']??0)<=0 || (int)($s['volume']??0)<=0 || (int)($s['count']??0)<=0) return null;
    return ['invoice_sha256'=>achbotBankBuyInvoiceFingerprint($pay),'account_count'=>(int)$s['count'],
        'amount'=>(int)$pay['price'],'server_id'=>(int)$pay['server_id'],'plan_id'=>(int)$pay['plan_id'],
        'entitled_days'=>(int)$s['days'],'entitled_volume'=>(int)$s['volume']];
}
/** SQL-only snapshot of the exact local quote, target and native incarnation.
 * The common provider validators below perform storage reads only. In
 * particular, achbotPgQuote/achbotSupportsPaymentSubject are NOT called here:
 * both may perform authenticated native HTTP even when their lock flag is true.
 */
function achbotApiPaymentLocalSubjectContext(mysqli $db,array $pay,bool $locked=false): ?string {
    try {
        $type=(string)($pay['type']??'');
        $context=['pay'=>achbotPaymentEvidencePayFingerprint($pay),
            'provider_quote'=>(string)($pay['provider_snapshot']??'')];
        if($type==='INCREASE_WALLET')return hash('sha256',json_encode($context,JSON_THROW_ON_ERROR));
        $sid=achbotProviderPaymentSubjectServer($db,$pay,$locked);
        if($sid===null)return null;
        $lock=$locked?' FOR UPDATE':'';
        $read=static function(string $table,int $id) use($db,$lock):?array {
            // All identifiers are fixed by this function, never user input.
            $q=$db->prepare('SELECT * FROM '.$table.' WHERE id=?'.$lock);
            $q->bind_param('i',$id);$q->execute();$row=$q->get_result()->fetch_assoc();$q->close();return $row?:null;
        };
        $server=$read('server_config',$sid);$info=$read('server_info',$sid);
        $pid=(int)($pay['plan_id']??0);$uid=(int)($pay['user_id']??0);
        $plan=$pid>0?$read('server_plans',$pid):null;$order=null;$oid=0;
        if(!$server||!$info||!achbotSupportsAccount($server['type'],0))return null;
        // A committed processing claim is recovery evidence, NOT a new
        // authorization to charge or mutate an account. New claims must obey
        // the CURRENT locked server policy even if remote preflight passed.
        $alreadyClaimed=($pay['state']??'')==='processing' &&
            (int)($pay['approved_by']??-1)===0;
        if (!$alreadyClaimed && ((int)$info['active']!==1 ||
            ($type==='BUY_SUB' ? (int)$info['state']!==1 :
                !in_array((int)$info['state'],[1,2],true))))return null;
        if($type==='BUY_SUB') {
            if($pid<=0||!achbotSupportsPlan($db,$pid,0,$sid,$locked))return null;
        } elseif($type==='RENEW_SCONFIG') {
            $source=json_decode((string)($pay['description']??''),true);
            if($pid<=0||$uid<=0||(int)($pay['volume']??-1)!==0||
                !is_array($source)||($source['marzban']??null)!==true||
                empty($source['remark'])||(int)($source['server_id']??0)!==$sid||
                !achbotSupportsMarzbanPlan($db,$pid,0,$sid,$locked))return null;
        } elseif($type==='RENEW_ACCOUNT'||
            preg_match('/^INCREASE_(DAY|VOLUME)_([1-9][0-9]*)_[1-9][0-9]*$/D',$type,$m)) {
            $oid=$type==='RENEW_ACCOUNT'?(int)($pay['order_id']??0):(int)$m[2];
            if($oid<=0||($type==='RENEW_ACCOUNT'&&$pid<=0)||
                (!empty($pay['order_id'])&&(int)$pay['order_id']!==$oid)||
                ($type!=='RENEW_ACCOUNT'&&achbotPinnedAddonUnits($pay)===null)||
                !achbotSupportsOrder($db,$oid,$pid>0?$pid:null,$uid>0?$uid:null,$locked))return null;
            $order=$read('orders_list',$oid);if(!$order)return null;
            if ($type!=='RENEW_ACCOUNT') {
                require_once __DIR__.'/../Plans/Addons.php';
                if (achbotAddonSchema($db) && !achbotAddonPayLocal($db,$pay,$order)) return null;
                $context['addon_scope_snapshot']=(string)($pay['addon_scope_snapshot']??'');
            }
        } else return null;
        $context['server']=$server;
        // Capacity and display metadata are not provider/quote identity.
        // Bind preflight to the actual commerce policy; a state flip after
        // native HTTP invalidates this fingerprint at the final SQL claim.
        $context['server_identity']=array_intersect_key($info,array_flip(
            ['id','panel_group_id','provider_group_ids','active','state']));
        $context['plan']=$plan;
        $context['order']=$order;
        if($server['type']==='pasarguard') {
            achbotPgLoad();
            $snap=json_decode((string)($pay['provider_snapshot']??''),true,64,JSON_THROW_ON_ERROR);
            $target=achbotProviderCanonicalSnapshot($snap);
            $panel=achbotProviderPanel($db,$target['panel_id'],$locked);
            if($panel['id']!==(int)$server['provider_connection_id']||
                $panel['instance_key']!==$server['provider_instance_key']||
                $target['instance_key']!==$panel['instance_key']||
                $target['endpoint_sha256']!==hash('sha256',rtrim($panel['api_url'],'/'))||
                rtrim($server['panel_url'],'/')!==rtrim($panel['api_url'],'/'))return null;
            $context['panel']=$panel;
            if($type!=='BUY_SUB') {
                $ctx=achbotPgOrderContext($db,$oid,$locked);
                foreach($ctx['identity'] as $key=>$value)
                    if((string)($snap[$key]??'')!==(string)$value)return null;
                $history=achbotPgMutationHistory($ctx['account']);
                if(count($history['consumed_keys'])>=4096&&
                    !in_array('pay:'.(int)$pay['id'],$history['consumed_keys'],true))return null;
                $context['native_identity']=$ctx['identity'];
                $context['account']=$ctx['account'];
            }
        }
        return hash('sha256',json_encode($context,JSON_THROW_ON_ERROR));
    }catch(Throwable $e){return null;}
}
/** Authenticated provider/native eligibility is captured without SQL locks.
 * Comparing both sides of HTTP prevents a mixed observation of two local
 * identities from authorizing a later financial claim.
 */
function achbotApiPaymentSubjectPreflight(mysqli $db,array $pay): array {
    $out=['valid'=>false,'fingerprint'=>achbotPaymentEvidencePayFingerprint($pay),'context'=>null];
    try {
        if((int)$db->query('SELECT @@in_transaction')->fetch_row()[0]!==0)return $out;
        $before=achbotApiPaymentLocalSubjectContext($db,$pay);
        if($before===null||!achbotSupportsPaymentSubject($db,$pay,false))return $out;
        $fresh=achbotApiPaymentRow($db,(int)$pay['id']);
        if(!$fresh||!hash_equals($out['fingerprint'],achbotPaymentEvidencePayFingerprint($fresh)))return $out;
        $after=achbotApiPaymentLocalSubjectContext($db,$fresh);
        if($after===null||!hash_equals($before,$after))return $out;
        return ['valid'=>true,'fingerprint'=>$out['fingerprint'],'context'=>$after];
    }catch(Throwable $e){return $out;}
}
/** Current locked local context only; never native HTTP. */
function achbotApiPaymentLocalSubjectAllowed(mysqli $db,array $pay,?array $seen=null): bool {
    $context=achbotApiPaymentLocalSubjectContext($db,$pay,true);
    return $context!==null&&($seen===null||(($seen['valid']??false)===true&&
        hash_equals((string)$seen['fingerprint'],achbotPaymentEvidencePayFingerprint($pay))&&
        hash_equals((string)$seen['context'],$context)));
}
/** Fresh native eligibility for a pinned API invoice's genuine human review. */
function achbotApiPaymentManualAddonPreflight(mysqli $db,string $hash): ?array {
    $pay=dbFetchPayByHash($db,$hash);
    if(!$pay||!achbotApiPaymentPinned($db,(int)$pay['id']))return null;
    if($pay['state']!=='have_sent')return ['valid'=>false];
    return achbotApiPaymentSubjectPreflight($db,$pay);
}
function achbotApiPaymentManualAddonAllowedLocked(mysqli $db,?array $pay,?array $seen): bool {
    if($pay===null)return false;
    if($seen===null)return achbotSupportsPaymentSubject($db,$pay,true);
    return achbotApiPaymentLocalSubjectAllowed($db,$pay,$seen);
}
/** Exclusive real local financial claim. No provider or Telegram HTTP inside. */
function achbotApiPaymentSettle(mysqli $db,int $id,array $proof,
    int $operator=0,int $primaryOwner=0,?array $operatorOverride=null): string {
    return achbotApiPaymentWithWorkerLock($db,$id,
        static fn():string=>achbotApiPaymentSettleLocked(
            $db,$id,$proof,$operator,$primaryOwner,$operatorOverride));
}
function achbotApiPaymentSettleLocked(mysqli $db,int $id,array $proof,
    int $operator=0,int $primaryOwner=0,?array $operatorOverride=null): string {
    require_once __DIR__.'/ServiceEffects.php';require_once __DIR__.'/BankPurchase.php';
    require_once __DIR__.'/DeliveryLedger.php';
    $before=achbotApiPaymentRow($db,$id);if(!$before)return 'review';
    $beforeAttempt=achbotApiPaymentAttempt($db,$id);
    if (!$beforeAttempt || ($beforeAttempt['review_archived_at']??null)!==null)
        return 'archived';
    if (achbotApiPaymentTerminal($before)) {
        // The original invoice is final: only CAS-protected PENDING deliveries
        // may continue. Uncertain sends never auto-resend or replay money.
        achbotDeliveryDispatchPending($db,$id);
        achbotDeliverySyncAggregate($db,$id);
        return 'settled';
    }
    if (!achbotDeliverySchemaReady($db)) return 'review';
    $attempt=achbotApiPaymentAttempt($db,$id);
    // Already verified native effects recover solely from their sealed result.
    $started=$before['state']==='processing'&&(int)($before['approved_by']??-1)===0&&
        in_array((string)($attempt['evidence_fulfillment_state']??''),
            ['dispatched','verified'],true);
    // Native state may be held while the original payment and provider proof
    // are already pinned. Do not re-run pre-claim provider eligibility on an
    // account whose in-flight journal deliberately refuses new mutations.
    $seen=$started?null:achbotApiPaymentSubjectPreflight($db,$before);
    if($seen!==null&&empty($seen['valid']))return 'review';
    try {
        if (!$db->begin_transaction()) return 'review';
        $pay=achbotApiPaymentRow($db,$id,true);
        if (!$pay) {$db->rollback();return 'review';}
        if (achbotApiPaymentTerminal($pay)) {$db->rollback();return 'settled';}
        $resuming=$pay['state']==='processing' && (int)($pay['approved_by']??-1)===0;
        if ($operator>0) {
            require_once __DIR__.'/../Admin/Access.php';
            if ($primaryOwner<=0 ||
                !achbotAdminCanLocked($db,$operator,$primaryOwner,'payments')) {
                $db->rollback();return 'review';
            }
        }
        $pinnedContinuation=$started && $resuming;
        if ((!in_array($pay['state'],['pending','have_sent'],true) && !$resuming) || (string)($pay['payid']??'')!=='' ||
            ($proof['mode']??'')!=='settle' || !achbotPaymentEvidenceValidateProofLocked($db,$pay,$proof) ||
            (!$pinnedContinuation && !achbotApiPaymentLocalSubjectAllowed($db,$pay,$seen))) {
            $db->rollback();return 'review';
        }
        $type=(string)$pay['type'];
        if (!$resuming && $type==='BUY_SUB') {
            $intent=achbotApiPaymentPurchaseIntent($db,$pay,$proof);if(!$intent)throw new RuntimeException('api_purchase_pin_invalid');
            achbotEffectRequireUniqueLine($db);
            $count=achbotManualBuyReserveCapacityLocked($db,$pay,(int)$pay['day']>0 || (int)$pay['volume']>0);
            if($count!==$intent['account_count'])throw new RuntimeException('api_purchase_count_drift');
            achbotEffectRecordManualCapacityLocked($db,$pay,$count,$proof);
        } elseif (!$resuming && $type==='INCREASE_WALLET') {
            if (!addToWallet($db,(int)$pay['user_id'],(int)$pay['price'])) throw new RuntimeException('api_wallet_credit_unconfirmed');
        } elseif (!$resuming && !in_array($type,['RENEW_ACCOUNT','RENEW_SCONFIG'],true) &&
            !preg_match('/^INCREASE_(DAY|VOLUME)_[1-9][0-9]*_[1-9][0-9]*$/D',$type)) throw new RuntimeException('api_subject_unsupported');
        if (!$resuming && $type==='INCREASE_WALLET') {
            // A credit without an atomic customer text-intent is forbidden.
            $a=achbotApiPaymentAttempt($db,$id,true);
            $text=achbotApiPaymentManualCustomerText($db,$pay);
            if (!$a || $text===null) throw new RuntimeException('api_wallet_delivery_intent_missing');
            achbotDeliveryInsertTextLocked($db,$pay,$a,$text,'HTML',0,0,
                hash('sha256','wallet:'.(int)$a['id']));
        }
        if (!$resuming) {
        $state=$type==='INCREASE_WALLET'?'approved':'processing';$before=(string)$pay['state'];
        $q=$db->prepare('UPDATE pays SET state=?,approved_by=0 WHERE id=? AND state=? AND (payid IS NULL OR payid=\'\')');
        $q->bind_param('sis',$state,$id,$before);if(!$q->execute() || $q->affected_rows!==1)throw new RuntimeException('api_claim_unconfirmed');$q->close();
        if ($type!=='INCREASE_WALLET') {
            $f=$db->prepare("UPDATE payment_attempts SET evidence_fulfillment_state='reserved' WHERE pay_row_id=? AND evidence_fulfillment_state IS NULL");$f->bind_param('i',$id);$f->execute();$f->close();
        }
        }
        if (!$db->commit()) throw new RuntimeException('api_claim_commit_unknown');
    } catch(Throwable $e) {try{$db->rollback();}catch(Throwable $ignored){}error_log('achbot_api_claim_review pay_id='.$id);return 'review';}
    try {
        if ($type==='BUY_SUB') {
            // Continue each original ordinal through the same pinned native
            // observation used by manual BUY_SUB. A prior dispatch is GET-only
            // and can never trigger a second POST; a never-started ordinal
            // retains its original single CREATE claim and capacity hold.
            $delivery=achbotAutoBankProvisionBuySub($db,$pay,
                $GLOBALS['botState']??[],$GLOBALS['buttonValues']??[],[],
                false,(string)($GLOBALS['botUrl']??''),$proof,null,
                $operator,$primaryOwner);
            if (!$delivery['committed']) return 'review';
            achbotReferralAfterCompletedPurchase($db,(int)$pay['user_id'],$id,(int)($GLOBALS['admin']??0),$GLOBALS['botState']??[]);
        } elseif($type!=='INCREASE_WALLET') {
            if (!achbotApiPaymentFulfillEdit($db,$pay,$proof,$operator>0,
                $operatorOverride,$operator,$primaryOwner)) return 'review';
        }
        $fresh=achbotApiPaymentRow($db,$id);if(!$fresh || !achbotApiPaymentTerminal($fresh))return 'review';
        // Every per-ordinal intent was written by the SAME terminal COMMIT.
        // Only pending lines may be dispatched automatically. ACK/unknown is
        // per line; a split-bulk failure cannot be erased by one global flag.
        achbotDeliveryDispatchPending($db,$id);
        $aggregate=achbotDeliveryAggregate($db,$id);
        if (!$aggregate || (int)$aggregate['total']===0) return 'review';
        $deliveryAcknowledged=(int)$aggregate['ack']===(int)$aggregate['total'];
        achbotDeliverySyncAggregate($db,$id);
        if ($operator>0)
            error_log('achbot_operator_service_completed pay_id='.$id.
                ' actor='.$operator.' type='.$type.
                ' delivery='.($deliveryAcknowledged?'acknowledged':'unconfirmed'));
        achbotApiPaymentNotice($db,$fresh,$deliveryAcknowledged?'confirmed':'settled');
        // This specific payment is already durably final, independent of
        // Telegram success. Converge its existing receipt messages NOW rather
        // than queueing behind historical invoices. Errors stay retryable in
        // payment_message_refs; no new SMS, charge, Native or customer packet.
        achbotPayUxSyncMessages($db,$id);
        return $deliveryAcknowledged?'confirmed':'settled';
    } catch(Throwable $e) {
        $code=(string)$e->getMessage();
        if (preg_match('/\A[a-z][a-z0-9_]{2,100}\z/D',$code)!==1)
            $code='internal_exception';
        error_log('achbot_api_effect_review pay_id='.$id.
            ' actor='.$operator.' code='.$code.' class='.get_class($e).
            ' at='.basename($e->getFile()).':'.$e->getLine());
        $fresh=achbotApiPaymentRow($db,$id);
        if($fresh)achbotApiPaymentNotice($db,$fresh,'review');
        return 'review';
    }
}
/** Reuse actual panel edit and exact local finisher, with pinned quantities. */
function achbotApiPaymentStoreFulfillment(mysqli $db,int $id,array $proof,string $state,array $payload,string $expected,?array $seen=null): bool {
    try {
        if(!$db->begin_transaction())return false;$pay=achbotApiPaymentRow($db,$id,true);
        if(!$pay||$pay['state']!=='processing'||!achbotPaymentEvidenceValidateProofLocked($db,$pay,$proof)||
            ($seen!==null&&!achbotApiPaymentLocalSubjectAllowed($db,$pay,$seen))){$db->rollback();return false;}
        $payload['pay_sha256']=achbotPaymentEvidencePayFingerprint($pay);$payload['operation']=$proof['operation'];
        $sealed=achbotPaymentEvidenceProtect('fulfillment:'.$id.':'.$proof['operation'],$payload);
        $q=$db->prepare('UPDATE payment_attempts SET evidence_fulfillment_state=?,evidence_fulfillment_payload=? WHERE pay_row_id=? AND evidence_fulfillment_state=?');
        $q->bind_param('ssis',$state,$sealed,$id,$expected);$q->execute();$ok=$q->affected_rows===1;$q->close();
        if(!$ok||!$db->commit())throw new RuntimeException('api_fulfillment_commit_unknown');return true;
    }catch(Throwable $e){try{$db->rollback();}catch(Throwable $ignored){}return false;}
}
/**
 * Only original claimed additive invoices can store a Native baseline. The
 * provider calls this BEFORE any side effect, so a present capture_required
 * marker with no captured intent proves this new-code attempt never wrote.
 * Historical dispatched rows have no such guarantee and remain reviewable.
 */
function achbotApiPaymentNativeIntentCapture(mysqli $db,int $id,array $proof,
    array &$payload): callable {
    return static function(array $native) use ($db,$id,$proof,&$payload): bool {
        if (isset($payload['native_intent']) ||
            ($payload['capture_required']??null)!==true ||
            ($native['version']??null)!==1)
            return false;
        $prepared=$payload;
        $prepared['native_intent']=$native;
        if (!achbotApiPaymentStoreFulfillment($db,$id,$proof,
            'dispatched',$prepared,'dispatched')) return false;
        $payload=$prepared;
        return true;
    };
}
function achbotApiPaymentFulfillEdit(mysqli $db,array $pay,array $proof,
    bool $operator=false,?array $operatorOverride=null,
    int $actor=0,int $primaryOwner=0): bool {
    $id=(int)$pay['id'];$type=(string)$pay['type'];$a=achbotApiPaymentAttempt($db,$id);$profile=$a?achbotPaymentEvidenceSnapshot($a):null;$subject=$profile['subject']??[];
    $state=(string)($a['evidence_fulfillment_state']??'');$payload=null;
    if($state==='verified') {
        $payload=achbotPaymentEvidenceUnprotect('fulfillment:'.$id.':'.$proof['operation'],(string)$a['evidence_fulfillment_payload']);
        if(!$payload||($payload['operation']??null)!==$proof['operation']||($payload['pay_sha256']??null)!==achbotPaymentEvidencePayFingerprint($pay))return false;
    } elseif($state==='reserved') {
        $kind=$type==='RENEW_SCONFIG'?'standard':($type==='RENEW_ACCOUNT'?'renew':(str_starts_with($type,'INCREASE_DAY_')?'day':'volume'));
        $oid=(int)($pay['order_id']??0);$server=(int)$pay['server_id'];$units=0;$order=null;
        if($kind==='standard'){$desc=json_decode((string)$pay['description'],true);$remark=(string)($desc['remark']??'');}
        else {
            if($kind==='day'||$kind==='volume'){preg_match('/^INCREASE_(?:DAY|VOLUME)_([1-9][0-9]*)_/',$type,$m);$oid=(int)($m[1]??0);$units=(int)($subject['units']??0);if($units!==achbotPinnedAddonUnits($pay))return false;}
            $q=$db->prepare('SELECT * FROM orders_list WHERE id=?');$q->bind_param('i',$oid);$q->execute();$order=$q->get_result()->fetch_assoc();$q->close();if(!$order)return false;$remark=(string)$order['remark'];$server=(int)$order['server_id'];
        }
        $seen=achbotApiPaymentSubjectPreflight($db,$pay);
        if(empty($seen['valid'])||!achbotAutoBankEditPreflight($db,$pay,$kind,$server,$oid,$remark,$units))return false;
        $params=['pay_id'=>$id,'remark'=>$remark];
        if($kind==='day')$params['plus_day']=$units;
        elseif($kind==='volume')$params['plus_volume']=$units;
        else {$params['days']=(int)($subject['days']??0);$params['volume']=(int)($subject['volume']??-1);if($params['days']<=0||$params['volume']<0)return false;}
        $payload=['kind'=>$kind,'server'=>$server,'order'=>$oid,'remark'=>$remark,'units'=>$units,'expiry'=>$order?(int)$order['expire_date']:null,'params'=>$params];
        // Initial RESERVED->dispatched API fulfillment has the SAME
        // original pay:ID/native-fence finality requirement as recovery.
        // Persist this before HTTP so a lost response can be read-first
        // reconciled without releasing the account to competing operations.
        $deferNativeFence=achbotProviderType($db,$server)==='pasarguard' &&
            in_array($kind,['renew','day','volume'],true);
        if ($deferNativeFence) $payload['native_fence_deferred']=true;
        if (in_array($kind,['day','volume'],true))
            $payload['capture_required']=true;
        if(!achbotApiPaymentStoreFulfillment($db,$id,$proof,'dispatched',$payload,'reserved',$seen))return false;
        // Only the winner of the persisted dispatch transition may call the
        // native adapter. A crash/timeout here stays reviewable, never replayed.
        $dispatch=$params;
        if ($deferNativeFence) $dispatch['_api_defer_account_finality']=true;
        if (($payload['capture_required']??false)===true)
            $dispatch['_paid_intent_capture']=achbotApiPaymentNativeIntentCapture(
                $db,$id,$proof,$payload);
        $response=achbotApplyServiceEdit($db,$server,$dispatch);
        if(!is_object($response)||($response->success??false)!==true||($response->verified??false)!==true)return false;
        if ($deferNativeFence && (
            ($response->native_fence_deferred??null)!==true ||
            !is_array($response->observed_group_ids??null) ||
            !is_int($response->applied_expire??null) ||
            !is_int($response->applied_bytes??null))) return false;
        $payload['result']=['success'=>true,'verified'=>true,'applied_expire'=>$response->applied_expire??null,'applied_bytes'=>$response->applied_bytes??null];
        if ($deferNativeFence)
            $payload['result']['observed_group_ids']=$response->observed_group_ids;
        if(!achbotApiPaymentStoreFulfillment($db,$id,$proof,'verified',$payload,'dispatched'))return false;
    } elseif($state==='dispatched') {
        // The old attempt already crossed its irreversible claim boundary.
        // Resume its sealed original payload, never claim another payment.
        $payload=achbotPaymentEvidenceUnprotect(
            'fulfillment:'.$id.':'.$proof['operation'],
            (string)$a['evidence_fulfillment_payload']);
        if (!is_array($payload) ||
            ($payload['operation']??null)!==$proof['operation'] ||
            ($payload['pay_sha256']??null)!==achbotPaymentEvidencePayFingerprint($pay) ||
            !is_array($payload['params']??null)) return false;
        $kind=(string)($payload['kind']??'');
        // Automatic recovery is permitted for an original pinned add-on too:
        // the provider must prove its captured absolute intent or pre-dispatch
        // reservation before it may send any further Native request.
        $server=(int)($payload['server']??0);
        $oid=(int)($payload['order']??0);
        $remark=(string)($payload['remark']??'');
        $units=(int)($payload['units']??0);
        if ($server<=0 || $remark==='' ||
            $payload['params']['pay_id']!==$id ||
            (string)($payload['params']['remark']??'')!==$remark ||
            !achbotAutoBankEditPreflight($db,$pay,$kind,$server,$oid,$remark,
                $units,true))
            return false;
        require_once __DIR__.'/../Panel/ProviderRuntime.php';
        $source=$payload;
        // The original account fence remains durable until the SAME local
        // transaction closes it with payment, audit and delivery finality.
        // A failure to persist verified fulfillment must NOT expose the
        // account to a newer Native operation.
        $fenceDeferred=achbotProviderType($db,$server)==='pasarguard' &&
            in_array($kind,['renew','day','volume'],true);
        if ($fenceDeferred) $source['defer_account_finality']=true;
        if ($operatorOverride!==null) {
            $digest=$operatorOverride['entitlement_digest']??null;
            $generation=$operatorOverride['generation']??null;
            $targetExpire=$operatorOverride['target_expire']??null;
            $targetBytes=$operatorOverride['target_bytes']??null;
            $savedGeneration=$payload['operator_override_generation']??0;
            if (!$operator || $actor<=0 || $primaryOwner<=0 ||
                !in_array($kind,['renew','day','volume'],true) ||
                !is_string($digest) ||
                preg_match('/^[a-f0-9]{64}$/D',$digest)!==1 ||
                !is_int($generation) || $generation<0 ||
                !is_int($savedGeneration) || $savedGeneration!==$generation ||
                $generation>=PHP_INT_MAX-1 ||
                !is_int($targetExpire) || $targetExpire<=0 ||
                !is_int($targetBytes) || $targetBytes<0 ||
                (int)($operatorOverride['actor_id']??0)!==$actor ||
                achbotProviderType($db,$server)!=='pasarguard')
                return false;
            // Consume the actor-bound one-time confirmation BEFORE any
            // remote reset/PUT. The attempt generation means a callback
            // replay cannot repeat a reset if the Native ACK was lost.
            // A fresh preview may authorize a deliberate next recovery.
            $previousAttestations=$payload['operator_override_attempts']??[];
            if (!is_array($previousAttestations) ||
                !array_is_list($previousAttestations) ||
                count($previousAttestations)!==$generation)
                return false;
            $attestation=[
                'actor_id'=>$actor,'native_digest'=>$digest,
                'generation'=>$generation+1,
                'target_expire'=>$targetExpire,'target_bytes'=>$targetBytes,
                'confirmed_at'=>gmdate('c')];
            $previousAttestations[]=$attestation;
            $payload['operator_override_generation']=$generation+1;
            $payload['operator_override_attempts']=$previousAttestations;
            $payload['operator_override_attestation']=$attestation;
            if (!achbotApiPaymentStoreFulfillment($db,$id,$proof,
                'dispatched',$payload,'dispatched')) return false;
            $source=$payload;
            if ($fenceDeferred) $source['defer_account_finality']=true;
            $source['operator_override_native_digest']=$digest;
            $source['operator_override_target']=[
                'expire'=>$targetExpire,'data_limit'=>$targetBytes];
            $source['operator_id']=$actor;
            $source['primary_owner_id']=$primaryOwner;
        }
        if (($payload['capture_required']??false)===true &&
            !isset($payload['native_intent']))
            $source['_paid_intent_capture']=achbotApiPaymentNativeIntentCapture(
                $db,$id,$proof,$payload);
        $response=achbotProviderRecoverEdit($db,$server,$payload['params'],
            $source,$operator);
        if (($response->success??false)!==true ||
            ($response->verified??false)!==true ||
            !is_int($response->applied_expire??null) ||
            !is_int($response->applied_bytes??null)) return false;
        if ($fenceDeferred && !is_array($response->observed_group_ids??null))
            return false;
        $payload['result']=['success'=>true,'verified'=>true,
            'applied_expire'=>$response->applied_expire,
            'applied_bytes'=>$response->applied_bytes];
        if ($fenceDeferred) {
            $payload['native_fence_deferred']=true;
            $payload['result']['observed_group_ids']=$response->observed_group_ids;
        }
        if (!achbotApiPaymentStoreFulfillment($db,$id,$proof,'verified',
            $payload,'dispatched'))return false;
    } else return false;
    $result=(object)($payload['result']??[]);
    $a=achbotApiPaymentAttempt($db,$id);
    $text=achbotApiPaymentManualCustomerText($db,$pay);
    if (!$a || $a['evidence_fulfillment_state']!=='verified' ||
        !is_string($a['evidence_fulfillment_payload']??null) ||
        $text===null) return false;
    $deliveryIntent=[
        'text'=>$text,'mode'=>$type==='RENEW_SCONFIG'?'plain':'HTML',
        'order'=>(int)$payload['order'],'server'=>(int)$payload['server'],
        'source_digest'=>hash('sha256',(string)$a['evidence_fulfillment_payload'])];
    return achbotAutoBankFinishEdit($db,$pay,$result,(string)$payload['kind'],(int)$payload['server'],(int)$payload['order'],(string)$payload['remark'],(int)$pay['price'],(int)$payload['units'],$payload['expiry']===null?null:(int)$payload['expiry'],$deliveryIntent,
        ($payload['native_fence_deferred']??false)===true
            ?['observed_group_ids'=>$payload['result']['observed_group_ids']??null,
              'operator_override_attested'=>is_array($payload['operator_override_attestation']??null)
                && (int)($payload['operator_override_attestation']['actor_id']??0)>0
                && is_string($payload['operator_override_attestation']['native_digest']??null)
                && preg_match('/^[a-f0-9]{64}$/D',
                    $payload['operator_override_attestation']['native_digest'])===1]
            :null);
}

/** No first-match wins. A same-amount local collision is left untouched. */
function achbotApiPaymentSmsUniqueLocked(mysqli $db,array $evidence,int $id): bool {
    $amount=(string)($evidence['amount_toman']??'');
    if (!preg_match('/^[1-9][0-9]*$/D',$amount)) return false;
    // Each page is a memory/locking batch, not a business ceiling. The
    // entire matching-quote set must be examined before assigning an SMS to
    // exactly one ORIGINAL invoice. The former LIMIT 201 made all invoices
    // fail once 201 issued the same exact amount.
    $cursor=0;$matches=[];
    do {
        $q=$db->prepare("SELECT a.*,p.state AS pay_state
            FROM payment_attempts a JOIN pays p ON p.id=a.pay_row_id
            WHERE a.provider='bank_transfer' AND a.evidence_profile IS NOT NULL
              AND a.quote_currency='toman' AND a.quote_amount=?
              AND a.pay_row_id>? ORDER BY a.pay_row_id LIMIT 100 FOR UPDATE");
        $q->bind_param('si',$amount,$cursor);$q->execute();
        $rows=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
        foreach ($rows as $a) {
            $cursor=(int)$a['pay_row_id'];
            // A fully consumed terminal invoice cannot claim another SMS.
            // Other terminal/manual claims stay in the collision set until
            // independently proven consumed via the original evidence journal.
            if(in_array($a['pay_state'],['approved','auto_cart_approved','paid','paid_with_wallet'],true) &&
                achbotPaymentEvidenceGetProof($db,$cursor)!==null)continue;
            $p=achbotPaymentEvidenceSnapshot($a);
            if($p===null)return false;
            if(achbotPaymentEvidenceSmsMatches($evidence,$a,$p)) {
                if($cursor!==$id || $matches) return false;
                $matches[]=$cursor;
            }
        }
    } while (count($rows)===100);
    return count($matches)===1 && $matches[0]===$id;
}
/** A bounded page is revisited from its start. No unpersisted cursor can lose evidence. */
function achbotApiPaymentCheckBank(mysqli $db,array $claim): string {
    $p=$claim['pay'];$a=$claim['attempt'];$profile=$claim['profile'];$id=(int)$p['id'];
    $existing=achbotPaymentEvidenceGetProof($db,$id);
    if($existing) {
        if(achbotApiPaymentTerminal($p))return 'settled';
        return achbotApiPaymentSettle($db,$id,$existing);
    }
    $pending=achbotPaymentEvidencePending($db,$id);
    if($pending) {
        $r=achbotPaymentEvidenceConsume($db,$pending);
        if(($r['status']??'')!=='proven')return (string)($r['status']??'review');
        $fresh=achbotApiPaymentRow($db,$id);if($fresh && achbotApiPaymentTerminal($fresh))return 'settled';
        $proof=$r['proof']??achbotPaymentEvidenceGetProof($db,$id);
        return is_array($proof)?achbotApiPaymentSettle($db,$id,$proof):'review';
    }
    if(time()>(int)$profile['issued_at']+(int)$profile['horizon_seconds'])return 'manual';
    // Keep original proof/uniqueness/admission logic independent from search.
    $inspect=static function(array $rows) use ($db,$a,$profile,$id): ?string {
        foreach($rows as $raw) {
            if(!is_array($raw))continue;
            $e=achbotPaymentEvidenceSmsNormalize($raw,$profile);
            if($e===null || !achbotPaymentEvidenceSmsMatches($e,$a,$profile))continue;
            $fresh=achbotApiPaymentRow($db,$id);if(!$fresh)return 'review';
            $mode=achbotApiPaymentTerminal($fresh)?'evidence_only':'settle';
            if($mode==='settle' && !in_array($fresh['state'],['pending','have_sent'],true))return 'review';
            $guard=static fn(mysqli $lockedDb)=>achbotApiPaymentSmsUniqueLocked($lockedDb,$e,$id);
            $handle=achbotPaymentEvidenceReserve($db,$id,$e,$mode,null,$guard);
            if(!$handle)return 'review';
            $consumed=achbotPaymentEvidenceConsume($db,$handle);
            if(($consumed['status']??'')!=='proven')return (string)($consumed['status']??'review');
            // A human may win while remote consumption is in flight; a terminal
            // human outcome must never be credited or applied again.
            $fresh=achbotApiPaymentRow($db,$id);
            if($fresh && achbotApiPaymentTerminal($fresh))return 'settled';
            $proof=$consumed['proof']??achbotPaymentEvidenceGetProof($db,$id);
            return is_array($proof)?achbotApiPaymentSettle($db,$id,$proof):'review';
        }
        return null;
    };
    $quote=achbotPaymentEvidenceIntegralQuote($a['quote_amount']??null);
    $issued=$profile['issued_at']??null;
    if(($a['quote_currency']??null)!=='toman' || $quote===null ||
        !is_int($issued) || $issued<=180)return 'review';

    // WordPress 1.0.16 indexes exact amount/unit and immutable ingestion time.
    // Start at original quote issuance minus 180s, NOT receipt submission time.
    // Never use an upper ingestion bound: a delayed bank SMS can be stored
    // after its real transfer time and still be eligible within the pinned
    // original quote horizon checked by SmsMatches().
    $filters=[['amount'=>(int)$quote,'display_unit'=>'toman','received_from'=>$issued-180]];
    if((int)$quote<=intdiv(PHP_INT_MAX,10))
        $filters[]=['amount'=>(int)$quote*10,'display_unit'=>'rial','received_from'=>$issued-180];
    $legacy=false;
    foreach($filters as $filter) {
        $after=0;
        for($page=0;$page<4;$page++) {
            $result=achbotPaymentEvidenceSmsList($profile,$after,$filter);
            if(($result['status']??'')==='ambiguous_time' &&
                isset($filter['received_from'])) {
                // WP site DATETIME during a timezone rollback may be ambiguous.
                // Amount/unit filtering remains indexed; source-SMS transfer-time
                // admission is still enforced locally to the original 180s gate.
                unset($filter['received_from']);$page--;continue;
            }
            if(($result['status']??'')==='unsupported') {
                // Older WP versions can accept unknown query args with HTTP 200.
                // Preserve availability without treating an unfiltered page as
                // exhaustive or weakening exact match/financial proof guards.
                $legacy=true;break 2;
            }
            if(empty($result['ok']))return 'unavailable';
            $outcome=$inspect($result['data']);
            if($outcome!==null)return $outcome;
            if(empty($result['has_more']))break;
            $next=(int)$result['next_after_id'];
            if($next<=$after)return 'review';
            if($page===3)return 'review'; // Too many same-amount candidates.
            $after=$next;
        }
    }
    if(!$legacy)return 'monitoring';

    // Backward-compatible fallback only for a demonstrably unsupported API.
    // Current Blinko 1.0.16 uses the fast path and never scans old unrelated SMS.
    $after=max(0,(int)($a['evidence_cursor_id']??0));
    for($page=0;$page<4;$page++) {
        $result=achbotPaymentEvidenceSmsList($profile,$after);
        if(empty($result['ok']))return 'unavailable';
        $outcome=$inspect($result['data']);
        if($outcome!==null)return $outcome;
        $next=empty($result['has_more'])?0:(int)($result['next_after_id']??0);
        if($next!==0 && $next<=$after)return 'review';
        $cursor=$db->prepare('UPDATE payment_attempts SET evidence_cursor_id=? WHERE pay_row_id=? AND evidence_claim_token=?');
        $cursor->bind_param('iis',$next,$id,$claim['token']);$cursor->execute();$cursor->close();
        if($next===0)break;$after=$next;
    }
    return 'monitoring';
}
/** Preserve a definite no-effect response for same-code reads without HTTP. */
function achbotApiPaymentRememberGiftRejection(mysqli $db,int $id,string $code,string $status): void {
    if(!in_array($status,['used','revoked','expired','mismatch'],true))return;
    try {
        if(!$db->begin_transaction())return;$pay=achbotApiPaymentRow($db,$id,true);$a=achbotApiPaymentAttempt($db,$id,true);
        if(!$pay||!$a||$a['evidence_state']!=='invalid_code'||!hash_equals((string)$a['evidence_code_sha256'],achbotPaymentEvidenceCodeDigest($code))){$db->rollback();return;}
        $d=achbotPaymentEvidenceUnprotect('code:'.$a['id'].':'.$id,(string)$a['evidence_code']);if(($d['code']??null)!==$code){$db->rollback();return;}
        $d['last_status']=$status;$sealed=achbotPaymentEvidenceProtect('code:'.$a['id'].':'.$id,$d);
        $q=$db->prepare('UPDATE payment_attempts SET evidence_code=? WHERE pay_row_id=? AND evidence_code_sha256=?');$q->bind_param('sis',$sealed,$id,$a['evidence_code_sha256']);$q->execute();$q->close();$db->commit();
    }catch(Throwable $e){try{$db->rollback();}catch(Throwable $ignored){}}
}
/** A proved gift card remains consumed by its ORIGINAL AChBot invoice
 * even if the issuer later changes the card back to active. This read-only
 * lookup does not authorize replay, reversal or a second settlement.
 */
function achbotApiPaymentGiftPreviouslyProven(mysqli $db,int $payId,?string $codeDigest=null,
                                            ?string $providerIdentity=null): bool {
    if ($payId<=0 || ($codeDigest===null && $providerIdentity===null))return false;
    try {
        if ($providerIdentity!==null && !preg_match('/\A[a-f0-9]{64}\z/D',$providerIdentity))
            return false;
        if ($codeDigest!==null && !preg_match('/\A[a-f0-9]{64}\z/D',$codeDigest))
            return false;
        $match=$providerIdentity!==null
            ?'t.payment_id=?':'a.evidence_code_sha256=?';
        $value=$providerIdentity!==null?'api1:'.$providerIdentity:$codeDigest;
        $q=$db->prepare('SELECT 1 FROM payment_attempts a
            INNER JOIN payment_transactions t ON t.attempt_id=a.id
            WHERE a.provider=\'gift_card\' AND t.provider=\'gift_card\'
              AND a.pay_row_id<>? AND t.evidence_state=\'proven\'
              AND t.evidence_operation IS NOT NULL AND '.$match.' LIMIT 1');
        $q->bind_param('is',$payId,$value);$q->execute();
        $found=$q->get_result()->num_rows>0;$q->close();
        return $found;
    } catch (Throwable $ignored) {return false;}
}

/** Customer code is encrypted before verification HTTP. Never log or fan it out. */
function achbotApiPaymentCheckGift(mysqli $db,array $claim): string {
    $pay=$claim['pay'];$id=(int)$pay['id'];
    if(achbotApiPaymentTerminal($pay))return 'settled';
    $proof=achbotPaymentEvidenceGetProof($db,$id);if($proof)return achbotApiPaymentSettle($db,$id,$proof);
    $pending=achbotPaymentEvidencePending($db,$id);
    if($pending) {
        $r=achbotPaymentEvidenceConsume($db,$pending);
        if(($r['status']??'')!=='proven')return (string)($r['status']??'review');
        $fresh=achbotApiPaymentRow($db,$id);if($fresh && achbotApiPaymentTerminal($fresh))return 'settled';
        $proof=$r['proof']??achbotPaymentEvidenceGetProof($db,$id);
        return is_array($proof)?achbotApiPaymentSettle($db,$id,$proof):'review';
    }
    $code=achbotPaymentEvidenceReadGiftCode($db,$id);if($code===null)return 'monitoring';
    // Legacy malformed sealed evidence is kept for human reconciliation,
    // but cannot re-enter provider monitoring or create a fresh review.
    if (!achbotPaymentEvidenceGiftCodeFormatValid($code))return 'manual';
    // The original proven code digest is sufficient to park an exact replay
    // WITHOUT another provider /verify (which itself writes issuer audit).
    if (achbotApiPaymentGiftPreviouslyProven($db,$id,
        (string)($claim['attempt']['evidence_code_sha256']??''))) {
        error_log('achbot_gift_proven_reuse_deferred pay_id='.$id.' reason=code_digest_collision');
        return 'manual';
    }
    $result=achbotPaymentEvidenceGiftVerify($claim['profile'],$code);$status=(string)($result['status']??'unavailable');
    if(in_array($status,['used','revoked','expired'],true)) {
        if(achbotPaymentEvidenceRejectGiftCode($db,$id,$code,$status))achbotApiPaymentRememberGiftRejection($db,$id,$code,$status);return $status;
    }
    if(empty($result['ok']) || $status!=='active')return 'review';
    $e=achbotPaymentEvidenceGiftNormalize($result['data']??[],$claim['profile'],$claim['attempt']);
    if($e===null) {$why=achbotPaymentEvidenceGiftNormalizationStatus($result['data']??[],$claim['profile'],$claim['attempt']);if($why==='mismatch' && achbotPaymentEvidenceRejectGiftCode($db,$id,$code,'mismatch'))achbotApiPaymentRememberGiftRejection($db,$id,$code,'mismatch');return $why==='mismatch'?'mismatch':'review';}
    if (achbotApiPaymentGiftPreviouslyProven($db,$id,null,(string)$e['identity'])) {
        error_log('achbot_gift_proven_reuse_deferred pay_id='.$id.' reason=provider_identity_collision');
        return 'manual';
    }
    // The locked Reserve() identity check remains authoritative for races.
    $handle=achbotPaymentEvidenceReserve($db,$id,$e,'settle',$code);
    if(!$handle)return 'review';
    $consumed=achbotPaymentEvidenceConsume($db,$handle);
    if(($consumed['status']??'')!=='proven')return (string)($consumed['status']??'review');
    $fresh=achbotApiPaymentRow($db,$id);if($fresh && achbotApiPaymentTerminal($fresh))return 'settled';
    $proof=$consumed['proof']??achbotPaymentEvidenceGetProof($db,$id);
    return is_array($proof)?achbotApiPaymentSettle($db,$id,$proof):'review';
}
/** Real text review uses a separate lease and the unchanged human callbacks. */
function achbotApiPaymentReviewKeyboard(array $pay): string {
    if($pay['state']!=='have_sent')return achbotPayUxStatusKeyboard((string)$pay['hash_id']);
    $hash=(string)$pay['hash_id'];$t=(string)$pay['type'];
    $prefix=match(true) {
        $t==='INCREASE_WALLET'=>'approvePayment',
        $t==='RENEW_ACCOUNT'=>'approveRenewAcc',
        $t==='RENEW_SCONFIG'=>'accept',
        str_starts_with($t,'INCREASE_DAY_')=>'approveIncreaseDay',
        str_starts_with($t,'INCREASE_VOLUME_')=>'approveIncreaseVolume',
        (int)$pay['day']>0 || (int)$pay['volume']>0=>'accCustom',
        default=>'accept'
    };
    $db=$GLOBALS['connection']??null;
    if($db instanceof mysqli)return buildAdminKeyboardWithStats($db,$GLOBALS['buttonValues']??achbotMessages('legacy_buttons'),$prefix.$hash,'declineAction_'.$hash,$hash,(int)$pay['user_id']);
    return inlineKeyboard([
        [['text'=>achbotMessage('manual_payment.api_payment.view_code'),'callback_data'=>'apiReview_'.$hash]],
        [['text'=>achbotMessage('legacy_buttons.approve'),'callback_data'=>$prefix.$hash],
         ['text'=>achbotMessage('legacy_buttons.decline'),'callback_data'=>'declineAction_'.$hash]]
    ]);
}
/** Button and admitted Cron service coalesce under this durable evidence claim. */
/** Ephemeral monotonic timing context; never seal it in financial evidence. */
function achbotApiPaymentWithBudget(float $seconds,callable $action) {
    $key='achbotPaymentEvidenceDeadline';$had=array_key_exists($key,$GLOBALS);$previous=$GLOBALS[$key]??null;
    $deadline=hrtime(true)/1000000000+$seconds;
    if((is_float($previous)||is_int($previous))&&is_finite((float)$previous))$deadline=min($deadline,(float)$previous);
    $GLOBALS[$key]=$deadline;
    try{return $action();}finally{if($had)$GLOBALS[$key]=$previous;else unset($GLOBALS[$key]);}
}
function achbotApiPaymentCheck(mysqli $db,int $id): string {
    // The Cron child may check many invoices in one PHP process. Scope HTTP
    // diagnostics to THIS original payment, then restore any parent context.
    $key='achbotPaymentDiagnosticPayId';
    $had=array_key_exists($key,$GLOBALS);
    $old=$GLOBALS[$key]??null;
    $GLOBALS[$key]=$id;
    try {
        return achbotApiPaymentWithWorkerLock($db,$id,
            static fn():string=>achbotApiPaymentWithBudget(15.0,
                static fn():string=>achbotApiPaymentCheckWithinBudget($db,$id)));
    } finally {
        if ($had) $GLOBALS[$key]=$old;
        else unset($GLOBALS[$key]);
    }
}
function achbotApiPaymentCheckWithinBudget(mysqli $db,int $id): string {
    $checkStarted=microtime(true);
    if(!achbotApiPaymentReady($db)) {
        achbotDiagnosticEvent('achbot.finance.evidence_check',
            ['pay_id'=>$id,'reason'=>'schema_not_ready'],'error');
        return 'unavailable';
    }
    $saved=achbotApiPaymentAttempt($db,$id);
    $pay=achbotApiPaymentRow($db,$id);
    if(!$saved || !$pay) {
        achbotDiagnosticEvent('achbot.finance.evidence_check',
            ['pay_id'=>$id,'reason'=>'original_missing'],'warning');
        return 'unavailable';
    }
    $context=['pay_id'=>$id,'provider'=>(string)$saved['provider'],
        'stage'=>(string)$saved['stage'],
        'state'=>(string)$pay['state'],
        'evidence_state'=>(string)($saved['evidence_state']??'none')];
    if (($saved['review_archived_at']??null)!==null) {
        achbotDiagnosticEvent('achbot.finance.evidence_check',$context+
            ['reason'=>'archived'],'info');
        return 'archived';
    }
    if(!achbotPaymentEvidenceReviewReady($pay,$saved) &&
        !achbotPaymentEvidencePending($db,$id) &&
        !achbotPaymentEvidenceGetProof($db,$id)) {
        achbotDiagnosticEvent('achbot.finance.evidence_check',$context+
            ['reason'=>'awaiting_receipt'],'info');
        return 'awaiting_receipt';
    }
    if($saved && $saved['provider']==='gift_card' && ($saved['evidence_state']??'')==='invalid_code') {
        $d=achbotPaymentEvidenceUnprotect('code:'.$saved['id'].':'.$id,(string)$saved['evidence_code']);
        if(in_array($d['last_status']??'',['used','revoked','expired','mismatch'],true)){achbotApiPaymentNotice($db,$pay,$d['last_status']);return $d['last_status'];}
    }
    $claim=achbotPaymentEvidenceClaim($db,$id);
    if(!$claim) {
        // This is a point-in-time diagnostic hint; Claim() remains the only
        // lock/lease authority. Never infer payment finality from a skipped poll.
        $reason='claim_declined';
        if (!empty($saved['evidence_next_check_at']) &&
            strtotime((string)$saved['evidence_next_check_at'])>time())
            $reason='check_not_due';
        elseif (!empty($saved['evidence_claim_token']) &&
            !empty($saved['evidence_claimed_at']) &&
            strtotime((string)$saved['evidence_claimed_at'])>time()-120)
            $reason='claim_lease_held';
        achbotDiagnosticEvent('achbot.finance.evidence_check',
            $context+['reason'=>$reason],'info');
        return $saved && $saved['provider']==='gift_card' &&
            ($saved['evidence_state']??'')==='review' &&
            !empty($saved['evidence_review_sent_at'])?'review':'pending';
    }
    $token=(string)$claim['token'];$status='review';
    try {
        $status=$claim['attempt']['provider']==='bank_transfer'?achbotApiPaymentCheckBank($db,$claim):achbotApiPaymentCheckGift($db,$claim);

    }catch(Throwable $e){
        achbotDiagnosticEvent('achbot.finance.evidence_check_exception',
            $context+['class'=>get_class($e),'reason'=>(string)$e->getMessage()],
            'error');
        error_log('achbot_api_payment_check_deferred pay_id='.$id);
        $status='review';
    }
    finally {
        // The one-minute Cron is already the polling throttle. A 60-second
        // post-receipt hint can miss the very next tick and add another
        // minute when bank SMS arrives just after the first check. Keep
        // provider Retry-After and evidence/operation idempotency fences.
        $delay=($claim['attempt']['provider']==='bank_transfer' &&
            in_array($status,['monitoring','pending'],true))?20:60;
        achbotPaymentEvidenceDefer($db,$id,$token,
            in_array($status,['confirmed','settled'],true)?'proven':
            (in_array($status,['review','unavailable'],true)?'review':
            (in_array($status,['used','expired','revoked','mismatch'],true)?'invalid_code':
            ($status==='pending'?'uncertain':($status==='manual'?'manual':'monitoring')))),
            $delay);
    }
    $pay=achbotApiPaymentRow($db,$id);if($pay){
        $c=achbotApiPaymentContext($db,(string)$pay['hash_id']);if($c)achbotApiPaymentSyncInvoice($db,$c);
        if(in_array($status,['used','revoked','expired','mismatch'],true))achbotApiPaymentNotice($db,$pay,$status);
        elseif(in_array($status,['review','pending','unavailable'],true))achbotApiPaymentNotice($db,$pay,$status==='pending'?'uncertain':'review');
        elseif($status==='settled'){$proof=achbotPaymentEvidenceGetProof($db,$id);achbotApiPaymentNotice($db,$pay,($proof['mode']??'')==='evidence_only'?'evidence_only':'settled');}
    }
    achbotDiagnosticEvent('achbot.finance.evidence_check',
        $context+['result'=>$status,
            'elapsed_ms'=>(int)round((microtime(true)-$checkStarted)*1000)],
        in_array($status,['review','unavailable'],true)?'warning':'info');
    return $status;
}
/** Migration 022 is additive: an older API-only installation may have the
 * original evidence ledger but not yet the operator Archive column. Its
 * genuine pending payment must keep working. On 022 installations the
 * exclusion is mandatory; any error other than a missing column fails
 * closed rather than silently admitting an archived original.
 */
function achbotApiPaymentArchivePredicate(mysqli $db): string {
    try {
        $probe=$db->query('SELECT review_archived_at FROM payment_attempts LIMIT 0');
        if ($probe instanceof mysqli_result) {
            $probe->free();
            return ' AND a.review_archived_at IS NULL';
        }
        if ((int)$db->errno===1054) return '';
        throw new RuntimeException('api_archive_schema_unavailable');
    } catch (mysqli_sql_exception $error) {
        if ((int)$error->getCode()===1054) return '';
        throw $error;
    }
}
function achbotApiPaymentMaintain(mysqli $db,int $limit=12): array {
    return achbotApiPaymentWithBudget(35.0,static fn()=>achbotApiPaymentMaintainWithinBudget($db,$limit));
}
function achbotApiPaymentMaintainWithinBudget(mysqli $db,int $limit=12): array {
    $checked=0;$edited=0;$selected=0;$overflowId=0;
    $started=microtime(true);$limit=max(1,min(12,$limit));
    if(!achbotApiPaymentReady($db)) {
        achbotDiagnosticEvent('achbot.finance.evidence_tick',
            ['reason'=>'schema_not_ready'],'error');
        return ['checked'=>0,'edited'=>0];
    }
    try {
        $archivePredicate=achbotApiPaymentArchivePredicate($db);
        $q=$db->query("SELECT a.pay_row_id,p.hash_id FROM payment_attempts a JOIN pays p ON p.id=a.pay_row_id
            WHERE a.evidence_profile IS NOT NULL AND a.provider IN ('bank_transfer','gift_card')
              {$archivePredicate}
              AND ((p.state='have_sent' AND a.stage='review' AND p.message_id>0 AND p.chat_id>0)
                   OR p.state IN ('approved','paid','auto_cart_approved','paid_with_wallet','processing')
                   OR EXISTS (SELECT 1 FROM payment_transactions t WHERE t.attempt_id=a.id AND t.evidence_operation IS NOT NULL))
              AND (a.evidence_next_check_at IS NULL OR a.evidence_next_check_at<=NOW())
              AND (a.evidence_state IS NULL OR a.evidence_state NOT IN ('proven','invalid_code','manual')
                   OR (a.evidence_state='proven' AND (p.state IN ('pending','have_sent') OR
                       (p.state='processing' AND (
                          a.evidence_fulfillment_state IN ('reserved','verified')
                          OR (a.evidence_fulfillment_state='dispatched'
                              AND (p.type IN ('RENEW_ACCOUNT','RENEW_SCONFIG')
                                  OR p.type REGEXP '^INCREASE_(DAY|VOLUME)_[1-9][0-9]*_[1-9][0-9]*$')))))))
            ORDER BY COALESCE(a.evidence_next_check_at,a.created_at),a.id LIMIT ".($limit+1));
        $rows=$q->fetch_all(MYSQLI_ASSOC);
        if (count($rows)>$limit) {
            $overflow=array_pop($rows);
            $overflowId=(int)$overflow['pay_row_id'];
        }
        $selected=count($rows);
        if($overflowId>0)
            achbotDiagnosticEvent('achbot.finance.evidence_batch_deferred',
                ['pay_id'=>$overflowId,'reason'=>'batch_size'],'info');
        foreach($rows as $row){
            if(microtime(true)-$started>=35)break;
            $outcome=achbotApiPaymentCheck($db,(int)$row['pay_row_id']);
            $checked++;
        }
        // UI convergence is independent of evidence polling/finality/leases.
        $q=$db->query("SELECT p.hash_id FROM payment_attempts a JOIN pays p ON p.id=a.pay_row_id
            WHERE a.evidence_profile IS NOT NULL
              {$archivePredicate}
            ORDER BY CASE WHEN p.state IN ('approved','paid','auto_cart_approved','paid_with_wallet')
                    AND (a.evidence_projection_checked_at IS NULL OR
                         a.updated_at>a.evidence_projection_checked_at)
                THEN 0 ELSE 1 END,
                a.evidence_projection_checked_at IS NOT NULL,
                a.evidence_projection_checked_at,a.id LIMIT {$limit}");
        foreach($q->fetch_all(MYSQLI_ASSOC) as $row){
            $c=achbotApiPaymentContext($db,(string)$row['hash_id']);if(!$c)continue;
            if(achbotApiPaymentSyncInvoice($db,$c))$edited++;
            $p=$c['pay'];$a=$c['attempt'];$proof=achbotPaymentEvidenceGetProof($db,(int)$p['id']);
            if(achbotApiPaymentTerminal($p))achbotApiPaymentNotice($db,$p,($proof['mode']??'')==='evidence_only'?'evidence_only':(($a['evidence_fulfillment_state']??'')==='done'?'confirmed':'settled'));
            elseif(($a['evidence_state']??'')==='invalid_code'){
                $d=achbotPaymentEvidenceUnprotect('code:'.$a['id'].':'.$p['id'],(string)$a['evidence_code']);
                if(in_array($d['last_status']??'',['used','revoked','expired','mismatch'],true))achbotApiPaymentNotice($db,$p,$d['last_status']);
            }
            elseif(in_array($a['evidence_state']??'',['review','uncertain'],true)||$p['state']==='processing')achbotApiPaymentNotice($db,$p,($a['evidence_state']??'')==='uncertain'?'uncertain':'review');
            $stamp=$db->prepare('UPDATE payment_attempts SET evidence_projection_checked_at=NOW(6) WHERE pay_row_id=?');$stamp->bind_param('i',$p['id']);$stamp->execute();$stamp->close();
        }
    }catch(Throwable $e){
        achbotDiagnosticEvent('achbot.finance.evidence_tick_exception',
            ['class'=>get_class($e),'reason'=>(string)$e->getMessage(),
             'checked'=>$checked,'projected'=>$edited],'error');
        error_log('achbot_api_payment_maintenance_deferred class='.
            get_class($e).' code='.(int)$e->getCode().' checked='.$checked.
            ' projected='.$edited);
    }finally{
        achbotDiagnosticEvent('achbot.finance.evidence_tick',
            ['selected'=>$selected,'checked'=>$checked,'projected'=>$edited,
             'deferred'=>(int)($overflowId>0),'due_unchecked'=>max(0,$selected-$checked),
             'budget_exhausted'=>(int)($selected>$checked),
             'batch_ms'=>(int)round((microtime(true)-$started)*1000)],'info');
    }
    return ['checked'=>$checked,'edited'=>$edited];
}
