<?php
/**
 * Issue #117: finish an already VERIFIED PasarGuard manual service edit after
 * its native effect was journalled but the local payment/audit COMMIT failed.
 * Never dispatch a second edit, reset, provider payment check, or settlement.
 */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }
require_once __DIR__.'/../Panel/ProviderRuntime.php';
require_once __DIR__.'/ApiPaymentPresentation.php';
require_once __DIR__.'/ReceiptHelpers.php';
require_once __DIR__.'/FollowupOutbox.php';

/** Local, immutable evidence only; neither provider HTTP nor SQL mutation. */
function achbotManualPgVerifiedEdit(mysqli $db,array $pay,string $kind,bool $locked=false): array {
    $id=(int)($pay['id']??0);$type=(string)($pay['type']??'');
    if ($id<=0 || (int)($pay['user_id']??0)<=0 || (int)($pay['price']??0)<=0 ||
        ($pay['state']??null)!=='processing' || (int)($pay['approved_by']??0)!==0 ||
        (string)($pay['payid']??'')!=='' ||
        !in_array($kind,['renew','day','volume'],true))
        throw new RuntimeException('manual_edit_recovery_payment_invalid');
    $oid=(int)($pay['order_id']??0);$units=0;$days=0;$volume=0;
    if ($kind==='renew') {
        if ($type!=='RENEW_ACCOUNT' || $oid<=0 || (int)($pay['plan_id']??0)<=0)
            throw new RuntimeException('manual_edit_recovery_subject_invalid');
        $s=$db->prepare('SELECT days,volume FROM server_plans WHERE id=?');
        $pid=(int)$pay['plan_id'];$s->bind_param('i',$pid);$s->execute();
        $plan=$s->get_result()->fetch_assoc();$s->close();
        $days=$plan?achbotWholeServiceUnits($plan['days'],36500):null;
        $volume=$plan?achbotWholeServiceUnits($plan['volume'],achbotServiceVolumeMax(),true):null;
        if ($days===null || $volume===null)
            throw new RuntimeException('manual_edit_recovery_plan_invalid');
        $requested=['days'=>$days,'volume'=>$volume];
    } else {
        $pattern=$kind==='day'
            ? '/^INCREASE_DAY_([1-9][0-9]*)_([1-9][0-9]*)$/D'
            : '/^INCREASE_VOLUME_([1-9][0-9]*)_([1-9][0-9]*)$/D';
        if (preg_match($pattern,$type,$m)!==1 ||
            ($oid!==0 && $oid!==(int)$m[1]))
            throw new RuntimeException('manual_edit_recovery_subject_invalid');
        $oid=(int)$m[1];$units=achbotPinnedAddonUnits($pay);
        if ($units===null) throw new RuntimeException('manual_edit_recovery_units_invalid');
        $requested=[$kind==='day'?'plus_day':'plus_volume'=>$units];
    }
    $ctx=achbotPgOrderContext($db,$oid,$locked);
    $o=$ctx['order'];$a=$ctx['account'];$key='manual:pay:'.$id;
    if ((int)$o['userid']!==(int)$pay['user_id'] ||
        (int)$o['server_id']!==(int)($pay['server_id']??0) ||
        (int)$o['fileid']!==(int)($pay['plan_id']??-1) ||
        (int)$o['inbound_id']!==0 || (int)$o['status']!==1 ||
        (string)$o['remark']==='' ||
        $a['state']!=='linked' || ($a['mutation_key']??null)!==$key)
        throw new RuntimeException('manual_edit_recovery_identity_changed');
    $snap=json_decode((string)($pay['provider_snapshot']??''),true,64,JSON_THROW_ON_ERROR);
    if (!is_array($snap) || ($snap['provider']??null)!=='pasarguard' ||
        (int)($snap['provider_account_id']??0)!==(int)$a['id'])
        throw new RuntimeException('manual_edit_recovery_quote_missing');
    $panel=achbotProviderPanelForSnapshot($db,$snap);
    if ((int)$panel['id']!==(int)$ctx['panel']['id'] ||
        $panel['instance_key']!==$ctx['panel']['instance_key'] ||
        $panel['api_url']!==$ctx['panel']['api_url'])
        throw new RuntimeException('manual_edit_recovery_panel_changed');
    foreach ($ctx['identity'] as $field=>$value)
        if ((string)($snap[$field]??'')!==(string)$value)
            throw new RuntimeException('manual_edit_recovery_account_changed');

    $history=achbotPgMutationHistory($a);
    if ($history['last_key']!==$key || !in_array($key,$history['consumed_keys'],true))
        throw new RuntimeException('manual_edit_recovery_operation_changed');
    $proof=json_decode((string)($a['mutation_snapshot']??''),true,64,JSON_THROW_ON_ERROR);
    $change=$proof['changes']??null;
    if (!is_array($proof) || ($proof['version']??null)!==1 ||
        ($proof['key']??null)!==$key || ($proof['action']??null)!=='edit' ||
        ($proof['result']??null)!=='verified' ||
        !is_string($proof['verified_at']??null) || $proof['verified_at']==='' ||
        ($proof['identity']??null)!==$ctx['identity'] ||
        !is_array($change) || ($change['requested_units']??null)!==$requested ||
        !is_int($change['original_approver_id']??null) ||
        $change['original_approver_id']<=0 ||
        !is_int($change['original_amount_toman']??null) ||
        $change['original_amount_toman']<=0 ||
        $change['original_amount_toman']!==(int)$pay['price'] ||
        ($change['reset_usage']??null)!==($kind==='renew') ||
        !is_array($change['before']??null) ||
        !array_key_exists('expire',$change['before']) ||
        !is_int($change['order_expire_before']??null) ||
        (int)$o['expire_date']!==$change['order_expire_before'] ||
        !is_int($change['before']['data_limit']??null) ||
        !is_array($change['absolute']??null) ||
        !is_int($change['absolute']['expire']??null) ||
        $change['absolute']['expire']<=0 || $change['absolute']['expire']>2147483647 ||
        !is_int($change['absolute']['data_limit']??null) ||
        $change['absolute']['data_limit']<0 ||
        !is_array($proof['current_group_ids']??null))
        throw new RuntimeException('manual_edit_recovery_verified_result_missing');

    // Existing 003 manual attempts pin the original local amount independently
    // of today's pays.price. Bank-card display amounts and crypto quotes are
    // in different currencies and must not be substituted for local toman.
    $ledger=achbotManualLedgerMode($db);
    if ($ledger==='invalid') throw new RuntimeException('manual_edit_recovery_ledger_invalid');
    if ($ledger==='current') {
        $s=$db->prepare('SELECT provider,stage,order_hash,local_amount_toman
            FROM payment_attempts WHERE pay_row_id=? LIMIT 1'.($locked?' FOR UPDATE':''));
        $s->bind_param('i',$id);$s->execute();
        $attempt=$s->get_result()->fetch_assoc();$s->close();
        if ($attempt!==null &&
            (!in_array((string)$attempt['provider'],['bank_transfer','gift_card','manual_usdt','manual_trx'],true) ||
             (string)$attempt['stage']!=='review' ||
             (string)$attempt['order_hash']!==(string)$pay['hash_id'] ||
             (int)$attempt['local_amount_toman']!==$change['original_amount_toman']))
            throw new RuntimeException('manual_edit_recovery_original_quote_changed');
    }

    $fingerprint=hash('sha256',json_encode([
        'pay'=>array_intersect_key($pay,array_flip([
            'id','hash_id','state','approved_by','user_id','type','order_id',
            'plan_id','server_id','price','day','volume','description','payid',
            'provider_snapshot','message_id','chat_id','admins_messages'])),
        'order'=>$o,
        'account'=>[$a['id'],$a['state'],$a['mutation_key'],$a['mutation_snapshot']],
        'identity'=>$ctx['identity'],'panel'=>[$panel['id'],$panel['instance_key'],$panel['api_url']]
    ],JSON_THROW_ON_ERROR));
    return ['fingerprint'=>$fingerprint,'order'=>$o,'pay'=>$pay,'oid'=>$oid,
        'kind'=>$kind,'units'=>$units,'days'=>$days,'volume'=>$volume,
        'original_approver_id'=>$change['original_approver_id'],
        'result'=>(object)['success'=>true,'verified'=>true,
            'applied_expire'=>$change['absolute']['expire'],
            'applied_bytes'=>$change['absolute']['data_limit']]];
}

/** Workbench hint, never a completion claim; the callback repeats all checks. */
function achbotManualPgEditRecoverable(mysqli $db,array $pay): bool {
    $type=(string)($pay['type']??'');
    $kind=$type==='RENEW_ACCOUNT'?'renew':
        (str_starts_with($type,'INCREASE_DAY_')?'day':
        (str_starts_with($type,'INCREASE_VOLUME_')?'volume':null));
    if ($kind===null || ($pay['state']??null)!=='processing') return false;
    try { achbotManualPgVerifiedEdit($db,$pay,$kind);return true; }
    catch (Throwable $ignored) { return false; }
}


/**
 * Seal each ORIGINAL recipient before the local terminal COMMIT. The verified
 * native mutation was already completed; any follow-up failure rolls back only
 * the local finalization and never authorizes another native PUT/reset.
 */
function achbotManualPgStageEditNoticesLocked(mysqli $db,array $e,
                                              array $mainValues,int $owner): void {
    $pay=$e['pay'];$id=(int)$pay['id'];$actor=(int)$pay['approved_by'];
    $kind=(string)$e['kind'];$remark=(string)$e['order']['remark'];
    if (!achbotFollowupSchemaReady($db) || $owner<=0 || $actor<=0 ||
        $pay['state']!=='approved' || !in_array($kind,['renew','day','volume'],true))
        throw new RuntimeException('manual_edit_recovery_followup_unavailable');
    $subject=$kind==='renew'?$remark:achbotMessage(
        $kind==='day'?'manual_receipt_final.day_subject':'manual_receipt_final.volume_subject',
        ['remark'=>$remark,'units'=>$e['units']]);
    $keys=achbotManualFinalReceiptKeyboard($db,$id,$subject);
    achbotFollowupInsertLocked($db,$pay,'approved_edit','receipt_primary','edit',
        (string)(int)$pay['chat_id'],(int)$pay['message_id'],
        ['text'=>$keys,'keys'=>null,'mode'=>null]);

    // Distinct message IDs in the same chat are distinct original receipts.
    // An exact historical pair is staged only once; revoked staff excluded.
    $seen=[];
    $copies=json_decode((string)($pay['admins_messages']??''),true);
    if (is_array($copies)) foreach ($copies as $copy) {
        if (!is_array($copy) || !isset($copy['chat_id'],$copy['message_id'])) continue;
        $chat=(int)$copy['chat_id'];$message=(int)$copy['message_id'];
        if ($chat<=0 || $message<=0 ||
            ($chat===(int)$pay['chat_id'] && $message===(int)$pay['message_id']) ||
            !achbotAdminStoredRecipientAllowed($db,$copy['chat_id'],$owner)) continue;
        $key=$chat.':'.$message;
        if (isset($seen[$key])) continue;
        $seen[$key]=true;
        achbotFollowupInsertLocked($db,$pay,'approved_edit','receipt_mirror','edit',
            (string)$chat,$message,['text'=>$keys,'keys'=>null,'mode'=>null]);
    }

    if ($kind==='renew') {
        if (!is_string($mainValues['renewed_config_to_user']??null))
            throw new RuntimeException('manual_edit_recovery_notice_template_missing');
        $operator=str_replace(
            ['USERID','REMARK','VOLUME','DAYS','PRICE','HASHID'],
            [(int)$pay['user_id'],$remark,$e['volume'],$e['days'],
             (int)$pay['price'],$pay['hash_id']],
            $mainValues['renewed_config_to_user']);
        achbotFollowupInsertLocked($db,$pay,'approved_edit','operator','send',
            (string)$actor,null,
            ['text'=>$operator,'keys'=>getMainKeys(),'mode'=>null]);
        $customer=achbotMessage('renewal.renewal_checkout.message.47b1149fb0',
            ['remark'=>$remark]);
    } else {
        $customer=achbotMessage(
            $kind==='day'?'renewal.extensions.message.24dea9b122':
                'renewal.extensions.message.d5b6a4e6e7',
            ['volume'=>$e['units'],'remark'=>$remark]);
    }
    achbotFollowupInsertLocked($db,$pay,'approved_edit','customer','send',
        (string)(int)$pay['user_id'],null,
        ['text'=>$customer,'keys'=>null,'mode'=>'HTML']);
}

/** Only an existing sealed pending follow-up may be continued post-COMMIT. */
function achbotManualPgEditPending(mysqli $db,array $pay): bool {
    if (($pay['state']??null)!=='approved' ||
        (int)($pay['approved_by']??0)<=0 ||
        !achbotFollowupSchemaReady($db)) return false;
    $id=(int)($pay['id']??0);
    if ($id<=0) return false;
    $q=$db->prepare("SELECT id FROM payment_followup_intents
        WHERE pay_row_id=? AND stage='approved_edit' AND state='pending' LIMIT 1");
    $q->bind_param('i',$id);$q->execute();
    $exists=$q->get_result()->num_rows===1;$q->close();
    return $exists;
}

/**
 * NULL: not a processing claim (run original handler).
 * review: no reliable completed native edit (keep claim; NO remote retry).
 * approved: single local payment, order-expiry and audit COMMIT.
 */
function achbotManualPgResumeEdit(mysqli $db,string $hash,int $actor,string $kind,
                                      array $mainValues=[],int $owner=0): ?array {
    if ($actor<=0 || preg_match('/^[A-Za-z0-9_-]{1,50}$/D',$hash)!==1)
        return ['status'=>'review'];
    $s=$db->prepare('SELECT * FROM pays WHERE hash_id=? LIMIT 1');
    $s->bind_param('s',$hash);$s->execute();$pay=$s->get_result()->fetch_assoc();$s->close();
    if ($pay && $pay['state']==='approved' &&
        (($kind==='renew' && $pay['type']==='RENEW_ACCOUNT') ||
         ($kind==='day' && str_starts_with((string)$pay['type'],'INCREASE_DAY_')) ||
         ($kind==='volume' && str_starts_with((string)$pay['type'],'INCREASE_VOLUME_'))) &&
        achbotManualPgEditPending($db,$pay))
        return ['status'=>'delivery_only','pay_id'=>(int)$pay['id']];
    if (!$pay || $pay['state']!=='processing') return null;
    // Other providers retain their original processing/no-replay guards; this
    // path is strictly a PasarGuard VERIFIED-journal local finisher.
    $s=$db->prepare('SELECT type FROM server_config WHERE id=?');
    $server=(int)($pay['server_id']??0);$s->bind_param('i',$server);
    $s->execute();$panel=$s->get_result()->fetch_assoc();$s->close();
    if (($panel['type']??null)!=='pasarguard') return null;
    $started=false;
    try {
        $before=achbotManualPgVerifiedEdit($db,$pay,$kind);
        if (!$db->begin_transaction()) throw new RuntimeException('manual_edit_recovery_begin_failed');
        $started=true;
        $s=$db->prepare('SELECT * FROM pays WHERE id=? FOR UPDATE');
        $id=(int)$pay['id'];$s->bind_param('i',$id);$s->execute();
        $locked=$s->get_result()->fetch_assoc();$s->close();
        if (!$locked ||
            !achbotManualServiceApprovalAllowedLocked($db,$locked,
                $kind==='renew'?'renewal':$kind,true))
            throw new RuntimeException('manual_edit_recovery_receipt_changed');
        $current=achbotManualPgVerifiedEdit($db,$locked,$kind,true);
        if (!hash_equals($before['fingerprint'],$current['fingerprint']))
            throw new RuntimeException('manual_edit_recovery_snapshot_changed');
        achbotStoreVerifiedServiceExpiryLocked($db,$current['order'],$current['result']);
        $uid=(int)$locked['user_id'];$oid=$current['oid'];
        $sid=(int)$current['order']['server_id'];$inbound=(int)$current['order']['inbound_id'];
        $remark=(string)$current['order']['remark'];$price=(int)$locked['price'];$now=time();
        if ($kind==='day' || $kind==='volume') {
            $column=$kind==='day'?'day':'volume';
            $s=$db->prepare('INSERT INTO increase_order
                (userid,server_id,inbound_id,remark,amount,date,'.$column.')
                VALUES (?,?,?,?,?,?,?)');
            $s->bind_param('iiisiii',$uid,$sid,$inbound,$remark,$price,$now,$current['units']);
        } else {
            $s=$db->prepare('INSERT INTO increase_order
                (userid,server_id,inbound_id,remark,amount,date)
                VALUES (?,?,?,?,?,?)');
            $s->bind_param('iiisii',$uid,$sid,$inbound,$remark,$price,$now);
        }
        $ok=$s->execute() && $s->affected_rows===1;$s->close();
        if (!$ok) throw new RuntimeException('manual_edit_recovery_audit_unconfirmed');
        $s=$db->prepare("UPDATE pays SET state='approved',approved_by=?
            WHERE id=? AND state='processing' AND (approved_by IS NULL OR approved_by=0)");
        $originalApprover=$current['original_approver_id'];
        $s->bind_param('ii',$originalApprover,$id);
        $ok=$s->execute() && $s->affected_rows===1;$s->close();
        if (!$ok) throw new RuntimeException('manual_edit_recovery_payment_unconfirmed');
        $current['pay']['approved_by']=$originalApprover;
        $current['pay']['state']='approved';
        achbotManualPgStageEditNoticesLocked($db,$current,$mainValues,$owner);
        if ($db->commit()!==true)
            throw new RuntimeException('manual_edit_recovery_commit_unconfirmed');
        $started=false;
        return ['status'=>'approved','pay_id'=>$id];
    } catch (Throwable $error) {
        if ($started) try {$db->rollback();}catch(Throwable $ignored){}
        // Log the precise internal recovery stage, never raw provider/SQL
        // payloads. Operators need a resolvable reason, not just class=0.
        $message=(string)$error->getMessage();
        $reason=preg_match('/\A(?:manual_edit_recovery|pg|day_extension|service_expiry)_[a-z0-9_]{2,100}\z/D',$message)===1
            ?$message:'internal_exception';
        error_log('achbot_manual_pg_edit_recovery_needs_review pay_id='.
            (int)($pay['id']??0).' kind='.$kind.' actor='.$actor.
            ' reason='.$reason.' class='.get_class($error).
            ' code='.(int)$error->getCode());
        return ['status'=>'review'];
    }
}

/**
 * Dispatch only sealed PENDING intents after payment COMMIT. UNKNOWN and
 * dispatching transport results are never resent from the original callback.
 */
function achbotManualPgEditNotify(mysqli $db,int $payId): void {
    $results=achbotFollowupDispatchPending($db,$payId,'approved_edit');
    $pending=array_filter($results,static fn($status)=>$status!=='acknowledged');
    if ($pending) error_log('achbot_manual_pg_edit_notice_pending pay_id='.$payId.
        ' count='.count($pending));
}
