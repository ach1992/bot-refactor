#!/usr/bin/env python3
"""Owner revision4: two editable audiences; bounded style and native copy proof."""
from __future__ import annotations
import collections, copy, functools, io, json, pathlib, re, socket, subprocess, tarfile, tempfile, time, urllib.error, urllib.request
import catalog_owner_r5
ROOT=pathlib.Path(__file__).resolve().parents[1]
BASE='7faac9b067f8a171e5e26d6aea5aaf9f4088a3e7'
DIGITS=str.maketrans('\u06f0\u06f1\u06f2\u06f3\u06f4\u06f5\u06f6\u06f7\u06f8\u06f9\u0660\u0661\u0662\u0663\u0664\u0665\u0666\u0667\u0668\u0669','01234567890123456789')
def check(ok,message):
    if not ok:raise AssertionError(message)
def command(*args,**kwargs):return subprocess.check_output(args,cwd=ROOT,**kwargs)
def text(entry):return entry['text']if isinstance(entry,dict)else entry
def parameters(entry):return entry['parameters']if isinstance(entry,dict)else []
def flatten(groups):return {topic+'.'+key:entry for topic,entries in groups.items()for key,entry in entries.items()}
def baseline():
    with tempfile.TemporaryDirectory(prefix='achbot-style-baseline-')as scratch:
        out=pathlib.Path(scratch)
        archive=command('git','archive',BASE,'messages')
        with tarfile.open(fileobj=io.BytesIO(archive))as source:
            for member in source.getmembers():
                rel=pathlib.PurePosixPath(member.name)
                check(not rel.is_absolute()and'..'not in rel.parts and not member.issym()and not member.islnk(),'unsafe baseline member')
                if member.isfile():
                    path=out/rel;path.parent.mkdir(parents=True,exist_ok=True);path.write_bytes(source.extractfile(member).read())
        php='require '+json.dumps(str(out/'messages/loader.php'))+';$r=[];foreach(glob('+json.dumps(str(out/'messages/fa/*.php'))+')as$p){$t=basename($p,".php");$r[$t]=achbotMessages($t);}echo json_encode($r,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);'
        return flatten(json.loads(command('php','-r',php)))
EMOJI_LETTERS=set()
def words(value):
    # Unicode includes pictographic letters such as the information glyph.
    # They are approved visual markers, not added/removed product wording.
    value=''.join(' 'if c in EMOJI_LETTERS else c for c in value)
    return re.findall(r'[^\W\d_]+|[0-9]+',value.translate(DIGITS),re.UNICODE)
CONTEXT_REFERENCE='707d1c930162bf69e220424bd648860be8e64195'
CONTEXT_CUES={
 'jobs.gift2all.message.1ea4ee3d49':'❌',
 'bot.bot.add_marzban_panel_group.alert.a77f2c705d':'❌',
 'bot.bot.gift_volume_and_day.message.dda8b45a3f':'📦',
 'provider.provider_admin.handle_provider_admin.message.bf4b28ea45':'🔑',
 'bot.bot.gift_volume_and_day.message.e0e7fe8fcb':'⚠️',
 'bot.bot.gift_volume_and_day.message.b155540ffb':'ℹ️',
 'bot.bot.process_callback_query.message.a4712f82bb':'ℹ️',
 'wallet.wallet_transfer.wallet_transfer_restart.message.6924ce253f':'⚠️',
 'wallet.admin_wallet.admin_wallet_report_result.customer.a5b15e454e':'✅',
}
CONTEXT_REPAIRS=list(CONTEXT_CUES)[:7]
PRIVATE_SUPPORT=['support.customer_conversation.user_all_tickets.'+key for key in [
 'message.2baf15e3c0','txt.54b5fecc08','text.a79a2fa69e','text.46d8580795','text.497743bb13',
 'button.2dc9dcf8cd','button.c9b4331f4f','button.74190f4bb0','button.105e0409bc']]
SHARED_SUPPORT=['support.admin_reply.button.f8a34e79d9','support.admin_reply.button.173875ae6b',
 'support.queue_and_history.all_tickets.text.61cf036058']
def context_cue(entries,key):
    check(text(entries[key]).startswith(CONTEXT_CUES[key]+' '),'semantic_status_context_mismatch:'+key)
def recipient_side(sides,key,target):
    other='admin'if target=='user'else'user'
    check(key in sides[target]and key not in sides[other],'recipient_placement_mismatch:'+key)
