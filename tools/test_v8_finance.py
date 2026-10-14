#!/usr/bin/env python3
"""V8 original V7 vs extracted finance routes on owned 23-table MariaDB.

These are native PHP/mysqli executions. No production services or credentials.
Generated test-only route excerpt is constrained to source-pinned V7 or V8.
"""
from __future__ import annotations
import argparse
import contextlib
import hashlib
import json
import re
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

import test_v8_native as n
import v8_contract as c
import test_v6_native as native
import test_access_state as support

SIDE = n.SIDE
ROUTE = {name.rsplit("/",1)[-1].replace(".php","").lower(): (name,start,end)
         for name,start,end in c.ROUTES}
TRIM_DDL = re.compile(r" AUTO_INCREMENT=\d+")
GATEWAY_INVOICE=b"https://api.nowpayments.io/v1/invoice"
GATEWAY_VERIFY=b"https://api.nowpayments.io/v1/payment/"
NEXTPAY_TOKEN=b"https://nextpay.org/nx/gateway/token"
NEXTPAY_VERIFY=b"https://nextpay.org/nx/gateway/verify"
TRON_ENDPOINT=b"https://apilist.tronscan.org/api/transaction-info?hash="
test_crypto_url=None


class OwnedGateway(BaseHTTPRequestHandler):
    server_version="AChBot-Owned-Fake-Payment"
    def log_message(self,*args):pass
    def do_POST(self):self.handle_payment()
    def do_GET(self):self.handle_payment()
    def handle_payment(self):
        server=self.server
        count=int(self.headers.get("Content-Length","0"))
        body=self.rfile.read(count) if count else b""
        server.trace.append({"method":self.command,"path":self.path,
          "body":body.decode("utf-8"),"api_key":self.headers.get("X-API-KEY","")})
        status=200
        if self.command=="POST" and self.path=="/v1/invoice":
            if server.mode=="invoice_http_500":
                status=500;payload={"message":"fixture-provider-503"}
            elif server.mode=="invoice_invalid":
                payload={"id":"NP-Fixture-001"}
            else:
                payload={"id":"NP-Fixture-001",
                    "invoice_url":"https://checkout.fixture.invalid/pay/1"}
        elif self.command=="GET" and self.path=="/v1/payment/NPTX-1":
            mapping={"callback_success":"finished","callback_partial":"partially_paid",
                "callback_canceled":"failed","callback_replay":"confirmed",
                "callback_provider_error":"unknown"}
            payload={"invoice_id":"NP-Fixture-001",
                     "payment_status":mapping.get(server.mode,"failed")}
            if server.mode=="callback_provider_error":status=503
        elif self.command=="POST" and self.path=="/nx/gateway/token":
            payload={"code":"-1","trans_id":"NXT-Fixture-001"}
        elif self.command=="POST" and self.path=="/nx/gateway/verify":
            payload={"code":"-2" if server.mode=="nextpay_verify_failed" else "0"}
        elif self.command=="POST" and self.path=="/soap/zarinpal/verify":
            details=json.loads(body.decode())
            if details.get("method")!="verify" or details["args"].get("MerchantID")!="owned-zarinpal":
                raise RuntimeError("Unexpected owned SOAP verification arguments")
            payload={"Status":101 if server.mode=="zarinpal_verify_failed" else 100}
        elif self.command=="POST" and self.path=="/soap/zarinpal/request":
            details=json.loads(body.decode())
            if details.get("method")!="request" or details["args"].get("MerchantID")!="owned-zarinpal":
                raise RuntimeError("Unexpected owned SOAP request arguments")
            payload={"Status":100,"Authority":"ZP-Fixture-001"}
        elif self.command=="GET" and self.path=="/api/transaction-info?hash=deadbeef":
            payload={"contractRet":"FAILED" if server.mode=="crypto_failed_contract" else "SUCCESS",
                "timestamp":server.crypto_timestamp,
                "tokenTransferInfo":{"to_address":"unrelated-wallet" if server.mode=="crypto_wrong_wallet" else "fixture-wallet",
                    "amount_str":"1500000" if server.mode=="crypto_amount_mismatch" else "500000"}}
        else:
            raise RuntimeError("Unexpected owned gateway request: "+self.command+" "+self.path)
        data=json.dumps(payload).encode()
        self.send_response(status)
        self.send_header("Content-Type","application/json")
        self.send_header("Content-Length",str(len(data)))
        self.end_headers()
        self.wfile.write(data)


@contextlib.contextmanager
def owned_gateway():
    server=ThreadingHTTPServer(("127.0.0.1",0),OwnedGateway)
    server.trace=[];server.mode="invoice_ok"
    server.crypto_timestamp=int(time.time()*1000)
    thread=threading.Thread(target=server.serve_forever,daemon=True)
    thread.start()
    try:
        yield server,"http://127.0.0.1:"+str(server.server_address[1])
    finally:
        server.shutdown();thread.join(timeout=6);server.server_close()


def rewrite_owned_gateway(root,modern,url):
    # Only test-owned staging copies. Exact matching proves no provider URL
    # survives the staging boundary for any scenario with valid invoices.
    url=url.encode()
    for old_name,new_name,needle,endpoint in (
       ("pay/index.php","src/Finance/GatewayInitiation.php",GATEWAY_INVOICE,b"/v1/invoice"),
       ("pay/back.php","src/Finance/GatewayReturn.php",GATEWAY_VERIFY,b"/v1/payment/"),
       ("pay/index.php","src/Finance/GatewayInitiation.php",NEXTPAY_TOKEN,b"/nx/gateway/token"),
       ("pay/back.php","src/Finance/GatewayReturn.php",NEXTPAY_VERIFY,b"/nx/gateway/verify"),
    ):
        path=root/(new_name if modern else old_name)
        raw=path.read_bytes()
        if raw.count(needle)!=1:raise RuntimeError("Gateway staging URL mismatch: "+str(path))
        changed=raw.replace(needle,url+endpoint)
        if any(live in changed for live in (GATEWAY_INVOICE,GATEWAY_VERIFY,NEXTPAY_TOKEN,NEXTPAY_VERIFY)):
            # Other fixtures can remain until their own replacement iteration.
            for remaining in (GATEWAY_INVOICE,GATEWAY_VERIFY,NEXTPAY_TOKEN,NEXTPAY_VERIFY):
                if remaining == needle and remaining in changed:
                    raise RuntimeError("Live gateway URL survived staged rewrite")
        path.write_bytes(changed)
    for rel in ("pay/index.php","pay/back.php","src/Finance/GatewayInitiation.php",
                "src/Finance/GatewayReturn.php"):
        path=root/rel
        if path.exists() and (rel.startswith("src/Finance/") == modern):
            if any(u in path.read_bytes() for u in (
                GATEWAY_INVOICE,GATEWAY_VERIFY,NEXTPAY_TOKEN,NEXTPAY_VERIFY)):
                raise RuntimeError("Real payment provider URL remains in owned fixture: "+rel)


