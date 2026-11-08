# Issue #117 — crash-safe customer delivery (source-only candidate)

## Scope and invariants

This is an additive **source** change, NOT an installed Chili migration and NOT a claim that full Issue #117 is accepted. Payment/native settlement remains governed by the existing financial and service-effect pins.

For 003-backed bank BUY_SUB and API bank/gift BUY_SUB, an original, immutable per-ordinal packet (QR/photo caption/text/keyboard, destination, provider/effect/order pins) is encrypted with the existing payment-evidence AEAD key and inserted in the **same InnoDB transaction** that records local orders, links verified native CREATE effects and marks pays terminal. If the required schema/key is unavailable, the remote CREATE path fails closed before any provider request. A late SQL failure rolls back local order, terminal state and all delivery intents together. Native effects already dispatched before the final DB transaction remain in review; no blind CREATE retry.

For API wallet and API service-edit subjects, the existing MANUAL customer success copy is frozen in the same terminal transaction; original API verified-fulfillment proof is used for service edits. For new 003-backed automatic bank-wallet credits, the pre-existing bank customer copy is similarly frozen with the original credit, preserving fallback behavior for historical records with no payment attempt. A modern 003-backed wallet receipt never falls through to an untracked direct send when the 018 ledger or AEAD key is temporarily unavailable.

Existing non-API bank service-edit subjects, manual owner-approved subjects, and other funding methods still have their independent delivery contracts. Do not claim general end-to-end recovery for these subjects from this slice.

A delivery packet is immutable. Per-ordinal mutable states:

| State | Meaning | Automatic replay |
| --- | --- | --- |
| pending | DB committed; no dispatch attempt claimed | Allowed via CAS |
| dispatching | Lease committed before Telegram request | **Never** |
| acknowledged | Positive transport ACK received and saved | **Never** |
| unknown | Failure, incomplete ACK or operator-observed stale lease | **Never** |

The attempt table retains each dispatch token, actor, start/result and end. Acknowledged means **Telegram accepted** observed transport calls, not proof the human read the message. A network failure is unknown, **not** "not delivered". A sendQrFromLink containing multiple Telegram messages is one logical packet: any uncertain sub-send makes the packet unknown; an operator resend may duplicate accepted sub-messages.

A fresh terminal API callback can resume only pending packets, never money, capacity or remote operations. Independent per-ordinal history handles partial bulk delivery. No new account-count ceiling was introduced.

## Owner workbench

From Payments requiring review, open the payment case and choose Customer delivery. This button appears only if an 018 delivery record exists and only to the current Owner. Each ordinal can be Checked without external provider requests. A Check also marks a dispatching lease older than 10 minutes unknown and records its attempt result; it does not infer actual receipt.

For eligible pending/unknown rows, choose Request send, read the duplicate-delivery warning, and confirm Send. A time-limited HMAC challenge binds Owner, payment, ordinal, state and attempt count. Under locked rechecks, the encrypted packet, payment/attempt identity and native/order provenance must match. Any mismatch blocks sending. The Owner button causes a delivery-only Telegram call, never external payment/provider mutation. Ambiguous ACK stays unknown.

The inbox is quiet: no new unsolicited delivery-recovery admin push.

## Forward migration 018: blocked until separate Owner approval

The source-only CLI payment-readiness tool now blocks for an absent, partial or
index-incompatible 018 ledger, in addition to its previous 001–009 checks.
A passing read-only schema inspection is NOT proof that migration happened or
that installed Chili has been authorized to run it.


File: migrations/post-v9/018_payment_delivery_intents.sql

Before touching Chili, require ALL:
1. Explicit Owner authorization covering target and maintenance window, separately approved even if source PR merges.
2. Verified actual server/schema, active 003, 007, 009, 014 objects (including earlier migrations), indexes, engines and row counts.
3. Backups of schema, data and encryption secrets; **test restore** and safe rollback not deleting newly recorded delivery intents.
4. Apply 018 to an owned staging copy first; reject conflicting existing objects or partial CREATE. Verify FKs, engines, encryption, and no historical backfill.
5. Deploy only exact reviewed source after independent review and staging smoke tests. Monitor ledger and Telegram results; source merge does NOT apply SQL or deploy Chili.

