-- Offline schema-chain evidence: a real nonfinancial event shares the current
-- report outbox. The event's immutable envelope is sealed in the intent;
-- deleted/moved orders need not still exist at delivery time.
ALTER TABLE payment_followup_intents
    DROP CONSTRAINT ck_followup_original_source,
    ADD COLUMN operational_ref VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
    ADD UNIQUE KEY uq_followup_operational_key (operational_ref,intent_key),
    ADD CONSTRAINT ck_followup_original_source CHECK (
        operational_ref IS NULL AND (
            wallet_entry_id IS NULL AND (pay_row_id IS NOT NULL OR service_operation_id IS NOT NULL)
            OR wallet_entry_id IS NOT NULL AND pay_row_id IS NULL AND service_operation_id IS NULL)
        OR operational_ref IS NOT NULL AND wallet_entry_id IS NULL
            AND pay_row_id IS NULL AND service_operation_id IS NULL);
