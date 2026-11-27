# Payment checkout and message lifecycle

The shared Wallet-first [architecture](WALLET-FIRST-ARCHITECTURE.md)
owns receipt, funding, service and delivery finality. This guide owns
checkout/message projection and the merchant configuration workflow.

## Customer journey and projection

- All registered methods use the same original owned draft, current
  eligibility and immutable issued quote. Checkout, recharge and renewal
  do not depend on a second executor. The common keyboard preserves the
  approved labels and order without amount suffixes. Show the amount in
  the checkout body, including time and volume add-ons; recharge never
  offers Wallet self-payment.
  A legitimately zero-priced commercial command has its own free
  confirmation and needs no monetary method or external invoice.
- One manual checkout route handles all six payment subjects. Method selection
  uses pm_b_/pm_g_ and the shared crypto network selector; rs_ starts an
  original receipt and rc_ cancels its current acknowledged prompt.
  The subject/method come from the owned persisted invoice, not the callback.
  The receipt step has a generation and exact bot prompt ID; validation
  sends a new corrective reply below the human input and retires the
  previous bot prompt only on exact acknowledgement of its replacement.
  A concurrent changed step cannot authorize evidence.
  Invoice Home (ph_) preserves the retained invoice. Draft cancellation
  (dc_) retires a selector, not a financial claim. Hosted methods, including
  renewal, use the same guarded URL directly.
  Initial and status receipt actions share the admitted receipt type. The pinned invoice evidence
  profile, not later method settings, determines gift image versus API gift
  code input. Bank and crypto retain their own approved labels; closed
  invoices offer original-payment review without a new payment link.
  Starting a manual receipt flow must not delete the original invoice.
  After durable receipt admission, the acknowledged receipt status takes
  ownership of the customer view; obsolete payment instructions are retired.
  Receipt admission does not imply service or delivery completion.
- Owned gateway initiation URLs re-check the original payment and
  authoritative current draft; public Return and authenticated merchant
  evidence are separate. Raw historical external links cannot be revoked
  from Telegram; do not claim provider cancellation from a local expiry.
- Issued receipt, Wallet credit, funded-service outcome and customer
  delivery are distinct facts. Current registered message references and
  projection scheduling edit actual original invoice/staff messages to
  show truthful next actions. Failed edits do not reverse funds or service,
  and never trigger a second invoice or Native action. Known
  `message is not modified` acknowledges the intended edit, not a new send.
- Submitted evidence can survive supersession of the original service.
  Genuine late verified funds credit Wallet once, without reviving the
  retired command. No archive action or state, old callback recovery or
  legacy pending-workflow replay. Use the [operator inbox](AUTOMATIC-GATEWAY-REVIEW.md).
- Current manual receipt cooldowns use admitted receipts and actual
  manual approval basis; automatic settlements and historical states do
  not create new restrictions. See [API evidence](API-PAYMENT-EVIDENCE.md)
  for API evidence and original-receipt recovery. Keep user-facing changes
  within the approved product contract.

## Message ownership and recovery

`payment_message_refs` is the shared payment projection registry, with an explicit
semantic purpose (`selector`, `invoice`, `status`, `receipt`, `receipt_view`, `staff`) and exact
owned chat/message identity. The existing payment session lease serializes
message promotion, navigation retirement and projection. No database
transaction is held across a Telegram edit. Selector retirement checks
positive absence of original financial evidence and commits under the same
original pay row lock as browser admission. Navigation does not cancel
an external invoice or change the payment state.

A Wallet top-up draft and its committed editor reference are written in the same
transaction. Telegram delivery is a separate acknowledged fact. A fresh private
menu action or matching old amount callback can recover that exact unissued draft
without inserting a second one; the same update cannot retry an unknown send.
The original exact prompt is retired only after a new selector is acknowledged.
A completed editor is not a cancellation. A selector with no eligible payment
method states that fact rather than inviting an impossible choice.

