#!/usr/bin/env python3
"""Source-only Cron wrapper execution tests; never execute real bot workers.

All four child script paths are replaced with disposable local stand-ins.
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
    "gift2all.php",
    "broadcast",
    "rewardReport.php",
    "services",
)

# This is deliberately the ACTUAL coordinator source, not a copy of its logic.
# Force only the standard PHP POSIX extension with -n; the production runner
# needs an effective-UID check before trusting a writable lock directory.
PHP_CLI_OPTS = ("-n", "-d", "extension=posix")
CHILD_PHP = r"""<?php
$name = basename(__FILE__);
$line = $name . "|" . getcwd() . "|" . PHP_BINARY . "|" . getmypid() . "\n";
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


# Only the coordinator is under test. Keep both domain boundaries local,
# silent, and database-free; reuse the existing trace without a new env input.
API_MAINTENANCE_PHP = r"""<?php
function achbotApiPaymentMaintain($db) {
    $trace = getenv('ACHBOT_TEST_ONLY_TRACE');
    if (is_string($trace) && $trace !== '') {
        file_put_contents($trace . '.maintenance', "api\n", FILE_APPEND | LOCK_EX);
        if (is_file($trace . '.api-failure')) {
            throw new RuntimeException('owned fixture controlled API maintenance failure');
        }
    }
    return [];
}
"""
PAYUX_MAINTENANCE_PHP = r"""<?php
function achbotPayUxMaintain($db) {
    $trace = getenv('ACHBOT_TEST_ONLY_TRACE');
    if (is_string($trace) && $trace !== '') {
        file_put_contents($trace . '.maintenance', "payux\n", FILE_APPEND | LOCK_EX);
    }
}
"""


def cmd(php: str, entrypoint: Path, cwd: Path, env: dict[str, str],
        *args: str, disable_proc: bool = False,
        disable_flock: bool = False) -> subprocess.CompletedProcess:
    command = [php, *PHP_CLI_OPTS]
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
        if name.endswith('.php'):
            (settings/name).write_text(CHILD_PHP,encoding='utf-8')
    (root/'baseInfo.php').write_text('<?php /* owned fixture */')
    (root/'config.php').write_text("<?php $buttonValues=['start_bot'=>'Fixture'];$botUsername='fixture';$connection=null;")
    (settings/'jdf.php').write_text('<?php /* owned fixture */')
    for rel in ['src/Jobs','src/Notifications','src/Finance']:
        (root/rel).mkdir(parents=True,exist_ok=True)
    (root/'src/Jobs/Checkpoint.php').write_text('<?php /* owned fixture */')
    (root/'src/Jobs/MessageActions.php').write_text(CHILD_PHP.replace('$name = basename(__FILE__);',"$name = 'broadcast';"))
    (root/'src/Jobs/BroadcastSend.php').write_text('<?php /* owned fixture */')
    (root/'src/Notifications/Warnings.php').write_text(CHILD_PHP.replace('$name = basename(__FILE__);',"$name = 'services';"))
    (root/'src/Finance/AutomaticRenewal.php').write_text('<?php /* owned fixture */')
    (root/'src/Finance/PaymentLifecycle.php').write_text(PAYUX_MAINTENANCE_PHP)
    (root/'src/Finance/ApiPayments.php').write_text(API_MAINTENANCE_PHP)

    work = root / "owned-cwd"
    work.mkdir()
    return entrypoint, work


def http_rejection(php: str, root: Path, env: dict[str, str],path: str="settings/cronRunner.php") -> bool:
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
                    f"http://127.0.0.1:{port}/{path}", timeout=5
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


