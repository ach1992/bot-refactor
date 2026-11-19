#!/usr/bin/env python3
"""Original account DELETE retry via user/staff Telegram callbacks (source-real).

Only isolated MariaDB/socket, local HTTP panel, synthetic Telegram. Never live.
"""
from __future__ import annotations
import argparse
import hashlib
import json
import time
from concurrent.futures import ThreadPoolExecutor
import subprocess
from pathlib import Path

import provider_storage_fixture as storage
import test_post_v9_scoped_api_pg as api
import test_post_v9_pasarguard_client as native

ROOT = Path(__file__).resolve().parents[1]
CREATED = '2026-10-26T05:00:00.123456Z'

PHP = r'''<?php
error_reporting(E_ALL & ~E_DEPRECATED);
set_error_handler(static function($level,$message) {throw new ErrorException($message,0,$level);});
define('ACHBOT_PG_TEST_LOOPBACK',true);
$_SERVER['SCRIPT_FILENAME']=__FILE__;
$root=$argv[1];
$case=json_decode($argv[4],true,64,JSON_THROW_ON_ERROR);
$connection=new mysqli('localhost','root','',$argv[3],0,$argv[2]);
$connection->set_charset('utf8mb4');
$from_id=(int)($case['actor']??1001);$admin=9001;$userInfo=[];
$user=$connection->prepare('SELECT * FROM users WHERE userid=?');
$user->bind_param('i',$from_id);$user->execute();
$userInfo=$user->get_result()->fetch_assoc()??['isAdmin'=>0];$user->close();
$data=$case['callback'];$match=[];$first_name='Fixture';$username='fixture';
$message_id=5;$update=(object)['callback_query'=>(object)[
 'from'=>(object)['first_name'=>'Fixture','username'=>'fixture']]];
$buttonValues=['back_to_main'=>'Back'];
$GLOBALS['events']=[];
function achbotSupportsPanel($type){return in_array($type,['pasarguard','marzban'],true);}
function jdate(...$args){return 'fixture-date';}
function alert(...$args){$GLOBALS['events'][]=['type'=>'alert','text'=>$args[0]];return (object)['ok'=>true];}
function editText(...$args){$GLOBALS['events'][]=['type'=>'edit','text'=>$args[1]];return (object)['ok'=>true];}
function achbotNotifyAdmins(...$args){$GLOBALS['events'][]=['type'=>'staff_notice'];return (object)['ok'=>true];}
function sendToAllAdmins(...$args){$GLOBALS['events'][]=['type'=>'staff_notice'];return (object)['ok'=>true];}
function sendMessage(...$args){$GLOBALS['events'][]=['type'=>'message'];return (object)['ok'=>true];}
register_shutdown_function(static function() {
 echo json_encode(['events'=>$GLOBALS['events']],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
});
try {
    if ($data==='fixture-claim-only') $data='fixture-no-route';
    require $root.'/src/Subscription/AccountDeletion.php';
    if ($case['callback']==='fixture-claim-only') {
        $ctx=achbotPgOrderContext($connection,41);
        $key='delete:'.(int)$ctx['account']['id'];
        $ok=achbotAutoRenewPrepareDeletion($connection,41,null,
            static function(mysqli $db,array $order) use ($ctx,$key): void {
                if ((int)$order['id']!==41)throw new RuntimeException('fixture_order_changed');
                achbotPgBeginMutation($db,$ctx,$key,'delete',['phase'=>'claimed'],true);
            });
        $GLOBALS['events'][]=['type'=>'claim','ok'=>$ok];
    }
} catch(Throwable $error) { $GLOBALS['events'][]=['type'=>'error','code'=>$error->getMessage()]; }
'''


def setup(sql, provider_url: str, epoch: int):
    sql("UPDATE service_effects SET source_kind='admin_bulk',effect_kind='create',"
        "state='local_recorded',order_id=41,reserved_count=1 WHERE id=1")
    sql(storage.identity(1, 41, 1, provider_url, 17, CREATED, 'fixture_user',
        native.MARKER, {'group_ids': [2, 5], 'data_limit': native.USER['data_limit'],
                        'expire': epoch, 'status': 'active'}, existing=True))
    assert sql('SELECT provider_state FROM service_effects WHERE id=1') == 'linked'
    assert sql('SELECT status FROM orders_list WHERE id=41') == '1'



