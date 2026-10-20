-- AChBot #51: source-only Telegram payment-message reference registry.
-- Separate future installation gate: Owner approval, verified backup/restore,
-- exact source package and tenant migration order (003 before 006).
-- Never reuse pays.message_id/chat_id: those store real manual-receipt evidence.
-- No historic Telegram messages can be backfilled without exact known IDs.
-- The registry stores only numeric Telegram message references and a digest,
-- not Telegram tokens, provider invoice URLs, or payment details.
-- Do not autoapply, backfill, drop, truncate, or cascade financial evidence.
CREATE TABLE payment_message_refs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  pay_row_id INT NOT NULL,
  chat_id BIGINT NOT NULL,
  message_id INT NOT NULL,
  kind VARCHAR(8) COLLATE utf8mb4_bin NOT NULL DEFAULT 'text',
  synced_digest CHAR(64) COLLATE utf8mb4_bin DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  -- Microsecond precision prevents same-second failed edits from starving
  -- later messages when a large payment has many Telegram references.
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_payment_message_chat (chat_id,message_id),
  KEY idx_payment_message_order (pay_row_id,updated_at),
  CONSTRAINT fk_payment_message_pay FOREIGN KEY (pay_row_id)
    REFERENCES pays(id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
