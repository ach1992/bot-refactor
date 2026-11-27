# API-assisted manual payments

This is the current optional authenticated bank-SMS and gift-card receipt contract. [Wallet-first architecture](WALLET-FIRST-ARCHITECTURE.md) owns credit, funding and service recovery; the [operator inbox](AUTOMATIC-GATEWAY-REVIEW.md) owns manual decisions. Verify installed API scope and configuration separately.

## Scope and existing contracts

The runtime retains optional verification within the existing manual bank-transfer and gift-card methods. Each has separate private API settings and an adjacent independent switch, absent/default OFF. OFF retains the original manual IMAGE journey. Bank transfers use the same receipt submission in either mode. API ON gift cards use a typed code within the same subject-specific review path; API OFF gift cards still require an image. Only matching API evidence or authorized staff confirmation can settle a receipt. This ownership check is repeated under the settlement lock.

Use the existing authenticated ACh Pay Manager v1 and ACh Gift Cards for Woo v2 APIs. Their ready/active-to-used/redeemed persistence is the selected consumption contract. No plugin change, new external invoice registry, exclusivity declaration, or mandatory filter wizard is a prerequisite. The bot remains the invoice authority. AChBot does not install or upgrade those WordPress plugins; verify their actual deployed APIs, credentials, scopes and live behavior separately.

Receipt evidence uses `payment_attempts` and child `payment_transactions`; authenticated proof enters the common Wallet credit transaction. An HTTP response, verified code, or payment confirmation is not proof of completed native delivery.

## Current operational diagnostics (no payment-policy change)

The private PHP error log receives structured `[AChBot]` JSON events for
`achbot.finance.evidence_check`, `achbot.finance.evidence_http`,
`achbot.finance.evidence_tick`, and the shared Native/wallet/delivery recovery
boundaries. Each event includes UTC microsecond timestamp, a process-local
`trace_id`, installation label, and only applicable typed identifiers,
stage/result/reason, HTTP status, cURL errno and safe upstream correlation.
Recognized API machine codes remain visible. A raw upstream request ID is
logged only for a valid provider response envelope and its documented
producer-generated ID format; other nonempty values are represented by a
`sha256:` fingerprint, never the original response text. Unknown status
strings become `unrecognized_upstream_code`. This filtering changes no
verification, matching, settlement or retry result.
The checking coordinator scopes `pay_id` to each original invoice while it
calls the evidence API. A failed Telegram ACK is recorded as unknown, not
delivered. The existing aaPanel/system log rotation policy remains the owner
of disk retention; no separate unbounded AChBot log file is introduced.

A confirmed SMS purchase must not be mistaken for a pending receipt
merely because its historic `payment_attempts.stage=review` or
`provider_status=receipt_started` remains intact. Reconcile original
`pays.state=auto_cart_approved`, the proven SMS transaction, the linked
service effect, original order and acknowledged delivery separately from
current staff followup attempt acknowledgements and customer
`payment_message_refs.synced_digest`. These only prove that Telegram
presentation was acknowledged. The retired `evidence_staff_digest` and
`review_notice_claimed_at` are retained in verified historical source
archives, not in the current runtime schema. After an actual original settlement, update
that payment's registered messages immediately; if Telegram is unavailable,
retain unsynced references for the bounded fair recovery queues, without
re-consuming the SMS or altering the financial evidence. When Telegram reports
`message is not modified` for an identical original invoice edit or staff
keyboard update, treat it as a valid edit ACK and persist the presentation
digest; do not turn an edit no-op into proof of a customer payment or new
message delivery. Rejections and transport failures retain diagnostic
categories so support can distinguish an unavailable recipient or stale
message from a delayed presentation queue.

A Cron tick marked successful is **not** proof that a particular original
invoice was admitted or that its SMS was found. Correlate
`evidence_tick.selected/checked/deferred`, each
`evidence_check.pay_id` result, and `evidence_http.pay_id`/HTTP reason;
compare the original immutable invoice and independent provider action
history before any financial reconciliation. Missing historical per-invoice
events cannot be retroactively inferred. Private event logging does not
alter SMS matching windows, exact amount, polling cadence, worker budgets,
claims, provider consumption, service effects or a manual staff outcome.
Do not include raw SMS, credentials, Telegram text, customer usernames,
gateway URLs or unvalidated external response content in diagnostic events.

## Configuration before API enablement

Use Gateway/Channel Settings to configure each API independently beneath its existing manual payment method. Keep `smsApiVerifyState` and `giftApiVerifyState` OFF while preparing the target.

