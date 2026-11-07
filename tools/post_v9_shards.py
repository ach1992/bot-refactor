"""Isolated PHP8.5 acceptance families with strict, explicit aggregation."""
from __future__ import annotations

import argparse
import copy
import hashlib
import json
import math
import re
import subprocess
import sys
import tempfile
import time
from pathlib import Path

import check_post_v9 as checks
import package_post_v9 as package
import post_v9_suites as suites

ROOT = checks.ROOT
FAMILIES = tuple(suites.RUNNERS)
COUNT = 8
SCHEMA = "achbot.post-v9.validation.v1"
FOUNDATION_FIELDS = ("source", "payment_reference_assurance", "customer_error_privacy")
IDENTITY_TOOLS = (
    "tools/check_post_v9.py", "tools/post_v9_suites.py", "tools/post_v9_shards.py",
    "tools/package_post_v9.py", "tools/test_post_v9_cron_runner.py",
)
SAFETY = {"actual_operator_installed_acceptance": False, "real_financial_safety_approved": False,
          "production_database_or_service_modified": False}
SOURCE_FACTS = {
    "source_files": package.EXPECTED_RUNTIME_COUNT,
    "php_lint": package.EXPECTED_RUNTIME_COUNT - 1,
    "original_historical_schema_file_unchanged": True,
    "current_additive_migration_separately_tested": False,
    "synthetic_baseinfo_example_current_settings_removed": True,
    "obsolete_private_staff_authority_absent": True,
    "historic_contract_changed": False,
    "retired_provider_api_removed": True,
    "admin_list_debug_artifact_writer_absent": True,
    "pasarguard_native_implemented": True,
    "new_xui_implemented": False,
}
INTEGER_FIELDS = {"owner_gift_lock_negative_controls", "owner_gift_policy_negative_controls"}


def digest(value) -> str:
    return hashlib.sha256(json.dumps(value, sort_keys=True, separators=(",", ":"),
                                     ensure_ascii=False, allow_nan=False).encode()).hexdigest()


def catalog() -> dict:
    if len(FAMILIES) != COUNT or set(FAMILIES) != set(suites.FAMILY_FIELDS):
        raise RuntimeError("Acceptance family catalog is incomplete")
    fields = [field for family in FAMILIES for field in suites.FAMILY_FIELDS[family]]
    if len(fields) != len(set(fields)) or len(fields) != 70:
        raise RuntimeError("Acceptance field ownership is incomplete or overlapping")
    return {family: {"index": index, "fields": list(suites.FAMILY_FIELDS[family]),
                     "source_updates": list(suites.SOURCE_UPDATES[family])}
            for index, family in enumerate(FAMILIES, 1)}


def execution_identity() -> dict:
    php = int(subprocess.check_output(["php", "-r", "echo PHP_VERSION_ID;"], text=True))
    identity = {
        "head": checks.cmd("rev-parse", "HEAD").decode().strip(),
        "tree": checks.cmd("rev-parse", "HEAD^{tree}").decode().strip(),
        "dirty": bool(checks.cmd("status", "--porcelain")),
        "php_version_id": php,
        "runtime_sha256": {name: hashlib.sha256((ROOT / name).read_bytes()).hexdigest()
                           for name in package.members()},
        "validation_sha256": {name: hashlib.sha256((ROOT / name).read_bytes()).hexdigest()
                              for name in IDENTITY_TOOLS},
    }
    validate_identity(identity)
    return identity


def validate_identity(identity: dict) -> None:
    required = {"head", "tree", "dirty", "php_version_id", "runtime_sha256", "validation_sha256"}
    if not isinstance(identity, dict) or set(identity) != required:
        raise ValueError("Missing or malformed validation identity")
    if any(not isinstance(identity[key], str) or not re.fullmatch(r"[0-9a-f]{40}", identity[key])
           for key in ("head", "tree")):
        raise ValueError("Invalid validation Git identity")
    if identity["dirty"] is not False:
        raise ValueError("Complete acceptance requires a clean committed source tree")
    php = identity["php_version_id"]
    if type(php) is not int or not 80500 <= php < 80600:
        raise ValueError("Current acceptance requires PHP8.5 only")
    for key, expected in (("runtime_sha256", set(package.members())),
                          ("validation_sha256", set(IDENTITY_TOOLS))):
        actual = identity[key]
        if not isinstance(actual, dict) or set(actual) != expected or any(
                not isinstance(value, str) or not re.fullmatch(r"[0-9a-f]{64}", value)
                for value in actual.values()):
            raise ValueError("Missing or malformed input hashes: " + key)


def _base(kind: str, identity: dict) -> dict:
    return {"schema": SCHEMA, "kind": kind, "complete": False,
            "catalog_sha256": digest(catalog()), "identity": identity, "safety": dict(SAFETY)}


