<?php
/**
 * Original Wallet add-on reconciliation, without a second debit or Native
 * operation. An authorized staff invocation inspects the original PasarGuard
 * journal and authenticated Native state, then atomically finishes the SAME
 * local payment/order/audit. No customer or Telegram send is inferred here.
 *
 * This module is deliberately not exposed as a new UI callback: an operator
 * surface and production use require separate Owner authorization.
 */
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {http_response_code(404);exit;}
require_once __DIR__.'/../Panel/ProviderRuntime.php';
require_once __DIR__.'/../Admin/Access.php';
require_once __DIR__.'/ReceiptHelpers.php';
require_once __DIR__.'/ServiceEffects.php';

/** Pure original-claim evidence; no remote access or financial writes. */
function achbotWalletPgAddonOriginal(mysqli $db,int $payId,bool $locked=false): array {
    if ($payId<=0) throw new RuntimeException('wallet_recovery_payment_invalid');
    $s=$db->prepare('SELECT * FROM pays WHERE id=?'.($locked?' FOR UPDATE':''));
    $s->bind_param('i',$payId);$s->execute();$pay=$s->get_result()->fetch_assoc();$s->close();
    if (!$pay || $pay['state']!=='processing_wallet' ||
        (int)$pay['user_id']<=0 || (int)$pay['price']<=0 ||
        (string)($pay['payid']??'')!=='')
        throw new RuntimeException('wallet_recovery_claim_invalid');
    $type=(string)$pay['type'];
    if (preg_match('/^INCREASE_(DAY|VOLUME)_([1-9][0-9]*)_([1-9][0-9]*)$/D',$type,$m)!==1)
        throw new RuntimeException('wallet_recovery_subject_invalid');
    $orderId=(int)$m[2];$kind=$m[1]==='DAY'?'day':'volume';
    $units=achbotPinnedAddonUnits($pay);
    if ($units===null || $units<=0 ||
        ((int)($pay['order_id']??0)!==0 && (int)$pay['order_id']!==$orderId))
        throw new RuntimeException('wallet_recovery_units_invalid');
    $ctx=achbotPgOrderContext($db,$orderId,$locked,true);
    $order=$ctx['order'];$account=$ctx['account'];$key='pay:'.$payId;
    if ((int)$order['userid']!==(int)$pay['user_id'] ||
        (int)$order['server_id']!==(int)$pay['server_id'] ||
        (int)$order['status']!==1 || (int)$order['inbound_id']!==0 ||
        $order['remark']==='' ||
        (string)$account['mutation_key']!==$key ||
        !in_array($account['state'],['mutating','linked'],true) ||
        !achbotPgRecoveryOrderOriginMatches($db,$ctx,$orderId))
        throw new RuntimeException('wallet_recovery_identity_changed');
    $pin=json_decode((string)($pay['provider_snapshot']??''),true,64,JSON_THROW_ON_ERROR);
    if (!is_array($pin) || ($pin['provider']??null)!=='pasarguard' ||
        (int)($pin['provider_account_id']??0)!==(int)$account['id'] ||
        (int)($pin['remote_user_id']??0)!==(int)$account['remote_user_id'] ||
        (string)($pin['remote_created_at']??'')!==(string)$account['remote_created_at'] ||
        (string)($pin['operation_marker']??'')!==(string)$account['operation_marker'] ||
        (string)($pin['instance_key']??'')!==(string)$ctx['panel']['instance_key'] ||
        (string)($pin['endpoint_sha256']??'')!==(string)$account['endpoint_sha256'])
        throw new RuntimeException('wallet_recovery_quote_changed');
    $proof=json_decode((string)($account['mutation_snapshot']??''),true,64,JSON_THROW_ON_ERROR);
    $change=$proof['changes']??null;
    $requested=[$kind==='day'?'plus_day':'plus_volume'=>$units];
    if (!is_array($proof) || ($proof['version']??null)!==1 ||
        ($proof['key']??null)!==$key || ($proof['action']??null)!=='edit' ||
        ($proof['identity']??null)!==$ctx['identity'] ||
        !in_array($key,$proof['consumed_keys']??[],true) ||
        !is_array($change) || ($change['requested_units']??null)!==$requested ||
        !is_int($change['order_expire_before']??null) ||
        (int)$order['expire_date']!==$change['order_expire_before'] ||
        !is_array($change['before']??null) ||
        !array_key_exists('expire',$change['before']) ||
        !is_int($change['before']['data_limit']??null) ||
        !is_array($change['absolute']??null) ||
        !is_int($change['absolute']['expire']??null) ||
        $change['absolute']['expire']<=0 || $change['absolute']['expire']>2147483647 ||
        !is_int($change['absolute']['data_limit']??null) ||
        $change['absolute']['data_limit']<0)
        throw new RuntimeException('wallet_recovery_original_intent_invalid');
    // The local original payment/order reference must remain identical after
    // the provider's GET-only inspection; a journal result may legitimately
    // transition from mutating to linked during that inspection.
    $fingerprint=hash('sha256',json_encode([
        'pay'=>array_intersect_key($pay,array_flip([
            'id','hash_id','state','user_id','type','order_id','plan_id',
            'server_id','price','day','volume','description','payid','provider_snapshot'])),
        'order'=>$order,
        'account'=>[$account['id'],$account['order_id'],$account['source_kind']??null,
            $account['effect_id'],$account['panel_id'],$account['instance_key'],
            $account['endpoint_sha256'],$account['remote_user_id'],
            $account['remote_created_at'],$account['remote_username'],
            $account['operation_marker'],$account['mutation_key']],
        'intent'=>[$change['before'],$change['absolute'],$change['requested_units'],
            $change['order_expire_before']],
        'panel'=>[$ctx['panel']['id'],$ctx['panel']['instance_key'],$ctx['panel']['api_url']]
    ],JSON_THROW_ON_ERROR));
    return ['fingerprint'=>$fingerprint,'pay'=>$pay,'order'=>$order,
        'account'=>$account,'ctx'=>$ctx,'units'=>$units,'kind'=>$kind,
        'order_id'=>$orderId,'absolute'=>$change['absolute']];
}

