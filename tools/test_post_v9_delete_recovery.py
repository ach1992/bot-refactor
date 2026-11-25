#!/usr/bin/env python3
"""Original account DELETE retry via user/staff Telegram callbacks (source-real).

Only isolated MariaDB/socket, local HTTP panel, synthetic Telegram. Never live.
"""
from __future__ import annotations
import argparse
import contextlib
import hashlib
import json
import threading
import time
from concurrent.futures import ThreadPoolExecutor
import subprocess
from pathlib import Path
from urllib.parse import urlsplit

import provider_storage_fixture as storage
import operational_report_fixture as reports
import native_identity_fixture as api
import test_post_v9_pasarguard_client as native

ROOT = Path(__file__).resolve().parents[1]
CREATED = '2026-10-26T05:00:00.123456Z'

@contextlib.contextmanager
def current_delete_sandbox():
    # This test exercises the current physical-service deletion guard, which
    # uses mandatory indexed locking reads and the current service ownership
    # journal. Install these only in the disposable database.
    with api.sandbox() as fixture:
        sql=fixture[0]
        for name in ('023_scoped_payment_lock_indexes.sql','024_wallet_entries.sql',
                     '025_wallet_receipt_credit.sql','026_service_operations.sql','033_checkout_commands.sql','035_service_resolution.sql'):
            sql((ROOT / 'migrations/post-v9' / name).read_text())
        reports.install(sql,has_service_schema=True)
        assert 'idx_pays_order_ref_v1' in sql('SHOW INDEX FROM pays')
        assert 'idx_pays_type_ref_v1' in sql('SHOW INDEX FROM pays')
        yield fixture


