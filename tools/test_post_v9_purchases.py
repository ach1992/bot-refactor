#!/usr/bin/env python3
"""Current purchase routes vs immutable V9, with an explicit actor-owned plan-draft amendment."""
from __future__ import annotations
import argparse
import copy
import hashlib
import json
import re
import subprocess
from pathlib import Path

import test_access_state as owned
import test_v6_native as native
import test_v6_panels as panel_functions
import test_v8_native as purchase
import v9_contract as v9
import package_post_v9 as current_runtime

ROOT=Path(__file__).resolve().parents[1]
HISTORY='7eb077fd8e6d0a303421626d1500f69e295a6664'
# Catalogue/negative authorization retains exact V9 finance/protocol parity.
# Owner-approved copy changes are compared only as exact manifest pairs in
# recognized UI text arguments; all other event/SQL/provider fields stay exact.
# CREATE routes now intentionally differ: debit and capacity must commit
# before remote HTTP, and real effect ids + Telegram update evidence are
# mandatory. Never weaken frozen V9 itself; it is executed separately at
# the pinned historical checkout. The revised workflows are source-natively
# tested by wallet_creation, service_effects and nonpaid_routes in the FULL
# post-V9 aggregate, including actual PHP/MariaDB, no-replay and failure races.
CASES=['nonadmin_bulk_denied','invalid_category_no_purchase',
       'admin_category_create','admin_category_edit','admin_plan_price_edit',
       'admin_plan_create_draft','admin_category_manage','interval_plan_manage']
INTENTIONALLY_CHANGED=['customer_paid_wallet','agent_one_wallet',
       'agent_many_wallet','custom_paid_wallet','free_trial',
       'free_trial_replay_denied','admin_bulk_create',
       'provider_reject_free','provider_unavailable_free']


STYLE_BASELINE='7faac9b067f8a171e5e26d6aea5aaf9f4088a3e7'
UI_TEXT_ARGUMENTS={'sendMessage':0,'editText':1,'alert':0}
UI_MARKUP_ARGUMENTS={'sendMessage':1,'editText':2}

def authorized_ui_pairs() -> dict[tuple[str,str],list[str]]:
    """Validate literal display pairs against immutable and actual catalogs.

    Parameterized templates are deliberately ineligible: this comparator
    never guesses where a rendered amount, account or recipient came from.
    """
    manifest=json.loads((ROOT/'tools/text_catalog_style_amendments.json').read_text())
    if manifest.get('version')!=1 or manifest.get('baseline_commit')!=STYLE_BASELINE:
        raise RuntimeError('UI parity style baseline mismatch')
    literal={key:record for key,record in manifest['changes'].items()
             if record.get('parameters')==[] and record.get('before_parameters',[])==[]}
    topics=sorted({key.split('.',1)[0] for key in literal})
    archived={topic:subprocess.check_output(
        ['git','-C',str(ROOT),'show',STYLE_BASELINE+':messages/fa/'+topic+'.php']).decode()
        for topic in topics}
    # Only inert catalog arrays from pinned Git objects are evaluated. The
    # current loader imports its two public copy files, never private bootstrap.
    driver=r"""
require 'messages/loader.php';
$old=[];
foreach(json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR) as $topic=>$source) {
    $start=strpos($source,'return [');
    if($start===false)throw new RuntimeException('old_copy_definition_missing');
    $old[$topic]=eval(substr($source,$start));
}
echo json_encode(['old'=>$old,'current'=>achbotMessageCatalog()],JSON_THROW_ON_ERROR);
"""
    definitions=json.loads(subprocess.check_output(['php','-r',driver],cwd=ROOT,
        input=json.dumps(archived).encode()))
    followups=json.loads((ROOT/'tools/text_catalog_owner_followup.json').read_text())
    if followups.get('baseline_commit')!='c9959811e264b5a8728f6a44376cb69c9a89b7f4' or followups.get('result_commit')!='faa9aa6502a4e24152f1fa6faad7f0ab7921652e':
        raise RuntimeError('Owner copy followup identity mismatch')
    pairs={}
    for key,record in literal.items():
        topic,name=key.split('.',1)
        before=definitions['old'][topic][name];after=definitions['current'][topic][name]
        old_parameters=before.get('parameters',[]) if isinstance(before,dict) else []
        new_parameters=after.get('parameters',[]) if isinstance(after,dict) else []
        old_text=before['text'] if isinstance(before,dict) else before
        new_text=after['text'] if isinstance(after,dict) else after
        followup=followups['changes'].get(key)
        expected_after=record['after']
        if followup:
            if followup.get('before')!=expected_after or not followup.get('reason'):
                raise RuntimeError('Owner literal followup lineage mismatch: '+key)
            expected_after=followup['after']
        if (old_parameters or new_parameters or record['before']!=old_text
                or expected_after!=new_text or old_text==new_text
                or not isinstance(old_text,str) or not isinstance(new_text,str)
                or not record.get('reasons')):
            raise RuntimeError('Unverified literal UI parity pair: '+key)
        pairs.setdefault((old_text,new_text),[]).append(key)
    if not pairs:raise RuntimeError('No authorized literal UI pairs')
    return pairs

