#!/usr/bin/env python3
"""V2 acceptance: immutable V1/V0 evidence plus candidate transport/input equivalence."""
from __future__ import annotations
import argparse, base64, json, os, re, shutil, subprocess, tempfile
from pathlib import Path
import check_v1
import v1_contract as v1
from v2_contract import ROOT, MODULES, ALL_MODULES, NAMES, previous, verify, input_block, runtime_files, candidate_identity
run=check_v1.run
TRANSPORT_VARIANTS=['ok','429','429-default','failure','500','199','400','empty','invalid','null','false','zero','array','unicode','multipart','nested-file','scalar','defaults','named','large-log','log-boundary']
MESSAGE_VARIANTS={
 'sendMessage':['defaults','explicit','named','null-parse','parse-error','api-false','failure'],
 'sendPhoto':['defaults','explicit','null-parse','parse-error','api-false','failure'],
 'sendDocument':['defaults','explicit','missing','api-false','failure'],
 'answerCallbackQuery':['defaults','explicit'], 'editKeys':['defaults','explicit'], 'editText':['defaults','explicit'],
 'delMessage':['defaults','explicit'], 'sendAction':['defaults','explicit'], 'forwardmessage':['defaults'],
 'pinOne':['defaults'], 'unpinOne':['defaults','unpin-fallback','api-false','failure'], 'deleteOne':['defaults'],
 'getFileUrl':['defaults','file-path-missing','api-false','failure'], 'alert':['defaults','explicit','named'],
}
CASES=[('transport',n+':'+v) for n in ['bot','sendFileToTelegram'] for v in TRANSPORT_VARIANTS]
CASES += [('message',n+':'+v) for n,vs in MESSAGE_VARIANTS.items() for v in vs]

def boundary_functions(root:Path) -> bytes:
    source=(root/'config.php').read_bytes();scan=v1.declarations(root)['config.php'];fragments=[]
    for name in sorted(NAMES|{'tgRetryAfter','normalizeReplyMarkup','apiOk'}):
        matches=[f for f in scan if f['name']==name]
        if len(matches)!=1:raise RuntimeError('Boundary function missing: '+name)
        f=matches[0];fragments.append(source[f['offset']:f['offset']+f['length']])
    return b'<?php\n'+b'\n\n'.join(fragments)+b'\n'

def sandbox(root:Path,d:Path):
    shutil.copytree(root/'src',d/'src');(d/'tests/support').mkdir(parents=True)
    shutil.copyfile(root/'tests/support/doubles.php',d/'tests/support/doubles.php')

def php(cmd:list[str],d:Path):
    env={'PATH':os.environ.get('PATH','/usr/bin:/bin'),'LANG':'C.UTF-8','ACHBOT_PRIVATE_TEST':'1'}
    args=['php','-n','-d','allow_url_fopen=0','-d','allow_url_include=0','-d','open_basedir='+str(d),
          '-d','disable_functions='+run.DISABLED,'-d','memory_limit=128M','-d','display_errors=0','-d','log_errors=0',*cmd]
    p=subprocess.run(args,cwd=d,env=env,capture_output=True,timeout=15,preexec_fn=run.enforce_limits)
    if p.returncode or p.stdout or p.stderr:
        raise RuntimeError('Isolated PHP failed: '+str(p.returncode)+' '+p.stdout.decode(errors='replace')[-1000:]+' '+p.stderr.decode(errors='replace')[-1000:])

def diagnostics(record:dict) -> dict:
    # Only physical locations/internal names in diagnostics; never output, values, URLs or logs.
    def norm(message:str):
        message=re.sub(r', called in .*? on line \d+','',message)
        for namespace,rows in MODULES.values():
            for old,new,_,_ in rows:message=message.replace(namespace+'\\'+new+'(',old+'(')
        return message
    if record.get('error'):
        e=record['error']
        if isinstance(e,dict):e['message']=norm(e['message'])
        else:e[-1]=norm(e[-1])
    for warning in record.get('warnings',[]):warning[-1]=norm(warning[-1])
    return record

def boundary(root:Path,functions:bytes,case:tuple[str,str],mode='legacy',strict=False) -> dict:
    with tempfile.TemporaryDirectory(prefix='achbot-v2-boundary-') as tmp:
        d=Path(tmp);sandbox(root,d);(d/'functions-under-test.php').write_bytes(functions)
        mapping={old:{'path':path,'function':ns+'\\'+new,'context':dict(extra)} for path,(ns,rows) in MODULES.items() for old,new,args,extra in rows}
        (d/'mapping.json').write_text(json.dumps(mapping));(d/'case.json').write_text(json.dumps({'family':case[0],'variant':case[1],'mode':mode}))
        script=(ROOT/'tests/v2/boundary.php').read_bytes()
        if strict:script=script.replace(b'<?php',b'<?php\ndeclare(strict_types=1);',1)
        (d/'boundary.php').write_bytes(script);php([str(d/'boundary.php')],d)
        return diagnostics(json.loads((d/'boundary-result.json').read_text().replace(tmp,'<SANDBOX>')))

