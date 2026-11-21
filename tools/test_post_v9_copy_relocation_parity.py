#!/usr/bin/env python3
"""Current-Stable proof: retired catalog entries only; all retained copy exact.

Reference is the approved current Stable source before the source/handler
ownership cleanup. No pre-Stable bot is run and no external service is used.
"""
from __future__ import annotations

import hashlib
import json
from pathlib import Path
import subprocess
import tempfile

import package_current
import test_post_v9_current_copy_ownership as owner

ROOT = Path(__file__).resolve().parents[1]
BASE = "270840a854b06bbe899b76249db68e4748d93739"
REMOVED_SHA256 = "7b7eb72cb6cc43de76b03ac3a63c4367f40ed3dce4c26008928452b0780d8482"
ADDED_SHA256 = "31088e8b9a9ee6cdc6a9a015aa8418b49ac6948302eb586a2da6db7f40b1e494"
REVIEW_R1_RETIRED = ('legacy_buttons.manual_usdt', 'legacy_messages.buy_custom_subscription_detail', 'manual_payment.api_payment.status_monitoring')
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
    check(len(removed) == 413 and digest(removed) == REMOVED_SHA256,
          "retired_key_footprint_changed")
    check(len(added) == 50 and digest(added) == ADDED_SHA256,
          "new_catalog_keys_not_owner_reviewed")
    check(set(REVIEW_R1_RETIRED) | {"legacy_messages.config_doesnt_exist", "subscription.buy_helpers.build_links_and_send_qr.start.e4bb9f1ece"} <= set(removed),
          "current_source_orphan_keys_not_retired")
    common = set(old) & set(new)
    changed = sorted(k for k in common if old[k] != new[k])
    check(not changed, "previously_rendered_copy_modified:" + str(changed[:12]))
    check({k for k in added if not k.startswith("web_login.")} ==
          set(EXPECTED_LABELS), "unexpected_nonweb_copy_added")
    check(all(new[k] == v for k, v in EXPECTED_LABELS.items()),
          "legacy_label_bytes_changed")
    assert len({k for k in added if k.startswith("web_login.")}) == 46

    sources = {p: (ROOT / p).read_text() for p in package_current.members()
               if p.endswith(".php") and p not in owner.AUDIENCES}
    # A removed legacy short name may still occur in unrelated protocol/SQL
    # data. Verify reachability at the actual catalog sinks, not lexical text.
    restored = {topic: dict(entries)
                for topic, entries in owner.loaded_catalog().items()}
    for qualified in removed:
        topic, short = qualified.split(".", 1)
        restored[topic][short] = old[qualified]
    check(owner.unresolved_copy_orphans(restored, sources) == removed,
          "removed_catalog_key_has_reachable_consumer")
    check(not owner.unresolved_copy_orphans(owner.loaded_catalog(), sources),
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
