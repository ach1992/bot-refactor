# AChBot

AChBot is a PHP Telegram bot for VPN subscriptions, plans, customer support, administrator management and scheduled service operations. It supports Marzban and native PasarGuard 5.5.1.

**Release:** [v0.10.0-rc.25](https://github.com/ach1992/bot-refactor/releases/tag/v0.10.0-rc.25), a **source-only prerelease**. Download its verified [runtime ZIP](https://github.com/ach1992/bot-refactor/releases/download/v0.10.0-rc.25/achbot-v0.10.0-rc.25-runtime.zip), [source manifest](https://github.com/ach1992/bot-refactor/releases/download/v0.10.0-rc.25/achbot-v0.10.0-rc.25-runtime.zip.manifest.json), and [SHA256SUMS](https://github.com/ach1992/bot-refactor/releases/download/v0.10.0-rc.25/SHA256SUMS) together. The runtime has **124 members / 123 PHP files** and contains no private configuration, SQL or installer. [PR #115](https://github.com/ach1992/bot-refactor/pull/115) was independently approved and merged with [full PHP 8.5 CI](https://github.com/ach1992/bot-refactor/actions/runs/42192558708) plus [successful post-merge validation](https://github.com/ach1992/bot-refactor/actions/runs/42196043404); source publication is **not** installed-host or real-provider acceptance.

**New in RC25:** an authorized administrator may assign, change or clear a Panel Group for Marzban and PasarGuard regardless of active/historical orders, saved plans, payment state, journals, or differing endpoints and administrator credentials. Group membership is the administrator's declaration of a genuinely shared physical panel and changes **only local Panel Group metadata**. Separate Native account, payment, transfer and plan operations retain their own identity, unresolved-journal and replay guards. See the [RC24 -> RC25 upgrade guide](docs/post-v9/RC24-TO-RC25.md) and [shared service/physical-panel contract](docs/post-v9/SHARED-SERVICE-TRANSFER.md).

**Schema / upgrade:** RC25 introduces **no new migration**. Installations correctly migrated through RC24's one-time [016](migrations/post-v9/016_remove_pasarguard_server_groups.sql) and [017](migrations/post-v9/017_shared_plan_changes.sql) must not rerun them. The earlier optional [015](migrations/post-v9/015_scoped_addons.sql) enables scoped add-on prices; 014 is an older prerequisite for optional API payments. Each migration is applied only when genuinely missing and appropriate for the actual source/schema, under a separately authorized, restore-tested operator rollout; 016 is a potentially destructive drop. ZIP extraction and GitHub publication never apply SQL. See the [RC24 -> RC25 guide](docs/post-v9/RC24-TO-RC25.md) and the previous [RC23 -> RC24 guide](https://github.com/ach1992/bot-refactor/releases/download/v0.10.0-rc.24/RC23-TO-RC24.md) before upgrading older installations.

## Features

- Server and plan management for Marzban and native PasarGuard; shared customer order, renewal and support journeys.
- Administrator-declared physical Panel Groups independent of orders/plans/payment state, with protected consumer-time Native and financial operations.
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
3. For a genuinely empty database, import the schema and apply the guide's ordered sidecars once. **Existing installations use their exact upgrade guide instead; never rerun completed 012-017 or any other already-applied migration.**
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

For upgrades, inventory the actual source, schema, private configuration and active schedules first. Replace the complete runtime together, reconcile retired files, preserve financial and Native journal evidence and active Cron locks, and verify restorable recovery before changing the environment. ZIP extraction never applies SQL. For the current source, use [RC24 -> RC25](docs/post-v9/RC24-TO-RC25.md) and the previous [RC23 -> RC24](https://github.com/ach1992/bot-refactor/releases/download/v0.10.0-rc.24/RC23-TO-RC24.md) guide as needed; older transitions remain linked below. Existing 015/016/017 migrations must never be replayed. After new financial or Native writes, use evidence-preserving reconciliation or operator-approved roll-forward rather than a blind downgrade.

## Documentation

- [RC24 -> RC25 Panel Group independence, journal safety and recovery](docs/post-v9/RC24-TO-RC25.md)
- [RC23 -> RC24 schema and physical-plan migration](https://github.com/ach1992/bot-refactor/releases/download/v0.10.0-rc.24/RC23-TO-RC24.md)
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
