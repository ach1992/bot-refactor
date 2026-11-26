#!/usr/bin/env python3
"""Single empty install, retained schema equivalence, and current runtime gates."""
import argparse
import json
from pathlib import Path
import re
import subprocess

import database_fixture as owned

ROOT=Path(__file__).resolve().parents[1]
RETIRED={'admins','black_list','servers','bank_buy_invoice_claims','automatic_renewal_attempts'}
PHP=r'''<?php
mysqli_report($argv[3]==='strict'?MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT:MYSQLI_REPORT_OFF);
$GLOBALS['telegramWebhookSecret']='Synthetic_Fresh_Schema_Only_2026';
require $argv[1].'/src/Finance/CheckoutAdmission.php';
require_once $argv[1].'/src/Finance/ServiceDelivery.php';
require_once $argv[1].'/src/Finance/FollowupOutbox.php';
require_once $argv[1].'/src/Services/EditFinalization.php';
$db=new mysqli('localhost','root','','fresh_single',0,$argv[2]);
if (($argv[4]??'')==='missing_delivery_contract') {
    echo achbotServiceDeliverySchemaReady($db)?'UNSAFE':'REJECTED';exit;
}
if (($argv[4]??'')==='missing_followup_index') {
    echo achbotFollowupSchemaReady($db)?'UNSAFE':'REJECTED';exit;
}
if (($argv[4]??'')==='settings_menu') {
    set_error_handler(static function($severity,$message){
        if ($severity & (E_WARNING|E_NOTICE|E_USER_WARNING))
            throw new ErrorException($message,0,$severity);
        return true;
    });
    $root=$argv[1];$connection=$db;
    require_once $root.'/tools/source_scan.php';
    require_once $root.'/src/Admin/InstanceContent.php';
    require_once $root.'/src/Finance/PaymentKeyAdmin.php';
    foreach (['getBotSettingKeys','getGateWaysKeys','achbotMutateBotStates','setSettings'] as $name)
        eval(str_replace('__DIR__',var_export($root,true),achbotExtractFunction($root.'/config.php',$name)));
    $before=(int)achbotProviderRow($db,'SELECT COUNT(*) AS n FROM setting')['n'];
    foreach ([false,true] as $configured) {
        if ($configured) $db->query("INSERT INTO setting(type,value) VALUES ('BOT_STATES','{\"cartToCartAutoAcceptState\":\"on\",\"botState\":\"off\"}')");
        foreach (['getBotSettingKeys','getGateWaysKeys'] as $name) {
            $rows=json_decode($name(),true,512,JSON_THROW_ON_ERROR)['inline_keyboard'];
            $buttons=[];
            foreach ($rows as $row) {
                if (!$row) throw new RuntimeException('empty_keyboard_row');
                foreach ($row as $button) {
                    if (isset($button['callback_data'])) {
                        if (strlen($button['callback_data'])>64) throw new RuntimeException('callback_length');
                        $buttons[$button['callback_data']]=$button['text'];
                    }
                }
            }
            foreach (['changeBotchangeProtocolState','changeUpdateConfigLinkState','changeBotcartToCartAutoAcceptState','changeBotcartToCartAutoAcceptType','editcartToCartAutoAcceptTime'] as $old)
                if (isset($buttons[$old])) throw new RuntimeException('retired_settings_button');
            if ($name==='getBotSettingKeys') {
                if ($buttons['changeBotbotState']!==achbotMessage($configured?'legacy_buttons.off':'legacy_buttons.on') ||
                    $buttons['changeBotchangePlanState']!==achbotMessage('legacy_buttons.on'))
                    throw new RuntimeException('bot_settings_current_defaults');
            } else {
                foreach (['walletState','nextpay','zarinpal','giftCardState','cartToCartState','smsApiVerifyState','giftApiVerifyState'] as $field)
                    if ($buttons['changeGateWays'.$field]!==achbotMessage('legacy_buttons.off'))
                        throw new RuntimeException('unconfigured_gateway_enabled');
            }
        }
    }
    if ((int)achbotProviderRow($db,'SELECT COUNT(*) AS n FROM setting')['n']!==$before+1)
        throw new RuntimeException('menu_mutated_settings');
    $db->query("DELETE FROM setting WHERE type='BOT_STATES'");
    // Exercise the actual toggle route and writer on the empty document.
    // No real webhook or Telegram requests leave the disposable harness.
    $source=file_get_contents($root.'/bot.php');
    $start=strpos($source,'if(($data=="botSettings"');
    $end=strpos($source,'if(($data=="gateWays_Channels"',$start);
    if ($start===false || $end===false) throw new RuntimeException('settings_route_missing');
    $route=substr($source,$start,$end-$start);
    $admin=$from_id=9001;$message_id=92;$userInfo=['isAdmin'=>1];$callbackId='fixture';$botState=[];
    $GLOBALS['settings_events']=[];
    function answerCallbackQuery(...$args) {$GLOBALS['settings_events'][]='denied';}
    function editText(...$args) {$GLOBALS['settings_events'][]='edited';}
    $data='changeBotchangeProtocolState';eval($route);
    if ($GLOBALS['settings_events']!==['denied'] || setSettings('updateConnectionState','site') ||
        setSettings('switchLocationState','on') || setSettings('cartToCartAutoAcceptState','on') || (int)achbotProviderRow($db,'SELECT COUNT(*) AS n FROM setting')['n']!==$before)
        throw new RuntimeException('retired_settings_writer');
    $data='changeBotbotState';eval($route);
    if ($botState!==['botState'=>'off'] || $GLOBALS['settings_events']!==['denied','edited'])
        throw new RuntimeException('first_robot_toggle_does_not_disable');
    eval($route);
    if ($botState!==['botState'=>'on'] || $GLOBALS['settings_events']!==['denied','edited','edited'])
        throw new RuntimeException('second_robot_toggle_does_not_enable');
    $db->query("DELETE FROM setting WHERE type='BOT_STATES'");
    echo 'PASS';exit;
}
achbotCheckoutRequireSchema($db);
achbotProviderRequireSchema($db);
foreach (['achbotWalletLedgerReady','achbotPaymentEvidenceSchemaReady','achbotServiceDeliverySchemaReady',
    'achbotFollowupSchemaReady','achbotServiceMutationSchemaReady','achbotAddonSchema'] as $check) {
    if (!$check($db)) throw new RuntimeException($check);
}
echo 'PASS';
'''

