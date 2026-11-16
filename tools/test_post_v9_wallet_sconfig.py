#!/usr/bin/env python3
"""Issue #26: current Checkout.php RENEW_SCONFIG Wallet ordering on disposable MariaDB.

An external Native spy uses a second SQL session to prove Wallet debit and
original processing claim were COMMITTED before the simulated Native call.
The real customer route, payment locking and final audit run unmodified.
"""
from __future__ import annotations

import argparse
import json
import subprocess
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path

import test_access_state as owned
import test_v8_native as purchase
import test_post_v9_wallet_checkout as checkout
import test_post_v9_scoped_api_pg as api

ROOT = Path(__file__).resolve().parents[1]

# Only external Native transport is replaced. All real finance/service route
# ordering and SQL effects execute against the isolated current-source bot.
NATIVE_SPY = r'''
function renewConfigOnServer($connection,$serverId,$inboundId,$uuid,$remark,
                             $days,$volume,$isMarzban,?int $payId=null) {
    $db=$GLOBALS['connection'];$spec=$GLOBALS['spec'];
    $tx=$db->query('SELECT @@in_transaction AS tx')->fetch_assoc()['tx']??-1;
    $other=new mysqli('localhost','root','',$GLOBALS['owned_db'],0,$GLOBALS['owned_socket']);
    $stmt=$other->prepare('SELECT state,price FROM pays WHERE id=?');
    $stmt->bind_param('i',$payId);$stmt->execute();
    $observed=$stmt->get_result()->fetch_assoc();$stmt->close();
    $wallet=$other->query('SELECT wallet FROM users WHERE userid=1001')->fetch_assoc()['wallet'];
    $other->close();
    $GLOBALS['events'][]=['event'=>'NATIVE_RENEW','tx'=>(int)$tx,
        'observed_state'=>$observed['state']??null,'wallet'=>(int)$wallet,
        'pay_id'=>$payId,'days'=>$days,'volume'=>$volume,
        'remark'=>$remark,'server'=>$serverId,'marzban'=>$isMarzban];
    if ((int)$tx!==0 || ($observed['state']??null)!=='processing_wallet' ||
        (int)$wallet!==(int)($spec['expected_wallet']??49900))
        throw new RuntimeException('fixture_native_before_durable_wallet');
    if (($spec['remote_result']??'')==='unknown')
        throw new RuntimeException('fixture_native_ack_unknown');
    if (($spec['remote_result']??'')==='rejected')
        return (object)['success'=>false,'verified'=>false,
            'uncertain'=>false,'msg'=>'fixture_native_rejected'];
    return (object)['success'=>true,'verified'=>true,
        'applied_expire'=>time()+86400*(int)$days,
        'applied_bytes'=>1073741824*(int)$volume];
}
'''

PHP = checkout.PHP.replace(
    "require_once $root.'/src/Purchase/Core.php';",
    NATIVE_SPY+"\nrequire_once $root.'/src/Purchase/Core.php';",1)
PHP = PHP.replace(
    "        if ($hook==='change_plan') {",
    "        if ($hook==='change_panel') {\n"
    "            $this->query(\"UPDATE server_config SET panel_url='https://changed-fixture.invalid' WHERE id=2\");\n"
    "        } elseif ($hook==='ambiguous_commit') {\n"
    "            throw new RuntimeException('fixture_commit_ack_lost_after_commit');\n"
    "        } elseif ($hook==='crash_after_claim') {\n"
    "            exit(0); // A dead worker cannot dispatch Native.\n"
    "        } elseif ($hook==='change_plan') {",1)
assert PHP != checkout.PHP


