#!/usr/bin/env python3
"""Funded service execution against real PHP/MySQLi and an owned HTTP peer.

The peer models PasarGuard's existing authenticated, unique-name CREATE and
readback contract. It does not certify a deployed provider or Telegram delivery.
"""
from __future__ import annotations

import argparse
from concurrent.futures import ThreadPoolExecutor
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
from pathlib import Path
import subprocess
import threading
from urllib.parse import unquote

import test_access_state as owned

ROOT=Path(__file__).resolve().parents[1]
DB=owned.NAMES[1]
PHP=r'''<?php
declare(strict_types=1);
mysqli_report($argv[4]==='strict'?MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT:MYSQLI_REPORT_OFF);
define('ACHBOT_PG_TEST_LOOPBACK',true);
require $argv[1].'/src/Services/Execution.php';
$db=new mysqli('localhost','root','',$argv[3],0,$argv[2]);
$x=json_decode($argv[5],true,512,JSON_THROW_ON_ERROR);
try {
    if ($x['op']==='step') $r=achbotServiceExecuteStep($db,$x['id']);
    elseif ($x['op']==='due') $r=achbotServiceExecuteDue($db,$x['limit'],10.0);
    else {
        if (!$db->begin_transaction()) throw new RuntimeException('fixture_begin');
        $r=match($x['op']) {
            'issue'=>achbotServiceIssueLocked($db,1001,'fixture',$x['ref'],$x['quote']),
            'fund'=>achbotServiceFundLocked($db,1001,$x['id']),
        };
        if (!$db->commit()) throw new RuntimeException('fixture_commit');
    }
    echo json_encode(['ok'=>true,'result'=>$r],JSON_THROW_ON_ERROR);
} catch(Throwable $e) {
    $db->rollback();
    echo json_encode(['ok'=>false,'reason'=>$e->getMessage()],JSON_THROW_ON_ERROR);
}
'''

