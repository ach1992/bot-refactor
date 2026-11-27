#!/usr/bin/env python3
"""Per-original staff receipt projection against the current disposable schema.

The transport double returns exact Telegram identities and explicit refusals.
No network, real financial effects, or external customer database.
"""
import argparse
import json
from pathlib import Path
import subprocess

import database_fixture as owned

ROOT=Path(__file__).resolve().parents[1]
DB=owned.NAMES[1]
DRIVER=r'''<?php
declare(strict_types=1);
error_reporting(E_ALL);
set_error_handler(static function($n,$s){throw new ErrorException($s,0,$n);});
$connection=new mysqli('localhost','root','',$argv[3],0,$argv[2]);
$connection->set_charset('utf8mb4');$admin=9001;
$spec=json_decode($argv[4],true,512,JSON_THROW_ON_ERROR);$events=[];$keyboards=[];
require_once $argv[1].'/src/Finance/ApiPayments.php';
require_once $argv[1].'/src/Finance/ReceiptNotices.php';
function bot($method,$args=[]){
    global $events,$spec,$keyboards;
    if ($method!=='editMessageReplyMarkup') throw new RuntimeException('unexpected_transport');
    $chat=(int)$args['chat_id'];$message=(int)$args['message_id'];$events[]=[$chat,$message];$keyboards[]=$args['reply_markup']??null;
    $mode=$spec['modes'][(string)$chat]??'ok';
    return match($mode) {
        'missing'=>(object)['ok'=>false,'error_code'=>400,'description'=>'Bad Request: message to edit not found'],
        'limited'=>(object)['ok'=>false,'error_code'=>429,'description'=>'Too Many Requests','parameters'=>(object)['retry_after'=>60]],
        'ambiguous'=>null,
        'wrong_ack'=>(object)['ok'=>true,'result'=>(object)['message_id'=>$message+1,'chat'=>(object)['id'=>$chat]]],
        default=>(object)['ok'=>true,'result'=>(object)['message_id'=>$message,'chat'=>(object)['id'=>$chat]]]
    };
}
$pay=achbotApiPaymentRow($connection,7701);
$refs=achbotReceiptMessageTargets($connection,$pay);
if (!empty($spec['unowned'])) $refs[]=['chat_id'=>9001,'message_id'=>999];
if (!empty($spec['stale'])) $pay['state']='declined';
if (!empty($spec['caller_tx'])) {
    $connection->begin_transaction();
    try {achbotApiPaymentProjectStaff($connection,$pay,$refs,'{}');$blocked=false;}
    catch(RuntimeException $e){$blocked=$e->getMessage()==='payment_evidence_transaction_boundary';}
    $active=(int)$connection->query('SELECT @@in_transaction')->fetch_row()[0];
    $connection->rollback();
    echo json_encode(['blocked'=>$blocked,'active'=>$active,'events'=>$events]);exit;
}
if (!empty($spec['notice'])) {
    if (!empty($spec['busy'])) {
        $other=new mysqli('localhost','root','',$argv[3],0,$argv[2]);
        if (achbotOriginalDispatchLeaseBegin($other,7701)!=='owned')
            throw new RuntimeException('fixture_busy_lease');
    }
    $edited=achbotApiPaymentNotice($connection,7701);
    echo json_encode(['edited'=>$edited,'events'=>$events,'keyboards'=>$keyboards],JSON_THROW_ON_ERROR);exit;
}
$edited=achbotApiPaymentProjectStaff($connection,$pay,$refs,json_encode(['inline_keyboard'=>[[['text'=>$spec['label']??'Review','callback_data'=>'noop_fixture']]]]));
echo json_encode(['edited'=>$edited,'events'=>$events,'rows'=>achbotProviderStatement($connection,"SELECT chat_id,message_id,retired_reason,synced_digest,TIMESTAMPDIFF(SECOND,NOW(6),updated_at) AS delay_seconds FROM payment_message_refs WHERE purpose='staff' ORDER BY chat_id")->get_result()->fetch_all(MYSQLI_ASSOC)],JSON_THROW_ON_ERROR);
'''

