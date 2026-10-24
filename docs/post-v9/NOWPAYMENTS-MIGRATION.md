# NOWPayments invoice continuity/IPN/reconciliation: source-only deployment gate

**Controlling work:** [Issue #26](https://github.com/ach1992/bot-refactor/issues/26), merged finance [PR #42](https://github.com/ach1992/bot-refactor/pull/42) and shared-ledger [PR #44](https://github.com/ach1992/bot-refactor/pull/44). The immutable **public v0.10.0-rc.4** release shipped NOWPayments-only 002 SQL, but real installation/application of 002 is NOT verified. The merged current source instead uses provider-shared `payment_attempts` / `payment_transactions` via **003**, with an optional separate, non-destructive import only for exact historical RC4 schemas. Review [PAYMENT-LEDGER-MIGRATION.md](PAYMENT-LEDGER-MIGRATION.md) and the exact [GitHub release](https://github.com/ach1992/bot-refactor/releases) before applying **any** SQL. Source merge or source-only publication does NOT authorize live migration, Cron/IPN activation or real settlement.

## User result and payment meaning

The buyer receives a durable HTTPS provider invoice link in Telegram. The provider's browser return is optional, and is **never evidence** of merchant settlement. An authenticated signed NOWPayments IPN stores the linked provider payment ID without creating an account in its HTTP request. The already scheduled `settings/rewardReport.php` Cron, **if actually installed and frequent enough**, fetches authenticated payment status, then calls the existing current business fulfillment only for API `finished` with matching exact invoice, merchant order, USD quote, deposit and paid-asset fields. A `confirmed` or `sending` result is **visually acknowledged as processing**, not credited or provisioned. This follows NOWPayments' recommendations for fixed-price goods rather than exposing the merchant to premature-payout risk.

**Operator caveat:** if the existing backup/report Cron is scheduled only hourly or does not run due to permissions/CWD, user delivery will be slow or missed despite valid IPN. Operator verification of the actual schedule is a release gate, not a test passed by GitHub CI.

The customer does **not** have to click Return to Merchant. When the existing business fulfillment succeeds, it already emits Telegram notifications and subscription links; final IPN handling does not depend on browser lifecycle.

## Shared additive storage (NOT approved for live database)

The new source-only migration is migrations/post-v9/003_payment_attempts.sql.
It defines **two common domain tables**, not a new table per gateway:
payment_attempts (provider, merchant order, quote amount/currency, provider
reference, original checkout URL, local Toman amount, payout floor and stage)
and payment_transactions (signed or otherwise independently verified
child evidence; unique external ID per provider, with a provider-matching FK).
NextPay and Zarinpal now also reserve their gateway attempt and external
checkout reference in the common ledger before/after issuing a non-idempotent
provider request; their callback verification policies are unchanged.

The released RC4 file migrations/post-v9/002_nowpayments_checkout_attempts.sql
created historical achbot_nowpayments_attempts and
achbot_nowpayments_child_payments tables **if an operator ran it**.
Those old tables and any payment records MUST NOT be silently dropped, ignored,
renamed or auto-backfilled. The separately gated one-time data-copy script
migrations/post-v9/legacy_rc4_nowpayments_import.sql is only for exact-schema
RC4 old -> empty common tables, after a verified isolated restore and
owner-authorized maintenance plan. See PAYMENT-LEDGER-MIGRATION.md.

Existing pays.payid is not unique or provider-scoped. The shared ledger
preserves exact provider-scoped identities, a snapshot of merchant amount
and payout obligations, and all additional provider child IDs without
mistaking checkout/browser return for confirmed payment. Multiple deposits
do not create duplicate service or credits; extra/partial/refunded deposits
stay under operator review. These tables are **not** an atomic outbox for
remote Marzban effects or a universal exactly-once financial accounting
ledger. Original 23 historical tables and migration 001 are unchanged.

**Live schema changes require separate explicit Owner authorization,
a restorable backup, original data audit and staging rehearsal.** A source
ZIP neither includes nor executes SQL. Shared migration 003 uses CREATE TABLE
without IF NOT EXISTS: pre-existing same-named tables must be audited, not
silently accepted as compatible. A partial DDL failure requires stopping
and inspecting the schema before any independently approved retry.

## Single private IPN secret (never send into Telegram chats)

NOWPayments dashboard provides **IPN Secret Key**. Enter it only in the **existing private, untracked `baseInfo.php`** as:

```php
$nowpaymentsIpnSecret = 'REPLACE_WITH_PRIVATE_NOWPAYMENTS_IPN_SECRET';
```

Protect the private file with its existing host-level permissions. Do NOT commit it, paste it into GitHub, send it through a Telegram administrator chat, write it into any public log, or display it in the gateway settings menu. The existing public `baseInfo.example.php` and historical V9 private-file template remain frozen. The separately stored `PAYMENT_KEYS.nowpayment` API key remains unchanged.

**Ordinary merchant payout — no extra private settings:** The Owner accepted the same NOWPayments conversion/network fees as the previously working hosted checkout. Leave `$nowpaymentsSettlementPolicy` **undefined** in `baseInfo.php` for the default flow. New invoices still snapshot the original USD quote and Toman amount; signed IPN plus existing API Key/Cron must verify `finished`, exact invoice/order/payment identity, correct USD quote, **the complete requested customer deposit** (`actually_paid >= pay_amount > 0`) and an authenticated **positive actual merchant payout** (`outcome_amount > 0`, valid asset code). Missing/malformed evidence, underpayment, partial status or currency/quote mismatch remains pending or manual review. Ordinary merchant payout fees may reduce the net proceeds; unlike the previous stricter mode, no fixed merchant minimum or assumed 1:1 USD-to-crypto exchange rate is invented.

**Optional stricter payout floor** for merchants who explicitly want one: existing installations that already set it retain the same immutable per-invoice checks. If needed, add **only to private** `baseInfo.php`:

```php
$nowpaymentsSettlementPolicy = [
    'outcome_currency' => 'REPLACE_WITH_VERIFIED_PAYOUT_ASSET',
    'minimum_outcome_per_usd' => 'REPLACE_WITH_APPROVED_NET_RATE',
];
```

These placeholders are deliberately invalid; do not add this array at all unless actual payout asset/network and approved minimum net units per quoted USD are known. An explicitly present **invalid** policy still blocks issuance rather than silently downgrading security. The exact payout currency and net amount floor for a strict invoice remain pinned to that invoice even if later defaults change. No source change reinterprets an existing strict invoice as a default-fees invoice. Only the Owner can approve a different merchant-fee risk policy.

A new hosted invoice is issued with `ipn_callback_url = {$botUrl}pay/back.php?nowpayment_ipn=1` and `success_url = {$botUrl}pay/back.php?nowpayment&hash_id=<merchant-id>`. Ensure `$botUrl` is the correct canonical public HTTPS URL ending in a slash, the IPN endpoint is reachable via POST, and valid IPN signatures are tested on a **separate owned staging environment**. Gatewall/Cloudflare/WAF/hosting proxies may block provider POST. No aaPanel hosting, TLS, DNS, firewall, Cron, or IPN dashboard configuration has been changed by source development.

`pay/back.php` now uses a **minimal isolated IPN bootstrap** that does not parse incoming JSON as Telegram updates. Bad signature, unknown local order, duplicate external provider IDs, wrong invoice/quote and wrong method are rejected without balance mutations. IPN response is bounded, cryptographically checked and does not make provider/Marzban/Telegram requests.

## Scheduled work and operational gate

`settings/rewardReport.php` is an **existing** Cron filename: it retains reward reports, backups and automatic card receipts. It additionally processes at most three matching pending NOWPayments attempts per run after verifying status against the actual provider API; there is no new daemon, service or standalone Cron job. The **actual live schedule and runner were never verified**. For prompt completion the Owner/operator should confirm it executes safely approximately every 1–5 minutes and doesn't get blocked by slow backups/provider calls; do not assume a one-hour Cron gives real-time service.

NOWPayments explicitly states that **invoices have no expiration date**, while an individual child payment is tracked for seven days and repeated deposits can create a *new* payment ID. Do not treat `attempt.created_at` as child-payment expiry: a valid `finished` payment can arrive on an older invoice and still needs verified settlement. Known child payments are polled at bounded per-tick frequency; terminal negative, ambiguous and underpaid outcomes require an explicit review policy. Repeated/extra child deposits must not trigger duplicate financial effects. All authenticated child IDs are durably stored in `payment_transactions` with a unique provider ID, before acknowledging IPN. Even a third or later child remains findable. A **new additional child payment identifier** with no evidence of incoming funds (`waiting`, `failed`, `expired`) **does not** move an otherwise completed invoice into financial `review` or alert an operator. The authenticated child identity/status remain durable. A subsequent authenticated `confirming`, `confirmed`, `sending`, `partially_paid`, `finished` or `refunded` event does move the invoice to `review` for money/fulfillment reconciliation **without a second wallet credit or remote provisioning**. Out-of-order old `waiting` evidence cannot downgrade a more mature funding status. Failed identity persistence returns non-2xx for provider retry. **Partial/refunded/additional-deposit allocation and refunds remain operator-led decisions**, not automated financial effects.

Concurrent Cron runs use compare-and-set attempt claims, leaving unfinished or ambiguous remote fulfillment for **manual reconciliation without blind replay**. A NOWPayments-verified payment with an uncertain Marzban create/renew/update result is marked `review` **without automatic wallet compensation**, because a remote timeout does not prove failure; this avoids refunding an account that may have been created or modified. This **no-refund-on-uncertain-remote-effect** guard also covers NextPay and Zarinpal provider-driven Marzban operations; old/manual/card financial paths remain separate work in Issue #26. An already-paid local order is never provisioned a second time merely due to late repeated IPN. This does not yet guarantee durable exactly-once Marzban fulfillment after process crash: inherited `doAction()` changes payment state before all remote side effects and remains part of Issue #26's next coherent work. The scheduled runner now invokes `achbotNpAlertOperator()` only for *actionable financial evidence* in outstanding `review` attempts. It does **not** send finance warnings for untouched invoices, extra unpaid child IDs, or technical `creating` reservations. One authenticated funding/status change can re-arm an operator notice. **There is no daily repeat** of the same unchanged evidence: `review_notified_at` is acknowledged only after successful Telegram send; failed sends remain retryable. The five-minute exclusive claim token and child-count high-water guard prevent overlapping workers and a new child during send from being hidden. The administrator-only notice is Persian, contains available user name/TG ID/username, order purpose, local Toman price and provider USD quote, invoice and payment IDs, evidence-limited child counts and reason, and a working inline link to the private bot `📋 بررسی پرداخت‌های نیازمند رسیدگی` inbox. In that private owner chat, case details display each authenticated child status and a bounded **read-only** provider status inquiry (**one chosen child per callback**, with next-child navigation, using the existing API key and exact identity matching). Read/view/inquire actions **never** credit funds, settle, provision or refund. No unaudited financial `approve/reject` button exists: a durable operator decision journal and safe, concurrency-aware resolution semantics remain an explicit separately gated #26 outcome. This is an operator *notice only* and must never trigger a blind refund, financial replay or remote account recreation.

### Safe operator worklist and manual recovery

On an **authorized, access-controlled installation**, the operator may run these **READ-ONLY** queries against the actual current schema, after confirming the proposed migration has been separately approved and executed. Do not paste output into public issue/CI logs.

```sql
SELECT id, provider, order_hash, provider_reference, payment_id, stage,
       provider_status, created_at, updated_at, review_notified_at
FROM payment_attempts
WHERE provider = 'nowpayments' AND (stage = 'review'
   OR (stage = 'creating' AND created_at < NOW() - INTERVAL 10 MINUTE))
ORDER BY updated_at ASC;

SELECT c.attempt_id, c.payment_id, c.observed_status, c.received_at
FROM payment_transactions c
JOIN payment_attempts a ON a.id = c.attempt_id AND a.provider = c.provider
WHERE a.provider = 'nowpayments' AND a.order_hash = 'REPLACE_WITH_OPERATOR_ORDER_REFERENCE'
ORDER BY c.id;
```

For each case: verify invoice/payment identity in the official provider dashboard or authenticated API, confirm actual merchant payout and fees, then inspect `pays`, `orders_list` and the remote Marzban account **read-only** before choosing any authorized correction. A timeout or an existing `paid` row does not prove remote failure. Never automatically reissue an invoice, repeat non-idempotent provisioning, credit an ambiguous wallet refund, or delete the evidence tables. Unknown/no-IPN payments may still require provider dashboard/resend and are not automatically discoverable from API-key-only access.

**Important missing-IPN limitation:** current reconciliation knows the payment ID only **after a valid signed IPN**. NOWPayments' invoice ID is not a payment ID: `GET /v1/payment/{invoice_id}` is incorrect. The official `GET /v1/payment/?invoiceid=...` invoice filter requires both `x-api-key` **and an Authorization Bearer JWT**; the documented `POST /v1/auth` generates a JWT valid for five minutes from the **merchant account email and password**. Those account credentials have materially broader sensitivity than the payment API key, and are **not authorized to be stored or used by this PHP application**. Investigate a provider-supported read-only scoped alternative before implementing background all-IPN-lost recovery. Without that separately approved authentication architecture and real staging proof, **do not claim fully unattended recovery when every IPN is lost**. Test provider POST delivery/retries and preserve the ability to manually resend signed IPNs via the provider dashboard; never store login credentials in public code, database attempt rows, Telegram or logs.

## Acceptance / rollback checklist

- Confirm the exact developer-reviewed GitHub SHA and verify the runtime ZIP against its generated `runtime.zip.manifest.json` (source commit/tree, every member and hash, ZIP checksum; **98 members in the current PR #66 R1-A1/A2 source-only candidate**; prior historical candidates had 94 (Issue #26 effect journal), 93 (Cron-enabled), and 92 (RC5 rollback). Verify the current exact HEAD rather than reusing a previous package inventory). Use `python3 tools/package_post_v9.py verify --zip <runtime.zip> --manifest <runtime.zip.manifest.json>` against the downloaded release assets. Separately check the original 23 tables plus existing `server_info.panel_group_id` and the already-applied shared 003 migration; never reapply SQL without an approved, backed-up per-target schema check.
- Verify direct Marzban PUT/reset transport, HTTP and user-response validation (including genuine success, rejected status, timeout, malformed body), Zarinpal browser NOK with provider 100/101 and transport uncertainty, three-provider renewal `success=false`, stale alternative-gateway callback after original invoice creation, nested operator-alert/new-child race, and missing-user/overflow/successful wallet compensation on actual native PHP/SQL.
- Test atomic cross-gateway claim (both directions), pre-POST `creating` reservation, timeout/no reissue, saved URL reopening, signed/invalid/duplicated multi-child IPNs, immutable Toman amount, output asset/net payout amount and fee shortfall, default no-policy issuance, malformed explicit-policy refusal and existing strict-policy compatibility, old legacy browser read-only route, real loopback HTTP bootstrap, `confirmed`/`sending` pending, authoritative `finished`, Telegram delivery without browser return, safe operator alerts, Marzban uncertainty with no auto-refund, replay/worker crash, provider API timeout, stale Cron and every IPN blocked.
- Actual browser return path should display locally **pending** or **paid** only, not issue a second provider payment nor automatically credit based on query arguments.
- PR #42 source merge already passed exact-head PHP8.1/8.4 CI and independent author-separated review with separate Owner authorization. **Do not deploy, apply shared SQL 003 or any RC4 import, activate provider/Cron/IPN or claim finance readiness** without separate Owner approval, actual backup plus isolated restore proof, IPN Secret and controlled operator acceptance (optional strict payout policy only when explicitly configured). Preserve the actual installed source for rollback, private credentials and existing customer/account data. After 003 has accepted new payment attempts, rolling source back to RC4 alone cannot restore payment ledger compatibility.
- Any live provider test must use a separately approved controlled minimal-value transaction; isolated synthetic CI is **not** proof of merchant settlement or real VPN provisioning. A provider `finished` with ambiguous quote/partial/repeated/late deposits must be escalated, not automatically credited.

Sources: [NOWPayments status handling for fixed-price goods](https://nowpayments.io/blog/best-integration-practices-at-nowpayments), [NOWPayments official IPN setup/signature](https://nowpayments.zendesk.com/hc/en-us/articles/21395546303389-IPN-and-how-to-setup), [NOWPayments API endpoints/JWT/invoiceid](https://nowpayments.zendesk.com/hc/en-us/articles/21345824322717-API-and-endpoint-description), [NOWPayments invoice/payment lifetime and repeated deposits](https://nowpayments.zendesk.com/hc/en-us/articles/18393446018461-Invoices-and-payments).
