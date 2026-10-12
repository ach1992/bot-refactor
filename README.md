# AchBot — PHP Telegram VPN bot (refactoring project)

This repository is a **public, sanitized reference baseline** for a staged, behavior-preserving refactor of an existing PHP Telegram bot. **Not a new product and not yet a refactored release.**

> **Safety:** no real tokens, passwords, user records, payment credentials, live backups or runtime caches belong in this repository. Use isolated test infrastructure. Existing credentials in the source database dump should be rotated if still valid.

## Current development boundary: V3 support/ticket lifecycle

V2 source was independently approved and merged. V3 separates the complete existing support lifecycle into six scoped modules while preserving dispatch positions, caller state, SQL, callback values and notification order. Only `bot.php` changes among earlier runtime/reference files. This is a staging candidate, not a production release or a claim that all backend refactoring is finished.

- [V3 boundary and module map](docs/v3/README.md)
- [Current tests and evidence limits](docs/v3/TESTING.md)
- [Source replacement and rollback](docs/v3/OPERATIONS.md)
- [Preserved support behavior](docs/v3/LEGACY-BEHAVIOR.md)
- [V3 work and acceptance](https://github.com/ach1992/bot-refactor/issues/8)

Run `python3 tools/check_v3.py` and the native DB/transport/support/HTTP/swap checks listed in the V3 guide. The main gate runs frozen V2/V1/V0 references before comparing the current source; do not re-record earlier goldens. The 18-request native support lifecycle adds real synthetic persisted-wizard continuity across code swap/rollback, not production/FPM/provider certification.

Keep the real `baseInfo.php`, existing database and private assets/state untouched. Install all modules before the matching root file. Earlier deployment-specific operator evidence remains in Issues #4/#6; the owner explicitly authorized progression, not invented test results. V3 needs its own independent review and applicable integration decision.

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

The immutable [V0 analysis](docs/v0/README.md), [V1 presentation boundary](docs/v1/README.md) and [V2 Telegram boundary](docs/v2/README.md) remain available. Their historical test drivers run in pinned reference trees through the current gate, not directly against later changed runtime code. Current source and acceptance belong to Issue #8 and its PR/CI; source integration never implies production rollout.