| Method | Required private configuration | Optional restrictions/interpretation |
| --- | --- | --- |
| Bank SMS | `smsApiUrl` ending in /wp-json/ach-pay-manager/v1; encrypted `smsApiToken` | Provider, sender, mobile source, rule ID and bank key filters; timezone/calendar when source timestamps need them |
| Gift card | `giftApiUrl` ending in /wp-json/ach-gcw/v2; `giftApiKeyId`; encrypted `giftApiSecret`; `giftApiAmountUnit` exactly rial or toman | Game key and redemption-value ID filters |

Use the actual trusted HTTPS WordPress API base on its default port or 443, including any installation subdirectory, without query/fragment, embedded credentials or redirects. Do not assume the APIs are reachable or authenticated because their settings show complete. Existing method switches and eligibility still apply; these API toggles do not enable a previously disabled payment method.

Before either API switch is enabled, an authorized operator must verify the exact installed runtime against the trusted manifest and the mandatory consolidated current schema, working OpenSSL AES-256-GCM and the correct per-bot webhook secret. Use [fresh installation](FRESH-INSTALL.md) and the [current read-only preflight](PAYMENT-READINESS-PREFLIGHT.md). The preflight does not decrypt or test the live API profile. Never apply historical 014 or earlier migrations to make this runtime ready.

The shared payment policy pins the transfer window from 180 seconds before original issuance to the original invoice deadline (six hours by default), independently of the search window. Automatic SMS search may continue for the configured post-expiry duration (24 hours by default); it does not extend the transfer window or revive a closed service. Reliable timestamp interpretation and an exact source/amount match are still required without optional filters. Gift funding always uses redeem_amount; choose its actual unit rather than inferring it from a face-value/display label. Enable and live-test each API only under the separately approved rollout/acceptance plan. Current unpinned manual receipts retain their configured policy; current pinned invoices retain their original profile when the new-issuance switch is turned OFF. Historical unsealed rows are not executable.

## Current operator exceptions

The original API receipt links to the shared operations-attention detail when genuinely unresolved. Manual receipt decisions and authenticated API recovery share the original receipt-credit transaction and session lease. Reject an unverified receipt only if no uncertain or consumed API evidence prevents it. Never discard genuine proof or re-consume an item after an unknown response. Funded operations use the same three service decisions regardless of payment method; Telegram delivery remains independently recoverable. There is no archive action or state. See [shared operator contract](AUTOMATIC-GATEWAY-REVIEW.md).

Same-amount SMS uniqueness now scans **all** possible originally issued
local quotes in small ordered locked pages. The page size limits memory
per read, not the number of invoices accepted: it never fails an otherwise
unique real SMS merely because over 200 invoices share the same Toman
amount. Exact amount/source/time, collision rejection and once-only remote
consume remain mandatory.

## Bank-transfer matching and journey

New API-pinned bank invoices retain the accepted administrator-evidence-first boundary. Earlier issued profiles retain their own immutable pinned policy and recovery configuration; they are not retroactively converted. Verify the actual installed schema and outstanding original operations before any separately approved activation or change.

The original full invoice amount, including its randomized trailing three digits, is immutable. A candidate must equal that complete amount after exact, explicit rial/toman conversion. Never match only the suffix, regenerate the quote, round, or tolerate a difference. A different amount does not enter the invoice candidate list and must cause zero remote mutation.

Read ready SMS only for new matching. Preserve immutable remote identity, configured source filters and reliable transfer time within the immutable original transfer window. Missing/unparseable time, unsupported units and ambiguous same-amount local invoices remain unconfirmed. Optional timezone/calendar settings permit interpreting timestamps without an offset; ingestion time is not a substitute for transfer time. A used status alone never confirms an invoice.

**Indexed bank-SMS lookup (current Stable source):** Before exact-ID readback or status mutation, the bot queries `GET /messages` with the **original pinned full quote** (`amount`) and its exact `display_unit`. It checks `toman` and, if representable, the same amount in `rial`. Its `received_from` is an offset-aware UTC instant exactly **180 seconds before original payment_attempts.created_at issuance**, not before receipt upload or the current Cron run. This filters immutable WordPress ingestion (`created_at`), **not** SMS-embedded transfer time. Do not use a `received_before` ceiling: delayed SMS ingestion may be legitimate even when the transfer occurred within the original transfer window. Independent transfer-time, status, provider, identity, full quote and uniqueness checks remain decisive.

