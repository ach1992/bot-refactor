-- Empty schema construction only. Existing installations require an explicit
-- verified transfer; never discard historical invoice identities or guess roles.
-- Check both sources before any DDL; a failed preflight must not leave a
-- temporary constraint on a live table.
DELIMITER //
BEGIN NOT ATOMIC
    IF EXISTS (SELECT 1 FROM payment_message_refs LIMIT 1) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='051 existing message references require transfer';
    END IF;
    IF EXISTS (SELECT 1 FROM payment_attempts WHERE evidence_invoice_chat_id IS NOT NULL
        OR evidence_invoice_message_id IS NOT NULL OR evidence_invoice_digest IS NOT NULL LIMIT 1) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='051 existing API invoice references require transfer';
    END IF;
END//
DELIMITER ;
ALTER TABLE payment_message_refs
    ADD purpose VARCHAR(12) COLLATE utf8mb4_bin NOT NULL DEFAULT 'status' AFTER kind,
    ADD last_status_update_id BIGINT UNSIGNED DEFAULT NULL AFTER purpose,
    ADD last_status_requested_at DATETIME(6) DEFAULT NULL AFTER last_status_update_id,
    ADD retired_reason VARCHAR(32) COLLATE utf8mb4_bin DEFAULT NULL AFTER last_status_requested_at,
    DROP INDEX idx_payment_message_order,
    ADD KEY idx_payment_message_order (pay_row_id,retired_reason,updated_at);
ALTER TABLE payment_attempts
    DROP COLUMN evidence_invoice_chat_id,
    DROP COLUMN evidence_invoice_message_id,
    DROP COLUMN evidence_invoice_digest;
