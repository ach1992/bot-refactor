#!/usr/bin/env python3
"""Self-tests and negative controls for V0 evidence (never edit application files)."""
from __future__ import annotations
import hashlib
import json
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'tools')); sys.path.insert(0,str(ROOT/'tests'))
import inventory
import run

class ParserTests(unittest.TestCase):
    def test_literal_is_not_concatenation_or_interpolation(self):
        self.assertEqual(inventory.literal("'abc'"),'abc')
        self.assertEqual(inventory.literal('"a\\nb"'),'a\nb')
        self.assertIsNone(inventory.literal("'abc'.'def'"))
        self.assertIsNone(inventory.literal('"prefix_$id"'))
    def test_balanced_function_source(self):
        code='<?php function outer($a) { $s="{$a}"; /* } */ $f=function(){ return "}"; }; function nested(){return 3;} return $s; }'
        with tempfile.TemporaryDirectory() as d:
            p=Path(d)/'sample.php'; p.write_text(code)
            result=json.loads(subprocess.check_output(['php','-n','-d','extension=tokenizer',str(ROOT/'tools/source_scan.php'),str(p)]))
        f={x['name']:x for x in result[str(p)]['functions']}
        self.assertEqual(set(f),{'outer','nested'})
        self.assertEqual(code[f['outer']['offset']:f['outer']['offset']+f['outer']['length']],code[6:])
    def test_sql_literal_whitespace_preserved(self):
        code="require 'tests/support/doubles.php'; echo achSql(\" SELECT  'two  spaces' AS  v \");"
        got=subprocess.check_output(['php','-n','-d','disable_functions='+run.DISABLED,'-r',code],cwd=ROOT).decode()
        self.assertEqual(got,"SELECT 'two  spaces' AS v")
    def test_schema_and_source_frozen(self):
        lock=run.assert_baseline()
        self.assertEqual(len(lock['files']),19)
        ddl=(ROOT/'database/legacy-schema.sql').read_text()
        self.assertEqual(ddl.count('CREATE TABLE '),23)
        self.assertNotIn('INSERT INTO ',ddl)
    def test_unknown_sql_fails_closed(self):
        # A mutated extracted function attempts an unexpected SELECT; script must fail, not return an empty row.
        self._expect_mutation_detected('wallet.success',b'SELECT * FROM `pays` WHERE `hash_id` = ? FOR UPDATE',
                                       b'SELECT * FROM `unexpected_table` WHERE `hash_id` = ? FOR UPDATE',True)
    def test_wallet_sign_mutation_is_observable(self):
        self._expect_mutation_detected('wallet.success',b'`wallet` = `wallet` - ?',b'`wallet` = `wallet` + ?')
    def test_transport_timeout_mutation_is_observable(self):
        self._expect_mutation_detected('transport.json',b'CURLOPT_TIMEOUT, 10',b'CURLOPT_TIMEOUT, 11')
    def test_output_mutation_is_observable(self):
        self._expect_mutation_detected('pure.contracts',b"'https://t.me/'",b"'https://t.me/changed/'")
    def _expect_mutation_detected(self,case,old,new,expect_error=False):
        with tempfile.TemporaryDirectory() as d:
            f=Path(d)/'functions.php'; manifest=Path(d)/'provenance.json'
            subprocess.run(['php','-n','-d','extension=tokenizer',str(ROOT/'tests/support/extract.php'),str(f),str(manifest)],check=True,capture_output=True)
            source=f.read_bytes(); self.assertTrue(old in source,'Mutation target missing: '+old.decode())
            mutated=source.replace(old,new,1)
            lock=json.loads((ROOT/'tests/fixtures/baseline-lock.json').read_text())
            if expect_error:
                with self.assertRaises(RuntimeError): run.run_case(case,mutated,list(lock['files']))
            else:
                got=run.run_case(case,mutated,list(lock['files']))
                expected=json.loads((ROOT/'tests/fixtures/golden.json').read_text())['cases'][case]
                self.assertNotEqual(got,expected,'Negative control escaped trace comparison')
        run.assert_baseline()

if __name__=='__main__': unittest.main(verbosity=2)