def main():
    ap=argparse.ArgumentParser(description=__doc__)
    ap.add_argument('--run-disposable',required=True,action='store_true')
    ap.add_argument('--report',type=Path)
    args=ap.parse_args()
    cases=[]
    with owned.owned_database() as (work,env):
        client=['mariadb','--no-defaults','--protocol=SOCKET','--socket='+str(work/'mysql.sock'),'-uroot','--batch','--skip-column-names',DB]
        def sql(q):
            p=subprocess.run(client,input=q,text=True,env=env,capture_output=True,timeout=15)
            assert p.returncode==0,(q,p.stderr)
            return p.stdout.strip()
        lock=threading.Lock()
        users={};requests=[];faults=[];errors=[]
        entered=threading.Event();release=threading.Event()
        def make_user(body,note=None):
            return dict(body,id=len(users)+1,created_at='2026-01-01T00:00:00Z',
                note=body['note'] if note is None else note,used_traffic=0,
                subscription_url='https://fixture.invalid/sub/'+str(len(users)+1),
                proxy_settings={'vless':{'id':'fixture'}})
        class Handler(BaseHTTPRequestHandler):
            def log_message(self,*a):pass
            def reply(self,status,data):
                wire=json.dumps(data).encode()
                self.send_response(status);self.send_header('Content-Type','application/json')
                self.send_header('Content-Length',str(len(wire)));self.end_headers()
                try:self.wfile.write(wire)
                except (BrokenPipeError,ConnectionResetError):pass
            def do_GET(self):
                try:
                    assert self.headers['X-Api-Key']=='fixture'
                    # Actual SQL writer on another connection proves no open
                    # InnoDB owner/effect lock spans the authenticated request.
                    sql('SET SESSION innodb_lock_wait_timeout=2; UPDATE users SET wallet=wallet WHERE userid=1001')
                    with lock:
                        requests.append(('GET',self.path))
                        if faults and faults[0]=='get_unavailable':
                            faults.pop(0);return self.reply(503,{})
                        if self.path.startswith('/api/user/by-username/'):
                            u=users.get(unquote(self.path.rsplit('/',1)[1]))
                        elif self.path.startswith('/api/user/by-id/'):
                            u=next((v for v in users.values() if str(v['id'])==self.path.rsplit('/',1)[1]),None)
                        else:raise AssertionError(self.path)
                    self.reply(200,u) if u is not None else self.reply(404,{})
                except Exception as e:
                    errors.append(repr(e));self.reply(500,{})
            def do_POST(self):
                try:
                    assert self.path=='/api/user' and self.headers['X-Api-Key']=='fixture'
                    body=json.loads(self.rfile.read(int(self.headers['Content-Length'])))
                    assert body['note'].startswith('achbot:v1:')
                    # Check committed dispatch intent before every POST.
                    state=sql("SELECT CONCAT(state,':',provider_state) FROM service_effects WHERE operation_id='"+body['note'].split(':')[-1]+"'")
                    assert state=='dispatching:dispatching',state
                    with lock:
                        requests.append(('POST',body.copy()))
                        fault=faults.pop(0) if faults else ''
                        if fault=='post_unavailable':return self.reply(503,{})
                        assert body['username'] not in users,'unexpected duplicate POST'
                        u=make_user(body);users[body['username']]=u
                    if fault=='pause':
                        entered.set()
                        assert release.wait(10)
                    if fault=='lost_response':return self.reply(503,{})
                    if fault=='conflict_after_create':return self.reply(409,{})
                    self.reply(201,u)
                except Exception as e:
                    errors.append(repr(e));self.reply(500,{})
        server=ThreadingHTTPServer(('127.0.0.1',0),Handler)
        thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
        base='http://127.0.0.1:'+str(server.server_port)
        try:
            for m in ('001_marzban_panel_group_id.sql','003_payment_attempts.sql',
                      '007_service_effects.sql','011_provider_identity.sql','012_shared_provider_storage.sql',
                      '014_api_payment_evidence.sql','022_gateway_review_archive.sql',
                      '023_scoped_payment_lock_indexes.sql','024_wallet_entries.sql',
                      '025_wallet_receipt_credit.sql','026_service_operations.sql'):
                sql((ROOT/'migrations/post-v9'/m).read_text())
            sql(f"""INSERT INTO server_config(id,panel_url,ip,sni,header_type,request_header,response_header,security,tlsSettings,type,username,password)
                VALUES (2,'{base}','127.0.0.1','','none','','','none','{{}}','pasarguard','fixture','fixture');
                INSERT INTO server_info(id,title,ucount,remark,flag,active,state) VALUES (2,'Fixture PG','100','pgfixture','',1,1);
                UPDATE server_config SET provider_connection_id=2,provider_instance_key=REPEAT('a',32),
                    provider_version='5.5.1',provider_enabled=1 WHERE id=2;
                INSERT INTO server_plans(id,fileid,catid,server_id,inbound_id,acount,title,protocol,days,volume,type,price,descr,pic,active,step,date,custom_sni,provider_group_ids)
                VALUES (7,'7',1,2,0,6,'Fixture PG','vless',30,2,'normal',100,'description','',1,0,'fixture',NULL,'[1,2]');""")
            driver=work/'execution.php';driver.write_text(PHP)
            def php(mode,op,**kw):
                p=subprocess.run(['php','-n','-d','extension=mysqlnd','-d','extension=mysqli','-d','extension=curl',
                    '-d','allow_url_fopen=0','-d','disable_functions=exec,shell_exec,system,passthru,popen,proc_open,mail',
                    str(driver),str(ROOT),str(work/'mysql.sock'),DB,mode,json.dumps(dict(op=op,**kw))],
                    env=env,text=True,capture_output=True,timeout=25)
                assert p.returncode==0,(p.returncode,p.stderr)
                for line in p.stderr.splitlines():
                    assert line.startswith('[AChBot] '),line
                r=json.loads(p.stdout);assert r['ok'],r
                return r['result']
            def issue(mode,ref,quantity=1):
                return php(mode,'issue',ref=mode+'-'+ref,quote=dict(kind='BUY',variant='standard',price=100,
                    server_id=2,plan_id=7,order_id=0,quantity=quantity,days=30,volume=2,
                    remark=mode+'-'+ref+'-12345',uuid='',naming_mode='digits',explicit_name=False))
            def step(mode,id,status,reason=None):
                r=php(mode,'step',id=id)
                assert r['status']==status and (reason is None or r.get('reason')==reason),(id,r,status,reason)
                return r
            def retry(id):
                sql(f'UPDATE service_operations SET next_attempt_at=NOW(6) WHERE id={id}')
            def fund(mode,id):
                assert php(mode,'fund',id=id)=='funded'
            def finance():
                return tuple(sql(q) for q in ('SELECT userid,wallet FROM users ORDER BY id',
                    'SELECT source_kind,source_ref,delta_toman FROM wallet_entries ORDER BY id',
                    'SELECT id,ucount FROM server_info ORDER BY id'))
            def rows(id):
                return sql(f"SELECT ordinal,operation_id,remote_username,requested_expire,requested_bytes,state,COALESCE(provider_remote_user_id,0) FROM service_effects WHERE source_kind='service_operation' AND source_ref='{id}' ORDER BY ordinal").splitlines()
            def posts():
                return sum(v[0]=='POST' for v in requests)
            for mode in ('strict','off'):
                # Isolate each test mode's scheduler from previously deferred
                # conflict fixtures; no terminal evidence is changed.
                sql("UPDATE service_operations SET next_attempt_at=DATE_ADD(NOW(6),INTERVAL 1 DAY) WHERE stage IN ('funded','prepared','dispatching')")
                id=issue(mode,'bulk',3);n=len(requests)
                step(mode,id,'awaiting_funds');assert len(requests)==n and not rows(id)
                cases.append(mode+'_unfunded_no_io')
                fund(mode,id);before=finance();n=posts()
                step(mode,id,'progressed');assert posts()==n+1 and len(rows(id))==1
                faults.append('lost_response')
                step(mode,id,'pending','pg_vendor_unavailable')
                original=rows(id)[1].split('\t')[:5]
                assert posts()==n+2 and len(rows(id))==2
                step(mode,id,'not_due')
                retry(id);step(mode,id,'progressed')
                assert rows(id)[1].split('\t')[:5]==original and posts()==n+2
                step(mode,id,'applied');assert posts()==n+3 and len(rows(id))==3
                assert all('\tverified\t' in row for row in rows(id))
                assert len({row.split('\t')[2] for row in rows(id)})==3
                step(mode,id,'applied');assert posts()==n+3 and finance()==before
                cases.append(mode+'_bulk_lost_response_exact_ordinal')
                id=issue(mode,'unavailable');fund(mode,id);before=finance();n=posts()
                faults.append('get_unavailable');step(mode,id,'pending','pg_vendor_unavailable')
                original=rows(id)[0].split('\t')[:5];assert posts()==n
                retry(id);faults.append('post_unavailable');step(mode,id,'pending','pg_vendor_unavailable')
                assert posts()==n+1
                retry(id);step(mode,id,'applied')
                assert posts()==n+2 and rows(id)[0].split('\t')[:5]==original and finance()==before
                cases.append(mode+'_outage_same_identity')
                id=issue(mode,'conflict');fund(mode,id);before=finance();n=posts()
                faults.append('conflict_after_create');step(mode,id,'pending','pg_conflict')
                retry(id);step(mode,id,'applied')
                assert posts()==n+1 and finance()==before
                cases.append(mode+'_409_readback')
                id=issue(mode,'rollback');fund(mode,id);before=finance();n=posts()
                sql("""DELIMITER //
CREATE TRIGGER fixture_execution_failure BEFORE UPDATE ON service_effects FOR EACH ROW
                    BEGIN IF NEW.state='verified' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture rollback'; END IF; END//
DELIMITER ;
""")
                try:
                    step(mode,id,'pending','provider_storage_unavailable')
                    assert posts()==n+1 and '\tdispatching\t0' in rows(id)[0]
                finally:sql('DROP TRIGGER fixture_execution_failure')
                retry(id);step(mode,id,'applied')
                assert posts()==n+1 and finance()==before
                cases.append(mode+'_local_commit_failure_recovery')
                id=issue(mode,'foreign');fund(mode,id);before=finance();n=posts()
                faults.append('get_unavailable');step(mode,id,'pending')
                name=rows(id)[0].split('\t')[2]
                with lock:
                    users[name]=make_user(dict(username=name,note='foreign',data_limit=1,expire=1800000000,
                        group_ids=[1],status='active',data_limit_reset_strategy='no_reset',next_plan=None))
                retry(id);step(mode,id,'pending','pg_create_name_identity_conflict')
                assert posts()==n and finance()==before
                cases.append(mode+'_foreign_identity_no_overwrite')
                id=issue(mode,'repoint');fund(mode,id);before=finance();n=len(requests)
                sql("UPDATE server_config SET provider_instance_key=REPEAT('b',32) WHERE id=2")
                step(mode,id,'pending')
                assert len(requests)==n and not rows(id) and finance()==before
                sql("UPDATE server_config SET provider_instance_key=REPEAT('a',32) WHERE id=2")
                retry(id);step(mode,id,'applied')
                cases.append(mode+'_original_panel_only')
                id=issue(mode,'evidence');fund(mode,id);before=finance()
                faults.append('get_unavailable');step(mode,id,'pending')
                sql(f"UPDATE service_effects SET provider_entitlement_snapshot=JSON_SET(provider_entitlement_snapshot,'$.data_limit',1) WHERE source_kind='service_operation' AND source_ref='{id}'")
                n=len(requests);retry(id);step(mode,id,'pending','service_buy_account_evidence_missing')
                assert len(requests)==n and finance()==before
                cases.append(mode+'_changed_local_entitlement_no_io')
                id=issue(mode,'concurrent');fund(mode,id);before=finance();n=posts()
                faults.append('pause');entered.clear();release.clear()
                with ThreadPoolExecutor(max_workers=2) as ex:
                    future=ex.submit(php,mode,'step',id=id)
                    try:
                        assert entered.wait(10),errors
                        step(mode,id,'busy')
                    finally:release.set()
                    assert future.result()['status']=='applied'
                assert posts()==n+1 and finance()==before
                cases.append(mode+'_session_owner')
                # One deferred operation cannot monopolize the due batch.
                first=issue(mode,'fair-first');fund(mode,first)
                second=issue(mode,'fair-second');fund(mode,second)
                before=finance();faults.append('get_unavailable')
                r=php(mode,'due',limit=2)
                assert r[str(first)]['status']=='pending' and r[str(second)]['status']=='applied',r
                assert finance()==before
                cases.append(mode+'_due_fair_progress')
                assert not faults and not errors,(faults,errors)
        finally:
            release.set();server.shutdown();server.server_close();thread.join(5)
    result=dict(complete=True,cases=len(cases),checks=cases,requests=len(requests),posts=posts())
    if args.report:args.report.write_text(json.dumps(result,indent=2)+'\n')
    print(json.dumps(result))

if __name__=='__main__':main()