def project_reply_markup(before,after,pairs:dict) -> list[dict] | None:
    """Allow exact approved labels at inline_keyboard[row][button].text only.

    JSON encoding is compared structurally only after at least one verified
    label changes. All other markup values and list positions remain exact.
    """
    if type(before) is not type(after):return None
    def unique_object(items):
        result={}
        for key,value in items:
            if key in result:raise ValueError('duplicate reply-markup key')
            result[key]=value
        return result
    try:
        old=json.loads(before,object_pairs_hook=unique_object) if isinstance(before,str) else before
        new=json.loads(after,object_pairs_hook=unique_object) if isinstance(after,str) else after
    except (ValueError,TypeError):return None
    if not isinstance(old,dict) or not isinstance(new,dict):return None
    old_rows=old.get('inline_keyboard');new_rows=new.get('inline_keyboard')
    if not isinstance(old_rows,list) or not isinstance(new_rows,list) or len(old_rows)!=len(new_rows):
        return None
    projected=copy.deepcopy(old);changes=[]
    for row_index,(old_row,new_row) in enumerate(zip(old_rows,new_rows)):
        if not isinstance(old_row,list) or not isinstance(new_row,list) or len(old_row)!=len(new_row):
            return None
        for button_index,(old_button,new_button) in enumerate(zip(old_row,new_row)):
            if not isinstance(old_button,dict) or not isinstance(new_button,dict):return None
            old_text=old_button.get('text');new_text=new_button.get('text')
            if old_text==new_text:continue
            if not isinstance(old_text,str) or not isinstance(new_text,str):return None
            keys=pairs.get((old_text,new_text))
            if keys is None:return None
            projected['inline_keyboard'][row_index][button_index]['text']=new_text
            changes.append({'field_path':['inline_keyboard',row_index,button_index,'text'],
                            'catalog_keys':keys})
    return changes if changes and projected==new else None

def project_ui_observation(before:dict,after:dict,pairs:dict) -> tuple[bool,list[dict]]:
    """Project recognized complete text arguments and verified button labels."""
    if before==after:return True,[]
    projected=copy.deepcopy(before);changes=[]
    old_events=projected.get('events');new_events=after.get('events')
    if not isinstance(old_events,list) or not isinstance(new_events,list) or len(old_events)!=len(new_events):
        return False,[]
    for index,(old,new) in enumerate(zip(old_events,new_events)):
        if not isinstance(old,dict) or not isinstance(new,dict) or old.get('name')!=new.get('name'):
            return False,[]
        args=old.get('args');new_args=new.get('args')
        if not isinstance(args,list) or not isinstance(new_args,list) or len(args)!=len(new_args):
            return False,[]
        slot=UI_TEXT_ARGUMENTS.get(old.get('name'))
        if slot is not None and slot<len(args) and args[slot]!=new_args[slot]:
            old_text=args[slot];new_text=new_args[slot]
            keys=pairs.get((old_text,new_text)) if isinstance(old_text,str) and isinstance(new_text,str) else None
            if keys is not None:
                args[slot]=new_text
                changes.append({'event_index':index,'event_name':old['name'],
                                'argument_index':slot,'field_path':[],'catalog_keys':keys})
        markup_slot=UI_MARKUP_ARGUMENTS.get(old.get('name'))
        if markup_slot is not None and markup_slot<len(args) and args[markup_slot]!=new_args[markup_slot]:
            markup_changes=project_reply_markup(args[markup_slot],new_args[markup_slot],pairs)
            if markup_changes is not None:
                args[markup_slot]=copy.deepcopy(new_args[markup_slot])
                changes.extend({'event_index':index,'event_name':old['name'],
                                'argument_index':markup_slot,**change} for change in markup_changes)
    return (True,changes) if projected==after else (False,[])

