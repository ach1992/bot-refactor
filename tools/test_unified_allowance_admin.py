#!/usr/bin/env python3
"""AChBot current administrator allowance screens over owned original-schema MariaDB."""
from __future__ import annotations
import argparse
import json
import subprocess
from pathlib import Path
import test_access_state as owned

ROOT=Path(__file__).resolve().parents[1]
DISPLAY_BASELINE='f4731de2922adb96d005e523f75ec067907b2e05'
PHP=r'''<?php
error_reporting(E_ALL & ~E_DEPRECATED);
set_error_handler(static function($severity,$message,$file,$line) {
    if ($severity===E_DEPRECATED) return true;
    throw new ErrorException($message,0,$severity,$file,$line);
});
date_default_timezone_set('UTC');
$GLOBALS['read_only_capture']=false;$GLOBALS['screen_writes']=[];
class AllowanceScreensMysqli extends mysqli {
    private function record(string $sql): void {
        if ($GLOBALS['read_only_capture'] &&
            preg_match('/^\s*(?:INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP|TRUNCATE)\b/i',$sql))
            $GLOBALS['screen_writes'][]=$sql;
    }
    public function prepare(string $query): mysqli_stmt|false {
        $this->record($query);return parent::prepare($query);
    }
    public function query(string $query,int $result_mode=MYSQLI_STORE_RESULT): mysqli_result|bool {
        $this->record($query);return parent::query($query,$result_mode);
    }
}
$root=$argv[1];
$connection=new AllowanceScreensMysqli('localhost','root','',$argv[3],0,$argv[2]);
$connection->set_charset('utf8mb4');
require $root.'/messages/loader.php';
require $root.'/src/Presentation/Text.php';
require $root.'/src/Purchase/Core.php';
require $root.'/settings/jdf.php';
$checks=[];
function yes(bool $ok,string $case): void {
    if (!$ok) throw new RuntimeException($case);$GLOBALS['checks'][]=$case;
}
function achbotSupportsPanel($type) {return in_array($type,['marzban','pasarguard'],true);}
function bot($method,$args=[]) {
    if ($method!=='getChat') throw new RuntimeException('unexpected external operation');
    return (object)['ok'=>true,'result'=>(object)['username'=>'fixture_customer',
        'first_name'=>'Fixture','last_name'=>'Customer']];
}
function sendMessage(...$args) {
    $GLOBALS['events'][]=['body'=>$args[0]??'','keys'=>$args[1]??null];return (object)['ok'=>true];
}
function editText(...$args) {$GLOBALS['events'][]=['body'=>$args[1]??'','keys'=>$args[2]??null];}
function alert(...$args) {throw new RuntimeException('unexpected authorization rejection');}
$buttonValues=achbotMessages('legacy_buttons');$mainValues=achbotMessages('legacy_messages');
$from_id=$chat_id=9001;$admin=9001;$data='';$text='';$message_id=1;$botState=[];
$userInfo=$connection->query('SELECT * FROM users WHERE userid=9001')->fetch_assoc();
$cancelKey='{}';$removeKeyboard='{}';$GLOBALS['events']=[];
$config=file_get_contents($root.'/config.php');
foreach ([
    ['function getUserInfoKeys(','// ✨ هندل کردن تغییر وضعیت همکار و مسدودی'],
    ['function getPlanDetailsKeys(','// BEGIN create user and admin get order detail'],
] as [$start,$end]) {
    $a=strpos($config,$start);$b=strpos($config,$end,$a);
    if ($a===false || $b===false) throw new RuntimeException('current screen extraction missing');
    eval(str_replace('__DIR__',var_export($root,true),substr($config,$a,$b-$a)));
}
require $root.'/src/Subscription/OrderManagement.php';
$connection->query("INSERT INTO server_info(id,title,ucount,remark,flag,active,state) VALUES
    (1,'Marzban server',10,'mb','',1,1),(2,'PG server',10,'pg','',1,1),(3,'Retired server',10,'old','',1,1)");
$connection->query("INSERT INTO server_config(id,panel_url,ip,sni,header_type,request_header,response_header,security,tlsSettings,type,username,password) VALUES
    (1,'https://mb.invalid','','','none','','','none','','marzban','',''),
    (2,'https://pg.invalid','','','none','','','none','','pasarguard','',''),
    (3,'https://old.invalid','','','none','','','none','','retired','','')");
$plans=[
    [41,1,0,0],[42,2,0,0],[43,1,30,20.5],[44,2,0.5,0.25],
    [45,1,-1,-1],[46,2,1,0],[47,2,0,5]
];
foreach ($plans as [$id,$server,$days,$volume]) {
    $title='Plan '.$id;
    $q=$connection->prepare("INSERT INTO server_plans(id,fileid,catid,server_id,inbound_id,acount,limitip,title,protocol,days,volume,type,price,descr,pic,active,step,date) VALUES(?,'',0,?,0,0,1,?,'',?,?,'ws',100,'Fixture','',1,10,'1700000000')");
    $q->bind_param('iisdd',$id,$server,$title,$days,$volume);$q->execute();$q->close();
}
$records=[
    [101,1,0,'mb-unlimited',0],[102,2,0,'pg-unlimited',0],
    [103,1,1800000000,'mb-finite',0],[104,2,1800000000,'pg-finite',0],
    [105,1,-1,'mb-invalid',0],[106,3,0,'old-zero',0],
    [107,9999,0,'unavailable-zero',0],[108,1,0,'retired-inbound-zero',5],
    [109,2,-2147483648,'pg-invalid',0]
];
foreach ($records as [$id,$server,$expiry,$remark,$inbound]) {
    $q=$connection->prepare("INSERT INTO orders_list(id,userid,token,transid,fileid,server_id,inbound_id,remark,uuid,protocol,expire_date,link,amount,status,date) VALUES(?,1001,'fixture-token','',0,?,?,?,'fixture-uuid','vless',?,'[]',0,1,'1700000000')");
    $q->bind_param('iiisi',$id,$server,$inbound,$remark,$expiry);$q->execute();$q->close();
}
$schemaBefore=[];
foreach (['orders_list','server_plans','users'] as $table)
    $schemaBefore[$table]=$connection->query('SHOW CREATE TABLE '.$table)->fetch_row()[1];
$plansBefore=$connection->query('SELECT * FROM server_plans ORDER BY id')->fetch_all(MYSQLI_ASSOC);
$ordersBefore=$connection->query('SELECT * FROM orders_list ORDER BY id')->fetch_all(MYSQLI_ASSOC);
$GLOBALS['read_only_capture']=true;
$unlimited=achbotMessage('subscription.account_deletion.no_dont_delete.text.5e6ee5eaa1');
$unknown=achbotMessage('admin_settings.config.get_bot_report_keys.text.f07a81a062');
use function AchBot\Presentation\Text\serviceAllowanceState;
use function AchBot\Presentation\Text\serviceExpiryLabel;
// Explicit context, rather than a numeric cast, owns the unlimited meaning.
foreach ([0,'0',0.0,'0.000'] as $value)
    yes(serviceAllowanceState($value,true)['state']==='unlimited','known exact zero allowance is unlimited');
foreach ([1,'1',0.5,'0.5','0.25'] as $value)
    yes(serviceAllowanceState($value,true)['state']==='finite','positive fractional allowance never becomes unlimited');
foreach ([null,-1,'-1',false,true,[],new stdClass(),NAN,INF,'NaN','unavailable','0e0','', ' 0 '] as $value)
    yes(serviceAllowanceState($value,true)['state']==='unknown','malformed or absent allowance remains unknown');
yes(serviceAllowanceState(null,true,true)['state']==='unlimited','confirmed native null allowance explicit context');
yes(serviceAllowanceState(0)['state']==='finite' && serviceAllowanceState(0)['display']==='0',
    'finite remaining zero is exhausted not unlimited');
$formatCalls=0;
$formatter=static function(int $timestamp) use (&$formatCalls): string {
    ++$formatCalls;return date('Y-m-d H:i:s',$timestamp);
};
yes(serviceExpiryLabel(0,$formatter,$unlimited,$unknown,true)==='نامحدود','known recorded zero expiry uses literal unlimited');
yes(serviceExpiryLabel(null,$formatter,$unlimited,$unknown,true)===$unknown,'absent recorded expiry is unknown');
yes(serviceExpiryLabel(null,$formatter,$unlimited,$unknown,false,true)===$unlimited,'confirmed native null expiry explicit context');
foreach ([-1,'-1',false,true,0.5,'0.5','unknown','',2147483648] as $value)
    yes(serviceExpiryLabel($value,$formatter,$unlimited,$unknown,true)===$unknown,'invalid expiry never becomes unlimited or an epoch');
yes($formatCalls===0,'sentinels and malformed expiries never enter calendar formatter');

function labelFor(string $json,string $callback): string {
    foreach (json_decode($json,true)['inline_keyboard'] as $row)
        foreach ($row as $button) if (($button['callback_data']??null)===$callback) return (string)$button['text'];
    throw new RuntimeException('expected current plan action missing');
}
foreach ([41,42] as $id) {
    $screen=getPlanDetailsKeys($id);
    yes(labelFor($screen,'planDays'.$id)===achbotMessage('admin_settings.management.plan_days_unlimited'),
        'actual plan days zero renders whole unlimited label '.$id);
    yes(labelFor($screen,'planVolume'.$id)===achbotMessage('admin_settings.management.plan_volume_unlimited'),
        'actual plan quota zero renders whole unlimited label '.$id);
    yes(!str_contains($screen,'نامحدود روز') && !str_contains($screen,'نامحدود گیگابایت'),
        'unlimited plan labels do not append finite units '.$id);
}
$finite=getPlanDetailsKeys(43);
yes(labelFor($finite,'planDays43')===achbotMessage('admin_settings.management.plan_days_label',['value'=>'30']),
    'actual finite plan days retain existing whole label');
yes(labelFor($finite,'planVolume43')===achbotMessage('admin_settings.management.plan_volume_label',['value'=>'20.5']),
    'actual finite fractional quota remains positive');
$fractional=getPlanDetailsKeys(44);
yes(labelFor($fractional,'planDays44')===achbotMessage('admin_settings.management.plan_days_label',['value'=>'0.5']),
    'actual fractional plan days never cast to unlimited');
yes(labelFor($fractional,'planVolume44')===achbotMessage('admin_settings.management.plan_volume_label',['value'=>'0.25']),
    'actual fractional quota never cast to unlimited');
$invalid=getPlanDetailsKeys(45);
yes(labelFor($invalid,'planDays45')===achbotMessage('admin_settings.management.plan_days_unknown') &&
    labelFor($invalid,'planVolume45')===achbotMessage('admin_settings.management.plan_volume_unknown'),
    'actual negative plan metadata remains unknown');
yes(labelFor(getPlanDetailsKeys(46),'planDays46')===achbotMessage('admin_settings.management.plan_days_label',['value'=>'1']) &&
    labelFor(getPlanDetailsKeys(46),'planVolume46')===achbotMessage('admin_settings.management.plan_volume_unlimited'),
    'time finite quota unlimited plan labels independent');
yes(labelFor(getPlanDetailsKeys(47),'planDays47')===achbotMessage('admin_settings.management.plan_days_unlimited') &&
    labelFor(getPlanDetailsKeys(47),'planVolume47')===achbotMessage('admin_settings.management.plan_volume_label',['value'=>'5']),
    'time unlimited quota finite plan labels independent');

$report=getUserInfoKeys(1001);$reportText=implode("\n\n",$report['subscriptionMessages']);
foreach ($records as [$id,$server,$expiry,$remark,$inbound]) {
    $expected=$expiry>0?date('Y-m-d H:i:s',$expiry):
        ($expiry===0 && in_array($server,[1,2],true) && $inbound===0?$unlimited:$unknown);
    yes(str_contains($reportText,achbotMessage('admin_settings.config.get_user_info_keys.text.609b681dab',[
        'remark'=>$remark,'expire_date'=>$expected])),'actual user report expiry label '.$remark);
}
yes(!str_contains($reportText,'1970') && !str_contains($reportText,'1969'),
    'actual administrator subscription report never renders zero negative epoch');
$panelDate=jdate('Y-m-d H:i',1800000000,'','Asia/Tehran','en');
yes(preg_match('/[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}/D',$panelDate)===1,
    'real existing JDF remains ASCII for finite expiry');
foreach ($records as [$id,$server,$expiry,$remark,$inbound]) {
    $order=$connection->query('SELECT * FROM orders_list WHERE id='.(int)$id)->fetch_assoc();
    $GLOBALS['events']=[];showOrderManagementPanel($order);
    $body=$GLOBALS['events'][0]['body'];
    $expected=$expiry>0?$panelDate:
        ($expiry===0 && in_array($server,[1,2],true) && $inbound===0?$unlimited:$unknown);
    yes(str_contains($body,$expected),'actual order management recorded expiry '.$remark);
    if ($expiry<=0) yes(!str_contains($body,'1970') && !str_contains($body,'1969'),
        'actual order management has no zero negative epoch '.$remark);
}
$order=$connection->query('SELECT * FROM orders_list WHERE id=101')->fetch_assoc();
foreach ([null,false,'not-a-date'] as $invalidExpiry) {
    $order['expire_date']=$invalidExpiry;$GLOBALS['events']=[];showOrderManagementPanel($order);
    $body=$GLOBALS['events'][0]['body'];
    yes(str_contains($body,$unknown) && !str_contains($body,$unlimited),
        'actual composed order screen absent malformed expiry is unknown');
}
yes($plansBefore===$connection->query('SELECT * FROM server_plans ORDER BY id')->fetch_all(MYSQLI_ASSOC) &&
    $ordersBefore===$connection->query('SELECT * FROM orders_list ORDER BY id')->fetch_all(MYSQLI_ASSOC),
    'all composed display reads preserve plans and orders');
yes($GLOBALS['screen_writes']===[],'composed display screens never attempt SQL mutation');
// Reuse the exact reviewed current baseline functions with only their names
// and source path mechanically rebound. These are behavioral RED controls,
// not new goldens and not changes to historical V0-V9 source or evidence.
require $argv[4];
$oldPlan=baselineAllowancePlanDetails(41);
yes(labelFor($oldPlan,'planDays41')!==achbotMessage('admin_settings.management.plan_days_unlimited') &&
    labelFor($oldPlan,'planVolume41')!==achbotMessage('admin_settings.management.plan_volume_unlimited'),
    'archived current plan zero labels RED against new unlimited contract');
$oldReport=baselineAllowanceUserInfo(1001);
yes(str_contains(implode("\n\n",$oldReport['subscriptionMessages']),'1970-01-01'),
    'archived current administrator subscription report zero epoch RED');
$oldOrder=$connection->query('SELECT * FROM orders_list WHERE id=105')->fetch_assoc();
$GLOBALS['events']=[];baselineAllowanceOrderPanel($oldOrder);
yes(str_contains($GLOBALS['events'][0]['body'],$unlimited) &&
    !str_contains($GLOBALS['events'][0]['body'],$unknown),
    'archived current order panel negative metadata incorrectly unlimited RED');
foreach ($schemaBefore as $table=>$before)
    yes($before===$connection->query('SHOW CREATE TABLE '.$table)->fetch_row()[1],'current display preserves schema '.$table);
echo json_encode(['complete'=>true,'cases'=>count($checks),'checks'=>$checks,
    'actual_plan_screen'=>true,'actual_admin_subscription_report'=>true,'actual_order_management_screen'=>true,
    'real_disposable_mariadb'=>true,'real_JDF'=>true,'finite_remaining_zero_not_unlimited'=>true,
    'read_only_screens'=>true,'archived_current_baseline_RED'=>3,'network_calls'=>0,'production_mutations'=>0],JSON_THROW_ON_ERROR);
'''

