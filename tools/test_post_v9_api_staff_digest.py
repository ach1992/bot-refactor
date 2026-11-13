#!/usr/bin/env python3
"""Real PHP 8.5 + isolated InnoDB staff digest lock-order/rollback regression.

Runs the exact source-extracted functions, with no Telegram or provider calls.
The manual/payment contender locks pays first, then updates payment_attempts.
"""
from __future__ import annotations

import argparse
import json
from pathlib import Path
import subprocess
import sys
import time

import test_access_state as owned
import test_v6_native as native

ROOT = Path(__file__).resolve().parents[1]
DB = owned.NAMES[1]
EXPECTED = [
    "current_state_digest_stamped",
    "stale_state_refuses_stamp",
    "different_approver_refuses_stamp",
    "null_approver_semantics",
    "caller_transaction_not_committed_or_rolled_back",
    "synthetic_1213_leaves_digest_retryable",
    "post_1213_retry_stamps_without_repeating_effects",
    "concurrent_pays_then_attempt_lock_order",
    "concurrent_changed_payment_refuses_stale_digest",
]

PHP_DRIVER = r"""<?php
error_reporting(E_ALL);
if (PHP_MAJOR_VERSION!==8 || PHP_MINOR_VERSION!==5) exit(10);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$spec=json_decode($argv[3],true,512,JSON_THROW_ON_ERROR);
$db=new mysqli('localhost','root','',$argv[2],0,$argv[1]);
$db->set_charset('utf8mb4');
$db->query('SET SESSION innodb_lock_wait_timeout=4');
$id=(int)$spec['id'];
$op=$spec['op'];
if ($op==='holder') {
    $db->begin_transaction();
    $q=$db->prepare('SELECT id FROM pays WHERE id=? FOR UPDATE');
    $q->bind_param('i',$id);$q->execute();$q->get_result();$q->close();
    echo "LOCKED\n";flush();
    if (trim((string)fgets(STDIN))!=='GO') exit(4);
    $stamp=str_repeat('e',64);
    $q=$db->prepare('UPDATE payment_attempts SET evidence_staff_digest=? WHERE pay_row_id=?');
    $q->bind_param('si',$stamp,$id);$q->execute();$q->close();
    if (!empty($spec['change_state'])) {
        $q=$db->prepare("UPDATE pays SET state='declined' WHERE id=?");
        $q->bind_param('i',$id);$q->execute();$q->close();
    }
    $db->commit();
    echo "COMMITTED\n";flush();
    exit(0);
}
if ($op==='caller_tx') {
    $db->begin_transaction();
    $q=$db->prepare("UPDATE pays SET state='declined' WHERE id=?");
    $q->bind_param('i',$id);$q->execute();$q->close();
    $answer=achbotApiPaymentStampStaffDigest($db,$id,'approved',0,str_repeat('d',64));
    $active=(int)$db->query('SELECT @@in_transaction')->fetch_row()[0];
    $db->rollback();
    echo json_encode(['ok'=>$answer,'caller_tx_active'=>$active],JSON_THROW_ON_ERROR),"\n";
    exit(0);
}
if ($op!=='stamp') exit(5);
if (!empty($spec['signal'])) {echo "READY\n";flush();}
$answer=achbotApiPaymentStampStaffDigest($db,$id,(string)$spec['state'],
    $spec['actor']===null?null:(int)$spec['actor'],(string)$spec['digest']);
$active=(int)$db->query('SELECT @@in_transaction')->fetch_row()[0];
echo json_encode(['ok'=>$answer,'transaction_open'=>$active],JSON_THROW_ON_ERROR),"\n";
"""

def php_functions() -> str:
    expr = (
        "require $argv[1];"
        "echo '<?php'.PHP_EOL,"
        "achbotExtractFunction($argv[2],'achbotPaymentEvidenceRequireAutocommit'),PHP_EOL,"
        "achbotExtractFunction($argv[3],'achbotApiPaymentStampStaffDigest'),PHP_EOL;"
    )
    return subprocess.check_output([
        "php", "-n", "-d", "extension=tokenizer", "-r", expr,
        str(ROOT / "tools/source_scan.php"),
        str(ROOT / "src/Finance/PaymentEvidence.php"),
        str(ROOT / "src/Finance/ApiPayments.php"),
    ], text=True)