def compare_native_runs(before:dict,after:dict,old_wire,new_wire,old_sql,new_sql,pairs:dict):
    if old_wire!=new_wire or old_sql!=new_sql:return False,[]
    return project_ui_observation(before,after,pairs)

def ui_projection_negative_controls(pairs:dict) -> list[str]:
    control_key='plans.administration.edit_custom_plan.message.06d5c646c1'
    selected=[pair for pair,keys in pairs.items() if control_key in keys]
    if len(selected)!=1:raise RuntimeError('Observed plan-label control missing')
    old_text,new_text=selected[0]
    before={'events':[
        {'name':'sendMessage','args':[old_text,{'inline_keyboard':[[
            {'text':'Proceed','callback_data':'confirm_100'}]]},'HTML',1001,None]},
        {'name':'setUser','args':['none','step']}],
        'warnings':[],'stdout':'','returned':True,'exception':None}
    after=copy.deepcopy(before);after['events'][0]['args'][0]=new_text
    wire=[{'method':'fixture','amount':100}]
    sql={'wallet':'1001\t50000','step':'none'}
    initial=copy.deepcopy(before)
    allowed,fields=compare_native_runs(before,after,wire,wire,sql,sql,pairs)
    if not allowed or len(fields)!=1 or before!=initial:
        raise RuntimeError('Exact authorized UI projection control failed')
    tests=[]
    for name in ['changed_amount','changed_recipient','changed_callback','changed_state',
                 'unrecorded_wording','changed_provider_wire','changed_method','changed_parse_mode']:
        candidate=copy.deepcopy(after);new_wire=copy.deepcopy(wire);new_sql=copy.deepcopy(sql)
        if name=='changed_amount':new_sql['wallet']='1001\t49000'
        elif name=='changed_recipient':candidate['events'][0]['args'][3]=1002
        elif name=='changed_callback':candidate['events'][0]['args'][1]['inline_keyboard'][0][0]['callback_data']='confirm_101'
        elif name=='changed_state':candidate['events'][1]['args'][0]='pending'
        elif name=='unrecorded_wording':candidate['events'][0]['args'][0]+=' unrecorded wording'
        elif name=='changed_provider_wire':new_wire[0]['amount']=101
        elif name=='changed_method':candidate['events'][0]['name']='sendToAllAdmins'
        elif name=='changed_parse_mode':candidate['events'][0]['args'][2]=None
        if compare_native_runs(before,candidate,wire,new_wire,sql,new_sql,pairs)[0]:
            raise RuntimeError('UI parity projection hid '+name)
        tests.append(name)
    # Identical-looking text in a persisted-state argument is never a UI slot.
    state_before={'events':[{'name':'setUser','args':[old_text,'step']}]}
    state_after={'events':[{'name':'setUser','args':[new_text,'step']}]}
    if project_ui_observation(state_before,state_after,pairs)[0]:
        raise RuntimeError('UI parity projection normalized persisted state')
    tests.append('authorized_text_in_non_ui_state_slot')
    # Exercise the real JSON reply-markup shape, plus the supported dict form.
    label_pairs=[pair for pair,keys in pairs.items() if pair[0]=='افزودن پلن زمانی جدید']
    if len(label_pairs)!=1:raise RuntimeError('Observed interval button control missing')
    old_label,new_label=label_pairs[0]
    old_markup={'inline_keyboard':[[{'text':old_label,'callback_data':'addNewDayPlan'}],
                                  [{'text':'fixture-button','callback_data':'backplan'}]]}
    new_markup=copy.deepcopy(old_markup);new_markup['inline_keyboard'][0][0]['text']=new_label
    for encoded in [False,True]:
        old_arg=json.dumps(old_markup) if encoded else old_markup
        new_arg=json.dumps(new_markup) if encoded else new_markup
        old_run={'events':[{'name':'editText','args':[71,'same title',old_arg]}]}
        new_run={'events':[{'name':'editText','args':[71,'same title',new_arg]}]}
        snapshot=copy.deepcopy(old_run)
        allowed,fields=compare_native_runs(old_run,new_run,wire,wire,sql,sql,pairs)
        if not allowed or len(fields)!=1 or fields[0]['field_path']!=['inline_keyboard',0,0,'text'] or old_run!=snapshot:
            raise RuntimeError('Exact inline-button projection failed')
    old_run={'events':[{'name':'editText','args':[71,'same title',json.dumps(old_markup)]}]}
    for name in ['keyboard_callback_changed','keyboard_row_added','keyboard_button_added',
                 'keyboard_unknown_field_changed','keyboard_unrecorded_label',
                 'keyboard_dynamic_amount_changed','keyboard_label_pair_in_url',
                 'keyboard_duplicate_json_key']:
        candidate=copy.deepcopy(new_markup)
        if name=='keyboard_callback_changed':candidate['inline_keyboard'][0][0]['callback_data']='other'
        elif name=='keyboard_row_added':candidate['inline_keyboard'].append([])
        elif name=='keyboard_button_added':candidate['inline_keyboard'][0].append({'text':'extra','callback_data':'x'})
        elif name=='keyboard_unknown_field_changed':candidate['resize_keyboard']=True
        elif name=='keyboard_unrecorded_label':candidate['inline_keyboard'][0][0]['text']+=' unrecorded'
        elif name=='keyboard_dynamic_amount_changed':candidate['inline_keyboard'][1][0]['text']='10001 تومان'
        elif name=='keyboard_label_pair_in_url':candidate['inline_keyboard'][0][0]['url']=new_label
        encoded=json.dumps(candidate)
        if name=='keyboard_duplicate_json_key':
            encoded=encoded[:-1]+',"inline_keyboard":'+json.dumps(candidate['inline_keyboard'])+'}'
        new_run={'events':[{'name':'editText','args':[71,'same title',encoded]}]}
        if compare_native_runs(old_run,new_run,wire,wire,sql,sql,pairs)[0]:
            raise RuntimeError('UI parity projection hid '+name)
        tests.append(name)

    return tests


