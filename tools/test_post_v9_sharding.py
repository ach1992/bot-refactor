#!/usr/bin/env python3
"""Eight PHP8.5 family contracts and fail-closed aggregate (static synthetic proofs).

No real PHP version, provider, financial operation or MariaDB server is run.
"""
from __future__ import annotations

import argparse
import contextlib
import copy
import io
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

import check_post_v9
import post_v9_shards as shard
import post_v9_suites as suites


def identity():
    return {
        "head": "a" * 40, "tree": "b" * 40, "dirty": False,
        "php_version_id": 80500,
        "runtime_sha256": {name: "c" * 64 for name in shard.package.members()},
        "validation_sha256": {name: "d" * 64 for name in shard.IDENTITY_TOOLS},
    }


def foundation():
    source = shard._base("foundation", identity())
    payload = {
        "source": copy.deepcopy(shard.SOURCE_FACTS),
        "payment_reference_assurance": {
            "complete": True, "crypto_source": "random_int",
            "default_length": 20, "unique_generated": 500,
            "longest_callback_bytes": 64,
        },
        "customer_error_privacy": {"complete": True},
    }
    source.update({
        "foundation_complete": True, "payload": payload,
        "payload_sha256": shard.digest(payload),
        "executed": ["source", "payment_reference_assurance", "customer_error_privacy"],
        "elapsed_seconds": 1.2, "source_seconds": 0.8,
    })
    return source


def family(index: int):
    name = shard.FAMILIES[index - 1]
    payload = {
        key: (1 if key in shard.INTEGER_FIELDS else {"complete": True, "synthetic": True})
        for key in suites.FAMILY_FIELDS[name]
    }
    updates = {
        key: (True if key == "current_additive_migration_separately_tested"
              else {"complete": True, "synthetic": True})
        for key in suites.SOURCE_UPDATES[name]
    }
    report = shard._base("shard", identity())
    report.update({
        "shard_complete": True, "index": index,
        "count": shard.COUNT, "family": name,
        "payload": payload, "source_updates": updates,
        "payload_sha256": shard.digest({"payload": payload, "source_updates": updates}),
        "elapsed_seconds": float(index),
    })
    return report


