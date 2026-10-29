# AChBot fresh installation

This guide is for a **new bot with a new, empty database**. An existing bot, a copied live database or a partly migrated database must use its exact release's upgrade/recovery guide instead. There is no browser installer or automatic schema upgrade.

Published RC18 and current unreleased source share the setup prerequisites below. Their runtime/configuration behavior is not interchangeable: the simplified display UI, managed invitation gift and root-helper retirement belong to **unreleased source** until a new release is published. Use one exact version throughout; [current scope](NEXT-RELEASE.md) identifies the boundary.

## 1. Prepare the installation

Prepare a dedicated HTTPS bot URL, a private setup directory outside the webroot, and a dedicated MariaDB database/account. PHP web and CLI must have the bot's extensions (`mysqli`/mysqlnd, `curl`, `zip`, `gd`); compatibility CI uses PHP 8.1 and PHP 8.4. The CLI Cron additionally needs `posix_geteuid`, `flock`, `proc_open`, an executable `PHP_BINARY` and Linux `/proc` descriptor information. Choose the actual CLI binary explicitly; it may differ from the web handler.

Create a Telegram bot through BotFather and privately retain its token, username and the Owner's numeric Telegram ID. Use separate identities and databases for separate installations. Keep Cron disabled while preparing the database and files; register the webhook only after that preparation is complete.

Extract **only the verified runtime ZIP** into the chosen bot directory. Keep the matching repository setup checkout, SQL, verification tools, credentials, dumps, backups and Cron logs outside served paths. Configure HTTPS and restrict private/internal files in the webserver; do not publish the complete development checkout. Use permissions appropriate to the actual PHP/Cron accounts, never a blanket `chmod 777`.

## 2. Match and verify the package

Obtain the release's runtime ZIP, manifest and checksum list from [GitHub Releases](https://github.com/ach1992/bot-refactor/releases). GitHub's automatic Source code archive is a setup-source snapshot, not the verified runtime ZIP. Obtain a private repository checkout at the manifest's exact `candidate` commit for schema files and verification tools; do not use latest main with an older runtime.

Verify the trusted checksum list and run the matching verifier from that private checkout:

```sh
python3 tools/package_post_v9.py verify \
  --zip /private/setup/runtime.zip \
  --manifest /private/setup/runtime.zip.manifest.json
```

Expected: `verified:true`, the intended candidate/tree and 115 runtime members. Verify every extracted runtime member against the same manifest using the [installed-runtime verifier](INSTALL-DIAGNOSTICS.md), not just a file count. The runtime ZIP has no `baseInfo.php` or database installer. CI migration sidecars are source files, not instructions to run every attached SQL against an existing tenant.

## 3. Initialize only the new empty database

From the **matching private setup checkout**, have the database administrator create a dedicated empty database with utf8mb4 support. Use an account scoped to that database; schema preparation needs DDL privileges. Do not use a global database administrator credential in the bot's runtime configuration.

Import `database/legacy-schema.sql` exactly once into that empty database, then apply the following matching source files **one at a time in this order**, stopping on any error. This is a full fresh schema for both supported providers:

| Order | Source file | Purpose |
| --- | --- | --- |
| Base | `database/legacy-schema.sql` | Empty historical 23-table baseline, not the completed current schema |
| 1 | `migrations/post-v9/001_marzban_panel_group_id.sql` | Existing server grouping |
| 2 | `migrations/post-v9/003_payment_attempts.sql` | Shared payment ledger |
| 3 | `migrations/post-v9/004_automatic_renewal_attempts.sql` | Renewal attempt evidence |
| 4 | `migrations/post-v9/005_wallet_adjustments.sql` | Wallet movement journal |
| 5 | `migrations/post-v9/006_payment_message_refs.sql` | Payment message references |
| 6 | `migrations/post-v9/007_service_effects.sql` | Verified service effects |
| 7 | `migrations/post-v9/008_referral_awards.sql` | Referral award evidence |
| 8 | `migrations/post-v9/009_bank_buy_invoice_claims.sql` | Bank invoice claims |
| 9 | `migrations/post-v9/011_provider_identity.sql` | Identity prerequisites for012 |
| 10 | `migrations/post-v9/012_shared_provider_storage.sql` | Current shared native storage |
| 11 | `migrations/post-v9/013_remove_obsolete_order_columns.sql` | Retire unused order columns |

For example, the MariaDB client can import the baseline with a prompted password:

```sh
mariadb --user=INSTALL_DB_USER --password NEW_EMPTY_DB < database/legacy-schema.sql
```

Replace the account/database placeholders and repeat the import form for each subsequent table entry. These paths are relative to the private checkout, not the runtime ZIP. The release may name001 `panel-group-migration.sql` and003 `payment-attempt-migration.sql`; use the contents/checksums matching the selected source. Do not use `--force` to ignore errors or a wildcard SQL loop.