def reviewed_context_proof(sides,merged,manifest):
    check(manifest.get('semantic_context_reference')==CONTEXT_REFERENCE,'context reference drift')
    archived={}
    decode='$s=stream_get_contents(STDIN);$p=strpos($s,"return [");if($p===false)throw new RuntimeException("array_missing");echo json_encode(eval(substr($s,$p)),JSON_THROW_ON_ERROR);'
    for audience in ['user','admin']:
        raw=command('git','show',CONTEXT_REFERENCE+':messages/fa/'+audience+'.php')
        archived[audience]=flatten(json.loads(command('php','-r',decode,input=raw)))
    old={**archived['user'],**archived['admin']}
    for key in CONTEXT_CUES:context_cue(merged,key)
    rejected=0
    for key in CONTEXT_REPAIRS:
        try:context_cue(old,key)
        except AssertionError as error:
            check(str(error)=='semantic_status_context_mismatch:'+key,'context RED rejected wrong property')
            rejected+=1
        else:raise AssertionError('actual archived bad context not rejected:'+key)
    expected={key:('user','admin')for key in PRIVATE_SUPPORT}|{key:('admin','user')for key in SHARED_SUPPORT}
    placements=manifest.get('recipient_placements',{})
    check(set(placements)==set(expected),'recipient placement scope drift')
    role_red=0
    for key,(before,after)in expected.items():
        record=placements[key]
        check(record['from']==before and record['to']==after and record.get('reason'),'recipient amendment drift:'+key)
        recipient_side(sides,key,after)
        check(merged[key]==old[key],'relocation changed actual payload:'+key)
        check(key in archived[before],'archived placement identity drift:'+key)
        try:recipient_side(archived,key,after)
        except AssertionError as error:
            check(str(error)=='recipient_placement_mismatch:'+key,'recipient RED rejected wrong property')
            role_red+=1
        else:raise AssertionError('actual archived misplaced entry accepted:'+key)
    # Bind recipient reasoning to the three unchanged real route sources. These
    # checks concern editing placement, not a general authorization proof.
    sources={name:(ROOT/('src/Support/'+name)).read_text()for name in ['CustomerConversation.php','AdminReply.php','QueueAndHistory.php']}
    for name,source in sources.items():
        check(source==command('git','show',CONTEXT_REFERENCE+':src/Support/'+name).decode(),'recipient source changed:'+name)
    customer=sources['CustomerConversation.php'];admin=sources['AdminReply.php'];history=sources['QueueAndHistory.php']
    close=customer[customer.index("if(preg_match('/^closeTicket_"):customer.index("if(preg_match('/^replySupport_")]
    check(all(key in close for key in PRIVATE_SUPPORT if key.endswith(('2baf15e3c0','2dc9dcf8cd','c9b4331f4f','74190f4bb0')))
          and 'sendMessage(\\achbotMessage(\'support.customer_conversation.user_all_tickets.message.2baf15e3c0\'),$keys,"HTML",$admin)'in close
          and "sendToAllAdmins('sendMessage'"in close,'actual administrator closure recipients drift')
    incoming=customer[customer.index("if(preg_match('/^sendMsg_"):customer.index('if(preg_match("/^rate_')]
    check(all(key in incoming for key in PRIVATE_SUPPORT if key.endswith(('54b5fecc08','a79a2fa69e','46d8580795')))
          and 'sendMessage($txt, $keys, "HTML", $admin)'in incoming
          and 'sendPhoto($fileid, $txt,$keys, "HTML", $admin)'in incoming
          and "'text' => $txt"in incoming and "'caption' => $txt"in incoming,'actual administrator incoming recipients drift')
    rating=customer[customer.index('if(preg_match("/^rate_'):]
    check(all(key in rating for key in PRIVATE_SUPPORT if key.endswith(('497743bb13','105e0409bc')))
          and 'sendMessage($text, $keys, "HTML", $admin)'in rating
          and "sendToAllAdmins('sendMessage'"in rating,'actual administrator rating recipients drift')
    listing=customer[:customer.index("if(preg_match('/^closeTicket_")]
    check(all(key in listing and key in admin for key in SHARED_SUPPORT[:2])
          and 'sendPhoto($fileid, $txt,json_encode' in listing
          and '"MarkDown", $userId)'in admin,'actual shared customer ticket controls drift')
    latest=history[history.index("if(preg_match('/^latestMsg_"):history.index("if(preg_match('/^\\/dlPic")]
    check(SHARED_SUPPORT[2]in latest and 'sendMessage($output, null, "html")'in latest
          and '"latestMsg_$rowId"'in listing,'actual customer shared history label drift')
    return {'reviewed_semantic_context_cases':len(CONTEXT_CUES),'archived_semantic_context_RED':rejected,
            'recipient_placement_cases':len(expected),'archived_recipient_placement_RED':role_red,
            'recipient_payloads_byte_unchanged':True,'recipient_source_files':len(sources)}

MANAGEMENT_BASE='2c0e728dcd71c38e20d176fe2577bc264d3faa1a'
MANAGEMENT_CONTRACT='docs/post-v9/UNIFIED-SERVICE-MANAGEMENT.md'
MANAGEMENT_USER_KEYS={'subscription.account_details.auto_renew_update_unverified','subscription.account_deletion.shared_effect_notice','subscription.account_details.status_on_hold','subscription.manual_accounts.import_unverified','subscription.manual_accounts.import_under_review','subscription.manual_accounts.import_completed','renewal.extensions.plan_selection_unavailable','renewal.extensions.plan_selection_review','subscription.presentation.config_qr_delivery_unconfirmed','subscription.presentation.config_qr_name_suffix'}
MANAGEMENT_ADMIN_KEYS={'subscription.manual_accounts.customer_not_found','subscription.account_deletion.report'}
def management_runtime_paths():
    # The package is the shipping source authority, including new draft modules
    # before the first candidate commit. No arbitrary worktree PHP is accepted.
    import package_post_v9
    return [p for p in package_post_v9.members()
            if p.endswith('.php') and not p.startswith(('phpqrcode/','messages/'))]
def management_runtime_refs():
    refs=set()
    for path in management_runtime_paths():
        refs.update(re.findall(r"\\?achbotMessage(?:Html)?\('([^']+)'",(ROOT/path).read_text()))
    return refs
