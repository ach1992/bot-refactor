# Shared physical service transfers and plan access (Issue #112)

This is a source-only operator contract. A published ZIP or GitHub release is
not an installation or authorization for real native/provider effects.

## Owner-accepted behavior

* The admin assigns a shared logical-panel group only when server entries
  point at **the same real native panel and the same provider type**. The group
  chooser explains this rule. The admin declares real identity; we do not
  infer it from URLs, usernames or API keys. Keep the legacy internal
  setting.type ACHBOT_MARZBAN_PANEL_GROUP for stable stored group IDs.
* Logical panel groups and PasarGuard native access Group IDs are different
  concepts. Native PasarGuard Groups are chosen per **plan only**, not in
  server onboarding/server configuration.
* An admin always sees Transfer in the order details. If no valid same-group
  server exists, show a clear no-destination explanation; do not hide the
  control. Cross-panel or Marzban-to-PasarGuard migration is unsupported.
* On transfer, change the logical server for **every active local reference to
  the exact physical subscription**, clear their old plan IDs and disable
  auto-renewal consent on the now-planless orders. No remote account move,
  cloning or deleting takes place. Admin selects a valid destination plan.
* On choosing a plan, the shared native subscription receives that plan's
  Marzban Inbounds/Hosts or PasarGuard Groups, affecting all linked users.
  After verified native readback, all active reference rows adopt that same
  plan/server. This is not a new sale, a quota or expiry change, a balance
  debit, or a new native subscription. A plan requiring new Marzban proxy
  credentials that the existing account cannot supply is refused safely.
* Selecting another local plan with the **same actual native access** is a
  valid change to the local plan references and must not send a Native PUT,
  reset or alter the account. Compare canonical Hosts/Inbounds or Groups to a
  fresh strict readback of the pinned physical account; do not infer equality
  from plan IDs or cached local configuration. Marzban skips an unnecessary
  host-catalog and second account GET on this verified no-change path;
  PasarGuard skips redundant administrator/Group catalog GETs. On changed
  access, retain authorization, account-drift checks, the native mutation
  fence and verified post-PUT readback.

## Failure and reconciliation contract

The same named physical-account lock serializes transfer and plan changes.
For ungrouped Marzban the lock uses the immutable logical server ID,
**not** its editable URL; for grouped Marzban it uses the explicitly assigned
group ID. Active subscriptions cannot change their physical-group
classification, including from ungrouped to grouped. Administrator changes to
Marzban URL/credentials are denied for a referenced server/group and blocked
by any outstanding journal even if active local orders have disappeared.
PasarGuard API-key rotation likewise cannot bypass an unfinished plan journal.
The old journal identity remains visible for manual reconciliation.

Before claiming a plan change or logical transfer, verify that every active
shared order reference is financially settled across pays, payment_attempts,
automatic_renewal_attempts, type-encoded legacy add-ons and service_effects.
Unlike normal auto-renewal reservations, a plan/server reassignment cannot
ignore an unclaimed payment quote; unfulfilled paid payments also block.
Previously settled historical receipts remain unmodified and do not block.
Revalidate the financial fence immediately before a provider write and again
before the atomic local finalization.

Each intent includes a cryptographic fingerprint of the exact source
connection, administrator credential binding, group and provider identity;
secrets themselves are not persisted in that journal. Revalidate it in a
locked final transaction even for a Native no-op. A changed-access request
also checks the original target and financial work at the last local
pre-PUT boundary. Marzban and PasarGuard Host/Group-only writes must preserve
used_traffic monotonically (ordinary concurrent usage growth is allowed);
an unexpected counter reduction leaves the journal in review without another
PUT. Native effect uncertainty always requires operator reconciliation.


For PasarGuard, existing service-effects native mutation evidence also fences
the remote write. A service_plan_changes journal row must be committed before
a network PUT. On success, all still-current local references and the journal
become committed in a single DB transaction, with the PasarGuard fence
finished in that transaction. An ambiguous PUT or DB COMMIT result keeps a
durable hold; do **not** replay from a Telegram callback.

To reconcile, a separate authorized operator must inspect the journal
intent_json, provider effect state, actual native account incarnation and
Hosts/Groups, linked order rows, and unsettled payment effects. Logs should
carry bounded order/journal IDs and error codes, not credentials or tokens.
Never mark a journal settled based on an unchecked HTTP response.

## Source-only schema migrations

* Migration 016_remove_pasarguard_server_groups.sql removes only the obsolete
  test-era column server_info.provider_group_ids after checking all active
  PasarGuard plans have explicit native provider_group_ids values.
  No order, plan, finance, wallet, native account or effect data are deleted.
* Migration 017_shared_plan_changes.sql introduces the durable plan journal.
  Both are one-time, non-replayable migration assets. Historical migrations
  001 through 015 remain untouched.
* On any separately authorized real tenant: inspect active runtime and actual
  schema, prove backup/restore on an isolated copy, gate webhook/Cron,
  apply only missing migrations in documented order, and verify new runtime.
  In particular, **never replay** completed migrations 012/013/014/015 on NL.
* Exact-source current PHP 8.5 acceptance plus independent source review is
  required before integration. This work does not deploy to NL Chili or call
  actual payment/native providers.
