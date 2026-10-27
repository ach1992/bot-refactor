<?php
/** AChBot per-logical-server subscription display settings; existing setting storage only. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }
require_once __DIR__.'/Presentation.php';

function achbotServerDisplayDefault(): array {
    return ['mode'=>'native','policy'=>['max_links'=>10]];
}
function achbotServerDisplayValid(array $document,string $provider): bool {
    if (!in_array($provider,['marzban','pasarguard'],true) ||
        array_diff(array_keys($document),['mode','policy','legacy']) ||
        !in_array($document['mode']??null,['native','marzban_legacy'],true) ||
        !is_array($document['policy']??null) ||
        !\AchBot\Subscription\Presentation\validPolicy($document['policy'])) return false;
    $policy=$document['policy'];
    foreach (['max_links','hide_if_more_than'] as $key)
        if (isset($policy[$key]) && $policy[$key]>1000) return false;
    if (count($policy['selectors']??[])>50) return false;
    foreach ($policy['selectors']??[] as $selector)
        foreach ($selector as $value)
            if (strlen($value)>300 || preg_match('/[\x00-\x1f\x7f]/',$value)) return false;
    if ($document['mode']==='native') return !array_key_exists('legacy',$document);
    if ($provider!=='marzban' || array_diff(array_keys($policy),['show_configs','show_subscription','qr']) || !is_array($document['legacy']??null)) return false;
    foreach ($document['legacy'] as $key=>$value) {
        if (in_array($key,['showIndexes','linkIndices'],true)) {
            if (!is_array($value) || !array_is_list($value) || count($value)>100) return false;
            foreach ($value as $index)
                if (!is_int($index) || $index<($key==='showIndexes'?1:0) || $index>1000) return false;
        } elseif (in_array($key,['hideConfigIfMany','hideConnectLinkForMarzban'],true)) {
            if (!is_bool($value)) return false;
        } else return false;
    }
    return true;
}
function achbotServerDisplayType(int $serverId): string {
    if ($serverId<=0) throw new RuntimeException('display_server_invalid');
    return 'ACHBOT_SERVER_DISPLAY_'.$serverId;
}
function achbotServerDisplayServer(mysqli $db,int $serverId,bool $lock=false): array {
    $s=$db->prepare('SELECT s.id,c.type FROM server_info s JOIN server_config c ON c.id=s.id
        WHERE s.id=? AND s.active=1'.($lock?' FOR UPDATE':''));
    $s->bind_param('i',$serverId);$s->execute();
    $rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
    if (count($rows)!==1 || !in_array($rows[0]['type'],['marzban','pasarguard'],true))
        throw new RuntimeException('display_server_unavailable');
    return $rows[0];
}
function achbotServerDisplayRow(mysqli $db,int $serverId,bool $lock=false): ?array {
    $type=achbotServerDisplayType($serverId);
    // Legacy setting.type has no unique index. Never silently pick a duplicate.
    $s=$db->prepare('SELECT id,type,value FROM setting WHERE type=? ORDER BY id LIMIT 2'.($lock?' FOR UPDATE':''));
    $s->bind_param('s',$type);$s->execute();
    $rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
    if (count($rows)>1) throw new RuntimeException('display_setting_duplicate');
    return $rows[0]??null;
}
function achbotServerDisplayDocument(?array $row,int $serverId,string $provider): array {
    if ($row===null) return achbotServerDisplayDefault();
    $value=json_decode((string)$row['value'],true,32,JSON_THROW_ON_ERROR);
    if (!is_array($value) || array_diff(array_keys($value),
        ['version','server_id','provider','updated_source','display']) ||
        ($value['version']??null)!==1 || ($value['server_id']??null)!==$serverId ||
        ($value['provider']??null)!==$provider ||
        !is_int($value['updated_source']??null) || $value['updated_source']<=0 ||
        !is_array($value['display']??null) ||
        !achbotServerDisplayValid($value['display'],$provider))
        throw new RuntimeException('display_setting_invalid');
    return $value['display'];
}
/** Only this resolver may supply runtime options. It never reads private display globals. */
function achbotServerDisplayOptions($db,int $serverId,string $provider): array {
    try {
        if (!$db instanceof mysqli) throw new RuntimeException('display_database_unavailable');
        $server=achbotServerDisplayServer($db,$serverId);
        if ($server['type']!==$provider) throw new RuntimeException('display_provider_mismatch');
        return achbotServerDisplayDocument(achbotServerDisplayRow($db,$serverId),$serverId,$provider);
    } catch (Throwable $error) {
        // Invalid configuration suppresses ALL config/subscription/QR output.
        // Keep infrastructure diagnostics out of customer copy and log no values.
        error_log('achbot_server_display_unavailable');
        return ['policy'=>false];
    }
}
/** Read-only provisioning preflight; no setting/remote effects. */
function achbotRequireServerDisplay(mysqli $db,int $serverId,string $provider): array {
    $options=achbotServerDisplayOptions($db,$serverId,$provider);
    if (($options['policy']??null)===false) throw new RuntimeException('display_configuration_unavailable');
    return $options;
}
function achbotServerDisplayActor(mysqli $db,int $actor,int $primary,bool $lock=false): array {
    $s=$db->prepare('SELECT id,userid,isAdmin,step FROM users WHERE userid=? LIMIT 2'.($lock?' FOR UPDATE':''));
    $s->bind_param('i',$actor);$s->execute();
    $rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
    if ($actor<=0 || $primary<=0 || count($rows)!==1 ||
        ($actor!==$primary && (int)$rows[0]['isAdmin']!==1) ||
        $rows[0]['step']==='banned') throw new RuntimeException('display_admin_required');
    return $rows[0];
}
function achbotServerDisplayBaseline(?array $row): string {
    return hash('sha256',$row===null?'absent':$row['id'].':'.$row['value']);
}
function achbotServerDisplayFrame($update,int $actor,int $chat,int $message,string $bound,string $kind): int {
    if (!function_exists('achbotWebhookIngressAuthenticated') ||
        !achbotWebhookIngressAuthenticated()) throw new RuntimeException('display_webhook_required');
    require_once __DIR__.'/DirectGift.php';
    return (int)achbotDirectGiftWebhook($update,$actor,$chat,$message,$bound,$kind)['update_id'];
}
/** Editor owns its transaction and serializes all writers despite nonunique setting.type.
 * Server/provider, fresh database role, user step and baseline are checked under lock.
 * A stale/replayed callback cannot commit a new setting or overwrite another editor.
 */