On a WordPress rollback-hour ambiguity, remove only the time filter and retain indexed amount/unit filters. On an explicitly unsupported/ignored filter response from an older Pay Manager, use the previous bounded unfiltered cursor scan rather than treat a partial page as absence of payment. A network timeout, malformed response or provider outage cannot mean no payment or authorize a new SMS consume. The existing primary admin receipt gate, durable evidence operation, same-key redeem replay, human/API winner semantics and financial settlement remain unchanged. Current source and the Owner-reported resolution of the previous Vivan SMS delay do not certify every external payment or Telegram path; installed acceptance remains separate.

### Original issuance-time matching and receipt-first review policy

Eligibility remains tied to the **original pinned invoice issue time**, never the later receipt submission. A bank transfer may precede that issuance by at most **180 seconds**; this guards minute-only SMS and small provider clock skew without changing the original *post-issuance* monitoring horizon. A source that reports HH:MM represents the entire minute [HH:MM:00, HH:MM:59]; a source with explicit seconds or timezone-offset timestamp remains exact. The SMS time interval must overlap [issued_at - 180 seconds, issued_at + configured_horizon]. Exact full amount, source identity, configured filters, ready status, same-amount local collision rejection, and once-only evidence consumption still apply. This exception applies to **bank SMS only**; gift-card verification does not use issuance-time matching.

Always offer **Submit bank receipt** immediately. Persist the actual receipt and deliver its established actionable caption/keyboard to the primary administrator before any new API request or consumption. No Check payment button or ten-minute fallback is exposed. A failed/queued primary Telegram delivery leaves the same durable evidence retryable, without external verification. Secondary staff copies retain current routing. After successful delivery, check the pinned API and continue background reconciliation; unsent invoices cannot occupy the evidence polling batch. The customer sees the established receipt acknowledgement BEFORE this request performs optional API verification, followed by the normal final payment status. A concurrent winner is not acknowledged again; failure of the customer acknowledgement does not revoke the delivered staff evidence or block financial reconciliation.

New profiles pin `review_first`; claim and new remote-operation reservation enforce the delivered-evidence boundary. Existing in-flight operations/proven evidence retain their original recovery path, without minting a new consumption. Preserve the pinned exact amount, source, timestamps and destination. Queued invoice edits recheck current financial state.

Human approval and verified API settlement compete for the same authoritative local claim. A manually settled invoice is terminal for wallet credit, native effects, capacity and referral rewards. A later exact ready SMS can be consumed for evidence only; it must not reopen confirmation or repeat any effect. Already-used SMS do not re-enter new matching. Preserve records of original dispatch and consumption even after configuration changes.

A manually rejected invoice followed by contradictory payment is reviewable. Do not silently reverse rejection, refund or fulfill it. After automatic monitoring ends, retain the manual invoice/review journey; monitoring policy does not delay receipt submission.

## Gift-card matching and journey

API OFF retains screenshot submission and its original human review. API ON replaces only the evidence input with **Submit gift-card code**, keeping the same original invoice, cancellation callback, private staff review and settlement journals. Explain that submitting a code permits consumption for that exact invoice. Validate the whole escaped code plus the complete administrator caption and gift details against Telegram's single-message limit **before saving evidence or sending an admin message**; never truncate a code or fabricate a screenshot. Nonempty text such as `0` is **not** automatically an acceptable code.

**Offline structural gate (no new secrets/settings):** ACh Gift Cards for Woo v2.3.3 issues `AG2-<key-id>-<token>` codes: key ID 1–40 ASCII letters/digits/underscore, exactly 84 RFC 4648 Base32 token characters (`A-Z2-7`), usually grouped in 5-character chunks, with the final Base32 zero-pad bits intact. Preserve the issuer's grouped or compact token; accept case variants for copy/paste. Before any provider request, SQL evidence, or administrator message, reject free text, bare numbers, truncated/invalid-alphabet tokens, noncanonical padding, and extra words. The same local gate applies at input, preflight and evidence-save boundaries for every API-on gift journey; API-off original gift-photo flow remains unchanged. Rejected input retains the code-entry step and offers retry/Cancel. **This is syntax only, not signature validation or proof that a card exists.** The WordPress plugin keeps the signing/encryption keys and verifies its own HMAC through the original authenticated `/verify`; AChBot never copies the card-signing secret. During ambiguous/unavailable API results, manual staff review is available **only after the code has passed the structural gate**, without silently settling any invoice.

**Already-proven gift-code reuse (financial safety contract):** A card that was previously consumed and proven for an original AChBot invoice must never credit a second invoice, even if an issuer operator later marks the card `active` again with a WordPress unredeem operation. The original provider-identity uniqueness lock remains the financial authority. To prevent a repeated scheduled `/verify` audit storm, a matching original **proven code digest** parks the new attempt in existing manual monitoring *before* a new network request; alternative grouping of the same canonical issuer card is fenced by the original **proven provider identity** after one authenticated verification. This never erases existing encrypted evidence, fabricates settlement, prevents currently authorized staff from inspecting history, or rewrites the first payment. An operator must independently reconcile original funds, service or wallet effects before deciding any separate manual action. Verify the selected installed Stable runtime and original payment/provider evidence before relying on an affected gift-recovery path.

