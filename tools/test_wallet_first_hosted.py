#!/usr/bin/env python3
"""Real registered hosted issuance/verification -> common Wallet settlement.

Exact source (except literal endpoint redirection in a disposable copy), real
curl HTTP against an owned loopback peer, SOAP argument spy, socket MariaDB.
No real merchant, Telegram or Native access. SOAP wire protocol itself is not
emulated by the spy; exact WebGate arguments and response handling are tested.
"""
from __future__ import annotations

import argparse
from concurrent.futures import ThreadPoolExecutor
import hashlib
import hmac
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
from pathlib import Path
import shutil
import subprocess
import threading
from urllib.parse import parse_qs

import package_current as package
import test_access_state as owned

ROOT = Path(__file__).resolve().parents[1]
DB = owned.NAMES[1]
PHP = r'''<?php
declare(strict_types=1);
mysqli_report($argv[4]==='strict'?MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT:MYSQLI_REPORT_OFF);
$telegramWebhookSecret='SyntheticHostedEvidenceSecret_2026';
$GLOBALS['telegramWebhookSecret']=$telegramWebhookSecret;
$GLOBALS['loopback']=$argv[6];
class SoapClient {
    function __construct($wsdl,$opts){
        if ($wsdl!=='https://www.zarinpal.com/pg/services/WebGate/wsdl' ||
            ($opts['connection_timeout']??null)!==5) throw new RuntimeException('fixture_soap_contract');
    }
    function request($op,$args){
        $ch=curl_init($GLOBALS['loopback'].'/soap/'.$op);
        curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($args),
            CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10]);
        $raw=curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);unset($ch);
        if ($raw===false || $code!==200) throw new RuntimeException('fixture_soap_unavailable');
        return (object)json_decode($raw,true,32,JSON_THROW_ON_ERROR);
    }
    function PaymentRequest($args){return $this->request('issue',$args);}
    function PaymentVerification($args){return $this->request('verify',$args);}
}
define('ACHBOT_NP_RECONCILE_ONLY',true);
require $argv[1].'/src/Finance/HostedReceipt.php';
require $argv[1].'/src/Finance/GatewayReturn.php';
$db=new mysqli('localhost','root','',$argv[3],0,$argv[2]);$db->set_charset('utf8mb4');
$c=json_decode($argv[5],true,512,JSON_THROW_ON_ERROR);
try {
    $id=(int)($c['id']??0);$owner=(int)($c['owner']??1001);
    $r=match ($c['op']) {
        'issue'=>achbotHostedReceiptIssue($db,$id,$owner,$c['method'],$c['service']??null,
            $c['quote']??[], $c['keys']??['nextpay'=>'next-original','zarinpal'=>'zarin-original','nowpayment'=>'np-original'],
            'https://bot.fixture.invalid/','Fixture description'),
        'verify'=>achbotHostedReceiptVerify($db,$id,$owner,$c['method'],$c['ref']),
        'ipn'=>achbotNpIngestIpn($db,'fixture-ipn',$c['raw'],$c['sig']),
        default=>throw new RuntimeException('fixture_unknown_op')
    };
    if ($c['lost_ack']??false) exit(19);
    echo json_encode(['ok'=>true,'result'=>$r],JSON_THROW_ON_ERROR);
} catch(Throwable $e) {
    $db->rollback();
    echo json_encode(['ok'=>false,'reason'=>$e->getMessage()],JSON_THROW_ON_ERROR);
}
'''


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--run-disposable',required=True,action='store_true')
    parser.add_argument('--report',type=Path)
    args=parser.parse_args()
    checks=[]
    with owned.owned_database() as (work,env):
        cli=['mariadb','--no-defaults','--protocol=SOCKET','--socket='+str(work/'mysql.sock'),'-uroot','--batch','--skip-column-names',DB]
        def sql(q):
            p=subprocess.run(cli,input=q,text=True,env=env,capture_output=True,timeout=20,check=True)
            return p.stdout.strip()
        for m in ('001_marzban_panel_group_id.sql','003_payment_attempts.sql',
                  '007_service_effects.sql','011_provider_identity.sql','012_shared_provider_storage.sql',
                  '014_api_payment_evidence.sql','022_gateway_review_archive.sql',
                  '023_scoped_payment_lock_indexes.sql','024_wallet_entries.sql',
                  '025_wallet_receipt_credit.sql','026_service_operations.sql'):
            sql((ROOT/'migrations/post-v9'/m).read_text())
        sql("""INSERT INTO server_config(id,panel_url,ip,sni,header_type,request_header,response_header,security,tlsSettings,type,username,password)
            VALUES (2,'https://fixture.invalid','127.0.0.1','','none','','','none','{}','pasarguard','fixture','fixture');
            INSERT INTO server_info(id,title,ucount,remark,flag,active,state) VALUES(2,'Fixture','100','fixture','',1,1);
            UPDATE server_config SET provider_connection_id=2,provider_instance_key=REPEAT('a',32),provider_version='5.5.1',provider_enabled=1 WHERE id=2;
            INSERT INTO server_plans(id,fileid,catid,server_id,inbound_id,acount,title,protocol,days,volume,type,price,descr,pic,active,step,date,custom_sni,provider_group_ids)
            VALUES(7,'7',1,2,0,6,'Fixture','vless',30,2,'normal',100,'description','',1,0,'fixture',NULL,'[1,2]');""")
        calls=[];responses={};errors=[];lock=threading.Lock()
        class Handler(BaseHTTPRequestHandler):
            def log_message(self,*a):pass
            def dispatch(self):
                try:
                    wire=self.rfile.read(int(self.headers.get('Content-Length',0)))
                    if self.path.startswith('/next/'):
                        body={k:v[0] for k,v in parse_qs(wire.decode()).items()}
                    else:body=json.loads(wire) if wire else {}
                    with lock:
                        calls.append((self.command,self.path,body,self.headers.get('X-Api-Key')))
                        response=responses[self.path]
                    # A second DB session can write the original rows during
                    # HTTP. This is a real check for released SQL row locks.
                    sql('SET innodb_lock_wait_timeout=1; UPDATE pays SET updated_at=NOW()')
                    if callable(response):response=response()
                    http,payload=response
                    data=json.dumps(payload).encode()
                    self.send_response(http);self.send_header('Content-Type','application/json')
                    self.send_header('Content-Length',str(len(data)));self.end_headers();self.wfile.write(data)
                except Exception as e:
                    errors.append(repr(e));self.send_error(500)
            do_POST=dispatch
            do_GET=dispatch
        server=ThreadingHTTPServer(('127.0.0.1',0),Handler)
        thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
        base='http://127.0.0.1:'+str(server.server_port)
        try:
            source=work/'source';members=set(package.members())|{'src/Finance/HostedMethods.php','src/Finance/HostedReceipt.php'}
            for member in members:
                target=source/member;target.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(ROOT/member,target)
            for file,replacements in {
                'src/Finance/HostedMethods.php':{
                    'https://nextpay.org/nx/gateway/token':base+'/next/issue',
                    'https://nextpay.org/nx/gateway/verify':base+'/next/verify',
                    'https://api.nowpayments.io/v1/invoice':base+'/np/issue'},
                'src/Finance/GatewayClient.php':{
                    'https://api.nowpayments.io/v1/payment/':base+'/np/payment/'},
            }.items():
                p=source/file;s=p.read_text()
                for before,after in replacements.items():
                    assert s.count(before)==1,(file,before)
                    s=s.replace(before,after)
                p.write_text(s)
            driver=work/'driver.php';driver.write_text(PHP)
            def run(mode,op,**kw):
                p=subprocess.run(['php','-n','-d','extension=mysqlnd','-d','extension=mysqli','-d','extension=curl',
                    '-d','allow_url_fopen=0','-d','disable_functions=exec,shell_exec,system,passthru,popen,proc_open,mail',
                    str(driver),str(source),str(work/'mysql.sock'),DB,mode,json.dumps(dict(op=op,**kw)),base],
                    env=env,capture_output=True,text=True,timeout=25)
                if kw.get('lost_ack'):
                    assert p.returncode==19 and not p.stdout,(p.returncode,p.stdout,p.stderr)
                    return None
                assert p.returncode==0,(p.returncode,p.stderr)
                assert all(x.startswith('[AChBot] ') for x in p.stderr.splitlines()),p.stderr
                return json.loads(p.stdout)
            def result(mode,op,**kw):
                r=run(mode,op,**kw);assert r['ok'],r
                return r['result']
            def seed(name,price=100,kind='INCREASE_WALLET'):
                sql(f"INSERT INTO pays(hash_id,user_id,type,price,request_date,state) VALUES ('{name}',1001,'{kind}',{price},UNIX_TIMESTAMP(),'pending')")
                return int(sql(f"SELECT id FROM pays WHERE hash_id='{name}'"))
            def snapshot():
                return tuple(sql(q) for q in [
                    'SELECT userid,wallet FROM users ORDER BY id',
                    'SELECT source_kind,source_ref,delta_toman FROM wallet_entries ORDER BY id',
                    'SELECT id,stage,COALESCE(wallet_credit_entry_id,0),COALESCE(wallet_funding_result,"") FROM payment_attempts ORDER BY id',
                    'SELECT id,stage,COALESCE(debit_entry_id,0) FROM service_operations ORDER BY id'])
            def bal():return int(sql('SELECT wallet FROM users WHERE userid=1001'))
            def reject(mode,op,reason,**kw):
                before=snapshot();start=len(calls)
                r=run(mode,op,**kw)
                assert r==dict(ok=False,reason=reason) and snapshot()==before and len(calls)==start,r
            def verify(mode,pid,method,ref,**kw):
                return result(mode,'verify',id=pid,method=method,ref=ref,**kw)
            def issue(mode,pid,method,**kw):
                return result(mode,'issue',id=pid,method=method,**kw)
            def sign(payload):
                raw=json.dumps(payload,separators=(',',':'),sort_keys=True)
                return dict(raw=raw,sig=hmac.new(b'fixture-ipn',raw.encode(),hashlib.sha512).hexdigest())
            for mode in ('strict','off'):
                def case(name):checks.append(mode+':'+name)
                pid=seed(mode+'-next')
                ref=mode+'-next-id'
                responses['/next/issue']=(200,dict(code=-1,trans_id=ref))
                b=bal();start=len(calls)
                r=issue(mode,pid,'nextpay')
                assert r==dict(status='issued',url='https://nextpay.org/nx/gateway/payment/'+ref),r
                assert calls[-1][2]==dict(api_key='next-original',amount='100',order_id=str(pid),currency='IRT',
                    callback_uri='https://bot.fixture.invalid/pay/back.php?nextpay&hash_id='+mode+'-next')
                assert issue(mode,pid,'nextpay')==r and len(calls)==start+1 and bal()==b
                assert sql(f"SELECT wallet_merchant_profile FROM payment_attempts WHERE pay_row_id={pid}").startswith('achpe1:')
                assert 'next-original' not in sql(f"SELECT wallet_merchant_profile FROM payment_attempts WHERE pay_row_id={pid}")
                case('nextpay_issue_once')
                reject(mode,'verify','hosted_reference_mismatch',id=pid,method='nextpay',ref='wrong-ref')
                reject(mode,'verify','payment_credit_owner_mismatch',id=pid,method='nextpay',ref=ref,owner=9002)
                responses['/next/verify']=(503,dict(code=0))
                assert verify(mode,pid,'nextpay',ref)['status']=='unavailable' and bal()==b
                responses['/next/verify']=(200,dict(code=-1))
                assert verify(mode,pid,'nextpay',ref)['status']=='pending' and bal()==b
                case('outage_and_pending_not_credited')
                responses['/next/verify']=(200,dict(code=0))
                assert verify(mode,pid,'nextpay',ref)['status']=='credited'
                assert calls[-1][2]==dict(api_key='next-original',amount='100',currency='IRT',trans_id=ref)
                start=len(calls)
                replay=verify(mode,pid,'nextpay',ref)
                assert replay['credit']['replayed'] and len(calls)==start and bal()==b+100
                assert sql(f"SELECT p.state,a.stage FROM pays p JOIN payment_attempts a ON p.id=a.pay_row_id WHERE p.id={pid}")=='paid\tdone'
                case('nextpay_receipt_once')
                pid=seed(mode+'-zarin');ref=mode+'-authority'
                responses['/soap/issue']=(200,dict(Status=100,Authority=ref))
                assert issue(mode,pid,'zarinpal')['status']=='issued'
                assert calls[-1][2]==dict(MerchantID='zarin-original',Amount=100,Description='Fixture description',Email='',Mobile='',
                    CallbackURL='https://bot.fixture.invalid/pay/back.php?zarinpal&hash_id='+mode+'-zarin')
                b=bal();responses['/soap/verify']=(200,dict(Status='100.9'))
                assert verify(mode,pid,'zarinpal',ref)['status']=='review' and bal()==b
                # Provider verified, local commit fails; next exact verification
                # returns documented already-verified status 101.
                responses['/soap/verify']=(200,dict(Status=100,RefID=91234))
                sql("CREATE TRIGGER fixture_credit_fail BEFORE UPDATE ON payment_attempts FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture'")
                try:
                    r=run(mode,'verify',id=pid,method='zarinpal',ref=ref);assert not r['ok'] and bal()==b,r
                finally:sql('DROP TRIGGER fixture_credit_fail')
                responses['/soap/verify']=(200,dict(Status=101,RefID=91234))
                assert verify(mode,pid,'zarinpal',ref)['status']=='credited' and bal()==b+100
                assert calls[-1][2]==dict(MerchantID='zarin-original',Authority=ref,Amount=100)
                assert verify(mode,pid,'zarinpal',ref)['credit']['replayed'] and bal()==b+100
                case('zarinpal_101_after_local_rollback')
                pid=seed(mode+'-np');ref=mode+'-invoice';child=mode+'-child'
                responses['/np/issue']=(200,dict(id=ref,invoice_url='https://nowpayments.io/payment/'+ref))
                b=bal()
                assert issue(mode,pid,'nowpayments',quote={'usd':'2.05'})['status']=='issued'
                assert calls[-1][2]['price_amount']==2.05 and calls[-1][2]['order_id']==mode+'-np' and calls[-1][3]=='np-original'
                reject(mode,'verify','hosted_child_not_known',id=pid,method='nowpayments',ref=child)
                payload=dict(payment_id=child,invoice_id=ref,order_id=mode+'-np',price_amount='2.05',price_currency='usd',payment_status='confirmed')
                assert result(mode,'ipn',**sign(payload))==200
                assert bal()==b
                responses['/np/payment/'+child]=(200,dict(payload,payment_status='finished',invoice_id='wrong'))
                assert verify(mode,pid,'nowpayments',child)['status']=='review' and bal()==b
                responses['/np/payment/'+child]=(200,dict(payload,payment_status='finished',price_amount='2.04'))
                assert verify(mode,pid,'nowpayments',child)['status']=='review' and bal()==b
                responses['/np/payment/'+child]=(200,dict(payload,payment_status='finished'))
                assert verify(mode,pid,'nowpayments',child)['status']=='credited' and bal()==b+100
                assert calls[-1][3]=='np-original'
                child2=child+'-second';payload2=dict(payload,payment_id=child2,payment_status='finished')
                assert result(mode,'ipn',**sign(payload2))==200
                responses['/np/payment/'+child2]=(200,payload2)
                assert verify(mode,pid,'nowpayments',child2)['credit']['replayed'] and bal()==b+100
                assert sql(f"SELECT COUNT(*) FROM payment_transactions t JOIN payment_attempts a ON a.id=t.attempt_id WHERE a.pay_row_id={pid}")=='2'
                case('signed_child_verified_exact_quote_once')
                pid=seed(mode+'-uncertain')
                responses['/next/issue']=(503,dict(code=-1,trans_id='lost-reference'))
                start=len(calls)
                assert issue(mode,pid,'nextpay')['status']=='issuance_uncertain'
                assert issue(mode,pid,'nextpay')['status']=='issuance_uncertain' and len(calls)==start+1
                case('uncertain_creation_not_reissued')
                # Actual new service funding handoff: create the immutable
                # quote via the common source, then lose service availability
                # while merchant creation or verification is in flight.
                qdriver=work/'service.php';qdriver.write_text("""<?php
require $argv[1].'/src/Services/Funding.php';
$db=new mysqli('localhost','root','',$argv[3],0,$argv[2]);
$db->begin_transaction();
$q=['kind'=>'BUY','variant'=>'standard','price'=>100,'server_id'=>2,'plan_id'=>7,'order_id'=>0,'quantity'=>1,'days'=>30,'volume'=>2,'remark'=>'fixture-12345','uuid'=>'','naming_mode'=>'digits','explicit_name'=>false];
$id=achbotServiceIssueLocked($db,1001,'fixture',$argv[4],$q);$db->commit();echo $id;
""")
                oid=int(subprocess.check_output(['php','-n','-d','extension=mysqlnd','-d','extension=mysqli',str(qdriver),str(source),str(work/'mysql.sock'),DB,mode+'-service'],env=env,text=True))
                pid=seed(mode+'-late',kind='BUY_SUB');ref=mode+'-late-id'
                responses['/next/issue']=(200,dict(code=-1,trans_id=ref))
                assert issue(mode,pid,'nextpay',service=oid)['status']=='issued'
                sql("UPDATE server_info SET active=0 WHERE id=2")
                responses['/next/verify']=(200,dict(code=0))
                b=bal();r=verify(mode,pid,'nextpay',ref)
                assert r['funding']=='request_invalidated' and bal()==b+100,r
                assert sql(f"SELECT stage FROM service_operations WHERE id={oid}")=='awaiting_funds'
                assert verify(mode,pid,'nextpay',ref)['credit']['replayed']
                assert bal()==b+100
                sql("UPDATE server_info SET active=1 WHERE id=2")
                case('actual_hosted_service_receipt_survives_disabled_panel')
                pid=seed(mode+'-lost-ack');ref=mode+'-ack-ref'
                responses['/next/issue']=(200,dict(code=-1,trans_id=ref))
                issue(mode,pid,'nextpay')
                b=bal()
                run(mode,'verify',id=pid,method='nextpay',ref=ref,lost_ack=True)
                assert verify(mode,pid,'nextpay',ref)['credit']['replayed'] and bal()==b+100
                case('actual_hosted_commit_ack_loss')
                pid=seed(mode+'-race');ref=mode+'-race-ref'
                responses['/next/issue']=(200,dict(code=-1,trans_id=ref))
                issue(mode,pid,'nextpay');b=bal()
                with ThreadPoolExecutor(4) as pool:
                    rs=list(pool.map(lambda _:verify(mode,pid,'nextpay',ref),range(4)))
                assert all(r['status']=='credited' for r in rs) and sum(not r['credit']['replayed'] for r in rs)==1 and bal()==b+100,rs
                case('parallel_actual_hosted_verification')
            assert not errors,errors
        finally:server.shutdown();server.server_close();thread.join(timeout=5)
    report=dict(complete=True,cases=len(checks),checks=checks,owned_loopback_requests=len(calls),
        actual_curl=True,soap_argument_spy=True,owned_socket_mariadb=True,external_requests=0)
    if args.report:args.report.write_text(json.dumps(report,indent=2)+'\n')
    print(json.dumps(report))


if __name__=='__main__':main()
