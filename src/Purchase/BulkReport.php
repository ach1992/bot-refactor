<?php
/** Read-only, original-source aggregate for bulk account notices.
 * No new state, provider calls, financial action, or retry authority.
 */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404); exit;
}
require_once __DIR__.'/../../messages/loader.php';

/** Count only confirmed local orders linked to their original 007 effects. */
function achbotBulkRecordedAccounts(mysqli $db,int $expected,int $owner,string $source): array {
    if ($expected<=0 || $owner<=0 || $source==='' || strlen($source)>200)
        throw new InvalidArgumentException('bulk_report_identity_invalid');
    $kind='admin_bulk';
    $q=$db->prepare("SELECT e.ordinal,e.state,e.remote_username,e.order_id,e.actor_userid AS userid
        FROM service_effects e
        WHERE e.source_kind=? AND e.source_ref=? AND e.effect_kind='create' ORDER BY e.ordinal");
    $q->bind_param('ss',$kind,$source);
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
            (int)$row['userid']!==$owner) {
            $invalid=true;continue;
        }
        $accounts[]=['ordinal'=>$ordinal,'name'=>$name,
            'order_id'=>(int)$row['order_id']];
    }
    usort($accounts,static fn($a,$b)=>$a['ordinal']<=>$b['ordinal']);
    return ['requested'=>$expected,'confirmed'=>count($accounts),
        'complete'=>!$invalid && count($rows)===$expected &&
            count($accounts)===$expected,'accounts'=>$accounts,
        'reference'=>$source];
}

/** Keep the existing bounded summary; partition every remaining confirmed
 * account into independently searchable, complete Telegram messages. */
function achbotBulkReportBuild(string $base,array $proof,bool $html=false,int $reservedBytes=0): array {
    $expected=(int)($proof['requested']??0);
    $known=(int)($proof['confirmed']??0);
    if ($expected<=0 || $known<0 || $known>$expected ||
        !is_array($proof['accounts']??null) || count($proof['accounts'])!==$known)
        throw new InvalidArgumentException('bulk_report_projection_invalid');
    $head="\n\n".achbotMessage(($proof['resolved']??false)
        ?'bulk_reporting.resolved_retained':(($proof['complete']??false)
        ?'bulk_reporting.complete':'bulk_reporting.needs_review'),
        ['confirmed'=>$known,'requested'=>$expected]);
    if ($known>0)$head.="\n".achbotMessage('bulk_reporting.names');
    // strlen is a conservative bound for UTF-16 Telegram characters.
    $limit=4000-$reservedBytes;
    if ($limit<=0) throw new InvalidArgumentException('bulk_report_reserved_bytes');
    $body=$base.$head;$shown=0;
    $lines=[];
    foreach ($proof['accounts'] as $account) {
        $name=(string)($account['name']??'');
        $safe=$html?htmlspecialchars($name,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'):$name;
        $line="\n".(int)$account['ordinal'].'. '.
            ($html?'<code>'.$safe.'</code>':$safe).' (#'.(int)$account['order_id'].')';
        $lines[]=$line;
    }
    foreach ($lines as $line) {
        $remaining=count($lines)-$shown-1;
        $reserve=$remaining>0?"\n".achbotMessage('bulk_reporting.more',['count'=>$remaining]):'';
        if (strlen($body.$line.$reserve)>$limit)break;
        $body.=$line;++$shown;
    }
    if ($known>$shown)$body.="\n".achbotMessage('bulk_reporting.more',
        ['count'=>$known-$shown]);
    // The summary always keeps the financial facts, even if every account
    // must be listed on continuation pages. Never silently discard them.
    if (strlen($body)>$limit) throw new RuntimeException('bulk_report_summary_too_long');
    $pages=[];$page='';
    foreach (array_slice($lines,$shown) as $line) {
        if ($page==='' || strlen($page.$line)>$limit) {
            if ($page!=='') $pages[]=$page;
            $params=['page'=>count($pages)+1,'reference'=>(string)($proof['reference']??'')];
            $page=$html?achbotMessageHtml('bulk_reporting.continuation',$params):
                achbotMessage('bulk_reporting.continuation',$params);
        }
        if (strlen($page.$line)>$limit) throw new RuntimeException('bulk_report_line_too_long');
        $page.=$line;
    }
    if ($page!=='') $pages[]=$page;
    return ['summary'=>$body,'pages'=>$pages];
}
