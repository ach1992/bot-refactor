# AChBot financial lifecycle — current contract and controlled consolidation

**Owner acceptance:** 2026-10-16, roadmap #29, finance owner #26. Current authoritative public SOURCE baseline is v0.10.0-rc.4 at main 58d98e05839b4aa5b28e985bcd1473173f590eda, including PR #42 and PR #43. The shared provider ledger is a later UNMERGED candidate. The last Owner-reported INSTALLED version is RC3. Publication, source merge and real schema/financial acceptance are different gates. This file is the specialized implementation contract; docs/PROJECT-SPEC.md defines canonical product intent.

## Updated owner decision — provider-neutral payment persistence (2026-10-17)

After the published source-only v0.10.0-rc.4, the Owner rejected an
expanding series of gateway-named database tables and requested clear,
unprefixed shared storage. The next **not-yet-merged** source candidate
uses payment_attempts and payment_transactions across NOWPayments,
NextPay and Zarinpal, preserving provider-scoped checkout/child identity
and immutable payout/quote evidence. This is a provider-neutral attempt
boundary, **not** a completed unified financial business-effect ledger.
Do not replace historical released RC4 bytes or silently migrate any
live/historical RC4 attempts. For fresh/current/legacy RC4 schema gates,
read PAYMENT-LEDGER-MIGRATION.md; exact deployment/migration still needs
separate Owner approval and independently proven restore.

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
| Business effect | `GatewayReturn.php::doAction()` | Verified wallet top-up now claims the payment and credits `users.wallet` atomically in MariaDB; remote Marzban/Telegram and other effects still lack full crash-safe durability |
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

## R2 — owner-prioritized browser-independent invoice recovery (SOURCE ONLY)

The Owner observed missed hosted-website Return-to-Merchant clicks in production and explicitly prioritized automatically completing real provider-verified orders. NOWPayments `confirmed` is a blockchain signal; for fixed-price VPN goods it **cannot by itself** certify merchant payout. To remove the human browser dependency without relaxing status rules, merged source-only PR #42 introduced (not yet operationally activated):

- the HISTORICAL RC4 separately shipped and migration-gated NOWPayments-specific attempt ledger `migrations/post-v9/002_nowpayments_checkout_attempts.sql` (23 frozen original tables + 2 proposed narrow tables in disposable tests); a new shared design is proposed separately in migrations/post-v9/003_payment_attempts.sql. It stores trusted order↔invoice↔child-payment correlation, original USD quote, same provider HTTPS invoice URL, recovery progress. No private secret or customer dump in SQL history;
- hosted new-invoice `ipn_callback_url`, direct Telegram invoice link, and safe reuse on subsequent checkout clicks; even after the older bot message's three-hour window, an already-issued pending invoice is not silently expired. A durable `creating` reservation is committed before the provider POST; a timeout/unknown result is held for review rather than issuing a second invoice;
- NOWPayments HMAC-SHA512 IPN on existing `pay/back.php?nowpayment_ipn=1` with a **small dedicated bootstrap** (no Telegram update parsing), storing only provider evidence. Secret is configured via private `baseInfo.php`, not posted to bot chats, committed source or another public endpoint;
- bounded, quote-checked provider status lookup from **already configured** `settings/rewardReport.php` Cron. `confirmed` or `sending` show as pending; `finished` with exact IDs, expected quote and paid-in-full constraints triggers existing Telegram/Marzban/business delivery independent of browser. The buyer returning to `success_url` only sees read-only local state; forged `NP_id` cannot bypass the managed attempt and trigger direct credit;
- updated exact-head native PHP/MariaDB test matrix (including actual local HTTP IPN entrypoint) for signed/forged IPN, repeated browser click, preserved invoice URL, pending→finished, no browser return, underpayment and concurrent replay.

**Finance review remediation (source-only):** The draft now uses an atomic per-order `pays.payid` reservation before any NOWPayments, NextPay or Zarinpal initiation, making switching gateways unable to overwrite an already-active invoice/Authority. Every signed NOWPayments child payment ID is stored in a **globally unique child evidence table** (primary and all extras), and extra child deposits create operator review rather than another business effect. The provider's `finished` status remains **necessary but insufficient**: the invoice captures original Toman price plus an *explicit private merchant-approved net payout currency and minimum quote-adjusted amount*; missing payout policy blocks new checkout; insufficient/wrong-asset `outcome_amount` or changed local Toman price results in review rather than automatic settlement. Legacy NOWPayments invoices lacking a captured payout quote are now browser-return **read-only/manual review**; no amount-blind settlement or cancellation survives. Zarinpal now accepts only exact provider success code 100 (integer or canonical string) for an exact unique stored Authority; unsupported 52/-52, 101, fractional, exponent, boolean or malformed response statuses remain pending for review rather than canceling or crediting funds. Duplicate Authority refusal and simulated mid-verification races remain tested. Wallet credits and payment claims remain one SQL transaction, including intentional negative admin debt.

