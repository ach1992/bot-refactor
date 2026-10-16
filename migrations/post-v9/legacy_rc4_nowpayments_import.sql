-- OPTIONAL ONE-TIME RC4 -> SHARED LEDGER DATA COPY, NOT A FRESH INSTALL.
-- Never apply automatically or to a live database without explicit Owner
-- migration approval, independently restored backup and a verified quiesced
-- staging rehearsal. See docs/post-v9/PAYMENT-LEDGER-MIGRATION.md.
--
-- REQUIRED preconditions: both old RC4 tables have exact released RC4 DDL;
-- both new 003_payment_attempts.sql tables exist and are EMPTY; no payment,
-- IPN, webhook, reconciliation or Cron writer is active during this COPY.
-- Validate old/new row counts, unique identifiers and unmatched pays rows.
-- Use a MariaDB CLI configured to ABORT ON FIRST ERROR. Do not continue to
-- COMMIT after a failed statement; explicitly ROLLBACK. Do NOT DROP tables.
--
-- Preserve primary keys, original provider correlations, all alert leases,
-- precise quote/merchant payout amounts, multi-child evidence and dates.
START TRANSACTION;
INSERT INTO payment_attempts (
    id,pay_row_id,provider,order_hash,provider_reference,payment_id,
    checkout_url,quote_amount,quote_currency,local_amount_toman,
    settlement_currency,minimum_settlement_amount,stage,provider_status,
    last_checked_at,review_notified_at,review_notice_claim_token,
    review_notice_claimed_at,created_at,updated_at
)
SELECT
    id,pay_row_id,'nowpayments',order_hash,invoice_id,payment_id,
    invoice_url,quote_usd,'usd',local_amount_toman,
    expected_outcome_currency,minimum_outcome_amount,stage,provider_status,
    last_checked_at,review_notified_at,review_notice_claim_token,
    review_notice_claimed_at,created_at,updated_at
FROM achbot_nowpayments_attempts;

INSERT INTO payment_transactions (
    id,attempt_id,provider,payment_id,observed_status,received_at
)
SELECT
    id,attempt_id,'nowpayments',payment_id,observed_status,received_at
FROM achbot_nowpayments_child_payments;
COMMIT;

-- After COMMIT, separately compare row counts, IDs, all financial fields,
-- child count and provider-scoped uniqueness; never remove original tables
-- before explicit review and evidence that rollback/forward is safe.
