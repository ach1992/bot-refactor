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
New steps use the existing transaction, role and Telegram update-ID fences.
Incorrect or stale input does not change pricing.

## Financial invariants and race handling

Every new scoped issued quote pins kind, offer ID, units, base price,
discounted final price, logical server ID, owned order ID, provider,
remark, verified native identity fingerprint, exact native Group-set and
scope fingerprint inside pays.addon_scope_snapshot. The existing pays.price
and pays.day / pays.volume retain the exact original financial and service
units. Agent discount is calculated before this quote is issued.

Every entry point must verify ownership and local provider identity before
claim, and the exact native Group-set and provider identity before payment
or remote provider effects. Stale callbacks, removed Groups, changed
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
in full CI, with its own evidence artifact. No new native, payment or
Telegram API endpoints are contacted. Historical accepted Persian copy
proofs remain intact; the 12 new scoped editor keys have a separately
pinned current-only text manifest.

No actual operator installation, release, live group mutation, real
gateway-money operation or production migration is tested/authorized here.
Independent high-assurance review and Owner approval remain mandatory.
