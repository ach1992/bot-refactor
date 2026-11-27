# Current source validation (PHP 8.5)

The required GitHub Actions status is `php85-validation` in
[.github/workflows/ci.yml](../../.github/workflows/ci.yml). It validates
the current source, not an installed bot. The executable check roster,
required case names and invocation details are owned by
[`tools/ci_quick.py`](../../tools/ci_quick.py); actual results and timings
belong to the exact Actions run. Do not maintain duplicate historical test
matrices or claim that a previous run proves a changed candidate.

## Required gate

- Check out the exact candidate commit. One PHP 8.5 runner and a
  SHA256-verified MariaDB fixture toolchain run source-real tests using
  disposable socket-only databases and owned loopback peers. No live
  merchant, Telegram, provider or customer database.
- Exercise current ledger, opening balances, receipt credit/funding,
  hosted/manual/SMS/gift evidence, immutable quotes, discounts/eligibility,
  shared Native/service operations, original identity, recovery and
  independent delivery. Include original once-only effects, uncertain
  response/commit, concurrency, historical workflow retirement and
  registered test-only extensions. Do not require retired executor drivers.
- Compare the consolidated empty schema to all tracked numbered
  construction SQL on disposable fixtures; verify current mandatory
  indexes/constraints, omitted tables/columns, strict/nonthrowing
  MySQLi modes and current settings defaults. Readiness tests bind the
  exact manifest/installed source and structural prerequisites, not
  private keys or actual remote availability.
- Cover current nonpaid provisioning, import/name occupancy/aliases,
  deletion, scoped access/plan changes, lifecycle, wallet adjustments/
  transfers, staff and actor permissions, reporting, Cron isolation,
  admin/customer route behavior, browser output and Telegram projection.
  Preserve exact approved Persian catalog/Telegram wire behavior with
  explicit current amendments and negative parity controls.
- Build and verify the clean exact-head runtime ZIP, manifest and
  SHA256SUMS with `tools/package_current.py`; publish CI report and
  runtime artifact for that commit. Successful tests without required
  packaging are not a successful full gate.
- Only the workflow's proven `README.md` and `docs/**/*.md` documentation-only
  diff can skip runtime checks. Unknown changes and other paths
  (including AGENTS.md) run the normal gate. No obsolete tests are
  revived merely to preserve historic compatibility.

## Local use

From a clean exact-source checkout with PHP 8.5, matching extensions,
MariaDB fixture binaries and Python 3:

```sh
python3 tools/ci_quick.py --report /tmp/achbot-ci-quick.json
```

For a targeted high-risk or failing scenario, run its actual current
fixture and inspect its real output. Example:

```sh
python3 tools/test_wallet_first_manual.py --run-disposable
```

Inspect the fixture's current CLI and prerequisites before using it.
Keep additional tests focused on changed risk; do not repeatedly run old
V0–V9/RC suites. A failed test is evidence, not automatically a source
defect or a flaky result. Diagnose the specific case; only rerun when
supported by actual evidence and retain the original outcome. Review and
validation bind an exact source identity; docs-only deltas do not change
reviewed functional blobs.

## Package and operational boundary

From the clean committed source at the intended release commit, choose a
fresh private directory outside the checkout:

```sh
python3 tools/package_current.py create --output /private/release/achbot-runtime.zip
python3 tools/package_current.py verify \
  --zip /private/release/achbot-runtime.zip \
  --manifest /private/release/achbot-runtime.zip.manifest.json
(cd /private/release && sha256sum achbot-runtime.zip achbot-runtime.zip.manifest.json > SHA256SUMS)
```

The ZIP and manifest must match that commit/tree and every tracked runtime
member. Verify the archive and checksums before release; do not rename or
reuse another candidate's manifest as proof. Use [fresh installation](FRESH-INSTALL.md) and [installed-runtime
diagnostics](INSTALL-DIAGNOSTICS.md) for the selected release's matching
schema and source. Source test results never authorize a real financial,
Native, Telegram, Cron, migration or deployment effect. Maintain distinct
publication and installed acceptance in [#204](https://github.com/ach1992/bot-refactor/issues/204)
and [#183](https://github.com/ach1992/bot-refactor/issues/183).

Current subscription-action and agency tests cover both SQL reporting modes,
literal scoped search, actual private update identity and stale editor
ownership, verified/uncertain Native rotation with read-only recovery,
original credential delivery guards, original event recovery, concurrent
agency decisions, atomic rollback, and preserving independent discount
scopes. Onboarding covers the original notice's recipient-specific recovery
and no repeated completed event. These are disposable-source proofs, not
an installed Telegram or provider acceptance.

Current storage validation includes two independent databases on the same
MariaDB instance in MySQLi strict/off modes, and a synthetic, independently
restored pre-hygiene source projected into the consolidated current schema.
See [current storage and isolation](CURRENT-STORAGE-HYGIENE.md).
