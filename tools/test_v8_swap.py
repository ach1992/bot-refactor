#!/usr/bin/env python3
"""Real paid V7 -> V8 -> V7 -> V8 source-only swap on one disposable 23-table DB.

Retains private state, source entrypoints, existing paid order and production-
style dormant internal module bytes. Never starts external Cron/services.
"""
from __future__ import annotations
import argparse
import hashlib
import json
import re
from pathlib import Path
import test_v8_native as e2e
import test_v6_native as native
import test_access_state as support
import v8_contract as c


def overlay(app,version):
    if version=="v8":
        for name in c.MODULES:
            target=app/name;target.parent.mkdir(parents=True,exist_ok=True)
            target.write_bytes((c.ROOT/name).read_bytes())
    for name in c.v7.members():
        target=app/name;target.parent.mkdir(parents=True,exist_ok=True)
        content=(c.ROOT/name).read_bytes() if version=="v8" else c.old(name)
        target.write_bytes(content)
    actual=c.members() if version=="v8" else c.v7.members()
    for name in actual:
        expectation=(c.ROOT/name).read_bytes() if version=="v8" else c.old(name)
        if (app/name).read_bytes()!=expectation:
            raise RuntimeError("Invalid source-only overlay identity: "+name)
    return len(actual)


def main():
    p=argparse.ArgumentParser();p.add_argument("--run-disposable",action="store_true",required=True)
    p.add_argument("--report",type=Path);a=p.parse_args();c.verify()
    phases=[]
    with support.owned_database() as (work,env),native.owned_provider() as (provider,url):
        e2e.init(work,env,url)
        app=work/"persisted-bot";e2e.stage(app,False)
        private={"baseInfo.php":b"<?php // owned private fixture; never installed\n",
                 "private-state.json":b"owned private data persists on source swap\n",
                 "settings/live-like-sentinel":b"owned private cron-state sentinel\n"}
        for filename,content in private.items():
            path=app/filename;path.parent.mkdir(parents=True,exist_ok=True);path.write_bytes(content)
        private_hashes={name:hashlib.sha256((app/name).read_bytes()).hexdigest() for name in private}
        db=e2e.SIDE[1]
        overlay(app,"v7")
        e2e.run(work,env,app,db,"selectplan",{"data":"selectPlan7_1_none","actor":1001},modern=False)
        provider.trace.clear()
        paid=e2e.run(work,env,app,db,"wallet",{"data":"payBuyWithWalletxxxxxxxxx","actor":1001},modern=False)
        if paid["exception"] or len(provider.trace)<3:
            raise RuntimeError("Pinned V7 failed to provision a paid order")
        before=e2e.snapshot(work,env,db)
        assert native.sql(work,env,"SELECT COUNT(*) FROM orders_list",db).strip()=="1"
        tables=native.sql(work,env,"SHOW TABLES",db).splitlines()
        if len(tables)!=23:raise RuntimeError("Not the original MariaDB schema")
        def ddl():
            return [re.sub(r" AUTO_INCREMENT=\d+","",
                native.sql(work,env,"SHOW CREATE TABLE `"+name+"`",db)) for name in tables]
        ddl_before=ddl()
        for index,version in enumerate(("v7","v8","v7","v8"),1):
            count=overlay(app,version)
            if {name:hashlib.sha256((app/name).read_bytes()).hexdigest() for name in private}!=private_hashes:
                raise RuntimeError("Source overlay touched private or cron state")
            provider.trace.clear()
            output=e2e.run(work,env,app,db,"catalogue",
                           {"data":"selectServer2_none","actor":1001},modern=version=="v8")
            if output["exception"] or not output["events"] or provider.trace:
                raise RuntimeError("Purchase catalogue failed across rollback: "+version)
            if e2e.snapshot(work,env,db)!=before:
                raise RuntimeError("Persisted V7 paid order/financial state lost")
            if ddl()!=ddl_before:
                raise RuntimeError("Original schema modified by source-only overlay")
            if index==1:
                control=output
            elif output!=control:
                raise RuntimeError("Telegram callback output changed under code rollback")
            phases.append({"step":index,"version":version,"source_members":count,
                           "persisted_paid_order":True,"original_schema_unchanged":True})
            print("PASS source-only swap "+str(index)+" "+version,flush=True)
    result={"phases":phases,"private_sentinels_unchanged":True,"no_database_rollback":True,
            "persisted_paid_order_survived":True,"original_23_table_schema_unchanged":True}
    if a.report:a.report.write_text(json.dumps(result,indent=2)+"\n")
    print(json.dumps(result))

if __name__=="__main__":main()
