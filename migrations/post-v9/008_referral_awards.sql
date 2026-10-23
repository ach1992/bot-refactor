-- Issue #26: exactly one source-financial referral award claim per paid order.
-- PROPOSED ADDITIVE CREATE. Never auto-install. Requires separately
-- authorized 007 and relevant payment migrations plus verified backup and
-- restore, exact DDL preflight, and absence of an incompatible existing
-- referral_awards object. Stop on partial DDL; do not rerun blindly.
-- No historical backfill, dropping or rewriting previous financial evidence.
-- A NOT_ATTEMPTED claim is NOT an invitation to automatically issue a credit.
CREATE TABLE referral_awards (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    pay_row_id INT NOT NULL,
    buyer_userid BIGINT NOT NULL,
    inviter_userid BIGINT NOT NULL,
    amount_toman INT UNSIGNED NOT NULL,
    state VARCHAR(20) COLLATE utf8mb4_bin NOT NULL,
    before_balance INT DEFAULT NULL,
    after_balance INT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    confirmed_at DATETIME DEFAULT NULL,
    review_at DATETIME DEFAULT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_referral_award_payment (pay_row_id),
    KEY idx_referral_award_review (state,updated_at),
    KEY idx_referral_award_inviter (inviter_userid,created_at),
    CONSTRAINT fk_referral_award_payment
      FOREIGN KEY (pay_row_id) REFERENCES pays(id)
      ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
