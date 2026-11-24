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
/** Local-only finalization has no external effect. The original receipt
 * and exact customer presentation are read under the completion lock. */
function achbotServiceEditFinalizePerform(mysqli $db,array $op,array $quote,array $row,array $presentation): array {
    return ['success'=>true];
}

/** Preserve the existing customer copy, parse mode and navigation for the
 * receipt that actually funded this edit. No unrelated late receipt can
 * change the original Wallet/automatic-renewal presentation. */
function achbotServiceEditCustomerPacketLocked(mysqli $db,array $op,array $quote): array {
    require_once __DIR__.'/../Finance/ServiceReports.php';
    $q=$quote['request'];$kind=$op['kind'];$keys=null;$mode='HTML';
    if ($op['command_kind']==='automatic_renewal') {
        $text=achbotMessageHtml('renewal.automatic_renewal.success_customer',
            achbotServiceAutoRenewNoticeValues($db,$op,$quote));
    } else {
        $receipt=achbotServiceFundingReceiptLocked($db,$op);
        if ($receipt!==null && achbotReceiptReportGateway($receipt['attempt'])!==null) {
            $text=match($kind) {
                'RENEW'=>achbotMessage('gateway.gateway_return.perform_payment_effect.message.a3b46e7d37',['remark'=>$q['remark']]),
                'ADD_TIME'=>achbotMessage('gateway.gateway_return.perform_payment_effect.message.8ab1d455cf',['plusDays'=>$q['days']]),
                'ADD_VOLUME'=>achbotMessage('gateway.gateway_return.perform_payment_effect.message.20ac3e8049',['plusVolume'=>$q['volume']]),
            };
            $keys=inlineKeyboard([[['text'=>achbotMessage('gateway.gateway_return.perform_payment_effect.text.275b4903d9'),
                'callback_data'=>'mainMenu']]]);
            $mode='plain';
        } elseif ($receipt!==null) {
            if ($kind==='RENEW' && $receipt['attempt']['wallet_credit_basis']==='bank_timer') {
                $text=achbotMessage('manual_payment.automatic_receipts.message.cca1075329',['remark'=>$q['remark']]);
                $mode='plain';
            } else {
                $text=match($kind) {
                    'RENEW'=>achbotMessageHtml('renewal.renewal_checkout.message.47b1149fb0',['remark'=>$q['remark']]),
                    'ADD_TIME'=>achbotMessageHtml('renewal.extensions.message.24dea9b122',['volume'=>$q['days'],'remark'=>$q['remark']]),
                    'ADD_VOLUME'=>achbotMessageHtml('renewal.extensions.message.d5b6a4e6e7',['volume'=>$q['volume'],'remark'=>$q['remark']]),
                };
            }
        } else $text=match($kind) {
            'RENEW'=>achbotMessageHtml('renewal.renewal_checkout.message.cca1075329',['remark'=>$q['remark']]),
            'ADD_TIME'=>achbotMessage('renewal.extensions.message.cce260f97e',['volume'=>$q['days']]),
            'ADD_VOLUME'=>achbotMessage('renewal.extensions.message.2d27ba4eea',['volume'=>$q['volume']]),
        };
    }
    return ['kind'=>'text','text'=>$text,'mode'=>$mode,'uid'=>(int)$op['userid'],'keys'=>$keys];
}
/**
 * Shared local completion of a verified original service edit. The provider
 * adapter supplies its authenticated result and owns the physical fence;
 * neither accounting nor customer copy is provider-specific.
 */
function achbotServiceEditCommitLocalLocked(mysqli $db,array $op,array $quote,array $row,array $o,
    ?int $verifiedExpiry): void {
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
    achbotServiceEditDeliveryInsertLocked($db,$op,$quote,$completed,
        achbotServiceEditCustomerPacketLocked($db,$op,$quote));
    if ($op['command_kind']==='automatic_renewal') {
        require_once __DIR__.'/../Finance/ServiceReports.php';
        achbotServiceAutoRenewStageReportsLocked($db,$op,$quote,(int)($GLOBALS['admin']??0),
            (string)($GLOBALS['botState']['rewardChannel']??''));
    }
}