def stage_finance(root: Path, modern: bool, gateway_url: str):
    n.stage(root,modern)
    (root/"pay").mkdir(exist_ok=True)
    for script in ("pay/index.php","pay/back.php"):
        (root/script).write_bytes((c.ROOT/script).read_bytes() if modern else c.old(script))
    for qr in ("phpqrcode/qrlib.php","phpqrcode/phpqrcode.php"):
        target=root/qr
        target.parent.mkdir(parents=True,exist_ok=True)
        target.write_bytes(c.old(qr))
    labels=root/"src/Presentation/Labels.php"
    labels.parent.mkdir(parents=True,exist_ok=True)
    labels.write_bytes(c.old("src/Presentation/Labels.php"))
    (root/"baseInfo.php").write_text("<?php // owned test placeholder only\n")
    (root/"config.php").write_text("<?php // owned safe gateway test stub; never contacts provider\n")
    # Extend only the owned copy of the immutable V7 PHP runner. The real
    # SoapClient WSDL and every provider HTTP path are prohibited in fixtures.
    harness=root/"native-purchase.php"
    source=harness.read_text()
    marker="try {require __DIR__.'/access-sequence.php';$ach_return=true;}"
    if source.count(marker)!=1:
        raise RuntimeError("Immutable V7 fixture entrypoint changed")
    soap_endpoint=json.dumps(gateway_url+"/soap/zarinpal/verify")
    soap_request=json.dumps(gateway_url+"/soap/zarinpal/request")
    adapters=r"""
function getMarzbanUser(...$args){
    $value=\AchBot\Panel\Accounts\getMarzbanUser(...$args);
    if (($GLOBALS['spec']['renew_fixture']??'')==='due' && isset($value->data_limit)) {
        $value->used_traffic=$value->data_limit;
    }
    return $value;
}
function editMarzbanConfig(...$args){return \AchBot\Panel\Accounts\editMarzbanConfig(...$args);}
function editClientTraffic(...$args){return \AchBot\Panel\Accounts\editClientTraffic(...$args);}
function editInboundTraffic(...$args){return \AchBot\Panel\Accounts\editInboundTraffic(...$args);}
class SoapClient {
    function __construct($wsdl,$options){
        if($wsdl!=='https://www.zarinpal.com/pg/services/WebGate/wsdl'){
            throw new RuntimeException('Blocked non-fixture WSDL');
        }
        achEvent('owned_soap_wsdl',[$wsdl,$options]);
    }
    private function request($url,$method,$args){
        $ch=curl_init($url);
        curl_setopt($ch,CURLOPT_POST,true);
        curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode(['method'=>$method,'args'=>$args]));
        curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
        $body=curl_exec($ch);
        curl_close($ch);
        if($body===false)throw new RuntimeException('Owned SOAP fixture failed');
        return (object)json_decode($body,true,512,JSON_THROW_ON_ERROR);
    }
    function PaymentRequest($args){return $this->request("__REQUEST__","request",$args);}
    function PaymentVerification($args){return $this->request("__VERIFY__","verify",$args);}
}
""".replace("__REQUEST__",json.loads(soap_request)).replace("__VERIFY__",json.loads(soap_endpoint))
    harness.write_text(source.replace(marker,adapters+"\n"+marker))
    rewrite_owned_gateway(root,modern,gateway_url)
    if modern:
        crypto=root/"src/Finance/CryptoChecker.php"
        content=crypto.read_bytes()
        if content.count(TRON_ENDPOINT)!=1:raise RuntimeError("Unexpected Tron external URL in staged PHP")
        if content.count(b"CURLOPT_VERBOSE, 1")!=1:
            raise RuntimeError("Unexpected historical Tron cURL verbose setting")
        crypto.write_bytes(content.replace(TRON_ENDPOINT,gateway_url.encode()+b"/api/transaction-info?hash=")
            .replace(b"CURLOPT_VERBOSE, 1",b"CURLOPT_VERBOSE, 0"))


def route_block(kind: str, modern: bool) -> bytes:
    global test_crypto_url
    if kind in ("automatic_receipts","automatic_renewal","crypto_checker","gateway_initiation","gateway_return"):
        code = b"<?php\n"
        if kind=="automatic_receipts":
            code += b"""$botState['cartToCartAutoAcceptState']='on';
$botState['cartToCartAutoAcceptTime']=10;
$botState['cartToCartAutoAcceptType']=$spec['autoType']??2;
function editKeys(...$args){achEvent('editKeys',$args);return (object)['ok'=>true];}
"""
            script="settings/rewardReport.php"
        elif kind=="automatic_renewal":
            code += b"$botState['renewAutoState']=$spec['enabled']??'off';\n"
            script="settings/warnusers.php"
        elif kind=="crypto_checker":
            code += b"""$paymentKeys['tronwallet']=$spec['wallet']??'';
"""
            script="settings/tronChecker.php"
        else:
            # Only prepare unknown gateways or absent invoice; real providers
            # are forbidden in these cases and never reached.
            code += b"""$_GET=$spec['query']??[];
$USDTPrice=100;
$USDTPriceagent=100;
$botUrl='http://bot.fixture.invalid/';
"""
            script="pay/index.php" if kind=="gateway_initiation" else "pay/back.php"
        module,begin,end=c.SCRIPTS[script][0]
        if modern:
            if kind.startswith("gateway"):
                code += b"require __DIR__ . '/" + script.encode() + b"';\n"
            else:
                code += b"require __DIR__ . '/" + module.encode() + b"';\n"
        else:
            if kind.startswith("gateway"):
                code += b"require __DIR__ . '/" + script.encode() + b"';\n"
            else:
                origin=c.old(script)
                start=origin.index(begin)
                stop=origin.index(end,start+len(begin)) if end is not None else len(origin)
                fragment=origin[start:stop]
                if kind=="crypto_checker":
                    if not test_crypto_url or fragment.count(TRON_ENDPOINT)!=1:
                        raise RuntimeError("Unowned/missing crypto network fixture")
                    if fragment.count(b"CURLOPT_VERBOSE, 1")!=1:
                        raise RuntimeError("Unexpected old Tron cURL verbose setting")
                    # Disable only diagnostic stderr noise in BOTH owned test copies;
                    # request payload/settlement SQL and real mysqli stay unchanged.
                    fragment=fragment.replace(TRON_ENDPOINT,test_crypto_url.encode()+b"/api/transaction-info?hash=")
                    fragment=fragment.replace(b"CURLOPT_VERBOSE, 1",b"CURLOPT_VERBOSE, 0")
                code += fragment
        return code
    if kind in ("discount_function", "referral_function", "wallet_refund_function"):
        code = b"""<?php
$mainValues += array_fill_keys(['not_valid_discount_code', 'discount_not_found',
  'discount_not_for_user','discount_not_for_service','discount_min_price',
  'discount_user_limit','valid_discount_code','used_discount_code'], 'fixture-message');
"""
        if kind=="discount_function":
            if modern:
                code += b"require __DIR__ . '/src/Finance/Discounts.php';\n"
            else:
                old=c.old("config.php")
                begin=b"function applyDiscountToPay("
                end=b"// END Apply Discount"
                if old.count(begin)!=1 or old.count(end)!=1:
                    raise RuntimeError("Discount function bounds drift")
                a=old.index(begin);b=old.index(end,a+len(begin))
                code += old[a:b] + b"\n"
            code += b"""$answer=applyDiscountToPay(
  $connection,(int)$spec['rowId'],$spec['discount'],$from_id,
  $username,$first_name,$admin,$mainValues,$botState);
achEvent('financial_function_result',[$answer]);
"""
        elif kind=="referral_function":
            code += b"""giveReferralReward(
  $connection,1001,9002,$admin,$botState,$spec['method']??null);
achEvent('financial_function_return',['referral']);
"""
        else:
            code += b"""refundToWalletAndNotify(
  $connection,1001,(int)($spec['amount']??200),'fixture-gateway',
  $admin,'Synthetic test refund',null,'Synthetic fixture reason');
achEvent('financial_function_return',['refund']);
"""
        return code
    if kind not in ROUTE:
        return n_original_block(kind, modern)
    module,start,end = ROUTE[kind]
    # The caller's original GOTOSTART label must remain in caller scope.
    code = b"<?php\nGOTOSTART:;\n"
    code += b"""$mainValues += array_fill_keys(['send_user_id','user_not_found','reached_main_menu',
      'enter_increase_amount','enter_decrease_amount','amount_decreased_from_your_wallet',
      'amount_decreased_from_user_wallet','not_valid_discount_code','discount_not_found',
      'discount_not_for_user','insert_discount_code','please_send_receipt_image',
      'please_send_only_image','order_submitted','increase_wallet_request_message'],
      'fixture-message');
$callbackId = 'fixture-callback';
function answerCallbackQuery(...$args) { achEvent('answerCallbackQuery',$args); }
function getDiscountCodeKeys($page=1) { return 'fixture-discount-keys'; }
"""
    if modern:
        code += (b"if (($achbotV8Result = require __DIR__ . '/" + module.encode() +
                 b"') === null) return;\n"
                 b"if ($achbotV8Result === '__ACHBOT_V8_GOTO_START__') goto GOTOSTART;\n")
    else:
        old = c.old("bot.php")
        if old.count(start)!=1 or old.count(end)!=1:
            raise RuntimeError("V7 route anchors not unique: "+module)
        a=old.index(start)
        b=old.index(end,a+len(start))
        code += old[a:b]
    code += b"achEvent('v8_final_data',[$data]);\n"
    return code


