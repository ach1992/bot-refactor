-- Empty schema construction only; never invent deadlines for historical money.
ALTER TABLE pays
  ADD COLUMN draft_expires_at bigint unsigned DEFAULT NULL,
  ADD KEY idx_pays_draft_deadline (state, draft_expires_at, id);
ALTER TABLE payment_attempts
  ADD COLUMN invoice_expires_at bigint unsigned DEFAULT NULL,
  ADD COLUMN receipt_grace_seconds bigint unsigned DEFAULT NULL,
  ADD COLUMN receipt_started_at bigint unsigned DEFAULT NULL,
  ADD KEY idx_attempt_deadline (stage, invoice_expires_at, pay_row_id);