Manual issuance promotes the existing selector to the invoice in place.
A committed original whose selector was not promoted is recovered by the
same projector. A stale method or crypto network click reconciles an
already admitted original rather than reviving its selection keyboard.
Other selectors are status views, not additional invoices.
An acknowledged receipt status (`receipt_view`) is the single mutable
customer view for admitted evidence. The original receipt acknowledgement
journal can establish its identity. Customer-authored input, staff evidence,
and independent result notices remain separate. After receipt admission the
old instructions are retired by verified identity; if deletion is not
acknowledged, the same obsolete target may be sanitized in place without
a replacement send, respecting rate limits. Never delete the human receipt. Closed invoices
preserve original financial facts in storage, not obsolete destinations in
customer messages. Hosted gateways project the original guarded payment URL
only while actionable. The same renderer serves explicit status requests
and automatic projection; a separate Wallet-credit or service-delivery
notice remains independently idempotent.

A retired navigation reference is not a successful edit and cannot become a
payment target again. Definite Telegram missing/non-editable responses retire
that reference. Unknown outcomes do not authorize replacement sends; the
existing due time and Telegram retry_after govern edits, including direct
status clicks. A definite target rejection can permit one explicit
replacement. Status requests from retained evidence use the acknowledged receipt status
first, otherwise the active invoice or status view when available; a new explicit request has a durable
update cursor before a new send. No repeated processing of the same update
may blindly resend an unknown result.

A staff projection reference is created only from an exact original
receipt target and is never a customer selector or invoice. Each
recipient/message has its own acknowledged digest, known-missing
retirement and retry deadline; a failed or removed staff message does
not force repeated edits of healthy recipients. Recheck current staff
authorization and the original payment state under the existing target
lease. Unknown and rate-limited results are not acknowledgements.
A successful evidence search without a match records normal monitoring.
A failed pre-consumption provider read with no usable evidence records
`unavailable` in the existing attempt evidence state; a later successful
no-match read restores monitoring. The existing retry/backoff and human
review remain available. An original journaled external operation retains
its own reserved/dispatched/uncertain/review or proven evidence instead of
being downgraded by an unavailable read; an exception without a completed
observation retains the last durable state. No new payment owner or schema
is involved. Normal API monitoring is distinct from exceptional review. The established
3/30-minute waiting guidance remains unchanged in ordinary receipt waiting
views; invalid, uncertain and final outcomes use their own truthful status. Staff retain
currently admissible manual actions and the actual review-case link. A persisted
external evidence operation fences the negative decision; the renderer and
server-side rejection guard share this predicate. Delayed first staff delivery
uses current controls rather than obsolete ones.
Historical aggregate staff digests do not acknowledge a new per-target
projection. No schema expansion, duplicate receipt report or new
worker is needed.

A recorded Wallet debit or receipt credit is not proof of delivery. Use the
original command and service completion evidence to distinguish pending
service, completed delivery, completed service with unconfirmed delivery,
Wallet credit only, verified resolution with or without returned funds, and
unverified outcomes. An actual rejection updates the receipt view immediately;
its reason and retry instruction remain a separate meaningful notice. Service
reports group the original credit and debit by verified Wallet entries. A shared
opening/closing balance and net change are rendered only when the same owner
has no intervening entry and the balances connect exactly. Otherwise each
entry retains its own balance pair. No sealed financial evidence is rewritten.

## Existing-instance transfer boundary

The current consolidated schema is for empty installation. Construction
script 051 refuses existing message references or former API invoice fields;
it is not a live upgrade procedure. For a separately authorized existing
instance transfer, preserve the source snapshot, original pay/attempt
identity, financial evidence, staff receipt IDs and delivery history. Map a
known API invoice chat/message pair to the same owned customer reference,
deduplicate by exact chat/message, and classify a reference only with
positive evidence of its purpose. Preserve ambiguous references as retired
non-projectable identities or in the protected transfer audit, not as guessed
active invoices. Preserve the original message data in the source backup.
Verify ownership, counts, collisions and financial evidence before activation;
an old digest is not acknowledgement of new presentation. The new runtime
does not carry the retired API fields or a compatibility projector.

## Invoice deadlines and late payment

