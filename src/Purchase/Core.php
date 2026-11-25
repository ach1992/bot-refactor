<?php
/** AChBot V7: immutable V6.1 purchase helper definitions. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../messages/loader.php'; // ACHBOT_TEXT_CATALOG

require_once __DIR__.'/../Panel/ProviderRuntime.php';
require_once __DIR__.'/../Plans/Addons.php';

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

        $days   = achbotWholeServiceUnits($plan['days'],achbotServiceDaysMax());
        $vol    = achbotWholeServiceUnits($plan['volume'],achbotServiceVolumeMax());
        $price  = isset($plan['price']) ? (int)$plan['price'] : 0;
        $date   = time();

        return [
            'plan'             => $plan,
            'days'             => $days,
            'volume'           => $vol,
            'price'            => $price,
            'date'             => $date,
        ];
    }
}

if (!function_exists('ensureServerCapacity')) {
    function ensureServerCapacity(mysqli $connection, int $server_id): void
    {
        $server = achbotProviderRow($connection, 'SELECT ucount FROM server_info WHERE id=?', 'i', [$server_id]);
        if (!$server || (int)$server['ucount'] <= 0) {
            alert(\achbotMessage('legacy_messages.out_of_server_capacity'));
            exit;
        }
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
 * Provider-independent nonpaid gift modification boundary. Paid mutations
 * use the funded service engine. Native effects require verified readback.
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
            $rnd = rand(10000, 99999);
            return "{$srv_remark}-{$userId}-{$rnd}";
        }
    }
}

// Only bounded, internally defined reasons may be forwarded to Telegram
// operators. Never send provider response bodies or Throwable::getMessage().
// One canonical purchase bootstrap for webhook, payment return and scheduled consumers.
require_once __DIR__.'/../Subscription/Delivery.php';
require_once __DIR__.'/OrderWrites.php';
require_once __DIR__.'/../Finance/ReferralReward.php';
require_once __DIR__.'/AgentPricing.php';
require_once __DIR__.'/../Finance/ReceiptHelpers.php';

/** Shared current structural offering selection. The purpose determines
 * commercial eligibility, not provider compatibility alone. */
function achbotAvailablePlans(mysqli $db,?int $server=null,?int $category=null,string $purpose='standard'): array {
    require_once __DIR__.'/../Panel/ProviderRuntime.php';
    $price=match($purpose) {
        'standard'=>' AND p.price>0',
        'trial'=>' AND p.price=0',
        'admin'=>' AND p.price>=0',
        default=>throw new InvalidArgumentException('offering_purpose_invalid')
    };
    $sql="SELECT p.*,si.title AS server_title,si.flag AS server_flag,si.ucount AS capacity,
        cat.title AS category_title
        FROM server_plans p JOIN server_config sc ON sc.id=p.server_id
        JOIN server_info si ON si.id=p.server_id JOIN server_categories cat ON cat.id=p.catid
        WHERE sc.type IN (".achbotProviderSqlTypes().")
        AND si.active=1 AND si.state=1 AND si.ucount>0 AND p.active=1 AND cat.active=1 AND cat.parent=0".$price;
    $types='';$values=[];
    if ($server!==null) {$sql.=' AND p.server_id=?';$types.='i';$values[]=$server;}
    if ($category!==null) {$sql.=' AND p.catid=?';$types.='i';$values[]=$category;}
    $s=achbotProviderStatement($db,$sql.' ORDER BY p.id',$types,$values);
    $rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
    return array_values(array_filter($rows,static fn($p)=>achbotSupportsPlan($db,(int)$p['id'],(int)$p['inbound_id'],(int)$p['server_id'])));
}

/** Existing custom purchasing is a separate enabled offering, with configured
 * per-unit prices. Only ordinary saleable plans may be used as templates;
 * zero-base-price plans are reserved for the dedicated trial route. */
function achbotCustomPurchaseEnabled(array $states,string $mode): bool {
    if (($states['plandelkhahState']??'off')!=='on' || !in_array($mode,['none','one'],true))
        return false;
    foreach (['gbPrice','dayPrice'] as $key)
        if (!isset($states[$key]) || filter_var($states[$key],FILTER_VALIDATE_INT,
            ['options'=>['min_range'=>0]])===false) return false;
    return true;
}
function achbotSaleablePlans(mysqli $db,?int $server=null,?int $category=null): array {
    return achbotAvailablePlans($db,$server,$category,'standard');
}

function achbotPurchaseModeAllowed(array $user,array $states,string $mode): bool {
    return $mode==='none' || (in_array($mode,['one','much'],true) &&
        achbotAgencyActive($user,$states));
}

/** Custom purchase uses the same commercial templates as standard.
 * Payment-method discovery belongs to checkout, after the price is known. */
function achbotCustomerCustomPlans(mysqli $db,array $user,array $states,string $mode,int $actor,int $primary,?int $server=null,?int $category=null): array {
    if (!achbotCustomPurchaseEnabled($states,$mode)) return [];
    return achbotCustomerPurchasePlans($db,$user,$states,$mode,$actor,$primary,$server,$category);
}

/** Discover eligible commercial offerings even with no monetary method:
 * a valid discount can make the final command free. Admission still owns
 * sales policy, identity, capacity, quote and actual funding. */
function achbotCustomerPurchasePlans(mysqli $db,array $user,array $states,string $mode,int $actor,int $primary,?int $server=null,?int $category=null): array {
    if (!achbotCustomerSaleAllowed($states,$actor,$primary) || !achbotPurchaseModeAllowed($user,$states,$mode)) return [];
    return achbotSaleablePlans($db,$server,$category);
}
