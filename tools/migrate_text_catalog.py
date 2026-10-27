#!/usr/bin/env python3
"""One-time reviewed token-range migration; NOT a blanket source replacement.

Run without --apply to inspect the exact proposed inventory. SQL, callbacks,
state/protocol literals, diagnostic logs, vendor QR source and frozen evidence
are not rewritten. Native PHP tokenization defines string boundaries.
"""
from __future__ import annotations
import collections, hashlib, json, pathlib, re, subprocess, sys

ROOT=pathlib.Path(__file__).resolve().parents[1]
BASE=sys.argv[sys.argv.index('--baseline')+1] if '--baseline' in sys.argv else '4b8bd77a1508ebcb59929ce2492824be9e1d1601'
def git(*args): return subprocess.check_output(['git','-C',str(ROOT),*args])
def quote(s): return "'"+s.replace('\\','\\\\').replace("'","\\'").replace("\r", "' . \"\\r\" . '").replace("\n", "' . \"\\n\" . '")+"'"
def decode(raw):
    return json.loads(subprocess.check_output(['php','-r',
        '$a=json_decode(stream_get_contents(STDIN),true);$r=[];foreach($a as $s)$r[]=eval("return ".$s.";");echo json_encode($r,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);'],
        input=json.dumps(raw,ensure_ascii=False).encode()))
def snake(s): return re.sub(r'(?<!^)(?=[A-Z])','_',s).lower()
def topic(path):
    if path=='bot.php': return 'bot'
    if path=='config.php': return 'admin_settings'
    if path=='buy_helpers.php': return 'subscription'
    if path.startswith('settings/jdf'): return 'date'
    if path.startswith('settings/'): return 'jobs'
    area=path.split('/')[1].lower()
    if area=='finance':
        name=pathlib.Path(path).stem
        if any(x in name for x in ['Wallet','AdminWallet']): return 'wallet'
        if any(x in name for x in ['Renew','Extension']): return 'renewal'
        if any(x in name for x in ['Manual','Receipt','Bank']): return 'manual_payment'
        if any(x in name for x in ['Recovery']): return 'payment_review'
        if any(x in name for x in ['GatewayRule','PaymentKey']): return 'payment_settings'
        if any(x in name for x in ['Gateway','PaymentPage']): return 'gateway'
        return 'payment'
    if area=='subscription': return 'subscription'
    if area=='panel': return 'provider'
    if area=='notifications': return 'notifications'
    if area=='presentation': return 'labels'
    return area

# Protected technical/protocol boundaries with explicit rationale.
EXCLUSIONS={
 'src/Telegram/Transport.php':'redacted transport diagnostics only; never UI copy',
}
EXACT_EXCLUSIONS={
 ('settings/jdf.php',"'٫'"):'numeric transliteration decimal separator/default parameter, not prose',
}
ASCII_EXTRA={
 'config.php':{'You do not have access','Unknown Plan','Path Custom','Error'},
 'src/Finance/PaymentLifecycle.php':{'NOWPayments'},
 'settings/subLink.php':{'Method not allowed','Wrong token','Legacy subscription link retired; use the current Marzban subscription URL.'},
}
ASCII_SELECTED=set()
def is_candidate(t,source):
    p=t['path'];r=t['raw'];before=source[:t['start']].decode()
    if p in EXCLUSIONS or (p,r) in EXACT_EXCLUSIONS: return False
    if t['kind']=='T_INLINE_HTML':return False
    if any(ord(c)>127 for c in r):return True
    if r[1:-1] in ASCII_EXTRA.get(p,set()):return True
    if (p,r) in ASCII_SELECTED:return True
    return False

