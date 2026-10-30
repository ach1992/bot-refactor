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
    current=sides
    sides,api_proof=verify_api_and_restore(sides)
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
    return copy.deepcopy(old),{'changes':len(changes),'retired':len(removed),'added':len(added),'current_keys':sum(map(len,current.values())),'r5_current_keys':sum(map(len,sides.values())),**api_proof}
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

# Current API copy is a separately reviewed additions-only layer. The original
# R5 baseline and its amendment file remain byte-for-byte historical evidence.
API_BASE='2d0ad0826d594adac49f5a83f0a9d47f6df5b264'
API_RESULT='df00c13da1e38e2ba364ab48e31db66f8705b2c2'
API_COMMITS=['2633ac3c21c539635c228dd31df2e07caee491d0',
             '2c685f5a9621a808c44a4c012b04c8ccd97102af',API_RESULT]
API_AUTH='https://github.com/ach1992/bot-refactor/issues/57#issuecomment-6514754219'
API_RETAINED={'payment.api_payment.completed','payment.api_payment.receipt_review',
              'manual_payment.api_payment.review_staff','manual_payment.api_payment.automatic_status',
              'manual_payment.api_payment.status_monitoring'}
def api_manifest():return json.loads((ROOT/'tools/text_catalog_owner_r6_api.json').read_text())
@functools.lru_cache(maxsize=None)
def api_catalog_snapshot(ref):
    check(ref in [API_BASE,*API_COMMITS],'api_catalog_snapshot_identity')
    decode='$s=stream_get_contents(STDIN);$p=strpos($s,"return [");if($p===false)throw new RuntimeException("array_missing");echo json_encode(eval(substr($s,$p)),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);'
    return {a:flatten(json.loads(command('php','-r',decode,input=command('git','show',ref+':messages/fa/'+a+'.php'))))for a in ['user','admin']}
def verify_api_and_restore(sides,definition=None):
    record=api_manifest()if definition is None else definition
    check(record.get('version')==1 and record.get('baseline_commit')==API_BASE
          and record.get('result_catalog_commit')==API_RESULT and record.get('accepted_commits')==API_COMMITS
          and record.get('authorization')==API_AUTH and record.get('scope')=='additions_only',
          'api_catalog_amendment_identity')
    before=api_catalog_snapshot(API_BASE);accepted=api_catalog_snapshot(API_RESULT)
    check(sum(map(len,before.values()))==2685,'api_prior_r5_catalog_identity')
    delta={}
    for audience in ['user','admin']:
        check(set(before[audience])<=set(accepted[audience]) and
              all(accepted[audience][key]==value for key,value in before[audience].items()),
              'api_catalog_changed_prior_copy')
        for key in set(accepted[audience])-set(before[audience]):
            check(key not in delta and all(key not in rows for rows in before.values()),'api_catalog_key_overlap:'+key)
            delta[key]=(audience,accepted[audience][key])
    added=record.get('added_keys',{})
    check(len(delta)==86 and sum(a=='user'for a,_ in delta.values())==22 and set(added)==set(delta),
          'api_catalog_exact_addition_scope')
    check(record.get('retained_unreferenced_keys')==sorted(API_RETAINED),'api_retained_exact_key_inventory')
    allowed_sources={'bot.php','src/Finance/PaymentApiAdmin.php','src/Finance/ApiPayments.php',
                     'src/Finance/Routes/ApiPayments.php','src/Finance/ReceiptHelpers.php'}
    for key,item in added.items():
        audience,value=delta[key]
        check(item.get('audience')==audience and item.get('entry')==value,'api_catalog_accepted_value_or_audience:'+key)
        introduced=next(ref for ref in API_COMMITS if key in api_catalog_snapshot(ref)[audience])
        check(item.get('introduced_in')==introduced and item.get('reason'),'api_catalog_entry_lineage:'+key)
        paths=item.get('source_paths');usage=item.get('usage')
        check(isinstance(paths,list) and len(paths)==len(set(paths)) and set(paths)<=allowed_sources,'api_catalog_source_inventory:'+key)
        check((key in API_RETAINED and usage=='retained_accepted' and not paths) or
              (key not in API_RETAINED and usage in ['settings_source','pending_flow'] and paths),
              'api_catalog_usage_identity:'+key)
        check(isinstance(text(value),str) and isinstance(params(value),list) and
              len(set(params(value)))==len(params(value)) and
              set(re.findall(r'\{([A-Za-z_][A-Za-z0-9_]*)\}',text(value)))==set(params(value)),
              'api_catalog_parameters:'+key)
        check(not re.search(r'[\u06f0-\u06f9\u0660-\u0669]',text(value)),'api_catalog_non_ascii_digits:'+key)
    # Compare the complete catalog, not a prefix-filtered projection. Unknown
    # additions and changes to the earlier layers remain hard failures.
    check(sides==accepted,'api_undeclared_catalog_key_value_parameter_or_recipient')
    return copy.deepcopy(before),{'api_added_keys':len(delta),'api_added_user_keys':22,'api_added_admin_keys':64,
        'api_baseline_keys':2685,'api_current_keys':2771,'api_retained_unreferenced_keys':len(API_RETAINED)}
def api_negative_controls(sides):
    definition=api_manifest();key='payment.api_payment.bank_invoice';controls=[]
    bad=copy.deepcopy(sides);bad['user']['payment.api_payment.unapproved']='ناشناخته';controls.append((bad,definition))
    bad=copy.deepcopy(sides);bad['admin']['unapproved.extra']='ناشناخته';controls.append((bad,definition))
    bad=copy.deepcopy(sides);bad['user'][key]['text']+=' تغییر ثبت‌نشده';controls.append((bad,definition))
    bad=copy.deepcopy(sides);bad['user'][key]['parameters']=['amount'];controls.append((bad,definition))
    bad=copy.deepcopy(sides);bad['user'][key]['parameters'].reverse();controls.append((bad,definition))
    bad=copy.deepcopy(sides);bad['admin'][key]=bad['user'].pop(key);controls.append((bad,definition))
    bad=copy.deepcopy(sides);bad['user'].pop(key);controls.append((bad,definition))
    bad=copy.deepcopy(sides);bad['admin'][key]=copy.deepcopy(bad['user'][key]);controls.append((bad,definition))
    candidate=copy.deepcopy(sides);record=copy.deepcopy(definition);candidate['user'][key]='Forged';record['added_keys'][key]['entry']='Forged';controls.append((candidate,record))
    candidate=copy.deepcopy(sides);record=copy.deepcopy(definition);candidate['user']['new.extra']='Forged';record['added_keys']['new.extra']=copy.deepcopy(record['added_keys'][key]);controls.append((candidate,record))
    bad=copy.deepcopy(definition);bad['baseline_commit']=BASE;controls.append((sides,bad))
    bad=copy.deepcopy(definition);bad['added_keys'][key]['reason']='';controls.append((sides,bad))
    bad=copy.deepcopy(definition);bad['added_keys'][key]['source_paths']=['unapproved.php'];controls.append((sides,bad))
    bad=copy.deepcopy(sides);bad['user']['legacy_messages.banned']='تغییر قدیمی ثبت‌نشده';controls.append((bad,definition))
    for candidate,record in controls:
        try:verify_api_and_restore(candidate,record)
        except AssertionError:continue
        raise AssertionError('api_catalog_negative_control_missed')
    return len(controls)
