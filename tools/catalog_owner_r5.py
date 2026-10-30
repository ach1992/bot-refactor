"""Owner revision5 current-copy proof layered over immutable accepted catalog history."""
from __future__ import annotations
import copy,functools,json,pathlib,re,subprocess
ROOT=pathlib.Path(__file__).resolve().parents[1]
BASE='64feca4b777a442023676122e315c68ad26e902d'
AUTH='https://github.com/ach1992/bot-refactor/issues/26#issuecomment-6486592777'
def check(ok,message):
    if not ok:raise AssertionError(message)
def flatten(groups):return {topic+'.'+key:entry for topic,entries in groups.items()for key,entry in entries.items()}
def text(entry):return entry['text']if isinstance(entry,dict)else entry
def params(entry):return entry['parameters']if isinstance(entry,dict)else []
def command(*args,**kwargs):return subprocess.check_output(args,cwd=ROOT,**kwargs)
@functools.lru_cache(maxsize=1)
def baseline():
    decode='$s=stream_get_contents(STDIN);$p=strpos($s,"return [");if($p===false)throw new RuntimeException("array_missing");echo json_encode(eval(substr($s,$p)),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);'
    return {a:flatten(json.loads(command('php','-r',decode,input=command('git','show',BASE+':messages/fa/'+a+'.php'))))for a in ['user','admin']}
def manifest():return json.loads((ROOT/'tools/text_catalog_owner_r5.json').read_text())
def verify_and_restore(sides,definition=None):
    record=manifest()if definition is None else definition
    check(record.get('version')==1 and record.get('baseline_commit')==BASE and record.get('authorization')==AUTH,'r5_amendment_identity')
    old=baseline();check(sum(map(len,old.values()))==2635,'r5_baseline_identity')
    expected=copy.deepcopy(old);changes=record.get('changes',{});removed=record.get('retired_keys',{});added=record.get('added_keys',{})
    check(changes and removed and added,'r5_amendment_scope_missing')
    check(not(set(changes)&set(removed)or set(changes)&set(added)or set(removed)&set(added)),'r5_amendment_scope_overlap')
    for kind,entries in [('changes',changes),('retired',removed),('added',added)]:
        for key,item in entries.items():
            audience=item.get('audience');check(audience in ['user','admin']and item.get('reason'),'r5_amendment_recipient_reason:'+key)
            if kind=='added':
                check(all(key not in rows for rows in old.values()),'r5_added_key_existed:'+key)
                check(isinstance(item.get('source_paths'),list)and item['source_paths'],'r5_added_source_identity:'+key)
                value=item.get('entry');expected[audience][key]=value
            else:
                check(key in old[audience]and item.get('before')==old[audience][key],'r5_before_identity:'+key)
                if kind=='retired':expected[audience].pop(key);continue
                value=item.get('after');check(value!=item['before'],'r5_spurious_amendment:'+key)
                check(params(value)==params(item['before']),'r5_parameters_changed:'+key)
                expected[audience][key]=value
            check(isinstance(text(value),str)and isinstance(params(value),list)and len(set(params(value)))==len(params(value)),'r5_invalid_template:'+key)
            check(set(re.findall(r'\{([A-Za-z_][A-Za-z0-9_]*)\}',text(value)))==set(params(value)),'r5_template_slots:'+key)
            check(not re.search(r'[\u06f0-\u06f9\u0660-\u0669]',text(value)),'r5_non_ascii_digits:'+key)
    check(sides==expected,'r5_undeclared_catalog_change_or_recipient')
    return copy.deepcopy(old),{'changes':len(changes),'retired':len(removed),'added':len(added),'current_keys':sum(map(len,sides.values()))}
def negative_controls(sides):
    definition=manifest();controls=[]
    key=next(iter(definition['changes']));audience=definition['changes'][key]['audience']
    bad=copy.deepcopy(sides);bad[audience][key]='Unrecorded copy';controls.append((bad,definition))
    bad=copy.deepcopy(definition);bad['changes'][key]['before']='Forged baseline';controls.append((sides,bad))
    bad=copy.deepcopy(sides);bad['user'if audience=='admin'else'admin'][key]=bad[audience].pop(key);controls.append((bad,definition))
    bad=copy.deepcopy(sides);bad[audience]['unapproved.extra']='Unapproved';controls.append((bad,definition))
    bad=copy.deepcopy(definition);bad['changes'][key]['reason']='';controls.append((sides,bad))
    for candidate,record in controls:
        try:verify_and_restore(candidate,record)
        except AssertionError:continue
        raise AssertionError('r5_negative_control_missed')
    return len(controls)

def verify_retained_display_keys():
    """Exact former display keys remain editable lineage, never an orphan exemption."""
    records=manifest().get('retained_display_source_keys',{})
    check(len(records)==26,'retained_display_key_count_drift')
    current=json.loads(command('php','-r','$g=require "messages/fa/admin.php";$r=[];foreach($g as$t=>$es)foreach($es as$k=>$v)$r[$t.".".$k]=$v;echo json_encode($r);'))
    decode='$s=stream_get_contents(STDIN);$p=strpos($s,"return [");echo json_encode(eval(substr($s,$p)));'
    baseline=flatten(json.loads(command('php','-r',decode,input=command('git','show','ec171d5bdd2d4487b8bc2c5782015f25c703cce6:messages/fa/admin.php'))))
    source=command('git','show','ec171d5bdd2d4487b8bc2c5782015f25c703cce6:src/Subscription/ServerDisplaySettings.php').decode()
    for key,item in records.items():
        check(item.get('baseline')=='ec171d5bdd2d4487b8bc2c5782015f25c703cce6'
              and item.get('source')=='src/Subscription/ServerDisplaySettings.php' and item.get('reason')
              and key in source and current.get(key)==baseline.get(key)==item.get('entry'),
              'retained_display_source_lineage_drift:'+key)
    return set(records)
