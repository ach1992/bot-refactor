# API-assisted manual payments

This is the current source-development contract for [Issue57](https://github.com/ach1992/bot-refactor/issues/57), alongside [Issue26](https://github.com/ach1992/bot-refactor/issues/26) and [Issue51](https://github.com/ach1992/bot-refactor/issues/51). Source implementation is prepared on `feature/existing-payment-apis`; full composed acceptance and independent review remain pending. This document does not establish installed behavior, authorize a migration, or enable an integration.

## Scope and existing contracts

Add optional verification to the existing manual bank-transfer and gift-card methods. Each has separate private API settings and an adjacent independent switch, absent/default OFF. OFF retains the original manual journey. The unrelated legacy automatic bank acceptance implementation remains unchanged.

Use the existing authenticated ACh Pay Manager v1 and ACh Gift Cards for Woo v2 APIs. Their ready/active-to-used/redeemed persistence is the selected consumption contract. No plugin change, new external invoice registry, exclusivity declaration, or mandatory filter wizard is a prerequisite. The bot remains the invoice authority. Dependency repositories and Blinko are read-only during this development.

Reuse shared `payment_attempts`, child `payment_transactions`, the real subject-specific local settlement claim and existing service-effect journals. An HTTP response, verified code, or payment confirmation is not proof of completed native delivery.

## Bank-transfer matching and journey

The original full invoice amount, including its randomized trailing three digits, is immutable. A candidate must equal that complete amount after exact, explicit rial/toman conversion. Never match only the suffix, regenerate the quote, round, or tolerate a difference. A different amount does not enter the invoice candidate list and must cause zero remote mutation.

Read ready SMS only for new matching. Preserve immutable remote identity, configured source filters and reliable transfer time within the invoice's explicitly configured monitoring horizon. Missing/unparseable time, unsupported units and ambiguous same-amount local invoices remain unconfirmed. Optional timezone/calendar settings permit interpreting timestamps without an offset; ingestion time is not a substitute for transfer time. A used status alone never confirms an invoice.

Initially show **Check payment** without receipt submission. At elapsed600 seconds from durable issuance, add **Submit bank receipt** by editing the existing invoice and retain checking. The fallback is not invoice expiry or evidence of nonpayment. Continue automatic reconciliation after receipt submission. UI projection must preserve the pinned exact amount and destination and must render the latest financial state after queued edits.

Human approval and verified API settlement compete for the same authoritative local claim. A manually settled invoice is terminal for wallet credit, native effects, capacity and referral rewards. A later exact ready SMS can be consumed for evidence only; it must not reopen confirmation or repeat any effect. Already-used SMS do not re-enter new matching. Preserve records of original dispatch and consumption even after configuration changes.

A manually rejected invoice followed by contradictory payment is reviewable. Do not silently reverse rejection, refund or fulfill it. After automatic monitoring ends, retain the manual invoice/review journey; monitoring policy is separate from the ten-minute fallback.

## Gift-card matching and journey

API ON replaces screenshot submission with **Submit gift-card code**. Explain that sending the code permits its consumption for the current invoice. Persist the recoverable code encrypted before verification. Do not log or include it in general reports or channel notifications.

The monetary field is canonical `redeem_amount` only. `display_amount`, `face_value` and a label are not funding evidence. Require an explicitly configured rial/toman unit. The conservative source implementation accepts one exact-value card; partial funding, multiple cards and excess wallet credit are not inferred. Optional game/value filters restrict eligible products without replacing the exact amount check.

Used, revoked and expired codes receive distinct messages. A definitely invalid or mismatched unconsumed code may be replaced. A different code cannot overwrite an in-flight, uncertain or manually reviewable submission. A repeated identical code reads the existing result.

Unknown/not-found or unavailable verification enters genuine typed-code manual review. Distinguish saved/queued evidence from delivered staff review, and do not misrepresent an outage as a known invalid card. Authorized current receipt-review staff can retrieve the protected code privately and use the existing human approval/rejection authority. Never invent `receipt_file_id` or impersonate an administrator to satisfy a photo-only guard.

## Durable consumption and recovery

Source-only additive migration014 extends the existing shared ledger with nullable evidence fields. It does not backfill old invoices, replace receipt stages/leases, create a provider registry, or alter issued009 identity pins. In particular, original `payment_id`, `provider_reference` and stable `checkout_url` facts remain immutable. API absence/partial schema fails the API path closed; unpinned manual paths remain usable.

Before an external mutation, commit evidence identity, original expected snapshot, operation key/reference and dispatched intent. Perform network requests outside SQL transactions. Same-operation retries keep the original identity. Timeout, HTTP5xx, lease expiry or process restart is not permission to create a new consume operation.

Pay Manager saves action-idempotency responses for24 hours. Recovery outside the proven replay window remains reviewable instead of blindly executing an old uncertain action. For gift cards, use a new transport nonce for each request and retain the business reference. A card restored active after an uncertain consume is not permission to consume again; existing APIs cannot prove the original consumption epoch in that case.

Protect credentials, policy snapshots and codes with purpose-separated authenticated encryption using the existing webhook secret. Credential edits affect new invoices; old invoices retain their pinned recovery configuration. Webhook-secret rotation requires a deliberate recovery plan: failed decryption does not silently turn a pinned invoice into a legacy one or discard its evidence. Validate trusted HTTPS endpoints, disable redirects, bound transport/response size, authenticate the exact signed request and use integer arithmetic.

A real authenticated stored API proof supports the automatic local actor; actor0 alone never grants financial authority. Restarted processing must reuse the existing native journals. An uncertain dispatched native effect cannot be replayed as a new operation.

Capture authenticated provider and native add-on eligibility outside SQL transactions, then compare the relevant local quote, connection and incarnation before and after the observation and under the financial claim. A stale local expiry cannot authorize an expired native volume add-on. Unknown/ineligible observations retain truthful review without a new native dispatch. A sealed, verified effect recovers locally without requiring a new native read or repeating its mutation.

## Notifications and message maintenance

| Event | Owner | Manager receiving ON | Operator/Support receiving ON | Report channel |
| --- | --- | --- | --- | --- |
| General report | Yes | Yes | No | Where intended |
| Automatic financial change or evidence reconciliation | Yes | Yes | No | Safe complete report |
| Actionable manual receipt/code review | Yes | Yes | Yes | Existing evidence privacy boundary |
| Private diagnostic | Yes | Yes | No | No |
| Backup archive | No new copy | No new copy | No new copy | Existing backup destination only |

Automatic reports identify customer, invoice, exact amount/unit, method, relevant safe evidence/operation reference and truthful outcome. Report substantive state changes rather than every unchanged poll. Never leak bearer codes or credentials. Delivery failure cannot roll back a committed payment; retain retryable notification/projection state.

Edit existing valid staff receipt copies to remove stale actions after either winner settles, even when their new-copy receiving preference is OFF. Exclude revoked, banned or ambiguous members. Direct responses to a currently authorized actor do not constitute unsolicited fanout. Financial action permissions remain unchanged.

A temporarily unreadable staff authority record leaves its projection unacknowledged for the next ordinary maintenance pass. Definitively excluded recipients do not prevent completion. Durable renewal/gateway uncertainty alerts acknowledge the complete eligible fanout, including deferred admission and transport failures, while preserving their evidence-generation CAS and lease ownership.

Use the two existing Persian catalogs. Separate payment verified/effect pending from completed service or wallet delivery. Show precise customer actions and avoid asking for another payment when the outcome is unknown. Projection maintenance must make bounded fair progress across invoices and survive Telegram failures/restarts.

## Native gift correction and Owner policy

The companion source slice fixes direct gifts by retaining transient transport credentials without persisting them, preserving local expiry/notification metadata for quota-only changes and comparing time increases with the adapter's verified native baseline. This does not identify the cause, provider, installed version or actual outcome of the Owner's screenshots/request69. Never replay that uncertain gift or erase its evidence.

Verified numeric-zero Marzban expiry is supported for a pinned, finite-quota, volume-only gift; local expiry/notif are preserved and replay performs no second mutation. Duration edits and non-gift result validation retain their existing guards. PasarGuard zero-to-NULL semantics are unchanged. Existing held records are not cleared or replayed by this source correction.

The Owner resolved the remaining gift choices on2026-10-30 ([Issue57 revision5](https://github.com/ach1992/bot-refactor/issues/57#issuecomment-6521175942)). Administrator gifts do not depend on the purchase plan, including genuine planless imports. Apply the gift ONCE to the physical native account and send one notice to each current linked customer; multiple customer references must not multiply the native change. Expiry/quota displayed to customers come from the panel. Preserve purchase history and financial records, validated current account identity, replay/uncertainty controls and only necessary internal lifecycle state. Local expiry remains unchanged for gifts; a verified positive-day gift re-arms the warning marker for admitted current references, while quota-only gifts preserve that marker. Native result and recipient identity evidence belong to the gift operation journal, separate from purchase history. This source extension requires focused shared/import regressions, current exact-candidate checks and independent delta review.

The direct wizard uses one deterministic current reference as its anchor and freezes the admitted cohort for the operation. Existing explicit same-provider/panel-group authority may identify aliases; URL/credential resemblance is not shared-panel proof. Distinct current customer IDs receive distinct notices, with canonical IDs and exact persisted recipient lookup. Cached Marzban proxy UUID differences do not establish different native accounts. Genuine planless imports require their existing nonfinancial origin/incarnation proof, not a zero price or missing plan alone.

Imported Marzban gifts pin the saved username/creation identity through every existing authenticated current/pre-effect/readback GET, without an extra native request. PasarGuard keeps its established account/incarnation anchor. An observed identity change remains held without replay. Marzban provides no atomic conditional PUT, so an external recreation in the final GET-to-PUT interval remains a provider limitation; source validation does not establish transactional native guarantees.

Gift SQL phases lock admitted order primary keys in ascending order before anchor/journal/provider fences, so shared aliases do not invert direct-versus-bulk completion order. Mutable PasarGuard order/effect/connection identity is checked against CURRENT primary-key fences; the existing immutable import-origin validation remains, without an unindexed locking scan of unrelated settings. Warning/history timestamps are not native identity. Transactions finish before native requests. This changes no financial policy or schema.

Successful bulk holds become compact completed-result records in the existing setting table, retaining safe native applied values, units and admitted reference/customer identities. Existing uncertain holds remain evidence and are never cleared by this change. Customer delivery and administrator/channel reports run after native/local finalization. Their acknowledgement or exception evidence is separate from the completed gift; a message failure cannot trigger another native increment or change completed entitlement into a provider-review claim. General result reports use the configured channel plus Owner/receiving-on Manager; private batch progress stays private.

## Acceptance and activation

Validate actual PHP/socket-MariaDB/loopback behavior: exact quotes and units, mismatch exclusion, cross-invoice/actor collisions, human/API races, delayed SMS, configuration changes, uncertain consume responses, old replay windows, restarts/partial commits, all supported subjects, native-effect identity, ten-minute queued edits, code review and role/receiving routing. Preserve frozen original V0–V9, the legacy automatic bank path, backup behavior and all current financial negative controls.

A stable candidate requires full exact-HEAD PHP8.1/PHP8.4 current and detached-history gates, independent runtime/package inventory verification and author-separated review. Source checks do not establish installed acceptance.

Before activation the Owner must configure the actual SMS client/horizon/time interpretation and gift client/value unit, then authorize the specific rollout and live acceptance. No installed target, private configuration, real API verify/redeem, provider/Telegram operation, production database query/migration, Cron/PHP/OPcache change or file installation is part of this source work. Prior RC19 grants are fulfilled; staging did not establish installation. Never rerun completed012/013.
