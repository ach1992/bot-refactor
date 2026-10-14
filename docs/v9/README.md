# AChBot V9 — final Phase 1 global source compatibility

**Authority:** [Issue #27](https://github.com/ach1992/bot-refactor/issues/27).
**Immutable reviewed previous source:** [V8 RC1](https://github.com/ach1992/bot-refactor/releases/tag/v0.8.0-rc.1) at merge SHA
`9ca6cabe43121a528db8d32c9b4aa1b5d10168ea`
(TREE `434f2d7aac6f23e0afb16b320782641aab81a6e0`).

V9 is the **last Phase 1 source integration/acceptance pass**, not a new
financial feature or database/configuration migration. The V8 runtime
already consists of 92 tracked source members, including 27 financial
modules, original public gateway/health/webhook/Cron entrypoints and the
original 23-table MariaDB schema. The initial V9 candidate **does not
change any of those 92 runtime members**: that is a deliberate safety
result of the final integration review, not a claim that real installation,
provider settlement or end-user behavior was tested.

## Global compatibility audit

- All 92 tracked runtime members are checked against Git at the merged V8
  baseline, including all 12 selected public PHP entrypoints, all 27
  financial modules and the original database DDL.
- Full real PHP `config.php` loading is characterized in an **owned**
  23-table MariaDB, with synthetic `baseInfo.php`, 40 global legacy
  facade signatures and seven owned loopback HTTP provider requests in
  each of two execution contexts (application root and foreign CWD).
  The old V8 merged source and new V9 source run against independently
  initialized but byte-equal original-schema DBs, comparing **all rows
  in all 23 tables**, recorded output/warnings and provider request traces.
- A separate physical V8 → V9 → V8 → V9 source overlay compares
  the actual 92-member file inventory, all public entrypoint PHP syntax,
  private-file sentinel hashes, 23-table DDL and persisted synthetic rows
  without deleting unknown files or restoring a DB snapshot. This
  stage exercises only `php -l` for the entrypoints, not financial processing.
- The pre-existing V8 rollback suite is independently rerun in the V9
  full acceptance gate and contains the **real native synthetic paid order**
  with order-linked coupon and renewal rows across four V7/V8 versions.
  It is not incorrectly represented as a newly exercised paid transaction
  during V9's separate static source overlay.
- A full V9 gate includes **unchanged V8 finance and full V7 → V0–V6.1
  historical regression** on actual PHP 8.1 and PHP 8.4 in CI.

## baseInfo.php: review last, no migration chosen

Real `baseInfo.php` is **untracked and never packaged**. The only review
source is the tracked, fake `baseInfo.example.php`: its variable names,
shape and bytes are pinned, including `botToken`, database credentials,
bot URL and administrator fields. Disposable tests construct a private
fixture by appending fake overrides (e.g., `bot.invalid`) under an
owned temporary directory; they do **not** access real secrets or operate
against a production bot or database.

The existing configuration structure suffices for this compatibility
increment. No breaking config change is evidenced or authorized, so
**none is introduced**. A later breaking config format or real
migration requires a distinct owner decision.

## Known limits and outstanding operations

Phase 1 source parity does **not** imply wallet correctness under all
parallel schedules, real chain finality, real gateway acceptance, VPN
panel/Telegram delivery or installed aaPanel/Cron readiness. The
[inherited finance findings](https://github.com/ach1992/bot-refactor/issues/26)
(TRON check/schema/TLS, wallet debit, NextPay cancellation and replay/
partial-settlement) are **not silently changed**.

V5/V6/V7 full installed operator acceptance and V6.1 actual isolated
MariaDB restore remain **NOT TESTED**; the owner's informal V8
looks-good feedback does not change them. Separate Owner approval and
environment acceptance are required before integration, release and
real deployment.

See [testing](TESTING.md) and [safe source operations](OPERATIONS.md).
