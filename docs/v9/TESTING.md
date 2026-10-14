# V9 acceptance and evidence

All tests use **owned disposable MariaDB Unix sockets**, synthetic
state, and owned loopback HTTP fixtures. Never run these checks in
an installed bot directory or with actual Telegram, payment, VPN, Cron,
wallet or production credentials.

## Commands

```bash
# Development feedback ONLY; not a full release gate
python3 tools/check_v9.py --focused --report /tmp/achbot-v9-focused.json

# Full PHP 8.4 Phase 1 acceptance (fresh temporary original DBs)
python3 tools/check_v9.py --report /tmp/achbot-v9-complete.json --evidence-dir /tmp/achbot-v9-evidence

# Full real PHP 8.1 compatibility; use actual PHP 8.1, not 8.4
python3 tools/check_v9.py --php81 --report /tmp/achbot-v9-php81.json

# Source-only ZIP from CLEAN, COMMITTED exact Git HEAD
python3 tools/package_v9.py --output /tmp/achbot-v9-runtime.zip
python3 tools/verify_v9_package.py --zip /tmp/achbot-v9-runtime.zip --manifest /tmp/achbot-v9-runtime.zip.manifest.json
```

`v9_contract.py` pins merge SHA + TREE, all 92 V8 source members,
public entrypoint identities, 27 finance modules, original SQL DDL,
untouched synthetic baseInfo example, and that actual private
`baseInfo.php` is neither tracked nor in the worktree. It rejects
unreviewed runtime changes. This is a deliberate **unchanged-runtime
global acceptance** increment.

`test_v9_bootstrap.py` stages original/current **complete** 92-file
runtime source with 2 fake config files and 2 original-schema DBs. It
executes the real `tests/v6/bootstrap.php` shutdown fixture, full
`config.php`, 40 facade reflections, original local-panel HTTP clients,
and checks expected early negative-user exit without real Telegram or
payment calls. The test compares returned signatures/warnings/outputs,
seven loopback provider requests on each side and all row values in
**all 23 tables**. It repeats in an external CWD and verifies that
synthetic private files and DDL remain byte-identical.

`test_v9_swap.py` uses a freshly owned filesystem and one disposable
23-table DB to physically verify V8 → V9 → V8 → V9 overlays. All four
phases contain precisely **92** runtime files. It pins public entrypoint
PHP syntax, actual on-disk bytes, private sentinels and full-row/DDL
equality. There is **no DB restore or real payment operation**. The
existing `test_v8_swap.py` within the mandatory full historical gate
separately tests a real **synthetic paid V7 order**, linked discount
and renewal rows on one DB with V7 → V8 → V7 → V8. Keep these two
types of evidence separate.

`check_v9.py` in full mode first runs the above and then **executes
the entire unmodified V8 gate** `tools/check_v8.py`, including 73
finance scenarios / 110 steps, 16 QR include cases, 8/8 finance mutants,
2 wallet races, 54 internal finance HTTP guards, real MariaDB financial
source rollback, 18 V7 purchase scenarios / 47 steps, and immutable
V7 → V0–V6.1 source history. The aggregate evidence records
`complete=true` only when all required tests have run and passed.
The workflow runs the full check under PHP 8.1 and PHP 8.4.

## Evidence honesty

Synthetic old/new behavior equivalence is **not** production payment
security assurance, real provider acceptance, or deployed operator
verification. Missing live scenarios remain **NOT TESTED**, not PASS.

The final CI artifact and deterministic clean-HEAD runtime ZIP/manifest
are SHA-bound to the exact reviewed candidate. Independent
author-separated HIGH_ASSURANCE review is required on that exact
candidate, followed by separately explicit owner merge/release/deploy
decisions. A new commit invalidates prior exact-head approval.
