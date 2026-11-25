<?php
declare(strict_types=1);
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {http_response_code(404);exit;}
require_once __DIR__.'/FundingCheckout.php';
require_once __DIR__.'/PaymentLifecycle.php';
require_once __DIR__.'/../Plans/Addons.php';
require_once __DIR__.'/../Services/Ownership.php';
require_once __DIR__.'/../Purchase/AgentPricing.php';

/** The final schema owns newly selected payment methods. An error is never
 * interpreted as permission to fall back to a legacy balance writer. */
function achbotCheckoutRequireSchema(mysqli $db): void {
    $r=achbotProviderRow($db,"SELECT DATA_TYPE,COLUMN_TYPE,IS_NULLABLE
        FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
        AND TABLE_NAME='pays' AND COLUMN_NAME='checkout_operation_id'");
    if ($r===null) throw new RuntimeException('checkout_command_schema_missing');
    if ($r['DATA_TYPE']!=='bigint' || !str_contains($r['COLUMN_TYPE'],'unsigned') ||
        $r['IS_NULLABLE']!=='YES' || !achbotWalletLedgerReady($db) ||
        !achbotServiceOperationsPresent($db))
        throw new RuntimeException('checkout_command_schema_invalid');
    $context=achbotProviderRow($db,"SELECT DATA_TYPE,IS_NULLABLE FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pays' AND COLUMN_NAME='checkout_draft_context'");
    if (!$context || !in_array($context['DATA_TYPE'],['json','longtext'],true) || $context['IS_NULLABLE']!=='YES')
        throw new RuntimeException('checkout_draft_context_schema_missing');
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
        (string)($p['checkout_draft_context']??''),
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

/** No monetary method is needed for an explicitly authorized original
 * zero-price standard commercial purchase. Unlike a discount-code outcome,
 * this authorization comes from the current primary-admin or agency policy.
 * The owner and target remain locked through actual reservation. */
function achbotCheckoutZeroCommercialLocked(mysqli $db,array $pay): ?string {
    if ($pay['type']!=='BUY_SUB' || (int)$pay['price']!==0 ||
        (float)$pay['day']!==0.0 || (float)$pay['volume']!==0.0) return null;
    $actor=(int)$pay['user_id'];$primary=(int)($GLOBALS['admin']??0);
    $owner=achbotProviderRow($db,'SELECT is_agent,discount_percent,step FROM users FORCE INDEX (idx_users_userid_v1) WHERE userid=? FOR UPDATE','i',[$actor]);
    $plan=achbotProviderRow($db,'SELECT p.*,cat.active AS category_active,cat.parent AS category_parent FROM server_plans p JOIN server_categories cat ON cat.id=p.catid WHERE p.id=? FOR UPDATE','i',[(int)$pay['plan_id']]);
    if (!$owner || $owner['step']==='banned' || !$plan ||
        (int)$plan['price']<=0 || (int)$plan['active']!==1 ||
        (int)$plan['category_active']!==1 || (int)$plan['category_parent']!==0 ||
        (int)$plan['server_id']!==(int)$pay['server_id']) return null;
    if ($primary>0 && $actor===$primary) return 'primary_admin';
    $state=achbotPayUxCurrentStatesLocked($db);
    if (!$state || ($state['sellState']??'off')!=='on' ||
        !achbotAgencyActive($owner,$state)) return null;
    [,,$percent]=achbotAgencyPrice($owner,$state,(int)$plan['id'],(int)$plan['server_id'],(int)$plan['price']);
    return $percent===100?'agency':null;
}

/** Raw zero-price custom quotes must be authorized by the current
 * configured rates and, for an agency quote, the current agency entitlement.
 * Discount-code zero is distinct: the original positive quote was sealed
 * before authorized discount consumption. */
function achbotCheckoutZeroCustomLocked(mysqli $db,array $pay): ?string {
    if ($pay['type']!=='BUY_SUB' || (int)$pay['price']!==0 ||
        (float)$pay['day']<=0 || (float)$pay['volume']<=0 ||
        (int)$pay['agent_count']>1) return null;
    $actor=(int)$pay['user_id'];$primary=(int)($GLOBALS['admin']??0);
    $owner=achbotProviderRow($db,'SELECT is_agent,discount_percent,step FROM users FORCE INDEX (idx_users_userid_v1) WHERE userid=? FOR UPDATE','i',[$actor]);
    $plan=achbotProviderRow($db,'SELECT p.*,cat.active AS category_active,cat.parent AS category_parent FROM server_plans p JOIN server_categories cat ON cat.id=p.catid WHERE p.id=? FOR UPDATE','i',[(int)$pay['plan_id']]);
    $state=achbotPayUxCurrentStatesLocked($db);
    $agent=(int)$pay['agent_bought']===1;$mode=$agent?'one':'none';
    if (!$owner || $owner['step']==='banned' || !$plan || !$state ||
        (int)$plan['price']<=0 || (int)$plan['active']!==1 ||
        (int)$plan['category_active']!==1 || (int)$plan['category_parent']!==0 ||
        (int)$plan['server_id']!==(int)$pay['server_id'] ||
        !achbotCustomerSaleAllowed($state,$actor,$primary) ||
        !achbotCustomPurchaseEnabled($state,$mode) ||
        ($agent && !achbotAgencyActive($owner,$state))) return null;
    if (!achbotCustomRatesAreZero($owner,$state,(int)$plan['id'],(int)$plan['server_id'])) return null;
    return (int)$state['gbPrice']===0 && (int)$state['dayPrice']===0?'configured_rates':'agency';
}

/** Current BUY admission applies to positive and free unissued drafts.
 * Already issued receipt attempts and funded replay never use this gate. */
function achbotCheckoutBuyPolicyLocked(mysqli $db,array $pay): void {
    $uid=(int)$pay['user_id'];$pid=(int)$pay['plan_id'];$sid=(int)$pay['server_id'];
    $owner=achbotProviderRow($db,'SELECT step,is_agent FROM users FORCE INDEX (idx_users_userid_v1) WHERE userid=? FOR UPDATE','i',[$uid]);
    $states=achbotPayUxCurrentStatesLocked($db);
    if (!$owner || $owner['step']==='banned' || $states===null ||
        !achbotCustomerSaleAllowed($states,$uid,(int)($GLOBALS['admin']??0)))
        throw new DomainException('checkout_sales_disabled');
    if ((int)$pay['agent_bought']===1 && !achbotAgencyActive($owner,$states))
        throw new DomainException('checkout_agency_unavailable');
    if (((float)$pay['day']!==0.0 || (float)$pay['volume']!==0.0) &&
        !achbotCustomPurchaseEnabled($states,(int)$pay['agent_bought']===1?'one':'none'))
        throw new DomainException('checkout_custom_unavailable');
    $plan=achbotProviderRow($db,'SELECT p.price,p.active,p.server_id,cat.active AS category_active,cat.parent AS category_parent FROM server_plans p JOIN server_categories cat ON cat.id=p.catid WHERE p.id=? FOR UPDATE','i',[$pid]);
    if (!$plan || (int)$plan['price']<=0 || (int)$plan['active']!==1 ||
        (int)$plan['server_id']!==$sid || (int)$plan['category_active']!==1 || (int)$plan['category_parent']!==0)
        throw new DomainException('service_plan_unavailable');
}

/** A raw zero renewal/add-on is only free when the current eligible base
 * offer and current agency policy actually resolve to zero. The add-on
 * price/scope is re-read under the existing quantity-range lock. */
function achbotCheckoutZeroManagedLocked(mysqli $db,array $pay): ?string {
    if ((int)$pay['price']!==0) return null;
    $type=(string)$pay['type'];
    $kind=match(true) {
        $type==='RENEW_ACCOUNT'=>'RENEW',
        str_starts_with($type,'INCREASE_DAY_')=>'ADD_TIME',
        str_starts_with($type,'INCREASE_VOLUME_')=>'ADD_VOLUME',
        default=>null
    };
    if ($kind===null) return null;
    $uid=(int)$pay['user_id'];
    $owner=achbotProviderRow($db,'SELECT step,is_agent,discount_percent FROM users FORCE INDEX (idx_users_userid_v1) WHERE userid=? FOR UPDATE','i',[$uid]);
    $states=achbotPayUxCurrentStatesLocked($db);
    $order=achbotProviderRow($db,'SELECT * FROM orders_list WHERE id=? FOR UPDATE','i',[(int)$pay['order_id']]);
    if (!$owner || $owner['step']==='banned' || $states===null || !$order ||
        (int)$order['userid']!==$uid || (int)$order['fileid']!==(int)$pay['plan_id'] ||
        (int)$order['server_id']!==(int)$pay['server_id']) return null;
    $plan=achbotManagedServicePlan($db,$order,$kind,$states,true);
    if ($plan===null) return null;
    if ($kind==='RENEW') $base=(int)$plan['price'];
    else {
        if (!achbotAddonDraftCurrent($db,(int)$pay['id'])) return null;
        $snapshot=json_decode((string)$pay['addon_scope_snapshot'],true,32,JSON_THROW_ON_ERROR);
        $base=$snapshot['base_price']??null;
        if (!is_int($base) || $base<0) return null;
    }
    if ($base===0) return 'configured_offer';
    [$effective,$active,$percent]=achbotAgencyPrice($owner,$states,(int)$pay['plan_id'],(int)$pay['server_id'],$base);
    return $effective===0 && $active && $percent===100?'agency':null;
}

/** A zero-value confirmation is not a payment-method permission. Only
 * server-sealed raw zero offers or an atomically consumed discount code
 * may use it; never a zero wallet recharge or an unsealed arbitrary price. */
function achbotCheckoutZeroAuthorizedLocked(mysqli $db,array $pay,array $context): bool {
    if ((int)$pay['price']!==0 || $pay['type']==='INCREASE_WALLET') return false;
    if (isset($context['zero_commercial'])) {
        if (achbotCheckoutZeroCommercialLocked($db,$pay)!==$context['zero_commercial'])
            throw new DomainException('checkout_zero_commercial_changed');
        return true;
    }
    if (isset($context['zero_custom'])) {
        if (achbotCheckoutZeroCustomLocked($db,$pay)!==$context['zero_custom'])
            throw new DomainException('checkout_zero_custom_changed');
        return true;
    }
    if (isset($context['zero_managed'])) {
        if (achbotCheckoutZeroManagedLocked($db,$pay)!==$context['zero_managed'])
            throw new DomainException('checkout_zero_managed_changed');
        return true;
    }
    if (($context['discount_applied']??false)===true) return true;
    throw new DomainException('checkout_zero_unverified');
}

/** Seal the entitlement and naming policy before the customer sees a standard
 * purchase/renewal quote. NULL is never permission to rebuild a stale quote. */
function achbotCheckoutSealDraftLocked(mysqli $db,int $id,array $pay,array $display,array $states): void {
    $standard=$pay['type']==='BUY_SUB' && (float)$pay['day']===0.0 && (float)$pay['volume']===0.0;
    // NULL is an inactive historical draft, never a current command.
    $context=['version'=>1];
    if ($pay['type']==='BUY_SUB') {
        achbotCheckoutBuyPolicyLocked($db,$pay);
        $plan=achbotProviderRow($db,'SELECT catid,price,title FROM server_plans WHERE id=? FOR UPDATE','i',[(int)$pay['plan_id']]);
        if (!$plan) throw new DomainException('checkout_plan_changed');
        $context['category_id']=(int)$plan['catid'];
        $owner=achbotProviderRow($db,'SELECT is_agent FROM users FORCE INDEX (idx_users_userid_v1) WHERE userid=? FOR UPDATE','i',[(int)$pay['user_id']]);
        if (!$owner) throw new DomainException('checkout_owner_unavailable');
        $context['buyer_was_agent']=(int)$owner['is_agent']===1;
        if ((int)$pay['agent_bought']===1) {
            $current=achbotPayUxCurrentStatesLocked($db);
            if (!$current || !achbotAgencyActive($owner,$current))
                throw new DomainException('checkout_agency_unavailable');
        }
        if ((int)$plan['price']<=0) throw new DomainException('checkout_purchase_offering_invalid');
        $category=achbotProviderRow($db,'SELECT title FROM server_categories WHERE id=? AND parent=0 AND active=1 FOR UPDATE','i',[(int)$plan['catid']]);
        if (!$category) throw new DomainException('checkout_view_category_unavailable');
        $serverLabel=achbotProviderRow($db,'SELECT title FROM server_info WHERE id=? FOR UPDATE','i',[(int)$pay['server_id']]);
        if (!$serverLabel) throw new DomainException('checkout_plan_changed');
        $context['labels']=['category'=>(string)$category['title'],'plan'=>(string)$plan['title'],'server'=>(string)$serverLabel['title']];
        if ($standard && (int)$pay['price']===0) {
            $authorization=achbotCheckoutZeroCommercialLocked($db,$pay);
            if ($authorization===null) throw new DomainException('checkout_zero_commercial_denied');
            $context['zero_commercial']=$authorization;
        } elseif (!$standard && (int)$pay['price']===0) {
            $authorization=achbotCheckoutZeroCustomLocked($db,$pay);
            if ($authorization===null) throw new DomainException('checkout_zero_custom_denied');
            $context['zero_custom']=$authorization;
        }
    } elseif ((int)$pay['price']===0 && $pay['type']!=='INCREASE_WALLET') {
        $authorization=achbotCheckoutZeroManagedLocked($db,$pay);
        if ($authorization===null) throw new DomainException('checkout_zero_managed_denied');
        $context['zero_managed']=$authorization;
    }

    if ($standard || $pay['type']==='RENEW_ACCOUNT') {
        $plan=achbotProviderRow($db,'SELECT days,volume FROM server_plans WHERE id=? FOR UPDATE','i',[(int)$pay['plan_id']]);
        $server=achbotProviderRow($db,'SELECT remark FROM server_info WHERE id=? FOR UPDATE','i',[(int)$pay['server_id']]);
        $days=achbotWholeServiceUnits($display['days']??null,achbotServiceDaysMax());
        $volume=achbotWholeServiceUnits($display['volume']??null,achbotServiceVolumeMax(),!$standard);
        if (!$plan || !$server || $days===null || $volume===null ||
            (float)$plan['days']!==(float)$days || (float)$plan['volume']!==(float)$volume)
            throw new DomainException('checkout_displayed_plan_changed');
        $context+=['days'=>$days,'volume'=>$volume,
            'naming_mode'=>(string)($states['remark']??''),'prefix'=>(string)$server['remark']];
    }
    $context=achbotServiceJson($context);
    $s=achbotProviderStatement($db,'UPDATE pays SET checkout_draft_context=? WHERE id=? AND checkout_draft_context IS NULL','si',[$context,$id]);
    $n=$s->affected_rows;$s->close();
    if ($n!==1) throw new RuntimeException('checkout_draft_context_bind_failed');
}
function achbotCheckoutReadDraftContext(array $pay): array {
    $context=json_decode((string)($pay['checkout_draft_context']??''),true);
    if (!is_array($context) || ($context['version']??null)!==1 || !is_int($context['days']??null) || !is_int($context['volume']??null) ||
        !is_string($context['naming_mode']??null) || !is_string($context['prefix']??null))
        throw new DomainException('checkout_draft_context_missing');
    return $context;
}

/** Original UI draft -> immutable provider-neutral service request.
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
        achbotCheckoutBuyPolicyLocked($db,$pay);
        $p=achbotProviderRow($db,'SELECT * FROM server_plans WHERE id=? FOR UPDATE','i',[$pid]);
        $s=achbotProviderRow($db,'SELECT remark FROM server_info WHERE id=? FOR UPDATE','i',[$sid]);
        if (!$p || !$s || (int)$p['server_id']!==$sid || (int)$pay['order_id']!==0)
            throw new DomainException('checkout_plan_changed');
        $custom=(float)$pay['day']!==0.0 || (float)$pay['volume']!==0.0;
        $q['kind']='BUY';$q['variant']=$custom?'custom':'standard';
        $q['quantity']=max(1,(int)$pay['agent_count']);
        $context=$custom?null:achbotCheckoutReadDraftContext($pay);
        $q['days']=achbotWholeServiceUnits($custom?$pay['day']:$context['days'],achbotServiceDaysMax());
        $q['volume']=achbotWholeServiceUnits($custom?$pay['volume']:$context['volume'],achbotServiceVolumeMax());
        $mode=$custom?'manual':$context['naming_mode'];
        if ($custom) {$q['remark']=(string)$pay['description'];$q['explicit_name']=true;}
        else {
            // The existing empty manual bulk description falls back to
            // generated names; preserve an explicit single name exactly.
            if ($mode==='manual' && $q['quantity']>1 && trim((string)$pay['description'])==='') $mode='';
            $q['remark']=buildRemark(['remark'=>$mode],$context['prefix'],$pay['description'],$uid);
            $q['explicit_name']=$mode==='manual';
        }
        $q['naming_mode']=$mode;
        $q['policy']['agent_bought']=(int)$pay['agent_bought']===1;
        $originalContext=json_decode((string)$pay['checkout_draft_context'],true,32,JSON_THROW_ON_ERROR);
        if (!is_bool($originalContext['buyer_was_agent']??null))
            throw new DomainException('checkout_buyer_context_missing');
        $q['policy']['buyer_was_agent']=$originalContext['buyer_was_agent'];
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
        $kind=$add?($parts[1]==='DAY'?'ADD_TIME':'ADD_VOLUME'):'RENEW';
        $current=achbotPayUxCurrentStatesLocked($db);
        if ($current===null || achbotManagedServicePlan($db,$o,$kind,$current,true)===null)
            throw new DomainException('checkout_managed_service_unavailable');
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
            $context=achbotCheckoutReadDraftContext($pay);
            $q['days']=achbotWholeServiceUnits($context['days'],achbotServiceDaysMax());
            $q['volume']=achbotWholeServiceUnits($context['volume'],achbotServiceVolumeMax(),true);
        }
    }
    if ($q['days']===null || $q['volume']===null) throw new DomainException('checkout_units_invalid');
    return achbotServiceQuoteRequest($q);
}

/** Configured, audience-eligible monetary methods for a positive service
 * quote. Never probe merchants or claim future payment success. Final use
 * holds the owner/draft/settings locks. Return names only, never keys. */
function achbotCheckoutPaymentMethodsLocked(mysqli $db,int $actor,string $type,int $price): array {
    if ($price<=0) return [];
    $states=achbotPayUxCurrentStatesLocked($db)??[];
    $keys=null;
    $methods=[];
    foreach (achbotPayUxAudienceMethods() as $method=>$label) {
        $flag=achbotPayUxMethodFlag($method,$type);
        if ($flag===null || ($states[$flag]??'off')!=='on') continue;
        if (!achbotPayUxAudienceAllowed($db,$method,$actor,true,$type)) continue;
        if ($method!=='wallet') {
            $keys??=achbotPayUxCurrentDocumentLocked($db,'PAYMENT_KEYS')??[];
            $def=achbotCreditMethodRegistry()[$method]??[];
            if (isset($def['manual_quote'])) {
                if (!achbotManualEnabled($method,$states,$keys)) continue;
            } elseif (isset($def['hosted'])) {
                $credential=$keys[$def['hosted']['credential_setting']]??null;
                if (!is_string($credential) || trim($credential)==='') continue;
                if ($method==='nowpayments') {
                    $rate=achbotUsdtRate($keys);
                    if ($rate===null || round($price/$rate,2)<0.01 ||
                        trim((string)($GLOBALS['nowpaymentsIpnSecret']??''))==='') continue;
                }
            } else continue;
        }
        $methods[]=$method;
    }
    return $methods;
}

/** Read original unclaimed checkout, validate its actual local admission,
 * and capture the exact display context. Caller holds owner and pay locks.
 * No external I/O, no new operation, no Wallet write. */
function achbotCheckoutDraftViewLocked(mysqli $db,array $pay,int $actor,array $states): array {
    $id=(int)$pay['id'];
    if ((int)$pay['user_id']!==$actor || $pay['checkout_operation_id']!==null ||
        !achbotPayUxCurrentDraft($pay) || !achbotPayUxUnclaimedDraftUsable($db,$pay,true) ||
        achbotProviderRow($db,'SELECT id FROM payment_attempts WHERE pay_row_id=? LIMIT 1 FOR UPDATE','i',[$id]) ||
        achbotProviderRow($db,'SELECT id FROM service_effects WHERE pay_row_id=? LIMIT 1 FOR UPDATE','i',[$id]))
        throw new DomainException('checkout_view_closed');
    $mode=null;
    foreach (['standard','custom','renew'] as $candidate)
        if (achbotCheckoutModeMatchesRequest($pay,$candidate)) {$mode=$candidate;break;}
    if ($mode===null) throw new DomainException('checkout_view_subject_invalid');
    $q=achbotCheckoutDraftRequestLocked($db,$pay,$states);
    $local=achbotServiceLocalTargetLocked($db,$actor,$q,true);
    $target=$local['identity'];$adapter=achbotProviderDefinition($target['provider']);
    if (!$adapter || !isset($adapter['service_checkout_pin']) ||
        !($adapter['service_checkout_pin'])($pay,$target,$q) ||
        ($q['kind']==='BUY' && (int)$local['capacity']<$q['quantity']))
        throw new DomainException('checkout_view_target_unavailable');
    $plan=achbotProviderRow($db,'SELECT * FROM server_plans WHERE id=? FOR UPDATE','i',[$q['plan_id']]);
    if (!$plan) throw new DomainException('checkout_view_plan_missing');
    $context=json_decode((string)$pay['checkout_draft_context'],true,32,JSON_THROW_ON_ERROR);
    achbotCheckoutZeroAuthorizedLocked($db,$pay,$context);
    $category=null;
    if ($mode!=='renew') {
        if (($context['category_id']??null)!==(int)$plan['catid'])
            throw new DomainException('checkout_view_category_changed');
        $category=achbotProviderRow($db,'SELECT title FROM server_categories WHERE id=? AND active=1 FOR UPDATE','i',[(int)$plan['catid']]);
        if (!$category) throw new DomainException('checkout_view_category_unavailable');
    }
    return ['pay'=>$pay,'mode'=>$mode,'quote'=>$q,'plan'=>$plan,'category'=>$category,
        'context'=>$context];
}

/** Safe snapshot for a prompt or an initial/invalid-code card. The definitive
 * redemption repeats the same checks under its own transaction. */
function achbotCheckoutDraftView(mysqli $db,int $id,int $actor,array $states): ?array {
    $begun=false;
    try {
        if (!$db->begin_transaction()) throw new RuntimeException('checkout_view_begin');
        $begun=true;
        achbotPayUxLockDraftOwner($db,$actor);
        $pay=achbotProviderRow($db,'SELECT * FROM pays WHERE id=? AND user_id=? FOR UPDATE','ii',[$id,$actor]);
        if (!$pay) throw new DomainException('checkout_view_missing');
        $view=achbotCheckoutDraftViewLocked($db,$pay,$actor,$states);
        $view['payment_methods']=achbotCheckoutPaymentMethodsLocked($db,$actor,$pay['type'],(int)$pay['price']);
        $db->rollback();return $view;
    } catch (Throwable $e) {
        if ($begun) try {$db->rollback();} catch (Throwable $ignored) {}
        if (!($e instanceof DomainException))
            achbotDiagnosticEvent('achbot.finance.checkout_view_deferred',[
                'pay_id'=>$id,'actor_id'=>$actor]+achbotDiagnosticLocalFailure($e),'warning');
        return null;
    }
}

/** First method selection owns the command. Original receipt reopens do
 * not mint a different product or require current service availability. */
function achbotCheckoutAdmitDraftLocked(mysqli $db,array $pay,?array $attempt,
    string $method,array $states): ?int {
    $uid=(int)$pay['user_id'];$id=(int)$pay['id'];
    if (!achbotPayUxCurrentDraft($pay))
        throw new DomainException('checkout_draft_unavailable');
    if ($attempt!==null) {
        if ($method==='wallet' || ($attempt['provider']??null)!==$method ||
            !is_string($attempt['wallet_invoice_sha256']??null) ||
            (int)($attempt['service_operation_id']??0)!==(int)($pay['checkout_operation_id']??0))
            throw new DomainException('checkout_original_attempt_conflict');
        if ($pay['checkout_operation_id']!==null) achbotCheckoutOriginalCommandLocked($db,$pay);
        elseif ($pay['type']!=='INCREASE_WALLET') throw new DomainException('checkout_original_command_missing');
        return $pay['checkout_operation_id']===null?null:(int)$pay['checkout_operation_id'];
    }
    $context=json_decode((string)$pay['checkout_draft_context'],true,32,JSON_THROW_ON_ERROR);
    $freeCommercial=achbotCheckoutZeroAuthorizedLocked($db,$pay,$context);
    if (!achbotPayUxUnclaimedDraftUsable($db,$pay,true) ||
        (!$freeCommercial && !achbotPayUxAudienceAllowed($db,$method,$uid,true,$pay['type'])) ||
        ((int)$pay['price']<0 || ($method!=='wallet' && (int)$pay['price']===0)) || !preg_match('/\A[A-Za-z0-9_-]{1,50}\z/D',$pay['hash_id']))
        throw new DomainException('checkout_draft_unavailable');
    if (achbotProviderRow($db,'SELECT id FROM service_effects WHERE pay_row_id=? LIMIT 1 FOR UPDATE','i',[$id]))
        throw new DomainException('checkout_historical_effect_pending');
    if ($pay['type']==='INCREASE_WALLET') {
        if ($method==='wallet' || $pay['checkout_operation_id']!==null)
            throw new DomainException('checkout_topup_method_invalid');
        return null;
    }
    // New service admission must be able to journal and protect its original
    // result before taking money. Existing issued receipts remain creditable
    // independently of service readiness.
    require_once __DIR__.'/ServiceDelivery.php';
    require_once __DIR__.'/FollowupOutbox.php';
    require_once __DIR__.'/../Services/EditFinalization.php';
    if (!achbotServiceDeliverySchemaReady($db) || !achbotFollowupSchemaReady($db) ||
        !achbotServiceMutationSchemaReady($db) || !achbotAddonSchema($db))
        throw new RuntimeException('checkout_service_schema_unavailable');
    if ($pay['type']==='BUY_SUB') {
        require_once __DIR__.'/../Subscription/ServerDisplaySettings.php';
        achbotRequireServerDisplay($db,(int)$pay['server_id'],achbotProviderType($db,(int)$pay['server_id']));
    }
    $q=achbotCheckoutDraftRequestLocked($db,$pay,$states);
    if ($pay['checkout_operation_id']!==null) {
        [$op]=achbotCheckoutOriginalCommandLocked($db,$pay);
        if ($op['stage']!=='awaiting_funds') throw new DomainException('checkout_original_command_closed');
        return (int)$op['id'];
    }
    $local=achbotServiceLocalTargetLocked($db,$uid,$q,true);
    if ($method!=='wallet' && $q['kind']==='BUY' && (int)$local['capacity']<$q['quantity'])
        throw new DomainException('checkout_capacity_unavailable');
    $target=$local['identity'];
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

/** Exact persisted callback family; no external renewal route. */
function achbotCheckoutModeMatchesRequest(array $pay,string $mode): bool {
    $type=(string)($pay['type']??'');
    return match($mode) {
        'topup'=>$type==='INCREASE_WALLET',
        'renew'=>$type==='RENEW_ACCOUNT',
        'day'=>preg_match('/\AINCREASE_DAY_[1-9][0-9]*_[1-9][0-9]*\z/D',$type)===1,
        'volume'=>preg_match('/\AINCREASE_VOLUME_[1-9][0-9]*_[1-9][0-9]*\z/D',$type)===1,
        'standard'=>$type==='BUY_SUB' && (int)$pay['day']===0 && (int)$pay['volume']===0,
        'custom'=>$type==='BUY_SUB' && (int)$pay['day']>0 && (int)$pay['volume']>0,
        default=>false
    };
}

/** The actual customer wallet callback: exact owner/hash/family, no remote
 * calls, one debit and capacity reservation. Replaying the callback reads
 * the original funded command rather than executing old service handlers. */
function achbotCheckoutWallet(mysqli $db,string $hash,int $actor,string $mode,array $states): array {
    achbotPaymentEvidenceRequireAutocommit($db);
    achbotCheckoutRequireSchema($db);
    if (!$db->begin_transaction()) throw new RuntimeException('checkout_wallet_begin');
    try {
        $owner=achbotWalletLockOwnerLocked($db,$actor);
        $s=achbotProviderStatement($db,'SELECT * FROM pays WHERE hash_id=? AND user_id=? LIMIT 2 FOR UPDATE','si',[$hash,$actor]);
        $rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
        $pay=count($rows)===1?$rows[0]:null;
        if (!$pay || !achbotCheckoutModeMatchesRequest($pay,$mode) || !in_array($mode,['standard','custom','renew','day','volume'],true) ||
            ($mode==='standard' && $pay['type']!=='BUY_SUB'))
            throw new DomainException('checkout_draft_unavailable');
        $id=(int)$pay['id'];
        $attempt=achbotProviderRow($db,'SELECT * FROM payment_attempts WHERE pay_row_id=? FOR UPDATE','i',[$id]);
        if ($attempt) throw new DomainException('checkout_original_attempt_conflict');
        if ($pay['checkout_operation_id']!==null && $pay['state']==='paid_with_wallet') {
            [$op]=achbotCheckoutOriginalCommandLocked($db,$pay);
            if (($op['resolution_kind']??null)!==null) {
                $db->rollback();
                return ['status'=>'request_invalidated','wallet'=>$owner['wallet'],'price'=>(int)$pay['price']];
            }
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
