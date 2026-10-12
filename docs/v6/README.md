# AChBot V6 — VPN panel and subscription/account compatibility package

**Development candidate, not an accepted release.** This cohesive Phase 1 increment is governed by [Issue #16](https://github.com/ach1992/bot-refactor/issues/16). The owner explicitly allowed **isolated V6 development before the outstanding V5 operator smoke result**; the gate remains open in Issue #14. No approval for merge, public release, production installation, real panel/database/Cron/Telegram mutation or legacy behavior fix follows from that waiver.

## Complete domain extraction

- `src/Panel/Accounts.php`: 31 original Sanaei / Alireza / Marzban and generic panel/account operations extracted byte-for-byte as namespaced implementations behind same global `config.php` functions, signatures and include-time behavior.
- `src/Subscription/AccountDetails.php`: nine original existing account/order/detail SQL, traffic and presentation functions, with global call-compatible facades. Existing `src/Subscription/Presentation.php` is reused unmodified, not duplicated.
- `src/Subscription/{ExternalAccounts,ManualAccounts,OrderManagement,ConfigRefresh,CustomerRoutes,SubLinkSettings,AccountTransfers,AccountDeletion}.php`: eight original ordered `bot.php` route segments, preserving callback literals, `return`/`exit`, SQL, shared variables and original route position. Account deletion remains an `elseif` dependency of the historical gift wizard through an original-equivalent `else` include; account transfer's duplicate callback routing and commented blocks remain unchanged.
- `settings/subLink.php` remains **exactly the original public HTTP entrypoint**, including token parsing, error output, panel sync, remark/link database update and encoded body. `baseInfo.php` is neither distributed nor modified.

**One necessary path relocation adjustment:** the legacy QR refresh callback's `include_once 'phpqrcode/qrlib.php'` must become `include_once dirname(__DIR__, 2) . '/phpqrcode/qrlib.php'` inside `src/Subscription/ConfigRefresh.php`. Otherwise the QR handler fails when PHP's working directory differs from the bot root. The source contract allows **only this single path-anchoring exception** to the original V5 route bytes, characterized with a real PHP non-root-CWD old/new callback and a deliberate negative regression control. Original public QR helper code and generated filenames are unchanged.

The full runtime ZIP is **53 members** (original 43 plus ten new guarded modules), carrying all old entrypoint/asset filenames, without private config, DDL/data, tests, logs, customer files, or backups. Keep byte-unchanged all payment, purchase, wallet, sensitive Cron, warnings, gift and financial consumers.

## Evidence and boundaries

See [TESTING.md](TESTING.md) for pinned V5 extraction, complete frozen V0–V5 validation, 23-table MariaDB, native PHP/cURL and synthetic provider/Telegram parity, operator-safe HTTP, negative controls, swap/rollback and exact Git blob package verification. [OPERATIONS.md](OPERATIONS.md) is a runbook only, **not authorization to install**. [LEGACY-BEHAVIOR.md](LEGACY-BEHAVIOR.md) identifies inherited flaws and unverified production behavior.

Do not treat synthetic native acceptance, a green CI, a ZIP or reviewer approval as human operator acceptance. Required sequence remains stable V6 candidate → exact-HEAD CI/ZIP → one fresh author-separated HIGH_ASSURANCE review → separate owner merge/release/deploy gates → controlled operator acceptance.
