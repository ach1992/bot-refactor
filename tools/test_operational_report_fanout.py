#!/usr/bin/env python3
"""Actual current config fanout, isolated DB and simulated Telegram transport."""
import argparse
import json
from pathlib import Path
import subprocess
import database_fixture as owned

ROOT=Path(__file__).resolve().parents[1]
PHP=r'''<?php
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$root=$argv[1];$connection=new mysqli('localhost','root','',$argv[3],0,$argv[2]);
$connection->set_charset('utf8mb4');$admin=9001;$botState=['rewardChannel'=>-100123];
require $root.'/tools/source_scan.php';require $root.'/src/Admin/Access.php';
eval(achbotExtractFunction($root.'/config.php','achbotNotifyAdmins'));

$events=[];$cases=[];$fail=0;$throw=0;
function sendMessage($text,$keys,$mode,$to,...$args) {
    global $events,$fail,$throw,$connection;
    if ((int)$connection->query('SELECT @@in_transaction')->fetch_row()[0]!==0)
        throw new RuntimeException('fixture report dispatched inside transaction');
    $events[]=['to'=>(int)$to,'keys'=>$keys,'text'=>$text,'mode'=>$mode];
    if ((int)$to===$throw) throw new RuntimeException('synthetic confidential marker');
    return (int)$to===$fail?(object)['ok'=>false,'error_code'=>403]:(object)['ok'=>true];
}
function yes($condition,$name) {global $cases;if (!$condition) throw new RuntimeException($name);$cases[]=$name;}
$targets=static fn()=>array_column($GLOBALS['events'],'to');
$data=['text'=>'Fixture report','reply_markup'=>'private','parse_mode'=>'HTML'];
$options=['report_actor'=>9002,'actor_capability'=>'orders','report_ref'=>'delete:41','report_reply_markup'=>null];
$connection->query("INSERT INTO setting(type,value) VALUES ('ADMIN_NOTIFY_9002','off')");
$r=achbotNotifyAdmins('sendMessage',$data,$options);
yes($targets()===[9001,9002,-100123] && (count($r)>0 && count(array_filter($r,static fn($notice)=>($notice['response']->ok??false)===true))===count($r)),'owner actual actor channel despite disabled general preference');
yes(array_column($events,'keys')===['private','private',null],'channel has no private controls');
$events=[];$options['report_actor']=9001;
achbotNotifyAdmins('sendMessage',$data,$options);
yes($targets()===[9001,-100123],'owner acting is not duplicated');
$events=[];$options['report_actor']=9002;$botState['rewardChannel']='9002';
achbotNotifyAdmins('sendMessage',$data,$options);
yes($targets()===[9001,9002],'overlapping actor and channel deduplicated');
$events=[];$botState['rewardChannel']=-100123;
$connection->query("UPDATE users SET isAdmin=0 WHERE userid=9002");
achbotNotifyAdmins('sendMessage',$data,$options);
yes($targets()===[9001,-100123],'revoked actor excluded');
$events=[];$connection->query("UPDATE users SET isAdmin=1 WHERE userid=9002");
$connection->query("INSERT INTO setting(type,value) VALUES ('ADMIN_ROLE_9002','manager')");
$connection->query("UPDATE setting SET value='on' WHERE type='ADMIN_NOTIFY_9002'");
achbotNotifyAdmins('sendMessage',$data,$options);
yes($targets()===[9001,9002,-100123],'manager and actor deduplicated');
$events=[];$fail=9001;$throw=9002;
$r=achbotNotifyAdmins('sendMessage',$data,$options);
yes($targets()===[9001,9002,-100123]&&!(count($r)>0 && count(array_filter($r,static fn($notice)=>($notice['response']->ok??false)===true))===count($r)),'independent sends survive false and throwing transport');
echo json_encode(['complete'=>true,'cases'=>count($cases),'case_names'=>$cases,'external_requests'=>0]);
'''
def main():
    p=argparse.ArgumentParser()
    p.add_argument('--run-disposable',action='store_true',required=True)
    p.add_argument('--report',type=Path,required=True)
    args=p.parse_args()
    with owned.owned_database(ROOT/'database/current-schema.sql') as (work,env):
        script=work/'report-fanout.php';script.write_text(PHP)
        r=subprocess.run(['php','-n','-d','extension=mysqlnd','-d','extension=mysqli','-d','extension=tokenizer','-d','log_errors=1','-d','display_errors=0','-d','error_log=/dev/stderr',str(script),str(ROOT),str(work/'mysql.sock'),owned.NAMES[0]],env=env,capture_output=True,text=True,timeout=30)
        assert r.returncode==0,(r.stdout,r.stderr)
        result=json.loads(r.stdout)
        assert 'source=delete:41 target=owner telegram_code=403' in r.stderr,r.stderr
        assert 'source=delete:41 target=actor telegram_code=0' in r.stderr,r.stderr
        assert 'synthetic confidential marker' not in r.stderr,r.stderr
        result['case_names'].append('source-specific redacted diagnostics')
        result['cases']+=1
        args.report.write_text(json.dumps(result,ensure_ascii=False))
        print(json.dumps(result,ensure_ascii=False))
if __name__=='__main__':main()
