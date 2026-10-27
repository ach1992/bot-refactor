"""Token-based ASCII UI sink inventory, with bounded local variable tracing.

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
        function_regions=[]
        for index,token in enumerate(tokens):
            if token['kind']!='T_FUNCTION':continue
            body=index+1
            while body<len(tokens) and tokens[body]['kind'] not in {'{',';'}:body+=1
            if body in matching:function_regions.append((body,matching[body]))
        def scope(index):
            regions=[(a,b) for a,b in function_regions if a<index<b]
            return min(regions,key=lambda r:r[1]-r[0]) if regions else None
        calls=[];ranges=[];assignments=collections.defaultdict(list)
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
            if t['kind']=='T_VARIABLE' and i+1<len(tokens) and tokens[i+1]['kind'] in {'=','T_CONCAT_EQUAL'}:
                assignments[t['raw']].append((i,i+2,expression_end(i+2),tokens[i+1]['kind']))
        # Trace local display variables, including adjacent .= fragments.
        queue=list(ranges);seen=set(ranges)
        while queue:
            a,b,origin=queue.pop()
            variables={t['raw'] for t in tokens[a:b] if t['kind']=='T_VARIABLE'}
            variables.update(value for t in tokens[a:b] if t['kind']=='interpolated'
                             for kind,value in t['parts'] if kind=='T_VARIABLE')
            for var in variables:
                if var in {'$action','$field','$mode','$state','$stage','$provider','$method','$step','$data','$type','$reason','$sqlstate','$gateType'}:continue
                preceding=[x for x in assignments[var] if x[0]<a and scope(a)==scope(x[0])]
                if not preceding:continue
                base=len(preceding)-1
                while base>0 and preceding[base][3]=='T_CONCAT_EQUAL':base-=1
                for _,start,end,_ in preceding[base:]:
                    span=(start,end,'variable')
                    if span not in seen:seen.add(span);ranges.append(span);queue.append(span)
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
