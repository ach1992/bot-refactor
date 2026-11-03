# AChBot

AChBot is a PHP Telegram bot for VPN subscriptions, plans, customer support, administrator management and scheduled service operations. It supports Marzban and native PasarGuard 5.5.1.

**Release:** [v0.10.0-rc.22](https://github.com/ach1992/bot-refactor/releases/tag/v0.10.0-rc.22), a **source-only prerelease**. Obtain its [runtime ZIP](https://github.com/ach1992/bot-refactor/releases/download/v0.10.0-rc.22/achbot-v0.10.0-rc.22-runtime.zip), [manifest](https://github.com/ach1992/bot-refactor/releases/download/v0.10.0-rc.22/achbot-v0.10.0-rc.22-runtime.zip.manifest.json) and [SHA256SUMS](https://github.com/ach1992/bot-refactor/releases/download/v0.10.0-rc.22/SHA256SUMS) together. The runtime has **121 members / 120 PHP files** and includes no private configuration, SQL or installer.

**New in RC22:** administrator-configurable global, server-specific and native PasarGuard Group-set offers for both day and volume extensions, with verified per-subscription eligibility, immutable quoted amounts, supported Wallet/Manual/Hosted/API settlement and replay protections. An authenticated imported PasarGuard subscription with an empty native Group-set can use eligible global/server offers, but not a Group-specific offer. The top-level Cron coordinator can securely initialize a missing private external lock directory on first CLI run, when its OS user has permission. The current-source CI policy uses PHP 8.5 with eight isolated native families and guarded evidence reuse; this is **not** a claim of live PHP 8.5 or installed-host acceptance. Read the [scoped add-on contract](docs/post-v9/ADDON-SCOPES.md) and [RC21 → RC22 upgrade guide](docs/post-v9/RC21-TO-RC22.md).

**Schema / upgrade:** optional [migration 015](migrations/post-v9/015_scoped_addons.sql) is required to **enable scoped add-on pricing**. It adds **7 columns across three existing tables and 2 unique indexes**, with no permanent new table. Without 015, unchanged legacy global offers remain available; partially applied or incompatible 015 must fail closed. Inspect legacy catalogue duplicates, rehearse and restore-test a database backup, quiesce webhook/Cron and obtain separate target authorization before applying it. The earlier complete migration 014 remains required for optional API evidence; **never replay completed 012/013/014**. Neither publishing nor extracting the ZIP applies SQL. See [RC21 → RC22](docs/post-v9/RC21-TO-RC22.md) for correct source/schema ordering, recovery and in-flight payment boundaries.

## Features

- Server and plan management for Marzban and native PasarGuard; shared customer order, renewal and support journeys.
- Per-server purchase-delivery and order-page presentation, subscription/auxiliary links and QR actions.
- Four administrator levels, separate notification preferences, editable instance messages and configurable channel/support destinations.
- Wallets, supported hosted gateways and manual payments with persisted payment/effect evidence. Optional exact-amount SMS and exact-value gift-code verification reuse the manual methods; manual crypto retains human receipt review.
- Administrator gifts work independently of purchase plans, including genuine planless imports. Shared registrations receive one native change and one notice per current linked customer; displayed quota/expiry come from the panel.
- Automatic TRON/TXID and WeSwap are retired.

## Requirements

- A Linux host with HTTPS, a PHP web handler and PHP CLI. **New source validation uses PHP 8.5 only**, with `mysqli`/mysqlnd, `curl`, `zip` and `gd`. Previously released RC21 was validated under its own historical toolchain; this CI policy is not a claim of installed PHP 8.5 acceptance.
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
7. Prepare the private Cron lock directory for the selected release, then schedule **one** `settings/cronRunner.php` invocation per minute using the correct PHP CLI and bot working directory. RC22 can create a missing private external directory on its first run when the Cron user can write to its trusted parent; RC21 requires manual provisioning.
8. Verify source/schema readiness, Owner access, ordinary-user behavior, webhook delivery and scheduled operations on the selected isolated installation before customer use.

Each installation needs its own `baseInfo.php`, token/webhook, database and Cron task. Do not copy another bot's live secrets or point a second bot at its database.

## Cron locks and multiple bots

The default `/var/lib/achbot/cron-locks` is a private **lock directory**, not the bot installation path. The coordinator creates a distinct `achbot-cron-<hash>.lock` for each canonical absolute installation path. Bots in different real directories therefore have independent locks, even when the directory is shared.

Bots running Cron under the **same effective OS user** may reuse that user's private directory. Different OS users need separate directories owned by the corresponding user, mode `0700`, selected with `ACHBOT_CRON_LOCK_DIR`. The directory must remain outside the installation and all served webroots. Never delete, rotate or recreate an active lock file.

**RC22 and later current source:** the top-level CLI coordinator creates missing lock-directory components as its current OS user with mode `0700`, after checking each existing parent. Existing valid directories and lock files are reused without permission or inode changes. Unsafe existing paths fail closed rather than being repaired. This grants no extra OS permissions: if that user cannot create the default path, select an external path under a trusted writable parent with `ACHBOT_CRON_LOCK_DIR`, or have an administrator provision it. Child modes, HTTP requests and includes cannot provision directories. Earlier RC21 requires manual provisioning.

The coordinator runs `gift2all.php`, its admitted `broadcast` mode, `rewardReport.php`, and its admitted `services` mode as separate sequential PHP processes. An overlapping minute is skipped, not queued. Schedule the coordinator only; the old `messagewizwiz.php`, `warnusers.php` and automatic TRON checker paths are retired. See [Cron setup and examples](docs/post-v9/FRESH-INSTALL.md#6-configure-cron-and-its-private-lock).

## Configuration and upgrades

Use Bot Settings for instance welcome/home/purchase text and the two explicit order QR switches. Gateway/channel settings own payment configuration and support/report/membership/help/cooperation destinations. Owner manages staff levels and receiving preferences in the administrator list. Each server owns its display settings; static interface copy remains in the two [audience catalogs](messages/README.md).

For upgrades, inventory the actual source, schema, private configuration and active schedules first. Replace the complete runtime together, reconcile retired files, preserve payment/native evidence and the active Cron lock, and verify a restorable backup. ZIP extraction never applies SQL. Follow [RC21 → RC22](docs/post-v9/RC21-TO-RC22.md), [RC20 → RC21](docs/post-v9/RC20-TO-RC21.md), [RC19 → RC20](docs/post-v9/RC19-TO-RC20.md), [RC18 → RC19](docs/post-v9/RC18-TO-RC19.md), [RC17 → RC18](docs/post-v9/RC17-TO-RC18.md), [RC16 → RC17](docs/post-v9/RC16-TO-RC17.md), or the matching earlier transition in the [scope guide](docs/post-v9/NEXT-RELEASE.md). RC22 retains the additive014 prerequisite before API enablement and introduces optional015 for scoped add-ons; RC19's invitation-setting and root-helper rollout requirements still apply when upgrading from an older version. After API issuance or other new writes, use the documented evidence-preserving roll-forward plan rather than a blind source/database downgrade.

## Documentation

- [RC21 → RC22 scoped add-ons, Cron and migration/recovery](docs/post-v9/RC21-TO-RC22.md)
- [Add-on pricing, native Group-set semantics and operator migration](docs/post-v9/ADDON-SCOPES.md)
- [RC20 → RC21 evidence-first upgrade and recovery](docs/post-v9/RC20-TO-RC21.md)
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

Run `python3 tools/check_post_v9.py --suite source` for fast source/inventory/PHP-lint feedback or `--suite cron` for that coverage plus isolated Cron coordinator regression. Neither selected mode claims full current-runtime acceptance. The default `python3 tools/check_post_v9.py --report /tmp/achbot-post-v9.json` is the complete serial current suite under **PHP 8.5**; CI distributes its eight native families across isolated runners, then strictly aggregates all reports. Native tests use disposable MariaDB and loopback provider fixtures; no production services are contacted. Build source packages with `tools/package_post_v9.py create` and check them with `verify`. Immutable original V0–V9 source/schema pins remain guarded, but their old PHP 8.1/8.4 executions are archived rather than falsely rerun or relabelled PHP 8.5. See [CI validation policy](docs/post-v9/CI-VALIDATION.md).

Source tests, independent review and publication do not establish installed Telegram/provider/payment acceptance. Those open boundaries remain tracked in Issues [26](https://github.com/ach1992/bot-refactor/issues/26), [37](https://github.com/ach1992/bot-refactor/issues/37), [51](https://github.com/ach1992/bot-refactor/issues/51) and [81](https://github.com/ach1992/bot-refactor/issues/81).
