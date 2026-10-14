#!/usr/bin/env python3
"""AChBot V8 relative include parity under real PHP entrypoint working directories.

Extract the actual statements from pinned V7 and V8 modules, exercise PHP's
include_path lookup through bot/Cron entrypoints in owned temporary trees.
No provider, database, production PHP or deployed bot is executed.
"""
from __future__ import annotations
import json
import os
import re
import subprocess
import tempfile
from pathlib import Path
import v8_contract as c

SITES = {
    "checkout_qr": ("bot.php", "src/Finance/Routes/Checkout.php"),
    "exchange_qr": ("bot.php", "src/Finance/Routes/CryptoAndExchange.php"),
    "automatic_receipt_qr": ("settings/rewardReport.php", "src/Finance/AutomaticReceipts.php"),
    "crypto_checker_qr": ("settings/tronChecker.php", "src/Finance/CryptoChecker.php"),
}
QR_INCLUDE = re.compile(rb"(?m)^[ \t]*(?:include|include_once)\s+[^;\r\n]*phpqrcode/qrlib\.php[^;\r\n]*;")


def origin_segment(entrypoint: str, module: str) -> bytes:
    for name,start,end in c.GROUPS[entrypoint]:
        if name==module:
            source=c.old(entrypoint)
            a=source.index(start)
            b=source.index(end,a+len(start)) if end is not None else len(source)
            return source[a:b]
    raise RuntimeError("Missing authoritative inclusion source")


def statement(body: bytes) -> str:
    found=QR_INCLUDE.findall(body)
    if len(found)!=1:
        raise RuntimeError("Expected one QR include site, found "+str(len(found)))
    return found[0].decode("utf-8").strip()


def execute(root: Path, entrypoint: str, module: str, include: str, modern: bool,
            cwd: Path, include_path: str) -> dict:
    entry=root/entrypoint
    entry.parent.mkdir(parents=True,exist_ok=True)
    script="<?php\n"
    if modern:
        probe=root/module
        probe.parent.mkdir(parents=True,exist_ok=True)
        probe.write_text("<?php\n"+include+"\n")
        import_path=os.path.relpath(probe,entry.parent).replace(os.sep,"/")
        script+="require __DIR__ . '/"+import_path+"';\n"
    else:
        script+=include+"\n"
    script+="echo json_encode([class_exists('QRcode',false),$GLOBALS['achbot_qr_probe']??'']);\n"
    entry.write_text(script)
    p=subprocess.run(["php","-n","-d","include_path="+include_path,
                      "-d","display_errors=0","-d","log_errors=0",str(entry)],
                     cwd=cwd,capture_output=True,text=True,timeout=8)
    if p.returncode!=0:
        return {"exit":p.returncode,"error":p.stderr[-250:],"stdout":p.stdout[-120:]}
    try: success=json.loads(p.stdout)
    except json.JSONDecodeError:raise RuntimeError("Unexpected probe response "+repr(p.stdout))
    return {"exit":0,"result":success}


def main():
    c.verify()
    sites={}
    for label,(entry,module) in SITES.items():
        old=statement(origin_segment(entry,module))
        new=statement((c.ROOT/module).read_bytes())
        sites[label]=(entry,module,old,new)
    includes={
        (p.as_posix(),line.decode()) for p in (c.ROOT/"src/Finance").rglob("*.php")
        for line in QR_INCLUDE.findall(p.read_bytes())
    }
    if len(includes)!=len(SITES):
        raise RuntimeError("New untested relative QR include site or duplicate")
    tested=0
    with tempfile.TemporaryDirectory(prefix="achbot-v8-include-parity-") as t:
        for label,(entry,module,old,new) in sites.items():
            for variation in ("root","script_dir","external","explicit_include_path"):
                fixture=Path(t)/label/variation
                old_root=fixture/"old";new_root=fixture/"new"
                out=[]
                for modern,root,expr in ((False,old_root,old),(True,new_root,new)):
                    qr=root/"phpqrcode/qrlib.php"
                    qr.parent.mkdir(parents=True,exist_ok=True)
                    qr.write_text("<?php class QRcode {} $GLOBALS['achbot_qr_probe']='expected-runtime-qr';\n")
                    other=fixture/"external"
                    other.mkdir(parents=True,exist_ok=True)
                    cwd={"root":root,"script_dir":(root/entry).parent,
                         "external":other,"explicit_include_path":other}[variation]
                    cwd.mkdir(parents=True,exist_ok=True)
                    path=(".:"+str(root) if variation=="explicit_include_path" else ".")
                    out.append(execute(root,entry,module,expr,modern,cwd,path))
                # Explicit ../ paths in legacy Cron code intentionally resolve
                # against cwd, not the original entrypoint directory. Preserve
                # inherited failures instead of converting them to new success.
                expected_loaded=(entry=="bot.php" or variation=="script_dir")
                expected={"exit":0,"result":[expected_loaded,
                          "expected-runtime-qr" if expected_loaded else ""]}
                if out[0]!=expected or out[1]!=out[0]:
                    raise RuntimeError("V8 QR include behavior differs "+repr({
                        "site":label,"cwd":variation,"baseline":out[0],"candidate":out[1],
                        "baseline_include":old,"candidate_include":new}))
                tested+=1
    print(json.dumps({"sites":len(sites),"entrypoint_php_cases":tested,
                      "all_relative_qr_includes_covered":True,"v7_v8_resolve_identically":True}))


if __name__=="__main__":main()
