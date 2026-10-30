#!/usr/bin/env python3
"""Actual direct bot route + native adapters on owned loopback/socket fixtures.

No installed tenant, real provider, Telegram or financial operation is used.
The existing direct-gift suite supplies the unmodified route extraction and
wizard harness; its synthetic Marzban edit is replaced with the real facade.
"""
from __future__ import annotations
import argparse
import copy
import contextlib
import io
from datetime import datetime
import json
from pathlib import Path
import subprocess

import provider_storage_fixture as storage
import test_access_state as owned
import test_post_v9_direct_gift as direct
import test_post_v9_gift_physical as physical
import test_post_v9_pasarguard_client as pg

ROOT=Path(__file__).resolve().parents[1]


class NativePayloadPanel(physical.Panel):
    def route(self):
        if self.command!='PUT':return super().route()
        raw=self.rfile.read(int(self.headers.get('Content-Length','0')))
        self.server.put_payloads.append(json.loads(raw))
        stream=self.rfile;self.rfile=io.BytesIO(raw)
        try:return super().route()
        finally:self.rfile=stream


@contextlib.contextmanager
def traced_panels():
    # Observe exact wire bytes while retaining the existing HTTP fixture.
    original=physical.Panel;physical.Panel=NativePayloadPanel
    try:
        with physical.panels() as values:
            values[0].put_payloads=[];values[1].put_payloads=[]
            yield values
    finally:physical.Panel=original


def native_driver(stage):
    php=direct.PHP
    start=php.index('function editMarzbanConfig(')
    end=php.index("require_once __DIR__.'/src/Purchase/Core.php';",start)
    config_start=physical.CONFIG.index("require_once __DIR__.'/src/Panel/Accounts.php';")
    config_end=physical.CONFIG.index('function sendMessage(',config_start)
    php=php[:start]+physical.CONFIG[config_start:config_end]+php[end:]
    php=php.replace("if (PHP_SAPI !== 'cli') exit(3);", "if (PHP_SAPI !== 'cli') exit(3);\ndefine('ACHBOT_PG_TEST_LOOPBACK',true);")
    original=r'return \AchBot\Panel\Accounts\editMarzbanConfig($server,$info,$target);'
    observed=r'''$native=\AchBot\Panel\Accounts\editMarzbanConfig($server,$info,$target);
    $public=array_intersect_key(get_object_vars($native),array_flip([
        'success','verified','uncertain','before_expire','applied_expire','applied_bytes','msg']));
    file_put_contents(__DIR__.'/native-adapter-results.jsonl',json_encode($public,JSON_THROW_ON_ERROR)."\n",FILE_APPEND);
    return $native;'''
    direct.ensure(php.count(original)==1,'Native adapter observation seam changed')
    php=php.replace(original,observed)
    php=php.replace("} elseif (($spec['route']??'gift')==='moderation') {",r''' } elseif (($spec['route']??'gift')==='core_probe') {
    $result=achbotApplyServiceEdit($connection,2,['remark'=>'fixture-user','plus_volume'=>1]);
    $GLOBALS['events'][]=['core_probe'=>$result];
} elseif (($spec['route']??'gift')==='moderation') {''')
    (stage/'driver.php').write_text(php)


def call(work,env,stage,**spec):
    proc=subprocess.run(physical.php_args(stage,'driver.php')+[json.dumps(spec)],
        cwd=stage,env={**env,'ACHBOT_DIRECT_DB':direct.DB,
        'ACHBOT_DIRECT_SOCKET':str(work/'mysql.sock'),
        'ACHBOT_DIRECT_LOG':str(stage/'effects.jsonl'),
        'http_proxy':'','https_proxy':'','all_proxy':'',
        'no_proxy':'127.0.0.1,localhost'},capture_output=True,text=True,timeout=38)
    direct.ensure(proc.returncode==0,'Actual native direct PHP failed: '+
        repr((proc.returncode,proc.stdout[-700:],proc.stderr[-700:])))
    direct.ensure(all(secret not in proc.stderr for secret in
        ['fixture-password','fixture-private-key',direct.SECRET]),'Private diagnostic leak')
    return json.loads(proc.stdout)


