# AChBot

AChBot is a PHP Telegram bot for managing VPN subscriptions through **Marzban**. It includes customer subscription and account flows, administrator server/plan management, support and scheduled operations. The current source is **Marzban-only**; the retired X-UI/Sanaei/Alireza/legacy normal panel adapters are not supported.

> **Release status:** Consult [GitHub Releases](https://github.com/ach1992/bot-refactor/releases) for the latest **Marzban-only source-only prerelease** and exact artifact identity. Earlier [v0.10.0-rc.2](https://github.com/ach1992/bot-refactor/releases/tag/v0.10.0-rc.2) was a security-only `config.php` hotfix to [RC1](https://github.com/ach1992/bot-refactor/releases/tag/v0.10.0-rc.1); historical V9 remains at [v0.9.0-rc.1](https://github.com/ach1992/bot-refactor/releases/tag/v0.9.0-rc.1). **Full installed/operator and financial acceptance is incomplete; source-only prereleases are not production-certified.** A ZIP replacement is neither atomic nor proof of active running code. Preserve local configuration and require separate deployment authorization.

## Requirements

- PHP with the extensions used by the bot, including `mysqli`, `curl`, `zip` and `gd`. Automated compatibility checks run on **PHP 8.1 and 8.4**.
- MariaDB with the existing bot's **23-table schema** and its application data.
- A Telegram bot/webhook, a Marzban backend, and an appropriately isolated test deployment.
- Local deployment settings in `baseInfo.php`, based on [`baseInfo.example.php`](baseInfo.example.php). Never commit real credentials, tokens, customer records or backups.

**Post-V9 schema prerequisite:** the current `main` additionally expects a nullable `server_info.panel_group_id` column and the separately migrated provider-neutral payment tables `payment_attempts` and `payment_transactions`. The separate [migration](migrations/post-v9/001_marzban_panel_group_id.sql) is **not** executed or embedded by the source ZIP. Read the [migration and recovery guide](docs/post-v9/PANEL-GROUPS-MIGRATION.md) before considering any upgrade. First verify a full backup can be restored into an isolated database; never test a migration directly on an unprotected live bot.

## Getting started in an isolated test environment

1. Choose the **exact, approved source-only package** for the version you intend to test. A GitHub source snapshot is not the verified runtime ZIP; verify the accompanying manifest and source identity.
2. Prepare a separate test installation and MariaDB copy. Preserve the existing bot, `baseInfo.php`, database, webhooks, Cron configuration and rollback copy. Do not import real secrets or data into disposable/public tooling.
3. Configure local `baseInfo.php` from the example, with test-only Telegram/Marzban credentials and matching database details. The archive deliberately excludes this private file.
4. For the **post-V9** runtime, complete the separately authorized, backed-up additive schema migration **before** enabling its PHP handlers. Do not attempt to use the post-V9 runtime against the unmigrated schema.
5. Configure the webhook and scheduled entrypoints for your isolated environment, then verify `/start`, administrator access, server/plan menus, subscription/account views and safe test-account operations. Financial and TRON settlement tests must not use live money.

**If legacy X-UI menus are still visible after replacing the ZIP**, do not repeat SQL migration: use the [read-only installed-runtime provenance guide](docs/post-v9/INSTALL-DIAGNOSTICS.md) to check actual served `bot.php`/`config.php`, Telegram webhook path, old inline messages and the active PHP runtime/OPcache (OpenLiteSpeed LSAPI or PHP-FPM, as applicable).

In **Server Management → Panel Groups**, an administrator can name a group and assign several logical server entries to it **only when they actually point to the same physical Marzban account store**. Different URLs or admin usernames can still represent the same backend. Unassigned or differently grouped servers cannot be switched between. An approved same-group logical switch updates the bot's `orders_list.server_id` only; it **does not migrate or modify a remote Marzban account**.

Use the [post-V9 operator acceptance checklist](docs/post-v9/MARZBAN-OPERATOR-ACCEPTANCE.md) before considering a test installation accepted.

**Finance source and migration status:** the immutable published **v0.10.0-rc.4** included the historical NOWPayments-only migration 002. [Merged PR #44](https://github.com/ach1992/bot-refactor/pull/44) supersedes that design in the current source with unprefixed shared `payment_attempts` and `payment_transactions` tables for NOWPayments, NextPay and Zarinpal. Consult [GitHub Releases](https://github.com/ach1992/bot-refactor/releases) for the exact source-only package: merging or publishing does **not** migrate any database. The new migration is [003](migrations/post-v9/003_payment_attempts.sql) and requires independent backup/restore proof, isolated rehearsal and separate Owner authorization for the real target. **Never run RC4 migration 002 merely to prepare 003.** If RC4 tables were already applied, follow the separately controlled [non-destructive import route](docs/post-v9/PAYMENT-LEDGER-MIGRATION.md); do not delete old rows. Private NOWPayments IPN signing/payout policy, actual Cron reachability, operator acceptance and live installation remain separate gates.


## Optional single-entry Cron runner (not activated on installed bots)

The source provides **`settings/cronRunner.php`** as a CLI-only coordinator for the existing five scheduled scripts, executed sequentially in their current order and **as separate PHP processes**. It does not change their individual filenames, logic, SQL, Telegram or payment effects. It preserves the inherited working directory and continues to the remaining jobs if one fails, returning a failed exit status for the operator log.

**Do not add it alongside the five existing aaPanel commands** or they will execute twice. Switching later requires a separately authorized operator change that **replaces** those five commands with a *single* `php /absolute/bot/path/settings/cronRunner.php` command, preserving the current once-per-minute schedule, PHP interpreter, aaPanel flock and external Cron logging. Release source or CI tests do **not** activate the runner or certify live Cron/payment side effects. No DB/schema migration is associated with this optional source addition.

Run its harmless native synthetic regression independently using `python3 tools/test_post_v9_cron_runner.py`; it runs disposable fake child PHP workers and an owned loopback HTTP denial test, never the real five scripts.

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

These tests and archives do **not** certify live payment settlement, full database recovery, Telegram webhooks, real Marzban provisioning or production deployment. The Owner has reported installing RC3 and observing no obvious initial regression, including an agency-decline smoke check; the [shared-ledger PR #44](https://github.com/ach1992/bot-refactor/pull/44) has been **merged and independently approved at the source level**, but the merged runtime must not replace any installation until separately authorized schema migration, staged acceptance and deployment checks are satisfied. For NOWPayments, a hosted browser return is not proof of payment; only verified provider `finished` can be eligible for settlement, subject to identity/amount and effect-idempotency checks still under development. The inherited financial/settlement risks are tracked in [Issue #26](https://github.com/ach1992/bot-refactor/issues/26).

## Documentation

- [Post-V9 Marzban behavior and validation](docs/post-v9/MARZBAN-ONLY.md)
- [Financial consolidation, NOWPayments finality and migration gates](docs/post-v9/FINANCIAL-CONSOLIDATION.md)
- [Shared payment ledger: migration, old RC4 data and recovery](docs/post-v9/PAYMENT-LEDGER-MIGRATION.md)
- [NOWPayments unattended IPN, checkout recovery and additive migration](docs/post-v9/NOWPAYMENTS-MIGRATION.md)
- [Schema migration and rollback precautions](docs/post-v9/PANEL-GROUPS-MIGRATION.md)
- [Operator acceptance checklist](docs/post-v9/MARZBAN-OPERATOR-ACCEPTANCE.md)
- [Project specification and historical V0–V9 contract](docs/PROJECT-SPEC.md)
- [Historical V9 operator notes](docs/v9/OPERATIONS.md)
- [Public repository security guidance](docs/SECURITY.md)
- [Source-only releases](https://github.com/ach1992/bot-refactor/releases)

The published V0–V9 history is preserved separately from the intentionally changed post-V9 Marzban-only runtime. The older `wizwiz` callback and scheduled-entrypoint identifiers may still appear for compatibility.
