# NOWPayments invoice continuity/IPN/reconciliation: source-only deployment gate

**Controlling work:** [Issue #26](https://github.com/ach1992/bot-refactor/issues/26), [Draft PR #42](https://github.com/ach1992/bot-refactor/pull/42), [financial architecture](FINANCIAL-CONSOLIDATION.md). Current owner-reported installed software is RC3; this design is an **unreleased development candidate**, not a live feature.

## User result and payment meaning

The buyer receives a durable HTTPS provider invoice link in Telegram. The provider's browser return is optional, and is **never evidence** of merchant settlement. An authenticated signed NOWPayments IPN stores the linked provider payment ID without creating an account in its HTTP request. The already scheduled `settings/rewardReport.php` Cron, **if actually installed and frequent enough**, fetches authenticated payment status, then calls the existing current business fulfillment only for API `finished` with matching exact invoice, merchant order, USD quote, deposit and paid-asset fields. A `confirmed` or `sending` result is **visually acknowledged as processing**, not credited or provisioned. This follows NOWPayments' recommendations for fixed-price goods rather than exposing the merchant to premature-payout risk.

**Operator caveat:** if the existing backup/report Cron is scheduled only hourly or does not run due to permissions/CWD, user delivery will be slow or missed despite valid IPN. Operator verification of the actual schedule is a release gate, not a test passed by GitHub CI.

The customer does **not** have to click Return to Merchant. When the existing business fulfillment succeeds, it already emits Telegram notifications and subscription links; final IPN handling does not depend on browser lifecycle.

## Additive storage (NOT approved for live database)

`migrations/post-v9/002_nowpayments_checkout_attempts.sql` creates one **new** `achbot_nowpayments_attempts` table with one row per original `pays.id`, unique merchant order/invoice ID, one nullable unique provider payment ID, durable invoice URL, USD issue-time quote, and bounded recovery status. It adds **no change to any of the original 23 historical tables**, no deletion/renaming/backfill, and must remain outside the 92-member source-only ZIP.

Existing `pays.payid` is neither provider-scoped nor UNIQUE; it is insufficient to persist both invoice ID and independently verified payment ID or the quote. This one additive table is justified by observed source paths and native tests; it is not a replacement for the **separate later shared finance effect ledger/outbox** required for all providers. Historical late/partial/repeated invoice behavior is intentionally **not** silently reinterpreted.

**Live migration requires separate Owner authorization and recovery evidence**: back up the *actual* MariaDB and private configuration, prove isolated restore, inspect existing `SHOW TABLES` and overlapping identifiers, rehearse `002` on a disposable restored copy, validate unique constraints and column types, and only then decide installation and production cutover. Source rollback can leave this additive table in place; database deletion/data cleanup is not authorized. Do **not** execute the SQL merely by installing a runtime ZIP.

## Single private IPN secret (never send into Telegram chats)

NOWPayments dashboard provides **IPN Secret Key**. Enter it only in the **existing private, untracked `baseInfo.php`** as:

```php
$nowpaymentsIpnSecret = 'REPLACE_WITH_PRIVATE_NOWPAYMENTS_IPN_SECRET';
```

Protect the private file with its existing host-level permissions. Do NOT commit it, paste it into GitHub, send it through a Telegram administrator chat, write it into any public log, or display it in the gateway settings menu. The existing public `baseInfo.example.php` and historical V9 private-file template remain frozen. The separately stored `PAYMENT_KEYS.nowpayment` API key remains unchanged.

A new hosted invoice is issued with `ipn_callback_url = {$botUrl}pay/back.php?nowpayment_ipn=1` and `success_url = {$botUrl}pay/back.php?nowpayment&hash_id=<merchant-id>`. Ensure `$botUrl` is the correct canonical public HTTPS URL ending in a slash, the IPN endpoint is reachable via POST, and valid IPN signatures are tested on a **separate owned staging environment**. Gatewall/Cloudflare/WAF/hosting proxies may block provider POST. No aaPanel hosting, TLS, DNS, firewall, Cron, or IPN dashboard configuration has been changed by source development.

`pay/back.php` now uses a **minimal isolated IPN bootstrap** that does not parse incoming JSON as Telegram updates. Bad signature, unknown local order, duplicate external provider IDs, wrong invoice/quote and wrong method are rejected without balance mutations. IPN response is bounded, cryptographically checked and does not make provider/Marzban/Telegram requests.

## Scheduled work and operational gate

`settings/rewardReport.php` is an **existing** Cron filename: it retains reward reports, backups and automatic card receipts. It additionally processes at most three matching pending NOWPayments attempts per run after verifying status against the actual provider API; there is no new daemon, service or standalone Cron job. The **actual live schedule and runner were never verified**. For prompt completion the Owner/operator should confirm it executes safely approximately every 1–5 minutes and doesn't get blocked by slow backups/provider calls; do not assume a one-hour Cron gives real-time service.

Attempts older than seven days with no final outcome are quarantined for review instead of endlessly polling a provider; no funds are silently canceled, refunded or discarded.

Concurrent Cron runs use compare-and-set attempt claims, leaving unfinished or ambiguous remote fulfillment for **manual reconciliation without blind replay**. An already-paid local order is never provisioned a second time merely due to late repeated IPN. This does not yet guarantee durable exactly-once Marzban fulfillment after process crash: inherited `doAction()` changes payment state before all remote side effects and remains part of Issue #26's next coherent work. Operator must have a safe way to locate `stage='review'` pending cases with order/attempt correlation (without writing full secrets or personal data into logs).

**Important missing-IPN limitation:** current reconciliation knows the payment ID only **after the signed IPN**. NOWPayments' invoice ID is not a payment ID; querying `GET /v1/payment/{invoice_id}` is incorrect. In the absence of **every** IPN callback, the existing API-key-only route cannot discover all invoice child payments; official invoice-child listing may require additional authorized JWT/API integration. Until this is validated with official vendor documentation and staging, **no absolute guarantee of zero manual stuck orders can be claimed**. Test provider POST delivery/retry and consider a separately reviewed invoice-payment lookup fallback; avoid storing provider credentials in new tables.

## Acceptance / rollback checklist

- Confirm exact developer-reviewed GitHub SHA, runtime ZIP manifest 92-member integrity, original 23 tables plus existing `server_info.panel_group_id`, and new 002 migration separately backed up and verified; never run SQL on an unsupported version.
- Test staged invoice initiation, saved URL reopened, signed and invalid IPNs, real loopback HTTP bootstrap without Telegram parsing, `confirmed`/ `sending` retained pending, later authoritative `finished`, Telegram delivery without browser return, replay, amount underpayment, duplicate payment ID, crash before/after external fulfillment, provider API timeout, stale Cron and IPN blocked entirely.
- Actual browser return path should display locally **pending** or **paid** only, not issue a second provider payment nor automatically credit based on query arguments.
- Disallow source merge/deploy if exact-head PHP8.1/8.4 CI, independent author-separated finance/security review, backup/restore proof, or separate Owner approval is missing. During deployment, preserve the installed RC3 artifact for source rollback, private credentials and all existing customer/account data.
- Any live provider test must use a separately approved controlled minimal-value transaction; isolated synthetic CI is **not** proof of merchant settlement or real VPN provisioning. A provider `finished` with ambiguous quote/partial/repeated/late deposits must be escalated, not automatically credited.

Sources: [NOWPayments status handling for fixed-price goods](https://nowpayments.io/blog/best-integration-practices-at-nowpayments), [NOWPayments official IPN setup/signature](https://nowpayments.zendesk.com/hc/en-us/articles/21395546303389-IPN-and-how-to-setup), [NOWPayments API payment updates](https://nowpayments.io/blog/nowpayments-api-explained-customize-your-payment-gateway).
