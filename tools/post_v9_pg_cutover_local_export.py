#!/usr/bin/env python3
"""Read-only MariaDB export for #183's offline PasarGuard preflight.

Use a SELECT-only DB account if available; reads a single consistent transaction.
Writes one operator-private JSON manifest, never to repo. Does not access providers.
"""
from __future__ import annotations
import argparse
import json
import subprocess
from pathlib import Path
import sys

import post_v9_pg_cutover_preflight as pg

SOURCES = {
    "S": """SELECT CONCAT('S|',JSON_OBJECT('id',i.id,'provider',c.type))
        FROM server_info i JOIN server_config c ON c.id=i.id ORDER BY i.id;""",
    "P": """SELECT CONCAT('P|',JSON_OBJECT('id',p.id,'server_id',p.server_id,
        'inbound_id',p.inbound_id)) FROM server_plans p ORDER BY p.id;""",
    "O": """SELECT CONCAT('O|',JSON_OBJECT('id',o.id,'server_id',o.server_id,
        'plan_id',o.fileid,'inbound_id',o.inbound_id,'username',o.remark,
        'owner_id',o.userid,'provider_effect_id',o.provider_effect_id,
        'provider_remote_user_id',o.provider_remote_user_id,
        'auto_renew',o.autoRenew,'amount',o.amount))
        FROM orders_list o ORDER BY o.id;""",
    "C": """SELECT CONCAT('C|',JSON_OBJECT(
       'unsettled_service_payments',
           (SELECT COUNT(*) FROM pays p WHERE
             (p.type='BUY_SUB' OR p.type='RENEW_ACCOUNT'
               OR p.type LIKE 'INCREASE_DAY_%' OR p.type LIKE 'INCREASE_VOLUME_%')
             AND p.state NOT IN ('approved','expired','rejected','paid_with_wallet',
                   'auto_cart_approved','service_superseded','declined','low_wallet')),
       'uncertain_provider_effects',
           (SELECT COUNT(*) FROM service_effects e WHERE
             e.state IN ('reserved','dispatching','mutating','unknown','review')
             OR e.provider_state IN ('reserved','dispatching','mutating','unknown','review')),
       'uncommitted_plan_changes',
           (SELECT COUNT(*) FROM service_plan_changes c
             WHERE c.state NOT IN ('committed','aborted'))
       ));""",
}


def snapshot_sql():
    return ("SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ;\n"
            "START TRANSACTION READ ONLY;\n"
            + "\n".join(SOURCES.values()) + "\nROLLBACK;\n")


def parse_rows(stdout: str, tenant: str):
    result = {"version": 1, "tenant": tenant, "servers": [], "plans": [],
              "orders": [], "claims": None}
    for line in stdout.splitlines():
        if line == "PAGER set to stdout" or not line.strip():
            continue
        if len(line) < 3 or line[1] != "|" or line[0] not in SOURCES:
            raise ValueError("unexpected_client_output")
        try:
            obj = json.loads(line[2:])
        except json.JSONDecodeError as error:
            raise ValueError("invalid_client_json") from error
        if type(obj) is not dict:
            raise ValueError("invalid_client_record")
        if line[0] == "S":
            result["servers"].append(obj)
        elif line[0] == "P":
            result["plans"].append(obj)
        elif line[0] == "O":
            result["orders"].append(obj)
        elif line[0] == "C":
            if result["claims"] is not None:
                raise ValueError("duplicate_claims")
            result["claims"] = obj
    if result["claims"] is None or not result["servers"] or not result["orders"]:
        raise ValueError("incomplete_database_snapshot")
    return result


def main(argv=None):
    parser = argparse.ArgumentParser(description="Offline private read-only AChBot source manifest")
    parser.add_argument("--tenant", choices=["vivan", "hell"], required=True)
    parser.add_argument("--output", type=Path, required=True)
    args = parser.parse_args(argv)
    db = "bot" + args.tenant
    try:
        proc = subprocess.run(
            ["mariadb", "--protocol=SOCKET", "--batch", "--raw", "--skip-column-names",
             "--database=" + db, "--execute=" + snapshot_sql()],
            stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, timeout=80, check=False)
        if proc.returncode:
            raise ValueError("readonly_db_query_failed")
        snapshot = parse_rows(proc.stdout, args.tenant)
        # Structural completeness is required even before target data exists.
        pg.unique_ids(snapshot["servers"], "servers")
        pg.unique_ids(snapshot["plans"], "plans")
        pg.unique_ids(snapshot["orders"], "orders")
        claims = snapshot["claims"]
        pg.require(all(pg.nonnegative(claims.get(k)) for k in
               ("unsettled_service_payments","uncertain_provider_effects",
                "uncommitted_plan_changes")), "claim_snapshot_invalid")
        pg.private_write(args.output, snapshot)
        print(json.dumps({"complete": True, "tenant": args.tenant,
                          "servers": len(snapshot["servers"]),
                          "plans": len(snapshot["plans"]),
                          "order_references": len(snapshot["orders"]),
                          "claim_attention_counts": claims,
                          "output_private": True}, sort_keys=True))
        return 0
    except (OSError, ValueError, TypeError, KeyError, subprocess.TimeoutExpired):
        print('{"complete":false,"error_code":"source_export_failed"}')
        return 2


if __name__ == "__main__":
    sys.exit(main())
