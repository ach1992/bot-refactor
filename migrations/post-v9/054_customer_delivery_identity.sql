-- Empty construction chain only; existing instances use explicit transfer.
-- Preserve historical ACKs without inventing a chat or message id.
ALTER TABLE payment_delivery_attempts
  ADD COLUMN ack_chat_id bigint(20) DEFAULT NULL AFTER retry_after_seconds,
  ADD COLUMN ack_message_id bigint(20) unsigned DEFAULT NULL AFTER ack_chat_id,
  ADD CONSTRAINT ck_delivery_attempt_ack_identity CHECK (
    (ack_chat_id IS NULL AND ack_message_id IS NULL) OR
    (result='acknowledged' AND ack_chat_id IS NOT NULL AND ack_chat_id<>0
      AND ack_message_id IS NOT NULL AND ack_message_id>0)
  );
