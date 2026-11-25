-- #204: extend the existing customer delivery journal; retain every legacy
-- payment packet and attempt. Apply only during the separately approved
-- Wallet-first migration; 018 and 026 must already be present.
ALTER TABLE payment_delivery_intents
    MODIFY pay_row_id INT DEFAULT NULL,
    MODIFY attempt_id BIGINT UNSIGNED DEFAULT NULL,
    MODIFY pay_type VARCHAR(120) COLLATE utf8mb4_bin DEFAULT NULL,
    MODIFY pay_fingerprint CHAR(64) COLLATE utf8mb4_bin DEFAULT NULL,
    ADD service_operation_id BIGINT UNSIGNED DEFAULT NULL,
    ADD UNIQUE KEY uq_delivery_service_ordinal (service_operation_id,ordinal),
    ADD CONSTRAINT fk_delivery_service_operation FOREIGN KEY (service_operation_id)
        REFERENCES service_operations(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    ADD CONSTRAINT ck_delivery_source CHECK (
        (service_operation_id IS NULL AND pay_row_id IS NOT NULL AND attempt_id IS NOT NULL
            AND pay_type IS NOT NULL AND pay_fingerprint IS NOT NULL)
        OR
        (service_operation_id IS NOT NULL AND pay_row_id IS NULL AND attempt_id IS NULL
            AND pay_type IS NULL AND pay_fingerprint IS NULL));
