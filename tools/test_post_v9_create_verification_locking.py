#!/usr/bin/env python3
"""AChBot: deterministic native CREATE verification/capacity lock regression.

Only disposable socket MariaDB and synthetic provider/Telegram boundaries.
No source hooks, transaction-isolation changes, financial retries or live HTTP.
The prior 011c source is an expected RED control, not accepted success.
"""
from __future__ import annotations

import argparse
import io
import json
import re
import shutil
import subprocess
import tarfile
import time
from pathlib import Path

import package_post_v9 as runtime
import test_post_v9_standard_integrity as standard
import test_post_v9_service_effects as effects

ROOT = Path(__file__).resolve().parents[1]
OLD = '011c17698a5082fb74ae2421bc4cdb6655263db3'
INTERMEDIATE = '7dca1378cf54b4de035cd8a024a453e52c76fec8'
DB = standard.DB

SUPPORT = r'''
function ownedNoHttpTransaction(string $boundary): void {
    global $connection;
    $r=$connection->query('SELECT @@in_transaction AS active_transaction')->fetch_assoc();
    $tx=(int)$r['active_transaction'];
    $GLOBALS['events'][]=['name'=>'HTTP_TRANSACTION','boundary'=>$boundary,'active'=>$tx];
    if ($tx!==0) throw new RuntimeException('owned_http_inside_transaction');
}
function ownedPublishReady(string $name): void {
    global $connection,$spec;
    $path=(string)$spec['barrier_dir'].'/'.$name;
    $temporary=$path.'.'.getmypid().'.tmp';
    $pid=(string)$connection->thread_id;
    // Publish only a complete PID; opening the final path before writing can
    // expose an empty marker to the strict reader in another process.
    if (file_put_contents($temporary,$pid)!==strlen($pid) || !rename($temporary,$path))
        throw new RuntimeException('owned_ready_publication_failed');
}
function ownedBarrier(string $ready,string $release): void {
    global $spec;
    $dir=(string)$spec['barrier_dir'];
    ownedPublishReady($ready);
    $until=microtime(true)+20;
    while (!is_file($dir.'/'.$release) && microtime(true)<$until) usleep(10000);
    if (!is_file($dir.'/'.$release)) throw new RuntimeException('owned_barrier_timeout');
}
// Fault injection is confined to the COMMIT acknowledgement seam. Normal
// race workers use an ordinary mysqli connection and unmodified statements.
class OwnedCommitFaultMysqli extends mysqli {
    public function commit(int $flags=0,?string $name=null): bool {
        global $spec;
        $atVerification=false;
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS,4) as $frame)
            if (($frame['function']??'')==='achbotVerifyMarzbanCreatedEffect') $atVerification=true;
        if ($atVerification) {
            $fault=(string)$spec['fault'];
            $GLOBALS['events'][]=['name'=>'COMMIT_FAULT','kind'=>$fault];
            if ($fault==='commit_ack_lost') parent::commit($flags,$name);
            return false;
        }
        return parent::commit($flags,$name);
    }
}
'''