def check_case(result: bool, label: str, seen: list[str]) -> None:
    if not result:
        raise AssertionError(label)
    seen.append(label)

def run_php(work: Path, env: dict, spec: dict, *, timeout: int = 10):
    cmd = [
        "php", "-n", "-d", "extension=mysqlnd", "-d", "extension=mysqli",
        "-d", "log_errors=1", "-d", "error_log=/dev/stderr",
        str(work / "worker.php"), str(work / "mysql.sock"), DB,
        json.dumps(spec),
    ]
    p = subprocess.run(cmd, cwd=work, env=env, capture_output=True, text=True, timeout=timeout)
    if p.returncode:
        raise AssertionError(("PHP worker failed", spec["op"], p.returncode, p.stderr[-1800:]))
    return json.loads(p.stdout), p.stderr

def row(work: Path, env: dict, id: int) -> tuple[str, str, str]:
    raw = native.sql(work, env, f"SELECT p.state,COALESCE(p.approved_by,'NULL'),"
                     f"COALESCE(a.evidence_staff_digest,'NULL') FROM pays p "
                     f"JOIN payment_attempts a ON a.pay_row_id=p.id WHERE p.id={id}", DB)
    parts = raw.strip().split("\t")
    assert len(parts) == 3, raw
    return tuple(parts)

def concurrent(work: Path, env: dict, id: int, change_state: bool) -> tuple[dict, str]:
    base = ["php", "-n", "-d", "extension=mysqlnd", "-d", "extension=mysqli",
            str(work / "worker.php"), str(work / "mysql.sock"), DB]
    holder = subprocess.Popen(
        base + [json.dumps({"op":"holder","id":id,"change_state":change_state})],
        cwd=work, env=env, stdin=subprocess.PIPE, stdout=subprocess.PIPE,
        stderr=subprocess.PIPE, text=True, bufsize=1,
    )
    worker = None
    try:
        assert holder.stdout.readline().strip() == "LOCKED"
        worker = subprocess.Popen(
            base + [json.dumps({"op":"stamp","id":id,"state":"approved",
                                 "actor":0,"digest":"b"*64,"signal":True})],
            cwd=work, env=env, stdout=subprocess.PIPE,
            stderr=subprocess.PIPE, text=True, bufsize=1,
        )
        assert worker.stdout.readline().strip() == "READY"
        # Give the stamp's SELECT ... FOR UPDATE an opportunity to wait on
        # pays. If it locked payment_attempts first, holder's second update
        # would face the original inverse-order cycle.
        time.sleep(0.20)
        holder.stdin.write("GO\n")
        holder.stdin.flush()
        hout, herr = holder.communicate(timeout=8)
        wout, werr = worker.communicate(timeout=8)
        assert holder.returncode == 0 and "COMMITTED" in hout, (herr, hout)
        assert worker.returncode == 0, (werr, wout)
        return json.loads(wout), werr
    finally:
        for p in (holder, worker):
            if p is not None and p.poll() is None:
                p.kill()
                p.communicate(timeout=5)

