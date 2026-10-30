#!/usr/bin/env python3
"""Current source API flow on socket MariaDB and authenticated loopback fixtures.

The optional roots compose uncommitted cooperating slices in a disposable copy;
repository source is never edited by this harness and no012/013 is executed.
"""
from pathlib import Path
from concurrent.futures import ThreadPoolExecutor
from http.server import ThreadingHTTPServer, BaseHTTPRequestHandler
import argparse, base64, hashlib, hmac, json, shutil, subprocess, threading, time
import test_access_state as owned
import test_v8_native as purchase
import test_post_v9_bank_buy as bank
import test_post_v9_manual_payments as manual
ROOT=Path(__file__).resolve().parents[1]
DB=purchase.SIDE[1]

class APIs(BaseHTTPRequestHandler):
    rows={}; cards={}; cache={}; events=[]; received=[]; next_id=0; mode='ok'; lock=threading.Lock()
    def log_message(self,*a): pass
    def reply(self,data,status=200):
        raw=json.dumps(data).encode();self.send_response(status);self.send_header('Content-Type','application/json');self.send_header('Content-Length',str(len(raw)));self.end_headers();
        try:self.wfile.write(raw)
        except (BrokenPipeError,ConnectionResetError):pass
    def auth(self,body=b''):
        if '/ach-pay-manager/' in self.path:return self.headers.get('Authorization')=='Bearer synthetic-bank-token'
        route='/ach-gcw/v2'+self.path.split('/ach-gcw/v2',1)[1]
        parts=[self.headers.get('X-ACH-Timestamp',''),self.headers.get('X-ACH-Nonce',''),self.command,route,body.decode()]
        sig=base64.urlsafe_b64encode(hmac.new(b'synthetic-gift-secret','\n'.join(parts).encode(),hashlib.sha256).digest()).decode().rstrip('=')
        return self.headers.get('X-ACH-Key-Id')=='fixture-key' and hmac.compare_digest(sig,self.headers.get('X-ACH-Signature',''))
    def do_GET(self):
        from urllib.parse import urlparse,parse_qs
        if not self.auth():return self.reply({},401)
        if self.mode=='offline':return self.reply({},503)
        self.received.append(time.monotonic())
        if self.mode=='slow':time.sleep(1)
        path=urlparse(self.path).path; q=parse_qs(urlparse(self.path).query)
        with self.lock:
            self.events.append(('GET',self.path))
            if path.endswith('/messages'):
                after=int(q.get('after_id',['0'])[0]);rows=[dict(r) for i,r in sorted(self.rows.items()) if i>after and r['status']=='ready'];page=rows[:100]
                return self.reply({'data':page,'pagination':{'has_more':len(rows)>100,'next_after_id':page[-1]['id'] if page else after}})
            if '/messages/' in path:
                r=self.rows.get(int(path.rsplit('/',1)[1]));return self.reply({'data':r} if r else {},200 if r else 404)
            return self.reply({'data':[],'pagination':{'has_more':False,'next_after_id':0}})
    def do_POST(self):
        body=self.rfile.read(int(self.headers.get('Content-Length','0')))
        if not self.auth(body):return self.reply({},401)
        data=json.loads(body)
        if self.mode=='offline':return self.reply({},503)
        with self.lock:
            self.events.append(('POST',self.path,data))
            if self.path.endswith('/actions'):
                n=int(self.path.split('/messages/')[1].split('/')[0]);key=self.headers.get('Idempotency-Key');r=self.rows[n]
                if key in self.cache:return self.reply(self.cache[key])
                if r['status']!='ready':return self.reply({'error':'used'},409)
                r['status']='used';out={'data':dict(r),'request_id':'sms-'+key[:16]};self.cache[key]=out
                if self.mode=='lost':return self.reply({},503)
                return self.reply(out)
            card=self.cards.get(data.get('code'))
            if not card:return self.reply({'api_version':'2','ok':False,'code':'card_not_found'},404)
            if self.path.endswith('/verify'):return self.reply({'api_version':'2','ok':True,'code':'card_verified','data':dict(card)})
            ref=data['reference'];old=self.cache.get(ref)
            if old:
                out=json.loads(json.dumps(old));out['data']['idempotent']=True;out['code']='card_redeem_idempotent';return self.reply(out)
            if card['status']!='active':return self.reply({'api_version':'2','ok':False,'code':'card_used'},409)
            card['status']='redeemed';out={'api_version':'2','ok':True,'code':'card_redeemed','reference':ref,'request_id':'gift-'+ref[-12:],'data':dict(card,idempotent=False)};self.cache[ref]=out
            if self.mode=='lost':return self.reply({},503)
            return self.reply(out)

