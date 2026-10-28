#!/usr/bin/env python3
"""Canonical checkout: real public PHP HTTP ingress with owned SQL/loopback only.

The complete issuance body is compared with its immutable pre-move source.
Provider callback/IPN bytes remain unchanged; no real provider is contacted.
"""
from __future__ import annotations
import argparse,contextlib,hashlib,json,socket,subprocess,time,urllib.error,urllib.request
from pathlib import Path
import package_post_v9 as package
import test_access_state as owned
import test_v6_native as native
import test_v8_native as purchase
ROOT=Path(__file__).resolve().parents[1]
DB=purchase.SIDE[1]
BASE='2c0e728dcd71c38e20d176fe2577bc264d3faa1a'

@contextlib.contextmanager
def http_server(work,env,app,socket_path,with_db=True):
 with socket.socket()as probe:probe.bind(('127.0.0.1',0));port=probe.getsockname()[1]
 log_path=work/('checkout-http-'+str(port)+'.log')
 with log_path.open('w+')as log:
  command=['php','-n']
  if with_db:command+=['-d','extension=mysqlnd','-d','extension=mysqli','-d','mysqli.default_socket='+str(socket_path)]
  command+=['-d','allow_url_fopen=0','-d','display_errors=1','-S','127.0.0.1:'+str(port),'-t',str(app)]
  process=subprocess.Popen(command,cwd=app,env=env,stdout=log,stderr=log)
  try:
   deadline=time.monotonic()+5
   while True:
    try:
     with socket.create_connection(('127.0.0.1',port),timeout=.2):break
    except OSError:
     if process.poll()is not None or time.monotonic()>=deadline:raise RuntimeError('owned_checkout_http_unavailable')
     time.sleep(.03)
   yield 'http://127.0.0.1:'+str(port)
  finally:process.terminate();process.wait(timeout=5)
 return log_path

class NoRedirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,*a,**kw):return None

def request(base,path,method='GET',data=None,headers=None):
 req=urllib.request.Request(base+path,data=data,headers=headers or {},method=method)
 try:
  response=urllib.request.build_opener(NoRedirect).open(req,timeout=8)
 except urllib.error.HTTPError as error:response=error
 with response:return response.status,dict(response.headers),response.read().decode('utf-8')

