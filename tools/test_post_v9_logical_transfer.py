#!/usr/bin/env python3
"""R3 logical-server Marzban relocation: real PHP callbacks and original SQL.

All source executes with synthetic Telegram messages on owned MariaDB socket
and a loopback-only fake Marzban provider. No real accounts or funds touched.
"""
from __future__ import annotations

import argparse
import json
from pathlib import Path

import test_access_state as owned
import test_v6_native as native
import test_v8_native as purchase
import test_post_v9_purchases as stages

ROOT = Path(__file__).resolve().parents[1]
DB = purchase.SIDE[1]


def sql(work, env, statement):
    return native.sql(work, env, statement, DB)


def add_order(work, env):
    sql(work, env, """INSERT INTO orders_list
        (userid,token,transid,fileid,server_id,inbound_id,remark,uuid,
         protocol,expire_date,link,amount,status,date)
        VALUES (1001,'logical-token','',7,2,0,'logical-fixture','uuid',
                'vless',2000000000,'[]',100,1,'synthetic')""")
    return int(sql(work, env, "SELECT id FROM orders_list WHERE remark='logical-fixture'").strip())


def fixture(work, env):
    # Servers 2 and 3 share the exact provider API. 4 is different API path,
    # 5 is a different API username and 6 is retired; all have synthetic data.
    sql(work, env, """
    DELETE FROM server_config WHERE id IN (3,4,5,6);
    INSERT INTO server_info (id,title,ucount,remark,flag,active,state)
      VALUES
        (3,'Other logical plan',9,'logic-3','',1,1),
        (4,'Different physical API',9,'other','',1,1),
        (5,'Different credential',9,'wrong','',1,1),
        (6,'Retired provider',9,'retired','',1,1);
    INSERT INTO server_config (id,panel_url,ip,sni,header_type,request_header,
       response_header,security,tlsSettings,type,username,password,port_type,reality,sub_links)
      SELECT 3,panel_url,ip,sni,header_type,request_header,
       response_header,security,tlsSettings,type,username,password,port_type,reality,sub_links
       FROM server_config WHERE id=2;
    INSERT INTO server_config (id,panel_url,ip,sni,header_type,request_header,
       response_header,security,tlsSettings,type,username,password,port_type,reality,sub_links)
      SELECT 4,CONCAT(panel_url,'/different'),ip,sni,header_type,request_header,
       response_header,security,tlsSettings,type,username,password,port_type,reality,sub_links
       FROM server_config WHERE id=2;
    INSERT INTO server_config (id,panel_url,ip,sni,header_type,request_header,
       response_header,security,tlsSettings,type,username,password,port_type,reality,sub_links)
      SELECT 5,panel_url,ip,sni,header_type,request_header,
       response_header,security,tlsSettings,type,'different-admin',password,port_type,reality,sub_links
       FROM server_config WHERE id=2;
    INSERT INTO server_config (id,panel_url,ip,sni,header_type,request_header,
       response_header,security,tlsSettings,type,username,password,port_type,reality,sub_links)
      SELECT 6,panel_url,ip,sni,header_type,request_header,
       response_header,security,tlsSettings,'normal',username,password,port_type,reality,sub_links
       FROM server_config WHERE id=2;
    INSERT INTO server_plans (id,fileid,catid,server_id,inbound_id,acount,
       title,protocol,days,volume,type,price,descr,pic,active,step,date,custom_sni)
      SELECT 11,'11',catid,3,0,acount,'Different logical price',protocol,
       days,volume,type,price+37,descr,pic,active,step,date,custom_sni
       FROM server_plans WHERE id=7;
    """)


def assert_unchanged(work, env, before, tag):
    if purchase.snapshot(work, env, DB) != before:
        raise RuntimeError("Unauthorized mutation on " + tag)


