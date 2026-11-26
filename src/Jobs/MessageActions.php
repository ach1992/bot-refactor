<?php
/** Current broadcast message actions under the canonical Cron lease. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../messages/loader.php'; // ACHBOT_TEXT_CATALOG
require_once __DIR__ . '/../Telegram/Response.php';

/** Only confirmed actions update local message flags. Record the existing
 * per-job success/failure counter in the same short local transaction. */
function achbotMessageActionRecord(mysqli $db,int $jobId,string $rowId,string $action,bool $ok): void {
    if (!$db->begin_transaction()) throw new RuntimeException('message_action_record_begin',$db->errno);
    try {
        $set=$ok?match($action) {
            'pin'=>'pinned=1,pinned_at=NOW()',
            'unpin'=>'pinned=0,pinned_at=NULL',
            'delete'=>'deleted=1,deleted_at=NOW(),pinned=0,pinned_at=NULL',
            'edit'=>null,
        }:null;
        if ($set!==null) achbotBroadcastQuery($db,"UPDATE bc_messages SET $set WHERE id=CAST(? AS UNSIGNED)",[$rowId]);
        $counter=$ok?'ok_cnt':'fail_cnt';
        achbotBroadcastQuery($db,"UPDATE bc_jobs SET $counter=$counter+1 WHERE id=?",[$jobId]);
        if (!$db->commit()) throw new RuntimeException('message_action_record_commit',$db->errno);
    } catch (Throwable $error) {
        try {$db->rollback();} catch (Throwable $ignored) {}
        throw $error;
    }
}

function achbotMessageActions(mysqli $connection,string $inlineStartKey): void {
    $limit=200;
    $sleepUs=40000;
    $jobId=0;
    $offset='0';
    $delay=0;
    $claimed=false;
    $claim=null;
    try {
        $job=achbotJobTake($connection,'bc_jobs');
        if (!$job) return;
        $jobId=(int)$job['id'];
        $broadcastId=(int)$job['broadcast_id'];
        $action=(string)$job['action'];
        $offset=(string)$job['offset'];
        $claimed=true;
        if ($offset==='0' && $job['first_state']===1)
            achbotJobNotice('message_action',$jobId,'started',achbotMessage('jobs.message_actions.button.5d55dfe1f8',
                ['bc_jobId'=>$jobId,'action'=>$action,'broadcastId'=>$broadcastId]));

        $sendType='text';
        if ($action==='edit') {
            $source=achbotBroadcastQuery($connection,"SELECT type FROM send_list WHERE id=? AND state=3",[$broadcastId]);
            $row=$source->fetch_assoc();$source->free();
            if (!$row || $row['type']==='forwardall')
                throw new RuntimeException('message_action_edit_source_unavailable');
            $sendType=$row['type'];
        }
        $filter=match($action) {
            'pin'=>' AND pinned=0',
            'unpin'=>' AND pinned=1',
            default=>'',
        };
        $rows=achbotBroadcastQuery($connection,"SELECT id,userid,message_id FROM bc_messages
            WHERE broadcast_id=? AND deleted=0 AND message_id>0 AND id>CAST(? AS UNSIGNED) $filter
            ORDER BY id LIMIT $limit",[$broadcastId,$offset]);
        if ($rows->num_rows===0) {
            $rows->free();
            $completed=markFinished($connection,'bc_jobs',$jobId);
            $claimed=false;
            if ($completed) achbotJobNotice('message_action',$jobId,'completed',
                achbotMessage('jobs.message_actions.button.8ece62920c',
                    ['bc_jobId'=>$jobId,'action'=>$action,'broadcastId'=>$broadcastId]));
            return;
        }
        $next='0';
        $claim=achbotJobClaimStatement($connection,'bc_jobs',$next,$jobId,$offset);
        $sliceUntil=achbotJobSliceDeadline();
        while ($row=$rows->fetch_assoc()) {
            if (hrtime(true)>=$sliceUntil) break;
            $next=(string)$row['id'];$uid=(int)$row['userid'];$mid=(int)$row['message_id'];
            if (!achbotJobClaim($connection,$claim)) {
                if (isCancelled($connection,'bc_jobs',$jobId)) {
                    $claimed=false;
                    achbotJobNotice('message_action',$jobId,'cancelled',
                        achbotMessage('jobs.message_actions.button.76bf7d1a9d',['bc_jobId'=>$jobId]));
                } else error_log('achbot_message_action_claim_not_acquired job_id='.$jobId.' row_id='.$next);
                return;
            }
            $offset=$next;
            $res=null;
            try {
                $res=match($action) {
                    'pin'=>pinOne($uid,$mid),
                    'unpin'=>unpinOne($uid,$mid),
                    // Deleting the exact message does not need a separate
                    // unpin request (or its unsafe omitted-ID fallback).
                    'delete'=>deleteOne($uid,$mid),
                    'edit'=> $sendType==='text'
                        ?bot('editMessageText',['chat_id'=>$uid,'message_id'=>$mid,
                            'text'=>(string)$job['new_html'],'reply_markup'=>$inlineStartKey,'parse_mode'=>'HTML'])
                        :bot('editMessageCaption',['chat_id'=>$uid,'message_id'=>$mid,
                            'caption'=>(string)$job['new_html'],'reply_markup'=>$inlineStartKey,'parse_mode'=>'HTML']),
                };
            } catch (Throwable $error) {
                error_log('achbot_message_action_dispatch_exception job_id='.$jobId.' row_id='.$next.
                    ' class='.get_class($error).' code='.(int)$error->getCode());
            }
            $outcome=\AchBot\Telegram\Response\deliveryOutcome($res);
            $ok=($action==='edit' && \AchBot\Telegram\Response\isEditNoop($res)) ||
                ($outcome['result']==='acknowledged' &&
                ($action==='edit'
                    ?is_object($res->result??null) && ($res->result->message_id??null)===$mid
                    :($res->result??null)===true));
            if (!$ok) error_log('achbot_message_action_unconfirmed job_id='.$jobId.' row_id='.$next.
                ' result='.$outcome['result'].' code='.(int)($outcome['code']??0));
            try {
                achbotMessageActionRecord($connection,$jobId,$next,$action,$ok);
            } catch (Throwable $error) {
                // Do not repeat an external action to compensate for a
                // failed local write. The next recipient needs its own claim.
                error_log('achbot_message_action_record_failed job_id='.$jobId.' row_id='.$next.
                    ' acknowledged='.(int)$ok.' class='.get_class($error).' code='.(int)$error->getCode());
            }
            if ($outcome['code']===429) {
                $delay=$outcome['retry_after']??0;
                error_log('achbot_message_action_rate_deferred job_id='.$jobId.' row_id='.$next.' retry_after='.$delay);
                return;
            }
            usleep($sleepUs);
        }
        $rows->free();
    } catch (Throwable $error) {
        error_log('achbot_message_action_worker_deferred job_id='.$jobId.' offset='.$offset.
            ' class='.get_class($error).' code='.(int)$error->getCode());
        throw new RuntimeException('message_action_worker_deferred',(int)$error->getCode());
    } finally {
        safeClose($claim);
        if ($claimed) achbotJobPause($connection,'bc_jobs','message_action',$jobId,$offset,$delay);
    }
}

achbotMessageActions($connection,$inlineStartKey);
