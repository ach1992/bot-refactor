-- AChBot #26: durable, per-order/cycle renewal effect reservation.
-- SOURCE-ONLY migration; never apply on any tenant without separate Owner
-- authorization, preflight, proved backup/restore, and staged rollout.
-- Requires the unchanged original 23-table schema, not legacy RC4 002.
-- No IF NOT EXISTS: an incompatible existing table must fail closed.
-- Existing pays/orders/users records and past renewal history are untouched.
CREATE TABLE automatic_renewal_attempts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id INT NOT NULL,
  cycle_expire INT NOT NULL,
  pay_row_id INT NOT NULL,
  user_id BIGINT NOT NULL,
  plan_id INT NOT NULL,
  server_id INT NOT NULL,
  account_remark VARCHAR(100) NOT NULL,
  price_toman INT NOT NULL,
  duration_days INT NOT NULL,
  volume_gb INT NOT NULL,
  new_expire INT NOT NULL,
  stage VARCHAR(32) COLLATE utf8mb4_bin NOT NULL DEFAULT 'reserved',
  reason_code VARCHAR(32) COLLATE utf8mb4_bin DEFAULT NULL,
  review_notified_at DATETIME DEFAULT NULL,
  review_claim_token CHAR(32) COLLATE utf8mb4_bin DEFAULT NULL,
  review_claimed_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_renewal_cycle (order_id,cycle_expire),
  UNIQUE KEY uq_renewal_payment (pay_row_id),
  KEY idx_renewal_inflight (order_id,stage),
  KEY idx_renewal_review (stage,updated_at,review_notified_at),
  CONSTRAINT fk_renewal_order FOREIGN KEY (order_id)
    REFERENCES orders_list(id) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_renewal_pay FOREIGN KEY (pay_row_id)
    REFERENCES pays(id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