The telegramWebhookSecret must be present for existing payment-evidence AEAD. Back it up securely; a lost key prevents safe decryption. Never log or expose encrypted payloads or destinations in public issues/logs.

## Source tests

- tools/test_post_v9_bank_buy.py: owned MariaDB socket, synthetic native and Telegram responses; additions cover atomic encrypted manifests, crash-after-COMMIT recovery, per-account bulk ACK/unknown, invalid Owner and stale challenge, stale lease, corrupted envelope and concurrent pending claim. Execute on PHP 8.5 only.
- API native/flow fixtures migrate 018 on disposable setups. All eight PHP 8.5 CI families remain the required exact-HEAD gate.
- Python syntax checks or CI on a different tree do NOT prove current PHP 8.5 correctness or deployed acceptance.

## Remaining Issue #117 work

This package does NOT close #117. Separately verify installed Chili backups/migration/deployment and Owner acceptance; native CREATE uncertainty/replacement policies; the five-provider financial review workbench; bank SMS exact-ID GET contract; legacy/unbackfilled paid history; and consistent durable delivery for non-API service-edit/manual approvals with their own provider/effect invariants. No real payment, provider mutation, production migration or real Telegram send is authorized by this source package.

## 2026-11-07 owner clarification — quiet finance workbench

**Controlling product rule:** Payment review is an **admin-initiated** list, not
an automatic message. Legacy scheduled NOWPayments and automatic-renewal
review push functions now return without Telegram, notifications, or lease
mutations. Existing **business outcome reports** (actual purchase/subscription
details, renewal, wallet credit, manual receipt approval/rejection) must continue
through their existing report channel. No generic approved-payment push or
already-finalized review message is added.

Payment review shows nonterminal payments only if an authenticated provider
child indicates incoming money, a positive NextPay/Zarinpal settlement hold is
recorded, a saved manual receipt awaits human review, or the API evidence
ledger has proven evidence. A waiting/expired/unfunded invoice, a paid/declined
terminal row, native CREATE uncertainty, and Telegram-delivery uncertainty
must **not** be re-labelled as a fresh payment awaiting approval.

Native account recovery and customer delivery each have separate Owner-only
workbenches. Review decisions already recorded in the 019 ledger show their
audit/status rather than offering another irreversible approval. The pending
delivery workbench lists only existing 018 intents awaiting ACK or inspection.
Historical records without an encrypted delivery intent are not fabricated.

### Quiet financial decisions and continuations — existing migration 019

- **NOWPayments:** signed original IPN children provide the incoming-funds
  evidence for the Owner's direct Approve/Reject. The callback records a unique
  journal decision and then invokes the original once-only `doAction` path.
  It performs **no mandatory merchant GET or second operator confirmation**.
  Owner attestation is marked `operator_attested`, not a forged provider
  `finished` outcome; rejecting an unfinished order is **not** a merchant
  refund. A secondary funded child stays a separate financial exception.
- **NextPay / Zarinpal:** an inconclusive hosted VERIFY (including transport
  uncertainty) on an exact, previously issued provider-scoped `003` invoice can
  save `verification_uncertain` on the same attempt. This is an unproven claim,
  **not evidence that the merchant received money**. It does not debit, credit,
  provision, notify or require another gateway verification. The Owner may
  inspect the original invoice, price, provider reference and status and make
  one direct decision. The existing **019 unique journal** records this as a
  human choice and the original hosted `doAction` path enforces the same pays
  CAS and attempt identity, including if a genuine verified browser callback
  wins the race. A successful human-attested business effect closes the 003
  attempt with local `operator_fulfilled` (or `operator_compensated`) status,
  **not merchant `finished`**. Genuine authenticated merchant-finished evidence
  retains its own provider-completed status; actor and decision remain in 019.
  Rejection closes an eligible unfinished business order only;
  no refund is inferred. If 019 is unavailable no new undecidable hosted
  review entry is staged. A positive verified hosted charge followed by
  uncertain native execution belongs to **serviceRecoveryInbox**, not
  financial review.
