#!/usr/bin/env python3
"""Issue #227: real WalletSettlement -> PasarGuard lost CREATE ACK -> staff finality.

Actual current PHP + disposable MariaDB + owned authenticated loopback PG HTTP.
No live Native, Wallet, customers, Telegram, Cron or production mutations.
"""
from __future__ import annotations

import argparse
import json
import subprocess
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path
from unittest.mock import patch

import test_post_v9_scoped_api_pg as api
import test_post_v9_wallet_creation as wallet_create
import test_post_v9_wallet_original_recovery as original

ROOT=Path(__file__).resolve().parents[1]


def drivers(work):
    create=wallet_create.PHP
    create=create.replace("$_SERVER['SCRIPT_FILENAME']=__FILE__;",
        "define('ACHBOT_PG_TEST_LOOPBACK',true);\n$_SERVER['SCRIPT_FILENAME']=__FILE__;")
    create=create.replace("'server_type'=>'marzban'","'server_type'=>'pasarguard'")
    before="""$realName,'testuuid','vless',time()+30*86400,
            'fixture-link',(int)$pay['price'],time(),0,0,true);"""
    after="""$realName,'testuuid',time()+30*86400,
            'fixture-link',(int)$pay['price'],time(),0,true);"""
    assert create.count(before)==1
    create=create.replace(before,after)
    at="$hash=(string)($spec['hash']??'walletcreate');"
    assert create.count(at)==1
    create=create.replace(at,at+"""
require_once $root.'/src/Panel/ProviderRuntime.php';
$stmt=$db->prepare('SELECT id FROM pays WHERE hash_id=?');
$stmt->bind_param('s',$hash);$stmt->execute();
$pid=(int)$stmt->get_result()->fetch_assoc()['id'];$stmt->close();
achbotPgPinPayment($db,$pid);
""")
    created=work/'source-wallet-pg-create.php';created.write_text(create)
    recover=original.PHP.replace("$_SERVER['SCRIPT_FILENAME']=__FILE__;",
        "define('ACHBOT_PG_TEST_LOOPBACK',true);\n$_SERVER['SCRIPT_FILENAME']=__FILE__;")
    point="    } elseif ($op==='marzban') {"
    assert recover.count(point)==1
    recover=recover.replace(point,"""    } elseif ($op==='pg_detail') {
        $id=(int)$spec['order'];
        $context=achbotPgOrderContext($db,$id);
        $read=achbotPgRead($context['panel'],$context['identity']);
        $result=['linked'=>($context['account']['state']??null)==='linked',
            'order_id'=>$id,'remote_id'=>$context['identity']['remote_user_id'],
            'created_at'=>$context['identity']['remote_created_at'],
            'native_verified'=>($read['success']??false)===true,
            'username'=>$read['user']['username']??null];
"""+point)
    recovered=work/'source-wallet-pg-recovery.php';recovered.write_text(recover)
    return created,recovered


