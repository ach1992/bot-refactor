# Unified payment-message lifecycle (Issue #51)

**Scope:** source-only checkout UI and Telegram message/reference management. This does not authorize payment, financial mutation, rollout, or SQL migration.

## State and payment identity

Every order keeps its original pays ID, hash, owner, amount and type. The existing payment_attempts table (migration 003) is the authoritative claim/evidence for hosted invoices and manual transfer receipts. A Telegram button is presentation only: removing it can never settle, refund, delete or cancel an external provider claim.

States are awaiting_payment, receipt_review, needs_review, payment_recorded, expired and declined. In particular, payment_recorded DOES NOT prove Marzban service delivery or an actual wallet credit. Keep separate financial evidence and conservative Persian customer copy. Existing Persian/RTL responsive PaymentPage.php handles browser returns.

## Buttons

New checkout/receipt messages are recorded by exact Telegram chat_id and message_id when optional migration 006 is installed. An existing one-minute warning worker then edits each registered message after payment transitions or entering review, replacing pay buttons with read-only status/menu actions; text edits are preferred and markup-only deletion is fallback. Successful markup-only fallback is retried for truthful text. Every edit failure is retriable and cannot modify original transaction money or status. A microsecond-precision last-attempt timestamp rotates unsuccessful edits behind untouched messages, so a permanently uneditable message cannot block the other messages for the same payment. The bounded Cron sweep prioritizes automatic financial renewal before optional Telegram edits.

Historical Telegram messages without known message ID cannot be discovered by the Bot API. Old raw third-party URLs cannot be revoked from Telegram; never offer raw provider URLs in NEW messages. New payment URLs point only to existing bot-owned gateway endpoints which re-check the exact original pays state and newer active draft BEFORE forwarding to a provider. Unsafe, stale and owner-mismatched callbacks fail closed. Clicking an obsolete button cannot create a replacement invoice.

## Safe expiration and newer drafts

An untouched, unclaimed draft expires 30 minutes after pays.request_date, or when a newer same-user/type/order draft has actually committed. Expiry is a conditional InnoDB transaction: lock exact pays row, confirm pending, no payid, and no payment_attempts row, then update pays.state to expired without deleting it. Do not expire or refund pending provider invoices, manual receipts, processing/checking/review, paid or uncertain claims. A newer draft does NOT cancel a live upstream invoice or its financial evidence. This prevents deleting an old pay record while a new purchase or wallet-top-up INSERT can fail, especially when already inside a transaction.

## SQL and rollout boundary

Source-only additive migration: migrations/post-v9/006_payment_message_refs.sql. Columns: pay_row_id with RESTRICT FK to pays; chat_id/message_id unique; message kind, synchronized digest and timestamps. Do NOT reuse pays.message_id or pays.chat_id; they are manual receipt evidence. Migration 006 requires prior 003; absent 006 yields graceful behavior but no proactive editing of untracked history.

Before installing, require a separate Owner decision, exact versioned/checksummed SQL asset, 003/004/005/006 schema and compatibility preflight, backup + restore rehearsal, installed Marzban/Telegram checks and historical pending-invoice inventory. Do not autoapply migrations or put SQL files in the runtime ZIP. New src/Finance/PaymentLifecycle.php must be included in the runtime ZIP. No change to four-worker Cron, retired TRON or WeSwap safety policy.

## Acceptance

tools/test_post_v9_payment_ux_lifecycle.py --run-disposable exercises actual PHP on owned original 23-table MariaDB plus only the optional disposable 003 and 006 migrations, with no external provider or Telegram network requests. It checks expiry, supersession, provenance, several messages edited, Telegram retry, manual receipt evidence and competing PHP/MySQLi clients. tools/check_post_v9.py must require all named cases. Frozen V0-V9 evidence remains unchanged. Installed E2E payment/provider and any real SQL activation require separate approval.

Unresolved finance: https://github.com/ach1992/bot-refactor/issues/26
Unified payment UX: https://github.com/ach1992/bot-refactor/issues/51
