# AChBot V5 — independently reproducible verification

Every command runs in an authorized **disposable development runner** with PHP 8.4, Python 3, MariaDB 10.11 tools and no real `baseInfo.php`, Telegram token, payment provider, VPN panel or customer DB. Existing checked-in historical tests and goldens must remain immutable.

```bash
python3 tools/check_v5.py --report /tmp/achbot-v5-full.json
```

The **full** gate verifies:

1. `tools/v5_contract.py`: pinned V4 provenance, exact moved bodies, old runtime unchanged outside the 3 explicit wrappers, original DDL identity, all frozen historical fixtures, absence of unexpected PHP files and symlinks.
2. `php -l`: every applicable runtime PHP file (42 `.php` members; the 43rd is `index.html`).
3. **Frozen full V4+V3+V2+V1+V0** gate executed in a **fresh, pinned Git archive of the integrated V4 base** using unmodified historical source/tests; not run against altered V5 routing. Also the six old full native/loopback/rollback reference checks (`check_v4`, `test_schema`, `test_v4_transport`, `test_access_state`, `test_access_http`, `rehearse_v4`). `--focused` is **never** acceptance.
4. **108 differential admin/wizard cases** (`test_v5_admin.py`): multiple actors/admin flags, audiences, title, text/photo/forward selection, history, edit/pin/unpin/delete, job listing/create/cancel, Telegram and ordered SQL/binds, caller-scope and early-return propagation.
5. **29 differential original scheduled-entrypoint cases** (`test_v5_worker.py`): both queues, all four message actions, message/audience classes, worker shutdown and 429/retry_after, 403 logging, cancellation, completion, warning/expiry notification, exact ordered SQL and Telegram request/payload sequence, warning bytes and log location. Test clocks/sleep are synthetic only.
6. **8 deliberate negative controls** (`test_v5_mutation.py`) that detect callback mutation, administrative SQL mutation, 429 retry/sleep changes in each worker source selection change, early-return behavior, checkpoint cursor and legacy error-log location, without rewriting any frozen fixture.
7. **14 scenario native MariaDB E2E / 32+ PHP process steps** (`test_v5_native.py`): original 23-table DDL in two owned databases, private Unix socket with `--skip-networking` and synthetic account/panel data, recovery after intentional interruptions, rate limits, pause/cancel/complete, persisted `offset`/messages/counters, warning and expiry notifications, real V4→V5→V4 code switching with unchanged synthetic private sentinels. No real Telegram/panel requests.
8. **10 GET/POST direct HTTP module probes** (`test_v5_http.py`): five internal modules each respond with empty 404 on an owned loopback PHP server.
9. Complete deterministic runtime ZIP/manifest with all 43 source members; after committing a clean candidate `tools/package_v5.py` checks each on-disk member against `HEAD:path` and the recomputed Git blob SHA1 and ZIP SHA256. The package excludes tests, SQL DDL/data, local configs/secrets, backups, logs and private state.

Run focused diagnostics during development (not full acceptance):

```bash
python3 tools/check_v5.py --focused
python3 tools/test_v5_admin.py --case job.create
python3 tools/test_v5_worker.py --case job.429
python3 tools/test_v5_native.py --run-disposable --case send.interrupt
python3 tools/test_v5_http.py --run-loopback
```

**Native MariaDB assurance limits:** A managed private datadir is initialized with `mariadb-install-db --no-defaults`, socket-only isolated `mariadbd --skip-networking`, loaded with *original* schema plus synthetic rows and destroyed after the run. The native PHP uses `mysqli/mysqlnd` and real SQL; Telegram/panel requests and sleeps are fixture-controlled. No tests connect to existing DBs. Tests compare selected committed state and request traces, not genuine Telegram acknowledgement delivery, production concurrency or actual financial/provider side effects.

**CI:** the branch PR runs the full gate at checkout of exact PR HEAD on an ephemeral runner, uploads a complete source-only ZIP with separate JSON manifest/evidence, and verifies archive bytes against exact Git blobs. Record executed/skipped test sections from the **actual** run, not just a green status. An author-separated HIGH_ASSURANCE review and operator staging acceptance are separate gates.

After building from **clean committed HEAD**, an operator or CI runner can independently verify the ZIP and manifest against all committed Git blobs:

```bash
python3 tools/verify_v5_package.py --zip /tmp/achbot-v5/achbot-v5-runtime.zip --manifest /tmp/achbot-v5/achbot-v5-runtime.zip.manifest.json
```
