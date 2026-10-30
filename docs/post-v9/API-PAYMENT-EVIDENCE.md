# API-assisted manual payment evidence

This is the accepted source-development contract for [Issue57](https://github.com/ach1992/bot-refactor/issues/57), alongside financial acceptance [Issue26](https://github.com/ach1992/bot-refactor/issues/26) and payment UX [Issue51](https://github.com/ach1992/bot-refactor/issues/51). The API integration described here is **not implemented or enabled**. Published RC19 and installed acceptance remain separate.

## Outcome and boundaries

Retain the existing manual bank-transfer and gift-card methods. Each gets an independent API verification switch and private connection settings in the existing Gateway/Channel Settings journey. OFF preserves the existing manual path. Do not repurpose or change the existing legacy card-transfer automatic acceptance path.

Use authenticated ACh Pay Manager SMS evidence and ACh Gift Cards for Woo code verification. Reuse the bot's shared payment attempts, transaction evidence and subject-specific settlement/effect pipeline. Neither an HTTP success nor a received code is proof of completed native service or wallet credit.

The dependency repositories and Blinko remain read-only during bot development. Live code verification itself can update external audit metadata; it is not a passive connectivity test. Actual client creation, configuration, real financial acceptance, installation, migrations, integration and publication keep their action-specific gates. Never replay completed012/013.

## Bank-transfer journey

- Pin the API mode, nonsecret logical source/destination configuration and policy when the invoice is issued. Preserve the original exact transfer quote, account/holder, user, subject and order identity.
- Initially show **Check payment**, without receipt submission. Both the bounded worker and an explicit customer check use the same persisted verification operation and lease.
- At elapsed600 seconds from durable issuance, add **Submit bank receipt** to that existing invoice if no payment was recognized. Retain Check payment and continue automatic reconciliation after receipt upload.
- The ten-minute fallback is not invoice expiry, proof of nonpayment or a retry deadline. Queued Telegram edits may arrive later; always render the latest financial state before editing.
- Human approval and verified API settlement compete for the same authoritative local financial claim. A later matched SMS after human approval is evidence-only: no second wallet credit, service effect, capacity change or referral reward.
- A late contradictory payment after human rejection remains reviewable. No silent reversal, refund or fulfillment is authorized without an accepted rule.
- Require one admissible evidence item and one invoice across the complete eligible retained set, including receipt-submitted and manually approved invoices. Exact amount alone is insufficient: destination/source, units, reliable payment time, immutable identity, external reservation conflicts and consumption provenance matter.

A generic external **used** status never proves that this invoice was paid. Unattributed manual consumption, missing reliable time, amount/source collisions or incomplete operation recovery must go to review.

## Gift-card journey

- API ON replaces screenshot submission with **Submit gift-card code**. Inform the customer that sending the code authorizes consuming it for that invoice under the configured value policy.
- Accept one immutable submission; repeat of the same code reads its status. A different code cannot replace an in-flight or uncertain operation.
- Authenticate the exact signed request, verify card identity, effective status and the explicitly configured denomination/unit, then reserve and consume once. A verify result is not settlement.
- Used, revoked and expired cards receive distinct clear messages. Unknown/not-found and unavailable verification use the support-review path without misrepresenting an outage as an invalid card.
- A code-review case is genuine typed evidence. Never fabricate a photo/receipt ID to satisfy the existing image-only approval guard.
- Protect a recoverable submitted bearer code until resolution; a digest alone cannot reproduce a lost consume request. Keep raw codes out of public diagnostics and general reports.
- Mismatch, multiple-card, partial-value, excess-value and currency/denomination rules remain Owner decisions; do not infer them from the visible face-value label.

## Independent evidence and receipt state

The receipt workflow and API verification run concurrently. Preserve separate persisted axes rather than overwriting receipt delivery stage, its claim token or its lease with polling state.

Before an external mutation, durably save the unique evidence identity, immutable expected snapshot, exact operation key/reference and dispatched intent. A timeout, HTTP5xx or lease expiry is not permission to create a new key or consume a second time. Recovery must distinguish the original committed operation from the evidence's current externally editable status.

The existing bank-buy009 fingerprint includes the attempt's payment_id, provider_reference and stable destination intent. Adding an SMS ID to those fields later or inserting mutable API fields into checkout_url would invalidate issued evidence. New source evidence belongs in child transactions or separately pinned additive fields, with no historical backfill.

Existing shared table uniqueness is reusable, but durable verification-policy, operation/outcome/snapshot and protected code recovery are real persistence gaps. Any justified additive migration must be reviewed and rehearsed separately; no new provider-specific invoice/account registry is assumed.

## Interoperability requirements

The existing external APIs provide useful authenticated read/consume operations, but their current contracts do not establish all required shared-destination and lifecycle-recovery guarantees.

Before enabling a shared bank destination, the external consumption boundary must coordinate with active WooCommerce reservations and all other consumption channels. It must bind the exact bot business/operation reference and immutable evidence expectations, retain attributable original operation proof, and refuse ambiguous conflicts. Prefer a conditional-consume extension with explicit conflict quarantine. It must check retained historical eligibility/assignment windows as well as active reservations, and synchronize all allocation/manual-consumption paths. Registering every external invoice in a new shared allocator is not necessary for safety when ambiguous collisions remain manual; do not create a second invoice authority merely for automatic-acceptance availability.

For gift recovery across manual unredeem/restore, bind operation identity to the authenticated client, card and consumption epoch, and retain original consumption proof separately from current card status. Without that proof, keep the outcome under review instead of retrying a new epoch.

Normal bot credentials need read/verify/consume authority only. Undo, revoke, restore and privileged manual association are separate authorities. Keep secrets out of URLs, Git and logs; pin trusted HTTPS origins, validate redirects/response identity, use exact integer/decimal arithmetic and explicit unit conversion.

### Minimal dependency work proposed for Owner approval

The bot remains the invoice authority. Extend the SMS consume contract with an explicitly versioned conditional mode: immutable business/operation reference and request digest; expected public evidence identity, monotonic revision, deposit direction, exact normalized amount/currency, destination/source mapping and reliable time snapshot. Under a common consumption/allocation fence, atomically refuse conflicting active **and retained historical** financial claims, then commit status, binding, audit and original operation result together. Add own-operation proof lookup. A restored-ready status or expired response cache must never let an old operation execute as a new payment.

For gift cards, scope durable operation identity to the authenticated stable client and bind card UUID, actor, business reference, expected denomination and consumption epoch. Return the original committed proof separately from current card status, including after an undo. Keep existing integrations compatible; no normal bot undo/override privilege is implied.

These are proposed additive dependency changes, not existing capabilities or authorization to modify either plugin. A new external invoice registry/shared quote allocator is unnecessary for the conservative implementation; conflicting or unprovable historical evidence remains manual.

## Interface projections and message convergence

Use complete Persian templates in messages/fa/user.php and operational/settings templates in messages/fa/admin.php. Preserve the existing simple sequential editor, current actor/revision guards, ASCII digits, escaping and exact copyable technical bytes.

| State | Customer action / truthful outcome |
| --- | --- |
| Bank monitoring | Check payment; original invoice facts and exact transfer amount remain visible. |
| Bank fallback due | Add Submit receipt, retain Check payment. |
| Code received | Show processing only after durable acceptance; do not claim a screenshot was received. |
| Support delivery queued | Distinguish saved evidence from delivered review request. |
| Verification unavailable | Outcome is not yet known; never advise paying again. |
| Money proven, effect pending | Payment verified; order completion under review. Remove payment/code submission actions. |
| Effect completed | Show the proven subject-specific service or wallet result. |

Current generic status synchronization skips awaiting-payment invoices and can replace full bank instructions with a price-only card. API waiting/fallback therefore needs an explicit projection preserving pinned facts, not an arbitrary provider_status string.

Preserve stored customer message identities and existing staff receipt copies. A stale queued fallback cannot overwrite a later successful state. Failed Telegram delivery never rolls back a committed financial result; retry delivery with the current projection. Editing an existing receipt to remove stale actions is distinct from sending a new general notification.

## Notification contract

The current Owner request changes unsolicited fanout, without granting extra action permissions.

| Event | Primary Owner | Manager with receiving ON | Operator/Support with receiving ON | Report channel |
| --- | --- | --- | --- | --- |
| General outcome/report | Yes | Yes | No | Where the event is intended for reports |
| Automatic financial change | Yes | Yes | No | Yes, safe complete outcome report |
| Actionable manual receipt/code review | Yes | Yes | Yes | Preserve the established evidence privacy boundary |
| Private operational diagnostic | Yes | Yes | No | No private diagnostic leakage |
| Backup archive | No new copy | No new copy | No new copy | Existing backup destination only |

Current valid stored receipt recipients still get final status/button maintenance when their new-copy preference is OFF. Revoked, banned or ambiguous identities remain excluded. Direct responses to the actor currently using an authorized screen and customer messages are not unsolicited staff fanout.

## Current source package and retained exceptions

The current source package prepares event-aware staff routing and evidenced direct native gift corrections; it does not implement API payment acceptance. The separate legacy automatic card-transfer implementation remains byte-identical, including its three existing Owner-only diagnostics. Its financial path is outside the current change. Backup scheduling/destination also remains unchanged.

Native gift corrections preserve transient credential pins without persisting raw credentials, retain local time/notification metadata on quota-only gifts, and validate time increases against the adapter's already-verified native baseline. They do not prove the cause or outcome of any installed request. Shared-reference anchor/notification policy and planless-import gift admission remain unresolved; existing refusals are not silently removed.

## Open activation decisions

- Exact redeem_amount unit and eligible card/game/value mapping; excess, partial or multiple-card policy.
- Reliable SMS time/source admission and monitoring/reconciliation horizons, separately from the600-second receipt fallback.
- Privileged association of externally pre-used evidence and treatment of later funds after manual rejection.
- Dependency API changes versus a demonstrably dedicated source with conservative review. No dependency mutation or live activation has been authorized.

## Required evidence

Prove source behavior on owned disposable PHP/MariaDB/loopback fixtures: exact units/quotes, cross-invoice and cross-actor collisions, concurrent human/API winners, delayed SMS, credential/config changes, invalid signatures, expired idempotency/replayed references, lifecycle resets, restarts, partial commits, unknown native effects, ten-minute queued edits and current role/receiving behavior.

Keep legacy automatic acceptance and frozen original V0–V9 unchanged. Stabilized source still requires full exact-candidate PHP8.1/PHP8.4 current and detached-history gates, runtime/package inventory verification and author-separated review. These checks do not establish installed acceptance.
