#!/usr/bin/env python3
"""Source-real original HOSTED BUY_SUB paid claim + fully linked delivery recovery.

All payments and native accounts are synthetic, on disposable MariaDB and
loopback providers only. PHP 8.5 is mandatory. Never contacts merchant,
production Chili, customer Telegram or any outside network.
"""
from __future__ import annotations
import argparse
import json
import subprocess
import time
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path
import test_access_state as owned
import test_v6_native as native
import test_v8_native as purchase
import test_post_v9_wallet_recovery as wallet
import provider_storage_fixture as pgstorage

ROOT=Path(__file__).resolve().parents[1]
DB=purchase.SIDE[1]

ORIGINAL=r"""<?php
error_reporting(E_ALL & ~E_DEPRECATED);
set_error_handler(static function($n,$m){throw new ErrorException($m,0,$n);});
if (PHP_VERSION_ID<80500 || PHP_VERSION_ID>=80600)
    throw new RuntimeException('php85_required');
define('ACHBOT_PG_TEST_LOOPBACK',true);
// Source GatewayReturn.php contains browser/IPN routes at include time.
// The canonical internal-only flag loads its original claim helpers without
// executing any public return handler or rendering an HTTP page.
define('ACHBOT_NP_RECONCILE_ONLY',true);
$_SERVER['SCRIPT_FILENAME']=__FILE__;
$root=$argv[1];$spec=json_decode($argv[4],true,64,JSON_THROW_ON_ERROR);
$connection=new mysqli('localhost','root','',$argv[3],0,$argv[2]);
$connection->set_charset('utf8mb4');
$GLOBALS['telegramWebhookSecret']='fixture-bank-webhook-secret-32-byte-valid';
$GLOBALS['events']=[];
function createServerAccount(...$args) {
    if (($GLOBALS['spec']['panel']??'')!=='marzban')
        throw new RuntimeException('pg_must_use_native_adapter');
    $name=(string)$args[4];
    $GLOBALS['events'][]=['event'=>'POST','name'=>$name];
    $GLOBALS['originalNative'][$name]=(object)[
        'username'=>$name,'note'=>'achbot:v1:'.(string)$args[14],
        'expire'=>(int)$args[15],
        'data_limit'=>(int)$args[5]*1073741824,
        'status'=>'active','subscription_url'=>'/sub/original-hosted',
        'links'=>['vless://hosted.invalid']];
    return [(object)['success'=>true],$name,12345];
}
function getMarzbanUser(...$args) {
    if (($args[3]??false)!==true)
        throw new RuntimeException('native_strict_read_required');
    $GLOBALS['events'][]=['event'=>'GET','name'=>$args[1]];
    return $GLOBALS['originalNative'][$args[1]]??null;
}
require_once $root.'/src/Purchase/Core.php';
require_once $root.'/src/Purchase/OrderWrites.php';
require_once $root.'/src/Finance/ReceiptHelpers.php';
require_once $root.'/src/Finance/GatewayReturn.php';
require_once $root.'/src/Panel/ProviderRuntime.php';
$pay=(int)$spec['pay'];$provider=(string)$spec['provider'];
if (($spec['panel']??'')==='pasarguard') achbotPgPinPayment($connection,$pay);
// The exact hosted paid+review claim is the production source transition.
// The synthetic provider's previous verification is staged in 003 by seed.
$q=$connection->prepare('SELECT hash_id,price,agent_count,server_id,plan_id,user_id
    FROM pays WHERE id=?');$q->bind_param('i',$pay);$q->execute();
$row=$q->get_result()->fetch_assoc();$q->close();
if (!$row) throw new RuntimeException('hosted_fixture_pay_missing');
if (!achbotClaimHostedServiceForReview($connection,$pay,(int)$row['price'],
        $provider,(string)$row['hash_id']))
    throw new RuntimeException('original_hosted_paid_claim_unconfirmed');
$count=max(1,(int)$row['agent_count']);
foreach (range(1,(int)($spec['create_prefix']??$count)) as $ordinal) {
    [$remote,$name,$port,$effect]=achbotCreatePaidAccount($connection,$pay,$ordinal,[
        'user_id'=>(int)$row['user_id'],'server_type'=>(string)$spec['panel'],
        'server_id'=>(int)$row['server_id'],'inbound_id'=>0,
        'plan_id'=>(int)$row['plan_id'],
        'remark'=>'hostedoriginal_'.$pay.'_'.$ordinal,
        'volume'=>2,'days'=>30,'uniqid'=>'fixture-original-uuid',
        'protocol'=>'vless','port'=>0,'expire_microdate'=>0,
        'net_type'=>'normal','rahgozar'=>0,'limitip'=>0
    ]);
    if (!is_object($remote) || ($remote->success??false)!==true)
        throw new RuntimeException('native_original_create_unconfirmed');
    if ($ordinal<=(int)($spec['link_prefix']??$count)) {
        if (!$connection->begin_transaction())
            throw new RuntimeException('original_link_begin_unconfirmed');
        try {
            $order=insertOrder($connection,(int)$row['user_id'],'fixture-hosted-token',
                (int)$row['plan_id'],(int)$row['server_id'],0,$name,
                'fixture-original-uuid',time()+86400*30,'fixture-hosted-link',
                achbotAllocateOrderAmount((int)$row['price'],$count,$ordinal),
                time(),0,true);
            achbotLinkPaidAccountEffect($connection,$effect,$order);
            if (!$connection->commit())
                throw new RuntimeException('original_link_commit_unconfirmed');
        } catch(Throwable $e){$connection->rollback();throw $e;}
    }
}
// The worker stops HERE to model an actual crash before 003 closure/018.
echo json_encode(['paid_claim'=>true,'events'=>$GLOBALS['events']],
    JSON_THROW_ON_ERROR);
"""

