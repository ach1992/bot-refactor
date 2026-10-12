#!/usr/bin/env python3
"""Bounded V2 source envelope over the immutable, reviewed V1 Git tree."""
from __future__ import annotations
import contextlib
import hashlib
import io
import json
import re
import tarfile
import tempfile
from pathlib import Path
import v1_contract as v1

ROOT = Path(__file__).resolve().parents[1]
V1 = 'a7ac4a86a84ccf781ef44130833dd76e923fdc47'
V1_TREE = '5adf954667c08fb11936f337c3010a5bcc697c0d'
# Legacy name, internal name, original argument names, explicit dependencies/context.
TRANSPORT = [
 ('bot','request',['method','datas'], [('botToken','$botToken'),('runtimeDir','__DIR__')]),
 ('sendFileToTelegram','sendFile',['method','datas'], [('botToken','$botToken'),('runtimeDir','__DIR__')]),
]
MESSAGES = [
 ('sendMessage','sendMessage',['txt','key','parse','ci','msg'], [('request',"'bot'"),('from_id','$from_id')]),
 ('sendDocument','sendDocument',['filePath','caption','ci'], [('fileRequest',"'sendFileToTelegram'"),('from_id','$from_id')]),
 ('answerCallbackQuery','answerCallbackQuery',['callbackId','text','show_alert'], [('readUrl',"'file_get_contents'"),('botUrl',"$GLOBALS['botUrl']")]),
 ('editKeys','editKeys',['keys','msgId','ci'], [('request',"'bot'"),('from_id','$from_id'),('message_id','$message_id')]),
 ('editText','editText',['msgId','txt','key','parse','ci'], [('request',"'bot'"),('from_id','$from_id')]),
 ('delMessage','deleteMessage',['msg','chat_id'], [('request',"'bot'"),('from_id','$from_id'),('message_id','$message_id')]),
 ('sendAction','sendAction',['action','ci'], [('request',"'bot'"),('from_id','$from_id')]),
 ('forwardmessage','forwardMessage',['tochatId','fromchatId','message_id'], [('request',"'bot'")]),
 ('pinOne','pinOne',['chatId','messageId'], [('request',"'bot'")]),
 ('unpinOne','unpinOne',['chatId','messageId'], [('request',"'bot'")]),
 ('deleteOne','deleteOne',['chatId','messageId'], [('request',"'bot'")]),
 ('sendPhoto','sendPhoto',['photo','caption','keyboard','parse','ci','replyTo'], [('request',"'bot'"),('from_id','$from_id')]),
 ('getFileUrl','getFileUrl',['fileid'], [('request',"'bot'"),('botToken','$botToken')]),
 ('alert','alert',['txt','type','callid'], [('request',"'bot'"),('callbackId','$callbackId')]),
]
MODULES = {
 'src/Telegram/Transport.php': ('AchBot\\Telegram\\Transport',TRANSPORT),
 'src/Telegram/Messages.php': ('AchBot\\Telegram\\Messages',MESSAGES),
}
NEW_FILES = [*MODULES,'src/Telegram/UpdateBindings.php']
ALL_MODULES = [*v1.MODULES,*NEW_FILES]
NAMES = {r[0] for _, rows in MODULES.values() for r in rows}
INPUT_START = b'if(isset($update->message)){'
INPUT_END = b'if($from_id < 0) exit();'
CALLABLES = {'request','fileRequest','readUrl'}

