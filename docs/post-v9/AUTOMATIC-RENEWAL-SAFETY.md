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
