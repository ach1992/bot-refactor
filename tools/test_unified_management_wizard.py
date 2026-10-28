#!/usr/bin/env python3
"""AChBot unified wizard acceptance with owned socket SQL and loopback providers.

Exercises current PHP controllers, persisted drafts, actual cURL admission and
transactional inserts. No production configuration or external service is used.
"""
from __future__ import annotations
import argparse, contextlib, copy, json, re, subprocess, threading
from concurrent.futures import ThreadPoolExecutor
from http.server import ThreadingHTTPServer
from pathlib import Path
from urllib.parse import parse_qs, urlsplit
import test_access_state as owned
import test_v6_native as native
import test_v8_native as purchase
import test_post_v9_pasarguard_client as pg

ROOT=Path(__file__).resolve().parents[1]
DB=purchase.SIDE[1]
class Handler(pg.Handler):
    def handle_request(self):
        path=urlsplit(self.path).path
        if path.startswith('/mb/'):
            n=int(self.headers.get('Content-Length','0'))
            body=self.rfile.read(n)
            self.server.trace.append((self.command,self.path,body.decode()))
            status=200
            if path=='/mb/api/admin/token' and self.command=='POST':
                values=parse_qs(body.decode())
                if values!={'username':['fixture-admin'],'password':['fixture-password']} or self.server.mb_deny:
                    status=401;value={'detail':'synthetic_denied'}
                else:value={'access_token':'fixture-token'}
            elif path=='/mb/api/core/config' and self.command=='GET':
                assert self.headers.get('Authorization')=='Bearer fixture-token'
                value={'inbounds':copy.deepcopy(self.server.mb_hosts)}
            else:
                status=404;value={'detail':'fixture_unknown_route'}
            raw=json.dumps(value).encode()
            self.send_response(status);self.send_header('Content-Type','application/json')
            self.send_header('Content-Length',str(len(raw)));self.end_headers();self.wfile.write(raw)
            return
        return super().handle_request()

@contextlib.contextmanager
def panels():
    s=ThreadingHTTPServer(('127.0.0.1',0),Handler)
    s.trace=[];s.user=copy.deepcopy(pg.USER);s.mode='ok';s.write_count=0;s.links_requested=False
    s.mb_deny=False;s.mb_hosts=[{'tag':'fixture-vless','protocol':'vless'},{'tag':'fixture-trojan','protocol':'trojan'}]
    t=threading.Thread(target=s.serve_forever,daemon=True);t.start()
    try:yield s,'http://127.0.0.1:'+str(s.server_address[1])
    finally:s.shutdown();t.join(5);s.server_close()

