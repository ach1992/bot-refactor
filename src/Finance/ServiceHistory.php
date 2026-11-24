<?php
declare(strict_types=1);
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {http_response_code(404);exit;}
require_once __DIR__.'/../Panel/ProviderStorage.php';

/** Original invoice types and shared service history. SQL aliases p/o are
 * fixed internal names; no callback, setting or user value becomes SQL. */
function achbotServiceHistoryTypes(): array {
    return [
        'buy'=>["p.type='BUY_SUB'",'BUY'],
        'renew'=>["p.type='RENEW_ACCOUNT'",'RENEW'],
        'volume'=>["p.type LIKE 'INCREASE!_VOLUME!_%' ESCAPE '!'",'ADD_VOLUME'],
        'time'=>["p.type LIKE 'INCREASE!_DAY!_%' ESCAPE '!'",'ADD_TIME']
    ];
}
function achbotServiceHistoryCheckoutPredicate(?string $metric=null): string {
    $types=achbotServiceHistoryTypes();
    if ($metric!==null) {
        if (!isset($types[$metric])) throw new InvalidArgumentException('service_history_metric');
        $types=[$types[$metric]];
    }
    $cases=[];
    foreach ($types as [$type,$kind]) {
        $cases[]="(".$type." AND (p.checkout_operation_id IS NULL OR o.kind='".$kind."'))";
    }
    return "p.state IN ('paid','approved','auto_cart_approved','paid_with_wallet')
        AND (".implode(' OR ',$cases).")
        AND (p.checkout_operation_id IS NULL OR
            (o.userid=p.user_id AND o.command_kind='checkout' AND
             o.command_ref=CAST(p.id AS CHAR) AND o.stage='completed'))";
}
function achbotServiceHistoryAutomaticPredicate(): string {
    return "o.command_kind='automatic_renewal' AND o.kind='RENEW' AND o.stage='completed'";
}

/** Return a precise per-user count, never a guessed success count. */
function achbotCustomerHistoryCount(mysqli $db,int $userId,string $metric,
                                       int $days,string $direction,
                                       bool $final,int $now): ?int {
    if ($userId<=0 || ($metric!=='topup' && !isset(achbotServiceHistoryTypes()[$metric]))) return null;
    $topup=$metric==='topup';
    // Current checkout completion and the historical unbound fact are shared
    // with sales reports and discount eligibility. The separate top-up is
    // an incoming-money fact, not a completed service.
    $lock=$final?' FOR UPDATE':'';
    $timeSql='';
    if ($days>0) {
        $boundary=$now-$days*86400;
        $timeSql=' AND p.request_date>0 AND p.request_date<=? AND p.request_date'.
            ($direction==='recent'?'>=?':'<=?');
    }
    $sql="SELECT COUNT(*) AS n FROM pays p".
        ($final?" FORCE INDEX (idx_pays_user_v1)":"").
        ($topup?'':" LEFT JOIN service_operations o ON o.id=p.checkout_operation_id").
        " WHERE p.user_id=? AND ".($topup?
            "p.type='INCREASE_WALLET' AND p.state IN ('paid','approved','auto_cart_approved','paid_with_wallet')":
            achbotServiceHistoryCheckoutPredicate($metric)).$timeSql.
        $lock;
    $stmt=$db->prepare($sql);
    if (!$stmt) throw new RuntimeException('rule_history_prepare');
    if ($days>0) $stmt->bind_param('iii',$userId,$now,$boundary);
    else $stmt->bind_param('i',$userId);
    if (!$stmt->execute()) throw new RuntimeException('rule_history_read');
    $record=$stmt->get_result()->fetch_assoc();$stmt->close();
    $count=achbotCustomerHistoryInteger($record['n']??null);
    if ($count===null || $metric!=='renew') return $count;

    // Automatic renewal no longer creates a pays row. Count only completed
    // current renewal commands, never refusals/funded/cancelled attempts.
    $timeSql=$days>0?' AND UNIX_TIMESTAMP(created_at)>0 AND UNIX_TIMESTAMP(created_at)<=?
        AND UNIX_TIMESTAMP(created_at)'.($direction==='recent'?'>=?':'<=?'):'';
    $stmt=$db->prepare("SELECT COUNT(*) AS n FROM service_operations o".
        ($final?' FORCE INDEX (idx_service_owner)':'').
        " WHERE o.userid=? AND ".achbotServiceHistoryAutomaticPredicate().$timeSql.$lock);
    if (!$stmt) throw new RuntimeException('rule_renewal_history_prepare');
    if ($days>0) $stmt->bind_param('iii',$userId,$now,$boundary);
    else $stmt->bind_param('i',$userId);
    if (!$stmt->execute()) throw new RuntimeException('rule_renewal_history_read');
    $record=$stmt->get_result()->fetch_assoc();$stmt->close();
    $renewals=achbotCustomerHistoryInteger($record['n']??null);
    return $renewals===null || $count>PHP_INT_MAX-$renewals?null:$count+$renewals;
}
function achbotCustomerHistoryInteger($value): ?int {
    if (!is_int($value) && (!is_string($value) || !preg_match('/\A[0-9]+\z/D',$value)))
        return null;
    $parsed=filter_var((string)$value,FILTER_VALIDATE_INT,
        ['options'=>['min_range'=>0]]);
    return $parsed===false?null:$parsed;
}

