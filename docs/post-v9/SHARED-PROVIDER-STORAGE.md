# Shared provider storage (012)

The owner-defined account model uses each `orders_list` row as a customer's
subscription reference. Current expiry, usage, state and native links come from
the panel. There is no second subscription registry or connection table.

| Existing owner | Current fields and responsibility |
| --- | --- |
| `server_config` | `panel_url` and `password` hold endpoint/API key; `provider_connection_id`, `provider_instance_key`, `provider_version`, `provider_enabled` bind logical server aliases to their native connection |
| `server_info`, `server_plans` | Existing `provider_group_ids`; NULL plan selection inherits its server |
| `orders_list` | `provider_effect_id`, `provider_remote_user_id`, `provider_remote_created_at` identify the originating verified native effect/account incarnation |
| `service_effects` | Existing create/adopt evidence owns pre-order reservation, uncertain dispatch and native identity; nullable `provider_*` fields preserve its durable mutation/replay evidence |
| `pays` / effect snapshots / import origin | Existing immutable quote, operation and genuine no-capacity import provenance; never rewritten by consolidation |

`provider_reference_id` and `provider_connection_id` retain the numbers already
serialized into historical quotes/adoption evidence. New originating effects use
their own effect ID and new connections use their actual server ID. Migration012
preserves both current and retired-table allocated sequences, and advances them
above all existing connection IDs (including orphan legacy config rows) and old
serialized numbers before dropping the
source tables. These are evidence identifiers, not another registry. Shared
customer references point to the same proven origin effect and native ID/creation
time. They retain independent order/payment ownership.

Native-only evidence updates keep the financial effect's `updated_at` unchanged.
`provider_evidence_created_at` / `provider_evidence_updated_at` preserve the old
native evidence timestamps and track future native operations separately. A
committed mutating/unknown outcome remains held after consolidation; deleting a
local order/server cannot cascade away its operation/payment evidence.

## Migration and recovery

`migrations/post-v9/012_shared_provider_storage.sql` is source preparation only.
It requires exact011 plus prior001/007 and the installation's existing payment
migrations. It transfers API keys without the old100-character truncation risk,
transfers native evidence and all shared local references (including fileid0
imports), verifies transferred values, then removes `provider_accounts`,
`provider_panels` and `server_info.provider_panel_id`. It does not contact any
panel, alter balances/capacity, create payment rows or rewrite historical hashes.
Migration013 separately removes obsolete order protocol/rahgozar fields.

An orphan saved connection, ambiguous/missing native mapping, missing/wrong-type
JSON field or malformed/duplicate origin blocks before application-table DDL.
There is no automatic guess, hidden archive table or discarded credential.
Operators must audit/map those identified rows before a separately approved
execution. The temporary procedure may remain after a refused call; do not replay
the file blindly. Preserve its error and original source tables for audit.

For an actual rollout, quiesce webhook and all leased Cron workers, verify a
restorable full backup, apply the reviewed012 then013, deploy their matching
runtime, run trusted read-only readiness and verify retired objects are absent.
DDL commits implicitly. A partial failure requires inspection/roll-forward or
restoring the complete pre-migration DB and paired old runtime. Rolling back
source alone to RC14 after012 is not supported because RC14 needs its old tables.
Do not keep old/new writers active together or reset the existing Cron lock.

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