def main():
    p=argparse.ArgumentParser()
    p.add_argument('--run-disposable',required=True,action='store_true')
    p.add_argument('--report',type=Path)
    args=p.parse_args();cases=[]
    with owned.owned_database(ROOT/'database/current-schema.sql') as (w,env):
        driver=w/'staff-projection.php';driver.write_text(DRIVER)
        def sql(q):return owned.sql_command(w,env,q,DB).strip()
        def run(**spec):
            r=subprocess.run(['php','-d','allow_url_fopen=0','-d','disable_functions=curl_exec,fsockopen,pfsockopen,stream_socket_client,exec,shell_exec,system,passthru,popen,proc_open',str(driver),str(ROOT),str(w/'mysql.sock'),DB,json.dumps(spec)],env=env,capture_output=True,text=True,timeout=20)
            assert r.returncode==0,(r.returncode,r.stderr,r.stdout)
            return json.loads(r.stdout)
        sql("INSERT INTO users(userid,name,username,refcode,wallet,date,isAdmin,first_start) VALUES(9003,'Fixture','fixture3','0',0,'1700000000',1,'sent'),(9004,'Fixture','fixture4','0',0,'1700000000',1,'sent')")
        mirrors=json.dumps([{'chat_id':9002,'message_id':72},{'chat_id':9003,'message_id':73},{'chat_id':9004,'message_id':74}])
        sql("INSERT INTO pays(id,hash_id,user_id,type,price,request_date,state,chat_id,message_id,admins_messages) VALUES(7701,'fixture-7701',1001,'INCREASE_WALLET',200,1700000000,'have_sent',9001,71,'"+mirrors+"')")
        r=run(modes={'9002':'missing','9003':'limited','9004':'wrong_ack'})
        assert r['edited']==1 and len(r['events'])==4,r
        rows={int(x['chat_id']):x for x in r['rows']}
        assert rows[9001]['synced_digest'] and rows[9002]['retired_reason']=='message_missing',r
        assert not rows[9003]['synced_digest'] and int(rows[9003]['delay_seconds'])>=57,r
        assert not rows[9004]['synced_digest'] and int(rows[9004]['delay_seconds'])>0,r
        cases+=['exact_ack','known_missing_target_retired','rate_limit_deferred','wrong_identity_not_acknowledged']
        r=run();assert not r['events'],r;cases.append('independent_target_retry_no_duplicate_healthy_edit')
        r=run(stale=True,label='Stale');assert not r['events'],r;cases.append('stale_payment_state_not_projected')
        sql("UPDATE payment_message_refs SET updated_at=NOW(6)-INTERVAL 1 SECOND WHERE purpose='staff'")
        sql("UPDATE users SET isAdmin=0 WHERE userid=9004")
        r=run(unowned=True);assert r['events']==[[9003,73]],r;cases+=['only_due_original_recipient_retried','revoked_staff_not_edited','arbitrary_message_not_registered']
        r=run(label='New status');assert r['events']==[[9001,71],[9003,73]],r;cases.append('new_status_converges_live_targets_only')
        r=run(caller_tx=True);assert r=={'blocked':True,'active':1,'events':[]},r;cases.append('caller_transaction_preserved')
        # Use the real notice entry, not a supplied stale keyboard.
        sql("INSERT INTO payment_attempts(pay_row_id,provider,order_hash,quote_amount,quote_currency,local_amount_toman,evidence_state) VALUES (7701,'bank_transfer','fixture-7701',200,'toman',200,'uncertain')")
        sql("UPDATE pays SET state='processing' WHERE id=7701")
        r=run(notice=True,busy=True);assert r['edited']==0 and not r['events'],r
        cases.append('original_payment_lease_prevents_stale_notice')
        r=run(notice=True);assert r['edited']==2 and r['keyboards'],r
        previous=r['keyboards']
        sql("UPDATE payment_attempts SET evidence_state='review' WHERE pay_row_id=7701")
        r=run(notice=True);assert r['edited']==2 and r['keyboards']!=previous,r
        assert not run(notice=True)['events']
        cases.append('notice_projects_current_committed_evidence_state')
        sql("UPDATE pays SET state='declined' WHERE id=7701")
        assert not run(notice=True)['events']
        cases.append('terminal_rejected_receipt_not_reopened')

    result={'complete':True,'status':'PASS','cases':len(cases),'scenarios':cases}
    if args.report:args.report.write_text(json.dumps(result,indent=2)+'\n')
    print(json.dumps(result))
if __name__=='__main__':main()
