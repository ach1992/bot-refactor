# Reproduce V0 safely

Run only in a development checkout. Never place the test harness in a public web root or run it beside live credentials. V0 tools are CLI-only where executable PHP is involved, but this is defense in depth, not web deployment authorization.

## Prerequisites and commands

The main harness uses Python 3 and PHP 8.4 CLI with tokenizer available. It uses only standard-library Python and bundled project test code, not Composer packages. Native DB checks additionally require existing `mariadb-install-db`, `mariadbd`, `mariadb`, PHP mysqlnd and mysqli. The scripts do not install host packages, touch existing services or attach to a configured database.

```sh
# Baseline integrity, generated catalog, PHP syntax, harness controls, 121 traces
python3 tools/check.py --report /tmp/achbot-v0-check.json

# Private, disposable socket-only MariaDB, original DDL, native SQL/transaction checks
python3 tools/test_schema.py --run-disposable --report /tmp/achbot-v0-schema.json

# Optional focused authoring checks; the full gate is still required before acceptance
python3 tests/run.py --case wallet.success
python3 tests/test_harness.py
python3 tools/inventory.py
```

Reports contain synthetic fixtures and source/test identities, not production credentials. Put reports outside tracked files; do not publish diagnostics that contain real runtime configuration. On unavailable prerequisites the tool fails rather than silently skipping a gate.

## What each layer establishes

| Layer | Evidence | Limits |
|---|---|---|
| 19-file byte lock | SHA-256 reference plus comparison to immutable Git commit when available; DDL and example config included | V0 only; a later refactor needs dual reference/new implementation testing, not indiscriminate re-recording |
| Lexical catalog | Reproducible function/include/SQL/callback/state/settings/side-effect inventories | Not whole-program type/control/data-flow analysis or proof of reachable authorization |
| Syntax | 24 PHP files, including original code and harness/tool PHP | Not runtime correctness or provider availability |
| Harness controls | Eight checks, including four deliberately detected mutations and literal/parser safeguards | A finite set of negative controls; no universal sandbox/security proof |
| 121 characterization cases | Frozen input/time/random/external responses; SQL types/values/order, local effects and outputs compared to stored traces; semantic assertions | Not 100% path coverage; mocked DB reads/writes cannot prove DB constraints or concurrency |
| Native MariaDB | 23 reference tables, constant SQL preparation, 13 transaction/binding/boundary/composed-SQL tests, schema fingerprint before/after | Private synthetic data only; engine version/SQL mode recorded, no real provider or production workload |
| Independent review | Fresh author-separated review of the exact candidate and tests | Must be obtained; self-review and CI success are not substitutes |
| Operator/provider acceptance | Actual supported environment and representative non-production end-to-end flows | Not performed automatically by V0 and cannot be inferred from these fixtures |

## Isolation design

Each mocked case runs in a fresh private temporary directory and subprocess. Only the locked reference source and test support are copied. A synthetic `baseInfo.php` is generated there; it is never copied back. The process has a restricted environment, `php -n`, no native mysqli/curl/FFI, disabled shell/process/network functions, URL file access disabled, `open_basedir`, and memory/CPU/time/file-size limits. Doubles reject unscripted SQL reads and external calls. Full-entrypoint tests include the original PHP file; function cases load byte-exact declarations identified with PHP's tokenizer, without executing the original eager bootstrap. Extraction provenance records the 82 declarations and source body hashes.

Time/random/sleep operations are deterministic stubs. Paths are normalized only where they point to the private temporary fixture. SQL layout whitespace is normalized outside quoted strings/identifiers; literal contents remain exact. Unexpected failures, incomplete scripted reads and changed traces fail normal comparison. The suite captures post-shutdown effects for the worker. It does not sandbox deliberately hostile arbitrary replacement PHP; inspect tests/source changes before execution.

The native DB launcher always makes its own temporary datadir, Unix socket, marker and schema, passes `--no-defaults` and `--skip-networking`, and invokes no production configuration. The probe verifies socket, datadir and networking state before touching synthetic data. Cleanup terminates only its owned process and removes its owned temporary directory. There is no schema upgrade or mutation of an existing server. Never add a host/socket override pointing to an established DB.

## Expected legacy SQL failures

`tests/fixtures/sql-prepare-baseline.json` records file/line/code/query hash and a statement-versus-fragment classification. Six failed preparations are expected: two statement/schema defects, four incomplete prefixes. Both suffixes of the four dynamic UPDATE prefixes are separately tested, exposing the actual `/empty` syntax defect. Twenty-four dynamic/other sites are excluded from the constant-literal preparation pass. A matching result means **no unexplained drift**, not that all SQL is valid.

Native reports capture engine version, SQL mode and schema hash. The development server used MariaDB 11.8.6 with PHP 8.4.24; the original dump records MariaDB 10.11.10/PHP 8.4.25. The GitHub workflow also exercises the available Ubuntu 24.04 MariaDB package with PHP 8.4. Read its actual run output for exact versions; never treat a package family or a different test engine as proof of the deployed environment.

## Snapshot and catalog changes

`python3 tools/inventory.py --write` regenerates static evidence. `python3 tests/run.py --record-baseline` and `python3 tools/test_schema.py --run-disposable --record-baseline --report <path>` are **maintainer recording operations**, never the normal CI path. Recording requires the unchanged imported 19-file source anchor. Review each semantic assertion and expected trace/known error; a new output is not correct merely because it was recorded. Keep the native fixture portable by comparing error codes/query identities, not version-dependent error prose.

Four temporary mutation controls prove detection of unexpected SQL, reversed wallet debit, changed transport timeout and changed support-link output. They modify only private extracted fixture code and recheck original file integrity afterward.

## CI and evidence ownership

The V0 workflow checks the exact PR HEAD, uses read-only repository permissions and pinned action commits, runs the same gate, then provisions prerequisites only on its ephemeral GitHub runner and creates the private DB. It publishes test summaries, not a deployable release. It has no deployment or production secrets step. Do not mark an issue accepted until the actual candidate's required checks and review are complete.

## Remaining tests before modifying high-risk paths

See the behavior matrix for exact gaps: positive gateway verification and replay; valid subscription/client import; full provisioning/renewal failure recovery; concurrent payment/Cron/port allocation; every role and persisted wizard; comprehensive old-keyboard handling; real HTTP routing/headers; QR assets; timezone/collation and live-schema correspondence. No performance improvement, zero-regression guarantee or production acceptance is claimed by V0.
