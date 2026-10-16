-- AChBot #26 additive proposal; do not run on real aaPanel without approval.
-- The immutable V0-V9 DDL and original tables remain unchanged.
CREATE TABLE IF NOT EXISTS achbot_nowpayments_attempts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  pay_row_id INT NOT NULL,
  order_hash VARCHAR(50) NOT NULL,
  invoice_id VARCHAR(128) NOT NULL,
  payment_id VARCHAR(128) DEFAULT NULL,
  invoice_url VARCHAR(2048) NOT NULL,
  quote_usd DECIMAL(18,2) NOT NULL,
  stage VARCHAR(32) NOT NULL DEFAULT 'pending',
  provider_status VARCHAR(32) DEFAULT NULL,
  last_checked_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_achbot_np_payment_row (pay_row_id),
  UNIQUE KEY uq_achbot_np_order_hash (order_hash),
  UNIQUE KEY uq_achbot_np_invoice (invoice_id),
  UNIQUE KEY uq_achbot_np_payment_id (payment_id),
  KEY idx_achbot_np_reconcile (stage,last_checked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
