# Dynamic gateway and customer-category eligibility (Issue #51, PR #82)

Current Wallet-first contract for configuring and evaluating payment-method
access. See [readiness preflight](PAYMENT-READINESS-PREFLIGHT.md) before
authorizing installed tests.

## Scope and data model

Registered payment methods share `achbotCheckoutMethodAllowedLocked()` in
`src/Finance/CheckoutAdmission.php` for the original checkout amount and
`achbotPayUxAudienceAllowedInState()` for current audience and enable flags. The feature uses existing
`setting.type=BOT_STATES`, `users`, `pays` and `orders_list` rows and
existing indexed reads; **no new table, migration, provider network call or
historical backfill**.

- Methods: `nowpayments`, `zarinpal`, `nextpay`, `bank_transfer`,
  `gift_card`, `manual_usdt`, `manual_trx` and `wallet`.
- Categories: `all`, `agents`, `non_agents` (everyone without
  `is_agent=1`), `buyers`, `non_buyers`, `has_active_order`,
  `no_active_order`. The old `off` mode means **no one** rather than a
  matching user category, so an explicitly empty method rule array or
  all-disabled rules deny everyone.
- A method can have one rule per category; multiple enabled category rules
  are OR. Each rule always requires matching its named user category. The
  «اعمال همزمان قوانین» toggle controls the conditions within that
  category: ON means AND (all active age/history criteria), OFF means OR
  (at least one active criterion). With no configured age/history criteria,
  category-only behavior is unchanged. Existing policies without match_all
  default to ON/AND, preserving prior eligibility; the optional boolean
  match_all persists per category when toggled. Unknown transaction reads
  and invalid user identity fail closed in either mode.
- Optional BOT_STATES.gatewayRuleExemptUsers stores a strict per-method map
  of positive numeric Telegram user IDs (e.g. {"bank_transfer":[123,456]}).
  An exemption bypasses only the selected method's audience/category/history
  rules. The method's real ON/OFF flag, unique current user identity and
  all financial/provider controls remain mandatory. An invalid exemption
  document denies issuance rather than silently opening access. A missing
  optional exemption key means an empty list; an explicitly present JSON
  null is invalid and is rejected in previews, final payment authorization,
  administrator pages and authenticated policy writes without advancing
  their source watermark.
- Optional `BOT_STATES.gatewayMaxAmountToman` holds independent method
  amounts, e.g. `{"bank_transfer":500000}`. Positive integral Toman values
  (including decimal strings directly edited in JSON) are inclusive.
  Optional `BOT_STATES.gatewayAmountMode` holds per-method `cap` or
  `fallback`, e.g. `{"bank_transfer":"fallback"}`. Omission defaults to
  the established `cap` behavior, so existing configured ceilings remain
  effective. Invalid explicit amounts/modes deny the affected method;
  a malformed map denies new monetary checkout rather than silently
  bypassing configured policy.
  - Cap: eligible audience (including exempt users) AND amount at or
    below the limit. Missing/null/zero amount means no monetary cap.
  - Fallback: eligible audience (including exempt users) OR a positive
    final invoice amount at or below the explicitly positive threshold.
    Missing/null/zero disables only this alternative. Eligible customers
    have no monetary ceiling in this mode. Empty/paused category rules
    or audience `off` can still admit the explicit amount alternative;
    the real method enable flag remains mandatory. Neither alternative
    rescues malformed active policy, missing/ambiguous identity, or
    other financial/provider/checkout checks. Independently satisfied
    amount eligibility does not need a category/history query.
  Both modes use the final discounted original invoice amount (bulk
  total, recharge amount, renewal or extension), not the randomized
  bank transfer amount, converted currency or individual item price.
  Zero-price service paths remain nonmonetary. Display and first admission
  use the same locked current setting; a prior admitted/issued invoice
  retains original settlement eligibility. Hosted original-link reopens
  keep their existing current audience/flag recheck, including the new
  alternative when applicable; cap mode does not reapply a newly reduced
  ceiling to a previously issued link. Scheduled automatic renewal is a
  separate no-invoice wallet service command under its existing policy,
  not method selection.
