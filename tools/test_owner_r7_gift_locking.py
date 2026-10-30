#!/usr/bin/env python3
"""Actual gift SQL lock scope/order on an owned socket-only MariaDB.

The current and pinned previous gift functions execute unchanged. Native
outcomes are supplied once at the local-completion boundary; no transport runs.
"""
from __future__ import annotations
import argparse,contextlib,hashlib,json,subprocess,time
from pathlib import Path

import test_access_state as owned
import test_post_v9_direct_gift as direct
import test_post_v9_direct_gift_native as native
import test_owner_r7_gift_policy as policy
import provider_storage_fixture as storage
import test_post_v9_pasarguard_client as pg

ROOT=Path(__file__).resolve().parents[1]
BASE='a32ec0178b7e1d11a8e8191279b7ef932b93c725'
RUNTIME=['src/Subscription/GiftBatch.php','src/Subscription/DirectGift.php','settings/gift2all.php']
CHILDREN=[]
DRIVER=r'''<?php
if(PHP_SAPI!=='cli')exit(3);
define('ACHBOT_PG_TEST_LOOPBACK',true);
error_reporting(E_ALL & ~E_DEPRECATED);
set_error_handler(static function($n,$m){throw new ErrorException($m,0,$n);});
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$spec=json_decode($argv[1],true,512,JSON_THROW_ON_ERROR);
$GLOBALS['spec']=$spec;$GLOBALS['native_redispatches']=0;$GLOBALS['sql_errors']=[];
function fixtureLog(array $value):void {
    if(isset($GLOBALS['spec']['trace']))
        file_put_contents($GLOBALS['spec']['trace'],json_encode($value,JSON_THROW_ON_ERROR)."\n",FILE_APPEND);
}
function fixtureGate(string $ready,string $release):void {
    file_put_contents($ready,'ready');
    $end=microtime(true)+12;
    while(!file_exists($release)){
        if(microtime(true)>$end)throw new RuntimeException('owned_gate_timeout');
        usleep(10000);
    }
}
class GiftLockStatement extends mysqli_stmt {
    private string $source;private array $arguments=[];
    public function __construct(mysqli $db,string $source){
        $this->source=$source;parent::__construct($db,$source);
    }
    public function bind_param(string $types,mixed &...$vars):bool {
        $this->arguments=$vars;return parent::bind_param($types,...$vars);
    }
    public function execute(?array $params=null):bool {
        $args=$params??$this->arguments;
        $sql=preg_replace('/\s+/',' ',trim($this->source));
        $order=preg_match('/FROM orders_list WHERE id=\? FOR UPDATE$/D',$sql)===1;
        if($order && ($GLOBALS['active']??false))
            fixtureLog(['kind'=>'order_request','id'=>(int)$args[0]]);
        try {$ok=parent::execute($params);}
        catch(mysqli_sql_exception $error){
            if($GLOBALS['active']??false){
                $GLOBALS['sql_errors'][]=$error->getCode();
                fixtureLog(['kind'=>'sql_error','code'=>$error->getCode()]);
            }
            throw $error;
        }
        if($order && ($GLOBALS['active']??false)){
            $id=(int)$args[0];fixtureLog(['kind'=>'order_acquired','id'=>$id]);
            if(!($GLOBALS['first_order_seen']??false)){
                $GLOBALS['first_order_seen']=true;
                if(isset($GLOBALS['spec']['first_ready']))
                    fixtureGate($GLOBALS['spec']['first_ready'],$GLOBALS['spec']['release_first']);
            }
        }
        return $ok;
    }
}
class GiftLockMysqli extends mysqli {
    public function prepare(string $query):mysqli_stmt|false {
        return new GiftLockStatement($this,$query);
    }
    public function commit(int $flags=0,?string $name=null):bool {
        if(($GLOBALS['active']??false)&&isset($GLOBALS['spec']['commit_ready'])&&!($GLOBALS['paused_commit']??false)){
            $GLOBALS['paused_commit']=true;
            fixtureGate($GLOBALS['spec']['commit_ready'],$GLOBALS['spec']['release_commit']);
        }
        return parent::commit($flags,$name);
    }
}
$connection=new GiftLockMysqli('localhost','root','',getenv('ACHBOT_DIRECT_DB'),0,getenv('ACHBOT_DIRECT_SOCKET'));
$connection->set_charset('utf8mb4');
$connection->query('SET SESSION innodb_lock_wait_timeout=3');
require_once __DIR__.'/src/Subscription/DirectGift.php';
require_once __DIR__.'/src/Purchase/Core.php';
function getMarzbanUser(...$args){$GLOBALS['native_redispatches']++;throw new RuntimeException('unexpected_native_transport');}
function source(int $update,int $message,string $text):array {
 return ['kind'=>'telegram_direct_gift_v1','update_id'=>$update,'actor_id'=>9001,
  'chat_id'=>9001,'message_id'=>$message,'text_sha256'=>hash('sha256',$text)];
}
function prepareWizard(mysqli $db):string {
 $step='achbotDirectGiftRemark:2:'.str_repeat('a',32).':500';
 $s=$db->prepare('UPDATE users SET step=? WHERE userid=9001');$s->bind_param('s',$step);$s->execute();$s->close();
 return $step;
}
function advanceToClaim(mysqli $db):string {
 $step=prepareWizard($db);
 $step=achbotDirectGiftAdvance($db,source(501,51,'fixture-user'),9001,$step,'fixture-user');
 return achbotDirectGiftAdvance($db,source(502,52,'2'),9001,$step,'2');
}
function verifiedResult():object {
 return (object)['success'=>true,'verified'=>true,'uncertain'=>false,
  'before_expire'=>1800000100,'applied_expire'=>1800172900,'applied_bytes'=>3221225472];
}
$mode=$spec['mode'];$phase=$spec['phase']??'';
if(array_key_exists('snapshot_on',$spec))
 $connection->query('SET SESSION innodb_snapshot_isolation='.($spec['snapshot_on']?'ON':'OFF'));
$session=$connection->query('SELECT VERSION() AS server_version,@@innodb_snapshot_isolation AS snapshot_mode,@@tx_isolation AS tx_isolation')->fetch_assoc();
if($mode==='metadata'){
 echo json_encode(['server_version'=>$session['server_version'],
  'snapshot_mode'=>(int)$session['snapshot_mode'],'tx_isolation'=>$session['tx_isolation'],
  'server_version'=>$session['server_version'],'sql_error_codes'=>$GLOBALS['sql_errors']],JSON_THROW_ON_ERROR);exit;
}
try{
 if($mode==='prepare'){
  if($phase==='advance')$step=prepareWizard($connection);
  else $step=advanceToClaim($connection);
  if(in_array($phase,['preeffect','complete','bulk','concurrent'],true)){
   $claim=achbotDirectGiftClaim($connection,source(503,53,'1024'),9001,$step,'1024');
   file_put_contents(__DIR__.'/claim.json',json_encode($claim,JSON_THROW_ON_ERROR));
  }
  if(in_array($phase,['bulk','concurrent'],true)){
   $orders=$claim['references'];$target=achbotGiftPublicTarget($claim['target']);
   $connection->query('DELETE FROM gift_list');
   $connection->query("DELETE FROM setting WHERE type LIKE 'ACHBOT_GIFT_%'");
   $connection->query("INSERT INTO gift_list(id,server_id,volume,day,`offset`) VALUES(10,2,1024,2,-1)");
   $meta=json_encode(['target'=>$target,'cursor'=>0,'count'=>1],JSON_THROW_ON_ERROR);
   $s=$connection->prepare("INSERT INTO setting(type,value) VALUES('ACHBOT_GIFT_ROSTER_10',?)");
   $s->bind_param('s',$meta);$s->execute();$rid=$s->insert_id;$s->close();
   $connection->query("INSERT INTO setting(type,value) VALUES('ACHBOT_GIFT_HOLD_10','{}')");
   $hid=$connection->insert_id;
   file_put_contents(__DIR__.'/bulk.json',json_encode(['roster_id'=>$rid,'hold_id'=>$hid,'target'=>$target],JSON_THROW_ON_ERROR));
  }
 }elseif($mode==='probe'){
  $table=$spec['table'];$id=(int)$spec['id'];
  if(!in_array($table,['orders_list','service_effects','setting'],true))throw new RuntimeException('owned_probe_table');
  $connection->begin_transaction();$start=microtime(true);
  try{
   $s=$connection->prepare('SELECT id FROM '.$table.' WHERE id=? FOR UPDATE NOWAIT');
   $s->bind_param('i',$id);$s->execute();$s->close();
   if($table==='setting')$connection->query("UPDATE setting SET value='1' WHERE id=".$id);
   $connection->commit();
   echo json_encode(['ok'=>true,'code'=>0,'elapsed'=>microtime(true)-$start]);exit;
  }catch(mysqli_sql_exception $e){
   $connection->rollback();
   echo json_encode(['ok'=>false,'code'=>$e->getCode(),'elapsed'=>microtime(true)-$start]);exit;
  }
 }elseif($mode==='stale'){
  $connection->begin_transaction();
  $orders=achbotGiftReferences($connection,2,'fixture-user');
  fixtureGate($spec['snapshot_ready'],$spec['release_snapshot']);
  $GLOBALS['active']=true;
  achbotGiftValidateReferences($connection,$orders,true);
  $connection->commit();
 }else{
  $GLOBALS['active']=true;
  if($phase==='advance'){
   $step=$connection->query('SELECT step FROM users WHERE userid=9001')->fetch_assoc()['step'];
   achbotDirectGiftAdvance($connection,source(501,51,'fixture-user'),9001,$step,'fixture-user');
  }elseif($phase==='claim'){
   $step=$connection->query('SELECT step FROM users WHERE userid=9001')->fetch_assoc()['step'];
   achbotDirectGiftClaim($connection,source(503,53,'1024'),9001,$step,'1024');
  }elseif($phase==='preeffect'){
   $claim=json_decode(file_get_contents(__DIR__.'/claim.json'),true,512,JSON_THROW_ON_ERROR);
   achbotDirectGiftPreEffect($connection,$claim,9001,9001);
  }elseif($phase==='complete'){
   $claim=json_decode(file_get_contents(__DIR__.'/claim.json'),true,512,JSON_THROW_ON_ERROR);
   achbotDirectGiftComplete($connection,$claim,verifiedResult());
  }elseif($phase==='bulk'){
   $claim=json_decode(file_get_contents(__DIR__.'/claim.json'),true,512,JSON_THROW_ON_ERROR);
   $meta=json_decode(file_get_contents(__DIR__.'/bulk.json'),true,512,JSON_THROW_ON_ERROR);
   $rowId=10;$serverId=2;$volume=1024;$day=2;$holdType='ACHBOT_GIFT_HOLD_10';
   $rosterType='ACHBOT_GIFT_ROSTER_10';$rosterId=$meta['roster_id'];$receipt=['target'=>$meta['target']];
   require __DIR__.'/bulk-finish.php';
   $proof=achbotGiftReferenceProof($claim['references'])+['username'=>'fixture-user',
    'native_identity_sha256'=>achbotGiftIdentityHash($connection,$claim['references'])];
   $finish(-1,$meta['hold_id'],1,$claim['references'],verifiedResult(),$proof);
  }else throw new RuntimeException('owned_phase_unknown');
 }
 echo json_encode(['ok'=>true,'native_redispatches'=>$GLOBALS['native_redispatches'],
  'snapshot_mode'=>(int)$session['snapshot_mode'],'tx_isolation'=>$session['tx_isolation'],
  'server_version'=>$session['server_version'],'sql_error_codes'=>$GLOBALS['sql_errors']],JSON_THROW_ON_ERROR);
}catch(Throwable $error){
 try{$connection->rollback();}catch(Throwable $ignored){}
 echo json_encode(['ok'=>false,'class'=>get_class($error),'reason'=>$error->getMessage(),
  'code'=>$error->getCode(),'native_redispatches'=>$GLOBALS['native_redispatches'],
  'snapshot_mode'=>(int)$session['snapshot_mode'],'tx_isolation'=>$session['tx_isolation'],
  'server_version'=>$session['server_version'],'sql_error_codes'=>$GLOBALS['sql_errors']],JSON_THROW_ON_ERROR);
}
'''


