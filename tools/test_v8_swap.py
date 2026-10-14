#!/usr/bin/env python3
"""Real paid V7 -> V8 -> V7 -> V8 source-only swap on one disposable 23-table DB.

Retains private state, source entrypoints, existing paid order, discounts,
renewal records and dormant internal module bytes. Never starts external Cron/services.
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


# The owned test harness is not part of either release manifest.
HARNESS_FILES = {
    "native-purchase.php", "access-sequence.php", "case.json",
    "result.json", "settings/temp.txt",
}
PRIVATE_FILES = {"baseInfo.php", "private-state.json", "settings/live-like-sentinel"}


def inventory(app):
    if any(x.is_symlink() for x in app.rglob("*")):
        raise RuntimeError("Unexpected symlink in isolated source swap tree")
    return {x.relative_to(app).as_posix() for x in app.rglob("*") if x.is_file()}


def overlay(app,version):
    if version not in ("v7", "v8"):
        raise ValueError("Unknown rollback target")
    # Never prune an arbitrary directory: remove only the 27 known V8 additions
    # from this freshly owned disposable fixture, leaving all private files alone.
    if version == "v7":
        for name in c.MODULES:
            target=app/name
            if target.is_symlink():
                raise RuntimeError("Unsafe module symlink: "+name)
            if target.is_file():
                target.unlink()
    else:
        for name in c.MODULES:
            target=app/name;target.parent.mkdir(parents=True,exist_ok=True)
            target.write_bytes((c.ROOT/name).read_bytes())
    for name in c.v7.members():
        target=app/name;target.parent.mkdir(parents=True,exist_ok=True)
        content=(c.ROOT/name).read_bytes() if version=="v8" else c.old(name)
        target.write_bytes(content)
    expected_runtime=set(c.members() if version=="v8" else c.v7.members())
    actual_inventory=inventory(app)
    actual_runtime=actual_inventory-HARNESS_FILES-PRIVATE_FILES
    if actual_runtime != expected_runtime or not PRIVATE_FILES <= actual_inventory:
        raise RuntimeError("Source-only inventory mismatch for "+version+": "+
            repr({"extra":sorted(actual_runtime-expected_runtime),
                  "missing":sorted(expected_runtime-actual_runtime),
                  "private_missing":sorted(PRIVATE_FILES-actual_inventory)}))
    for name in expected_runtime:
        expectation=(c.ROOT/name).read_bytes() if version=="v8" else c.old(name)
        if (app/name).read_bytes()!=expectation:
            raise RuntimeError("Invalid source-only overlay identity: "+name)
    return len(actual_inventory-HARNESS_FILES-PRIVATE_FILES)


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
        if native.sql(work,env,"SELECT COUNT(*) FROM orders_list WHERE userid=1001 AND status=1",db).strip()!="1":
            raise RuntimeError("V7 rollback fixture must have one real paid order")
        # Seed both financial record classes required by Issue #24 onto the
        # SAME disposable MariaDB used for all four physical source phases.
        # SELECT links each fixture to the actually paid order, rather than
        # inventing an unassociated renewal/discount record.
        native.sql(work,env,"""
INSERT INTO discounts
 (id,hash_id,type,amount,expire_date,expire_count,used_by,can_use,
  audience_type,audience_user_id,target_type,target_id,min_price,max_discount,is_active)
 SELECT 910001,'SOURCE-SWAP-COUPON','percent',15,2147000000,5,'[1001]',5,
        'users',userid,'plan',fileid,0,0,1
 FROM orders_list WHERE userid=1001 AND status=1;
INSERT INTO increase_order
 (id,userid,server_id,inbound_id,remark,amount,date,volume,day)
 SELECT 910001,userid,server_id,inbound_id,remark,100,'1780000000',2,30
 FROM orders_list WHERE userid=1001 AND status=1;
""",db)
        # Full-row snapshots pin EVERY persisted column, including coupon
        # audience/use fields and renewal amount, date, volume and days.
        persistence_queries={
            "discounts":"SELECT * FROM discounts ORDER BY id",
            "renewals":"SELECT * FROM increase_order ORDER BY id",
        }
        persistence_before={kind:native.sql(work,env,query,db)
                            for kind,query in persistence_queries.items()}
        if any(len(rows.splitlines())!=1 for rows in persistence_before.values()):
            raise RuntimeError("Discount/renewal preservation fixtures were not seeded exactly once")
        association=native.sql(work,env,"""
SELECT COUNT(*) FROM increase_order AS i JOIN orders_list AS o
 ON i.userid=o.userid AND i.server_id=o.server_id
 AND i.inbound_id=o.inbound_id AND i.remark=o.remark
 WHERE i.id=910001 AND o.userid=1001 AND o.status=1
""",db).strip()
        if association!="1":
            raise RuntimeError("Synthetic renewal not associated with paid order")
        coupon_link=native.sql(work,env,"""
SELECT COUNT(*) FROM discounts AS d JOIN orders_list AS o
 ON d.audience_user_id=o.userid AND d.target_id=o.fileid
 WHERE d.id=910001 AND o.userid=1001 AND o.status=1
""",db).strip()
        if coupon_link!="1":
            raise RuntimeError("Synthetic discount not associated with paid order")
        before=e2e.snapshot(work,env,db)
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
            observed_persistence={kind:native.sql(work,env,query,db)
                                  for kind,query in persistence_queries.items()}
            for kind,expected_rows in persistence_before.items():
                if observed_persistence[kind]!=expected_rows:
                    raise RuntimeError("Source rollback modified complete "+kind+" rows: "+version)
            if ddl()!=ddl_before:
                raise RuntimeError("Original schema modified by source-only overlay")
            if index==1:
                control=output
            elif output!=control:
                raise RuntimeError("Telegram callback output changed under code rollback")
            phases.append({"step":index,"version":version,"source_members":count,
                           "actual_inventory_verified":True,
                           "actual_runtime_members":len(inventory(app)-HARNESS_FILES-PRIVATE_FILES),
                           "persisted_paid_order":True,
                           "financial_records":{
                               kind:{"row_count":len(rows.splitlines()),"unchanged":True,
                                     "sha256":hashlib.sha256(rows.encode()).hexdigest()}
                               for kind,rows in observed_persistence.items()},
                           "original_schema_unchanged":True})
            print("PASS source-only swap "+str(index)+" "+version,flush=True)
    result={"phases":phases,"private_sentinels_unchanged":True,"no_database_rollback":True,
            "persisted_paid_order_survived":True,
            "persisted_discount_and_renewal_records_survived":True,
            "original_23_table_schema_unchanged":True}
    if a.report:a.report.write_text(json.dumps(result,indent=2)+"\n")
    print(json.dumps(result))

if __name__=="__main__":main()
