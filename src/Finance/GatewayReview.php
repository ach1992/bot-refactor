<?php
/** One-shot human decisions use the existing per-payment provider attempt.
 * No new review table, additional financial transaction or separate order.
 * Operator checks the merchant's ORIGINAL invoice before deciding.
 */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '')===__FILE__) {
    http_response_code(404); exit;
}
require_once __DIR__.'/GatewayClient.php';
require_once __DIR__.'/PaymentEvidence.php';
require_once __DIR__.'/../Admin/Access.php';

/** One source of truth for active operator-attention cases. Archival is
 * a terminal LOCAL automation fence, not a merchant settlement or refund.
 * Late signed IPN evidence is separate from the immutable archived attempt.
 */
function achbotGatewayAttentionScope(): string {
    // Age, a never-paid invoice, or generic unknown verification is NOT
    // financial evidence. The inbox is for observed money, a genuine
    // verification transport outage, or a previously started local effect.
    return "a.review_archived_at IS NULL
        AND a.provider IN ('nowpayments','nextpay','zarinpal')
        AND (
            (p.state IN ('pending','send') AND (
                (a.stage='review' AND (
                    (a.provider='nowpayments' AND a.provider_status IN (
                        'partially_paid','confirming','confirmed','sending',
                        'finished','refunded','amount_mismatch','multiple_payments'))
                    OR (a.provider IN ('nextpay','zarinpal') AND
                        a.provider_status='verification_unavailable')
                    OR a.provider_status='operator_fulfillment_unconfirmed'
                    OR (a.provider_status IN (
                        'fulfillment_failed','fulfillment_unconfirmed',
                        'fulfillment_needs_review')
                        AND a.updated_at<=NOW()-INTERVAL 10 MINUTE)))
                OR (a.stage='fulfilling' AND
                    a.updated_at<=NOW()-INTERVAL 10 MINUTE)))
            OR (p.state IN ('processing','paid') AND
                a.stage IN ('review','fulfilling') AND
                a.provider_status IN ('fulfillment_started',
                    'fulfillment_unconfirmed','fulfillment_failed',
                    'fulfillment_needs_review','operator_fulfillment_unconfirmed')
                AND a.updated_at<=NOW()-INTERVAL 10 MINUTE)
            OR (p.state='service_superseded' AND a.provider='nowpayments'
                AND EXISTS (SELECT 1 FROM payment_transactions t
                    WHERE t.attempt_id=a.id AND t.provider=a.provider
                    AND t.observed_status IN (
                        'partially_paid','confirming','confirmed','sending','finished')))
            OR (p.state='service_superseded' AND
                a.provider IN ('nextpay','zarinpal') AND
                a.stage='review' AND a.provider_status='retired_verified_funds'
                AND EXISTS (SELECT 1 FROM payment_transactions t
                    WHERE t.attempt_id=a.id AND t.provider=a.provider
                      AND t.payment_id=a.provider_reference
                      AND t.observed_status='finished'))
        )";
}

