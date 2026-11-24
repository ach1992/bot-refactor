<?php
/** AChBot V7: immutable V6.1 purchase helper definitions. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../messages/loader.php'; // ACHBOT_TEXT_CATALOG

require_once __DIR__.'/../Panel/ProviderRuntime.php';
require_once __DIR__.'/../Plans/Addons.php';

/**
 * Compatibility hook called before new payment-draft INSERTs. It MUST remain
 * non-destructive: the successor INSERT/COMMIT can still fail and some callers
 * already hold a MariaDB transaction. Shared PaymentLifecycle expiry later
 * transitions only unclaimed old drafts and preserves the original pays ID.
 */
if (!function_exists('achbotRemoveUnclaimedPendingPayments')) {
    function achbotRemoveUnclaimedPendingPayments(mysqli $db, int $userId,
                                                   string $type): void {
        if ($userId<=0 || !in_array($type,[
            'BUY_SUB','RENEW_ACCOUNT',
            'INCREASE_DAY','INCREASE_VOLUME','INCREASE_WALLET'
        ],true)) throw new InvalidArgumentException('unsupported_payment_draft_cleanup');
        // Called BEFORE creating the replacement. Do not delete, expire or
        // touch the old draft yet: an insert or COMMIT can still fail, and a
        // wallet top-up caller already owns an InnoDB transaction here.
        // The one-minute lifecycle worker + guarded URL/callback checks expire
        // only a genuinely old unclaimed draft once a successor commits.
        // Existing signature remains for backward-compatible callsites.
        return;
    }
}

if (!function_exists('getPlanDetails')) {
    function getPlanDetails($connection, $planId)
    {
        $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
        $stmt->bind_param("i", $planId);
        $stmt->execute();
        $plan = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$plan) {
            alert(\achbotMessage('purchase.core.get_plan_details.alert.93f191e066'));
            exit;
        }

        $days   = (int)$plan['days'];
        $vol    = (int)$plan['volume'];
        $price  = isset($plan['price']) ? (int)$plan['price'] : 0;
        $date   = time();
        $expire_microdate = floor(microtime(true) * 1000) + (864000 * $days * 100);
        $expire_date      = $date + (86400 * $days);

        return [
            'plan'             => $plan,
            'days'             => $days,
            'volume'           => $vol,
            'price'            => $price,
            'date'             => $date,
            'expire_microdate' => $expire_microdate,
            'expire_date'      => $expire_date,
        ];
    }
}

if (!function_exists('ensureServerCapacity')) {
    function ensureServerCapacity($connection, $server_id, $inbound_id, $acount)
    {
        if ((int)$acount === 0 && (int)$inbound_id !== 0) {
            alert(\achbotMessage('legacy_messages.out_of_connection_capacity'));
            exit;
        }

        $server_info = null;

        if ((int)$inbound_id === 0) {
            $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
            $stmt->bind_param("i", $server_id);
            $stmt->execute();
            $server_info = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$server_info || (int)$server_info['ucount'] <= 0) {
                alert(\achbotMessage('legacy_messages.out_of_server_capacity'));
                exit;
            }
        }
        return $server_info;
    }
}