PHP = r'''<?php
error_reporting(E_ALL & ~E_DEPRECATED);
set_error_handler(static function($level,$message) {throw new ErrorException($message,0,$level);});
define('ACHBOT_PG_TEST_LOOPBACK',true);
$_SERVER['SCRIPT_FILENAME']=__FILE__;
$root=$argv[1];
$case=json_decode($argv[4],true,64,JSON_THROW_ON_ERROR);
$connection=new mysqli('localhost','root','',$argv[3],0,$argv[2]);
$connection->set_charset('utf8mb4');
$from_id=(int)($case['actor']??1001);$admin=9001;$telegramWebhookSecret='FixtureDeleteReportSecret2026';$userInfo=[];
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
function achbotNotifyAdmins(...$args){$GLOBALS['events'][]=['type'=>'staff_notice','data'=>$args[1],'options'=>$args[2]??[]];return [['response'=>(object)['ok'=>true]]];}
function sendToAllAdmins(...$args){$GLOBALS['events'][]=['type'=>'staff_notice'];return (object)['ok'=>true];}
function sendMessage(...$args){
    $ctx=$GLOBALS['achbotTelegramDiagnosticContext']??[];
    if (isset($ctx['operational_ref'])) {
        $r=achbotProviderRow($GLOBALS['connection'],'SELECT * FROM payment_followup_intents WHERE id=?','i',[$ctx['followup_id']]);
        $event=null;
        $packet=achbotFollowupDecodePacket(null,$r,$r['pay_fingerprint'],$event);
        if (!$packet || $r['state']!=='dispatching') throw new RuntimeException('fixture_uncommitted_report');
        $GLOBALS['events'][]=['type'=>'staff_notice','data'=>['text'=>$args[0],'parse_mode'=>$args[2]],
            'options'=>['report_ref'=>$event['reference'],'report_actor'=>$event['actor']]];
    } else $GLOBALS['events'][]=['type'=>'message'];
    return (object)['ok'=>true];
}
register_shutdown_function(static function() {
 echo json_encode(['events'=>$GLOBALS['events']],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
});
try {
    if ($data==='fixture-scheduled') {
        require_once $root.'/src/Finance/ReceiptHelpers.php';
        $buttonValues=['back_to_main'=>'Back','my_subscriptions'=>'Subs','sharj'=>'Wallet'];
        require $root.'/src/Notifications/Warnings.php';
        $GLOBALS['events'][]=['type'=>'cron_done'];
    } else {
        if (in_array($data,['fixture-claim-only','fixture-cron-claim-only','fixture-unpinned-only'],true))
        $data='fixture-no-route';
        require $root.'/src/Subscription/AccountDeletion.php';
    }
    if (in_array($case['callback'],[
        'fixture-claim-only','fixture-cron-claim-only','fixture-unpinned-only'],true)) {
        $unpinned=$case['callback']==='fixture-unpinned-only';
        $restoreCapacity=$case['callback']!=='fixture-cron-claim-only';
        $target=achbotProviderDeletionTarget($connection,2,'fixture_user');
        $ok=achbotAutoRenewPrepareDeletion($connection,41,null,
            static function(mysqli $db,array $order) use ($target,$unpinned,$restoreCapacity): void {
                if ((int)$order['id']!==41)throw new RuntimeException('fixture_order_changed');
                if (!$unpinned) {
                    achbotPgClaimOriginalDelete($db,$order,$target,$restoreCapacity);
                } else {
                    // Old installed sources did not pin original capacity
                    // policy, whether the DELETE began in Cron or in the UI.
                    $ctx=achbotPgOrderContext($db,41,true);
                    $key='delete:'.(int)$ctx['account']['id'];
                    achbotPgBeginMutation($db,$ctx,$key,'delete',['phase'=>'claimed'],true);
                }
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


def expect_retry(value):
    messages=[e['text'] for e in value['events'] if e['type']=='alert']
    assert messages and 'دوباره' in messages[-1] and 'بله' in messages[-1],value
    assert 'پشتیبانی' not in messages[-1] and 'بررسی' not in messages[-1],value


def expect_review(value):
    messages=[e['text'] for e in value['events'] if e['type']=='alert']
    assert messages and 'بررسی' in messages[-1] and 'دوباره' not in messages[-1],value


def case_retry(mode: str, actor: int, *, legacy: bool=False):
    checks=[]
    with current_delete_sandbox() as (sql, run, provider, epoch):
        provider_url=sql('SELECT panel_url FROM server_config WHERE id=2')
        if legacy: setup_legacy(sql,provider_url,epoch)
        else: setup(sql,provider_url,epoch)
        capacity_before=int(sql('SELECT ucount FROM server_info WHERE id=2'))
        callback=('yesDeleteUserConfig41' if actor==9001 else 'yesDeleteConfig41')
        if legacy: provider.user['note']='old-note-from-migration'
        before_user=json.loads(json.dumps(provider.user))
        provider.mode=mode
        first=driver(run,callback,actor)
        assert outcome(first)=='alert',(mode,first)
        expect_retry(first)
        assert sql('SELECT status FROM orders_list WHERE id=41')=='0',mode
        assert sql('SELECT autoRenew FROM orders_list WHERE id=41')=='off',mode
        assert sql('SELECT provider_state FROM service_effects WHERE id=1')=='mutating',mode
        assert sql('SELECT provider_mutation_key FROM service_effects WHERE id=1')=='delete:1',mode
        snapshot=json.loads(sql('SELECT provider_mutation_snapshot FROM service_effects WHERE id=1'))
        assert snapshot['delete_restore_capacity'] is True,(mode,snapshot)
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
        reports=[e for e in second['events'] if e['type']=='staff_notice']
        assert len(reports)==1 and reports[0]['options']['report_ref']=='delete:41',second
        assert '41' in reports[0]['data']['text'] and reports[0]['data']['parse_mode']=='HTML'
        assert reports[0]['options'].get('report_actor',0)==(9001 if actor==9001 else 0)
        assert second['events'].index(reports[0])<next(i for i,e in enumerate(second['events']) if e['type']=='edit')
        assert sql('SELECT COUNT(*) FROM orders_list WHERE id=41')=='0',mode
        assert sql('SELECT provider_state FROM service_effects WHERE id=1')=='deleted',mode
        assert provider.write_count==(2 if mode=='delete_lie' else 1),(mode,provider.trace)
        assert sql('SELECT ucount FROM server_info WHERE id=2')==str(
            capacity_before+(0 if legacy else 1)),(mode,legacy)
        checks.append(f'{"legacy_" if legacy else ""}{mode}_original_identity_resumed_without_duplicate_remote_delete')
        replay=driver(run,callback,actor)
        assert outcome(replay)=='alert',replay
        assert provider.write_count==(2 if mode=='delete_lie' else 1)
        assert sql('SELECT ucount FROM server_info WHERE id=2')==str(
            capacity_before+(0 if legacy else 1))
        checks.append(f'{"legacy_" if legacy else ""}{mode}_repeated_callback_after_success_no_effect')
    return checks



def case_local_retry(*, legacy: bool):
    # Real native deletion succeeds, but the original SQL finalizer fails.
    # The next callback must ONLY finalize locally, never DELETE by ID twice.
    with current_delete_sandbox() as (sql, run, provider, epoch):
        url=sql('SELECT panel_url FROM server_config WHERE id=2')
        if legacy: setup_legacy(sql,url,epoch)
        else: setup(sql,url,epoch)
        capacity_before=int(sql('SELECT ucount FROM server_info WHERE id=2'))
        if legacy:
            sql("CREATE TRIGGER test_delete_block BEFORE DELETE ON orders_list FOR EACH ROW "
                "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture_block_delete'")
        else:
            sql('UPDATE server_info SET ucount=2147483647 WHERE id=2')
        first=driver(run,'yesDeleteConfig41')
        assert outcome(first)=='alert',first
        expect_retry(first)
        assert sql('SELECT status FROM orders_list WHERE id=41')=='0'
        assert sql('SELECT provider_state FROM service_effects WHERE id=1')=='deleted'
        assert provider.user is None and provider.write_count==1
        if legacy: sql('DROP TRIGGER test_delete_block')
        else: sql(f'UPDATE server_info SET ucount={capacity_before} WHERE id=2')
        second=driver(run,'yesDeleteConfig41')
        assert any(e['type']=='edit' and 'موفقیت' in e['text'] for e in second['events']),second
        assert sql('SELECT COUNT(*) FROM orders_list WHERE id=41')=='0'
        assert provider.write_count==1
        assert sql('SELECT provider_state FROM service_effects WHERE id=1')=='deleted'
        assert sql('SELECT ucount FROM server_info WHERE id=2')==str(
            capacity_before+(0 if legacy else 1))
        replay=driver(run,'yesDeleteConfig41')
        assert outcome(replay)=='alert' and provider.write_count==1,replay
        assert sql('SELECT ucount FROM server_info WHERE id=2')==str(
            capacity_before+(0 if legacy else 1))
    return [f'{"legacy" if legacy else "created"}_verified_remote_deletion_retries_only_local_finalizer']


def case_changed_identity():
    with current_delete_sandbox() as (sql, run, provider, epoch):
        url=sql('SELECT panel_url FROM server_config WHERE id=2')
        setup(sql,url,epoch)
        provider.mode='delete_lie'
        initial=driver(run,'yesDeleteConfig41')
        assert outcome(initial)=='alert'
        expect_retry(initial)
        assert provider.write_count==1
        provider.mode='ok'
        original_created=provider.user['created_at']
        provider.user['created_at']='2025-01-01T00:00:00Z'
        protected=driver(run,'yesDeleteConfig41')
        assert outcome(protected)=='alert'
        expect_review(protected)
        assert sql('SELECT status FROM orders_list WHERE id=41')=='0'
        assert sql('SELECT provider_state FROM service_effects WHERE id=1')=='mutating'
        assert provider.write_count==1
        provider.user['created_at']=original_created
        assert any(e['type']=='edit' and 'موفقیت' in e['text'] for e in driver(run,'yesDeleteConfig41')['events'])
        assert provider.write_count==2
        assert sql('SELECT COUNT(*) FROM orders_list WHERE id=41')=='0'
    return ['replaced_incarnation_never_deleted_original_claim_resumes_once_restored']




def case_delete_readback_foreign_identity():
    # The DELETE gets HTTP204, but by-ID HTTP200 in the next GET is a FOREIGN
    # incarnation. It must not be interpreted as a retryable original account.
    with current_delete_sandbox() as (sql,run,provider,epoch):
        url=sql('SELECT panel_url FROM server_config WHERE id=2')
        setup(sql,url,epoch)
        provider.mode='delete_lie'
        original=api.DriftHandler.handle_request
        saw_delete=[False];injected=[False]

        def foreign_after_delete(self):
            path=urlsplit(self.path).path
            if self.command=='DELETE' and path=='/api/user/by-id/17':
                saw_delete[0]=True
            if (self.command=='GET' and path=='/api/user/by-id/17'
                    and saw_delete[0] and not injected[0]):
                injected[0]=True
                old_created=provider.user['created_at']
                provider.user['created_at']='2025-01-01T00:00:00Z'
                try:return original(self)
                finally:provider.user['created_at']=old_created
            return original(self)

        api.DriftHandler.handle_request=foreign_after_delete
        try: first=driver(run,'yesDeleteConfig41')
        finally:api.DriftHandler.handle_request=original
        assert saw_delete[0] and injected[0]
        assert outcome(first)=='alert',first
        expect_review(first)
        assert provider.write_count==1
        assert sql('SELECT status FROM orders_list WHERE id=41')=='0'
        assert sql('SELECT provider_state FROM service_effects WHERE id=1')=='mutating'
        provider.mode='ok'
        resumed=driver(run,'yesDeleteConfig41')
        assert any(e['type']=='edit' and 'موفقیت' in e['text'] for e in resumed['events']),resumed
        assert provider.write_count==2
        assert sql('SELECT COUNT(*) FROM orders_list WHERE id=41')=='0'
    return ['post_delete_http200_foreign_native_incarnation_requires_review_not_retry']


def case_recovery_authority_denied():
    # A real permission/identity barrier cannot be fixed merely by repeating
    # confirmation. Error guidance must not promise another DELETE attempt.
    with current_delete_sandbox() as (sql,run,provider,epoch):
        url=sql('SELECT panel_url FROM server_config WHERE id=2')
        setup(sql,url,epoch)
        provider.mode='delete_lie'
        first=driver(run,'yesDeleteConfig41')
        expect_retry(first)
        assert provider.write_count==1
        provider.mode='http_403'
        blocked=driver(run,'yesDeleteConfig41')
        expect_review(blocked)
        assert provider.write_count==1
        assert sql('SELECT provider_state FROM service_effects WHERE id=1')=='mutating'
        provider.mode='ok'
        restored=driver(run,'yesDeleteConfig41')
        assert any(e['type']=='edit' and 'موفقیت' in e['text'] for e in restored['events'])
        assert provider.write_count==2
    return ['native_permission_denied_requires_review_not_reconfirm_prompt']


def case_no_intent():
    with current_delete_sandbox() as (sql, run, provider, epoch):
        url=sql('SELECT panel_url FROM server_config WHERE id=2')
        setup(sql,url,epoch)
        sql("UPDATE orders_list SET status=0,autoRenew='off' WHERE id=41")
        denied=driver(run,'yesDeleteConfig41')
        assert outcome(denied)=='alert'
        expect_review(denied)
        assert provider.write_count==0
        assert sql('SELECT provider_state FROM service_effects WHERE id=1')=='linked'
    return ['zero_status_without_original_dispatched_deletion_cannot_trigger_native_delete']



def case_claimed_before_dispatch():
    checks=[]
    for native_absent in (False,True):
        with current_delete_sandbox() as (sql,run,provider,epoch):
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
    with current_delete_sandbox() as (sql,run,provider,epoch):
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



def case_scheduled_vs_manual():
    """Cron owns the original physical lock while its DELETE is in flight.

    Pause the real local HTTP DELETE after the durable Cron dispatch journal;
    a simultaneous original user confirmation must not issue another DELETE.
    Exercise success, verified HTTP204-but-account-remains and timed-out ACK.
    """
    checks=[]
    for mode in ('ok','delete_lie','write_timeout'):
        with current_delete_sandbox() as (sql,run,provider,epoch):
            url=sql('SELECT panel_url FROM server_config WHERE id=2')
            setup(sql,url,epoch)
            sql('UPDATE orders_list SET date=1700000000,notif=UNIX_TIMESTAMP()-1,'
                'expire_date=1700000000 WHERE id=41')
            provider.user['status']='expired'
            provider.user['expire']='2025-01-01T00:00:00Z'
            provider.mode=mode
            wallet_before=sql('SELECT wallet FROM users WHERE userid=1001')
            pays_before=sql('SELECT COUNT(*) FROM pays')
            capacity_before=int(sql('SELECT ucount FROM server_info WHERE id=2'))
            started=threading.Event();released=threading.Event()
            gate_guard=threading.Lock()
            in_flight=[0];highest=[0];delete_entries=[0]
            original=api.DriftHandler.handle_request

            def hold_delete(self):
                if self.command!='DELETE' or urlsplit(self.path).path!='/api/user/by-id/17':
                    return original(self)
                with gate_guard:
                    in_flight[0]+=1
                    delete_entries[0]+=1
                    highest[0]=max(highest[0],in_flight[0])
                started.set()
                try:
                    assert released.wait(9),'fixture_original_delete_gate_timed_out'
                    return original(self)
                finally:
                    with gate_guard:in_flight[0]-=1

            api.DriftHandler.handle_request=hold_delete
            try:
                with ThreadPoolExecutor(max_workers=2) as pool:
                    cron=pool.submit(driver,run,'fixture-scheduled')
                    try:
                        assert started.wait(12),'real_scheduled_delete_not_dispatched'
                        assert sql('SELECT status FROM orders_list WHERE id=41')=='0'
                        assert sql('SELECT provider_state FROM service_effects WHERE id=1')=='mutating'
                        snap=json.loads(sql('SELECT provider_mutation_snapshot FROM service_effects WHERE id=1'))
                        assert snap['delete_restore_capacity'] is False,(mode,snap)
                        assert sql("SELECT JSON_UNQUOTE(JSON_EXTRACT(provider_mutation_snapshot,'$.phase')) FROM service_effects WHERE id=1")=='dispatching'
                        callback='yesDeleteUserConfig41' if mode=='delete_lie' else 'yesDeleteConfig41'
                        actor=9001 if mode=='delete_lie' else 1001
                        pending=pool.submit(driver,run,callback,actor)
                        blocked=pending.result(timeout=7)
                        assert outcome(blocked)=='alert',blocked
                        expect_retry(blocked)
                        assert delete_entries[0]==1 and highest[0]==1,(mode,delete_entries,highest)
                        assert provider.write_count==0,(mode,provider.trace)
                        checks.append(mode+'_cron_original_in_flight_prevents_manual_duplicate_delete')
                    finally:
                        released.set()
                    cron_result=cron.result(timeout=28)
                assert any(e['type']=='cron_done' for e in cron_result['events']),cron_result
            finally:
                released.set()
                api.DriftHandler.handle_request=original
            assert delete_entries[0]==1 and highest[0]==1,(mode,delete_entries,highest)
            assert provider.write_count==1,(mode,provider.trace)
            assert sql('SELECT wallet FROM users WHERE userid=1001')==wallet_before
            assert sql('SELECT COUNT(*) FROM pays')==pays_before
            assert sql('SELECT ucount FROM server_info WHERE id=2')==str(capacity_before)
            if mode=='ok':
                assert sql('SELECT COUNT(*) FROM orders_list WHERE id=41')=='0'
                assert sql('SELECT provider_state FROM service_effects WHERE id=1')=='deleted'
                checks.append('cron_success_finishes_once_without_user_duplicate_or_financial_effect')
            else:
                assert sql('SELECT status FROM orders_list WHERE id=41')=='0'
                assert sql('SELECT provider_state FROM service_effects WHERE id=1')=='mutating'
                scheduled_again=driver(run,'fixture-scheduled')
                assert any(e['type']=='cron_done' for e in scheduled_again['events'])
                assert provider.write_count==1,'Cron must not automatically replay held DELETE'
                provider.mode='ok'
                resumed=driver(run,'yesDeleteConfig41')
                assert any(e['type']=='edit' and 'موفقیت' in e['text'] for e in resumed['events']),resumed
                assert sql('SELECT COUNT(*) FROM orders_list WHERE id=41')=='0'
                assert sql('SELECT provider_state FROM service_effects WHERE id=1')=='deleted'
                assert provider.write_count==(1 if mode=='write_timeout' else 2)
                assert sql('SELECT ucount FROM server_info WHERE id=2')==str(capacity_before)
                replay=driver(run,'yesDeleteConfig41')
                assert outcome(replay)=='alert' and provider.write_count==(
                    1 if mode=='write_timeout' else 2)
                assert sql('SELECT ucount FROM server_info WHERE id=2')==str(capacity_before)
                assert sql('SELECT wallet FROM users WHERE userid=1001')==wallet_before
                assert sql('SELECT COUNT(*) FROM pays')==pays_before
                checks.append(mode+'_cron_failure_requires_explicit_safe_reconfirmation_only')
    return checks



def case_original_capacity_claims():
    """A crash after the atomic claim/fence must not change origin policy."""
    checks=[]
    for cron in (False,True):
        for actor in (1001,9001):
            with current_delete_sandbox() as (sql,run,provider,epoch):
                setup(sql,sql('SELECT panel_url FROM server_config WHERE id=2'),epoch)
                capacity=int(sql('SELECT ucount FROM server_info WHERE id=2'))
                before_payments=sql('SELECT * FROM pays ORDER BY id')
                before_wallet=sql('SELECT wallet FROM users WHERE userid=1001')
                claim='fixture-cron-claim-only' if cron else 'fixture-claim-only'
                assert driver(run,claim)['events']==[{'type':'claim','ok':True}]
                snap=json.loads(sql('SELECT provider_mutation_snapshot FROM service_effects WHERE id=1'))
                assert snap['delete_restore_capacity'] is (not cron),snap
                assert snap['changes']=={'phase':'claimed'} and 'phase' not in snap
                assert provider.write_count==0
                callback='yesDeleteUserConfig41' if actor==9001 else 'yesDeleteConfig41'
                restored=driver(run,callback,actor)
                assert any(e['type']=='edit' and 'موفقیت' in e['text'] for e in restored['events']),restored
                assert provider.write_count==1 and provider.user is None
                expected=capacity+(0 if cron else 1)
                assert sql('SELECT ucount FROM server_info WHERE id=2')==str(expected)
                assert sql('SELECT * FROM pays ORDER BY id')==before_payments
                assert sql('SELECT wallet FROM users WHERE userid=1001')==before_wallet
                repeat=driver(run,callback,actor)
                assert outcome(repeat)=='alert' and provider.write_count==1
                assert sql('SELECT ucount FROM server_info WHERE id=2')==str(expected)
                checks.append(('cron' if cron else 'manual')+'_claimed_before_dispatch_'+
                    ('admin' if actor==9001 else 'owner')+'_preserves_initial_capacity_pin')
    return checks


def case_unpinned_legacy_capacity():
    """Do not guess whether an older unmarked claim originated in Cron/UI."""
    checks=[]
    for legacy in (True,False):
        with current_delete_sandbox() as (sql,run,provider,epoch):
            url=sql('SELECT panel_url FROM server_config WHERE id=2')
            if legacy:
                setup_legacy(sql,url,epoch)
                provider.user['note']='historical-note'
            else: setup(sql,url,epoch)
            capacity=int(sql('SELECT ucount FROM server_info WHERE id=2'))
            before_payment=sql('SELECT * FROM pays ORDER BY id')
            before_wallet=sql('SELECT wallet FROM users WHERE userid=1001')
            assert driver(run,'fixture-unpinned-only')['events']==[{'type':'claim','ok':True}]
            snap=json.loads(sql('SELECT provider_mutation_snapshot FROM service_effects WHERE id=1'))
            assert 'delete_restore_capacity' not in snap
            answer=driver(run,'yesDeleteConfig41')
            if legacy:
                assert any(e['type']=='edit' and 'موفقیت' in e['text'] for e in answer['events']),answer
                assert sql('SELECT COUNT(*) FROM orders_list WHERE id=41')=='0'
                assert provider.write_count==1
                checks.append('old_unpinned_migrated_account_no_reserved_capacity_completes_without_slot')
            else:
                expect_review(answer)
                assert sql('SELECT status FROM orders_list WHERE id=41')=='0'
                assert sql('SELECT provider_state FROM service_effects WHERE id=1')=='mutating'
                assert provider.write_count==0
                checks.append('old_unpinned_reserved_account_needs_proven_policy_before_recovery')
            assert sql('SELECT ucount FROM server_info WHERE id=2')==str(capacity)
            assert sql('SELECT * FROM pays ORDER BY id')==before_payment
            assert sql('SELECT wallet FROM users WHERE userid=1001')==before_wallet
    return checks



def case_cron_native_reactivated_before_delete():
    # A service can be renewed externally after an earlier expiry observation.
    # The Cron dispatch guard must check the final ORIGINAL Native identity
    # and entitlement immediately before sending the irreversible DELETE.
    with current_delete_sandbox() as (sql,run,provider,epoch):
        setup(sql,sql('SELECT panel_url FROM server_config WHERE id=2'),epoch)
        sql('UPDATE orders_list SET date=1700000000,notif=UNIX_TIMESTAMP()-1,'
            'expire_date=1700000000 WHERE id=41')
        provider.user['status']='expired'
        provider.user['expire']='2025-01-01T00:00:00Z'
        capacity=int(sql('SELECT ucount FROM server_info WHERE id=2'))
        original=api.DriftHandler.handle_request
        changed=[False]

        def renew_after_fence(self):
            if (self.command=='GET' and urlsplit(self.path).path=='/api/user/by-id/17' and
                sql('SELECT status FROM orders_list WHERE id=41')=='0' and not changed[0]):
                changed[0]=True
                provider.user['status']='active'
                provider.user['expire']='2100-01-01T00:00:00Z'
            return original(self)

        api.DriftHandler.handle_request=renew_after_fence
        try: cron=driver(run,'fixture-scheduled')
        finally:api.DriftHandler.handle_request=original
        assert changed[0] and outcome(cron)=='cron_done',cron
        assert provider.write_count==0 and provider.user is not None
        assert sql('SELECT status FROM orders_list WHERE id=41')=='0'
        snapshot=json.loads(sql('SELECT provider_mutation_snapshot FROM service_effects WHERE id=1'))
        assert snapshot['delete_restore_capacity'] is False
        assert snapshot['changes']=={'phase':'claimed'}
        assert sql('SELECT ucount FROM server_info WHERE id=2')==str(capacity)
        skipped=driver(run,'fixture-scheduled')
        assert outcome(skipped)=='cron_done' and provider.write_count==0
        # The owner may explicitly authorize deletion of the now-active
        # ORIGINAL account; this cannot change the original Cron capacity.
        restored=driver(run,'yesDeleteConfig41')
        assert any(e['type']=='edit' and 'موفقیت' in e['text'] for e in restored['events']),restored
        assert provider.write_count==1 and provider.user is None
        assert sql('SELECT ucount FROM server_info WHERE id=2')==str(capacity)
    return ['cron_final_by_id_read_rejects_reactivated_native_before_remote_delete']


def case_cron_local_finalizer_failure():
    checks=[]
    for actor in (1001,9001):
        with current_delete_sandbox() as (sql,run,provider,epoch):
            setup(sql,sql('SELECT panel_url FROM server_config WHERE id=2'),epoch)
            sql('UPDATE orders_list SET date=1700000000,notif=UNIX_TIMESTAMP()-1,'
                'expire_date=1700000000 WHERE id=41')
            provider.user['status']='expired'
            provider.user['expire']='2025-01-01T00:00:00Z'
            capacity=int(sql('SELECT ucount FROM server_info WHERE id=2'))
            wallet_before=sql('SELECT wallet FROM users WHERE userid=1001')
            pays_before=sql('SELECT * FROM pays ORDER BY id')
            sql("CREATE TRIGGER fixture_block_cron_finalize BEFORE DELETE ON orders_list "
                "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture_cron_finality'")
            cron=driver(run,'fixture-scheduled')
            assert outcome(cron)=='cron_done' and not any(e['type']=='message' for e in cron['events']),cron
            assert provider.user is None and provider.write_count==1
            assert sql('SELECT provider_state FROM service_effects WHERE id=1')=='deleted'
            assert sql('SELECT status FROM orders_list WHERE id=41')=='0'
            assert sql('SELECT ucount FROM server_info WHERE id=2')==str(capacity)
            held=driver(run,'fixture-scheduled')
            assert outcome(held)=='cron_done' and provider.write_count==1
            sql('DROP TRIGGER fixture_block_cron_finalize')
            callback='yesDeleteUserConfig41' if actor==9001 else 'yesDeleteConfig41'
            restored=driver(run,callback,actor)
            assert any(e['type']=='edit' and 'موفقیت' in e['text'] for e in restored['events']),restored
            assert provider.write_count==1
            assert sql('SELECT COUNT(*) FROM orders_list WHERE id=41')=='0'
            assert sql('SELECT ucount FROM server_info WHERE id=2')==str(capacity)
            assert sql('SELECT wallet FROM users WHERE userid=1001')==wallet_before
            assert sql('SELECT * FROM pays ORDER BY id')==pays_before
            repeated=driver(run,callback,actor)
            assert outcome(repeated)=='alert' and provider.write_count==1
            assert sql('SELECT ucount FROM server_info WHERE id=2')==str(capacity)
            checks.append('cron_native_deleted_sql_finality_failure_recovered_by_'+
                ('admin' if actor==9001 else 'owner')+'_without_capacity_change_or_second_delete')
    return checks


def case_concurrent():
    with current_delete_sandbox() as (sql, run, provider, epoch):
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




def case_native_finality_retry():
    # Remote DELETE definitely ran on the first request but its ACK was lost.
    # A second request fails specifically at the Native journal terminal write;
    # its third attempt must commit BOTH Native and SQL finality without DELETE.
    with current_delete_sandbox() as (sql, run, provider, epoch):
        url=sql('SELECT panel_url FROM server_config WHERE id=2')
        setup(sql,url,epoch)
        provider.mode='write_timeout'
        assert outcome(driver(run,'yesDeleteConfig41'))=='alert'
        assert provider.user is None and provider.write_count==1
        assert sql('SELECT provider_state FROM service_effects WHERE id=1')=='mutating'
        sql("CREATE TRIGGER fixture_block_effect BEFORE UPDATE ON service_effects "
            "FOR EACH ROW SIGNAL SQLSTATE '45000' "
            "SET MESSAGE_TEXT='fixture_block_native_finality'")
        provider.mode='ok'
        failed=driver(run,'yesDeleteConfig41')
        assert outcome(failed)=='alert',failed
        expect_retry(failed)
        assert sql('SELECT provider_state FROM service_effects WHERE id=1')=='mutating'
        assert sql('SELECT status FROM orders_list WHERE id=41')=='0'
        assert provider.write_count==1
        sql('DROP TRIGGER fixture_block_effect')
        recovered=driver(run,'yesDeleteConfig41')
        assert any(e['type']=='edit' and 'موفقیت' in e['text']
                   for e in recovered['events']),recovered
        assert sql('SELECT provider_state FROM service_effects WHERE id=1')=='deleted'
        assert sql('SELECT COUNT(*) FROM orders_list WHERE id=41')=='0'
        assert provider.write_count==1
    return ['lost_remote_ack_and_failed_native_finality_recovered_without_second_delete']


def case_shared_historical_reference():
    # A legitimate second order points at the SAME historical physical ID.
    # Neither its owner nor staff may fence one order then delete both users.
    with current_delete_sandbox() as (sql,run,provider,epoch):
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
        expect_review(first)
        assert sql('SELECT status FROM orders_list WHERE id=41')=='1'
        assert sql('SELECT status FROM orders_list WHERE id=42')=='1'
        assert sql('SELECT provider_state FROM service_effects WHERE id=1')=='linked'
        assert provider.write_count==0
    return ['shared_historical_physical_identity_blocks_before_order_fence_and_native_claim']


def case_unauthorized():
    with current_delete_sandbox() as (sql, run, provider, epoch):
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
    ap.add_argument('--case',choices=['all','owner','admin','legacy','unauthorized','local','drift','authority','readback_identity','concurrent','cron_race','claimed','capacity_claims','old_unpinned','cron_drift','cron_finality','shared','native_finality'],default='all')
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
    if args.case in ['all','authority']:
        checks.extend(case_recovery_authority_denied())
    if args.case in ['all','readback_identity']:
        checks.extend(case_delete_readback_foreign_identity())
    if args.case in ['all','concurrent']:
        checks.extend(case_concurrent())
    if args.case in ['all','cron_race']:
        checks.extend(case_scheduled_vs_manual())
    if args.case in ['all','claimed']:
        checks.extend(case_claimed_before_dispatch())
        checks.extend(case_atomic_claim_rollback())
    if args.case in ['all','capacity_claims']:
        checks.extend(case_original_capacity_claims())
    if args.case in ['all','old_unpinned']:
        checks.extend(case_unpinned_legacy_capacity())
    if args.case in ['all','cron_drift']:
        checks.extend(case_cron_native_reactivated_before_delete())
    if args.case in ['all','cron_finality']:
        checks.extend(case_cron_local_finalizer_failure())
    if args.case in ['all','shared']:
        checks.extend(case_shared_historical_reference())
    if args.case in ['all','native_finality']:
        checks.extend(case_native_finality_retry())
    payload={'complete':True,'cases':len(checks),'checks':checks,'php85_current_source':True,
        'owned_mariadb':True,'owned_http_provider':True,'live_mutations':0}
    if args.report:args.report.write_text(json.dumps(payload,indent=2)+'\n')
    print(json.dumps(payload))

if __name__=='__main__': main()
