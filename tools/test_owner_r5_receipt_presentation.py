#!/usr/bin/env python3
"""Pinned manual-quote presentation: real PHP/owned MariaDB, no external calls."""
import argparse
import hashlib
import json
import subprocess
import tarfile
import io
from pathlib import Path
import test_access_state as owned
import test_v8_native as purchase
import test_post_v9_manual_payments as manual

ROOT=Path(__file__).resolve().parents[1]
BASE='64feca4b777a442023676122e315c68ad26e902d'
KEYS=['buy_custom_account_request','buy_new_account_request',
      'buy_new_much_account_request','renew_account_request_message',
      'increase_account_request_message','increase_wallet_request_message']
PHP=r'''<?php
error_reporting(E_ALL);
set_error_handler(static function($severity,$message){throw new ErrorException($message,0,$severity);});
$_SERVER['SCRIPT_FILENAME']=__FILE__;
$root=$argv[1];$db=new mysqli('localhost','root','',$argv[3],0,$argv[2]);
$db->set_charset('utf8mb4');
require $root.'/src/Finance/ReceiptHelpers.php';
require_once $root.'/messages/loader.php';
$s=$db->prepare('SELECT * FROM pays WHERE hash_id=?');$s->bind_param('s',$argv[4]);
$s->execute();$pay=$s->get_result()->fetch_assoc();$s->close();
$templates=achbotMessages('legacy_messages');$key=$argv[5];
$template=$templates[$key];$composed=achbotManualCaption($db,$pay,$template);
$vars=['USER-ID'=>'1001','USERNAME'=>'fixture_user','NAME'=>($argv[6]??'کاربر آزمایشی'),
'PRICE'=>(string)$pay['price'],'TYPE'=>'بانکی','REMARK'=>'اشتراک نمونه',
'SERVERNAME'=>'سرور نمونه','ACCOUNT-COUNT'=>'3','VOLUME'=>'10','DAYS'=>'30','INCREASE'=>'5'];
echo json_encode(['template'=>$template,'composed'=>$composed,'rendered'=>str_replace(array_keys($vars),array_values($vars),$composed)],
JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
'''
def main():
    ap=argparse.ArgumentParser()
    ap.add_argument('--run-disposable',action='store_true',required=True)
    ap.add_argument('--report',type=Path)
    args=ap.parse_args();cases=[]
    with owned.owned_database() as (work,env):
        purchase.init(work,env,'http://127.0.0.1:9')
        sql=lambda s:manual.query(work,env,s)
        sql((ROOT/'migrations/post-v9/003_payment_attempts.sql').read_text())
        sql("INSERT INTO setting(type,value) VALUES ('BOT_STATES','{\"walletState\":\"on\",\"cartToCartState\":\"on\",\"giftCardState\":\"on\",\"manualUsdtState\":\"on\"}')")
        manual_driver=work/'claim.php';manual_driver.write_text(manual.PHP)
        driver=work/'caption.php';driver.write_text(PHP)
        def caption(hash_id,key,root=ROOT,name='کاربر آزمایشی'):
            r=subprocess.run(['php','-d','allow_url_fopen=0',str(driver),str(root),
                str(work/'mysql.sock'),manual.DB,hash_id,key,name],capture_output=True,text=True,env=env,timeout=15)
            assert r.returncode==0 and not r.stderr,(r.returncode,r.stderr,r.stdout)
            return json.loads(r.stdout)
        def row_fingerprint():
            pieces=[sql('SELECT * FROM '+t+' ORDER BY id') for t in
                    ['users','pays','payment_attempts']]
            return hashlib.sha256('\n'.join(pieces).encode()).hexdigest()
        manual.seed(work,env,'r5bank')
        assert manual.run_php(work,env,manual_driver,'r5bank','claim') is True
        quoted=sql("SELECT quote_amount FROM payment_attempts WHERE order_hash='r5bank'").split('.')[0]
        before=row_fingerprint()
        for key in KEYS:
            value=caption('r5bank',key)
            lines=value['composed'].splitlines()
            price_line=next(i for i,l in enumerate(lines) if 'PRICE' in l)
            exact_line=next(i for i,l in enumerate(lines) if 'مبلغ دقیق حواله' in l)
            assert exact_line==price_line+1,(key,lines)
            assert f'{int(quoted):,}' in lines[exact_line],(key,lines)
            for removed in ['روش پرداخت:','مقصد:','سفارش:','شناسه تلگرام:']:
                assert removed not in value['composed'],(key,removed)
            assert 'MANUAL-QUOTE' not in value['rendered']
            assert 'شناسه عددی' in value['rendered']
            cases.append('pinned_bank_quote_after_paid_line_'+key)
        tricky=caption('r5bank','buy_new_account_request',name='PRICE NAME USER-ID')
        actual=tricky['rendered'].splitlines()
        quote_at=next(i for i,l in enumerate(actual) if 'مبلغ دقیق حواله' in l)
        paid_at=next(i for i,l in enumerate(actual) if 'مبلغ پرداختی' in l)
        assert quote_at==paid_at+1
        assert sum('مبلغ دقیق حواله' in line for line in actual)==1
        cases.append('actual_sequential_replacement_customer_parser_words_do_not_move_pinned_quote')
        assert row_fingerprint()==before
        cases.append('caption_reads_do_not_change_users_pay_or_attempt_rows')
        # Original source produces the rejected duplicate metadata header.
        baseline=work/'receipt-baseline';baseline.mkdir()
        archive=subprocess.check_output(['git','-C',str(ROOT),'archive',BASE])
        with tarfile.open(fileobj=io.BytesIO(archive)) as tar:
            tar.extractall(baseline,filter='data')
        old=caption('r5bank','buy_new_account_request',baseline)
        oldlines=old['composed'].splitlines()
        oldprice=next(i for i,l in enumerate(oldlines) if 'PRICE' in l)
        oldquote=next(i for i,l in enumerate(oldlines) if 'مبلغ دقیق حواله' in l)
        assert oldquote!=oldprice+1 and 'روش پرداخت:' in old['composed']
        cases.append('archived_original_source_RED_duplicate_header_and_wrong_quote_position')
        # Other pinned methods keep the useful asset/network proof, not destination.
        for method in ['gift_card','manual_usdt','manual_trx']:
            h='r5'+method.replace('_','')
            manual.seed(work,env,h)
            opts={'network':'BEP20'} if method=='manual_usdt' else ({'network':'TRON','keys':{'manualTrxWallet':'TJRabPrwbZy45sbavfcjinPJC18kjpRTv8','manualTrxRateToman':'15000'}} if method=='manual_trx' else {})
            assert manual.run_php(work,env,manual_driver,h,'claim',method=method,**opts) is True
            before=row_fingerprint();v=caption(h,'buy_new_account_request')
            assert all(x not in v['composed'] for x in ['روش پرداخت:','مقصد:','آدرس:'])
            if method=='gift_card':
                assert 'مبلغ دقیق حواله' not in v['composed'] and 'مبلغ رمزارز' not in v['composed']
            else:
                ls=v['composed'].splitlines();at=next(i for i,l in enumerate(ls) if 'PRICE' in l)
                assert 'مبلغ رمزارز' in ls[at+1]
                assert ('TRX' if method=='manual_trx' else 'USDT') in ls[at+1]
                assert ('TRON' if method=='manual_trx' else 'BEP20') in ls[at+1]
            assert row_fingerprint()==before
            cases.append('original_pinned_'+method+'_proof_and_no_destination_header')
        # An absent quote must never invent a display amount.
        sql("DELETE FROM payment_attempts WHERE order_hash='r5bank'")
        v=caption('r5bank','buy_new_account_request')
        assert 'مبلغ دقیق حواله' not in v['composed'] and 'PRICE' in v['composed']
        cases.append('missing_quote_no_fabricated_amount_or_metadata')
    report={'complete':True,'cases':len(cases),'names':cases,'source_real':True,
            'owned_original_schema_mariadb':True,'archived_baseline':BASE,
            'baseline_red':True,'external_calls':0,'financial_mutations_by_caption':0}
    if args.report:args.report.write_text(json.dumps(report,ensure_ascii=False,indent=2)+'\n')
    print(json.dumps(report,ensure_ascii=False))
if __name__=='__main__':main()
