#!/usr/bin/env python3
"""Current-Stable proof: retired catalog entries only; all retained copy exact.

Reference is the approved current Stable source before the source/handler
ownership cleanup. No pre-Stable bot is run and no external service is used.
"""
from __future__ import annotations

import collections
import hashlib
import json
from pathlib import Path
import subprocess
import tempfile

import package_current
import test_post_v9_current_copy_ownership as owner

ROOT = Path(__file__).resolve().parents[1]
BASE = "270840a854b06bbe899b76249db68e4748d93739"
REMOVED_SHA256 = "39f54995be97088d92c8a57724c76bd7f534abf9d05470ecc528a7be90fa176b"
ADDED_SHA256 = "31088e8b9a9ee6cdc6a9a015aa8418b49ac6948302eb586a2da6db7f40b1e494"
EXPECTED_LABELS = {
    "automatic_gateway_review.unlimited_expiry": "نامحدود",
    "bulk_reporting.unknown_confirmed": "نامشخص",
    "manual_payment.api_payment.currency_rial": "ریال",
    "manual_payment.api_payment.currency_toman": "تومان",
}


def check(ok: bool, name: str) -> None:
    if not ok:
        raise AssertionError(name)


def load(files: dict[str, Path]) -> dict[str, object]:
    result = {}
    for audience, file in files.items():
        script = ("$d=require " + json.dumps(str(file)) +
                  ";echo json_encode($d,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);")
        groups = json.loads(subprocess.check_output(
            ["php", "-n", "-r", script], cwd=ROOT,
        ))
        for topic, entries in groups.items():
            for key, value in entries.items():
                name = topic + "." + key
                check(name not in result, "duplicate_cross_audience_key:" + name)
                result[name] = value
    return result


def digest(keys: list[str]) -> str:
    return hashlib.sha256(json.dumps(
        keys, separators=(",", ":"), ensure_ascii=False
    ).encode()).hexdigest()


def main() -> None:
    with tempfile.TemporaryDirectory(prefix="achbot-current-copy-original-") as folder:
        sources = {}
        for audience in ("user", "admin"):
            p = Path(folder) / (audience + ".php")
            p.write_bytes(subprocess.check_output([
                "git", "show", BASE + ":messages/fa/" + audience + ".php"
            ], cwd=ROOT))
            sources[audience] = p
        old = load(sources)
    new = load({a: ROOT / "messages/fa" / (a + ".php")
                for a in ("user", "admin")})
    removed = sorted(set(old) - set(new))
    added = sorted(set(new) - set(old))
    check(len(removed) == 408 and digest(removed) == REMOVED_SHA256,
          "retired_key_footprint_changed")
    check(len(added) == 50 and digest(added) == ADDED_SHA256,
          "new_catalog_keys_not_owner_reviewed")
    common = set(old) & set(new)
    changed = sorted(k for k in common if old[k] != new[k])
    check(not changed, "previously_rendered_copy_modified:" + str(changed[:12]))
    check({k for k in added if not k.startswith("web_login.")} ==
          set(EXPECTED_LABELS), "unexpected_nonweb_copy_added")
    check(all(new[k] == v for k, v in EXPECTED_LABELS.items()),
          "legacy_label_bytes_changed")
    assert len({k for k in added if k.startswith("web_login.")}) == 46

    paths = [p for p in package_current.members()
             if p.endswith(".php") and p not in owner.AUDIENCES]
    runtime = "\n".join((ROOT / path).read_text() for path in paths)
    for qualified in removed:
        topic, short = qualified.split(".", 1)
        if topic in ("legacy_messages", "legacy_buttons"):
            import re
            check(re.search(r"""['"]""" + re.escape(short) + r"""['"]""", runtime)
                  is None, "removed_alias_still_referenced:" + qualified)
        else:
            check(qualified not in runtime and
                  not any(qualified.startswith(prefix)
                          for prefix in owner.DYNAMIC_PREFIXES),
                  "removed_catalog_key_still_dynamic:" + qualified)

    check(not owner.unresolved_copy_orphans(owner.loaded_catalog(), runtime,
                                             owner.DYNAMIC_PREFIXES),
          "remaining_catalog_has_unaccounted_copy")
    print(json.dumps({
        "complete": True, "status": "PASS",
        "baseline": BASE,
        "existing_copy_values_preserved": len(common),
        "existing_copy_values_changed": 0,
        "removed_source_dead_copy": len(removed),
        "new_web_copy_entries": 46,
        "new_admin_label_entries": 4,
        "current_catalog_entries": len(new),
        "removed_key_sha256": digest(removed),
        "added_key_sha256": digest(added),
        "network_calls": 0,
    }, ensure_ascii=False))


if __name__ == "__main__":
    main()
