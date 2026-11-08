"""Owner revision5 current-copy proof layered over immutable accepted catalog history."""
from __future__ import annotations
import copy,functools,hashlib,json,pathlib,re,subprocess
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
def addon_selection_restore(sides):
    """Revert only the newly Owner-approved RC22 customer add-on copy in a copy.

    Retain the previously accepted R5/API text proofs unchanged, including
    their historical exact values. Unknown catalog mutations still fail.
    """
    record=json.loads((ROOT/'tools/text_catalog_addon_selection_ui.json').read_text())
    parent='4d7d1c04f15e5d58eb9c8030fc086b8658ba6c49'
    check(record.get('version')==1 and record.get('accepted_parent')==parent and
          record.get('owner_decision')==
          'https://github.com/ach1992/bot-refactor/issues/107#issuecomment-6594462582'
          and record.get('audience')=='user', 'addon_selection_manifest_identity')
    changes=record.get('entries')
    expected_keys={'renewal.extensions.button.fbb9f61105',
                   'renewal.extensions.button.249377f2b7',
                   'renewal.extensions.button.8405a4154f'}
    check(type(changes) is dict and set(changes)==expected_keys,
          'addon_selection_exact_keys')
    decode='$s=stream_get_contents(STDIN);$p=strpos($s,"return [");if($p===false)throw new RuntimeException("array_missing");echo json_encode(eval(substr($s,$p)),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);'
    original=flatten(json.loads(command('php','-r',decode,
        input=command('git','show',parent+':messages/fa/user.php'))))
    result=copy.deepcopy(sides)
    for key,item in changes.items():
        before=item.get('before');after=item.get('after')
        check(item.get('source_paths')==['src/Finance/Routes/Extensions.php'] and
              before==original.get(key) and before!=after and
              result['user'].get(key)==after and key not in result['admin'],
              'addon_selection_copy_or_baseline_drift:'+key)
        check(isinstance(text(after),str) and params(after)==params(before) and
              set(re.findall(r'\{([A-Za-z_][A-Za-z0-9_]*)\}',text(after)))==set(params(after))
              and not re.search(r'[\u0660-\u0669\u06f0-\u06f9]',text(after)),
              'addon_selection_slots_or_digits:'+key)
        result['user'][key]=before
    return result

def scoped_addon_restore(sides):
    """Strict additive/current-only scoped-addon copy layer; preserve accepted proof totals.

    Only exactly declared admin keys are removed from a *copy*. The historical
    R5/API/Gift/Review snapshots and all previously accepted catalog keys remain
    fully compared against their original immutable references.
    """
    manifest=json.loads((ROOT/"tools/text_catalog_addon_scopes.json").read_text())
    check(manifest.get('version')==1 and
          manifest.get('project_issue')=='https://github.com/ach1992/bot-refactor/issues/107' and
          manifest.get('audience')=='admin' and manifest.get('topic')=='addon_scopes',
          'addon_catalog_manifest_identity')
    expected=manifest.get('entries')
    check(type(expected) is dict and len(expected)==14,'addon_catalog_key_inventory')
    check(type(sides) is dict and set(sides)=={'user','admin'},'addon_catalog_audiences')
    sides=addon_selection_restore(sides)
    check(all('addon_scopes.' not in key for key in sides['user']), 'addon_catalog_cross_audience')
    real={key:value for key,value in sides['admin'].items() if key.startswith('addon_scopes.')}
    check(set(real)=={'addon_scopes.'+key for key in expected},'addon_catalog_additions_only')
    for key,item in expected.items():
        check(type(item) is dict and set(item)=={'value','source_paths'} and
              item['source_paths']==['src/Plans/AddonAdmin.php'], 'addon_catalog_source:'+key)
        value=item['value']
        check(real['addon_scopes.'+key]==value and
              isinstance(text(value),str) and isinstance(params(value),list) and
              len(set(params(value)))==len(params(value)) and
              set(re.findall(r'\{([A-Za-z_][A-Za-z0-9_]*)\}',text(value)))==set(params(value)) and
              re.search(r'[\u0660-\u0669\u06f0-\u06f9]',text(value)) is None,
              'addon_catalog_value_or_parameters:'+key)
    restored={audience:dict(rows) for audience,rows in sides.items()}
    for key in real:restored['admin'].pop(key)
    return restored

# #112: tightly pinned new Transfer and shared-panel wizard copy. The old
# independently accepted R5/API/Gift/RC22 catalog proofs remain unchanged.
SHARED_TRANSFER_SOURCE='b654336d92a7e8e994e24d32144e995b5dba1407'
@functools.lru_cache(maxsize=1)
def shared_transfer_baseline():
    decode='$s=stream_get_contents(STDIN);$p=strpos($s,"return [");if($p===false)throw new RuntimeException("array_missing");echo json_encode(eval(substr($s,$p)),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);'
    return {a:flatten(json.loads(command('php','-r',decode,
        input=command('git','show',SHARED_TRANSFER_SOURCE+':messages/fa/'+a+'.php'))))
        for a in ['user','admin']}

@functools.lru_cache(maxsize=2)
def shared_transfer_source_lines(path,original=False):
    raw=command('git','show',SHARED_TRANSFER_SOURCE+':'+path).decode() if original else (ROOT/path).read_text()
    lines={}
    topic=None
    for line in raw.splitlines():
        heading=re.match(r"^    '([^']+)'\s*=>\s*\[$",line)
        if heading:
            topic=heading.group(1)
            continue
        entry=re.match(r"^        '([^']+)'\s*=>",line)
        if entry and topic is not None:
            key=topic+'.'+entry.group(1)
            check(key not in lines,'shared_transfer_source_duplicate:'+key)
            lines[key]=line
    return lines

@functools.lru_cache(maxsize=1)
def shared_transfer_shipped_values():
    # Compare synthetic negative controls to the actual pinned shipping copy,
    # never just to the key inventory. The source line hash is verified below.
    return {audience:flatten(json.loads(command(
        'php','-r',
        '$g=require "messages/fa/'+audience+'.php";echo json_encode($g,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);'
    ))) for audience in ['user','admin']}

def shared_transfer_copy_restore(sides):
    record=json.loads((ROOT/'tools/text_catalog_shared_transfer.json').read_text())
    check(record.get('version')==1 and
          record.get('accepted_source_commit')==SHARED_TRANSFER_SOURCE and
          record.get('owner_issue')=='https://github.com/ach1992/bot-refactor/issues/112',
          'shared_transfer_catalogue_identity')
    entries=record.get('entries')
    check(isinstance(entries,dict) and len(entries)==11 and
          all(isinstance(item,dict) and set(item)=={'audience','before_sha256','after_sha256'}
              for item in entries.values()),'shared_transfer_catalogue_shape')
    baseline=shared_transfer_baseline()
    shipped=shared_transfer_shipped_values()
    check(type(sides) is dict and set(sides)=={'user','admin'},
          'shared_transfer_catalogue_audiences')
    # Restore only the eleven exact Owner-approved copy changes. Unknown
    # mutations stay visible to the unchanged API/R5 whole-catalogue proof;
    # this also preserves its original negative-control failure categories.
    restored=copy.deepcopy(sides)
    for key,item in entries.items():
        audience=item['audience']
        check(audience in ['user','admin'] and
              key not in sides['admin' if audience=='user' else 'user'],
              'shared_transfer_audience_invalid:'+key)
        path='messages/fa/'+audience+'.php'
        for original,field in [(True,'before_sha256'),(False,'after_sha256')]:
            line=shared_transfer_source_lines(path,original).get(key)
            digest=hashlib.sha256(line.encode()).hexdigest() if line is not None else None
            check(digest==item[field],'shared_transfer_source_value_drift:'+key)
        before=baseline[audience].get(key)
        if key in baseline[audience]:
            check(item['before_sha256'] is not None,
                  'shared_transfer_baseline_missing_hash:'+key)
            restored[audience][key]=before
        else:
            check(item['before_sha256'] is None,
                  'shared_transfer_added_key_baseline:'+key)
            restored[audience].pop(key,None)
        # All new/changed keys must be present in their approved audience.
        # Obsolete keys must remain absent. Unknown unrelated mutations are
        # intentionally handled by the older whole-catalogue check below.
        check((key in sides[audience])==(item['after_sha256'] is not None),
              'shared_transfer_key_presence:'+key)
        if item['after_sha256'] is not None:
            check(sides[audience][key]==shipped[audience][key],
                  'shared_transfer_approved_value_drift:'+key)
    return restored


