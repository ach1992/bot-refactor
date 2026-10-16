-- AChBot post-V9 #26: SHARED provider-scoped payment-attempt/evidence ledger.
-- For NEW installations only. Do NOT run on a live DB without separate
-- Owner approval, verified backup/restore, staging and schema preflight.
-- Supersedes the RC4-released, unapplied NOWPayments-only 002 proposal.
-- NEVER automatically drop/rename/backfill any pre-existing payment table.
-- See docs/post-v9/PAYMENT-LEDGER-MIGRATION.md for legacy RC4 handling.
--
-- A local pays row can have one active provider claim; every external
-- identity is namespace-scoped by provider. The quote is an immutable
-- currency+amount snapshot; settlement policy is separately snapshotted.
CREATE TABLE IF NOT EXISTS payment_attempts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  pay_row_id INT NOT NULL,
  provider VARCHAR(32) NOT NULL,
  order_hash VARCHAR(50) NOT NULL,
  provider_reference VARCHAR(128) DEFAULT NULL,
  payment_id VARCHAR(128) DEFAULT NULL,
  checkout_url VARCHAR(2048) DEFAULT NULL,
  quote_amount DECIMAL(24,8) NOT NULL,
  quote_currency VARCHAR(16) NOT NULL,
  local_amount_toman INT NOT NULL DEFAULT 0,
  settlement_currency VARCHAR(32) DEFAULT NULL,
  minimum_settlement_amount DECIMAL(24,8) NOT NULL DEFAULT 0,
  stage VARCHAR(32) NOT NULL DEFAULT 'pending',
  provider_status VARCHAR(32) DEFAULT NULL,
  last_checked_at DATETIME DEFAULT NULL,
  review_notified_at DATETIME DEFAULT NULL,
  review_notice_claim_token CHAR(32) DEFAULT NULL,
  review_notice_claimed_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_payment_attempt_pay_row (pay_row_id),
  UNIQUE KEY uq_payment_attempt_order_hash (order_hash),
  UNIQUE KEY uq_payment_attempt_ref (provider,provider_reference),
  UNIQUE KEY uq_payment_attempt_payment (provider,payment_id),
  UNIQUE KEY uq_payment_attempt_provider (id,provider),
  KEY idx_payment_attempt_reconcile (provider,stage,last_checked_at),
  KEY idx_payment_attempt_alerts (provider,stage,review_notified_at,review_notice_claimed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One invoice can have several provider-reported child payments.
-- Even when two providers reuse the same reference, identities cannot collide.
-- The composite FK prevents attaching a child to the wrong provider.
CREATE TABLE IF NOT EXISTS payment_transactions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  attempt_id BIGINT UNSIGNED NOT NULL,
  provider VARCHAR(32) NOT NULL,
  payment_id VARCHAR(128) NOT NULL,
  observed_status VARCHAR(32) DEFAULT NULL,
  received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_payment_transaction_reference (provider,payment_id),
  KEY idx_payment_transaction_attempt (attempt_id,provider),
  CONSTRAINT fk_payment_transaction_attempt
    FOREIGN KEY (attempt_id,provider) REFERENCES payment_attempts(id,provider)
    ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
