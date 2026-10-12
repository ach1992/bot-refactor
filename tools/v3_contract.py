#!/usr/bin/env python3
"""V3 support lifecycle envelope over the immutable, approved V2 tree."""
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
import v2_contract as v2

ROOT = Path(__file__).resolve().parents[1]
V2 = '893032ef217fe89fa1d46b06a5c3368b8f4fb63e'
V2_TREE = '92a42beae3be056af0968bdfb3e0462b712fa5de'
# Boundaries follow the original route order, including unrelated routes between regions.
REGIONS = [
 ('Entry.php', b'if($data=="supportSection"){', b"if($data == 'dayPlanSettings'", 'Support menu and customer category selection.'),
 ('CreateTicket.php', b"if(preg_match('/^supportCat(.*)/'", b'if($data== "usersOpenTickets"', 'Persisted category/title/body wizard and text/photo ticket creation.'),
 ('CustomerConversation.php', b'if($data== "usersOpenTickets"', b'if($data=="ticketsList"', 'Customer lists, closure, reply wizard, message persistence and rating.'),
 ('Categories.php', b'if($data=="ticketsList"', b'if(($data=="openTickets"', 'Administrator ticket menu and category create/list/delete operations.'),
 ('QueueAndHistory.php', b'if(($data=="openTickets"', b'if($data == "banUser"', 'Administrator queues, pagination, closure, history and attachment retrieval.'),
 ('AdminReply.php', b'if(preg_match("/^reply_(.*)/"', b"if (preg_match('/freeTrial(", 'Administrator reply wizard, text/photo notifications and ticket state update.'),
]
NEW_FILES = ['src/Support/'+r[0] for r in REGIONS]
DIRECT_GUARD = b"if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {\n    http_response_code(404);\n    exit;\n}\n\n"

@contextlib.contextmanager
def previous():
    if v1.git('rev-parse', V2+'^{tree}').decode().strip() != V2_TREE:
        raise RuntimeError('Approved V2 tree identity mismatch')
    with tempfile.TemporaryDirectory(prefix='achbot-v2-reference-') as tmp:
        root=Path(tmp)
        with tarfile.open(fileobj=io.BytesIO(v1.git('archive','--format=tar',V2))) as archive:
            members=archive.getmembers()
            if len(members)>1024 or sum(m.size for m in members)>60*1024*1024:
                raise RuntimeError('Unexpected V2 reference size')
            for m in members:
                p=Path(m.name)
                if p.is_absolute() or '..' in p.parts or '.git' in p.parts:
                    raise RuntimeError('Unsafe reference member')
                dest=root/p
                if m.isdir(): dest.mkdir(parents=True,exist_ok=True)
                elif m.isfile():
                    dest.parent.mkdir(parents=True,exist_ok=True)
                    dest.write_bytes(archive.extractfile(m).read())
                else: raise RuntimeError('Non-regular reference member')
        yield root,json.loads((root/'tests/fixtures/baseline-lock.json').read_text())

def region_blocks(old:Path):
    source=(old/'bot.php').read_bytes(); blocks=[]; prior_end=0
    for filename,start,end,purpose in REGIONS:
        starts=list(re.finditer(rb'(?m)^'+re.escape(start),source))
        ends=list(re.finditer(rb'(?m)^'+re.escape(end),source))
        if len(starts)!=1 or len(ends)!=1:
            raise RuntimeError('Ambiguous source boundary: '+filename)
        a=starts[0].start(); b=ends[0].start()
        if a<prior_end or b<=a: raise RuntimeError('Invalid support boundary order')
        raw=source[a:b]; prior_end=b
        blocks.append({'file':'src/Support/'+filename,'offset':a,'length':b-a,
                       'line':source[:a].count(b'\n')+1,'end_line':source[:b].count(b'\n'),
                       'body':raw,'purpose':purpose,'sha256':hashlib.sha256(raw).hexdigest()})
    return blocks