def management_baseline_sides():
    decode='$s=stream_get_contents(STDIN);$p=strpos($s,"return [");if($p===false)throw new RuntimeException("array_missing");echo json_encode(eval(substr($s,$p)),JSON_THROW_ON_ERROR);'
    return {audience:flatten(json.loads(command('php','-r',decode,input=command(
        'git','show',MANAGEMENT_BASE+':messages/fa/'+audience+'.php'))))
        for audience in ['user','admin']}
OWNER_FOLLOWUP_BASE='c9959811e264b5a8728f6a44376cb69c9a89b7f4'
OWNER_FOLLOWUP_RESULT='faa9aa6502a4e24152f1fa6faad7f0ab7921652e'
@functools.lru_cache(maxsize=None)
def owner_followup_sides(ref):
    decode='$s=stream_get_contents(STDIN);$p=strpos($s,"return [");if($p===false)throw new RuntimeException("array_missing");echo json_encode(eval(substr($s,$p)),JSON_THROW_ON_ERROR);'
    return {audience:flatten(json.loads(command('php','-r',decode,input=command(
        'git','show',ref+':messages/fa/'+audience+'.php'))))for audience in ['user','admin']}
def owner_followup(sides,merged,manifest=None):
    if manifest is None:manifest=json.loads((ROOT/'tools/text_catalog_owner_followup.json').read_text())
    check(manifest.get('version')==1 and manifest.get('baseline_commit')==OWNER_FOLLOWUP_BASE
          and manifest.get('result_commit')==OWNER_FOLLOWUP_RESULT
          and manifest.get('authorization')==MANAGEMENT_CONTRACT,'owner followup identity drift')
    before_sides=owner_followup_sides(OWNER_FOLLOWUP_BASE);after_sides=owner_followup_sides(OWNER_FOLLOWUP_RESULT)
    before={**before_sides['user'],**before_sides['admin']};after={**after_sides['user'],**after_sides['admin']}
    changes=manifest.get('changes',{})
    expected={key for key in before if before[key]!=after[key]}
    check(len(expected)==7 and set(changes)==expected,'owner followup exact seven-entry scope drift')
    for key,entry in before.items():
        audience='user'if key in before_sides['user']else'admin'
        check(key in sides[audience] and merged.get(key)==after[key],'owner followup result or audience drift:'+key)
        if key in expected:
            record=changes[key]
            check(record.get('before')==entry and record.get('after')==after[key]
                  and record.get('audience')==audience and record.get('reason'),'owner followup declaration drift:'+key)
            check(parameters(entry)==parameters(after[key]),'owner followup template parameter drift:'+key)
            check(not re.search(r'[\u06f0-\u06f9\u0660-\u0669]',text(after[key])),'owner followup numeric presentation drift:'+key)
            check(re.findall(r'</?[A-Za-z][^>]*>',text(entry))==re.findall(r'</?[A-Za-z][^>]*>',text(after[key])),
                  'owner followup markup drift:'+key)
    return changes
def owner_followup_negative_controls(sides,merged):
    manifest=json.loads((ROOT/'tools/text_catalog_owner_followup.json').read_text())
    key='provider.provider_admin.handle_provider_admin.message.bf4b28ea45';controls=[]
    for bad in ['Unapproved rewrite','<b>Unapproved markup</b>',{'text':'Changed {extra}','parameters':['extra']}]:
        wrong_sides=copy.deepcopy(sides);wrong_sides['admin'][key]=bad
        wrong_manifest=copy.deepcopy(manifest);wrong_manifest['changes'][key]['after']=bad
        controls.append((wrong_sides,{**wrong_sides['user'],**wrong_sides['admin']},wrong_manifest))
    wrong_sides=copy.deepcopy(sides);wrong_sides['user'][key]=wrong_sides['admin'].pop(key)
    controls.append((wrong_sides,merged,manifest))
    wrong=copy.deepcopy(manifest);wrong['changes'][key]['before']='Forged old text';controls.append((sides,merged,wrong))
    wrong=copy.deepcopy(manifest);wrong['changes'][key]['reason']='';controls.append((sides,merged,wrong))
    wrong_sides=copy.deepcopy(sides);warning='notifications.warnings.msg.804056ecc4';wrong_sides['user'][warning]='Unapproved warning rewrite'
    controls.append((wrong_sides,{**wrong_sides['user'],**wrong_sides['admin']},manifest))
    for no,(candidate_sides,candidate_merged,candidate_manifest)in enumerate(controls,1):
        try:owner_followup(candidate_sides,candidate_merged,candidate_manifest)
        except AssertionError:continue
        raise AssertionError('owner followup negative control missed:'+str(no))
    return len(controls)
