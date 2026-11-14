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

All existing `server_plans.provider_group_ids` are NULL and the orders have no Native PasarGuard effect/remote ID. Old Marzban `server_info.panel_group_id` 61/55 are **not** native PasarGuard Group IDs. Source is RC43. The existing native import procedure handles new zero-price/planless imports only; it must not be replayed for purchased historical orders.

## Inputs required AFTER the panel migration

1. Owner completion signal and authorized protected connection to the real PasarGuard 5.5.1 instance(s) hosting Vivan/Hell, with all migrated users accessible to the intended `bot-admin` authority. API keys belong in protected connection files, never in GitHub/chat.
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

The exporter uses one `START TRANSACTION READ ONLY` snapshot, exporting minimum owner/order/server/plan IDs and usernames, **not** private tokens, links, UUIDs, passwords or balances. Output is operator-private `0600` and must be outside the repo. Three SQL claims fields are conservative attention counts; zero is *not* complete financial settlement proof. This operation has not been executed against production.

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
      "api_key": "PROTECTED_CREDENTIAL_PLACEHOLDER",
      "version": "5.5.1"
    }
  }
}
```

The 32-digit instance key must stay stable across all aliases and future operations. Native adapter authentication and Group permissions are verified by GET-only methods. The export's API key is never written to the output.

Run the Native GET-only exporter **after** the panel migration:

```bash
php tools/post_v9_pg_cutover_native_export.php \
  --local=/secure-private/vivan-local.json \
  --mapping=/secure-private/vivan-map.json \
  --connections=/secure-private/pg-connections.json \
  --output=/secure-private/vivan-native.json
```

It calls only the existing Native PasarGuard 5.5.1 adapter's `GET /api/system`, `GET /api/admin`, `GET /api/groups` and `GET /api/user/by-username/{username}` endpoints, no POST/PUT/DELETE. It excludes private proxy settings, tokens and notes from its JSON output. Errors abort without saving a partial file. Multi-user API reads are sequential, not a transactional panel snapshot.

Then validate completely offline:

```bash
python3 tools/post_v9_pg_cutover_preflight.py \
  --local /secure-private/vivan-local.json \
  --native /secure-private/vivan-native.json \
  --mapping /secure-private/vivan-map.json \
  --private-map /secure-private/vivan-validated-map.json
```

Repeat for Hell. The optional validated private map is created **only when every reference matches**, permission `0600`, never overwritten, never inside the repo. Stdout contains counts and SHA256 input identities, not usernames. It detects missing/colliding native IDs, changed/unknown plans, unbound Native Groups, conflicting shared subscriptions, nonzero inbound/previous native binding, native scheduling and unresolved financial/effect attention counts. Unlimited quota (`data_limit=0`) and unlimited expiry (`expire=null`) remain distinct.

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
