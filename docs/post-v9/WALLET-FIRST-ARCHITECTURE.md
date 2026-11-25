# Wallet-first payment and service architecture

**Decision:** Owner accepted the Wallet-first separation and a cohesive root-cause
refactor on 2026-11-24. This is the architecture of the replacement source,
not a claim about the published or installed runtime. The owning work item is
[#204](https://github.com/ach1992/bot-refactor/issues/204); current execution and
acceptance evidence belong there and in the implementing PR. Baseline source:
`484c80e6ee030e8656da96edc3000311d10327b5` (Stable v0.10.14).
[Audit evidence](https://github.com/ach1992/bot-refactor/issues/204#issuecomment-7111121827).

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
become executable current commands. Reconcile actual funds, already charged
orders, reserved capacity and uncertain Native effects explicitly in the
separate manual transfer; retain the evidence in the protected source archive.
Never invent proof, replay a terminal operation, or charge an already
debited operation again. Unknown claims stay isolated and documented until
reconciled. Verify
per-user balances (including zero and debt), unique user identity, original
payment references, Native ownership and reserved capacity before activating
the new runtime. Retain the old database read-only; rollback is only safe
before new live effects, otherwise reconcile and roll forward.

Owner's final clarification: [fresh-runtime decision](https://github.com/ach1992/bot-refactor/issues/204#issuecomment-7133896850).
There is no old callback execution or pending-invoice compatibility contract.
Retired callbacks have no compatibility handler or authority to execute. Current draft admission
requires the current version marker in `checkout_draft_context`; historical
NULL drafts cannot create a new invoice or service command. A current
receipt intake requires its issued immutable pin. The only paid service
funding proof is its own wallet debit. The prior historical prepaid service
executor/adoption column are removed from the unpublished candidate.
New deployments use the single empty `database/current-schema.sql` and
need no automated migration or replay. Current retry, idempotency, account
incarnation and actual migrated Native provenance remain essential.
This replaces earlier requirements for automated historical adoption logic.
The target schema, business invariants, source-cleanup acceptance, and
independent review/deployment gates are unchanged.

## 1. Goals and boundaries

Payment adapters verify receipt of value; exactly one common local settlement
credits Wallet. Service operations consume Wallet independently of the payment
route. A service order is not a payment attempt, and a Telegram delivery is not
service or financial finality. Automatic renewal submits the same RENEW as
interactive renewal. An operator is not a substitute for missing recovery code.
Normal valid payments and service operations must self-complete; known temporary
failures retry automatically without arbitrary lifetime/attempt exhaustion.
Manual receipt decisions still establish proof for genuinely manual methods.
A real loss of dependency access, configuration/authority defect, contradictory
authoritative evidence, or internal invariant violation is reported with an
actionable cause to the appropriate operator/technical owner, not a generic
per-payment review queue or permission to fabricate proof. Connectivity recovery
automatically resumes the original work. A genuine irreconcilable external
effect is not made safe by human approval of an unexplained status.

Preserve the current Persian customer/admin copy, pricing, account naming (including
bulk), discounts, agent terms, referral rules, balances including intentional
administrator debt, URLs, credentials, Native identity, active entitlements and
staff permissions. PasarGuard is the sole Native adapter shipped in this
refactor, not a restriction on the service architecture or future providers;
preserve standard/custom single/bulk, managed renewal, add-ons and auto renewal.
Owner clarification (2026-11-24): all current subscriptions are on PasarGuard.
Remove Marzban runtime support completely, including external RENEW_SCONFIG,
rather than maintain a compatibility adapter. No development, patch, fork,
direct database write or new component on either panel; AChBot uses existing
PasarGuard APIs and authorized connection information only. The baseline
[read-only audit](https://github.com/ach1992/bot-refactor/issues/204#issuecomment-7111121827)
found Hell 5 PasarGuard servers, Vivan 4, Chili 0 and no Marzban server entries.
The Owner explicitly permits abandoning unfinished operations whose actual
remaining Native target is Marzban; record a durable retired-provider
disposition without replay or pretending success, and do not import them as
runnable new service operations. Do not infer this disposition merely from an
old Marzban origin/snapshot: migrated live PasarGuard subscriptions retain
their identity and provenance, not executable historical pending workflows. Preserve historical
identifiers, evidence, balances and legitimate payment verification/credit;
abandoning Native execution does not authorize deleting financial records,
taking received money, fabricating a refund or altering existing entitlements.
Rehearse classification and financial reconciliation before cutover. No
production effects are authorized by this clarification.
No resurrection of retired payment/providers; no old-bot compatibility shims.
Plan/service change, import, deletion, status, gifts and trial remain distinct
business commands but share physical-account/CREATE primitives where appropriate.
Do not force free/admin operations through fictional external payments.
Keep PHP 8.5, MariaDB, existing four ordered Cron workers/installation lease.
No Redis, broker, microservice, new Foundation or generic orchestration framework.
No silent new UX, funding policy, refund policy, expiry/deletion rule, or provider
requirement: current owner-approved policy applies until a specific change is
approved. In particular, do not silently switch checkout to paying only the
shortfall or allow partial settlement of an original invoice.

### Current-operation recovery and explicit final disposition (035)

The approved recovery contract is recorded in [Issue #204](https://github.com/ach1992/bot-refactor/issues/204#issuecomment-7135092291). A new operation observes the actual Native entitlement; the completed previous operation is not an entitlement precondition. While an operation is in flight, only its owned fields, physical identity, and applicable authorization are frozen for replay/readback. Unrelated Native changes do not invalidate an otherwise valid add-on.

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

035–038 are schema construction evidence only. Fresh installations use the consolidated empty current schema, not a migration chain. Preserve real account provenance and financial history during the separately authorized manual transfer; do not import obsolete interaction state as runnable work.

## 2. Single ownership / permitted dependency direction

| Owner | Inputs | Output | Must not do |
|---|---|---|---|
| Payments | immutable invoice, provider evidence, authenticated manual decision | confirmed receipt, credit transaction | Native calls, plan eligibility, service dispatch, referral reward |
| Wallet | authenticated domain command, stable source identity, integer Toman movement | durable ledger entry and balance | payment proof/HTTP, plan, delivery |
| Services | immutable quote/request, Wallet funding, local target, Provider capabilities | completed original operation and durable result | choose/verify payment method, use payment-specific recovery |
| Panel | Native identity, immutable request/target and single attempt | classified authoritative response | read payment provider, payment type/actor, set financial state |
| Delivery | committed source identity and intended payload | acknowledged/known failure/unknown | repeat money or service |
| Scheduler / UX | due operation or event, current authorization | invoke domain command, show projection | own a second settlement or service implementation |

Route adapters and webhook/Cron callbacks all enter the same domain commands.
Use existing auth, provider registry, HTTP, evidence validation, catalog and
subscription rendering. Move code only when ownership changes; no wrapper
around two long-lived legacy/new engines. Replace the old payment/service runtime
as a coherent completed implementation, remove redundant legacy execution paths
and use a data-preserving migration where needed. Historical data and migration
readers are not a second runtime. No incomplete candidate replaces production.

### Provider and payment-method extension boundaries

Owner clarification (2026-11-24): adding a future Native panel, payment gateway
or payment method must not require another implementation of service execution,
Wallet settlement, delivery or recovery. PasarGuard is the only Native adapter
being shipped now, not a hardcoded provider in the shared engine. Keep a
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
  provider and target; do not silently switch on retry. The existing registry
  is the natural extension point; replace obsolete payment-coupled callbacks
  as their callers converge. Nonpayment reservation resolves and locks the
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

## 3. Financial data model and invariants

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

Introduce one `wallet_entries` journal, with a unique
`(source_kind, source_ref, leg)`, exact user row/Telegram identity, signed
integer Toman delta, before/after balances, original actor and time. Financial
amounts are integers, never floats. Keep `users.wallet` as the synchronized
current balance projection, not a second independent writer. Initially preserve
the signed INT range of the actual column; widen only as an audited schema
change if needed. Use locking current reads on the uniquely resolved user,
check exact replay identity and return the original entry even if current
balance has since moved. Conflicting reuse is an invariant violation.
Caller owns one local transaction: receipt/proof claim + movement + receipt
finality (and durable funding continuation) commit together. Likewise operation
funding + one debit + capacity claim when needed; no network within SQL locks.
Use a consistent lock order, deterministic user order for transfers; audit
inversion against every migrated route. Commit ACK lost => reread unique
persisted source, never infer rollback. Reject duplicate local user IDs before
cutover; add unique userid constraint only after proving no duplicates and
rehearsing onboarding. Never silently merge distinct financial identities.

Movement sources: opening balance, payment credit, service debit, service
compensation, transfer (two atomic legs), purchase referral, onboarding
referral, authorized admin adjustment. Preserve `wallet_adjustments` and
`referral_awards` as historical/domain evidence and migrate their future
monetary effects to this journal; never dual-write balances. Zero-cost service
is explicitly funded at zero (no fake payment/credit). Credit can reduce
existing debt; ordinary debit cannot deepen debt. The current authorized
administrator-debt policy is unchanged. Refund/compensation refers to the
original debit/line, once only; never compensate an uncertain external write
as if proven absent. Partial bulk cancellation, overpayment and cancellation
policies must be resolved against existing accepted behavior before activating
those paths. No new arbitrary caps or expiry.

On cutover, quiesce all old writers; establish one opening per existing user
including negative/zero; reconcile count and per-user sum (not merely total).
Never replay historical credits/debits to build balances. New users start at
zero. Retain original invoices and audit records. Reconcile already-debited
historical operations separately during the approved manual transfer; preserve
their debit evidence and account for it in the reconciled opening balance.
Do not import them as runnable Wallet-first operations, debit again or create
a fake credit.

## 4. Service operations and data model

Use a durable service_operations header independent from payment attempts:
stable ID, unique original command namespace/reference (Telegram update,
scheduled cycle, or explicit historical mapping), user, kind, fixed quote
(price, quantity/plan, units, provider/panel/account identity, policy snapshot),
funding/debit reference, stage, due-at/attempt metadata, and last classified
failure. A mutable menu is not the quote authority. New orders are BUY, RENEW,
ADD_VOLUME, ADD_TIME. RENEW operates on an existing local PasarGuard
subscription; Marzban-only external RENEW_SCONFIG is retired.
A recurring renewal has one unique account/cycle command; no separate debit
or renewal executor. Preserve manual and auto renewal timing semantics.

Reuse existing service_effects CREATE/physical identity rows for each original
ordinal using source_kind/source_ref + ordinal rather than coupling to pay_row_id.
Preserve existing unique operation ID, username/physical target, linked order,
provider instance, marker and remote incarnation (id/created_at where supported).
For new edits keep one before/target/dispatch/result journal per operation,
with exact physical identity. A prepared observation may be refreshed until
the first possible Native dispatch; then the intent is immutable. Do not use an ever-growing consumed_keys JSON
or fixed 4096 limit: a durable unique operation record prevents replay. Replace
the old account mutation history authority while preserving historical
consumption evidence offline; do not replay outstanding historical claims. Account-level ownership
must cover paid, free, gift, change, deletion and other conflicting local
mutations and all aliases of the physical subscription, not just order_id.
Independent databases managing the same Native user are outside one local
transaction boundary. Reuse existing configured ownership and shared physical
identity within AChBot; diagnose actual conflicting ownership rather than add
a cross-installation service, require a new provider coordination API, or
pretend local locks cover external writers.

Stage meanings (stored, not guessed from error text):
- awaiting_funds: no committed debit; no Native effect allowed.
- funded: original debit or explicit zero-price acceptance and capacity claim
  committed; no new debit on continuation.
- prepared: original target sealed from current authenticated state under the
  physical-account fence, no Native write yet.
- dispatching: durable dispatch intent; remote effect may have occurred.
- applied: authoritative result for original immutable target; finalization only.
- completed: one atomic local result (orders/account linkage, entitlement audit,
  capacity disposition, delivery intent). Never reopen for a delivery failure.
- cancelled: no possible unpaid/uncompensated service side effect; cancellation
  and any compensation atomic, current policy. Partial bulk retains completed
  ordinals and their money; unresolved ordinals are not implicitly refunded.

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

## 5. Native execution contracts

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
time. Explicitly test delayed stale PUT, competing administrator/other bot,
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

## 6. Payment proof and delivery

Bank SMS matches actual source, amount, time/reference and one-time consumption;
missing receipt waits, API outage retries; ambiguous genuine evidence is
not a manufactured payment. Gift verifies/redeems with original code/reference;
recover uncertain redemption only from authenticated evidence, no blind second
redeem. Manual methods retain authorized receipt approval/rejection. Hosted
NOWPayments/NextPay/Zarinpal preserve exact merchant finality and issued quote,
callback authentication/verification and provider transaction identity.
All adapters call the same credit command, never CREATE/RENEW.
API proof may succeed while panel is down; receipt still credits exactly once.
No timer-only proof for an API-verified invoice; any existing explicit
timer-based manual acceptance policy change needs Owner decision.

Receipt submission and service validity are separate. A receipt already
reserved as receiving may finish or recover its original evidence and
Telegram reference even if the service is superseded concurrently. Its
superseded financial state is retained: verified funds credit Wallet once
and never revive the invalidated service. A superseded invoice without
an original receiving claim cannot start new evidence submission. Typed
gift-code storage and receiving reservation are one locked transaction;
failure rolls back both. The configured timer remains applicable to
eligible already-submitted non-API bank receipts, including superseded
ones, without overriding the API evidence requirement.

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


Generalize existing 018/020 delivery journals rather than invent independent
senders. New delivery identity is stable source (payment credit or service
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

Operator: maintain distinct receipt approval, dependency health, technical
incident and delivery status. Alerts should be coalesced by dependency and
actionable, not one mandatory review for each valid payment. Preserve current
UI/callback/copy during development; present proposed UX delta for approval
before wiring any new user-visible behavior. The current receipt and service decisions must not turn genuine received money into uncredited money or invent refund/success. Historical archive records are evidence for manual transfer, not executable state.

## 7. Execution sequence and removal

One integration candidate (no partial production cutover). Internal commits
are reviewable vertical slices, not independent releases. The Wallet-first
runtime supersedes the legacy Native port-counter fix in PR #233: neither
commercial commands nor trial/admin provisioning allocate a local port.
Do not merge #233 separately into this candidate or revive the counter.
Its independent disposition remains subject to the Owner's integration gate.

1. Foundation: wallet journal, transaction/replay primitive, source-real
   MariaDB tests, inventory every balance writer and source/amount semantics.
   Inactive until all balance writers are routed through the new journal.
2. Credit: adapt original bank/manual/API/hosted verification to one settlement;
   shared transaction + durable funding handoff. Preserve provider evidence,
   delivery and referral semantics. Test each actual adapter, not a stubbed
   common function alone.
3. Service vertical slices: BUY (single/custom/bulk + original identity,
   capacity and result), RENEW (manual/automatic), ADD_VOLUME/TIME (shared
   fixed-target engine). Introduce operation ownership and reuse physical
   identities and existing PasarGuard API capabilities. Test actual API
   response/commit failure sequences before replacing each path.
4. Converge all callers and maintenance: admin/transfer/reward/onboarding
   wallet writes, checkout, auto renew, free/admin primitives, delivery and
   read models. Ensure the common worker continues all funded operations.
   Inventory removal targets: per-method service branch in GatewayReturn,
   ApiPayments, AutomaticReceipts, Routes/ManualApproval, Checkout,
   Extensions, RenewalCheckout, AutomaticRenewal; separate WalletOriginal/
   WalletRenewal/WalletEdit recovery coordinators; payment-method switches
   inside ProviderRuntime, mutation history cap and old direct wallet SQL.
   Preserve genuinely unique contract, test and historical read-only migration
   logic before removing code. Remove all Marzban runtime API calls, adapters,
   routes, menu/configuration choices and recovery branches; retain historical
   identifiers and evidence readers required for migrated PasarGuard accounts
   and accounting. Do not preserve a runtime Marzban fallback.
   Verify actual call graph after removal. No legacy execution fallback.
5. Migration/cutover: implement reviewed forward schema transition using
   release-matched migration sequence; preserve old records and imported
   unfinished PasarGuard effects, existing active Native identities, balances
   and subscription/config URLs. Retire truly Marzban-targeted unfinished
   Native work under section 1 without altering the original financial proof. Rehearse on synthetic copies of actual supported
   schemas including all relevant 003..023 structures and data cases. No
   speculative schema drop or customer data in tests. Quiesce webhook/Cron/
   workers/payment callbacks safely, prevent dual writers, reconcile all
   opening balances and original credit/debit/hold/remote/notification states,
   deploy exact reviewed artifact, activate only new writers, resume verified
   work. Inbound payment evidence during maintenance must remain retrievable/
   retryable; check provider-specific callback/poll behavior. Back up and
   demonstrate restore; pre-activation rollback is feasible, after new money
   or remote effects use tested roll-forward/reconciliation, never restore
   an old DB over newer legitimate financial/Native effects. No production
   action without separate exact target approval.
6. Exact-candidate broad PHP 8.5 CI, additional high-risk fault injection,
   source/duplicate-write inventory, documentation, author-separated review,
   then Owner integration/release approval and separately authorized staged
   installed acceptance. No release of an inactive partial implementation.

Legacy migration cases: terminal #113453 never replay; #113457 preserve
original funded reserved BUY and capacity, no second debit; #113434 classify
reserved auto-renewal using its original cycle and actual Native evidence;
#113445 requires real receipt evidence, not a presumed approval. Chili's
runtime divergence must be reconciled against actual current installed hashes
before selecting any cutover artifact. These incident references are evidence,
not authorization to mutate them. No blanket reprocessing.

The old prepaid-adoption rehearsal is superseded. The current source-only
inventory and opening-balance rehearsal preserves old evidence but never
adopts a historical operation into the current executor. No special prepaid
funding path exists. The old automatic-renewal journal is outside the current runtime and empty
schema. Preserve it as external historical evidence if needed; do not transfer
its execution state. Verified Native account identity/provenance remains a
current safety requirement. During manual transfer resolve every outstanding historical claim,
reconcile capacity and preserve the original physical account before activation.
The new runtime must not carry unresolved old workflows forward.

A subsequent read-only Hell reconciliation (2026-11-24) reconfirmed that
#113457 has zero persisted day/volume, one untouched capacity hold, no CREATE,
no bank quote, and no linked delivery/follow-up/referral claim. Current plan
values are not proof of its original quote; binary logging is disabled.
#113434 is still `review / remote_unconfirmed` with a different or missing
original mutation key. #113445 remains monitoring without recorded approval;
#113453 remains terminal. Recover authentic original evidence during separate manual reconciliation; do not guess, automatically compensate,
or replay the terminal purchase. Independent source replacement and actual
route validation remain necessary even while historical evidence is missing.

## 8. Definition of done / measurable acceptance

- Every supported payment adapter has real-source tests for valid evidence,
  duplicate callback/verification, conflict, unavailable API, delayed receipt,
  commit lost and one exact credit. Payment code has no Native dispatch.
- Every supported Wallet mutation (incl. transfer, admin, two referral paths)
  uses one journal transaction; no direct update of balance outside ledger
  and initial zero/accounting migration; exact replay and conflicting identity
  covered; no lost or doubled money, debt policy maintained.
- Each service operation works with Wallet independent of funding route; no
  Native references to payment method. PasarGuard is the sole current real
  adapter; verify removal of Marzban runtime paths and historical
  migration/retirement classification. Verify new Native and payment adapter
  registration through test-only fixtures without edits to shared engines.
  Cover actual operation variants, standard/custom/bulk, zero-cost, auto
  renewal, same-physical aliases, price/plan drift and immutable snapshots.
- Deliberate crashes before/after each durable/remote boundary, lost SQL ACK,
  HTTP timeout after apply and before apply, stale response/worker, overlapping
  Cron/callbacks, slow/failed providers, fair backlog and partial bulk. No
  duplicate create, debit, increment or audit. Normal temporary outages
  automatically converge; genuine unsupported/contradictory evidence has a
  precise diagnostics/technical resolution, not routine admin review.
- Completed service always visible to customer; delivery failure/ACK unknown
  cannot reopen funds or service. Existing catalog/permission and disclosure
  contracts pass, no unauthorized UI changes.
- Verified migration per-user balances, original pending/terminal proof,
  existing identity/config links, accounting sums and no old writer. Tested
  recovery/rollback boundary and schema/version compatibility; exact runtime
  package excludes private data. No production claims from fixtures.
- Update canonical spec and nearest technical docs; supersede old operational
  recovery instructions at cutover, retain useful audit history. No new
  parallel ledger authority, legacy runtime fallback or artificial cap.


## 9. Development implementation boundary (2026-11-24)

This checkpoint is an **inactive draft**, not an installed runtime, release,
or definition-of-done acceptance.

- 024 / `src/Wallet/Ledger.php`: the shared owner lock, exact journal lookup
  (no-write on miss), and one transactional balance mutation. Existing
  user balance writers have not yet been converged.
- 025 / `src/Finance/PaymentCredit.php`: new-issuer-only invoice pin;
  historical NULL cannot be promoted by normal issuance. Hosted and manual
  receipt identity, persisted protected API proof and authorized manual
  decision enter one payment-credit source. Retain multiple authenticated
  hosted children without double credit. The existing timer policy is read
  under its exact settings-row lock; API-pinned invoices cannot use the
  timer fallback. Already-proven API money is recognizable without
  authorizing new redemption. Actual hosted/manual/API route cutover,
  receiving-merchant-account scoping, and proof acquisition independent of
  Native eligibility are not yet complete.
- 026 / `src/Services/Funding.php` / `src/Finance/FundingCheckout.php`:
  independent unique original command and immutable quote; local subject and
  physical target admission; one debit (or explicit zero-price funding),
  BUY capacity, and the durable funded continuation in one transaction.
  Original external invoice credit and optional service funding share one
  transaction. A known invalidated/capacity-unavailable request retains the
  verified credit; receipt replay returns the committed handoff, not another
  funding attempt. Current business quote adapters, Native executor, account
  fences, worker, cancellation/compensation after funding, delivery, migration
  of historical funded claims, and old path removal remain incomplete.
- Source-real disposable PHP 8.5.12 / socket-only MariaDB:
  `test_wallet_first_ledger.py` 42 cases,
  `test_wallet_first_credit.py` 78 cases (including loopback SMS and gift
  evidence; hosted verified-response fixtures, not actual gateway HTTP),
  `test_wallet_first_funding.py` 40 cases (local admission/funding, not
  Native execution; standard/custom/bulk, managed/external renewal and
  add-ons on Marzban, PasarGuard BUY, zero cost, concurrent receipt,
  lost COMMIT ACK, deliberate late SQL failure, capacity and invalidation).
  Both MySQLi strict and report-off modes. New modules have no direct
  Telegram wire calls; existing exact wire-parity check retains that rule.

The preceding test matrix describes the earlier foundation commit. Following
the Owner's PasarGuard-only decision, new service quote/funding admission
no longer implements Marzban or external RENEW_SCONFIG. The revised
`test_wallet_first_funding.py` passes 42 cases on PHP 8.5.12 / disposable
MariaDB (strict and report-off): PasarGuard standard/custom/bulk, managed
renewal and both add-ons with real local identity evidence; retired target
and external renewal admission refusals; verified receipt for a retired
target credits once without service debit or capacity reservation. This
does not yet remove Marzban from the old runtime or implement historical
retirement/migration and Native execution.

The next extension-boundary slice moves PasarGuard's local target admission
into its registered `service_target` adapter and current method-specific
invoice/proof rules into `src/Finance/ReceiptMethods.php`. The common service
funding and credit transactions no longer branch on concrete provider names.
The Native callback is temporarily optional in the existing legacy registry
while old callers remain; the final registry must require the actual new
service capabilities, not obsolete payment-coupled callbacks. The payment
registry currently owns local invoice/proof validation; real issuance,
authenticated external verification, callbacks/polling and UI registration
still need convergence. No production extension or runtime activation.
The funding suite now uses owned source copies with a test-only Native adapter,
hosted gateway and manual method; common engine files remain byte-identical.
Unknown registrations, invalid proof, mismatched evidence and unauthorized
manual proof are refused; valid new methods settle and fund once on replay.

### Receipt adapter execution slice

New original hosted issuance and verification are implemented in
`HostedReceipt.php` / `HostedMethods.php` and selected by the receipt
registry. Reserve the original attempt, quote and protected receiving
merchant credential before transport. The reference and checkout URL are
bound before publication; a creation with no confirmed reference does not
silently create another remote invoice. Known references can be verified
without a Native subject preflight. NOWPayments uses a signed, durably
known child and authenticated lookup; NextPay verifies the original
reference/amount; Zarinpal verifies the same pinned merchant and reference,
including already-verified status 101 after local rollback (see
[ZarinPal-Lab legacy WebGate sample](https://github.com/ZarinPal-Lab/SampleCode-Python-Flask)
and [official GravityForms integration](https://github.com/ZarinPal-Lab/GravityForms)).
Do not reinterpret unpinned historical 101 results.

New manual issuance shares the existing method-specific destination/quote
policy; the API profiles pin proof independently of the service subject.
Existing receipt acquisition and protected bank/gift evidence transport
enter the common receipt sink. Staff decisions, the explicit existing
bank timer, and API proof commit one credit and the optional funded
service continuation. A service invoice cannot be issued without its
original service operation. Rejection cannot authorize another consume or discard definitive evidence from a previously dispatched operation. An
uncertain gift restored to active still cannot be automatically re-consumed
without authoritative proof of the original consumption epoch.

Local financial projections become paid/done in the same commit as the
receipt and handoff. They do not assert service completion. No receipt
HTTP call holds a financial SQL transaction. The 025 draft schema now
includes the protected merchant profile and the invoice fingerprint binds
it and the original minimum-settlement metadata. No installed migration.

Source-real PHP 8.5 / owned MariaDB checks: credit 78, funding 54, hosted
18 (46 owned loopback HTTP requests, WebGate SOAP argument/response spy),
manual 16 (actual receipt acknowledgement contract and existing SMS/gift
HTTP evidence paths). Includes concurrent exact verify, local commit loss,
remote consume ACK loss, provider unavailability, bank/gift proof recovery
after service invalidation, exact method quotes and actor refusal.
SOAP wire interoperability and actual merchant production behavior were
not exercised. The existing route/UI/Cron entrypoints are not yet switched.
Automatic fair receipt polling, gateway issuance uncertainty disposition,
receiving-account identity scoping, Native execution, final delivery,
writer/caller convergence and migration remain acceptance work.

### Source-neutral Native BUY execution slice

The new `src/Services/Execution.php` owns due-time selection, one active
database session per original operation, committed dispatch intent and
claim-token result fencing. It calls registered prepare/perform/apply
capabilities, not payment-specific executors. No transaction holds the Wallet
owner across HTTP; a worker that loses its session cannot finalize a successor's
claim. A failure retains the original phase and a due retry with a classified
reason. The worker is deliberately inactive until the cohesive cutover.

PasarGuard BUY reuses the existing physical CREATE evidence and the unique
source/ordinal and physical-panel/username keys. Reserve each missing ordinal's
original name, marker, expiry and exact bytes before POST. Read the exact
authenticated name first; only an authenticated 404 permits CREATE with that
same original unique name. A 409 or uncertain response is reconciled by
readback, never a different name or another capacity reservation/debit.
Matching name alone is insufficient: verify marker, incarnation, group
membership, exact entitlement, managed state and by-ID readback. Existing
verified ordinals never cause another POST. Changed local entitlement evidence
and repointed panel identities block execution with a concrete diagnostic.

`python3 tools/test_wallet_first_service_execution.py --run-disposable`
passed 20 cases (strict/report-off PHP MySQLi, disposable MariaDB and actual
loopback HTTP: 76 requests / 20 POSTs), including three-account partial
completion and lost response, 409 after creation, definite dependency outage,
SQL rollback after remote success, foreign same-name identity, panel repoint,
locally changed entitlement, concurrent worker exclusion, due-batch progress,
and financial/capacity invariance throughout replay. A second SQL connection
writes the Wallet owner during HTTP to exercise the no-open-transaction
boundary. These tests prove the current BUY-to-applied slice, not full order
completion or a deployed provider.

Next dependencies for BUY: atomic local order linkage and source-neutral
018/020 delivery/referral/report finality, capacity restoration using the
original service-operation reservation rather than per-line debit, immutable
rendering evidence, correct completion and independent delivery retries.
Implement RENEW and both ADD operations through the same coordinator, with
a persistent physical-account fence covering all aliases and other mutation
callers. Do not use the old ever-growing mutation-key array as the new
replay authority. Admission, worker and registry must reject unsupported
operation kinds before activation; temporary incomplete adapters are only
a development boundary. The legacy runtime, writers and Cron entrypoints
remain unconverted; migration rehearsal and independent review are pending.

### BUY local completion and independent delivery slice

The same due worker now progresses verified BUY from applied through local
Order creation and durable delivery, to completed. No payment-method
branch, second debit or second Native CREATE in finalization. Registered
adapter finalization reads the already-verified incarnation and actual native
links outside the SQL transaction; reused purchase presentation preserves the
existing catalog, links, QR and keyboard. Each original ordinal commits its
local order, provider linkage and sealed delivery intent together, with
integer amount allocation summing to the original paid price. Last ordinal
and completed state commit together. The original capacity reservation is
unchanged; deletion capacity convergence is documented in the later
subscription lifecycle slice. Referral/report convergence is still pending.

Draft 027 extends the original 018 journal with the real service operation
source and nullable legacy payment references. Existing payment packets and
attempts remain unchanged; no synthetic pay or attempt. Automatic dispatch
shares the original claim and acknowledgement primitives and sender. An
abandoned service delivery claim becomes unknown after session loss; it
never authorizes another automatic send. Source changes prevent obsolete
packets from being sent. At that development checkpoint the maintenance worker and whole-packet
contract were still transitional. The final fresh runtime described below
dispatches only current multipart service/effect sources.

Source-real PHP/MariaDB/loopback execution suite now passes 30 cases
(118 owned HTTP requests / 22 POSTs), including a forward 018→027 migration
with a historical packet, bulk lost response, atomic rollback after failed
delivery insert, completed-state commit acknowledgement loss, concurrent
delivery, abandoned claim, stale order refusal, sales-disabled catalogue
finalization and shared delivery queue progress. Telegram is a fixture
boundary spy; no live send. Existing subscription presentation 251,
bank-buy 72 and original manual redrive 6 tests pass. Existing Native API
limitations and the still-inactive overall cutover remain unchanged.

### Shared monetary writer convergence slice

Administrator credit/debit and explicitly consented debt, user-to-user
transfer, and onboarding referral rewards now enter `achbotWalletApplyLocked`
instead of their independent direct SQL balance writers. Original role,
Telegram update/actor/step/cursor evidence and the existing 005 audit records
remain binding. The 005 audit is a domain record, not another balance
projection. Both transfer legs, original 005 evidence and consumed step
commit together under the existing sorted owner-lock order. Registration
and its invitation reward commit together; the original registration's
local row ID distinguishes an actual new account from delivery replay.
No arbitrary debt or amount policy was added. The unused generic
`setUser(..., 'wallet')` mutation is explicitly refused before any write.

The new shared journal readiness check validates transactional engines,
monetary column shapes, source uniqueness and owner-index shape. These
converted domains refuse missing/incompatible 024 rather than falling back
to legacy balance writes. This is **not** an approved partial cutover:
024 opening balances and migration are still outstanding; the remaining
payment/service/referral paths must converge before installation.

Current-source validation: ledger 42, admin adjustment 59, onboarding 56,
and transfer 41 (40 current-source cases plus the preserved historical RED
control in an isolated fixture). Tests prove exact common journal entries,
authorization and explicit debt consent, replay/parallel events, missing or
incompatible schema, rollback of first and second legs, preserved legacy
audit evidence, registration rollback on reward failure, and generic
profile-setter refusal. The current-only transfer mode is included in the
representative CI roster without running its historical source there.
Telegram wire parity remains 898/898. No production database, provider or
Telegram effects. The direct writers in those pre-cutover routes were replaced by the shared
Wallet ledger, receipt, service and referral owners. This historical slice
is evidence of the conversion, not a list of remaining runtime tasks.

### Shared RENEW / ADD_VOLUME / ADD_TIME and scheduled admission slice

The current schema records one normalized mutation row per service operation.
Its hashed observation and absolute target, first possible dispatch timestamp,
verified reset acknowledgement, verified result, and original local audit ID
replace growing mutation-key lists for the new executor. The real existing
physical account effect is claimed as `mutating` under the common
`service:<id>` marker, with a nonblocking session lease on that account
shared with existing plan/reassignment operations. Local aliases therefore
cannot execute conflicting operations concurrently. The old historical
snapshot/key are not rewritten as a new purchase. During the claim, the
historical migrated-account validator recognizes only a matching new journal
and nonterminal operation; original provenance remains required.

The PasarGuard adapter owns authenticated observations, Native identity,
scope, reset and absolute PUT. Before the first possible write, refresh
the owned entitlement from current authenticated Native state; scheduled
renewal must still meet its current lifecycle eligibility. Seal the exact
intent and dispatch timestamp at the final pre-write boundary (038 offline
construction evidence). After that boundary, RENEW freezes its expiry/quota;
ADD_VOLUME writes only the fixed byte target and ADD_TIME only the fixed
expiry. An equal target before that boundary does not prove fulfillment. A verified reset ACK does not require traffic to remain zero
afterwards. Once the ACK is recorded, later retry does not reset again.
Current target readback is sufficient to finish an original unresolved
edit; a conflicting entitlement does not authorize another relative
increment or a blind overwrite. The provider-neutral local edit finalizer
records the original Order change, one increase_order audit, the verified
result, and one sealed customer intent in a transaction with release of the
physical ownership. A local/Telegram failure does not restart Native I/O.
Existing 018 packets and schema semantics are preserved.

New scheduled-renewal admission (`src/Services/AutomaticRenewal.php`) reads
the existing auto-renew setting, original Order/cycle, server eligibility,
and exact agent discount; observes the actual authenticated account while
holding its shared physical lease **before** entering the money transaction.
Under the owner lock it revalidates the exact target/cycle and commits the
original command, one debit (or explicit zero-price funding), physical claim
and frozen Native renewal target together. No synthetic pay or attempt is
created. Competing aliases cannot reserve a second renewal. Insufficient
balance disables that original auto-renew setting without a debit or
mutation; 100% discount keeps the accepted signed-debt behavior. The
customer success packet uses the original real service reference and
committed balance transition; administrative/report-channel followup and
the existing low-wallet notice still need source-neutral outbox/caller
convergence. The old scheduler/Telegram entrypoints remain inactive with
respect to these new commands until the coherent cutover.

Disposable PHP/MySQLi (strict and report-off), MariaDB and loopback Native
contract tests: `test_wallet_first_service_execution.py` 46/46 cases, 270
actual HTTP requests including 32 original CREATE POSTs. Includes reset
ACK with later traffic and PUT outage, uncertain PUT readback, fixed ADD
targets, alias serialization, independent drift, atomic finalization failure
and COMMIT ACK loss, independent delivery, concurrent automatic-cycle
admission, low balance, funded observation drift and full agent discount
with existing debt. No deployed Native service, actual Telegram delivery or
production money was used. These are actual new source paths in an isolated
candidate, not completed end-to-end production route/migration acceptance.
Regression on the committed engine also passed funding 54/54, adapter
registry 94/94, hosted payment 18/18 and Telegram wire parity 898/898.
The historical cutover recovery suite now includes the new journal's actual
ADD_TIME mutation on a migrated physical account and independently imported
local reference. Both original recovery outcomes (first write and observed
already-applied result) preserve the historical mutation key, snapshot,
provider provenance, import origin and payment/transaction records across
the new edit; exactly one new debit, audit and delivery intent are recorded.
The expanded suite passed 16/16 on disposable PHP/MariaDB and loopback Native.

The known delayed older PUT limitation below still requires explicit
recovery/reconciliation evidence, including stale arrival after a later
successful update. Expanded new-route tests for scoped add-ons and other
current account policies, report/referral convergence and
all remaining activation/removal gates are still required.

### Subscription lifecycle ownership and original capacity

The new operations journal is now consulted by the existing shared-account
plan, logical-server reassignment and deletion gates. Before dispatch, a
funded operation has exactly the same local ownership as a prepared,
dispatching or applied operation. The guard covers all current account
references and protects a locally recorded bulk BUY ordinal until the whole
original BUY completes. Reuse the existing physical account and order locks;
no new reservation ledger or second mutation coordinator. The current UI does not offer endpoint rebinding. Credential rotation authenticates the new key and updates the same connection aliases atomically; unfinished journals never prevent repairing authentication. Keep the explicitly authorized local server hard-delete policy unchanged.

Draft 026 (not installed) derives indexed subject_order_id and target_server_id
from the immutable quote. There is no additional writable target projection.
Funding/readback cross-check those fields. The indexed current reads share
the order/server row locks used by funding; no table-wide JSON scan.
The current schema is required. Draft creation and service admission use
the same physical-account plan-journal key, including aliases; an unrelated
unresolved or locked journal cannot hold a different account's draft.
Live preparation and actually dispatched, unresolved plan journals, and non-service Native mutation claims refuse new funding. Abandoned prepared plan/correction journals prove no possible Native dispatch and are cancelled under the original physical lease. The original plan journal and Native fence commit together at the final pre-PUT callback. GET-only failures do not consume a corrective dispatch. A paid receipt arriving during a live plan fence credits Wallet without waiting for that Native operation; a fresh service request may be needed even if the plan subsequently fails. Group-only changes compare only owned Groups and account identity, without freezing unrelated quota/expiry. The retired pay-bound quote/preflight adapter is not in the
Native registry; nonpaid effects retain their plan-only target adapter.
A real plan change or reassignment atomically cancels only awaiting-funds
quotes for the affected references; deletion cancels the selected reference's
unfunded quotes within its original claim transaction. No credit, debit or
capacity movement occurs on this cancellation. The receipt sink credits a
verified late payment once, even after the original plan returns. A submitted receipt remains reviewable, with a direct case link in its original administrator message; never revive the old service. A deliberately removed server does not prevent an explicitly authorized refund of unperformed BUY units: no capacity is restored to a nonexistent server or a different logical server.

New purchase deletion resolves one ordinal's capacity from its completed
original service reservation, even if current plan is zero or the logical
server changed. The authorized UI restoration increments only that original
server in the same transaction that removes the local order, after verifying
Native absence. A deliberately removed original server has no inventory to
restore; never increment a different server. Existing Cron/local-only
no-restoration policy and historical provenance remain unchanged. Imported
references do not own a purchased slot. A new-source DELETE pins the exact
local order, owner and server in the original Native claim, so a sole
remaining independently proven import can recover its own delete without
pretending to be the original BUY order. Recovery rejects a changed subject.

Committed-source execution passed 62/62 cases in strict and report-off
MySQLi modes, 378 loopback HTTP requests and 40 CREATE POSTs. The 16 new
cases cover funded-before-first-order rebind, partially recorded bulk
ownership, funded-before-dispatch lifecycle exclusion, late receipt credit
after successful plan change and return, relocated deletion with SQL
failure, wrong deletion-subject recovery, funded alias exclusion and
no unreserved slot for an imported reference, and a genuine unresolved
plan intent. Funding passed 54/54. Previous exact candidate CI
45353358384 (bee6770) passed; do not attribute that run to the new tree.

This package does not activate the new payment/service callers or complete
migration of historical pending claims. The existing-provider delayed PUT
limitation remains as documented below.

### Purchase referral and durable reward-report convergence

Draft 029 extends existing 008 and 020 journals without changing historical
rows or sealed packets. A completed original BUY qualifies only if its
actual verified receipt atomically funded the original operation. Direct
Wallet purchases, later receipts for already-funded or cancelled commands,
unrelated top-ups, zero-cost operations, and agent purchases do not earn a purchase referral.
Exclude a buyer with current agent status at final local completion or an original quote marked agent_bought, regardless of discount, purchase quantity or external payment method. The inviter's own agent status is not an exclusion.
The existing fixed amount and original inviter are captured at final local
completion, once per entire purchase, not once per bulk ordinal. Preserve
disabled decisions; a missing or replaced inviter row is contradictory
identity evidence, never permission to pay a replacement account.

The due reward worker locks the original claim, verifies completed service
and actual receipt/debit journals, and applies one purchase_referral entry
through the shared Wallet primitive. Credit, 008 finality and existing
catalog reports to inviter, owner, authorized staff and report channel
commit together. No Telegram or Native I/O occurs during this transaction.
Failure before commit leaves the same claim pending with deferred due time;
loss of commit acknowledgement does not repeat a credit. 020 v2 packets
bind the real service ID and real receipt ID, while v1 historical validators
remain in place. Report dispatch reuses the existing payment lease and sender;
a lost/abandoned claim becomes UNKNOWN, never automatically resends, and
cannot reopen the service or Wallet. Each report is separately claimed.
This is not yet general report convergence: Wallet-only service reports,
receipt-credit reports, automatic-renewal and low-wallet reports, confirmed
Telegram rejection/redrive, and scheduler/actual route cutover are outstanding.

Local disposable execution: 74/74 cases in strict and report-off MySQLi,
450 loopback HTTP requests, 52 CREATE POSTs. Twelve added cases cover
forward 008/020 migration with historical rows, original bulk completion
and duplicate/late receipt, immutable policy/recipient, transactional
rollback on report insert, concurrent workers and lost commit ACK, one
report attempt and abandoned dispatch, direct Wallet exclusion, disabled
reward, and replaced inviter. Legacy award journal 16/16 passes. Optional
legacy referral route-order suite fails identically on exact baseline
d508e3fe14a40b9a5f1932a8f47291028b9ed11b and this candidate: its manual
route stderr allowlist rejects structured achbot.delivery diagnostics
before later assertions. Do not claim that suite passed. Previous CI
45355547656 failed the automatic-renewal fixture's diagnostic allowlist;
the exact local-deletion-deferred reason is now admitted without changing
rollback assertions, and automatic-renewal suite passes 77/77. No
production funds, provider account or Telegram message touched.

### Source-neutral automatic renewal reports and low-wallet decisions

Draft 030 generalizes the existing 020 original-source journal: an intended
report may have a real payment, a real service operation, or both. The
historical payment key, source digest, foreign key, payload and states
remain intact. New v3 sealed packets have a real service ID, no synthetic
invoice or attempt, and a source fingerprint verified before dispatch.
The original session-owner lease, claim, sender, acknowledgement, and
bounded maintenance path are shared with 020; loss of acknowledgement or
an abandoned claim remains unknown, not automatic resend.

The automatic renewal's frozen cycle, reference, exact debit journal (or
legitimate zero price), verified mutation and local audit determine both
the existing customer success notice and independent owner/authorized
staff/channel report. Record the report intents atomically with service
completion. No mutable plan repricing, new debit or repeat Native write
on report failure. A real low-wallet refusal is an unfunded cancelled
service command with its immutable quote and original disable decision.
Its durable existing customer notice and disabling autoRenew commit
together; a rollback leaves neither. No invoice, debit, reservation or
remote mutation is manufactured. The refusal does not claim the actual
funded cycle: deliberate re-enable after restoring balance can renew it.
Historical payment follow-ups retain their original validator.

Local disposable execution: 82/82 in strict and report-off MySQLi,
470 owned loopback requests and 52 CREATE POSTs. New cases include
source-only per-recipient ACK/UNKNOWN, atomic rollback of reports during
verified renewal finalization and local-only recovery after lost COMMIT
ACK, low-wallet disable+notice rollback, lost COMMIT ACK, intentional
re-enable, and unchanged zero-cost/debt policy. No live Telegram, panel
or production database. Still outstanding: general BUY/RENEW/add-on
reports and receipt-credit reports, per-part definite rejection and
authorized delivery redrive, actual routes and scheduler activation.

### Definite single-message report rejection and explicit recovery (draft 031)

The existing 020 follow-up dispatcher now journals each attempt for new
service-sourced v2/v3 reports. Migration 031 adds due time and a separate
append-only attempt identity/history; original v1 payment-only reports keep
their existing one-shot behavior. Historical attempts are not fabricated.
The same source lease, immutable sealed payload, recipient and exact source
are retained. A committed attempt precedes the external send, and the attempt
outcome and intent transition commit together. An abandoned claim or failed
SQL finalization after a possible send remains UNKNOWN, not assumed rejected.

A complete explicit Bot API rejection with a recognized integer 4xx error
code and nonempty description is a definite rejection. A valid positive
429 retry_after schedules a due retry automatically; the dispatcher will not
send before that due time. Other definite rejections remain visible for
correction and authorized retry. Missing/malformed responses, transport
failures and 5xx remain UNKNOWN, with no automatic re-send. Retry work is
deferred fairly when its source lease is busy or temporarily unavailable.
Each attempt preserves its own outcome and timing.

An explicitly authorized redrive reuses the exact original packet. The
current actor must have payment permission and confirm an actor-, attempt-,
state- and payload-bound token. The existing one-explicit-UNKNOWN-redrive
policy is enforced; no ordinary duplicate callback or worker can repeat an
uncertain send. There is no new UI route yet. Safe diagnostics retain intent,
actor, attempt and Telegram error code without raw payload or secret logs.

Disposable PHP 8.5.12 / MariaDB tests: 94/94 cases across strict and report-off
MySQLi, 470 owned loopback HTTP requests, 52 CREATE POSTs; includes 429 wait
and due recovery, blocked-source fairness, rejected/UNKNOWN permission-bound
redrive, stale confirmation, failure after send but before SQL finalization,
abandoned claim and lost caller response after commit. Old referral award
journal 16/16, presentation 57/57 and isolated log privacy 13/13 pass; no
real Telegram sends. PHP lint and diff checks pass. This is single-message
service report recovery, not completion of 018 multi-part customer delivery
or legacy v1 replacement. General service/receipt reports, multipart
QR/text and authorized delivery recovery, actual route/scheduler integration,
migration rehearsal and all other #204 acceptance criteria remain open.

### Original completion and retained-credit report convergence

General BUY (standard/custom/bulk), RENEW, ADD_VOLUME and ADD_TIME
completion stages reuse the actual service ID, immutable original quote,
confirmed debit and exact sealed 018 completion lines. Reports do not need
a synthetic payment/attempt, a fresh plan price, a current subscription
credential or a successful Telegram delivery. Bulk reports are staged only
with the last committed line and include the original confirmed account
names. Existing automatic-renewal and referral policies remain separate
business decisions but share the same original-source Outbox sender.

The receipt evidence reader checks the pinned invoice, original wallet
credit row/actor/balances, committed funding outcome and bound external
transaction. Only the receipt that actually funded the service determines
its report method; a second, late, refused or otherwise retained receipt
has its own real pay-only wallet-credit report (sealed packet v4).
A service-funded receipt never also emits a Wallet top-up success.
The first credit and its required report intents commit together. A
rollback leaves neither, and exact receipt replay never restages reports.

Existing hosted owner/staff/channel notifications and manual top-up
owner/staff notifications keep their respective audiences. API and timer
approval edit existing authorized receipt cards without extra staff
notifications. For a manual/API/timer-funded completed service, the
original receipt message targets and current decision are checked again before editing; stale receipt cards cannot reactivate a service. Manual operator notices that assert
customer delivery, and actor-context menu presentation, still belong to
the subsequent delivery/route integration slice. Do not send a fabricated
"delivered" notice before 018 acknowledges it. Current catalog wording is
reused and no new callback route is introduced.

The existing 031 attempt history, source leases, 429 due retry, explicit
actor-bound redrive and UNKNOWN policy also cover the new v4 wallet-credit
intents. The bounded original-source scheduler includes both service and
retained-credit reports. Historical v1 packets retain their one-shot
disposition. No additional migration is needed for this slice.
Receipt/card targets and recipient authorization are revalidated before
dispatch, and a successful or uncertain Telegram result cannot repeat
money, debit, reservation, Native execution or report creation.

Disposable PHP 8.5.12 / MariaDB: service execution 106/106 (518 owned
loopback HTTP requests, 52 CREATE POSTs), funding 56/56, hosted 18/18,
manual 16/16, migrated-account recovery 16/16. The added cases cover
one report for a confirmed bulk, funded versus retained late receipt,
pay-only 429 recovery, hosted renew/time/volume completion, atomic report
insertion failure and exact original manual-receipt edit after target
tampering is rejected. The receipt and service rollback/lost-ACK tests
show no duplicate money or Native execution. No live external effects.
Presentation 57/57, wallet-report HTML 12/12 (22 fixture sends) and
isolated log privacy 13/13 also passed. Runtime route and scheduler
activation, multipart 018 recovery and remaining #204 gates remain open.

### Independent customer delivery message effects (032)

Migration 032 extends the existing 018/027 journal, rather than introducing
a second financial or provider execution owner. On local service completion,
the actual renderer's QR (if present) and all HTML text parts, with the
original keyboard on the last text, are sealed in the original packet.
The parent and per-part digests are inserted atomically with the local
order/audit. Text-only edit notices retain their original mode. No later
display-setting, pricing or account lookup reconstructs that manifest.

The parent owns one session lease and a monotone attempt sequence; each
part has its own state and latest attempt. A BUY additionally holds the
existing physical account lease against transfer, plan change and deletion.
Before dispatch, the original debit, completed operation, identity, manifest,
part and attempt history are checked. Commit the exact claim before Telegram;
no SQL transaction spans its network call. Finish the part and aggregate
parent together. ACK means all parts are acknowledged, not just one photo.
Other never-attempted or due parts can progress despite a sibling failure.
No delivery or redrive changes wallet, reservation, provider or order data.

Explicit Telegram 4xx rejections require a matching HTTP envelope. A positive
valid 429 retry_after schedules only that part; a known local preparation
failure before the send is also retryable, with its own recorded outcome
and no invented remote error. Other definite rejections require explicit
correction/redrive. Unknown transport or 5xx, contradictory or malformed
response, abandoned claim, and uncertain SQL finality after sending are
UNKNOWN, not automatically replayed. The original actor-bound confirmation
policy now targets one exact part and its latest attempt; a single explicitly
authorized UNKNOWN redrive is allowed per part. Permission and source
identity are rechecked at dispatch. Existing quiet-window maintenance and
bounded selection include due parts and abandoned service claims.

At this development checkpoint, migration 032 preserved original 018 pay-only
records without backfilling part boundaries, manufacturing ACKs or replaying
old uncertain packets. The final fresh schema (043) omits these historical
transport-only fields altogether and requires actual current source and part
identities. It is not an in-place migration of historical packets. There are no
new callback routes, no production migration, and no live cutover in this
slice. Existing rendering/keyboard/copy and one-shot legacy callers are
retained; PHP 8.5 QR encoder deprecations are corrected without changing the
encoding algorithm. The actual service-route and scheduler activation,
delivery-dependent manual operator notices, outstanding payment-policy
coverage, historical mapping/rehearsal, old runtime removal, and independent
review remain separate acceptance gates.

### Delivery-dependent human approval reports and original-source maintenance

A manually funded BUY seals the existing operator purchase notice together
with original service completion. A bulk purchase also seals the original
Owner and configured channel copies where distinct. A delivery-dependent
report cannot claim a Telegram send until every original account's sealed
032 manifest and latest per-message attempt history proves acknowledged
delivery. Parent or child state projections alone are insufficient. The
original credit, completed service and recipient permission are revalidated
under lock. No recipient is inferred from a later callback; revocation or
channel changes do not redirect the sealed report. The receipt card update
is independent of customer ACK, as is the manual RENEW success notice,
whose wording asserts service completion rather than customer delivery.
Timer/API approval continues to update receipt cards without adding
manual-operator fanout. The supplementary legacy referred-purchase alert
and remaining payment-policy parity are still cutover checklist items.

The main menu is rendered by the existing shared layout with explicit
recipient context. The original approver's persisted profile supplies the
operator notice keyboard, rather than an unrelated webhook or Cron user.
Hosted renew/time/volume customer notices reuse their original text,
plain parse mode and mainMenu button. Manual/API notices retain the named
subscription presentation, and original RENEW_SCONFIG/timer plain copy.
Wallet and automatic-renewal copy is unchanged. Presentation is sealed
from the actual funding receipt at verified local completion, not rebuilt
from an unrelated later receipt or a current Native account.

The existing services Cron source now invokes bounded original-source
follow-up maintenance after customer delivery maintenance, with an
independent exception boundary. Before 026 exists it has no new work.
Delivery-dependent pending reports whose parent manifests are incomplete
do not occupy the due batch; the complete proof is always rechecked before
claim. Other reports can proceed even while a delivery is UNKNOWN. All
effects use the existing 031 attempt history, lease, definite-rejection,
429 and explicit redrive policies. No new schema or callback is introduced.

Disposable real PHP 8.5/MariaDB and owned-loopback execution: 122/122
scenarios (632 HTTP requests, 66 CREATE POSTs), including both strict and
report-off MySQLi modes. The new cases exercise atomic rollback of the
operator report, exact actor menu context, partial multi-order delivery,
forged ACK projections, role/channel revocation, concurrent single claim,
receipt-card progress, hosted navigation and manual renewal independent
of customer delivery. Migrated account recovery 16/16 and exact menu
parity 41/41 also pass. This is a source-level maintenance connection,
not live execution, admission/callback cutover, or production deployment.
Full payment/service route convergence and independent review remain open.

### Verified Native limitation of existing APIs, not an accepted retry contract

`python3 tools/test_wallet_first_native_write_fence.py --run-disposable`
executes the current real PHP PasarGuard HTTP adapter against an isolated
synthetic peer implementing the reviewed unconditional assignment semantics.
A first PUT to quota 200 reaches the peer but times out; an identical retry
is acknowledged and read back at 200. A later PUT to 300 is also acknowledged
and read back. Release the original delayed PUT: observed writes are
`[200,300,200]`; final quota is 200. PASS of this **counterexample** means
the failure was reproduced, not that Native write safety is accepted.

At the [reviewed PasarGuard source](https://github.com/PasarGuard/panel/blob/eba70d68eaa5a89e34ffebb47c8fd5ce054d0e43/app/db/crud/user.py#L1254-L1357),
modify_user assigns expire/data_limit directly before committing; the current
client sends an unconditional PUT by remote ID. At the
[inspected Marzban source](https://github.com/Gozargah/Marzban/blob/7f396db3e703d71a28060bc9ce4a532ec64cb1f4/app/routers/user.py),
PUT by username invokes
[crud.update_user](https://github.com/Gozargah/Marzban/blob/7f396db3e703d71a28060bc9ce4a532ec64cb1f4/app/db/crud.py),
which assigns expiry/quota directly. The existing GET checks are necessary
but cannot be a remote commit fence. These are source observations at the
specified references, not an inventory of every deployed provider/version
or a claim that an actual installed account was mutated.

The Owner subsequently ruled out provider-side development. This evidence
remains valid, but the earlier remote-side conditional/idempotent or proven
quiescence prerequisite is superseded by the existing-API recovery contract
in section 5. The counterexample must remain visible in acceptance; do not
relabel reproduction as safety. The new edit executor is implemented in this draft but has not been
activated by production callers. Use PasarGuard only and continue financial, service,
migration and caller convergence work using existing APIs only.
PR #233 retains its separate review/integration gate.

### Checkout commands and opening-balance rehearsal (033/034; inactive draft)

The persisted checkout draft is the command reference. 033 binds it to
one service operation and protects the original provider/account pin,
plan, amount, quantity, and operation kind. The Wallet button funds and
reserves that command atomically. A declined Wallet attempt does not
consume the draft or block selection of another payment method. Manual
receipt issuance and approval share this admission and settlement path.
The checkout callback entry points for purchase (standard/custom),
renewal, volume/time add-ons and Wallet recharge, bank timer/API checking,
and automatic renewal have source-level connections to the shared
financial/service worker. The services Cron can advance due commands.
A held live service lease must not occupy every bounded worker batch.

New hosted invoices persist their original merchant credential/profile
before issuance. A repeated issuance request never creates a second
invoice when the first response is uncertain. A signed NOWPayments IPN
under the original merchant secret can identify that exact original
invoice and child, including recovery of an unacknowledged creation;
the worker still obtains authenticated merchant verification before
credit. The child has separate check and verified timestamps. NextPay
and Zarinpal verification use the pinned original merchant context.
Browser-return data alone is not payment evidence. The old invoice
notification is emitted on original successful issuance, not on reopen.
The full multi-method customer/operator UI workflow has not yet passed
an end-to-end cutover acceptance gate.

034 preserves the existing owner index name and requires unique userid;
it does not guess how to merge duplicate owners. The CLI-only migration
rehearsal in tools/wallet_first_cutover.php computes a deterministic
per-owner signed balance digest, inventories unresolved legacy claims,
and creates exact opening entries in a single transaction after explicit
snapshot and unique-index checks. Zero and negative balances are preserved.
Replay is read-only for the identical complete opening set. Stale
snapshots, duplicate owners, incomplete existing journals and failed
insertion are rejected. No receipt, debit, subscription or Native effect
is recreated by that opening operation.

**Activation is not authorized.** The runtime no longer includes the
parallel payment-specific service executors or Marzban implementation.
The original provider/payment state is retained solely to preserve and
reconcile historical evidence. New checkout admission requires the new
schema and rejects incomplete migration rather than selecting an old
writer. Owner-authorized manual transfer is a separate cutover activity.
Do not install 033 or 034 separately on any bot. The prior delayed Native
write counterexample remains an explicit limitation of the existing
provider API, not a test of safe remote CAS. Exact candidate validation,
independent review and installed acceptance remain separate gates.


### Operational reporting acceptance (current fresh schema 039)

- Payment credit: primary + configured report channel + actual human approval actor, if any. Applies to all registered receipt methods, including API/timer/hosted, duplicate receipt replay, service supersession and credits immediately allocated to a service. No misleading customer top-up notice for a consumed credit. Report the credit ledger before/after, not a later balance snapshot.
- Service result: BUY/RENEW/ADD_TIME/ADD_VOLUME all emit source-neutral completion reports after verified local finality, independently of Telegram customer delivery; original manual receipt edit and delivery-dependent confirmation remain separate. Automatic renewal retains its dedicated report with the service and order identifiers.
- Other Wallet credits: original ledger-backed adjustment, transfer and onboarding reward use a wallet_entry_id followup source (039, no synthetic invoice); purchase referral and resolution use their existing verified sources with the Wallet entry correlation. Administrative debits and refund/close decisions are also searchable. Transfer sender is not a fabricated administrator; report both original balance movements. A primary recipient who also approved receives one report.
- Other useful service events: manual/existing subscription import now uses the general report audience for both customer/admin registration. Trial, administrator bulk creation, plan change, transfer/reassignment, direct gift and deletion retain their existing notification paths; their direct reports are best-effort and are not upgraded to financial outbox sources in this slice. Failure diagnostics remain private to authorized administrators.
- Report channels are the configured rewardChannel. New configuration stores the resolved Telegram numeric chat ID; username casing is canonicalized and malformed destinations are logged without vetoing a financial decision. Missing/invalid destinations cannot receive a report. Channel/role changes do not silently redirect sealed reports; the same original attempt journal and attention inbox support explicit redrive of unknown/rejected packets. Optional general-manager copies do not block mandatory owner/actor/channel staging on a contended preference read. No credentials, original receipt image, or private subscription URLs in financial/service summary reports.


### Final-audit remediation on #204 / #234 (2026-11-24)

This slice follows the last red candidate 557f7276b876cdabcfe1b7e8f108ae50546e751e
(base 484c80e6ee030e8656da96edc3000311d10327b5, tree
b6e2c10eb397b0a3338d8b318bd2ddc2ef6acb75). It does not authorize activation.

- Current receipt, service-completion, wallet-movement, rejection and
  purchase-referral admin reports share the same recipient staging policy.
  Deduplicate owner, authorized optional staff, real human actor and
  configured channel; preserve the acting operator's private moderation
  keyboard on manual rejection. Purchase-referral customer notice remains
  separate from administrator reports. The original funding ledger actor,
  not a guessed administrator or the reward worker, identifies a human
  approval. No invented actor for automatic financial confirmation.
- Current payment method display labels are owned by the receipt registry.
  Reports derive hosted capability from that registry rather than a second
  provider-specific report map. Ordinary service completion reports carry
  the original payment/request ID and hash, operation ID, subscription
  identity, and immutable debit entry/before/after values; report unlimited
  volume as unlimited, not zero gigabytes.
- Outbox admission requires both InnoDB tables, current source/attempt
  columns and exact non-prefix unique source and attempt identities.
  Missing current Wallet or attempt-journal unique keys fail closed.
  The current schema itself is unchanged by this audit slice.
- An unavailable optional staff preference read no longer aborts a valid
  credit or referral. Existing receipt-copy identity can be staged when
  permission discovery is contended, but dispatch must revalidate current
  authorization. An invalid channel is not guessed or silently redirected.
- Explicit UTF-8/utf8mb4 fixture connections and SQL output, and a stored
  byte round-trip assertion, address the observed Persian report encoding
  mismatch. The exact CI environment must still pass. Removed 32 proven
  unused catalog keys; changed/new texts are individually pinned, not a
  blanket snapshot acceptance. Direct Telegram-wire parity remains required.

The current functional suite uses real PHP, disposable MariaDB and owned
loopback peers, and validates rendered source packets and callbacks. It is
not installed/live acceptance or proof of every external Native ordering.

### Shared financial and operational reports

Successful non-financial trial, direct/scheduled gift, administrator bulk
creation, subscription import, plan change, transfer/reassignment and
deletion now stage reports in the same local transaction that commits their
verified effect. Migration 040 adds an exclusive, unique operational source
identity to the existing followup intent table. No fake payment, ledger
entry, service operation, additional outbox table or worker is needed.
The sealed original event and recipient-specific packet remain valid after
the order changes or is deleted. The common claim/attempt/acknowledgement,
429 retry, ambiguous-delivery recovery and current recipient authorization
rules apply equally to financial and operational reports. Original staff
actor and primary owner/channel are deduplicated; unsolicited notification
preferences do not suppress the actual actor's result. Private import
controls remain off channel copies. Scheduled gifts resolve the actual
enqueue actor from their bound original source claim; no actor is invented.

Manual RENEW completion has one report per recipient, preserving the actual
approving operator's menu. BUY delivery acknowledgement remains a separate
event, never inferred from successful local creation. Report references
prefer the receipt that actually funded the service over a draft that did not
fund it. Unused renewal-success copy is retired.

Large paid BUY and partial-retained resolution reports partition overflow
account names and original order IDs into bounded continuation messages.
Each page is sealed with a deterministic event identity and separate
delivery attempts. Administrator bulk uses the same partitioning: each
verified local creation commits its own report first, then a completed or
partially completed batch may compact untouched packets into a summary and
continuation pages under its source lease. Compaction verifies original
seals and the unchanged audience and cannot replace an attempted message.
If the process stops before compaction, the individual committed packets
remain deliverable, with no Native recreation.

Direct and scheduled gift customer notices now commit with the verified
gift and staff reports, using the same followup journal and attempt engine.
Each original recipient has one sealed text packet bound to the exact local
subscription attachment. A changed or removed attachment is terminally
skipped; transient SQL failures defer. Interrupted post-commit dispatch
can resume without repeating a Native gift, and ambiguous Telegram writes
require an explicit original-packet redrive. The old best-effort customer
send and notice projection are removed; their three obsolete warning copy
keys are retired, not replaced with a separate notification system.
The existing attention inbox distinguishes customer redrive from staff
report recovery using existing confirmation copy. Free trial and administrator
bulk creation now atomically record the already verified Native CREATE alongside
the local order, sealed customer packet/parts and staff reports. The existing
multipart delivery ledger, maintenance scheduler and explicit part recovery
handle both paid and nonpaid sources; a nonpaid source uses its actual
effect identity, never a synthetic payment or wallet debit. The shared
attention inbox exposes uncertain nonpaid parts using the same confirmation
copy. Ordinary on-demand subscription display and diagnostics stay separate.
Fresh schema construction 041 admits the effect-owned delivery source;
042 removes nineteen unused Native transport and gift cursor columns.
043 completes the current-only delivery schema: remove obsolete pay_row_id,
attempt_id, pay_type and pay_fingerprint from the delivery intent (not from
the original financial journals); require a current operation/effect, order
and positive part count, and a real part/claim identity on every attempt.
The one readiness check verifies the current tables, source/attempt uniqueness
and source constraint. Historical whole-packet evidence stays outside fresh
install; the construction-only DDL refuses incompatible existing rows rather
than deleting or converting them. Never run the construction sequence on a
deployed database.
Old server and custom-plan editing handlers, the old direct debt wizard
aliases, two ineffective bot settings (protocol switching and old link-update
location) and their unreferenced Persian text are removed. Debt is still
available through the current debit/negative-balance confirmation flow.
The staff attention inbox uses a non-reserved pagination alias and the same
actor-bound part confirmation for paid and nonpaid messages. Empty or partial
settings documents render without empty keyboard rows, implicit writes or
enabling unconfigured gateways. The robot switch displays its existing
operational default and its first toggle actually changes it.
Original financial and Native provenance fields remain evidence, not runnable
historical operations.
Owner decision (24 Nov 2026): a definitively ineligible report recipient
is terminally skipped without redirection, another Telegram attempt, or
manufactured acknowledgement. Incomplete permission reads remain retryable;
other original recipients proceed independently. Restored access does not
reactivate a skipped packet. Previously unknown/rejected deliveries preserve
their attempt evidence and are never automatically replayed; a permitted
explicit redrive detects ineligibility and skips only that recipient.
The prior delayed-write counterexample remains a Native API limitation. Keep
independent review, owner decision and installed acceptance gates separate.

### Forward-only runtime and entitlement bounds

The owner explicitly retired old Telegram messages, callback flows and
historical report recovery as compatibility requirements. New report
attempts must have their own durable attempt identity; a missing attempt
record cannot be finalized using a pre-upgrade exception. The current
service schema is mandatory. Retained financial evidence and active account
provenance are data, not an alternative execution path.

Purchase, renewal, automatic renewal, plan administration, extensions and
new gifts use the shared whole-unit rules. The expiry bound is derived from
the signed INT Unix timestamp in orders_list (2038 boundary) and the
operation's relevant clock, not a ten-year/36500-day product cap.
Absolute Native mutation validates its actual anchor again. Catalog writes
cannot silently round whole units in the current FLOAT columns; values
above the previous million-GiB UI cap are permitted if exactly representable.
Server capacity uses the shared signed integer quantity range. Administrator
bulk input is canonicalized before advancing the wizard, not truncated on
a later callback. Trial/bulk order expiry must match its verified Native
create effect; obsolete millisecond arguments and the former Marzban target
join are no longer used. The old warning-toggle alias and exemption-wizard
compatibility branch are retired. Current error messages are corrected in
the explicit catalog delta, rather than preserving abusive old wording.

The current admission and execution tests cover the real representable
duration, an overflow rejected before funding, successful long renewal,
missing current report attempts, and exact catalog integer boundaries.
These changes require fresh exact-candidate CI and independent review;
they do not remove the operational acceptance limitations above.

### Completed-service sales and customer history

`src/Finance/ServiceHistory.php` owns the shared original-checkout completion
predicate, customer history and commercial sales projection. A linked receipt
is not a service sale until its matching original operation is completed.
The net commercial amount is the original debit (or zero for a free completed
commercial command) less its original compensating refund, validated against
the owning wallet entries. A fully cancelled operation is not a sale; a partial
BUY retaining accounts counts once with only the retained value. Automatic
renewals count once on their original completed command, without synthetic
`pays` records. Server and plan attribution comes from the original service
target and frozen quote, not the current catalog. Historical unbound terminal
non-top-up payments retain their historical reporting value; they do not
become executable current commands.

The existing sales-labelled administrator totals, agent sales, server/plan
rankings, custom-date sales amount and scheduled sales report use this
projection. Date windows retain original request timestamps (automatic
renewal uses command creation time); agent classification remains the current
agent flag. Wallet-origin sales mean direct Wallet checkout and automatic
Wallet renewal, not every externally funded checkout that passes through
the ledger. Sales counts are original operations, not provisioned accounts,
delivery parts or report attempts. The abandoned-purchase statistic counts
distinct users with expired/cancelled BUY requests and no completed original
purchase.

Incoming-payment metrics remain separate: top individual payments, top users
by paid amount, per-user payment totals, agent-list payment sums, receipt
statistics and the successful-payment count in the custom-date report still
use receipt facts. The existing top-user detail displays paid amounts, not
service value; the existing order inventory statistics describe stored
orders. No payment amounts, historical evidence, or report labels are rewritten.

Discount buyer/non-buyer audiences reuse the same completed-purchase fact
as gateway eligibility. Eligibility and target restrictions are checked again
inside the owner/draft/discount transaction before consumption. A stored-order
audience remains distinct from gateway active-entitlement classification.

### Current customer continuation and settings namespace

- Public custom-menu content is limited to the literal case-sensitive
  MAIN_BUTTONS prefix with a nonempty label. Operator content deletion
  rechecks the operations capability under lock and deletes only the
  exact authorized main-menu or TICKETS_CATEGORY record. Numeric IDs
  never grant access to unrelated settings.
- Standard, custom, bulk and renewal checkout presentation uses the
  original owned unclaimed draft and its pinned entitlement and target.
  A single discount redemption route rechecks that same draft and its
  current admissible target before atomic price/counter updates. A
  redeemed draft cannot consume a second code; a zero-priced result
  uses the free confirmation through the existing durable local funding
  engine without any monetary method gate or debit. The renderer uses the
  original quantity, price and purchase mode, including Back navigation.
  With sales open, payment-method availability never hides eligible standard,
  custom or representative purchase offerings. Both the initial card and
  atomic redemption use the same current configured monetary-method list
  under owner/settings locks, including audience and locally required
  payment configuration. A positive residual without any eligible method
  leaves price, context, counters and usage unchanged; the existing code
  button is retained for retry with the approved explanation. A zero
  residual bypasses the monetary-method test. Positive time/volume add-ons,
  Wallet recharge, manual crypto network selection and new renewal gateway
  navigation read the same locked options from the committed draft.
  The shared unclaimed-draft guard excludes issued attempts and prior
  service effects. A single payment keyboard builder covers standard,
  custom, renewal, day/volume and recharge modes without changing their
  approved labels, order or callbacks. Recharge keeps its own NOWPayments
  flag and never offers Wallet payment for itself.
  Method discovery returns only method identifiers and available network
  names. First customer invoice issuance uses current persisted method
  configuration under the admission transaction, rather than request
  snapshots. Manual destination validation and hosted checkout input/quote
  validation (including an explicitly configured optional NOWPayments
  payout policy) are shared with discovery. No network or payment effect
  is needed to discover eligibility. Original issued attempts retain
  their pinned quote and independent settlement/recovery path. A locally
  eligible method is not a claim of merchant acceptance or sufficient
  Wallet balance.
  Existing payment expiry/review/cooldown policy definitions are shared
  between admin settings and the runtime; the top-up amount validator is
  shared between input and admission. Existing configured ranges and
  business rules are unchanged.
- The retired shared-inbound availability menu and settings toggle have
  no supported Native saleable-plan meaning. Individual capacity and
  purchase selection share the current provider-validated active
  catalogue with available server capacity. Retained provenance is
  not deleted merely because a historical UI classification is retired.
- Genuine private /buy and /mysubs (including supported /start payloads
  and membership continuation) use normal authenticated navigation,
  registration, membership, bot-state and phone gates, followed by the
  existing purchase or subscription route. No configuration-time
  customer dispatch remains. No additional financial executor is added.

### Offering purpose and nonpaid admission

Provider-supported active root-category plans and available servers share
one structural offering query. A positive base price identifies standard
purchase offerings; a zero base price identifies dedicated trials. A
configured, enabled custom purchase uses a positive-base commercial
template, with its own units and price. The administrative bulk catalogue uses the same
structural eligibility, not the ordinary commercial price filter.

Ordinary free trials require the current enabled trial setting, the
eligible zero-price plan and an unused owner entitlement, rechecked before
capacity reservation in the existing nonpaid effect transaction.
Primary-admin trials use their own exact update reference; administrative
bulk provisioning rechecks the operations capability under the owner lock.
Neither a stale button nor a caller-supplied admin flag authorizes creation.

Legitimate zero-effective commercial purchases (standard/custom), manual
renewals and time/volume add-ons are original commercial service commands,
not trials. A raw zero price must be authorized by the current eligible
offer, configured custom rates or current agency entitlement, or the
existing primary-administrator standard-purchase exception. A redeemed
discount-code zero is proven by the atomically consumed original draft
discount. No monetary method enablement or audience permission is needed
for those commands. Only the approved free-confirmation button and
existing navigation are displayed; no external payment attempt or Wallet
debit is created. Positive-amount checkout retains its existing method
selection. The existing command callbacks and durable service engine are
reused; no second executor, new schema or compatibility layer is needed.

Sales closure independently denies new ordinary commercial drafts and
first admission of positive and zero unissued quotes. Main menu, welcome,
purchase shortcut and catalogue use the same primary-owner-only purchase
exception; secondary staff do not see unusable ordinary purchase buttons.
Administrative provisioning remains distinct. The trial setting,
automatic-renewal opt-in policy and authorized administrative provisioning
remain independent. Already issued receipt attempts and funded operations
retain their immutable original evidence and recovery semantics.
