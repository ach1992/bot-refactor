# AChBot financial lifecycle — current contract and controlled consolidation

**Owner acceptance:** 2026-10-16, roadmap #29, finance owner #26. The immutable public `v0.10.0-rc.4` baseline includes merged PRs #42 and #43; [PR #44](https://github.com/ach1992/bot-refactor/pull/44) has since integrated the provider-shared ledger into `main` under independently approved source-only gates. Consult [GitHub Releases](https://github.com/ach1992/bot-refactor/releases) for the exact published artifact. On 2026-10-17 the identified Chili test installation was verified on-disk at RC5, its dedicated `chilibot` MariaDB database was verified after the Owner-authorized additive 003 migration (25 total tables, both new tables empty), and the Owner reported normal basic bot operation. This narrow acceptance is recorded in [Issue #26](https://github.com/ach1992/bot-refactor/issues/26#issuecomment-6225581330); other installations/schemas, live money and merchant settlement remain unverified. Publication, source merge, real database migration and financial acceptance are distinct gates. This file is the specialized implementation contract; `docs/PROJECT-SPEC.md` defines canonical product intent.

## Source-only customer wallet top-up request boundary (PR #55, Issues #26/#51)

The existing bot uses `users.step` to persist a compact `achbotWalletTopup` request state, with **no schema migration**. Only a genuine customer callback in that customer's private chat can start a menu. Its Telegram response must contain an authenticated successful `sendMessage.result.message_id`; otherwise selection remains unavailable. The fixed-amount and custom-amount buttons are accepted only from **that exact current bot menu message**. A custom amount is accepted only as a genuine new customer message in the same private chat **with `message_id` greater than the actual bot amount prompt**. This survives delayed/out-of-order updates without treating old bot callback text as fresh input. Telegram `update_id` is retained as event identity and used for ordering in active sequences, but the bot handles the [official documented random reset after at least one idle week](https://core.telegram.org/bots/api#update); prompt-relative *chat message identity* remains the authoritative freshness boundary. A legacy step or previously sent stale numeric button intentionally fails closed and the customer must restart wallet top-up from the menu. Callback names and amount rules are unchanged.

MariaDB serializes each stage transition using an **exact-step compare-and-swap** on one uniquely identified locked user record. A valid custom text or current fixed-amount selection atomically claims that stage, removes **only unclaimed** obsolete top-up drafts and inserts the new `pays` row in a **single InnoDB transaction**. Existing provider-claimed attempts, provider-issued identifiers and customer wallet balances are preserved. A failed ledger visibility check, draft `DELETE`, `INSERT` or conditional step update rolls back both the old draft and the step claim, including with mysqli nonthrow mode; a lost COMMIT acknowledgment is **review**, not permission to blindly retry. New Telegram invoice messages occur only after confirmed commit. Any post-commit Telegram delivery uncertainty must not reissue the database operation. This is a narrow local invoice-draft exactly-once boundary, **not** provider settlement, cross-system fulfillment or general payment-business-effect exactly-once certification.

Regression enforcement includes real `UpdateBindings.php`, `WalletRecharge.php` and `WalletStart.php`, persisted `users.step`, real disposable socket-only MariaDB, complete `pays`, `payment_attempts`, `payment_transactions`, wallet/step snapshots, old/replayed/group/cross-chat updates, week-id reset, concurrent PHP sessions competing for one step, failed SQL `INSERT`/`DELETE` (also under `MYSQLI_REPORT_OFF`) and uncertain Telegram delivery. The actual `config.php`/`bot.php` webhook bootstrap and any installed or real-money effect are **not** certified by this isolated route fixture. No merge, release, production migration, financial operation or Chili deployment is implied by development/CI success; retain exact-candidate independent review and separate Owner Merge authorization.

## Updated owner decision — provider-neutral payment persistence (2026-10-17)

After the published source-only v0.10.0-rc.4, the Owner rejected an
expanding series of gateway-named database tables and requested clear,
unprefixed shared storage. The merged current source uses `payment_attempts` and `payment_transactions`
across NOWPayments, NextPay and Zarinpal, preserving provider-scoped checkout/child identity
and immutable payout/quote evidence. This is a provider-neutral attempt
boundary, **not** a completed unified financial business-effect ledger.
Do not replace historical released RC4 bytes or silently migrate any
live/historical RC4 attempts. For fresh/current/legacy RC4 schema gates,
read PAYMENT-LEDGER-MIGRATION.md; exact deployment/migration still needs
separate Owner approval and independently proven restore.

## Three distinct manual payment options (2026-10-17, Issue #51)

AChBot supports manual bank transfer (`bank_transfer`), purchased gift cards (`gift_card`) and one **manual crypto selector** across purchase/renewal/extension/wallet subjects. Its independent network options are USDT BEP20, TRC20, ERC20 (provider `manual_usdt`) and native TRX on TRON (provider `manual_trx`). Administrators configure `manualUsdtWalletBEP20`, `manualUsdtWalletTRC20`, `manualUsdtWalletERC20`, `manualTrxWallet` and independent rates `manualUsdtRateToman` and `manualTrxRateToman` under the existing shared toggle `manualUsdtState`. Native TRX **requires** its explicit rate and wallet, while USDT may use a valid private exchange rate. No unconfigured or invalid address/rate is offered. The earlier `manualUsdtWallet` / `manualUsdtNetwork` pair remains usable only as a fallback for its original network, while retired `tronwallet` does not activate manual TRX.