Do **not** run historical002, `legacy_rc4_nowpayments_import.sql`, or optional cleanup010 for a fresh installation. Cleanup010 is separate guarded maintenance, not a startup requirement; original unused tables may remain. Do not insert another bot's settings/users or synthetic test fixtures. The ordinary Owner settings journeys initialize the intended configuration.

If a step fails or any expected object already exists, keep the error and inspect the state before continuing. MariaDB DDL can commit independently; replaying the whole sequence is not recovery. Never run this fresh sequence against an existing installation, and never rerun its completed012/013. Existing-data consolidation and paired restore are documented in [shared storage](SHARED-PROVIDER-STORAGE.md).

## 4. Create private per-bot configuration

Copy the selected runtime's `baseInfo.example.php` to `baseInfo.php` **only for this new installation**. Existing installations preserve their own private file. Fill in:

| Field | Required value |
| --- | --- |
| `$botToken` | This bot's private BotFather token |
| `$botUsername` | This bot's username without `@` |
| `$botUrl` | Its absolute HTTPS bot-root URL, without `/bot.php` or a trailing slash |
| `$admin` | Owner's positive numeric Telegram ID |
| `$dbUserName`, `$dbPassword`, `$dbName` | This installation's dedicated MariaDB credentials/database; runtime connects to `localhost` |
| `$telegramWebhookSecret` | A unique random per-bot secret, added manually to the private file |

The example does not provide `$telegramWebhookSecret`. Generate 32 random bytes locally and encode as hexadecimal, then insert the resulting 64-character string **inside PHP, before the closing `?>`**:

```php
$telegramWebhookSecret = 'REPLACE_WITH_YOUR_UNIQUE_64_CHARACTER_HEX_SECRET';
```

The placeholder is not usable. AChBot requires 16–256 ASCII letters, digits, `_` or `-`; no shared PHP environment fallback is read. Keep the secret and token out of public outputs, Git and shared logs. Run PHP syntax checking locally without printing the file. Restrict file access to the accounts that actually need it.

Only primary `$admin` belongs in private staff configuration; use the administrator list for other staff. Use the gateway/channel UI for destinations, credentials and rates. `$joinLink` is an optional retained private-channel join fallback; do not leave the example's fake link as a configured destination. Current unreleased source ignores private `$inviteReward`: configure it after Wallet in gateway/channel settings; unset/invalid/zero disables future invitation gifts. Published RC18 keeps its version's older invitation configuration, so follow its matching example instead of mixing these contracts.

## 5. Register the webhook and configure the bot

