#!/usr/bin/env python3
"""Retained relocation proof plus current two-file copy/audience acceptance."""
from __future__ import annotations
import hashlib, json, pathlib, re, socket, subprocess, sys, tempfile, time, urllib.error, urllib.request
import catalog_ui
ROOT=pathlib.Path(__file__).resolve().parents[1]
def command(*args,**kw):return subprocess.check_output(args,cwd=ROOT,**kw)
def check(ok,message):
    if not ok:raise AssertionError(message)
def runtime_paths():
    paths=command('git','ls-files','*.php').decode().splitlines()
    return [p for p in paths if not p.startswith(('tools/','tests/','phpqrcode/','messages/')) and p!='baseInfo.example.php']
STYLE_BASELINE='7faac9b067f8a171e5e26d6aea5aaf9f4088a3e7'
def style_source_reconstruction():
    source=json.loads((ROOT/'tools/text_catalog_style_source.json').read_text())
    style=json.loads((ROOT/'tools/text_catalog_style_amendments.json').read_text())
    check(source.get('version')==1 and source.get('baseline_commit')==STYLE_BASELINE,
          'style source baseline drift')
    check(style.get('version')==1 and style.get('baseline_commit')==STYLE_BASELINE,
          'style definition baseline drift')
    edits=source['edits']
    allowed={'config.php','src/Purchase/OrderWrites.php','src/Finance/GatewayReturn.php'}
    check(len(edits)==26 and {e['path'] for e in edits}==allowed and
          set(source['source_sha256'])==allowed,'style source scope drift')
    check(len(source['retired_keys'])==23 and set(source['retired_keys'])==set(style['retired_keys']),
          'retired implementation token/source coverage drift')
    restored={}
    for path in allowed:
        current=(ROOT/path).read_bytes()
        hashes=source['source_sha256'][path]
        baseline=command('git','show',STYLE_BASELINE+':'+path)
        check(hashlib.sha256(current).hexdigest()==hashes['after'] and
              hashlib.sha256(baseline).hexdigest()==hashes['before'],
              'style source identity mismatch: '+path)
        for edit in reversed([e for e in edits if e['path']==path]):
            before=edit['original'].encode();after=edit['replacement'].encode()
            check(before!=after and current.count(after)==1 and bool(edit['reason']),
                  'style source expression drift: '+path)
            current=current.replace(after,before,1)
        check(current==baseline,'style source reversal differs from immutable source: '+path)
        restored[path]=current
    return restored,source,style
