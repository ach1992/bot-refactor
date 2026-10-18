# AChBot automatic-renewal financial safety — Issue #26

## Source-only, not a production authorization

The Owner reports that the installed bot's **renewAutoState is OFF**. This
candidate must not enable it. Source verification, exact-candidate CI,
author-separated review, integration, package, database migration, operator
installation and real-money/provider acceptance are distinct checkpoints.

Before enabling on a real tenant: explicit Owner authorization, verified
file+SQL backup **and rehearsal restore**, schema preflight, separately
authorized additive migration 004, installed PHP/runtime compatibility,
isolated operator E2E of actual Marzban+Cron+MariaDB+Telegram, and review of
unresolved manual/provider renewal overlap. No live SQL, payment or service
operation was performed while implementing this candidate.

The source-only migration
**migrations/post-v9/004_automatic_renewal_attempts.sql**
creates only the **automatic_renewal_attempts** journal. It never modifies
the byte-frozen original 23-table V9 dump, overwrites the shared 003 ledger,
drops historical evidence or changes real data. It is NOT automatically run by
a source-only ZIP. Missing/incompatible schema fails closed before debiting,
creating a local payment or calling external fulfillment.

## Financial state and failure contract

1. Eligibility requires global and per-order auto-renew ON, active and
   owned Marzban order, valid active plan, positive price/duration, and
   unambiguous customer identity. An administrator-created negative debt
   remains legal but cannot be increased by this routine user-funded debit.
2. In an InnoDB transaction, lock the exact original orders_list row and
   wallet owner, save a unique (order_id,cycle_expire) renewal journal,
   save a pending local pays reference and debit the wallet **together**.
   The new renewal payment hash comes from cryptographic random_bytes.
3. Durably persist reserved -> remote_started **before** invoking Marzban.
   A duplicate Cron run, timeout, crash or old button cannot issue a second
   remote edit for that order while the attempt is unresolved. Never
   automatically refund money just because the remote reply is missing.
4. Only confirmed success from the existing Marzban edit helper may
   atomically finalize pays, increase_order, orders_list expiry and notif,
   plus the journal done marker. Its normal success includes matching
   remote UserResponse fields, not only HTTP 2xx. The helper can reset
   traffic before PUT; partial failure stays review/remote_started.
5. If local finalization fails after remote success, keep the operation
   unresolved for an operator instead of replaying service or releasing
   the debit. Ambiguous COMMIT is not evidence of rollback.
6. A low wallet disables only that subscription's auto renewal without a
   debit. A verified success sends the customer completion message only
   after commit; failed Telegram delivery cannot revert money or service.
7. Stale reserved/remote_started/review claims notify the authorized admin,
   with a bounded claim token and success-only daily acknowledgment, even
   if auto renewal was switched OFF during incident containment.

This journal prevents **automatic** renewal replay. The historical manual
renewal and externally paid fulfillment routes do not all take this
journal's lock; complete cross-path coordination is a separate Issue #26
acceptance gate. Do not claim this alone proves global exactly-once Marzban.

## PR #56 independent-review remediation: F1, F2, F3

The initial independently reviewed commit 18dd6a2 passed CI but was
**CHANGES_REQUIRED** for three serious omissions. The following design
addresses them in a NEW source-only candidate. Earlier CI and reviewer
verdict **do not** authorize its integration; rerun full exact-head CI
and request author-separated re-review of the new delta.

### F1 — Safe deletion while keeping every financial record

The 004 journal intentionally retains the immutable original order_id and
has a restrictive foreign key to the original pays row, but it **does not**
reference orders_list with ON DELETE RESTRICT or CASCADE. A completed
subscription can therefore be removed from orders_list without deleting its
historical payment or journal. Never backfill, rewrite, detach, or delete
the original payment evidence just to make a deletion succeed.

Actual user, administrator, local-only operator and expiry-Cron deletion
paths must call the same source-level safety functions:

