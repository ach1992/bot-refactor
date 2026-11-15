# Unified server, plan and service lifecycle — Owner request 2026-10-28

## Accepted outcome
The administrator operates Marzban and PasarGuard through the same familiar server list and step-by-step journeys. Implementation details must not create a separate connection hierarchy in the primary UI. Configuration/link display belongs to each server. Plan onboarding and editing offer only the actual provider's entitlement controls. Cancel/back recover in one action. Customer journeys remain provider-neutral. Expiry, quota warnings, automatic renewal and delayed deletion use one common policy over validated provider observations.

The owner requested one coherent implementation batch and explicitly included provider-neutral scheduled lifecycle behavior. This supersedes the earlier separate PasarGuard onboarding UI and staged operator-test-before-development requirement for this batch; it does not fabricate live operator results or waive release/integration/production gates.

## Historical source audit (main@2c0e728)

This table records original implementation findings, **not open defects or new assignments**. The current source and accepted contracts below take precedence.

| Surface | Historical evidence / defect | Accepted design intent |
| --- | --- | --- |
| Server onboarding | bot.php legacy sequential Marzban wizard; ProviderAdmin.php separate 3-line connection plus 5-line server form | One add-server entry, type selection, sequential fields, optional reuse of a saved connection, final summary and atomic save |
| Cancel | ProviderAdmin clears step then returns false; generic cancel compares stale cached step and refuses | The owning handler clears state and renders the originating section once |
| Plan onboarding | Administration.php uses global inactive drafts and getMarzbanHosts for both providers | Per-administrator draft; common plan fields; Marzban inbounds or PasarGuard Groups; no cross-admin draft deletion |
| Server management | PasarGuard keys omit flag, deletion and other shared actions | Same ordering and common actions, provider-specific connection/entitlement controls in the same server screen |
| Display | Per-server values exist, but legacy-number controls are buried and UI lacks contextual return | Clear display/config section within server; contextual back/cancel; preserve exact installed policy values |
| Plans / orders | Existing shared server_info, server_config, server_plans, orders_list, pays already serve both providers | Reuse these; retain provider identity/snapshot evidence; do not delete populated historical columns merely because hidden controls are obsolete |
| Scheduled warnings/deletion | Warnings.php has provider branch, null/unlimited arithmetic, uuid-wide updates and disabled-state inference | Strict common observation, expiration/quota reasons only, per-order writes, preserve existing grace and effect guards |
| Automatic renewal | Requires numeric expiry even for unlimited time, quota trigger nested under expiry | Shared observation and trigger policy, unchanged protected reserve/dispatch/finalize |
| Customer service view | Different unlimited/status presentation across providers | Same confirmed status/remaining quota vocabulary; technical details stay admin-only |
| Purchase / renewal / extension / gifts | Shared guarded dispatch already exists | Preserve financial/effect identity, capacity, panel ownership, uncertain-operation handling; regression-check both providers |

## Current implementation and acceptance boundary

Current server and plan onboarding uses provider-aware, actor-isolated revisioned drafts, safe cancel/back, validated read-only native admission and guarded writes to existing tables; unknown/stale callbacks cannot reuse another actor's changes. Existing customer, account and financial evidence remains authoritative. Preserve the accepted configurable lifecycle policies (36-hour/0.5 GiB warnings, 2-hour/0.2 GiB renewal triggers, 10-day deletion grace), including explicit unlimited values. Unavailable, malformed or unknown native observations never authorize a charge or automatic deletion.

For any future source change, use current PHP 8.5 functional/disposable-MariaDB and loopback tests under the [active CI contract](CI-VALIDATION.md), with exact-candidate review and separate Owner gates for operational changes. Completed historical implementation plans and PHP 8.1/8.4/V0-V9 test matrices are not current assignments or acceptance gates.

## Data / rollout
No destructive migration is needed for this UX batch. Already installed 011 stable identities remain necessary evidence for PasarGuard remote ownership, creation verification, retries and payment snapshots. They are not a separate user-facing server catalog. Never remove these to cosmetically match the UI. Frozen V0–V9 source/schema/tests and published releases remain unchanged. Real credentials, wallets, customer data, Cron registrations and provider accounts are excluded from development.

## Accepted runtime and import boundaries

The accepted cleanup retains supported `settings/` and `pay/` runtime entrypoints and excludes proven retired compatibility-only files from package/deployment inventories. Both Marzban and PasarGuard retain common subscription creation, order management and day/volume add-on workflows. UI copy stays clear and uses examples for ambiguous input. Existing PasarGuard subscriptions use the established Marzban-style import journey: a customer supplies the subscription URL, previews the account and confirms, or an administrator selects a customer through the existing flow. Native proof is internal; no new user step is required.