def stage_source(stage:Path,current:bool)->dict:
    stage.mkdir(parents=True,exist_ok=False)
    members = current_runtime.members() if current else v9.members()
    for rel in members:
        dest=stage/rel
        dest.parent.mkdir(parents=True,exist_ok=True)
        raw=(ROOT/rel).read_bytes() if current else subprocess.check_output(
            ['git','-C',str(ROOT),'show',HISTORY+':'+rel])
        dest.write_bytes(raw)
    if current:
        # Fragment fixture executes the real common provider seam too.
        for rel in ['src/Panel/ProviderRuntime.php','src/Panel/ProviderImport.php','src/Panel/ProviderAdmin.php','src/Panel/ProviderStorage.php','src/Panel/PasarGuard.php','src/Subscription/Presentation.php']:
            dest=stage/rel;dest.parent.mkdir(parents=True,exist_ok=True);dest.write_bytes((ROOT/rel).read_bytes())
    fixture=(ROOT/'tests/v7/native-purchase.php').read_text()
    if current:
        (stage/'presentation-under-test.php').write_bytes(panel_functions.function_bodies((ROOT/'config.php').read_bytes(),['buildSubscriptionPresentation']))
        # Production config loads the real Jalali/date helpers before admin redraws.
        # Bootstrap that candidate dependency here; the frozen V7 driver stays byte-identical.
        fixture=fixture.replace('<?php','<?php\nrequire_once __DIR__ . "/settings/jdf.php";\nrequire_once __DIR__ . "/presentation-under-test.php";',1)
    # Stage the same explicitly configured, synthetic bank destination for
    # immutable V9 and current source. Real manual checkout now requires it;
    # do not modify the pinned historical fixture or waive event parity.
    bank_anchor="$botUrl='http://localhost.invalid/';$botUsername='';$paymentKeys=[];"
    assert fixture.count(bank_anchor)==1
    fixture=fixture.replace(bank_anchor, "$botUrl='http://localhost.invalid/';"
        "$botUsername='';$paymentKeys=['bankAccount'=>'fixture-bank-account',"
        "'holderName'=>'fixture-bank-holder'];")
    if current:
        # The actual interval route now verifies a fresh private Telegram
        # callback and the persisted administrator. Supply that context ONLY
        # in this candidate fixture; the archived V7 driver stays untouched.
        interval_anchor='$linkIndices=[0];$hideConfigIfMany=false;$permissions=[];$update=[];'
        assert fixture.count(interval_anchor)==1
        fixture=fixture.replace(interval_anchor,interval_anchor+'''\nif (in_array((string)($spec['data']??''),['dayPlanSettings','volumePlanSettings'],true)) {\n    $update=json_decode(json_encode(['update_id'=>710001,'callback_query'=>[\n        'id'=>'fixture-current-interval','data'=>$data,'from'=>['id'=>$from_id,'is_bot'=>false],\n        'message'=>['message_id'=>$message_id,'chat'=>['id'=>$from_id,'type'=>'private']]\n    ]]));\n}\n''',1)
    needle='function getConnectionLink(...$args)'
    assert fixture.count(needle)==1
    fixture=fixture.replace(needle,"function achbotSupportsPanel($type) { return $type === 'marzban'; }\n"+needle)
    (stage/'native-purchase.php').write_text(fixture)
    (stage/'settings/temp.txt').write_text('3000-0')
    (stage/'baseInfo.php').write_text('<?php // fixture only\n')
    (stage/'private-marker.json').write_text('{"synthetic":"only"}\n')
    return {name:hashlib.sha256((stage/name).read_bytes()).hexdigest()
            for name in ['baseInfo.php','private-marker.json']}


