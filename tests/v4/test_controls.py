#!/usr/bin/env python3
"""V4 exact-source, behavior-sensitivity, package and isolation controls."""
from __future__ import annotations
import contextlib
import io
import shutil
import sys
import tempfile
import unittest
import zipfile
from pathlib import Path

ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'tools'))
import v4_contract as c
import check_v4 as check
import package_v4

class Controls(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.reference=c.previous()
        cls.old,cls.lock=cls.reference.__enter__()
        cls.cases=check.build_cases()
    @classmethod
    def tearDownClass(cls):
        cls.reference.__exit__(None,None,None)
    @contextlib.contextmanager
    def copy(self):
        with tempfile.TemporaryDirectory(prefix='achbot-access-control-') as tmp:
            d=Path(tmp)
            files=set(c.runtime_files(self.lock)+['database/legacy-schema.sql']+c.frozen_files(self.old))
            for rel in files:
                dst=d/rel;dst.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(ROOT/rel,dst)
            yield d
    def reject(self,d):
        with self.assertRaises(RuntimeError):c.verify(d,self.old,self.lock)
    def behavioral(self,file,old,new,case):
        original=(ROOT/file).read_bytes()
        self.assertIn(old,original)
        altered=original.replace(old,new,1)
        with self.copy() as d:
            (d/file).write_bytes(altered)
            self.reject(d)
        reference=check.access(self.old,self.old,self.cases[case],False)
        changed=check.access(ROOT,self.old,self.cases[case],True,override={file:altered})
        self.assertTrue(reference!=changed,'Comparator insensitive to important mutation')
    def test_exact_candidate(self):
        self.assertEqual(c.verify(ROOT,self.old,self.lock)['regions'],6)
    def test_route_mutation(self):
        self.behavioral('src/Admin/Roles.php',b'$data=="adminsList"',b'$data=="wrongRoleList"','roles.list.9001-0')
    def test_strict_owner_authorization(self):
        self.behavioral('src/Admin/Roles.php',b'if($data=="addNewAdmin" && $from_id === $admin)',b'if($data=="addNewAdmin" && $from_id == $admin)',"roles.add.'9001'-0")
    def test_application_state_mutation(self):
        self.behavioral('src/Agent/Applications.php',b"setUser(2, 'is_agent')",b"setUser(1, 'is_agent')",'agent.request.0')
    def test_ban_sql_mutation(self):
        self.behavioral('src/Admin/Bans.php',b"SET \x60step\x60 = 'banned'",b"SET \x60step\x60 = 'none'",'ban.complete.9001-0-none')
    def test_discount_sql_mutation(self):
        self.behavioral('src/Agent/Discounts.php',b"UPDATE \x60users\x60 SET \x60discount_percent\x60 = ?",b"UPDATE \x60users\x60 SET \x60is_agent\x60 = ?",'discount.remove.Plan.9001')
    def test_require_once_must_fail_reentry(self):
        case=self.cases['repeat.adminsList']
        reference=check.access(self.old,self.old,case,False)
        altered=check.sequence(self.old,True).replace(b'require ',b'require_once ')
        changed=check.access(ROOT,self.old,case,True,override={'access-sequence.php':altered})
        self.assertNotEqual(reference,changed)
        with self.copy() as d:
            p=d/'bot.php';p.write_bytes(p.read_bytes().replace(b"require __DIR__ . '/src/Admin/Roles.php';",b"require_once __DIR__ . '/src/Admin/Roles.php';"))
            self.reject(d)
    def test_root_order_change(self):
        with self.copy() as d:
            p=d/'bot.php';raw=p.read_bytes()
            a=b"require __DIR__ . '/src/Admin/Roles.php';"
            b=b"require __DIR__ . '/src/Agent/Discounts.php';"
            # Even a swapped include on a distant route is an unauthorized change.
            self.assertIn(a,raw);self.assertIn(b,raw)
            p.write_bytes(raw.replace(a,b,1))
            self.reject(d)
    def test_missing_module(self):
        with self.copy() as d:
            (d/c.NEW_FILES[0]).unlink()
            self.reject(d)
    def test_direct_guard_removed(self):
        with self.copy() as d:
            p=d/c.NEW_FILES[0]
            p.write_bytes(p.read_bytes().replace(c.DIRECT_GUARD,b'',1))
            self.reject(d)
    def test_schema_mutation(self):
        with self.copy() as d:
            p=d/'database/legacy-schema.sql'
            p.write_bytes(p.read_bytes()+b'\n-- unacceptable DDL change\n')
            self.reject(d)
    def test_prior_golden_mutation(self):
        with self.copy() as d:
            (d/'tests/fixtures/golden.json').write_text('{}')
            self.reject(d)
    def test_unrelated_root_edit(self):
        with self.copy() as d:
            p=d/'bot.php';p.write_bytes(p.read_bytes()+b'\n// unexpected root change\n')
            self.reject(d)
    def test_extra_php(self):
        with self.copy() as d:
            (d/'unreviewed.php').write_bytes(b'<?php')
            self.reject(d)
    def test_extra_src(self):
        with self.copy() as d:
            (d/'src/Admin/extra.txt').write_text('unreviewed')
            self.reject(d)
    def test_module_symlink(self):
        with self.copy() as d:
            p=d/c.NEW_FILES[0];p.unlink();p.symlink_to(ROOT/c.NEW_FILES[0])
            self.reject(d)
    def test_package_reproducible_and_exclusive(self):
        a,ha=package_v4.build_bytes(ROOT,self.lock)
        b,hb=package_v4.build_bytes(ROOT,self.lock)
        self.assertEqual(a,b);self.assertEqual(ha,hb)
        with zipfile.ZipFile(io.BytesIO(a)) as z:
            members=z.namelist()
            self.assertEqual(set(members),set(c.runtime_files(self.lock)))
            self.assertEqual(len(members),38)
            self.assertNotIn('baseInfo.php',members)
            self.assertFalse(any(x.endswith('.sql') or x.startswith(('tests/','tools/')) for x in members))

if __name__=='__main__':unittest.main(verbosity=2)
