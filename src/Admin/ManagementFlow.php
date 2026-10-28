<?php
/** AChBot actor-owned management drafts; no global inactive server/plan rows. */
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {http_response_code(404);exit;}
require_once __DIR__.'/../../messages/loader.php';
require_once __DIR__.'/ServerWizard.php';
require_once __DIR__.'/../Plans/Wizard.php';

function achbotManagementSql(mysqli $db,string $sql,string $types='',array $args=[]): mysqli_stmt {
    $s=$db->prepare($sql);
    if (!$s || ($types!=='' && !$s->bind_param($types,...$args)) || !$s->execute())
        throw new RuntimeException('management_storage');
    return $s;
}
function achbotManagementRow(mysqli $db,string $sql,string $types='',array $args=[]): ?array {
    $s=achbotManagementSql($db,$sql,$types,$args);
    try {return $s->get_result()->fetch_assoc();} finally {$s->close();}
}
function achbotManagementRows(mysqli $db,string $sql,string $types='',array $args=[]): array {
    $s=achbotManagementSql($db,$sql,$types,$args);
    try {return $s->get_result()->fetch_all(MYSQLI_ASSOC);} finally {$s->close();}
}
function achbotManagementText(string $key,array $params=[]): string {
    return match($key) {
        'intro'=>achbotMessage('admin_settings.management.intro',$params),
        'back'=>achbotMessage('admin_settings.management.back',$params),
        'cancel'=>achbotMessage('admin_settings.management.cancel',$params),
        'canceled'=>achbotMessage('admin_settings.management.canceled',$params),
        'server_list'=>achbotMessage('admin_settings.management.server_list',$params),
        'plan_list'=>achbotMessage('admin_settings.management.plan_list',$params),
        'open_plans'=>achbotMessage('admin_settings.management.open_plans',$params),
        'server_saved'=>achbotMessage('admin_settings.management.server_saved',$params),
        'plan_saved'=>achbotMessage('admin_settings.management.plan_saved',$params),
        'management_input'=>achbotMessage('admin_settings.management.management_input',$params),
        'management_connection'=>achbotMessage('admin_settings.management.management_connection',$params),
        'management_targets'=>achbotMessage('admin_settings.management.management_targets',$params),
        'management_stale'=>achbotMessage('admin_settings.management.management_stale',$params),
        'management_other_flow'=>achbotMessage('admin_settings.management.management_other_flow',$params),
        'management_other_action'=>achbotMessage('admin_settings.management.management_other_action',$params),
        'management_admin_required'=>achbotMessage('admin_settings.management.management_admin_required',$params),
        'management_unavailable'=>achbotMessage('admin_settings.management.management_unavailable',$params),
        'review_required'=>achbotMessage('admin_settings.management.review_required',$params),
        'restart'=>achbotMessage('admin_settings.management.restart',$params),
        'marzban'=>achbotMessage('admin_settings.management.marzban',$params),
        'pasarguard'=>achbotMessage('admin_settings.management.pasarguard',$params),
        'previous_page'=>achbotMessage('admin_settings.management.previous_page',$params),
        'next_page'=>achbotMessage('admin_settings.management.next_page',$params),
        'selected'=>achbotMessage('admin_settings.management.selected',$params),
        'selection_done'=>achbotMessage('admin_settings.management.selection_done',$params),
        'reuse_connection'=>achbotMessage('admin_settings.management.reuse_connection',$params),
        'no_shared_group'=>achbotMessage('admin_settings.management.no_shared_group',$params),
        'inherit_groups'=>achbotMessage('admin_settings.management.inherit_groups',$params),
        'server_provider'=>achbotMessage('admin_settings.management.server_provider',$params),
        'server_title'=>achbotMessage('admin_settings.management.server_title',$params),
        'server_capacity'=>achbotMessage('admin_settings.management.server_capacity',$params),
        'server_remark'=>achbotMessage('admin_settings.management.server_remark',$params),
        'server_flag'=>achbotMessage('admin_settings.management.server_flag',$params),
        'server_url'=>achbotMessage('admin_settings.management.server_url',$params),
        'server_username'=>achbotMessage('admin_settings.management.server_username',$params),
        'server_password'=>achbotMessage('admin_settings.management.server_password',$params),
        'server_api_key'=>achbotMessage('admin_settings.management.server_api_key',$params),
        'server_panelgroup'=>achbotMessage('admin_settings.management.server_panelgroup',$params),
        'server_groups'=>achbotMessage('admin_settings.management.server_groups',$params),
        'server_review'=>achbotMessage('admin_settings.management.server_review',$params),
        'confirm_server'=>achbotMessage('admin_settings.management.confirm_server',$params),
        'confirm_plan'=>achbotMessage('admin_settings.management.confirm_plan',$params),
        'plan_title'=>achbotMessage('admin_settings.management.plan_title',$params),
        'plan_price'=>achbotMessage('admin_settings.management.plan_price',$params),
        'plan_category'=>achbotMessage('admin_settings.management.plan_category',$params),
        'plan_server'=>achbotMessage('admin_settings.management.plan_server',$params),
        'plan_days'=>achbotMessage('admin_settings.management.plan_days',$params),
        'plan_volume'=>achbotMessage('admin_settings.management.plan_volume',$params),
        'plan_description'=>achbotMessage('admin_settings.management.plan_description',$params),
        'no_categories'=>achbotMessage('admin_settings.management.no_categories',$params),
        'no_servers'=>achbotMessage('admin_settings.management.no_servers',$params),
        'plan_groups'=>achbotMessage('admin_settings.management.plan_groups',$params),
        'plan_inbounds'=>achbotMessage('admin_settings.management.plan_inbounds',$params),
        'plan_review'=>achbotMessage('admin_settings.management.plan_review',$params),
        'add_server'=>achbotMessage('admin_settings.management.add_server',$params),
        'add_plan'=>achbotMessage('admin_settings.management.add_plan',$params),
        'display'=>achbotMessage('admin_settings.management.display',$params),
        'plans'=>achbotMessage('admin_settings.management.plans',$params),
        'connection'=>achbotMessage('admin_settings.management.connection',$params),
        'groups'=>achbotMessage('admin_settings.management.groups',$params),
        'server_flag_label'=>achbotMessage('admin_settings.management.server_flag_label',$params),
        'server_delete'=>achbotMessage('admin_settings.management.server_delete',$params),
        'server_back'=>achbotMessage('admin_settings.management.server_back',$params),
        'capacity'=>achbotMessage('admin_settings.management.capacity',$params),
        'remark'=>achbotMessage('admin_settings.management.remark',$params),
        'plan_days_label'=>achbotMessage('admin_settings.management.plan_days_label',$params),
        'plan_volume_label'=>achbotMessage('admin_settings.management.plan_volume_label',$params),
        'plan_days_prompt'=>achbotMessage('admin_settings.management.plan_days_prompt',$params),
        'plan_volume_prompt'=>achbotMessage('admin_settings.management.plan_volume_prompt',$params),
        'plan_updated'=>achbotMessage('admin_settings.management.plan_updated',$params),
        'display_context'=>achbotMessage('admin_settings.management.display_context',$params),
        'display_canceled'=>achbotMessage('admin_settings.management.display_canceled',$params),
        'shared_key'=>achbotMessage('admin_settings.management.shared_key',$params),
        'shared_key_notice'=>achbotMessage('admin_settings.management.shared_key_notice',$params),
        'server_referenced'=>achbotMessage('admin_settings.management.server_referenced',$params),
        'server_removed'=>achbotMessage('admin_settings.management.server_removed',$params),
        'settings_saved'=>achbotMessage('admin_settings.management.settings_saved',$params),
        'server_delete_confirm'=>achbotMessage('admin_settings.management.server_delete_confirm',$params),
        'server_choice'=>achbotMessage('admin_settings.management.server_choice',$params),
        'selection_summary'=>achbotMessage('admin_settings.management.selection_summary',$params),
        default=>throw new RuntimeException('management_copy_missing')
    };
}
function achbotManagementEscape($value): string {
    return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
}
function achbotManagementSelectionSummary(array $labels): string {
    $sample=array_map(static fn($s)=>mb_substr((string)$s,0,64),array_slice($labels,0,8));
    return achbotManagementText('selection_summary',['count'=>(string)count($labels),'items'=>implode(', ',$sample)]);
}
function achbotManagementButton(array $draft,string $action,string $value='0'): string {
    $data='am|'.$draft['nonce'].'|'.$draft['rev'].'|'.$action.'|'.$value;
    if (strlen($data)>64) throw new RuntimeException('management_callback_length');
    return $data;
}
function achbotManagementFrame($update,int $actor,string $data,?string $text): int {
    if (!is_object($update) || !is_int($update->update_id??null) || $update->update_id<=0)
        throw new RuntimeException('management_frame');
    $q=$data!==''?($update->callback_query??null):null;
    $m=$q?($q->message??null):($update->message??null);
    $sender=$q?($q->from??null):($m->from??null);
    if (!is_object($m) || ($m->chat->type??'')!=='private' ||
        (int)($m->chat->id??0)!==$actor || (int)($sender->id??0)!==$actor ||
        !is_int($m->message_id??null) || $m->message_id<=0 ||
        ($q && ($q->data??null)!==$data) ||
        (!$q && (!is_string($text) || ($m->text??null)!==$text)))
        throw new RuntimeException('management_private_required');
    return $update->update_id;
}
function achbotManagementDraft(array $user): ?array {
    if (!str_starts_with((string)$user['step'],'achbotManage|')) return null;
    $d=json_decode((string)$user['temp'],true);
    if (!is_array($d) || ($d['version']??null)!==1 ||
        !in_array($d['kind']??null,['server','plan'],true) ||
        preg_match('/^[a-f0-9]{12}$/D',(string)($d['nonce']??''))!==1 ||
        !is_int($d['rev']??null) || !is_int($d['source']??null) ||
        !is_array($d['values']??null) || !is_string($d['stage']??null) ||
        $user['step']!=='achbotManage|'.$d['nonce'].'|'.$d['rev'])
        throw new RuntimeException('management_draft_invalid');
    return $d;
}
/** Resolve exactly one persisted actor; userid is deliberately NOT a unique key. */
function achbotManagementActor(mysqli $db,int $actor,int $primary,bool $lock=true): array {
    $rows=achbotManagementRows($db,'SELECT id,userid,isAdmin,step,temp FROM users FORCE INDEX (idx_users_userid_v1) WHERE userid=? LIMIT 2'.($lock?' FOR UPDATE':''),'i',[$actor]);
    if (count($rows)!==1 || (int)$rows[0]['id']<=0 || (int)$rows[0]['userid']!==$actor ||
        ($actor!==$primary && (int)$rows[0]['isAdmin']!==1))
        throw new RuntimeException('management_admin_required');
    return $rows[0];
}
function achbotManagementWriteActorState(mysqli $db,int $actor,int $actorRowId,string $step,?string $temp): void {
    // Recheck even under READ COMMITTED before a write, and target the exact
    // validated primary row rather than every historical userid duplicate.
    $user=achbotManagementActor($db,$actor,(int)($GLOBALS['admin']??0));
    if ((int)$user['id']!==$actorRowId) throw new RuntimeException('management_admin_required');
    $s=achbotManagementSql($db,'UPDATE users SET step=?,temp=? WHERE id=? AND userid=?','ssii',[$step,$temp,$actorRowId,$actor]);
    $changed=$s->affected_rows;$s->close();
    if ($changed!==1) throw new RuntimeException('management_storage');
    $GLOBALS['userInfo']['id']=$actorRowId;$GLOBALS['userInfo']['userid']=$actor;
    $GLOBALS['userInfo']['step']=$step;$GLOBALS['userInfo']['temp']=$temp;
}
function achbotManagementStore(mysqli $db,int $actor,int $actorRowId,?array $draft): void {
    $step=$draft===null?'none':'achbotManage|'.$draft['nonce'].'|'.$draft['rev'];
    $temp=$draft===null?null:json_encode($draft,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    achbotManagementWriteActorState($db,$actor,$actorRowId,$step,$temp);
}
function achbotManagementRender(mysqli $db,array $draft,string $prefix='',?int $messageId=null): void {
    [$body,$keys]=$draft['kind']==='server'?achbotServerWizardView($db,$draft):achbotPlanWizardView($db,$draft);
    if ($draft['stage']!=='provider') $keys[]=[[
        'text'=>achbotManagementText('back'),'callback_data'=>achbotManagementButton($draft,'back')]];
    $keys[]=[['text'=>achbotManagementText('cancel'),'callback_data'=>achbotManagementButton($draft,'cancel')]];
    $markup=json_encode(['inline_keyboard'=>$keys],JSON_UNESCAPED_UNICODE);
    if ($messageId!==null) {
        editText($messageId,$prefix.$body,$markup,'HTML');
        return;
    }
    sendMessage($prefix.$body,$markup,'HTML');
}
function achbotManagementHome(string $kind,?int $id=null,string $prefix=''): void {
    global $removeKeyboard;
    sendMessage($prefix!==''?$prefix:achbotManagementText('canceled'),$removeKeyboard??json_encode(['remove_keyboard'=>true]));
    if ($kind==='server') sendMessage(achbotManagementText('server_list'),$id?getServerConfigKeys($id):getServerListKeys(),'HTML');
    else sendMessage(achbotManagementText('plan_list'),$id?getPlanDetailsKeys($id):
        json_encode(['inline_keyboard'=>[[['text'=>achbotManagementText('open_plans'),'callback_data'=>'backplan']]]]),'HTML');
}
/** Own one request completely, including cancel; callers never replay it through generic cancel. */
function achbotHandleManagement(): bool {
    global $connection,$from_id,$admin,$data,$text,$update,$userInfo,$buttonValues,$cancelKey,$message_id,$chat_id;
    $data=is_string($data??null)?$data:'';
    $text=is_string($text??null)?$text:null;
    $starts=in_array($data,['addManagedServer','addNewMarzbanPanel','pgConnections','pgAddConnection',
        'addManagedPlan','addNewMarzbanPlan'],true) ||
        preg_match('/^(?:pgNewServer|newPlanForServer)([1-9][0-9]*)$/D',$data);
    $owned=str_starts_with((string)($userInfo['step']??''),'achbotManage|');
    $callback=str_starts_with($data,'am|');
    $legacy=preg_match('/^(?:addserverName$|addServer(?:UCount|Remark|Flag|PanelUrl|PanelGroup|PanelUser|PanePassword)|addNewMarzbanPlan$)/D',(string)($userInfo['step']??''))===1;
    if (!$starts && !$owned && !$callback && !$legacy) return false;
    $draft=null;$begun=false;$canRecover=false;
    try {
        $actor=(int)$from_id;$source=achbotManagementFrame($update,$actor,$data,$text);
        if (!$connection->begin_transaction()) throw new RuntimeException('management_begin');
        $begun=true;
        $user=achbotManagementActor($connection,$actor,(int)$admin);
        $actorRowId=(int)$user['id'];
        $canRecover=true;
        if (preg_match('/^yesDeleteServer[1-9][0-9]*$/D',$data) &&
            !in_array((string)$user['step'],['none',''],true))
            throw new RuntimeException('management_other_flow');
        $draft=achbotManagementDraft($user);
        if ($legacy && $user['step']===(string)($userInfo['step']??'')) {
            achbotManagementStore($connection,$actor,$actorRowId,null);
            if (!achbotManagementCommit($connection)) throw new RuntimeException('management_commit_unknown');$begun=false;
            achbotManagementHome($user['step']==='addNewMarzbanPlan'?'plan':'server',null,achbotManagementText('restart'));return true;
        }
        if ($starts) {
            // Financial/privileged editors own their state. Starting a wizard
            // from an old message must not erase one of their active drafts.
            if ($draft===null && !in_array($user['step'],['none',''],true))
                throw new RuntimeException('management_other_flow');
            if ($draft!==null && $source<=$draft['source']) throw new RuntimeException('management_stale');
            $kind=in_array($data,['addManagedPlan','addNewMarzbanPlan'],true) ||
                str_starts_with($data,'newPlanForServer')?'plan':'server';
            $draft=['version'=>1,'kind'=>$kind,'nonce'=>bin2hex(random_bytes(6)),
                'rev'=>0,'source'=>$source,'stage'=>$kind==='server'?'provider':'title','values'=>[],'history'=>[]];
            if ($data==='addNewMarzbanPanel') {$draft['values']['provider']='marzban';$draft['stage']='title';}
            if ($data==='pgAddConnection') {$draft['values']['provider']='pasarguard';$draft['stage']='title';}
            if (preg_match('/^pgNewServer([1-9][0-9]*)$/D',$data,$m)) {
                $draft['values']=['provider'=>'pasarguard','panel_id'=>(int)$m[1]];$draft['stage']='title';
            }
            if (preg_match('/^newPlanForServer([1-9][0-9]*)$/D',$data,$m))
                $draft['values']['preferred_server']=(int)$m[1];
            if ($draft['kind']==='server') achbotServerWizardPrepare($connection,$draft);
            else achbotPlanWizardPrepare($connection,$draft);
            achbotManagementStore($connection,$actor,$actorRowId,$draft);
        } else {
            if ($draft===null || $source<=$draft['source']) throw new RuntimeException('management_stale');
            $action='text';$value=$text??'';
            if ($callback) {
                if (!preg_match('/^am\|([a-f0-9]{12})\|(0|[1-9][0-9]*)\|([a-z]+)\|([a-zA-Z0-9_-]{1,20})$/D',$data,$m) ||
                    !hash_equals($draft['nonce'],$m[1]) ||
                    ($m[3]==='cancel' ? (int)$m[2]>$draft['rev'] : (int)$m[2]!==$draft['rev']))
                    throw new RuntimeException('management_stale');
                $action=$m[3];$value=$m[4];
            } elseif ($data!=='') throw new RuntimeException('management_other_action');
            if (!$callback && $text===($buttonValues['cancel']??null)) $action='cancel';
            if ($action==='cancel') {
                $kind=$draft['kind'];achbotManagementStore($connection,$actor,$actorRowId,null);
                if (!achbotManagementCommit($connection)) throw new RuntimeException('management_commit_unknown');$begun=false;
                achbotManagementHome($kind);return true;
            }
            if ($action==='back') {
                if (!$draft['history']) {
                    $kind=$draft['kind'];achbotManagementStore($connection,$actor,$actorRowId,null);
                    if (!achbotManagementCommit($connection)) throw new RuntimeException('management_commit_unknown');$begun=false;
                    achbotManagementHome($kind);return true;
                }
                $draft['stage']=array_pop($draft['history']);unset($draft['choices'],$draft['page']);
            } else {
                $before=$draft['stage'];
                $saved=$draft['kind']==='server'?
                    achbotServerWizardAdvance($connection,$draft,$action,$value):
                    achbotPlanWizardAdvance($connection,$draft,$action,$value);
                if (is_int($saved) && $saved>0) {
                    $kind=$draft['kind'];achbotManagementStore($connection,$actor,$actorRowId,null);
                    if (!achbotManagementCommit($connection)) throw new RuntimeException('management_commit_unknown');$begun=false;
                    achbotManagementHome($kind,$saved,achbotManagementText($kind.'_saved'));return true;
                }
                if ($draft['stage']!==$before) $draft['history'][]=$before;
            }
            $draft['source']=$source;++$draft['rev'];
            if ($draft['kind']==='server') achbotServerWizardPrepare($connection,$draft);
            else achbotPlanWizardPrepare($connection,$draft);
            achbotManagementStore($connection,$actor,$actorRowId,$draft);
        }
        if (!achbotManagementCommit($connection)) throw new RuntimeException('management_commit_unknown');$begun=false;
        if ($starts) sendMessage(achbotManagementText('intro'),$cancelKey);
        // A selection changes this keyboard, not the conversation history.
        $messageId=(!$starts && $callback && in_array($action,['pick','page'],true) &&
            in_array($draft['stage'],['groups','targets'],true))?
            $update->callback_query->message->message_id:null;
        achbotManagementRender($connection,$draft,'',$messageId);
    } catch (Throwable $error) {
        if ($begun) {try {$connection->rollback();} catch (Throwable $ignored) {}}
        $reason=$error instanceof RuntimeException?$error->getMessage():'management_unavailable';
        $public=in_array($reason,['management_input','management_connection','management_targets','management_stale',
            'management_other_flow','management_other_action','management_admin_required'],true)?$reason:'management_unavailable';
        error_log('achbot_management_deferred reason='.$public);
        // A failed/unknown COMMIT is not safe to present as a failed save or retry.
        if ($reason==='management_commit_unknown') {
            sendMessage(achbotManagementText('review_required'));return true;
        }
        if (in_array($reason,['management_frame','management_private_required'],true)) return true;
        sendMessage(achbotManagementText($public));
        // Only a verified private, currently authorized actor may recover a draft.
        if (!$canRecover || $reason==='management_admin_required') return true;
        // Recover the committed draft, never render a partially changed local copy.
        try {
            $current=achbotManagementActor($connection,(int)$from_id,(int)$admin,false);
            $saved=$current?achbotManagementDraft($current):null;
            if ($saved!==null) achbotManagementRender($connection,$saved);
        } catch (Throwable $ignored) {}
    }
    return true;
}

function achbotManagementFieldBaseline(array $row,string $field): string {
    $column=['name'=>'title','flag'=>'flag','remark'=>'remark','max'=>'ucount','days'=>'days','volume'=>'volume'][$field]??null;
    $identity=['id'=>$row['id'],'type'=>$row['type']];
    if (isset($row['server_id'])) $identity['server_id']=$row['server_id'];
    if ($field==='login') foreach (['panel_url','username','password'] as $key) $identity[$key]=$row[$key];
    elseif ($column!==null) $identity['value']=$row[$column];
    else throw new RuntimeException('management_input');
    return hash('sha256',json_encode($identity,JSON_THROW_ON_ERROR));
}
/** Contextual server/plan field editors; shared validation and private cancel. */
function achbotHandleManagementFields(): bool {
    global $connection,$from_id,$admin,$data,$text,$update,$userInfo,$buttonValues,$cancelKey,$message_id,$chat_id;
    $cb=is_string($data??null)?$data:'';
    $step=(string)($userInfo['step']??'');
    $active=str_starts_with($step,'achbotField|');
    $start=preg_match('/^(editServer(Name|Flag|Remark|Max)|plan(Days|Volume)|changesServerLoginInfo)([1-9][0-9]*)$/D',$cb,$match)===1;
    $legacyCancel=is_string($text??null) && $text===($buttonValues['cancel']??null) &&
        preg_match('/^(?:editServer(?:Name|Flag|Remark|Max)|plan(?:Days|Volume)|changesServerLoginInfo)([1-9][0-9]*)$/D',$step,$old);
    $delete=preg_match('/^yesDeleteServer([1-9][0-9]*)$/D',$cb,$deletion)===1;
    if (!$start && !$active && !$legacyCancel && !$delete) return false;
    $begun=false;$context='server';$entity=0;
    try {
        $actor=(int)$from_id;$source=achbotManagementFrame($update,$actor,$cb,is_string($text??null)?$text:null);
        if (!$connection->begin_transaction()) throw new RuntimeException('management_begin');$begun=true;
        $u=achbotManagementActor($connection,$actor,(int)$admin);
        $actorRowId=(int)$u['id'];
        if ($delete && !in_array((string)$u['step'],['none',''],true))
            throw new RuntimeException('management_other_flow');
        $d=str_starts_with($u['step'],'achbotField|')?json_decode((string)$u['temp'],true):null;
        if ($active && (!is_array($d) || $u['step']!=='achbotField|'.($d['nonce']??'') ||
            $source<=(int)($d['source']??PHP_INT_MAX))) throw new RuntimeException('management_stale');
        if ($delete) {
            if ($actor!==(int)$admin && empty($GLOBALS['permissions']['servers'])) throw new RuntimeException('management_admin_required');
            $entity=(int)$deletion[1];
            $server=achbotManagementRow($connection,'SELECT i.id,c.type FROM server_info i JOIN server_config c ON c.id=i.id WHERE i.id=? FOR UPDATE','i',[$entity]);
            if (!$server || !achbotSupportsAccount($server['type'],0)) throw new RuntimeException('management_targets');
            // Keep live services and historical payment/fulfillment references recoverable.
            foreach (['SELECT id FROM orders_list WHERE server_id=? LIMIT 1 FOR UPDATE',
                'SELECT id FROM server_plans WHERE server_id=? LIMIT 1 FOR UPDATE',
                'SELECT id FROM pays WHERE server_id=? LIMIT 1 FOR UPDATE',
                'SELECT id FROM service_effects WHERE target_server_id=? LIMIT 1 FOR UPDATE'] as $sql)
                if (achbotManagementRow($connection,$sql,'i',[$entity])) throw new RuntimeException('server_referenced');
            achbotManagementSql($connection,'DELETE FROM server_config WHERE id=?','i',[$entity])->close();
            achbotManagementSql($connection,'DELETE FROM server_info WHERE id=?','i',[$entity])->close();
            if (!achbotManagementCommit($connection)) throw new RuntimeException('management_commit_unknown');$begun=false;
            achbotManagementHome('server',null,achbotManagementText('server_removed'));return true;
        }
        if ($legacyCancel) {
            if ($u['step']!==$step) throw new RuntimeException('management_stale');
            $context=str_starts_with($step,'plan')?'plan':'server';$entity=(int)$old[1];
            achbotManagementStore($connection,$actor,$actorRowId,null);
            if (!achbotManagementCommit($connection)) throw new RuntimeException('management_commit_unknown');$begun=false;
            achbotManagementHome($context,$entity);return true;
        }
        if ($start) {
            if (!in_array($u['step'],['none',''],true) && !str_starts_with($u['step'],'achbotField|')) throw new RuntimeException('management_other_flow');
            $entity=(int)$match[4];
            $context=str_starts_with($cb,'plan')?'plan':'server';
            $field=$context==='plan'?strtolower($match[3]):($match[1]==='changesServerLoginInfo'?'login':strtolower($match[2]));
            $row=$context==='plan'?achbotManagementRow($connection,'SELECT p.*,c.type FROM server_plans p JOIN server_config c ON c.id=p.server_id WHERE p.id=? AND p.active=1 FOR UPDATE','i',[$entity]):
                achbotManagementRow($connection,'SELECT i.*,c.type,c.panel_url,c.username,c.password FROM server_info i JOIN server_config c ON c.id=i.id WHERE i.id=? FOR UPDATE','i',[$entity]);
            if (!$row || !achbotSupportsAccount($row['type'],0) || ($field==='login' && $row['type']!=='marzban'))
                throw new RuntimeException('management_targets');
            $d=['nonce'=>bin2hex(random_bytes(6)),'source'=>$source,'context'=>$context,'id'=>$entity,
                'field'=>$field,'stage'=>$field==='login'?'url':$field,'values'=>[],
                'baseline'=>achbotManagementFieldBaseline($row,$field)];
        } else {
            $context=$d['context'];$entity=(int)$d['id'];
            if (is_string($text??null) && $text===($buttonValues['cancel']??null)) {
                achbotManagementStore($connection,$actor,$actorRowId,null);
                if (!achbotManagementCommit($connection)) throw new RuntimeException('management_commit_unknown');$begun=false;
                achbotManagementHome($context,$entity);return true;
            }
            if ($cb!=='' || !is_string($text)) throw new RuntimeException('management_other_action');
            $field=$d['field'];$input=trim($text);$save=true;
            if ($field==='login') {
                if ($d['stage']==='url') {$d['values']['url']=achbotManagementUrl($input);$d['stage']='username';$save=false;}
                elseif ($d['stage']==='username') {
                    if ($input==='' || mb_strlen($input)>100) throw new RuntimeException('management_input');
                    $d['values']['username']=$input;$d['stage']='password';$save=false;
                } else {
                    if ($text==='' || mb_strlen($text)>100 || preg_match('/[\\x00-\\x1f\\x7f]/',$text)) throw new RuntimeException('management_input');
                    $d['values']['password']=$text;achbotManagementMarzbanCheck($d['values']);
                }
            } elseif (in_array($field,['max','days','volume'],true)) {
                $input=achbotManagementInteger($input,1,$field==='days'?36500:($field==='volume'?1000000:100000000));
                if ($field==='days' && time()+$input*86400>2147483647) throw new RuntimeException('management_input');
            } elseif ($input==='' || mb_strlen($input)>100 ||
                ($field==='remark' && preg_match('/^[a-zA-Z0-9_-]{1,60}$/D',$input)!==1)) throw new RuntimeException('management_input');
            if ($save) {
                $row=$context==='plan'?achbotManagementRow($connection,'SELECT p.*,c.type FROM server_plans p JOIN server_config c ON c.id=p.server_id WHERE p.id=? AND p.active=1 FOR UPDATE','i',[$entity]):
                    achbotManagementRow($connection,'SELECT i.*,c.type,c.panel_url,c.username,c.password FROM server_info i JOIN server_config c ON c.id=i.id WHERE i.id=? FOR UPDATE','i',[$entity]);
                if (!$row || !hash_equals($d['baseline'],achbotManagementFieldBaseline($row,$field))) throw new RuntimeException('management_stale');
                if ($field==='login') {
                    $v=$d['values'];achbotManagementSql($connection,'UPDATE server_config SET panel_url=?,username=?,password=? WHERE id=? AND type=\'marzban\'','sssi',[$v['url'],$v['username'],$v['password'],$entity])->close();
                } else {
                    $column=['name'=>'title','flag'=>'flag','remark'=>'remark','max'=>'ucount','days'=>'days','volume'=>'volume'][$field]??null;
                    if ($column===null) throw new RuntimeException('management_input');
                    $table=$context==='plan'?'server_plans':'server_info';
                    achbotManagementSql($connection,"UPDATE $table SET $column=? WHERE id=?",'si',[(string)$input,$entity])->close();
                }
                achbotManagementStore($connection,$actor,$actorRowId,null);
                if (!achbotManagementCommit($connection)) throw new RuntimeException('management_commit_unknown');$begun=false;
                achbotManagementHome($context,$entity,achbotManagementText($context==='plan'?'plan_updated':'settings_saved'));return true;
            }
            $d['source']=$source;
        }
        $next='achbotField|'.$d['nonce'];$payload=json_encode($d,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        achbotManagementWriteActorState($connection,$actor,$actorRowId,$next,$payload);
        if (!achbotManagementCommit($connection)) throw new RuntimeException('management_commit_unknown');$begun=false;
        $prompt=match($d['stage']) {
            'days'=>'plan_days_prompt','volume'=>'plan_volume_prompt',
            'name'=>'server_title','flag'=>'server_flag','remark'=>'server_remark','max'=>'server_capacity',
            'url'=>'server_url','username'=>'server_username','password'=>'server_password',
            default=>throw new RuntimeException('management_input')
        };
        sendMessage(achbotManagementText($prompt),$cancelKey,'HTML');
    } catch (Throwable $error) {
        if ($begun) {try{$connection->rollback();}catch(Throwable $ignored){}}
        $code=$error instanceof RuntimeException?$error->getMessage():'management_unavailable';
        if (in_array($code,['management_frame','management_private_required'],true)) return true;
        $allowed=['management_stale','management_targets','management_input','management_other_flow','management_other_action','management_connection','management_admin_required','server_referenced'];
        error_log('achbot_management_field_deferred reason='.(in_array($code,$allowed,true)?$code:'management_unavailable'));
        sendMessage(achbotManagementText($code==='management_commit_unknown'?'review_required':(in_array($code,$allowed,true)?$code:'management_unavailable')));
    }
    return true;
}

function achbotManagementCommit(mysqli $db): bool {
    try {if ($db->commit()!==true) throw new RuntimeException('management_commit_unknown');}
    catch (Throwable $error) {throw new RuntimeException('management_commit_unknown');}
    return true;
}