EXTRA_OPERATIONS = r'''
require_once $root.'/src/Finance/ServiceEffects.php';
$GLOBALS['events'][]=['name'=>'FIX_PRESENT','value'=>function_exists('achbotVerifyMarzbanCreatedEffect')];
if (($spec['role']??'')==='info_blocker') {
    $connection->begin_transaction();
    $connection->query('SELECT id FROM server_info WHERE id=2 FOR UPDATE');
    ownedBarrier('info-locked','release-info');
    $connection->rollback();exit;
}
if (($spec['role']??'')==='nonpaid') {
    ownedPublishReady('contender-start');
    try {
        achbotCreateNonPaymentAccount($connection,'admin_bulk','bulk:9001:777',9001,1,1,
            ['server_id'=>2,'plan_id'=>7,'server_type'=>'marzban','inbound_id'=>0,
             'remark'=>'owned-nonpaid','volume'=>2,'days'=>30,'is_primary_admin'=>true,
             'protocol'=>'vless','user_id'=>9001]);
        $GLOBALS['events'][]=['name'=>'CONTENDER_RESULT','success'=>true];
    } catch(Throwable $error) {
        $GLOBALS['events'][]=['name'=>'CONTENDER_RESULT','success'=>false,
            'errno'=>(int)$error->getCode(),
            'sqlstate'=>method_exists($error,'getSqlState')?$error->getSqlState():null];
    }
    exit;
}
if (($spec['role']??'')==='linker') {
    ownedPublishReady('contender-start');
    $id=(int)$spec['effect'];
    try {
        $connection->begin_transaction();
        // The actual insertOrder caller writes only orders_list. This native
        // INSERT SELECT supplies the same exact identity to the real linker.
        $connection->query("INSERT INTO orders_list
            (userid,token,transid,fileid,server_id,inbound_id,remark,uuid,protocol,
             expire_date,link,amount,status,date,notif,rahgozar,agent_bought)
             SELECT p.user_id,'token','',e.plan_id,e.target_server_id,0,
                    e.remote_username,'fixture-uuid','vless',2000000000,'[]',
                    100,1,'fixture',0,0,0
             FROM service_effects e JOIN pays p ON p.id=e.pay_row_id WHERE e.id=".$id);
        $order=(int)$connection->insert_id;
        achbotLinkPaidAccountEffect($connection,$id,$order);
        $connection->commit();
        $GLOBALS['events'][]=['name'=>'CONTENDER_RESULT','success'=>true];
    } catch(Throwable $error) {
        $connection->rollback();
        $GLOBALS['events'][]=['name'=>'CONTENDER_RESULT','success'=>false,
            'errno'=>(int)$error->getCode(),
            'sqlstate'=>method_exists($error,'getSqlState')?$error->getSqlState():null];
    }
    exit;
}

if (($spec['role']??'')==='claimant') {
    // The actual source claimant sequence from ManualApproval: lock pays,
    // locked Core plan/config preflight, then guarded capacity reservation.
    // The barrier controls scheduling, without replacing either SQL helper.
    try {
        $connection->begin_transaction();
        $stmt=$connection->prepare('SELECT * FROM pays WHERE hash_id=? FOR UPDATE');
        $hash=(string)$spec['hash'];$stmt->bind_param('s',$hash);$stmt->execute();
        $pay=$stmt->get_result()->fetch_assoc();$stmt->close();
        if (!achbotSupportsPaymentSubject($connection,$pay,true))
            throw new RuntimeException('owned_claimant_subject_invalid');
        ownedBarrier('claimant-config-locked','release-claimant');
        $count=achbotManualStandardReserveCapacityLocked($connection,$pay);
        $GLOBALS['events'][]=['name'=>'CLAIMANT_CAPACITY','count'=>$count];
        $connection->rollback();
    } catch (Throwable $error) {
        $GLOBALS['events'][]=['name'=>'CLAIMANT_DENIED','class'=>get_class($error),
            'errno'=>(int)$error->getCode(),
            'sqlstate'=>method_exists($error,'getSqlState')?$error->getSqlState():null];
        $connection->rollback();
    }
    exit;
}
if (($spec['role']??'')==='active_transaction') {
    require_once $root.'/src/Finance/ServiceEffects.php';
    $before=(int)$connection->query('SELECT wallet FROM users WHERE userid=1001')->fetch_assoc()['wallet'];
    $connection->begin_transaction();
    $connection->query('UPDATE users SET wallet=wallet+1 WHERE userid=1001');
    try {
        achbotVerifyMarzbanCreatedEffect($connection,999,2,null,'owned','owned',[]);
        $GLOBALS['events'][]=['name'=>'ACTIVE_TRANSACTION','refused'=>false];
    } catch (Throwable $error) {
        $tx=(int)$connection->query('SELECT @@in_transaction AS active_transaction')->fetch_assoc()['active_transaction'];
        $during=(int)$connection->query('SELECT wallet FROM users WHERE userid=1001')->fetch_assoc()['wallet'];
        $GLOBALS['events'][]=['name'=>'ACTIVE_TRANSACTION',
            'refused'=>$error->getMessage()==='effect_verification_requires_autocommit',
            'transaction_active'=>$tx,'caller_write_visible'=>$during===$before+1];
    }
    $connection->rollback();
    $after=(int)$connection->query('SELECT wallet FROM users WHERE userid=1001')->fetch_assoc()['wallet'];
    $GLOBALS['events'][]=['name'=>'CALLER_ROLLBACK','unchanged'=>$after===$before];
    exit;
}
'''