PREP=r'''
define('ACHBOT_PAYMENT_EVIDENCE_TEST_TRANSPORT',true);
$telegramWebhookSecret='synthetic-webhook-secret-0123456789';
require_once $root.'/src/Finance/PaymentEvidence.php';
$paymentKeys=['bankAccount'=>'6037999999999999','holderName'=>'Fixture','giftCardUrl'=>'https://example.org/gift-card',
 'smsApiUrl'=>$spec['url'].'/wp-json/ach-pay-manager/v1','smsApiHorizonHours'=>'24','smsApiTimezone'=>'UTC',
 'smsApiToken'=>achbotPaymentEvidenceProtect('setting:smsApiToken',['value'=>'synthetic-bank-token']),
 'giftApiUrl'=>$spec['url'].'/wp-json/ach-gcw/v2','giftApiKeyId'=>'fixture-key','giftApiAmountUnit'=>'toman',
 'giftApiSecret'=>achbotPaymentEvidenceProtect('setting:giftApiSecret',['value'=>'synthetic-gift-secret'])];
$botState=array_replace($botState,['cartToCartState'=>'on','giftCardState'=>'on','smsApiVerifyState'=>'on','giftApiVerifyState'=>'on']);
if(!empty($spec['off'])){$botState['smsApiVerifyState']='off';$botState['giftApiVerifyState']='off';}
if(isset($spec['seller_override']))$paymentKeys['giftCardUrl']=$spec['seller_override'];
$mainValues=achbotMessages('legacy_messages');$buttonValues=achbotMessages('legacy_buttons');
require_once $root.'/src/Finance/ApiPayments.php';
require_once $root.'/src/Finance/ReferralReward.php';
'''
OPS=r'''
$op=$spec['op']??'check';$id=(int)$spec['pay'];$pay=achbotApiPaymentRow($connection,$id);
if($op==='issue')$value=achbotManualClaim($connection,$pay,$spec['method']??'bank_transfer',1001);
elseif($op==='route'){$from_id=(int)($spec['actor']??1001);$chat_id=(int)($spec['chat']??$from_id);$data=(string)($spec['data']??'');$text=$spec['text']??null;$callbackId='fixture-callback';$userInfo=['step'=>$spec['step']??'none'];$value=require $root.'/src/Finance/Routes/ApiPayments.php';}
elseif($op==='initial_step')$value=achbotApiPaymentInitialStep($connection,$pay['hash_id'],'legacy-gift-step');
elseif($op==='budget'){$deadline=hrtime(true)/1000000000+(float)$spec['seconds'];$GLOBALS['achbotPaymentEvidenceDeadline']=$deadline;$value=['status'=>achbotApiPaymentCheck($connection,$id),'restored'=>($GLOBALS['achbotPaymentEvidenceDeadline']??null)===$deadline];unset($GLOBALS['achbotPaymentEvidenceDeadline']);}
elseif($op==='save')$value=achbotPaymentEvidenceSaveGiftCode($connection,$id,1001,$spec['code']);
elseif($op==='check')$value=achbotApiPaymentCheck($connection,$id);
elseif($op==='settle'){$proof=achbotPaymentEvidenceGetProof($connection,$id);$value=$proof?achbotApiPaymentSettle($connection,$id,$proof):'no-proof';}
elseif($op==='human')$value=achbotApproveManualWalletTopup($connection,$pay['hash_id'],9001,9001);
elseif($op==='receipt'){$v=achbotManualBeginEvidence($connection,$pay,'bank_transfer',1001,'fixture-photo');$value=$v?achbotManualFinishEvidence($connection,$pay['hash_id'],'fixture-photo',$v,222,9001):false;}
elseif($op==='view'){$c=achbotApiPaymentContext($connection,$pay['hash_id'],1001);$value=['keys'=>$c?json_decode(achbotApiPaymentKeyboard($c),true):null,'text'=>$c?achbotApiPaymentInvoiceText($c):null,'allowed'=>achbotManualReceiptAllowed($connection,$pay,'bank_transfer',1001)];}
elseif($op==='track'){achbotApiPaymentTrackInvoice($connection,$pay['hash_id'],1001,(object)['ok'=>true,'result'=>(object)['message_id'=>567]]);$value=true;}
elseif($op==='expire')$value=achbotPayUxExpireUnstartedManual($connection,$id);
elseif($op==='maintain')$value=achbotApiPaymentMaintain($connection,(int)($spec['limit']??12));
elseif($op==='notice'){achbotApiPaymentNotice($connection,$pay,$spec['status']??'confirmed');$value=true;}
elseif($op==='access'){$from_id=(int)$spec['actor'];$value=achbotAdminRequestAllowed('apiReview_'.$pay['hash_id'],null,'none');}
elseif($op==='attention'){require_once $root.'/src/Finance/RecoveryInspection.php';$q=$connection->query('SELECT p.id FROM pays p WHERE p.id='.$id.' AND '.achbotRecoveryAttentionSql($connection));$value=$q->num_rows===1;}
elseif($op==='profile_shift'){$a=achbotApiPaymentAttempt($connection,$id);$p=achbotPaymentEvidenceSnapshot($a);$p['issued_at']=time()-(int)($spec['age']??90000);unset($p['profile_sha256']);$sealed=achbotPaymentEvidenceProtect('profile:'.$a['id'].':'.$id,$p);$q=$connection->prepare('UPDATE payment_attempts SET evidence_profile=? WHERE id=?');$q->bind_param('si',$sealed,$a['id']);$q->execute();$q->close();$value=true;}
elseif($op==='photo_race'){$v=achbotManualBeginEvidence($connection,$pay,'bank_transfer',1001,'race-photo');$value=$v?sendReceiptToAdminsAndUpdatePay($connection,'race-photo','رسید تست',achbotApiPaymentReviewKeyboard($pay),9001,$pay['hash_id'],$v):false;}
else throw new RuntimeException('unknown fixture op');
echo json_encode(['result'=>$value,'events'=>$GLOBALS['events']],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
'''

