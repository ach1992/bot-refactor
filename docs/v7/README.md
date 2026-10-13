# AChBot V7 — product plans, purchase and provisioning

**Development candidate only. Not merged, released or approved for deployment.**
Controlling contract: [Issue #21](https://github.com/ach1992/bot-refactor/issues/21).
Pinned last verified V6.1 source: `ca0405442e98481ec2107772f5d2df94adb2b134`
(tree `da6ad5c94182e8a2bc3e7a02ce968d8f8f0c97eb`).

## Implemented domain and compatibility boundary

`tools/v7_contract.py` regenerates and validates **byte-identical PHP bodies**
from pinned V6.1 into eleven internal, direct-HTTP-guarded global-scope
modules. Original script `require` hooks stay in their original order,
executing with the original caller variables, match groups, step state,
conditional/exit behavior, SQL, callbacks, business rules and provider API
calls. The unchanged global helper function names/signatures remain reachable
through the original `buy_helpers.php` facade path.

| Area | New module(s) | Existing behavior retained |
|---|---|---|
| Customer and agent entry; admin multi-account creation | `src/Purchase/EntryAndBulk.php` | `buySubscription`, `agentOneBuy`, `agentMuchBuy`, admin `createAcc*` callbacks and persisted wizard steps |
| Server/category/plan catalogue, custom-plan wizard | `src/Purchase/Catalogue.php`, `src/Purchase/TrialCatalogue.php`, `src/Purchase/SelectPlan.php` | `selectServer*`, `selectCategory*`, `selectCustomPlan*`, `selectCustomePlan*` (original spelling), `selectPlan*`, pending `pays` writes, agent count/discount behavior |
| Existing free/trial provisioning | `src/Purchase/TrialProvision.php` | `getTestAccount`, `freeTrial*`, eligibility flag, original external account creation and `orders_list`/capacity effects |
| Plan/category administration | `src/Plans/Intervals.php`, `src/Plans/Administration.php`, `src/Plans/Categories.php` | day/volume plans, plan creation/edit wizard, category create/edit/delete, admin callbacks |
| Plan, server and provisioning helpers | `src/Purchase/Core.php`, `src/Purchase/OrderWrites.php`, `src/Purchase/AgentPricing.php` | public helper names/parameters; capacity, panel account creation, order writes, agent price calculation |

The V7 source-only package has **65 tracked runtime members** (the previous
54 plus eleven new internal PHP modules), of which 64 are PHP files. The
original `config.php`, `src/Panel/*`, `settings/subLink.php`, `pay/back.php`,
`settings/rewardReport.php` and `settings/tronChecker.php` are unchanged from
V6.1. `buy_helpers.php` moves only the pinned helper definitions listed above;
financial/reward/payment functions and QR/link implementation remain in the
original file. Existing V6.1 backup footer fix, all 26 backup synthetic
regressions and original 23-table MariaDB DDL are preserved.

## Cross-consumer integration and intentionally unchanged boundaries

The existing wallet purchase/renewal consumer in `bot.php` continues to call
`processWalletPayment`, `getPlanDetails`, `ensureServerCapacity`,
`getServerInfoAndConfig`, `createServerAccount`, `buildLinksAndSendQr`,
`insertOrder` and `decreaseServerCapacity` with unchanged arguments. In native
E2E the **original unchanged wallet route** is driven only against temporary
synthetic wallets/rows to complete customer and agent purchases; no changes to
settlement or financial policy are made. Payment-return scripts, Tron check,
reward/Cron consumer and subscription HTTP endpoint are immutable Git blobs.

Panel implementation and wire formats remain the V6 modules; the V7 tests
exercise them against local Marzban HTTP fixtures and compare ordered calls.
All original Telegram callback strings and `users.step` state remain stable,
including custom plan spelling, legacy discounts and eligibility rules.
Authorization is preserved as-is, not silently tightened or relaxed. A defect
observed in inherited behavior must be tracked separately, not hidden in V7.

**Not included (V8/owner gate):** wallet/financial policy redesign,
settlement logic, new discount policy, refunds, price changes, SQL migrations,
provider protocol rewrites, production bot or server actions. V5/V6 manual
operator gates in Issues #14/#16 remain **NOT TESTED**; V6.1 backup was received
by the operator but **MariaDB restoration was NOT TESTED** by owner decision.

See [TESTING.md](TESTING.md) for executed source/SQL/HTTP regression contracts
and [OPERATIONS.md](OPERATIONS.md) for candidate package, source swap,
recovery and owner-only integration gates. All progress and evidence are
attached to Issue #21/its PR; no chat history is authoritative.
