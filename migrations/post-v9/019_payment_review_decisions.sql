-- Issue #117: source-only, additive manual NOWPayments decision audit.
-- NOT applied to live Chili. Requires separate migration approval/restore.
-- Unique pay lock forbids a second operator-driven effect after crash.
CREATE TABLE payment_review_decisions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  pay_row_id INT NOT NULL,
  attempt_id BIGINT UNSIGNED NOT NULL,
  decision VARCHAR(16) COLLATE utf8mb4_bin NOT NULL,
  actor_id BIGINT NOT NULL,
  payment_id VARCHAR(128) COLLATE utf8mb4_bin NOT NULL,
  provider_status VARCHAR(32) COLLATE utf8mb4_bin NOT NULL,
  evidence_sha256 CHAR(64) COLLATE utf8mb4_bin NOT NULL,
  state VARCHAR(24) COLLATE utf8mb4_bin NOT NULL,
  outcome VARCHAR(32) COLLATE utf8mb4_bin DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at DATETIME DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_review_decision_pay(pay_row_id),
  KEY idx_review_decision_attempt(attempt_id),
  CONSTRAINT fk_review_decision_pay FOREIGN KEY (pay_row_id)
    REFERENCES pays(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_review_decision_attempt FOREIGN KEY (attempt_id)
    REFERENCES payment_attempts(id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
