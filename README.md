# AChBot — PHP Telegram VPN bot (refactoring project)

This repository is a **public, sanitized reference baseline** for a staged, behavior-preserving refactor of an existing PHP Telegram bot. **This repository is not a production-certified release.**

> **Safety:** no real tokens, passwords, user records, payment credentials, live backups or runtime caches belong in this repository. Use isolated test infrastructure. Existing credentials in the source database dump should be rotated if still valid.

## Current development boundary: V4 administrator and agent lifecycle

V3 support was independently approved and merged into main as
`283165f19777ad6ab62cfcb03de329c5bb0f8b74`.
V4 isolates complete administrator-role/ban and agent lifecycle
routes into six scoped modules. Only `bot.php` changes
among earlier runtime files; old behavior, database and state
remain fixed. **V4 is a review candidate, not a production release.**

Start with [V4 modules](docs/v4/README.md),
[testing](docs/v4/TESTING.md), [staging / rollback](docs/v4/OPERATIONS.md),
and [known legacy behavior](docs/v4/LEGACY-BEHAVIOR.md).
[Issue #10](https://github.com/ach1992/bot-refactor/issues/10)
owns this candidate and its remaining acceptance.

Run `python3 tools/check_v4.py` plus all native,
HTTP and swap gates in V4 TESTING.md. Earlier reference
snapshots/goldens remain immutable. Keep the real
`baseInfo.php`, original MariaDB and private state
unchanged. V1-V3 operator acceptance is still independently
tracked in Issues #4/#6/#8, and name/callback compatibility in #2.
Owner V3 merge permission did not authorize V4 merge or deployment.

**Official name: AChBot.** Lowercase `achbot` is the technical slug for package/test paths; legacy callbacks and Cron identifiers remain unchanged. Previously approved, frozen source comments may retain earlier spelling pending the relevant refactor stage.

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

The immutable [V0 analysis](docs/v0/README.md), [V1 presentation boundary](docs/v1/README.md) and [V2 Telegram boundary](docs/v2/README.md) remain available. Their historical test drivers run in pinned reference trees through the current gate, not directly against later changed runtime code. V4 source and acceptance belong to Issue #10 and its PR/CI; source integration never implies production rollout.
