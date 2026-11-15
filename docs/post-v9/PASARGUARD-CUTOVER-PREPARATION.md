# Vivan / Hell PasarGuard bot cutover preparation (#183)

**Current status (2026-11-15):** The Hell/Vivan historical conversions were completed in
Production under [#183](https://github.com/ach1992/bot-refactor/issues/183).
Do not run the historical converter with --apply again on either tenant.
Six stale ACHBOT_SERVER_DISPLAY provider metadata rows were separately
repaired with exact-row backups and conditional per-tenant SQL transactions under
[#187](https://github.com/ach1992/bot-refactor/issues/187); GET-only Native and
real PHP renderer probes now pass for all nine migrated logical servers.
Source PR #184 is still pending integration/release review; the new source
diagnostic logging and future-cutover guard are not installed yet. Cron
scheduling and original tokenized subscription-URL continuity remain separate
checks.

**The material below is a historical preparation record**, not permission to
repeat the Production conversion. Preserve financial history, customer accounts,
Native provider state, Chili, and the existing deletion policy.

## Read-only 2026-11-14 baseline

| | Vivan (`botvivan`) | Hell (`bothell`) |
|---|---:|---:|
| Existing Marzban servers | 4: 2,3,12,13 | 5: 2,3,4,5,11 |
| Plans | 38 | 42 |
| Order references | 464 | 2,071 |
| Distinct (server_id, username) | 464 | 2,049 |
| Shared-reference excess | 0 | 22 |
| Planless records | 0 | 12 |
| Auto-renew on | 16 | 136 |

At this baseline, the **Marzban legacy plans** had NULL `server_plans.provider_group_ids` and their orders had no Native PasarGuard effect/remote ID. Old Marzban `server_info.panel_group_id` 61/55 are **not** native PasarGuard Group IDs. These are historical, changing production counts, not a frozen cutover snapshot. The existing native import procedure handles new zero-price/planless imports only; it must not be replayed for purchased historical orders.

## Existing PasarGuard test connections (read-only 2026-11-15 observation)

The Owner has already configured test PasarGuard connections in both live bot databases and directs the future cutover to **reuse the saved per-bot endpoint, API key and validation/authority settings** rather than inventing or separately hardcoding replacement credentials. These observations are not approval to rewrite server rows now.

| | Vivan (`botvivan`) | Hell (`bothell`) |
|---|---|---|
| Existing test PasarGuard server ID / connection ID | 14 / 14 | 12 / 12 |
| `provider_enabled` / server `state` | 1 / 0 (sales disabled) | 1 / 0 (sales disabled) |
| Stored informational PasarGuard version | `5.6.0` | `5.6.0` |
| Original Marzban logical server IDs to map later | 2, 3, 12, 13 | 2, 3, 4, 5, 11 |

The existing API URL and API-key values match between bots by protected, read-only fingerprint comparison, but the **local `provider_instance_key` values differ**. Preserve each bot's own current connection ID and instance key; these local identities are not interchangeable. A direct protected-key Native API probe was not completed, so **current credential validity, effective scope/Group authority and test-user remote readback are not yet verified**.

Hell also has a test plan `server_plans.id=82`, `provider_group_ids=[1]`, and one local test order `orders_list.id=37015` linked to `service_effects.id=41`. That effect has `source_kind=free_trial`, `provider_state=linked` and a stored native account ID/incarnation. This is **test evidence only**, not a paid legacy-order conversion, and Group `[1]` must **not** be copied indiscriminately to historic plans. The test plan row is active, but the test server's sales state is 0.

The production read-only aggregate at 2026-11-15 counted **458 Marzban-linked orders in Vivan** and **2,063 in Hell**, with 38/42 legacy plans, respectively. These counts change during live operation and do not authorize a cutover. One Vivan order still has an immutable original **Marzban import origin** from a different old logical server than its current plan (a legitimate historical transfer); this origin must be preserved unchanged during cutover. The runtime migration-specific Native identity validation accepts that original historical Marzban origin without reinterpreting it as a PasarGuard import, new sale or capacity reservation.

At the authorized later cutover, map every legacy logical server to the verified already-registered **tenant-local** PasarGuard connection and each legacy paid plan to its **actual Native Group set**. Do not change historical server/order/plan IDs, customer credentials, finances, or old subscription/config links. Recheck all data under a controlled source/target snapshot after the external panel migration.

## Accepted access Groups and server cohorts

Owner mapping (2026-11-15), stored as the small source-controlled operator declaration
[`PASARGUARD-COHORTS.json`](PASARGUARD-COHORTS.json):

| Logical bot | Legacy server ID / service | PasarGuard Group |
|---|---|---|
| Vivan | 2 Plus, 3 No-expiry / unlimited duration | `Plus` |
| Vivan | 12 Lite | `Lite` |
| Vivan | 13 Prime | `Prime` |
| Hell | 2 Plus, 3 Unlimited-duration | `Plus` |
| Hell | 4 Unlimited-volume, 5 Lite | `Lite` |
| Hell | 11 Prime | `Prime` |

Read-only PostgreSQL of the real PasarGuard panel found **Plus ID 1, Lite ID 3,
Prime ID 2**, all enabled at this checkpoint. These **are observations**,
not hardcoded provider identifiers. The later authorized connection's GET-only
Group catalog is authoritative; mapping generation requires matching enabled,
permitted Groups and stops if a name is missing, disabled or ambiguous.

**Access Group is NOT a quota/expiry policy.** Never turn a no-expiry account
into a timed account merely because it belongs to Plus; never turn an
unlimited-volume account into a finite-volume account merely because it belongs
to Lite. Preserve per-user Native expiry, consumed traffic, limits and status.
Legacy bot server and plan IDs do not change during conversion.

## Inputs required AFTER the panel migration

1. Owner completion signal and a fresh protected read-only validation of each **existing bot-local PasarGuard connection**, its real API/Group permissions and the migrated users under the intended `bot-admin` authority. The reported vendor version is informational; actual API behavior and permissions determine validity. Reuse the already-saved credentials through a protected operator workflow, never paste keys into GitHub/chat.
2. Mapping of each original logical server ID to a native panel alias and of each original plan ID to the native Group IDs. Preserve original plan IDs, prices, titles, quota/time types and server sale state. Native Groups must be real, eligible and nonempty for plans.
3. Verified identities and current native usage/limit/expiry/status/Groups, plus an independent old-config/proxy-credential/subscription-URL continuity test. Usernames alone do not prove exact Native ID and created-at incarnation.
4. Separate reconciliation of unfinished service-bound invoices/old callbacks, financial/effect/plan journals, and any uncertain work. Historical money, balances and original effect/quote evidence remain immutable.
5. A controlled cutover window with permission for the exact DB and writers, restorable full database backups and a paired-runtime recovery plan. No panel-side account edits or replayed native writes.

## Fast maintenance-window runbook (prepared tooling; do NOT execute in Production now)

The Owner will **start the separate panel-user migration** and **temporarily
turn off both bot webhook/interaction paths and all bot Cron workers**, preventing
new orders/renewals and new financial/provider effects. The bot cutover follows
only when the external panel migration is complete, clients' existing
configs/links work, and the Owner authorizes the **exact bot databases/actions**.

**Gate before every Production write:** confirm the actual Vivan/Hell runtime
source and post-V9 Schema support this new historical provenance. RC44 ZIP was
*staged only*, not installed, and **does not include this source-only
converter**. Review and publish a new runtime artifact through the repository
gates, then install it during a separately approved window, before re-enabling
customers. Do not install an unreviewed Draft PR or extract the RC44 ZIP as if it
contains this converter. Chili is unrelated and remains untouched.

### 1. Quiesce and protect state

1. Owner stops the two bots and all their Cron dispatchers. Verify **no
   active webhook, scheduled, payment-callback, administrator, or manual service
   writer** can race the snapshots/apply. Merely disabling new purchase buttons
   is insufficient if callback workers can still write. Observe the actual
   processes/leases with passive diagnostics; do not erase them.
2. Confirm physical user migration completed to PasarGuard under
   `bot-admin`, including original UUID/password/proxy/subscription URL
   continuity. Verify current traffic, quota, expiry, status and access Group
   for every cohort. Confirm the planned node/link behavior with the panel
   migration Owner; node replacement remains controlled by that other project.
3. Make **restorable**, time-bound backups of `botvivan` and `bothell`
   plus their private config/runtime release identities. Keep full data,
   including wallets, payments, service effects, origin/plan journals,
   customer links and old Marzban connection metadata. Check a restore in an
   isolated database before assuming a fallback works. Preserve the
   pre-migration backup until post-restart acceptance.
4. Reconcile outstanding incomplete invoices separately. Old pending
   requests do **not** automatically block unrelated future purchases, but
   uncertain provider effects and uncommitted plan journals remain blockers
   to the affected conversion. Do not expire/settle/replay historical invoices
   simply to make this check pass.

### 2. Capture private source, connection, and native access Groups

Use approved, **non-printing protected credentials** and private operator
directory `/secure-private` on the authorized execution host. `0600`
snapshot files contain private owner/native identities; the connections file
also contains a **real API Key**. Do not upload them to GitHub, chat, CI
artifacts, logs or general-purpose paste tools. The commands below are
templates for the authorized environment; do not assume a tool named
`mariadb` has the correct default socket/account until inspected.

```bash
# After both bots/Cron are confirmed quiescent, with authorized DB read access:
python3 tools/post_v9_pg_cutover_local_export.py --tenant vivan --output /secure-private/vivan-local.json
python3 tools/post_v9_pg_cutover_local_export.py --tenant hell  --output /secure-private/hell-local.json

# Reuse the already configured PasarGuard test connection in EACH bot DB:
python3 tools/post_v9_pg_cutover_connection_export.py --tenant vivan --server-id 14 --panel-alias main --output /secure-private/vivan-connections.json
python3 tools/post_v9_pg_cutover_connection_export.py --tenant hell  --server-id 12 --panel-alias main --output /secure-private/hell-connections.json

# GET only: /api/system, /api/admin, /api/groups with existing authorized credentials.
php tools/post_v9_pg_cutover_group_catalog.php --connections=/secure-private/vivan-connections.json --panel-alias=main --output=/secure-private/vivan-groups.json
php tools/post_v9_pg_cutover_group_catalog.php --connections=/secure-private/hell-connections.json  --panel-alias=main --output=/secure-private/hell-groups.json
```

The connection exporter runs a single MariaDB `READ ONLY` transaction and
never prints the secret/URL. It checks the **configured** connection ID,
instance key and enabled state, not a hardcoded provider version. The PasarGuard
Group catalog exporter verifies actual API authentication, user/Group permissions
and `require_template=false`, returning only currently eligible enabled
Groups by ID and name. It writes no Native changes and never prints keys.
**Live API/Group permission readback has not been performed yet**; saved SQL
configuration alone does not prove it.

### 3. Generate ALL plan mappings from the verified Groups

```bash
python3 tools/post_v9_pg_cutover_mapping.py --local /secure-private/vivan-local.json --cohorts docs/post-v9/PASARGUARD-COHORTS.json --group-catalog /secure-private/vivan-groups.json --panel-alias main --output /secure-private/vivan-map.json

python3 tools/post_v9_pg_cutover_mapping.py --local /secure-private/hell-local.json --cohorts docs/post-v9/PASARGUARD-COHORTS.json --group-catalog /secure-private/hell-groups.json --panel-alias main --output /secure-private/hell-map.json
```

Generation covers **every** old Marzban server and existing plan ID and
fails on unexpected/renamed servers or missing/ambiguous/disabled Group
labels; it does not infer IDs from the bot-local historical Marzban panel
groups 61/55. Existing separate PasarGuard test plans/orders are excluded
from legacy source export, and no Native Group is assigned to customers
via this operation.

### 4. GET-only account inventory and strict offline reconciliation

```bash
php tools/post_v9_pg_cutover_native_export.php --local=/secure-private/vivan-local.json --mapping=/secure-private/vivan-map.json --connections=/secure-private/vivan-connections.json --output=/secure-private/vivan-native.json

php tools/post_v9_pg_cutover_native_export.php --local=/secure-private/hell-local.json --mapping=/secure-private/hell-map.json --connections=/secure-private/hell-connections.json --output=/secure-private/hell-native.json

python3 tools/post_v9_pg_cutover_preflight.py --local /secure-private/vivan-local.json --native /secure-private/vivan-native.json --mapping /secure-private/vivan-map.json --private-map /secure-private/vivan-validated-map.json

python3 tools/post_v9_pg_cutover_preflight.py --local /secure-private/hell-local.json --native /secure-private/hell-native.json --mapping /secure-private/hell-map.json --private-map /secure-private/hell-validated-map.json
```

The Native exporter uses only authenticated GETs and records exact Native
`user.id` and `created_at`, Groups, current used traffic/limit, expiry and
status, without returning UUIDs or tokens to stdout. A Native **HTTP 404** for
an exact username is recorded privately in `native.not_found`; access denied,
transport failures and malformed user data still abort the export rather than
being treated as absence. The comparator validates
the entire local source, including Hell's shared physical users and 12
historical planless references (refresh moving counts). Strict defaults refuse every missing account, native collision, Group drift,
scheduling conflict, or unsettled Native/plan effect. The scoped post-panel
cutover exception options require **exact counts** for absence, current-group
preservation, and current-Native-scheduler preservation. Review these counts
from fresh read-only live data before running; an unanticipated identity,
changed count, unauthorized Group or incorrect 404 classification refuses
conversion. The native Group and scheduling settings are **never edited** by
the local SQL conversion, including when exceptions are accepted. Incomplete old financial invoices remain independently
visible for review. An offline `complete=true` is **NOT** approval for
production; `ready_for_production_cutover=false` is intentional. Confirm
actual subscription URL and original client config continuity separately.

### 5. Dry-run and guarded one-time local conversion

Before the dry-run, generate `--private-map` with three **verified** exception
counts, for example for the 2026-11-15 observed Hell cohort:

```bash
python3 tools/post_v9_pg_cutover_preflight.py --local /secure-private/hell-local.json --native /secure-private/hell-native.json --mapping /secure-private/hell-map.json --private-map /secure-private/hell-validated-map.json --approved-missing-account-count 97 --approved-preserved-group-count 4 --approved-preserved-schedule-count 4
```

These numbers are observations and must not be assumed for a later snapshot.
Vivan has a separate cohort and separate counts. A positive count is **not**
permission to fabricate, import, remove, change group/expiry or bypass an
unrelated service-effect uncertainty.

The new operator CLI
[`post_v9_pg_cutover_apply.php`](../../tools/post_v9_pg_cutover_apply.php)
uses the **exact same sealed four input files**. It now requires the sibling
tools/post_v9_pg_cutover_display.php file to be present beside the CLI.
A valid existing native server-display document has only its top-level
provider metadata migrated under the same transaction; all display policy keys
are preserved. Missing settings retain the native default. Malformed,
duplicate, wrong-provider or legacy-only display settings refuse the cutover
before any changes; the runtime validator remains fail-closed. It verifies that all
live local server/plan/order identities and Native mappings are unchanged,
that the preconfigured PasarGuard endpoint/instance is still the same, and
that uncertain service effects/plan changes are absent. The default mode
is one MariaDB **READ ONLY** transaction with no live writes.

Before running, have the operator establish `ACHBOT_PG_CUTOVER_DB_SOCKET`,
`ACHBOT_PG_CUTOVER_DB_USER` and
`ACHBOT_PG_CUTOVER_DB_PASSWORD` through the authorized secret mechanism,
without printing the password; set `ACHBOT_PG_CUTOVER_DB` to **exactly**
`botvivan` or `bothell` for the corresponding tenant. No database
connection credential is stored in the repository.

```bash
# Example for Hell; repeat with vivan paths/tenant/DB in its own transaction.
php tools/post_v9_pg_cutover_apply.php --tenant=hell --local=/secure-private/hell-local.json --native=/secure-private/hell-native.json --mapping=/secure-private/hell-map.json --validated-map=/secure-private/hell-validated-map.json --dry-run
```

Only when the dry-run is clean, both source and target backups are proven
restorable, live Native/old-link acceptance is complete, independent source
review and exact candidate CI pass, and the Owner **separately authorizes
Production SQL cutover for the exact two bot databases**, is the `--apply`
mode eligible. `--apply` creates migration-specific native provenance in
`service_effects` for each distinct physical account, links all existing
order references to it, sets per-plan Native Group IDs and reuses the
stored PasarGuard connection on each existing old logical server. It leaves all customer financial and historical order rows in place.
For explicitly verified absent historical Native identities only, it marks
the old local order as `status=0, autoRenew=off` to avoid advertising or
renewing an account which no longer exists. No charge is made and no record is
deleted. It does not overwrite order IDs, paid amounts, plan terms, customer
tokens, links, UUID/proxy credentials, wallet balances, payment history,
original effect/quote/plan journals, or Native traffic/expiry/groups/schedules. It does not dispatch
Native POST/PUT/DELETE, payment or Telegram requests. The database
transaction either commits all local changes with a one-time journal in
`setting` or rolls them back; exact repeated invocation verifies the journal
and does not replay the conversion.

The --apply flag **must never be re-run against the already converted Hell and Vivan production databases**.
Never use the ordinary zero-price `achbotProviderAdoptAccount` importer for
old paid orders. The new provenance records **a verified historical
association**, not a fictitious new purchase/effect. A wrong user/created-at,
changed source order, duplicate alias collision or changed mapped Group
is a stop/reconcile condition, not a reason to relax validation.

### 6. Acceptance before reopening the bots

1. Confirm current installed runtime artifact and schema match the
   independently reviewed cutover source and both SQL journals/readbacks.
   Check every original legacy order reference is linked, including multiple
   customers sharing the same physical subscription, and test-only PasarGuard
   orders are unchanged.
2. Verify representative existing account details, original old VLESS/VMess/
   Trojan configs, original subscription URLs and links, exact native ID,
   expiry/unlimited-time, used traffic, finite/unlimited quota, status,
   Groups and auto-renew consent without charging Wallet or mutating Native.
3. Keep all bot writers paused if identity/mapping or old-URL continuity
   cannot be verified. Do not simply restore a pre-cutover bot database to
   Marzban after customers/nodes have physically moved; that is not a
   safe standalone rollback. Reconcile external panel state before deciding
   rollback or roll-forward.
4. Only after verified stable read-only acceptance does the Owner re-enable
   bot webhooks and Cron. Perform **separately authorized** representative
   first new purchase, renew, add-on and admin plan change, checking one
   physical effect and exactly-once money mutation. Observe failures and
   preserve usable logs/correlation evidence.
5. Leave original deletion logic untouched (actual grace 10 days; customer
   message intentionally says 3). Keep Issue #183 open until installed
   Vivan/Hell acceptance is real.

## Remaining independent and Production gates

- The operational SQL migrations completed on 2026-11-15 under a specific
  Owner waiver for that action only. PR #184 remains Draft; source integration,
  release and deployment remain separately gated and need exact-candidate CI.
- The six post-cutover display metadata repairs under #187 were backed up
  privately, applied transactionally, and checked through live PHP/Native GET.
  No new customer/proxy/financial mutation was part of this repair.
- Still outstanding: tokenized **original** customer subscription-URL
  continuity, Cron scheduling/flow validation and an Owner decision before
  re-enabling, and authorized end-to-end Telegram purchase/renewal/admin tests.
- The source diagnostic and pre-cutover guard are not yet installed in the bots;
  installed-runtime verification is needed after authorized deployment.
- Preserve the original 10-day actual / 3-day user-facing deletion contract.

## Synthetic validation only

```bash
python3 tools/test_post_v9_pg_cutover_preflight.py
python3 tools/test_post_v9_pg_cutover_native.py
python3 tools/test_post_v9_pg_cutover_mapping.py
python3 tools/test_post_v9_pg_cutover_connection.py
python3 tools/test_post_v9_pg_cutover_groups.py
python3 tools/test_post_v9_pg_cutover_source_mariadb.py
python3 tools/test_post_v9_pg_cutover_apply_mariadb.py
python3 tools/test_post_v9_pg_cutover_runtime.py
php -l tools/post_v9_pg_cutover_native_export.php
php -l tools/post_v9_pg_cutover_group_catalog.php
php -l tools/post_v9_pg_cutover_apply.php
php -l tools/post_v9_pg_cutover_display.php
php -l src/Panel/ProviderStorage.php
```

All native test traffic uses synthetic loopback HTTP; tests assert GET-only and zero native writes. See [#183](https://github.com/ach1992/bot-refactor/issues/183), [native contract](PASARGUARD-CONTRACT.md), [shared provider storage](SHARED-PROVIDER-STORAGE.md).
