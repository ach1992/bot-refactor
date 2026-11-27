<?php
/**
 * AChBot #51: guarded, source-only payment message lifecycle.
 *
 * No payment/provider/credit/fulfillment operation may depend on Telegram edits.
 * The optional additive 006 registry is installed only after its own approval.
 */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../messages/loader.php'; // ACHBOT_TEXT_CATALOG
require_once __DIR__.'/../Diagnostics/EventLog.php';
require_once __DIR__.'/../Telegram/Response.php';

function achbotPayUxTable(mysqli $db, string $name): bool {
    if (!in_array($name, ['payment_attempts','payment_transactions',
                          'payment_message_refs'], true)) return false;
    try {
        $result = $db->query("SHOW TABLES LIKE '".$name."'");
        return $result instanceof mysqli_result && $result->num_rows === 1;
    } catch (Throwable $ignored) {
        return false;
    }
}

/** Per-instance policy. Deadline limits reserve arithmetic headroom for the pinned grace/search window. */
function achbotPaymentPolicyFields(?int $clock=null): array {
    $remaining=PHP_INT_MAX-($clock??time());
    return [
        'paymentDraftMinutes'=>['label'=>\achbotMessage('admin_settings.config.payment_policy_fields.text.ca36f51ec1'),'default'=>30,'min'=>1,'max'=>intdiv($remaining,120)],
        'paymentManualHours'=>['label'=>\achbotMessage('admin_settings.config.payment_policy_fields.text.9bd7de27ab'),'default'=>6,'min'=>1,'max'=>intdiv($remaining,7200)],
        'paymentHostedHours'=>['label'=>\achbotMessage('admin_settings.config.payment_policy_fields.text.7763855ebb'),'default'=>6,'min'=>1,'max'=>intdiv($remaining,7200)],
        'paymentReceiptGraceMinutes'=>['label'=>\achbotMessage('admin_settings.config.payment_policy_fields.receipt_grace'),'default'=>15,'min'=>1,'max'=>intdiv($remaining,120)],
        'paymentSmsReviewHours'=>['label'=>\achbotMessage('admin_settings.config.payment_policy_fields.sms_review'),'default'=>24,'min'=>1,'max'=>intdiv($remaining,7200)],
        'receiptApprovedMinutes'=>['label'=>\achbotMessage('admin_settings.config.payment_policy_fields.text.99467f1d89'),'default'=>10,'min'=>1,'max'=>1440],
        'receiptApprovedOtherMinutes'=>['label'=>\achbotMessage('admin_settings.config.payment_policy_fields.text.c23b59a9e3'),'default'=>60,'min'=>1,'max'=>1440],
        'receiptReviewMinutes'=>['label'=>\achbotMessage('admin_settings.config.payment_policy_fields.text.7b27bba920'),'default'=>360,'min'=>1,'max'=>1440],
        'receiptDeclinedMinutes'=>['label'=>\achbotMessage('admin_settings.config.payment_policy_fields.text.5fea8ef738'),'default'=>3,'min'=>1,'max'=>1440],
    ];
}
/** One validated policy value for the editor and runtime. */
function achbotPaymentPolicyValue(string $field,$raw): ?int {
    $definition=achbotPaymentPolicyFields()[$field]??null;
    if ($definition===null || (!is_int($raw) && !is_string($raw)) ||
        !preg_match('/^[0-9]+$/D',(string)$raw) || filter_var($raw,FILTER_VALIDATE_INT)===false) return null;
    $value=(int)$raw;
    return $value>=$definition['min'] && $value<=$definition['max']?$value:null;
}
function achbotPayUxPolicyNumber(string $field,?array $states=null): int {
    $definition=achbotPaymentPolicyFields()[$field]??null;
    if ($definition===null) throw new InvalidArgumentException('payment_policy_unknown');
    $states=$states??($GLOBALS['botState']??[]);
    return achbotPaymentPolicyValue($field,is_array($states)?($states[$field]??null):null)??$definition['default'];
}
function achbotPayUxDraftDeadlineSeconds(?array $states=null): int {
    return 60*achbotPayUxPolicyNumber('paymentDraftMinutes',$states);
}
function achbotPayUxManualDeadlineSeconds(?array $states=null): int {
    return 3600*achbotPayUxPolicyNumber('paymentManualHours',$states);
}
function achbotPayUxHostedAttentionSeconds(?array $states=null): int {
    return 3600*achbotPayUxPolicyNumber('paymentHostedHours',$states);
}

/** Seal once on the original issuer transaction, before publishing a claim.
 * The deadline is local admission policy, never evidence of provider payment. */
function achbotPayUxSealInvoiceLocked(mysqli $db,array $attempt,?array $states=null): array {
    if (($attempt['invoice_expires_at']??null)!==null) return $attempt;
    if (($attempt['wallet_invoice_sha256']??null)!=='')
        throw new RuntimeException('invoice_deadline_not_new');
    $aid=(int)$attempt['id'];
    $row=achbotProviderRow($db,'SELECT UNIX_TIMESTAMP(created_at) AS issued FROM payment_attempts WHERE id=? FOR UPDATE','i',[$aid]);
    $issued=(int)($row['issued']??0);
    if ($issued<=0) throw new RuntimeException('invoice_deadline_anchor_missing');
    $manual=achbotPayUxManualProvider((string)$attempt['provider']);
    $duration=$manual?achbotPayUxManualDeadlineSeconds($states):achbotPayUxHostedAttentionSeconds($states);
    $deadline=$issued+$duration;
    $grace=$manual?60*achbotPayUxPolicyNumber('paymentReceiptGraceMinutes',$states):0;
    $q=achbotProviderStatement($db,'UPDATE payment_attempts SET invoice_expires_at=?,receipt_grace_seconds=?
        WHERE id=? AND invoice_expires_at IS NULL AND wallet_invoice_sha256=\'\'','iii',[$deadline,$grace,$aid]);
    $n=$q->affected_rows;$q->close();
    if ($n!==1) throw new RuntimeException('invoice_deadline_write_failed');
    $attempt['invoice_expires_at']=$deadline;
    $attempt['receipt_grace_seconds']=$grace;
    return $attempt;
}
function achbotPayUxInvoiceDeadline(array $attempt): int {
    return (int)($attempt['invoice_expires_at']??0);
}
function achbotPayUxReceiptDeadline(array $attempt): int {
    $deadline=achbotPayUxInvoiceDeadline($attempt);
    $start=(int)($attempt['receipt_started_at']??0);
    // Only the first click BEFORE expiry can extend receipt upload, never
    // the transfer window, and never shorten a still-open original invoice.
    return $start>0 && $start<$deadline
        ? max($deadline,$start+(int)($attempt['receipt_grace_seconds']??0))
        : $deadline;
}
function achbotPayUxInvoiceOpen(array $attempt,?int $clock=null): bool {
    return achbotPayUxInvoiceDeadline($attempt)>($clock??time());
}
/** An already-issued hosted invoice may expose only its original guarded link. */
function achbotPayUxHostedLinkOpen(array $pay,array $attempt,?int $clock=null): bool {
    return ($pay['state']??null)==='pending' && empty($pay['superseded']) &&
        ($attempt['stage']??null)==='pending' && achbotCurrentReceiptPin($attempt) &&
        achbotPayUxHostedProvider((string)($attempt['provider']??'')) &&
        empty($attempt['wallet_credit_entry_id']) && empty($attempt['payment_id']) &&
        in_array($attempt['provider_status']??null,[null,'','waiting','pending'],true) &&
        achbotPayUxInvoiceOpen($attempt,$clock);
}
function achbotPayUxHostedLink(string $method,string $hash,string $botUrl): ?string {
    require_once __DIR__.'/ReceiptMethods.php';
    $definition=achbotCreditMethodDefinition($method)['hosted']??null;
    if (!$definition || !preg_match('/^[A-Za-z0-9_-]{1,50}$/D',$hash)) return null;
    try { achbotHostedCallbackBase($botUrl); } catch (Throwable $ignored) { return null; }
    return rtrim($botUrl,'/').'/pay/checkout.php?'.$definition['credential_setting'].'&hash_id='.rawurlencode($hash);
}
function achbotPayUxReceiptOpen(array $attempt,?int $clock=null): bool {
    return achbotPayUxReceiptDeadline($attempt)>($clock??time());
}
function achbotPayUxManualProvider(string $provider): bool {
    require_once __DIR__.'/ReceiptMethods.php';
    return achbotCreditMethodHas($provider,'manual_quote');
}
function achbotPayUxHostedProvider(string $provider): bool {
    require_once __DIR__.'/ReceiptMethods.php';
    return achbotCreditMethodHas($provider,'hosted');
}

