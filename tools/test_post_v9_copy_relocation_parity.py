#!/usr/bin/env python3
"""Current-Stable proof: explicit retired, added and changed catalog entries; all other copy exact.

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
RETIREMENT_SHA256 = "2f9c39821b1680d38aa58eafe461b702c64a77abd8ed47c7136065314237137f"


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
    stable = "484c80e6ee030e8656da96edc3000311d10327b5"
    def at_revision(revision):
        with tempfile.TemporaryDirectory(prefix="achbot-copy-stable-") as folder:
            files = {}
            for audience in ("user", "admin"):
                p = Path(folder) / (audience + ".php")
                p.write_bytes(subprocess.check_output([
                    "git", "show", revision + ":messages/fa/" + audience + ".php"
                ], cwd=ROOT))
                files[audience] = p
            return load(files)
    old = at_revision(stable)
    mapping = json.loads((ROOT / "tools/current_storage_copy_mapping.json").read_text())
    previous = at_revision(mapping["baseline"])
    new = load({a: ROOT / "messages/fa" / (a + ".php") for a in ("user","admin")})
    prior_retired = sorted(set(old)-set(previous))
    allowed = json.loads((ROOT / "tools/wallet_first_copy_corrections.json").read_text())
    actual = {k: {"before": old.get(k), "after": previous[k]} for k in previous if k not in old or old[k]!=previous[k]}
    check(actual == allowed, "prior_retained_copy_bytes_changed")
    check(len(prior_retired)==481 and digest(prior_retired)==RETIREMENT_SHA256, "prior_retired_copy_set_changed")
    expected = dict(previous)
    for k, target in mapping["equal"].items():
        value = expected.pop(k)
        check(target not in expected or expected[target]==value,"semantic_copy_not_equal:"+k)
        expected[target] = value
    for k in mapping["dead"]:
        del expected[k]
    ux = json.loads((ROOT / "tools/ux_lifecycle_copy_corrections.json").read_text())
    ux_base = at_revision(ux["baseline"])
    check(expected == ux_base, "ux_copy_baseline_mismatch")
    for k, change in ux["changes"].items():
        check(expected.get(k) == change["before"], "ux_copy_before_mismatch:" + k)
        if change["after"] is None:
            del expected[k]
        else:
            expected[k] = change["after"]
    # Apply semantic sharing after the explicit approved wording changes.
    # This records a retired key exactly once without duplicating its prose.
    semantic=ux.get("semantic_equal",{})
    semantic_values={}
    for name,target in semantic.items():
        check(name in expected and name!=target and target not in semantic,
              "semantic_copy_invalid_mapping:"+name)
        value=expected.pop(name)
        check(target not in expected or expected[target]==value,
              "semantic_copy_not_equal:"+name)
        expected[target]=value
        semantic_values[name]=value
    check(new==expected, "current_copy_bytes_changed_outside_explicit_mapping")
    ux_retired = {k for k, change in ux["changes"].items() if change["after"] is None}
    retired = sorted(set(prior_retired)|set(mapping["equal"])|set(mapping["dead"])|ux_retired|set(semantic))
    sources = {p:(ROOT/p).read_text() for p in package_current.members()
               if p.endswith(".php") and p not in owner.AUDIENCES}
    restored = {topic:dict(entries) for topic,entries in owner.loaded_catalog().items()}
    for name in retired:
        topic,key=name.split(".",1)
        restored.setdefault(topic,{})[key]=(semantic_values[name] if name in semantic_values else
            ux_base[name] if name in ux_retired else previous[name] if name in previous else old[name])
    check(owner.unresolved_copy_orphans(restored,sources)==retired,"retired_copy_has_live_consumer")
    check(not owner.unresolved_copy_orphans(owner.loaded_catalog(),sources),"unresolved_current_copy")
    print(json.dumps({"complete":True,"status":"PASS","baseline":stable,
        "existing_copy_values_preserved":len(new)-len(allowed),"existing_copy_values_changed":sum(v["before"] is not None for v in allowed.values()),
        "explicit_new_copy":sum(v["before"] is None for v in allowed.values()),
        "removed_source_dead_copy":len(retired),"retired_key_sha256":digest(retired),
        "semantic_aliases_retired":len(semantic),
        "network_calls":0},ensure_ascii=False))


if __name__ == "__main__":
    main()
