# Subscription presentation and per-server settings

Current source contract for Issue #81 / Issue #26 revision 3. This does not
establish installed provider acceptance or authorize tenant/config/schema,
deployment, payment, or real provider changes.

## Administrator configuration

Open **server settings → نمایش لینک و QR** for the desired logical Marzban or
PasarGuard server, then use the config/subscription toggles, QR choices, link limits and guided
protocol/name selections. Marzban compatibility has guided index lists. A concise
Persian summary shows current values. Optional **ویرایش پیشرفته** accepts a full
JSON document in the administrator's private chat.
Logical servers sharing a physical panel have independent display documents.

~~~json
{
  "mode": "native",
  "policy": {
    "selectors": [{"protocol": "vless", "name": "Fast"}, {"protocol": "trojan"}],
    "show_configs": true,
    "show_subscription": true,
    "max_links": 4,
    "hide_if_more_than": 20,
    "qr": "auto"
  }
}
~~~

An absent setting defaults to native mode, visible configs and subscription,
at most ten configs, and automatic QR selection. Defaults do not read/import
old private globals or global bot display switches. The source navigation
follow-up after RC16 removes the Bot Settings shortcut: open the selected
server's display menu directly. Retired global display callbacks and persistence
keys remain rejected. Other bot/service/payment availability controls are unchanged.

All fields in one selector must match; records are alternatives, processed in
configured order with native order within a match. Protocol is case insensitive;
name/tag are exact. Tags require upstream metadata, never an inferred name or
provider Group. The pinned PasarGuard client supplies native links without tags.
Duplicate native URIs are removed. Omitted selectors select all native configs;
an empty list selects none. A miss never falls back to an unselected config.

| Native policy field | Valid values |
| --- | --- |
| selectors | Up to 50 records with protocol/name/tag; bounded nonempty strings |
| show_configs | Boolean; inline configs and native config QR data |
| show_subscription | Boolean; subscription, backups and named subscription QR |
| max_links | Integer 0–1000; zero selects none |
| hide_if_more_than | Integer 0–1000; hide configs above native-count threshold |
| qr | auto, subscription, first_config, none |

The qr field controls the single delivery QR. Automatic selection uses the
visible subscription then first visible config; subscription mode has no config
fallback. A named subscription QR always uses subscription_url; a config QR
action uses qr_config_links. The none mode is valid text-only delivery and does
not prevent an otherwise verified account from being recorded. Text requires an
independent Telegram acknowledgement. Ambiguous effects are never retried.

Hard transport bounds apply even to larger explicit limits: at most ten
inline/config-QR links and 2200 bytes of rendered link content, prioritizing the
subscription URL; at most two backup URLs within the remaining budget. Overlong
native configs/URLs are omitted whole, never truncated or rewritten. A safe
notice explains limits and directs the customer to their subscription/support.
These are display limits, not provider entitlement controls.

## Explicit managed Marzban compatibility

This opt-in document preserves established positional selection without
reading baseInfo.php:

~~~json
{
  "mode": "marzban_legacy",
  "policy": {},
  "legacy": {
    "showIndexes": [2, 4],
    "linkIndices": [0, 2],
    "hideConfigIfMany": false,
    "hideConnectLinkForMarzban": false
  }
}
~~~

showIndexes is one based, native-order membership only when view has more than
three configs. linkIndices is zero based and configured-order for delivery;
missing positions fall back to the first config and an empty list uses first.
Delivery without native configs may use the visible subscription URL.
hideConfigIfMany hides delivery configs above ten; hideConnectLinkForMarzban
hides view configs. Small delivery uses second config (first if only one);
above three use the visible subscription, then first-config fallback.

Legacy policy may contain only show_configs, show_subscription, and qr.
Omitting qr preserves the legacy automatic rule. An explicit compatibility
exception keeps raw Marzban config-QR data, including duplicates and hidden
inline configs, subject to hard bounds. PasarGuard rejects this mode; it never
consumes Marzban positions or a synthesized vendor subscription path.

## Storage, validation and migration

The cohesive src/Subscription/ServerDisplaySettings.php module owns the resolver,
transactional editor and authorization. Existing setting stores one row per
logical server: type ACHBOT_SERVER_DISPLAY_<server_id>; value is a version 1
envelope with exact server_id, provider, updated_source, and display document.

No DDL or automatic data migration is involved. setting.type is not unique,
so duplicates fail closed. Unknown/deleted servers, provider mismatch,
malformed envelopes, unknown keys, wrong types and unsupported modes suppress
config, subscription and QR output. Administrators can explicitly repair one
malformed row through the editor; duplicate rows require reviewed operator
reconciliation. No automatic deletion or repair occurs.

Edits require a verified webhook, exact private sender/chat/frame, current unique
administrator identity, fresh source order, owned persisted step and unchanged
baseline. A named writer lock and transaction serialize writes and role checks.
Stale/replayed saves cannot overwrite another editor. Logs contain no settings,
URLs, credentials, raw exceptions, or customer values.

Before rollout, configure each desired logical server explicitly. Copy old
values deliberately into native or managed legacy mode. Runtime no longer reads
subscriptionPresentation, showIndexes, linkIndices, hideConfigIfMany,
hideConnectLinkForMarzban, or retired global subLinkState/configLinkState values.
No private file was read/edited. The current synthetic example removes obsolete
lines; frozen V0–V9 source/tests/schema remain unchanged.

Display settings do not filter the full subscription response. Native provider
Groups/Hosts must enforce actual access.

## Runtime and evidence

- Pure renderer: AchBot\Subscription\Presentation\present(...).
- Runtime facade: buildSubscriptionPresentation(provider, native, botState,
  options, context, serverId); sixth argument binds the logical server. The
  old options position remains compatible but cannot override stored settings.
- Delivery resolves the existing server_id fifth argument of
  buildLinksAndSendQr(...). Tuple positions/native persisted links remain.
- Paid/nonpayment CREATE helpers preflight settings before reserving/dispatching
  a remote CREATE. Later presentation changes never authorize replay.

Run the following source-only checks:

~~~sh
php tools/test_subscription_presentation.php
python3 tools/test_server_display_settings.py --run-disposable
python3 tools/test_post_v9_bank_buy.py --run-disposable
~~~

They cover pure selection and transport acknowledgement/cleanup; actual resolver,
facade, delivery, per-server isolation, authenticated admin frames, forged/stale
operations, role revocation, malformed/duplicate rows, retired global controls
and meaningful text-only delivery. The bank suite proves text-only account
recording and invalid-display preflight before remote creation/capacity changes.
Only disposable socket MariaDB, synthetic data and local spies are used.

Master owns aggregate call sites/inventory, exact-candidate PHP 8.1/8.4,
package/history gates, independent review and installed/operator acceptance.