def driver_source() -> str:
    php = standard.PHP
    anchor = "if (!empty($spec['nonthrowing'])) mysqli_report(MYSQLI_REPORT_OFF);"
    assert php.count(anchor) == 1
    php = php.replace(anchor, SUPPORT + '\n' + anchor)
    anchor = "$connection=new mysqli('localhost','root','',$argv[3],0,$argv[2]);"
    assert php.count(anchor) == 1
    php = php.replace(anchor, "$connection=in_array($spec['fault']??'',['commit_false','commit_ack_lost'],true)\n"
        "    ?new OwnedCommitFaultMysqli('localhost','root','',$argv[3],0,$argv[2])\n"
        "    :new mysqli('localhost','root','',$argv[3],0,$argv[2]);")
    anchor = "function createServerAccount(...$args) {\n"
    assert php.count(anchor) == 1
    php = php.replace(anchor, anchor + "    ownedNoHttpTransaction('create');\n")
    anchor = "    $account=$GLOBALS['remote_accounts'][(string)$remark]??null;\n"
    assert php.count(anchor) == 1
    php = php.replace(anchor, anchor + r'''
    ownedNoHttpTransaction('readback');
    $fault=(string)($GLOBALS['spec']['fault']??'');
    $sql=match($fault) {
        'password_drift'=>"UPDATE server_config SET password='owned-changed' WHERE id=2",
        'username_drift'=>"UPDATE server_config SET username='owned-changed' WHERE id=2",
        'endpoint_drift'=>"UPDATE server_config SET panel_url='http://127.0.0.1:8' WHERE id=2",
        'group_drift'=>"UPDATE server_info SET panel_group_id=9901 WHERE id=2",
        'operation_drift'=>"UPDATE service_effects SET operation_id=REPEAT('1',32) WHERE effect_kind='create'",
        'journal_endpoint_drift'=>"UPDATE service_effects SET endpoint_sha256=REPEAT('1',64) WHERE effect_kind='create'",
        default=>null
    };
    if ($sql!==null) $GLOBALS['connection']->query($sql);
    if (($GLOBALS['spec']['role']??'')==='verifier')
        ownedBarrier('http-ready','release-verifier');
''')
    anchor = "if (($spec['op']??'route')==='capacity_helper') {"
    assert php.count(anchor) == 1
    return php.replace(anchor, EXTRA_OPERATIONS + '\n' + anchor)


def sql(work, env, query):
    return standard.sql(work, env, query)


def initialize(work, env):
    for filename in ('001_marzban_panel_group_id.sql', '007_service_effects.sql',
                     '008_referral_awards.sql'):
        sql(work, env, (ROOT/'migrations/post-v9'/filename).read_text())


def stage_source(work, name, old=False, ref=None):
    stage = work/name
    stage.mkdir()
    ref = OLD if old else ref
    if ref:
        # An immutable baseline owns its entire source layout. In particular,
        # older topic catalogs must not be selected using today's two-file
        # runtime inventory, or overlaid with today's loader/catalog bytes.
        raw = subprocess.check_output(['git','-C',str(ROOT),'archive',ref])
        with tarfile.open(fileobj=io.BytesIO(raw)) as archive:
            archive.extractall(stage, filter='data')
    else:
        for member in runtime.members():
            dest = stage/member
            dest.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(ROOT/member, dest)
    return stage


def launch(work, env, stage, driver, **spec):
    return subprocess.Popen(['php','-n','-d','extension=mysqlnd','-d','extension=mysqli',
        '-d','extension=curl','-d','allow_url_fopen=0',
        '-d','disable_functions=exec,shell_exec,system,passthru,popen,proc_open,mail',
        str(driver),str(stage),str(work/'mysql.sock'),DB,json.dumps(spec)],
        cwd=work, env=env, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)


