-- Offline schema-chain evidence. Fresh installs use database/current-schema.sql.
-- A standalone wallet movement is a real source, not a synthetic invoice.
ALTER TABLE payment_followup_intents
    DROP CONSTRAINT ck_followup_original_source,
    ADD COLUMN wallet_entry_id BIGINT UNSIGNED DEFAULT NULL,
    ADD UNIQUE KEY uq_followup_wallet_key (wallet_entry_id,intent_key),
    ADD CONSTRAINT fk_followup_wallet FOREIGN KEY (wallet_entry_id) REFERENCES wallet_entries(id),
    ADD CONSTRAINT ck_followup_original_source CHECK
        (wallet_entry_id IS NULL AND (pay_row_id IS NOT NULL OR service_operation_id IS NOT NULL)
         OR wallet_entry_id IS NOT NULL AND pay_row_id IS NULL AND service_operation_id IS NULL);
