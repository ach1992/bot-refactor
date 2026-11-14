# Vivan / Hell PasarGuard bot cutover preparation (#183)

**Status:** Source-only preparation, **NOT** database conversion or production permission. Run only **after the separate panel/user/node migration** is complete and the Owner authorizes the exact target actions. Do not touch Chili, wallets, historical financial records, user-facing wording, Cron/webhooks or deletion policy.

## Read-only 2026-11-14 baseline

| | Vivan (`botvivan`) | Hell (`bothell`) |
|---|---:|---:|
| Existing Marzban servers | 4: 2,3,12,13 | 5: 2,3,4,5,11 |
| Plans | 38 | 42 |
| Order references | 464 | 2,071 |
| Distinct (server_id, username) | 464 | 2,049 |
| Shared-reference excess | 0 | 22 |
| Planless records | 0 | 12 |
| Auto-renew on | 16 | 136 |

At this baseline, the **Marzban legacy plans** had NULL `server_plans.provider_group_ids` and their orders had no Native PasarGuard effect/remote ID. Old Marzban `server_info.panel_group_id` 61/55 are **not** native PasarGuard Group IDs. These are historical, changing production counts, not a frozen cutover snapshot. The existing native import procedure handles new zero-price/planless imports only; it must not be replayed for purchased historical orders.

## Existing PasarGuard test connections (read-only 2026-11-15 observation)

The Owner has already configured test PasarGuard connections in both live bot databases and directs the future cutover to **reuse the saved per-bot endpoint, API key and validation/authority settings** rather than inventing or separately hardcoding replacement credentials. These observations are not approval to rewrite server rows now.

| | Vivan (`botvivan`) | Hell (`bothell`) |
|---|---|---|
| Existing test PasarGuard server ID / connection ID | 14 / 14 | 12 / 12 |
| `provider_enabled` / server `state` | 1 / 0 (sales disabled) | 1 / 0 (sales disabled) |
| Stored informational PasarGuard version | `5.6.0` | `5.6.0` |
| Original Marzban logical server IDs to map later | 2, 3, 12, 13 | 2, 3, 4, 5, 11 |

The existing API URL and API-key values match between bots by protected, read-only fingerprint comparison, but the **local `provider_instance_key` values differ**. Preserve each bot's own current connection ID and instance key; these local identities are not interchangeable. A direct protected-key Native API probe was not completed, so **current credential validity, effective scope/Group authority and test-user remote readback are not yet verified**.

Hell also has a test plan `server_plans.id=82`, `provider_group_ids=[1]`, and one local test order `orders_list.id=37015` linked to `service_effects.id=41`. That effect has `source_kind=free_trial`, `provider_state=linked` and a stored native account ID/incarnation. This is **test evidence only**, not a paid legacy-order conversion, and Group `[1]` must **not** be copied indiscriminately to historic plans. The test plan row is active, but the test server's sales state is 0.

The production read-only aggregate at 2026-11-15 counted **458 Marzban-linked orders in Vivan** and **2,063 in Hell**, with 38/42 legacy plans, respectively. These counts change during live operation and do not authorize a cutover.

At the authorized later cutover, map every legacy logical server to the verified already-registered **tenant-local** PasarGuard connection and each legacy paid plan to its **actual Native Group set**. Do not change historical server/order/plan IDs, customer credentials, finances, or old subscription/config links. Recheck all data under a controlled source/target snapshot after the external panel migration.

## Inputs required AFTER the panel migration

1. Owner completion signal and a fresh protected read-only validation of each **existing bot-local PasarGuard connection**, its real API/Group permissions and the migrated users under the intended `bot-admin` authority. The reported vendor version is informational; actual API behavior and permissions determine validity. Reuse the already-saved credentials through a protected operator workflow, never paste keys into GitHub/chat.
2. Mapping of each original logical server ID to a native panel alias and of each original plan ID to the native Group IDs. Preserve original plan IDs, prices, titles, quota/time types and server sale state. Native Groups must be real, eligible and nonempty for plans.
3. Verified identities and current native usage/limit/expiry/status/Groups, plus an independent old-config/proxy-credential/subscription-URL continuity test. Usernames alone do not prove exact Native ID and created-at incarnation.
4. Separate reconciliation of unfinished service-bound invoices/old callbacks, financial/effect/plan journals, and any uncertain work. Historical money, balances and original effect/quote evidence remain immutable.
5. A controlled cutover window with permission for the exact DB and writers, restorable full database backups and a paired-runtime recovery plan. No panel-side account edits or replayed native writes.

## Prepare private input snapshots (read-only, no changes now)

Only after exact read-only access is authorized, with a SELECT-only DB account where possible, run the source export on the appropriate host:

```bash
python3 tools/post_v9_pg_cutover_local_export.py --tenant vivan --output /secure-private/vivan-local.json
python3 tools/post_v9_pg_cutover_local_export.py --tenant hell --output /secure-private/hell-local.json
```