Using a trusted private Bot API client, call Telegram [`setWebhook`](https://core.telegram.org/bots/api#setwebhook) for this exact bot:

| Parameter | Value |
| --- | --- |
| `url` | `https://YOUR_HOST/YOUR_BOT_DIRECTORY/bot.php` |
| `secret_token` | Exactly the private `$telegramWebhookSecret` value |

Telegram sends HTTPS POST updates with `X-Telegram-Bot-Api-Secret-Token`. AChBot checks that header before database initialization. Keep the HTTP header intact through any proxy and preserve the actual Telegram source IP for the existing ingress allowlist; do not disable authentication/IP checks to make a proxy work. Ensure delivery includes `message` and `callback_query` updates. Do not casually drop pending updates or share this bot token with another running deployment.

Expected: `setWebhook` succeeds; private `getWebhookInfo` reports the exact intended URL and no continuing delivery error; Owner sends `/start` and receives the ordinary menu. A browser GET or a request without the secret should return 403, not a setup page. If delivery fails, inspect the actual web PHP handler, file provenance and private logs using [installation diagnostics](INSTALL-DIAGNOSTICS.md). Never post token-bearing API URLs or secrets in an Issue.

Before public use, configure:

- Owner's administrator list, intended levels and message-receiving preferences.
- Bot Settings instance messages and explicit config/subscription QR switches; gateway/channel support, reports, membership and optional private invite URL.
- Supported servers and plans. PasarGuard 5.5.1 requires actual Groups/Hosts, scoped API access and an absolute subscription URL prefix before account admission; see its [contract](PASARGUARD-CONTRACT.md).
- Each server's purchase-delivery and order-page display choices. Current unreleased source has subscription/config/auxiliary toggles, ordered exact-name selections (default 3/max 10 inline), purchase-only QR cycling and separate explicit order QR actions; see [presentation](SUBSCRIPTION-PRESENTATION.md).
- Intended payment credentials, destinations, rates and eligibility before enabling a method. Shared `manualUsdtRateToman` covers USDT/NOWPayments; TRX has its own rate. Manual crypto still needs human receipt verification. Current-source invitation gift starts disabled until deliberately set in the same gateway/channel editor.

## 6. Configure Cron and its private lock

First identify the effective OS account that will run Cron and the correct PHP CLI. Provision a private local directory **outside all served webroots**, owned by that exact account with mode 0700. The coordinator does not create this directory and refuses a directory inside the bot installation.

The default is `/var/lib/achbot/cron-locks`. For a **fresh host with verified root-run Cron**, inspect existing paths/owners/symlinks first. Only when these directories are absent and their parents trusted, a system administrator can provision:

```sh
sudo install -d -m 0755 -o root -g root /var/lib/achbot
sudo install -d -m 0700 -o root -g root /var/lib/achbot/cron-locks
sudo stat -c '%U:%G %a %n' /var/lib/achbot /var/lib/achbot/cron-locks
```

Expected lock-directory ownership/mode: `root:root 700`. Do not overwrite or change an unexpected existing path. For non-root Cron, use a separate private directory owned by its user and set `ACHBOT_CRON_LOCK_DIR` in that Cron command. A root-owned 0700 directory cannot be reused by another UID. Do not make it 0777. Use an absolute canonical path without symlink aliases; ancestors must be root- or scheduler-owned, without untrusted write access (a root-owned sticky ancestor is permitted).

After settings/readiness preparation, schedule **one invocation per minute**. In aaPanel select the one-minute schedule and put only the command below in its command field. In a normal crontab, prefix the command with `* * * * *`. Replace paths with this installation's actual paths and pre-create its private writable log directory:

```sh
cd /absolute/bot/path && /absolute/path/to/php /absolute/bot/path/settings/cronRunner.php >> /private/log/path/cron.log 2>&1
```

For a custom lock directory, the command form is:

```sh
cd /absolute/bot/path && ACHBOT_CRON_LOCK_DIR=/private/cron-user/bot-locks /absolute/path/to/php /absolute/bot/path/settings/cronRunner.php >> /private/log/path/cron.log 2>&1
```

The CLI working directory must be that bot's root. Verify the actual scheduled account, PHP extensions/functions and private path/log permissions. A missing/unsafe lock stops before workers with exit 78. Do not run the real runner manually as a harmless readiness check: its workers have service, reward and financial effects.

The coordinator runs four separate processes, in order: `gift2all.php`, admitted `broadcast`, `rewardReport.php`, admitted `services`. Schedule none of those separately alongside it. Old `messagewizwiz.php`, `warnusers.php`, `tronChecker.php` and WeSwap schedules are not part of current runtime. Do not rely on aaPanel's saved `flock` checkbox as evidence that external locking is active; the coordinator enforces its own lease.

**Multiple bots:** same server + same effective OS user may provision the private lock directory once. Each canonical absolute bot directory hashes to its own `achbot-cron-<hash>.lock`, so bots do not block each other's Cron. Different OS users need separate owned directories; a new server needs its own provisioning. An overlapping invocation of the same bot is skipped, not queued. Never delete, rotate or recreate its lock file while any coordinator/child is active. Moving an active installation or changing its lock directory requires a coordinated stop/cutover so old and new paths cannot run together. The lease prevents overlapping batches, not every duplicate financial effect after an uncertain partial operation.

## 7. Verify before customer use

Confirm the exact runtime/manifest, expected schema and intended settings. The [read-only readiness preflight](PAYMENT-READINESS-PREFLIGHT.md) uses a dedicated SELECT-only account and does not load private configuration or invoke workers/providers. It expects configured `BOT_STATES`/`PAYMENT_KEYS` documents and a potentially usable payment method; an untouched new database is not yet payment-ready. Its PASS is local readiness, not proof of a real payment.

On the selected isolated bot, verify Owner access, ordinary-user restrictions, current server/config display, explicit QR actions, cancellation and safe invalid-input behavior. Observe the actual scheduler's private logs and absence of overlapping batches. Follow the [operator acceptance checklist](MARZBAN-OPERATOR-ACCEPTANCE.md) for provider/payment tests with their own controlled accounts and funds. A successful browser payment return is not settlement proof; never automatically retry a transaction or provider mutation after an ambiguous result.

Keep a private installation record: release/source/tree, manifest/checksum, database migration results, PHP web/CLI identities, webhook URL (no token/secret), Cron user/command/lock path, verification results and recovery location. Real source/config/database backups need protected storage and a rehearsed restore; do not publish them in GitHub.

Existing installations must retain financial/native history, active lock files and their private configuration during upgrades. Read the exact transition and use paired source/database recovery after012; do not use this fresh-install sequence as an upgrade or rollback script.
