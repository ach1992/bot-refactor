# AChBot

AChBot is a PHP Telegram bot for VPN subscriptions, plans, customer support, administrator management and scheduled service operations. It supports Marzban and native PasarGuard 5.5.1.

**Release:** [v0.10.0-rc.26](https://github.com/ach1992/bot-refactor/releases/tag/v0.10.0-rc.26), the latest **source-only prerelease**. Download its verified [runtime ZIP](https://github.com/ach1992/bot-refactor/releases/download/v0.10.0-rc.26/achbot-v0.10.0-rc.26-runtime.zip), matching [source manifest](https://github.com/ach1992/bot-refactor/releases/download/v0.10.0-rc.26/achbot-v0.10.0-rc.26-runtime.zip.manifest.json), and [SHA256SUMS](https://github.com/ach1992/bot-refactor/releases/download/v0.10.0-rc.26/SHA256SUMS) together. The runtime has **132 members**, no private configuration, SQL or installer. The release tag pins merged source `390f7ac5cb99285864d019e3ee0ee0b5fad05bec` with reviewed TREE `a9d677eae76794e47ad4ae88b3ae60520a1a1ef0`. [PR #134](https://github.com/ach1992/bot-refactor/pull/134) and [PR #133](https://github.com/ach1992/bot-refactor/pull/133) passed independent exact-candidate review; [full PHP 8.5 CI](https://github.com/ach1992/bot-refactor/actions/runs/42518405396) and [same-TREE post-merge CI](https://github.com/ach1992/bot-refactor/actions/runs/42519667946) succeeded. Publication does **not** certify or deploy installed Chili, real Native, merchant, payment, SQL or Telegram operations.

**New in RC26:** evidence-backed, actionable payment review with no duplicate successful-customer copy; guarded original manual/bank/API/wallet/Hosted purchase recovery for Marzban/PasarGuard; original-entitlement-proven, explicitly authorized paid-account replacement; durable encrypted payment delivery and follow-up journals; and bounded Cron recovery of the **first** never-attempted, already-sealed Telegram packet after a committed payment. Unknown Telegram ACK and uncertain Native CREATE/additive PUT are **never** automatically replayed. See [Issue #117](https://github.com/ach1992/bot-refactor/issues/117), [release scope](docs/post-v9/NEXT-RELEASE.md), [RC25 to RC26 operator guide](docs/post-v9/RC25-TO-RC26.md), and [payment recovery](docs/post-v9/PAYMENT-DELIVERY-RECOVERY.md). Issue #117 remains **OPEN** for installed acceptance, tested restore and actual provider/Telegram verification.

**New in RC25:** an authorized administrator may assign, change or clear a Panel Group for Marzban and PasarGuard regardless of active/historical orders, saved plans, payment state, journals, or differing endpoints and administrator credentials. Group membership is the administrator's declaration of a genuinely shared physical panel and changes **only local Panel Group metadata**. Separate Native account, payment, transfer and plan operations retain their own identity, unresolved-journal and replay guards. See the [RC24 -> RC25 upgrade guide](docs/post-v9/RC24-TO-RC25.md) and [shared service/physical-panel contract](docs/post-v9/SHARED-SERVICE-TRANSFER.md).

**Unreleased successor - Draft PR #136:** The owner opted for a root
cleanup of RC26's experimental recovery workbenches. The successor source
has exactly one on-demand incomplete automatic-gateway payment inbox,
reusing the original payment_attempts record for one-shot financial decisions.
Bank/gift/manual approvals, money and Native uniqueness guards, and the
018/020 core Telegram journals remain intact. The old private workbenches,
specialized paid replacement and their 019/021 future-schema requirements
are removed from this source; **published RC26 remains immutable**.
See [new exception inbox and cleanup contract](docs/post-v9/AUTOMATIC-GATEWAY-REVIEW.md).
**This branch is not independently reviewed, installed or released. Do not
combine the published RC26 runtime with prospective 018/020-only schema.**

**Schema / upgrade:** RC26 introduces four **separate, one-time SQL sidecars**, [018](migrations/post-v9/018_payment_delivery_intents.sql) (delivery journal), [019](https://github.com/ach1992/bot-refactor/blob/v0.10.0-rc.26/migrations/post-v9/019_payment_review_decisions.sql) (human payment decisions), [020](migrations/post-v9/020_payment_followup_intents.sql) (follow-up packets) and [021](https://github.com/ach1992/bot-refactor/blob/v0.10.0-rc.26/migrations/post-v9/021_paid_reconciliation_provenance.sql) (paid replacement provenance and frozen original issued terms). None is inside the runtime ZIP or applied by the GitHub Release. Starting from RC25, inventory real installed source, config, migration history and schema, verify a restorable backup on staging, and audit/apply only genuinely absent steps in strict **018 -> 019 -> 020 -> 021** order under separate target-specific approval. Partial objects, existing conflicting FKs/indexes, missing encryption keys or unknown original Native/payment effects are STOP conditions, not grounds to rerun DDL or retry operations. Earlier 015/016/017 remain one-time historical migrations: never replay them or migrate an unexamined schema. Use the [RC25 -> RC26 guide](docs/post-v9/RC25-TO-RC26.md) and [RC24 -> RC25 guide](docs/post-v9/RC24-TO-RC25.md) where applicable.

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

For upgrades, inventory the installed source, schema, private configuration and active schedules first. Replace the complete runtime together only under an authorized, tested cutover; reconcile retired files, preserve financial/Native journals and active Cron locks, and verify restorable recovery before changing the environment. ZIP extraction never applies SQL. For published RC26, read [RC25 -> RC26](docs/post-v9/RC25-TO-RC26.md); older installations must also follow their preceding [RC24 -> RC25](docs/post-v9/RC24-TO-RC25.md) and earlier sequential guides. Completed 015-021 migrations must never be replayed. After new financial or Native writes, use evidence-preserving reconciliation or operator-approved roll-forward rather than a blind downgrade.

## Documentation

- [RC25 -> RC26 payment/native recovery, forward migrations and operator preflight](docs/post-v9/RC25-TO-RC26.md)
- [Issue #117 immutable payment/delivery and manual reconciliation](docs/post-v9/PAYMENT-DELIVERY-RECOVERY.md)
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