n_original_block = n.block
n.block = route_block


def sql(work, env, query, db):
    return native.sql(work,env,query,db)


def ddl(work,env,db):
    names = sql(work,env,"SHOW TABLES",db).splitlines()
    if len(names)!=23:
        raise RuntimeError("Unexpected schema size "+db)
    return names, [TRIM_DDL.sub("",sql(work,env,"SHOW CREATE TABLE `"+name+"`",db))
                   for name in names]


def finance_state(work,env,db):
    queries = {
        "users":"SELECT userid,wallet,step,isAdmin,is_agent,IFNULL(refered_by,'NULL') FROM users ORDER BY userid",
        "pays":"SELECT id,hash_id,user_id,type,plan_id,price,state,order_id,IFNULL(payid,'NULL') FROM pays ORDER BY id",
        "orders":"SELECT id,userid,fileid,remark,status,expire_date,autoRenew FROM orders_list ORDER BY id",
        "discounts":"SELECT id,hash_id,amount,type,expire_count,IFNULL(used_by,'NULL'),is_active FROM discounts ORDER BY id",
        "increase_order":"SELECT userid,server_id,inbound_id,remark,amount,date,volume,day FROM increase_order ORDER BY id",
        "setting":"SELECT type,value FROM setting ORDER BY type",
    }
    for table,key in (
        ("users","userid"),("pays","id"),("orders_list","id"),
        ("discounts","id"),("increase_order","id"),
        ("increase_day","id"),("increase_plan","id"),
        ("server_info","id"),("server_plans","id"),
        ("server_categories","id"),("server_config","id"),
        ("setting","type"),
    ):
        queries["complete_"+table]=f"SELECT * FROM `{table}` ORDER BY `{key}`"
    # One owned MariaDB connection per observation: output is still exactly
    # that of every individual SELECT, but avoids 18 CLI launches/side/step.
    # Explicit markers keep empty tables distinct from missing query results.
    marker="__ACHBOT_V8_FINANCE_SNAPSHOT_"
    batch="".join(
        "SELECT '"+marker+str(index)+"__'; "+q+";\n"
        for index,q in enumerate(queries.values())
    )
    response=sql(work,env,batch,db)
    result={}
    current=None
    collected=[]
    ids=list(queries)
    for line in response.splitlines(keepends=True):
        tag=line.rstrip("\n")
        if tag.startswith(marker):
            if current is not None:
                result[ids[current]]="".join(collected)
            suffix=tag[len(marker):]
            if not suffix.endswith("__") or not suffix[:-2].isdigit():
                raise RuntimeError("Invalid batch snapshot marker")
            index=int(suffix[:-2])
            if index!=len(result) or index>=len(ids):
                raise RuntimeError("Batch snapshot missing/duplicate/out-of-order query")
            current=index
            collected=[]
        elif current is None:
            raise RuntimeError("Batch snapshot unexpected leading SQL output")
        else:
            collected.append(line)
    if current is None:
        raise RuntimeError("Batch snapshot returned no results")
    result[ids[current]]="".join(collected)
    if len(result)!=len(ids):
        raise RuntimeError("Batch snapshot omitted tables")
    return result


# The immutable legacy PHP uses wall-clock time() and uniqid() directly.
# Bound these two unmockable data sources instead of requiring subsecond
# equality between separate V7/V8 PHP processes. All other row bytes, effects
# and provider request fields remain compared without transformation.
FRESH_PAYMENT_ID=re.compile(r"(?<![a-z0-9])pay_[0-9a-f]{13}(?![a-z0-9])")
UNIX_SECONDS=re.compile(r"(?<![0-9])1[6-9][0-9]{8}(?![0-9])")


def canonical_execution(value,now):
    if isinstance(value,dict):
        return {key:canonical_execution(v,now) for key,v in value.items()}
    if isinstance(value,list):
        return [canonical_execution(v,now) for v in value]
    if not isinstance(value,str):
        return value
    result=FRESH_PAYMENT_ID.sub("<fixture-pay-uniqid>",value)
    def normalize_time(m):
        observed=int(m.group())
        for delta,label in ((0,"now"),(30*86400,"now+30d"),(5*86400,"now+5d")):
            if abs(observed-(now+delta))<=5:
                return "<fixture-"+label+">"
        return m.group()
    return UNIX_SECONDS.sub(normalize_time,result)


def seed_pay(work,env,db,record,extra=""):
    state=record.get("state","pending")
    pay_type=record.get("type","BUY_SUB")
    date_expr=record.get("date","UNIX_TIMESTAMP()")
    price=int(record.get("price",100))
    fid=int(record.get("plan",7))
    h=record.get("hash","abcdefgh")
    assert h.isalnum() and state.replace("_","").isalnum() and pay_type.isidentifier()
    sql(work,env,(
        "INSERT INTO pays (hash_id,user_id,type,plan_id,price,request_date,state) "
        f"VALUES ('{h}',1001,'{pay_type}',{fid},{price},{date_expr},'{state}');"
        +extra),db)


