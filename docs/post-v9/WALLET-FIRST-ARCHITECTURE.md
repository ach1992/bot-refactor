# Wallet-first payment and service architecture

**Decision:** Owner accepted the Wallet-first separation and a cohesive root-cause
refactor on 2026-11-24. This is the target architecture, not a claim about the
installed Stable runtime. The owning work item is
[#204](https://github.com/ach1992/bot-refactor/issues/204); current execution and
acceptance evidence belong there and in the implementing PR. Baseline source:
`484c80e6ee030e8656da96edc3000311d10327b5` (Stable v0.10.14).
[Audit evidence](https://github.com/ach1992/bot-refactor/issues/204#issuecomment-7111121827).

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
old Marzban origin/snapshot: migrated live PasarGuard subscriptions and their
valid pending PasarGuard operations remain supported. Preserve historical
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
  as their callers converge.
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
  funding commands. Prove extension by adapter/registration only, including
  refusal of unregistered methods and no duplicate financial effect. Such
  fixtures do not claim another production provider is implemented. Later
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
zero. Retain original invoices and audit records. An operation that was already
debited is imported funded with its original debit evidence; opening balance
already includes it, so no second debit and no fake credit.

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
For new edits store one immutable before/target/dispatch/result per operation,
with exact physical identity. Do not use an ever-growing consumed_keys JSON
or fixed 4096 limit: a durable unique operation record prevents replay. Replace
the old account mutation history authority only after migrating its outstanding
claims and preserving historical consumption evidence. Account-level ownership
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
ordinal and marker before POST. Use authenticated GET by exact username/identity;
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

Generalize existing 018/020 delivery journals rather than invent independent
senders. New delivery identity is stable source (payment credit or service
operation) plus purpose/ordinal/audience as needed; old pay/attempt/effect
references remain historical. New service delivery must not require a fake
external attempt. Complete service and create durable first delivery in the
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
before wiring any new user-visible behavior. Archive must not turn a genuine
received payment into uncredited money or invent refund/success; define
conversion of legacy Archive stop semantics with exact original proof.

## 7. Execution sequence and removal

One integration candidate (no partial production cutover). Internal commits
are reviewable vertical slices, not independent releases. Rebase/reconcile
open port-counter PR #233 through its own existing review/integration gate;
do not absorb unapproved work or revive the obsolete counter.

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
  timer fallback. Archived already-proven API money is recognizable without
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
original service operation. Archive cannot authorize another consume, nor
discard definitive evidence from a previously dispatched operation. An
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
after service invalidation/Archive, exact method quotes and actor refusal.
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
unchanged; deletion capacity/referral/report convergence is still pending.

Draft 027 extends the original 018 journal with the real service operation
source and nullable legacy payment references. Existing payment packets and
attempts remain unchanged; no synthetic pay or attempt. Automatic dispatch
shares the original claim and acknowledgement primitives and sender. An
abandoned service delivery claim becomes unknown after session loss; it
never authorizes another automatic send. Source changes prevent obsolete
packets from being sent. The existing maintenance worker can select both
historical payment packets and completed service packets fairly. The
existing whole-packet QR/text acknowledgement contract is preserved; explicit
per-part definite-rejection retry and operator retry convergence remain work.

Source-real PHP/MariaDB/loopback execution suite now passes 30 cases
(118 owned HTTP requests / 22 POSTs), including a forward 018→027 migration
with a historical packet, bulk lost response, atomic rollback after failed
delivery insert, completed-state commit acknowledgement loss, concurrent
delivery, abandoned claim, stale order refusal, sales-disabled catalogue
finalization and shared delivery queue progress. Telegram is a fixture
boundary spy; no live send. Existing subscription presentation 251,
bank-buy 72 and original manual redrive 6 tests pass. Existing Native API
limitations and the still-inactive overall cutover remain unchanged.

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
relabel reproduction as safety. No new edit executor or retry behavior has
yet been enabled. Use PasarGuard only and continue financial, service,
migration and caller convergence work using existing APIs only.
PR #233 retains its separate review/integration gate.