**Upgrade-only sealed-code recovery:** Evidence encrypted by an older installed runtime may contain a malformed code such as `0`, even though current submissions reject it. Keep its original encrypted payload, local HMAC/digest, invoice, and financial history available to authorized reconciliation. The final `/verify` admission check rejects malformed recovered codes without contacting WordPress; scheduled gift checks park those attempts in the existing `manual` state rather than generating a new review or automatic settlement. A previously delivered, authenticated staff review can still display its original historical evidence through the existing permission-checked callback. A pending retry must never send a new staff receipt for a malformed recovered code. No backfill, code deletion, payment-history rewrite, or new signing secret is required.

**Customer-first non-consuming screening:** Before any durable receipt lease or administrator disclosure, use the original signed, pinned `/verify` profile and exact original invoice quote. A reliable authenticated result proving `card_not_found`, a well-formed invalid-code response, `redeemed`/`redeemed_offline`, `revoked`, `expired`, the wrong `redeem_amount` or a well-formed mismatch against a configured product/value filter **does not go to staff**. It returns a reason-specific Persian message with **retry the code or Cancel**, without leaving the original code-entry step or changing `pays`, provider evidence, wallet or Native state. No gift redemption occurs on invalid inputs. If a provider error/status/payload is ambiguous, untrusted, incomplete, malformed, unauthorized or unavailable, keep the existing *genuine* manual staff review; never invent a known-invalid outcome from transport failure. Confirmed-good verified cards enter the normal original private staff review and existing guarded API settlement. Definitively invalid inputs are screened before staff review; ambiguous upstream failures must still retain genuine manual review.

