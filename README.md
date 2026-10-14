# AChBot — PHP Telegram VPN bot (refactoring project)

This repository is a **public, sanitized reference baseline** for a staged, behavior-preserving refactor of an existing PHP Telegram bot. **This repository is not a production-certified release.**

> **Safety:** no real tokens, passwords, user records, payment credentials, live backups or runtime caches belong in this repository. Use isolated test infrastructure. Existing credentials in the source database dump should be rotated if still valid.

## Current test releases and operator evidence

- [V5 `v0.5.0-rc.1`](https://github.com/ach1992/bot-refactor/releases/tag/v0.5.0-rc.1): merged/released; real V5 operator acceptance remains unverified in [Issue #14](https://github.com/ach1992/bot-refactor/issues/14).
- [V6 `v0.6.0-rc.1`](https://github.com/ach1992/bot-refactor/releases/tag/v0.6.0-rc.1): merged/released; full V6 operator acceptance remains unverified in [Issue #16](https://github.com/ach1992/bot-refactor/issues/16).
- [V6.1 backup RC2 `v0.6.1-rc.2`](https://github.com/ach1992/bot-refactor/releases/tag/v0.6.1-rc.2): independently reviewed, merged and source-only released after the aaPanel MariaDB completion-hour regression in RC1. **Owner reports installing RC2 on the affected bot and receiving the first real backup ZIP in the intended Telegram report channel.** The submitted ZIP was previously reported as passing archive/structural checks; this is owner/chat evidence, not an automated CI claim. **No actual restore into an isolated database was performed; owner explicitly chose to skip further restoration experiments.** Backup transmission is observed, but recoverability remains unproved. See [Issue #18](https://github.com/ach1992/bot-refactor/issues/18) and [backup notes](docs/backup-hotfix/README.md).
- None of these source releases implies full production certification for the bot, wallet, providers or backups. GitHub CI exercises disposable synthetic services; user-installed source and real-channel receipt are separate evidence. An unrelated older working bot must remain untouched.

**V7 delivered as a source-only release:** [`v0.7.0-rc.1`](https://github.com/ach1992/bot-refactor/releases/tag/v0.7.0-rc.1) completed the eleven-module plans/purchase/provisioning extraction, independent review, merge and PHP 8.1/8.4 CI. See [Issue #21](https://github.com/ach1992/bot-refactor/issues/21) and [V7 evidence](docs/v7/TESTING.md). **Real installed/operator V7 acceptance remains NOT TESTED.** Earlier stages' pending operator checks and the unperformed V6.1 full restore are not changed by V7 publication.

**V8 delivered as a source-only test release:** [v0.8.0-rc.1](https://github.com/ach1992/bot-refactor/releases/tag/v0.8.0-rc.1) packages the original financial lifecycle behind 27 protected modules with unchanged business policy, legacy entrypoints and original 23-table database schema. The exact-HEAD [PR #25](https://github.com/ach1992/bot-refactor/pull/25) passed independent HIGH_ASSURANCE review; [PHP 8.1 and PHP 8.4 post-merge CI](https://github.com/ach1992/bot-refactor/actions/runs/38544165632) passed. The source-only ZIP contains 92 runtime files, not private configuration or data. **Real financial gateway, provider, Cron and installed-bot acceptance remains NOT TESTED**; inherited payment correctness/security risks stay in [Issue #26](https://github.com/ach1992/bot-refactor/issues/26). See the [V8 acceptance record](https://github.com/ach1992/bot-refactor/issues/24) and [V8 tests](docs/v8/TESTING.md).

**V9 Phase 1 final integration in isolated development:** [Issue #27](https://github.com/ach1992/bot-refactor/issues/27) controls a whole-application compatibility and reversible source-acceptance pass over the merged V8 source. V9 tests the full PHP configuration bootstrap, old global facades, all 23 synthetic MariaDB tables, cross-working-directory private-configuration resolution, public entrypoints and physical V8/V9 source swaps. **The approved V8 92 runtime files are intentionally byte-identical in this candidate**; V9 does not invent a behavioral, database or private-config migration. Review [V9 design](docs/v9/README.md), [tests](docs/v9/TESTING.md) and [operations](docs/v9/OPERATIONS.md). V9 merge, release and any installation are independently owner-gated.

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
