#!/usr/bin/env python3
"""Owned socket-MariaDB + real loopback current-API evidence regression.

No tenant config, installed target, provider, Telegram or external API is used.
"""
from __future__ import annotations
import argparse
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
 $result=['evidence'=>$n,'matches'=>$n && achbotPaymentEvidenceSmsMatches($n,$a,$profile)];break;
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
case 'save':
 $result=achbotPaymentEvidenceSaveGiftCode($db,$id,1001,$spec['code']);break;
case 'reject':
 $result=achbotPaymentEvidenceRejectGiftCode($db,$id,$spec['code'],$spec['reason']);break;
case 'giftverify':
 $code=achbotPaymentEvidenceReadGiftCode($db,$id);
 $result=$code===null?null:achbotPaymentEvidenceGiftVerify($profile,$code);break;
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
            if body['code']=='unknown-code':
                return self.send(404,{'api_version':'2','ok':False,'code':'card_not_found','data':None})
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
def run(module):
    with owned.owned_database() as (work,env):
        driver=work/'evidence.php';driver.write_text(DRIVER)
        def sql(q):return native.sql(work,env,q,DB).strip()
        sql((ROOT/'migrations/post-v9/003_payment_attempts.sql').read_text())
        def dbcheck():sql('SET innodb_lock_wait_timeout=1; UPDATE payment_attempts SET last_checked_at=NOW()')
        server=Server(dbcheck);thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
        base='http://127.0.0.1:'+str(server.server_port)
        def php(op,**spec):
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
                return ident
            def sms(ident,amount=100001,unit='toman'):
                return dict(id=ident,public_uid='UID'+str(ident),provider='BANK_FIXTURE',
                    provider_sms_id='provider'+str(ident),sender='BANK',mobile_source='phone',
                    bank_key='fixture',rule_id=7,amount=amount,display_unit=unit,
                    sms_date=now.strftime('%Y-%m-%d'),sms_time=now.strftime('%H:%M:%S'),status='ready')
            def card(uuid='CARD_UUID_1',amount=100001):
                return dict(card_uuid=uuid,status='active',game_key='fixture',redeem_value_id=42,
                    redeem_amount=amount,display_amount=999999,face_value=10,currency_label='fixture')
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
            # Late ready SMS after manual approval is consumption-only, proof is not settlement authority.
            late=seed('smslate');rl=sms(5);server.messages['5']=dict(rl)
            sql(f"UPDATE pays SET state='approved',approved_by=9001 WHERE id={late}")
            assert php('reserve',pay_id=late,row=rl) is None
            assert php('reserve',pay_id=late,row=rl,mode='evidence_only')
            assert php('consume',pay_id=late)['status']=='proven'
            assert php('validate',pay_id=late) is False
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
            refs=[b['reference'] for path,h,b in server.posts if path.endswith('/redeem') and b.get('code')=='gift-code-two']
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
            # Rotation/tampering closes old snapshots; never changes the namespace or erases intent.
            assert php('snapshot',pay_id=g3,webhook='different-webhook-key-1234567890')['valid'] is False
            assert php('snapshot',pay_id=g3)['valid'] is True
            assert server.lock_checks>=10
            return {'complete':True,'contracts':['partial_schema','encrypted_config','explicit_mapping','stable_namespace',
                'full_exact_quote','exact_rial_conversion','ready_only','original_time',
                'sql_collision_fence','pre_dispatch_intent','sms_same_key_recovery','sms_expired_recovery',
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
