<?php
/**
 * Original Wallet add-on reconciliation, without a second debit or Native
 * operation. An authorized staff invocation inspects the original PasarGuard
 * journal and authenticated Native state, then atomically finishes the SAME
 * local payment/order/audit. The same local COMMIT records durable pending
 * customer/admin notification markers; no Telegram request is made in it.
 *
 * An internal Cron coordinator may reconcile an ALREADY APPLIED original
 * Native claim without a human actor. Ambiguous baseline/drift never results
 * in a second Native request, a wallet debit or a fabricated payment.
 */
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {http_response_code(404);exit;}
require_once __DIR__.'/../Panel/ProviderRuntime.php';
require_once __DIR__.'/../Admin/Access.php';
require_once __DIR__.'/ReceiptHelpers.php';
require_once __DIR__.'/ServiceEffects.php';
require_once __DIR__.'/WalletAddonNotices.php';

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
    // the provider's GET-only inspection. Observation must NOT release the
    // unfinished original mutation fence before local financial COMMIT.
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
 * Operator and internal-system entrypoints share the exact same original
 * journal/identity/Native evidence and atomic financial finalization. Only
 * the manual entrypoint needs an administrator permission check; the Cron
 * entrypoint can never request a Native edit or take an ownership shortcut.
 */
function achbotWalletPgResumeAddon(mysqli $db,int $payId,int $actor,int $primary): array {
    return achbotWalletPgResumeAddonCore($db,$payId,$actor,$primary,false);
}
function achbotWalletPgResumeAppliedSystem(mysqli $db,int $payId): array {
    return achbotWalletPgResumeAddonCore($db,$payId,0,0,true);
}
function achbotWalletPgResumeAddonCore(mysqli $db,int $payId,int $actor,
        int $primary,bool $system): array {
    if ($payId<=0 || ($system?($actor!==0||$primary!==0):($actor<=0||$primary<=0)))
        return ['status'=>'review'];
    $started=false;
    try {
        if (!$system && !achbotAdminCanFor($db,$actor,$primary,'payments'))
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
        if (!hash_equals($before['fingerprint'],$current['fingerprint']))
            throw new RuntimeException('wallet_recovery_original_changed');
        $proof=json_decode((string)$current['account']['mutation_snapshot'],true,64,JSON_THROW_ON_ERROR);
        $expectedGroups=$proof['changes']['before']['group_ids']??null;
        if (!is_array($expectedGroups) ||
            !is_array($remote->observed_group_ids??null) ||
            $remote->observed_group_ids!==$expectedGroups)
            throw new RuntimeException('wallet_recovery_native_proof_missing');
        if ($current['account']['state']==='mutating') {
            // This state transition and all financial writes are one local
            // transaction; a failed audit/COMMIT cannot free the account.
            achbotPgFinishMutation($db,$current['ctx'],$proof,
                ['success'=>true,'user'=>['group_ids'=>$remote->observed_group_ids]]);
        } elseif ($current['account']['state']!=='linked' ||
            ($proof['result']??null)!=='verified' ||
            !is_string($proof['verified_at']??null) || $proof['verified_at']==='' ||
            ($proof['current_group_ids']??null)!==$expectedGroups) {
            throw new RuntimeException('wallet_recovery_original_changed');
        }
        if (!$system && !achbotAdminCanLocked($db,$actor,$primary,'payments'))
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
        // Keep the ordinary notification/report obligation durable across a
        // crash after financial COMMIT. Telegram and administrative fanout
        // are performed separately, never while holding payment SQL locks.
        achbotWalletAddonMarkRecoveryNoticesLocked($db,$current);
        if ($db->commit()!==true)
            throw new RuntimeException('wallet_recovery_commit_unknown');
        $started=false;
        achbotDiagnosticEvent('achbot.finance.wallet_original_reconciled',[
            'pay_id'=>$payId,'order_id'=>$current['order_id'],
            'source'=>$current['kind'],'actor_id'=>$actor,
            'result'=>'paid_with_wallet','fulfillment_state'=>'delivery_pending',
            'recovery_actor'=>$system?'system':'operator'],'info');
        // No new send is inferred from old Telegram callbacks. The existing
        // first-send-only 018 ledger and normal fanout coordinator own notices.
        return ['status'=>'settled_delivery_pending','pay_id'=>$payId,
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
