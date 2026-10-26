#!/usr/bin/env python3
"""Source-real per-method/category dynamic eligibility and Telegram editor.

Own disposable socket-only MariaDB, real PHP + original indexed 23-table schema,
actual config.php writer and bot.php route extraction, no provider/Telegram calls.
"""
from __future__ import annotations
import argparse
import json
import subprocess
import time
from pathlib import Path
import test_access_state as owned
import test_post_v9_checkout_serialization as dbops
import test_post_v9_gateway_admin_source as gateway_source

ROOT=Path(__file__).resolve().parents[1]
DB=gateway_source.DB
EVAL=r'''<?php
error_reporting(E_ALL & ~E_DEPRECATED);
set_error_handler(static function($level,$message){
    if ($level===E_DEPRECATED) return true;
    throw new ErrorException($message,0,$level);
});
$_SERVER['SCRIPT_FILENAME']=__FILE__;
$spec=json_decode($argv[4],true,512,JSON_THROW_ON_ERROR);
$connection=new mysqli('localhost','root','',$argv[3],0,$argv[2]);
$connection->set_charset('utf8mb4');
$botState=$spec['snapshot']??[];
require $argv[1].'/src/Finance/PaymentLifecycle.php';
if (!empty($spec['history_boundary'])) {
    $result=achbotGatewayRuleHistoryCount(
       $connection,(int)$spec['actor'],'buy',90,
       (string)$spec['direction'],false,(int)$spec['clock']);
    echo json_encode(['count'=>$result],JSON_THROW_ON_ERROR);
    exit;
}
if (!empty($spec['isolation']) && $spec['isolation']==='READ-COMMITTED')
    $connection->query('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
$allowed=false;
try {
    if ($spec['final']) {
        if (!$connection->begin_transaction()) throw new RuntimeException('begin');
        achbotPayUxLockDraftOwner($connection,(int)$spec['actor']);
    }
    $allowed=achbotPayUxAudienceAllowed($connection,
        $spec['method'],(int)$spec['actor'],(bool)$spec['final'],$spec['subject']);
} catch (Throwable $e) { $allowed=false; }
if ($spec['final']) $connection->rollback();
echo json_encode(['allowed'=>$allowed],JSON_THROW_ON_ERROR);
'''
ADMIN=r'''<?php
error_reporting(E_ALL & ~E_DEPRECATED);
set_error_handler(static function($level,$message){
    if ($level===E_DEPRECATED) return true;
    throw new ErrorException($message,0,$level);
});
$_SERVER['SCRIPT_FILENAME']=__FILE__;
$_SERVER['REQUEST_METHOD']='POST';
$_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']='SafeSyntheticGatewayToken_2026';
putenv('ACHBOT_TELEGRAM_WEBHOOK_SECRET=SafeSyntheticGatewayToken_2026');
$root=$argv[1];$spec=json_decode($argv[4],true,512,JSON_THROW_ON_ERROR);
$connection=new mysqli('localhost','root','',$argv[3],0,$argv[2]);
$connection->set_charset('utf8mb4');
$from_id=(int)$spec['actor'];$admin=9001;$chat_id=$from_id;
$message_id=(int)($spec['message_id']??75);
$data=(string)($spec['data']??'');$text=(string)($spec['text']??'');
$botState=$spec['snapshot']??[];
$buttonValues=['cancel'=>'لغو'];$cancelKey='{}';
$mainValues=['change_bot_settings_message'=>'Fixture settings'];
$callbackId='fixture-callback';
$stmt=$connection->prepare('SELECT * FROM users WHERE userid=? LIMIT 1');
$stmt->bind_param('i',$from_id);$stmt->execute();
$userInfo=$stmt->get_result()->fetch_assoc();$stmt->close();
if (!$userInfo) throw new RuntimeException('fixture_actor_missing');
$source=['id'=>$from_id,'is_bot'=>false];
$chat=['id'=>$from_id,'type'=>'private'];
if (!empty($spec['invalid']) && $spec['invalid']==='group') $chat['type']='group';
if (!empty($spec['invalid']) && $spec['invalid']==='token')
    unset($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']);
if (!empty($spec['invalid']) && $spec['invalid']==='chat') $chat['id']++;
if (!empty($spec['invalid']) && $spec['invalid']==='sender') $source['id']++;
if (!empty($spec['invalid']) && $spec['invalid']==='other_message')
    $message_id++;
$update=(object)['update_id'=>(int)$spec['update_id']];
if (!empty($spec['message'])) {
    $update->message=(object)[
        'message_id'=>$message_id,'from'=>(object)$source,
        'chat'=>(object)$chat,'text'=>$text];
} else {
    $update->callback_query=(object)[
        'id'=>'callback-'.$spec['update_id'],'from'=>(object)$source,
        'message'=>(object)['message_id'=>$message_id,'chat'=>(object)$chat],
        'data'=>$data];
}
$GLOBALS['events']=[];
function editText(...$a){$GLOBALS['events'][]=['edit',$a[1]??'',$a[2]??''];}
function sendMessage(...$a){$GLOBALS['events'][]=['send',$a[0]??'',$a[1]??''];}
function answerCallbackQuery(...$a){$GLOBALS['events'][]=['deny',$a[1]??''];}
function getGateWaysKeys(){return '{}';}
function achbotGatewayAudienceKeyboard(){return '{}';}
require $root.'/src/Finance/PaymentLifecycle.php';
require $root.'/src/Finance/GatewayRuleAdmin.php';
require $root.'/src/Subscription/DirectGift.php';
require $argv[7]; // extracted real ingress-auth function
require $argv[5]; // extracted real source settings writer
require $argv[6]; // extracted real bot.php callbacks, including wizard
echo json_encode(['events'=>$GLOBALS['events']],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
'''

