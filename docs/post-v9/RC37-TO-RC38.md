# RC37 → RC38 — gateway eligibility and gift-card selection label

RC38 is the **source-only** successor to RC37, integrating
[independently approved PR #169](https://github.com/ach1992/bot-refactor/pull/169).
The reviewed candidate was
`7b9a5454cd5d6ed8828f85cdbdc6990af3755f2a`, with exact tree
`7b3c3484783332460ee184d6b20e83f7003509f0`. It was merged
as `978ff715b10f9da63ac6c000c2e62e2ba319bfc4` with that
same tree. [PHP 8.5 CI #43404256057](https://github.com/ach1992/bot-refactor/actions/runs/43404256057)
passed Foundation, all eight Native families, the protected aggregate
and exact-source packaging. Later release-only documents do not modify
reviewed runtime or test files; the final published tag and ZIP manifest
own exact artifact identity.

## Visible interface and permissions

- **Main admin menu:** "🔐 مدیریت دسترسی به روش‌های پرداخت" now appears
  immediately below the payment review inbox. Owner, Manager and Operator
  may enter/manage rules; Support may not. Operator does not gain general
  merchant or payment API settings privileges.
- **Method exemption:** The last action before Back on each payment method
  is "🔓 معاف کاربر از قوانین". Send multiple positive numeric Telegram IDs,
  separated by spaces, commas or newlines; prefix an ID with `-`
  to remove it. Each method keeps its own list. An exemption bypasses
  **only** the method's audience eligibility rules; it cannot enable a
  disabled method, bypass final payment authorization, or authorize an
  ambiguous customer. Existing batch/replay controls remain.
- **Category matching:** "⚖️ اعمال همزمان قوانین" is a toggle immediately
  above the existing disable-rule control. ON requires all enabled age/history
  criteria (AND); OFF accepts at least one (OR). Category membership remains
  mandatory; separately enabled category rules remain OR. An existing rule
  without `match_all` defaults ON/AND.
- **Payment selection:** The `gift_card` option in wallet top-up,
  purchase/custom purchase and day/volume add-on selection menus is exactly
  "🎁 خرید گیفت کارت | ریالی". Callbacks, the separate gift purchase/link
  button, receipt/evidence actions and renewal-price label are unchanged.

Already-sent Telegram keyboards may display the previous text until a
fresh payment selector is shown.

## Persistence and validation

Optional `BOT_STATES.gatewayRuleExemptUsers` is a JSON map from
method to arrays of positive numeric Telegram IDs. **Absent** means
empty exemptions for existing installations. **Explicit JSON null** or
other malformed documents fail closed for financial preview/final checks,
administrator page loading and authenticated mutation, without changing
`BOT_STATES`, `users.step` or the source watermark.
No new table, migration, private PHP setting, Cron or provider API is needed.
Do **not** rerun old migrations solely for RC38.

## Publication vs installation and future verification

1. Verify the [RC38 release](https://github.com/ach1992/bot-refactor/releases/tag/v0.10.0-rc.38),
   all 132 ZIP members, source-bound manifest and `SHA256SUMS`.
   GitHub's automatic repository source archive is **not** the runtime ZIP.
2. Release publication does **not** install/update Vivan, Chili, Hell or any
   other tenant. A separately authorized installation must first establish
   installed file hashes, private config, DB migration state, pending
   finance/Native effects, Cron/webhook ownership and a tested restorable
   backup. No extraction or service reload is implied by ZIP presence.
3. During an approved installed acceptance, use fresh Telegram selectors
   and verify each administrator tier, add/remove exemptions, method OFF,
   AND/OR behavior and pending-payment recovery, without inventing
   settlement results or issuing real provider payments without authority.
4. If rollback is approved and necessary, restore the **verified**
   previous RC37 source files with a tested rollout plan; preserve data,
   existing exemption lists, source/event identities and any financial
   evidence. This update has no migration to reverse.

[Vivan financial reconciliation #157](https://github.com/ach1992/bot-refactor/issues/157),
[Hell migration #161](https://github.com/ach1992/bot-refactor/issues/161)
and wider installed acceptance remain open, separately authorized work.
