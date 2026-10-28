#!/usr/bin/env python3
"""Real source route tests with original-schema isolated MariaDB / owned HTTP.

Mixed legacy-panel catalogue, direct forged plan callbacks and a current
Marzban subscription QR use only synthetic information. No live credentials.
"""
import argparse
import json
import hashlib
import itertools
from pathlib import Path
import test_access_state as owned
import test_v6_native as native
import test_v8_native as purchase
import test_post_v9_purchases as src

ROOT=Path(__file__).resolve().parents[1]
REQUEST_SOURCES=itertools.count(810001)

def bootstrap_current_catalogue(stage: Path) -> None:
    """Supply real current bootstrap dependencies to the owned V7-derived driver.

    Production config.php loads PaymentLifecycle before these routes. The
    frozen V7 driver is intentionally unchanged and knows no such dependency.
    This generated candidate copy derives its private callback actor through
    the real UpdateBindings include before the existing persisted-user query.
    """
    driver=stage/'native-purchase.php'
    source=driver.read_text()
    request_anchor='$linkIndices=[0];$hideConfigIfMany=false;$permissions=[];$update=[];'
    if source.count(request_anchor)!=1:
        raise RuntimeError('Current catalogue request fixture anchor changed')
    request_context=r"""
$sender=['id'=>$from_id,'is_bot'=>false,'username'=>'fixture','first_name'=>'Fixture'];
$update=json_decode(json_encode(['update_id'=>(int)($spec['source']??810001),
    'callback_query'=>['id'=>'current-catalogue-fixture','data'=>$data,'from'=>$sender,
        'message'=>['message_id'=>$message_id,'text'=>'fixture bot message',
            'from'=>['id'=>777001,'is_bot'=>true],
            'chat'=>['id'=>$from_id,'type'=>'private'],
            'reply_markup'=>['inline_keyboard'=>[]]]]]));
require __DIR__.'/src/Telegram/UpdateBindings.php';
"""
    source=source.replace(request_anchor,request_anchor+request_context,1)
    route_anchor="try {require __DIR__.'/access-sequence.php';$ach_return=true;}"
    if source.count(route_anchor)!=1:
        raise RuntimeError('Current catalogue dispatch fixture anchor changed')
    context=r"""
// Actual current helper; no audience approval spy or duplicate implementation.
require_once __DIR__.'/src/Finance/PaymentLifecycle.php';
$actualRole=$userInfo['is_agent']??null;
if (!empty($spec['fake_cached_agent'])) $userInfo['is_agent']=1;
if (isset($spec['states'])) $botState=array_replace($botState,$spec['states']);
achEvent('current_catalogue_context',[
    'actor'=>(int)$from_id,'chat'=>(int)$chat_id,'private'=>($chat_type??'')==='private',
    'source'=>(int)$update->update_id,'db_is_agent'=>$actualRole,
    'cached_is_agent'=>$userInfo['is_agent']??null
]);
"""
    source=source.replace(route_anchor,context+route_anchor,1)
    driver.write_text(source)

def check_no_effects(work,env,panel,stage,db,kind,spec,tag):
    before=purchase.snapshot(work,env,db)
    panel.trace.clear()
    spec=dict(spec,source=next(REQUEST_SOURCES))
    result=purchase.run(work,env,stage,db,kind,spec,modern=True)
    after=purchase.snapshot(work,env,db)
    if panel.trace:
        raise RuntimeError('Unexpected panel API on '+tag)
    if result['exception'] or result['warnings']:
        raise RuntimeError('PHP warnings/errors on '+tag+':'+repr(result)[:600])
    contexts=[event['args'] for event in result['events']
              if event['name']=='current_catalogue_context']
    if (len(contexts)!=1 or contexts[0]['actor']!=spec.get('actor',1001) or
        contexts[0]['chat']!=spec.get('actor',1001) or
        contexts[0]['private'] is not True or contexts[0]['source']!=spec['source']):
        raise RuntimeError('Current private callback context not exercised: '+tag)
    for key in ('users','payments','orders','capacity','plans','categories'):
        if before[key]!=after[key]:
            raise RuntimeError('Unsupported or display-only request changed '+key+' on '+tag)
    return result