# #114: owner-approved group chooser/failure copy without changing the
# immutable R5/API and #112 catalog evidence. Restore exactly the known
# values in a validation copy, never in the actual source.
PANEL_GROUP_PARENT='8c0af0899051ed60253fe5d40301dfdbb1d2e5de'
def panel_group_copy_restore(sides):
    record=json.loads((ROOT/'tools/text_catalog_panel_group_ownership.json').read_text())
    entries=record.get('entries')
    keys={'bot.bot.addserver_name.message.c31e264971',
          'bot.bot.addserver_name.alert.27e6f9c466',
          'bot.bot.addserver_name.alert.9ee9f25355',
          'bot.bot.addserver_name.alert.a5625b915f'}
    check(record.get('version')==1 and
          record.get('owner_issue')=='https://github.com/ach1992/bot-refactor/issues/114' and
          record.get('accepted_parent')==PANEL_GROUP_PARENT and
          record.get('audience')=='admin' and
          type(entries) is dict and set(entries)==keys,
          'panel_group_copy_manifest_identity')
    path='messages/fa/admin.php'
    original=command('git','show',PANEL_GROUP_PARENT+':'+path).decode('utf-8')
    current=(ROOT/path).read_text()
    def lines(source):
        topic=None;out={}
        for line in source.splitlines():
            heading=re.match(r"^    '([^']+)'\s*=>\s*\[$",line)
            if heading:topic=heading.group(1);continue
            entry=re.match(r"^        '([^']+)'\s*=>",line)
            if entry and topic is not None:out[topic+'.'+entry.group(1)]=line
        return out
    old_lines,new_lines=lines(original),lines(current)
    decode='$s=stream_get_contents(STDIN);$p=strpos($s,"return [");if($p===false)throw new RuntimeException("array_missing");echo json_encode(eval(substr($s,$p)),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);'
    prior=flatten(json.loads(command('php','-r',decode,input=original.encode('utf-8'))))
    restored=copy.deepcopy(sides)
    check(set(restored)=={'user','admin'},'panel_group_copy_audiences')
    for key,item in entries.items():
        check(set(item)=={'before_sha256','after_sha256','reason'} and bool(item['reason']),
              'panel_group_copy_entry_shape:'+key)
        expected_old=hashlib.sha256(old_lines[key].encode()).hexdigest()
        expected_new=hashlib.sha256(new_lines[key].encode()).hexdigest()
        check(item['before_sha256']==expected_old and
              item['after_sha256']==expected_new and
              expected_old!=expected_new and
              key in prior and key in restored['admin'] and key not in restored['user'],
              'panel_group_copy_source_drift:'+key)
        check(isinstance(restored['admin'][key],str) and
              re.search(r'[\u06f0-\u06f9\u0660-\u0669]',restored['admin'][key]) is None,
              'panel_group_copy_value_invalid:'+key)
        restored['admin'][key]=prior[key]
    return restored

# #112 Owner Revision 4 supersedes forced retirement: the policy is
# admin-confirmed hard deletion plus explicit server-sale/renewal states and
# plan visibility. Pin each changed/additive admin key against source while
# restoring only the evidenced delta for immutable historical R5/API proofs.
SERVER_REMOVAL_PARENT='218319313fdbd9cee8aeea15e1c073997f4485e7'
SERVER_REMOVAL_KEYS=(
    'admin_settings.management.server_delete_confirm',
    'admin_settings.management.server_removed',
    'admin_settings.management.server_retired',
    'admin_settings.management.server_state_prompt',
    'admin_settings.management.server_state_full',
    'admin_settings.management.server_state_renew_only',
    'admin_settings.management.server_state_off',
    'admin_settings.management.plan_hide_from_sale',
    'admin_settings.management.plan_offer_for_sale',
    'admin_settings.management.plan_delete_confirm',
    'admin_settings.management.plan_deleted',
)

@functools.lru_cache(maxsize=1)
def server_removal_baseline():
    path='messages/fa/admin.php'
    raw=command('git','show',SERVER_REMOVAL_PARENT+':'+path)
    decode='$s=stream_get_contents(STDIN);$p=strpos($s,"return [");if($p===false)throw new RuntimeException("array_missing");echo json_encode(eval(substr($s,$p)),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);'
    values=flatten(json.loads(command('php','-r',decode,input=raw)))
    lines={};topic=None
    for line in raw.decode().splitlines():
        heading=re.match(r"^    '([^']+)'\s*=>\s*\[$",line)
        if heading:topic=heading.group(1);continue
        entry=re.match(r"^        '([^']+)'\s*=>",line)
        if entry and topic is not None:
            key=topic+'.'+entry.group(1)
            check(key not in lines,'server_removal_baseline_source_duplicate:'+key)
            lines[key]=line
    return values,lines

def server_removal_copy_restore(sides):
    record=json.loads((ROOT/'tools/text_catalog_server_removal_safety.json').read_text())
    entries=record.get('entries')
    check(record.get('version')==1 and
          set(record)=={'version','owner_issue','previous_candidate','entries','retained_unreferenced_keys'} and
          record.get('retained_unreferenced_keys')==[
              'bot.bot.addserver_name.alert.601504ae47',
              'bot.bot.addserver_name.message.756c3005d3',
              'bot.bot.servers_setting.alert.9f4e68eda7',
              'plans.administration.edit_custom_plan.alert.3064bbd794',
              'plans.administration.edit_custom_plan.message.b059640ccc'] and
          record.get('previous_candidate')==SERVER_REMOVAL_PARENT and
          record.get('owner_issue')=='https://github.com/ach1992/bot-refactor/issues/112' and
          type(entries) is dict and tuple(entries)==SERVER_REMOVAL_KEYS,
          'server_removal_catalog_identity')
    prior,original=server_removal_baseline()
    actual=shared_transfer_shipped_values()
    current=shared_transfer_source_lines('messages/fa/admin.php',False)
    result=copy.deepcopy(sides)
    for key,item in entries.items():
        check(type(item) is dict and
              set(item)=={'audience','before_sha256','after_sha256','reason'} and
              item['audience']=='admin' and bool(item['reason']) and
              key not in sides.get('user',{}),
              'server_removal_catalog_scope_or_audience:'+key)
        for lines,field in [(original,'before_sha256'),(current,'after_sha256')]:
            raw=lines.get(key)
            digest=hashlib.sha256(raw.encode()).hexdigest() if raw is not None else None
            check(digest==item[field] and (field!='after_sha256' or raw is not None),
                  'server_removal_catalog_source_drift:'+key)
        check(key in sides['admin'] and
              sides['admin'][key]==actual['admin'][key],
              'server_removal_catalog_copy_or_audience_drift:'+key)
        if key in prior:
            check(item['before_sha256'] is not None,
                  'server_removal_catalog_prior_identity:'+key)
            result['admin'][key]=prior[key]
        else:
            check(item['before_sha256'] is None,
                  'server_removal_catalog_added_identity:'+key)
            result['admin'].pop(key)
    return result

