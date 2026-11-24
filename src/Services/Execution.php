<?php
declare(strict_types=1);
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {http_response_code(404);exit;}
require_once __DIR__.'/Funding.php';
require_once __DIR__.'/../Finance/ServicePurchaseReferral.php';
require_once __DIR__.'/../Finance/ServiceReports.php';

/** The database-local session owner serializes actual live workers without
 * holding an InnoDB transaction across HTTP. A persisted claim token fences
 * local finalization after connection loss; neither is a remote CAS. */
function achbotServiceExecutionLock(mysqli $db,int $id): string {
    $r=achbotProviderRow($db,'SELECT DATABASE() AS db');
    if ($id<=0 || !is_string($r['db']??null) || $r['db']==='')
        throw new RuntimeException('service_worker_identity');
    return 'achbot-service-'.substr(hash('sha256',$r['db']),0,20).'-'.$id;
}
function achbotServiceExecutionOwnsLock(mysqli $db,string $name): bool {
    $r=achbotProviderRow($db,'SELECT IS_USED_LOCK(?)=CONNECTION_ID() AS owned','s',[$name]);
    return (int)($r['owned']??0)===1;
}
function achbotServiceExecutionReason(Throwable $e): string {
    $reason=$e->getMessage();
    return ($e instanceof RuntimeException || $e instanceof DomainException) &&
        preg_match('/\A(?:service|provider|pg)_[a-z0-9_]{1,86}\z/D',$reason)
        ?$reason:'service_local_execution_failed';
}

/** Run at most one prepared Native step. An adapter supplies local immutable
 * preparation, actual authenticated execution/readback and local result
 * persistence. Only this owner schedules the original funded operation.
 * No payment method, Telegram, price calculation or extra debit here. */
