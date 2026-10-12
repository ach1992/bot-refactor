# AChBot — PHP Telegram VPN bot (refactoring project)

This repository is a **public, sanitized reference baseline** for a staged, behavior-preserving refactor of an existing PHP Telegram bot. **This repository is not a production-certified release.**

> **Safety:** no real tokens, passwords, user records, payment credentials, live backups or runtime caches belong in this repository. Use isolated test infrastructure. Existing credentials in the source database dump should be rotated if still valid.

## Current development boundary: V5 complete broadcast and scheduled jobs

V0 through V4 are integrated and independently reviewed. The V4 source-only
operator candidate is [`v0.4.0-rc.1`](https://github.com/ach1992/bot-refactor/releases/tag/v0.4.0-rc.1).
The owner approved proceeding with V5 development after an initial positive
source trial, **not** V5 production deployment.

V5 isolates the **complete legacy broadcast administration lifecycle**, both
`bc_jobs` and `send_list` scheduled processors, and the notification/expiry
portion of `warnusers.php` into five guarded internal modules. The legacy
Cron/webhook paths, callbacks, MariaDB DDL and original automated renewal,
wallet, subscription and payment code remain unchanged. The full **43-file**
source-only runtime retains the operator's existing `baseInfo.php` outside
this public repository. No production certification is implied.

See [V5 scope](docs/v5/README.md), [testing](docs/v5/TESTING.md),
[cron inventory](docs/v5/CRON-INVENTORY.md),
[rollback](docs/v5/OPERATIONS.md) and
[preserved legacy behavior](docs/v5/LEGACY-BEHAVIOR.md).
[Issue #14](https://github.com/ach1992/bot-refactor/issues/14)
is the controlling acceptance and continuation contract.

Run `python3 tools/check_v5.py --report /tmp/achbot-v5-full.json`
for full frozen V0–V4 plus V5 checks on a **disposable** development runner;
`--focused` skips native/historical suites and is not acceptance. Neither CI
nor an author test substitutes for an exact-candidate independent review and
explicit operator staging/merge approval.

**Official name: AChBot.** Lowercase `achbot` is a technical slug only;
legacy `wizwiz` callback strings and Cron filenames remain functional.

## Project contract

- **Phase 1:** refactor code only; retain existing MariaDB schema, data formats, behavior, SQL semantics, Telegram callback compatibility, bot/admin UX, integration URLs and cron entrypoints.
- Refactor in incremental independently runnable releases, each with regression tests, an independent review and explicit operator acceptance before proceeding.
- **Phase 2 (later):** database refactor and migrations, after Phase 1 has been accepted and only with separate explicit authorization.
- `baseInfo.php` is preserved and considered for modification only in the last Phase 1 increment if necessary.

## Navigating the project

- [Contract and acceptance](docs/PROJECT-SPEC.md)
- [Baseline and integration surfaces](docs/ARCHITECTURE-BASELINE.md)
- [Proposed stages](docs/REFACTOR-STAGES.md)
- [Public repository security guidance](docs/SECURITY.md)
- [Original database DDL (reference only)](database/legacy-schema.sql)

## Prerequisites

See source entrypoints in `bot.php`, `config.php`, `pay/`, and `settings/`. The existing target used MariaDB 10.11 and PHP 8.4. Provision `baseInfo.php` **locally only**, configure webhooks, scheduled tasks and integrations as they were in the existing deployment, and keep deployment state out of Git.

## Earlier reference stages

The immutable [V0 analysis](docs/v0/README.md), [V1 presentation boundary](docs/v1/README.md) and [V2 Telegram boundary](docs/v2/README.md) remain available. Their historical test drivers run in pinned reference trees through the current gate, not directly against later changed runtime code. V4 source and operator limitations remain recorded in Issue #10 and its historical PR/CI; V5 development is separately controlled by Issue #14. Source integration never implies production rollout.
