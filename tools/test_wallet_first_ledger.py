#!/usr/bin/env python3
"""Wallet-first ledger: actual PHP/MySQLi and owned socket-only MariaDB.

No bot, external provider, customer data, or installed database is used.
The synthetic fixture uses the current signed users.wallet schema.
"""
from __future__ import annotations

import argparse
from concurrent.futures import ThreadPoolExecutor
import json
from pathlib import Path
import subprocess

import database_fixture as owned

ROOT = Path(__file__).resolve().parents[1]
DB = owned.NAMES[1]
PHP = r'''<?php
declare(strict_types=1);
$mode=$argv[4];
mysqli_report($mode==='strict' ? MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT : MYSQLI_REPORT_OFF);
require $argv[1].'/src/Wallet/Ledger.php';
$db=new mysqli('localhost','root','',$argv[3],0,$argv[2]);
$case=json_decode($argv[5],true,512,JSON_THROW_ON_ERROR);
$results=[];
try {
    if (($case['transaction']??true) && !$db->begin_transaction())
        throw new RuntimeException('fixture_begin_failed');
    foreach ($case['entries'] as $entry) $results[]=achbotWalletApplyLocked($db,...$entry);
    if (($case['rollback']??false)) {
        if (!$db->rollback()) throw new RuntimeException('fixture_rollback_failed');
    } else {
        if (!$db->commit()) throw new RuntimeException('fixture_commit_failed');
        if (($case['lost_ack']??false)) exit(19);
    }
    echo json_encode(['ok'=>true,'entries'=>$results],JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    $db->rollback();
    echo json_encode(['ok'=>false,'reason'=>$e->getMessage()],JSON_THROW_ON_ERROR);
}
'''