def expected_runtime(old:Path):
    source=(old/'bot.php').read_bytes(); outputs={}
    blocks=region_blocks(old)
    for r in reversed(blocks):
        header=('<?php\n/**\n * AchBot Support: '+r['purpose']+'\n * Scoped compatibility handler, included at its original bot.php route position.\n * Keep caller variables, fall-through, exit behavior and SQL/message ordering.\n * No direct HTTP/CLI entrypoint. See docs/v3/README.md for the scope contract.\n */\n').encode()
        outputs[r['file']]=header+DIRECT_GUARD+r['body']
        include=("require __DIR__ . '/"+r['file']+"';\n").encode()
        source=source[:r['offset']]+include+source[r['offset']+r['length']:]
    outputs['bot.php']=source
    return outputs,blocks

def runtime_files(lock:dict):
    return sorted(v2.runtime_files(lock)+NEW_FILES)

def frozen_files(old:Path):
    return sorted(p.relative_to(old).as_posix() for folder in ['tools','tests','docs/v0','docs/v1','docs/v2']
                  for p in (old/folder).rglob('*') if p.is_file())

def verify(root:Path,old:Path,lock:dict):
    expected,blocks=expected_runtime(old)
    refs=v2.runtime_files(lock)+['database/legacy-schema.sql']
    for file in refs+NEW_FILES:
        path=root/file
        if any(p.is_symlink() for p in [path,*path.parents] if p!=root and root in p.parents):
            raise RuntimeError('Symlink in runtime path: '+file)
        content=expected[file] if file in expected else (old/file).read_bytes()
        if not path.is_file() or path.read_bytes()!=content:
            raise RuntimeError('V3 support contract mismatch: '+file)
    actual={p.relative_to(root).as_posix() for p in root.rglob('*.php')
            if p.relative_to(root).parts[0] not in {'tests','tools','.git'} and p.relative_to(root).as_posix()!='baseInfo.php'}
    if actual!={p for p in runtime_files(lock) if p.endswith('.php')}:
        raise RuntimeError('Unexpected application PHP inventory')
    src={p.relative_to(root).as_posix() for p in (root/'src').rglob('*') if p.is_file() or p.is_symlink()}
    if src!=set(v2.ALL_MODULES+NEW_FILES): raise RuntimeError('Unexpected src inventory')
    evidence=frozen_files(old)
    for file in evidence:
        p=root/file
        if not p.is_file() or p.is_symlink() or p.read_bytes()!=(old/file).read_bytes():
            raise RuntimeError('Frozen historical evidence changed: '+file)
    # Refuse moving cross-file control flow or location-dependent code. This scan is lexical.
    with tempfile.TemporaryDirectory(prefix='achbot-support-token-') as tmp:
        f=Path(tmp)/'regions.php'; f.write_bytes(b'<?php\n'+b'\n'.join(r['body'] for r in blocks))
        code="foreach(token_get_all(file_get_contents($argv[1]),TOKEN_PARSE) as $t) if(is_array($t)&&in_array($t[0],[T_GOTO,T_RETURN,T_FUNCTION,T_DIR,T_FILE,T_NAMESPACE],true)) {fwrite(STDERR,token_name($t[0]));exit(1);}"
        subprocess.run(['php','-n','-d','extension=tokenizer','-r',code,str(f)],check=True,capture_output=True,timeout=15)
    return {'previous_commit':V2,'previous_tree':V2_TREE,'support_modules':len(NEW_FILES),
            'moved_source_lines':sum(r['body'].count(b'\n') for r in blocks),
            'runtime_files':len(runtime_files(lock)),'unchanged_runtime_reference_files':len(refs)-1,
            'frozen_evidence_files':len(evidence),'schema_unchanged':True,'scoped_route_order_preserved':True}

def identity():
    return {'candidate_head':v1.git('rev-parse','HEAD').decode().strip(),
            'candidate_tree':v1.git('rev-parse','HEAD^{tree}').decode().strip(),
            'worktree_dirty':bool(v1.git('status','--porcelain'))}

if __name__=='__main__':
    with previous() as (old,lock): print(json.dumps(verify(ROOT,old,lock),sort_keys=True))
