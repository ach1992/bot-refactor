-- AChBot Issue #26: source-only durable administrator wallet audit.
-- REQUIRES separate Owner-authorized migration, tested backup/restore,
-- and installed-runtime preflight before operator wallet UI may be used.
-- 005 is independent of optional, still-uninstalled renewal migration 004.
-- Never modify/drop historical 23-table V9 schema, payment ledgers or
-- financial evidence. Do not drop this journal to roll back source code.
CREATE TABLE admin_wallet_adjustments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    operator_userid BIGINT NOT NULL,
    target_userid BIGINT NOT NULL,
    telegram_update_id BIGINT NOT NULL,
    source_chat_id BIGINT NOT NULL,
    source_message_id BIGINT NOT NULL,
    adjustment_type VARCHAR(16) COLLATE utf8mb4_bin NOT NULL,
    amount_toman INT UNSIGNED NOT NULL,
    before_balance INT NOT NULL,
    after_balance INT NOT NULL,
    reason VARCHAR(500) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_admin_wallet_update (telegram_update_id),
    KEY idx_admin_wallet_target (target_userid,created_at),
    KEY idx_admin_wallet_operator (operator_userid,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