- **API bank SMS / gift card:** if the existing `014` evidence is already
  proven, the ordinary financial/service coordinator
  `achbotApiPaymentSettle` can be resumed from the same case; this makes no
  fresh provider verification request and reuses the already pinned identity,
  source proof, native effect and delivery journals. Proven cases that have
  already reached `processing` appear in the existing technical-recovery
  view (not as new unpaid financial cases). The button is a display-only
  hint; source admission and native-effect journals are always rechecked.
  Completed actions return to the inbox rather than a stale case link.
  A still-valid callback for an already terminal payment may continue **only
  PENDING** original customer-delivery intents; UNKNOWN messages are never
  automatically resent. The admin result says delivery completed only when
  every existing intent is ACKed. Otherwise it explicitly reports financially
  settled with customer delivery not fully acknowledged, even if the original
  coordinator returns `settled`. Original human receipt Approve/Reject callbacks remain available when
  their usual prerequisites apply. No new staff role or payment
  authorization was created.
- **Hosted wallet finalization:** when a later genuinely verified hosted
  callback completes the original atomic wallet claim, the matching 003
  attempt is marked done, including a previously uncertain VERIFY; this
  never credits the wallet a second time. An operator-owned 019 claim remains
  journal-controlled instead of being mistaken for provider settlement.
- **Recovery boundaries:** the original decision journal fences double
  approvals; an uncertain native CREATE/PUT is not replayed merely because a
  human presses the same button again. Verified native service and pending-only
  Telegram delivery continue under the existing specialized queues, with
  unknown delivery left explicitly unknown. None of these operations triggers
  unsolicited payment-review notifications.

Migration `019_payment_review_decisions.sql` remains **source-only and
unapplied** on Chili unless independently authorized, backed up, restored in
staging and deployed under the separate Owner/CI/review gates. This source
change does not grant live settlement, release or installation authority.

Scope remaining for Issue #117: verified provider/native readback for the
remaining unknown hosted service-edit and bulk cases, source-real subject-by-
subject acceptance, legacy/unbackfilled financial evidence and installed Chili
acceptance. Never describe a source-only PR as deployed or complete for all
possible failure paths.

### Isolated proof

- tools/test_owner_r6_financial_alert_ack.py: owned MariaDB proves no automatic
  finance/renewal review Telegram fanout or financial/lease changes.
- tools/test_post_v9_review_decisions.py: owned MariaDB and original decision
  source, 019 uniqueness, short decision token binding, valid finished,
  negative identity/amount/confirmed, provider refund, duplicate approve,
  ambiguous remote no replay, concurrent real Cron operator-fence, and queue
  separation for completed, unfunded and positively evidenced cases.
- Existing finance route suite tests unchanged provider/API outcomes and
  original purchase copy; retired notification expectations now test no push.
- Do **not** treat PHP 8.4 as current-runtime validation. Required acceptance
  remains the exact candidate's full PHP 8.5 CI.

### Technical service queue after separating financial review

A current Owner-private `bankReviewRead_<pay>_<ordinal>_<cursor>` Check now
routes the **original paid BUY_SUB CREATE line** by its saved native provider:
Marzban retains its pinned login/account GET; PasarGuard uses a single
read-only by-username GET or, where the original remote incarnation is pinned,
a by-ID GET. The original operation marker, paid owner/plan/server/ordinal,
reserved entitlement, provider instance, target and Group snapshot must match.
A second local read fences concurrent drift. A missing line, 404, mismatched
account, changed target or unavailable provider never authorizes another POST,
payment settlement, capacity adjustment or Telegram send. A matching remote
observation is **not** delivery ACK or proof of received merchant funds.
Existing manual/bank continuations still own actual once-only finalization;
this Check itself never changes a native/payment/order state. It works per
account for partial bulk without assuming that unrecorded ordinals are absent.

A paid transaction can have an unresolved native CREATE/service edit or
reserved automatic renewal without being an unapproved payment. The owner
now has a **separate** read-only serviceRecoveryInbox with status-specific
serviceRecoveryCase_* evidence and links to existing pinned BUY_SUB native
readback. It also surfaces nonterminal processing/processing_wallet claims
whose expected journal is missing (diagnostic only, not proof of funds).

Rows with completed payment and no concrete technical hold do **not** appear;
customer-delivery unknown/pending stays in deliveryInbox, and genuine
unfinalized incoming money stays in attentionInbox. No service queue view
performs CREATE, PUT, DELETE, wallet mutation, provider request, or Telegram
review alert. The operator cannot replay a remote effect by opening a row.

