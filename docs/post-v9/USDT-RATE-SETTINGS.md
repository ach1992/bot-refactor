# AChBot shared USDT rate settings

This describes the **current Stable** manual USDT rate contract, established by the [Owner decision of 2026-10-28](https://github.com/ach1992/bot-refactor/issues/26#issuecomment-6453867569). A separate agent-only exchange rate is retired; original previously issued quotes and financial records still retain their own evidence. Historical RC upgrade instructions are not operating instructions for Stable installations.

In administrator **gateway settings**, configure the existing shared rate:

| Setting | Meaning | `/clear` behavior |
|---|---|---|
| `manualUsdtRateToman` | One USD/Toman rate for every account, every configured manual USDT network and hosted NOWPayments conversion | Disable new USDT/NOWPayments quotes for everyone |
| `manualTrxRateToman` | Independent native TRX/Toman rate | Disable new TRX quotes only |

USDT editor values are whole Toman amounts from 100 through 1,000,000,000. Runtime readers reject nonnumeric, nonfinite, out-of-range and nonscalar values. Card payments, gift cards, wallet payments, other hosted gateways and independently configured TRX retain their own availability. Existing customer/agent eligibility rules are unchanged; account role never selects a different exchange rate. Customer errors retain plain support guidance without infrastructure details.

The retired `usdtAgentRateToman` field is absent from the menu, editor allowlist and help. Old callbacks and unfinished retired editors cannot write it. Any retained value is inert, whether valid, invalid or cleared, and cannot rescue a missing/invalid shared rate. Valid existing per-field editor revision history remains readable so other gateway settings can still be edited; it never authorizes editing the retired setting. No schema migration, automatic JSON deletion or actual private configuration change is required.

The old `USDTPrice` and `USDTPriceagent` private globals and Tetherland lookup are not runtime rate sources. There is no private, external, 1:1 or other automatic rate fallback. Single-network address compatibility does not supply a rate; no credentials move out of private config.

Existing `payment_attempts` retain their original quote, asset, network, recipient and rate, including quotes issued before the shared-rate policy. Settings changes do not reprice, settle, refund or reissue an existing claim. Manual receipts still require independent authorized human verification.

For targeted current-source validation use actual PHP and socket-only disposable MariaDB with `tools/test_next_currency_settings.py --run-disposable`. Relevant scenarios cover the shared rate across all three USDT networks, hosted conversion, invalid/missing settings, immutable already issued quotes, rejected retired editor paths and retained editor revisions. The [required CI policy](CI-VALIDATION.md) determines which current Stable checks run automatically. Neither fixture execution nor GitHub CI proves live payment, Native, wallet or Telegram acceptance.
