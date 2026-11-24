-- #204: preserve each single-message service follow-up attempt.
-- Old 020/029/030 intent and payload rows are retained, not reconstructed.
-- Apply only as part of the approved complete Wallet-first migration.
ALTER TABLE payment_followup_intents
    ADD COLUMN next_attempt_at DATETIME(6) DEFAULT NULL,
    ADD KEY idx_followup_due(state,next_attempt_at,id);

CREATE TABLE payment_followup_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    intent_id BIGINT UNSIGNED NOT NULL,
    attempt_no INT UNSIGNED NOT NULL,
    dispatch_token CHAR(32) COLLATE utf8mb4_bin NOT NULL,
    actor_userid BIGINT NOT NULL DEFAULT 0,
    claim_state VARCHAR(24) COLLATE utf8mb4_bin NOT NULL,
    result VARCHAR(24) COLLATE utf8mb4_bin NOT NULL DEFAULT 'dispatching',
    telegram_error_code SMALLINT UNSIGNED DEFAULT NULL,
    retry_after_seconds INT UNSIGNED DEFAULT NULL,
    started_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    finished_at DATETIME(6) DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_followup_attempt_no(intent_id,attempt_no),
    UNIQUE KEY uq_followup_attempt_token(dispatch_token),
    CONSTRAINT fk_followup_attempt_intent FOREIGN KEY (intent_id)
        REFERENCES payment_followup_intents(id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