/**
 * Explicit authorized operator action only. The provider's recovery adapter
 * enforces original identity + actual current Native entitlement and runs in
 * observe_only mode: never a second PUT or RESET, even on matching baseline.
 * Payment and increase_order are changed in one short local SQL transaction.
 */
function achbotWalletPgResumeAddon(mysqli $db,int $payId,int $actor,int $primary): array {
    if ($payId<=0 || $actor<=0 || $primary<=0) return ['status'=>'review'];
    $started=false;
    try {
        if (!achbotAdminCanFor($db,$actor,$primary,'payments'))
            throw new RuntimeException('wallet_recovery_operator_denied');
        $q=$db->prepare('SELECT type,state FROM pays WHERE id=?');
        $q->bind_param('i',$payId);$q->execute();
        $previous=$q->get_result()->fetch_assoc();$q->close();
        if ($previous && $previous['state']==='paid_with_wallet' &&
            preg_match('/^INCREASE_(DAY|VOLUME)_[1-9][0-9]*_[1-9][0-9]*$/D',
                (string)$previous['type'])===1)
            // This is a status report, not fresh Native or delivery proof.
            return ['status'=>'already_paid_state','pay_id'=>$payId];
        $before=achbotWalletPgAddonOriginal($db,$payId);
        $server=(int)$before['order']['server_id'];
        $params=['pay_id'=>$payId,'remark'=>(string)$before['order']['remark'],
            $before['kind']==='day'?'plus_day':'plus_volume'=>$before['units']];
        $source=['kind'=>$before['kind'],'order'=>$before['order_id'],
            'expiry'=>(int)$before['order']['expire_date'],'observe_only'=>true];
        // No SQL row lock spans this authenticated external GET. A failed or
        // changed observation does not grant a second Native write.
        $remote=achbotPgRecoverEdit($db,$server,$params,$source,true);
        if (($remote->success??false)!==true || ($remote->verified??false)!==true)
            throw new RuntimeException('wallet_recovery_native_not_applied');
        if (!is_int($remote->applied_expire??null) ||
            !is_int($remote->applied_bytes??null) ||
            $remote->applied_expire!==$before['absolute']['expire'] ||
            $remote->applied_bytes!==$before['absolute']['data_limit'])
            throw new RuntimeException('wallet_recovery_native_result_changed');
        if (!$db->begin_transaction()) throw new RuntimeException('wallet_recovery_begin_failed');
        $started=true;
        $current=achbotWalletPgAddonOriginal($db,$payId,true);
        if (!hash_equals($before['fingerprint'],$current['fingerprint']) ||
            $current['account']['state']!=='linked')
            throw new RuntimeException('wallet_recovery_original_changed');
        $proof=json_decode((string)$current['account']['mutation_snapshot'],true,64,JSON_THROW_ON_ERROR);
        if (($proof['result']??null)!=='verified' ||
            !is_string($proof['verified_at']??null) || $proof['verified_at']==='' ||
            !is_array($proof['current_group_ids']??null) ||
            $proof['current_group_ids']!==($proof['changes']['before']['group_ids']??null))
            throw new RuntimeException('wallet_recovery_native_proof_missing');
        if (!achbotAdminCanLocked($db,$actor,$primary,'payments'))
            throw new RuntimeException('wallet_recovery_operator_denied');
        achbotEffectAssertNotArchivedLocked($db,$payId);
        achbotStoreVerifiedServiceExpiryLocked($db,$current['order'],$remote);
        $pay=$current['pay'];$order=$current['order'];$now=time();
        $column=$current['kind']==='day'?'day':'volume';
        $s=$db->prepare('INSERT INTO increase_order
            (userid,server_id,inbound_id,remark,amount,date,'.$column.')
            VALUES (?,?,?,?,?,?,?)');
        $uid=(int)$pay['user_id'];$sid=(int)$order['server_id'];
        $inbound=(int)$order['inbound_id'];$remark=(string)$order['remark'];
        $price=(int)$pay['price'];$units=$current['units'];
        $s->bind_param('iiisiii',$uid,$sid,$inbound,$remark,$price,$now,$units);
        if (!$s->execute() || $s->affected_rows!==1)
            throw new RuntimeException('wallet_recovery_audit_unconfirmed');
        $s->close();
        $hash=(string)$pay['hash_id'];$type=(string)$pay['type'];
        $s=$db->prepare("UPDATE pays SET state='paid_with_wallet'
            WHERE id=? AND user_id=? AND hash_id=? AND type=? AND price=?
            AND state='processing_wallet'");
        $s->bind_param('iissi',$payId,$uid,$hash,$type,$price);
        if (!$s->execute() || $s->affected_rows!==1)
            throw new RuntimeException('wallet_recovery_payment_unconfirmed');
        $s->close();
        if ($db->commit()!==true)
            throw new RuntimeException('wallet_recovery_commit_unknown');
        $started=false;
        achbotDiagnosticEvent('achbot.finance.wallet_original_reconciled',[
            'pay_id'=>$payId,'order_id'=>$current['order_id'],
            'source'=>$current['kind'],'actor_id'=>$actor,
            'result'=>'paid_with_wallet','fulfillment_state'=>'delivery_unverified'],'info');
        // Original Telegram delivery has NO proved ACK and is not resent.
        return ['status'=>'settled_delivery_unverified','pay_id'=>$payId,
            'order_id'=>$current['order_id']];
    } catch (Throwable $error) {
        if ($started) try {$db->rollback();} catch (Throwable $ignored) {}
        $reason=(string)$error->getMessage();
        if (preg_match('/^wallet_recovery_[a-z0-9_]{2,95}$/D',$reason)!==1)
            $reason='wallet_recovery_internal_error';
        achbotDiagnosticEvent('achbot.finance.wallet_original_recovery_deferred',[
            'pay_id'=>$payId,'actor_id'=>$actor,'reason'=>$reason,
            'class'=>get_class($error)],'warning');
        return ['status'=>'review','pay_id'=>$payId,'reason'=>$reason];
    }
}