def management_amendment(sides,merged,amendment=None):
    if amendment is None:amendment=json.loads((ROOT/'tools/text_catalog_management_amendment.json').read_text())
    check(amendment.get('version')==1 and amendment.get('baseline_commit')==MANAGEMENT_BASE
          and amendment.get('authorization')==MANAGEMENT_CONTRACT,
          'management amendment identity drift')
    check((ROOT/MANAGEMENT_CONTRACT).is_file(),'management accepted contract missing')
    followups=owner_followup(sides,merged)
    archived=management_baseline_sides();before={**archived['user'],**archived['admin']}
    check(len(before)==2523,'management baseline key identity drift')
    additions=amendment.get('added_keys',{})
    check(additions and all(key.startswith('admin_settings.management.') or
          key in MANAGEMENT_USER_KEYS|MANAGEMENT_ADMIN_KEYS for key in additions),
          'management addition scope drift')
    check(set(merged)==set(before)|set(additions),'management catalog extension drift')
    for key,entry in before.items():
        check(merged[key]==followups.get(key,{}).get('after',entry),'pre-management copy changed:'+key)
        audience='user'if key in archived['user']else'admin'
        check(key in sides[audience],'pre-management recipient changed:'+key)
    # The earlier management/relocation proof belongs to its accepted stage.
    # Current R5 declarations are independently bound to that exact stage above.
    archived_paths=set(command('git','ls-tree','-r','--name-only',catalog_owner_r5.BASE).decode().splitlines())
    runtime_sources={path:command('git','show',catalog_owner_r5.BASE+':'+path).decode()
        for path in management_runtime_paths() if path in archived_paths}
    facade_source=runtime_sources['src/Admin/ManagementFlow.php'];facade_source=facade_source[facade_source.index('function achbotManagementText('):facade_source.index('function achbotManagementEscape(')]
    facade_keys=set(re.findall(r"achbotMessage\('([^']+)'",facade_source))
    check(facade_keys=={key for key,record in additions.items()if record.get('facade')is True},'management facade mapping scope drift')
    refs=set()
    for source in runtime_sources.values():refs.update(re.findall(r"\\?achbotMessage(?:Html)?\('([^']+)'",source))
    for key,record in additions.items():
        audience=record.get('audience')
        check(audience==('user'if key in MANAGEMENT_USER_KEYS else'admin'),
              'management addition recipient drift:'+key)
        check(key in sides[audience] and merged[key]==record.get('entry'),
              'management added value/parameters drift:'+key)
        check(record.get('reason') and key in refs,'management addition is not source-backed:'+key)
        paths=record.get('source_paths',[])
        check(paths and all(path in runtime_sources and key in runtime_sources[path]
              for path in paths),'management added source identity drift:'+key)
        value=text(merged[key]);params=parameters(merged[key])
        check(isinstance(value,str) and isinstance(params,list) and len(params)==len(set(params)),
              'management template invalid:'+key)
        check(not re.search(r'[\u06f0-\u06f9\u0660-\u0669]',value),'management digits not ASCII:'+key)
        check(set(re.findall(r'\{([a-zA-Z_][a-zA-Z0-9_]*)\}',value))==set(params),
              'management template parameters invalid:'+key)
        check(not re.search(r'<(?!/?(?:b|code)(?:>|\s))',value),'management unsupported markup:'+key)
    retained=amendment.get('retained_legacy_keys',{})
    orphan={key for key in set(merged)-refs if not key.startswith(('legacy_messages.','legacy_buttons.'))}
    check(orphan==set(retained),'unrecorded/obsolete management legacy copy: '+str(sorted(orphan^set(retained))))
    previous_sources={}
    for key,record in retained.items():
        check(key in before and record.get('entry')==followups.get(key,{}).get('after',before[key]) and record.get('reason'),
              'retained management legacy baseline drift:'+key)
        paths=record.get('previous_source_paths',[])
        for path in paths:
            if path not in previous_sources:previous_sources[path]=command('git','show',MANAGEMENT_BASE+':'+path).decode()
        check(paths and all(key in previous_sources[path]for path in paths),
              'retained management legacy source missing:'+key)
    return {'added_keys':additions,'retained_legacy_keys':retained,
            'baseline_keys':len(before),'current_keys':len(merged)}

def management_facade_proof(additions):
    # Execute only definition-only modules, never bootstrap, DB, Telegram or a
    # provider. The UI scanner may therefore treat this exact facade's key
    # arguments as technical tokens, just like achbotMessage itself.
    code=r"""require 'src/Admin/ManagementFlow.php';
$records=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);$count=0;
foreach($records as$key=>$record){
 if(empty($record['facade']))continue;
 $entry=$record['entry'];$parameters=is_array($entry)?$entry['parameters']:[];
 $values=[];foreach($parameters as$name)$values[$name]='fixture <&> {'.$name.'}';
 $short=substr($key,strlen('admin_settings.management.'));
 if(achbotManagementText($short,$values)!==achbotMessage($key,$values))
  throw new RuntimeException('management_facade_payload_drift:'.$key);
 ++$count;
}
echo json_encode(['cases'=>$count],JSON_THROW_ON_ERROR);"""
    result=json.loads(command('php','-n','-d','allow_url_fopen=0','-r',code,
        input=json.dumps(additions,ensure_ascii=False).encode()))
    check(result['cases']==sum(record.get('facade')is True for record in additions.values()),
          'management facade coverage missing')
    return result['cases']

def management_provider_labels():
    code=r"""require 'messages/loader.php';require 'src/Panel/ProviderRuntime.php';
$expected=['marzban'=>'admin_settings.management.marzban','pasarguard'=>'admin_settings.management.pasarguard'];$count=0;
foreach($expected as$type=>$key){
 if(achbotProviderLabel($type)!==achbotMessage($key))throw new RuntimeException('provider_label_catalog_mismatch');
 ++$count;
}
foreach(['unknown','Marzban','pasarguard ','admin_settings.management.marzban','',null,0,true,[]]as$type){
 if(achbotProviderLabel($type)!=='')throw new RuntimeException('unknown_provider_became_label');
 ++$count;
}
echo json_encode(['cases'=>$count],JSON_THROW_ON_ERROR);"""
    result=json.loads(command('php','-n','-d','allow_url_fopen=0','-r',code))
    check(result['cases']==11,'native provider-label boundary missing')
    return result['cases']

