# Simple automatic-gateway exception inbox — successor to RC26 Issue #117 menu

> **Status:** source-only development, not merged, independently approved, released or deployed.
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

An original hosted payment can also be stranded after settlement or during
Native fulfillment. Stale `processing`/already-paid cases with unresolved
fulfillment evidence and an operator's previously unknown effect remain
**visible until explicitly archived** in the same list, **read-only for
Approve/Reject**: they never receive a second financial decision button.
A case can be **Archived** once no already-admitted Native dispatch
remains unresolved. If one is in flight, Archive reports that it was **not**
performed; the operator must verify its outcome first, not assume that a
remote request was canceled. A still-**reserved** line is not an admitted
remote operation and cannot dispatch after Archive. Archive removes
a successfully closed case from the active inbox, while preserving the original payment/order
state, provider and child evidence, and Native/Telegram replay holds.
The archive actor and timestamp persist; later provider callbacks and
Cron must neither change the archived original's stage/status nor mutate
its local financial order or retry Native/Telegram effects. A late signed
NOWPayments child event can be recorded separately as external evidence,
without reopening the archived case or claiming a refund/settlement.
Short-lived
active original callbacks are not mistaken for stale failures (ten-minute cutoff). No new technical
workbench or unsafe Native replay is introduced.

## Decisions

A currently authorized payments administrator can choose Approve, Reject,
Archive or Back. Approval claims the original unique payment_attempts row
and advances the original doAction; no duplicate payment/order is
created. Rejection marks this original invoice declined **without claiming
a merchant refund**. All callbacks re-validate user role, private chat,
unchanged quote/invoice/target, one original claim, recorded child payments
and pre-existing service effects under correct InnoDB lock order. An
ambiguous native/credit effect is held; it is **never blindly replayed**.
Fully settled, fulfilled and rejected orders leave the inbox automatically;
unresolved previously claimed orders stay visible read-only **until archived**.
Approval is not proof
of merchant payment; the operator must check the merchant dashboard.

## Schema policy

No new table for the inbox. Reuse pays, payment_attempts and
payment_transactions. Owner clarification adds exactly two metadata columns
on the original attempt, `review_archived_at` and `review_archived_by`,
through one-time additive migration `022_gateway_review_archive.sql`.
The archive marker is a terminal local automation fence distinct from provider `stage` and
`provider_status`: neither provider callbacks nor the Cron may
clear it. This avoids stale original cases reappearing after they have been
archived, while original financial and service effects are untouched.

Migration 022 is required before using the successor's inbox. It is not
present in installed RC26 and requires the same separately approved,
backup/restore-tested, versioned runtime cutover gate. The old experimental
payment_review_decisions migration 019 is removed from *future-source*
packaging. Existing RC26 installations may still contain this historical
table and must retain it until any separately authorized schema audit
establishes a safe disposition. No installed objects are dropped by PR #136.

**Do not drop 018 or 020**: payment_delivery_intents,
payment_delivery_attempts and payment_followup_intents remain part of
normal paid/manual bank/gift operations, even though the experimental
recovery screen is gone.

The successor **does not offer paid replacement** or require 019/021 for a
new empty database, but existing RC26 records are different: historic 018
delivery packets and API evidence may refer to 021 issued-entitlement fields
or replacement provenance. These fields are now read **only to validate
the immutable historical fingerprint and delivery digest**. Removing their
database columns would invalidate already-sealed proof. Therefore:

- **New empty databases:** use the current 018/020/022 installation order;
  do not apply 019/021.
- **Existing RC26 tenants, including TEST `chilibot`:** preserve existing
  019/021 objects and their historical rows by default; removal is not part
  of this source cleanup. Do not remove 021 columns or 018 sealed evidence.
- The Owner's backup waiver applies only to `chilibot` TEST. It does not
  authorize MariaDB DDL, host changes or source cutover. A distinct,
  evidence-backed approval is required for any future destructive cleanup.

**Archive versus outbound Native CREATE:** a short original-payment lock
coordinates reserved -> dispatching admission with terminal Archive. A
reserved line can be archived and then cannot begin remote dispatch. An
already-admitted/uncertain Native operation makes Archive return a clear
**not archived** status until its outcome is independently resolved; there
is no false remote cancellation or replay. No new process, queue, table or
operator workbench is introduced.

No runtime ZIP extraction, test DB DROP, webhook, live payment or provider
side effect is authorized by this source document. PHP 8.5 validation,
independent review, owner integration and deployment gates still apply.
