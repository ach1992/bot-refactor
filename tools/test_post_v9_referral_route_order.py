#!/usr/bin/env python3
"""Real manual and custom receipt handlers, post-commit referral journal ordering.

Exercise current PHP against owned socket-only MariaDB, with ONLY Telegram and
Marzban transport stubs. No installed order, financial service or merchant.
"""
from __future__ import annotations
import argparse,json
from pathlib import Path

import test_access_state as owned
import test_v8_native as purchase
import test_post_v9_manual_payments as manual

ROOT=Path(__file__).resolve().parents[1]
DB=purchase.SIDE[1]

# Reuse the current payment-route fixture's REAL application PHP and actual
# MySQLi writes; extend only the synthetic Telegram boundary and include the
# real reward module exactly as the production src/Purchase/Core.php does.
OLD_SEND="""function sendMessage(...$args){
    global $spec;
    $GLOBALS['events'][]=['name'=>'sendMessage','args'=>$args];"""
NEW_SEND="""function sendMessage(...$args){
    global $spec,$connection;
    $GLOBALS['events'][]=['name'=>'sendMessage','args'=>$args];
    if (($spec['message_transport']??'')==='postreferral' && ($args[3]??null)===null) {
        // Throw only after the buyer's payment award is durably CONFIRMED;
        // do not fault referral's own inviter/admin notices.
        $result=$connection->query("SELECT COUNT(*) AS n FROM referral_awards
            WHERE buyer_userid=1001 AND state='CONFIRMED'");
        if ($result && (int)$result->fetch_assoc()['n']>0)
            throw new RuntimeException('fixture_postreferral_customer_message_failed');
    }"""
assert OLD_SEND in manual.PHP_ROUTE
PHP_ROUTE=manual.PHP_ROUTE.replace(OLD_SEND,NEW_SEND,1)
assert "require_once $root.'/src/Finance/ReferralReward.php';" in PHP_ROUTE


def query(work,env,sql):
    return manual.query(work,env,sql)


def initial(work,env):
    purchase.init(work,env,'http://127.0.0.1:9')
    query(work,env,"INSERT INTO setting(type,value) VALUES "
        "('BOT_STATES','{\"cartToCartState\":\"on\",\"giftCardState\":\"on\"}'),"
        "('INVITE_BANNER_AMOUNT','1000')")
    query(work,env,(ROOT/'migrations/post-v9/001_marzban_panel_group_id.sql').read_text())
    query(work,env,"INSERT INTO server_info (id,title,ucount,remark,flag,active,state) "
        "VALUES (1,'Synthetic service',100,'fixture','',1,1)")
    query(work,env,"INSERT INTO server_plans "
        "(id,fileid,catid,server_id,inbound_id,acount,title,protocol,days,volume,type,"
        "price,descr,pic,active,step,date,custom_sni) "
        "VALUES (1,'1',1,1,0,100,'Synthetic service','vless',30,10,'normal',100,"
        "'fixture','',1,0,'fixture','{}')")
    query(work,env,"INSERT INTO server_config "
        "(id,panel_url,ip,sni,header_type,request_header,response_header,security,tlsSettings,"
        "type,username,password,sub_links) "
        "VALUES (1,'http://127.0.0.1:9','127.0.0.1','','none','','','none','{}',"
        "'marzban','fixture-admin','fixture-password','')")
    for migration in ('003_payment_attempts.sql','007_service_effects.sql',
                      '008_referral_awards.sql',
                      '009_bank_buy_invoice_claims.sql'):
        query(work,env,(ROOT/'migrations/post-v9'/migration).read_text())
    query(work,env,"UPDATE users SET refered_by=9002 WHERE userid=1001")


