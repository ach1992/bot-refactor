# AChBot financial lifecycle — current contract and controlled consolidation

**Owner acceptance:** 2026-10-16, roadmap [#29](https://github.com/ach1992/bot-refactor/issues/29), controlling finance [#26](https://github.com/ach1992/bot-refactor/issues/26).  Current source baseline: released `v0.10.0-rc.3`, `main@9d2f731bb23e0618ff3607df5bcbdd8ecdcb8b71`. The Owner reports the RC3 runtime installed and generally working; this is **not** full financial acceptance. This file is the specialized *implementation/assurance* contract; `docs/PROJECT-SPEC.md` remains canonical product intent and GitHub Issues/PRs own current work/evidence.

## Accepted objective, boundaries and non-goals

Make **all existing and future payment methods** use the same *verified payment and durable fulfillment* policy, while keeping each provider's request/authentication/proof/status semantics in an isolated adapter. Manual receipts, wallet payment/transfer, administrative debt and scheduled approval are **not bank gateways**, but must share the relevant transaction/idempotency/effect rules. A common router does **not** mean forcing distinct financial semantics into one PHP function.

- Stay in this repository, PHP 8.1+/MariaDB, current bot, its already supported providers and Marzban-only service operations. No cross-project Foundation, new service, worker framework, duplicate orchestration, speculative future gateway implementation or wholesale database redesign.
- Keep the original 23-table historical V9 DDL/goldens immutable. The *current* installed runtime additionally requires the separately accepted nullable `server_info.panel_group_id` column. No real DB migration is authorized by this refactor discussion. Favor current schema for initial characterization; permit **only justified additive, backwards-compatible persistence changes** after a separately reviewed design, original-data audit, explicit Owner schema/deployment approval and independently restorable backup.
- Preserve installed `baseInfo.php`, existing URLs (`pay/index.php`, `pay/back.php`), Telegram callback identifiers, Cron entrypoint paths, price/currency expectations, supported `pays.type` business outcomes and existing Marzban account identity/capacity rules. Do not silently reinterpret historical orders, mark unsupported panels as Marzban, or convert refunds into automatic wallet credit.
- A privileged admin may deliberately create a negative wallet **debt** with reason/actor/audit; user-controlled spend/transfer/purchase/renewal must not create or deepen debt. This policy is **not yet fully implemented/accepted**. Do not blanket clamp `users.wallet`.

## Current source inventory (verified at RC3 baseline)

| Responsibility | Existing source | Observed gap / preservation constraint |
|---|---|---|
| External gateway start | `src/Finance/GatewayInitiation.php`, `pay/index.php` | NOWPayments, NextPay, Zarinpal use distinct setup, stored provider IDs; preserve redirects and URLs |
| External gateway verify | `src/Finance/GatewayReturn.php`, `pay/back.php`, `GatewayClient.php` | Separate branches and uneven response/transport semantics; a browser return is untrusted, a successful API response still needs exact order/amount/provider binding |
| Business effect | `GatewayReturn.php::doAction()` | Inherited `pays.state='paid'` before downstream wallet/service effects; no durable atomicity across MariaDB and external Marzban/Telegram |
| Bank/card receipt | `AutomaticReceipts.php`, `Routes/ManualReceipts.php`, `Routes/ManualApproval.php` | Different approval state transitions and race/partial-failure behaviors; must not be treated as identical gateway returns |
| Customer wallet | `WalletSettlement.php`, `Routes/WalletTransfer.php`, `Routes/AdminWallet.php` | Some `FOR UPDATE`/transaction controls exist; explicit admin debt and durable, actor-attributed audit remain separate requirements |
| Crypto/chain | `CryptoChecker.php`, `settings/tronChecker.php`, `Routes/CryptoAndExchange.php` | TRON success assignment bug, no TLS peer validation and `pays.tron_price` not in pinned schema; **NOT certified for real settlement** |
| Database | `database/legacy-schema.sql` | `pays.hash_id` UNIQUE; `pays.payid` NOT UNIQUE/provider-scoped; no general durable effect/outbox log. `users.wallet` is signed integer |

## Required provider adapter and shared-core boundaries

```text
Existing public browser/Telegram/Cron/admin entrypoints (compatible adapters)
  -> Provider-specific authenticated/verified evidence
       initiation, callback/webhook identity, API reconciliation,
       provider statuses, currencies, amount and trusted external identity
  -> Common result: VERIFIED / PENDING / PARTIAL / REJECTED / REVIEW
  -> Exact local payment-intent correlation and atomic state claim
  -> Idempotent business effect: wallet / purchase / renewal / extension / receipt
  -> Durable external fulfillment/retry or bounded manual reconciliation
       (a successful payment != completed Marzban/Telegram delivery)
  -> User/operator status, safe diagnostic correlation, no credentials/log leaks
```

**Non-negotiable invariants:**

1. Every transition validates the exact local order, expected provider and transaction attempt, authorized amount/currency/rate/units, unique provider evidence, and status. Never trust a browser redirect, query flag, or user-entered reference as payment proof.
2. Duplicate, simultaneous, late or reordered callbacks, Cron and administrator actions must not duplicate wallet credit/debit or subscription provisioning. A unique payment attempt and a unique *business effect* are different identities. A database row marked `paid` is **not** proof external Marzban fulfillment succeeded.
3. Unknown, unauthenticated, missing, partially paid, provider-transport-failed or not-yet-final results remain reviewable; do not report definite failure or success without trusted evidence. Negative provider verdict must never overwrite a concurrent positive final state.
4. Provider API calls, DB commits and remote fulfillment cannot be one atomic database transaction. After any interruption (before/after DB commit, provider timeout, Telegram failure, Marzban success without receipt), the system must recover safely or explicitly surface a reconciliation case. Never auto-repeat a non-idempotent external creation blindly.
5. Preserve existing successful flows and source-only packages while migrating consumers. Gateway adapters cannot perform direct wallet/provisioning effects; only the shared settlement/fulfillment path may request them. Introduce a second real provider through this seam before claiming the seam is general.
6. Treat money as integer units with checked currency conversion, valid positive bounds and no floating-point ambiguity in persisted ledgers. The current legacy `pays.price` and wallet units are accepted history, not permission to reinterpret old amounts.

## NOWPayments finality — Owner meaning and official provider contract

Owner's description of the hosted checkout screen saying completed / return to merchant expresses the desired *user-visible outcome*, **not a trusted signal or literal API status name**. NOWPayments documentation distinguishes:

| Provider-reported `payment_status` (from authenticated server GET) | Current agreed treatment |
|---|---|
| `finished` | Eligible for verified-success processing **after** matching invoice/payment identity, amount, currency and other accepted conditions; downstream exactly-once work remains open |
| `waiting`, `confirming`, `confirmed`, `sending` | PENDING; show progress, no wallet/service effect |
| `partially_paid` | PARTIAL; no success or irrevocable cancellation. Keep order reconcilable for top-up/manual review until an explicit later partial-payment policy is accepted |
| `failed`, `expired` | Provider terminal rejection; narrow conditional cancellation of the **selected pending** order; late funds require explicit reconciliation |
| `refunded`, unknown, malformed response, HTTP/network failure | REVIEW/PENDING; do not claim success or automatically credit/refund/cancel |

Verified primary guidance: [NOWPayments status definitions](https://nowpayments.io/help/dashboard/how-to-view-past-payments-and-purchases), [API integration guide](https://nowpayments.io/blog/nowpayments-api-explained-customize-your-payment-gateway), [server verification vs success redirect](https://nowpayments.io/blog/how-to-add-crypto-payments-faster-with-the-nowpayments-node-js-sdk). A hosted invoice has its own invoice ID and may produce distinct payment IDs; never globally identify them through an unscoped `pays.payid` without checking uniqueness and ownership. The current hosted-invoice `success_url` is composed with the local `hash_id`, **not a guaranteed provider payment ID**: a redirect containing only that hash must show a pending/reconciliation message without any negative or positive state transition. A separately authenticated IPN/payment-ID lookup will be required for reliable return-path completion, including invoices that may receive several payments. Existing handler currently correlates the API's `invoice_id` to stored `pays.payid`; **actual live invoice/payment metadata and amount matching are not independently proven**. Do not upgrade that inference to real-money readiness.

### First source-only integration increment (Issue #26 branch; NOT deployed)

- Add a pure, provider-specific result classifier in the existing runtime module `src/Finance/GatewayClient.php` and reuse it for NOWPayments and NextPay callback dispositions; do not add a speculative provider SDK or alter public entrypoint paths.
- NOWPayments: only `finished` enters inherited business fulfillment; intermediate/partial/unknown/network states have no financial SQL mutation, definite final negatives use exact-row compare-and-set, ambiguous invoice IDs fail closed, and verified payment ID is checked if returned. These changes **do not** solve inherited positive settlement concurrency and external fulfillment atomicity.
- On disposable socket-only MariaDB, execute **actual** PHP modules with fake cURL providers, capture real database state and notifications; pin RED test against RC3 `confirmed` and `sending` premature delivery, then GREEN with success, pending, partial, collisions, provider errors, race and sequential replay.
- Historical `tools/v9_contract.py` and original immutable runtime hash expectations stay untouched. New tests run against the actual current post-V9 source.

## Sequenced completion, with real decision gates

**A. Safe result interpretation (first increment).** Finish provider classification + NOWPayments nonfinal/fail-closed change and characterization. Reviewer must independently check that `finished` is necessary but insufficient until identity/amount checks; characterize Zarinpal/NextPay response handling before broader unification. No schema edit.

**B. Shared payment claim and identity (second increment).** Before adding schema, prove or disprove concurrency/restart-safe exact-once effects using current 23 tables. Inspect non-secret historical data for duplicate provider IDs and legacy states. If current schema cannot enforce provider-scoped `(provider, external_attempt_id)` and effect uniqueness, propose minimum additive indexed provider-attempt/effect persistence and SQL migration on disposable copies; no unreviewed table drop, destructive backfill or live migration.

**C. Durable fulfillment/recovery.** Move `doAction()` and *all* gateway/manual/automatic wallet/renewal routes behind one verified payment claim plus operation-specific idempotent effect and recoverable (existing Cron or operator) work. Record incomplete fulfillment separately from confirmed receipt. Avoid duplicate Marzban account creation even after crash/retry; preserve order/account identity. Test crash/restart interleavings, duplicate deliveries, partial remote success and operator reconciliation.

**D. Financial policy and TRON.** Add separate authorized admin debt action with actor/reason/audit; guard every user debit. Require trusted TRON asset/network/amount/finality/confirmation source, SSL verification and migration strategy for missing quoted amount. If these cannot be established, keep TRON unaccepted rather than pretending crypto payments are safe.

**E. Assurance and operations.** PHP 8.1/8.4 exact-head CI, native 23-table tests, initial public callback/entrypoint compatibility and no-real-provider controls, author-separated financial-security review, Owner approval before high-consequence merge, separate real DB migration (if accepted), staged operator verification and production rollout. Preserve RC3 rollback artifacts and private configs; no production banking/Marzban/SQL/Cron or shared aaPanel/LSAPI change is authorized by source work.

**Current open questions for later Owner decision:** whether old records/attempts can be safely backfilled into a unique provider/effect ledger; handling actual partial/late/refunded receipts and multi-asset quotes; TRON amount units, token contract/network and finality; external provisioning retry and compensation policy. Do not invent these outcomes from synthetic tests.
