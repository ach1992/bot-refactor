#!/usr/bin/env python3
"""#227: real PHP8.5 / owned MariaDB / loopback-PasarGuard wallet renewal recovery.

No live bot, customer database, Telegram or production provider calls.
"""
from __future__ import annotations
import argparse
import datetime as dt
import json
import subprocess
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path

import test_post_v9_scoped_api_pg as api
import test_post_v9_pg_cutover_runtime as cutover

ROOT=Path(__file__).resolve().parents[1]
EXPIRY=1893456000

PHP=r'''<?php
error_reporting(E_ALL & ~E_DEPRECATED);
set_error_handler(static function($level,$message){throw new ErrorException($message,0,$level);});
if (PHP_VERSION_ID<80500 || PHP_VERSION_ID>=80600)throw new RuntimeException('php85_required');
define('ACHBOT_PG_TEST_LOOPBACK',true);
$_SERVER['SCRIPT_FILENAME']=__FILE__;
$root=$argv[1];$spec=json_decode($argv[4],true,64,JSON_THROW_ON_ERROR);
$telegramWebhookSecret='fixture-webhook-secret-32-bytes-123456';
$GLOBALS['events']=[];
function sendMessage(...$args){
    $GLOBALS['events'][]=['kind'=>'customer_send','text'=>(string)$args[0]];
    return ($GLOBALS['spec']['telegram_unknown']??false)===true
        ?(object)['ok'=>false]:(object)['ok'=>true];
}
function achbotNotifyAdmins($method,array $data,array $options=[]):array {
    $GLOBALS['events'][]=['kind'=>'admin_report','text'=>(string)($data['text']??'')];
    return [['chat_id'=>9001,'response'=>(object)['ok'=>true]]];
}
function achbotAdminFanoutAcknowledged(array $notices):bool{
    return $notices!==[] && array_reduce($notices,
        fn($all,$n)=>$all && ($n['response']->ok??false)===true,true);
}
require_once $root.'/src/Purchase/Core.php';
require_once $root.'/src/Finance/WalletRenewalRecovery.php';
require_once $root.'/src/Finance/WalletEditRecovery.php';
// The real config.php bootstrap defines this tiny facade. Load its actual
// display-settings dependency and pure native presenter without entering
// a second production bot/webhook bootstrap in the disposable harness.
function buildSubscriptionPresentation($provider,$native,$botState,$options=[],
    $context='view',$serverId=0) {
    global $connection,$root;
    require_once $root.'/src/Subscription/ServerDisplaySettings.php';
    $resolved=achbotServerDisplayOptions($connection??null,
        (int)$serverId,(string)$provider);
    return \AchBot\Subscription\Presentation\present(
        $provider,$native,$botState,$resolved,$context);
}
class TestRenewMysqli extends mysqli {
    public function commit(int $flags=0,?string $name=null):bool{
        $ok=parent::commit($flags,$name);
        if($ok && ($GLOBALS['spec']['commit_ack_lost']??false)===true &&
          !($GLOBALS['commit_ack_lost_fired']??false)){
            $id=(int)$GLOBALS['spec']['pay_id'];
            $q=$this->prepare('SELECT state FROM pays WHERE id=?');
            $q->bind_param('i',$id);$q->execute();
            $row=$q->get_result()->fetch_assoc();$q->close();
            if(($row['state']??null)==='paid_with_wallet'){
                $GLOBALS['commit_ack_lost_fired']=true;
                throw new RuntimeException('fixture_commit_ack_lost_after_success');
            }
        }
        return $ok;
    }
}
$db=new TestRenewMysqli('localhost','root','',$argv[3],0,$argv[2]);
$db->set_charset('utf8mb4');
$GLOBALS['connection']=$db;
try {
    $payId=(int)$spec['pay_id'];
    $op=$spec['op'];
    if($op==='seed_native'){
        achbotPgPinPayment($db,$payId);
        $q=$db->prepare('SELECT days,volume FROM server_plans WHERE id=7');
        $q->execute();$plan=$q->get_result()->fetch_assoc();$q->close();
        $out=achbotPgServiceEdit($db,2,[
            'pay_id'=>$payId,'remark'=>'historical_fixture',
            'days'=>(int)$plan['days'],'volume'=>(int)$plan['volume']]);
        $result=['success'=>$out->success??false,'verified'=>$out->verified??false,
                 'message'=>$out->msg??null,
                 'expire'=>$out->applied_expire??null,'bytes'=>$out->applied_bytes??null];
    }elseif($op==='addon_inspect'){
        $result=achbotWalletPgAddonInspect($db,$payId,
            (int)($spec['actor']??9001),9001);
    }elseif($op==='addon_token'){
        $claim=achbotWalletPgAddonOriginal($db,$payId);
        $result=['token'=>achbotWalletPgAddonConfirmationToken($claim,
            (int)($spec['actor']??9001))];
    }elseif($op==='addon_resume'){
        $result=achbotWalletPgResumeAddon($db,$payId,
            (int)($spec['actor']??9001),9001);
    }elseif($op==='seed_addon'){
        achbotPgPinPayment($db,$payId);
        $out=achbotPgServiceEdit($db,2,[
            'pay_id'=>$payId,'remark'=>'historical_fixture',
            'plus_volume'=>3]);
        $result=['success'=>$out->success??false,'verified'=>$out->verified??false,
            'fence'=>$out->native_fence_deferred??false,
            'reason'=>$out->msg??null];
    }elseif($op==='render_live'){
        $user=achbotProviderRenderPacket($db,2,'historical_fixture');
        $result=['success'=>$user->success??true,
            'proof'=>$user->provider_render_proof??null,
            'username'=>$user->username??null,'status'=>$user->status??null,
            'quota'=>$user->data_limit??null,'expire'=>$user->expire??null,
            'used'=>$user->used_traffic??null];
    }elseif($op==='mutation_guard'){
        try {
            $ctx=achbotPgRemarkContext($db,2,'historical_fixture');
            $result=['mutation_allowed'=>true,'state'=>$ctx['account']['state']];
        }catch(Throwable $error){
            $result=['mutation_allowed'=>false,'reason'=>$error->getMessage()];
        }
    }elseif($op==='read_matrix'){
        require_once $root.'/src/Panel/ServiceLifecycle.php';
        $ctx=achbotPgObserveOrderContext($db,103);
        $order=$ctx['order'];
        $server=$db->query('SELECT * FROM server_config WHERE id=2')->fetch_assoc();
        $native=achbotProviderRenderPacket($db,2,'historical_fixture');
        $simple=achbotProviderUser(2,'historical_fixture',false);
        $status=achbotServiceObserve(2,'historical_fixture');
        $info=achbotProviderUserInfo(2,'historical_fixture');
        $presentation=achbotOrderPresentation($db,$order,$server,
            ['qrSubState'=>'on','qrConfigState'=>'on'],$native);
        $presentationWithoutPacket=achbotOrderPresentation($db,$order,$server,
            ['qrSubState'=>'on','qrConfigState'=>'on']);
        $result=[
            'context'=>$ctx['account']['state'],
            'owner'=>achbotSupportsOrderObservation($db,103,1001),
            'foreign'=>achbotSupportsOrderObservation($db,103,1002),
            'nonexistent'=>achbotSupportsOrderObservation($db,99999,1001),
            'mutation_admission'=>achbotSupportsOrder($db,103,7,1001),
            'render'=>isset($native->provider_render_proof),
            'read_username'=>$simple->username??null,
            'status_verified'=>$status['verified']??false,
            'status'=>$status['status']??null,
            'information_username'=>$info->username??null,
            'qr_sub_url'=>$presentation['qr_subscription_url']??null,
            'qr_default_sub_url'=>$presentationWithoutPacket['qr_subscription_url']??null,
            'qr_config_count'=>count($presentation['qr_config_links']??[]),
            'qr_default_config_count'=>count($presentationWithoutPacket['qr_config_links']??[])];
    }elseif($op==='inspect'){
        $result=achbotWalletPgRenewInspect($db,$payId,
            (int)($spec['actor']??9001),9001);
    }elseif($op==='preview_retry'){
        $claim=achbotWalletPgRenewOriginal($db,$payId);
        $info=achbotWalletPgRenewInspect($db,$payId,9001,9001);
        if(($info['status']??null)!=='retryable')throw new RuntimeException('fixture_not_retryable');
        $result=['status'=>$info['status'],'disposition'=>$info['disposition'],
            'token'=>achbotWalletPgRenewRetryToken($claim,9001,
                $info['entitlement_digest'])];
    }elseif($op==='retry'){
        $result=achbotWalletPgRetryOriginalRenew($db,$payId,9001,9001,
            (string)($spec['token']??''));
    }elseif($op==='resume'){
        $result=achbotWalletPgResumeRenew($db,$payId,
            (int)($spec['actor']??9001),9001);
    }elseif($op==='cron_recover'){
        $result=achbotWalletPgRenewMaintain($db);
    }elseif($op==='cron_reports'){
        $result=achbotWalletAddonMaintainReports($db);
    }elseif($op==='customer_first_send'){
        $result=achbotDeliveryDispatch($db,$payId,1);
    }elseif($op==='original'){
        $x=achbotWalletPgRenewOriginal($db,$payId);
        $result=['kind'=>$x['kind'],'days'=>$x['days'],
            'volume'=>$x['volume'],'state'=>$x['account']['state']];
    }else throw new RuntimeException('fixture_operation_invalid');
    echo json_encode(['ok'=>true,'result'=>$result,'events'=>$GLOBALS['events']],
        JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_THROW_ON_ERROR);
}
'''