- `BOT_STATES.gatewayRules` is optional and versioned:
  `{"version":1,"methods":{"wallet":[{"category":"non_agents","enabled":true,"registration_age_days":90,"metrics":{"buy":{"count":5,"days":90,"direction":"older"},"renew":{"count":0,"days":0,"direction":"older"},"volume":{"count":0,"days":0,"direction":"older"},"time":{"count":0,"days":0,"direction":"older"},"topup":{"count":0,"days":0,"direction":"older"}}}]}}`.
  **The example numbers are not defaults or UI limits.** All count and day
  values are freely entered nonnegative integers, with overflow validation.
- If `gatewayRules` or a method key is **absent**, the existing
  `gatewayAudience` for that method remains authoritative, including explicit
  `off`. A method key with an empty list is deny-all. A malformed explicit
  policy, unknown category/method, duplicate category, invalid number, ambiguous
  user or failed authoritative read fails closed. A newly added rule for a
  previously `off` method requires an explicit administrator action.

A local active-order category requires status=1 and either a future finite expiry or the established zero unlimited-time sentinel. Disabled, negative and expired records do not qualify. Both legacy category settings and dynamic rules use this same local classification in preview and locked final checks; it does not replace fresh native financial/effect verification.

## Exact meanings of dates and counts

The five history dimensions count one original confirmed wallet recharge or completed service command. Retained successful unbound payment rows remain historical evidence. Current checkout rows use the following original invoice types:

| Criterion | Matching `pays.type` |
|---|---|
| Initial purchase | `BUY_SUB` |
| Renewal | `RENEW_ACCOUNT` |
| Volume extension | `INCREASE_VOLUME_*` |
| Time extension | `INCREASE_DAY_*` |
| Wallet recharge | `INCREASE_WALLET` |

Only `paid`, `approved`, `auto_cart_approved` and `paid_with_wallet`
count. Pending, declined, review, unresolved and processing states do not.
A bulk invoice does not become multiple purchases based on the quantity of
subscriptions. For a current linked checkout, a terminal receipt alone is not successful service history: its original matching service operation must be completed. Automatic renewal counts a completed original automatic-renewal service operation without a synthetic invoice; refused, funded-only and cancelled operations do not count. Its original creation time is the request-time anchor. A partially fulfilled BUY resolved with retained accounts counts as one original purchase. The historical `pays.request_date` is the **request creation
timestamp** and must not be called payment settlement or service delivery.
Original `users.date` is Unix registration time as a string.

Evaluation uses current rolling Unix seconds: `days*86400`. For a
**positive-days window**, a request timestamp must be positive and no later
than now; future/nonpositive timestamps are excluded. An **all-history**
`days=0` count instead relies solely on successful state/type/user identity,
including successful historical records with zero or future request timestamps.
The `buyers` and `non_buyers` customer categories use exactly this all-history
BUY_SUB success definition, preserving their original semantics; this matters
for blocking a previous buyer from incorrectly entering the non-buyer cohort.
`count>0,days=0` requires that number of all-time successes;
`count>0,days>0` requires successes recorded on the selected
**older-than or recent** side of the inclusive time cutoff; `count=0,days>0`
requires at least one corresponding successful event in that window; both zero
disable only that metric. Registration age zero disables the age criterion.
The numeric editor does not embed example thresholds.

The same completed BUY history in `ServiceHistory.php` defines discount
buyer/non-buyer audiences. Discount redemption rechecks audience and target
under the definitive owner/draft/discount transaction, not just the earlier
preview. The separate discount stored-order audience retains its existing
meaning; it is not the gateway active-entitlement category.

## Persian Telegram operator path

From gateway settings select **«مدیریت دسترسی به روش‌های پرداخت»**. Choose
a method (availability displayed separately), then a customer category, and
explicitly add/activate the rule. Open its registration-age, purchase, renewal,
volume, time or top-up editor. Every count and day field accepts a free
nonnegative integer via a **new signed private Telegram message**, shows the
pending value without applying it, and requires **Save**. Cancel leaves the
old rule in force; invalid input preserves the wizard for correction.
Direction buttons select older/recent, the metric-disable button clears
count/days to zero, and a category rule can be enabled/paused. Removing a
rule requires a separate signed, nonce-bound confirmation; stale/replayed
confirmations and revoked/non-admin/wrong-chat actions fail closed.

