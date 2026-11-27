<?php
declare(strict_types=1);
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {http_response_code(404);exit;}
require_once __DIR__.'/../Telegram/Conversation.php';
require_once __DIR__.'/../Panel/ProviderStorage.php';
require_once __DIR__.'/../Admin/Access.php';

/** Search never picks an arbitrary first row. Literal exact name wins only
 * when unique; otherwise accept a unique literal substring in the same
 * current authorized scope. '%' and '_' are ordinary name characters. */
function achbotSubscriptionSearch(mysqli $db,int $actor,string $mode,string $input): array {
    $input=trim($input);
    if ($input==='') return ['status'=>'missing'];
    if ($mode==='searchUsersConfig') {
        if (!achbotAdminCanFor($db,$actor,(int)$GLOBALS['admin'],'orders')) return ['status'=>'denied'];
        $where='status=1';$types='';$args=[];
    } elseif (in_array($mode,['searchAgentConfig','searchMyConfig'],true)) {
        $where='status=1 AND userid=?';$types='i';$args=[$actor];
        if ($mode==='searchMyConfig') $where.=' AND agent_bought=0';
    } else return ['status'=>'denied'];
    $s=achbotProviderStatement($db,"SELECT id, BINARY remark=BINARY ? AS exact_match
        FROM orders_list WHERE ".$where." AND LOCATE(?,remark)>0
        ORDER BY exact_match DESC,id DESC LIMIT 2",'s'.$types.'s',[$input,...$args,$input]);
    $rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
    if (!$rows) return ['status'=>'missing'];
    if (count($rows)>1 && ((int)$rows[0]['exact_match']!==(int)1 || (int)$rows[1]['exact_match']===1))
        return ['status'=>'ambiguous'];
    return ['status'=>'found','id'=>(int)$rows[0]['id']];
}
function achbotSubscriptionSearchEditor(string $step): ?array {
    return preg_match('/^(searchAgentConfig|searchMyConfig|searchUsersConfig)_([1-9][0-9]*)$/D',$step,$m)
        ?['mode'=>$m[1],'source'=>(int)$m[2]]:null;
}
function achbotSubscriptionSearchCancelKeys(int $source): string {
    return json_encode(['inline_keyboard'=>[[[
        'text'=>achbotMessage('legacy_buttons.cancel'),
        'callback_data'=>'cancelSearchConfig_'.$source]]]],JSON_THROW_ON_ERROR);
}
