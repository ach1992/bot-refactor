# Scoped day and volume extension offers (Issue #107)

Current Wallet-first contract. The scoped offer columns and unique indexes are mandatory parts of the consolidated current schema. Use [fresh installation](FRESH-INSTALL.md) and [current readiness](PAYMENT-READINESS-PREFLIGHT.md). Historical schema transitions are not runtime prerequisites.

## Eligibility and price precedence

Existing increase_day and increase_plan row IDs, quantities and prices are retained.
Current scope metadata has three meanings:

| Scope | Data | Eligibility |
| --- | --- | --- |
| Global | server_id NULL, group_ids NULL | All supported servers |
| Server | logical server ID, group_ids NULL | Exact supported logical server (currently PasarGuard) |
| PasarGuard Group-set | logical PasarGuard server ID plus canonical Group ID JSON | Exact native Group-set on the selected native account |

The editable scope is only server_id and group_ids. MariaDB automatically
maintains the generated scope_hash index key from these fields (compacting
JSON, not sorting arrays); app-written group sets are canonical. A direct
SQL edit never needs to update a hash. The runtime normalizes valid
Group-set JSON to sorted distinct positive integer IDs and derives the
semantic G / S:<server-id> / P:<server-id>:<sorted-comma-separated-group-ids>
SHA-256 for quote evidence, without trusting the index key as another
business value. The unique index rejects identical compact scope rows.
Direct SQL can produce differently ordered but semantically duplicate
Group-sets; the runtime detects competing winning offers per quantity. Do not confuse native PasarGuard groups with physical
panel_group_id. For a given extension kind and integer unit count,
priority is exact native PasarGuard Group-set > server > Global.
Multiple native Groups are one exact set, never a cheapest matching
individual Group. Imported PasarGuard accounts with an authenticated,
unchanged empty native Group-set ([]) can use eligible Server/Global
offers, but cannot match a Group-specific offer or invent Group membership.
Such a newly issued scoped quote pins native_groups: []; do not treat a historical NULL snapshot as a current issued quote.
CREATE/plan defaults still require nonempty native Groups. A pinned
existing-account snapshot may preserve [] only with valid account/identity
references and must pass the usual current provider-authority checks both
before payment and again after the durable effect claim, before PUT.
Show at most one offer per quantity. An ambiguous winning scope or malformed
potentially applicable scope suppresses only that quantity, with a private
diagnostic. Other quantities and unrelated server scopes stay usable. A
higher-specificity valid scope can supersede lower-rank duplicate offers.
No malformed scope is silently treated as Global.

## Administrator workflow

In dayPlanSettings or volumePlanSettings create an offer and choose Global,
Server, or PasarGuard Groups on a Server. Select a valid active logical
server if needed. PasarGuard native group IDs are entered as a comma
separated set, for example 3,7; the server must be PasarGuard and the
Group lookup must verify active/allowed Groups. Enter units-price,
for example 10-25000. Existing offers display scope and can change
scope; current edit/delete and administrator permissions remain.
The day and volume administrator lists always show **every offer of the
selected kind** in one view: Global, logical Server and native PasarGuard
Group-set offers appear together. Each row's scope label is the entry to
edit that precise offer's scope; price, quantity and delete actions remain
available on the same row with revision-bound callbacks. There is **no
scope-filter row**. Retired filter callbacks are not a supported journey.
The customer sees only the applicable winning offer per quantity for the
owned subscription; it is not this unfiltered administrator catalogue.
Customer day/volume selection uses a single-column keyboard with concise
Persian quantity/price labels and a Back action to the owned order details.
Every scoped-editor session now carries a fresh, server-stored 16-hex nonce
in its Scope and Server picker callbacks. A newer Telegram update ID alone
never authorizes an old keyboard from another editing session. Every current offer
edit/delete/scope button also contains a 20-hex
content revision for the exact displayed kind, ID, scope, quantity and
price. The existing authenticated SQL transaction locks and checks actual
row values before any write; deleted or changed rows are rejected, not
silently reinterpreted. Fresh lists use current edited data; a no-op
confirmed save is successful. A malformed scope remains editable using the
existing scope picker, without hiding the other offers. Unbound historical callbacks are refused; no pre-015 runtime fallback remains. All callback payloads stay inside Telegram's 64-byte limit.

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
historical server defaults are insufficient. The final native PasarGuard PUT for a scoped invoice additionally
rechecks the entire native Group-set and current enabled/credential-allowed
Group authority after the durable account mutation claim. The adapter does
this after its own last identity readback and before the first write; a
changed Group or unavailable authority holds the already-claimed operation
for reconciliation without PUT, repricing, refund or replay. This cannot
make provider-credential revocation atomic with a network dispatch; it
minimizes the application-side check-to-write window.

Disallowed native Group
membership fails closed even when a Server/Global price is available,
rather than accepting money for an unfulfillable fallback. The trusted
current Group eligibility result is reused during one catalog resolution,
not inferred from arbitrary callback-supplied IDs. Stale callbacks, removed Groups, changed
server/provider identity, deleted offers or altered scopes fail closed
for a refreshed quote or reconciliation, never automatic repricing.
An already-issued quote must never silently change amount because of
admin edits. New draft issuance fails when the selected offer price
changed while the draft was being created.

Historical invoices without a current immutable quote retain evidence of the original amount and units but are not executable in the replacement runtime. Existing-instance reconciliation is separately authorized.
Existing idempotency journals, wallet protections and gateway records
remain authoritative. Never retry an uncertain external effect blindly.

### Imported planless non-API financial completion

orders_list.fileid=0 and pays.plan_id=0 can be valid for a real
imported existing PasarGuard subscription. A successful native
PUT/readback alone does not authorize the local payment/order/audit
transition: the shared locked expiry finisher must preserve its complete
original-vs-current order CAS, verify the immutable external_import
origin, and rebind the exact current PasarGuard physical account identity,
native incarnation, endpoint, logical server, owner and remark under the
finalization SQL transaction. Only this verified existing-account case may
retain a zero plan ID. Normal plan-backed orders keep their strict checks;
zero/invalid references without verified import provenance fail closed.

Current funded add-on execution is shared across payment methods and uses
the service operation, Wallet and Native evidence described in
[the architecture](WALLET-FIRST-ARCHITECTURE.md). A failed local finalization
keeps the original evidence and recovery boundary; never invent a refund,
reprice, or blindly replay a remote mutation.

## Installation and verification

The mandatory current schema contains both generated scope keys and scoped
unique indexes. Partial or incompatible schema is rejected. No optional
migration or old schema mode is supported. Fresh-install construction
includes 057_derived_addon_scope.sql; it is not run from the bot. For an
already-current v0.12.1 installation, separately authorize the controlled
schema transition and rehearse it on a restore-tested backup before
activation. Audit both tables for actual business scopes, invalid/duplicate
quantities and affected original quotes, then convert only the redundant
derived key, preserving IDs, editable fields and all original financial
snapshots. Do not replay the historical migration chain. Record any changed
scope interpretation (including old stale hash evidence) before rollout;
a generated index collision must be reconciled explicitly, not by deleting
rows or guessing prices. Retain the protected original backup. The current CI suites include wallet_first_funding,
wallet_first_service_execution, wallet_first_fresh_schema and
current_payment_readiness; see [CI validation](CI-VALIDATION.md). Source
acceptance does not authorize live installation, data transfer, or provider
operations. Historical SQL and test evidence are available in Git history.