def inputs() -> dict:
    import copy
    user={'id':1001,'first_name':'<Fixture & User>','last_name':'Last','username':'fixture_user'}
    message={'message_id':71,'chat':{'id':1001,'type':'private'},'from':user,'text':'/start'}
    callback={'id':'fixture-callback','from':user,'data':'wizwizch','message':dict(message,reply_markup={'inline_keyboard':[[{'text':'old','callback_data':'wizwizch'}]]})}
    cases={'empty':'','invalid':'{bad','null':'null','false':'false','zero':'0','array':'[]','object':'{}',
      'unrelated':json.dumps({'inline_query':{'id':'fixture'},'botToken':'must-not-project','connection':'must-not-project'}),
      'message':json.dumps({'message':message}),'callback':json.dumps({'callback_query':callback}),
      'both':json.dumps({'message':dict(message,caption='keep-caption'),'callback_query':callback})}
    for value in [None,False,0,'',[],{},'fixture']:
        for target in ['message','callback_query']:cases[target+'-shape:'+repr(value)]=json.dumps({target:value})
    for field in ['from','chat','message_id','text','caption','reply_to_message']:
        for value in [None,{},[],False,0,'fixture']:
            m=copy.deepcopy(message);m[field]=value;cases['message-field:'+field+':'+repr(value)]=json.dumps({'message':m})
        m=copy.deepcopy(message);m.pop(field,None);cases['message-absent:'+field]=json.dumps({'message':m})
    for field in ['username','first_name','last_name','id']:
        for value in [None,'<fixture>',0,False,[],{}]:
            for target in ['message','callback_query']:
                obj=copy.deepcopy(message if target=='message' else callback);obj['from'][field]=value
                cases[target+'-user:'+field+':'+repr(value)]=json.dumps({target:obj})
    for data in ['mainMenu','wizwizch','old_callback_1','',None,123,'\u0000','\u0633\u0644\u0627\u0645']:
        c=copy.deepcopy(callback);c['data']=data;cases['callback-data:'+repr(data)]=json.dumps({'callback_query':c})
    for value in [None,{},[],{'inline_keyboard':[]},{'inline_keyboard':[[{'callback_data':'wizwizch'}]]}]:
        c=copy.deepcopy(callback);c['message']['reply_markup']=value;cases['callback-markup:'+repr(value)]=json.dumps({'callback_query':c})
    for field in ['message','from','id','data']:
        c=copy.deepcopy(callback);c.pop(field,None);cases['callback-absent:'+field]=json.dumps({'callback_query':c})
    for reply in [{'text':'reply','forward_sender_name':'sender','forward_from':{'id':2002}}, {'text':'reply'},{}]:
        m=copy.deepcopy(message);m['reply_to_message']=reply;cases['reply:'+repr(reply)]=json.dumps({'message':m})
    return cases

def projection(root:Path,is_candidate:bool) -> dict:
    with tempfile.TemporaryDirectory(prefix='achbot-v2-input-') as tmp:
        d=Path(tmp);(d/'inputs.json').write_text(json.dumps(inputs(),ensure_ascii=True))
        if is_candidate:
            shutil.copyfile(root/'src/Telegram/UpdateBindings.php',d/'UpdateBindings.php')
            body=b"require __DIR__.'/UpdateBindings.php';"
        else:body=input_block((root/'config.php').read_bytes())
        code=(b'<?php\nfunction projectUnderTest($update, &$partial) {\n'
              b"$from_id=7; $alias=&$from_id; $caption='prior-caption'; $data='prior-callback'; $untouched='persistent-value';\ntry {\n"+
              body+b"\n} finally {$snapshot=get_defined_vars(); unset($snapshot['update'],$snapshot['partial']); $partial=$snapshot;}\nreturn $partial;\n}\n")
        (d/'projection-under-test.php').write_bytes(code);shutil.copyfile(ROOT/'tests/v2/input.php',d/'input.php');php([str(d/'input.php')],d)
        records=json.loads((d/'input-result.json').read_text())
        for value in records.values():diagnostics(value)
        return records

