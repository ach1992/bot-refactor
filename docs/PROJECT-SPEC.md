# AChBot — canonical product and historical refactor contract

## Current post-V9 supported-product contract — Owner-confirmed 2026-10-14

**Source of truth for current development:** [Issue #31](https://github.com/ach1992/bot-refactor/issues/31) owns the Marzban-only implementation and acceptance. [Issue #29](https://github.com/ach1992/bot-refactor/issues/29) is the four-stage roadmap. The latest **released** version remains [V9 `v0.9.0-rc.1`](https://github.com/ach1992/bot-refactor/releases/tag/v0.9.0-rc.1) with all original 92 runtime files **unchanged from V8**. The scope below is for the **next, not-yet-integrated/released** source candidate and must not be represented as completed deployed behavior.

**Purpose:** retain the desired bot, user/admin/agent, purchase/renewal, support, reporting, scheduled and wallet functions with less coupling and obsolete code, while **keeping the original 23-table MariaDB schema and persisted data unchanged by default**. Broad schema redesign/new features/performance reengineering belong to a later independently accepted phase.

**Supported VPN backend now: Marzban only.** The Owner explicitly confirms that **legacy X-UI, Sanaei, Alireza, old 'normal' panel variants have NEVER been operated**, and authorizes **fully removing** their networking, management/wizard, configuration/link-building and dead branches from the next source iteration. **No backups, data export, migration or cutover for these nonexistent legacy panel deployments are required.** This narrow old-panel waiver never waives actual bot/customer/Marzban/financial data backup, schema migration, or restoration safety. Never infer that an unexpected legacy server record should use Marzban: reject unknown/retired types before provisioning/charging/deletion with safe feedback. No real provider state or real DB is to be modified as part of source refactoring.

**Owner-confirmed same-panel logical server transfer (2026-10-15):** multiple logical bot servers may reference the same actual Marzban panel but have different displayed plans, categories or prices. For these entries, preserve the original `orders_list.server_id`-only change, without changing the provider account, its inbounds, protocol, UUID, token, links, purchase plan/fileid, wallet or capacity. Enable the action only for the same verified Marzban API endpoint and admin username, with optional explicit matching panel-group tags stored in existing `setting` rows; untagged legacy entries with the same endpoint/username stay eligible. Conflicting explicit tags or differing endpoints/username must not move even if a callback is forged. An admin can choose or change the group; `server_info.flag` is an unrelated presentation field. Updating real provider profiles, changing plans/prices automatically, migrating between actual panels and supporting future PasarGuard are separately scoped future work; don't claim physical relocation. Reference [Issue #31 owner decision](https://github.com/ach1992/bot-refactor/issues/31#issuecomment-6184040770).

**Extensibility:** keep a *minimal* explicit provider boundary for Marzban account create/read/update/delete, renewals, links, status and traffic. **PasarGuard, next-generation X-UI and other unknown panels are future optional implementations, NOT current supported transports.** Do not build speculative APIs or compatibility flags for panels that have not yet been selected.

**Wallet:** `users.wallet` may be negative to reflect **explicit debt set by a privileged administrator**. Ordinary users must **never** create or deepen debt via wallet spend, transfer or renewal. A distinct auditable administrator-debt operation is required; never globally enforce wallet >= 0. This is a **desired future policy** tracked under [#26](https://github.com/ach1992/bot-refactor/issues/26), not retroactive financial safety certification of published V9.

**Payments and TRON:** future gateways must fit provider-specific initiation/verification and a common payment-state/idempotency mechanism; do not silently unify different settlement semantics. TRON's inherited success-assignment bug, missing `pays.tron_price` in reference DDL and TLS validation defects need dedicated fail-closed remediation. The current Marzban removal PR **must not disguise finance-policy changes**. Keep relevant public callback/payment/Cron entrypoints stable and do not operate live funds.

**Acceptance:** separate (a) original **immutable V0–V9 Phase 1 historical** source, test and release evidence from (b) **new current Marzban-only behavioral** tests; do not edit old golden references or relax the old byte-equality contract for the changed runtime. Verify Marzban on owned original-schema MariaDB + loopback fake provider (positive + failed + unexpected legacy type), current PHP 8.1/8.4, no real secrets and no real Cron/Telegram/payment. Operator-installed acceptance, Owner merge, release, and actual deployment are distinct gates.

**Data policy:** no SQL schema/table/column drops in Marzban-only source removal. An audited later cleanup may remove *proved* unused fields/tables only with separate Owner authorization, true data safety, backup/restore and rollback proof. Static code search alone is not evidence a column is safely removable.

## Historical Phase 1 V0–V9 contract — completed source integration, original reference only

The following sections describe the **immutable, historically accepted behavior-preserving** source-refactor phase, not a rule that prevents the Owner's later explicitly authorized Marzban-only removal. Their test sources and released evidence must remain truthful and independently reproducible.


## Outcome

Incrementally refactor the existing PHP Telegram VPN bot so the final operator can replace the bot application source on its existing deployment without changing its existing MariaDB database, business rules or user-visible/admin-visible behavior. Improving readability, maintainability, testability and future extension is the purpose of the change.

## Non-negotiable compatibility

1. **Database:** No DDL, migration, table/index/column changes, writes for repair/migration, or data re-encoding as part of Phase 1. Ordinary application data writes must continue with equivalent semantics.
2. **Observed behavior:** Maintain commands, menus, labels, callback handlers (including in-flight legacy callback strings), state transitions, price/calculation rules, purchase, extension, renewal, discounts, wallet accounting, bot/admin/agent permissions and notification behavior.
3. **Integrations:** Maintain Telegram webhook entrypoint, return URLs, active subscription endpoints, remote VPN panel adapters, gateway/payment return behavior, Cron file paths and timing assumptions until replacements are proven compatible.
4. **Operator experience:** Every refactor increment must be complete and independently deployable with a documented file swap, readiness checks, human-tested acceptance and rollback.
5. **`baseInfo.php`:** Keep actual local file and values untouched. Do not attempt a breaking config migration. Consider any structural change only in the last increment.
6. **Bugs:** Changes that might alter external behavior are separately scoped, evidence-backed, testable and explicitly accepted; when uncertain, preserve old behavior behind a seam for later repair.
7. **Phase 2:** Future schema migration is outside Phase 1. Add application boundaries allowing future storage refactoring; do not over-engineer abstractions.

## Acceptance gates per increment

- Recorded initial baseline, integration/entrypoint inventory and explicit scenario-by-scenario behavior checks.
- Targeted automated characterization/regression tests **and** preserved system-wide smoke tests; manual operator test over representative non-production state.
- SQL/DDL fingerprint unchanged and verified against reference; no migration runner/change to production DB.
- Every old entrypoint, callback, URL and Cron path required for compatibility continues to work, or an equivalent verified forwarding compatibility adapter exists.
- Exact-candidate independent review; compare code behavior plus database side effects and external requests, not only PHP syntax.
- Operator approves the validated increment before it becomes the base for the next; rollback remains viable.

## Security and publication constraints

Never place original SQL with customer/financial/integration data into a public repository. This public bootstrap intentionally omits actual rows, live runtime config, backups and logs. Search for additional embedded secrets before every push. Rotate any real credentials that were included in shared source/dump. Do not add deployment secrets to CI logs.

## Future naming

The official, case-sensitive product name is **AChBot** (not **AchBot**). Use this spelling in all new user-facing names, release titles, project docs, and source comments. Lowercase `achbot` remains an acceptable technical slug in paths, tests, release filenames and identifiers; do not rename working interfaces for spelling alone. Legacy `wizwiz` callback payloads and script paths are compatibility contracts, not just branding: replace them only with backward-compatible decoding/aliases, and do not break previously sent Telegram inline keyboards. Internal names can be simplified where not observable externally.

Previously approved, byte-frozen source excerpts and historical reference fixtures may retain earlier spelling. Branding changes never authorize changing runtime behavior, callbacks, Cron filenames, or database records.
