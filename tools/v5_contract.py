#!/usr/bin/env python3
"""AChBot V5 pinned V4->V5 byte-level source and runtime contract."""
from __future__ import annotations

import contextlib
import hashlib
import io
import re
import tarfile
import tempfile
from pathlib import Path

import v1_contract as v1
import v4_contract as v4

ROOT = Path(__file__).resolve().parents[1]
BASE = 'ff81d1b8d8832ec17fea7b07dabebc37614d29f2'
BASE_TREE = '80a84c8dff6edd0754acd2d4f17d1211c2112267'
NEW_FILES = [
    'src/Broadcast/Routes.php',
    'src/Jobs/Checkpoint.php',
    'src/Jobs/MessageActions.php',
    'src/Jobs/BroadcastSend.php',
    'src/Notifications/Warnings.php',
]
CHANGED = ['bot.php', 'settings/messagewizwiz.php', 'settings/warnusers.php']


def replace_region(source: bytes, start: bytes, end: bytes, replacement: bytes, *, include_end=False):
    first = [m.start() for m in re.finditer(re.escape(start), source)]
    last = [m.start() for m in re.finditer(re.escape(end), source)]
    if len(first) != 1 or len(last) != 1 or first[0] >= last[0]:
        raise RuntimeError(f'Ambiguous/out-of-order source boundary: {start[:45]!r}')
    left = first[0]
    right = last[0] + (len(end) if include_end else 0)
    return source[:left] + replacement + source[right:], source[left:right]


def module_header(name: str) -> bytes:
    return ("<?php\n/** AChBot V5: " + name +
            "; legacy caller scope and side effects are intentional. */\n").encode() + v4.DIRECT_GUARD


def expected_runtime(reference: Path):
    outputs = {}
    route = (reference/'bot.php').read_bytes()
    replacement = (b"if ((require __DIR__ . '/src/Broadcast/Routes.php') === null) return;\n")
    # Original bare top-level return in these routes exits the webhook. A bare
    # return inside require yields null; normal require completion yields 1.
    route, original_routes = replace_region(
        route, b"// BEGIN send to all\nif($data == 'message2All'",
        b'// END pin Manage\n', replacement, include_end=True)
    if b'__DIR__' in original_routes or b'__FILE__' in original_routes or re.search(rb'\bgoto\b', original_routes):
        raise RuntimeError('Unsafe moved broadcast router source')
    if b'return;' not in original_routes:
        raise RuntimeError('Broadcast route early-return sentinel was not exercised')
    outputs['bot.php'] = route
    outputs['src/Broadcast/Routes.php'] = module_header('broadcast administration and job lifecycle') + original_routes

    cron = (reference/'settings/messagewizwiz.php').read_bytes()
    cron, helpers = replace_region(cron, b'/**\n * Helpers (local)\n */\n',
        b'$inlineStartKey = json_encode([',
        b"require __DIR__ . '/../src/Jobs/Checkpoint.php';\n\n")
    if re.search(rb'__DIR__|__FILE__|\bgoto\b|\breturn;', helpers):
        raise RuntimeError('Unsafe checkpoint relocation')
    outputs['src/Jobs/Checkpoint.php'] = module_header('shared worker checkpoints') + helpers
    cron, jobs = replace_region(cron,
        b'// BEGIN Processor: bc_jobs (pin / unpin / delete / edit)\n',
        b'// END Processor: bc_jobs\n',
        b"require __DIR__ . '/../src/Jobs/MessageActions.php';\n\n", include_end=True)
    if jobs.count(b'goto CRON_BROADCAST_PART;') != 3 or jobs.count(b'CRON_BROADCAST_PART:\n') != 1:
        raise RuntimeError('Job processor goto/label contract drift')
    if b'__DIR__' in jobs or b'__FILE__' in jobs:
        raise RuntimeError('Job processor file-path contract drift')
    jobs = jobs.replace(b'goto CRON_BROADCAST_PART;', b'return;').replace(b'CRON_BROADCAST_PART:\n', b'')
    outputs['src/Jobs/MessageActions.php'] = module_header('message action worker') + jobs
    cron, broadcast = replace_region(cron, b'// BEGIN send to all\n$staleMinutes',
        b'// END send to all\n',
        b"require __DIR__ . '/../src/Jobs/BroadcastSend.php';\n", include_end=True)
    original_log = b'__DIR__ . "/error_log.txt"'
    if broadcast.count(original_log) != 1 or broadcast.count(b'__DIR__') != 1:
        raise RuntimeError('Broadcast error_log location contract drift')
    broadcast = broadcast.replace(original_log, b'dirname(__DIR__, 2) . "/settings/error_log.txt"')
    if b'__FILE__' in broadcast or b'goto ' in broadcast:
        raise RuntimeError('Unsafe relocated broadcast worker')
    outputs['src/Jobs/BroadcastSend.php'] = module_header('broadcast recipient worker') + broadcast
    outputs['settings/messagewizwiz.php'] = cron

    warn = (reference/'settings/warnusers.php').read_bytes()
    warn, notification = replace_region(warn, b'$time = time(); \n',
        b'global $connection, $botState;\n',
        b"require __DIR__ . '/../src/Notifications/Warnings.php';\n\n")
    if b'__DIR__' in notification or b'__FILE__' in notification or re.search(rb'\b(?:goto|return)\s*;', notification):
        raise RuntimeError('Unsafe notification extraction')
    if b'handleAutoRenewCallback' in notification:
        raise RuntimeError('Unexpected entitlement/financial code moved')
    outputs['src/Notifications/Warnings.php'] = module_header('expiry and status notifications') + notification
    outputs['settings/warnusers.php'] = warn
    return outputs, {'routes_sha256':hashlib.sha256(original_routes).hexdigest(),
                     'jobs_sha256':hashlib.sha256(jobs).hexdigest(),
                     'broadcast_sha256':hashlib.sha256(broadcast).hexdigest(),
                     'warnings_sha256':hashlib.sha256(notification).hexdigest(),
                     'route_lines':original_routes.count(b'\n'),
                     'job_lines':jobs.count(b'\n'),
                     'broadcast_lines':broadcast.count(b'\n'),
                     'warning_lines':notification.count(b'\n')}