Acceptance: tools/test_next_payment_ux.py asserts that manual receipt
is visible only in financial review, completed payments are excluded, and
the automatic renewal/native effect/processing-wallet holds remain visible
in the technical queue with owner-only, zero-remote, zero-mutation readback.

The four new catalog topics are separately source-SHA-pinned and their
historical ancestors are preserved. The archived unsolicited-review strings
remain in the editable catalog with exact historical source provenance but
cannot be referenced by new Cron or owner decisions.

## PR #122 independent review remediation — 2026-11-07 (source only)

The original HEAD 9f4b6113b76b51666ab9ad79fc18814b30e5d6a2
received independent HIGH_ASSURANCE / CHANGES_REQUIRED; exact-HEAD
PHP 8.5 CI success did **not** approve Merge. The three REQUIRED findings
are addressed in the subsequent remediation candidate, which itself
requires new exact-HEAD PHP 8.5 CI and independent delta review.

**R1 — no provider I/O under financial locks.** achbotNpDecisionCommit
now captures a SQL-local, immutable quote/subject fingerprint *before* any
potentially networked eligibility preflight, rechecks it after the quote,
and checks it again inside the locked decision transaction. Only SQL-local
table reads are permitted after InnoDB locks are acquired. The fingerprint
includes the bound pay, logical server, plan, order, provider effect and every
physical row sharing the provider's logical provider_connection_id (not a
fictitious server_config.id equal to that logical ID). A changed local
target or payment fails closed. The native mutation still has its own final
admission/readback. tools/test_post_v9_review_pg_lock.py drives the **real**
PasarGuard quote and HTTP GET on a controlled loopback endpoint with real
separate MariaDB sessions; its concurrent local pay/plan updates finish
without waiting on financial GET-held locks, and both drift cases reject
the original decision without a provider write.

**R2 — secondary NOWPayments funds.** Every authenticated child remains
durably recorded in payment_transactions. A new funded child may mark a
still-unclaimed pending original for review, but **never rewrites** a paid,
terminal, owner-dispatching or fulfilling primary. The independent,
owner-private, quiet financeExtraInbox reads additional funded-child
evidence even when the original pay is already completed. Its case view
shows child identifiers/statuses and links to bounded official child inquiry;
it does not provide Approve/Reject for the paid original, invoke doAction,
send an unsolicited review notice, or create a new schema. Merchant-funds
reconciliation/refund remains a separate documented financial operation.
The test suites exercise signed IPN interleavings before/after owner claim,
out-of-order/repeated receipts, paid-wallet protection and owner-only cases.

**R3 — original manual receipt final presentation.** A shared source-real
achbotManualFinalReceiptKeyboard fetches the **committed** human approver,
original hash and payment state, and uses the same three-row terminal,
non-financial callback contract as API approval. The second row is the
actual subject: issued subscription identity, bound service renewal/add-on
or wallet payment, not an arbitrary notice/operational navigation button.
Standard, custom, RENEW_SCONFIG, RENEW_ACCOUNT, manual day/volume and wallet
approval routes edit the original stored receipt and authorized
admins_messages copies in place. Existing normal customer/business outcome
reports remain; no separate payment-review notice is added. The real
manual payment route suite captures original editKeys keys/message IDs
and checks the actual actor, subject, hash and no-op callbacks.

**Boundaries:** Neither 018 nor 019 has been applied to Chili. No live
NOWPayments, Marzban, PasarGuard, bank, SMS, gift, Telegram, installed Chili
or production database operation was exercised. Live provider behavior,
full multi-provider/manual approval, historically missing Telegram packets
and uncertain native CREATE recovery remain Issue #117 acceptance work.
Source-only tests or a green CI must never be described as production
readiness or as authority to merge/deploy. A new Owner decision after an
independent APPROVE is required for Merge; migration/deploy need separate
authorization and verified backup/restore.


## 2026-11-07 Owner decision — one-click attestation and safe resume (post-PR #122)

The newer Owner contract is recorded in Issue #117 comment #6701412070 and
**supersedes** the two-step merchant-GET requirement in the historical 019
section above **for current human decisions only**. The automatic merchant
settlement policy is unchanged and still requires verified provider finality.

