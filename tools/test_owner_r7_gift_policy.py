#!/usr/bin/env python3
"""Owner gift policy through actual bot/Core/native adapters and bulk worker.

All SQL, provider HTTP and Telegram transports belong to disposable fixtures.
The original source is exercised separately as an explicit RED comparison.
"""
from __future__ import annotations
import argparse,copy,hashlib,json,subprocess
from datetime import datetime
from pathlib import Path

import notification_fixture
import provider_storage_fixture as storage
import test_access_state as owned
import test_post_v9_direct_gift as direct
import test_post_v9_direct_gift_native as native
import test_post_v9_gift_physical as physical
import test_post_v9_gift_worker as bulk
import test_post_v9_pasarguard_client as pg

ROOT=Path(__file__).resolve().parents[1]
BASE='a0785dca3e46f031ca9a0a4500a71bf3ea11ee8e'
CREATED='2026-10-26T05:00:00.123456Z'


def source_stage(stage,baseline=False):
    if baseline:
        for path in ['src/Subscription/GiftBatch.php','src/Subscription/DirectGift.php']:
            (stage/path).write_bytes(subprocess.check_output(['git','show',BASE+':'+path],cwd=ROOT))
        bot=subprocess.check_output(['git','show',BASE+':bot.php'],cwd=ROOT,text=True)
        a=bot.index('// ACHBOT_DIRECT_GIFT_ROUTE_BEGIN')
        b=bot.index('// ACHBOT_DIRECT_GIFT_ROUTE_END',a)+len('// ACHBOT_DIRECT_GIFT_ROUTE_END')
        (stage/'gift-route.php').write_text('<?php\n'+bot[a:b])
    else:
        for path in ['src/Subscription/GiftBatch.php','src/Subscription/DirectGift.php']:
            (stage/path).write_bytes((ROOT/path).read_bytes())
        bot=(ROOT/'bot.php').read_text()
        a=bot.index('// ACHBOT_DIRECT_GIFT_ROUTE_BEGIN')
        b=bot.index('// ACHBOT_DIRECT_GIFT_ROUTE_END',a)+len('// ACHBOT_DIRECT_GIFT_ROUTE_END')
        (stage/'gift-route.php').write_text('<?php\n'+bot[a:b])
    native.native_driver(stage)
    php=(stage/'driver.php').read_text()
    php=php.replace("$botState=['sellState'=>'on'];",
        "$botState=['sellState'=>'on','rewardChannel'=>'@fixture-gift-channel'];\nrequire_once __DIR__.'/notifications-under-test.php';")
    php=php.replace("function bot($method,$args=[]) {", """function bot($method,$args=[]) {
    if ($method==='sendMessage') {
        $GLOBALS['admin_notices'][]=$args;
        return (object)['ok'=>!(($GLOBALS['spec']['mode']??'')==='report_fail' && ($args['chat_id']??null)===9101)];
    }""")
    php=php.replace("    $GLOBALS['events'][]=(string)$body;\n    $GLOBALS['customer_notices'][]=", """    if (($GLOBALS['spec']['mode']??'')==='customer_fail' && $recipient===1002)
        throw new RuntimeException('synthetic_customer_ack_lost');
    $GLOBALS['events'][]=(string)$body;
    $GLOBALS['customer_notices'][]=""")
    php=php.replace("    if (($GLOBALS['spec']['mode']??'')==='notice_fail' &&",
        "    if (($GLOBALS['spec']['mode']??'')==='report_fail' && $recipient===9101) return (object)['ok'=>false];\n    if (($GLOBALS['spec']['mode']??'')==='notice_fail' &&")
    php=php.replace("'customer_notices'=>$GLOBALS['customer_notices']??[]]",
        "'customer_notices'=>$GLOBALS['customer_notices']??[],'admin_notices'=>$GLOBALS['admin_notices']??[]]")
    # Exercise the actual repeat-finalization guard without another provider call.
    php=php.replace("} elseif (($spec['route']??'gift')==='moderation') {", """} elseif (($spec['route']??'gift')==='repeat_finish') {
    require_once __DIR__.'/src/Subscription/DirectGift.php';
    $source=$connection->query("SELECT id,type,value FROM setting WHERE type LIKE 'ACHBOT_DIRECT_GIFT_SOURCE_%' LIMIT 1")->fetch_assoc();
    $record=json_decode($source['value'],true);
    $claim=['id'=>(int)$source['id'],'type'=>$source['type'],'order'=>$record['order'],
        'target'=>achbotGiftPhysicalTarget($connection,$record['order']['server_id']),
        'days'=>$record['days'],'volume_mb'=>$record['volume_mb'],
        'native_identity_sha256'=>$record['native_identity_sha256']];
    $marker=$connection->query("SELECT type FROM setting WHERE type LIKE 'ACHBOT_DIRECT_GIFT_ACCOUNT_%' LIMIT 1")->fetch_assoc();
    $claim['marker_type']=$marker['type'];
    $claim['references']=achbotGiftReferences($connection,$record['order']['server_id'],$record['order']['remark']);
    $claim['target']=achbotGiftPinNativeIdentity($connection,$claim['references'],$claim['target']);
    try {achbotDirectGiftComplete($connection,$claim,(object)['success'=>true,'verified'=>true,
        'uncertain'=>false,'before_expire'=>$record['before_expire']??1800000100,
        'applied_expire'=>$record['applied_expire'],'applied_bytes'=>$record['applied_bytes']]);
        $GLOBALS['events'][]='REPEAT_ACCEPTED';}
    catch (Throwable $error) {$GLOBALS['events'][]='REPEAT_DENIED';}
} elseif (($spec['route']??'gift')==='moderation') {""")
    (stage/'driver.php').write_text(php)
    notification_fixture.stage_router(stage)


