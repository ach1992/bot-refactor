#!/usr/bin/env python3
"""Owner-only post-crash BUY_SUB review: real PHP + isolated MariaDB.

Actual admin callback module, source inspector, and only synthetic remote GET.
All financial/provider writes, app customer data and live Telegram are banned.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import subprocess
import time
from pathlib import Path

import test_access_state as owned
import test_v6_native as native
import test_v8_native as purchase
import test_post_v9_bank_buy as bank

ROOT=Path(__file__).resolve().parents[1]
DB=purchase.SIDE[1]
DRIVER=r"""<?php
error_reporting(E_ALL);
set_error_handler(static function($level,$message) {
    if ($level===E_DEPRECATED) return true;
    throw new ErrorException($message,0,$level);
});
$_SERVER['SCRIPT_FILENAME']=__FILE__;
$root=$argv[1];$socket=$argv[2];$dbName=$argv[3];
$spec=json_decode($argv[4],true,512,JSON_THROW_ON_ERROR);
$connection=new mysqli('localhost','root','',$dbName,0,$socket);
$connection->set_charset('utf8mb4');
if (!empty($spec['nonthrowing'])) mysqli_report(MYSQLI_REPORT_OFF);
$GLOBALS['events']=[];$GLOBALS['spec']=$spec;
$admin=9001;$from_id=(int)($spec['actor']??9001);
$chat_id=(int)($spec['chat']??9001);
$GLOBALS['chat_id']=$chat_id;
$callbackId='fixture-callback';$message_id=456;
$data=(string)($spec['callback']??'bankReviewInbox');
$userInfo=['isAdmin'=>false];$paymentKeys=[];
function answerCallbackQuery(...$args) {
    $GLOBALS['events'][]=['event'=>'ACK','text'=>(string)($args[1]??'')];
}
function editText(...$args) {
    $GLOBALS['events'][]=['event'=>'EDIT','text'=>(string)($args[1]??''),
        'buttons'=>json_decode((string)($args[2]??''),true)];
    return (object)['ok'=>($GLOBALS['spec']['edit_failure']??false)!==true];
}
function sendMessage(...$args) {
    if (($args[3]??null)!==9001 || $GLOBALS['chat_id']!==9001)
        throw new RuntimeException('forbidden_nonowner_send');
    $GLOBALS['events'][]=['event'=>'SEND','text'=>(string)($args[0]??''),
        'buttons'=>json_decode((string)($args[1]??''),true)];
    return (object)['ok'=>true];
}
function createServerAccount(...$args) {throw new RuntimeException('forbidden_remote_post');}
function editMarzbanConfig(...$args) {throw new RuntimeException('forbidden_remote_put');}
function addMarzbanUser(...$args) {throw new RuntimeException('forbidden_remote_post');}
// A source-only callback spy. Separate transport tests use the actual curl
// adapter against an isolated authenticated loopback HTTP fixture.
function achbotRecoveryStrictAccountGet($endpoint,$authUser,$authPass,$name) {
    if (!str_starts_with($endpoint,'http://127.0.0.1:') ||
        $authUser!== 'fixture-admin' || $authPass!== 'fixture-password')
        throw new RuntimeException('unpinned_remote_identity');
    $server=2;
    $GLOBALS['events'][]=['event'=>'GET','server'=>$server,
        'username'=>$name,'pinned_endpoint'=>$endpoint];
    $mode=$GLOBALS['spec']['remote_mode']??'match';
    if ($mode==='timeout') throw new RuntimeException('fixture_remote_timeout');
    if ($mode==='missing') return null;
    global $connection;
    $stmt=$connection->prepare("SELECT * FROM service_effects
        WHERE target_server_id=? AND remote_username=? LIMIT 1");
    $stmt->bind_param('is',$server,$name);
    $stmt->execute();
    $effect=$stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$effect) return null;
    if ($mode==='drift_during_get') {
        $connection->query("UPDATE server_config SET panel_url='https://changed.invalid'
            WHERE id=".(int)$server);
    }
    if ($mode==='terminal_during_get') {
        $connection->query("UPDATE pays SET state='auto_cart_approved'
            WHERE id=".(int)$effect['pay_row_id']);
    }
    if ($mode==='drop_effects_during_get') {
        $connection->query('DROP TABLE service_effects');
    }
    $bytes=(int)$effect['requested_bytes'];
    $expiry=(int)$effect['requested_expire'];
    $remote=(object)[
        'username'=>$name,
        'note'=>'achbot:v1:'.(string)$effect['operation_id'],
        'expire'=>$expiry,'data_limit'=>$bytes,'status'=>'active',
    ];
    if ($mode==='wrong_note') $remote->note='someone_else';
    if ($mode==='float_bytes') $remote->data_limit=$bytes+0.5;
    if ($mode==='float_expiry') $remote->expire=$expiry+0.5;
    if ($mode==='bad_status') $remote->status='disabled';
    if ($mode==='overflow') $remote->data_limit='99999999999999999999999';
    if ($mode==='exponent') $remote->data_limit=(string)$bytes.'e0';
    return $remote;
}
$op=(string)($spec['op']??'route');
if ($op==='route') {
    require $root.'/src/Finance/Routes/ManualReceipts.php';
} else {
    require_once $root.'/src/Finance/RecoveryInspection.php';
    $id=(int)($spec['pay']??0);$n=(int)($spec['ordinal']??1);
    $value=match($op) {
        'list'=>achbotRecoveryPendingCases($connection,(int)($spec['cursor']??0)),
        'case'=>achbotRecoveryCase($connection,$id,(int)($spec['cursor']??0)),
        'inspect'=>achbotRecoveryInspectLine($connection,$id,$n,
            static fn(string $url,string $u,string $p,string $name) =>
                achbotRecoveryStrictAccountGet($url,$u,$p,$name)),
        default=>throw new RuntimeException('unknown_fixture_mode')
    };
}
echo json_encode(['value'=>$value??null,'events'=>$GLOBALS['events']],
    JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
"""

def sql(work,env,statement):
    return native.sql(work,env,statement,DB).strip()

def call(work,env,driver,**spec):
    cmd=['php','-n','-d','extension=mysqlnd','-d','extension=mysqli',
         '-d','allow_url_fopen=0','-d','display_errors=0',
         '-d','disable_functions=curl_init,curl_exec,exec,shell_exec,system,passthru,popen,proc_open,mail',
         str(driver),str(ROOT),str(work/'mysql.sock'),DB,json.dumps(spec)]
    r=subprocess.run(cmd,cwd=work,env=env,capture_output=True,text=True,timeout=25)
    if r.returncode!=0:
        raise RuntimeError(f'PHP native recovery failed {r.returncode}: {r.stderr[-1700:]}')
    try:return json.loads(r.stdout)
    except ValueError as e:raise RuntimeError('invalid PHP report '+r.stdout[-500:]) from e

def effects(work,env,payid):
    return sql(work,env,
        f"SELECT GROUP_CONCAT(CONCAT(ordinal,':',state,':',COALESCE(order_id,0)) ORDER BY ordinal) FROM service_effects WHERE pay_row_id={payid}")

def unchanged(work,env,payid):
    return (
        sql(work,env,f"SELECT state FROM pays WHERE id={payid}"),
        sql(work,env,"SELECT wallet FROM users WHERE userid=1001"),
        sql(work,env,"SELECT COUNT(*) FROM orders_list"),
        effects(work,env,payid),
    )

def create_case(work,env,key,*,state='processing',count=1,number_of_effects=1,
                effect_state='dispatching',method='bank_transfer'):
    pid=bank.seed(work,env,key,count=count)
    sql(work,env,f"UPDATE pays SET state='{state}' WHERE id={pid}")
    if method:
        # Ledger details themselves are not settlement evidence.
        sql(work,env,
            "INSERT INTO payment_attempts (pay_row_id,provider,order_hash,"
            "quote_amount,quote_currency,local_amount_toman) VALUES "
            f"({pid},'{method}','{key}',101,'toman',101)")
    panel=sql(work,env,"SELECT panel_url FROM server_config WHERE id=2")
    endpoint=hashlib.sha256(panel.rstrip('/').encode()).hexdigest()
    group=sql(work,env,"SELECT COALESCE(panel_group_id,'NULL') FROM server_info WHERE id=2")
    groupid=None if group=='NULL' else int(group)
    targetkey=f'group:{groupid}' if groupid is not None else 'server:2'
    for ordinal in range(1,number_of_effects+1):
        operation=f'{pid:016x}{ordinal:016x}'
        name=f'fx_{pid}_{ordinal}'
        expiry=int(time.time())+86400*9
        quota=8*1073741824
        data=[pid,ordinal,7,2,groupid,targetkey,endpoint,name,expiry,quota]
        fingerprint=hashlib.sha256(json.dumps(data,separators=(',',':')).encode()).hexdigest()
        sql(work,env,"INSERT INTO service_effects (pay_row_id,ordinal,operation_id,"
            "effect_kind,target_server_id,target_group_id,target_key,endpoint_sha256,"
            "plan_id,remote_username,requested_expire,requested_bytes,request_sha256,state)"
            " VALUES ("+','.join([
                str(pid),str(ordinal),f"'{operation}'","'create'","2",
                'NULL' if groupid is None else str(groupid),
                f"'{targetkey}'",f"'{endpoint}'","7",f"'{name}'",
                str(expiry),str(quota),f"'{fingerprint}'",f"'{effect_state}'"
            ])+")")
    return pid

def events(result,name):
    return [e for e in result['events'] if e['event']==name]

def buttons(result):
    edits=events(result,'EDIT')
    if not edits:return []
    return [button for row in edits[-1]['buttons'].get('inline_keyboard',[])
            for button in row]

def check():
    cases=[]
    with owned.owned_database() as (work,env):
        purchase.init(work,env,'http://127.0.0.1:9')
        for name in ('001_marzban_panel_group_id.sql',
                     '003_payment_attempts.sql','007_service_effects.sql'):
            sql(work,env,(ROOT/'migrations/post-v9'/name).read_text())
        driver=work/'recovery-driver.php'
        driver.write_text(DRIVER)
        pay=create_case(work,env,'revinspector')
        baseline=unchanged(work,env,pay)
        result=call(work,env,driver,op='case',pay=pay)
        assert result['value']['pay_id']==pay and result['value']['requested_accounts']==1
        assert result['value']['method']=='bank_transfer'
        assert result['value']['lines'][0]['state']=='dispatching'
        assert unchanged(work,env,pay)==baseline
        cases.append('unresolved_bank_case_reads_exact_local_identity_without_sql_writes')

        held=create_case(work,env,'wallet-held-inbox',state='processing_wallet',
                         number_of_effects=0,method=None)
        sql(work,env,"INSERT INTO service_effects "
            "(pay_row_id,ordinal,operation_id,effect_kind,target_server_id,"
            "target_key,endpoint_sha256,plan_id,remote_username,"
            "requested_expire,requested_bytes,request_sha256,reserved_count,state) "
            f"VALUES ({held},0,REPEAT('c',32),'wallet_hold',2,'server:2',"
            "REPEAT('a',64),7,'#wallet_hold_fixture',0,0,REPEAT('b',64),1,'reserved')")
        held_before=unchanged(work,env,held)
        list_now=call(work,env,driver,op='list')
        assert any(int(x['id'])==held for x in list_now['value'])
        wallet_case=call(work,env,driver,op='case',pay=held)
        assert wallet_case['value']['payment_state']=='processing_wallet'
        assert wallet_case['value']['method']=='wallet_held'
        assert wallet_case['value']['wallet_hold_state']=='reserved'
        assert wallet_case['value']['wallet_capacity_reserved']==1
        assert wallet_case['value']['recorded_accounts']==0
        assert wallet_case['value']['journal_complete'] is False
        assert not events(wallet_case,'GET')
        wallet_not_created=call(work,env,driver,op='inspect',pay=held,ordinal=1)
        assert wallet_not_created['value']['status']=='target_or_identity_changed'
        assert not events(wallet_not_created,'GET')
        wallet_panel=call(work,env,driver,op='route',callback=f'bankReviewCase_{held}')
        assert not events(wallet_panel,'GET')
        assert 'ظرفیت رزروشده' in str(wallet_panel['events'])
        assert unchanged(work,env,held)==held_before
        cases.append('owner_private_wallet_debit_capacity_hold_visible_no_unissued_get')

        matched=call(work,env,driver,op='inspect',pay=pay)
        assert matched['value']['status']=='matching_observation',matched
        assert matched['value']['remote_gets']==1 and len(events(matched,'GET'))==1
        assert matched['value']['settlement_proven'] is False
        assert unchanged(work,env,pay)==baseline
        cases.append('strict_one_account_get_only_matching_observation_no_replay')

        for mode,expected in [('missing','remote_unavailable'),
                              ('timeout','remote_unavailable'),
                              ('wrong_note','remote_mismatch'),
                              ('float_bytes','remote_mismatch'),
                              ('float_expiry','remote_mismatch'),
                              ('bad_status','remote_mismatch'),
                              ('overflow','remote_mismatch'),
                              ('exponent','remote_mismatch')]:
            check_result=call(work,env,driver,op='inspect',pay=pay,remote_mode=mode)
            assert check_result['value']['status']==expected,(mode,check_result)
            assert len(events(check_result,'GET'))==1,(mode,check_result)
            assert unchanged(work,env,pay)==baseline
            cases.append('no_financial_write_remote_'+mode)

        invalid=call(work,env,driver,op='inspect',pay=pay,ordinal=2)
        assert invalid['value']['status']=='target_or_identity_changed'
        assert not events(invalid,'GET')
        cases.append('missing_ordinal_never_infers_remote_absence_or_gets')

        for actor,chat in [(9002,9001),(9001,7777),(9002,7777)]:
            bad=call(work,env,driver,op='route',callback=f'bankReviewRead_{pay}_1_0',
                     actor=actor,chat=chat)
            assert not events(bad,'GET') and not events(bad,'EDIT')
            assert events(bad,'ACK')
            assert unchanged(work,env,pay)==baseline
            cases.append('nonowner_or_nonprivate_inspection_denied_'+str(actor)+'_'+str(chat))

        view=call(work,env,driver,op='route',callback=f'bankReviewRead_{pay}_1_0')
        assert len(events(view,'GET'))==1,view
        assert [e['event'] for e in view['events']]==['ACK','GET','EDIT'],view
        assert events(view,'EDIT') and 'اثبات وصول وجه' in events(view,'EDIT')[0]['text']
        assert unchanged(work,env,pay)==baseline
        cases.append('owner_private_readback_button_real_callback_no_sql_mutations')

        # A damaged journal fingerprint must not select any remote destination.
        saved_sha=sql(work,env,f"SELECT request_sha256 FROM service_effects WHERE pay_row_id={pay} LIMIT 1")
        sql(work,env,f"UPDATE service_effects SET request_sha256=REPEAT('0',64) WHERE pay_row_id={pay}")
        corrupted=call(work,env,driver,op='inspect',pay=pay)
        assert corrupted['value']['status']=='target_or_identity_changed'
        assert not events(corrupted,'GET')
        sql(work,env,f"UPDATE service_effects SET request_sha256='{saved_sha}' WHERE pay_row_id={pay}")
        cases.append('corrupted_operation_fingerprint_blocks_remote_get')

        # F2: the original payment reservation constraints must be checked
        # before even contacting the selected remote account.
        sql(work,env,"UPDATE server_plans SET inbound_id=9 WHERE id=7")
        incompatible=call(work,env,driver,op='inspect',pay=pay)
        assert incompatible['value']['status']=='target_or_identity_changed'
        assert not events(incompatible,'GET')
        sql(work,env,"UPDATE server_plans SET inbound_id=0 WHERE id=7")
        cases.append('changed_plan_inbound_rejected_before_any_remote_get')

        sql(work,env,f"UPDATE pays SET agent_count=-1 WHERE id={pay}")
        bad_count=call(work,env,driver,op='inspect',pay=pay)
        assert not events(bad_count,'GET')
        sql(work,env,f"UPDATE pays SET agent_count=1 WHERE id={pay}")
        cases.append('invalid_payment_account_count_fails_closed')

        original_bytes=sql(work,env,f"SELECT requested_bytes FROM service_effects WHERE pay_row_id={pay}")
        original_fp=sql(work,env,f"SELECT request_sha256 FROM service_effects WHERE pay_row_id={pay}")
        expiry=int(sql(work,env,f"SELECT requested_expire FROM service_effects WHERE pay_row_id={pay}"))
        zero_bytes_fp=hashlib.sha256(json.dumps(
            [pay,1,7,2,None,'server:2',original_sha if 'original_sha' in locals()
             else hashlib.sha256('http://127.0.0.1:9'.encode()).hexdigest(),
             f'fx_{pay}_1',expiry,0],separators=(',',':')).encode()).hexdigest()
        sql(work,env,f"UPDATE service_effects SET requested_bytes=0,request_sha256='{zero_bytes_fp}' WHERE pay_row_id={pay}")
        zero=call(work,env,driver,op='inspect',pay=pay)
        assert zero['value']['status']=='target_or_identity_changed' and not events(zero,'GET')
        sql(work,env,f"UPDATE service_effects SET requested_bytes={original_bytes},request_sha256='{original_fp}' WHERE pay_row_id={pay}")
        cases.append('zero_quota_with_matching_fingerprint_never_gets')

        # Explicit group identity cannot be derived from the URL.
        sql(work,env,"UPDATE server_info SET panel_group_id=602 WHERE id=2")
        changed_group=call(work,env,driver,op='inspect',pay=pay)
        assert not events(changed_group,'GET')
        assert changed_group['value']['status']=='target_or_identity_changed'
        sql(work,env,"UPDATE server_info SET panel_group_id=NULL WHERE id=2")
        cases.append('logical_group_drift_blocks_remote_get')

        # A remote target that changes before the GET is a strict no-contact fence.
        sql(work,env,"UPDATE server_config SET panel_url='https://changed.invalid' WHERE id=2")
        changed=call(work,env,driver,op='route',callback=f'bankReviewRead_{pay}_1_0')
        assert not events(changed,'GET')
        assert 'قابل احراز نیست' in events(changed,'EDIT')[0]['text']
        cases.append('endpoint_drift_fences_remote_get_without_guessing')
        # Restore only the known synthetic fixture value.
        panel=sql(work,env,"SELECT panel_url FROM server_config WHERE id=2")
        assert panel=='https://changed.invalid'
        original='http://127.0.0.1:9'
        sql(work,env,f"UPDATE server_config SET panel_url='{original}' WHERE id=2")
        # Intentionally restore the exact recorded endpoint from ledger equality below
        original_sha=sql(work,env,f"SELECT endpoint_sha256 FROM service_effects WHERE pay_row_id={pay} LIMIT 1")
        assert hashlib.sha256(original.rstrip('/').encode()).hexdigest()==original_sha

        during=call(work,env,driver,op='inspect',pay=pay,remote_mode='drift_during_get')
        assert during['value']['status']=='changed_during_inspection',during
        assert len(events(during,'GET'))==1
        cases.append('concurrent_endpoint_change_disqualifies_readback_evidence')
        sql(work,env,f"UPDATE server_config SET panel_url='{original}' WHERE id=2")

        # A 15-account order must not silently hide later ordinal identities.
        big=create_case(work,env,'revbig',count=15,number_of_effects=15)
        first=call(work,env,driver,op='case',pay=big)
        assert len(first['value']['lines'])==12 and first['value']['next_ordinal']==12
        full_page=call(work,env,driver,op='route',callback=f'bankReviewCase_{big}')
        displayed=events(full_page,'EDIT')[0]['text']
        assert len(displayed.encode('utf-16-le'))//2<=4096
        assert all(len(str(b.get('callback_data','')).encode())<=64 for b in buttons(full_page))
        assert len([b for b in buttons(full_page)
                    if str(b.get('callback_data','')).startswith('bankReviewRead_')])==12
        cases.append('twelve_account_page_telegram_length_and_button_bounds')
        second=call(work,env,driver,op='case',pay=big,cursor=12)
        assert [x['ordinal'] for x in second['value']['lines']]==[13,14,15]
        assert second['value']['next_ordinal']==0
        deep=call(work,env,driver,op='route',callback=f'bankReviewRead_{big}_14_12')
        assert len(events(deep,'GET'))==1 and events(deep,'GET')[0]['username'].endswith('_14')
        cases.append('15_line_keyset_pagination_and_exact_late_account_get')

        # F2/F4: reduced account count, sparse journal, and exact operator IDs.
        sql(work,env,f"UPDATE pays SET agent_count=12 WHERE id={big}")
        outside=call(work,env,driver,op='inspect',pay=big,ordinal=14)
        assert outside['value']['status']=='target_or_identity_changed'
        assert not events(outside,'GET')
        sql(work,env,f"UPDATE pays SET agent_count=15 WHERE id={big}")
        cases.append('ordinal_exceeding_mutated_payment_account_count_no_remote_get')

        sparse=create_case(work,env,'sparsejournal',count=15,number_of_effects=2)
        missing=call(work,env,driver,op='case',pay=sparse)
        assert missing['value']['requested_accounts']==15
        assert missing['value']['recorded_accounts']==2
        assert missing['value']['journal_complete'] is False
        shown=call(work,env,driver,op='route',callback=f'bankReviewCase_{sparse}')
        assert 'دفتر عملیات ناقص' in events(shown,'EDIT')[0]['text']
        op=sql(work,env,f"SELECT operation_id FROM service_effects WHERE pay_row_id={sparse} AND ordinal=2")
        assert op in events(shown,'EDIT')[0]['text']
        assert 'شناسه اثر:' in events(shown,'EDIT')[0]['text']
        assert not events(call(work,env,driver,op='route',callback=f'bankReviewCase_{sparse}',
                               actor=9002),'EDIT')
        cases.append('fifteen_requested_two_recorded_clear_owner_private_operation_ids')

        # Distinguish different pending payments without tallying Marzban accounts.
        newest=[create_case(work,env,f'newrecover{i}',number_of_effects=0) for i in range(15)]
        first_list=call(work,env,driver,op='list')
        assert len(first_list['value'])==13
        cursor=int(first_list['value'][11]['id'])
        older=call(work,env,driver,op='list',cursor=cursor)
        assert all(int(x['id'])<cursor for x in older['value'])
        case_empty=call(work,env,driver,op='case',pay=newest[-1])
        assert case_empty['value']['lines']==[]
        cases.append('bank_payment_without_effect_visible_and_13th_case_reachable')

        # A deleted historical order is not proof the remote account vanished.
        sql(work,env,f"UPDATE service_effects SET state='local_recorded',order_id=99887766 WHERE pay_row_id={pay}")
        archived=call(work,env,driver,op='case',pay=pay)
        assert archived['value']['lines'][0]['local_evidence']=='historical_order_missing'
        assert unchanged(work,env,pay)[2]==baseline[2]
        cases.append('deleted_historical_order_preserved_without_inferred_refund')

        # A paid hosted case has an explicit provider label but that alone
        # is not verified merchant funds or permission to provision again.
        hosted=create_case(work,env,'revhosted',state='paid',method='nowpayments')
        host=call(work,env,driver,op='case',pay=hosted)
        assert host['value']['method']=='nowpayments'
        seen=call(work,env,driver,op='inspect',pay=hosted)
        assert seen['value']['status']=='matching_observation'
        assert seen['value']['settlement_proven'] is False
        assert sql(work,env,f'SELECT state FROM pays WHERE id={hosted}')=='paid'
        cases.append('hosted_paid_state_not_assumed_settled_or_mutated')

        inbox=call(work,env,driver,op='route',callback='bankReviewInbox')
        assert not events(inbox,'GET') and events(inbox,'EDIT')
        assert any(str(x.get('callback_data','')).startswith('bankReviewInbox_')
                   for x in buttons(inbox))
        current=call(work,env,driver,op='route',callback=f'bankReviewCase_{big}_12')
        assert len(events(current,'EDIT'))==1 and not events(current,'GET')
        assert any('bankReviewRead_' in str(x.get('callback_data',''))
                   for x in buttons(current))
        assert not events(call(work,env,driver,op='route',callback='bankReviewInbox',
                               actor=9001,chat=2222),'EDIT')
        cases.append('owner_private_full_inbox_page_and_case_ui_navigation')

        # Local status changes do not authorize remote readback of a terminal pay.
        sql(work,env,f"UPDATE pays SET state='auto_cart_approved' WHERE id={pay}")
        terminal=call(work,env,driver,op='inspect',pay=pay)
        assert not events(terminal,'GET') and terminal['value']['status']=='target_or_identity_changed'
        assert call(work,env,driver,op='case',pay=pay)['value'] is None
        cases.append('terminal_payment_not_reattributed_to_pending_case')

        # F3: acknowledge exactly once, even when the case goes terminal
        # between GET and the subsequent owner message render.
        transition=call(work,env,driver,op='route',
            callback=f'bankReviewRead_{hosted}_1_0',remote_mode='terminal_during_get')
        assert len(events(transition,'ACK'))==1 and len(events(transition,'GET'))==1
        assert len(events(transition,'EDIT'))==1
        assert 'در دسترس نیست' in events(transition,'EDIT')[0]['text']
        sql(work,env,f"UPDATE pays SET state='paid' WHERE id={hosted}")
        cases.append('terminal_after_get_owner_sees_uncertainty_once_ack')

        fallback=call(work,env,driver,op='route',
            callback=f'bankReviewRead_{hosted}_1_0',
            remote_mode='timeout',edit_failure=True)
        assert len(events(fallback,'ACK'))==1
        assert len(events(fallback,'GET'))==1
        assert len(events(fallback,'SEND'))==1
        assert 'اثبات وصول وجه' in events(fallback,'SEND')[0]['text'] or (
            'استعلام قابل اتکا' in events(fallback,'SEND')[0]['text'])
        cases.append('telegram_edit_failure_falls_back_to_owner_private_send')

        # Schema uniqueness is part of exact per-line remote selection.
        sql(work,env,"ALTER TABLE service_effects DROP INDEX uq_effect_operation")
        malformed=call(work,env,driver,op='route',callback=f'bankReviewRead_{hosted}_1_0',
                       nonthrowing=True)
        assert not events(malformed,'GET') and len(events(malformed,'EDIT'))==1
        assert events(malformed,'ACK')
        cases.append('missing_unique_effect_index_blocks_remote_selection')

        # Restore only the synthetic uniqueness index to reach a genuine
        # post-GET failure under MYSQLI_REPORT_OFF.
        sql(work,env,"ALTER TABLE service_effects ADD UNIQUE KEY uq_effect_operation (operation_id)")
        postget=call(work,env,driver,op='route',
            callback=f'bankReviewRead_{hosted}_1_0',
            remote_mode='drop_effects_during_get',nonthrowing=True)
        assert len(events(postget,'GET'))==1
        assert len(events(postget,'ACK'))==1
        assert len(events(postget,'EDIT'))==1
        assert 'نتیجهٔ استعلام قابل احراز نیست' in events(postget,'EDIT')[0]['text']
        cases.append('mysqlireport_off_sql_failure_after_get_ends_owner_visible_uncertain')

        # Missing 007 or 003 is a visible operator failure, not empty SUCCESS.
        # The previous post-GET failure intentionally dropped this disposable table.
        missing=call(work,env,driver,op='route',callback=f'bankReviewCase_{big}')
        assert events(missing,'EDIT') and len(events(missing,'ACK'))==1
        assert not events(missing,'GET')
        cases.append('missing_ledger_schema_fails_closed_without_get_or_mutation')

    return {'complete':True,'cases':len(cases),'scenarios':cases,
            'actual_php_admin_route':True,'owned_socket_mariadb':True,
            'max_account_get_per_callback':1,'remote_writes':0,
            'money_mutations':0,'installed_operator_tested':False}

def main():
    ap=argparse.ArgumentParser()
    ap.add_argument('--run-disposable',action='store_true',required=True)
    ap.add_argument('--report',type=Path)
    args=ap.parse_args()
    result=check()
    if args.report:
        args.report.write_text(json.dumps(result,ensure_ascii=False,indent=2)+'\n')
    print(json.dumps(result,ensure_ascii=False))

if __name__=='__main__': main()
