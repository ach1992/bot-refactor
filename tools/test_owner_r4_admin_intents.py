#!/usr/bin/env python3
"""Actual plan admin routes: replay-safe sale actions and bound hard-delete sessions.

Source-real PHP / disposable MariaDB / both native-provider fixtures. No live effects.
"""
from __future__ import annotations
import argparse, json, re, subprocess
from pathlib import Path
import test_shared_plan_review_fixes as shared
import test_v6_panels as bodies

ROOT=Path(__file__).resolve().parents[1]
DRIVER=r'''<?php
error_reporting(E_ALL);
set_error_handler(static function($n,$s){if($n===E_DEPRECATED)return true;throw new ErrorException($s,0,$n);});
$_SERVER['SCRIPT_FILENAME']=__FILE__;
$root=$argv[1];$connection=new mysqli('localhost','root','',$argv[3],0,$argv[2]);
$connection->set_charset('utf8mb4');
$spec=json_decode($argv[4],true,64,JSON_THROW_ON_ERROR);
require_once $root.'/src/Purchase/Core.php';
require_once $root.'/src/Admin/IntentSafety.php';
require_once $root.'/src/Presentation/Text.php';
require_once __DIR__.'/plan-menu-facade.php';
$from_id=(int)($spec['actor']??9001);$admin=9001;$chat_id=$from_id;
$message_id=(int)($spec['message_id']??575);$data=(string)$spec['callback'];$text=null;
$update=json_decode(json_encode(['update_id'=>(int)$spec['source'],'callback_query'=>[
 'from'=>['id'=>$spec['sender']??$from_id],'data'=>$data,'message'=>[
  'message_id'=>$message_id,'chat'=>['id'=>$spec['chat_id']??$from_id,
  'type'=>$spec['chat_type']??'private']]]]));
$q=$connection->prepare('SELECT * FROM users WHERE userid=?');$q->bind_param('i',$from_id);$q->execute();
$userInfo=$q->get_result()->fetch_assoc()??['step'=>'none'];$q->close();
$buttonValues=['back_button'=>'Back','cancel'=>'Cancel'];$botState=[];
$events=[];
function editText($id,$msg,$keys=null,$parse=null){$GLOBALS['events'][]=['type'=>'edit','message'=>$id,'keys'=>$keys];return (object)['ok'=>true];}
function sendMessage(...$args){$GLOBALS['events'][]=['type'=>'send'];return (object)['ok'=>true];}
function alert($text,$modal=false){$GLOBALS['events'][]=['type'=>'alert'];}
function getMainKeys(){return '{}';}
function achbotSupportsPanel($type){return in_array($type,['marzban','pasarguard'],true);}
try {require $root.'/src/Plans/Administration.php';$error=null;}
catch(Throwable $e){$error=get_class($e).':'.$e->getMessage();}
echo json_encode(['error'=>$error,'events'=>$events],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
'''

