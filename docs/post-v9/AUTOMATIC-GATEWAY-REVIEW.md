# Shared payment and service exception inbox — operator contract

> Source contract integrated by [reviewed PR #189](https://github.com/ach1992/bot-refactor/pull/189). **A Source merge, GitHub release or ZIP copied to
> the Chili/Hell/Vivan directories does not update the installed runtime.** Production
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

The staff recovery entry described here is implemented for **original
bank/gift API-pinned proven claims**; hosted and non-API manual settlement
eligibility remain with their existing financial coordinators and must not
be represented as auto-recoverable until that source path is verified.

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
- **✅ تأیید و تکمیل عملیات** for an unfinished bank/gift API-pinned
  payment keeps the same original `settle` evidence, payment and order. It
  does not require the Native work to be `reserved` or `verified` first:
  `dispatched` is an actionable interrupted state, not an endless dead end.
  If Native may already have been called, staff first sees a **read-only,
  actor-bound confirmation**. The second click rechecks current `payments`
  permission, private chat, archived status, original evidence, invoice
  fingerprint and original worker lease. No new payment, wallet credit,
  capacity reservation or synthetic order is permitted. If the original
  payment/service is already terminal, the same callback is **🔎 بررسی
  پرونده**, for existing `PENDING` Telegram delivery only; `UNKNOWN`
  Telegram ACK remains unconfirmed and cannot be silently redelivered.
- Native recovery is a **provider registry contract**, not a payment-method or
  panel-name switch in the financial/UI code. For original paid **CREATE**,
  adapters first inspect the existing immutable username/operation marker.
  A deliberate staff retry, if no matching completed CREATE can be recovered,
  re-dispatches the **same original effect/ordinal/username**, with no second
  capacity debit. If rearm commits the original CREATE effect to `reserved`
  and PHP stops before dispatch admission, the next authorized staff action
  **first performs the registered provider's authenticated Native observation**
  again. A matching previously created account is reconciled into the same
  original effect with zero additional POSTs. A verified foreign account holds
  for review; if Native is unavailable, only the authorized original staff
  recovery path may attempt reissue under provider duplicate safeguards.
  Original payment/Archive/permission are checked again under the local final
  verification transaction. Unique provider account names remain the remote
  duplicate backstop. For **renewal**,
  repeating the absolute plan/reset is permitted
  automatically on the already-funded original invoice, including a small
  expiry-time difference. For **volume/time extensions**, an automatic retry
  cannot add the purchased units twice. On the **first** paid Marzban
  additive execution, persist the exact baseline, account target and
  calculated absolute entitlement inside the original authenticated
  payment-attempt fulfillment evidence **before Native PUT**. Its recovery
  may recognize an already-applied result or repeat only this same absolute
  operation if Native still matches its original baseline. A historical
  `dispatched` Marzban attempt without a trustworthy captured intent stays
  on the reviewable case; it never invents baseline proof or repeats a
  relative increment. The PasarGuard adapter uses its existing durable
  original mutation snapshot with the same baseline/target reconciliation.
  PasarGuard recovery also carries the expected Native entitlement into the
  provider's final authenticated GET immediately before RESET and PUT. When a
  legitimate RESET has occurred, PUT must match that RESET's verified
  entitlement (including status), not the stale pre-RESET observation.
  Native quota, expiry, status, Groups or account-configuration drift is a
  **reviewable hold**, not permission to overwrite a later panel edit or
  reset usage unexpectedly. A provider without atomic conditional Native
  writes still has an external race after that final GET; never claim a
  cross-provider exactly-once guarantee. Both adapters preserve the same local accounting
  once and the original delivery journal. The same four operation meanings
  apply to any registered provider; future Native adapters must implement
  the registered recovery callbacks and prove equivalent reconciliation
  before accepting paid operations.
- A failed staff retry remains on the original case and logs payment, actor,
  effect, stage and bounded reason for diagnosis. A successful service readback
  finishes the existing local payment/order and uses the original delivery
  journal; it never interprets success of the Native API alone as confirmed
  Telegram delivery. No arbitrary number of retries is encoded in business
  logic; active same-payment worker leases and exact native identities still
  protect against concurrent or unrelated operations.
- Details, resolved cases, hosted Approve/Reject/Recover/Archive results,
  and the bank/gift API continuation result return through the existing
  **↪️ بازگشت به لیست بررسی** to `attentionInbox`. Only the top-level inbox
  navigates back to `managePanel`. Legacy manual-receipt staff actions remain
  their original permission-checked workflows, not a replacement settlement.
- **NOWPayments amount_mismatch** left by the older second crypto
  amount-check gate may be recovered after an authenticated merchant GET
  proves `finished` for the **exact original** payment, invoice, hash,
  immutable original USD quote and local order amount. It uses the original human settlement and
  `doAction` once-only pathway. Never synthesize provider children, refill
  wallets or call Native twice. The ordinary automatic reconciler accepts
  authenticated merchant-final `finished` without independent crypto
  deposit/payout thresholds; non-final `partially_paid` still does not
  auto-fulfill.
- If there is no safe action beyond review, show why; do not manufacture a
  recovery button or require a payment decision before Archive.

## 📨 Explicit customer message redelivery — authorized staff, not a financial retry

When a **terminal** payment/service has an original immutable 018 customer
packet with `UNKNOWN` Telegram acknowledgement and no previous human attempt,
the existing case shows **📨 ارسال مجدد پیام مشتری**. This button is available
to **all staff who actually retain `payments` authorization** for the shared
review screen (Owner, Manager, Operator and Support according to their current
roles); it is not an Owner-only bypass. The action is accepted only from the
operator's own private chat and the current role is checked **again inside**
the original, payment-scoped InnoDB claim transaction.

The first click is **read-only** and displays the original recipient, payment
and packet ordinal, plus a clear warning that the earlier Telegram message may
have reached the customer. It offers **✅ تأیید و ارسال** or
**↪️ بازگشت به پرونده**. No customer send happens on preview or Back.
The confirmation callback carries a short, actor-bound proof of this
specific original packet/version; a forged `Send` callback or a button
forwarded from another administrator cannot bypass the warning. Permission
and payment/effect provenance are checked again at execution under locks.
The Back button uses an ordinary valid Telegram inline keyboard row.

The confirmation sends the **exact originally encrypted and authenticated
018 packet**, including the original text/mode or original QR/caption/keyboard,
not a rebuilt message with changed plan/configuration values. The packet's
payment, attempt, customer, service, order and SHA256 pins are revalidated.
The original per-database/per-payment session lease is held for the entire
send and journal finalization, with no InnoDB transaction spanning HTTP.
The original `payment_delivery_attempts` journal records the current actor,
attempt count and result. A durable once-only test for **any prior human
attempt** prevents a second manual send after success, uncertainty, stale
buttons or simultaneous operator clicks. The existing automatic dispatcher
remains **PENDING-only**. `UNKNOWN` can never be reclassified as successful
delivery without a positive Telegram ACK.

Sending the packet touches **only** its original delivery intent and attempts,
plus the existing aggregate display of receipt status. It does not invoke any
financial coordinator, payment provider, wallet, Native PUT/CREATE, renewal
or quota modification. A successful Telegram ACK means the API accepted the
message, not that the recipient read it. After an ambiguous manual send, no
additional manual resend is offered for that same ordinal; if customer
contact remains necessary, handle it via separately authorized support
outside this original delivery journal. An already archived attempt cannot
be redriven.

The UI button concerns only the **first eligible packet in an open case**;
other original packets remain independently reviewable on subsequent case
visits, without inventing an account-count ceiling.

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
lease through the entire webhook. Independent original 018 customer delivery
and 020 customer/staff follow-up dispatchers hold or borrow that lease across
the durable one-shot claim, outbound Telegram request and acknowledgement
recording, without holding an InnoDB transaction over HTTP. A nested dispatcher
must never release its parent's lease. Archive returns **busy** when a genuine
original worker is active rather than claiming to cancel an already-dispatched
Native or Telegram operation. An already-open manual rejection-reason editor
must recheck the original Archive marker under payment/attempt locks before
consuming the editor or committing any rejection or follow-up.
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