**Administrator evidence:** The single original subject-specific private receipt message includes the complete HTML-escaped code, the verified upstream canonical `redeem_amount` in its explicitly pinned rial/toman unit when a valid exact-match API observation exists (otherwise mark as requiring manual verification), plus **WooCommerce gift-card purchase `order_number`** and **gift-card issuance `created_at`** from the same authenticated verified response. This is supported by the [ACh Gift Cards for Woo v2.3.3 API](https://github.com/ach1992/ach-gift-cards-for-woo/blob/v2.3.3/docs/EXTERNAL_API_V2.md#verify). `created_at` is the gift card's creation timestamp (displayed with its source UTC offset); **`order_created_at` is a different field** and must not be substituted. A valid API `null`/absent order number or card timestamp is explicitly displayed as **not provided by API**. The bot never substitutes its own local AChBot invoice/order ID/time or the numeric WooCommerce `order_id` for those two fields. Arbitrary WooCommerce custom order numbers are HTML-escaped, malformed values are not treated as verified metadata, and the complete **actual** message length is validated before preserving the code or claiming the staff-send lease; the whole code is never truncated. Code disclosure remains restricted to authorized private receipt reviewers; never log it or send it through report/channel notification. Publishing upstream v2.3.3 source does not verify the deployed WordPress installation has been upgraded; an older installed v2 response simply supplies fewer optional display fields.

**Replacement/recovery:** A customer can retry any definitely rejected input immediately because no code was saved or sent. Once a code has actually been accepted for potential human review, persist it encrypted **before** sending to staff and retain existing lease, exact-code identity, no-replacement-after-delivery and uncertain-effect protections. A returned HTTP status alone cannot authorize consumption or let a later code replace an in-flight/manual/uncertain original. Duplicate accepted submissions are matched to the original encrypted evidence. Approval/rejection, receipt statistics, original pay identity and financial/Native/Telegram replay protections remain unchanged.

## Durable consumption and recovery

The current schema stores the original receipt attempt, immutable evidence and proof in the shared ledger. The current issuer's immutable pin is mandatory for a new executable API receipt. The shared receipt-credit transaction and funded service engine separate local Wallet credit from Native service completion. Historical unpinned invoices are not runnable current payment commands.

Before an external mutation, commit evidence identity, original expected snapshot, operation key/reference and dispatched intent. Perform network requests outside SQL transactions. Same-operation retries keep the original identity. Timeout, HTTP5xx, lease expiry or process restart is not permission to create a new consume operation.

Pay Manager saves action-idempotency responses for 24 hours; the bot conservatively refuses an unproven SMS consume retry at or beyond 23 hours from dispatch. The monitoring horizon does not extend that recovery window. Older/unknown operations remain reviewable instead of blindly executing an uncertain action. For gift cards, use a new transport nonce for each request and retain the business reference. A card restored active after an uncertain consume is not permission to consume again; existing APIs cannot prove the original consumption epoch in that case.

Protect credentials, policy snapshots and codes with purpose-separated authenticated encryption using the existing webhook secret. Credential edits affect new invoices; old invoices retain their pinned recovery configuration. Webhook-secret rotation requires a deliberate recovery plan: failed decryption does not silently turn a pinned invoice into a legacy one or discard its evidence. Validate trusted HTTPS endpoints, disable redirects, bound transport/response size, authenticate the exact signed request and use integer arithmetic.

A real authenticated stored API proof supports the automatic local actor; actor0 alone never grants financial authority. Restarted receipt processing reuses the original receipt and proof. Service recovery is independently owned by the original funded operation.

Capture authenticated provider and native add-on eligibility outside SQL transactions, then compare the relevant local quote, connection and incarnation before and after the observation and under the financial claim. A stale local expiry cannot authorize an expired native volume add-on. Unknown/ineligible observations retain truthful review without a new native dispatch. A sealed, verified effect recovers locally without requiring a new native read or repeating its mutation.

## Migration, cutover and recovery boundary

For this replacement use the empty consolidated schema and the separately
authorized manual transfer/reconciliation contract in
[the architecture](WALLET-FIRST-ARCHITECTURE.md#cutover-decision--controlled-manual-database-transfer-owner-2026-11-24).
Migration 014 and RC19–RC21 upgrade procedures are historical release
evidence only. Do not replay old SQL, clear a claim or activate old/new
writers together.

Turning an API switch OFF affects new issuance; it is not settlement rollback and does not remove the responsibility to finish/reconcile earlier pinned invoices. After API issuance, consumption, new gift journals or other new writes, an old runtime/database can ignore recovery state or erase newer truth. Keep source, database, private secret and operation evidence paired. Prefer a reviewed roll-forward; a downgrade/restore needs its own explicit current-state plan and must never erase later financial/native evidence or replay an uncertain effect. Do not rotate the webhook secret during this transition: it is also required to decrypt saved API profiles/codes.

## Notifications and message maintenance

| Event | Owner | Manager receiving ON | Operator/Support receiving ON | Report channel |
| --- | --- | --- | --- | --- |
| General report | Yes | Yes | No | Where intended |
| Automatic financial change or evidence reconciliation | Yes | Yes | No | Safe complete report |
| Actionable manual receipt/code review | Yes | Yes | Yes | Existing evidence privacy boundary |
| Private diagnostic | Yes | Yes | No | No |
| Backup archive | No new copy | No new copy | No new copy | Existing backup destination only |

Automatic reports identify customer, invoice, exact amount/unit, method, relevant safe evidence/operation reference and truthful outcome. Report substantive state changes rather than every unchanged poll. Never leak bearer codes or credentials. Delivery failure cannot roll back a committed payment; retain retryable notification/projection state.

Edit existing valid staff receipt copies to remove stale actions after either winner settles. Label an actual API-backed automatic approval "🔑 تایید خودکار توسط API"; a human winner or later evidence-only consumption never gets that label. Preserve original actions while verification is unavailable, mismatched or uncertain. Complete these updates even when their new-copy receiving preference is OFF. Exclude revoked, banned or ambiguous members. Direct responses to a currently authorized actor do not constitute unsolicited fanout. Financial action permissions remain unchanged.

A temporarily unreadable staff authority record leaves its projection unacknowledged for the next ordinary maintenance pass. Definitively excluded recipients do not prevent completion. Durable renewal/gateway uncertainty alerts acknowledge the complete eligible fanout, including deferred admission and transport failures, while preserving their evidence-generation CAS and lease ownership.

Use the two existing Persian catalogs. Separate payment verified/effect pending from completed service or wallet delivery. Show precise customer actions and avoid asking for another payment when the outcome is unknown. Projection maintenance must make bounded fair progress across invoices and survive Telegram failures/restarts.

## Related administrator gifts

Native administrator gifts are non-financial service edits, not gift-card
receipt verification. See [direct/scheduled gift recovery](DIRECT-GIFT-RECOVERY.md).

## Acceptance and activation

Use the [current CI contract](CI-VALIDATION.md) for source-real receipt
verification, local rollback/recovery, manual/API races, uncertainty and
current projection tests. Use [readiness preflight](PAYMENT-READINESS-PREFLIGHT.md)
before separately authorized live activation. No source test proves live
merchant or Telegram acceptance.