def origin(work,env,oid,uid,provider):
    identity={'remote_created_at':CREATED,'remote_username':'fixture-user'}
    if provider=='pasarguard':identity['remote_user_id']=17
    record=dict(version=1,kind='external_import',order_id=oid,owner_userid=uid,
        actor_userid=9001,provider=provider,server_id=2,remark='fixture-user',
        proof_sha256='f'*64,native_identity=identity,capacity_reserved=False,
        provider_account_id=1 if provider=='pasarguard' else None)
    direct.q(work,env,'INSERT INTO setting(type,value) VALUES('+
        storage.text('ACHBOT_SERVICE_ORIGIN:'+str(oid))+','+storage.text(json.dumps(record))+')')


def attach(work,env,oid,uid,provider,imported=False,status=1,uuid=None):
    if direct.q(work,env,'SELECT COUNT(*) FROM users WHERE userid='+str(uid))=='0':
        direct.q(work,env,"INSERT INTO users(userid,name,username,refcode,wallet,date) VALUES("+
            str(uid)+",'Fixture','fixture-shared','0',0,'1700000000')")
    direct.q(work,env,'INSERT INTO orders_list(id,userid,token,transid,fileid,server_id,inbound_id,remark,uuid,expire_date,link,amount,status,date,notif,autoRenew) '+
        'SELECT '+str(oid)+','+str(uid)+",token,transid,"+('0' if imported else 'fileid')+
        ',server_id,inbound_id,remark,'+('uuid' if uuid is None else storage.text(uuid))+
        ',expire_date,link,amount,'+str(status)+',date,-1,autoRenew FROM orders_list WHERE id=91')
    if provider=='pasarguard':
        direct.q(work,env,'UPDATE orders_list SET provider_effect_id=1,provider_remote_user_id=17,provider_remote_created_at='+
            storage.text(CREATED)+' WHERE id='+str(oid))
        # A second local reference reuses the genuine originating account anchor.
        origin(work,env,oid,uid,provider)
    elif imported:origin(work,env,oid,uid,provider)


