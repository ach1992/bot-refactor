<?php
/** AChBot administrative PAYMENT_KEYS editor.
 * Source-only, no schema or provider operation; logs never contain key values.
 */
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {
    http_response_code(404);exit;
}

function achbotPaymentKeyAdminFields(): array {
    return [
        'nowpayment','nextpay','zarinpal','bankAccount','holderName','giftCardUrl',
        'manualUsdtWalletBEP20','manualUsdtWalletTRC20','manualUsdtWalletERC20',
        'manualTrxWallet','manualUsdtRateToman','usdtAgentRateToman','manualTrxRateToman'
    ];
}

/** Hosted merchant secrets are never used as Telegram button labels. */
function achbotPaymentKeyAdminMerchantLabel(array $keys,string $field): string {
    if (!in_array($field,['nowpayment','zarinpal','nextpay'],true))
        throw new RuntimeException('payment_key_not_hosted_merchant');
    return is_string($keys[$field]??null) && trim($keys[$field])!==''
        ? '✅ تنظیم‌شده' : '⚠️ تنظیم‌نشده';
}

function achbotPaymentKeyAdminBaseline(array $keys,string $field,int $revision): string {
    // Protect low-entropy public account/rate fields against offline guessing
    // from stored user.step values. Source-authenticated webhook secret is
    // already mandatory for all editor operations.
    $secret=$GLOBALS['telegramWebhookSecret']??null;
    if (!is_string($secret) || strlen($secret)<16 || strlen($secret)>256 ||
        preg_match('/\A[A-Za-z0-9_-]+\z/D',$secret)!==1)
        throw new RuntimeException('payment_key_baseline_secret_unavailable');
    return hash_hmac('sha256',json_encode(
        [$field,array_key_exists($field,$keys),$keys[$field]??null,$revision],
        JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$secret);
}

function achbotPaymentKeyAdminStep(string $step): ?array {
    if (preg_match('/\AachbotPaymentKey\|([A-Za-z0-9]+)\|([1-9][0-9]*)\|([a-f0-9]{64})\z/D',
        $step,$m)!==1 || !in_array($m[1],achbotPaymentKeyAdminFields(),true))
        return null;
    $id=filter_var($m[2],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    return $id===false?null:['field'=>$m[1],'source_id'=>$id,'baseline'=>$m[3]];
}

/** Keep original network/rate semantics, with no untrusted echo of secrets. */
function achbotPaymentKeyAdminNormalize(string $field,string $input): ?string {
    if (!in_array($field,achbotPaymentKeyAdminFields(),true)) return null;
    if ($field==='giftCardUrl') {
        require_once __DIR__.'/ReceiptHelpers.php';
        $url=trim($input);
        return achbotManualGiftSellerUrlValid($url)?$url:null;
    }
    $addresses=[
        'manualUsdtWalletBEP20'=>'BEP20',
        'manualUsdtWalletTRC20'=>'TRC20',
        'manualUsdtWalletERC20'=>'ERC20',
        'manualTrxWallet'=>'TRON'
    ];
    if (isset($addresses[$field])) {
        require_once __DIR__.'/ReceiptHelpers.php';
        $value=trim($input);
        if ($value==='/clear') return '';
        $valid=$addresses[$field]==='TRON'
            ? achbotManualTronAddressValid($value)
            : achbotManualUsdtWalletValid($value,$addresses[$field]);
        return $valid?$value:null;
    }
    if (in_array($field,['manualUsdtRateToman','usdtAgentRateToman','manualTrxRateToman'],true)) {
        $value=trim($input);
        if ($value==='/clear') return '';
        if ($field==='manualTrxRateToman')
            return preg_match('/\A[1-9][0-9]{0,8}\z/D',$value)===1?$value:null;
        require_once __DIR__.'/ReceiptHelpers.php';
        return preg_match('/\A[1-9][0-9]{2,9}\z/D',$value)===1 &&
            achbotUsdtRateValue($value)!==null ? $value : null;
    }
    // Legacy merchant credentials and public account/holder fields accept
    // free text. Never display a submitted credential in bot confirmations.
    return $input;
}

/** Human-friendly validation feedback without echoing merchant credentials. */
function achbotPaymentKeyAdminInputHint(string $field,string $input): ?string {
    if (achbotPaymentKeyAdminNormalize($field,$input)!==null) return null;
    if ($field==='giftCardUrl')
        return '⚠️ لینک HTTPS معتبر، بدون اطلاعات ورود و حداکثر ۳۵۰ بایت وارد کنید.';
    if (str_starts_with($field,'manualUsdtWallet') || $field==='manualTrxWallet')
        return '⚠️ آدرس مقصد با شبکهٔ انتخاب‌شده سازگار نیست؛ برای حذف /clear ارسال کنید.';
    if (str_ends_with($field,'RateToman'))
        return '⚠️ نرخ معتبر به تومان وارد کنید؛ برای حذف /clear ارسال کنید.';
    return '⛔️ مقدار واردشده معتبر نیست.';
}

/**
 * Operation start: exact signed private callback creates a baseline-bound step.
 * Operation save/cancel: new signed private message must own that exact step.
 * The source marker, actor step and current per-field JSON write commit once.
 */
function achbotAdminPaymentKeyChange($update,int $actor,int $chat,int $message,
                                    int $primary,string $kind,string $field,
                                    ?string $input=null): bool {
    global $connection;
    $lock='achbot_payment_keys_write';
    $owns=false;$begun=false;
    try {
        if ($actor<=0 || $primary<=0 || $chat!==$actor || $message<=0 ||
            !in_array($kind,['start','save','cancel'],true) ||
            !in_array($field,achbotPaymentKeyAdminFields(),true) ||
            ($kind!=='start' && $input===null))
            throw new RuntimeException('payment_key_request_invalid');
        $bound=$kind==='start'?'changePaymentKeys'.$field:(string)$input;
        if (($kind==='start' && strlen($bound)>64) ||
            !function_exists('achbotWebhookIngressAuthenticated') ||
            !achbotWebhookIngressAuthenticated())
            throw new RuntimeException('payment_key_webhook_invalid');
        if (!function_exists('achbotDirectGiftWebhook'))
            require_once __DIR__.'/../Subscription/DirectGift.php';
        $frame=achbotDirectGiftWebhook(
            $update,$actor,$chat,$message,$bound,$kind==='start'?'callback':'message');
        $sourceId=(int)$frame['update_id'];
        $normalized=null;
        if ($kind==='save') {
            $normalized=achbotPaymentKeyAdminNormalize($field,(string)$input);
            if ($normalized===null) throw new RuntimeException('payment_key_value_invalid');
        }
        // Find actor PK before beginning the transaction: no early snapshot
        // can mask a role revocation under REPEATABLE READ.
        $findActor=static function() use($connection,$actor): int {
            $stmt=$connection->prepare('SELECT id FROM users WHERE userid=? LIMIT 2');
            if (!$stmt) throw new RuntimeException('payment_key_actor_find_prepare');
            $stmt->bind_param('i',$actor);
            $ok=$stmt->execute();
            $rows=$ok?$stmt->get_result()->fetch_all(MYSQLI_ASSOC):[];
            $stmt->close();
            if (!$ok || count($rows)!==1 || (int)$rows[0]['id']<=0)
                throw new RuntimeException('payment_key_actor_ambiguous');
            return (int)$rows[0]['id'];
        };
        $pk=$findActor();
        $stmt=$connection->prepare('SELECT GET_LOCK(?,10) AS acquired');
        if (!$stmt) throw new RuntimeException('payment_key_lock_prepare');
        $stmt->bind_param('s',$lock);
        $ok=$stmt->execute();
        $owns=$ok && (int)($stmt->get_result()->fetch_assoc()['acquired']??0)===1;
        $stmt->close();
        if (!$owns) throw new RuntimeException('payment_key_lock_unavailable');
        if (!$connection->begin_transaction()) throw new RuntimeException('payment_key_begin_failed');
        $begun=true;
        // Lock current administrator PK first, before any setting row.
        $stmt=$connection->prepare('SELECT id,userid,isAdmin,step FROM users WHERE id=? FOR UPDATE');
        if (!$stmt) throw new RuntimeException('payment_key_actor_lock_prepare');
        $stmt->bind_param('i',$pk);
        $ok=$stmt->execute();
        $user=$ok?$stmt->get_result()->fetch_assoc():null;
        $stmt->close();
        if (!$ok || !$user || (int)$user['id']!==$pk ||
            (int)$user['userid']!==$actor ||
            ($actor!==$primary && (int)$user['isAdmin']!==1) ||
            (string)$user['step']==='banned' || $findActor()!==$pk)
            throw new RuntimeException('payment_key_actor_revoked');
        $previousStep=(string)$user['step'];
        $startStep=achbotPaymentKeyAdminStep($previousStep);
        if ($kind!=='start' && (!$startStep ||
            $startStep['field']!==$field ||
            $sourceId<=$startStep['source_id']))
            throw new RuntimeException('payment_key_step_not_owned');
        if ($kind==='start' && (
            str_starts_with($previousStep,'achbotDirectGift') ||
            str_starts_with($previousStep,'achbotExempt:')))
            throw new RuntimeException('payment_key_other_wizard_active');

        // Existing legacy setting.type is NOT unique. Find <=2 nonlocking
        // IDs under named writer lock, then exclusively lock only chosen PK.
        $locate=static function(string $type) use($connection): array {
            $stmt=$connection->prepare(
                'SELECT id FROM setting WHERE type=? ORDER BY id LIMIT 2');
            if (!$stmt) throw new RuntimeException('payment_key_locate_prepare');
            $stmt->bind_param('s',$type);
            $ok=$stmt->execute();
            $rows=$ok?$stmt->get_result()->fetch_all(MYSQLI_ASSOC):[];
            $stmt->close();
            if (!$ok || count($rows)>1)
                throw new RuntimeException('payment_key_setting_ambiguous');
            if (!$rows) return [];
            $id=(int)$rows[0]['id'];
            $stmt=$connection->prepare('SELECT id,type,value FROM setting WHERE id=? FOR UPDATE');
            if (!$stmt) throw new RuntimeException('payment_key_row_lock_prepare');
            $stmt->bind_param('i',$id);
            $ok=$stmt->execute();
            $row=$ok?$stmt->get_result()->fetch_assoc():null;
            $stmt->close();
            if (!$ok || !$row || (int)$row['id']!==$id || $row['type']!==$type)
                throw new RuntimeException('payment_key_setting_changed');
            return $row;
        };
        $markerType='ACHBOT_ADMIN_PAYMENT_KEYS_SOURCE';
        $oldMarker=$locate($markerType);
        if ($oldMarker) {
            $marker=json_decode((string)$oldMarker['value'],true,512,JSON_THROW_ON_ERROR);
            if (!is_array($marker) || ($marker['version']??null)!==1 ||
                !is_int($marker['last_update_id']??null) ||
                !is_int($marker['actor_id']??null) ||
                $sourceId<=$marker['last_update_id'])
                throw new RuntimeException('payment_key_source_stale_or_invalid');
        }
        // A per-field revision prevents ABA: an older wizard must not be
        // accepted merely because another editor restored the same value.
        // Revisions are stored in the existing source-marker JSON, no DDL.
        $revisions=$marker['field_versions']??[];
        if (!is_array($revisions)) throw new RuntimeException('payment_key_revisions_invalid');
        foreach ($revisions as $revisionField=>$revision) {
            if (!is_string($revisionField) ||
                !in_array($revisionField,achbotPaymentKeyAdminFields(),true) ||
                !is_int($revision) || $revision<=0)
                throw new RuntimeException('payment_key_revisions_invalid');
        }
        $row=$locate('PAYMENT_KEYS');
        $keys=$row
            ? json_decode((string)$row['value'],true,512,JSON_THROW_ON_ERROR)
            : [];
        if (!is_array($keys)) throw new RuntimeException('payment_key_document_invalid');
        if ($kind==='start') {
            $nextStep='achbotPaymentKey|'.$field.'|'.$sourceId.'|'.
                achbotPaymentKeyAdminBaseline($keys,$field,$revisions[$field]??0);
        } else {
            $nextStep='none';
            if ($kind==='save') {
                $baseline=achbotPaymentKeyAdminBaseline($keys,$field,$revisions[$field]??0);
                if (!hash_equals($startStep['baseline'],$baseline))
                    throw new RuntimeException('payment_key_field_changed_since_editor');
                $keys[$field]=$normalized;
                $revisions[$field]=$sourceId;
                $json=json_encode($keys,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
                if ($row) {
                    if ($json!==(string)$row['value']) {
                        $stmt=$connection->prepare(
                            "UPDATE setting SET value=? WHERE id=? AND type='PAYMENT_KEYS'
                              AND BINARY value=BINARY ?");
                        if (!$stmt) throw new RuntimeException('payment_key_update_prepare');
                        $id=(int)$row['id'];$old=(string)$row['value'];
                        $stmt->bind_param('sis',$json,$id,$old);
                        $ok=$stmt->execute();$n=$stmt->affected_rows;$stmt->close();
                        if (!$ok || $n!==1)
                            throw new RuntimeException('payment_key_update_unconfirmed');
                    }
                } else {
                    $stmt=$connection->prepare(
                        "INSERT INTO setting(type,value) VALUES ('PAYMENT_KEYS',?)");
                    if (!$stmt) throw new RuntimeException('payment_key_insert_prepare');
                    $stmt->bind_param('s',$json);
                    $ok=$stmt->execute();$n=$stmt->affected_rows;$stmt->close();
                    if (!$ok || $n!==1)
                        throw new RuntimeException('payment_key_insert_unconfirmed');
                }
            }
        }
        $stmt=$connection->prepare('UPDATE users SET step=? WHERE id=? AND step=?');
        if (!$stmt) throw new RuntimeException('payment_key_step_update_prepare');
        $stmt->bind_param('sis',$nextStep,$pk,$previousStep);
        $ok=$stmt->execute();$n=$stmt->affected_rows;$stmt->close();
        if (!$ok || $n!==1) throw new RuntimeException('payment_key_step_update_unconfirmed');
        $nextMarker=json_encode([
            'version'=>1,'last_update_id'=>$sourceId,'actor_id'=>$actor,
            'last_message_id'=>$message,'kind'=>$kind,'field'=>$field,
            'field_versions'=>$revisions
        ],JSON_THROW_ON_ERROR);
        if ($oldMarker) {
            $stmt=$connection->prepare(
                'UPDATE setting SET value=? WHERE id=? AND type=? AND BINARY value=BINARY ?');
            if (!$stmt) throw new RuntimeException('payment_key_marker_update_prepare');
            $id=(int)$oldMarker['id'];$old=(string)$oldMarker['value'];
            $stmt->bind_param('siss',$nextMarker,$id,$markerType,$old);
        } else {
            $stmt=$connection->prepare('INSERT INTO setting(type,value) VALUES (?,?)');
            if (!$stmt) throw new RuntimeException('payment_key_marker_insert_prepare');
            $stmt->bind_param('ss',$markerType,$nextMarker);
        }
        $ok=$stmt->execute();$n=$stmt->affected_rows;$stmt->close();
        if (!$ok || $n!==1) throw new RuntimeException('payment_key_marker_unconfirmed');
        if (!$connection->commit()) throw new RuntimeException('payment_key_commit_uncertain');
        $begun=false;
        return true;
    } catch (Throwable $error) {
        if ($begun) {
            try {$connection->rollback();} catch(Throwable $ignored) {}
        }
        $reason=($error instanceof RuntimeException &&
            preg_match('/\A[a-z0-9_]{1,96}\z/D',$error->getMessage())===1)
            ? $error->getMessage() : 'unclassified';
        error_log('achbot_payment_key_edit_deferred kind='.$kind.
            ' reason='.$reason.' code='.(int)$error->getCode().
            ' class='.get_class($error));
        return false;
    } finally {
        if ($owns) {
            try {
                $stmt=$connection->prepare('SELECT RELEASE_LOCK(?)');
                $stmt->bind_param('s',$lock);
                $stmt->execute();$stmt->close();
            } catch(Throwable $ignored) {}
        }
    }
}
