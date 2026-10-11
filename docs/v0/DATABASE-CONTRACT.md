# Existing database contract: Phase 1 is migration-free

Source: [`database/legacy-schema.sql`](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/database/legacy-schema.sql). Exact file SHA-256: `f790a6257e28916430c44172498bd08cb70c803f8690298dc5c8c7d145568a88`. This is a public **schema-only reference**, not the production database and not an installer/migration to run on a populated deployment.

## Complete table inventory

Counts are lexical SQL sites, including repeated statements and some construction fragments. Zero references are not evidence of non-use. Column definitions, engine/collation and each ALTER/index definition are preserved verbatim in `inventory.json.tables`.

| Table | Columns | SQL sites | Operations found | Role supported by source/schema |
|---|---:|---:|---|---|
| `add_link` | 5 | 2 | INSERT, SELECT | Ownership and token/remark registry for added links; bot SQL links it to an actor via added_by. |
| `admins` | 5 | 0 | No literal site found | Administrative-looking account/configuration fields in the supplied schema; no literal SQL sites found in this PHP set. External use is unknown. |
| `bc_jobs` | 11 | 15 | INSERT, SELECT, UPDATE | Pin/unpin/delete/edit job queue, parent broadcast, counters, cursor and timestamps. |
| `bc_messages` | 9 | 12 | INSERT, SELECT, UPDATE | Per-broadcast recipient message IDs with pin/delete state and timestamps; duplicate broadcast/user pair constrained. |
| `black_list` | 2 | 0 | No literal site found | Schema-only blacklist-looking structure; no literal references in scanned PHP. Do not infer it is safe to delete. |
| `chats` | 7 | 19 | INSERT, SELECT, UPDATE | Support-ticket header: user, category, state, rate and creation time. |
| `chats_info` | 5 | 11 | INSERT, SELECT | Support-ticket message details linked logically by chat_id. |
| `discounts` | 15 | 25 | DELETE, INSERT, SELECT, UPDATE | Discount rule and audience/target selectors, remaining count, cap, activity and JSON used_by. |
| `gift_list` | 6 | 7 | DELETE, INSERT, SELECT, UPDATE | Gift batch volume/days plus scan/server offsets. |
| `increase_day` | 3 | 17 | DELETE, INSERT, SELECT, UPDATE | Priced duration-extension catalog; legacy volume column name must remain. |
| `increase_order` | 9 | 23 | INSERT, SELECT | Nine-column extension/renewal ledger; optional day and volume coexist with older seven-value INSERT paths. |
| `increase_plan` | 3 | 17 | DELETE, INSERT, SELECT, UPDATE | Priced traffic-extension catalog. |
| `needed_sofwares` | 4 | 1 | SELECT | Software/help-link catalog; preserve the original misspelled table name. |
| `orders_list` | 19 | 161 | DELETE, INSERT, SELECT, UPDATE | Provisioned subscription, owner, plan/file reference, panel/inbound, token/UUID/links, expiry, price/status/notification and auto-renew flags. |
| `pays` | 21 | 118 | DELETE, INSERT, SELECT, UPDATE | Payment request and approval state, amount, provider ID, target/order and receipt/admin-message metadata. |
| `send_list` | 14 | 24 | INSERT, SELECT, UPDATE | Broadcast content/target/queue state and cursor; preview message/recipient metadata. |
| `servers` | 8 | 0 | No literal site found | Separate legacy server credential structure; no literal references found. Not equivalent to server_info/server_config by assumption. |
| `server_categories` | 6 | 27 | DELETE, INSERT, SELECT, UPDATE | Category hierarchy and server grouping, wizard step and activity. |
| `server_config` | 15 | 90 | DELETE, INSERT, SELECT, UPDATE | Panel credentials and protocol/TLS/host/subscription options keyed by server ID. |
| `server_info` | 7 | 52 | DELETE, INSERT, SELECT, UPDATE | Display/capacity/remark/flag and activation/state per configured server. |
| `server_plans` | 26 | 115 | DELETE, INSERT, SELECT, UPDATE | Commercial and protocol plan, category/server/inbound, days/volume/price, limits and advanced connection fields. |
| `setting` | 3 | 58 | DELETE, INSERT, SELECT, UPDATE | Repeated type/value settings including structured JSON blobs; type is not a declared unique key. |
| `users` | 18 | 136 | INSERT, SELECT, UPDATE | Telegram identity, wallet, referrals, persisted conversation, permissions/agency/discounts and spam metadata. |

## Relationships and representations to preserve

These are application-level links inferred from joins/conditions, not newly introduced foreign keys. The supplied DDL contains no `FOREIGN KEY` clauses.

