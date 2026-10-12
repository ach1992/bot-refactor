#!/usr/bin/env python3
"""Positive and deliberate negative controls; mutate only owned temporary copies."""
from __future__ import annotations
import contextlib,io,json,shutil,sys,tempfile,unittest,zipfile
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'tools'));sys.path.insert(0,str(ROOT/'tests/v3'))
import v3_contract as c
import check_v3 as check
import package_v3
from cases import build_cases

class Controls(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.ref=c.previous();cls.old,cls.lock=cls.ref.__enter__();cls.cases=build_cases()
    @classmethod
    def tearDownClass(cls):cls.ref.__exit__(None,None,None)
    @contextlib.contextmanager
    def copy(self):
        with tempfile.TemporaryDirectory(prefix='achbot-support-control-') as tmp:
            root=Path(tmp)
            files=set(c.runtime_files(self.lock)+['database/legacy-schema.sql']+c.frozen_files(self.old))
            for file in files:
                p=root/file;p.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(ROOT/file,p)
            yield root
    def reject(self,root):
        with self.assertRaises(RuntimeError):c.verify(root,self.old,self.lock)
    def behavioral_mutation(self,file,old,new,case):
        raw=(ROOT/file).read_bytes();self.assertIn(old,raw);mutated=raw.replace(old,new,1)
        with self.copy() as root:
            (root/file).write_bytes(mutated);self.reject(root)
        spec=self.cases[case]
        before=check.support(self.old,self.old,spec,False)
        after=check.support(ROOT,self.old,spec,True,override={file:mutated})
        self.assertNotEqual(before,after,'Behavioral mutation escaped comparison')
    def test_exact_candidate(self):
        self.assertEqual(c.verify(ROOT,self.old,self.lock)['support_modules'],6)
    def test_route_mismatch_detected(self):
        self.behavioral_mutation('src/Support/Entry.php',b'$data=="supportSection"',b'$data=="wrong-route"','entry.menu')
    def test_wizard_state_mutation_detected(self):
        self.behavioral_mutation('src/Support/CreateTicket.php',b'"sendTicket_"',b'"wrongStep_"','create.title')
    def test_actor_scope_mutation_detected(self):
        self.behavioral_mutation('src/Support/CustomerConversation.php',b"$from_id = $ticketInfo['user_id'];",b"$ticketOwner = $ticketInfo['user_id'];",'close.2002')
    def test_sql_state_mutation_detected(self):
        self.behavioral_mutation('src/Support/CustomerConversation.php',b'SET `state` = 2',b'SET `state` = 1','close.1001')
    def test_require_once_changes_reentry(self):
        spec=self.cases['entry.reenter'];expected=check.support(self.old,self.old,spec,False)
        altered=check.support_sequence(self.old,True).replace(b'require ',b'require_once ')
        actual=check.support(ROOT,self.old,spec,True,override={'support-sequence.php':altered})
        self.assertNotEqual(expected,actual)
        with self.copy() as root:
            p=root/'bot.php';p.write_bytes(p.read_bytes().replace(b"require __DIR__ . '/src/Support/",b"require_once __DIR__ . '/src/Support/",1));self.reject(root)
    def test_root_order_mutation(self):
        with self.copy() as root:
            p=root/'bot.php';s=p.read_bytes();a=b"require __DIR__ . '/src/Support/CreateTicket.php';";b=b"require __DIR__ . '/src/Support/CustomerConversation.php';"
            s=s.replace(a,b'__FIXTURE_SWAP__',1).replace(b,a,1).replace(b'__FIXTURE_SWAP__',b,1);p.write_bytes(s);self.reject(root)
    def test_missing_module(self):
        with self.copy() as root:(root/c.NEW_FILES[0]).unlink();self.reject(root)
    def test_direct_guard_removal(self):
        with self.copy() as root:
            p=root/c.NEW_FILES[0];p.write_bytes(p.read_bytes().replace(c.DIRECT_GUARD,b'',1));self.reject(root)
    def test_schema_change(self):
        with self.copy() as root:
            p=root/'database/legacy-schema.sql';p.write_bytes(p.read_bytes()+b'\n-- invalid fixture drift\n');self.reject(root)
    def test_frozen_golden_change(self):
        with self.copy() as root:(root/'tests/fixtures/golden.json').write_text('{}');self.reject(root)
    def test_extra_php(self):
        with self.copy() as root:(root/'unexpected.php').write_text('<?php');self.reject(root)
    def test_extra_module_data(self):
        with self.copy() as root:(root/'src/Support/extra.txt').write_text('fixture');self.reject(root)
    def test_symlink_module(self):
        with self.copy() as root:
            p=root/c.NEW_FILES[0];p.unlink();p.symlink_to(ROOT/c.NEW_FILES[0]);self.reject(root)
    def test_package_complete_reproducible(self):
        a,ma=package_v3.build_bytes(ROOT,self.lock);b,mb=package_v3.build_bytes(ROOT,self.lock)
        self.assertEqual(a,b);self.assertEqual(ma,mb)
        with zipfile.ZipFile(io.BytesIO(a)) as z:
            self.assertEqual(set(z.namelist()),set(c.runtime_files(self.lock)));self.assertEqual(len(z.namelist()),32)
            self.assertNotIn('baseInfo.php',z.namelist());self.assertFalse(any(p.endswith('.sql') or p.startswith(('tools/','tests/')) for p in z.namelist()))

if __name__=='__main__':unittest.main(verbosity=2)