An authenticated Owner's Approve/Reject is the action, not a request to
re-query the merchant. Source implementation in the **new, separately
reviewable development candidate** makes the existing NOWPayments decision
callback use local signed child evidence and a unique audited pay-scoped
019 decision without another merchant GET or extra confirmation click.
Provider status is recorded as `operator_attested` or `operator_rejected`,
never falsified as merchant `finished`/`refunded`. Stored financial evidence
survives a human business rejection. Local payment/subject identity, exact
quoted amount, current pay stage, existing remote effects, and duplicate
claims remain fenced; only the original one-shot business-effect path runs.
The primary invoice's additional funded children remain separate financial
exceptions, never second wallet credits or service CREATE operations. The
old two-step signed callback is rejected as stale after upgrade.

**Current source-only LIMIT:** Human decision for NextPay, Zarinpal and API
bank/gift remains to be unified. More importantly, this first slice DOES NOT
yet implement automatic safe resume of a previously remote-started `processing`
CREATE, renewal, day or volume effect. The current one-shot journal retains
an ambiguous outcome without replaying money/native effects; a follow-up
package must complete state- and ordinal-keyed native readback/attach and
report/Telegram delivery convergence. An unedited Telegram button alone
MUST NOT authorize a second native mutation. Neither this development
candidate nor prior PR #122 is installed on Chili, and 018/019 remain unapplied.

### 2026-11-07 standard manual BUY_SUB continuation candidate (PR #123, post-owner direct decision)

**Source-only work, separately gated:** an original manually reviewed standard
BUY_SUB receipt now needs the 018 delivery schema and encryption key **before**
claiming payment/capacity or attempting native CREATE. The original human
confirmation stays the only financial decision; native network reads during
subsequent processing are readback of the **already claimed account**, never
another merchant payment inquiry.

- The first click claims `have_sent -> processing` with the existing one-shot
  007 `manual_hold`. Each paid line has exactly one `service_effects` tuple
  `(pay_id, create, ordinal)` and one native `operation_id`/target/username.
- If a line is already `dispatching`/`verified`, a second click uses the
  original pinned native identity. Marzban requires strict HTTP login and
  account GET with matching exact operation note, status, quota, expiry, URL
  and no physical target drift. PasarGuard requires original native incarnation,
  note, Group IDs and exact entitlements through read-only GET. Matching
  evidence can complete only **local** verification, not another remote POST.
  Missing, changed or unavailable remote evidence is a resumable *hold*.
- All locally verified lines must be linked and the immutable customer
  QR/text/keyboard packets sealed in 018 **inside the same transaction** as
  the original final `pays.approved` claim. A late SQL error can roll back
  this finalization without losing the already pinned remote effect.
- Only after this COMMIT may Telegram delivery begin. A duplicate approval
  click after `approved` dispatches **only `pending`** customer packets and
  unattempted original receipt/report notices. Neither a staff callback nor
  the Owner's repeat approval authorizes resending an `unknown` Telegram ACK.
  The Owner can inspect each original packet in the separate, private
  `deliveryInbox`, read its duplicate-delivery warning and explicitly confirm
  a time-limited sealed-packet resend under locked provenance checks. No
  financial, capacity or native operation is performed by this recovery.
- The historical `RENEW_SCONFIG` and uncertain Marzban additive
  day/volume/renewal routes are **not** covered by this one-shot native
  CREATE journal. PasarGuard manual RENEW_ACCOUNT/day/volume claims can
  finish locally only with the positive original operation-owned verified
  journal implemented by #125. The follow-up proposal uses existing sealed
  020 `approved_edit` intents for post-COMMIT receipts/notices and never
  resends UNKNOWN Telegram ACKs. Unverified native mutations remain fenced
  under `processing`; no second remote PUT is allowed. The bank/API
  `auto` BUY_SUB completion and its 018 delivery records are distinct.

Real PHP 8.5 regression candidates live in `test_post_v9_manual_payments.py`
(original receipt, one-shot capacity, single/multiple account ordering,
Telegram ambiguity and duplicate Owner callback) and
`test_post_v9_recovery_transport.py` (actual credential-bound localhost
login/GET, no remote re-POST, local verification of precisely one original
native `service_effects` row). Neither proves installed Chili acceptance.

### Direct manual receipt rejection

