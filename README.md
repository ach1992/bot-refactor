# AChBot

**Current stable release:** [Latest stable](https://github.com/ach1992/bot-refactor/releases/latest) · [First Stable baseline v0.10.0](https://github.com/ach1992/bot-refactor/releases/tag/v0.10.0) · [All releases](https://github.com/ach1992/bot-refactor/releases)

AChBot is a Persian-language PHP Telegram bot for VPN subscriptions, native **Marzban** and **PasarGuard** account management, wallets and payments, support, administrator operations and scheduled service maintenance. This is the **first supported stable source baseline**; compatibility with superseded bot implementation versions is no longer a development requirement. Existing customer subscription URLs, Native credentials, active accounts, financial records and recovery safeguards **must remain valid**.

## What is supported

- Provider-aware purchases, subscription views, renewals, native plan changes, extensions, imports and multi-account orders. PasarGuard plan access uses `provider_group_ids`; Marzban retains its inbound/proxy metadata.
- Four staff levels (Owner, Manager, Operator, Support), customer support, agent services, plan/server management and per-instance Telegram text.
- Wallets, hosted gateways and separately configured manual bank, gift-card and crypto payment methods. Optional authenticated bank-SMS and gift-code evidence; human review and durable once-only financial effects are preserved.
- Explicit administrator-defined physical Panel Groups, per-server presentation policies and configurable subscription/QR/auxiliary-link display.
- One per-bot Cron coordinator for notifications, rewards, service maintenance and recoverable financial follow-ups.

Unused legacy X-UI, WeSwap and automatic TRON checker integrations are **not** supported. Future Marzban installations remain supported even though current Hell/Vivan installations use PasarGuard.

## Requirements

- Linux with HTTPS, PHP web/CLI and the PHP extensions `mysqli`/mysqlnd, `curl`, `zip`, `gd`; current source acceptance uses **PHP 8.5**.
- MariaDB, with a **separate database/account per bot** and the full matching current schema. The original `database/legacy-schema.sql` by itself is not the current schema.
- Telegram bot token, numeric Owner ID and a unique `$telegramWebhookSecret` per bot.
- A native Marzban or PasarGuard panel with the required API permissions. [PasarGuard contract](docs/post-v9/PASARGUARD-CONTRACT.md).
- For Cron: Linux `/proc`, PHP CLI `posix_geteuid`, `flock`, `proc_open`, and a trusted external lock directory.

## Installation and upgrade

Use the **official runtime ZIP**, its manifest and SHA256SUMS from the selected GitHub release. The automatically generated GitHub *Source code* ZIP is **not** the deployable runtime. The current package has 132 source-bound runtime members, with no live `baseInfo.php`, customer data, database installer, payment credentials, or Cron configuration.

1. **New empty database:** follow [Fresh installation](docs/post-v9/FRESH-INSTALL.md) for the release-matched schema and migration order, private configuration, webhook, and safe Cron setup.
2. **Existing bot:** compare the installed file hashes, real DB migration state, private settings, pending effects and available rollback before replacing source. Follow [Installed-runtime diagnostics](docs/post-v9/INSTALL-DIAGNOSTICS.md); **never replay completed migrations or the one-time Hell/Vivan PasarGuard cutover**.
3. Populate the private `baseInfo.php` from the matching example only for a new installation. Preserve the existing real private file and encryption-dependent secrets during upgrades.
4. Configure the HTTPS `bot.php` Telegram webhook with the matching secret token. Opening the webhook in a browser does not prove Telegram delivery.
5. Configure one `settings/cronRunner.php` task per minute using the intended PHP CLI and bot working directory. The coordinator owns four sequential jobs and skips an overlapping tick. Never run the live coordinator as a harmless smoke test: workers can perform real service and financial effects.

Each bot has its own database, webhook, private configuration and Cron job. The default Cron lock location is `/var/lib/achbot/cron-locks`. For different OS users, use their separately owned private directories via `ACHBOT_CRON_LOCK_DIR` (mode `0700`), outside served paths. Never delete an active lock file.

**Release != installation:** GitHub publication or merely placing a runtime ZIP in a bot directory does not replace live bot files, run SQL, enable payments/Cron or validate real Native/Telegram transactions. Separate operator authorization and installed acceptance apply.

## Documentation

- [Product contract](docs/PROJECT-SPEC.md) · [Stable-baseline and release boundary](docs/post-v9/STABLE-BASELINE.md)
- [Fresh installation](docs/post-v9/FRESH-INSTALL.md) · [Installed-runtime diagnostics](docs/post-v9/INSTALL-DIAGNOSTICS.md)
- [Provider storage and account identity](docs/post-v9/SHARED-PROVIDER-STORAGE.md) · [Service/plan changes](docs/post-v9/SHARED-SERVICE-TRANSFER.md)
- [PasarGuard API and groups](docs/post-v9/PASARGUARD-CONTRACT.md) · [Unified service management](docs/post-v9/UNIFIED-SERVICE-MANAGEMENT.md)
- [Financial safeguards](docs/post-v9/FINANCIAL-CONSOLIDATION.md) · [Payment recovery](docs/post-v9/PAYMENT-DELIVERY-RECOVERY.md) · [Operator exception inbox](docs/post-v9/AUTOMATIC-GATEWAY-REVIEW.md) · [API evidence](docs/post-v9/API-PAYMENT-EVIDENCE.md)
- [Subscription presentation](docs/post-v9/SUBSCRIPTION-PRESENTATION.md) · [Scheduled renewals](docs/post-v9/AUTOMATIC-RENEWAL-SAFETY.md)
- [Telegram transport diagnostics](docs/post-v9/TELEGRAM-DIAGNOSTICS.md) · [Current CI contract](docs/post-v9/CI-VALIDATION.md) · [Security](docs/SECURITY.md)

Historical V0–V9/RC release notes and obsolete transition instructions are available in **Git history and immutable published Releases**, not duplicated as active instructions. Keep unresolved work in [GitHub Issues](https://github.com/ach1992/bot-refactor/issues), not in release-status paragraphs.

## Development and release integrity

Use `AGENTS.md` and the product contract before changing behavior. PHP runtime changes require relevant isolated regression tests and the fast, current PHP 8.5 `php85-validation` gate; run additional current Stable integration tests for high-risk changes when relevant; pre-Stable suites are retired. Independent review precedes consequential source integration. Test fixtures and packaging never use customer or live provider state.

From a **clean committed source checkout** at the exact intended release commit:

```sh
python3 tools/package_current.py create --output /private/release/achbot-runtime.zip
python3 tools/package_current.py verify \
  --zip /private/release/achbot-runtime.zip \
  --manifest /private/release/achbot-runtime.zip.manifest.json
```

The manifest binds the commit, tree, runtime file digests and ZIP SHA256. Use only the release assets built and verified from the tagged commit. See [CI validation](docs/post-v9/CI-VALIDATION.md).

### GitHub Release notes

GitHub already renders the Release **title** as its main heading. Put the version and concise change summary in that title **once**; start the Release **body** with a short introduction, not a second `# AChBot v…` heading. Organize longer notes using `## What changed`, `## Validation` and `## Deployment notes` only when they contain useful facts. Distinguish published source, migrations (if any), deployed runtime and verified live behavior. Never claim installation or successful customer delivery merely because a Release or CI exists.

## Public root and health

`index.php` is the public information page; `health.php` is a bounded local readiness endpoint, **not** proof of financial settlement, Telegram delivery or provider behavior. Do not expose the source checkout, backups, SQL, credentials, private setup tools or logs through the webroot.
