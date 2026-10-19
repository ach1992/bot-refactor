# Unified payment-message lifecycle (Issue #51)

**Scope:** source-only checkout UI and Telegram message/reference management. This does not authorize payment, financial mutation, rollout, or SQL migration.

## State and payment identity

Every order keeps its original pays ID, hash, owner, amount and type. The existing payment_attempts table (migration 003) is the authoritative claim/evidence for hosted invoices and manual transfer receipts. A Telegram button is presentation only: removing it can never settle, refund, delete or cancel an external provider claim.

States are awaiting_payment, receipt_review, needs_review, payment_recorded, expired and declined. In particular, payment_recorded DOES NOT prove Marzban service delivery or an actual wallet credit. Keep separate financial evidence and conservative Persian customer copy. Existing Persian/RTL responsive PaymentPage.php handles browser returns.

## Buttons

New checkout/receipt messages are recorded by exact Telegram chat_id and message_id when optional migration 006 is installed. An existing one-minute warning worker then edits each registered message after payment transitions or entering review, replacing pay buttons with read-only status/menu actions; text edits are preferred and markup-only deletion is fallback. Successful markup-only fallback is retried for truthful text. Every edit failure is retriable and cannot modify original transaction money or status. A microsecond-precision last-attempt timestamp rotates unsuccessful edits behind untouched messages, so a permanently uneditable message cannot block the other messages for the same payment. The bounded Cron sweep prioritizes automatic financial renewal before optional Telegram edits.

Historical Telegram messages without known message ID cannot be discovered by the Bot API. Old raw third-party URLs cannot be revoked from Telegram; never offer raw provider URLs in NEW messages. New payment URLs point only to existing bot-owned gateway endpoints which re-check the exact original pays state and newer active draft BEFORE forwarding to a provider. Unsafe, stale and owner-mismatched callbacks fail closed. Clicking an obsolete button cannot create a replacement invoice.

## Safe expiration and newer drafts

A genuinely untouched unclaimed draft expires after **30 minutes by default**, or when a newer same-user/payment-family/order draft has actually committed. Its duration is configurable with bounded administrative BOT_STATES setting paymentDraftMinutes (5..180, default 30).

**Issued MANUAL invoice:** the pinned bank-account / gift seller HTTPS link / selected USDT- or TRX-network destination in 003 payment_attempts is retained. If the customer has NOT clicked the exact “send receipt / gift card” Telegram button within **6 hours of attempt creation** (setting paymentManualHours, 1..72 hours), a serializable pays-then-attempt InnoDB lock verifies the absence of provider_status, receipt lease, photo evidence and child transactions, then updates the pays record and attempt.stage to expired **without deleting evidence**. The original quote/identifier remain for audit/support. A sender may have transferred funds out of band: expiry never certifies no funds or authorizes refund, and the visible customer text asks to contact support with proof. If the customer clicks that button before expiry, a durable provider_status=receipt_started marker is recorded. This is an UPLOAD INTENT ONLY (not transfer/receipt/credit/provision evidence); the existing photo receiving/review process remains separately protected. Legacy step-only/stale clickable message cannot bypass source-real age/owner/method guards. Prior photo evidence, receiving or review is never expired by the idle worker.

**Issued HOSTED invoice:** opening the bot-owned checkout URL is observable but **not payment evidence**. After **6 hours by default** (setting paymentHostedHours, 1..72 hours) the bot-owned URL and registered Telegram buttons are replaced with read-only status/support, but the real upstream invoice can still be payable. Financial pays/payid, 003 payment_attempts and any payment_transactions stay immutable and pending/review for reconciliation with the provider. There is no automatic upstream cancellation, refund, double-issue or inference of failure. Provider-specific finality and eventual settlement are managed only by authenticated provider evidence and Issue #26.

**Audience and anti-duplicate settings:** the administrator's gateway settings include paymentDraftMinutes, paymentManualHours, paymentHostedHours, receiptApprovedMinutes (10), receiptApprovedOtherMinutes (60), receiptReviewMinutes (360) and receiptDeclinedMinutes (3), with bounded validation. Missing/invalid keys retain legacy-safe defaults. An independent gatewayAudience map per method can target all, agents, non-agents, previous BUY_SUB buyers, non-buyers, active or inactive service owners, or off; unknown policy is denied. It is checked at the first-party gateway URL, Telegram callback, method selection, wallet debit and manual-claim boundary, not only hidden from keyboards. Existing issued provider claims and async settlement evidence are preserved when access changes. No setting disables monetary once-only, admin rights or provider reconciliation.
 Expiry is a conditional InnoDB transaction: lock exact pays row, confirm pending, no payid, and no payment_attempts row, then update pays.state to expired without deleting it. Do not expire or refund pending provider invoices, manual receipts, processing/checking/review, paid or uncertain claims. A newer draft does NOT cancel a live upstream invoice or its financial evidence. This prevents deleting an old pay record while a new purchase or wallet-top-up INSERT can fail, especially when already inside a transaction.

## SQL and rollout boundary

Source-only additive migration: migrations/post-v9/006_payment_message_refs.sql. Columns: pay_row_id with RESTRICT FK to pays; chat_id/message_id unique; message kind, synchronized digest and timestamps. Do NOT reuse pays.message_id or pays.chat_id; they are manual receipt evidence. Migration 006 requires prior 003; absent 006 yields graceful behavior but no proactive editing of untracked history.

Before installing, require a separate Owner decision, exact versioned/checksummed SQL asset, 003/004/005/006 schema and compatibility preflight, backup + restore rehearsal, installed Marzban/Telegram checks and historical pending-invoice inventory. Do not autoapply migrations or put SQL files in the runtime ZIP. New src/Finance/PaymentLifecycle.php must be included in the runtime ZIP. No change to four-worker Cron, retired TRON or WeSwap safety policy.

## Acceptance

tools/test_post_v9_payment_ux_lifecycle.py --run-disposable exercises actual PHP on owned original 23-table MariaDB plus only the optional disposable 003 and 006 migrations, with no external provider or Telegram network requests. It checks expiry, supersession, provenance, several messages edited, Telegram retry, manual receipt evidence and competing PHP/MySQLi clients. tools/check_post_v9.py must require all named cases. Frozen V0-V9 evidence remains unchanged. Installed E2E payment/provider and any real SQL activation require separate approval.

Unresolved finance: https://github.com/ach1992/bot-refactor/issues/26
Unified payment UX: https://github.com/ach1992/bot-refactor/issues/51