def case(variant:str):
    checks=[]
    # api.sandbox already owns Native incarnation 17 for a separate older
    # account. A source-REAL new CREATE is a different incarnation (18).
    with api.sandbox() as (sql,runner,provider,_bank,_epoch):
        work=runner.fixture_work;env=runner.fixture_env
        created,recovered=drivers(work)
        sql((ROOT/'migrations/post-v9/023_scoped_payment_lock_indexes.sql').read_text())
        sql("DELETE FROM setting WHERE type='BOT_STATES'")
        sql("INSERT INTO setting(type,value) VALUES ('BOT_STATES',"+
            api.quote_sql(json.dumps({'walletState':'on',
                'gatewayAudience':{'wallet':'all'}}))+')')
        name='pg-wallet-original-'+variant
        sql("INSERT INTO pays (hash_id,user_id,type,plan_id,server_id,agent_count,"
            "price,day,volume,request_date,state) VALUES ("+
            api.quote_sql(name)+",1001,'BUY_SUB',7,2,1,100,0,0,UNIX_TIMESTAMP(),'pending')")
        pay=int(sql('SELECT id FROM pays WHERE hash_id='+api.quote_sql(name)))
        def call(source,op=None,**kw):
            spec={'hash':name} if op is None else {'op':op,'pay':pay}
            spec.update(kw)
            cmd=['php','-n','-d','extension=mysqlnd','-d','extension=mysqli',
                '-d','extension=curl','-d','extension=ctype',
                '-d','log_errors=1','-d','error_log=/dev/stderr',
                str(source),str(ROOT),str(runner.fixture_socket),api.DB,
                json.dumps(spec,separators=(',',':'))]
            p=subprocess.run(cmd,cwd=work,env=env,capture_output=True,text=True,timeout=55)
            if p.returncode or not p.stdout:
                raise AssertionError(('php85_runtime_failed',op,variant,p.returncode,
                    p.stdout[-500:],p.stderr[-950:]))
            try:return json.loads(p.stdout)
            except ValueError as error:
                raise AssertionError(('php85_result_invalid',op,p.stdout[-450:],
                    p.stderr[-900:])) from error
        def invoke(op,**kw):
            result=call(recovered,op,**kw)
            assert result['ok'] is True,result
            return result['result']
        with patch.dict(api.pg.USER,{'id':18}):
            provider.user=None
            provider.trace.clear()
            provider.mode='write_lost' if variant not in ('verified','linked') else 'ok'
            initial_wallet=int(sql('SELECT wallet FROM users WHERE userid=1001'))
            initial_capacity=int(sql('SELECT ucount FROM server_info WHERE id=2'))
            created_result=call(created,None,order_failure=variant!='linked')
            assert created_result['result']['status']==('ok' if variant=='linked' else 'error'),created_result
            provider.mode='ok'
            assert provider.user and provider.user['id']==18 and (
                provider.user['note'].startswith('achbot:v1:')),provider.user
            assert len([a for a in provider.trace if a[0]=='POST' and a[1]=='/api/user'])==1,provider.trace
            assert sql(f'SELECT state FROM pays WHERE id={pay}')==(
                'paid_with_wallet' if variant=='linked' else 'processing_wallet')
            assert int(sql('SELECT wallet FROM users WHERE userid=1001'))==initial_wallet-100
            assert int(sql('SELECT ucount FROM server_info WHERE id=2'))==initial_capacity-1
            effect=sql(f"SELECT id,state,provider_state,remote_username,operation_id,"
                f"requested_expire,requested_bytes,provider_remote_user_id "
                f"FROM service_effects WHERE pay_row_id={pay} AND effect_kind='create'").split('\t')
            assert len(effect)==8,effect
            effect_id,origin_state,provider_state,username,operation,expiry,quota,remote_id=effect
            assert origin_state==('local_recorded' if variant=='linked' else
                'verified' if variant=='verified' else 'dispatching'),effect
            assert sql(f"SELECT state,reserved_count FROM service_effects WHERE pay_row_id={pay} "
                       "AND effect_kind='wallet_hold'")==('consumed\t1')
            assert provider.user['note']=='achbot:v1:'+operation
            assert int(quota)==provider.user['data_limit']
            native_posts_before=sum(a[0]=='POST' for a in provider.trace)
            wallet_after=sql('SELECT wallet FROM users WHERE userid=1001')
            capacity_after=sql('SELECT ucount FROM server_info WHERE id=2')
            if variant=='linked':
                # Source CREATE and original order committed; terminal Wallet
                # payment COMMIT is synthetically lost, not another debit.
                sql(f"UPDATE pays SET state='processing_wallet' WHERE id={pay}")
                checks.append('pg_source_real_already_linked_pre_terminal_wallet_claim')
            if variant=='reserved':
                # The same source operation may be rearmed before the GET
                # acknowledgement. There is NO new capacity reservation.
                sql(f"UPDATE service_effects SET state='reserved',"
                    "provider_state='reserved' WHERE id="+effect_id)
                checks.append('pg_reserved_original_effect_same_operation_no_reallocation')
            assert invoke('inspect',actor=999999)['status']=='denied'
            if variant in ('lost','reserved','verified','linked','stale','fault'):
                inspected=invoke('inspect')
                assert inspected['status']=='ready',inspected
                assert inspected['lines'][0]['status'] in (
                    'matching_original_linked','matching_original_unlinked_recoverable'),inspected
                assert sql(f"SELECT state FROM service_effects WHERE id={effect_id}")==(
                    'reserved' if variant=='reserved' else origin_state)
                checks.append('pg_get_only_operator_preview_keeps_original_effect_unchanged')
                token=invoke('token')
                assert isinstance(token,str) and len(token)==20,token
                assert invoke('complete',token='0'*20)['status']=='review'
                assert invoke('complete',token=token,actor=999999)['status']=='review'
                assert sql(f'SELECT state FROM pays WHERE id={pay}')=='processing_wallet'
                checks.append('pg_forged_and_unauthorized_confirmation_no_native_write')
                if variant=='stale':
                    initial_proof=inspected['proof']
                    provider.user['created_at']='2026-10-26T05:00:01.123456+00:00'
                    changed=invoke('inspect')
                    assert changed['status']=='ready' and changed['proof']!=initial_proof,changed
                    assert invoke('complete',token=token)['status']=='review'
                    assert sql(f'SELECT state FROM pays WHERE id={pay}')=='processing_wallet'
                    assert sql(f"SELECT state FROM service_effects WHERE id={effect_id}")==origin_state
                    checks.append('pg_native_incarnation_changes_make_original_confirmation_stale')
                    provider.user['created_at']=api.pg.USER['created_at']
                    # The prior Native incarnation ID itself is part of
                    # the GET-observed operator token even before storage.
                    provider.user['id']=19
                    assert invoke('complete',token=token)['status']=='review'
                    provider.user['id']=18
                    token=invoke('token')
                    checks.append('pg_changed_remote_id_invalidates_stale_operator_token')
                if variant=='fault':
                    sql("CREATE TRIGGER pg_wallet_order_failure BEFORE INSERT ON orders_list "
                        "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture'")
                    assert invoke('complete',token=token)['status']=='review'
                    assert sql(f'SELECT state FROM pays WHERE id={pay}')=='processing_wallet'
                    assert sql(f"SELECT COUNT(*) FROM setting WHERE type='WALLET_ADDON_REPORT:{pay}'")=='0'
                    sql('DROP TRIGGER pg_wallet_order_failure')
                    # Local effect verification was already committed; never
                    # reissue Native CREATE and confirm the same original row.
                    token=invoke('token')
                    checks.append('pg_verified_effect_survives_local_sql_failure_without_replay')
                if variant=='lost':
                    for field,value in (
                        ('note','foreign-operation'),('username','wrong-user'),
                        ('group_ids',[2]),('data_limit',int(quota)+1073741824),
                        ('status','on_hold')):
                        saved=provider.user[field]
                        provider.user[field]=value
                        assert invoke('inspect')['status']=='review',(field,value)
                        assert invoke('complete',token=token)['status']=='review'
                        provider.user[field]=saved
                    old_exp=provider.user['expire']
                    provider.user['expire']='2030-01-01T00:00:00Z'
                    assert invoke('inspect')['status']=='review'
                    provider.user['expire']=old_exp
                    checks.append('pg_foreign_marker_username_groups_quota_expiry_status_hold')
                    provider.user['created_at']='2026-10-26T05:00:01.123456+00:00'
                    assert invoke('complete',token=token)['status']=='review'
                    provider.user['created_at']=api.pg.USER['created_at']
                    checks.append('pg_stale_native_incarnation_denies_original_token')
                    # Repointing the source connection is never a recovered
                    # identity. Revert test-only connection after the hold.
                    original_url=sql('SELECT panel_url FROM server_config WHERE id=2')
                    sql("UPDATE server_config SET panel_url='http://127.0.0.1:9' WHERE id=2")
                    assert invoke('inspect')['status']=='review'
                    sql('UPDATE server_config SET panel_url='+api.quote_sql(original_url)+
                        ' WHERE id=2')
                    checks.append('pg_repointed_endpoint_keeps_human_review_hold')
                    original_instance=sql('SELECT provider_instance_key FROM server_config WHERE id=2')
                    sql("UPDATE server_config SET provider_instance_key=REPEAT('a',32) WHERE id=2")
                    assert invoke('inspect')['status']=='review'
                    sql('UPDATE server_config SET provider_instance_key='+
                        api.quote_sql(original_instance)+' WHERE id=2')
                    checks.append('pg_changed_provider_connection_instance_denies_original_recovery')
                before_orders=int(sql('SELECT COUNT(*) FROM orders_list'))
                if variant=='lost':
                    with ThreadPoolExecutor(max_workers=2) as pool:
                        future=[pool.submit(invoke,'complete',token=token) for _ in range(2)]
                        results=[f.result() for f in future]
                    assert [r['status'] for r in results].count('settled_delivery_pending')==1,results
                    checks.append('pg_concurrent_confirmations_create_only_one_original_order')
                else:
                    result=invoke('complete',token=token,
                        commit_ack_lost=variant=='verified')
                    assert result['status'] in ('settled_delivery_pending','already_paid'),result
                    if variant=='verified':
                        assert result['status']=='already_paid',result
                        checks.append('pg_original_local_commit_ack_lost_is_idempotent')
                assert sql(f'SELECT state FROM pays WHERE id={pay}')=='paid_with_wallet'
                assert sql(f"SELECT state,provider_state FROM service_effects WHERE id={effect_id}")==(
                    'local_recorded\tlinked')
                assert int(sql('SELECT COUNT(*) FROM orders_list'))==(
                    before_orders+int(origin_state!='local_recorded'))
                assert int(sql(f'SELECT COUNT(*) FROM payment_attempts WHERE pay_row_id={pay}'))==1
                assert int(sql(f'SELECT COUNT(*) FROM payment_delivery_intents WHERE pay_row_id={pay}'))==1
                assert sql(f"SELECT COUNT(*) FROM setting WHERE type='WALLET_ADDON_REPORT:{pay}'")=='1'
                assert sql('SELECT wallet FROM users WHERE userid=1001')==wallet_after
                assert sql('SELECT ucount FROM server_info WHERE id=2')==capacity_after
                assert sum(a[0]=='POST' for a in provider.trace)==native_posts_before
                assert not any(a[0] in ('PUT','DELETE') for a in provider.trace)
                order=int(sql(f"SELECT order_id FROM service_effects WHERE id={effect_id}"))
                provider_ref=sql(f'SELECT provider_effect_id,provider_remote_user_id,'
                    f'provider_remote_created_at FROM orders_list WHERE id={order}').split('\t')
                assert provider_ref==[effect_id,'18',api.pg.USER['created_at'].replace('+00:00','Z')],provider_ref
                detail=invoke('pg_detail',order=order)
                assert detail['linked'] and detail['native_verified'] and (
                    detail['remote_id']==18 and detail['username']==username),detail
                checks.append('pg_native_id_incarnation_and_original_effect_link_to_order_detail_get')
                delivered=call(recovered,'deliver')
                assert delivered['ok'] and delivered['result']=='acknowledged',delivered
                assert sum(e['type']=='first_customer_delivery' for e in delivered['events'])==1
                assert not any(e['type']=='first_customer_delivery' for e in call(
                    recovered,'deliver')['events'])
                reported=call(recovered,'reports')
                assert reported['ok'] and reported['result']['acknowledged']==1,reported
                assert sum(e['type']=='admin_report' for e in reported['events'])==1
                assert not any(e['type']=='admin_report' for e in call(
                    recovered,'reports')['events'])
                checks.append('pg_source_customer_and_admin_telegram_first_send_ack_once')
                again=invoke('complete',token=token)
                assert again['status']=='already_paid',again
                assert sum(a[0]=='POST' for a in provider.trace)==native_posts_before
                checks.append('pg_original_payment_wallet_capacity_customer_admin_once_only')
            else:
                for field,value in (
                    ('note','foreign-operation'),('username','foreign-user'),
                    ('group_ids',[2]),('data_limit',int(quota)+1)):
                    old=provider.user[field];provider.user[field]=value
                    assert invoke('inspect')['status']=='review',field
                    provider.user[field]=old
                # Before a first authenticated observation, a new ID with
                # the exact original operation marker cannot be disproven;
                # AFTER first GET, the actor token pins ID + incarnation.
                token=invoke('token')
                provider.user['id']=19
                assert invoke('complete',token=token)['status']=='review'
                provider.user['id']=18
                provider.user=None
                assert invoke('inspect')['status']=='review'
                assert invoke('token') is None
                checks.append('pg_unknown_native_original_requires_human_hold_no_replay')
                assert sum(a[0]=='POST' for a in provider.trace)==native_posts_before
    return checks


def test():
    checks=[]
    for variant in ('lost','reserved','verified','linked','stale','fault','unproven'):
        checks.extend(case(variant))
    return {'complete':True,'cases':len(checks),'checks':checks,
        'php85':True,'disposable_mariadb':True,
        'authenticated_pg_native_http':True,
        'production_mutations':0}


def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('--run-disposable',action='store_true')
    parser.add_argument('--report',type=Path)
    a=parser.parse_args()
    if not a.run_disposable:parser.error('requires --run-disposable')
    result=test()
    if a.report:a.report.write_text(json.dumps(result,ensure_ascii=False,indent=2)+'\n')
    print(json.dumps(result,ensure_ascii=False))


if __name__=='__main__':main()
