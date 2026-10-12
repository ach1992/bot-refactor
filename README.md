# AChBot — PHP Telegram VPN bot (refactoring project)

This repository is a **public, sanitized reference baseline** for a staged, behavior-preserving refactor of an existing PHP Telegram bot. **This repository is not a production-certified release.**

> **Safety:** no real tokens, passwords, user records, payment credentials, live backups or runtime caches belong in this repository. Use isolated test infrastructure. Existing credentials in the source database dump should be rotated if still valid.

## Current shipped test baseline and pending maintenance

- V5 broadcast/Cron source was independently reviewed, merged and published as
  [`v0.5.0-rc.1`](https://github.com/ach1992/bot-refactor/releases/tag/v0.5.0-rc.1).
- V6 VPN/account/subscription source was independently reviewed, merged and published
  as [`v0.6.0-rc.1`](https://github.com/ach1992/bot-refactor/releases/tag/v0.6.0-rc.1).
- **Neither pre-release is a production-certified build.** Real operator acceptances
  remain open in [V5 Issue #14](https://github.com/ach1992/bot-refactor/issues/14)
  and [V6 Issue #16](https://github.com/ach1992/bot-refactor/issues/16).

**Active scoped maintenance (NOT yet merged, released or deployed):**
[V6.1 automatic-backup reliability Issue #18](https://github.com/ach1992/bot-refactor/issues/18).
Owner's read-only aaPanel diagnostics showed unqualified `mysqldump` is missing
from Cron's explicit `PATH`, despite an executable in `/www/server/mysql/bin`.
The old backup function/scheduler were unchanged throughout V0–V6; the new
source-only compatibility patch resolves the runtime path and makes dump,
ZIP, Telegram status, privacy and retry failures observable/recoverable without
host package or Cron modifications. See [scope](docs/backup-hotfix/README.md),
[tests](docs/backup-hotfix/TESTING.md) and
[operator precautions](docs/backup-hotfix/OPERATIONS.md).

The planned later V7 product-plan stage remains separate from this maintenance.

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