def seed_receipt(work,env,method_driver,key,route):
    manual.seed(work,env,key)
    custom=route=='custom'
    desc='fx_'+key if custom else 'fixture'
    day,volume=(30,10) if custom else (0,0)
    query(work,env,"UPDATE pays SET type='BUY_SUB',plan_id=1,server_id=1,"
        f"day={day},volume={volume},description='{desc}' WHERE hash_id='{key}'")
    assert manual.run_php(work,env,method_driver,key,'claim','bank_transfer') is True
    token=manual.run_php(work,env,method_driver,key,'begin','bank_transfer')
    assert isinstance(token,str) and token
    assert manual.run_php(work,env,method_driver,key,'finish','bank_transfer',
                          token=token) is True
    assert query(work,env,f"SELECT state FROM pays WHERE hash_id='{key}'")=='have_sent'
    return int(query(work,env,f"SELECT id FROM pays WHERE hash_id='{key}'"))


def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('--run-disposable',action='store_true',required=True)
    parser.add_argument('--report',type=Path)
    args=parser.parse_args()
    cases=[]
    with owned.owned_database() as (work,env):
        initial(work,env)
        route_driver=work/'referral-manual-route.php'
        route_driver.write_text(PHP_ROUTE)
        method_driver=work/'referral-receipt-method.php'
        method_driver.write_text(manual.PHP)

        baseline=int(query(work,env,'SELECT wallet FROM users WHERE userid=9002'))

        def check(route,key,behavior,expected_warning):
            pid=seed_receipt(work,env,method_driver,key,route)
            kw={'route':'standard_approval' if route=='standard' else 'checkout',
                'data':('accept' if route=='standard' else 'accCustom')+key,
                'actor':9001,'expected_warning':expected_warning}
            kw.update(behavior)
            events=manual.run_route(work,env,route_driver,**kw)
            assert query(work,env,f"SELECT state FROM pays WHERE id={pid}")=='approved',(key,events)
            assert query(work,env,f"SELECT state FROM referral_awards WHERE pay_row_id={pid}")=='CONFIRMED',key
            assert query(work,env,f"SELECT COUNT(*) FROM referral_awards WHERE pay_row_id={pid}")=='1'
            expected=baseline+1000*(len(cases)+1)
            actual=int(query(work,env,'SELECT wallet FROM users WHERE userid=9002'))
            assert actual==expected,(key,actual,expected,events)
            assert [e['name'] for e in events].count('remote_create')==1,(key,events)
            repeated=manual.run_route(work,env,route_driver,data=kw['data'],actor=9001,
                route=kw['route'])
            assert not [e for e in repeated if e['name']=='remote_create'],(key,repeated)
            assert query(work,env,f"SELECT COUNT(*) FROM referral_awards WHERE pay_row_id={pid}")=='1'
            assert int(query(work,env,'SELECT wallet FROM users WHERE userid=9002'))==expected
            return events

        for route in ('standard','custom'):
            if route=='standard':
                warning='achbot_manual_standard_delivery_needs_review'
            else:
                warning='achbot_custom_receipt_delivery_review'
            events=check(route,'ref'+route+'qr',{'qr_transport':'throw'},warning)
            assert [e['name'] for e in events].count('qr_send')==1,events
            cases.append('real_'+route+'_receipt_qr_throw_award_confirmed_no_replay')

            warning=('achbot_manual_standard_delivery_needs_review'
                     if route=='standard' else
                     'achbot_custom_receipt_postcommit_notice_review')
            check(route,'ref'+route+'message',{'message_transport':'postreferral'},warning)
            cases.append('real_'+route+'_receipt_customer_message_throw_award_confirmed_no_replay')

            warning=('achbot_manual_standard_buttons_update_failed'
                     if route=='standard' else
                     'achbot_custom_receipt_postcommit_notice_review')
            check(route,'ref'+route+'buttons',{'message_edit_transport':'throw'},warning)
            cases.append('real_'+route+'_receipt_admin_markup_throw_award_confirmed_no_replay')

    result={'complete':True,'cases':len(cases),'scenarios':cases,
            'native_php_mariadb':True,'financial_effects_production':0,
            'real_telegram_or_provider_calls':0}
    if args.report:args.report.write_text(json.dumps(result,indent=2)+'\n')
    print(json.dumps(result))


if __name__=='__main__':
    main()