def main():
 parser=argparse.ArgumentParser();parser.add_argument('--run-disposable',required=True,action='store_true');parser.add_argument('--report',type=Path);args=parser.parse_args()
 checks=[]
 def yes(ok,name):
  if not ok:raise AssertionError(name)
  checks.append(name)
 source=(ROOT/'pay/checkout.php').read_bytes()
 old=subprocess.check_output(['git','show',BASE+':src/Finance/GatewayInitiation.php'],cwd=ROOT)
 marker=b'/**\n * Claim one gateway per local order'
 new_body=source[source.index(marker):]
 for current,original in [
  (b"__DIR__ . '/../src/Finance/PaymentLifecycle.php'",b"__DIR__ . '/PaymentLifecycle.php'"),
  (b"__DIR__.'/../src/Finance/ReceiptHelpers.php'",b"__DIR__.'/ReceiptHelpers.php'"),
  (b"__DIR__ . '/../src/Finance/PaymentPage.php'",b"__DIR__ . '/PaymentPage.php'"),
  (b'pay/checkout.php?',b'pay/?'),
 ]:new_body=new_body.replace(current,original)
 yes(new_body==old[old.index(marker):],'complete_issuance_logic_unchanged_except_location_and_retired_URL')
 callback=(ROOT/'pay/back.php').read_bytes()
 yes(callback==subprocess.check_output(['git','show',BASE+':pay/back.php'],cwd=ROOT),'browser_callback_and_POST_IPN_file_exact_bytes_unchanged')
 yes(not(ROOT/'pay/index.php').exists()and not(ROOT/'src/Finance/GatewayInitiation.php').exists(),'two_obsolete_initiation_files_deleted_without_alias')
 for rel in package.members():
  if rel.endswith('.php'):yes(b'pay/?'not in(ROOT/rel).read_bytes(),'runtime_has_no_retired_initiation_URL_'+rel)
 forbidden=['PRIVATE_CHECKOUT_DB_CANARY','PRIVATE_CHECKOUT_IPN_CANARY','SQLSTATE','mysqli','Fatal error','Warning:',str(ROOT),'/srv/ai-workspace/','GatewayInitiation.php']
 def private(body):return not any(value in body for value in forbidden)
 with owned.owned_database()as(work,env):
  purchase.init(work,env,'http://127.0.0.1:9')
  def sql(q):return native.sql(work,env,q,DB)
  for name in ['001_marzban_panel_group_id.sql','003_payment_attempts.sql','006_payment_message_refs.sql']:
   sql((ROOT/'migrations/post-v9'/name).read_text())
  app=work/'app'
  for rel in package.members():
   path=app/rel;path.parent.mkdir(parents=True,exist_ok=True);path.write_bytes((ROOT/rel).read_bytes())
  def snapshot():return sql('SELECT * FROM users ORDER BY id;SELECT * FROM pays ORDER BY id;SELECT * FROM orders_list ORDER BY id;SELECT * FROM server_info ORDER BY id;SELECT * FROM payment_attempts ORDER BY id;SELECT * FROM payment_transactions ORDER BY id')
  # Retired routes are absent and non-GET initiation is rejected before bootstrap.
  with http_server(work,env,app,work/'mysql.sock')as base:
   for method in ['HEAD','POST','PUT','PATCH','DELETE']:
    status,headers,body=request(base,'/pay/checkout.php?nowpayment&hash_id=not-issued',method,b'{}'if method!='HEAD'else None)
    yes(status==405 and headers.get('Allow')=='GET'and not body,'direct_'+method+'_rejected_before_missing_configuration')
   for path in ['/pay/index.php?nowpayment','/src/Finance/GatewayInitiation.php?nowpayment','/pay/?nowpayment']:
    status,headers,body=request(base,path)
    yes(status==404,'retired_route_no_alias_'+path.split('?')[0])
   status,headers,body=request(base,'/pay/checkout.php?nowpayment')
   yes(status==503 and private(body)and headers.get('Cache-Control')=='private, no-store, max-age=0','missing_private_configuration_is_private_503')
  # Actual shipping config catches unavailable mysqli without exposing inputs.
  private_config="<?php\n$dbUserName='fixture';$dbPassword='PRIVATE_CHECKOUT_DB_CANARY';$dbName='fixture';$nowpaymentsIpnSecret='PRIVATE_CHECKOUT_IPN_CANARY';\n"
  (app/'baseInfo.php').write_text(private_config)
  with http_server(work,env,app,work/'mysql.sock',with_db=False)as base:
   status,headers,body=request(base,'/pay/checkout.php?zarinpal&hash_id=not-issued')
   yes(status==503 and private(body)and 'try'not in body.lower(),'actual_config_database_unavailable_is_private_503')
   for method in ['HEAD','POST']:
    status,headers,body=request(base,'/pay/checkout.php?nowpayment',method,b'{}'if method=='POST'else None)
    yes(status==405 and not body,'direct_'+method+'_does_not_bootstrap_even_with_no_database_extension')
  # A synthetic bootstrap connects exclusively to this owned socket; the
  # checkout/controller/renderer/provider libraries remain shipping source.
  (app/'baseInfo.php').write_text("<?php\n$dbUserName='root';$dbPassword='';$dbName="+json.dumps(DB)+";$nowpaymentsIpnSecret='PRIVATE_CHECKOUT_IPN_CANARY';\n")
  bootstrap="""<?php
error_reporting(E_ALL & ~E_DEPRECATED);
set_error_handler(static function($n,$m){if($n===E_DEPRECATED)return true;throw new ErrorException($m,0,$n);});
file_put_contents(__DIR__.'/fixture-bootstrap-count','1',FILE_APPEND);
$connection=new mysqli('localhost','root','',"""+json.dumps(DB)+""",0,"""+json.dumps(str(work/'mysql.sock'))+""");
$connection->set_charset('utf8mb4');$botUsername='fixture_bot';$botUrl='https://bot.example.invalid/';
$botState=['nowPaymentWallet'=>'off','nowPaymentOther'=>'off','zarinpal'=>'off','nextpay'=>'off'];
$paymentKeys=[];
require_once __DIR__.'/src/Purchase/Core.php';
require_once __DIR__.'/src/Finance/ReceiptHelpers.php';
require_once __DIR__.'/src/Finance/GatewayClient.php';
"""
  (app/'config.php').write_text(bootstrap)
  before=snapshot()
  with http_server(work,env,app,work/'mysql.sock')as base:
   for query in ['', '?nowpayment&hash_id=unknown', '?zarinpal&hash_id=unknown','?nextpay&hash_id=unknown']:
    status,headers,body=request(base,'/pay/checkout.php'+query)
    yes(status==200 and '<html'in body.lower()and private(body)and'data-status="completed"'not in body,'direct_GET_runs_full_safe_controller_'+(query.split('&')[0]or'empty'))
    yes(headers.get('Cache-Control')=='private, no-store, max-age=0','checkout_response_is_no_store')
   count=(app/'fixture-bootstrap-count').read_bytes()
   yes(count==b'1111','direct_canonical_entry_bootstraps_once_per_GET')
   for method in ['HEAD','POST']:
    status,headers,body=request(base,'/pay/checkout.php?nextpay&hash_id=unknown',method,b'{}'if method=='POST'else None)
    yes(status==405 and(app/'fixture-bootstrap-count').read_bytes()==count,'non_GET_never_enters_invoice_bootstrap_'+method)
   # The original callback endpoint retains its independent POST contract.
   status,headers,body=request(base,'/pay/back.php?nowpayment_ipn=1')
   yes(status==405 and headers.get('Allow')=='POST','unchanged_IPN_route_rejects_GET_with_POST_contract')
   status,headers,body=request(base,'/pay/back.php?nowpayment_ipn=1','POST',b'{}',{'Content-Type':'application/json','X-Nowpayments-Sig':'invalid-fixture-signature'})
   yes(status in[400,401,403]and json.loads(body)=={'accepted':False},'unchanged_POST_IPN_reaches_authentication_and_denies_invalid_signature')
   yes((app/'fixture-bootstrap-count').read_bytes()==count,'POST_IPN_bypasses_Telegram_checkout_bootstrap')
  yes(before==snapshot(),'all_public_denials_leave_wallet_order_payment_attempts_unchanged')
  # Including the full controller with supplied context keeps library semantics:
  # it must not bootstrap this synthetic configuration again.
  library=work/'library-checkout.php'
  library.write_text("<?php\n$_SERVER['SCRIPT_FILENAME']=__FILE__;$_GET=[];$botUsername='fixture_bot';require "+json.dumps(str(app/'pay/checkout.php'))+";\n")
  count=(app/'fixture-bootstrap-count').read_bytes()
  run=subprocess.run(['php','-n','-d','display_errors=1',str(library)],capture_output=True,text=True,timeout=8)
  yes(run.returncode==0 and not run.stderr and'<html'in run.stdout.lower()and private(run.stdout),'indirect_include_executes_actual_implementation_without_bootstrap')
  yes((app/'fixture-bootstrap-count').read_bytes()==count,'indirect_include_does_not_load_private_configuration')
  logs='\n'.join(path.read_text()for path in work.glob('checkout-http-*.log'))
  yes('PRIVATE_CHECKOUT_DB_CANARY'not in logs and'PRIVATE_CHECKOUT_IPN_CANARY'not in logs,'HTTP_logs_contain_no_private_canary')
 report={'complete':True,'cases':len(checks),'checks':checks,'actual_public_controller':True,'native_http':True,
  'owned_socket_mariadb':True,'complete_issuance_body_preserved':True,'pay_back_bytes_unchanged':True,
  'callback_sha256':hashlib.sha256(callback).hexdigest(),'retired_routes_absent':True,'GET_only':True,
  'no_database_failure_private':True,'indirect_include_preserved':True,'external_requests':0,'production_mutations':0}
 if args.report:args.report.write_text(json.dumps(report,ensure_ascii=False,indent=2)+'\n')
 print(json.dumps(report,ensure_ascii=False))
if __name__=='__main__':main()
