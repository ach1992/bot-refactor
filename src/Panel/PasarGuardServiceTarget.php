<?php
declare(strict_types=1);

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '')===__FILE__) {http_response_code(404);exit;}

function achbotPgServiceOrderTarget(array $ctx): array {
    $p=$ctx['panel'];$o=$ctx['order'];
    return ['provider'=>'pasarguard','endpoint'=>hash('sha256',rtrim($p['api_url'],'/')),
        'physical'=>'pg:'.$p['instance_key'],'panel_id'=>$p['id'],
        'account'=>['remark'=>$o['remark'],'uuid'=>(string)$o['uuid'],'native'=>$ctx['identity']]];
}

/** PasarGuard's local, locked service-admission contract. No HTTP, money,
 * service dispatch or Telegram. Known business/identity refusals must be
 * distinguished from DB/invariant errors, which roll back the caller. */
function achbotPasarGuardServiceTargetLocked(mysqli $db,array $q,?array $o,?array $p): array {
    achbotPgLoad();
    require_once __DIR__.'/AccountNaming.php';
    require_once __DIR__.'/../Services/Ownership.php';
    try {
        $sid=$q['server_id'];$buy=$q['kind']==='BUY';
        $s=achbotProviderRow($db,'SELECT type,panel_url,provider_connection_id FROM server_config WHERE id=? FOR UPDATE','i',[$sid]);
        if (!$s || $s['type']!=='pasarguard')
            throw new DomainException('service_server_unavailable');
        $target=['endpoint'=>hash('sha256',rtrim($s['panel_url'],'/'))];
        $panel=achbotProviderPanel($db,(int)$s['provider_connection_id'],true);
        if ($target['endpoint']!==hash('sha256',rtrim($panel['api_url'],'/')))
            throw new DomainException('service_panel_changed');
        $target['physical']='pg:'.$panel['instance_key'];
        $target['panel_id']=$panel['id'];
        if ($o!==null) {
            if ((int)$o['inbound_id']!==0) throw new DomainException('service_order_changed');
            // A pending mutation is not cancellation proof. The execution
            // worker serializes it using the pinned physical identity.
            $ctx=achbotPgOrderContext($db,$q['order_id'],true,true);
            $a=$ctx['account'];
            if ($a['state']==='mutating' &&
                !preg_match('/^service:[1-9][0-9]*$/D',(string)$a['mutation_key']))
                throw new DomainException('service_account_mutation_pending');
            $lock='achbot-plan-'.substr(hash('sha256','pg:'.$a['id']),0,40);
            if (achbotServicePlanChangePendingLocked($db,$lock))
                throw new DomainException('service_plan_change_pending');
            if ($a['source_kind']==='service_operation') {
                $e=achbotProviderRow($db,'SELECT * FROM service_effects WHERE id=? FOR UPDATE','i',[$a['effect_id']]);
                if (!$e || achbotServiceOriginalBuyLocked($db,$e)['stage']!=='completed')
                    throw new DomainException('service_original_buy_pending');
            }
            $target['account']=achbotPgServiceOrderTarget($ctx)['account'];
        }
        if ($p!==null) {
            if ((int)$p['inbound_id']!==0) throw new DomainException('service_plan_unavailable');
            if ((int)$p['server_id']!==$sid) {
                $alias=achbotProviderRow($db,'SELECT c.type,c.provider_connection_id
                    FROM server_info i JOIN server_config c ON c.id=i.id WHERE i.id=? FOR UPDATE',
                    'i',[(int)$p['server_id']]);
                if (!$alias || $alias['type']!=='pasarguard' ||
                    $alias['provider_connection_id']!==$s['provider_connection_id'])
                    throw new DomainException('service_plan_target_changed');
            }
            if ($buy) $target['groups']=achbotProviderGroups($p['provider_group_ids']);
        }
        if ($buy) {
            try {
                // Check the largest ordinal too: its suffix can be longer.
                foreach (array_unique([1,$q['quantity']]) as $ordinal)
                    achbotStyledAccountRemark($q['remark'],'pasarguard','service:quote-check',
                        $ordinal,$q['quantity'],$q['naming_mode'],$q['explicit_name']);
            } catch (InvalidArgumentException $e) {
                throw new DomainException('service_account_name_invalid',0,$e);
            }
        }
        return $target;
    } catch (RuntimeException $invalid) {
        $reason=$invalid->getMessage();
        if (!in_array($reason,['provider_panel_invalid','provider_panel_unavailable',
            'provider_connection_ambiguous','provider_groups_invalid',
            'pg_order_identity_changed','pg_account_not_verified','pg_account_panel_changed',
            'provider_order_identity_invalid','provider_order_origin_invalid',
            'provider_order_origin_identity_invalid','provider_account_provenance_invalid'],true))
            throw $invalid;
        throw new DomainException($reason,0,$invalid);
    }
}

/** SQL-only comparison against the existing draft's original PG pin. */
function achbotPasarGuardCheckoutPinMatches(array $pay,array $target,array $q): bool {
    try {
        $pin=json_decode((string)($pay['provider_snapshot']??''),true,64,JSON_THROW_ON_ERROR);
        if (($pin['provider']??null)!=='pasarguard' ||
            (int)($pin['panel_id']??0)!==$target['panel_id'] ||
            'pg:'.($pin['instance_key']??'')!==$target['physical'] ||
            ($pin['endpoint_sha256']??null)!==$target['endpoint']) return false;
        if ($q['kind']==='BUY')
            return achbotProviderGroups($pin['group_ids']??null)===$target['groups'];
        foreach ($target['account']['native'] as $key=>$value)
            if (!array_key_exists($key,$pin) || (string)$pin[$key]!== (string)$value) return false;
        return true;
    } catch (Throwable $e) {return false;}
}
