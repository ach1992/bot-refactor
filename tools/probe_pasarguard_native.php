<?php
/**
 * Explicit, task-owned native PasarGuard fixture probe. Never run on a real panel.
 * CLI: php tools/probe_pasarguard_native.php <private-fixture-state.json> <private-checkpoint.json>
 * Credentials/links/raw users never enter stdout. No retry or uncertain cleanup.
 */
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
error_reporting(E_ALL);
ini_set('display_errors','0');ini_set('log_errors','0');
set_error_handler(static function($n,$text) {
    if ($n===E_DEPRECATED) return true;
    throw new RuntimeException('probe_php_warning');
});
define('ACHBOT_PG_TEST_LOOPBACK',true);
require_once dirname(__DIR__).'/src/Panel/PasarGuard.php';
$events=[];
function probeSave(string $path,array $state): void {
    $tmp=$path.'.next';
    if (file_put_contents($tmp,json_encode($state,JSON_THROW_ON_ERROR))===false ||
        !chmod($tmp,0600) || !rename($tmp,$path)) throw new RuntimeException('probe_checkpoint_unavailable');
}
function probeResult(string $name,array $result): array {
    $safe=['phase'=>$name,'success'=>($result['success']??false)===true,
        'uncertain'=>(bool)($result['uncertain']??false),'http'=>(int)($result['http']??0),
        'reason'=>(string)($result['reason']??'probe_result_invalid')];
    $GLOBALS['events'][]=$safe;
    if (!$safe['success']) throw new RuntimeException('probe_phase_unconfirmed');
    return $result;
}
try {
    if ($argc!==3 || file_exists($argv[2])) throw new RuntimeException('probe_new_checkpoint_required');
    $fixture=json_decode(file_get_contents($argv[1]),true,64,JSON_THROW_ON_ERROR);
    $parts=parse_url((string)($fixture['url']??''));
    if (($parts['scheme']??'')!=='http' || !in_array($parts['host']??'', ['127.0.0.1','[::1]'],true))
        throw new RuntimeException('probe_owned_loopback_required');
    $groups=achbotPgGroupsCanonical($fixture['group_ids']??[]);
    if (count($groups)<2) throw new RuntimeException('probe_two_fixture_groups_required');
    $panel=['id'=>1,'instance_key'=>str_repeat('b',32),'api_url'=>$fixture['url'],
        'api_key'=>$fixture['api_key'],'version'=>'5.5.1'];
    $check=probeResult('check',achbotPgCheck($panel));
    $marker='achbot:v1:'.bin2hex(random_bytes(16));
    $username='achbot_probe_'.bin2hex(random_bytes(6));
    $state=['phase'=>'create_intent','username'=>$username,'operation_marker'=>$marker];
    probeSave($argv[2],$state);
    $created=probeResult('create201_readback',achbotPgCreate($panel,[
        'username'=>$username,'data_limit'=>1073741824,'expire'=>time()+86400,
        'group_ids'=>[$groups[0]],'operation_marker'=>$marker]));
    $user=$created['user'];
    $identity=['remote_user_id'=>$user['id'],'remote_created_at'=>$user['created_at'],'operation_marker'=>$marker];
    $state['identity']=$identity;$state['phase']='created';probeSave($argv[2],$state);
    probeResult('by_id_read',achbotPgRead($panel,$identity));
    $state['phase']='modify_intent';probeSave($argv[2],$state);
    probeResult('absolute_quota_expiry_groups',achbotPgModify($panel,$identity,[
        'data_limit'=>2147483648,'expire'=>time()+172800,'group_ids'=>[$groups[1]]]));
    foreach (['disabled','active'] as $status) {
        $state['phase']='status_'.$status.'_intent';probeSave($argv[2],$state);
        probeResult('status_'.$status,achbotPgModify($panel,$identity,['status'=>$status]));
    }
    foreach (['reset'=>'achbotPgReset','revoke_proxy_identity'=>'achbotPgRevoke'] as $phase=>$fn) {
        $state['phase']=$phase.'_intent';probeSave($argv[2],$state);
        probeResult($phase,$fn($panel,$identity));
    }
    foreach (['links','links_base64'] as $format) {
        $r=probeResult($format,achbotPgLinks($panel,$identity,$format));
        $events[count($events)-1]['links_count']=count($r['links']);
    }
    $state['phase']='delete_intent';probeSave($argv[2],$state);
    probeResult('delete204_by_id404',achbotPgDelete($panel,$identity));
    $absent=achbotPgRead($panel,$identity);
    if (($absent['success']??true)!==false || ($absent['http']??0)!==404)
        throw new RuntimeException('probe_final_absence_unconfirmed');
    $state['phase']='complete';probeSave($argv[2],$state);
    echo json_encode(['complete'=>true,'provider_pin'=>'5.5.1','events'=>$events,
        'live_customer_or_traffic_tested'=>false],JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $e) {
    echo json_encode(['complete'=>false,'reason'=>'probe_stopped_without_retry_or_compensation',
        'events'=>$events],JSON_THROW_ON_ERROR).PHP_EOL;
    exit(1);
}
