# RC33 → RC34 — Vivan wallet-choice recovery and payment-policy administration

**Status: [RC34 source-only prerelease published](https://github.com/ach1992/bot-refactor/releases/tag/v0.10.0-rc.34); NOT installed.**
The previous release was [RC33](https://github.com/ach1992/bot-refactor/releases/tag/v0.10.0-rc.33).
This guide covers the independently approved, merged
[PR #159](https://github.com/ach1992/bot-refactor/pull/159) and
[Owner-approved Issue #158](https://github.com/ach1992/bot-refactor/issues/158).
The approved runtime was merged into `main@151f411711076b7789dd7be6fb2456efd4d12b88`
with unchanged reviewed Git tree `60878fb35588a76351b2916f9fa9b4c5bf06c7eb`.
This source release is **not** permission to install or mutate Vivan, Chili or Hell.

## Behavior changed in source

- Insufficient wallet balance never *claims* or consumes an unpaid purchase,
  custom purchase, renewal, day extension or volume extension. The draft stays
  payable through the other enabled methods without an extra wallet debit or
  external Native effect.
- **Historical low_wallet remediation:** Renewal's authenticated
  `prePayCheck_*` callback and the other four payment families' **actual
  authenticated Telegram** wallet, bank, gift or manual-crypto choice callbacks
  now reach the same owner-first transactional rearm check. A valid older
  `low_wallet` quote becomes `pending` for that same user only while its
  original draft deadline is still live, with no later quote, issued provider
  reference, 003 payment attempt/transaction, 007 service effect or conflicting
  Native plan-change journal. A repeated click never creates another invoice
  claim by itself; normal final issuer checks still apply. The original
  alternative hosted link can then be used without making a new quote.
  **Public `pay/checkout.php` GET never performs this recovery.** If the
  original Telegram buttons are unavailable, payment methods were disabled,
  the draft has expired or the evidence/journal is ambiguous, the user must
  start a **new** eligible quote through the ordinary bot flow; never replay
  an old provider invoice or presume a refund.
- The existing Telegram administrator policy engine and stored
  `BOT_STATES.gatewayRules` schema/authorization semantics are unchanged.
  Category screens prioritize active conditions; metric screens provide simple
  examples, quick 1/7/30-day presets, direct manual controls, and per-metric
  navigation after saves. The existing source-authenticated admin permissions,
  stale-button CAS, 64-byte callback length and method policy checks remain.
- The Owner explicitly approved the visible copy changes: 🎫 numeric customer
  identifiers; 🔄 subscription usernames; **شناسه تغییر دهنده** for the actor
  in service-change logs; icons in the renewal administrator report; wallet
  top-up buttons show numbers and **تومان** without **مبلغ** or money emoji;
  one continuous 💡 subscription-link hint; consistent **↪️ بازگشت** navigation.

No new DDL, SQL migration, Cron, provider API, payment engine, runtime file or
third-party dependency is introduced by this candidate. The existing expected
source ZIP manifest remains 132 runtime files and must exclude tenant config,
SQL backups and payment/user data.

## Acceptance and shipping boundary

Native acceptance includes the actual low-wallet Telegram route and
alternative synthetic hosted checkout for purchase/custom/renewal/day/volume,
plus foreign-owner, old/ambiguous provider, Native-journal, expired,
superseded and repeated-click negative evidence. The historical ledger is
never rewritten automatically by migration or an unauthenticated browser.

**Source acceptance complete:** The exact reviewed candidate
`d1b08da685f6f642f6e344da0f4f77587550f9d1` received independent
HIGH_ASSURANCE `APPROVE` with no findings. The [PHP 8.5 CI run #43335158774](https://github.com/ach1992/bot-refactor/actions/runs/43335158774)
passed Foundation, all eight Native families (including 28 real-route recovery
cases) and protected aggregate. The accepted candidate's runtime tree matched
the merge. The subsequent release-only README/docs commit changes no runtime
source and does not require a new behavior review; its source package must still
be built and SHA/manifest-verified from the exact tagged commit. Neither CI nor
publication verifies any installed Telegram, payment or Native service effect.

The Vivan production installation currently receives Telegram at
`https://host.hellservice.top/vivan/bot.php`, uses `botvivan`, and reuses
the existing Vivan Cron. The one-time migration from `/order` is already
complete; **do not rerun migration**, reset financial journals, reactivate
the old Cron or write to other bots. The old and new Vivan databases are
stateful, and historical provider links can still produce late payment evidence.

A future live Vivan installation needs its **own explicit, target-specific
Owner authorization** under MCP Gateway production guidance. Before installing,
verify the installed version, all private-config dependencies, current schema
and payment/service journals, a restorable files+DB/config backup and an
appropriate rollback/roll-forward plan. Stop duplicate external provider and
Native effects by reconciliation, not by overwriting or replaying old rows.
Install the complete approved package atomically while preserving
`baseInfo.php`, Webhook and existing Cron; then compare installed hashes to the
RC34 manifest and smoke-check the real insufficient-wallet recovery and
multi-rule admin workflow. The current PR's synthetic tests are not real
provider settlement or live Telegram acceptance.

Repository release publication, ZIP-only staging, and live installation are
**three separate effects and approvals**. Do not carry over the RC33 Chili
ZIP-staging permission to Vivan, Chili, Hell or any other directory.

Track financial/provider and migration follow-up under
[Vivan Issue #157](https://github.com/ach1992/bot-refactor/issues/157)
and candidate acceptance under [#158](https://github.com/ach1992/bot-refactor/issues/158).
