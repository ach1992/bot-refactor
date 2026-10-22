#!/usr/bin/env python3
"""Real trial and administrator bulk handlers, isolated PHP/MariaDB + fake wire.

No production updates, real customers, real Marzban, or payment operations.
"""
from __future__ import annotations
import argparse,json,subprocess
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path

import test_access_state as owned
import test_v6_native as native
import test_v8_native as purchase

ROOT=Path(__file__).resolve().parents[1]
DB=purchase.SIDE[1]
PHP=r'''<?php
error_reporting(E_ALL & ~E_DEPRECATED);
set_error_handler(static function($n,$m){throw new ErrorException($m,0,$n);});
$_SERVER['SCRIPT_FILENAME']=__FILE__;
$root=$argv[1];$spec=json_decode($argv[4],true,512,JSON_THROW_ON_ERROR);
$connection=new mysqli('localhost','root','',$argv[3],0,$argv[2]);
$connection->set_charset('utf8mb4');
$actor=(int)($spec['actor']??1001);
$from_id=$actor;$admin=9001;$chat_id=$actor;$message_id=92;
$data=(string)($spec['data']??'freeTrial8_normal');
$text=(string)($spec['text']??'');
$updateId=(int)($spec['update_id']??987650);
$sender=(object)['id'=>(int)($spec['wire_actor']??$actor),'is_bot'=>false];
$chat=(object)['id'=>(int)($spec['wire_chat']??$actor),
    'type'=>(string)($spec['chat_type']??'private')];
$message=(object)['message_id'=>92,'chat'=>$chat,'from'=>$sender];
$update=(object)['update_id'=>$updateId];
if (($spec['route']??'trial')==='trial') {
    $message->text='Previously delivered bot menu';
    $update->callback_query=(object)['data'=>$data,'from'=>$sender,
        'message'=>$message];
} else {
    $message->text=$text;
    $update->message=$message;
}
$botState=['remark'=>'digits','rewardChannel'=>null];
$buttonValues=['cancel'=>'cancel','start_bot'=>'start','back_to_main'=>'back'];
$mainValues=['sending_config_to_user'=>'Working',
 'send_only_number'=>'Enter integer','can_create_specific_account'=>'Limit AMOUNT',
 'enter_account_amount'=>'Enter amount','out_of_server_capacity'=>'No capacity',
 'out_of_connection_capacity'=>'No inbound capacity'];
$botUrl='https://fixture.invalid/';$botUsername='fixture';
$linkIndices=[];$hideConfigIfMany=false;
$cancelKey='cancel';$first_name='fixture';$username='fixture';$match=[];
$userQ=$connection->prepare('SELECT * FROM users WHERE userid=?');
$userQ->bind_param('i',$actor);$userQ->execute();
$userInfo=$userQ->get_result()->fetch_assoc();$userQ->close();
if (!empty($spec['force_cached_step'])) $userInfo['step']='createAccAmount7_30_2';
$GLOBALS['events']=[];$GLOBALS['spec']=$spec;
register_shutdown_function(static function(){
    echo json_encode(['events'=>$GLOBALS['events'],'fatal'=>error_get_last()],
       JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
});
function alert(...$args){$GLOBALS['events'][]=['name'=>'ALERT','text'=>$args[0]??''];}
function delMessage(...$a){$GLOBALS['events'][]=['name'=>'DELETE_MESSAGE'];}
function setUser($value='none',$field='step'){
    global $connection,$from_id;
    if (!in_array($field,['step','temp','freetrial'],true)) throw new RuntimeException('bad_column');
    $stmt=$connection->prepare("UPDATE users SET `$field`=? WHERE userid=?");
    $stmt->bind_param('si',$value,$from_id);
    if (!$stmt->execute()) throw new RuntimeException('step_write_failed');
    $stmt->close();
}
function sendMessage($body=null,$keys=null,$format=null,$to=null){
    $GLOBALS['events'][]=['name'=>'MESSAGE','to'=>$to,'text'=>(string)$body];
    return (object)['ok'=>true];
}
function sendQrFromLink(...$args) {
    $GLOBALS['events'][]=['name'=>'DELIVER','uid'=>$args[4]??null];
    if (($GLOBALS['spec']['notice']??'')==='throw')
        throw new RuntimeException('lost_qr_ack');
    if (($GLOBALS['spec']['notice']??'')==='false') return false;
    return true;
}
function getMainKeys(){return '{}';}
function calculateAgentPrice($userInfo,$botState,$planId,$serverId,$buyType,$price){
    return [(int)$price,false,0];
}
function getServerInfoAndConfig($connection,$sid) {
    $stmt=$connection->prepare("SELECT i.remark,c.* FROM server_info i
        JOIN server_config c ON c.id=i.id WHERE i.id=?");
    $stmt->bind_param('i',$sid);$stmt->execute();
    $row=$stmt->get_result()->fetch_assoc();$stmt->close();
    return [['remark'=>$row['remark'],'title'=>'Test server'], $row];
}
function buildRemark(...$args){
    return 'source_'.(int)$args[3].'_'.substr(hash('sha256',
        ($GLOBALS['spec']['route']??'trial').'_'.
        (int)($GLOBALS['spec']['update_id']??987650)),0,12);
}
function generateRandomString(){return 'fixtureuuid';}
function getNextPortAndIncrease(){return [12345,0];}
function createServerAccount(...$args) {
    $GLOBALS['events'][]=['name'=>'POST','remark'=>$args[4]];
    $count=($GLOBALS['posts']??0)+1;$GLOBALS['posts']=$count;
    $remote=[
        'username'=>(string)$args[4],'note'=>'achbot:v1:'.(string)$args[14],
        'expire'=>(int)$args[15],'data_limit'=>(int)$args[5]*1073741824];
    $GLOBALS['remote'][(string)$args[4]]=$remote;
    if (($GLOBALS['spec']['remote']??'ok')==='lost' ||
       (($GLOBALS['spec']['remote']??'ok')==='second_lost' && $count===2))
        throw new RuntimeException('fixture_post_unknown');
    return [(object)['success'=>true,'sub_link'=>'/sub/fixture','vray_links'=>['vless://fixture']],$args[4],12345];
}
function getMarzbanUser($serverId,$remark,$token=null,$strict=false) {
    if (!$strict) throw new RuntimeException('strict_read_required');
    $GLOBALS['events'][]=['name'=>'GET','remark'=>$remark];
    return isset($GLOBALS['remote'][$remark])?(object)$GLOBALS['remote'][$remark]:null;
}
function buildLinksAndSendQr(...$args) {
    if (end($args)!==false)
        throw new RuntimeException('premature_link_delivery');
    $GLOBALS['events'][]=['name'=>'BUILD'];
    return ['token','["vless://fixture"]','uuid','Fixture account','{}','vless://fixture'];
}
function ensureAccountCreationSucceeded($response,$serverInfo,$inbound,$admin,$throw=false) {
    if (!is_object($response) || ($response->success??false)!==true)
        throw new RuntimeException('fixture_remote_unconfirmed');
}
require_once $root.'/src/Purchase/Core.php';
require_once $root.'/src/Purchase/OrderWrites.php';
require_once $root.'/src/Finance/ServiceEffects.php';
if (($spec['route']??'trial')==='trial') {
    require $root.'/src/Purchase/TrialProvision.php';
} else {
    require $root.'/src/Purchase/EntryAndBulk.php';
}
'''

