#!/usr/bin/env python3
"""Actual sales keyboard, date report and discount redemption on current schema.

Shape-valid local history fixtures distinguish receipt, operation and ledger
facts. No production, merchant, provider or Telegram calls.
"""
import argparse
import json
from pathlib import Path
import subprocess
import time

import test_access_state as owned
from test_v5_native import sql_command

ROOT=Path(__file__).resolve().parents[1]
DB=owned.NAMES[1]
DRIVER=r'''<?php
declare(strict_types=1);
$root=$argv[1];
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
set_error_handler(static function($n,$s){throw new ErrorException($s,0,$n);});
$connection=new mysqli('localhost','root','',$argv[3],0,$argv[2]);
$connection->set_charset('utf8mb4');
date_default_timezone_set('Asia/Tehran');
$connection->query("SET time_zone='+03:30'");
require $root.'/src/Finance/PaymentLifecycle.php';
require_once $root.'/src/Finance/Discounts.php';
require_once $root.'/tools/source_scan.php';
$botState=['walletState'=>'on'];
$cancelKey=$removeKeyboard='{}';
$from_id=9001;$events=[];
function sendMessage(...$a){$GLOBALS['events'][]=$a;return (object)['ok'=>true];}
function achbotNotifyAdmins(...$a){$GLOBALS['events'][]=$a;return true;}
function setSettings(...$a){$GLOBALS['events'][]=$a;return true;}
foreach (['fetchData','sendChunkedMessages','getBotReportKeys','calculateSalesBetweenDates','getPaymentSumInRange'] as $name)
    eval(str_replace('__DIR__',var_export($root,true),achbotExtractFunction($root.'/config.php',$name)));
$x=json_decode($argv[4],true,512,JSON_THROW_ON_ERROR);
if ($x['op']==='report') {
    $r=json_decode(getBotReportKeys('stats_sales'),true,512,JSON_THROW_ON_ERROR);
    if ($r!==json_decode(getBotReportKeys('stats_sales'),true,512,JSON_THROW_ON_ERROR))
        throw new RuntimeException('unstable report');
} elseif ($x['op']==='range') {
    calculateSalesBetweenDates($x['start'],$x['end']);$r=$events;
} elseif ($x['op']==='payments') {
    getBotReportKeys('top_50_pays');$r=$events;
 } elseif ($x['op']==='scheduled') {
    $botState=['rewaredTime'=>24,'rewardChannel'=>'@fixture','lastRewardMessage'=>0];
    $channelLock='Fixture';
    $source=file_get_contents($root.'/settings/rewardReport.php');
    $start=strpos($source,'$rewaredTime =');$end=strpos($source,'// AChBot backup:',$start);
    if ($start===false || $end===false) throw new RuntimeException('report_source_boundary');
    eval(str_replace('__DIR__',var_export($root.'/settings',true),substr($source,$start,$end-$start)));
    $r=$events;
} elseif ($x['op']==='payment_sum') {
    $r=getPaymentSumInRange($connection,$x['owner'],0);
} elseif ($x['op']==='pin') {
    require_once $root.'/src/Finance/CheckoutAdmission.php';
    $connection->begin_transaction();
    achbotProviderPinPayment($connection,$x['pay']);
    $p=achbotProviderRow($connection,'SELECT * FROM pays WHERE id=? FOR UPDATE','i',[$x['pay']]);
    $plan=achbotProviderRow($connection,'SELECT days,volume FROM server_plans WHERE id=?','i',[(int)$p['plan_id']]);
    achbotCheckoutSealDraftLocked($connection,$x['pay'],$p,$plan,['remark'=>'digits']);
    $connection->commit();$r=true;
} elseif ($x['op']==='discount') {
    $from_id=$x['owner'];
    $r=applyDiscountToPay($connection,$x['pay'],$x['code'],$from_id,'fixture','Fixture',9001,$botState);
} elseif ($x['op']==='history') {
    $r=achbotCustomerHistoryCount($connection,$x['owner'],'buy',0,'older',false,time());
} else throw new RuntimeException('unknown fixture operation');
echo json_encode(['result'=>$r,'events'=>$events],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
'''

# Separate connection holds the real owner lock across preview and final
# redemption. Release only after the competing process is waiting on it.
LOCKER=r'''<?php
$db=new mysqli('localhost','root','',$argv[2],0,$argv[1]);
$db->begin_transaction();
$db->query("SELECT id FROM users WHERE userid=2009 FOR UPDATE");
file_put_contents($argv[3],'ready');
$deadline=microtime(true)+20;
while (!is_file($argv[4]) && microtime(true)<$deadline) usleep(10000);
if (!is_file($argv[4])) {$db->rollback();exit(7);}
$db->query("UPDATE service_operations SET stage='completed' WHERE userid=2009 AND command_kind='checkout'");
$db->commit();
'''