def ddl(work:Path,env:dict,db:str,tables:list[str])->list[str]:
    return [re.sub(r' AUTO_INCREMENT=\d+','',
                   native.sql(work,env,'SHOW CREATE TABLE '+chr(96)+t+chr(96),db))
            for t in tables]


# Current-only request driver. The frozen V7 driver and V9 modules remain intact.
PLAN_DRAFT_PHP=r'''<?php
error_reporting(E_ALL);
set_error_handler(static function($n,$m){if($n===E_DEPRECATED)return true;throw new ErrorException($m,0,$n);});
$_SERVER['SCRIPT_FILENAME']=__FILE__;
require $argv[1].'/src/Purchase/Core.php';
require $argv[1].'/src/Admin/ManagementFlow.php';
$connection=new mysqli('localhost','root','',$argv[3],0,$argv[2]);$connection->set_charset('utf8mb4');
$spec=json_decode($argv[4],true,64,JSON_THROW_ON_ERROR);
$from_id=$admin=$chat_id=9001;$message_id=71;$data=$spec['data']??'';$text=$spec['text']??null;
$buttonValues=['cancel'=>'fixture-cancel'];$cancelKey='{"keyboard":[["fixture-cancel"]]}';$removeKeyboard='{"remove_keyboard":true}';
$sender=['id'=>9001];$message=['message_id'=>71,'chat'=>['id'=>9001,'type'=>'private'],'from'=>$sender,'text'=>$text??'old-bot-message'];
$frame=['update_id'=>$spec['source']];
if($data!=='')$frame['callback_query']=['id'=>'fixture-inline','from'=>$sender,'data'=>$data,'message'=>$message];
else$frame['message']=$message;
$update=json_decode(json_encode($frame));$userInfo=$connection->query('SELECT * FROM users WHERE userid=9001')->fetch_assoc();
$events=[];
function sendMessage(...$args){$GLOBALS['events'][]=['name'=>'sendMessage','args'=>$args];return(object)['ok'=>true];}
function delMessage(...$args){$GLOBALS['events'][]=['name'=>'delMessage','args'=>$args];}
try {
 $handled=achbotHandleManagement();
 $row=$connection->query('SELECT id,userid,step,temp FROM users WHERE userid=9001')->fetch_assoc();
 echo json_encode(['handled'=>$handled,'events'=>$events,'row'=>$row,'labels'=>[
  'intro'=>achbotManagementText('intro'),'title'=>achbotManagementText('plan_title'),'price'=>achbotManagementText('plan_price')
 ]],JSON_THROW_ON_ERROR);
}catch(Throwable$error){echo json_encode(['exception'=>$error->getMessage()],JSON_THROW_ON_ERROR);}
'''