**Remote and operator boundaries:** NOWPayments, NextPay and Zarinpal now **defer automatic wallet refund** after an uncertain Marzban account change, because a successful remote effect may have lost its response. `settings/rewardReport.php` retains its existing reward/backup/card work and performs bounded payout reconciliation plus throttled operator notifications for `review` and stale `creating` attempts. Notifications are not a safe retry or a refund authorization. No cross-DB/Marzban exactly-once guarantee, all-IPN-lost payment discovery, live Cron verification or real provider acceptance is claimed. The narrower current effort still leaves manual/card/transfer/renewal business-effect consolidation, durable remote operation identity and TRON/debt policy under Issue #26.


### R10 — final R9 independent security review remediation (SOURCE ONLY)

The independent R9 review of `551efb3` was **COMPLETE / CHANGES_REQUIRED** because it identified one additional **BLOCKER** (a stale renewal callback's unconditional `mismatched_plan` UPDATE could overwrite a finalized payment) and one **REQUIRED** (lossy `is_numeric()` / integer coercion admitted SOAP statuses such as `"100.9"` as success). The previous six R7 findings were marked resolved and remain supported by R8/R9 tests.

- **Stale renewal callbacks:** `prePayCheck` rejects paid, expired, canceled and other terminal rows before any payout/expiration/plan mutation. A changed current plan is handled only after verifying the same account owner, supported Marzban account and original supported renewal plan. A local mismatch state transition uses an exact conditional `pending`-only `UPDATE` bound to the original row/user/order/plan and the current differing `orders_list.fileid`, and only if `payid` is unclaimed and no NOWPayments attempt exists. Already-issued NOWPayments invoices, external Authorities and `send`/paid rows are retained for manual review; stale or newly changed states never generate competing payment links. The original unchanged valid renewal checkout remains functional.
- **Exact Zarinpal SOAP success value:** only native integer `100` or the canonical string `"100"` authorizes fulfillment after the existing exact Authority and identity checks. Floats, fractional or scientific-notation strings, booleans, padded/alternate strings, null and unknown status codes remain pending for merchant review, without credit, order completion or automatic cancellation. Browser `Status=NOK` still cannot override an actual canonical provider success.
- **Proof:** new actual-route native tests cover paid/canceled/expired stale renewal replay, pending unclaimed mismatch, an independently connected second worker finalizing payment after the SELECT and before the mismatch UPDATE, claimed external and NOWPayments renewal invoices, normal renewal compatibility, and strict SOAP results across authentic/invalid types. The existing original 23-table fixture and frozen historical evidence remain unmodified; all external provider/Telegram effects are synthetic. Exact R10 PHP 8.1/8.4 CI and author-separated final delta review were completed for the separately Owner-authorized PR #42 source merge; installed operator acceptance remains a separate gate.

### R8 — second independent review remediation (source-only; NOT deployed)

The independent R7 review of `2802bee` was **COMPLETE / CHANGES_REQUIRED** (two BLOCKER and four REQUIRED). Corrective source and native disposable regressions are bundled in the current draft PR #42, without changing the original frozen V0–V9 schema, accepted provider finality, public callback endpoints, or requiring live credentials.

- **Marzban external-result boundary:** `src/Panel/Accounts.php::editMarzbanConfig()` no longer infers success from a raw `curl_exec()` string. It verifies an actual response from the intended account, cURL transport and HTTP status, parses the Marzban UserResponse, and checks returned account identity, expiry and traffic limit against the requested change. A malformed body, rejected PUT, timeout, or uncertain reset is an unconfirmed remote operation, never a fabricated success. `RENEW_ACCOUNT`, `INCREASE_DAY` and `INCREASE_VOLUME` therefore cannot update local subscription state or send a verified-success notification based only on a failed API response. **No automatic retry or refund on uncertainty.**
- **Zarinpal evidence precedence:** the browser's `Status` is advisory only. Exact order/unique `Authority` plus authenticated `PaymentVerification.Status=100` may settle even if a stale browser flag is `NOK`. Previously verified `101`, malformed, transport and unverified status codes (including `52`/`-52`) remain pending for operator investigation; none are promoted to definite cancellation without authoritative provider-version-specific negative semantics. The earlier pending-state CAS is intentionally not exercised by an unsupported status mapping. Do not conflate browser rejection with authenticated merchant settlement.
- **Shared renewal:** a non-null object with `success=false` is not renewal success for *any* of NOWPayments, NextPay or Zarinpal. The provider-independent check precedes local expiry mutation, service-delivered messaging or financial completion; failures/uncertainties remain reviewable.
- **Stale checkout buttons:** the prepayment route inspects a recorded NOWPayments invoice/`creating` attempt irrespective of the selected old gateway button. Valid hosted URLs are reused rather than expired/reissued. Time-based expiry uses an exact still-pending, still-unclaimed compare-and-set.
- **Concurrent private alerts:** `review_notice_claim_token` and `review_notice_claimed_at` provide a bounded five-minute lease for mutually exclusive Cron notification. Acknowledgment checks that claim token, stage and the **same number of durable child receipts observed before sending**. Any new signed child atomically clears the prior claim, so an older in-flight send cannot mark the new receipt as already notified. Failed Telegram sends remain retryable; a successful alert retains the 24-hour throttle.
- **Proven wallet compensation:** `addToWallet()` verifies one actual credited row and signed INT bounds (preserving intentional negative admin debt). `refundToWalletAndNotify()` only sends positive compensation messages and returns success after a real durable wallet update. All provider-driven `doAction()` compensation cases return `ambiguous`/operator review instead of falsely returning `compensated` when the wallet credit fails. This does **not** add a global wallet refund/outbox ledger or automatically replay an uncertain refund.

These corrections were driven by native source execution on disposable MariaDB with explicitly injected Marzban HTTP/timeout/response outcomes, Zarinpal browser/provider disagreements, missing-user/overflow refunds, three-provider renewal failures, old-button invoice preservation, and deterministic nested-alert/new-child interleavings. Preserve exact-candidate CI and fresh author-separated delta review as separate gates.

**Unresolved:** private key activation, actual aaPanel Cron frequency, IPN provider POST reachability, invoice payment ID discovery when *all* IPNs are lost (official `GET /v1/payment/?invoiceid=...` requires an API key plus a short-lived JWT; documented JWT issuance requires merchant account credentials), real final payout proof, and source-remote fulfillment atomicity. Do not store merchant login credentials on the bot or implement this higher-privilege flow without a reviewed least-privilege alternative and explicit Owner security decision. The current `doAction()` CAS stops many repeated callbacks but marking `paid` before outside-DB Marzban effects remains a review/manual-reconciliation concern. No production changes or finance-safety certification follow from synthetic tests. Read the authoritative [NOWPayments migration/operations checklist](NOWPAYMENTS-MIGRATION.md). Work later stages B/C/D are not closed by this bounded implementation.

## Sequenced completion, with real decision gates

**A. Safe result interpretation (first increment).** Finish provider classification + NOWPayments nonfinal/fail-closed change and characterization. Reviewer must independently check that `finished` is necessary but insufficient until identity/amount checks; characterize Zarinpal/NextPay response handling before broader unification. No schema edit.

**B. Shared payment claim and identity (second increment).** Before adding schema, prove or disprove concurrency/restart-safe exact-once effects using current 23 tables. Inspect non-secret historical data for duplicate provider IDs and legacy states. If current schema cannot enforce provider-scoped `(provider, external_attempt_id)` and effect uniqueness, propose minimum additive indexed provider-attempt/effect persistence and SQL migration on disposable copies; no unreviewed table drop, destructive backfill or live migration.

**C. Durable fulfillment/recovery.** Move `doAction()` and *all* gateway/manual/automatic wallet/renewal routes behind one verified payment claim plus operation-specific idempotent effect and recoverable (existing Cron or operator) work. Record incomplete fulfillment separately from confirmed receipt. Avoid duplicate Marzban account creation even after crash/retry; preserve order/account identity. Test crash/restart interleavings, duplicate deliveries, partial remote success and operator reconciliation.

**D. Financial policy and TRON.** Add separate authorized admin debt action with actor/reason/audit; guard every user debit. Require trusted TRON asset/network/amount/finality/confirmation source, SSL verification and migration strategy for missing quoted amount. If these cannot be established, keep TRON unaccepted rather than pretending crypto payments are safe.

**E. Assurance and operations.** PHP 8.1/8.4 exact-head CI, native 23-table tests, initial public callback/entrypoint compatibility and no-real-provider controls, author-separated financial-security review, Owner approval before high-consequence merge, separate real DB migration (if accepted), staged operator verification and production rollout. Preserve RC3 rollback artifacts and private configs; no production banking/Marzban/SQL/Cron or shared aaPanel/LSAPI change is authorized by source work.

**Current open questions for later Owner decision:** whether old records/attempts can be safely backfilled into a unique provider/effect ledger; handling actual partial/late/refunded receipts and multi-asset quotes; TRON amount units, token contract/network and finality; external provisioning retry and compensation policy. Do not invent these outcomes from synthetic tests.


**PR #43 source remediation — wallet identity, ambiguous acknowledgement and finance error audiences
(draft, not yet integrated or published):** The original users.userid index is
**not unique**; the transfer route locks and checks *all* matching records and
fails closed on duplicate sender/recipient identity, then binds each monetary
write to the captured users.id plus the expected userid/guards. No legacy
DDL or uniqueness migration is implied. An attempted COMMIT whose
acknowledgement is lost/false is an **unknown financial outcome**, even if a
later rollback API call reports success; support must verify the account
history before suggesting any compensation or retry. In manual/wallet
extension and renewal error paths, a remote effect followed by local SQL or
notification failure is likewise under review, not a proven remote failure.
Customers receive review/status/support guidance without infrastructure
terms; private operators receive only bounded reason codes, transaction/order
references and the unverified remote-effect warning, never raw upstream
exceptions or response bodies. The bounded privacy checks consist of
first-argument literal inspections **plus** executable synthetic-provider/
owned-MariaDB audience tests; static counts alone do not certify dynamic or
mixed-audience safety. No global exactly-once effect ledger, active-schema
migration, installed-log rotation, real provider/financial effect or production
acceptance is part of this PR. Issue #26 remains OPEN.
