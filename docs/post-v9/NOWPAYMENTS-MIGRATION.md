# NOWPayments invoice continuity/IPN/reconciliation: source-only deployment gate

**Controlling work:** [Issue #26](https://github.com/ach1992/bot-refactor/issues/26), [Draft PR #42](https://github.com/ach1992/bot-refactor/pull/42), [financial architecture](FINANCIAL-CONSOLIDATION.md). Current owner-reported installed software is RC3; this design is an **unreleased development candidate**, not a live feature.

## User result and payment meaning

The buyer receives a durable HTTPS provider invoice link in Telegram. The provider's browser return is optional, and is **never evidence** of merchant settlement. An authenticated signed NOWPayments IPN stores the linked provider payment ID without creating an account in its HTTP request. The already scheduled `settings/rewardReport.php` Cron, **if actually installed and frequent enough**, fetches authenticated payment status, then calls the existing current business fulfillment only for API `finished` with matching exact invoice, merchant order, USD quote, deposit and paid-asset fields. A `confirmed` or `sending` result is **visually acknowledged as processing**, not credited or provisioned. This follows NOWPayments' recommendations for fixed-price goods rather than exposing the merchant to premature-payout risk.

**Operator caveat:** if the existing backup/report Cron is scheduled only hourly or does not run due to permissions/CWD, user delivery will be slow or missed despite valid IPN. Operator verification of the actual schedule is a release gate, not a test passed by GitHub CI.

The customer does **not** have to click Return to Merchant. When the existing business fulfillment succeeds, it already emits Telegram notifications and subscription links; final IPN handling does not depend on browser lifecycle.

## Additive storage (NOT approved for live database)

`migrations/post-v9/002_nowpayments_checkout_attempts.sql` proposes **two additive tables**: `achbot_nowpayments_attempts` (one row per original `pays.id`, merchant order, invoice, primary payment ID, original hosted URL, USD quote, **original Toman price**, approved payout currency and minimum net payout, workflow status, last operator notification) and `achbot_nowpayments_child_payments` (globally unique durable child `payment_id` evidence for **every signed deposit**). The first provider invoice request is preceded by a MariaDB `creating` reservation **and an atomic `pays.payid` gateway claim**. A response timeout/crash never silently creates a second invoice. NextPay and Zarinpal now also reserve the same order identity before their respective external requests; a buyer cannot overwrite a NOWPayments invoice by switching gateways. The original historical 23 tables and `001` panel-group migration remain unchanged. Both new tables are **proposed/source-only**, separate from the 92-member runtime ZIP.

Existing `pays.payid` is neither provider-scoped nor UNIQUE; without the proposed tables it cannot hold the original quote, primary/extra payment IDs or alert state. The two narrow additive tables address current NOWPayments evidence/recovery without redesigning historical schema; they are **not a universal shared business-effect/outbox ledger**. Each child deposit is only acknowledged after its globally unique ID is durable and linked to the invoice; additional deposits are review-only and never duplicate service or credit. Historical late/partial payments are not silently reinterpreted.

**Live migration requires separate Owner authorization and recovery evidence**: back up the *actual* MariaDB and private configuration, prove isolated restore, inspect existing `SHOW TABLES` and overlapping identifiers, rehearse `002` on a disposable restored copy, validate unique constraints and column types, and only then decide installation and production cutover. Source rollback can leave this additive table in place; database deletion/data cleanup is not authorized. Do **not** execute the SQL merely by installing a runtime ZIP.

## Single private IPN secret (never send into Telegram chats)

NOWPayments dashboard provides **IPN Secret Key**. Enter it only in the **existing private, untracked `baseInfo.php`** as:

```php
$nowpaymentsIpnSecret = 'REPLACE_WITH_PRIVATE_NOWPAYMENTS_IPN_SECRET';
```

Protect the private file with its existing host-level permissions. Do NOT commit it, paste it into GitHub, send it through a Telegram administrator chat, write it into any public log, or display it in the gateway settings menu. The existing public `baseInfo.example.php` and historical V9 private-file template remain frozen. The separately stored `PAYMENT_KEYS.nowpayment` API key remains unchanged.

**Additional private merchant payout policy required BEFORE invoice creation:**

```php
$nowpaymentsSettlementPolicy = [
    'outcome_currency' => 'REPLACE_WITH_VERIFIED_PAYOUT_ASSET',
    'minimum_outcome_per_usd' => 'REPLACE_WITH_APPROVED_NET_RATE',
];
```

These are deliberately invalid placeholders. The Owner/operator must independently establish the **actual merchant receiving asset/network** and the **minimum NET payout units per USD of invoice quote** covering accepted conversion/fee/slippage risk. The rate is pinned at issuance and the worker requires provider-reported `outcome_currency` to match exactly and `outcome_amount` to cover the captured minimum; it also requires correct price currency, original quote and complete customer deposit. `finished` without enough merchant payout **never** authorizes fulfillment. No automatic 1:1 stablecoin assumption, default shortfall acceptance, or account-password/JWT integration is provided. If the private policy is absent/invalid, new NOWPayments invoice creation **fails closed**. Any future merchant risk/asset policy must be a separate explicit Owner decision before live activation.

A new hosted invoice is issued with `ipn_callback_url = {$botUrl}pay/back.php?nowpayment_ipn=1` and `success_url = {$botUrl}pay/back.php?nowpayment&hash_id=<merchant-id>`. Ensure `$botUrl` is the correct canonical public HTTPS URL ending in a slash, the IPN endpoint is reachable via POST, and valid IPN signatures are tested on a **separate owned staging environment**. Gatewall/Cloudflare/WAF/hosting proxies may block provider POST. No aaPanel hosting, TLS, DNS, firewall, Cron, or IPN dashboard configuration has been changed by source development.

`pay/back.php` now uses a **minimal isolated IPN bootstrap** that does not parse incoming JSON as Telegram updates. Bad signature, unknown local order, duplicate external provider IDs, wrong invoice/quote and wrong method are rejected without balance mutations. IPN response is bounded, cryptographically checked and does not make provider/Marzban/Telegram requests.

## Scheduled work and operational gate

`settings/rewardReport.php` is an **existing** Cron filename: it retains reward reports, backups and automatic card receipts. It additionally processes at most three matching pending NOWPayments attempts per run after verifying status against the actual provider API; there is no new daemon, service or standalone Cron job. The **actual live schedule and runner were never verified**. For prompt completion the Owner/operator should confirm it executes safely approximately every 1–5 minutes and doesn't get blocked by slow backups/provider calls; do not assume a one-hour Cron gives real-time service.

NOWPayments explicitly states that **invoices have no expiration date**, while an individual child payment is tracked for seven days and repeated deposits can create a *new* payment ID. Do not treat `attempt.created_at` as child-payment expiry: a valid `finished` payment can arrive on an older invoice and still needs verified settlement. Known child payments are polled at bounded per-tick frequency; terminal negative, ambiguous and underpaid outcomes require an explicit review policy. Repeated/extra child deposits must not trigger duplicate financial effects. All authenticated child IDs are durably stored in `achbot_nowpayments_child_payments` with a unique provider ID, before acknowledging IPN. Even a third or later child remains findable. Additional child deposits never trigger a second credit/provisioning; they move the invoice to operator `review`. Failed identity persistence returns non-2xx for provider retry. **Partial/refunded/additional-deposit allocation and refunds remain operator-led decisions**, not automated financial effects.

Concurrent Cron runs use compare-and-set attempt claims, leaving unfinished or ambiguous remote fulfillment for **manual reconciliation without blind replay**. A NOWPayments-verified payment with an uncertain Marzban create/renew/update result is marked `review` **without automatic wallet compensation**, because a remote timeout does not prove failure; this avoids refunding an account that may have been created or modified. This **no-refund-on-uncertain-remote-effect** guard also covers NextPay and Zarinpal provider-driven Marzban operations; old/manual/card financial paths remain separate work in Issue #26. An already-paid local order is never provisioned a second time merely due to late repeated IPN. This does not yet guarantee durable exactly-once Marzban fulfillment after process crash: inherited `doAction()` changes payment state before all remote side effects and remains part of Issue #26's next coherent work. The scheduled runner now invokes `achbotNpAlertOperator()` to send bounded **private administrator alerts** for `review` attempts and `creating` reservations older than ten minutes. Alerts include an opaque order reference, provider IDs, child count and reason, not secret keys or user data; a successful Telegram send is recorded in `review_notified_at` and only re-alerted after 24 hours while unresolved. Failed sends do not acknowledge the alert. This is an operator *notice only* and must never trigger a blind refund, financial replay or remote account recreation.

### Safe operator worklist and manual recovery

On an **authorized, access-controlled installation**, the operator may run these **READ-ONLY** queries against the actual current schema, after confirming the proposed migration has been separately approved and executed. Do not paste output into public issue/CI logs.

```sql
SELECT id, order_hash, invoice_id, payment_id, stage,
       provider_status, created_at, updated_at, review_notified_at
FROM achbot_nowpayments_attempts
WHERE stage = 'review'
   OR (stage = 'creating' AND created_at < NOW() - INTERVAL 10 MINUTE)
ORDER BY updated_at ASC;

SELECT c.attempt_id, c.payment_id, c.observed_status, c.received_at
FROM achbot_nowpayments_child_payments c
JOIN achbot_nowpayments_attempts a ON a.id = c.attempt_id
WHERE a.order_hash = 'REPLACE_WITH_OPERATOR_ORDER_REFERENCE'
ORDER BY c.id;
```

For each case: verify invoice/payment identity in the official provider dashboard or authenticated API, confirm actual merchant payout and fees, then inspect `pays`, `orders_list` and the remote Marzban account **read-only** before choosing any authorized correction. A timeout or an existing `paid` row does not prove remote failure. Never automatically reissue an invoice, repeat non-idempotent provisioning, credit an ambiguous wallet refund, or delete the evidence tables. Unknown/no-IPN payments may still require provider dashboard/resend and are not automatically discoverable from API-key-only access.

**Important missing-IPN limitation:** current reconciliation knows the payment ID only **after a valid signed IPN**. NOWPayments' invoice ID is not a payment ID: `GET /v1/payment/{invoice_id}` is incorrect. The official `GET /v1/payment/?invoiceid=...` invoice filter requires both `x-api-key` **and an Authorization Bearer JWT**; the documented `POST /v1/auth` generates a JWT valid for five minutes from the **merchant account email and password**. Those account credentials have materially broader sensitivity than the payment API key, and are **not authorized to be stored or used by this PHP application**. Investigate a provider-supported read-only scoped alternative before implementing background all-IPN-lost recovery. Without that separately approved authentication architecture and real staging proof, **do not claim fully unattended recovery when every IPN is lost**. Test provider POST delivery/retries and preserve the ability to manually resend signed IPNs via the provider dashboard; never store login credentials in public code, database attempt rows, Telegram or logs.

## Acceptance / rollback checklist

- Confirm exact developer-reviewed GitHub SHA, runtime ZIP manifest 92-member integrity, original 23 tables plus existing `server_info.panel_group_id`, and new 002 migration separately backed up and verified; never run SQL on an unsupported version.
- Test atomic cross-gateway claim (both directions), pre-POST `creating` reservation, timeout/no reissue, saved URL reopening, signed/invalid/duplicated multi-child IPNs, immutable Toman amount, output asset/net payout amount and fee shortfall, no-policy issuance refusal, old legacy browser read-only route, real loopback HTTP bootstrap, `confirmed`/`sending` pending, authoritative `finished`, Telegram delivery without browser return, safe operator alerts, Marzban uncertainty with no auto-refund, replay/worker crash, provider API timeout, stale Cron and every IPN blocked.
- Actual browser return path should display locally **pending** or **paid** only, not issue a second provider payment nor automatically credit based on query arguments.
- Disallow source merge/deploy if exact-head PHP8.1/8.4 CI, independent author-separated finance/security review, backup/restore proof, or separate Owner approval is missing. During deployment, preserve the installed RC3 artifact for source rollback, private credentials and all existing customer/account data.
- Any live provider test must use a separately approved controlled minimal-value transaction; isolated synthetic CI is **not** proof of merchant settlement or real VPN provisioning. A provider `finished` with ambiguous quote/partial/repeated/late deposits must be escalated, not automatically credited.

Sources: [NOWPayments status handling for fixed-price goods](https://nowpayments.io/blog/best-integration-practices-at-nowpayments), [NOWPayments official IPN setup/signature](https://nowpayments.zendesk.com/hc/en-us/articles/21395546303389-IPN-and-how-to-setup), [NOWPayments API endpoints/JWT/invoiceid](https://nowpayments.zendesk.com/hc/en-us/articles/21345824322717-API-and-endpoint-description), [NOWPayments invoice/payment lifetime and repeated deposits](https://nowpayments.zendesk.com/hc/en-us/articles/18393446018461-Invoices-and-payments).
