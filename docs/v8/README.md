# V8 — source-pinned financial lifecycle extraction

**Controlling authority:** [Issue #24](https://github.com/ach1992/bot-refactor/issues/24).
**Pinned V7 baseline:** `26ba693242241c248b9f53a33153a2bb3c2d4bd0`
(tree `3ab1cc5632846de1f1b1d1ccdc480255a6d4e78a`).
**Boundary:** Phase 1 source-only refactor. Neither merge nor source
release nor live installation is granted by this development work.

## Implementation layout and compatibility

The original eight files are retained as real, same-named entrypoints/facades:

- `bot.php`: exact-position loaders for financial Telegram callbacks,
  admin wallet operations, old inline keyboards, checkout and renewals.
- `buy_helpers.php`: eager global PHP financial function facades and old
  helper include order; the exposed signatures are unchanged.
- `config.php`: original eager function visibility for
  `startWalletPayment`, `NOWPayments`, agent/discount functions.
- `pay/index.php`, `pay/back.php`: same published gateway paths, return
  behavior, callback URLs, HTML and request order.
- `settings/rewardReport.php`, `settings/tronChecker.php`,
  `settings/warnusers.php`: same Cron filenames and existing preceding
  processing (including backups). The V8 extraction does **not**
  schedule, execute or change a real Cron.

The original financial statements reside in 27 guarded internal modules
under `src/Finance/`, including `Routes/`, gateway initiation/return,
wallet settlement, discounts/referral, crypto checking, automatic receipts
and auto-renewal. `tools/v8_contract.py` reproducibly pins every moved
byte span to the integrated V7 Git tree, validates each public loader and
module, and refuses unrelated runtime PHP files or DDL changes.

The only mechanically authorized adaptations are relocated `__DIR__`
semantics, two entrypoint-root fallback corrections for original QR
`include_once` paths (without overriding cwd/include_path precedence),
and the 26 former `goto GOTOSTART` jumps: their internal-module
return sentinel is forwarded back to the **unchanged label in the original
caller**, preserving the legacy control-flow boundary. No global facade,
callback ID, financial formula, price, SQL state, refund or transaction
policy was redesigned. V6/V7 non-financial routes are untouched.
This establishes a compatibility seam, not a new clean financial domain
API or exactly-once guarantee.

## Inherited risks: business-policy decisions, not stealth fixes

1. **TRON legacy financial validation is unsafe.** The existing checker
   assigns rather than compares the `contractRet` success flag
   (`$success = "SUCCESS" && isset($success)`). It also reads
   `tron_price` from `pays`, although the pinned 23-table schema
   does not declare that column, and disables TLS verification in its
   original cURL caller. Corrective transaction-validation and schema
   policy require a distinct owner-approved change.
2. **Administrator wallet debit** uses direct arithmetic SQL without
   positivity or lower-bound validation; the legacy route may create
   negative balances. Do not present extraction parity as validation of
   this pre-existing business authorization policy.
3. **Concurrent settlement and webhook retries:** card auto-approval
   reads pending payments before updating them and can be invoked by
   overlapping schedulers. Gateway callbacks similarly use historical
   state checks without a newly introduced globally durable transaction
   claim/unique idempotency key. Serialized callback replay and wallet
   transfer row-lock races are tested, but do not guarantee exactly-once
   behavior for every concurrent provider/Cron combination.
4. **NextPay failed verification remains pending (inherited):**
   original return code locates a payment by `payid = trans_id` but uses
   `hash_id = trans_id` in the failed-verification UPDATE, so a rejected
   callback may leave a pending row; isolated original-V7 vs V8 tests
   characterize this, not authorize a correction.
5. **Partial effects across payment and external provisioning:** original
   code can transition a payment before contacting a VPN panel and may
   credit a wallet on panel failure. The refactor deliberately retains
   the original order, handling and error messages. Provider/service
   outages and rollback must be treated as financial operational risk.

These are **legacy observations / risks**, not newly introduced
refactor regressions, and not permission to change amounts or customer
state. The inherited safety work is tracked in [Issue #26](https://github.com/ach1992/bot-refactor/issues/26),
separately from the behavior-preserving V8 acceptance in Issue #24.
Record an explicit owner decision and distinct implementation authority
before altering financial behavior.

## Boundaries and downstream work

The schema remains exactly 23 MariaDB tables with no migration; original
`baseInfo.php`, production credentials, records, backups and installation
remain strictly excluded. V9 integration cleanup and possible
`baseInfo.php` consideration occur **last**, with separate scope. V5 and
V6 operator acceptance, V7 installed acceptance and V6.1 full restore are
NOT TESTED; they are not made PASS by source extraction.

See [tests and evidence](TESTING.md) and
[source-only operator protections](OPERATIONS.md).