function achbotGatewayReviewReady(mysqli $db): bool {
    try {
        $q=$db->query('SELECT id,pay_row_id,provider,stage,provider_status,
            review_archived_at,review_archived_by FROM payment_attempts LIMIT 0');
        $q->free();
        $q=$db->query("SHOW INDEX FROM payment_attempts WHERE Key_name='uq_payment_attempt_pay_row'");
        $cols=[];
        while ($r=$q->fetch_assoc()) {
            if ((int)$r['Non_unique']!==0) return false;
            $cols[(int)$r['Seq_in_index']]=(string)$r['Column_name'];
        }
        ksort($cols);
        return array_values($cols)===['pay_row_id'];
    } catch (Throwable $error) {return false;}
}

/** Current committed local state, NOT a claim that merchant funds settled.
 * A recorded remote CREATE/credit disqualifies all further decisions.
 * Aged unpaid invoices never enter the queue. Genuine provider evidence
 * or a verified upstream transport outage is required for original decisions.
 */
function achbotGatewayReviewEligible(mysqli $db,int $attemptId,
    string $decision,bool $lock=false): ?array {
    if ($attemptId<=0 || !in_array($decision,['approve','reject'],true)) return null;
    $q=$db->prepare("SELECT pay_row_id FROM payment_attempts
        WHERE id=? AND provider IN ('nowpayments','zarinpal','nextpay') LIMIT 1");
    $q->bind_param('i',$attemptId);$q->execute();$r=$q->get_result()->fetch_assoc();$q->close();
    if (!$r) return null;
    $payId=(int)$r['pay_row_id'];$suffix=$lock?' FOR UPDATE':'';
    $q=$db->prepare('SELECT * FROM pays WHERE id=?'.$suffix);
    $q->bind_param('i',$payId);$q->execute();$pay=$q->get_result()->fetch_assoc();$q->close();
    $q=$db->prepare('SELECT *,TIMESTAMPDIFF(SECOND,created_at,NOW()) AS age_seconds
        FROM payment_attempts WHERE id=? AND pay_row_id=?'.$suffix);
    $q->bind_param('ii',$attemptId,$payId);$q->execute();
    $a=$q->get_result()->fetch_assoc();$q->close();
    if (!$pay || !$a || ($a['review_archived_at']??null)!==null ||
        !in_array((string)$pay['state'],['pending','send'],true) ||
        (int)$pay['price']<=0 ||
        (int)$a['local_amount_toman']!==(int)$pay['price'] ||
        !hash_equals((string)$pay['hash_id'],(string)$a['order_hash']) ||
        !hash_equals((string)$pay['payid'],(string)$a['provider_reference']) ||
        (string)$a['provider_reference']==='' ||
        achbotHasUnscopedPaymentReferenceCollision($db,(string)$a['provider_reference'],$payId))
        return null;
    $provider=(string)$a['provider'];
    if ($provider==='nowpayments') {
        if (strtolower((string)$a['quote_currency'])!=='usd' ||
            !achbotNpDecimalAtLeast($a['quote_amount'],'0.00000001')) return null;
    } else {
        if (strtolower((string)$a['quote_currency'])!=='toman' ||
            !achbotNpDecimalAtLeast($a['quote_amount'],(string)$pay['price']) ||
            !achbotNpDecimalAtLeast((string)$pay['price'],$a['quote_amount']) ||
            (string)($a['payment_id']??'')!=='') return null;
    }
    $stage=(string)$a['stage'];$status=(string)($a['provider_status']??'');
    // Staff cannot approve a waiting invoice on elapsed time alone or while
    // a real verifier is running. Human decision is only for a trusted
    // provider observation or a verified merchant-transport outage.
    if ($stage!=='review') return null;
    if ($provider==='nowpayments') {
        // A merchant-confirmed refund permits closing the *original* local
        // quote as declined, never approving it or refunding anything again.
        if ($status==='refunded') {
            if ($decision!=='reject') return null;
        } elseif (!in_array($status,[
            'partially_paid','confirming','confirmed','sending','finished'],true))
            return null;
    } elseif ($status!=='verification_unavailable') return null;
    // Never declare an invoice unpaid or send its effects a second time if
    // a local service/payment side effect has already started.
    $q=$db->prepare('SELECT id FROM service_effects WHERE pay_row_id=? LIMIT 1'.$suffix);
    $q->bind_param('i',$payId);$q->execute();
    $effect=$q->get_result()->fetch_assoc();$q->close();
    if ($effect) return null;
    // A second funded provider child cannot settle the same original order.
    // A refunded child is not proof that another child has been refunded.
    $q=$db->prepare('SELECT payment_id,observed_status FROM payment_transactions
        WHERE attempt_id=? AND provider=? ORDER BY id'.$suffix);
    $q->bind_param('is',$attemptId,$provider);$q->execute();
    $children=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
    $primary=(string)($a['payment_id']??'');
    foreach ($children as $child) {
        $childId=(string)$child['payment_id'];
        $seen=(string)$child['observed_status'];
        if ($provider==='nowpayments' && $childId!==$primary &&
            in_array($seen,['confirming','confirmed','sending','partially_paid','finished'],true))
            return null;
        if ($decision==='reject' &&
            in_array($seen,['confirming','confirmed','sending','partially_paid','finished'],true))
            return null;
        if ($decision==='approve' && $seen==='refunded' && $childId===$primary)
            return null;
    }
    // NOWPayments may have persisted the issued invoice but lost the child
    // payment ID during a provider disconnect. The Owner can independently
    // match the original invoice in the merchant dashboard. No synthetic
    // payment_transactions row or provider 'finished' claim is created.
    unset($a['age_seconds']); // time passes between preflight and locked recheck
    $digest=hash('sha256',json_encode([
        'pay'=>$pay,'attempt'=>$a,'children'=>$children],
        JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
    return ['pay'=>$pay,'attempt'=>$a,'digest'=>$digest,'provider'=>$provider];
}

/** Exact source target pin. Native reads happen BEFORE financial locks. */

function achbotGatewayReviewSubjectStamp(mysqli $db,array $pay,bool $lock=false): ?string {
    if (($pay['type']??'')==='INCREASE_WALLET') return 'wallet';
    if (!function_exists('achbotProviderPaymentSubjectServer')) return null;
    $sid=achbotProviderPaymentSubjectServer($db,$pay,$lock);
    if ($sid===null || $sid<=0) return null;
    $type=(string)($pay['type']??'');
    $oid=(int)($pay['order_id']??0);
    if (preg_match('/^INCREASE_(?:DAY|VOLUME)_([1-9][0-9]*)_[1-9][0-9]*$/D',$type,$m))
        $oid=(int)$m[1];
    $planId=(int)($pay['plan_id']??0);
    $read=static function(string $table,int $id) use ($db,$lock): ?array {
        if ($id<=0) return null;
        // Only fixed internal table names; values are bound, not interpolated.
        if (!in_array($table,['server_config','server_info','server_plans',
                              'orders_list','service_effects'],true)) return null;
        $q=$db->prepare("SELECT * FROM $table WHERE id=?".($lock?' FOR UPDATE':''));
        $q->bind_param('i',$id);$q->execute();
        $row=$q->get_result()->fetch_assoc();$q->close();
        return $row?:null;
    };
    $config=$read('server_config',$sid);
    $info=$read('server_info',$sid);
    if (!$config || !$info) return null;
    $snapshot=['pay'=>$pay,'server_config'=>$config,'server_info'=>$info];
    if ($planId>0) {
        $plan=$read('server_plans',$planId);
        if (!$plan) return null;
        $snapshot['plan']=$plan;
    }
    if ($oid>0) {
        $order=$read('orders_list',$oid);
        if (!$order) return null;
        $snapshot['order']=$order;
        if ((int)($order['provider_effect_id']??0)>0) {
            $effect=$read('service_effects',(int)$order['provider_effect_id']);
            if (!$effect) return null;
            $snapshot['provider_effect']=$effect;
        }
    }
    $connectionId=(int)($config['provider_connection_id']??0);
    if ($connectionId>0) {
        // provider_connection_id is a LOGICAL identity, not a server_config.id.
        // Preserve all actual rows sharing the physical provider identity.
        $q=$db->prepare('SELECT * FROM server_config
            WHERE provider_connection_id=? ORDER BY id'.($lock?' FOR UPDATE':''));
        $q->bind_param('i',$connectionId);$q->execute();
        $panels=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
        if (!$panels) return null;
        $snapshot['provider_connections']=$panels;
    }
    return hash('sha256',json_encode($snapshot,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
}


/** Complete a human decision exactly once through the original paid-order
 * effect. A crash after the provider-attempt claim is an UNKNOWN hold, not
 * permission to create another account or re-credit the wallet.
 */
function achbotGatewayReviewCommit(mysqli $db,int $actor,int $attemptId,
    string $decision,int $primaryOwner): array {
    if ($actor<=0 || !achbotGatewayReviewReady($db))
        return ['status'=>'unavailable'];
    try {$before=achbotGatewayReviewEligible($db,$attemptId,$decision);}
    catch (Throwable $error) {return ['status'=>'unavailable'];}
    if (!$before) return ['status'=>'stale'];
    $stamp=null;
    if ($decision==='approve') {
        try {
            $stamp=achbotGatewayReviewSubjectStamp($db,$before['pay']);
            if ($stamp===null || !achbotSupportsPaymentSubject($db,$before['pay']))
                return ['status'=>'unsupported'];
            $second=achbotGatewayReviewSubjectStamp($db,$before['pay']);
            if ($second===null || !hash_equals($stamp,$second)) return ['status'=>'stale'];
        } catch (Throwable $error) {return ['status'=>'unavailable'];}
    }
    $open=false;
    try {
        $db->begin_transaction();$open=true;
        $locked=achbotGatewayReviewEligible($db,$attemptId,$decision,true);
        if (!$locked || !hash_equals($before['digest'],$locked['digest'])) {
            $db->rollback();$open=false;return ['status'=>'stale'];
        }
        if (!achbotAdminCanLocked($db,$actor,$primaryOwner,'payments')) {
            $db->rollback();$open=false;return ['status'=>'denied'];
        }
        $pay=$locked['pay'];$a=$locked['attempt'];$payId=(int)$pay['id'];
        if ($decision==='approve') {
            $now=achbotGatewayReviewSubjectStamp($db,$pay,true);
            if ($now===null || !hash_equals($stamp,$now)) {
                $db->rollback();$open=false;return ['status'=>'stale'];
            }
        }
        $provider=(string)$locked['provider'];
        $stage=(string)$a['stage'];$last=$a['provider_status'];
        $newStage=$decision==='approve'?'fulfilling':'done';
        $newStatus=$decision==='approve'?'operator_attested':'operator_rejected';
        $q=$db->prepare("UPDATE payment_attempts SET stage=?,provider_status=?
            WHERE id=? AND pay_row_id=? AND stage=?
              AND provider_status <=> ? AND review_archived_at IS NULL");
        $q->bind_param('ssiiss',$newStage,$newStatus,$attemptId,$payId,$stage,$last);
        $q->execute();$changed=$q->affected_rows;$q->close();
        if ($changed!==1) throw new RuntimeException('operator_claim_unconfirmed');
        if ($decision==='reject') {
            $q=$db->prepare("UPDATE pays SET state='declined',approved_by=?
                WHERE id=? AND state IN ('pending','send')");
            $q->bind_param('ii',$actor,$payId);
        } else {
            $q=$db->prepare("UPDATE pays SET approved_by=?
                WHERE id=? AND state IN ('pending','send')");
            $q->bind_param('ii',$actor,$payId);
        }
        $q->execute();$changed=$q->affected_rows;$q->close();
        if ($changed!==1) throw new RuntimeException('pay_decision_claim_unconfirmed');
        if (!$db->commit()) throw new RuntimeException('operator_claim_commit_unknown');
        $open=false;
        if ($decision==='reject') return ['status'=>'rejected','pay'=>$pay];
        ob_start();
        try {$effect=doAction($payId,$provider==='nowpayments'?'nowpayment':$provider);}
        catch (Throwable $error) {
            error_log('achbot_gateway_review_effect_unknown pay_id='.$payId.
                ' class='.get_class($error));$effect='unknown';
        } finally {ob_end_clean();}
        $q=$db->prepare('SELECT state FROM pays WHERE id=? LIMIT 1');
        $q->bind_param('i',$payId);$q->execute();
        $after=$q->get_result()->fetch_assoc();$q->close();
        $done=($after['state']??'')==='paid' &&
            in_array($effect,['delivered','compensated'],true);
        try {
            $q=$db->prepare("UPDATE payment_attempts SET
                stage=?,provider_status=? WHERE id=? AND stage IN ('fulfilling','review')
                AND provider_status IN ('operator_attested','fulfillment_started')
                AND review_archived_at IS NULL");
            $state=$done?'done':'review';
            $reason=$done?($effect==='compensated'
                ?'operator_compensated':'operator_fulfilled'):'operator_fulfillment_unconfirmed';
            $q->bind_param('ssi',$state,$reason,$attemptId);$q->execute();$q->close();
        } catch (Throwable $error) {
            error_log('achbot_gateway_review_closure_unknown pay_id='.$payId);
        }
        return ['status'=>$done?'completed':'unknown','pay'=>$pay];
    } catch (Throwable $error) {
        if ($open) try{$db->rollback();}catch(Throwable $ignored){}
        error_log('achbot_gateway_review_decision_unavailable attempt='.$attemptId.
            ' class='.get_class($error));
        return ['status'=>'unavailable'];
    }
}

/** End local automated processing for an already reviewed exception.
 * The operator action never changes the original provider/order/effect state,
 * but all subsequent automatic financial paths must honor this terminal fence.
 * Admission is enforced at the private, payments-capable callback route.
 */
function achbotGatewayReviewArchive(mysqli $db,int $actor,int $attemptId,int $primaryOwner): string {
    if ($actor<=0 || $attemptId<=0) return 'stale';
    // The original attempt must remain unique before terminally archiving it.
    if (!achbotGatewayReviewReady($db)) return 'unavailable';
    $open=false;
    try {
        // Fail closed if the separately versioned archival schema is missing.
        $q=$db->query('SELECT review_archived_at,review_archived_by FROM payment_attempts LIMIT 0');
        if (!$q) return 'unavailable';
        $q->free();
        $q=$db->prepare("SELECT pay_row_id FROM payment_attempts
            WHERE id=? AND provider IN ('nowpayments','nextpay','zarinpal')");
        $q->bind_param('i',$attemptId);
        $q->execute();$source=$q->get_result()->fetch_assoc();$q->close();
        if (!$source) return 'stale';
        $payId=(int)$source['pay_row_id'];
        if (!$db->begin_transaction()) return 'unavailable';
        $open=true;
        // Keep the same payment -> attempt lock ordering as original finance.
        $q=$db->prepare('SELECT id FROM pays WHERE id=? FOR UPDATE');
        $q->bind_param('i',$payId);
        $q->execute();$pay=$q->get_result()->fetch_assoc();$q->close();
        $q=$db->prepare('SELECT id,review_archived_at FROM payment_attempts
            WHERE id=? AND pay_row_id=? FOR UPDATE');
        $q->bind_param('ii',$attemptId,$payId);
        $q->execute();$attempt=$q->get_result()->fetch_assoc();$q->close();
        if (!$pay || !$attempt || $attempt['review_archived_at']!==null) {
            $db->rollback();$open=false;
            return 'stale';
        }
        if (!achbotAdminCanLocked($db,$actor,$primaryOwner,'payments')) {
            $db->rollback();$open=false;return 'denied';
        }
        // A durable 007 dispatching/verified row is forensic evidence, not
        // proof that the PHP worker is still alive. The original doAction
        // holds one MariaDB session lock across the WHOLE hosted operation,
        // including post-Native local/Telegram work, but no SQL transaction
        // remains open over HTTP. A worker crash releases the lease, allowing
        // the human to archive without replaying or rewriting its outcome.
        $name=achbotHostedWorkerLockName($db,$payId);
        $q=$db->prepare('SELECT IS_USED_LOCK(?) AS holder');
        $q->bind_param('s',$name);$q->execute();
        $holder=$q->get_result()->fetch_assoc();$q->close();
        if (!$holder || $holder['holder']!==null) {
            $db->rollback();$open=false;return 'busy';
        }
        // A finished or newly active case cannot be hidden by an old button.
        $scope=achbotGatewayAttentionScope();
        $q=$db->prepare("SELECT p.id FROM pays p
            JOIN payment_attempts a ON a.pay_row_id=p.id
            WHERE a.id=? AND $scope LIMIT 1");
        $q->bind_param('i',$attemptId);
        $q->execute();$visible=$q->get_result()->fetch_assoc();$q->close();
        if (!$visible) {
            $db->rollback();$open=false;
            return 'stale';
        }
        $q=$db->prepare("UPDATE payment_attempts
            SET review_archived_at=UTC_TIMESTAMP(),review_archived_by=?
            WHERE id=? AND pay_row_id=? AND review_archived_at IS NULL");
        $q->bind_param('iii',$actor,$attemptId,$payId);
        $q->execute();$changed=$q->affected_rows;$q->close();
        if ($changed!==1 || !$db->commit())
            throw new RuntimeException('gateway_review_archive_commit_unknown');
        $open=false;
        return 'archived';
    } catch (Throwable $error) {
        error_log('achbot_gateway_review_archive_unavailable attempt='.$attemptId.
            ' class='.get_class($error));
        return 'unavailable';
    } finally {
        if ($open) try {$db->rollback();}catch (Throwable $ignored) {}
    }
}
