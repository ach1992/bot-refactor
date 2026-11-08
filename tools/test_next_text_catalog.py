#!/usr/bin/env python3
"""Retained relocation proof plus current two-file copy/audience acceptance."""
from __future__ import annotations
import hashlib, json, pathlib, re, socket, subprocess, sys, tempfile, time, urllib.error, urllib.request
import catalog_ui
import test_text_catalog_style as management_catalog
import catalog_owner_r5
ROOT=pathlib.Path(__file__).resolve().parents[1]
def command(*args,**kw):return subprocess.check_output(args,cwd=ROOT,**kw)
def check(ok,message):
    if not ok:raise AssertionError(message)
def runtime_paths():
    return management_catalog.management_runtime_paths()
STYLE_BASELINE='7faac9b067f8a171e5e26d6aea5aaf9f4088a3e7'
RELOCATION_SOURCE='ecd30ba9192ed374703118a6c131be8809cc7b06'
def relocation_source(path):
    return command('git','show',RELOCATION_SOURCE+':'+path)
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
        current=relocation_source(path)
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
        current=restored.get(path,relocation_source(path))
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
    sources={path:(ROOT/path).read_text() for path in runtime_paths()}
    refs=set()
    for path,source in sources.items():
        refs.update(catalog_owner_r5.api_source_references(path,source))
    catalog=json.loads(command('php','-r',
        'require "messages/loader.php";$r=[];foreach(achbotMessageTopics() as $t){foreach(achbotMessages($t) as $k=>$v)$r[]=$t.".".$k;}echo json_encode($r);'))
    refs,api_references=catalog_owner_r5.api_reference_proof(sources,catalog)
    api_reference_controls=catalog_owner_r5.api_reference_negative_controls(sources,catalog)
    entries=set(catalog)
    check(len(entries)==len(catalog),'duplicate catalog keys')
    check(refs<=entries,'unknown catalog references: '+str(refs-entries))
    unused={k for k in entries-refs if not k.startswith(('legacy_messages.','legacy_buttons.'))}
    management=json.loads((ROOT/'tools/text_catalog_management_amendment.json').read_text())
    # The scoped admin catalogue no longer renders the three scope-filter
    # buttons. Preserve the previously accepted, editable copy key without
    # quietly treating arbitrary new orphan labels as acceptable.
    obsolete_filter={'addon_scopes.filter_all'}
    check(obsolete_filter<=entries and not (obsolete_filter & refs),
          'retired_scope_filter_label_is_rendered_or_missing')
    # The canonical renderer also retires six legacy post-edit only
    # messages/placeholder headers. Preserve exact provenance while ensuring
    # none silently reappear in current UI; no other orphan is admitted.
    obsolete_postwrite={
        'plans.intervals.add_new_day_plan.msg.34a6e4ec60',
        'plans.intervals.add_new_volume_plan.msg.9725f12c7b',
        'plans.intervals.day_plan_settings.button.59ca629220',
        'plans.intervals.day_plan_settings.button.7d2c8a1926',
        'plans.intervals.day_plan_settings.button.be0d8f0892',
        'plans.intervals.volume_plan_settings.button.57fa83c28f',
    }
    interval_source=(ROOT/'src/Plans/Intervals.php').read_text()
    check(obsolete_postwrite<=entries and not (obsolete_postwrite & refs) and
          'wizwizch' not in interval_source,'retired_postwrite_catalogue_reintroduced')
    # #112 deliberately retains four historical copy keys while retiring
    # the global transfer toggle and obsolete DB-only plan messages.
    retired_shared_transfer={
        'renewal.extensions.message.dee2da49f3',
        'admin_settings.config.get_bot_setting_keys.button.c6b7f26615',
        'subscription.order_management.show_order_management_panel.alert.f19b5510c6',
        'subscription.account_transfers.alert.6b5b63fb0f',
    }
    check(retired_shared_transfer<=entries and not
          (retired_shared_transfer & refs),'retired_shared_transfer_copy_reintroduced')
    # R1 removed the legacy unguarded bot.php server-deletion branch.
    # Keep only its two historical UI keys as explicit, source-proven orphans
    # rather than deleting frozen translations or admitting arbitrary unused
    # labels. The Owner Rev4 live delete/state/plan copy is source pinned.
    removal=json.loads((ROOT/'tools/text_catalog_server_removal_safety.json').read_text())
    legacy_delete_keys={
        'bot.bot.addserver_name.alert.601504ae47',
        'bot.bot.addserver_name.message.756c3005d3',
    }
    historical_labels={
        'bot.bot.servers_setting.alert.9f4e68eda7':'bot.php',
        'plans.administration.edit_custom_plan.alert.3064bbd794':'src/Plans/Administration.php',
        'plans.administration.edit_custom_plan.message.b059640ccc':'src/Plans/Administration.php',
    }
    retired_keys=legacy_delete_keys|set(historical_labels)
    check(removal.get('version')==1 and
          removal.get('previous_candidate')==catalog_owner_r5.SERVER_REMOVAL_PARENT and
          set(removal.get('retained_unreferenced_keys',[]))==retired_keys and
          len(removal['retained_unreferenced_keys'])==len(retired_keys),
          'legacy_server_removal_copy_lineage_invalid')
    original_bot=command('git','show',catalog_owner_r5.SERVER_REMOVAL_PARENT+':bot.php').decode()
    current_bot=sources['bot.php']
    check(all(key in original_bot and key not in current_bot for key in legacy_delete_keys) and
          legacy_delete_keys<=entries and not (legacy_delete_keys&refs),
          'obsolete_unguarded_server_deletion_copy_still_in_use_or_missing')
    for key,path in historical_labels.items():
        ancestor=command('git','show',catalog_owner_r5.SERVER_REMOVAL_PARENT+':'+path).decode()
        check(key in ancestor and key not in sources[path] and key in entries and key not in refs,
              'retired_owner_rev4_status_plan_label_invalid:'+key)
    # #117 deliberately removes the unsolicited BUY_SUB Telegram-delivery
    # alert to the Owner. Preserve the already-accepted three catalog strings
    # and their history for existing installations, but require proof that
    # no other new orphan is admitted by this exact retirement.
    retired_bank_delivery_push={
        'manual_payment.automatic_receipts.text.d828bce37f',
        'manual_payment.automatic_receipts.message.9b88994424',
        'manual_payment.automatic_receipts.text.38146cb4da',
    }
    bank_source=sources['src/Finance/AutomaticReceipts.php']
    old_bank_source=command('git','show',
        '17af1e55dc4cb8db6751a731bb223d93dc6f902f:src/Finance/AutomaticReceipts.php').decode()
    check(retired_bank_delivery_push<=entries and
          not (retired_bank_delivery_push&refs) and
          all(key in old_bank_source and key not in bank_source
              for key in retired_bank_delivery_push),
          'issue117_quiet_delivery_retired_copy_provenance_invalid')
    # Owner explicitly retired unsolicited finance and renewal-review push
    # deliveries. Historical translations stay in the current editable
    # catalog for old receipts and backwards compatibility; prove their
    # exact inventory and original source usage, with a cryptographic
    # inventory fingerprint, rather than admitting arbitrary new orphans.
    retired_review_push=set()
    for topic,source_path,expected_count,digest in (
      ('gateway.gateway_return.np_alert_operator.',
       'src/Finance/GatewayReturn.php',26,
       'ea2bcadd0d1fd0df23542cd19b3a5b3413edfa86066b5b1a681929430d5c3a28'),
      ('renewal.automatic_renewal.auto_renew_alert_review.',
       'src/Finance/AutomaticRenewal.php',6,
       'e02164fea0fc02080bc71e35fdb3a427525a7f57dfc99f6b3422d522af4f3dab'),
    ):
        keys={key for key in entries if key.startswith(topic)}
        ancestor=command('git','show',
          '17af1e55dc4cb8db6751a731bb223d93dc6f902f:'+source_path).decode()
        current=sources[source_path]
        check(len(keys)==expected_count and
              hashlib.sha256('\n'.join(sorted(keys)).encode()).hexdigest()==digest and
              all(key in ancestor and key not in current for key in keys) and
              not (keys&refs),'retired_review_push_proof_failed:'+topic)
        retired_review_push|=keys
    old_delivery=command('git','show',
      '3e524af8003891ac6cf849fac0116c01e942ad0a:src/Finance/Routes/DeliveryRecovery.php').decode()
    delivery_source=sources['src/Finance/Routes/DeliveryRecovery.php']
    retained_delivery_back={'delivery_recovery.back_inbox'}
    check(retained_delivery_back<=entries and
          all(k in old_delivery and k not in delivery_source for k in retained_delivery_back)
          and not (retained_delivery_back&refs),
          'retired_delivery_navigation_copy_not_exact')
    # PR #122 independent R3: original manual receipt completion now always
    # uses the source-real three-row no-op keyboard. Preserve old editable
    # translations without reintroducing their now-retired navigation buttons.
    retired_manual_terminal={
        'src/Finance/Routes/Extensions.php':{
            'renewal.extensions.button.139c829462'},
        'src/Finance/Routes/RenewalCheckout.php':{
            'renewal.extensions.button.139c829462'},
        'src/Finance/Routes/Checkout.php':{
            'payment.checkout.button.139c829462',
            'payment.checkout.hash_id.2899228604',
            'payment.checkout.remark.4348a3fd9b'},
        'src/Finance/Routes/ManualApproval.php':{
            'manual_payment.manual_approval.manual_standard_report_delivery.remark.4348a3fd9b'},
    }
    retained_manual_terminal=set().union(*retired_manual_terminal.values())
    for path,keys in retired_manual_terminal.items():
        ancestor=command('git','show',
            '17af1e55dc4cb8db6751a731bb223d93dc6f902f:'+path).decode()
        current=sources[path]
        check(all(key in ancestor and key not in current for key in keys),
              'manual_final_retired_source_provenance:'+path)
    check(retained_manual_terminal<=entries and not (retained_manual_terminal&refs),
          'manual_final_retired_catalog_unknown_or_still_live')
    # #117 newer Owner decision removed the extra merchant-GET preview and
    # second-click confirmation. Retain these five approved historical copy
    # entries (old Telegram screens may still exist) without allowing any
    # other unreferenced catalog key to slip into the inventory.
    retired_decision_preview={
        'finance_workbench.not_safe',
        'finance_workbench.commit_approve',
        'finance_workbench.commit_reject',
        'finance_workbench.preview_approve',
        'finance_workbench.preview_reject',
    }
    old_decisions=command('git','show',
        '569b2dfaee6658598aaf527fe6a389bd8685aa66:src/Finance/Routes/ManualReceipts.php').decode()
    current_decisions=sources['src/Finance/Routes/ManualReceipts.php']
    check(retired_decision_preview<=entries and
          all("'"+key+"'" in old_decisions and "'"+key+"'" not in current_decisions
              for key in retired_decision_preview) and
          not (retired_decision_preview & refs),
          'owner_direct_decision_retired_preview_provenance')
    # #117 independently reviewed R2: the untracked post-commit failure
    # notification was replaced by 020 durable, independently acknowledged
    # report intents. Keep only this exact old copy key, not a wildcard.
    replaced_followup={'manual_payment.manual_approval.manual_standard_report_delivery.message.76fb0b9a90'}
    before_followup=command('git','show',
        '7a8942aab7b83720f641b871c835fe520c903a27:src/Finance/Routes/ManualApproval.php').decode()
    check(replaced_followup<=entries and all(
        "'"+key+"'" in before_followup and "'"+key+"'" not in sources['src/Finance/Routes/ManualApproval.php']
        for key in replaced_followup) and not (replaced_followup & refs),
        'followup_outbox_retired_notice_provenance')
    # A report staged before the recipient Telegram ACK must not claim
    # success of customer delivery. The old pre-020 notice is retained only
    # for byte-stable history; the truthful existing workbench report is live.
    replaced_reject_report={'manual_payment.manual_receipts.message.667f0ab959'}
    before_reject=command('git','show',
        '5863fbd64fe6ee59cfddc09d5e5d9cc992f6a0cc:src/Finance/Routes/ManualReceipts.php').decode()
    check(replaced_reject_report<=entries and all(
        "'"+key+"'" in before_reject and "'"+key+"'" not in sources['src/Finance/Routes/ManualReceipts.php']
        for key in replaced_reject_report) and not (replaced_reject_report & refs),
        'manual_reject_false_delivery_claim_retired_provenance')
    expected_unused=(retired_shared_transfer|set(management['retained_legacy_keys'])|
        catalog_owner_r5.verify_retained_display_keys()|
        catalog_owner_r5.API_REVIEW_RETAINED|obsolete_filter|obsolete_postwrite|
        retired_keys|retired_bank_delivery_push|
        retired_review_push|retained_delivery_back|retained_manual_terminal|
        retired_decision_preview|replaced_followup|replaced_reject_report)
    check(unused==expected_unused,'unrecorded legacy/orphan copy entries: '+str(unused^expected_unused))
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
        # Dynamic catalog keys are machine state; indirect visible parameter
        # values still receive the same return/value tracing as ordinary UI.
        fixture.write_text("<?php function stageKey(){return 'MachineOnlyKey';} function label(){return 'Visible Parameter';} sendMessage(achbotManagementText(stageKey(),['value'=>label()]));")
        found={t['raw']for t in catalog_ui.ui_candidates(ROOT,[str(fixture)])}
        check(found=={"'Visible Parameter'"},'management catalog key/parameter audit boundary failed:'+str(found))
        fixture.write_text("<?php $draft=['stage'=>'url'];$prompt=match($draft['stage']){'url'=>'server_url',default=>'error'};sendMessage(achbotManagementText($prompt));")
        found={t['raw']for t in catalog_ui.ui_candidates(ROOT,[str(fixture)])}
        check(not found,'management lookup state misclassified as UI copy:'+str(found))
        # Named return values used solely in a comparison are implementation
        # fields, but nearby actual labels and the same helper rendered directly
        # must remain observable. Never whitelist a source file/helper name.
        fixture.write_text("<?php function fields(){return ['start_message','user_welcome_message','reached_main_menu','purchase_note'];} function label(){return 'Visible English UI';} $field='start_message';$text=in_array($field,fields(),true)?label():'Fallback English UI';sendMessage($text);")
        found={t['raw']for t in catalog_ui.ui_candidates(ROOT,[str(fixture)])}
        check(found=={"'Visible English UI'","'Fallback English UI'"},'predicate field/visible label audit boundary failed:'+str(found))
        fixture.write_text("<?php function fields(){return ['start_message','user_welcome_message','reached_main_menu','purchase_note'];} sendMessage(implode(', ',fields()));")
        found={t['raw']for t in catalog_ui.ui_candidates(ROOT,[str(fixture)])}
        check({"'user_welcome_message'","'reached_main_menu'","'purchase_note'"}<=found,'rendered machine-looking fields escaped UI audit:'+str(found))

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
    check(native['status']=='PASS'and native.get('current_catalog_binding_paths')==2
          and native.get('current_catalog_binding_entries')==4 and native.get('current_catalog_binding_negative_controls')==4
          and native.get('retired_current_facade_absent')is True,
          'pre-style byte and canonical current catalog bindings acceptance')
    presentation=json.loads(command('python3','tools/test_text_catalog_style.py'))
    check(presentation.get('status')=='PASS' and presentation.get('editable_files')==2
          and presentation.get('canonical_binding_paths')==2 and presentation.get('canonical_binding_entries')==4 and presentation.get('canonical_binding_negative_controls')==4
          and presentation.get('current_keys')==2523+len(management['added_keys'])-len(catalog_owner_r5.manifest()['retired_keys'])+len(catalog_owner_r5.manifest()['added_keys'])+len(catalog_owner_r5.api_manifest()['added_keys'])+1+8+14+9+18+27+12+7+17+2+2+3+4+1+3+6+1  # Previously pinned messages plus one private diagnostic template.
          and presentation.get('management_added_keys')==len(management['added_keys'])
          and presentation.get('management_baseline_keys')==2523
          and presentation.get('management_negative_controls')==5
          and presentation.get('management_provider_label_cases')==11
          and presentation.get('management_facade_cases')==sum(record.get('facade')is True for record in management['added_keys'].values()) and presentation.get('retired_keys')==23
          and presentation.get('added_keys')==1 and presentation.get('logical_topics')==28+10
          and presentation.get('numeric_template_changes')==23
          and presentation.get('adjacent_emoji_violations')==0
          and presentation.get('parameter_exception_count')==3
          and presentation.get('semantic_label_icon_cases')==12
          and presentation.get('semantic_context_icon_cases')==16
          and presentation.get('native_compositions')==6
          and presentation.get('reviewed_semantic_context_cases')==9
          and presentation.get('archived_semantic_context_RED')==7
          and presentation.get('recipient_placement_cases')==12
          and presentation.get('archived_recipient_placement_RED')==12
          and presentation.get('recipient_payloads_byte_unchanged') is True
          and presentation.get('recipient_source_files')==3,
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
      'catalog_keys':len(entries),**api_references,'api_reference_negative_controls':api_reference_controls,'management_added_keys':len(management['added_keys']),'management_retained_legacy_keys':len(management['retained_legacy_keys']),'runtime_references':len(refs),'static_ui_violations':len(violations),'ascii_ui_violations':len(ascii_ui),'ascii_audit_negative_controls':8,'management_ascii_audit_controls':2,'machine_predicate_audit_controls':2,'migrated_machine_fields_or_sql_arguments':0,
      'authorized_source_dependency_files':len(amendments['source_dependencies']),'authorized_bootstrap_flow_changes':1,'approved_public_copy_corrections':len(amendments['approved_copy']),
      'direct_http_guards':len(guarded),'editable_catalog_files':2,'logical_topics':28+10,
      'current_authorized_source_edits':len(style_source['edits']),'retired_parser_tokens':len(style_source['retired_keys']),'relocation_source_ref':RELOCATION_SOURCE,'presentation':presentation,'native':native},ensure_ascii=False))
if __name__=='__main__':main()
