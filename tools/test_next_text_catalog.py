#!/usr/bin/env python3
"""Current-runtime copy audit and native equivalence. Frozen V0–V9 untouched."""
from __future__ import annotations
import hashlib, json, pathlib, re, socket, subprocess, sys, time, urllib.error, urllib.request
ROOT=pathlib.Path(__file__).resolve().parents[1]
def command(*args,**kw):return subprocess.check_output(args,cwd=ROOT,**kw)
def check(ok,message):
    if not ok:raise AssertionError(message)
def runtime_paths():
    paths=command('git','ls-files','*.php').decode().splitlines()
    return [p for p in paths if not p.startswith(('tools/','tests/','phpqrcode/','messages/')) and p!='baseInfo.example.php']
def main():
    inventory=json.loads((ROOT/'tools/text_catalog_inventory.json').read_text())
    reverse=0
    for path,record in inventory['files'].items():
        current=(ROOT/path).read_bytes()
        marker=('\n'+record['loader']).encode()
        check(current.count(marker)==1,'loader marker drift: '+path)
        current=current.replace(marker,b'',1)
        offsets=[];delta=0
        for edit in sorted(record['edits'],key=lambda e:e['start']):
            start=edit['start']+delta;replacement=edit['replacement'].encode()
            check(current[start:start+len(replacement)]==replacement,'catalog expression drift: '+path+':'+str(edit['line']))
            offsets.append((start,start+len(replacement),edit['original'].encode()))
            delta+=len(replacement)-len(edit['original'].encode())
        for start,end,original in reversed(offsets):current=current[:start]+original+current[end:]
        check(hashlib.sha256(current).hexdigest()==record['source_sha256'],'non-copy source changed: '+path)
        original=command('git','show',record['source_commit']+':'+path)
        check(current==original,'baseline source identity mismatch: '+path)
        reverse+=len(record['edits'])
    tokens=json.loads(command('php','tools/catalog_tokens.php',input=json.dumps(runtime_paths()).encode()))
    exclusions={(e['path'],e['literal']) for e in inventory['excluded_tokens']}
    violations=[]
    for token in tokens:
        path=token['path'];literal=token['raw']
        if path in inventory['excluded_files'] or (path,literal) in exclusions:continue
        if any(ord(c)>127 for c in literal):
            violations.append([path,token['line'],literal[:100]])
        # ASCII literal copy directly inside explicit Telegram button/caption sinks.
        source=(ROOT/path).read_bytes()[:token['start']].decode()
        if re.search(r"['\"](?:text|caption)['\"]\s*=>\s*$",source[-100:]) and re.search('[A-Za-z]',literal):
            violations.append([path,token['line'],literal[:100]])
    check(not violations,'UI literals outside catalog: '+json.dumps(violations,ensure_ascii=False))
    refs=set()
    for path in runtime_paths():
        refs.update(re.findall(r"\\?achbotMessage(?:Html)?\('([^']+)'",(ROOT/path).read_text()))
    catalog=json.loads(command('php','-r',
        'require "messages/loader.php";$r=[];foreach(glob("messages/fa/*.php") as $p){$t=basename($p,".php");foreach(achbotMessages($t) as $k=>$v)$r[]=$t.".".$k;}echo json_encode($r);'))
    entries=set(catalog)
    check(len(entries)==len(catalog),'duplicate catalog keys')
    check(refs<=entries,'unknown catalog references: '+str(refs-entries))
    unused={k for k in entries-refs if not k.startswith(('legacy_messages.','legacy_buttons.'))}
    check(not unused,'orphan copy entries: '+str(unused))
    native=json.loads(command('php','tools/test_text_catalog.php'))
    check(native['status']=='PASS','native byte equivalence')
    # Direct requests must not expose any internal catalog/module source or text.
    with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
    process=subprocess.Popen(['php','-S','127.0.0.1:'+str(port),'-t',str(ROOT)],cwd=ROOT,
                             stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    try:
        deadline=time.monotonic()+5
        while True:
            try:
                with socket.create_connection(('127.0.0.1',port),timeout=.2):break
            except OSError:
                check(time.monotonic()<deadline,'isolated PHP listener unavailable');time.sleep(.05)
        guarded=['messages/loader.php','src/Finance/ReceiptHelpers.php']+[
            p.relative_to(ROOT).as_posix() for p in sorted((ROOT/'messages/fa').glob('*.php'))]
        for path in guarded:
            try:response=urllib.request.urlopen('http://127.0.0.1:'+str(port)+'/'+path);status=response.status;body=response.read()
            except urllib.error.HTTPError as error:status=error.code;body=error.read()
            check(status==404 and not body,'direct guard failed: '+path)
    finally:
        process.terminate();process.wait(timeout=5)
    print(json.dumps({'status':'PASS','source_equivalent_occurrences':reverse,'source_files':len(inventory['files']),
      'catalog_keys':len(entries),'runtime_references':len(refs),'static_ui_violations':len(violations),
      'direct_http_guards':len(guarded),'native':native},ensure_ascii=False))
if __name__=='__main__':main()