def setup_legacy(sql, provider_url: str, epoch: int):
    """One real historical-cutover reference; immutable native/origin proof."""
    snapshot={
        'provider':'pasarguard','panel_id':1,'instance_key':api.INSTANCE,
        'endpoint_sha256':hashlib.sha256(provider_url.encode()).hexdigest(),
        'group_ids':[2,5],'identity_mode':'historical_cutover',
        'migration':{'version':1,'tenant':'hell','source_server_id':2,
            'source_input_sha256':'a'*64,'native_input_sha256':'b'*64,
            'mapping_input_sha256':'c'*64,
            'references':[{'order_id':41,'owner_id':1001,'plan_id':7}]}}
    native_snapshot={'version':1,'id':17,'username':'fixture_user',
        'created_at':CREATED,'group_ids':[2,5],
        'data_limit':native.USER['data_limit'],'used_traffic':10,
        'expire':epoch,'status':'active','native_schedules_clear':True}
    encoded=json.dumps(snapshot,separators=(',',':'))
    record=json.dumps(native_snapshot,separators=(',',':'))
    digest=hashlib.sha256(encoded.encode()).hexdigest()
    source_ref='legacy:2:'+hashlib.sha256(b'fixture_user').hexdigest()
    sql('UPDATE service_effects SET '
        "source_kind='legacy_migration',actor_userid=NULL,ordinal=1,reserved_count=0,"
        "effect_kind='adopt',operation_id=REPEAT('d',32),target_server_id=2,"
        'target_key='+api.quote_sql('pg:'+api.INSTANCE)+','
        'source_ref='+api.quote_sql(source_ref)+','
        'endpoint_sha256='+api.quote_sql(snapshot['endpoint_sha256'])+','
        "plan_id=0,remote_username='fixture_user',"
        'requested_expire='+str(epoch)+','
        'requested_bytes='+str(native.USER['data_limit'])+','
        'request_sha256='+api.quote_sql(digest)+','
        "state='local_recorded',order_id=NULL,verified_at=UTC_TIMESTAMP(),"
        'provider_snapshot='+api.quote_sql(encoded)+','
        'provider_reference_id=1,provider_connection_id=1,'
        'provider_remote_user_id=17,provider_remote_created_at='+api.quote_sql(CREATED)+','
        'provider_operation_marker='+api.quote_sql('achbot:adopt:'+'d'*32)+','
        'provider_entitlement_snapshot='+api.quote_sql(record)+','
        "provider_state='linked' WHERE id=1")
    sql('UPDATE orders_list SET provider_effect_id=1,provider_remote_user_id=17,'
        'provider_remote_created_at='+api.quote_sql(CREATED)+' WHERE id=41')
    assert sql('SELECT source_kind FROM service_effects WHERE id=1') == 'legacy_migration'


def driver(run, callback: str, actor: int = 1001):
    script = run.fixture_work / 'deletion-route.php'
    if not script.exists(): script.write_text(PHP)
    p = subprocess.run(['php','-n','-d','extension=mysqlnd','-d','extension=mysqli',
        '-d','extension=curl','-d','allow_url_fopen=0','-d','log_errors=1',
        '-d','error_log=/dev/stderr',str(script),str(ROOT),
        str(run.fixture_socket),api.DB,json.dumps({'callback':callback,'actor':actor})],
        cwd=run.fixture_work,env=run.fixture_env,capture_output=True,text=True,timeout=35)
    if p.returncode or not p.stdout.strip():
        raise AssertionError(('php_failed',callback,p.returncode,p.stderr[-1000:],p.stdout[-400:]))
    if 'fixture-private-key' in p.stderr or 'fixture-private-key' in p.stdout:
        raise AssertionError('private Native credential emitted')
    result=json.loads(p.stdout)
    if any(e['type']=='error' for e in result['events']):
        raise AssertionError(('php_exception',callback,result,p.stderr[-600:]))
    return result


def outcome(value) -> str:
    return value['events'][-1]['type'] if value['events'] else 'none'