def migration_setup(work,env):
    for name in ['001_marzban_panel_group_id.sql','004_automatic_renewal_attempts.sql',
                 '007_service_effects.sql','011_provider_identity.sql','012_shared_provider_storage.sql']:
        direct.q(work,env,(ROOT/'migrations/post-v9'/name).read_text())


def wizard(work,env,stage,days,volume,update=500,message=50):
    for spec in [dict(kind='callback',update_id=update,message_id=message,text='giftToUser2'),
                 dict(kind='message',update_id=update+1,message_id=message+1,text='fixture-user'),
                 dict(kind='message',update_id=update+2,message_id=message+2,text=str(days))]:
        call(work,env,stage,**spec)
    return call(work,env,stage,kind='message',update_id=update+3,message_id=message+3,text=str(volume))


def zero_expiry_cases(work,env,stage,a,url_a,server,url_pg,checks,observations):
    # Marzban's literal numeric zero is an existing finite API observation,
    # distinct from NULL/missing/malformed time and from unlimited quota.
    cases=[
        ('marzban_zero_expiry_quota_active','marzban',0,'active',direct.OLD_EXPIRY,0,1024,False,1,True),
        ('marzban_zero_expiry_quota_limited','marzban',0,'limited',0,0,1024,False,1,True),
        ('marzban_null_expiry_quota_held','marzban',None,'active',direct.OLD_EXPIRY,0,1024,False,0,False),
        ('marzban_missing_expiry_quota_held','marzban','@missing','active',direct.OLD_EXPIRY,0,1024,False,0,False),
        ('marzban_fractional_expiry_quota_held','marzban',0.5,'active',direct.OLD_EXPIRY,0,1024,False,0,False),
        ('marzban_boolean_expiry_quota_held','marzban',False,'active',direct.OLD_EXPIRY,0,1024,False,0,False),
        ('marzban_malformed_expiry_quota_held','marzban','0e0','active',direct.OLD_EXPIRY,0,1024,False,0,False),
        ('marzban_zero_expiry_unlimited_quota_held','marzban',0,'active',direct.OLD_EXPIRY,0,1024,True,0,False),
        ('marzban_positive_day_zero_readback_held','marzban',direct.OLD_EXPIRY,'active',direct.OLD_EXPIRY,2,0,False,1,False),
        # PG explicitly normalizes native zero to NULL; its quota-only
        # finite-entitlement guard remains the authority, without reinterpretation.
        ('pasarguard_zero_expiry_quota_held','pasarguard',0,'active',direct.OLD_EXPIRY,0,1024,False,0,False),
    ]
    for name,provider,expire,status,local_expire,days,volume,unlimited,writes_expected,success in cases:
        direct.reset(work,env)
        direct.q(work,env,'UPDATE orders_list SET expire_date='+str(local_expire)+',notif=1 WHERE id=91')
        if provider=='marzban':
            direct.q(work,env,"UPDATE server_config SET panel_url='"+url_a+"' WHERE id=2")
            a.events.clear();a.errors.clear();a.put_count=0
            user=physical.remote_user('fixture-user')
            user.update(expire=expire,status=status)
            if expire=='@missing':del user['expire']
            if unlimited:user['data_limit']=None
            if status=='limited':user['used_traffic']=user['data_limit']
            a.accounts={'fixture-user':user}
            a.hook=None;a.hook_at=None;a.lost_put=False;a.fail_readback=False;a.list_mode='complete'
            if status=='limited':
                # Native quota PUT naturally reactivates a limited account;
                # only the HTTP fixture status transition is modeled here.
                a.hook_at='after_put'
                a.hook=lambda:a.accounts['fixture-user'].update(status='active')
            elif days>0:
                # A foreign/invalid zero observed after a duration PUT is
                # unverified, even if the PUT acknowledgement looked successful.
                a.hook_at='after_put'
                a.hook=lambda:a.accounts['fixture-user'].update(expire=0)
        else:
            direct.q(work,env,'DELETE FROM service_effects')
            direct.q(work,env,storage.connection(2,1,url_pg))
            server.user=copy.deepcopy(pg.USER);server.user.update(username='fixture-user',expire=expire)
            server.trace=[];server.write_count=0;server.mode='ok'
            direct.q(work,env,storage.identity(1,91,1,url_pg,17,
                '2026-10-26T05:00:00.123456Z','fixture-user',pg.MARKER,
                dict(data_limit=2147483648,expire=expire,group_ids=[2,5],status='active')))
        a.put_payloads=[]
        adapter_log=stage/'native-adapter-results.jsonl';adapter_log.unlink(missing_ok=True)
        before_money=direct.q(work,env,'SELECT userid,wallet FROM users ORDER BY id;SELECT * FROM pays ORDER BY id')
        # Capture the exact old final step, then replay that authenticated
        # source envelope through claim admission despite the consumed DB step.
        for spec in [dict(kind='callback',update_id=500,message_id=50,text='giftToUser2'),
                     dict(kind='message',update_id=501,message_id=51,text='fixture-user'),
                     dict(kind='message',update_id=502,message_id=52,text=str(days))]:
            call(work,env,stage,**spec)
        old_step=direct.q(work,env,'SELECT step FROM users WHERE userid=9001')
        result=call(work,env,stage,kind='message',update_id=503,message_id=53,text=str(volume))
        journal=direct.rows(work,env,'SOURCE');marker=direct.rows(work,env,'ACCOUNT')
        native_user=a.accounts['fixture-user'] if provider=='marzban' else server.user
        writes=a.put_count if provider=='marzban' else server.write_count
        actual=dict(state=journal[0]['state'] if journal else None,
            review_reason=journal[0].get('review_reason') if journal else None,
            writes=writes,native_expire=native_user.get('expire','@missing'),
            native_bytes=native_user['data_limit'],native_status=native_user['status'],
            local_expire=direct.q(work,env,'SELECT expire_date FROM orders_list WHERE id=91'),
            notif=direct.q(work,env,'SELECT notif FROM orders_list WHERE id=91'),
            trace=a.events if provider=='marzban' else server.trace,events=result['events'],
            wire_payloads=list(a.put_payloads) if provider=='marzban' else [],
            adapter_results=[json.loads(line) for line in adapter_log.read_text().splitlines()] if adapter_log.exists() else [])
        expected_state='completed' if success else 'review'
        ok=(actual['state']==expected_state and marker[0]['state']==expected_state and
            writes==writes_expected and actual['local_expire']==str(local_expire) and actual['notif']=='1' and
            direct.q(work,env,'SELECT userid,wallet FROM users ORDER BY id;SELECT * FROM pays ORDER BY id')==before_money)
        if success:
            ok=(ok and type(actual['native_expire']) is int and actual['native_expire']==0 and
                actual['native_bytes']==3221225472 and actual['native_status']=='active' and
                journal[0].get('applied_expire')==0 and any('✅' in event for event in result['events']) and
                actual['wire_payloads']==[{'data_limit':3221225472}] and
                len(actual['adapter_results'])==1 and
                actual['adapter_results'][0].get('success') is True and
                actual['adapter_results'][0].get('verified') is True and
                type(actual['adapter_results'][0].get('before_expire')) is int and
                actual['adapter_results'][0]['before_expire']==0 and
                type(actual['adapter_results'][0].get('applied_expire')) is int and
                actual['adapter_results'][0]['applied_expire']==0)
        else:ok=ok and not any('✅' in event for event in result['events'])
        observations.append(dict(scenario=name,passed=ok,actual=actual))
        if ok:checks.append(name)
        replay=call(work,env,stage,kind='message',update_id=503,message_id=53,text=str(volume),stale_step=old_step)
        replay_writes=a.put_count if provider=='marzban' else server.write_count
        replay_ok=(replay_writes==writes and len(direct.rows(work,env,'SOURCE'))==1 and
            not any('✅' in event for event in replay['events']))
        observations.append(dict(scenario=name+'_authenticated_replay_no_second_mutation',passed=replay_ok,
            actual=dict(writes=replay_writes,events=replay['events'])))
        if replay_ok:checks.append(name+'_authenticated_replay_no_second_mutation')
        if not success:
            wizard(work,env,stage,days,volume,update=600,message=60)
            hold_writes=a.put_count if provider=='marzban' else server.write_count
            hold_ok=hold_writes==writes and len(direct.rows(work,env,'SOURCE'))==1
            observations.append(dict(scenario=name+'_fresh_source_hold',passed=hold_ok,actual=dict(writes=hold_writes)))
            if hold_ok:checks.append(name+'_fresh_source_hold')


    direct.reset(work,env)
    direct.q(work,env,"UPDATE server_config SET panel_url='"+url_a+"' WHERE id=2")
    a.accounts={'fixture-user':physical.remote_user('fixture-user')}
    a.accounts['fixture-user']['expire']=0
    a.events=[];a.put_count=0;a.put_payloads=[];a.hook=None;a.hook_at=None
    a.fail_readback=False;a.lost_put=False
    adapter_log=stage/'native-adapter-results.jsonl';adapter_log.unlink(missing_ok=True)
    result=call(work,env,stage,route='core_probe',kind='message',update_id=700,message_id=70,text='probe')
    verdict=result['events'][0]['core_probe']
    native_result=json.loads(adapter_log.read_text().splitlines()[0])
    no_gift_ok=(verdict.get('success') is False and verdict.get('msg')=='service_edit_unverified_outcome' and
        native_result.get('success') is True and native_result.get('verified') is True and
        type(native_result.get('applied_expire')) is int and native_result['applied_expire']==0 and
        a.put_count==1 and a.put_payloads==[{'data_limit':3221225472}] and not direct.rows(work,env,'SOURCE'))
    name='non_gift_native_zero_expiry_result_guard_preserved'
    observations.append(dict(scenario=name,passed=no_gift_ok,actual=dict(result=verdict,
        adapter_result=native_result,wire_payloads=a.put_payloads,writes=a.put_count)))
    if no_gift_ok:checks.append(name)


