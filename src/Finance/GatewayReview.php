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

function achbotGatewayReviewReady(mysqli $db): bool {
    try {
        $q=$db->query('SELECT id,pay_row_id,provider,stage,provider_status
            FROM payment_attempts LIMIT 0');
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
 * Pending checkout invoices enter this queue only after the normal hosted
 * attention threshold; an older invoice is not necessarily a paid invoice.
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
    if (!$pay || !$a || !in_array((string)$pay['state'],['pending','send'],true) ||
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
    if ($stage==='pending') {
        if ($status!=='' || (int)$a['age_seconds']<achbotPayUxHostedAttentionSeconds())
            return null;
    } elseif ($stage==='checking') {
        // A stranded NOWPayments verification may have lost the worker. The
        // original Cron retries only after ten minutes, so never let staff
        // claim the live worker's current check.
        if ($provider!=='nowpayments' ||
            (string)($a['last_checked_at']??'')==='' ||
            strtotime((string)$a['last_checked_at'])===false ||
            strtotime((string)$a['last_checked_at'])>time()-600)
            return null;
    } elseif ($stage==='review') {
        if (in_array($status,[
            'fulfillment_started','fulfillment_failed','fulfillment_unconfirmed',
            'fulfillment_needs_review','operator_fulfillment_unconfirmed',
            'operator_attested','operator_verified_finished','operator_rejected',
            'identity_mismatch','local_amount_changed','amount_mismatch',
            'multiple_payments'],true)) return null;
        if ($provider!=='nowpayments' && $status!=='verification_uncertain')
            return null;
    } else return null;
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
    string $decision): array {
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
              AND provider_status <=> ?");
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
                AND provider_status IN ('operator_attested','fulfillment_started')");
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
