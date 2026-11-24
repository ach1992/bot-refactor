-- Current-schema construction only. No live migration authorization.
ALTER TABLE service_operations
    DROP CONSTRAINT ck_service_archive,
    DROP archived_at,
    DROP archived_by,
    ADD resolution_kind VARCHAR(16) COLLATE utf8mb4_bin DEFAULT NULL,
    ADD resolved_by BIGINT DEFAULT NULL,
    ADD resolved_at DATETIME(6) DEFAULT NULL,
    ADD refund_entry_id BIGINT UNSIGNED DEFAULT NULL,
    ADD cancelled_count INT UNSIGNED NOT NULL DEFAULT 0,
    ADD UNIQUE KEY uq_service_refund (refund_entry_id),
    ADD CONSTRAINT fk_service_refund FOREIGN KEY (refund_entry_id) REFERENCES wallet_entries(id),
    ADD CONSTRAINT ck_service_resolution CHECK (
        (resolution_kind IS NULL AND resolved_by IS NULL AND resolved_at IS NULL AND refund_entry_id IS NULL AND cancelled_count=0)
        OR (resolution_kind IN ('closed','refunded') AND resolved_by>0 AND resolved_at IS NOT NULL
            AND stage IN ('completed','cancelled') AND next_attempt_at IS NULL
            AND (resolution_kind='refunded' OR (refund_entry_id IS NULL AND cancelled_count=0))
            AND (kind<>'BUY' OR resolution_kind='refunded')));

ALTER TABLE service_effects
    ADD active_username VARCHAR(100) COLLATE utf8mb4_bin
        GENERATED ALWAYS AS (IF(state='cancelled',NULL,remote_username)) VIRTUAL,
    DROP INDEX uq_effect_target_username,
    ADD UNIQUE KEY uq_effect_target_username (target_key,active_username);