def sql(w,e,q):return dbops.sql(w,e,q)
def quoted(s):return "'" + s.replace("'", "''") + "'"

def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('--run-disposable',action='store_true',required=True)
    parser.add_argument('--report',type=Path)
    a=parser.parse_args()
    cases=[]
    now=int(time.time())
    with owned.owned_database() as (w,e):
        initial={'walletState':'on','nowPaymentOther':'on','nowPaymentWallet':'on',
                 'zarinpal':'on','nextpay':'on','cartToCartState':'on',
                 'giftCardState':'on','manualUsdtState':'on','gatewayAudience':{}}
        def state():return json.loads(sql(w,e,"SELECT value FROM setting WHERE type='BOT_STATES' LIMIT 1"))
        def store(data):
            sql(w,e,"DELETE FROM setting WHERE type IN ('BOT_STATES','ACHBOT_ADMIN_GATEWAY_SOURCE')")
            sql(w,e,"INSERT INTO setting(type,value) VALUES ('BOT_STATES',"+
                quoted(json.dumps(data,ensure_ascii=False,separators=(',',':')))+")")
        store(initial)
        # Native schema fixture has user1001 (non-agent), 9001 primary, 9002 delegate.
        sql(w,e,f"UPDATE users SET date='{now-140*86400}',is_agent=0 WHERE userid=1001")
        sql(w,e,f"INSERT INTO users (userid,name,username,refcode,wallet,date,is_agent) "
            f"VALUES (1002,'Agent','agent','0',0,'{now-160*86400}',1)")
        kinds=['BUY_SUB','RENEW_ACCOUNT','RENEW_SCONFIG',
            'INCREASE_VOLUME_20','INCREASE_DAY_10','INCREASE_WALLET']
        for i,kind in enumerate(kinds):
            for j in range(2):
                when=now-((110 if j==0 else 9)*86400)-50
                sql(w,e,f"INSERT INTO pays(hash_id,user_id,type,plan_id,server_id,"
                    f"volume,day,price,request_date,state) "
                    f"VALUES ('rules-{i}-{j}',1001,'{kind}',7,2,0,0,100,{when},"
                    f"'{['paid','approved','auto_cart_approved','paid_with_wallet'][i%4]}')")
        sql(w,e,f"INSERT INTO pays(hash_id,user_id,type,price,request_date,state)"
            f" VALUES('rules-pending',1001,'BUY_SUB',100,{now-120*86400},'pending'),"
            f"('rules-future',1001,'BUY_SUB',100,{now+100},'paid')")
        # An exactly equal cutoff satisfies both directions (inclusive).
        clock=now
        boundary=clock-90*86400
        sql(w,e,f"INSERT INTO users(userid,name,username,refcode,wallet,date) "
            f"VALUES(1200,'Boundary','boundary','0',0,'{clock-150*86400}')")
        sql(w,e,f"INSERT INTO pays(hash_id,user_id,type,price,request_date,state)"
            f" VALUES('rules-exact-cutoff',1200,'BUY_SUB',100,{boundary},'paid')")
        baseSnap=sql(w,e,'SELECT id,user_id,type,state,request_date FROM pays ORDER BY id')
        def php(src,spec):
            command=['php','-n','-d','extension=mysqlnd','-d','extension=mysqli',
                '-d','log_errors=1','-d',f'error_log={w}/rules-php.log',
                '-d','display_errors=0','-d','allow_url_fopen=0',
                '-d','disable_functions=exec,shell_exec,system,passthru,popen,proc_open,mail',
                str(src),str(ROOT),str(w/'mysql.sock'),DB,
                json.dumps(spec,ensure_ascii=False)]
            if src.name=='rules-admin.php':
                command += [str(w/'rules-config.php'),str(w/'rules-route.php'),
                            str(w/'rules-ingress.php')]
            proc=subprocess.run(command,cwd=w,env=e,capture_output=True,timeout=25)
            assert proc.returncode==0 and proc.stdout and not proc.stderr,(
                proc.returncode,proc.stdout[-500:],proc.stderr[-1500:])
            return json.loads(proc.stdout)
        eva=w/'rules-eval.php';eva.write_text(EVAL)
        admin=w/'rules-admin.php';admin.write_text(ADMIN)
        bot=(ROOT/'bot.php').read_bytes(); cfg=(ROOT/'config.php').read_bytes()
        extracted=gateway_source.extraction(cfg,bot,bot)
        for name,source in zip(['config','route','ingress'],extracted):
            (w/('rules-'+name+'.php')).write_bytes(source)
        # Unconfigured legacy behavior across all methods.
        for method in ['wallet','nowpayments','zarinpal','nextpay','bank_transfer',
                       'gift_card','manual_usdt','manual_trx']:
            for final in (False,True):
                r=php(eva,{'snapshot':initial,'method':method,'actor':1001,
                           'subject':'BUY_SUB','final':final})
                assert r['allowed'],(method,final,r)
            cases.append('legacy_'+method)
        for legacy in ['agents','non_agents','buyers','non_buyers',
                       'has_active_order','no_active_order','off','all']:
            s={**initial,'gatewayAudience':{'wallet':legacy}}
            store(s)
            allowed=php(eva,{'snapshot':s,'method':'wallet','actor':1001,
                             'subject':'BUY_SUB','final':True})['allowed']
            assert allowed == (legacy in ['non_agents','buyers','no_active_order','all']),legacy
            cases.append('legacy_category_'+legacy)
        def blank(cat):return {'category':cat,'enabled':True,'registration_age_days':0,
            'metrics':{name:{'count':0,'days':0,'direction':'older'}
              for name in ['buy','renew','volume','time','topup']}}
        def policy(method,rules,**extras):
            return {**initial,**extras,'gatewayRules':{'version':1,
                'methods':{method:rules}}}
        def allowed(s,method='wallet',who=1001,final=True,subject='BUY_SUB',
                    isolation=None,snapshot=None):
            store(s)
            return php(eva,{'snapshot':snapshot if snapshot is not None else s,
                'method':method,'actor':who,'final':final,'subject':subject,
                'isolation':isolation})['allowed']
        agent=blank('agents');regular=blank('non_agents')
        regular['registration_age_days']=90
        regular['metrics']['buy']['count']=2
        for isolation in ['READ-COMMITTED','REPEATABLE-READ']:
            agent['enabled']=True
            s=policy('wallet',[agent,regular])
            assert allowed(s,who=1001,isolation=isolation)
            assert allowed(s,who=1002,isolation=isolation)
            s['gatewayRules']['methods']['wallet'][0]['enabled']=False
            assert not allowed(s,who=1002,isolation=isolation)
            cases.append('or_and_categories_'+isolation)
        for category,expected in [
                ('all',True),('agents',False),('non_agents',True),
                ('buyers',True),('non_buyers',False),
                ('has_active_order',False),('no_active_order',True)]:
            assert allowed(policy('wallet',[blank(category)])) is expected,category
            cases.append('new_category_'+category)
        sql(w,e,("INSERT INTO orders_list "
            "(userid,token,transid,fileid,server_id,inbound_id,remark,uuid,"
            "protocol,expire_date,link,amount,status,date) "
            "VALUES(1001,'synthetic','',7,2,0,'rules-active-order',"
            "'rules-active-uuid','vless',2000000000,'[]',100,1,'synthetic')"))
        assert allowed(policy('wallet',[blank('has_active_order')]))
        assert not allowed(policy('wallet',[blank('no_active_order')]))
        sql(w,e,"DELETE FROM orders_list WHERE remark='rules-active-order'")
        cases.append('new_active_order_positive_and_inverse')
        for metric in ['buy','renew','volume','time','topup']:
            rule=blank('all');rule['metrics'][metric]['count']=2
            assert allowed(policy('wallet',[rule])),metric
            rule['metrics'][metric]['count']=5 if metric=='renew' else 3
            assert not allowed(policy('wallet',[rule])),metric
            rule['metrics'][metric]['count']=2
            rule['metrics'][metric]['days']=30
            rule['metrics'][metric]['count']=3
            rule['metrics'][metric]['direction']='recent'
            assert not allowed(policy('wallet',[rule])),metric
            rule['metrics'][metric]['count']=1
            assert allowed(policy('wallet',[rule])),metric
            rule['metrics'][metric]['direction']='older'
            assert allowed(policy('wallet',[rule])),metric
            rule['metrics'][metric]['count']=0
            assert allowed(policy('wallet',[rule])),metric
            rule['metrics'][metric]['days']=0
            assert allowed(policy('wallet',[rule])),metric
            cases.append('metric_dynamic_'+metric)
        age=blank('all');age['registration_age_days']=140
        assert allowed(policy('wallet',[age]))
        age['registration_age_days']=141
        assert not allowed(policy('wallet',[age]))
        age['registration_age_days']=0
        assert allowed(policy('wallet',[age]))
        cases.append('registration_age_rolling_days')
        s=policy('wallet',[])
        assert not allowed(s)
        cases.append('empty_rules_deny')
        s=policy('wallet',[blank('all')],walletState='off')
        assert not allowed(s)
        cases.append('off_gateway_deny')
        for method in ['nowpayments','zarinpal','nextpay','bank_transfer',
                       'gift_card','manual_usdt','manual_trx','wallet']:
            assert allowed(policy(method,[blank('all')]),method)
            assert not allowed(policy(method,[blank('agents')]),method)
            cases.append('rule_independent_method_'+method)
        assert not allowed(policy('nowpayments',[blank('all')]),
                           'nowpayments',subject=None)
        assert allowed(policy('nowpayments',[blank('all')]),
                       'nowpayments',subject='INCREASE_WALLET')
        cases.append('nowpayments_subject_split')
        for malformed_id,malformed in enumerate([
                {'version':2,'methods':{}},
                {'version':1,'methods':{'wallet':'all'}},
                {'version':1,'methods':{'wallet':[{'category':'all'}]}},
                {'version':1,'methods':{'wallet':[blank('all'),blank('all')]}},
                {'version':1,'methods':{'retired':[blank('all')]}},
        ]):
            s={**initial,'gatewayRules':malformed}
            assert not allowed(s),malformed
            cases.append('explicit_invalid_document_denies_'+str(malformed_id))
        s=policy('wallet',[blank('all')])
        s['gatewayRules']['methods']['wallet'][0]['metrics']['buy']['days']=-1
        assert not allowed(s)
        cases.append('negative_days_denied')
        for label,wrong in [('garbled','nope'),('future',str(now+100)),
                            ('negative','-1'),('zero','0')]:
            s=policy('wallet',[blank('all')])
            s['gatewayRules']['methods']['wallet'][0]['registration_age_days']=1
            sql(w,e,'UPDATE users SET date='+quoted(wrong)+' WHERE userid=1001')
            assert not allowed(s),wrong
            cases.append('bad_registration_'+label)
        sql(w,e,f"UPDATE users SET date='{now-140*86400}' WHERE userid=1001")
        s=policy('wallet',[blank('all')])
        sql(w,e,"INSERT INTO users(userid,name,username,refcode,wallet,date) "
            "VALUES (1001,'Duplicate','duplicate','0',0,'1700000000')")
        assert not allowed(s)
        sql(w,e,"DELETE FROM users WHERE username='duplicate'")
        cases.append('duplicate_customer_rejected')
        # Exact cutoff inclusive on both sides; +/- one second matters.
        for offset,older,recent in [(0,1,1),(-1,1,0),(1,0,1)]:
            sql(w,e,f"UPDATE pays SET request_date={boundary+offset} "
                "WHERE hash_id='rules-exact-cutoff'")
            for direction,wanted in [('older',older),('recent',recent)]:
                got=php(eva,{'history_boundary':True,'actor':1200,'clock':clock,
                             'direction':direction})['count']
                assert got==wanted,(offset,direction,got,wanted)
        sql(w,e,f"UPDATE pays SET request_date={boundary} WHERE hash_id='rules-exact-cutoff'")
        cases.append('inclusive_exact_cutoff_plus_minus_one_second')
        # Dated windows never count future successes, nor pending records.
        r=blank('all');r['metrics']['buy']['count']=2
        r['metrics']['buy']['days']=5;r['metrics']['buy']['direction']='recent'
        assert not allowed(policy('wallet',[r]))
        cases.append('future_timestamp_not_recent_success')
        # All-history and dated windows are request-time based; the 50-second
        # fixture offset makes transitions stable across test startup seconds.
        r=blank('all');r['metrics']['buy']['count']=0
        r['metrics']['buy']['days']=110;r['metrics']['buy']['direction']='older'
        assert allowed(policy('wallet',[r]))
        r['metrics']['buy']['days']=111
        assert not allowed(policy('wallet',[r]))
        r['metrics']['buy']['days']=10;r['metrics']['buy']['direction']='recent'
        assert allowed(policy('wallet',[r]))
        r['metrics']['buy']['days']=9
        assert not allowed(policy('wallet',[r]))
        cases.append('rolling_window_9_10_110_111_date_only_boundaries')
        s=policy('wallet',[blank('all')])
        s['gatewayRules']['methods']['wallet'][0]['metrics']['buy']['count']=2
        assert allowed(s)
        s['gatewayRules']['methods']['wallet'][0]['metrics']['buy']['days']=0
        assert allowed(s)
        s['gatewayRules']['methods']['wallet'][0]['metrics']['buy']['count']=0
        assert allowed(s)
        cases.append('all_history_count_vs_disabled_zero_zero')
        # A rule on another method must not alter a legacy-off wallet policy.
        s=policy('zarinpal',[blank('all')],gatewayAudience={'wallet':'off'})
        assert not allowed(s,method='wallet')
        cases.append('method_omission_retains_legacy_explicit_off')
        s=policy('wallet',[blank('non_agents')],gatewayAudience={'wallet':'off'})
        assert allowed(s)
        cases.append('intentional_explicit_new_rule_overrides_legacy_off')
        s=policy('nowpayments',[blank('all')],nowPaymentWallet='off')
        assert not allowed(s,'nowpayments',subject='INCREASE_WALLET')
        assert allowed(s,'nowpayments',subject='BUY_SUB')
        cases.append('nowpayments_topup_flag_independent')
        # Rest of operator suite uses original source route and writer.
        store(initial)
        sequence=[1000]
        def act(data='',text='',who=9001,message=False,invalid=None,
                seq=None,snapshot=None):
            if seq is None:sequence[0]+=1;seq=sequence[0]
            else:sequence[0]=max(sequence[0],seq)
            spec={'actor':who,'data':data,'text':text,'message':message,
                  'update_id':seq,'snapshot':snapshot if snapshot is not None else state(),
                  'invalid':invalid}
            return php(admin,spec)['events'],seq
        events,_=act('gr|l')
        assert any(x[0]=='edit' and 'روش‌های پرداخت' in x[1] for x in events)
        events,_=act('gr|r|wallet|non_agents')
        assert any(x[0]=='edit' and 'ذخیره' in str(x) for x in events)
        assert 'gatewayRules' not in state()
        cases.append('operator_preview_does_not_persist')
        events,_=act('gr|a|wallet|non_agents')
        assert state()['gatewayRules']['methods']['wallet'][0]['category']=='non_agents'
        cases.append('operator_explicit_add')
        events,_=act('gr|n|wallet|non_agents|buy|count')
        assert sql(w,e,'SELECT step FROM users WHERE userid=9001').startswith('gr|edit|')
        assert any(x[0]=='send' for x in events)
        events,nonce=act(text='13',message=True)
        assert '|13|'+str(nonce)+'|0' in sql(w,e,'SELECT step FROM users WHERE userid=9001')
        assert state()['gatewayRules']['methods']['wallet'][0]['metrics']['buy']['count']==0
        events,_=act('gr|s|'+str(nonce))
        assert state()['gatewayRules']['methods']['wallet'][0]['metrics']['buy']['count']==13
        assert sql(w,e,'SELECT step FROM users WHERE userid=9001')=='none'
        cases.append('operator_private_integer_confirm_commit')
        events,_=act('gr|n|wallet|non_agents|renew|days')
        events,_=act(text='bad',message=True)
        assert state()['gatewayRules']['methods']['wallet'][0]['metrics']['renew']['days']==0
        assert any(x[0]=='send' and 'معتبر' in x[1] for x in events)
        events,_=act(text='250',message=True)
        assert state()['gatewayRules']['methods']['wallet'][0]['metrics']['renew']['days']==0
        token=int(sql(w,e,'SELECT step FROM users WHERE userid=9001').split('|')[-1])
        events,_=act('gr|x|'+str(token))
        assert state()['gatewayRules']['methods']['wallet'][0]['metrics']['renew']['days']==0
        cases.append('operator_invalid_and_cancel')
        events,_=act('gr|a|wallet|agents',who=9002)
        assert len(state()['gatewayRules']['methods']['wallet'])==2
        events,_=act('gr|v|wallet|non_agents|buy',who=9001)
        assert state()['gatewayRules']['methods']['wallet'][0]['metrics']['buy']['direction']=='recent'
        events,_=act('gr|t|wallet|agents',who=9002)
        assert not state()['gatewayRules']['methods']['wallet'][1]['enabled']
        cases.append('two_admins_independent_fields')
        current=json.dumps(state(),sort_keys=True)
        events,_=act('gr|c|wallet|non_agents|buy',invalid='token')
        assert json.dumps(state(),sort_keys=True)==current
        events,_=act('gr|d|wallet|agents',who=9002,invalid='group')
        assert json.dumps(state(),sort_keys=True)==current
        events,_=act('gr|t|wallet|agents',who=9002,invalid='sender')
        assert json.dumps(state(),sort_keys=True)==current
        cases.append('unauthenticated_group_sender_rejected')
        # Stale callback retries and revoked admins do not mutate.
        events,_=act('gr|t|wallet|agents',who=9002,seq=sequence[0]-3)
        assert json.dumps(state(),sort_keys=True)==current
        sql(w,e,"UPDATE users SET isAdmin=0 WHERE userid=9002")
        events,_=act('gr|d|wallet|agents',who=9002)
        assert json.dumps(state(),sort_keys=True)==current
        cases.append('stale_or_revoked_admin_rejected')
        # Numeric editor rejects arbitrary overflow and invalid negative.
        events,_=act('gr|n|wallet|non_agents|time|days')
        events,_=act(text='9223372036854775808',message=True)
        assert sql(w,e,'SELECT step FROM users WHERE userid=9001').startswith('gr|edit|')
        assert any(x[0]=='send' and 'معتبر' in x[1] for x in events)
        events,_=act(text='-2',message=True)
        assert sql(w,e,'SELECT step FROM users WHERE userid=9001').startswith('gr|edit|')
        events,nonce=act(text='17',message=True)
        events,_=act('gr|s|'+str(nonce))
        assert state()['gatewayRules']['methods']['wallet'][0]['metrics']['time']['days']==17
        cases.append('operator_arbitrary_17_and_overflow_rejected')
        events,_=act('gr|n|wallet|non_agents|topup|count')
        events,nonce=act(text='2147483648',message=True)
        events,_=act('gr|s|'+str(nonce))
        assert state()['gatewayRules']['methods']['wallet'][0]['metrics']['topup']['count']==2147483648
        cases.append('operator_64_bit_integer_not_32_bit_preset')
        sql(w,e,"UPDATE users SET isAdmin=1 WHERE userid=9002")
        events,_=act('gr|n|wallet|non_agents|buy|count',who=9001)
        events,first_nonce=act(text='23',message=True,who=9001)
        events,_=act('gr|n|wallet|non_agents|buy|count',who=9002)
        events,second_nonce=act(text='33',message=True,who=9002)
        events,_=act('gr|s|'+str(second_nonce),who=9002)
        assert state()['gatewayRules']['methods']['wallet'][0]['metrics']['buy']['count']==33
        events,_=act('gr|s|'+str(first_nonce),who=9001)
        assert state()['gatewayRules']['methods']['wallet'][0]['metrics']['buy']['count']==33
        assert sql(w,e,'SELECT step FROM users WHERE userid=9001').startswith('gr|confirm|')
        events,_=act('gr|x|'+str(first_nonce),who=9001)
        assert sql(w,e,'SELECT step FROM users WHERE userid=9001')=='none'
        cases.append('two_admin_same_field_stale_editor_cas_rejected')
        events,_=act('gr|n|wallet|non_agents|volume|count',who=9001)
        events,first_nonce=act(text='12',message=True,who=9001)
        events,_=act('gr|n|wallet|non_agents|topup|days',who=9002)
        events,second_nonce=act(text='20',message=True,who=9002)
        events,_=act('gr|s|'+str(second_nonce),who=9002)
        events,_=act('gr|s|'+str(first_nonce),who=9001)
        metrics=state()['gatewayRules']['methods']['wallet'][0]['metrics']
        assert metrics['volume']['count']==12 and metrics['topup']['days']==20
        cases.append('two_admin_distinct_field_interleaving_both_persist')
        # Delete requires an authenticated nonce-bearing two-step confirmation.
        snapshot=json.dumps(state(),sort_keys=True)
        events,_=act('gr|d|wallet|agents|123')
        assert json.dumps(state(),sort_keys=True)==snapshot
        events,nonce=act('gr|q|wallet|agents')
        assert sql(w,e,'SELECT step FROM users WHERE userid=9001').startswith('gr|delete|wallet|agents|'+str(nonce)+'|')
        assert any(x[0]=='edit' and ('gr|d|wallet|agents|'+str(nonce)) in x[2] for x in events)
        assert json.dumps(state(),sort_keys=True)==snapshot
        events,_=act('gr|x|'+str(nonce))
        assert json.dumps(state(),sort_keys=True)==snapshot
        assert sql(w,e,'SELECT step FROM users WHERE userid=9001')=='none'
        cases.append('operator_delete_requires_confirmation_cancel_safe')
        events,nonce=act('gr|q|wallet|agents')
        events,_=act('gr|t|wallet|agents',who=9002)
        before_delete=json.dumps(state(),sort_keys=True)
        events,_=act('gr|d|wallet|agents|'+str(nonce))
        assert json.dumps(state(),sort_keys=True)==before_delete
        assert sql(w,e,'SELECT step FROM users WHERE userid=9001').startswith('gr|delete|')
        events,_=act('gr|x|'+str(nonce))
        events,_=act('gr|t|wallet|agents',who=9002)
        cases.append('delete_fingerprint_conflict_with_other_admin')
        events,nonce=act('gr|q|wallet|agents')
        events,_=act('gr|d|wallet|agents|'+str(nonce))
        assert not any(x['category']=='agents'
                       for x in state()['gatewayRules']['methods']['wallet'])
        assert sql(w,e,'SELECT step FROM users WHERE userid=9001')=='none'
        events,_=act('gr|d|wallet|agents|'+str(nonce))
        assert not any(x['category']=='agents'
                       for x in state()['gatewayRules']['methods']['wallet'])
        cases.append('operator_confirmed_delete_single_use')
        assert sql(w,e,'SELECT id,user_id,type,state,request_date FROM pays ORDER BY id')==baseSnap
        cases.append('no_financial_side_effects')
    result={'complete':True,'cases':len(cases),'scenarios':cases,
            'source_real':True,'owned_original_mariadb':True,
            'php_runtime':True,'payment_effects':0,'telegram_calls':0,
            'provider_effects':0}
    if a.report:a.report.write_text(json.dumps(result,ensure_ascii=False,indent=2)+'\n')
    print(json.dumps(result,ensure_ascii=False))
if __name__=='__main__':main()