@contextlib.contextmanager
def previous():
    """No fetch/hooks/reset; allow only regular members of the pinned V1 archive."""
    if v1.git('rev-parse',V1+'^{tree}').decode().strip()!=V1_TREE:
        raise RuntimeError('Reviewed V1 tree identity mismatch')
    with tempfile.TemporaryDirectory(prefix='achbot-v1-reference-') as tmp:
        root=Path(tmp)
        with tarfile.open(fileobj=io.BytesIO(v1.git('archive','--format=tar',V1))) as tar:
            entries=tar.getmembers()
            if len(entries)>1024 or sum(e.size for e in entries)>60*1024*1024:
                raise RuntimeError('Unexpected V1 archive size')
            for e in entries:
                p=Path(e.name)
                if p.is_absolute() or '..' in p.parts or '.git' in p.parts:
                    raise RuntimeError('Unsafe V1 archive path')
                dest=root/p
                if e.isdir(): dest.mkdir(parents=True,exist_ok=True)
                elif e.isfile():
                    dest.parent.mkdir(parents=True,exist_ok=True)
                    dest.write_bytes(tar.extractfile(e).read())
                else: raise RuntimeError('Non-regular V1 archive entry')
        yield root,json.loads((root/'tests/fixtures/baseline-lock.json').read_text())

def input_block(source:bytes) -> bytes:
    start=source.index(INPUT_START,source.index(b'$update = json_decode(file_get_contents("php://input"));'))
    end=source.index(INPUT_END,start)
    return source[start:end]

def expected_runtime(old:Path) -> dict[str,bytes]:
    """Reconstruct allowed edits; differential tests separately challenge equivalence."""
    source=(old/'config.php').read_bytes()
    funcs=v1.declarations(old)['config.php']; edits=[]; outputs={}
    for path,(namespace,rows) in MODULES.items():
        body=('<?php\n/**\n * AchBot Telegram boundary. Dependencies and request context are explicit.\n * Preserve legacy payloads, transport policies, return values and diagnostics.\n */\nnamespace '+namespace+';\n\nuse CURLFile;\n\nrequire_once __DIR__ . \'/Response.php\';\n').encode()
        if path.endswith('Messages.php'):
            body+=b"require_once __DIR__ . '/Markup.php';\n"
        body+=b'\n'
        for name,new,args,extra in rows:
            matches=[f for f in funcs if f['name']==name]
            if len(matches)!=1: raise RuntimeError('Missing/ambiguous boundary: '+name)
            f=matches[0]; raw=source[f['offset']:f['offset']+f['length']]
            signature=raw[:raw.index(b'{')]
            globals_=re.findall(rb'^    global ([^;]+);\n',raw,re.M)
            if len(globals_)>1: raise RuntimeError('Unexpected global declarations')
            internal=re.sub(rb'^    global [^;]+;\n',b'',raw,flags=re.M)
            leading=', '.join(('callable ' if key in CALLABLES else '')+'$'+key for key,_ in extra)+', '
            internal=internal.replace(('function '+name+'(').encode(),('function '+new+'('+leading).encode(),1)
            if name in {'bot','sendFileToTelegram'}:
                internal=internal.replace(b'__DIR__',b'$runtimeDir')
                internal=internal.replace(b'tgRetryAfter($decoded)',b'\\AchBot\\Telegram\\Response\\retryAfter($decoded)')
            else:
                internal=re.sub(rb'\bbot\(',b'$request(',internal)
                internal=internal.replace(b'sendFileToTelegram(',b'$fileRequest(')
                internal=internal.replace(b'normalizeReplyMarkup(',b'\\AchBot\\Telegram\\Markup\\normalize(')
                internal=internal.replace(b'apiOk(',b'\\AchBot\\Telegram\\Response\\isSuccessful(')
                if name=='answerCallbackQuery':
                    internal=internal.replace(b"$GLOBALS['botUrl']",b'$botUrl').replace(b'file_get_contents(',b'$readUrl(')
            invocation=', '.join([value for _,value in extra]+['$'+a for a in args])
            wrapper=signature+b'{\n'
            if globals_: wrapper+=b'    global '+globals_[0]+b';\n'
            wrapper+=("    require_once __DIR__ . '/"+path+"';\n    return \\"+namespace+'\\'+new+'('+invocation+');\n}').encode()
            edits.append((f['offset'],f['length'],wrapper))
            body+=v1.format_internal(internal)+b'\n\n'
        outputs[path]=body.rstrip(b'\n')+b'\n'
    block=input_block(source)
    keys=sorted(set(re.findall(rb'\$(\w+)\s*=',block))-{b'update'})
    # These are source-defined names, NEVER arbitrary incoming object keys.
    if not keys or any(k in {b'GLOBALS',b'connection',b'botToken'} for k in keys):
        raise RuntimeError('Unexpected update projection key')
    # A scoped include is intentional: returning a complete map would defer assignments,
    # lose partially assigned variables on TypeError, and hide caller reference semantics.
    outputs['src/Telegram/UpdateBindings.php']=(
        b'<?php\n/** Legacy update bindings: execute in the including scope, in the original order. */\n'+block)
    offset=source.index(block,source.index(b'$update = json_decode'))
    # Keep the original read/decode/time statement and execute at the identical point in bootstrap.
    replacement=b"require __DIR__ . '/src/Telegram/UpdateBindings.php';\n"
    edits.append((offset,len(block),replacement))
    for offset,length,content in sorted(edits,reverse=True): source=source[:offset]+content+source[offset+length:]
    outputs['config.php']=source
    return outputs