// Shared read-only preflight for current purchase/manual approval/wallet.
// Type + inbound are validated as one provider contract before settlement.
// Post-V9: read-only order and payment-subject validation at *entrance*.
// All consumers use the same current provider and 23-table schema; R4 adds
// only one separately migrated nullable server_info column.
// R4: physical panel is explicitly identified by an admin-managed group.
// Different API URLs and admin users can access the SAME backend.
// Only a nullable server_info.panel_group_id column is added by separate SQL
// migration; historical V9 DDL remains frozen.
if (!function_exists('achbotGroupNameValid')) {
    function achbotGroupNameValid($name) {
        $name = is_string($name) ? $name : '';
        // Reject ALL line breaks, including trailing ones before trimming.
        // 60 Unicode characters fit setting.value TEXT, including emoji.
        if (preg_match('/\R/u', $name) !== 0) return false;
        $name = trim($name);
        return $name !== '' && preg_match('/\A.{1,60}\z/u', $name) === 1;
    }
}
if (!function_exists('achbotListPanelGroups')) {
    function achbotListPanelGroups($connection) {
        $stmt = $connection->prepare(
            "SELECT id, value AS name FROM setting WHERE type = 'ACHBOT_MARZBAN_PANEL_GROUP' ORDER BY id"
        );
        $stmt->execute();
        $result = $stmt->get_result();
        $groups = [];
        while ($row = $result->fetch_assoc()) {
            $groups[] = ['id'=>(int)$row['id'], 'name'=>(string)$row['name']];
        }
        $stmt->close();
        return $groups;
    }
}
if (!function_exists('achbotCreatePanelGroupRecord')) {
    function achbotCreatePanelGroupRecord($connection, $name) {
        if (!achbotGroupNameValid($name)) return null;
        $name = trim((string)$name);
        // No extra schema/index is authorized. MariaDB's connection-scoped
        // advisory lock serializes ALL group-name checks and inserts.
        // This helper owns its transaction: release the lock only AFTER
        // commit/rollback so another creator cannot observe uncommitted data.
        $lockName = 'achbot_post_v9_marzban_group_create';
        $stmt = $connection->prepare('SELECT GET_LOCK(?, 10) AS acquired');
        $stmt->bind_param('s', $lockName);
        $stmt->execute();
        $acquired = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ((int)($acquired['acquired'] ?? 0) !== 1) {
            throw new \RuntimeException('panel_group_create_lock_unavailable');
        }
        try {
            $connection->begin_transaction();
            try {
                $stmt = $connection->prepare(
                    "SELECT id FROM setting WHERE type = 'ACHBOT_MARZBAN_PANEL_GROUP' AND value = ? LIMIT 1"
                );
                $stmt->bind_param('s', $name);
                $stmt->execute();
                $exists = $stmt->get_result()->num_rows > 0;
                $stmt->close();
                if ($exists) {
                    $connection->rollback();
                    return null;
                }
                $stmt = $connection->prepare(
                    "INSERT INTO setting (type,value) VALUES ('ACHBOT_MARZBAN_PANEL_GROUP', ?)"
                );
                $stmt->bind_param('s', $name);
                $stmt->execute();
                $id = (int)$stmt->insert_id;
                $stmt->close();
                if ($id <= 0) throw new \RuntimeException('panel_group_insert_failed');
                $connection->commit();
                return $id;
            } catch (\Throwable $e) {
                $connection->rollback();
                throw $e;
            }
        } finally {
            $stmt = $connection->prepare('SELECT RELEASE_LOCK(?)');
            $stmt->bind_param('s', $lockName);
            $stmt->execute();
            $stmt->close();
        }
    }
}
if (!function_exists('achbotSetPanelGroupRecord')) {
    function achbotSetPanelGroupRecord($connection, $serverId, $groupId) {
        // Caller must own SQL transaction and server_config row lock.
        $serverId = (int)$serverId;
        $groupId = ($groupId === null || (int)$groupId === 0) ? null : (int)$groupId;
        if ($serverId <= 0 || ($groupId !== null && $groupId <= 0)) {
            throw new \InvalidArgumentException('invalid_panel_group_assignment');
        }
        if ($groupId !== null) {
            $stmt = $connection->prepare(
                "SELECT id FROM setting WHERE id = ? AND type = 'ACHBOT_MARZBAN_PANEL_GROUP' FOR UPDATE"
            );
            $stmt->bind_param('i', $groupId);
            $stmt->execute();
            $exists = $stmt->get_result()->num_rows === 1;
            $stmt->close();
            if (!$exists) throw new \RuntimeException('unknown_panel_group');
        }
        // Panel Group is the administrator's declaration of real physical-panel
        // identity. Assigning it is configuration only: existing orders,
        // plans, invoices, provider effects and unfinished journals never
        // restrict this editor and are not mutated by it. The downstream
        // operations must independently recheck their own pinned identities.
        $groupStmt=$connection->prepare(
            'SELECT id FROM server_info WHERE id=? FOR UPDATE');
        $groupStmt->bind_param('i',$serverId);$groupStmt->execute();
        $groupRow=$groupStmt->get_result()->fetch_assoc();$groupStmt->close();
        if (!$groupRow) throw new \RuntimeException('group_target_unavailable');
        $stmt = $connection->prepare("UPDATE server_info SET panel_group_id = ? WHERE id = ?");
        $stmt->bind_param('ii', $groupId, $serverId);
        $stmt->execute();
        $stmt->close();
    }
}
if (!function_exists('achbotDeletePanelGroupRecord')) {
    function achbotDeletePanelGroupRecord($connection, $groupId) {
        $groupId = (int)$groupId;
        if ($groupId <= 0) return false;
        $stmt = $connection->prepare(
            "DELETE FROM setting WHERE id = ?
               AND type = 'ACHBOT_MARZBAN_PANEL_GROUP'
               AND NOT EXISTS (SELECT 1 FROM server_info WHERE panel_group_id = ?)"
        );
        $stmt->bind_param('ii', $groupId, $groupId);
        $stmt->execute();
        $success = $stmt->affected_rows === 1;
        $stmt->close();
        return $success;
    }
}
// Panel grouping is a permission to remap the bot's logical server label,
// not an authorization to modify or migrate the actual Marzban account.
// Physical panel membership is administrator-declared, provider-neutral.
// Keep the original setting.type and nullable column to preserve all installed
// group IDs and pre-existing Marzban API compatibility without a data migration.
if (!function_exists('achbotPanelGroups')) {
    function achbotPanelGroups($db): array { return achbotListPanelGroups($db); }
    function achbotPanelGroupValid($name): bool { return achbotGroupNameValid($name); }
    function achbotCreatePanelGroup($db,$name): ?int { return achbotCreatePanelGroupRecord($db,$name); }
    function achbotDeletePanelGroup($db,int $id): bool { return achbotDeletePanelGroupRecord($db,$id); }
    function achbotPanelGroup($db,int $serverId): ?array {
        if ($serverId<=0) return null;
        $s=$db->prepare("SELECT g.id,g.value AS name FROM server_info i
            JOIN server_config c ON c.id=i.id
            JOIN setting g ON g.id=i.panel_group_id AND g.type='ACHBOT_MARZBAN_PANEL_GROUP'
            WHERE i.id=? AND c.type='pasarguard' LIMIT 1");
        $s->bind_param('i',$serverId);$s->execute();
        $row=$s->get_result()->fetch_assoc();$s->close();
        return $row ? ['id'=>(int)$row['id'],'name'=>(string)$row['name']] : null;
    }
    function achbotSetPanelGroup($db,int $serverId,int $groupId): void {
        $s=$db->prepare("SELECT c.type FROM server_config c
            JOIN server_info i ON i.id=c.id WHERE c.id=? FOR UPDATE");
        $s->bind_param('i',$serverId);$s->execute();$row=$s->get_result()->fetch_assoc();$s->close();
        if (!$row || $row['type']!=='pasarguard')
            throw new \RuntimeException('panel_group_server_unavailable');
        achbotSetPanelGroupRecord($db,$serverId,$groupId);
    }
    function achbotSamePanelGroup($db,int $sourceId,int $targetId): bool {
        if ($sourceId<1 || $targetId<1 || $sourceId===$targetId) return false;
        $a=achbotPanelGroup($db,$sourceId);
        $b=achbotPanelGroup($db,$targetId);
        return $a!==null && $b!==null && $a['id']===$b['id'];
    }
}

/** Fresh native baseline shared by add-on selection and pre-payment checks. */
if (!function_exists('achbotOrderAddonEligibility')) {
function achbotOrderAddonEligibility($connection,array $order,string $kind,?array $nativeContext=null): array {
    $out=['eligible'=>false,'reason'=>'unknown'];
    if (!($connection instanceof mysqli) || !in_array($kind,['day','volume'],true) ||
        (int)($order['id']??0)<=0 || (int)($order['server_id']??0)<=0 ||
        !is_string($order['remark']??null) || $order['remark']==='') return $out;
    require_once __DIR__.'/../Panel/ServiceLifecycle.php';
    if (!achbotServerCommerceAvailable($connection,(int)$order['server_id'],false))
        return ['eligible'=>false,'reason'=>'server_renewal_disabled'];
    $native=$nativeContext===null?achbotServiceObserve((int)$order['server_id'],$order['remark']):($nativeContext['observation']??[]);
    if (!$native['verified'] || !in_array($native['status'],['active','disabled','expired','limited'],true)) return $out;
    if ($native['expire']===null) return ['eligible'=>false,'reason'=>'unlimited_time'];
    // Existing day extensions may revive an expired finite account. Volume
    // extensions preserve its date and therefore require a still-valid date.
    if ($kind==='volume' && $native['expire']<=time()) return ['eligible'=>false,'reason'=>'expired'];
    if ($kind==='volume' && $native['quota']===null) return ['eligible'=>false,'reason'=>'unlimited_volume'];
    return ['eligible'=>true,'reason'=>''];
}
}

/**
 * A new quote cannot claim a shared physical account while a Native plan
 * change has a durable, not-yet-committed journal, including when another
 * owner is the selected source. Called after order validation/locking.
 *
 * The 017 table is optional only when truly absent. An invalid installed
 * journal or unreadable old_rows is NOT proof of eligibility.
 */
if (!function_exists('achbotServicePaymentJournalClear')) {
    function achbotServicePaymentJournalClear(mysqli $db,int $orderId,bool $lock=false): bool {
        if ($orderId<=0) return false;
        try {
            $q=$db->query("SELECT TABLE_TYPE,ENGINE FROM information_schema.TABLES
                WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='service_plan_changes'");
            if (!($q instanceof mysqli_result)) return false;
            $row=$q->fetch_assoc();$count=$q->num_rows;$q->free();
            if ($row===null) return $count===0;
            if ($count!==1 || $row['TABLE_TYPE']!=='BASE TABLE' ||
                strcasecmp((string)$row['ENGINE'],'InnoDB')!==0) return false;
            $q=$db->query("SELECT source_order_id,intent_json
                FROM service_plan_changes WHERE state<>'committed' ORDER BY id".
                ($lock?' FOR UPDATE':''));
            if (!($q instanceof mysqli_result)) return false;
            while ($pending=$q->fetch_assoc()) {
                if ((int)$pending['source_order_id']===$orderId) { $q->free();return false; }
                $intent=json_decode((string)$pending['intent_json'],true);
                if (!is_array($intent) || !isset($intent['old_rows']) ||
                    !is_array($intent['old_rows']) || count($intent['old_rows'])>10000) {
                    $q->free();return false;
                }
                foreach ($intent['old_rows'] as $peer) {
                    if (!is_array($peer) || (int)($peer['id']??0)<=0) {
                        $q->free();return false;
                    }
                    if ((int)$peer['id']===$orderId) { $q->free();return false; }
                }
            }
            $q->free();return true;
        } catch (Throwable $error) { return false; }
    }
}

/** New service-bound payment claims need a still-active local order.
 * Check only never-issued drafts; an already issued gateway/receipt keeps
 * its independent late-funds reconciliation even if the order is retired.
 * A locking read serializes the new claim with scheduled deletion's order
 * status fence, including when the earlier preview used a stale snapshot.
 */
if (!function_exists('achbotUnissuedServiceOrderActive')) {
    function achbotUnissuedServiceOrderActive(mysqli $db,array $pay,int $orderId,bool $lock): bool {
        if ($orderId<=0) return false;
        if (!in_array((string)($pay['state']??''),['pending','send','low_wallet'],true) ||
            (string)($pay['payid']??'')!=='') return true;
        $stmt=$db->prepare('SELECT status FROM orders_list WHERE id=?'.($lock?' FOR UPDATE':''));
        if (!$stmt) return false;
        try {
            $stmt->bind_param('i',$orderId);
            if (!$stmt->execute()) return false;
            $row=$stmt->get_result()->fetch_assoc();
            if ($row!==null && (int)$row['status']===1) return true;
        } catch (Throwable $error) {return false;}
        finally {$stmt->close();}
        // Manual receipts can own an issued 003 claim while pays.payid is
        // empty. Preserve that existing late-funds path after local removal;
        // only a draft with no attempt can be barred from NEW issuance.
        $payId=(int)($pay['id']??0);
        if ($payId<=0) return false;
        try {
            $q=$db->prepare('SELECT id FROM payment_attempts WHERE pay_row_id=? LIMIT 1'.
                ($lock?' FOR UPDATE':''));
            if (!$q) return false;
            $q->bind_param('i',$payId);
            if (!$q->execute()) return false;
            return $q->get_result()->fetch_assoc()!==null;
        } catch (Throwable $error) {return false;}
        finally {if (isset($q) && $q instanceof mysqli_stmt) $q->close();}
    }
}

if (!function_exists('achbotSupportsPaymentSubject')) {
    function achbotSupportsPaymentSubject($connection, $pay, bool $lock = false)
    {
        if (!is_array($pay)) return false;
        $type = (string)($pay['type'] ?? '');
        // Supersession terminates only the ORIGINAL service-bound invoice.
        // Even a correct old plan/server no longer makes it payable.
        if (($pay['state']??null)==='service_superseded') return false;
        // Wallet top-ups are deliberately not tied to VPN providers.
        if ($type === 'INCREASE_WALLET') return true;
        if (!achbotProviderQuoteEligible($connection,$pay,$lock)) return false;
        $pid = (int)($pay['plan_id'] ?? 0);
        $uid = (int)($pay['user_id'] ?? 0);
        if ($type === 'BUY_SUB') {
            return $pid > 0 && achbotSupportsPlan(
                $connection, $pid, 0, $pay['server_id'] ?? null, $lock
            );
        }
        if ($type === 'RENEW_ACCOUNT') {
            $oid=(int)($pay['order_id']??0);
            return $pid > 0 && achbotUnissuedServiceOrderActive($connection,$pay,$oid,$lock) &&
                achbotSupportsOrder($connection, $oid, $pid, $uid > 0 ? $uid : null, $lock) &&
                achbotServicePaymentJournalClear($connection,$oid,$lock);
        }
        if (preg_match('/^INCREASE_(DAY|VOLUME)_(\d+)_(\d+)$/', $type, $match)) {
            $oid = (int)$match[2];
            $addon = (int)$match[3];
            if ($addon <= 0 ||
                !achbotUnissuedServiceOrderActive($connection,$pay,$oid,$lock) ||
                !achbotSupportsOrder($connection, $oid, $pid > 0 ? $pid : null,
                    $uid > 0 ? $uid : null, $lock)) return false;
            if (!empty($pay['order_id']) && (int)$pay['order_id'] !== $oid) return false;
            if (!achbotServicePaymentJournalClear($connection,$oid,$lock)) return false;
            if (achbotPinnedAddonUnits($pay)===null) return false;
            $stmt=$connection->prepare('SELECT id,server_id,remark,userid FROM orders_list WHERE id=?'.($lock?' FOR UPDATE':''));
            $stmt->bind_param('i',$oid);$stmt->execute();$order=$stmt->get_result()->fetch_assoc();$stmt->close();
            if (!$order) return false;
            try {
                if (!achbotAddonSchema($connection))
                    return achbotOrderAddonEligibility($connection,$order,strtolower($match[1]))['eligible'];
                $context=achbotAddonContext($connection,$order);
                return achbotOrderAddonEligibility($connection,$order,strtolower($match[1]),$context)['eligible'] &&
                    achbotAddonPayEligible($connection,$pay,$order,$context);
            } catch (Throwable $error) {return false;}
        }
        return false;
    }
}

/**
 * Provider-independent paid service modification boundary.
 *
 * Payments (wallet, automatic bank receipt, hosted gateways and operator
 * evidence) must call THIS function rather than Marzban's transport directly.
 * Each provider adapter must prove its own effect and return a normalized
 * verified absolute result; a plain HTTP acknowledgement is NOT success.
 *
 * Adding a panel (for example Pasarguard) extends only the adapter dispatch
 * and its verified account semantics. It does not grant that panel support
 * until its readback/effect tests pass.
 */
if (!function_exists('achbotApplyServiceEdit')) {
    function achbotApplyServiceEdit($db, $serverId, array $change,
                                   ?array $giftTarget=null): object {
        $serverId=(int)$serverId;
        if (!($db instanceof mysqli) || $serverId<=0 ||
            !isset($change['remark']) ||
            !is_string($change['remark']) || $change['remark']==='')
            return (object)['success'=>false,'uncertain'=>false,
                'msg'=>'service_edit_invalid_request'];

        // Paid edits have exactly one entry point: the funded service engine.
        if ($giftTarget===null || array_diff(array_keys($change),['remark','order_id','plus_day','plus_volume','operation_key']))
            return (object)['success'=>false,'uncertain'=>false,'msg'=>'service_edit_invalid_request'];
        $stmt=$db->prepare('SELECT type FROM server_config WHERE id=?');
        if (!$stmt) return (object)['success'=>false,'uncertain'=>true,
                                   'msg'=>'service_adapter_lookup_unavailable'];
        $stmt->bind_param('i',$serverId);
        if (!$stmt->execute()) {
            $stmt->close();
            return (object)['success'=>false,'uncertain'=>true,
                            'msg'=>'service_adapter_lookup_unavailable'];
        }
        $panel=$stmt->get_result()->fetch_assoc();
        $stmt->close();
        $type=(string)($panel['type']??'');
        if ($giftTarget!==null) {
            require_once __DIR__ . '/../Subscription/GiftBatch.php';
            if (!achbotSupportsAccount($type,0) ||
                !achbotGiftTargetMatches($db,$serverId,$giftTarget))
                return (object)['success'=>false,'uncertain'=>true,
                                'msg'=>'gift_service_target_changed'];
        }
        // The transport-specific code only exists inside its own adapter.
        // No speculative fallback or implicit behavior for future panels.
        $outcome=achbotProviderServiceEdit($db,$type,$serverId,$change,$giftTarget);
        if (!is_object($outcome) && !is_array($outcome))
            return (object)['success'=>false,'uncertain'=>true,
                            'msg'=>'service_edit_invalid_outcome'];
        $outcome=is_array($outcome)?(object)$outcome:$outcome;
        if (($outcome->success??false)!==true) return $outcome;
        // Every adapter must expose a verified, absolute remote readback.
        if (($outcome->verified??false)!==true ||
            !isset($outcome->applied_expire,$outcome->applied_bytes) ||
            !is_numeric($outcome->applied_expire) ||
            !is_numeric($outcome->applied_bytes) ||
            (int)$outcome->applied_expire<=0 ||
            (int)$outcome->applied_bytes<0)
            return (object)['success'=>false,'uncertain'=>true,
                            'msg'=>'service_edit_unverified_outcome'];
        return $outcome;
    }
}

if (!function_exists('getServerInfoAndConfig')) {
    function getServerInfoAndConfig($connection, $server_id)
    {
        $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $serverInfo = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id`=?");
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $serverConfig = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$serverInfo || !$serverConfig) {
            alert(\achbotMessage('purchase.core.get_server_info_and_config.alert.c0552cb2f5'));
            exit;
        }

        return [$serverInfo, $serverConfig];
    }
}

if (!function_exists('buildRemark')) {
    function buildRemark($botState, $srv_remark, $description, $userId, $forcedRemark = null)
    {
        if ($forcedRemark !== null && $forcedRemark !== '') {
            return $forcedRemark;
        }

        $remarkMode = $botState['remark'] ?? '';

        if ($remarkMode === "digits") {
            $rnd = rand(10000, 99999);
            return "{$srv_remark}-{$rnd}";
        } elseif ($remarkMode === "manual") {
            return $description;
        } else {
            $rnd = rand(1111, 99999);
            return "{$srv_remark}-{$userId}-{$rnd}";
        }
    }
}

// Only bounded, internally defined reasons may be forwarded to Telegram
// operators. Never send provider response bodies or Throwable::getMessage().
if (!function_exists('achbotFinanceDiagnosticReason')) {
    function achbotFinanceDiagnosticReason(Throwable $error): string
    {
        if ($error instanceof mysqli_sql_exception) {
            $state = (string)$error->getSqlState();
            return preg_match('/^[A-Z0-9]{5}$/D', $state)
                ? 'database_error_' . $state : 'database_error';
        }
        $message = $error->getMessage();
        $allowed = [
            'unsupported_panel', 'server_config_missing', 'order_not_found',
            'increase_day_plan_not_found', 'increase_plan_not_found',
            'invalid_increase_type', 'invalid_response_type',
            'server_connection_null', 'server_null_response',
            'provider_rejected',
        ];
        return in_array($message, $allowed, true) ? $message : 'runtime_error';
    }
}


// One canonical purchase bootstrap for webhook, payment return and scheduled consumers.
require_once __DIR__.'/../Subscription/Delivery.php';
require_once __DIR__.'/OrderWrites.php';
require_once __DIR__.'/../Finance/ReferralReward.php';
require_once __DIR__.'/AgentPricing.php';
require_once __DIR__.'/../Finance/ReceiptHelpers.php';
