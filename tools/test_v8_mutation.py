#!/usr/bin/env python3
"""Seven owned-fixture finance mutants must be detected by V8 differential E2E.

Mutations NEVER touch tracked source, production, user worktrees or real data.
"""
from __future__ import annotations
import argparse
import json
from pathlib import Path
import test_v8_finance as f
import test_v8_native as n
import test_v6_native as native
import test_access_state as support
import v8_contract as c


def cases():
    return [
        ("transfer_debit_reversed","src/Finance/Routes/WalletTransfer.php",
         b"`wallet` - ? WHERE",b"`wallet` + ? WHERE","wallet_transfer"),
        ("admin_credit_reversed","src/Finance/Routes/AdminWallet.php",
         b"`wallet` + ? WHERE",b"`wallet` - ? WHERE","admin_wallet_credit"),
        ("expired_invoice_accepted","src/Finance/Routes/PrePayment.php",
         b"> 10800",b"> 1080000","prepay_expired_pending"),
        ("discount_price_increased","src/Finance/Discounts.php",
         b"$price -= $discountValue;",b"$price += $discountValue;","discount_percent"),
        ("referral_reward_debited","src/Finance/ReferralReward.php",
         b"`wallet` + ? WHERE",b"`wallet` - ? WHERE","referral_nonwallet"),
        ("auto_receipt_state_lost","src/Finance/AutomaticReceipts.php",
         b"auto_cart_approved",b"bad_auto_state","auto_card_wallet_credit"),
        ("partial_payment_misclassified","src/Finance/GatewayReturn.php",
         b"low_payment",b"paid","payment_callback_partial"),
        ("invoice_provider_id_lost","src/Finance/GatewayInitiation.php",
         b"SET `payid` = ?",b"SET `description` = ?","payment_invoice_created"),
    ]


def main():
    parser=argparse.ArgumentParser()
    parser.add_argument("--run-disposable",action="store_true",required=True)
    parser.add_argument("--report",type=Path)
    args=parser.parse_args()
    c.verify()
    outcomes=[]
    with support.owned_database() as (work,env),native.owned_provider() as (provider,url),f.owned_gateway() as (gateway,gateway_url):
        previous=work/"v7-mutant-baseline"
        candidate=work/"v8-mutant-candidate"
        f.stage_finance(previous,False,gateway_url)
        f.stage_finance(candidate,True,gateway_url)
        for label,path,anchor,mutant,flow in cases():
            target=candidate/path
            original=target.read_bytes()
            expected_count=2 if label=="invoice_provider_id_lost" else 1
            if original.count(anchor)!=expected_count:
                raise RuntimeError("Missing or ambiguous mutation anchor: "+label)
            gateway.mode=("callback_partial" if flow=="payment_callback_partial" else "invoice_ok")
            f.prepare_label(work,env,flow,url)
            target.write_bytes(original.replace(anchor,mutant,1))
            try:
                traces=[]
                for root,db in ((previous,f.SIDE[0]),(candidate,f.SIDE[1])):
                    event_history=[];wire_history=[]
                    for route,spec in f.flows()[flow]:
                        provider.trace.clear();gateway.trace.clear()
                        req=dict(spec)
                        if route=="discount_function":
                            req["rowId"]=int(f.sql(work,env,"SELECT id FROM pays ORDER BY id DESC LIMIT 1",db).strip())
                        output=n.run(work,env,root,db,route,req)
                        event_history.append(output)
                        wire_history.append({"panel":list(provider.trace),
                                             "gateway":list(gateway.trace)})
                    traces.append((event_history,wire_history,f.finance_state(work,env,db)))
            finally:
                target.write_bytes(original)
            events=traces[0][0]!=traces[1][0]
            wire=traces[0][1]!=traces[1][1]
            sql=traces[0][2]!=traces[1][2]
            if not (events or wire or sql):
                raise RuntimeError("Finance mutation undetected: "+label)
            outcomes.append({"mutation":label,"detected":True,
                             "events":events,"wire":wire,"sql":sql})
            print("PASS detects "+label,flush=True)
    c.verify()
    result={"mutants":len(outcomes),"all_detected":True,
            "mutated_disposable_copies_only":True,"cases":outcomes}
    if args.report:args.report.write_text(json.dumps(result,indent=2)+"\n")
    print(json.dumps(result))


if __name__=="__main__":main()