def case_retry(mode: str, actor: int, *, legacy: bool=False):
    checks=[]
    with api.sandbox() as (sql, run, provider, _bank, epoch):
        provider_url=sql('SELECT panel_url FROM server_config WHERE id=2')
        if legacy: setup_legacy(sql,provider_url,epoch)
        else: setup(sql,provider_url,epoch)
        callback=('yesDeleteUserConfig41' if actor==9001 else 'yesDeleteConfig41')
        if legacy: provider.user['note']='old-note-from-migration'
        before_user=json.loads(json.dumps(provider.user))
        provider.mode=mode
        first=driver(run,callback,actor)
        assert outcome(first)=='alert',(mode,first)
        assert sql('SELECT status FROM orders_list WHERE id=41')=='0',mode
        assert sql('SELECT autoRenew FROM orders_list WHERE id=41')=='off',mode
        assert sql('SELECT provider_state FROM service_effects WHERE id=1')=='mutating',mode
        assert sql('SELECT provider_mutation_key FROM service_effects WHERE id=1')=='delete:1',mode
        assert sql('SELECT JSON_UNQUOTE(JSON_EXTRACT(provider_mutation_snapshot,\'$.phase\')) FROM service_effects WHERE id=1')=='dispatching',mode
        assert provider.write_count==1,(mode,provider.trace)
        checks.append(f'{"legacy_" if legacy else ""}{mode}_original_callback_held_and_original_native_attempt_once')
        if mode=='delete_lie':
            assert provider.user is not None,mode
            assert provider.user==before_user,mode
        else:
            assert provider.user is None,mode
        provider.mode='ok'
        second=driver(run,callback,actor)
        assert any(event['type']=='edit' and 'موفقیت' in event['text'] for event in second['events']),(mode,second)
        assert sql('SELECT COUNT(*) FROM orders_list WHERE id=41')=='0',mode
        assert sql('SELECT provider_state FROM service_effects WHERE id=1')=='deleted',mode
        assert provider.write_count==(2 if mode=='delete_lie' else 1),(mode,provider.trace)
        checks.append(f'{"legacy_" if legacy else ""}{mode}_original_identity_resumed_without_duplicate_remote_delete')
        replay=driver(run,callback,actor)
        assert outcome(replay)=='alert',replay
        assert provider.write_count==(2 if mode=='delete_lie' else 1)
        checks.append(f'{"legacy_" if legacy else ""}{mode}_repeated_callback_after_success_no_effect')
    return checks



def case_local_retry(*, legacy: bool):
    # Real native deletion succeeds, but the original SQL finalizer fails.
    # The next callback must ONLY finalize locally, never DELETE by ID twice.
    with api.sandbox() as (sql, run, provider, _bank, epoch):
        url=sql('SELECT panel_url FROM server_config WHERE id=2')
        if legacy: setup_legacy(sql,url,epoch)
        else: setup(sql,url,epoch)
        if legacy:
            sql("CREATE TRIGGER test_delete_block BEFORE DELETE ON orders_list FOR EACH ROW "
                "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture_block_delete'")
        else:
            sql('UPDATE server_info SET ucount=2147483647 WHERE id=2')
        first=driver(run,'yesDeleteConfig41')
        assert outcome(first)=='alert',first
        assert sql('SELECT status FROM orders_list WHERE id=41')=='0'
        assert sql('SELECT provider_state FROM service_effects WHERE id=1')=='deleted'
        assert provider.user is None and provider.write_count==1
        if legacy: sql('DROP TRIGGER test_delete_block')
        else: sql('UPDATE server_info SET ucount=8 WHERE id=2')
        second=driver(run,'yesDeleteConfig41')
        assert any(e['type']=='edit' and 'موفقیت' in e['text'] for e in second['events']),second
        assert sql('SELECT COUNT(*) FROM orders_list WHERE id=41')=='0'
        assert provider.write_count==1
        assert sql('SELECT provider_state FROM service_effects WHERE id=1')=='deleted'
    return [f'{"legacy" if legacy else "created"}_verified_remote_deletion_retries_only_local_finalizer']


