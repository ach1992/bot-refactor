# AChBot shared USDT rate settings

The [Owner decision of 2026-10-28](https://github.com/ach1992/bot-refactor/issues/26#issuecomment-6453867569) removes the separate agent USDT rate from development after RC12. This document describes the new source contract; the published RC12 ZIP remains unchanged and includes its former optional override.

In administrator **gateway settings**, configure the existing shared rate:

| Setting | Meaning | `/clear` behavior |
|---|---|---|
| `manualUsdtRateToman` | One USD/Toman rate for every account, every configured manual USDT network and hosted NOWPayments conversion | Disable new USDT/NOWPayments quotes for everyone |
| `manualTrxRateToman` | Independent native TRX/Toman rate | Disable new TRX quotes only |

USDT editor values are whole Toman amounts from 100 through 1,000,000,000. Runtime readers reject nonnumeric, nonfinite, out-of-range and nonscalar values. Card payments, gift cards, wallet payments, other hosted gateways and independently configured TRX retain their own availability. Existing customer/agent eligibility rules are unchanged; account role never selects a different exchange rate. Customer errors retain plain support guidance without infrastructure details.

The retired `usdtAgentRateToman` field is absent from the menu, editor allowlist and help. Old callbacks and unfinished retired editors cannot write it. Any retained value is inert, whether valid, invalid or cleared, and cannot rescue a missing/invalid shared rate. Valid RC12 per-field editor revision history remains readable so other gateway settings can still be edited; it never authorizes editing the retired setting. No schema migration, automatic JSON deletion or actual private configuration change is required.

The old `USDTPrice` and `USDTPriceagent` private globals and Tetherland lookup are not runtime rate sources. There is no private, external, 1:1 or other automatic rate fallback. Single-network address compatibility does not supply a rate; no credentials move out of private config.

Existing `payment_attempts` retain their original quote, asset, network, recipient and rate, including quotes originally issued with RC12 agent pricing. Settings changes do not reprice, settle, refund or reissue an existing claim. Manual receipts still require independent authorized human verification.

Validation uses actual PHP and socket-only disposable MariaDB: `tools/test_next_currency_settings.py --run-disposable`, plus manual-payments, payment-key editor, financial-routes, readiness and full current-source acceptance. Coverage includes archived RC12 override behavior, shared manual quotes on all three networks, hosted conversion, invalid/missing shared rates despite retained overrides, immutable prior quotes, rejected retired editors and retained editor revisions. No installed DB, wallet, provider, Telegram or production operation is performed.