## Implemented adapter boundary
The current closed registry has exactly Marzban and PasarGuard. Its 15 required operations (as defined by `achbotProviderRequiredOperations()`) cover plan/order eligibility, same-panel proof, read/info, pinned deletion identity, read-only `action_preflight` admission, action, edit, payment quote pin/validation and account-effect target/reserve/dispatch/link. A missing callback or unknown type fails before a new payable draft or wallet/account reservation. Source-owned provider keys generate the shared SQL list. A temporary test adapter exercises the same consumer paths; no third production provider is enabled. Native credential/entitlement setup remains implementation-specific; adding a real provider still requires its complete identity/operation adapter and integration evidence, without rewriting the payment/wallet/order/add-on/lifecycle business rules.

Management identity must resolve exactly one persisted actor under lock; draft writes target its primary id and Telegram userid together. A fresh cancel from an older revision of the same flow closes that flow once, while a previous flow nonce or future revision cannot cancel a new draft. Server deletion is refused while any financial/editor/wizard step owns the actor state.

Administrator-authored messages remain in Telegram history, including passwords/API keys, rejected input, cancel text and media. The shared deletion facade protects only the current incoming administrator message at its exact chat/message target; callback-driven cleanup of bot-authored menus and scheduled deletion of stored outgoing broadcasts retain their behavior. Group selection, deselection and pagination update the existing callback message for both server and plan selectors. Typed input continues to receive the next sequential prompt; a not-modified edit does not create a duplicate message.

The common financial-subject preflight resolves the referenced order or plan before choosing its provider. Read-only callers may omit the redundant server hint; any explicit conflicting or malformed server hint is rejected. Native PasarGuard durable quote/account/endpoint pins remain mandatory. Existing payment/owner/plan/effect guards retain their authority.

## Native full renewal from unlimited time
A verified native account may have an explicitly unlimited expiry while its finite quota needs renewal. Both adapters accept that NULL baseline only for a validated positive whole-days replacement that establishes a finite new expiry. Existing account identity, target/configuration, group, usage-reset/readback, consent, wallet, journal and finalization guards remain authoritative. Relative extensions and requests without a full-days replacement retain their previous expiry restrictions. Missing, malformed and changed observations cannot authorize a partial reset or PUT.

Actual native financial sweeps reproduce the prior 2408982 failure for both providers and verify one debit, one native reset/absolute update, preserved native configuration, completed payment/attempt/order, and no replay. Pure/native edit negatives preserve zero semantics and reject invalid or drifting expiry before effects.

Customer/admin date coverage composes the affected expiry/grace views with the real Jalali calendar in Tehran. The four touched calls select English digits explicitly. The pinned baseline calendar already produced ASCII digits; this is an explicit-format and coverage refinement, not a claimed Persian-digit regression.

## Verification boundary
Current functional checks cover server/plan wizard state, cancel/back, actor/role framing, concurrency/replay/unknown results, shared menus, quota/time lifecycle, import/add-on validation and the provider contract. Required acceptance uses the [current PHP 8.5 CI contract](CI-VALIDATION.md) plus relevant targeted current-source tests; immutable historical suites remain evidence, **not required checks**. Isolated source/native results do not certify real Telegram rendering, live merchant settlement or production panel operations.

## Owner clarification: remove compatibility-only paths
The owner explicitly clarified that a file left only to preserve an old path must be removed along with its old references. The canonical cleanup therefore removes the values facade (bindings come directly from the two catalogs), folds broadcast/service composition into the actual leased Cron coordinator's isolated child modes, and places the full initiation implementation in public pay/checkout.php. It removes pay/index.php and the old src/Finance/GatewayInitiation.php module. No replacement alias, redirect or empty wrapper is introduced. The real callback/IPN boundary pay/back.php and full gift/report/date implementations remain. New checkout URLs use pay/checkout.php; previously sent initiation links to pay/? are intentionally retired. Provider-issued return/IPN callback URLs remain unchanged.

## Notification delivery and lifecycle state
Scheduled deletion also uses bounded ID keyset traversal through existing setting metadata. Every selected batch advances even when all rows defer, and exhaustion wraps to revisit earlier rows. Invalid deadlines, unavailable provider reads or pending financial work therefore cannot permanently hide a later eligible order from either provider. Traversal never authorizes deletion or alters a deferred order/payment/account record. Native RED evidence executes the previous 5568e5e source twice; GREEN covers both cross-provider directions and 50 real pending-payment guard deferrals.

