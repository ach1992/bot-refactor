#!/usr/bin/env python3
"""AChBot V8 financial lifecycle acceptance with pinned V7/V0-V6.1 history.

All native tests use owned database socket and local HTTP. Never use installed
production bot, payment endpoints, live Cron, customer data or private config.
"""
from __future__ import annotations
import argparse
import hashlib
import io
import json
import os
import subprocess
import tarfile
import tempfile
from pathlib import Path
import v8_contract as c

ROOT=c.ROOT


def run(cmd:list[str],cwd:Path=ROOT,timeout=1500):
    p=subprocess.run(cmd,cwd=cwd,env=dict(os.environ,ACHBOT_PRIVATE_TEST="1"),
                     text=True,capture_output=True,timeout=timeout)
    if p.returncode:
        raise RuntimeError("Failed exact acceptance "+repr(cmd)+
              "\nstdout: "+p.stdout[-3100:]+"\nstderr: "+p.stderr[-3100:])
    return {"stdout_sha256":hashlib.sha256(p.stdout.encode()).hexdigest(),
            "last_line":p.stdout.strip().splitlines()[-1] if p.stdout.strip() else ""}


def pin_v7(target:Path):
    target.mkdir(parents=True,exist_ok=True)
    archive=c.git("archive","--format=tar",c.BASE)
    with tarfile.open(fileobj=io.BytesIO(archive)) as tar:
        members=tar.getmembers()
        if len(members)>2500 or sum(m.size for m in members)>150*1024*1024:
            raise RuntimeError("Pinned historical Git source archive too large")
        for member in members:
            path=Path(member.name)
            if path.is_absolute() or ".." in path.parts or not (member.isfile() or member.isdir()):
                raise RuntimeError("Invalid archive path or type")
        tar.extractall(target,filter="data")
    subprocess.run(["git","init","--quiet",str(target)],check=True,capture_output=True)
    objects=Path(c.git("rev-parse","--path-format=absolute","--git-common-dir").decode().strip())/"objects"
    alt=target/".git/objects/info/alternates"
    alt.parent.mkdir(parents=True,exist_ok=True)
    alt.write_text(str(objects)+"\n")
    for args in [("update-ref","refs/heads/frozen-v7",c.BASE),
                 ("symbolic-ref","HEAD","refs/heads/frozen-v7"),
                 ("read-tree",c.BASE)]:
        subprocess.run(["git","-C",str(target),*args],check=True,capture_output=True)
    if subprocess.check_output(["git","-C",str(target),"status","--porcelain"]).strip():
        raise RuntimeError("Historical V7 source tree not clean")
    if subprocess.check_output(["git","-C",str(target),"rev-parse","HEAD"]).decode().strip()!=c.BASE:
        raise RuntimeError("Historical V7 source SHA mismatch")


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--report",type=Path)
    parser.add_argument("--evidence-dir",type=Path)
    parser.add_argument("--php81",action="store_true")
    parser.add_argument("--focused",action="store_true",
                        help="Local V8-only feedback; NOT full historical acceptance")
    args=parser.parse_args()
    source=c.verify()
    version=int(subprocess.check_output(["php","-r","echo PHP_VERSION_ID;"]).decode())
    if args.php81 and not 80100<=version<80200:
        raise RuntimeError("PHP 8.1 gate cannot run on PHP "+str(version))
    if not args.php81 and not args.focused and not 80400<=version<80500:
        raise RuntimeError("Full V8 gate requires PHP 8.4; got "+str(version))
    checks={}
    php_members=[x for x in c.members() if x.endswith(".php")]
    for member in php_members:
        run(["php","-l",str(ROOT/member)],timeout=15)
    checks["php_syntax"]={"runtime_version":version,"php_files":len(php_members)}
    work=args.evidence_dir
    if work:work.mkdir(parents=True,exist_ok=True)
    suites=[
        ("financial_facades","test_v8_facades.py",False),
        ("v7_purchase_regression","test_v8_native.py",True),
        ("financial_lifecycle","test_v8_finance.py",True),
        ("finance_mutation_controls","test_v8_mutation.py",True),
        ("source_only_rollback","test_v8_swap.py",True),
        ("concurrent_financial_attempts","test_v8_concurrency.py",True),
        ("direct_http_guards","test_v8_http.py",False),
    ]
    for label,script,owned in suites:
        cmd=["python3",str(ROOT/"tools"/script)]
        if owned:cmd.append("--run-disposable")
        evidence=work/(label+".json") if work else None
        if evidence:cmd+=["--report",str(evidence)]
        checks[label]=run(cmd,timeout=600)
        result=json.loads(checks[label]["last_line"])
        if label=="v7_purchase_regression" and (result["scenarios"],result["cases"])!=(18,47):
            raise RuntimeError("V7 purchase coverage incomplete")
        if label=="financial_lifecycle" and (result["scenarios"],result["steps"])!=(52,68):
            raise RuntimeError("V8 finance coverage incomplete")
        if label=="finance_mutation_controls" and (result["mutants"]!=8 or not result["all_detected"]):
            raise RuntimeError("Mutation sensitivity degraded")
        if label=="source_only_rollback" and len(result["phases"])!=4:
            raise RuntimeError("V7/V8/V7/V8 source rollback did not finish")
        if label=="direct_http_guards" and result["guarded_http_requests"]!=54:
            raise RuntimeError("Missing direct module access protection")
        if label=="concurrent_financial_attempts" and (result["cases"]!=2 or not result["no_double_spend"]):
            raise RuntimeError("Concurrent row-lock financial controls did not execute")
        print("PASS "+label+" "+checks[label]["last_line"][:230],flush=True)
    if not args.focused:
        with tempfile.TemporaryDirectory(prefix="achbot-v8-pinned-v7-") as tmp:
            frozen=Path(tmp)/"frozen-v7"
            pin_v7(frozen)
            cmd=["python3",str(frozen/"tools/check_v7.py")]
            if args.php81:cmd.append("--php81")
            evidence=work/"frozen-v7.json" if work else Path(tmp)/"frozen-v7.json"
            cmd+=["--report",str(evidence)]
            if not args.php81 and work:
                cmd+=["--evidence-dir",str(work/"frozen-v7-evidence")]
            checks["full_pinned_v7_and_v0_to_v61"]=run(cmd,frozen,timeout=2600)
            v7=json.loads(evidence.read_text())
            if not v7["full_historical_v0_to_v61"] and not args.php81:
                raise RuntimeError("Pinned V0-V6.1 history was not exercised")
            if not v7["php81_compatibility"] and args.php81:
                raise RuntimeError("Frozen V7 PHP 8.1 compatibility missing")
            print("PASS immutable V7 -> V0-V6.1 evidence",flush=True)
    report={"complete":not args.focused,"php81":args.php81,
            "php_version_id":version,"source_members":len(c.members()),
            "financial_modules":len(c.MODULES),"source_pin":source["base"],
            "checks":checks,"owner_operator_acceptance":False,
            "owner_merge_approved":False,"public_release_published":False,
            "production_deployed":False,
            "historical_v5_v6_v7_operator_acceptance":"NOT TESTED",
            "v61_full_restore":"NOT TESTED"}
    if args.report:args.report.write_text(json.dumps(report,indent=2)+"\n")
    print(json.dumps({"complete":report["complete"],"php81":args.php81,
                      "checks":list(checks),"php_linted":len(php_members),
                      "source_members":len(c.members())}),flush=True)


if __name__=="__main__":main()
