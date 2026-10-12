#!/usr/bin/env python3
"""V4 exact source contract over the approved, integrated AchBot V3."""
from __future__ import annotations
import contextlib
import hashlib
import io
import json
import re
import subprocess
import tarfile
import tempfile
from pathlib import Path

import v1_contract as v1
import v3_contract as v3

ROOT = Path(__file__).resolve().parents[1]
V3 = '283165f19777ad6ab62cfcb03de329c5bb0f8b74'
V3_TREE = 'b172f48120a6febf3ee654de2b50acb45ace6a1c'

# Ordered, disjoint, anchored source boundaries. Everything between these
# boundaries is preserved BYTE FOR BYTE and runs in the original caller scope.
REGIONS = [
    ('src/Admin/Roles.php', b'if($data=="adminsList"', b'if(($data=="botSettings"',
     'Administrator listing, grant and revoke.'),
    ('src/Agent/Discounts.php', b'if(($data == "agentsList"', b"if(preg_match('/^edit(RewaredTime|cartToCartAutoAcceptTime)/', $data,",
     'Agent listing, details, plan/server/default discount management.'),
    ('src/Agent/Applications.php', b'if($data=="agencySettings"', b"if (\n    preg_match('/accept(.*)/'",
     'Agent request, acceptance and rejection wizard.'),
    ('src/Admin/Bans.php', b'if($data == "banUser"', b'if($data=="mainMenuButtons"',
     'User ban/unban request and ban authorization.'),
    ('src/Admin/Unban.php', b'if($userInfo[\'step\'] == "unbanUser"',
     b"require __DIR__ . '/src/Support/AdminReply.php';",
     'User unban completion, notifications and admin menu.'),
    ('src/Agent/Rejected.php', b'if($data=="rejectedAgentList"', b'// BEGIN Edit by ACh',
     'Rejected agent listing and release.'),
]
NEW_FILES = [name for name, _, _, _ in REGIONS]
DIRECT_GUARD = (
    b"if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {\n"
    b"    http_response_code(404);\n"
    b"    exit;\n"
    b"}\n\n"
)

@contextlib.contextmanager
def previous():
    if v1.git('rev-parse', V3+'^{tree}').decode().strip() != V3_TREE:
        raise RuntimeError('V3 source tree identity mismatch')
    with tempfile.TemporaryDirectory(prefix='achbot-v3-ref-') as tmp:
        root = Path(tmp)
        with tarfile.open(fileobj=io.BytesIO(v1.git('archive', '--format=tar', V3))) as ar:
            members = ar.getmembers()
            if len(members)>1500 or sum(m.size for m in members)>70*1024*1024:
                raise RuntimeError('Reference archive size bound exceeded')
            for m in members:
                p=Path(m.name)
                if p.is_absolute() or '..' in p.parts or not (m.isfile() or m.isdir()):
                    raise RuntimeError('Unsafe V3 reference member')
                dst=root/p
                if m.isdir():
                    dst.mkdir(parents=True,exist_ok=True)
                else:
                    dst.parent.mkdir(parents=True,exist_ok=True)
                    with ar.extractfile(m) as fi, open(dst,'wb') as out:
                        out.write(fi.read())
        yield root,json.loads((root/'tests/fixtures/baseline-lock.json').read_text())

def region_blocks(old:Path):
    source=(old/'bot.php').read_bytes()
    previous_end=0
    blocks=[]
    for filename,start,end,purpose in REGIONS:
        a=[m.start() for m in re.finditer(rb'(?m)^'+re.escape(start),source)]
        b=[m.start() for m in re.finditer(rb'(?m)^'+re.escape(end),source)]
        if len(a)!=1 or len(b)!=1:
            raise RuntimeError('Ambiguous V4 boundary: '+filename+f' ({len(a)}/{len(b)})')
        lo,hi=a[0],b[0]
        if lo<previous_end or hi<=lo:
            raise RuntimeError('Overlapping/out-of-order V4 region: '+filename)
        body=source[lo:hi]
        previous_end=hi
        blocks.append({'file':filename,'offset':lo,'length':len(body),
                       'line':source[:lo].count(b'\n')+1,
                       'end_line':source[:hi].count(b'\n'),
                       'body':body,'purpose':purpose,
                       'sha256':hashlib.sha256(body).hexdigest()})
    return blocks

