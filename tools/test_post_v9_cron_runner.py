#!/usr/bin/env python3
"""Source-only Cron wrapper execution tests; never execute real bot workers.

All five child script paths are replaced with disposable local stand-ins.
Includes CLI PHP execution, failure continuation, CWD, lock contention,
failed-lock denial, argument denial and anonymous HTTP rejection. No database,
Telegram, real Cron or public services.
"""
from __future__ import annotations

import argparse
import fcntl
import hashlib
import json
import os
import re
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
RUNNER = ROOT / "settings" / "cronRunner.php"
JOBS = (
    "tronChecker.php",
    "gift2all.php",
    "messagewizwiz.php",
    "rewardReport.php",
    "warnusers.php",
)

# This is deliberately the ACTUAL coordinator source, not a copy of its logic.
# Any future source change is exercised by the same PHP native tests.
CHILD_PHP = r"""<?php
$name = basename(__FILE__);
$line = $name . "|" . getcwd() . "|" . PHP_BINARY . "\n";
file_put_contents(getenv('ACHBOT_TEST_ONLY_TRACE'), $line, FILE_APPEND | LOCK_EX);
if ($name === getenv('ACHBOT_TEST_ONLY_PAUSE')) {
    $release = getenv('ACHBOT_TEST_ONLY_RELEASE');
    $until = microtime(true) + 8;
    while ($release && !file_exists($release) && microtime(true) < $until) {
        usleep(20000);
    }
    if (!$release || !file_exists($release)) {
        fwrite(STDERR, "owned fixture release timeout\n");
        exit(99);
    }
}
if ($name === getenv('ACHBOT_TEST_ONLY_FAIL')) {
    fwrite(STDERR, "owned fixture controlled failure\n");
    exit(23);
}
echo "owned fixture success\n";
"""


def cmd(php: str, entrypoint: Path, cwd: Path, env: dict[str, str],
        *args: str, disable_proc: bool = False,
        disable_flock: bool = False) -> subprocess.CompletedProcess:
    command = [php, "-n"]
    if disable_proc:
        command.extend(["-d", "disable_functions=proc_open"])
    if disable_flock:
        command.extend(["-d", "disable_functions=flock"])
    command.extend([str(entrypoint), *args])
    return subprocess.run(
        command, cwd=cwd, env=env, capture_output=True, text=True, timeout=18
    )


def setup(root: Path) -> tuple[Path, Path]:
    settings = root / "settings"
    settings.mkdir()
    entrypoint = settings / RUNNER.name
    entrypoint.write_bytes(RUNNER.read_bytes())
    for name in JOBS:
        (settings / name).write_text(CHILD_PHP, encoding="utf-8")
    work = root / "owned-cwd"
    work.mkdir()
    return entrypoint, work


