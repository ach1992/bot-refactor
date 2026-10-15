#!/usr/bin/env python3
"""Actual PHP/23-table MariaDB/loopback-provider Marzban differential acceptance.

Pinned historical V9 is reference evidence, not an expected candidate runtime.
No real deployment credentials, Telegram, payment, Tron or external API calls.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import shutil
import socket
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.request
from pathlib import Path

import test_access_state as owned
import test_v6_native as native
import test_v6_panels as panels

ROOT = Path(__file__).resolve().parents[1]
V9 = "7eb077fd8e6d0a303421626d1500f69e295a6664"
V9_TREE = "7da17725c1e7f0448119b7e41c8b11b21f3e093d"
FACADES = """getMarzbanToken getMarzbanJson getMarzbanUserInfo getMarzbanUser
getMarzbanHosts addMarzbanUser editMarzbanConfig resetMarzbanTraffic
renewMarzbanUUID deleteMarzban changeMarzbanState getOrderTrafficInfo""".split()


def git(*args: str) -> bytes:
    return subprocess.check_output(["git", "-C", str(ROOT), *args])


def prepare(work: Path, stage: Path, candidate: bool) -> None:
    stage.mkdir(parents=True, exist_ok=False)
    if candidate:
        config = (ROOT / "config.php").read_bytes()
        get_bytes = lambda path: (ROOT / path).read_bytes()
    else:
        config = git("show", V9 + ":config.php")
        get_bytes = lambda path: git("show", V9 + ":" + path)
    (stage / "functions-under-test.php").write_bytes(panels.function_bodies(config, FACADES))
    for name in ("src/Panel/Accounts.php", "src/Subscription/AccountDetails.php"):
        dest = stage / name
        dest.parent.mkdir(parents=True, exist_ok=True)
        dest.write_bytes(get_bytes(name))
    (stage / "native.php").write_bytes((ROOT / "tests/v6/native.php").read_bytes())
    (stage / "baseInfo.php").write_text("<?php // owned synthetic placeholder\n")
    (stage / "private-state.json").write_text('{"fixture":"post-v9"}\n')


def no_provider_for_retired(work: Path, env: dict, stage: Path,
                            server: object, db: str, fn: str, args: list) -> dict:
    server.trace.clear()
    result = native.run(work, env, stage, db, {"function": fn, "args": args})
    if server.trace:
        raise RuntimeError("Retired panel issued outbound HTTP " + fn)
    if result["exception"] or result["stdout"] or result["warnings"]:
        raise RuntimeError("Retired panel error output: " + str(result))
    returned = result["result"]
    if fn == "getOrderTrafficInfo":
        if returned["found"] or returned["enable"]:
            raise RuntimeError("Unexpected retired order was found")
    elif not isinstance(returned, dict) or returned.get("detail") != "unsupported_panel":
        raise RuntimeError("Missing fail-closed provider status " + str(result))
    return result


def safe_core_negatives() -> dict:
    # Executed PHP, no live globals, network and DB side effects possible.
    # Only tests preflight. Do not call a valid provider in this function.
    stage = str(ROOT / "src/Purchase/Core.php")
    src = r'''
      $_SERVER['SCRIPT_FILENAME']='fixture-stub';
      function achbotSupportsPanel($type) { return $type === 'marzban'; }
      function addMarzbanUser(...$args) { throw new Exception('PROVIDER MUST NOT BE CALLED'); }
      require $argv[1];
      foreach (['sanaei','alireza','normal','unknown'] as $type) {
        $result=createServerAccount($type,3,0,7,'fake',2,30,'id','vless',443,0,'tcp',false,1);
        if (($result[0]->msg ?? '') !== 'unsupported_panel') exit(7);
      }
      $result=createServerAccount('marzban',2,7,7,'fake',2,30,'id','vless',443,0,'tcp',false,1);
      if (($result[0]->msg ?? '') !== 'unsupported_panel') exit(8);
      echo "SAFE";
    '''
    command = ["php", "-n", "-d", "disable_functions=exec,shell_exec,system,passthru,popen,proc_open,mail",
               "-r", src, stage]
    run = subprocess.run(command, capture_output=True, timeout=15)
    if run.returncode or run.stdout != b"SAFE" or run.stderr:
        raise RuntimeError("Marzban-only provisioning preflight failed: " +
                           repr([run.returncode,run.stdout[-500:],run.stderr[-500:]]))
    return {"checked": 5, "provider_requests": 0, "result": "PASS"}


def retired_sub_link(work: Path) -> dict:
    stage = work / "retired-sub-link"
    (stage / "settings").mkdir(parents=True, exist_ok=False)
    (stage / "settings/subLink.php").write_bytes((ROOT / "settings/subLink.php").read_bytes())
    # Use a loopback socket without any source/DB/private credentials available.
    sock = socket.socket()
    sock.bind(("127.0.0.1", 0))
    host, port = sock.getsockname()
    sock.close()
    log = work / "retired-sub-link-server.log"
    with log.open("wb") as output:
        env = {**os.environ, "http_proxy": "http://127.0.0.1:9",
               "https_proxy": "http://127.0.0.1:9", "all_proxy": "http://127.0.0.1:9",
               "no_proxy": "127.0.0.1,localhost"}
        process = subprocess.Popen(
            ["php", "-n", "-d", "allow_url_fopen=0",
             "-d", "open_basedir=" + str(stage),
             "-S", host+":"+str(port), "-t", str(stage)],
            cwd=stage, env=env, stdout=output, stderr=output)
        try:
            for i in range(100):
                if process.poll() is not None:
                    raise RuntimeError("Retired link HTTP server exited")
                try:
                    with socket.create_connection((host, port), timeout=.25):
                        break
                except OSError:
                    time.sleep(.05)
            else:
                raise RuntimeError("PHP built-in HTTP server not ready")
            samples = [
                ("GET", "/settings/subLink.php", 200, "Wrong token"),
                ("GET", "/settings/subLink.php?token=short", 200, "Wrong token"),
                ("GET", "/settings/subLink.php?token=" + "a"*30, 410,
                 "Legacy subscription link retired; use the current Marzban subscription URL."),
                ("POST", "/settings/subLink.php?token=" + "a"*30, 405, "Method not allowed"),
            ]
            for method, path, status, expected in samples:
                req = urllib.request.Request(f"http://{host}:{port}{path}", method=method)
                try:
                    with urllib.request.urlopen(req, timeout=5) as response:
                        got_status = response.status
                        body = response.read().decode()
                except urllib.error.HTTPError as response:
                    got_status = response.code
                    body = response.read().decode()
                if (got_status, body) != (status, expected):
                    raise RuntimeError("Retired link unexpectedly altered: " +
                                       repr((method, path,got_status,body)))
        finally:
            process.terminate()
            try:
                process.wait(timeout=5)
            except subprocess.TimeoutExpired:
                process.kill();process.wait(timeout=3)
    return {"http_cases":len(samples), "old_valid_token_http":410,"result":"PASS"}


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--run-disposable", action="store_true", required=True)
    ap.add_argument("--report", type=Path)
    args = ap.parse_args()
    if git("rev-parse", V9+"^{tree}").decode().strip() != V9_TREE:
        raise RuntimeError("Historical V9 source reference changed")
    current_cases = {k:v for k,v in native.case_defs().items() if k.startswith("marzban.")}
    # This contains positive and negative Marzban authentication, user
    # creation/deletion, usage, renewals and state transition API exercises.
    records = []
    with owned.owned_database() as (work, env), native.owned_provider() as (panel, url):
        native.seed(work, env, url)
        old = work / "historic-v9"
        current = work / "candidate"
        prepare(work,old,False)
        prepare(work,current,True)
        private = {
            p.name:hashlib.sha256(p.read_bytes()).hexdigest()
            for p in [old/"baseInfo.php",current/"baseInfo.php",
                      old/"private-state.json",current/"private-state.json"]
        }
        for name,(fn,arguments) in current_cases.items():
            panel.mode = (
                "marzban-invalid"
                if name.endswith(".invalid") or name == "marzban.user.unauthorized"
                else "marzban-503" if name=="marzban.token.service_503"
                else "ok"
            )
            results=[];traces=[]
            for stage,db in ((old,native.DBS[0]),(current,native.DBS[1])):
                panel.trace.clear()
                result=native.run(work,env,stage,db,{"function":fn,"args":arguments})
                results.append(result);traces.append(list(panel.trace))
            if results[0]!=results[1] or traces[0]!=traces[1]:
                raise RuntimeError("Marzban differential mismatch: "+name+
                                   "\n"+json.dumps({"historical":results[0],
                                                    "candidate":results[1],
                                                    "wire_hist":traces[0],
                                                    "wire_current":traces[1]},ensure_ascii=False)[:1700])
            if results[0]["exception"] or not traces[0]:
                raise RuntimeError("Marzban case not executed successfully: " + name)
            records.append({"case":name,"owned_http_requests":len(traces[0]),"result":"PASS"})
        prior_state = native.snapshot(work,env,native.DBS[1])
        for id,typ in ((3,"sanaei"),(4,"alireza")):
            result=no_provider_for_retired(work,env,current,panel,native.DBS[1],
                                           "getMarzbanToken",[id])
            records.append({"case":"retired_provider."+typ,"owned_http_requests":0,"result":"PASS"})
            result=no_provider_for_retired(work,env,current,panel,native.DBS[1],
                                           "getOrderTrafficInfo",[typ,id,"fixture-user","fixture-uuid",7])
            records.append({"case":"retired_traffic."+typ,"owned_http_requests":0,"result":"PASS"})
        if native.snapshot(work,env,native.DBS[1]) != prior_state:
            raise RuntimeError("Retired panel negative tests mutated original SQL rows")
        if native.snapshot(work,env,native.DBS[1]) != native.snapshot(work,env,native.DBS[0]):
            raise RuntimeError("Marzban native testing drifted SQL rows")
        if any(hashlib.sha256((current/name).read_bytes()).hexdigest()!=private[name]
               for name in ("baseInfo.php","private-state.json")):
            raise RuntimeError("Synthetic private state mutated")
        sub=retired_sub_link(work)
    core=safe_core_negatives()
    out={"complete":True,"historical_v9_reference":V9,
         "historical_full_all_panel_parity_not_claimed":True,
         "original_schema_socket_mariadb":23,
         "marzban_positive_cases":len(current_cases),
         "retired_provider_negative_cases":4,
         "marzban_http_wire_sql_parity":True,
         "private_state_preserved":True,
         "retired_subscription":sub,
         "unsupported_purchase_guard":core,
         "scenarios":records,
         "real_provider_installed_tested":False,
         "financial_safety_approved":False}
    if args.report:args.report.write_text(json.dumps(out,ensure_ascii=False,indent=2)+"\n")
    print(json.dumps({k:v for k,v in out.items() if k!="scenarios"},ensure_ascii=False))


if __name__=="__main__":main()
