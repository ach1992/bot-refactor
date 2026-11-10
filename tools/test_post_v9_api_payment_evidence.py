#!/usr/bin/env python3
"""Owned socket-MariaDB + real loopback current-API evidence regression.

No tenant config, installed target, provider, Telegram or external API is used.
"""
from __future__ import annotations
import argparse
import base64
import contextlib
import datetime as dt
import hashlib
import hmac
import http.server
import json
import subprocess
import threading
import tempfile
import concurrent.futures
from pathlib import Path

import test_access_state as owned
import test_v6_native as native

def fixture_gift_code(label):
    """Canonical issuer-shaped synthetic code; the fake /verify owns identity."""
    raw=hashlib.sha512(('AChBot evidence fixture / '+label).encode()).digest()[:52]
    token=base64.b32encode(raw).decode('ascii').rstrip('=')
    assert len(token)==84
    return 'AG2-K1-'+'-'.join(token[i:i+5] for i in range(0,84,5))

ROOT=Path(__file__).resolve().parents[1]
DB=owned.NAMES[1]
DRIVER=r'''<?php
define('ACHBOT_PAYMENT_EVIDENCE_TEST_TRANSPORT',true);
require $argv[1];
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$db=new mysqli('localhost','root','',$argv[3],0,$argv[2]);
$db->set_charset('utf8mb4');
$spec=json_decode($argv[4],true,32,JSON_THROW_ON_ERROR);
$GLOBALS['telegramWebhookSecret']=$spec['webhook']??'fixture-webhook-secret-32-bytes-123456';
$keys=['smsApiUrl'=>$spec['base'].'/wp-json/ach-pay-manager/v1',
 'smsApiHorizonHours'=>'24','smsApiTimezone'=>'UTC','smsApiCalendar'=>'gregorian',
 'giftApiUrl'=>$spec['base'].'/wp-json/ach-gcw/v2','giftApiKeyId'=>'fixture-client',
 'giftApiAmountUnit'=>'toman'];
$keys['smsApiToken']=achbotPaymentEvidenceProtect('setting:smsApiToken',['value'=>'fixture-sms-secret']);
$keys['giftApiSecret']=achbotPaymentEvidenceProtect('setting:giftApiSecret',['value'=>'fixture-gift-secret']);
foreach($spec['keys']??[] as $key=>$value)$keys[$key]=$value;
$states=['smsApiVerifyState'=>'on','giftApiVerifyState'=>'on'];
$method=$spec['method']??'bank_transfer';
$p=achbotPaymentEvidenceProfile($method,$keys,$states);
$id=(int)($spec['pay_id']??0);
$pay=$id?$db->query('SELECT * FROM pays WHERE id='.$id)->fetch_assoc():null;
$a=$id?$db->query('SELECT * FROM payment_attempts WHERE pay_row_id='.$id)->fetch_assoc():null;
$profile=$a?achbotPaymentEvidenceSnapshot($a):$p;
if(isset($spec['hostile_field']) && $profile)$profile['amount_field']=$spec['hostile_field'];
if(isset($spec['pin_hostile']) && $p)$p['amount_field']=$spec['pin_hostile'];
$result=null;
switch($spec['op']){
case 'ready':$result=achbotPaymentEvidenceSchemaReady($db);break;
case 'profile':
 $result=['valid'=>$p!==null,'source'=>$p['source_key']??null,'endpoint'=>$p['endpoint_sha256']??null,
  'amount_field'=>$p['amount_field']??null];break;
case 'normalize':
 $n=achbotPaymentEvidenceSmsNormalize($spec['row'],$profile??$p);
 $issue=isset($spec['issued_unix'])?(int)$spec['issued_unix']:null;
 $result=['evidence'=>$n,'matches'=>$n && achbotPaymentEvidenceSmsMatches($n,$a,$profile,$issue)];break;
case 'jalali':$result=achbotPaymentEvidenceJalaliGregorian(...$spec['date']);break;
case 'pin':
 $p['subject']=['days'=>30,'volume'=>50,'provider'=>'fixture'];
 $db->begin_transaction();
 $result=achbotPaymentEvidencePinLocked($db,$pay,$a,$p);
 $db->commit();break;
case 'snapshot':
 $result=['valid'=>$profile!==null,'issued'=>$profile['issued_at']??null,
 'subject'=>$profile['subject']??null];break;
case 'claim':$result=achbotPaymentEvidenceClaim($db,$id);if($result)$result=['token'=>$result['token']];break;
case 'defer':$result=achbotPaymentEvidenceDefer($db,$id,$spec['token'],$spec['status']);break;
case 'typed':$db->begin_transaction();$result=achbotPaymentEvidenceHasTypedReviewLocked($db,$pay);$db->rollback();break;
case 'reserve':
 $n=achbotPaymentEvidenceSmsNormalize($spec['row'],$profile);
 $result=$n?achbotPaymentEvidenceReserve($db,$id,$n,$spec['mode']??'settle',null,
   isset($spec['guard'])?static fn($locked)=>$spec['guard']:null):null;break;
case 'pending':$result=achbotPaymentEvidencePending($db,$id);break;
case 'consume':
 $h=achbotPaymentEvidencePending($db,$id);
 $result=$h?achbotPaymentEvidenceConsume($db,$h):null;break;
case 'proof':$result=achbotPaymentEvidenceGetProof($db,$id);break;
case 'validate':
 $proof=achbotPaymentEvidenceGetProof($db,$id);
 if(isset($spec['price']))$pay['price']=$spec['price'];
 if(isset($spec['state']))$pay['state']=$spec['state'];
 $db->begin_transaction();
 $result=$proof && achbotPaymentEvidenceValidateProofLocked($db,$pay,$proof);
 $db->rollback();break;
case 'giftformat':
 $result=achbotPaymentEvidenceGiftCodeFormatValid($spec['code']);break;
case 'save':
 $result=achbotPaymentEvidenceSaveGiftCode($db,$id,1001,$spec['code']);break;
case 'reject':
 $result=achbotPaymentEvidenceRejectGiftCode($db,$id,$spec['code'],$spec['reason']);break;
case 'giftverify':
 $code=achbotPaymentEvidenceReadGiftCode($db,$id);
 $result=$code===null?null:achbotPaymentEvidenceGiftVerify($profile,$code);break;
case 'giftprecheck':
 require_once dirname($argv[1]).'/ApiPayments.php';
 $result=achbotManualGiftSubmissionPreflight($db,$pay,'gift_card',$spec['code']);break;
case 'giftpreview':
 require_once dirname($argv[1]).'/ReceiptHelpers.php';
 $result=['text'=>achbotManualGiftReviewText($spec['caption']??'Fixture invoice',
     $spec['code']??'fixture-code',$spec['screen']??null),
     'fits'=>achbotManualGiftReviewFits($spec['caption']??'Fixture invoice',
     $spec['code']??'fixture-code',$spec['screen']??null)];break;
case 'giftretry':
 require_once dirname($argv[1]).'/ReceiptHelpers.php';
 $result=achbotManualGiftRetryMessage($spec['reason']);break;
case 'giftnormalize':
 $result=achbotPaymentEvidenceGiftNormalizationStatus($spec['card'],$profile,$a);break;
case 'giftreserve':
 $n=achbotPaymentEvidenceGiftNormalize($spec['card'],$profile,$a);
 $result=$n?achbotPaymentEvidenceReserve($db,$id,$n,'settle',
  achbotPaymentEvidenceReadGiftCode($db,$id)):null;break;
case 'protect':
 $sealed=achbotPaymentEvidenceProtect('fixture',['value'=>'fixture-sensitive']);
 $wrong=achbotPaymentEvidenceUnprotect('other-purpose',$sealed);
 $GLOBALS['telegramWebhookSecret']='rotated-fixture-webhook-secret-12345';
 $rotated=achbotPaymentEvidenceUnprotect('fixture',$sealed);
 $result=['wrong_purpose'=>$wrong,'rotated'=>$rotated,
  'plaintext'=>str_contains($sealed,'fixture-sensitive')];break;
case 'http':
 $result=achbotPaymentEvidenceHttp($p,'GET','/messages?status=ready&after_id=0&per_page=100');
 unset($result['json']);break;
default:throw new RuntimeException('fixture_unknown_op');
}
echo json_encode(['result'=>$result],JSON_THROW_ON_ERROR);
'''
class Server(http.server.ThreadingHTTPServer):
    daemon_threads=True
    def __init__(self,dbcheck):
        super().__init__(('127.0.0.1',0),Handler)
        self.messages={};self.cache={};self.consumes={};self.posts=[]
        self.card={};self.gift_refs={};self.gift_consumes=0
        self.nonces=set();self.fail_once=False;self.gift_fail_once=False
        self.redirect=False;self.overflow=False;self.dbcheck=dbcheck;self.lock_checks=0
        self.gift_idempotent_first=False
        self.read_faults=[];self.read_requests=0
        self.verify_faults=[];self.verify_requests=0;self.verify_responses=[]
        self.direct_not_found=set();self.direct_reads={}