def management_negative_controls(sides,merged,record):
    amendment=json.loads((ROOT/'tools/text_catalog_management_amendment.json').read_text())
    admin_key=next(key for key,value in record['added_keys'].items()if value['audience']=='admin')
    controls=[]
    wrong=copy.deepcopy(sides);wrong['user'][admin_key]=wrong['admin'].pop(admin_key)
    controls.append((wrong,merged,amendment))
    wrong=copy.deepcopy(sides);wrong['admin'][admin_key]='Unrecorded copy change'
    controls.append((wrong,{**wrong['user'],**wrong['admin']},amendment))
    wrong=dict(merged);wrong.pop('legacy_messages.banned')
    controls.append((sides,wrong,amendment))
    wrong=dict(merged);wrong['admin_settings.management.unaccepted']='Unaccepted key'
    controls.append((sides,wrong,amendment))
    wrong=copy.deepcopy(amendment)
    baseline=management_baseline_sides()
    key=next(k for k in baseline['admin']if k not in wrong['retained_legacy_keys'])
    wrong['retained_legacy_keys'][key]={'entry':baseline['admin'][key],'reason':'unaccepted','previous_source_paths':['config.php']}
    controls.append((sides,merged,wrong))
    for no,(candidate_sides,candidate_merged,candidate_amendment)in enumerate(controls,1):
        try:management_amendment(candidate_sides,candidate_merged,candidate_amendment)
        except AssertionError:continue
        raise AssertionError('management amendment negative control missed:'+str(no))
    return len(controls)

