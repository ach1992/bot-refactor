#!/usr/bin/env python3
"""Native PasarGuard Group export: actual PHP, loopback GET only, private output."""
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

import test_post_v9_pasarguard_client as pg

ROOT=Path(__file__).resolve().parents[1]
SCRIPT=ROOT/"tools/post_v9_pg_cutover_group_catalog.php"


class GroupCatalog(unittest.TestCase):
    def case(self,mode="ok",insecure=False):
        with tempfile.TemporaryDirectory(prefix="pg-native-group-") as d:
            folder=Path(d)
            with pg.panel() as (server,url):
                server.mode=mode
                connections={"version":1,"panels":{"main":{
                    "id":71,"instance_key":"a"*32,"api_url":url,
                    "api_key":"fixture-private-key","version":"anything"}}}
                sec=folder/"private.json"
                sec.write_text(json.dumps(connections))
                sec.chmod(0o644 if insecure else 0o600)
                prepend=folder/"prepend.php"
                prepend.write_text("<?php define('ACHBOT_PG_TEST_LOOPBACK',true);")
                out=folder/"groups.json"
                p=subprocess.run(["php","-d","auto_prepend_file="+str(prepend),
                    str(SCRIPT),"--connections="+str(sec),"--panel-alias=main",
                    "--output="+str(out)],capture_output=True,text=True,timeout=20)
                self.assertNotIn("fixture-private-key",p.stdout+p.stderr)
                self.assertTrue(all(method=="GET" for method,_,_ in server.trace))
                self.assertEqual(server.write_count,0)
                if p.returncode==0:
                    groups=json.loads(out.read_text())
                    self.assertEqual(groups["version"],1)
                    self.assertEqual({e["id"] for e in groups["groups"]},{2,5})
                    self.assertEqual(out.stat().st_mode & 0o777,0o600)
                else:
                    self.assertFalse(out.exists())
                return p.returncode,json.loads(p.stdout)

    def test_success(self):
        code,result=self.case()
        self.assertEqual(code,0,result)

    def test_vendor_version_informational(self):
        code,result=self.case("wrong_version")
        self.assertEqual(code,0,result)

    def test_bad_permissions_hold(self):
        code,result=self.case(insecure=True)
        self.assertEqual(code,2)
        self.assertEqual(result["error_code"],"private_connection_invalid")

    def test_disabled_or_missing_admin_authority_hold(self):
        for mode in ("permission_missing","template_only"):
            code,result=self.case(mode)
            self.assertEqual(code,2)
            self.assertEqual(result["error_code"],"native_group_check_unavailable")


if __name__=="__main__":
    unittest.main()
