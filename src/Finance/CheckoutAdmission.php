<?php
declare(strict_types=1);
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {http_response_code(404);exit;}
require_once __DIR__.'/FundingCheckout.php';
require_once __DIR__.'/PaymentLifecycle.php';
require_once __DIR__.'/../Plans/Addons.php';
require_once __DIR__.'/../Services/Ownership.php';

/** The final schema owns newly selected payment methods. An error is never
 * interpreted as permission to fall back to a legacy balance writer. */
function achbotCheckoutCommandsPresent(mysqli $db): bool {
    $r=achbotProviderRow($db,"SELECT DATA_TYPE,COLUMN_TYPE,IS_NULLABLE
        FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
        AND TABLE_NAME='pays' AND COLUMN_NAME='checkout_operation_id'");
    if ($r===null) return false; // pre-cutover source compatibility only
    if ($r['DATA_TYPE']!=='bigint' || !str_contains($r['COLUMN_TYPE'],'unsigned') ||
        $r['IS_NULLABLE']!=='YES' || !achbotWalletLedgerReady($db) ||
        !achbotServiceOperationsPresent($db))
        throw new RuntimeException('checkout_command_schema_invalid');
    // 033 has multiple auto-committing DDL statements. The first ALTER
    // alone is not permission to activate the new receipt coordinator.
    $s=achbotProviderStatement($db,"SELECT COLUMN_NAME,DATA_TYPE,IS_NULLABLE,DATETIME_PRECISION
        FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
          AND TABLE_NAME='payment_transactions'
          AND COLUMN_NAME IN ('merchant_checked_at','merchant_verified_at')");
    $cols=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
    if (count($cols)!==2) throw new RuntimeException('checkout_command_schema_partial');
    foreach ($cols as $c)
        if ($c['DATA_TYPE']!=='datetime' || $c['IS_NULLABLE']!=='YES' || (int)$c['DATETIME_PRECISION']!==6)
            throw new RuntimeException('checkout_command_schema_invalid');
    $s=achbotProviderStatement($db,"SELECT COLUMN_NAME,NON_UNIQUE,SUB_PART
        FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE()
          AND TABLE_NAME='pays' AND INDEX_NAME='uq_checkout_operation'");
    $idx=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
    if (count($idx)!==1 || $idx[0]['COLUMN_NAME']!=='checkout_operation_id' ||
        (int)$idx[0]['NON_UNIQUE']!==0 || $idx[0]['SUB_PART']!==null)
        throw new RuntimeException('checkout_command_schema_invalid');
    return true;
}

/** Bind all persisted UI service coordinates, not a callback's price/order
 * or a newly observed account. The hash excludes mutable receipt/status
 * fields and is recorded inside the original service quote. */
function achbotCheckoutDraftFingerprint(array $p): string {
    return hash('sha256',achbotServiceJson([
        (int)$p['id'],(int)$p['user_id'],(string)$p['hash_id'],(string)$p['type'],
        (int)$p['price'],(int)$p['server_id'],(int)$p['plan_id'],(int)$p['order_id'],
        (string)$p['day'],(string)$p['volume'],(string)$p['description'],
        (int)$p['agent_bought'],(int)$p['agent_count'],
        (string)($p['provider_snapshot']??''),(string)($p['addon_scope_snapshot']??''),
    ]));
}

function achbotCheckoutOriginalCommandLocked(mysqli $db,array $pay): array {
    $id=(int)($pay['checkout_operation_id']??0);
    if ($id<=0) throw new RuntimeException('checkout_original_command_missing');
    [$op,$quote,$owner]=achbotServiceReadLocked($db,(int)$pay['user_id'],$id);
    $original=$quote['request']['policy']['checkout']??null;
    if ($op['command_kind']!=='checkout' || $op['command_ref']!==(string)$pay['id'] ||
        !is_array($original) || ($original['hash']??null)!==$pay['hash_id'] ||
        ($original['type']??null)!==$pay['type'] ||
        !hash_equals((string)($original['fingerprint']??''),achbotCheckoutDraftFingerprint($pay)))
        throw new RuntimeException('checkout_original_command_changed');
    return [$op,$quote,$owner];
}

/** Original legacy UI draft -> immutable provider-neutral service request.
 * Only the authenticated route supplies this already persisted draft. */
function achbotCheckoutDraftRequestLocked(mysqli $db,array $pay,array $states): array {
    $uid=(int)$pay['user_id'];$sid=(int)$pay['server_id'];$pid=(int)$pay['plan_id'];
    $type=$pay['type'];$price=(int)$pay['price'];
    $q=['price'=>$price,'server_id'=>$sid,'plan_id'=>$pid,'order_id'=>0,
        'quantity'=>1,'days'=>0,'volume'=>0,'remark'=>'','uuid'=>'',
        'naming_mode'=>'','explicit_name'=>false,
        'policy'=>['checkout'=>['hash'=>$pay['hash_id'],'type'=>$type,
            'fingerprint'=>achbotCheckoutDraftFingerprint($pay)]]];
    if ($type==='BUY_SUB') {
        $p=achbotProviderRow($db,'SELECT * FROM server_plans WHERE id=? FOR UPDATE','i',[$pid]);
        $s=achbotProviderRow($db,'SELECT remark FROM server_info WHERE id=? FOR UPDATE','i',[$sid]);
        if (!$p || !$s || (int)$p['server_id']!==$sid || (int)$pay['order_id']!==0)
            throw new DomainException('checkout_plan_changed');
        $custom=(float)$pay['day']!==0.0 || (float)$pay['volume']!==0.0;
        $q['kind']='BUY';$q['variant']=$custom?'custom':'standard';
        $q['quantity']=max(1,(int)$pay['agent_count']);
        $q['days']=achbotWholeServiceUnits($custom?$pay['day']:$p['days'],3650);
        $q['volume']=achbotWholeServiceUnits($custom?$pay['volume']:$p['volume'],achbotServiceVolumeMax());
        $mode=(string)($states['remark']??'');
        if ($custom) {$q['remark']=(string)$pay['description'];$q['explicit_name']=true;}
        else {
            // The existing empty manual bulk description falls back to
            // generated names; preserve an explicit single name exactly.
            if ($mode==='manual' && $q['quantity']>1 && trim((string)$pay['description'])==='') $mode='';
            $q['remark']=buildRemark(['remark'=>$mode],$s['remark'],$pay['description'],$uid);
            $q['explicit_name']=$mode==='manual';
        }
        $q['naming_mode']=$mode;
        $q['policy']['agent_bought']=(int)$pay['agent_bought']===1;
    } else {
        $add=preg_match('/\AINCREASE_(DAY|VOLUME)_([1-9][0-9]*)_([1-9][0-9]*)\z/D',$type,$parts)===1;
        if ($type!=='RENEW_ACCOUNT' && !$add)
            throw new DomainException('checkout_service_unsupported');
        $oid=(int)$pay['order_id'];
        if ($oid<=0 || ($add && (int)$parts[2]!==$oid))
            throw new DomainException('checkout_order_changed');
        $o=achbotProviderRow($db,'SELECT * FROM orders_list WHERE id=? FOR UPDATE','i',[$oid]);
        if (!$o || (int)$o['userid']!==$uid || (int)$o['status']!==1 ||
            (int)$o['server_id']!==$sid || (int)$o['fileid']!==$pid)
            throw new DomainException('checkout_order_changed');
        $q['order_id']=$oid;$q['variant']='managed';$q['remark']=$o['remark'];$q['uuid']=(string)$o['uuid'];
        if ($add) {
            if (!achbotAddonPayLocal($db,$pay,$o)) throw new DomainException('checkout_addon_changed');
            $units=achbotPinnedAddonUnits($pay);
            if ($units===null) throw new DomainException('checkout_addon_changed');
            $q['kind']=$parts[1]==='DAY'?'ADD_TIME':'ADD_VOLUME';
            $q[$parts[1]==='DAY'?'days':'volume']=$units;
            if (($pay['addon_scope_snapshot']??null)!==null)
                $q['policy']['addon_scope']=json_decode($pay['addon_scope_snapshot'],true,32,JSON_THROW_ON_ERROR);
        } else {
            $p=achbotProviderRow($db,'SELECT days,volume FROM server_plans WHERE id=? FOR UPDATE','i',[$pid]);
            if (!$p) throw new DomainException('checkout_plan_changed');
            $q['kind']='RENEW';
            $q['days']=achbotWholeServiceUnits($p['days'],3650);
            $q['volume']=achbotWholeServiceUnits($p['volume'],achbotServiceVolumeMax(),true);
        }
    }
    if ($q['days']===null || $q['volume']===null) throw new DomainException('checkout_units_invalid');
    return achbotServiceQuoteRequest($q);
}

/** First method selection owns the command. Original receipt reopens do
 * not mint a different product or require current service availability. */
function achbotCheckoutAdmitDraftLocked(mysqli $db,array $pay,?array $attempt,
    string $method,array $states): ?int {
    $uid=(int)$pay['user_id'];$id=(int)$pay['id'];
    if ($attempt!==null) {
        if ($method==='wallet' || ($attempt['provider']??null)!==$method ||
            !is_string($attempt['wallet_invoice_sha256']??null) ||
            (int)($attempt['service_operation_id']??0)!==(int)($pay['checkout_operation_id']??0))
            throw new DomainException('checkout_original_attempt_conflict');
        if ($pay['checkout_operation_id']!==null) achbotCheckoutOriginalCommandLocked($db,$pay);
        elseif ($pay['type']!=='INCREASE_WALLET') throw new DomainException('checkout_original_command_missing');
        return $pay['checkout_operation_id']===null?null:(int)$pay['checkout_operation_id'];
    }
    if (!achbotPayUxUnclaimedDraftUsable($db,$pay,true) ||
        !achbotPayUxAudienceAllowed($db,$method,$uid,true,$pay['type']) ||
        ((int)$pay['price']<0 || ($method!=='wallet' && (int)$pay['price']===0)) || !preg_match('/\A[A-Za-z0-9_-]{1,50}\z/D',$pay['hash_id']))
        throw new DomainException('checkout_draft_unavailable');
    if (achbotProviderRow($db,'SELECT id FROM service_effects WHERE pay_row_id=? LIMIT 1 FOR UPDATE','i',[$id]))
        throw new DomainException('checkout_historical_effect_pending');
    if ($pay['type']==='INCREASE_WALLET') {
        if ($method==='wallet' || $pay['checkout_operation_id']!==null)
            throw new DomainException('checkout_topup_method_invalid');
        return null;
    }
    if ($pay['checkout_operation_id']!==null) {
        [$op]=achbotCheckoutOriginalCommandLocked($db,$pay);
        if ($op['stage']!=='awaiting_funds') throw new DomainException('checkout_original_command_closed');
        return (int)$op['id'];
    }
    $q=achbotCheckoutDraftRequestLocked($db,$pay,$states);
    $target=achbotServiceLocalTargetLocked($db,$uid,$q,true)['identity'];
    $adapter=achbotProviderDefinition($target['provider']);
    if (!isset($adapter['service_checkout_pin']) ||
        !($adapter['service_checkout_pin'])($pay,$target,$q))
        throw new DomainException('checkout_original_target_changed');
    $operation=achbotServiceIssueLocked($db,$uid,'checkout',(string)$id,$q);
    $s=achbotProviderStatement($db,'UPDATE pays SET checkout_operation_id=? WHERE id=? AND checkout_operation_id IS NULL','ii',[$operation,$id]);
    $n=$s->affected_rows;$s->close();
    if ($n!==1) throw new RuntimeException('checkout_command_bind_failed');
    return $operation;
}

/** The actual customer wallet callback: exact owner/hash/family, no remote
 * calls, one debit and capacity reservation. Replaying the callback reads
 * the original funded command rather than executing old service handlers. */
function achbotCheckoutWallet(mysqli $db,string $hash,int $actor,string $mode,array $states): array {
    require_once __DIR__.'/WalletSettlement.php';
    achbotPaymentEvidenceRequireAutocommit($db);
    if (!achbotCheckoutCommandsPresent($db)) throw new RuntimeException('checkout_command_schema_missing');
    if (!$db->begin_transaction()) throw new RuntimeException('checkout_wallet_begin');
    try {
        $owner=achbotWalletLockOwnerLocked($db,$actor);
        $s=achbotProviderStatement($db,'SELECT * FROM pays WHERE hash_id=? AND user_id=? LIMIT 2 FOR UPDATE','si',[$hash,$actor]);
        $rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
        $pay=count($rows)===1?$rows[0]:null;
        if (!$pay || !achbotWalletModeMatchesRequest($pay,$mode) || !in_array($mode,['standard','custom','renew','day','volume'],true) ||
            ($mode==='standard' && $pay['type']!=='BUY_SUB'))
            throw new DomainException('checkout_draft_unavailable');
        $id=(int)$pay['id'];
        $attempt=achbotProviderRow($db,'SELECT * FROM payment_attempts WHERE pay_row_id=? FOR UPDATE','i',[$id]);
        if ($attempt) throw new DomainException('checkout_original_attempt_conflict');
        if ($pay['checkout_operation_id']!==null && $pay['state']==='paid_with_wallet') {
            [$op]=achbotCheckoutOriginalCommandLocked($db,$pay);
            if (achbotServiceFundLocked($db,$actor,(int)$op['id'])!=='already_funded')
                throw new RuntimeException('checkout_wallet_projection_invalid');
            $db->rollback();
            return ['status'=>'already_funded','operation'=>(int)$op['id'],'stage'=>$op['stage']];
        }
        $operation=achbotCheckoutAdmitDraftLocked($db,$pay,null,'wallet',$states);
        if ($operation===null) throw new DomainException('checkout_topup_method_invalid');
        $fund=achbotServiceFundLocked($db,$actor,$operation);
        if ($fund!=='funded') {
            $db->rollback(); // decline is not a claim on another method
            return ['status'=>$fund,'wallet'=>$owner['wallet'],'price'=>(int)$pay['price']];
        }
        $s=achbotProviderStatement($db,"UPDATE pays SET state='paid_with_wallet' WHERE id=? AND checkout_operation_id=?",'ii',[$id,$operation]);
        $n=$s->affected_rows;$s->close();
        if ($n!==1 || !$db->commit()) throw new RuntimeException('checkout_wallet_commit');
        return ['status'=>'funded','operation'=>$operation,'stage'=>'funded'];
    } catch (Throwable $e) {$db->rollback();throw $e;}
}