PHP=r'''<?php
error_reporting(E_ALL);
set_error_handler(static function($n,$m){if($n===E_DEPRECATED)return true;throw new ErrorException($m,0,$n);});
$_SERVER['SCRIPT_FILENAME']=__FILE__;
define('ACHBOT_PG_TEST_LOOPBACK',true);
require $argv[1].'/src/Purchase/Core.php';
require $argv[1].'/src/Panel/Accounts.php';
require $argv[1].'/src/Panel/ProviderAdmin.php';
require $argv[1].'/src/Admin/ManagementFlow.php';
function achbotSupportsPanel($type){return in_array($type,['marzban','pasarguard'],true);}
function getMarzbanHosts($id){return \AchBot\Panel\Accounts\getMarzbanHosts($id);}
function getServerListKeys(...$args){return json_encode(['inline_keyboard'=>[[['text'=>'fixture-server-list','callback_data'=>'serversSetting']]]]);}
function getServerConfigKeys($id,...$args){return json_encode(['inline_keyboard'=>[[['text'=>'fixture-server-'.$id,'callback_data'=>'showServerSettings'.$id.'_0']]]]);}
function getPlanDetailsKeys($id,...$args){return json_encode(['inline_keyboard'=>[[['text'=>'fixture-plan-'.$id,'callback_data'=>'planDetails'.$id]]]]);}
$GLOBALS['events']=[];
function sendMessage(...$args){$GLOBALS['events'][]=['body'=>$args[0]??'','keys'=>$args[1]??null,'parse'=>$args[2]??null];return (object)['ok'=>true];}
function delMessage(...$args){$GLOBALS['events'][]=['deleted'=>true];}
function alert(...$args){$GLOBALS['events'][]=['alert'=>$args[0]??''];}
function setUser($value=null,$field='step'){
 global $connection,$from_id;
 $s=$connection->prepare('UPDATE users SET step=? WHERE userid=?');$step=$value??'none';
 $s->bind_param('si',$step,$from_id);$s->execute();$s->close();
}
class FixtureDb extends mysqli {
 public bool $breakCommit=false;
 public function commit(int $flags=0,?string $name=null): bool {
  if($this->breakCommit) {parent::commit($flags,$name);throw new RuntimeException('fixture_commit_ack_lost');}
  return parent::commit($flags,$name);
 }
}
$connection=new FixtureDb('localhost','root','',$argv[3],0,$argv[2]);$connection->set_charset('utf8mb4');
$connection->query("SET SESSION sql_mode='STRICT_TRANS_TABLES'");
$spec=json_decode($argv[4],true,64,JSON_THROW_ON_ERROR);
$from_id=(int)($spec['actor']??9001);$admin=9001;$chat_id=(int)($spec['chat_id']??$from_id);$message_id=1;
$data=$spec['callback']??'';$text=$spec['text']??null;$source=(int)$spec['source'];
$sender=['id'=>$spec['sender']??$from_id];
$message=['message_id'=>1,'chat'=>['id'=>$chat_id,'type'=>$spec['chat_type']??'private'],'from'=>$sender,'text'=>$text??'cached-message'];
$wire=['update_id'=>$source];
if($data!=='') $wire['callback_query']=['id'=>'fixture-callback','from'=>$sender,'data'=>$data,'message'=>$message];
else $wire['message']=$message;
$update=json_decode(json_encode($wire));
$s=$connection->prepare('SELECT * FROM users WHERE userid=?');$s->bind_param('i',$from_id);$s->execute();
$userInfo=$s->get_result()->fetch_assoc()??['step'=>'none'];$s->close();
if(!empty($spec['cached_admin']))$userInfo['isAdmin']=1;
$buttonValues=['cancel'=>'fixture-cancel','back_button'=>'fixture-back'];$cancelKey='{"keyboard":[["fixture-cancel"]],"resize_keyboard":true}';
$removeKeyboard='{"remove_keyboard":true}';$connection->breakCommit=!empty($spec['commit_ack_lost']);
try {
 $handled=!empty($spec['field'])?achbotHandleManagementFields():achbotHandleManagement();
 $s=$connection->prepare('SELECT step,temp FROM users WHERE userid=?');$s->bind_param('i',$from_id);$s->execute();
 $state=$s->get_result()->fetch_assoc();$s->close();
 echo json_encode(['handled'=>$handled,'events'=>$GLOBALS['events'],'state'=>$state,'cached_step'=>$userInfo['step']??null,'review_label'=>achbotManagementText('review_required')],JSON_THROW_ON_ERROR);
} catch(Throwable $error){echo json_encode(['exception'=>$error->getMessage()],JSON_THROW_ON_ERROR);}
'''

LEGACY_CANCEL_PHP=r'''<?php
error_reporting(E_ALL);set_error_handler(static function($n,$m){if($n===E_DEPRECATED)return true;throw new ErrorException($m,0,$n);});
$_SERVER['SCRIPT_FILENAME']=__FILE__;
require $argv[1].'/src/Purchase/Core.php';
require $argv[1].'/src/Subscription/DirectGift.php';
require $argv[4];
$connection=new mysqli('localhost','root','',$argv[3],0,$argv[2]);$connection->set_charset('utf8mb4');
$from_id=$admin=$chat_id=9001;$message_id=1;$data='';$text='fixture-cancel';$buttonValues=['cancel'=>$text];$cancelKey='{}';$removeKeyboard='{"remove_keyboard":true}';
$connection->query("UPDATE users SET step='pg:server:1',temp=NULL WHERE userid=9001");
$userInfo=$connection->query('SELECT * FROM users WHERE userid=9001')->fetch_assoc();
$update=json_decode(json_encode(['update_id'=>99999,'message'=>['message_id'=>1,'chat'=>['id'=>9001,'type'=>'private'],'from'=>['id'=>9001],'text'=>$text]]));
$events=[];
function sendMessage(...$args){$GLOBALS['events'][]=$args;return (object)['ok'=>true];}
function alert(...$args){$GLOBALS['events'][]=$args;}
function getServerListKeys(...$args){return '{}';}
function setUser($value=null,$field='step') {global $connection,$from_id;$v=$value??'none';$s=$connection->prepare('UPDATE users SET step=? WHERE userid=?');$s->bind_param('si',$v,$from_id);$s->execute();$s->close();}
$handled=achbotHandleProviderAdmin();$fallback=$handled?null:achbotDirectGiftGuardLegacyCancel($connection,$from_id,$userInfo);
echo json_encode(['handled'=>$handled,'fallback'=>$fallback,'events'=>count($events),'step'=>$connection->query('SELECT step FROM users WHERE userid=9001')->fetch_assoc()['step']],JSON_THROW_ON_ERROR);
'''

