#!/usr/bin/env python3
"""SELECT-only export of a bot's existing PasarGuard connection to private 0600 JSON.

Reads the existing target's saved credentials; never logs or sends them to a provider.
The caller must be authorized to read these production secrets. Never run during
normal source-only preparation or into a repository/review artifact.
"""
from __future__ import annotations

import argparse
import json
import re
import subprocess
import sys
from pathlib import Path

import post_v9_pg_cutover_preflight as preflight


def connection_from_stdout(stdout: str, requested_id: int, alias: str) -> dict:
    rows=[line for line in stdout.splitlines() if line.strip()]
    preflight.require(len(rows)==1, "connection_not_unique")
    record=json.loads(rows[0])
    preflight.require(type(record) is dict and
                      record.get("server_id")==requested_id and
                      record.get("type")=="pasarguard" and
                      record.get("enabled")==1 and
                      preflight.positive(record.get("connection_id")) and
                      type(record.get("instance_key")) is str and
                      re.fullmatch(r"[a-f0-9]{32}", record["instance_key"]) is not None and
                      type(record.get("api_url")) is str and
                      record["api_url"].startswith("https://") and
                      not any(char.isspace() for char in record["api_url"]) and
                      type(record.get("api_key")) is str and
                      bool(record["api_key"]) and
                      len(record["api_key"]) <= 8192 and
                      all(33 <= ord(c) <= 126 for c in record["api_key"]),
                      "connection_invalid")
    panel={"id":record["connection_id"],
           "instance_key":record["instance_key"],
           "api_url":record["api_url"],"api_key":record["api_key"]}
    if type(record.get("provider_version")) is str and record["provider_version"]:
        panel["version"]=record["provider_version"]  # informational only
    return {"version":1,"panels":{alias:panel}}


def main(argv=None):
    parser=argparse.ArgumentParser(description="Private existing PasarGuard connection export; no provider HTTP")
    parser.add_argument("--tenant", choices=["vivan","hell"], required=True)
    parser.add_argument("--server-id", type=int, required=True)
    parser.add_argument("--panel-alias", required=True)
    parser.add_argument("--output", type=Path, required=True)
    args=parser.parse_args(argv)
    try:
        preflight.require(preflight.positive(args.server_id) and
                          re.fullmatch(r"[A-Za-z][A-Za-z0-9_-]{0,49}",args.panel_alias) is not None,
                          "connection_arguments_invalid")
        query=("SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ; "
               "START TRANSACTION READ ONLY; "
               "SELECT JSON_OBJECT('server_id',c.id,'type',c.type,'enabled',c.provider_enabled,"
               "'connection_id',c.provider_connection_id,'instance_key',c.provider_instance_key,"
               "'api_url',c.panel_url,'api_key',c.password,'provider_version',c.provider_version)"
               " FROM server_config c JOIN server_info i ON i.id=c.id "
               "WHERE c.id="+str(args.server_id)+"; ROLLBACK;")
        command=["mariadb","--protocol=SOCKET","--batch","--raw","--skip-column-names",
                 "--database=bot"+args.tenant,"--execute="+query]
        process=subprocess.run(command,stdout=subprocess.PIPE,stderr=subprocess.PIPE,
                               text=True,timeout=20,check=False)
        preflight.require(process.returncode==0,"connection_sql_unavailable")
        result=connection_from_stdout(process.stdout,args.server_id,args.panel_alias)
        preflight.private_write(args.output,result)
        # The credential itself, endpoint and instance are never printed.
        print(json.dumps({"complete":True,"tenant":args.tenant,
                          "connection_id":result["panels"][args.panel_alias]["id"],
                          "private_output":True},sort_keys=True))
        return 0
    except (ValueError,KeyError,TypeError,OSError,json.JSONDecodeError,
            subprocess.TimeoutExpired):
        print('{"complete":false,"error_code":"connection_export_unavailable"}')
        return 2


if __name__=="__main__":
    sys.exit(main())
