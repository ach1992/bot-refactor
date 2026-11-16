# Current AChBot CI contract (PHP 8.5)

The accepted Stable line checks **current bot functionality**, not every archived
source revision. This is source acceptance only: live payment finality, provider
behavior, Telegram delivery and production schema/install acceptance remain
separate operator decisions.

## Required fast check

[.github/workflows/ci.yml](../../.github/workflows/ci.yml) runs on PRs and
main pushes under the single `php85-validation` required status:

- Exactly one PHP 8.5 runner and one SHA256-verified cached MariaDB fixture
  toolchain. No Docker-in-Docker, APT mirror installation or eight duplicate
  MariaDB downloads. Independent cases use isolated, disposable socket-only
  databases and local loopback providers.
- `tools/ci_quick.py` runs the focused current `test_stable_nowpayments.py`
  for signed IPN, exact invoice/quote identity, nonfinal vs finished provider
  outcome and replay without double wallet/Native effects; and the
  `--ci-redrive` mode of `test_owner_r6_api_flow.py` for private,
  permission-checked preview/confirmation and original-packet single claim.
  Neither exercises pre-Stable source or makes live requests.
- `tools/ci_quick.py` also runs real-source PHP, finance, payment/checkout,
  customer/service operations, subscription/payment flows, onboarding, wallet, Native,
  operator permissions, Telegram error diagnostics, Cron and
  payment-browser checks. The current roster additionally exercises
  independently proven post-cutover PasarGuard order-attachment recovery and
  malformed bank/gift HTTP diagnostics without leaking external response text.
  Runs are parallel but retain each test's own assertions and failure details.
  The JSON receipt records exact HEAD/tree,
  PHP runtime, actual checks and elapsed times.
- Wallet finality regression suites cover live-source `RENEW_SCONFIG`
  commit-before-Native ordering (including unknown COMMIT/ACK, crash, races,
  native panel and plan drift), and **GET-only** original PasarGuard wallet
  DAY/VOLUME reconciliation, including migration-backed NULL order origin and
  local accounting failure. See [Wallet finality and recovery](WALLET-FINALITY-RECOVERY.md).
- Every relevant build produces a reproducible current-source-only runtime
  ZIP, manifest and checksums at the **current exact Git commit**, separate
  from any real database migration or deployment.
- Pure `README.md` and `docs/**/*.md` changes (proven from two existing
  Git commit objects, not a filename guess) skip PHP/native tests while
  retaining the required status. Unknown/ambiguous changes run the checks.
  Superseded PR runs are cancelled.

The required status is **representative current Stable functional coverage**, not an
exhaustive claim about every fixture and negative test. It fails if any
required suite, source/Cron check or package verification fails; it never
calls the real merchant, Telegram API or production database.

## Stable-only coverage boundary

Only **Stable v0.10.0 and later** is supported. Historical V0-V9 execution,
archived RED fixtures, old PHP 8.1/8.4 matrices and byte-for-byte comparisons
against pre-Stable implementations do **not** run in CI, even manually. Their
published results remain recoverable from Git history; they are not current
compatibility requirements. Avoid reintroducing them in new acceptance tests.

Existing customers' live contracts (original issued subscriptions, unpaid
payments, wallets, permission grants and previously imported provider accounts)
remain a current Stable obligation: test these workflows against the **current
Stable source/schema** without running obsolete bot implementations.

Use targeted **current-only** functional integration checks during development
for high-risk changes. Expand the representative CI roster only where evidence
shows an active path is otherwise untested. No separate historical eight-family
workflows or mandatory retroactive migration suite.

## Baseline and measurement

The last exact full PHP 8.5 [Actions run #43747843516](https://github.com/ach1992/bot-refactor/actions/runs/43747843516)
took about **4m47s wall time** (first job to aggregator finish) with eight
native-family jobs, one foundation, a planning job and an aggregator.
Individual family job durations ranged approximately 88–262 seconds; the
longest were `services` and `presentation`. A large portion is source-real
fixture execution, not PHP startup. The old per-run runner-minute cost
includes *all* jobs, not just critical-path time.

Do not claim the new 2–3 minute target is achieved until the same-class
`php85-validation` Actions job has passed and its **actual start/end and
per-check durations** are measured. Review its result before adjusting the
representative test roster, concurrency or MariaDB setup. CI optimizations
must not silently turn an incomplete smoke check into a full acceptance claim.

## Local use

From a clean, exact source checkout with PHP 8.5 and MariaDB fixture
executables on PATH:

```bash
python3 tools/ci_quick.py --report /tmp/achbot-ci-quick.json
```

For deeper testing of a high-risk change, run additional **current Stable**
source-real integration tests individually in an isolated development
environment. Do not run archived pre-Stable test drivers. Never execute
test fixtures against live bot configuration, customer/payment databases
or external Native provider endpoints.