/**
 * Expire ONE unissued, unclaimed draft; serialize against the existing provider
 * reservation by locking pays first, then checking its attempt under the lock.
 * A newer active draft supersedes older, same-user/same-type/same-target drafts.
 * Never delete pays; never erase financial evidence or pending provider claims.
 */
function achbotPayUxExpireDraft(mysqli $db, int $payId, ?int $clock = null): bool {
    // Partial 003 cannot establish that an invoice was never issued.
    if ($payId <= 0 || !achbotPayUxTable($db,'payment_attempts') ||
        !achbotPayUxTable($db,'payment_transactions')) return false;
    $now = $clock ?? time();
    $started = false;
    try {
        if (!$db->begin_transaction()) return false;
        $started = true;
        $stmt = $db->prepare(
            "SELECT id,user_id,type,order_id,request_date,state,payid,draft_expires_at
             FROM pays WHERE id=? FOR UPDATE"
        );
        $stmt->bind_param('i', $payId);
        if (!$stmt->execute()) throw new RuntimeException('draft_lock_unavailable');
        $pay = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$pay || $pay['state'] !== 'pending' ||
            (string)($pay['payid'] ?? '') !== '' || (int)$pay['user_id'] <= 0 || (int)($pay['draft_expires_at']??0)<=0) {
            $db->rollback(); return false;
        }
        $stmt = $db->prepare(
            "SELECT id FROM payment_attempts WHERE pay_row_id=? LIMIT 1 FOR UPDATE"
        );
        $stmt->bind_param('i', $payId);
        if (!$stmt->execute()) throw new RuntimeException('draft_attempt_unavailable');
        $attempt = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($attempt || !achbotPayUxDraftWithoutEvidence($db,$payId,(int)$pay['user_id'])) {
            $db->rollback(); return false;
        }

        $overdue = (int)($pay['draft_expires_at']??0)>0 && $now >= (int)$pay['draft_expires_at'];

        // Source of truth for newer-draft ordering is pays.id, allocated
        // inside the shared owner lock for every customer-draft INSERT.
        // request_date was computed BEFORE that lock and is only an idle TTL;
        // combining the two can leave TWO payable drafts after serial commits.
        // Every committed same-scope replacement is permanent: a later
        // expired/declined/mismatched quote must NOT revive an older link.
        $newer = achbotPayUxHasNewer($db,$pay);
        if (!$overdue && !$newer) { $db->rollback(); return false; }

        $stmt = $db->prepare(
            "UPDATE pays SET state='expired',updated_at=NOW()
             WHERE id=? AND state='pending' AND (payid IS NULL OR payid='')
             AND NOT EXISTS (SELECT 1 FROM payment_attempts a
                             WHERE a.pay_row_id=pays.id)"
        );
        $stmt->bind_param('i', $payId);
        if (!$stmt->execute()) throw new RuntimeException('draft_expiry_failed');
        $changed = $stmt->affected_rows;
        $stmt->close();
        if ($changed !== 1 || !$db->commit()) {
            throw new RuntimeException('draft_expiry_commit_uncertain');
        }
        return true;
    } catch (Throwable $error) {
        if ($started) {
            try { $db->rollback(); } catch (Throwable $ignored) {}
        }
        error_log('achbot_payment_draft_expiry_deferred pay_id='.$payId);
        return false; // fail closed: never promise the draft was cancelled.
    }
}

/**
 * Lock the owning user before any unclaimed-draft INSERT or hosted-provider
 * reservation. users.userid is historically indexed but NOT unique: refuse
 * missing/ambiguous identity. Never perform a remote operation under this lock.
 *
 * Lock order: users(userid) -> pays(id) -> payment_attempts(pay_row_id).
 * Replacement INSERTs hold the SAME owner lock until COMMIT, preventing
 * phantom successor drafts between a reservation's recheck and COMMIT.
 */
function achbotPayUxLockDraftOwner(mysqli $db, int $userId): void {
    if ($userId <= 0) throw new RuntimeException('draft_owner_invalid');
    // The historical userid index is non-unique. Preserve the exact-owner
    // identity check, but never let a small-table optimizer choose an ALL
    // locking scan that X-locks other customers before checkout starts.
    $stmt=$db->prepare('SELECT id FROM users FORCE INDEX (idx_users_userid_v1) WHERE userid=? FOR UPDATE');
    if (!$stmt) throw new RuntimeException('draft_owner_lock_unavailable');
    $stmt->bind_param('i',$userId);
    if (!$stmt->execute()) throw new RuntimeException('draft_owner_lock_failed');
    $rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    if (count($rows)!==1) throw new RuntimeException('draft_owner_missing_or_ambiguous');
}

