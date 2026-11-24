<?php
declare(strict_types=1);
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {http_response_code(404);exit;}

/**
 * An old wallet BUY's processing_wallet + matching original hold were
 * committed with its debit. Opening balances already include that debit.
 * Only a never-dispatched custom single purchase has all the required
 * original entitlement/name data in that source. Never reconstruct missing
 * standard-plan units from today's catalogue, or replace an existing CREATE.
 *
 * This is a historical proof reader, not a second monetary writer.
 */
function achbotServiceHistoricalBuySourceLocked(mysqli $db,int $payId,array $owner,
    ?int $operationId,bool $completed=false): array {
    require_once __DIR__.'/../Finance/CheckoutAdmission.php';
    require_once __DIR__.'/../Panel/AccountNaming.php';
    $p=achbotProviderRow($db,'SELECT * FROM pays WHERE id=? FOR UPDATE','i',[$payId]);
    if (!$p || $p['type']!=='BUY_SUB' ||
        $p['state']!==($completed?'paid_with_wallet':'processing_wallet') ||
        (int)$p['price']<=0 || (int)$p['user_id']<=0 ||
        (int)$p['server_id']<=0 || (int)$p['plan_id']<=0 ||
        (int)$p['order_id']!==0 || (string)($p['payid']??'')!=='' ||
        (int)$p['agent_count']<0 || (int)$p['agent_count']>1 ||
        !preg_match('/\A[A-Za-z0-9_-]{1,50}\z/D',(string)$p['hash_id']) ||
        ($p['checkout_operation_id']===null?null:(int)$p['checkout_operation_id'])!==$operationId)
        throw new RuntimeException('service_historical_wallet_payment_invalid');
    $uid=(int)$p['user_id'];$sid=(int)$p['server_id'];$pid=(int)$p['plan_id'];
    $u=achbotProviderRow($db,'SELECT userid FROM users WHERE id=? FOR UPDATE','i',[$owner['id']]);
    if (!$u || (int)$u['userid']!==$uid)
        throw new RuntimeException('service_historical_wallet_owner_invalid');
    $days=achbotWholeServiceUnits($p['day'],36500);
    $volume=achbotWholeServiceUnits($p['volume'],achbotServiceVolumeMax());
    if ($days===null || $volume===null || !achbotEffectValidUsername((string)$p['description'],'pasarguard'))
        throw new RuntimeException('service_historical_wallet_quote_unproven');
    $snapshot=achbotProviderCanonicalSnapshot(json_decode((string)$p['provider_snapshot'],true,64,JSON_THROW_ON_ERROR));
    $target=['provider'=>'pasarguard','endpoint'=>$snapshot['endpoint_sha256'],
        'physical'=>'pg:'.$snapshot['instance_key'],'panel_id'=>$snapshot['panel_id'],'groups'=>$snapshot['group_ids']];
    $s=achbotProviderStatement($db,'SELECT * FROM service_effects WHERE pay_row_id=? ORDER BY id FOR UPDATE','i',[$payId]);
    $effects=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
    if (count($effects)!==1) throw new RuntimeException('service_historical_wallet_effect_conflict');
    $h=$effects[0];
    $digest=hash('sha256',json_encode(['wallet_hold',$payId,1,$pid,$sid,null,$target['endpoint']],JSON_THROW_ON_ERROR));
    if ($h['effect_kind']!=='wallet_hold' || (int)$h['ordinal']!==0 ||
        $h['source_kind']!==null || $h['source_ref']!==null || $h['actor_userid']!==null ||
        $h['state']!==($operationId===null?'reserved':'consumed') ||
        (int)$h['reserved_count']!==1 || (int)$h['target_server_id']!==$sid ||
        (int)$h['plan_id']!==$pid || $h['target_group_id']!==null ||
        $h['target_key']!==$target['physical'] || $h['endpoint_sha256']!==$target['endpoint'] ||
        $h['remote_username']!=='#wallet_hold_'.$payId || (int)$h['requested_expire']!==0 ||
        (int)$h['requested_bytes']!==0 || $h['order_id']!==null ||
        $h['provider_snapshot']!==null || $h['provider_state']!==null ||
        !preg_match('/\A[a-f0-9]{32}\z/D',$h['operation_id']) ||
        !hash_equals($h['request_sha256'],$digest))
        throw new RuntimeException('service_historical_wallet_hold_invalid');
    foreach (['verified_at','provider_reference_id','provider_connection_id','provider_remote_user_id',
        'provider_remote_created_at','provider_operation_marker','provider_entitlement_snapshot',
        'provider_mutation_key','provider_mutation_snapshot','provider_verified_at',
        'provider_evidence_created_at','provider_evidence_updated_at'] as $field) {
        if ($h[$field]!==null) throw new RuntimeException('service_historical_wallet_hold_invalid');
    }
    foreach (['payment_attempts','payment_delivery_intents','payment_followup_intents','referral_awards'] as $table) {
        if (achbotProviderRow($db,"SELECT id FROM $table WHERE pay_row_id=? LIMIT 1 FOR UPDATE",'i',[$payId]))
            throw new RuntimeException('service_historical_wallet_other_claim');
    }
    $opening=achbotProviderRow($db,"SELECT id,user_row_id,userid,delta_toman,balance_before,balance_after,actor_userid
        FROM wallet_entries WHERE source_kind='opening' AND source_ref=? AND leg=0 FOR UPDATE",'s',[(string)$owner['id']]);
    if (!$opening || (int)$opening['user_row_id']!==$owner['id'] || (int)$opening['userid']!==$uid ||
        (int)$opening['balance_before']!==0 || (int)$opening['delta_toman']!==(int)$opening['balance_after'] ||
        (int)$opening['actor_userid']!==0)
        throw new RuntimeException('service_historical_wallet_opening_invalid');
    $q=achbotServiceQuoteRequest(['kind'=>'BUY','variant'=>'custom','price'=>(int)$p['price'],
        'server_id'=>$sid,'plan_id'=>$pid,'order_id'=>0,'quantity'=>1,'days'=>$days,'volume'=>$volume,
        'remark'=>$p['description'],'uuid'=>'','naming_mode'=>'manual','explicit_name'=>true,
        'policy'=>['checkout'=>['hash'=>$p['hash_id'],'type'=>$p['type'],'fingerprint'=>achbotCheckoutDraftFingerprint($p)],
            'agent_bought'=>(int)$p['agent_bought']===1,
            'historical_wallet'=>['pay_id'=>$payId,'hold_id'=>(int)$h['id'],
                'hold_operation'=>$h['operation_id'],'hold_digest'=>$digest,
                'hold_created_at'=>$h['created_at'],'opening_id'=>(int)$opening['id'],
                'opening_balance'=>(int)$opening['balance_after']]]]);
    return ['request'=>$q,'target'=>$target];
}

/** Verify exact historical ownership during every normal worker funding read.
 * An opening row is NOT a debit for this purchase, and must never be used as one. */
function achbotServiceHistoricalBuyFundingLocked(mysqli $db,array $op,array $quote,array $owner): void {
    $payId=(int)$op['historical_pay_id'];
    if ($payId<=0 || $op['kind']!=='BUY' || $op['command_kind']!=='checkout' ||
        $op['command_ref']!==(string)$payId || $op['debit_entry_id']!==null ||
        (int)$op['reserved_count']!==1 ||
        $quote!==achbotServiceHistoricalBuySourceLocked($db,$payId,$owner,(int)$op['id'],$op['stage']==='completed'))
        throw new RuntimeException('service_historical_wallet_funding_conflict');
}

/** Project the original payment as finished only in the verified service
 * completion transaction. Retain its hash, amount and source hold. */
function achbotServiceHistoricalBuyCompleteLocked(mysqli $db,array $op): void {
    if (($op['historical_pay_id']??null)===null) return;
    if ($op['stage']!=='applied') throw new RuntimeException('service_historical_wallet_completion_stage');
    $s=achbotProviderStatement($db,"UPDATE pays SET state='paid_with_wallet'
        WHERE id=? AND checkout_operation_id=? AND state='processing_wallet'",
        'ii',[(int)$op['historical_pay_id'],(int)$op['id']]);
    $n=$s->affected_rows;$s->close();
    if ($n!==1) throw new RuntimeException('service_historical_wallet_completion_conflict');
}
