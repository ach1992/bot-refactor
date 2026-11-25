-- #204: extend 018/027, without rewriting original packets or attempt history.
-- NULL part_count/part_no identifies whole-packet historical evidence.
-- No backfill, inferred part ACK, or automatic redrive of historical UNKNOWN.
ALTER TABLE payment_delivery_intents
    ADD COLUMN part_count INT UNSIGNED DEFAULT NULL,
    ADD COLUMN next_attempt_at DATETIME(6) DEFAULT NULL,
    ADD KEY idx_delivery_due(state,next_attempt_at,id);

ALTER TABLE payment_delivery_attempts
    ADD COLUMN part_no INT UNSIGNED DEFAULT NULL,
    ADD COLUMN claim_state VARCHAR(24) COLLATE utf8mb4_bin DEFAULT NULL,
    ADD COLUMN telegram_error_code SMALLINT UNSIGNED DEFAULT NULL,
    ADD COLUMN retry_after_seconds INT UNSIGNED DEFAULT NULL,
    ADD KEY idx_delivery_part_attempt(intent_id,part_no,attempt_no);

CREATE TABLE payment_delivery_parts (
    intent_id BIGINT UNSIGNED NOT NULL,
    part_no INT UNSIGNED NOT NULL,
    payload_sha256 CHAR(64) COLLATE utf8mb4_bin NOT NULL,
    state VARCHAR(24) COLLATE utf8mb4_bin NOT NULL DEFAULT 'pending',
    last_attempt_no INT UNSIGNED DEFAULT NULL,
    next_attempt_at DATETIME(6) DEFAULT NULL,
    PRIMARY KEY (intent_id,part_no),
    CONSTRAINT fk_delivery_part_intent FOREIGN KEY (intent_id)
        REFERENCES payment_delivery_intents(id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE payment_delivery_attempts
    ADD CONSTRAINT fk_delivery_attempt_part FOREIGN KEY (intent_id,part_no)
        REFERENCES payment_delivery_parts(intent_id,part_no) ON DELETE RESTRICT ON UPDATE RESTRICT;
