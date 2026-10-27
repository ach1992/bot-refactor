# PasarGuard native contract (source candidate)

Issue #81, NEXT-PG-CLIENT-2 / #26 revision2. Supported source pin: 5.5.1,
eba70d68eaa5a89e34ffebb47c8fd5ce054d0e43. Actual target source/version verification:
https://github.com/ach1992/bot-refactor/issues/81#issuecomment-6427352542 .
This contract does not establish installed/live acceptance.

## API

Global functions in src/Panel/PasarGuard.php, PHP >=8.1:

- achbotPgCreate(array $panel,array $request):array
- achbotPgRead(array $panel,array $identity):array
- achbotPgModify(array $panel,array $identity,array $absoluteChanges):array
- achbotPgReset(array $panel,array $identity):array
- achbotPgRevoke(array $panel,array $identity):array
- achbotPgDelete(array $panel,array $identity):array
- achbotPgLinks(array $panel,array $identity,string $format='links'):array
- achbotPgCheck(array $panel):array
- achbotPgGroups(array $panel):array

Panel keys: id,instance_key,api_url,api_key,version.
Identity keys: remote_user_id,remote_created_at,operation_marker.
Create request: username,data_limit(int bytes),expire(?int UTC epoch),
group_ids(sorted unique positive ints),operation_marker(achbot:v1:<32hex>).
Modify accepts only absolute data_limit,expire,status(active|disabled),group_ids.

Result keys: success(bool),uncertain(bool),reason(string),http(int),user?(array),
links?(list<string>),subscription_url?(string). Never return raw provider bodies,
keys, cURL texts or URLs/tokens in diagnostics.
Normalized user: id,username,created_at(UTC RFC3339 microseconds),note,data_limit
(int bytes;0 unlimited),expire(?int UTC epoch),status,group_ids,used_traffic,
subscription_url(native absolute),proxy_settings.
Native limited/expired/disabled/active statuses remain distinct.

By-ID reads and writes fence created_at + original creation marker.
CREATE is HTTP201, DELETE is HTTP204 followed by by-ID404.
No retry on failed/malformed write, no compensation from uncertain results.
The integration owns durable claims/reservations and operator reconciliation.
Revoke requires changed proxy identity; URL inequality alone is insufficient.
Links use native GET /api/user/{id}/subscription/links or links_base64;
presentation never constructs protocol/SNI/transports.

## Persistence

Migration011 is additive SOURCE ONLY. Missing011 must leave existing Marzban
usable but fail closed for PasarGuard setup/financial dispatch.

- provider_panels: physical connection; immutable random instance_key, private
  api_key. Logical profiles reference this shared identity.
- server_info.provider_panel_id + provider_group_ids: default native Groups.
- server_plans.provider_group_ids: NULL inherits, explicit list replaces.
- pays.provider_snapshot and service_effects.provider_snapshot: nullable JSON
  {provider,panel_id,instance_key,endpoint_sha256,group_ids}. Resolve at quote,
  copy paid snapshot before HTTP; never overwrite from mutable defaults.
- provider_accounts: effect_id unique reserved BEFORE HTTP, order_id nullable
  until linking; remote ID/incarnation NULL until verified. Stores endpoint
  digest, original marker, username and entitlement_snapshot. No credentials
  in journals; no deletion cascade to orders/effects; unknown cannot reissue.

Global storage functions in src/Panel/ProviderStorage.php:

- achbotProviderPanel(mysqli $db,int $panelId):array
- achbotProviderSnapshot(mysqli $db,int $serverId,?int $planId=null):array
- achbotProviderPanelForSnapshot(mysqli $db,array $snapshot):array
- achbotProviderAccountByOrder(mysqli $db,int $orderId):?array
- achbotProviderAccountByEffect(mysqli $db,int $effectId):?array
- achbotProviderReserveAccount(mysqli $db,int $effectId,array $snapshot,string $username,string $marker,array $entitlement):array
- achbotProviderVerifyAccount(mysqli $db,int $effectId,array $user):void
- achbotProviderLinkAccount(mysqli $db,int $effectId,int $orderId):void

Storage errors throw private reason-only RuntimeException. Reservation uses the
caller's SQL transaction; caller MUST commit it before HTTP. It is idempotent
only for the exact same existing reservation; returned state never authorizes a
second dispatch. The returned row includes newly_reserved=true only on INSERT;
exact existing reservations return newly_reserved=false. Reserve requires an
open caller transaction and validates the shared effect operation/username,
endpoint and pinned snapshot. Trials use genuine nonpaid shared effect rows. Shared service_effects owns financial/replay state. Pure
existing Marzban does not load/use provider tables.
Source-only revision2 adds nullable provider_accounts.mutation_key (VARCHAR100)
and mutation_snapshot (LONGTEXT) for durable action/absolute-intent fencing.
The runtime commits linked -> mutating with intent before HTTP; unconfirmed
writes remain mutating/review and cannot authorize replay. The purchased
entitlement_snapshot remains intact. No production migration is implied.
Account extension preserves existing purchased Groups. Explicit service switch
updates remote Groups and verifies before local routing/snapshot completion.
Native Group contents are mutable upstream; IDs do not freeze Hosts/inbounds.
No User Template, on_hold, next_plan or periodic reset scheduling integration.