The ordinary «Reject» admin callback for bank/gift/manual-crypto receipts
commits rejection immediately using the **existing** `have_sent -> declined`
compare-and-swap; it does not request an extra reason entry or merchant
provider GET. The same existing customer rejection and truthful administrative
business-result report are staged atomically in 020 with the financial
transition. Each recipient is attempted separately after COMMIT; an ambiguous
Telegram ACK remains `unknown` and is **not** a claim of completed delivery.
A button clicked after `processing` or a terminal outcome
cannot erase payment or undo a remote service operation. Old reason-editor
messages from the previous installed version are still accepted only when
the original payment is unfulfilled; a replayed editor cannot reject twice.

### 2026-11-07 independent review remediation delta — DRAFT ONLY

HIGH_ASSURANCE review of PR #123 at `7a8942aab7b83720f641b871c835fe520c903a27`
returned **CHANGES_REQUIRED** for five distinct issues. Remediation source
adds forward-only, separate Owner-gated `020_payment_followup_intents.sql`.
The original manual BUY_SUB orders, referral decision (008), immutable
customer delivery (018), payment and all independent purchase-report
destinations (020) must commit in one transaction. The eligible referral
credit and its actual-balance notifications commit in their own atomic
transaction, using the original frozen award identity. Repeated Approve
resumes only never-attempted PENDING reports and paid delivery; UNKNOWN
Telegram deliveries need a separate explicit Owner warning/confirmation.
The original manual Reject similarly commits its full customer/staff/receipt
notification manifest atomically with `have_sent -> declined`. Repeated legacy
references to the **same** chat/message ID create one intent, but distinct
historical admin messages remain independent; duplicates never roll back the
financial outcome. One failing Telegram edit does not prevent independent
notifications. Once an outbox
recipient has been attempted, an ambiguous ACK is UNKNOWN and never
authorizes an automatic repeated message. Human-rejected NOWPayments primary
funds remain visible in the Owner-private financial exceptions inbox until
actual positive merchant refund evidence is recorded; business rejection
never fabricates a refund nor reopens the rejected order. PasarGuard source
verification additionally requires strict by-username lookup with real
localhost HTTP and negative identity/entitlement cases.

**Do not run 020 or deploy Chili until exact candidate CI, independent
remediation review, migration audit/backup and TESTED restore, and separate
Owner authorization succeed.** Older source-only 018/019 migration plans
remain forward-only and do not backfill historical untracked notifications.

## 2026-11-08 — Original-wallet committed-native recovery (source only)

An Owner-private `serviceRecoveryCase_<pay>` page offers a wallet continuation
**only** for a `processing_wallet` BUY_SUB with its original consumed
`wallet_hold`, a **full and contiguous** original CREATE-effect manifest, and
no competing 003 payment attempt. Original CREATE lines can be either
unlinked (`dispatching`/`verified`) or already linked to genuine local orders
(`local_recorded`). Linked orders must still match the exact customer,
plan, server, account, allocated price, order status and PasarGuard 012
incarnation **and the current server-to-original physical panel binding**.
Missing CREATE ordinals, forged/conflicting linked orders,
unknown funding and changed native/account pins remain in the workbench for
separate, evidence-based operator reconciliation.

The `walletBuyResume_<pay>` callback checks Owner/private `review` authority,
then performs one exact original native GET for **every** stored CREATE effect
(Marzban or PasarGuard), never POST. The `existingOnly` gate forbids falling
back to CREATE on an absent ordinal. The additional linked-read permission is
restricted to this original `processing_wallet` recovery; ordinary
bank/manual/API continuation cannot use it. Matching native identity and
entitlement can verify a previously `dispatching` effect; a linked original
account must already have valid, unchanged local and native evidence.

Only after **all** original ordinals are independently proven, one InnoDB
transaction retains every previously linked original order **unchanged**,
creates and links **only** missing local orders, records one `provider=wallet`
**local debit provenance** (not merchant settlement) in 003, encrypts the
original immutable 018 customer packets for **all** ordinals, and transitions
`pays` from `processing_wallet` to `paid_with_wallet`. Original linked
order data and effect/order IDs are pinned before remote GET and rechecked
under SQL locks at finalization. For previously linked PasarGuard accounts,
the original 012 mutation key, snapshot, state and evidence-update generation
are also pinned: a separately completed revoke/edit or unresolved native
mutation after the GET cannot finalize a stale customer packet. Current
server-to-panel identity is checked before GET and again using current
server_config data within the final locked transaction. The recovery never
debits the wallet, reserves capacity, re-creates accounts or sends Telegram
inside the transaction. After COMMIT it dispatches **only pending** packets. Unknown/dispatching delivery
remains held; Owner uses the warned Delivery Recovery flow for intentional
resends, never an automatic replay.