def flows():
    # Each series has persisted state across requests, reconstructed independently
    # for old and candidate. Duplicate/replay must preserve inherited behavior.
    return {
      "prepay_missing":[("prepayment",dict(actor=1001,data="prePayCheck_BUY_Wallet_abcdefgh"))],
      "prepay_expired_pending":[("prepayment",dict(actor=1001,data="prePayCheck_BUY_Wallet_abcdefgh"))],
      "prepay_expired_paid":[("prepayment",dict(actor=1001,data="prePayCheck_BUY_Wallet_abcdefgh"))],
      "prepay_renew_mismatch":[("prepayment",dict(actor=1001,data="prePayCheck_RENEW_Wallet_abcdefgh"))],
      "prepay_wallet_dispatch":[("prepayment",dict(actor=1001,data="prePayCheck_BUY_Wallet_abcdefgh"))],
      "prepay_card_dispatch":[("prepayment",dict(actor=1001,data="prePayCheck_BUY_CartToCart_abcdefgh"))],
      "prepay_gateway_redirect":[("prepayment",dict(actor=1001,data="prePayCheck_BUY_Zarinpal_abcdefgh"))],
      "prepay_unknown_gateway":[("prepayment",dict(actor=1001,data="prePayCheck_BUY_Unknown_abcdefgh"))],
      "wallet_transfer":[
         ("wallettransfer",dict(actor=1001,data="transferMyWallet")),
         ("wallettransfer",dict(actor=1001,text="9002")),
         ("wallettransfer",dict(actor=1001,text="300")),
      ],
      "wallet_transfer_insufficient":[
         ("wallettransfer",dict(actor=1001,data="transferMyWallet")),
         ("wallettransfer",dict(actor=1001,text="9002")),
         ("wallettransfer",dict(actor=1001,text="999999")),
      ],
      "wallet_transfer_invalid_target":[
         ("wallettransfer",dict(actor=1001,data="transferMyWallet")),
         ("wallettransfer",dict(actor=1001,text="99998877")),
      ],
      "wallet_transfer_self":[
         ("wallettransfer",dict(actor=1001,data="transferMyWallet")),
         ("wallettransfer",dict(actor=1001,text="1001")),
      ],
      "wallet_transfer_zero":[
         ("wallettransfer",dict(actor=1001,data="transferMyWallet")),
         ("wallettransfer",dict(actor=1001,text="9002")),
         ("wallettransfer",dict(actor=1001,text="0")),
      ],
      "admin_wallet_credit":[
         ("adminwallet",dict(actor=9001,data="increaseUserWallet")),
         ("adminwallet",dict(actor=9001,text="1001")),
         ("adminwallet",dict(actor=9001,text="800")),
      ],
      "admin_wallet_debit":[
         ("adminwallet",dict(actor=9001,data="decreaseUserWallet")),
         ("adminwallet",dict(actor=9001,text="1001")),
         ("adminwallet",dict(actor=9001,text="800")),
      ],
      "admin_wallet_unauthorized":[
         ("adminwallet",dict(actor=1001,data="increaseUserWallet")),
         ("adminwallet",dict(actor=1001,text="9002")),
      ],
      "auto_card_wallet_credit":[
          ("automatic_receipts",dict(actor=1001)),
          ("automatic_receipts",dict(actor=1001)),
      ],
      "auto_card_wallet_agent_skipped":[("automatic_receipts",dict(actor=1001,autoType=1))],
      "qr_checkout_external_cwd":[("checkout",dict(actor=1001,data="showQrSub91"))],
      "manual_renew_quote":[("renewalcheckout",dict(actor=1001,data="renewAccount91"))],
      "manual_renew_paid":[
          ("renewalcheckout",dict(actor=1001,data="renewAccount91")),
          ("renewalcheckout",dict(actor=1001,data="payRenewWithWalletxxxxxxxxx")),
          ("renewalcheckout",dict(actor=1001,data="payRenewWithWalletxxxxxxxxx")),
      ],
      "manual_renew_low_wallet":[
          ("renewalcheckout",dict(actor=1001,data="renewAccount91")),
          ("renewalcheckout",dict(actor=1001,data="payRenewWithWalletxxxxxxxxx")),
      ],
      "extension_day_quote":[("extensions",dict(actor=1001,data="selectPlanDayIncrease91_1"))],
      "extension_day_wallet":[
          ("extensions",dict(actor=1001,data="selectPlanDayIncrease91_1")),
          ("extensions",dict(actor=1001,data="payIncreaseDayWithWalletxxxxxxxxx")),
          ("extensions",dict(actor=1001,data="payIncreaseDayWithWalletxxxxxxxxx")),
      ],
      "extension_day_low_wallet":[
          ("extensions",dict(actor=1001,data="selectPlanDayIncrease91_1")),
          ("extensions",dict(actor=1001,data="payIncreaseDayWithWalletxxxxxxxxx")),
      ],
      "extension_volume_quote":[("extensions",dict(actor=1001,data="increaseVolumePlan91_1"))],
      "extension_volume_wallet":[
          ("extensions",dict(actor=1001,data="increaseVolumePlan91_1")),
          ("extensions",dict(actor=1001,data="payIncreaseWithWalletxxxxxxxxx")),
          ("extensions",dict(actor=1001,data="payIncreaseWithWalletxxxxxxxxx")),
      ],
      "extension_volume_expired":[("extensions",dict(actor=1001,data="increaseAVolume91"))],
      "auto_renew_eligible":[("automatic_renewal",dict(actor=1001,enabled="on",renew_fixture="due"))],
      "auto_renew_low_wallet":[("automatic_renewal",dict(actor=1001,enabled="on",renew_fixture="due"))],
      "auto_renew_off":[("automatic_renewal",dict(actor=1001,enabled="off"))],
      "auto_renew_no_orders":[("automatic_renewal",dict(actor=1001,enabled="on"))],
      "crypto_empty":[("crypto_checker",dict(actor=1001,wallet="fixture-wallet"))],
      "crypto_without_wallet":[("crypto_checker",dict(actor=1001,wallet=""))],
      "crypto_paid_wallet":[("crypto_checker",dict(actor=1001,wallet="fixture-wallet"))],
      "crypto_failed_flag_inherited":[("crypto_checker",dict(actor=1001,wallet="fixture-wallet"))],
      "crypto_wrong_wallet":[("crypto_checker",dict(actor=1001,wallet="fixture-wallet"))],
      "crypto_amount_mismatch":[("crypto_checker",dict(actor=1001,wallet="fixture-wallet"))],
      "payment_index_unknown":[("gateway_initiation",dict(actor=1001,query={"hash_id":"abcdefgh"}))],
      "payment_index_missing":[("gateway_initiation",dict(actor=1001,query={"hash_id":"abcdefgh","zarinpal":""}))],
      "payment_back_no_callback":[("gateway_return",dict(actor=1001,query={}))],
      "payment_invoice_created":[("gateway_initiation",dict(actor=1001,query={"hash_id":"abcdefgh","nowpayment":""}))],
      "payment_invoice_http_error":[("gateway_initiation",dict(actor=1001,query={"hash_id":"abcdefgh","nowpayment":""}))],
      "payment_invoice_invalid_body":[("gateway_initiation",dict(actor=1001,query={"hash_id":"abcdefgh","nowpayment":""}))],
      "payment_zarinpal_initiation":[
          ("gateway_initiation",dict(actor=1001,query={"zarinpal":"","hash_id":"abcdefgh"}))],
      "payment_nextpay_initiation":[
          ("gateway_initiation",dict(actor=1001,query={"nextpay":"","hash_id":"abcdefgh"}))],
      "payment_zarinpal_verify_success":[
          ("gateway_return",dict(actor=1001,query={
              "zarinpal":"","hash_id":"abcdefgh","Status":"OK","Authority":"ZP-Fixture-001"})),
          ("gateway_return",dict(actor=1001,query={
              "zarinpal":"","hash_id":"abcdefgh","Status":"OK","Authority":"ZP-Fixture-001"})),
      ],
      "payment_zarinpal_verify_failed":[
          ("gateway_return",dict(actor=1001,query={
              "zarinpal":"","hash_id":"abcdefgh","Status":"OK","Authority":"ZP-Fixture-001"}))],
      "payment_nextpay_verify_success":[
          ("gateway_return",dict(actor=1001,query={
              "nextpay":"","trans_id":"NXT-Fixture-001"})),
          ("gateway_return",dict(actor=1001,query={
              "nextpay":"","trans_id":"NXT-Fixture-001"})),
      ],
      "payment_nextpay_verify_failed":[
          ("gateway_return",dict(actor=1001,query={
              "nextpay":"","trans_id":"NXT-Fixture-001"}))],
      "payment_callback_success":[
          ("gateway_return",dict(actor=1001,query={"nowpayment":"","NP_id":"NPTX-1"})),
          ("gateway_return",dict(actor=1001,query={"nowpayment":"","NP_id":"NPTX-1"})),
      ],
      "payment_callback_partial":[("gateway_return",dict(actor=1001,query={"nowpayment":"","NP_id":"NPTX-1"}))],
      "payment_callback_canceled":[("gateway_return",dict(actor=1001,query={"nowpayment":"","NP_id":"NPTX-1"}))],
      "payment_callback_provider_error":[("gateway_return",dict(actor=1001,query={"nowpayment":"","NP_id":"NPTX-1"}))],
      "discount_percent":[("discount_function",dict(actor=1001,discount="V8CODE"))],
      "discount_fixed_capped":[("discount_function",dict(actor=1001,discount="V8CODE"))],
      "discount_exhausted":[("discount_function",dict(actor=1001,discount="V8CODE"))],
      "discount_expired":[("discount_function",dict(actor=1001,discount="V8CODE"))],
      "discount_min_price":[("discount_function",dict(actor=1001,discount="V8CODE"))],
      "discount_wrong_audience":[("discount_function",dict(actor=1001,discount="V8CODE"))],
      "discount_agent_allowed":[("discount_function",dict(actor=9002,discount="V8CODE"))],
      "discount_wrong_plan":[("discount_function",dict(actor=1001,discount="V8CODE"))],
      "discount_repeat":[
         ("discount_function",dict(actor=1001,discount="V8CODE")),
         ("discount_function",dict(actor=1001,discount="V8CODE")),
      ],
      "referral_nonwallet":[("referral_function",dict(actor=1001,method="zarinpal"))],
      "referral_wallet_excluded":[("referral_function",dict(actor=1001,method="wallet"))],
      "refund_wallet":[("wallet_refund_function",dict(actor=1001,amount=200))],
      "discount_entry":[("discountentry",dict(actor=1001,data="haveDiscountRenew_7_9"))],
      "discount_admin_unauthorized":[("discountadministration",dict(actor=1001,data="discount_codes"))],
      "discount_admin_list":[("discountadministration",dict(actor=9001,data="discount_codes"))],
      "discount_admin_toggle_missing":[("discountadministration",dict(actor=9001,data="toggleDiscount777"))],
      "discount_admin_toggle_existing":[
          ("discountadministration",dict(actor=9001,data="toggleDiscount<seeded>")),
          ("discountadministration",dict(actor=9001,data="toggleDiscount<seeded>")),
      ],
      "discount_admin_create":[
          ("discountadministration",dict(actor=9001,data="addDiscountCode")),
          ("discountadministration",dict(actor=9001,text="V8NEW")),
          ("discountadministration",dict(actor=9001,text="20%")),
          ("discountadministration",dict(actor=9001,text="0")),
          ("discountadministration",dict(actor=9001,text="3")),
          ("discountadministration",dict(actor=9001,text="2")),
          ("discountadministration",dict(actor=9001,data="setDiscountAudience_all")),
          ("discountadministration",dict(actor=9001,data="setDiscountTarget_all")),
          ("discountadministration",dict(actor=9001,text="10")),
          ("discountadministration",dict(actor=9001,text="25")),
      ],
      "discount_admin_create_invalid":[
          ("discountadministration",dict(actor=9001,data="addDiscountCode")),
          ("discountadministration",dict(actor=9001,text="INVALID CODE!")),
      ],
    }