def main():
    files=sorted(p.relative_to(ROOT).as_posix()for p in(ROOT/'messages/fa').glob('*.php'))
    check(files==['messages/fa/admin.php','messages/fa/user.php'],'editable copy must be exactly user.php/admin.php')
    for file in files:
        keys=re.findall(r"^        '([^']+)' =>",(ROOT/file).read_text(),re.M)
        check(len(keys)==len(set(keys)),'duplicate literal entry in '+file)
    current=json.loads(command('php','-r','$r=[];foreach(["user","admin"]as$a)$r[$a]=require "messages/fa/".$a.".php";echo json_encode($r,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);'))
    sides={a:flatten(v)for a,v in current.items()}
    check(not set(sides['user'])&set(sides['admin']),'duplicate key across audiences')
    current_sides=sides;current_merged={**sides['user'],**sides['admin']}
    sides,r5_proof=catalog_owner_r5.verify_and_restore(current_sides)
    r5_controls=catalog_owner_r5.negative_controls(current_sides)
    merged={**sides['user'],**sides['admin']};management=management_amendment(sides,merged)
    management_controls=management_negative_controls(sides,merged,management)
    owner_changes=owner_followup(sides,merged);owner_controls=owner_followup_negative_controls(sides,merged)
    management_facades=management_facade_proof(management['added_keys'])
    provider_labels=management_provider_labels()
    all_merged=current_merged;merged={k:owner_changes.get(k,{}).get('before',v) for k,v in merged.items()if k not in management['added_keys']};old=baseline()
    chars=sorted({c for entry in[*old.values(),*merged.values()]for c in text(entry)if c.isalpha()})
    glyphs='$r=[];foreach(json_decode(file_get_contents("php://stdin"),true)as$c)if(preg_match("/\\\\p{Extended_Pictographic}/u",$c))$r[]=$c;echo json_encode($r,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);'
    EMOJI_LETTERS.update(json.loads(command('php','-r',glyphs,input=json.dumps(chars).encode())))
    manifest=json.loads((ROOT/'tools/text_catalog_style_amendments.json').read_text())
    check(manifest['version']==1 and manifest['baseline_commit']==BASE,'style baseline drift')
    changes=manifest['changes'];retired=manifest['retired_keys'];added=manifest['added_keys']
    expected_retired={key for key in old if key.startswith(('admin_settings.config.convert_persian_digits_to_english_for_stats.','admin_settings.config.parse_stats_date_range.'))}
    check(set(retired)==expected_retired and len(retired)==23,'retired matcher scope drift')
    feature_retired=manifest.get('retired_feature_keys',{})
    expected_feature_retired={
        'bot.bot.change_config_remark_type.gate.ce14a45981',
        'admin_settings.config.get_gate_ways_keys.text.84a6dfb6ae',
        'admin_settings.config.get_gate_ways_keys.text.f419959ea3',
    }
    check(set(feature_retired)==expected_feature_retired and manifest.get('feature_retirements_authorization')==
          'https://github.com/ach1992/bot-refactor/issues/26#issuecomment-6453867569','feature retirement scope drift')
    for key,record in feature_retired.items():
        check(record['before']==old[key] and record.get('reason'),'feature retirement baseline mismatch')
    check(len(added)==1 and set(merged)==set(old)-set(retired)-set(feature_retired)|set(added),'stable keys or retirement/addition drift')
    privacy_keys={
      'purchase.order_writes.ensure_account_creation_succeeded.user_message.7a64d65208',
      'gateway.gateway_return.perform_payment_effect.message.3768e94d51',
      'jobs.sub_link.legacy_subscription_link_retired_use.6e4eb03e96',
      'admin_settings.config.get_order_detail_keys.alert.fb223619e1',
      'admin_settings.config.get_order_detail_keys.alert.4f0ac830c9',
    }
    diagnostic_key='gateway.gateway_return.perform_payment_effect.text.ed588b3bc9'
    param_keys={key for key in changes if'before_parameters'in changes[key]}
    check(param_keys=={diagnostic_key,
      'purchase.order_writes.ensure_account_creation_succeeded.user_message.7a64d65208',
      'gateway.gateway_return.perform_payment_effect.message.3768e94d51'},'parameter exception scope drift')
    assertions=0
    for key,entry in merged.items():
        check(isinstance(text(entry),str)and isinstance(parameters(entry),list),'invalid entry '+key)
        check(not re.search(r'[\u06f0-\u06f9\u0660-\u0669]',text(entry)),'non-ASCII display digits: '+key)
        if key in added:
            check(text(entry)==added[key]['after']and parameters(entry)==added[key]['parameters'],'added diagnostic drift')
            continue
        before=old[key]
        if key not in changes:
            check(entry==before,'undeclared copy change '+key)
        else:
            record=changes[key]
            check(record['before']==text(before)and record['after']==text(entry),'before/after mismatch '+key)
            check(record.get('before_parameters',record['parameters'])==parameters(before)and record['parameters']==parameters(entry),'parameter mismatch '+key)
            check(text(before)!=text(entry)or parameters(before)!=parameters(entry),'spurious amendment '+key)
            if key not in privacy_keys|{diagnostic_key}:
                check(words(text(before))==words(text(entry)),'unapproved prose rewrite '+key)
                check(re.findall(r'</?[A-Za-z][^>]*>',text(before))==re.findall(r'</?[A-Za-z][^>]*>',text(entry)),'markup changed without caller proof '+key)
        assertions+=1
    check(set(changes)<=set(old)&set(merged),'unmatched amendment')
    for key,record in retired.items():check(record['before']==old[key],'retired baseline mismatch')
    reviewed=reviewed_context_proof(sides,merged,manifest)
    # Known operational notices and customer outcomes have actually different
    # recipients in the source. Shared harmless labels remain edited once.
    admin_cases=[
      diagnostic_key,'purchase.order_writes.ensure_account_creation_succeeded.admin_message.inbound_missing',
      'wallet.wallet_transfer.wallet_transfer_restart.a_ch_bot_wallet_transfer_failure.2e028e487d',
      'wallet.wallet_transfer.wallet_transfer_restart.reference_reference.f5ec5fabf8',
      'wallet.wallet_transfer.wallet_transfer_restart.a_ch_bot_wallet_transfer_notification_failure.9a285d7145',
      'wallet.wallet_transfer.wallet_transfer_restart.reason_reason_code.a18553cad8',
      'wallet.wallet_transfer.wallet_transfer_restart.transfer_already_committed_status_code.abb8981186',
      'renewal.renewal_checkout.alert.fbaa266905','renewal.extensions.alert.135aa8877f',
      'manual_payment.automatic_receipts.message.9b88994424',
      'manual_payment.manual_receipts.body.33cce3f587'
    ]
    user_cases=list(privacy_keys)+[
      'wallet.admin_wallet.admin_wallet_report_result.customer.a5b15e454e',
      'manual_payment.manual_receipts.message.bc0155976d',
      'notifications.warnings.msg.804056ecc4','notifications.warnings.msg.c7907b6342'
    ]
    check(all(key in sides['admin']for key in admin_cases),'administrator diagnostic in customer editing file')
    check(all(key in sides['user']for key in user_cases),'customer outcome in administrator editing file')
    for key in privacy_keys:
        check(not re.search(r'inbound|server_plans|fileid|Marzban',text(merged[key]),re.I),'private implementation identifier in customer copy')
    check('{inbound_id}'in text(sides['admin'][diagnostic_key]),'useful admin inbound diagnostic lost')
    fragments=[key for key in merged if key.startswith('date.')]+[
      'wallet.admin_wallet.admin_wallet_report_result.text.bfb7221456',
      'wallet.admin_wallet.admin_wallet_report_result.text.2cc98f7f7c',
      'jobs.gift2all.text.1a51e219ec','jobs.gift2all.text.b70363d685',
      'subscription.account_details.remaining_traffic',
    ]
    check(all(key in old for key in fragments),'known fragment key drift')
    check(all(merged[key]==old[key]for key in fragments),'shared date/unit/continuation fragment decorated')
    # Real PCRE Unicode graphemes distinguish a stacked pair from joined emoji,
    # flags, variation selectors and keycaps. Newlines begin a separate line.
    php=r'''require 'messages/loader.php';
function adjacentEmoji(string $s): bool {
    preg_match_all('/\X/u',strip_tags($s),$m);$previous=false;
    foreach($m[0]as$c){
        if(str_contains($c,"\n")||str_contains($c,"\r")){$previous=false;continue;}
        $emoji=preg_match('/\p{Extended_Pictographic}|\p{Regional_Indicator}|[0-9]\x{FE0F}?\x{20E3}/u',$c)===1;
        if($previous&&$emoji)return true;
        if(trim($c)!==''&&!in_array($c,['|','│','/'],true))$previous=$emoji;
    }return false;
}
$violations=[];foreach(achbotMessageTopics()as$t)foreach(achbotMessages($t)as$k=>$v){$s=is_array($v)?$v['text']:$v;if(adjacentEmoji($s))$violations[]=$t.'.'.$k;}
foreach(['⚠️ ❌ پیام','❗️ | 💳 پرداخت']as$s)if(!adjacentEmoji($s))throw new RuntimeException('emoji_negative_control_missing');
foreach(['👩‍💻 پیام','🏳️‍🌈 متن','🇮🇷 متن','1️⃣ مرحله',"✅ متن\n⚠️ ادامه"]as$s)if(adjacentEmoji($s))throw new RuntimeException('emoji_grapheme_split');
$prefix='wallet.admin_wallet.admin_wallet_report_result.';
$customer=achbotMessage($prefix.'customer.a5b15e454e').number_format(12500).achbotMessage($prefix.'text.bfb7221456').achbotMessage($prefix.'text.587ace91f5').achbotMessage($prefix.'text.25f7017bbb').number_format(-2500).achbotMessage($prefix.'text.2cc98f7f7c');
if(!str_starts_with($customer,'✅ مبلغ 12,500 تومان ')||!str_contains($customer,'-2,500 تومان')||str_contains($customer,'<')||adjacentEmoji($customer))throw new RuntimeException('committed_wallet_notice_composition');
$gift=achbotMessage('jobs.gift2all.gift_text.8f714dee13',['volume'=>512]).achbotMessage('jobs.gift2all.text.1a51e219ec').achbotMessage('jobs.gift2all.text.b70363d685',['day'=>3]);
$notice=achbotMessage('jobs.gift2all.message.1bf0942a3d',['remark'=>'fixture_account','giftText'=>$gift]);
if(!str_contains($notice,'512 مگابایت حجم و  3 روز زمان')||adjacentEmoji($notice))throw new RuntimeException('gift_fragment_composition');
$sample=achbotMessage('purchase.order_writes.ensure_account_creation_succeeded.admin_message.inbound_missing',['title'=>'Synthetic service','inbound_id'=>123]);
if(!str_contains($sample,'inbound_not_found')||!str_contains($sample,'شناسه ورودی: 123'))throw new RuntimeException('administrator_diagnostic_incomplete');
$private=achbotMessage('purchase.order_writes.ensure_account_creation_succeeded.user_message.7a64d65208');
if(str_contains($private,'123')||str_contains($private,'inbound'))throw new RuntimeException('customer_inbound_disclosure');
require 'tools/catalog_bindings.php';$bindingProof=catalogAssertCurrentBindings(__DIR__);$bindingNegatives=catalogBindingNegativeControls();$mainValues=$bindingProof['main'];$buttonValues=$bindingProof['buttons'];
// Exercise the original legacy user quote/admin request compositions. These
// assertions fail for their old battery/name/ID/money/date marker mix.
$userQuote=str_replace(['PLAN-NAME','VOLUME','DAYS','PRICE','DESCRIPTION'],['Fixture plan','10','30','100000','Fixture description'],$mainValues['buy_custom_subscription_detail']);
$adminRequest=strtr($mainValues['buy_new_account_request'],['TYPE'=>'bank','USER-ID'=>'1001','NAME'=>'Fixture customer','USERNAME'=>'fixture_user','PRICE'=>'100000','SERVERNAME'=>'Fixture service','REMARK'=>'fixture_account','VOLUME'=>'10','DAYS'=>'30']);
$fields=[
 [$adminRequest,'🎫','شناسه عددی:'],[$adminRequest,'👤','اسم کاربر:'],
 [$adminRequest,'🔖','نام کاربری:'],[$adminRequest,'💰','مبلغ پرداختی:'],
 [$adminRequest,'📦','حجم اشتراک:'],[$adminRequest,'📅','مدت اشتراک:'],
 [$userQuote,'📦','حجم اختصاصی:'],[$userQuote,'📅','مدت اختصاصی:'],[$userQuote,'💰','قیمت پلن:'],
 [$buttonValues['volume_left'],'📦','حجم باقیمانده:'],
 [$buttonValues['expire_date'],'📅','تاریخ انقضاء:'],[$buttonValues['buy_date'],'📅','تاریخ خرید:'],
];
foreach($fields as[$value,$icon,$label])if(!preg_match('/(?:^|\R)[ \t]*'.preg_quote($icon,'/').'[ \t]*'.preg_quote($label,'/').'/u',strip_tags($value)))throw new RuntimeException('semantic_field_marker_mismatch');
if(adjacentEmoji($userQuote)||adjacentEmoji($adminRequest))throw new RuntimeException('legacy_composition_stacked_marker');
$contexts=0;
foreach(achbotMessages('wallet')as$key=>$entry)if(str_starts_with($key,'wallet_recharge.wallet_topup_amount_problem.button.')&&preg_match('/\d[0-9,]* تومان/u',is_array($entry)?$entry['text']:$entry)){
 $value=is_array($entry)?$entry['text']:$entry;
 if(!str_starts_with($value,'💰 '))throw new RuntimeException('amount_choice_unrelated_marker');$contexts++;
}
if($contexts!==7)throw new RuntimeException('amount_choice_fixture_drift');
foreach(['config.get_gate_ways_keys.button.26003a476f','config.get_gate_ways_keys.button.c0199d5243','config.get_gate_ways_keys.button.a0c8498d77']as$key){if(!str_starts_with(achbotMessage('admin_settings.'.$key),'💳 '))throw new RuntimeException('gateway_choice_unrelated_marker');$contexts++;}
foreach([$buttonValues['renew_config'],achbotMessage('admin_settings.config.get_bot_setting_keys.button.df1c62b99e'),achbotMessage('admin_settings.config.get_bot_setting_keys.button.f9a77f614a')]as$value){if(!str_starts_with($value,'♻️ '))throw new RuntimeException('renew_action_marker_mismatch');$contexts++;}
foreach([[$buttonValues['back_to_main'],'🔙 '],[$buttonValues['back_button'],'🔙 '],[achbotMessage('manual_payment.manual_crypto.button.1121ffbf88'),'🏠 ']]as[$value,$icon]){if(!str_starts_with($value,$icon))throw new RuntimeException('navigation_marker_mismatch');$contexts++;}
if(!str_starts_with(achbotMessage('wallet.wallet_transfer.wallet_transfer_restart.message.6924ce253f'),'⚠️ '))throw new RuntimeException('unknown_transfer_misleading_failure_marker');
echo json_encode(['violations'=>$violations,'topics'=>count(achbotMessageTopics()),'native_compositions'=>6,'emoji_negative_controls'=>7,'semantic_label_icon_cases'=>count($fields),'semantic_context_icon_cases'=>$contexts,'canonical_binding_paths'=>count($bindingProof['paths']),'canonical_binding_entries'=>$bindingProof['entries'],'canonical_binding_negative_controls'=>$bindingNegatives],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);'''
    native=json.loads(command('php','-r',php))
    check(not native['violations'],'adjacent emoji: '+str(native['violations']))
    check(native['topics']==26,'R5 instance-content topic missing or unexpected topic')
    # A mistaken cross-file move/copy must fail closed, never silently let
    # one audience override the other's value. Poison only an owned temporary
    # copy; the actual source and catalog remain untouched.
    with tempfile.TemporaryDirectory(prefix='achbot-style-loader-')as scratch:
        stage=pathlib.Path(scratch);(stage/'fa').mkdir()
        (stage/'loader.php').write_bytes((ROOT/'messages/loader.php').read_bytes())
        (stage/'fa/user.php').write_bytes((ROOT/'messages/fa/user.php').read_bytes())
        admin_source=(ROOT/'messages/fa/admin.php').read_text()
        marker="    'legacy_messages' => ["
        check(admin_source.count(marker)==1,'admin section fixture boundary drift')
        duplicate=admin_source.replace(marker,marker+"\n        'banned' => 'poisoned duplicate',",1)
        (stage/'fa/admin.php').write_text(duplicate)
        code='require '+json.dumps(str(stage/'loader.php'))+';try{achbotMessageTopics();echo "unsafe";}catch(UnexpectedValueException $e){echo $e->getMessage();}'
        check(command('php','-r',code).decode()=='message_key_duplicate_or_invalid','cross-audience duplicate silently accepted')
        (stage/'fa/admin.php').write_text(admin_source.replace(marker,"    '../private' => [",1))
        check(command('php','-r',code).decode()=='message_topic_invalid','invalid logical topic accepted')
    guarded=['messages/loader.php',*files]
    with socket.socket()as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
    listener=subprocess.Popen(['php','-S','127.0.0.1:'+str(port),'-t',str(ROOT)],cwd=ROOT,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    try:
        deadline=time.monotonic()+5
        while True:
            try:
                with socket.create_connection(('127.0.0.1',port),timeout=.2):break
            except OSError:
                check(time.monotonic()<deadline,'owned PHP listener unavailable');time.sleep(.05)
        for file in guarded:
            try:response=urllib.request.urlopen('http://127.0.0.1:'+str(port)+'/'+file);status=response.status;body=response.read()
            except urllib.error.HTTPError as error:status=error.code;body=error.read()
            check(status==404 and not body,'direct copy source leaked '+file)
    finally:listener.terminate();listener.wait(timeout=5)
    print(json.dumps({'status':'PASS','editable_files':2,'current_keys':len(all_merged),'management_added_keys':len(management['added_keys']),'management_retained_legacy_keys':len(management['retained_legacy_keys']),'management_baseline_keys':management['baseline_keys'],'management_negative_controls':management_controls,'owner_followup_changes':len(owner_changes),'owner_followup_negative_controls':owner_controls,'r5_changes':r5_proof['changes'],'r5_retired_keys':r5_proof['retired'],'r5_added_keys':r5_proof['added'],'r5_negative_controls':r5_controls,'management_facade_cases':management_facades,'management_provider_label_cases':provider_labels,'logical_topics':native['topics'],'canonical_binding_paths':native['canonical_binding_paths'],'canonical_binding_entries':native['canonical_binding_entries'],'canonical_binding_negative_controls':native['canonical_binding_negative_controls'],'style_changes':len(changes),'numeric_template_changes':sum('ascii_digits'in v['reasons']for v in changes.values()),'retired_keys':len(retired),'retired_feature_keys':len(feature_retired),'added_keys':len(added),'current_entry_assertions':assertions,'parameter_exception_count':len(param_keys),'adjacent_emoji_violations':0,'emoji_negative_controls':native['emoji_negative_controls'],'audience_admin_diagnostic_cases':len(admin_cases),'audience_customer_cases':len(user_cases),'preserved_shared_fragments':len(fragments),'native_compositions':native['native_compositions'],'semantic_label_icon_cases':native['semantic_label_icon_cases'],'semantic_context_icon_cases':native['semantic_context_icon_cases'],'direct_http_guards':len(guarded),'loader_negative_controls':2,**reviewed},ensure_ascii=False))
if __name__=='__main__':main()
