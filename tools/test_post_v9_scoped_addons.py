#!/usr/bin/env python3
"""Disposable scoped-add-on migration proof; runtime tests use Wallet-first CI."""
import argparse
import hashlib
import json
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path
import test_access_state as owned
import test_v6_native as native
import test_v8_native as purchase

ROOT=Path(__file__).resolve().parents[1]
DB=purchase.SIDE[1]

def sha(text:str)->str:return hashlib.sha256(text.encode()).hexdigest()

def test()->dict:
    checks=[]
    def yes(ok:bool,label:str):
        if not ok:raise AssertionError(label)
        checks.append(label)
    with owned.owned_database() as (w,e):
        purchase.init(w,e,'http://127.0.0.1:9')
        def q(sql:str):return native.sql(w,e,sql,DB).strip()
        # This test never accesses installed databases; owned_database binds an
        # isolated socket under a disposable temporary directory.
        q("""INSERT INTO increase_day(id,volume,price) VALUES(101,20,1000);
              INSERT INTO increase_plan(id,volume,price) VALUES(201,10,100);""")
        before=q('SELECT id,volume,price FROM increase_day ORDER BY id; SELECT id,volume,price FROM increase_plan ORDER BY id')
        q((ROOT/'migrations/post-v9/015_scoped_addons.sql').read_text())
        yes(q('SELECT id,volume,price FROM increase_day ORDER BY id; SELECT id,volume,price FROM increase_plan ORDER BY id')==before,
            'migration_preserves_global_offer_ids_units_prices')
        yes(q("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() "
              "AND TABLE_NAME='pays' AND COLUMN_NAME='addon_scope_snapshot'")=='1',
            'migration_installs_immutable_quote_column')
        for kind,offer,scope,price in [
            ('volume',301,'S:2',120),('volume',302,'P:2:1',130),
            ('volume',303,'P:2:1,2',150),('volume',304,'S:3',160),
            ('day',401,'S:2',1100)]:
            table='increase_plan' if kind=='volume' else 'increase_day'
            server=int(scope.split(':')[1])
            groups='NULL' if scope.startswith('S:') else "'[" +','.join(scope.split(':')[2].split(','))+"]'"
            q(f"INSERT INTO {table}(id,volume,price,server_id,group_ids,scope_hash) "
              f"VALUES({offer},{10 if kind=='volume' else 20},{price},{server},{groups},'{sha(scope)}')")
        yes(q("SELECT COUNT(*) FROM increase_plan WHERE volume=10")=='5',
            'global_and_scoped_identical_units_coexist')
        def race_insert(_):
            try:
                native.sql(w,e,"INSERT INTO increase_plan(volume,price,server_id,scope_hash)"
                    f" VALUES(777,42,2,'{sha('S:2')}')",DB)
                return True
            except Exception:return False
        with ThreadPoolExecutor(max_workers=2) as pool:
            claims=list(pool.map(race_insert,range(2)))
        yes(claims.count(True)==1 and q("SELECT COUNT(*) FROM increase_plan "
                "WHERE volume=777 AND server_id=2")=='1',
            'concurrent_duplicate_scoped_offer_insert_one_unique_winner')
        # The concurrency case is not a customer offer fixture. Keep the
        # subsequent exact per-quantity catalog assertions independent.
        q("DELETE FROM increase_plan WHERE volume=777 AND server_id=2")
        yes(q("SELECT COUNT(*) FROM increase_plan WHERE volume=777")=='0',
            'concurrent_offer_fixture_isolation_restored')
        try:
            q("INSERT INTO increase_plan(volume,price,server_id,scope_hash) "
              f"VALUES(10,121,2,'{sha('S:2')}')")
        except Exception:checks.append('duplicate_unit_scope_rejected_by_db')
        else:raise AssertionError('duplicate_unit_scope_accepted')
        # Reapplying migration is deliberately blocked before any DDL.
        try:q((ROOT/'migrations/post-v9/015_scoped_addons.sql').read_text())
        except Exception:checks.append('repeated_migration_rejected')
        else:raise AssertionError('repeated_migration_accepted')

    return {'status':'PASS','cases':len(checks),'checks':checks,
            'sql_only':True,'production_database_modified':False}

def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--sql-only',action='store_true',required=True)
    parser.add_argument('--report',type=Path)
    args=parser.parse_args()
    result=test()
    if args.report:args.report.write_text(json.dumps(result,indent=2)+'\n')
    print(json.dumps(result))

if __name__=='__main__':main()