def prepare_label(work,env,label,url):
    n.init(work,env,url)
    # One synthetic payment creation instant across old/candidate DBs.
    # Otherwise even immutable baseline fixture setup diverges on time().
    fixture_now=int(time.time())
    for db in SIDE:
        sql(work,env,"DELETE FROM discounts; DELETE FROM increase_order; DELETE FROM setting WHERE type IN ('INVITE_BANNER_AMOUNT','AutoRenewCheck','PAYMENT_KEYS')",db)
        if label.startswith(("manual_renew_","extension_","auto_renew_")) or label.startswith("qr_checkout_"):
            # n.init deliberately clears V6 fixture orders. Seed a genuinely
            # owned paid order; otherwise renewal/extension tests pass vacuously.
            sql(work,env,"INSERT INTO orders_list "
                "(id,userid,token,transid,fileid,server_id,inbound_id,remark,uuid,"
                "protocol,expire_date,link,amount,status,date,notif,autoRenew) VALUES "
                "(91,1001,REPEAT('a',30),'fixture',7,2,0,'fixture-user',"
                "'fixture-uuid','vless',1800000100,'[\"vless://fixture\"]',"
                "100,1,'fixture',1,'off')",db)
            if label.endswith("low_wallet"):
                sql(work,env,"UPDATE users SET wallet=25 WHERE userid=1001",db)
            if label=="extension_volume_expired":
                sql(work,env,"UPDATE orders_list SET expire_date=1000000000 WHERE id=91",db)
            if label.startswith("auto_renew_") and label not in ("auto_renew_off","auto_renew_no_orders"):
                sql(work,env,"UPDATE orders_list SET server_id=2, inbound_id=0, "
                             "notif=1, autoRenew='on' WHERE id=91",db)
            if label.startswith("extension_day"):
                sql(work,env,"DELETE FROM increase_day; INSERT INTO increase_day "
                             "(id,volume,price) VALUES (1,5,75)",db)
            if label.startswith("extension_volume"):
                sql(work,env,"DELETE FROM increase_plan; INSERT INTO increase_plan "
                             "(id,volume,price) VALUES (1,3,90)",db)
        if label.startswith("prepay_") and label != "prepay_missing":
            fields = {"prepay_expired_pending":{"date":"UNIX_TIMESTAMP()-10801"},
                      "prepay_expired_paid":{"date":"UNIX_TIMESTAMP()-10801","state":"paid"},
                      "prepay_renew_mismatch":{"type":"RENEW_ACCOUNT"}}.get(label,{}).copy()
            fields["date"]=str(fixture_now - 10801 if "expired" in label else fixture_now)
            seed_pay(work,env,db,fields)
        if label.startswith("discount_") and label not in (
              "discount_entry","discount_admin_unauthorized","discount_admin_list","discount_admin_toggle_missing","discount_admin_toggle_existing","discount_admin_create","discount_admin_create_invalid"):
            seed_pay(work,env,db,{"price":101,"date":"1780000000"})
            settings={
              "discount_percent":{"type":"percent","amount":20},
              "discount_fixed_capped":{"type":"fixed","amount":25,"max_discount":10},
              "discount_exhausted":{"count":0},
              "discount_expired":{"expire":1600000000},
              "discount_min_price":{"min_price":1000},
              "discount_wrong_audience":{"audience":"agents"},
              "discount_agent_allowed":{"audience":"agents"},
              "discount_wrong_plan":{"target":"plan","target_id":999},
              "discount_repeat":{"can_use":1},
            }[label]
            amount=settings.get("amount",20)
            discount_type=settings.get("type","percent")
            count=settings.get("count",3)
            expire=settings.get("expire",2147480000)
            min_price=settings.get("min_price",0)
            max_discount=settings.get("max_discount",0)
            audience=settings.get("audience","all")
            target=settings.get("target","all")
            target_id=settings.get("target_id",0)
            can_use=settings.get("can_use",3)
            sql(work,env,(
              "INSERT INTO discounts (hash_id,type,amount,expire_date,expire_count,"
              "can_use,used_by,audience_type,target_type,target_id,min_price,max_discount) "
              f"VALUES ('V8CODE','{discount_type}',{amount},{expire},{count},"
              f"{can_use},'[]','{audience}','{target}',{target_id},{min_price},{max_discount})"),db)
        if label.startswith("auto_card_wallet"):
            seed_pay(work,env,db,{"state":"have_sent","type":"INCREASE_WALLET","price":150,"date":"1780000000"})
            if label.endswith("agent_skipped"):
                sql(work,env,"UPDATE users SET is_agent=1 WHERE userid=1001",db)
            if label.endswith("agent_skipped"):
                # Original cron agent type 1 declines agent auto-accept.
                pass
        if label.startswith(("payment_zarinpal","payment_nextpay")):
            payid="NXT-Fixture-001" if "nextpay_verify" in label else ""
            seed_pay(work,env,db,{"price":150,"type":"INCREASE_WALLET","date":"1780000000"},
                     "UPDATE pays SET payid='"+payid+"' WHERE hash_id='abcdefgh';")
            sql(work,env,"INSERT INTO setting (type,value) VALUES "
                         "('PAYMENT_KEYS','{\"zarinpal\":\"owned-zarinpal\","
                         "\"nextpay\":\"owned-nextpay\"}')",db)
        if label.startswith("payment_invoice"):
            seed_pay(work,env,db,{"price":150,"type":"INCREASE_WALLET","date":"1780000000"})
            sql(work,env,"INSERT INTO setting (type,value) VALUES "
                         "('PAYMENT_KEYS','{\"nowpayment\":\"owned-fixture-key\"}')",db)
        if label.startswith("payment_callback"):
            seed_pay(work,env,db,{"price":150,"type":"INCREASE_WALLET","date":"1780000000"},
                     "UPDATE pays SET payid='NP-Fixture-001' WHERE hash_id='abcdefgh';")
            sql(work,env,"INSERT INTO setting (type,value) VALUES "
                         "('PAYMENT_KEYS','{\"nowpayment\":\"owned-fixture-key\"}')",db)
        if label=="crypto_without_wallet" or label in (
            "crypto_paid_wallet","crypto_failed_flag_inherited",
            "crypto_wrong_wallet","crypto_amount_mismatch"):
            seed_pay(work,env,db,{"state":"1234","type":"INCREASE_WALLET","price":150,"date":"1780000000"},
                     "UPDATE pays SET payid='deadbeef' WHERE hash_id='abcdefgh';")
        if label.startswith("referral_"):
            sql(work,env,"INSERT INTO setting (type,value) VALUES ('INVITE_BANNER_AMOUNT','150')",db)
        if label=="discount_admin_toggle_existing":
            sql(work,env,"INSERT INTO discounts (hash_id,type,amount,expire_date,expire_count) "
                         "VALUES ('V8TOGGLE','percent',15,0,3)",db)
        if label=="discount_admin_list":
            sql(work,env,"INSERT INTO discounts (hash_id,type,amount,expire_date,expire_count) "
                         "VALUES ('V8FIX','percent',20,0,3)",db)