def finish(proc):
    out, err = proc.communicate(timeout=30)
    if proc.returncode:
        raise AssertionError(('native_php_exit',proc.returncode,out[-900:],err[-900:]))
    data = json.loads(out)
    assert data['fatal'] is None, data
    return data['events'], err


def wait_file(path, proc):
    end = time.monotonic()+10
    while time.monotonic()<end:
        if path.exists():
            return int(path.read_text())
        if proc.poll() is not None:
            raise AssertionError(('early_worker_exit',finish(proc)))
        time.sleep(.01)
    raise AssertionError(('barrier_timeout',str(path)))


def deadlock_graph(work, env):
    status = sql(work, env, 'SHOW ENGINE INNODB STATUS').replace('\\n','\n')
    marker = status.find('LATEST DETECTED DEADLOCK')
    return '' if marker<0 else status[marker:].split('\nTRANSACTIONS\n',1)[0]


# These are the actual prepared verifier statements in pinned OLD/current
# source. A matching active query alone is not proof of native row ownership.
OLD_VERIFICATION_SQL = """UPDATE service_effects e
    INNER JOIN server_info s ON s.id=e.target_server_id
    INNER JOIN server_config c ON c.id=s.id
    SET e.state='verified',e.verified_at=NOW()
    WHERE e.id=? AND e.state='dispatching' AND e.order_id IS NULL
      AND c.type='marzban' AND s.panel_group_id <=> e.target_group_id
      AND SHA2(TRIM(TRAILING '/' FROM c.panel_url),256)=e.endpoint_sha256"""
CURRENT_VERIFICATION_SQL = 'SELECT type,panel_url,username,password FROM server_config WHERE id=? FOR UPDATE'

PK_LOCK_PROBE = r"""
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db=null;$result=null;
try {
    if (!in_array($argv[3],['server_config','server_info'],true))
        throw new RuntimeException('owned_probe_invalid_table');
    $db=new mysqli('localhost','root','',$argv[2],0,$argv[1]);
    $db->begin_transaction();
    try {
        $row=$db->query('SELECT id FROM '.$argv[3].' FORCE INDEX (PRIMARY) WHERE id=2 FOR UPDATE NOWAIT')->fetch_assoc();
        if (!$row || (int)$row['id']!==2) throw new RuntimeException('owned_probe_target_missing');
        $result=['acquired'=>true,'row_id'=>(int)$row['id'],'errno'=>0];
    } catch (mysqli_sql_exception $error) {
        if ((int)$error->getCode()!==1205) throw $error;
        $result=['acquired'=>false,'row_id'=>null,'errno'=>1205];
    }
} finally {
    if ($db instanceof mysqli) {
        try {$db->rollback();} finally {$db->close();}
    }
}
echo json_encode($result,JSON_THROW_ON_ERROR);
"""


def probe_pk_lock(work,env,table):
    # NOWAIT never joins the actors' wait graph. This independent short
    # transaction always rolls back/closes before either actor is released.
    p=subprocess.run(['php','-n','-d','extension=mysqlnd','-d','extension=mysqli',
        '-r',PK_LOCK_PROBE,str(work/'mysql.sock'),DB,table],
        env=env,capture_output=True,text=True,timeout=10)
    assert p.returncode==0 and not p.stderr,('native_pk_probe_failed',table,p.returncode,p.stderr[-900:])
    result=json.loads(p.stdout)
    assert result in ({'acquired':True,'row_id':2,'errno':0},
                      {'acquired':False,'row_id':None,'errno':1205}),result
    return result


def verifier_phase(work,env,verifier,old):
    raw=sql(work,env,f"SELECT JSON_OBJECT('command',COMMAND,'query',INFO) FROM information_schema.PROCESSLIST WHERE ID={verifier}")
    row=json.loads(raw) if raw else {}
    query=row.get('query')
    expected=OLD_VERIFICATION_SQL if old else CURRENT_VERIFICATION_SQL
    active=(row.get('command') not in (None,'Sleep') and isinstance(query,str) and
            re.sub(r'\s+',' ',query).strip()==re.sub(r'\s+',' ',expected).strip())
    return active,row


