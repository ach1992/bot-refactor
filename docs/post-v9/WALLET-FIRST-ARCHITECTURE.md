# Wallet-first payment and service architecture

Current implementation contract. Receipt proof credits one Wallet ledger;
funded services and transport effects have separate durable identities.
PasarGuard is the only shipped Native adapter, not an architectural
restriction. The [product contract](../PROJECT-SPEC.md) governs business
policy and permissions. Source integration, release and live cutover are
separate gates; see [Issue #204](https://github.com/ach1992/bot-refactor/issues/204).

## Cutover decision — controlled manual database transfer (Owner, 2026-11-24)

The runtime is a clean replacement requiring the new schema. Automated
historical migration/import, dual-write, old-schema mode, and old financial
executors are **not** product requirements. The owner approved manual transfer
from each old installation into a separately prepared new-schema database.
Existing offline rehearsal/audit helpers are not part of the runtime and do
not authorize production actions.

Before a separately approved transfer, stop all old writers and schedulers,
take and restore-test a full backup, snapshot every user's exact integer
balance and active Native identity/entitlement, preserve configuration,
orders, receipts, and pending/unknown/terminal evidence. Close old
interactive workflows, stale requests and unissued drafts. They must not
become executable current commands. Before activation, verify that historical
unfinished broadcast/forward (`send_list`) and message-action (`bc_jobs`) jobs
are not executable in the replacement; retain history in the protected archive.
Reconcile actual funds, already charged
orders, reserved capacity and uncertain Native effects explicitly in the
separate manual transfer; retain the evidence in the protected source archive.
Never invent proof, replay a terminal operation, or charge an already
debited operation again. Unknown claims stay isolated and documented until
reconciled. Verify
per-user balances (including zero and debt), unique user identity, original
payment references, Native ownership and reserved capacity before activating
the new runtime. Retain the old database read-only; rollback is only safe
before new live effects, otherwise reconcile and roll forward.

The [Owner's fresh-runtime decision](https://github.com/ach1992/bot-refactor/issues/204#issuecomment-7133896850)
retires old callbacks, pending-invoice execution and automated historical
adoption. The current draft version marker and immutable issued-receipt pin
are mandatory. Historical NULL/unsealed rows are evidence, not executable
commands. A funded current paid service uses its own original Wallet debit.

An opening ledger entry represents each reconciled existing user's exact
balance, including zero or intentional debt. Verify unique user identity and
individual balances, not merely aggregate totals. Do not replay old movements
to derive that opening balance or merge different identities. Any unresolved
historical payment, reserved capacity or Native effect is reconciled outside
the new executor. Follow [financial reconciliation #26](https://github.com/ach1992/bot-refactor/issues/26)
and [Vivan historical evidence #157](https://github.com/ach1992/bot-refactor/issues/157).
The [installed acceptance work item #183](https://github.com/ach1992/bot-refactor/issues/183)
owns the actual per-bot cutover, backup/restore evidence, activation and smoke
approval. Check retrievability of authentic merchant evidence received during
maintenance; never run old and new writers concurrently.

## Domain ownership and extension boundaries

| Owner | Inputs | Output | Must not do |
|---|---|---|---|
| Payments | immutable invoice, provider evidence, authenticated manual decision | confirmed receipt, credit transaction | Native calls, plan eligibility, service dispatch, referral reward |
| Wallet | authenticated domain command, stable source identity, integer Toman movement | durable ledger entry and balance | payment proof/HTTP, plan, delivery |
| Services | immutable quote/request, Wallet funding, local target, Provider capabilities | completed original operation and durable result | choose/verify payment method, use payment-specific recovery |
| Panel | Native identity, immutable request/target and single attempt | classified authoritative response | read payment provider, payment type/actor, set financial state |
| Delivery | committed source identity and intended payload | acknowledged/known failure/unknown | repeat money or service |
| Scheduler / UX | due operation or event, current authorization | invoke domain command, show projection | own a second settlement or service implementation |

All HTTP, Telegram and Cron routes call the same domain commands. Current
source-owned registries select actual adapters; no dynamic code loading or
second financial/service runtime. Keep the shared dependencies explicit and
do not add parallel orchestration layers.

### Provider and payment-method extension boundaries

Owner clarification (2026-11-24): adding a future Native panel, payment gateway
or payment method must not require another implementation of service execution,
Wallet settlement, delivery or recovery. PasarGuard is the only Native adapter
shipped now, not a hardcoded provider in the shared engine. Keep a
source-owned, explicitly registered adapter boundary; no dynamic code loading,
fake compatibility adapters or generalized plugin framework.

- Native: select the adapter by the actual configured provider identity;
  keep account incarnation, physical-panel identity, provider-specific plan
  settings, naming limits, authentication/transport, unit conversion,
  readback and result classification in that adapter. The service engine
  owns original commands, immutable quotes, funding, scheduling, operation
  order, local finality and delivery. Admit only operations supported by the
  selected implemented adapter and actual configuration. Unknown/partial
  adapters must fail before funding or a remote effect. Pin the selected
  provider and target; do not silently switch on retry. The current registry is the extension point. Nonpayment reservation resolves
  and locks the
  physical connection through the registered target adapter before effect-range
  reads. Its normalized `key` and `connection_id` feed the shared allocator;
  provider-private snapshots and key construction remain adapter-owned.
  The registered username validator preserves the local name/storage contract
  together with the provider's actual naming constraints.
- Payment: register source-owned method/gateway definitions and their
  issuer, verification/proof, callback/polling and configuration/UI metadata
  as needed. Manual confirmation, evidence APIs and hosted gateways have
  different actual proof rules; keep those rules inside their adapters.
  All use one local invoice identity, evidence consumption, credit and
  optional service-funding transaction. Receipt validity is independent of
  Native availability. A new method changes its adapter, registration,
  method-specific configuration/UI and tests, not Wallet arithmetic or
  service dispatch. No arbitrary provider/method ENUM or cross-product
  switch tables in shared storage/engine; retain real field/protocol limits,
  stable method identifiers and merchant-account-scoped evidence.
- Acceptance: add a test-only registered Native adapter and payment method
  in isolated source fixtures, using the actual common admission/credit/
  funding commands and nonpayment reservation. Prove extension by
  adapter/registration only, including refusal of unregistered methods,
  a distinct physical name namespace without a PasarGuard snapshot, and
  no duplicate financial effect. Such fixtures do not claim another
  production provider is implemented. Later
  actual adapters require source-real protocol and failure-path tests.

## Wallet and receipt ownership

Use existing `pays`, `payment_attempts`, `payment_transactions` as the external
invoice/evidence owners; new external payment issuance is a funding invoice,
not the authority for a Native mutation. A checkout can refer to an optional
original service operation; it must not be required for credit. Original amount,
currency quote, invoice/payment identities, human actor or provider proof are
pinned. Scope merchant references by actual receiving provider account where
needed; preserve existing uniqueness and consumption evidence. For multiple
callbacks/children of one settled invoice use one credit source keyed by its
local invoice ID. One evidence item cannot fund two invoices. Manual decisions
require currently authorized staff, exact original invoice and genuine proof;
retry of the same approval returns the result, not another movement. Rejected,
unpaid, expired invoice and late actually received payment are distinct;
expiry/cancellation of a service request cannot erase authenticated received
money. Historical archived/ambiguous claims require explicit migration
classification; never reinterpret Archive as payment proof or refund.

The single `wallet_entries` journal uses a unique
`(source_kind, source_ref, leg)`, exact user row/Telegram identity, signed
integer Toman delta, before/after balances, original actor and time. Financial
amounts are integers, never floats. Keep `users.wallet` as the synchronized
current balance projection, not a second independent writer. Preserve
the signed INT range of the actual column; widen only as an audited schema
change if needed. Use locking current reads on the uniquely resolved user,
check exact replay identity and return the original entry even if current
balance has since moved. Conflicting reuse is an invariant violation.
Caller owns one local transaction: receipt/proof claim + movement + receipt
finality (and durable funding continuation) commit together. Likewise operation
funding + one debit + capacity claim when needed; no network within SQL locks.
Use a consistent lock order, deterministic user order for transfers; audit
inversion against every migrated route. Commit ACK lost => reread unique
persisted source, never infer rollback. The current schema uniquely identifies each userid. Reject duplicate source
identities before transfer; never silently merge distinct users.

Movement sources: opening balance, payment credit, service debit, service
compensation, transfer (two atomic legs), purchase referral, onboarding
referral, authorized admin adjustment. The `wallet_adjustments` and `referral_awards` domain evidence is retained;
current monetary effects use the common journal; never dual-write balances. Zero-cost service
is explicitly funded at zero (no fake payment/credit). Credit can reduce
existing debt; ordinary debit cannot deepen debt. The current authorized
administrator-debt policy is unchanged. Refund/compensation refers to the
original debit/line, once only; never compensate an uncertain external write
as if proven absent. Apply the original funded-operation resolution policy; no arbitrary new caps or expiry.

## Original service operations

The durable `service_operations` header is independent of payment attempts:
stable ID, unique original command namespace/reference (Telegram update,
scheduled cycle), user, kind, fixed quote
(price, quantity/plan, units, provider/panel/account identity, policy snapshot),
funding/debit reference, stage, due-at/attempt metadata, and last classified
failure. A mutable menu is not the quote authority. New orders are BUY, RENEW,
ADD_VOLUME, ADD_TIME. RENEW operates on an existing local PasarGuard
subscription; Marzban-only external RENEW_SCONFIG is retired.
A recurring renewal has one unique account/cycle command; no separate debit
or renewal executor. Preserve manual and auto renewal timing semantics.

Use `service_effects` CREATE/physical identity rows for each original
ordinal using source_kind/source_ref + ordinal rather than coupling to pay_row_id.
Preserve existing unique operation ID, username/physical target, linked order,
provider instance, marker and remote incarnation (id/created_at where supported).
For new edits keep one before/target/dispatch/result journal per operation,
with exact physical identity. A prepared observation may be refreshed until
the first possible Native dispatch; then the intent is immutable. Do not use an ever-growing consumed_keys JSON
or fixed 4096 limit: a durable unique operation record prevents replay. Preserve historical consumption evidence offline; do not replay old claims. Account-level ownership
must cover paid, free, gift, change, deletion and other conflicting local
mutations and all aliases of the physical subscription, not just order_id.
Independent databases managing the same Native user are outside one local
transaction boundary. Reuse existing configured ownership and shared physical
identity within AChBot; diagnose actual conflicting ownership rather than add
a cross-installation service, require a new provider coordination API, or
pretend local locks cover external writers.

The operation and effect journals distinguish awaiting funds, funded work,
sealed preparation, possible dispatch, verified application and local
completion/resolution. Their actual persisted state transitions are owned by
`src/Services/Execution.php`, `Funding.php` and `Resolution.php`, not inferred
from an error string or a Telegram response. A completed operation is never
reopened for message delivery. Partial BUY retains each completed ordinal;
cancellation and any remaining compensation commit together.

Retry/wait are scheduling metadata (reason, dependency, next attempt),
not an independent state that discards the previous phase. Use due-time/indexed
fair work selection, small local transaction claims and recoverable leases.
Serialize conflicting bot-owned writes to the same physical account while the
original operation is unresolved, using the existing API recovery contract
below. A stale worker must not finalize another worker's claim. Do not make an
unavailable remote fencing API or proof of perpetual remote quiescence a
prerequisite for ordinary work.
No arbitrary global lock or per-order operator confirmation for a known safe
retry. Keep payment verification and service processing independently progressable.

Checkout: fund and enqueue the original service operation using the common
Wallet transaction. Credit receipt independently even if service can no
longer be admitted; no losing money on Native/plan/capacity failure. If funding
is possible, reserve/debit it for that original request without a concurrent
wallet spend stealing it. Retain current visible payment amount choices until
approved otherwise; optional future mixed/shortfall checkout is not needed for
this refactor. For an invalidated/cancelled request keep the received value
available according to accepted cancellation policy, never silently fund a
different plan. Use immutable original amount for any subsequent debit.

## Native execution and recovery

All operations use the same coordinator and typed Adapter outcomes:
known success, definite non-application/rejection, unavailable/transient,
outcome uncertain, identity/entitlement conflict. Capture useful provider
HTTP/reason/identity evidence; distinguish authentication/authority failure,
valid not-found, malformed response, rate limit and connection failure.
Do not treat 404/409 indiscriminately as safe retry.

**BUY:** reserve capacity exactly once, persist original naming/username,
ordinal and marker before POST. Before creating a new effect, resolve a local
generated-name collision within the existing five-digit naming shape on the
same physical target; explicit single names are never changed. Paid BUY and
nonpayment creation share the allocator. Local import and first CREATE
reservation serialize on the same current physical-panel rows, across
logical aliases. Both use the originating-account occupancy predicate,
independent of the import effect's `target_key`. Attachments are not separate
physical claims; deleted imported incarnations without active local references
do not permanently exclude a name. Deleted incarnations with active references
remain a conflict, and the existing CREATE target-key uniqueness fence is
preserved. The fresh schema indexes `(provider_connection_id, remote_username)`
for exact and suffix-range occupancy reads. Once reserved, the exact name and
marker are immutable. Exhaustion or an explicit-name conflict is classified;
a foreign remote identity is not a reason to allocate another name.
Use authenticated GET by exact username/identity;
adopt only matching original account incarnation and entitlement. For
PasarGuard the reviewed upstream has unique username, by-username and by-id
GET, and note marker; same-name original POST and 409->GET can converge under
tested provider semantics. Never generate a new username as an uncertain-write
fallback or recreate an account already proven created and later deleted.
Foreign same-name account is a real conflict, not evidence of success.
Multi-account only executes missing original ordinals. Complete local
Order/intent exactly once per account.

**RENEW:** exact original account, reset usage and set fixed absolute plan
quota and expiry. Freeze renewal period anchor at the first valid dispatch
preparation (preserve accepted renewal policy; do not recalculate every retry).
Owner accepts repeated reset during unresolved original full renewal, not
after terminal success and not another debit/audit/invoice. Record sub-step
results; valid reset ACK plus subsequent traffic is not a reset failure.
No repeated reset once verified merely because local SQL or delivery failed.
Keep disabled/account/group/business rules; do not unintentionally enable or
change groups. Test reset succeeded/PUT failed, expired/limited, unlimited
supported renewal, lost ACK and real traffic progress.

**ADD_VOLUME / ADD_TIME:** calculate once from fresh authenticated state
after claiming physical-account ownership. Persist target data_limit or expiry
(in exact Native units, with original expired-account arithmetic policy).
Use an absolute set of only the necessary field(s) and no reset; preserve
other entitlement, identity, group and administrative status. GET first on
retry: if target observed and original proof matches, finalize; if original
baseline confirmed unchanged, retry the same fixed target under the existing
API recovery contract below, not another delta; divergent state requires
actual reconciliation, not overwrite or second relative increment. Next
operation calculates its target after the previous operation completes.
No repeated DAY/VOLUME arithmetic on retry.

Existing-API recovery contract (Owner clarification, 2026-11-24): keep the
smallest practical bot-side coordination. Persist immutable targets and
original identity, serialize conflicting local writes, classify real API
responses, use authenticated readback after ambiguous outcomes and retry
recoverable original operations only under their original semantics. Never
start another money movement, relative increment, new CREATE identity or
terminal renewal/reset because of a timeout or local finalization/delivery
failure. A genuine identity/entitlement conflict or insufficient evidence for
an affected mutation gets a specific diagnosis; a normal timeout/outage is not
by itself a permanent manual-review condition. Use only capabilities actually
exposed by the existing provider API. Do not require provider modifications,
new remote coordination components, arbitrary wait-as-proof, speculative
version gates or indefinite holds merely because a stronger hypothetical API
does not exist.

Same-value PUT is idempotent in isolation, not an atomic compare-and-set
against concurrent writers. A delayed older request can arrive after a later
update; a local lock or readback cannot fence the remote server. Exactly-once
local funding/finality remains mandatory; do not promise exactly-once physical
execution or prove remote quiescence from HTTP status, pre-write GET or elapsed
time. Regression coverage must characterize delayed stale PUT, competing writers,
delete/recreate, foreign identity, API scope drift and account aliases.
Detect/reconcile genuine divergence using current authorized observations and
original operation evidence; do not blindly reassert completed entitlements
over legitimate later mutations. Record any residual limitation of the
existing API and the actual recovery behavior in final independent review
and Owner acceptance. Absence of a stronger remote API is not a blanket
development or release prerequisite; unresolved material correctness defects
must still be disclosed and resolved or explicitly accepted before cutover.
[PasarGuard reviewed source](https://github.com/PasarGuard/panel/blob/eba70d68eaa5a89e34ffebb47c8fd5ce054d0e43/app/db/crud/user.py#L1254-L1317);
[Koinon external-effect contract](https://github.com/AChWorks/koinon/blob/main/docs/contracts/external-effect-safety.md).
Do not version-gate adapters based on the reviewed reference commit.

## Recovery and terminal disposition

The approved recovery contract is recorded in [Issue #204](https://github.com/ach1992/bot-refactor/issues/204#issuecomment-7135092291). A new operation observes the actual Native entitlement; a completed prior operation is not an entitlement precondition. While an operation is in flight, only its owned fields, physical identity, and applicable authorization are frozen for replay/readback. Unrelated Native changes do not invalidate an otherwise valid add-on.

The common service attention surface is keyed by service operation, including scheduled renewals with no payment row. Retry continues the original operation immediately without another debit. Closing without a refund is available only for edits. Cancellation/refund is an explicit terminal decision: BUY retains each successfully recorded ordinal and its delivery intent, and returns only the unfulfilled original allocation through the common wallet ledger. Each ordinal is finalized before attempting the next. No refund may conceal an existing unrecorded Native account; reconcile it first. An uncertain outcome requires an explicit operator assertion based on external verification, not an assumption of failure. Remote requests are not guaranteed to be quiescent merely because a local request timed out. Automatic renewal refund disables automatic renewal until deliberately enabled again.

Resolution, refund entry, capacity release, physical ownership release and reports are committed together. No archive action or state remains for receipts or services. Unverified receipts use explicit approval/rejection or evidence recheck; unresolved funded operations remain visible until an actual resolution. Subsequent verified receipts may credit the wallet but must not resurrect a resolved operation. Delivery and report recovery reuses immutable payloads, with a fresh actor-bound confirmation for an unknown send.

Physical lifecycle changes (plan, transfer and deletion) use the same
service ownership decision. Funded or unfinished Native work holds the
account; an unpaid or independently verified receipt does not own Native.
A verified subject change retires only current awaiting-funds operations and
sealed current checkout drafts bound to that exact subject. Preserve
historical unsealed rows, receipt proof and ledger history unchanged.
Subsequent authentic proof credits the original Wallet without reviving
the retired service. The canonical indexed subject locking read remains
scoped to the affected orders; no old pay/attempt stage classifier or
second scheduled-deletion ownership engine is required.

Payment evidence, service-effect table and shared plan journal are mandatory current storage; both receipt archive columns are retired. Missing schema cannot authorize a mutation. Only current fresh-install plan intent format 2 is executable; earlier plans are not replayed. Its immutable target identifies the connection instance, endpoint and Native account, not replaceable credentials.

## Payment proof and independent delivery

Bank SMS matches actual source, amount, time/reference and one-time consumption;
missing receipt waits, API outage retries; ambiguous genuine evidence is
not a manufactured payment. Gift verifies/redeems with original code/reference;
recover uncertain redemption only from authenticated evidence, no blind second
redeem. Manual methods retain authorized receipt approval/rejection. Hosted
NOWPayments/NextPay/Zarinpal preserve exact merchant finality and issued quote,
callback authentication/verification and provider transaction identity.
All adapters call the same credit command, never CREATE/RENEW.
API proof may succeed while panel is down; receipt still credits exactly once.
No time-only payment proof. Every receipt requires authenticated evidence
or authorized staff confirmation.

Receipt submission and service validity are separate. A receipt already
reserved as receiving may finish or recover its original evidence and
Telegram reference even if the service is superseded concurrently. Its
superseded financial state is retained: verified funds credit Wallet once
and never revive the invalidated service. A superseded invoice without
an original receiving claim cannot start new evidence submission. Typed
gift-code storage and receiving reservation are one locked transaction;
failure rolls back both.

Configured receipt cooldowns are evaluated on current admitted manual
receipts and actual manual credit basis. They do not turn historical
states, automatic settlements or original evidence recovery into new
manual-payment restrictions. Polling and message-projection scheduling
rotate pre-claim failures and malformed current contexts without deleting
evidence, asserting success or imposing lifetime retry limits. Historical
unpinned records are not executable projection work. Bank SMS search
advances the existing cyclic cursor across both exact amount/unit
filters, with a bounded per-tick page budget; it never treats a long
result set as permanent proof failure. A demonstrably unsupported
external API filter contract uses the bounded unfiltered lookup, subject
to the same original proof and uniqueness checks. Hosted polling
derives its single-reference and child-transaction selectors from the
validated receipt registry.


The shared delivery and followup journals own independent transport effects. New delivery identity is stable source (payment credit or service
operation or verified nonpaid effect) plus purpose/ordinal/audience as needed.
Payment and Native evidence remain with their original owners. The current
customer delivery journal does not carry old pay/attempt columns or require a
fake external attempt. Complete service and create durable first delivery in the
same local commit. Render existing catalog/packet without changing native
config bytes; send outside transaction. Known failures retry; ACK loss remains
unknown (Telegram send does not provide server-side client idempotency).
Use existing message edit target when valid; preserve explicit original
delivery retry policy until Owner approves changed duplicate-message behavior.
Customer can view the completed same subscription independently of delivery.
No new money/native action on repeated retrieval. Referral/reports follow
completed purchase/credit exactly once with existing eligibility policy.

## Reporting and sales projections

All original positive Wallet movements stage primary owner, configured report
channel and actual human actor notices, deduplicated with optional authorized
staff recipients. Automatic confirmation has no fabricated human actor. A
report identifies the original receipt/operation and ledger entry, method,
amount, before/after and disposition. A credit used immediately for a service
does not become a misleading top-up notification. The sealed service-completion
report remains independent of customer delivery. Newly staged followup
intents are immediately due; actual retry deadlines and recipient
eligibility still govern dispatch. Presentation of Wallet ledger time is
Asia/Tehran; stored UTC evidence and fingerprints remain unchanged.
Issue #236 owns the remaining logical-report consolidation work; a change
in presentation must not conflate independent financial, service and
Telegram delivery evidence.

Verified trial, administrator bulk, import, direct/scheduled gift, plan change,
transfer and deletion use the same operational followup source, not fake
payments. Paid and nonpaid CREATE customer packets are durably bound to their
actual operation/effect and original order. Gift notices are bound to the
actual subscription attachment. A changed/removed attachment is terminally
skipped; transient failures defer. Current multipart delivery and followup
attempts preserve original source and recipient, known rejection versus unknown
ACK, rate-limit recovery, and explicit original-packet redrive. Large bulk and
partial-retained reports use bounded sealed continuation pages; compaction can
only replace untouched packets, never an attempted message. Interrupted
compaction leaves the individual verified creation reports deliverable.

Missing/invalid channel configuration does not veto valid financial effects.
The configured rewardChannel is resolved to the actual chat; no redirect of
sealed packets after settings/permission changes. A definitively ineligible
recipient is terminally skipped, without invented acknowledgement or later
automatic reactivation. An incomplete authorization read defers; other
recipients continue. Unknown/rejected deliveries retain attempt evidence;
explicit redrive revalidates the original recipient. No report exposes private
receipt images, credentials or subscription links in public summaries.

`src/Finance/ServiceHistory.php` owns completed commercial sales and buyer
history. One completed original operation counts once (bulk is not many
sales); value is the verified debit less its original compensation, with
zero for a legitimate free command. Cancelled operations are not sales;
partially retained BUY counts only retained value. Automatic renewals count
once without a synthetic receipt. Server/plan attribution uses the frozen
original quote; date windows use original request/command time. Historical
unbound terminal non-top-up payment evidence retains reporting value but is
not executable. Wallet-origin sales distinguish direct Wallet checkout/auto
renewal from externally funded service commands. Incoming payment sums,
successful payment counts and stored-order inventory remain separate measures.
Abandoned purchase counts distinct users with expired/cancelled BUY requests
and no completed original purchase.

## Source boundaries and validation

No legacy compatibility executor or old callback replay. Only current
fresh-schema journals and registered adapters authorize effects. The
consolidated schema and numbered SQL are described in [fresh installation](FRESH-INSTALL.md).
Keep financial/service/Native/Telegram evidence separate and use private
reason/correlation logs. Local once-only funding does not prove remote
exactly-once or remote quiescence.

[CI validation](CI-VALIDATION.md) owns the current automated gate; the
[operator inbox](AUTOMATIC-GATEWAY-REVIEW.md) owns the actionable disposition
surface. [Project spec](../PROJECT-SPEC.md) owns user-facing policy; [payment
UX](PAYMENT-UX-LIFECYCLE.md), [Native API](PASARGUARD-CONTRACT.md) and
[service management](UNIFIED-SERVICE-MANAGEMENT.md) own specialized contracts.
[Issue #204](https://github.com/ach1992/bot-refactor/issues/204) owns outstanding
delivery, not a second implementation roadmap. The approved implementation
and historical design trail are available in [PR #234](https://github.com/ach1992/bot-refactor/pull/234)
and its Git history.
