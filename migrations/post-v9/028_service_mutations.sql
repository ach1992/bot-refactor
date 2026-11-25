-- #204: immutable edit intent/result per original funded service operation.
-- The existing physical account state is the durable cross-caller fence.
-- Preserve its historical mutation snapshot and restore the prior key on
-- completion; the new journal, not the old consumed_keys array, owns replay.
CREATE TABLE service_mutations (
    service_operation_id BIGINT UNSIGNED NOT NULL,
    account_effect_id BIGINT UNSIGNED NOT NULL,
    previous_mutation_key VARCHAR(100) COLLATE utf8mb4_bin DEFAULT NULL,
    order_expire_before INT NOT NULL,
    phase VARCHAR(16) COLLATE utf8mb4_bin NOT NULL,
    intent_payload LONGTEXT COLLATE utf8mb4_bin DEFAULT NULL,
    intent_sha256 CHAR(64) COLLATE utf8mb4_bin DEFAULT NULL,
    reset_ack_at DATETIME(6) DEFAULT NULL,
    result_payload LONGTEXT COLLATE utf8mb4_bin DEFAULT NULL,
    result_sha256 CHAR(64) COLLATE utf8mb4_bin DEFAULT NULL,
    audit_id INT DEFAULT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (service_operation_id),
    KEY idx_service_mutation_account (account_effect_id,service_operation_id),
    UNIQUE KEY uq_service_mutation_audit (audit_id),
    CONSTRAINT fk_service_mutation_operation FOREIGN KEY (service_operation_id)
        REFERENCES service_operations(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_service_mutation_account FOREIGN KEY (account_effect_id)
        REFERENCES service_effects(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT ck_service_mutation_phase CHECK (phase IN ('observe','reset','modify','verified','completed')),
    CONSTRAINT ck_service_mutation_intent CHECK (
        (phase='observe' AND intent_payload IS NULL AND intent_sha256 IS NULL)
        OR (phase<>'observe' AND intent_payload IS NOT NULL AND intent_sha256 IS NOT NULL AND JSON_VALID(intent_payload))),
    CONSTRAINT ck_service_mutation_result CHECK (
        (phase IN ('verified','completed') AND result_payload IS NOT NULL AND result_sha256 IS NOT NULL AND JSON_VALID(result_payload))
        OR (phase NOT IN ('verified','completed') AND result_payload IS NULL AND result_sha256 IS NULL)),
    CONSTRAINT ck_service_mutation_audit CHECK ((phase='completed')=(audit_id IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