def check() -> list[str]:
    checks=[]
    with owned.owned_database() as (work,env):
        purchase.init(work,env,'http://127.0.0.1:9')
        sql=lambda command:checkout.sql(work,env,command)
        for name in ('001_marzban_panel_group_id.sql','003_payment_attempts.sql',
                     '007_service_effects.sql'):
            sql((ROOT/'migrations/post-v9'/name).read_text())
        sql("DELETE FROM setting WHERE type='BOT_STATES'; "
            "INSERT INTO setting(type,value) VALUES "
            "('BOT_STATES','{\"walletState\":\"on\",\"gatewayAudience\":{\"wallet\":\"all\"}}')")
        driver=work/'wallet-sconfig-real-checkout.php'
        driver.write_text(PHP)
        def newpay(name,*,type='RENEW_SCONFIG',price=100,description=None):
            if description is None:
                description=json.dumps({'uuid':'fixture-uuid','remark':'fixture_user',
                                        'server_id':2,'marzban':True},separators=(',',':'))
            sql('UPDATE users SET wallet=50000 WHERE userid=1001')
            sql('UPDATE server_plans SET active=1,days=30,volume=2 WHERE id=7')
            sql("INSERT INTO pays(hash_id,user_id,type,plan_id,server_id,volume,"
                "day,price,description,request_date,state) VALUES ("
                +','.join([api.quote_sql(name), '1001',api.quote_sql(type),
                            '7','2','0','0',str(price),api.quote_sql(description),
                            "UNIX_TIMESTAMP()","'pending'"])+')')
            return int(sql('SELECT id FROM pays WHERE hash_id='+api.quote_sql(name)))
        def route(name,**kwargs):
            return checkout.route(work,env,driver,hash=name,**kwargs)
        def state(pid):
            return sql('SELECT state FROM pays WHERE id='+str(pid))
        def amount():
            return int(sql('SELECT wallet FROM users WHERE userid=1001'))
        def audit():
            return int(sql('SELECT COUNT(*) FROM increase_order'))
        def native(events):
            return [x for x in events if x.get('event')=='NATIVE_RENEW']

        good=newpay('wallet-renew-happy')
        before=audit()
        successful=route('wallet-renew-happy')
        assert state(good)=='paid_with_wallet' and amount()==49900
        assert audit()==before+1 and len(native(successful))==1,native(successful)
        observation=native(successful)[0]
        assert observation['tx']==0 and observation['observed_state']=='processing_wallet'
        assert observation['wallet']==49900 and observation['pay_id']==good
        assert observation['days']==30 and observation['volume']==2
        assert sql('SELECT day,volume,amount FROM increase_order ORDER BY id DESC LIMIT 1')=='30\t2\t100'
        checks.append('renew_native_only_after_committed_wallet_claim_and_audit_atomic')
        replay=route('wallet-renew-happy')
        assert len(native(replay))==0 and audit()==before+1 and amount()==49900
        checks.append('duplicate_wallet_button_no_second_native_or_debit')

        insufficient=newpay('wallet-renew-insufficient',price=90000)
        events=route('wallet-renew-insufficient')
        assert state(insufficient)=='pending' and amount()==50000 and len(native(events))==0
        checks.append('insufficient_wallet_no_durable_claim_or_native')

        invalid=newpay('wallet-renew-invalid',description='{}')
        events=route('wallet-renew-invalid')
        assert state(invalid)=='pending' and amount()==50000 and not native(events)
        checks.append('invalid_original_external_subject_fails_before_wallet_debit')

        failed=newpay('wallet-renew-unknown')
        previous=audit()
        events=route('wallet-renew-unknown',remote_result='unknown')
        assert state(failed)=='processing_wallet' and amount()==49900 and audit()==previous
        assert len(native(events))==1 and native(events)[0]['tx']==0
        retry=route('wallet-renew-unknown')
        assert not native(retry) and amount()==49900 and audit()==previous
        checks.append('unknown_native_ack_holds_original_debit_without_replay')

        rejected=newpay('wallet-renew-rejected')
        previous=audit()
        events=route('wallet-renew-rejected',remote_result='rejected')
        assert state(rejected)=='processing_wallet' and amount()==49900 and audit()==previous
        assert len(native(events))==1
        checks.append('provider_rejection_after_financial_claim_is_operator_review_only')

        broken=newpay('wallet-renew-audit-fails')
        previous=audit()
        sql("CREATE TRIGGER wallet_sconfig_audit_fail BEFORE INSERT ON increase_order "
            "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture_audit_fail'")
        events=route('wallet-renew-audit-fails')
        assert state(broken)=='processing_wallet' and amount()==49900 and audit()==previous
        assert len(native(events))==1 and native(events)[0]['tx']==0
        retry=route('wallet-renew-audit-fails')
        assert not native(retry) and amount()==49900 and audit()==previous
        sql('DROP TRIGGER wallet_sconfig_audit_fail')
        checks.append('sql_audit_failure_after_native_keeps_debit_held_and_prevents_retry')

        ambiguous=newpay('wallet-renew-ambiguous')
        previous=audit()
        events=route('wallet-renew-ambiguous',after_wallet_commit='ambiguous_commit')
        assert state(ambiguous)=='processing_wallet' and amount()==49900
        assert not native(events) and audit()==previous
        retry=route('wallet-renew-ambiguous')
        assert not native(retry) and amount()==49900
        checks.append('commit_ack_unknown_after_actual_debit_does_not_dispatch_or_rededuct')

        crashed=newpay('wallet-renew-crashed')
        previous=audit()
        proc=subprocess.run(['php','-n','-d','extension=mysqlnd','-d','extension=mysqli',
            '-d','disable_functions=curl_init,curl_exec,exec,shell_exec,system,passthru,popen,proc_open,mail',
            str(driver),str(ROOT),str(work/'mysql.sock'),checkout.DB,
            json.dumps({'hash':'wallet-renew-crashed','after_wallet_commit':'crash_after_claim'})],
            cwd=work,env=env,capture_output=True,text=True,timeout=35)
        assert proc.returncode==0 and proc.stdout=='',proc.stderr[-400:]
        assert state(crashed)=='processing_wallet' and amount()==49900 and audit()==previous
        retry=route('wallet-renew-crashed')
        assert not native(retry) and amount()==49900
        checks.append('worker_crash_after_durable_claim_replay_is_financially_inert')

        concurrent=newpay('wallet-renew-concurrent')
        previous=audit()
        with ThreadPoolExecutor(max_workers=2) as pool:
            futures=[pool.submit(route,'wallet-renew-concurrent') for _ in range(2)]
            both=[f.result() for f in futures]
        assert state(concurrent)=='paid_with_wallet' and amount()==49900
        assert audit()==previous+1 and sum(len(native(events)) for events in both)==1
        checks.append('simultaneous_buttons_emit_only_one_financial_and_native_operation')

        moved=newpay('wallet-renew-repointed-panel')
        previous=audit()
        events=route('wallet-renew-repointed-panel',after_wallet_commit='change_panel')
        assert state(moved)=='processing_wallet' and amount()==49900
        assert not native(events) and audit()==previous
        checks.append('changed_native_panel_after_wallet_hold_never_receives_original_edit')
        sql("UPDATE server_config SET panel_url='http://127.0.0.1:9' WHERE id=2")

        changed=newpay('wallet-renew-plan-changed')
        previous=audit()
        events=route('wallet-renew-plan-changed',after_wallet_commit='change_plan')
        assert state(changed)=='paid_with_wallet' and audit()==previous+1
        assert len(native(events))==1 and native(events)[0]['days']==30
        assert sql('SELECT day,volume FROM increase_order ORDER BY id DESC LIMIT 1')=='30\t2'
        assert sql('SELECT days,volume FROM server_plans WHERE id=7')=='15\t1'
        checks.append('post_commit_plan_change_never_changes_original_paid_renewal')

    return checks


def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('--run-disposable',action='store_true',required=True)
    parser.add_argument('--report',type=Path)
    args=parser.parse_args()
    checks=check()
    result={'complete':True,'cases':len(checks),'checks':checks,
            'real_php_source':True,'disposable_mariadb':True,
            'native_transport_spy':True,'production_mutations':0}
    if args.report:args.report.write_text(json.dumps(result,indent=2)+'\n')
    print(json.dumps(result))

if __name__=='__main__':main()
