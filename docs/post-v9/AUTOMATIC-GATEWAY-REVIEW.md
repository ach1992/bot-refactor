# Simple automatic-gateway exception inbox — successor to RC26 Issue #117 menu

> **Status:** source-only development, not merged, reviewed, released or deployed.
> The published RC26 asset and migration 019 remain historical external artifacts.

## Scope

One silent, on-demand administrator screen only for incomplete hosted
NOWPayments, Zarinpal and NextPay orders. Ordinary customer-funded manual
receipt queues are unchanged and **never** appear in this screen. No
operator-only finance, service-native, Telegram or evidence workbenches.

The list renders **amount in toman | numeric customer ID | gateway** for
original unresolved issued invoices. After a bounded, configurable payment
age, even a still-pending hosted invoice appears as a **candidate**, never
as automatically paid. Tap to inspect the original order type, customer,
plan, bound subscription username, original request date, provider invoice
and payment references, original quoted amount, latest locally recorded
provider events and their timestamps, plus processing state. Merchant
verification is manual and out of band. Do not infer merchant settlement
from browser return, elapsed time or an old confirmed NOWPayments state.

## Decisions

A currently authorized payments administrator can choose Approve, Reject
or Back. Approval claims the original unique payment_attempts row
and advances the original doAction; no duplicate payment/order is
created. Rejection marks this original invoice declined **without claiming
a merchant refund**. All callbacks re-validate user role, private chat,
unchanged quote/invoice/target, one original claim, recorded child payments
and pre-existing service effects under correct InnoDB lock order. An
ambiguous native/credit effect is held; it is **never blindly replayed**.
Terminal/owner-claimed orders leave the inbox. Approval is not proof
of merchant payment; the operator must check the merchant dashboard.

## Schema policy

No new table or column for the inbox. Reuse pays,
payment_attempts and payment_transactions. The old experimental
payment_review_decisions migration 019 is removed from *future-source*
packaging. **Existing isolated Chili test schema still contains the table**;
it can be dropped only once the new runtime is installed and no caller
uses it. Confirm zero rows, exact schema and any FKs before the scoped
DROP TABLE chilibot.payment_review_decisions with target-specific approval.

**Do not drop 018 or 020** merely because their review UI buttons were
removed: payment_delivery_intents, payment_delivery_attempts and
payment_followup_intents are core, actively referenced by normal
bank/gift/payment and Telegram once-only workflows. Migration 021's
frozen issued terms and native effect reconciliation fields also have
live backend consumers; their removal requires a separate fully tested
rewrite, not dropping columns while consumers still read or write them.

No runtime ZIP extraction, test DB DROP, webhook, live payment or provider
side effect is authorized by this source document. PHP 8.5 validation,
independent review, owner integration and deployment gates still apply.