def query(work,env,sql):return bank.query(work,env,sql)
def run(work,env,app,driver,url,**spec):
    spec['url']=url
    cmd=['php','-n','-d','extension=mysqlnd','-d','extension=mysqli','-d','extension=curl','-d','allow_url_fopen=0','-d','display_errors=0','-d','log_errors=1','-d','error_log=/dev/stderr',str(driver),str(app),str(work/'mysql.sock'),DB,json.dumps(spec)]
    p=subprocess.run(cmd,cwd=work,env=env,capture_output=True,text=True,timeout=35)
    if p.returncode:raise AssertionError(('PHP fixture error',spec,p.stdout,p.stderr[-1800:]))
    try:r=json.loads(p.stdout)
    except ValueError as e:raise AssertionError((p.stdout,p.stderr)) from e
    r['stderr']=p.stderr;return r

def check(evidence_root,settings_root):
    cases=[];srv=ThreadingHTTPServer(('127.0.0.1',0),APIs);thread=threading.Thread(target=srv.serve_forever,daemon=True);thread.start();url=f'http://127.0.0.1:{srv.server_port}'
    try:
      with owned.owned_database() as (work,env):
        app=work/'app';app.mkdir()
        for folder in ['src','messages']:
            shutil.copytree(ROOT/folder,app/folder)
        shutil.copyfile(evidence_root/'src/Finance/PaymentEvidence.php',app/'src/Finance/PaymentEvidence.php')
        for audience in ['user','admin']:shutil.copyfile(settings_root/f'messages/fa/{audience}.php',app/f'messages/fa/{audience}.php')
        purchase.init(work,env,url)
        for f in ['001_marzban_panel_group_id.sql','003_payment_attempts.sql','006_payment_message_refs.sql','007_service_effects.sql','008_referral_awards.sql','009_bank_buy_invoice_claims.sql']:
            query(work,env,(ROOT/'migrations/post-v9'/f).read_text())
        query(work,env,(evidence_root/'migrations/post-v9/014_api_payment_evidence.sql').read_text())
        query(work,env,"UPDATE server_info SET ucount=100 WHERE id=2")
        query(work,env,"INSERT INTO setting(type,value) VALUES('BOT_STATES','{\"cartToCartState\":\"on\",\"giftCardState\":\"on\"}')")
        php=bank.PHP[:bank.PHP.index("$op=$spec['op']??'auto';")]+PREP+OPS
        php=php.replace("function achbotApplyServiceEdit(...$args) {throw new RuntimeException('unexpected_edit');}","function achbotApplyServiceEdit($db,$sid,$change) {$GLOBALS['events'][]=['event'=>'EDIT','change'=>$change,'tx'=>(int)$db->query('SELECT @@in_transaction')->fetch_row()[0]];return(object)['success'=>true,'verified'=>true,'applied_expire'=>1802000000,'applied_bytes'=>3221225472];}")
        php=php.replace("function getMainKeys() {return '{}';}",r"""function getMainKeys() {return '{}';}
function bot($method,$data){
    $GLOBALS['events'][]=['event'=>'BOT','method'=>$method,'data'=>$data,'tx'=>(int)$GLOBALS['connection']->query('SELECT @@in_transaction')->fetch_row()[0]];
    if(!empty($GLOBALS['spec']['queued_human'])&&empty($GLOBALS['queue_once'])){$GLOBALS['queue_once']=true;$p=achbotApiPaymentRow($GLOBALS['connection'],(int)$GLOBALS['spec']['pay']);achbotApproveManualWalletTopup($GLOBALS['connection'],$p['hash_id'],9001,9001);}
    return(object)['ok'=>true];
}
function answerCallbackQuery(...$args){$GLOBALS['events'][]=['event'=>'ACK','at'=>hrtime(true)/1000000000];}
function alert(...$args){answerCallbackQuery(...$args);}
function setUser($step='none',$field='step'){$GLOBALS['events'][]=['event'=>'STATE','step'=>$step];}
function sendPhoto(...$args){$GLOBALS['events'][]=['event'=>'PHOTO'];if(!empty($GLOBALS['spec']['photo_api']))achbotApiPaymentCheck($GLOBALS['connection'],(int)$GLOBALS['spec']['pay']);return(object)['ok'=>true,'result'=>(object)['message_id'=>456]];}
""")
        php=php.replace("function editKeys(...$args) {\n    $GLOBALS['events'][]=['event'=>'EDIT_KEYS','state'=>fixtureState()];\n}","function editKeys(...$args){$GLOBALS['events'][]=['event'=>'EDIT_KEYS','state'=>fixtureState(),'chat'=>$args[2]??null];return !empty($GLOBALS['spec']['fail_staff'])?null:(object)['ok'=>true];}")
        php=php.replace("return (object)['ok'=>true];\n}\nfunction editKeys", "return (object)['ok'=>true,'result'=>(object)['message_id'=>456]];\n}\nfunction editKeys")
        php=php.replace("    if ($strict!==true) throw new RuntimeException('non_strict_remote_get');", "    if((int)$GLOBALS['connection']->query('SELECT @@in_transaction')->fetch_row()[0]!==0)throw new RuntimeException('fixture_native_get_under_financial_lock');\n    if(str_starts_with($name,'api_edit'))return(object)['username'=>$name,'status'=>'active','expire'=>1900000000,'data_limit'=>10737418240,'used_traffic'=>0];\n    if ($strict!==true) throw new RuntimeException('non_strict_remote_get');")
        # Controlled process-loss injection in disposable source, never runtime hooks.
        flow=app/'src/Finance/ApiPayments.php';flow.write_text(flow.read_text().replace('$delivery=achbotAutoBankProvisionBuySub(',"if(!empty($GLOBALS['spec']['crash_before_native']))throw new RuntimeException('fixture_crash_after_claim');\n            $delivery=achbotAutoBankProvisionBuySub(").replace("$result=(object)($payload['result']??[]);","if(!empty($GLOBALS['spec']['crash_after_verified']))throw new RuntimeException('fixture_crash_after_verified');\n    $result=(object)($payload['result']??[]);"))

        driver=work/'api-flow.php';driver.write_text(php)
        human_driver=work/'human-flow.php';human_driver.write_text(manual.PHP_ROUTE.replace("$GLOBALS['spec']=$spec;","$GLOBALS['spec']=$spec;\n"+PREP))
        def call(**s):return run(work,env,app,driver,url,**s)
        def human(route,data):
            r=run(work,env,app,human_driver,url,route=route,data=data,actor=9001)
            assert not r.get('fatal'),r
            return r
        seed_index=0
        def seed(key,kind='INCREASE_WALLET',method='bank_transfer',days=0,volume=0,count=1,order=0):
            nonlocal seed_index
            if key not in ['collision2','sequential2']:seed_index+=1
            price=101 if method=='gift_card' else 101+seed_index*1000
            query(work,env,f"INSERT INTO pays(hash_id,user_id,type,plan_id,server_id,order_id,price,day,volume,agent_count,description,request_date,state) VALUES('{key}',1001,'{kind}',7,2,{order},{price},{days},{volume},{count},'api_{key}',UNIX_TIMESTAMP(),'pending')")
            n=int(query(work,env,f"SELECT id FROM pays WHERE hash_id='{key}'"))
            if kind=='RENEW_SCONFIG':query(work,env,f"UPDATE pays SET description='{{\"remark\":\"api_edit_external\",\"uuid\":\"fixture-uuid\",\"marzban\":true,\"server_id\":2}}' WHERE id={n}")
            assert call(op='issue',pay=n,method=method)['result'] is True,(key,call(op='issue',pay=n,method=method));return n
        def sms(n,extra=0):
            a=int(query(work,env,f'SELECT quote_amount FROM payment_attempts WHERE pay_row_id={n}').split('.')[0]);APIs.next_id+=1;rid=APIs.next_id
            APIs.rows[rid]={'id':rid,'public_uid':'uid'+str(rid),'status':'ready','amount':str(a+extra),'display_unit':'toman','received_at':time.strftime('%Y-%m-%dT%H:%M:%S+00:00'),'transfer_at':int(time.time()),'sms_date':time.strftime('%Y-%m-%d'),'sms_time':time.strftime('%H:%M:%S'),'provider':'fixture','sender':'bank','bank_key':'fixture'}
            return rid
        def due(n):query(work,env,f'UPDATE payment_attempts SET evidence_next_check_at=NULL,evidence_claim_token=NULL WHERE pay_row_id={n}')
        def state(n):return query(work,env,f'SELECT state FROM pays WHERE id={n}')
        def gift(code,amount=101,status='active'):
            APIs.cards[code]={'card_uuid':'card-'+code,'status':status,'redeem_amount':str(amount),'display_amount':'999999','game_key':'fixture','redeem_value_id':1}
        # OFF preserves original screenshot choice and no pin.
        query(work,env,"INSERT INTO pays(hash_id,user_id,type,price,request_date,state) VALUES('offmanual',1001,'INCREASE_WALLET',101,UNIX_TIMESTAMP(),'pending')")
        off=int(query(work,env,"SELECT id FROM pays WHERE hash_id='offmanual'"));r=call(op='issue',pay=off,off=True);assert r['result'] is True,r
        assert query(work,env,f'SELECT evidence_profile IS NULL FROM payment_attempts WHERE pay_row_id={off}')=='1';cases.append('off_manual_original_unpinned')
        n=seed('exactbank');bad=sms(n,1);r=call(pay=n);assert r['result']=='monitoring' and APIs.rows[bad]['status']=='ready' and state(n)=='pending',r
        due(n);good=sms(n);before=int(query(work,env,'SELECT wallet FROM users WHERE userid=1001'));r=call(pay=n);assert r['result']=='confirmed' and state(n)=='approved' and APIs.rows[good]['status']=='used',r
        assert int(query(work,env,'SELECT wallet FROM users WHERE userid=1001'))==before+int(query(work,env,f'SELECT price FROM pays WHERE id={n}'))
        assert call(op='settle',pay=n)['result']=='settled';assert APIs.rows[bad]['status']=='ready';cases.append('full_exact_quote_only_and_wallet_replay')
        quote=query(work,env,f'SELECT quote_amount FROM payment_attempts WHERE pay_row_id={n}').split('.')[0];local=query(work,env,f'SELECT price FROM pays WHERE id={n}');assert quote!=local
        reports=[e.get('text',e.get('data',{}).get('text','')) for e in r['events'] if e['event'] in ['MESSAGE','BOT'] and 'مبلغ دقیق' in e.get('text',e.get('data',{}).get('text',''))];assert reports and any('مبلغ دقیق: '+quote+' تومان' in text for text in reports),r
        again=call(op='notice',pay=n);assert not any('مبلغ دقیق' in e.get('text',e.get('data',{}).get('text','')) for e in again['events']);cases.append('complete_report_uses_original_random_quote_and_durable_dedup')

        # A terminal invoice with consumed proof cannot claim a second ready SMS.
        first=seed('sequential1');old=sms(first);assert call(pay=first)['result']=='confirmed'
        second=seed('sequential2');assert query(work,env,f'SELECT quote_amount FROM payment_attempts WHERE pay_row_id={first}')==query(work,env,f'SELECT quote_amount FROM payment_attempts WHERE pay_row_id={second}')
        fresh=sms(second);r=call(pay=second);assert r['result']=='confirmed' and APIs.rows[old]['status']=='used' and APIs.rows[fresh]['status']=='used',r;cases.append('proven_terminal_excluded_new_same_quote_distinct_sms')
        # Local same full-amount collision leaves external evidence untouched.
        n=seed('collision1');m=seed('collision2');quote=query(work,env,f'SELECT quote_amount FROM payment_attempts WHERE pay_row_id={n}')
        # Same original quote is created legitimately by deterministic PHP seed.
        assert query(work,env,f'SELECT quote_amount FROM payment_attempts WHERE pay_row_id={m}')==quote
        rid=sms(n);r=call(pay=n);assert r['result']=='review' and APIs.rows[rid]['status']=='ready' and state(n)=='pending',r;cases.append('full_quote_collision_no_first_match_or_consumption')
        # Fresh timestamp windows prevent prior collision rows from later fixtures.
        APIs.rows={};query(work,env,"UPDATE payment_attempts SET evidence_profile=NULL WHERE order_hash IN ('collision1','collision2')")
        n=seed('fallback');v=call(op='view',pay=n)['result'];assert not v['allowed'] and len(v['keys']['inline_keyboard'])==2
        query(work,env,f'UPDATE payment_attempts SET created_at=NOW()-INTERVAL 600 SECOND WHERE pay_row_id={n}');v=call(op='view',pay=n)['result'];assert v['allowed'] and len(v['keys']['inline_keyboard'])==3
        call(op='track',pay=n);query(work,env,f'UPDATE payment_attempts SET created_at=NOW()-INTERVAL 90 DAY WHERE pay_row_id={n}');assert call(op='expire',pay=n)['result'] is False;assert state(n)=='pending';cases.append('exact600_fallback_original_reference_and_no_unpaid_timeout')
        APIs.mode='offline';due(n);assert call(pay=n)['result']=='unavailable';assert state(n)=='pending';assert call(op='attention',pay=n)['result'] is True;assert call(op='receipt',pay=n)['result'] is True;APIs.mode='ok';cases.append('offline_bank_keeps_genuine_manual_receipt')
        view=call(op='view',pay=n)['result'];assert 'انتظار بررسی' in view['text'] and '30 دقیقه' not in view['text'] and len(view['keys']['inline_keyboard'])==2;cases.append('bank_submitted_receipt_text_retains_check_without_new_receipt_invitation')
        # Late SMS after real manual approval consumes only, never credits again.
        assert call(op='human',pay=n)['result'] is not None;balance=query(work,env,'SELECT wallet FROM users WHERE userid=1001');rid=sms(n);due(n);assert call(pay=n)['result']=='settled';assert APIs.rows[rid]['status']=='used' and query(work,env,'SELECT wallet FROM users WHERE userid=1001')==balance;cases.append('late_ready_sms_manual_settlement_consumption_only')
        # Real simultaneous human/API wallet claim on one pays row.
        APIs.rows={};n=seed('racewallet');query(work,env,f'UPDATE payment_attempts SET created_at=NOW()-INTERVAL 601 SECOND WHERE pay_row_id={n}');assert call(op='receipt',pay=n)['result'];rid=sms(n);before=int(query(work,env,'SELECT wallet FROM users WHERE userid=1001'))
        with ThreadPoolExecutor(2) as pool:out=list(pool.map(lambda op:call(pay=n,op=op),['human','check']))
        assert int(query(work,env,'SELECT wallet FROM users WHERE userid=1001'))==before+int(query(work,env,f'SELECT price FROM pays WHERE id={n}')) and state(n)=='approved',out
        due(n);r=call(pay=n);assert APIs.rows[rid]['status']=='used',r;cases.append('concurrent_real_human_api_wallet_at_most_once')
        # Existing CREATE journal: standard, custom and bulk all use actor0 proof.
        APIs.rows={}
        for label,days,vol,count in [('standard',0,0,1),('custom',12,3,1),('bulk',0,0,3)]:
            n=seed(label,'BUY_SUB',days=days,volume=vol,count=count);rid=sms(n);r=call(pay=n);assert r['result']=='confirmed',(label,r)
            assert int(query(work,env,f"SELECT COUNT(*) FROM service_effects WHERE pay_row_id={n} AND effect_kind='create' AND state='local_recorded'"))==count,(label,r)
            assert query(work,env,f'SELECT approved_by FROM pays WHERE id={n}')=='0';assert call(op='settle',pay=n)['result']=='settled';cases.append('proof_backed_'+label+'_existing_create_journal')
            APIs.rows={}
        # Every edit subject uses pinned quantities and the same real local finisher.
        query(work,env,"INSERT INTO orders_list(userid,token,transid,fileid,server_id,inbound_id,remark,uuid,expire_date,link,amount,status,date) VALUES(1001,'fixture-token','',7,2,0,'api_edit_order','fixture-uuid',1900000000,'[]',101,1,'1700000000')")
        oid=int(query(work,env,"SELECT id FROM orders_list WHERE remark='api_edit_order'"))
        for label,kind,days,vol in [('renew','RENEW_ACCOUNT',0,0),('day',f'INCREASE_DAY_{oid}_1',4,0),('volume',f'INCREASE_VOLUME_{oid}_1',0,5),('external','RENEW_SCONFIG',0,0)]:
            APIs.rows={};n=seed(label,kind,days=days,volume=vol,order=oid if label!='external' else 0);sms(n);r=call(pay=n);assert r['result']=='confirmed',(label,r)
            edits=[e for e in r['events'] if e['event']=='EDIT'];assert len(edits)==1 and edits[0]['tx']==0,(label,r)
            assert call(op='settle',pay=n)['result']=='settled';cases.append('proof_backed_'+label+'_existing_edit_finisher')
        # Real human route/API contenders share one actual local claim for every subject.
        subjects=[('rstandard','BUY_SUB',0,0,1,'standard_approval','accept',0),('rcustom','BUY_SUB',12,3,1,'checkout','accCustom',0),('rbulk','BUY_SUB',0,0,3,'standard_approval','accept',0),('rrenew','RENEW_ACCOUNT',0,0,1,'renewal','approveRenewAcc',oid),('rday',f'INCREASE_DAY_{oid}_1',4,0,1,'extension','approveIncreaseDay',oid),('rvolume',f'INCREASE_VOLUME_{oid}_1',0,5,1,'extension','approveIncreaseVolume',oid),('rexternal','RENEW_SCONFIG',0,0,1,'standard_approval','accept',0)]
        for label,kind,days,vol,count,route,prefix,order in subjects:
            APIs.rows={};n=seed(label,kind,days=days,volume=vol,count=count,order=order);key=query(work,env,f'SELECT hash_id FROM pays WHERE id={n}')
            query(work,env,f'UPDATE payment_attempts SET created_at=NOW()-INTERVAL 601 SECOND WHERE pay_row_id={n}');assert call(op='receipt',pay=n)['result'];rid=sms(n)
            with ThreadPoolExecutor(2) as pool:
                api=pool.submit(call,pay=n);person=pool.submit(human,route,prefix+key);r1=api.result();r2=person.result()
            assert state(n) in ['approved','auto_cart_approved'],(label,r1,r2)
            if kind=='BUY_SUB':assert int(query(work,env,f"SELECT COUNT(*) FROM service_effects WHERE pay_row_id={n} AND effect_kind='create' AND state='local_recorded'"))==count,(label,r1,r2)
            due(n);r=call(pay=n);assert APIs.rows[rid]['status']=='used',(label,r)
            assert call(op='settle',pay=n)['result']=='settled';cases.append('concurrent_real_human_api_'+label+'_at_most_once')
        # Crash after funded local claim before any CREATE uses its existing hold once.
        APIs.rows={};n=seed('restartcreate','BUY_SUB');sms(n);capacity=int(query(work,env,'SELECT ucount FROM server_info WHERE id=2'));r=call(pay=n,crash_before_native=True);assert r['result']=='review' and state(n)=='processing',r
        assert int(query(work,env,'SELECT ucount FROM server_info WHERE id=2'))==capacity-1
        due(n);r=call(op='maintain',pay=n);assert state(n)=='auto_cart_approved' and sum(e['event']=='POST' for e in r['events'])==1,r
        assert int(query(work,env,'SELECT ucount FROM server_info WHERE id=2'))==capacity-1;cases.append('restart_after_local_claim_reuses_capacity_hold_once')
        # Verified native edit survives process loss before SQL finish; restart only finishes locally.
        APIs.rows={};n=seed('restartedit','RENEW_ACCOUNT',order=oid);sms(n);r=call(pay=n,crash_after_verified=True);assert r['result']=='review' and state(n)=='processing',r
        due(n);r=call(op='maintain',pay=n);assert state(n)=='auto_cart_approved' and not any(e['event']=='EDIT' for e in r['events']),r;cases.append('restart_verified_edit_finishes_without_native_redispatch');cases.append('admitted_maintenance_recovers_proven_processing_reserved_and_verified')
        # Unknown CREATE keeps existing no-replay journal and no false refund/success.
        APIs.rows={};n=seed('unknowncreate','BUY_SUB');sms(n);r=call(pay=n,mode='lost');assert r['result']=='review' and state(n)=='processing',r
        r=call(op='settle',pay=n);assert r['result']=='review' and not any(e['event']=='POST' for e in r['events']),r;cases.append('uncertain_create_journal_never_redispatched')
        # Durable monitoring horizon stops NEW API scans, while the original invoice stays manual.
        APIs.rows={};n=seed('horizon');call(op='profile_shift',pay=n,age=90000);before=len(APIs.events);assert call(pay=n)['result']=='manual' and len(APIs.events)==before
        query(work,env,f'UPDATE payment_attempts SET created_at=NOW()-INTERVAL 90000 SECOND WHERE pay_row_id={n}');assert call(op='view',pay=n)['result']['allowed'];assert call(op='expire',pay=n)['result'] is False;cases.append('configured_horizon_stops_new_scans_without_financial_expiry')
        # An in-flight invoice edit rechecks a real human settlement after Telegram returns.
        APIs.rows={};n=seed('queuededit');query(work,env,f'UPDATE payment_attempts SET created_at=NOW()-INTERVAL 601 SECOND WHERE pay_row_id={n}');assert call(op='receipt',pay=n)['result'];r=call(op='track',pay=n,queued_human=True)
        bodies=[e for e in r['events'] if e['event']=='BOT' and e['method']=='editMessageText'];assert state(n)=='approved' and len(bodies)>=2 and 'apiCheck_' not in json.dumps(bodies[-1]),r;cases.append('queued_invoice_edit_human_winner_converges_final')
        # Genuine photo delivery after API wins updates the just-created staff copy.
        APIs.rows={};n=seed('queuedphoto');query(work,env,f'UPDATE payment_attempts SET created_at=NOW()-INTERVAL 601 SECOND WHERE pay_row_id={n}');sms(n);r=call(op='photo_race',pay=n,photo_api=True);assert r['result'] is True and state(n)=='approved' and any(e['event']=='EDIT_KEYS' for e in r['events']),r;cases.append('queued_photo_copy_converges_after_api_settlement')
        # Actual bot ingress capability check retains all existing human review levels.
        query(work,env,"INSERT INTO users(userid,name,username,refcode,wallet,date,isAdmin) VALUES(9004,'Support','support','0',0,'1700000000',1),(9005,'Revoked','revoked','0',0,'1700000000',0)")
        query(work,env,"INSERT INTO setting(type,value) VALUES('ADMIN_ROLE_9004','support'),('ADMIN_ROLE_9005','support'),('ADMIN_NOTIFY_9004','off')")
        for actor,want in [(9001,True),(9002,True),(9004,True),(9005,False),(1001,False)]:assert call(op='access',pay=n,actor=actor)['result'] is want
        cases.append('actual_ingress_review_owner_operator_support_current_revoked_customer')
        query(work,env,f'''UPDATE pays SET admins_messages='[{{"chat_id":9004,"message_id":333}}]' WHERE id={n}''')
        old_digest=query(work,env,f'SELECT evidence_staff_digest FROM payment_attempts WHERE pay_row_id={n}');r=call(op='notice',pay=n,fail_staff=True);assert query(work,env,f'SELECT evidence_staff_digest FROM payment_attempts WHERE pay_row_id={n}')==old_digest,r
        r=call(op='notice',pay=n);assert any(e['event']=='EDIT_KEYS' and e['chat']==9004 for e in r['events']) and query(work,env,f'SELECT evidence_staff_digest FROM payment_attempts WHERE pay_row_id={n}')!=old_digest,r;cases.append('staff_final_copy_retry_after_failure_ignores_receiving_off')
        # Fair projection: more than one batch of old clean rows cannot starve later invoices.
        for i in range(15):seed('fair'+str(i))
        query(work,env,'UPDATE payment_attempts SET evidence_next_check_at=NOW()+INTERVAL 1 DAY,evidence_projection_checked_at=NULL')
        for _ in range(10):call(op='maintain',pay=n,limit=5)
        assert query(work,env,"SELECT COUNT(*) FROM payment_attempts WHERE order_hash LIKE 'fair%' AND evidence_projection_checked_at IS NULL")=='0';cases.append('bounded_projection_fairness_more_than_one_batch')
        # Gift exact single card; clear definite no-effect replacement; unknown is typed review.
        n=seed('giftvalue',method='gift_card');view=call(op='view',pay=n,seller_override='https://changed.invalid/new')['result'];assert any(button.get('url')=='https://example.org/gift-card' for row in view['keys']['inline_keyboard'] for button in row);gift('wrong',102);assert call(op='save',pay=n,code='wrong')['result']['status']=='saved';assert call(pay=n)['result']=='mismatch' and APIs.cards['wrong']['status']=='active'
        gift('right');assert call(op='save',pay=n,code='right')['result']['status']=='saved';due(n);r=call(pay=n);assert r['result']=='confirmed' and APIs.cards['right']['status']=='redeemed',r;cases.append('gift_canonical_redeem_exact_single_code_replacement')
        for label,days,vol,count in [('giftstandard',0,0,1),('giftcustom',12,3,1),('giftbulk',0,0,2)]:
            n=seed(label,'BUY_SUB',method='gift_card',days=days,volume=vol,count=count);gift(label);assert call(op='save',pay=n,code=label)['result']['status']=='saved';r=call(pay=n);assert r['result']=='confirmed',(label,r)
            assert int(query(work,env,f"SELECT COUNT(*) FROM service_effects WHERE pay_row_id={n} AND effect_kind='create' AND state='local_recorded'"))==count;assert query(work,env,f'SELECT COUNT(*) FROM bank_buy_invoice_claims WHERE pay_row_id={n}')=='0';cases.append(label+'_proof_uses_genuine_create_without_bank009')
        for label in ['used','revoked','expired']:
            n=seed('gift'+label,method='gift_card');gift(label,status='redeemed' if label=='used' else label);assert call(op='save',pay=n,code=label)['result']['status']=='saved';assert call(pay=n)['result']==label
            before=len(APIs.events);assert call(pay=n)['result']==label and len(APIs.events)==before;assert APIs.cards[label]['status']==('redeemed' if label=='used' else label);cases.append('gift_'+label+'_distinct_same_code_reads_state')
        n=seed('giftunknown',method='gift_card');assert call(op='save',pay=n,code='unknown')['result']['status']=='saved';r=call(pay=n);assert r['result']=='review',r
        # Real typed text evidence has an acknowledged current staff delivery and no fake photo.
        view=call(op='view',pay=n)['result'];assert view['keys']['inline_keyboard'][0][0]['text']=='بررسی پرداخت' and 'تصویر' not in view['text'];cases.append('gift_inflight_review_status_has_no_fresh_code_invitation')
        assert state(n)=='have_sent' and query(work,env,f'SELECT evidence_code IS NOT NULL AND evidence_review_sent_at IS NOT NULL FROM payment_attempts WHERE pay_row_id={n}')=='1';assert 'receipt_file_id' not in query(work,env,f'SELECT checkout_url FROM payment_attempts WHERE pay_row_id={n}');assert call(op='save',pay=n,code='different')['result']['status']=='locked';cases.append('unknown_code_encrypted_review_different_code_fenced')
        # The real issuer step accepts its initial code prompt without another button press.
        n=seed('directgiftinput',method='gift_card');key=query(work,env,f'SELECT hash_id FROM pays WHERE id={n}');r=run(work,env,app,human_driver,url,route='wallet',data='payIncreaseWalletWithGiftCard'+key,actor=1001)
        assert any(e.get('name')=='setUser' and e.get('step')=='apiGiftCode_'+key for e in r['events']),r;cases.append('actual_gift_issuer_binds_initial_code_prompt')
        for text,data in [('/start',''),('/start Fixture',''),('↩️ برگشت',''),('', 'mainMenu'),('', 'cancelIncreaseWalletReceipt'+key),('', 'unrelatedCustomerCallback')]:
            if text=='↩️ برگشت':text=subprocess.check_output(['php','-r',"require $argv[1];echo achbotMessages('legacy_buttons')['back_to_main'];",str(app/'messages/loader.php')],text=True)
            before=len(APIs.received);r=call(op='route',pay=n,step='apiGiftCode_'+key,text=text,data=data)
            assert r['result']==1 and len(APIs.received)==before and query(work,env,f'SELECT evidence_code IS NULL AND evidence_review_sent_at IS NULL FROM payment_attempts WHERE pay_row_id={n}')=='1',r
        cases.append('gift_navigation_start_back_cancel_and_callbacks_never_become_code')
        # The authenticated callback is acknowledged once BEFORE slow API HTTP.
        n=seed('slowbutton');key=query(work,env,f'SELECT hash_id FROM pays WHERE id={n}');APIs.mode='slow';offset=len(APIs.received);r=call(op='route',pay=n,data='apiCheck_'+key)
        ack=[e for e in r['events'] if e['event']=='ACK'];assert len(ack)==1 and APIs.received[offset:] and ack[0]['at']<APIs.received[offset],r;cases.append('callback_ack_precedes_slow_api_and_has_no_expired_second_answer')
        n=seed('deadline');start=time.monotonic();r=call(op='budget',pay=n,seconds=.15);elapsed=time.monotonic()-start;assert elapsed<.9 and r['result']['restored'] and r['result']['status']=='unavailable',(elapsed,r)
        APIs.mode='ok';due(n);offset=len(APIs.received);r=call(op='budget',pay=n,seconds=-1);assert r['result']['status']=='unavailable' and r['result']['restored'] and len(APIs.received)==offset,r;cases.append('monotonic_expired_and_remaining_api_budget_no_new_http_context_restored')
        return {'complete':True,'cases':len(cases),'scenarios':cases,'source_real_php':True,'socket_mariadb':True,'authenticated_loopback_requests':len(APIs.events),'live_requests':0,'migrations_executed':['001','003','006','007','008','009','014'],'installed_tested':False,'flow_head':subprocess.check_output(['git','rev-parse','HEAD'],cwd=ROOT,text=True).strip(),'evidence_head':subprocess.check_output(['git','rev-parse','HEAD'],cwd=evidence_root,text=True).strip(),'settings_head':subprocess.check_output(['git','rev-parse','HEAD'],cwd=settings_root,text=True).strip()}
    finally:srv.shutdown();srv.server_close();thread.join()

def main():
    p=argparse.ArgumentParser();p.add_argument('--run-disposable',action='store_true',required=True);p.add_argument('--evidence-root',type=Path,default=ROOT);p.add_argument('--settings-root',type=Path,default=ROOT);p.add_argument('--report',type=Path);a=p.parse_args();r=check(a.evidence_root,a.settings_root);raw=json.dumps(r,ensure_ascii=False);print(raw)
    if a.report:a.report.write_text(raw+'\n')
if __name__=='__main__':main()