def server_removal_copy_negative_controls(sides):
    # A valid shipping catalog must pass BEFORE the mutations; otherwise a
    # permanently failing check would falsely prove every negative control.
    server_removal_copy_restore(sides)
    cases=[]
    missing=copy.deepcopy(sides);missing['admin'].pop(SERVER_REMOVAL_KEYS[2]);cases.append(missing)
    changed=copy.deepcopy(sides);changed['admin'][SERVER_REMOVAL_KEYS[0]]+=' changed';cases.append(changed)
    wrong=copy.deepcopy(sides);wrong['user'][SERVER_REMOVAL_KEYS[2]]=wrong['admin'].pop(SERVER_REMOVAL_KEYS[2]);cases.append(wrong)
    duplicated=copy.deepcopy(sides);duplicated['user'][SERVER_REMOVAL_KEYS[1]]=duplicated['admin'][SERVER_REMOVAL_KEYS[1]];cases.append(duplicated)
    for candidate in cases:
        try:server_removal_copy_restore(candidate)
        except AssertionError:continue
        raise AssertionError('server_removal_copy_negative_control_missed')
    return len(cases)

# Successor contract: remove experimental workbench copy in the installed
# catalogs while preserving ALL previously accepted user/admin copy. Historic
# proof inputs are restored ONLY in memory from an exact Git source snapshot.
CLEANUP_BASE='25f33731c568f9aa9a9c7137bd44032d2647e166'
CLEANUP_SOURCE='a732398116ffedbc82ec2037f99b1c7313bc1d54'
CLEANUP_TOPICS={
    'payment_review','delivery_recovery','finance_workbench',
    'finance_exceptions','service_workbench','service_recovery_action',
    'hosted_replacement','hosted_original_recovery',
    'service_edit_delivery','delivery_workbench',
}
CLEANUP_ORIGINAL_NEW_KEYS={
    'automatic_gateway_review.'+name for name in (
        'menu','provider_nextpay','provider_nowpayments','provider_zarinpal',
        'rejected_customer','rejected_report','result','status_completed',
        'status_rejected','status_stale','status_unavailable','status_unknown',
        'status_unsupported',
    )
} | {
    'manual_receipt_final.'+name for name in (
        'back','checking','edit_unverified','rejected_report','result',
        'status_completed','status_delivery_unconfirmed','status_rejected',
        'status_stale','status_unavailable','status_unknown',
    )
}

# Owner's archive decision: these are NEW bounded UI entries, not part of
# the exact historical 24-key delivery/review copy baseline.
CLEANUP_ARCHIVE_NEW_KEYS={
    'automatic_gateway_review.archive',
    'automatic_gateway_review.archive_notice',
    'automatic_gateway_review.status_archived',
}
CLEANUP_NEW_KEYS=CLEANUP_ORIGINAL_NEW_KEYS | CLEANUP_ARCHIVE_NEW_KEYS
@functools.lru_cache(maxsize=2)
def _cleanup_catalog(ref):
    php='$s=stream_get_contents(STDIN);$p=strpos($s,"return [");if($p===false)throw new RuntimeException("array_missing");echo json_encode(eval(substr($s,$p)),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);'
    return {audience:{
        topic+'.'+key:value for topic,group in json.loads(command('php','-r',php,
            input=command('git','show',ref+':messages/fa/'+audience+'.php'))).items()
        for key,value in group.items()} for audience in ('user','admin')}

def current_cleanup_restore(sides):
    if not (CLEANUP_NEW_KEYS & set(sides.get('admin',{}))):
        return sides
    check(set(sides)=={'user','admin'},'cleanup_audiences')
    original=_cleanup_catalog(CLEANUP_BASE)
    accepted=_cleanup_catalog(CLEANUP_SOURCE)
    check(sides['user']==original['user'],'cleanup_user_copy_changed')
    old=original['admin'];current=sides['admin']
    removed={key for key in old if key.split('.',1)[0] in CLEANUP_TOPICS}
    check(len(removed)==129 and
          set(old)-set(current)==removed and
          set(current)-set(old)==CLEANUP_NEW_KEYS and
          all(current.get(k)==v for k,v in old.items() if k not in removed),
          'cleanup_unapproved_catalog_change')
    check(set(accepted['admin'])-set(old)==CLEANUP_ORIGINAL_NEW_KEYS and
          all(current[k]==accepted['admin'][k] for k in CLEANUP_ORIGINAL_NEW_KEYS),
          'cleanup_new_copy_byte_drift')
    for key in CLEANUP_NEW_KEYS:
        entry=current[key];value=text(entry);parameters=params(entry)
        check(isinstance(value,str) and value.strip() and
              len(parameters)==len(set(parameters)) and
              set(parameters)==set(re.findall(
                  r'\{([A-Za-z_][A-Za-z0-9_]*)\}',value)) and
              not re.search(r'[\u06f0-\u06f9\u0660-\u0669]',value),
              'cleanup_new_template_invalid:'+key)
    # 25f3373 included an unreviewed intermediary gateway UI. Restore the
    # last independently reviewed RC26 catalog for *historical* byte tests;
    # the entire successor delta above is already checked against its exact
    # intermediary source and no old copy can silently drift.
    return copy.deepcopy(_cleanup_catalog(
        '50318bc3163bfa7496b3bc6dce8b1e789417334e'))

def verify_and_restore(sides,definition=None):
    sides=current_cleanup_restore(sides)
    if any(key.startswith('delivery_recovery.') for audience in sides.values() for key in audience):
        sides=delivery_recovery_copy_restore(sides)
    current=scoped_addon_restore(shared_transfer_copy_restore(server_removal_copy_restore(panel_group_copy_restore(sides))))
    sides,api_proof=verify_api_and_restore(current)
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
    verify_and_restore(sides,definition)
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

