-- #204: independent original service command and atomic Wallet funding.
-- Additive development schema; NOT an authorization for live migration.
-- Historical prepaid claims reference their original pays row, never a synthetic debit.
CREATE TABLE service_operations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    command_kind VARBINARY(32) NOT NULL,
    command_ref VARBINARY(128) NOT NULL,
    user_row_id INT NOT NULL,
    userid BIGINT NOT NULL,
    kind VARCHAR(16) COLLATE utf8mb4_bin NOT NULL,
    quote_payload LONGTEXT COLLATE utf8mb4_bin NOT NULL,
    quote_sha256 CHAR(64) COLLATE utf8mb4_bin NOT NULL,
    -- Index immutable quote subjects without a second writable copy of the
    -- original target. Order ID zero denotes BUY (no existing order).
    subject_order_id INT AS (CAST(JSON_VALUE(quote_payload,'$.request.order_id') AS UNSIGNED)) PERSISTENT,
    target_server_id INT AS (CAST(JSON_VALUE(quote_payload,'$.request.server_id') AS UNSIGNED)) PERSISTENT,
    price_toman INT UNSIGNED NOT NULL,
    stage VARCHAR(24) COLLATE utf8mb4_bin NOT NULL DEFAULT 'awaiting_funds',
    debit_entry_id BIGINT UNSIGNED DEFAULT NULL,
    historical_pay_id INT DEFAULT NULL,
    reserved_count INT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt_at DATETIME(6) DEFAULT NULL,
    attempt_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
    claim_token CHAR(32) COLLATE utf8mb4_bin DEFAULT NULL,
    claim_until DATETIME(6) DEFAULT NULL,
    failure_code VARCHAR(96) COLLATE utf8mb4_bin DEFAULT NULL,
    archived_at DATETIME(6) DEFAULT NULL,
    archived_by BIGINT DEFAULT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_service_command (command_kind,command_ref),
    UNIQUE KEY uq_service_debit (debit_entry_id),
    UNIQUE KEY uq_service_historical_payment (historical_pay_id),
    KEY idx_service_due (stage,next_attempt_at,id),
    KEY idx_service_owner (userid,id),
    KEY idx_service_subject_stage (subject_order_id,stage,id),
    KEY idx_service_target_stage (target_server_id,stage,id),
    CONSTRAINT fk_service_debit FOREIGN KEY (debit_entry_id)
        REFERENCES wallet_entries(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_service_historical_payment FOREIGN KEY (historical_pay_id)
        REFERENCES pays(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT ck_service_historical_funding CHECK (historical_pay_id IS NULL OR
        (command_kind='checkout' AND kind='BUY' AND price_toman>0 AND debit_entry_id IS NULL
         AND stage IN ('funded','prepared','dispatching','applied','completed'))),
    CONSTRAINT ck_service_archive CHECK ((archived_at IS NULL) = (archived_by IS NULL)),
    CONSTRAINT ck_service_kind CHECK (kind IN ('BUY','RENEW','ADD_VOLUME','ADD_TIME')),
    CONSTRAINT ck_service_price CHECK (price_toman<=2147483647),
    CONSTRAINT ck_service_quote CHECK (JSON_VALID(quote_payload) AND
        subject_order_id IS NOT NULL AND subject_order_id>=0 AND
        target_server_id IS NOT NULL AND target_server_id>0 AND
        ((kind='BUY')=(subject_order_id=0))),
    CONSTRAINT ck_service_stage CHECK (stage IN ('awaiting_funds','funded','prepared','dispatching','applied','completed','cancelled')),
    CONSTRAINT ck_service_unfunded CHECK (
        stage<>'awaiting_funds' OR (debit_entry_id IS NULL AND reserved_count=0)),
    CONSTRAINT ck_service_funded CHECK (
        stage NOT IN ('funded','prepared','dispatching','applied','completed')
        OR ((price_toman=0 AND debit_entry_id IS NULL) OR
            (price_toman>0 AND ((debit_entry_id IS NOT NULL) <> (historical_pay_id IS NOT NULL)))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE payment_attempts
    ADD service_operation_id BIGINT UNSIGNED DEFAULT NULL,
    ADD wallet_funding_result VARCHAR(48) COLLATE utf8mb4_bin DEFAULT NULL,
    ADD KEY idx_payment_service_operation (service_operation_id),
    ADD CONSTRAINT fk_payment_service_operation FOREIGN KEY (service_operation_id)
        REFERENCES service_operations(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    ADD CONSTRAINT ck_payment_funding_result CHECK (
        wallet_funding_result IS NULL OR wallet_credit_entry_id IS NOT NULL);
