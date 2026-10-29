#!/usr/bin/env python3
"""Actual purchase/details/QR context selection on owned SQL and native HTTP fixtures."""
from __future__ import annotations
import argparse,html,io,json,re,subprocess,tarfile
from pathlib import Path
import package_post_v9 as package
import provider_storage_fixture as storage
import test_access_state as owned
import test_post_v9_pasarguard_client as pg
import test_v6_native as native
import test_v6_panels as functions
import test_v8_native as purchase

ROOT=Path(__file__).resolve().parents[1]
BASE='748994636f9fc2cf4c0ea4c7c810093c0dd108ef'
PG_LINKS=['vless://native-identity@host.invalid:443?type=grpc&serviceName=literal#Native',
          'vmess://eyJwcyI6Ik5hdGl2ZSJ9']
MB_LINKS=PG_LINKS+['trojan://opaque%2Bcredential@native.invalid:443?type=ws&path=%2Fa#Third',
                   'ss://opaque@native.invalid:443#Fourth']
PHP=r'''<?php
error_reporting(E_ALL);
set_error_handler(static function($n,$m){if($n===E_DEPRECATED)return true;throw new ErrorException($m,0,$n);});
$_SERVER['SCRIPT_FILENAME']=__FILE__;define('ACHBOT_PG_TEST_LOOPBACK',true);
$root=$argv[1];chdir(__DIR__);set_include_path(__DIR__);
$spec=json_decode($argv[4],true,64,JSON_THROW_ON_ERROR);
$connection=new mysqli('localhost','root','',$argv[3],0,$argv[2]);$connection->set_charset('utf8mb4');
require_once $root.'/src/Purchase/Core.php';
if(is_file($root.'/buy_helpers.php'))require_once $root.'/buy_helpers.php'; // Archived fixture source only.
require $argv[5];
require_once $root.'/src/Subscription/ServerDisplaySettings.php';
$admin=9001;$from_id=$chat_id=(int)($spec['actor']??1001);$message_id=7;$callbackId='owned-display';
$botUrl='https://bot.example.invalid/';$botUsername='';$botState=['qrConfigState'=>'on','qrSubState'=>'on'];$data='';$text='';
$userInfo=$connection->query('SELECT * FROM users WHERE userid='.$from_id)->fetch_assoc()??['step'=>'none'];
$buttonValues=['back_to_main'=>'Back'];$events=[];$qr_payloads=[];$files=[];
class QRcode {public static function png($uri,$file,...$rest){$GLOBALS['qr_payloads'][]=$uri;$GLOBALS['files'][]=$file;file_put_contents($file,'owned-qr-placeholder');}}
function RandomString(){return 'owned-context-'.bin2hex(random_bytes(8));}
function sendPhoto($photo,$caption=null,$keys=null,$parse=null,$to=null,...$rest){
 $path=$photo instanceof CURLFile?$photo->getFilename():(string)$photo;
 $GLOBALS['events'][]=['kind'=>'photo','caption'=>$caption,'parse'=>$parse,'to'=>$to,'file_present'=>is_file($path)];
 return(object)['ok'=>true];
}
function sendMessage($text,$keys=null,$parse=null,$to=null,...$rest){$GLOBALS['events'][]=['kind'=>'text','text'=>$text,'parse'=>$parse,'to'=>$to];return(object)['ok'=>true];}
function alert($text,$modal=false){$GLOBALS['events'][]=['kind'=>'alert','text'=>$text,'modal'=>$modal];}
function answerCallbackQuery(...$args){$GLOBALS['events'][]=['kind'=>'denied'];}
if($spec['op']==='save'){
 try {
  $source=(int)$spec['source'];$sid=(int)$spec['sid'];
  achbotServerDisplayEdit($connection,$sid,$from_id,9001,$source,'start',null,'document');
  achbotServerDisplayEdit($connection,$sid,$from_id,9001,$source+1,'save',json_encode($spec['document'],JSON_THROW_ON_ERROR));
  $out=['saved'=>true];
 }catch(Throwable $e){$out=['saved'=>false];}
}elseif($spec['op']==='set'){
 try {achbotServerDisplayEdit($connection,(int)$spec['sid'],$from_id,9001,(int)$spec['source'],'set',(string)$spec['input'],(string)$spec['field'],(int)$spec['revision']);$out=['saved'=>true];}
 catch(Throwable $e){$out=['saved'=>false];}
}elseif($spec['op']==='draw'){
 $q=$connection->prepare('SELECT * FROM orders_list WHERE id=?');$q->bind_param('i',$spec['order']);$q->execute();$order=$q->get_result()->fetch_assoc();$q->close();
 $q=$connection->prepare('SELECT * FROM server_config WHERE id=?');$q->bind_param('i',$order['server_id']);$q->execute();$server=$q->get_result()->fetch_assoc();$q->close();
 $provider=$server['type'];$links=$spec['links'];
 $response=$provider==='pasarguard'?['links'=>$links,'subscription_url'=>$spec['subscription_url']]:['vray_links'=>$links,'sub_link'=>'/sub/mb-token'];
 $delivered=buildLinksAndSendQr($provider,$server,$botState,$botUrl,(int)$order['server_id'],
  'original-id','','fixture_user',0,'',0,'','','','',$response,$from_id,2,30,[],[99],true,'',null,true);
 $delivery=['returned'=>$delivered,'events'=>$events,'qr_payloads'=>$qr_payloads];
 $view=achbotOrderPresentation($connection,$order,$server,$botState);
 $events=[];$qr_payloads=[];$data='showQrConfig'.$order['id'];
 require $root.'/src/Finance/Routes/Checkout.php';
 $out=['delivery'=>$delivery,'view'=>$view,'qr'=>['events'=>$events,'qr_payloads'=>$qr_payloads],
  'temp_files_remaining'=>count(array_filter($files,'is_file'))];
}elseif($spec['op']==='qr'){
 $data='showQrConfig'.(int)$spec['order'];require $root.'/src/Finance/Routes/Checkout.php';
 $out=['events'=>$events,'qr_payloads'=>$qr_payloads,'temp_files_remaining'=>count(array_filter($files,'is_file'))];
}else throw new RuntimeException('fixture_operation_unknown');
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
'''

