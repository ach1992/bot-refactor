-- AChBot Issue #26: source-only durable wallet movement audit (RC9 -> current).
-- New table only: RC9 has no 005 journal. RC10 is not an upgrade baseline.
-- Do not run this over any existing 005 table without a separate audit.
-- Source identity + leg number is unique; a transfer has debit and credit legs.
-- The Telegram update/leg key is independent of operation type: one event
-- cannot authorize BOTH an admin adjustment and a transfer.
-- REQUIRES separate Owner-authorized migration, tested backup/restore,
-- and installed-runtime preflight before operator wallet UI may be used.
-- 005 is independent of optional, still-uninstalled renewal migration 004.
-- Never modify/drop historical 23-table V9 schema, payment ledgers or
-- financial evidence. Do not drop this journal to roll back source code.
CREATE TABLE wallet_adjustments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    source_type VARCHAR(32) COLLATE utf8mb4_bin NOT NULL,
    source_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
    entry_no TINYINT UNSIGNED NOT NULL,
    actor_userid BIGINT NOT NULL,
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
    UNIQUE KEY uq_wallet_source_entry (source_type,source_id,entry_no),
    UNIQUE KEY uq_wallet_telegram_entry (telegram_update_id,entry_no),
    KEY idx_wallet_target (target_userid,created_at),
    KEY idx_wallet_actor (actor_userid,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