function achbotServerDisplayEdit(mysqli $db,int $serverId,int $actor,int $primary,
    int $source,string $kind,?string $input=null): void {
    if ($source<=0 || !in_array($kind,['start','save','cancel'],true))
        throw new RuntimeException('display_edit_invalid');
    $name='achbot_server_display_writer';$owns=false;$begun=false;
    try {
        $s=$db->prepare('SELECT GET_LOCK(?,5) AS acquired');$s->bind_param('s',$name);$s->execute();
        $owns=(int)($s->get_result()->fetch_assoc()['acquired']??0)===1;$s->close();
        if (!$owns) throw new RuntimeException('display_write_busy');
        if (!$db->begin_transaction()) throw new RuntimeException('display_begin_failed');$begun=true;
        $user=achbotServerDisplayActor($db,$actor,$primary,true);
        $server=achbotServerDisplayServer($db,$serverId,true);$provider=$server['type'];
        $row=achbotServerDisplayRow($db,$serverId,true);
        $step=(string)$user['step'];
        // Starting an editor does not overwrite any in-progress wizard.
        if ($kind==='start') {
            if ($step!=='none' && !str_starts_with($step,'achbotDisplay|'))
                throw new RuntimeException('display_other_editor_active');
            if ($row!==null) {
                $stored=json_decode($row['value'],true);
                if (is_array($stored) && is_int($stored['updated_source']??null) &&
                    $source<=$stored['updated_source'])
                    throw new RuntimeException('display_source_stale');
            }
            // A malformed single row may be repaired explicitly by its admin;
            // duplicate rows remain blocked for an operator to reconcile.
            $next='achbotDisplay|'.$serverId.'|'.$source.'|'.achbotServerDisplayBaseline($row);
            if (preg_match('/^achbotDisplay\|[1-9][0-9]*\|([1-9][0-9]*)\|[a-f0-9]{64}$/D',$step,$m)
                && $source<=(int)$m[1]) throw new RuntimeException('display_source_stale');
        } else {
            if (!preg_match('/^achbotDisplay\|([1-9][0-9]*)\|([1-9][0-9]*)\|([a-f0-9]{64})$/D',$step,$m) ||
                (int)$m[1]!==$serverId || $source<=(int)$m[2] ||
                !hash_equals($m[3],achbotServerDisplayBaseline($row)))
                throw new RuntimeException('display_editor_stale');
            $next='none';
            if ($kind==='save') {
                if (!is_string($input) || strlen($input)>12000) throw new RuntimeException('display_input_invalid');
                $document=json_decode($input,true,32,JSON_THROW_ON_ERROR);
                if (!is_array($document) || !achbotServerDisplayValid($document,$provider))
                    throw new RuntimeException('display_input_invalid');
                $value=json_encode(['version'=>1,'server_id'=>$serverId,'provider'=>$provider,
                    'updated_source'=>$source,'display'=>$document],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
                if ($row===null) {
                    $type=achbotServerDisplayType($serverId);
                    $s=$db->prepare('INSERT INTO setting(type,value) VALUES (?,?)');$s->bind_param('ss',$type,$value);
                } else {
                    $id=(int)$row['id'];$old=(string)$row['value'];
                    $s=$db->prepare('UPDATE setting SET value=? WHERE id=? AND BINARY value=BINARY ?');
                    $s->bind_param('sis',$value,$id,$old);
                }
                $ok=$s->execute();$count=$s->affected_rows;$s->close();
                if (!$ok || $count!==1) throw new RuntimeException('display_write_unconfirmed');
            }
        }
        $id=(int)$user['id'];
        $s=$db->prepare('UPDATE users SET step=? WHERE id=? AND step=?');$s->bind_param('sis',$next,$id,$step);
        $ok=$s->execute();$count=$s->affected_rows;$s->close();
        if (!$ok || $count!==1) throw new RuntimeException('display_step_unconfirmed');
        if (!$db->commit()) throw new RuntimeException('display_commit_unknown');$begun=false;
    } finally {
        if ($begun) {try {$db->rollback();} catch(Throwable $ignored) {}}
        if ($owns) {
            $s=$db->prepare('SELECT RELEASE_LOCK(?)');$s->bind_param('s',$name);$s->execute();$s->close();
        }
    }
}
function achbotServerDisplayKeys(int $serverId): string {
    return json_encode(['inline_keyboard'=>[
        [['text'=>'✏️ ویرایش سیاست نمایش','callback_data'=>'serverDisplayEdit'.$serverId]],
        [['text'=>'🔙 تنظیمات سرور','callback_data'=>'showServerSettings'.$serverId.'_0']],
    ]],JSON_UNESCAPED_UNICODE);
}
function achbotServerDisplayGuide(string $provider): string {
    $example=json_encode(achbotServerDisplayDefault(),JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
    $guide="تنظیمات فقط برای همین سرور ذخیره می‌شود. سند JSON کامل را بفرستید.\n".
        "mode=native و policy:\n".
        "show_configs و show_subscription: true یا false\n".
        "max_links: سقف تعداد لینک؛ hide_if_more_than: مخفی‌کردن کانفیگ‌ها بالاتر از این تعداد (۰ تا ۱۰۰۰)\n".
        "qr: auto، subscription، first_config یا none\n".
        "selectors: فهرست شرط‌های protocol، name یا tag؛ شرط‌های یک رکورد همزمان و رکوردها به‌ترتیب اجرا می‌شوند. [] هیچ کانفیگی انتخاب نمی‌کند. حذف selectors همه را انتخاب می‌کند.\n".
        "name دقیق است. tag فقط از فراداده واقعی می‌آید؛ نام یا Group تگ نیست.\n".
        "QR اشتراک فقط لینک اشتراکِ قابل‌نمایش را دارد؛ QR کانفیگ فقط انتخاب‌های قابل‌نمایش را دارد. مخفی‌کردن لینک، دسترسی محتوای اشتراک را محدود نمی‌کند.\n".
        "نمونه:\n<pre>".\AchBot\Subscription\Presentation\escape($example)."</pre>";
    if ($provider==='marzban') {
        $legacy=['mode'=>'marzban_legacy','policy'=>new stdClass(),'legacy'=>[
            'showIndexes'=>[2,4],'linkIndices'=>[0,2],'hideConfigIfMany'=>false,'hideConnectLinkForMarzban'=>false]];
        $guide.="\nسازگاری اختیاری مرزبان: showIndexes یک‌مبنا در نمایش بیشتر از ۳ لینک؛ linkIndices صفرمبنا در تحویل است. QR کانفیگ در این حالت همه لینک‌های خام را دارد، حتی اگر متن مخفی باشد.\n<pre>".
            \AchBot\Subscription\Presentation\escape(json_encode($legacy,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT))."</pre>";
    }
    return $guide;
}
function achbotHandleServerDisplayAdmin(): bool {
    global $connection,$data,$text,$userInfo,$from_id,$admin,$chat_id,$message_id,$update,$cancelKey,$buttonValues;
    $callback=is_string($data??null) && str_starts_with($data,'serverDisplay');
    $step=(string)($userInfo['step']??'');
    if (!$callback && !str_starts_with($step,'achbotDisplay|')) return false;
    try {
        if ($callback) {
            if (!preg_match('/^serverDisplay(Edit)?([1-9][0-9]{0,9})$/D',$data,$m))
                throw new RuntimeException('display_callback_invalid');
            $sid=(int)$m[2];
            $source=achbotServerDisplayFrame($update,(int)$from_id,(int)$chat_id,(int)$message_id,$data,'callback');
            achbotServerDisplayActor($connection,(int)$from_id,(int)$admin);
            $server=achbotServerDisplayServer($connection,$sid);
            if ($m[1]==='Edit') {
                achbotServerDisplayEdit($connection,$sid,(int)$from_id,(int)$admin,$source,'start');
                sendMessage(achbotServerDisplayGuide($server['type']),$cancelKey);
            } else {
                $row=achbotServerDisplayRow($connection,$sid);
                try {
                    $document=achbotServerDisplayDocument($row,$sid,$server['type']);
                    $body="⚙️ نمایش لینک‌های این سرور\n<pre>".
                        \AchBot\Subscription\Presentation\escape(json_encode($document,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT))."</pre>";
                } catch (Throwable $error) {
                    $body='⚠️ تنظیمات ذخیره‌شده معتبر نیست و نمایش لینک متوقف است. برای اصلاح، سیاست کامل این سرور را دوباره ذخیره کنید.';
                }
                sendMessage($body,achbotServerDisplayKeys($sid));
            }
        } else {
            if (!preg_match('/^achbotDisplay\|([1-9][0-9]*)\|/D',$step,$m))
                throw new RuntimeException('display_step_invalid');
            $sid=(int)$m[1];
            if (!is_string($text)) throw new RuntimeException('display_message_invalid');
            $source=achbotServerDisplayFrame($update,(int)$from_id,(int)$chat_id,(int)$message_id,$text,'message');
            $kind=$text===($buttonValues['cancel']??null)?'cancel':'save';
            achbotServerDisplayEdit($connection,$sid,(int)$from_id,(int)$admin,$source,$kind,$text);
            sendMessage($kind==='save'?'✅ تنظیمات نمایش این سرور ذخیره شد.':'ویرایش لغو شد.',achbotServerDisplayKeys($sid));
        }
    } catch (Throwable $error) {
        error_log('achbot_server_display_admin_deferred');
        sendMessage('⚠️ تنظیمات نمایش تأیید نشد. دسترسی و قالب مقادیر را بررسی و ویرایش را از تنظیمات همان سرور دوباره آغاز کنید.',$cancelKey);
    }
    return true;
}
