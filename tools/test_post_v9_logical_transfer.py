#!/usr/bin/env python3
"""R4: real PHP panel-group/admin/wizard callbacks on owned MariaDB + fake HTTP.

Frozen V9 schema remains untouched in Git. On disposable MariaDB ONLY, test
the separately shipped one-column additive MariaDB migration and its rerun.
"""
from __future__ import annotations
import argparse
import concurrent.futures
import json
import re
import shutil
import subprocess
import time
from pathlib import Path
import test_access_state as owned
import test_v6_native as native
import test_v8_native as purchase
import test_post_v9_purchases as stages
import test_unified_management_wizard as management

ROOT=Path(__file__).resolve().parents[1]
DB=purchase.SIDE[1]
def sql(w,e,s): return native.sql(w,e,s,DB)

def snapshot(w,e):
    return (purchase.snapshot(w,e,DB),
        sql(w,e,'SELECT id,panel_group_id FROM server_info ORDER BY id'),
        sql(w,e,"SELECT id,value FROM setting WHERE type='ACHBOT_MARZBAN_PANEL_GROUP' ORDER BY id"))

def main():
    ap=argparse.ArgumentParser()
    ap.add_argument('--run-disposable',action='store_true',required=True)
    ap.add_argument('--report',type=Path)
    args=ap.parse_args()
    checks=[]
    def passed(tag): checks.append(tag)
    with owned.owned_database() as (work,env),native.owned_provider() as (panel,url):
        stage=work/'group-r4'
        stages.stage_source(stage,True)
        fixture=stage/'native-purchase.php'
        stub=fixture.read_text()
        assert stub.count("'subLinkState'=>'on',")==1
        stub=stub.replace("'subLinkState'=>'on',",
                          "'subLinkState'=>'on','switchLocationState'=>'on',")
        config=(stage/'config.php').read_text()
        a=config.index('function getMarzbanPanelGroupKeys(')
        b=config.index('function getServerConfigKeys(',a)
        assert stub.count('function getServerListKeys(')==1
        stub=stub.replace('function getServerListKeys(',
            config[a:b]+
            "function getServerConfigKeys($id,$offset=0){return 'fixture-admin-config';}\n"
            "function getServerListKeys(")
        fixture.write_text(stub)
        source=(stage/'bot.php').read_text()
        a=source.index("if ($data === 'manageMarzbanPanelGroups'")
        b=source.index("if(preg_match('/^changesServerLoginInfo",a)
        wizard=b'<?php\n'+source[a:b].encode()
        purchase.init(work,env,url)
        tables=sql(work,env,'SHOW TABLES').splitlines()
        if len(tables)!=23: raise RuntimeError('Original table count drift')
        before=stages.ddl(work,env,DB,tables)
        if sql(work,env,"SHOW COLUMNS FROM server_info LIKE 'panel_group_id'"):
            raise RuntimeError('Historical SQL unexpectedly has the new column')
        baseline=sql(work,env,'SELECT id,title FROM server_info ORDER BY id')
        mig=(ROOT/'migrations/post-v9/001_marzban_panel_group_id.sql').read_text()
        sql(work,env,mig)
        if sql(work,env,'SELECT id,title FROM server_info ORDER BY id')!=baseline:
            raise RuntimeError('Migration modified old values')
        if sql(work,env,'SELECT COUNT(*) FROM server_info WHERE panel_group_id IS NOT NULL').strip()!='0':
            raise RuntimeError('Migration assigned unapproved identity')
        after=stages.ddl(work,env,DB,tables)
        if any(before[i]!=after[i] for i,t in enumerate(tables) if t!='server_info'):
            raise RuntimeError('Migration touched unrelated SQL table')
        sql(work,env,mig)
        if stages.ddl(work,env,DB,tables)!=after:
            raise RuntimeError('MariaDB migration is not idempotent')
        passed('additive_migration_original_rows_intact')
        passed('repeat_migration_unchanged')
        # Execute pinned V9 PHP source on the real, already-migrated fixture DB.
        old=work/'pinned-v9-on-migrated-db'
        stages.stage_source(old,False)
        pre_v9=snapshot(work,env)
        z=purchase.run(work,env,old,DB,'catalogue',
                       {'actor':1001,'data':'selectServer2_none'},modern=True)
        if z['exception'] or z['warnings'] or z['stdout']:
            raise RuntimeError('Pinned V9 source failed on migrated DB: '+repr(z)[:1000])
        post_v9=snapshot(work,env)
        # V9 selectors legitimately record the customer's wizard step/temp.
        # Financial state, provider catalogue, orders and group data cannot move.
        protected=('payments','orders','capacity','plans','categories')
        changed=[k for k in protected if pre_v9[0][k]!=post_v9[0][k]]
        if changed or pre_v9[1:]!=post_v9[1:]:
            raise RuntimeError('Pinned V9 changed '+repr(changed)+
                               '; server/catalog SQL delta '+repr(pre_v9[1:]!=post_v9[1:]))
        sql(work,env,"UPDATE users SET step='none',temp=NULL WHERE userid=1001")
        passed('pinned_v9_native_source_on_additive_column')
        sql(work,env,"""
        DELETE FROM server_config WHERE id IN (3,4,5,6);
        INSERT INTO server_info (id,title,ucount,remark,flag,active,state)
          VALUES (3,'Same physical different API',9,'r3','',1,1),
                 (4,'Different physical',9,'r4','',1,1),
                 (5,'Same URL different group',9,'r5','',1,1),
                 (6,'Retired',9,'r6','',1,1);
        INSERT INTO server_config (id,panel_url,ip,sni,header_type,request_header,
           response_header,security,tlsSettings,type,username,password,port_type,reality,sub_links)
          SELECT 3,CONCAT(panel_url,'/alias'),ip,sni,header_type,request_header,
             response_header,security,tlsSettings,type,'other-admin',password,port_type,reality,sub_links
          FROM server_config WHERE id=2;
        INSERT INTO server_config (id,panel_url,ip,sni,header_type,request_header,
           response_header,security,tlsSettings,type,username,password,port_type,reality,sub_links)
          SELECT 4,CONCAT(panel_url,'/different'),ip,sni,header_type,request_header,
             response_header,security,tlsSettings,type,username,password,port_type,reality,sub_links
          FROM server_config WHERE id=2;
        INSERT INTO server_config (id,panel_url,ip,sni,header_type,request_header,
           response_header,security,tlsSettings,type,username,password,port_type,reality,sub_links)
          SELECT 5,panel_url,ip,sni,header_type,request_header,
             response_header,security,tlsSettings,type,username,password,port_type,reality,sub_links
          FROM server_config WHERE id=2;
        INSERT INTO server_config (id,panel_url,ip,sni,header_type,request_header,
           response_header,security,tlsSettings,type,username,password,port_type,reality,sub_links)
          SELECT 6,panel_url,ip,sni,header_type,request_header,
             response_header,security,tlsSettings,'normal',username,password,port_type,reality,sub_links
          FROM server_config WHERE id=2;
        INSERT INTO orders_list (userid,token,transid,fileid,server_id,inbound_id,
            remark,uuid,protocol,expire_date,link,amount,status,date)
            VALUES (1001,'logical-token','',7,2,0,'r4-order','fixture-uuid',
                    'vless',2000000000,'[]',100,1,'fake');
        """)
        oid=int(sql(work,env,"SELECT id FROM orders_list WHERE remark='r4-order'").strip())
        prev=purchase.block
        def block(kind,modern):
            if kind=='transfer': return b"<?php require __DIR__.'/src/Subscription/AccountTransfers.php';"
            if kind=='admin_transfer': return b"<?php require __DIR__.'/src/Subscription/OrderManagement.php';"
            if kind=='wizard': return wizard
            if kind=='race_wizard':
                # Two independent PHP admin sessions enter the real bot handler.
                gate=(b"<?php\nfile_put_contents(__DIR__.'/race-ready','1');\n"
                      b"$deadline=microtime(true)+8;\n"
                      b"while (!file_exists(__DIR__.'/../group-race-go')) {\n"
                      b"  if(microtime(true)>$deadline) throw new RuntimeException('race_barrier_timeout');\n"
                      b"  usleep(1000);\n}\n")
                return gate+wizard[len(b'<?php\n'):]
            if kind=='group':
                return b"""<?php
$connection->begin_transaction();
try {
    $sid=(int)$spec['server_id'];
    $stmt=$connection->prepare('SELECT type FROM server_config WHERE id=? FOR UPDATE');
    $stmt->bind_param('i',$sid);$stmt->execute();$stmt->get_result();$stmt->close();
    achbotSetMarzbanGroup($connection,$sid,(int)$spec['group_id']);
    $connection->commit();achEvent('group_saved','ok');
} catch (Throwable $e) {
    $connection->rollback();achEvent('group_rejected',$e->getMessage());
}
"""
            if kind=='probe':
                return b"""<?php
achEvent('probe',[
 achbotSupportsMarzbanOrder($connection,(int)$spec['oid']),
 achbotSupportsPaymentSubject($connection,['type'=>'RENEW_ACCOUNT',
    'order_id'=>(int)$spec['oid'],'plan_id'=>7,'user_id'=>1001])
]);
"""
            return prev(kind,modern)
        purchase.block=block
        def do(kind,data='',actor=1001,**extra):
            panel.trace.clear()
            z=purchase.run(work,env,stage,DB,kind,
                {'actor':actor,'data':data,**extra},modern=True)
            if z['exception'] or z['warnings'] or z['stdout']:
                raise RuntimeError('Actual PHP failed: '+repr((kind,data,z))[:1400])
            if panel.trace and (kind!='wizard' or
                any('/api/admin/token' not in str(hit) for hit in panel.trace)):
                raise RuntimeError('Unapproved Marzban API call during logical group flow')
            return z
        def has(z,needle):return needle in json.dumps(z['events'],ensure_ascii=False)
        def refuse(tag,kind,data,actor=1001):
            before=snapshot(work,env)
            z=do(kind,data,actor)
            if snapshot(work,env)!=before:raise RuntimeError(tag+' changed SQL')
            if not any(x['name']=='alert' for x in z['events']):
                raise RuntimeError(tag+' did not give refusal feedback')
            passed(tag)
        def assign(sid,gid,success=True):
            z=do('group',server_id=sid,group_id=gid)
            if not has(z,'group_saved' if success else 'group_rejected'):
                raise RuntimeError('Invalid group assignment outcome: '+str(z))
            return z
        def newgroup(name):
            do('wizard',data='addMarzbanPanelGroup',actor=9001)
            z=do('wizard',text=name,actor=9001)
            if not has(z,'✅'): raise RuntimeError('Admin group wizard failed')
            return int(sql(work,env,
                f"SELECT id FROM setting WHERE type='ACHBOT_MARZBAN_PANEL_GROUP' AND value='{name}'").strip())
        def getid():
            return int(sql(work,env,f'SELECT server_id FROM orders_list WHERE id={oid}').strip())
        try:
            # Two real admin callbacks, two independent mysqli connections.
            # A disposable trigger delays INSERT so an unlocked SELECT/INSERT
            # implementation would admit duplicate normalized names.
            race_name='پنل همزمان'
            sql(work,env,"UPDATE users SET step='newMarzbanPanelGroup' WHERE userid IN (9001,9002)")
            roots=[work/'group-race-a',work/'group-race-b']
            for root in roots: shutil.copytree(stage,root)
            sql(work,env,
                "CREATE TRIGGER achbot_race_delay BEFORE INSERT ON setting "
                "FOR EACH ROW SET @achbot_race_delay = SLEEP(0.35)")
            try:
                with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
                    futures=[
                        pool.submit(purchase.run,work,env,root,DB,'race_wizard',
                                    {'actor':actor,
                                     'text':' '+race_name+'  ' if actor==9001 else race_name},
                                    modern=True)
                        for root,actor in zip(roots,(9001,9002))
                    ]
                    for _ in range(240):
                        if all((root/'race-ready').exists() for root in roots):
                            break
                        if any(f.done() for f in futures):
                            raise RuntimeError('Race worker ended before synchronized start')
                        time.sleep(0.02)
                    else:
                        raise RuntimeError('Two admin PHP workers did not rendezvous')
                    (work/'group-race-go').write_text('go')
                    outcomes=[f.result(timeout=28) for f in futures]
                if any(z['exception'] or z['warnings'] or z['stdout'] for z in outcomes):
                    raise RuntimeError('Admin race PHP exception/warning: '+repr(outcomes)[:1800])
                accepted=sum(has(z,'✅') for z in outcomes)
                rejected=sum(has(z,'قبلاً') for z in outcomes)
                rows=sql(work,env,
                    "SELECT id,value FROM setting WHERE type='ACHBOT_MARZBAN_PANEL_GROUP' "
                    "AND value='پنل همزمان' ORDER BY id").splitlines()
                if accepted!=1 or rejected!=1 or len(rows)!=1:
                    raise RuntimeError('Concurrent admin create did not produce one stable ID: '+
                                       repr((accepted,rejected,rows,outcomes))[:2000])
                stable_id=int(rows[0].split('\t')[0])
                if stable_id<=0:raise RuntimeError('Concurrent group ID invalid')
                sql(work,env,f"DELETE FROM setting WHERE id={stable_id}")
            finally:
                (work/'group-race-go').write_text('go')
                sql(work,env,'DROP TRIGGER IF EXISTS achbot_race_delay')
                sql(work,env,"UPDATE users SET step='none' WHERE userid IN (9001,9002)")
            passed('concurrent_two_admin_same_normalized_name_one_group')
            refuse('same_url_without_group_blocked','transfer',f'switchServer5_{oid}')
            refused=do('transfer',f'switchLocation{oid}_2_0_0')
            if not any(x['name']=='alert' for x in refused['events']):
                raise RuntimeError('Unassigned logical server offered transfers')
            passed('ungrouped_transfer_menu_empty')
            manager=do('wizard',data='manageMarzbanPanelGroups',actor=9001)
            if not has(manager,'addMarzbanPanelGroup'):raise RuntimeError('Group management missing')
            passed('admin_catalogue_manager_available')
            g1=newgroup('پنل اصلی');g2=newgroup('پنل دیگر')
            passed('persian_named_groups_created')
            do('wizard',data='addMarzbanPanelGroup',actor=9001)
            dupe=do('wizard',text='پنل اصلی',actor=9001)
            if not has(dupe,'قبلاً'):raise RuntimeError('Duplicate group accepted')
            passed('duplicate_group_name_rejected')
            do('wizard',data='addMarzbanPanelGroup',actor=9001)
            emoji_name='🟩'*60
            result=do('wizard',text=emoji_name,actor=9001)
            if not has(result,'✅'):raise RuntimeError('60 Unicode emoji group name rejected')
            # The mariadb CLI can default to utf8mb3 on older CI runners.
            # Compare the stored utf8mb4 bytes using ASCII HEX(), not an
            # emoji literal interpreted through the client's default charset.
            emoji_row=sql(work,env,
                "SELECT id,HEX(value),CHAR_LENGTH(value) FROM setting "
                "WHERE type='ACHBOT_MARZBAN_PANEL_GROUP' ORDER BY id DESC LIMIT 1"
            ).strip().split('\t')
            if (len(emoji_row)!=3 or emoji_row[1].lower()!=emoji_name.encode().hex()
                    or emoji_row[2]!='60'):
                raise RuntimeError('Stored Unicode name is not exact UTF-8: '+repr(emoji_row))
            emoji_id=int(emoji_row[0])
            do('wizard',data=f'deleteMarzbanPanelGroup_{emoji_id}',actor=9001)
            if sql(work,env,f'SELECT COUNT(*) FROM setting WHERE id={emoji_id}').strip()!='0':
                raise RuntimeError('Unicode test group deletion failed')
            passed('sixty_unicode_emoji_group_name_accepted')
            do('wizard',data='addMarzbanPanelGroup',actor=9001)
            for invalid in ('بخش\nدیگر','نام\n'):
                state=snapshot(work,env)
                rejected=do('wizard',text=invalid,actor=9001)
                if not has(rejected,'نام گروه نامعتبر') or snapshot(work,env)!=state:
                    raise RuntimeError('Name containing newline passed admin validation')
            passed('embedded_and_trailing_newline_groups_rejected')
            initial=snapshot(work,env)
            do('wizard',data='addMarzbanPanelGroup',actor=1001)
            if snapshot(work,env)!=initial:raise RuntimeError('Customer wrote group')
            passed('only_admin_may_create_group')
            for sid,gid in [(2,g1),(3,g1),(4,g2),(5,g2),(6,g1)]:assign(sid,gid)
            passed('groups_persist_in_server_info_column')
            menu=do('transfer',f'switchLocation{oid}_2_0_0')
            if not has(menu,f'switchServer3_{oid}') or any(
                has(menu,f'switchServer{i}_{oid}') for i in (4,5,6)):
                raise RuntimeError('Transfer target choices not based ONLY on group')
            passed('different_api_and_username_same_group_can_transfer')
            for sid,tag in [(4,'cross_panel_blocked'),(5,'same_api_different_group_blocked'),
                            (6,'retired_provider_blocked')]:
                refuse(tag,'transfer',f'switchServer{sid}_{oid}')
            refuse('nonowner_cannot_switch','transfer',f'switchServer3_{oid}',actor=9002)
            refuse('admin_cannot_cross_panel','admin_transfer',f'selectServerId_{oid}_4',actor=9001)
            refuse('forged_admin_callback_denied','admin_transfer',f'selectServerId_{oid}_3',actor=1001)
            before=snapshot(work,env)
            fields=sql(work,env,f'SELECT fileid,token,remark,uuid,link,amount,status FROM orders_list WHERE id={oid}')
            z=do('transfer',f'switchServer3_{oid}')
            if getid()!=3 or not has(z,'editText'):raise RuntimeError('User remapping failed')
            if fields!=sql(work,env,
                f'SELECT fileid,token,remark,uuid,link,amount,status FROM orders_list WHERE id={oid}'):
                raise RuntimeError('Logical transfer modified physical account or payment')
            if any(before[0][k]!=snapshot(work,env)[0][k] for k in
                   ('payments','capacity','users','plans','categories')):
                raise RuntimeError('Logical transfer touched finance or catalogue')
            passed('server_id_only_no_financial_or_provider_mutation')
            z=do('probe',oid=oid)
            if not has(z,'[true, true]'):raise RuntimeError('Renewal blocked after group move: '+str(z))
            passed('original_plan_renewal_still_valid')
            refuse('replayed_user_callback','transfer',f'switchServer3_{oid}')
            reject=assign(3,g2,False)
            if not has(reject,'group_change_would_strand_order'):
                raise RuntimeError('Group edit stranded existing order')
            passed('group_change_cannot_strand_existing_renewal')
            z=do('admin_transfer',f'changeServerId_{oid}',actor=9001)
            if not has(z,f'selectServerId_{oid}_2') or has(z,f'selectServerId_{oid}_4'):
                raise RuntimeError('Admin selector leaked other groups')
            passed('admin_selector_group_filtered')
            do('admin_transfer',f'selectServerId_{oid}_2',actor=9001)
            if getid()!=2:raise RuntimeError('Admin same-panel switch failed')
            passed('admin_same_panel_id_only_switch')
            assign(3,0)
            refuse('unassigned_destination_denied','transfer',f'switchServer3_{oid}')
            assign(3,g1)
            passed('group_unset_reset_with_order_preserved')
            sql(work,env,'UPDATE server_info SET state=0 WHERE id=3')
            refuse('disabled_destination_denied','transfer',f'switchServer3_{oid}')
            sql(work,env,"UPDATE server_info SET state=1,ucount='0' WHERE id=3")
            refuse('zero_capacity_destination_denied','transfer',f'switchServer3_{oid}')
            sql(work,env,"UPDATE server_info SET ucount='9' WHERE id=3")
            orig=fixture.read_text()
            fixture.write_text(orig.replace("'switchLocationState'=>'on'",
                "'switchLocationState'=>'off'"))
            try:refuse('bot_toggle_off_denies_transfer','transfer',f'switchServer3_{oid}')
            finally:fixture.write_text(orig)

            picker=do('wizard',data='editMarzbanGroup3',actor=9001)
            if not has(picker,f'setServerPanelGroup_3_{g2}'):
                raise RuntimeError('Admin edit picker did not show catalogued groups')
            do('wizard',data=f'setServerPanelGroup_3_{g2}',actor=9001)
            if sql(work,env,'SELECT panel_group_id FROM server_info WHERE id=3').strip()!=str(g2):
                raise RuntimeError('Admin group editor failed')
            passed('admin_edit_group_from_defined_categories')
            do('wizard',data='setServerPanelGroup_3_0',actor=9001)
            if sql(work,env,'SELECT panel_group_id IS NULL FROM server_info WHERE id=3').strip()!='1':
                raise RuntimeError('Admin unset group failed')
            passed('admin_can_choose_no_group')

            # Current onboarding is owned above the retained inline group
            # catalogue. Execute that real controller with private, fresh frames;
            # the old selectNewPanelGroup callback is no longer its protocol.
            entry="require_once __DIR__.'/src/Admin/ManagementFlow.php';\nif (achbotHandleManagement()) return;"
            if source.count(entry)!=1 or source.index(entry)>=a:
                raise RuntimeError('Current management owner is not the earlier bot entry')
            driver=work/'logical-current-onboarding.php'
            php=management.PHP
            label="'review_label'=>achbotManagementText('review_required'),"
            if php.count(label)!=1:
                raise RuntimeError('Current management fixture label anchor drift')
            php=php.replace(label,label+
                "'targets_label'=>achbotManagementText('management_targets'),"+
                "'saved_label'=>achbotManagementText('server_saved'),")
            driver.write_text(php)
            # Begin a separate onboarding journey after the preceding catalogue
            # editor scenarios; no prior unrelated editor draft is silently used.
            sql(work,env,"UPDATE users SET step='none',temp=NULL WHERE userid=9001")
            sequence=800000
            auth_calls=[]
            def current_call(**spec):
                nonlocal sequence
                sequence+=1
                spec.setdefault('source',sequence)
                spec.setdefault('actor',9001)
                panel.trace.clear()
                proc=subprocess.run(
                    ['php','-d','display_errors=0','-d','log_errors=0',
                     '-d','open_basedir='+str(stage)+':'+str(work),
                     str(driver),str(stage),str(work/'mysql.sock'),DB,
                     json.dumps(spec,ensure_ascii=False)],
                    cwd=stage,env=env,capture_output=True,text=True,timeout=30)
                if proc.returncode or any(not re.fullmatch(
                    r'achbot_management(?:_field)?_deferred reason=[a-z_]+',line)
                    for line in proc.stderr.splitlines()):
                    raise RuntimeError('Current onboarding PHP child failed: '+
                                       repr((proc.returncode,proc.stderr))[:1200])
                result=json.loads(proc.stdout)
                if 'exception' in result or result.get('handled') is not True:
                    raise RuntimeError('Actual current onboarding not handled: '+repr(result)[:1200])
                if any('/api/admin/token' not in str(hit) for hit in panel.trace):
                    raise RuntimeError('Current onboarding performed a non-admission provider call')
                auth_calls.extend(panel.trace)
                return result
            def current_draft(result,expected):
                state=result['state']
                draft=json.loads(state['temp']) if state and state['temp'] else None
                if (not draft or draft['kind']!='server' or draft['stage']!=expected or
                    state['step']!='achbotManage|'+draft['nonce']+'|'+str(draft['rev'])):
                    raise RuntimeError('Wrong current onboarding stage: '+repr((expected,state)))
                return draft
            def group_callback(draft,value):
                return 'am|'+draft['nonce']+'|'+str(draft['rev'])+'|group|'+str(value)
            protected_query=(
                'SELECT * FROM server_info ORDER BY id;'
                'SELECT * FROM server_config ORDER BY id;'
                'SELECT * FROM orders_list ORDER BY id;SELECT * FROM pays ORDER BY id;'
                'SELECT * FROM server_plans ORDER BY id;SELECT * FROM setting ORDER BY id;'
                'SELECT * FROM users WHERE userid<>9001 ORDER BY id;'
                'SELECT userid,wallet FROM users ORDER BY id')
            protected_before=sql(work,env,protected_query)
            username,password=sql(work,env,
                'SELECT username,password FROM server_config WHERE id=2').strip().split('\t',1)
            result=current_call(callback='addNewMarzbanPanel')
            draft=current_draft(result,'title')
            nonce=draft['nonce']
            for value,expected in [
                ('Grouped wizard server','capacity'),('8','remark'),
                ('grouped-remark','flag'),('🇮🇷','url'),(url,'username'),
                (username,'password'),(password,'panelgroup')]:
                result=current_call(text=value)
                draft=current_draft(result,expected)
                if draft['nonce']!=nonce or draft['source']!=sequence:
                    raise RuntimeError('Current onboarding lost its fresh actor-owned draft')
                if sql(work,env,protected_query)!=protected_before:
                    raise RuntimeError('Current onboarding wrote business rows before confirmation')
            passed('unified_onboarding_private_fresh_actor_draft')
            for tag,override,silent in [
                ('nonprivate_onboarding_group_refused_without_writes',{'chat_type':'group'},True),
                ('forged_sender_onboarding_group_refused_without_writes',{'sender':9002},True),
                ('stale_onboarding_group_refused_without_writes',{'source':draft['source']},False)]:
                before=snapshot(work,env)
                denied=current_call(callback=group_callback(draft,g1),**override)
                if snapshot(work,env)!=before or panel.trace:
                    raise RuntimeError(tag+' changed SQL or called provider')
                if silent and denied['events']:
                    raise RuntimeError(tag+' exposed the private draft')
                if not silent and not denied['events']:
                    raise RuntimeError(tag+' lacked stale-action feedback')
                passed(tag)
            before=snapshot(work,env)
            denied=current_call(callback=group_callback(draft,999999))
            if (snapshot(work,env)!=before or panel.trace or
                denied['targets_label'] not in [e.get('body') for e in denied['events']]):
                raise RuntimeError('Forged current onboarding group was not refused without writes')
            current_draft(denied,'panelgroup')
            passed('forged_onboarding_group_id')
            chosen=current_call(callback=group_callback(draft,g1))
            review=current_draft(chosen,'review')
            if (review['values']['panel_group_id']!=g1 or
                sql(work,env,protected_query)!=protected_before):
                raise RuntimeError('Current group selection did not remain an unsaved review')
            passed('valid_onboarding_group_requires_explicit_confirmation')
            created=current_call(callback='am|'+review['nonce']+'|'+str(review['rev'])+'|save|0')
            if (created['state']!={'step':'none','temp':None} or
                created['saved_label'] not in [e.get('body') for e in created['events']]):
                raise RuntimeError('Current Marzban server confirmation did not complete')
            new_id=int(sql(work,env,
                "SELECT id FROM server_info WHERE title='Grouped wizard server'").strip())
            if sql(work,env,
                f'SELECT panel_group_id FROM server_info WHERE id={new_id}').strip()!=str(g1):
                raise RuntimeError('New logical server lost group field')
            if len(auth_calls)!=2:
                raise RuntimeError('Current onboarding must authenticate at password and final save only')
            passed('native_marzban_onboarding_selects_catalog_group')
            onboarding_proof={'actual_management_entry':True,'private_fresh_frames':True,
                'nonce_revision_group_callback':True,'unknown_group_refused_without_writes':True,
                'invalid_frame_and_stale_input_refused_without_writes':True,
                'explicit_confirmation_required':True,'provider_admission_calls':len(auth_calls),
                'remote_account_writes':0}
            denied=do('wizard',data=f'deleteMarzbanPanelGroup_{g1}',actor=9001)
            if not has(denied,'هنوز'):raise RuntimeError('Assigned group was deleted')
            passed('assigned_group_delete_refused')
            unused=newgroup('گروه خالی')
            do('wizard',data=f'deleteMarzbanPanelGroup_{unused}',actor=9001)
            if sql(work,env,f'SELECT COUNT(*) FROM setting WHERE id={unused}').strip()!='0':
                raise RuntimeError('Unassigned group could not be removed')
            passed('unassigned_group_deletion')
            if len(sql(work,env,'SHOW TABLES').splitlines())!=23 or stages.ddl(work,env,DB,tables)!=after:
                raise RuntimeError('Application mutated schema beyond migration')
            passed('post_migration_schema_stable')
        finally:
            purchase.block=prev
    report={'complete':True,'cases':len(checks),'schema_tables':23,
            'additive_server_info_column_verified':True,
            'original_historical_schema_file_unchanged':True,
            'same_panel_group_not_derived_from_url_or_username':True,
            'concurrent_two_admin_group_creation_verified':True,
            'pinned_v9_source_on_migrated_schema_verified':True,
            'unicode_group_name_contract_verified':True,
            'no_real_provider_or_payment_calls':True,
            'current_unified_onboarding':onboarding_proof,'scenarios':checks}
    if args.report:args.report.write_text(json.dumps(report,indent=2,ensure_ascii=False)+'\n')
    print(json.dumps({k:v for k,v in report.items() if k!='scenarios'},ensure_ascii=False))
if __name__=='__main__':main()