def directory_provisioning_cases(php: str, check) -> None:
    """Execute the actual coordinator against disposable filesystem boundaries."""
    def metadata(path: Path) -> tuple:
        s = path.lstat()
        return (s.st_dev, s.st_ino, s.st_mode, s.st_uid, s.st_gid,
                s.st_nlink, s.st_size, s.st_mtime_ns, s.st_ctime_ns)

    def snapshot(root: Path) -> dict:
        # Never follow directory symlinks. Ignore access time from inspection.
        result = {}
        for parent, directories, files in os.walk(root, followlinks=False):
            for name in directories + files:
                path = Path(parent) / name
                result[str(path.relative_to(root))] = (
                    metadata(path), os.readlink(path) if path.is_symlink() else None
                )
        return result

    with tempfile.TemporaryDirectory(prefix="achbot-cron-directory-") as tmp:
        owned = Path(tmp)
        bot = owned / "bot"
        bot.mkdir()
        entry, cwd = setup(bot)
        trace = owned / "jobs.log"
        parent = owned / "trusted-parent"
        parent.mkdir(mode=0o750)
        parent.chmod(0o750)
        parent_before = parent.stat()
        lock_dir = parent / "first" / "second" / "locks"
        env = {
            **os.environ, "ACHBOT_CRON_LOCK_DIR": str(lock_dir),
            "ACHBOT_TEST_ONLY_TRACE": str(trace), "ACHBOT_TEST_ONLY_FAIL": "",
            "ACHBOT_TEST_ONLY_PAUSE": "", "ACHBOT_TEST_ONLY_RELEASE": "",
            "http_proxy": "http://127.0.0.1:9",
            "https_proxy": "http://127.0.0.1:9",
            "all_proxy": "http://127.0.0.1:9",
            "no_proxy": "127.0.0.1,localhost",
        }
        # A permissive caller umask must not expose any newly created component.
        old_umask = os.umask(0)
        try:
            first = cmd(php, entry, cwd, env)
        finally:
            os.umask(old_umask)
        created = [parent / "first", parent / "first" / "second", lock_dir]
        check("missing_nested_external_directories_created_private_current_uid",
              first.returncode == 0 and all(
                  p.is_dir() and p.stat().st_mode & 0o777 == 0o700
                  and p.stat().st_uid == os.geteuid() for p in created))
        check("first_directory_creation_runs_exact_four_standins",
              first.stdout == "owned fixture success\n" * len(JOBS)
              and first.stderr == ""
              and [line.split("|")[0] for line in trace.read_text().splitlines()] == list(JOBS))
        check("trusted_existing_parent_inode_mode_owner_preserved",
              (parent.stat().st_ino, parent.stat().st_mode, parent.stat().st_uid)
              == (parent_before.st_ino, parent_before.st_mode, parent_before.st_uid))
        lock_file = lock_dir / (
            "achbot-cron-" + hashlib.sha256(str(bot.resolve()).encode()).hexdigest()[:24] + ".lock")
        check("autoprovisioned_directory_uses_private_installation_lock",
              lock_file.is_file() and lock_file.stat().st_mode & 0o777 == 0o600
              and lock_file.stat().st_uid == os.geteuid() and lock_file.stat().st_nlink == 1)
        stable = {str(p): metadata(p) for p in created + [lock_file]}
        reuse = cmd(php, entry, cwd, env)
        check("existing_directories_and_lock_reused_without_chmod_or_inode_repair",
              reuse.returncode == 0
              and stable == {str(p): metadata(p) for p in created + [lock_file]}
              and len(trace.read_text().splitlines()) == 2 * len(JOBS))
        trace.unlink()

        mkdir_missing = parent / "disabled-mkdir" / "locks"
        before_missing = snapshot(owned)
        disabled_mkdir = subprocess.run(
            [php, *PHP_CLI_OPTS, "-d", "disable_functions=mkdir", str(entry)],
            cwd=cwd, env={**env, "ACHBOT_CRON_LOCK_DIR": str(mkdir_missing)},
            capture_output=True, text=True, timeout=18)
        check("missing_directory_with_mkdir_disabled_denied_without_creation",
              disabled_mkdir.returncode == 78
              and "AChBot Cron: secure lock directory unavailable" in disabled_mkdir.stderr
              and "reason=mkdir_unavailable" in disabled_mkdir.stderr
              and "path=" in disabled_mkdir.stderr and "no tasks started" in disabled_mkdir.stderr
              and not trace.exists() and snapshot(owned) == before_missing)
        disabled_existing = subprocess.run(
            [php, *PHP_CLI_OPTS, "-d", "disable_functions=mkdir", str(entry)],
            cwd=cwd, env=env, capture_output=True, text=True, timeout=18)
        check("valid_existing_directory_runs_with_mkdir_disabled_without_repair",
              disabled_existing.returncode == 0
              and disabled_existing.stdout == "owned fixture success\n" * len(JOBS)
              and disabled_existing.stderr == ""
              and [line.split("|")[0] for line in trace.read_text().splitlines()] == list(JOBS)
              and stable == {str(p): metadata(p) for p in created + [lock_file]})
        trace.unlink()

        def denied(name: str, requested: str, *arguments: str) -> None:
            before = snapshot(owned)
            result = cmd(php, entry, cwd, {**env, "ACHBOT_CRON_LOCK_DIR": requested}, *arguments)
            check(name, result.returncode == 78 and "no tasks started" in result.stderr
                  and not trace.exists() and snapshot(owned) == before)
            if not arguments:
                check(name + "_has_actionable_directory_context",
                      "AChBot Cron: secure lock directory unavailable" in result.stderr
                      and "reason=" in result.stderr and "path=" in result.stderr)

        unsafe = owned / "unsafe-parent"
        unsafe.mkdir()
        unsafe.chmod(0o777)
        denied("missing_below_world_writable_parent_denied_unchanged",
               str(unsafe / "missing" / "locks"))
        unsafe.chmod(0o770)
        denied("missing_below_group_writable_parent_denied_unchanged",
               str(unsafe / "missing" / "locks"))
        unsafe.chmod(0o500)
        try:
            denied("missing_below_unwritable_parent_denied_unchanged",
                   str(unsafe / "missing" / "locks"))
        finally:
            unsafe.chmod(0o700)
        alias = owned / "directory-alias"
        alias.symlink_to(parent, target_is_directory=True)
        denied("missing_below_symlink_parent_denied_without_target_creation",
               str(alias / "never-created" / "locks"))
        ordinary_file = owned / "ordinary-file"
        ordinary_file.write_text("owned marker remains unchanged", encoding="utf-8")
        denied("missing_below_regular_file_denied_without_mutation",
               str(ordinary_file / "never-created" / "locks"))
        permissive = owned / "permissive-leaf"
        permissive.mkdir()
        for mode, name in ((0o777, "world_writable"), (0o750, "nonprivate"), (0o500, "unwritable")):
            permissive.chmod(mode)
            denied("existing_" + name + "_leaf_denied_without_chmod_repair", str(permissive))
        permissive.chmod(0o700)
        denied("missing_inside_installation_denied_without_directory_creation",
               str(bot / "never-created" / "locks"))
        denied("relative_missing_directory_denied_without_creation", "relative/missing/locks")
        denied("dot_component_denied_without_creation", str(parent) + "/./dot-missing/locks")
        denied("parent_alias_component_denied_without_creation",
               str(parent) + "/../parent-missing/locks")
        denied("repeated_separator_denied_without_creation", str(parent) + "//alias-missing/locks")
        denied("root_path_denied_without_bootstrap", "/")
        for mode in ("broadcast", "services"):
            denied("child_" + mode + "_cannot_create_missing_directories",
                   str(parent / ("child-" + mode) / "locks"), "--job=" + mode)

        missing_entry = parent / "entry-must-not-create" / "locks"
        entry_env = {**env, "ACHBOT_CRON_LOCK_DIR": str(missing_entry)}
        before = snapshot(owned)
        included = subprocess.run(
            [php, "-n", "-r", "include $argv[1];", str(entry)], cwd=cwd,
            env=entry_env, capture_output=True, text=True, timeout=12)
        check("cli_include_cannot_autoprovision_missing_directory",
              included.returncode == 0 and not trace.exists() and snapshot(owned) == before)
        check("http_cannot_autoprovision_missing_directory",
              http_rejection(php, bot, entry_env) and not trace.exists()
              and snapshot(owned) == before)

        # Five first starts race on MISSING directories and the initial inode.
        race_dir = parent / "race-first" / "nested" / "locks"
        release = owned / "release-directory-race"
        race_env = {**env, "ACHBOT_CRON_LOCK_DIR": str(race_dir),
                    "ACHBOT_TEST_ONLY_PAUSE": JOBS[0],
                    "ACHBOT_TEST_ONLY_RELEASE": str(release)}
        contenders = [
            subprocess.Popen([php, *PHP_CLI_OPTS, str(entry)], cwd=cwd, env=race_env,
                             stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
            for _ in range(5)
        ]
        try:
            for _ in range(200):
                if trace.exists() and len(trace.read_text().splitlines()) >= 1:
                    break
                if all(p.poll() is not None for p in contenders):
                    raise RuntimeError("No first-start directory contender acquired lease")
                time.sleep(.02)
            else:
                raise RuntimeError("First-start directory race did not reach stand-in")
            # Keep the winner held until all four losing ticks have skipped.
            for _ in range(150):
                if sum(p.poll() is not None for p in contenders) == 4:
                    break
                time.sleep(.02)
            check("five_missing_directory_starts_admit_only_one_batch",
                  len(trace.read_text().splitlines()) == 1
                  and sum(p.poll() is not None for p in contenders) == 4)
            check("concurrent_created_components_remain_private",
                  all(p.is_dir() and p.stat().st_mode & 0o777 == 0o700
                      and p.stat().st_uid == os.geteuid()
                      for p in (parent / "race-first", parent / "race-first" / "nested", race_dir)))
            race_lock = race_dir / lock_file.name
            race_inode = race_lock.stat().st_ino

            # A separate canonical installation shares the newly created dir,
            # while the first installation's batch is still holding its lease.
            other_bot = owned / "other-bot"
            other_bot.mkdir()
            other_entry, other_cwd = setup(other_bot)
            other_trace = owned / "other-jobs.log"
            other_env = {**env, "ACHBOT_CRON_LOCK_DIR": str(race_dir),
                         "ACHBOT_TEST_ONLY_TRACE": str(other_trace)}
            other = cmd(php, other_entry, other_cwd, other_env)
            other_lock = race_dir / (
                "achbot-cron-" + hashlib.sha256(str(other_bot.resolve()).encode()).hexdigest()[:24] + ".lock")
            check("independent_bots_share_autocreated_directory_without_global_exclusion",
                  other.returncode == 0
                  and [line.split("|")[0] for line in other_trace.read_text().splitlines()] == list(JOBS)
                  and other_lock.is_file() and other_lock.stat().st_ino != race_inode
                  and len(list(race_dir.iterdir())) == 2
                  and len(trace.read_text().splitlines()) == 1)
            release.touch()
            results = [p.communicate(timeout=12) for p in contenders]
            check("five_first_directory_starts_finish_one_exact_batch",
                  all(p.returncode == 0 for p in contenders)
                  and sum(out == "owned fixture success\n" * len(JOBS) and err == ""
                          for out, err in results) == 1
                  and sum(out == "" and "previous invocation active; skipping" in err
                          for out, err in results) == 4
                  and [line.split("|")[0] for line in trace.read_text().splitlines()] == list(JOBS)
                  and race_lock.stat().st_ino == race_inode)
        finally:
            release.touch()
            for proc in contenders:
                if proc.poll() is None:
                    proc.terminate()
                proc.communicate(timeout=5)



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

    directory_provisioning_cases(php, check)

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
        check("four_jobs_sequential_exact_order",
              clean.returncode == 0 and len(lines) == len(JOBS)
              and [line.split("|")[0] for line in lines] == list(JOBS))
        check("individual_cli_processes_inherit_cron_cwd",
              all(line.split("|")[1] == str(work) for line in lines)
              and len({line.split("|")[2] for line in lines}) == 1)
        check("successful_workers_preserve_outputs",
              clean.stdout.count("owned fixture success") == len(JOBS)
              and clean.stderr == "")
        maintenance_trace = Path(str(trace) + '.maintenance')
        api_order = maintenance_trace.read_text().splitlines() == ['api', 'payux']
        check('api_payment_maintenance_precedes_payux', api_order)
        check("per_installation_private_lock_created",
              lock_file.exists() and lock_file.stat().st_mode & 0o777 == 0o600)
        check("first_create_then_reuse_same_inode",
              clean.returncode == 0 and lock_file.is_file()
              and lock_file.stat().st_nlink == 1)
        initial_inode = lock_file.stat().st_ino
        reuse = cmd(php, entrypoint, work, env)
        check("later_run_reuses_stable_lock_inode",
              reuse.returncode == 0 and lock_file.stat().st_ino == initial_inode
              and len(trace.read_text().splitlines()) == 2 * len(JOBS))
        trace.unlink()

        # Test concurrent first-time creation from a missing lock file. Only
        # the fixture removes an idle lock inode; the runner NEVER removes it.
        lock_file.unlink()
        release_race = root / "release-create-race"
        race_env = {**env, "ACHBOT_TEST_ONLY_PAUSE": JOBS[0],
                    "ACHBOT_TEST_ONLY_RELEASE": str(release_race)}
        contenders = [
            subprocess.Popen(
                [php, *PHP_CLI_OPTS, str(entrypoint)],
                cwd=work, env=race_env, stdout=subprocess.PIPE,
                stderr=subprocess.PIPE, text=True,
            )
            for _ in range(5)
        ]
        try:
            for _ in range(200):
                if trace.exists() and len(trace.read_text().splitlines()) >= 1:
                    break
                time.sleep(.02)
            else:
                raise RuntimeError("No first-time Cron contender acquired lock")
            time.sleep(.20)
            check("atomic_first_creation_does_not_start_second_batch",
                  len(trace.read_text().splitlines()) == 1)
            release_race.touch()
            results = [proc.communicate(timeout=12) for proc in contenders]
            check("five_simultaneous_first_invocations_finish_one_batch",
                  all(proc.returncode == 0 for proc in contenders)
                  and sum("owned fixture success" in out for out, err in results) == 1
                  and sum("previous invocation active; skipping" in err
                          for out, err in results) == 4
                  and len(trace.read_text().splitlines()) == len(JOBS))
        finally:
            release_race.touch()
            for proc in contenders:
                if proc.poll() is None:
                    proc.terminate()
                proc.communicate(timeout=5)
        trace.unlink()

        # Two independent scheduler ticks may start from the SAME aaPanel job.
        # Hold the first worker and verify the second invocation does nothing.
        release_signal = root / "release-fixture"
        held_env = {**env, "ACHBOT_TEST_ONLY_PAUSE": JOBS[0],
                    "ACHBOT_TEST_ONLY_RELEASE": str(release_signal)}
        held = subprocess.Popen(
            [php, *PHP_CLI_OPTS, str(entrypoint)],
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
                  and len((root / "second.log").read_text().splitlines()) == len(JOBS))
            release_signal.touch()
            held_stdout, held_stderr = held.communicate(timeout=14)
            check("first_tick_finishes_full_batch_after_overlap",
                  held.returncode == 0
                  and held_stdout.count("owned fixture success") == len(JOBS)
                  and held_stderr == ""
                  and len(trace.read_text().splitlines()) == len(JOBS))
        finally:
            release_signal.touch()
            if held.poll() is None:
                held.terminate()
            held.communicate(timeout=8)
        trace.unlink()
        after = cmd(php, entrypoint, work, env)
        check("stale_lock_file_does_not_block_next_tick",
              after.returncode == 0 and len(trace.read_text().splitlines()) == len(JOBS))
        trace.unlink()

        # Simulate a hard coordinator crash DURING a financial worker. The
        # orphan child must retain fd 3's kernel lease until that child exits.
        crash_release = root / "release-orphan"
        crash_env = {**env, "ACHBOT_TEST_ONLY_PAUSE": JOBS[0],
                     "ACHBOT_TEST_ONLY_RELEASE": str(crash_release)}
        crashed = subprocess.Popen(
            [php, *PHP_CLI_OPTS, str(entrypoint)],
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
                  and len(trace.read_text().splitlines()) == len(JOBS))
        finally:
            crash_release.touch()
            if crashed.poll() is None:
                crashed.kill()
                crashed.wait(timeout=3)
        trace.unlink()

        # Simulate a hard coordinator crash DURING a financial worker. The
        # orphan child must retain fd 3's kernel lease until that child exits.
        crash_release = root / "release-canonical-orphan"
        crash_env = {**env, "ACHBOT_TEST_ONLY_PAUSE": JOBS[1],
                     "ACHBOT_TEST_ONLY_RELEASE": str(crash_release)}
        crashed = subprocess.Popen(
            [php, *PHP_CLI_OPTS, str(entrypoint)],
            cwd=work, env=crash_env, stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
        )
        try:
            for _ in range(200):
                if trace.exists() and len(trace.read_text().splitlines()) >= 2:
                    break
                if crashed.poll() is not None:
                    raise RuntimeError("Coordinator died before crash simulation")
                time.sleep(0.02)
            else:
                raise RuntimeError("Worker never reached crash simulation")
            crashed.kill()
            crashed.wait(timeout=3)
            in_flight = cmd(php, entrypoint, work, env)
            check("orphaned_canonical_worker_retains_lock_after_parent_kill",
                  in_flight.returncode == 0
                  and "previous invocation active; skipping" in in_flight.stderr
                  and len(trace.read_text().splitlines()) == 2)
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
            check("new_run_recovers_after_orphan_canonical_worker_exit",
                  recovered.returncode == 0
                  and len(trace.read_text().splitlines()) == len(JOBS))
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
        alias = Path(tmp) / "untrusted-directory-alias"
        alias.symlink_to(lock_dir, target_is_directory=True)
        alias_env = {**env, "ACHBOT_CRON_LOCK_DIR": str(alias)}
        alias_denied = cmd(php, entrypoint, work, alias_env)
        check("symlinked_lock_directory_alias_denied",
              alias_denied.returncode == 78
              and "no tasks started" in alias_denied.stderr
              and not trace.exists())
        # Missing INSIDE the served installation remains forbidden; missing
        # trusted external paths are exercised by directory_provisioning_cases.
        bad_env = {**env, "ACHBOT_CRON_LOCK_DIR": str(root / "absent-lock-dir")}
        invalid = cmd(php, entrypoint, work, bad_env)
        check("unavailable_lock_directory_fails_closed",
              invalid.returncode == 78 and "no tasks started" in invalid.stderr
              and not trace.exists())
        lock_file.unlink()
        attack_target = root / "outside-owned-fixture"
        attack_target.write_text("untouched", encoding="utf-8")
        lock_file.symlink_to(attack_target)
        start = time.monotonic()
        invalid_link = cmd(php, entrypoint, work, env)
        check("symlink_lock_path_fails_closed",
              invalid_link.returncode == 78 and "no tasks started" in invalid_link.stderr
              and time.monotonic() - start < 2
              and not trace.exists() and attack_target.read_text() == "untouched")
        lock_file.unlink()

        dangling = Path(tmp) / "target-must-never-be-created"
        lock_file.symlink_to(dangling)
        start = time.monotonic()
        dangling_result = cmd(php, entrypoint, work, env)
        check("dangling_symlink_rejected_without_target_creation",
              dangling_result.returncode == 78
              and "no tasks started" in dangling_result.stderr
              and time.monotonic() - start < 2
              and not trace.exists() and not dangling.exists())
        lock_file.unlink()

        os.mkfifo(lock_file, mode=0o600)
        start = time.monotonic()
        fifo_result = cmd(php, entrypoint, work, env)
        check("fifo_preexisting_lock_rejected_without_block",
              fifo_result.returncode == 78
              and "no tasks started" in fifo_result.stderr
              and time.monotonic() - start < 2
              and not trace.exists())
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

        shared = Path(tmp) / "shared-sticky"
        shared.mkdir()
        shared.chmod(0o1777)
        squatted_lock = shared / lock_file.name
        squatted_lock.write_text("untrusted file remains untouched")
        shared_env = {**env, "ACHBOT_CRON_LOCK_DIR": str(shared)}
        start = time.monotonic()
        squatted = cmd(php, entrypoint, work, shared_env)
        check("shared_sticky_lock_directory_and_preempted_name_denied",
              squatted.returncode == 78
              and "no tasks started" in squatted.stderr
              and time.monotonic() - start < 2
              and squatted_lock.read_text() == "untrusted file remains untouched"
              and not trace.exists())

        untrusted_parent = Path(tmp) / "untrusted-parent"
        untrusted_parent.mkdir()
        untrusted_parent.chmod(0o777)
        protected_child = untrusted_parent / "apparently-private"
        protected_child.mkdir(mode=0o700)
        parent_env = {**env, "ACHBOT_CRON_LOCK_DIR": str(protected_child)}
        rejected_parent = cmd(php, entrypoint, work, parent_env)
        check("writable_nonsticky_ancestor_denied",
              rejected_parent.returncode == 78
              and "no tasks started" in rejected_parent.stderr
              and not (protected_child / lock_file.name).exists()
              and not trace.exists())

        disabled_posix = subprocess.run(
            [php, "-n", "-d", "extension=posix",
             "-d", "disable_functions=posix_geteuid", str(entrypoint)],
            cwd=work, env=env, capture_output=True, text=True, timeout=4,
        )
        check("missing_posix_effective_uid_denied",
              disabled_posix.returncode == 78
              and "no tasks started" in disabled_posix.stderr
              and not trace.exists())

        disabled_flock = cmd(php, entrypoint, work, env, disable_flock=True)
        check("unavailable_php_flock_fails_closed",
              disabled_flock.returncode == 78
              and "no tasks started" in disabled_flock.stderr
              and not trace.exists())
        direct=cmd(php,entrypoint,work,env,'--job=broadcast')
        check('direct_child_without_inherited_lease_denied',
              direct.returncode==78 and not trace.exists())
        wrong=cmd(php,entrypoint,work,env,'--job=unknown')
        check('unknown_child_mode_denied',wrong.returncode==64 and not trace.exists())
        def child_with_fd(fd,job='broadcast',php_options=()):
            return subprocess.run([php,*PHP_CLI_OPTS,*php_options,str(entrypoint),'--job='+job],
                cwd=work,env=env,capture_output=True,text=True,timeout=18,
                pass_fds=(fd,),preexec_fn=lambda:os.dup2(fd,3,inheritable=True))
        lock_file.write_text('');lock_file.chmod(0o600)
        with lock_file.open('rb') as independent_fd:
            unheld=child_with_fd(independent_fd.fileno())
            check('independent_unlocked_fd_is_not_child_admission',
                  unheld.returncode==78 and not trace.exists())
            fcntl.flock(independent_fd.fileno(),fcntl.LOCK_SH)
            read_only_lease=child_with_fd(independent_fd.fileno())
            check('shared_read_flock_is_not_exclusive_worker_lease',
                  read_only_lease.returncode==78 and not trace.exists())
            fcntl.flock(independent_fd.fileno(),fcntl.LOCK_UN)
        unrelated=lock_dir/'unrelated-held-file'
        unrelated.write_text('');unrelated.chmod(0o600)
        with unrelated.open('rb') as wrong_fd:
            fcntl.flock(wrong_fd.fileno(),fcntl.LOCK_EX)
            wrong_inode=child_with_fd(wrong_fd.fileno())
            check('different_exclusively_locked_inode_denied',
                  wrong_inode.returncode==78 and not trace.exists())
        with lock_file.open('rb') as held_fd:
            fcntl.flock(held_fd.fileno(),fcntl.LOCK_EX)
            no_fdinfo=child_with_fd(held_fd.fileno(),php_options=('-d','disable_functions=file_get_contents'))
            check('missing_kernel_fdinfo_capability_denies_child',
                  no_fdinfo.returncode==78 and not trace.exists())
            admitted=child_with_fd(held_fd.fileno())
            check('canonical_broadcast_child_runs_only_under_inherited_lease',
                  admitted.returncode==0 and len(trace.read_text().splitlines())==1 and
                  trace.read_text().split('|')[0]=='broadcast')
            with lock_file.open('rb') as probe:
                retained=False
                try:fcntl.flock(probe.fileno(),fcntl.LOCK_EX|fcntl.LOCK_NB)
                except BlockingIOError:retained=True
                check('canonical_child_never_unlocks_parent_open_file_description',retained)
            fcntl.flock(held_fd.fileno(),fcntl.LOCK_UN)
        trace.unlink()
        check('retired_worker_wrappers_and_values_are_not_staged',
              all(not (entrypoint.parent/name).exists() for name in ['messagewizwiz.php','warnusers.php','values.php']))
        missing_proc=cmd(php,entrypoint,work,env,'--job=services',disable_proc=True)
        check('child_mode_missing_process_capability_denied',missing_proc.returncode==78 and not trace.exists())
        env["ACHBOT_TEST_ONLY_FAIL"] = JOBS[1]
        failing = cmd(php, entrypoint, work, env)
        lines = trace.read_text().splitlines()
        check("failed_child_does_not_skip_remaining_workers",
              failing.returncode == 1 and len(lines) == len(JOBS)
              and [line.split("|")[0] for line in lines] == list(JOBS)
              and (JOBS[1] + " exited with status 23") in failing.stderr)
        check("no_child_exception_details_emitted_by_runner",
              "Throwable" not in failing.stderr and "Traceback" not in failing.stderr)
        trace.unlink()

        (entrypoint.parent / JOBS[2]).unlink()
        env["ACHBOT_TEST_ONLY_FAIL"] = ""
        missing = cmd(php, entrypoint, work, env)
        lines = trace.read_text().splitlines()
        check("missing_worker_fails_but_others_continue",
              missing.returncode == 1 and len(lines) == len(JOBS)-1
              and [line.split("|")[0] for line in lines] ==
              [job for job in JOBS if job != JOBS[2]]
              and ("missing or invalid worker: " + JOBS[2]) in missing.stderr)
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
        pattern_start=text.index(chr(39)+'/^lock:')+1
        lock_pattern=text[pattern_start:text.index(chr(39)+',',pattern_start)]
        records=[
            ("lock:\\t1: FLOCK  ADVISORY  WRITE 282947 00:26:4990488 0 EOF\\n",True),
            ("lock: 123: FLOCK ADVISORY WRITE 1 1a:0F:999999999 0 EOF\\n",True),
            ("lock: 1: FLOCK ADVISORY READ 12 00:26:4990488 0 EOF\\n",False),
            ("lock: 1: POSIX ADVISORY WRITE 12 00:26:4990488 0 EOF\\n",False),
            ("lock: 1: FLOCK ADVISORY WRITE 12 00:26:4990488 1 EOF\\n",False),
            ("lock: 1: FLOCK ADVISORY WRITE 12 00:26:4990488 0 100\\n",False),
            ("lock: 1: FLOCK ADVISORY WRITE 12 00:26:4990488 0 EOF trailing\\n",False),
            ("ino: 4990488\\n",False),
        ]
        records=[(v.replace(chr(92)+'t',chr(9)).replace(chr(92)+'n',chr(10)),ok)for v,ok in records]
        # Python strings above are transmitted as data; PHP executes the exact
        # regex extracted from the actual coordinator, never an imitation.
        probe=r'$s=json_decode(stream_get_contents(STDIN),true);$out=[];foreach($s["records"]as$r)$out[]=preg_match($s["pattern"],$r[0])===1;echo json_encode($out);'
        parsed=json.loads(subprocess.check_output([php,'-r',probe],
            input=json.dumps({'pattern':lock_pattern,'records':records}).encode()))
        check('inherited_lease_fdinfo_parser_native_spaces_and_negative_controls',
              parsed==[ok for _,ok in records])
        check("observed_chili_order_bounded_and_explicit",
              all(text[text.index('$jobs=['):].count("'name'=>'"+x+"'")==1 for x in JOBS)
              and text.index("'name'=>'"+JOBS[0]+"'") < text.index("'name'=>'"+JOBS[1]+"'") <
                  text.index("'name'=>'"+JOBS[2]+"'") < text.index("'name'=>'"+JOBS[3]+"'")
              and len(JOBS)==4 and "tronChecker.php" not in text)
        for real_worker in ['gift2all.php','rewardReport.php']:
            staged=entrypoint.parent/real_worker;original=staged.read_bytes()
            bootstrap_trace=root/'http-bootstrap-must-not-run'
            original_config=(root/'config.php').read_bytes()
            (root/'config.php').write_text('<?php file_put_contents('+json.dumps(str(bootstrap_trace))+',"unexpected bootstrap");')
            try:
                staged.write_bytes((ROOT/'settings'/real_worker).read_bytes())
                check('real_'+real_worker+'_http_denied_before_bootstrap',
                      http_rejection(php,root,env,'settings/'+real_worker) and
                      not bootstrap_trace.exists() and not trace.exists())
            finally:
                staged.write_bytes(original);(root/'config.php').write_bytes(original_config)
        parent_prefix=text.split('if ($workerMode!==null)',1)[0]
        check("source_has_no_database_or_provider_bootstrap",
              not re.search(r"\b(?:mysqli|PDO|curl_init|file_get_contents)\s*\(",parent_prefix)
              and "baseInfo.php" not in parent_prefix and "config.php" not in parent_prefix)


        # The owned API spy throws before returning. The actual coordinator's
        # finally block must still invoke PayUX, preserving all four outputs.
        maintenance_trace.unlink()
        api_failure = Path(str(trace) + '.api-failure')
        api_failure.write_text('owned controlled failure', encoding='utf-8')
        try:
            api_failed = cmd(php, entrypoint, work, env)
        finally:
            api_failure.unlink()
        api_finally = (
            api_failed.returncode == 0
            and api_failed.stdout == 'owned fixture success\n' * len(JOBS)
            and api_failed.stderr.strip() == 'achbot_payment_lifecycle_deferred'
            and maintenance_trace.read_text().splitlines() == ['api', 'payux']
            and [line.split('|')[0] for line in trace.read_text().splitlines()] == list(JOBS)
        )
        check('api_payment_failure_preserves_payux_finally', api_finally)


    report = {
        "complete": True,
        "cases": len(result_names),
        "scenarios": result_names,
        "source_only": True,
        "runner_sha256": hashlib.sha256(RUNNER.read_bytes()).hexdigest(),
        "actual_directory_autoprovisioning": True,
        "actual_current_runner_executed": True,
        "isolated_child_php_processes": True,
        "actual_api_maintenance_boundary": True,
        "api_before_payux": api_order,
        "api_failure_payux_finally": api_finally,
        "actual_cron_or_bot_job_executed": False,
        "production_database_or_service_modified": False,
    }
    if args.report:
        args.report.write_text(json.dumps(report, indent=2) + "\n")
    print(json.dumps({k: v for k, v in report.items() if k != "scenarios"}))


if __name__ == "__main__":
    main()