class Handler(http.server.BaseHTTPRequestHandler):
    def log_message(self,*args):pass
    def send(self,status,data):
        raw=json.dumps(data).encode()
        self.send_response(status);self.send_header('Content-Type','application/json')
        self.send_header('Content-Length',str(len(raw)));self.end_headers();self.wfile.write(raw)
    def do_GET(self):
        s=self.server
        if self.headers.get('Authorization')!='Bearer fixture-sms-secret':
            return self.send(401,{'code':'auth'})
        s.read_requests+=1
        if s.read_faults:
            status=s.read_faults.pop(0)
            if status==429:
                self.send_response(429);self.send_header('Retry-After','1')
                self.end_headers();return
            return self.send(status,{'code':'fixture_read_failed'})
        if s.redirect:
            self.send_response(302);self.send_header('Location','http://127.0.0.1:9/unowned')
            self.end_headers();return
        if s.overflow:return self.send(200,{'padding':'x'*150000})
        path=self.path.split('?',1)[0]
        if path.endswith('/messages'):
            rows=[v for v in s.messages.values() if v['status']=='ready']
            return self.send(200,{'data':rows,'pagination':{'has_more':False,
                'next_after_id':max([int(v['id']) for v in rows],default=0)}})
        ident=path.rsplit('/',1)[-1]
        s.direct_reads[ident]=s.direct_reads.get(ident,0)+1
        if ident in s.direct_not_found:
            return self.send(404,{'code':'ach_pay_not_found'})
        row=s.messages.get(ident)
        return self.send(200,{'data':row,'request_id':'read-fixture'}) if row else self.send(404,{'code':'not_found'})
    def do_POST(self):
        s=self.server
        raw=self.rfile.read(int(self.headers.get('Content-Length','0')))
        body=json.loads(raw);s.posts.append((self.path,dict(self.headers),body))
        # A separate DB connection must take the attempt row immediately.
        s.dbcheck();s.lock_checks+=1
        if '/ach-pay-manager/' in self.path:
            if self.headers.get('Authorization')!='Bearer fixture-sms-secret':
                return self.send(401,{'code':'auth'})
            key=self.headers.get('Idempotency-Key')
            if key in s.cache:return self.send(200,s.cache[key])
            ident=self.path.split('/')[-2];row=s.messages[ident]
            if row['status']!='ready':return self.send(409,{'code':'not_ready'})
            row['status']='used';s.consumes[ident]=s.consumes.get(ident,0)+1
            response={'data':dict(row),'request_id':'sms-fixture-'+ident}
            s.cache[key]=response
            if s.fail_once:s.fail_once=False;return self.send(503,{'code':'fixture_after_commit'})
            return self.send(200,response)
        stamp=self.headers.get('X-ACH-Timestamp','');nonce=self.headers.get('X-ACH-Nonce','')
        route=self.path.removeprefix('/wp-json')
        message=b'\n'.join([stamp.encode(),nonce.encode(),b'POST',route.encode(),raw])
        import base64
        expected=base64.urlsafe_b64encode(hmac.digest(b'fixture-gift-secret',message,'sha256')).decode().rstrip('=')
        assert self.headers.get('X-ACH-Signature')==expected
        assert self.headers.get('X-ACH-Key-Id')=='fixture-client'
        assert nonce not in s.nonces;s.nonces.add(nonce)
        if self.path.endswith('/verify'):
            s.verify_requests+=1
            if s.verify_responses:
                status,payload=s.verify_responses.pop(0)
                return self.send(status,payload)
            if s.verify_faults:
                status=s.verify_faults.pop(0)
                return self.send(status,{'code':'fixture_verify_failed'})
            if body['code']==fixture_gift_code('unknown-code'):
                return self.send(404,{'api_version':'2','ok':False,'code':'card_not_found','data':None})
            if body['code']==fixture_gift_code('bad-code'):
                return self.send(422,{'api_version':'2','ok':False,'code':'invalid_card_code','data':None})
            if body['code']==fixture_gift_code('bad-payload'):
                return self.send(409,{'api_version':'2','ok':False,'code':'payload_mismatch','data':None})
            if body['code']==fixture_gift_code('unauthorized'):
                return self.send(403,{'api_version':'2','ok':False,'code':'invalid_card_code','data':None})
            return self.send(200,{'api_version':'2','ok':True,'code':'card_verified','data':dict(s.card),
                'request_id':'req_verify_fixture'})
        ref=body['reference'];already=s.card['status']!='active'
        if already and s.gift_refs.get(s.card['card_uuid'])!=ref:
            return self.send(409,{'api_version':'2','ok':False,'code':'already_redeemed','data':None})
        if not already:
            s.card['status']='redeemed';s.gift_refs[s.card['card_uuid']]=ref;s.gift_consumes+=1
        if s.gift_idempotent_first:
            already=True;s.gift_idempotent_first=False
        response={'api_version':'2','ok':True,
            'code':'card_redeem_idempotent' if already else 'card_redeemed','reference':ref,
            'data':dict(s.card,idempotent=already),'request_id':'req_redeem_fixture'}
        if s.gift_fail_once:s.gift_fail_once=False;return self.send(500,
            {'api_version':'2','ok':False,'code':'audit_write_failed',
             'data':{'state_changed':True,'transition_committed':True}})
        self.send(200,response)
