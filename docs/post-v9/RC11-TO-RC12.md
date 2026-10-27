# RC11 → RC12 preparation guide

[راهنمای فارسی](RC11-TO-RC12.fa.md)

This guide describes the **planned RC12 source-only target** for a compatible
RC11 installation. It is preparation, not evidence that RC12 is published or
that a tenant was upgraded. Choose artifacts and their exact source identity
from [GitHub Releases](https://github.com/ach1992/bot-refactor/releases) only after
the required review and separate Owner publication/installation decisions.
[RC11](https://github.com/ach1992/bot-refactor/releases/tag/v0.10.0-rc.11) remains
immutable published evidence. RC9 installations must first follow their
[RC9 → RC11 contract](RC9-TO-RC11.md); this is not an RC10 import procedure.

[Issue #81](https://github.com/ach1992/bot-refactor/issues/81) retains the Owner
merge/release and separate installed provider/operator acceptance gates.
Source tests, a source ZIP or a successful adapter probe do not grant deployment,
SQL, provider, wallet, webhook or Cron authority.

## What the source candidate changes

| Area | Result and boundary |
| --- | --- |
| Providers | Native PasarGuard 5.5.1 joins Marzban, using concrete panel/account identities and native Group IDs. It does not reinterpret Marzban identities or reenable retired panels. |
| Links and QR | Each logical server has its own guided administrator display settings; native provider configs/subscriptions are used. Display selection does not enforce upstream access. |
| Exchange rates | Normal/shared and optional agent USDT rates live in bot gateway settings, for manual USDT and hosted NOWPayments. TRX has an independent rate. |
| Messages | One loader and 25 topic catalogs hold bot/PHP payment-page copy. Existing legacy facade keys/placeholders remain compatible. |
| Wallet | The original Increase/Decrease controls remain. Debt-producing Decrease requires an explicit locked preview/confirmation; notices use committed facts. |
| Payment evidence | Home adds a menu while preserving invoices/status/evidence. Attention views distinguish actual unresolved claims from historical paid orders and state evidence gaps. |
| Add-ons | New quotes pin whole GiB/days and price in existing `pays` fields. Later catalogue edits/deletion cannot reprice or replace issued units. |

Details and evidence limits are in [NEXT-RELEASE](NEXT-RELEASE.md). No new
automatic chain settlement, financial repair, refund or uncertain-effect retry
is introduced.

## Inventory, schema and source preparation

1. Identify the exact tenant, installed source, database, outstanding payments,
   claimed/uncertain effects, remote accounts and active schedules. Take
   access-controlled source/config/database backups and rehearse restore in an
   isolated environment. Preserve the real private `baseInfo.php`.
2. Check the exact existing RC11 financial schema and data. Keep
   `wallet_adjustments`, both transfer legs, source/replay cursors, payment
   attempts/transactions, effect and referral journals. **Do not rerun 005,
   rename journals, import RC10, or reconstruct historical movements.** Missing
   or incompatible earlier sidecars require their own audited upgrade path;
   source ZIP extraction never applies SQL.
3. Select the intended provider and review the conditional sidecars below.
   Apply nothing merely because a new sidecar is present in release assets.
4. Stage the entire exact runtime and verify its trusted manifest. The candidate
   has **135 runtime files**, including `messages/loader.php` and **all 25**
   `messages/fa/*.php` topics. A changed-file-only overlay or omitted
   `messages/` directory is not a valid runtime. GitHub's automatic Source
   code archive is not the verified runtime ZIP.
5. Configure intended per-server display policies and bot FX in the
   administrator UI before enabling new journeys. Follow separately approved
   source/schema/schedule cutover and installed acceptance; do not use a setup
   check to run financial Cron or create real customer accounts.

| Sidecar | RC11 preparation rule |
| --- | --- |
| Existing 001 / 003–009 contracts | Verify exact shapes and preserve data. Compatible existing journals are not recreated; 005 is not an RC11 upgrade action. |
| [011_provider_identity.sql](../../migrations/post-v9/011_provider_identity.sql) | Additive, separate and required only for PasarGuard setup/use. Existing Marzban-only operation does not require it. Missing/partial/incompatible state blocks PasarGuard and requires audit, not automatic repair. |
| [010 cleanup manifest and tool](CURRENT-RUNTIME-CLEANUP.md) | Optional, independently authorized maintenance only. Never automatic DROP, a startup dependency or a prerequisite for 011. |

011 stores panel incarnation, quote-pinned Group IDs, stable remote numeric
account identity and durable CREATE/mutation intent. Purchased entitlement
remains distinct from later mutation observations. Do not remove or repurpose
that identity to enable older source. See the [native contract](PASARGUARD-CONTRACT.md).

010's guarded `tools/cleanup_post_v9.php` defaults to inspection. Applying it
requires exact original empty shapes, dependency visibility, tenant identity,
retention/external-consumer disposition, an exclusive maintenance window and a
new protected restore-DDL file. Only `admins`, `black_list` and `servers`
are candidates. Nonempty, custom, unknown or dependent state is refused and
retained. Follow the cleanup guide; do not issue unconditional DROP statements.

After verifying the artifact's origin/checksums independently, use the matching
verification tool with its actual paths:

~~~sh
python3 tools/package_post_v9.py verify \
  --zip /path/to/verified-runtime.zip \
  --manifest /path/to/verified-runtime.zip.manifest.json
~~~

The [read-only payment readiness preflight](PAYMENT-READINESS-PREFLIGHT.md)
can inspect selected runtime/schema/settings without provider or money effects.
A matching untrusted manifest is not provenance, and readiness does not certify
real merchant credentials, customer eligibility or installed settlement.

## Configure providers, display and rates deliberately

For **PasarGuard 5.5.1**, use the native administrator setup and Group choices
with a scoped key and the permissions in the [provider contract](PASARGUARD-CONTRACT.md).
Configure valid native Groups/Hosts and an absolute subscription URL prefix
before account admission. Native Group IDs are not AChBot customer payment
cohorts or Marzban physical-panel groups; upstream Group contents can change.
User Templates, `on_hold` and native next-plan/periodic reset scheduling are
not managed. An ambiguous write is held for reconciliation; never repeat CREATE
or edit because its response was lost. Adapter/probe evidence is not installed
bot or customer data-plane acceptance.

For **each logical server**, open **server settings → نمایش لینک و QR**.
Guided controls cover config/subscription visibility, QR, limits and protocol/name
selection, with explicit managed positional compatibility for Marzban only.
Logical servers sharing one backend can have different display policies.
Missing settings use documented native defaults; deliberately configure the
intended policy before rollout rather than assuming old selection was imported.
Invalid/duplicate/provider-mismatched documents fail closed. See
[subscription presentation](SUBSCRIPTION-PRESENTATION.md).

In **administrator gateway settings**, configure:

| Bot setting | Use |
| --- | --- |
| `manualUsdtRateToman` | Explicit normal USD/Toman rate for manual USDT and hosted NOWPayments. |
| `usdtAgentRateToman` | Optional override for actual agent accounts, for both uses. Missing or `/clear` reuses the explicit normal rate; an invalid explicit override disables only agent quoting. |
| `manualTrxRateToman` | Independent native TRX/Toman rate; no USDT-derived default. |

A missing/invalid normal rate disables normal USDT/NOWPayments quoting; a valid
explicit agent override can still serve agents. There is no private-price,
public-price-service or 1:1 fallback. Existing pinned attempts keep their
original quote/network/recipient/rate after edits or clear. Other payment
methods remain independently configured. See [USDT settings](USDT-RATE-SETTINGS.md).

The six old private globals `hideConnectLinkForMarzban`, `showIndexes`,
`hideConfigIfMany`, `linkIndices`, `USDTPrice` and `USDTPriceagent`
are ignored, not imported. The current synthetic example removes those six
assignments. This upgrade does not read, overwrite or remove them from the
actual private file; keep per-install secrets and access controls intact.

## Pending invoices, debt and reconciliation

New volume add-on invoices pin whole GiB in `pays.volume` with `day=0`;
new day add-ons pin whole days in `pays.day` with `volume=0`. All final
wallet/manual/hosted/scheduled callers use the pinned quantity and original
`pays.price`, even after SKU changes/deletion. No new DDL is needed.

RC11 and older zero/NULL/missing/inconsistent quantities do not prove the
original entitlement. They cannot obtain a new claim, wallet debit or provider
effect. Already paid/claimed/processing records retain evidence for operator
review. Do not fill them from today's catalogue, rewrite price/quantity,
retry the effect, refund, or manufacture success automatically. Reconcile the
original invoice, payment and provider facts under separately authorized action;
any new supported quote must use the guarded current quote path.

Wallet Decrease uses a **positive amount and reason**. If its result would create
or deepen debt, the locked before/after preview requires separate explicit
private confirmation; a changed balance invalidates that consent. Customer
notices state the committed operation, amount and resulting balance; administrator
notices add target numeric ID, before/after balances and tracking identity.
Notification failure cannot undo money or authorize another adjustment.
See the [wallet contract](ADMIN-WALLET-ADJUSTMENTS.md).

Home preserves the originating invoice/status/evidence message and sends a new
menu. Attention inventory is read-only evidence review, not a list of every
historical paid order or proof of universal successful fulfillment. Do not infer
a particular old top-up from today's wallet balance or an add-on from today's
account quota/expiry. Missing correlated evidence remains an explicit gap.

## Message editing map

Change wording in the matching `messages/fa/` file, keeping the stable key,
declared parameter names, HTML/escaping assumptions and legacy placeholders.
Do not move callback/state/SQL/provider identifiers or diagnostic codes into
translations. `settings/values.php` remains a compatibility facade.
[Catalog authoring and validation](../../messages/README.md) describes one-pass
substitution and the source/byte audit.

| Topic file | Copy area |
| --- | --- |
| `admin.php` | Administrator actions and management. |
| `admin_settings.php` | General administrator/server configuration. |
| `agent.php` | Agent journeys and management. |
| `backup.php` | Backup outcomes and notices. |
| `bot.php` | Main bot navigation and shared routes. |
| `broadcast.php` | Broadcast administration. |
| `date.php` | Date/calendar display labels. |
| `gateway.php` | Hosted gateway initiation/return. |
| `jobs.php` | Scheduled-job messages. |
| `labels.php` | Shared display labels. |
| `legacy_buttons.php` | Existing `$buttonValues` compatibility text. |
| `legacy_messages.php` | Existing `$mainValues` compatibility text. |
| `manual_payment.php` | Bank, gift-card and manual crypto receipts. |
| `notifications.php` | Account/customer notification copy. |
| `payment.php` | Payment invoices, status and checkout. |
| `payment_review.php` | Payment evidence and operator review. |
| `payment_settings.php` | Gateway, destination, rate and eligibility settings. |
| `plans.php` | Plan/catalogue management and selection. |
| `provider.php` | Native provider setup/account operations. |
| `purchase.php` | New subscription purchase journeys. |
| `renewal.php` | Renewal and add-on journeys. |
| `subscription.php` | Subscription/config/QR presentation and settings. |
| `support.php` | Support and ticket flows. |
| `telegram.php` | Telegram interaction/transport-facing copy. |
| `wallet.php` | Wallet adjustment, transfer and debt flows. |

Runtime callers require `messages/loader.php` and the complete topic set.
A local wording change creates a different source identity: review it, run the
catalog audit and verify a matching artifact rather than editing an approved
package without recording the change.

## Rollback is a coordinated recovery operation

Retain a verified complete source/config backup and a separately reviewed
compatible recovery path before cutover. Restore source only as a coherent
package, including its matching message loader/topics and schedule contract.
An older version that cannot represent PasarGuard accounts or current financial
state must not continue operating those records.

Keep committed balances, paired wallet movements, payment/effect/referral
journals, replay cursors, provider identities and remote accounts intact.
A source downgrade does not reverse a payment or provider operation. **Do not
drop 011, erase journals, reset replay history, delete remote accounts, restore
an old database blindly, or automatically compensate/replay uncertain effects.**
An old database restore could erase intervening money and recreate replay
exposure. Any restore or repair needs separately approved, evidence-based
reconciliation of all effects since the backup. Coordinate webhook and Cron
activation so old/new workers do not run concurrently.
