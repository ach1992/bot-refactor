<?php
require_once __DIR__.'/../Admin/Access.php';
/** AChBot per-logical-server subscription display settings; existing setting storage only. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }
require_once __DIR__ . '/../../messages/loader.php'; // ACHBOT_TEXT_CATALOG

require_once __DIR__.'/Presentation.php';

function achbotServerDisplayDefault(): array {
    return ['mode'=>'native','policy'=>['max_links'=>10]];
}
function achbotServerDisplayValid(array $document,string $provider): bool {
    if (!achbotSupportsProviderType($provider) ||
        array_diff(array_keys($document),['mode','policy','legacy']) ||
        !in_array($document['mode']??null,['native','marzban_legacy'],true) ||
        !is_array($document['policy']??null) ||
        !\AchBot\Subscription\Presentation\validPolicy($document['policy'])) return false;
    $policy=$document['policy'];
    foreach (['max_links','hide_if_more_than'] as $key)
        if (isset($policy[$key]) && $policy[$key]>1000) return false;
    foreach (['selectors','delivery_selectors','view_selectors'] as $key) {
        if (count($policy[$key]??[])>50) return false;
        foreach ($policy[$key]??[] as $selector)
            foreach ($selector as $value)
                if (strlen($value)>300 || preg_match('/[\x00-\x1f\x7f]/',$value)) return false;
    }
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
    $s=$db->prepare('SELECT s.id,s.title,c.type FROM server_info s JOIN server_config c ON c.id=s.id
        WHERE s.id=? AND s.active=1'.($lock?' FOR UPDATE':''));
    $s->bind_param('i',$serverId);$s->execute();
    $rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
    if (count($rows)!==1 || !achbotSupportsProviderType($rows[0]['type']))
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
        !achbotAdminCanLocked($db,$actor,$primary,'servers') ||
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
function achbotServerDisplayChange(array $document,string $provider,string $field,string $input): array {
    $policy=$document['policy'];
    $keys=['cfg'=>'show_configs','sub'=>'show_subscription'];
    if (isset($keys[$field]) && $input==='flip') {
        $key=$keys[$field];$policy[$key]=!($policy[$key]??true);
    } elseif ($field==='qr') {
        if (!in_array($input,['auto','subscription','first_config','none'],true))
            throw new RuntimeException('display_choice_invalid');
        $policy['qr']=$input;
    } elseif ($field==='mode') {
        if (!in_array($input,['native','marzban_legacy'],true) || ($input==='marzban_legacy' && $provider!=='marzban'))
            throw new RuntimeException('display_mode_invalid');
        $policy=array_intersect_key($policy,array_flip(['show_configs','show_subscription','qr']));
        $document=$input==='native'
            ? ['mode'=>'native','policy'=>$policy+['max_links'=>10]]
            : ['mode'=>'marzban_legacy','policy'=>$policy,'legacy'=>[
                'showIndexes'=>[1],'linkIndices'=>[0],
                'hideConfigIfMany'=>false,'hideConnectLinkForMarzban'=>false]];
        if (!achbotServerDisplayValid($document,$provider)) throw new RuntimeException('display_input_invalid');
        return $document;
    } elseif (in_array($field,['many','viewhide'],true) && $input==='flip' && $document['mode']==='marzban_legacy') {
        $key=$field==='many'?'hideConfigIfMany':'hideConnectLinkForMarzban';
        $document['legacy'][$key]=!($document['legacy'][$key]??false);
    } elseif (in_array($field,['view','delivery'],true) && $document['mode']==='marzban_legacy') {
        $indices=[];
        if ($input!=='/empty') {
            if (!preg_match('/^[0-9]+(?:\s*,\s*[0-9]+)*$/D',trim($input)))
                throw new RuntimeException('display_indices_invalid');
            foreach (preg_split('/\s*,\s*/',trim($input)) as $part) {
                if (strlen($part)>4) throw new RuntimeException('display_indices_invalid');
                $indices[]=(int)$part;
            }
        }
        $document['legacy'][$field==='view'?'showIndexes':'linkIndices']=$indices;
    } elseif ($document['mode']==='native' && in_array($field,['max','threshold'],true)) {
        $key=$field==='max'?'max_links':'hide_if_more_than';
        if ($field==='threshold' && $input==='/off') unset($policy[$key]);
        else {
            if (!preg_match('/^(?:0|[1-9][0-9]{0,3})$/D',trim($input)) || (int)$input>1000)
                throw new RuntimeException('display_number_invalid');
            $policy[$key]=(int)$input;
        }
    } elseif ($document['mode']==='native' && in_array($field,['select','dselect','vselect'],true)) {
        $key=['select'=>'selectors','dselect'=>'delivery_selectors','vselect'=>'view_selectors'][$field];
        if ($input==='all') {
            // Explicit ALL edits preserve the peer's effective inherited list.
            // Reads never normalize old documents or introduce an ALL sentinel.
            if ($field!=='select' && array_key_exists('selectors',$policy)) {
                $other=$field==='dselect'?'view_selectors':'delivery_selectors';
                if (!array_key_exists($other,$policy)) $policy[$other]=$policy['selectors'];
                unset($policy['selectors']);
            }
            unset($policy[$key]);
        } elseif ($input==='none') $policy[$key]=[];
        else throw new RuntimeException('display_choice_invalid');
    } elseif ($document['mode']==='native' && in_array($field,['protocols','names','dprotocols','vprotocols','dnames','vnames'],true)) {
        $key=match($field) {
            'dprotocols','dnames'=>'delivery_selectors',
            'vprotocols','vnames'=>'view_selectors', default=>'selectors'
        };
        $protocols=str_ends_with($field,'protocols');
        $values=$protocols?explode(',',$input):preg_split('/\R/',trim($input));
        $policy[$key]=[];
        foreach ($values as $value) {
            $value=trim($value);
            if ($value==='' || ($protocols && !preg_match('/^[a-z][a-z0-9+.-]{0,30}$/iD',$value)))
                throw new RuntimeException('display_selector_invalid');
            $policy[$key][]=[$protocols?'protocol':'name'=>$value];
        }
    } else throw new RuntimeException('display_field_invalid');
    $document['policy']=$policy;
    if (!achbotServerDisplayValid($document,$provider)) throw new RuntimeException('display_input_invalid');
    return $document;
}
function achbotServerDisplayRevision(?array $row): int {
    if ($row===null) return 0;
    $value=json_decode((string)$row['value'],true,32,JSON_THROW_ON_ERROR);
    if (!is_array($value) || !is_int($value['updated_source']??null) || $value['updated_source']<=0)
        throw new RuntimeException('display_revision_invalid');
    return $value['updated_source'];
}
function achbotServerDisplayEdit(mysqli $db,int $serverId,int $actor,int $primary,
    int $source,string $kind,?string $input=null,string $field='document',?int $expected=null): void {
    if ($source<=0 || !in_array($kind,['start','save','cancel','set'],true))
        throw new RuntimeException('display_edit_invalid');
    if (!in_array($field,['document','cfg','sub','qr','mode','many','viewhide','view','delivery',
        'max','threshold','select','protocols','names','dselect','vselect','dprotocols','vprotocols','dnames','vnames'],true)) throw new RuntimeException('display_field_invalid');
    $name='achbot_server_display_writer';$owns=false;$begun=false;
    try {
        $s=$db->prepare('SELECT GET_LOCK(?,5) AS acquired');$s->bind_param('s',$name);$s->execute();
        $owns=(int)($s->get_result()->fetch_assoc()['acquired']??0)===1;$s->close();
        if (!$owns) throw new RuntimeException('display_write_busy');
        if (!$db->begin_transaction()) throw new RuntimeException('display_begin_failed');$begun=true;
        $user=achbotServerDisplayActor($db,$actor,$primary,true);
        $server=achbotServerDisplayServer($db,$serverId,true);$provider=$server['type'];
        $row=achbotServerDisplayRow($db,$serverId,true);$step=(string)$user['step'];$write=null;
        if ($kind==='start' || $kind==='set') {
            if ($step!=='none' && !str_starts_with($step,'achbotDisplay|'))
                throw new RuntimeException('display_other_editor_active');
            // Advanced editing may repair one malformed row. Normal controls
            // require a valid document and the revision shown on their button.
            if ($field!=='document' || $kind==='set') {
                $document=achbotServerDisplayDocument($row,$serverId,$provider);
                if (in_array($field,['dselect','vselect','dprotocols','vprotocols','dnames','vnames'],true) && $document['mode']!=='native')
                    throw new RuntimeException('display_field_invalid');
                $revision=achbotServerDisplayRevision($row);
                if ($expected===null || $expected!==$revision || $source<=$revision)
                    throw new RuntimeException('display_control_stale');
            } elseif ($row!==null) {
                $stored=json_decode($row['value'],true);
                if (is_array($stored) && is_int($stored['updated_source']??null) &&
                    $source<=$stored['updated_source']) throw new RuntimeException('display_source_stale');
            }
            if (preg_match('/^achbotDisplay\|[1-9][0-9]*\|([1-9][0-9]*)\|[a-f0-9]{64}(?:\|[a-z]+)?$/D',$step,$m)
                && $source<=(int)$m[1]) throw new RuntimeException('display_source_stale');
            if ($kind==='start') {
                $next='achbotDisplay|'.$serverId.'|'.$source.'|'.achbotServerDisplayBaseline($row).'|'.$field;
            } else {
                $write=achbotServerDisplayChange($document,$provider,$field,(string)$input);
                $next='none';
            }
        } else {
            if (!preg_match('/^achbotDisplay\|([1-9][0-9]*)\|([1-9][0-9]*)\|([a-f0-9]{64})(?:\|([a-z]+))?$/D',$step,$m) ||
                (int)$m[1]!==$serverId || $source<=(int)$m[2] ||
                !hash_equals($m[3],achbotServerDisplayBaseline($row)))
                throw new RuntimeException('display_editor_stale');
            $next='none';
            if ($kind==='save') {
                if (!is_string($input) || strlen($input)>12000) throw new RuntimeException('display_input_invalid');
                $ownedField=$m[4]??'document';
                if ($ownedField==='document') {
                    $write=json_decode($input,true,32,JSON_THROW_ON_ERROR);
                    if (!is_array($write) || !achbotServerDisplayValid($write,$provider))
                        throw new RuntimeException('display_input_invalid');
                } else $write=achbotServerDisplayChange(
                    achbotServerDisplayDocument($row,$serverId,$provider),$provider,$ownedField,$input);
            }
        }
        if ($write!==null) {
            $value=json_encode(['version'=>1,'server_id'=>$serverId,'provider'=>$provider,
                'updated_source'=>$source,'display'=>$write],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
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
        if ($next!==$step) {
            $id=(int)$user['id'];
            $s=$db->prepare('UPDATE users SET step=? WHERE id=? AND step=?');$s->bind_param('sis',$next,$id,$step);
            $ok=$s->execute();$count=$s->affected_rows;$s->close();
            if (!$ok || $count!==1) throw new RuntimeException('display_step_unconfirmed');
        }
        if (!$db->commit()) throw new RuntimeException('display_commit_unknown');$begun=false;
    } finally {
        if ($begun) {try {$db->rollback();} catch(Throwable $ignored) {}}
        if ($owns) {
            $s=$db->prepare('SELECT RELEASE_LOCK(?)');$s->bind_param('s',$name);$s->execute();$s->close();
        }
    }
}
function achbotServerDisplayKeys(int $serverId,?array $document=null,int $revision=0): string {
    $keys=[];
    if ($document!==null) {
        $p=$document['policy'];
        $label=static fn($value)=>$value?\achbotMessage('subscription.server_display_settings.server_display_keys.text.be9fd3bd1b'):\achbotMessage('subscription.server_display_settings.server_display_keys.text.841b8cb341');
        $keys[]=[
            ['text'=>\achbotMessage('subscription.server_display_settings.server_display_keys.button.d087c68a83').$label($p['show_configs']??true),'callback_data'=>"serverDisplaySet{$serverId}_cfg_flip_{$revision}"],
            ['text'=>\achbotMessage('subscription.server_display_settings.server_display_keys.button.9508cf3453').$label($p['show_subscription']??true),'callback_data'=>"serverDisplaySet{$serverId}_sub_flip_{$revision}"],
        ];
        $keys[]=[['text'=>\achbotMessage('subscription.server_display_settings.server_display_keys.button.3ca7cc1405'),'callback_data'=>'serverDisplayQr'.$serverId]];
        if ($document['mode']==='native') {
            $keys[]=[
                ['text'=>\achbotMessage('subscription.server_display_settings.server_display_keys.button.f86223c190'),'callback_data'=>"serverDisplayValue{$serverId}_max_{$revision}"],
                ['text'=>\achbotMessage('subscription.server_display_settings.server_display_keys.button.6b77433ae2'),'callback_data'=>"serverDisplayValue{$serverId}_threshold_{$revision}"],
            ];
            $keys[]=[['text'=>\achbotMessage('subscription.server_display_settings.server_display_keys.button.a4c3ef092e'),'callback_data'=>'serverDisplaySelect'.$serverId]];
        } else {
            $keys[]=[
                ['text'=>\achbotMessage('subscription.server_display_settings.server_display_keys.button.9eea37f5f0'),'callback_data'=>"serverDisplayValue{$serverId}_view_{$revision}"],
                ['text'=>\achbotMessage('subscription.server_display_settings.server_display_keys.button.c0668ff5b5'),'callback_data'=>"serverDisplayValue{$serverId}_delivery_{$revision}"],
            ];
            $keys[]=[
                ['text'=>\achbotMessage('subscription.server_display_settings.server_display_keys.button.b71d77fe36').$label($document['legacy']['hideConfigIfMany']??false),
                    'callback_data'=>"serverDisplaySet{$serverId}_many_flip_{$revision}"],
                ['text'=>\achbotMessage('subscription.server_display_settings.server_display_keys.button.f4c0c5cd38').$label($document['legacy']['hideConnectLinkForMarzban']??false),
                    'callback_data'=>"serverDisplaySet{$serverId}_viewhide_flip_{$revision}"],
            ];
        }
        $keys[]=[['text'=>\achbotMessage('subscription.server_display_settings.server_display_keys.button.b3ba57d81a'),'callback_data'=>'serverDisplayMode'.$serverId]];
    }
    $keys[]=[['text'=>\achbotMessage('subscription.server_display_settings.server_display_keys.button.ad00f1d27d'),'callback_data'=>'serverDisplayEdit'.$serverId]];
    $keys[]=[['text'=>\achbotMessage('subscription.server_display_settings.server_display_keys.button.8d809988a8'),'callback_data'=>'showServerSettings'.$serverId.'_0']];
    return json_encode(['inline_keyboard'=>$keys],JSON_UNESCAPED_UNICODE);
}
function achbotServerDisplayQrLabel(string $mode): string {
    return ['auto'=>\achbotMessage('subscription.server_display_settings.server_display_qr_label.text.fe9c7660a2'),'subscription'=>\achbotMessage('subscription.server_display_settings.server_display_qr_label.text.140669bdb0'),'first_config'=>\achbotMessage('subscription.server_display_settings.server_display_qr_label.text.2dd7aa82b8'),'none'=>\achbotMessage('subscription.server_display_settings.server_display_qr_label.text.e5be3d69e3')][$mode]??\achbotMessage('subscription.server_display_settings.server_display_qr_label.text.fe9c7660a2');
}
function achbotServerDisplaySummary(array $document): string {
    $p=$document['policy'];$yes=static fn($v)=>$v?\achbotMessage('subscription.server_display_settings.server_display_keys.text.be9fd3bd1b'):\achbotMessage('subscription.server_display_settings.server_display_keys.text.841b8cb341');
    $body=\achbotMessage('subscription.server_display_settings.server_display_summary.body.d15b654a37').$yes($p['show_configs']??true).
        \achbotMessage('subscription.server_display_settings.server_display_summary.text.3623a042e1').$yes($p['show_subscription']??true).
        \achbotMessage('subscription.server_display_settings.server_display_summary.text.5f5512eac0').achbotServerDisplayQrLabel($p['qr']??'auto');
    if ($document['mode']==='native') {
        $selection=static function(string $key) use($p): string {
            if (!array_key_exists($key,$p)) $key='selectors';
            return !array_key_exists($key,$p)?\achbotMessage('subscription.server_display_settings.server_display_summary.text.704a2e9016'):
                ($p[$key]===[]?\achbotMessage('subscription.server_display_settings.server_display_summary.text.571525117a'):count($p[$key]).\achbotMessage('subscription.server_display_settings.server_display_summary.text.2496192b9b'));
        };
        if (array_key_exists('delivery_selectors',$p) || array_key_exists('view_selectors',$p)) {
            $body.="\n\n".\achbotMessage('admin_settings.display.delivery_selection',['selection'=>$selection('delivery_selectors')]).
                "\n".\achbotMessage('admin_settings.display.view_selection',['selection'=>$selection('view_selectors')]);
        } else $body.=\achbotMessage('subscription.server_display_settings.server_display_summary.body.8fc24cefa6').$selection('selectors');
        $body.=\achbotMessage('subscription.server_display_settings.server_display_summary.text.3305f8a04f').($p['max_links']??10).
            \achbotMessage('subscription.server_display_settings.server_display_summary.text.ae2383a123').($p['hide_if_more_than']??\achbotMessage('subscription.server_display_settings.server_display_summary.text.4e67bd8e34'));
    } else {
        $body.=\achbotMessage('subscription.server_display_settings.server_display_summary.body.f97693c9e9').
            implode(', ',$document['legacy']['showIndexes']??[]).
            \achbotMessage('subscription.server_display_settings.server_display_summary.text.9453bf36b1').implode(', ',$document['legacy']['linkIndices']??[]);
    }
    return $body.\achbotMessage('subscription.server_display_settings.server_display_summary.text.7724fb5e57');
}
function achbotServerDisplayShow(mysqli $db,int $serverId,string $prefix=''): void {
    $server=achbotServerDisplayServer($db,$serverId);
    $row=achbotServerDisplayRow($db,$serverId);
    try {
        $document=achbotServerDisplayDocument($row,$serverId,$server['type']);
        $keys=achbotServerDisplayKeys($serverId,$document,achbotServerDisplayRevision($row));
        // Native mode selector is useful only for Marzban. PG has one real mode.
        if ($server['type']!=='marzban') {
            $parsed=json_decode($keys,true);
            $parsed['inline_keyboard']=array_values(array_filter($parsed['inline_keyboard'],
                static fn($r)=>($r[0]['callback_data']??'')!=='serverDisplayMode'.$serverId));
            $keys=json_encode($parsed,JSON_UNESCAPED_UNICODE);
        }
        $body=achbotServerDisplaySummary($document);
    } catch (Throwable $error) {
        $body=\achbotMessage('subscription.server_display_settings.server_display_show.body.e2f9302913');
        $keys=achbotServerDisplayKeys($serverId);
    }
    sendMessage($prefix.\achbotMessage('admin_settings.management.display_context',['title'=>htmlspecialchars((string)$server['title'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')]).$body,$keys,'HTML');
}
function achbotServerDisplayPrompt(string $field): string {
    $contextFields=['dprotocols'=>'protocols','vprotocols'=>'protocols','dnames'=>'names','vnames'=>'names'];
    if (isset($contextFields[$field])) {
        $context=\achbotMessage($field[0]==='d'?'admin_settings.display.delivery_configs':'admin_settings.display.view_configs');
        return \achbotMessage('admin_settings.display.editing_context',['context'=>$context])."\n\n".
            achbotServerDisplayPrompt($contextFields[$field]);
    }
    return [
        'max'=>\achbotMessage('subscription.server_display_settings.server_display_prompt.text.9314449c88'),
        'threshold'=>\achbotMessage('subscription.server_display_settings.server_display_prompt.text.dc76b33303'),
        'protocols'=>\achbotMessage('subscription.server_display_settings.server_display_prompt.text.43b25df873'),
        'names'=>\achbotMessage('subscription.server_display_settings.server_display_prompt.text.44c7e97137'),
        'view'=>\achbotMessage('subscription.server_display_settings.server_display_prompt.text.4750c0b8eb'),
        'delivery'=>\achbotMessage('subscription.server_display_settings.server_display_prompt.text.4408da75e8'),
    ][$field]??throw new RuntimeException('display_field_invalid');
}
function achbotServerDisplayGuide(string $provider): string {
    $example=json_encode(achbotServerDisplayDefault(),JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
    $guide=\achbotMessage('subscription.server_display_settings.server_display_guide.guide.80a3795e36').
        \achbotMessage('subscription.server_display_settings.server_display_guide.mode_native_policy.4b6249e7e9').
        \achbotMessage('subscription.server_display_settings.server_display_guide.text.07b6a43417').
        \achbotMessage('subscription.server_display_settings.server_display_guide.text.effa069ca6').
        \achbotMessage('subscription.server_display_settings.server_display_guide.text.c45066e46f').
        \achbotMessage('subscription.server_display_settings.server_display_guide.text.37cb4134e8').
        \achbotMessage('subscription.server_display_settings.server_display_guide.text.700a492c3b').
        \achbotMessage('subscription.server_display_settings.server_display_guide.text.a585d74e4d').
        \achbotMessage('subscription.server_display_settings.server_display_guide.text.9cc423134a').\AchBot\Subscription\Presentation\escape($example)."</pre>";
    if ($provider==='marzban') {
        $legacy=['mode'=>'marzban_legacy','policy'=>new stdClass(),'legacy'=>[
            'showIndexes'=>[2,4],'linkIndices'=>[0,2],'hideConfigIfMany'=>false,'hideConnectLinkForMarzban'=>false]];
        $guide.=\achbotMessage('subscription.server_display_settings.server_display_guide.guide.bdbed004bb').
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
            if (strlen($data)>64) throw new RuntimeException('display_callback_invalid');
            $basic=preg_match('/^serverDisplay(Edit|Qr|SelectD|SelectV|Select|Mode)?([1-9][0-9]{0,9})$/D',$data,$m);
            $value=$set=false;
            if ($basic) {$action=$m[1];$sid=(int)$m[2];}
            elseif ($value=preg_match('/^serverDisplayValue([1-9][0-9]{0,9})_([a-z]{1,12})_(0|[1-9][0-9]*)$/D',$data,$m)) {
                $action='Value';$sid=(int)$m[1];$field=$m[2];$revision=(int)$m[3];
            } elseif ($set=preg_match('/^serverDisplaySet([1-9][0-9]{0,9})_([a-z]{1,12})_([a-z_]{1,14})_(0|[1-9][0-9]*)$/D',$data,$m)) {
                $action='Set';$sid=(int)$m[1];$field=$m[2];$choice=$m[3];$revision=(int)$m[4];
            } else throw new RuntimeException('display_callback_invalid');
            $source=achbotServerDisplayFrame($update,(int)$from_id,(int)$chat_id,(int)$message_id,$data,'callback');
            achbotServerDisplayActor($connection,(int)$from_id,(int)$admin);
            $server=achbotServerDisplayServer($connection,$sid);
            if ($action==='Edit' || $action==='Value') {
                $field=$action==='Edit'?'document':$field;
                $prompt=$field==='document'?achbotServerDisplayGuide($server['type']):achbotServerDisplayPrompt($field);
                achbotServerDisplayEdit($connection,$sid,(int)$from_id,(int)$admin,$source,'start',
                    null,$field,$action==='Edit'?null:$revision);
                if ($field==='document') sendMessage($prompt,$cancelKey,'HTML');
                else sendMessage($prompt,$cancelKey);
            } elseif ($action==='Set') {
                achbotServerDisplayEdit($connection,$sid,(int)$from_id,(int)$admin,$source,'set',$choice,$field,$revision);
                achbotServerDisplayShow($connection,$sid,\achbotMessage('subscription.server_display_settings.handle_server_display_admin.text.ed95738546'));
            } elseif (in_array($action,['Qr','Select','SelectD','SelectV','Mode'],true)) {
                $row=achbotServerDisplayRow($connection,$sid);
                $document=achbotServerDisplayDocument($row,$sid,$server['type']);
                $revision=achbotServerDisplayRevision($row);$keys=[];
                if ($action==='Qr') {
                    foreach (['auto','subscription','first_config','none'] as $choice)
                        $keys[]=[['text'=>achbotServerDisplayQrLabel($choice),
                            'callback_data'=>"serverDisplaySet{$sid}_qr_{$choice}_{$revision}"]];
                    $body=\achbotMessage('subscription.server_display_settings.handle_server_display_admin.body.97ea2ac3c4');
                } elseif ($action==='Select' && $document['mode']==='native') {
                    $keys=[
                        [['text'=>\achbotMessage('admin_settings.display.delivery_configs'),'callback_data'=>'serverDisplaySelectD'.$sid]],
                        [['text'=>\achbotMessage('admin_settings.display.view_configs'),'callback_data'=>'serverDisplaySelectV'.$sid]],
                    ];
                    $body=\achbotMessage('admin_settings.display.choose_context')."\n\n".\achbotMessage('admin_settings.display.context_guide');
                } elseif (in_array($action,['SelectD','SelectV'],true) && $document['mode']==='native') {
                    $prefix=$action==='SelectD'?'d':'v';
                    $keys=[
                        [['text'=>\achbotMessage('subscription.server_display_settings.handle_server_display_admin.button.ddeef82ae5'),'callback_data'=>"serverDisplayValue{$sid}_{$prefix}protocols_{$revision}"]],
                        [['text'=>\achbotMessage('subscription.server_display_settings.handle_server_display_admin.button.9397b410f0'),'callback_data'=>"serverDisplayValue{$sid}_{$prefix}names_{$revision}"]],
                        [['text'=>\achbotMessage('subscription.server_display_settings.server_display_summary.text.704a2e9016'),'callback_data'=>"serverDisplaySet{$sid}_{$prefix}select_all_{$revision}"],
                         ['text'=>\achbotMessage('subscription.server_display_settings.server_display_summary.text.571525117a'),'callback_data'=>"serverDisplaySet{$sid}_{$prefix}select_none_{$revision}"]],
                    ];
                    $context=\achbotMessage($prefix==='d'?'admin_settings.display.delivery_configs':'admin_settings.display.view_configs');
                    $body=\achbotMessage('admin_settings.display.editing_context',['context'=>$context])."\n\n".
                        \achbotMessage('subscription.server_display_settings.handle_server_display_admin.body.dc1a09d9ef');
                } elseif ($action==='Mode' && $server['type']==='marzban') {
                    foreach (['native'=>\achbotMessage('subscription.server_display_settings.handle_server_display_admin.text.50539a0548'),'marzban_legacy'=>\achbotMessage('subscription.server_display_settings.handle_server_display_admin.text.19adf0f6e6')] as $choice=>$label)
                        $keys[]=[['text'=>$label,'callback_data'=>"serverDisplaySet{$sid}_mode_{$choice}_{$revision}"]];
                    $body=\achbotMessage('subscription.server_display_settings.handle_server_display_admin.body.fccad7f96a');
                } else throw new RuntimeException('display_action_invalid');
                $keys[]=[['text'=>\achbotMessage('subscription.server_display_settings.handle_server_display_admin.button.63b4398cc3'),'callback_data'=>(in_array($action,['SelectD','SelectV'],true)?'serverDisplaySelect':'serverDisplay').$sid]];
                sendMessage($body,json_encode(['inline_keyboard'=>$keys],JSON_UNESCAPED_UNICODE));
            } else achbotServerDisplayShow($connection,$sid);
        } else {
            if (!preg_match('/^achbotDisplay\|([1-9][0-9]*)\|/D',$step,$m) || !is_string($text))
                throw new RuntimeException('display_step_invalid');
            $sid=(int)$m[1];
            $source=achbotServerDisplayFrame($update,(int)$from_id,(int)$chat_id,(int)$message_id,$text,'message');
            $kind=$text===($buttonValues['cancel']??null)?'cancel':'save';
            achbotServerDisplayEdit($connection,$sid,(int)$from_id,(int)$admin,$source,$kind,$text);
            sendMessage($kind==='save'?\achbotMessage('subscription.server_display_settings.handle_server_display_admin.text.ed95738546'):\achbotMessage('admin_settings.management.display_canceled'),
                $GLOBALS['removeKeyboard']??json_encode(['remove_keyboard'=>true]));
            if ($kind==='cancel' && function_exists('getServerConfigKeys'))
                sendMessage(\achbotMessage('admin_settings.management.server_list'),getServerConfigKeys($sid));
            else achbotServerDisplayShow($connection,$sid);
        }
    } catch (Throwable $error) {
        error_log('achbot_server_display_admin_deferred');
        sendMessage(\achbotMessage('subscription.server_display_settings.handle_server_display_admin.message.b57e3f0cb1'),$cancelKey);
    }
    return true;
}
