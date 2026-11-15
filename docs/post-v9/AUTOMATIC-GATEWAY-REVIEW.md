# Shared payment and service exception inbox — operator contract

> Source contract for the next reviewed AChBot release. **Source changes do
> not mean any installed Chili/Hell/Vivan bot has been updated.** Production
> activation and any schema migration require separate Owner approval. The
> historic hosted-only 022 release behavior remains available in Git history.

## One inbox and original-case ownership

The existing admin entry **💳 پرداخت‌های نیازمند بررسی** is the only active
operator attention list. It does not create a second payment, another ledger,
or a second recovery dashboard. The list reads original `pays`,
`payment_attempts`, `payment_transactions`, `service_effects`,
`payment_delivery_intents`, and `payment_followup_intents` when their
original schema is available. No normal, unpaid, aging checkout enters the
list on age alone. Completed cases with no unresolved effect or delivery exit
automatically; no operator Archive is needed after genuine completion.

Admitted cases include: hosted NOWPayments/NextPay/Zarinpal payment evidence
or operator-eligible transport failures; bank/gift **API-pinned** proved
but unfinished payment/service or genuinely delivered manual staff review;
manual bank/gift/USDT/TRX orders with started and unfinished local service
effects; and completed payments across hosted/manual methods with outstanding
original customer or staff notification packets. A terminal financial
operation with `PENDING` or `UNKNOWN` delivery remains reviewable but is
never replayed as another payment or Native operation. Missing optional
API/delivery schema must never invent financial evidence or make a
hosted-only read fail.

Opening a case presents original subject/customer/amount/ids, locally
observed provider status and service-effect history, with the relevant API
evidence/fulfillment status only when that invoice was API-pinned. The same
case can be opened by a real button on the **existing API receipt**; a dead
"نیازمند بررسی مدیر" noop must not be its only way to act. Background polling
does not send duplicate standalone administrator messages.

## Contextual, unchanged original actions

- **Approve/Reject** for hosted gateway exceptions retain their existing
  eligibility, original decision CAS, exact invoice, provider identity and
  refund constraints. A refund is never implied by a rejection.
- **Approve/Reject + reject reason** for an unverified manual bank/gift
  receipt remain the **existing manual callbacks**, not a new hosted-like
  operator attestation. The original receipt and reports are preserved.
- **🔄 بررسی و بازیابی عملیات** for a bank/gift API case is shown only
  with the existing authenticated, pinned `settle` proof and one-shot
  original coordinator; `dispatched/unknown` Native work is read-only and
  never blindly retried. A terminal original with pending delivery may only
  dispatch previously unattempted `PENDING` packets.
- **NOWPayments amount_mismatch** left by the older second crypto
  amount-check gate may be recovered after an authenticated merchant GET
  proves `finished` for the **exact original** payment, invoice, hash,
  and local order amount. It uses the original human settlement and
  `doAction` once-only pathway. Never synthesize provider children, refill
  wallets or call Native twice. The ordinary automatic reconciler accepts
  authenticated merchant-final `finished` without independent crypto
  deposit/payout thresholds; non-final `partially_paid` still does not
  auto-fulfill.
- If there is no safe action beyond review, show why; do not manufacture a
  recovery button or require a payment decision before Archive.

## 📦 بایگانی — terminal LOCAL operator stop

An authorized payments administrator in a private chat may choose Archive
directly even when no Approve/Reject/Recover is eligible. This lets an
operator stop the **original bot-owned payment/service continuation** after
handling the customer independently, without entering an external/manual
quota scenario in bot logic.

Archive records `payment_attempts.review_archived_at` and
`review_archived_by` exactly once under the original payment/attempt
InnoDB locks. It does **not** settle, reject, refund, credit, mutate Native,
rewrite provider observations, delete history or mark Telegram delivered.
The archived case exits the live inbox; old callbacks cannot reopen it.
An authentic late NOWPayments IPN may still preserve signed child evidence
without changing the archived original.

The original `achbotHostedWorkerLockName` per-database/per-payment MariaDB
session lock is shared with API SMS/gift processing and fulfillment.
Human manual approval of Native service work also holds that same session
lease through the entire webhook; a completed SQL claim alone does not prove
that the corresponding remote operation has finished. Archive
returns **busy** when a genuine original worker is active rather than
claiming to cancel an already-dispatched Native or Telegram operation.
Original financial/API proof, Native service-effect admission, and first
customer/staff delivery-fence checks must refuse new work after Archive.
Previously unknown remote results remain unknown. New independent customer
purchases/renewals and original financial history are unaffected.

There is **no automatic detection, reconciliation or compensation of manual
Native quota edits**. An external operator action cannot silently make an
unfinished original bot payment "already delivered". Only the operator's
explicit terminal Archive stops bot followup on that basis.

## Operator checks and release boundary

Every action rechecks permissions, private chat, original payment and target,
evidence and local/remote once-only journals. Synthetic acceptance covers
active-vs-archived scopes, normal pending exclusion, API and manual cases,
human/merchant finality, terminal delivery holds, concurrency and stale
buttons. A green source check is not installed acceptance.

Use current additive migrations `003`, `014` (optional bank/gift API),
`018` (delivery), `020` (followups) and `022` (Archive) as applicable
to the target's **existing migration state**. This source package introduces
no new migration and never auto-runs DDL. Do not rerun existing migrations,
change live webhook/Cron or test real financial/provider operations under
a source-only approval. Independent review, Owner-authorized merge/release,
and each exact installation's backup/restore and acceptance are separate gates.
