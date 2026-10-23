-- Issue #26 / PR #69 R1: original paid bank BUY_SUB invoice identity.
-- CREATE-only additive schema for source-only development. No tenant migration,
-- live bank/Marzban action or historical V9 modification is authorized.
-- This table is required BEFORE issuing NEW bank BUY_SUB payment intents.
-- If this or a related object exists, stop: audit installed schema and rows,
-- then develop a separately reviewed data-preserving forward plan.
-- One immutable issued invoice is inserted IN THE SAME transaction as its
-- original 003 bank_transfer attempt, BEFORE bank instructions are delivered.
-- The later have_sent->processing bank claim must VERIFY it, never infer it.
-- Quoted day/volume=0/0 is a standard template; entitlement dimensions are
-- frozen at ORIGINAL intent issuance, never read from a later plan template.
-- Existing receipts without a trustworthy issued snapshot require operator
-- reconciliation; no automatic legacy entitlement reconstruction is allowed.
-- The hash additionally pins invoice hash_id, original description,
-- agent_bought, raw account_count, type, order_id and payid.
CREATE TABLE bank_buy_invoice_claims (
  pay_row_id INT NOT NULL,
  invoice_sha256 CHAR(64) COLLATE utf8mb4_bin NOT NULL,
  bank_attempt_id BIGINT UNSIGNED NOT NULL,
  bank_attempt_sha256 CHAR(64) COLLATE utf8mb4_bin NOT NULL,
  user_id BIGINT NOT NULL,
  plan_id INT NOT NULL,
  server_id INT NOT NULL,
  amount INT NOT NULL,
  account_count INT UNSIGNED NOT NULL,
  quote_mode VARCHAR(12) COLLATE utf8mb4_bin NOT NULL,
  quoted_days INT NOT NULL,
  quoted_volume INT NOT NULL,
  entitled_days INT NOT NULL,
  entitled_volume INT NOT NULL,
  issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (pay_row_id),
  UNIQUE KEY uq_bank_claim_attempt (bank_attempt_id),
  CONSTRAINT fk_bank_claim_payment FOREIGN KEY (pay_row_id)
    REFERENCES pays(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_bank_claim_attempt FOREIGN KEY (bank_attempt_id)
    REFERENCES payment_attempts(id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
