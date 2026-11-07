<?php
/** #117: two-step, locally audited, one-shot owner decisions on gateway funds.
 * Never infer merchant payout from confirmed/sending or a review stage alone.
 * A decision claim and journal COMMIT before the normal effect path.
 */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '')===__FILE__) {http_response_code(404);exit;}
require_once __DIR__.'/GatewayClient.php';
require_once __DIR__.'/PaymentEvidence.php';

function achbotNpDecisionSchemaReady(mysqli $db): bool {
    try {
        $q=$db->query("SELECT id,pay_row_id,attempt_id,decision,actor_id,payment_id,
            provider_status,evidence_sha256,state,outcome,created_at,resolved_at
            FROM payment_review_decisions LIMIT 0");
        if (!$q) return false;
        $q->free();
        $s=$db->query("SHOW TABLE STATUS WHERE Name='payment_review_decisions'");
        if (!$s || strtolower((string)($s->fetch_assoc()['Engine']??''))!=='innodb') return false;
        $q=$db->query('SHOW INDEX FROM payment_review_decisions');
        $keys=[];
        while ($q && ($r=$q->fetch_assoc())) if ((int)$r['Non_unique']===0)
            $keys[(string)$r['Key_name']][(int)$r['Seq_in_index']]=(string)$r['Column_name'];
        if (!isset($keys['uq_review_decision_pay'])) return false;
        ksort($keys['uq_review_decision_pay']);
        return array_values($keys['uq_review_decision_pay'])===['pay_row_id'];
    } catch(Throwable $ignored) {return false;}
}
function achbotNpDecisionDigest(array $remote): string {
    $data=[];
    foreach (['payment_id','invoice_id','order_id','payment_status','price_amount',
        'price_currency','pay_amount','actually_paid','pay_currency',
        'outcome_amount','outcome_currency'] as $key) $data[$key]=$remote[$key]??null;
    return hash('sha256',json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
}
function achbotNpDecisionToken(int $actor,int $id,string $decision,int $until,string $digest): string {
    return substr(hash_hmac('sha256',"np-review:v1|$actor|$id|$decision|$until|$digest",
        achbotPaymentEvidenceKey()),0,20);
}
/** Pays first, attempt next; never hold DB locks while issuing provider GET. */
function achbotNpDecisionEligible(mysqli $db,int $id,array $remote,string $decision,
                                  bool $lock=false): ?array {
    if ($id<=0 || !in_array($decision,['approve','reject'],true)) return null;
    $q=$db->prepare("SELECT pay_row_id FROM payment_attempts WHERE id=?
        AND provider='nowpayments' LIMIT 1");
    $q->bind_param('i',$id);$q->execute();$r=$q->get_result()->fetch_assoc();$q->close();
    if (!$r) return null;
    $payId=(int)$r['pay_row_id'];$suffix=$lock?' FOR UPDATE':'';
    $q=$db->prepare('SELECT * FROM pays WHERE id=?'.$suffix);
    $q->bind_param('i',$payId);$q->execute();$pay=$q->get_result()->fetch_assoc();$q->close();
    $q=$db->prepare('SELECT * FROM payment_attempts WHERE id=? AND pay_row_id=?'.$suffix);
    $q->bind_param('ii',$id,$payId);$q->execute();$a=$q->get_result()->fetch_assoc();$q->close();
    if (!$pay || !$a || !in_array((string)$pay['state'],['pending','send'],true) ||
        $a['stage']!=='review' || $a['provider']!=='nowpayments' ||
        (int)$a['local_amount_toman']<=0 ||
        (int)$a['local_amount_toman']!==(int)$pay['price'] ||
        (string)$pay['hash_id']!==(string)$a['order_hash'] ||
        (string)$pay['payid']!==(string)$a['provider_reference'] ||
        (string)$a['provider_reference']==='' || (string)$a['payment_id']==='' ||
        (string)($remote['payment_id']??'')!==(string)$a['payment_id'] ||
        (string)($remote['invoice_id']??'')!==(string)$a['provider_reference'] ||
        (string)($remote['order_id']??'')!==(string)$a['order_hash']) return null;
    $status=(string)($remote['payment_status']??'');
    if ($decision==='approve') {
        if ($status!=='finished' || !achbotNpValidFinalAmount($a,$remote)) return null;
    } else {
        if ($status!=='refunded' || strtolower((string)($remote['price_currency']??''))!=='usd' ||
            !achbotNpDecimalAtLeast($remote['price_amount']??null,$a['quote_amount']) ||
            !achbotNpDecimalAtLeast($a['quote_amount'],$remote['price_amount']??null)) return null;
    }
    $q=$db->prepare('SELECT payment_id,observed_status FROM payment_transactions
        WHERE attempt_id=? AND provider=? ORDER BY id'.$suffix);
    $provider='nowpayments';$q->bind_param('is',$id,$provider);$q->execute();
    $children=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
    $primary=false;
    foreach ($children as $child) {
        if ((string)$child['payment_id']===(string)$a['payment_id']) {
            $primary=true;
            if (achbotNpChildEvidenceRank($child['observed_status'])<=0) return null;
        } elseif (achbotNpChildEvidenceRank($child['observed_status'])>0) {
            // A second funded child needs separate aggregate/refund accounting.
            return null;
        }
    }
    if (!$primary) return null;
    if ($decision==='approve' && (string)$pay['type']!=='INCREASE_WALLET') {
        // Any prior native attempt could have reached the remote server.
        $q=$db->prepare('SELECT id FROM service_effects WHERE pay_row_id=? LIMIT 1'.$suffix);
        $q->bind_param('i',$payId);$q->execute();
        $exists=$q->get_result()->num_rows>0;$q->close();
        if ($exists) return null;
    }
    return ['pay'=>$pay,'attempt'=>$a,'digest'=>achbotNpDecisionDigest($remote)];
}
/**
 * SQL-only target read-set. A networked quote is verified before the financial
 * transaction; only these local rows are re-read/locked after it opens.
 * Native mutation still performs its own final admission and readback.
 */
function achbotNpDecisionSubjectStamp(mysqli $db,array $pay,bool $lock=false): ?string {
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

/**
 * Exact provider response is supplied by a fresh authenticated GET outside
 * the transaction. Any concurrent terminal pay/attempt change fails closed.
 */
function achbotNpDecisionCommit(mysqli $db,int $actor,int $id,string $decision,
                                array $remote): array {
    if ($actor<=0 || !achbotNpDecisionSchemaReady($db)) return ['status'=>'unavailable'];
    // Service eligibility may call PasarGuard HTTP GET. Never carry payment,
    // attempt or provider-child SQL locks across this preflight.
    $preflight=achbotNpDecisionEligible($db,$id,$remote,$decision);
    if (!$preflight) return ['status'=>'stale'];
    $subjectStamp=null;
    if ($decision==='approve') {
        // Pin local quote/target BEFORE any network I/O. A concurrent change
        // during the PasarGuard GET must not become the new accepted baseline.
        try {$subjectStamp=achbotNpDecisionSubjectStamp($db,$preflight['pay']);}
        catch(Throwable $error) {return ['status'=>'unavailable'];}
        if ($subjectStamp===null) return ['status'=>'stale'];
        if (!achbotSupportsPaymentSubject($db,$preflight['pay']))
            return ['status'=>'unsupported'];
        try {$afterPreflight=achbotNpDecisionSubjectStamp($db,$preflight['pay']);}
        catch(Throwable $error) {return ['status'=>'unavailable'];}
        if ($afterPreflight===null || !hash_equals($subjectStamp,$afterPreflight))
            return ['status'=>'stale'];
    }
    $open=false;
    try {
        if (!$db->begin_transaction()) return ['status'=>'unavailable'];
        $open=true;
        $c=achbotNpDecisionEligible($db,$id,$remote,$decision,true);
        if (!$c) {$db->rollback();$open=false;return ['status'=>'stale'];}
        $pay=$c['pay'];$a=$c['attempt'];$payId=(int)$pay['id'];
        $q=$db->prepare('SELECT id FROM payment_review_decisions WHERE pay_row_id=? FOR UPDATE');
        $q->bind_param('i',$payId);$q->execute();
        $duplicate=$q->get_result()->num_rows>0;$q->close();
        if ($duplicate) {$db->rollback();$open=false;return ['status'=>'already'];}
        if ($decision==='approve') {
            // SQL-local fingerprint includes the pinned payment, plan, order,
            // provider credentials/endpoint and account operation evidence.
            // No provider quote/read function is invoked with locks held.
            $lockedStamp=achbotNpDecisionSubjectStamp($db,$pay,true);
            if ($lockedStamp===null || !hash_equals($subjectStamp,$lockedStamp)) {
                $db->rollback();$open=false;return ['status'=>'stale'];
            }
        }
        $paymentId=(string)$a['payment_id'];$status=(string)$remote['payment_status'];
        $digest=$c['digest'];$state=$decision==='approve'?'dispatching':'completed';
        $q=$db->prepare("INSERT INTO payment_review_decisions
            (pay_row_id,attempt_id,decision,actor_id,payment_id,provider_status,
             evidence_sha256,state,resolved_at)
            VALUES (?,?,?,?,?,?,?,?,CASE WHEN ?='completed' THEN NOW() ELSE NULL END)");
        $q->bind_param('iisisssss',$payId,$id,$decision,$actor,
            $paymentId,$status,$digest,$state,$state);
        if (!$q->execute() || $q->affected_rows!==1)
            throw new RuntimeException('review_journal_insert');
        $q->close();
        if ($decision==='approve') {
            $q=$db->prepare("UPDATE payment_attempts SET stage='fulfilling',
                provider_status='operator_verified_finished'
                WHERE id=? AND stage='review' AND provider='nowpayments'");
            $q->bind_param('i',$id);$q->execute();$ok=$q->affected_rows===1;$q->close();
        } else {
            $q=$db->prepare("UPDATE pays SET state='declined',approved_by=?
                WHERE id=? AND state IN ('pending','send')");
            $q->bind_param('ii',$actor,$payId);$q->execute();$ok=$q->affected_rows===1;$q->close();
            $q=$db->prepare("UPDATE payment_attempts SET stage='done',
                provider_status='operator_refund_verified' WHERE id=? AND stage='review'");
            $q->bind_param('i',$id);$q->execute();$ok=$ok&&$q->affected_rows===1;$q->close();
        }
        if (!$ok) throw new RuntimeException('review_claim');
        if (!$db->commit()) throw new RuntimeException('review_commit_unconfirmed');
        $open=false;
        if ($decision==='reject') return ['status'=>'rejected','pay'=>$pay];
        // Single original execution of wallet/service effect and its normal
        // customer/admin/report-channel output. Never dispatch it twice.
        ob_start();
        try {$effect=doAction($payId,'nowpayment');}
        catch(Throwable $error) {
            error_log('achbot_review_effect_unknown pay_id='.$payId.' class='.get_class($error));
            $effect='unknown';
        } finally {ob_end_clean();}
        $q=$db->prepare('SELECT state FROM pays WHERE id=? LIMIT 1');
        $q->bind_param('i',$payId);$q->execute();
        $after=$q->get_result()->fetch_assoc();$q->close();
        $done=($after['state']??'')==='paid' && in_array($effect,['delivered','compensated'],true);
        try {
            $db->begin_transaction();
            $finish=$done?'completed':'unknown';
            $q=$db->prepare("UPDATE payment_review_decisions SET state=?,
                outcome=?,resolved_at=NOW() WHERE pay_row_id=? AND state='dispatching'");
            $q->bind_param('ssi',$finish,$effect,$payId);$q->execute();$q->close();
            $next=$done?'done':'review';
            $reason=$done?($effect==='compensated'?'compensated':'finished'):
                'operator_fulfillment_unconfirmed';
            $q=$db->prepare("UPDATE payment_attempts SET stage=?,provider_status=?
                WHERE id=? AND stage='fulfilling'");
            $q->bind_param('ssi',$next,$reason,$id);$q->execute();$q->close();
            $db->commit();
        } catch(Throwable $error) {
            try {$db->rollback();}catch(Throwable $ignored){}
            error_log('achbot_review_closure_unknown pay_id='.$payId);
        }
        return ['status'=>$done?'completed':'unknown','pay'=>$pay];
    } catch(Throwable $error) {
        if ($open) try {$db->rollback();}catch(Throwable $ignored){}
        error_log('achbot_review_decision_unknown attempt_id='.$id.
            ' class='.get_class($error));
        return ['status'=>'unavailable'];
    }
}
