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
    $id=(int)$info['id'];
    return json_encode(['inline_keyboard'=>[
        [['text'=>\achbotMessage('provider.provider_admin.pg_server_admin_keys.pasar_guard.c8a922f551'),'callback_data'=>'wizwizch']],
        [['text'=>$info['title'],'callback_data'=>'editServerName'.$id]],
        [['text'=>\achbotMessage('provider.provider_admin.pg_server_admin_keys.button.93b4ef5cbb').$info['ucount'],'callback_data'=>'editServerMax'.$id]],
        [['text'=>\achbotMessage('provider.provider_admin.pg_server_admin_keys.button.9dee35a276').$info['remark'],'callback_data'=>'editServerRemark'.$id]],
        [['text'=>\achbotMessage('provider.provider_admin.pg_server_admin_keys.button.c84bd01998'),'callback_data'=>'pgServerGroups'.$id]],
        [['text'=>\achbotMessage('provider.provider_admin.pg_server_admin_keys.button.e2e7e28899'),'callback_data'=>'pgConnections']],
        [['text'=>\achbotMessage('provider.provider_admin.pg_server_admin_keys.button.b233883d0d'),'callback_data'=>'nextServerPage'.$offset]]
    ]],JSON_UNESCAPED_UNICODE);
}
function achbotHandleProviderAdmin(): bool {
    global $connection,$from_id,$admin,$userInfo,$data,$text,$cancelKey,$update,$message_id,$chat_id;
    $step=(string)($userInfo['step']??'');
    $native=is_string($data??null) && preg_match('/^pg(?:Connections|AddConnection|NewServer|RotateKey|ServerGroups|PlanGroups|ResetAccount)/D',$data);
    if (!$native && !str_starts_with($step,'pg:')) return false;
    if (!((int)$from_id===(int)$admin || (int)($userInfo['isAdmin']??0)===1)) {
        alert(\achbotMessage('provider.provider_admin.handle_provider_admin.alert.057ec5448c'),true);return true;
    }
    try {
        achbotPgLoad();achbotProviderRequireSchema($connection);
        if ($text===($GLOBALS['buttonValues']['cancel']??null)) {setUser();return false;}
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
        if (preg_match('/^pg(NewServer|RotateKey|ServerGroups|PlanGroups)([1-9][0-9]*)$/D',(string)$data,$m)) {
            $id=(int)$m[2];$mode=$m[1];
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
            delMessage($message_id,$chat_id);
            $parts=explode("\n",trim($text));
            if (count($parts)!==3 || trim($parts[0])==='' || mb_strlen(trim($parts[0]))>100)
                throw new RuntimeException('pg_connection_input_invalid');
            $name=trim($parts[0]);$url=rtrim(trim($parts[1]),'/');$key=trim($parts[2]);
            $instance=substr(hash('sha256','pg:'.(int)$from_id.':'.(int)$update->update_id),0,32);
            $panel=['id'=>1,'instance_key'=>$instance,'api_url'=>$url,'api_key'=>$key,'version'=>'5.5.1'];
            $r=achbotPgCheck($panel);if (($r['success']??false)!==true) throw new RuntimeException('pg_connection_check_unverified');
            $s=$connection->prepare("INSERT INTO provider_panels(provider,instance_key,name,api_url,api_key,version) VALUES('pasarguard',?,?,?,?, '5.5.1')");
            $s->bind_param('ssss',$instance,$name,$url,$key);
            if (!$s->execute() || $s->affected_rows!==1) throw new RuntimeException('pg_connection_save_unconfirmed');$s->close();
            setUser();sendMessage(\achbotMessage('provider.provider_admin.handle_provider_admin.message.84166ad032'),getServerListKeys());return true;
        }
        if (preg_match('/^pg:key:([1-9][0-9]*)$/D',$step,$m)) {
            delMessage($message_id,$chat_id);$id=(int)$m[1];$p=achbotProviderPanel($connection,$id);
            $p['api_key']=trim($text);$r=achbotPgCheck($p);
            if (($r['success']??false)!==true) throw new RuntimeException('pg_key_check_unverified');
            $s=$connection->prepare("UPDATE provider_panels SET api_key=? WHERE id=? AND provider='pasarguard'");
            $s->bind_param('si',$p['api_key'],$id);
            if (!$s->execute() || $s->affected_rows!==1) throw new RuntimeException('pg_key_save_unconfirmed');$s->close();
            setUser();sendMessage(\achbotMessage('provider.provider_admin.handle_provider_admin.message.7a19204ab5'),getServerListKeys());return true;
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
                $s=$connection->prepare('INSERT INTO server_info(title,ucount,remark,flag,active,provider_panel_id,provider_group_ids) VALUES(?,?,?,?,1,?,?)');
                $s->bind_param('sissis',$title,$count,$remark,$flag,$pid,$groups);
                if (!$s->execute() || $s->affected_rows!==1) throw new RuntimeException('pg_server_insert_unconfirmed');
                $id=(int)$s->insert_id;$s->close();$url=$panel['api_url'];
                $s=$connection->prepare("INSERT INTO server_config(id,panel_url,type) VALUES(?,?,'pasarguard')");
                $s->bind_param('is',$id,$url);
                if (!$s->execute() || $s->affected_rows!==1) throw new RuntimeException('pg_server_config_unconfirmed');$s->close();
                if (!$connection->commit()) throw new RuntimeException('pg_server_commit_unknown');
            } catch (Throwable $e) {try{$connection->rollback();}catch(Throwable $ignored){}throw $e;}
            setUser();sendMessage(\achbotMessage('provider.provider_admin.handle_provider_admin.message.230010849c'),getServerListKeys());return true;
        }
        if (preg_match('/^pg:groups:(plan|server):([1-9][0-9]*)$/D',$step,$m)) {
            $id=(int)$m[2];$sid=$id;
            if ($m[1]==='plan') {
                $s=$connection->prepare('SELECT server_id FROM server_plans WHERE id=?');$s->bind_param('i',$id);$s->execute();$sid=(int)($s->get_result()->fetch_assoc()['server_id']??0);$s->close();
            }
            $panel=achbotPgServerPanel($connection,$sid);
            $json=$m[1]==='plan' && $text==='/inherit'?null:json_encode(achbotPgAdminGroupIds($text,$panel),JSON_THROW_ON_ERROR);
            $table=$m[1]==='plan'?'server_plans':'server_info';
            $s=$connection->prepare("UPDATE $table SET provider_group_ids=? WHERE id=?");$s->bind_param('si',$json,$id);
            if (!$s->execute()) throw new RuntimeException('pg_group_save_unconfirmed');$s->close();
            setUser();sendMessage(\achbotMessage('provider.provider_admin.handle_provider_admin.message.2fb5824b4d'),getServerListKeys());return true;
        }
        throw new RuntimeException('pg_setup_step_invalid');
    } catch (Throwable $error) {
        error_log('achbot_pg_admin_action_deferred class='.get_class($error));
        sendMessage(\achbotMessage('provider.provider_admin.handle_provider_admin.message.6d701e69e5'),$cancelKey);return true;
    }
}