def http_rejection(php: str, root: Path, env: dict[str, str]) -> bool:
    sock = socket.socket()
    sock.bind(("127.0.0.1", 0))
    port = sock.getsockname()[1]
    sock.close()
    with open(os.devnull, "wb") as output:
        server = subprocess.Popen(
            [php, "-n", "-S", f"127.0.0.1:{port}", "-t", str(root)],
            cwd=root, env=env, stdout=output, stderr=output,
        )
        try:
            for _ in range(60):
                if server.poll() is not None:
                    raise RuntimeError("Owned PHP HTTP server did not start")
                try:
                    with socket.create_connection(("127.0.0.1", port), timeout=0.2):
                        break
                except OSError:
                    time.sleep(.04)
            else:
                raise RuntimeError("Owned PHP HTTP server not ready")
            try:
                with urllib.request.urlopen(
                    f"http://127.0.0.1:{port}/settings/cronRunner.php", timeout=5
                ) as response:
                    status = response.status
            except urllib.error.HTTPError as error:
                status = error.code
            return status == 404
        finally:
            server.terminate()
            try:
                server.wait(timeout=4)
            except subprocess.TimeoutExpired:
                server.kill()
                server.wait(timeout=3)


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--report", type=Path)
    args = parser.parse_args()
    php = os.environ.get("ACHBOT_CLI_PHP", "php")
    result_names: list[str] = []

    def check(name: str, condition: bool) -> None:
        if not condition:
            raise RuntimeError("Cron runner regression: " + name)
        result_names.append(name)

    with tempfile.TemporaryDirectory(prefix="achbot-owned-cron-runner-") as tmp:
        root = Path(tmp) / "bot"
        root.mkdir()
        entrypoint, work = setup(root)
        trace = root / "trace.log"
        lock_dir = Path(tmp) / "private-locks"
        lock_dir.mkdir(mode=0o700)
        lock_file = lock_dir / (
            "achbot-cron-" + hashlib.sha256(str(root.resolve()).encode()).hexdigest()[:24]
            + ".lock"
        )
        env = {**os.environ, "ACHBOT_TEST_ONLY_TRACE": str(trace),
               "ACHBOT_TEST_ONLY_FAIL": "",
               "ACHBOT_TEST_ONLY_PAUSE": "",
               "ACHBOT_TEST_ONLY_RELEASE": "",
               "ACHBOT_CRON_LOCK_DIR": str(lock_dir),
               "http_proxy": "http://127.0.0.1:9",
               "https_proxy": "http://127.0.0.1:9",
               "all_proxy": "http://127.0.0.1:9",
               "no_proxy": "127.0.0.1,localhost"}
        clean = cmd(php, entrypoint, work, env)
        lines = trace.read_text().splitlines()
        check("five_jobs_sequential_exact_order",
              clean.returncode == 0 and len(lines) == 5
              and [line.split("|")[0] for line in lines] == list(JOBS))
        check("individual_cli_processes_inherit_cron_cwd",
              all(line.split("|")[1] == str(work) for line in lines)
              and len({line.split("|")[2] for line in lines}) == 1)
        check("successful_workers_preserve_outputs",
              clean.stdout.count("owned fixture success") == 5
              and clean.stderr == "")
        check("per_installation_private_lock_created",
              lock_file.exists() and lock_file.stat().st_mode & 0o777 == 0o600)
        trace.unlink()

        # Two independent scheduler ticks may start from the SAME aaPanel job.
        # Hold the first worker and verify the second invocation does nothing.
        release_signal = root / "release-fixture"
        held_env = {**env, "ACHBOT_TEST_ONLY_PAUSE": JOBS[0],
                    "ACHBOT_TEST_ONLY_RELEASE": str(release_signal)}
        held = subprocess.Popen(
            [php, "-n", str(entrypoint)],
            cwd=work, env=held_env, stdout=subprocess.PIPE,
            stderr=subprocess.PIPE, text=True,
        )
        try:
            for _ in range(200):
                if trace.exists() and len(trace.read_text().splitlines()) >= 1:
                    break
                if held.poll() is not None:
                    raise RuntimeError("First Cron exited before overlap fixture")
                time.sleep(0.02)
            else:
                raise RuntimeError("First Cron did not reach lock-held fixture")
            overlap = cmd(php, entrypoint, work, env)
            check("overlapping_tick_skips_without_extra_jobs",
                  overlap.returncode == 0
                  and "previous invocation active; skipping" in overlap.stderr
                  and len(trace.read_text().splitlines()) == 1)
            # The key is INSTALLATION identity, not global host-wide exclusion.
            independent = root / "second-bot"
            independent.mkdir()
            second_entry, second_cwd = setup(independent)
            second_env = {**env, "ACHBOT_TEST_ONLY_TRACE": str(root / "second.log")}
            second = cmd(php, second_entry, second_cwd, second_env)
            check("different_installation_runs_while_first_locked",
                  second.returncode == 0
                  and len((root / "second.log").read_text().splitlines()) == 5)
            release_signal.touch()
            held_stdout, held_stderr = held.communicate(timeout=14)
            check("first_tick_finishes_full_batch_after_overlap",
                  held.returncode == 0
                  and held_stdout.count("owned fixture success") == 5
                  and held_stderr == ""
                  and len(trace.read_text().splitlines()) == 5)
        finally:
            release_signal.touch()
            if held.poll() is None:
                held.terminate()
            held.communicate(timeout=8)
        trace.unlink()
        after = cmd(php, entrypoint, work, env)
        check("stale_lock_file_does_not_block_next_tick",
              after.returncode == 0 and len(trace.read_text().splitlines()) == 5)
        trace.unlink()

        # Simulate a hard coordinator crash DURING a financial worker. The
        # orphan child must retain fd 3's kernel lease until that child exits.
        crash_release = root / "release-orphan"
        crash_env = {**env, "ACHBOT_TEST_ONLY_PAUSE": JOBS[0],
                     "ACHBOT_TEST_ONLY_RELEASE": str(crash_release)}
        crashed = subprocess.Popen(
            [php, "-n", str(entrypoint)],
            cwd=work, env=crash_env, stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
        )
        try:
            for _ in range(200):
                if trace.exists() and len(trace.read_text().splitlines()) >= 1:
                    break
                if crashed.poll() is not None:
                    raise RuntimeError("Coordinator died before crash simulation")
                time.sleep(0.02)
            else:
                raise RuntimeError("Worker never reached crash simulation")
            crashed.kill()
            crashed.wait(timeout=3)
            in_flight = cmd(php, entrypoint, work, env)
            check("orphaned_worker_retains_lock_after_parent_kill",
                  in_flight.returncode == 0
                  and "previous invocation active; skipping" in in_flight.stderr
                  and len(trace.read_text().splitlines()) == 1)
            crash_release.touch()
            # Wait for the orphaned fixture to exit and release its kernel fd.
            for _ in range(200):
                with lock_file.open("rb") as h:
                    try:
                        fcntl.flock(h.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
                    except BlockingIOError:
                        time.sleep(0.02)
                    else:
                        fcntl.flock(h.fileno(), fcntl.LOCK_UN)
                        break
            else:
                raise RuntimeError("Orphan worker did not release lock")
            trace.unlink()
            recovered = cmd(php, entrypoint, work, env)
            check("new_run_recovers_after_orphan_worker_exit",
                  recovered.returncode == 0
                  and len(trace.read_text().splitlines()) == 5)
        finally:
            crash_release.touch()
            if crashed.poll() is None:
                crashed.kill()
                crashed.wait(timeout=3)
        trace.unlink()

        web_lock_dir = root / "web-exposed-locks"
        web_lock_dir.mkdir(mode=0o700)
        web_env = {**env, "ACHBOT_CRON_LOCK_DIR": str(web_lock_dir)}
        web_denied = cmd(php, entrypoint, work, web_env)
        check("lock_directory_inside_webroot_fails_closed",
              web_denied.returncode == 78 and "no tasks started" in web_denied.stderr
              and not trace.exists())
        bad_env = {**env, "ACHBOT_CRON_LOCK_DIR": str(root / "absent-lock-dir")}
        invalid = cmd(php, entrypoint, work, bad_env)
        check("unavailable_lock_directory_fails_closed",
              invalid.returncode == 78 and "no tasks started" in invalid.stderr
              and not trace.exists())
        lock_file.unlink()
        attack_target = root / "outside-owned-fixture"
        attack_target.write_text("untouched", encoding="utf-8")
        lock_file.symlink_to(attack_target)
        invalid_link = cmd(php, entrypoint, work, env)
        check("symlink_lock_path_fails_closed",
              invalid_link.returncode == 78 and "no tasks started" in invalid_link.stderr
              and not trace.exists() and attack_target.read_text() == "untouched")
        lock_file.unlink()
        lock_file.write_text("", encoding="utf-8")
        lock_file.chmod(0o666)
        insecure = cmd(php, entrypoint, work, env)
        check("permissive_lock_file_fails_closed",
              insecure.returncode == 78 and "no tasks started" in insecure.stderr
              and not trace.exists())
        lock_file.unlink()

        group_writable = Path(tmp) / "group-writable-locks"
        group_writable.mkdir(mode=0o770)
        group_writable.chmod(0o770)
        group_env = {**env, "ACHBOT_CRON_LOCK_DIR": str(group_writable)}
        unsafe_group = cmd(php, entrypoint, work, group_env)
        check("group_writable_unprotected_lock_directory_denied",
              unsafe_group.returncode == 78
              and "no tasks started" in unsafe_group.stderr
              and not trace.exists())
        disabled_flock = cmd(php, entrypoint, work, env, disable_flock=True)
        check("unavailable_php_flock_fails_closed",
              disabled_flock.returncode == 78
              and "no tasks started" in disabled_flock.stderr
              and not trace.exists())
        env["ACHBOT_TEST_ONLY_FAIL"] = JOBS[1]
        failing = cmd(php, entrypoint, work, env)
        lines = trace.read_text().splitlines()
        check("failed_child_does_not_skip_remaining_workers",
              failing.returncode == 1 and len(lines) == 5
              and [line.split("|")[0] for line in lines] == list(JOBS)
              and "gift2all.php exited with status 23" in failing.stderr)
        check("no_child_exception_details_emitted_by_runner",
              "Throwable" not in failing.stderr and "Traceback" not in failing.stderr)
        trace.unlink()

        (entrypoint.parent / JOBS[2]).unlink()
        env["ACHBOT_TEST_ONLY_FAIL"] = ""
        missing = cmd(php, entrypoint, work, env)
        lines = trace.read_text().splitlines()
        check("missing_worker_fails_but_others_continue",
              missing.returncode == 1 and len(lines) == 4
              and [line.split("|")[0] for line in lines] ==
              [job for job in JOBS if job != JOBS[2]]
              and "missing or invalid worker: messagewizwiz.php" in missing.stderr)
        trace.unlink()
        (entrypoint.parent / JOBS[2]).write_text(CHILD_PHP, encoding="utf-8")

        rejected = cmd(php, entrypoint, work, env, "--unknown")
        check("unexpected_arguments_denied_without_side_effect",
              rejected.returncode == 64 and not trace.exists()
              and "no tasks started" in rejected.stderr)
        disabled = cmd(php, entrypoint, work, env, disable_proc=True)
        check("missing_proc_open_fails_closed",
              disabled.returncode == 78 and not trace.exists()
              and "no tasks started" in disabled.stderr)

        include = subprocess.run(
            [php, "-n", "-r", "include $argv[1];", str(entrypoint)],
            cwd=work, env=env, capture_output=True, text=True, timeout=12,
        )
        check("accidental_cli_include_has_no_side_effect",
              include.returncode == 0 and not trace.exists())
        check("anonymous_http_returns_404_without_running_workers",
              http_rejection(php, root, env) and not trace.exists())

        text = RUNNER.read_text()
        check("observed_chili_order_bounded_and_explicit",
              all(text.count("'" + x + "'") == 1 for x in JOBS)
              and text.index("'" + JOBS[0] + "'") <
                  text.index("'" + JOBS[1] + "'") <
                  text.index("'" + JOBS[2] + "'") <
                  text.index("'" + JOBS[3] + "'") <
                  text.index("'" + JOBS[4] + "'"))
        check("source_has_no_database_or_provider_bootstrap",
              not re.search(r"\b(?:mysqli|PDO|curl_init|file_get_contents)\s*\(", text)
              and "baseInfo.php" not in text
              and "config.php" not in text)

    report = {
        "complete": True,
        "cases": len(result_names),
        "scenarios": result_names,
        "source_only": True,
        "actual_current_runner_executed": True,
        "isolated_child_php_processes": True,
        "actual_cron_or_bot_job_executed": False,
        "production_database_or_service_modified": False,
    }
    if args.report:
        args.report.write_text(json.dumps(report, indent=2) + "\n")
    print(json.dumps({k: v for k, v in report.items() if k != "scenarios"}))


if __name__ == "__main__":
    main()
