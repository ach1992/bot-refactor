# Pending administrator, instance-content and Telegram copy follow-up

This source batch improves the existing bot without a new schema: four administrator tiers, settings-backed instance messages/destinations, clearer Persian Telegram text, receipt-review layout, user-info/profile updates and PasarGuard auxiliary links. A compatible RC15 installation retains its current schema, private configuration and financial/native evidence. Source acceptance does not authorize integration, publication or installation.

## Administrator scope and downgrade boundary

Owner is the administrator configured in private baseInfo and appears in the existing administrator list. That list adds or changes Manager, Operator and Support. Manager has ordinary admin functions except administrator management; Operator additionally excludes statistics, servers, plans/categories, bot settings and gateway/channel settings; Support has customer information, ban/unban, the order database editor, and human payment-receipt review/approval. This payment permission does not allow independent wallet adjustments/debt, foreign-ticket management or native/server administration. Menu visibility and actual requests agree. Payment review follows administrator-list placement and permits inspection to all four tiers; consequential owner-only reconciliation stays separate.

Secondary ticket copies require current operations authority (Owner, Manager or Operator). Payment-receipt copies and updates to their stored payment-message references use the narrow payments permission, which also includes Support. Receipt senders select that trusted payment context explicitly; the default fan-out remains operations-only. Secondary recipients come from the same current database staff membership used by the administrator list; private staff arrays do not select or authorize recipients. Demotion or revocation stops subsequent delivery while retaining historical message references. Confirmed new references append atomically; malformed historical metadata is preserved rather than repaired or discarded. Primary Owner delivery and financial evidence/replay remain separate and unchanged.

The existing boolean admin marker remains for compatibility and the assigned level uses existing settings. Existing staff without an explicit persisted level receive Operator scope. Valid persisted Manager, Operator and Support levels remain authoritative; Owner assigns Manager or changes levels through the existing administrator list. Only the primary `$admin` Owner identity belongs in private baseInfo among staff settings. Retained `$admins_list` or `$special_admins` variables are ignored, without importing them into the database or inferring Manager access. An old runtime does not understand new tier restrictions: after assigning tiers, do not roll back source blindly and assume the old code preserves them. Reconcile administrator access before any separately authorized downgrade.

For a separately authorized upgrade to this new runtime, verify the database staff list and assign the required levels from the Owner account, then remove the obsolete `$admins_list` and `$special_admins` assignments as part of that paired runtime/private-config rollout. Keep the primary `$admin` setting. Installed RC15 still uses the old fields: do not remove them from an RC15 installation alone. This source change neither reads nor edits any actual private file and does not authorize a rollout.

## Bot Settings and copy

Use Bot Settings to edit welcome/home messages and the shared purchase note. Unset content gives neutral setup guidance. Enter normal text or Telegram-formatted text; do not paste escaped HTML. Existing valid per-instance content and custom buttons are retained. Support, help-channel, mandatory-channel join and cooperation-panel destinations are configurable; unset optional destinations do not create fabricated external links. For a private channel, keep its numeric membership identifier and edit the join/invite URL separately. Clearing that URL restores public-channel derivation or the existing valid private-config joinLink fallback.

Destination values are encoded at each actual HTML, legacy Markdown or plain-text consumer. Stored settings and native keyboard URLs keep their original bytes. Current template substitution is a single pass so placeholder-like words inside a destination remain literal data.

Static interface copy remains in exactly two audience files, while instance marketing/message bodies are persisted configuration. Persian prose starts with Persian wording around Latin terms/numbers; raw copyable URLs, configs, code and parser tokens retain their bytes. The display-policy guide uses supported HTML/code presentation.

## Receipts, profiles and links

Administrator receipt captions retain useful customer/service facts, omit duplicated metadata, and place the pinned exact bank amount immediately after paid amount. Original payment/attempt evidence and guarded approval callbacks remain unchanged. User-info counters preserve incoming-payment and service-value meanings; agent percentage is next to agent status for permitted staff. A genuine private /start refreshes the name and a supplied nonempty username, retaining the previous username if absent. Configured auxiliary links are displayed for PasarGuard after purchase and in account details without modifying native subscription/config URIs.

## Previous RC15 shared-order release and migration boundary