The eligibility menu appears in the main administrator menu below the
operations-attention entry, for Owner, Manager and Operator (not
Support), independently of the Manager-only general gateway/settings menu.
Each method has a monetary amount row and the adjacent «حالت مبلغ» toggle.
The default «سقف برای همه» shows «سقف مبلغ پرداخت» and «بدون محدودیت» when
unset. Toggle to «دسترسی جایگزین» to show «دسترسی جایگزین تا» and «غیرفعال»
when unset. The toggle preserves the stored numeric amount. The page
explains the current interpretation. Open the amount row, enter a
nonnegative whole Toman amount (0 clears the amount condition, not the
mode); numeric input stages it and presents the existing bound Save/Cancel
confirmation. Save commits the exact normalized value and returns to the
current method menu; Cancel preserves the old amount. The separate clear
action removes the amount under the current interpretation. Both fields
share one method-local revision: a stale amount editor, toggle or clear
cannot overwrite a changed amount or mode, but direct edits and admins
working on different methods remain independent. An invalid individual
mode can be repaired to cap via the toggle; a malformed whole map cannot
be overwritten by a per-method action. No schema migration is required. Each payment method has a single «معاف کاربر از قوانین» button immediately
before Back. It opens a signed private input for one or more positive decimal
IDs separated by whitespace or commas; prefixing IDs with - removes them
from the same method's exemption list. Duplicates are ignored, invalid batches
are rejected atomically, and editing a different method never overwrites its
existing list. Completing or cancelling an exemption edit removes the temporary
reply keyboard and returns to that same payment method's rule menu; cancelling
never changes the saved exemptions. The exemption prompt shows only the ten
most recently added persisted IDs, but its count and the effective allowlist
still include every ID. New edits preserve insertion order; historical lists
previously stored in numeric order cannot reveal their original add times.
The category editor places the «اعمال همزمان قوانین» ON/OFF
control immediately above the existing rule enabled/disabled action.

New rules persist atomically with the global gateway `update_id` watermark,
the actor's private wizard step, and other administrators' independent fields.
Wizard save compares the current field against the value seen when editing
began (field-level compare-and-set): two admins editing **different** fields
can both save, while a stale editor cannot overwrite a newer value in the
**same** field. Rule deletion similarly checks the originally previewed
rule fingerprint. Every mutable Telegram control (toggle, time direction,
condition clearing, number editor) carries an expected **rule-state token**
from the UI that actually rendered it, checked against the most recent
persisted rule under the existing writer lock. This means a later Telegram
`update_id` alone cannot authorize an old Clear/Toggle/Direction action;
both current count and days must match the preview before Clear can apply.
On conflict, the administrator gets a Persian error and the current policy
keyboard, without a write. Old unversioned controls fail closed.
The token identifies a state snapshot, not a bearer-authentication secret:
webhook validation, actor identity and current permissions still apply.
The existing named settings writer lock plus current administrator row-PK
lock prevent lost updates and cached-privilege bypass. The visible preview is only
advisory; actual authorization re-reads the persisted `BOT_STATES` with an
indexed customer/transaction current read under the existing owner/payment
locks. A global gateway OFF flag remains OFF regardless of eligibility:
NOWPayments still has separate wallet-topup and other switches; USDT and TRX
remain independently eligible under their shared manual-crypto switch.

Rule changes affect only **NEW** payment-method selection and checkout
issuance. Prior verified or uncertain paid claims, manual receipts, wallet
effects and external service states remain evidence for recovery, never
retroactively cancelled or refunded.

## Verification

The current gate covers real audience-rule evaluation, live-setting admission
boundaries, permission and stale/replay rejection. See [CI validation](CI-VALIDATION.md).
New methods register their own configuration/proof in the shared adapter
boundary; there is no second eligibility engine.
