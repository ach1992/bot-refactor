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

### NOWPayments operator decisions — additive migration 019

- **Owner only, current private chat, signed short-lived two-step challenge.**
  Each approval performs one fresh authenticated provider GET and requires
  exact invoice/order/payment ID identity, quoted USD amount, merchant
  settlement evidence and provider status **finished**. A locally observed
  confirmed/sending/partially_paid result is **not** authority to settle.
  A refusal requires provider **refunded** and cannot override completed orders.
  A second positively funded child blocks treating one child as the invoice.
- Insert a unique, pay-scoped decision audit and claim attempt
  operator_verified_finished in **one InnoDB transaction** before running
  the original doAction effect path. The standard customer/business
  output is preserved, including the report channel. Cron must not turn
  this operator-owned fulfilling claim back into review.
- If the process exits after the journal COMMIT, the status remains
  dispatching (unknown until reconciled). An ambiguous provider/native or
  Telegram outcome stays unknown; **never auto-repeat** the same financial,
  remote CREATE or customer-delivery effect. Duplicate callbacks are rejected.
- The provider's refunded result may be closed as a rejected payment, with
  the final customer state and normal report. Unknown/short-funded/multiple
  paid children are *not* blindly rejected or credited.
- Migration 019_payment_review_decisions.sql is **source-only and unapplied**,
  additionally required by read-only preflight. Explicit backup, staging
  restore, separate Owner migration authorization, and exact source/CI/
  independent review gates apply as to 018.

Scope remaining for Issue #117: a genuine multi-provider override with
explicit human attestation/reference/reason for verified positive funds when
provider is not finished, cross-provider manual decisions beyond NOWPayments,
full native Marzban/PasarGuard readback/replacement workflow, and controlled
Chili acceptance. Do not describe this source slice as deployed or approved.

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
- Local Dev PHP 8.4 can catch defects early but **required acceptance remains
  the exact candidate's full PHP 8.5 CI**.