def main():
    ap=argparse.ArgumentParser()
    ap.add_argument('--run-disposable',action='store_true',required=True)
    ap.add_argument('--report',type=Path)
    args=ap.parse_args();checks=[]
    def yes(ok,name):
        if not ok:raise AssertionError(name)
        checks.append(name)
    for provider in ('marzban','pasarguard'):
        with shared.harness(provider) as h:
            work=h['work'];env=h['env'];db=h['db'];id=7
            db(f'UPDATE server_plans SET active=1 WHERE id={id}')
            facade=bodies.function_bodies((ROOT/'config.php').read_bytes(),['getPlanDetailsKeys'])
            (work/'plan-menu-facade.php').write_bytes(facade.replace(b'__DIR__',repr(str(ROOT)).encode()))
            driver=work/'plan-admin-driver.php';driver.write_text(DRIVER)
            seq=900000
            def call(callback,**extra):
                nonlocal seq
                seq+=1
                data=dict(source=seq,actor=9001,message_id=575,callback=callback)
                data.update(extra)
                run=subprocess.run(['php','-d','display_errors=1','-d','log_errors=1',
                    str(driver),str(ROOT),str(work/'mysql.sock'),shared.DB,json.dumps(data)],
                    cwd=work,env=env,capture_output=True,text=True,timeout=25)
                if run.returncode or any(line not in ('achbot_admin_plan_visibility_deferred','achbot_admin_plan_delete_warning_deferred','achbot_admin_plan_delete_deferred') for line in run.stderr.splitlines()):
                    raise AssertionError(('php-admin-plan',provider,run.returncode,run.stderr))
                result=json.loads(run.stdout)
                if result['error']:raise AssertionError((provider,result))
                return result
            def buttons(reply):
                for event in reply['events']:
                    if event['type']=='edit' and event.get('keys'):
                        for row in json.loads(event['keys']).get('inline_keyboard',[]):
                            for button in row:yield button.get('callback_data','')
            def menu():
                return next(x for x in buttons(call('planDetails7')) if x.startswith('setPlanSale7_'))
            def state():return db('SELECT active FROM server_plans WHERE id=7')
            def nochange(reply):return not any(e['type']=='edit' for e in reply['events'])
            writes=len(h['writes']());finance=db('SELECT * FROM pays ORDER BY id')
            first=menu();yes(re.fullmatch(r'setPlanSale7_0_[a-f0-9]{16}',first),provider+'_explicit_hide_callback')
            call(first);yes(state()=='0',provider+'_hide_committed')
            yes(nochange(call(first)) and state()=='0',provider+'_hide_replay_does_not_reenable')
            second=menu();yes(re.fullmatch(r'setPlanSale7_1_[a-f0-9]{16}',second),provider+'_explicit_reenable_callback')
            call(second);yes(state()=='1',provider+'_reenable_committed')
            yes(nochange(call(second)) and state()=='1',provider+'_reenable_replay_does_not_hide')
            yes(nochange(call(first)) and state()=='1',provider+'_old_hide_button_cannot_undo_newer_action')
            third=menu();yes(third!=first,provider+'_version_rotation_prevents_aba')
            yes(nochange(call(third,actor=9002)) and state()=='1',provider+'_plan_state_wrong_capability_denied')
            yes(nochange(call('togglePlanState7')) and state()=='1',provider+'_retired_toggling_callback_inert')
            def warning():
                reply=call('wizwizplandelete7')
                confirmation=next(x for x in buttons(reply) if x.startswith('confirmDeletePlan7_'))
                cancel=next(x for x in buttons(reply) if x.startswith('cancelDeletePlan7_'))
                yes(cancel==confirmation.replace('confirmDeletePlan','cancelDeletePlan'),
                    provider+'_plan_warning_back_is_bound_cancellation')
                return confirmation
            canceled=warning()
            result=call(canceled.replace('confirmDeletePlan','cancelDeletePlan'))
            yes(any(e['type']=='edit' for e in result['events']) and state()=='1',
                provider+'_plan_back_cancels_pending_warning')
            yes(nochange(call(canceled)) and state()=='1',
                provider+'_plan_old_warning_after_back_denied')
            old=warning();yes(re.fullmatch(r'confirmDeletePlan7_[a-f0-9]{16}',old),provider+'_plan_delete_nonce_issued')
            yes(nochange(call(old,message_id=576)) and state()=='1',provider+'_plan_delete_wrong_message_denied')
            newer=warning();yes(nochange(call(old)) and state()=='1',provider+'_plan_delete_old_warning_denied')
            db("UPDATE server_plans SET title=CONCAT(title,' edited') WHERE id=7")
            yes(nochange(call(newer)) and state()=='1',provider+'_plan_delete_after_edit_denied')
            valid=warning()
            yes(nochange(call(valid,actor=9002)) and state()=='1',provider+'_plan_delete_wrong_actor_denied')
            yes(nochange(call('confirmDeletePlan7')) and state()=='1',provider+'_legacy_plan_delete_callback_inert')
            call(valid);yes(db('SELECT COUNT(*) FROM server_plans WHERE id=7')=='0',provider+'_plan_local_hard_delete_committed')
            yes(nochange(call(valid)) and db('SELECT COUNT(*) FROM server_plans WHERE id=7')=='0',provider+'_plan_delete_replay_denied')
            yes(db('SELECT * FROM pays ORDER BY id')==finance and len(h['writes']())==writes,
                provider+'_admin_operations_have_no_financial_or_native_effects')
    report=dict(complete=True,cases=len(checks),checks=checks,actual_php=True,
        owned_mariadb=True,providers=['marzban','pasarguard'],external_calls=0,production_mutations=0)
    if args.report:args.report.write_text(json.dumps(report,ensure_ascii=False,indent=2)+'\n')
    print(json.dumps({k:v for k,v in report.items() if k!='checks'}))
if __name__=='__main__':main()
