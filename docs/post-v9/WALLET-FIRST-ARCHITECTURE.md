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
staff permissions. Preserve Marzban and PasarGuard, standard/custom single/bulk,
managed renewal, supported Marzban external RENEW_SCONFIG, add-ons and auto renewal.
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
around two long-lived legacy/new engines.

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
ADD_VOLUME, ADD_TIME; external Marzban RENEW_SCONFIG is a RENEW variant with
its own validated original target and quote, not a fifth payment route.
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
Multiple independent databases managing the same Native user require a proven
single-writer boundary or provider-level coordination; independent local locks
alone do not suffice.

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
Do not release the account fence for a conflicting write while the prior remote
outcome is unresolved. A stale worker must not finalize another worker's claim.
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
Foreign same-name account is a real conflict, not evidence of success. Preserve
existing Marzban constraints, supported lookup and identity proof; verify
actual behavior before permitting retries. Multi-account only executes missing
original ordinals. Complete local Order/intent exactly once per account.

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
baseline confirmed unchanged and same-target retry is safe, apply fixed
target, not another delta; divergent state requires actual reconciliation,
not overwrite or second relative increment. Next operation calculates its
target after previous completed. No repeated DAY/VOLUME arithmetic on retry.

Provider feasibility gate: same-value PUT is idempotent in isolation, not an
atomic compare-and-set against concurrent writers. A delayed older request
can arrive after a later update; a local lock cannot fence the remote server.
Before enabling autonomous retry prove a safe provider-specific execution
contract (provider conditional update/idempotency or proven ordered/quiescent
single-writer execution and reconciliation). Explicitly test delayed stale
PUT, competing administrator/other bot, delete/recreate, foreign marker, API
scope drift, real account aliases. Do not claim exactly-once external behavior
from HTTP status or a pre-write GET alone. If a provider lacks necessary
evidence, stop only the affected unsafe operation with a precise technical
cause; choose the smallest supported repair, or surface the remaining actual
provider limitation/owner decision before release. This is not an excuse for
generic manual review of normal valid operations.
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
   capacity and result), RENEW (manual/automatic + external Marzban variant),
   ADD_VOLUME/TIME (shared fixed-target engine). Introduce operation ownership
   and reuse physical identities/verified Adapter capabilities. Test actual
   provider response/commit failure sequences before replacing each path.
4. Converge all callers and maintenance: admin/transfer/reward/onboarding
   wallet writes, checkout, auto renew, free/admin primitives, delivery and
   read models. Ensure the common worker continues all funded operations.
   Inventory removal targets: per-method service branch in GatewayReturn,
   ApiPayments, AutomaticReceipts, Routes/ManualApproval, Checkout,
   Extensions, RenewalCheckout, AutomaticRenewal; separate WalletOriginal/
   WalletRenewal/WalletEdit recovery coordinators; payment-method switches
   inside ProviderRuntime, mutation history cap and old direct wallet SQL.
   Preserve genuinely unique contract, test and historical read-only migration
   logic before removing code. Verify actual call graph after removal.
5. Migration/cutover: implement reviewed forward schema transition using
   release-matched migration sequence; preserve old records and imported
   unfinished effects, existing active Native identities, balances and
   subscription/config URLs. Rehearse on synthetic copies of actual supported
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
  Native references to payment method. Test both providers and actual
  applicable operation variants, standard/custom/bulk, zero-cost, auto
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

