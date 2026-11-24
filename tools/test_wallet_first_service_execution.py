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
import re
from urllib.parse import unquote

import test_access_state as owned

ROOT=Path(__file__).resolve().parents[1]
DB=owned.NAMES[1]
PHP=r'''<?php
declare(strict_types=1);
mysqli_report($argv[4]==='strict'?MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT:MYSQLI_REPORT_OFF);
define('ACHBOT_PG_TEST_LOOPBACK',true);
require $argv[1].'/src/Services/Execution.php';
require $argv[1].'/src/Finance/ServiceDelivery.php';
$GLOBALS['telegramWebhookSecret']='SyntheticServiceDeliveryKey_2026';
function sendQrFromLink(string $qr,string $caption,string $keys,string $botUrl,int $uid): bool {
    global $db,$x;
    if ((int)achbotProviderRow($db,'SELECT @@in_transaction AS tx')['tx']!==0)
        throw new RuntimeException('fixture_telegram_inside_transaction');
    $ctx=$GLOBALS['achbotTelegramDiagnosticContext'];
    $d=achbotProviderRow($db,'SELECT id,state,attempt_count FROM payment_delivery_intents WHERE service_operation_id=? AND ordinal=?','ii',
        [$ctx['service_operation_id'],$ctx['delivery_ordinal']]);
    if ($d['state']!=='dispatching' || (int)$d['attempt_count']!==1)
        throw new RuntimeException('fixture_telegram_claim_not_committed');
    $s=achbotProviderStatement($db,'INSERT INTO fixture_telegram(intent_id,user_id,qr,caption,keys_json,bot_url)
        VALUES (?,?,?,?,?,?)','iissss',[$d['id'],$uid,$qr,$caption,$keys,$botUrl]);$s->close();
    return !($x['unknown_ack']??false);
}

$db=new mysqli('localhost','root','',$argv[3],0,$argv[2]);
$x=json_decode($argv[5],true,512,JSON_THROW_ON_ERROR);
try {
    $presentation=['bot_state'=>[],'bot_url'=>'https://bot.fixture.invalid','bot_username'=>'fixture_bot'];
    if ($x['op']==='step') $r=achbotServiceExecuteStep($db,$x['id'],$presentation);
    elseif ($x['op']==='due') $r=achbotServiceExecuteDue($db,$x['limit'],10.0,$presentation);
    elseif ($x['op']==='deliver') $r=achbotServiceDeliveryDispatch($db,$x['id']);
    elseif ($x['op']==='maintain_delivery') $r=achbotDeliveryMaintainPending($db,$x['limit'],10.0);
    elseif ($x['op']==='claim_delivery') {
        $db->begin_transaction();
        $d=achbotProviderRow($db,'SELECT * FROM payment_delivery_intents WHERE id=? FOR UPDATE','i',[$x['id']]);
        $r=achbotDeliveryClaimLocked($db,$d);$db->commit();
    }
    else {
        if (!$db->begin_transaction()) throw new RuntimeException('fixture_begin');
        $r=match($x['op']) {
            'issue'=>achbotServiceIssueLocked($db,1001,'fixture',$x['ref'],$x['quote']),
            'fund'=>achbotServiceFundLocked($db,1001,$x['id']),
        };
        if (!$db->commit()) throw new RuntimeException('fixture_commit');
    }
    if ($x['lost_ack']??false) exit(19);
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
            def reply_links(self,status,link):
                data=link.encode()
                self.send_response(status);self.send_header('Content-Type','text/plain')
                self.send_header('Content-Length',str(len(data)));self.end_headers()
                self.wfile.write(data)
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
                        elif re.fullmatch(r'/api/user/[0-9]+/subscription/links',self.path):
                            uid=int(self.path.split('/')[3])
                            assert any(v['id']==uid for v in users.values())
                            return self.reply_links(200,'vless://fixture-'+str(uid)+'@example.invalid:443#Original%20name\n')
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
                      '014_api_payment_evidence.sql','018_payment_delivery_intents.sql','022_gateway_review_archive.sql',
                      '023_scoped_payment_lock_indexes.sql','024_wallet_entries.sql',
                      '025_wallet_receipt_credit.sql','026_service_operations.sql'):
                sql((ROOT/'migrations/post-v9'/m).read_text())
            # Rehearse forward alteration with an original 018 row present.
            sql("""INSERT INTO pays(hash_id,user_id,type,price,request_date,state)
                VALUES ('legacy-delivery-fixture',1001,'INCREASE_WALLET',100,1700000000,'paid');
                INSERT INTO payment_attempts(pay_row_id,provider,order_hash,quote_amount,quote_currency,local_amount_toman,stage)
                SELECT id,'nextpay',hash_id,price,'toman',price,'done' FROM pays WHERE hash_id='legacy-delivery-fixture';
                INSERT INTO payment_delivery_intents(pay_row_id,ordinal,attempt_id,user_id,pay_type,pay_fingerprint,source_digest,sealed_payload,payload_sha256,state)
                SELECT p.id,1,a.id,p.user_id,p.type,REPEAT('a',64),REPEAT('b',64),'original-packet',REPEAT('c',64),'unknown'
                FROM pays p JOIN payment_attempts a ON a.pay_row_id=p.id WHERE p.hash_id='legacy-delivery-fixture';""")
            original=sql("SELECT id,pay_row_id,attempt_id,pay_type,sealed_payload,state FROM payment_delivery_intents")
            sql((ROOT/'migrations/post-v9/027_service_delivery_sources.sql').read_text())
            assert sql("SELECT id,pay_row_id,attempt_id,pay_type,sealed_payload,state FROM payment_delivery_intents")==original
            sql("""CREATE TABLE fixture_telegram (
                id INT AUTO_INCREMENT PRIMARY KEY, intent_id BIGINT UNSIGNED NOT NULL,
                user_id BIGINT NOT NULL, qr TEXT, caption TEXT, keys_json TEXT, bot_url TEXT) ENGINE=InnoDB""")

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
                if kw.get('lost_ack'):
                    assert p.returncode==19 and not p.stdout,(p.returncode,p.stdout,p.stderr)
                    return None
                assert p.returncode==0,(p.returncode,p.stdout,p.stderr)
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
                sql("UPDATE service_operations SET next_attempt_at=DATE_ADD(NOW(6),INTERVAL 1 DAY) WHERE stage IN ('funded','prepared','dispatching','applied')")
                sql("UPDATE payment_delivery_intents SET updated_at=NOW() WHERE service_operation_id IS NOT NULL AND state='pending'")
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
                assert finance()==before
                cases.append(mode+'_bulk_lost_response_exact_ordinal')
                # Verify atomic finalization after the Native success is
                # already committed. A failed delivery insert rolls back the
                # Order and link. Retry is GET-only, never another CREATE.
                sql("CREATE TRIGGER fixture_delivery_fail BEFORE INSERT ON payment_delivery_intents FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture rollback'")
                try:
                    step(mode,id,'pending','provider_storage_unavailable')
                    assert sql(f"SELECT COUNT(*) FROM service_effects WHERE source_kind='service_operation' AND source_ref='{id}' AND order_id IS NOT NULL")=='0'
                    assert sql(f"SELECT COUNT(*) FROM payment_delivery_intents WHERE service_operation_id={id}")=='0'
                finally:sql('DROP TRIGGER fixture_delivery_fail')
                retry(id)
                step(mode,id,'progressed')
                assert sql(f"SELECT COUNT(*) FROM payment_delivery_intents WHERE service_operation_id={id}")=='1'
                first_delivery=int(sql(f"SELECT id FROM payment_delivery_intents WHERE service_operation_id={id} AND ordinal=1"))
                assert php(mode,'deliver',id=first_delivery)=='not_ready'
                step(mode,id,'progressed')
                # The last ordinal and completed state commit together.
                php(mode,'step',id=id,lost_ack=True)
                step(mode,id,'completed')
                assert posts()==n+3 and finance()==before
                assert sql(f"SELECT COUNT(*),SUM(o.amount),SUM(o.provider_effect_id=e.id) FROM service_effects e JOIN orders_list o ON o.id=e.order_id WHERE e.source_kind='service_operation' AND e.source_ref='{id}'")=='3\t100\t3'
                assert sql(f"SELECT COUNT(*) FROM payment_delivery_intents WHERE service_operation_id={id} AND pay_row_id IS NULL AND attempt_id IS NULL AND state='pending'")=='3'
                assert all('\tlocal_recorded\t' in row for row in rows(id))
                cases.append(mode+'_atomic_order_delivery_and_completion')
                delivery_ids=list(map(int,sql(f"SELECT id FROM payment_delivery_intents WHERE service_operation_id={id} ORDER BY ordinal").splitlines()))
                # Actual common claim + finish journal with Telegram boundary
                # spy; no new money or Native work for acknowledgement loss.
                reqs=len(requests)
                with ThreadPoolExecutor(3) as ex:
                    outcomes=list(ex.map(lambda _:php(mode,'deliver',id=delivery_ids[0]),range(3)))
                assert 'acknowledged' in outcomes and set(outcomes)<={'acknowledged','busy'},outcomes
                assert sql(f"SELECT COUNT(*) FROM fixture_telegram WHERE intent_id={delivery_ids[0]}")=='1'
                wire=sql(f"SELECT user_id,qr,caption,keys_json FROM fixture_telegram WHERE intent_id={delivery_ids[0]}")
                assert wire.startswith('1001\t') and 'fixture_bot' in wire,wire
                assert sql(f"SELECT sealed_payload FROM payment_delivery_intents WHERE id={delivery_ids[0]}").startswith('achpe1:')
                assert php(mode,'deliver',id=delivery_ids[1],unknown_ack=True)=='unknown'
                assert php(mode,'deliver',id=delivery_ids[1])=='unknown'
                assert sql(f"SELECT COUNT(*) FROM fixture_telegram WHERE intent_id={delivery_ids[1]}")=='1'
                php(mode,'claim_delivery',id=delivery_ids[2])
                assert php(mode,'deliver',id=delivery_ids[2])=='unknown'
                assert sql(f"SELECT COUNT(*) FROM fixture_telegram WHERE intent_id={delivery_ids[2]}")=='0'
                assert sql(f"SELECT COUNT(*) FROM payment_delivery_attempts a JOIN payment_delivery_intents d ON d.id=a.intent_id WHERE d.service_operation_id={id} AND a.result IN ('acknowledged','unknown')")=='3'
                assert len(requests)==reqs and finance()==before
                step(mode,id,'completed')
                cases.append(mode+'_delivery_ack_unknown_and_abandoned_claim')

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
                # A sales-disabled server does not undo a verified purchase.
                sql('UPDATE server_info SET active=0 WHERE id=2')
                step(mode,id,'completed')
                sql('UPDATE server_info SET active=1 WHERE id=2')
                cases.append(mode+'_funded_order_finalizes_after_sales_disabled')
                delivery=int(sql(f"SELECT id FROM payment_delivery_intents WHERE service_operation_id={id}"))
                order=int(sql(f"SELECT order_id FROM payment_delivery_intents WHERE id={delivery}"))
                sql(f"UPDATE orders_list SET uuid='different-local-identity' WHERE id={order}")
                assert php(mode,'deliver',id=delivery)=='deferred'
                assert sql(f"SELECT COUNT(*) FROM fixture_telegram WHERE intent_id={delivery}")=='0'
                cases.append(mode+'_changed_order_no_stale_packet')
                next_id=issue(mode,'delivery-fair');fund(mode,next_id)
                step(mode,next_id,'applied');step(mode,next_id,'completed')
                next_delivery=int(sql(f"SELECT id FROM payment_delivery_intents WHERE service_operation_id={next_id}"))
                sql(f"UPDATE payment_delivery_intents SET updated_at=DATE_SUB(NOW(),INTERVAL 4 MINUTE) WHERE id IN ({delivery},{next_delivery})")
                reqs=len(requests);before=finance()
                stats=php(mode,'maintain_delivery',limit=2)
                assert stats==dict(checked=2,acknowledged=1,unknown=0,deferred=1),stats
                assert sql(f"SELECT COUNT(*) FROM fixture_telegram WHERE intent_id={next_delivery}")=='1'
                assert php(mode,'maintain_delivery',limit=2)['checked']==0
                assert len(requests)==reqs and finance()==before
                cases.append(mode+'_shared_delivery_worker_fair_progress')


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
                sql("UPDATE service_operations SET next_attempt_at=DATE_ADD(NOW(6),INTERVAL 1 DAY) WHERE stage='applied'")
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
