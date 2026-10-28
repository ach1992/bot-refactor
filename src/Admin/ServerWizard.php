<?php
/** AChBot sequential server onboarding for both current providers. */
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {http_response_code(404);exit;}

function achbotManagementUrl(string $input): string {
    $value=rtrim(trim($input),'/');$p=parse_url($value);
    if (strlen($value)>254 || !$p || empty($p['host']) ||
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
    $panel=['id'=>1,'version'=>'5.5.1','instance_key'=>$v['instance_key']??str_repeat('0',32),
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
function achbotManagementMarzbanCheck(array $v): void {
    $ch=curl_init(rtrim($v['url'],'/').'/api/admin/token');
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query([
        'username'=>$v['username'],'password'=>$v['password']]),
        CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>10,
        CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded','Accept: application/json']]);
    $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$errno=curl_errno($ch);curl_close($ch);
    $r=is_string($raw)?json_decode($raw,true):null;
    if ($errno!==0 || $status!==200 || !is_array($r) || !is_string($r['access_token']??null) ||
        $r['access_token']==='') throw new RuntimeException('management_connection');
}
function achbotServerWizardPrepare(mysqli $db,array &$d): void {
    if ($d['stage']==='groups' && !isset($d['choices'])) {
        $p=achbotServerWizardPanel($db,$d['values']);
        $d['choices']=achbotManagementPgChoices($p);
        $d['values']['groups']=array_values(array_intersect($d['values']['groups']??[],array_column($d['choices'],'id')));
        $d['values']['panel_fingerprint']=hash('sha256',json_encode($p,JSON_THROW_ON_ERROR));
    }
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
        foreach (['marzban','pasarguard'] as $type) $keys[]=[[
            'text'=>achbotManagementText($type),'callback_data'=>achbotManagementButton($d,'provider',$type)]];
    } elseif ($stage==='url' && $v['provider']==='pasarguard') {
        // Saved connections are an optional shortcut INSIDE add-server, not a separate catalog.
        try {
            achbotPgLoad();achbotProviderRequireSchema($db);
            foreach (achbotManagementRows($db,"SELECT id,name FROM provider_panels WHERE provider='pasarguard' AND enabled=1 ORDER BY id LIMIT 30") as $p)
                $keys[]=[['text'=>achbotManagementText('reuse_connection',['name'=>$p['name']]),
                    'callback_data'=>achbotManagementButton($d,'panel',(string)$p['id'])]];
        } catch (Throwable $ignored) {}
    } elseif ($stage==='panelgroup') {
        foreach (achbotMarzbanPanelGroups($db) as $g) $keys[]=[[
            'text'=>$g['name'],'callback_data'=>achbotManagementButton($d,'group',(string)$g['id'])]];
        $keys[]=[['text'=>achbotManagementText('no_shared_group'),'callback_data'=>achbotManagementButton($d,'group','0')]];
    } elseif ($stage==='groups') {
        $keys=achbotManagementChoices($d,$v['groups']??[]);
        $keys[]=[['text'=>achbotManagementText('selection_done'),'callback_data'=>achbotManagementButton($d,'done')]];
    } elseif ($stage==='review') {
        $body=achbotManagementText('server_review',[
            'title'=>achbotManagementEscape($v['title']),'provider'=>achbotManagementText($v['provider']),
            'capacity'=>(string)$v['capacity'],'remark'=>achbotManagementEscape($v['remark']),
            'flag'=>achbotManagementEscape($v['flag']),'url'=>achbotManagementEscape($v['url']),
            'access'=>achbotManagementEscape($v['provider']==='pasarguard'?achbotManagementSelectionSummary(array_map('strval',$v['groups'])):($v['panel_group_name']??achbotManagementText('no_shared_group')))]);
        $keys[]=[['text'=>achbotManagementText('confirm_server'),'callback_data'=>achbotManagementButton($d,'save')]];
        return [$body,$keys];
    }
    return [achbotManagementText('server_'.$stage),$keys];
}
function achbotServerWizardAdvance(mysqli $db,array &$d,string $action,string $input): ?int {
    $v=&$d['values'];$stage=$d['stage'];
    if ($stage==='provider' && $action==='provider' && in_array($input,['marzban','pasarguard'],true)) {
        $v=['provider'=>$input];$d['stage']='title';return null;
    }
    if (in_array($stage,['title','remark','flag','url','username','password','api_key','capacity'],true)) {
        if ($stage==='url' && $v['provider']==='pasarguard' && $action==='panel') {
            unset($v['groups'],$v['panel_fingerprint'],$d['choices'],$d['page']);
            $v['panel_id']=achbotManagementInteger($input);
            $p=achbotServerWizardPanel($db,$v);$v['url']=$p['api_url'];$d['stage']='groups';
            unset($d['choices']);return null;
        }
        if ($action!=='text') throw new RuntimeException('management_input');
        $input=$stage==='password'?$input:trim($input);
        if ($stage==='capacity') $v['capacity']=achbotManagementInteger($input,1,100000000);
        elseif ($stage==='url') {
            $v['url']=achbotManagementUrl($input);unset($v['panel_id'],$v['api_key'],$v['password'],$v['username'],$v['groups'],$v['panel_fingerprint'],$d['choices'],$d['page']);
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
            'url'=>$v['provider']==='pasarguard'?'api_key':'username','username'=>'password',
            'password'=>'panelgroup','api_key'=>'groups'];
        if (in_array($stage,['password','api_key'],true)) {
            if ($stage==='password') achbotManagementMarzbanCheck($v);
            else { $v['instance_key']=bin2hex(random_bytes(16));unset($d['choices'],$v['groups'],$v['panel_fingerprint']); }
        }
        if ($stage==='flag' && !empty($v['panel_id'])) {
            $v['url']=achbotServerWizardPanel($db,$v)['api_url'];$d['stage']='groups';
        } else $d['stage']=$next[$stage];
        return null;
    }
    if ($stage==='panelgroup' && $action==='group') {
        $id=achbotManagementInteger($input,0);$name=null;
        foreach (achbotMarzbanPanelGroups($db) as $g) if ((int)$g['id']===$id) $name=$g['name'];
        if ($id!==0 && $name===null) throw new RuntimeException('management_targets');
        $v['panel_group_id']=$id;$v['panel_group_name']=$name;$d['stage']='review';return null;
    }
    if ($stage==='groups') {
        if ($action==='page') {
            $page=achbotManagementInteger($input,0);
            if ($page*8>=count($d['choices'])) throw new RuntimeException('management_input');
            $d['page']=$page;return null;
        }
        if ($action==='pick') {
            $choice=$d['choices'][achbotManagementInteger($input,0)]??null;
            if (!$choice) throw new RuntimeException('management_targets');
            $ids=$v['groups']??[];$id=$choice['id'];
            $v['groups']=in_array($id,$ids,true)?array_values(array_diff($ids,[$id])):array_merge($ids,[$id]);return null;
        }
        if ($action==='done' && !empty($v['groups'])) {$d['stage']='review';return null;}
    }
    if ($stage!=='review' || $action!=='save') throw new RuntimeException('management_input');
    $provider=$v['provider'];$pid=null;$groupJson=null;
    if ($provider==='pasarguard') {
        $p=achbotServerWizardPanel($db,$v);
        if (!hash_equals($v['panel_fingerprint']??'',hash('sha256',json_encode($p,JSON_THROW_ON_ERROR))))
            throw new RuntimeException('management_connection');
        $allowed=array_column(achbotManagementPgChoices($p),'id');
        if (!$v['groups'] || array_diff($v['groups'],$allowed)) throw new RuntimeException('management_targets');
        $groupJson=json_encode(achbotPgGroupsCanonical($v['groups']),JSON_THROW_ON_ERROR);
        if (!empty($v['panel_id'])) {
            $pid=(int)$v['panel_id'];
            // Pin the exact credential/instance row against concurrent rotation.
            $locked=achbotManagementRow($db,"SELECT * FROM provider_panels WHERE id=? AND enabled=1 FOR UPDATE",'i',[$pid]);
            if (!$locked || $locked['instance_key']!==$p['instance_key'] || $locked['api_key']!==$p['api_key'] ||
                $locked['api_url']!==$p['api_url']) throw new RuntimeException('management_connection');
        } else {
            $s=achbotManagementSql($db,"INSERT INTO provider_panels(provider,instance_key,name,api_url,api_key,version) VALUES('pasarguard',?,?,?,?, '5.5.1')",
                'ssss',[$v['instance_key'],$v['title'],$v['url'],$v['api_key']]);
            $pid=(int)$s->insert_id;$s->close();
        }
    } elseif ($provider==='marzban') achbotManagementMarzbanCheck($v);
    else throw new RuntimeException('management_input');
    if ($provider==='pasarguard') {
        $s=achbotManagementSql($db,'INSERT INTO server_info(title,ucount,remark,flag,active,provider_panel_id,provider_group_ids) VALUES(?,?,?,?,1,?,?)',
            'sissis',[$v['title'],$v['capacity'],$v['remark'],$v['flag'],$pid,$groupJson]);
    } else {
        // Marzban onboarding remains usable without migration 011.
        $s=achbotManagementSql($db,'INSERT INTO server_info(title,ucount,remark,flag,active) VALUES(?,?,?,?,1)',
            'siss',[$v['title'],$v['capacity'],$v['remark'],$v['flag']]);
    }
    $id=(int)$s->insert_id;$s->close();
    $s=achbotManagementSql($db,"INSERT INTO server_config(id,panel_url,ip,sni,header_type,request_header,response_header,security,tlsSettings,username,password,type) VALUES(?,?,'','','none','','','none','',?,?,?)",
        'issss',[$id,$v['url'],$v['username']??'',$v['password']??'',$provider]);$s->close();
    if ($provider==='marzban') achbotSetMarzbanGroup($db,$id,(int)$v['panel_group_id']);
    return $id;
}