def foundation(source_result: dict | None = None) -> dict:
    started = time.monotonic()
    before = execution_identity()
    source_started = time.monotonic()
    source = checks.source_checks() if source_result is None else copy.deepcopy(source_result)
    source_seconds = time.monotonic() - source_started if source_result is None else None
    payload = suites.run_foundation(source)
    if execution_identity() != before:
        raise RuntimeError("Foundation inputs changed during execution")
    report = _base("foundation", before)
    report.update({"foundation_complete": True, "payload": payload,
                   "payload_sha256": digest(payload),
                   "executed": ["source", "payment_reference_assurance", "customer_error_privacy"],
                   "elapsed_seconds": time.monotonic() - started,
                   "source_seconds": source_seconds})
    validate_foundation(report)
    return report


def run(index: int, count: int = COUNT) -> dict:
    if type(index) is not int or type(count) is not int or count != COUNT or not 1 <= index <= count:
        raise ValueError("Choose one of the eight declared acceptance families")
    family = FAMILIES[index - 1]
    started = time.monotonic()
    before = execution_identity()
    with tempfile.TemporaryDirectory(prefix="achbot-post-v9-" + family + "-") as tmp:
        payload, updates = suites.RUNNERS[family](tmp)
    if execution_identity() != before:
        raise RuntimeError("Shard inputs changed during execution")
    report = _base("shard", before)
    report.update({"shard_complete": True, "family": family, "index": index, "count": count,
                   "payload": payload, "source_updates": updates,
                   "payload_sha256": digest({"payload": payload, "source_updates": updates}),
                   "elapsed_seconds": time.monotonic() - started})
    validate_shard(report)
    return report


def _validate_base(report: dict, kind: str) -> None:
    if not isinstance(report, dict) or report.get("schema") != SCHEMA or report.get("kind") != kind:
        raise ValueError("Missing or incompatible validation report")
    if report.get("complete") is not False or report.get("catalog_sha256") != digest(catalog()):
        raise ValueError("A partial or stale report cannot claim full acceptance")
    validate_identity(report.get("identity"))
    safety = report.get("safety")
    if not isinstance(safety, dict) or set(safety) != set(SAFETY) or any(
            safety[key] is not False for key in SAFETY):
        raise ValueError("Missing safe fixture execution evidence")
    elapsed = report.get("elapsed_seconds")
    if type(elapsed) not in (int, float) or not math.isfinite(elapsed) or elapsed < 0:
        raise ValueError("Invalid execution timing")


def _proof_types(payload: dict, expected) -> None:
    if not isinstance(payload, dict) or set(payload) != set(expected):
        raise ValueError("Missing, unknown or duplicate-owned report fields")
    for key, value in payload.items():
        if key in INTEGER_FIELDS:
            if type(value) is not int or value <= 0:
                raise ValueError("Missing negative control count: " + key)
        elif not isinstance(value, dict) or not value:
            raise ValueError("Missing native proof object: " + key)


def validate_foundation(report: dict) -> None:
    _validate_base(report, "foundation")
    if report.get("foundation_complete") is not True or report.get("executed") != [
            "source", "payment_reference_assurance", "customer_error_privacy"]:
        raise ValueError("Incomplete shared foundation")
    payload = report.get("payload")
    _proof_types(payload, FOUNDATION_FIELDS)
    if report.get("payload_sha256") != digest(payload):
        raise ValueError("Foundation payload digest mismatch")
    source = payload["source"]
    if set(source) != set(SOURCE_FACTS) or any(
            type(source.get(key)) is not type(value) or source[key] != value
            for key, value in SOURCE_FACTS.items()):
        raise ValueError("Incomplete source invariants or unexecuted migration claim")
    identity = payload["payment_reference_assurance"]
    if not (identity.get("complete") is True and identity.get("crypto_source") == "random_int"
            and identity.get("default_length") == 20 and identity.get("unique_generated") == 500
            and type(identity.get("longest_callback_bytes")) is int
            and identity["longest_callback_bytes"] <= 64):
        raise ValueError("Incomplete payment reference foundation")
    if payload["customer_error_privacy"].get("complete") is not True:
        raise ValueError("Incomplete customer privacy foundation")


def validate_shard(report: dict) -> None:
    _validate_base(report, "shard")
    family = report.get("family")
    if not isinstance(family, str) or family not in FAMILIES:
        raise ValueError("Unknown acceptance family")
    if (report.get("shard_complete") is not True or type(report.get("index")) is not int
            or report["index"] != FAMILIES.index(family) + 1
            or type(report.get("count")) is not int or report["count"] != COUNT):
        raise ValueError("Incomplete or mismatched acceptance shard")
    payload, updates = report.get("payload"), report.get("source_updates")
    _proof_types(payload, suites.FAMILY_FIELDS[family])
    if not isinstance(updates, dict) or set(updates) != set(suites.SOURCE_UPDATES[family]):
        raise ValueError("Unexpected or missing source proof updates")
    if family == "services" and (not isinstance(updates["unified_service_management"], dict)
                                  or not updates["unified_service_management"]):
        raise ValueError("Missing unified management execution evidence")
    if family == "funding" and updates["current_additive_migration_separately_tested"] is not True:
        raise ValueError("Missing actual migration execution evidence")
    if report.get("payload_sha256") != digest({"payload": payload, "source_updates": updates}):
        raise ValueError("Shard payload digest mismatch")


