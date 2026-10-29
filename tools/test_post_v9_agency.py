#!/usr/bin/env python3
"""Post-V9 Issue #12 agency authorization/state regression on owned MariaDB only.

Run actual current src/Agent/Applications.php against the original 23-table
schema. Telegram is mocked, external network APIs are disabled, and the
source's unchanged setUser helper is extracted from config.php. Nothing
contacts a production database, webhook, provider, or payment service.
"""
from __future__ import annotations

import argparse
import concurrent.futures
import json
import subprocess
import threading
from pathlib import Path

import test_access_state as owned
import test_v6_native as native

ROOT = Path(__file__).resolve().parents[1]
DB = owned.NAMES[1]

PHP_DRIVER = r"""<?php
error_reporting(E_ALL);
set_error_handler(function($level, $message) {
    throw new ErrorException($message, 0, $level);
});
$_SERVER['SCRIPT_FILENAME'] = __FILE__;
$root = $argv[1];
$socket = $argv[2];
$dbname = $argv[3];
$spec = json_decode($argv[4], true, 512, JSON_THROW_ON_ERROR);
$connection = new mysqli('localhost', 'root', '', $dbname, 0, $socket);
$connection->set_charset('utf8mb4');
$from_id = (int)$spec['actor'];
$admin = 9001;
$data = (string)($spec['data'] ?? '');
$text = (string)($spec['text'] ?? '');
$message_id = 77;
$time = 1800000000;
$username = 'fixture';
$first_name = 'Fixture';
$botState = ['rewardChannel' => null];
$buttonValues = [
    'approve' => 'Approve', 'decline' => 'Decline', 'approved' => 'Approved',
    'declined' => 'Declined', 'cancel' => 'Cancel'
];
$mainValues = [
    'agent_setting_message' => 'settings',
    'request_agency_message' => 'USERNAME NAME USERID',
    'agency_request_sent' => 'requested',
    'agency_request_already_sent' => 'already pending',
    'agency_request_declined' => 'declined',
    'agency_request_approved' => 'approved',
    'send_agent_discount_percent' => 'enter percent',
    'saved_successfuly' => 'saved',
    'send_only_number' => 'number only',
    'config_not_found' => 'invalid or stale',
];
$cancelKey = 'cancel';
$GLOBALS['events'] = [];
function getAgentKeys() { return 'agent'; }
function getMainKeys() { return 'main'; }
function alert($value) { $GLOBALS['events'][] = ['alert', $value]; }
function editText(...$args) { $GLOBALS['events'][] = ['editText', $args]; }
function editKeys(...$args) { $GLOBALS['events'][] = ['editKeys', $args]; }
function sendMessage(...$args) {
    $GLOBALS['events'][] = ['sendMessage', $args];
}
$error = null;
try {
    $stmt = $connection->prepare('SELECT * FROM users WHERE userid = ? LIMIT 1');
    $stmt->bind_param('i', $from_id);
    $stmt->execute();
    $userInfo = $stmt->get_result()->fetch_assoc() ??
        ['isAdmin' => 0, 'is_agent' => 0, 'step' => 'none'];
    $stmt->close();
    require $argv[5]; // Extracted ORIGINAL setUser only; no production config.
    require $root . '/src/Agent/Applications.php';
} catch (Throwable $e) {
    $error = get_class($e) . ':' . $e->getMessage();
    // Source-origin location is safe in this synthetic fixture and identifies
    // which exact SQL site caused any future CI concurrency victim.
    if ($e instanceof mysqli_sql_exception)
        $error .= ' code='.(int)$e->getCode().' site='.basename($e->getFile()).':'.$e->getLine();
}
echo json_encode(['error' => $error, 'events' => $GLOBALS['events']],
                 JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
"""

def sql(work: Path, env: dict, query: str) -> str:
    return native.sql(work, env, query, DB)

def agency_test_ddl(work: Path, env: dict, ddl: str) -> None:
    # mariadb -e splits semicolons inside BEGIN/END; pass this single bounded
    # synthetic DDL statement through mysqli so the whole trigger is atomic.
    php='$c=new mysqli("localhost","root","",$argv[1],0,$argv[2]);$c->query($argv[3]);'
    result=subprocess.run(['php','-n','-d','extension=mysqlnd',
                           '-d','extension=mysqli','-r',php,
                           DB,str(work/'mysql.sock'),ddl],
                          cwd=work,env=env,capture_output=True,timeout=10)
    if result.returncode or result.stderr:
        raise RuntimeError('Owned trigger fixture DDL failed: '+
                           repr((result.returncode,result.stderr[-400:])))

def field(work: Path, env: dict, userid: int, column: str) -> str:
    if column not in ('is_agent', 'isAdmin', 'step', 'discount_percent'):
        raise ValueError('unapproved test column')
    return sql(work, env, f'SELECT {column} FROM users WHERE userid={userid} ORDER BY id LIMIT 1').strip()

