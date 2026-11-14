#!/usr/bin/env python3
"""Offline, read-only manifest reconciliation for the future Vivan/Hell PG cutover.

No database/network client, migrations, provider writes or import of live runtime.
Only an operator with separate live authority may obtain input snapshots.
"""
from __future__ import annotations

import argparse
from collections import Counter, defaultdict
from datetime import datetime, timezone
import hashlib
import json
import os
from pathlib import Path
import re
import sys

ROOT = Path(__file__).resolve().parents[1]
HEX32 = re.compile(r"[0-9a-f]{32}\Z")
HEX64 = re.compile(r"[0-9a-f]{64}\Z")
USERNAME = re.compile(r"[a-zA-Z0-9_@.-]{1,100}\Z")
CREATED = re.compile(r"\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{6}Z\Z")
BANNED_KEYS = frozenset({"api_key", "password", "secret", "token", "link", "subscription_url",
                         "proxy_settings", "uuid", "baseinfo", "database_password"})
VALID_STATUSES = frozenset({"active", "disabled", "limited", "expired"})


def positive(value):
    return type(value) is int and value > 0


def nonnegative(value):
    return type(value) is int and value >= 0


def require(condition, code):
    if not condition:
        raise ValueError(code)


def load(path: Path):
    raw = path.read_bytes()
    require(len(raw) <= 50_000_000, "input_too_large")

    def unique_pairs(pairs):
        result = {}
        for key, value in pairs:
            require(key not in result, "duplicate_json_key")
            result[key] = value
        return result

    data = json.loads(raw, object_pairs_hook=unique_pairs)
    require(type(data) is dict and type(data.get("version")) is int and
            data["version"] == 1, "input_version_invalid")

    def no_secrets(value):
        if type(value) is dict:
            require(not any(str(key).lower() in BANNED_KEYS for key in value), "secret_field_forbidden")
            for child in value.values():
                no_secrets(child)
        elif type(value) is list:
            for child in value:
                no_secrets(child)

    no_secrets(data)
    return data, hashlib.sha256(raw).hexdigest()


def unique_ids(rows, label, field="id"):
    require(type(rows) is list, label + "_not_list")
    result = {}
    for item in rows:
        require(type(item) is dict and positive(item.get(field)), label + "_id_invalid")
        key = item[field]
        require(key not in result, label + "_duplicate_id")
        result[key] = item
    return result


def canonical_groups(value, allow_empty=False):
    require(type(value) is list and len(value) <= 1000, "groups_invalid")
    require(all(positive(v) for v in value) and value == sorted(set(value)),
            "groups_noncanonical")
    require(bool(value) or allow_empty, "groups_empty")
    return tuple(value)


def date_is_canonical(value):
    if type(value) is not str or not CREATED.fullmatch(value):
        return False
    try:
        parsed = datetime.strptime(value, "%Y-%m-%dT%H:%M:%S.%fZ")
        return parsed.replace(tzinfo=timezone.utc).isoformat(timespec="microseconds").replace("+00:00", "Z") == value
    except ValueError:
        return False


