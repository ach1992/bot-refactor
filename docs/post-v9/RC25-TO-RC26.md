# RC25 -> RC26 operator migration and recovery guide

> **Historical published RC26 upgrade only.** Draft PR #136 proposes a
> different future schema without experimental 019/021. Use RC26's tagged
> runtime and 018-021 sidecars together if following this historical guide;
> do not combine them with the unreleased successor's 018/020-only schema.


> **RC26 SOURCE-ONLY PRERELEASE — NOT INSTALLED, NOT APPROVED FOR RUNTIME CUTOVER.**
> [v0.10.0-rc.26](https://github.com/ach1992/bot-refactor/releases/tag/v0.10.0-rc.26)
> publishes the independently reviewed, Owner-approved merged source through
> [PR #134](https://github.com/ach1992/bot-refactor/pull/134):
> `main@390f7ac5cb99285864d019e3ee0ee0b5fad05bec`, source TREE
> `a9d677eae76794e47ad4ae88b3ae60520a1a1ef0`.
> The previously reviewed [PR #133](https://github.com/ach1992/bot-refactor/pull/133)
> is included. The full PHP 8.5 candidate CI and same-tree merged-main
> verification passed. Publication and this guide do **not** authorize
> Chili installation, schema migration, new Cron activation, live
> merchant/provider changes, real payments or Telegram sends.

## PR #133 review history — R0/R1 fixes and independently approved R2

The first PR #133 candidate (`d5222c3e6702e1ea6d1e98da3d587deeb3c2faba`)
received **CHANGES_REQUIRED** (one blocker, three required). R1 resolved
those four findings but initially omitted 021 from distribution sidecars.
The final R2 candidate `bbfbd9586f0e6ee4341394b301e8135244a92dea`
independently received **COMPLETE / APPROVE** after its
[full PHP 8.5 validation](https://github.com/ach1992/bot-refactor/actions/runs/42510873278).
It distributes 018–021 SQL/checksum sidecars and documents explicit 021
operator verification; [PR #133](https://github.com/ach1992/bot-refactor/pull/133)
was merged with unchanged tree. These reviewed corrections preserve legitimate
`free_trial` and `admin_bulk`
Native CREATE deletion capacity, while treating source-pinned imported
accounts as never consuming capacity; paid replacement has its own positive
payment provenance. Both native adapters require source-real local deletion
finalization tests.

The source-only 021 migration **also adds two nullable `pays` columns**:
`issued_entitlement_days` and `issued_entitlement_gb`. All new standard BUY_SUB
invoices pin the actual day/GB pair in the original draft transaction while
preserving `pays.day=0` / `pays.volume=0` for the existing standard/custom
routing contract. Custom invoices pin their same positive custom dimensions.
These fields join the immutable payment fingerprint for new invoices only;
historical packets retain their original hash. An older standard invoice
with empty 007 and no independent issued terms is **not** permission to use
TODAY'S edited plan: confirmation refuses before capacity reservation/native
POST and shows the original-purchase evidence deficiency. Surviving original
007 may independently establish historical terms, which must match on
replacement. No automatic guess/backfill or fabricated historical 007 is
permitted.

A replacement preview's signed confirmation now also pins the current
Marzban URL/group/target and PasarGuard physical connection, instance and
provider generation, using hashed physical target evidence. The reservation
transaction rejects a stale target before COMMIT; the staff actor must inspect
and confirm again. The existing private admin recovery page returns bounded
sanitized `reason`, `stage`, and `next_action` values for each operational
failure; credentials, arbitrary vendor responses, and SQL exception text are
not rendered. An UNKNOWN Native POST is always handled through GET-only
reconciliation and never a repeated CREATE.

All migration/publish/installed-host effects remain separately gated.
The existing R2 approval applies only to its exact accepted source tree;
any further runtime changes require their own CI and independent review.

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
- **Already integrated original manual BUY_SUB continuation (#124):** the
  Service recovery case offers **Continue approved purchase** for eligible
  standard `BUY_SUB` claims in `processing` with their original receipt,
  `007` capacity hold and ready 008/018/020 contracts. The original
  `accept{original_hash}` handler rechecks original proof and once-only effects
  under the existing payment-approval authority. Merely viewing is read-only.
- **Integrated source-only PR #125 (not installed in Chili):** the
  original manual `approveRenewAcc`, `approveIncreaseDay` and
  `approveIncreaseVolume` buttons may finish a prior PasarGuard
  `processing` claim **only when the existing per-account mutation journal**
  proves the exact `manual:pay:<id>` action completed successfully with
  `provider_state=linked`, verified requested units, original local expiry,
  **original human approver and local toman amount** (persisted before native
  dispatch), immutable account/provider identity and matching original receipt.
  Where the original 003 manual payment attempt is present, the immutable
  `local_amount_toman` and payment identity must also match. A second
  payments-authorized administrator may resume without replacing the original
  approving actor in `approved_by` or the final administrative receipt. The quiet
  case view shows the existing button only for that verified local-only
  continuation. The callback rechecks all evidence under its transaction and
  writes the expiry, payment `approved` state and one audit row together;
  final administrator receipt edits and customer notices are post-COMMIT.
  No new provider GET/PUT/reset, charge or approval is authorized. Missing or
  altered evidence stays `processing` for operator investigation, not a
  repeated native mutation or fabricated customer success.
- **Integrated source-only durable follow-up completion (Issue #117):**
  after a verified PasarGuard manual edit is locally finished, the original
  primary/eligible stored administrator receipt edits and subject-specific
  customer notice (plus the original renewal operator summary, where applicable)
  are sealed as **020 `approved_edit` intents inside the same terminal SQL
  COMMIT**. Missing 020 schema/key, invalid original recipient or a failed
  packet insertion rolls back the local payment/order/audit COMMIT; the native
  verified operation remains unchanged for later local-only completion.
  After successful COMMIT, attempt each sealed PENDING packet at most once.
  A PHP failure between COMMIT and Telegram leaves PENDING rows available
  through the original payment-authorized callback. The Owner-private
  service case displays each recorded packet's role, state, attempt count
  and original recipient/message reference (unresolved packets first);
  the original callback button is offered only when PENDING exists.
  UNKNOWN/dispatching ACKs remain visible for read-only diagnosis and are
  **not** automatically resent or counted as delivered.
  The existing `payment_followup_intents` table needs no new migration;
  without a separately authorized installed 020 migration this behavior must
  not be presented as production-ready. No second payment/native action,
  role expansion or automatic Telegram retry is authorized.
- **Bank-Cron / verified bank-SMS or gift API `BUY_SUB`:** an interrupted
  `processing` purchase may re-enter the existing per-ordinal continuation.
  An already journaled ordinal requires pinned read-only native confirmation
  before local commit; it cannot reissue CREATE. A genuinely unstarted ordinal
  may be claimed once through the original unique-key create path. An original
  007 full-quantity capacity reservation counts even when today's free slots
  are exhausted. Missing or mismatched native evidence stays unresolved.
  Custom terms and multi-account quantity remain subject to original immutable
  invoice and per-ordinal verification. This is not a refund or new approval.
- **Bank-Cron processing recovery:** the normal timer deliberately only
  claims original `have_sent` receipts; it does not revisit the resulting
  `processing` cases after a crash. For **non-API bank receipts** with an
  unchanged original `003` attempt and `009` issued invoice, an authorized
  administrator uses the **existing payment approval permission** from the
  original `attentionInbox` technical view (`attentionInboxService`),
  and the existing `accept{hash}` callback to continue the original purchase.
  This does not change `Access.php`, role levels or owner-only specialist tools.
  Only original `007` CREATE ordinals with a valid full-count inventory origin
  are eligible. The original provisioner and native readback are reused;
  after a verified terminal COMMIT, only pending 018 delivery,
  payment-keyed 008 referral work, and the **original** bank-Cron
  administrator receipt/status edits (primary plus eligible stored copies)
  may be performed. Telegram edit failure must never reopen financial/native
  fulfillment; the recovery Workbench message alone is not the original receipt.
  Unmatched/unknown native effects or changed invoices stay `processing`
  and never authorize an extra CREATE or automatic message resend.
  The 003 stage may remain `pending` after the original Cron claim; its
  immutable 009 fingerprint, not the mutable stage, owns bank identity.
  No new background reprocessing loop is introduced.

## Original Hosted BUY_SUB recovery — integrated source, not installed

After RC25, Issue #117 recovery uses original 003/007/012/018/019 journals
for paid NextPay/Zarinpal native purchases whose order/delivery commit
did not complete. With a complete original CREATE manifest, the Owner
authenticates each original Marzban/PasarGuard account via GET; original
linked orders are immutable, verified-but-unlinked original accounts receive
their first local orders, and original 003 service closure and all immutable
018 packets COMMIT together. Customer Telegram follows COMMIT only.

If a purchase has a **contiguous original 007 prefix**, a first full-count
capacity reservation, and **trailing never-reserved ordinals**, the explicitly
labeled Owner callback may first perform one new 007 CREATE for **the next
original never-started ordinal**. This is not replay or replacement: the
actual 007 source reserves a unique ordinal before the first POST, reuses
the original capacity hold and denies changed provider/plan/entitlement
or missing authenticated GET of every previous account. The Owner can repeat
only for further never-started ordinals. A dispatched/unknown 007 record
must be inspected by GET, never re-POSTed. No new merchant settlement,
Wallet debit, refund, duplicate original reserve or new invoice is allowed.

A missing first 007 owner, noncontiguous ordinals and authenticated
foreign/orphan accounts now have a **separate** staff-attested replacement
workflow from integrated PR #133, described below. That workflow does not
rewrite or repeat an original CREATE; an HTTP 404, timeout, missing historical
provenance or changed physical endpoint never supplies automatic approval.
Preserve original 003/007/012/018/019/020/021 evidence. Further details:
[Payment delivery recovery](PAYMENT-DELIVERY-RECOVERY.md).

## Owner-reviewed paid Hosted account replacement — integrated source, not installed

The original paid NextPay/Zarinpal claim is still governed by its saved 003
merchant-completed evidence and frozen invoice/quote. Its **original 007
CREATE row is never reused, overwritten, retried or relabeled**. If a Native
account cannot be safely recovered, authorized payment-review staff in their
**own private Telegram chat** (Owner, Manager, Operator and Support where the
current persisted role allows `payments`) may inspect original evidence and
then explicitly confirm one **distinct** replacement. The signed,
actor/ordinal/source-pinned two-step warning does **not** claim that a 404 or
timeout proves absence; an original Native account can still be orphaned.
A linked original local order cannot be silently replaced. Stored staff role
and payment capability are rechecked inside the reservation and final SQL
transactions, so old keyboards cannot grant actions after demotion.

New additive, **not yet installed** migration `021_paid_reconciliation_provenance.sql`
adds optional original-effect self-reference, immutable reason/fingerprint,
a bounded stored Native-check outcome and observed timestamp, and
a unique source-original replacement claim to the existing 007 journal.
A replacement gets an independent operation marker and `effect_kind=replacement`
reservation **COMMITTED BEFORE** its first Native POST; any lost/ambiguous
POST response requires GET-only reconciliation, never a second POST. The
first committed original capacity claimant stays authoritative. Where there
is genuinely no first original 007 capacity claimant, an explicitly authorized
first replacement reserves the complete original quantity once **only
when the original 007 CREATE history is genuinely empty** under its atomic
reservation-before-POST contract. If first 007 is missing while later original
ordinals survive, capacity was already owned under 007's predecessor rule.
This route must first authenticate **every** surviving original Native account
via strict GET and the exact immutable operation marker. Only then can staff
explicitly approve replacement ordinal one **without any new capacity debit**,
while preserving the damaged/orphan original history. A later Native mismatch,
timeout, unknown physical account, or failed authenticated GET **fails closed**;
never infer prior capacity ownership merely from a damaged SQL row. Later linked orders,
immutable encrypted 018 packets and original 003 closure are an atomic final
SQL transaction, with `pending` Telegram delivery only after COMMIT.

This reviewed paid-replacement source increment is **published in RC26**:
exact PHP 8.5 full candidate CI, independent high-assurance review, Owner
Merge and source-only publication gates passed. The new 021 schema is still
**not installed** by publication; its SQL remains outside the runtime ZIP
and requires *separate* live migration approval after 018/019/020 rehearsal.
Missing evidence, unknown payment provenance, changed provider group/endpoint,
partial 021 shape, or unsupported history fail closed; this does not
constitute unconditional resolution of every historical paid orphan.

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
   then `020_payment_followup_intents.sql`, testing each absence and shape
   before applying it, and verifying InnoDB, composite unique constraints,
   indexes and recorded receipt/decision foreign keys after each step.
   **Never backfill missing delivery packets** by guessing old subscriptions
   or assuming an existing account. Only after the complete 018/019/020
   contract is verified, audit `service_effects` and `pays` for every object
   introduced by `021_paid_reconciliation_provenance.sql`. Verify the
   CI-distributed SQL sidecar against its separate checksum from the sidecar
   directory (`sha256sum -c 021_paid_reconciliation_provenance.sha256`);
   SQL and checksum are **outside** `runtime.zip`. If the complete 021
   extension is absent, apply `021_paid_reconciliation_provenance.sql`
   **once** through an audited, separately authorized staging SQL session.
   If 021 is already complete, verify-only; any partial, unexpected or
   incompatible 021 object is a **STOP**, not permission to rerun an ALTER,
   drop an object or guess a historical value. Verify the five 021
   `service_effects` columns, `uq_effect_one_replacement`, and
   `fk_effect_original_replacement` with its restrictive FK actions; also
   verify nullable `pays.issued_entitlement_days` and
   `pays.issued_entitlement_gb`. Inspect actual DDL and run the read-only
   `payment_readiness_preflight.php` against the exact candidate/manifest;
   a PASS does not replace the schema, row and migration-history audit.
6. Run exactly the reviewed PHP 8.5 runtime package in isolated staging with
   synthetic/unfunded payment cases and mock Marzban/PasarGuard. Prove first
   approval, double click/two staff, remote success with SQL commit failure,
   native timeout/404 and same-account GET, second bulk ordinal, QR ACK
   ambiguity, notification reporting and rejected receipt no-effect path.
7. The RC26 source publication already passed the reviewed exact-candidate
   full PHP 8.5 CI, independent review, Owner Merge/release authorization,
   same-tree merged-main CI and SHA256-bound ZIP/manifest verification.
   Its public GitHub Release is **not** evidence of installed acceptance.
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

## Bounded scheduled first delivery of already-committed service packets

The existing four-worker Cron `services` worker now has a bounded 018
customer **first-delivery** continuation. Only a payment with a terminal
confirmed local state and a sealed `payment_delivery_intents` row that is
still `pending`, has **zero previous attempts** and has been quiet for at
least two minutes enters the sweep. The existing `achbotDeliveryDispatch`
transaction revalidates frozen payment, native-effect, order, recipient and
encrypted packet evidence before recording an atomic dispatch and sending
Telegram. Default scope is four packets per tick and an eight-second **soft**
budget; a single provider-independent Telegram call can exceed that budget.
Invalid/preflight-deferred packets simply move behind other due packets
using existing `updated_at` metadata, with at least another two-minute
interval before reinspection, not a new payment/native attempt. No new SQL
migration, worker mode or payment settlement is introduced.

A first Telegram attempt with ambiguous ACK is `unknown` and **never**
automatically replayed; pre-existing `unknown`/`dispatching`, operator
resends and all provider CREATE/PUT uncertainty remain in their appropriate
explicit human recovery process. A clean local retry of a terminal callback
and Cron compete on the same once-only 018 claim. Source tests use real
disposable MariaDB and isolated Telegram spies, not live customers.

## Explicitly uncompleted acceptance

The integrated current source covers original manual/Hosted/wallet BUY_SUB
continuation, explicit staff-attested paid replacement and durable per-subject
approved follow-up packets. It intentionally does **not** infer a successful
ambiguous PasarGuard `mutating` or uncertain Marzban
`RENEW_ACCOUNT`/day/volume/standard `RENEW_SCONFIG` remote PUT. These
require original authenticated positive operation evidence; neither Cron nor
a user button may issue another additive PUT from uncertainty. Provider-
specific financial review parity and installed Marzban/PasarGuard/Chili
acceptance still need verified test/operational evidence. The actual 018–021
schema, original payment/native journals, tested database **restore**, live
Telegram acknowledgement and end-to-end authorized acceptance remain
unverified until an explicit target cutover. Future source candidates need
exact-HEAD PHP 8.5 CI, independent review and separate Owner Merge/release
permission. **RC26 is published source-only; it is NOT installed, migrated,
live-provider certified or deployment-ready** merely because `main` passed CI.

References: [Issue #117](https://github.com/ach1992/bot-refactor/issues/117),
[PR #123](https://github.com/ach1992/bot-refactor/pull/123),
[Payment delivery recovery](PAYMENT-DELIVERY-RECOVERY.md).
