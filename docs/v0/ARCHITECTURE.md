# Architecture of the imported application

## Evidence and interpretation

All source links refer to the immutable imported commit, not moving line numbers on `main`. The generated inventory reports 19 reference files, 357 named function declarations (182 outside the bundled QR/date libraries), 34 include sites, 925 SQL-literal sites, 1,072 callback-output sites, 668 route-related conditions and 294 `setUser` calls. These counts include repetition and nested conditions; they are not unique features, runtime reachability or percentage coverage.

## Actual request bootstrap

`bot.php` loads `config.php`, then `buy_helpers.php`, then calls the IP check. Loading `config.php` is already effectful: it writes an admin-list helper file, includes local settings/configuration, opens mysqli, parses `php://input`, reads users/settings, and calls Telegram membership lookup. It also has top-level input handling, not just declarations. Moving an include or treating the file as a passive dependency can therefore change externally visible ordering. See [`bot.php:1`](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/bot.php#L1-L35) and [`config.php:1`](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L1-L13), [`config.php:618`](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L618-L724).

Top-level function declarations in `bot.php` can be available when its include executes; a cross-file textual call is not automatically an undefined-function bug. The inventory's call names are lexical only; method names and nested declarations must not be mistaken for a type-resolved call graph.

The principal path is:

```text
Telegram request -> bot.php
  -> config.php: local files + database + update globals + membership side effects
  -> buy_helpers.php declarations
  -> IP/source check -> ban/spam/payment/channel/bot-state guards
  -> first matching command/callback/conversation-step branch
  -> SQL and panel/payment/Telegram side effects -> persisted user step and response
```

The payment precheck can rewrite a callback into a wallet/cart/crypto action. Its expiry guard and renewal-plan consistency guard occur before the corresponding payment path. Existing old inline keyboards and a user with a non-`none` `users.step` are therefore live compatibility inputs, not obsolete state to reset. [`bot.php:37`](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/bot.php#L37-L174).

## Responsibility map

| Surface | Current owner | Coupling and eventual boundary |
|---|---|---|
| Telegram parsing, routing and conversation | `bot.php`; top-level `config.php` | Globals, branch ordering, `exit`, persisted `step`/`temp`; eventually a request context and handlers preserving the current order |
| Text, keyboards, admin/agent menus | `settings/values.php`, `config.php` | Existing Persian text, emoji, parse mode, callback bytes, role-specific visibility; presentation extraction must preserve output |
| Purchase preparation/provisioning | `buy_helpers.php` and duplicated inline branches | Capacity checks, prices, panel calls, orders, referral rewards and messaging; extract by a complete use case, not just file size |
| Wallet transaction | `processWalletPayment` | Locks and state machine wrap a callback that may make external calls; SQL rollback does not undo panel or Telegram effects |
| Payments/receipt workflow | `pay/index.php`, `pay/back.php`, receipt helpers, bot branches, scheduled reporters | Several initiation/confirmation paths, gateway IDs and return URLs, time windows, conditional auto-approval |
| Discounts and eligibility | `applyDiscountToPay`, helper queries | JSON audience/use history, status spelling, integer money and expiry decisions are storage contracts |
| VPN integration | lower half of `config.php` | Marzban and inbound/client-oriented adapters, authentication, response shapes, links and units |
| Subscription endpoint | `settings/subLink.php` | Not read-only: successful requests can change remote remarks and local order data |
| Broadcasts and maintenance | `settings/messagewizwiz.php`, other scheduled scripts | DB checkpoints, conditional job claims, shutdown behavior, timing, existing script paths |
| Health endpoint | `health.php` | Separate local config, DB ping, Telegram getMe, file cache and HTTP status |
| Shared persistence | mysqli SQL throughout | Keep current schema/SQL semantics behind narrow operations; do not introduce an ORM or new schema as an incidental cleanup |

Function-by-function locations, signature, lexical calls, globals and body digests are in [the atlas](SOURCE-ATLAS.md) and `inventory.json`. Bundled `phpqrcode/` and `settings/jdf.php` are distinguished from first-party code; preserve their notices and do not relabel their authorship.

## Financial and provisioning sequences

`processWalletPayment` reads and locks the payment and user, rejects selected terminal/busy states, checks balance, marks processing, debits, invokes its business callback, and either commits a paid state or rolls back. False returns and exceptions have separate legacy traces. A bare `expired` state and a foreign `from_id` are not independently rejected inside this helper; callers must be evaluated before classifying this as a reachable authorization failure. [`buy_helpers.php:726`](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/buy_helpers.php#L726-L891).

Account creation dispatches between Marzban, standalone inbound and client-in-inbound operations. Duplicate handling, port choice, success/null/error results, order insertion and capacity decrement must remain ordered. `renewConfigOnServer` dispatches to different provider operations; return-value equivalence alone does not prove provider behavior. [`buy_helpers.php:137`](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/buy_helpers.php#L137-L235), [`buy_helpers.php:517`](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/buy_helpers.php#L517-L701), [`buy_helpers.php:702`](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/buy_helpers.php#L702-L724).

A future transaction boundary must not promise distributed atomicity: a panel can accept a change and the local transaction can later fail. V0 captures selected local decisions and adapter invocations; retry/concurrency/compensation semantics require their own tests and owner-approved changes.

## Proposed first refactor boundary (not implemented by V0)

After V0 review and operator acceptance, start with a small group of deterministic formatting/normalization helpers with existing callers and golden output. Move their implementation behind backward-compatible functions, preserve weak typing, argument defaults, globals and inclusion order, and test the whole increment. Do **not** split the eager `config.php` bootstrap, rename callbacks, rewrite financial flows, or migrate configuration as the first supposedly low-risk extraction.

As subsequent whole use cases become testable, introduce a thin persistence boundary around semantic operations (load payment, debit wallet within the existing transaction, save legacy user step) and provider/Telegram adapters. Keep legacy column names, JSON coercions, SQL ordering and transaction ownership inside the initial implementations. Phase 2 can later replace storage implementations with separately authorized mappings/migrations. Avoid one interface per function and avoid preparing a speculative universal framework.

V0's byte lock intentionally prevents runtime changes while building a reference. Later refactor increments need a reviewed change to the harness strategy: preserve an immutable baseline execution/reference and run the new implementation against the same fixtures. Do not simply regenerate expected outputs until a changed implementation passes.
