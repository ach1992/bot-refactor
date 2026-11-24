#!/usr/bin/env python3
"""Single empty install, retained schema equivalence, and current runtime gates."""
import argparse
import json
from pathlib import Path
import re
import subprocess

import test_access_state as owned

ROOT=Path(__file__).resolve().parents[1]
RETIRED={'admins','black_list','servers','bank_buy_invoice_claims','automatic_renewal_attempts'}
PHP=r'''<?php
mysqli_report($argv[3]==='strict'?MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT:MYSQLI_REPORT_OFF);
$GLOBALS['telegramWebhookSecret']='Synthetic_Fresh_Schema_Only_2026';
require $argv[1].'/src/Finance/CheckoutAdmission.php';
require_once $argv[1].'/src/Finance/ServiceDelivery.php';
require_once $argv[1].'/src/Finance/FollowupOutbox.php';
require_once $argv[1].'/src/Services/EditFinalization.php';
$db=new mysqli('localhost','root','','fresh_single',0,$argv[2]);
if (($argv[4]??'')==='missing_followup_index') {
    echo achbotFollowupSchemaReady($db)?'UNSAFE':'REJECTED';exit;
}
achbotCheckoutRequireSchema($db);
achbotProviderRequireSchema($db);
foreach (['achbotWalletLedgerReady','achbotPaymentEvidenceSchemaReady','achbotServiceDeliverySchemaReady',
    'achbotFollowupSchemaReady','achbotServiceMutationSchemaReady','achbotAddonSchema'] as $check) {
    if (!$check($db)) throw new RuntimeException($check);
}
echo 'PASS';
'''

def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--run-disposable',action='store_true',required=True)
    parser.add_argument('--report',type=Path)
    args=parser.parse_args()
    migrations=sorted((ROOT/'migrations/post-v9').glob('[0-9][0-9][0-9]_*.sql'))
    checks=[]
    with owned.owned_database() as (work,env):
        cli=['mariadb','--no-defaults','--protocol=SOCKET','--socket='+str(work/'mysql.sock'),
             '-uroot','--batch','--skip-column-names']
        def sql(db,q):
            return subprocess.run(cli+([db] if db else []),input=q,text=True,env=env,
                capture_output=True,check=True,timeout=30).stdout.strip()
        sql(None,'CREATE DATABASE fresh_chain CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE DATABASE fresh_single CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci')
        sql('fresh_chain',(ROOT/'database/legacy-schema.sql').read_text())
        for migration in migrations:
            sql('fresh_chain',migration.read_text())
        sql('fresh_single',(ROOT/'database/current-schema.sql').read_text())
        old=set(sql('fresh_chain','SHOW TABLES').splitlines())
        new=set(sql('fresh_single','SHOW TABLES').splitlines())
        assert RETIRED<=old and new==old-RETIRED,(old-new,new-old)
        checks.append('only_retired_tables_omitted')
        for table in sorted(new):
            def definition(db):
                return re.sub(r' AUTO_INCREMENT=[0-9]+','',sql(db,'SHOW CREATE TABLE `'+table+'`'))
            assert definition('fresh_chain')==definition('fresh_single'),table
            assert sql('fresh_single','SELECT COUNT(*) FROM `'+table+'`')=='0',table
            checks.append('empty_and_exact:'+table)
        assert sql('fresh_single',"SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='service_operations' AND COLUMN_NAME='historical_pay_id'")=='0'
        assert sql('fresh_single',"SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='userid' AND NON_UNIQUE=0")!='0'
        checks+=['no_historical_funding_column','unique_wallet_owner']
        retired_columns=['review_notified_at', 'evidence_review_claim_token', 'evidence_review_claimed_at', 'evidence_report_digest', 'evidence_fulfillment_state', 'evidence_fulfillment_payload','review_archived_at','review_archived_by']
        for column in retired_columns:
            assert sql('fresh_single',"SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payment_attempts' AND COLUMN_NAME='"+column+"'")== '0',column
            checks.append('retired_payment_column:'+column)
        path=work/'fresh.php';path.write_text(PHP)
        for mode in ('strict','off'):
            p=subprocess.run(['php',str(path),str(ROOT),str(work/'mysql.sock'),mode],
                env=env,capture_output=True,text=True,timeout=20)
            assert p.returncode==0 and p.stdout=='PASS' and not p.stderr,(p.stdout,p.stderr)
            checks.append(mode+':current_schema_gates')
        for table,key,columns in (
            ('payment_followup_intents','uq_followup_wallet_key','wallet_entry_id,intent_key'),
            ('payment_followup_attempts','uq_followup_attempt_no','intent_id,attempt_no'),
        ):
            sql('fresh_single',f'ALTER TABLE {table} ADD INDEX fixture_fk ({columns}); ALTER TABLE {table} DROP INDEX {key}')
            for mode in ('strict','off'):
                p=subprocess.run(['php',str(path),str(ROOT),str(work/'mysql.sock'),mode,'missing_followup_index'],
                    env=env,capture_output=True,text=True,timeout=20)
                assert p.returncode==0 and p.stdout=='REJECTED' and not p.stderr,(p.stdout,p.stderr)
                checks.append(mode+':missing_'+key+'_denied')
            sql('fresh_single',f'ALTER TABLE {table} ADD UNIQUE INDEX {key} ({columns}); ALTER TABLE {table} DROP INDEX fixture_fk')
    result=dict(complete=True,status='PASS',cases=len(checks),tables=len(new),
                migrations=len(migrations),checks=checks,external_requests=0)
    if args.report:args.report.write_text(json.dumps(result,indent=2)+'\n')
    print(json.dumps(result))
if __name__=='__main__':main()
