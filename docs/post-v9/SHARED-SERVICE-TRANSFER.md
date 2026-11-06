# Shared physical service transfers and plan access (Issue #112)

This is a source-only operator contract. A published ZIP or GitHub release is
not an installation or authorization for real native/provider effects.

## Owner-accepted behavior

* Panel Group is an **administrator-owned physical-panel declaration**, not
  an account or order operation. The admin assigns, reassigns or clears the
  group of any configured Marzban/PasarGuard logical server, including when
  there are active or historical orders, different plans, pending payments,
  unsettled native effects or plan journals. Assignment validates only the
  existing group (or unassigned choice), configured supported server and
  administrator authority. It changes only `server_info.panel_group_id`.
  It does not edit orders, plans, finance, native accounts or credentials,
  query the provider, or transfer a service.
* The admin declares two logical servers to be on the **same actual physical
  panel** when assigning one group, even if endpoint URLs, administrator
  usernames, API credentials or provider connection records differ. These
  differences never block assigning the group; the chooser describes the
  admin's responsibility. Provider type and real Native account identity are
  still validated by later consumer operations; a group is not a permit to
  mutate an unverified account. Retain the historical storage
  `setting.type = ACHBOT_MARZBAN_PANEL_GROUP` for stable group IDs.
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

The same named physical-account lock serializes transfer, plan changes and
new existing-subscription attachments when the plan journal is installed.
Import obtains the lease before entering its SQL transaction; a pending plan
journal rejects a new attachment even after the lease was released. This
prevents an extra customer's reference from appearing between the original
journal snapshot and its Native PUT. A final pre-PUT membership recheck also
detects unexpected new references and holds the change for operator review.
Legacy installations without migration 017 retain their existing import path.
For ungrouped Marzban the lock uses the immutable logical server ID,
**not** its editable URL; for grouped Marzban it uses the explicitly assigned
group ID. **Changing group membership is never blocked by an active order,
an unfinished journal or a different endpoint/credential binding.** This
does not grant an existing Native operation permission to proceed with its
stale source-group identity: outstanding attempts must revalidate their
current pinned identity and fail/hold safely if it changed; never replay an
uncertain remote effect. This independent group assignment rule does not
relax the separate rule that administrator **endpoint changes** to Marzban
are denied for a referenced server/group. Credential rotation without an
endpoint change remains possible on an active server, but all connection
changes are blocked by outstanding journals even if active local orders
have disappeared.
PasarGuard API-key rotation likewise cannot bypass an unfinished plan journal.
The old journal identity remains visible for manual reconciliation.

**Owner Revision 4 (2026-11-04) supersedes the earlier forced retirement.**
Server deletion, server sales state, and plan availability are distinct operations.
After an explicit consequence warning, an authorized administrator may hard-delete
a local server connection (the server_info/server_config rows) even while linked
subscriptions, invoices, Native effects, or plan-change journals exist. The
historical orders, plan rows, payments and durable operation evidence remain
unchanged; no Native account is deleted or refund invented. The administrator
accepts that previously linked bot order details, pending renewal/checkout,
and recovery may be unavailable and require manual reconciliation. In-flight
operations must fail safely without replaying uncertain Native effects.

Destructive server and plan confirmations are actor- and Telegram-message-bound,
expire after 10 minutes, and carry a fresh one-use token and a digest of the
local entity state shown in the warning. A newer warning replaces the earlier
one; Back cancels its own warning. A stale, replayed, wrong-actor or
post-edit confirmation cannot delete. Confirmation consumption and local
hard deletion commit together. Plan visibility uses explicit desired-state
callbacks and a persisted revision token, so a duplicate or older button
cannot reverse the administrator's latest choice. These protections use the
existing `setting` table without an additional migration.

An existing server's `state` is three-valued without a schema migration:
`1` = new sales and existing renewals/add-ons allowed;
`2` = no new sales, existing renewals/add-ons allowed;
`0` = no new sales and no renewals/add-ons (default inactive behavior).
The existing `active` column continues to identify a configured server row;
it is NOT the sales switch. A configured server can still serve read-only
order details in all three sale states. Unrecognized states fail closed.
The server list opens an explicit three-option state selector rather than a
misleading binary switch.

Plans have independent `active` visibility. An inactive plan remains hidden
from all new-purchase and new plan-selection paths. An existing order with
that exact saved plan can still renew and purchase add-ons when its server
allows existing-service commerce. The plan can be reactivated; explicit
administrator plan deletion removes only the local plan row with a warning
that historical orders remain but may lose renewal capability. Receipt
state, Native identities, refunds and order rows are never mutated by
plan or server deletion. Quote, draft issuance, hosted/manual/wallet and
automatic renewal run the same current server-state admission checks. In
particular, API-backed service claims recheck the server's current commerce
policy at the final SQL-only financial admission boundary even when earlier
Native preflight was valid; already-committed effects retain their recovery
holds and immutable payment evidence. A completed `auto_cart_approved`
renewal/add-on does not by itself block scheduled deletion, but an unfinished
provider attempt, ambiguous payment or Native effect still does.

Before claiming a plan change or logical transfer, verify that every active
shared order reference is financially settled across pays, payment_attempts,
automatic_renewal_attempts, type-encoded legacy add-ons and service_effects.
Unlike normal auto-renewal reservations, a plan/server reassignment cannot
ignore an unclaimed payment quote; unfulfilled paid payments also block.
Previously settled historical receipts remain unmodified and do not block.
Historical `declined` manual receipts and verified unclaimed `mismatched_plan`
renewal drafts are non-payable only with no `payid` or provider attempt (and
therefore no child provider transaction). Unfinished attempts, unknown payment
evidence and Native effects still block even under a terminal-looking state.
Fully fulfilled `auto_cart_approved` follows existing successful payment rules.
A sent-receipt `expired_have_sent` remains uncertain and is not silently
classified as harmless. No historical financial record is rewritten.
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
