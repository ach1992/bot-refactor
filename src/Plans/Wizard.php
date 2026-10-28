<?php
/** AChBot plan drafts: common product fields, native provider entitlement selection. */
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {http_response_code(404);exit;}

function achbotPlanWizardServer(mysqli $db,int $id,bool $lock=false): array {
    $r=achbotManagementRow($db,'SELECT i.id,i.title,i.active,c.type,c.panel_url,c.username,c.password
        FROM server_info i JOIN server_config c ON c.id=i.id WHERE i.id=?'.($lock?' FOR UPDATE':''),'i',[$id]);
    if (!$r || (int)$r['active']!==1 || !achbotSupportsAccount($r['type'],0))
        throw new RuntimeException('management_targets');
    return $r;
}
function achbotPlanWizardFingerprint(mysqli $db,array $server): string {
    $identity=[$server['id'],$server['type'],$server['panel_url'],$server['username'],$server['password']];
    if ($server['type']==='pasarguard') $identity[]=achbotPgServerPanel($db,(int)$server['id']);
    return hash('sha256',json_encode($identity,JSON_THROW_ON_ERROR));
}
function achbotPlanWizardChoices(mysqli $db,array $server): array {
    if ($server['type']==='pasarguard') return achbotManagementPgChoices(achbotPgServerPanel($db,(int)$server['id']));
    $r=getMarzbanHosts((int)$server['id']);
    if (!is_object($r) || !is_array($r->inbounds??null)) throw new RuntimeException('management_connection');
    $out=[];
    foreach ($r->inbounds as $host) {
        if (!is_object($host) || !is_string($host->tag??null) || $host->tag==='' ||
            !in_array($host->protocol??null,['vmess','vless','trojan','shadowsocks'],true)) continue;
        $id=hash('sha256',$host->protocol."\0".$host->tag);
        $out[]=['id'=>$id,'name'=>$host->tag,'protocol'=>$host->protocol,'tag'=>$host->tag];
    }
    if (!$out) throw new RuntimeException('management_targets');
    return $out;
}
function achbotPlanWizardPrepare(mysqli $db,array &$d): void {
    if ($d['stage']!=='targets' || isset($d['choices'])) return;
    $server=achbotPlanWizardServer($db,(int)$d['values']['server']);
    if (!hash_equals($d['values']['server_fingerprint'],achbotPlanWizardFingerprint($db,$server)))
        throw new RuntimeException('management_targets');
    $d['choices']=achbotPlanWizardChoices($db,$server);
    $d['values']['targets']=array_values(array_intersect($d['values']['targets']??[],array_column($d['choices'],'id')));
}
function achbotPlanWizardView(mysqli $db,array $d): array {
    $v=$d['values'];$stage=$d['stage'];$keys=[];
    if ($stage==='category') {
        foreach (achbotManagementRows($db,'SELECT id,title FROM server_categories WHERE active=1 AND parent=0 ORDER BY id') as $row)
            $keys[]=[['text'=>$row['title'],'callback_data'=>achbotManagementButton($d,'category',(string)$row['id'])]];
        if (!$keys) return [achbotManagementText('no_categories'),[]];
    } elseif ($stage==='server') {
        foreach (achbotManagementRows($db,"SELECT i.id,i.title,c.type FROM server_info i JOIN server_config c ON c.id=i.id WHERE i.active=1 AND c.type IN (".achbotProviderSqlTypes().") ORDER BY i.id") as $row)
            $keys[]=[['text'=>achbotManagementText('server_choice',['title'=>$row['title'],'provider'=>achbotManagementText($row['type'])]),
                'callback_data'=>achbotManagementButton($d,'server',(string)$row['id'])]];
        if (!$keys) return [achbotManagementText('no_servers'),[]];
    } elseif ($stage==='targets') {
        $keys=achbotManagementChoices($d,$v['targets']??[]);
        if ($v['provider']==='pasarguard') $keys[]=[[
            'text'=>achbotManagementText('inherit_groups'),'callback_data'=>achbotManagementButton($d,'inherit')]];
        $keys[]=[['text'=>achbotManagementText('selection_done'),'callback_data'=>achbotManagementButton($d,'done')]];
        return [achbotManagementText($v['provider']==='pasarguard'?'plan_groups':'plan_inbounds'),$keys];
    } elseif ($stage==='review') {
        $body=achbotManagementText('plan_review',[
            'title'=>achbotManagementEscape($v['title']),'price'=>number_format($v['price']),
            'category'=>achbotManagementEscape($v['category_name']),'server'=>achbotManagementEscape($v['server_name']),
            'days'=>(string)$v['days'],'volume'=>(string)$v['volume'],
            'description'=>achbotManagementEscape($v['description']),
            'access'=>achbotManagementEscape(!empty($v['inherit'])?achbotManagementText('inherit_groups'):
                achbotManagementSelectionSummary(array_column(array_filter($d['choices'],static fn($c)=>in_array($c['id'],$v['targets']??[],true)),'name')))]);
        $keys[]=[['text'=>achbotManagementText('confirm_plan'),'callback_data'=>achbotManagementButton($d,'save')]];
        return [$body,$keys];
    }
    return [achbotManagementText('plan_'.$stage),$keys];
}
function achbotPlanWizardAdvance(mysqli $db,array &$d,string $action,string $input): ?int {
    $v=&$d['values'];$stage=$d['stage'];
    if (in_array($stage,['title','price','days','volume','description'],true)) {
        if ($action!=='text') throw new RuntimeException('management_input');
        $input=trim($input);
        if ($stage==='price') $v['price']=achbotManagementInteger($input,0);
        elseif ($stage==='days') {
            $v['days']=achbotManagementInteger($input,1,36500);
            if (time()+$v['days']*86400>2147483647) throw new RuntimeException('management_input');
        } elseif ($stage==='volume') $v['volume']=achbotManagementInteger($input,1,1000000);
        else {
            if ($input==='' || mb_strlen($input)>($stage==='description'?1000:150))
                throw new RuntimeException('management_input');
            $v[$stage]=$input;
        }
        $next=['title'=>'price','price'=>'category','days'=>'volume','volume'=>'description','description'=>'targets'];
        $d['stage']=$next[$stage];return null;
    }
    if ($stage==='category' && $action==='category') {
        $id=achbotManagementInteger($input);
        $row=achbotManagementRow($db,'SELECT title FROM server_categories WHERE id=? AND active=1 AND parent=0','i',[$id]);
        if (!$row) throw new RuntimeException('management_targets');
        $v['category']=$id;$v['category_name']=$row['title'];$d['stage']='server';
        if (!empty($v['preferred_server'])) {
            $server=achbotPlanWizardServer($db,(int)$v['preferred_server']);
            $v['server']=(int)$server['id'];$v['server_name']=$server['title'];$v['provider']=$server['type'];
            $v['server_fingerprint']=achbotPlanWizardFingerprint($db,$server);$d['stage']='days';
        }
        return null;
    }
    if ($stage==='server' && $action==='server') {
        $id=achbotManagementInteger($input);$server=achbotPlanWizardServer($db,$id);
        $v['server']=$id;$v['server_name']=$server['title'];$v['provider']=$server['type'];
        $v['server_fingerprint']=achbotPlanWizardFingerprint($db,$server);
        unset($v['targets'],$v['inherit'],$d['choices'],$d['page']);
        $d['stage']='days';return null;
    }
    if ($stage==='targets') {
        if ($action==='page') {
            $page=achbotManagementInteger($input,0);
            if ($page*8>=count($d['choices'])) throw new RuntimeException('management_input');
            $d['page']=$page;return null;
        }
        if ($action==='pick') {
            $choice=$d['choices'][achbotManagementInteger($input,0)]??null;
            if (!$choice) throw new RuntimeException('management_targets');
            $ids=$v['targets']??[];$id=$choice['id'];
            $v['targets']=in_array($id,$ids,true)?array_values(array_diff($ids,[$id])):array_merge($ids,[$id]);
            $v['inherit']=false;return null;
        }
        if ($action==='inherit' && $v['provider']==='pasarguard') {
            // Validate the actual inherited Group defaults before showing a confirmation.
            $snapshot=achbotPgPlanSnapshot($db,(int)$v['server'],0);
            if (!$snapshot['group_ids']) throw new RuntimeException('management_targets');
            $v['inherit']=true;$d['stage']='review';return null;
        }
        if ($action==='done' && !empty($v['targets'])) {$v['inherit']=false;$d['stage']='review';return null;}
    }
    if ($stage!=='review' || $action!=='save') throw new RuntimeException('management_input');
    $server=achbotPlanWizardServer($db,(int)$v['server'],true);
    if (!hash_equals($v['server_fingerprint'],achbotPlanWizardFingerprint($db,$server)))
        throw new RuntimeException('management_targets');
    if (!achbotManagementRow($db,'SELECT id FROM server_categories WHERE id=? AND active=1 AND parent=0 FOR UPDATE','i',[$v['category']]))
        throw new RuntimeException('management_targets');
    $choices=achbotPlanWizardChoices($db,$server);$allowed=array_column($choices,'id');
    $selected=$v['targets']??[];
    if (empty($v['inherit']) && (!$selected || array_diff($selected,$allowed)))
        throw new RuntimeException('management_targets');
    $custom='';$groups=null;
    if ($server['type']==='pasarguard') {
        if (!empty($v['inherit'])) {
            $snapshot=achbotPgPlanSnapshot($db,(int)$v['server'],0);
            if (array_diff($snapshot['group_ids'],$allowed)) throw new RuntimeException('management_targets');
        } else $groups=json_encode(achbotPgGroupsCanonical($selected),JSON_THROW_ON_ERROR);
    } else {
        $inbounds=[];$proxies=[];
        foreach ($choices as $choice) if (in_array($choice['id'],$selected,true)) {
            $protocol=$choice['protocol'];$inbounds[$protocol][]=$choice['tag'];
            $proxies[$protocol]=match($protocol) {
                'vless'=>['flow'=>''],'shadowsocks'=>['method'=>'chacha20-ietf-poly1305'],default=>new stdClass()
            };
        }
        $custom=json_encode(['inbounds'=>$inbounds,'proxies'=>$proxies],JSON_THROW_ON_ERROR);
    }
    $sql="INSERT INTO server_plans(fileid,catid,server_id,inbound_id,acount,limitip,title,protocol,days,volume,type,price,descr,pic,active,step,date,custom_sni";
    $columns=$server['type']==='pasarguard'?',provider_group_ids':'';
    $sql.=$columns.") VALUES('',?,?,0,0,1,?,'',?,?,'ws',?,?,'',1,10,?,?".($columns!==''?',?':'').")";
    $args=[$v['category'],$v['server'],$v['title'],$v['days'],$v['volume'],$v['price'],$v['description'],(string)time(),$custom];
    $types='iisiiisss';
    if ($columns!=='') {$types.='s';$args[]=$groups;}
    $s=achbotManagementSql($db,$sql,$types,$args);$id=(int)$s->insert_id;$s->close();
    return $id;
}