def aggregate(source: dict, reports: list[dict]) -> dict:
    validate_foundation(source)
    if len(reports) != COUNT:
        raise ValueError("Full acceptance requires all eight shard reports")
    selected = {}
    result = copy.deepcopy(source["payload"])
    for report in reports:
        validate_shard(report)
        family = report["family"]
        if family in selected:
            raise ValueError("Duplicate acceptance family: " + family)
        if report["identity"] != source["identity"]:
            raise ValueError("Shard/foundation source or PHP identity mismatch")
        if set(result) & set(report["payload"]):
            raise ValueError("Overlapping acceptance field ownership")
        selected[family] = report
        result.update(copy.deepcopy(report["payload"]))
        result["source"].update(copy.deepcopy(report["source_updates"]))
    if set(selected) != set(FAMILIES):
        raise ValueError("Missing acceptance family coverage")
    result.update({"complete": True,
                   "historical_suite_executed_by_this_script": False,
                   "historical_suite_requires_separate_pinned_ci_execution": False,
                   "actual_operator_installed_acceptance": False,
                   "real_financial_safety_approved": False})
    # Old historical PHP8.1/8.4 execution is archived, never relabelled PHP8.5.
    result["validation"] = {
        "schema": SCHEMA, "scope": "full_current_php85", "identity": source["identity"],
        "catalog_sha256": source["catalog_sha256"], "families": list(FAMILIES),
        "source_payload_sha256": source["payload_sha256"],
        "family_payload_sha256": {name: selected[name]["payload_sha256"] for name in FAMILIES},
        "foundation_seconds": source["elapsed_seconds"],
        "family_seconds": {name: selected[name]["elapsed_seconds"] for name in FAMILIES},
        "sum_family_seconds": sum(selected[name]["elapsed_seconds"] for name in FAMILIES),
        "maximum_family_seconds": max(selected[name]["elapsed_seconds"] for name in FAMILIES),
        "historical_policy": "Original V0-V9 source/schema pins preserved; former PHP8.1/8.4 execution archived.",
    }
    return result


def full_checks(args, source_result: dict) -> dict:
    """Default serial CLI uses exactly the same families and aggregate contract."""
    source = foundation(source_result)
    result = aggregate(source, [run(index) for index in range(1, COUNT + 1)])
    if args.report:
        args.report.write_text(json.dumps(result, indent=2, ensure_ascii=False) + "\n")
    print(json.dumps({"complete": True, "source": result["source"],
                      "scope": "full_current_php85", "families": list(FAMILIES),
                      "validation": result["validation"]}))
    return result


def _read(path: Path) -> dict:
    with path.open() as stream:
        return json.load(stream)


def main(argv=None) -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    commands = parser.add_subparsers(dest="command", required=True)
    base = commands.add_parser("foundation")
    base.add_argument("--report", type=Path, required=True)
    shard = commands.add_parser("run")
    shard.add_argument("--index", type=int, required=True)
    shard.add_argument("--count", type=int, default=COUNT)
    shard.add_argument("--report", type=Path, required=True)
    joined = commands.add_parser("aggregate")
    joined.add_argument("--source", type=Path, required=True)
    joined.add_argument("--reports", type=Path, nargs="+", required=True)
    joined.add_argument("--report", type=Path, required=True)
    args = parser.parse_args(argv)
    if args.command == "foundation":
        result = foundation()
    elif args.command == "run":
        result = run(args.index, args.count)
    else:
        paths = args.reports
        if len(paths) == 1 and paths[0].is_dir():
            paths = sorted(paths[0].rglob("shard-*.json"))
        if not paths or any(path.is_dir() for path in paths):
            raise ValueError("Provide exactly eight shard report files")
        result = aggregate(_read(args.source), [_read(path) for path in paths])
    args.report.write_text(json.dumps(result, indent=2, ensure_ascii=False) + "\n")
    print(json.dumps({"complete": result["complete"], "kind": result.get("kind", "aggregate"),
                      "family": result.get("family"), "count": result.get("count")}))


if __name__ == "__main__":
    try:
        main()
    except subprocess.CalledProcessError as error:
        # Only synthetic/disposable native tests run here. Preserve useful
        # bounded child diagnostics instead of masking the actual failure.
        print("Native acceptance child failed (exit %s)" % error.returncode,
              file=sys.stderr)
        for label, output in (("stderr", error.stderr), ("stdout", error.stdout)):
            if output:
                if isinstance(output, bytes):
                    output = output.decode("utf-8", "replace")
                print("Fixture %s (last 12000 chars):\n%s" % (label, output[-12000:]),
                      file=sys.stderr)
        raise
