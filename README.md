# AChBot

AChBot is a PHP Telegram bot for VPN subscriptions, plans, customer support, administrator management and scheduled service operations. It supports Marzban and native PasarGuard 5.5.1.

**Published release:** [v0.10.0-rc.18](https://github.com/ach1992/bot-refactor/releases/tag/v0.10.0-rc.18), a source-only prerelease. Download the official [runtime ZIP](https://github.com/ach1992/bot-refactor/releases/download/v0.10.0-rc.18/achbot-v0.10.0-rc.18-runtime.zip), [manifest](https://github.com/ach1992/bot-refactor/releases/download/v0.10.0-rc.18/achbot-v0.10.0-rc.18-runtime.zip.manifest.json) and [SHA256SUMS](https://github.com/ach1992/bot-refactor/releases/download/v0.10.0-rc.18/SHA256SUMS). The package contains **115 runtime members / 114 PHP files**; it excludes private configuration and does not install the database.

**Unreleased source:** simple independent purchase/order display sections, ordered exact-name configs (automatic first 3; maximum 10 inline), complete-message fitting, independent explicit order QR actions, managed invitation gift after Wallet, and complete retirement of root `buy_helpers.php`. These changes are not in the published RC18 ZIP. See the [current scope](docs/post-v9/NEXT-RELEASE.md#unreleased-source-follow-up).

## Features

- Server and plan management for Marzban and native PasarGuard; shared customer order, renewal and support journeys.
- Per-server purchase-delivery and order-page presentation, subscription/auxiliary links and QR actions.
- Four administrator levels, separate notification preferences, editable instance messages and configurable channel/support destinations.
- Wallets, supported hosted gateways and manual payments with persisted payment/effect evidence. Manual crypto uses human receipt review; automatic TRON/TXID and WeSwap are retired.

## Requirements

- A Linux host with HTTPS, a PHP web handler and PHP CLI. Compatibility CI runs on **PHP 8.1 and 8.4** with `mysqli`/mysqlnd, `curl`, `zip` and `gd`.
- MariaDB and a dedicated database/account for each bot. Prepare the matching current schema; the historical 23-table DDL alone is insufficient.
- A Telegram bot token, its numeric Owner ID and a unique per-bot webhook secret.
- A supported panel. Native PasarGuard **5.5.1** needs its [Group/Host, subscription URL and API prerequisites](docs/post-v9/PASARGUARD-CONTRACT.md); other versions and retired X-UI variants are not implied.
- For scheduled operations: a one-minute Cron task, CLI `posix_geteuid`, `flock`, `proc_open`, Linux `/proc`, and a private lock directory outside all served webroots.

## Install a new bot

Follow the complete [fresh installation guide](docs/post-v9/FRESH-INSTALL.md). It separates a **new empty database** from upgrades and covers the exact inputs, SQL order, webhook, Cron and acceptance checks.

1. Choose one exact runtime release and its matching private setup-source checkout. Verify the ZIP/manifest; do not combine an older ZIP with newer migrations or tools.
2. Prepare a separate HTTPS installation directory, database/account and Telegram bot identity. Keep setup sources, SQL, backups and logs outside the webroot.
3. For a genuinely empty database, import the schema and apply the guide's ordered sidecars once. **Existing installations use their upgrade guide instead; never rerun completed012/013.**
4. Create private `baseInfo.php` from that version's example. Fill its bot/database/Owner fields and add a unique `$telegramWebhookSecret` (the example does not supply it).
5. Register the HTTPS `<bot-url>/bot.php` webhook using the same `secret_token`. Opening that URL in a browser is not a webhook health test.
6. Start the bot as Owner and configure servers, plans, instance text, destinations and intended payment methods through the administrator UI. Current unreleased source manages invitation gifts there; unset or zero disables them.
7. Provision the private Cron lock directory, then schedule **one** `settings/cronRunner.php` invocation per minute using the correct PHP CLI and bot working directory.
8. Verify source/schema readiness, Owner access, ordinary-user behavior, webhook delivery and scheduled operations on the selected isolated installation before customer use.

Each installation needs its own `baseInfo.php`, token/webhook, database and Cron task. Do not copy another bot's live secrets or point a second bot at its database.

## Cron locks and multiple bots

The default `/var/lib/achbot/cron-locks` is a private **lock directory**, not the bot installation path. The coordinator creates a distinct `achbot-cron-<hash>.lock` for each canonical absolute installation path. Bots in different real directories therefore have independent locks, even when the directory is shared.

Bots running Cron under the **same effective OS user** may reuse that user's private directory. Different OS users need separate directories owned by the corresponding user, mode `0700`, selected with `ACHBOT_CRON_LOCK_DIR`. The application does not provision the directory or accept a lock directory inside the installation. Never delete, rotate or recreate an active lock file.

The coordinator runs `gift2all.php`, its admitted `broadcast` mode, `rewardReport.php`, and its admitted `services` mode as separate sequential PHP processes. An overlapping minute is skipped, not queued. Schedule the coordinator only; the old `messagewizwiz.php`, `warnusers.php` and automatic TRON checker paths are retired. See [Cron setup and examples](docs/post-v9/FRESH-INSTALL.md#6-configure-cron-and-its-private-lock).

## Configuration and upgrades

Use Bot Settings for instance welcome/home/purchase text and the two explicit order QR switches. Gateway/channel settings own payment configuration and support/report/membership/help/cooperation destinations. Owner manages staff levels and receiving preferences in the administrator list. Each server owns its display settings; static interface copy remains in the two [audience catalogs](messages/README.md).

For upgrades, inventory the actual source, schema, private configuration and active schedules first. Replace the complete runtime together, reconcile retired files, preserve payment/native evidence and the active Cron lock, and verify a restorable backup. ZIP extraction never applies SQL. Follow [RC17 → RC18](docs/post-v9/RC17-TO-RC18.md), [RC16 → RC17](docs/post-v9/RC16-TO-RC17.md), or the matching earlier transition in the [scope guide](docs/post-v9/NEXT-RELEASE.md). Unreleased source additionally needs its documented invitation-setting and root-helper rollout steps.

## Documentation

- [Fresh installation: private configuration, ordered SQL, webhook and Cron](docs/post-v9/FRESH-INSTALL.md)
- [Current source/release scope and configuration boundaries](docs/post-v9/NEXT-RELEASE.md)
- [Per-server display and QR settings](docs/post-v9/SUBSCRIPTION-PRESENTATION.md)
- [Native PasarGuard contract](docs/post-v9/PASARGUARD-CONTRACT.md)
- [Read-only installed-runtime diagnostics](docs/post-v9/INSTALL-DIAGNOSTICS.md)
- [Read-only payment readiness preflight](docs/post-v9/PAYMENT-READINESS-PREFLIGHT.md)
- [Operator acceptance checklist](docs/post-v9/MARZBAN-OPERATOR-ACCEPTANCE.md)
- [Shared storage migration and recovery](docs/post-v9/SHARED-PROVIDER-STORAGE.md)
- [Financial safety and unresolved acceptance](docs/post-v9/FINANCIAL-CONSOLIDATION.md)
- [Shared USDT rate configuration](docs/post-v9/USDT-RATE-SETTINGS.md)
- [Project specification](docs/PROJECT-SPEC.md) and [security guidance](docs/SECURITY.md)

## Development

Run `python3 tools/check_post_v9.py --report /tmp/achbot-post-v9.json` for the current isolated suite. It uses disposable MariaDB and loopback provider fixtures. Build source packages with `tools/package_post_v9.py create` and check them with its `verify` command. Required CI separately exercises immutable original V0–V9 history; do not run those historical parity contracts directly against the changed current runtime.

Source tests, independent review and publication do not establish installed Telegram/provider/payment acceptance. Those open boundaries remain tracked in Issues [26](https://github.com/ach1992/bot-refactor/issues/26), [37](https://github.com/ach1992/bot-refactor/issues/37), [51](https://github.com/ach1992/bot-refactor/issues/51) and [81](https://github.com/ach1992/bot-refactor/issues/81).
