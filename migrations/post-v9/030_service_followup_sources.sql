-- #204: an original service decision can own a report without an invoice.
-- Retain every historical 020/029 row, key, FK and sealed payload.
-- Development candidate; no installed migration authorization.
ALTER TABLE payment_followup_intents
    MODIFY pay_row_id INT DEFAULT NULL,
    ADD CONSTRAINT ck_followup_original_source CHECK (
        pay_row_id IS NOT NULL OR service_operation_id IS NOT NULL);