def template(t):
    if t['kind']=='T_CONSTANT_ENCAPSED_STRING': return decode([t['raw']])[0],{}
    parts=t['parts'];pieces=[];exprs={};i=0
    chunks=[]
    while i<len(parts):
        kind,text=parts[i]
        if kind=='T_ENCAPSED_AND_WHITESPACE':
            chunks.append(text);pieces.append(('literal',len(chunks)-1));i+=1;continue
        if kind=='T_CURLY_OPEN':
            i+=1;expr=''
            while i<len(parts) and parts[i][0]!='}':
                expr+=parts[i][1];i+=1
            if i==len(parts):raise ValueError(t)
            i+=1
        elif kind=='T_VARIABLE':
            expr=text;i+=1
            if i<len(parts) and parts[i][0]=='[':
                expr+='[';i+=1
                while i<len(parts) and parts[i][0]!=']':
                    k,v=parts[i]
                    expr+=quote(v) if k=='T_STRING' else v;i+=1
                expr+=']';i+=1
        else: raise ValueError(('unexpected interpolation',t,parts[i]))
        name=re.sub('[^A-Za-z0-9_]+','_',expr.lstrip('$')).strip('_') or 'value'
        base=name;occurrence=2
        while name in exprs:
            name=base+'_'+str(occurrence);occurrence+=1
        exprs[name]=expr;pieces.append(('slot',name))
    literals=decode(['"'+c+'"' for c in chunks])
    return ''.join(literals[v] if k=='literal' else '{'+v+'}' for k,v in pieces),exprs

def key_context(t,source,value):
    before=source[:t['start']].decode();module=snake(pathlib.Path(t['path']).stem)
    functions=list(re.finditer(r'\bfunction\s+([A-Za-z0-9_]+)\s*\(',before))
    context=snake(functions[-1][1]).removeprefix('achbot_') if functions else ''
    # Legacy top-level routes: semantic callback/step identity is more useful than line numbers.
    if not functions or len(before)-functions[-1].end()>20000:
        matches=list(re.finditer(r"(?:\$data\s*={2,3}\s*['\"]([A-Za-z][A-Za-z0-9_]*)['\"]|\$userInfo\['step'\]\s*==\s*['\"]([A-Za-z][A-Za-z0-9_]*)['\"])",before))
        if matches:context=snake(matches[-1][1] or matches[-1][2])
    tail=before[-180:]
    if re.search(r"['\"]text['\"]\s*=>\s*$",tail):role='button'
    elif re.search(r'(?:alert|answerCallbackQuery)\([^;]*$',tail):role='alert'
    elif re.search(r'\b(?:sendMessage|editText|showForm)\([^;]*$',tail):role='message'
    elif re.search(r'\breturn\s*$',tail):role='label'
    else:
        var=re.search(r'\$([A-Za-z_][A-Za-z0-9_]*)\s*(?:\.?=)\s*$',tail)
        role=snake(var[1]) if var else 'text'
    # English diagnostic fields retain meaningful names in authorized admin notices.
    english=' '.join(re.findall('[A-Za-z][A-Za-z0-9_]+',re.sub('<[^>]*>','',value))[:5])
    if english and len(re.findall('[\u0600-\u06ff]',value))<3:role=snake(re.sub(r'[^a-zA-Z0-9]+','_',english)).strip('_')
    digest=hashlib.sha256(value.encode()).hexdigest()[:10]
    return '.'.join(x for x in [module,context,role] if x)+'.'+digest