1. Verify the entire 004 schema (or its genuinely absent historical state).
   Lock the exact local order and account-related active order identities.
   Refuse deletion if any unfinished journal or competing active Marzban
   account has uncertain ownership. A malformed existing 004 table is a
   HARD STOP, not a missing-table fallback.
2. Commit a durable local **status=0 / autoRenew=off deletion fence**
   before the non-idempotent external Marzban DELETE. After a lost remote
   response, the original order remains inactive for operator reconciliation:
   unattended Cron or a stale user callback cannot repeat the DELETE or
   charge for another renewal.
3. Require a successful remote response, then atomically remove the local
   order and restore logical server capacity when the original operation
   requires it. Failure of either local write leaves the status=0 order
   and financial journal for manual reconciliation instead of falsely
   reporting that deletion completed. The same shared cleanup helper is
   used for local-only operator removal (without remote calls or capacity
   restoration).
4. An unrelated account-cleanup error must not prevent due financial
   reconciliation alerts from running in the same scheduled worker. The
   warning Cron wrapper isolates cleanup exceptions with a finally block.
5. Remote DELETE transport checks the authoritative upstream provider
   response contract: the pinned Gozargah/Marzban revision
   `7f396db3e703d71a28060bc9ce4a532ec64cb1f4`, file
   `app/routers/user.py` (DELETE `/api/user/{username}`), returns
   HTTP 200 with the exact JSON object
   `{"detail":"User successfully deleted"}` after successful removal.
   Other response bodies, even HTTP 200 with unrelated JSON or arbitrary
   detail text, never certify deletion. The pinned upstream route does
   not document a 204 empty-body response: classify 204 as unconfirmed
   unless a separately verified supported provider version establishes
   that contract. HTTP 4xx/5xx, timeout, malformed JSON and lost
   transport responses remain review holds. Actual installed Marzban
   version/behavior is a separate operator-acceptance preflight.
   Source: https://github.com/Gozargah/Marzban/blob/7f396db3e703d71a28060bc9ce4a532ec64cb1f4/app/routers/user.py#L140-L156

This is a conservative state transition: remote deletion failure can leave a
legitimate original account inactive in local UI until operator recovery.
Never auto-reactivate, silently delete local evidence, auto-refund or call
the remote delete again to hide that partial failure. Operators must inspect
the actual Marzban account, remote action history, local status, capacity and
financial journal first.

**IMPORTANT rollback rule:** migration 004 has its own separate Owner-gated
installation and cannot be reverted by merely switching PHP source back.
An older unguarded AccountDeletion/Warnings/OrderManagement PHP version MUST
NOT be run against installed 004 with unresolved or status=0 deletion
fences. Freeze automatic renewal AND all destructive account-cleanup Cron,
reconcile holds, maintain an approved 004-compatible guard or provide a
separately tested forward-repair plan. Do not DROP 004 or delete its history
to make old code work. Historic clients without any 004 table may continue
legacy deletions, but with the new guarded source.

### F2 — Exclusive logical remote account identity

The old unique (order_id,cycle_expire) key only stops two automatic
workers renewing the same local order. Two eligible local orders might
point to one actual Marzban account. Inside the reservation transaction,
the new source locks all active orders with the same Marzban remark,
then rejects the attempt if another active order uses either the exact
same server ID or a separately administrator-assigned **valid Marzban
panel_group_id**. URL, username and unauthenticated claims are not used
to infer panel identity. A deadlock is safely rolled back before any money
or remote effect. The frozen original orders_list
idx_remark_userid index supports the matching-row lookup.

Distinct valid Marzban accounts still renew normally. The authority for
grouping is the existing source-only 001 migration; a missing/invalid alias
group cannot be guessed into equivalence. Changes to panel group
assignments during an actual payment remain an administrative operation
requiring its own coordination; this feature does not certify every
manual/hosted renewal route against cross-path overlap.

### F3 — Validate whole installed 004 contract before spending

