-- Construction only. Preserve installed attempt evidence in the separately
-- approved data transfer; old fixed wire action/target are provable from
-- the original intent, but superseded presentation stages are not replayed.
DELIMITER //
BEGIN NOT ATOMIC
    IF EXISTS (SELECT 1 FROM payment_followup_attempts) OR
       EXISTS (SELECT 1 FROM payment_followup_intents) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='existing followup evidence requires explicit transfer';
    END IF;
END//
DELIMITER ;
ALTER TABLE payment_followup_attempts
  ADD COLUMN wire_action varchar(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL AFTER claim_state,
  ADD COLUMN wire_recipient varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL AFTER wire_action,
  ADD COLUMN wire_message_id bigint(20) unsigned DEFAULT NULL AFTER wire_recipient,
  ADD COLUMN failure_reason varchar(48) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL AFTER retry_after_seconds,
  ADD CONSTRAINT ck_followup_attempt_wire CHECK (
    (wire_action='send' AND wire_message_id IS NULL) OR
    (wire_action IN ('edit_text','edit_keys') AND wire_message_id IS NOT NULL AND wire_message_id>0)
  );
