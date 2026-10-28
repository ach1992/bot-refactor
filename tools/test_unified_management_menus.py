#!/usr/bin/env python3
"""AChBot server/plan menus and one-action contextual cancellation on owned MariaDB."""
from __future__ import annotations
import argparse
import json
import subprocess
from pathlib import Path
import test_access_state as owned

ROOT=Path(__file__).resolve().parents[1]
PHP=r'''<?php
error_reporting(E_ALL & ~E_DEPRECATED);
set_error_handler(static function($severity,$message,$file,$line) {
    if ($severity===E_DEPRECATED) return true;
    throw new ErrorException($message,0,$severity,$file,$line);
});
$root=$argv[1];$connection=new mysqli('localhost','root','',$argv[3],0,$argv[2]);
$connection->set_charset('utf8mb4');
require $root.'/messages/loader.php';
require $root.'/src/Purchase/Core.php';
require $root.'/src/Panel/ProviderAdmin.php';
require $root.'/src/Subscription/ServerDisplaySettings.php';
function achbotSupportsPanel($type) {return in_array($type,['marzban','pasarguard'],true);}
$events=[];$checks=0;
function sendMessage(...$args) {$GLOBALS['events'][]=$args;return (object)['ok'=>true];}
function alert(...$args) {$GLOBALS['events'][]=['alert',...$args];}
function yes($condition,$case) {
    if (!$condition) throw new RuntimeException($case);
    ++$GLOBALS['checks'];
}
function callbacks($markup) {
    $data=is_string($markup)?json_decode($markup,true):$markup;
    $all=[];
    foreach ($data['inline_keyboard']??[] as $row) {
        yes(is_array($row) && count($row)>0,'empty keyboard row');
        foreach ($row as $button) if (isset($button['callback_data'])) {
            yes(strlen($button['callback_data'])<=64,'callback length');
            $all[]=$button['callback_data'];
        }
    }
    return $all;
}
function containsAll($actual,$expected,$case) {yes(array_diff($expected,$actual)===[],$case);}
function noCallbacks($actual,$pattern,$case) {
    yes(!array_filter($actual,static fn($value)=>preg_match($pattern,$value)),$case);
}
$config=file_get_contents($root.'/config.php');
foreach ([
    ['function getServerConfigKeys(','function getServerListKeys('],
    ['function getServerListKeys(','function getCategoriesKeys('],
    ['function getPlanDetailsKeys(','// BEGIN create user and admin get order detail'],
] as [$start,$end]) {
    $a=strpos($config,$start);$b=strpos($config,$end,$a);
    eval(str_replace('__DIR__',var_export($root,true),substr($config,$a,$b-$a)));
}
$buttonValues=['back_button'=>'Back','active'=>'On','deactive'=>'Off','cancel'=>'Cancel'];
$removeKeyboard=json_encode(['remove_keyboard'=>true]);$cancelKey=json_encode(['keyboard'=>[['Cancel']]]);
$admin=9001;$from_id=$chat_id=9001;$message_id=10;
$connection->query('ALTER TABLE server_info ADD panel_group_id INT NULL');
$connection->query("INSERT INTO server_info(id,title,ucount,remark,flag,active,state) VALUES
    (1,'Marzban',10,'mb','F',1,1),(2,'PG <title>',20,'pg','G',1,1),(3,'Retired',5,'old','',1,1)");
$connection->query("INSERT INTO server_config(id,panel_url,ip,sni,header_type,request_header,response_header,security,tlsSettings,type,username,password,reality) VALUES
    (1,'https://marzban.invalid','','','none','','','none','','marzban','user','secret','true'),
    (2,'https://pg.invalid','','','none','','','none','','pasarguard','','','true'),
    (3,'https://retired.invalid','','','none','','','none','','retired','','','true')");
$connection->query("INSERT INTO server_plans(id,fileid,catid,server_id,inbound_id,acount,limitip,title,protocol,days,volume,type,price,descr,pic,active,step,date,rahgozar) VALUES
    (41,'',0,1,0,0,1,'MB plan','',30,20,'ws',1000,'Description','',1,10,'1700000000',1),
    (42,'',0,2,0,0,1,'PG plan','',40,30,'ws',2000,'Description','',1,10,'1700000000',1),
    (43,'',0,3,0,0,1,'Old plan','',40,30,'ws',2000,'Description','',1,10,'1700000000',1),
    (44,'',0,1,5,0,1,'Old inbound plan','',40,30,'ws',2000,'Description','',1,10,'1700000000',1)");
$list=callbacks(getServerListKeys());
yes(count(array_filter($list,static fn($value)=>$value==='addManagedServer'))===1,'one common server entry');
noCallbacks($list,'/^(?:addNewMarzbanPanel|pgConnections|pgAddConnection)$/','no connection hierarchy in main list');
containsAll($list,['showServerSettings1_0','showServerSettings2_0'],'server names open management');
foreach ([1,2] as $sid) {
    $keys=callbacks(getServerConfigKeys($sid,15));
    containsAll($keys,['editServerName'.$sid,'editServerFlag'.$sid,'editServerMax'.$sid,
        'editServerRemark'.$sid,'serverDisplay'.$sid,'plansList'.$sid,'newPlanForServer'.$sid,
        'wizwizdeleteserver'.$sid,'nextServerPage15'],'common server actions');
    noCallbacks($keys,'/^(?:pgConnections|addNewMarzbanPanel)$/','server settings do not divert to physical connection list');
}
$pg=callbacks(getServerConfigKeys(2));
containsAll($pg,['pgServerGroups2','pgRotateServerKey2'],'native configuration remains in server context');
noCallbacks($pg,'/^(?:changesServerLoginInfo|editSubLinks)/','PG omits Marzban-only credentials and backup-link synthesis');
$mb=callbacks(getPlanDetailsKeys(41));$pg=callbacks(getPlanDetailsKeys(42));
foreach ([$mb,$pg] as $index=>$keys) {
    $id=$index===0?41:42;
    containsAll($keys,['planDays'.$id,'planVolume'.$id,'wizwizplanname'.$id,
        'wizwizplanrial'.$id,'wizwizplaneditdes'.$id],'common editable product fields');
    noCallbacks($keys,'/^(?:changeCustom|editDestName|editServerNames|editSpiderX|editFlow|wizwizplanslimit|wizwizplansinobundid)/',
        'retired fields absent even when old row flags remain set');
}
containsAll($mb,['marzbanHostSettings41'],'Marzban offers native inbounds');
containsAll($pg,['pgPlanGroups42'],'PG offers native Groups');
noCallbacks($pg,'/^marzbanHostSettings/','PG never offers Marzban inbounds');
yes(getPlanDetailsKeys(999)===null && getPlanDetailsKeys(43)===null && getPlanDetailsKeys(44)===null,
    'missing unsupported and retired-inbound plans fail closed');
$before=$connection->query('SHOW CREATE TABLE server_info')->fetch_row()[1];
$events=[];achbotServerDisplayShow($connection,2);
yes(str_contains($events[0][0],'PG &lt;title&gt;') && !str_contains($events[0][0],'PG <title>') &&
    $events[0][2]==='HTML','display editor identifies and escapes the server');
foreach ([
    ['pg:groups:server:2','serverDisplay2'],
    ['pg:groups:plan:42','planDays42'],
    ['pg:key:10:2','serverDisplay2'],
    ['pg:server:10','addManagedServer'],
    ['pg:connection','addManagedServer'],
] as $index=>[$step,$target]) {
    $stmt=$connection->prepare('UPDATE users SET step=?,temp=? WHERE userid=9001');
    $temporary='private-draft-not-echoed';$stmt->bind_param('ss',$step,$temporary);$stmt->execute();$stmt->close();
    $userInfo=$connection->query('SELECT * FROM users WHERE userid=9001')->fetch_assoc();
    $data='';$text='Cancel';$events=[];
    $update=(object)['update_id'=>100+$index,'message'=>(object)[
        'message_id'=>10,'text'=>'Cancel','from'=>(object)['id'=>9001],
        'chat'=>(object)['id'=>9001,'type'=>'private']]];
    yes(achbotHandleProviderAdmin()===true,'cancel is fully consumed');
    $current=$connection->query('SELECT step,temp FROM users WHERE userid=9001')->fetch_assoc();
    yes($current['step']==='none' && $current['temp']===null && $userInfo['step']==='none','cancel clears persisted and cached draft');
    yes(count($events)===2 && (json_decode($events[0][1],true)['remove_keyboard']??false)===true,
        'cancel removes reply keyboard with one response');
    containsAll(callbacks($events[1][1]),[$target],'cancel returns to edited object');
    yes(!str_contains(json_encode($events),'private-draft-not-echoed'),'cancel never echoes private state');
}
$connection->query("UPDATE users SET step='pg:groups:server:2',isAdmin=0 WHERE userid=9002");
$from_id=$chat_id=9002;$userInfo=['step'=>'pg:groups:server:2','isAdmin'=>1];$data='';$text='Cancel';
$update=(object)['update_id'=>200,'message'=>(object)['message_id'=>10,'text'=>'Cancel',
    'from'=>(object)['id'=>9002],'chat'=>(object)['id'=>9002,'type'=>'private']]];
yes(achbotHandleProviderAdmin()===true,'revoked cached administrator receives safe response');
yes($connection->query('SELECT step FROM users WHERE userid=9002')->fetch_row()[0]==='pg:groups:server:2',
    'revoked administrator cannot mutate state');
$from_id=$chat_id=9001;$userInfo=['step'=>'pg:groups:server:2','isAdmin'=>1];
$connection->query("UPDATE users SET step='protected-new-flow',temp='keep' WHERE userid=9001");
$update=(object)['update_id'=>201,'message'=>(object)['message_id'=>10,'text'=>'Cancel',
    'from'=>(object)['id'=>9001],'chat'=>(object)['id'=>9001,'type'=>'private']]];
yes(achbotHandleProviderAdmin()===true,'stale cancel consumed without generic fallthrough');
yes($connection->query('SELECT step FROM users WHERE userid=9001')->fetch_row()[0]==='protected-new-flow',
    'stale cancel preserves newer workflow');
$connection->query("UPDATE users SET step='pg:groups:server:2' WHERE userid=9001");
$userInfo['step']='pg:groups:server:2';
$update->message->from->id=1001;
yes(achbotHandleProviderAdmin()===true,'forged sender rejected');
yes($connection->query('SELECT step FROM users WHERE userid=9001')->fetch_row()[0]==='pg:groups:server:2',
    'forged cancel makes no write');
yes($connection->query('SHOW CREATE TABLE server_info')->fetch_row()[1]===$before,'no schema mutation by menus or cancellation');
echo json_encode(['complete'=>true,'checks'=>$checks,'network_calls'=>0,
    'database'=>'owned socket MariaDB','optional_011_missing_cancel_proven'=>true],JSON_THROW_ON_ERROR);
'''

def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('--run-disposable',action='store_true',required=True)
    parser.add_argument('--report',type=Path)
    args=parser.parse_args()
    with owned.owned_database() as (work,env):
        runner=work/'unified-management-menus.php'
        runner.write_text(PHP)
        result=subprocess.run(['php','-d','disable_functions=curl_init',str(runner),str(ROOT),
            str(work/'mysql.sock'),owned.NAMES[1]],env=env,capture_output=True,text=True,timeout=45)
        if result.returncode:
            raise RuntimeError(result.stdout[-1500:]+'\n'+result.stderr[-3500:])
        report=json.loads(result.stdout)
    if args.report:args.report.write_text(json.dumps(report,indent=2)+'\n')
    print(json.dumps(report))

if __name__=='__main__':main()