# #117: exact, additive, owner-private delivery-recovery copy. Old R5/RC25
# catalogs retain their exact hashes and approved copy baseline. The only
# source difference must be this explicitly enumerated 18-entry topic.
DELIVERY_RECOVERY_BASE='17af1e55dc4cb8db6751a731bb223d93dc6f902f'
DELIVERY_RECOVERY_BLOCK_SHA256='d10cb333f3576f5386a3f18226f569941a1488de7fae648822c507f8b1e9d4d8'
WORKBENCH_BLOCKS={
 'manual_receipt_final':{
  'sha':'3423d7b6837c1db79e0c1a6d8297f663d6244d5134c3522b98b3891b6749f1df',
  'names':{'day_subject','volume_subject'}
 },
 'finance_exceptions':{
  'sha':'2b1c0aa5e61b96967b184d5b7b2c090aa6704920c15999d4b72febccb7cbaf66',
  'names':{'open','primary_rejected','primary_refund_unverified','title','empty','case_label','case_title',
   'readonly_warning','child_line','inspect','more','back','back_finance',
   'next','first','checked','unavailable'}
 },
 'finance_workbench':{
  'sha':'ae4989180d45ed15f33e668a9d5e7111551fddd6b681bd10da526de670b233ea',
  'names':{'denied','checking','back','not_safe','challenge_expired',
   'unavailable','migration_required','preview_button','reject_button',
   'commit_approve','commit_reject','preview_approve','preview_reject',
   'rejected_customer','rejected_report','result','status_completed',
   'status_delivery_unconfirmed','status_rejected','status_unknown','status_unavailable','status_stale',
   'status_already','status_unsupported','approve_original','reject_original',
   'manual_evidence_warning','prior_decision'}
 },
 'service_workbench':{
  'sha':'feda16d173f3d99b91d9544715c170d4c978a5763e02ffd1164a4e7dc75dbaf8',
  'names':{'title','empty','case_label','case_title','readonly_warning','open',
   'back','back_finance','next','first','checked','unavailable'}
 },
 'service_recovery_action':{
  'sha':'37c23110bf7b6696eef5b2c8a552d9bccb519ac830a0094aa876adb57a656360',
  'names':{'resume_purchase','resume_warning','resume_verified_edit','edit_warning','edit_unverified'}
 },
 # #117 additive new original-HOSTED first-time CREATE guidance.
 # Source bytes are independently pinned; all pre-existing sections and
 # prior Owner-approved translations remain byte-for-byte unchanged.
 'hosted_replacement':{
  'sha':'71b48a3375bde58af787cbbe1b9f670ebaf6341ca1650e5b24b6842a0ec6102e',
  'names':{'inspect','review_note','warning','confirm','reserved','unavailable','diagnostic'}
 },
 'hosted_original_recovery':{
  'sha':'f442322538c4ebf091f8ca914667a046ad3ba7431a094de4cb794f25d6574394',
  'names':{'resume_unstarted','unstarted_warning','started'}
 },
 'service_edit_delivery':{
  'sha':'32355d7bacf05d19b252482f21c2ea382723a63f44ddb7fb3e89c9e24fa9a8fa',
  'names':{'header','line','more','warning'}
 },
 'delivery_workbench':{
  'sha':'0ac67e667fe6669be3a8dae1a90de94dc7347dcb6bc129052f61d190bbf144f3',
  'names':{'inbox_title','inbox_empty','inbox_case','open_inbox',
    'native_inbox','back_finance','back_inbox'}
 }
}
DELIVERY_RECOVERY_NAMES={
    'title','summary','line','warning','check_result','send_result',
    'check_ack','check','request_send','send_confirm','back','back_inbox',
    'next','first','missing','not_eligible','unavailable','open'
}
def delivery_recovery_original_bytes(current:bytes) -> bytes:
    import re
    block=re.search(rb"(?ms)^    'delivery_recovery' => \[\n.*?^    \],\n",current)
    check(block is not None and
          hashlib.sha256(block.group()).hexdigest()==DELIVERY_RECOVERY_BLOCK_SHA256,
          'delivery_recovery_exact_additive_block_changed')
    original=current[:block.start()]+current[block.end():]
    for topic,proof in WORKBENCH_BLOCKS.items():
        pattern=(rb"(?ms)^    '"+topic.encode()+rb"' => \[\n.*?^    \],\n")
        matches=list(re.finditer(pattern,original))
        check(len(matches)==1 and hashlib.sha256(matches[0].group()).hexdigest()==proof['sha'],
              'workbench_exact_additive_source_identity:'+topic)
        original=original[:matches[0].start()]+original[matches[0].end():]
    check(original==command('git','show',DELIVERY_RECOVERY_BASE+':messages/fa/admin.php'),
          'delivery_recovery_preexisting_catalog_bytes_changed')
    return original
def delivery_recovery_copy_restore(sides:dict) -> dict:
    # Historical proof may be called directly by a sibling suite; verify
    # the present successor delta first, then restore old approved evidence.
    sides=current_cleanup_restore(sides)
    # This feature was intentionally removed; its old approved copy must
    # still match the exact immutable published-source evidence.
    present=(ROOT/'messages/fa/admin.php').read_bytes()
    archived=command('git','show','50318bc3163bfa7496b3bc6dce8b1e789417334e:messages/fa/admin.php')
    if b"'delivery_recovery' =>" not in present:
        check(b"'delivery_recovery' =>" in archived,
              'retired_delivery_baseline_missing')
        delivery_recovery_original_bytes(archived)
    else:
        delivery_recovery_original_bytes(present)
    check(set(sides)=={'user','admin'},'delivery_recovery_catalog_audiences')
    restored=copy.deepcopy(sides)
    for topic,proof in WORKBENCH_BLOCKS.items():
        keys={topic+'.'+name for name in proof['names']}
        found={key for key in restored['admin'] if key.startswith(topic+'.')}
        check(found==keys and not any(k.startswith(topic+'.') for k in restored['user']),
              'workbench_exact_owner_only_copy:'+topic)
        for key in keys:
            entry=restored['admin'][key];value=text(entry)
            check(isinstance(value,str) and value.strip() and
                  isinstance(params(entry),list) and
                  len(params(entry))==len(set(params(entry))) and
                  set(re.findall(r'\{([a-zA-Z_][a-zA-Z0-9_]*)\}',value))==set(params(entry)) and
                  not re.search(r'[\u06f0-\u06f9\u0660-\u0669]',value),
                  'workbench_copy_parameters_or_digits:'+key)
            restored['admin'].pop(key)
    sides=restored
    expected={'delivery_recovery.'+name for name in DELIVERY_RECOVERY_NAMES}
    actual={key for key in sides['admin'] if key.startswith('delivery_recovery.')}
    check(actual==expected and not any(key.startswith('delivery_recovery.')
        for key in sides['user']),'delivery_recovery_exact_owner_copy_inventory')
    for key in expected:
        entry=sides['admin'][key]
        value=text(entry)
        check(isinstance(value,str) and len(value)>0 and
              isinstance(params(entry),list) and
              len(params(entry))==len(set(params(entry))) and
              set(re.findall(r'\{([a-zA-Z_][a-zA-Z0-9_]*)\}',value))==set(params(entry)) and
              not re.search(r'[\u06f0-\u06f9\u0660-\u0669]',value),
              'delivery_recovery_exact_copy_slots_or_digits:'+key)
    for key in ['title','summary','line','warning','check_result','send_result',
                'check_ack','check','request_send','send_confirm','back_inbox','next',
                'first','missing','not_eligible','unavailable','open']:
        # Verify the original route/source binding in the archived source,
        # not a non-existent runtime file (restoration is proof-only).
        historic=command('git','show',
            '50318bc3163bfa7496b3bc6dce8b1e789417334e:src/Finance/Routes/DeliveryRecovery.php').decode()
        historic_receipts=command('git','show',
            '50318bc3163bfa7496b3bc6dce8b1e789417334e:src/Finance/Routes/ManualReceipts.php').decode()
        check("'delivery_recovery."+key+"'" in historic or
              "'delivery_recovery."+key+"'" in historic_receipts or
              (key=='back_inbox' and "'delivery_workbench.back_inbox'" in historic),
              'retired_delivery_route_original_binding:'+key)
    restored=copy.deepcopy(sides)
    for key in actual:restored['admin'].pop(key)
    return restored
def delivery_recovery_copy_negative_controls(sides:dict) -> int:
    delivery_recovery_copy_restore(sides)
    cases=[]
    bad=copy.deepcopy(sides);bad['admin'].pop('delivery_recovery.title');cases.append(bad)
    bad=copy.deepcopy(sides);bad['user']['delivery_recovery.open']=bad['admin'].pop('delivery_recovery.open');cases.append(bad)
    bad=copy.deepcopy(sides);bad['admin']['delivery_recovery.extra']='Injected';cases.append(bad)
    bad=copy.deepcopy(sides);bad['admin']['delivery_recovery.title']['parameters'].pop();cases.append(bad)
    for candidate in cases:
        try:delivery_recovery_copy_restore(candidate)
        except AssertionError:continue
        raise AssertionError('delivery_recovery_copy_negative_control_missed')
    return len(cases)

