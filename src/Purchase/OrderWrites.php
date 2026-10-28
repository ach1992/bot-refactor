<?php
/** AChBot shared order persistence and capacity provenance. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../messages/loader.php'; // ACHBOT_TEXT_CATALOG


/**
 * Split integer paid amounts across ordered accounts without losing a unit.
 * The first (total % count) rows receive one extra unit (1-based ordinal).
 */
if (!function_exists('achbotAllocateOrderAmount')) {
    function achbotAllocateOrderAmount(int $total, int $count, int $ordinal): int {
        if ($total<0 || $count<=0 || $ordinal<=0 || $ordinal>$count)
            throw new InvalidArgumentException('invalid_order_amount_allocation');
        return intdiv($total,$count)+($ordinal<=($total%$count)?1:0);
    }
}

if (!function_exists('insertOrder')) {
    function insertOrder(
        $connection,
        $uid,
        $token,
        $fid,
        $server_id,
        $inbound_id,
        $remark,
        $uniqid,
        $expire_date,
        $vray_link,
        $amount,
        $date,
        $agent_bought,
        $requireConfirmed = false
    ) {
        $stmt = $connection->prepare("INSERT INTO `orders_list`
            (`userid`, `token`, `transid`, `fileid`, `server_id`, `inbound_id`, `remark`, `uuid`, `expire_date`, `link`, `amount`, `status`, `date`, `notif`, `agent_bought`)
            VALUES (?, ?, '', ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, 0, ?);");
        $stmt->bind_param(
            "ssiiissisiii",
            $uid,
            $token,
            $fid,
            $server_id,
            $inbound_id,
            $remark,
            $uniqid,
            $expire_date,
            $vray_link,
            $amount,
            $date,
            $agent_bought
        );
        $saved=$stmt->execute();
        $inserted=$stmt->affected_rows;
        $orderId=($saved===true && $inserted===1) ? (int)$stmt->insert_id : 0;
        $stmt->close();
        if ($requireConfirmed && ($saved!==true || $inserted!==1 || $orderId<=0)) {
            throw new RuntimeException('order_insert_unconfirmed');
        }
        return $orderId;
    }
}

if (!function_exists('decreaseServerCapacity')) {
    function decreaseServerCapacity($connection, $server_id, $inbound_id, $plan_id, $count = 1, $requireConfirmed = false)
    {
        $count      = (int)$count;
        $server_id  = (int)$server_id;
        $inbound_id = (int)$inbound_id;
        $plan_id    = (int)$plan_id;

        if ($count <= 0) {
            if ($requireConfirmed) throw new RuntimeException('capacity_invalid_count');
            return;
        }

        if ($inbound_id === 0) {
            $stmt = $connection->prepare(
                "UPDATE `server_info` SET `ucount` = `ucount` - ?
                 WHERE `id` = ? AND `ucount` >= ?"
            );
            if (!$stmt) throw new RuntimeException('server_capacity_prepare_failed');
            $stmt->bind_param("iii", $count, $server_id, $count);
            $saved=$stmt->execute();
            $changed=$stmt->affected_rows;
            $stmt->close();
            if ($requireConfirmed && ($saved!==true || $changed!==1))
                throw new RuntimeException('server_capacity_update_unconfirmed');
        } else {
            $stmt = $connection->prepare(
                "UPDATE `server_plans` SET `acount` = `acount` - ?
                 WHERE `id` = ? AND `acount` >= ?"
            );
            if (!$stmt) throw new RuntimeException('plan_capacity_prepare_failed');
            $stmt->bind_param("iii", $count, $plan_id, $count);
            $saved=$stmt->execute();
            $changed=$stmt->affected_rows;
            $stmt->close();
            if ($requireConfirmed && ($saved!==true || $changed!==1))
                throw new RuntimeException('plan_capacity_update_unconfirmed');
        }
    }
}

if (!function_exists('ensureAccountCreationSucceeded')) {
    function ensureAccountCreationSucceeded($response, $serverInfo, $inbound_id, $admin, $throwOnError = false)
    {
        $hasError = false;
        $errorCode = '';
        $userMessage = '';
        $adminMessage = '';

        if (is_null($response)) {
            $hasError    = true;
            $errorCode   = 'server_connection_null';
            $userMessage = \achbotMessage('purchase.order_writes.ensure_account_creation_succeeded.user_message.7eb9410072');

        } elseif ($response === "inbound not Found") {
            $hasError    = true;
            $errorCode   = 'inbound_not_found';
            $userMessage = \achbotMessage('purchase.order_writes.ensure_account_creation_succeeded.user_message.7a64d65208');
            $title = $serverInfo['title'] ?? \achbotMessage('purchase.order_writes.ensure_account_creation_succeeded.text.f07a81a062');
            $adminMessage = \achbotMessage('purchase.order_writes.ensure_account_creation_succeeded.admin_message.inbound_missing', ['title' => $title, 'inbound_id' => $inbound_id]);

        } else {
            $success = false;
            $msg     = '';

            if (is_object($response)) {
                $success = !empty($response->success);
                $msg     = $response->msg ?? '';
            } elseif (is_array($response)) {
                $success = !empty($response['success']);
                $msg     = $response['msg'] ?? '';
            }

            if (!$success) {
                $hasError    = true;
                $errorCode   = 'server_response_failed';
                $userMessage = \achbotMessage('purchase.order_writes.ensure_account_creation_succeeded.user_message.b3fe04305c');

                $title = $serverInfo['title'] ?? \achbotMessage('purchase.order_writes.ensure_account_creation_succeeded.text.f07a81a062');
                $adminMessage = \achbotMessage('purchase.order_writes.ensure_account_creation_succeeded.admin_message.7af76743b5', ['title' => $title]) . ($msg ?: \achbotMessage('purchase.order_writes.ensure_account_creation_succeeded.text.9d93e5efb8'));
            }
        }

        if (!$hasError) {
            return;
        }

        if ($throwOnError) {
            $fullMessage = $errorCode . ($adminMessage ? (' | ' . $adminMessage) : '');
            throw new Exception($fullMessage ?: $errorCode);
        }

        if (!empty($userMessage)) {
            alert($userMessage);
        }

        if (!empty($adminMessage) && !empty($admin)) {
            sendMessage($adminMessage, null, null, $admin);
            // sendToAllAdmins(...)
        }

        exit;
    }
}


/**
 * Explicit nonfinancial origin for local references to an existing subscription.
 * The original setting table keeps this usable without new DDL or payment tables.
 * These records are immutable evidence, never inferred from zero prices or URLs.
 */
if (!function_exists('achbotImportedOrderOrigin')) {
    function achbotServiceOriginPositiveInt($value): int {
        if (is_int($value) && $value>0) return $value;
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/D',$value) &&
            strlen($value)<=strlen((string)PHP_INT_MAX) &&
            (strlen($value)<strlen((string)PHP_INT_MAX) || strcmp($value,(string)PHP_INT_MAX)<=0))
            return (int)$value;
        throw new RuntimeException('service_origin_identity_invalid');
    }

    function achbotServiceOriginKey(int $orderId): string {
        if ($orderId<=0) throw new RuntimeException('service_origin_identity_invalid');
        return 'ACHBOT_SERVICE_ORIGIN:'.$orderId;
    }

    /** Validate only bounded, non-secret native identity fields supplied by the native adapter. */
    function achbotServiceOriginIdentity(array $identity,string $provider,string $remark): array {
        if (array_diff(array_keys($identity),['remote_user_id','remote_created_at','remote_username']) ||
            ($identity['remote_username']??null)!==$remark ||
            !is_string($identity['remote_created_at']??null) ||
            preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/D',$identity['remote_created_at'])!==1)
            throw new RuntimeException('service_origin_native_identity_invalid');
        try {
            $date=new DateTimeImmutable($identity['remote_created_at']);
            $errors=DateTimeImmutable::getLastErrors();
            if (($errors!==false && ($errors['warning_count'] || $errors['error_count'])) ||
                $date->format('Y-m-d\TH:i:s.u\Z')!==$identity['remote_created_at'])
                throw new RuntimeException('service_origin_native_identity_invalid');
        } catch (Throwable $error) {throw new RuntimeException('service_origin_native_identity_invalid');}
        $out=['remote_created_at'=>$identity['remote_created_at'],'remote_username'=>$remark];
        if (array_key_exists('remote_user_id',$identity)) {
            if (!is_int($identity['remote_user_id']) || $identity['remote_user_id']<=0)
                throw new RuntimeException('service_origin_native_identity_invalid');
            $out['remote_user_id']=$identity['remote_user_id'];
        }
        if ($provider==='pasarguard' && !isset($out['remote_user_id']))
            throw new RuntimeException('service_origin_native_identity_invalid');
        return $out;
    }

    /** Absent is legacy; malformed or duplicate present evidence is never legacy. */
    function achbotImportedOrderOrigin(mysqli $db,int $orderId,bool $lock=false): ?array {
        $key=achbotServiceOriginKey($orderId);
        $q=$db->prepare('SELECT id,type,value FROM setting WHERE type=? LIMIT 2'.($lock?' FOR UPDATE':''));
        if (!$q) throw new RuntimeException('service_origin_read_unavailable');
        $q->bind_param('s',$key);
        if (!$q->execute()) {$q->close();throw new RuntimeException('service_origin_read_unavailable');}
        $rows=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
        if (!$rows) return null;
        if (count($rows)!==1 || $rows[0]['type']!==$key || !is_string($rows[0]['value']) || strlen($rows[0]['value'])>4096)
            throw new RuntimeException('service_origin_record_invalid');
        try {$value=json_decode($rows[0]['value'],true,16,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);}
        catch (Throwable $error) {throw new RuntimeException('service_origin_record_invalid');}
        $keys=['version','kind','order_id','owner_userid','actor_userid','provider','server_id','remark',
            'proof_sha256','native_identity','capacity_reserved','provider_account_id'];
        if (!is_array($value) || array_diff(array_keys($value),$keys) || array_diff($keys,array_keys($value)) ||
            ($value['version']??null)!==1 || ($value['kind']??null)!=='external_import' ||
            ($value['order_id']??null)!==$orderId || !is_int($value['owner_userid']??null) || $value['owner_userid']<=0 ||
            !is_int($value['actor_userid']??null) || $value['actor_userid']<=0 ||
            !is_int($value['server_id']??null) || $value['server_id']<=0 ||
            !is_string($value['provider']??null) || preg_match('/^[a-z][a-z0-9_]{0,31}$/D',$value['provider'])!==1 ||
            !is_string($value['remark']??null) || preg_match('/^[a-zA-Z0-9_@.-]{1,100}$/D',$value['remark'])!==1 ||
            !is_string($value['proof_sha256']??null) || preg_match('/^[a-f0-9]{64}$/D',$value['proof_sha256'])!==1 ||
            ($value['capacity_reserved']??null)!==false || !is_array($value['native_identity']??null) ||
            !($value['provider_account_id']===null || (is_int($value['provider_account_id']) && $value['provider_account_id']>0)) ||
            ($value['provider']==='pasarguard' && $value['provider_account_id']===null))
            throw new RuntimeException('service_origin_record_invalid');
        $identity=achbotServiceOriginIdentity($value['native_identity'],$value['provider'],$value['remark']);
        // Field order in input JSON is not identity, while every field/value must match.
        if ($identity!=$value['native_identity']) throw new RuntimeException('service_origin_record_invalid');
        return $value;
    }

    /** Bind origin to the local reference, while plan, price, token and logical routing may evolve. */
    function achbotOrderImportOrigin(mysqli $db,array $order,bool $lock=false): ?array {
        $id=achbotServiceOriginPositiveInt($order['id']??null);
        $origin=achbotImportedOrderOrigin($db,$id,$lock);
        if ($origin===null) return null;
        if (achbotServiceOriginPositiveInt($order['userid']??null)!==$origin['owner_userid'] ||
            ($order['remark']??null)!==$origin['remark'])
            throw new RuntimeException('service_origin_order_changed');
        return $origin;
    }

    function achbotOrderIsImported(mysqli $db,array $order,bool $lock=false): bool {
        return achbotOrderImportOrigin($db,$order,$lock)!==null;
    }

    /** Product selection stays available for verified imports without inventing a paid amount. */
    function achbotOrderMayUsePaidPlan(mysqli $db,array $order,bool $lock=false): bool {
        if (achbotOrderImportOrigin($db,$order,$lock)!==null) return true;
        $amount=$order['amount']??null;
        return (is_int($amount) && $amount>0) || (is_string($amount) &&
            preg_match('/^[1-9][0-9]*$/D',$amount)===1 && strlen($amount)<=10 && (int)$amount<=2147483647);
    }

    /** Caller owns the transaction containing the new order and successful native adoption. */
    function achbotRecordImportedOrderOriginLocked(mysqli $db,int $orderId,int $ownerId,int $actorId,
        string $provider,int $initialServerId,string $remark,string $proofSha256,array $nativeIdentity,
        ?int $providerAccountId=null): array {
        if (min($orderId,$ownerId,$actorId,$initialServerId)<=0 ||
            !function_exists('achbotSupportsProviderType') || !achbotSupportsProviderType($provider) ||
            preg_match('/^[a-zA-Z0-9_@.-]{1,100}$/D',$remark)!==1 ||
            preg_match('/^[a-f0-9]{64}$/D',$proofSha256)!==1 ||
            !($providerAccountId===null || $providerAccountId>0) ||
            ($provider==='pasarguard' && $providerAccountId===null))
            throw new RuntimeException('service_origin_input_invalid');
        $nativeIdentity=achbotServiceOriginIdentity($nativeIdentity,$provider,$remark);
        $r=$db->query('SELECT @@in_transaction AS active_transaction');
        if (!($r instanceof mysqli_result) || (int)($r->fetch_assoc()['active_transaction']??0)!==1)
            throw new RuntimeException('service_origin_transaction_required');
        $q=$db->prepare('SELECT * FROM orders_list WHERE id=? FOR UPDATE');
        if (!$q) throw new RuntimeException('service_origin_order_unavailable');
        $q->bind_param('i',$orderId);
        if (!$q->execute()) {$q->close();throw new RuntimeException('service_origin_order_unavailable');}
        $order=$q->get_result()->fetch_assoc();$q->close();
        if (!$order || (int)$order['userid']!==$ownerId || (int)$order['server_id']!==$initialServerId ||
            $order['remark']!==$remark || (int)$order['fileid']!==0 || (int)$order['amount']!==0 ||
            (int)$order['inbound_id']!==0 || (int)$order['status']!==1 || ($order['autoRenew']??null)!=='off')
            throw new RuntimeException('service_origin_order_invalid');
        $q=$db->prepare('SELECT c.type FROM server_config c JOIN server_info i ON i.id=c.id WHERE c.id=?');
        if (!$q) throw new RuntimeException('service_origin_server_unavailable');
        $q->bind_param('i',$initialServerId);
        if (!$q->execute()) {$q->close();throw new RuntimeException('service_origin_server_unavailable');}
        $server=$q->get_result()->fetch_assoc();$q->close();
        if (!$server || $server['type']!==$provider) throw new RuntimeException('service_origin_provider_changed');
        if (achbotImportedOrderOrigin($db,$orderId)!==null) throw new RuntimeException('service_origin_already_recorded');
        $origin=['version'=>1,'kind'=>'external_import','order_id'=>$orderId,'owner_userid'=>$ownerId,
            'actor_userid'=>$actorId,'provider'=>$provider,'server_id'=>$initialServerId,'remark'=>$remark,
            'proof_sha256'=>$proofSha256,'native_identity'=>$nativeIdentity,'capacity_reserved'=>false,
            'provider_account_id'=>$providerAccountId];
        $json=json_encode($origin,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        if (strlen($json)>4096) throw new RuntimeException('service_origin_record_too_large');
        $key=achbotServiceOriginKey($orderId);
        $q=$db->prepare('INSERT INTO setting(type,value) VALUES(?,?)');
        if (!$q) throw new RuntimeException('service_origin_write_unavailable');
        $q->bind_param('ss',$key,$json);
        if (!$q->execute() || $q->affected_rows!==1) {$q->close();throw new RuntimeException('service_origin_write_unconfirmed');}
        $q->close();
        $confirmed=achbotOrderImportOrigin($db,$order);
        if ($confirmed!==$origin) throw new RuntimeException('service_origin_write_unconfirmed');
        return $origin;
    }
}
