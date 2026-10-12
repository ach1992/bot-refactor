# AChBot V6 — exact-source and behavioral testing

The full gate runs only on an **authorized disposable runner** with PHP 8.4 (`mysqli`, `curl`, `mysqlnd`), MariaDB tools, Python 3, and historical Git blobs available. It **never accesses a real panel, Telegram, payment gateway, `baseInfo.php` or existing database**. Discrete fixtures live under owned temporary paths with private Unix-domain MariaDB sockets and Python/PHP localhost synthetic HTTP providers; they are terminated and deleted after each run.

```bash
python3 tools/check_v6.py --report /tmp/achbot-v6-check.json --evidence-dir /tmp/achbot-v6-evidence
```

Full acceptance includes:

1. SHA-bound source transform derived from merged V5 `38dcdfc...`, with exactly 40 public function facades, eight ordered route modules, ten guarded new files, unchanged 43 older members outside the allowed transformed originals, unchanged `settings/subLink.php` and all original 23-table DDL and V0–V5 evidence bytes. It forbids unexpectedly added runtime PHP files and unsafe symlinks.
2. Real `php -l` of **52** runtime PHP members; original HTML member preserved; deterministic source-only 53-file ZIP dry-run.
3. **Full historical V0–V5** original acceptance re-run inside an immutable exact-V5 Git/archive/object-store view: all eleven existing V5 suite groups including frozen V4/V3/V2/V1/V0 golden/reference, old original-schema native MariaDB and rollback, original Telegram/admin/Cron/mutation/HTTP checks. No historical goldens are rewritten or copied from V6.
4. `test_v6_panels.py`: PHP 8.4 with closed synthetic I/O and 29 scripted V5↔V6 exact ordered SQL-bind/panel cURL-option/return/error comparisons, including invalid Marzban credentials, 503/network failures, missing users and account traffic cases.
5. `test_v6_routes.py`: 96 PHP Telegram/SQL state and return-parity cases over customer/admin/agent route positions, original callback bytes, actor access, deletion `elseif` ancestry, duplicate transfer handling, original error/warning behavior and in-flight callbacks.
6. `test_v6_native.py`: 41 paired real PHP mysqli+cURL scenarios on **two owned original-schema MariaDB databases**; 117 outbound synthetic provider calls per side for Marzban, Sanaei, Alireza, invalid login/HTTP errors, account read/link/remark/status/delete, with JSON result, warnings, SQL rows and provider method/path/headers/form/JSON payload comparisons. Only randomly generated multipart boundaries and validated `time()`-relative two-day/30-day expiry timestamps are canonicalized; other request content is retained.
7. `test_v6_native_routes.py`: 8 real MariaDB + PHP/cURL + synthetic Telegram account-deletion, subLink settings and transfer callbacks; compare actual SQL row mutations, upstream panel method/body and actor-filtered Telegram traces.
8. `test_v6_bootstrap.py`: execute the original **whole** `config.php` unchanged in both V5 and V6 against real owned 23-table MariaDB plus localhost X-UI/Marzban; verify all 40 global facade signatures, seven genuine panel HTTP requests, SQL/outputs, legacy include/negative-actor early exit, and unchanged original private-config sentinel. Does not issue real Telegram/payment requests.
9. `test_v6_subscription_http.py`: original public `settings/subLink.php` over real owned PHP HTTP with missing/invalid/valid token, byte-identical HTTP response, base64 output, server remark update, post-write SQL state and upstream provider transcript.
10. `test_v6_swap.py`: `V5 → V6 → V5 → V6` code-only overlay on a persisted synthetic subscription, with private config sentinel preserved, original 23-table `SHOW CREATE TABLE` unchanged, byte-identical endpoint output and unchanged runtime path/rollback assumptions.
11. `test_v6_mutation.py`: six actual deliberate behavioral negatives detecting mutated provider headers, encoded paths, SQL, old Telegram callback, account deletion callback and public subscription base64 body. Tests do not bless changed baseline.
12. `test_v6_consumers.py`: byte-fingerprint nine unchanged integration consumers, including payment, purchase, gift, rewards, Tron, warnings, Cron and original public endpoint, plus function-call inventory.
13. `test_v6_http.py`: original module paths are internal; all **20** direct GET/POST localhost probes on ten new modules must return empty 404. Only old public entrypoints remain public.

Development diagnostics **are not release approval**:

```bash
python3 tools/check_v6.py --focused
python3 tools/test_v6_panels.py --case marzban.user
python3 tools/test_v6_routes.py --case deletion.
python3 tools/test_v6_native.py --run-disposable --case sanaei.list
python3 tools/test_v6_subscription_http.py --run-disposable
```

Once a candidate is committed cleanly, build/check exactly that Git commit, outside the repository:

```bash
python3 tools/package_v6.py --output /tmp/achbot-v6-runtime.zip
python3 tools/verify_v6_package.py --zip /tmp/achbot-v6-runtime.zip --manifest /tmp/achbot-v6-runtime.zip.manifest.json
```

CI uploads the ZIP, complete Git blob/SHA256 manifest and suite evidence. Exact-HEAD CI must cover **all sections**, with no skipped historical or native gate. An independent read-only HIGH_ASSURANCE reviewer checks the exact PR HEAD and effective diff **once after stabilization**.

**Limits:** Synthetic Telegram/API, owned panel HTTP and disposable SQL cannot prove live provider versions/availability, real end-user delivery, operator's PHP-FPM/OPcache, actual Cron CWD/overlap, rate-limiting incidents, production wallet, transport-level TLS or manual V5/V6 operator acceptance. Those are separate owner/operator gates; lack of their evidence is not a claim that V6 is production-ready.