The administrator's payment policy controls new invoices. The default
unissued draft is 30 minutes; manual invoices (including API-assisted
bank and gift receipts) and hosted initiation links are six hours.
The draft deadline is pinned at draft creation, and the original invoice
expiry is pinned at issuance rather than recalculated from subsequent
configuration. A first receipt click before expiry can grant
15 minutes of upload time from that click, without shortening the
original window, extending the transfer window or renewing the grace on
later clicks. The grace and post-expiry SMS search duration are configurable.
A local deadline closes only an unfunded request without recorded evidence;
it never cancels an external payment or declares it unsuccessful. Issued
hosted links use the original guarded checkout route and expose the pinned
deadline. An expired or superseded link cannot initiate another merchant
invoice; the original authenticated reconciliation and staff review remain
available. A closed current draft positively proven never admitted into
payment or service execution is different: show a closed selection notice
and Home, not order review. Missing or inconsistent evidence cannot prove
nonadmission. The shared absence predicate checks original payment/receipt
fields, attempts, service commands and effects, Wallet credit, referral
and follow-up journals. It also guards new admission and draft expiry;
contradictory facts keep the recovery action and do not promise a credit
without an identified invoice. Bank instructions use the persisted invoice deadline once,
not a hardcoded duration. All customer invoice and status references use
the same projection owner, including API-assisted invoices.

Late evidence is admitted against the same immutable issued invoice.
Verified funds for an expired or superseded service credit Wallet once;
the closed command is not revived. Already recorded evidence and funded
service work retain their original recovery path. Time alone cannot
approve a bank receipt: authenticated API evidence or an authorized
operator decision is required. Historical unsealed invoices are not
reissued. See [API evidence](API-PAYMENT-EVIDENCE.md) for the separate
transfer-time and SMS-search windows.

## Merchant credential and destination editor

The administrator `PAYMENT_KEYS` editor configures methods; it does not settle payments.
From `درگاه‌ها`, select the desired credential, bank-account/holder, gift
seller HTTPS URL, or independent USDT BEP20/TRC20/ERC20 and TRX TRON
destination or exchange rate. An authenticated **private Telegram callback**
opens a field-specific wizard; a new signed private text message saves the
input, and **Cancel** leaves the stored value unchanged. Invalid URLs,
network-incompatible addresses and rates show Persian correction hints
without overwriting the active setting. `/clear` is supported for the
manual crypto destination and rate fields already permitting it.

The editor checks current primary/delegated administrator identity, role,
ban status, exact owned wizard step, source identity and monotonic Telegram
`update_id` on every action. It never trusts cached `userInfo` or
`paymentKeys` to authorize a write. The historical `setting.type` has
no unique constraint: the writer takes a named lock, verifies exactly one
`PAYMENT_KEYS` document (or creates one), locks the current administrator
PK **before** setting PKs, and commits the field-scoped settings JSON,
`users.step` and the separate
`ACHBOT_ADMIN_PAYMENT_KEYS_SOURCE` watermark together. A source marker
stores per-field revision numbers, and a webhook-secret-keyed HMAC
of the previewed value and revision is stored in the wizard; a stale editor
cannot overwrite a concurrent modification, even if another administrator
restores the original value (ABA). Different-field edits can both succeed.
Malformed, duplicate, revoked, unsigned, replayed or unknown-commit
operations return **no success confirmation**, with reason-code-only
administrative logging. No private field value or submitted credential is
echoed into logs, Telegram management menus or success feedback.

Only `configured` / `missing` indicators are shown for
**NOWPayments, Zarinpal and NextPay hosted merchant credentials**.
The public bank account, holder and valid manual-chain destinations/rates
remain displayed for the operator where needed. The settings menu itself
requires a current signed private admin role check; prior forwarded or
cached-role callbacks cannot expose its public destinations to a revoked
administrator. The chat message containing a newly *typed* secret is
still held by Telegram according to its own service behavior: the source
fix cannot erase previous historic Telegram messages containing exposed
merchant credentials. Any installed historical exposure assessment or
credential rotation is a separate Owner/operator action; no rotation
or production webhook was performed in source acceptance.

## Exchange rates and destinations

`manualUsdtRateToman` is the common USD/Toman rate for every customer,
all three configured USDT networks and hosted NOWPayments. Its accepted
whole-Toman range is 100–1,000,000,000. `manualTrxRateToman` is independent.
Clearing/invalidating a rate prevents only the affected new quotes, not
other usable methods. No agent-only USDT rate, Tetherland/private-global
fallback or inferred 1:1 conversion. Issued attempts keep their exact
original price, rate, asset, network, destination and evidence.

See [gateway rules](GATEWAY-ELIGIBILITY-RULES.md) for audience policy and
[readiness preflight](PAYMENT-READINESS-PREFLIGHT.md) for installed checks.
Use [CI validation](CI-VALIDATION.md) for current route, hosted, manual,
eligibility and editor regressions. Future visible changes need Owner
approval.
