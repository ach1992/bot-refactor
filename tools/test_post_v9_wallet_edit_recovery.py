#!/usr/bin/env python3
"""Issue #26: applied-only Wallet add-on recovery on real current PHP + disposable MariaDB.

An original imported PasarGuard account retains a NULL originating order.
No second PUT/RESET, wallet debit, Telegram send, or fabricated financial row.
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

ROOT = Path(__file__).resolve().parents[1]
ORIGINAL_EXPIRE = 1893456000
USER = cutover.USER
CREATED = cutover.CREATED

PHP = r'''<?php
error_reporting(E_ALL & ~E_DEPRECATED);
set_error_handler(static function($level,$message){throw new ErrorException($message,0,$level);});
if (PHP_VERSION_ID<80500 || PHP_VERSION_ID>=80600) throw new RuntimeException('php85_required');
define('ACHBOT_PG_TEST_LOOPBACK',true);
$_SERVER['SCRIPT_FILENAME']=__FILE__;
$root=$argv[1];$spec=json_decode($argv[4],true,64,JSON_THROW_ON_ERROR);
$GLOBALS['spec']=$spec;
$telegramWebhookSecret='fixture-webhook-secret-32-bytes-123456';
$GLOBALS['events']=[];
function sendMessage(...$args){
    $GLOBALS['events'][]=['kind'=>'customer_send','text'=>(string)$args[0]];
    if (($GLOBALS['spec']['telegram_unknown']??false)===true)
        return (object)['ok'=>false];
    return (object)['ok'=>true];
}
function achbotNotifyAdmins($method,array $data,array $options=[]):array {
    $GLOBALS['events'][]=['kind'=>'admin_report','method'=>$method,
        'text'=>(string)($data['text']??'')];
    return [['chat_id'=>9001,'response'=>(object)['ok'=>(($GLOBALS['spec']['report_ack']??true)===true)]]];
}
function editText(...$args) {
    $GLOBALS['events'][]=['kind'=>'ui_edit',
        'text'=>(string)$args[1], 'keyboard'=>(string)($args[2]??'')];
    return (object)['ok'=>true];
}
function answerCallbackQuery(...$args) {
    $GLOBALS['events'][]=['kind'=>'ui_callback'];
}
function achbotAdminFanoutAcknowledged(array $notices):bool {
    return $notices!==[] && array_reduce($notices,
        fn($all,$n)=>$all && ($n['response']->ok??false)===true,true);
}
require_once $root.'/src/Purchase/Core.php';
require_once $root.'/src/Finance/WalletEditRecovery.php';
class OwnedRecoveryMysqli extends mysqli {
    public function commit(int $flags=0,?string $name=null): bool {
        $ok=parent::commit($flags,$name);
        if ($ok && ($GLOBALS['spec']['commit_ack_lost']??false)===true &&
            !($GLOBALS['commit_ack_lost_fired']??false)) {
            $id=(int)($GLOBALS['spec']['pay_id']??0);
            $q=$this->prepare('SELECT state FROM pays WHERE id=?');
            $q->bind_param('i',$id);$q->execute();
            $row=$q->get_result()->fetch_assoc();$q->close();
            if (($row['state']??null)==='paid_with_wallet') {
                $GLOBALS['commit_ack_lost_fired']=true;
                throw new RuntimeException('fixture_commit_ack_lost_after_success');
            }
        }
        return $ok;
    }
}
$db=new OwnedRecoveryMysqli('localhost','root','',$argv[3],0,$argv[2]);
$db->set_charset('utf8mb4');
try {
    $payId=(int)$spec['pay_id'];
    $orderId=(int)($spec['order_id']??103);
    if ($spec['op']==='seed') {
        achbotPgPinPayment($db,$payId);
        $ctx=achbotPgOrderContext($db,$orderId);
        $read=achbotPgRead($ctx['panel'],$ctx['identity']);
        if (($read['success']??false)!==true) throw new RuntimeException('seed_native_unavailable');
        $before=$read['user'];$field=($spec['kind']??'day')==='volume'?'plus_volume':'plus_day';
        $params=['pay_id'=>$payId,'remark'=>'historical_fixture',$field=>($field==='plus_day'?10:4)];
        $absolute=achbotPgEditChanges($before,$params);
        $units=achbotPgEditInputUnits($params);
        if (($spec['direct']??false)!==true) {
            $intent=achbotPgBeginMutation($db,$ctx,'pay:'.$payId,'edit',
                ['phase'=>'claimed','requested_units'=>$units]);
            achbotPgPersistMutationIntent($db,$ctx,$intent,[
                'requested_units'=>$units,
                'order_expire_before'=>(int)$ctx['order']['expire_date'],
                'absolute'=>$absolute,'reset_usage'=>false,
                'before'=>array_intersect_key($before,array_flip([
                    'data_limit','expire','status','group_ids','used_traffic']))]);
        }
        $result=['absolute'=>$absolute,'baseline'=>$before['expire']];
    } elseif ($spec['op']==='native_wallet_edit') {
        $kind=(string)$spec['kind'];
        $params=['pay_id'=>$payId,'remark'=>'historical_fixture',
            $kind==='day'?'plus_day':'plus_volume'=>$kind==='day'?10:4];
        if (($spec['api_defer']??false)===true)
            $params['_api_defer_account_finality']=true;
        $response=achbotPgServiceEdit($db,2,$params);
        $result=['success'=>$response->success??false,
            'verified'=>$response->verified??false,
            'fence_deferred'=>$response->native_fence_deferred??false,
            'expire'=>$response->applied_expire??null,
            'bytes'=>$response->applied_bytes??null];
    } elseif ($spec['op']==='resume') {
        $result=achbotWalletPgResumeAddon($db,$payId,
            (int)($spec['actor']??9001),(int)($spec['primary']??9001));
    } elseif ($spec['op']==='cron_recover') {
        $result=achbotWalletAddonMaintain($db);
    } elseif ($spec['op']==='cron_reports') {
        $result=achbotWalletAddonMaintainReports($db);
    } elseif ($spec['op']==='report_ui') {
        $connection=$db;$from_id=(int)($spec['actor']??9001);
        $admin=9001;$chat_id=$from_id;$callbackId='fixture-callback';
        $message_id=77;
        $data=(string)($spec['data']??'');
        require $root.'/src/Finance/Routes/AutomaticGatewayReview.php';
        $result=['handled'=>true];
    } elseif ($spec['op']==='report_case') {
        require_once $root.'/src/Finance/GatewayClient.php';
        $case=achbotWalletAddonReportCase($db,$payId);
        $result=['case'=>$case?array_diff_key($case,['value'=>true]):null,
            'inbox'=>achbotGatewayAttentionCaseOpen($db,$payId)];
    } elseif ($spec['op']==='report_token') {
        $case=achbotWalletAddonReportCase($db,$payId);
        $result=['token'=>$case?achbotWalletAddonReportToken($case,
            (int)($spec['actor']??9001)):null];
    } elseif ($spec['op']==='report_manual') {
        $result=achbotWalletAddonReportManualSend($db,$payId,
            (int)($spec['actor']??9001),9001,(string)($spec['token']??''));
    } elseif ($spec['op']==='customer_first_send') {
        $result=achbotDeliveryDispatch($db,$payId,1);
    } elseif ($spec['op']==='original') {
        $e=achbotWalletPgAddonOriginal($db,$payId);
        $result=['kind'=>$e['kind'],'units'=>$e['units'],
            'state'=>$e['account']['state'],'order_id'=>$e['account']['order_id']];
    } elseif ($spec['op']==='compete') {
        $ctx=achbotPgOrderContext($db,$orderId,false,true);
        achbotPgBeginMutation($db,$ctx,'operator:competing-edit','edit',
            ['phase'=>'claimed','requested_units'=>['plus_day'=>1]]);
        $result=['unexpected_competing_account_claim'=>true];
    } elseif ($spec['op']==='validate_ref') {
        achbotPgOrderContext($db,$orderId,false,true);
        $result=['unexpected_unlisted_reference'=>true];
    } else throw new RuntimeException('unexpected_fixture_operation');
    echo json_encode(['ok'=>true,'result'=>$result,
        'events'=>$GLOBALS['events']],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
} catch(Throwable $error) {
    echo json_encode(['ok'=>false,'error'=>$error->getMessage()],JSON_THROW_ON_ERROR);
}
'''


def case(*, kind: str = 'day', finalization_failure: bool = False,
         historical_planless: bool = False, commit_ack_lost: bool = False,
         concurrent: bool = False, system: bool = False,
         direct: bool = False, notice_failure: bool = False,
         telegram_unknown: bool = False, report_scenarios: bool = False,
         report_manual_unknown: bool = False) -> list[str]:
    checks: list[str] = []
    with api.sandbox() as (sql, run, provider, _bank, _epoch):
        effect_id = int(cutover.fixture_effect(
            sql, original_planless=historical_planless))
        oid = 101 if historical_planless else 103
        provider.user.update({
            'id': cutover.REMOTE_ID, 'username': USER, 'created_at': CREATED,
            'note': 'original-existing-account', 'data_limit': 123400000,
            'used_traffic': 20123,
            'expire': dt.datetime.fromtimestamp(ORIGINAL_EXPIRE,dt.timezone.utc).isoformat().replace('+00:00','Z'),
            'group_ids': [2,5], 'status': 'active',
        })
        if historical_planless:
            # An ORIGINAL migrated reference (not a later import) has fileid=0,
            # NULL effect.order_id and no modern PasarGuard import origin.
            sql('UPDATE orders_list SET expire_date='+str(ORIGINAL_EXPIRE)+
                ',fileid=0 WHERE id=101')
            assert sql('SELECT fileid FROM orders_list WHERE id=101')=='0'
        else:
            sql("INSERT INTO orders_list (id,userid,token,transid,fileid,server_id,inbound_id,"
                "remark,uuid,expire_date,link,amount,status,date,autoRenew,"
                "provider_effect_id,provider_remote_user_id,provider_remote_created_at) VALUES "
                "(103,1001,'wallet-existing-ref','',7,2,0,'historical_fixture',"
                "'unchanged-original-uuid',"+str(ORIGINAL_EXPIRE)+",'[]',1200,1,'fixture','off',"
                f"{effect_id},72,{api.quote_sql(CREATED)})")
        origin = {'version': 1, 'kind': 'external_import', 'order_id': 103,
                  'owner_userid': 1001, 'actor_userid': 1001,
                  'provider': 'pasarguard', 'server_id': 2,
                  'remark': USER, 'proof_sha256': 'b' * 64,
                  'native_identity': {
                      'remote_user_id': 72, 'remote_created_at': CREATED,
                      'remote_username': USER},
                  'capacity_reserved': False, 'provider_account_id': effect_id}
        original_origin=json.dumps(origin,separators=(',',':'))
        if not historical_planless:
            sql("INSERT INTO setting(type,value) VALUES "
                "('ACHBOT_SERVICE_ORIGIN:103',"+api.quote_sql(original_origin)+")")
        sql('UPDATE users SET wallet=900000 WHERE userid=1001')
        sql("INSERT INTO pays(hash_id,user_id,type,plan_id,server_id,order_id,"
            "volume,day,price,request_date,state) VALUES "
            f"('wallet-original-{oid}',1001,'INCREASE_{kind.upper()}_{oid}_1',"
            f"{0 if historical_planless else 7},2,{oid},"
            +('0,10' if kind=='day' else '4,0')+
            ",100000,UNIX_TIMESTAMP(),'processing_wallet')")
        pay_id=int(sql("SELECT id FROM pays WHERE hash_id='wallet-original-"+str(oid)+"'"))
        driver=run.fixture_work/'wallet-edit-recovery.php'
        driver.write_text(PHP)

        def call(op:str,**args):
            proc=subprocess.run([
                'php','-n','-d','extension=mysqlnd','-d','extension=mysqli',
                '-d','extension=curl','-d','extension=mbstring','-d','log_errors=1','-d','error_log=/dev/stderr',
                str(driver),str(ROOT),str(run.fixture_socket),api.DB,
                json.dumps({'op':op,'kind':kind,'pay_id':pay_id,
                            'order_id':oid,**args}),
            ],cwd=run.fixture_work,env=run.fixture_env,capture_output=True,
                text=True,timeout=40)
            if proc.returncode or not proc.stdout:
                raise AssertionError(('fixture_php_failed',op,proc.returncode,
                                      proc.stdout[-900:],proc.stderr[-1200:]))
            if 'fixture-private-key' in proc.stderr or 'SENSITIVE' in proc.stderr:
                raise AssertionError('provider credential leaked')
            return json.loads(proc.stdout)

        saved=call('seed',direct=direct)
        assert saved['ok'],saved
        target=saved['result']['absolute']
        assert saved['result']['baseline']==ORIGINAL_EXPIRE
        if direct:
            assert sql('SELECT provider_state FROM service_effects WHERE id='+str(effect_id))=='linked'
            # The bank/gift API flag must never authorize an original Wallet
            # processing claim. An invalid cross-mode request cannot reach
            # Native or take ownership of this account's mutation fence.
            invalid=call('native_wallet_edit',api_defer=True)
            assert invalid['ok'] and not invalid['result']['success'],invalid
            assert provider.write_count==0
            assert sql('SELECT state FROM pays WHERE id='+str(pay_id))=='processing_wallet'
            assert sql('SELECT provider_state FROM service_effects WHERE id='+str(effect_id))=='linked'
            assert sql('SELECT COUNT(*) FROM increase_order')=='0'
            checks.append('api_deferred_flag_cannot_authorize_original_wallet_'+kind)
            native=call('native_wallet_edit')
            assert native['ok'] and native['result']['success'] and native['result']['verified'],native
            assert native['result']['fence_deferred'] is True,native
            assert sql('SELECT provider_state FROM service_effects WHERE id='+str(effect_id))=='mutating'
            assert sql('SELECT provider_mutation_key FROM service_effects WHERE id='+str(effect_id))=='pay:'+str(pay_id)
            assert sql('SELECT COUNT(*) FROM increase_order')=='0'
            assert provider.write_count==1,provider.trace
            # Simulate PHP death AFTER the original Native PUT, before
            # the callback's local financial COMMIT. The hourly services
            # coordinator must preserve the same paid effect, no second PUT.
            sql("UPDATE pays SET request_date=UNIX_TIMESTAMP()-3600,"
                "updated_at=NULL WHERE id="+str(pay_id))
            system_result=call('cron_recover')
            assert system_result['ok'] and system_result['result']['settled']==1,system_result
            assert sql('SELECT state FROM pays WHERE id='+str(pay_id))=='paid_with_wallet'
            assert sql('SELECT provider_state FROM service_effects WHERE id='+str(effect_id))=='linked'
            assert sql('SELECT COUNT(*) FROM increase_order')=='1'
            assert provider.write_count==1
            assert sql('SELECT COUNT(*) FROM payment_delivery_intents')=='1'
            assert sql('SELECT COUNT(*) FROM payment_attempts')=='1'
            report=call('cron_reports')
            assert report['ok'] and report['result']['acknowledged']==1,report
            assert len(report['events'])==1 and report['events'][0]['kind']=='admin_report'
            customer=call('customer_first_send')
            assert customer['ok'] and customer['result']=='acknowledged',customer
            assert len(customer['events'])==1 and customer['events'][0]['kind']=='customer_send'
            assert provider.write_count==1
            assert call('cron_recover')['result']['settled']==0
            assert call('cron_reports')['result']['acknowledged']==0
            assert call('customer_first_send')['events']==[]
            checks.append('real_native_wallet_'+kind+'_keeps_account_fence_until_original_financial_COMMIT')
            checks.append('crash_after_native_applied_then_cron_one_original_payment_audit')
            checks.append('native_original_claim_once_and_customer_admin_notice_exactly_once')
            return checks
        if kind=='day':
            assert target['expire']==ORIGINAL_EXPIRE+864000,target
        else:
            assert target['expire']==ORIGINAL_EXPIRE,target
            assert target['data_limit']==123400000+4*1073741824,target
        assert call('original')['result']=={
            'kind':kind,'units':10 if kind=='day' else 4,
            'state':'mutating','order_id':None}
        assert sql('SELECT provider_state FROM service_effects WHERE id='+str(effect_id))=='mutating'
        assert sql('SELECT order_id IS NULL FROM service_effects WHERE id='+str(effect_id))=='1'
        checks.append('original_migration_null_order_and_claim_intent_preserved')

        denied=call('resume',actor=9002)
        assert denied['result']['status']=='review',denied
        assert provider.write_count==0
        checks.append('nonstaff_cannot_resume_original_wallet_claim')

        baseline=call('resume')
        assert baseline['result']['status']=='review',baseline
        assert provider.write_count==0
        assert sql('SELECT provider_state FROM service_effects WHERE id='+str(effect_id))=='mutating'
        checks.append('unapplied_baseline_never_triggers_second_native_put')

        provider.user['data_limit']+=27
        drift=call('resume')
        assert drift['result']['status']=='review',drift
        provider.user['data_limit']-=27
        checks.append('independent_native_entitlement_drift_remains_reviewable')

        if not historical_planless:
            wrong=dict(origin,provider='marzban')
            sql('UPDATE setting SET value='+api.quote_sql(json.dumps(wrong))+
                " WHERE type='ACHBOT_SERVICE_ORIGIN:103'")
            changed=call('resume')
            assert changed['result']['status']=='review',changed
            sql('UPDATE setting SET value='+api.quote_sql(original_origin)+
                " WHERE type='ACHBOT_SERVICE_ORIGIN:103'")
            checks.append('invalid_import_origin_never_authorizes_recovery')
        else:
            assert sql("SELECT COUNT(*) FROM setting WHERE type='ACHBOT_SERVICE_ORIGIN:101'")=='0'
            for column, changed, original in (
                ('userid','1002','1001'),
                ('provider_remote_user_id','73','72'),
                ('provider_remote_created_at',api.quote_sql(CREATED+'-changed'),api.quote_sql(CREATED)),
                ('server_id','3','2'),
            ):
                sql('UPDATE orders_list SET '+column+'='+changed+' WHERE id=101')
                held=call('resume')
                assert held['result']['status']=='review',(column,held)
                sql('UPDATE orders_list SET '+column+'='+original+' WHERE id=101')
            checks.append('original_planless_wrong_owner_native_incarnation_and_server_denied')
            original_url=sql('SELECT panel_url FROM server_config WHERE id=2')
            sql("UPDATE server_config SET panel_url='https://wrong-panel.invalid' WHERE id=2")
            held=call('resume')
            assert held['result']['status']=='review',held
            sql('UPDATE server_config SET panel_url='+api.quote_sql(original_url)+' WHERE id=2')
            checks.append('original_planless_repointed_physical_endpoint_denied')
            sql("INSERT INTO orders_list (id,userid,token,transid,fileid,server_id,inbound_id,"
                "remark,uuid,expire_date,link,amount,status,date,autoRenew,"
                "provider_effect_id,provider_remote_user_id,provider_remote_created_at) VALUES "
                "(104,1001,'unlisted','',0,2,0,'historical_fixture',"
                "'unlisted-uuid',"+str(ORIGINAL_EXPIRE)+",'[]',1200,1,'fixture','off',"
                f"{effect_id},72,{api.quote_sql(CREATED)})")
            unlisted=call('validate_ref',order_id=104)
            assert not unlisted['ok'],unlisted
            checks.append('unlisted_historical_reference_not_validated_as_planless_origin')

        if kind=='day':
            provider.user['expire']=dt.datetime.fromtimestamp(
                target['expire'],dt.timezone.utc).isoformat().replace('+00:00','Z')
        else:
            provider.user['data_limit']=target['data_limit']
        if finalization_failure:
            sql("CREATE TRIGGER wallet_resume_local_audit_fail BEFORE INSERT ON increase_order "
                "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture_audit_failure'")
            failed=call('resume')
            assert failed['result']['status']=='review',failed
            assert sql('SELECT provider_state FROM service_effects WHERE id='+str(effect_id))=='mutating'
            assert sql('SELECT provider_mutation_key FROM service_effects WHERE id='+str(effect_id))=='pay:'+str(pay_id)
            competitor=call('compete')
            assert not competitor['ok'] and 'mutation_requires_review' in competitor['error'],competitor
            assert sql('SELECT provider_state FROM service_effects WHERE id='+str(effect_id))=='mutating'
            assert sql('SELECT provider_mutation_key FROM service_effects WHERE id='+str(effect_id))=='pay:'+str(pay_id)
            assert sql('SELECT state FROM pays WHERE id='+str(pay_id))=='processing_wallet'
            assert sql('SELECT expire_date FROM orders_list WHERE id='+str(oid))==str(ORIGINAL_EXPIRE)
            assert sql('SELECT COUNT(*) FROM increase_order')=='0'
            assert sql('SELECT wallet FROM users WHERE userid=1001')=='900000'
            assert provider.write_count==0
            checks.append('audit_failure_preserves_original_mutation_fence_and_blocks_competitor')
            sql('DROP TRIGGER wallet_resume_local_audit_fail')
        if notice_failure:
            marker='WALLET_ADDON_REPORT:'+str(pay_id)
            sql("INSERT INTO setting(type,value) VALUES ("+
                api.quote_sql(marker)+",'do-not-overwrite-existing-original')")
            failed=call('resume')
            assert failed['result']['status']=='review',failed
            assert sql('SELECT provider_state FROM service_effects WHERE id='+str(effect_id))=='mutating'
            assert sql('SELECT provider_mutation_key FROM service_effects WHERE id='+str(effect_id))=='pay:'+str(pay_id)
            assert sql('SELECT state FROM pays WHERE id='+str(pay_id))=='processing_wallet'
            assert sql('SELECT COUNT(*) FROM increase_order')=='0'
            assert sql('SELECT COUNT(*) FROM payment_attempts')=='0'
            assert sql('SELECT COUNT(*) FROM payment_delivery_intents')=='0'
            assert provider.write_count==0
            checks.append('notice_commit_failure_preserves_original_finance_account_fence')
            sql('DELETE FROM setting WHERE type='+api.quote_sql(marker))
        if system:
            sql("UPDATE pays SET request_date=UNIX_TIMESTAMP()-3600,"
                "updated_at=NULL WHERE id="+str(pay_id))
            sql("UPDATE service_effects SET provider_evidence_updated_at="
                "DATE_SUB(NOW(),INTERVAL 10 MINUTE) WHERE id="+str(effect_id))
            result=call('cron_recover')
            assert result['ok'] and result['result']['settled']==1,result
            checks.append('cron_recovers_only_native_applied_original_'+kind)
        elif concurrent:
            with ThreadPoolExecutor(max_workers=2) as pool:
                futures=[pool.submit(call,'resume') for _ in range(2)]
                outcomes=[f.result() for f in futures]
            statuses=[x['result'].get('status') for x in outcomes]
            assert statuses.count('settled_delivery_pending')==1,outcomes
            assert all(s in ('settled_delivery_pending','review','already_paid_state')
                       for s in statuses),outcomes
            checks.append('concurrent_staff_recoveries_have_one_committed_settlement')
        else:
            result=call('resume',commit_ack_lost=commit_ack_lost)
            if commit_ack_lost:
                assert result['result']['status']=='review',result
                assert call('resume')['result']['status']=='already_paid_state'
                checks.append('ambiguous_final_commit_ack_does_not_repeat_original_settlement')
            else:
                assert result['ok'] and result['result']['status']=='settled_delivery_pending',result
        assert sql('SELECT state FROM pays WHERE id='+str(pay_id))=='paid_with_wallet'
        assert sql('SELECT expire_date FROM orders_list WHERE id='+str(oid))==str(target['expire'])
        col='day' if kind=='day' else 'volume'
        units=10 if kind=='day' else 4
        assert sql(f'SELECT COUNT(*) FROM increase_order WHERE userid=1001 AND {col}={units} AND amount=100000')=='1'
        assert sql('SELECT wallet FROM users WHERE userid=1001')=='900000'
        assert sql('SELECT provider_state FROM service_effects WHERE id='+str(effect_id))=='linked'
        assert sql('SELECT order_id IS NULL FROM service_effects WHERE id='+str(effect_id))=='1'
        assert sql("SELECT source_kind FROM service_effects WHERE id="+str(effect_id))=='legacy_migration'
        assert provider.write_count==0
        checks.append('applied_native_once_local_payment_order_and_audit_atomic')

        replay=call('resume')
        assert replay['result']['status']=='already_paid_state',replay
        assert sql('SELECT COUNT(*) FROM increase_order')=='1'
        assert provider.write_count==0
        assert sql('SELECT COUNT(*) FROM payment_delivery_intents')=='1'
        assert sql("SELECT state FROM payment_delivery_intents WHERE pay_row_id="+str(pay_id))=='pending'
        assert sql('SELECT COUNT(*) FROM payment_attempts WHERE pay_row_id='+str(pay_id))=='1'
        assert sql("SELECT provider_status FROM payment_attempts WHERE pay_row_id="+str(pay_id))=='wallet_reconciled_notice'
        assert sql("SELECT JSON_UNQUOTE(JSON_EXTRACT(value,'$.state')) FROM setting WHERE type='WALLET_ADDON_REPORT:"+str(pay_id)+"'")=='pending'
        checks.append('repeat_resume_one_original_customer_outbox_and_admin_report_no_telegram_replay')
        if report_scenarios:
            # Customer success may be entirely independent of an admin fanout
            # with an ambiguous ACK. It must remain in the SAME attention inbox.
            before=call('report_case')
            assert before['result']['case']['state']=='pending',before
            assert before['result']['inbox'],before
            first=call('cron_reports',report_ack=False)
            assert first['result']['unknown']==1 and len(first['events'])==1,first
            customer=call('customer_first_send')
            assert customer['result']=='acknowledged',customer
            pending=call('report_case')
            assert pending['result']['case']['state']=='unknown_or_partial',pending
            assert pending['result']['inbox'] is True,pending
            ui=call('report_ui',data='attWReportI_'+str(pay_id))
            edits=[event for event in ui['events'] if event['kind']=='ui_edit']
            assert ui['ok'] and len(edits)==1,ui
            assert 'گزارش اصلی ادمین' in edits[0]['text'],ui
            assert 'attWReportS_'+str(pay_id)+'_' in edits[0]['keyboard'],ui
            ui_denied=call('report_ui',actor=9999999,
                           data='attWReportI_'+str(pay_id))
            assert not [event for event in ui_denied['events'] if
                        event['kind']=='ui_edit'],ui_denied
            checks.append('persian_report_unknown_preview_and_staff_private_callback')
            bad=call('report_token',actor=9999999)['result']['token']
            denied=call('report_manual',actor=9999999,token=bad)
            assert denied['result']=='denied' and not denied['events'],denied
            assert call('report_case')['result']['case']['manual_attempts']==0
            token=call('report_token')['result']['token']
            with ThreadPoolExecutor(max_workers=2) as pool:
                done=[future.result() for future in
                    (pool.submit(call,'report_manual',token=token,
                                 report_ack=not report_manual_unknown),
                     pool.submit(call,'report_manual',token=token,
                                 report_ack=not report_manual_unknown))]
            statuses=[item['result'] for item in done]
            terminal='unknown_or_partial' if report_manual_unknown else 'acknowledged'
            assert statuses.count(terminal)==1 and set(statuses)<=set(
                [terminal,'busy','stale']),done
            assert sum(len(x['events']) for x in done)==1,done
            latest=call('report_case')['result']['case']
            assert latest['state']==terminal and latest['manual_attempts']==1,latest
            assert call('report_case')['result']['inbox'] is report_manual_unknown
            assert call('cron_reports')['events']==[]
            assert call('report_manual',token=token)['result']=='stale'
            if report_manual_unknown:
                assert call('report_token')['result']['token']!=token
                assert call('report_manual',token=token,
                    report_ack=True)['events']==[]
                checks.append('admin_retry_unknown_remains_attention_and_cannot_auto_or_manual_resend')
            else:
                checks.append('admin_real_ack_closes_attention_without_second_wallet_native_or_audit')
            assert sql('SELECT wallet FROM users WHERE userid=1001')=='900000'
            assert sql('SELECT COUNT(*) FROM increase_order')=='1'
            assert provider.write_count==0
            checks.append('customer_ack_admin_unknown_retained_in_shared_attention_inbox')
            checks.append('operator_denial_and_concurrent_manual_report_one_retry')
        if system:
            report=call('cron_reports')
            assert report['ok'] and report['result']['acknowledged']==1,report
            assert [e['kind'] for e in report['events']]==['admin_report'],report
            assert sql("SELECT JSON_UNQUOTE(JSON_EXTRACT(value,'$.state')) FROM setting WHERE type='WALLET_ADDON_REPORT:"+str(pay_id)+"'")=='acknowledged'
            customer=call('customer_first_send',telegram_unknown=telegram_unknown)
            expected='unknown' if telegram_unknown else 'acknowledged'
            assert customer['ok'] and customer['result']==expected,customer
            assert [e['kind'] for e in customer['events']]==['customer_send'],customer
            assert sql('SELECT state FROM payment_delivery_intents WHERE pay_row_id='+str(pay_id))==expected
            assert sql('SELECT attempt_count FROM payment_delivery_intents WHERE pay_row_id='+str(pay_id))=='1'
            repeat_report=call('cron_reports')
            assert repeat_report['result']['acknowledged']==0 and repeat_report['events']==[],repeat_report
            repeat_customer=call('customer_first_send')
            assert repeat_customer['result']!='acknowledged' and repeat_customer['events']==[],repeat_customer
            assert provider.write_count==0
            checks.append('cron_first_customer_notice_and_admin_fanout_once_'+kind)
            checks.append('cron_replay_does_not_duplicate_telegram_or_native_'+kind)
            if telegram_unknown:
                checks.append('telegram_unknown_ack_remains_one_attempt_no_auto_resend')
    return checks


def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('--run-disposable',action='store_true',required=True)
    parser.add_argument('--report',type=Path)
    args=parser.parse_args()
    checks=(case()+case(kind='volume',finalization_failure=True)+
            case(historical_planless=True)+case(concurrent=True,commit_ack_lost=False)+
            case(commit_ack_lost=True)+
            case(system=True)+case(kind='volume',system=True)+
            case(direct=True)+case(kind='volume',direct=True)+
            case(notice_failure=True)+case(system=True,telegram_unknown=True)+
            case(report_scenarios=True)+
            case(report_scenarios=True,report_manual_unknown=True))
    result={'complete':True,'cases':len(checks),'checks':checks,
            'real_php_source':True,'disposable_mariadb':True,
            'local_native_only':True,'production_mutations':0}
    if args.report:
        args.report.write_text(json.dumps(result,indent=2)+'\n')
    print(json.dumps(result))

if __name__=='__main__':
    main()
