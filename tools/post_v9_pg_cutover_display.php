<?php
/**
 * Atomic provider-metadata transition for existing per-server display policies.
 * Called by the historical cutover preflight and its write transaction.
 * A missing setting uses the runtime default. Incompatible/malformed policies
 * block the conversion; we never silently discard customer-visible choices.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(2); }
require_once __DIR__.'/../src/Subscription/ServerDisplaySettings.php';

/** @return array<int,array{id:int,type:string,value:string,server_id:int}> */
function pgcDisplayPlan(mysqli $db,array $servers,bool $lock): array {
    $planned=[];
    foreach (array_keys($servers) as $serverId) {
        $serverId=(int)$serverId;
        $type=achbotServerDisplayType($serverId);
        $rows=pgcRows($db,'SELECT id,type,value FROM setting WHERE type=? ORDER BY id LIMIT 2'.
            ($lock?' FOR UPDATE':''),[$type]);
        pgcExpect(count($rows)<=1,'display_setting_duplicate');
        if (!$rows) continue; // Runtime's native default works for both providers.
        $row=$rows[0];
        try {
            $before=json_decode((string)$row['value'],true,32,JSON_THROW_ON_ERROR);
            $display=achbotServerDisplayDocument($row,$serverId,'marzban');
        } catch (Throwable $error) {pgcFail('display_setting_invalid');}
        pgcExpect(($display['mode']??null)==='native','display_setting_legacy_incompatible');
        $after=$before;
        $after['provider']='pasarguard';
        try {
            $checked=achbotServerDisplayDocument([
                'value'=>json_encode($after,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)
            ],$serverId,'pasarguard');
        } catch (Throwable $error) {pgcFail('display_setting_target_invalid');}
        pgcExpect($checked===$display,'display_policy_changed');
        $planned[]=['id'=>(int)$row['id'],'type'=>$type,'value'=>(string)$row['value'],
            'server_id'=>$serverId];
    }
    return $planned;
}

/** Runs under the caller's transaction, with original rows locked by pgcDisplayPlan. */
function pgcDisplayApply(mysqli $db,array $planned): void {
    foreach ($planned as $row) {
        // Exact original raw JSON CAS protects against administrator edits.
        // Only top-level $.provider is modified; all policy keys are retained.
        pgcUpdate($db,"UPDATE setting SET value=JSON_SET(value,'$.provider','pasarguard')
            WHERE id=? AND type=? AND BINARY value=BINARY ?",[
            $row['id'],$row['type'],$row['value']]);
        $read=pgcRows($db,'SELECT value FROM setting WHERE id=? AND type=?',[
            $row['id'],$row['type']]);
        pgcExpect(count($read)===1,'display_setting_readback_missing');
        try {
            $before=json_decode($row['value'],true,32,JSON_THROW_ON_ERROR);
            $after=json_decode((string)$read[0]['value'],true,32,JSON_THROW_ON_ERROR);
            $before['provider']='pasarguard';
            pgcExpect($after===$before,'display_policy_changed');
            pgcExpect(achbotServerDisplayDocument(
                ['value'=>$read[0]['value']],$row['server_id'],'pasarguard'
            )===$before['display'],'display_setting_target_invalid');
        } catch (RuntimeException $error) {throw $error;}
        catch (Throwable $error) {pgcFail('display_setting_readback_invalid');}
    }
}