def case_changed_identity():
    with api.sandbox() as (sql, run, provider, _bank, epoch):
        url=sql('SELECT panel_url FROM server_config WHERE id=2')
        setup(sql,url,epoch)
        provider.mode='delete_lie'
        assert outcome(driver(run,'yesDeleteConfig41'))=='alert'
        assert provider.write_count==1
        provider.mode='ok'
        original_created=provider.user['created_at']
        provider.user['created_at']='2025-01-01T00:00:00Z'
        assert outcome(driver(run,'yesDeleteConfig41'))=='alert'
        assert sql('SELECT status FROM orders_list WHERE id=41')=='0'
        assert sql('SELECT provider_state FROM service_effects WHERE id=1')=='mutating'
        assert provider.write_count==1
        provider.user['created_at']=original_created
        assert any(e['type']=='edit' and 'موفقیت' in e['text'] for e in driver(run,'yesDeleteConfig41')['events'])
        assert provider.write_count==2
        assert sql('SELECT COUNT(*) FROM orders_list WHERE id=41')=='0'
    return ['replaced_incarnation_never_deleted_original_claim_resumes_once_restored']


def case_no_intent():
    with api.sandbox() as (sql, run, provider, _bank, epoch):
        url=sql('SELECT panel_url FROM server_config WHERE id=2')
        setup(sql,url,epoch)
        sql("UPDATE orders_list SET status=0,autoRenew='off' WHERE id=41")
        assert outcome(driver(run,'yesDeleteConfig41'))=='alert'
        assert provider.write_count==0
        assert sql('SELECT provider_state FROM service_effects WHERE id=1')=='linked'
    return ['zero_status_without_original_dispatched_deletion_cannot_trigger_native_delete']



def case_claimed_before_dispatch():
    checks=[]
    for native_absent in (False,True):
        with api.sandbox() as (sql,run,provider,_bank,epoch):
            url=sql('SELECT panel_url FROM server_config WHERE id=2')
            setup(sql,url,epoch)
            result=driver(run,'fixture-claim-only')
            assert result['events']==[{'type':'claim','ok':True}],result
            assert sql('SELECT status FROM orders_list WHERE id=41')=='0'
            assert sql('SELECT provider_state FROM service_effects WHERE id=1')=='mutating'
            assert sql("SELECT JSON_EXTRACT(provider_mutation_snapshot,'$.phase') IS NULL FROM service_effects WHERE id=1")== '1'
            assert provider.write_count==0
            if native_absent:provider.user=None
            resumed=driver(run,'yesDeleteConfig41')
            assert any(e['type']=='edit' and 'موفقیت' in e['text'] for e in resumed['events']),resumed
            assert sql('SELECT COUNT(*) FROM orders_list WHERE id=41')=='0'
            assert sql('SELECT provider_state FROM service_effects WHERE id=1')=='deleted'
            assert provider.write_count==(0 if native_absent else 1)
            checks.append('committed_claimed_before_dispatched_resumes_'+('already_absent' if native_absent else 'same_original_native_id'))
    return checks


def case_atomic_claim_rollback():
    with api.sandbox() as (sql,run,provider,_bank,epoch):
        url=sql('SELECT panel_url FROM server_config WHERE id=2')
        setup(sql,url,epoch)
        sql("CREATE TRIGGER fixture_block_status BEFORE UPDATE ON orders_list FOR EACH ROW "
            "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture_block_status'")
        result=driver(run,'fixture-claim-only')
        assert result['events']==[{'type':'claim','ok':False}],result
        assert sql('SELECT status FROM orders_list WHERE id=41')=='1'
        assert sql('SELECT provider_state FROM service_effects WHERE id=1')=='linked'
        assert provider.write_count==0
    return ['failed_local_fence_rolls_back_uncommitted_native_claim_atomically']


def case_concurrent():
    with api.sandbox() as (sql, run, provider, _bank, epoch):
        url=sql('SELECT panel_url FROM server_config WHERE id=2')
        setup(sql,url,epoch)
        provider.mode='write_timeout'
        with ThreadPoolExecutor(max_workers=2) as pool:
            first=pool.submit(driver,run,'yesDeleteConfig41')
            # Wait for the original durable claim; never race on a wall-clock guess.
            for _ in range(80):
                if sql('SELECT status FROM orders_list WHERE id=41')=='0': break
                time.sleep(.075)
            else: raise AssertionError('first_delete_did_not_claim')
            second=pool.submit(driver,run,'yesDeleteConfig41')
            assert outcome(second.result(timeout=8))=='alert'
            assert outcome(first.result(timeout=25))=='alert'
        assert provider.write_count==1
        assert sql('SELECT provider_state FROM service_effects WHERE id=1')=='mutating'
        provider.mode='ok'
        assert any(e['type']=='edit' and 'موفقیت' in e['text'] for e in driver(run,'yesDeleteConfig41')['events'])
        assert provider.write_count==1
    return ['concurrent_repeated_confirmation_waits_for_original_physical_account_lease']