# The approved Chili RC25 hot-copy is a *specific* 83-value revision on top
# of main, not a blanket exception to the historical whole-audience checks.
# Both Git objects and shipping catalog bytes are verified; restore is only
# in-memory for the older immutable proofs and never edits a user template.
CHILI_COPY_BASE='ed0338baa3b006759ef9fdab66ca6e68defdc9ef'
CHILI_COPY_HEAD='4c4004d9b85bc6b1c7297788675d0e917c1f26d0'
CHILI_COPY_FILES={
    'user':'ebc2195d535cc3f3b1cd233ac04b2ef7db22dd6c2a4d8d1ac399f085fdf086e1',
    'admin':'0c3e8afb70387969bc7cddc286644d8ce6798e65c4116050c77beccbb1c79b62',
}
@functools.lru_cache(maxsize=1)
def chili_copy_values():
    decode='$s=stream_get_contents(STDIN);$p=strpos($s,"return [");if($p===false)throw new RuntimeException("catalog_missing");echo json_encode(eval(substr($s,$p)),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);'
    before={};after={};changed={}
    for audience,digest in CHILI_COPY_FILES.items():
        path='messages/fa/'+audience+'.php'
        raw=command('git','show',CHILI_COPY_HEAD+':'+path)
        current=(ROOT/path).read_bytes()
        if audience=='admin':
            # #117 has one separately pinned additive topic. Removing only
            # that exact byte block must restore the approved, historical
            # RC25 source byte for byte; no preexisting copy can drift.
            # Current successor removed these 019/021 workbenches. Their
            # historical byte proof is over immutable published RC26 source,
            # while current catalog scope is independently pinned above.
            if b"'delivery_recovery' =>" not in current:
                current=command('git','show',
                    '50318bc3163bfa7496b3bc6dce8b1e789417334e:'+path)
            current=delivery_recovery_original_bytes(current)
        check(hashlib.sha256(raw).hexdigest()==digest and
              hashlib.sha256(current).hexdigest()==digest,
              'chili_copy_exact_source_identity:'+path)
        old=command('git','show',CHILI_COPY_BASE+':'+path)
        before[audience]=flatten(json.loads(command('php','-r',decode,input=old)))
        after[audience]=flatten(json.loads(command('php','-r',decode,input=raw)))
        check(set(before[audience])==set(after[audience]),
              'chili_copy_catalog_inventory:'+audience)
        changed[audience]={key for key in before[audience]
            if before[audience][key]!=after[audience][key]}
    check(sum(map(len,changed.values()))==83 and
          len(changed['user'])==61 and len(changed['admin'])==22,
          'chili_copy_exact_value_change_count')
    return before,after,changed
def chili_copy_restore(sides):
    before,after,changed=chili_copy_values()
    # An all-before input comes from a caller that already restored the
    # approved layer; a mixed before/after copy is never a valid revision.
    current=all(sides.get(a,{}).get(k)==after[a][k]
                for a in changed for k in changed[a])
    old=all(sides.get(a,{}).get(k)==before[a][k]
            for a in changed for k in changed[a])
    check(current or old,'chili_copy_value_or_audience_drift')
    restored=copy.deepcopy(sides)
    if current:
        for a in changed:
            for k in changed[a]:restored[a][k]=before[a][k]
    return restored

# Current API copy is a separately reviewed additions-only layer. The original
# R5 baseline and its amendment file remain byte-for-byte historical evidence.
API_BASE='2d0ad0826d594adac49f5a83f0a9d47f6df5b264'
API_RESULT='df00c13da1e38e2ba364ab48e31db66f8705b2c2'
API_COMMITS=['2633ac3c21c539635c228dd31df2e07caee491d0',
             '2c685f5a9621a808c44a4c012b04c8ccd97102af',API_RESULT]
API_AUTH='https://github.com/ach1992/bot-refactor/issues/57#issuecomment-6514754219'
REVIEW_SOURCE='16c1558322b578b8ca817f3c4b42818d57dcf161'
API_RETAINED={'payment.api_payment.completed',
              'manual_payment.api_payment.review_staff','manual_payment.api_payment.automatic_status',
              'manual_payment.api_payment.status_monitoring'}
def api_manifest():return json.loads((ROOT/'tools/text_catalog_owner_r6_api.json').read_text())
@functools.lru_cache(maxsize=None)
def api_catalog_snapshot(ref):
    check(ref in [API_BASE,*API_COMMITS,REVIEW_SOURCE],'api_catalog_snapshot_identity')
    decode='$s=stream_get_contents(STDIN);$p=strpos($s,"return [");if($p===false)throw new RuntimeException("array_missing");echo json_encode(eval(substr($s,$p)),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);'
    return {a:flatten(json.loads(command('php','-r',decode,input=command('git','show',ref+':messages/fa/'+a+'.php'))))for a in ['user','admin']}
# One explicit Owner gift-report addition precedes the unchanged API/R5 layers.
# Strip this exact key only; the API layer still compares the WHOLE remainder.
GIFT_REPORT_AUTH='https://github.com/ach1992/bot-refactor/issues/57#issuecomment-6521312639'
GIFT_REPORT_KEY='gifts.completed_report'
GIFT_REPORT_ENTRY={'text': '🎁 هدیهٔ اشتراک اعمال شد\n🔖 شناسه عملیات: <code>{reference}</code>\n👤 اشتراک: <code>{remark}</code>\n📅 زمان هدیه: {days} روز\n📦 حجم هدیه: {volume_mb} مگابایت\n👥 ثبت\u200cهای مرتبط: {reference_count}\n✉️ کاربران مرتبط: {recipient_count}\n⚠️ پیام\u200cهای بدون تأیید ارسال: {notice_unconfirmed_count}', 'parameters': ['reference', 'remark', 'days', 'volume_mb', 'reference_count', 'recipient_count', 'notice_unconfirmed_count']}
def gift_catalog_restore(sides):
    accepted=api_catalog_snapshot(API_RESULT)
    check(all(GIFT_REPORT_KEY not in rows for rows in accepted.values()),
          'gift_report_key_existed_in_api_baseline')
    check(GIFT_REPORT_KEY not in sides['user'] and
          sides['admin'].get(GIFT_REPORT_KEY)==GIFT_REPORT_ENTRY,
          'gift_report_value_parameter_or_audience_drift')
    restored=copy.deepcopy(sides)
    restored['admin'].pop(GIFT_REPORT_KEY)
    return restored,{'gift_added_admin_keys':1,'gift_catalog_baseline_keys':2771,
        'gift_catalog_current_keys':sum(map(len,sides.values())),
        'gift_report_parameter_count':len(GIFT_REPORT_ENTRY['parameters']),
        'gift_report_authorization':GIFT_REPORT_AUTH}
def gift_catalog_negative_controls(sides):
    # Positive admission first prevents an unrelated legitimate addition from
    # making every rejection a false-positive proof.
    verify_and_restore(sides)
    controls=[]
    bad=copy.deepcopy(sides);bad['admin'].pop(GIFT_REPORT_KEY);controls.append(bad)
    bad=copy.deepcopy(sides);bad['user'][GIFT_REPORT_KEY]=bad['admin'].pop(GIFT_REPORT_KEY);controls.append(bad)
    bad=copy.deepcopy(sides);bad['admin'][GIFT_REPORT_KEY]['text']+=' changed';controls.append(bad)
    bad=copy.deepcopy(sides);bad['admin'][GIFT_REPORT_KEY]['parameters'].pop();controls.append(bad)
    bad=copy.deepcopy(sides);bad['admin'][GIFT_REPORT_KEY]['parameters'].reverse();controls.append(bad)
    bad=copy.deepcopy(sides);bad['admin']['gifts.unapproved']='changed';controls.append(bad)
    bad=copy.deepcopy(sides);bad['user'][GIFT_REPORT_KEY]=copy.deepcopy(bad['admin'][GIFT_REPORT_KEY]);controls.append(bad)
    bad=copy.deepcopy(sides);bad['user']['legacy_messages.banned']='changed';controls.append(bad)
    for candidate in controls:
        try:verify_and_restore(candidate)
        except AssertionError:continue
        raise AssertionError('gift_catalog_negative_control_missed')
    return len(controls)
