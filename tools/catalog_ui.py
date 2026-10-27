"""Token-based ASCII UI sink inventory, with bounded local variable, array-field and return tracing.

This is an audit aid, not a PHP interpreter. It excludes array/record keys,
comparisons, formatting/encoding/protocol arguments and catalog references.
Human-readable indirect admin report fields are explicitly included.
"""
from __future__ import annotations
import collections,json,re,subprocess
SINKS={'sendMessage':(0,),'editText':(1,),'alert':(0,),
       'answerCallbackQuery':(1,),'showForm':(0,1),
       'achbotRenderPaymentPage':(0,1),'sendDocument':(1,),
       'sendPhoto':(1,),'sendWithStartButton':(0,1),
       'notifyAllAdminsSimple':(0,)}
TECHNICAL_CALLS={'achbotMessage','achbotMessageHtml','achbotMessages','date','jdate',
 'strftime','strtotime','preg_match','preg_replace','preg_quote','str_replace',
 'str_contains','str_starts_with','str_ends_with','in_array','array_key_exists',
 'method_exists','function_exists','class_exists','hash','hash_equals',
 'header','error_log','logFailure','defined','constant'}
TECHNICAL_VALUES={'UTF-8','utf-8','HTML','Markdown','MarkDown','JSON','GET','POST',
 'ENT_QUOTES','ENT_SUBSTITUTE','n/a'}
COMPARISON={'T_IS_EQUAL','T_IS_IDENTICAL','T_IS_NOT_EQUAL','T_IS_NOT_IDENTICAL',
 'T_IS_SMALLER_OR_EQUAL','T_IS_GREATER_OR_EQUAL'}