function achbotServiceExecuteStep(mysqli $db,int $id,array $presentation=[],bool $force=false): array {
    $tx=achbotProviderRow($db,'SELECT @@in_transaction AS tx');
    if ((int)($tx['tx']??1)!==0) throw new LogicException('service_worker_requires_autocommit');
    $name=achbotServiceExecutionLock($db,$id);
    $held=achbotProviderRow($db,'SELECT GET_LOCK(?,0) AS acquired','s',[$name]);
    if ((int)($held['acquired']??0)!==1) return ['status'=>'busy'];
    $owner=null;$physicalLock=null;
    try {
        $locator=achbotProviderRow($db,'SELECT userid FROM service_operations WHERE id=?','i',[$id]);
        if (!$locator) return ['status'=>'missing'];
        $owner=(int)$locator['userid'];
        if (!$db->begin_transaction()) throw new RuntimeException('service_worker_begin');
        [$op,$quote]=achbotServiceReadLocked($db,$owner,$id);
        if (!in_array($op['stage'],['funded','prepared','dispatching','applied'],true)) {
            $db->rollback();return ['status'=>$op['stage']];
        }
        $due=achbotProviderRow($db,'SELECT next_attempt_at IS NOT NULL AND next_attempt_at<=NOW(6) AS due FROM service_operations WHERE id=?','i',[$id]);
        if (!$force && (int)$due['due']!==1) {$db->rollback();return ['status'=>'not_due'];}
        // Read-only exact replay checks the already committed debit, never
        // calls admission or funds another operation in these stages.
        if (achbotServiceFundLocked($db,$owner,$id)!=='already_funded')
            throw new RuntimeException('service_worker_funding_missing');
        $adapter=achbotProviderDefinition($quote['target']['provider']??null);
        if ($op['kind']!=='BUY' && isset($adapter['service_account_lock'])) {
            $lock=($adapter['service_account_lock'])($db,$quote['request']['order_id']);
            $held=achbotProviderRow($db,'SELECT GET_LOCK(?,0) AS acquired','s',[$lock]);
            if ((int)($held['acquired']??0)!==1) throw new RuntimeException('service_edit_account_busy');
            $physicalLock=$lock;
        }
        $finalizing=$op['stage']==='applied';
        $def=$adapter[$finalizing?'service_finalization':'service_execution'][$op['kind']]??null;
        if (!$def) throw new DomainException($finalizing?'service_finalizer_unavailable':'service_executor_unavailable');
        $work=($def['prepare'])($db,$op,$quote);
        $token=bin2hex(random_bytes(16));
        $claimStage=$finalizing?'applied':'dispatching';
        if ($work===null) {
            // The adapter has verified every original line in this phase.
            $stage=$finalizing?'completed':'applied';
            if ($finalizing) {
                achbotServiceReferralReserveLocked($db,$op,$quote);
                achbotServiceStageCompletionReportsLocked($db,$op,$quote);
            }
            $s=achbotProviderStatement($db,"UPDATE service_operations SET stage=?,next_attempt_at=IF(?=1,NULL,NOW(6)),
                claim_token=NULL,claim_until=NULL,failure_code=NULL,updated_at=NOW(6) WHERE id=?",
                'sii',[$stage,(int)$finalizing,$id]);$s->close();
            if (!$db->commit()) throw new RuntimeException('service_worker_commit');
            return ['status'=>$stage];
        }
        $s=achbotProviderStatement($db,"UPDATE service_operations SET stage=?,
            claim_token=?,claim_until=DATE_ADD(NOW(6),INTERVAL 2 MINUTE),attempt_count=attempt_count+1,
            failure_code=NULL,updated_at=NOW(6) WHERE id=?",'ssi',[$claimStage,$token,$id]);$s->close();
        if (!$db->commit()) throw new RuntimeException('service_worker_dispatch_commit');
        if (!achbotServiceExecutionOwnsLock($db,$name) ||
            ($physicalLock!==null && !achbotServiceExecutionOwnsLock($db,$physicalLock)))
            throw new RuntimeException('service_worker_ownership_lost');
        $result=$finalizing?($def['perform'])($db,$op,$quote,$work,$presentation):($def['perform'])($db,$op,$quote,$work);
        if (!$db->begin_transaction()) throw new RuntimeException('service_worker_result_begin');
        [$current,$original]=achbotServiceReadLocked($db,$owner,$id);
        if (!achbotServiceExecutionOwnsLock($db,$name) ||
            ($physicalLock!==null && !achbotServiceExecutionOwnsLock($db,$physicalLock)) || $current['stage']!==$claimStage ||
            $current['claim_token']!==$token || $original!==$quote)
            throw new RuntimeException('service_worker_result_stale');
        if (($result['success']??false)===true) {
            $done=($def['apply'])($db,$current,$original,$work,$result);
            if (!is_bool($done)) throw new RuntimeException('service_worker_result_invalid');
            $stage=$done?($finalizing?'completed':'applied'):($finalizing && $op['kind']==='BUY'?'dispatching':$claimStage);
            if ($done && $finalizing) {
                achbotServiceReferralReserveLocked($db,$current,$original);
                achbotServiceStageCompletionReportsLocked($db,$current,$original);
            }
            $s=achbotProviderStatement($db,"UPDATE service_operations SET stage=?,
                next_attempt_at=IF(?='completed',NULL,NOW(6)),claim_token=NULL,claim_until=NULL,
                failure_code=NULL,updated_at=NOW(6) WHERE id=? AND claim_token=? AND stage=?",
                'ssiss',[$stage,$stage,$id,$token,$claimStage]);
            $n=$s->affected_rows;$s->close();
            if ($n!==1 || !$db->commit()) throw new RuntimeException('service_worker_result_commit');
            return ['status'=>$done?$stage:'progressed'];
        }
        $reason=$result['reason']??'service_native_unconfirmed';
        if (!is_string($reason) || !preg_match('/\A[a-z0-9_]{1,96}\z/D',$reason)) $reason='service_native_unconfirmed';
        $delay=($result['http']??0)===429?60:20;
        $s=achbotProviderStatement($db,"UPDATE service_operations SET next_attempt_at=DATE_ADD(NOW(6),INTERVAL ? SECOND),
            claim_token=NULL,claim_until=NULL,failure_code=?,updated_at=NOW(6)
            WHERE id=? AND claim_token=?",'isis',[$delay,$reason,$id,$token]);$s->close();
        if (!$db->commit()) throw new RuntimeException('service_worker_defer_commit');
        return ['status'=>'pending','reason'=>$reason];
    } catch (Throwable $e) {
        try {$db->rollback();} catch (Throwable $ignored) {}
        $reason=achbotServiceExecutionReason($e);
        // Even a failed preparation must not monopolize the first due row.
        // Do not overwrite a new owner's claim after losing this connection.
        try {
            if ($owner!==null && achbotServiceExecutionOwnsLock($db,$name)) {
                $s=achbotProviderStatement($db,"UPDATE service_operations SET
                    next_attempt_at=DATE_ADD(NOW(6),INTERVAL 60 SECOND),claim_token=NULL,
                    claim_until=NULL,failure_code=?,updated_at=NOW(6)
                    WHERE id=? AND userid=? AND stage IN ('funded','prepared','dispatching','applied')",
                    'sii',[$reason,$id,$owner]);$s->close();
            }
        } catch (Throwable $ignored) {}
        achbotDiagnosticEvent('achbot.service.execution',[
            'service_operation_id'=>$id,'reason'=>$reason,'class'=>get_class($e)],'error');
        return ['status'=>'pending','reason'=>$reason];
    } finally {
        if ($physicalLock!==null) {
            try {$s=achbotProviderStatement($db,'SELECT RELEASE_LOCK(?)','s',[$physicalLock]);$s->close();}
            catch (Throwable $ignored) {}
        }
        try {$s=achbotProviderStatement($db,'SELECT RELEASE_LOCK(?)','s',[$name]);$s->close();}
        catch (Throwable $ignored) {}
    }
}

/** Bounded work per invocation, never a lifetime retry cap. Older deferred
 * operations remain due, with one step per original operation per pass. */
function achbotServiceExecuteDue(mysqli $db,int $limit,float $budgetSeconds,array $presentation=[]): array {
    if ($limit<=0 || !is_finite($budgetSeconds) || $budgetSeconds<=0)
        throw new InvalidArgumentException('service_worker_budget_invalid');
    $deadline=hrtime(true)/1000000000+$budgetSeconds;
    // A stuck request holding its live session lease must not occupy
    // every bounded pass. This is only a scheduling hint; ExecuteStep
    // independently acquires and verifies the exact lock before any I/O.
    $s=achbotProviderStatement($db,"SELECT id FROM service_operations
        WHERE stage IN ('funded','prepared','dispatching','applied') AND next_attempt_at<=NOW(6)
          AND IS_FREE_LOCK(CONCAT('achbot-service-',LEFT(SHA2(DATABASE(),256),20),'-',id))=1
        ORDER BY next_attempt_at,id LIMIT ?",'i',[$limit]);
    $rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();$out=[];
    foreach ($rows as $r) {
        if (hrtime(true)/1000000000>=$deadline) break;
        $out[(int)$r['id']]=achbotServiceExecuteStep($db,(int)$r['id'],$presentation);
    }
    return $out;
}
