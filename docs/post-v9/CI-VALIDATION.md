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
- `tools/ci_quick.py` runs real-source PHP, finance, payment/checkout,
  customer/service lifecycle, subscriptions, onboarding, wallet, Native,
  operator permissions, Telegram delivery/diagnostics, Cron, catalog and
  payment-browser checks. Runs are parallel but retain each test's own
  assertions and failure details. The JSON receipt records exact HEAD/tree,
  PHP runtime, actual checks and elapsed times.
- Every relevant build produces a reproducible 132-file source-only runtime
  ZIP, manifest and checksums at the **current exact Git commit**, separate
  from any real database migration or deployment.
- Pure `README.md` and `docs/**/*.md` changes (proven from two existing
  Git commit objects, not a filename guess) skip PHP/native tests while
  retaining the required status. Unknown/ambiguous changes run the checks.
  Superseded PR runs are cancelled.

The quick status is **representative current functional coverage**, not an
exhaustive claim about every fixture and negative test. It fails if any
required suite, source/Cron check or package verification fails; it never
calls the real merchant, Telegram API or production database.

## Optional extended check

[.github/workflows/v0.yml](../../.github/workflows/v0.yml) is now **manual
`workflow_dispatch` only**. It retains the existing eight-family native
matrix, strict source/fixture proof aggregation, exhaustive edge cases and
historical migration/reference tests. It does not run on every PR or main
push. Use it when a meaningful change affects cross-cutting financial
finality, migrations, original provider integration, concurrency or
other high-risk behavior beyond the fast check's representative paths.

Retaining these offline test fixtures does **not** mean every archived
assertion should consume CI minutes on every ordinary source edit. Individual
outdated fixtures can be removed only after verifying that no active path
or necessary regression is lost. Prior V0-V9 PHP 8.1/8.4 test outcomes are
historical and do not become PHP 8.5 evidence.

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

For deeper optional testing, use the manual extended GitHub workflow (or
`python3 tools/check_post_v9.py --suite full` in an appropriately provisioned
isolated development environment). Never run the test fixtures against live
bot configuration, payment databases or Native provider endpoints.
