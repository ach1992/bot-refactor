<?php
/** Broadcast dispatch under the canonical per-installation Cron lease. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../messages/loader.php'; // ACHBOT_TEXT_CATALOG
require_once __DIR__ . '/../Telegram/Response.php';

require_once __DIR__.'/../Broadcast/Storage.php';

/** Notifications cannot stop or restart the actual dispatch job. */
function achbotBroadcastNotice(int $id,string $phase,string $text): void {
    try {
        achbotNotifyAdmins('sendMessage',['text'=>$text],
            ['report_channel'=>null,'report_ref'=>'broadcast:'.$id.':'.$phase]);
    } catch (Throwable $error) {
        error_log('achbot_broadcast_notice_failed broadcast_id='.$id.' phase='.$phase.
            ' class='.get_class($error).' code='.(int)$error->getCode());
    }
}

function achbotBroadcastSend(mysqli $connection,string $botUsername): void {
    $batchLimit=400;
    $sleepUs=80000;
    // The existing minute-based, sequential Cron has other business jobs.
    // Bound one slice of work, not the total audience or number of slices.
    // An in-flight request can finish; never kill it to satisfy this budget.
    $sendId=0;
    $offset=0;
    $delay=0;
    $claimed=false;
    $claim=null;
    try {
        // The canonical Cron lease excludes a living previous dispatcher.
        // Running state therefore recovers immediately after process death.
        // For paused state, updated_at is the durable eligible-at checkpoint:
        // ordinarily NOW(), or the future time requested by Telegram 429.
        $list=achbotBroadcastQuery($connection,"
            SELECT id,`offset`,updated_at,
                (state=2 OR updated_at IS NULL OR updated_at<=NOW()) AS ready
            FROM send_list WHERE (type='forwardall' OR text_html IS NOT NULL)
              AND state IN (1,2)
            ORDER BY id LIMIT 1");
        $candidate=$list->fetch_assoc();
        $list->free();
        // Preserve FIFO across queued broadcasts during an upstream wait.
        if (!$candidate || !(int)$candidate['ready']) return;
        $sendId=(int)$candidate['id'];
        $offset=(int)$candidate['offset'];
        achbotBroadcastQuery($connection,"UPDATE send_list SET state=2,updated_at=NOW()
            WHERE id=? AND `offset`=?
              AND (state=2 OR (state=1 AND (updated_at IS NULL OR updated_at<=NOW())))",
            [$sendId,$offset]);
        // A recovered state=2 row may already have the same second timestamp.
        // Use the actual guarded read below, not changed-row semantics, for
        // that case; cancellation is never converted back into running.
        $list=achbotBroadcastQuery($connection,"SELECT * FROM send_list
            WHERE id=? AND state=2 AND `offset`=?",[$sendId,$offset]);
        $info=$list->fetch_assoc();
        $list->free();
        if (!$info) return;
        $claimed=true;

        $type=$info['type'];
        $file_id=$info['file_id'];
        $chat_id=$info['chat_id'];
        $text=$info['text_html'];
        $message_id=$info['message_id'];
        $group=$info['target_group']??'all';
        if ($offset===0 && $candidate['updated_at']===null) {
            $msg=$type==='forwardall'?achbotMessage('jobs.broadcast_send.text.5168e99d1c'):
                achbotMessage('jobs.broadcast_send.text.afcd1bb579');
            achbotBroadcastNotice($sendId,'started',$msg);
        }

        $keys=json_encode(['inline_keyboard'=>[[[
            'text'=>achbotMessage('legacy_buttons.start_bot'),
            'url'=>"https://t.me/$botUsername?start=mainMenu"
        ]]]]);
        $audience=achbotBroadcastAudienceSql($group);
        $usersList=achbotBroadcastQuery($connection,"SELECT u.id,u.userid FROM users u
            WHERE u.id>? AND $audience ORDER BY u.id LIMIT $batchLimit",[$offset]);

        if ($usersList->num_rows===0) {
            $usersList->free();
            // bc_messages has a unique (broadcast_id,userid) key. Count only
            // durable positive message IDs, independent of live audience or
            // dispatch cursor. An empty/failed query cannot invent success.
            $ackCount=achbotBroadcastAckCount($connection,$sendId);
            $msg=$type==='forwardall'?achbotMessage('jobs.broadcast_send.text.2c23963c7c'):
                achbotMessage('jobs.broadcast_send.text.684e7d6efc');
            $msg.=achbotMessage('jobs.broadcast_send.text.f9f933d21b',['count'=>$ackCount]).
                achbotMessage('jobs.broadcast_send.text.2e434e30b2');
            // Commit the state before the optional notification. Cancellation
            // wins if already recorded, and a report failure cannot reopen it.
            $completed=markFinished($connection,'send_list',$sendId,$offset);
            $claimed=false;
            if ($completed) achbotBroadcastNotice($sendId,'completed',$msg);
            return;
        }

        // Claim each recipient durably BEFORE any possible external send.
        // The cursor is not proof of ACK. Never replay an uncertain dispatch.
        $claim=$connection->prepare("UPDATE send_list SET `offset`=?,updated_at=NOW()
            WHERE id=? AND state=2 AND `offset`=?");
        if (!$claim) throw new RuntimeException('broadcast_claim_prepare_failed',$connection->errno);
        $next=0;
        $claim->bind_param('iii',$next,$sendId,$offset);
        $needsSample=(int)($info['sample_message_id']??0)<=0;
        $sliceUntil=hrtime(true)+45_000_000_000;
        while ($user=$usersList->fetch_assoc()) {
            if (hrtime(true)>=$sliceUntil) break;
            $next=(int)$user['id'];
            $uid=(int)$user['userid'];
            if (!$claim->execute()) throw new RuntimeException('broadcast_claim_write_failed',$claim->errno);
            if ($claim->affected_rows!==1) {
                if (isCancelled($connection,'send_list',$sendId)) {
                    $claimed=false;
                    achbotBroadcastNotice($sendId,'cancelled',
                        achbotMessage('jobs.broadcast_send.button.488a04a1e0',['offset'=>$offset]));
                } else error_log('achbot_broadcast_claim_not_acquired broadcast_id='.$sendId.' user_id='.$uid);
                return;
            }
            $offset=$next;

            // Exceptions, rejections and missing ACKs are per-recipient
            // outcomes, not reasons to discard or restart the whole batch.
            $sendRes=null;
            try {
                if ($type==='text') {
                    $sendRes = sendMessage($text, $keys, 'HTML', $user['userid']);
                } elseif ($type==='forwardall') {
                    $sendRes=forwardmessage($user['userid'],$chat_id,$message_id);
                } else {
                    $media=match($type) {
                        'music'=>'audio','video'=>'video','voice'=>'voice','photo'=>'photo',
                        default=>'document'
                    };
                    $sendRes=bot('send'.ucfirst($media),[
                        'chat_id'=>$user['userid'],$media=>$file_id,'caption'=>$text,
                        'parse_mode'=>'HTML','reply_markup'=>$keys
                    ]);
                }
            } catch (Throwable $error) {
                error_log('achbot_broadcast_dispatch_exception broadcast_id='.$sendId.
                    ' user_id='.$uid.' class='.get_class($error).' code='.(int)$error->getCode());
            }
            $outcome=\AchBot\Telegram\Response\deliveryOutcome($sendRes);
            if ($outcome['result']==='rejected') {
                $code=$outcome['code'];
                error_log('achbot_broadcast_delivery_rejected code='.$code.
                    ' user_id='.$uid.' broadcast_id='.$sendId);
                if ($code===403) {
                    try {
                        achbotBroadcastQuery($connection,"UPDATE users SET step='Bot_Blocked'
                            WHERE userid=? AND (step IS NULL OR
                                (step NOT IN ('banned','Bot_Blocked')
                                AND step NOT LIKE 'achbotDirectGift%' AND step NOT LIKE 'achbotExempt:%'))",[$uid]);
                    } catch (Throwable $error) {
                        error_log('achbot_broadcast_block_marker_failed broadcast_id='.$sendId.
                            ' user_id='.$uid.' code='.(int)$error->getCode());
                    }
                }
                if ($code===429) {
                    // The rejected recipient was already claimed. Skip it,
                    // but honor the upstream wait for later recipients. Do
                    // not sleep while holding the shared Cron lease. A 429
                    // without a usable wait yields until the next Cron tick.
                    $delay=$outcome['retry_after']??0;
                    error_log('achbot_broadcast_rate_deferred broadcast_id='.$sendId.
                        ' user_id='.$uid.' retry_after='.$delay);
                    return;
                }
            } elseif ($outcome['result']==='acknowledged' &&
                      is_int($sendRes->result->message_id??null) && $sendRes->result->message_id>0) {
                $sentMsgId=$sendRes->result->message_id;
                $recorded=false;
                try {
                    achbotBroadcastRecordAck($connection,$sendId,$uid,$sentMsgId);
                    $recorded=true;
                } catch (Throwable $error) {
                    // Keep the actual accepted message ID in private
                    // diagnostics. Never resend to compensate for a local
                    // record failure. The next claim still requires SQL.
                    error_log('achbot_broadcast_ack_record_failed broadcast_id='.$sendId.
                        ' user_id='.$uid.' message_id='.$sentMsgId.
                        ' class='.get_class($error).' code='.(int)$error->getCode());
                }
                if ($recorded && $needsSample) {
                    try {
                        achbotBroadcastQuery($connection,"UPDATE send_list
                            SET sample_userid=?,sample_message_id=?
                            WHERE id=? AND (sample_message_id IS NULL OR sample_message_id=0)",
                            [$uid,$sentMsgId,$sendId]);
                        $needsSample=false;
                    } catch (Throwable $error) {
                        // The recipient ACK is already durable; preview
                        // persistence is a different, optional effect.
                        error_log('achbot_broadcast_sample_record_failed broadcast_id='.$sendId.
                            ' user_id='.$uid.' message_id='.$sentMsgId.' code='.(int)$error->getCode());
                    }
                }
            } else {
                error_log('achbot_broadcast_delivery_unknown broadcast_id='.$sendId.' user_id='.$uid);
            }
            usleep($sleepUs);
        }
        $usersList->free();
    } catch (Throwable $error) {
        error_log('achbot_broadcast_worker_deferred broadcast_id='.$sendId.' offset='.$offset.
            ' class='.get_class($error).' code='.(int)$error->getCode());
        // Signal a worker error to the coordinator without serializing SQL
        // or customer content; the other ordered jobs still run.
        throw new RuntimeException('broadcast_worker_deferred',(int)$error->getCode());
    } finally {
        safeClose($claim);
        if ($claimed) {
            // Guarded state transition: no rewind, no revival of completed
            // or cancelled work. If SQL is unavailable, state=2 is
            // recoverable at the next admitted Cron invocation.
            try {
                if (!markPaused($connection,'send_list',$sendId,$offset,$delay))
                    throw new RuntimeException('broadcast_checkpoint_write_failed',$connection->errno);
            } catch (Throwable $error) {
                error_log('achbot_broadcast_checkpoint_deferred broadcast_id='.$sendId.' offset='.$offset.
                    ' class='.get_class($error).' code='.(int)$error->getCode());
                throw new RuntimeException('broadcast_checkpoint_deferred',(int)$error->getCode());
            }
        }
    }
}

achbotBroadcastSend($connection,$botUsername);
