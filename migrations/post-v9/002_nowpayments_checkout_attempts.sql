-- AChBot #26 additive proposal; do not run on real aaPanel without approval.
-- The immutable V0-V9 DDL and original tables remain unchanged.
CREATE TABLE IF NOT EXISTS achbot_nowpayments_attempts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  pay_row_id INT NOT NULL,
  order_hash VARCHAR(50) NOT NULL,
  -- Nullable until the provider returns its invoice. The local row must
  -- exist BEFORE the non-idempotent external create request.
  invoice_id VARCHAR(128) DEFAULT NULL,
  payment_id VARCHAR(128) DEFAULT NULL,
  invoice_url VARCHAR(2048) DEFAULT NULL,
  quote_usd DECIMAL(18,2) NOT NULL,
  -- Snapshot the exact local Toman obligation and operator-approved payout
  -- amount and asset/network AT ISSUANCE. Zero/unset means never auto-settle.
  local_amount_toman INT NOT NULL DEFAULT 0,
  expected_outcome_currency VARCHAR(32) DEFAULT NULL,
  minimum_outcome_amount DECIMAL(24,8) NOT NULL DEFAULT 0,
  stage VARCHAR(32) NOT NULL DEFAULT 'pending',
  provider_status VARCHAR(32) DEFAULT NULL,
  last_checked_at DATETIME DEFAULT NULL,
  review_notified_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_achbot_np_payment_row (pay_row_id),
  UNIQUE KEY uq_achbot_np_order_hash (order_hash),
  UNIQUE KEY uq_achbot_np_invoice (invoice_id),
  UNIQUE KEY uq_achbot_np_payment_id (payment_id),
  KEY idx_achbot_np_reconcile (stage,last_checked_at),
  KEY idx_achbot_np_alerts (stage,review_notified_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A single hosted invoice may receive multiple distinct child deposits.
-- Persist EVERY signed additional child ID before acknowledging its IPN;
-- a one-slot review_payment_id cannot preserve a third or later deposit.
CREATE TABLE IF NOT EXISTS achbot_nowpayments_child_payments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  attempt_id BIGINT UNSIGNED NOT NULL,
  payment_id VARCHAR(128) NOT NULL,
  observed_status VARCHAR(32) DEFAULT NULL,
  received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_achbot_np_extra_payment (payment_id),
  KEY idx_achbot_np_extra_attempt (attempt_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
