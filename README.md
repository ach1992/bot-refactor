# AchBot — PHP Telegram VPN bot (refactoring project)

This repository is a **public, sanitized reference baseline** for a staged, behavior-preserving refactor of an existing PHP Telegram bot. **Not a new product and not yet a refactored release.**

> **Safety:** no real tokens, passwords, user records, payment credentials, live backups or runtime caches belong in this repository. Use isolated test infrastructure. Existing credentials in the source database dump should be rotated if still valid.

## Current development boundary: V2 Telegram integration

V0 and V1 have independent technical approval and are integrated in PRs #3 and #5. V2 extracts sixteen Telegram transport/message operations and moves the existing incoming-update assignments into a scoped compatibility adapter. Only `config.php` changes among existing runtime files; three new files extend the five unchanged V1 modules. This is not an accepted production release.

Start with [V2 module map](docs/v2/README.md), [test commands and evidence limits](docs/v2/TESTING.md), and [staging replacement/rollback](docs/v2/OPERATIONS.md). [Issue #6](https://github.com/ach1992/bot-refactor/issues/6) and its PR own current V2 acceptance. [Issue #4](https://github.com/ach1992/bot-refactor/issues/4) retains deployment-specific V1 operator evidence separately; the owner's subsequent instruction authorized delegated checks and starting V2, not a claim that manual tests occurred.

```sh
python3 tools/check_v2.py --report /tmp/achbot-v2-check.json
python3 tools/test_schema.py --run-disposable --report /tmp/achbot-v2-schema.json
python3 tools/test_native_telegram.py --run-loopback --report /tmp/achbot-v2-http.json
python3 tools/rehearse_swap.py --report /tmp/achbot-v2-swap.json
```

Use a development checkout and existing prerequisites only. The aggregate gate reconstructs and executes frozen V1/V0 references before comparing current source; do not run their old byte-lock gates directly against modified runtime source or re-record snapshots to accept changes. The native HTTP test uses only an owned loopback listener, never the real Telegram API.

Keep the real `baseInfo.php`, database and existing runtime assets/state unchanged during every source swap. The schema reference is not a live migration. Old callback/Cron naming remains compatible and tracked in Issue #2. Exact independent review and applicable owner acceptance remain required before V2 integration or deployment.

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

## Historical reference evidence

[V0 documentation](docs/v0/README.md) contains the lexical atlas, database contract, behavior matrix, risk register and original harness design. [V1 documentation](docs/v1/README.md) describes the accepted presentation-helper extraction. Their scripts, fixtures and snapshots remain byte-identical; the current V2 driver runs them inside the correct pinned source trees.

Known legacy SQL errors are recorded, not repaired. Passing tests do not establish full-route, provider, concurrency, FPM/OPcache or production compatibility. Current decisions and unresolved gates live in the controlling issues/PRs rather than this README.
