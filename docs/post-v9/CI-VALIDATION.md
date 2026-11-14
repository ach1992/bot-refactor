# Current PHP 8.5 validation and CI policy

This document applies to **new development after RC21** in `ach1992/bot-refactor`. Published releases and immutable historical commits retain their original evidence. It does not authorize installation, database changes, payments or production Cron operations.

## Documentation boundary

The lightweight `docs` scope uses a **positive exact-path allowlist**:
only modified-in-place, regular `README.md` and `docs/post-v9/README.md`.
Financial, security, CI/policy, installation, migration and release-note
contracts (including `docs/post-v9/NEXT-RELEASE.md`) remain `full`, even
when only their prose changes. New or deleted documents, symlinks, mode
changes and ambiguous renames/add-delete pairs also require `full`.
This preserves the independent-review correction previously accepted
under Issue #104 while allowing explicit affected-family test selection.

## Decision and selection

`tools/ci_plan.py` uses immutable Git base/head objects and a clean exact
checkout. PRs execute the classifier from the **trusted base revision**,
never from the unreviewed candidate. Unknown, changed policy/CI/validation
infrastructure and unprovable diffs retain the full fallback.

| Scope | Trigger | Validation | Evidence |
| --- | --- | --- | --- |
| `docs` | Existing allowlisted README files, modified in place | Git SHA/tree, ancestry, file mode and changed-path verification | Informational-only, no PHP/native claims |
| `none` | Exact same tree/no effective changes | Git identity and ancestry | No source impact |
| `source` | Reserved for previously supported bounded source selection | Source checks + PHP lint | Incomplete targeted only |
| `cron` | Dedicated Cron test or byte-bounded Cron lock preparation | Source checks and actual isolated Cron regression | Incomplete targeted only |
| `families` | Existing, explicitly mapped leaf runtime/test files | Shared PHP 8.5 source foundation plus only impacted native family shards | Incomplete, lists selected **and omitted** families |
| `full` | Shared/high-risk/unknown source, CI/policy/financial/release/installation docs, migrations and ambiguous changes | Source foundation and all eight PHP 8.5 families | Complete only after strict eight-family aggregation |

The `families` scope has a small explicit reviewed mapping from existing file
paths to the eight owned `post_v9_suites.RUNNERS` families. Multiple changed
paths take the **union** of affected families. It rejects added/deleted code,
non-regular files, unrecognized tests and cross-domain Cron changes, and uses
full validation if the union includes all eight families. For example:

- `tools/test_post_v9_onboarding.py` selects only `admin` (1).
- `src/Telegram/UserProfile.php` selects `admin/services/presentation` (1/3/5).
- `src/Finance/ApiPayments.php` selects `api/wallet/effects/funding` (2/4/7/8).
- `src/Finance/ReceiptHelpers.php`, `config.php`, `bot.php`, and
  `tools/post_v9_suites.py` remain **full**, because of broad dependencies or
  direct influence over test/acceptance policy.

A tested, newly justified mapping can be added in a separate CI change; do
not infer code safety from file extension alone. This is an explicit bounded
policy, not dynamic dependency discovery or an exemption from review.

`php85-validation` remains the protected required status for every scope.
For `families`, it consumes the trusted path-selection receipt plus the
source foundation and **exactly** the selected shard artifacts. The targeted
aggregator rejects missing, duplicate, extra, tampered or mismatched
SHA/tree/PHP/validator inputs and must always report
`complete: false`, `targeted_complete: true`, and omitted families.
Only a full eight-family aggregation may report full current PHP 8.5
acceptance. Manual `workflow_dispatch` always selects full. Targeted checks
do not authorize production rollout or real financial/provider operations.

`python3 tools/check_post_v9.py --report <path>` remains the full local
entrypoint; `--suite source` and `--suite cron` remain explicitly targeted.
Historical V0–V9 execution is not relabelled as new PHP 8.5 proof.

## Full-current sharding

Full acceptance runs eight isolated native families under **PHP 8.5 only**.
For explicitly mapped changes, the workflow starts only the selected subset
using the same runners, MariaDB bootstrap and real PHP 8.5 test contracts: `admin`, `api`, `services`, `wallet`, `presentation`, `authorization`, `effects`, `funding`. Each family has disjoint declared report-field ownership. Shared source/payment-identity/privacy checks run once in `foundation`. Each family can use its own disposable MariaDB and loopback fixtures.

The eight hosted native-family runners use the official MariaDB 10.11.19 Linux x86-64 binary tarball, fixed upstream archive URL and verified SHA-256, placed under each job's temporary directory. The `mariadbd`, `mariadb` and `mariadb-install-db` tools run without Ubuntu APT mirrors; every source-real test continues to create and own its separate socket-only database. No shared SQL service, fixture assertions, PHP version checks, source hashes, shard count or protected aggregation is replaced. An unavailable archive, checksum mismatch or missing native executable fails the family before acceptance. This eliminates the observed GitHub-hosted Ubuntu APT/DNS dependency rather than bypassing native tests.

`tools/post_v9_shards.py foundation --report <file>`, `run --index 1..8 --count 8 --report <file>`, and `aggregate --source <file> --reports <directory> --report <file>` are the individual stages. Each shard and the foundation are explicitly incomplete. Aggregation rejects missing, duplicate, unknown, version-mismatched or inconsistent reports, and checks exact current Git HEAD/tree, PHP 8.5, runtime and validator hashes, catalog ownership, execution evidence and required migration proof. Only an accepted complete aggregation may set `complete: true` for **current PHP 8.5 acceptance**. Full reports include per-family timings; rebalance only after measured CI evidence.