def wait_config_lock(work,env,verifier,claimant,verifier_id,old):
    end=time.monotonic()+10
    diagnostic={}
    while time.monotonic()<end:
        assert verifier.poll() is None and claimant.poll() is None,'early_owned_worker_exit'
        active,before=verifier_phase(work,env,verifier_id,old)
        diagnostic={'phase_before':before}
        if active:
            config=probe_pk_lock(work,env,'server_config')
            assert config['errno']==1205,('claimant_config_not_owned',config,before)
            info=probe_pk_lock(work,env,'server_info')
            active,after=verifier_phase(work,env,verifier_id,old)
            assert verifier.poll() is None and claimant.poll() is None,'early_owned_worker_exit'
            diagnostic.update(config=config,info=info,phase_after=after)
            if active and info['acquired'] is (not old):
                return {'exact_verifier_query':after['query'],
                        'config_conflict_errno':config['errno'],
                        'info_probe':info,'actors_alive':True,
                        'probe_transactions_rolled_back_and_closed':True}
        time.sleep(.02)
    # Information-schema lock metadata is diagnostic only: some MariaDB
    # versions omit an executing waiter until the metadata cache refreshes.
    diagnostic['lock_waits']=sql(work,env,'SELECT * FROM information_schema.INNODB_LOCK_WAITS')
    raise AssertionError(('missing_native_verification_lock_witness',old,diagnostic))


def named(events, name):
    return [e for e in events if e['name']==name]


def concurrency(old):
    with standard.owned.owned_database() as (work,env):
        initialize(work,env)
        stage = stage_source(work,'prior' if old else 'corrected',old)
        driver = work/'verification-race.php';driver.write_text(driver_source())
        standard.seed(work,env,'claim_a',capacity=1)
        sql(work,env,"INSERT INTO pays (hash_id,user_id,type,plan_id,server_id,agent_count,day,volume,price,request_date,state,message_id,chat_id,description) VALUES ('claim_b',1001,'BUY_SUB',7,2,1,0,0,100,1700000000,'have_sent',212,9001,'other-account')")
        wallet = sql(work,env,'SELECT userid,wallet FROM users ORDER BY userid')
        gates = work/'barriers';gates.mkdir()
        verifier = launch(work,env,stage,driver,hash='claim_a',role='verifier',barrier_dir=str(gates))
        claimant = None
        try:
            aid = wait_file(gates/'http-ready',verifier)
            claimant = launch(work,env,stage,driver,hash='claim_b',role='claimant',barrier_dir=str(gates))
            bid = wait_file(gates/'claimant-config-locked',claimant)
            assert verifier.poll() is None and claimant.poll() is None,'early_owned_worker_exit'
            # A is still paused after HTTP and owns no config lock; this
            # native conflict therefore witnesses B's exact config target.
            config_before=probe_pk_lock(work,env,'server_config')
            assert config_before['errno']==1205,config_before
            (gates/'release-verifier').write_text('go')
            witness=wait_config_lock(work,env,verifier,claimant,aid,old)
            witness['config_before_verifier_release_errno']=config_before['errno']
            (gates/'release-claimant').write_text('go')
            a,ae = finish(verifier);b,be = finish(claimant)
        finally:
            for name in ('release-verifier','release-claimant'):
                (gates/name).write_text('cleanup')
            for proc in (verifier,claimant):
                if proc is not None and proc.poll() is None:
                    proc.kill();proc.communicate(timeout=3)
        graph = deadlock_graph(work,env)
        assert len(named(a,'CREATE'))==1 and not named(b,'CREATE'),(a,b)
        assert len(named(a,'HTTP_TRANSACTION'))==2
        assert all(e['active']==0 for e in named(a,'HTTP_TRANSACTION'))
        assert named(a,'FIX_PRESENT')[0]['value'] is (not old)
        assert sql(work,env,'SELECT userid,wallet FROM users ORDER BY userid')==wallet
        assert sql(work,env,'SELECT ucount FROM server_info WHERE id=2')=='0'
        assert sql(work,env,"SELECT COUNT(*) FROM service_effects WHERE effect_kind='create'")=='1'
        orders = int(sql(work,env,'SELECT COUNT(*) FROM orders_list'))
        if old:
            assert graph and 'UPDATE service_effects e' in graph and 'UPDATE server_info SET ucount' in graph,graph
            assert 'server_info' in graph and 'server_config' in graph
            assert orders<=1
        else:
            assert not graph,graph
            assert orders==1 and sql(work,env,"SELECT state FROM pays WHERE hash_id='claim_a'")=='approved',(a,b,ae,be)
            assert sql(work,env,"SELECT state FROM pays WHERE hash_id='claim_b'")=='have_sent'
            assert sql(work,env,"SELECT COUNT(*) FROM service_effects WHERE effect_kind='create' AND state='local_recorded' AND order_id IS NOT NULL")=='1'
            assert sql(work,env,'SELECT amount FROM orders_list')=='100'
        replay = launch(work,env,stage,driver,hash='claim_a')
        again,_ = finish(replay)
        assert not named(again,'CREATE') and not named(again,'READBACK')
        assert int(sql(work,env,'SELECT COUNT(*) FROM orders_list'))==orders
        assert sql(work,env,'SELECT userid,wallet FROM users ORDER BY userid')==wallet
        return {'case':'prior_011c_lock_inversion_expected_RED' if old else 'corrected_config_first_native_purchase_GREEN',
                'pass':True,'old_source_deadlock_observed':bool(graph),'provider_creates':1,
                'orders':orders,'replay_creates':0,'native_lock_witness':witness,
                'old_deadlock_graph':graph if old else None}



