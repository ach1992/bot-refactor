-- #204 additive Wallet-first receipt admission and finality.
-- NULL invoice pin identifies all existing historical attempts. Never set it
-- just because an old invoice is pending, paid, approved or archived.
-- Only a NEW issuer INSERTs the empty admission marker. It pins the original
-- local money identity before publishing its checkout. The controlled migration classifies historical invoices separately.
-- No runtime activation or live execution is authorized by this migration.
ALTER TABLE payment_attempts
 ADD wallet_invoice_sha256 CHAR(64) COLLATE utf8mb4_bin DEFAULT NULL,
 ADD wallet_merchant_profile TEXT COLLATE utf8mb4_bin DEFAULT NULL,
 ADD wallet_credit_entry_id BIGINT UNSIGNED DEFAULT NULL,
 ADD wallet_credit_basis VARCHAR(32) COLLATE utf8mb4_bin DEFAULT NULL,
 ADD wallet_credit_transaction_id BIGINT UNSIGNED DEFAULT NULL,
 ADD UNIQUE KEY uq_wallet_credit_entry (wallet_credit_entry_id),
 ADD UNIQUE KEY uq_wallet_credit_transaction (wallet_credit_transaction_id),
 ADD CONSTRAINT fk_wallet_credit_entry FOREIGN KEY (wallet_credit_entry_id)
   REFERENCES wallet_entries(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 ADD CONSTRAINT fk_wallet_credit_transaction FOREIGN KEY (wallet_credit_transaction_id)
   REFERENCES payment_transactions(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 ADD CONSTRAINT ck_wallet_invoice_admission CHECK (
   wallet_invoice_sha256 IS NULL OR wallet_invoice_sha256=''
     OR wallet_invoice_sha256 REGEXP '^[0-9a-f]{64}$'),
 ADD CONSTRAINT ck_wallet_credit_link CHECK (
   (wallet_credit_entry_id IS NULL AND wallet_credit_basis IS NULL
     AND wallet_credit_transaction_id IS NULL)
   OR (wallet_invoice_sha256 IS NOT NULL AND wallet_invoice_sha256<>'' AND wallet_credit_entry_id IS NOT NULL
     AND wallet_credit_basis IS NOT NULL)
 );
