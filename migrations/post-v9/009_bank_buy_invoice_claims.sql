-- Issue #26 / PR #69 R1: original paid bank BUY_SUB invoice identity.
-- CREATE-only additive schema for source-only development. No tenant migration,
-- live bank/Marzban action or historical V9 modification is authorized.
-- This table is required BEFORE bank-Cron BUY_SUB automatic fulfillment.
-- If this or a related object exists, stop: audit installed schema and rows,
-- then develop a separately reviewed data-preserving forward plan.
-- The claim is written in the same short transaction as have_sent->processing.
-- Rows are immutable after initial claim; changed pays records fail closed.
-- Quoted day/volume=0/0 means standard template; entitled days/volume are
-- the actual purchased snapshot resolved DURABLY at claim, not at remote POST.
-- The hash additionally pins invoice hash_id, original description,
-- agent_bought, raw account_count, type, order_id and payid.
CREATE TABLE bank_buy_invoice_claims (
  pay_row_id INT NOT NULL,
  invoice_sha256 CHAR(64) COLLATE utf8mb4_bin NOT NULL,
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
  claimed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (pay_row_id),
  CONSTRAINT fk_bank_claim_payment FOREIGN KEY (pay_row_id)
    REFERENCES pays(id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