def runtime_files(lock:dict) -> list[str]:
    return sorted([f for f in lock['files'] if not f.startswith('database/')]+ALL_MODULES)

def verify(root:Path,old:Path,lock:dict) -> dict:
    allowed=expected_runtime(old)
    references=list(lock['files'])+list(v1.MODULES)
    for file in references+NEW_FILES:
        p=root/file
        if any(q.is_symlink() for q in [p,*p.parents] if q!=root and root in q.parents):
            raise RuntimeError('Symlink in candidate runtime: '+file)
        expected=allowed.get(file,(old/file).read_bytes() if (old/file).is_file() else b'')
        if not p.is_file() or p.read_bytes()!=expected:
            raise RuntimeError('V2 runtime boundary mismatch: '+file)
    actual={p.relative_to(root).as_posix() for p in root.rglob('*.php')
            if p.relative_to(root).parts[0] not in {'tests','tools','.git'} and p.relative_to(root).as_posix()!='baseInfo.php'}
    if actual!={f for f in runtime_files(lock) if f.endswith('.php')}:
        raise RuntimeError('Unexpected PHP runtime inventory')
    if {p.relative_to(root).as_posix() for p in (root/'src').rglob('*') if p.is_file() or p.is_symlink()}!=set(ALL_MODULES):
        raise RuntimeError('Unexpected module inventory')
    frozen=[]
    for folder in ['tools','tests','docs/v0','docs/v1']:
        frozen.extend(p.relative_to(old).as_posix() for p in (old/folder).rglob('*') if p.is_file())
    for file in frozen:
        p=root/file
        if not p.is_file() or p.is_symlink() or p.read_bytes()!=(old/file).read_bytes():
            raise RuntimeError('Frozen V0/V1 evidence changed: '+file)
    return {'previous_commit':V1,'previous_tree':V1_TREE,'extracted_telegram_functions':len(NAMES),
            'new_modules':len(NEW_FILES),'runtime_files':len(runtime_files(lock)),
            'unchanged_runtime_reference_files':len(references)-1,'frozen_evidence_files':len(frozen),
            'schema_unchanged':True,'input_bindings_preserve_caller_scope':True}

def candidate_identity():
    """Dirty authoring work is explicitly distinguished from an exact committed candidate."""
    return {'candidate_head':v1.git('rev-parse','HEAD').decode().strip(),
            'candidate_tree':v1.git('rev-parse','HEAD^{tree}').decode().strip(),
            'worktree_dirty':bool(v1.git('status','--porcelain'))}

if __name__=='__main__':
    with previous() as (old,lock): print(json.dumps(verify(ROOT,old,lock),sort_keys=True))