The initial PR #129 admitted only all-unlinked originals; PR #130 extends it
to already/partly linked **complete** manifests. This is source-only, with no
new SQL migration. It does not claim missing native operations, conflicting
original orders, unstarted held debit, merchant-hosted uncertainty or
installed Chili acceptance. Do not deploy or run live recovery without
separate target approval, compatible 003/007/018 audit and restore-tested backup.

### Source-only original Hosted paid delivery and partial-link recovery

The Owner-private technical case may offer `hostedBuyResume_<pay>` for an
**existing** NextPay/Zarinpal `BUY_SUB` whose original financial claim is
`pays.state=paid`, original 003 attempt is `review/fulfillment_started`, and
every original 007 CREATE ordinal **already present** is contiguous, tied
to its first ordinal's full-count capacity reservation and unchanged physical
provider. Original accounts may already be linked to their exact original
orders or may still be `dispatching` / `verified` with no order link.
Any missing ordinal must be a **trailing** unstarted line: 007's mandatory
committed-before-POST reservation proves no source-driven CREATE was ever
dispatched for that ordinal. A gap, a missing first capacity owner, a present
`dispatching` row or any unjournaled historical creation is NOT an
unstarted original account and is never re-POSTed.

The exact immutable payment reference, fixed Toman quote, recipient,
plan/server, original 007 operation/target, current physical provider
identity and 019 absence of Owner attestation must all match. Previously
linked orders retain their immutable order IDs, amount and Native identity;
PasarGuard 012 mutation generations and provider incarnation are checked.
An existing 018 intent prohibits reconstruction and cannot be silently
replaced.

An authorized Owner continuation first performs authenticated read-only
Marzban/PasarGuard GET for **each recorded original** CREATE operation;
unknown, mismatched, foreign or retargeted remote accounts are never
adopted. Previously `dispatching` originals may become locally `verified`
only on matching readback.

When exactly one or more **trailing** original ordinals were never reserved,
the explicitly labeled Owner action may issue **one first-time 007 CREATE**
per click, only after successful preceding native readback, immutable payment
identity, original reservation/target and original plan volume/days checks.
The 007 source transaction MUST commit a unique original ordinal before any
provider POST. A lost POST response becomes its same original
`dispatching` row and is GET-only on every subsequent callback, not an
invitation to issue a replacement or re-reserve capacity. If more unstarted
ordinals remain, the Owner sees a clear next-action status with no Telegram
delivery. A final ordinal must pass another complete original readback,
followed by the terminal atomic local order/delivery closure.

For a now **complete** original manifest, in one terminal SQL transaction the original linked
orders remain unchanged, **only verified original accounts with no local
order receive their first local order and 007 link**, and every 018 immutable
encrypted customer packet is sealed. The same COMMIT closes the 003
*service fulfillment* attempt as `done/delivered`, which does **not**
assert a new merchant settlement or a Telegram ACK. No repeated provider CREATE, wallet debit, payment settlement, capacity
claim or replacement is performed. Only a genuinely never-reserved trailing
original ordinal can receive its **first** provider CREATE under an
explicit Owner action. A rollback undoes every new local link/order and packet while
preserving original verified native operations. After COMMIT only PENDING
Telegram packets are sent; `unknown`/`dispatching` are never automatically
retried.

Missing first ordinal/invalid tail gaps, orphan/foreign/conflicting
identities, missing financial provenance, Owner-approved replacement, and
already sealed 018 remain outside this bounded action and demand independent,
evidence-based reconciliation. No historical paid row is automatically backfilled.
Source-real PHP 8.5 / disposable MariaDB + loopback acceptance is mandatory;
installed Chili, 018/019/020 migrations, backup/restore and real
provider/Telegram acceptance remain separately gated and **UNVERIFIED**.

### 2026-11-08 independent-review remediation for PR #129