def state(work: Path, env: dict) -> str:
    return sql(work, env, '''SELECT id,userid,isAdmin,is_agent,step,
      COALESCE(discount_percent, 'null'),agent_date FROM users ORDER BY id''')

def execute(work: Path, env: dict, spec: dict) -> dict:
    disable = ('curl_init,curl_exec,curl_setopt,curl_close,curl_multi_exec,'
               'fsockopen,pfsockopen,stream_socket_client,file_get_contents,'
               'shell_exec,exec,system,passthru,popen,proc_open,mail')
    cmd = ['php', '-n', '-d', 'extension=mysqlnd', '-d', 'extension=mysqli',
           '-d', 'allow_url_fopen=0', '-d', 'allow_url_include=0',
           '-d', 'disable_functions=' + disable,
           str(work / 'agency-driver.php'), str(ROOT),
           str(work / 'mysql.sock'), DB, json.dumps(spec),
           str(work / 'set-user.php')]
    result = subprocess.run(cmd, cwd=work, env=env, capture_output=True, timeout=20)
    if result.returncode or result.stderr:
        raise RuntimeError('Native PHP failure: ' +
                           repr((result.returncode, result.stdout[-400:], result.stderr[-500:])))
    try:
        parsed = json.loads(result.stdout)
    except ValueError as exc:
        raise RuntimeError('Invalid native PHP output: ' + repr(result.stdout[-800:])) from exc
    if parsed.get('error'):
        raise RuntimeError('Current agency handler error: ' + str(parsed['error']))
    return parsed

def outgoing(results: list[dict], target: int) -> int:
    return sum(1 for result in results for event in result['events']
               if event[0] == 'sendMessage' and len(event[1]) > 3
               and event[1][3] == target)