Notification transport success is not the authority for renewal or deletion. A pending warning uses existing signed notif=-2 and is retried; strict remote/consent/journal renewal guards stay unchanged. Confirmed expiry/exhaustion starts the full ten-day grace regardless of Telegram delivery. Positive deletion deadlines must be canonical and after the recorded order creation timestamp; invalid/unknown markers defer without repair or destructive calls. The administrator order screen reports the actual stage and deadline read-only; it does not toggle this internal scheduling field. The archived old toggle's actual mutations are preserved as RED evidence (0 to -1, or grace to 0), without attributing a nonexistent 0-to-1 behavior.


## Existing subscription import contract
Both adapters use an optional, all-or-none import lookup/adoption capability alongside the 15 required provider operations (including read-only `action_preflight`). A new adapter implements native proof while common preview, actor/customer authorization, order persistence and post-import management stay shared. Missing import capability disables only import for that adapter.

PasarGuard 5.5.1 tokens identify an untrusted native lookup hint. The supplied token is verified through the native read-only subscription info endpoint derived from an authenticated native response, then matched by native id, username and creation time. Fresh API reads issue a new token, so equality with a newly issued token is not an ownership proof. Never fetch an arbitrary supplied host or rewrite the native note. Exact configured aliases and prefixes participate in URL admission; distinct physical identities remain ambiguous.

The existing transaction records an amount-zero, fileid-zero order with the actual native expiry and automatic renewal off, matching Marzban import. It links a genuine adopt/external_import effect and provider identity using existing tables, with no payment row or capacity reservation. Different bot users may intentionally retain separate references to the same physical native identity; the same request cannot create accidental duplicate records. Created accounts retain their strict native marker proof; adopted accounts derive their explicit identity mode only from validated persisted adoption provenance. The original NOT NULL orders_list expiry column keeps its existing zero sentinel for native unlimited time; the native snapshot and normalized observations retain literal NULL. Common reads, customer/admin controls, payment pins and lifecycle use that identity without inventing an active status, finite expiry or product plan. Remote schedules and unsupported mutations retain native eligibility restrictions.


## Fresh customer expiry and bounded read reuse
The owner explicitly requires the customer service page to show the current panel expiry like current usage. One fresh normalized provider observation per page supplies quota, status and expiry together; the stored order date is never a fallback for an unavailable or malformed remote read. Reopening the page observes external renewals immediately, including transitions to or from unlimited time. Tehran Jalali formatting uses ASCII digits. Reuse is confined to that render: no cross-request cache may bypass current financial, bearer, incarnation or destructive-action proofs. Administrator metadata continues to label its separately stored date explicitly.


## Unlimited display and import management completion
Known unlimited total quota and expiry display the literal Persian word نامحدود in import previews, native customer/admin service pages, plan details, recorded-order metadata, subscription reports and deletion reports. Unknown, absent or malformed evidence displays نامشخص or the existing unavailable notice. Finite zero remaining quota, zero usage and a zero gift/add-on delta retain their distinct meanings. Native creation still requires the existing positive finite product entitlement.

Relative volume-add-on callbacks cannot describe a zero unlimited-time sentinel as expired or create an unsupported payable draft. Local gateway active-order categories include the established unlimited-time zero sentinel only for status=1, consistently in preview and locked final checks; native financial verification remains separate and fresh.

Planless imported services use actual regenerate-link/delete controllers without requiring a missing product row. Native Marzban revoke is verified by exact pinned native acknowledgement, incarnation/configuration preservation and real proxy credential rotation; generated token inequality is not proof because native tokens can be identical within the same second. No synthetic success fields are accepted from a fixture.

Import does not reserve capacity, and deletion must not restore an unreserved slot. Current imports record explicit nonfinancial origin in the existing setting table and adoption evidence in existing007 when available (PasarGuard also retains011 native identity). Final deletion rechecks that origin under the locked order, including after later plan assignment. This also preserves zero reserved capacity on original-schema Marzban without007 or011 and without installing any schema. Existing positive-plan orders without adoption evidence retain their established restoration behavior. No historical provenance is guessed or backfilled.


The initial planless import does not offer an automatic-renewal toggle which its existing handler cannot enable. Unsupported relative day/volume actions are hidden for confirmed unlimited-time accounts; a confirmed unlimited total quota also hides relative volume purchase. Finite zero remaining quota retains the volume-extension action. The familiar change-plan action remains available. These controls reuse the same fresh packet and do not relax a payment or mutation guard. Unknown capacity provenance retains the fenced order for reconciliation instead of restoring an unproven slot or silently discarding the record.

