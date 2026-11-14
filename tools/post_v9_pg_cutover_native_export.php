<?php
/** Native GET-only snapshot for an authorized offline Vivan/Hell cutover preflight.
 * This CLI tool NEVER sends POST/PUT/DELETE or touches any database.
 * Input credentials remain private, never copied into a manifest or diagnostic.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
umask(0077);
require_once dirname(__DIR__).'/src/Panel/PasarGuard.php';

function cutoverFail(string $reason): never {
    throw new RuntimeException($reason);
}
function cutoverJson(string $path, bool $credential=false): array {
    if ($path==='' || is_link($path) || !is_file($path) || filesize($path)===false ||
        filesize($path)>50000000) cutoverFail('input_unavailable');
    if ($credential && (fileperms($path)&0077)!==0) cutoverFail('credential_file_permissions');
    try {
        $data=json_decode((string)file_get_contents($path),true,32,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);
    } catch (Throwable $e) {cutoverFail('invalid_input_json');}
    if (!is_array($data) || ($data['version']??null)!==1) cutoverFail('input_version_invalid');
    return $data;
}
function cutoverCanonicalName($value): string {
    if (!is_string($value) || !preg_match('/^[a-zA-Z0-9_@.-]{1,100}$/D',$value))
        cutoverFail('local_username_invalid');
    return $value;
}
function cutoverOutput(string $path,array $payload): void {
    $repo=realpath(dirname(__DIR__));$folder=realpath(dirname($path));
    if ($folder===false || $repo===false ||
        $folder===$repo || str_starts_with($folder,$repo.DIRECTORY_SEPARATOR))
        cutoverFail('private_output_location_invalid');
    if (file_exists($path) || is_link($path)) cutoverFail('private_output_exists');
    $stream=@fopen($path,'x');
    if ($stream===false) cutoverFail('private_output_unavailable');
    try {
        $json=json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $length=strlen($json);$written=0;
        while ($written<$length) {
            $n=fwrite($stream,substr($json,$written,1048576));
            if ($n===false || $n===0) cutoverFail('private_output_write_failed');
            $written+=$n;
        }
        if (fwrite($stream,"\n")!==1 || !fflush($stream)) cutoverFail('private_output_write_failed');
    } catch (Throwable $e) {
        fclose($stream);
        @unlink($path);
        throw $e;
    }
    fclose($stream);
}

try {
    $options=getopt('',['local:','mapping:','connections:','output:']);
    if (!is_array($options) || count($options)!==4 ||
        !isset($options['local'],$options['mapping'],$options['connections'],$options['output']) ||
        !is_string($options['local']) || !is_string($options['mapping']) ||
        !is_string($options['connections']) || !is_string($options['output']))
        cutoverFail('arguments_invalid');
    $local=cutoverJson($options['local']);
    $mapping=cutoverJson($options['mapping']);
    $connections=cutoverJson($options['connections'],true);
    $tenant=$local['tenant']??null;
    if (!in_array($tenant,['vivan','hell'],true) || ($mapping['tenant']??null)!==$tenant ||
        !is_array($mapping['server_panels']??null) ||
        !is_array($connections['panels']??null)) cutoverFail('input_tenant_or_mapping_invalid');
    $panels=[];$outputPanels=[];
    $requiredAliases=array_unique(array_values($mapping['server_panels']));
    foreach ($requiredAliases as $alias) {
        if (!is_string($alias) || !preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,49}$/D',$alias))
            cutoverFail('panel_alias_invalid');
        $panel=$connections['panels'][$alias]??null;
        if (!is_array($panel) || !achbotPgPanelValid($panel)) cutoverFail('panel_config_invalid');
        $check=achbotPgCheck($panel); // READ ONLY: /api/system, /api/admin, /api/groups
        if (($check['success']??false)!==true) cutoverFail('panel_readonly_check_failed');
        $eligible=achbotPgEligibleGroupIds($check);
        if (!$eligible) cutoverFail('panel_groups_empty');
        $panels[$alias]=$panel;
        $outputPanels[]=[
            'key'=>$alias, 'id'=>$panel['id'],
            'instance_key'=>$panel['instance_key'],
            'endpoint_sha256'=>hash('sha256',rtrim($panel['api_url'],'/')),
            'available_groups'=>$eligible,
        ];
    }
    $lookup=[];
    $seenOrders=[];
    foreach ($local['orders']??[] as $order) {
        if (!is_array($order) || !is_int($order['id']??null) || $order['id']<=0 ||
            !is_int($order['server_id']??null) || $order['server_id']<=0 ||
            isset($seenOrders[$order['id']])) cutoverFail('local_order_invalid');
        $seenOrders[$order['id']]=true;
        $serverId=(string)$order['server_id'];
        $alias=$mapping['server_panels'][$serverId]??null;
        if (!is_string($alias) || !isset($panels[$alias])) cutoverFail('missing_panel_mapping');
        $username=cutoverCanonicalName($order['username']??null);
        $lookup[$alias.'|'.$username]=[$alias,$username];
    }
    if (!$lookup) cutoverFail('local_orders_empty');
    ksort($lookup,SORT_STRING);
    $accounts=[];
    foreach ($lookup as [$alias,$username]) {
        // The native API's authenticated exact username lookup is read-only.
        $r=achbotPgUserResult(achbotPgRequest($panels[$alias],'GET',
            '/api/user/by-username/'.rawurlencode($username)));
        if (($r['success']??false)!==true) cutoverFail('native_user_read_failed');
        $user=$r['user'];
        if ($user['username']!==$username) cutoverFail('native_username_mismatch');
        $accounts[]=[
            'panel_key'=>$alias,'id'=>$user['id'],'username'=>$user['username'],
            'created_at'=>$user['created_at'],'group_ids'=>$user['group_ids'],
            'data_limit'=>$user['data_limit'],'used_traffic'=>$user['used_traffic'],
            'expire'=>$user['expire'],'status'=>$user['status'],
            'native_schedules_clear'=>achbotPgManaged($user),
        ];
    }
    $snapshot=['version'=>1,'tenant'=>$tenant,'panels'=>$outputPanels,'accounts'=>$accounts];
    cutoverOutput($options['output'],$snapshot);
    echo json_encode(['complete'=>true,'tenant'=>$tenant,'native_accounts'=>count($accounts),
        'panel_count'=>count($outputPanels),'output_private'=>true],JSON_THROW_ON_ERROR)."\n";
    exit(0);
} catch (Throwable $e) {
    // Never echo private path, API key, URL, username, raw response or exception text.
    $allowed=['arguments_invalid','input_unavailable','credential_file_permissions',
        'invalid_input_json','input_version_invalid','private_output_location_invalid',
        'private_output_exists','private_output_unavailable','private_output_write_failed',
        'input_tenant_or_mapping_invalid','panel_alias_invalid','panel_config_invalid',
        'panel_readonly_check_failed','panel_groups_empty','local_order_invalid',
        'missing_panel_mapping','local_username_invalid','local_orders_empty',
        'native_user_read_failed','native_username_mismatch'];
    $reason=$e->getMessage();
    if (!in_array($reason,$allowed,true)) $reason='native_snapshot_failed';
    echo json_encode(['complete'=>false,'error_code'=>$reason])."\n";
    exit(2);
}
