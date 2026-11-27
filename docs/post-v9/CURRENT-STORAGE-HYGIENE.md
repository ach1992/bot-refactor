# Current storage and isolation contract

Scope: [#236](https://github.com/ach1992/bot-refactor/issues/236), following
integrated main 90a9d79e2f8b4814db08c00d5a52c4e5a5d842f7. The runtime
targets only the current schema. PasarGuard is the current physical provider;
historical Native evidence is not an executable legacy provider.

## Database resources and state ownership

MariaDB advisory locks are instance-wide, not database-wide. Settings
documents (BOT_STATES, payment keys, referral settings, server display),
physical panel-group creation, signup, discount writers, report and followup
leases use actual selected database identity plus resource identity.
Physical operations and original-credential delivery use the same
database-scoped account lease. The existing immutable physical journal key
is an account identity, distinct from the session lock name. Never rewrite
it in a sealed intent. Payment-hosted, delivery and service execution
leases already use actual database identity and their respective numeric
resource IDs; the service scheduler's SQL free-lock predicate must agree
with its PHP name. Cron and backup filesystem locks remain installation
scoped. SQL row locks and transaction ordering remain resource-specific.

Separate bots must have separate databases. This isolation does not grant
two independent bots authority to mutate the same remote account, nor
provide a remote compare-and-swap. The Native delayed-write counterexample
remains an acknowledged limitation of the provider contract; a passing
characterization test is not proof that it has been solved.

The 35 current tables belong to the following state owners. Preserve
their current columns, primary/unique identities, indexes, constraints,
generated columns, JSON state and current reader/writer contracts except
for the explicit changes below.

| Owner | Tables |
| --- | --- |
| Account, configuration and catalogue | users, setting, server_info, server_config, server_categories, server_plans, add_link, needed_sofwares |
| Subscriptions and entitlements | orders_list, increase_order, increase_day, increase_plan, gift_list, discounts |
| Original money and attribution | pays, payment_attempts, payment_transactions, wallet_entries, wallet_adjustments, referral_awards |
| Payment delivery, receipts and projections | payment_delivery_intents, payment_delivery_attempts, payment_delivery_parts, payment_followup_intents, payment_followup_attempts, payment_message_refs |
| Native lifecycle and evidence | service_operations, service_effects, service_mutations, service_plan_changes |
| Support and communication | chats, chats_info, send_list, bc_jobs, bc_messages |

Current support history, chat/message identities, historical receipt text
and original Native evidence are data, not old runtime dependencies.
Unknown settings, unclassified history or unresolved business states
are not permission to delete or reinterpret them.

## Exact projection from the integrated pre-hygiene schema

The following is a data mapping for a separately authorized transfer,
not an in-place upgrade script.

| Source | Current target / required preservation |
| --- | --- |
| setting.type = ACHBOT_MARZBAN_PANEL_GROUP | ACHBOT_PANEL_GROUP; preserve each row's ID and name and every server_info.panel_group_id reference. Reject existing destination discriminator collisions, ambiguous names and dangling memberships. |
| orders_list.inbound_id, server_plans.inbound_id, increase_order.inbound_id | No current column. Source values must all be zero; any nonzero value needs explicit reconciliation. The sealed gift-reference and plan fingerprint formats retain their original zero slot; do not rehash historical evidence. |
| payment_attempts.review_notice_claimed_at, evidence_staff_digest | Retire these unused projection fields only from the current schema; retain exact original values in the verified source archive. Keep review_notice_claim_token, evidence_review_sent_at and evidence_projection_checked_at: current code consumes them. |
| PAYMENT_KEYS.usdtAgentRateToman | Omit only the retired agent-rate key; preserve current manual rate, credentials and other fields. |
| ACHBOT_ADMIN_PAYMENT_KEYS_SOURCE.field_versions.usdtAgentRateToman | Omit only this retired revision entry; preserve the document's current revision checks and all other entries. |
| orders_list.uuid | Preserve exactly: current account identity, credential and ownership checks use it. The unused index idx_orders_uuid is removed. |
| server_config.idx_server_id | Remove redundant index on primary-key id, not the identity or column. |

All other retained values and original IDs are copied unchanged, including
the original provider origin in Native snapshots and persisted original
effect, mutation, operation and plan identities. No dual writers,
old-key fallback, historical provider resurrection, fabricated Native
proof, reissuing old effects or replaying old callbacks. Preserve actual
financial opening/debt/ledger history; historical reconciliation remains
[#26](https://github.com/ach1992/bot-refactor/issues/26) and
[#157](https://github.com/ach1992/bot-refactor/issues/157).

## Transfer boundary

For a fresh installation import only database/current-schema.sql.
056 is a construction-sidecar proof, restricted to empty affected
tables. It is not an upgrade of installed data.

For an installed instance, first identify its actual schema, current
runtime, active state and original evidence. Prepare a source-specific
mapping (including any differences from the above exact baseline) and
obtain separate authorization under
[#183](https://github.com/ach1992/bot-refactor/issues/183).
Quiesce old and new webhook/worker/Cron writers before the authorized
transfer; do not run old and new lease naming or schema contracts
concurrently against the same database. Take a consistent private source
archive, independently restore and compare it before omitting any field.
Keep original credentials and dumps private. Transfer into a new clean
current database; never destructively transform the source. Reconcile
row counts and full values or digests, balances and ledger legs, original
receipts, physical membership, ownership, effect and operation references,
entitlements and URLs, generated columns, constraints, foreign-key
relationships and identity allocation counters (including gaps). Reject
malformed/ambiguous setting documents and unsupported source values rather
than guess repairs. Preserve the private source and tested recovery route
until separately authorized cleanup. Activation and installation are
separate gates.

The disposable transfer test compares all 35 table shapes and retained
values in a representative synthetic source, verifies independent dump
restoration, selected rejection conditions, identity/credential evidence
and current group access in MySQLi strict/off modes. It is not a live
migration tool and does not prove an uninspected installed database is
ready. The isolation test uses two databases on one disposable MariaDB
instance. See [CI validation](CI-VALIDATION.md) and
[Wallet-first architecture](WALLET-FIRST-ARCHITECTURE.md).
