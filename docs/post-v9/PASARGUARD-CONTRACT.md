# PasarGuard Native API contract

PasarGuard is the shipped Native adapter. API behavior and actual account/
credential capabilities, not a pinned version string, govern admission.
Source owners: `src/Panel/PasarGuard.php`, the PasarGuard service adapter
modules, `ProviderRuntime.php` and `ProviderStorage.php`. The shared
[architecture](WALLET-FIRST-ARCHITECTURE.md) owns execution/funding.

## API and identity

- The client exposes create/read/modify/reset/revoke/delete/links/check/
  groups operations. Panel connection fields are id, instance_key, api_url
  and api_key; version is informational. Use authenticated by-ID/username
  lookup as appropriate, actual remote id, creation time and original
  creation marker. A foreign incarnation is never proof of success.
- Create persists the original username and `achbot:v1:<32hex>` marker before
  dispatch. Native data_limit uses integer bytes (zero means unlimited);
  expiry is a UTC timestamp or NULL (unlimited); groups are canonical
  unique positive IDs. Mutations use absolute field values, not repeated
  relative additions. Readbacks normalize actual quota, traffic, expiry,
  status, groups, subscription URL and proxy identity; don't manufacture
  success from an HTTP response alone.
- CREATE requires verified original account/entitlement. DELETE requires
  verified original identity and HTTP204/by-ID not-found confirmation;
  revoke must prove actual proxy credential rotation, not merely a changed
  URL. Links come from native subscription API endpoints; preserve bytes.
  Existing API delayed/uncertain write limits and local serialization are
  specified in the architecture, not a claim of remote exactly-once.
- Diagnostics preserve useful reason, HTTP code, operation and safe
  correlation; do not expose API credentials, raw provider bodies,
  private subscription tokens or untrusted response text.

## Configure the actual panel

Use the authorized existing API (no modifications to provider internals).
Configure its absolute HTTP(S) subscription URL origin/prefix before
admitting new accounts. A relative/token-only URL on CREATE is not proof
of a valid customer subscription and may leave the original effect held.
Do not fabricate a URL or create another account to repair it.

Scoped credentials need the actual user create/read/update/delete/reset_usage/
revoke_sub and Groups read/read_simple capabilities, the allowed Groups and
no required template. OWN scope can suffice for accounts created by the
same administrator; do not demand a global Owner API key. Native on-hold,
auto-delete, next-plan or periodic reset schedules incompatible with managed
mutations are not silently overwritten. Read-only preflight is not proof
of every write or subscription URL configuration.

Group IDs are per-plan (`server_plans.provider_group_ids`), not inherited
server defaults, and are separate from administrator physical Panel Groups.
CREATE requires a valid nonempty group set. A genuine existing imported
account can retain an authenticated empty set for appropriate server/global
offers. [Scoped offers](ADDON-SCOPES.md) and [plan changes](SHARED-SERVICE-TRANSFER.md)
define actual eligibility and final readback.

## Durable persistence, deletion and import

Use the consolidated current schema and [shared storage](SHARED-PROVIDER-STORAGE.md).
The operation/effect and Native mutation journals own immutable target,
identity and dispatch evidence; there is no bounded consumed-key replay
history as an admission policy. Commit the original intent before HTTP;
no network request inside its money transaction.

Scheduled expiry and explicit user/staff DELETE share the physical-account
lease and the original identity fence. A known remote deletion can finish
local state without a new DELETE. Explicit reconfirmation can recover only
the original held DELETE after authorized readback; unknown/foreign identity,
conflicting work or other unresolved reference authority do not authorize
blind replay. Capacity restoration is pinned to the original intent and
verified reservation provenance, not the recovering actor. Imported/adopted
accounts have no reserved capacity. Unknown provenance is held, not guessed.
See [service management](UNIFIED-SERVICE-MANAGEMENT.md) for lifecycle policy.

The customer-supplied subscription URL is an untrusted lookup hint. Verify
the actual supplied bearer at the trusted Native subscription info endpoint,
derived from authenticated Native evidence, without forwarding panel API
credentials or following arbitrary user-supplied origins. Match native id,
username and creation time; fresh token equality is not possession proof.
Created accounts retain original marker proof, adopted accounts retain
validated origin and incarnation. Separate customer references are supported
under the shared physical identity rules.

Whole-unit duration/quota validation, original paid quote, immutable target,
renewal reset and edit recovery are defined in the architecture. There is no
Marzban runtime, no legacy schema fallback and no new historical migration.

## Evidence

Reviewed upstream reference: [PasarGuard source](https://github.com/PasarGuard/panel/tree/eba70d68eaa5a89e34ffebb47c8fd5ce054d0e43).
It is not an enforced version gate. Use [CI validation](CI-VALIDATION.md)
for the actual loopback/native contract tests and [readiness preflight](PAYMENT-READINESS-PREFLIGHT.md)
before separately authorized installed acceptance.