The exporter uses one `START TRANSACTION READ ONLY` snapshot, selecting **only server/plan/order rows whose configured provider is `marzban`**; already-existing PasarGuard test rows are not legacy migration sources. Its aggregate finance/effect/journal attention counts still cover the bot database and must be reconciled separately. Exported fields are minimum owner/order/server/plan IDs and usernames, **not** private tokens, links, UUIDs, passwords or balances. Output is operator-private `0600` and must be outside the repo. Three SQL claims fields are conservative attention counts; zero is *not* complete financial settlement proof. This operation has not been executed against production.

For each tenant, create a private mapping file, e.g. this **synthetic example** (not a real assignment):

```json
{
  "version": 1,
  "tenant": "vivan",
  "server_panels": {"2": "main"},
  "plan_groups": {"9": [1, 2]}
}
```

The real files must enumerate **every** existing server ID and plan ID, with sorted positive native Groups. Native `group_ids=[]` can be valid for an existing planless native subscription, not an ordinary plan.

Create private credentials `/secure-private/pg-connections.json` with mode `0600`. The values below are placeholders only:

```json
{
  "version": 1,
  "panels": {
    "main": {
      "id": 71,
      "instance_key": "0123456789abcdef0123456789abcdef",
      "api_url": "https://pasarguard.example.invalid",
      "api_key": "PROTECTED_CREDENTIAL_PLACEHOLDER"
    }
  }
}
```

The 32-digit instance key must stay stable **for a given bot-local connection** across its mapped aliases and future operations; do not force Vivan and Hell to share one local instance key. Use each existing saved connection ID/key rather than these synthetic values. The optional provider `version` metadata is informational, never a compatibility allowlist. Native adapter authentication and Group permissions are verified by GET-only methods. The export's API key is never written to the output.

Run the Native GET-only exporter **after** the panel migration:

```bash
php tools/post_v9_pg_cutover_native_export.php \
  --local=/secure-private/vivan-local.json \
  --mapping=/secure-private/vivan-map.json \
  --connections=/secure-private/pg-connections.json \
  --output=/secure-private/vivan-native.json
```

It calls only the existing native PasarGuard adapter's `GET /api/system`, `GET /api/admin`, `GET /api/groups` and `GET /api/user/by-username/{username}` endpoints, no POST/PUT/DELETE. It excludes private proxy settings, tokens and notes from its JSON output. Errors abort without saving a partial file. Multi-user API reads are sequential, not a transactional panel snapshot.

Then validate completely offline:

```bash
python3 tools/post_v9_pg_cutover_preflight.py \
  --local /secure-private/vivan-local.json \
  --native /secure-private/vivan-native.json \
  --mapping /secure-private/vivan-map.json \
  --private-map /secure-private/vivan-validated-map.json
```

Repeat for Hell. The optional validated private map is created **only when every reference matches**, permission `0600`, never overwritten, never inside the repo. Stdout contains counts and SHA256 input identities, not usernames. It detects missing/colliding native IDs, changed/unknown plans, unbound Native Groups, conflicting shared subscriptions, nonzero inbound/previous native binding, native scheduling and uncertain native effects or plan-change journals. Existing nonfinal invoices are separate **financial attention**, not an unconditional account-mapping block; they must be individually reconciled before a separately authorized conversion/fulfillment, without suppressing later unrelated payments. Unlimited quota (`data_limit=0`) and unlimited expiry (`expire=null`) remain distinct.

A passing offline consistency result is **not** approval or readiness for production. It does not prove actual old config/URL continuity, bot-admin ownership, live payment settlement, frozen source/target identities, or valid SQL conversion.

## Remaining gated execution work

- Build/verify the future **one-time historical-order SQL conversion** using original order and financial history *without* calling the existing zero-price import, overwriting paid rows, creating users or replaying Native/payment operations.
- Link every bot order reference to an exact native ID and created-at with durable **migration-specific provenance**. Hell's duplicate local references must share the same verified physical Native account. Planless orders retain their ownership and planless semantics.
- Reverify all remote data and Native Groups immediately before the approved cutover; quiesce webhook and Cron writers only with exact production permission. Preserve rollback/roll-forward evidence, avoid unsafe SQL replay and cross-provider settlement of old invoices.
- After conversion, verify read-only order details/links/usage/status and separately authorized new purchase, renewal, add-on, auto-renew and administrative plan changes. Do not alter the 10-day actual/3-day user-facing deletion policy.

## Synthetic validation only

```bash
python3 tools/test_post_v9_pg_cutover_preflight.py
python3 tools/test_post_v9_pg_cutover_native.py
python3 tools/test_post_v9_pg_cutover_source_mariadb.py
php -l tools/post_v9_pg_cutover_native_export.php
```

All native test traffic uses synthetic loopback HTTP; tests assert GET-only and zero native writes. See [#183](https://github.com/ach1992/bot-refactor/issues/183), [native contract](PASARGUARD-CONTRACT.md), [shared provider storage](SHARED-PROVIDER-STORAGE.md).
