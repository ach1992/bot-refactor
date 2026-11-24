-- Wallet-first #204: stage 1 of the new financial architecture.
-- Additive candidate only; do NOT execute against installed databases.
-- This table is not active until every balance writer is migrated and an
-- approved, rehearsed opening-balance/cutover procedure has completed.
-- Historical payment/adjustment/referral records remain intact.
CREATE TABLE wallet_entries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    source_kind VARBINARY(32) NOT NULL,
    source_ref VARBINARY(128) NOT NULL,
    leg INT UNSIGNED NOT NULL,
    user_row_id INT NOT NULL,
    userid BIGINT NOT NULL,
    delta_toman BIGINT NOT NULL,
    balance_before BIGINT NOT NULL,
    balance_after BIGINT NOT NULL,
    actor_userid BIGINT NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_wallet_entry_source (source_kind,source_ref,leg),
    KEY idx_wallet_entry_user (userid,id),
    CONSTRAINT ck_wallet_entry_arithmetic CHECK (balance_after=balance_before+delta_toman),
    CONSTRAINT ck_wallet_entry_nonzero CHECK (delta_toman<>0 OR source_kind='opening')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
