#!/usr/bin/env python3
"""Pure offline cohort mapping regression tests; no database/provider access."""
from __future__ import annotations

import copy
import json
from pathlib import Path
import tempfile
import unittest

import post_v9_pg_cutover_mapping as mapping

COHORTS = json.loads((mapping.ROOT / "docs/post-v9/PASARGUARD-COHORTS.json").read_text())
CATALOG = {"version": 1, "groups": [
    {"id": 1, "name": "Plus", "is_disabled": False},
    {"id": 2, "name": "Prime", "is_disabled": False},
    {"id": 3, "name": "Lite", "is_disabled": False},
]}


class MappingTests(unittest.TestCase):
    def source(self, tenant="hell"):
        return {"version": 1, "tenant": tenant,
                "servers": [{"id": int(k), "provider": "marzban"}
                            for k in COHORTS["tenants"][tenant]],
                "plans": [{"id": i + 10, "server_id": int(k), "inbound_id": 0}
                          for i, k in enumerate(COHORTS["tenants"][tenant])],
                "orders": [{"id": 500, "server_id": 2}]}

    def test_owner_mapping_both_bots(self):
        for tenant, expected in (("vivan", {"2": 1, "3": 1, "12": 3, "13": 2}),
                                 ("hell", {"2": 1, "3": 1, "4": 3, "5": 3, "11": 2})):
            with self.subTest(tenant=tenant):
                source = self.source(tenant)
                generated = mapping.generate(source, COHORTS, CATALOG, "live")
                self.assertEqual({k: generated["plan_groups"][str(i + 10)][0]
                                 for i, k in enumerate(COHORTS["tenants"][tenant])}, expected)
                self.assertEqual(set(generated["server_panels"]),
                                 set(COHORTS["tenants"][tenant]))
                self.assertEqual(set(generated["server_panels"].values()), {"live"})

    def test_catalog_is_not_hardcoded_to_observed_ids(self):
        catalog = copy.deepcopy(CATALOG)
        for group in catalog["groups"]:
            group["id"] += 100
        generated = mapping.generate(self.source(), COHORTS, catalog, "trusted")
        self.assertEqual(generated["plan_groups"]["10"], [101])
        self.assertEqual(generated["plan_groups"]["14"], [102])

    def test_disabled_group_refused(self):
        c = copy.deepcopy(CATALOG)
        c["groups"][0]["is_disabled"] = True
        with self.assertRaisesRegex(ValueError, "native_group_disabled"):
            mapping.generate(self.source(), COHORTS, c, "live")

    def test_missing_or_duplicate_groups_refused(self):
        for mutate in (lambda c: c["groups"].pop(),
                       lambda c: c["groups"].append(dict(c["groups"][0]))):
            c = copy.deepcopy(CATALOG)
            mutate(c)
            with self.assertRaises(ValueError):
                mapping.generate(self.source(), COHORTS, c, "live")

    def test_unexpected_legacy_server_refused(self):
        source = self.source()
        source["servers"].append({"id": 55, "provider": "marzban"})
        with self.assertRaisesRegex(ValueError, "cohort_servers_mismatch"):
            mapping.generate(source, COHORTS, CATALOG, "live")

    def test_existing_pasarguard_server_refused(self):
        source = self.source()
        source["servers"].append({"id": 12, "provider": "pasarguard"})
        with self.assertRaises(ValueError):
            mapping.generate(source, COHORTS, CATALOG, "live")

    def test_missing_plan_refused(self):
        source = self.source()
        source["plans"][0]["server_id"] = 9999
        with self.assertRaisesRegex(ValueError, "legacy_plan_invalid"):
            mapping.generate(source, COHORTS, CATALOG, "live")

    def test_private_output_and_no_overwrite(self):
        source = self.source()
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            for name, value in (("local.json", source),
                                ("cohorts.json", COHORTS), ("groups.json", CATALOG)):
                (root / name).write_text(json.dumps(value))
            args = ["--local", str(root / "local.json"),
                    "--cohorts", str(root / "cohorts.json"),
                    "--group-catalog", str(root / "groups.json"),
                    "--panel-alias", "live", "--output", str(root / "map.json")]
            self.assertEqual(mapping.main(args), 0)
            self.assertEqual((root / "map.json").stat().st_mode & 0o777, 0o600)
            self.assertEqual(mapping.main(args), 2)


if __name__ == "__main__":
    unittest.main()