def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('--run-disposable',action='store_true',required=True)
    parser.add_argument('--report',type=Path)
    args=parser.parse_args()
    old_config=subprocess.check_output(['git','show',DISPLAY_BASELINE+':config.php'],cwd=ROOT,text=True)
    old_order=subprocess.check_output(['git','show',DISPLAY_BASELINE+':src/Subscription/OrderManagement.php'],cwd=ROOT,text=True)
    def archived_function(source,start,end,old_name,new_name):
        begin=source.index(start);finish=source.index(end,begin)
        return source[begin:finish].replace(old_name,new_name,1).replace('__DIR__',json.dumps(str(ROOT)))
    baseline_functions='''<?php
'''+archived_function(old_config,'function getPlanDetailsKeys(','// BEGIN create user and admin get order detail',
            'function getPlanDetailsKeys(','function baselineAllowancePlanDetails(')+archived_function(
            old_config,'function getUserInfoKeys(','// توابع کمکی برای خوانایی بیشتر',
            'function getUserInfoKeys(','function baselineAllowanceUserInfo(')+archived_function(
            old_order,'function showOrderManagementPanel(',"if (preg_match('/^returnToPanel_",
            'function showOrderManagementPanel(','function baselineAllowanceOrderPanel(')
    with owned.owned_database() as (work,env):
        baseline=work/'archived-current-display.php';baseline.write_text(baseline_functions)
        driver=work/'allowance-admin.php';driver.write_text(PHP)
        process=subprocess.run(['php','-d','disable_functions=curl_init,curl_exec',str(driver),
            str(ROOT),str(work/'mysql.sock'),owned.NAMES[1],str(baseline)],env=env,
            capture_output=True,text=True,timeout=45)
        if process.returncode:
            raise RuntimeError(process.stdout[-1000:]+'\n'+process.stderr[-3500:])
        result=json.loads(process.stdout)
        result['display_baseline']=DISPLAY_BASELINE
    if args.report:args.report.write_text(json.dumps(result,indent=2,ensure_ascii=False)+'\n')
    print(json.dumps({key:value for key,value in result.items() if key!='checks'}))
if __name__=='__main__':main()