def sql(work,env,cmd):
    return native.sql(work,env,cmd,DB).strip()

def php(work,env,driver,**spec):
    p=subprocess.run(['php','-n','-d','extension=mysqlnd','-d','extension=mysqli',
        '-d','disable_functions=curl_init,curl_exec,exec,shell_exec,system,passthru,popen,proc_open,mail',
        str(driver),str(ROOT),str(work/'mysql.sock'),DB,json.dumps(spec)],
        cwd=work,env=env,capture_output=True,text=True,timeout=25)
    if p.returncode:raise RuntimeError('PHP fatal '+repr((p.returncode,p.stderr[-900:],p.stdout[-700:])))
    data=json.loads(p.stdout)
    if data.get('fatal'):raise RuntimeError('Native PHP fatal '+str(data['fatal'])[:400])
    return data['events']

def check():
    cases=[]
    with owned.owned_database() as (work,env):
        purchase.init(work,env,'http://127.0.0.1:9')
        sql(work,env,(ROOT/'migrations/post-v9/001_marzban_panel_group_id.sql').read_text())
        sql(work,env,(ROOT/'migrations/post-v9/007_service_effects.sql').read_text())
        driver=work/'nonpaid-route.php';driver.write_text(PHP)
        def reset():
            sql(work,env,'DELETE FROM service_effects')
            purchase.init(work,env,'http://127.0.0.1:9')
            sql(work,env,'UPDATE users SET freetrial=NULL WHERE userid=1001')
        def capacity():
            return int(sql(work,env,'SELECT ucount FROM server_info WHERE id=2'))
        def effects(kind):
            return sql(work,env,f"SELECT ordinal,state,reserved_count FROM service_effects WHERE source_kind='{kind}' ORDER BY ordinal")
        trial=php(work,env,driver,route='trial')
        assert [e['name'] for e in trial if e['name'] in ('POST','GET','DELIVER')]==['POST','GET','DELIVER'],trial
        assert sql(work,env,'SELECT freetrial FROM users WHERE userid=1001')=='used'
        assert capacity()==9 and effects('free_trial')=='1\tlocal_recorded\t1'
        assert not any(e['name']=='POST' for e in php(work,env,driver,route='trial',update_id=987651))
        cases.append('actual_trial_route_reserved_once_with_real_order_and_delivery')

        reset()
        bad=php(work,env,driver,route='trial',remote='lost')
        assert len([e for e in bad if e['name']=='POST'])==1
        assert not any(e['name']=='DELIVER' for e in bad)
        assert capacity()==9 and effects('free_trial')=='1\tdispatching\t1'
        assert sql(work,env,'SELECT freetrial FROM users WHERE userid=1001')=='reserved'
        assert not any(e['name']=='POST' for e in php(work,env,driver,route='trial',update_id=987652))
        cases.append('actual_trial_unknown_remote_retained_fence_and_capacity')

        reset()
        denied=php(work,env,driver,route='trial',wire_actor=9002)
        assert not any(e['name']=='POST' for e in denied)
        assert capacity()==10
        cases.append('forged_trial_update_no_dispatch')

        reset()
        sql(work,env,"UPDATE users SET step='createAccAmount7_30_2' WHERE userid=9001")
        bulk=php(work,env,driver,route='bulk',actor=9001,text='2')
        assert len([e for e in bulk if e['name']=='POST'])==2,bulk
        assert len([e for e in bulk if e['name']=='DELIVER'])==2
        assert capacity()==8 and effects('admin_bulk')=='1\tlocal_recorded\t2\n2\tlocal_recorded\t0'
        assert not any(e['name']=='POST' for e in php(work,env,driver,route='bulk',
            actor=9001,text='2',force_cached_step=True))
        cases.append('actual_admin_bulk_two_accounts_full_reservation_one_batch')

        reset()
        sql(work,env,"UPDATE users SET step='createAccAmount7_30_2' WHERE userid=9001")
        partial=php(work,env,driver,route='bulk',actor=9001,text='2',remote='second_lost')
        assert len([e for e in partial if e['name']=='POST'])==2
        assert not any(e['name']=='DELIVER' for e in partial)
        assert capacity()==8 and effects('admin_bulk')=='1\tlocal_recorded\t2\n2\tdispatching\t0'
        assert not any(e['name']=='POST' for e in php(work,env,driver,route='bulk',
            actor=9001,text='2',force_cached_step=True))
        cases.append('actual_admin_bulk_partial_retains_capacity_and_audit')

        reset()
        sql(work,env,"UPDATE users SET step='createAccAmount7_30_2' WHERE userid=9001")
        sql(work,env,"UPDATE users SET isAdmin=1,step='createAccAmount7_30_2' WHERE userid=9002")
        sql(work,env,'UPDATE server_info SET ucount=1 WHERE id=2')
        with ThreadPoolExecutor(max_workers=2) as ex:
            pair=list(ex.map(lambda actor:php(work,env,driver,route='bulk',
                actor=actor,text='1',update_id=987650+actor),[9001,9002]))
        assert sum(sum(e['name']=='POST' for e in x) for x in pair)==1,pair
        assert capacity()==0
        cases.append('two_real_admin_batches_race_one_available_server_slot')

        reset()
        sql(work,env,"UPDATE users SET step='createAccAmount7_30_2' WHERE userid=9001")
        sql(work,env,"CREATE TRIGGER fixture_effect_insert_block BEFORE INSERT ON service_effects "
            "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='disposable_failure'")
        try: blocked=php(work,env,driver,route='bulk',actor=9001,text='1')
        finally: sql(work,env,'DROP TRIGGER fixture_effect_insert_block')
        assert not any(e['name']=='POST' for e in blocked)
        assert capacity()==10 and effects('admin_bulk')==''
        cases.append('nonpaid_source_db_insert_failure_before_post_no_capacity_loss')

        reset()
        delivered=php(work,env,driver,route='trial',notice='false')
        assert len([e for e in delivered if e['name']=='POST'])==1
        assert sql(work,env,'SELECT freetrial FROM users WHERE userid=1001')=='used'
        assert effects('free_trial')=='1\tlocal_recorded\t1'
        assert not any(e['name']=='POST' for e in php(work,env,driver,route='trial'))
        cases.append('trial_postcommit_telegram_failure_no_recreate')

    return dict(complete=True,cases=len(cases),scenarios=cases,source_real_php=True,
        socket_only=True,production_mutations=0,provider_requests=0)

if __name__=='__main__':
    p=argparse.ArgumentParser()
    p.add_argument('--run-disposable',action='store_true',required=True)
    p.add_argument('--report',type=Path)
    a=p.parse_args()
    r=check()
    if a.report:a.report.write_text(json.dumps(r,indent=2)+'\n')
    print(json.dumps(r))