- `users.userid` is the Telegram ID, distinct from the auto-increment `users.id`. Most bot ownership queries use `userid`. Do not replace it with row ID. Legacy `users.userid` has an ordinary index, not a unique constraint; duplicate behavior must not be changed via an implicit unique migration.
- `pays.user_id`, `orders_list.userid`, `chats.user_id`, `bc_messages.userid` and `add_link.added_by` link to actor identity with existing types. `increase_order.userid` is a varchar while other owner columns are bigint; the initial persistence layer must preserve this difference.
- Orders use `fileid` for the plan. Payment `plan_id`, `order_id`, `server_id` and `type` guide different payment paths. Their names do not mean they are interchangeable; the renewal precheck verifies plan correspondence.
- `server_info.id` and `server_config.id` are joined by application convention; `server_plans.server_id`, `server_categories.server_id` and order/payment server IDs participate. `servers` is a separate table, not permission to consolidate it.
- `chats_info.chat_id` links to the support header; `bc_jobs.broadcast_id` and `bc_messages.broadcast_id` link to `send_list.id`.
- `users.step`/`temp` are persisted conversation state. Prefixes, delimiters, JSON types, current values and canceled/in-flight flows must survive deployment. `setUser` has generic state behavior and field-specific writes; keep exact coercion and SQL call order initially. [`config.php:4347`](https://github.com/ach1992/bot-refactor/blob/a89e63713131d16b5d6461fd41c01bb002d0be63/config.php#L4347-L4382).

Integer `wallet`, `price` and `amount` values must not be silently rescaled. Display text uses legacy money labels, while gateway payload conversion can differ by provider; validate each adapter rather than making a blanket conversion. Days/volume can be floating-point plan fields, whereas order/payment/extension fields differ. Provider expiry units and traffic units are converted at adapter boundaries; do not merge seconds/milliseconds or GB/bytes without a per-path equivalence test.

Dates mix Unix-like integers, varchar date fields, DATETIME and TIMESTAMP. Preserve timezone/session behavior, boundary comparisons and inclusivity. `NULL`, empty string, zero, string flags such as `on`/`off`, integer booleans and default values are not interchangeable. Preserve original spelling/case such as `refered_by`, `needed_sofwares`, `autoRenew` and `rewaredTime`.

## JSON and settings contracts

`setting.type` is not unique: repeated category values can be meaningful. `PAYMENT_KEYS` includes key names `nowpayment`, `zarinpal`, `nextpay`, `bankAccount`, `holderName`, `tronwallet`; never place actual values in public fixtures. `BOT_STATES` contains provider/payment/bot/sale/registration/renewal/QR/link/agency toggles and settings; the machine inventory lists every observed key and its callsite. Missing keys and null values already occur as code paths and must not acquire new defaults accidentally.

Other structured values include `users.discount_percent`, `users.spam_info`, `users.temp`, `discounts.used_by`, `orders_list.link`, `pays.admins_messages`, and protocol/TLS/custom plan/config fields. Data shapes can vary with the producer. Fixtures must include legacy strings and decoded arrays/objects, not just idealized newly normalized data.

## Financial state and transaction contract

`pays.state` is varchar, not an enum; routes compare spelling literally. The characterized wallet helper rejects `approved`, `declined`, `expired_have_sent`, repeat `paid_with_wallet`, and busy `processing_wallet` with distinct behavior, and records `low_wallet` when appropriate. Other branches use `pending`, gateway-specific states and numeric strings. This is not a complete finite-state proof; all observed comparisons and assignments can be found in the route/SQL catalogs.

The wallet transaction's SELECT FOR UPDATE, user/payment order, debit, handler call, terminal update, commit and rollback are part of the contract. Thirteen private native-engine checks test selected operations, including sequential repeat and rollback of handler-written fixture data. They do not establish concurrent exactly-once processing, cross-provider atomicity or crash recovery.

## SQL audit: distinguish defects from parser limitations

Native preparation of 489 unique eligible constant strings produces 483 successes and six expected failures. Two are actual statement/schema incompatibilities; four are intentionally incomplete construction prefixes. Twenty-four dynamic/other sites are excluded from this preparation pass. The four prefixes are additionally tested with both actual suffixes: ordinary updates prepare, the legacy clear-field form fails. These results are recorded in `tests/fixtures/sql-prepare-baseline.json`; matching known failures is a characterization result, not a clean bill of health.

See [risk register](RISK-REGISTER.md) for `tron_price`, positional `increase_order` INSERTs and clear-field syntax. **Do not add a column or change the reference schema to make these tests pass.** An operator may have a different live schema or disabled feature; neither is established by this dump.

The native probe compares SHOW CREATE TABLE for every table before and after its synthetic DML. It ignores only the table's next AUTO_INCREMENT counter, which ordinary inserts advance; it does not ignore column/index changes. The reference DDL file remains byte-identical as a separate check.

## Preparing for Phase 2 without doing it

Introduce domain operations with legacy SQL implementations and explicit transaction ownership as each covered use case is refactored. Keep mapping of existing names, types, state formats and provider payloads at those boundaries. Phase 2 may change their implementations only after a separate migration/data/rollback design. No new columns, unique indexes, repair writes, JSON rewrites or migration runner are part of V0 or Phase 1.