def review_catalog_restore(sides):
    before=api_catalog_snapshot(API_RESULT);after=api_catalog_snapshot(REVIEW_SOURCE)
    keys={'user':{'payment.api_payment.code_acknowledgement','payment.api_payment.code_too_long'},'admin':{'manual_payment.api_payment.auto_approved','manual_payment.api_payment.code_evidence','manual_payment.api_payment.code_used','manual_payment.api_payment.code_revoked','manual_payment.api_payment.code_expired','manual_payment.api_payment.code_mismatch'}}
    restored=copy.deepcopy(sides)
    for audience,added in keys.items():
        # The gift-report layer is independently admitted below.
        check(set(after[audience])-set(before[audience])==added|({'gifts.completed_report'}if audience=='admin'else set()),'review_catalog_scope_drift')
        for key in added:
            check(sides[audience].get(key)==after[audience][key] and key not in sides['admin'if audience=='user'else'user'],'review_catalog_value_parameters_or_audience_drift:'+key)
            restored[audience].pop(key)
    return restored,{'review_added_keys':8,'review_added_user_keys':2,'review_added_admin_keys':6,'review_source':REVIEW_SOURCE}

def verify_api_and_restore(sides,definition=None):
    sides=current_cleanup_restore(sides)
    sides=current_cleanup_restore(sides)
    if any(key.startswith('delivery_recovery.') for audience in sides.values() for key in audience):
        sides=delivery_recovery_copy_restore(sides)
    # Historical composition proofs enter directly at this API layer. They
    # must first undo the exact #112 copy delta, while the normal R5 pipeline
    # has already restored its historical inheritance key.
    if ('admin_settings.management.server_groups' in sides.get('admin',{}) and
        'admin_settings.management.inherit_groups' not in sides['admin']):
        if 'admin_settings.management.server_retired' in sides['admin']:
            sides=server_removal_copy_restore(sides)
        sides=shared_transfer_copy_restore(sides)
    sides=scoped_addon_restore(sides) if any(key.startswith('addon_scopes.')
        for key in sides.get('admin',{})) else sides
    sides=chili_copy_restore(sides)
    sides,review_proof=review_catalog_restore(sides)
    sides,gift_proof=gift_catalog_restore(sides)
    record=api_manifest()if definition is None else definition
    check(record.get('version')==1 and record.get('baseline_commit')==API_BASE
          and record.get('result_catalog_commit')==API_RESULT and record.get('accepted_commits')==API_COMMITS
          and record.get('authorization')==API_AUTH and record.get('scope')=='additions_only'
          and record.get('source_binding_commit')==API_SOURCE,
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
              (key not in API_RETAINED and usage in ['settings_source','flow_source'] and paths),
              'api_catalog_usage_identity:'+key)
        expected_paths=[path for path in API_REFERENCE_PATHS if key in api_source_references(path,api_consumer_snapshot(path))]
        check(paths==expected_paths,'api_catalog_actual_source_binding:'+key)
        check(isinstance(text(value),str) and isinstance(params(value),list) and
              len(set(params(value)))==len(params(value)) and
              set(re.findall(r'\{([A-Za-z_][A-Za-z0-9_]*)\}',text(value)))==set(params(value)),
              'api_catalog_parameters:'+key)
        check(not re.search(r'[\u06f0-\u06f9\u0660-\u0669]',text(value)),'api_catalog_non_ascii_digits:'+key)
    # Compare the complete catalog, not a prefix-filtered projection. Unknown
    # additions and changes to the earlier layers remain hard failures.
    check(sides==accepted,'api_undeclared_catalog_key_value_parameter_or_recipient')
    return copy.deepcopy(before),{'api_added_keys':len(delta),'api_added_user_keys':22,'api_added_admin_keys':64,
        'api_baseline_keys':2685,'api_current_keys':2771,'api_retained_unreferenced_keys':len(API_RETAINED),**gift_proof,**review_proof}
def api_negative_controls(sides):
    definition=api_manifest();key='payment.api_payment.bank_invoice';controls=[]
    verify_api_and_restore(sides,definition)
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

# Dynamic lookups are resolved only for these exact adopted consumer sources.
# This is not a prefix exemption: source bytes and every expanded key are pinned.
API_SOURCE='67aba2527f58c0efd844b3c52cd2469143fe9113'
API_FIELDS=['smsApiUrl','smsApiToken','smsApiHorizonHours','smsApiProvider','smsApiSender',
            'smsApiMobileSource','smsApiRuleId','smsApiBankKey','smsApiTimezone','smsApiCalendar',
            'giftApiUrl','giftApiKeyId','giftApiSecret','giftApiAmountUnit','giftApiGameKey','giftApiValueId']
API_DYNAMIC={
 'src/Finance/PaymentApiAdmin.php':{
    'payment_settings.api.':['payment_settings.api.'+s for s in ['sms_verify','gift_verify','sms_settings','gift_settings','sms_title','gift_title','sms_guidance','gift_guidance']],
    'payment_settings.api.field.':['payment_settings.api.field.'+f for f in API_FIELDS],
    'payment_settings.api.hint.':['payment_settings.api.hint.'+f for f in API_FIELDS]},
 'src/Finance/ApiPayments.php':{
    'payment.api_payment.':['payment.api_payment.'+s for s in ['code','check','code_prompt','used','revoked','expired','mismatch','code_received','review','pending','receipt_review','bank_fallback','bank_pending']],
    'manual_payment.api_payment.status_':['manual_payment.api_payment.status_'+s for s in ['confirmed','settled','review','uncertain','evidence_only']]},
 'src/Finance/Routes/ApiPayments.php':{
    'payment.api_payment.':['payment.api_payment.'+s for s in ['confirmed','review','unavailable','pending','bank_pending','locked_code','single_code','used','revoked','expired','mismatch']]}
}
API_CONDITIONAL_REFS={'src/Finance/PaymentApiAdmin.php':[
    'payment_settings.api.configured','payment_settings.api.incomplete',
    'payment_settings.api.complete_guidance','payment_settings.api.incomplete_guidance']}
API_REFERENCE_PATHS=[*API_DYNAMIC,'bot.php','src/Finance/ReceiptHelpers.php']
@functools.lru_cache(maxsize=None)
def api_consumer_snapshot(path,ref=API_SOURCE):
    check(path in API_REFERENCE_PATHS,'api_consumer_source_identity')
    check(ref in [API_SOURCE,REVIEW_SOURCE],'api_consumer_revision_identity')
    return command('git','show',ref+':'+path).decode()
