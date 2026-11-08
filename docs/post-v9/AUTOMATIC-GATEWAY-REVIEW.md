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

An original hosted payment can also be stranded after settlement or during
Native fulfillment. Stale `processing`/already-paid cases with unresolved
fulfillment evidence and an operator's previously unknown effect remain
**visible until explicitly archived** in the same list, **read-only for
Approve/Reject**: they never receive a second financial decision button.
Any listed case can be **Archived** after operator review. Archive removes
it from the active inbox, while preserving the original payment/order
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
packaging. **Existing isolated Chili test schema still contains the table**;
it can be dropped only once the new runtime is installed and no caller
uses it. Confirm zero rows, exact schema and any FKs before the scoped
DROP TABLE chilibot.payment_review_decisions with target-specific approval.

**Do not drop 018 or 020**: payment_delivery_intents,
payment_delivery_attempts and payment_followup_intents remain part of
normal paid/manual bank/gift operations, even though the experimental
recovery screen is gone.

The 021 paid replacement feature, its extra 007 effect columns and the
two nullable issued-entitlement columns on pays have no successor runtime
consumers. On a **new empty database**, do not apply 019 or 021. On an
installed test database such as chilibot, first install the version-matched
new runtime and verify all its ledger and customer paths. Only then, with
a target-scoped DDL authorization, inspect and optionally remove the exact
021 unique key, restrictive self-FK and seven unused fields, plus 019.
Neither removing UI routes nor seeing zero rows justifies dropping core
delivery or follow-up evidence tables. If a foreign key or old data
requires preservation, stop and investigate instead of blindly dropping.

### Optional installed test-schema cleanup (NOT EXECUTED)

Current installed Chili still has RC26 paths that depend on the 019 table.
**Do not run these statements against RC26.** Once a separately approved,
fully verified successor runtime is installed, and an explicit host-scoped
DDL approval is granted, inspect the exact objects and users. The Owner has
**waived a backup for the named `chilibot` TEST database only**; this does
not waive schema/row/FK/index inspection, change approval, or the risk of
irreversible test-data loss. Production or any other database has no such
waiver and requires its own verified recovery plan:

```sql
SELECT COUNT(*) AS experimental_decision_rows
  FROM chilibot.payment_review_decisions;
SELECT effect_kind, COUNT(*) AS rows_seen
  FROM chilibot.service_effects GROUP BY effect_kind;
SELECT COUNT(*) AS old_issued_terms
  FROM chilibot.pays
  WHERE issued_entitlement_days IS NOT NULL
     OR issued_entitlement_gb IS NOT NULL;
SELECT TABLE_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_NAME
  FROM information_schema.KEY_COLUMN_USAGE
  WHERE TABLE_SCHEMA = 'chilibot'
    AND (TABLE_NAME IN ('service_effects','payment_review_decisions')
      OR REFERENCED_TABLE_NAME = 'payment_review_decisions');
```

If the exact expected 019/021 shape, FK names, data ownership or host
authorizations do not match, **STOP**. In particular, do not silently
discard real replacement effects (if any). The minimal *candidate* SQL
below is deliberately **not** a runtime migration/installer, and requires
its own reviewed exact-shape preflight and explicit host mutation grant:

```sql
-- Run only after approved test-only cutover and exact schema inspection.
DROP TABLE chilibot.payment_review_decisions;
ALTER TABLE chilibot.service_effects
  DROP FOREIGN KEY fk_effect_original_replacement;
ALTER TABLE chilibot.service_effects
  DROP INDEX uq_effect_one_replacement;
ALTER TABLE chilibot.service_effects
  DROP COLUMN replaces_effect_id,
  DROP COLUMN reconciliation_reason,
  DROP COLUMN reconciliation_fingerprint,
  DROP COLUMN reconciliation_evidence_status,
  DROP COLUMN reconciliation_observed_at;
ALTER TABLE chilibot.pays
  DROP COLUMN issued_entitlement_days,
  DROP COLUMN issued_entitlement_gb;
```

These MariaDB DDL statements autocommit; SQL ROLLBACK cannot undo them.
A verified database restore is a possible recovery path **only when a matching
backup actually exists**. Since the Owner waived a backup for `chilibot` TEST,
no physical rollback can be promised for that target; approval must explicitly
accept this consequence. Re-run the read-only schema and payment/source
preflight afterward and record exact before/after objects. Keep 018/020, original 003/007/009 and customer data
untouched. **No command from this subsection was executed on the host.**

No runtime ZIP extraction, test DB DROP, webhook, live payment or provider
side effect is authorized by this source document. PHP 8.5 validation,
independent review, owner integration and deployment gates still apply.