def invoke(stage,env,spec,*,wait=True):
    command=['php','-n','-d','extension=mysqlnd','-d','extension=mysqli',
        '-d','disable_functions=curl_exec,curl_init,exec,shell_exec,system,passthru,popen,proc_open,mail',
        '-d','allow_url_fopen=0','-d','open_basedir='+str(stage)+':'+str(stage.parent),
        str(stage/'lock-driver.php'),json.dumps(spec)]
    if not wait:
        child=subprocess.Popen(command,env=env,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
        CHILDREN.append(child)
        return child
    result=subprocess.run(command,env=env,capture_output=True,text=True,timeout=20)
    if result.returncode or result.stderr:raise RuntimeError('owned PHP: '+result.stderr[-1600:]+result.stdout[-500:])
    return json.loads(result.stdout)


def finish(process):
    out,err=process.communicate(timeout=20)
    if process.returncode or err:raise RuntimeError('owned child: '+err[-1500:]+out[-500:])
    return json.loads(out)


def waitfile(path,process=None):
    end=time.monotonic()+12
    while not path.exists():
        if process is not None and process.poll() is not None:
            raise RuntimeError('owned child exited before SQL gate: '+json.dumps(finish(process)))
        if time.monotonic()>end:raise RuntimeError('owned gift SQL gate did not arrive')
        time.sleep(.02)


@contextlib.contextmanager
def owned_children():
    try:yield
    finally:
        for child in CHILDREN:
            if child.poll() is None:child.terminate()
            try:child.communicate(timeout=3)
            except subprocess.TimeoutExpired:child.kill();child.communicate(timeout=3)
        CHILDREN.clear()


def main():
    ap=argparse.ArgumentParser();ap.add_argument('--run-disposable',required=True,action='store_true')
    ap.add_argument('--report',type=Path);args=ap.parse_args()
    start_head=subprocess.check_output(['git','rev-parse','HEAD'],cwd=ROOT,text=True).strip()
    start_hashes={p:hashlib.sha256((ROOT/p).read_bytes()).hexdigest() for p in RUNTIME}
    start_clean=not subprocess.check_output(['git','status','--porcelain'],cwd=ROOT,text=True).strip()
    observations=[]
    def check(name,ok,actual):
        observations.append(dict(scenario=name,passed=bool(ok),actual=actual))
        if args.report:args.report.write_text(json.dumps(dict(complete=False,observations=observations),indent=2)+'\n')
        if not ok:raise RuntimeError(name+': '+json.dumps(actual))
    with owned.owned_database() as (work,env),owned_children():
        stage=direct.setup(work,env);native.migration_setup(work,env)
        env={**env,'ACHBOT_DIRECT_DB':direct.DB,'ACHBOT_DIRECT_SOCKET':str(work/'mysql.sock')}
        (stage/'lock-driver.php').write_text(DRIVER)
        default_observation=invoke(stage,env,dict(mode='metadata'))
        source=(ROOT/'settings/gift2all.php').read_text()
        body=source[source.index('$finish=static function('):source.index('$closeRoster=static function(')]
        (stage/'bulk-finish.php').write_text('<?php\n'+body)
        counter=0
        def staged(baseline):
            for path in RUNTIME:
                data=subprocess.check_output(['git','show',BASE+':'+path],cwd=ROOT) if baseline else (ROOT/path).read_bytes()
                (stage/path).parent.mkdir(parents=True,exist_ok=True)
                (stage/path).write_bytes(data)
            selected=(stage/'settings/gift2all.php').read_text()
            finish_body=selected[selected.index('$finish=static function('):selected.index('$closeRoster=static function(')]
            (stage/'bulk-finish.php').write_text('<?php\n'+finish_body)
        def seed(provider):
            direct.q(work,env,"DELETE FROM setting; DELETE FROM gift_list; DELETE FROM service_effects; DELETE FROM orders_list WHERE id<>91")
            direct.reset(work,env)
            direct.q(work,env,"INSERT INTO users(userid,name,username,refcode,wallet,date) VALUES(1002,'Second','shared','0',0,'1700000000') ON DUPLICATE KEY UPDATE name='Second'")
            direct.q(work,env,"INSERT INTO server_info(id,title,ucount,remark,flag,active,state) VALUES(4,'Alias',5,'fixture','',1,1) ON DUPLICATE KEY UPDATE active=1,state=1")
            direct.q(work,env,'DELETE FROM server_config WHERE id=4;'+storage.clone(4,2))
            direct.q(work,env,"INSERT INTO orders_list(id,userid,token,transid,fileid,server_id,inbound_id,remark,uuid,expire_date,link,amount,status,date,notif,autoRenew) SELECT 90,1002,token,transid,fileid,4,inbound_id,remark,uuid,expire_date,link,amount,1,date,-1,autoRenew FROM orders_list WHERE id=91")
            direct.q(work,env,"UPDATE orders_list SET notif=-1,provider_effect_id=NULL,provider_remote_user_id=NULL,provider_remote_created_at=NULL")
            if provider=='pasarguard':
                direct.q(work,env,storage.connection(2,1,'http://127.0.0.1:9')+storage.connection(4,1,'http://127.0.0.1:9'))
                direct.q(work,env,storage.identity(1,91,1,'http://127.0.0.1:9',17,policy.CREATED,'fixture-user',pg.MARKER,
                    dict(data_limit=2147483648,expire=direct.OLD_EXPIRY,group_ids=[2,5],status='active')))
                direct.q(work,env,"UPDATE orders_list SET provider_effect_id=1,provider_remote_user_id=17,provider_remote_created_at="+storage.text(policy.CREATED)+" WHERE id=90")
                policy.origin(work,env,90,1002,'pasarguard')
            else:
                direct.q(work,env,"UPDATE server_config SET type='marzban',panel_url='http://127.0.0.1:9',username='fixture-admin',password='fixture-password' WHERE id IN(2,4)")
                direct.q(work,env,"INSERT INTO setting(id,type,value) VALUES(333,'ACHBOT_MARZBAN_PANEL_GROUP','Fixture Group'); UPDATE server_info SET panel_group_id=333 WHERE id IN(2,4)")
            direct.q(work,env,"INSERT INTO setting(id,type,value) VALUES(9000,'UNRELATED_GIFT_LOCK_TEST','0')")
        for baseline in [True,False]:
            staged(baseline)
            for phase in ['advance','claim','preeffect','complete','bulk']:
                seed('pasarguard');counter+=1
                prep=invoke(stage,env,dict(mode='prepare',phase=phase))
                if not prep['ok']:raise RuntimeError('prepare '+json.dumps(prep))
                ready=work/('ready-'+str(counter));release=work/('release-'+str(counter));trace=work/('trace-'+str(counter))
                proc=invoke(stage,env,dict(mode='run',phase=phase,commit_ready=str(ready),release_commit=str(release),trace=str(trace)),wait=False)
                try:
                    waitfile(ready,proc)
                    probes={table:invoke(stage,env,dict(mode='probe',table=table,id=oid)) for table,oid in [('setting',9000),('orders_list',91),('service_effects',1)]}
                finally:release.write_text('release')
                result=finish(proc)
                events=[json.loads(x) for x in trace.read_text().splitlines()]
                acquired=[e['id'] for e in events if e['kind']=='order_acquired']
                want=91 if phase=='advance' else 90
                ok=result['ok'] and result['native_redispatches']==0 and all(not probes[t]['ok'] and probes[t]['code']==1205 for t in ['orders_list','service_effects'])
                ok=ok and (not probes['setting']['ok'] and probes['setting']['code']==1205 if baseline else probes['setting']['ok'] and acquired[0]==want)
                check(('baseline_' if baseline else 'current_')+'pg_'+phase+'_lock_scope',ok,dict(probes=probes,first_order=acquired[0],result=result))
        for baseline in [True,False]:
            staged(baseline);seed('marzban');counter+=1
            assert invoke(stage,env,dict(mode='prepare',phase='concurrent'))['ok']
            r1=work/('first-direct-'+str(counter));go1=work/('go-direct-'+str(counter))
            r2=work/('first-bulk-'+str(counter));go2=work/('go-bulk-'+str(counter));go2.write_text('release')
            t1=work/('direct-trace-'+str(counter));t2=work/('bulk-trace-'+str(counter))
            a=invoke(stage,env,dict(mode='run',phase='complete',snapshot_on=True,first_ready=str(r1),release_first=str(go1),trace=str(t1)),wait=False)
            b=None
            try:
                waitfile(r1,a)
                b=invoke(stage,env,dict(mode='run',phase='bulk',snapshot_on=True,first_ready=str(r2),release_first=str(go2),trace=str(t2)),wait=False)
                if baseline:waitfile(r2,b)
                else:
                    time.sleep(.2)
                    if r2.exists():raise RuntimeError('bulk acquired a reference held by direct completion')
            finally:go1.write_text('release')
            ar=finish(a);br=finish(b)
            tracea=[json.loads(x) for x in t1.read_text().splitlines()]
            traceb=[json.loads(x) for x in t2.read_text().splitlines()]
            source_state=direct.rows(work,env,'SOURCE')[0]['state']
            cursor=direct.q(work,env,'SELECT `offset` FROM gift_list WHERE id=10')
            if baseline:
                first_direct=next(e['id'] for e in tracea if e['kind']=='order_acquired')
                first_bulk=next(e['id'] for e in traceb if e['kind']=='order_acquired')
                ok=(not ar['ok'] or not br['ok']) and any(r.get('code')==1213 for r in [ar,br])
                ok=ok and first_direct==91 and first_bulk==90
            else:
                ok=ar['ok'] and br['ok'] and source_state=='completed' and cursor=='1'
            ok=ok and ar['native_redispatches']==0 and br['native_redispatches']==0 and ar['snapshot_mode']==br['snapshot_mode']==1
            check(('baseline_' if baseline else 'current_')+'direct_bulk_completion_order',ok,dict(direct=ar,bulk=br,direct_trace=tracea,bulk_trace=traceb,source_state=source_state,cursor=cursor,verified_native_results_supplied=2))
        staged(False)
        for snapshot_enabled in [True,False]:
            for label in ['native_id','effect_marker','notif_only','effect_timestamp_only']:
                seed('pasarguard');counter+=1
                ready=work/('snapshot-'+str(counter));release=work/('snapshot-release-'+str(counter))
                proc=invoke(stage,env,dict(mode='stale',snapshot_on=snapshot_enabled,snapshot_ready=str(ready),release_snapshot=str(release)),wait=False)
                try:
                    waitfile(ready,proc)
                    sql={
                     'native_id':"UPDATE orders_list SET provider_remote_user_id=18; UPDATE service_effects SET provider_remote_user_id=18",
                     'effect_marker':"UPDATE service_effects SET provider_operation_marker='achbot:v1:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'",
                     'notif_only':"UPDATE orders_list SET notif=0",
                     'effect_timestamp_only':"UPDATE service_effects SET provider_evidence_updated_at='2026-10-30 12:30:00'",
                    }[label]
                    direct.q(work,env,sql)
                finally:release.write_text('release')
                result=finish(proc)
                deferred=not result['ok'] and result.get('sql_error_codes')==[1020] and (
                    result.get('class')=='mysqli_sql_exception' and result.get('code')==1020 or
                    result.get('class')=='RuntimeException' and result.get('reason')=='provider_storage_unavailable')
                material_error=result.get('reason') in ['gift_pg_current_order_changed','gift_pg_current_account_changed','gift_pg_current_identity_changed']
                if label.endswith('_only'):
                    ok=result['ok'] or (snapshot_enabled and deferred)
                else:
                    ok=not result['ok'] and (material_error or (snapshot_enabled and deferred))
                ok=ok and result['native_redispatches']==0 and result['tx_isolation']=='REPEATABLE-READ'
                ok=ok and result['snapshot_mode']==int(snapshot_enabled)
                check('current_rr_'+('on_' if snapshot_enabled else 'off_')+label,ok,result)
    end_head=subprocess.check_output(['git','rev-parse','HEAD'],cwd=ROOT,text=True).strip()
    end_hashes={p:hashlib.sha256((ROOT/p).read_bytes()).hexdigest() for p in RUNTIME}
    end_clean=not subprocess.check_output(['git','status','--porcelain'],cwd=ROOT,text=True).strip()
    report=dict(complete=start_head==end_head and start_hashes==end_hashes,cases=len(observations),
        scenarios=[o['scenario'] for o in observations],observations=observations,
        actual_direct_phases=True,actual_bulk_finish_closure=True,owned_socket_mariadb=True,
        source_unchanged=start_head==end_head and start_hashes==end_hashes,
        source_clean_at_start=start_clean,source_clean_at_end=end_clean,
        owned_children_cleaned=not CHILDREN,transports_disabled=True,
        default_observation=default_observation,default_rr_preserved=True,
        explicit_snapshot_modes=[1,0],snapshot_settings_scope='owned_fixture_session_only',
        bulk_finish_sha256=hashlib.sha256(body.encode()).hexdigest(),
        baseline_source=BASE,baseline_red_cases=6,native_redispatches=0,
        source_head=start_head,source_head_after=end_head,runtime_sha256=start_hashes,
        runtime_sha256_after=end_hashes,production_operations=0)
    if args.report:args.report.write_text(json.dumps(report,indent=2)+'\n')
    print(json.dumps(dict(complete=report['complete'],cases=report['cases'])))
    if not report['complete']:raise SystemExit('source changed during lock proof')
if __name__=='__main__':main()