**TRON recipient-integrity contract (PR #54 independent R1/R2 remediation):** Both USDT/TRC20 and native TRX/TRON destinations use the SAME deterministic offline Base58Check verification: canonical 34-character encoding, exactly 25 decoded bytes, a 0x41 plus 20-byte payload, and the four-byte double-SHA256 checksum. EVM addresses retain separate 0x/20-byte validation. The rule covers administrator editor, configured-network chooser, new attempt claims, legacy/JSON pinned-intent reading, receipt submission and customer instructions. A malformed historical pinned address is **retained for operator review**; the bot must not overwrite, silently repair, resume or display payment instructions for it. These checks establish destination syntax, **not address ownership, funds receipt, intended USDT token contract or chain settlement**. Official format: https://developers.tron.network/docs/encoding and https://developers.tron.network/docs/account. Addresses from those pages are non-payment fixture examples only, NEVER customer receiving accounts.

**Administrator retirement boundary:** changeBot... and changeGateWays... callbacks accept only explicitly supported identifiers represented in current administrator keyboards. Old weSwapState, tronWallet, arbitrary and cross-surface callback identifiers fail closed without changing BOT_STATES or financial state. The shared setSettings() persistence writer uses a POSITIVE list of valid fields, rejecting all arbitrary retired identifiers even if another caller bypasses a callback guard. The Owner confirms old automatic TRON, WeSwap and NextPay were never used on prior installations. Their historical migrations/adapters are not required; NextPay remains supported for new transactions. No real database records are deleted or rewritten. Source-only regression executes byte-extracted actual bot.php handlers, the real config.php writers and socket-only original-schema MariaDB, in addition to independent Base58Check vectors and native manual-payment coverage. Before separately authorized installed rollout, run a nonfinancial webhook/administrator acceptance; successful source CI alone is not proof of installed operation.

The provider-neutral `payment_attempts` entry stores original order identity, provider, Toman amount, quoted USDT (2 decimal places) or TRX (6 decimal places), asset/network, pinned recipient, rate and versioned JSON manual-payment intent in `checkout_url`. That JSON is **not a hosted checkout link** and cannot prove settlement. Existing photo evidence uses guarded `pending` → `receiving` → `review`; only independent human confirmation triggers guarded wallet or service effects. No blockchain verification, automatic chain settlement or screenshot-based credit exists. A future separately accepted verification adapter can authenticate chain proof against the persisted asset/network/recipient/amount with replay exclusion and durable effects; it must not reuse the unsafe original TRON checker. The old `settings/tronChecker.php`, `src/Finance/CryptoChecker.php` and `src/Finance/Routes/CryptoAndExchange.php` are deleted from CURRENT source/package, not merely inert. The four-worker Cron coordinator stays unchanged; any installed schedule pointing at removed code needs its own authorized operator cleanup.

A submitted receipt's Telegram photo file ID is durably saved in the same intent *before* sending to administrators. `stage=receiving` uses an existing-ledger, bounded 2-minute delivery lease. A callback that receives no Telegram message ID, loses its response, or cannot update SQL remains pending and may retry **only the same image**. Operator message delivery, invoice status (`pending -> have_sent`), and attempt stage (`receiving -> review`) are validated as one guarded transition. An old callback cannot revive a declined/approved invoice. A duplicate operator notification is possible after a lost acknowledgment, but it never authorizes a duplicate wallet credit.

The bank auto-accept scheduler checks the attempt provider and fails closed on lookup failure. It re-locks the pay row and atomically commits a valid bank-only `have_sent -> auto_cart_approved` transition with any wallet top-up credit; concurrent admin approval cannot also credit that order. Cached wallet actions and manual-crypto network choices honor the exclusive payment-attempt claim. This protects local replay and wallet effects; **it does not certify inherited remote service provisioning** (which still requires operator reconciliation on partial failure), and the retired TRON chain verifier is not active settlement code.

No provider signing/IPN is involved for these manual methods. Source-only development does not migrate actual customer databases or alter live financial records.

The user-owned wallet top-up receipt approval (`approvePayment...`) now atomically locks `pays` and the wallet owner, verifies `INCREASE_WALLET` with `have_sent`, performs a checked signed-wallet increment, and compare-and-sets `pays.state='approved'` in one MariaDB transaction. Second or competing administrator approval must not credit a second time; late declines cannot overwrite an approved receipt. Existing legacy receipts are not silently erased. Operator verification of independent settlement remains a required human step; no screenshot alone proves money arrived.

**Service operator approval boundary (PR #52 review remediation):** The custom-purchase `accCustom` callback requires the actual administrator role; all custom-purchase, renewal, day-extension and volume-extension approval handlers lock the exact `pays` row, require its exact `have_sent` state, correct business subject (custom `BUY_SUB` requires nonzero chosen day/volume, unlike standard plan invoices), persisted administrator message delivery, no competing `payid`, and a manual-provider `payment_attempts` at `stage=review` with the original photo ID for post-ledger orders. The guarded transition to `approved` must change exactly one `have_sent` row. A pre-ledger receipt without an attempt is eligible only when a real operator-delivery record survives; this is **compatibility, not verified settlement**, and a human must verify funds. Missing ledger/schema, conflicting provider, pending or terminal states fail closed. Stale admin callbacks cannot act after `auto_cart_approved`; bank Cron and operator compete on the same row lock. Remote Marzban success followed by a local rollback may remain ambiguous and requires operator reconciliation, never blind retrial.

**Standard-plan and RENEW_SCONFIG operator claim (Issues #51/#26, source-only remediation):**
The actual Checkout-issued accept{hash} administrator callback now shares the
manual service receipt guard already used by custom/extension flows. Under the
exact pays FOR UPDATE lock it verifies correct purchase or renewal subject,
persisted operator-message delivery, unmatched provider claim, and the
provider-scoped attempt at review with the original receipt photo. It commits
one guarded processing claim **before** any Marzban call. A second administrator
or a replay cannot start a second effect. Successful local order finalization
must demand checked order INSERT, capacity update, transaction BEGIN and
conditional change of that same processing row, with confirmed COMMIT even
when MYSQLI_REPORT_OFF is active. If remote creation/renewal, account N
of a multi-account purchase, local order persistence or COMMIT acknowledgment
is uncertain, never silently return the invoice to have_sent: retain the
processing no-replay fence and signal operator reconciliation. By contrast,
after a **confirmed** approved local COMMIT, a Telegram delivery/notification,
operator-button edit or referral-reward failure cannot undo the completed
service record; retain approved, signal a distinct post-commit follow-up with
safe stage/correlation in protected logs, and never reissue an account or
mislabel the financial result as uncertain. The receipt itself is not
merchant settlement evidence, and processing does not certify completed
service. Existing externally created accounts still lack a
general durable per-account effect identity: safe operator reconciliation and
the minimal additive schema decision remain open under #26. A source-only
test/CI pass is NOT installed or real-money acceptance.

All purchase, renewal, extension and wallet top-up menus use the shared manual-crypto selector and retain protected subject-specific receipt and admin effect callbacks. A mismatched method, stale callback or competing active payment attempt fails closed. `payment_attempts.stage` distinguishes awaiting evidence, upload in progress and review; `pays.state` still governs the legacy admin effect. Missing evidence cannot trigger financial finality. The existing bank auto-accept scheduler must skip gift cards, manual USDT and manual TRX, while no new automatic settlement path is introduced. Any residual inherited administrator approval concurrency risk remains controlled separately under #26 and must not be misrepresented as an accepted exactly-once system. No merge, live migration, real payment, provider call, Chili deployment or production data modification is authorized by this source-only package.

## Accepted objective, boundaries and non-goals

Make **all existing and future payment methods** use the same *verified payment and durable fulfillment* policy, while keeping each provider's request/authentication/proof/status semantics in an isolated adapter. Manual receipts, wallet payment/transfer, administrative debt and scheduled approval are **not bank gateways**, but must share the relevant transaction/idempotency/effect rules. A common router does **not** mean forcing distinct financial semantics into one PHP function.

- Stay in this repository, PHP 8.1+/MariaDB, current bot, its already supported providers and Marzban-only service operations. No cross-project Foundation, new service, worker framework, duplicate orchestration, speculative future gateway implementation or wholesale database redesign.
- Keep the original 23-table historical V9 DDL/goldens immutable. The *current source* expects the separately accepted nullable `server_info.panel_group_id` column plus the provider-shared 003 tables. Actual installed migration state remains unverified; no real DB migration is authorized by this source integration. Favor current schema for initial characterization; permit **only justified additive, backwards-compatible persistence changes** after a separately reviewed design, original-data audit, explicit Owner schema/deployment approval and independently restorable backup.
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
| Manual crypto | `Routes/ManualCrypto.php`, `Routes/ManualReceipts.php` | USDT on BEP20/TRC20/ERC20 and native TRX/TRON retain a configured address, pinned quote, customer screenshot and human verification. The removed automatic TRON/WeSwap source paths have no settlement or migration role. |
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

## Staged hosted-fulfillment hold — NextPay/Zarinpal (#26, source-only)

For **new, provider-scoped** NextPay and Zarinpal service purchases,
renewals and extensions, current-source `doAction()` now atomically claims the
original `pays` row as `paid` and transitions its existing **003**
`payment_attempts` row from `pending` to `review`, with
`provider_status='fulfillment_started'`, **before** calling Marzban. This is
a durable **operator-review hold**, not proof of remote provisioning, and
**not** permission for an automatic retry, refund or remote DELETE. The
hold intentionally survives an interrupted PHP worker, lost provider response
or a local SQL write failure. A fully returned and locally confirmed
`delivered` or `compensated` outcome transitions only its own hold to
`done` with a corresponding status. A response that cannot be confirmed
leaves the hold and original `pays`/invoice evidence intact. Wallet top-ups
continue to use their established single-transaction wallet credit; the
NOWPayments worker keeps its separately staged `fulfilling`/review contract.

The original local `increase_order` INSERT, order expiry write, purchase
`orders_list` INSERT and source capacity DML now check MySQLi results
**also under `MYSQLI_REPORT_OFF`** rather than trusting non-throwing calls.
Strict receipt verification on shared purchase/accounting helpers is **opt-in
for this hosted payment boundary**; unrelated manual approval, trial and
wallet routes keep their existing exception contracts pending their own
dedicated reconciliation redesign. No existing caller is silently switched
to a retryable error state after a potentially successful remote effect.
An uncertain remote effect followed by rejected SQL is kept under review, not
reported as a confirmed delivery. Invalid provider ownership, changed
amount or a previously reviewed attempt fail closed with no new remote
side-effect. The bounded validation uses the actual PHP functions, original
synthetic 23-table MariaDB, separately applied existing 003 and injected
crash/SQL failures; **no real provider, wallet, Telegram, Marzban, installed
webhook or tenant database was touched**.

Authorized operators on an **independently approved target**, after backing
up and confirming the exact installed version and full 003 schema, can
privately locate unresolved hosted operations using this **read-only**
query (do not post customer identities or raw result rows in GitHub):

```sql
SELECT a.id AS attempt_id, a.pay_row_id, a.provider, a.order_hash,
       a.stage, a.provider_status, a.updated_at,
       p.state AS pay_state, p.type AS pay_type,
       p.user_id, p.order_id
FROM payment_attempts AS a
JOIN pays AS p ON p.id = a.pay_row_id
WHERE a.provider IN ('nextpay','zarinpal')
  AND a.stage = 'review'
  AND a.provider_status = 'fulfillment_started'
ORDER BY a.updated_at, a.id;
```

### Verified hosted charge with an uncommitted claim (F1)

A provider-verified NextPay/Zarinpal callback can reach the local claim and
still fail a schema check, transaction start, conditional paid claim, 003
attempt read/stage update or commit acknowledgment. The handler attempts
**rollback** and **performs no remote service operation**. A lost commit
acknowledgment is itself uncertain (rollback cannot disprove an earlier
commit). The handler must NOT claim that
`review/fulfillment_started` was saved. The customer sees a generic Persian
message that the verified payment's order recording is incomplete, should
**not pay again**, should keep the payment reference and contact support.
No database/SQL, Marzban or raw exception text is shown to the customer.

On a working **protected application error-log sink**, look for this bounded
diagnostic format (the values below are placeholders, not credentials):

```text
achbot_hosted_claim_unconfirmed pay_id=<local-positive-integer> provider=<nextpay|zarinpal> code=<bounded_failure_code>
```

Codes are `invalid_input`, `schema_inspection_failed`,
`transaction_begin_failed`, `paid_claim_failed`,
`paid_claim_unavailable`, `attempt_read_failed`, `attempt_mismatch`,
`ledger_stage_failed`, `ledger_stage_unconfirmed`,
`commit_unconfirmed`, and `preclaim_local_error`. The latter specifically
covers a failed initial local payment read or subject inspection **before**
the hosted paid/review claim helper is entered. A different protected event,
`achbot_hosted_service_effect_needs_review pay_id=<local-id> provider=<nextpay|zarinpal> code=postclaim_effect_exception`,
distinguishes an exception **after** a successful paid claim, when remote
service effects may already exist. Neither customer response claims a durable
review record or instructs the customer to repeat payment.

**The log is correlation evidence, not a durable payment/review claim**, and
its delivery/retention depends on the actual private PHP error-log
configuration. Neither the provider reference, token,
raw SQL exception nor user message is placed in these diagnostics.

After restoring a healthy read-only DB connection and verifying its exact
003 table layout, the operator can locate one **logged** local payment ID:

```sql
SELECT p.id AS pay_id, p.hash_id, p.payid AS issued_reference,
       p.state AS pay_state, p.type AS pay_type, p.price,
       a.id AS attempt_id, a.provider, a.provider_reference,
       a.stage, a.provider_status, a.updated_at
FROM pays AS p
LEFT JOIN payment_attempts AS a ON a.pay_row_id = p.id
WHERE p.id = ?;
```

To find potential **unlogged** unresolved checkouts, inspect
`payment_attempts` with `provider IN ('nextpay','zarinpal')`,
`stage='pending'` and matching `pays.state IN ('pending','send')` in a
bounded, access-controlled read-only query. **These rows are candidates,
not proof of paid funds**: ordinarily unpaid invoices look the same.
Correlate the local payment ID, original issued provider identity, quote,
trusted provider dashboard/settlement evidence, any previous request logs,
and actual local and remote order effects. For Zarinpal, an already-verified
status `101` is not independently sufficient for automatic settlement;
obtain trusted provider-side evidence before any manual decision. If the
local schema or log is unavailable, collect and retain provider-side payment
history for later authorized reconciliation rather than guessing a state.

The operator must inspect for a later legitimate successful callback before
any recovery action, to avoid duplicating effects. Never blindly repeat
external provisioning, refund, mark an attempt paid/done, edit live SQL or
expose payment identities in public Issues. There is no automatic outbox
for a transaction which failed **before** the review marker committed; this
limitation remains a separately authorized global financial recovery concern
under #26.

### Final review closure and multi-account partial effects (Issue #26)

For **new hosted NextPay/Zarinpal service payments**, a confirmed
`delivered` or `compensated` outcome attempts the existing guarded
`payment_attempts.review → done` transition. An SQL call returning success
is **not** proof that the guarded UPDATE matched a row. The current source
requires exactly one updated row and otherwise emits a protected diagnostic:

```text
achbot_hosted_fulfillment_finish_needs_review pay_id=<local-positive-integer> provider=<nextpay|zarinpal> code=<closure_unmatched|closure_sql_unconfirmed>
```

`closure_unmatched` means no exactly-one guarded stage transition was
observed; `closure_sql_unconfirmed` includes failure/exception to perform
the update. Neither case automatically retries an external account effect,
marks the attempt delivered, or refunds a verified payment. The diagnostic
contains no provider response, customer message or raw database exception.
When the shared table exists but a legitimate pre-003 service invoice has
no attempt row, or a hosted wallet top-up has no service-review closure to
perform, the helper preserves the original no-op behavior without creating
an artificial mismatch alert. On databases **without migration 003**, a
failed guarded closure UPDATE is silent only after MariaDB reports error
`1146` for a missing table **and** a read-only, single-table
`SELECT 1 FROM payment_attempts LIMIT 0` probe independently confirms
that `payment_attempts` itself is missing. Unlike `SHOW TABLES`, this
probe does not confuse an existing but privilege-hidden table with absence.
A `1142` access denial, a different SQL failure, or an uncertain probe
retains the bounded `closure_sql_unconfirmed` diagnostic. The hosted
claim also uses this privilege-aware check: an inaccessible existing 003
ledger cannot silently permit a pays-only claim or remote fulfillment.
Both throwing MySQLi and `MYSQLI_REPORT_OFF` are characterized on
the disposable 003-absent/accessible/inaccessible fixture for both
providers; the failing statement's original errno is captured before
subsequent SQL can overwrite it. The normal, successful modern guarded
closure performs **no extra schema query**.

An existing **service** attempt with a nonmatching stage must instead be
correlated through the bounded log. The diagnostic requires reconciliation,
not an assumption of lost funds or a license to create or replay an effect.

**Historical pre-007 hosted checkpoint (superseded by the current
payment-owned capacity contract below):** A disposable native two-account
BUY_SUB characterization previously covered one successful Marzban
account operation followed by an uncertain second operation for **both**
hosted providers. In that older flow the existing local
`pays` remains paid, the shared attempt stays
`review/fulfillment_started`, the first local `orders_list` record may
already exist, and no global capacity decrement has yet completed.
A duplicate callback does not reissue either remote operation. The fixture
also covers a successful two-account baseline (both local orders,
corresponding capacity decrement and a guarded `done/delivered` stage).
These are **observed source semantics under synthetic provider boundaries**,
not an endorsement of their completeness. In particular, a partially
created remote account can exist without matching per-account durable
effect evidence, and local capacity may need independent reconciliation.

The operator must verify the original provider settlement, enumerate
every expected account individually on the correct underlying panel,
compare actual remote UUIDs to local order rows and subscriptions, and
review the plan's remaining capacity before any Owner-authorized
correction. Never decide compensation from a single failed remote response
or re-trigger the entire multi-account checkout. Durable per-account effect
identity, an automated reconciliation algorithm and operator action
approval are still **OPEN** in #26 and may require a separately accepted
persistence/architecture decision.

Reconcile each result against the verified provider charge, original local
plan/order, independent Marzban account readback (including each line item),
and customer-visible evidence **before** a separately authorized manual
decision. A `paid` row, a `done` stage, or a local callback alone is not
a transactional proof that every remote account operation completed. Existing
pre-003 payments without a provider-scoped attempt remain on the guarded
legacy `pays` behavior and are not automatically backfilled. This bounded
hold does **not** provide global exactly-once business effects across
wallet/manual/Cron pathways, an append-only per-account fulfillment journal,
a trustworthy multi-account recovery mechanism, remote idempotency,
automated state reconciliation, or permission for live SQL/release/deployment.
Those remain under Issue #26, with operator E2E delegated to the Owner in
#31/#51 only after an explicitly authorized installed release.

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


#### Standard operator approval safety after PR #66 independent review

For a manually reviewed standard BUY_SUB, the durable reservation of the
entire `agent_count` is coupled to its payment `processing` claim. The
conditional capacity update happens **once before CREATE**, not afterward.
Failures before claim COMMIT roll back both claim and reservation; after an
unknown remote outcome, the claim and reserved capacity stay held for
operator reconciliation. A missing or replaced plan/server never permits
retargeting to another Marzban host, including RENEW_SCONFIG edits.

Verified external effects and local order/accounting completion remain
separate from Telegram QR, message or markup success; positive API outcomes
are required, and any failed notification after successful local COMMIT is
a delivery-only issue. A referral credit must also be independently
confirmed before telling the inviter it succeeded. Never retry a referral
credit or account CREATE merely because a post-COMMIT Telegram call failed.

A genuinely historical 23-table operator-delivered receipt remains
eligible for human financial review when **both** 003 tables are proven
absent. Permission failure, incomplete migration and missing operator
delivery evidence cannot be treated as a valid historical exception.
Native source-only R1–R6 regression evidence lives in
`tools/test_post_v9_standard_integrity.py`; no migration is auto-applied.


#### Shared capacity and per-payment referral recovery decisions pending

The independent re-review of PR #66 (`b06ca4e`) identified that
standard BUY_SUB's guarded full-count reservation alone does **not**
make the shared `decreaseServerCapacity` default safe: several
legacy wallet/custom/bulk/trial callers still may ignore a zero-row
conditional UPDATE. Failing after a non-idempotent external CREATE must
not leave a rearmable payment with no durable effect checkpoint.
Resolve the entire interacting reservation/fence contract rather than
mistaking a late exception for distributed exactly-once behavior.

A successful service COMMIT and independent reward decision must not
depend on customer Telegram delivery. At the existing helper boundary,
a confirmed wallet UPDATE now returns true independently of later failed
Telegram notifications; each inviter/admin/channel notice is attempted
separately with protected target-role diagnostics. A rejected or unconfirmed
wallet UPDATE still returns false, with zero credited notices. Real-helper
MariaDB regression covers both branches, including `MYSQLI_REPORT_OFF`,
false/null Telegram responses and thrown Telegram exceptions. This is a
source-only correction to *meaning of the boolean*, **not** proof that
the same payment cannot invoke the credit again. A referral credit which is
not attempted, confirmed, uncertain or notice-failed still needs payment-scoped
auditable evidence; an `error_log` line does not establish it. Any new
additive journal schema or migration contract requires separate Owner
acceptance before installed operation. Source-only improvements are not
permission for tenant SQL migration, real refunds, retries or
manual replacement of balance.

### Minimal per-account Marzban effects (Issue #26, proposed Migration 007)

This is the **new source-only incremental implementation**, NOT an installed
migration or a settlement/completion certificate. Migration
`migrations/post-v9/007_service_effects.sql` adds **one** indexed InnoDB
`service_effects` table without changing the frozen original 23-table schema,
payment identity migrations 003, or current customer records. Install 001/003
where applicable first. 007 must be explicitly approved, preflighted,
backed-up and installed on the chosen database **before deploying a runtime
that uses it for paid account creation**; deploying only the PHP ZIP would
otherwise stop new paid service fulfillment for safe manual review.

**Current PR #67 hosted-capacity remediation (independent review
BLOCKER-PR67-1):** This revised proposed 007 CREATE includes an explicit
reserved_count on each effect row. **Hosted payment** ordinal 1 stores the
entire payment's quantity; every subsequent ordinal stores zero and must
validate that first-row hold and the previous verified account.
The **manually approved standard** path already debits capacity inside its
original receipt-processing claim. In that SAME claim transaction it stores
a payment-scoped manual_hold marker (ordinal 0) with the full count.
The first manual CREATE atomically consumes that marker, with no second
capacity UPDATE; all manual CREATE rows store zero. A missing, unexpected,
mismatched or previously consumed manual hold fails closed before POST.
A short InnoDB transaction locks the exact pays row, verifies paid
user/plan/server/provider and requested count, performs a guarded full-count
server_info.ucount UPDATE and inserts ordinal 1 of the paid effect with
reserved_count=accountCount; it COMMITs **before the first Marzban POST**.
This same server counter is updated by manually reviewed standard BUY_SUB
capacity claims, so independent purchases serialize under InnoDB.
Insufficient quantity, SQL failure, missing/old schema, or rejected COMMIT
produce no new remote request. Partial/missing GET, second-account uncertainty,
post-COMMIT delivery failure and callback replay **never** release the held
count automatically or issue another CREATE. Neither manual nor hosted
success handlers may decrement capacity a second time. The paid charge remains operator-reviewable;
an insufficient full-count reservation is not permission for a speculative
wallet refund or a false completed/delivered page.

This is **a revised CREATE-only proposal**, not an implicit in-place upgrade.
An installation that already has *any* earlier 007 table must STOP and
inventory its current columns, constraints, row states, capacity and remote
accounts. A separately independently reviewed, data-preserving forward
migration, tested restore and explicit Owner authorization are required
before switching that installed database to this contract. Never rerun
007_service_effects.sql, drop its existing rows, infer previously held
capacity from account counts, or install any migration as part of the
source-only release ZIP.

**Combined PR #66 + PR #67 manual-standard boundary:** A historically
operator-delivered receipt may still be evaluated against its original
pays and message evidence when both 003 tables are proven absent, but
receipt eligibility is NOT permission to issue an unjournaled account.
The current standard BUY_SUB approval verifies the mandatory 007 unique
identity constraints under the locked payment row **before** it reserves
capacity or commits processing. Missing/partial 007 fails closed and
leaves the original receipt and server free-capacity unchanged for human
review. With properly installed 001/007, the old receipt can be fulfilled
once under 007 identity/readback rules even when original 003 evidence is
genuinely absent. Renewals do not create accounts and retain their
separate eligibility and no-replay contract. The real native regression
tests exercise both schema states, not a mocked success or real tenant DDL.

Every line of a `BUY_SUB` payment carries a unique local
`(pay_row_id,effect_kind='create',ordinal)` reservation with a random immutable
`operation_id`, pinned Marzban logical server/group **and concrete
endpoint fingerprint**, exact username, plan, bytes, expiry and request
fingerprint. A unique `(target_key,remote_username)` constraint prevents
accidental reuse of one Marzban account name across separate payments on
the same explicitly grouped physical panel; otherwise `target_key` is
scoped to its individual logical server. The **ordinal is the line-item
identity within that one paid order, NOT a count of server accounts**.
The reservation commits before the external POST, while a short local
payment-row lock protects validation and the unique line reservation.
A malformed/missing unique index blocks dispatch. The Marzban CREATE includes
a one-operation `note=achbot:v1:<operation_id>`, fixed expiry and the original
username. Its answer and a subsequent **timeout-bounded, status-checked
authenticated GET** of that exact username must match username, note, bytes
and expiry before a transition
`dispatching -> verified`. The byte quota and expiration must be exact
nonnegative integer JSON values (or canonical decimal integer strings);
fractional, exponent-encoded or overflowing provider values are not accepted
by PHP integer truncation as proof of paid delivery. A successful matching `orders_list` INSERT
atomically joins its exact new `id` to the effect and marks
`local_recorded` in the caller's local transaction. Hosted purchases
commit each order INSERT and ledger link in one short SQL transaction,
never while a remote POST is in flight; link failure rolls back that
local order rather than leaving an unlinked row. If a later account
fails, earlier independently committed accounts remain reviewable.
The referenced historical `order_id` remains recorded after a permitted
subscription/order deletion; its correctness is checked by joining to the
live order **at link time**, but no permanent FK to deletable
`orders_list` can block normal customer/admin/expiry cleanup.
The ledger's immutable parent payment remains retained.

An unavailable POST response, GET/permission failure, mismatched note, changed
logical panel grouping or concrete endpoint, SQL failure or lost commit
acknowledgement **does not
authorize a second remote CREATE, a newly randomized name, an automatic refund
or a false delivered status**. The row remains available for privileged
operator reconciliation. Remote account counts, plan capacity, or a current
404 are never proof of success or non-execution; an unrelated account can be
created/deleted at any time. The remote `note` is mutable: readback is
corroboration, not a cryptographic remote idempotency guarantee, and absence
after a possible deletion requires investigation. There is **no automated
claim that exactly-once delivery or end-to-end recovery is solved**.

The first consumers are standard manually approved `BUY_SUB` and hosted
NOWPayments/NextPay/Zarinpal `BUY_SUB`. Existing original wallet/customer
checkout, custom/manual variants, old scheduled receipt processing, renewal,
deletion, free trial and independent admin account creation are **not yet
migrated to this effect journal**; their existing fences remain unchanged.
Those source paths cannot claim the new journal's assurance. Move them
incrementally only after their payment/transaction boundaries and actual
operator proof are reconciled. No new Cron, reconciliation actor, account
backfill, repair button or external replay is introduced by 007.

**Owner-performed test gate** after independent source review and a separate
authorized disposable installation: confirm installed Marzban fork's
`POST /api/user` 200 response, 409 no-rename behavior, persistent `note`
on readback and unchanged username/data/expiry, provider login/GET timeout,
two-account partial creation and readback, multiple logical entries sharing
a physical panel via explicit `panel_group_id`, concurrent unrelated
create/delete, Telegram operator/customer status and real payment proof.
Use only disposable test accounts and authorized test financial instruments.
Capture bounded operation/order/effect identifiers without exposing tokens
or real customer data. No tenant SQL/production installation performed here.

### Provider-neutral service modifications: verified result and bounded self-repair

The source-only remediation for Issue #26 addresses historical false success
when a bot-reported renewal, day or traffic increase was not actually changed.
The public application boundary is **achbotApplyServiceEdit(connection, serverId,
change)** in src/Purchase/Core.php. Financial claims and provider transport are
separate. The wallet, manually reviewed receipt, automatic bank receipt,
hosted gateway and automatic-renew paths now use the shared boundary.

Only Marzban is currently supported. A future panel, such as Pasarguard,
requires explicit opt-in support, its own authenticated edit and exact account
readback implementation, and source and installed tests. Unknown panel types
return an unsupported result with **no provider request**. A new payment gateway
likewise requires trusted settlement evidence, but not a separate account-edit
algorithm.

A normalized successful result requires success=true, verified=true,
applied_expire in seconds and applied_bytes, all independently read from the
expected physical panel and account. Plain HTTP 2xx or a Telegram callback is
not a delivery certificate. The Marzban adapter checks username, original note,
expiry, bytes and active status using authenticated, timeout-bounded GET.
Remote fractional, scientific or overflowing numeric data is refused rather
than converted to an apparently matching integer quota/expiry.
A lost PUT reply followed by matching readback is already complete and is
not repeated. If strict GET still confirms the exact original state, at most
one corrective PUT with the SAME absolute values is allowed; no second relative
increment or traffic reset. Foreign or unknown states, changing target or
failed authentication remain uncertain, with no blind retry.

The current adapter uses a bounded connection-level lock on the configured
physical-panel-group + username, falling back to exact endpoint + username
when no group is assigned. Thus even multiple bot entries with distinct URLs
but one explicitly shared physical panel are serialized. It does not claim
to serialize edits by unrelated systems, multiple databases or incorrectly
grouped panels. Traffic reset is separately read back and never blindly
repeated.

PR #68 high-assurance remediation preserves the initial authenticated
physical endpoint across token acquisition, account readback and traffic
RESET. A mutable server record cannot silently select a different target
for RESET or forward the original bearer token there. Both the initial and bounded corrective Marzban UserModify PUT now send
only the specifically purchased absolute expiry and/or quota; a quota-only
edit omits expiry and a duration-only edit omits quota. Status, inbounds,
proxies, note and reset strategy are never sent. Marzban's optional fields preserve concurrent
operator configuration rather than overwrite it. Before any bounded
corrective PUT, the same authenticated account must still have its original
status, inbounds, proxies, traffic reset strategy, note and exact
integer expiry/quota; foreign modifications leave the payment under review.
An administrator-disabled/on-hold account is never reactivated by a routine edit. A naturally expired account may be activated by a paid duration extension/renewal; a quota-limited account may be activated by a paid quota extension/renewal. The original status still remains part of the verified baseline, and foreign status drift blocks corrective PUT.
A traffic RESET is verified only when the exact account reports canonical
integer `used_traffic=0`; partial, malformed or unavailable readbacks
must not authorize the follow-on PUT or another reset.

The existing `orders_list.expire_date` is signed INT with a maximum Unix
timestamp of `2147483647`; new service edits reject any resulting expiry
outside that existing contract *before* outbound mutation. Bank-finisher
SQL also refuses out-of-range verified timestamps under either strict or
permissive MariaDB mode and re-reads persisted expiry before completion.
For previously existing orders, the scheduled bank finisher compares the
original `orders_list.expire_date` recorded before remote dispatch with
the locked live row, and conditionally updates only an unchanged row.
A newer manual, scheduled or other financial finalization therefore
prevents an older verified edit from overwriting that newer local expiry.
The older claimed payment stays nonterminal for operator reconciliation;
no extra remote edit, wallet refund or payment claim is authorized.
This optimistic SQL boundary is not a global lock on independent
Marzban administrator actions, which remain subject to review/operational
coordination.

**Marzban unlimited quota and authoritative service expiry (PR #68 review R6/R7).**
Marzban's authenticated account quota may be explicit JSON `null` for
unlimited data or the canonical numeric `0`; the bounded Marzban adapter
normalizes either *present* representation to `applied_bytes=0` for strict
same-account readback. Missing quota fields and noncanonical finite values
remain invalid. Paid renewals can keep unlimited quota, and an absolute
change may explicitly switch between limited and unlimited; an additive
GB extension on an already unlimited account is refused **before** remote
effects because it would accidentally lower the customer's existing service.
The scheduled renewal sweep recognizes explicit null as unlimited and does
not treat it as a depleted finite quota.

For day extensions and full subscription renewals, the authenticated
provider's `verified.applied_expire` is the authoritative local expiration:
the provider may start duration from the *later* of remote expiry and now.
Local `expire_date + days` and separate clock-based estimates are not
proof of the delivered term. Hosted payments, reviewed manual receipts,
direct wallet flows and the automatic-renewal journal now use exact remote
absolute expiry, a signed-INT bound, an original **pre-remote** order snapshot,
a locking identity/previous-expiry compare-and-set and readback of the
persisted SQL value. The order, audit and final payment transition commit
as one local transaction. Manual/wallet claims (and wallet debit reservation)
are durably nonterminal **before** any external edit, rather than holding
an InnoDB transaction across HTTP. Hosted and automatic-bank/renewal
paths retain their own previously established nonterminal claims: their
claim lifecycles are not interchangeable. After an uncertain HTTP, stale
local order, SQL fault, crash or ambiguous commit, claims remain reviewable;
no callback may replay the remote edit or debit, customer-facing Persian
notices may not falsely announce completion, and automatic refunds are
forbidden without operator reconciliation. No source test authorizes an
installed tenant SQL/Marzban/financial operation.

**Automatic bank edits for grouped Marzban aliases (PR #68 review R8).**
A paid order may legitimately keep a plan from its *original* logical
server after the customer moves its label to a different server on the
same explicitly configured physical Marzban panel. For automatic-bank
`RENEW_ACCOUNT`, `INCREASE_DAY` and `INCREASE_VOLUME` receipts, the
payment must still match the order's user, original plan, current logical
server, exact account remark and quote. The bank preflight uses Core.php's
existing order/group authorization instead of incorrectly requiring the
plan's old logical server to equal the current order server; an unrelated
panel is rejected *before* any remote request. After a verified remote edit,
the finisher re-checks Core authorization under the existing payment-then-
order SQL locks: revoked group membership, payment/order identity drift,
stale expiry and failed SQL leave the durable bank claim `processing` for
operator review, with no false approval, retry or speculative refund.
The original claimed payment's type, plan, quote/amount, optional order ID
and description remain pinned across both checkpoints: a coordinated
payment-and-order rewrite or equally sized quote substitution cannot
retroactively approve an edit dispatched for a different paid subject.
Standalone `RENEW_SCONFIG` retains its original exact plan/server rule.
Mandatory disposable tests execute the real PHP bank scheduler, Core.php
and MariaDB transaction/lock/CAS path with a counted provider spy for all
three authorized and denied alias routes, group revocation, stale identity,
replay, coherent invoice/plan and quote drift, remote uncertainty and SQL
failure. These are source-level tests,
not installed/financial/remote-provider acceptance.

**Queued Marzban next-plan preservation (source-only precaution).**
At upstream Marzban master `7f396db3e703d71a28060bc9ce4a532ec64cb1f4`
the account `UserModify` implementation deletes an existing `next_plan`
when a normal PUT omits that field; the traffic RESET implementation also
deletes a queued `next_plan`. This differs from the route's generic
omitted-fields-preserved documentation. Therefore account edits with a
non-null observed `next_plan` now fail closed BEFORE any PUT or RESET,
leaving the paid claim for operator reconciliation. The service coordinator
additionally re-reads the pinned authenticated account, original limits,
status/config and `next_plan` immediately before RESET and before the
initial PUT; a newly observed next plan or foreign change blocks dispatch.
Four source-native regressions cover initial and interleaved queued plans
for quota-only and combined renewals. Marzban does **not** offer an atomic
version/If-Match precondition for this API; a concurrent external actor
could still change a plan after the final GET but before the request.
The installed Marzban server's version/behavior and these interleavings
must be validated on a separately approved isolated operator account;
source-only tests cannot prove that guarantee. Do not auto-refund or replay
uncertain provider effects.

The existing extension quote schema can store fractional values while its
historical service audit stores integer days/GB. A fractional paid quote must
NOT be silently rounded down and marked delivered. Until separately accepted
precision-safe units/accounting exist, these requests are refused before
any remote PUT or reset, leaving the financial claim reviewable.

The scheduled bank-acceptance path for service edits now reserves a
nonterminal payment state first. Only a verified account effect plus original
paid-target/order identity and one atomic local order/audit/payment COMMIT may
be reported as approved. Telegram failures after COMMIT cannot undo service.
If the remote outcome or SQL commit is unknown, preserve the financial claim
for review; never automatically refund or repeat a possibly applied change.

**Limits:** automatic self-repair is bounded to the current active request,
while the original and requested absolute values are still in hand. After
worker crash, external panel mutation or lost SQL acknowledgement there is
not yet an end-to-end persisted before/after intent and unattended reconciler
for every wallet, manual, hosted and renewal flow. The BUY_SUB creation journal
does not imply such protection for edits. Durable post-crash reconciliation
remains a separately gated Issue #26 requirement. Never infer success from
remote account counts. No installed database or real provider operation was
performed for this source-only change.