def reconcile(local, native, mapping):
    require(local.get("tenant") in ("vivan", "hell") and
            native.get("tenant") == mapping.get("tenant") == local["tenant"],
            "tenant_mismatch")
    servers = unique_ids(local.get("servers"), "servers")
    plans = unique_ids(local.get("plans"), "plans")
    orders = unique_ids(local.get("orders"), "orders")
    require(bool(servers) and bool(plans) and bool(orders), "empty_source")
    for server in servers.values():
        require(server.get("provider") == "marzban", "source_provider_mismatch")
    for plan in plans.values():
        require(positive(plan.get("server_id")) and plan["server_id"] in servers and
                plan.get("inbound_id") == 0, "source_plan_invalid")
    panel_by_key = {}
    native_panels = native.get("panels")
    require(type(native_panels) is list and native_panels, "panels_invalid")
    panel_ids = set()
    for panel in native_panels:
        require(type(panel) is dict and type(panel.get("key")) is str and
                re.fullmatch(r"[a-zA-Z][a-zA-Z0-9_-]{0,49}", panel["key"]) and
                positive(panel.get("id")) and type(panel.get("instance_key")) is str and
                HEX32.fullmatch(panel["instance_key"]) and
                type(panel.get("endpoint_sha256")) is str and
                HEX64.fullmatch(panel["endpoint_sha256"]), "panel_invalid")
        require(panel["key"] not in panel_by_key and panel["id"] not in panel_ids,
                "panel_duplicate")
        panel_ids.add(panel["id"])
        panel_by_key[panel["key"]] = panel
        canonical_groups(panel.get("available_groups"))
    server_map = mapping.get("server_panels")
    plan_map = mapping.get("plan_groups")
    require(type(server_map) is dict and type(plan_map) is dict, "mapping_invalid")
    require(set(server_map) == {str(k) for k in servers} and
            set(plan_map) == {str(k) for k in plans}, "mapping_incomplete")
    for sid, panel_key in server_map.items():
        require(type(panel_key) is str and panel_key in panel_by_key,
                "mapped_panel_missing")
    plan_groups = {}
    for plan_id, plan in plans.items():
        groups = canonical_groups(plan_map[str(plan_id)])
        available = panel_by_key[server_map[str(plan["server_id"])]]["available_groups"]
        require(set(groups) <= set(available), "mapped_group_unavailable")
        plan_groups[plan_id] = groups
    claims = local.get("claims")
    require(type(claims) is dict and
            all(nonnegative(claims.get(key)) for key in
                ("unsettled_service_payments", "uncertain_provider_effects", "uncommitted_plan_changes")),
            "claim_evidence_missing")

    by_remote = {}
    by_username = {}
    target_accounts = native.get("accounts")
    require(type(target_accounts) is list, "accounts_invalid")
    for account in target_accounts:
        require(type(account) is dict and type(account.get("panel_key")) is str and
                account["panel_key"] in panel_by_key and positive(account.get("id")) and
                type(account.get("username")) is str and
                USERNAME.fullmatch(account["username"]) and
                date_is_canonical(account.get("created_at")) and
                nonnegative(account.get("data_limit")) and
                nonnegative(account.get("used_traffic")) and
                (account.get("expire") is None or positive(account.get("expire"))) and
                account.get("status") in VALID_STATUSES and
                type(account.get("native_schedules_clear")) is bool,
                "native_account_invalid")
        canonical_groups(account.get("group_ids"), allow_empty=True)
        panel_key = account["panel_key"]
        unique = (panel_key, account["id"])
        named = (panel_key, account["username"])
        require(unique not in by_remote and named not in by_username,
                "native_duplicate_identity")
        by_remote[unique] = account
        by_username[named] = account

    issues = defaultdict(set)
    physical = {}
    for oid, order in orders.items():
        require(positive(order.get("server_id")) and order["server_id"] in servers and
                type(order.get("username")) is str and USERNAME.fullmatch(order["username"]) and
                positive(order.get("owner_id")) and
                type(order.get("plan_id")) is int and order["plan_id"] >= 0 and
                order.get("inbound_id") == 0 and order.get("provider_effect_id") is None and
                order.get("provider_remote_user_id") is None and
                order.get("auto_renew") in ("on", "off") and
                nonnegative(order.get("amount")), "source_order_invalid")
        sid, name = order["server_id"], order["username"]
        if order["plan_id"]:
            plan = plans.get(order["plan_id"])
            if plan is None or plan["server_id"] != sid:
                issues["order_plan_mismatch"].add(oid)
        key = (sid, name)
        physical.setdefault(key, []).append(order)

    allocation = {}
    resolved = {}
    for (sid, username), refs in physical.items():
        panel_key = server_map[str(sid)]
        account = by_username.get((panel_key, username))
        reference = min(r["id"] for r in refs)
        if account is None:
            issues["native_account_missing"].add(reference)
            continue
        remote_key = (panel_key, account["id"])
        existing = allocation.get(remote_key)
        if existing is not None and existing != (sid, username):
            issues["cross_server_identity_collision"].add(reference)
            continue
        allocation[remote_key] = (sid, username)
        if not account["native_schedules_clear"]:
            issues["native_scheduling_conflict"].add(reference)
        expected = {plan_groups[o["plan_id"]] for o in refs if o["plan_id"] in plan_groups}
        if len(expected) > 1:
            issues["shared_account_plan_conflict"].add(reference)
        elif expected and next(iter(expected)) != tuple(account["group_ids"]):
            issues["native_group_mismatch"].add(reference)
        resolved[(sid, username)] = account

    for key in ("unsettled_service_payments", "uncertain_provider_effects", "uncommitted_plan_changes"):
        if claims[key]:
            issues["unresolved_" + key].add("global")

    stats = {
        "tenant": local["tenant"],
        "servers": len(servers),
        "plans": len(plans),
        "local_order_references": len(orders),
        "unique_source_accounts": len(physical),
        "shared_reference_excess": len(orders) - len(physical),
        "auto_renew_on": sum(o["auto_renew"] == "on" for o in orders.values()),
        "planless_orders": sum(o["plan_id"] == 0 for o in orders.values()),
        "native_accounts_available": len(by_remote),
        "native_accounts_matched": len(resolved),
        "native_accounts_unrelated": len(by_remote) - len(allocation),
    }
    private_map = []
    for oid, order in sorted(orders.items()):
        account = resolved.get((order["server_id"], order["username"]))
        if account is None:
            continue
        private_map.append({
            "order_id": oid,
            "server_id": order["server_id"],
            "plan_id": order["plan_id"],
            "owner_id": order["owner_id"],
            "panel_id": panel_by_key[server_map[str(order["server_id"])]]["id"],
            "remote_user_id": account["id"],
            "remote_created_at": account["created_at"],
            "native_groups": account["group_ids"],
            "planned_groups": list(plan_groups[order["plan_id"]]) if order["plan_id"] in plan_groups else None,
        })
    report = {
        "version": 1,
        "ready_for_production_cutover": False,
        "proof_boundary": "offline_manifest_only; live_native_payment_and_config_continuity_unverified",
        "complete": not issues and len(resolved) == len(physical),
        "statistics": stats,
        "blocker_counts": dict(sorted((code, len(ids)) for code, ids in issues.items())),
        "unresolved_claim_counts": claims,
        "private_map_size": len(private_map),
    }
    return report, private_map


