#!/usr/bin/env python3
"""Actual direct bot route + native adapters on owned loopback/socket fixtures.

No installed tenant, real provider, Telegram or financial operation is used.
The existing direct-gift suite supplies the unmodified route extraction and
wizard harness; its synthetic Marzban edit is replaced with the real facade.
"""
from __future__ import annotations
import argparse
import copy
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


def native_driver(stage):
    php=direct.PHP
    start=php.index('function editMarzbanConfig(')
    end=php.index("require_once __DIR__.'/src/Purchase/Core.php';",start)
    config_start=physical.CONFIG.index("require_once __DIR__.'/src/Panel/Accounts.php';")
    config_end=physical.CONFIG.index('function sendMessage(',config_start)
    php=php[:start]+physical.CONFIG[config_start:config_end]+php[end:]
    php=php.replace("if (PHP_SAPI !== 'cli') exit(3);", "if (PHP_SAPI !== 'cli') exit(3);\ndefine('ACHBOT_PG_TEST_LOOPBACK',true);")
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


def main():
    p=argparse.ArgumentParser();p.add_argument('--run-disposable',action='store_true',required=True)
    p.add_argument('--report',type=Path);args=p.parse_args()
    checks=[];observations=[]
    with owned.owned_database() as (work,env),physical.panels() as (a,b,url_a,url_b),pg.panel() as (server,url_pg):
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
    report=dict(complete=len(checks)==len(observations),cases=len(checks),scenarios=checks,
        observations=observations,actual_bot_route=True,actual_native_adapters=True,
        owned_mariadb_only=True,loopback_only=True,real_provider_requests=0,production_operations=0)
    if args.report:args.report.write_text(json.dumps(report,ensure_ascii=False,indent=2)+'\n')
    print(json.dumps(dict(complete=report['complete'],cases=len(checks),
        failed=[x for x in observations if not x['passed']]),ensure_ascii=False,indent=2))
    if not report['complete']:raise SystemExit(1)


if __name__=='__main__':main()