def wait_owned_lock(work,env,requestor,blocker,table):
    end=time.monotonic()+5
    while time.monotonic()<end:
        rows=sql(work,env,"SELECT r.trx_mysql_thread_id,b.trx_mysql_thread_id,l.lock_table "
            "FROM information_schema.INNODB_LOCK_WAITS w "
            "JOIN information_schema.INNODB_TRX r ON r.trx_id=w.requesting_trx_id "
            "JOIN information_schema.INNODB_TRX b ON b.trx_id=w.blocking_trx_id "
            "JOIN information_schema.INNODB_LOCKS l ON l.lock_id=w.requested_lock_id")
        if any(r.split('\t')[:2]==[str(requestor),str(blocker)] and table in r
               for r in rows.splitlines()):return
        time.sleep(.02)
    raise AssertionError(('missing_owned_lock',requestor,blocker,table,rows))


def prepare_link(work,env,stage):
    sql(work,env,"INSERT INTO pays (hash_id,user_id,type,plan_id,server_id,agent_count,day,volume,price,request_date,state,message_id,chat_id,description) VALUES ('link_b',1001,'BUY_SUB',7,2,1,0,0,100,1700000000,'have_sent',212,9001,'link-account')")
    pay=int(sql(work,env,"SELECT id FROM pays WHERE hash_id='link_b'"))
    driver=work/'native-effect-preparation.php';driver.write_text(effects.PHP)
    before=effects.ROOT
    try:
        effects.ROOT=stage
        assert effects.run_php(work,env,driver,pay=pay,operation='manual_capacity')['error'] is None
        result=effects.run_php(work,env,driver,pay=pay)
        assert result['error'] is None,result
        assert [e['event'] for e in result['events']]==['POST','GET']
        return result['result']['effect_id']
    finally:effects.ROOT=before