## Primary source facts

Pinned upstream app/models/user.py blob84d559893cce9b6ebe8f102c58f4ae15841291dc,
app/routers/user.py d46b0382acac5c612dbdffca90d48c7a33a3ad57,
app/models/admin_role.py ba143e83b1b228f0bd71f5ee8154e462e07d559f,
app/routers/authentication.py 7659f8363ff408943dfc7692747a387e85fb8c23,
app/models/group.py efcdf4f1ca5b056f39ee9e075f27fffb2de2d9e5.
The installed OpenAPI is deliberately disabled; exact tagged/actual model and
route matches establish this contract, not an invented OpenAPI fixture.

Scoped API key needs users create/read/update/delete/reset_usage/revoke_sub,
Groups read/read_simple, require_template=false and permitted native Groups.
OWN scope suffices for same-admin-created accounts. Do not require owner key.

## Native panel configuration prerequisite

Configure the native subscription URL prefix/origin before admission. PasarGuard
5.5.1 may return a relative/token-only subscription_url when its subscription
url_prefix is unset; this adapter deliberately requires a native absolute HTTP(S)
URL and holds a CREATE with invalid link readback as uncertain. Never manufacture
a subscription URL or repeat that CREATE. Reconcile the exact owned marker and
incarnation. Read-only system/admin/Groups preflight does not certify subscription
configuration; it intentionally does not require wider settings permissions.
Native auto-delete, on-hold timing, next-plan and periodic reset settings must be
absent for AChBot-managed accounts; externally configured scheduling fails closed.

Reservation reads lock the current account row after serializing on its effect.
MariaDB strict snapshot isolation may instead reject a competing transaction
whose earlier read view predates the reservation; that SQL rejection is private,
rolls back local work and never grants remote dispatch. Runtime does not alter
SQL isolation settings or automatically retry an uncertain external write.


## Mutation replay-history integrity

A linked account may start with empty history only when both
`mutation_key` and `mutation_snapshot` are SQL NULL. After the first
mutation, the snapshot must be valid JSON version 1, its key must equal
`mutation_key`, and `consumed_keys` must be a nonempty JSON list of unique,
valid 1–100-character operation keys containing the last key. Partial NULL,
malformed JSON, wrong types, missing fields, duplicate keys or inconsistent
last-key evidence refuse the next mutation before provider GET or write;
the runtime never substitutes an empty history.

The retained list is bounded at **4096 consumed sources**. An account at that
bound fails closed for new mutations and requires operator review. History
is never automatically pruned, reset or reconstructed to authorize a retry.
This does not claim protection against a fully privileged operator manually
rewriting the database. Existing-account operations preserve current remote
Groups; initial CREATE still uses its pinned quote Groups.


## Whole-unit service edits

The existing accounting contract supports whole days and whole GiB only.
`increase_plan.volume` is FLOAT, but `pays.volume` and
`increase_order.volume` are INT; the current Marzban adapter explicitly
refuses fractional units rather than silently truncating them. The same
contract applies to native service edits. No new fractional rounding,
precision migration or accounting policy is introduced here.

Pure unit/type/finiteness/bounds validation happens before a native account
claim or provider GET. Current quota and expiry are observed and calculated
only under the durable account claim. Explicit zero quota replacement remains
unlimited; adding volume to current unlimited service refuses before any
write and retains the review fence. Addition uses checked integer arithmetic.

Unsupported fractional add-on rows are hidden from new selections and refused
before new quote/payment/wallet claims. Manual and scheduled preflight recheck
raw units before casts; existing invoice, money and service evidence is not
rewritten or automatically refunded. Existing addon invoices retain their
historical ID-based unit reference; this change does not invent original-unit
evidence or perform a compatibility backfill when a mutable plan later changes.


### Immutable add-on quantity and RC11 pending-invoice boundary

New volume add-on drafts pin their original whole GiB in `pays.volume`
with `day=0`; new day add-on drafts pin whole days in `pays.day` with
`volume=0`. These existing INT fields require no DDL. All manual, wallet,
hosted and scheduled effects use the original pinned quantity and invoice
price, not the mutable catalogue row. Later SKU quantity/price changes or
deletion cannot replace the entitlement of an issued quote.

Legacy RC11 and earlier add-on rows with zero, NULL, missing or inconsistent
quantity do not prove an original entitlement. They fail closed before a new
claim, wallet debit or provider effect; already paid/claimed rows remain under
review. There is no automatic backfill, invoice rewrite, refund or replay.
Operators must reconcile original evidence separately rather than reviving a
legacy invoice by changing its SKU. New supported quotes can be issued only
through the guarded current quote path.