/** Execute a NEW customer draft under the same lock held by all issuers. */
function achbotPayUxInsertDraft(mysqli $db, int $actor, mysqli_stmt $insert, array $pricingPolicy, bool $scopedAddon = false, array $display = [], array $states = [], ?array $editor = null): int {
    $started=false;
    try {
        if (!$db->begin_transaction()) throw new RuntimeException('draft_begin_failed');
        $started=true;
        achbotPayUxLockDraftOwner($db,$actor);
        $editorRow=null;
        if ($editor!==null) {
            require_once __DIR__.'/../Telegram/Conversation.php';
            $editorRow=\AchBot\Telegram\Conversation\lockEditor($db,$actor,$editor);
        }
        if (!$insert->execute() || $insert->affected_rows!==1)
            throw new RuntimeException('draft_insert_failed');
        $draftId=(int)$insert->insert_id;
        require_once __DIR__.'/../Panel/ProviderRuntime.php';
        achbotProviderPinPayment($db,$draftId);
        // Stale buttons cannot issue new invoices on disabled/deleted servers.
        $s=$db->prepare('SELECT * FROM pays WHERE id=? FOR UPDATE');
        $s->bind_param('i',$draftId);$s->execute();$pay=$s->get_result()->fetch_assoc();$s->close();
        if (!$pay) throw new RuntimeException('draft_service_missing');
        $type=(string)$pay['type'];
        $addon=preg_match('/^INCREASE_(?:DAY|VOLUME)_([1-9][0-9]*)_[1-9][0-9]*$/D',$type,$match)===1;
        if ($type==='RENEW_ACCOUNT' || $addon) {
            $orderId=$addon?(int)$match[1]:(int)$pay['order_id'];
            // The issued invoice must belong to this owner and currently
            // selected service. The current add-on type and order_id must agree.
            if ($orderId<=0 || (int)$pay['user_id']!==$actor ||
                ($addon && (int)$pay['order_id']!==$orderId) ||
                achbotProviderPaymentSubjectServer($db,$pay,true)!==(int)$pay['server_id'])
                throw new RuntimeException('draft_service_identity_changed');
            require_once __DIR__.'/../Subscription/PhysicalService.php';
            require_once __DIR__.'/../Services/Ownership.php';
            // Scope the current locking read to the same Native identity as
            // funding and plan changes, including aliases. Unrelated journals
            // never participate in this draft's admission.
            $lease=achbotPhysicalLockName($db,['id'=>$orderId,'server_id'=>(int)$pay['server_id']],true);
            if (achbotServicePlanChangePendingLocked($db,$lease))
                throw new RuntimeException('draft_native_journal_unresolved');
        }
        if ($type!=='INCREASE_WALLET') {
            require_once __DIR__.'/../Panel/ProviderRuntime.php';
            $buy=(string)$pay['type']==='BUY_SUB';
            if (!achbotServerCommerceAvailable($db,(int)$pay['server_id'],$buy,true))
                throw new RuntimeException('draft_server_disabled');
            if ($buy &&
                !achbotSupportsPlan($db,(int)$pay['plan_id'],(int)$pay['server_id'],true))
                throw new RuntimeException('draft_new_plan_unavailable');
        }
        if ($scopedAddon) {
            require_once __DIR__.'/../Plans/Addons.php';
            if (!achbotAddonDraftCurrent($db,$draftId))
                throw new RuntimeException('addon_draft_scope_or_price_changed');
        }
        // The route calculated its amount from server-owned state. Reject
        // a changed representative entitlement/percentage before publishing
        // that amount, under the same owner and settings locks.
        require_once __DIR__.'/../Purchase/AgentPricing.php';
        $pricingOwner=achbotProviderRow($db,'SELECT is_agent,discount_percent FROM users FORCE INDEX (idx_users_userid_v1) WHERE userid=? FOR UPDATE','i',[$actor]);
        $pricingState=achbotPayUxCurrentStatesLocked($db);
        if ($pricingOwner===null || $pricingState===null ||
            achbotAgencyQuotePolicy($pricingOwner,$pricingState,(int)$pay['plan_id'],(int)$pay['server_id'])!==$pricingPolicy)
            throw new DomainException('draft_agency_pricing_changed');
        if ($type==='RENEW_ACCOUNT' || $addon) {
            require_once __DIR__.'/../Purchase/OrderWrites.php';
            $order=achbotProviderRow($db,'SELECT * FROM orders_list WHERE id=? FOR UPDATE','i',[$orderId]);
            $kind=$type==='RENEW_ACCOUNT'?'RENEW':(str_starts_with($type,'INCREASE_DAY_')?'ADD_TIME':'ADD_VOLUME');
            if (!$order || (int)$order['userid']!==$actor ||
                achbotManagedServicePlan($db,$order,$kind,$pricingState,true)===null)
                throw new DomainException('draft_managed_service_unavailable');
        }
        require_once __DIR__.'/CheckoutAdmission.php';
        achbotCheckoutRequireSchema($db);
        achbotCheckoutSealDraftLocked($db,$draftId,$pay,$display,$states);
        if ($editorRow!==null)
            \AchBot\Telegram\Conversation\writeEditorLocked($db,$editorRow,'none',null);
        if ($draftId<=0 || !$db->commit())
            throw new RuntimeException('draft_commit_unknown');
        return $draftId;
    } catch (Throwable $error) {
        $diagnostic=achbotDiagnosticLocalFailure($error);
        if ($diagnostic['reason']==='draft_insert_failed' && $insert->errno>0)
            $diagnostic['sql_errno']=$insert->errno;
        if ($started) {try{$db->rollback();}catch(Throwable $ignored){}}
        achbotDiagnosticEvent('achbot.finance.draft_creation_deferred',[
            'actor_id'=>$actor,'pay_id'=>$draftId??0,
            'server_id'=>(int)($pay['server_id']??0),'order_id'=>(int)($pay['order_id']??0)
        ]+$diagnostic,'warning');
        // A lost COMMIT ack is ambiguous. The caller must not retry this
        // potentially committed invoice or advertise an unverified hash.
        return 0;
    }
}

/** A payment family groups only related recurring price/quantity variants. */
function achbotPayUxPaymentFamily(string $type): string {
    return implode('_',array_slice(explode('_',$type),0,2));
}

/** A later COMMITTED payment draft makes an older *UI link* stale.
 * ID order is authoritative only because same-owner draft INSERTs acquire
 * the users row lock before allocating an ID and hold it through COMMIT.
 * request_date is NOT an ordering tie-breaker; it is an independent TTL.
 * The successor row's later terminal state never reactivates old links.
 * Issued provider invoices remain claims and are NEVER auto-cancelled.
 */
function achbotPayUxHasNewer(mysqli $db, array $pay, bool $lockingRead=false): bool {
    $payId=(int)($pay['id']??0);$userId=(int)($pay['user_id']??0);
    if ($payId<=0 || $userId<=0) return false;
    try {
        $family=achbotPayUxPaymentFamily((string)($pay['type']??''));
        $orderId=(int)($pay['order_id']??0);
        // On 10.11 the optimizer can choose an ALL scan for a small
        // historical pays table. FOR UPDATE would then X-lock unrelated
        // customers and invert concurrent wallet/hosted checkout ordering.
        // Owner-row serialization already prevents same-owner phantom
        // draft insertion; require the existing user_id secondary index for
        // the CURRENT locking read so it never scans the whole pays table.
        $indexHint=$lockingRead ? ' FORCE INDEX (idx_pays_user_v1)' : '';
        $stmt=$db->prepare(
            "SELECT id FROM pays".$indexHint." WHERE user_id=? AND SUBSTRING_INDEX(type,'_',2)=? AND id>?
               AND COALESCE(order_id,0)=?
             LIMIT 1".($lockingRead ? ' FOR UPDATE' : '')
        );
        $stmt->bind_param('isii',$userId,$family,$payId,$orderId);
        if (!$stmt->execute()) throw new RuntimeException('successor_read_failed');
        $row=$stmt->get_result()->fetch_assoc();$stmt->close();
        return (bool)$row;
    } catch (Throwable $ignored) {
        // The checkout URL must fail closed if newer status is unreadable.
        throw new RuntimeException('payment_successor_check_unavailable');
    }
}

/**
 * Call only with a freshly locked pays row when selecting a NEW payment
 * method or debiting a wallet. Existing manual/provider claims must use their
 * separate evidence/reconciliation path, never be invalidated by this check.
 */
function achbotCurrentReceiptPin(array $attempt): bool {
    return achbotPayUxInvoiceDeadline($attempt)>0 && is_string($attempt['wallet_invoice_sha256']??null) &&
        preg_match('/^[a-f0-9]{64}$/D',$attempt['wallet_invoice_sha256'])===1;
}

function achbotPayUxCurrentDraft(array $pay): bool {
    $context=json_decode((string)($pay['checkout_draft_context']??''),true);
    return is_array($context) && ($context['version']??null)===1;
}

function achbotPayUxUnclaimedDraftUsable(mysqli $db, array $pay,
                                          bool $lockingRead=false): bool {
    if ((int)($pay['id']??0)<=0 ||
        !in_array((string)($pay['state']??''),['pending','send'],true) ||
        (string)($pay['payid']??'')!=='' ||
        (int)($pay['draft_expires_at']??0)<=time()) {
        return false;
    }
    try {
        return !achbotPayUxHasNewer($db,$pay,$lockingRead);
    } catch (Throwable $ignored) {
        return false;
    }
}

/**
 * Close an unfunded local request while retaining its immutable invoice.
 * Caller holds owner, pay and attempt locks. Recorded receipt/proof and
 * funded work are not cancelled by time. Late verified money has the same
 * credit owner as every other receipt.
 */