@contextlib.contextmanager
def previous():
    if v1.git('rev-parse', BASE+'^{tree}').decode().strip() != BASE_TREE:
        raise RuntimeError('V4 integration base identity mismatch')
    with tempfile.TemporaryDirectory(prefix='achbot-v5-v4-reference-') as tmp:
        out = Path(tmp)
        archive = v1.git('archive','--format=tar',BASE)
        with tarfile.open(fileobj=io.BytesIO(archive)) as tf:
            members=tf.getmembers()
            if len(members)>1800 or sum(m.size for m in members)>75*1024*1024:
                raise RuntimeError('V4 reference archive limit')
            for m in members:
                rel=Path(m.name)
                if rel.is_absolute() or '..' in rel.parts or not (m.isfile() or m.isdir()):
                    raise RuntimeError('Unsafe V4 archive member')
            tf.extractall(out, filter='data')
        yield out


def runtime_files():
    with v4.previous() as (_, lock):
        return sorted(v4.runtime_files(lock) + NEW_FILES)


def verify(root:Path=ROOT):
    with previous() as prior, v4.previous() as (v3,lock):
        v4.verify(prior,v3,lock)
        expected,parts=expected_runtime(prior)
        before=v4.runtime_files(lock)
        for name in before+NEW_FILES+['database/legacy-schema.sql']:
            p=root/name
            if not p.is_file() or p.is_symlink() or any(q.is_symlink() for q in p.parents if q!=root and root in q.parents):
                raise RuntimeError('Unsafe/absent runtime file: '+name)
            correct=expected[name] if name in expected else (prior/name).read_bytes()
            if p.read_bytes()!=correct:
                raise RuntimeError('V5 source drift: '+name)
        found={p.relative_to(root).as_posix() for p in root.rglob('*.php')
               if p.relative_to(root).parts[0] not in ('tools','tests','.git') and p.name!='baseInfo.php'}
        allowed={p for p in before+NEW_FILES if p.endswith('.php')}
        if found!=allowed:
            raise RuntimeError('Unexpected runtime PHP member: '+str(sorted(found^allowed)))
        # Existing V4 tools/tests/docs are frozen by the V4 baseline archive.
        frozen=[p.relative_to(prior).as_posix() for d in ('tests','docs/v0','docs/v1','docs/v2','docs/v3','docs/v4')
                for p in (prior/d).rglob('*') if p.is_file()]
        frozen += [p.relative_to(prior).as_posix() for p in (prior/'tools').rglob('*') if p.is_file()]
        for name in frozen:
            if (root/name).read_bytes() != (prior/name).read_bytes():
                raise RuntimeError('Frozen historical evidence changed: '+name)
        return {'base':BASE,'base_tree':BASE_TREE,**parts,'runtime_members':len(before)+len(NEW_FILES),
                'unchanged_previous_runtime':len(before)-len(CHANGED),
                'frozen_evidence_files':len(frozen), 'schema_unchanged':True}
