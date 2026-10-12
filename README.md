# AchBot — PHP Telegram VPN bot (refactoring project)

This repository is a **public, sanitized reference baseline** for a staged, behavior-preserving refactor of an existing PHP Telegram bot. **Not a new product and not yet a refactored release.**

> **Safety:** no real tokens, passwords, user records, payment credentials, live backups or runtime caches belong in this repository. Use isolated test infrastructure. Existing credentials in the source database dump should be rotated if still valid.

## Current candidate: V1 presentation extraction

V0 was independently approved, accepted by the owner and merged in PR #3. V1 moves 20 presentation/payload helpers into five `src/` modules while retaining legacy global entrypoints. Only the selected bodies in `config.php` and `buy_helpers.php` change; all other original runtime/reference files and the database schema remain fixed. This is not an accepted production release.

- [V1 boundary and module map](docs/v1/README.md)
- [Current test commands and evidence limits](docs/v1/TESTING.md)
- [Staging package, operator checks and rollback](docs/v1/OPERATIONS.md)
- [V1 acceptance work](https://github.com/ach1992/bot-refactor/issues/4)

Run `python3 tools/check_v1.py`, then `python3 tools/test_schema.py --run-disposable --report /tmp/achbot-v1-schema.json` in a development checkout. The aggregate check reconstructs and runs the frozen V0 reference before comparing the new source. Do not re-record V0 goldens to bless V1 behavior.

The real `baseInfo.php` is still local-only; preserve it on every source swap. The schema reference is not a live migration. Old callback/cron naming remains compatible and tracked in Issue #2. Review and operator acceptance are required before this increment becomes the next accepted base.

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

## V0 analysis and test harness

Start at [V0 documentation](docs/v0/README.md). It includes the complete lexical source atlas, database contract, behavior matrix, known-risk register and isolated testing/operations runbooks.

```sh
python3 tools/check.py --report /tmp/achbot-v0-check.json
python3 tools/test_schema.py --run-disposable --report /tmp/achbot-v0-schema.json
```

Use only a development checkout and existing test prerequisites. These commands never use local production configuration. Review [test isolation and limitations](docs/v0/TESTING.md) before running them. Known legacy SQL errors are recorded, not repaired; passing checks do not establish full integration equivalence or production acceptance. Current review/acceptance state belongs to Issue #1 and its PR/CI.

V0 preserves all runtime/reference source bytes. It is not a refactored deployment package and does not authorize V1 or a production source swap.
