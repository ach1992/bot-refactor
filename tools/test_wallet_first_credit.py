#!/usr/bin/env python3
"""Shared payment credit on actual PHP 8.5, owned MariaDB and loopback proof APIs.

Hosted inputs are authenticated-verifier result fixtures, not HTTP adapter
integration tests. No installed bot, merchant, Native or Telegram access.
"""
from __future__ import annotations

import argparse
from concurrent.futures import ThreadPoolExecutor
from datetime import datetime, timezone, timedelta
import json
from pathlib import Path
import subprocess
import threading

import database_fixture as owned
import test_post_v9_api_payment_evidence as evidence

ROOT = Path(__file__).resolve().parents[1]
DB = owned.NAMES[1]
PHP = evidence.DRIVER.replace(
    'require $argv[1];',
    'require $argv[1]; require_once dirname($argv[1])."/PaymentCredit.php";'
).replace(
    'mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);',
    'mysqli_report(($argv[5]??"strict")==="strict" ? MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT : MYSQLI_REPORT_OFF);'
).replace(
    "case 'save':\n $result=achbotPaymentEvidenceSaveGiftCode($db,$id,1001,$spec['code']);break;", r"""
case 'save':
 require_once dirname($argv[1]).'/ApiPayments.php';
 $token=achbotManualBeginEvidence($db,$pay,'gift_card',1001,$spec['code']);
 $result=['status'=>$token && achbotManualFinishEvidence($db,$pay['hash_id'],$spec['code'],$token,222,9001)?'saved':'invalid'];
 break;
"""
).replace(
    "case 'ready':", r"""
case 'issue':
case 'credit':
 try {
    if (!($spec['no_tx']??false) && !$db->begin_transaction())
        throw new RuntimeException('fixture_begin');
    if ($spec['op']==='issue') {
        if ($spec['api']??false) {
            if (!$p || !achbotPaymentEvidencePinLocked($db,$pay,$a,$p))
                throw new RuntimeException('fixture_evidence_pin');
        }
        achbotCreditPinIssuedLocked($db,$id,(int)($spec['user']??1001));
        $result=['ok'=>true];
    } else {
        $proof=$spec['proof']??[];
        if ($spec['stored_proof']??false) {
            $proof=achbotPaymentEvidenceGetProof($db,$id)??[];
            if ($spec['tamper']??false) $proof['proof_sha256']=str_repeat('0',64);
        }
        $entry=achbotPaymentCreditLocked($db,$id,(int)($spec['user']??1001),
            $spec['basis'],$proof,(int)($spec['actor']??0),9001);
        $result=['ok'=>true,'entry'=>$entry];
    }
    if ($spec['rollback']??false) {
        if (!$db->rollback()) throw new RuntimeException('fixture_rollback');
    } else {
        if (!$db->commit()) throw new RuntimeException('fixture_commit');
        if ($spec['lost_ack']??false) exit(19);
    }
 } catch (Throwable $e) {
    $db->rollback();
    $result=['ok'=>false,'reason'=>$e->getMessage()];
 }
 break;
case 'ready':"""
)


