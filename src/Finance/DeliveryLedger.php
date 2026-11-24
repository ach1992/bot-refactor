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
/**
 * Recover first, never-attempted legacy payment delivery and due service
 * message parts after a committed order. The 018/032 journal has an exact
 * claim and attempt history; this sweep never changes money or Native
 * provider state and never resends UNKNOWN parts. Abandoned service claims
 * are reconciled, not replayed.
 *
 * A quiet two-minute window lets the original callback finish first.  A
 * deferred preflight merely rotates updated_at to avoid starving later valid
 * packets; it is NOT a Telegram attempt or permission to rebuild the packet.
 * This worker has a bounded batch and soft wall-time budget (one delivery
 * intent may contain multiple transport calls).
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
        require_once __DIR__.'/ServiceDelivery.php';
        if (!achbotServiceDeliverySchemaReady($db))
            throw new RuntimeException('delivery_service_schema_invalid');
        $q=$db->query("SELECT d.id,d.pay_row_id,d.ordinal,d.service_operation_id
            FROM payment_delivery_intents d JOIN service_operations o ON o.id=d.service_operation_id
            WHERE d.state IN ('pending','dispatching') AND o.stage='completed' AND o.archived_at IS NULL
                AND d.part_count IS NOT NULL AND ((d.next_attempt_at IS NULL
                    AND d.updated_at<=DATE_SUB(NOW(),INTERVAL 2 MINUTE)) OR d.next_attempt_at<=NOW(6))
            ORDER BY COALESCE(d.next_attempt_at,d.updated_at),d.id LIMIT {$limit}");
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
            $outcome=achbotServiceDeliveryDispatch($db,$id);
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
                    "(part_count IS NOT NULL AND state IN ('pending','dispatching'))":
                    "(state='pending' AND attempt_count=0)";
                $set=($d['service_operation_id']??null)!==null?
                    "updated_at=NOW(),next_attempt_at=CASE WHEN next_attempt_at<=NOW(6)
                        THEN DATE_ADD(NOW(6),INTERVAL 2 MINUTE) ELSE next_attempt_at END":
                    "updated_at=NOW()";
                $q=$db->prepare("UPDATE payment_delivery_intents
                    SET ".$set." WHERE id=? AND ".$where);
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
        FROM payment_delivery_intents WHERE service_operation_id=(SELECT checkout_operation_id FROM pays WHERE id=?)");
    $q->bind_param('i',$payId);$q->execute();
    $row=$q->get_result()->fetch_assoc();$q->close();
    return $row?:null;
}
