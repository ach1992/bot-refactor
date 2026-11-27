<?php
/** Read-only status inspection; one customer message projection owner. */
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {http_response_code(404);exit;}
require_once __DIR__.'/../PaymentLifecycle.php';
if (preg_match('/\Aps_([A-Za-z0-9_-]{1,50})\z/D',(string)$data,$match)) {
    $actor=(int)$from_id;$message=(int)$message_id;
    $frame=\AchBot\Telegram\Conversation\source($update??null,$actor,true);
    if (!$frame || $frame->message_id!==$message || ($update->callback_query->data??null)!==$data) return;
    $locator=achbotPayUxOwnedReference($connection,$match[1],$actor);
    if (!$locator) {answerCallbackQuery($callbackId,achbotMessage('payment.pre_payment.alert.8fbaa8aebc'));return;}
    try {
        $id=(int)$locator['id'];
        $done=achbotPayUxWithLease($connection,$id,static function() use ($connection,$id,$actor,$message,$update): bool {
            $ref=achbotPayUxRecoveryRef($connection,$id,$actor,$message,$update);
            if (!$ref) return false;
            $source=achbotPayUxProjectionSource($connection,$id);
            if (!$source) return false;
            [$pay,$attempt]=$source;
            // An explicit request from retained receipt history can open a
            // separate status view once; it must never overwrite the receipt.
            if ($ref['purpose']==='receipt') {
                $existing=achbotProviderRow($connection,"SELECT *,updated_at<=NOW(6) AS due FROM payment_message_refs WHERE pay_row_id=?
                    AND chat_id=? AND purpose IN ('invoice','status') AND retired_reason IS NULL ORDER BY purpose='invoice' DESC,id DESC LIMIT 1",'ii',[$id,$actor]);
                if ($existing) $ref=$existing;
                else {
                    if (!achbotPayUxClaimStatusRequest($connection,$ref,(int)($update->update_id??0))) return false;
                    $projection=achbotPayUxProjection($connection,$pay,$attempt,'status');
                    [$body,$keys]=$projection;
                    $sent=sendMessage($body,$keys,'HTML',$actor);
                    achbotPayUxTrackMessage($connection,$id,$actor,$sent,'status');
                    return true;
                }
            }
            // Recover a committed manual invoice before converting any
            // selector into a generic status view.
            if ($ref['purpose']==='selector' && $attempt!==null) {
                achbotPayUxSyncMessages($connection,$id);
                $ref=achbotPayUxRef($connection,$id,$actor,(int)$ref['message_id']);
                if (!$ref || $ref['retired_reason']!==null) return false;
                if ($ref['purpose']==='selector') return false;
            }
            if ($ref['purpose']==='selector' && !achbotPayUxChangePurpose($connection,$ref,'status')) return false;
            if ($ref['purpose']==='selector') {$ref['purpose']='status';$ref['synced_digest']=null;}
            $projection=achbotPayUxProjection($connection,$pay,$attempt,$ref['purpose']);
            if ($projection) achbotPayUxApplyProjection($connection,$ref,...$projection);
            return true;
        },false);
        if ($done) answerCallbackQuery($callbackId,achbotMessage('payment.pre_payment.alert.fdf0d1d6f9'));
    } catch (Throwable $error) {
        error_log('achbot_payment_status_deferred pay_id='.(int)$locator['id'].' class='.get_class($error));
    }
    return;
}
return 1;