def main():
    inventory=json.loads((ROOT/'tools/text_catalog_inventory.json').read_text())
    legacy=command('git','show',inventory['baseline']+':settings/values.php')
    check(hashlib.sha256(legacy).hexdigest()==inventory['values_sha256'],'legacy default source identity mismatch')
    amendments=json.loads((ROOT/'tools/text_catalog_amendments.json').read_text())
    # Preserve the original per-file raw baselines. First reverse only the two
    # explicit followup edits, then prove the three authorized auth dependencies.
    check(set(amendments['source_dependencies'])=={
        'src/Subscription/AccountDetails.php','src/Subscription/CustomerRoutes.php',
        'src/Subscription/OrderManagement.php'},'authorized source scope drift')
    followups=amendments['source_edits']
    check(len(followups)==2 and {e['kind'] for e in followups}=={
        'byte_preserved_presentation','authorized_bootstrap_privacy'},'followup scope drift')
    restored,style_source,style=style_source_reconstruction()
    reverse=0
    for path,record in inventory['files'].items():
        current=restored.get(path,(ROOT/path).read_bytes())
        relevant=[e for e in followups if e['path']==path]
        for edit in reversed(relevant):
            before=edit['original'].encode();after=edit['replacement'].encode()
            check(current.count(after)==1,'followup expression drift: '+path)
            current=current.replace(after,before,1)
        if path in amendments['source_dependencies']:
            dependency=amendments['source_dependencies'][path]
            accepted=command('git','show',dependency['commit']+':'+path)
            check(current==accepted,'authorized source dependency drift: '+path)
            diff=command('git','diff',amendments['catalog_commit'],dependency['commit'],'--',path)
            check(hashlib.sha256(diff).hexdigest()==dependency['diff_sha256'],'authorized diff identity drift: '+path)
            current=command('git','show',amendments['catalog_commit']+':'+path)
        elif relevant:
            check(current==command('git','show',amendments['assignment_base']+':'+path),
                  'non-copy followup source drift: '+path)
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
        for edit in record['edits']:
            if edit.get('formatting'):continue
            before=current[:edit['start']].decode()
            check(not re.search(r"['\"](?:callback_data|step|provider|state)['\"]\s*=>\s*$",before[-100:]),'Machine field moved into catalog: '+path)
            check(not re.search(r'->(?:prepare|query)\(\s*$',before[-100:]),'SQL argument moved into catalog: '+path)
        reverse+=len(record['edits'])
    reverse+=sum(e['kind']=='byte_preserved_presentation' for e in followups)
    tokens=json.loads(command('php','tools/catalog_tokens.php',input=json.dumps(runtime_paths()).encode()))
    exclusions={(e['path'],e['literal']) for e in inventory['excluded_tokens']}
    violations=[]
    for token in tokens:
        path=token['path'];literal=token['raw']
        if path in inventory['excluded_files'] or (path,literal) in exclusions:continue
        if any(ord(c)>127 for c in literal):
            violations.append([path,token['line'],literal[:100]])
    check(not violations,'UI literals outside catalog: '+json.dumps(violations,ensure_ascii=False))
    ascii_ui=catalog_ui.ui_candidates(ROOT,runtime_paths())
    check(not ascii_ui,'ASCII UI copy outside catalog: '+json.dumps(ascii_ui,ensure_ascii=False))
    refs=set()
    for path in runtime_paths():
        refs.update(re.findall(r"\\?achbotMessage(?:Html)?\('([^']+)'",(ROOT/path).read_text()))
    catalog=json.loads(command('php','-r',
        'require "messages/loader.php";$r=[];foreach(achbotMessageTopics() as $t){foreach(achbotMessages($t) as $k=>$v)$r[]=$t.".".$k;}echo json_encode($r);'))
    entries=set(catalog)
    check(len(entries)==len(catalog),'duplicate catalog keys')
    check(refs<=entries,'unknown catalog references: '+str(refs-entries))
    unused={k for k in entries-refs if not k.startswith(('legacy_messages.','legacy_buttons.'))}
    check(not unused,'orphan copy entries: '+str(unused))
    with tempfile.TemporaryDirectory(prefix='achbot-catalog-audit-') as scratch:
        fixture=pathlib.Path(scratch)/'fixture.php'
        fixture.write_text("<?php $message='Receipt accepted'; $message.=' Reference: '.$id; sendMessage($message); $keys=[['text'=>'Continue','callback_data'=>'keep_protocol']]; sendPhoto('file.png','Visible caption'); ?><p>Visible page title</p>")
        found={t['raw'] for t in catalog_ui.ui_candidates(ROOT,[str(fixture)])}
        check(found=={"'Receipt accepted'","' Reference: '","'Continue'","'Visible caption'",'Visible page title'},'ASCII audit negative control failed: '+str(found))
        fixture.write_text("<?php function record(){ $result=['protocol'=>'kept_machine','leftgb'=>round(1.234,2).' GB']; return $result; } function label(){return 'Returned label';} $traffic=record();$leftgb=$traffic['leftgb'];$keys=[['text'=>$leftgb,'callback_data'=>$traffic['protocol']]];sendMessage(label());")
        found={t['raw'] for t in catalog_ui.ui_candidates(ROOT,[str(fixture)])}
        check(found=={"' GB'","'Returned label'"},'array-field/return audit negative control failed: '+str(found))
        # Real helper/consumer flow must be detected when this one relocation
        # is reverted. The immutable source is copied only into a temporary file.
        fixture.write_bytes(command('git','show',amendments['assignment_base']+':src/Subscription/AccountDetails.php'))
        found={t['raw'] for t in catalog_ui.ui_candidates(ROOT,[str(fixture)])}
        check(found=={"' GB'"},'actual remaining-traffic audit negative control failed: '+str(found))
    import test_post_v9_customer_errors as privacy
    import catalog_source
    copy=catalog_source.templates(ROOT)
    privacy.verify(ROOT,copy)
    surface=(ROOT/privacy.CUSTOMER_SURFACES[0]).read_text()
    key=re.search(r"(?:sendMessage|alert)\(\s*\\achbotMessage\('([^']+)'",surface)[1]
    poisoned=dict(copy);poisoned[key]+=' SQLSTATE'
    try:privacy.verify(ROOT,poisoned)
    except RuntimeError as error:check('exposes infrastructure' in str(error),'catalog privacy negative control failed for wrong reason')
    else:raise AssertionError('catalog privacy negative control missed infrastructure copy')
    native=json.loads(command('php','tools/test_text_catalog.php'))
    check(native['status']=='PASS','pre-style byte and current exact-presentation acceptance')
    presentation=json.loads(command('python3','tools/test_text_catalog_style.py'))
    check(presentation.get('status')=='PASS' and presentation.get('editable_files')==2
          and presentation.get('current_keys')==2526 and presentation.get('retired_keys')==23
          and presentation.get('added_keys')==1 and presentation.get('logical_topics')==25
          and presentation.get('numeric_template_changes')==24
          and presentation.get('adjacent_emoji_violations')==0
          and presentation.get('parameter_exception_count')==3,
          'two-file style, digit and audience acceptance incomplete')
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
    print(json.dumps({'status':'PASS','pre_style_source_equivalent_occurrences':reverse,'source_files':len(inventory['files']),
      'catalog_keys':len(entries),'runtime_references':len(refs),'static_ui_violations':len(violations),'ascii_ui_violations':len(ascii_ui),'ascii_audit_negative_controls':8,'migrated_machine_fields_or_sql_arguments':0,
      'authorized_source_dependency_files':len(amendments['source_dependencies']),'authorized_bootstrap_flow_changes':1,'approved_public_copy_corrections':len(amendments['approved_copy']),
      'direct_http_guards':len(guarded),'editable_catalog_files':2,'logical_topics':25,
      'current_authorized_source_edits':len(style_source['edits']),'retired_parser_tokens':len(style_source['retired_keys']),'presentation':presentation,'native':native},ensure_ascii=False))
if __name__=='__main__':main()