REVIEW_DYNAMIC={
 'src/Finance/PaymentApiAdmin.php':API_DYNAMIC['src/Finance/PaymentApiAdmin.php'],
 'src/Finance/ApiPayments.php':{'manual_payment.api_payment.code_':['manual_payment.api_payment.code_'+s for s in ['used','revoked','expired','mismatch']],'manual_payment.api_payment.status_':['manual_payment.api_payment.status_'+s for s in ['confirmed','settled','review','uncertain','evidence_only']]},
 'src/Finance/Routes/ApiPayments.php':{},
}
# Issue #107 changes an API payment *eligibility fence*, not any accepted copy.
# Preserve the old dynamic-message source proof byte-for-byte by admitting only
# this explicit non-copy insertion; arbitrary edits still fail source binding.
# Issue #117: the new receipt/report presenter adds only these exact
# non-catalog source fragments, preserving every established literal and
# dynamic catalog key. Removing the complete recognized delta must yield the
# prior byte-pinned consumer; a partial, arbitrary or changed hook fails.
# Issue #117 Owner final acceptance: allow only TWO source-exact deltas:
# quiet pull-only review (no pushed report) and manual-copy/ACK-aware
# customer delivery. Each pair is fully reversible to the independently
# pinned PR #120 source; all original provenance checks stay enabled.
API_OWNER117_CHANGE_1_AFTER = """    // Review details remain available from the original evidence/report
    // presenter. Owner opens the review workbench voluntarily: a background
    // provider poll must not send additional standalone admin messages.
    // Original receipt keyboards are still edited in place below.
"""
API_OWNER117_CHANGE_1_BEFORE = """    $digest=hash('sha256',$body);
    if($report && ($a['evidence_report_digest']??null)!==$digest && function_exists('achbotNotifyAdmins')) {
        try {
            $results=achbotNotifyAdmins('sendMessage',['text'=>$body,'parse_mode'=>'HTML']);$ack=count($results)>0;
            foreach($results as $result)if(!achbotApiPaymentAck($result['response']??null))$ack=false;
            if($ack){$q=$db->prepare('UPDATE payment_attempts SET evidence_report_digest=? WHERE pay_row_id=?');$q->bind_param('si',$digest,$id);$q->execute();$q->close();}
        }catch(Throwable $e){error_log('achbot_api_report_delivery_deferred pay_id='.$id);}
    }
"""
API_OWNER117_CHANGE_2_AFTER = """            achbotReferralAfterCompletedPurchase($db,(int)$pay['user_id'],$id,(int)($GLOBALS['admin']??0),$GLOBALS['botState']??[]);
            $deliveryPackets=$delivery['deliveries'];
        } elseif($type!=='INCREASE_WALLET') {
            if (!achbotApiPaymentFulfillEdit($db,$pay,$proof)) return 'review';
        }
        $fresh=achbotApiPaymentRow($db,$id);if(!$fresh || !achbotApiPaymentTerminal($fresh))return 'review';
        // Durable delivery fence precedes every fallible Telegram call.
        // A lost response, process crash, or unconfirmed QR is never a reason
        // to replay payment, capacity, CREATE or native account edits.
        $claim=$db->prepare("UPDATE payment_attempts SET evidence_fulfillment_state='delivery_dispatching'
            WHERE pay_row_id=? AND (evidence_fulfillment_state IS NULL OR
              evidence_fulfillment_state IN ('reserved','verified'))");
        $claim->bind_param('i',$id);
        $claimed=$claim->execute() && $claim->affected_rows===1;$claim->close();
        if(!$claimed)return 'review';
        $deliveryAcknowledged=true;
        if($type==='BUY_SUB') {
            $deliveryAcknowledged=!empty($deliveryPackets);
            foreach(($deliveryPackets??[]) as $index=>$packet) {
                try {
                    if(sendQrFromLink($packet['qr'],$packet['caption'],$packet['keys'],
                        (string)($GLOBALS['botUrl']??''),(int)$packet['uid'])!==true) {
                        $deliveryAcknowledged=false;
                        error_log('achbot_api_paid_delivery_unconfirmed pay_id='.$id.' line='.($index+1));
                    }
                } catch(Throwable $error) {
                    $deliveryAcknowledged=false;
                    error_log('achbot_api_paid_delivery_exception pay_id='.$id.' line='.($index+1).
                        ' class='.get_class($error));
                }
            }
        } else {
            // The funds/native edit have already committed. Customer copy is
            // identical to the successful manual path for the same subject.
            $deliveryAcknowledged=achbotApiPaymentDeliverManualCustomer($db,$fresh);
        }
        $deliveryState=$deliveryAcknowledged?'done':'delivery_unconfirmed';
        $done=$db->prepare("UPDATE payment_attempts SET evidence_fulfillment_state=?
            WHERE pay_row_id=? AND evidence_fulfillment_state='delivery_dispatching'");
        $done->bind_param('si',$deliveryState,$id);
        $marked=$done->execute() && $done->affected_rows===1;$done->close();
        if(!$marked)return 'review';
        achbotApiPaymentNotice($db,$fresh,$deliveryAcknowledged?'confirmed':'settled');
        return $deliveryAcknowledged?'confirmed':'settled';
"""
API_OWNER117_CHANGE_2_BEFORE = """            achbotReferralAfterCompletedPurchase($db,(int)$pay['user_id'],$id,(int)($GLOBALS['admin']??0),$GLOBALS['botState']??[]);
            foreach($delivery['deliveries'] as $packet)try{sendQrFromLink($packet['qr'],$packet['caption'],$packet['keys'],(string)($GLOBALS['botUrl']??''),(int)$packet['uid']);}catch(Throwable $e){error_log('achbot_api_paid_delivery_review pay_id='.$id);}
        } elseif($type!=='INCREASE_WALLET') {
            if (!achbotApiPaymentFulfillEdit($db,$pay,$proof)) return 'review';
        }
        $fresh=achbotApiPaymentRow($db,$id);if(!$fresh || !achbotApiPaymentTerminal($fresh))return 'review';
        $done=$db->prepare("UPDATE payment_attempts SET evidence_fulfillment_state='done' WHERE pay_row_id=?");$done->bind_param('i',$id);$done->execute();$done->close();
        achbotApiPaymentNotice($db,$fresh,'confirmed');
        try{sendMessage(achbotMessage('payment.api_payment.confirmed'),null,'HTML',(int)$pay['user_id']);}catch(Throwable $e){}
        return 'confirmed';
"""

API_RECEIPT_PRESENTATION_DIFF = [
    (API_OWNER117_CHANGE_1_AFTER, API_OWNER117_CHANGE_1_BEFORE),
    (API_OWNER117_CHANGE_2_AFTER, API_OWNER117_CHANGE_2_BEFORE),
    ("require_once __DIR__.'/ApiPaymentPresentation.php';\n", ""),
    ("    $presentation=achbotApiPaymentPresentation($db,$pay,$a);\n", ""),
    ("'reference'=>$hash.' (#'.$id.')'", "'reference'=>$hash"),
    ("""        'subject'=>payTypeToDescription((string)$pay['type']).
            ($presentation['details']==='' && $presentation['subject']!==payTypeToDescription((string)$pay['type'])
                ?' | '.$presentation['subject']:''),'status'=>$label]);""",
     """        'subject'=>payTypeToDescription((string)$pay['type']),'status'=>$label]);"""),
    ("    $body=achbotApiPaymentRichReport($pay,$body,$presentation);\n", ""),
    ("    if(achbotApiPaymentTerminal($pay))$keys=achbotApiPaymentFinalKeyboard($pay,$label,$automatic,$presentation);\n", ""),
]
SCOPED_ADDON_API_INSERTION="""            if ($type!=='RENEW_ACCOUNT') {
                require_once __DIR__.'/../Plans/Addons.php';
                if (achbotAddonSchema($db) && !achbotAddonPayLocal($db,$pay,$order)) return null;
                $context['addon_scope_snapshot']=(string)($pay['addon_scope_snapshot']??'');
            }
"""
# Reviewer F3 fix: explicitly reverse ONLY the two scoped non-copy
# final-local-claim policy edits before requiring the immutable original
# dynamic-message consumer source. Neither arbitrary PHP nor copy edits pass.
FINAL_COMMERCE_POLICY_INSERTION="""        // A committed processing claim is recovery evidence, NOT a new
        // authorization to charge or mutate an account. New claims must obey
        // the CURRENT locked server policy even if remote preflight passed.
        $alreadyClaimed=($pay['state']??'')==='processing' &&
            (int)($pay['approved_by']??-1)===0;
        if (!$alreadyClaimed && ((int)$info['active']!==1 ||
            ($type==='BUY_SUB' ? (int)$info['state']!==1 :
                !in_array((int)$info['state'],[1,2],true))))return null;
"""
FINAL_COMMERCE_IDENTITY_BEFORE="""        $context['server_identity']=array_intersect_key($info,array_flip(['id','panel_group_id','provider_group_ids']));"""
FINAL_COMMERCE_IDENTITY_AFTER="""        // Bind preflight to the actual commerce policy; a state flip after
        // native HTTP invalidates this fingerprint at the final SQL claim.
        $context['server_identity']=array_intersect_key($info,array_flip(
            ['id','panel_group_id','provider_group_ids','active','state']));"""
