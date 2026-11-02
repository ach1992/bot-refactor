# Scoped day and volume extension offers (Issue #107)

Status: SOURCE-DEVELOPMENT CANDIDATE ONLY. Not a release or installation authorization.
Applies to current post-V9 source; historical accepted V0-V9 schema remains frozen.

## Eligibility and price precedence

Existing increase_day and increase_plan row IDs, quantities and prices are retained.
Optional migration 015_scoped_addons.sql adds scope metadata with three meanings:

| Scope | Data | Eligibility |
| --- | --- | --- |
| Global | server_id NULL, group_ids NULL | All supported servers |
| Server | logical server ID, group_ids NULL | Exact Marzban/PasarGuard logical server |
| PasarGuard Group-set | logical PasarGuard server ID plus canonical Group ID JSON | Exact native Group-set on the selected native account |

Hash the unambiguous scope strings G, S:<server-id>, and
P:<server-id>:<sorted-comma-separated-group-ids> with SHA-256.
These are stored as scope_hash; an index prevents duplicate
quantity+scope pairs. Do not confuse native PasarGuard groups with Marzban
panel_group_id. For a given extension kind and integer unit count,
priority is exact native PasarGuard Group-set > server > Global.
Multiple native Groups are one exact set, never a cheapest matching
individual Group. Show at most one offer per quantity. Ambiguous/corrupt
catalog entries fail closed.

## Administrator workflow

In dayPlanSettings or volumePlanSettings create an offer and choose Global,
Server, or PasarGuard Groups on a Server. Select a valid active logical
server if needed. PasarGuard native group IDs are entered as a comma
separated set, for example 3,7; the server must be PasarGuard and the
Group lookup must verify active/allowed Groups. Enter units-price,
for example 10-25000. Existing offers display scope and can change
scope; existing legacy edit/delete and administrator permissions remain.
The list provides All, Global and Server filters for both day and volume;
the Server view shows this server's own offers (including all of its native
Group-set variants), rather than unrelated servers or Global-only entries.
Filters are read-only and cannot interrupt an active admin editor.
Every scoped-editor session now carries a fresh, server-stored 16-hex nonce
in its Scope and Server picker callbacks. A newer Telegram update ID alone
never authorizes an old keyboard from another editing session. Every offer
edit/delete/scope button in a migrated catalog also contains a 20-hex
content revision for the exact displayed kind, ID, scope, quantity and
price. The existing authenticated SQL transaction locks and checks that
row before any write; deleted or changed rows are rejected, not silently
reinterpreted. Older unbound migrated callbacks are refused. Legacy
unmigrated global catalogs keep the pre-015 path without weakening migrated
state checks. All callback payloads stay inside Telegram's 64-byte limit.

New steps retain the existing role, private Telegram frame and update-ID
fences. Incorrect or stale input does not change pricing.

## Financial invariants and race handling

Every new scoped issued quote pins kind, offer ID, units, base price,
discounted final price, logical server ID, owned order ID, provider,
remark, verified native identity fingerprint, exact native Group-set and
scope fingerprint inside pays.addon_scope_snapshot. The existing pays.price
and pays.day / pays.volume retain the exact original financial and service
units. Agent discount is calculated before this quote is issued.

Every entry point must verify ownership and local provider identity before
claim, and the exact native Group-set and provider identity before payment
or remote provider effects. PasarGuard account Group membership must still
be active AND allowed by the current credential; only Group existence and
historical server defaults are insufficient. Disallowed native Group
membership fails closed even when a Server/Global price is available,
rather than accepting money for an unfulfillable fallback. The trusted
current Group eligibility result is reused during one catalog resolution,
not inferred from arbitrary callback-supplied IDs. Stale callbacks, removed Groups, changed
server/provider identity, deleted offers or altered scopes fail closed
for a refreshed quote or reconciliation, never automatic repricing.
An already-issued quote must never silently change amount because of
admin edits. New draft issuance fails when the selected offer price
changed while the draft was being created.

Pre-migration invoices have a NULL snapshot and retain original amount
and units. They may be fulfilled only if they still unambiguously
correlate with a legacy Global offer. Otherwise stop for manual
reconciliation, not an invented alternative price or scope.
Existing idempotency journals, wallet protections and gateway records
remain authoritative. Never retry an uncertain external effect blindly.

## Operator migration and rollback (separate authorization required)

1. Quiesce the bot webhook and Cron, verify no in-flight payment effects,
   take an installation-specific DB backup and test its restoration.
2. Confirm earlier approved migrations and an audited original Global
   catalogue. Inspect duplicate quantities and invalid legacy units.
3. Rehearse migration 015 on a disposable DB copy. It checks old columns
   are absent and refuses duplicate or malformed legacy offers. CI ships
   a SHA-256-verified 015 SQL sidecar, never an automatic migration.
4. Only when separately authorized, apply 015 once and verify original
   row IDs, prices, legacy pay snapshots and both new indexes/columns.
   Runtime readiness requires both exact unique (volume,scope_hash) BTREE
   definitions, their declared order and transactional InnoDB tables, not
   merely a complete-looking column list. Partial/index-drifted
   installations remain non-operational until separately reconciled.
5. Deploy the paired compatible source, verify readbacks and isolated
   financial scenarios before resuming the bot.

DDL is not transactionally atomic; a partial migration must be inspected
and reconciled, never replayed blindly. Dropping new snapshot columns
after scoped invoices exist would destroy financial provenance: first
reconcile those invoices and prepare a reviewed compatible restore. Do
not execute any of these installed-host steps from source-only authority.

## Verification

SQL-only owned disposable socket fixture:
  PYTHONPATH=tools python3 tools/test_post_v9_scoped_addons.py --sql-only

PHP8.5-only real PHP+isolated MariaDB fixture:
  PYTHONPATH=tools python3 tools/test_post_v9_scoped_addons.py --run-disposable

The latter runs only on the existing current-runtime native fifth shard
in full CI, with its own evidence artifact. The review-remediation
candidate extends it with stale multi-admin callbacks, index drift,
current credential Group authority and a controlled native HTTP identity
readback, and financial claim/dispatch/finalization/replay checks. Any new
financial adapter in the test is local-only; full acceptance must be
supported by the artifact actually produced for the exact candidate,
never by this description. The fixture rejects unbounded current-runtime
PHP deprecations rather than silently treating them as successes. No new native, payment or
Telegram API endpoints are contacted. Historical accepted Persian copy
proofs remain intact; the 14 current-only scoped editor keys have a separately
pinned current-only text manifest.

No actual operator installation, release, live group mutation, real
gateway-money operation or production migration is tested/authorized here.
Independent high-assurance review and Owner approval remain mandatory.