/** One row per original completed commercial service. The amount is the
 * original debit less its original compensating refund, not receipt value.
 * Zero-priced completed commands have no artificial debit. Unbound
 * terminal historical sales remain evidence, not executable operations.
 * Wallet-origin means selected Wallet checkout or scheduled Wallet renewal,
 * not every receipt whose funds pass through the Wallet ledger. */
function achbotServiceSalesSql(): string {
    $checkout=achbotServiceHistoryCheckoutPredicate();
    $auto=achbotServiceHistoryAutomaticPredicate();
    $ledger=" LEFT JOIN wallet_entries d ON d.id=o.debit_entry_id
        AND d.user_row_id=o.user_row_id AND d.userid=o.userid
        AND d.source_kind='service_debit' AND d.source_ref=CAST(o.id AS CHAR) AND d.leg=0
        AND d.delta_toman=-CAST(o.price_toman AS SIGNED)
        LEFT JOIN wallet_entries r ON r.id=o.refund_entry_id
        AND r.user_row_id=o.user_row_id AND r.userid=o.userid
        AND r.source_kind='service_refund' AND r.source_ref=CAST(o.id AS CHAR) AND r.leg=0
        AND r.delta_toman>0 AND r.delta_toman<=o.price_toman ";
    $valid="((o.price_toman=0 AND o.debit_entry_id IS NULL) OR d.id IS NOT NULL)
        AND (o.refund_entry_id IS NULL OR r.id IS NOT NULL)";
    $net="CAST(o.price_toman AS SIGNED)-COALESCE(r.delta_toman,0)";
    return "SELECT p.user_id,p.server_id,p.plan_id,p.request_date,
            p.price AS amount,p.state='paid_with_wallet' AS wallet_origin
        FROM pays p WHERE p.checkout_operation_id IS NULL
            AND p.type<>'INCREASE_WALLET'
            AND p.state IN ('paid','approved','auto_cart_approved','paid_with_wallet')
        UNION ALL
        SELECT p.user_id,o.target_server_id,
            CAST(JSON_VALUE(o.quote_payload,'$.request.plan_id') AS UNSIGNED),
            p.request_date,".$net.",p.state='paid_with_wallet'
        FROM pays p JOIN service_operations o ON o.id=p.checkout_operation_id
        ".$ledger." WHERE ".$checkout." AND ".$valid."
        UNION ALL
        SELECT o.userid,o.target_server_id,
            CAST(JSON_VALUE(o.quote_payload,'$.request.plan_id') AS UNSIGNED),
            FLOOR(UNIX_TIMESTAMP(o.created_at)),".$net.",1
        FROM service_operations o ".$ledger." WHERE ".$auto." AND ".$valid;
}

