<?php
/** Scheduled admission into the shared service engine. */
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {http_response_code(404);exit;}
require_once __DIR__.'/CheckoutAdmission.php';
/**
 * The keyset cursor advances for every examined row, including unavailable
 * accounts, and stays complete when renewed rows leave the candidate set.
 */
function achbotAutoRenewSweep(mysqli $db, array $botState): void {
    if (($botState['renewAutoState']??'off')!=='on') return;
    $stmt=$db->prepare("SELECT value FROM setting WHERE type='AutoRenewLastOrderId' LIMIT 1");
    $stmt->execute();
    $row=$stmt->get_result()->fetch_assoc();
    $stmt->close();
    $cursor=max(0,(int)($row['value']??0));
    $stmt=$db->prepare("SELECT id,userid,server_id,remark
        FROM orders_list WHERE status=1 AND notif<>0 AND autoRenew='on' AND id>?
        ORDER BY id ASC LIMIT 50");
    $stmt->bind_param('i',$cursor);
    $stmt->execute();
    $orders=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    require_once __DIR__.'/CheckoutAdmission.php';
    achbotCheckoutRequireSchema($db);
    require_once __DIR__.'/../Services/AutomaticRenewal.php';
    $deadline=hrtime(true)/1000000000+10.0;$examined=0;
    foreach ($orders as $order) {
        if (hrtime(true)/1000000000>=$deadline) break;
        $orderId=(int)$order['id'];$examined++;
        achbotServiceAutoRenewAdmit($db,$orderId,(int)$order['userid'],$botState);
    }
    // A fulfilled row leaves this set. A keyset cursor cannot skip the next
    // eligible service when a successful renewal resets notif to zero.
    $next=$examined===count($orders) && count($orders)<50 ? 0 :
        ($examined>0?(int)$orders[$examined-1]['id']:$cursor);
    $stringNext=(string)$next;
    if ($row) {
        $stmt=$db->prepare("UPDATE setting SET value=?
            WHERE type='AutoRenewLastOrderId'");
        $stmt->bind_param('s',$stringNext);
    } else {
        $stmt=$db->prepare("INSERT INTO setting (type,value)
            VALUES ('AutoRenewLastOrderId',?)");
        $stmt->bind_param('s',$stringNext);
    }
    $stmt->execute();
    $stmt->close();
}

try {
    achbotAutoRenewSweep($connection,$botState??[]);
} catch (Throwable $e) {
    achbotDiagnosticEvent('achbot.service.auto_renewal',['reason'=>'sweep_deferred','class'=>get_class($e)],'error');
}
