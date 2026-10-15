# AChBot

AChBot is a PHP Telegram bot for managing VPN subscriptions through **Marzban**. It includes customer subscription and account flows, administrator server/plan management, support and scheduled operations. The current source is **Marzban-only**; the retired X-UI/Sanaei/Alireza/legacy normal panel adapters are not supported.

> **Release status (2026-10-15):** [v0.10.0-rc.1](https://github.com/ach1992/bot-refactor/releases/tag/v0.10.0-rc.1) is the current **Marzban-only source-only pre-release**; the immutable historical V9 source remains available at [v0.9.0-rc.1](https://github.com/ach1992/bot-refactor/releases/tag/v0.9.0-rc.1). This pre-release has not completed full installed/operator acceptance and is **not production-certified**; verify your own runtime and schema before upgrading. Replacing a ZIP is not an atomic or self-verifying update.

## Requirements

- PHP with the extensions used by the bot, including `mysqli`, `curl`, `zip` and `gd`. Automated compatibility checks run on **PHP 8.1 and 8.4**.
- MariaDB with the existing bot's **23-table schema** and its application data.
- A Telegram bot/webhook, a Marzban backend, and an appropriately isolated test deployment.
- Local deployment settings in `baseInfo.php`, based on [`baseInfo.example.php`](baseInfo.example.php). Never commit real credentials, tokens, customer records or backups.

**Post-V9 schema requirement:** the current `main` code additionally expects a nullable `server_info.panel_group_id` column. The separate [migration](migrations/post-v9/001_marzban_panel_group_id.sql) is **not** executed or embedded by the source ZIP. Read the [migration and recovery guide](docs/post-v9/PANEL-GROUPS-MIGRATION.md) before considering any upgrade. First verify a full backup can be restored into an isolated database; never test a migration directly on an unprotected live bot.

## Getting started in an isolated test environment

1. Choose the **exact, approved source-only package** for the version you intend to test. A GitHub source snapshot is not the verified runtime ZIP; verify the accompanying manifest and source identity.
2. Prepare a separate test installation and MariaDB copy. Preserve the existing bot, `baseInfo.php`, database, webhooks, Cron configuration and rollback copy. Do not import real secrets or data into disposable/public tooling.
3. Configure local `baseInfo.php` from the example, with test-only Telegram/Marzban credentials and matching database details. The archive deliberately excludes this private file.
4. For the **post-V9** runtime, complete the separately authorized, backed-up additive schema migration **before** enabling its PHP handlers. Do not attempt to use the post-V9 runtime against the unmigrated schema.
5. Configure the webhook and scheduled entrypoints for your isolated environment, then verify `/start`, administrator access, server/plan menus, subscription/account views and safe test-account operations. Financial and TRON settlement tests must not use live money.

**If legacy X-UI menus are still visible after replacing the ZIP**, do not repeat SQL migration: use the [read-only installed-runtime provenance guide](docs/post-v9/INSTALL-DIAGNOSTICS.md) to check actual served `bot.php`/`config.php`, Telegram webhook path, old inline messages and the active PHP runtime/OPcache (OpenLiteSpeed LSAPI or PHP-FPM, as applicable).

In **Server Management → Panel Groups**, an administrator can name a group and assign several logical server entries to it **only when they actually point to the same physical Marzban account store**. Different URLs or admin usernames can still represent the same backend. Unassigned or differently grouped servers cannot be switched between. An approved same-group logical switch updates the bot's `orders_list.server_id` only; it **does not migrate or modify a remote Marzban account**.

Use the [post-V9 operator acceptance checklist](docs/post-v9/MARZBAN-OPERATOR-ACCEPTANCE.md) before considering a test installation accepted.

## Development and tests

The current Marzban-only suite uses disposable MariaDB instances and loopback fake providers, not live customer/payment services:

```bash
python3 tools/check_post_v9.py --report /tmp/achbot-post-v9.json
```

To create and verify a **source-only** archive from a clean committed checkout:

```bash
python3 tools/package_post_v9.py create --output /tmp/achbot-runtime.zip
python3 tools/package_post_v9.py verify \
  --zip /tmp/achbot-runtime.zip \
  --manifest /tmp/achbot-runtime.zip.manifest.json
```

These tests and archives do **not** certify live payment settlement, full database recovery, Telegram webhooks, real Marzban provisioning or production deployment. The inherited financial/settlement risks are tracked in [Issue #26](https://github.com/ach1992/bot-refactor/issues/26).

## Documentation

- [Post-V9 Marzban behavior and validation](docs/post-v9/MARZBAN-ONLY.md)
- [Schema migration and rollback precautions](docs/post-v9/PANEL-GROUPS-MIGRATION.md)
- [Operator acceptance checklist](docs/post-v9/MARZBAN-OPERATOR-ACCEPTANCE.md)
- [Project specification and historical V0–V9 contract](docs/PROJECT-SPEC.md)
- [Historical V9 operator notes](docs/v9/OPERATIONS.md)
- [Public repository security guidance](docs/SECURITY.md)
- [Source-only releases](https://github.com/ach1992/bot-refactor/releases)

The published V0–V9 history is preserved separately from the intentionally changed post-V9 Marzban-only runtime. The older `wizwiz` callback and scheduled-entrypoint identifiers may still appear for compatibility.
