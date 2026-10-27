# AChBot USDT rate settings

Contract revision 3 moves exchange rates from private configuration into the
existing `setting` row with `type=PAYMENT_KEYS`. No schema change or automatic
configuration import is performed. Existing installations must explicitly set
rates through the bot’s administrator gateway settings before issuing new USDT
or NOWPayments invoices. The real private `baseInfo.php` is never inspected or
modified by this change; published historical source remains unchanged.

In administrator **gateway settings**, the two rate rows appear beside the
hosted gateway configuration and identify both manual USDT and NOWPayments:

| Setting | Meaning | `/clear` behavior |
|---|---|---|
| `manualUsdtRateToman` | Normal USD/Toman rate for manual USDT on every configured network and hosted NOWPayments conversion | New normal quotes are unavailable; agents remain available only with a valid explicit agent override |
| `usdtAgentRateToman` | Optional rate for actual `users.is_agent=1` accounts, for both manual USDT and NOWPayments | Reuse the explicit normal rate; no separate agent default |
| `manualTrxRateToman` | Independent native TRX/Toman rate | Disable new TRX quotes only |

USDT editor values are whole Toman amounts from 100 through 1,000,000,000.
Runtime readers reject nonnumeric, nonfinite, out-of-range and nonscalar values.
An explicitly invalid agent override never silently falls back to the normal
rate. Without a usable rate, only the affected audience’s USDT/NOWPayments
option is unavailable; card payments, gift cards, wallet payments, other hosted
gateways and independently configured TRX remain unchanged. Customers receive
plain support guidance if a stale initiation link has no usable rate.

The old `USDTPrice` and `USDTPriceagent` private globals and Tetherland lookup
are no longer runtime rate sources. There is no 1:1 or other automatic default.
The existing single-network address fallback remains address compatibility
only; it does not supply a rate. No credentials move out of private config.

Existing `payment_attempts` retain their original quote, asset, network,
recipient and rate even if settings are edited, cleared or invalidated later.
Settings changes do not reprice, settle, refund, or reissue an existing claim.
Manual receipts still require independent authorized human verification.

Validation: `tools/test_next_currency_settings.py --run-disposable`, the
post-V9 manual-payments, payment-key administrator, financial-routes and
readiness suites exercise synthetic PHP and socket-only MariaDB. These checks
perform no installed DB, wallet, provider, Telegram or production operations.