/** Existing report date windows use request time. All-time includes retained
 * historical records with no usable timestamp. Incoming payment counts and
 * amounts are separate queries over receipt facts, never this projection. */
function achbotServiceSalesTotal(mysqli $db,?int $from=null,?int $to=null): int {
    $sql='SELECT COALESCE(SUM(s.amount),0) AS total FROM ('.achbotServiceSalesSql().') s WHERE 1=1';
    $types='';$values=[];
    if ($from!==null) {$sql.=' AND s.request_date>=?';$types.='i';$values[]=$from;}
    if ($to!==null) {$sql.=' AND s.request_date<=?';$types.='i';$values[]=$to;}
    return (int)achbotProviderRow($db,$sql,$types,$values)['total'];
}

/** One aggregate scan per dimension rather than two per configured server.
 * User classification is the same current is_agent setting as before. */
function achbotServiceSalesSummary(mysqli $db,array $windows,int $now): array {
    $sql=achbotServiceSalesSql();
    $select=['COALESCE(SUM(s.amount),0) AS total',
        'COALESCE(SUM(CASE WHEN s.wallet_origin THEN 1 ELSE 0 END),0) AS wallet_count',
        'COALESCE(SUM(CASE WHEN s.wallet_origin THEN s.amount ELSE 0 END),0) AS wallet_total'];
    $types='';$values=[];
    foreach ($windows as $key=>$start) {
        // This set is owned by the calling report, not user input.
        if (!preg_match('/\A[a-z]+\z/D',$key) || !is_int($start))
            throw new InvalidArgumentException('sales_window');
        $select[]="COALESCE(SUM(CASE WHEN s.request_date>=? THEN s.amount ELSE 0 END),0) AS total_".$key;
        $select[]="COALESCE(SUM(CASE WHEN u.is_agent=1 AND s.request_date BETWEEN ? AND ? THEN s.amount ELSE 0 END),0) AS agent_".$key;
        $types.='iii';array_push($values,$start,$start,$now);
    }
    $totals=achbotProviderRow($db,'SELECT '.implode(',',$select).' FROM ('.$sql.') s
        LEFT JOIN users u ON u.userid=s.user_id',$types,$values);
    $stmt=achbotProviderStatement($db,"SELECT s.server_id,SUM(s.amount) AS total,
        SUM(CASE WHEN s.request_date>=? THEN s.amount ELSE 0 END) AS month
        FROM (".$sql.") s GROUP BY s.server_id",'i',[$windows['month']]);
    $servers=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
    $plans=[];
    foreach ([null,$windows['month']] as $from) {
        $stmt=achbotProviderStatement($db,"SELECT s.plan_id,SUM(s.amount) AS total
            FROM (".$sql.") s".($from===null?'':' WHERE s.request_date>=?').
            " GROUP BY s.plan_id ORDER BY total DESC,s.plan_id ASC LIMIT 5",
            $from===null?'':'i',$from===null?[]:[$from]);
        $plans[]=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
    }
    // Preserve the original abandoned-purchase cohort: a distinct user with
    // an expired or cancelled BUY and no completed original purchase.
    // An incoming receipt or top-up alone does not remove this user.
    $unfinished=achbotProviderRow($db,"SELECT COUNT(DISTINCT p.user_id) AS total
        FROM pays p LEFT JOIN service_operations o ON o.id=p.checkout_operation_id
        WHERE p.type='BUY_SUB' AND (p.state IN ('expired_pending','expired','service_superseded','expired_have_sent')
            OR (o.userid=p.user_id AND o.command_kind='checkout' AND o.command_ref=CAST(p.id AS CHAR)
                AND o.kind='BUY' AND o.stage='cancelled'))
        AND p.user_id NOT IN (SELECT p.user_id FROM pays p
            LEFT JOIN service_operations o ON o.id=p.checkout_operation_id
            WHERE ".achbotServiceHistoryCheckoutPredicate('buy').")");
    return ['totals'=>$totals,'servers'=>$servers,'plans'=>$plans,
        'unfinished'=>(int)$unfinished['total']];
}