def characterize_actor_plan_draft(work,env,old,current,provider,steps,records):
    old_db,new_db=purchase.SIDE
    frozen=(ROOT/'tests/v7/native-purchase.php').read_bytes()
    if frozen!=subprocess.check_output(['git','-C',str(ROOT),'show',HISTORY+':tests/v7/native-purchase.php']):
        raise RuntimeError('Frozen V7 native driver was changed')
    # Preserve the old Marzban scenario over the actual immutable V9 module.
    historical=[]
    for i,(kind,spec) in enumerate(steps):
        provider.trace.clear()
        result=purchase.run(work,env,old,old_db,kind,spec,modern=True)
        if provider.trace:raise RuntimeError('Historical plan draft called provider')
        historical.append(result)
        count=native.sql(work,env,'SELECT COUNT(*) FROM server_plans WHERE active=0',old_db).strip()
        if count!='1':raise RuntimeError('Historical global inactive-plan draft was not reproduced')
    if native.sql(work,env,'SELECT title FROM server_plans WHERE active=0',old_db).strip()!='Sample plan':
        raise RuntimeError('Historical plan title progression was not reproduced')
    # Current drafts must preserve both another administrator and an unrelated
    # inactive plan. Neither is deleted or used as a global shared scratch row.
    native.sql(work,env,"INSERT INTO server_plans(id,fileid,catid,server_id,inbound_id,acount,limitip,title,protocol,days,volume,type,price,descr,pic,active,step,date) VALUES(999,'other-draft',1,2,0,0,1,'OTHER ADMIN INACTIVE PLAN','',30,1,'ws',1,'preserved','',0,4,'fixture');UPDATE users SET step='other-admin-draft',temp='OTHER-ADMIN-PRIVATE-DRAFT' WHERE userid=9002",new_db)
    plans_before=native.sql(work,env,'SELECT * FROM server_plans ORDER BY id',new_db)
    others_before=native.sql(work,env,'SELECT * FROM users WHERE userid<>9001 ORDER BY id',new_db)
    financial_query='SELECT id,userid,wallet FROM users ORDER BY id;SELECT * FROM pays ORDER BY id;SELECT * FROM orders_list ORDER BY id;SELECT * FROM server_info ORDER BY id'
    financial_before=native.sql(work,env,financial_query,new_db)
    driver=work/'actual-plan-draft.php';driver.write_text(PLAN_DRAFT_PHP)
    nonce=None
    for i,(kind,spec) in enumerate(steps):
        request=dict(spec,source=700000+i)
        provider.trace.clear()
        run=subprocess.run(['php','-n','-d','extension=mysqlnd','-d','extension=mysqli','-d','extension=mbstring',
            '-d','open_basedir='+str(current)+':'+str(work),'-d','allow_url_fopen=0',
            '-d','display_errors=0','-d','log_errors=0',str(driver),str(current),str(work/'mysql.sock'),new_db,json.dumps(request)],
            cwd=current,env=env,capture_output=True,text=True,timeout=15)
        if run.returncode or run.stderr:raise RuntimeError('Current plan entry PHP failed: '+repr((run.returncode,run.stderr)))
        result=json.loads(run.stdout)
        if 'exception'in result or result.get('handled')is not True:
            raise RuntimeError('Current plan entry bypassed the real dispatcher: '+repr(result))
        draft=json.loads(result['row']['temp']);expected='title'if i==0 else'price'
        if draft['kind']!='plan'or draft['stage']!=expected or draft['source']!=request['source']or not re.fullmatch(r'[a-f0-9]{12}',draft['nonce']):
            raise RuntimeError('Current actor plan draft did not advance correctly')
        if nonce is not None and draft['nonce']!=nonce:raise RuntimeError('Current plan request lost its actor-owned nonce')
        nonce=draft['nonce']
        if i==1 and draft['values'].get('title')!='Sample plan':raise RuntimeError('Current actor-owned draft title missing')
        if result['row']['step']!='achbotManage|'+nonce+'|'+str(draft['rev']):
            raise RuntimeError('Current plan step is not bound to its own draft')
        messages=[event['args'][0]for event in result['events']if event['name']=='sendMessage']
        if result['labels'][expected]not in messages or(i==0 and result['labels']['intro']not in messages):
            raise RuntimeError('Current plan prompt not shown in the private journey')
        if native.sql(work,env,'SELECT * FROM server_plans ORDER BY id',new_db)!=plans_before:
            raise RuntimeError('Current per-actor draft mutated server_plans')
        if native.sql(work,env,'SELECT * FROM users WHERE userid<>9001 ORDER BY id',new_db)!=others_before:
            raise RuntimeError('Current per-actor draft changed another administrator')
        if native.sql(work,env,financial_query,new_db)!=financial_before or provider.trace:
            raise RuntimeError('Plan onboarding performed a financial or provider effect')
        records.append({'case':'admin_plan_create_draft','step':i,'kind':kind,
            'intentional_behavior_amendment':'actor_owned_plan_draft','historical_global_draft_executed':True,
            'current_private_frame_executed':True,'current_draft_stage':expected,
            'server_plans_unchanged':True,'other_admin_unchanged':True,'fake_provider_calls':0})
    return {'case':'admin_plan_create_draft','historical_v9_executed':True,
        'historical_global_inactive_draft_reproduced':True,'frozen_driver_unchanged':True,
        'frozen_driver_sha256':hashlib.sha256(frozen).hexdigest(),'actual_candidate_dispatcher':True,
        'candidate_private_fresh_frame':True,'actor_owned_draft_persisted':True,
        'no_candidate_server_plans_mutation':True,'unrelated_inactive_plan_preserved':True,
        'unrelated_admin_state_preserved':True,'financial_snapshot_unchanged':True,'provider_calls':0}


