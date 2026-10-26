# AChBot

AChBot is a PHP Telegram bot for managing VPN subscriptions through **Marzban**. It includes customer subscription and account flows, administrator server/plan management, support and scheduled operations. The current source is **Marzban-only**; the retired X-UI/Sanaei/Alireza/legacy normal panel adapters are not supported.

> **Release status:** Consult [GitHub Releases](https://github.com/ach1992/bot-refactor/releases) for the latest **Marzban-only source-only prerelease** and exact artifact identity. Earlier [v0.10.0-rc.2](https://github.com/ach1992/bot-refactor/releases/tag/v0.10.0-rc.2) was a security-only `config.php` hotfix to [RC1](https://github.com/ach1992/bot-refactor/releases/tag/v0.10.0-rc.1); historical V9 remains at [v0.9.0-rc.1](https://github.com/ach1992/bot-refactor/releases/tag/v0.9.0-rc.1). **Full installed/operator and financial acceptance is incomplete; source-only prereleases are not production-certified.** A ZIP replacement is neither atomic nor proof of active running code. Preserve local configuration and require separate deployment authorization.

## Requirements

- PHP with the extensions used by the bot, including `mysqli`, `curl`, `zip` and `gd`. Automated compatibility checks run on **PHP 8.1 and 8.4**.
- MariaDB with the existing bot's **23-table schema** and its application data.
- A Telegram bot/webhook, a Marzban backend, and an appropriately isolated test deployment.
- **Merged source contract ([PR #79](https://github.com/ach1992/bot-refactor/pull/79), 2026-10-26; not deployed):** all `bot.php` Telegram webhooks require a valid `POST` and `X-Telegram-Bot-Api-Secret-Token` matching the PHP process `ACHBOT_TELEGRAM_WEBHOOK_SECRET` (16–256 Telegram-compatible characters) **before `config.php` initializes**. Without operator provisioning, the merged source fails closed for *all* bot updates, not only gifts. Source CI uses a synthetic secret; do **not** change installed webhook configuration without separate authorization. See the [Direct Gift recovery and moderation policy](docs/post-v9/DIRECT-GIFT-RECOVERY.md).
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

**Unreleased service-edit correctness work:** The developing source uses a
provider-neutral account-edit boundary with strict panel readback, bounded
correction and financial completion only after service + SQL verification.
This is not support for a new panel or payment gateway: active support remains
Marzban-only, with separate installed/release approval required. See the
[financial consolidation document](docs/post-v9/FINANCIAL-CONSOLIDATION.md)
and [operator checklist](docs/post-v9/MARZBAN-OPERATOR-ACCEPTANCE.md).
Uncertain effects after worker termination may still need reconciliation;
never repeat payment or remote edits blindly.

**Finance source and migration status (2026-10-17):** the immutable published **v0.10.0-rc.4** included the historical NOWPayments-only migration 002. [Merged PR #44](https://github.com/ach1992/bot-refactor/pull/44) supersedes that design in the current source with unprefixed shared `payment_attempts` and `payment_transactions` tables for NOWPayments, NextPay and Zarinpal. Consult [GitHub Releases](https://github.com/ach1992/bot-refactor/releases) for the exact source-only package: merging or publishing does **not** migrate any database. The new migration is [003](migrations/post-v9/003_payment_attempts.sql); verify its exact schema state and obtain separate Owner authorization before applying it to ANY other target. On the Owner-authorized Chili test installation, 003 was explicitly applied only to `chilibot` (23 to 25 tables), while RC5 runtime was installed and the Owner reported basic bot operation. No live financial settlement or independent backup restoration was verified. See [Issue #26](https://github.com/ach1992/bot-refactor/issues/26#issuecomment-6225581330). **Never run RC4 migration 002 merely to prepare 003.** If RC4 tables were already applied, follow the separately controlled [non-destructive import route](docs/post-v9/PAYMENT-LEDGER-MIGRATION.md); do not delete old rows. Private NOWPayments IPN signing, optional stricter merchant payout floors, actual Cron reachability, operator acceptance and live installation remain separate gates; ordinary payout conversion/network fees follow the Owner-approved default without extra net-floor configuration.

**Administrator wallet journal (source merged 2026-10-18):** [PR #58](https://github.com/ach1992/bot-refactor/pull/58) merged audited admin credit, routine debit, and explicit privileged debt into `main`. This source requires separately gated [migration 005](migrations/post-v9/005_admin_wallet_adjustments.sql), which the merge **did not apply** to any installed database. Check the [administrator wallet contract, preflight and restore requirements](docs/post-v9/ADMIN-WALLET-ADJUSTMENTS.md) before any separately authorized rollout. Neither merging nor a source ZIP activates or proves real-money behavior.

**Manual payment configuration (merged source in PR #54 on 2026-10-19; not deployed):** Gateway settings independently control bank transfer, purchased gift cards and a **manual crypto** method. Manual crypto presents up to four separately configured receiving destinations: **USDT BEP20** (`manualUsdtWalletBEP20`), **USDT TRC20** (`manualUsdtWalletTRC20`), **USDT ERC20** (`manualUsdtWalletERC20`), and native **TRX on TRON** (`manualTrxWallet`). USDT uses the configured `manualUsdtRateToman` or a validated existing rate; TRX requires its **own configured `manualTrxRateToman`**. Only valid destination/rate options appear (TRON/TRC20 addresses require canonical offline Base58Check, not just a T-shaped string), and administrators can clear any new address or rate via `/clear`. Checksum validation is not proof of wallet control or funds receipt. Prior one-network USDT configuration is accepted only as a compatible legacy fallback; the old standalone TRON TXID gateway and automated chain checker are removed from the current runtime/package, **not** used for new TRX transfers. Each manual crypto selection pins currency, network, converted amount, recipient and rate in the existing shared payment-attempt ledger. The customer submits a screenshot; **only independent administrator verification**, not a screenshot or transaction ID, may authorize the guarded wallet/service effect. The original gift-card seller HTTPS URL may include a fragment like `#giftcard-products` and remains unchanged on save. No chain watcher, blockchain RPC, automatic crypto credit or on-chain confirmation is active now; any future automation requires a separately approved verifier and independent review. Source integration alone does not authorize a Release, SQL migration or Chili deployment. See [financial lifecycle and safety notes](docs/post-v9/FINANCIAL-CONSOLIDATION.md) and [Issue #51](https://github.com/ach1992/bot-refactor/issues/51).


## Optional single-entry Cron runner (operator-managed activation)

The source provides **`settings/cronRunner.php`** as a CLI-only coordinator for the four supported scheduled scripts (`gift2all.php`, `messagewizwiz.php`, `rewardReport.php`, `warnusers.php`), executed sequentially in their current order and **as separate PHP processes**. It preserves their filenames, business effects, working directory and independent PHP lifetimes; errors are written to the operator Cron log and later workers still run.

**One Cron, one active run:** the runner acquires a per-installation, nonblocking kernel `flock` **before the first worker** and retains it through the final child. An overlapping minute is **skipped** (not queued or retried). The empty lock file remains on disk, and the kernel lease survives a coordinator crash while an orphan child still runs. This protects concurrent batches, **not global exactly-once financial settlement** after an uncertain partial effect.

**Secure lock directory is a required operator preflight, not an auto-created file under the website.** The default is the dedicated `/var/lib/achbot/cron-locks` (not created by the application; check its current state before provisioning). Before an **authorized** cutover, inspect and prepare this directory **outside all served webroots**: it must be owned by the effective Cron user and be `0700` (e.g. for the observed root-owned aaPanel job, `install -d -m 0700 -o root -g root /var/lib/achbot/cron-locks` when provisioned safely). For non-root Cron, set `ACHBOT_CRON_LOCK_DIR` to a separately pre-provisioned private directory owned by that account. Specify an absolute canonical path without directory symlink aliases, on a local filesystem. Parent directories must be trusted/root- or scheduler-owned; a root-owned sticky ancestor is permitted, but a world/group-writable **lock directory itself is never accepted**, even with sticky bit. The selected CLI PHP must support the `posix_geteuid` and `flock` functions. A missing or unsafe lock fails closed with exit 78 before starting workers; no fallback to `/run/lock` or `/tmp`. An existing regular lock is validated before a read-only/nonblocking open; first creation is exclusive, and existing/dangling symlinks or FIFOs are rejected without opening them.

### Reusing the private Cron lock directory on other installations

`/var/lib/achbot/cron-locks` is **one host-level private lock directory**, not a path inside each bot's ZIP or web-served folder. When multiple bots on the **same server** run Cron under the **same effective OS user**, provision it **once**. The runner hashes each installation's *canonical absolute bot directory* into a distinct `achbot-cron-<hash>.lock` filename, so separate real installation paths have independent locks. Do not delete, rotate or recreate an in-use lock file; that can defeat single-flight protection. A copy on a **new server** needs its own directory provisioning.

**Fresh-host root Cron example (aaPanel):** first verify that the real scheduled command runs as root and inspect any existing paths, owners and symlinks; never blindly change or replace an unexpected existing directory. Then, on a trusted host where these directories are missing, provision them **outside the document root**:

```sh
sudo install -d -m 0755 -o root -g root /var/lib/achbot
sudo install -d -m 0700 -o root -g root /var/lib/achbot/cron-locks
sudo stat -c '%U:%G %a %n' /var/lib/achbot /var/lib/achbot/cron-locks
```

Expected lock-directory ownership and mode for **root-run Cron**: `root:root 700`. For a different Cron account, provision a **separate** private `0700` directory owned by that exact effective UID and configure its **absolute canonical** path using `ACHBOT_CRON_LOCK_DIR` in that Cron command. A root-owned `0700` directory is **not usable** by a non-root Cron account; never weaken permissions to `0777` or share a writable lock directory among different UIDs. Verify the interpreter's `posix_geteuid`/`flock` support and file-system permissions **as the scheduled user**, without manually running the real workers.

Copying the bot folder alone does not configure a new bot: use a separate private `baseInfo.php`, Telegram token/webhook, database and Cron task per installation. Keep the web-served runner path `<bot-root>/settings/cronRunner.php` and its four supported workers unchanged; the lock path is intentionally host-local. Follow the coordinated Cron replacement and rollback rules below.

**Operational cutover is separately authorized:** first inventory the installed schedule and outstanding supported-provider payments. The current source package removes old automatic TRON files, including `settings/tronChecker.php`. Verify that any independently configured old TRON Cron schedule is absent or is explicitly removed during the authorized cutover; never reactivate automatic TRON settlement through rollback. Under a separately authorized cutover, replace the four still-supported worker commands with **only one** `php /absolute/bot/path/settings/cronRunner.php` call, preserving the one-minute schedule, interpreter, CWD, environment and external logging. Never run both old and new commands simultaneously. The inspected aaPanel implementation **stores `flock=1` but does not enforce it** because the scheduling code is commented out; do not rely on that field. Verify the actual lock directory, active job and logged scheduler effects before activation. **Rollback coordinates source and schedule:** an older 92-member runtime without the runner/self-lock requires restoring only the four approved non-TRON worker commands **under one independently verified external `flock -n` guard** with matching interpreter/CWD/environment/cadence/logging. Neither CI nor source publication activates the runner or proves real financial/Cron acceptance. No DB migration is associated with this source change.

Run its harmless native synthetic regression independently using `python3 tools/test_post_v9_cron_runner.py`; it runs disposable fake child PHP workers and an owned loopback HTTP denial test, never the real four supported worker scripts.

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

These tests and archives do **not** certify live payment settlement, full database recovery, Telegram webhooks, real Marzban provisioning or production deployment. The Owner previously reported an RC3 agency-decline smoke check, and on 2026-10-17 the **Chili test bot** was independently verified on-disk at **RC5** with its `chilibot`-only shared ledger migration 003 applied and a basic Owner-reported successful bot smoke. Those bounded checks do not establish live payment/merchant settlement, end-to-end Cron operation, general deployment safety, or any other tenant readiness. [PR #46](https://github.com/ach1992/bot-refactor/pull/46) introduced the optional Cron runner now included in RC7; the Chili operator subsequently installed and scheduled it, while full Cron and financial acceptance remain open under [Issue #14](https://github.com/ach1992/bot-refactor/issues/14). For NOWPayments, a hosted browser return is not proof of payment; only verified provider `finished` can be eligible for settlement, subject to identity/amount and effect-idempotency checks still under development. The inherited financial/settlement risks are tracked in [Issue #26](https://github.com/ach1992/bot-refactor/issues/26).

## Documentation

- [Post-V9 Marzban behavior and validation](docs/post-v9/MARZBAN-ONLY.md)
- [Financial consolidation, NOWPayments finality and migration gates](docs/post-v9/FINANCIAL-CONSOLIDATION.md)
- [Payment UX lifecycle: old message buttons, 30-minute untouched drafts, optional migration 006 and review-safe invoices](docs/post-v9/PAYMENT-UX-LIFECYCLE.md)
- [Automatic renewal: source-only durable 004 journal, deferred operator activation and reconciliation](docs/post-v9/AUTOMATIC-RENEWAL-SAFETY.md)
- [Shared payment ledger: migration, old RC4 data and recovery](docs/post-v9/PAYMENT-LEDGER-MIGRATION.md)
- [NOWPayments unattended IPN, checkout recovery and additive migration](docs/post-v9/NOWPAYMENTS-MIGRATION.md)
- [Schema migration and rollback precautions](docs/post-v9/PANEL-GROUPS-MIGRATION.md)
- [Direct administrator gift: source claims, webhook authentication and reconciliation](docs/post-v9/DIRECT-GIFT-RECOVERY.md)
- [Operator acceptance checklist](docs/post-v9/MARZBAN-OPERATOR-ACCEPTANCE.md)
- [Project specification and historical V0–V9 contract](docs/PROJECT-SPEC.md)
- [Historical V9 operator notes](docs/v9/OPERATIONS.md)
- [Public repository security guidance](docs/SECURITY.md)
- [Source-only releases](https://github.com/ach1992/bot-refactor/releases)

The published V0–V9 history is preserved separately from the intentionally changed post-V9 Marzban-only runtime. The older `wizwiz` callback and scheduled-entrypoint identifiers may still appear for compatibility.