def case_shared_historical_reference():
    # A legitimate second order points at the SAME historical physical ID.
    # Neither its owner nor staff may fence one order then delete both users.
    with api.sandbox() as (sql,run,provider,_bank,epoch):
        url=sql('SELECT panel_url FROM server_config WHERE id=2')
        setup_legacy(sql,url,epoch)
        snapshot=json.loads(sql('SELECT provider_snapshot FROM service_effects WHERE id=1'))
        snapshot['migration']['references'].append(
            {'order_id':42,'owner_id':1001,'plan_id':7})
        encoded=json.dumps(snapshot,separators=(',',':'))
        digest=hashlib.sha256(encoded.encode()).hexdigest()
        sql('UPDATE service_effects SET provider_snapshot='+api.quote_sql(encoded)+
            ',request_sha256='+api.quote_sql(digest)+' WHERE id=1')
        sql("INSERT INTO orders_list (id,userid,token,transid,fileid,server_id,"
            "inbound_id,remark,uuid,expire_date,link,amount,status,date,autoRenew,"
            "provider_effect_id,provider_remote_user_id,provider_remote_created_at) VALUES "
            "(42,1001,'second-local-token','',7,2,0,'fixture_user',"
            "'second-proxy',0,'[]',1200,1,'fixture','off',1,17,"+
            api.quote_sql(CREATED)+")")
        first=driver(run,'yesDeleteUserConfig41',9001)
        assert outcome(first)=='alert',first
        assert sql('SELECT status FROM orders_list WHERE id=41')=='1'
        assert sql('SELECT status FROM orders_list WHERE id=42')=='1'
        assert sql('SELECT provider_state FROM service_effects WHERE id=1')=='linked'
        assert provider.write_count==0
    return ['shared_historical_physical_identity_blocks_before_order_fence_and_native_claim']


def case_unauthorized():
    with api.sandbox() as (sql, run, provider, _bank, epoch):
        url=sql('SELECT panel_url FROM server_config WHERE id=2')
        setup(sql,url,epoch)
        result=driver(run,'yesDeleteConfig41',actor=1002)
        assert outcome(result)=='alert',result
        assert sql('SELECT status FROM orders_list WHERE id=41')=='1'
        assert provider.write_count==0
    return ['foreign_owner_cannot_delete_or_consume_native_intent']


def main():
    ap=argparse.ArgumentParser()
    ap.add_argument('--run-disposable',required=True,action='store_true')
    ap.add_argument('--case',choices=['all','owner','admin','legacy','unauthorized','local','drift','concurrent','claimed','shared'],default='all')
    ap.add_argument('--report',type=Path)
    args=ap.parse_args();checks=[]
    if args.case in ['all','owner']:
        for mode in ['delete_lie','write_timeout']:
            checks.extend(case_retry(mode,1001))
    if args.case in ['all','admin']:
        checks.extend(case_retry('delete_lie',9001))
    if args.case in ['all','legacy']:
        checks.extend(case_retry('write_timeout',1001,legacy=True))
    if args.case in ['all','unauthorized']:
        checks.extend(case_unauthorized())
    if args.case in ['all','local']:
        checks.extend(case_local_retry(legacy=False))
        checks.extend(case_local_retry(legacy=True))
    if args.case in ['all','drift']:
        checks.extend(case_changed_identity())
        checks.extend(case_no_intent())
    if args.case in ['all','concurrent']:
        checks.extend(case_concurrent())
    if args.case in ['all','claimed']:
        checks.extend(case_claimed_before_dispatch())
        checks.extend(case_atomic_claim_rollback())
    if args.case in ['all','shared']:
        checks.extend(case_shared_historical_reference())
    payload={'complete':True,'cases':len(checks),'checks':checks,'php85_current_source':True,
        'owned_mariadb':True,'owned_http_provider':True,'live_mutations':0}
    if args.report:args.report.write_text(json.dumps(payload,indent=2)+'\n')
    print(json.dumps(payload))

if __name__=='__main__': main()