def main():
    ap=argparse.ArgumentParser()
    ap.add_argument('--run-disposable',action='store_true',required=True)
    ap.add_argument('--report',type=Path)
    args=ap.parse_args()
    if subprocess.check_output(['git','-C',str(ROOT),'rev-parse',HISTORY+'^{tree}'],text=True).strip()!='7da17725c1e7f0448119b7e41c8b11b21f3e093d':
        raise RuntimeError('Original V9 TREE drift')
    records=[];authorized_fields=[];behavior_amendments=[]
    pairs=authorized_ui_pairs()
    projection_controls=ui_projection_negative_controls(pairs)
    with owned.owned_database() as (work,env),native.owned_provider() as (provider,url):
        old=work/'original-v9'
        current=work/'marzban-only'
        private_old=stage_source(old,False)
        private_new=stage_source(current,True)
        tables=native.sql(work,env,'SHOW TABLES',purchase.SIDE[0]).splitlines()
        if len(tables)!=23:raise RuntimeError('Not original 23-table schema')
        initial={db:ddl(work,env,db,tables) for db in purchase.SIDE}
        if initial[purchase.SIDE[0]]!=initial[purchase.SIDE[1]]:
            raise RuntimeError('Initial DDL mismatch')
        for label in CASES:
            purchase.init(work,env,url)
            # Both original and current fixture DBs represent a configured
            # installation. Real current issuance validates the persisted
            # BOT_STATES row, never the PHP-only $botState snapshot.
            # Preserve both immutable source trees and 23-table DDL.
            for db in purchase.SIDE:
                native.sql(work,env,
                    "DELETE FROM setting WHERE type='BOT_STATES'; "
                    "INSERT INTO setting (type,value) VALUES ('BOT_STATES','{\"walletState\":\"on\",\"cartToCartState\":\"on\",\"giftCardState\":\"on\",\"manualUsdtState\":\"on\",\"nowPaymentWallet\":\"on\",\"nowPaymentOther\":\"on\",\"zarinpal\":\"on\",\"nextpay\":\"on\"}')",db)
            provider.mode={'provider_reject_free':'marzban-invalid',
                           'provider_unavailable_free':'marzban-503'}.get(label,'ok')
            steps=purchase.series()[label]
            if label=='admin_plan_create_draft':
                steps=[(kind,{**spec,'data':'addNewMarzbanPlan'} if spec.get('data')=='addNewPlan' else spec)
                       for kind,spec in steps]
                behavior_amendments.append(characterize_actor_plan_draft(work,env,old,current,provider,steps,records))
            for i,(kind,spec) in enumerate([] if label=='admin_plan_create_draft' else steps):
                observations=[];wires=[];states=[]
                for stage,db in ((old,purchase.SIDE[0]),(current,purchase.SIDE[1])):
                    provider.trace.clear()
                    result=purchase.run(work,env,stage,db,kind,spec,modern=True)
                    observations.append(result)
                    wires.append(list(provider.trace))
                    states.append(purchase.snapshot(work,env,db))
                equivalent,projected=compare_native_runs(
                    observations[0],observations[1],wires[0],wires[1],states[0],states[1],pairs)
                if not equivalent:
                    reason={'case':label,'step':i,'route':kind,
                            'events_equal':observations[0]==observations[1],
                            'wire_equal':wires[0]==wires[1],
                            'sql_equal':states[0]==states[1],
                            'old_event':observations[0],'new_event':observations[1]}
                    raise RuntimeError('Marzban source drift: '+json.dumps(reason,ensure_ascii=False)[:4000])
                if kind=='wallet' and provider.mode=='ok' and not wires[0]:
                    raise RuntimeError('Wallet action never reached fake provider')
                if kind=='trialprovision' and provider.mode!='ok' and not wires[0]:
                    raise RuntimeError('Provider rejection did not exercise error')
                authorized_fields.extend({'case':label,'step':i,'kind':kind,**field} for field in projected)
                records.append({'case':label,'step':i,'kind':kind,
                                'authorized_ui_field_differences':len(projected),
                                'fake_provider_calls':len(wires[0]),
                                'sql_sha256':hashlib.sha256(
                                    json.dumps(states[0],sort_keys=True).encode()).hexdigest()})
            if label!='admin_plan_create_draft':purchase.assert_outcome(work,env,label)
            for db in purchase.SIDE:
                if ddl(work,env,db,tables)!=initial[db]:
                    raise RuntimeError('Schema changed during '+label)
        for stage,private in ((old,private_old),(current,private_new)):
            for name,hashval in private.items():
                if hashlib.sha256((stage/name).read_bytes()).hexdigest()!=hashval:
                    raise RuntimeError('Private synthetic file changed')
    report={'complete':True,'historical_v9':HISTORY,'scenarios':len(CASES),
            'steps':len(records),'schema_tables':23,'mysql_socket_only':True,
            'php_sql_telegram_fake_provider_parity':False,
            'telegram_comparison':'seven_exact_style_scoped_cases_and_one_explicit_actor_draft_amendment',
            'sql_provider_wire_exact':False,
            'unchanged_differential_scenarios':len(CASES)-len(behavior_amendments),
            'unchanged_differential_sql_provider_wire_exact':True,
            'unchanged_differential_telegram_comparison':'exact_except_explicit_authorized_ui_text_fields',
            'intentional_behavior_amendments':behavior_amendments,
            'actor_owned_plan_draft_entry_verified':len(behavior_amendments)==1,
            'ui_projection_baseline':STYLE_BASELINE,
            'verified_literal_style_pairs':len(pairs),
            'authorized_ui_field_differences':len(authorized_fields),
            'authorized_ui_fields':authorized_fields,
            'ui_projection_negative_controls':len(projection_controls),
            'rejected_projection_mutations':projection_controls,
            'changed_paid_nonpaid_source_contracts':INTENTIONALLY_CHANGED,
            'new_source_contracts_mandatory_in_full_aggregate':True,
            'historical_original_v9_requires_separate_pinned_ci':True,
            'live_provider_or_real_money_tested':False,'details':records}
    if args.report:
        args.report.write_text(json.dumps(report,indent=2,ensure_ascii=False)+'\n')
    print(json.dumps({k:v for k,v in report.items() if k!='details'}))

if __name__=='__main__':
    main()
