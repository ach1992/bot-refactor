# Shared provider storage (012)

The owner-defined account model uses each `orders_list` row as a customer's
subscription reference. Current expiry, usage, state and native links come from
the panel. There is no second subscription registry or connection table.

| Existing owner | Current fields and responsibility |
| --- | --- |
| `server_config` | `panel_url` and `password` hold endpoint/API key; `provider_connection_id`, `provider_instance_key`, `provider_version`, `provider_enabled` bind logical server aliases to their native connection |
| `server_plans` | Explicit `provider_group_ids` per plan; no server-level inheritance or fallback |
| `orders_list` | `provider_effect_id`, `provider_remote_user_id`, `provider_remote_created_at` identify the originating verified native effect/account incarnation |
| `service_effects` | Existing create/adopt evidence owns pre-order reservation, uncertain dispatch and native identity; nullable `provider_*` fields preserve its durable mutation/replay evidence |
| `pays` / effect snapshots / import origin | Existing immutable quote, operation and genuine no-capacity import provenance; never rewritten by consolidation |

`provider_reference_id` and `provider_connection_id` retain the numbers already
serialized into historical quotes/adoption evidence. New originating effects use
their own effect ID and new connections use their actual server ID. The historical migration 012
preserved both current and retired-table allocated sequences, and advanced them
above all existing connection IDs (including orphan legacy config rows) and old
serialized numbers before dropping the
source tables. These are evidence identifiers, not another registry. Shared
customer references point to the same proven origin effect and native ID/creation
time. They retain independent order/payment ownership.

When adopting an existing external PasarGuard account, a **new physical**
originating effect uses the immutable `import:<orderId>` target key, not the
username-scoped `pg:<instance>` key that may still exist on a verified-deleted
incarnation. Legacy `pg:<instance>` effects and their deleted/uncertain
provenance remain intact. A newly verified Native ID and creation time may
reuse a retired username only after the older local active references have
been resolved; no SQL migration is required for this source change.

**Deployment rollback boundary:** after the new runtime has written
`import:<orderId>` effects, an older Stable application may not validate
their provenance. Do not downgrade application files alone; preserve the
matching database/runtime recovery pair and use a separately verified
rollback or forward-recovery plan. Source release alone performs no import
and changes no installed database.

An original Marzban→PasarGuard migration can have one physical
`legacy_migration` effect and multiple verified order references. Its
`service_effects.order_id` intentionally remains **NULL**. Provenance is
checked against the original migration hashes, provider identity, Native
incarnation and sorted reference list. A later legitimate Native mutation
moves `provider_state` from `linked` to `mutating` without invalidating
that immutable provenance; the active mutation key/identity journal must
match the original adoption. Paid edit recovery resolves the customer's
order via the authenticated migration reference, not by inventing or
backfilling an originating `order_id`. A later real PasarGuard external
attachment to that same physical migrated account is **not** appended to the
historical migration reference list: its independent
`ACHBOT_SERVICE_ORIGIN:<order_id>` must pass the shared import-origin,
owner and exact Native account/incarnation checks at recovery admission.
Independent Native entitlement drift
still requires operator reconciliation; it is not evidence that the original
purchase was reapplied, nor permission for a blind additive write.

Native-only evidence updates keep the financial effect's `updated_at` unchanged.
`provider_evidence_created_at` / `provider_evidence_updated_at` preserve the old
native evidence timestamps and track future native operations separately. A
committed mutating/unknown outcome remains held after consolidation; deleting a
local order/server cannot cascade away its operation/payment evidence.

## Fresh installation and existing-instance boundary

Use the single consolidated [current schema](FRESH-INSTALL.md), including the
current provider and service-operation identities. The historical migrations
012/013 describe how older source once changed its tables; they are not an
installation or upgrade procedure for the Wallet-first replacement. Existing
instances require the separately authorized manual transfer/reconciliation in
[the architecture](WALLET-FIRST-ARCHITECTURE.md#cutover-decision--controlled-manual-database-transfer-owner-2026-11-24).
Never replay historical effects or replace real provenance with a guessed
mapping. The read-only readiness check is
[the current preflight](PAYMENT-READINESS-PREFLIGHT.md).

## Isolated evidence

`tools/test_post_v9_shared_provider_storage.py --run-disposable` exercises a
populated transfer with two customer references across two server aliases,
immutable payment/origin/hash/order/wallet evidence, long credentials, unknown
pre-order dispatch, shared mutation/replay protection, noncolliding sequences and
an actual full-dump restore. It also proves required malformed/missing mapping
cases stop before dropping source tables or altering application columns.

Native client/storage, provider registry, import/renewal/lifecycle and readiness
suites use the real shared schema and PHP. Archived historical code keeps its own
old schema. No compatibility SQL views or native-proof bypass is used. These are
owned socket MariaDB/loopback fixtures; no tenant migration or live provider,
Telegram, wallet or merchant acceptance is claimed.