def main():
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument('--run-disposable', required=True, action='store_true')
    ap.add_argument('--report', type=Path)
    args = ap.parse_args()
    cases = []
    with owned.owned_database() as (work, env):
        client = ['mariadb', '--no-defaults', '--protocol=SOCKET',
                  '--socket='+str(work/'mysql.sock'), '-uroot', '--batch', '--skip-column-names', DB]
        def sql(query):
            p = subprocess.run(client, input=query, text=True, env=env, check=True,
                               capture_output=True, timeout=15)
            return p.stdout.strip()
        driver=work/'wallet-ledger.php'
        driver.write_text(PHP)
        def run(entries, mode='strict', **kw):
            result=subprocess.run(['php', str(driver), str(ROOT),
                str(work/'mysql.sock'), DB, mode, json.dumps(dict(entries=entries, **kw))],
                env=env, capture_output=True, text=True, timeout=20)
            assert not result.stderr, (mode, result.stderr)
            if kw.get('lost_ack'):
                assert result.returncode==19 and not result.stdout, result
                return {}
            assert result.returncode==0, result
            return json.loads(result.stdout)
        def balance(user):
            return int(sql(f'SELECT wallet FROM users WHERE userid={user}'))
        def count():
            return int(sql('SELECT COUNT(*) FROM wallet_entries'))
        def reject(name, entries, mode, reason=None, **kw):
            before=sql('SELECT id,userid,wallet FROM users ORDER BY id')
            n=count()
            result=run(entries, mode, **kw)
            assert not result['ok'] and (reason is None or result['reason']==reason), result
            assert sql('SELECT id,userid,wallet FROM users ORDER BY id')==before
            assert count()==n
            cases.append(name+'_'+mode)
        def entry(user, kind, ref, delta, leg=0, actor=0, debt=False):
            return [user,kind,ref,leg,delta,actor,debt]

        sql((ROOT/'migrations/post-v9/024_wallet_entries.sql').read_text())
        assert count()==0 and balance(1001)==50000
        for mode in ('strict','off'):
            prefix=mode+'-'
            old=balance(1001)
            credit=entry(1001,'payment_credit',prefix+'receipt',10000,actor=9001)
            debit=entry(1001,'service_debit',prefix+'order',-3000)
            n=count()
            r=run([credit,debit],mode)
            assert r['ok'] and not any(e['replayed'] for e in r['entries']),r
            assert balance(1001)==old+7000 and count()==n+2
            cases.append('atomic_credit_debit_'+mode)
            replay=run([entry(1001,'payment_credit',prefix+'receipt',10000,actor=9002)],mode)
            assert replay['ok'] and replay['entries'][0]==dict(r['entries'][0],replayed=True),replay
            assert balance(1001)==old+7000 and count()==n+2
            cases.append('replay_after_later_movement_original_actor_'+mode)
            reject('conflicting_amount',[entry(1001,'payment_credit',prefix+'receipt',10001)],mode,'wallet_entry_source_conflict')
            reject('conflicting_owner',[entry(9001,'payment_credit',prefix+'receipt',10000)],mode,'wallet_entry_source_conflict')
            reject('insufficient_funds',[entry(1001,'service_debit',prefix+'large',-balance(1001)-1)],mode,'wallet_insufficient_funds')
            reject('no_transaction',[entry(1001,'payment_credit',prefix+'autocommit',1)],mode,'wallet_transaction_required',transaction=False)
            reject('no_user',[entry(199999,'payment_credit',prefix+'missing',1)],mode,'wallet_owner_not_unique')
            reject('invalid_source',[entry(1001,'payment_credit','',1)],mode,'wallet_entry_invalid')
            reject('zero_delta',[entry(1001,'service_debit',prefix+'free',0)],mode,'wallet_entry_invalid')
            reject('unauthenticated_debt',[entry(9002,'admin_adjustment',prefix+'baddebt',-1,debt=True)],mode,'wallet_entry_invalid')
            sql('UPDATE users SET wallet=-500 WHERE userid=9002')
            n=count()
            r=run([entry(9002,'payment_credit',prefix+'debt_credit',200)],mode)
            assert r['ok'] and balance(9002)==-300 and count()==n+1,r
            reject('debt_cannot_deepen',[entry(9002,'service_debit',prefix+'debt-debit',-1)],mode,'wallet_insufficient_funds')
            r=run([entry(9002,'admin_adjustment',prefix+'authorized_debt',-100,actor=9001,debt=True)],mode)
            assert r['ok'] and balance(9002)==-400,r
            cases.append('existing_debt_policy_'+mode)
            reject('overflow',[entry(1001,'payment_credit',prefix+'overflow',9223372036854775807)],mode,'wallet_balance_out_of_range')
            reject('underflow',[entry(1001,'admin_adjustment',prefix+'underflow',-9223372036854775807,actor=9001,debt=True)],mode,'wallet_balance_out_of_range')
            # Credit and its companion domain work must be in the same caller
            # transaction: a rollback removes both the projection and journal.
            old=balance(1001);n=count()
            x=entry(1001,'payment_credit',prefix+'rollback',123)
            r=run([x],mode,rollback=True)
            assert r['ok'] and balance(1001)==old and count()==n
            assert run([x],mode)['ok'] and balance(1001)==old+123 and count()==n+1
            cases.append('rollback_and_retry_'+mode)
            # Two legs share a source and commit together; a later error
            # must not retain the first leg.
            old=balance(1001);other=balance(9001);n=count()
            ref=prefix+'transfer'
            legs=[entry(1001,'transfer',ref,-55,0,1001),entry(9001,'transfer',ref,55,1,1001)]
            reject('second_leg_failure',[legs[0],entry(199999,'transfer',ref,55,1,1001)],mode,'wallet_owner_not_unique')
            r=run(legs,mode)
            assert r['ok'] and balance(1001)==old-55 and balance(9001)==other+55 and count()==n+2
            cases.append('transfer_atomic_'+mode)
            # Crash/ACK-loss after a real COMMIT: replay reads original source.
            old=balance(1001);n=count()
            x=entry(1001,'payment_credit',prefix+'lostack',20)
            run([x],mode,lost_ack=True)
            replay=run([x],mode)
            assert replay['ok'] and replay['entries'][0]['replayed'] and balance(1001)==old+20 and count()==n+1
            cases.append('lost_commit_ack_'+mode)
            # A SQL failure after the journal INSERT must roll it back.
            sql("CREATE TRIGGER fixture_reject_projection BEFORE UPDATE ON users FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture_projection_rejected'")
            try:
                reject('projection_failure',[entry(1001,'payment_credit',prefix+'sqlfail',5)],mode)
            finally:
                sql('DROP TRIGGER fixture_reject_projection')
            # Ambiguous local identities must never select an arbitrary row.
            sql("INSERT INTO users (userid,name,username,refcode,wallet,date) VALUES (1001,'Duplicate','fixturedup','0',7,'0')")
            try:
                reject('duplicate_user',[entry(1001,'payment_credit',prefix+'dupe',7)],mode,'wallet_owner_not_unique')
            finally:
                sql("DELETE FROM users WHERE username='fixturedup'")
            # Real concurrent duplicate callbacks share an original source.
            old=balance(1001);n=count()
            x=entry(1001,'payment_credit',prefix+'race',100)
            with ThreadPoolExecutor(max_workers=4) as pool:
                results=list(pool.map(lambda _: run([x],mode), range(4)))
            assert all(r['ok'] for r in results),results
            assert sum(not r['entries'][0]['replayed'] for r in results)==1,results
            assert len({r['entries'][0]['id'] for r in results})==1
            assert balance(1001)==old+100 and count()==n+1
            cases.append('concurrent_duplicate_credit_'+mode)
        assert sql('SELECT COUNT(*) FROM wallet_entries WHERE balance_after<>balance_before+delta_toman')=='0'
    report={'complete':True,'cases':len(cases),'native_php_mysqli':True,
            'owned_socket_only':True,'production_effects':0,
            'cases_detail':cases}
    if args.report:
        args.report.write_text(json.dumps(report,indent=2)+'\n')
    print(json.dumps(report))


if __name__=='__main__':
    main()
