# Behavior and acceptance matrix

Sources are the imported code, generated catalogs and executable fixtures. An automated example proves only the listed case under its declared fixtures. Every family is enumerated in `tests/run.py`; stored traces are in `tests/fixtures/golden.json`. There are **121 scenarios**, not 121 distinct production features and not a coverage percentage.

| Capability | Source boundary | Executable V0 cases | Still needs provider/operator or broader coverage |
|---|---|---|---|
| Bootstrap, ban and bot state | `bot.php` + eager `config.php` | `entry.bot_banned`, `entry.bot_off`, `entry.bot_start` | Actual webhook registration, proxy/IP configuration, phone/referral/channel permutations |
| Main/agent/admin menus | `getMainKeys`, `getAgentKeys`, `getAdminKeys` | seven `menu.*` cases, `entry.bot_main_callback` | Every role/permission combination, real old keyboards, Telegram rendering |
| Text commands and old callback routing | `getCallbackFromCommand`, root router | seven `command.*`, `entry.bot_legacy_callback` | Full handler set and every persisted callback prefix; tested legacy callback is one example |
| Existing conversation steps | `users.step`/`temp`, `setUser` | State writes captured in entry/feature traces; complete lexical write inventory | Resume each purchase/admin/support wizard from a pre-upgrade step; no reset to `none` |
| Agent pricing | `calculateAgentPrice` | five `price.*`, including malformed/over-100 legacy input | Intended policy for invalid discounts; data cleanup is outside V0 |
| Capacity and identifiers | plan/server helpers | three `capacity.*`, four `remark.*`, three `port.*` | Concurrent provisioning/port allocation and all naming policies |
| Account provisioning | `createServerAccount` | seven `provision.*`, four `account_error.*` | Actual panel versions, TLS, traffic/expiry units, duplicate responses and partial success |
| Orders and capacity effects | insertion/decrement helpers | four `order.*`; native binding/nullability checks | Complete purchase success/failure across Telegram + panel + DB |
| Wallet payment | `processWalletPayment` | thirteen `wallet.*`; five native transaction cases | Concurrent callbacks, lock contention, crash windows, remote effects after rollback |
| Payment start/return | `pay/index.php`, `pay/back.php` | `entry.pay_unknown`, `entry.pay_missing`, `entry.pay_return_missing` | Positive verification/refund/replay paths for each gateway; actual HTTP redirect headers |
| Receipt duplicate guard | `duplicateReceiptGuard` | eight `receipt.*`; native boundary checks | Real media upload/forward variants, concurrent approvals, complete receipt-to-admin sequence |
| Card-to-card eligibility | eligibility/message helpers | eight `cart.*` | Full auto-approval/manual-approval, real oldest payment/date timezone, payment reconciliation |
| Discount application | `applyDiscountToPay` | ten `discount.*`; native persistence/use-limit checks | Race conditions, every audience/target combination and later failed payment |
| Referral reward | `giveReferralReward` | two `referral.*` | Every referral source, self/referral abuse policy and duplicate external success |
| Renewal and extension | `renewConfigOnServer`, scheduled logic | three `renew.*`; native extension insert check | Full scheduled auto-renew/expiry/warning paths, insufficient credit and panel rejection |
| Subscription response | `settings/subLink.php` | `entry.sub_missing`, `entry.sub_short` | Valid token output, remote/local mutation, all protocols, actual client import |
| Broadcast queue/job operations | DB job worker and helpers | three `broadcast.*`; idle/pin/429 entrypoint cases | Edit/delete/unpin variants, whole recipient queue, restart/overlap and actual API limits |
| Gift/report/crypto maintenance | scheduled scripts | gift/reward idle and TRON disabled entrypoints | Non-idle gifting, report/payment success, TRON evidence and safe failure behavior |
| Telegram transport | `bot`, markup helpers | six `transport.*` including malformed JSON/429/500/multipart | Real network retries, timeout timing, TLS and delivery semantics |
| Health | `health.php` | six `entry.health_*` | Web-server access restrictions, HEAD/status headers in real SAPI, monitoring policy |
| Support/tickets and broad administration | bot/config routes and SQL inventory | Static map and related menu traces only | Ticket create/reply/rating, all admin setters, server/plan editing, permission denial paths |

## Compare these outputs, not just return values

Keep emitted Telegram method/payload (text, caption, parse mode, markup, chat/user, existing callback bytes), ordered SQL and bound parameter types/values, transaction events, chosen provider adapter/arguments, local state files, exit/output/status and legacy exception handling equivalent. The harness captures these where its cases execute. It must fail on unscripted reads or unexpected external calls, rather than responding with a generic successful stub.

Money, time, random identifiers and external responses are deterministic **test inputs**; their real-world distributions and clocks are not benchmarked. SQL layout normalization preserves quoted literal contents. Snapshot updates must be reviewed against source and semantic assertions; a regenerated snapshot by itself is not validation.

## High-impact source sequences to review

- Payment precheck expiry and renewal plan mismatch precede payment-method callback rewriting: [`bot.php:37`](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/bot.php#L37-L174).
- Wallet SQL transaction and handler side effects: [`buy_helpers.php:726`](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/buy_helpers.php#L726-L891).
- Discount mutation happens during application, before payment completion: [`config.php:2976`](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L2976-L3317).
- Native extension schema mismatch in a scheduled payment path: [`settings/rewardReport.php:455`](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/settings/rewardReport.php#L455-L458).
- Broadcast cursor/state and shutdown behavior: [`settings/messagewizwiz.php`](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/settings/messagewizwiz.php).
- A valid subscription GET can mutate remote/local data: [`settings/subLink.php`](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/settings/subLink.php).

## Operator acceptance for this increment

V0 changes no application runtime files; do not deploy its test tools into a live web root. Review the matrix and the uncovered features against the bot actually in use. Record which gateways, panels, roles, Cron scripts, feature flags and persisted steps are operationally relevant, without publishing secrets or customer data. Validate the isolated reproduction and review the known defects before approving V1.

For each later runtime increment, use a non-production bot and synthetic data with the same supported environment. Test ordinary user, root admin, delegated admin and agent; old sent inline keyboards; an in-progress wizard; normal and duplicate purchase/payment/renewal; insufficient balance and provider rejection; support; scheduled work; and reversal to the prior code with current persisted state. Attach candidate SHA, environment, exact scenario/outcome and any sanitized mismatch. Unrun or unavailable scenarios remain explicitly unverified.