[RC15](https://github.com/ach1992/bot-refactor/releases/tag/v0.10.0-rc.15) is published and PR92 is merged. It consolidated PasarGuard connection/native-operation evidence into existing tables and retired obsolete order columns. The rules below concern that release, not new SQL for this presentation/administration follow-up.

RC15 requires reviewed database preparation: [012_shared_provider_storage.sql](../../migrations/post-v9/012_shared_provider_storage.sql) transfers and verifies populated native evidence before removing the two provider tables; [013_remove_obsolete_order_columns.sql](../../migrations/post-v9/013_remove_obsolete_order_columns.sql) removes the two unused order columns. The runtime ZIP does not execute SQL. Read the [shared-storage migration and recovery contract](SHARED-PROVIDER-STORAGE.md) and [accepted workflow decisions](UNIFIED-SERVICE-MANAGEMENT.md#owner-simplification-follow-up-2026-10-28-revision-2) before rollout. Quiesce matching writers, verify a restorable full backup and use the paired source/schema. After012, reverting only runtime to RC14 is unsupported; recovery must restore the paired database/runtime or use an audited roll-forward. No tenant migration, release, or installation is implied by source acceptance.

The RC13/RC12 scope below is historical release context. Its no-new-SQL and011-only descriptions do not apply to RC15.

## Previous RC13 release scope and delivery contract

[RC13](https://github.com/ach1992/bot-refactor/releases/tag/v0.10.0-rc.13) removes the separate agent USDT rate. Every new manual USDT and hosted NOWPayments quote uses only `manualUsdtRateToman`, including agent accounts; old issued quotes retain their pinned rate and amount. The [Owner decision](https://github.com/ach1992/bot-refactor/issues/26#issuecomment-6453867569), [PR89](https://github.com/ach1992/bot-refactor/pull/89), exact PHP8.1/8.4 CI and independent APPROVE cover runtime source `a9175a2`. Publication documentation introduces no runtime changes. Read [RC12 → RC13](RC12-TO-RC13.md) or the [Persian guide](RC12-TO-RC13.fa.md). A compatible RC12 installation needs no new SQL; earlier migration and operator gates remain separate.

The inherited RC12 scope below remains available, except its optional agent-rate description is historical and superseded by RC13's [shared-rate contract](USDT-RATE-SETTINGS.md).

## Inherited RC12 scope

[RC12](https://github.com/ach1992/bot-refactor/releases/tag/v0.10.0-rc.12) is published as a source-only prerelease. Its runtime and manifest identify the reviewed source `ecd30ba9192ed374703118a6c131be8809cc7b06`, integrated through [PR #87](https://github.com/ach1992/bot-refactor/pull/87). This document describes that release scope; publication does not establish installation or operational acceptance. Published RC10/RC11 and frozen V0–V9 evidence remain immutable.
Owner scope is recorded in #26 comments
[6433839924](https://github.com/ach1992/bot-refactor/issues/26#issuecomment-6433839924),
[6434034586](https://github.com/ach1992/bot-refactor/issues/26#issuecomment-6434034586)
and [6434233761](https://github.com/ach1992/bot-refactor/issues/26#issuecomment-6434233761).
See the [RC11 preparation guide](RC11-TO-RC12.md) for package, settings and recovery
steps. No actual private configuration, tenant, database, webhook, Cron or money
is changed by this source work.

## Customer and administrator behavior

- The two original **Increase/Decrease wallet controls** remain. A Decrease that
  would create or deepen debt requires a locked before/after preview and separate
  explicit confirmation; preview moves no money. Customer notices report the
  committed operation, amount and resulting balance. Private administrator
  notices also report numeric target ID, before/after balances and tracking ID.
  Failed Telegram delivery does not reverse a committed movement.
- Home sends a new menu while preserving invoices, status results and payment
  evidence. References display the persisted customer's numeric Telegram ID.
  The attention inventory distinguishes unresolved claims/effects from historical
  successful `paid` orders. It shows subject-specific evidence gaps and never
  automatically approves, retries, refunds, reissues or repairs money.
- New volume/day add-on invoices pin **whole GiB/days** in existing `pays.volume`
  or `pays.day`, with the other axis zero and the original `pays.price` retained.
  Fulfillment uses these immutable issued units even after a catalogue change
  or deletion. Legacy zero/NULL/missing/inconsistent quantities do not prove the
  original entitlement: refuse new claim/debit/provider effects and retain
  paid/processing evidence for operator reconciliation. Do not autofill from
  today's SKU, rewrite old quotes, refund or replay automatically. See the
  [financial boundary](FINANCIAL-CONSOLIDATION.md#immutable-add-on-quantity-and-rc11-pending-invoice-boundary).

## Providers, display, rates and copy

RC12 uses genuine Marzban and native **PasarGuard 5.5.1** paths.
PasarGuard persists concrete panel incarnation and remote numeric account
identity, pins native Group IDs and uses native subscription/config responses.
Its Groups are distinct from customer payment cohorts and Marzban physical-panel
groups. Existing Marzban grouping remains unchanged. Retired X-UI variants stay
unsupported; User Templates, `on_hold` and native next-plan/reset scheduling are
not managed. Configure native Groups/Hosts and an absolute subscription URL
prefix before PasarGuard use; mutable upstream Group contents are not frozen by
a pinned ID. Details: [PasarGuard contract](PASARGUARD-CONTRACT.md).

In each logical server's **نمایش لینک و QR** settings, administrators use guided
config/subscription toggles, QR choices, limits and protocol/name selectors.
Marzban positional compatibility is an explicit managed mode; PasarGuard never
reuses those indices. Logical servers have independent policies even when they
share a backend. Configure the intended policy before rollout; an absent setting
uses documented native defaults, while malformed/duplicate/provider-mismatched
settings fail closed. No display setting changes actual subscription access.
See [presentation settings](SUBSCRIPTION-PRESENTATION.md).

Administrator gateway settings own normal/shared `manualUsdtRateToman` and
optional `usdtAgentRateToman` for both manual USDT and hosted NOWPayments.
Missing/cleared agent override uses the explicit normal rate; invalid explicit
override disables only that audience's quoting. TRX keeps its independent rate,
and older pinned attempts retain their original quote. Configure rates before
new use; no private/external/1:1 fallback exists. See [USDT settings](USDT-RATE-SETTINGS.md).

All bot/PHP payment-page copy lives in exactly **two editable files**:
`messages/fa/user.php` for customer/agent journeys and shared safe labels, and
`messages/fa/admin.php` for administrator management and diagnostics. Logical
topic sections and stable keys remain; `messages/loader.php` is code-only and
legacy settings keys remain a compatibility facade. Bot-owned prose/numeric
presentation uses ASCII digits, coordinated meaningful emoji and the format
supported by its actual Telegram or browser consumer. Keep established wording
where possible; never decorate parser/date/unit fragments as whole messages.
Use the [editing map](RC11-TO-RC12.md#message-editing-map) and
[catalog contract](../../messages/README.md), preserving keys, declared parameters,
escaping and legacy placeholders. Callback/state/SQL/provider identifiers and
diagnostic codes remain implementation contracts.

The actual private `baseInfo.php` remains untouched. The six obsolete globals
`hideConnectLinkForMarzban`, `showIndexes`, `hideConfigIfMany`, `linkIndices`,
`USDTPrice` and `USDTPriceagent` are ignored as runtime configuration sources,
not imported. Those six assignments were removed from the synthetic example in
the earlier release. The current staff follow-up additionally removes only
`admins_list` and `special_admins` from that example; primary Owner and per-installation
secrets remain. Actual private cleanup follows the paired-runtime boundary above.

## Schema and package boundaries

A compatible RC11 installation already has its reviewed financial journals.
Do not rerun **005**, rename journals or import RC10; preserve wallet journal
identity and both transfer legs. Audit actual shape/state rather than assuming
a previous upgrade ran.

[011_provider_identity.sql](../../migrations/post-v9/011_provider_identity.sql)
is additive and separately applied **only when PasarGuard is used**.
Existing Marzban-only operation does not require it. Missing, partial or
incompatible 011 blocks PasarGuard; it does not authorize repair or fallback.
Persistence separates the purchased entitlement from later mutation observations;
uncertain provider writes require reconciliation, never blind second dispatch.

[010 cleanup](CURRENT-RUNTIME-CLEANUP.md) is optional operator maintenance, not
an automatic DROP migration or readiness prerequisite. Its strict manifest and
guarded CLI allow only reviewed empty original table shapes with dependency,
retention and tenant identity checks plus explicit maintenance authorization.
Unknown/custom/nonempty state must be retained. Retired TRON/WeSwap source audits
also reject unknown files and active schedules; source search is not permission
to delete installed data.

The RC12 runtime contains **112 files**, including the loader and all
25 message topics. Verify every file against the trusted exact-release manifest;
a partial overlay without `messages/` is unusable. Source ZIPs contain no private
configuration and perform no SQL installation or maintenance.

## Validation, delivery and rollback

The Master binds exact stabilized source, PHP 8.1/8.4 current tests, detached
original-V9 gates, package/manifest and fresh author-separated review. Intermediate
worker checks and adapter probes are not aggregate or installed acceptance.
[Issue #81](https://github.com/ach1992/bot-refactor/issues/81) retains
separate provider/operator gates. The Owner-authorized RC12 source merge and
publication are complete; no production approval is implied here.

Bounded adapter evidence: client `30f8eab14b01421b0cab221a301d392673f696f8`
completed 11 native probe phases against unmodified upstream PasarGuard 5.5.1
commit `eba70d68eaa5a89e34ffebb47c8fd5ce054d0e43` on a task-owned loopback panel.
Native links and links_base64 each contained one configuration; DELETE204 was
followed by by-ID404. This does not certify the aggregate bot or customer
data-plane traffic. See #81 milestones
[6434115683](https://github.com/ach1992/bot-refactor/issues/81#issuecomment-6434115683)
and [6434178553](https://github.com/ach1992/bot-refactor/issues/81#issuecomment-6434178553).

Rollback must preserve committed money, payment/effect/replay journals, provider
identities and remote accounts. Older source may not understand PasarGuard or the
new message/settings dependencies; a source downgrade is not a financial undo.
Never erase journals or restore an old database blindly to make old code run.
Reconcile any uncertain operation against protected local/provider evidence and
use a separately reviewed recovery plan. Lower Telegram update IDs after a long
idle epoch and retained transfer cursors also require explicit reconciliation,
not erasing replay history.
