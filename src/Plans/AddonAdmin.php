<?php
/** Scoped day/volume offer Telegram editor. Loaded only by authorized Intervals. */
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {http_response_code(404);exit;}
require_once __DIR__.'/Addons.php';

function achbotAddonAdminScopeLabel(array $scope): string {
    if ($scope['server_id']===null) return \achbotMessage('addon_scopes.scope_global');
    if ($scope['groups']===null)
        return \achbotMessage('addon_scopes.scope_server',['server'=>$scope['server_id']]);
    return \achbotMessage('addon_scopes.scope_groups',[
        'server'=>$scope['server_id'],'groups'=>implode(',',$scope['groups'])]);
}
function achbotAddonAdminFlow(): array {
    $temp=json_decode((string)($GLOBALS['userInfo']['temp']??''),true,16);
    $flow=is_array($temp)?($temp['flow']??null):null;
    if (!is_array($flow) || !in_array($flow['kind']??null,['day','volume'],true) ||
        !in_array($flow['action']??null,['create','edit'],true) ||
        !is_string($flow['session']??null) ||
        preg_match('/^[a-f0-9]{16}$/D',$flow['session'])!==1 ||
        ($flow['action']==='edit' && !is_array($flow['original']??null)))
        throw new RuntimeException('addon_admin_state_invalid');
    return $flow;
}
function achbotAddonAdminScopeKeys(string $session): string {
    return json_encode(['inline_keyboard'=>[
        [['text'=>\achbotMessage('addon_scopes.global'),'callback_data'=>'addonScopeGlobal_'.$session]],
        [['text'=>\achbotMessage('addon_scopes.server'),'callback_data'=>'addonScopeServer_'.$session]],
        [['text'=>\achbotMessage('addon_scopes.groups'),'callback_data'=>'addonScopeGroups_'.$session]]
    ]],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
}
/** Read-only catalog filters; the label still comes from the admin catalog. */
function achbotAddonAdminFilterKeys(string $kind): array {
    if (!in_array($kind,['day','volume'],true)) throw new InvalidArgumentException('addon_kind_invalid');
    $part=$kind==='day'?'Day':'Volume';
    return [[
        ['text'=>\achbotMessage('addon_scopes.filter_all'),'callback_data'=>"addonFilter{$part}All"],
        ['text'=>\achbotMessage('addon_scopes.global'),'callback_data'=>"addonFilter{$part}Global"],
        ['text'=>\achbotMessage('addon_scopes.server'),'callback_data'=>"addonFilter{$part}Servers"]
    ]];
}

function achbotAddonAdminServers(mysqli $db,string $scope): array {
    $where=$scope==='groups'?" AND c.type='pasarguard'":'';
    $rows=$db->query("SELECT i.id,i.title,c.type FROM server_info i INNER JOIN server_config c ON c.id=i.id
        WHERE i.state=1 AND c.type IN ('marzban','pasarguard')".$where." ORDER BY i.id");
    if (!$rows) throw new RuntimeException('addon_admin_servers_unavailable');
    $list=$rows->fetch_all(MYSQLI_ASSOC);$rows->free();return $list;
}
function achbotAddonAdminValidServer(mysqli $db,int $id,string $scope): bool {
    if ($id<=0) return false;
    $q=$db->prepare("SELECT c.type FROM server_info i INNER JOIN server_config c ON c.id=i.id
        WHERE i.id=? AND i.state=1");
    $q->bind_param('i',$id);$q->execute();$server=$q->get_result()->fetch_assoc();$q->close();
    return $server && ($scope==='groups'?
        $server['type']==='pasarguard':in_array($server['type'],['marzban','pasarguard'],true));
}
function achbotAddonAdminVerifyFlow(mysqli $db,array $flow): array {
    $scope=$flow['scope']??null;
    if (!in_array($scope,['global','server','groups'],true)) throw new RuntimeException('addon_admin_scope_invalid');
    if ($scope==='global') return [null,null,achbotAddonScopeHash(null,null)];
    $sid=$flow['server_id']??null;
    if (!is_int($sid) || !achbotAddonAdminValidServer($db,$sid,$scope))
        throw new RuntimeException('addon_admin_server_missing');
    if ($scope==='server') return [$sid,null,achbotAddonScopeHash($sid,null)];
    $groups=achbotAddonGroups($flow['groups']??[],true);
    if (count($groups)>64) throw new RuntimeException('addon_admin_groups_too_many');
    require_once __DIR__.'/../Panel/ProviderAdmin.php';
    $panel=achbotPgServerPanel($db,$sid);
    $verified=achbotPgAdminGroupIds(implode(',',$groups),$panel);
    if ($verified!==$groups) throw new RuntimeException('addon_admin_groups_changed');
    return [$sid,json_encode($groups,JSON_THROW_ON_ERROR),achbotAddonScopeHash($sid,$groups)];
}
function achbotAddonAdminSave(mysqli $db,array $flow,?int $units=null,?int $price=null): bool {
    [$sid,$groupJson,$hash]=achbotAddonAdminVerifyFlow($db,$flow);
    $table=achbotAddonTable($flow['kind']);
    if ($flow['action']==='create') {
        if ($units===null || $price===null) return false;
        $sql="INSERT INTO $table (volume,price,server_id,group_ids,scope_hash) VALUES (?,?,?,?,?)";
        $ok=achbotIntervalWrite($db,$sql,'iiiss',[$units,$price,$sid,$groupJson,$hash],true,null,null,true);
    } else {
        $original=$flow['original'];
        if (!is_int($original['id']??null) || $original['id']<=0 ||
            !is_string($original['scope_hash']??null) ||
            !is_int($original['volume']??null) || !is_int($original['price']??null)) return false;
        $sql="UPDATE $table SET server_id=?,group_ids=?,scope_hash=?
              WHERE id=? AND scope_hash=? AND volume=? AND price=?";
        $ok=achbotIntervalWrite($db,$sql,'issisii',[
            $sid,$groupJson,$hash,$original['id'],$original['scope_hash'],
            $original['volume'],$original['price']],true,null,null,true);
    }
    if (!$ok) return false;
    // The financial write has already committed; rendering must never turn
    // a confirmed save into a misleading "no change" failure notification.
    try {
        sendMessage(\achbotMessage('addon_scopes.offer_saved'),$GLOBALS['removeKeyboard']??null);
        achbotIntervalShow($db,$flow['kind']);
    } catch (Throwable $error) {
        error_log('achbot_addon_admin_postcommit_ui_deferred class='.get_class($error));
    }
    return true;
}
function achbotAddonAdminNext(mysqli $db,array $flow): void {
    global $cancelKey;
    if ($flow['action']==='edit') {
        if (!achbotAddonAdminSave($db,$flow)) achbotIntervalWriteNotice();
        return;
    }
    if (!achbotIntervalWrite($db,'','',[],false,'addon:units',$flow)) {
        achbotIntervalWriteNotice();return;
    }
    sendMessage(\achbotMessage('addon_scopes.units_price'),$cancelKey);
}
/** Handles scoped routes before the legacy handlers. All SQL writes go through
 * the same authenticated, monotonic Telegram-update transaction gate.
 */
function achbotAddonAdminHandle(mysqli $db): bool {
    global $data,$text,$from_id,$admin,$userInfo,$cancelKey;
    $cb=(string)($data??'');$step=(string)($userInfo['step']??'');
    $filterMatch=[];
    $filterOwned=preg_match('/^addonFilter(Day|Volume)(All|Global|Servers|Server[1-9][0-9]*)$/D',
        $cb,$filterMatch)===1;
    $owned=in_array($cb,['dayPlanSettings','volumePlanSettings','addNewDayPlan','addNewVolumePlan'],true) ||
        $filterOwned ||
        preg_match('/^addon(?:EditScope(?:Day|Volume)[1-9][0-9]*_[a-f0-9]{20}|Scope(?:Global|Server|Groups)_[a-f0-9]{16}|PickServer[1-9][0-9]*_[a-f0-9]{16})$/D',$cb) ||
        str_starts_with($step,'addon:');
    if (!$owned) return false;
    try {
        if (!achbotAddonSchema($db)) return false;
        if (!achbotAdminCanFor($db,(int)$from_id,(int)$admin,'plans'))
            throw new RuntimeException('addon_admin_denied');
        if ($filterOwned) {
            // Catalog navigation is read-only. A pending editor is ordinary
            // UX state, not an operational error or a failed financial write.
            if ($step!=='none') {
                sendMessage(\achbotMessage('addon_scopes.filter_blocked'),$cancelKey);
                return true;
            }
            $kind=$filterMatch[1]==='Day'?'day':'volume';
            $part=$filterMatch[1];$mode=$filterMatch[2];
            if ($mode==='All') {achbotIntervalShow($db,$kind);return true;}
            if ($mode==='Global') {achbotIntervalShow($db,$kind,'global');return true;}
            if ($mode==='Servers') {
                $list=achbotAddonAdminServers($db,'server');
                if (!$list) throw new RuntimeException('addon_admin_no_servers');
                $keys=[];
                foreach($list as $server)
                    $keys[]=[['text'=>(int)$server['id'].' - '.$server['title'].' ('.$server['type'].')',
                        'callback_data'=>"addonFilter{$part}Server".$server['id']]];
                $keys[]=[['text'=>\achbotMessage('addon_scopes.filter_all'),
                    'callback_data'=>"addonFilter{$part}All"]];
                sendMessage(\achbotMessage('addon_scopes.choose_server'),json_encode(
                    ['inline_keyboard'=>$keys],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
                return true;
            }
            if (!preg_match('/^Server([1-9][0-9]*)$/D',$mode,$m) ||
                !achbotAddonAdminValidServer($db,(int)$m[1],'server'))
                throw new RuntimeException('addon_admin_server_missing');
            achbotIntervalShow($db,$kind,'server',(int)$m[1]);return true;
        }
        if ($cb==='dayPlanSettings' || $cb==='volumePlanSettings') {
            if ($step!=='none') throw new RuntimeException('addon_admin_editor_in_progress');
            achbotIntervalShow($db,$cb==='dayPlanSettings'?'day':'volume');return true;
        }
        if ($cb==='addNewDayPlan' || $cb==='addNewVolumePlan' ||
            preg_match('/^addonEditScope(Day|Volume)([1-9][0-9]*)_([a-f0-9]{20})$/D',$cb,$edit)) {
            if ($step!=='none') throw new RuntimeException('addon_admin_other_editor_active');
            $kind=($cb==='addNewDayPlan' || ($edit[1]??null)==='Day')?'day':'volume';
            $flow=['action'=>isset($edit[2])?'edit':'create','kind'=>$kind,
                'session'=>bin2hex(random_bytes(8))];
            if ($flow['action']==='edit') {
                $table=achbotAddonTable($kind);$id=(int)$edit[2];
                $q=$db->prepare("SELECT * FROM $table WHERE id=?");
                $q->bind_param('i',$id);$q->execute();$row=$q->get_result()->fetch_assoc();$q->close();
                if (!$row) throw new RuntimeException('addon_admin_offer_missing');
                if (!hash_equals(achbotAddonOfferRevision($kind,$row),$edit[3]))
                    throw new RuntimeException('addon_admin_stale_callback');
                $scope=achbotAddonScopeRow($row,true);
                $units=achbotWholeServiceUnits($row['volume'],$kind==='day'?36500:achbotServiceVolumeMax());
                if ($units===null || (int)$row['price']<0) throw new RuntimeException('addon_admin_offer_invalid');
                $flow['original']=['id'=>$id,'scope_hash'=>$scope['scope_hash'],
                    'volume'=>$units,'price'=>(int)$row['price']];
            }
            if (!achbotIntervalWrite($db,'','',[],false,'addon:scope',$flow)) {
                achbotIntervalWriteNotice();return true;
            }
            sendMessage(\achbotMessage('addon_scopes.choose_scope'),achbotAddonAdminScopeKeys($flow['session']));
            return true;
        }
        $flow=achbotAddonAdminFlow();
        if (preg_match('/^addonScope(Global|Server|Groups)_([a-f0-9]{16})$/D',$cb,$m)) {
            if ($step!=='addon:scope' || !hash_equals($flow['session'],$m[2]))
                throw new RuntimeException('addon_admin_stale_callback');
            $flow['scope']=strtolower($m[1]);
            $flow['server_id']=null;$flow['groups']=null;
            if ($flow['scope']==='global') achbotAddonAdminNext($db,$flow);
            else {
                $list=achbotAddonAdminServers($db,$flow['scope']);
                if (!$list) throw new RuntimeException('addon_admin_no_servers');
                if (!achbotIntervalWrite($db,'','',[],false,'addon:server',$flow)) {
                    achbotIntervalWriteNotice();return true;
                }
                $keys=[];
                foreach($list as $server)
                    $keys[]=[['text'=>(int)$server['id'].' - '.$server['title'].' ('.$server['type'].')',
                        'callback_data'=>'addonPickServer'.$server['id'].'_'.$flow['session']]];
                sendMessage(\achbotMessage('addon_scopes.choose_server'),json_encode(
                    ['inline_keyboard'=>$keys],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
            }
            return true;
        }
        if (preg_match('/^addonPickServer([1-9][0-9]*)_([a-f0-9]{16})$/D',$cb,$m)) {
            if ($step!=='addon:server' || !hash_equals($flow['session'],$m[2]))
                throw new RuntimeException('addon_admin_stale_callback');
            $sid=(int)$m[1];
            if (!achbotAddonAdminValidServer($db,$sid,$flow['scope']??'')) throw new RuntimeException('addon_admin_server_missing');
            $flow['server_id']=$sid;
            if ($flow['scope']==='server') achbotAddonAdminNext($db,$flow);
            elseif ($flow['scope']==='groups') {
                require_once __DIR__.'/../Panel/ProviderAdmin.php';
                $panel=achbotPgServerPanel($db,$sid);$result=achbotPgCheck($panel);
                if (($result['success']??false)!==true) throw new RuntimeException('addon_admin_groups_unverified');
                $eligible=achbotPgEligibleGroupIds($result);
                $list=[];foreach($result['groups'] as $g)
                    if (in_array($g['id'],$eligible,true))
                        $list[]=$g['id'].' = '.$g['name'];
                if (!$list) throw new RuntimeException('addon_admin_no_groups');
                if (!achbotIntervalWrite($db,'','',[],false,'addon:groups',$flow)) {
                    achbotIntervalWriteNotice();return true;
                }
                $notice=\achbotMessage('addon_scopes.choose_groups');
                // Telegram caps message length; accepting IDs is not limited to visible previews.
                sendMessage($notice."\n".mb_substr(implode("\n",$list),0,2400),$cancelKey);
            } else throw new RuntimeException('addon_admin_scope_invalid');
            return true;
        }
        if ($cb!=='' || !is_string($text)) throw new RuntimeException('addon_admin_stale_callback');
        if ($step==='addon:groups') {
            if ($flow['scope']!=='groups' || !is_int($flow['server_id']??null))
                throw new RuntimeException('addon_admin_state_invalid');
            require_once __DIR__.'/../Panel/ProviderAdmin.php';
            $panel=achbotPgServerPanel($db,$flow['server_id']);
            $flow['groups']=achbotPgAdminGroupIds(trim($text),$panel);
            achbotAddonAdminNext($db,$flow);
            return true;
        }
        if ($step==='addon:units') {
            $input=explode('-',trim($text));
            if (count($input)!==2) throw new RuntimeException('addon_admin_units_invalid');
            $units=achbotWholeServiceUnits($input[0],$flow['kind']==='day'?36500:achbotServiceVolumeMax());
            $price=achbotIntervalPrice($input[1]);
            if ($units===null || $price===null || $flow['action']!=='create')
                throw new RuntimeException('addon_admin_units_invalid');
            if (!achbotAddonAdminSave($db,$flow,$units,$price)) achbotIntervalWriteNotice();
            return true;
        }
        throw new RuntimeException('addon_admin_step_invalid');
    } catch(Throwable $error) {
        $reason=$error instanceof RuntimeException &&
            preg_match('/^addon_[a-z_]+$/D',$error->getMessage())===1
            ?$error->getMessage():get_class($error);
        // A prior Telegram prompt clicked after this editor advanced is
        // normal user navigation, not a failed database write. Avoid noisy
        // operational errors or a false financial failure report.
        if ($reason==='addon_admin_stale_callback') {
            sendMessage(\achbotMessage($step==='none'
                ?'addon_scopes.unavailable':'addon_scopes.filter_blocked'),$cancelKey);
            return true;
        }
        $update=$GLOBALS['update']??null;
        error_log('achbot_addon_admin_deferred reason='.$reason.
            ' actor='.(int)$from_id.' update='.(int)($update->update_id??0).' step='.$step);
        sendMessage(\achbotMessage('addon_scopes.unavailable'),$cancelKey);
        return true;
    }
}