def validate_gift_route_boundaries():
    # These are the six original subject handlers. The common screen must
    # run before the exact full-message length validation, durable lease or
    # private admin send; a rejected code cannot consume the input step.
    paths={
        'src/Finance/Routes/Checkout.php':2,
        'src/Finance/Routes/Extensions.php':2,
        'src/Finance/Routes/RenewalCheckout.php':1,
        'src/Finance/Routes/WalletRecharge.php':1,
    }
    for name,expected in paths.items():
        source=(ROOT/name).read_text()
        boundary='$giftScreen=achbotManualGiftSubmissionPreflight($connection,$receiptPay,$method,$fileid);'
        reserve='$deliveryToken = achbotManualBeginEvidence($connection, $receiptPay, $method, (int)$from_id, $fileid);'
        assert source.count(boundary)==source.count(reserve)==expected,(name,'gift_screen_route_count')
        assert source.count('$deliveryToken,$giftScreen))')==expected,(name,'receipt_review_projection_count')
        for section in source.split(boundary)[1:]:
            # Fail-safe customer message exits immediately, with the original
            # cancellation action. No payment state or admin delivery occurs.
            assert section.index('achbotManualGiftRetryMessage(')<section.index(reserve),(name,'retry_before_claim')
            assert section.index('exit;')<section.index(reserve),(name,'reject_before_claim')
            assert section.index('achbotManualGiftReviewFits($msg,$fileid,$giftScreen)')<section.index(reserve),(name,'full_metadata_length_before_claim')
            assert section.index(reserve)<section.index('sendReceiptToAdminsAndUpdatePay('),(name,'claim_before_staff')
            assert 'setUser(' not in section[:section.index(reserve)],(name,'reject_discarded_input_step')
    return 6

