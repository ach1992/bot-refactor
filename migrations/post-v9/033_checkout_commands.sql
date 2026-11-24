-- #204: exact UI draft to service-command identity. Additive candidate.
-- Existing payment rows stay NULL; no historical receipt/debit is invented.
-- Do not install or activate before the full opening-balance and
-- historical-claim reconciliation, source cutover and independent review.
ALTER TABLE pays
    ADD checkout_draft_context JSON DEFAULT NULL,
    ADD checkout_operation_id BIGINT UNSIGNED DEFAULT NULL,
    ADD UNIQUE KEY uq_checkout_operation (checkout_operation_id),
    ADD CONSTRAINT fk_checkout_operation FOREIGN KEY (checkout_operation_id)
        REFERENCES service_operations(id) ON DELETE RESTRICT ON UPDATE RESTRICT;

-- Signed IPN observations and actual merchant verification are different.
ALTER TABLE payment_transactions
    ADD merchant_checked_at DATETIME(6) DEFAULT NULL,
    ADD merchant_verified_at DATETIME(6) DEFAULT NULL,
    ADD KEY idx_merchant_due (provider,merchant_verified_at,merchant_checked_at,id);