def cross_caller(kind,old):
    # Third SQL worker queues the actual contender on info before verification
    # starts. It adds no writes, timing trigger, or runtime source hook.
    with standard.owned.owned_database() as (work,env):
        initialize(work,env)
        stage=stage_source(work,'cross-caller',ref=INTERMEDIATE if old else None)
        driver=work/'cross-caller.php';driver.write_text(driver_source())
        standard.seed(work,env,'claim_a',capacity=3 if kind=='linker' else 2)
        effect=prepare_link(work,env,stage) if kind=='linker' else 0
        wallet=sql(work,env,'SELECT userid,wallet FROM users ORDER BY userid')
        gates=work/'gates';gates.mkdir();processes=[]
        try:
            a=launch(work,env,stage,driver,hash='claim_a',role='verifier',barrier_dir=str(gates));processes.append(a)
            aid=wait_file(gates/'http-ready',a)
            c=launch(work,env,stage,driver,hash='claim_a',role='info_blocker',barrier_dir=str(gates));processes.append(c)
            cid=wait_file(gates/'info-locked',c)
            b=launch(work,env,stage,driver,hash='claim_a',role=kind,effect=effect,barrier_dir=str(gates));processes.append(b)
            bid=wait_file(gates/'contender-start',b)
            wait_owned_lock(work,env,bid,cid,'server_info')
            (gates/'release-verifier').write_text('go')
            end=time.monotonic()+5
            while time.monotonic()<end:
                # MariaDB can omit a second waiter for the same record from
                # INNODB_LOCK_WAITS. Observe this owned session's actual PK
                # locking statement as well; its source config lock precedes it.
                query=sql(work,env,f'SELECT INFO FROM information_schema.PROCESSLIST WHERE ID={aid}')
                if 'FOR UPDATE' in query and ('server_info' in query or 'server_config' in query):break
                if a.poll() is not None:raise AssertionError(('early_verifier',finish(a)))
                time.sleep(.02)
            else:raise AssertionError(('verifier_not_at_server_lock',query))
            (gates/'release-info').write_text('go')
            ae,aerr=finish(a);be,berr=finish(b);finish(c)
        finally:
            for name in ('release-verifier','release-info'):(gates/name).write_text('cleanup')
            for proc in processes:
                if proc.poll() is None:proc.kill();proc.communicate(timeout=3)
        graph=deadlock_graph(work,env)
        assert len(named(ae,'CREATE'))==1 and all(e['active']==0 for e in named(ae,'HTTP_TRANSACTION'))
        assert sql(work,env,'SELECT userid,wallet FROM users ORDER BY userid')==wallet
        orders=int(sql(work,env,'SELECT COUNT(*) FROM orders_list'))
        if old:
            assert graph and 'server_info' in graph and 'server_config' in graph,graph
            assert ('FROM server_plans s' if kind=='nonpaid' else 'UPDATE service_effects e') in graph
        else:
            assert not graph,graph
            assert named(be,'CONTENDER_RESULT')==[{'name':'CONTENDER_RESULT','success':True}],(be,berr)
            assert sql(work,env,"SELECT state FROM pays WHERE hash_id='claim_a'")=='approved',(ae,aerr)
            assert orders==(2 if kind=='linker' else 1)
            assert sql(work,env,'SELECT ucount FROM server_info WHERE id=2')==('1' if kind=='linker' else '0')
            assert len(named(be,'CREATE'))==(0 if kind=='linker' else 1)
            assert all(e['active']==0 for e in named(be,'HTTP_TRANSACTION'))
            assert sql(work,env,"SELECT COUNT(*) FROM service_effects WHERE effect_kind='create' AND state='local_recorded'")==str(orders)
        again,_=finish(launch(work,env,stage,driver,hash='claim_a'))
        assert not named(again,'CREATE')
        if not old:
            repeat,_=finish(launch(work,env,stage,driver,hash='claim_a',role=kind,effect=effect,barrier_dir=str(gates)))
            assert not named(repeat,'CREATE') and named(repeat,'CONTENDER_RESULT')[0]['success'] is False
        assert int(sql(work,env,'SELECT COUNT(*) FROM orders_list'))==orders
        assert sql(work,env,'SELECT userid,wallet FROM users ORDER BY userid')==wallet
        return {'case':kind+('_prior_7dca_expected_RED' if old else '_canonical_target_locks_GREEN'),
                'pass':True,'source_deadlock_observed':bool(graph),'verifier_creates':1,
                'orders':orders,'replay_creates':0,'old_deadlock_graph':graph if old else None}


