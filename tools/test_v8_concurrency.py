#!/usr/bin/env python3
"""Actual overlapping old/new PHP mysqli transfer attempts on a private socket DB.

Barrier ensures two independent PHP processes have loaded the same customer
step before release; tests row locks, sufficient/insufficient balance and
final conserved money, without using any provider or live wallet.
"""
from __future__ import annotations
import argparse
import json
import os
import subprocess
import time
from pathlib import Path
import test_v8_finance as f
import test_v8_native as n
import test_v6_native as native
import test_access_state as support
import v8_contract as c


def worker_command(root,work):
    return ["php","-n","-d","extension=mysqlnd","-d","extension=mysqli",
       "-d","extension=curl","-d","extension=gd",
       "-d","open_basedir="+str(root)+":"+str(work),
       "-d","allow_url_fopen=0","-d","display_errors=0","-d","log_errors=0",
       "-d","memory_limit=128M",
       "-d","disable_functions=exec,shell_exec,system,passthru,popen,proc_open,pcntl_exec,pcntl_fork,mail,dl",
       str(root/"native-purchase.php")]


def launch(work,env,db,modern,case):
    barrier=work/("concurrent-v8" if modern else "concurrent-v7")
    if barrier.with_suffix(".release").exists():
        raise RuntimeError("Concurrency barrier unexpectedly already released")
    children=[]
    for idx in (1,2):
        root=work/("parallel-v8" if modern else "parallel-v7")/str(idx)
        n.stage(root,modern)
        sequence=f.route_block("wallettransfer",modern)
        token=idx
        wait=b"""$barrier=$spec['barrier'];
$token=(int)$spec['token'];
file_put_contents($barrier.'.'.$token,'ready');
$start=microtime(true);
while (!is_file($barrier.'.release')) {
    if (microtime(true)-$start>8) throw new RuntimeException('Owned race barrier timed out');
    usleep(10000);
}
"""
        sequence=sequence.replace(b"<?php\nGOTOSTART:;\n",
            b"<?php\nGOTOSTART:;\n"+wait,1)
        (root/"access-sequence.php").write_bytes(sequence)
        (root/"case.json").write_text(json.dumps({
            "actor":1001,"text":str(case["amount"]),
            "barrier":str(barrier),"token":token}))
        subenv=dict(env,ACHBOT_PRIVATE_TEST="1",ACHBOT_V7_NATIVE="1",
                     ACHBOT_DATABASE=db,ACHBOT_SOCKET=str(work/"mysql.sock"))
        child=subprocess.Popen(worker_command(root,work),cwd=root,env=subenv,
                               stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        children.append((child,root))
    try:
        deadline=time.monotonic()+9
        while not all((work/("concurrent-v8" if modern else "concurrent-v7")).with_suffix("."+str(x)).exists()
                      for x in (1,2)):
            if any(proc.poll() is not None for proc,_ in children):
                raise RuntimeError("Child failed before concurrent start")
            if time.monotonic()>deadline:
                raise RuntimeError("PHP workers did not reach barrier")
            time.sleep(.015)
        (work/("concurrent-v8" if modern else "concurrent-v7")).with_suffix(".release").write_text("go")
        responses=[]
        for child,root in children:
            out,err=child.communicate(timeout=18)
            if child.returncode or out or err or not (root/"result.json").exists():
                raise RuntimeError("Concurrent child failed "+repr((child.returncode,out[-100:],err[-200:])))
            response=json.loads((root/"result.json").read_text())
            if response["exception"]:
                raise RuntimeError("Concurrent PHP exception: "+str(response["exception"]))
            responses.append(response)
        # Either child may acquire the sender row lock first.
        return sorted(json.dumps(x,sort_keys=True,ensure_ascii=False) for x in responses)
    finally:
        for child,_ in children:
            if child.poll() is None:
                child.kill();child.wait(timeout=5)


def main():
    p=argparse.ArgumentParser();p.add_argument("--run-disposable",action="store_true",required=True)
    p.add_argument("--report",type=Path);a=p.parse_args();c.verify()
    cases=[{"name":"two_successful_transfers","amount":300,"starting":50000,
            "sender_after":49400,"recipient_after":50600},
           {"name":"insufficient_funds_race","amount":300,"starting":400,
            "sender_after":100,"recipient_after":50300}]
    result=[]
    # Each case needs fresh rendezvous paths. The owned DBs themselves are isolated.
    for case in cases:
        with support.owned_database() as (work,env),native.owned_provider() as (provider,url):
            n.init(work,env,url)
            snapshots=[];records=[]
            for db,modern in ((f.SIDE[0],False),(f.SIDE[1],True)):
                f.sql(work,env,
                    "UPDATE users SET step='tranfserUserAmount9002',wallet="+str(case["starting"])+
                    " WHERE userid=1001",db)
                outcomes=launch(work,env,db,modern,case)
                snapshots.append(f.finance_state(work,env,db))
                records.append(outcomes)
                wallet={int(row.split("\t")[0]):int(row.split("\t")[1])
                        for row in snapshots[-1]["users"].splitlines() if row}
                if (wallet[1001],wallet[9002])!=(case["sender_after"],case["recipient_after"]):
                    raise RuntimeError("Concurrent debit/credit or overdraft mismatch "+
                                       case["name"]+" "+repr(wallet))
            if snapshots[0]!=snapshots[1] or records[0]!=records[1]:
                raise RuntimeError("V7/V8 concurrent transfer parity diverged: "+case["name"])
            result.append({"case":case["name"],"attempts_per_side":2,
                           "wallet_balance_conserved":True,"source_parity":True})
            print("PASS concurrent "+case["name"],flush=True)
    outcome={"cases":len(result),"parallel_php_requests_per_case_per_side":2,
             "real_mysqli_row_locks":True,"no_double_spend":True,
             "results":result}
    if a.report:a.report.write_text(json.dumps(outcome,indent=2)+"\n")
    print(json.dumps(outcome))


if __name__=="__main__":main()
