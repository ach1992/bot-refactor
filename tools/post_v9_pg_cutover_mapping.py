#!/usr/bin/env python3
"""Produce a private, verified plan-to-Native-Group map for the legacy cutover.

This does NOT contact providers, read database credentials or change any account.
Group names/IDs are operator observations, not API-version compatibility rules.
"""
from __future__ import annotations

import argparse
import json
from pathlib import Path
import re
import sys

import post_v9_pg_cutover_preflight as pg

ROOT = Path(__file__).resolve().parents[1]


def generate(source: dict, cohorts: dict, catalog: dict, alias: str) -> dict:
    tenant = source.get("tenant")
    pg.require(source.get("version") == cohorts.get("version") == catalog.get("version") == 1,
               "mapping_version_invalid")
    pg.require(tenant in ("vivan", "hell"), "tenant_invalid")
    pg.require(type(alias) is str and alias.isascii() and
               re.fullmatch(r"[a-zA-Z][a-zA-Z0-9_-]{0,49}", alias) is not None,
               "panel_alias_invalid")
    pg.require(type(cohorts.get("tenants")) is dict and
               type(cohorts["tenants"].get(tenant)) is dict, "cohort_declaration_invalid")
    cohort = cohorts["tenants"][tenant]
    servers = pg.unique_ids(source.get("servers"), "servers")
    plans = pg.unique_ids(source.get("plans"), "plans")
    pg.unique_ids(source.get("orders"), "orders")
    pg.require(bool(servers) and bool(plans), "legacy_source_empty")
    pg.require(all(s.get("provider") == "marzban" for s in servers.values()),
               "legacy_provider_invalid")
    pg.require(set(cohort) == {str(k) for k in servers}, "cohort_servers_mismatch")
    pg.require(type(catalog.get("groups")) is list, "group_catalog_invalid")
    names = {}
    ids = set()
    for group in catalog["groups"]:
        pg.require(type(group) is dict and
                   pg.positive(group.get("id")) and type(group.get("name")) is str and
                   bool(group["name"].strip()) and
                   type(group.get("is_disabled")) is bool,
                   "group_catalog_record_invalid")
        name = group["name"].casefold()
        pg.require(name not in names and group["id"] not in ids, "group_catalog_ambiguous")
        names[name] = group
        ids.add(group["id"])
    server_groups = {}
    for sid in sorted(servers):
        requested = cohort[str(sid)]
        pg.require(type(requested) is str and requested.casefold() in names,
                   "native_group_missing")
        group = names[requested.casefold()]
        pg.require(not group["is_disabled"], "native_group_disabled")
        server_groups[sid] = group["id"]
    plan_groups = {}
    for pid, plan in sorted(plans.items()):
        pg.require(pg.positive(plan.get("server_id")) and
                   plan["server_id"] in servers and plan.get("inbound_id") == 0,
                   "legacy_plan_invalid")
        plan_groups[str(pid)] = [server_groups[plan["server_id"]]]
    return {"version": 1, "tenant": tenant,
            "server_panels": {str(i): alias for i in sorted(servers)},
            "plan_groups": plan_groups}


def main(argv=None):
    parser = argparse.ArgumentParser(description="Offline cohort/Native group mapping generator")
    parser.add_argument("--local", required=True, type=Path)
    parser.add_argument("--cohorts", required=True, type=Path)
    parser.add_argument("--group-catalog", required=True, type=Path)
    parser.add_argument("--panel-alias", required=True)
    parser.add_argument("--output", required=True, type=Path)
    options = parser.parse_args(argv)
    try:
        local, _ = pg.load(options.local)
        cohorts, _ = pg.load(options.cohorts)
        catalog, _ = pg.load(options.group_catalog)
        mapping = generate(local, cohorts, catalog, options.panel_alias)
        pg.private_write(options.output, mapping)
        print(json.dumps({"complete": True, "tenant": mapping["tenant"],
                          "servers": len(mapping["server_panels"]),
                          "plans": len(mapping["plan_groups"]), "output_private": True},
                         sort_keys=True))
        return 0
    except (ValueError, TypeError, KeyError, OSError, json.JSONDecodeError):
        print('{"complete":false,"error_code":"mapping_input_or_scope_invalid"}')
        return 2


if __name__ == "__main__":
    sys.exit(main())