def main():
    p=argparse.ArgumentParser();p.add_argument('--run-disposable',required=True,action='store_true')
    p.add_argument('--report',type=Path);args=p.parse_args()
    runtime_paths=['bot.php','src/Subscription/GiftBatch.php','src/Subscription/DirectGift.php',
        'settings/gift2all.php','src/Panel/Accounts.php']
    start_hashes={path:hashlib.sha256((ROOT/path).read_bytes()).hexdigest() for path in runtime_paths}
    start_head=subprocess.check_output(['git','rev-parse','HEAD'],cwd=ROOT,text=True).strip()
    start_clean=not subprocess.check_output(['git','status','--porcelain'],cwd=ROOT,text=True).strip()
    observations=[]
    def check(name,ok,actual):
        observations.append(dict(scenario=name,passed=bool(ok),actual=actual))
        if args.report:args.report.write_text(json.dumps(dict(complete=False,cases=len(observations),observations=observations),ensure_ascii=False,indent=2)+'\n')
        if not ok:raise RuntimeError(name+': '+json.dumps(actual,ensure_ascii=False))
    with owned.owned_database() as (work,env),native.traced_panels() as (a,b,url_a,url_b),pg.panel() as (server,url_pg):
        stage=direct.setup(work,env);native.migration_setup(work,env)
        original_uuid=direct.q(work,env,'SELECT uuid FROM orders_list WHERE id=91')
        bulk.seed_notification_staff(work,env)

        def reset(provider):
            direct.q(work,env,'DELETE FROM orders_list WHERE id BETWEEN 92 AND 2500')
            direct.q(work,env,"DELETE FROM setting WHERE type LIKE 'ACHBOT_SERVICE_ORIGIN:%'")
            direct.q(work,env,'DELETE FROM service_effects')
            direct.reset(work,env)
            direct.q(work,env,'UPDATE orders_list SET fileid=7,notif=-1,uuid='+storage.text(original_uuid)+' WHERE id=91')
            if provider=='marzban':
                direct.q(work,env,"UPDATE server_config SET panel_url="+storage.text(url_a)+",username='fixture-admin',password='fixture-password',type='marzban' WHERE id=2")
                a.accounts={'fixture-user':physical.remote_user('fixture-user')}
                a.accounts['fixture-user']['created_at']=CREATED
                a.events=[];a.errors=[];a.put_count=0;a.put_payloads=[]
                a.hook=None;a.hook_at=None;a.lost_put=False;a.fail_readback=False;a.list_mode='complete'
            else:
                direct.q(work,env,storage.connection(2,1,url_pg))
                server.user=copy.deepcopy(pg.USER);server.user.update(username='fixture-user',expire=direct.OLD_EXPIRY)
                server.trace=[];server.write_count=0;server.mode='ok'
                direct.q(work,env,storage.identity(1,91,1,url_pg,17,CREATED,'fixture-user',pg.MARKER,
                    dict(data_limit=2147483648,expire=direct.OLD_EXPIRY,group_ids=[2,5],status='active')))
            stage.joinpath('effects.jsonl').unlink(missing_ok=True)

        def writes(provider):return a.put_count if provider=='marzban' else server.write_count
        def details(provider,result):
            journals=direct.rows(work,env,'SOURCE')
            return dict(writes=writes(provider),journal=journals[0] if journals else None,
                recipients=[n['recipient'] for n in result.get('customer_notices',[]) if isinstance(n.get('recipient'),int) and 1001<=n['recipient']<=1102],
                reports=[n['recipient'] for n in result.get('customer_notices',[]) if n.get('recipient') in [9001,9101,'@fixture-gift-channel']],
                native=a.accounts['fixture-user'] if provider=='marzban' else server.user,
                fields=direct.q(work,env,'SELECT id,userid,fileid,expire_date,amount,notif FROM orders_list ORDER BY id'))

        for provider in ['marzban','pasarguard']:
            for label in ['shared','planless']:
                reset(provider);source_stage(stage,baseline=True)
                if label=='shared':attach(work,env,92,1002,provider)
                else:
                    direct.q(work,env,'UPDATE orders_list SET fileid=0 WHERE id=91')
                    origin(work,env,91,1001,provider)
                r=native.wizard(work,env,stage,2,1024)
                actual=details(provider,r)
                check('baseline_'+provider+'_'+label+'_RED',writes(provider)==0 and not actual['recipients'],actual)
        source_stage(stage)

        for provider in ['marzban','pasarguard']:
            for label in ['shared','same_user_dedup','planless','planless_shared','archived_reference',
                          'large_cohort','customer_fail','report_fail','quota_only']:
                reset(provider)
                imported=label.startswith('planless')
                if imported:
                    direct.q(work,env,'UPDATE orders_list SET fileid=0 WHERE id=91')
                    origin(work,env,91,1001,provider)
                if label!='planless':attach(work,env,92,1002,provider,imported=imported)
                expected=[1001] if label=='planless' else [1001,1002]
                if label=='same_user_dedup':attach(work,env,93,1002,provider)
                if label=='archived_reference':attach(work,env,93,1003,provider,status=0,uuid='archived-foreign-uuid')
                if label=='large_cohort':
                    for i in range(3,103):attach(work,env,90+i,1000+i,provider)
                    expected=list(range(1001,1103))
                before=direct.q(work,env,'SELECT id,userid,fileid,expire_date,amount FROM orders_list ORDER BY id')
                money=direct.q(work,env,'SELECT userid,wallet FROM users ORDER BY id; SELECT * FROM pays ORDER BY id')
                days=0 if label=='quota_only' else 2
                # Carry a deliberately different local date; native observation remains authoritative.
                direct.q(work,env,'UPDATE orders_list SET expire_date=expire_date+100 WHERE status=1')
                before=direct.q(work,env,'SELECT id,userid,fileid,expire_date,amount FROM orders_list ORDER BY id')
                for spec in [dict(kind='callback',update_id=500,message_id=50,text='giftToUser2'),
                             dict(kind='message',update_id=501,message_id=51,text='fixture-user'),
                             dict(kind='message',update_id=502,message_id=52,text=str(days))]:
                    native.call(work,env,stage,**spec)
                old_step=direct.q(work,env,'SELECT step FROM users WHERE userid=9001')
                r=native.call(work,env,stage,kind='message',update_id=503,message_id=53,text='1024',
                    mode=label if label in ['customer_fail','report_fail'] else 'verified')
                actual=details(provider,r);journal=actual['journal'];notices=journal.get('customer_notices',{}) if journal else {}
                native_user=actual['native'];expire=native_user['expire']
                if isinstance(expire,str):expire=int(datetime.fromisoformat(expire.replace('Z','+00:00')).timestamp())
                fields_unchanged=direct.q(work,env,'SELECT id,userid,fileid,expire_date,amount FROM orders_list ORDER BY id')==before
                unconfirmed=[1002] if label=='customer_fail' else []
                acknowledged=[uid for uid in expected if uid not in unconfirmed]
                refs=[91] if label=='planless' else list(range(91,193)) if label=='large_cohort' else [91,92,93] if label=='same_user_dedup' else [91,92]
                ok=(journal and journal['state']=='completed' and writes(provider)==1 and fields_unchanged and
                    expire==direct.OLD_EXPIRY+days*86400 and native_user['data_limit']==3221225472 and
                    sorted(actual['recipients'])==acknowledged and journal['reference_ids']==refs and
                    journal['recipient_userids']==expected and sorted(notices.get('acknowledged',[]))==acknowledged and
                    notices.get('unconfirmed')==unconfirmed and notices.get('send_exceptions')==unconfirmed and
                    notices.get('report_acknowledged')==(label!='report_fail') and
                    direct.q(work,env,'SELECT userid,wallet FROM users ORDER BY id; SELECT * FROM pays ORDER BY id')==money and
                    direct.q(work,env,'SELECT COUNT(*) FROM orders_list WHERE status=1 AND notif<>0')==('0' if days else str(len(refs))))
                # General success goes Owner/receiving-on Manager/channel only.
                ok=ok and sorted(map(str,actual['reports']))==sorted(map(str,[9001,9101,'@fixture-gift-channel']))
                check(provider+'_'+label+'_one_native_gift_preserves_purchase',ok,actual)
                native.call(work,env,stage,kind='message',update_id=503,message_id=53,text='1024',stale_step=old_step)
                check(provider+'_'+label+'_authenticated_replay_no_second_native',writes(provider)==1,dict(writes=writes(provider)))
                repeat=native.call(work,env,stage,route='repeat_finish',kind='message',text='probe')
                check(provider+'_'+label+'_repeat_sql_finish_cannot_reopen',
                    writes(provider)==1 and repeat['events']==['REPEAT_DENIED'] and direct.rows(work,env,'SOURCE')[0]['state']=='completed',
                    dict(writes=writes(provider),events=repeat['events']))

            for label in ['unproven_planless','foreign_current_reference','native_incarnation_changed','noncanonical_userid']:
                reset(provider)
                if label=='unproven_planless':direct.q(work,env,'UPDATE orders_list SET fileid=0 WHERE id=91')
                elif label=='noncanonical_userid':
                    attach(work,env,92,1002,provider)
                    direct.q(work,env,'ALTER TABLE orders_list MODIFY userid VARCHAR(32) NOT NULL')
                    direct.q(work,env,"UPDATE orders_list SET userid='1002abc' WHERE id=92")
                elif label=='foreign_current_reference':
                    attach(work,env,92,1002,provider,uuid='different-cached-proxy')
                    if provider=='marzban':
                        origin(work,env,91,1001,provider);origin(work,env,92,1002,provider)
                        direct.q(work,env,"UPDATE setting SET value=REPLACE(value,"+storage.text(CREATED)+","+
                            storage.text('2026-10-25T05:00:00.123456Z')+") WHERE type='ACHBOT_SERVICE_ORIGIN:92'")
                    if provider=='pasarguard':
                        direct.q(work,env,'UPDATE orders_list SET provider_remote_user_id=18 WHERE id=92')
                else:
                    direct.q(work,env,'UPDATE orders_list SET fileid=0 WHERE id=91');origin(work,env,91,1001,provider)
                    if provider=='marzban':a.accounts['fixture-user']['created_at']='2026-10-25T05:00:00.123456Z'
                    else:server.mode='foreign_created'
                r=native.wizard(work,env,stage,2,1024)
                check(provider+'_'+label+'_no_native_write',
                    writes(provider)==0 and not [n for n in r.get('customer_notices',[]) if isinstance(n.get('recipient'),int) and 1001<=n['recipient']<=1102],
                    details(provider,r))

        # Actual GET -> pre-effect GET and write -> readback incarnation changes.
        # The previous getter can mutate a recreated same-name user; the pin guards each read.
        for label in ['getter_baseline_race_RED','before_effect_incarnation_race','readback_incarnation_race','late_attachment']:
            reset('marzban')
            direct.q(work,env,'UPDATE orders_list SET fileid=0 WHERE id=91')
            origin(work,env,91,1001,'marzban')
            attach(work,env,92,1002,'marzban',imported=True,uuid='stale-but-valid-proxy-cache')
            old_accounts=(stage/'src/Panel/Accounts.php').read_bytes()
            if label=='getter_baseline_race_RED':
                (stage/'src/Panel/Accounts.php').write_bytes(subprocess.check_output(['git','show',BASE+':src/Panel/Accounts.php'],cwd=ROOT))
            a.hook_at='after_put' if label=='readback_incarnation_race' else 'after_user_get'
            if label=='late_attachment':
                a.hook=lambda:attach(work,env,93,1003,'marzban',imported=True)
            else:
                a.hook=lambda:a.accounts['fixture-user'].update(created_at='2026-10-25T05:00:00.123456Z')
            result=native.wizard(work,env,stage,2,1024)
            actual=details('marzban',result)
            expected_writes=0 if label=='before_effect_incarnation_race' else 1
            if label in ['getter_baseline_race_RED','late_attachment']:
                ok=actual['journal']['state']=='completed' and actual['recipients']==[1001,1002]
            else:
                ok=actual['journal']['state']=='review' and not actual['recipients']
            check('marzban_'+label,writes('marzban')==expected_writes and ok,actual)
            if label!='getter_baseline_race_RED':
                native.call(work,env,stage,kind='message',update_id=503,message_id=53,text='1024')
                check('marzban_'+label+'_no_replay',writes('marzban')==expected_writes,dict(writes=writes('marzban')))
            (stage/'src/Panel/Accounts.php').write_bytes(old_accounts)

        # Actual bulk worker uses the same frozen references, commit and customer helper.
        stage_bulk=physical.prepare(work);log=work/'owner-policy-bulk-events.jsonl'
        config=(stage_bulk/'config.php').read_text()
        config=config.replace("return $type==='marzban';","return achbotSupportsProviderType($type);")
        (stage_bulk/'config.php').write_text(config.replace('<?php',"<?php\ndefine('ACHBOT_PG_TEST_LOOPBACK',true);",1))
        for provider in ['marzban','pasarguard']:
            reset(provider)
            attach(work,env,92,1002,provider)
            attach(work,env,93,1002,provider)
            direct.q(work,env,"DELETE FROM setting WHERE type LIKE 'ACHBOT_GIFT_%'; DELETE FROM gift_list")
            out=physical.php(work,env,stage_bulk,'enqueue.php','verified',log,['1024','2'])
            gid=int(out.strip())
            physical.php(work,env,stage_bulk,'run.php','verified',log)
            records=direct.q(work,env,"SELECT value FROM setting WHERE type LIKE 'ACHBOT_GIFT_RESULT_"+str(gid)+"_%'")
            if not records:
                raise RuntimeError(provider+'_bulk_result_missing: '+direct.q(work,env,
                    "SELECT id,server_id,volume,day,offset FROM gift_list; SELECT type,value FROM setting WHERE type LIKE 'ACHBOT_GIFT_HOLD_%'")+
                    ' events='+log.read_text()[-3000:])
            record=json.loads(records)
            entries=[json.loads(x) for x in log.read_text().splitlines()]
            recipients=[x['recipient'] for x in entries if x['kind']=='MESSAGE' and x.get('recipient') in [1001,1002]]
            check(provider+'_bulk_shared_once_dedup_native_journal',
                writes(provider)==1 and sorted(recipients)==[1001,1002] and record['state']=='completed' and
                record['reference_ids']==[91,92,93] and record['recipient_userids']==[1001,1002] and
                direct.q(work,env,'SELECT COUNT(*) FROM orders_list WHERE expire_date<>'+str(direct.OLD_EXPIRY))=='0' and
                direct.q(work,env,'SELECT COUNT(*) FROM orders_list WHERE notif<>0')=='0',
                dict(writes=writes(provider),recipients=recipients,record=record))
            physical.php(work,env,stage_bulk,'run.php','verified',log)
            physical.php(work,env,stage_bulk,'run.php','verified',log)
            check(provider+'_bulk_completion_and_replay_no_second_native',
                writes(provider)==1,dict(writes=writes(provider)))


        # PG's initial selected-server membership is unchanged. Additional
        # same-panel references participate only in the admitted service cohort.
        alias_base='a32ec0178b7e1d11a8e8191279b7ef932b93c725'
        current_worker=(stage_bulk/'settings/gift2all.php').read_bytes()

        def alias_setup():
            direct.q(work,env,'DELETE FROM orders_list WHERE id=90')
            reset('pasarguard')
            direct.q(work,env,"INSERT INTO server_info(id,title,ucount,remark,flag,active,state) "+
                "VALUES(4,'Verified alias',5,'alias','','1',1) ON DUPLICATE KEY UPDATE active=1,state=1")
            fields=['type','panel_url','username','password','provider_connection_id',
                'provider_instance_key','provider_version','provider_enabled']
            direct.q(work,env,'UPDATE server_config a JOIN server_config b ON b.id=2 SET '+
                ','.join('a.'+field+'=b.'+field for field in fields)+' WHERE a.id=4')
            attach(work,env,90,1002,'pasarguard')
            direct.q(work,env,'UPDATE orders_list SET server_id=4 WHERE id=90')
            direct.q(work,env,"DELETE FROM setting WHERE type LIKE 'ACHBOT_GIFT_%'; DELETE FROM gift_list")

        def enqueue_alias():
            return int(physical.php(work,env,stage_bulk,'enqueue.php','verified',log,['1024','2']).strip())

        def result_records(gid):
            raw=direct.q(work,env,"SELECT value FROM setting WHERE type LIKE 'ACHBOT_GIFT_RESULT_"+str(gid)+"_%'")
            return [json.loads(line) for line in raw.splitlines() if line]

        alias_setup()
        (stage_bulk/'settings/gift2all.php').write_bytes(subprocess.check_output(
            ['git','show',alias_base+':settings/gift2all.php'],cwd=ROOT))
        gid=enqueue_alias()
        physical.php(work,env,stage_bulk,'run.php','verified',log)
        state=bulk.state(work,env,gid)
        check('baseline_pasarguard_bulk_lower_id_alias_anchor_RED',
            server.write_count==0 and state[0]==-1 and state[2]==1 and not result_records(gid),
            dict(writes=server.write_count,state=state,records=result_records(gid)))
        (stage_bulk/'settings/gift2all.php').write_bytes(current_worker)

        alias_setup()
        gid=enqueue_alias()
        before=direct.q(work,env,'SELECT id,userid,fileid,expire_date,amount FROM orders_list ORDER BY id')
        physical.php(work,env,stage_bulk,'run.php','verified',log)
        records=result_records(gid)
        entries=[json.loads(line) for line in log.read_text().splitlines()]
        recipients=[item['recipient'] for item in entries if item['kind']=='MESSAGE' and item.get('recipient') in [1001,1002]]
        record=records[0] if len(records)==1 else {}
        check('pasarguard_bulk_lower_id_alias_prefers_batchserver_anchor',
            server.write_count==1 and sorted(recipients)==[1001,1002] and record.get('state')=='completed' and
            record.get('reference_ids')==[90,91] and record.get('recipient_userids')==[1001,1002] and
            record.get('dispatch_order_id')==91 and record.get('dispatch_server_id')==2 and
            record.get('server_id')==2 and record.get('batch_target')==record.get('dispatch_target') and
            record.get('applied_bytes')==3221225472 and record.get('applied_expire')==direct.OLD_EXPIRY+2*86400 and
            direct.q(work,env,'SELECT id,userid,fileid,expire_date,amount FROM orders_list ORDER BY id')==before,
            dict(writes=server.write_count,recipients=recipients,records=records))
        physical.php(work,env,stage_bulk,'run.php','verified',log)
        physical.php(work,env,stage_bulk,'run.php','verified',log)
        check('pasarguard_bulk_lower_id_alias_completion_replay_no_second_write',
            server.write_count==1 and bulk.state(work,env,gid)[0] is None,
            dict(writes=server.write_count,state=bulk.state(work,env,gid)))

        alias_setup()
        direct.q(work,env,'UPDATE orders_list SET server_id=4 WHERE id=91')
        gid=enqueue_alias()
        physical.php(work,env,stage_bulk,'run.php','verified',log)
        check('pasarguard_bulk_initial_alias_only_remains_outside_selected_server_roster',
            server.write_count==0 and not result_records(gid) and bulk.state(work,env,gid)[0] is None,
            dict(writes=server.write_count,state=bulk.state(work,env,gid),records=result_records(gid)))

        alias_setup()
        gid=enqueue_alias()
        # Stop the actual worker after durable roster creation but before the
        # first native claim. This fixture trigger never touches any real DB.
        direct.q(work,env,"CREATE TRIGGER owner_alias_freeze_stop BEFORE UPDATE ON gift_list FOR EACH ROW "+
            "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic_stop_before_claim'")
        try:
            physical.php(work,env,stage_bulk,'run.php','verified',log)
        finally:
            direct.q(work,env,'DROP TRIGGER owner_alias_freeze_stop')
        frozen=bulk.manifest(work,env,gid)
        direct.ensure(server.write_count==0 and frozen is not None and frozen['count']==1 and frozen['cursor']==0,
            'Alias-only hold fixture did not freeze original selected-server username before any claim')
        direct.q(work,env,'UPDATE orders_list SET server_id=4 WHERE id=91')
        physical.php(work,env,stage_bulk,'run.php','verified',log)
        state=bulk.state(work,env,gid)
        check('pasarguard_bulk_frozen_member_moved_to_alias_preserves_missing_member_hold',
            server.write_count==0 and state[0]==-1 and state[2]==1 and
            state[3]['username']=='fixture-user' and state[3]['reason']=='snapshot_recipient_missing' and
            not result_records(gid),
            dict(writes=server.write_count,state=state,roster=frozen))
        direct.q(work,env,'UPDATE orders_list SET server_id=2 WHERE id=91')
        physical.php(work,env,stage_bulk,'run.php','verified',log)
        state=bulk.state(work,env,gid)
        check('pasarguard_bulk_frozen_alias_hold_restored_reference_cannot_rearm',
            server.write_count==0 and state[0]==-1 and state[2]==1 and not result_records(gid),
            dict(writes=server.write_count,state=state))

    end_hashes={path:hashlib.sha256((ROOT/path).read_bytes()).hexdigest() for path in runtime_paths}
    end_head=subprocess.check_output(['git','rev-parse','HEAD'],cwd=ROOT,text=True).strip()
    end_clean=not subprocess.check_output(['git','status','--porcelain'],cwd=ROOT,text=True).strip()
    source_unchanged=start_hashes==end_hashes and start_head==end_head
    report=dict(complete=source_unchanged,cases=len(observations),scenarios=[o['scenario'] for o in observations],
        observations=observations,actual_bot_route=True,actual_bulk_worker=True,actual_native_adapters=True,
        actual_notification_router=True,original_source=BASE,alias_regression_source=alias_base,baseline_red_cases=6,real_provider_requests=0,
        owned_mariadb_only=True,loopback_only=True,production_operations=0,
        source_head=start_head,source_head_after=end_head,source_unchanged=source_unchanged,
        source_clean_at_start=start_clean,source_clean_at_end=end_clean,
        runtime_sha256=start_hashes,runtime_sha256_after=end_hashes)
    if args.report:args.report.write_text(json.dumps(report,ensure_ascii=False,indent=2)+'\n')
    print(json.dumps(dict(complete=report['complete'],cases=report['cases'])))
    if not source_unchanged:raise SystemExit('source changed during Owner gift-policy proof')
if __name__=='__main__':main()