def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument('--run-disposable', action='store_true', required=True)
    parser.add_argument('--report', type=Path)
    args = parser.parse_args()
    checks = []
    def passed(name: str, condition: bool) -> None:
        if not condition:
            raise RuntimeError('Agency regression: ' + name)
        checks.append(name)

    with owned.owned_database() as (work, env):
        (work / 'agency-driver.php').write_text(PHP_DRIVER)
        extract = ("require $argv[1]; echo '<?php'.PHP_EOL."
                   "achbotExtractFunction($argv[2],'setUser');")
        original_writer = subprocess.check_output(
            ['php', '-n', '-d', 'extension=tokenizer', '-r', extract,
             str(ROOT / 'tools/source_scan.php'), str(ROOT / 'config.php')],
            cwd=work, env=env, timeout=10)
        (work / 'set-user.php').write_bytes(original_writer)

        tables = sql(work, env, 'SHOW TABLES').splitlines()
        passed('original_23_table_schema', len(tables) == 23)
        sql(work, env, """
            INSERT INTO users (userid,name,username,refcode,wallet,date,isAdmin,first_start)
            VALUES (1003,'Second applicant','second','0',0,'1700000000',0,'sent');
            UPDATE users SET is_agent=2 WHERE userid=1003;
        """)
        run = lambda actor, data='', text='': execute(work, env,
            {'actor': actor, 'data': data, 'text': text})
        sql(work, env, 'UPDATE users SET is_agent=0,step="none" WHERE userid=1001')
        requested = run(1001, 'requestAgency')
        passed('existing_request_to_pending', field(work, env, 1001, 'is_agent') == '2'
               and outgoing([requested], 9001) == 1)

        baseline = state(work, env)
        denied = run(1003, 'agencyDecline1001')
        # The real access guard now sends one actor-local denial. No explicit
        # destination may turn this notice into an applicant notification.
        passed('nonadmin_decline_denied', state(work, env) == baseline
               and denied['events'] == [['sendMessage',
                   ['این بخش برای سطح دسترسی شما فعال نیست.', None, 'HTML']]])
        for malformed in ('agencyDecline1001extra', 'agencyDecline0', 'agencyDecline-1',
                          'agencyDecline999999999999999999999999999999',
                          'agencyDecline1001\n'):
            result = run(9001, malformed)
            passed('malformed_' + repr(malformed), state(work, env) == baseline
                   and outgoing([result], 1001) == 0)
        missing = run(9001, 'agencyDecline7654321')
        passed('unknown_applicant_denied', state(work, env) == baseline
               and outgoing([missing], 7654321) == 0)

        declined = run(9001, 'agencyDecline1001')
        passed('decline_updates_only_pending_applicant',
               field(work, env, 1001, 'is_agent') == '-1'
               and field(work, env, 9001, 'is_agent') == '0'
               and field(work, env, 9002, 'is_agent') == '0'
               and outgoing([declined], 1001) == 1)
        declined_state = state(work, env)
        replay = run(9001, 'agencyDecline1001')
        passed('decline_replay_no_side_effects',
               state(work, env) == declined_state and outgoing([replay], 1001) == 0
               and not any(e[0] == 'editKeys' for e in replay['events']))
        sql(work, env, 'UPDATE users SET is_agent=1 WHERE userid=1001')
        admin_approved_decline = run(9001, 'agencyDecline1001')
        passed('approved_applicant_cannot_be_declined',
               field(work, env, 1001, 'is_agent') == '1'
               and outgoing([admin_approved_decline], 1001) == 0)
        sql(work, env, 'UPDATE users SET is_agent=2 WHERE userid=1001')
        delegated = run(9002, 'agencyDecline1001')
        passed('delegated_admin_can_decline_without_self_mutation',
               field(work, env, 1001, 'is_agent') == '-1'
               and field(work, env, 9002, 'is_agent') == '0'
               and outgoing([delegated], 1001) == 1)

        sql(work, env, 'UPDATE users SET is_agent=2,discount_percent=NULL WHERE userid=1001')
        start = run(9001, 'agencyApprove1001')
        passed('approve_start_pending_only',
               field(work, env, 9001, 'step') == 'agencyApprove1001_77'
               and field(work, env, 1001, 'is_agent') == '2'
               and any(e[0] == 'sendMessage' for e in start['events']))
        approved = run(9001, text='12')
        passed('approve_save_conditional_target',
               field(work, env, 1001, 'is_agent') == '1'
               and field(work, env, 9001, 'is_agent') == '0'
               and field(work, env, 9001, 'step') == 'none'
               and json.loads(field(work, env, 1001, 'discount_percent')) == {'normal': '12'}
               and outgoing([approved], 1001) == 1)
        replay_approval = run(9001, 'agencyApprove1001')
        passed('stale_approve_start_denied',
               field(work, env, 9001, 'step') == 'none'
               and outgoing([replay_approval], 1001) == 0)
        sql(work, env, "UPDATE users SET is_agent=2 WHERE userid=1001")
        run(9001, 'agencyApprove1001')
        run(9002, 'agencyDecline1001')
        stale_save = run(9001, text='15')
        passed('decline_wins_over_stale_approve_save',
               field(work, env, 1001, 'is_agent') == '-1'
               and field(work, env, 9001, 'step') == 'none'
               and outgoing([stale_save], 1001) == 0)
        sql(work, env, "UPDATE users SET is_agent=2,step='agencyApprove1001_77' WHERE userid=1001")
        before_spoof = state(work, env)
        spoof = run(1001, text='20')
        passed('nonadmin_cannot_save_spoofed_approval_step',
               state(work, env) == before_spoof and outgoing([spoof], 1001) == 0)
        sql(work, env, "UPDATE users SET step='none' WHERE userid=1001")

        sql(work, env, """UPDATE users SET is_agent=2 WHERE userid=1001;
            UPDATE users SET step='agencyApprove1001_77' WHERE userid IN (9001,9002)""")
        # Make callbacks genuinely enter at the same time rather than
        # accidentally serializing process launch on a fast runner.
        start_barrier=threading.Barrier(2)
        def concurrent_save(actor):
            start_barrier.wait(timeout=6)
            return run(actor, '', '12')
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
            futures = [pool.submit(concurrent_save, actor) for actor in (9001,9002)]
            competing = [future.result() for future in futures]
        passed('concurrent_admin_approval_exactly_one',
               field(work, env, 1001, 'is_agent') == '1'
               and outgoing(competing, 1001) == 1
               and field(work, env, 9001, 'is_agent') == '0'
               and field(work, env, 9002, 'is_agent') == '0')

        # V1 exact-head PHP8.4 CI saw mysql errno 1213 while two admins
        # saved the same pending applicant. SQLSTATE from a REAL MariaDB
        # BEFORE UPDATE trigger deterministically exercises both bounded
        # retry success and persistent victim fail-closed. This models the
        # database-reported error code (not a fabricated Python exception).
        sql(work,env,"UPDATE users SET is_agent=2,discount_percent=NULL WHERE userid=1001;"
                     "UPDATE users SET step='agencyApprove1001_77' WHERE userid=9001")
        sql(work,env,"CREATE TABLE agency_fixture_deadlock_counter "
                     "(n INT NOT NULL) ENGINE=MyISAM")
        sql(work,env,"INSERT INTO agency_fixture_deadlock_counter (n) VALUES (0)")
        trigger_name='agency_fixture_one_victim'
        trigger_ddl=(
            "CREATE TRIGGER "+trigger_name+" BEFORE UPDATE ON users FOR EACH ROW "
            "BEGIN IF OLD.userid=1001 AND OLD.is_agent=2 AND NEW.is_agent=1 THEN "
            "UPDATE agency_fixture_deadlock_counter SET n=n+1; "
            "IF (SELECT n FROM agency_fixture_deadlock_counter LIMIT 1)=1 THEN "
            "SIGNAL SQLSTATE '40001' SET MYSQL_ERRNO=1213, "
            "MESSAGE_TEXT='synthetic_once_deadlock_victim'; END IF; "
            "END IF; END"
        )
        try:
            agency_test_ddl(work,env,trigger_ddl)
            one_victim=run(9001,text='12')
            passed('v1_single_database_deadlock_victim_retries_exact_once_before_effect',
                field(work,env,1001,'is_agent')=='1' and
                outgoing([one_victim],1001)==1 and
                field(work,env,9001,'step')=='none' and
                sql(work,env,'SELECT n FROM agency_fixture_deadlock_counter').strip()=='2')
        finally:
            sql(work,env,'DROP TRIGGER IF EXISTS '+trigger_name)
            sql(work,env,'DROP TABLE IF EXISTS agency_fixture_deadlock_counter')

        sql(work,env,"UPDATE users SET is_agent=2,discount_percent=NULL WHERE userid=1001;"
                     "UPDATE users SET step='agencyApprove1001_77' WHERE userid=9001")
        always_name='agency_fixture_persistent_victim'
        always_ddl=(
            "CREATE TRIGGER "+always_name+" BEFORE UPDATE ON users FOR EACH ROW "
            "BEGIN IF OLD.userid=1001 AND OLD.is_agent=2 AND NEW.is_agent=1 THEN "
            "SIGNAL SQLSTATE '40001' SET MYSQL_ERRNO=1213, "
            "MESSAGE_TEXT='synthetic_persistent_deadlock_victim'; END IF; END"
        )
        try:
            agency_test_ddl(work,env,always_ddl)
            persistent=run(9001,text='12')
            passed('v1_repeated_database_deadlock_victim_fails_closed_no_role_or_notice',
                field(work,env,1001,'is_agent')=='2' and
                outgoing([persistent],1001)==0 and
                field(work,env,9001,'step')=='none')
        finally:
            sql(work,env,'DROP TRIGGER IF EXISTS '+always_name)
        sql(work,env,"UPDATE users SET is_agent=2,discount_percent=NULL WHERE userid=1001;"
                     "UPDATE users SET step='agencyApprove1001_77' WHERE userid=9001")
        timeout_name='agency_fixture_lock_wait_timeout'
        timeout_ddl=(
            "CREATE TRIGGER "+timeout_name+" BEFORE UPDATE ON users FOR EACH ROW "
            "BEGIN IF OLD.userid=1001 AND OLD.is_agent=2 AND NEW.is_agent=1 THEN "
            "SIGNAL SQLSTATE 'HY000' SET MYSQL_ERRNO=1205, "
            "MESSAGE_TEXT='synthetic_lock_wait_timeout'; END IF; END"
        )
        try:
            agency_test_ddl(work,env,timeout_ddl)
            timeout=run(9001,text='12')
            passed('v1_database_lock_wait_timeout_no_role_or_notice',
                field(work,env,1001,'is_agent')=='2' and
                outgoing([timeout],1001)==0 and
                field(work,env,9001,'step')=='none')
        finally:
            sql(work,env,'DROP TRIGGER IF EXISTS '+timeout_name)
        passed('v1_synthetic_schema_restored_no_trigger_or_table_leak',
            len(sql(work,env,'SHOW TABLES').splitlines())==23 and
            not sql(work,env,'SHOW TRIGGERS').strip())

        sql(work, env, """
            UPDATE users SET is_agent=2 WHERE userid=1001;
            INSERT INTO users (userid,name,username,refcode,wallet,date,isAdmin,first_start,is_agent)
            VALUES (1001,'Duplicate','duplicate','0',0,'1700000000',0,'sent',2);
        """)
        before_duplicate = state(work, env)
        duplicate = run(9001, 'agencyDecline1001')
        passed('ambiguous_duplicate_userid_fails_closed',
               state(work, env) == before_duplicate and outgoing([duplicate], 1001) == 0)

    report = {
        'complete': True, 'cases': len(checks), 'schema_tables': 23,
        'original_setUser_extracted': True, 'actual_current_handler_executed': True,
        'synthetic_socket_only': True, 'admin_decline_targeted': True,
        'approval_stale_and_replay_guarded': True, 'concurrent_approval_guarded': True,
        'real_telegram_marzban_payment_calls': 0, 'production_tested': False,
        'details': checks,
    }
    if args.report:
        args.report.write_text(json.dumps(report, indent=2) + '\n')
    print(json.dumps({key:value for key,value in report.items() if key != 'details'}))

if __name__ == '__main__':
    main()