def run(module):
    assert validate_gift_route_boundaries()==6
    with owned.owned_database() as (work,env):
        driver=work/'evidence.php';driver.write_text(DRIVER)
        def sql(q):return native.sql(work,env,q,DB).strip()
        sql((ROOT/'migrations/post-v9/003_payment_attempts.sql').read_text())
        def dbcheck():sql('SET innodb_lock_wait_timeout=1; UPDATE payment_attempts SET last_checked_at=NOW()')
        server=Server(dbcheck);thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
        base='http://127.0.0.1:'+str(server.server_port)
        def php(op,**spec):
            raw_code=spec.pop('raw_code',False)
            if not raw_code and op in ('save','reject','giftprecheck') and isinstance(spec.get('code'),str):
                spec['code']=fixture_gift_code(spec['code'])
            data=dict(op=op,base=base,**spec)
            p=subprocess.run(['php','-n','-d','extension=mysqlnd','-d','extension=mysqli',
                '-d','extension=curl','-d','display_errors=stderr','-d','log_errors=0',
                '-d','allow_url_fopen=0','-d','disable_functions=exec,shell_exec,system,passthru,popen,proc_open,mail',
                str(driver),str(module),str(work/'mysql.sock'),DB,json.dumps(data)],
                env=env,capture_output=True,text=True,timeout=25)
            if p.returncode or p.stderr:raise RuntimeError('owned PHP failure: '+p.stderr[-1500:])
            return json.loads(p.stdout)['result']
        try:
            assert php('ready') is False
            sql((ROOT/'migrations/post-v9/014_api_payment_evidence.sql').read_text())
            assert php('ready') is True
            sql('ALTER TABLE payment_transactions DROP INDEX uq_payment_evidence_operation')
            assert php('ready') is False
            sql('ALTER TABLE payment_transactions ADD UNIQUE KEY uq_payment_evidence_operation(evidence_operation)')
            assert php('ready') is True
            assert php('profile')['valid']
            assert not php('profile',keys={'smsApiHorizonHours':''})['valid']
            assert not php('profile',keys={'smsApiHorizonHours':'1.5'})['valid']
            assert not php('profile',keys={'smsApiToken':'plaintext-secret'})['valid']
            assert not php('profile',keys={'smsApiUrl':'http://example.invalid/wp-json/ach-pay-manager/v1'})['valid']
            assert not php('profile',method='gift_card',keys={'giftApiAmountUnit':''})['valid']
            assert php('profile',method='gift_card')['amount_field']=='redeem_amount'
            assert not php('profile',method='gift_card',keys={'giftApiAmountField':'display_amount'})['valid']
            original=php('profile')
            changed=php('profile',keys={'smsApiUrl':'https://changed.invalid/wp-json/ach-pay-manager/v1'})
            assert original['source']==changed['source'] and original['endpoint']!=changed['endpoint']
            assert php('protect')=={'wrong_purpose':None,'rotated':None,'plaintext':False}
            assert php('jalali',date=[1405,8,7])==[2026,10,29]
            assert php('jalali',date=[1402,12,30]) is None
            now=dt.datetime.now(dt.timezone.utc).replace(microsecond=0)
            issued=now-dt.timedelta(minutes=10)
            def seed(name,method='bank_transfer',price=100001,**pinargs):
                sql("INSERT INTO pays(hash_id,user_id,type,price,request_date,state) VALUES "
                    f"('{name}',1001,'INCREASE_WALLET',{price},1700000000,'pending')")
                ident=int(sql(f"SELECT id FROM pays WHERE hash_id='{name}'"))
                sql("INSERT INTO payment_attempts(pay_row_id,provider,order_hash,quote_amount,quote_currency,"
                    "local_amount_toman,checkout_url,created_at) VALUES "
                    f"({ident},'{method}','{name}',{price},'toman',{price},"
                    "'{\"v\":1,\"method\":\""+method+"\"}',"
                    f"'{issued:%Y-%m-%d %H:%M:%S}')")
                expected_pin=pinargs.pop('expected_pin',True)
                assert php('pin',pay_id=ident,method=method,**pinargs) is expected_pin
                if expected_pin:
                    sql(f"UPDATE pays SET state='have_sent',message_id=222,chat_id=9001 WHERE id={ident};UPDATE payment_attempts SET stage='review' WHERE pay_row_id={ident}")
                return ident
            def sms(ident,amount=100001,unit='toman'):
                return dict(id=ident,public_uid='UID'+str(ident),provider='BANK_FIXTURE',
                    provider_sms_id='provider'+str(ident),sender='BANK',mobile_source='phone',
                    bank_key='fixture',rule_id=7,amount=amount,display_unit=unit,
                    sms_date=now.strftime('%Y-%m-%d'),sms_time=now.strftime('%H:%M:%S'),status='ready')
            def card(uuid='CARD_UUID_1',amount=100001):
                # v2.3.3 verified-card metadata, distinct from the bot's
                # invoice/order ID and the WooCommerce order creation time.
                return dict(card_uuid=uuid,status='active',game_key='fixture',redeem_value_id=42,
                    redeem_amount=amount,display_amount=999999,face_value=10,currency_label='fixture',
                    order_id=1234,order_number='WEB-2026-0042',
                    order_created_at='2026-09-09T07:00:00+00:00',
                    created_at='2026-09-10T08:05:00+00:00')
            p1=seed('smsexact')
            r=sms(1);server.messages['1']=dict(r)
            assert php('normalize',pay_id=p1,row=r)['matches']
            for amount in (99001,100000,100002):
                assert not php('normalize',pay_id=p1,row=sms(1,amount))['matches']
            assert php('normalize',pay_id=p1,row=sms(1,1000010,'rial'))['matches']
            assert php('normalize',pay_id=p1,row=sms(1,1000011,'rial'))['evidence'] is None
            assert php('normalize',pay_id=p1,row=dict(r,status='used'))['evidence'] is None
            assert php('normalize',pay_id=p1,row=dict(r,sms_date=''))['evidence'] is None
            assert php('normalize',pay_id=p1,row=dict(r,direction='withdrawal'))['evidence'] is None
            # Bank timestamps: original quote issue, not receipt-upload time.
            # Precision is part of the observation, not the SMS fingerprint.
            invoice=dt.datetime(2026,11,6,16,5,17,tzinfo=dt.timezone.utc)
            issued_ts=int(invoice.timestamp())
            def source_stamp(day,clock):
                return dict(r,id=101,public_uid='UID101',sms_date=day,sms_time=clock)
            for time_text,expected in [
                ('16:05',True),   # minute covering original issue
                ('16:02',True),   # minute-only source within the 3-minute grace
                ('16:01',False),  # cannot extend grace arbitrarily
                ('16:02:17',True), ('16:02:16',False), # exact +/-180s
            ]:
                got=php('normalize',pay_id=p1,issued_unix=issued_ts,
                        row=source_stamp('2026-11-06',time_text))
                assert got['matches'] is expected,(time_text,got)
                assert got['evidence']['time_precision_seconds']==(0 if time_text.count(':')==2 else 59)
            assert php('normalize',pay_id=p1,issued_unix=issued_ts,
                row=source_stamp('2026-11-07','16:05:17'))['matches']
            assert not php('normalize',pay_id=p1,issued_unix=issued_ts,
                row=source_stamp('2026-11-07','16:05:18'))['matches']
            # Gregorian absolute timestamps stay second-precise (no invented
            # minute tolerance). The legacy Jalali minute is an interval.
            assert not php('normalize',pay_id=p1,issued_unix=issued_ts,
                row=source_stamp('2026-11-06T16:02:16Z','16:02'))['matches']
            jalali=seed('smsjalaliminute',keys={
                'smsApiTimezone':'Asia/Tehran','smsApiCalendar':'jalali'})
            tehran=dt.timezone(dt.timedelta(hours=3,minutes=30))
            local_ts=int(dt.datetime(2026,11,6,16,5,17,tzinfo=tehran).timestamp())
            check=php('normalize',pay_id=jalali,issued_unix=local_ts,
                row=source_stamp('1405/08/15','16:05'))
            assert check['matches'] and check['evidence']['time_precision_seconds']==59,check
            before=sql(f"SELECT quote_amount,provider_reference,payment_id,checkout_url FROM payment_attempts WHERE pay_row_id={p1}")
            assert php('reserve',pay_id=p1,row=r,guard=False) is None
            handle=php('reserve',pay_id=p1,row=r);assert handle
            assert sql('SELECT evidence_dispatched_at IS NULL FROM payment_transactions')=='1'
            assert server.consumes=={}
            out=php('consume',pay_id=p1);assert out['status']=='proven',out
            assert server.consumes['1']==1
            assert php('consume',pay_id=p1)['status']=='proven'
            assert server.consumes['1']==1
            assert php('validate',pay_id=p1,state='processing') is True
            assert php('validate',pay_id=p1,price=100002) is False
            assert before==sql(f"SELECT quote_amount,provider_reference,payment_id,checkout_url FROM payment_attempts WHERE pay_row_id={p1}")
            p2=seed('smsreuse')
            assert php('reserve',pay_id=p2,row=r) is None
            claim=php('claim',pay_id=p2);assert claim
            assert php('defer',pay_id=p2,token=claim['token'],status='manual')
            assert sql(f'SELECT evidence_state FROM payment_attempts WHERE pay_row_id={p2}')=='manual'
            alias=seed('smsalias',keys={'smsApiUrl':'https://changed.invalid/wp-json/ach-pay-manager/v1'})
            assert php('reserve',pay_id=alias,row=r) is None
            # Distinct SQL connections cannot bind the same evidence twice.
            ca=seed('smsconcurrenta');cb=seed('smsconcurrentb');cr=sms(6)
            with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
                results=list(pool.map(lambda p:php('reserve',pay_id=p,row=cr),[ca,cb]))
            assert sum(x is not None for x in results)==1
            assert sql("SELECT COUNT(*) FROM payment_transactions WHERE payment_id='api1:"+
                hashlib.sha256((original['source']+'\0sms\0UID6').encode()).hexdigest()+"'")=='1'
            # Ciphertext never contains raw auth/code material, even while replayable.
            assert 'fixture-sms-secret' not in sql('SELECT evidence_profile FROM payment_attempts')
            # Lost successful response: same persisted key recovers exactly one consume.
            p3=seed('smslost');r3=sms(3);server.messages['3']=dict(r3);server.fail_once=True
            assert php('reserve',pay_id=p3,row=r3)
            assert php('consume',pay_id=p3)['status']=='pending'
            assert php('consume',pay_id=p3)['status']=='proven'
            keys=[h.get('Idempotency-Key') for path,h,b in server.posts if path.endswith('/messages/3/actions')]
            assert len(keys)==2 and keys[0]==keys[1] and server.consumes['3']==1
            #24h+ has no safe transport retry, even if current used/ready.
            p4=seed('smsold');r4=sms(4);server.messages['4']=dict(r4);server.fail_once=True
            assert php('reserve',pay_id=p4,row=r4)
            assert php('consume',pay_id=p4)['status']=='pending'
            sql("UPDATE payment_transactions SET evidence_dispatched_at=NOW()-INTERVAL 25 HOUR "
                f"WHERE attempt_id=(SELECT id FROM payment_attempts WHERE pay_row_id={p4})")
            count=len(server.posts)
            assert php('consume',pay_id=p4)['status']=='review' and len(server.posts)==count
            # A list-visible SMS with a consistently 404 exact-ID GET can
            # never be blindly consumed. The same original journal is reused
            # once the provider's exact-ID admission is repaired.
            pn=seed('smsnotfound');rn=sms(55);server.messages['55']=dict(rn)
            assert php('reserve',pay_id=pn,row=rn)
            server.direct_not_found.add('55')
            assert php('consume',pay_id=pn)['status']=='pending'
            assert server.direct_reads['55']==1
            assert '55' not in server.consumes
            assert sql(f"SELECT evidence_state FROM payment_transactions WHERE attempt_id="
                f"(SELECT id FROM payment_attempts WHERE pay_row_id={pn})")=='uncertain'
            server.direct_not_found.remove('55')
            assert php('consume',pay_id=pn)['status']=='proven'
            assert server.consumes['55']==1
            # Late ready SMS after manual approval is consumption-only, proof is not settlement authority.
            late=seed('smslate');rl=sms(5);server.messages['5']=dict(rl)
            sql(f"UPDATE pays SET state='approved',approved_by=9001 WHERE id={late}")
            assert php('reserve',pay_id=late,row=rl) is None
            assert php('reserve',pay_id=late,row=rl,mode='evidence_only')
            assert php('consume',pay_id=late)['status']=='proven'
            assert php('validate',pay_id=late) is False
            # Mutated PaymentEvidence.php candidates live outside the runtime
            # directory and deliberately exercise ONLY their three historical
            # mutation-specific RED checks. New integrated screens load the
            # real sibling ApiPayments/ReceiptHelpers modules on the canonical
            # source path; mutating those dependencies is outside this control.
            if module.resolve()==(ROOT/'src/Finance/PaymentEvidence.php').resolve():
                sample=fixture_gift_code('canonical-format')
                compact='AG2-K1-'+sample[len('AG2-K1-'):].replace('-','')
                assert php('giftformat',code=sample) is True
                assert php('giftformat',code=sample.lower()) is True
                assert php('giftformat',code=compact) is True
                for malformed in ('0','hello','AG2-K1-ABC','AG2-K1-'+
                        sample[len('AG2-K1-'):].replace('-','')[:-1]+'B',
                        'AG2-K1-'+sample[len('AG2-K1-'):].replace('-','')[:-1]+'1',
                        'AG2-K1-'+sample[len('AG2-K1-'):].replace('-','')+'AG2',
                        sample+' extra text'):
                    assert php('giftformat',code=malformed) is False,malformed
                # Customer-first read-only screening of the original pending invoice.
                # Nothing is persisted, redeemed or delivered to staff on rejection.
                gs=seed('giftprecheck','gift_card')
                sql(f"UPDATE pays SET state='pending',message_id=0,chat_id=0 WHERE id={gs}")
                sql(f"UPDATE payment_attempts SET stage='pending' WHERE pay_row_id={gs}")
                before_requests=server.verify_requests
                for malformed in ('0','any arbitrary text','123456','AG2-K1-invalid'):
                    denied=php('giftprecheck',pay_id=gs,code=malformed,raw_code=True)
                    assert denied['accepted'] is False and denied['reason']=='invalid',denied
                    assert php('save',pay_id=gs,code=malformed,raw_code=True)['status']=='invalid'
                assert server.verify_requests==before_requests
                assert sql(f'SELECT evidence_code IS NULL FROM payment_attempts WHERE pay_row_id={gs}')=='1'
                assert php('giftprecheck',pay_id=gs,code='unknown-code')=={
                    'accepted':False,'reason':'not_found','redeem_amount':None,'amount_unit':None,
                    'order_number':None,'created_at':None}
                assert php('giftprecheck',pay_id=gs,code='bad-code')['reason']=='invalid'
                assert php('giftprecheck',pay_id=gs,code='bad-payload')['reason']=='invalid'
                for reason,proof in [
                    ('not_found','پیدا نشد'),('invalid','معتبر نیست'),
                    ('used','استفاده شده'),('revoked','باطل شده'),
                    ('expired','پایان'),('mismatch','یکسان نیست')]:
                    copy=php('giftretry',reason=reason)
                    assert proof in copy and 'لغو' in copy and 'بفرستید' in copy,(reason,copy)
                # Invalid HTTP scopes cannot be misinterpreted as a definite card
                # error even when the body misleadingly uses an invalid-code tag.
                assert php('giftprecheck',pay_id=gs,code='unauthorized')['accepted'] is True
                # A recognizable error code is NOT definitive when its v2
                # envelope contradicts HTTP status or omits required data.
                error={'api_version':'2','ok':False,'code':'card_not_found','data':None}
                for http,body in [
                    (404,dict(error,ok=True)),
                    (404,{k:v for k,v in error.items() if k!='data'}),
                    (404,dict(error,data={'status':'redeemed'})),
                    (200,error),
                    (409,{'api_version':'2','ok':True,'code':'payload_mismatch','data':None}),
                    (422,{'api_version':'2','ok':False,'code':'invalid_card_code',
                          'data':{'status':'revoked'}}),
                ]:
                    server.verify_responses=[(http,body)]
                    ambiguous=php('giftprecheck',pay_id=gs,code='card-code')
                    assert ambiguous['accepted'] is True and 'reason' not in ambiguous,(http,body,ambiguous)
                # Every successful status, not just active, requires enough
                # card identity/amount to justify rejecting the customer.
                for state in ('redeemed','redeemed_offline','revoked','expired'):
                    complete=card('CARD_AMBIGUOUS_'+state)
                    complete['status']=state
                    for key in ('card_uuid','game_key','redeem_value_id','redeem_amount'):
                        incomplete=dict(complete)
                        del incomplete[key]
                        server.verify_responses=[(200,{'api_version':'2','ok':True,
                            'code':'card_verified','data':incomplete})]
                        ambiguous=php('giftprecheck',pay_id=gs,code='card-code')
                        assert ambiguous['accepted'] is True and 'reason' not in ambiguous,(state,key,ambiguous)
                    server.verify_responses=[(200,{'api_version':'2','ok':True,
                        'code':'card_verified','data':dict(complete,card_uuid='invalid uuid')})]
                    assert php('giftprecheck',pay_id=gs,code='card-code')['accepted'] is True
                assert not server.verify_responses
                assert server.gift_consumes==0
                assert sql(f"SELECT state,IFNULL(message_id,0) FROM pays WHERE id={gs}")=='pending\t0'
                assert sql(f"SELECT stage,evidence_code IS NULL,evidence_review_sent_at IS NULL "
                    f"FROM payment_attempts WHERE pay_row_id={gs}")=='pending\t1\t1'
                assert sql(f"SELECT COUNT(*) FROM payment_transactions WHERE attempt_id="
                    f"(SELECT id FROM payment_attempts WHERE pay_row_id={gs})")=='0'
                server.card=card('CARD_PRECHECK',100001)
                exact=php('giftprecheck',pay_id=gs,code='card-code')
                assert exact=={
                    'accepted':True,'redeem_amount':100001,'amount_unit':'toman',
                    'order_number':'WEB-2026-0042','created_at':'2026-09-10 08:05:00 +00:00'
                },exact
                preview=php('giftpreview',code='card-code',screen=exact)
                assert 'WEB-2026-0042' in preview['text'] and '2026-09-10 08:05:00 +00:00' in preview['text']
                assert '2026-09-09' not in preview['text'] and '1234' not in preview['text']
                for remote_state,reason in [('redeemed','used'),('redeemed_offline','used'),
                         ('revoked','revoked'),('expired','expired')]:
                    server.card['status']=remote_state
                    got=php('giftprecheck',pay_id=gs,code='card-code')
                    assert got['accepted'] is False and got['reason']==reason,(remote_state,got)
                server.card=card('CARD_PRECHECK_WRONG',100000)
                assert php('giftprecheck',pay_id=gs,code='card-code')['reason']=='mismatch'
                server.card=card('CARD_PRECHECK_MISSING')
                del server.card['redeem_amount']
                assert php('giftprecheck',pay_id=gs,code='card-code')['accepted'] is True
                server.card=card('CARD_PRECHECK_AMBIGUOUS')
                del server.card['card_uuid']
                assert php('giftprecheck',pay_id=gs,code='card-code')['accepted'] is True
                server.card=card('CARD_PRECHECK')
                server.verify_faults=[503,503,503]
                assert php('giftprecheck',pay_id=gs,code='card-code')['accepted'] is True
                assert server.gift_consumes==0
                assert sql(f"SELECT state,IFNULL(message_id,0) FROM pays WHERE id={gs}")=='pending	0'
                assert sql(f"SELECT stage,evidence_code IS NULL,evidence_review_sent_at IS NULL "
                    f"FROM payment_attempts WHERE pay_row_id={gs}")=='pending	1	1'
                assert sql(f"SELECT COUNT(*) FROM payment_transactions WHERE attempt_id="
                    f"(SELECT id FROM payment_attempts WHERE pay_row_id={gs})")=='0'
                # Configured product filter is also a definite mismatch if
                # returned with a different legitimate value. Missing data is not.
                gf=seed('giftprefilter','gift_card',keys={'giftApiGameKey':'expected-game'})
                sql(f"UPDATE pays SET state='pending',message_id=0,chat_id=0 WHERE id={gf}")
                sql(f"UPDATE payment_attempts SET stage='pending' WHERE pay_row_id={gf}")
                server.card=card('CARD_PREFILTER')
                assert php('giftprecheck',pay_id=gf,code='card-code')['reason']=='mismatch'
                del server.card['game_key']
                assert php('giftprecheck',pay_id=gf,code='card-code')['accepted'] is True
                server.card['game_key']=False
                assert php('giftprecheck',pay_id=gf,code='card-code')['accepted'] is True
                server.card['game_key']='fixture'
                vf=seed('giftprevalue','gift_card',keys={'giftApiValueId':'43'})
                sql(f"UPDATE pays SET state='pending',message_id=0,chat_id=0 WHERE id={vf}")
                sql(f"UPDATE payment_attempts SET stage='pending' WHERE pay_row_id={vf}")
                assert php('giftprecheck',pay_id=vf,code='card-code')['reason']=='mismatch'
                server.card['redeem_value_id']='corrupt'
                assert php('giftprecheck',pay_id=vf,code='card-code')['accepted'] is True
                # The staff amount must use the provider's configured physical unit,
                # NOT silently display a Rial denomination as Toman.
                gr=seed('giftprerial','gift_card',keys={'giftApiAmountUnit':'rial'})
                sql(f"UPDATE pays SET state='pending',message_id=0,chat_id=0 WHERE id={gr}")
                sql(f"UPDATE payment_attempts SET stage='pending' WHERE pay_row_id={gr}")
                server.card=card('CARD_PRE_RIAL',1000010)
                unit=php('giftprecheck',pay_id=gr,code='card-code')
                assert unit=={'accepted':True,'redeem_amount':1000010,'amount_unit':'rial',
                    'order_number':'WEB-2026-0042','created_at':'2026-09-10 08:05:00 +00:00'},unit
                assert '1,000,010 ریال' in php('giftpreview',code='test',
                    screen=unit)['text']
                server.card=card('CARD_PRE_WRONG_RIAL',1000011)
                assert php('giftprecheck',pay_id=gr,code='card-code')['accepted'] is True
                # Private staff display includes exact verified redeem amount and
                # honest unavailable markers; bot invoice fields are NOT misused as
                # upstream gift creation metadata.
                display=php('giftpreview',code='<sensitive&code>',screen={'redeem_amount':100001,'amount_unit':'toman'})
                assert '100,001' in display['text'] and '&lt;sensitive&amp;code&gt;' in display['text']
                assert '🧾 شماره سفارش' in display['text'] and 'در پاسخ API ارائه نشده' in display['text']
                assert '📅 تاریخ ایجاد' in display['text'] and display['fits']
                assert php('giftpreview',code='0')['fits']
                # Upstream nulls are legitimate and MUST NOT be replaced by the
                # bot invoice, numeric order_id or WooCommerce order_created_at.
                server.card=card('CARD_META_MISSING')
                server.card['order_number']=None
                server.card['created_at']=None
                missing=php('giftprecheck',pay_id=gs,code='card-code')
                assert missing['accepted'] and missing['order_number'] is None
                assert missing['created_at'] is None
                unavail=php('giftpreview',code='card-code',screen=missing)['text']
                assert unavail.count('در پاسخ API ارائه نشده')==2
                assert '1234' not in unavail and '2026-09-09' not in unavail
                # A custom WooCommerce order number is untrusted presentation data.
                server.card=card('CARD_META_ESCAPE')
                server.card['order_number']='<b>&BadOrder</b>'
                server.card['created_at']='2026-09-10T11:35:00+03:30'
                escaped=php('giftprecheck',pay_id=gs,code='card-code')
                assert escaped['order_number']=='<b>&BadOrder</b>'
                assert escaped['created_at']=='2026-09-10 11:35:00 +03:30'
                rendered=php('giftpreview',code='<bad&code>',screen=escaped)['text']
                assert '&lt;b&gt;&amp;BadOrder&lt;/b&gt;' in rendered
                assert '<b>&BadOrder</b>' not in rendered and '<bad&code>' not in rendered
                assert '&lt;bad&amp;code&gt;' in rendered
                # Bad dates cannot be used as creation evidence; they do not erase
                # the independent genuine redeem/order-number observations.
                server.card['created_at']='2026-02-30T10:00:00+00:00'
                malformed=php('giftprecheck',pay_id=gs,code='card-code')
                assert malformed['accepted'] and malformed['created_at'] is None
                assert malformed['order_number']=='<b>&BadOrder</b>'
                server.card['created_at']='2026-09-10T08:05:00+00:00'
                server.card['order_number']='order-123\nforged status'
                control=php('giftprecheck',pay_id=gs,code='card-code')
                assert control['accepted'] and control['order_number'] is None
                # Long optional metadata cannot create an un-sendable accepted
                # code after it is encrypted and the staff lease has begun.
                full=php('giftpreview',caption='Invoice',code='x'*3970,
                    screen={'redeem_amount':100001,'amount_unit':'toman',
                        'order_number':'W'*128,'created_at':'2026-09-10 08:05:00 +00:00'})
                assert not full['fits']
                assert server.gift_consumes==0
            # Gift: persist code first, exact redeem_amount independent of display_amount.
            hostile=seed('gifthostile','gift_card',pin_hostile='display_amount',expected_pin=False)
            assert php('snapshot',pay_id=hostile)['valid'] is False
            g1=seed('giftexact','gift_card');server.card=card()
            assert php('save',pay_id=g1,method='gift_card',code='gift-code-one')['status']=='saved'
            assert php('save',pay_id=g1,method='gift_card',code='different-code')['status']=='locked'
            assert php('giftverify',pay_id=g1,method='gift_card')['status']=='active'
            assert php('giftnormalize',pay_id=g1,method='gift_card',card=server.card)=='exact'
            assert php('giftnormalize',pay_id=g1,method='gift_card',
                hostile_field='display_amount',card=dict(server.card,display_amount=100001))=='review'
            assert php('giftreserve',pay_id=g1,method='gift_card',card=server.card)
            assert php('consume',pay_id=g1,method='gift_card')['status']=='proven'
            assert server.gift_consumes==1
            assert 'gift-code-one' not in sql('SELECT evidence_payload FROM payment_transactions')
            assert 'gift-code-one' not in json.dumps(php('proof',pay_id=g1))
            # Same persisted operation may win in a concurrent request before this call returns.
            gi=seed('giftidempotent','gift_card');server.card=card('CARD_IDEMPOTENT');server.gift_idempotent_first=True
            assert php('save',pay_id=gi,method='gift_card',code='same-operation-code')['status']=='saved'
            assert php('giftreserve',pay_id=gi,method='gift_card',card=server.card)
            assert php('consume',pay_id=gi,method='gift_card')['status']=='proven'
            # Unknown code remains protected/manual; known no-effect mismatch may replace.
            gm=seed('giftmismatch','gift_card');server.card=card('CARD_MISMATCH',100000)
            assert php('save',pay_id=gm,method='gift_card',code='wrong-value')['status']=='saved'
            assert php('giftnormalize',pay_id=gm,method='gift_card',card=server.card)=='mismatch'
            assert php('giftreserve',pay_id=gm,method='gift_card',card=server.card) is None
            assert php('reject',pay_id=gm,code='wrong-value',reason='mismatch')
            assert php('save',pay_id=gm,method='gift_card',code='replacement')['status']=='saved'
            assert php('save',pay_id=gm,method='gift_card',code='unknown-code')['status']=='locked'
            assert php('typed',pay_id=gm) is False
            sql(f"UPDATE pays SET state='have_sent',message_id=222,chat_id=9001 WHERE id={gm}")
            sql(f"UPDATE payment_attempts SET evidence_review_sent_at=NOW() WHERE pay_row_id={gm}")
            assert php('typed',pay_id=gm) is True
            code_cipher=sql(f"SELECT evidence_code FROM payment_attempts WHERE pay_row_id={gm}")
            sql(f"UPDATE payment_attempts SET evidence_code=CONCAT(evidence_code,'A') WHERE pay_row_id={gm}")
            assert php('typed',pay_id=gm) is False
            sql(f"UPDATE payment_attempts SET evidence_code='{code_cipher}',evidence_review_sent_at=NULL WHERE pay_row_id={gm}")
            assert php('typed',pay_id=gm) is False
            # Gift partial commit500 recovers same reference with fresh signed nonce.
            g2=seed('giftlost','gift_card');server.card=card('CARD_UUID_2');server.gift_fail_once=True
            assert php('save',pay_id=g2,method='gift_card',code='gift-code-two')['status']=='saved'
            assert php('giftreserve',pay_id=g2,method='gift_card',card=server.card)
            assert php('consume',pay_id=g2,method='gift_card')['status']=='pending'
            assert php('consume',pay_id=g2,method='gift_card')['status']=='proven'
            refs=[b['reference'] for path,h,b in server.posts if path.endswith('/redeem') and b.get('code')==fixture_gift_code('gift-code-two')]
            assert len(refs)==2 and refs[0]==refs[1]
            # Unredeem/active after uncertain consume never causes blind new consumption.
            g3=seed('giftreset','gift_card');server.card=card('CARD_UUID_3');server.gift_fail_once=True
            assert php('save',pay_id=g3,method='gift_card',code='gift-code-three')['status']=='saved'
            assert php('giftreserve',pay_id=g3,method='gift_card',card=server.card)
            assert php('consume',pay_id=g3,method='gift_card')['status']=='pending'
            server.card['status']='active';count=server.gift_consumes
            assert php('consume',pay_id=g3,method='gift_card')['status']=='review'
            assert server.gift_consumes==count
            # Transport does not follow redirects or accept unbounded bodies.
            server.redirect=True;assert not php('http')['ok'];server.redirect=False
            server.overflow=True;assert not php('http')['ok'];server.overflow=False
            # The retry policy applies only to read-only HTTP and uses a shared
            # short deadline; credentials/auth/invalid identity are not retried.
            before_reads=server.read_requests
            server.read_faults=[503]
            assert php('http')['ok'] and server.read_requests==before_reads+2
            before_reads=server.read_requests
            server.read_faults=[429]
            assert php('http')['ok'] and server.read_requests==before_reads+2
            before_reads=server.read_requests
            server.read_faults=[404]
            assert php('http')['http']==404 and server.read_requests==before_reads+1
            before_reads=server.read_requests
            server.read_faults=[503,503]
            assert php('http')['ok'] and server.read_requests==before_reads+3
            # Gift verify is an authenticated non-consuming check. A new HMAC
            # nonce is required on each read retry; redeem is never retried here.
            before_verifies=server.verify_requests
            before_consumes=server.gift_consumes
            server.verify_faults=[503]
            assert php('giftverify',pay_id=g1,method='gift_card')['status']=='active'
            assert server.verify_requests==before_verifies+2
            assert server.gift_consumes==before_consumes
            # Rotation/tampering closes old snapshots; never changes the namespace or erases intent.
            assert php('snapshot',pay_id=g3,webhook='different-webhook-key-1234567890')['valid'] is False
            assert php('snapshot',pay_id=g3)['valid'] is True
            assert server.lock_checks>=10
            return {'complete':True,'contracts':['partial_schema','encrypted_config','explicit_mapping','stable_namespace',
                'full_exact_quote','exact_rial_conversion','ready_only','original_time',
                'bank_preissue_grace_and_minute_precision','sql_collision_fence','pre_dispatch_intent','sms_same_key_recovery','sms_expired_recovery',
                'manual_settled_evidence_only','typed_review_authenticity','gift_single_code','gift_exact_redeem_amount',
                'gift_partial_commit','gift_nonce_reference','gift_uncertain_active_hold',
                'bounded_transport','rotation_fails_closed'],'loopback_dispatches':server.lock_checks,
                'exact_quote':True,'replay_and_partial_commit':True,'no_http_transaction':True,
                'scope':'owned socket MariaDB and loopback only'}
        finally:
            server.shutdown();server.server_close();thread.join(timeout=5)