Before initiating any renewal or sending financial-review alerts,
the source verifies the 004 table using INFORMATION_SCHEMA: **InnoDB**,
the exact required nullable/nonnullable column/type/length contract,
binary stage and notification token collations, the stage default,
all expected ordered unique/nonunique keys and the restrictive **pays**
foreign key, while rejecting an obsolete orders_list FK.

An existing table with missing alert columns, missing cycle uniqueness,
missing payment FK, wrong transactional engine, incompatible stage type,
or old restrictive order FK is NOT ready. No local pays reservation, wallet
debit, auto-renewal external edit or destructive deletion is allowed while
an incompatible table exists. The operator sees a protected reason-code log;
customer-facing messages remain safe and generic. Only the exact 004
contract can activate the worker.

A table that looks similar is not sufficient proof of compatibility.
Separate operator-approved migration/restore and durable reconciliation
are still required; NEVER silently ALTER a real tenant's journal table
or infer a partial migration is harmless.

### Reproducible regression evidence

The source-only native harness tests full temporary original-schema
MariaDB plus isolated 004 and 001/003 interoperability. F1 tests the
actual user/admin/local order deletion PHP routes, the actual warning
cleanup and finally-isolated Cron wrapper, official HTTP 200 success
detail plus fail-closed 204/4xx/5xx/unexpected-detail/invalid/lost body
cases against an owned 127.0.0.1 test provider, and actual guarded
customer/admin/Cron deletion handlers using the real Marzban transport,
SQL failure after mock remote deletion, operator recovery, and
preservation of historical pays/journal evidence. Customer preview
authorization and safe order-ID-only operator diagnostics are included. F2 tests two simultaneous PHP
workers targeting different local order IDs for one account, including
grouped same-panel aliases with different URLs/admin usernames. F3
runs deliberately incompatible temporary schema mutants; every unsafe
configuration must perform zero debit/remote effect until fully restored.
No real production/financial data or providers are touched.

## Safe operator reconciliation

For any unresolved attempt, independently inspect journal ID, original pays,
wallet debit, orders_list local expiry, increase_order, actual provider
user state and prior administrative/other-method renewals. Resolve whether
traffic reset/PUT occurred and whether local finalization committed.
Only a separately authorized audited operator action may settle or compensate.
No blind retry, automatic refund, data deletion or new remote invoice.

The native acceptance script
**tools/test_post_v9_automatic_renewal.py --run-disposable**
uses original 23-table disposable MariaDB, isolated 004, actual new PHP
functions, two competing real PHP/mysqli clients and mocked provider.
It covers replay, cross-owner, signed debt, insufficient funds, invalid
amounts, missing migration, remote timeout, remote-success/local-failure,
worker crash after remote marker and operator alert delivery failures.
No production money, Marzban or Telegram requests are made.

## Other currently used methods and future plans

Currently active high-priority technical assurance covers **manual bank
transfer, manual gift card, wallet payments/transfers, referral awards,
administrator wallet adjustments, and NOWPayments**. Their evidence, owner
authorization, concurrency/replay and provider finality must be verified
independently, not inferred from this renewal candidate passing.

**FUTURE / PARKED**: A bank-SMS matching API may be integrated after source
authentication, destination-account verification, exact bank transaction
identity, anti-replay, reversal and ambiguity handling. Persist each
**deliberately non-round exact Toman quote** per order in the provider-neutral
payment_attempts record (immutable DECIMAL quoted amount plus original local
Toman amount and destination snapshot), so it can later be compared exactly
to authenticated bank events without floating-point rounding. Until that
external integration exists, bank receipts remain manually verified and the
time-only automatic card acceptance must stay disabled.

**FUTURE / PARKED**: Gift-card code entry instead of a photo, with external
provider API for validity, unused/redeemed status, identity, settlement and
one-time use. Current gift-card evidence requires independent human review.
No API integration or operator policy change is included in this candidate.
