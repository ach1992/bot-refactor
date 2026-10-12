#!/usr/bin/env python3
"""V2 negative controls affect private copies only; never regenerate reference evidence."""
from __future__ import annotations
import contextlib,io,shutil,subprocess,sys,tempfile,unittest,zipfile
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2];sys.path.insert(0,str(ROOT/'tools'))
import check_v2 as check
import v2_contract as contract
import package_v2

class Controls(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.context=contract.previous();cls.old,cls.lock=cls.context.__enter__();cls.functions=check.boundary_functions(cls.old)
    @classmethod
    def tearDownClass(cls):cls.context.__exit__(None,None,None)
    @contextlib.contextmanager
    def candidate(self):
        with tempfile.TemporaryDirectory(prefix='achbot-v2-control-') as tmp:
            d=Path(tmp)/'candidate';shutil.copytree(ROOT,d,ignore=shutil.ignore_patterns('.git','__pycache__'));yield d
    def change(self,d,file,old,new):
        p=d/file;s=p.read_bytes();self.assertIn(old,s);p.write_bytes(s.replace(old,new,1))
        with self.assertRaises(RuntimeError):contract.verify(d,self.old,self.lock)
    def test_exact_candidate(self):self.assertEqual(contract.verify(ROOT,self.old,self.lock)['extracted_telegram_functions'],16)
    def test_wrong_token_is_observable(self):
        with self.candidate() as d:
            self.change(d,'config.php',b'request($botToken, __DIR__, $method, $datas)',b"request('changed-token', __DIR__, $method, $datas)")
            case=('transport','bot:ok');self.assertNotEqual(check.boundary(d,check.boundary_functions(d),case),check.boundary(self.old,self.functions,case))
    def test_timeout_is_observable(self):
        with self.candidate() as d:
            self.change(d,'src/Telegram/Transport.php',b'CURLOPT_TIMEOUT, 10',b'CURLOPT_TIMEOUT, 11')
            case=('transport','bot:ok');self.assertNotEqual(check.boundary(d,check.boundary_functions(d),case),check.boundary(self.old,self.functions,case))
    def test_file_namespace_is_observable(self):
        with self.candidate() as d:
            self.change(d,'src/Telegram/Transport.php',b'use CURLFile;',b'// missing CURLFile import')
            case=('transport','bot:multipart');self.assertNotEqual(check.boundary(d,check.boundary_functions(d),case),check.boundary(self.old,self.functions,case))
    def test_input_callback_is_observable(self):
        with self.candidate() as d:
            self.change(d,'src/Telegram/UpdateBindings.php',b"$data = $update->callback_query->data;",b"$data = 'changed';")
            self.assertNotEqual(check.projection(d,True),check.projection(self.old,False))
    def test_missing_module(self):
        with self.candidate() as d:
            (d/'src/Telegram/Transport.php').unlink()
            with self.assertRaises(RuntimeError):contract.verify(d,self.old,self.lock)
            with self.assertRaises(RuntimeError):check.boundary(d,check.boundary_functions(d),('transport','bot:ok'))
    def test_schema_drift(self):
        with self.candidate() as d:self.change(d,'database/legacy-schema.sql',b'CREATE TABLE',b'CREATE TABLE IF NOT EXISTS')
    def test_frozen_golden(self):
        with self.candidate() as d:self.change(d,'tests/fixtures/golden.json',b'1800000000',b'1800000001')
    def test_unrelated_bootstrap(self):
        with self.candidate() as d:self.change(d,'config.php',b'$time = time();',b'$time = 1;')
    def test_wrong_log_location(self):
        with self.candidate() as d:
            self.change(d,'src/Telegram/Transport.php',b'$runtimeDir . "/error_log.txt"',b'__DIR__ . "/error_log.txt"')
            case=('transport','bot:failure');self.assertNotEqual(check.boundary(d,check.boundary_functions(d),case),check.boundary(self.old,self.functions,case))
    def test_extra_application_file(self):
        for name in ['extra.php','nested/baseInfo.php']:
            with self.subTest(path=name),self.candidate() as d:
                p=d/name;p.parent.mkdir(parents=True,exist_ok=True);p.write_text('<?php')
                with self.assertRaises(RuntimeError):contract.verify(d,self.old,self.lock)
    def test_extra_module_data(self):
        with self.candidate() as d:
            (d/'src/Telegram/extra.json').write_text('{}')
            with self.assertRaises(RuntimeError):contract.verify(d,self.old,self.lock)
    def test_symlink_module(self):
        with self.candidate() as d:
            p=d/'src/Telegram/Transport.php';p.unlink();p.symlink_to(ROOT/'src/Telegram/Transport.php')
            with self.assertRaises(RuntimeError):contract.verify(d,self.old,self.lock)
    def test_modules_are_passive(self):
        with self.candidate() as d:
            code=''.join("require '"+p+"';" for p in contract.NEW_FILES)
            out=subprocess.run(['php','-n','-d','allow_url_fopen=0','-d','disable_functions='+check.run.DISABLED,'-r',code],cwd=d,capture_output=True,timeout=10)
            self.assertEqual((out.returncode,out.stdout,out.stderr),(0,b'',b''))
            self.assertFalse((d/'error_log.txt').exists());self.assertFalse((d/'baseInfo.php').exists())
    def test_package_members_and_repeatability(self):
        a,manifest=package_v2.build_bytes(ROOT,self.lock);b,_=package_v2.build_bytes(ROOT,self.lock);self.assertEqual(a,b)
        with zipfile.ZipFile(io.BytesIO(a)) as z:
            self.assertEqual(set(z.namelist()),set(contract.runtime_files(self.lock)));self.assertEqual(len(z.namelist()),26)
            self.assertNotIn('baseInfo.php',z.namelist());self.assertFalse(any(f.endswith('.sql') or f.startswith(('tests/','tools/')) for f in z.namelist()))

if __name__=='__main__':unittest.main(verbosity=2)
