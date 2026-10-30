-- AChBot Issue57: source-only existing-API evidence on the shared ledger.
-- Apply ONLY with separate tenant approval, verified backup/restore and exact
-- migration preflight (003 must exist). Never backfill/rewrite issued009 pins.
-- Every column is nullable: unpinned invoices retain their manual journey.
-- No provider-specific registry, automatic migration, destructive cleanup or
-- dependency schema is introduced. Partial014 must fail API closed.
ALTER TABLE payment_attempts
 ADD evidence_profile LONGTEXT COLLATE utf8mb4_bin DEFAULT NULL,
 ADD evidence_state VARCHAR(32) COLLATE utf8mb4_bin DEFAULT NULL,
 ADD evidence_cursor_id BIGINT UNSIGNED DEFAULT NULL,
 ADD evidence_claim_token CHAR(32) COLLATE utf8mb4_bin DEFAULT NULL,
 ADD evidence_claimed_at DATETIME DEFAULT NULL,
 ADD evidence_next_check_at DATETIME DEFAULT NULL,
 ADD evidence_invoice_chat_id BIGINT DEFAULT NULL,
 ADD evidence_invoice_message_id INT DEFAULT NULL,
 ADD evidence_invoice_digest CHAR(64) COLLATE utf8mb4_bin DEFAULT NULL,
 ADD evidence_code LONGTEXT COLLATE utf8mb4_bin DEFAULT NULL,
 ADD evidence_code_sha256 CHAR(64) COLLATE utf8mb4_bin DEFAULT NULL,
 ADD evidence_review_claim_token CHAR(32) COLLATE utf8mb4_bin DEFAULT NULL,
 ADD evidence_review_claimed_at DATETIME DEFAULT NULL,
 ADD evidence_review_sent_at DATETIME DEFAULT NULL,
 ADD evidence_projection_checked_at DATETIME DEFAULT NULL,
 ADD evidence_report_digest CHAR(64) COLLATE utf8mb4_bin DEFAULT NULL,
 ADD evidence_staff_digest CHAR(64) COLLATE utf8mb4_bin DEFAULT NULL,
 ADD evidence_fulfillment_state VARCHAR(32) COLLATE utf8mb4_bin DEFAULT NULL,
 ADD evidence_fulfillment_payload LONGTEXT COLLATE utf8mb4_bin DEFAULT NULL,
 ADD KEY idx_payment_evidence_due(provider,evidence_state,evidence_next_check_at);
ALTER TABLE payment_transactions
 ADD evidence_state VARCHAR(32) COLLATE utf8mb4_bin DEFAULT NULL,
 ADD evidence_operation CHAR(64) COLLATE utf8mb4_bin DEFAULT NULL,
 ADD evidence_payload LONGTEXT COLLATE utf8mb4_bin DEFAULT NULL,
 ADD evidence_dispatched_at DATETIME DEFAULT NULL,
 ADD evidence_proven_at DATETIME DEFAULT NULL,
 ADD UNIQUE KEY uq_payment_evidence_operation(evidence_operation);
