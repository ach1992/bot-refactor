# Current PHP 8.5 validation and CI policy

This document applies to **new development after RC21** in `ach1992/bot-refactor`. Published releases and immutable historical commits retain their original evidence. It does not authorize installation, database changes, payments or production Cron operations.

## Decision and selection

`tools/ci_plan.py` chooses exactly one current-source scope from the Git base/head of a clean checkout:

| Scope | Accepted changes | Executed checks | Claim |
| --- | --- | --- | --- |
| `source` | Ordinary root README and post-V9 documentation only | Runtime inventory, pinned source/schema invariants and PHP lint | Targeted only; `complete: false` |
| `cron` | Only the precisely delimited lock-directory preparation region in `settings/cronRunner.php`, the dedicated Cron regression file and ordinary documentation | Source checks plus actual isolated Cron coordinator regression | Targeted only; `complete: false` |
| `full` | Shared/runtime worker changes, CI/tooling, tests outside the dedicated Cron suite, migrations, policy, unknown or unprovable diffs | Source/payment/privacy foundation plus all eight native families | Full current acceptance only after strict aggregation |

The classifier verifies exact Git SHAs, ancestry, a clean checkout, the original and changed bytes outside the Cron preparation region, and the event/checkout identity. Missing evidence fails closed to `full`; an untrusted or mismatched checkout fails the plan. Manual `workflow_dispatch` always requests `full`. There is no file-name-only exemption for Cron workers.

The checker keeps its default complete serial entrypoint (`python3 tools/check_post_v9.py --report <path>`). Targeted modes are `--suite source` and `--suite cron`. Historical V0–V9 execution is not claimed by either mode.

## Full-current sharding

Eight isolated native families run under **PHP 8.5 only** on distinct disposable GitHub Actions runners: `admin`, `api`, `services`, `wallet`, `presentation`, `authorization`, `effects`, `funding`. Each family has disjoint declared report-field ownership. Shared source/payment-identity/privacy checks run once in `foundation`. Each family can use its own disposable MariaDB and loopback fixtures.

`tools/post_v9_shards.py foundation --report <file>`, `run --index 1..8 --count 8 --report <file>`, and `aggregate --source <file> --reports <directory> --report <file>` are the individual stages. Each shard and the foundation are explicitly incomplete. Aggregation rejects missing, duplicate, unknown, version-mismatched or inconsistent reports, and checks exact current Git HEAD/tree, PHP 8.5, runtime and validator hashes, catalog ownership, execution evidence and required migration proof. Only an accepted complete aggregation may set `complete: true` for **current PHP 8.5 acceptance**. Full reports include per-family timings; rebalance only after measured CI evidence.

The protected aggregate job is `php85-validation`. It verifies upstream job outcomes before assembling current-head source ZIP and checksum-verified SQL sidecars, without executing migrations. Incomplete targeted reports and same-tree reuse receipts have separate names and must never be relabelled as full/native results. A successful old PHP 8.1/8.4 job does not satisfy a PHP 8.5 requirement.

## Main-push reuse

After integration into `main`, `tools/ci_reuse.py` may avoid rerunning the identical accepted source pipeline **only if** read-only GitHub evidence unambiguously establishes the same full source tree, matching merged PR/main SHA, original successful exact-source PR workflow/run/attempt, and a successful single `php85-validation` job. Any ambiguity, API error, workflow change or partial result means ordinary current validation runs.

A reused result is an **attestation of the original PR pipeline**, not a newly executed native/full run. Its receipt contains the original source/run identity and the new `main` identity; current-`main` source ZIP is rebuilt and verified independently.

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