def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--run-disposable',action='store_true',required=True)
    parser.add_argument('--report',type=Path)
    args=parser.parse_args()
    migrations=sorted((ROOT/'migrations/post-v9').glob('[0-9][0-9][0-9]_*.sql'))
    checks=[]
    with owned.owned_database() as (work,env):
        cli=['mariadb','--no-defaults','--protocol=SOCKET','--socket='+str(work/'mysql.sock'),
             '-uroot','--batch','--skip-column-names']
        def sql(db,q):
            return subprocess.run(cli+([db] if db else []),input=q,text=True,env=env,
                capture_output=True,check=True,timeout=30).stdout.strip()
        sql(None,'CREATE DATABASE fresh_chain CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE DATABASE fresh_single CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci')
        sql('fresh_chain',(ROOT/'database/legacy-schema.sql').read_text())
        for migration in migrations:
            if migration.name=='043_current_only_delivery.sql':
                # Historical transport evidence is not migrated by a fresh
                # install. The construction DDL must fail before dropping it.
                sql('fresh_chain', '''INSERT INTO pays(hash_id,user_id,type,price,request_date,state)
                    VALUES ('old-delivery-fixture',1001,'INCREASE_WALLET',100,1700000000,'paid');
                    INSERT INTO payment_attempts(pay_row_id,provider,order_hash,quote_amount,quote_currency,local_amount_toman,stage)
                    SELECT id,'nextpay',hash_id,price,'toman',price,'done' FROM pays WHERE hash_id='old-delivery-fixture';
                    INSERT INTO payment_delivery_intents(pay_row_id,ordinal,attempt_id,user_id,pay_type,pay_fingerprint,source_digest,sealed_payload,payload_sha256,state)
                    SELECT p.id,1,a.id,p.user_id,p.type,REPEAT('a',64),REPEAT('b',64),'original-packet',REPEAT('c',64),'unknown'
                    FROM pays p JOIN payment_attempts a ON a.pay_row_id=p.id WHERE p.hash_id='old-delivery-fixture';''')
                before=sql('fresh_chain','SELECT * FROM payment_delivery_intents')
                preflight=migration.read_text().split(';',1)[0]+';'
                rejected=subprocess.run(cli+['fresh_chain'],input=preflight,text=True,env=env,capture_output=True,timeout=30)
                assert rejected.returncode!=0 and 'ck_delivery_no_historical_rows' in rejected.stderr,rejected.stderr
                assert sql('fresh_chain','SELECT * FROM payment_delivery_intents')==before
                checks.append('construction_refuses_historical_delivery_without_mutating_evidence')
                sql('fresh_chain','DELETE FROM payment_delivery_intents; DELETE FROM payment_attempts; DELETE FROM pays')
            if migration.name=='051_payment_message_ownership.sql':
                sql('fresh_chain', """INSERT INTO pays(hash_id,user_id,type,price,request_date,state)
                    VALUES ('old-message-fixture',1001,'INCREASE_WALLET',100,1700000000,'paid');
                    INSERT INTO payment_message_refs(pay_row_id,chat_id,message_id,kind)
                    SELECT id,user_id,123,'text' FROM pays WHERE hash_id='old-message-fixture';
                    INSERT INTO payment_attempts(pay_row_id,provider,order_hash,quote_amount,quote_currency,local_amount_toman,stage,evidence_invoice_chat_id,evidence_invoice_message_id,evidence_invoice_digest)
                    SELECT id,'bank_transfer',hash_id,price,'toman',price,'done',user_id,123,REPEAT('a',64) FROM pays WHERE hash_id='old-message-fixture';""")
                before_ref=sql('fresh_chain','SELECT * FROM payment_message_refs')
                before_attempt=sql('fresh_chain','SELECT * FROM payment_attempts')
                # Execute the complete construction script against each
                # forbidden source independently. Neither rows nor schema
                # may change, even if only the second source blocks it.
                before_defs={table:sql('fresh_chain','SHOW CREATE TABLE '+table)
                    for table in ('payment_message_refs','payment_attempts')}
                rejected=subprocess.run(cli+['fresh_chain'],input=migration.read_text(),text=True,env=env,capture_output=True,timeout=30)
                assert rejected.returncode!=0 and 'existing message references require transfer' in rejected.stderr,rejected.stderr
                assert sql('fresh_chain','SELECT * FROM payment_message_refs')==before_ref
                assert sql('fresh_chain','SELECT * FROM payment_attempts')==before_attempt
                assert all(sql('fresh_chain','SHOW CREATE TABLE '+table)==definition for table,definition in before_defs.items())
                sql('fresh_chain','DELETE FROM payment_message_refs')
                rejected=subprocess.run(cli+['fresh_chain'],input=migration.read_text(),text=True,env=env,capture_output=True,timeout=30)
                assert rejected.returncode!=0 and 'existing API invoice references require transfer' in rejected.stderr,rejected.stderr
                assert sql('fresh_chain','SELECT * FROM payment_attempts')==before_attempt
                assert all(sql('fresh_chain','SHOW CREATE TABLE '+table)==definition for table,definition in before_defs.items())
                checks.append('construction_refuses_historical_invoice_references_without_mutation')
                sql('fresh_chain','DELETE FROM payment_message_refs; DELETE FROM payment_attempts; DELETE FROM pays')
            try:
                sql('fresh_chain',migration.read_text())
            except subprocess.CalledProcessError as error:
                raise AssertionError((migration.name,error.stderr)) from error
        sql('fresh_single',(ROOT/'database/current-schema.sql').read_text())
        old=set(sql('fresh_chain','SHOW TABLES').splitlines())
        new=set(sql('fresh_single','SHOW TABLES').splitlines())
        assert RETIRED<=old and new==old-RETIRED,(old-new,new-old)
        checks.append('only_retired_tables_omitted')
        for db in ('fresh_chain','fresh_single'):
            assert sql(db,"SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND ((TABLE_NAME='bc_jobs' AND COLUMN_NAME='offset') OR (TABLE_NAME='bc_messages' AND COLUMN_NAME='id')) ORDER BY TABLE_NAME").splitlines()==['bigint(20) unsigned']*2
            assert sql(db,"SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND ((TABLE_NAME='send_list' AND COLUMN_NAME='offset') OR (TABLE_NAME='users' AND COLUMN_NAME='id')) ORDER BY TABLE_NAME").splitlines()==['int(11)']*2
        checks.append('message_action_cursor_matches_full_unsigned_message_identity')
        for table in sorted(new):
            def definition(db):
                return re.sub(r' AUTO_INCREMENT=[0-9]+','',sql(db,'SHOW CREATE TABLE `'+table+'`'))
            assert definition('fresh_chain')==definition('fresh_single'),table
            assert sql('fresh_single','SELECT COUNT(*) FROM `'+table+'`')=='0',table
            checks.append('empty_and_exact:'+table)
        assert sql('fresh_single',"SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='service_operations' AND COLUMN_NAME='historical_pay_id'")=='0'
        assert sql('fresh_single',"SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payment_followup_attempts' AND COLUMN_NAME IN ('ack_chat_id','ack_message_id')")=='2'
        assert sql('fresh_single',"SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='payment_followup_attempts' AND CONSTRAINT_NAME='ck_followup_attempt_ack_identity'")=='1'
        checks.append('followup_attempt_identity_schema')

        assert sql('fresh_single',"SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='userid' AND NON_UNIQUE=0")!='0'
        checks+=['no_historical_funding_column','unique_wallet_owner']
        retired_columns=['review_notified_at', 'evidence_review_claim_token', 'evidence_review_claimed_at', 'evidence_report_digest', 'evidence_fulfillment_state', 'evidence_fulfillment_payload','review_archived_at','review_archived_by','evidence_invoice_chat_id','evidence_invoice_message_id','evidence_invoice_digest']
        for column in retired_columns:
            assert sql('fresh_single',"SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payment_attempts' AND COLUMN_NAME='"+column+"'")== '0',column
            checks.append('retired_payment_column:'+column)
        for table,columns in {
            'gift_list':['server_offset'],
            'server_categories':['server_id','step'],
            'server_config':['port_type','reality','ip','sni','header_type','request_header','response_header','security','tlsSettings'],
            'server_plans':['dest','serverNames','spiderX','limitip','rahgozar','flow','custom_path','custom_port','custom_sni','fileid','acount','protocol','type','pic','step'],
        }.items():
            for column in columns:
                assert sql('fresh_single',"SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='"+table+"' AND COLUMN_NAME='"+column+"'")=='0',(table,column)
                checks.append('retired_native_column:'+table+'.'+column)
        for column in ('pay_row_id','attempt_id','pay_type','pay_fingerprint'):
            assert sql('fresh_single',"SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payment_delivery_intents' AND COLUMN_NAME='"+column+"'")=='0'
            checks.append('retired_delivery_column:'+column)
        for table,columns in [('payment_delivery_intents',['part_count','order_id']),
                              ('payment_delivery_attempts',['part_no','claim_state'])]:
            for column in columns:
                assert sql('fresh_single',"SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='"+table+"' AND COLUMN_NAME='"+column+"'")=='NO'
                checks.append('required_delivery_column:'+table+'.'+column)
        path=work/'fresh.php';path.write_text(PHP)
        for mode in ('strict','off'):
            p=subprocess.run(['php',str(path),str(ROOT),str(work/'mysql.sock'),mode],
                env=env,capture_output=True,text=True,timeout=20)
            assert p.returncode==0 and p.stdout=='PASS' and not p.stderr,(p.stdout,p.stderr)
            checks.append(mode+':current_schema_gates')
            p=subprocess.run(['php',str(path),str(ROOT),str(work/'mysql.sock'),mode,'settings_menu'],
                env=env,capture_output=True,text=True,timeout=20)
            assert p.returncode==0 and p.stdout=='PASS' and not p.stderr,(p.stdout,p.stderr)
            checks.append(mode+':fresh_and_partial_settings_menus_and_current_toggle_routes')
        for table,key,columns in (
            ('payment_delivery_intents','uq_delivery_effect','effect_id'),
            ('payment_delivery_intents','uq_delivery_service_ordinal','service_operation_id,ordinal'),
            ('payment_delivery_attempts','uq_delivery_attempt_no','intent_id,attempt_no'),
            ('payment_delivery_attempts','uq_delivery_dispatch_token','dispatch_token'),
        ):
            sql('fresh_single',f'ALTER TABLE {table} ADD INDEX fixture_fk ({columns}); ALTER TABLE {table} DROP INDEX {key}')
            for mode in ('strict','off'):
                p=subprocess.run(['php',str(path),str(ROOT),str(work/'mysql.sock'),mode,'missing_delivery_contract'],
                    env=env,capture_output=True,text=True,timeout=20)
                assert p.returncode==0 and p.stdout=='REJECTED' and not p.stderr,(p.stdout,p.stderr)
                checks.append(mode+':missing_'+key+'_denied')
            sql('fresh_single',f'ALTER TABLE {table} ADD UNIQUE INDEX {key} ({columns}); ALTER TABLE {table} DROP INDEX fixture_fk')
        sql('fresh_single','ALTER TABLE payment_delivery_intents DROP CONSTRAINT ck_delivery_source_v2')
        for mode in ('strict','off'):
            p=subprocess.run(['php',str(path),str(ROOT),str(work/'mysql.sock'),mode,'missing_delivery_contract'],
                env=env,capture_output=True,text=True,timeout=20)
            assert p.returncode==0 and p.stdout=='REJECTED' and not p.stderr,(p.stdout,p.stderr)
            checks.append(mode+':missing_delivery_source_constraint_denied')
        for table,key,columns in (
            ('payment_followup_intents','uq_followup_wallet_key','wallet_entry_id,intent_key'),
            ('payment_followup_intents','uq_followup_operational_key','operational_ref,intent_key'),
            ('payment_followup_attempts','uq_followup_attempt_no','intent_id,attempt_no'),
        ):
            sql('fresh_single',f'ALTER TABLE {table} ADD INDEX fixture_fk ({columns}); ALTER TABLE {table} DROP INDEX {key}')
            for mode in ('strict','off'):
                p=subprocess.run(['php',str(path),str(ROOT),str(work/'mysql.sock'),mode,'missing_followup_index'],
                    env=env,capture_output=True,text=True,timeout=20)
                assert p.returncode==0 and p.stdout=='REJECTED' and not p.stderr,(p.stdout,p.stderr)
                checks.append(mode+':missing_'+key+'_denied')
            sql('fresh_single',f'ALTER TABLE {table} ADD UNIQUE INDEX {key} ({columns}); ALTER TABLE {table} DROP INDEX fixture_fk')
        sql('fresh_single','ALTER TABLE payment_followup_attempts DROP CONSTRAINT ck_followup_attempt_ack_identity')
        for mode in ('strict','off'):
            p=subprocess.run(['php',str(path),str(ROOT),str(work/'mysql.sock'),mode,'missing_followup_index'],
                env=env,capture_output=True,text=True,timeout=20)
            assert p.returncode==0 and p.stdout=='REJECTED' and not p.stderr,(p.stdout,p.stderr)
            checks.append(mode+':missing_followup_ack_constraint_denied')
        sql('fresh_single','ALTER TABLE payment_followup_attempts DROP COLUMN ack_message_id')
        for mode in ('strict','off'):
            p=subprocess.run(['php',str(path),str(ROOT),str(work/'mysql.sock'),mode,'missing_followup_index'],
                env=env,capture_output=True,text=True,timeout=20)
            assert p.returncode==0 and p.stdout=='REJECTED' and not p.stderr,(p.stdout,p.stderr)
            checks.append(mode+':missing_followup_ack_column_denied')
    result=dict(complete=True,status='PASS',cases=len(checks),tables=len(new),
                migrations=len(migrations),checks=checks,external_requests=0)
    if args.report:args.report.write_text(json.dumps(result,indent=2)+'\n')
    print(json.dumps(result))
if __name__=='__main__':main()
