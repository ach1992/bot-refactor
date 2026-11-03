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

## Failure and reconciliation contract

The same named physical-account lock serializes transfer and plan changes.
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
