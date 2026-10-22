<?php
/**
 * AChBot #26: bank-Cron paid BUY_SUB.  The existing durable service_effects
 * journal owns remote CREATE identity.  This module never retries dispatch
 * after uncertainty, credits a wallet, or sends before final local COMMIT.
 */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

/**
 * All locally recorded account lines, capacity and payment finality must
 * commit atomically.  HTTP and Telegram are forbidden inside this transaction.
 */
function achbotAutoBankFinishBuySub(mysqli $db, array $pay, array $lines): bool {
    $id=(int)($pay['id']??0); $owner=(int)($pay['user_id']??0);
    if ($id<=0 || $owner<=0 || $lines===[]) return false;
    $started=false;
    try {
        if ($db->begin_transaction()!==true) throw new RuntimeException('bank_buy_begin');
        $started=true;
        $stmt=$db->prepare('SELECT * FROM pays WHERE id=? FOR UPDATE');
        if (!$stmt) throw new RuntimeException('bank_buy_pay_prepare');
        $stmt->bind_param('i',$id);
        if (!$stmt->execute()) throw new RuntimeException('bank_buy_pay_read');
        $locked=$stmt->get_result()->fetch_assoc(); $stmt->close();
        $count=$locked?max(1,(int)$locked['agent_count']):0;
        $price=(int)($locked['price']??0);
        $server=(int)($locked['server_id']??0);
        $planId=(int)($locked['plan_id']??0);
        if (!$locked || (int)$locked['user_id']!==$owner ||
            $locked['state']!=='processing' || $locked['type']!=='BUY_SUB' ||
            $server<=0 || $planId<=0 || $price<=0 || $count>$price ||
            count($lines)!==$count)
            throw new RuntimeException('bank_buy_payment_changed');

        $stmt=$db->prepare('SELECT provider FROM payment_attempts WHERE pay_row_id=? LIMIT 1 FOR UPDATE');
        if (!$stmt) throw new RuntimeException('bank_buy_method_prepare');
        $stmt->bind_param('i',$id);
        if (!$stmt->execute()) throw new RuntimeException('bank_buy_method_read');
        $attempt=$stmt->get_result()->fetch_assoc(); $stmt->close();
        if ($attempt && $attempt['provider']!=='bank_transfer')
            throw new RuntimeException('bank_buy_method_changed');

        // Mutable plan identity still requires a fresh physical-target guard.
        // The *entitlement* itself is pinned by the paid, verified per-line
        // CREATE journal, not by a template administrators may edit after GET.
        $stmt=$db->prepare('SELECT p.server_id,p.inbound_id,c.type AS panel_type
            FROM server_plans p JOIN server_config c ON c.id=p.server_id
            WHERE p.id=? LIMIT 1 FOR UPDATE');
        if (!$stmt) throw new RuntimeException('bank_buy_plan_prepare');
        $stmt->bind_param('i',$planId);
        if (!$stmt->execute()) throw new RuntimeException('bank_buy_plan_read');
        $plan=$stmt->get_result()->fetch_assoc(); $stmt->close();
        $volume=(int)$locked['volume']; $days=(int)$locked['day'];
        if ($volume===0 && $days===0) {
            // This is the original standard quote used in this invocation to
            // dispatch CREATE. The per-payment 007 journal below proves its
            // exact requested bytes and remote-verified expiry. A late edit of
            // server_plans.days/volume is NOT a reason to strand a completed
            // remote effect or fabricate a different entitlement.
            $volume=(int)($lines[0]['volume']??0);
            $days=(int)($lines[0]['days']??0);
        }
        if (!$plan || (int)$plan['server_id']!==$server ||
            (int)$plan['inbound_id']!==0 || $plan['panel_type']!=='marzban' ||
            $volume<=0 || $days<=0 || $days>36500 ||
            $volume>intdiv(PHP_INT_MAX,1073741824))
            throw new RuntimeException('bank_buy_plan_changed');

        $stmt=$db->prepare("SELECT id,ordinal,state,order_id,plan_id,target_server_id,
            remote_username,requested_expire,requested_bytes,reserved_count
            FROM service_effects WHERE pay_row_id=? AND effect_kind='create'
            ORDER BY ordinal FOR UPDATE");
        if (!$stmt) throw new RuntimeException('bank_buy_effect_prepare');
        $stmt->bind_param('i',$id);
        if (!$stmt->execute()) throw new RuntimeException('bank_buy_effect_read');
        $result=$stmt->get_result(); $effects=[];
        while ($e=$result->fetch_assoc()) $effects[(int)$e['ordinal']]=$e;
        $stmt->close();
        if (count($effects)!==$count)
            throw new RuntimeException('bank_buy_effects_incomplete');
        if (!isset($effects[1]) || (int)$effects[1]['reserved_count']!==$count)
            throw new RuntimeException('bank_buy_capacity_unreserved');
        foreach ($lines as $index=>$line) {
            $n=$index+1; $e=$effects[$n]??null;
            if (!$e || (int)$e['id']!==(int)($line['effect_id']??0) ||
                $e['state']!=='verified' || $e['order_id']!==null ||
                (int)$e['plan_id']!==$planId || (int)$e['target_server_id']!==$server ||
                $e['remote_username']!==(string)($line['remark']??'') ||
                (int)$e['requested_bytes']!==$volume*1073741824 ||
                (int)$e['requested_expire']<=time() ||
                (int)($line['volume']??0)!==$volume ||
                (int)($line['days']??0)!==$days)
                throw new RuntimeException('bank_buy_effect_changed');
        }

        $each=intdiv($price,$count); $extra=$price%$count;
        foreach ($lines as $index=>$line) {
            $n=$index+1; $e=$effects[$n];
            $orderId=insertOrder($db,$owner,(string)$line['token'],$planId,
                $server,0,(string)$line['remark'],(string)$line['uniqid'],
                (string)$line['protocol'],(int)$e['requested_expire'],
                (string)$line['vray_link'],$each+($n<=$extra?1:0),
                (int)$line['date'],(int)$line['rahgozar'],
                (int)$line['agent_bought'],true);
            achbotLinkPaidAccountEffect($db,(int)$e['id'],(int)$orderId);
        }

        // The committed first service_effects line already reserved all
        // slots before HTTP; finalizing orders must not decrement twice.
        $stmt=$db->prepare("UPDATE pays SET state='auto_cart_approved'
            WHERE id=? AND user_id=? AND type='BUY_SUB' AND state='processing'");
        if (!$stmt) throw new RuntimeException('bank_buy_finish_prepare');
        $stmt->bind_param('ii',$id,$owner);
        if (!$stmt->execute() || $stmt->affected_rows!==1)
            throw new RuntimeException('bank_buy_finish_state');
        $stmt->close();
        if ($db->commit()!==true) throw new RuntimeException('bank_buy_commit_unconfirmed');
        $started=false;
        return true;
    } catch (Throwable $error) {
        if ($started) {
            try {$db->rollback();} catch (Throwable $ignored) {}
        }
        error_log('achbot_bank_buy_needs_review pay_id='.$id.
                  ' class='.get_class($error));
        return false;
    }
}

/**
 * Runs only after an exclusive, durable nonterminal bank claim.
 * Every remote line is individually reserved by achbotCreatePaidAccount().
 * Incomplete multi-account orders intentionally remain pending for review.
 */
function achbotAutoBankProvisionBuySub(
    mysqli $db, array $pay, array $botState, array $buttonValues,
    array $linkIndices, bool $hideConfigIfMany, string $botUrl
): array {
    achbotEffectRequireAutocommit($db);
    $payId=(int)($pay['id']??0); $owner=(int)($pay['user_id']??0);
    $planId=(int)($pay['plan_id']??0); $server=(int)($pay['server_id']??0);
    if ($payId<=0 || $owner<=0 || $planId<=0 || $server<=0)
        throw new RuntimeException('bank_buy_invalid_subject');

    $stmt=$db->prepare("SELECT p.type,p.state,p.user_id,p.plan_id,p.server_id,
        p.volume,p.day,p.price,p.agent_count,p.agent_bought,p.description,
        s.server_id AS plan_server,s.inbound_id,s.volume AS plan_volume,
        s.days AS plan_days,s.protocol,s.type AS net_type,s.rahgozar,
        s.limitip,s.custom_path,s.custom_port,s.custom_sni,
        i.ucount,i.remark AS server_remark,c.type AS panel_type,
        c.port_type,c.panel_url,c.sub_links
        FROM pays p JOIN server_plans s ON s.id=p.plan_id
        JOIN server_info i ON i.id=s.server_id
        JOIN server_config c ON c.id=s.server_id
        WHERE p.id=? LIMIT 1");
    if (!$stmt) throw new RuntimeException('bank_buy_preflight_prepare');
    $stmt->bind_param('i',$payId);
    if (!$stmt->execute()) throw new RuntimeException('bank_buy_preflight_read');
    $p=$stmt->get_result()->fetch_assoc(); $stmt->close();
    $count=$p?max(1,(int)$p['agent_count']):0;
    $price=(int)($p['price']??0);
    if (!$p || $p['type']!=='BUY_SUB' || $p['state']!=='processing' ||
        (int)$p['user_id']!==$owner || (int)$p['plan_id']!==$planId ||
        (int)$p['server_id']!==$server || (int)$p['plan_server']!==$server ||
        (int)$p['inbound_id']!==0 || $p['panel_type']!=='marzban' ||
        !achbotSupportsPanel($p['panel_type']) || $price<=0 || $count>$price ||
        (int)$p['ucount']<$count || !is_string($p['panel_url']) ||
        $p['panel_url']==='')
        throw new RuntimeException('bank_buy_preflight_unavailable');

    $volume=(int)$p['volume']; $days=(int)$p['day'];
    if ($volume===0 && $days===0) {
        $volume=(int)$p['plan_volume']; $days=(int)$p['plan_days'];
    }
    if ($volume<=0 || $days<=0)
        throw new RuntimeException('bank_buy_invalid_plan_units');

    $lines=[]; $deliveries=[];
    for ($i=1;$i<=$count;$i++) {
        $uniqid=generateRandomString(42,(string)$p['protocol']);
        // Marzban does not consume the legacy per-file port allocator.
        $port=random_int(1111,65000);
        $description=(string)($p['description']??'');
        $remark=buildRemark($botState,(string)$p['server_remark'],
            $description,$owner,$description!==''?$description:null);
        if ($count>1 && $description!=='') $remark.='_'.$i;
        [$response,$remark,$port,$effectId]=achbotCreatePaidAccount($db,$payId,$i,[
            'user_id'=>$owner,'server_type'=>'marzban','server_id'=>$server,
            'inbound_id'=>0,'plan_id'=>$planId,'remark'=>$remark,
            'volume'=>$volume,'days'=>$days,'uniqid'=>$uniqid,
            'protocol'=>(string)$p['protocol'],'port'=>$port,
            'expire_microdate'=>0,'net_type'=>(string)$p['net_type'],
            'rahgozar'=>(int)$p['rahgozar'],'limitip'=>(int)$p['limitip']
        ]);
        if (!is_object($response) || ($response->success??false)!==true)
            throw new RuntimeException('bank_buy_create_unconfirmed');

        [$token,$links,$uuid,$caption,$keys,$qr]=buildLinksAndSendQr(
            'marzban',$p,$botState,$botUrl,$server,$uniqid,
            (string)$p['protocol'],$remark,$port,(string)$p['net_type'],0,
            (int)$p['rahgozar'],(string)$p['custom_path'],
            (string)$p['custom_port'],(string)$p['custom_sni'],
            $response,$owner,$volume,$days,$buttonValues,$linkIndices,
            $hideConfigIfMany,'',null,false
        );
        if (!is_string($token) || $token==='' ||
            !is_string($qr) || $qr==='' || !is_string($links) || $links==='')
            throw new RuntimeException('bank_buy_links_missing');
        $lines[]=['effect_id'=>$effectId,'remark'=>$remark,'volume'=>$volume,
            'days'=>$days,'token'=>$token,'uniqid'=>$uuid?:$uniqid,
            'protocol'=>(string)$p['protocol'],'vray_link'=>$links,
            'date'=>time(),'rahgozar'=>(int)$p['rahgozar'],
            'agent_bought'=>(int)$p['agent_bought']];
        $deliveries[]=['qr'=>$qr,'caption'=>$caption,'keys'=>$keys,'uid'=>$owner];
    }

    $committed=achbotAutoBankFinishBuySub($db,$pay,$lines);
    return ['committed'=>$committed,'deliveries'=>$committed?$deliveries:[]];
}
