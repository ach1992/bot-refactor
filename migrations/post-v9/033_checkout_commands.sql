-- #204: exact UI draft to service-command identity. Additive candidate.
-- Existing payment rows stay NULL; no historical receipt/debit is invented.
-- Do not install or activate before the full opening-balance and
-- historical-claim reconciliation, source cutover and independent review.
ALTER TABLE pays
    ADD checkout_operation_id BIGINT UNSIGNED DEFAULT NULL,
    ADD UNIQUE KEY uq_checkout_operation (checkout_operation_id),
    ADD CONSTRAINT fk_checkout_operation FOREIGN KEY (checkout_operation_id)
        REFERENCES service_operations(id) ON DELETE RESTRICT ON UPDATE RESTRICT;
