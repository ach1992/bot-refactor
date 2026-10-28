# Unified server, plan and service lifecycle — Owner request 2026-10-28

## Accepted outcome
The administrator operates Marzban and PasarGuard through the same familiar server list and step-by-step journeys. Implementation details must not create a separate connection hierarchy in the primary UI. Configuration/link display belongs to each server. Plan onboarding and editing offer only the actual provider's entitlement controls. Cancel/back recover in one action. Customer journeys remain provider-neutral. Expiry, quota warnings, automatic renewal and delayed deletion use one common policy over validated provider observations.

The owner requested one coherent implementation batch and explicitly included provider-neutral scheduled lifecycle behavior. This supersedes the earlier separate PasarGuard onboarding UI and staged operator-test-before-development requirement for this batch; it does not fabricate live operator results or waive release/integration/production gates.

## Source audit at main 2c0e728
| Surface | Evidence / defect | Result required |
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

## Implementation plan
1. Add a small shared admin draft controller using existing users.step/temp, private actor framing, monotonic updates, nonce/revision callbacks and transactional final save. No new schema.
2. Replace server and plan onboarding with coherent provider-aware wizards, shared navigation/confirmation, validated read-only provider admission, atomic existing-table writes. Old entry buttons route to the appropriate new wizard; stale draft callbacks fail safely.
3. Align server and plan management menus, remove irrelevant retired-provider controls from visible screens, keep existing provider account and payment audit data.
4. Centralize service observation and lifecycle decisions. Preserve configured global switches and thresholds (36-hour / 0.5 GiB warnings, 2-hour / 0.2 GiB renewal, 10-day deletion grace). Unlimited time/quota are explicit. Disabled, unavailable, malformed and unknown states never authorize automatic deletion or charging.
5. Exercise real PHP + disposable MariaDB + loopback provider fixtures for both complete admin journeys, cancel/back, stale/replayed/cross-admin callbacks, provider mismatch, interrupted save, shared lifecycle and user messages. Run required PHP 8.1/8.4 current and historical gates on a stable candidate.
6. Review exact diff, obtain repository-required independent review and prepare a single reviewable PR/package. Production work remains bound to the approved exact candidate and target.

## Data / rollout
No destructive migration is needed for this UX batch. Already installed 011 stable identities remain necessary evidence for PasarGuard remote ownership, creation verification, retries and payment snapshots. They are not a separate user-facing server catalog. Never remove these to cosmetically match the UI. Frozen V0–V9 source/schema/tests and published releases remain unchanged. Real credentials, wallets, customer data, Cron registrations and provider accounts are excluded from development.

## Additional Owner scope during implementation
Audit every current settings/ and pay/ runtime file, remove only proven unused/retired files and reconcile packaging/deployment deletion lists. Supported scheduler/payment entrypoints remain authoritative. Review common admin/user subscription creation, order management and volume/time addons for both providers. Copy must be clear, concise and include examples when input is ambiguous. External adoption of accounts created outside the bot is a distinct ownership/identity decision; a question was raised and no answer is yet available. Preserve the existing identity guarantees rather than invent an adoption marker.

## Implemented adapter boundary
The current closed registry has exactly Marzban and PasarGuard. Its 14 required operations cover plan/order eligibility, same-panel proof, read/info, pinned deletion identity, action, edit, payment quote pin/validation and account-effect target/reserve/dispatch/link. A missing callback or unknown type fails before a new payable draft or wallet/account reservation. Source-owned provider keys generate the shared SQL list. A temporary test adapter exercises the same consumer paths; no third production provider is enabled. Native credential/entitlement setup remains implementation-specific; adding a real provider still requires its complete identity/operation adapter and integration evidence, without rewriting the payment/wallet/order/add-on/lifecycle business rules.

## Verification boundary
New native suites cover server/plan wizard state, one-action cancel/back, exact actor/role framing, concurrency/replay/unknown commit results, shared menus and customer/order actions, quota/time lifecycle, endpoint-change deletion safety, runtime-file/import/add-on validation and the provider contract. Existing current financial and immutable historical suites remain required. Isolated source/native results do not certify a real Telegram client rendering, live merchant settlement or a production panel operation.

## Owner clarification: remove compatibility-only paths
The owner explicitly clarified that a file left only to preserve an old path must be removed along with its old references. The canonical cleanup therefore removes the values facade (bindings come directly from the two catalogs), folds broadcast/service composition into the actual leased Cron coordinator's isolated child modes, and places the full initiation implementation in public pay/checkout.php. It removes pay/index.php and the old src/Finance/GatewayInitiation.php module. No replacement alias, redirect or empty wrapper is introduced. The real callback/IPN boundary pay/back.php and full gift/report/date implementations remain. New checkout URLs use pay/checkout.php; previously sent initiation links to pay/? are intentionally retired. Provider-issued return/IPN callback URLs remain unchanged.

## Notification delivery and lifecycle state
Notification transport success is not the authority for renewal or deletion. A pending warning uses existing signed notif=-2 and is retried; strict remote/consent/journal renewal guards stay unchanged. Confirmed expiry/exhaustion starts the full ten-day grace regardless of Telegram delivery. Positive deletion deadlines must be canonical and after the recorded order creation timestamp; invalid/unknown markers defer without repair or destructive calls. The administrator order screen reports the actual stage and deadline read-only; it does not toggle this internal scheduling field. The archived old toggle's actual mutations are preserved as RED evidence (0 to -1, or grace to 0), without attributing a nonexistent 0-to-1 behavior.
