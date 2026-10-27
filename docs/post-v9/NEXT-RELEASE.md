# RC12 release scope and delivery contract

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
not imported. Only those six assignments are removed from the current synthetic
example; secrets remain per installation.

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
