# AChBot — PHP Telegram VPN bot (refactoring project)

This repository is a **public, sanitized reference baseline** for a staged, behavior-preserving refactor of an existing PHP Telegram bot. **This repository is not a production-certified release.**

> **Safety:** no real tokens, passwords, user records, payment credentials, live backups or runtime caches belong in this repository. Use isolated test infrastructure. Existing credentials in the source database dump should be rotated if still valid.

## Current test releases and operator evidence

- [V5 `v0.5.0-rc.1`](https://github.com/ach1992/bot-refactor/releases/tag/v0.5.0-rc.1): merged/released; real V5 operator acceptance remains unverified in [Issue #14](https://github.com/ach1992/bot-refactor/issues/14).
- [V6 `v0.6.0-rc.1`](https://github.com/ach1992/bot-refactor/releases/tag/v0.6.0-rc.1): merged/released; full V6 operator acceptance remains unverified in [Issue #16](https://github.com/ach1992/bot-refactor/issues/16).
- [V6.1 backup RC2 `v0.6.1-rc.2`](https://github.com/ach1992/bot-refactor/releases/tag/v0.6.1-rc.2): independently reviewed, merged and source-only released after the aaPanel MariaDB completion-hour regression in RC1. **Owner reports installing RC2 on the affected bot and receiving the first real backup ZIP in the intended Telegram report channel.** The submitted ZIP was previously reported as passing archive/structural checks; this is owner/chat evidence, not an automated CI claim. **No actual restore into an isolated database was performed; owner explicitly chose to skip further restoration experiments.** Backup transmission is observed, but recoverability remains unproved. See [Issue #18](https://github.com/ach1992/bot-refactor/issues/18) and [backup notes](docs/backup-hotfix/README.md).
- None of these source releases implies full production certification for the bot, wallet, providers or backups. GitHub CI exercises disposable synthetic services; user-installed source and real-channel receipt are separate evidence. An unrelated older working bot must remain untouched.

**V7 delivered as a source-only release:** [`v0.7.0-rc.1`](https://github.com/ach1992/bot-refactor/releases/tag/v0.7.0-rc.1) completed the eleven-module plans/purchase/provisioning extraction, independent review, merge and PHP 8.1/8.4 CI. See [Issue #21](https://github.com/ach1992/bot-refactor/issues/21) and [V7 evidence](docs/v7/TESTING.md). **Real installed/operator V7 acceptance remains NOT TESTED.** Earlier stages' pending operator checks and the unperformed V6.1 full restore are not changed by V7 publication.

**V8 isolated source candidate (NOT merged, released or deployed):** [Issue #24](https://github.com/ach1992/bot-refactor/issues/24) controls the existing financial lifecycle refactor. The pinned V7 financial route, wallet, gateway, discount, refund, referral and renewal consumers are mechanically extracted behind original PHP entrypoints and global function facades without changing financial policy or the 23-table schema. Native old-versus-new financial tests, source swap, security guards and historical V0–V7 regressions are required before any separate owner approval to integrate. Start with [V8 architecture](docs/v8/README.md), [test contract](docs/v8/TESTING.md) and [safe operations](docs/v8/OPERATIONS.md). The older installed working bot remains untouched.

**Incident root causes:** old Cron could not find aaPanel's `mysqldump` through its overridden `PATH`, and initial V6.1 RC1 incorrectly rejected MariaDB's legitimate space-padded single-digit dump-completion hour. RC2 fixes both in the source and retains SQL validation, Telegram success-gating and private temporary-file cleanup. Details and regression commands: [backup README](docs/backup-hotfix/README.md), [test matrix](docs/backup-hotfix/TESTING.md), [operations precautions](docs/backup-hotfix/OPERATIONS.md).

**Official name: AChBot.** Lowercase `achbot` is a technical slug only;
legacy `wizwiz` callback strings and Cron filenames remain functional.

## Project contract

- **Phase 1:** refactor code only; retain existing MariaDB schema, data formats, behavior, SQL semantics, Telegram callback compatibility, bot/admin UX, integration URLs and cron entrypoints.
- Refactor in incremental independently runnable releases, with regression tests and independent review. Operator acceptance remains a separate gate; a specifically documented owner waiver may authorize **isolated development** of the next stage without treating unperformed tests as PASS.
- **Phase 2 (later):** database refactor and migrations, after Phase 1 has been accepted and only with separate explicit authorization.
- `baseInfo.php` is preserved and considered for modification only in the last Phase 1 increment if necessary.

## Navigating the project

- [Contract and acceptance](docs/PROJECT-SPEC.md)
- [Baseline and integration surfaces](docs/ARCHITECTURE-BASELINE.md)
- [Proposed stages](docs/REFACTOR-STAGES.md)
- [Public repository security guidance](docs/SECURITY.md)
- [Original database DDL (reference only)](database/legacy-schema.sql)

## Prerequisites

See source entrypoints in `bot.php`, `config.php`, `pay/`, and `settings/`. Native CI covers PHP 8.1 (observed aaPanel CLI/Cron) and PHP 8.4; the source remains compatible with the original 23-table MariaDB schema. Provision `baseInfo.php` **locally only**, configure webhooks, scheduled tasks and integrations as they were in the existing deployment, and keep deployment state out of Git.

## Earlier reference stages

The immutable [V0 analysis](docs/v0/README.md), [V1 presentation boundary](docs/v1/README.md) and [V2 Telegram boundary](docs/v2/README.md) remain available. Their historical test drivers run in pinned reference trees through the current gate, not directly against later changed runtime code. V4 source and operator limitations remain recorded in Issue #10 and its historical PR/CI; V5 development is separately controlled by Issue #14. Source integration never implies production rollout.
