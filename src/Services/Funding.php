<?php
declare(strict_types=1);

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '')===__FILE__) {http_response_code(404);exit;}
require_once __DIR__.'/../Wallet/Ledger.php';
require_once __DIR__.'/../Purchase/Core.php';
require_once __DIR__.'/../Panel/ProviderStorage.php';

/**
 * Local quote admission and Wallet funding only. The trusted business adapter
 * supplies the already authorized/discounted price and request, never raw
 * callback fields. Native account observation and mutation are later phases;
 * no HTTP, dispatch, Telegram or referral effect here.
 */
function achbotServiceQuoteRequest(array $r): array {
    $kind=$r['kind']??null;$variant=$r['variant']??null;
    if (!is_string($kind) || !in_array($kind,['BUY','RENEW','ADD_VOLUME','ADD_TIME'],true) ||
        !in_array($variant,match($kind){
            'BUY'=>['standard','custom'],
            default=>['managed']
        },true)) throw new InvalidArgumentException('service_quote_kind');
    $q=['kind'=>$kind,'variant'=>$variant];
    foreach (['price','server_id','plan_id','order_id','quantity','days','volume'] as $key) {
        $v=$r[$key]??null;
        if (!is_int($v) || $v<0 ||
            ($key==='volume' ? $v>achbotServiceVolumeMax() : $v>2147483647))
            throw new InvalidArgumentException('service_quote_number_'.$key);
        $q[$key]=$v;
    }
    if ($q['server_id']===0 || $q['quantity']===0 ||
        ($kind!=='BUY' && $q['quantity']!==1) ||
        ($kind==='BUY' && ($q['order_id']!==0 || $q['plan_id']===0 ||
            $q['days']===0 || $q['volume']===0 || ($variant==='custom' && $q['quantity']!==1))) ||
        ($kind==='RENEW' && ($q['plan_id']===0 || $q['days']===0 ||
            $q['order_id']===0)) ||
        ($kind==='ADD_VOLUME' && ($q['order_id']===0 || $q['days']!==0 || $q['volume']===0)) ||
        ($kind==='ADD_TIME' && ($q['order_id']===0 || $q['days']===0 || $q['volume']!==0)))
        throw new InvalidArgumentException('service_quote_dimensions');
    $q['remark']=$r['remark']??'';
    $q['uuid']=$r['uuid']??'';
    $q['naming_mode']=$r['naming_mode']??'';
    $q['explicit_name']=$r['explicit_name']??false;
    if (!is_string($q['remark']) || strlen($q['remark'])>255 ||
        !is_string($q['uuid']) || strlen($q['uuid'])>200 ||
        !is_string($q['naming_mode']) || !is_bool($q['explicit_name']) ||
        ($kind==='BUY' && $q['remark']===''))
        throw new InvalidArgumentException('service_quote_identity');
    // The business adapter pins the original authorization/pricing/agent
    // policy inputs here. Never reprice from the mutable menu during funding.
    $q['policy']=$r['policy']??[];
    if (!is_array($q['policy'])) throw new InvalidArgumentException('service_quote_policy');
    return $q;
}

function achbotServiceJson(array $value): string {
    return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}

/** SQL-only current subject/target. DomainException = known invalidation;
 * storage errors and unresolved Native identity must propagate, not be
 * mistaken for a business refusal or a successful settlement. */
