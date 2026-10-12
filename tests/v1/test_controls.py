#!/usr/bin/env python3
"""Negative controls on disposable copies only. Never modify the development checkout."""
from __future__ import annotations
import io
import json
import shutil
import subprocess
import sys
import tempfile
import unittest
import zipfile
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'tools'))
import check_v1
import v1_contract as contract
from package_v1 import build_bytes


class Controls(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.context=contract.reference(); cls.old,cls.lock=cls.context.__enter__()
        cls.original,_=check_v1.extract(cls.old)
        cls.expected=check_v1.edges(cls.old,cls.original)
    @classmethod
    def tearDownClass(cls): cls.context.__exit__(None,None,None)
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory(prefix='achbot-v1-control-')
        self.root=Path(self.temp.name)
        self.addCleanup(self.temp.cleanup)
        # Only explicit tracked source/evidence, never actual baseInfo.php or runtime state.
        paths=list(self.lock['files'])+list(contract.MODULES)
        paths+=['tools/source_scan.php','tools/inventory.py','tools/check.py','tools/test_schema.py',
                'tests/run.py','tests/test_harness.py','tests/schema_probe.php']
        paths+=[str(p.relative_to(ROOT)) for folder in ['tests/fixtures','tests/support','docs/v0']
                for p in (ROOT/folder).rglob('*') if p.is_file()]
        for file in set(paths):
            dest=self.root/file; dest.parent.mkdir(parents=True,exist_ok=True); shutil.copyfile(ROOT/file,dest)
    def reject(self):
        with self.assertRaises(RuntimeError): contract.verify(self.root,self.old,self.lock)
    def edit(self,file,old,new):
        p=self.root/file; data=p.read_bytes(); self.assertEqual(data.count(old),1)
        p.write_bytes(data.replace(old,new,1))
    def test_current_candidate_contract(self):
        self.assertEqual(contract.verify(self.root,self.old,self.lock)['extracted_helpers'],20)
    def test_module_output_mutation(self):
        self.edit('src/Presentation/Text.php',b"'https://t.me/'",b"'https://t.me/changed/'")
        self.reject()
        funcs,_=check_v1.extract(self.root)
        self.assertNotEqual(self.expected,check_v1.edges(self.root,funcs))
    def test_wrapper_argument_mutation(self):
        self.edit('config.php',b'supportUrl($botState, $fallback)',b"supportUrl($botState, 'changed')")
        self.reject()
        funcs,_=check_v1.extract(self.root)
        self.assertNotEqual(self.expected,check_v1.edges(self.root,funcs))
    def test_missing_runtime_module(self):
        (self.root/'src/Telegram/Response.php').unlink(); self.reject()
    def test_schema_mutation(self):
        p=self.root/'database/legacy-schema.sql'; p.write_bytes(p.read_bytes()+b'\n'); self.reject()
    def test_unrelated_bootstrap_mutation(self):
        p=self.root/'config.php'; p.write_bytes(p.read_bytes()+b'\n'); self.reject()
    def test_golden_rewrite_rejected(self):
        p=self.root/'tests/fixtures/golden.json'; p.write_bytes(p.read_bytes()+b'\n'); self.reject()
    def test_extra_module_rejected(self):
        (self.root/'src/unexpected.php').write_text('<?php echo "unexpected";'); self.reject()
    def test_unexpected_application_php(self):
        (self.root/'unexpected.php').write_text('<?php echo "unexpected";'); self.reject()
    def test_symlink_module_directory(self):
        (self.root/'src').rename(self.root/'other_modules')
        (self.root/'src').symlink_to(self.root/'other_modules',target_is_directory=True)
        self.reject()
    def test_standalone_module_load(self):
        funcs,_=check_v1.extract(self.root)
        self.assertEqual(self.expected,check_v1.edges(self.root,funcs,mode='internal'))
    def test_runtime_archive(self):
        # Private files exist but must be excluded rather than deleted or copied.
        (self.root/'baseInfo.php').write_text('SYNTHETIC PRIVATE CONFIG')
        (self.root/'error_log').write_text('SYNTHETIC LOG')
        first,manifest=build_bytes(self.root,self.lock); second,_=build_bytes(self.root,self.lock)
        self.assertEqual(first,second)
        with zipfile.ZipFile(io.BytesIO(first)) as archive:
            self.assertEqual(set(archive.namelist()),set(manifest)); self.assertEqual(len(manifest),23)
            self.assertNotIn('baseInfo.php',manifest)
            self.assertFalse(any(p.startswith(('tests/','tools/','database/')) for p in manifest))
            for file in manifest: self.assertEqual(archive.read(file),(self.root/file).read_bytes())
        self.assertEqual((self.root/'baseInfo.php').read_text(),'SYNTHETIC PRIVATE CONFIG')


if __name__=='__main__': unittest.main(verbosity=2)