def case(*, variant:str)->list[str]:
    checks=[]
    with api.sandbox() as (sql, run, provider, _bank, _epoch):
        effect_id=int(cutover.fixture_effect(sql))
        provider.user.update({
            'id':72,'username':cutover.USER,'created_at':cutover.CREATED,
            'expire':dt.datetime.fromtimestamp(EXPIRY,dt.timezone.utc)
                       .isoformat().replace('+00:00','Z'),
            'data_limit':123400000,'used_traffic':20123,
            'group_ids':[2,5],'status':'active'})
        sql("UPDATE server_plans SET days=31,volume=10 WHERE id=7")
        sql("INSERT INTO orders_list (id,userid,token,transid,fileid,server_id,inbound_id,"
            "remark,uuid,expire_date,link,amount,status,date,autoRenew,"
            "provider_effect_id,provider_remote_user_id,provider_remote_created_at) VALUES "
            "(103,1001,'wallet-renew-original','',7,2,0,'historical_fixture',"
            "'original-uuid',"+str(EXPIRY)+",'[]',185000,1,'fixture','off',"
            f"{effect_id},72,{api.quote_sql(cutover.CREATED)})")
        origin={'version':1,'kind':'external_import','order_id':103,
                'owner_userid':1001,'actor_userid':1001,
                'provider':'pasarguard','server_id':2,
                'remark':cutover.USER,'proof_sha256':'b'*64,
                'native_identity':{'remote_user_id':72,
                    'remote_created_at':cutover.CREATED,
                    'remote_username':cutover.USER},
                'capacity_reserved':False,'provider_account_id':effect_id}
        sql("INSERT INTO setting(type,value) VALUES "
            "('ACHBOT_SERVICE_ORIGIN:103',"+api.quote_sql(
                json.dumps(origin,separators=(',',':')))+")")
        sql("UPDATE users SET wallet=900000 WHERE userid=1001")
        sql("INSERT INTO pays(hash_id,user_id,type,plan_id,server_id,order_id,"
            "volume,day,price,request_date,state) VALUES "
            "('wallet-renew-original-103',1001,'RENEW_ACCOUNT',7,2,103,"
            "0,0,185000,UNIX_TIMESTAMP()-3600,'processing_wallet')")
        pay_id=int(sql("SELECT id FROM pays WHERE hash_id='wallet-renew-original-103'"))
        driver=run.fixture_work/'wallet-renewal-recovery.php'
        driver.write_text(PHP)
        def call(op:str,**kw):
            cp=subprocess.run([
                'php','-n','-d','extension=mysqlnd','-d','extension=mysqli',
                '-d','extension=curl','-d','log_errors=1','-d','error_log=/dev/stderr',
                str(driver),str(ROOT),str(run.fixture_socket),api.DB,
                json.dumps({'op':op,'pay_id':pay_id,**kw})],
                cwd=run.fixture_work,env=run.fixture_env,capture_output=True,text=True,
                timeout=45)
            if cp.returncode or not cp.stdout or 'SENSITIVE' in cp.stderr:
                raise AssertionError(('fixture_php_failed',cp.returncode,cp.stdout[-1200:],
                                      cp.stderr[-2500:]))
            return json.loads(cp.stdout)
        if variant=='addon_mutating_live':
            # This is a REAL additive (not synthetic edited state) wallet
            # Native PUT with deferred local financial COMMIT. It creates the
            # same provider_state=mutating fence as DAY/VOLUME partial failures.
            sql("DELETE FROM pays WHERE id="+str(pay_id))
            sql("INSERT INTO pays(hash_id,user_id,type,plan_id,server_id,order_id,"
                "volume,day,price,request_date,state) VALUES "
                "('addon-original-103',1001,'INCREASE_VOLUME_103_11',7,2,103,"
                "3,0,25000,UNIX_TIMESTAMP()-3600,'processing_wallet')")
            addon_id=int(sql("SELECT id FROM pays WHERE hash_id='addon-original-103'"))
            addon=call('seed_addon',pay_id=addon_id)
            assert addon['ok'] and addon['result']['success'] and addon['result']['verified'],addon
            assert addon['result']['fence'] is True,addon
            assert sql("SELECT provider_state FROM service_effects WHERE id="+str(effect_id))=='mutating'
            matrix=call('read_matrix',pay_id=addon_id)
            assert matrix['ok'],matrix
            view=matrix['result']
            assert view['context']=='mutating' and view['owner'] is True
            assert view['mutation_admission'] is False and view['foreign'] is False
            assert view['status_verified'] is True and view['render'] is True
            assert view['qr_sub_url'] and view['qr_default_sub_url']
            assert provider.write_count==1,provider.trace
            denied=call('addon_inspect',pay_id=addon_id,actor=1001)
            assert denied['result']['status']=='denied',denied
            inspected=call('addon_inspect',pay_id=addon_id)
            assert inspected['result']['status']=='applied',inspected
            original=call('addon_token',pay_id=addon_id)
            assert original['ok'] and len(original['result']['token'])==20,original
            initial_wallet=sql('SELECT wallet FROM users WHERE userid=1001')
            report=call('addon_resume',pay_id=addon_id)
            assert report['result']['status']=='settled_delivery_pending',report
            assert sql('SELECT state FROM pays WHERE id='+str(addon_id))=='paid_with_wallet'
            assert sql('SELECT wallet FROM users WHERE userid=1001')==initial_wallet
            assert call('addon_resume',pay_id=addon_id)['result']['status']=='already_paid_state'
            assert provider.write_count==1
            checks.append('staff_get_only_applied_addon_inspection_and_authorization')
            checks.append('staff_original_addon_finalize_once_no_redelivery_or_native_replay')
            checks.append('real_additive_native_write_fence_keeps_live_read_paths_open')
            checks.append('real_additive_payment_still_pending_and_mutation_admission_fenced')
            checks.append('real_additive_fence_live_links_do_not_send_second_native_write')
            return checks
        native=call('seed_native')
        assert native['ok'] and native['result']['success'] and native['result']['verified'],native
        target=native['result']['expire']
        assert int(sql('SELECT expire_date FROM orders_list WHERE id=103'))==EXPIRY
        assert sql('SELECT state FROM pays WHERE id='+str(pay_id))=='processing_wallet'
        assert provider.write_count==2,provider.trace
        checks.append('native_original_reset_and_put_ends_before_local_payment_commit')
        audit_before=int(sql('SELECT COUNT(*) FROM increase_order'))
        initial_wallet=int(sql('SELECT wallet FROM users WHERE userid=1001'))
        denied=call('inspect',actor=1001)
        assert denied['result']['status']=='denied',denied
        checks.append('nonstaff_cannot_inspect_original_wallet_payment')
        if variant=='render_mutating_live':
            # Native still active, but the original renewal's SQL fence is
            # parked after the first provider RESET/PUT and before financial
            # completion. The order page must show authenticated LIVE values
            # (not stale local numbers), while all mutations remain fenced.
            sql("UPDATE service_effects SET provider_state='mutating' WHERE id="+str(effect_id))
            assert call('mutation_guard')['result']['mutation_allowed'] is False
            proof=call('render_live')
            assert proof['ok'] and proof['result']['proof']=={
                'provider':'pasarguard','server_id':2,
                'username':'historical_fixture'},proof
            assert proof['result']['username']=='historical_fixture'
            assert proof['result']['status']=='active'
            assert proof['result']['quota']==10*1073741824,proof
            assert proof['result']['used']==0,proof
            matrix=call('read_matrix')
            assert matrix['ok'],matrix
            view=matrix['result']
            assert view['context']=='mutating',view
            assert view['owner'] is True and view['foreign'] is False
            assert view['nonexistent'] is False and view['mutation_admission'] is False
            assert view['render'] is True and view['read_username']=='historical_fixture'
            assert view['status_verified'] is True and view['status']=='active'
            assert view['information_username']=='historical_fixture'
            assert view['qr_sub_url'] and view['qr_default_sub_url'],view
            assert view['qr_config_count']>0 and view['qr_default_config_count']>0,view
            assert provider.write_count==2,provider.trace
            checks.append('cross_routes_live_status_subscription_details_and_account_info')
            checks.append('qr_and_sub_links_from_validated_live_native_while_mutating')
            checks.append('cross_user_read_denied_no_mutation_admission_from_observation')
            checks.append('mutating_native_fence_still_allows_exact_live_order_view')
            checks.append('native_fence_still_rejects_mutation_admission_and_no_put_from_view')
        if variant=='native_identity_wrong':
            sql("UPDATE service_effects SET provider_state='mutating' WHERE id="+str(effect_id))
            provider.user['created_at']='2026-11-15T21:00:00.000000Z'
            response=call('read_matrix')
            assert response['ok'],response
            result=response['result']
            assert result['owner'] is True and result['mutation_admission'] is False
            assert result['render'] is False and result['status_verified'] is False
            assert result['qr_sub_url']=='' and result['qr_default_sub_url']==''
            assert result['qr_config_count']==0 and result['qr_default_config_count']==0
            assert provider.write_count==2
            checks.append('wrong_native_incarnation_never_emits_qr_links')
            checks.append('unverified_live_native_remains_unknown_not_stale_local')
            return checks
        if variant=='retired_order':
            sql("UPDATE orders_list SET status=0 WHERE id IN (101,102,103)")
            assert call('read_matrix')['ok'] is False
            assert call('render_live')['result']['proof'] is None
            assert provider.write_count==2
            checks.append('retired_or_unattached_order_never_authorizes_live_link')
            return checks
        if variant=='native_drift':
            provider.user['data_limit']+=777
            assert call('inspect')['result']['status']=='review'
            assert call('resume')['result']['status']=='review'
            assert provider.write_count==2
            assert sql('SELECT state FROM pays WHERE id='+str(pay_id))=='processing_wallet'
            checks.append('unrelated_native_change_cannot_be_claimed_as_original_renewal')
            return checks
        expected_writes=2
        if variant in ('reset_only_baseline','reset_only_drift'):
            # Identical Native reset-only partial failure to Hell #113331:
            # prior quota 12GiB, zero usage, original plan target 10GiB.
            # A fresh operator preview uses GET-only and the ORIGINAL journal
            # key; explicit Retry completes without a second Wallet debit.
            provider.user['data_limit']=12*1073741824
            provider.user['expire']=dt.datetime.fromtimestamp(
                EXPIRY,dt.timezone.utc).isoformat().replace('+00:00','Z')
            provider.user['used_traffic']=0
            sql("UPDATE service_effects SET provider_state='mutating',"
                "provider_mutation_snapshot=JSON_REMOVE("
                "provider_mutation_snapshot,'$.result','$.verified_at','$.current_group_ids')"
                " WHERE id="+str(effect_id))
            if variant=='reset_only_baseline':
                sql("UPDATE service_effects SET provider_mutation_snapshot="
                    "JSON_SET(provider_mutation_snapshot,"
                    "'$.changes.before.data_limit',"+str(12*1073741824)+","
                    "'$.changes.before.expire',"+str(EXPIRY)+")"
                    " WHERE id="+str(effect_id))
            assert provider.user['used_traffic']==0
            preview=call('preview_retry')
            assert preview['ok'] and preview['result']['status']=='retryable',preview
            disposition='baseline' if variant=='reset_only_baseline' else 'drift'
            assert preview['result']['disposition']==disposition,preview
            stale=call('retry',token='00000000000000000000')
            assert stale['result']['status']=='review',stale
            assert provider.write_count==2,provider.trace
            recovered=call('retry',token=preview['result']['token'])
            assert recovered['result']['status']=='settled_delivery_pending',recovered
            expected_writes=4
            checks.append('reset_only_12g_native_get_plus_staff_confirm_restores_original_10g_31days')
            checks.append('forged_or_stale_operator_token_cannot_repeat_native')
        if variant=='plan_changed':
            sql("UPDATE server_plans SET volume=18,days=60 WHERE id=7")
            assert call('original')['result']['volume']==10
            checks.append('changed_mutable_plan_catalog_does_not_reprice_old_debited_journal')
        if variant=='identity_drift':
            sql('UPDATE orders_list SET provider_remote_user_id=123 WHERE id=103')
            assert call('resume')['result']['status']=='review'
            assert provider.write_count==2
            assert sql('SELECT COUNT(*) FROM increase_order')==str(audit_before)
            checks.append('foreign_physical_account_identity_denied')
            return checks
        if variant=='audit_failure':
            sql("CREATE TRIGGER wallet_renew_audit_fail BEFORE INSERT ON increase_order "
                "FOR EACH ROW SIGNAL SQLSTATE '45000' "
                "SET MESSAGE_TEXT='fixture_audit_failure'")
            failed=call('resume')
            assert failed['result']['status']=='review',failed
            assert sql('SELECT state FROM pays WHERE id='+str(pay_id))=='processing_wallet'
            assert sql('SELECT expire_date FROM orders_list WHERE id=103')==str(EXPIRY)
            assert provider.write_count==2
            assert sql('SELECT COUNT(*) FROM payment_delivery_intents')=='0'
            checks.append('sql_failure_rolls_back_original_order_payment_and_delivery')
            sql('DROP TRIGGER wallet_renew_audit_fail')
        if variant=='notice_failure':
            sql("INSERT INTO setting(type,value) VALUES "
                "('WALLET_ADDON_REPORT:"+str(pay_id)+"','fixture-conflicting-report')")
            failed=call('resume')
            assert failed['result']['status']=='review',failed
            assert sql('SELECT expire_date FROM orders_list WHERE id=103')==str(EXPIRY)
            assert sql('SELECT COUNT(*) FROM payment_attempts')=='0'
            sql("DELETE FROM setting WHERE type='WALLET_ADDON_REPORT:"+str(pay_id)+"'")
            checks.append('outbox_failure_rolls_back_original_local_finality')
        if variant in ('reset_only_baseline','reset_only_drift'):
            pass  # original operator retry already completed above
        elif variant=='race':
            with ThreadPoolExecutor(max_workers=2) as pool:
                results=list(pool.map(lambda _:call('resume'),range(2)))
            assert sum(x['result']['status']=='settled_delivery_pending'
                       for x in results)==1,results
            checks.append('two_operator_confirmations_settle_exactly_once')
        elif variant=='cron':
            sql('UPDATE pays SET updated_at=NULL WHERE id='+str(pay_id))
            result=call('cron_recover')
            assert result['result']['settled']==1,result
            checks.append('cron_get_only_resolves_original_native_renewal')
        elif variant=='commit_ack':
            result=call('resume',commit_ack_lost=True)
            assert result['result']['status']=='review',result
            checks.append('lost_local_commit_ack_treated_as_unknown')
        else:
            assert call('inspect')['result']['status']=='applied'
            result=call('resume')
            assert result['result']['status']=='settled_delivery_pending',result
            checks.append('verified_original_native_receives_local_completion')
        assert sql('SELECT state FROM pays WHERE id='+str(pay_id))=='paid_with_wallet'
        assert sql('SELECT expire_date FROM orders_list WHERE id=103')==str(target)
        assert sql('SELECT COUNT(*) FROM increase_order')==str(audit_before+1)
        assert sql('SELECT wallet FROM users WHERE userid=1001')==str(initial_wallet)
        assert provider.write_count==expected_writes
        assert sql('SELECT COUNT(*) FROM payment_delivery_intents')=='1'
        assert sql('SELECT COUNT(*) FROM payment_attempts')=='1'
        assert sql('SELECT provider_state FROM service_effects WHERE id='+str(effect_id))=='linked'
        assert call('resume')['result']['status']=='already_paid_state'
        assert sql('SELECT COUNT(*) FROM increase_order')==str(audit_before+1)
        checks.append('terminal_invoice_replay_never_debits_or_edits_native_twice')
        report=call('cron_reports')
        assert report['result']['acknowledged']==1,report
        assert [x['kind'] for x in report['events']]==['admin_report']
        deliver=call('customer_first_send',telegram_unknown=True)
        assert deliver['result']=='unknown',deliver
        assert [x['kind'] for x in deliver['events']]==['customer_send']
        assert call('customer_first_send')['events']==[]
        assert call('cron_reports')['events']==[]
        assert provider.write_count==expected_writes
        checks.append('customer_unknown_ack_never_auto_resends_and_admin_report_once')
        return checks

def main():
    ap=argparse.ArgumentParser()
    ap.add_argument('--run-disposable',action='store_true',required=True)
    ap.add_argument('--report',type=Path)
    args=ap.parse_args()
    checks=[]
    for variant in ('applied','native_drift','identity_drift','audit_failure',
                    'notice_failure','commit_ack','race','cron','plan_changed',
                    'reset_only_baseline','reset_only_drift',
                    'render_mutating_live','native_identity_wrong',
                    'retired_order','addon_mutating_live'):
        checks += [variant+':'+x for x in case(variant=variant)]
    result={'complete':True,'status':'PASS','checks':len(checks),'cases':checks,'real_php':True,
            'native':'loopback-only','database':'owned-temporary-socket',
            'real_payment_writes':False}
    if args.report:args.report.write_text(json.dumps(result,indent=2)+'\n')
    print(json.dumps({'checks':len(checks),'variants':15},sort_keys=True))

if __name__=='__main__':main()