RECOVERY=wallet.RECOVERY.replace(
    "achbotWalletBuyRecoverable", "achbotHostedLinkedBuyRecoverable"
).replace(
    "achbotResumeWalletBuy", "achbotResumeHostedLinkedBuy"
).replace("walletBuyResume_","hostedBuyResume_")

def sql(work,env,query):
    return wallet.sql(work,env,query)

def check():
    scenarios=[]
    with owned.owned_database() as (work,env),wallet.wallet_panel() as (server,url):
        purchase.init(work,env,url)
        for f in ('001_marzban_panel_group_id.sql',
                  '003_payment_attempts.sql','007_service_effects.sql',
                  '011_provider_identity.sql','012_shared_provider_storage.sql'):
            sql(work,env,(ROOT/'migrations/post-v9'/f).read_text())
        sql(work,env,pgstorage.connection(2,1,url)+
            "UPDATE server_plans SET provider_group_ids='[2,5]' WHERE id IN (7,8)")
        for f in ('016_remove_pasarguard_server_groups.sql',
                  '017_shared_plan_changes.sql','018_payment_delivery_intents.sql',
                  '019_payment_review_decisions.sql'):
            sql(work,env,(ROOT/'migrations/post-v9'/f).read_text())
        driver=work/'hosted-linked-recovery.php';driver.write_text(RECOVERY)
        original=work/'hosted-original-native.php';original.write_text(ORIGINAL)

        def run(pay,pg=False,**opts):
            return wallet.recover(work,env,driver,pg_native=pg,pay=pay,**opts)
        def call_original(pay,provider,panel,count,create_prefix=None,link_prefix=None):
            spec={'pay':pay,'provider':provider,'panel':panel,
                  'create_prefix':count if create_prefix is None else create_prefix,
                  'link_prefix':count if link_prefix is None else link_prefix}
            command=['php','-n','-d','extension=mysqlnd','-d','extension=mysqli',
                     '-d','extension=curl','-d','extension=ctype',
                     '-d','disable_functions=exec,shell_exec,system,passthru,popen,proc_open,mail',
                     str(original),str(ROOT),str(work/'mysql.sock'),DB,json.dumps(spec)]
            p=subprocess.run(command,cwd=work,env=env,capture_output=True,
                             text=True,timeout=40)
            if p.returncode or p.stderr or not p.stdout.strip():
                raise RuntimeError('original hosted source '+repr((p.returncode,p.stderr[-1200:],p.stdout[-200:])))
            return json.loads(p.stdout)
        def seed(tag,provider='nextpay',panel='marzban',count=1,
                 create_prefix=None,link_prefix=None):
            assert tag.isascii() and tag.isalnum()
            sql(work,env,"UPDATE server_info SET ucount=10,active=1,state=1 WHERE id=2; "
                "UPDATE users SET wallet=50000 WHERE userid=1001")
            if panel=='marzban':
                sql(work,env,"UPDATE server_config SET type='marzban' WHERE id=2")
            else:
                sql(work,env,pgstorage.connection(2,1,url))
            # All source-native effects originate from the real hosted claim.
            ref='hosted-'+tag
            sql(work,env,"INSERT INTO pays(hash_id,user_id,type,plan_id,server_id,agent_count,"
                "price,day,volume,request_date,state,payid) "
                f"VALUES('{tag}',1001,'BUY_SUB',7,2,{count},100,0,0,UNIX_TIMESTAMP(),'pending','{ref}')")
            pay=int(sql(work,env,f"SELECT id FROM pays WHERE hash_id='{tag}'"))
            sql(work,env,"INSERT INTO payment_attempts "
                "(pay_row_id,provider,order_hash,provider_reference,quote_amount,"
                "quote_currency,local_amount_toman,stage,provider_status) "
                f"VALUES({pay},'{provider}','{tag}','{ref}',100,'toman',100,'pending','verified')")
            server.block_posts=False;server.trace=[];server.mode='ok'
            origin=call_original(pay,provider,panel,count,create_prefix,link_prefix)
            assert origin['paid_claim'],origin
            assert sql(work,env,f'SELECT state FROM pays WHERE id={pay}')=='paid'
            assert sql(work,env,'SELECT stage,provider_status FROM payment_attempts '
                f"WHERE pay_row_id={pay}")=='review\tfulfillment_started'
            return pay,origin
        def count(table,pay):
            if table=='orders_list':
                return int(sql(work,env,"SELECT COUNT(*) FROM orders_list o "
                    "JOIN service_effects e ON e.order_id=o.id "
                    f"WHERE e.pay_row_id={pay}"))
            return int(sql(work,env,f'SELECT COUNT(*) FROM {table} WHERE pay_row_id={pay}'))
        def balances():
            return (int(sql(work,env,'SELECT wallet FROM users WHERE userid=1001')),
                    int(sql(work,env,'SELECT ucount FROM server_info WHERE id=2')))
        def stage(pay):
            return sql(work,env,'SELECT stage,provider_status FROM payment_attempts '
                f'WHERE pay_row_id={pay}')
        def native_trace():
            return [(method,path) for method,path,_ in server.trace]
        def after_readback(pay,action,pg=True):
            # Two genuine PHP/SQL sessions, with a test-only barrier exactly
            # after native GET, before the terminal SQL transaction.
            pause=work/f'hosted-after-readback-{pay}'
            with ThreadPoolExecutor(max_workers=1) as pool:
                future=pool.submit(lambda:run(pay,pg=pg,pause_after_readback=str(pause)))
                limit=time.monotonic()+12
                while not (work/f'hosted-after-readback-{pay}.ready').exists():
                    if future.done():raise AssertionError(('hosted_no_barrier',future.result()))
                    if time.monotonic()>limit:raise AssertionError('hosted_barrier_timeout')
                    time.sleep(.03)
                try:action()
                finally:(work/f'hosted-after-readback-{pay}.release').write_text('continue')
                return future.result(timeout=45)
        def native_revoke(pay):
            mutation=work/'hosted-native-mutation.php'
            mutation.write_text(wallet.NATIVE_MUTATION)
            spec={'server_id':2,'remark':f'hostedoriginal_{pay}_1'}
            run=subprocess.run(['php','-n','-d','extension=mysqlnd',
                '-d','extension=mysqli','-d','extension=curl','-d','extension=ctype',
                '-d','disable_functions=exec,shell_exec,system,passthru,popen,proc_open,mail',
                str(mutation),str(ROOT),str(work/'mysql.sock'),DB,json.dumps(spec)],
                cwd=work,env=env,capture_output=True,text=True,timeout=30)
            assert run.returncode==0,(run.stderr,run.stdout)
            result=json.loads(run.stdout)
            assert result['success'] and result['verified'],result
        def clean_get():
            server.block_posts=True;server.trace=[]
        def check_result(pay,pg,count_value):
            assert stage(pay)=='done\tdelivered'
            assert count('orders_list',pay)==count_value
            assert count('payment_delivery_intents',pay)==count_value
            assert run(pay,pg,op='check',ordinal=1)['result']=='acknowledged'
            assert not any(method=='POST' for method,_ in native_trace())

        # Reproduce the original confirmed problem: 003 is held in review
        # even with all original 007 orders locally linked and no sealed 018.
        pay,origin=seed('hostedlinknext',count=2)
        assert sum(e['event']=='POST' for e in origin['events'])==2
        assert count('orders_list',pay)==2 and count('payment_delivery_intents',pay)==0
        assert stage(pay)=='review\tfulfillment_started'
        clean_get()
        assert run(pay,op='case')['result'] is True
        assert run(pay,op='eligible')['result'] is True
        # Exercise the exact production coordinator directly first. The
        # Telegram route intentionally catches native/SQL exceptions, so a
        # route-only test can conceal the original failure reason.
        accepted=run(pay)
        assert accepted['error'] is None and accepted['state']=='paid',accepted
        assert accepted['orders']==2 and len(accepted['delivery'])==2
        assert [d['state'] for d in accepted['delivery']]==['acknowledged','acknowledged'],accepted
        assert len([e for e in accepted['events'] if e['event']=='TELEGRAM'])==2,accepted
        assert balances()==(50000,8) and stage(pay)=='done\tdelivered',accepted
        assert run(pay,op='case')['result'] is False
        scenarios.append('original_hosted_nextpay_fully_linked_two_original_accounts_sealed_once')
        check_result(pay,False,2)
        scenarios.append('hosted_original_delivery_packet_passes_real_integrity_validator')
        route_pay,_=seed('hostedroute',count=1)
        clean_get()
        owner=run(route_pay,op='route',actor=9001,chat=9001)
        assert owner['state']=='paid' and owner['orders']==1,owner
        assert owner['delivery']==[{'ordinal':1,'state':'acknowledged','attempt_count':1}],owner
        views=[x for x in owner['events'] if x['event']=='OWNER_EDIT']
        assert len(views)==1 and views[0]['markup']['inline_keyboard'][0][0]['callback_data']==(
            'deliveryCase_'+str(route_pay)),owner
        assert stage(route_pay)=='done\tdelivered'
        assert balances()==(50000,9)
        scenarios.append('hosted_owner_route_valid_callback_finishes_and_opens_delivery')
        sql(work,env,"UPDATE payment_attempts SET provider_reference='foreign-hosted' "
            f'WHERE pay_row_id={pay}')
        assert run(pay,op='check')['result']=='identity_or_payload_invalid'
        sql(work,env,"UPDATE payment_attempts SET provider_reference='hosted-hostedlinknext' "
            f'WHERE pay_row_id={pay}')
        assert run(pay,op='check')['result']=='acknowledged'
        scenarios.append('hosted_tampered_payment_reference_blocks_sealed_delivery_validation')
        replay=run(pay,op='route',actor=9001,chat=9001)
        assert stage(pay)=='done\tdelivered'
        assert len([e for e in replay['events'] if e['event']=='TELEGRAM'])==0
        assert count('payment_delivery_intents',pay)==2 and balances()==(50000,8)
        scenarios.append('hosted_repeat_owner_callback_never_reseals_or_resends')
        assert not any(method=='POST' for method,_ in native_trace())
        scenarios.append('hosted_claimed_original_native_never_reposts_on_resume')

        pay,_=seed('hostedlinkzarin',provider='zarinpal')
        clean_get()
        state=run(pay)
        assert state['error'] is None and stage(pay)=='done\tdelivered',state
        assert len(state['delivery'])==1 and balances()==(50000,9)
        scenarios.append('original_hosted_zarinpal_same_paid_native_once_only')

        pay,_=seed('hostedforeign',count=1)
        clean_get()
        miss=run(pay,native='wrong_note')
        assert miss['error'] and stage(pay)=='review\tfulfillment_started',miss
        assert miss['delivery']==[] and balances()==(50000,9)
        assert run(pay)['error'] is None
        scenarios.append('hosted_foreign_native_marker_blocks_then_valid_get_recovers')

        pay,_=seed('hostedincomplete',count=2,create_prefix=1,link_prefix=1)
        clean_get()
        missing=run(pay)
        assert missing['error']=='hosted_recovery_manifest_unavailable' and missing['delivery']==[]
        assert stage(pay)=='review\tfulfillment_started' and balances()==(50000,8)
        assert not server.trace
        scenarios.append('hosted_incomplete_original_ordinals_refuse_without_get_or_create')

        pay,_=seed('hostedunlinked',count=1,link_prefix=0)
        clean_get()
        unlinked=run(pay)
        assert unlinked['error']=='hosted_recovery_manifest_unavailable' and unlinked['delivery']==[]
        assert stage(pay)=='review\tfulfillment_started' and balances()==(50000,9)
        scenarios.append('hosted_unlinked_original_requires_separate_operator_reconciliation')

        pay,_=seed('hostedquote')
        sql(work,env,'UPDATE payment_attempts SET local_amount_toman=101 '
            f'WHERE pay_row_id={pay}')
        clean_get()
        quote=run(pay)
        assert quote['error']=='hosted_recovery_manifest_unavailable' and not server.trace
        assert count('payment_delivery_intents',pay)==0 and stage(pay)=='review\tfulfillment_started'
        scenarios.append('hosted_foreign_quote_never_becomes_delivery_authority')

        pay,_=seed('hostedhuman')
        aid=int(sql(work,env,f'SELECT id FROM payment_attempts WHERE pay_row_id={pay}'))
        sql(work,env,"INSERT INTO payment_review_decisions "
            "(pay_row_id,attempt_id,decision,actor_id,payment_id,provider_status,"
            "evidence_sha256,state) "
            f"VALUES({pay},{aid},'approve',9001,'hosted-hostedhuman',"
            "'operator_attested',REPEAT('a',64),'dispatching')")
        clean_get()
        attested=run(pay)
        assert attested['error']=='hosted_recovery_manifest_unavailable' and not server.trace
        assert stage(pay)=='review\tfulfillment_started'
        assert count('payment_delivery_intents',pay)==0
        scenarios.append('hosted_existing_human_attestation_cannot_be_relabelled_merchant_delivered')

        pay,_=seed('hostedstage')
        sql(work,env,"UPDATE payment_attempts SET stage='done',provider_status='delivered' "
            f'WHERE pay_row_id={pay}')
        clean_get()
        prior=run(pay)
        assert prior['error']=='hosted_recovery_manifest_unavailable' and not server.trace
        assert count('payment_delivery_intents',pay)==0
        scenarios.append('hosted_already_terminal_attempt_cannot_fabricate_backfill')

        pay,_=seed('hostedunknown')
        clean_get()
        unknown=run(pay,telegram='unknown')
        assert unknown['error'] is None and unknown['delivery']==[
            {'ordinal':1,'state':'unknown','attempt_count':1}],unknown
        assert stage(pay)=='done\tdelivered'
        replay=run(pay)
        assert replay['error']=='hosted_recovery_manifest_unavailable'
        assert replay['delivery']==unknown['delivery'] and not replay['events']
        assert balances()==(50000,9)
        scenarios.append('hosted_telegram_unknown_stays_sealed_no_auto_retry')

        # Real provider identity and original 012 journal, not a reconstructed
        # Marzban response. All native CREATEs happen only in the origin.
        pay,origin=seed('hostedpgoriginal',panel='pasarguard',count=2)
        assert len([entry for entry in server.trace if entry[0]=='POST'])==2,server.trace
        clean_get()
        observed=run(pay,pg=True,op='route',actor=9001,chat=9001)
        assert observed['error'] is None and observed['state']=='paid',observed
        assert observed['delivery']==[
            {'ordinal':1,'state':'acknowledged','attempt_count':1},
            {'ordinal':2,'state':'acknowledged','attempt_count':1}],observed
        assert stage(pay)=='done\tdelivered' and balances()==(50000,8)
        assert all(method=='GET' for method,_ in native_trace()),server.trace
        assert run(pay,pg=True,op='check')['result']=='acknowledged'
        scenarios.append('pg_hosted_two_original_linked_native_accounts_by_id_are_delivered_once')

        pay,_=seed('hostedpgdrift',panel='pasarguard')
        sql(work,env,pgstorage.connection(2,66,url,instance='e'*32))
        clean_get()
        drift=run(pay,pg=True)
        assert drift['error']=='hosted_recovery_manifest_unavailable' and not server.trace,drift
        assert stage(pay)=='review\tfulfillment_started' and count('payment_delivery_intents',pay)==0
        sql(work,env,pgstorage.connection(2,1,url))
        clean_get()
        fixed=run(pay,pg=True)
        assert fixed['error'] is None and stage(pay)=='done\tdelivered',fixed
        scenarios.append('pg_hosted_current_physical_provider_binding_refuses_retargeted_order')

        pay,_=seed('hostedmarzbandrift',panel='marzban')
        # A complete historical 007/023 record is insufficient once the
        # logical server is rebound to a different physical Marzban endpoint.
        sql(work,env,"UPDATE server_config SET panel_url='http://127.0.0.1:9/new' WHERE id=2")
        clean_get()
        blocked=run(pay)
        assert blocked['error']=='hosted_recovery_manifest_unavailable' and not blocked['events']
        assert stage(pay)=='review\tfulfillment_started'
        assert count('payment_delivery_intents',pay)==0
        sql(work,env,pgstorage.connection(2,1,url))
        sql(work,env,"UPDATE server_config SET type='marzban' WHERE id=2")
        assert run(pay)['error'] is None
        scenarios.append('hosted_current_marzban_endpoint_drift_rejected_before_native_get')

        pay,_=seed('hostedmarzbanafterget',panel='marzban')
        clean_get()
        stale=after_readback(pay,lambda:sql(work,env,
            "UPDATE server_config SET panel_url='http://127.0.0.1:9/new' WHERE id=2"),
            pg=False)
        assert stale['error']=='hosted_recovery_identity_changed',stale
        assert stage(pay)=='review\tfulfillment_started'
        assert count('payment_delivery_intents',pay)==0 and balances()==(50000,9)
        assert not any(e['event']=='TELEGRAM' for e in stale['events']),stale
        sql(work,env,pgstorage.connection(2,1,url))
        sql(work,env,"UPDATE server_config SET type='marzban' WHERE id=2")
        assert run(pay)['error'] is None
        scenarios.append('hosted_marzban_target_drift_after_get_blocks_stale_terminal_commit')

        pay,_=seed('hostedpgafterget',panel='pasarguard')
        clean_get()
        changed=after_readback(pay,lambda:sql(work,env,
            pgstorage.connection(2,66,url,instance='e'*32)))
        assert changed['error']=='hosted_recovery_identity_changed',changed
        assert count('payment_delivery_intents',pay)==0
        assert stage(pay)=='review\tfulfillment_started' and balances()==(50000,9)
        assert not any(e['event']=='TELEGRAM' for e in changed['events']),changed
        sql(work,env,pgstorage.connection(2,1,url))
        ok=run(pay,pg=True)
        assert ok['error'] is None and stage(pay)=='done\tdelivered',ok
        scenarios.append('pg_hosted_rebind_between_native_get_and_commit_refuses_stale_delivery')

        pay,_=seed('hostedpgrevoke',panel='pasarguard')
        clean_get()
        revoked=after_readback(pay,lambda:native_revoke(pay))
        assert revoked['error']=='hosted_recovery_identity_changed',revoked
        assert count('payment_delivery_intents',pay)==0
        assert stage(pay)=='review\tfulfillment_started' and balances()==(50000,9)
        assert not any(e['event']=='TELEGRAM' for e in revoked['events']),revoked
        assert any(m=='REVOKE' for m,_ in native_trace()) and not any(
            m=='POST' for m,_ in native_trace()),server.trace
        fresh=run(pay,pg=True)
        assert fresh['error'] is None and stage(pay)=='done\tdelivered',fresh
        scenarios.append('pg_hosted_completed_native_revoke_between_get_and_commit_refuses_stale_packet')

        # A failed second sealed-packet insertion must roll back the FIRST
        # packet as well as the 003 stage update. No native or financial replay.
        pay,_=seed('hostedsqlfail',count=2)
        sql(work,env,"DELIMITER //\n"
            "CREATE TRIGGER owned_hosted_second_delivery_fail "
            "BEFORE INSERT ON payment_delivery_intents FOR EACH ROW "
            "BEGIN IF NEW.ordinal=2 THEN SIGNAL SQLSTATE '45000' "
            "SET MESSAGE_TEXT='fixture_second_hosted_packet'; END IF; END//\n"
            "DELIMITER ;")
        try:
            failed=run(pay)
        finally:
            sql(work,env,'DROP TRIGGER owned_hosted_second_delivery_fail')
        assert failed['error'] and failed['delivery']==[] and failed['orders']==2,failed
        assert stage(pay)=='review\tfulfillment_started' and balances()==(50000,8)
        recovered=run(pay)
        assert recovered['error'] is None and stage(pay)=='done\tdelivered'
        scenarios.append('hosted_second_delivery_insert_rolls_back_then_recovers_without_native_create')

        pay,_=seed('hostedconcurrent',count=2)
        clean_get()
        with ThreadPoolExecutor(max_workers=2) as pool:
            requests=list(pool.map(lambda _:run(pay),range(2)))
        assert sum(x['error'] is None for x in requests)==1,requests
        assert sum(len([e for e in x['events'] if e['event']=='TELEGRAM'])
            for x in requests)==2,requests
        assert count('payment_delivery_intents',pay)==2
        assert stage(pay)=='done\tdelivered' and balances()==(50000,8)
        scenarios.append('hosted_concurrent_owner_resumes_single_terminal_commit_and_delivery')

        pay,_=seed('hosteddenied')
        clean_get()
        denied=run(pay,op='route',actor=1001,chat=1001)
        assert not any(e['event'] in ('TELEGRAM','GET') for e in denied['events'])
        assert stage(pay)=='review\tfulfillment_started'
        private=run(pay,op='route',actor=9001,chat=1001)
        assert not any(e['event'] in ('TELEGRAM','GET') for e in private['events'])
        assert count('payment_delivery_intents',pay)==0
        scenarios.append('hosted_owner_only_private_callback_denies_foreign_actor_chat')

        return {
            'complete':True,'source_real':True,'php85_required':True,
            'disposable_mariadb':True,'original_hosted_post_replays':0,
            'original_hosted_duplicate_wallet_debits':0,'scenarios':scenarios
        }

if __name__=='__main__':
    parser=argparse.ArgumentParser();parser.add_argument('--report')
    args=parser.parse_args()
    result=check()
    if args.report:Path(args.report).write_text(json.dumps(result))
    print(json.dumps(result))
