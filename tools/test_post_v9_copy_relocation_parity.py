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
RETIREMENT_SHA256 = "eb941ba8e0a75dd4bf50e33ec219540d66bbdc26dacffdd18350ae23d06b7ffd"


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
    with tempfile.TemporaryDirectory(prefix="achbot-copy-stable-") as folder:
        files = {}
        for audience in ("user", "admin"):
            p = Path(folder) / (audience + ".php")
            p.write_bytes(subprocess.check_output([
                "git", "show", stable + ":messages/fa/" + audience + ".php"
            ], cwd=ROOT))
            files[audience] = p
        old = load(files)
    new = load({a: ROOT / "messages/fa" / (a + ".php") for a in ("user","admin")})
    retired = sorted(set(old)-set(new))
    allowed = json.loads((ROOT / "tools/wallet_first_copy_corrections.json").read_text())
    actual = {k: {"before": old.get(k), "after": new[k]} for k in new if k not in old or old[k]!=new[k]}
    check(actual == allowed, "retained_copy_bytes_changed")
    check(len(retired)==460 and digest(retired)==RETIREMENT_SHA256, "retired_copy_set_changed")
    sources = {p:(ROOT/p).read_text() for p in package_current.members()
               if p.endswith(".php") and p not in owner.AUDIENCES}
    restored = {topic:dict(entries) for topic,entries in owner.loaded_catalog().items()}
    for name in retired:
        topic,key=name.split(".",1)
        restored.setdefault(topic,{})[key]=old[name]
    check(owner.unresolved_copy_orphans(restored,sources)==retired,"retired_copy_has_live_consumer")
    check(not owner.unresolved_copy_orphans(owner.loaded_catalog(),sources),"unresolved_current_copy")
    print(json.dumps({"complete":True,"status":"PASS","baseline":stable,
        "existing_copy_values_preserved":len(new)-len(allowed),"existing_copy_values_changed":sum(v["before"] is not None for v in allowed.values()),
        "explicit_new_copy":sum(v["before"] is None for v in allowed.values()),
        "removed_source_dead_copy":len(retired),"retired_key_sha256":digest(retired),
        "network_calls":0},ensure_ascii=False))


if __name__ == "__main__":
    main()
