# Dynamic gateway and customer-category eligibility (Issue #51, PR #82)

**Status:** source-only development candidate; independently reviewed, merged,
released, installed and live paid/provider smoke tests are distinct gates.

## Scope and data model

The existing eight payment methods share the **same protected authorization
entrypoint** `achbotPayUxAudienceAllowed()` in
`src/Finance/PaymentLifecycle.php`. The feature uses existing
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
- A method can have one rule per category; multiple enabled rules are OR.
  Within each rule, the category, optional minimum registration age and
  each enabled history criterion are AND.
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

## Exact meanings of dates and counts

The five history dimensions query **one row per successful pays transaction**:

| Criterion | Matching `pays.type` |
|---|---|
| Initial purchase | `BUY_SUB` |
| Renewal | `RENEW_ACCOUNT`, `RENEW_SCONFIG` |
| Volume extension | `INCREASE_VOLUME_*` |
| Time extension | `INCREASE_DAY_*` |
| Wallet recharge | `INCREASE_WALLET` |

Only `paid`, `approved`, `auto_cart_approved` and `paid_with_wallet`
count. Pending, declined, review, unresolved and processing states do not.
A bulk invoice does not become multiple purchases based on the quantity of
subscriptions. The historical `pays.request_date` is the **request creation
timestamp** and must not be called payment settlement or service delivery.
Original `users.date` is Unix registration time as a string.

Evaluation uses current rolling Unix seconds: `days*86400`. Future/nonpositive
request times are excluded. `count>0,days=0` requires that number of all-time
successes; `count>0,days>0` requires successes recorded on the selected
**older-than or recent** side of the inclusive time cutoff; `count=0,days>0`
requires at least one corresponding successful event in that window; both zero
disable only that metric. Registration age zero disables the age criterion.
The numeric editor does not embed example thresholds.

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

New rules persist atomically with the global gateway `update_id` watermark,
the actor's private wizard step, and other administrators' independent fields.
Wizard save compares the current field against the value seen when editing
began (field-level compare-and-set): two admins editing **different** fields
can both save, while a stale editor cannot overwrite a newer value in the
**same** field. Rule deletion similarly checks the originally previewed
rule fingerprint. The existing named settings writer lock plus current
administrator row-PK lock
prevent lost updates and cached-privilege bypass. The visible preview is only
advisory; actual authorization re-reads the persisted `BOT_STATES` with an
indexed customer/transaction current read under the existing owner/payment
locks. A global gateway OFF flag remains OFF regardless of eligibility:
NOWPayments still has separate wallet-topup and other switches; USDT and TRX
remain independently eligible under their shared manual-crypto switch.

Rule changes affect only **NEW** payment-method selection and checkout
issuance. Prior verified or uncertain paid claims, manual receipts, wallet
effects and external service states remain evidence for recovery, never
retroactively cancelled or refunded.

## Source acceptance and rollout

From a clean exact candidate run:

```sh
python3 tools/test_post_v9_gateway_rules.py --run-disposable
python3 tools/test_post_v9_gateway_admin_source.py --run-disposable
python3 tools/test_post_v9_manual_crypto_guards.py --run-disposable
python3 tools/test_post_v9_cohort_lock_scope.py --run-disposable
python3 tools/check_post_v9.py --report /tmp/achbot-post-v9-report.json
```

The first test uses real PHP on an **owned socket-only original-schema MariaDB**
and extracts the real bot callback and config writer code. It verifies all
methods, categories, history counters, legacy fallback, time boundaries,
overflow, private numeric confirmation, privileged callbacks, replay, and
absence of provider/payment effects. The master post-V9 acceptance requires
its exact named scenarios. Source regression is not proof of installed
Telegram/merchant/provider operation.

On an owner-approved **isolated installed test bot**, the operator should
verify the matrix below separately for each *purchase, renewal, time extension,
volume extension and wallet recharge* type; use synthetic/zero-risk data, not
real money or customer accounts.

| Actor / setting | Menu | New issuance | Expected |
|---|---|---|---|
| Eligible non-agent satisfying age+history | offered if method flag ON | permitted | no duplicate debit |
| Young/unqualified non-agent | hidden | denied even with old callback/URL | no claim created |
| Eligible agent with its own distinct rule | offered | permitted | independent policy |
| Method disabled, rule would otherwise match | hidden | denied | switch overrides rule |
| Invalid/empty explicit rule | hidden | denied | fails closed |
| Previously issued verified/manual evidence; rule then revoked | historic claim remains visible for review | **no new** issuance | no automatic refund/cancellation |

Before installing, confirm the exact PR SHA and 101-file runtime ZIP, do an
owned isolated test, verify secrets remain private, and obtain independent
review, Owner merge approval and separately scoped operator/deployment approval.
No SQL migration is part of this eligibility feature. See also
`PAYMENT-UX-LIFECYCLE.md`, `ISSUES-51-26-ACCEPTANCE.md`, and Issue #26.