The Owner callback for a **successfully** recovered wallet purchase now
returns to `serviceRecoveryInbox`: a terminal `paid_with_wallet` record is no
longer eligible for `serviceRecoveryCase_<pay>`. An unresolved wallet case
retains the original case Back destination. The existing delivery case remains
a separate valid Owner-only destination.

The source-real funding family also exercises **actual** wallet debit/capacity
claim, original PasarGuard CREATE through a disposable 127.0.0.1 HTTP server,
then source-real by-username/by-ID native GET, original 007 proof, single- and
complete two-account 003/018 COMMIT, two distinct encrypted delivery packets,
and transactional rollback on late SQL failure. Owner/private Callback routing
is tested with denied actors/chats and verified post-success keyboard actions.
These tests prohibit a second HTTP POST during recovery and use no real user,
merchant, installed Chili or native provider. Real installed acceptance remains
separately gated.

### Subsequent source-only 021 paid reconciliation and staff authority (PR #133)

This source candidate extends the previous **original-only, Owner-gated**
recovery paragraphs above without changing their historical 007/018
invariants. `serviceRecoveryInbox` and `serviceRecoveryCase_*` can now be read
by current, private-chat `review` administrators; `walletBuyResume_*`,
`hostedBuyResume_*`, `hostedReplP_*` and `hostedReplC_*` require the existing
`payments` permission. This includes configured Manager and Operator (also
Support where payment approval is currently authorized), **not** generic
Native/server administration. Provider-creating actions and terminal local
COMMIT independently recheck current persisted permissions under SQL locks.
`deliveryInbox` and other Owner-only specialist tools remain unchanged.

The distinct 021 replacement operation never rewrites or repeats a previous
original 007 CREATE. The operator must inspect actual original native evidence,
then explicitly accept a short-lived, actor-bound confirmation warning. A
new replacement gets its own randomly unique native username, committed
reservation and operation marker before the first POST. For new invoices the
actual original standard/custom days and GB are pinned inside draft issuance
in 021-added nullable `pays` fields. For old standard invoices, surviving
original 007 records can independently prove original terms. When both are
absent the next CREATE is denied before reserving capacity; the CURRENT plan
cannot be substituted for historically purchased dimensions. The prior
standard/custom `pays.day/volume` sentinel semantics are unchanged. Unknown/failed responses are GET-only, never another POST.
A surviving first original capacity owner is reused; truly empty original 007
history can reserve its first quantity only once. If the original first 007
row was lost but later originals survived, **every** later 007 Native identity
must be confirmed by strict GET before an explicit replacement can use its
provably previous capacity with **no second inventory debit**. A forged,
foreign, changed, or unreachable surviving account fails closed. A recovered
replacement can use the existing atomic original 003 + 018 order/delivery
closure; original and replacement histories remain inspectable.

Migration `021_paid_reconciliation_provenance.sql` is **not included** in the
source ZIP, and neither this source increment nor its CI automatically applies
018/019/020/021 or authorizes actual Chili, merchant, native, or Telegram
operations. A separate Owner-approved, backup/restore-tested staging and
production process is required. The 021 extra durable evidence fields store only bounded machine status
and fingerprints, not raw provider responses. The short-lived private staff
confirmation also pins the current physical Marzban or PasarGuard target and
provider generation. Each error exposes only safe reason, stage and next
operator action. The prior free-trial and admin-bulk 007 CREATE deletion
capacity return remains valid; external imports never gain capacity.

### Scheduled first-attempt delivery for terminal committed packets

Source-only Issue #117 follow-up adds a narrow Cron service-worker sweep
on the existing migration 018 table. It is **not** an automatic native or
payment reconciliation loop. Only immutable, previously sealed Telegram
packets under terminal confirmed `pays`, with `state=pending`,
`attempt_count=0` and at least two minutes of quiet time, are eligible.
The ordinary locked `achbotDeliveryDispatch` verifies the exact original
payment, delivery, native effect, local order and decrypted immutable packet
before claiming one attempt. Default sweep: four records / eight seconds
soft wall-time per tick; a single Telegram transport can outlast the budget.
A preflight-deferring row rotates its existing `updated_at` queue timestamp,
without changing financial state or declaring Telegram attempted. A lost
Telegram ACK remains `unknown`; it is **never** retried from Cron.
`dispatching`, `unknown`, manual warned redelivery and provider uncertainty
stay separate. No new migration, source ZIP SQL, or live activation is implied.
