#!/usr/bin/env python3
"""No secrets printed or sourced externally; synthetic connection output only."""
import json
import tempfile
from pathlib import Path
import unittest

import post_v9_pg_cutover_connection_export as source
import post_v9_pg_cutover_preflight as private


class Connection(unittest.TestCase):
    def setUp(self):
        self.row={"server_id":14,"type":"pasarguard","enabled":1,
                  "connection_id":14,"instance_key":"a"*32,
                  "api_url":"https://pg.example.invalid",
                  "api_key":"fixture-sensitive-key", "provider_version":"5.6.0"}

    def test_safe_private_snapshot(self):
        data=source.connection_from_stdout(json.dumps(self.row)+"\n",14,"main")
        with tempfile.TemporaryDirectory() as directory:
            path=Path(directory)/"secret.json"
            private.private_write(path,data)
            self.assertEqual(path.stat().st_mode & 0o777,0o600)
            self.assertEqual(json.loads(path.read_text())["panels"]["main"]["api_key"],
                             "fixture-sensitive-key")
            with self.assertRaises(FileExistsError):
                private.private_write(path,data)

    def test_no_missing_or_ambiguous_panel(self):
        with self.assertRaises(ValueError):
            source.connection_from_stdout("",14,"main")
        with self.assertRaises(ValueError):
            source.connection_from_stdout("\n".join([json.dumps(self.row)]*2),14,"main")

    def test_invalid_identity_or_credentials_refused(self):
        for key,value in [("server_id",12),("connection_id",None),
                          ("instance_key","invalid"),("api_key",""),
                          ("api_key","space break"),("enabled",0),
                          ("type","marzban"),("api_url","http://unsafe.invalid")]:
            with self.subTest(key=key):
                old=self.row[key];self.row[key]=value
                with self.assertRaises(ValueError):
                    source.connection_from_stdout(json.dumps(self.row),14,"main")
                self.row[key]=old


if __name__=="__main__":
    unittest.main()
