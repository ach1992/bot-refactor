<?php
/** Authenticated GET-only native Group catalog, stored as private offline input.
 * Does not query users, create entitlements or write anything to PasarGuard.
 */
if (PHP_SAPI !== 'cli') {http_response_code(404);exit(1);}
umask(0077);
require_once dirname(__DIR__).'/src/Panel/PasarGuard.php';

try {
    $opts=getopt('',['connections:','panel-alias:','output:']);
    if (!is_array($opts) || count($opts)!==3 ||
        !is_string($opts['connections']??null) ||
        !is_string($opts['panel-alias']??null) ||
        !is_string($opts['output']??null)) throw new RuntimeException('arguments_invalid');
    $connections=$opts['connections'];
    if (is_link($connections) || !is_file($connections) ||
        filesize($connections)===false || filesize($connections)>200000 ||
        (fileperms($connections)&0077)!==0)
        throw new RuntimeException('private_connection_invalid');
    $private=json_decode((string)file_get_contents($connections),true,32,JSON_THROW_ON_ERROR);
    if (($private['version']??null)!==1 ||
        !preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,49}$/D',$opts['panel-alias']))
        throw new RuntimeException('private_connection_invalid');
    $panel=$private['panels'][$opts['panel-alias']]??null;
    if (!is_array($panel) || !achbotPgPanelValid($panel))
        throw new RuntimeException('private_connection_invalid');
    $check=achbotPgCheck($panel); // ONLY GET /system, /admin and /groups.
    if (($check['success']??false)!==true)
        throw new RuntimeException('native_group_check_unavailable');
    $allowed=achbotPgEligibleGroupIds($check);
    $groups=[];
    foreach ($check['groups'] as $entry) {
        if (!in_array($entry['id'],$allowed,true) || $entry['is_disabled']) continue;
        $groups[]=['id'=>$entry['id'],'name'=>$entry['name'],'is_disabled'=>false];
    }
    if (!$groups) throw new RuntimeException('no_authorized_groups');
    usort($groups,static fn($a,$b)=>$a['id']<=>$b['id']);
    $out=$opts['output'];$folder=realpath(dirname($out));
    $repo=realpath(dirname(__DIR__));
    if ($folder===false || $folder===$repo ||
        str_starts_with($folder,$repo.DIRECTORY_SEPARATOR) ||
        is_link($out) || file_exists($out))
        throw new RuntimeException('private_output_invalid');
    $handle=@fopen($out,'x');
    if ($handle===false) throw new RuntimeException('private_output_invalid');
    try {
        $json=json_encode(['version'=>1,'groups'=>$groups],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        if (fwrite($handle,$json."\n")!==strlen($json)+1 || !fflush($handle))
            throw new RuntimeException('private_output_failed');
    } catch(Throwable $error) {
        fclose($handle);@unlink($out);throw $error;
    }
    fclose($handle);
    echo json_encode(['complete'=>true,'eligible_groups'=>count($groups),'output_private'=>true],
        JSON_THROW_ON_ERROR)."\n";
    exit(0);
} catch (Throwable $error) {
    $reason=$error instanceof RuntimeException?$error->getMessage():'group_catalog_unavailable';
    if (!in_array($reason,['arguments_invalid','private_connection_invalid',
         'native_group_check_unavailable','no_authorized_groups',
         'private_output_invalid','private_output_failed'],true))
        $reason='group_catalog_unavailable';
    echo json_encode(['complete'=>false,'error_code'=>$reason],JSON_THROW_ON_ERROR)."\n";
    exit(2);
}
