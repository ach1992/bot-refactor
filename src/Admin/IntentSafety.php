<?php
/** Persisted, replay-safe administrator intents. Uses existing setting rows; no schema migration. */
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {http_response_code(404);exit;}
require_once __DIR__.'/Access.php';

function achbotAdminIntentFrame($update,int $actor,string $data): array {
    $q=is_object($update)?($update->callback_query??null):null;
    $m=is_object($q)?($q->message??null):null;
    if (!is_object($update) || !is_int($update->update_id??null) || $update->update_id<=0 ||
        !is_object($q) || ($q->data??null)!==$data ||
        !is_object($m) || ($m->chat->type??'')!=='private' ||
        (int)($m->chat->id??0)!==$actor || (int)($q->from->id??0)!==$actor ||
        !is_int($m->message_id??null) || $m->message_id<=0)
        throw new RuntimeException('management_private_required');
    return ['source'=>$update->update_id,'message'=>$m->message_id];
}
function achbotAdminIntentActor(mysqli $db,int $actor,int $primary,string $capability): array {
    $q=$db->prepare('SELECT id,userid,isAdmin,step FROM users WHERE userid=? LIMIT 2 FOR UPDATE');
    $q->bind_param('i',$actor);$q->execute();$rows=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
    if (count($rows)!==1 || (int)$rows[0]['id']<=0 || (int)$rows[0]['userid']!==$actor ||
        ($actor!==$primary && (int)$rows[0]['isAdmin']!==1) ||
        !achbotAdminCanLocked($db,$actor,$primary,$capability))
        throw new RuntimeException('management_admin_required');
    return $rows[0];
}
function achbotAdminIntentSettingRows(mysqli $db,string $key,bool $locked): array {
    $q=$db->prepare('SELECT id,value FROM setting WHERE type=? LIMIT 2'.($locked?' FOR UPDATE':''));
    $q->bind_param('s',$key);$q->execute();$rows=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
    if (count($rows)>1)throw new RuntimeException('management_stale');
    return $rows;
}
function achbotAdminIntentWrite(mysqli $db,string $key,string $value,array $rows): void {
    if ($rows) {
        $id=(int)$rows[0]['id'];
        $q=$db->prepare('UPDATE setting SET value=? WHERE id=? AND type=?');
        $q->bind_param('sis',$value,$id,$key);
    } else {
        $q=$db->prepare('INSERT INTO setting(type,value) VALUES(?,?)');
        $q->bind_param('ss',$key,$value);
    }
    if (!$q->execute() || $q->affected_rows!==1) {$q->close();throw new RuntimeException('management_storage');}
    $q->close();
}
/** Entire local entity snapshot, so a warning cannot outlive a material edit. */
function achbotAdminIntentEntityDigest(mysqli $db,string $kind,int $id,bool $locked): ?string {
    if ($id<=0 || !in_array($kind,['server','plan'],true))return null;
    $suffix=$locked?' FOR UPDATE':'';
    if ($kind==='server') {
        $q=$db->prepare('SELECT * FROM server_config WHERE id=?'.$suffix);
        $q->bind_param('i',$id);$q->execute();$config=$q->get_result()->fetch_assoc();$q->close();
        $q=$db->prepare('SELECT * FROM server_info WHERE id=?'.$suffix);
        $q->bind_param('i',$id);$q->execute();$info=$q->get_result()->fetch_assoc();$q->close();
        if (!$config || !$info || (int)$info['active']!==1 ||
            !in_array($config['type'],['marzban','pasarguard'],true))return null;
        $target=['config'=>$config,'info'=>$info];
    } else {
        $q=$db->prepare('SELECT * FROM server_plans WHERE id=?'.$suffix);
        $q->bind_param('i',$id);$q->execute();$plan=$q->get_result()->fetch_assoc();$q->close();
        if (!$plan || (int)$plan['inbound_id']!==0 || !in_array((int)$plan['active'],[0,1],true))return null;
        $q=$db->prepare('SELECT type FROM server_config WHERE id=?'.$suffix);
        $sid=(int)$plan['server_id'];$q->bind_param('i',$sid);$q->execute();$server=$q->get_result()->fetch_assoc();$q->close();
        if (!$server || !in_array($server['type'],['marzban','pasarguard'],true))return null;
        $target=['plan'=>$plan,'provider'=>$server['type']];
    }
    return hash('sha256',json_encode($target,JSON_THROW_ON_ERROR));
}
/** One latest destructive warning per actor, bound to a real private Telegram message. */
function achbotAdminIntentIssue(mysqli $db,int $actor,int $primary,string $kind,int $id,
    $update,string $data): string {
    $frame=achbotAdminIntentFrame($update,$actor,$data);
    $key='ACHBOT_ADMIN_DELETE_INTENT:'.$actor;$cap=$kind==='server'?'servers':'plans';
    $begun=false;
    try {
        if (!$db->begin_transaction())throw new RuntimeException('management_begin');$begun=true;
        $u=achbotAdminIntentActor($db,$actor,$primary,$cap);
        if (!in_array((string)$u['step'],['none',''],true))throw new RuntimeException('management_other_flow');
        $digest=achbotAdminIntentEntityDigest($db,$kind,$id,true);
        if ($digest===null)throw new RuntimeException('management_targets');
        $rows=achbotAdminIntentSettingRows($db,$key,true);
        $old=$rows?json_decode((string)$rows[0]['value'],true):null;
        if (is_array($old) && $frame['source']<=(int)($old['source']??0))
            throw new RuntimeException('management_stale');
        $nonce=bin2hex(random_bytes(8));
        $intent=['version'=>1,'actor'=>$actor,'kind'=>$kind,'id'=>$id,'nonce'=>$nonce,
            'digest'=>$digest,'source'=>$frame['source'],'message'=>$frame['message'],'issued'=>time()];
        achbotAdminIntentWrite($db,$key,json_encode($intent,JSON_THROW_ON_ERROR),$rows);
        if (!$db->commit())throw new RuntimeException('management_commit_unknown');$begun=false;
        return ($kind==='server'?'yesDeleteServer':'confirmDeletePlan').$id.'_'.$nonce;
    } catch(Throwable $e) {
        if ($begun) {try{$db->rollback();}catch(Throwable $ignored){}}
        throw $e;
    }
}
/** Must be called inside the same locked SQL transaction as the local DELETE. */
function achbotAdminIntentConsumeLocked(mysqli $db,int $actor,int $primary,string $kind,
    int $id,string $nonce,$update,string $data): void {
    $frame=achbotAdminIntentFrame($update,$actor,$data);
    $u=achbotAdminIntentActor($db,$actor,$primary,$kind==='server'?'servers':'plans');
    if (!in_array((string)$u['step'],['none',''],true))throw new RuntimeException('management_other_flow');
    $key='ACHBOT_ADMIN_DELETE_INTENT:'.$actor;
    $rows=achbotAdminIntentSettingRows($db,$key,true);
    $intent=$rows?json_decode((string)$rows[0]['value'],true):null;
    if (!is_array($intent) || ($intent['version']??null)!==1 ||
        ($intent['actor']??null)!==$actor || ($intent['kind']??null)!==$kind ||
        ($intent['id']??null)!==$id || !is_string($intent['nonce']??null) ||
        !hash_equals($intent['nonce'],$nonce) || ($intent['message']??null)!==$frame['message'] ||
        $frame['source']<=(int)($intent['source']??PHP_INT_MAX) ||
        !is_int($intent['issued']??null) || time()<$intent['issued'] || time()-$intent['issued']>600)
        throw new RuntimeException('management_stale');
    $digest=achbotAdminIntentEntityDigest($db,$kind,$id,true);
    if ($digest===null || !hash_equals((string)($intent['digest']??''),$digest))
        throw new RuntimeException('management_stale');
    // Consumed atomically with the destructive SQL write; replay is denied.
    achbotAdminIntentWrite($db,$key,'{}',$rows);
}
/** Cancel an issued warning on Back, without exposing the old destructive button. */
function achbotAdminIntentCancelLocked(mysqli $db,int $actor,int $primary,string $kind,
    int $id,string $nonce,$update,string $data): void {
    $frame=achbotAdminIntentFrame($update,$actor,$data);
    $u=achbotAdminIntentActor($db,$actor,$primary,$kind==='server'?'servers':'plans');
    if (!in_array((string)$u['step'],['none',''],true))throw new RuntimeException('management_other_flow');
    $key='ACHBOT_ADMIN_DELETE_INTENT:'.$actor;
    $rows=achbotAdminIntentSettingRows($db,$key,true);
    $intent=$rows?json_decode((string)$rows[0]['value'],true):null;
    if (!is_array($intent) || ($intent['version']??null)!==1 ||
        ($intent['actor']??null)!==$actor || ($intent['kind']??null)!==$kind ||
        ($intent['id']??null)!==$id || !is_string($intent['nonce']??null) ||
        !hash_equals($intent['nonce'],$nonce) || ($intent['message']??null)!==$frame['message'] ||
        $frame['source']<=(int)($intent['source']??PHP_INT_MAX))
        throw new RuntimeException('management_stale');
    achbotAdminIntentWrite($db,$key,'{}',$rows);
}
/** Initial token is valid only before the first successful state mutation. */
function achbotAdminPlanStateRevision(mysqli $db,int $planId,bool $locked=false): string {
    $rows=achbotAdminIntentSettingRows($db,'ACHBOT_PLAN_STATE_REV:'.$planId,$locked);
    $rev=$rows?(string)$rows[0]['value']:'0000000000000000';
    if (!preg_match('/^[a-f0-9]{16}$/D',$rev))throw new RuntimeException('management_stale');
    return $rev;
}
/** Rotate after every successful visibility mutation or hard delete, preventing ABA replay. */
function achbotAdminPlanStateBumpLocked(mysqli $db,int $planId): string {
    $key='ACHBOT_PLAN_STATE_REV:'.$planId;
    $rows=achbotAdminIntentSettingRows($db,$key,true);
    $new=bin2hex(random_bytes(8));
    achbotAdminIntentWrite($db,$key,$new,$rows);
    return $new;
}
function achbotAdminPlanStateSet(mysqli $db,int $actor,int $primary,int $planId,
    int $desired,string $revision,$update,string $data): void {
    if (!in_array($desired,[0,1],true) || $planId<=0 ||
        !preg_match('/^[a-f0-9]{16}$/D',$revision))throw new RuntimeException('management_stale');
    achbotAdminIntentFrame($update,$actor,$data);
    $begun=false;
    try {
        if (!$db->begin_transaction())throw new RuntimeException('management_begin');$begun=true;
        achbotAdminIntentActor($db,$actor,$primary,'plans');
        $q=$db->prepare('SELECT active,inbound_id FROM server_plans WHERE id=? FOR UPDATE');
        $q->bind_param('i',$planId);$q->execute();$plan=$q->get_result()->fetch_assoc();$q->close();
        if (!$plan || (int)$plan['inbound_id']!==0 || (int)$plan['active']!==1-$desired ||
            !hash_equals(achbotAdminPlanStateRevision($db,$planId,true),$revision))
            throw new RuntimeException('management_stale');
        $previous=1-$desired;
        $q=$db->prepare('UPDATE server_plans SET active=? WHERE id=? AND active=?');
        $q->bind_param('iii',$desired,$planId,$previous);
        if (!$q->execute() || $q->affected_rows!==1) {$q->close();throw new RuntimeException('management_stale');}
        $q->close();
        achbotAdminPlanStateBumpLocked($db,$planId);
        if (!$db->commit())throw new RuntimeException('management_commit_unknown');$begun=false;
    } catch(Throwable $e) {
        if ($begun) {try{$db->rollback();}catch(Throwable $ignored){}}
        throw $e;
    }
}