def private_write(destination: Path, data):
    # Personal and native identifiers must never be written into this repository.
    resolved = destination.resolve(strict=False)
    require(not resolved.is_relative_to(ROOT), "private_map_inside_repository")
    fd = os.open(destination, os.O_WRONLY | os.O_CREAT | os.O_EXCL | getattr(os, "O_NOFOLLOW", 0), 0o600)
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as output:
            json.dump(data, output, ensure_ascii=False, sort_keys=True, separators=(",", ":"))
            output.write("\n")
    except BaseException:
        try:
            destination.unlink()
        except OSError:
            pass
        raise


def main(argv=None):
    parser = argparse.ArgumentParser(description="Validate future AChBot Marzban-to-PasarGuard mapping, offline only")
    parser.add_argument("--local", type=Path, required=True)
    parser.add_argument("--native", type=Path, required=True)
    parser.add_argument("--mapping", type=Path, required=True)
    parser.add_argument("--private-map", type=Path, help="Operator-only mapping; fails if already exists")
    args = parser.parse_args(argv)
    try:
        local, local_hash = load(args.local)
        native, native_hash = load(args.native)
        mapping, mapping_hash = load(args.mapping)
        report, proposed = reconcile(local, native, mapping)
        report["input_sha256"] = {
            "local": local_hash, "native": native_hash, "mapping": mapping_hash,
        }
        if args.private_map and report["complete"]:
            private_write(args.private_map, {"version": 1, "tenant": local["tenant"],
                                            "input_sha256": report["input_sha256"],
                                            "references": proposed})
        print(json.dumps(report, sort_keys=True, separators=(",", ":")))
        return 0 if report["complete"] else 2
    except (ValueError, TypeError, KeyError, OSError, json.JSONDecodeError) as error:
        # Reason codes and exception type only; never echo input contents, paths or credentials.
        print(json.dumps({"complete": False, "error_code": str(error) if isinstance(error, ValueError)
                          else "invalid_input_or_io"}))
        return 2


if __name__ == "__main__":
    sys.exit(main())
