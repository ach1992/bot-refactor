<?php
/** Read-only, original-source aggregate for bulk account notices.
 * No new state, provider calls, financial action, or retry authority.
 */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404); exit;
}
require_once __DIR__.'/../../messages/loader.php';

/** Count only confirmed local orders linked to their original 007 effects. */
function achbotBulkRecordedAccounts(mysqli $db,int $expected,int $owner,
    ?int $payId=null,?string $source=null): array {
    if ($expected<=0 || $owner<=0 || (($payId!==null)===($source!==null)))
        throw new InvalidArgumentException('bulk_report_identity_invalid');
    if ($payId!==null) {
        $q=$db->prepare("SELECT e.ordinal,e.state,e.remote_username,e.order_id,
            o.id AS linked_id,o.remark,o.userid
            FROM service_effects e LEFT JOIN orders_list o ON o.id=e.order_id
            WHERE e.pay_row_id=? AND e.effect_kind='create' ORDER BY e.ordinal");
        $q->bind_param('i',$payId);
    } else {
        if ($source==='' || strlen($source)>200)
            throw new InvalidArgumentException('bulk_report_source_invalid');
        $kind='admin_bulk';
        $q=$db->prepare("SELECT e.ordinal,e.state,e.remote_username,e.order_id,
            o.id AS linked_id,o.remark,o.userid
            FROM service_effects e LEFT JOIN orders_list o ON o.id=e.order_id
            WHERE e.source_kind=? AND e.source_ref=? AND e.effect_kind='create'
            ORDER BY e.ordinal");
        $q->bind_param('ss',$kind,$source);
    }
    if (!$q->execute()) throw new RuntimeException('bulk_report_query_unavailable');
    $rows=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
    $accounts=[];$seen=[];$invalid=false;
    foreach ($rows as $row) {
        $ordinal=(int)$row['ordinal'];
        if ($ordinal<=0 || $ordinal>$expected || isset($seen[$ordinal]))
            $invalid=true;
        $seen[$ordinal]=true;
        if ($row['state']!=='local_recorded') continue;
        $name=(string)$row['remote_username'];
        if ($name==='' || strlen($name)>256 ||
            (int)$row['order_id']<=0 ||
            (int)$row['order_id']!==(int)$row['linked_id'] ||
            (int)$row['userid']!==$owner ||
            (string)$row['remark']!==$name) {
            $invalid=true;continue;
        }
        $accounts[]=['ordinal'=>$ordinal,'name'=>$name,
            'order_id'=>(int)$row['order_id']];
    }
    usort($accounts,static fn($a,$b)=>$a['ordinal']<=>$b['ordinal']);
    return ['requested'=>$expected,'confirmed'=>count($accounts),
        'complete'=>!$invalid && count($rows)===$expected &&
            count($accounts)===$expected,'accounts'=>$accounts,
        'reference'=>$payId!==null?'#'.$payId:(string)$source];
}

/** Include every account that fits in the actual Telegram message budget.
 * Omitted names are COUNTED, never silently presented as one account.
 */
function achbotBulkReportAppend(string $base,array $proof,bool $html=false): string {
    $expected=(int)($proof['requested']??0);
    $known=(int)($proof['confirmed']??0);
    if ($expected<=0 || $known<0 || $known>$expected ||
        !is_array($proof['accounts']??null))
        throw new InvalidArgumentException('bulk_report_projection_invalid');
    $head="\n\n".achbotMessage(($proof['complete']??false)
        ?'bulk_reporting.complete':'bulk_reporting.needs_review',
        ['confirmed'=>$known,'requested'=>$expected]);
    if ($known>0)$head.="\n".achbotMessage('bulk_reporting.names');
    // strlen is a conservative bound for UTF-16 Telegram characters.
    $limit=4000;
    $body=$base.$head;$shown=0;
    foreach ($proof['accounts'] as $account) {
        $name=(string)($account['name']??'');
        $safe=$html?htmlspecialchars($name,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'):$name;
        $line="\n".(int)$account['ordinal'].'. '.
            ($html?'<code>'.$safe.'</code>':$safe).' (#'.(int)$account['order_id'].')';
        $remaining=count($proof['accounts'])-$shown-1;
        $reserve=$remaining>0?'\n'.achbotMessage('bulk_reporting.more',['count'=>$remaining]):'';
        if (strlen($body.$line.$reserve)>$limit)break;
        $body.=$line;++$shown;
    }
    if ($known>$shown)$body.="\n".achbotMessage('bulk_reporting.more',
        ['count'=>$known-$shown]);
    // If a legacy verbose template exhausted space, preserve the facts in
    // the short standalone report instead of ever claiming one account.
    if (strlen($body)>$limit || $shown===0 && $known>0) {
        $short=achbotMessage('bulk_reporting.short_reference').' '.
            ($html?htmlspecialchars((string)($proof['reference']??''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'):
                (string)($proof['reference']??''));
        if ($short!==$base)return achbotBulkReportAppend($short,$proof,$html);
    }
    return $body;
}