def assert_expected(label,state,events):
    new=dict(zip(SIDE,state))[SIDE[1]]
    # DB effects, not only differential equality, are checked.
    wallet={int(x.split('\t')[0]):int(x.split('\t')[1]) for x in new["users"].splitlines() if x.strip()}
    pays=new["pays"].splitlines()
    if label=="wallet_transfer" and (wallet[1001],wallet[9002])!=(49700,50300):
        raise RuntimeError("Wallet transfer financial outcome invalid")
    if label=="admin_wallet_credit" and wallet[1001]!=50800:
        raise RuntimeError("Admin credit did not persist")
    if label=="admin_wallet_debit" and wallet[1001]!=49200:
        raise RuntimeError("Admin debit did not persist")
    if label in ("wallet_transfer_insufficient","wallet_transfer_invalid_target","wallet_transfer_self",
                 "wallet_transfer_zero","admin_wallet_unauthorized") and wallet[1001]!=50000:
        raise RuntimeError("Negative transfer/authorization changed wallet")
    if label=="prepay_expired_pending" and not any("\texpired\t" in p for p in pays):
        raise RuntimeError("Expired pending invoice not updated")
    if label=="prepay_expired_paid" and not any("\tpaid\t" in p for p in pays):
        raise RuntimeError("Inherited paid invoice expired unexpectedly")
    if label=="prepay_renew_mismatch" and not any("\tmismatched_plan\t" in p for p in pays):
        raise RuntimeError("Renewal mismatch state not persisted")
    if label=="prepay_wallet_dispatch" and not any("payBUYWithWalletabcdefgh" in str(e) for e in events):
        raise RuntimeError("Wallet dispatch callback did not survive")
    if label=="prepay_card_dispatch" and not any("payBUYWithCartToCartabcdefgh" in str(e) for e in events):
        raise RuntimeError("Card dispatch callback did not survive")
    if label=="discount_percent" and not any("\t81\t" in "\t"+p+"\t" for p in pays):
        raise RuntimeError("Percent discount amount not applied")
    if label=="discount_fixed_capped" and not any("\t91\t" in "\t"+p+"\t" for p in pays):
        raise RuntimeError("Fixed capped discount amount not applied "+repr(pays))
    if label in ("discount_exhausted","discount_expired","discount_min_price",
                 "discount_wrong_audience","discount_wrong_plan") and not any("\t101\t" in "\t"+p+"\t" for p in pays):
        raise RuntimeError("Rejected discount changed invoice")
    if label=="discount_repeat" and not any("\t81\t" in "\t"+p+"\t" for p in pays):
        raise RuntimeError("First discount redemption lost")
    if label=="referral_nonwallet" and wallet[9002]!=50150:
        raise RuntimeError("Referral reward credit mismatch")
    if label=="referral_wallet_excluded" and wallet[9002]!=50000:
        raise RuntimeError("Wallet payment rewarded referral")
    if label=="refund_wallet" and wallet[1001]!=50200:
        raise RuntimeError("Refund wallet credit mismatch")
    if label in ("crypto_paid_wallet","crypto_failed_flag_inherited"):
        if wallet[1001]!=50150 or not any("\tapproved\t" in p for p in pays):
            raise RuntimeError("Inherited crypto wallet settlement no longer matches legacy")
    if label in ("crypto_wrong_wallet","crypto_amount_mismatch") and wallet[1001]!=50000:
        raise RuntimeError("Crypto invalid amount/address credited wallet")
    if label=="crypto_wrong_wallet" and not any("\tdeclined\t" in p for p in pays):
        raise RuntimeError("Wrong Tron recipient did not decline after inherited retry state")
    if label=="crypto_amount_mismatch" and not any("\tpartially_paid\t" in p for p in pays):
        raise RuntimeError("Out-of-band Tron amount not flagged partially paid")
    if label=="auto_card_wallet_credit":
        if wallet[1001]!=50150 or not any("\tauto_cart_approved\t" in x for x in pays):
            raise RuntimeError("Automatic card receipt settlement mismatched")
    if label=="auto_card_wallet_agent_skipped" and wallet[1001]!=50000:
        raise RuntimeError("Agent auto-card filter mutated wallet")
    def requires_balance(expected):
        if wallet[1001]!=expected:
            raise RuntimeError(label+" wallet expected "+str(expected)+", got "+str(wallet[1001]))
    def requires_state(state):
        if not any("\t"+state+"\t" in row for row in pays):
            raise RuntimeError(label+" missing payment state "+state+": "+repr(pays))
    if label=="manual_renew_quote":
        requires_balance(50000);requires_state("pending")
    if label=="manual_renew_paid":
        requires_balance(49900);requires_state("paid_with_wallet")
        if "\t100\t" not in "\t"+new["increase_order"]:
            raise RuntimeError("Manual renewal did not persist increase_order")
    if label=="manual_renew_low_wallet":
        requires_balance(25);requires_state("low_wallet")
        if new["increase_order"]:raise RuntimeError("Unfunded renewal created increase_order")
    if label=="extension_day_wallet":
        requires_balance(49925);requires_state("paid_with_wallet")
        if not any(row.endswith("\t5") for row in new["increase_order"].strip().splitlines()):
            raise RuntimeError("Day extension amount not recorded")
    if label=="extension_day_low_wallet":
        requires_balance(25);requires_state("low_wallet")
        if new["increase_order"]:raise RuntimeError("Unfunded day extension recorded increase")
    if label=="extension_volume_wallet":
        requires_balance(49910);requires_state("paid_with_wallet")
        if not any("\t3\t" in "\t"+row for row in new["increase_order"].splitlines()):
            raise RuntimeError("Volume extension not recorded")
    if label=="auto_renew_eligible":
        requires_balance(49900);requires_state("paid_with_wallet")
        if not new["increase_order"]:
            raise RuntimeError("Eligible auto renewal did not persist increase_order")
        if "AutoRenewCheck" not in new["setting"]:
            raise RuntimeError("Auto renewal did not advance scheduled cursor")
    if label=="auto_renew_low_wallet":
        requires_balance(25)
        if "\toff" not in new["orders"]:
            raise RuntimeError("Low-fund auto-renew did not disable autoRenew")
        if new["increase_order"]:
            raise RuntimeError("Low-fund auto-renew extended without settlement")
    if label.endswith("verify_success") and label.startswith(("payment_zarinpal","payment_nextpay")):
        requires_balance(50150);requires_state("paid")
    if label=="payment_zarinpal_verify_failed":
        requires_balance(50000);requires_state("canceled")
    if label=="payment_nextpay_verify_failed":
        # Inherited V7 bug: rejection writes WHERE hash_id=trans_id even
        # though the queried row was selected by payid=trans_id. The update
        # does not match. Preserve this unsafe baseline result under V8.
        requires_balance(50000);requires_state("pending")
    if label=="payment_nextpay_initiation":
        if not any("NXT-Fixture-001" in row for row in pays):
            raise RuntimeError("NextPay token not retained")
    if label=="payment_zarinpal_initiation":
        requires_balance(50000);requires_state("pending")
    if label=="discount_admin_toggle_existing":
        if not any("V8TOGGLE" in x and x.endswith("\t1") for x in new["discounts"].splitlines()):
            raise RuntimeError("Discount admin double toggle did not restore active state")
    if label=="discount_admin_create":
        rows=[r.split("\t") for r in new["complete_discounts"].strip().splitlines()]
        if len(rows)!=1:
            raise RuntimeError("Discount admin did not create exactly one coupon")
        col_names=["id","hash_id","type","amount","expire_date","expire_count"]
        expect={"hash_id":"V8NEW","type":"percent","amount":"20",
                "expire_date":"0","expire_count":"3"}
        observed=dict(zip(col_names,rows[0]))
        if any(observed.get(k)!=v for k,v in expect.items()):
            raise RuntimeError("Discount admin creation persisted wrong financial fields: "+str(observed))
    if label=="discount_admin_create_invalid" and new["complete_discounts"].strip():
        raise RuntimeError("Invalid admin coupon input created a discount")
    if label=="auto_renew_off" and "AutoRenewCheck" not in new["setting"]:
        raise RuntimeError("Auto-renew original schedule cursor absent")
    if label=="payment_invoice_created" and not any("NP-Fixture-001" in p for p in pays):
        raise RuntimeError("Payment invoice ID not persisted")
    if label in ("payment_invoice_http_error","payment_invoice_invalid_body") and any("NP-Fixture-001" in p for p in pays):
        raise RuntimeError("Failed invoice modified payment provider ID")
    if label=="payment_callback_success":
        if wallet[1001]!=50150 or not any("\tpaid\t" in p for p in pays):
            raise RuntimeError("Successful gateway settlement/replay credited incorrect amount")
    if label=="payment_callback_partial" and not any("\tlow_payment\t" in p for p in pays):
        raise RuntimeError("Partial payment missing low_payment state")
    if label=="payment_callback_canceled" and not any("\tcanceled\t" in p for p in pays):
        raise RuntimeError("Canceled payment state missing")
    if label.startswith("payment_callback_") and label!="payment_callback_success" and wallet[1001]!=50000:
        raise RuntimeError("Unsuccessful payment changed wallet")


