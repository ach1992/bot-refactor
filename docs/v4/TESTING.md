# V4 verification, limits and reproducible commands

Run only in a disposable development checkout with existing Python 3,
PHP 8.4 CLI/tokenizer, and for native suites mysqli/mysqlnd/MariaDB/cURL.
No real `baseInfo.php`, existing DB, provider, customer records
or live Telegram endpoint is read or modified.

```
python3 tools/check_v4.py --report /tmp/achbot-v4-check.json
python3 tools/test_schema.py --run-disposable --report /tmp/achbot-v4-schema.json
python3 tools/test_v4_transport.py --run-loopback --report /tmp/achbot-v4-wire.json
python3 tools/test_access_state.py --run-disposable --report /tmp/achbot-v4-access.json
python3 tools/test_access_http.py --run-loopback --report /tmp/achbot-v4-direct.json
python3 tools/rehearse_v4.py --report /tmp/achbot-v4-swap.json
```

`--focused` and `--case` are authoring-only and are
never complete candidate acceptance evidence. No recording/regeneration
option exists. Exact-commit CI and its real artifact are the authoritative
release-candidate evidence, not a dirty author checkout.

| Layer | Verified target and scope limit |
|---|---|
| Historical references | Frozen V3/V2/V1/V0 gates from pinned immutable trees; 62 earlier evidence files unchanged |
| Source guard | Six exact-body moves, original route positions, 31 other previous runtime members unchanged, schema byte-identical, symlink/extra-module rejection |
| Old regression | 121 original golden cases plus 300 V1 helper vectors per typing mode |
| Admin/agent comparison | 229 old/new cases, 11 with preexisting matching warnings; ordered SQL/binds, messages, callbacks, stored writes, caller scope/aliases, warnings/exceptions and reentry |
| Entire router | 65 cases executing old/new real `bot.php` and startup/helpers with fail-closed I/O doubles |
| Mutation controls | 17 tests (15 negative) sensitive to authorization, state, SQL, callbacks/routing, guard/schema/fixture drift, reentry and package completeness |
| Native persistence | 19 paired logical actions / 38 fresh PHP processes on two original-schema socket-only temporary MariaDB DBs, including V4->V3 pending-wizard continuation |
| Original native schema | 23 tables and 13 existing DB cases; classified inherited SQL failures unchanged |
| Native transport | Frozen V3 cURL 16-pair/32-loopback-request regression plus byte check of unchanged V4 transport files |
| Direct access | All six handlers reject GET/POST directly with empty 404 on owned loopback PHP dev server: 12 checks |
| File-copy drill | V3->V4->V3, four phases / 100 CLI smoke executions, private-file sentinels byte-preserved |
| Package | 38 deterministic runtime files, manifest with Git member IDs/hash; excludes SQL, tests, real config and state |

Native database scripts create an **owned private** datadir/socket with
`--no-defaults` and `--skip-networking`.
They verify exact socket/datadir/ownership and load the original 23-table
DDL only into two synthetic databases. Old and new sides execute original
`setUser` and compare stored rows/notifications/warnings after
each action across old source, modules-first, candidate, rollback and
reapplication. This is *selected* real synthetic persisted-state continuity;
it does not prove all pending wizards or production database compatibility.

The routing comparator cannot silently re-record old outputs.
SQL-bound values, request sequences, callback bytes and messages remain
observable; errors, partial scope and exit semantics are recorded.
The old warning/diagnostic physical file-line changes are not user-facing
contracts; semantic warnings are compared, not swallowed.

No actual PHP-FPM/OPcache, reverse-proxy configuration, real Telegram/VPN/
payments, concurrency, availability, security clearance, remote side-effect
recovery or production performance is certified. Manual operator acceptance
and actual deployment remain separate, outstanding gates.
