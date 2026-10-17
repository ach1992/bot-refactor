#!/usr/bin/env python3
"""Source-only Cron wrapper execution tests; never execute real bot workers.

All five child script paths are replaced with disposable local stand-ins.
Includes CLI PHP execution, failure continuation, CWD, argument denial and
anonymous HTTP rejection. No database, Telegram, real Cron or public services.
"""
from __future__ import annotations

import argparse
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
if ($name === getenv('ACHBOT_TEST_ONLY_FAIL')) {
    fwrite(STDERR, "owned fixture controlled failure\n");
    exit(23);
}
echo "owned fixture success\n";
"""


def cmd(php: str, entrypoint: Path, cwd: Path, env: dict[str, str],
        *args: str, disable_proc: bool = False) -> subprocess.CompletedProcess:
    command = [php, "-n"]
    if disable_proc:
        command.extend(["-d", "disable_functions=proc_open"])
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
        root = Path(tmp)
        entrypoint, work = setup(root)
        trace = root / "trace.log"
        env = {**os.environ, "ACHBOT_TEST_ONLY_TRACE": str(trace),
               "ACHBOT_TEST_ONLY_FAIL": "",
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
        trace.unlink()

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