def expected_runtime(old:Path):
    src=(old/'bot.php').read_bytes()
    outputs={}
    blocks=region_blocks(old)
    for block in reversed(blocks):
        header=(
            '<?php\n/**\n * AchBot Access: '+block['purpose']+
            '\n * Scoped, re-entrant, byte-compatible route at original bot.php position.'
            '\n * Internal include only. Do not execute via HTTP directly.'
            '\n */\n'
        ).encode()
        outputs[block['file']]=header+DIRECT_GUARD+block['body']
        include=("require __DIR__ . '/"+block['file']+"';\n").encode()
        src=src[:block['offset']]+include+src[block['offset']+block['length']:]
    outputs['bot.php']=src
    return outputs,blocks

def runtime_files(lock:dict):
    return sorted(v3.runtime_files(lock)+NEW_FILES)

def frozen_files(old:Path):
    return sorted(p.relative_to(old).as_posix()
                  for directory in ('tools','tests','docs/v0','docs/v1','docs/v2','docs/v3')
                  for p in (old/directory).rglob('*') if p.is_file())

def identity():
    head=v1.git('rev-parse','HEAD').decode().strip()
    tree=v1.git('rev-parse','HEAD^{tree}').decode().strip()
    dirty=bool(v1.git('status','--porcelain').strip())
    return {'candidate_head':head,'candidate_tree':tree,'worktree_dirty':dirty}

def verify(root:Path,old:Path,lock:dict):
    outputs, blocks=expected_runtime(old)
    before_files=v3.runtime_files(lock)
    for rel in before_files+NEW_FILES+['database/legacy-schema.sql']:
        path=root/rel
        if path.is_symlink() or any(p.is_symlink() for p in path.parents if p!=root and root in p.parents):
            raise RuntimeError('Symlink in runtime: '+rel)
        expected=outputs[rel] if rel in outputs else (old/rel).read_bytes()
        if not path.is_file() or path.read_bytes()!=expected:
            raise RuntimeError('V4 runtime/source envelope mismatch: '+rel)
    actual={p.relative_to(root).as_posix() for p in root.rglob('*.php')
            if p.relative_to(root).parts[0] not in ('tests','tools','.git')
            and p.relative_to(root).as_posix()!='baseInfo.php'}
    if actual!={p for p in runtime_files(lock) if p.endswith('.php')}:
        raise RuntimeError('Unexpected V4 application PHP inventory')
    src={p.relative_to(root).as_posix() for p in (root/'src').rglob('*')
         if p.is_file() or p.is_symlink()}
    if src!={p for p in runtime_files(lock) if p.startswith('src/')}:
        raise RuntimeError('Unexpected src contents')
    evidence=frozen_files(old)
    for rel in evidence:
        p=root/rel
        if not p.is_file() or p.is_symlink() or p.read_bytes()!=(old/rel).read_bytes():
            raise RuntimeError('Frozen V3/V2/V1/V0 evidence mutated: '+rel)
    # Do not move location-sensitive control flow across a require.
    for b in blocks:
        raw=b['body']
        if re.search(rb'\b(?:goto|function|require_once|include_once)\b|__DIR__|__FILE__',raw):
            raise RuntimeError('Potentially unsafe cross-file construct: '+b['file'])
    return {
        'previous_commit':V3,'previous_tree':V3_TREE,
        'regions':len(blocks),
        'moved_lines':sum(b['body'].count(b'\n') for b in blocks),
        'moved_branches':sum(len(re.findall(rb'(?m)^if\s*\(',b['body'])) for b in blocks),
        'previous_runtime_files_unchanged':len(before_files)-1,
        'runtime_files':len(runtime_files(lock)),
        'frozen_evidence_files':len(evidence),
        'schema_unchanged':True,
        'route_positions_preserved':True
    }