OWNER_REV4_API_PREVIOUS="if (!$plan || (int)$plan['active']!==1 || (int)$plan['inbound_id']!==0 ||"
OWNER_REV4_API_GUARD="if (!$plan || ($type!=='RENEW_ACCOUNT' && (int)$plan['active']!==1) ||\n            (int)$plan['inbound_id']!==0 ||"
def api_source_references(path,source):
    refs=set(re.findall(r"\\?achbotMessage(?:Html)?\('([^']+)'",source))
    if path in API_DYNAMIC:
        # Owner Rev4 replaces exactly one pre-issuance plan-visibility guard
        # for an existing RENEW_ACCOUNT. Undo only that non-copy delta before
        # checking the same pinned API source and previously accepted #107 insertion.
        audited=source
        if path=='src/Finance/ApiPayments.php':
            # The only surviving API recovery predicate has moved from its
            # retired workbench into the API module. Verify exact original
            # source bytes first; do not accept any unrelated API drift.
            moved='/** Display-only proof of a safe original API continuation;'
            if moved in audited:
                original=command('git','show',
                    '25f33731c568f9aa9a9c7137bd44032d2647e166:src/Finance/RecoveryInspection.php').decode()
                source_begin=original.index(moved)
                source_end=original.index('/** Payment recording and fulfillment',source_begin)
                expected=original[source_begin:source_end].strip().replace(
                    'achbotRecoveryApiCanResume','achbotApiPaymentCanResume')
                begin=audited.index(moved)
                end=audited.index('function achbotApiPaymentReady(',begin)
                check(audited[begin:end].strip()==expected,
                    'api_resume_extracted_exact_historical_source')
                audited=audited[:begin]+audited[end:]
            # Preserve the original #117 source proof and explicitly pin
            # the reviewed interrupted-BUY_SUB continuation delta. Neither
            # changes the catalog's historical dynamic-message inventory.
            # New or modified consumers are rejected until given an exact
            # independent source identity, including in negative controls.
            if hashlib.sha256(source.encode()).hexdigest() in {
                '811aa2c126a30cc156e2b25055479fa729f3d277a9a5aff71e8f484142714a98',
                '82ab48ab2e18ca7e48e67349b132df8ad77170095911dc9461a0c03b062a3208',
            }:
                baseline=command('git','show',
                    '17af1e55dc4cb8db6751a731bb223d93dc6f902f:src/Finance/ApiPayments.php')
                check(hashlib.sha256(baseline).hexdigest()==(
                    '89cd59037b234327fd05eb4531ad855480376ea51d13d43e3cfee07b60cd5d78'),
                    'issue117_previous_api_consumer_source_identity_drift')
                audited=baseline.decode()
            if any(fragment in audited for fragment,_ in API_RECEIPT_PRESENTATION_DIFF):
                for fragment,_ in API_RECEIPT_PRESENTATION_DIFF:
                    check(audited.count(fragment)==1,
                          'api_receipt_presentation_incomplete_or_duplicate')
                for before,after in API_RECEIPT_PRESENTATION_DIFF:
                    audited=audited.replace(before,after,1)
            # Full-content equality below fails closed if any edit falls
            # outside these independently exact source-text allowances.
            if (audited.count(FINAL_COMMERCE_POLICY_INSERTION)==1 and
                audited.count(FINAL_COMMERCE_IDENTITY_AFTER)==1):
                audited=audited.replace(FINAL_COMMERCE_POLICY_INSERTION,'',1)
                audited=audited.replace(FINAL_COMMERCE_IDENTITY_AFTER,
                    FINAL_COMMERCE_IDENTITY_BEFORE,1)
            if audited.count(OWNER_REV4_API_GUARD)==1:
                audited=audited.replace(OWNER_REV4_API_GUARD,OWNER_REV4_API_PREVIOUS,1)
        original=audited==api_consumer_snapshot(path)
        reviewed=audited==api_consumer_snapshot(path,REVIEW_SOURCE)
        if path=='src/Finance/ApiPayments.php' and not original and not reviewed:
            reviewed=(audited.count(SCOPED_ADDON_API_INSERTION)==1 and
                audited.replace(SCOPED_ADDON_API_INSERTION,'')==api_consumer_snapshot(path,REVIEW_SOURCE))
        check(original or reviewed,'api_dynamic_consumer_source_drift:'+path)
        for key in API_CONDITIONAL_REFS.get(path,[]):
            check(repr(key) in source,'api_conditional_literal_missing:'+path+':'+key)
            refs.add(key)
        for literal,expanded in (API_DYNAMIC if original else REVIEW_DYNAMIC)[path].items():
            check(literal in refs,'api_dynamic_literal_missing:'+path+':'+literal)
            refs.remove(literal);refs.update(expanded)
    return refs
API_REVIEW_RETAINED={'manual_payment.api_payment.review_staff', 'payment.api_payment.bank_invoice', 'manual_payment.api_payment.automatic_status', 'payment.api_payment.bank_fallback', 'payment.api_payment.expired', 'payment.api_payment.receipt_review', 'manual_payment.api_payment.typed_evidence', 'payment.api_payment.single_code', 'payment.api_payment.revoked', 'manual_payment.api_payment.status_monitoring', 'payment.api_payment.receipt', 'payment.api_payment.mismatch', 'payment.api_payment.used', 'payment.api_payment.check', 'payment.api_payment.bank_pending', 'payment.api_payment.completed', 'payment.api_payment.review', 'payment.api_payment.locked_code'}
def api_reference_proof(sources,entries):
    check(set(API_DYNAMIC)<=set(sources),'api_consumer_source_missing')
    refs=set()
    for path,source in sources.items():refs.update(api_source_references(path,source))
    check(refs<=set(entries),'unknown_catalog_reference:'+str(sorted(refs-set(entries))))
    added=set(api_manifest()['added_keys'])
    check(added-refs==API_REVIEW_RETAINED,'api_unreferenced_key_inventory_drift:'+str(sorted(added-refs)))
    return refs,{'api_referenced_keys':len(added&refs),'api_dynamic_source_paths':2,
                 'api_reference_source_commit':REVIEW_SOURCE,'api_retained_unreferenced_keys':len(API_REVIEW_RETAINED)}
def api_reference_negative_controls(sources,entries):
    controls=[]
    path='src/Finance/ApiPayments.php'
    bad=dict(sources);bad[path]+="\nachbotMessage('payment.api_payment.unapproved');";controls.append(bad)
    bad=dict(sources);bad[path]=bad[path].replace("'confirmed'","'unapproved_state'");controls.append(bad)
    bad=dict(sources);bad['unapproved.php']="<?php achbotMessage('payment.api_payment.unapproved');";controls.append(bad)
    bad=dict(sources);bad['unapproved.php']="<?php achbotMessage('payment.api_payment.'.$state);";controls.append(bad)
    bad=dict(sources);bad.pop('src/Finance/PaymentApiAdmin.php');controls.append(bad)
    bad=dict(sources);bad['unapproved.php']="<?php achbotMessage('payment.api_payment.completed');";controls.append(bad)
    if sources[path].count(FINAL_COMMERCE_POLICY_INSERTION)==1:
        bad=dict(sources)
        bad[path]=bad[path].replace("(int)$info['state']!==1", "(int)$info['state']===1",1)
        controls.append(bad)
        bad=dict(sources)
        bad[path]=bad[path].replace("'provider_group_ids','active','state'",
            "'provider_group_ids','active'",1)
        controls.append(bad)
    for candidate in controls:
        try:api_reference_proof(candidate,entries)
        except AssertionError:continue
        raise AssertionError('api_reference_negative_control_missed')
    return len(controls)