def code_configs(text,known):
    return [html.unescape(value) for value in re.findall(r'<code>(.*?)</code>',text,re.S)
            if html.unescape(value) in known]

def main():
    p=argparse.ArgumentParser();p.add_argument('--run-disposable',action='store_true',required=True);p.add_argument('--report',type=Path);a=p.parse_args()
    checks=[]
    def yes(ok,name):
        if not ok:raise AssertionError(name)
        checks.append(name)
    with owned.owned_database() as (work,env),pg.panel() as (panel,url):
        purchase.init(work,env,url);db=purchase.SIDE[1]
        def sql(query):return native.sql(work,env,query,db).strip()
        for name in ['001_marzban_panel_group_id.sql','004_automatic_renewal_attempts.sql','007_service_effects.sql','011_provider_identity.sql','012_shared_provider_storage.sql']:
            sql((ROOT/'migrations/post-v9'/name).read_text())
        sql(storage.connection(2,1,url)+"UPDATE server_info SET provider_group_ids='[2,5]' WHERE id=2;")
        sql("INSERT INTO server_info(id,title,ucount,remark,flag,active,state) VALUES(3,'Native MB',10,'mb','',1,1),(4,'Peer PG',10,'peer','',1,1);DELETE FROM server_config WHERE id IN(3,4);"+storage.clone(3,2)+storage.clone(4,2)+"UPDATE server_config SET type='marzban',provider_enabled=0 WHERE id=3;")
        sql("INSERT INTO orders_list(id,userid,token,transid,fileid,server_id,inbound_id,remark,uuid,protocol,expire_date,link,amount,status,date) VALUES(91,1001,'','',7,2,0,'fixture_user','native','vless',2000000000,'[]',100,1,'1700000000'),(92,1001,'mb-token','',0,3,0,'mb_fixture','mb-native','vless',2000000000,"+storage.text(json.dumps(MB_LINKS))+",100,1,'1700000000'),(93,1001,'peer-token','',0,4,0,'peer_fixture','peer-native','vless',2000000000,'[]',100,1,'1700000000');")
        entitlement={'group_ids':[2,5],'expire':2000000000,'data_limit':2147483648}
        sql(storage.identity(901,91,1,url,17,pg.USER['created_at'],'fixture_user',pg.MARKER,entitlement))
        sql("INSERT INTO setting(type,value) VALUES('ADMIN_ROLE_9002','manager'),('unrelated_fixture','must-remain');")
        driver=work/'display-contexts.php';driver.write_text(PHP)
        (work/'phpqrcode').mkdir();(work/'phpqrcode/qrlib.php').write_text('<?php // QR boundary is the owned encoder spy.\n')
        def facades(root,name):
            target=work/name;body=functions.function_bodies((root/'config.php').read_bytes(),['buildSubscriptionPresentation','achbotSupportsPanel'])
            target.write_bytes(body.replace(b'__DIR__',repr(str(root)).encode()));return target
        current_facade=facades(ROOT,'current-facades.php')
        def call(root=ROOT,facade=current_facade,expected_stderr="",**spec):
            r=subprocess.run(['php','-d','display_errors=0','-d','log_errors=0',str(driver),str(root),str(work/'mysql.sock'),db,json.dumps(spec),str(facade)],cwd=work,env=env,capture_output=True,text=True,timeout=25)
            if r.returncode or r.stderr!=expected_stderr:raise AssertionError((r.returncode,r.stderr[-1800:],r.stdout[-300:]))
            return json.loads(r.stdout)
        def financial():return sql('SELECT * FROM orders_list ORDER BY id;SELECT * FROM pays ORDER BY id;SELECT * FROM service_effects ORDER BY id;SELECT * FROM users ORDER BY id;SELECT * FROM server_info ORDER BY id;SELECT * FROM server_config ORDER BY id')
        def snapshot():return financial()+sql('SELECT * FROM setting ORDER BY id')
        def display_type(sid):
            # Compare the controlled ASCII key as bytes, independent of DB collation.
            return "UNHEX('"+('ACHBOT_SERVER_DISPLAY_'+str(sid)).encode('ascii').hex()+"')"
        source=10
        def save(sid,document):
            nonlocal source
            source+=10;before=financial();peers=sql("SELECT * FROM setting WHERE BINARY type<>"+display_type(sid)+' ORDER BY id')
            out=call(op='save',sid=sid,document=document,source=source,actor=9001)
            yes(out['saved'],'actual_owner_document_save_'+str(source))
            yes(before==financial() and peers==sql("SELECT * FROM setting WHERE BINARY type<>"+display_type(sid)+' ORDER BY id'),'single_server_write_no_peer_or_financial_change_'+str(source))
            yes(sql("SELECT COUNT(*) FROM setting WHERE BINARY type="+display_type(sid))=='1','single_existing_setting_row_'+str(source))
            return source+1
        schema=sql('SHOW TABLES;SHOW CREATE TABLE setting')
        peer={'mode':'native','policy':{'selectors':[{'protocol':'vmess'}],'qr':'first_config'}}
        save(4,peer)
        matrix=[
            ('disjoint',{'delivery_selectors':[{'protocol':'vless'}],'view_selectors':[{'protocol':'vmess'}]},[0],[1]),
            ('delivery_none',{'delivery_selectors':[],'view_selectors':[{'protocol':'vmess'}]},[],[1]),
            ('view_none',{'delivery_selectors':[{'protocol':'vless'}],'view_selectors':[]},[0],[]),
            ('shared',{'selectors':[{'protocol':'vmess'}]},[1],[1]),
            ('delivery_over_shared',{'selectors':[{'protocol':'vmess'}],'delivery_selectors':[{'protocol':'vless'}]},[0],[1]),
            ('view_over_all',{'view_selectors':[{'protocol':'vmess'}]},None,[1]),
            ('all',{},None,None),
            ('missing_delivery',{'delivery_selectors':[{'name':'Absent native name'}],'view_selectors':[{'protocol':'vmess'}]},[],[1]),
        ]
        def draw(provider,order,links,expected_delivery,expected_view,label,root=ROOT,facade=current_facade):
            before=snapshot();writes=panel.write_count
            out=call(root,facade,op='draw',order=order,links=links,subscription_url=pg.USER['subscription_url'])
            d=out['delivery'];text='\n'.join(e['text'] for e in d['events'] if e['kind']=='text')
            yes(code_configs(text,links)==expected_delivery,provider+'_'+label+'_actual_purchase_text')
            yes(d['qr_payloads']==expected_delivery[:1],provider+'_'+label+'_actual_purchase_qr')
            yes(json.loads(d['returned'][1])==links,provider+'_'+label+'_stored_delivery_native_uri_bytes_unchanged')
            yes(out['view']['links']==expected_view and code_configs(out['view']['config_html'],links)==expected_view,provider+'_'+label+'_actual_order_view')
            yes(out['qr']['qr_payloads']==links,provider+'_'+label+'_actual_checkout_config_qr')
            qr_text=[e['text'] for e in out['qr']['events'] if e['kind']=='text']
            yes([html.unescape(t[11:-13]) for t in qr_text]==links,provider+'_'+label+'_actual_qr_copy_text_bytes')
            yes(before==snapshot() and panel.write_count==writes and out['temp_files_remaining']==0,provider+'_'+label+'_read_only_money_peers_and_temp_cleanup')
            return out
        for provider,sid,order,links in [('pasarguard',2,91,PG_LINKS),('marzban',3,92,MB_LINKS)]:
            for label,policy,di,vi in matrix:
                save(sid,{'mode':'native','policy':dict(policy,qr='first_config')})
                draw(provider,order,links,links[:3] if di is None else [links[i] for i in di],links[:3] if vi is None else [links[i] for i in vi],label)
            # ALL must clear only this context's effective filter, preserving its peer.
            revision=save(sid,{'mode':'native','policy':{'selectors':[{'protocol':'vmess'}],'qr':'first_config'}})
            source+=10;before=financial();peers=sql("SELECT * FROM setting WHERE BINARY type<>"+display_type(sid)+' ORDER BY id')
            out=call(op='set',sid=sid,actor=9001,source=source,field='dselect',input='all',revision=revision)
            yes(out['saved'] and before==financial() and peers==sql("SELECT * FROM setting WHERE BINARY type<>"+display_type(sid)+' ORDER BY id'),provider+'_actual_all_control_keeps_other_rows')
            draw(provider,order,links,links[:3],[links[1]],'all_preserves_inherited_view')
            before=snapshot();out=call(op='set',sid=sid,actor=9001,source=source+1,field='vselect',input='none',revision=revision)
            yes(not out['saved'] and snapshot()==before,provider+'_stale_revision_cannot_reselect_other_context')
            before=snapshot();requests=len(panel.trace)
            denied=call(op='qr',order=order,actor=9002)
            yes(not denied['qr_payloads'] and denied['events'] and all(e['kind']=='alert' for e in denied['events']) and snapshot()==before and len(panel.trace)==requests,provider+'_foreign_order_qr_denied_before_native_read_or_config_disclosure')
        # Genuine Marzban positional compatibility still has its historical QR exception.
        save(3,{'mode':'marzban_legacy','legacy':{'showIndexes':[2,4],'linkIndices':[2,9]},'policy':{'qr':'first_config'}})
        before=snapshot();legacy=call(op='draw',order=92,links=MB_LINKS,subscription_url=pg.USER['subscription_url'])
        yes(code_configs('\n'.join(e['text'] for e in legacy['delivery']['events'] if e['kind']=='text'),MB_LINKS)==[MB_LINKS[2],MB_LINKS[0]],'legacy_marzban_delivery_zero_based_and_fallback')
        yes(legacy['view']['links']==[MB_LINKS[1],MB_LINKS[3]] and legacy['qr']['qr_payloads']==MB_LINKS,'legacy_marzban_view_one_based_and_original_qr_all')
        yes(snapshot()==before,'legacy_render_does_not_write')
        # Fresh real actor authority applies even to context documents.
        sql('UPDATE users SET isAdmin=0 WHERE userid=9002')
        for actor in [1001,9002]:
            before=snapshot();out=call(op='save',sid=2,actor=actor,source=source+100,document={'mode':'native','policy':{'delivery_selectors':[]}})
            yes(not out['saved'] and snapshot()==before,'unauthorized_context_writer_no_effect_'+str(actor))
        save(2,{'mode':'native','policy':{'selectors':[{'protocol':'vless'}],'qr':'first_config'}})
        archived=work/'archived';archived.mkdir();members=set(package.members())|{'buy_helpers.php'}
        with tarfile.open(fileobj=io.BytesIO(subprocess.check_output(['git','archive',BASE],cwd=ROOT))) as archive:
            archive.extractall(archived,members=[m for m in archive.getmembers() if m.name in members],filter='data')
        old_facade=facades(archived,'archived-facades.php');before=snapshot()
        old=call(archived,old_facade,op='draw',order=91,links=PG_LINKS,subscription_url=pg.USER['subscription_url'])
        old_text='\n'.join(e['text'] for e in old['delivery']['events'] if e['kind']=='text')
        yes(code_configs(old_text,PG_LINKS)==old['view']['links']==old['qr']['qr_payloads']==[PG_LINKS[0]],'archived_748_actual_pg_shared_selection_cannot_deliver_vless_and_view_vmess')
        yes(snapshot()==before,'archived_actual_consumers_no_financial_or_peer_writes')
        revision=save(2,{'mode':'native','policy':{'delivery_selectors':[{'protocol':'vless'}],'view_selectors':[{'protocol':'vmess'}],'qr':'first_config'}})
        draw('pasarguard',91,PG_LINKS,[PG_LINKS[0]],[PG_LINKS[1]],'final_disjoint_after_archived_red')
        before=snapshot();old_split=call(archived,old_facade,expected_stderr='achbot_server_display_unavailable\n'*3,op='draw',order=91,links=PG_LINKS,subscription_url=pg.USER['subscription_url'])
        old_text='\n'.join(e['text'] for e in old_split['delivery']['events'] if e['kind']=='text')
        yes(code_configs(old_text,PG_LINKS)==[] and old_split['view']['links']==[] and old_split['qr']['qr_payloads']==[] and old_split['view']['reason']=='invalid_policy','archived_748_same_split_document_cannot_serve_either_required_context_red')
        yes(snapshot()==before,'archived_same_split_document_fails_closed_without_writes')
        yes(re.sub(r'AUTO_INCREMENT=[0-9]+','AUTO_INCREMENT=fixture',sql('SHOW TABLES;SHOW CREATE TABLE setting'))==re.sub(r'AUTO_INCREMENT=[0-9]+','AUTO_INCREMENT=fixture',schema),'original_schema_tables_unchanged')
        yes(panel.write_count==0 and panel.trace and all(row[0]=='GET' for row in panel.trace),'owned_native_fixture_only_GET_no_create_or_mutation')
    report={'complete':True,'cases':len(checks),'checks':checks,'source_real':True,'owned_original_schema_mariadb':True,
        'actual_canonical_delivery':True,'actual_order_presentation':True,'actual_checkout_config_qr':True,
        'loopback_native_pasarguard_readonly':True,'native_providers':['pasarguard','marzban'],
        'disjoint_delivery_view':True,'explicit_none_isolated':True,'shared_selector_fallback':True,'absent_selectors_first_three':True,
        'context_all_preserves_other_effective_selection':True,'single_existing_setting_row':True,
        'other_rows_and_financial_state_unchanged':True,'current_authority_revision_guards':True,
        'native_uri_bytes_preserved':True,'legacy_marzban_indices_unchanged':True,
        'archived_shared_selection_red':True,'archived_context_document_red':True,'baseline_source':BASE,
        'real_provider_calls':0,'real_telegram_calls':0,'native_mutations':0,'runtime_schema_mutations':0,'runtime_automatic_data_writes':0}
    if a.report:a.report.write_text(json.dumps(report,indent=2)+'\n')
    print(json.dumps({k:v for k,v in report.items() if k!='checks'}))
if __name__=='__main__':main()
