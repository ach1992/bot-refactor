<?php
/** Current service/effect-owned customer delivery maintenance. No merchant
 * or Native effects, runtime DDL, or historical-packet reconstruction. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }
require_once __DIR__.'/PaymentEvidence.php';
require_once __DIR__.'/../Admin/Access.php';

/**
 * Recover due current service
 * message parts after a committed order. The current multipart journal has an exact
 * claim and attempt history; this sweep never changes money or Native
 * provider state and never resends UNKNOWN parts. Abandoned service claims
 * are reconciled, not replayed.
 *
 * Newly committed parts are immediately eligible. The existing delivery
 * lease prevents callback/Cron duplicate sends and establishes when an
 * abandoned claim can be reconciled. A deferred preflight yields to peers,
 * without counting a Telegram attempt or rebuilding the packet.
 * This worker has a bounded batch and soft wall-time budget (one delivery
 * intent may contain multiple transport calls).
 */
function achbotDeliveryMaintainPending(mysqli $db,int $limit=4,float $budgetSeconds=8.0): array {
    $stats=['checked'=>0,'acknowledged'=>0,'unknown'=>0,'deferred'=>0];
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
        $q=$db->query("SELECT d.id,d.service_operation_id,d.effect_id,d.ordinal
            FROM payment_delivery_intents d LEFT JOIN service_operations o ON o.id=d.service_operation_id
            LEFT JOIN service_effects e ON e.id=d.effect_id
            WHERE d.state IN ('pending','dispatching')
                AND (o.stage='completed' OR (d.service_operation_id IS NULL AND e.source_kind IN ('free_trial','admin_bulk')
                    AND e.state='local_recorded'))
                AND (d.next_attempt_at IS NULL OR d.next_attempt_at<=NOW(6))
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
        $id=(int)$d['id'];$sourceId=(int)($d['service_operation_id']??$d['effect_id']);
        $ordinal=(int)$d['ordinal'];
        $stats['checked']++;
        try {
            $outcome=achbotServiceDeliveryDispatch($db,$id);
        } catch (Throwable $error) {
            error_log('achbot_delivery_maintenance_dispatch_deferred source_id='.$sourceId.
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
                // No packet/state/attempt/actor changes. A failed readiness
                // check yields to other work without delaying new packets.
                // Also defer abandoned service claim reconciliation on a
                // transient SQL/ownership error, without changing its token.
                $q=$db->prepare("UPDATE payment_delivery_intents
                    SET updated_at=NOW(),next_attempt_at=CASE WHEN next_attempt_at IS NULL OR next_attempt_at<=NOW(6)
                        THEN DATE_ADD(NOW(6),INTERVAL 5 SECOND) ELSE next_attempt_at END
                    WHERE id=? AND state IN ('pending','dispatching')");
                $q->bind_param('i',$id);
                $q->execute();$q->close();
            } catch (Throwable $error) {
                error_log('achbot_delivery_maintenance_defer_failed source_id='.$sourceId.
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
