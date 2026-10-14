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

**V9 Phase 1 completed/source-only published:** [`v0.9.0-rc.1`](https://github.com/ach1992/bot-refactor/releases/tag/v0.9.0-rc.1) is the exact integrated reviewed source-only V9 release (92 unchanged V8 runtime files) with full original-schema SQL/HTTP/private-fixture and historical tests. [Issue #27](https://github.com/ach1992/bot-refactor/issues/27) tracks real installed/operator acceptance, still **NOT TESTED**. [PR #30](https://github.com/ach1992/bot-refactor/pull/30) adds additional post-release *historical V9 ZIP* synthetic PHP HTTP acceptance; this PR remains separately gated.

**NEXT TARGET (not yet merged/released): Marzban-only** — Owner-approved [Issue #31](https://github.com/ach1992/bot-refactor/issues/31) removes **unused** legacy X-UI/Sanaei/Alireza/normal panel backends, admin setup and obsolete branches. The Owner states these panels were **never operated**, so no old-panel account migration or backup is necessary. All desired Marzban and bot capabilities and the original 23-table DB are to remain. A small provider boundary permits independently defined future PasarGuard/new X-UI adapters, but none exist today. **This intentionally changes legacy panel behavior versus V9**: retain V9 frozen evidence and test the new product against an independent post-V9 acceptance suite; do not claim old all-panel byte-parity for new source. The four-step [roadmap #29](https://github.com/ach1992/bot-refactor/issues/29) and financial [Issue #26](https://github.com/ach1992/bot-refactor/issues/26) are authoritative for scope, policy and gates.

**Incident root causes:** old Cron could not find aaPanel's `mysqldump` through its overridden `PATH`, and initial V6.1 RC1 incorrectly rejected MariaDB's legitimate space-padded single-digit dump-completion hour. RC2 fixes both in the source and retains SQL validation, Telegram success-gating and private temporary-file cleanup. Details and regression commands: [backup README](docs/backup-hotfix/README.md), [test matrix](docs/backup-hotfix/TESTING.md), [operations precautions](docs/backup-hotfix/OPERATIONS.md).

**Official name: AChBot.** Lowercase `achbot` is a technical slug only;
legacy `wizwiz` callback strings and Cron filenames remain functional.

## Project contract

- **Current supported VPN backend:** **Marzban only** (target version under [#31](https://github.com/ach1992/bot-refactor/issues/31)); obsolete X-UI/Sanaei/Alireza code intentionally retired. Future PasarGuard and new-generation X-UI are separate unimplemented extensions. No real old-panel backup or migration needed (Owner confirmed never used).
- **Historical Phase 1 V0–V9:** immutable parity/evidence against all original functionality, original MariaDB schema, SQL and Telegram callback contracts. This no longer governs supported legacy panels in *future* code. Keep frozen historical tests on pinned checkouts and new Marzban-only acceptance on current code.
- **Database:** retain the original **23-table** MariaDB schema for this candidate; future, independently approved audit/migration may drop genuinely unused tables/columns. Never equate X-UI backup exemption with Marzban/financial DB backup exemption.
- **Finance:** privileged negative wallet debt is valid; customer-initiated overdrafts not permitted; TRON/NextPay/global gateway safety remediation is separate [#26](https://github.com/ach1992/bot-refactor/issues/26). None of the current source-only compatibility test releases certifies live financial safety.
- **Gates:** full synthetic PHP8.1/8.4 with original-schema fixtures; independent review; separate Owner authorization for merge, new release, installed staging and production.

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