def ui_candidates(root,paths):
    all_tokens=json.loads(subprocess.check_output(['php','tools/catalog_tokens.php','--all'],
        cwd=root,input=json.dumps(paths).encode()))
    by_path=collections.defaultdict(list)
    for t in all_tokens:
        if t['kind'] not in {'T_COMMENT','T_DOC_COMMENT','T_WHITESPACE','T_OPEN_TAG','T_CLOSE_TAG'}:
            by_path[t['path']].append(t)
    result=[]
    for path,tokens in by_path.items():
        stack=[];matching={}
        for i,t in enumerate(tokens):
            k=t['kind']
            if k in {'(','[','{'}:stack.append((k,i))
            elif k in {')',']','}'} and stack:
                op,j=stack.pop();matching[j]=i
        def expression_end(start,closing=None):
            j=start
            while j<len(tokens):
                if tokens[j]['kind'] in {',',';',')',']','}'}:return j
                if j in matching:j=matching[j]+1
                else:j+=1
            return j
        def args(op):
            end=matching.get(op)
            if end is None:return []
            spans=[];start=op+1;j=start
            while j<end:
                if tokens[j]['kind']==',':spans.append((start,j));start=j+1
                elif j in matching:j=matching[j]
                j+=1
            spans.append((start,end));return spans
        function_regions=[];functions=collections.defaultdict(list)
        for index,token in enumerate(tokens):
            if token['kind']!='T_FUNCTION':continue
            body=index+1
            while body<len(tokens) and tokens[body]['kind'] not in {'{',';'}:body+=1
            if body in matching:
                region=(body,matching[body]);function_regions.append(region)
                name=index+1
                if tokens[name]['kind']=='&':name+=1
                if tokens[name]['kind']=='T_STRING':functions[tokens[name]['raw']].append(region)
        def scope(index):
            regions=[(a,b) for a,b in function_regions if a<index<b]
            return min(regions,key=lambda r:r[1]-r[0]) if regions else None
        def access(index,end):
            parts=[tokens[index]['raw']];j=index+1
            while j+2<end and tokens[j]['kind']=='[' and matching.get(j)==j+2:
                field=tokens[j+1]
                if field['kind'] not in {'T_CONSTANT_ENCAPSED_STRING','T_LNUMBER'}:break
                parts.append(field['raw'][1:-1] if field['kind']=='T_CONSTANT_ENCAPSED_STRING' else field['raw'])
                j+=3
            return tuple(parts),j
        calls=[];ranges=[];assignments=collections.defaultdict(list);returns=collections.defaultdict(list)
        for i,t in enumerate(tokens):
            if t['kind'] in {'T_STRING','T_NAME_FULLY_QUALIFIED','T_NAME_QUALIFIED'} and i+1<len(tokens) and tokens[i+1]['kind']=='(':
                if i and tokens[i-1]['kind']=='T_FUNCTION':continue
                name=t['raw'].split('\\')[-1];spans=args(i+1)
                end=matching.get(i+1,i+1);calls.append((i,end,name,spans))
                positions=SINKS.get(name,())
                if name=='showForm' and path.endswith('GatewayInitiation.php'):positions=(0,)
                if '\\Telegram\\Messages\\' in t['raw']:
                    positions={'sendMessage':(2,),'editText':(3,),'alert':(2,),
                               'answerCallbackQuery':(3,),'sendDocument':(3,),'sendPhoto':(3,)}.get(name,())
                for arg in positions:
                    if arg<len(spans):ranges.append((*spans[arg],'sink'))
            if t['kind']=='T_CONSTANT_ENCAPSED_STRING' and t['raw'][1:-1] in {'text','caption'} and i+1<len(tokens) and tokens[i+1]['kind']=='T_DOUBLE_ARROW':
                ranges.append((i+2,expression_end(i+2),'field'))
            if t['kind']=='T_VARIABLE':
                target,j=access(i,len(tokens))
                if j<len(tokens) and tokens[j]['kind'] in {'=','T_CONCAT_EQUAL'}:
                    assignments[target].append((i,j+1,expression_end(j+1),tokens[j]['kind']))
            if t['kind']=='T_RETURN':
                returns[scope(i)].append((i+1,expression_end(i+1)))
        # A demand keeps its selected record field through assignments and
        # same-file named function returns. Other record fields stay machine data.
        queue=[(a,b,origin,()) for a,b,origin in ranges];seen=set(queue)
        def enqueue(a,b,origin,selected=()):
            item=(a,b,origin,selected)
            if item not in seen:
                seen.add(item);queue.append(item)
        while queue:
            a,b,origin,selected=queue.pop()
            if not selected and a<b and tokens[a]['kind']=='[' and matching.get(a)==b-1:
                if any(start+1<end and tokens[start+1]['kind']=='T_DOUBLE_ARROW' for start,end in args(a)):
                    continue # A whole record is structured data; trace a demanded field.
            if not selected:ranges.append((a,b,origin))
            if selected and a<b and tokens[a]['kind']=='[' and matching.get(a)==b-1:
                for start,end in args(a):
                    if start+1<end and tokens[start+1]['kind']=='T_DOUBLE_ARROW':
                        key=tokens[start]['raw'].strip("'\"")
                        if key==selected[0]:enqueue(start+2,end,'array_field',selected[1:])
                continue
            known=[c for c in calls if a<=c[0]<b and c[2] in functions]
            for start,end,name,_ in known:
                # Ambiguous duplicate/anonymous definitions are not guessed.
                if len(functions[name])==1:
                    for ra,rb in returns[functions[name][0]]:
                        enqueue(ra,rb,'return',selected)
            references=[]
            i=a
            while i<b:
                if tokens[i]['kind']=='T_VARIABLE':
                    key,j=access(i,b);references.append(key+selected);i=j
                else:
                    if tokens[i]['kind']=='interpolated':
                        references.extend((value,)+selected for kind,value in tokens[i]['parts'] if kind=='T_VARIABLE')
                    i+=1
            for key in set(references):
                if len(key)==1 and key[0] in {'$action','$field','$mode','$state','$stage','$provider','$method','$step','$data','$type','$reason','$sqlstate','$gateType'}:continue
                # Try field writes as well as whole-record assignments. Select
                # only the requested field from an initializer/returned record.
                for size in range(len(key),0,-1):
                    preceding=[x for x in assignments[key[:size]] if x[0]<a and scope(a)==scope(x[0])]
                    if not preceding:continue
                    base=len(preceding)-1
                    while base>0 and preceding[base][3]=='T_CONCAT_EQUAL':base-=1
                    for _,start,end,_ in preceding[base:]:
                        enqueue(start,end,'array_field' if len(key)>1 else 'variable',key[size:])
        for i,t in enumerate(tokens):
            if t['kind']=='T_INLINE_HTML':
                raw=t['raw']
                masked=re.sub(r'<(?:style|script)\b[^>]*>[\s\S]*?</(?:style|script)\s*>',lambda m:' '*len(m[0]),raw,flags=re.I)
                for match in re.finditer(r'(?<=>)[^<>]+(?=<|$)|^[^<>]+(?=<|$)',masked):
                    if re.search('[A-Za-z]',match[0]):
                        result.append(t|{'start':t['start']+len(raw[:match.start()].encode()),
                            'end':t['start']+len(raw[:match.end()].encode()),
                            'line':t['line']+raw[:match.start()].count('\n'),
                            'raw':raw[match.start():match.end()],'ui_origin':['html_text']})
                continue
            if t['kind'] not in {'T_CONSTANT_ENCAPSED_STRING','interpolated'}:continue
            owners=[origin for a,b,origin in ranges if a<=i<b]
            if not owners:continue
            raw=t['raw'];value=raw[1:-1]
            visible=''.join(v for k,v in t.get('parts',[]) if k=='T_ENCAPSED_AND_WHITESPACE') if t['kind']=='interpolated' else value
            visible=re.sub(r'\\[nrt]','',re.sub('<[^>]*>','',visible))
            if not re.search('[A-Za-z]',visible):continue
            # Associative/record keys and compared protocol/state values are code.
            prev=tokens[i-1]['kind'] if i else ''
            nxt=tokens[i+1]['kind'] if i+1<len(tokens) else ''
            if prev=='[' or nxt=='T_DOUBLE_ARROW' or prev in COMPARISON or nxt in COMPARISON:continue
            if any(a<i<=b and name in TECHNICAL_CALLS for a,b,name,_ in calls):continue
            if value in TECHNICAL_VALUES or value in {'on','off','pending','review','completed','neutral','failed','paid','BEP20','TRC20','ERC20','UsdtReceipt','GiftReceipt'}:continue
            transparent={'json_encode','htmlspecialchars','htmlentities','trim','ltrim','rtrim','sprintf','vsprintf','implode','strval'}
            containing=[(a,b,name,spans) for a,b,name,spans in calls if a<i<=b]
            inner=min(containing,key=lambda c:c[1]-c[0]) if containing else None
            if inner and inner[2] not in transparent and inner[2] not in SINKS:continue
            if re.match(r'(?is)^\s*(?:SELECT|INSERT|UPDATE|DELETE|ALTER|CREATE|SHOW|WHERE|AND|OR|FROM|FOR UPDATE|ORDER BY|LIMIT|EXISTS)\b',value):continue
            if re.match(r'^(?:https?://|tg://|t\.me/|/\^|/\\A|~\^|[a-z]+\|)',value):continue
            if re.fullmatch(r'</?[A-Za-z][A-Za-z0-9]*(?:\s[^>]*)?>\\?[nr]?',value):continue
            if value.startswith(('achbot_','ACHBOT_')):continue
            if owners==['variable'] and re.fullmatch('[a-z][A-Za-z0-9_:-]*',visible.strip()):continue
            if re.fullmatch(r'/[A-Za-z][A-Za-z0-9_]*',value):continue
            result.append(t|{'ui_origin':sorted(set(owners))})
    return result
