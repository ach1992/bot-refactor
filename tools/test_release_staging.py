#!/usr/bin/env python3
"""Rehearse the published V9 source-only ZIP on two disposable installed trees.

This is an actual temporary full-PHP HTTP + original MariaDB + loopback-panel
check, not real owner-installed/FPM/Telegram/payment/Cron acceptance. The
positive Telegram webhook and real payment endpoints are intentionally
excluded. The published ZIP has no private file; generate only fake settings.
"""
from __future__ import annotations

import argparse
import contextlib
import hashlib
import json
import re
import socket
import subprocess
import time
import urllib.error
import urllib.request
import zipfile
from pathlib import Path

import test_access_state as support
import test_v6_bootstrap as bootstrap
import test_v6_native as native
import test_v9_bootstrap as config
import v9_contract as contract
import v6_contract

PUBLISHED_SHA = "7eb077fd8e6d0a303421626d1500f69e295a6664"
PUBLISHED_TREE = "7da17725c1e7f0448119b7e41c8b11b21f3e093d"
PUBLISHED_ZIP_SHA256 = "5a2fd006048aaba4086f183a07e034ed9716da8277692128fd77006f60c585c0"
PUBLIC_PATHS = tuple(contract.PUBLIC_ENTRYPOINTS)


def sha(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def source_git(*args: str) -> str:
    return subprocess.check_output(
        ["git", "-C", str(contract.ROOT), *args], text=True
    ).strip()


def verify_published(archive: Path, manifest: Path) -> list[str]:
    metadata = json.loads(manifest.read_text())
    if metadata.get("candidate") != PUBLISHED_SHA or metadata.get("tree") != PUBLISHED_TREE:
        raise RuntimeError("Not the published V9 merge revision")
    if metadata.get("file_count") != 92 or metadata.get("zip_sha256") != PUBLISHED_ZIP_SHA256:
        raise RuntimeError("Unexpected published V9 archive identity")
    if sha(archive.read_bytes()) != PUBLISHED_ZIP_SHA256:
        raise RuntimeError("Published V9 archive SHA256 mismatch")
    if source_git("rev-parse", PUBLISHED_SHA + "^{tree}") != PUBLISHED_TREE:
        raise RuntimeError("Pinned V9 tree was not found or changed")
    members = sorted(contract.members())
    if len(members) != 92 or list(metadata.get("files", {})) != members:
        raise RuntimeError("V9 full-runtime manifest membership drift")
    for forbidden in (
        "baseInfo_php_included", "sql_data_included", "production_approved",
        "deploy_approved", "mysql_schema_migration_included"
    ):
        if metadata.get(forbidden) is not False:
            raise RuntimeError("Release safety flag not verified: " + forbidden)
    with zipfile.ZipFile(archive) as z:
        if z.namelist() != members:
            raise RuntimeError("Unapproved member, ordering or file in V9 release ZIP")
        for entry in z.infolist():
            name = entry.filename
            if (entry.is_dir() or name.startswith("/") or
                    ".." in Path(name).parts or (entry.external_attr >> 16) & 0o170000 == 0o120000):
                raise RuntimeError("Untrusted release member path or symlink")
            raw = z.read(entry)
            expected = metadata["files"][name]
            blob = hashlib.sha1(b"blob " + str(len(raw)).encode() + b"\0" + raw).hexdigest()
            if (sha(raw) != expected["sha256"] or len(raw) != expected["size"]
                    or blob != expected["git_blob_sha1"]
                    or blob != source_git("rev-parse", PUBLISHED_SHA + ":" + name)):
                raise RuntimeError("Git/manifest/source member mismatch: " + name)
    return members


def stage(archive: Path, root: Path, db: str, members: list[str]) -> dict[str, str]:
    root.mkdir(parents=True, exist_ok=False)
    with zipfile.ZipFile(archive) as z:
        for name in members:
            dest = root / name
            dest.parent.mkdir(parents=True, exist_ok=True)
            dest.write_bytes(z.read(name))
    (root / "bootstrap.php").write_bytes((contract.ROOT / "tests/v6/bootstrap.php").read_bytes())
    (root / "names.json").write_text(json.dumps(
        v6_contract.PANEL_FUNCS + v6_contract.DETAIL_FUNCS))
    (root / "baseInfo.php").write_bytes(config.synthetic_private(db))
    (root / "private-state.json").write_text('{"fixture":"source-only-V9-staging"}\n')
    watched = ("baseInfo.php", "private-state.json")
    return {name: sha((root / name).read_bytes()) for name in watched}


@contextlib.contextmanager
def php_http(root: Path, work: Path, env: dict):
    with socket.socket() as s:
        s.bind(("127.0.0.1", 0))
        port = s.getsockname()[1]
    # Do not permit accidental external HTTP(S) egress from fake settings;
    # the only allowed provider is owned 127.0.0.1 (no_proxy).
    safe_env = dict(env, http_proxy="http://127.0.0.1:9",
                    https_proxy="http://127.0.0.1:9",
                    HTTP_PROXY="http://127.0.0.1:9",
                    HTTPS_PROXY="http://127.0.0.1:9",
                    all_proxy="http://127.0.0.1:9",
                    ALL_PROXY="http://127.0.0.1:9",
                    no_proxy="127.0.0.1,localhost",
                    NO_PROXY="127.0.0.1,localhost")
    disabled = "exec,shell_exec,system,passthru,popen,proc_open,pcntl_exec,pcntl_fork,mail,dl"
    cmd = [
        "php", "-n", "-d", "extension=mysqlnd", "-d", "extension=mysqli",
        "-d", "extension=curl", "-d", "mysqli.default_socket=" + str(work / "mysql.sock"),
        "-d", "open_basedir=" + str(root) + ":" + str(work),
        "-d", "allow_url_fopen=0", "-d", "allow_url_include=0",
        "-d", "display_errors=0", "-d", "log_errors=0",
        "-d", "disable_functions=" + disabled,
        "-S", "127.0.0.1:" + str(port), "-t", str(root),
    ]
    with (work / "php-http.log").open("ab") as log:
        server = subprocess.Popen(cmd, cwd=root, env=safe_env,
                                  stdout=log, stderr=log)
        try:
            for _ in range(100):
                if server.poll() is not None:
                    raise RuntimeError("Disposable PHP HTTP server exited unexpectedly")
                try:
                    with socket.create_connection(("127.0.0.1", port), timeout=.25):
                        break
                except OSError:
                    time.sleep(.05)
            else:
                raise RuntimeError("Disposable PHP HTTP server did not start")
            yield port
        finally:
            if server.poll() is None:
                server.terminate()
            try:
                server.wait(timeout=5)
            except subprocess.TimeoutExpired:
                server.kill()
                server.wait(timeout=3)


def get(port: int, path: str, method: str = "GET") -> dict:
    url = "http://127.0.0.1:" + str(port) + path
    req = urllib.request.Request(url, method=method)
    try:
        with urllib.request.urlopen(req, timeout=10) as response:
            return {"status": response.status, "body": response.read().decode("utf-8", "replace")}
    except urllib.error.HTTPError as response:
        return {"status": response.code, "body": response.read().decode("utf-8", "replace")}


def canonical_panel_wire(trace: list[dict]) -> tuple[list[dict], int]:
    # The legacy panel client synthesizes a random 16-char `subId` in its
    # JSON-in-multipart updateClient request. This field is not part of the
    # observable subscription URL output; only this exact random field is
    # normalized, never request path, recipient, headers, other form data.
    stable = []
    random_subids = 0
    for request in trace:
        copy = dict(request)
        if request["path"].startswith("/panel/inbound/updateClient/") and request["method"] == "POST":
            pattern = r'("subId":")[A-Za-z0-9]{16}(")'
            copy["body"], changed = re.subn(pattern, r'\1fixture-random-subid\2', request["body"])
            if changed != 1:
                raise RuntimeError("Unexpected updateClient subId structure; do not mask provider drift")
            random_subids += changed
        stable.append(copy)
    return stable, random_subids


def snapshot(work: Path, env: dict, db: str, tables: list[str]) -> dict:
    return config.snapshot(work, env, db, tables)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--run-disposable", action="store_true", required=True)
    parser.add_argument("--release-zip", type=Path, required=True)
    parser.add_argument("--manifest", type=Path, required=True)
    parser.add_argument("--report", type=Path)
    args = parser.parse_args()
    members = verify_published(args.release_zip, args.manifest)
    results = []
    with support.owned_database() as (work, env), native.owned_provider() as (provider, url):
        native.seed(work, env, url)
        sides = [(work / "staged-a", native.DBS[0]), (work / "staged-b", native.DBS[1])]
        before = {db: stage(args.release_zip, root, db, members) for root, db in sides}
        tables = native.sql(work, env, "SHOW TABLES", native.DBS[0]).splitlines()
        if len(tables) != 23:
            raise RuntimeError("Not original 23-table SQL schema")
        ddl = {db: config.ddl_snapshot(work, env, db, tables) for _, db in sides}
        if ddl[native.DBS[0]] != ddl[native.DBS[1]]:
            raise RuntimeError("Original-schema SQL mismatch before checks")
        observations = []
        traces = []
        for root, db in sides:
            provider.trace.clear()
            php = bootstrap.run(root, work, env, db)
            observations.append(php)
            traces.append(list(provider.trace))
        if observations[0] != observations[1] or traces[0] != traces[1]:
            raise RuntimeError("Deployed ZIP full config/bootstrap parity failed")
        if observations[0]["exception"] or len(observations[0]["result"]["signatures"]) != 40:
            raise RuntimeError("Real staged runtime global facade bootstrap failed")
        if len(traces[0]) != 7:
            raise RuntimeError("Staged loopback panel not reached seven times")
        results.append({"case": "full_config_bootstrap", "result": "PASS",
                        "global_facades": 40, "loopback_provider_requests": 7})
        # Only a strictly enumerated safe public HTTP surface:
        # 405 avoids health's REAL api.telegram.org/getMe path;
        # subscription uses solely synthetic DB + owned fake panel.
        cases = [
            ("health_post_denied", "/health.php", "POST"),
            ("subscription_missing", "/settings/subLink.php", "GET"),
            ("subscription_invalid", "/settings/subLink.php?token=short", "GET"),
            ("subscription_valid_fake", "/settings/subLink.php?token=" + "a" * 30, "GET"),
        ]
        initial_state = {db: snapshot(work, env, db, tables) for _, db in sides}
        for label, path, method in cases:
            before_request = {db: snapshot(work, env, db, tables) for _, db in sides}
            observed, wire = [], []
            for root, db in sides:
                provider.trace.clear()
                with php_http(root, work, env) as port:
                    response = get(port, path, method)
                observed.append(response)
                wire.append(list(provider.trace))
            stable_wires, counts = zip(*(canonical_panel_wire(x) for x in wire))
            if observed[0] != observed[1] or stable_wires[0] != stable_wires[1]:
                mismatch = next((i for i in range(min(len(wire[0]), len(wire[1])))
                                 if stable_wires[0][i] != stable_wires[1][i]), None)
                detail = {"response_equal": observed[0] == observed[1],
                          "wire_counts": [len(x) for x in wire],
                          "normalized_subids": counts, "first_mismatch_at": mismatch,
                          "wire_first_mismatch": ([stable_wires[0][mismatch], stable_wires[1][mismatch]]
                                                  if mismatch is not None else []),
                          "response_sha": [sha(x["body"].encode()) for x in observed]}
                raise RuntimeError("Staged HTTP response/wire parity failed: " + label +
                                   " " + json.dumps(detail,ensure_ascii=False))
            if label == "health_post_denied":
                if observed[0]["status"] != 405 or '"method_not_allowed"' not in observed[0]["body"] or wire[0]:
                    raise RuntimeError("Health POST guard failed")
            elif label in ("subscription_missing", "subscription_invalid"):
                if observed[0]["body"] != "Wrong token" or wire[0]:
                    raise RuntimeError("Malformed/missing subLink token was not rejected without panel access")
            else:
                import base64
                if observed[0]["status"] != 200 or not observed[0]["body"] or not wire[0]:
                    raise RuntimeError("Owned valid subscription HTTP and provider not exercised")
                if counts != (1, 1):
                    raise RuntimeError("Expected one known random client subId per owned request")
                try:
                    base64.b64decode(observed[0]["body"], validate=True)
                except Exception as e:
                    raise RuntimeError("Valid subscription body not base64") from e
            after_request = {db: snapshot(work, env, db, tables) for _, db in sides}
            if after_request[native.DBS[0]] != after_request[native.DBS[1]]:
                raise RuntimeError("Staged original databases diverged after " + label)
            table_changes = {
                db: [table for table in tables if after_request[db][table] != before_request[db][table]]
                for _, db in sides
            }
            if table_changes[native.DBS[0]] != table_changes[native.DBS[1]]:
                raise RuntimeError("Asymmetric SQL side effects after " + label)
            if label == "subscription_valid_fake":
                if table_changes[native.DBS[0]] != ["orders_list"]:
                    raise RuntimeError("Valid subscription wrote unexpected SQL tables: " +
                                       repr(table_changes))
                # The original endpoint refreshes the order's remark and
                # generated connection link; guard every other stored
                # financial/customer field from accidental mutation.
                def rows(raw: str) -> list[list[str]]:
                    return [line.split("\t") for line in raw.splitlines()]
                fields = [line.split("\t", 1)[0] for line in
                          native.sql(work, env, "SHOW COLUMNS FROM orders_list",
                                     native.DBS[0]).splitlines()]
                if len(fields) < 14 or not {"remark", "link"} <= set(fields):
                    raise RuntimeError("Original order schema for link refresh not recognized")
                changed_columns = []
                previous = rows(before_request[native.DBS[0]]["orders_list"])
                following = rows(after_request[native.DBS[0]]["orders_list"])
                if len(previous) != len(following):
                    raise RuntimeError("Public subscription altered number of orders")
                for prior, current in zip(previous, following):
                    if len(prior) != len(fields) or len(current) != len(fields):
                        raise RuntimeError("SQL row/column width changed")
                    changed_columns += [
                        column for column, earlier, newer
                        in zip(fields, prior, current) if earlier != newer
                    ]
                if sorted(changed_columns) != ["link", "remark"]:
                    raise RuntimeError("Unexpected order fields updated: " + repr(changed_columns))
            elif table_changes[native.DBS[0]]:
                raise RuntimeError("Rejected/method-denied request modified SQL: " +
                                   label + repr(table_changes))
            results.append({"case": label, "result": "PASS",
                            "status": observed[0]["status"],
                            "output_sha256": sha(observed[0]["body"].encode()),
                            "owned_provider_requests": len(wire[0]),
                            "normalized_16char_subid_fields": counts[0],
                            "sql_changed_tables": table_changes[native.DBS[0]],
                            "sql_changed_columns": (["link", "remark"]
                                                    if label == "subscription_valid_fake" else [])})
        after_state = {db: snapshot(work, env, db, tables) for _, db in sides}
        if after_state[native.DBS[0]] != after_state[native.DBS[1]]:
            raise RuntimeError("Staged original databases diverged after all requests")
        if [x for x in tables if after_state[native.DBS[0]][x] != initial_state[native.DBS[0]][x]] != ["orders_list"]:
            raise RuntimeError("Synthetic staging touched forbidden SQL tables")
        for root, db in sides:
            if config.ddl_snapshot(work, env, db, tables) != ddl[db]:
                raise RuntimeError("Staging changed old 23-table database schema")
            if any(sha((root / name).read_bytes()) != expected
                   for name, expected in before[db].items()):
                raise RuntimeError("Staging modified synthetic private file")
            actual_runtime = {p.relative_to(root).as_posix() for p in root.rglob("*.php")}
            wanted_runtime = {p for p in members if p.endswith(".php")}
            # bootstrap.php and synthetic baseInfo.php are staged-only,
            # deliberately absent from the public 92-member release ZIP.
            if actual_runtime - {"bootstrap.php", "baseInfo.php"} != wanted_runtime:
                raise RuntimeError("Staging PHP inventory drift: " +
                                   repr(sorted((actual_runtime -
                                       {"bootstrap.php", "baseInfo.php"}) ^ wanted_runtime)))
    report = {
        "published_tag": "v0.9.0-rc.1",
        "published_commit": PUBLISHED_SHA,
        "source_zip_sha256": PUBLISHED_ZIP_SHA256,
        "staged_source_members": len(members),
        "scenarios": results,
        "real_php_cli_config": True,
        "real_php_http_on_loopback": True,
        "original_mariadb_23_table_socket_only": True,
        "fake_panel_http_only": True,
        "sql_other_tables_unchanged": True,
        "subscription_updated_only_orders_list_remark_and_link": True,
        "schema_and_fake_private_config_unchanged": True,
        "full_telegram_webhook_executed": False,
        "live_payment_cron_executed": False,
        "php_fpm_opcache_tested": False,
        "real_installed_operator_accepted": False,
    }
    if args.report:
        args.report.write_text(json.dumps(report, indent=2,ensure_ascii=False) + "\n")
    print(json.dumps(report,ensure_ascii=False))


if __name__ == "__main__":
    main()
