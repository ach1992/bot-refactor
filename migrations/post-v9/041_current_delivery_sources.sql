-- Construction of the current empty schema. The original effect is the
-- source of nonpaid delivery; old payment-bound rows are evidence only.
-- No historical data is deleted or made executable by this constraint.
ALTER TABLE payment_delivery_intents
    DROP CONSTRAINT ck_delivery_source,
    ADD CONSTRAINT ck_delivery_source_current CHECK (
        (service_operation_id IS NULL AND pay_row_id IS NOT NULL AND attempt_id IS NOT NULL
            AND pay_type IS NOT NULL AND pay_fingerprint IS NOT NULL)
        OR
        ((service_operation_id IS NOT NULL OR effect_id IS NOT NULL)
            AND pay_row_id IS NULL AND attempt_id IS NULL
            AND pay_type IS NULL AND pay_fingerprint IS NULL));
