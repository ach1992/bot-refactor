<?php
/** Optional existing-API evidence inside the original manual payment ledger. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }
require_once __DIR__.'/../Diagnostics/EventLog.php';
require_once __DIR__.'/ReceiptHelpers.php';
require_once __DIR__.'/PaymentLifecycle.php';
require_once __DIR__.'/ApiPaymentPresentation.php';
require_once __DIR__.'/PaymentEvidence.php';
require_once __DIR__.'/CheckoutAdmission.php';

/** Protect each original API payment throughout evidence redemption,
 * settlement and invoice projection. Human decisions share the same
 * DB-scoped session lock.
 */
function achbotApiPaymentWithWorkerLock(mysqli $db,int $payId,callable $action): string {
    if ($payId<=0) return 'review';
    require_once __DIR__.'/GatewayClient.php';
    $lease=achbotOriginalDispatchLeaseBegin($db,$payId);
    if (!in_array($lease,['owned','borrowed'],true)) {
        achbotDiagnosticEvent('achbot.finance.worker_lock',
            ['pay_id'=>$payId,'reason'=>$lease==='busy'?'worker_busy':'lease_unavailable'],'info');
        return 'review';
    }
    try {return $action();}
    finally {achbotOriginalDispatchLeaseEnd($db,$payId,$lease);}
}

/** A recorded original proof can only resume its admitted invoice. */
function achbotApiPaymentCanResume(mysqli $db,array $pay): bool {
    $id=(int)($pay['id']??0);$a=$pay['attempt']??null;
    if ($id<=0 || !$a ||
        !achbotCurrentReceiptPin($a) ||
        !achbotCreditMethodHas($a['provider'],'api_checker')) return false;
    $proof=achbotPaymentEvidenceGetProof($db,$id);
    return ($proof['mode']??null)==='settle';
}

