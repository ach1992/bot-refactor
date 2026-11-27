-- Empty construction chain only. Installed data follows the explicit cutover plan.
-- Historical ACK evidence may have no proven message identity; never invent one.
ALTER TABLE payment_followup_attempts
  ADD COLUMN ack_chat_id bigint(20) DEFAULT NULL AFTER retry_after_seconds,
  ADD COLUMN ack_message_id bigint(20) unsigned DEFAULT NULL AFTER ack_chat_id,
  ADD CONSTRAINT ck_followup_attempt_ack_identity CHECK (
    (ack_chat_id IS NULL AND ack_message_id IS NULL) OR
    (result='acknowledged' AND ack_chat_id IS NOT NULL AND ack_chat_id<>0
      AND ack_message_id IS NOT NULL AND ack_message_id>0)
  );