def current_legacy(root:Path,old:Path,lock:dict):
    functions,p=check_v1.extract(root);old_functions,q=check_v1.extract(old)
    p={(f['file'],f['function']):f['sha256'] for f in p};q={(f['file'],f['function']):f['sha256'] for f in q}
    if set(p)!=set(q):raise RuntimeError('Existing extraction inventory drift')
    if any(p[k]!=v and k[1] not in NAMES for k,v in q.items()):raise RuntimeError('Unrelated extracted declaration changed')
    expected=json.loads((old/'tests/fixtures/golden.json').read_text())['cases']
    if set(expected)!=set(run.CASES):raise RuntimeError('Existing scenario list changed')
    run.ROOT=root
    for case in run.CASES:
        if run.run_case(case,functions,list(lock['files'])+ALL_MODULES)!=expected[case]:raise RuntimeError('V2 legacy trace mismatch: '+case)
    for strict in [False,True]:
        check_v1.assert_equal(check_v1.edges(old,old_functions,strict=strict),check_v1.edges(root,functions,strict=strict),'V1 edge regression')
    print('PASS current legacy scenarios:',len(expected),flush=True)

def main():
    ap=argparse.ArgumentParser(description=__doc__);ap.add_argument('--focused',action='store_true',help='Authoring only; not full acceptance');ap.add_argument('--report',type=Path);args=ap.parse_args()
    with previous() as (old,lock):
        summary=verify(ROOT,old,lock)
        if not args.focused:
            env=dict(os.environ);env['GIT_DIR']=subprocess.check_output(['git','rev-parse','--absolute-git-dir'],cwd=ROOT,text=True).strip();env['GIT_WORK_TREE']=str(old)
            subprocess.run(['python3',str(old/'tools/check_v1.py')],cwd=old,env=env,check=True,timeout=240)
        current_legacy(ROOT,old,lock);oldf=boundary_functions(old);newf=boundary_functions(ROOT)
        for strict in [False,True]:
            for case in CASES:
                expected=boundary(old,oldf,case,strict=strict)
                for mode in ['legacy','internal']:
                    got=boundary(ROOT,newf,case,mode,strict)
                    if got!=expected:raise RuntimeError('Boundary mismatch '+str((case,mode,strict))+' '+json.dumps({'expected':expected,'actual':got},ensure_ascii=True)[:5000])
            print('PASS transport/message cases:',len(CASES),'strict:',strict,flush=True)
        expected=projection(old,False);got=projection(ROOT,True);check_v1.assert_equal(expected,got,'Input scope bindings')
        if any(k in got['unrelated']['fields'] for k in ['botToken','connection']):raise RuntimeError('Untrusted input key escaped fixed bindings')
        if got['both']['overlay']['caption']!='keep-caption':raise RuntimeError('Message-only state lost during callback overlay')
        malformed=got['message-user:first_name:[]']
        if malformed['error'][0]!='TypeError' or malformed['partial_scope']['from_id']!=1001 or malformed['partial_scope']['alias']!=1001:
            raise RuntimeError('Partial assignments/caller aliases changed on malformed input')
        print('PASS input binding cases:',len(got),flush=True)
        files=sorted(set(ROOT/f for f in runtime_files(lock) if f.endswith('.php'))|set((ROOT/'tests').rglob('*.php'))|set((ROOT/'tools').rglob('*.php')))
        for p in files:subprocess.run(['php','-n','-d','disable_functions='+run.DISABLED,'-l',str(p)],check=True,capture_output=True,timeout=10)
        for p in [*(ROOT/'tools').glob('*.py'),*(ROOT/'tests/v2').glob('*.py')]:compile(p.read_text(),str(p),'exec')
        patterns=[r'\b[0-9]{7,11}:[A-Za-z0-9_-]{30,50}\b',r'-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----',r'gh[pousr]_[A-Za-z0-9_]{18,}']
        for p in [*files,*(ROOT/'tools').glob('*.py'),*(ROOT/'docs/v2').glob('*.md')]:
            if any(re.search(pattern,p.read_text()) for pattern in patterns):raise RuntimeError('Potential secret in '+str(p.relative_to(ROOT)))
        subprocess.run(['python3',str(ROOT/'tests/v2/test_controls.py')],cwd=ROOT,check=True,timeout=180)
        summary.update(candidate_identity())
        summary.update({'v2_control_tests':15,'v2_negative_controls':12,'frozen_v1_full_gate':not args.focused,'candidate_legacy_cases':len(run.CASES),'v1_helper_vectors_per_typing_mode':300,
          'boundary_cases_per_typing_mode':len(CASES),'boundary_comparison_pairs':len(CASES)*4,'input_cases':len(got),'typing_modes':2,'php_files_linted':len(files),
          'native_external_io':False,'operator_manual_acceptance':False,'independent_review':False,'production_acceptance':False})
    if args.report:args.report.write_text(json.dumps(summary,indent=2)+'\n')
    print(json.dumps(summary,sort_keys=True));return 0
if __name__=='__main__':raise SystemExit(main())