def main():
    paths=[p for p in git('ls-files','*.php').decode().splitlines()
           if not p.startswith(('tools/','tests/','phpqrcode/','messages/')) and p!='baseInfo.example.php']
    tokens=json.loads(subprocess.check_output(['php',str(ROOT/'tools/catalog_tokens.php')],input=json.dumps(paths).encode(),cwd=ROOT))
    decode_raw=decode
    literals=[t['raw'] for t in tokens if t['kind']=='T_CONSTANT_ENCAPSED_STRING']
    literals += ['"'+text+'"' for t in tokens if t['kind']=='interpolated' for kind,text in t['parts'] if kind=='T_ENCAPSED_AND_WHITESPACE']
    literals=list(dict.fromkeys(literals))
    cached=dict(zip(literals,decode_raw(literals)))
    globals()['decode']=lambda raw:[cached[s] for s in raw]
    sources={p:(ROOT/p).read_bytes() for p in paths}
    import catalog_ui
    ASCII_SELECTED.update((t['path'],t['raw']) for t in catalog_ui.ui_candidates(ROOT,paths))
    ASCII_SELECTED.update({('src/Finance/Routes/ManualApproval.php',"' chat:'"),('src/Finance/Routes/ManualApproval.php',"'additional_targets:'")})
    if '--apply' in sys.argv:
        for p,raw in sources.items():
            if raw!=git('show',BASE+':'+p):raise RuntimeError('Migration requires exact unmodified baseline source: '+p)
    # Preserve previously assigned stable identities when upstream adds/moves copy.
    previous=json.loads(subprocess.check_output(['php','-r',
        'require "messages/loader.php";$r=[];foreach(glob("messages/fa/*.php") as $p){$t=basename($p,".php");if(str_starts_with($t,"legacy_"))continue;foreach(achbotMessages($t) as $k=>$v)$r[]=[$t,$k,$v];}echo json_encode($r,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);'],cwd=ROOT))
    stable={}
    for top,local,value in previous:
        text=value['text'] if isinstance(value,dict) else value
        params=tuple(value['parameters']) if isinstance(value,dict) else ()
        stable[(top,text,params)]=top+'.'+local
    catalog=collections.defaultdict(dict);dedup={};plan=collections.defaultdict(list)
    for t in tokens:
        p=t['path'];source=sources[p]
        if p=='settings/values.php':continue
        if not is_candidate(t,source):continue
        value,exprs=template(t);top=topic(p)
        ident=(top,value,tuple(exprs))
        if ident in dedup:key=dedup[ident]
        else:
            key=stable.get(ident,top+'.'+key_context(t,source,value));local=key.split('.',1)[1]
            catalog[top][local]={'text':value,'parameters':list(exprs)} if exprs else value
            dedup[ident]=key
        args=', ['+', '.join(quote(n)+' => '+expr for n,expr in exprs.items())+']' if exprs else ''
        replacement=r'\achbotMessage('+quote(key)+args+')'
        plan[p].append(dict(start=t['start'],end=t['end'],line=t['line'],original=t['raw'],replacement=replacement,key=key,expressions=exprs))
    # Actual browser payment markup: move text nodes, keep HTML/CSS exact.
    for t in tokens:
        if t['kind']!='T_INLINE_HTML' or t['path']!='src/Finance/PaymentPage.php':continue
        raw=t['raw'];prefix=0
        for m in re.finditer(r'(?<=>)([^<>]*[\u0600-\u06ff][^<>]*)(?=<|$)|^([^<>]*[\u0600-\u06ff][^<>]*)(?=<|$)',raw):
            value=m[0];top='gateway';local='payment_page.browser_text.'+hashlib.sha256(value.encode()).hexdigest()[:10]
            key=top+'.'+local;catalog[top][local]=value
            start=t['start']+len(raw[:m.start()].encode());end=t['start']+len(raw[:m.end()].encode())
            plan[t['path']].append(dict(start=start,end=end,line=t['line']+raw[:m.start()].count('\n'),original=value,replacement="<?= \\achbotMessage("+quote(key)+") ?>",key=key,expressions={},inline_html=True))
    # Remove inherited source-only whitespace on touched lines, outside strings.
    # Record every such edit so reverse-source verification still reconstructs exact bytes.
    for p,edits in plan.items():
        source=sources[p]
        protected=[(t['start'],t['end']) for t in tokens if t['path']==p]
        touched=set()
        for e in edits:
            start=source[:e['start']].count(b'\n')
            end=source[:e['end']].count(b'\n')
            touched.update(range(start,end+1))
        for match in re.finditer(rb'[ \t\r]+(?=\n)',source):
            if source[:match.start()].count(b'\n') not in touched:continue
            if any(a<=match.start()<b for a,b in protected):continue
            edits.append(dict(start=match.start(),end=match.end(),line=source[:match.start()].count(b'\n')+1,
                original=match[0].decode(),replacement='',formatting=True))
        for match in re.finditer(rb'^[ \t]*\t[ \t]*',source,re.M):
            if source[:match.start()].count(b'\n') not in touched:continue
            if any(a<=match.start()<b for a,b in protected):continue
            if b' ' not in match[0].split(b'\t')[0]:continue
            edits.append(dict(start=match.start(),end=match.end(),line=source[:match.start()].count(b'\n')+1,
                original=match[0].decode(),replacement=match[0].expandtabs(8).decode(),formatting=True))
    summary={t:len(v) for t,v in sorted(catalog.items())}
    print(json.dumps({'topics':summary,'occurrences':sum(map(len,plan.values())),'files':len(plan),'exclusions':EXCLUSIONS},indent=2))
    pathlib.Path('/tmp/achbot-catalog-plan.json').write_text(json.dumps(dict(plan),ensure_ascii=False,indent=2))
    if '--apply' not in sys.argv:return
    inventory={'baseline':BASE,'excluded_files':EXCLUSIONS,'excluded_tokens':[{'path':p,'literal':r,'reason':reason} for (p,r),reason in EXACT_EXCLUSIONS.items()],'files':{}}
    for p,edits in plan.items():
        original=sources[p];updated=original
        for e in sorted(edits,key=lambda e:e['start'],reverse=True):
            assert original[e['start']:e['end']].decode()==e['original'],e
            updated=updated[:e['start']]+e['replacement'].encode()+updated[e['end']:]
        text=updated.decode();depth=len(pathlib.Path(p).parts)-1
        rel=('../'*depth)+'messages/loader.php'
        loader="require_once __DIR__ . '/"+rel+"'; // ACHBOT_TEXT_CATALOG\n"
        # Namespaces must precede imports; direct guards stay before loader.
        namespace=re.search(r'^namespace\s+[^;]+;',text,re.M)
        if namespace:
            pos=namespace.end();text=text[:pos]+'\n'+loader+text[pos:]
        else:
            guard=re.search(r'if\s*\(\s*realpath\([\s\S]{0,250}?===\s*__FILE__\s*\)\s*\{[\s\S]{0,80}?\bexit\s*;?\s*\}',text)
            if guard:pos=guard.end();text=text[:pos]+'\n'+loader+text[pos:]
            else:pos=text.index('<?php')+5;text=text[:pos]+'\n'+loader+text[pos:]
        (ROOT/p).write_bytes(text.encode())
        inventory['files'][p]={'source_sha256':hashlib.sha256(original).hexdigest(),'source_commit':BASE,'loader':loader,'edits':edits}
    header="<?php\n/** AChBot Persian copy; keys are stable module/context/purpose identities. */\nif (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {http_response_code(404);exit;}\nreturn [\n"
    for top,entries in catalog.items():
        lines=[]
        for key,value in entries.items():
            if isinstance(value,str):v=quote(value)
            else:v="['text' => "+quote(value['text'])+", 'parameters' => ["+', '.join(map(quote,value['parameters']))+"]]"
            lines.append('    '+quote(key)+' => '+v+',')
        (ROOT/'messages/fa'/f'{top}.php').write_text(header+'\n'.join(lines)+'\n];\n')
    # Existing customization/placeholder contracts retain their stable facade.
    values=sources['settings/values.php'].decode()
    legacy=json.loads(subprocess.check_output(['php','-r',
        '$s=stream_get_contents(STDIN);ob_start();eval("?>".$s);ob_end_clean();echo json_encode([$mainValues,$buttonValues],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);'],input=sources['settings/values.php']))
    for top,entries in zip(['legacy_messages','legacy_buttons'],legacy):
        if not all(isinstance(k,str) and isinstance(v,str) for k,v in entries.items()):raise ValueError('non-string legacy catalog entry')
        lines=['    '+quote(k)+' => '+quote(v)+',' for k,v in entries.items()]
        (ROOT/'messages/fa'/f'{top}.php').write_text(header+'\n'.join(lines)+'\n];\n')
    (ROOT/'settings/values.php').write_text("<?php\n/** Compatibility facade: existing stored/customized message keys remain unchanged. */\nrequire_once __DIR__ . '/../messages/loader.php';\n$mainValues = achbotMessages('legacy_messages');\n$buttonValues = achbotMessages('legacy_buttons');\n")
    inventory['values_sha256']=hashlib.sha256(sources['settings/values.php']).hexdigest()
    (ROOT/'tools/text_catalog_inventory.json').write_text(json.dumps(inventory,ensure_ascii=False,indent=2)+'\n')
if __name__=='__main__':main()
