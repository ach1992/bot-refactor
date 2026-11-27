<?php
declare(strict_types=1);
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {http_response_code(404);exit;}
require_once __DIR__.'/../Panel/ProviderRuntime.php';
require_once __DIR__.'/PhysicalService.php';
require_once __DIR__.'/../Admin/Access.php';

/** Resolve a current authorized order, never a guessed username. */
function achbotSubscriptionLinkOrder(mysqli $db,int $actor,int $orderId): ?array {
    $s=achbotProviderStatement($db,'SELECT id,userid,step FROM users WHERE userid=? LIMIT 2','i',[$actor]);
    $users=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
    if (count($users)!==1 || $users[0]['step']==='banned') return null;
    $order=achbotProviderRow($db,'SELECT * FROM orders_list WHERE id=? AND status=1','i',[$orderId]);
    if (!$order || ((int)$order['userid']!==$actor &&
        !achbotAdminCanFor($db,$actor,(int)$GLOBALS['admin'],'orders'))) return null;
    return $order;
}
/**
 * Use the existing physical account lease for credential disclosure and
 * rotation. Native mutation and its verified journal are independent from
 * Telegram delivery. A repeated original verified update is read-only;
 * a different/uncertain operation cannot replay the original Native write.
 *
 * The presenter receives the exact freshly verified packet; it never
 * rotates again or silently fetches a different account.
 */
function achbotSubscriptionLinkAction(mysqli $db,int $actor,int $orderId,bool $rotate,callable $present): string {
    $held=null;
    try {
        $initial=achbotSubscriptionLinkOrder($db,$actor,$orderId);
        if (!$initial) return 'denied';
        $adapter=achbotProviderDefinition(achbotProviderType($db,(int)$initial['server_id']));
        if (!$adapter || !isset($adapter['service_account_lock'])) return 'denied';
        $lease=($adapter['service_account_lock'])($db,$orderId);
        achbotPhysicalAcquireLock($db,$lease);$held=$lease;
        $order=achbotSubscriptionLinkOrder($db,$actor,$orderId);
        if (!$order || ($adapter['service_account_lock'])($db,$orderId)!==$lease ||
            !achbotSupportsOrderObservation($db,$orderId,(int)$order['userid']))
            return 'denied';
        $server=(int)$order['server_id'];$remark=$order['remark'];
        $type=achbotProviderType($db,$server);
        if ($rotate) {
            if (!achbotSupportsOrder($db,$orderId,null,(int)$order['userid'])) return 'review';
            $remote=achbotProviderRevoke($server,$remark);
            if (($remote->success??false)!==true || ($remote->verified??false)!==true) return 'review';
        } else $remote=achbotProviderRenderPacket($db,$server,$remark);
        if (!achbotProviderRenderProofValid($remote,$order,$type) ||
            ($remote->provider_links_verified??false)!==true ||
            !is_string($remote->subscription_url??null) || $remote->subscription_url==='' ||
            !is_array($remote->links??null))
            return $rotate?'review':'unavailable';
        // Current Native view is authoritative. Preserve the original stored
        // purchase links and sealed delivery evidence; rotation's existing
        // mutation journal prevents sending a stale original credential packet.
        $current=achbotSubscriptionLinkOrder($db,$actor,$orderId);
        if (!$current || (int)$current['userid']!==(int)$order['userid'] ||
            (int)$current['server_id']!==$server || $current['remark']!==$remark ||
            ($adapter['service_account_lock'])($db,$orderId)!==$lease) return 'denied';
        return $present($current,$remote)===true?'shown':'view_unconfirmed';
    } catch (Throwable $e) {
        error_log('achbot_subscription_link_deferred order_id='.$orderId.' actor='.$actor.' class='.get_class($e));
        return $rotate?'review':'unavailable';
    } finally {
        if ($held!==null) achbotPhysicalReleaseLock($db,$held);
    }
}