function achbotApiPaymentReady(mysqli $db): bool {
    return function_exists('achbotPaymentEvidenceSchemaReady') && achbotPaymentEvidenceSchemaReady($db);
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
function achbotApiPaymentContext(mysqli $db,string $hash,int $actor=0): ?array {
    if (!achbotApiPaymentReady($db)) return null;
    try {
        $q=$db->prepare('SELECT * FROM pays WHERE hash_id=? LIMIT 1');$q->bind_param('s',$hash);
        $q->execute();$pay=$q->get_result()->fetch_assoc();$q->close();
        if (!$pay || ($actor>0 && (int)$pay['user_id']!==$actor)) return null;
        $attempt=achbotApiPaymentAttempt($db,(int)$pay['id']);
        $profile=$attempt && achbotCurrentReceiptPin($attempt)?achbotPaymentEvidenceSnapshot($attempt):null;
        return $profile===null?null:['pay'=>$pay,'attempt'=>$attempt,'profile'=>$profile];
    } catch (Throwable $e) {return null;}
}
function achbotApiPaymentTerminal(array $pay): bool {
    return in_array((string)($pay['state']??''),['approved','paid','auto_cart_approved','paid_with_wallet'],true);
}
/** Project committed state, not a stale checker return. The same original
 * payment lease already serializes evidence and human decisions. */
function achbotApiPaymentNotice(mysqli $db,int $id): int {
    require_once __DIR__.'/ReceiptNotices.php';
    require_once __DIR__.'/GatewayClient.php';
    achbotPaymentEvidenceRequireAutocommit($db);
    return achbotPayUxWithLease($db,$id,static function() use ($db,$id): int {
        $edited=0;
        $pay=achbotApiPaymentRow($db,$id);$a=achbotApiPaymentAttempt($db,$id);
        if (!$pay || !$a || $a['wallet_credit_entry_id']!==null || $pay['state']==='declined') return 0;
        $hash=(string)$pay['hash_id'];
        $proof=achbotPaymentEvidenceGetProof($db,$id);
        $actionable=achbotGatewayAttentionCaseOpen($db,$id);
        $label=achbotApiPaymentStatusLabel($pay,$a,$proof);
        if ($label===null && !$actionable) return 0;
        $label??=achbotMessage('manual_payment.api_payment.status_review');
        $automatic=achbotApiPaymentTerminal($pay) && (int)($pay['approved_by']??-1)===0 && ($proof['mode']??'')==='settle';
        if ($automatic) $label=achbotMessage('manual_payment.api_payment.auto_approved');
        $refs=achbotReceiptMessageTargets($db,$pay);
        if (achbotApiPaymentTerminal($pay)) {
            $keys=achbotApiPaymentFinalKeyboard($pay,$label,$automatic,achbotApiPaymentPresentation($db,$pay,$a));
        } else {
            // The review queue also contains ordinarily admitted receipts.
            // Its membership is not evidence of exceptional API failure.
            $keys=achbotApiPaymentReviewKeyboard($db,$pay,$a,$label,$actionable);
        }
        if ($keys!==null) $edited=achbotApiPaymentProjectStaff($db,$pay,$refs,$keys);
        return $edited;
    },0);
}
/** Each original staff target has independent ACK/retirement/retry state.
 * Financial and terminal edits keep the existing per-target lease. */
function achbotApiPaymentProjectStaff(mysqli $db,array $pay,array $refs,string $keys): int {
    require_once __DIR__.'/ReceiptNotices.php';
    achbotPaymentEvidenceRequireAutocommit($db);
    $edited=0;
    foreach ($refs as $ref) {
        $chat=(int)$ref['chat_id'];$message=(int)$ref['message_id'];
        if ($chat<=0 || $message<=0) continue;
        try {
            $result=achbotReceiptStatusEdit($db,$pay,$chat,$message,
                static function() use($db,$pay,$chat,$message,$keys): ?bool {
                    $r=achbotPayUxStaffRef($db,$pay,$chat,$message);
                    if (!$r) return null;
                    return achbotPayUxApplyProjection($db,$r,'',$keys,true)==='acknowledged';
                });
            if ($result===true) ++$edited;
        } catch (Throwable $e) {
            error_log('achbot_api_staff_projection_deferred pay_id='.(int)$pay['id'].' class='.get_class($e));
        }
    }
    return $edited;
}

/** Resume an already persisted proof through the common credit transaction. */
function achbotApiPaymentSettle(mysqli $db,int $id,array $proof): string {
    require_once __DIR__.'/CheckoutAdmission.php';
    return achbotApiPaymentWithWorkerLock($db,$id,static function() use ($db,$id,$proof): string {
        try {
            achbotCheckoutRequireSchema($db);
            $pay=achbotApiPaymentRow($db,$id);
            if (!$pay || ($proof['mode']??null)!=='settle') return 'review';
            $r=achbotCheckoutSettleReceipt($db,$id,(int)$pay['user_id'],'api',$proof);
            require_once __DIR__.'/CheckoutRoutes.php';
            achbotCheckoutAdvance($db,$r['operation'],$id);
            return 'settled';
        } catch (Throwable $e) {
            achbotDiagnosticEvent('achbot.finance.evidence_settlement',
                ['pay_id'=>$id,'reason'=>'checkout_settlement_deferred','class'=>get_class($e)],'warning');
            return 'review';
        }
    });
}

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
/** Bounded work per tick, with a persisted cyclic search cursor. */
function achbotApiPaymentCheckBank(mysqli $db,array $claim,callable $settle): string {
    $p=$claim['pay'];$a=$claim['attempt'];$profile=$claim['profile'];$id=(int)$p['id'];
    $existing=achbotPaymentEvidenceGetProof($db,$id);
    if($existing) {
        if(achbotApiPaymentTerminal($p))return 'settled';
        return $settle($db,$id,$existing);
    }
    $pending=achbotPaymentEvidencePending($db,$id);
    if($pending) {
        $r=achbotPaymentEvidenceConsume($db,$pending);
        if(($r['status']??'')!=='proven')return (string)($r['status']??'review');
        $fresh=achbotApiPaymentRow($db,$id);if($fresh && achbotApiPaymentTerminal($fresh))return 'settled';
        $proof=$r['proof']??achbotPaymentEvidenceGetProof($db,$id);
        return is_array($proof)?$settle($db,$id,$proof):'review';
    }
    if(time()>=(int)($profile['search_expires_at']??0))return 'manual';
    // Keep original proof/uniqueness/admission logic independent from search.
    $inspect=static function(array $rows) use ($db,$a,$profile,$id,$settle): ?string {
        foreach($rows as $raw) {
            if(!is_array($raw))continue;
            $e=achbotPaymentEvidenceSmsNormalize($raw,$profile);
            if($e===null || !achbotPaymentEvidenceSmsMatches($e,$a,$profile))continue;
            $fresh=achbotApiPaymentRow($db,$id);if(!$fresh)return 'review';
            $mode=achbotApiPaymentTerminal($fresh)?'evidence_only':'settle';
            if($mode==='settle' && !in_array($fresh['state'],['pending','have_sent','service_superseded'],true))return 'review';
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
            return is_array($proof)?$settle($db,$id,$proof):'review';
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
    // Advance one shared frontier only after every supported unit has
    // been checked through it. Exhausted units do not constrain the
    // frontier; reaching the end restarts a cycle for late arrivals.
    $after=max(0,(int)($a['evidence_cursor_id']??0));
    $saveCursor=static function(int $next) use ($db,$id,$claim): void {
        $q=achbotProviderStatement($db,'UPDATE payment_attempts SET evidence_cursor_id=? WHERE pay_row_id=? AND evidence_claim_token=?',
            'iis',[$next,$id,$claim['token']]);
        $q->close();
    };
    $legacy=false;
    for($page=0;$page<4;$page++) {
        $frontiers=[];
        foreach($filters as $index=>$filter) {
            $result=achbotPaymentEvidenceSmsList($profile,$after,$filter);
            if(($result['status']??'')==='ambiguous_time' && isset($filter['received_from'])) {
                // The source transfer-time check remains authoritative
                // when the upstream ingestion timezone is ambiguous.
                unset($filter['received_from']);
                $filters[$index]=$filter;
                $result=achbotPaymentEvidenceSmsList($profile,$after,$filter);
            }
            if(($result['status']??'')==='unsupported') {
                $legacy=true;break 2;
            }
            if(empty($result['ok']))return 'unavailable';
            $outcome=$inspect($result['data']);
            if($outcome!==null)return $outcome;
            if(!empty($result['has_more'])) {
                $next=(int)$result['next_after_id'];
                if($next<=$after)return 'review';
                $frontiers[]=$next;
            }
        }
        $next=$frontiers?min($frontiers):0;
        $saveCursor($next);
        if($next===0)break;
        $after=$next;
    }
    if(!$legacy)return 'monitoring';

    // An externally deployed API that does not implement the filter
    // contract still uses the same bounded, cyclic evidence search.
    for($page=0;$page<4;$page++) {
        $result=achbotPaymentEvidenceSmsList($profile,$after);
        if(empty($result['ok']))return 'unavailable';
        $outcome=$inspect($result['data']);
        if($outcome!==null)return $outcome;
        $next=empty($result['has_more'])?0:(int)($result['next_after_id']??0);
        if($next!==0 && $next<=$after)return 'review';
        $saveCursor($next);
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
function achbotApiPaymentCheckGift(mysqli $db,array $claim,callable $settle): string {
    $pay=$claim['pay'];$id=(int)$pay['id'];
    if(achbotApiPaymentTerminal($pay))return 'settled';
    $proof=achbotPaymentEvidenceGetProof($db,$id);if($proof)return $settle($db,$id,$proof);
    $pending=achbotPaymentEvidencePending($db,$id);
    if($pending) {
        $r=achbotPaymentEvidenceConsume($db,$pending);
        if(($r['status']??'')!=='proven')return (string)($r['status']??'review');
        $fresh=achbotApiPaymentRow($db,$id);if($fresh && achbotApiPaymentTerminal($fresh))return 'settled';
        $proof=$r['proof']??achbotPaymentEvidenceGetProof($db,$id);
        return is_array($proof)?$settle($db,$id,$proof):'review';
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
    // No usable pre-consumption verification response. Preserve the
    // recoverable read failure; pending consumption is handled above.
    if($status==='unavailable')return 'unavailable';
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
    return is_array($proof)?$settle($db,$id,$proof):'review';
}
/** Current evidence state, independently of attention-inbox membership. */
function achbotApiPaymentStatusLabel(array $pay,array $a,?array $proof=null): ?string {
    if (achbotApiPaymentTerminal($pay))
        return achbotMessage(($proof['mode']??null)==='evidence_only'?
            'manual_payment.api_payment.status_evidence_only':'manual_payment.api_payment.status_settled');
    $state=$a['evidence_state']??null;
    if ($state==='invalid_code') {
        $d=achbotPaymentEvidenceUnprotect('code:'.$a['id'].':'.$pay['id'],(string)$a['evidence_code']);
        return achbotMessage(match($d['last_status']??null) {
            'used'=>'manual_payment.api_payment.code_used',
            'revoked'=>'manual_payment.api_payment.code_revoked',
            'expired'=>'manual_payment.api_payment.code_expired',
            'mismatch'=>'manual_payment.api_payment.code_mismatch',
            default=>'manual_payment.api_payment.status_review',
        });
    }
    return match($state) {
        'monitoring'=>achbotMessage('manual_payment.api_payment.current_monitoring'),
        'reserved','proven'=>achbotMessage('manual_payment.api_payment.current_verifying'),
        'dispatched','uncertain'=>achbotMessage('manual_payment.api_payment.status_uncertain'),
        'manual','review'=>achbotMessage('manual_payment.api_payment.status_review'),
        default=>$pay['state']==='processing'?achbotMessage('manual_payment.api_payment.status_review'):null,
    };
}

/** Preserve real manual decisions and their context, not synthetic success
 * or an unconditional review-only keyboard. Rejection has a stricter
 * persisted evidence-operation guard than the idempotent manual credit. */
function achbotApiPaymentReviewKeyboard(mysqli $db,array $pay,array $a,?string $label,bool $actionable): ?string {
    $hash=(string)$pay['hash_id'];$id=(int)$pay['id'];
    $rows=[];
    if ($a['wallet_credit_entry_id']===null &&
        in_array($pay['state'],['have_sent','service_superseded'],true) &&
        achbotManualAdmittedEvidence($pay,$a)!==null) {
        $rows=json_decode(buildAdminKeyboardWithStats($db,achbotManualApprovalCallbackData($pay),
            'declineAction_'.$hash,$hash,(int)$pay['user_id']),true,32,JSON_THROW_ON_ERROR)['inline_keyboard'];
        if (achbotManualReceiptEvidenceOperationExists($db,(int)$a['id'])) {
            $rows[0]=array_values(array_filter($rows[0],static fn(array $button):bool=>
                $button['callback_data']!=='declineAction_'.$hash));
        }
    }
    if ($actionable) $rows[]=[['text'=>$label??achbotMessage('automatic_gateway_review.open_case'),
        'callback_data'=>'attentionOpenCase_'.$id]];
    elseif ($label!==null) $rows[]=[['text'=>$label,'callback_data'=>'noop_'.$hash]];
    return $rows?inlineKeyboard($rows):null;
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
        require_once __DIR__.'/CheckoutAdmission.php';
        achbotCheckoutRequireSchema($db);
        require_once __DIR__.'/ManualReceipt.php';
        try {
            $status=achbotManualReceiptCheckApi($db,$id);
            $pay=achbotApiPaymentRow($db,$id);
            if ($status==='settled' && $pay) {
                // The API callback and polling worker both use the original
                // funded service and its durable delivery packets. No
                // payment-specific Native executor and no second credit.
                $attempt=achbotApiPaymentAttempt($db,$id);
                if ($attempt && $attempt['wallet_credit_entry_id']!==null) {
                    require_once __DIR__.'/CheckoutRoutes.php';
                    achbotCheckoutAdvance($db,$attempt['service_operation_id']===null?null:(int)$attempt['service_operation_id'],$id);
                }
            }
            if ($pay && !in_array($status,['rejected','awaiting_receipt'],true)) {
                achbotApiPaymentNotice($db,$id);
                achbotPayUxSyncMessages($db,$id);
            }
            return $status;
        } catch (Throwable $e) {
            achbotDiagnosticEvent('achbot.finance.evidence_check',['pay_id'=>$id,'reason'=>'checkout_evidence_deferred','class'=>get_class($e)],'warning');
            return 'review';
        }
    } finally {
        if ($had) $GLOBALS[$key]=$old;
        else unset($GLOBALS[$key]);
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
        require_once __DIR__.'/CheckoutAdmission.php';
        achbotCheckoutRequireSchema($db);
        $apiMethods=achbotCreditMethodSqlList('api_checker');
        $q=$db->query("SELECT a.pay_row_id,p.hash_id FROM payment_attempts a JOIN pays p ON p.id=a.pay_row_id
                WHERE a.evidence_profile IS NOT NULL AND a.provider IN ($apiMethods)
                  AND a.invoice_expires_at IS NOT NULL AND a.wallet_invoice_sha256 IS NOT NULL AND a.wallet_invoice_sha256<>'' AND a.wallet_credit_entry_id IS NULL
                  AND (a.evidence_next_check_at IS NULL OR a.evidence_next_check_at<=NOW())
                  AND (a.evidence_state='proven' OR
                       (p.state<>'declined' AND (a.evidence_state IS NULL OR a.evidence_state NOT IN ('invalid_code','manual')) AND
                         ((p.state IN ('have_sent','service_superseded') AND a.stage='review') OR EXISTS (SELECT 1 FROM payment_transactions t WHERE t.attempt_id=a.id AND t.evidence_operation IS NOT NULL))))
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
            $id=(int)$row['pay_row_id'];
            try {
                achbotApiPaymentCheck($db,$id);
            } catch (Throwable $e) {
                achbotDiagnosticEvent('achbot.finance.evidence_check',['pay_id'=>$id,
                    'reason'=>'maintenance_check_deferred','class'=>get_class($e)],'warning');
            } finally {
                // Pre-claim failures and busy leases may leave a row due.
                // Rotate without overriding provider backoff or evidence.
                $stamp=achbotProviderStatement($db,"UPDATE payment_attempts
                    SET evidence_next_check_at=DATE_ADD(NOW(),INTERVAL 60 SECOND),updated_at=updated_at
                    WHERE pay_row_id=? AND wallet_credit_entry_id IS NULL
                      AND (evidence_next_check_at IS NULL OR evidence_next_check_at<=NOW())",'i',[$id]);
                $stamp->close();$checked++;
            }
        }
        // UI convergence is independent of evidence polling/finality/leases.
        $q=$db->query("SELECT a.pay_row_id,p.hash_id FROM payment_attempts a JOIN pays p ON p.id=a.pay_row_id
            WHERE a.evidence_profile IS NOT NULL AND a.provider IN ($apiMethods)
              AND a.invoice_expires_at IS NOT NULL AND a.wallet_invoice_sha256 IS NOT NULL AND a.wallet_invoice_sha256<>''
              AND a.wallet_credit_entry_id IS NULL AND p.state<>'declined'
            ORDER BY CASE WHEN p.state='paid'
                    AND (a.evidence_projection_checked_at IS NULL OR
                         a.updated_at>a.evidence_projection_checked_at)
                THEN 0 ELSE 1 END,
                a.evidence_projection_checked_at IS NOT NULL,
                a.evidence_projection_checked_at,a.id LIMIT {$limit}");
        foreach($q->fetch_all(MYSQLI_ASSOC) as $row){
            $id=(int)$row['pay_row_id'];
            try {
                $c=achbotApiPaymentContext($db,(string)$row['hash_id']);
                if (!$c) {achbotDiagnosticEvent('achbot.finance.evidence_projection',['pay_id'=>$id,'reason'=>'original_context_unavailable'],'warning');continue;}
                $edited+=achbotApiPaymentNotice($db,$id);
            } catch (Throwable $e) {
                achbotDiagnosticEvent('achbot.finance.evidence_projection',['pay_id'=>$id,
                    'reason'=>'projection_deferred','class'=>get_class($e)],'warning');
            } finally {
                // Scheduling stamp, not a successful delivery assertion.
                $stamp=achbotProviderStatement($db,'UPDATE payment_attempts SET evidence_projection_checked_at=NOW(),updated_at=updated_at WHERE pay_row_id=?','i',[$id]);
                $stamp->close();
            }
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