function achbotServiceLocalTargetLocked(mysqli $db,int $userId,array $q,bool $issuing): array {
    $sid=$q['server_id'];$buy=$q['kind']==='BUY';
    $s=achbotProviderRow($db,'SELECT i.active,i.state,i.ucount,c.type
        FROM server_info i JOIN server_config c ON c.id=i.id WHERE i.id=? FOR UPDATE','i',[$sid]);
    if (!$s || (int)$s['active']!==1 ||
        !in_array((int)$s['state'],$buy?[1]:[1,2],true))
        throw new DomainException('service_server_unavailable');
    $adapter=achbotProviderDefinition($s['type']);
    if ($adapter===null || !isset($adapter['service_target']))
        throw new DomainException('service_server_unavailable');
    $o=null;$p=null;
    if ($q['order_id']>0) {
        $o=achbotProviderRow($db,'SELECT id,userid,server_id,fileid,inbound_id,status,remark,uuid
            FROM orders_list WHERE id=? FOR UPDATE','i',[$q['order_id']]);
        if (!$o || (int)$o['userid']!==$userId || (int)$o['status']!==1 ||
            (int)$o['server_id']!==$sid || (int)$o['fileid']!==$q['plan_id'] ||
            $o['remark']!==$q['remark'] || (string)$o['uuid']!==$q['uuid'])
            throw new DomainException('service_order_changed');
    }
    if ($q['plan_id']>0) {
        $p=achbotProviderRow($db,'SELECT * FROM server_plans WHERE id=? FOR UPDATE','i',[$q['plan_id']]);
        if (!$p || ($buy && (int)$p['active']!==1))
            throw new DomainException('service_plan_unavailable');
        if ($buy && (int)$p['server_id']!==$sid)
            throw new DomainException('service_plan_target_changed');
        // Only at quote creation compare standard plan units. Afterwards use
        // the pinned units even when the commercial plan is repriced.
        if ($issuing && (($buy && $q['variant']==='standard') || $q['kind']==='RENEW') &&
            ((float)$p['days']!==(float)$q['days'] || (float)$p['volume']!==(float)$q['volume']))
            throw new DomainException('service_plan_units_changed');
    }
    // The source-owned adapter validates its supported operation, physical
    // identity, plan/account compatibility and naming; SQL only, no HTTP.
    // Its DomainException means known ineligibility, not failed storage.
    $identity=($adapter['service_target'])($db,$q,$o,$p);
    if (!is_array($identity) || isset($identity['provider']) ||
        !is_string($identity['physical']??null) || $identity['physical']==='')
        throw new RuntimeException('service_adapter_target_invalid');
    $target=['provider'=>$s['type']]+$identity;
    return ['identity'=>$target,'capacity'=>$s['ucount']];
}

function achbotServiceReadLocked(mysqli $db,int $userId,int $operationId): array {
    $owner=achbotWalletLockOwnerLocked($db,$userId);
    $op=achbotProviderRow($db,'SELECT * FROM service_operations WHERE id=? FOR UPDATE','i',[$operationId]);
    if (!$op || (int)$op['userid']!==$userId || (int)$op['user_row_id']!==$owner['id'])
        throw new RuntimeException('service_operation_owner');
    $payload=(string)$op['quote_payload'];
    if (!hash_equals($op['quote_sha256'],hash('sha256',$payload)))
        throw new RuntimeException('service_quote_integrity');
    $quote=json_decode($payload,true,512,JSON_THROW_ON_ERROR);
    if (!is_array($quote['request']??null) || !is_array($quote['target']??null) ||
        $op['kind']!==($quote['request']['kind']??null) ||
        (int)$op['price_toman']!==($quote['request']['price']??null))
        throw new RuntimeException('service_quote_inconsistent');
    return [$op,$quote,$owner];
}

/** Exact command replay returns the original immutable quote, not a new quote
 * or a new debit. Caller owns the surrounding transaction. */
function achbotServiceIssueLocked(mysqli $db,int $userId,string $commandKind,string $commandRef,array $request): int {
    $owner=achbotWalletLockOwnerLocked($db,$userId);
    if (!preg_match('/\A[a-z][a-z0-9_]{0,31}\z/D',$commandKind) ||
        $commandRef==='' || strlen($commandRef)>128) throw new InvalidArgumentException('service_command_identity');
    $q=achbotServiceQuoteRequest($request);
    $old=achbotProviderRow($db,'SELECT id FROM service_operations WHERE command_kind=? AND command_ref=? FOR UPDATE',
        'ss',[$commandKind,$commandRef]);
    if ($old) {
        [$op,$quote]=achbotServiceReadLocked($db,$userId,(int)$old['id']);
        if ($quote['request']!==$q) throw new RuntimeException('service_command_conflict');
        return (int)$op['id'];
    }
    $local=achbotServiceLocalTargetLocked($db,$userId,$q,true);
    $payload=achbotServiceJson(['request'=>$q,'target'=>$local['identity']]);
    $hash=hash('sha256',$payload);
    $s=achbotProviderStatement($db,'INSERT INTO service_operations
        (command_kind,command_ref,user_row_id,userid,kind,quote_payload,quote_sha256,price_toman,created_at,updated_at)
        VALUES (?,?,?,?,?,?,?,?,NOW(6),NOW(6))','ssiisssi',
        [$commandKind,$commandRef,$owner['id'],$userId,$q['kind'],$payload,$hash,$q['price']]);
    $id=(int)$s->insert_id;$s->close();
    if ($id<=0) throw new RuntimeException('service_issue_write');
    return $id;
}

/** Only this transition reserves service money and BUY capacity. No Native
 * execution is authorized by a payment method or an unfunded operation. */
function achbotServiceFundLocked(mysqli $db,int $userId,int $operationId): string {
    [$op,$quote,$owner]=achbotServiceReadLocked($db,$userId,$operationId);
    $q=$quote['request'];$price=$q['price'];$count=$q['kind']==='BUY'?$q['quantity']:0;
    if ($op['stage']==='cancelled') return 'request_invalidated';
    if (in_array($op['stage'],['funded','prepared','dispatching','applied','completed'],true)) {
        if ($price>0) {
            $e=achbotWalletFindEntryLocked($db,$owner['id'],$userId,'service_debit',(string)$operationId,0,-$price);
            if (!$e || $e['id']!==(int)$op['debit_entry_id'])
                throw new RuntimeException('service_debit_finality_conflict');
        } elseif ($op['debit_entry_id']!==null) throw new RuntimeException('service_zero_debit_conflict');
        return 'already_funded';
    }
    if ($op['stage']!=='awaiting_funds' || $op['debit_entry_id']!==null ||
        (int)$op['reserved_count']!==0) throw new RuntimeException('service_funding_state');
    try {$local=achbotServiceLocalTargetLocked($db,$userId,$q,false);}
    catch (DomainException $invalid) {return achbotServiceFundingRefusedLocked($db,$operationId,'request_invalidated',$invalid->getMessage());}
    if ($quote['target']!==$local['identity'])
        return achbotServiceFundingRefusedLocked($db,$operationId,'request_invalidated','service_target_changed');
    if ($price>0 && $owner['wallet']<$price)
        return achbotServiceFundingRefusedLocked($db,$operationId,'insufficient_funds','wallet_balance_insufficient');
    if ($count>0) {
        $available=filter_var($local['capacity'],FILTER_VALIDATE_INT);
        if ($available===false || $available<0) throw new RuntimeException('service_capacity_invalid');
        if ($available<$count) return achbotServiceFundingRefusedLocked($db,$operationId,'capacity_unavailable','service_capacity_insufficient');
        $s=achbotProviderStatement($db,'UPDATE server_info SET ucount=ucount-? WHERE id=? AND ucount>=?',
            'iii',[$count,$q['server_id'],$count]);
        $n=$s->affected_rows;$s->close();
        if ($n!==1) throw new RuntimeException('service_capacity_reservation_conflict');
    }
    $debit=null;
    if ($price>0) {
        $e=achbotWalletApplyLocked($db,$userId,'service_debit',(string)$operationId,0,-$price);
        if ($e['replayed']) throw new RuntimeException('service_orphan_debit');
        $debit=$e['id'];
    }
    $s=achbotProviderStatement($db,"UPDATE service_operations SET stage='funded',debit_entry_id=?,
        reserved_count=?,next_attempt_at=NOW(6),failure_code=NULL,updated_at=NOW(6)
        WHERE id=? AND stage='awaiting_funds'",'iii',[$debit,$count,$operationId]);
    $n=$s->affected_rows;$s->close();
    if ($n!==1) throw new RuntimeException('service_funding_write');
    return 'funded';
}


/** Local refusal is diagnostic only, not evidence of a Native write. */
function achbotServiceFundingRefusedLocked(mysqli $db,int $operationId,string $result,string $reason): string {
    $s=achbotProviderStatement($db,"UPDATE service_operations SET failure_code=?,updated_at=NOW(6)
        WHERE id=? AND stage='awaiting_funds'",'si',[$reason,$operationId]);$s->close();
    return $result;
}

/** Cancellation of an uncharged original command; no compensation or capacity
 * change. Funded/uncertain operations require their own proven disposition. */
function achbotServiceCancelUnfundedLocked(mysqli $db,int $userId,int $operationId): void {
    [$op]=achbotServiceReadLocked($db,$userId,$operationId);
    if ($op['stage']==='cancelled') {
        if ($op['debit_entry_id']!==null || (int)$op['reserved_count']!==0)
            throw new RuntimeException('service_cancel_has_funding');
        return;
    }
    if ($op['stage']!=='awaiting_funds') throw new RuntimeException('service_cancel_has_funding');
    $s=achbotProviderStatement($db,"UPDATE service_operations SET stage='cancelled',updated_at=NOW(6)
        WHERE id=? AND stage='awaiting_funds' AND debit_entry_id IS NULL AND reserved_count=0",
        'i',[$operationId]);
    $n=$s->affected_rows;$s->close();
    if ($n!==1) throw new RuntimeException('service_cancel_write');
}
