-- Issue #117: source-only, one-shot post-COMMIT presentations.
-- No production deployment or automatic backfill. Apply only after 018/019,
-- verified backup+restore, exact schema audit and separate Owner approval.
-- Each destined Telegram SEND is claimed before network I/O. An unknown
-- response is never automatically resent. Each receipt EDIT is independently
-- captured and a failed edit cannot suppress other pending recipients.
CREATE TABLE payment_followup_intents (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  pay_row_id INT NOT NULL,
  intent_key CHAR(64) COLLATE utf8mb4_bin NOT NULL,
  stage VARCHAR(32) COLLATE utf8mb4_bin NOT NULL,
  kind VARCHAR(8) COLLATE utf8mb4_bin NOT NULL,
  role VARCHAR(24) COLLATE utf8mb4_bin NOT NULL,
  recipient VARCHAR(128) COLLATE utf8mb4_bin NOT NULL,
  message_id INT DEFAULT NULL,
  pay_fingerprint CHAR(64) COLLATE utf8mb4_bin NOT NULL,
  sealed_payload LONGTEXT COLLATE utf8mb4_bin NOT NULL,
  payload_sha256 CHAR(64) COLLATE utf8mb4_bin NOT NULL,
  state VARCHAR(24) COLLATE utf8mb4_bin NOT NULL DEFAULT 'pending',
  attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
  dispatch_token CHAR(32) COLLATE utf8mb4_bin DEFAULT NULL,
  dispatched_at DATETIME DEFAULT NULL,
  acknowledged_at DATETIME DEFAULT NULL,
  last_result VARCHAR(36) COLLATE utf8mb4_bin DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_followup_pay_key (pay_row_id,intent_key),
  KEY idx_followup_pending (state,updated_at),
  CONSTRAINT fk_followup_payment FOREIGN KEY (pay_row_id)
    REFERENCES pays(id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
