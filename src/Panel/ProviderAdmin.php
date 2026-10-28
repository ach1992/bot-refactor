<?php
/** Native PasarGuard administrative configuration. Loaded only after webhook authentication. */
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {http_response_code(404);exit;}
require_once __DIR__ . '/../../messages/loader.php'; // ACHBOT_TEXT_CATALOG

require_once __DIR__.'/ProviderRuntime.php';

function achbotPgAdminGroupIds(string $text,array $panel): array {
    if (preg_match('/^[1-9][0-9]*(?:\s*,\s*[1-9][0-9]*)*$/D',trim($text))!==1)
        throw new RuntimeException('pg_group_selection_invalid');
    $groups=achbotPgGroupsCanonical(array_map('intval',preg_split('/\s*,\s*/',trim($text))));
    $check=achbotPgCheck($panel);
    if (($check['success']??false)!==true) throw new RuntimeException('pg_setup_check_unverified');
    $eligible=[];
    foreach ($check['groups'] as $g)
        if (!$g['is_disabled']) $eligible[]=$g['id'];
    if (array_diff($groups,$eligible)) throw new RuntimeException('pg_groups_unavailable');
    if (is_array($check['allowed_group_ids']??null) && array_diff($groups,$check['allowed_group_ids']))
        throw new RuntimeException('pg_groups_not_permitted');
    return $groups;
}
function achbotPgServerAdminKeys(array $info,array $config,int $offset): string {
    global $buttonValues;
    $id=(int)$info['id'];
    return json_encode(['inline_keyboard'=>[
        [['text'=>$config['panel_url'],'callback_data'=>'wizwizch']],
        [['text'=>$info['title'],'callback_data'=>'editServerName'.$id],
         ['text'=>\achbotMessage('admin_settings.config.get_server_config_keys.button.2f6b3d0d80'),'callback_data'=>'wizwizch']],
        [['text'=>$info['flag'],'callback_data'=>'editServerFlag'.$id],
         ['text'=>\achbotMessage('admin_settings.config.get_server_config_keys.button.d004e8d654'),'callback_data'=>'wizwizch']],
        [['text'=>\achbotMessage('admin_settings.management.capacity',['value'=>$info['ucount']]),'callback_data'=>'editServerMax'.$id]],
        [['text'=>\achbotMessage('admin_settings.management.remark',['value'=>$info['remark']]),'callback_data'=>'editServerRemark'.$id]],
        [['text'=>\achbotMessage('admin_settings.management.groups'),'callback_data'=>'pgServerGroups'.$id]],
        [['text'=>\achbotMessage('admin_settings.management.shared_key'),'callback_data'=>'pgRotateServerKey'.$id]],
        [['text'=>\achbotMessage('admin_settings.management.display'),'callback_data'=>'serverDisplay'.$id]],
        [['text'=>\achbotMessage('admin_settings.management.plans'),'callback_data'=>'plansList'.$id],
         ['text'=>\achbotMessage('admin_settings.management.add_plan'),'callback_data'=>'newPlanForServer'.$id]],
        [['text'=>\achbotMessage('admin_settings.management.server_delete'),'callback_data'=>'wizwizdeleteserver'.$id]],
        [['text'=>$buttonValues['back_button'],'callback_data'=>'nextServerPage'.max(0,$offset)]]
    ]],JSON_UNESCAPED_UNICODE);
}
/** Return to the edited object; never let generic cancel process a stale step. */
function achbotPgAdminReturn(string $step,string $notice): void {
    global $removeKeyboard;
    sendMessage($notice,$removeKeyboard??json_encode(['remove_keyboard'=>true]));
    $sid=0;$plan=0;
    if (preg_match('/^pg:groups:(plan|server):([1-9][0-9]*)$/D',$step,$m)) {
        if ($m[1]==='plan') $plan=(int)$m[2];else $sid=(int)$m[2];
    } elseif (preg_match('/^pg:key:[1-9][0-9]*:([1-9][0-9]*)$/D',$step,$m)) $sid=(int)$m[1];
    if ($plan>0 && function_exists('getPlanDetailsKeys')) {
        sendMessage(\achbotMessage('admin_settings.management.plan_list'),getPlanDetailsKeys($plan));return;
    }
    if ($sid>0 && function_exists('getServerConfigKeys')) {
        sendMessage(\achbotMessage('admin_settings.management.server_list'),getServerConfigKeys($sid));return;
    }
    sendMessage(\achbotMessage('admin_settings.management.server_list'),getServerListKeys());
}
function achbotPgAdminFrame($update,int $actor,string $data,?string $text): void {
    if (!is_object($update) || !is_int($update->update_id??null) || $update->update_id<=0)
        throw new RuntimeException('pg_admin_frame_invalid');
    $callback=$data!==''?($update->callback_query??null):null;
    $message=$callback?($callback->message??null):($update->message??null);
    $sender=$callback?($callback->from??null):($message->from??null);
    if (!is_object($message) || ($message->chat->type??'')!=='private' ||
        (int)($message->chat->id??0)!==$actor || (int)($sender->id??0)!==$actor ||
        ($data!=='' && (!is_object($callback) || ($callback->data??null)!==$data)) ||
        ($data==='' && (!is_string($text) || ($message->text??null)!==$text)))
        throw new RuntimeException('pg_admin_private_required');
}
function achbotPgAdminActor(mysqli $db,string $step,bool $lock=false): void {
    global $from_id,$admin;
    $s=$db->prepare('SELECT step,isAdmin FROM users WHERE userid=?'.($lock?' FOR UPDATE':''));
    $actor=(int)$from_id;$s->bind_param('i',$actor);$s->execute();
    $current=$s->get_result()->fetch_assoc();$s->close();
    if (!$current || $current['step']!==$step ||
        ($actor!==(int)$admin && (int)$current['isAdmin']!==1))
        throw new RuntimeException('pg_admin_stale_or_denied');
}
function achbotPgAdminFinish(mysqli $db,string $step): void {
    global $from_id,$userInfo;
    $actor=(int)$from_id;
    $s=$db->prepare("UPDATE users SET step='none',temp=NULL WHERE userid=? AND step=?");
    $s->bind_param('is',$actor,$step);$s->execute();$s->close();
    if (!$db->commit()) throw new RuntimeException('pg_admin_commit_unknown');
    $userInfo['step']='none';$userInfo['temp']=null;
}
function achbotHandleProviderAdmin(): bool {
    global $connection,$from_id,$admin,$userInfo,$data,$text,$cancelKey,$update,$message_id,$chat_id;
    $step=(string)($userInfo['step']??'');
    $native=is_string($data??null) && preg_match('/^pg(?:Connections|AddConnection|NewServer|RotateServerKey|RotateKey|ServerGroups|PlanGroups|ResetAccount)/D',$data);
    if (!$native && !str_starts_with($step,'pg:')) return false;
    if (!((int)$from_id===(int)$admin || (int)($userInfo['isAdmin']??0)===1)) {
        alert(\achbotMessage('provider.provider_admin.handle_provider_admin.alert.057ec5448c'),true);return true;
    }
    try {
        achbotPgAdminFrame($update,(int)$from_id,(string)($data??''),is_string($text??null)?$text:null);
        achbotPgAdminActor($connection,$step);
        if ($step!=='none' && !str_starts_with($step,'pg:'))
            throw new RuntimeException('pg_admin_other_flow_active');
        // Cancel remains available even when optional provider schema is broken.
        if ($text===($GLOBALS['buttonValues']['cancel']??null)) {
            $msg=$update->message??null;
            if (!is_object($msg) || ($msg->chat->type??'')!=='private' ||
                (int)($msg->from->id??0)!==(int)$from_id ||
                (int)($msg->chat->id??0)!==(int)$from_id || ($msg->text??null)!==$text)
                throw new RuntimeException('pg_setup_private_message_required');
            if (!$connection->begin_transaction()) throw new RuntimeException('pg_cancel_begin');
            try {
                $s=$connection->prepare('SELECT step,isAdmin FROM users WHERE userid=? FOR UPDATE');
                $actor=(int)$from_id;$s->bind_param('i',$actor);$s->execute();
                $current=$s->get_result()->fetch_assoc();$s->close();
                if (!$current || $current['step']!==$step ||
                    ((int)$from_id!==(int)$admin && (int)$current['isAdmin']!==1))
                    throw new RuntimeException('pg_cancel_stale');
                $s=$connection->prepare("UPDATE users SET step='none',temp=NULL WHERE userid=? AND step=?");
                $s->bind_param('is',$actor,$step);$s->execute();$s->close();
                if (!$connection->commit()) throw new RuntimeException('pg_cancel_commit_unknown');
            } catch (Throwable $error) {try{$connection->rollback();}catch(Throwable $ignored){}throw $error;}
            $userInfo['step']='none';$userInfo['temp']=null;
            achbotPgAdminReturn($step,\achbotMessage('admin_settings.management.canceled'));return true;
        }
        achbotPgLoad();achbotProviderRequireSchema($connection);
        if (preg_match('/^pgResetAccount([1-9][0-9]*)$/D',(string)$data,$m)) {
            $ctx=achbotPgOrderContext($connection,(int)$m[1]);
            $r=achbotProviderAction((int)($ctx['order_server_id']??0),$ctx['account']['remote_username'],'reset');
            if (($r->success??false)!==true) throw new RuntimeException('pg_reset_requires_review');
            sendMessage(\achbotMessage('provider.provider_admin.handle_provider_admin.message.b3a3ec95dd'));return true;
        }
        if ($data==='pgConnections') {
            $rows=$connection->query("SELECT id,name FROM provider_panels WHERE provider='pasarguard' AND enabled=1 ORDER BY id");
            $keys=[];
            while ($row=$rows->fetch_assoc()) {
                $id=(int)$row['id'];
                $keys[]=[['text'=>$row['name'],'callback_data'=>'pgNewServer'.$id],
                    ['text'=>\achbotMessage('provider.provider_admin.handle_provider_admin.button.c672a35381'),'callback_data'=>'pgRotateKey'.$id]];
            }
            $keys[]=[['text'=>\achbotMessage('provider.provider_admin.handle_provider_admin.button.13bae1ab18'),'callback_data'=>'pgAddConnection']];
            $keys[]=[['text'=>\achbotMessage('provider.provider_admin.pg_server_admin_keys.button.b233883d0d'),'callback_data'=>'serversSetting']];
            sendMessage(\achbotMessage('provider.provider_admin.handle_provider_admin.message.601077bd6d'),
                json_encode(['inline_keyboard'=>$keys],JSON_UNESCAPED_UNICODE));return true;
        }
        if ($data==='pgAddConnection') {
            setUser('pg:connection');
            sendMessage(\achbotMessage('provider.provider_admin.handle_provider_admin.message.d0e9e358ec'),$cancelKey);return true;
        }
        if (preg_match('/^pg(NewServer|RotateServerKey|RotateKey|ServerGroups|PlanGroups)([1-9][0-9]*)$/D',(string)$data,$m)) {
            $id=(int)$m[2];$mode=$m[1];
            if ($mode==='RotateServerKey') {
                $panel=achbotPgServerPanel($connection,$id);
                setUser('pg:key:'.(int)$panel['id'].':'.$id);
                sendMessage(\achbotMessage('admin_settings.management.shared_key_notice'),$cancelKey);return true;
            }
            if ($mode==='NewServer') {
                $p=achbotProviderPanel($connection,$id);$r=achbotPgCheck($p);
                if (($r['success']??false)!==true) throw new RuntimeException('pg_setup_unverified');
                $names=[];foreach ($r['groups'] as $g) if (!$g['is_disabled']) $names[]=$g['id'].' = '.$g['name'];
                setUser('pg:server:'.$id);
                sendMessage(\achbotMessage('provider.provider_admin.handle_provider_admin.message.339107229c').implode("\n",$names),$cancelKey);return true;
            }
            if ($mode==='RotateKey') {achbotProviderPanel($connection,$id);setUser('pg:key:'.$id);sendMessage(\achbotMessage('provider.provider_admin.handle_provider_admin.message.bf4b28ea45'),$cancelKey);return true;}
            $sid=$id;
            if ($mode==='PlanGroups') {
                $s=$connection->prepare('SELECT server_id FROM server_plans WHERE id=?');$s->bind_param('i',$id);$s->execute();$sid=(int)($s->get_result()->fetch_assoc()['server_id']??0);$s->close();
            }
            $p=achbotPgServerPanel($connection,$sid);$r=achbotPgGroups($p);
            if (($r['success']??false)!==true) throw new RuntimeException('pg_groups_unverified');
            setUser('pg:groups:'.($mode==='PlanGroups'?'plan':'server').':'.$id);
            $names=[];foreach ($r['groups'] as $g) if (!$g['is_disabled']) $names[]=$g['id'].' = '.$g['name'];
            sendMessage(\achbotMessage('provider.provider_admin.handle_provider_admin.message.254f747510').implode("\n",$names),$cancelKey);return true;
        }
        // Configuration values and credentials are accepted only from a fresh private message.
        $msg=$update->message??null;
        if (!is_object($msg) || ($msg->chat->type??'')!=='private' ||
            (int)($msg->from->id??0)!==(int)$from_id || (int)($msg->chat->id??0)!==(int)$from_id ||
            !is_string($msg->text??null) || $msg->text!==$text) throw new RuntimeException('pg_setup_private_message_required');
        if ($step==='pg:connection') {
            $parts=explode("\n",trim($text));
            if (count($parts)!==3 || trim($parts[0])==='' || mb_strlen(trim($parts[0]))>100)
                throw new RuntimeException('pg_connection_input_invalid');
            $name=trim($parts[0]);$url=rtrim(trim($parts[1]),'/');$key=trim($parts[2]);
            $instance=substr(hash('sha256','pg:'.(int)$from_id.':'.(int)$update->update_id),0,32);
            $panel=['id'=>1,'instance_key'=>$instance,'api_url'=>$url,'api_key'=>$key,'version'=>'5.5.1'];
            $r=achbotPgCheck($panel);if (($r['success']??false)!==true) throw new RuntimeException('pg_connection_check_unverified');
            if (!$connection->begin_transaction()) throw new RuntimeException('pg_connection_begin');
            try {
                achbotPgAdminActor($connection,$step,true);
                $s=$connection->prepare("INSERT INTO provider_panels(provider,instance_key,name,api_url,api_key,version) VALUES('pasarguard',?,?,?,?, '5.5.1')");
                $s->bind_param('ssss',$instance,$name,$url,$key);
                if (!$s->execute() || $s->affected_rows!==1) throw new RuntimeException('pg_connection_save_unconfirmed');$s->close();
                achbotPgAdminFinish($connection,$step);
            } catch (Throwable $error) {try{$connection->rollback();}catch(Throwable $ignored){}throw $error;}
            sendMessage(\achbotMessage('provider.provider_admin.handle_provider_admin.message.84166ad032'),getServerListKeys());return true;
        }
        if (preg_match('/^pg:key:([1-9][0-9]*)(?::([1-9][0-9]*))?$/D',$step,$m)) {
            $id=(int)$m[1];$p=achbotProviderPanel($connection,$id);
            $before=$p['api_key'];$p['api_key']=trim($text);$r=achbotPgCheck($p);
            if (($r['success']??false)!==true) throw new RuntimeException('pg_key_check_unverified');
            if (!$connection->begin_transaction()) throw new RuntimeException('pg_key_begin');
            try {
                achbotPgAdminActor($connection,$step,true);
                $s=$connection->prepare("SELECT provider,instance_key,api_url,api_key,version,enabled FROM provider_panels WHERE id=? FOR UPDATE");
                $s->bind_param('i',$id);$s->execute();$locked=$s->get_result()->fetch_assoc();$s->close();
                if (!$locked || $locked['provider']!=='pasarguard' || (int)$locked['enabled']!==1 ||
                    $locked['instance_key']!==$p['instance_key'] || $locked['api_url']!==$p['api_url'] ||
                    $locked['version']!==$p['version'] || !hash_equals($before,$locked['api_key']))
                    throw new RuntimeException('pg_key_target_changed');
                $s=$connection->prepare("UPDATE provider_panels SET api_key=? WHERE id=? AND provider='pasarguard' AND BINARY api_key=BINARY ?");
                $s->bind_param('sis',$p['api_key'],$id,$before);
                if (!$s->execute() || ($s->affected_rows!==1 && $p['api_key']!==$before))
                    throw new RuntimeException('pg_key_save_unconfirmed');
                $s->close();achbotPgAdminFinish($connection,$step);
            } catch (Throwable $error) {try{$connection->rollback();}catch(Throwable $ignored){}throw $error;}
            achbotPgAdminReturn($step,\achbotMessage('provider.provider_admin.handle_provider_admin.message.7a19204ab5'));return true;
        }
        if (preg_match('/^pg:server:([1-9][0-9]*)$/D',$step,$m)) {
            $pid=(int)$m[1];$panel=achbotProviderPanel($connection,$pid);$parts=explode("\n",trim($text));
            if (count($parts)!==5 || trim($parts[0])==='' || mb_strlen($parts[0])>100 ||
                preg_match('/^[1-9][0-9]{0,8}$/D',trim($parts[1]))!==1 ||
                preg_match('/^[a-zA-Z0-9_-]{1,60}$/D',trim($parts[2]))!==1)
                throw new RuntimeException('pg_server_input_invalid');
            $title=trim($parts[0]);$count=(int)$parts[1];$remark=trim($parts[2]);$flag=trim($parts[3]);
            $groups=json_encode(achbotPgAdminGroupIds($parts[4],$panel),JSON_THROW_ON_ERROR);
            if (!$connection->begin_transaction()) throw new RuntimeException('pg_server_begin');
            try {
                achbotPgAdminActor($connection,$step,true);
                $s=$connection->prepare('INSERT INTO server_info(title,ucount,remark,flag,active,provider_panel_id,provider_group_ids) VALUES(?,?,?,?,1,?,?)');
                $s->bind_param('sissis',$title,$count,$remark,$flag,$pid,$groups);
                if (!$s->execute() || $s->affected_rows!==1) throw new RuntimeException('pg_server_insert_unconfirmed');
                $id=(int)$s->insert_id;$s->close();$url=$panel['api_url'];
                $s=$connection->prepare("INSERT INTO server_config(id,panel_url,ip,sni,header_type,request_header,response_header,security,tlsSettings,username,password,type) VALUES(?,?,'','','none','','','none','','','','pasarguard')");
                $s->bind_param('is',$id,$url);
                if (!$s->execute() || $s->affected_rows!==1) throw new RuntimeException('pg_server_config_unconfirmed');$s->close();
                achbotPgAdminFinish($connection,$step);
            } catch (Throwable $e) {try{$connection->rollback();}catch(Throwable $ignored){}throw $e;}
            sendMessage(\achbotMessage('provider.provider_admin.handle_provider_admin.message.230010849c'),getServerListKeys());return true;
        }
        if (preg_match('/^pg:groups:(plan|server):([1-9][0-9]*)$/D',$step,$m)) {
            $id=(int)$m[2];$sid=$id;
            if ($m[1]==='plan') {
                $s=$connection->prepare('SELECT server_id FROM server_plans WHERE id=?');$s->bind_param('i',$id);$s->execute();$sid=(int)($s->get_result()->fetch_assoc()['server_id']??0);$s->close();
            }
            $panel=achbotPgServerPanel($connection,$sid);
            $table=$m[1]==='plan'?'server_plans':'server_info';
            $s=$connection->prepare("SELECT provider_group_ids FROM $table WHERE id=?");
            $s->bind_param('i',$id);$s->execute();$beforeRow=$s->get_result()->fetch_assoc();$s->close();
            if (!$beforeRow) throw new RuntimeException('pg_group_target_missing');
            $beforeGroups=$beforeRow['provider_group_ids'];
            if ($m[1]==='plan' && $text==='/inherit') {
                $defaults=achbotPgPlanSnapshot($connection,$sid,0);
                achbotPgAdminGroupIds(implode(',',$defaults['group_ids']),$panel);
                $json=null;
            } else $json=json_encode(achbotPgAdminGroupIds($text,$panel),JSON_THROW_ON_ERROR);
            if (!$connection->begin_transaction()) throw new RuntimeException('pg_group_begin');
            try {
                achbotPgAdminActor($connection,$step,true);
                // Pin the edited row and the logical server before applying its selection.
                $fields=$m[1]==='plan'?'server_id,provider_group_ids':'provider_group_ids';
                $s=$connection->prepare("SELECT $fields FROM $table WHERE id=? FOR UPDATE");
                $s->bind_param('i',$id);$s->execute();$target=$s->get_result()->fetch_assoc();$s->close();
                if (!$target || $target['provider_group_ids']!==$beforeGroups ||
                    ($m[1]==='plan' && (int)$target['server_id']!==$sid))
                    throw new RuntimeException('pg_group_target_changed');
                $s=$connection->prepare('SELECT i.provider_panel_id,c.type,c.panel_url FROM server_info i JOIN server_config c ON c.id=i.id WHERE i.id=? FOR UPDATE');
                $s->bind_param('i',$sid);$s->execute();$logical=$s->get_result()->fetch_assoc();$s->close();
                if (!$logical || $logical['type']!=='pasarguard' ||
                    (int)$logical['provider_panel_id']!==(int)$panel['id'] ||
                    rtrim($logical['panel_url'],'/')!==rtrim($panel['api_url'],'/'))
                    throw new RuntimeException('pg_group_panel_changed');
                $fresh=achbotPgServerPanel($connection,$sid);
                if ($fresh['id']!==$panel['id'] || $fresh['instance_key']!==$panel['instance_key'] ||
                    $fresh['api_url']!==$panel['api_url'])
                    throw new RuntimeException('pg_group_panel_changed');
                $s=$connection->prepare("UPDATE $table SET provider_group_ids=? WHERE id=?");$s->bind_param('si',$json,$id);
                if (!$s->execute()) throw new RuntimeException('pg_group_save_unconfirmed');$s->close();
                achbotPgAdminFinish($connection,$step);
            } catch (Throwable $error) {try{$connection->rollback();}catch(Throwable $ignored){}throw $error;}
            achbotPgAdminReturn($step,\achbotMessage('provider.provider_admin.handle_provider_admin.message.2fb5824b4d'));return true;
        }
        throw new RuntimeException('pg_setup_step_invalid');
    } catch (Throwable $error) {
        error_log('achbot_pg_admin_action_deferred class='.get_class($error));
        sendMessage(\achbotMessage('provider.provider_admin.handle_provider_admin.message.6d701e69e5'),$cancelKey);return true;
    }
}