def main():
    parser=argparse.ArgumentParser()
    parser.add_argument("--run-disposable",action="store_true",required=True)
    parser.add_argument("--report",type=Path)
    parser.add_argument("--case",action="append",default=[])
    args=parser.parse_args()
    c.verify()
    global test_crypto_url
    cases=[];finished=[]
    with support.owned_database() as (work,env),native.owned_provider() as (provider,url),owned_gateway() as (gateway,gateway_url):
        test_crypto_url=gateway_url
        old=work/"v7-finance";new=work/"v8-finance"
        stage_finance(old,False,gateway_url)
        stage_finance(new,True,gateway_url)
        old_names,old_ddl=ddl(work,env,SIDE[0])
        new_names,new_ddl=ddl(work,env,SIDE[1])
        if old_names!=new_names or old_ddl!=new_ddl:
            raise RuntimeError("Original/candidate schema differ at test start")
        for label,steps in flows().items():
            if args.case and not any(label.startswith(part) for part in args.case):
                continue
            prepare_label(work,env,label,url)
            mode={"payment_invoice_http_error":"invoice_http_500",
                  "payment_invoice_invalid_body":"invoice_invalid",
                  "payment_callback_success":"callback_success",
                  "payment_callback_partial":"callback_partial",
                  "payment_callback_canceled":"callback_canceled",
                  "payment_callback_provider_error":"callback_provider_error",
                  "payment_zarinpal_verify_failed":"zarinpal_verify_failed",
                  "payment_nextpay_verify_failed":"nextpay_verify_failed",
                  "crypto_paid_wallet":"crypto_success",
                  "crypto_failed_flag_inherited":"crypto_failed_contract",
                  "crypto_wrong_wallet":"crypto_wrong_wallet",
                  "crypto_amount_mismatch":"crypto_amount_mismatch"}.get(label,"invoice_ok")
            gateway.mode=mode
            historical_wires=[]
            historical_states=[]
            for index,(kind,spec) in enumerate(steps):
                outputs=[];states=[];wire=[]
                if kind=="discount_function":
                    for db in SIDE:
                        spec["rowId"]=int(sql(work,env,"SELECT id FROM pays ORDER BY id DESC LIMIT 1",db).strip())
                for root,db in ((old,SIDE[0]),(new,SIDE[1])):
                    provider.trace.clear();gateway.trace.clear()
                    owned_spec=dict(spec)
                    if label=="discount_admin_toggle_existing":
                        discount_id=sql(work,env,"SELECT id FROM discounts WHERE hash_id='V8TOGGLE'",db).strip()
                        if not discount_id.isdigit():
                            raise RuntimeError("Missing seeded toggle discount")
                        owned_spec["data"]="toggleDiscount"+discount_id
                    foreign_cwd=None
                    if label=="qr_checkout_external_cwd":
                        foreign_cwd=work/"owned-foreign-cwd"
                        foreign_cwd.mkdir(exist_ok=True)
                    outputs.append(n.run(work,env,root,db,kind,owned_spec,
                                         execution_cwd=foreign_cwd))
                    wire.append({"panel":list(provider.trace),"payment":list(gateway.trace)})
                    states.append(finance_state(work,env,db))
                captured_now=int(time.time())
                observed=[canonical_execution(x,captured_now) for x in outputs]
                persisted=[canonical_execution(x,captured_now) for x in states]
                requests=[canonical_execution(x,captured_now) for x in wire]
                if observed[0]!=observed[1] or persisted[0]!=persisted[1] or requests[0]!=requests[1]:
                    raise RuntimeError("V8 financial side-effect mismatch: "+json.dumps({
                      "label":label,"step":index,"old":outputs[0],"new":outputs[1],
                      "old_sql":states[0],"new_sql":states[1],
                      "old_wire":wire[0],"new_wire":wire[1]},ensure_ascii=False)[:6000])
                historical_wires.append(wire[0])
                historical_states.append(states[0])
                cases.append({"scenario":label,"step":index,"route":kind,"actor":spec["actor"],
                              "events":len(outputs[0]["events"]),
                              "state_sha256":hashlib.sha256(json.dumps(states[0],sort_keys=True).encode()).hexdigest()})
            if label=="qr_checkout_external_cwd":
                if not any(e["name"]=="sendPhoto" for x in outputs for e in x["events"]):
                    raise RuntimeError("QR route did not render and send a synthetic photo")
            if label in ("manual_renew_paid","extension_day_wallet","extension_volume_wallet",
                         "auto_renew_eligible"):
                if not any(x["panel"] for x in historical_wires):
                    raise RuntimeError(label+" did not call owned provisioning endpoint")
            if label in ("payment_zarinpal_initiation","payment_zarinpal_verify_success",
                         "payment_zarinpal_verify_failed"):
                endpoint="/soap/zarinpal/request" if label.endswith("initiation") else "/soap/zarinpal/verify"
                if not any(w["path"]==endpoint for step in historical_wires for w in step["payment"]):
                    raise RuntimeError("No owned Zarinpal HTTP fixture request")
            if label.startswith("payment_nextpay_"):
                endpoint="/nx/gateway/token" if label.endswith("initiation") else "/nx/gateway/verify"
                if not any(w["path"]==endpoint for step in historical_wires for w in step["payment"]):
                    raise RuntimeError("No owned NextPay HTTP fixture request")
            if label in ("manual_renew_paid","extension_day_wallet","extension_volume_wallet",
                         "payment_zarinpal_verify_success","payment_nextpay_verify_success"):
                if historical_wires[-1]["panel"] or historical_wires[-1]["payment"]:
                    raise RuntimeError("Replay invoked external provider: "+label)
                if historical_states[-1]!=historical_states[-2]:
                    raise RuntimeError("Replay modified settled financial rows: "+label)
            assert_expected(label,states,[e for x in outputs for e in x["events"]])
            finished.append(label)
            print("PASS V8 finance "+label,flush=True)
        for db in SIDE:
            after_names,after_ddl=ddl(work,env,db)
            if old_names!=after_names or old_ddl!=after_ddl:
                raise RuntimeError("V8 original-schema DDL changed: "+db)
    report={"scenarios":len(finished),"steps":len(cases),"scenario_names":finished,
            "original_schema_tables":23,"before_after_ddl_both_sides":True,
            "real_mysqli":True,"provider_is_owned_loopback":True,
            "synthetic_telegram":True,"old_v7_v8_ordered_side_effect_parity":True,
            "details":cases}
    if args.report:args.report.write_text(json.dumps(report,ensure_ascii=False,indent=2)+"\n")
    print(json.dumps({k:v for k,v in report.items() if k!="details"},ensure_ascii=False),flush=True)

if __name__=="__main__":
    main()