def main():
    p=argparse.ArgumentParser();p.add_argument('--run-disposable',action='store_true',required=True)
    p.add_argument('--report',type=Path);args=p.parse_args()
    checks=[];observations=[]
    with owned.owned_database() as (work,env),traced_panels() as (a,b,url_a,url_b),pg.panel() as (server,url_pg):
        stage=direct.setup(work,env);native_driver(stage)
        for provider in ['marzban','pasarguard']:
            if provider=='pasarguard':migration_setup(work,env)
            cases=[
                ('combined',2,1024,0),('days_only',2,0,0),('volume_only',0,1024,0),
                ('volume_stale_local_future',0,1024,100),
                ('volume_stale_local_past',0,1024,-100),
                ('days_stale_local_future',2,0,2000000),
                ('combined_stale_local_future',2,1024,2000000)]
            negative=(['credentials_rotated','endpoint_rotated','native_expiry_drift','readback_lost','next_plan']
                if provider=='marzban' else ['native_identity_drift','readback_unverified','write_lost','scheduled_reset'])
            negative+=['unlimited_time','unlimited_volume']
            cases += [(name,2,1024,0) for name in negative]
            for name,days,volume,local_delta in cases:
                direct.reset(work,env)
                local_expire=direct.OLD_EXPIRY+local_delta
                direct.q(work,env,'UPDATE orders_list SET expire_date='+str(local_expire)+',notif=1 WHERE id=91')
                if provider=='marzban':
                    direct.q(work,env,"UPDATE server_config SET panel_url='"+url_a+"' WHERE id=2")
                    a.events.clear();a.errors.clear();a.put_count=0
                    a.accounts={'fixture-user':physical.remote_user('fixture-user')}
                    a.hook=None;a.hook_at=None;a.lost_put=False;a.fail_readback=False;a.list_mode='complete'
                else:
                    direct.q(work,env,'DELETE FROM service_effects')
                    direct.q(work,env,storage.connection(2,1,url_pg))
                    server.user=copy.deepcopy(pg.USER);server.user['username']='fixture-user'
                    server.user['expire']=direct.OLD_EXPIRY
                    server.trace=[];server.write_count=0;server.mode='ok'
                    direct.q(work,env,storage.identity(1,91,1,url_pg,17,
                        '2026-10-26T05:00:00.123456Z','fixture-user',pg.MARKER,
                        dict(data_limit=2147483648,expire=direct.OLD_EXPIRY,group_ids=[2,5],status='active')))
                if name in negative:
                    if provider=='marzban':
                        if name=='credentials_rotated':
                            a.hook_at='after_login'
                            a.hook=lambda:direct.q(work,env,"UPDATE server_config SET password='fixture-rotated-password' WHERE id=2")
                        elif name=='endpoint_rotated':
                            a.hook_at='after_user_get'
                            a.hook=lambda:direct.q(work,env,"UPDATE server_config SET panel_url='"+url_b+"' WHERE id=2")
                        elif name=='native_expiry_drift':
                            a.hook_at='after_user_get'
                            a.hook=lambda:a.accounts['fixture-user'].update(expire=direct.OLD_EXPIRY+100)
                        elif name=='readback_lost':a.fail_readback=True
                        elif name=='next_plan':a.accounts['fixture-user']['next_plan']={'data_limit':1073741824}
                        elif name=='unlimited_time':a.accounts['fixture-user']['expire']=None
                        elif name=='unlimited_volume':a.accounts['fixture-user']['data_limit']=None
                    else:
                        server.mode={'native_identity_drift':'foreign_created',
                            'readback_unverified':'modify_lie'}.get(name,name)
                        if name=='unlimited_time':server.user['expire']=None;server.mode='ok'
                        elif name=='unlimited_volume':server.user['data_limit']=None;server.mode='ok'
                before_money=direct.q(work,env,'SELECT userid,wallet FROM users ORDER BY id;SELECT * FROM pays ORDER BY id')
                result=wizard(work,env,stage,days,volume)
                journals=direct.rows(work,env,'SOURCE');markers=direct.rows(work,env,'ACCOUNT')
                native_user=a.accounts['fixture-user'] if provider=='marzban' else server.user
                writes=a.put_count if provider=='marzban' else server.write_count
                expected_expire=direct.OLD_EXPIRY+days*86400
                expected_local=expected_expire if days else local_expire
                expected_bytes=2147483648+(volume//1024)*1073741824
                actual=dict(state=journals[0]['state'] if journals else None,
                    review_reason=journals[0].get('review_reason') if journals else None,
                    writes=writes,native_expire=native_user['expire'],native_bytes=native_user['data_limit'],
                    local_expire=direct.q(work,env,'SELECT expire_date FROM orders_list WHERE id=91'),
                    notif=direct.q(work,env,'SELECT notif FROM orders_list WHERE id=91'),
                    trace=a.events if provider=='marzban' else server.trace,
                    events=result['events'])
                # Native PG encodes expiry as an ISO timestamp on writes.
                native_expire=(int(datetime.fromisoformat(native_user['expire'].replace('Z','+00:00')).timestamp())
                    if isinstance(native_user['expire'],str) else native_user['expire'])
                ok=(actual['state']=='completed' and writes==1 and native_expire==expected_expire and
                    native_user['data_limit']==expected_bytes and actual['local_expire']==str(expected_local) and
                    markers[0]['state']=='completed' and direct.q(work,env,
                    'SELECT userid,wallet FROM users ORDER BY id;SELECT * FROM pays ORDER BY id')==before_money and
                    ('fixture-password' not in json.dumps(journals)) and
                    ('fixture-private-key' not in json.dumps(journals)))
                if days:ok=ok and journals[0].get('before_expire')==direct.OLD_EXPIRY
                else:ok=ok and actual['notif']=='1'
                if name in negative:
                    expected_writes=1 if name in ['readback_lost','readback_unverified','write_lost'] else 0
                    ok=(actual['state']=='review' and markers[0]['state']=='review' and
                        writes==expected_writes and actual['local_expire']==str(local_expire) and
                        not any('✅' in event for event in result['events']) and
                        direct.q(work,env,'SELECT userid,wallet FROM users ORDER BY id;SELECT * FROM pays ORDER BY id')==before_money)
                scenario=provider+'_'+name
                observations.append(dict(scenario=scenario,passed=ok,actual=actual))
                if ok:checks.append(scenario)
                # An authenticated replay must never dispatch a second PUT.
                replay=call(work,env,stage,kind='message',update_id=503,message_id=53,text=str(volume))
                current_writes=a.put_count if provider=='marzban' else server.write_count
                replay_ok=current_writes==writes
                observations.append(dict(scenario=scenario+'_replay',passed=replay_ok,actual=dict(writes=current_writes)))
                if replay_ok:checks.append(scenario+'_replay')
                if name in negative:
                    if name=='endpoint_rotated':
                        # Retry the original physical account, whose hold owns
                        # the old endpoint scope; a new endpoint is not that account.
                        direct.q(work,env,"UPDATE server_config SET panel_url='"+url_a+"' WHERE id=2")
                    wizard(work,env,stage,days,volume,update=600,message=60)
                    hold_writes=a.put_count if provider=='marzban' else server.write_count
                    hold_ok=hold_writes==writes and len(direct.rows(work,env,'SOURCE'))==1
                    observations.append(dict(scenario=scenario+'_new_source_hold',passed=hold_ok,actual=dict(writes=hold_writes)))
                    if hold_ok:checks.append(scenario+'_new_source_hold')
        zero_expiry_cases(work,env,stage,a,url_a,server,url_pg,checks,observations)
    report=dict(complete=len(checks)==len(observations),cases=len(checks),scenarios=checks,
        observations=observations,actual_bot_route=True,actual_native_adapters=True,
        owned_mariadb_only=True,loopback_only=True,real_provider_requests=0,production_operations=0,
        zero_expiry_quota_only_native_verified=all(name in checks for name in [
            'marzban_zero_expiry_quota_active','marzban_zero_expiry_quota_limited']),
        positive_day_zero_expiry_rejected='marzban_positive_day_zero_readback_held' in checks,
        zero_expiry_authenticated_replay_no_second_mutation=all(name in checks for name in [
            'marzban_zero_expiry_quota_active_authenticated_replay_no_second_mutation',
            'marzban_zero_expiry_quota_limited_authenticated_replay_no_second_mutation']),
        pasarguard_zero_expiry_semantics_preserved='pasarguard_zero_expiry_quota_held' in checks)
    if args.report:args.report.write_text(json.dumps(report,ensure_ascii=False,indent=2)+'\n')
    print(json.dumps(dict(complete=report['complete'],cases=len(checks),
        failed=[x for x in observations if not x['passed']]),ensure_ascii=False,indent=2))
    if not report['complete']:raise SystemExit(1)


if __name__=='__main__':main()
