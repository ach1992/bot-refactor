<?php
declare(strict_types=1);
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {http_response_code(404);exit;}

/** Before a new Native write, prove that all local result writes can commit
 * together. Missing/malformed migration is a local failure, not a provider
 * rejection or permission to dispatch without durable evidence. */
function achbotServiceMutationSchemaReady(mysqli $db): bool {
    $s=$db->query("SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('service_mutations','increase_order','orders_list','service_effects','service_operations')");
    if (!$s) return false;
    $rows=$s->fetch_all(MYSQLI_ASSOC);$s->free();
    if (count($rows)!==5) return false;
    foreach ($rows as $r) if (strcasecmp($r['ENGINE']??'','InnoDB')!==0) return false;
    return true;
}
function achbotServiceMutationRow(mysqli $db,int $id,bool $lock=false): ?array {
    return achbotProviderRow($db,'SELECT * FROM service_mutations WHERE service_operation_id=?'.
        ($lock?' FOR UPDATE':''),'i',[$id]);
}
/** Local-only finalization. No GET, PUT, reset, or payment provider. */
function achbotServiceEditFinalizePerform(mysqli $db,array $op,array $quote,array $row,array $presentation): array {
    require_once __DIR__.'/../../messages/loader.php';
    $q=$quote['request'];
    // The automatic-renewal detail uses only the original committed debit
    // and the real service reference, not a synthetic payment invoice.
    if ($op['command_kind']==='automatic_renewal') {
        $auto=$q['policy']['automatic_renewal']??null;
        if (!is_array($auto) || $op['kind']!=='RENEW' ||
            ($auto['cycle']??null)!==$op['command_ref'] ||
            !is_string($auto['reference']??null) || !preg_match('/^rn_[a-f0-9]{32}$/D',$auto['reference']) ||
            !is_int($auto['wallet_before']??null) || !is_int($auto['wallet_after']??null) ||
            $auto['wallet_before']-$q['price']!==$auto['wallet_after'])
            throw new RuntimeException('service_auto_notice_evidence');
        if ($q['price']>0) {
            $entry=achbotProviderRow($db,'SELECT user_row_id,userid,source_kind,source_ref,leg,delta_toman,balance_before,balance_after FROM wallet_entries WHERE id=?',
                'i',[(int)$op['debit_entry_id']]);
            if (!$entry || (int)$entry['user_row_id']!==(int)$op['user_row_id'] ||
                (int)$entry['userid']!==(int)$op['userid'] || $entry['source_kind']!=='service_debit' ||
                $entry['source_ref']!==(string)$op['id'] || (int)$entry['leg']!==0 ||
                (int)$entry['delta_toman']!==-$q['price'] ||
                (int)$entry['balance_before']!==$auto['wallet_before'] || (int)$entry['balance_after']!==$auto['wallet_after'])
                throw new RuntimeException('service_auto_notice_debit_conflict');
        }
        $text=achbotMessageHtml('renewal.automatic_renewal.success_customer',[
            'remark'=>$q['remark'],'amount'=>number_format($q['price']),
            'wallet_before'=>number_format($auto['wallet_before']),'wallet_after'=>number_format($auto['wallet_after']),
            'days'=>number_format($q['days']),
            'volume'=>$q['volume']===0?achbotMessage('renewal.automatic_renewal.unlimited_volume'):
                achbotMessage('renewal.automatic_renewal.finite_volume',['volume'=>number_format($q['volume'])]),
            'payment_ref'=>$auto['reference']]);
    } else $text=match($op['kind']) {
        'RENEW'=>achbotMessageHtml('renewal.renewal_checkout.message.cca1075329',['remark'=>$q['remark']]),
        'ADD_TIME'=>achbotMessage('renewal.extensions.message.cce260f97e',['volume'=>$q['days']]),
        'ADD_VOLUME'=>achbotMessage('renewal.extensions.message.2d27ba4eea',['volume'=>$q['volume']]),
    };
    return ['success'=>true,'packet'=>['kind'=>'text','text'=>$text,'mode'=>'HTML','uid'=>(int)$op['userid']]];
}
/**
 * Shared local completion of a verified original service edit. The provider
 * adapter supplies its authenticated result and owns the physical fence;
 * neither accounting nor customer copy is provider-specific.
 */
function achbotServiceEditCommitLocalLocked(mysqli $db,array $op,array $quote,array $row,array $o,
    ?int $verifiedExpiry,array $packet): void {
    require_once __DIR__.'/../Finance/ServiceDelivery.php';
    $id=(int)$op['id'];$q=$quote['request'];$oid=(int)$o['id'];
    if ((int)$row['service_operation_id']!==$id || $row['phase']!=='verified' ||
        $oid!==$q['order_id'] || (int)$o['userid']!==(int)$op['userid'] ||
        (int)$o['expire_date']!==(int)$row['order_expire_before'])
        throw new RuntimeException('service_edit_local_result_conflict');
    $expiry=$op['kind']==='ADD_VOLUME'?(int)$o['expire_date']:$verifiedExpiry;
    if (!is_int($expiry) || $expiry<0 || $expiry>2147483647)
        throw new RuntimeException('service_edit_local_expiry_invalid');
    // Preserve current local policy: only the original buyer's order is
    // updated. Other aliases observe the same actual Native entitlement.
    $s=achbotProviderStatement($db,'UPDATE orders_list SET expire_date=?,notif=0 WHERE id=? AND expire_date=?',
        'iii',[$expiry,$oid,(int)$row['order_expire_before']]);$s->close();
    $stored=achbotProviderRow($db,'SELECT * FROM orders_list WHERE id=? FOR UPDATE','i',[$oid]);
    if (!$stored || (int)$stored['expire_date']!==$expiry || (int)$stored['notif']!==0)
        throw new RuntimeException('service_edit_local_expiry_unconfirmed');
    $day=$op['kind']==='ADD_TIME'?$q['days']:null;
    $volume=$op['kind']==='ADD_VOLUME'?$q['volume']:null;
    $s=achbotProviderStatement($db,"INSERT INTO increase_order
        (userid,server_id,inbound_id,remark,amount,date,volume,day) VALUES (?,?,0,?,?,?,?,?)",
        'iisiiii',[(int)$op['userid'],$q['server_id'],$q['remark'],$q['price'],time(),$volume,$day]);
    $audit=(int)$s->insert_id;$n=$s->affected_rows;$s->close();
    if ($n!==1 || $audit<=0) throw new RuntimeException('service_edit_audit_write');
    $s=achbotProviderStatement($db,"UPDATE service_mutations SET phase='completed',audit_id=?
        WHERE service_operation_id=? AND phase='verified'",'ii',[$audit,$id]);
    $n=$s->affected_rows;$s->close();
    if ($n!==1) throw new RuntimeException('service_edit_complete_write');
    $completed=achbotServiceMutationRow($db,$id,true);
    achbotServiceEditDeliveryInsertLocked($db,$op,$quote,$completed,$packet);
}
