<?php
/** AChBot sequential server onboarding for both current providers. */
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {http_response_code(404);exit;}

function achbotManagementUrl(string $input,int $max=254): string {
    $value=rtrim(trim($input),'/');$p=parse_url($value);
    if (strlen($value)>$max || !$p || empty($p['host']) ||
        !in_array($p['scheme']??'',['http','https'],true) ||
        isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment']) ||
        preg_match('/[\x00-\x20\x7f]/',$value)) throw new RuntimeException('management_input');
    return $value;
}
function achbotManagementInteger(string $input,int $min=1,int $max=2147483647): int {
    if (preg_match('/^(?:0|[1-9][0-9]*)$/D',trim($input))!==1 ||
        strlen(trim($input))>10 || (int)$input<$min || (int)$input>$max)
        throw new RuntimeException('management_input');
    return (int)$input;
}
function achbotServerWizardPanel(mysqli $db,array $v): array {
    achbotPgLoad();achbotProviderRequireSchema($db);
    if (!empty($v['panel_id'])) return achbotProviderPanel($db,(int)$v['panel_id']);
    $panel=['id'=>1,'version'=>null,'instance_key'=>$v['instance_key']??str_repeat('0',32),
        'api_url'=>$v['url']??'','api_key'=>$v['api_key']??''];
    if (!achbotPgPanelValid($panel)) throw new RuntimeException('management_connection');
    return $panel;
}
function achbotManagementPgChoices(array $panel): array {
    $r=achbotPgCheck($panel);
    if (($r['success']??false)!==true) throw new RuntimeException('management_connection');
    $out=[];
    foreach ($r['groups'] as $group) {
        if ($group['is_disabled'] || (is_array($r['allowed_group_ids']??null) &&
            !in_array($group['id'],$r['allowed_group_ids'],true))) continue;
        $out[]=['id'=>$group['id'],'name'=>$group['name']];
    }
    if (!$out) throw new RuntimeException('management_targets');
    return $out;
}
function achbotServerWizardPrepare(mysqli $db,array &$d): void {
    // Native PasarGuard Groups are configured only at plan level.
}
function achbotManagementChoices(array $d,array $selected,string $action='pick'): array {
    $rows=[];$choices=$d['choices']??[];
    $page=max(0,(int)($d['page']??0));$size=8;
    foreach (array_slice($choices,$page*$size,$size,true) as $index=>$choice) {
        $rows[]=[['text'=>(in_array($choice['id'],$selected,true)?achbotManagementText('selected'):'').mb_substr($choice['name'],0,80),
            'callback_data'=>achbotManagementButton($d,$action,(string)$index)]];
    }
    $nav=[];
    if ($page>0) $nav[]=['text'=>achbotManagementText('previous_page'),'callback_data'=>achbotManagementButton($d,'page',(string)($page-1))];
    if (($page+1)*$size<count($choices)) $nav[]=['text'=>achbotManagementText('next_page'),'callback_data'=>achbotManagementButton($d,'page',(string)($page+1))];
    if ($nav) $rows[]=$nav;
    return $rows;
}
function achbotServerWizardView(mysqli $db,array $d): array {
    $v=$d['values'];$stage=$d['stage'];$keys=[];
    if ($stage==='provider') {
        foreach (['pasarguard'] as $type) $keys[]=[[
            'text'=>achbotManagementText($type),'callback_data'=>achbotManagementButton($d,'provider',$type)]];
    } elseif ($stage==='url' && $v['provider']==='pasarguard') {
        // Saved connections are an optional shortcut INSIDE add-server, not a separate catalog.
        try {
            achbotPgLoad();achbotProviderRequireSchema($db);
            $connections=achbotProviderConnections($db);
            // Real pages, not an arbitrary total-connections ceiling or a
            // single Telegram keyboard with unbounded buttons.
            $page=max(0,(int)($d['page']??0));$size=8;
            $total=count($connections);
            if ($page>0 && $page*$size>=$total) $page=max(0,intdiv(max(0,$total-1),$size));
            foreach (array_slice($connections,$page*$size,$size) as $p)
                $keys[]=[['text'=>achbotManagementText('reuse_connection',['name'=>$p['name']]),
                    'callback_data'=>achbotManagementButton($d,'panel',(string)$p['id'])]];
            $nav=[];
            if ($page>0) $nav[]=['text'=>achbotManagementText('previous_page'),
                'callback_data'=>achbotManagementButton($d,'page',(string)($page-1))];
            if (($page+1)*$size<$total) $nav[]=['text'=>achbotManagementText('next_page'),
                'callback_data'=>achbotManagementButton($d,'page',(string)($page+1))];
            if ($nav) $keys[]=$nav;
        } catch (Throwable $ignored) {}
    } elseif ($stage==='panelgroup') {
        foreach (achbotPanelGroups($db) as $g) $keys[]=[[
            'text'=>$g['name'],'callback_data'=>achbotManagementButton($d,'group',(string)$g['id'])]];
        $keys[]=[['text'=>achbotManagementText('no_shared_group'),'callback_data'=>achbotManagementButton($d,'group','0')]];
    } elseif ($stage==='review') {
        $body=achbotManagementText('server_review',[
            'title'=>achbotManagementEscape($v['title']),'provider'=>achbotManagementText($v['provider']),
            'capacity'=>(string)$v['capacity'],'remark'=>achbotManagementEscape($v['remark']),
            'flag'=>achbotManagementEscape($v['flag']),'url'=>achbotManagementEscape($v['url']),
            'access'=>achbotManagementEscape($v['panel_group_name']??achbotManagementText('no_shared_group'))]);
        $keys[]=[['text'=>achbotManagementText('confirm_server'),'callback_data'=>achbotManagementButton($d,'save')]];
        return [$body,$keys];
    }
    return [achbotManagementText('server_'.$stage),$keys];
}
function achbotServerWizardAdvance(mysqli $db,array &$d,string $action,string $input): ?int {
    $v=&$d['values'];$stage=$d['stage'];
    if ($stage==='provider' && $action==='provider' && in_array($input,['pasarguard'],true)) {
        $v=['provider'=>$input];$d['stage']='title';return null;
    }
    if (($v['provider']??null)!=='pasarguard') throw new RuntimeException('management_input');
    if (in_array($stage,['title','remark','flag','url','api_key','capacity'],true)) {
        if ($stage==='url' && $v['provider']==='pasarguard' && $action==='page') {
            $page=achbotManagementInteger($input,0);
            $count=count(achbotProviderConnections($db));$size=8;
            if ($page>0 && $page*$size>=$count-1) throw new RuntimeException('management_input');
            $d['page']=$page;
            return null;
        }
        if ($stage==='url' && $v['provider']==='pasarguard' && $action==='panel') {
            unset($v['panel_fingerprint'],$d['choices'],$d['page']);
            $v['panel_id']=achbotManagementInteger($input);
            $p=achbotServerWizardPanel($db,$v);$v['url']=$p['api_url'];
            $v['panel_fingerprint']=hash('sha256',json_encode($p,JSON_THROW_ON_ERROR));
            $d['stage']='panelgroup';return null;
        }
        if ($action!=='text') throw new RuntimeException('management_input');
        $input=trim($input);
        if ($stage==='capacity') $v['capacity']=achbotManagementInteger($input);
        elseif ($stage==='url') {
            $v['url']=achbotManagementUrl($input,1000);unset($v['panel_id'],$v['api_key'],$v['password'],$v['username'],$v['panel_fingerprint'],$d['choices'],$d['page']);
        } elseif ($stage==='remark') {
            if (preg_match('/^[a-zA-Z0-9_-]{1,60}$/D',$input)!==1) throw new RuntimeException('management_input');
            $v['remark']=$input;
        } else {
            $max=$stage==='api_key'?4096:100;
            if ($input==='' || mb_strlen($input)>$max || preg_match('/[\x00-\x1f\x7f]/',$input))
                throw new RuntimeException('management_input');
            $v[$stage]=$input;
        }
        $next=['title'=>'capacity','capacity'=>'remark','remark'=>'flag','flag'=>'url',
            'url'=>'api_key','api_key'=>'panelgroup'];
        if ($stage==='api_key') {
            $v['instance_key']=bin2hex(random_bytes(16));
            $p=achbotServerWizardPanel($db,$v);
            $v['panel_fingerprint']=hash('sha256',json_encode($p,JSON_THROW_ON_ERROR));
        }
        if ($stage==='flag' && !empty($v['panel_id'])) {
            $p=achbotServerWizardPanel($db,$v);
            $v['url']=$p['api_url'];
            $v['panel_fingerprint']=hash('sha256',json_encode($p,JSON_THROW_ON_ERROR));
            $d['stage']='panelgroup';
        } else $d['stage']=$next[$stage];
        return null;
    }
    if ($stage==='panelgroup' && $action==='group') {
        $id=achbotManagementInteger($input,0);$name=null;
        foreach (achbotPanelGroups($db) as $g) if ((int)$g['id']===$id) $name=$g['name'];
        if ($id!==0 && $name===null) throw new RuntimeException('management_targets');
        $v['panel_group_id']=$id;$v['panel_group_name']=$name;$d['stage']='review';return null;
    }
    if ($stage!=='review' || $action!=='save') throw new RuntimeException('management_input');
    $provider=$v['provider'];$pid=null;
    if ($provider==='pasarguard') {
        $p=achbotServerWizardPanel($db,$v);
        if (!hash_equals($v['panel_fingerprint']??'',hash('sha256',json_encode($p,JSON_THROW_ON_ERROR))))
            throw new RuntimeException('management_connection');
        // Verify API authentication; native Group membership is plan-scoped.
        $check=achbotPgCheck($p);
        if (($check['success']??false)!==true) throw new RuntimeException('management_connection');
        if (!empty($v['panel_id'])) {
            $pid=(int)$v['panel_id'];
            // Pin the exact credential/instance row against concurrent rotation.
            $locked=achbotProviderPanel($db,$pid,true);
            if ($locked['instance_key']!==$p['instance_key'] || $locked['api_key']!==$p['api_key'] ||
                $locked['api_url']!==$p['api_url']) throw new RuntimeException('management_connection');
        }
    } else throw new RuntimeException('management_input');
    // Both providers use the same logical server row; native access stays on plans.
    $s=achbotManagementSql($db,'INSERT INTO server_info(title,ucount,remark,flag,active) VALUES(?,?,?,?,1)',
        'siss',[$v['title'],$v['capacity'],$v['remark'],$v['flag']]);
    $id=(int)$s->insert_id;$s->close();
    if ($provider==='pasarguard') {
        // Each logical server owns its connection; reused aliases keep the same stable instance.
        $pid=$pid??$id;
        $s=achbotManagementSql($db,"INSERT INTO server_config(id,panel_url,ip,sni,header_type,request_header,response_header,security,tlsSettings,username,password,type,
            provider_connection_id,provider_instance_key,provider_version,provider_enabled)
            VALUES(?,?,'','','none','','','none','','',?,'pasarguard',?,?,?,1)",
            'ississ',[$id,$v['url'],$p['api_key'],$pid,$p['instance_key'],$check['version']]);$s->close();
    }
    achbotSetPanelGroup($db,$id,(int)$v['panel_group_id']);
    return $id;
}
