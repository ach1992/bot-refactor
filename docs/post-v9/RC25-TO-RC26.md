# Draft RC25 -> RC26 operator migration and recovery guide

> **DRAFT — NOT RELEASED, NOT APPROVED FOR INSTALLATION.** This file prepares
> the eventual `v0.10.0-rc.26` source candidate. RC25 remains the latest
> published release until exact-main provenance, all eight PHP 8.5 families,
> an independent exact-HEAD review, and the required Owner gates pass. This
> document and GitHub PR do not authorize Chili changes, remote provider
> operations, real payments, Telegram sends or SQL changes.

## Scope and new contracts

- Administrator approval or rejection of a NOWPayments review item is a
  single explicit human financial decision; **no additional merchant GET and
  no second confirmation screen**. The exact original invoice/quote/child
  records and one-shot `019` decision journal are still required. Human
  attestation is never recorded as a fabricated provider `finished`/`refunded`.
- Ordinary bank/gift/manual crypto receipt rejection runs the existing
  `have_sent -> declined` compare-and-swap on the first button press, with
  standard customer and staff reporting and no service or wallet grant.
  An already `processing` or final order cannot be rejected to reverse a
  possible remote effect.
- For standard, manually reviewed `BUY_SUB`, the **original 003 receipt,
  007 per-payment account identity, and ready 008/018/020 ledger schemas**
  are mandatory before the first native CREATE. The frozen 008 referral claim
  is actually **inserted in the final local order/payment COMMIT**, after
  native identity verification, not before contacting the provider. Processing
  retries must use the pinned
  account/operation readback, not issue a second CREATE. Only verified local
  account/order records, frozen referral decision and immutable sealed
  customer/report packets are committed together; Telegram dispatch occurs
  **after** this final transaction. The eligible referral credit and its
  reports commit in their own uniquely fenced local transaction.
  Reapproval of an already completed local order dispatches only never-attempted
  `pending` sealed customer packets and 020 reports, never an `unknown` ACK.
  The separate Owner-private `deliveryInbox` requires an explicit warned
  confirmation before resending the **same** sealed packet with an ambiguous
  prior Telegram result; it cannot grant new service or credit.
- An unreachable/mismatched Marzban or PasarGuard native account leaves the
  original processing state unresolved, with the pinned effect preserved.
  Never infer failure from HTTP 404, timeout or Telegram errors and issue
  a second CREATE, PUT, credit or refund.

## Prerequisites and one-way migration

1. Determine **actual installed** source ZIP manifest, schema, PHP version,
   active financial/native holds and read-only payment/effect/delivery counts.
   Never assume a prior RC tag or source CI proves database readiness.
2. Obtain a separate, target-specific Owner approval. Quiesce new bot
   submissions, checkout, payment Cron and webhooks at a safe coordinated
   maintenance boundary. Preserve active locks and install-private config.
3. Make encrypted backups of the actual entire database, private bot config,
   `telegramWebhookSecret` and current source. **Restore to disposable staging**
   and verify decrypt/read, FKs and a controlled failed/rollback simulation.
   Merely generating a backup is not a tested rollback.
4. Audit required earlier migrations in order (`001`–`017` as applicable):
   especially `003_payment_attempts`, `007_service_effects`, `009` bank claim,
   `012` PasarGuard storage and any needed `014` API evidence.
   Existing tables/columns or unknown partial objects are STOP conditions,
   not reasons to rerun additive SQL or drop/replace historic payment rows.
5. Staging-only, after confirming schema and backup: apply the one-time
   `018_payment_delivery_intents.sql`, then `019_payment_review_decisions.sql`,
   and `020_payment_followup_intents.sql` after testing each absence and shape
   only if each is genuinely absent and the exact source/migration contract
   was reviewed. Verify InnoDB, composite unique constraints, indexes and
   recorded receipt/decision foreign keys. **Never backfill missing delivery
   packets** by guessing old subscriptions or assuming an existing account.
6. Run exactly the reviewed PHP 8.5 runtime package in isolated staging with
   synthetic/unfunded payment cases and mock Marzban/PasarGuard. Prove first
   approval, double click/two staff, remote success with SQL commit failure,
   native timeout/404 and same-account GET, second bulk ordinal, QR ACK
   ambiguity, notification reporting and rejected receipt no-effect path.
7. Publish source artifacts only after exact-main full CI, independent review,
   Owner Merge/release authorization and SHA256-bound ZIP/manifest checks.
   An authorized Chili rollout is **separate** and must stop before any real
   financial/provider mutation if staging or migration evidence is incomplete.

## Recovery and rollback

- Before migration or any new current-source financial/native write, the
  tested DB+config+runtime checkpoint permits a coordinated whole-state
  rollback at the approved boundary.
- **After** a current-source financial/native/018/019 write, restoring older
  source or DB alone can erase new evidence and cause duplicate grants. Treat
  this as a forward-recovery case: preserve full journals and reconcile the
  exact original payment/ordinal/remote identity. Do not delete new ledgers,
  reset a `processing` pay to `have_sent`, or run blind CREATE/PUT/refund.
- Protect the encryption key. If the key is lost, sealed delivery packets
  cannot be treated as safely retrievable. Use the original verified backup
  or stop pending delivery.
- Native unavailability and uncertain Telegram acknowledgements are not
  authorization to create a replacement subscription. Report correlation IDs
  and actual journal states to authorized diagnostic channels without secrets.

## Explicitly uncompleted acceptance

This candidate does **not** yet provide automatic replay-safe renewal,
`plus_day` or `plus_volume` recovery for every payment method; these are
additive external mutations and must not be retried without an original
absolute target baseline. Other checkout providers, full PasarGuard recovery
and actual installed Chili evidence require separate acceptance under
[Issue #117](https://github.com/ach1992/bot-refactor/issues/117). Do not
label RC26 released or deployment ready while those gates remain open.

References: [Issue #117](https://github.com/ach1992/bot-refactor/issues/117),
[PR #123](https://github.com/ach1992/bot-refactor/pull/123),
[Payment delivery recovery](PAYMENT-DELIVERY-RECOVERY.md).
