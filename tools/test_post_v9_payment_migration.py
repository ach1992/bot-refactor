#!/usr/bin/env python3
"""Prove non-destructive RC4 -> common-ledger data import on socket-only MariaDB.

Never connects to production, a provider, the network or tenant data. The
original published RC4 schema is fetched from its exact merged source commit.
"""
from __future__ import annotations

import argparse
import json
import subprocess
from pathlib import Path

import test_access_state as owned
import test_v6_native as native
import test_v8_native as purchase
import test_post_v9_finance_routes as finance

ROOT = Path(__file__).resolve().parents[1]
RC4_SOURCE = "58d98e05839b4aa5b28e985bcd1473173f590eda"
DB = purchase.SIDE[1]
RC4_SQL = "migrations/post-v9/002_nowpayments_checkout_attempts.sql"


def sql(work,env,query):
    return native.sql(work,env,query,DB)


def run():
    with owned.owned_database() as (work,env):
        purchase.init(work,env,"http://127.0.0.1:9")
        if len(sql(work,env,"SHOW TABLES").splitlines())!=23:
            raise RuntimeError("Historical original 23-table schema changed")
        historic=subprocess.check_output(
            ["git","-C",str(ROOT),"show",RC4_SOURCE+":"+RC4_SQL],
            timeout=10
        ).decode()
        if ("CREATE TABLE IF NOT EXISTS achbot_nowpayments_attempts" not in historic or
            "CREATE TABLE IF NOT EXISTS achbot_nowpayments_child_payments" not in historic or
            "review_notice_claim_token" not in historic):
            raise RuntimeError("Historic RC4 schema is not exact expected form")
        native.sql(work,env,historic,DB)
        finance.seed_payment(work,env,"migration-preserve","INCREASE_WALLET",0,0,"old-invoice")
        sql(work,env,"INSERT INTO achbot_nowpayments_attempts "
            "(pay_row_id,order_hash,invoice_id,payment_id,invoice_url,quote_usd,"
            "local_amount_toman,expected_outcome_currency,minimum_outcome_amount,"
            "stage,provider_status,review_notice_claim_token,review_notice_claimed_at) "
            "SELECT id,hash_id,'old-invoice','old-primary',"
            "'https://nowpayments.io/pay/old-invoice',1.00,price,'usdttrc20',"
            "0.98000000,'review','multiple_payments',"
            "'0123456789abcdef0123456789abcdef','2026-10-16 12:00:00' "
            "FROM pays WHERE hash_id='migration-preserve'")
        sql(work,env,"INSERT INTO achbot_nowpayments_child_payments "
            "(attempt_id,payment_id,observed_status) "
            "SELECT id,'old-primary','finished' "
            "FROM achbot_nowpayments_attempts WHERE order_hash='migration-preserve'")
        sql(work,env,"INSERT INTO achbot_nowpayments_child_payments "
            "(attempt_id,payment_id,observed_status) "
            "SELECT id,'old-second','partially_paid' "
            "FROM achbot_nowpayments_attempts WHERE order_hash='migration-preserve'")

        old=sql(work,env,"SELECT id,pay_row_id,order_hash,invoice_id,payment_id,"
            "quote_usd,local_amount_toman,minimum_outcome_amount,stage,"
            "provider_status,review_notice_claim_token,review_notice_claimed_at "
            "FROM achbot_nowpayments_attempts").strip()
        old_children=sql(work,env,"SELECT id,attempt_id,payment_id,observed_status "
            "FROM achbot_nowpayments_child_payments ORDER BY id").strip()
        if len(sql(work,env,"SHOW TABLES").splitlines())!=25:
            raise RuntimeError("Original RC4 fixture has invalid table count")
        shared_sql=(ROOT/"migrations/post-v9/003_payment_attempts.sql").read_text()
        if ('CREATE TABLE IF NOT EXISTS payment_attempts' in shared_sql or
            'CONSTRAINT fk_payment_attempt_order' not in shared_sql):
            raise RuntimeError("Shared schema does not fail closed on table identity")
        native.sql(work,env,shared_sql,DB)
        # Do not treat existing generic names as proof that a matching schema
        # was applied; blind reruns must fail before importing financial data.
        try:
            native.sql(work,env,shared_sql,DB)
        except Exception:
            pass
        else:
            raise RuntimeError("Shared migration silently reused existing tables")
        if len(sql(work,env,"SHOW TABLES").splitlines())!=27:
            raise RuntimeError("Shared ledger did not add two separate tables")
        if sql(work,env,"SELECT COUNT(*) FROM payment_attempts").strip()!='0':
            raise RuntimeError("New shared ledger must start empty for import")
        native.sql(work,env,(ROOT/"migrations/post-v9/legacy_rc4_nowpayments_import.sql").read_text(),DB)

        newer=sql(work,env,"SELECT id,pay_row_id,order_hash,provider_reference,payment_id,"
            "quote_amount,local_amount_toman,minimum_settlement_amount,stage,"
            "provider_status,review_notice_claim_token,review_notice_claimed_at "
            "FROM payment_attempts").strip()
        children=sql(work,env,"SELECT id,attempt_id,payment_id,observed_status "
            "FROM payment_transactions ORDER BY id").strip()
        if old.replace('1.00\t','1.00000000\t')!=newer:
            raise RuntimeError("RC4 attempt identity or value changed during import: "
                               +repr(old)+" vs "+repr(newer))
        if children!=old_children:
            raise RuntimeError("RC4 child payments or IDs changed in import")
        if sql(work,env,"SELECT provider,quote_currency,settlement_currency "
               "FROM payment_attempts").strip()!='nowpayments\tusd\tusdttrc20':
            raise RuntimeError("Original provider and currency evidence not restored")
        if sql(work,env,"SELECT COUNT(*) FROM payment_transactions").strip()!='2':
            raise RuntimeError("Repeated deposit history was lost")
        if old!=sql(work,env,"SELECT id,pay_row_id,order_hash,invoice_id,payment_id,"
                       "quote_usd,local_amount_toman,minimum_outcome_amount,stage,"
                       "provider_status,review_notice_claim_token,review_notice_claimed_at "
                       "FROM achbot_nowpayments_attempts").strip():
            raise RuntimeError("Original historical attempt data was modified")
        if old_children!=sql(work,env,"SELECT id,attempt_id,payment_id,observed_status "
                       "FROM achbot_nowpayments_child_payments ORDER BY id").strip():
            raise RuntimeError("Original historical child data was modified")
        return {
            "complete":True,
            "historical_schema_source":RC4_SOURCE,
            "original_tables":23,
            "old_rc4_tables":2,
            "new_shared_tables":2,
            "imported_attempts":1,
            "imported_children":2,
            "preserved_rc4_rows_and_ids":True,
            "live_database_touched":False
        }


def main():
    p=argparse.ArgumentParser()
    p.add_argument("--run-disposable",action="store_true",required=True)
    p.add_argument("--report",type=Path)
    args=p.parse_args()
    result=run()
    if args.report:
        args.report.parent.mkdir(parents=True,exist_ok=True)
        args.report.write_text(json.dumps(result,indent=2)+"\n")
    print(json.dumps(result))


if __name__=="__main__": main()
