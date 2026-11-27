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
        $summary=achbotPayUxWithLease($connection,$id,static function() use ($connection,$id,$actor,$message,$update): ?string {
            $ref=achbotPayUxRef($connection,$id,$actor,$message);
            if ($ref && $ref['retired_reason']!==null) {
                if (!in_array($ref['retired_reason'],['message_missing','message_not_editable','receipt_replaced'],true) ||
                    !\AchBot\Telegram\Conversation\verifiedCallback($update,$actor,$message)) return null;
            } else $ref=achbotPayUxRecoveryRef($connection,$id,$actor,$message,$update);
            if (!$ref) return null;
            $source=achbotPayUxProjectionSource($connection,$id);
            if (!$source) return null;
            [$pay,$attempt]=$source;
            $view=achbotPayUxReceiptView($connection,$id,$actor);
            $hadReceipt=$view??achbotPayUxReceiptView($connection,$id,$actor,true);
            $title=explode("\n",strip_tags(achbotPayUxStatusText($pay,$attempt)),2)[0];
            $deferred=achbotMessage('payment.payment_lifecycle.status_update_unconfirmed');
            // A fresh user request can recover a definitely unavailable
            // status target. The same update cannot blindly resend.
            $recover=static function(array $claim,string $purpose) use ($connection,$id,$actor,$update,$pay,$attempt,$title,$deferred): string {
                if (!achbotPayUxClaimStatusRequest($connection,$claim,(int)($update->update_id??0))) return $deferred;
                $projection=achbotPayUxProjection($connection,$pay,$attempt,$purpose);
                if (!$projection) return $deferred;
                [$body,$keys]=$projection;
                $sent=sendMessage($body,$keys,'HTML',$actor);
                if (!achbotPayUxTrackMessage($connection,$id,$actor,$sent,$purpose)) return $deferred;
                return $title;
            };
            if ($view) {
                $ref=$view;$purpose='receipt_view';
            } elseif ($ref['retired_reason']!==null || $ref['purpose']==='receipt') {
                // Unproven retained history is not a mutable receipt.
                $existing=achbotProviderRow($connection,"SELECT *,updated_at<=NOW(6) AS due FROM payment_message_refs WHERE pay_row_id=?
                    AND chat_id=? AND purpose IN ('invoice','status') AND retired_reason IS NULL ORDER BY purpose='invoice' DESC,id DESC LIMIT 1",'ii',[$id,$actor]);
                if ($existing && !$hadReceipt) {$ref=$existing;$purpose=$ref['purpose'];}
                else return $recover($hadReceipt??$ref,$hadReceipt?'receipt_view':($ref['purpose']==='invoice'?'invoice':'status'));
            } else $purpose=$ref['purpose'];
            // Recover a committed manual invoice before converting any
            // selector into a generic status view.
            if ($purpose==='selector' && $attempt!==null) {
                achbotPayUxSyncMessages($connection,$id);
                $ref=achbotPayUxRef($connection,$id,$actor,(int)$ref['message_id']);
                if (!$ref || $ref['retired_reason']!==null || $ref['purpose']==='selector') return $deferred;
                $purpose=$ref['purpose'];
            }
            if ($purpose==='selector') {
                if (!achbotPayUxChangePurpose($connection,$ref,'status')) return $deferred;
                $ref['purpose']=$purpose='status';$ref['synced_digest']=null;
            }
            $projection=achbotPayUxProjection($connection,$pay,$attempt,$purpose);
            if (!$projection) return $deferred;
            $result=achbotPayUxApplyProjection($connection,$ref,...$projection);
            if ($result==='retired') return $recover($ref,$purpose);
            return in_array($result,['unchanged','acknowledged'],true)?$title:$deferred;
        },null);
        answerCallbackQuery($callbackId,$summary??achbotMessage('payment.payment_lifecycle.status_update_unconfirmed'));
    } catch (Throwable $error) {
        error_log('achbot_payment_status_deferred pay_id='.(int)$locator['id'].' class='.get_class($error));
        answerCallbackQuery($callbackId,achbotMessage('payment.payment_lifecycle.status_update_unconfirmed'));
    }
    return;
}
return 1;
