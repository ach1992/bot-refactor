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

/** Strictly bounded per-instance financial UX policy editor. */
function achbotPaymentPolicyFields(): array {
    return [
        'paymentDraftMinutes'=>['label'=>\achbotMessage('admin_settings.config.payment_policy_fields.text.ca36f51ec1'),'default'=>30,'min'=>1,'max'=>intdiv(PHP_INT_MAX-time(),120)],
        'paymentManualHours'=>['label'=>\achbotMessage('admin_settings.config.payment_policy_fields.text.9bd7de27ab'),'default'=>6,'min'=>1,'max'=>intdiv(PHP_INT_MAX-time(),7200)],
        'paymentHostedHours'=>['label'=>\achbotMessage('admin_settings.config.payment_policy_fields.text.7763855ebb'),'default'=>6,'min'=>1,'max'=>intdiv(PHP_INT_MAX-time(),7200)],
        'paymentReceiptGraceMinutes'=>['label'=>\achbotMessage('admin_settings.config.payment_policy_fields.receipt_grace'),'default'=>15,'min'=>1,'max'=>intdiv(PHP_INT_MAX-time(),120)],
        'paymentSmsReviewHours'=>['label'=>\achbotMessage('admin_settings.config.payment_policy_fields.sms_review'),'default'=>24,'min'=>1,'max'=>intdiv(PHP_INT_MAX-time(),7200)],
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
        if ($attempt) { $db->rollback(); return false; }

        $overdue = (int)($pay['draft_expires_at']??0)>0 && $now >= (int)$pay['draft_expires_at'];

        // Source of truth for newer-draft ordering is pays.id, allocated
        // inside the shared owner lock for every customer-draft INSERT.
        // request_date was computed BEFORE that lock and is only an idle TTL;
        // combining the two can leave TWO payable drafts after serial commits.
        // Every committed same-scope replacement is permanent: a later
        // expired/declined/mismatched quote must NOT revive an older link.
        $userId = (int)$pay['user_id'];
        $family = achbotPayUxPaymentFamily((string)$pay['type']);
        $orderId = (int)($pay['order_id'] ?? 0);
        $stmt = $db->prepare(
            "SELECT id FROM pays
             WHERE user_id=? AND SUBSTRING_INDEX(type,'_',2)=? AND id>?
               AND COALESCE(order_id,0)=?
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->bind_param('isii', $userId, $family, $payId, $orderId);
        if (!$stmt->execute()) throw new RuntimeException('draft_successor_unavailable');
        $newer = $stmt->get_result()->fetch_assoc();
        $stmt->close();
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
function achbotPayUxInsertDraft(mysqli $db, int $actor, mysqli_stmt $insert, array $pricingPolicy, bool $scopedAddon = false, array $display = [], array $states = []): int {
    $started=false;
    try {
        if (!$db->begin_transaction()) throw new RuntimeException('draft_begin_failed');
        $started=true;
        achbotPayUxLockDraftOwner($db,$actor);
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
                !achbotSupportsPlan($db,(int)$pay['plan_id'],0,(int)$pay['server_id'],true))
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
    return is_string($attempt['wallet_invoice_sha256']??null) &&
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
        achbotPayUxInvoiceDeadline($a)<=0 || $a['wallet_credit_entry_id']!==null ||
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
    $owner=achbotProviderRow($db,'SELECT user_id FROM pays WHERE id=?','i',[$payId]);
    if (!$owner) return false;
    try {
        if (!$db->begin_transaction()) return false;
        [$pay,$a]=achbotCreditInvoiceLocked($db,$payId,(int)$owner['user_id']);
        $changed=achbotPayUxCloseInvoiceLocked($db,$pay,$a,$clock);
        if (!$db->commit()) throw new RuntimeException('invoice_deadline_commit_uncertain');
        return $changed;
    } catch (Throwable $error) {
        try {$db->rollback();} catch (Throwable $ignored) {}
        error_log('achbot_invoice_expiry_deferred pay_id='.$payId.' reason='.$error->getMessage());
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
            !in_array($pay['state'],['pending','expired','service_superseded'],true) ||
            (string)($pay['payid']??'')!=='' || !achbotCurrentReceiptPin($a) ||
            achbotPayUxInvoiceDeadline($a)<=0 || $a['provider']!==$method ||
            !in_array($a['stage'],['pending','receiving'],true) ||
            !achbotManualIntentDecode((string)$a['checkout_url'],$method) ||
            ($a['evidence_profile']!==null && !achbotPaymentEvidenceSnapshot($a))) {
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

/** Register only messages successfully sent by the bot to the actual pay owner. */
function achbotPayUxTrackMessage(mysqli $db, int $payId, int $chatId,
                                 $telegramResult, string $kind = 'text'): bool {
    if ($payId <= 0 || $chatId <= 0 ||
        !achbotPayUxTable($db, 'payment_message_refs')) return false;
    $messageId = 0;
    if (is_object($telegramResult) && ($telegramResult->ok ?? false) === true) {
        $messageId = (int)($telegramResult->result->message_id ?? 0);
    } elseif (is_array($telegramResult) && ($telegramResult['ok'] ?? false) === true) {
        $messageId = (int)($telegramResult['result']['message_id'] ?? 0);
    }
    if ($messageId <= 0 || !in_array($kind,['text','media'],true)) return false;
    try {
        $stmt = $db->prepare(
            "INSERT INTO payment_message_refs (pay_row_id,chat_id,message_id,kind)
             SELECT id,?, ?,? FROM pays WHERE id=? AND user_id=?
             ON DUPLICATE KEY UPDATE synced_digest=payment_message_refs.synced_digest"
        );
        $stmt->bind_param('iisii', $chatId, $messageId, $kind, $payId, $chatId);
        $ok = $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        return $ok === true && $affected === 1;
    } catch (Throwable $ignored) {
        error_log('achbot_payment_message_registration_deferred pay_id='.$payId.
            ' code='.(int)$ignored->getCode().' class='.get_class($ignored));
        return false;
    }
}

/** Map an already-confirmed order hash to its exact current pays row. */
function achbotPayUxTrackByHash(mysqli $db, string $hash, int $chatId,
                                $telegramResult = null, int $clickedMessageId = 0): void {
    if ($chatId <= 0 || !preg_match('/^[A-Za-z0-9_-]{1,50}$/D',$hash)) return;
    try {
        $stmt=$db->prepare('SELECT id FROM pays WHERE hash_id=? AND user_id=? LIMIT 1');
        $stmt->bind_param('si',$hash,$chatId);
        $stmt->execute();$row=$stmt->get_result()->fetch_assoc();$stmt->close();
        if (!$row) return;
        if ($clickedMessageId>0) {
            achbotPayUxTrackClicked($db,(int)$row['id'],$chatId,$clickedMessageId);
        } else {
            achbotPayUxTrackMessage($db,(int)$row['id'],$chatId,$telegramResult);
        }
    } catch (Throwable $ignored) {}
}

/** A clicked, owner-verified bot message is another safe recoverable reference. */
function achbotPayUxTrackClicked(mysqli $db, int $payId, int $chatId, int $messageId): void {
    if ($payId <= 0 || $chatId <= 0 || $messageId <= 0 ||
        !achbotPayUxTable($db,'payment_message_refs')) return;
    try {
        $stmt = $db->prepare(
            "INSERT INTO payment_message_refs (pay_row_id,chat_id,message_id,kind)
             SELECT id,?,?,'text' FROM pays WHERE id=? AND user_id=?
             ON DUPLICATE KEY UPDATE synced_digest=payment_message_refs.synced_digest"
        );
        $stmt->bind_param('iiii', $chatId, $messageId, $payId, $chatId);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $ignored) {}
}

/**
 * Conservative semantic projection from durable local facts. 'paid' by itself
 * NEVER proves that Marzban delivery or a top-up effect was completed.
 */
function achbotPayUxState(array $pay, ?array $attempt): string {
    $state = (string)($pay['state'] ?? '');
    $stage = (string)($attempt['stage'] ?? '');
    // A service-change intent invalidates the old invoice's product claim
    // without asserting its external funds were unpaid or refunded.
    if ($state === 'service_superseded') return 'service_superseded';
    if ($state === 'expired' || $state === 'mismatched_plan' ||
        $state === 'expired_have_sent') return 'expired';
    if ($state === 'declined') return 'declined';
    if (in_array($state, ['paid','approved','auto_cart_approved',
                          'paid_with_wallet'],true)) return 'payment_recorded';
    if ($state === 'have_sent' || $stage === 'receiving') return 'receipt_review';
    if ($stage === 'review' || $stage === 'checking' || $stage === 'fulfilling' ||
        $stage === 'creating') return 'needs_review';
    // Issued/manual quotes have a DB-created timestamp independent of the
    // original unissued draft; read their age from the SQL server clock.
    $provider=(string)($attempt['provider']??'');

    if (achbotPayUxManualProvider($provider) && $stage==='pending') {
        if (!achbotPayUxReceiptOpen($attempt)) return 'expired';
        if ((string)($attempt['provider_status']??'')==='receipt_started')
            return 'awaiting_receipt';
    }
    // An unpaid hosted checkout remains awaiting payment regardless of age.
    // Time passing is not evidence of funds or provider unavailability.
    if (achbotPayUxHostedProvider($provider) && $stage==='pending' &&
        in_array((string)($attempt['provider_status']??''),
            ['','waiting','pending','confirming'],true))
        return achbotPayUxInvoiceOpen($attempt)?'awaiting_payment':'expired';
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
    $state = achbotPayUxState($pay,$attempt);
    $subject = function_exists('payTypeToDescription')
        ? (string)payTypeToDescription((string)$pay['type'])
        : \achbotMessage('payment.payment_lifecycle.pay_ux_status_text.text.df83eb82dd');
    $title = match ($state) {
        'service_superseded' => \achbotMessage('payment.payment_lifecycle.pay_ux_status_text.service_superseded_title'),
        'expired' => \achbotMessage('payment.payment_lifecycle.pay_ux_status_text.text.a1026d7b85'),
        'superseded' => \achbotMessage('payment.payment_lifecycle.pay_ux_status_text.text.74adf2adfe'),
        'declined' => \achbotMessage('payment.payment_lifecycle.pay_ux_status_text.text.f8f1a7f2f5'),
        'receipt_review' => \achbotMessage('payment.payment_lifecycle.pay_ux_status_text.text.0a0cf2fd64'),
        'awaiting_receipt' => \achbotMessage('payment.payment_lifecycle.pay_ux_status_text.text.8c253d0486'),
        'needs_review' => \achbotMessage('payment.payment_lifecycle.pay_ux_status_text.text.aa6e41205d'),
        'payment_recorded' => \achbotMessage('payment.payment_lifecycle.pay_ux_status_text.text.7873970550'),
        default => \achbotMessage('payment.payment_lifecycle.pay_ux_status_text.text.c7ccf032ac'),
    };
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
        'receipt_review' => \achbotMessage('payment.payment_lifecycle.pay_ux_status_text.text.d5b12a8ef5'),
        'payment_recorded' => \achbotMessage('payment.payment_lifecycle.pay_ux_status_text.text.368ad58967'),
        'needs_review' => \achbotMessage('payment.payment_lifecycle.pay_ux_status_text.text.f1092775b2'),
        default => \achbotMessage('payment.payment_lifecycle.pay_ux_status_text.text.5e55076dd5'),
    };
    return "<b>".$safe($title)."</b>\n".achbotPayUxReference($pay)
        .\achbotMessage('payment.payment_lifecycle.pay_ux_status_text.text.d251ffde03').$safe($subject).\achbotMessage('payment.payment_lifecycle.pay_ux_status_text.text.20714482be', ['amount' => $amount]).$safe($next).achbotPayUxDeadlineText($attempt??[]);
}

function achbotPayUxReceiptPrefixes(array $pay,string $method): array {
    require_once __DIR__.'/CheckoutAdmission.php';
    $family=match(achbotCheckoutMode($pay)) {
        'standard'=>['Buy','Cart'],'custom'=>['Custom','Cart'],'renew'=>['Renew','Cart'],
        'day'=>['IncreaseDay',''],'volume'=>['IncreaseVolume',''],'topup'=>['IncreaseWallet',''],
        default=>null,
    };
    if (!$family || !achbotPayUxManualProvider($method)) return [];
    [$name,$bank]=$family;
    $suffix=match($method) {'gift_card'=>'Gift','manual_usdt','manual_trx'=>'Usdt',default=>$bank};
    return ['send'.$name.$suffix.'Receipt','cancel'.$name.$bank.'Receipt'];
}

/** Invoice deadline is an absolute time, never a new destination or quote. */
function achbotPayUxDeadlineText(array $attempt): string {
    $deadline=achbotPayUxInvoiceDeadline($attempt);
    if ($deadline<=0) return '';
    $date=(new DateTimeImmutable('@'.$deadline))->setTimezone(new DateTimeZone('Asia/Tehran'));
    return "\n".achbotMessageHtml('payment.payment_lifecycle.invoice_deadline',['deadline'=>$date->format('Y-m-d H:i').' (IR)']);
}

function achbotPayUxStatusKeyboard(string $hash,?array $pay=null,?array $attempt=null): string {
    $rows = [];
    if (preg_match('/^[A-Za-z0-9_-]{1,50}$/D',$hash)) {
        if ($pay && $attempt && achbotCurrentReceiptPin($attempt) &&
            achbotPayUxInvoiceDeadline($attempt)>0 &&
            in_array($pay['state'],['pending','expired','service_superseded'],true) &&
            in_array($attempt['stage'],['pending','receiving'],true) &&
            ($prefixes=achbotPayUxReceiptPrefixes($pay,(string)$attempt['provider']))) {
            $late=$pay['state']!=='pending' || !achbotPayUxInvoiceOpen($attempt);
            $rows[]=[['text'=>achbotMessage($late?'payment.payment_lifecycle.previous_payment_review':
                ($attempt['provider']==='gift_card'?'payment.api_payment.code':'telegram.markup.receipt_and_cancel.button.ae15b282fd')),
                'callback_data'=>$prefixes[0].$hash]];
        }
        $rows[] = [['text'=>\achbotMessage('payment.payment_lifecycle.pay_ux_status_keyboard.button.9e25b02e86'),'callback_data'=>'ps_'.$hash]];
    }
    $rows[] = [['text'=>\achbotMessage('payment.payment_lifecycle.pay_ux_status_keyboard.button.1121ffbf88'),'callback_data'=>preg_match('/^[A-Za-z0-9_-]{1,50}$/D',$hash)?'ph_'.$hash:'mainMenu']];
    return json_encode(['inline_keyboard'=>$rows],JSON_UNESCAPED_UNICODE);
}

/**
 * A Telegram no-op response is an acknowledgement that the requested text
 * AND keyboard are already present. A markup-only no-op is not text proof.
 */
function achbotPayUxEditAccepted($reply): bool {
    return is_object($reply) && (($reply->ok ?? false) === true ||
        ((int)($reply->error_code ?? 0) === 400 &&
         is_string($reply->description ?? null) &&
         str_contains(strtolower($reply->description), 'message is not modified')));
}

/** Stable, non-sensitive Telegram failure categories. Missing originals
 * remain retained and unsynced; a new message stays immediately eligible.
 */
function achbotPayUxEditFailureCategory($reply): string {
    if (!is_object($reply))return 'transport_unavailable';
    $http=(int)($reply->error_code??0);
    $description=strtolower(is_string($reply->description??null)?$reply->description:'');
    if ($http===400 && str_contains($description,'message to edit not found'))return 'message_missing';
    if ($http===400 && (str_contains($description,"message can't be edited") ||
        str_contains($description,'message can not be edited')))return 'message_not_editable';
    if ($http===403)return 'access_denied';
    if ($http===429)return 'rate_limited';
    if ($http>=500)return 'telegram_server_error';
    return 'telegram_rejected';
}

/**
 * Best-effort Telegram clean-up: each registered payment message is rewritten
 * to its current status, or at minimum has its old clickable URL removed.
 * A failed edit never rewinds or changes a financial transition.
 */
function achbotPayUxSyncMessages(mysqli $db, int $payId): int {
    if ($payId <= 0 || !function_exists('bot') ||
        !achbotPayUxTable($db,'payment_message_refs')) return 0;
    try {
        $stmt = $db->prepare('SELECT * FROM pays WHERE id=? LIMIT 1');
        $stmt->bind_param('i',$payId);
        $stmt->execute();
        $pay=$stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$pay) return 0;
        $attempt=null;
        if (achbotPayUxTable($db,'payment_attempts')) {
            $stmt=$db->prepare('SELECT *
                FROM payment_attempts WHERE pay_row_id=? LIMIT 1');
            $stmt->bind_param('i',$payId);$stmt->execute();
            $attempt=$stmt->get_result()->fetch_assoc()?:null;$stmt->close();
        }
        if (function_exists('achbotApiPaymentContext')) {
            $api=achbotApiPaymentContext($db,(string)$pay['hash_id']);
            if ($api && in_array($pay['state'],['pending','have_sent'],true)) {
                achbotApiPaymentSyncInvoice($db,$api);return 0;
            }
        }
        try { $pay['superseded']=achbotPayUxHasNewer($db,$pay); }
        catch (Throwable $ignored) { $pay['superseded']=true; }
        $state=achbotPayUxState($pay,$attempt);
        // Still-live payment CTAs must not be needlessly removed.
        if ($state==='awaiting_payment') return 0;
        $text=achbotPayUxStatusText($pay,$attempt);
        $keyboard=achbotPayUxStatusKeyboard((string)$pay['hash_id'],$pay,$attempt);
        $digest=hash('sha256',$state."\n".$text."\n".$keyboard);
        $stmt=$db->prepare('SELECT id,chat_id,message_id,kind,synced_digest
                            FROM payment_message_refs WHERE pay_row_id=?
                              AND updated_at<=NOW(6)
                              AND (synced_digest IS NULL OR synced_digest<>?)
                            ORDER BY updated_at ASC,id ASC LIMIT 10');
        $stmt->bind_param('is',$payId,$digest);$stmt->execute();
        $messages=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
        $changed=0;
        foreach ($messages as $item) {
            if ((string)($item['synced_digest'] ?? '')===$digest) continue;
            $chat=(int)$item['chat_id'];$message=(int)$item['message_id'];
            if ($chat <= 0 || $chat !== (int)$pay['user_id'] || $message <= 0) {
                // Never edit a foreign/bad reference; rotate it out so other
                // messages remain eligible rather than starving indefinitely.
                $rowId=(int)$item['id'];
                // Keep corrupt/foreign references, but do not spin on them.
                $stmt=$db->prepare('UPDATE payment_message_refs
                                    SET updated_at=DATE_ADD(NOW(6),INTERVAL 3 MINUTE)
                                    WHERE id=? AND pay_row_id=?');
                $stmt->bind_param('ii',$rowId,$payId);
                $stmt->execute();$stmt->close();
                continue;
            }
            // Text first changes the instructions AND removes stale provider URL.
            $ok=false;
            $fullTextEdited=false;
            $transportFailed=false;
            $fullEditResponse=null;
            if ($item['kind']==='text') {
                try {
                    $res=bot('editMessageText',[
                        'chat_id'=>$chat,'message_id'=>$message,
                        'text'=>$text,'parse_mode'=>'HTML',
                        'reply_markup'=>$keyboard,
                        'disable_web_page_preview'=>true
                    ]);
                    $fullEditResponse=$res;
                    $ok=achbotPayUxEditAccepted($res);
                    $fullTextEdited=$ok;
                    $transportFailed=$res===null || $res===false;
                } catch (Throwable $ignored) { $transportFailed=true; }
            }
            if (!$ok && $item['kind']==='media') {
                try {
                    $res=bot('editMessageCaption',[
                        'chat_id'=>$chat,'message_id'=>$message,
                        'caption'=>$text,'parse_mode'=>'HTML',
                        'reply_markup'=>$keyboard
                    ]);
                    $fullEditResponse=$res;
                    $ok=achbotPayUxEditAccepted($res);
                    $fullTextEdited=$ok;
                    $transportFailed=$res===null || $res===false;
                } catch (Throwable $ignored) { $transportFailed=true; }
            }
            // A failed transport affects markup too; do not double-hit it.
            if (!$ok && !$transportFailed) {
                try {
                    $res=bot('editMessageReplyMarkup',[
                        'chat_id'=>$chat,'message_id'=>$message,
                        'reply_markup'=>$keyboard,
                    ]);
                    $ok=is_object($res) && ($res->ok ?? false)===true;
                } catch (Throwable $ignored) {}
            }
            $failureClass=achbotPayUxEditFailureCategory($fullEditResponse);
            if (!$fullTextEdited) {
                // A successful markup-only fallback does NOT prove the old
                // text was updated. Keep that failure visible, with safe
                // per-reference diagnostics instead of a generic warning.
                $tgCode=is_object($fullEditResponse)
                    ?max(0,min(599,(int)($fullEditResponse->error_code??0))):0;
                $kind=$item['kind']==='media'?'media':'text';
                error_log('achbot_payment_telegram_edit_deferred pay_id='.$payId.
                    ' ref_id='.(int)$item['id'].' kind='.$kind.
                    ' category='.$failureClass.' tg_code='.$tgCode.
                    ' markup_only='.($ok?'1':'0'));
                // Even a successful markup-only action is not a truthful text
                // synchronization. Preserve the reference, but delay an
                // unchanged failure for three minutes to avoid a Telegram
                // request storm. A new reference is eligible immediately.
                // This scheduler already owns updated_at ordering; a future
                // value is a retry-not-before hint, not proof of delivery.
                $rowId=(int)$item['id'];
                $retrySeconds=in_array($failureClass,
                    ['message_missing','message_not_editable','access_denied'],true)
                    ?6*3600:3*60;
                $stmt=$db->prepare('UPDATE payment_message_refs
                                    SET updated_at=DATE_ADD(NOW(6),INTERVAL ? SECOND)
                                    WHERE id=? AND pay_row_id=?');
                $stmt->bind_param('iii',$retrySeconds,$rowId,$payId);
                $stmt->execute();$stmt->close();
                continue;
            }
            // Only stamp after an acknowledged full text update.
            $rowId=(int)$item['id'];
            $stmt=$db->prepare(
                'UPDATE payment_message_refs SET synced_digest=?,updated_at=NOW(6)
                 WHERE id=? AND pay_row_id=?'
            );
            $stmt->bind_param('sii',$digest,$rowId,$payId);
            $stmt->execute();$stmt->close();
            ++$changed;
        }
        return $changed;
    } catch (Throwable $error) {
        error_log('achbot_payment_message_sync_deferred pay_id='.$payId);
        return 0;
    }
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
                   AND (p.payid IS NULL OR p.payid='')
                   AND NOT EXISTS (
                       SELECT 1 FROM payment_attempts a WHERE a.pay_row_id=p.id
                   )
                   AND p.draft_expires_at IS NOT NULL
                   AND (p.draft_expires_at<=? OR EXISTS (
                       SELECT 1 FROM pays n WHERE n.user_id=p.user_id
                         AND SUBSTRING_INDEX(n.type,'_',2)=SUBSTRING_INDEX(p.type,'_',2)
                         AND n.id>p.id
                         AND COALESCE(n.order_id,0)=COALESCE(p.order_id,0)
                   ))
                 ORDER BY p.request_date ASC LIMIT {$maxRows}"
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
                     WHERE r.updated_at<=NOW(6) AND r.synced_digest IS NULL
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
                 WHERE updated_at<=NOW(6) AND synced_digest IS NULL
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
                     WHERE updated_at<=NOW(6)
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
                     WHERE pay_row_id=? AND updated_at<=NOW(6)'
                );
                $stmt->bind_param('i',$id);$stmt->execute();$stmt->close();
            }
        } catch (Throwable $ignored) {
            error_log('achbot_payment_message_batch_deferred');
        }
    }
    return ['expired'=>$expired,'edited'=>$updated];
}