The Marzban proof follows pinned Gozargah/Marzban 7f396db3e703d71a28060bc9ce4a532ec64cb1f4. Its model regenerates username/time tokens on reads, and its native parser also accepts legacy HS256 subscription JWTs. Both forms are untrusted lookup hints until native bearer proof. Primary references are app/models/user.py (fa590036c054e89103dd6b88a44e37d8c798e013), app/utils/jwt.py (b2202cf9487ed857eca80938e53a9cbaf7d56cad), app/routers/subscription.py (a75525209f5286fbaff8a41f9f2c1013f88945bb), app/dependencies.py (b11608f4f1503a2b4d92cba5f0a94e3cf3d5b4ec), app/db/crud.py (35436e5038cc50aca860a9e3ad423bdfefa1fe50), app/models/proxy.py (174922b6526292c1df76a6933e5010c4c488be00), and app/routers/user.py (e5bcdf81e9aa7be45f17cb2ab8e62da42761da15). Real native responses contain no synthetic success/verified fields; the adapter establishes those only after its own proof.


## Source validation and installed boundary

Validate current provider-aware import, identity, shared-account references, capacity provenance, plan changes, permission and presentation paths with applicable actual-PHP/disposable-MariaDB fixtures. The [current PHP 8.5 CI contract](CI-VALIDATION.md), release-matched package verification and author-separated review own current source acceptance. Historical test counts/RC matrices are available in Git history and are not active gates. No source test alone proves installed Native, Telegram, payment or customer behavior.


## Preserve the existing link-to-order flow
The owner clarified that the established Marzban flow already adds the supplied subscription link to the current customer's orders, permits later plan selection, and allows another customer or administrator-selected customer to add that same link. The PasarGuard adapter must preserve that flow; this is not a new multi-user product or setup process. This supersedes any earlier exclusive-owner language or cross-user rejection tests. It does not authorize reusing another actor's private draft; each reference follows the same bearer-link/preview/confirmation journey. Existing native identity and payment/effect replay safeguards remain. There is no ownership-transfer flow.

The remediation uses genuine version1 per-order origin metadata in the existing setting table, implemented in OrderWrites.php. It records nonfinancial import origin and zero reserved capacity even without007, survives plan assignment and token rotation, and may reference one PG physical provider_accounts row. Multiple local references share that verified physical native identity/mutation fence; the original created/adopted anchor and journal history are not rewritten. No new DDL or fabricated paid amount is introduced. Absent metadata preserves legacy behavior; malformed or ambiguous present evidence is held rather than guessed.

Verified imports may use the existing plan-selection journey while their amount remains0. Confirming a plan changes only a valid current same-server active plan reference under current-owner checks; it does not debit funds, provision a new account or reserve capacity. Paid renewal/add-ons then use their actual current quotes and existing settlement/identity guards. Zero-price free trials do not gain imported-service permissions. Planless update-link refresh and regenerate are both independent of a missing product row.

Shared registration is separate from conflicting operations. Actual in-flight or uncertain financial work retains its protection. Automatic renewal uses one consenting funded claimant for a known physical cycle and updates shared observations after verified completion, avoiding duplicate automatic charges. An explicit native deletion keeps the established Marzban meaning: the actual remote account is deleted; other local references are not silently removed or reinterpreted as a transfer/detachment.

The f8f5151 source matrix was green, but independent review requested the actual plan-selection/paid journey, visible planless update and original23 capacity-origin remediation. Its exclusive-owner finding is superseded by the subsequent explicit owner clarification. The remediation requires refreshed native/current proofs, exact-head matrix and independent delta review; the prior93-case result is baseline evidence rather than final shared-registration acceptance.


## Remediation acceptance
The actual supplied-link→local order→plan selection→native renewal journey now runs for both adapters with the existing callbacks and no extra user setup. The native186 suite performs real priced renewal through the actual quote, prepayment and wallet controllers in an isolated database/native HTTP fixture, retaining payment-owner and replay denials. The separate original23 origin suite verifies46 cases without007 or011, including later plan assignment, unchanged financial/capacity data and malformed-origin holds. Focused plan/consent and physical-operation regressions verify the actual existing UI/controllers rather than SQL-only shortcuts.

Both visible native update-link and regenerate-link actions work with a planless imported service. The admin service-detail plan action keeps the familiar common selector with a fresh persisted admin override; ordinary users still operate their own local order. Choosing a plan changes fileid only. Existing financial/native eligibility is checked before an unsupported operation can debit a wallet or leave a known-refused account claim suspended. Existing mutation history is validated before eligibility HTTP and again under the exclusive claim; only a fresh post-claim observation drives native arithmetic. The existing same-order automatic-renewal claim returns its established replay result before peer-work checks for a new claim. Imported native scheduling fields remain untouched and registration/read remain allowed. No new role, transfer workflow, payer setting, registration mode or user step is added.

