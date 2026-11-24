-- #204: bind new purchase referral claims to the completed original service
-- and actual funding receipt, retaining historical 008 and 020 rows exactly.
-- Development candidate only; not authorization to apply on installed bots.
ALTER TABLE referral_awards
    ADD service_operation_id BIGINT UNSIGNED DEFAULT NULL,
    ADD inviter_user_row_id INT DEFAULT NULL,
    ADD wallet_entry_id BIGINT UNSIGNED DEFAULT NULL,
    ADD next_attempt_at DATETIME(6) DEFAULT NULL,
    ADD UNIQUE KEY uq_referral_service (service_operation_id),
    ADD UNIQUE KEY uq_referral_wallet_entry (wallet_entry_id),
    ADD KEY idx_referral_service_due (state,next_attempt_at,id),
    ADD CONSTRAINT fk_referral_service FOREIGN KEY (service_operation_id)
        REFERENCES service_operations(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    ADD CONSTRAINT fk_referral_wallet_entry FOREIGN KEY (wallet_entry_id)
        REFERENCES wallet_entries(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    ADD CONSTRAINT ck_referral_service_claim CHECK (
        service_operation_id IS NULL OR
        ((state='NOT_ATTEMPTED' AND inviter_user_row_id IS NOT NULL AND
            amount_toman>0 AND wallet_entry_id IS NULL AND next_attempt_at IS NOT NULL) OR
         (state='CONFIRMED' AND inviter_user_row_id IS NOT NULL AND
            amount_toman>0 AND wallet_entry_id IS NOT NULL AND next_attempt_at IS NULL) OR
         (state IN ('DISABLED','NEEDS_REVIEW') AND
            wallet_entry_id IS NULL AND next_attempt_at IS NULL)));

ALTER TABLE payment_followup_intents
    ADD service_operation_id BIGINT UNSIGNED DEFAULT NULL,
    ADD UNIQUE KEY uq_followup_service_key (service_operation_id,intent_key),
    ADD CONSTRAINT fk_followup_service FOREIGN KEY (service_operation_id)
        REFERENCES service_operations(id) ON DELETE RESTRICT ON UPDATE RESTRICT;