class ShardContractTests(unittest.TestCase):
    def setUp(self):
        self.source = foundation()
        self.reports = [family(i) for i in range(1, 9)]

    def test_php85_noop_close_and_case_label_regressions(self):
        # Native PHP8.5 tests revealed these deprecations in live sources.
        for path in ("src/Finance/PaymentEvidence.php", "src/Panel/Accounts.php"):
            with self.subTest(path=path):
                self.assertNotIn("curl_close(", (shard.ROOT / path).read_text())
        self.assertNotIn("case 'k';", (shard.ROOT / "settings/jdf.php").read_text())

    def test_exact_eight_disjoint_families_own_all_original_fields(self):
        catalog = shard.catalog()
        self.assertEqual(tuple(catalog), (
            "admin", "api", "services", "wallet", "presentation",
            "authorization", "effects", "funding"))
        owned = [field for group in catalog.values() for field in group["fields"]]
        self.assertEqual(len(owned), 69)
        self.assertEqual(len(owned), len(set(owned)))
        self.assertEqual(set(owned) & set(shard.FOUNDATION_FIELDS), set())

    def test_complete_aggregate_requires_real_validated_family_proofs(self):
        result = shard.aggregate(self.source, self.reports)
        self.assertIs(result["complete"], True)
        # All 77 pre-sharding fields remain, plus the explicit validation record.
        self.assertEqual(len(result), 78)
        self.assertIs(result["historical_suite_requires_separate_pinned_ci_execution"], False)
        self.assertEqual(result["validation"]["scope"], "full_current_php85")
        self.assertEqual(result["validation"]["families"], list(shard.FAMILIES))
        self.assertEqual(len(result["validation"]["family_seconds"]), 8)
        self.assertEqual(result["validation"]["maximum_family_seconds"], 8.0)
        self.assertIs(result["source"]["current_additive_migration_separately_tested"], True)
        self.assertIs(result["actual_operator_installed_acceptance"], False)
        self.assertIs(result["real_financial_safety_approved"], False)
        self.assertIs(result["historical_suite_executed_by_this_script"], False)

    def test_selected_foundation_and_every_shard_remain_incomplete(self):
        self.assertIs(self.source["complete"], False)
        for item in self.reports:
            self.assertIs(item["complete"], False)
            shard.validate_shard(item)
        shard.validate_foundation(self.source)

    def test_missing_or_duplicate_shard_cannot_report_full(self):
        with self.assertRaises(ValueError):
            shard.aggregate(self.source, self.reports[:-1])
        replaced = copy.deepcopy(self.reports)
        replaced[-1] = copy.deepcopy(replaced[0])
        with self.assertRaises(ValueError):
            shard.aggregate(self.source, replaced)

    def test_unknown_family_index_count_or_false_complete_rejected(self):
        for key, value in (("family", "invented"), ("index", 2),
                           ("count", 7), ("shard_complete", False),
                           ("complete", True)):
            with self.subTest(key=key):
                mutated = copy.deepcopy(self.reports[0])
                mutated[key] = value
                with self.assertRaises(ValueError):
                    shard.validate_shard(mutated)
        for index, count in ((0, 8), (9, 8), (1, 7), (True, 8)):
            with self.subTest(index=index, count=count):
                with self.assertRaises(ValueError):
                    shard.run(index, count)

    def test_runtime_identity_immutable_and_php85_only(self):
        for key, value in (("head", "unknown"), ("dirty", True),
                           ("php_version_id", 80400),
                           ("php_version_id", 80600),
                           ("php_version_id", "80500"),
                           ("runtime_sha256", {}),
                           ("validation_sha256", {})):
            with self.subTest(key=key, value=str(value)[:18]):
                mutated = identity()
                mutated[key] = value
                with self.assertRaises(ValueError):
                    shard.validate_identity(mutated)

    def test_cross_shard_head_php_and_input_hash_drift_rejected(self):
        for key, value in (("head", "e" * 40), ("tree", "f" * 40),
                           ("php_version_id", 80501)):
            with self.subTest(key=key):
                mutated = copy.deepcopy(self.reports)
                mutated[4]["identity"][key] = value
                with self.assertRaises(ValueError):
                    shard.aggregate(self.source, mutated)
        mutated = copy.deepcopy(self.reports)
        member = next(iter(mutated[4]["identity"]["runtime_sha256"]))
        mutated[4]["identity"]["runtime_sha256"][member] = "f" * 64
        with self.assertRaises(ValueError):
            shard.aggregate(self.source, mutated)
        mutated = copy.deepcopy(self.reports)
        mutated[4]["identity"]["validation_sha256"]["tools/check_post_v9.py"] = "e" * 64
        with self.assertRaises(ValueError):
            shard.aggregate(self.source, mutated)

    def test_report_payload_tamper_and_missing_field_rejected(self):
        for mutator in (
            lambda r: r["payload"].pop(next(iter(r["payload"]))),
            lambda r: r["payload"].update({"forged": {"complete": True}}),
            lambda r: r.update({"payload_sha256": "0" * 64}),
        ):
            modified = copy.deepcopy(self.reports)
            mutator(modified[0])
            with self.assertRaises(ValueError):
                shard.aggregate(self.source, modified)

    def test_missing_migration_execution_is_never_static_success(self):
        changed = copy.deepcopy(self.reports)
        changed[-1]["source_updates"]["current_additive_migration_separately_tested"] = False
        changed[-1]["payload_sha256"] = shard.digest({
            "payload": changed[-1]["payload"], "source_updates": changed[-1]["source_updates"]
        })
        with self.assertRaises(ValueError):
            shard.aggregate(self.source, changed)
        bad = copy.deepcopy(self.source)
        bad["payload"]["source"]["current_additive_migration_separately_tested"] = True
        bad["payload_sha256"] = shard.digest(bad["payload"])
        with self.assertRaises(ValueError):
            shard.aggregate(bad, self.reports)

    def test_synthetic_safety_claims_cannot_claim_production(self):
        modified = copy.deepcopy(self.source)
        modified["safety"]["production_database_or_service_modified"] = True
        with self.assertRaises(ValueError):
            shard.aggregate(modified, self.reports)
        modified = copy.deepcopy(self.reports)
        modified[0]["safety"]["real_financial_safety_approved"] = True
        with self.assertRaises(ValueError):
            shard.aggregate(self.source, modified)

    def test_cli_aggregates_only_exact_complete_artifacts(self):
        with tempfile.TemporaryDirectory(prefix="achbot-shard-contract-") as tmp:
            root = Path(tmp)
            source = root / "foundation.json"
            directory = root / "reports"
            directory.mkdir()
            output = root / "final.json"
            source.write_text(json.dumps(self.source))
            for number, item in enumerate(self.reports, 1):
                (directory / f"shard-{number}.json").write_text(json.dumps(item))
            with contextlib.redirect_stdout(io.StringIO()):
                shard.main(["aggregate", "--source", str(source), "--reports",
                            str(directory), "--report", str(output)])
            self.assertIs(json.loads(output.read_text())["complete"], True)
            (directory / "shard-8.json").unlink()
            with self.assertRaises(ValueError):
                with contextlib.redirect_stdout(io.StringIO()):
                    shard.main(["aggregate", "--source", str(source), "--reports",
                                str(directory), "--report", str(output)])

    def test_serial_default_delegates_to_the_same_full_implementation(self):
        candidate = argparse.Namespace(report=None)
        source = copy.deepcopy(shard.SOURCE_FACTS)
        with patch.object(shard, "full_checks", return_value={"complete": True}) as stub:
            actual = check_post_v9.full_checks(candidate, source)
        self.assertEqual(actual, {"complete": True})
        stub.assert_called_once_with(candidate, source)


if __name__ == "__main__":
    unittest.main()