def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('--run-disposable',action='store_true',required=True)
    parser.add_argument('--report',type=Path)
    args=parser.parse_args()
    cases=[]
    now=int(time.time())
    with owned.owned_database(ROOT/'database/current-schema.sql') as (w,e):
        def sql(q):return sql_command(w,e,"SET time_zone='+03:30'; "+q,DB).strip()
        driver=w/'sales-discount.php';driver.write_text(DRIVER)
        def command(spec):
            return ['php','-n','-d','extension=mysqlnd','-d','extension=mysqli','-d','extension=tokenizer',
                '-d','allow_url_fopen=0','-d','disable_functions=exec,shell_exec,system,passthru,popen,proc_open,mail',
                str(driver),str(ROOT),str(w/'mysql.sock'),DB,json.dumps(spec)]
        def invoke(op,**kw):
            p=subprocess.run(command(dict(op=op,**kw)),env=e,text=True,capture_output=True,timeout=25)
            assert p.returncode==0 and not p.stderr,(op,p.returncode,p.stdout[-1000:],p.stderr[-1000:])
            return json.loads(p.stdout)['result']
        def literal(value):return "'"+str(value).replace("'","''")+"'"
        sql("INSERT INTO setting(type,value) VALUES ('BOT_STATES','{\"walletState\":\"on\"}')")
        for uid in range(2001,2012):
            sql(f"INSERT INTO users(userid,name,username,refcode,wallet,date,is_agent) VALUES({uid},'Fixture','fixture{uid}','0',1000000,'1700000000',{int(uid==2003)})")
        sql("INSERT INTO server_info(id,title,ucount,remark,flag,active,state) VALUES (2,'Fixture A','100','a','',1,1),(3,'Fixture B','100','b','',1,1),(4,'Fixture Empty','100','c','',1,1)")
        sql("INSERT INTO server_plans(id,fileid,catid,server_id,inbound_id,acount,title,protocol,days,volume,type,price,descr,pic,active,step,date,provider_group_ids) VALUES (7,'7',1,2,0,6,'Plan A','vless',30,2,'normal',100,'','',1,0,'fixture','[1]'),(8,'8',1,3,0,6,'Plan B','vless',30,2,'normal',100,'','',1,0,'fixture','[1]')")
        sql("INSERT INTO server_categories(id,server_id,title,parent,step,active) VALUES (1,'2','Fixture category',0,0,1)")
        sql("INSERT INTO server_config(id,panel_url,type,username,password,provider_connection_id,provider_instance_key,provider_version,provider_enabled) VALUES(2,'https://fixture.invalid','pasarguard','fixture','fixture',2,REPEAT('a',32),'5.5.1',1)")
        def pay(uid,tag,price,state,typ='BUY_SUB',server=2,plan=7,stamp=None,context=None):
            ctx='NULL'
            sql(f"INSERT INTO pays(hash_id,user_id,type,price,request_date,state,server_id,plan_id,checkout_draft_context) VALUES ('{tag}',{uid},'{typ}',{price},{now if stamp is None else stamp},'{state}',{server},{plan},{ctx})")
            pid=int(sql(f"SELECT id FROM pays WHERE hash_id='{tag}'"))
            if context is not None: assert invoke('pin',pay=pid)
            return pid
        def operation(uid,tag,price,stage,*,pid=None,kind='BUY',refund=0,server=2,plan=7,age=0,quantity=1):
            command_kind='checkout' if pid else 'automatic_renewal'
            ref=str(pid) if pid else tag
            row=int(sql(f"SELECT id FROM users WHERE userid={uid}"))
            payload=literal(json.dumps({'request':{'order_id':0 if kind=='BUY' else 99,'server_id':server,
                'plan_id':plan,'price':price,'quantity':quantity,'kind':kind}},separators=(',',':')))
            # Issue first, then bind the original debit/refund evidence.
            sql(f"INSERT INTO service_operations(command_kind,command_ref,user_row_id,userid,kind,quote_payload,quote_sha256,price_toman,created_at,updated_at) VALUES ('{command_kind}','{ref}',{row},{uid},'{kind}',{payload},SHA2({payload},256),{price},FROM_UNIXTIME({now-age}),NOW(6))")
            op=int(sql(f"SELECT id FROM service_operations WHERE command_kind='{command_kind}' AND command_ref='{ref}'"))
            debit='NULL';credit='NULL'
            if price and stage not in ('awaiting_funds',):
                sql(f"INSERT INTO wallet_entries(source_kind,source_ref,leg,user_row_id,userid,delta_toman,balance_before,balance_after,actor_userid,created_at) VALUES ('service_debit','{op}',0,{row},{uid},-{price},1000000,{1000000-price},0,NOW(6))")
                debit=sql(f"SELECT id FROM wallet_entries WHERE source_kind='service_debit' AND source_ref='{op}'")
            if refund:
                sql(f"INSERT INTO wallet_entries(source_kind,source_ref,leg,user_row_id,userid,delta_toman,balance_before,balance_after,actor_userid,created_at) VALUES ('service_refund','{op}',0,{row},{uid},{refund},{1000000-price},{1000000-price+refund},9001,NOW(6))")
                credit=sql(f"SELECT id FROM wallet_entries WHERE source_kind='service_refund' AND source_ref='{op}'")
            resolution=f",resolution_kind='refunded',resolved_by=9001,resolved_at=NOW(6),refund_entry_id={credit},cancelled_count=1" if refund else ''
            sql(f"UPDATE service_operations SET stage='{stage}',debit_entry_id={debit},reserved_count={quantity if kind=='BUY' and stage!='awaiting_funds' else 0}{resolution} WHERE id={op}")
            if pid:sql(f"UPDATE pays SET checkout_operation_id={op} WHERE id={pid}")
            return op
        # A credited but cancelled original and a funded-only command are
        # not sales; external and direct Wallet completion each count once.
        p=pay(2001,'invalidated',100000,'paid')
        operation(2001,'invalidated',100000,'cancelled',pid=p,refund=100000)
        p=pay(2002,'funded',200000,'paid_with_wallet')
        operation(2002,'funded',200000,'funded',pid=p)
        p=pay(2003,'external-complete',300000,'paid')
        operation(2003,'external-complete',300000,'completed',pid=p)
        p=pay(2004,'wallet-complete',400000,'paid_with_wallet',server=3,plan=8)
        operation(2004,'wallet-complete',400000,'completed',pid=p,server=3,plan=8)
        operation(2004,'auto-cycle',50000,'completed',kind='RENEW',server=3,plan=8)
        p=pay(2005,'full-refund',600000,'paid_with_wallet')
        operation(2005,'full-refund',600000,'cancelled',pid=p,refund=600000)
        p=pay(2006,'partial',100000,'paid_with_wallet')
        operation(2006,'partial',100000,'completed',pid=p,refund=50000,quantity=2)
        pay(2007,'historical',70000,'approved',stamp=now-60*86400)
        pay(2008,'topup',800000,'paid',typ='INCREASE_WALLET',server=0,plan=0)
        pay(2008,'abandoned',900000,'expired')
        # Credited receipt with no service debit at all (late invalidation).
        p=pay(2010,'late-credit',110000,'paid')
        op=operation(2010,'late-credit',110000,'awaiting_funds',pid=p)
        sql(f"UPDATE service_operations SET stage='cancelled',failure_code='service_subject_superseded' WHERE id={op}")
        # Distinct report date windows and original plan/server ownership.
        report=invoke('report')['inline_keyboard']
        by_label={row[1]['text']:row[0]['text'] for row in report if len(row)==2}
        expected={
            '📈 فروش کل':870000,'🛍 فروش ماه':800000,'🤝 فروش همکاری ماه':300000,
            '💼 مجموع فروش با کیف پول':500000,
        }
        for label,amount in expected.items():
            assert by_label[label].startswith(f'{amount:,} '),(label,by_label.get(label),report)
        assert '3' in by_label['💼 تعداد فروش با کیف پول'],by_label
        assert '4' in by_label['🚫 خریدهای نهایی نشده'],by_label
        def amounts(callback):
            return [row[0]['text'] for row in report if len(row)==2 and row[0]['callback_data']==callback]
        for callback,amount in [('server_sales_2',420000),('server_sales_3',450000),
                ('server_sales_month_2',350000),('server_sales_month_3',450000),
                ('server_sales_4',0),('plan_sales_7',420000),('plan_sales_8',450000)]:
            assert amounts(callback)[0].startswith(f'{amount:,} '),(callback,amounts(callback))
        cases.append('actual_sales_keyboard_net_completed_value_wallet_origin_and_original_windows')
        # Explicit payment history remains receipt-based, including incoming
        # money credited despite invalidated services and independent topups.
        assert invoke('payment_sum',owner=2010).startswith('110,000 ')
        payment_text=''.join(x[0] for x in invoke('payments'))
        assert '800,000' in payment_text and '110,000' in payment_text and '400,000' not in payment_text,payment_text
        cases.append('actual_payment_reports_preserve_incoming_receipts')
        from datetime import datetime,timedelta,timezone
        today=datetime.now(timezone(timedelta(hours=3,minutes=30))).date().isoformat()
        txt=invoke('range',start=today,end=today)[0][0]
        assert '800,000' in txt and '<b>4</b>' in txt,txt
        cases.append('actual_custom_date_sales_and_receipt_count_are_distinct')
        scheduled=invoke('scheduled')
        assert scheduled[0][0]=='lastRewardMessage' and '800,000' in scheduled[1][1]['text'],scheduled
        cases.append('actual_scheduled_sales_report_uses_same_completed_value')

        # Actual discount application, not just a pure predicate. Each code
        # has its own counter and a fresh sealed draft; rejected applications
        # must leave both original amount and counter unchanged.
        buyers={2003,2004,2006,2007}
        for uid in range(2001,2009):
            assert (invoke('history',owner=uid)>0)==(uid in buyers)
            for audience in ('buyers','non_buyers'):
                code=f'disc-{uid}-{audience}'
                pid=pay(uid,f'draft-{uid}-{audience}',100,'pending',context={'version':1})
                sql(f"INSERT INTO discounts(hash_id,type,amount,expire_date,expire_count,can_use,audience_type) VALUES ('{code}','percent',20,0,2,1,'{audience}')")
                result=invoke('discount',owner=uid,pay=pid,code=code)
                wanted=(uid in buyers)==(audience=='buyers')
                assert result['success']==wanted,(uid,audience,result)
                assert sql(f"SELECT price FROM pays WHERE id={pid}")==('80' if wanted else '100')
                assert sql(f"SELECT expire_count FROM discounts WHERE hash_id='{code}'")==('1' if wanted else '2')
        cases.append('actual_discount_buyer_inverse_matches_completed_history_and_no_ineligible_consumption')
        for target,target_id,wanted in [('server',2,True),('server',3,False),
                ('category',1,True),('category',99,False),('plan',7,True),('plan',8,False)]:
            code=f'target-{target}-{target_id}'
            pid=pay(2011,'draft-'+code,100,'pending',context={'version':1})
            sql(f"INSERT INTO discounts(hash_id,type,amount,expire_date,expire_count,can_use,target_type,target_id) VALUES ('{code}','percent',20,0,2,1,'{target}',{target_id})")
            result=invoke('discount',owner=2011,pay=pid,code=code)
            assert result['success']==wanted,(target,target_id,result)
            assert sql(f"SELECT price FROM pays WHERE id={pid}")==('80' if wanted else '100')
            assert sql(f"SELECT expire_count FROM discounts WHERE hash_id='{code}'")==('1' if wanted else '2')
        cases.append('actual_discount_target_policy_preserved_at_consumption')

        # The owner-lock handoff makes the initially valid non-buyer code
        # invalid before consumption, while the draft itself is unchanged.
        p=pay(2009,'race-funded',100,'paid_with_wallet')
        operation(2009,'race-funded',100,'funded',pid=p)
        pid=pay(2009,'race-draft',100,'pending',context={'version':1})
        sql("INSERT INTO discounts(hash_id,type,amount,expire_date,expire_count,can_use,audience_type) VALUES ('race-code','percent',20,0,2,1,'non_buyers')")
        locker=w/'locker.php';locker.write_text(LOCKER);ready=w/'ready';release=w/'release'
        first=subprocess.Popen(['php',str(locker),str(w/'mysql.sock'),DB,str(ready),str(release)],env=e,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        second=None
        try:
            deadline=time.monotonic()+8
            while not ready.exists() and time.monotonic()<deadline:time.sleep(.03)
            assert ready.exists()
            second=subprocess.Popen(command(dict(op='discount',owner=2009,pay=pid,code='race-code')),env=e,text=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
            deadline=time.monotonic()+8
            while time.monotonic()<deadline:
                processes=sql("SHOW FULL PROCESSLIST")
                if any('idx_users_userid_v1' in line and 'FOR UPDATE' in line
                       for line in processes.splitlines()):
                    break
                if second.poll() is not None:
                    raise AssertionError(('discount exited before owner lock',second.communicate()))
                time.sleep(.04)
            else:raise AssertionError(('actual discount did not reach owner lock',processes))
            release.touch()
            out,err=second.communicate(timeout=15)
            assert second.returncode==0 and not err,(out,err)
            assert not json.loads(out)['result']['success'],out
            assert sql(f"SELECT price FROM pays WHERE id={pid}")=='100'
            assert sql("SELECT expire_count FROM discounts WHERE hash_id='race-code'")=='2'
            assert invoke('history',owner=2009)==1
        finally:
            release.touch()
            out,err=first.communicate(timeout=10)
            assert first.returncode==0,(out,err)
            if second and second.poll() is None:second.terminate();second.wait(timeout=5)
        cases.append('actual_discount_revalidates_buyer_history_under_owner_lock')
    report={'status':'PASS','complete':True,'cases':len(cases),'scenarios':cases,
        'external_requests':0,'production_mutations':0}
    if args.report:args.report.write_text(json.dumps(report,indent=2)+'\n')
    print(json.dumps(report))

if __name__=='__main__':main()