def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--run-disposable", action="store_true", required=True)
    parser.add_argument("--report", type=Path)
    args = parser.parse_args()
    seen: list[str] = []
    with owned.owned_database() as (work, env):
        work.joinpath("worker.php").write_text(php_functions().rstrip() + "\n" +
                                             PHP_DRIVER.split("<?php", 1)[1])
        native.sql(work, env, (ROOT / "migrations/post-v9/003_payment_attempts.sql").read_text(), DB)
        native.sql(work, env, (ROOT / "migrations/post-v9/014_api_payment_evidence.sql").read_text(), DB)
        for id in (7701, 7702, 7703, 7704):
            actor = "NULL" if id == 7702 else "0"
            native.sql(work, env, f"INSERT INTO pays(id,hash_id,user_id,type,price,request_date,state,approved_by)"
                       f" VALUES({id},'fixture-{id}',1001,'INCREASE_WALLET',200,1700000000,'approved',{actor})", DB)
            native.sql(work, env, f"INSERT INTO payment_attempts(pay_row_id,provider,order_hash,"
                       f"quote_amount,quote_currency) VALUES({id},'bank_transfer',"
                       f"'fixture-{id}',200,'TOMAN')", DB)
        def stamp(id: int, state: str, actor: int | None, digest: str):
            return run_php(work, env, {"op":"stamp","id":id,"state":state,
                                       "actor":actor,"digest":digest})

        current, stderr = stamp(7701, "approved", 0, "a"*64)
        check_case(current == {"ok":True,"transaction_open":0} and row(work,env,7701)==
                   ("approved","0","a"*64),"current_state_digest_stamped",seen)
        stale, _ = stamp(7701, "processing", 0, "b"*64)
        check_case(stale["ok"] is False and row(work,env,7701)[2]=="a"*64,
                   "stale_state_refuses_stamp",seen)
        other, _ = stamp(7701, "approved", 9001, "b"*64)
        check_case(other["ok"] is False and row(work,env,7701)[2]=="a"*64,
                   "different_approver_refuses_stamp",seen)
        null_ok, _ = stamp(7702, "approved", None, "c"*64)
        null_bad, _ = stamp(7702, "approved", 0, "d"*64)
        check_case(null_ok["ok"] is True and null_bad["ok"] is False and
                   row(work,env,7702)==("approved","NULL","c"*64),
                   "null_approver_semantics",seen)
        caller, log = run_php(work,env,{"op":"caller_tx","id":7701})
        check_case(caller == {"ok":False,"caller_tx_active":1} and
                   row(work,env,7701)[0]=="approved" and
                   "phase=begin exception=RuntimeException" in log,
                   "caller_transaction_not_committed_or_rolled_back",seen)
        native.sql(work,env,"""DELIMITER //
CREATE TRIGGER fixture_notice_1213 BEFORE UPDATE ON payment_attempts
FOR EACH ROW BEGIN
  IF NEW.evidence_staff_digest<>OLD.evidence_staff_digest THEN
    SIGNAL SQLSTATE '40001' SET MYSQL_ERRNO=1213,
      MESSAGE_TEXT='synthetic_staff_digest_deadlock';
  END IF;
END//
DELIMITER ;""",DB)
        try:
            victim, log = stamp(7701, "approved", 0, "d"*64)
        finally:
            native.sql(work,env,"DROP TRIGGER fixture_notice_1213",DB)
        check_case(victim == {"ok":False,"transaction_open":0} and
                   row(work,env,7701)[2]=="a"*64 and
                   "phase=stamp_attempt" in log and "code=1213" in log,
                   "synthetic_1213_leaves_digest_retryable",seen)
        retried, _ = stamp(7701, "approved", 0, "d"*64)
        check_case(retried["ok"] is True and row(work,env,7701)[2]=="d"*64,
                   "post_1213_retry_stamps_without_repeating_effects",seen)
        normal, _ = concurrent(work,env,7703,False)
        check_case(normal=={"ok":True,"transaction_open":0} and
                   row(work,env,7703)==("approved","0","b"*64),
                   "concurrent_pays_then_attempt_lock_order",seen)
        changed, _ = concurrent(work,env,7704,True)
        check_case(changed=={"ok":False,"transaction_open":0} and
                   row(work,env,7704)==("declined","0","e"*64),
                   "concurrent_changed_payment_refuses_stale_digest",seen)
    result={"complete":True,"cases":len(seen),"scenarios":seen,
            "exact_source_extracted":True,"php85":True,"owned_socket_mariadb":True,
            "real_innodb_concurrency":True,"synthetic_1213":True,
            "provider_requests":0,"telegram_requests":0,"production_mutations":0,
            "source_head": subprocess.check_output(["git","rev-parse","HEAD"],cwd=ROOT,text=True).strip()}
    assert seen == EXPECTED
    if args.report:
        args.report.write_text(json.dumps(result,ensure_ascii=False,indent=2))
    print(json.dumps(result,ensure_ascii=False))

if __name__=="__main__":
    main()
