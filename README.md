# AchBot — PHP Telegram VPN bot (refactoring project)

This repository is a **public, credential-free baseline** for a staged, behavior-preserving refactor of an existing PHP Telegram bot. **Not a new product and not yet a refactored release.**

> **Safety:** no real tokens, passwords, user records, payment credentials, live backups or runtime caches belong in this repository. Use isolated test infrastructure. Existing credentials in the source database dump should be rotated if still valid.

## Current state

- Initial imported PHP source is preserved byte-for-byte for tracked legacy source files; the local-only `baseInfo.php` configuration is intentionally excluded.
- `baseInfo.example.php` is a **template**, not a drop-in replacement for an active environment. When replacing deployed source later, keep the existing local `baseInfo.php` unchanged.
- `database/legacy-schema.sql` has the original DDL, indexes and AUTO_INCREMENT definitions, **without any actual row data**. It is a compatibility reference, **not a migration to run on a live database**.
- A number of legacy `wizwiz` identifiers are present as Telegram callback values or a cron-visible script path. They are **not renamed in this baseline** because existing Telegram messages and existing scheduler paths must still work. AchBot branding applies to new documentation and modules; legacy identifiers will be handled by compatibility adapters during the refactor.
- The repo is **not safe to deploy directly** without provisioned local config and careful staging validation.

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

No claim of integration equivalence, runtime acceptance, staging readiness or successful deployment is made at this point. Baseline tests and runtime inventory still need to be established.
