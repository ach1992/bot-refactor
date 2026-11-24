#!/usr/bin/env python3
"""Prove the old schema and provider cannot activate the paid runtime.

Actual PHP and disposable socket MariaDB, both MySQLi report modes.
Historical files in Git are not executable runtime members.
"""
import argparse
import json
from pathlib import Path
import subprocess
import package_current as package
import test_access_state as owned

ROOT=Path(__file__).resolve().parents[1]
PHP=r'''<?php
declare(strict_types=1);
mysqli_report($argv[4]==='strict'?MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT:MYSQLI_REPORT_OFF);
require $argv[1].'/src/Finance/CheckoutAdmission.php';
$db=new mysqli('localhost','root','',$argv[3],0,$argv[2]);
$checks=[];
function check(bool $pass,string $name): void {
    if (!$pass) throw new RuntimeException($name);
    $GLOBALS['checks'][]=$name;
}
$registry=achbotProviderRegistry();
check(achbotProviderRegistryValid($registry) && array_keys($registry)===['pasarguard'],'single_current_provider');
check(!achbotSupportsProviderType('marzban') && !achbotSupportsProviderType('xui'),'retired_and_unknown_provider_denied');
foreach (achbotProviderRequiredOperations() as $op) {
    $bad=$registry['pasarguard'];unset($bad[$op]);
    check(!achbotProviderRegistryEntryValid('pasarguard',$bad),'missing_required_'.$op);
}
foreach (['BUY','RENEW','ADD_TIME','ADD_VOLUME'] as $kind) {
    $bad=$registry['pasarguard'];unset($bad['service_finalization'][$kind]);
    check(!achbotProviderRegistryEntryValid('pasarguard',$bad),'incomplete_service_'.$kind);
}
$bad=$registry['pasarguard'];unset($bad['service_target']);
check(!achbotProviderRegistryEntryValid('pasarguard',$bad),'missing_service_target');
check(!achbotProviderRegistryEntryValid("pg' OR 1=1",$registry['pasarguard']),'invalid_registry_key');
try {achbotCheckoutRequireSchema($db);$reason='accepted';}
catch(Throwable $e){$reason=$e->getMessage();}
check($reason===$argv[5],'schema_'.$reason);
try {achbotServiceOperationsPresent($db);$ownership=true;}
catch(Throwable $e){$ownership=false;}
check($ownership===($argv[6]!=='missing'),'physical_current_ownership_schema_gate');
if ($reason!=='accepted') {
    try {achbotCheckoutWallet($db,'never-issue',1001,'standard',[]);$reason='accepted';}
    catch(Throwable $e){$reason=$e->getMessage();}
    check($reason===$argv[5],'wallet_schema_gate');
}
echo json_encode($checks,JSON_THROW_ON_ERROR);
'''

def main():
    ap=argparse.ArgumentParser(description=__doc__)
    ap.add_argument('--run-disposable',action='store_true',required=True)
    ap.add_argument('--report',type=Path)
    args=ap.parse_args()
    checks=[]
    runtime=set(package.members())
    retired={'src/Finance/BankPurchase.php','src/Finance/ManualEditRecovery.php',
        'src/Finance/PaidAccountRecovery.php','src/Finance/RecoveryInspection.php',
        'src/Finance/RecoveryTransport.php','src/Finance/RenewalHelper.php',
        'src/Finance/Routes/RenewalPlan.php','src/Finance/Routes/RenewalSelection.php',
        'src/Finance/WalletAddonNotices.php','src/Finance/WalletEditRecovery.php',
        'src/Finance/WalletOriginalRecovery.php','src/Finance/WalletRenewalRecovery.php',
        'src/Finance/WalletSettlement.php','src/Services/HistoricalFunding.php','src/Panel/Accounts.php','src/Panel/MarzbanPlanAccess.php'}
    assert not (retired & runtime)
    checks.append('old_executors_absent_from_runtime')
    for path in runtime:
        source=(ROOT/path).read_text()
        for old_quote in ('achbotServicePaymentJournalClear','achbotUnissuedServiceOrderActive',
                          'achbotSupportsPaymentSubject','achbotProviderQuoteEligible',
                          'achbotPasarGuardQuoteAdapter','achbotPgQuote'):
            assert old_quote+'(' not in source,(path,old_quote)
        for old in retired:
            # Include/require targets cannot refer to removed files.
            assert not any(('/'+old.rsplit('/',1)[1]) in line for line in source.splitlines()
                           if 'require' in line and '.php' in line),(path,old)
    checks.append('no_retired_runtime_require')
    with owned.owned_database() as (work,env):
        db=owned.NAMES[1]
        cli=['mariadb','--no-defaults','--protocol=SOCKET','--socket='+str(work/'mysql.sock'),'-uroot','--batch','--skip-column-names',db]
        def sql(q):
            return subprocess.run(cli,input=q,text=True,env=env,capture_output=True,timeout=20,check=True).stdout.strip()
        def migrate(name):
            sql((ROOT/'migrations/post-v9'/name).read_text())
        driver=work/'retirement.php';driver.write_text(PHP)
        def run(expect,ledger='current'):
            before=sql('SELECT userid,wallet FROM users ORDER BY id; SELECT COUNT(*) FROM pays')
            for mode in ('strict','off'):
                p=subprocess.run(['php','-n','-d','extension=mysqlnd','-d','extension=mysqli',
                    str(driver),str(ROOT),str(work/'mysql.sock'),db,mode,expect,ledger],env=env,
                    capture_output=True,text=True,timeout=20)
                assert p.returncode==0 and not p.stderr,(p.returncode,p.stdout,p.stderr)
                checks.extend(mode+':'+expect+':'+n for n in json.loads(p.stdout))
            assert sql('SELECT userid,wallet FROM users ORDER BY id; SELECT COUNT(*) FROM pays')==before
        run('checkout_command_schema_missing','missing')
        for m in ('001_marzban_panel_group_id.sql','003_payment_attempts.sql','004_automatic_renewal_attempts.sql',
                  '007_service_effects.sql','011_provider_identity.sql','012_shared_provider_storage.sql',
                  '014_api_payment_evidence.sql','022_gateway_review_archive.sql','023_scoped_payment_lock_indexes.sql',
                  '024_wallet_entries.sql','025_wallet_receipt_credit.sql','026_service_operations.sql','035_service_resolution.sql'):
            migrate(m)
        run('checkout_command_schema_missing')
        # Exercise each auto-committing 033 DDL boundary, not merely a mock.
        ddl='\n'.join(line for line in (ROOT/'migrations/post-v9/033_checkout_commands.sql').read_text().splitlines() if not line.lstrip().startswith('--'))
        statements=[part.strip()+';' for part in ddl.split(';') if part.strip() and not all(
            not line.strip() or line.lstrip().startswith('--') for line in part.splitlines())]
        for i,statement in enumerate(statements):
            sql(statement)
            if i==0:
                # The first ALTER owns the FK/index/context, but not merchant timestamps.
                run('checkout_command_schema_partial')
        run('accepted')
        sql('ALTER TABLE pays ADD INDEX fixture_checkout_fk(checkout_operation_id); ALTER TABLE pays DROP INDEX uq_checkout_operation, ADD INDEX uq_checkout_operation(checkout_operation_id)')
        run('checkout_command_schema_invalid')
    result=dict(complete=True,cases=len(checks),checks=checks,external_requests=0)
    if args.report:args.report.write_text(json.dumps(result,indent=2)+'\n')
    print(json.dumps(result))
if __name__=='__main__':main()
