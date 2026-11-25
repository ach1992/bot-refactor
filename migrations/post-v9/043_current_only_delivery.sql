-- EMPTY schema construction only. Never run against an installed database.
-- The first checks deliberately reject pre-current rows, rather than deleting,
-- converting or inventing acknowledgements for historical delivery evidence.
ALTER TABLE payment_delivery_intents ADD CONSTRAINT ck_delivery_no_historical_rows CHECK (
    pay_row_id IS NULL AND attempt_id IS NULL AND pay_type IS NULL AND pay_fingerprint IS NULL
    AND part_count IS NOT NULL AND part_count > 0 AND order_id IS NOT NULL AND order_id > 0);
ALTER TABLE payment_delivery_attempts ADD CONSTRAINT ck_delivery_attempt_current_rows CHECK (
    part_no IS NOT NULL AND claim_state IS NOT NULL);
ALTER TABLE payment_delivery_intents
    DROP CONSTRAINT ck_delivery_no_historical_rows,
    DROP CONSTRAINT ck_delivery_source_current,
    DROP FOREIGN KEY fk_delivery_attempt,
    DROP FOREIGN KEY fk_delivery_payment,
    DROP INDEX uq_delivery_pay_ordinal,
    DROP INDEX fk_delivery_attempt,
    DROP COLUMN pay_row_id,
    DROP COLUMN attempt_id,
    DROP COLUMN pay_type,
    DROP COLUMN pay_fingerprint,
    MODIFY order_id INT NOT NULL,
    MODIFY part_count INT UNSIGNED NOT NULL,
    ADD CONSTRAINT ck_delivery_source_v2 CHECK (
        (service_operation_id IS NOT NULL OR effect_id IS NOT NULL) AND part_count > 0 AND order_id > 0);
ALTER TABLE payment_delivery_attempts
    DROP CONSTRAINT ck_delivery_attempt_current_rows,
    MODIFY part_no INT UNSIGNED NOT NULL,
    MODIFY claim_state VARCHAR(24) COLLATE utf8mb4_bin NOT NULL;
