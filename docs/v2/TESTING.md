# V2 evidence and reproducible checks

Run in a development checkout only. Tests use synthetic identities/data and must never load a real `baseInfo.php` or access Telegram, payment or VPN providers.

```sh
python3 tools/check_v2.py --report /tmp/achbot-v2-check.json
python3 tools/test_schema.py --run-disposable --report /tmp/achbot-v2-schema.json
python3 tools/test_native_telegram.py --run-loopback --report /tmp/achbot-v2-http.json
python3 tools/rehearse_swap.py --report /tmp/achbot-v2-swap.json
```

Existing prerequisites: Python 3, PHP 8.4 CLI/tokenizer; native HTTP also requires the existing PHP cURL extension; native DB requires the existing MariaDB tools plus mysqli/mysqlnd. These commands do not install packages, modify host services or connect to an existing database. `--focused` on the main gate is an authoring shortcut only, not full acceptance evidence. No V2 recording/regeneration option exists.

## Independent layers

1. **Immutable references.** `tools/v2_contract.py` materializes the exact reviewed V1 tree from local Git objects with checked member paths/types/size. It neither fetches nor changes branches. All 33 existing V0/V1 tooling/fixture/document files are frozen. The full gate executes the pinned V1 gate (including V0) with the current repository's object database exposed read-only through Git environment variables. No golden output is re-recorded.
2. **Bounded source change.** The expected facade/module extraction is reconstructed from V1. Only sixteen function bodies and the incoming assignment block in `config.php` may change. Twenty-three complete runtime/reference files, including DDL and the V1 modules, remain byte-identical. A structural guard is not independent review of the transformation's correctness.
3. **Existing behavior.** All 121 original cases run against the candidate and compare with the immutable V0 traces. The V1 300-vector helper suite also compares old/current behavior under weak and strict callers.
4. **Telegram boundary.** 84 transport/message cases run under weak and strict callers. Old behavior is compared to both the current public wrappers and direct modules: 336 comparison pairs, not 336 distinct product features. Cases cover all sixteen moved functions, defaults/named arguments, form/multipart handling, object/array/scalar/false/null results, cURL errors, malformed/empty responses, `429`, HTTP errors, log rotation at/above the boundary, fallback requests, files and legacy GET callback routing.
5. **Incoming bindings.** 135 raw-update cases execute the original block and actual new scoped include. They compare warnings/exceptions, field existence/values, existing variable overlay, reference aliases and partial assignment state after malformed input. Both message/callback sections still run in the original order. Unrelated incoming object keys cannot become caller variables.
6. **Controls.** Fifteen controls include twelve negative controls. Wrong token forwarding, timeout, CURLFile namespace, callback binding and log location must change differential output as well as fail the source guard. Missing modules, unexpected code, symlinks, schema/fixture drift and unrelated bootstrap edits are rejected. Positive checks cover the exact candidate, empty-context load and reproducible packaging.
7. **Actual cURL and wire representation.** Sixteen old/new comparisons execute 32 real HTTP requests against a parent-owned `127.0.0.1` ephemeral server. This is real PHP cURL, not the main suite's curl doubles. Test-only namespace interceptors remap only the fixed synthetic Telegram URL to that server, reject other URLs and redirects, and clear proxy use. Responses include success, `429`, `503`, empty/malformed/null and connection closure. Multipart fields, filenames/content and form bytes are compared; only cURL's generated MIME boundary is normalized. This does **not** test production TLS, DNS, actual Telegram quotas or delivery.
8. **Native SQL.** The existing separate socket-only MariaDB suite remains unchanged: 23 tables, 13 transaction/binding/boundary tests, 483 successful constant preparations and the same classified legacy failures. Its original engine/session/version and before/after schema evidence remain distinct from mocks.
9. **Source-swap rehearsal.** Two private file-copy drills cover V0 -> V1 -> V0 and V1 -> V2 -> V1. Each has before, module-first, activated and rolled-back phases, with 25 existing CLI smoke cases per phase (200 executions). Private configuration, asset and state **sentinels** remain unchanged. Those sentinels are not a real database; these drills do not establish persistent DB conversation continuity, PHP-FPM/OPcache behavior or manual operator acceptance.

## Isolation and comparison rules

Mock cases run in a fresh private directory with `php -n`, no native curl/mysqli/FFI, URL access disabled, restricted environment/open_basedir, and process/network/resource limits. The callback GET test uses a local scripted stream wrapper, not network access. Existing fail-closed SQL/external-call doubles are unchanged.

Returned serialized types/values, emitted events/payloads, message strings and URLs are compared. Only internal function names and physical source-location suffixes in diagnostics are normalized. Log checks normalize only the private fixture directory in logged file paths before hashing; all other log content and relative log paths remain observable.

The native cURL test enables networking **only for its own loopback fixture** and uses actual curl leaves behind explicit test-only URL guards. It is not a general-purpose hostile-code sandbox. Inspect application/test changes before running it. The listener is closed in `finally`; no existing web port or service is used. Native DB tests retain their separate owned socket/datadir checks and cleanup.

Reports identify Git HEAD/tree and whether the working tree was dirty. Authoring reports from dirty source are not represented as exact-commit evidence. Final CI checks an exact commit, builds the artifact after checks and records the manifest. Passing author tests/CI does not provide independent review or operator approval.

## Remaining acceptance

Fresh exact-candidate independent review, operator checks of the actual supported environment, old sent Telegram keyboards, all relevant roles/persisted wizards, live-provider non-production success/failure paths, and real deployment rollback remain separate. No full-route coverage, concurrency, performance improvement, security clearance or production readiness is inferred. V0-R01 through V0-R15 remain existing risks, not silently fixed defects.