The protected aggregate job is `php85-validation`. It verifies upstream job
outcomes before assembling the current-head source ZIP and checksum-verified
SQL sidecars, without executing migrations. Incomplete targeted, full and
same-tree reuse evidence have distinct files and cannot be silently relabelled
as a complete eight-family result. A successful old PHP 8.1/8.4 job does not satisfy a PHP 8.5 requirement.

## Main-push reuse

After integration into `main`, `tools/ci_reuse.py` may avoid rerunning the identical accepted source pipeline **only if** read-only GitHub evidence unambiguously establishes the same full source tree, matching merged PR/main SHA, original successful exact-source PR workflow/run/attempt, and a successful single `php85-validation` job. Any ambiguity, API error, workflow change or partial result means ordinary current validation runs.

A reused result is an **attestation of the original PR pipeline** (full or
explicitly targeted as originally run), not a newly executed native/full run. Its receipt contains the original source/run identity and the new `main` identity; current-`main` source ZIP is rebuilt and verified independently when runtime-relevant validation or reused acceptance actually requires a package. For trusted `docs`/`none` changes no runtime ZIP is rebuilt; explicit `workflow_dispatch` continues to require full PHP 8.5 acceptance and exact-head packaging.

The read-only REST client pins `X-GitHub-Api-Version: 2022-11-28` deliberately. Verified against merged PR #105/main: the newer `2026-03-10` representation returns `merge_commit_sha: null` for an already merged PR, disabling attestation and redundantly triggering the full PHP 8.5 suite. The pinned version supplies the verified exact merge SHA. Never infer a missing merge identity or relax any other evidence checks; verify live API behavior before changing the pin.

## Historical constraints and interpretation

The frozen V9 full historical driver requires PHP 8.4. It cannot provide an honest new PHP 8.5 full-history result without changing frozen artifacts. The Owner explicitly retired new PHP 8.1/8.4 executions: the older successful V0–V9 runs remain archived as **historical evidence only** and existing byte/schema pins still protect history. Never alter old fixtures or invoke `--focused` to imply historical full acceptance.

Previously observed full CI critical path: approximately **17m27s (PHP 8.1)** and **22m01s (PHP 8.4)** on main run [41110763256](https://github.com/ach1992/bot-refactor/actions/runs/41110763256). The Cron candidate [PR #103](https://github.com/ach1992/bot-refactor/pull/103) completed its earlier required checks [run 41450937783](https://github.com/ach1992/bot-refactor/actions/runs/41450937783). Neither is a PHP 8.5 timing baseline. New critical path and family timings must come from actual PHP 8.5 Actions runs; do not claim an optimization percentage before comparable evidence exists.

## Development and integration

Keep the Cron first-run behavior in the separate stabilized PR #103 until author-independent review and Owner integration authorization. The CI implementation is stacked on that source for testing, but its own changes require a new exact-candidate review and required CI. Do not rewrite or rerun an unchanged RED baseline, duplicate Dev-full with CI-full, or repeat a complete suite after an unrelated local change. Narrow feedback during edits and run required broad checks when the candidate is stable.

Repository branch protection settings were not readable with the current GitHub integration. Before replacing any required contexts, a repository administrator must verify whether legacy job names `phase1-php81-compatibility` / `phase1-php84-full-history` are required and, if so, replace only those with `php85-validation`. Do not change unrelated protections, bypass checks or auto-merge an unreviewed candidate.

Live target installation, scheduler changes, PHP/FPM compatibility, external provider behavior, bank settlement and manual operator acceptance remain **unverified by CI**.

## Actual PHP 8.5 compatibility findings (issue #104)

The first full PHP 8.5 matrix runs [41462988501](https://github.com/ach1992/bot-refactor/actions/runs/41462988501) and [41463994951](https://github.com/ach1992/bot-refactor/actions/runs/41463994951) exposed **real E_DEPRECATED failures**, not a CI routing defect: PHP 8.5 deprecates curl_close() (an inert no-op since PHP 8.0) and case 'k'; in the JDF switch. Only the current exercised HTTP/Marzban paths have the redundant no-op calls removed and the current JDF case syntax corrected. Frozen Git history remains byte-identical. The current JDF is byte-equal to the original baseline after exactly one PHP 8.5 case-delimiter substitution, and this exception is enforced by the native unified-services fixture. The archived date comparison, which executes the older JDF only as fixture, omits only E_DEPRECATED; all other errors and the current live-source JDF path remain strict.

Do **not** claim that every PHP runtime code path is proven PHP 8.5 compatible: other curl_close() calls remain, including the exact byte-guarded canonical checkout flow. Any changes to frozen byte-parity paths require a separate explicit contract/review decision, not test suppression or silent rewrite. Full current acceptance remains blocked until the actual matrix and aggregator succeed.

The historical V9 Marzban differential keeps checking the exact original Git SHA and all native SQL/HTTP/result comparisons; on PHP 8.5, its comparison normalizes **only** the exact historical no-op curl_close() E_DEPRECATED message, records the number of those archival messages, and rejects every other historical/current warning. The old gift/getter race RED fixture similarly reads the exact archived source and removes **exactly 11** inert close calls in its temporary executable copy only. Neither operation rewrites historical Git objects, changes the candidate source, exempts a current warning, nor claims a new PHP 8.4 test.


### Verified isolated MariaDB runtime bootstrap
All eight required PHP 8.5 native-family shards use a SHA-256-pinned official
MariaDB 10.11.19 binary archive, replacing intermittent GitHub APT mirror/DNS
failures. Each family still initializes its own socket-only database and
strict acceptance/aggregate contracts remain unchanged. A mismatched archive
necessarily fails validation.