Existing automatic-renewal consent remains visible for withdrawal on an active local order after plan retirement, a global renewal switch-off or an unavailable observation. It retains the original callback and label; offering new consent still requires the existing eligible paid plan and native/global checks. The actual renderer and native URL-import/controller suites verify590 and94 cases respectively, including unchanged financial/native data on withdrawal.

Verified imported subscriptions receive ordinary quota/expiry warnings before and after plan selection, despite retaining their truthful amount0. The existing origin distinguishes them from genuine zero-value trials, whose prior silent-warning policy remains. Send failures retain notif=-2 for retry; confirmed messages are not repeated. Malformed present origin holds only its affected row. Actual URL-import/native acceptance verifies186 cases, and the lifecycle suite118, retaining all previous guards and thresholds without money, capacity or native-account writes.

The current provider contract has15 mandatory operations, including read-only action_preflight. Actual customer, administrator and scheduled deletion establish native eligibility before any local deletion fence; immutable target capture/matches and post-fence proofs remain separate. Automatic renewal runs authoritative quote eligibility after pinning and before committing payment, journal or debit. Locked quote reads are current reads even after an earlier repeatable-read snapshot. Registry89 includes discriminating stale-snapshot RED controls.

Existing amount0/agent_bought1 services retain their familiar manual renewal and finite day/volume actions with a valid current plan. Shared plan validation is separate from action admission; this exception does not create import origin, fabricate money or enable new automatic consent/plan selection. The focused42-case native suite executes actual100%-discount SelectPlan→TrialProvision→CREATE/readback, reduces the discount, and verifies the existing manual buttons and real priced quotes for both providers, with archived4458 renderer RED controls.

The historical independent review and its native/lifecycle fixture counts record completed source corrections; they do not reopen old test matrices. Current changes follow the active PHP 8.5 CI and exact-candidate independent review requirements, with installed Native/payment acceptance separately gated.


## Owner simplification follow-up (2026-10-28, revision 2)

This accepted follow-up supersedes earlier separate-provider-table, import-origin allowlist, shared-reference expiry synchronization and customer-switching assumptions in this document. Each local order remains the customer's account reference and native reads remain authoritative. Reuse existing server/order/effect storage; preserve native incarnation and pre-order/unknown-result financial evidence rather than relocating a duplicate account registry under another name. Historic provider-table and obsolete order-column cleanup belongs to versioned source migration history; it is **not** an instruction to prepare or execute old SQL on an installed tenant. Actual schema changes require independent target inspection and Owner authorization.

Acceptance: both providers keep the familiar purchase/import/renewal/add-on/lifecycle paths; arbitrary subscription alias domains are admitted only after native token proof without fetching arbitrary hosts; current agent discounts apply to automatic renewal; fresh native state controls add-on eligibility and prevents duplicate shared automatic cycles without peer order writes; full renewal preserves current Groups. Server switching is admin-only, applying destination-server access within existing same-panel constraints. Confirmed server removal may retain dependent historical rows and must consume its prompt. Every config QR has its own native name caption and a separate copyable unchanged URI. Existing statistics definitions, presentation, import/warning wording, thresholds and bulk policy remain; include the current expired state in unfinished-purchase statistics. Incoming admin history and in-place group selection from PR92 remain required.

Validation: current Native/socket-MariaDB regressions and actual migration/rollback or approved roll-forward evidence where relevant, plus exact-candidate [PHP 8.5 CI](CI-VALIDATION.md), package verification and author-separated review. Real provider, Telegram, payment and production/schema effects remain independently Owner-gated.

Migration 013 was authored to remove obsolete order protocol/rahgozar fields only after their active consumers stopped using them; its completed implementation/test history is not an instruction to reapply it. Actual installed schema, original financial records and Native identity remain subject to per-bot verification and Owner approval before any new migration.

## 2026-11-03 owner clarification: physical subscriptions and plan-native access

The earlier historical sections that say plan selection only changes one order's
`fileid`, that a PasarGuard logical transfer adopts a server-default Group,
or that sharing implies zero peer-order updates are superseded specifically for
the accepted physical subscription transfer and plan-selection workflows.
See [SHARED-SERVICE-TRANSFER.md](SHARED-SERVICE-TRANSFER.md) and [Issue #112](https://github.com/ach1992/bot-refactor/issues/112).
The current contract synchronizes all active local references; native access is
changed once from the selected plan and verified before committing the shared
orders. Routine paid renewal and extension retain their separately accepted
financial cycle contracts and must not be conflated with plan selection.
