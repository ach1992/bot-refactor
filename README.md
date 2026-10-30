# AChBot

AChBot is a PHP Telegram bot for VPN subscriptions, plans, customer support, administrator management and scheduled service operations. It supports Marzban and native PasarGuard 5.5.1.

**Version covered here:** [v0.10.0-rc.20](https://github.com/ach1992/bot-refactor/releases/tag/v0.10.0-rc.20), a source-only prerelease. The release page is authoritative for publication status and assets. Use its [runtime ZIP](https://github.com/ach1992/bot-refactor/releases/download/v0.10.0-rc.20/achbot-v0.10.0-rc.20-runtime.zip), [manifest](https://github.com/ach1992/bot-refactor/releases/download/v0.10.0-rc.20/achbot-v0.10.0-rc.20-runtime.zip.manifest.json) and [SHA256SUMS](https://github.com/ach1992/bot-refactor/releases/download/v0.10.0-rc.20/SHA256SUMS) together. The runtime contains **119 members / 118 PHP files**; it excludes private configuration, SQL and installation tools.

**RC20 changes:** optional bank-SMS and gift-card API verification within the existing manual methods, independent default-OFF switches, ten-minute bank-receipt fallback, shared payment/effect recovery, plan-independent administrator gifts applied once per physical subscription with notices to every current linked customer, and event-aware staff/report routing. The separate legacy automatic card-transfer path remains unchanged. Read [payment behavior and recovery](docs/post-v9/API-PAYMENT-EVIDENCE.md).

**Upgrade:** RC20 adds [source migration 014](migrations/post-v9/014_api_payment_evidence.sql) to the existing shared payment ledger. It must be applied manually to an authorized target and verified before either API mode is enabled; publication or ZIP extraction applies no SQL. Follow [RC19 → RC20](docs/post-v9/RC19-TO-RC20.md). Completed012/013 must never be rerun. RC19 and its published display/QR/invitation behavior remain immutable historical release facts; RC20 retains those features.

## Features

- Server and plan management for Marzban and native PasarGuard; shared customer order, renewal and support journeys.
- Per-server purchase-delivery and order-page presentation, subscription/auxiliary links and QR actions.
- Four administrator levels, separate notification preferences, editable instance messages and configurable channel/support destinations.
- Wallets, supported hosted gateways and manual payments with persisted payment/effect evidence. Optional exact-amount SMS and exact-value gift-code verification reuse the manual methods; manual crypto retains human receipt review.
- Administrator gifts work independently of purchase plans, including genuine planless imports. Shared registrations receive one native change and one notice per current linked customer; displayed quota/expiry come from the panel.
- Automatic TRON/TXID and WeSwap are retired.

## Requirements

- A Linux host with HTTPS, a PHP web handler and PHP CLI. Compatibility CI runs on **PHP 8.1 and 8.4** with `mysqli`/mysqlnd, `curl`, `zip` and `gd`.
- MariaDB and a dedicated database/account for each bot. Prepare the matching current schema; the historical 23-table DDL alone is insufficient.
- A Telegram bot token, its numeric Owner ID and a unique per-bot webhook secret. API evidence additionally needs OpenSSL AES-256-GCM support; preserve the existing secret on upgrades because it protects pinned credentials and recoverable codes.
- A supported panel. Native PasarGuard **5.5.1** needs its [Group/Host, subscription URL and API prerequisites](docs/post-v9/PASARGUARD-CONTRACT.md); other versions and retired X-UI variants are not implied.
- For scheduled operations: a one-minute Cron task, CLI `posix_geteuid`, `flock`, `proc_open`, Linux `/proc`, and a private lock directory outside all served webroots.

## Install a new bot

Follow the complete [fresh installation guide](docs/post-v9/FRESH-INSTALL.md). It separates a **new empty database** from upgrades and covers the exact inputs, SQL order, webhook, Cron and acceptance checks.

1. Choose one exact runtime release and its matching private setup-source checkout. Verify the ZIP/manifest; do not combine an older ZIP with newer migrations or tools.
2. Prepare a separate HTTPS installation directory, database/account and Telegram bot identity. Keep setup sources, SQL, backups and logs outside the webroot.
3. For a genuinely empty database, import the schema and apply the guide's ordered sidecars once. **Existing installations use their upgrade guide instead; never rerun completed 012/013.**
4. Create private `baseInfo.php` from that version's example. Fill its bot/database/Owner fields and add a unique `$telegramWebhookSecret` (the example does not supply it).
5. Register the HTTPS `<bot-url>/bot.php` webhook using the same `secret_token`. Opening that URL in a browser is not a webhook health test.
6. Start the bot as Owner and configure servers, plans, instance text, destinations and intended payment methods through the administrator UI. Configure invitation gifts there; unset or zero disables them. Keep both API switches OFF until 014 and their actual profiles are verified; see the [API setup requirements](docs/post-v9/API-PAYMENT-EVIDENCE.md#configuration-before-api-enablement).
7. Provision the private Cron lock directory, then schedule **one** `settings/cronRunner.php` invocation per minute using the correct PHP CLI and bot working directory.
8. Verify source/schema readiness, Owner access, ordinary-user behavior, webhook delivery and scheduled operations on the selected isolated installation before customer use.

Each installation needs its own `baseInfo.php`, token/webhook, database and Cron task. Do not copy another bot's live secrets or point a second bot at its database.

## Cron locks and multiple bots

The default `/var/lib/achbot/cron-locks` is a private **lock directory**, not the bot installation path. The coordinator creates a distinct `achbot-cron-<hash>.lock` for each canonical absolute installation path. Bots in different real directories therefore have independent locks, even when the directory is shared.

Bots running Cron under the **same effective OS user** may reuse that user's private directory. Different OS users need separate directories owned by the corresponding user, mode `0700`, selected with `ACHBOT_CRON_LOCK_DIR`. The application does not provision the directory or accept a lock directory inside the installation. Never delete, rotate or recreate an active lock file.

The coordinator runs `gift2all.php`, its admitted `broadcast` mode, `rewardReport.php`, and its admitted `services` mode as separate sequential PHP processes. An overlapping minute is skipped, not queued. Schedule the coordinator only; the old `messagewizwiz.php`, `warnusers.php` and automatic TRON checker paths are retired. See [Cron setup and examples](docs/post-v9/FRESH-INSTALL.md#6-configure-cron-and-its-private-lock).

## Configuration and upgrades

Use Bot Settings for instance welcome/home/purchase text and the two explicit order QR switches. Gateway/channel settings own payment configuration and support/report/membership/help/cooperation destinations. Owner manages staff levels and receiving preferences in the administrator list. Each server owns its display settings; static interface copy remains in the two [audience catalogs](messages/README.md).

For upgrades, inventory the actual source, schema, private configuration and active schedules first. Replace the complete runtime together, reconcile retired files, preserve payment/native evidence and the active Cron lock, and verify a restorable backup. ZIP extraction never applies SQL. Follow [RC19 → RC20](docs/post-v9/RC19-TO-RC20.md), [RC18 → RC19](docs/post-v9/RC18-TO-RC19.md), [RC17 → RC18](docs/post-v9/RC17-TO-RC18.md), [RC16 → RC17](docs/post-v9/RC16-TO-RC17.md), or the matching earlier transition in the [scope guide](docs/post-v9/NEXT-RELEASE.md). RC20 needs the additive014 schema before API enablement; RC19's invitation-setting and root-helper rollout requirements still apply when upgrading from an older version. After API issuance or other new writes, use the documented evidence-preserving roll-forward plan rather than a blind source/database downgrade.

## Documentation

- [RC19 → RC20 upgrade, API prerequisites and recovery](docs/post-v9/RC19-TO-RC20.md)
- [RC18 → RC19 upgrade and recovery](docs/post-v9/RC18-TO-RC19.md)
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