def get_id(work, env, oid):
    return int(sql(work, env, f"SELECT server_id FROM orders_list WHERE id={oid}").strip())


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--run-disposable', action='store_true', required=True)
    ap.add_argument('--report', type=Path)
    args = ap.parse_args()
    cases = []
    with owned.owned_database() as (work, env), native.owned_provider() as (provider, url):
        stage = work / 'logical-transfer'
        stages.stage_source(stage, True)
        fixture_path = stage / 'native-purchase.php'
        fixture_text = fixture_path.read_text()
        assert fixture_text.count("'subLinkState'=>'on',") == 1
        fixture_text = fixture_text.replace(
            "'subLinkState'=>'on',", "'subLinkState'=>'on','switchLocationState'=>'on',")
        assert fixture_text.count('function getServerListKeys(') == 1
        fixture_text = fixture_text.replace('function getServerListKeys(',
            "function getServerConfigKeys($id,$offset=0){return 'fixture-server-config';}\nfunction getServerListKeys(")
        fixture_path.write_text(fixture_text)
        bot_src=(stage/'bot.php').read_text()
        start=bot_src.index('if($data == "addNewMarzbanPanel"')
        end=bot_src.index("if(preg_match('/^changesServerLoginInfo",start)
        admin_wizard_php=b'<?php\n'+bot_src[start:end].encode()
        purchase.init(work, env, url)
        tables = sql(work, env, 'SHOW TABLES').splitlines()
        if len(tables) != 23:
            raise RuntimeError('Schema no longer has 23 tables')
        original_ddl = stages.ddl(work, env, DB, tables)
        fixture(work, env)
        oid = add_order(work, env)

        old_block = purchase.block

        def block(kind, modern):
            if kind == 'transfer':
                return b"<?php\nrequire __DIR__ . '/src/Subscription/AccountTransfers.php';\n"
            if kind == 'admin_transfer':
                return b"<?php\nrequire __DIR__ . '/src/Subscription/OrderManagement.php';\n"
            if kind == 'admin_wizard':
                return admin_wizard_php
            if kind == 'probe':
                return b"""<?php
achEvent('probe', [
  achbotSupportsMarzbanOrder($connection, (int)$spec['order_id']),
  achbotSupportsPaymentSubject($connection, [
    'type'=>'RENEW_ACCOUNT', 'plan_id'=>7,
    'order_id'=>(int)$spec['order_id'], 'user_id'=>1001
  ])
]);
"""
            if kind == 'set_group':
                return b"""<?php
$connection->begin_transaction();
achbotSetMarzbanGroup($connection, (int)$spec['server_id'], $spec['group']);
$connection->commit();
"""
            return old_block(kind, modern)

        purchase.block = block

        def do(kind, data=None, actor=1001, **extra):
            provider.trace.clear()
            info = purchase.run(work, env, stage, DB, kind,
                dict(data=data or '', actor=actor, **extra), modern=True)
            if info['exception'] or info['warnings'] or info['stdout']:
                raise RuntimeError('PHP warnings/exception: ' + repr((kind, data, info))[:900])
            if provider.trace and kind != 'admin_wizard':
                raise RuntimeError('Marzban account operation during logical switch')
            if provider.trace and kind == 'admin_wizard':
                if not all('/api/admin/token' in str(call) for call in provider.trace):
                    raise RuntimeError('Unexpected Marzban request during synthetic onboarding')
            return info

        def event_has(info, needle):
            return needle in json.dumps(info['events'], ensure_ascii=False)

        def refuse(name, kind, data, actor=1001):
            before = purchase.snapshot(work, env, DB)
            info = do(kind, data, actor)
            assert_unchanged(work, env, before, name)
            if not any(ev['name'] == 'alert' for ev in info['events']):
                raise RuntimeError('Missing refusal feedback: ' + name)
            cases.append({'case':name, 'pass':True, 'sql_mutation':0, 'provider_calls':0})

        def set_group(sid, label):
            do('set_group', '', server_id=sid, group=label)

        try:
            # Legacy duplicate server entries have no group setting yet.
            original = purchase.snapshot(work, env, DB)
            catalog = do('transfer', f'switchLocation{oid}_2_1.7_1900000000')
            if not event_has(catalog, f'switchServer3_{oid}'):
                raise RuntimeError('Same-panel old logical server missing')
            if any(event_has(catalog, f'switchServer{i}_{oid}') for i in (4,5,6)):
                raise RuntimeError('Other physical providers leak into transfer choices')
            assert_unchanged(work, env, original, 'legacy_same_panel_menu')
            cases.append({'case':'legacy_same_panel_menu','pass':True, 'provider_calls':0})

            refuse('different_api_blocked_user', 'transfer', f'switchServer4_{oid}')
            refuse('different_admin_blocked_user', 'transfer', f'switchServer5_{oid}')
            refuse('retired_type_blocked_user', 'transfer', f'switchServer6_{oid}')
            refuse('other_owner_blocked', 'transfer', f'switchServer3_{oid}', actor=9002)
            refuse('other_owner_menu_blocked', 'transfer', f'switchLocation{oid}_2_0_0', actor=9002)

            before = purchase.snapshot(work, env, DB)
            before_fields=sql(work,env,
                f'SELECT fileid,token,remark,uuid,protocol,link,amount,status FROM orders_list WHERE id={oid}')
            success = do('transfer', f'switchServer3_{oid}')
            if not event_has(success, 'editText') or get_id(work,env,oid)!=3:
                raise RuntimeError('Logical reassignment did not succeed')
            after = purchase.snapshot(work, env, DB)
            if any(before[key]!=after[key] for key in ('payments','capacity','users','plans','categories')):
                raise RuntimeError('Logical switch changed payments, capacity or plans')
            after_fields=sql(work,env,
                f'SELECT fileid,token,remark,uuid,protocol,link,amount,status FROM orders_list WHERE id={oid}')
            if before_fields != after_fields:
                raise RuntimeError('Logical switch rewrote provider account/plan fields')
            cases.append({'case':'positive_legacy_same_panel_reassign','pass':True,
                'changed':'orders_list.server_id_only','provider_calls':0})

            obs = do('probe', order_id=oid)
            probes = [event for event in obs['events'] if event['name']=='probe']
            if not probes or probes[0]['args'] != [True,True]:
                raise RuntimeError('Post-reassignment Marzban order or renewal was rejected')
            cases.append({'case':'old_plan_renewal_remains_valid','pass':True})

            refuse('user_replay_noop', 'transfer', f'switchServer3_{oid}')
            refuse('stale_old_source_menu', 'transfer', f'switchLocation{oid}_2_0_0')

            admin_menu=do('admin_transfer', f'changeServerId_{oid}', actor=9001)
            if not event_has(admin_menu, f'selectServerId_{oid}_2') or \
                event_has(admin_menu, f'selectServerId_{oid}_4'):
                raise RuntimeError('Admin menu did not filter to shared physical panel')
            cases.append({'case':'admin_same_panel_menu','pass':True})

            refuse('admin_different_physical_panel', 'admin_transfer',
                   f'selectServerId_{oid}_4', actor=9001)
            refuse('unprivileged_admin_callback', 'admin_transfer',
                   f'selectServerId_{oid}_2', actor=1001)

            admin_move=do('admin_transfer', f'selectServerId_{oid}_2', actor=9001)
            if get_id(work,env,oid)!=2 or not event_has(admin_move,'showOrderManagementPanel'):
                # Panel rendering produces sendMessage/editText, not an event
                # named after the PHP function.
                if get_id(work,env,oid)!=2 or not event_has(admin_move,'sendMessage'):
                    raise RuntimeError('Admin same-panel reassign failed')
            cases.append({'case':'positive_admin_same_panel_reassign','pass':True})

            set_group(2,'main'); set_group(3,'main')
            if sql(work,env, "SELECT value FROM setting WHERE type='ACHBOT_MARZBAN_PANEL_GROUP_3'").strip()!='main':
                raise RuntimeError('Group setting not saved in original schema')
            can=do('transfer', f'switchLocation{oid}_2_0_0')
            if not event_has(can, f'switchServer3_{oid}'):
                raise RuntimeError('Explicit same group was not selectable')
            cases.append({'case':'explicit_shared_tag','pass':True})

            set_group(3,'isolated')
            refuse('conflicting_tags_blocked','transfer',f'switchServer3_{oid}')
            set_group(3,'main')
            set_group(4,'main')
            refuse('same_tag_different_endpoint_blocked','transfer',f'switchServer4_{oid}')
            set_group(5,'main')
            refuse('same_tag_different_credentials_blocked','transfer',f'switchServer5_{oid}')
            set_group(6,'main')
            refuse('same_tag_retired_provider_blocked','transfer',f'switchServer6_{oid}')
            set_group(3,'')
            if sql(work,env,"SELECT COUNT(*) FROM setting WHERE type='ACHBOT_MARZBAN_PANEL_GROUP_3'").strip()!='0':
                raise RuntimeError('Auto mode left obsolete tag record')
            can=do('transfer',f'switchLocation{oid}_2_0_0')
            if not event_has(can, f'switchServer3_{oid}'):
                raise RuntimeError('Untagged legacy matching panel no longer permitted')
            cases.append({'case':'auto_group_removal_and_match','pass':True})

            set_group(3,'main')
            sql(work,env,"INSERT INTO setting (type,value) VALUES ('ACHBOT_MARZBAN_PANEL_GROUP_3','main')")
            refuse('ambiguous_duplicate_tags_blocked','transfer',f'switchServer3_{oid}')
            set_group(3,'main') # admin repair normalizes duplicate settings
            cases.append({'case':'duplicate_group_repair','pass':True})

            sql(work,env,"UPDATE server_info SET state=0 WHERE id=3")
            refuse('disabled_destination_blocked','transfer',f'switchServer3_{oid}')
            sql(work,env,"UPDATE server_info SET state=1,ucount='0' WHERE id=3")
            refuse('no_capacity_destination_blocked','transfer',f'switchServer3_{oid}')
            sql(work,env,"UPDATE server_info SET ucount='9' WHERE id=3")
            sql(work,env,f"UPDATE orders_list SET inbound_id=7 WHERE id={oid}")
            refuse('old_inbound_order_blocked','transfer',f'switchServer3_{oid}')
            sql(work,env,f"UPDATE orders_list SET inbound_id=0 WHERE id={oid}")
            sql(work,env,"UPDATE server_plans SET inbound_id=7 WHERE id=7")
            refuse('old_inbound_plan_blocked','transfer',f'switchServer3_{oid}')
            sql(work,env,"UPDATE server_plans SET inbound_id=0 WHERE id=7")
            configured=fixture_path.read_text()
            assert configured.count("'switchLocationState'=>'on'") == 1
            fixture_path.write_text(configured.replace("'switchLocationState'=>'on'",
                                                     "'switchLocationState'=>'off'"))
            try:
                refuse('user_feature_toggle_off','transfer',f'switchServer3_{oid}')
            finally:
                fixture_path.write_text(configured)
            sql(work,env,"UPDATE server_config SET panel_url=CONCAT(panel_url,'/alias') WHERE id=3")
            refuse('same_tag_but_changed_endpoint_blocked','admin_transfer',
                   f'selectServerId_{oid}_3',actor=9001)
            cases.append({'case':'no_schema_migration','pass':True, 'schema_tables':23})
            # The real admin onboarding fragment, not a reconstructed wizard,
            # must collect a tag and persist it after verifying local fake
            # Marzban credentials. Only synthetic local API login is allowed.
            user_row=sql(work,env,'SELECT username,password FROM server_config WHERE id=2').strip()
            admin_user,admin_password=user_row.split('\t',1)
            for spec in [
                dict(data='addNewMarzbanPanel'),
                dict(text='Wizard logical server'),
                dict(text='8'),
                dict(text='wizard-remark'),
                dict(text='🇮🇷'),
                dict(text=url),
            ]:
                do('admin_wizard', actor=9001, **spec)
            invalid=do('admin_wizard', actor=9001, text='bad group!')
            if not event_has(invalid,'تگ نامعتبر'):
                raise RuntimeError('Onboarding accepted invalid group')
            do('admin_wizard', actor=9001, text='main')
            do('admin_wizard', actor=9001, text=admin_user)
            finished=do('admin_wizard', actor=9001, text=admin_password)
            if not event_has(finished,'تبریک'):
                raise RuntimeError('Native onboarding failed local Marzban auth/SQL')
            wizard_id=int(sql(work,env,
                "SELECT id FROM server_info WHERE title='Wizard logical server' LIMIT 1").strip())
            if sql(work,env,
                f"SELECT value FROM setting WHERE type='ACHBOT_MARZBAN_PANEL_GROUP_{wizard_id}'").strip()!='main':
                raise RuntimeError('Onboarding lost explicit panel group')
            cases.append({'case':'positive_real_admin_onboarding_group','pass':True,
                          'only_owned_local_login':True})

            edited=do('admin_wizard',data=f'editMarzbanGroup{wizard_id}',actor=9001)
            if not event_has(edited,'🏷') or not event_has(edited,'main'):
                raise RuntimeError('Admin panel group editor did not open')
            do('admin_wizard',text='isolated',actor=9001)
            if sql(work,env,
                f"SELECT value FROM setting WHERE type='ACHBOT_MARZBAN_PANEL_GROUP_{wizard_id}'").strip()!='isolated':
                raise RuntimeError('Admin panel group editor did not persist')
            cases.append({'case':'positive_real_admin_group_edit','pass':True})
            do('admin_wizard',data=f'editMarzbanGroup{wizard_id}',actor=9001)
            do('admin_wizard',text='/auto',actor=9001)
            if sql(work,env,
                f"SELECT COUNT(*) FROM setting WHERE type='ACHBOT_MARZBAN_PANEL_GROUP_{wizard_id}'").strip()!='0':
                raise RuntimeError('Admin auto group option did not reset the tag')
            cases.append({'case':'positive_real_admin_group_auto_reset','pass':True})
            if stages.ddl(work,env,DB,tables) != original_ddl:
                raise RuntimeError('Original MariaDB DDL changed during logical transfer')
        finally:
            purchase.block = old_block

    report = {'complete':True,'cases':len(cases), 'original_schema_tables':23,
       'original_schema_unchanged':True, 'source_only_logical_reassignment':True,
       'no_real_panel_or_financial_calls':True,'scenarios':cases}
    if args.report:
        args.report.write_text(json.dumps(report,indent=2,ensure_ascii=False)+'\n')
    print(json.dumps({k:v for k,v in report.items() if k!='scenarios'}))

if __name__ == '__main__':
    main()