if __name__=='__main__':
    parser=argparse.ArgumentParser()
    parser.add_argument('--module',type=Path,default=ROOT/'src/Finance/PaymentEvidence.php')
    parser.add_argument('--negative-controls',action='store_true')
    args=parser.parse_args()
    if args.negative_controls:
        source=args.module.read_text()
        mutations={
            'suffix_only':("$quote!==null && ($evidence['amount_toman']??null)===$quote",
                "$quote!==null && substr((string)($evidence['amount_toman']??''),-3)===substr($quote,-3)"),
            'fresh_retry_key':("['Idempotency-Key'=>$op]","['Idempotency-Key'=>bin2hex(random_bytes(32))]"),
            'gift_active_retry':("($retry && !in_array($current['status'],['redeemed','redeemed_offline'],true))",
                "false"),
        }
        red=[]
        with tempfile.TemporaryDirectory(prefix='achbot-evidence-negatives-') as tmp:
            for name,(before,after) in mutations.items():
                assert source.count(before)==1,(name,source.count(before))
                candidate=Path(tmp)/(name+'.php');candidate.write_text(source.replace(before,after))
                try:run(candidate)
                except AssertionError:red.append(name)
                else:raise RuntimeError('Negative control incorrectly passed: '+name)
        print(json.dumps({'complete':True,'expected_RED':red,'source_unchanged':True}))
    else:print(json.dumps(run(args.module),sort_keys=True))