def main():
    p=argparse.ArgumentParser()
    p.add_argument('--run-disposable',action='store_true',required=True)
    p.add_argument('--report',type=Path)
    a=p.parse_args()
    records=[]
    with owned.owned_database() as (work,env), native.owned_provider() as (panel,url):
        stage=work/'post-v9'
        frozen_driver=(ROOT/'tests/v7/native-purchase.php').read_bytes()
        src.stage_source(stage,True)
        bootstrap_current_catalogue(stage)
        db=purchase.SIDE[1]
        purchase.init(work,env,url)
        # Copy synthetically another server/config/plan. No deployed records.
        native.sql(work,env,'''
INSERT INTO server_info (id,title,ucount,remark,flag,active,state)
VALUES (3,'Retired synthetic server',10,'obsolete','',1,1);
INSERT INTO server_plans (id,fileid,catid,server_id,inbound_id,acount,
 title,protocol,days,volume,type,price,descr,pic,active,step,date,custom_sni)
SELECT 9,'9',catid,3,0,6,'Obsolete paid','vless',30,2,'normal',
 100,'synthetic','',1,0,'now',custom_sni FROM server_plans WHERE id=7;
INSERT INTO server_plans (id,fileid,catid,server_id,inbound_id,acount,
 title,protocol,days,volume,type,price,descr,pic,active,step,date,custom_sni)
SELECT 10,'10',catid,2,7,6,'Obsolete inbound','vless',30,2,'normal',
 100,'synthetic','',1,0,'now',custom_sni FROM server_plans WHERE id=7;
''',db)
        controls=[
            ('entryandbulk',dict(data='buySubscription',actor=1001),'mixed_server_catalogue'),
            ('catalogue',dict(data='selectServer3_none',actor=1001),'retired_server_category'),
            ('catalogue',dict(data='selectCategory1_3_none',actor=1001),'retired_server_plan'),
            ('catalogue',dict(data='selectCategory1_2_none',actor=1001),'marzban_only_plan_list'),
            ('selectplan',dict(data='selectPlan9_1_none',actor=1001),'retired_forged_purchase'),
            ('selectplan',dict(data='selectPlan10_1_none',actor=1001),'inbound_forged_purchase'),
            ('catalogue',dict(data='selectCustomePlan9_1_none',actor=1001),'retired_forged_custom'),
            ('trialcatalogue',dict(data='getTestAccount',actor=1001),'filtered_free_trial'),
            ('trialprovision',dict(data='freeTrial9_normal',actor=1001),'retired_forged_trial'),
            ('entryandbulk',dict(data='createAccPlan9',actor=9001),'admin_forged_bulk'),
        ]
        for kind,spec,tag in controls:
            obs=check_no_effects(work,env,panel,stage,db,kind,spec,tag)
            output=json.dumps(obs['events'],ensure_ascii=False)
            if tag=='mixed_server_catalogue':
                if 'selectServer2_' not in output or 'selectServer3_' in output:
                    raise RuntimeError('Legacy server appears in user catalogue')
            if tag=='marzban_only_plan_list':
                if 'selectPlan7_' not in output or 'selectPlan10_' in output:
                    raise RuntimeError('Legacy inbound plan appears in paid list')
            if tag=='filtered_free_trial' and 'freeTrial8_' not in output:
                raise RuntimeError('Valid Marzban free trial hidden')
            if tag in ('retired_forged_purchase','inbound_forged_purchase',
                       'retired_forged_trial','admin_forged_bulk'):
                if not any(event['name']=='alert' for event in obs['events']):
                    raise RuntimeError('Forged callback not given bounded message: '+tag)
            records.append({'case':tag,'pass':True,'sql_payment_order_mutations':0,'provider_calls':0})
        # Real audience eligibility uses the actor currently stored in MariaDB.
        # Bank transfer is the only enabled/configured option in these cases.
        state={'walletState':'off','cartToCartState':'on','giftCardState':'off',
               'manualUsdtState':'off','nowPaymentOther':'off','zarinpal':'off',
               'nextpay':'off','gatewayAudience':{'bank_transfer':'agents'}}
        for actor,expected,tag,spoof in [
            (1001,False,'audience_nonagent_denied',False),
            (9002,True,'audience_persisted_agent_admitted',False),
            (1001,False,'audience_forged_cached_agent_denied',True)]:
            obs=check_no_effects(work,env,panel,stage,db,'entryandbulk',
                dict(data='buySubscription',actor=actor,states=state,fake_cached_agent=spoof),tag)
            shown=json.dumps(obs['events'],ensure_ascii=False)
            if ('selectServer2_' in shown)!=expected:
                raise RuntimeError('Real audience helper did not enforce persisted role: '+tag)
            if not expected and not any(e['name']=='alert' for e in obs['events']):
                raise RuntimeError('Audience denial had no bounded feedback: '+tag)
            context=next(e['args'] for e in obs['events'] if e['name']=='current_catalogue_context')
            if str(context['db_is_agent'])!=('1' if actor==9002 else '0'):
                raise RuntimeError('Persisted role was replaced by fixture approval: '+tag)
            if spoof and str(context['cached_is_agent'])!='1':
                raise RuntimeError('Stale cached role negative was not exercised')
            records.append({'case':tag,'pass':True,'sql_payment_order_mutations':0,'provider_calls':0})
        native.sql(work,env,'UPDATE users SET is_agent=0 WHERE userid=9002',db)
        obs=check_no_effects(work,env,panel,stage,db,'entryandbulk',
            dict(data='buySubscription',actor=9002,states=state,fake_cached_agent=True),
            'audience_revoked_agent_denied')
        if 'selectServer2_' in json.dumps(obs['events'],ensure_ascii=False):
            raise RuntimeError('Revoked current role retained catalogue access')
        records.append({'case':'audience_revoked_agent_denied','pass':True,
                        'sql_payment_order_mutations':0,'provider_calls':0})
        native.sql(work,env,'UPDATE users SET is_agent=1 WHERE userid=9002',db)
        if (ROOT/'tests/v7/native-purchase.php').read_bytes()!=frozen_driver:
            raise RuntimeError('Frozen V7 native driver was modified')

        # Build one valid owned order and load the actual Checkout PHP route.
        native.sql(work,env,'''INSERT INTO orders_list
            (userid,token,transid,fileid,server_id,inbound_id,remark,uuid,
             protocol,expire_date,link,amount,status,date)
            VALUES (1001,'safe-qr-token','',7,2,0,'safe-qr','id','vless',
                    2000000000,'[]',100,1,'synthetic')''',db)
        oid=int(native.sql(work,env,"SELECT id FROM orders_list WHERE remark='safe-qr'",db).strip())
        # The QR-code encoder is a local spy; no network or actual customer media.
        qrlib=stage/'phpqrcode/qrlib.php'
        qrlib.write_text('''<?php
class QRcode {
  public static function png($payload,$file,$ecc,$scale,$margin) {
    file_put_contents(dirname(__DIR__).'/owned-qr-payload.txt',$payload);
    file_put_contents($file,'owned-synthetic-png-placeholder');
  }
}
''')
        old=purchase.block
        try:
            purchase.block=lambda kind,modern: (
                b"<?php\nif ((require __DIR__ . '/src/Finance/Routes/Checkout.php') === null) return;\n"
                if kind=='qrcheckout' else old(kind,modern)
            )
            report=check_no_effects(work,env,panel,stage,db,'qrcheckout',
                                     dict(data='showQrSub'+str(oid),actor=1001),'marzban_qr')
        finally:
            purchase.block=old
        value=(stage/'owned-qr-payload.txt').read_text()
        if value != url+'/sub/safe-qr-token' and value != 'http://127.0.0.1:'+url.rsplit(':',1)[-1]+'/sub/safe-qr-token':
            # Native seed stores the URL returned by the loopback server.
            stored=native.sql(work,env,"SELECT panel_url FROM server_config WHERE id=2",db).strip()
            if value != stored.rstrip('/')+'/sub/safe-qr-token':
                raise RuntimeError('QR sent wrong URL: '+repr((value,stored)))
        if 'settings/subLink.php' in value:
            raise RuntimeError('Live Marzban QR still points at retired endpoint')
        records.append({'case':'marzban_qr','pass':True,'correct_provider_url':True,
                        'sql_payment_order_mutations':0,'provider_calls':0})
    report={'complete':True,'cases':len(records),'original_schema_tables':23,
            'financial_mutations':0,'real_current_audience_helper':True,'actual_private_update_bindings':True,
            'persisted_audience_role_cases':4,'frozen_driver_unchanged':True,
            'frozen_driver_sha256':hashlib.sha256(frozen_driver).hexdigest(),
            'real_payment_or_telegram_tested':False,
            'scenarios':records}
    if a.report:a.report.write_text(json.dumps(report,indent=2)+'\n')
    print(json.dumps({k:v for k,v in report.items() if k!='scenarios'}))
if __name__=='__main__':main()