function achbotPayUxCloseInvoiceLocked(mysqli $db,array &$pay,array $a,?int $clock=null): bool {
    if ($pay['state']!=='pending' || !achbotCurrentReceiptPin($a) ||
         $a['wallet_credit_entry_id']!==null ||
        !in_array($a['stage'],['pending','review'],true)) return false;
    if (!hash_equals($a['wallet_invoice_sha256'],achbotCreditInvoiceFingerprint($pay,$a)))
        throw new RuntimeException('invoice_deadline_original_pin_changed');
    $manual=achbotPayUxManualProvider((string)$a['provider']);
    if ($manual) {
        if ($a['stage']!=='pending' || achbotPayUxReceiptOpen($a,$clock) ||
            (int)($pay['message_id']??0)>0 || $a['review_notice_claim_token']!==null ||
            !empty($a['evidence_code'])) return false;
        $intent=achbotManualIntentDecode((string)$a['checkout_url'],(string)$a['provider']);
        if (!$intent || !empty($intent['receipt_file_id'])) return false;
    } elseif (!achbotPayUxHostedProvider((string)$a['provider']) || achbotPayUxInvoiceOpen($a,$clock)) return false;
    // Any durable provider evidence gets its own reconciliation, regardless
    // of the UI timeout. An idle waiting child is not a recorded payment.
    $evidence=achbotProviderRow($db,"SELECT id FROM payment_transactions WHERE attempt_id=?
        AND (evidence_operation IS NOT NULL OR observed_status IN ('partially_paid','confirming','confirmed','sending','finished'))
        LIMIT 1 FOR UPDATE",'i',[(int)$a['id']]);
    if ($evidence) return false;
    if (!$manual && in_array($a['provider_status']??null,['partially_paid','confirming','confirmed','sending','finished'],true))
        return false;
    $operation=(int)($a['service_operation_id']??0);
    if ($operation>0) {
        require_once __DIR__.'/../Services/Funding.php';
        [$op]=achbotServiceReadLocked($db,(int)$pay['user_id'],$operation);
        if (!in_array($op['stage'],['awaiting_funds','cancelled'],true)) return false;
        achbotServiceCancelUnfundedLocked($db,(int)$pay['user_id'],$operation);
    }
    $q=achbotProviderStatement($db,"UPDATE pays SET state='expired',updated_at=NOW() WHERE id=? AND state='pending'",
        'i',[(int)$pay['id']]);
    $n=$q->affected_rows;$q->close();
    if ($n!==1) throw new RuntimeException('invoice_deadline_close_race');
    $pay['state']='expired';
    return true;
}

/** The one local expiry transition is shared with click/receipt/settlement. */
function achbotPayUxExpireInvoice(mysqli $db,int $payId,?int $clock=null): bool {
    require_once __DIR__.'/FundingCheckout.php';
    require_once __DIR__.'/ReceiptHelpers.php';
    try {
        $owner=achbotProviderRow($db,'SELECT user_id FROM pays WHERE id=?','i',[$payId]);
        if (!$owner) return false;
        if (!$db->begin_transaction()) return false;
        [$pay,$a]=achbotCreditInvoiceLocked($db,$payId,(int)$owner['user_id']);
        $changed=achbotPayUxCloseInvoiceLocked($db,$pay,$a,$clock);
        if (!$db->commit()) throw new RuntimeException('invoice_deadline_commit_uncertain');
        return $changed;
    } catch (Throwable $error) {
        try {$db->rollback();} catch (Throwable $ignored) {}
        achbotDiagnosticEvent('achbot.finance.invoice_expiry_deferred',
            ['pay_id'=>$payId]+achbotDiagnosticLocalFailure($error),'warning');
        return false;
    }
}

/** A first timely receipt click grants only the pinned upload grace.
 * The same original receipt action accepts late proof, not a new payment. */
function achbotPayUxStartManualReceipt(mysqli $db,int $payId,string $method,int $actor,?string $expectedMode=null): bool {
    require_once __DIR__.'/CheckoutAdmission.php';
    if ($payId<=0 || $actor<=0 || !achbotPayUxManualProvider($method)) return false;
    try {
        if (!$db->begin_transaction()) return false;
        [$pay,$a]=achbotCreditInvoiceLocked($db,$payId,$actor);
        if (($expectedMode!==null && !achbotCheckoutModeMatchesRequest($pay,$expectedMode)) ||
            !achbotManualReceiptEligible($pay,$a,$method)) {
            $db->rollback();return false;
        }
        $now=time();
        achbotPayUxCloseInvoiceLocked($db,$pay,$a,$now);
        if ($a['stage']==='pending') {
            $start=$pay['state']==='pending' && achbotPayUxInvoiceOpen($a,$now)?$now:null;
            $q=achbotProviderStatement($db,"UPDATE payment_attempts SET provider_status='receipt_started',
                receipt_started_at=COALESCE(receipt_started_at,?) WHERE id=? AND stage='pending'
                AND (provider_status IS NULL OR provider_status='receipt_started')",'ii',[$start,(int)$a['id']]);
            $q->close();
            if (!in_array($a['provider_status'],[null,'receipt_started'],true)) {
                $db->rollback();return false;
            }
        }
        if (!$db->commit()) throw new RuntimeException('manual_click_commit_uncertain');
        return true;
    } catch (Throwable $error) {
        try {$db->rollback();} catch (Throwable $ignored) {}
        error_log('achbot_manual_receipt_click_deferred pay_id='.$payId);
        return false;
    }
}

function achbotPayUxHostedAttentionElapsed(mysqli $db,int $payId): bool {
    $a=achbotProviderRow($db,'SELECT provider,invoice_expires_at FROM payment_attempts WHERE pay_row_id=?','i',[$payId]);
    return $a && achbotPayUxHostedProvider((string)$a['provider']) && !achbotPayUxInvoiceOpen($a);
}

/**
 * Payment audience policy is an *additional* owner restriction, never a
 * replacement for payment state, global on/off flags, authorization or proof.
 * A missing policy means legacy 'all'; invalid explicit policy FAILS CLOSED.
 */
require_once __DIR__.'/GatewayRules.php';
function achbotPayUxAudienceModes(): array {
    return ['all'=>\achbotMessage('payment.payment_lifecycle.pay_ux_audience_modes.text.15270886ea'),'agents'=>\achbotMessage('payment.payment_lifecycle.pay_ux_audience_modes.text.452a2d6fed'),'non_agents'=>\achbotMessage('payment.payment_lifecycle.pay_ux_audience_modes.text.8b3e917f90'),
            'buyers'=>\achbotMessage('payment.payment_lifecycle.pay_ux_audience_modes.text.446e7017e1'),'non_buyers'=>\achbotMessage('payment.payment_lifecycle.pay_ux_audience_modes.text.711a7d07d3'),
            'has_active_order'=>\achbotMessage('payment.payment_lifecycle.pay_ux_audience_modes.text.08f38edd68'),
            'no_active_order'=>\achbotMessage('payment.payment_lifecycle.pay_ux_audience_modes.text.3310f2a1e3'),'off'=>\achbotMessage('payment.payment_lifecycle.pay_ux_audience_modes.text.29dbbef63d')];
}
function achbotPayUxAudienceMethods(): array {
    return [
        'nowpayments'=>\achbotMessage('payment.payment_lifecycle.pay_ux_audience_methods.n_o_w_payments.83db3cb125'),'zarinpal'=>\achbotMessage('payment.payment_lifecycle.pay_ux_audience_methods.text.7d213c0bc4'),'nextpay'=>\achbotMessage('payment.payment_lifecycle.pay_ux_audience_methods.text.d5f4cc2d6e'),
        'bank_transfer'=>\achbotMessage('payment.payment_lifecycle.pay_ux_audience_methods.text.64c20e2b45'),'gift_card'=>\achbotMessage('payment.payment_lifecycle.pay_ux_audience_methods.text.3904ef0f3d'),
        'manual_usdt'=>\achbotMessage('payment.payment_lifecycle.pay_ux_audience_methods.text.4fafcb549d'),'manual_trx'=>\achbotMessage('payment.payment_lifecycle.pay_ux_audience_methods.text.349ece736f'),'wallet'=>\achbotMessage('payment.payment_lifecycle.pay_ux_audience_methods.text.6295c35212')
    ];
}
/** Current exact settings row under the existing owner/setting lock order.
 * Call only inside the caller's admission transaction. */
function achbotPayUxCurrentStatesLocked(mysqli $db): ?array {
    return achbotPayUxCurrentDocumentLocked($db,'BOT_STATES');
}
function achbotPayUxCurrentDocumentLocked(mysqli $db,string $type): ?array {
    $stmt=$db->prepare("SELECT id FROM setting
        WHERE type=? ORDER BY id LIMIT 2");
    if (!$stmt || !$stmt->bind_param('s',$type) || !$stmt->execute())
        throw new RuntimeException('audience_settings_locate_failed');
    $ids=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    if (count($ids)!==1) return null;
    $id=(int)$ids[0]['id'];
    $stmt=$db->prepare("SELECT type,value FROM setting
        WHERE id=? LOCK IN SHARE MODE");
    if (!$stmt) throw new RuntimeException('audience_settings_lock_prepare');
    $stmt->bind_param('i',$id);
    if (!$stmt->execute())
        throw new RuntimeException('audience_settings_lock_failed');
    $row=$stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row || $row['type']!==$type) return null;
    $states=json_decode((string)$row['value'],true);
    return is_array($states)?$states:null;
}

/** Single method flag owner for presentation and locked admission. */
function achbotPayUxMethodFlag(string $method,?string $subjectType): ?string {
    return match($method) {
        'wallet'=>'walletState',
        'bank_transfer'=>'cartToCartState',
        'gift_card'=>'giftCardState',
        'manual_usdt','manual_trx'=>'manualUsdtState',
        'zarinpal'=>'zarinpal',
        'nextpay'=>'nextpay',
        'nowpayments'=>$subjectType===null || $subjectType===''?null:
            ($subjectType==='INCREASE_WALLET'?'nowPaymentWallet':'nowPaymentOther'),
        default=>null
    };
}

/**
 * $final is permitted only inside an already-open owner-locked financial
 * transaction. It uses CURRENT locking reads of BOT_STATES and the cohort.
 * Request-global $botState is deliberately only a menu/preview snapshot.
 * A missing/duplicate/malformed persisted policy fails closed at issuance.
 */
function achbotPayUxAudienceAllowed(mysqli $db, string $method, int $userId,
                                     bool $final = false,
                                     ?string $subjectType = null): bool {
    try {
        $states=$final?achbotPayUxCurrentStatesLocked($db):($GLOBALS['botState']??[]);
        return is_array($states) && achbotPayUxAudienceAllowedInState($db,$method,$userId,$states,$final,$subjectType);
    } catch (Throwable $error) {
        error_log('achbot_gateway_audience_guard_deferred method='.$method.
            ' code='.(int)$error->getCode().' class='.get_class($error));
        return false;
    }
}

/** A shared snapshot is supplied only by a caller owning the current
 * settings lock when $final is true; never pass request state for admission. */
function achbotPayUxAudienceAllowedInState(mysqli $db,string $method,int $userId,array $states,
    bool $final=false,?string $subjectType=null): bool {
    if ($userId<=0 || !array_key_exists($method,achbotPayUxAudienceMethods())) return false;
    try {
        // Gateway availability is independent from the audience rule.
        // Only a final financial authorization requires persisted flags;
        // preview/menu checks remain presentation-only.
        if ($final) {
            $primaryFlag=achbotPayUxMethodFlag($method,$subjectType);
            if ($primaryFlag===null || ($states[$primaryFlag]??null)!=='on')
                return false;
        }
        // New rule documents are versioned and strictly validated. A missing
        // method retains its exact legacy audience policy.
        $rules=null;
        if (array_key_exists('gatewayRules',$states)) {
            $rules=achbotGatewayRulesDecode($states['gatewayRules']);
            if ($rules===null) return false;
        }
        $exempt=achbotGatewayRuleExemptUsersDecode(
            array_key_exists('gatewayRuleExemptUsers',$states)
                ? $states['gatewayRuleExemptUsers'] : []);
        if ($exempt===null) return false;
        if (in_array($userId,$exempt[$method]??[],true)) {
            // Explicit policy exemption never grants an ambiguous/nonexistent
            // customer access or revives a disabled gateway flag.
            $stmt=$db->prepare('SELECT id FROM users'.
                ($final?' FORCE INDEX (idx_users_userid_v1)':'').
                ' WHERE userid=? LIMIT 2'.($final?' FOR UPDATE':''));
            if (!$stmt) throw new RuntimeException('exempt_identity_prepare');
            $stmt->bind_param('i',$userId);
            if (!$stmt->execute()) throw new RuntimeException('exempt_identity_read');
            $people=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
            return count($people)===1;
        }
        if ($rules!==null && array_key_exists($method,$rules['methods']))
            return achbotGatewayRulesAllow($db,$userId,
                $rules['methods'][$method],$final);
        $configured=array_key_exists('gatewayAudience',$states)
            ? $states['gatewayAudience'] : [];
        if (!is_array($configured)) return false;
        $mode=array_key_exists($method,$configured) ? $configured[$method] : 'all';
        if (!is_string($mode) || !array_key_exists($mode,achbotPayUxAudienceModes()))
            return false;
        if ($mode==='off') return false;
        if ($mode==='all') return true;
        // Definitive current reads must remain indexed by customer, even on
        // small MariaDB 10.11 tables where a FOR UPDATE ALL scan is legal.
        // Keep preview queries unchanged; the owner was already locked.
        $usersIndex=$final ? ' FORCE INDEX (idx_users_userid_v1)' : '';
        $stmt=$db->prepare('SELECT id,is_agent FROM users'.$usersIndex.' WHERE userid=? LIMIT 2'.
                          ($final?' FOR UPDATE':''));
        if (!$stmt) throw new RuntimeException('audience_user_prepare_failed');
        $stmt->bind_param('i',$userId);
        if (!$stmt->execute()) throw new RuntimeException('audience_user_lookup');
        $people=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
        if (count($people)!==1) return false;
        if ($mode==='agents') return (int)$people[0]['is_agent']===1;
        if ($mode==='non_agents') return (int)$people[0]['is_agent']!==1;
        if ($mode==='buyers' || $mode==='non_buyers') {
            $purchased=achbotCustomerHistoryCount(
                $db,$userId,'buy',0,'older',$final,time());
            return $purchased!==null && ($purchased>0)===($mode==='buyers');
        }
        $now=time();
        $ordersIndex=$final ? ' FORCE INDEX (idx_orders_userid_v1)' : '';
        $stmt=$db->prepare("SELECT id FROM orders_list".$ordersIndex." WHERE userid=?
            AND status=1 AND (expire_date=0 OR expire_date>?) LIMIT 1".($final?' FOR UPDATE':''));
        if (!$stmt) throw new RuntimeException('audience_orders_prepare_failed');
        $stmt->bind_param('ii',$userId,$now);
        if (!$stmt->execute()) throw new RuntimeException('audience_orders_lookup');
        $hasOrder=$stmt->get_result()->fetch_assoc()!==null;$stmt->close();
        return $mode==='has_active_order' ? $hasOrder : !$hasOrder;
    } catch (Throwable $error) {
        error_log('achbot_gateway_audience_guard_deferred method='.$method.
            ' code='.(int)$error->getCode().' class='.get_class($error));
        return false;
    }
}

require_once __DIR__.'/PaymentMessages.php';

/** One positive absence predicate for an unissued draft. The owner row
 * and original payment coordinate financial admission; presentation must
 * not treat contradictory journal/receipt evidence as a disposable screen.
 * The fixed alias p is the original pays row in the caller's SQL statement. */
function achbotPayUxDraftWithoutEvidenceSql(): string {
    return "p.state IN ('pending','send','expired')
        AND p.checkout_operation_id IS NULL
        AND (p.payid IS NULL OR p.payid='')
        AND COALESCE(p.message_id,0)=0 AND COALESCE(p.chat_id,'')=''
        AND COALESCE(p.admins_messages,'') IN ('','[]')
        AND COALESCE(p.approved_by,0)=0
        AND NOT EXISTS (SELECT 1 FROM payment_attempts a WHERE a.pay_row_id=p.id)
        AND NOT EXISTS (SELECT 1 FROM service_effects e WHERE e.pay_row_id=p.id)
        AND NOT EXISTS (SELECT 1 FROM service_operations o
            WHERE o.command_kind='checkout' AND o.command_ref=CAST(p.id AS CHAR))
        AND NOT EXISTS (SELECT 1 FROM wallet_entries w
            WHERE w.source_kind='payment_credit' AND w.source_ref=CAST(p.id AS CHAR))
        AND NOT EXISTS (SELECT 1 FROM referral_awards r WHERE r.pay_row_id=p.id)
        AND NOT EXISTS (SELECT 1 FROM payment_followup_intents f WHERE f.pay_row_id=p.id)";
}

/** An absent row is not proof. Read errors propagate to the caller's
 * recovery boundary; never turn a failed query into a successful absence. */
function achbotPayUxDraftWithoutEvidence(mysqli $db,int $id,int $actor): bool {
    if ($id<=0 || $actor<=0) return false;
    $row=achbotProviderRow($db,"SELECT p.id FROM pays p WHERE p.id=? AND p.user_id=? AND ".
        achbotPayUxDraftWithoutEvidenceSql(),'ii',[$id,$actor]);
    return $row!==null;
}

/** Observe closure and admission evidence in one SQL statement. */
function achbotPayUxUnadmittedClosed(mysqli $db,array $pay,?array $attempt): bool {
    if ($attempt!==null || !achbotPayUxCurrentDraft($pay) ||
        (int)($pay['id']??0)<=0 || (int)($pay['user_id']??0)<=0) return false;
    $row=achbotProviderRow($db,"SELECT p.id FROM pays p WHERE p.id=? AND p.user_id=?
        AND JSON_VALUE(p.checkout_draft_context,'$.version')='1'
        AND (p.state='expired' OR (p.state='pending' AND (
            (p.draft_expires_at IS NOT NULL AND p.draft_expires_at<=UNIX_TIMESTAMP())
            OR EXISTS (SELECT 1 FROM pays newer WHERE newer.user_id=p.user_id
                AND newer.id>p.id AND SUBSTRING_INDEX(newer.type,'_',2)=SUBSTRING_INDEX(p.type,'_',2)
                AND COALESCE(newer.order_id,0)=COALESCE(p.order_id,0)))))
        AND ".achbotPayUxDraftWithoutEvidenceSql(),'ii',[(int)$pay['id'],(int)$pay['user_id']]);
    return $row!==null;
}

/**
 * Conservative semantic projection from durable local facts. 'paid' by itself
 * NEVER proves that Native delivery or a top-up effect was completed.
 */
function achbotPayUxState(array $pay, ?array $attempt): string {
    if (!empty($pay['projection_unknown'])) return 'needs_review';
    $state = (string)($pay['state'] ?? '');
    $stage = (string)($attempt['stage'] ?? '');
    if ($attempt===null && ($pay['unadmitted_closed']??false)===true) return 'draft_closed';
    if ($attempt===null && ($pay['closure_unverified']??false)===true) return 'needs_review';
    // A service-change intent invalidates the old invoice's product claim
    // without asserting its external funds were unpaid or refunded.
    if ($state === 'declined') return 'declined';
    if (in_array($state, ['paid','approved','auto_cart_approved',
                          'paid_with_wallet'],true)) return 'payment_recorded';
    $provider=(string)($attempt['provider']??'');
    if ($state==='have_sent' ||
        ($state==='service_superseded' && $stage==='review' && achbotPayUxManualProvider($provider))) {
        return match($attempt['evidence_state']??null) {
            'monitoring'=>'receipt_monitoring',
            'manual','review'=>'receipt_manual',
            'invalid_code'=>'receipt_invalid',
            'dispatched','uncertain'=>'receipt_uncertain',
            'reserved','proven'=>'receipt_verifying',
            default=>'receipt_review'
        };
    }
    // Ordinary merchant progress is not an exceptional/manual-review
    // outcome, and does not re-open a hosted payment link.
    if (achbotPayUxHostedProvider($provider) && $state==='pending' &&
        in_array($stage,['pending','review'],true)) {
        $status=(string)($attempt['provider_status']??'');
        $observed=$pay['projection_hosted_observation']??null;
        if (in_array($status,['confirming','confirmed','sending'],true) &&
            ($observed===null || in_array($observed,['waiting','confirming','confirmed','sending'],true)))
            return 'hosted_confirming';
        if ($stage==='pending' && in_array($status,['','waiting','pending'],true) &&
            !empty($attempt['payment_id']) && $observed==='waiting')
            return 'hosted_waiting';
    }
    if ($stage === 'review' || $stage === 'checking' || $stage === 'fulfilling' ||
        $stage === 'creating') return 'needs_review';
    if ($state === 'service_superseded') return 'service_superseded';
    if ($state === 'expired' || $state === 'mismatched_plan' ||
        $state === 'expired_have_sent') return 'expired';
    // Issued deadlines are sealed, not recomputed from current settings.

    if (achbotPayUxManualProvider($provider) && $stage==='pending') {
        if (!achbotPayUxReceiptOpen($attempt)) return 'expired';
        if ((string)($attempt['provider_status']??'')==='receipt_started')
            return 'awaiting_receipt';
    }
    if (achbotPayUxHostedProvider($provider) && $stage==='pending') {
        if (!empty($attempt['payment_id']) ||
            !in_array($attempt['provider_status']??null,[null,'','waiting','pending'],true))
            return 'needs_review';
        if (!achbotPayUxInvoiceOpen($attempt)) return 'expired';
        return achbotPayUxHostedLinkOpen($pay,$attempt)?'awaiting_payment':'needs_review';
    }
    if (achbotPayUxManualProvider($provider) && $stage==='expired')
        return 'expired';
    if (!in_array($state,['pending','send'],true)) return 'needs_review';
    if ($attempt===null && !empty($pay['draft_expires_at']) && (int)$pay['draft_expires_at']<=time())
        return 'expired';
    if (!empty($pay['superseded'])) {
        // A claimed provider/manual invoice is NOT financially cancelled by
        // a newer quote; keep its own outstanding claim under review.
        return $attempt===null && (string)($pay['payid']??'')===''
            ? 'superseded' : 'needs_review';
    }
    if ((string)($attempt['payment_id'] ?? '') !== '' ||
        (string)($attempt['provider_status'] ?? '') !== '') return 'needs_review';
    if ((string)($pay['payid'] ?? '') !== '' && !$attempt) return 'needs_review';
    return 'awaiting_payment';
}

/** Customer/order references must originate in an authenticated persisted pay row. */
function achbotPayUxReference(array $pay): string {
    $hash=htmlspecialchars((string)($pay['hash_id']??''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    return \achbotMessage('payment.payment_lifecycle.pay_ux_reference.label.edb6e627ea').$hash."</code>\n".
        \achbotMessage('payment.payment_lifecycle.pay_ux_reference.text.d031212b3c').(int)($pay['user_id']??0)."</code>\n";
}

/** Read-only reference lookup: the hash is a locator, never authentication. */
function achbotPayUxOwnedReference(mysqli $db, string $hash, int $actor): ?array {
    if ($actor<=0 || preg_match('/^[A-Za-z0-9_-]{1,50}$/D',$hash)!==1) return null;
    try {
        $stmt=$db->prepare('SELECT id,hash_id,user_id FROM pays WHERE hash_id=? AND user_id=? LIMIT 2');
        $stmt->bind_param('si',$hash,$actor);$stmt->execute();
        $rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
        return count($rows)===1?$rows[0]:null;
    } catch (Throwable $ignored) { return null; }
}

/** Public-facing messages cannot include a raw provider link or secret. */
function achbotPayUxStatusText(array $pay, ?array $attempt): string {
    require_once __DIR__.'/ReceiptMethods.php';
    $state = achbotPayUxState($pay,$attempt);
    if ($state==='draft_closed') return achbotMessage('payment.payment_lifecycle.draft_closed');
    $subject = function_exists('payTypeToDescription')
        ? (string)payTypeToDescription((string)$pay['type'])
        : \achbotMessage('payment.payment_lifecycle.pay_ux_status_text.text.df83eb82dd');
    $title = match ($state) {
        'service_superseded' => \achbotMessage('payment.payment_lifecycle.pay_ux_status_text.service_superseded_title'),
        'expired' => \achbotMessage('payment.payment_lifecycle.pay_ux_status_text.text.a1026d7b85'),
        'superseded' => \achbotMessage('payment.payment_lifecycle.pay_ux_status_text.text.74adf2adfe'),
        'declined' => \achbotMessage('payment.payment_lifecycle.pay_ux_status_text.text.f8f1a7f2f5'),
        'receipt_review','receipt_monitoring','receipt_manual','receipt_verifying' =>
            achbotMessage(achbotManualTypedGiftAttempt($attempt??[])?
                'payment.payment_lifecycle.gift_received':'payment.payment_lifecycle.pay_ux_status_text.text.0a0cf2fd64'),
        'receipt_invalid'=>achbotMessage('payment.payment_lifecycle.gift_not_verified'),
        'receipt_uncertain'=>achbotMessage('payment.payment_lifecycle.receipt_uncertain'),
        'hosted_waiting'=>achbotMessage('payment.payment_lifecycle.hosted_waiting'),
        'hosted_confirming'=>achbotMessage('payment.payment_lifecycle.hosted_confirming'),
        'awaiting_receipt' => \achbotMessage('payment.payment_lifecycle.pay_ux_status_text.text.8c253d0486'),
        'needs_review' => \achbotMessage('payment.payment_lifecycle.pay_ux_status_text.text.aa6e41205d'),
        'payment_recorded' => \achbotMessage('payment.payment_lifecycle.pay_ux_status_text.text.7873970550'),
        default => \achbotMessage('payment.payment_lifecycle.pay_ux_status_text.text.c7ccf032ac'),
    };
    $outcome=$pay['projection_outcome']??null;
    if ($state==='payment_recorded' && $outcome==='service_pending')
        $title=achbotMessage('payment.payment_lifecycle.request_accepted');
    elseif ($state==='payment_recorded' && $outcome==='unknown')
        $title=achbotMessage('payment.payment_lifecycle.pay_ux_status_text.text.aa6e41205d');
    $safe = static fn($v) => htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $amount = number_format((int)($pay['price'] ?? 0));
    $next = match ($state) {
        'service_superseded' => \achbotMessage('payment.payment_lifecycle.pay_ux_status_text.service_superseded_next'),
        'expired' => achbotPayUxManualProvider((string)($attempt['provider']??''))
            ? \achbotMessage('payment.payment_lifecycle.pay_ux_status_text.text.8b1195f62f')
            : \achbotMessage('payment.payment_lifecycle.pay_ux_status_text.text.90290d6783'),
        'awaiting_receipt' => \achbotMessage('payment.payment_lifecycle.pay_ux_status_text.text.4bca689fc3'),
        'superseded' => \achbotMessage('payment.payment_lifecycle.pay_ux_status_text.text.c0ee0533a8'),
        'declined' => \achbotMessage('payment.payment_lifecycle.pay_ux_status_text.text.b2948cf010'),
        'receipt_review' => achbotMessage('payment.payment_lifecycle.pay_ux_status_text.text.d5b12a8ef5'),
        'receipt_monitoring'=>achbotMessage('payment.payment_lifecycle.receipt_monitoring_next'),
        'receipt_manual'=>achbotMessage('payment.payment_lifecycle.receipt_manual_next'),
        'receipt_verifying'=>achbotMessage('payment.payment_lifecycle.receipt_verifying_next'),
        'receipt_invalid'=>achbotMessage('payment.payment_lifecycle.gift_not_verified_next'),
        'receipt_uncertain'=>achbotMessage('payment.payment_lifecycle.pay_ux_status_text.text.f1092775b2'),
        'hosted_waiting','hosted_confirming'=>achbotMessage('payment.payment_lifecycle.hosted_progress_next'),
        'payment_recorded' => \achbotMessage('payment.payment_lifecycle.pay_ux_status_text.text.368ad58967'),
        'needs_review' => \achbotMessage('payment.payment_lifecycle.pay_ux_status_text.text.f1092775b2'),
        default => \achbotMessage('payment.payment_lifecycle.pay_ux_status_text.text.5e55076dd5'),
    };
    if ($state==='payment_recorded' && $outcome!==null) {
        $next=match ($outcome) {
            'service_pending'=>achbotMessage('payment.payment_lifecycle.progress_pending'),
            'completed'=>achbotMessage('payment.payment_lifecycle.progress_completed'),
            'delivery_unconfirmed'=>achbotMessage('payment.payment_lifecycle.progress_delivery_unconfirmed'),
            'wallet_credited'=>achbotMessage('payment.payment_lifecycle.progress_wallet_credited'),
            'resolved_refunded'=>achbotMessage('payment.payment_lifecycle.progress_resolved_refunded'),
            'resolved_closed'=>achbotMessage('payment.payment_lifecycle.progress_resolved_closed'),
            default=>achbotMessage('payment.payment_lifecycle.progress_unverified'),
        };
    }
    if ($state==='awaiting_payment' && achbotPayUxHostedProvider((string)($attempt['provider']??'')))
        $next=achbotMessage('payment.payment_lifecycle.hosted_awaiting_next');
    if ($attempt!==null && (str_starts_with($state,'receipt_') || $state==='needs_review') &&
        in_array($pay['state']??null,['expired','service_superseded'],true))
        $next.="\n".achbotMessage('payment.payment_lifecycle.pay_ux_status_text.service_superseded_next');
    return "<b>".$safe($title)."</b>\n".achbotPayUxReference($pay)
        .\achbotMessage('payment.payment_lifecycle.pay_ux_status_text.text.d251ffde03').$safe($subject).\achbotMessage('payment.payment_lifecycle.pay_ux_status_text.text.20714482be', ['amount' => $amount]).$safe($next).
        ($attempt!==null && achbotCreditMethodDefinition((string)($attempt['provider']??''))!==null
            ?achbotMessage('payment.pre_payment.text.c8886e46d5').$safe(achbotCreditMethodLabel($attempt['provider'])):'').
        (in_array($state,['awaiting_payment','awaiting_receipt'],true) && achbotPayUxInvoiceOpen($attempt??[]) ? achbotPayUxDeadlineText($attempt??[]) : '');
}

/** Invoice deadline is an absolute time, never a new destination or quote. */
function achbotPayUxDeadlineText(array $attempt): string {
    $deadline=achbotPayUxInvoiceDeadline($attempt);
    if ($deadline<=0) return '';
    $date=(new DateTimeImmutable('@'.$deadline))->setTimezone(new DateTimeZone('Asia/Tehran'));
    return "\n<b>".achbotMessageHtml('payment.payment_lifecycle.invoice_deadline',['deadline'=>$date->format('Y-m-d H:i').' (IR)']).'</b>';
}

function achbotPayUxStatusKeyboard(string $hash,?array $pay=null,?array $attempt=null): string {
    $rows = [];
    $draftClosed=$pay!==null && achbotPayUxState($pay,$attempt)==='draft_closed';
    if (!$draftClosed && preg_match('/^[A-Za-z0-9_-]{1,50}$/D',$hash)) {
        require_once __DIR__.'/ReceiptHelpers.php';
        if ($pay && $attempt && empty($pay['projection_unknown']) && (string)$pay['hash_id']===$hash &&
            ($action=achbotManualReceiptAction($pay,$attempt))) $rows[]=[$action['button']];
        $hosted=$pay && $attempt && empty($pay['projection_unknown']) && achbotCurrentReceiptPin($attempt) &&
            achbotPayUxHostedProvider((string)($attempt['provider']??''));
        if ($hosted && achbotPayUxHostedLinkOpen($pay,$attempt)) {
            $url=achbotPayUxHostedLink((string)$attempt['provider'],$hash,(string)($GLOBALS['botUrl']??''));
            if ($url!==null) $rows[]=[['text'=>achbotMessage('payment.pre_payment.button.f81a8cfcdd'),'url'=>$url]];
        }
        $previous=$hosted && in_array(achbotPayUxState($pay,$attempt),['expired','service_superseded','needs_review'],true);
        $rows[] = [['text'=>achbotMessage($previous?'payment.payment_lifecycle.previous_payment_review':
            'payment.payment_lifecycle.pay_ux_status_keyboard.button.9e25b02e86'),'callback_data'=>'ps_'.$hash]];
    }
    $rows[] = [['text'=>\achbotMessage('payment.payment_lifecycle.pay_ux_status_keyboard.button.1121ffbf88'),'callback_data'=>!$draftClosed && preg_match('/^[A-Za-z0-9_-]{1,50}$/D',$hash)?'ph_'.$hash:'mainMenu']];
    return json_encode(['inline_keyboard'=>$rows],JSON_UNESCAPED_UNICODE);
}

/** Run under the existing one-minute warning job; never create a fifth Cron. */
function achbotPayUxMaintain(mysqli $db, int $maxRows=45): array {
    $maxRows=max(1,min($maxRows,100));
    $expired=0;$updated=0;
    if (achbotPayUxTable($db,'payment_attempts') &&
        achbotPayUxTable($db,'payment_transactions')) {
        try {
            $cutoff=time();
            $stmt=$db->prepare(
                "SELECT p.id FROM pays p WHERE p.state='pending'
                   AND p.user_id>0 AND ".achbotPayUxDraftWithoutEvidenceSql()."
                   AND p.draft_expires_at>0
                   AND (p.draft_expires_at<=? OR EXISTS (
                       SELECT 1 FROM pays n WHERE n.user_id=p.user_id
                         AND SUBSTRING_INDEX(n.type,'_',2)=SUBSTRING_INDEX(p.type,'_',2)
                         AND n.id>p.id
                         AND COALESCE(n.order_id,0)=COALESCE(p.order_id,0)
                   ))
                 ORDER BY p.request_date ASC,p.id LIMIT {$maxRows}"
            );
            $stmt->bind_param('i',$cutoff);$stmt->execute();
            $rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
            foreach ($rows as $r) {
                if (achbotPayUxExpireDraft($db,(int)$r['id'])) ++$expired;
            }
        } catch (Throwable $ignored) {
            error_log('achbot_payment_expiry_batch_deferred');
        }
    }
    // Local deadlines never close recorded provider evidence or receipts.
    try {
        $manualMethods=achbotCreditMethodSqlList('manual_quote');
        $hostedMethods=achbotCreditMethodSqlList('hosted');
        $stmt=$db->prepare("SELECT p.id FROM pays p JOIN payment_attempts a ON a.pay_row_id=p.id
            WHERE p.state='pending' AND a.wallet_invoice_sha256<>'' AND a.wallet_credit_entry_id IS NULL
              AND a.invoice_expires_at<=UNIX_TIMESTAMP()
              AND GREATEST(a.invoice_expires_at,IF(a.receipt_started_at<a.invoice_expires_at,
                 a.receipt_started_at+a.receipt_grace_seconds,a.invoice_expires_at))<=UNIX_TIMESTAMP()
              AND ((a.provider IN ($manualMethods) AND a.stage='pending') OR
                   (a.provider IN ($hostedMethods) AND a.stage IN ('pending','review')))
              AND (p.message_id IS NULL OR p.message_id=0)
              AND a.evidence_code IS NULL AND a.review_notice_claim_token IS NULL
              AND NOT EXISTS (SELECT 1 FROM service_operations o WHERE o.id=a.service_operation_id AND o.stage NOT IN ('awaiting_funds','cancelled'))
              AND (a.provider_status IS NULL OR a.provider_status NOT IN ('partially_paid','confirming','confirmed','sending','finished'))
              AND NOT EXISTS (SELECT 1 FROM payment_transactions t WHERE t.attempt_id=a.id
                AND (t.evidence_operation IS NOT NULL OR t.observed_status IN ('partially_paid','confirming','confirmed','sending','finished')))
            ORDER BY a.invoice_expires_at,a.id LIMIT {$maxRows}");
        $stmt->execute();$rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
        foreach ($rows as $row) if (achbotPayUxExpireInvoice($db,(int)$row['id'])) ++$expired;
    } catch (Throwable $ignored) {
        error_log('achbot_invoice_expiry_batch_deferred');
    }
    if (achbotPayUxTable($db,'payment_message_refs')) {
        // Telegram edits are network calls. Bound each Cron tick separately
        // from the 45-row local expiry scan to avoid delaying worker duties.
        $messageBatch=min($maxRows,8);
        try {
            // A large backlog of old stale receipts must not delay a newly
            // confirmed purchase for tens of Cron ticks. Reserve at most half
            // of this existing bounded budget for the newest unsynced final
            // payments; the other half retains oldest-first fairness.
            $priorityLimit=intdiv($messageBatch,2);
            $rows=[];
            if ($priorityLimit>0) {
                $stmt=$db->prepare(
                    "SELECT r.pay_row_id FROM payment_message_refs r
                     JOIN pays p ON p.id=r.pay_row_id
                     WHERE r.purpose<>'staff' AND r.retired_reason IS NULL AND r.updated_at<=NOW(6) AND r.synced_digest IS NULL
                       AND p.state IN ('approved','paid','auto_cart_approved','paid_with_wallet')
                     GROUP BY r.pay_row_id ORDER BY MAX(r.id) DESC LIMIT {$priorityLimit}"
                );
                $stmt->execute();
                $rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
            }
            // Do not spend the oldest-first recovery slots on payments whose
            // previous Telegram status was already acknowledged. Keep a small
            // always-on rotating fallback lane for later changed status even
            // under sustained unsynced traffic, without a cursor/migration.
            $stmt=$db->prepare(
                "SELECT pay_row_id FROM payment_message_refs
                 WHERE purpose<>'staff' AND retired_reason IS NULL AND updated_at<=NOW(6) AND synced_digest IS NULL
                 GROUP BY pay_row_id ORDER BY MIN(updated_at) ASC LIMIT {$messageBatch}"
            );
            $stmt->execute();
            $oldest=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
            // Keep one slot for the rotating full scan EVEN when continuous
            // unsynced traffic fills every earlier lane. Otherwise a payment
            // that changed after its last successful edit can starve forever.
            // All slots remain within the original eight-payment budget.
            $selected=[];
            foreach ($rows as $r) {
                if (count($selected)>=$priorityLimit) break;
                $id=(int)$r['pay_row_id'];
                $selected[$id]=true;
            }
            foreach ($oldest as $r) {
                if (count($selected)>=max(0,$messageBatch-1)) break;
                $id=(int)$r['pay_row_id'];
                $selected[$id]=true;
            }
            if (count($selected)<$messageBatch) {
                $stmt=$db->prepare(
                    "SELECT pay_row_id FROM payment_message_refs
                     WHERE purpose<>'staff' AND retired_reason IS NULL AND updated_at<=NOW(6)
                     GROUP BY pay_row_id ORDER BY MIN(updated_at) ASC LIMIT {$messageBatch}"
                );
                $stmt->execute();
                $fallback=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
                foreach ($fallback as $r) {
                    if (count($selected)>=$messageBatch) break;
                    $id=(int)$r['pay_row_id'];
                    $selected[$id]=true;
                }
            }
            foreach (array_keys($selected) as $id) {
                $updated+=achbotPayUxSyncMessages($db,$id);
                // Fair scanning; a failed Telegram edit remains eligible later.
                $stmt=$db->prepare(
                    'UPDATE payment_message_refs SET updated_at=NOW(6)
                     WHERE pay_row_id=? AND purpose<>\'staff\' AND retired_reason IS NULL AND updated_at<=NOW(6)'
                );
                $stmt->bind_param('i',$id);$stmt->execute();$stmt->close();
            }
        } catch (Throwable $ignored) {
            error_log('achbot_payment_message_batch_deferred');
        }
    }
    return ['expired'=>$expired,'edited'=>$updated];
}
