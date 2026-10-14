#!/usr/bin/env python3
"""Verify V8 legacy PHP global API compatibility by exact old/new reflection."""
from __future__ import annotations
import argparse
import json
import os
import shutil
import subprocess
import tempfile
from pathlib import Path

import v7_contract as v7
import v8_contract as c


def php(root, script):
    proc=subprocess.run(
        ["php","-n","-d","open_basedir="+str(root),
         "-d","allow_url_fopen=0",str(root/script)],
        cwd=root,env={"PATH":os.environ.get("PATH","/usr/bin:/bin"),
                      "ACHBOT_PRIVATE_TEST":"1"},capture_output=True,timeout=15)
    if proc.returncode or proc.stderr:
        raise RuntimeError("Reflection execution failed: "+repr((proc.returncode,proc.stderr[-750:])))
    return json.loads(proc.stdout)


def stage(root,modern):
    root.mkdir(parents=True)
    (root/"buy_helpers.php").write_bytes(
        (c.ROOT/"buy_helpers.php").read_bytes() if modern else c.old("buy_helpers.php"))
    (root/"reflect-helpers.php").write_bytes((c.ROOT/"tests/v7/reflect.php").read_bytes())
    for filename in v7.MODULES + (c.MODULES if modern else []):
        src=c.ROOT/filename
        target=root/filename
        target.parent.mkdir(parents=True,exist_ok=True)
        shutil.copyfile(src,target)
    raw=c.old("config.php")
    snippets=[]
    for module,start,end in c.CONFIG:
        if raw.count(start)!=1 or raw.count(end)!=1:
            raise RuntimeError("Original global function boundary changed: "+module)
        a=raw.index(start);b=raw.index(end,a+len(start))
        snippets.append(raw[a:b])
    (root/"config-facades.php").write_bytes(
        b"<?php\n"+(
            b"\n".join(b"require __DIR__ . '/"+x[0].encode()+b"';" for x in c.CONFIG)
            if modern else b"\n".join(snippets)))
    names=["startWalletPayment","getAgentDiscounts","NOWPayments","applyDiscountToPay","getDiscountCodeKeys"]
    meta=b"""<?php
if (PHP_SAPI!=='cli' || getenv('ACHBOT_PRIVATE_TEST')!=='1') exit(3);
require __DIR__.'/config-facades.php';
$names=%s;
$out=[];
foreach($names as $name) {
    if(!function_exists($name))throw new RuntimeException('Missing V8 global facade: '.$name);
    $f=new ReflectionFunction($name);
    $args=[];
    foreach($f->getParameters() as $p){
        $args[]=[(string)$p->getName(),(string)$p->getType(),
            $p->isOptional(),$p->isDefaultValueAvailable()?$p->getDefaultValue():null,
            $p->isPassedByReference(),$p->isVariadic()];
    }
    $out[$name]=['parameters'=>$args,'return_type'=>(string)$f->getReturnType()];
}
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
""" % json.dumps(names).encode()
    (root/"reflect-config.php").write_bytes(meta)


def main():
    p=argparse.ArgumentParser();p.add_argument("--report",type=Path);a=p.parse_args()
    c.verify()
    with tempfile.TemporaryDirectory(prefix="achbot-v8-facades-") as tmp:
        versions=[]
        for modern in (False,True):
            folder=Path(tmp)/("new" if modern else "old")
            stage(folder,modern)
            versions.append({"helpers":php(folder,"reflect-helpers.php"),
                             "config":php(folder,"reflect-config.php")})
        if versions[0]!=versions[1]:
            raise RuntimeError("Legacy global function signature mismatch")
    result={"legacy_helper_facades":len(versions[0]["helpers"]),
            "finance_config_facades":len(versions[0]["config"]),
            "reflection_equal":True,"no_new_mandatory_parameters":True}
    if a.report:a.report.write_text(json.dumps(result,indent=2)+"\n")
    print(json.dumps(result))

if __name__=="__main__":main()