def main():
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument('--run-disposable', required=True, action='store_true')
    ap.add_argument('--report', type=Path)
    args = ap.parse_args()
    cases = []
    with owned.owned_database(ROOT/'database/current-schema.sql') as (work, env):
        client = ['mariadb', '--no-defaults', '--protocol=SOCKET',
                  '--socket='+str(work/'mysql.sock'), '-uroot', '--batch',
                  '--skip-column-names', DB]
        def sql(query):
            return subprocess.run(client, input="SET time_zone='+00:00';"+query,
                text=True, env=env, check=True, capture_output=True, timeout=15).stdout.strip()
        sql("INSERT INTO setting(type,value) VALUES ('BOT_STATES','{\"cartToCartAutoAcceptState\":\"on\",\"cartToCartAutoAcceptTime\":5,\"cartToCartAutoAcceptType\":2}')")
        server=evidence.Server(lambda:sql('SET innodb_lock_wait_timeout=1; UPDATE payment_attempts SET last_checked_at=NOW()'))
        thread=threading.Thread(target=server.serve_forever,daemon=True)
        thread.start()
        driver=work/'credit.php'
        driver.write_text(PHP)
        base='http://127.0.0.1:'+str(server.server_port)
        def php(op, pid, mode='strict', **spec):
            proc=subprocess.run(['php', '-n', '-d','extension=mysqlnd','-d','extension=mysqli',
                '-d','extension=curl','-d','display_errors=stderr',
                '-d','allow_url_fopen=0','-d','disable_functions=exec,shell_exec,system,passthru,popen,proc_open,mail',
                str(driver),str(ROOT/'src/Finance/PaymentEvidence.php'),str(work/'mysql.sock'),DB,
                json.dumps(dict(op=op,pay_id=pid,base=base,**spec)),mode],
                env=env, text=True, capture_output=True, timeout=25)
            if spec.get('lost_ack'):
                assert proc.returncode==19 and not proc.stdout,(proc.returncode,proc.stdout,proc.stderr)
                return None
            assert proc.returncode==0,(proc.returncode,proc.stderr)
            assert all(x.startswith('[AChBot] ') for x in proc.stderr.splitlines()),proc.stderr
            return json.loads(proc.stdout)['result']
        def money():
            return sql('SELECT userid,wallet FROM users ORDER BY id'),sql(
                'SELECT id,source_kind,source_ref,delta_toman FROM wallet_entries ORDER BY id')
        def balance():
            return int(sql('SELECT wallet FROM users WHERE userid=1001'))
        def linked(pid):
            return sql(f'SELECT COALESCE(wallet_credit_entry_id,0) FROM payment_attempts WHERE pay_row_id={pid}')
        def seed(method,name,api=False,admit=True,receipt=True,kind='INCREASE_WALLET'):
            hosted=method in ('nowpayments','nextpay','zarinpal')
            ref='invoice-'+name if hosted else None
            reference="'"+ref+"'" if ref else 'NULL'
            sql(f"""INSERT INTO pays(hash_id,user_id,type,price,request_date,state,payid)
                 VALUES ('{name}',1001,'{kind}',100001,UNIX_TIMESTAMP()-600,'pending',{reference})""")
            pid=int(sql(f"SELECT id FROM pays WHERE hash_id='{name}'"))
            intent={'v':1,'method':method}
            if method=='bank_transfer':intent.update(account='synthetic',holder='fixture')
            if method=='gift_card':intent.update(seller_url='https://fixture.invalid')
            if method=='manual_usdt':intent.update(network='ERC20',wallet='0x'+'1'*40)
            if method=='manual_trx':intent.update(network='TRON',wallet='TJRabPrwbZy45sbavfcjinPJC18kjpRTv8')
            currency='usd' if method=='nowpayments' else ('USDT' if method=='manual_usdt' else ('TRX' if method=='manual_trx' else 'toman'))
            amount='2.05' if currency.lower() in ('usd','usdt','trx') else '100001'
            checkout='https://fixture.invalid/checkout' if hosted else json.dumps(intent,separators=(',',':'))
            sql(f"""INSERT INTO payment_attempts(pay_row_id,provider,order_hash,provider_reference,
                 quote_amount,quote_currency,local_amount_toman,checkout_url,created_at,wallet_invoice_sha256)
                 VALUES ({pid},'{method}','{name}',{reference},{amount},'{currency}',100001,
                 '{checkout}',UTC_TIMESTAMP()-INTERVAL 10 MINUTE,{chr(39)+chr(39) if admit else 'NULL'})""")
            if admit:
                assert php('issue',pid,method=method,api=api)=={'ok':True}
            if receipt and not hosted:
                intent['receipt_file_id']='receipt-'+name
                payload=json.dumps(intent,separators=(',',':'))
                sql(f"""UPDATE pays SET state='have_sent',message_id=222,chat_id=9001 WHERE id={pid};
                     UPDATE payment_attempts SET stage='review',checkout_url='{payload}' WHERE pay_row_id={pid}""")
            proof=dict(payment_id='child-'+name,invoice_id=ref,order_id=name,
                       price_amount='2.05000000',price_currency='usd',payment_status='finished') if method=='nowpayments' else dict(
                       reference=ref,status=0 if method=='nextpay' else 100,amount=100001)
            return pid,proof
        def rejected(name,pid,mode,reason=None,**spec):
            before=money();link=linked(pid)
            r=php('credit',pid,mode,**spec)
            assert not r['ok'] and (reason is None or r['reason']==reason),(name,r)
            assert money()==before and linked(pid)==link,name
            cases.append(name+'_'+mode)
        def accept(name,pid,mode,**spec):
            before=balance()
            r=php('credit',pid,mode,**spec)
            assert r['ok'] and not r['entry']['replayed'],(name,r)
            assert balance()==before+100001 and linked(pid)==str(r['entry']['id']),name
            replay=php('credit',pid,mode,**spec)
            assert replay['ok'] and replay['entry']==dict(r['entry'],replayed=True),(name,replay)
            assert balance()==before+100001
            cases.append(name+'_'+mode)
        try:
            for mode in ('strict','off'):
                for method in ('bank_transfer','gift_card','manual_usdt','manual_trx'):
                    pid,_=seed(method,mode+'-'+method)
                    rejected('unauthorized_'+method,pid,mode,'payment_credit_manual_authority',basis='manual',actor=1001)
                    accept('manual_'+method,pid,mode,basis='manual',actor=9002)
                pid,_=seed('bank_transfer',mode+'-timer')
                sql(f'UPDATE pays SET request_date=UNIX_TIMESTAMP() WHERE id={pid}')
                rejected('retired_timer_not_due',pid,mode,'payment_credit_basis_invalid',basis='bank_timer')
                sql(f'UPDATE pays SET request_date=UNIX_TIMESTAMP()-600 WHERE id={pid}')
                rejected('retired_timer_due',pid,mode,'payment_credit_basis_invalid',basis='bank_timer')
                accept('retired_timer_requires_staff',pid,mode,basis='manual',actor=9002)
                pid,_=seed('bank_transfer',mode+'-missing',receipt=False)
                rejected('missing_receipt',pid,mode,'payment_credit_manual_receipt',basis='manual',actor=9001)
                pid,_=seed('bank_transfer',mode+'-history',admit=False)
                rejected('historical_unadmitted',pid,mode,'payment_credit_unadmitted_or_changed_invoice',basis='manual',actor=9001)
                pid,_=seed('bank_transfer',mode+'-old-pending',admit=False,receipt=False)
                assert php('issue',pid,mode,method='bank_transfer')=={'ok':False,'reason':'payment_credit_issue_not_new'}
                cases.append('no_historical_promotion_'+mode)
                pid,_=seed('bank_transfer',mode+'-drift')
                sql(f'UPDATE pays SET price=100002 WHERE id={pid}')
                rejected('changed_invoice',pid,mode,basis='manual',actor=9001)
                pid,_=seed('bank_transfer',mode+'-owner')
                rejected('foreign_owner',pid,mode,'payment_credit_owner_mismatch',basis='manual',actor=9001,user=9002)
                rejected('no_transaction',pid,mode,'wallet_transaction_required',basis='manual',actor=9001,no_tx=True)
                for method in ('nowpayments','nextpay','zarinpal'):
                    pid,proof=seed(method,mode+'-'+method)
                    spec=dict(basis=method,proof=proof)
                    bad=dict(proof)
                    if method=='nowpayments':
                        bad['payment_status']='partially_paid'
                    else:bad['status']=99
                    rejected('unverified_'+method,pid,mode,basis=method,proof=bad)
                    bad=dict(proof)
                    if method=='nowpayments':bad['price_amount']='2.04'
                    else:bad['amount']=100002
                    rejected('wrong_amount_'+method,pid,mode,basis=method,proof=bad)
                    before=money()
                    assert php('credit',pid,mode,rollback=True,**spec)['ok']
                    assert money()==before and linked(pid)=='0'
                    accept('hosted_'+method,pid,mode,**spec)
                    if method=='nowpayments':
                        old=balance()
                        later=dict(proof,payment_id='second-'+mode)
                        assert php('credit',pid,mode,basis=method,proof=later)['entry']['replayed']
                        assert balance()==old
                        assert sql(f'SELECT COUNT(*) FROM payment_transactions t JOIN payment_attempts a ON t.attempt_id=a.id WHERE a.pay_row_id={pid}')=='2'
                        cases.append('additional_verified_child_once_credit_'+mode)
                        other,op=seed(method,mode+'-other-child')
                        op['payment_id']=proof['payment_id']
                        rejected('cross_invoice_child',other,mode,'payment_credit_external_collision',basis=method,proof=op)
                pid,proof=seed('nowpayments',mode+'-late',kind='BUY_SUB')
                sql(f"UPDATE pays SET state='service_superseded' WHERE id={pid}")
                accept('late_superseded_service',pid,mode,basis='nowpayments',proof=proof)
                pid,_=seed('gift_card',mode+'-race')
                old=balance()
                with ThreadPoolExecutor(max_workers=4) as pool:
                    results=list(pool.map(lambda _:php('credit',pid,mode,basis='manual',actor=9001),range(4)))
                assert all(r['ok'] for r in results),results
                assert sum(not r['entry']['replayed'] for r in results)==1 and balance()==old+100001
                cases.append('concurrent_receipt_'+mode)
                pid,proof=seed('nextpay',mode+'-lostack')
                old=balance()
                php('credit',pid,mode,basis='nextpay',proof=proof,lost_ack=True)
                assert php('credit',pid,mode,basis='nextpay',proof=proof)['entry']['replayed']
                assert balance()==old+100001
                cases.append('lost_commit_ack_'+mode)
                pid,proof=seed('nextpay',mode+'-link-error')
                sql("CREATE TRIGGER fixture_link_failure BEFORE UPDATE ON payment_attempts FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture_link_failure'")
                try:rejected('link_failure',pid,mode,basis='nextpay',proof=proof)
                finally:sql('DROP TRIGGER fixture_link_failure')
                assert sql(f'SELECT COUNT(*) FROM payment_transactions t JOIN payment_attempts a ON a.id=t.attempt_id WHERE a.pay_row_id={pid}')=='0'
                accept('link_failure_retry',pid,mode,basis='nextpay',proof=proof)
                # The actual protected API evidence path, including HTTP
                # consumption, rather than directly fabricating proven rows.
                pid,_=seed('bank_transfer',mode+'-sms',api=True)
                rejected('api_timer_fallback',pid,mode,'payment_credit_basis_invalid',basis='bank_timer')
                rejected('no_api_proof',pid,mode,'payment_credit_api_proof',basis='api')
                now=datetime.now(timezone.utc).replace(microsecond=0)
                ident=100 if mode=='strict' else 200
                row=dict(id=ident,public_uid='UID'+str(ident),provider='BANK_FIXTURE',
                    provider_sms_id='provider'+str(ident),sender='BANK',mobile_source='phone',
                    bank_key='fixture',rule_id=7,amount=100001,display_unit='toman',
                    sms_date=now.strftime('%Y-%m-%d'),sms_time=now.strftime('%H:%M:%S'),status='ready')
                server.messages[str(ident)]=dict(row)
                assert php('reserve',pid,mode,row=row),pid
                assert php('consume',pid,mode)['status']=='proven'
                rejected('tampered_api_proof',pid,mode,'payment_credit_api_proof',basis='api',stored_proof=True,tamper=True)
                accept('consumed_sms_proven',pid,mode,basis='api',stored_proof=True)
                pid,_=seed('gift_card',mode+'-giftapi',api=True,receipt=False)
                code=evidence.fixture_gift_code(mode+'-gift')
                server.card=dict(card_uuid='CARD_'+mode,status='active',game_key='fixture',redeem_value_id=42,
                    redeem_amount=100001,display_amount=999999,face_value=10,currency_label='fixture',
                    order_id=1234,order_number='WEB-2026-0042',
                    order_created_at='2026-09-09T07:00:00+00:00',created_at='2026-09-10T08:05:00+00:00')
                assert php('save',pid,mode,method='gift_card',code=code)['status']=='saved'
                assert php('giftverify',pid,mode,method='gift_card')['status']=='active'
                assert php('giftreserve',pid,mode,method='gift_card',card=server.card)
                assert php('consume',pid,mode,method='gift_card')['status']=='proven'
                accept('consumed_gift_api',pid,mode,basis='api',stored_proof=True,method='gift_card')
            assert sql('SELECT COUNT(*) FROM wallet_entries WHERE balance_after<>balance_before+delta_toman')=='0'
            assert sql("SELECT COUNT(*) FROM wallet_entries w LEFT JOIN payment_attempts a ON a.wallet_credit_entry_id=w.id WHERE a.id IS NULL")=='0'
            assert sum(server.consumes.values())==2 and server.gift_consumes==2
        finally:
            server.shutdown();server.server_close();thread.join(timeout=5)
    report=dict(complete=True,cases=len(cases),cases_detail=cases,native_php_mysqli=True,
                owned_socket_only=True,loopback_evidence=True,hosted_verified_response_fixtures=True,production_effects=0)
    if args.report:args.report.write_text(json.dumps(report,indent=2)+'\n')
    print(json.dumps(report))


if __name__=='__main__':main()