def boundaries():
    records=[]
    with standard.owned.owned_database() as (work,env):
        initialize(work,env)
        stage=stage_source(work,'corrected-boundaries')
        driver=work/'verification-boundaries.php';driver.write_text(driver_source())
        for fault in ('password_drift','username_drift','endpoint_drift','group_drift',
                      'operation_drift','journal_endpoint_drift','verified_write_failure',
                      'commit_false','commit_ack_lost'):
            standard.seed(work,env,'held',capacity=1)
            sql(work,env,"INSERT INTO setting(id,type,value) VALUES(9901,'ACHBOT_MARZBAN_PANEL_GROUP','owned drift') ON DUPLICATE KEY UPDATE value='owned drift'")
            wallet=sql(work,env,'SELECT userid,wallet FROM users ORDER BY userid')
            if fault=='verified_write_failure':
                sql(work,env,"DELIMITER //\nCREATE TRIGGER owned_verify_write_fail BEFORE UPDATE ON service_effects FOR EACH ROW BEGIN IF NEW.state='verified' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='owned verification refusal'; END IF; END//\nDELIMITER ;")
            try:
                events,_=finish(launch(work,env,stage,driver,hash='held',fault=fault))
            finally:
                if fault=='verified_write_failure':sql(work,env,'DROP TRIGGER owned_verify_write_fail')
            assert named(events,'FIX_PRESENT')[0]['value'] is True
            assert len(named(events,'CREATE'))==1 and len(named(events,'READBACK'))==1,(fault,events)
            assert all(e['active']==0 for e in named(events,'HTTP_TRANSACTION'))
            assert sql(work,env,"SELECT state FROM pays WHERE hash_id='held'")=='processing',(fault,events)
            assert sql(work,env,'SELECT COUNT(*) FROM orders_list')=='0'
            assert sql(work,env,'SELECT ucount FROM server_info WHERE id=2')=='0'
            assert sql(work,env,'SELECT userid,wallet FROM users ORDER BY userid')==wallet
            effect=sql(work,env,"SELECT state FROM service_effects WHERE effect_kind='create'")
            assert effect==('verified' if fault=='commit_ack_lost' else 'dispatching'),(fault,effect)
            if fault.startswith('commit_'):
                assert len(named(events,'COMMIT_FAULT'))==1
            again,_=finish(launch(work,env,stage,driver,hash='held',fault=fault))
            assert not named(again,'CREATE') and not named(again,'READBACK')
            assert sql(work,env,'SELECT COUNT(*) FROM orders_list')=='0'
            assert sql(work,env,'SELECT userid,wallet FROM users ORDER BY userid')==wallet
            records.append({'case':fault+'_held_no_refund_or_repeat_create','pass':True})
        standard.seed(work,env,'caller_transaction',capacity=1)
        events,_=finish(launch(work,env,stage,driver,hash='caller_transaction',role='active_transaction'))
        entry=named(events,'ACTIVE_TRANSACTION')[0]
        assert entry=={'name':'ACTIVE_TRANSACTION','refused':True,'transaction_active':1,'caller_write_visible':True},events
        assert named(events,'CALLER_ROLLBACK')==[{'name':'CALLER_ROLLBACK','unchanged':True}]
        assert not named(events,'CREATE')
        records.append({'case':'active_caller_transaction_refused_without_implicit_commit_or_rollback','pass':True})
    return records


def main():
    ap=argparse.ArgumentParser(description=__doc__)
    ap.add_argument('--run-disposable',action='store_true',required=True)
    ap.add_argument('--report',type=Path)
    args=ap.parse_args()
    records=[concurrency(True),concurrency(False),
             *[cross_caller(kind,old) for kind in ('nonpaid','linker') for old in (True,False)],
             *boundaries()]
    report={'complete':True,'cases':len(records),'old_source':OLD,
            'current_head':subprocess.check_output(['git','-C',str(ROOT),'rev-parse','HEAD'],text=True).strip(),
            'native_php_mysqli':True,'owned_socket_only':True,'isolation_changed':False,
            'atomic_ready_marker_publication':True,
            'native_pk_lock_witness':all(r['native_lock_witness']['config_conflict_errno']==1205 and
                r['native_lock_witness']['config_before_verifier_release_errno']==1205 and
                r['native_lock_witness']['probe_transactions_rolled_back_and_closed'] and
                r['native_lock_witness']['actors_alive'] and
                r['native_lock_witness']['info_probe']['acquired'] is (not r['old_source_deadlock_observed'])
                for r in records[:2]),
            'live_provider_requests':0,'financial_http_retries':0,'cases_detail':records}
    if args.report:args.report.write_text(json.dumps(report,indent=2)+'\n')
    print(json.dumps({k:v for k,v in report.items() if k!='cases_detail'}))


if __name__=='__main__':main()
