<?php
declare(strict_types=1);
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {http_response_code(404);exit;}
require_once __DIR__.'/../Telegram/Conversation.php';
require_once __DIR__.'/GatewayClient.php';

/**
 * The payment message registry holds per-target projection state. Staff
 * identities and immutable payment facts remain in their original ledgers.
 * Every reference has an explicit purpose; retired identities are tombstones,
 * not successful edits, and may never become projection targets again.
 */
function achbotPayUxRegister(mysqli $db,int $payId,int $actor,int $message,string $purpose,string $kind='text'): bool {
    if ($payId<=0 || $actor<=0 || $message<=0 ||
        !in_array($purpose,['selector','invoice','status','receipt'],true) ||
        !in_array($kind,['text','media'],true)) return false;
    if (!achbotPayUxTable($db,'payment_message_refs')) return false;
    try {
        $s=$db->prepare("INSERT INTO payment_message_refs (pay_row_id,chat_id,message_id,kind,purpose)
            SELECT id,?,?,?,? FROM pays WHERE id=? AND user_id=?
            ON DUPLICATE KEY UPDATE id=payment_message_refs.id");
        $s->bind_param('iissii',$actor,$message,$kind,$purpose,$payId,$actor);
        $ok=$s->execute() && $s->affected_rows===1;$s->close();
        if ($ok) return true;
        $row=achbotPayUxRef($db,$payId,$actor,$message);
        return $row!==null && $row['retired_reason']===null &&
            $row['purpose']===$purpose && $row['kind']===$kind;
    } catch (Throwable $error) {
        error_log('achbot_payment_message_registration_deferred pay_id='.$payId.
            ' code='.(int)$error->getCode().' class='.get_class($error));
        return false;
    }
}

/** Project an already recorded staff receipt, not a new send. */
function achbotPayUxStaffRef(mysqli $db,array $pay,int $chat,int $message): ?array {
    $payId=(int)$pay['id'];
    if ($payId<=0 || $chat<=0 || $message<=0) return null;
    $owned=false;
    foreach (achbotReceiptMessageTargets($db,$pay) as $target)
        if ($target['chat_id']===$chat && $target['message_id']===$message) {$owned=true;break;}
    if (!$owned) return null;
    $q=achbotProviderStatement($db,"INSERT INTO payment_message_refs(pay_row_id,chat_id,message_id,kind,purpose)
        VALUES (?,?,?,'text','staff') ON DUPLICATE KEY UPDATE id=payment_message_refs.id",
        'iii',[$payId,$chat,$message]);
    $q->close();
    return achbotProviderRow($db,"SELECT *,updated_at<=NOW(6) AS due FROM payment_message_refs
        WHERE pay_row_id=? AND chat_id=? AND message_id=? AND purpose='staff'",'iii',[$payId,$chat,$message]);
}

function achbotPayUxTrackMessage(mysqli $db,int $payId,int $actor,$response,string $purpose,string $kind='text'): bool {
    $id=\AchBot\Telegram\Conversation\acknowledgedMessage($response,$actor);
    return $id!==null && achbotPayUxRegister($db,$payId,$actor,$id,$purpose,$kind);
}

function achbotPayUxTrackByHash(mysqli $db,string $hash,int $actor,$response,string $purpose): void {
    $pay=achbotPayUxOwnedReference($db,$hash,$actor);
    if ($pay) achbotPayUxTrackMessage($db,(int)$pay['id'],$actor,$response,$purpose);
}

function achbotPayUxRef(mysqli $db,int $payId,int $actor,int $message): ?array {
    if ($payId<=0 || $actor<=0 || $message<=0) return null;
    $s=$db->prepare('SELECT r.*,r.updated_at<=NOW(6) AS due FROM payment_message_refs r JOIN pays p ON p.id=r.pay_row_id
        WHERE r.pay_row_id=? AND r.chat_id=? AND r.message_id=? AND p.user_id=? AND r.purpose<>\'staff\'');
    $s->bind_param('iiii',$payId,$actor,$message,$actor);
    $s->execute();$row=$s->get_result()->fetch_assoc();$s->close();
    return $row?:null;
}

/** Recover a failed registration only from an actual, current bot-authored
 * private callback carrying this exact action. Never infer ownership of an
 * arbitrary clicked message or revive an already retired identity. */
function achbotPayUxSelectorRef(mysqli $db,int $payId,int $actor,int $message,$update): ?array {
    $row=achbotPayUxRef($db,$payId,$actor,$message);
    if ($row!==null) return $row['retired_reason']===null && $row['purpose']==='selector'?$row:null;
    if (\AchBot\Telegram\Conversation\verifiedCallback($update,$actor,$message) &&
        achbotPayUxRegister($db,$payId,$actor,$message,'selector'))
        return achbotPayUxRef($db,$payId,$actor,$message);
    return null;
}

/** The source of a recovery click is retained rather than overwritten
 * when its original presentation is not known. */
function achbotPayUxRecoveryRef(mysqli $db,int $payId,int $actor,int $message,$update): ?array {
    $row=achbotPayUxRef($db,$payId,$actor,$message);
    if ($row) return $row['retired_reason']===null?$row:null;
    if (!\AchBot\Telegram\Conversation\verifiedCallback($update,$actor,$message) ||
        !achbotPayUxRegister($db,$payId,$actor,$message,'receipt')) return null;
    return achbotPayUxRef($db,$payId,$actor,$message);
}

/** An explicit status recovery send has one durable claim per update.
 * A replay of the same delivery cannot blindly resend on an unknown ACK.
 * A newer user click remains a recovery action, not an automatic retry.
 * Telegram may restart update numbering after a week without updates;
 * a long-idle reference can accept the next authenticated update epoch. */
function achbotPayUxClaimStatusRequest(mysqli $db,array $ref,int $updateId): bool {
    if ($updateId<=0) return false;
    $id=(int)$ref['id'];
    $s=$db->prepare('UPDATE payment_message_refs SET last_status_update_id=?,last_status_requested_at=NOW(6),updated_at=updated_at
        WHERE id=? AND retired_reason IS NULL AND (last_status_update_id IS NULL OR last_status_update_id<?
            OR last_status_requested_at<DATE_SUB(NOW(6),INTERVAL 7 DAY))');
    $s->bind_param('iii',$updateId,$id,$updateId);$ok=$s->execute() && $s->affected_rows===1;$s->close();
    return $ok;
}

/** All changes of semantic ownership and transport effects for a payment
 * share its existing database-scoped session lease. No SQL transaction
 * is held across a Telegram request. */
function achbotPayUxWithLease(mysqli $db,int $payId,callable $action,$busy=null) {
    $lease=achbotOriginalDispatchLeaseBegin($db,$payId);
    if (!in_array($lease,['owned','borrowed'],true)) return $busy;
    try {return $action();}
    finally {achbotOriginalDispatchLeaseEnd($db,$payId,$lease);}
}

/** Under the original payment lease, a selector is disposable only
 * until an actual admission exists. Recover the committed invoice/status
 * rather than losing its last projection on a stale Back/Cancel. */
function achbotPayUxRetireNavigation(mysqli $db,array $ref): bool {
    $pay=(int)$ref['pay_row_id'];$actor=(int)$ref['chat_id'];
    if ($ref['purpose']!=='selector' || $ref['retired_reason']!==null) return false;
    // Browser admission uses the original pay row lock, even without a
    // Telegram session lease. Classify and retire atomically against it.
    if (!$db->begin_transaction()) return false;
    try {
        $locked=achbotProviderRow($db,'SELECT id FROM pays WHERE id=? AND user_id=? FOR UPDATE','ii',[$pay,$actor]);
        if (!$locked || !achbotPayUxDraftWithoutEvidence($db,$pay,$actor)) {
            $db->rollback();
            achbotPayUxSyncMessages($db,$pay);
            return false;
        }
        if (!achbotPayUxRetire($db,$ref,'navigation')) {
            $db->rollback();return false;
        }
        if (!$db->commit()) throw new RuntimeException('payment_navigation_commit_unknown');
        return true;
    } catch (Throwable $error) {
        try {$db->rollback();} catch (Throwable $ignored) {}
        throw $error;
    }
}

function achbotPayUxRetire(mysqli $db,array $ref,string $reason): bool {
    $id=(int)$ref['id'];$pay=(int)$ref['pay_row_id'];
    $s=$db->prepare('UPDATE payment_message_refs SET retired_reason=? WHERE id=? AND pay_row_id=? AND retired_reason IS NULL');
    $s->bind_param('sii',$reason,$id,$pay);$ok=$s->execute() && $s->affected_rows===1;$s->close();
    return $ok;
}

function achbotPayUxChangePurpose(mysqli $db,array $ref,string $purpose): bool {
    if (!in_array($purpose,['invoice','status'],true) || $ref['purpose']!=='selector') return false;
    $id=(int)$ref['id'];$pay=(int)$ref['pay_row_id'];
    $s=$db->prepare("UPDATE payment_message_refs SET purpose=?,synced_digest=NULL,updated_at=GREATEST(updated_at,NOW(6))
        WHERE id=? AND pay_row_id=? AND purpose='selector' AND retired_reason IS NULL");
    $s->bind_param('sii',$purpose,$id,$pay);$ok=$s->execute() && $s->affected_rows===1;$s->close();
    return $ok;
}

function achbotPayUxEditAccepted($reply,int $chat,int $message): bool {
    return \AchBot\Telegram\Response\messageDeliveryOutcome($reply,(string)$chat,$message)['result']==='acknowledged';
}

function achbotPayUxEditFailureCategory($reply): string {
    if (!is_object($reply)) return 'transport_unavailable';
    $code=(int)($reply->error_code??0);
    $description=strtolower((string)($reply->description??''));
    if ($code===400 && str_contains($description,'message to edit not found')) return 'message_missing';
    if ($code===400 && (str_contains($description,"message can't be edited") ||
        str_contains($description,'message can not be edited'))) return 'message_not_editable';
    if ($code===403) return 'access_denied';
    if ($code===429) return 'rate_limited';
    if ($code>=500) return 'telegram_server_error';
    return 'telegram_rejected';
}

/** Retain an exact acknowledged digest, or record a truthful failure.
 * A missing/non-editable target is retired only on complete definite rejection.
 * A rate limit is honored; transport ambiguity never triggers a second call.
 * Markup fallback is only for a definite 400 content/shape rejection, not
 * missing/deleted/forbidden messages or a rate limit. */
function achbotPayUxApplyProjection(mysqli $db,array $ref,string $body,string $keys,bool $markupOnly=false): string {
    $id=(int)$ref['id'];$pay=(int)$ref['pay_row_id'];
    $chat=(int)$ref['chat_id'];$message=(int)$ref['message_id'];
    if ($ref['retired_reason']!==null) return 'retired';
    $digest=hash('sha256',($markupOnly?'markup':$ref['kind'])."\n".$body."\n".$keys);
    if (($ref['synced_digest']??null)===$digest) return 'unchanged';
    if (isset($ref['due']) && (int)$ref['due']!==1) return 'deferred';
    $method=$markupOnly?'editMessageReplyMarkup':($ref['kind']==='media'?'editMessageCaption':'editMessageText');
    $payload=['chat_id'=>$chat,'message_id'=>$message,'reply_markup'=>$keys];
    if (!$markupOnly) {
        $payload[$ref['kind']==='media'?'caption':'text']=$body;
        $payload['parse_mode']='HTML';
        if ($ref['kind']!=='media') $payload['disable_web_page_preview']=true;
    }
    try {$response=bot($method,$payload);} catch (Throwable $e) {$response=null;}
    if (achbotPayUxEditAccepted($response,$chat,$message)) {
        $s=$db->prepare('UPDATE payment_message_refs SET synced_digest=?,updated_at=NOW(6) WHERE id=? AND pay_row_id=? AND retired_reason IS NULL');
        $s->bind_param('sii',$digest,$id,$pay);$s->execute();$s->close();
        return 'acknowledged';
    }
    $outcome=\AchBot\Telegram\Response\deliveryOutcome($response);
    $category=achbotPayUxEditFailureCategory($response);
    $definite=$outcome['result']==='rejected';
    $fallback=false;$retired=false;
    if ($definite && in_array($category,['message_missing','message_not_editable'],true)) {
        $retired=achbotPayUxRetire($db,$ref,$category);
    } else {
        if (!$markupOnly && $definite && $outcome['code']===400) {
            try {
                $r=bot('editMessageReplyMarkup',['chat_id'=>$chat,'message_id'=>$message,'reply_markup'=>$keys]);
                $fallback=achbotPayUxEditAccepted($r,$chat,$message);
                $fallbackOutcome=\AchBot\Telegram\Response\deliveryOutcome($r);
                if ($fallbackOutcome['result']==='rejected' && in_array(achbotPayUxEditFailureCategory($r),['message_missing','message_not_editable'],true))
                    $retired=achbotPayUxRetire($db,$ref,achbotPayUxEditFailureCategory($r));
                if (($fallbackOutcome['retry_after']??0)>($outcome['retry_after']??0))
                    $outcome['retry_after']=$fallbackOutcome['retry_after'];
            } catch (Throwable $ignored) {}
        }
        $retry=$outcome['retry_after']??($category==='access_denied'?21600:180);
        $s=$db->prepare('UPDATE payment_message_refs SET updated_at=DATE_ADD(NOW(6),INTERVAL ? SECOND)
            WHERE id=? AND pay_row_id=? AND retired_reason IS NULL');
        $s->bind_param('iii',$retry,$id,$pay);$s->execute();$s->close();
    }
    $code=is_object($response)?max(0,min(599,(int)($response->error_code??0))):0;
    error_log('achbot_payment_telegram_edit_deferred pay_id='.$pay.' ref_id='.$id.
        ' kind='.$ref['kind'].' category='.$category.' tg_code='.$code.' markup_only='.($fallback?'1':'0'));
    return $retired?'retired':'deferred';
}

/** Read original facts, never interpret a failed lookup as nonadmission. */
function achbotPayUxProjectionSource(mysqli $db,int $payId): ?array {
    $s=$db->prepare('SELECT * FROM pays WHERE id=?');$s->bind_param('i',$payId);
    $s->execute();$pay=$s->get_result()->fetch_assoc();$s->close();
    if (!$pay) return null;
    $attempt=null;
    try {
        $s=$db->prepare('SELECT * FROM payment_attempts WHERE pay_row_id=?');
        $s->bind_param('i',$payId);$s->execute();$attempt=$s->get_result()->fetch_assoc()?:null;$s->close();
        $pay['superseded']=achbotPayUxHasNewer($db,$pay);
    } catch (Throwable $error) {
        $pay['projection_unknown']=true;
        error_log('achbot_payment_projection_source_unavailable pay_id='.$payId.' class='.get_class($error));
    }
    $pay['unadmitted_closed']=false;
    if (empty($pay['projection_unknown']) && $attempt===null &&
        ($pay['state']==='expired' || ($pay['state']==='pending' &&
            ((!empty($pay['draft_expires_at']) && (int)$pay['draft_expires_at']<=time()) ||
             !empty($pay['superseded']))))) {
        try {
            $pay['unadmitted_closed']=achbotPayUxUnadmittedClosed($db,$pay,$attempt);
            $pay['closure_unverified']=!$pay['unadmitted_closed'];
        } catch (Throwable $error) {
            $pay['projection_unknown']=true;
            error_log('achbot_checkout_closed_evidence_unavailable pay_id='.$payId.' class='.get_class($error));
        }
    } elseif (empty($pay['projection_unknown']) && $attempt===null &&
        $pay['state']==='pending' && achbotPayUxCurrentDraft($pay)) {
        try {
            // A still-visible selector must not invite another payment
            // when the original journal contradicts an unissued draft.
            $pay['projection_unknown']=!achbotPayUxDraftWithoutEvidence($db,$payId,(int)$pay['user_id']);
        } catch (Throwable $error) {
            $pay['projection_unknown']=true;
            error_log('achbot_checkout_draft_evidence_unavailable pay_id='.$payId.' class='.get_class($error));
        }
    }
    if (empty($pay['projection_unknown']) && in_array($pay['state'],['paid','paid_with_wallet','approved','auto_cart_approved'],true)) {
        require_once __DIR__.'/CheckoutRoutes.php';
        $pay['projection_outcome']='unknown';
        if ($pay['state']==='paid_with_wallet' && $attempt===null && (int)($pay['checkout_operation_id']??0)>0)
            $pay['projection_outcome']=achbotCheckoutWalletOutcome($db,$pay);
        elseif (($attempt['wallet_credit_entry_id']??null)!==null)
            $pay['projection_outcome']=achbotCheckoutReceiptOutcome($db,$payId);
    }
    return [$pay,$attempt];
}

/** One immutable-source presentation policy for API and all other invoices. */
function achbotPayUxProjection(mysqli $db,array $pay,?array $attempt,string $purpose): ?array {
    $state=achbotPayUxState($pay,$attempt);
    $keys=achbotPayUxStatusKeyboard((string)$pay['hash_id'],$pay,$attempt);
    $body=achbotPayUxStatusText($pay,$attempt);
    if ($purpose==='receipt') return ['', $keys, true];
    if ($purpose==='invoice') {
        // If original evidence is unreadable, do not overwrite retained
        // instructions with a fabricated invoice/status. Retry read later.
        if (!empty($pay['projection_unknown']) || !$attempt ||
            !achbotCurrentReceiptPin($attempt) ||
            !achbotPayUxManualProvider((string)$attempt['provider'])) return null;
        require_once __DIR__.'/ReceiptHelpers.php';
        $open=$pay['state']==='pending' && !($pay['superseded']??false) &&
            achbotPayUxInvoiceOpen($attempt) && $attempt['stage']==='pending';
        $invoice=achbotManualInvoiceText($db,$pay,$attempt,$open);
        if ($invoice==='') return null;
        if ($open) return [$invoice,achbotManualReceiptKeyboard($pay,$attempt),false];
        return [$body."\n\n".$invoice,$keys,false];
    }
    if ($purpose==='selector' && $state==='awaiting_payment' && $attempt===null) return null;
    return [$body,$keys,false];
}

function achbotPayUxSyncMessages(mysqli $db,int $payId): int {
    if ($payId<=0 || !function_exists('bot') || !achbotPayUxTable($db,'payment_message_refs')) return 0;
    try {
        return achbotPayUxWithLease($db,$payId,static function() use ($db,$payId): int {
            $source=achbotPayUxProjectionSource($db,$payId);
            if (!$source) return 0;
            [$pay,$attempt]=$source;
            // One bounded edit batch per payment. Digest filtering is per
            // purpose, so acknowledged rows cannot consume all batch slots.
            $changed=0;$budget=10;
            $s=$db->prepare('SELECT * FROM payment_message_refs WHERE pay_row_id=? AND purpose<>\'staff\' AND retired_reason IS NULL
                AND updated_at<=NOW(6) ORDER BY updated_at,id');
            $s->bind_param('i',$payId);$s->execute();$rows=$s->get_result();$s->close();
            $projections=[];
            $hasInvoice=(bool)achbotProviderRow($db,"SELECT id FROM payment_message_refs
                WHERE pay_row_id=? AND purpose='invoice' AND retired_reason IS NULL LIMIT 1",'i',[$payId]);
            while ($ref=$rows->fetch_assoc()) {
                if ($budget<=0) break;
                if ((int)$ref['chat_id']!==(int)$pay['user_id'] || (int)$ref['message_id']<=0) {
                    achbotPayUxRetire($db,$ref,'invalid_owner');continue;
                }
                $purpose=$ref['purpose'];
                // Recover an admission committed before the UI promotion.
                // The first available selector owns the original manual invoice;
                // additional selectors can only become status views.
                $promote=$purpose==='selector' && ($attempt!==null || (int)($pay['checkout_operation_id']??0)>0);
                if ($promote) {
                    $purpose=!$hasInvoice && $attempt!==null &&
                        achbotPayUxManualProvider((string)$attempt['provider'])?'invoice':'status';
                }
                if (!array_key_exists($purpose,$projections)) $projections[$purpose]=achbotPayUxProjection($db,$pay,$attempt,$purpose);
                $projection=$projections[$purpose];
                if ($projection===null) continue;
                if ($promote) {
                    if (!achbotPayUxChangePurpose($db,$ref,$purpose)) continue;
                    $ref['purpose']=$purpose;$ref['synced_digest']=null;
                    if ($purpose==='invoice') $hasInvoice=true;
                }
                [$body,$keys,$markup]=$projection;
                $result=achbotPayUxApplyProjection($db,$ref,$body,$keys,$markup);
                if ($result==='acknowledged') ++$changed;
                if (!in_array($result,['unchanged'],true)) --$budget;
            }
            $rows->free();
            return $changed;
        },0);
    } catch (Throwable $error) {
        error_log('achbot_payment_message_sync_deferred pay_id='.$payId.' class='.get_class($error));
        return 0;
    }
}

/** A standard purchase selector may navigate back to its plan list.
 * A known payment message is handled here, including a refused stale
 * action, so Catalogue cannot overwrite an issued/retired record. */
function achbotPayUxNavigateBack(mysqli $db,int $actor,int $message,string $body,string $keys): bool {
    try {
        $locator=achbotProviderRow($db,"SELECT pay_row_id FROM payment_message_refs WHERE chat_id=? AND message_id=? AND purpose<>'staff'",'ii',[$actor,$message]);
        if (!$locator) return false; // an ordinary non-payment catalogue
        $id=(int)$locator['pay_row_id'];
        achbotPayUxWithLease($db,$id,static function() use ($db,$id,$actor,$message,$body,$keys): void {
            $ref=achbotPayUxRef($db,$id,$actor,$message);
            if (!$ref) return;
            if ($ref['retired_reason']==='navigation') {
                // A retired projection may continue normal catalogue navigation
                // only from the bot's actual keyboard, never a payment action.
                if (\AchBot\Telegram\Conversation\verifiedCallback($GLOBALS['update']??null,$actor,$message))
                    editText($message,$body,$keys,'HTML',$actor);
                return;
            }
            if ($ref['retired_reason']!==null || $ref['purpose']!=='selector') return;
            // The same message can be reused for ordinary category navigation.
            // It is permanently removed from payment projection ownership.
            if (achbotPayUxRetireNavigation($db,$ref))
                \AchBot\Telegram\Conversation\replaceTransient($actor,$message,$body,$keys);
        });
        return true;
    } catch (Throwable $error) {
        error_log('achbot_payment_back_deferred actor='.$actor.' class='.get_class($error));
        return true;
    }
}