def main():
    p=argparse.ArgumentParser()
    p.add_argument('--run-disposable',action='store_true',required=True)
    p.add_argument('--report',type=Path)
    a=p.parse_args();checks=[];sequence=100
    def yes(condition,name,detail=None):
        if not condition:raise AssertionError((name,detail))
        checks.append(name)
    with owned.owned_database() as (work,env),panels() as (server,url):
        purchase.init(work,env,url+'/mb')
        def sql(query):return native.sql(work,env,query,DB)
        for name in ['001_marzban_panel_group_id.sql','004_automatic_renewal_attempts.sql','007_service_effects.sql','011_provider_identity.sql']:
            sql((ROOT/'migrations/post-v9'/name).read_text())
        # A historical inactive draft belongs to nobody in the new workflow.
        sql("INSERT INTO server_plans(fileid,catid,server_id,inbound_id,acount,title,protocol,days,volume,type,price,descr,pic,active,step,date) VALUES('orphan',1,2,0,0,'SENTINEL-INACTIVE','',30,1,'ws',1,'sentinel','',0,4,'fixture')")
        script=work/'unified-wizard.php';script.write_text(PHP)
        def call(**spec):
            nonlocal sequence
            sequence+=1;spec.setdefault('source',sequence)
            result=subprocess.run(['php','-d','display_errors=0','-d','log_errors=0',str(script),str(ROOT),str(work/'mysql.sock'),DB,json.dumps(spec)],capture_output=True,text=True,timeout=30)
            if result.returncode or any(not re.fullmatch(r'achbot_management(?:_field)?_deferred reason=[a-z_]+',line) for line in result.stderr.splitlines()):raise AssertionError(('php-child',result.returncode,result.stderr[:1200]))
            data=json.loads(result.stdout)
            if 'exception'in data:raise AssertionError(data)
            return data
        def draft(result):
            state=result['state'];return json.loads(state['temp']) if state and state['step'].startswith('achbotManage|') else None
        def stage(result,name):yes(draft(result) is not None and draft(result)['stage']==name,'stage_'+name)
        def action(result,name,value='0',**spec):
            d=draft(result);assert d is not None
            return call(callback='am|'+d['nonce']+'|'+str(d['rev'])+'|'+name+'|'+str(value),**spec)
        def text(result,value,**spec):return call(text=value,**spec)
        def cancel(result,**spec):
            r=call(text='fixture-cancel',**spec)
            yes(r['handled'] and draft(r)is None and r['state']['step']=='none' and r['state']['temp']is None,'one_press_cancel')
            yes(any(e.get('keys')=='{"remove_keyboard":true}' for e in r['events']),'cancel_removes_reply_keyboard')
            return r
        def counts():return sql("SELECT (SELECT COUNT(*) FROM server_info),(SELECT COUNT(*) FROM server_config),(SELECT COUNT(*) FROM server_plans),(SELECT COUNT(*) FROM provider_panels)")
        def rows():return sql("SELECT id,title,ucount,remark,flag,active,provider_panel_id,provider_group_ids FROM server_info ORDER BY id;SELECT id,type,panel_url FROM server_config ORDER BY id;SELECT id,server_id,title,active,custom_sni,provider_group_ids FROM server_plans ORDER BY id")
        def no_secrets(r):
            shown=json.dumps(r['events'],ensure_ascii=False)
            yes('fixture-password'not in shown and 'fixture-private-key'not in shown,'credentials_not_echoed')
        # Single cancel from entry + every ordinary server stage.
        r=call(callback='addManagedServer');stage(r,'provider');cancel(r)
        r=call(callback='addManagedPlan');stage(r,'title');cancel(r)
        for provider in ['marzban','pasarguard']:
            r=call(callback='addManagedServer');r=action(r,'provider',provider);stage(r,'title')
            snap=counts()
            for name,value in [('title','Fixture '+provider),('capacity','20'),('remark','newfixture'),('flag','FIXTURE'),('url',url+('/mb'if provider=='marzban'else''))]:
                stage(r,name)
                current=r;cancel(current)
                r=call(callback='addManagedServer');r=action(r,'provider',provider)
                for prior,pvalue in [('title','Fixture '+provider),('capacity','20'),('remark','newfixture'),('flag','FIXTURE'),('url',url+('/mb'if provider=='marzban'else''))]:
                    if prior==name:break
                    r=text(r,pvalue)
                r=text(r,value)
            yes(counts()==snap,'cancel_no_partial_'+provider)
            if provider=='marzban':
                stage(r,'username');r=text(r,'fixture-admin');stage(r,'password')
                r=text(r,'fixture-password');stage(r,'panelgroup');no_secrets(r)
                yes(any(e.get('deleted')for e in r['events']),'password_message_deleted')
                r=action(r,'group','0');stage(r,'review')
            else:
                stage(r,'api_key');r=text(r,'fixture-private-key');stage(r,'groups');no_secrets(r)
                yes(any(e.get('deleted')for e in r['events']),'api_key_message_deleted')
                r=action(r,'pick','0');r=action(r,'done');stage(r,'review')
            before=counts();save_callback='am|'+draft(r)['nonce']+'|'+str(draft(r)['rev'])+'|save|0'
            r=call(callback=save_callback);yes(draft(r)is None,'server_confirmed_'+provider)
            after=counts();yes(before!=after,'server_inserted_'+provider)
            server_id=int(sql("SELECT MAX(id) FROM server_info").strip())
            yes(sql("SELECT type FROM server_config WHERE id="+str(server_id)).strip()==provider,'server_native_provider_'+provider)
            replay=call(callback=save_callback);yes(counts()==after,'server_confirmation_replay_no_duplicate_'+provider)
            yes(draft(replay)is None,'completed_callback_does_not_restart_'+provider)
            no_secrets(r)
            if provider=='marzban':mb_id=server_id
            else:pg_id=server_id
        # Input, actor, callback revision, request-order, Back.
        r=call(callback='addManagedServer');r=action(r,'provider','pasarguard');r=text(r,'Another')
        stage(r,'capacity');old=draft(r);before=counts()
        r_bad=text(r,'0');yes(draft(r_bad)==old and counts()==before,'invalid_capacity_preserves_draft')
        forged=call(callback='am|'+old['nonce']+'|999|cancel|0')
        yes(draft(forged)==old,'forged_revision_cannot_cancel')
        r=action(r,'back');stage(r,'title')
        stale=call(callback='am|'+old['nonce']+'|'+str(old['rev'])+'|back|0')
        yes(draft(stale)==draft(r),'stale_callback_cannot_rewind')
        out_of_order=call(text='Old title',source=old['source'])
        yes(draft(out_of_order)==draft(r),'old_update_cannot_advance')
        cross=action(r,'cancel',actor=9002)
        yes(draft(cross)is None,'cross_actor_has_no_foreign_draft')
        current=call(text='Renamed')
        yes(draft(current)['stage']=='capacity','owner_draft_survives_cross_actor')
        cancel(current)
        r=call(callback='addManagedServer');d=draft(r)
        nonprivate=call(text='fixture-cancel',chat_type='group');yes(draft(nonprivate)==d,'group_message_cannot_cancel')
        foreign=call(text='fixture-cancel',sender=9002);yes(draft(foreign)==d,'foreign_sender_cannot_cancel')
        cancel(r)
        sql("UPDATE users SET isAdmin=0 WHERE userid=9002")
        before=counts();denied=call(callback='addManagedServer',actor=9002,cached_admin=True)
        yes(draft(denied)is None and counts()==before,'revoked_admin_cached_role_denied')
        sql("UPDATE users SET isAdmin=1 WHERE userid=9002")
        # Concurrent admins own independent drafts; one cancellation never deletes the other.
        r1=call(callback='addManagedPlan');r2=call(callback='addManagedPlan',actor=9002)
        d2=draft(r2);cancel(r1)
        check2=call(text='Delegate plan',actor=9002)
        yes(draft(check2)['values']['title']=='Delegate plan' and draft(check2)['nonce']==d2['nonce'],'concurrent_admin_draft_independent')
        cancel(check2,actor=9002)
        # Both provider plan flows use only their own native entitlement controls.
        def plan_to_targets(provider,server_id,actor=9001):
            r=call(callback='addManagedPlan',actor=actor);stage(r,'title')
            for expected,value in [('title','Plan '+provider),('price','123000')]:
                stage(r,expected);r=call(text=value,actor=actor)
            stage(r,'category');r=action(r,'category','1',actor=actor)
            stage(r,'server');r=action(r,'server',str(server_id),actor=actor)
            for expected,value in [('days','30'),('volume','5'),('description','Visible fixture description')]:
                stage(r,expected);r=call(text=value,actor=actor)
            stage(r,'targets');return r
        for provider,server_id in [('marzban',mb_id),('pasarguard',pg_id)]:
            r=plan_to_targets(provider,server_id)
            d=draft(r)
            yes(d['values']['provider']==provider,'plan_matches_server_'+provider)
            yes(all(('protocol'in c)==(provider=='marzban')for c in d['choices']),'native_target_choices_'+provider)
            r=action(r,'pick','0');r=action(r,'done');stage(r,'review');no_secrets(r)
            before=counts();save='am|'+draft(r)['nonce']+'|'+str(draft(r)['rev'])+'|save|0'
            r=call(callback=save);yes(draft(r)is None and counts()!=before,'plan_confirmed_'+provider)
            saved=sql("SELECT custom_sni,IFNULL(provider_group_ids,'NULL') FROM server_plans ORDER BY id DESC LIMIT 1").strip()
            yes(('fixture-vless'in saved)==(provider=='marzban') and ('[2]'in saved)==(provider=='pasarguard'),'plan_native_entitlement_persisted_'+provider)
            snapshot=counts();call(callback=save);yes(counts()==snapshot,'plan_save_replay_no_duplicate_'+provider)
        r=plan_to_targets('pasarguard',pg_id);r=action(r,'inherit');stage(r,'review')
        r=action(r,'save');yes(draft(r)is None and sql("SELECT IFNULL(provider_group_ids,'NULL') FROM server_plans ORDER BY id DESC LIMIT 1").strip()=='NULL','plan_inherits_groups')
        yes(sql("SELECT COUNT(*) FROM server_plans WHERE title='SENTINEL-INACTIVE' AND active=0").strip()=='1','unrelated_inactive_plan_not_deleted')
        # Native access revocation while a confirmation is pending fails without partial inserts.
        r=plan_to_targets('pasarguard',pg_id);r=action(r,'pick','0');r=action(r,'done');stage(r,'review')
        before=counts();server.mode='permission_missing';failed=action(r,'save')
        yes(counts()==before and draft(failed)['stage']=='review','permissions_revoked_before_save_no_insert')
        server.mode='ok';cancel(failed)
        # SQL failure after a server_info insert must rollback all three related rows.
        r=call(callback='pgAddConnection')
        for value in ['Rollback fixture','10','rollback','FIXTURE',url,'fixture-private-key']:r=text(r,value)
        stage(r,'groups');r=action(r,'pick','0');r=action(r,'done');stage(r,'review')
        before=counts()
        sql("CREATE TRIGGER fixture_reject_config BEFORE INSERT ON server_config FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture-reject'")
        failed=action(r,'save');sql('DROP TRIGGER fixture_reject_config')
        yes(counts()==before and draft(failed)['stage']=='review','server_insert_rollback_has_no_orphan_panel_server')
        cancel(failed)
        # Every later server stage, including credential/target/review, cancels in one request.
        def server_to_stage(provider,target):
            r=call(callback='addManagedServer');r=action(r,'provider',provider)
            steps=[('title','Cancellation fixture'),('capacity','10'),('remark','cancelfixture'),('flag','FIXTURE'),('url',url+('/mb'if provider=='marzban'else''))]
            steps+= [('username','fixture-admin'),('password','fixture-password')] if provider=='marzban' else [('api_key','fixture-private-key')]
            for current,value in steps:
                if current==target:return r
                r=text(r,value)
            if target in ['panelgroup','groups']:return r
            if provider=='marzban':r=action(r,'group','0')
            else:r=action(action(r,'pick','0'),'done')
            return r
        for provider,targets in [('marzban',['username','password','panelgroup','review']),('pasarguard',['api_key','groups','review'])]:
            for target in targets:
                r=server_to_stage(provider,target);stage(r,target);before=counts();cancel(r)
                yes(counts()==before,'later_stage_cancel_no_rows_'+provider+'_'+target)
        # Every plan stage has the same one-press cancel and no partial catalog row.
        for target in ['title','price','category','server','days','volume','description','targets','review']:
            r=call(callback='addManagedPlan')
            while draft(r)['stage']!=target:
                current=draft(r)['stage']
                if current=='title':r=text(r,'Cancel plan')
                elif current=='price':r=text(r,'1')
                elif current=='category':r=action(r,'category','1')
                elif current=='server':r=action(r,'server',str(pg_id))
                elif current=='days':r=text(r,'30')
                elif current=='volume':r=text(r,'5')
                elif current=='description':r=text(r,'Cancel description')
                elif current=='targets':r=action(r,'inherit')
                else:raise AssertionError(current)
            before=counts();cancel(r);yes(counts()==before,'plan_cancel_no_rows_'+target)
        # Contextual field editors use persisted role/row revision and never accept another provider's credentials.
        for callback,kind,entity in [('editServerName'+str(pg_id),'server',pg_id),('planDays9','plan',9),('planVolume9','plan',9)]:
            if kind=='plan':
                entity=int(sql('SELECT MIN(id) FROM server_plans WHERE active=1').strip());callback=callback.rstrip('9')+str(entity)
            r=call(callback=callback,field=True);yes(r['state']['step'].startswith('achbotField|'),'field_editor_started_'+callback)
            before=rows();r=call(text='fixture-cancel',field=True)
            yes(r['state']['step']=='none' and r['state']['temp']is None and rows()==before,'field_cancel_no_write_'+callback)
            shown=json.dumps(r['events']);yes(('showServerSettings'+str(entity)in shown)if kind=='server'else('planDetails'+str(entity)in shown),'field_cancel_context_'+callback)
        plan_id=int(sql('SELECT MIN(id) FROM server_plans WHERE active=1').strip())
        for suffix in ['Days','Volume']:
            r=call(callback='plan'+suffix+str(plan_id),field=True);before=rows()
            r=call(text='-1',field=True);yes(r['state']['step'].startswith('achbotField|')and rows()==before,'invalid_plan_unit_'+suffix)
            call(text='fixture-cancel',field=True)
        before=rows();r=call(callback='changesServerLoginInfo'+str(pg_id),field=True)
        yes(r['state']['step']=='none' and rows()==before,'pg_never_uses_marzban_login_editor')
        r=call(callback='editServerName'+str(pg_id),field=True)
        sql('UPDATE server_info SET ucount=ucount+1 WHERE id='+str(pg_id));capacity=sql('SELECT ucount FROM server_info WHERE id='+str(pg_id)).strip()
        r=call(text='Independent name',field=True)
        yes(r['state']['step']=='none' and sql('SELECT title FROM server_info WHERE id='+str(pg_id)).strip()=='Independent name' and sql('SELECT ucount FROM server_info WHERE id='+str(pg_id)).strip()==capacity,'unrelated_capacity_change_does_not_block_name_editor_or_get_overwritten')
        r=call(callback='editServerName'+str(pg_id),field=True)
        sql("UPDATE server_info SET title='Concurrent name' WHERE id="+str(pg_id));before=rows()
        r=call(text='Stale name',field=True);yes(rows()==before and r['state']['step'].startswith('achbotField|'),'field_stale_baseline_preserves_concurrent_edit')
        call(text='fixture-cancel',field=True)
        r=call(callback='changesServerLoginInfo'+str(mb_id),field=True)
        r=call(text=url+'/mb',field=True);r=call(text='fixture-admin',field=True)
        r=call(text='fixture-password',field=True)
        yes(r['state']['step']=='none' and any(e.get('deleted')for e in r['events']),'credential_edit_deletes_secret_and_saves')
        no_secrets(r)
        # Independent HTTP requests racing the same final confirmation produce exactly one row.
        r=plan_to_targets('pasarguard',pg_id);r=action(r,'inherit');stage(r,'review')
        save='am|'+draft(r)['nonce']+'|'+str(draft(r)['rev'])+'|save|0'
        before=int(sql('SELECT COUNT(*) FROM server_plans').strip())
        with ThreadPoolExecutor(max_workers=2)as pool:results=list(pool.map(lambda _:call(callback=save),range(2)))
        yes(int(sql('SELECT COUNT(*) FROM server_plans').strip())==before+1,'concurrent_confirmation_has_one_plan')
        yes(all(result['state']['step']=='none'for result in results),'concurrent_confirmation_clears_draft_once')
        # Saved connection shortcut still creates only a logical server on final confirmation.
        r=call(callback='addManagedServer');r=action(r,'provider','pasarguard')
        for value in ['Reuse fixture','10','reusefixture','FIXTURE']:r=text(r,value)
        stage(r,'url');panel_id=int(sql('SELECT MIN(id) FROM provider_panels').strip())
        r=action(r,'panel',str(panel_id));stage(r,'groups');r=action(action(r,'pick','0'),'done')
        before=int(sql('SELECT COUNT(*) FROM provider_panels').strip());r=action(r,'save')
        yes(r['state']['step']=='none'and int(sql('SELECT COUNT(*) FROM provider_panels').strip())==before,'saved_connection_reused_without_duplicate_panel')
        # COMMIT can succeed while its acknowledgment is lost; surface uncertainty and make replay inert.
        r=plan_to_targets('pasarguard',pg_id);r=action(r,'inherit');stage(r,'review')
        save='am|'+draft(r)['nonce']+'|'+str(draft(r)['rev'])+'|save|0'
        before=int(sql('SELECT COUNT(*) FROM server_plans').strip())
        r=call(callback=save,commit_ack_lost=True)
        yes(int(sql('SELECT COUNT(*) FROM server_plans').strip())==before+1 and r['state']['step']=='none','commit_ack_loss_preserves_committed_plan')
        yes(any(e.get('body')==r['review_label']for e in r['events']),'commit_ack_loss_displays_review_not_failed_save')
        call(callback=save);yes(int(sql('SELECT COUNT(*) FROM server_plans').strip())==before+1,'commit_ack_loss_replay_no_duplicate')
        # Failed role/frame validation must never render private existing draft content.
        r=server_to_stage('pasarguard','review')
        for spec in [dict(chat_type='group'),dict(sender=9002)]:
            failed=call(text='fixture-cancel',**spec)
            yes(not any('Cancellation fixture'in str(e.get('body',''))or'http://127.0.0.1'in str(e.get('body',''))for e in failed['events']),'untrusted_frame_does_not_render_private_draft')
        cancel(r)
        r=call(callback='pgAddConnection',actor=9002)
        r=call(text='Revoked private draft',actor=9002)
        sql('UPDATE users SET isAdmin=0 WHERE userid=9002')
        failed=call(text='1',actor=9002,cached_admin=True)
        yes(not any('Revoked private draft'in str(e.get('body',''))or'ظرفیت'in str(e.get('body',''))for e in failed['events']),'revoked_role_does_not_render_private_editor')
        sql('UPDATE users SET isAdmin=1 WHERE userid=9002');cancel(r,actor=9002)
        # Contextual add-plan entry remains bound to the chosen server, without reselecting it.
        r=call(callback='newPlanForServer'+str(pg_id));r=text(r,'Context plan');r=text(r,'123')
        stage(r,'category');r=action(r,'category','1')
        yes(draft(r)['stage']=='days' and draft(r)['values']['server']==pg_id,'contextual_plan_shortcut_keeps_server')
        cancel(r)
        # Rejected secret input is deleted even before it can pass validation.
        for provider,target,secret in [('marzban','password','x'*101),('pasarguard','api_key','x'*4097)]:
            r=server_to_stage(provider,target);before=counts();r=text(r,secret)
            yes(draft(r)['stage']==target and counts()==before and any(e.get('deleted')for e in r['events']),'invalid_secret_deleted_'+provider)
            cancel(r)
        # Removing a server cannot strand orders/plans/payment evidence; a pristine server can be removed locally.
        before=rows();r=call(callback='yesDeleteServer'+str(pg_id),field=True)
        yes(rows()==before,'referenced_server_delete_is_blocked')
        clean_id=int(sql('SELECT MAX(id) FROM server_info').strip());before=rows()
        r=call(callback='yesDeleteServer'+str(clean_id),actor=9002,field=True)
        yes(rows()==before,'delegate_without_server_permission_cannot_delete')
        panels_before=sql('SELECT COUNT(*) FROM provider_panels')
        r=call(callback='yesDeleteServer'+str(clean_id),field=True)
        yes(sql('SELECT COUNT(*) FROM server_info WHERE id='+str(clean_id)).strip()=='0' and sql('SELECT COUNT(*) FROM server_config WHERE id='+str(clean_id)).strip()=='0','unreferenced_server_delete_is_atomic')
        yes(sql('SELECT COUNT(*) FROM provider_panels')==panels_before,'shared_provider_identity_retained_on_server_delete')
        # Actual RC13 source reproduces the reported double-cancel cause; current handler consumes it once.
        old_provider=subprocess.check_output(['git','show','2c0e728dcd71c38e20d176fe2577bc264d3faa1a:src/Panel/ProviderAdmin.php'],cwd=ROOT)
        old_path=work/'rc13-provider-admin.php'
        old_path.write_bytes(old_provider.replace(b'__DIR__',repr(str(ROOT/'src/Panel')).encode()))
        legacy_script=work/'legacy-cancel.php';legacy_script.write_text(LEGACY_CANCEL_PHP)
        def legacy(source):
            p=subprocess.run(['php','-d','display_errors=0','-d','log_errors=0',str(legacy_script),str(ROOT),str(work/'mysql.sock'),DB,str(source)],capture_output=True,text=True,timeout=10)
            if p.returncode or p.stderr:raise AssertionError(('legacy-cancel-child',p.returncode,p.stderr[:1200]))
            return json.loads(p.stdout)
        old=legacy(old_path);new=legacy(ROOT/'src/Panel/ProviderAdmin.php')
        yes(old=={'handled':False,'fallback':False,'events':0,'step':'none'},'actual_rc13_cancel_RED_stale_cache_prevents_navigation')
        yes(new['handled']is True and new['fallback']is None and new['events']>=2 and new['step']=='none','current_pg_cancel_GREEN_consumes_request_and_navigates')
        # Assert the complete native HTTP trace contains no provider user mutation.
        trace=server.trace
        yes(all(method=='GET'or (method=='POST'and urlsplit(path).path=='/mb/api/admin/token')for method,path,_ in trace),'only_read_admission_and_auth_http')
        yes(not any('/api/user'in path or '/subscription/'in path for _,path,_ in trace),'no_remote_account_or_subscription_calls')
        yes(server.write_count==0,'no_provider_mutation')
        report={'complete':True,'cases':len(checks),'checks':checks,'actual_php':True,'owned_socket_mariadb':True,
            'loopback_provider_http':True,'provider_requests':len(trace),'provider_mutations':0,'production_mutations':0,
            'real_telegram_tested':False,'real_provider_tested':False,'actual_rc13_cancel_RED':True}
    if a.report:a.report.write_text(json.dumps(report,ensure_ascii=False,indent=2)+'\n')
    print(json.dumps(report,ensure_ascii=False))
if __name__=='__main__':main()
