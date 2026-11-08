-- Issue #117: operator triage closure on ORIGINAL hosted-gateway attempts.
-- Run once only after original 003; not on the installed RC26 bot.
-- Review current schema, test backup/restore and deploy the matching runtime
-- under separate target-specific approval. DDL autocommits in MariaDB.
-- This does not alter provider stage/status or pays, child or native evidence.
ALTER TABLE payment_attempts
  ADD COLUMN review_archived_at DATETIME DEFAULT NULL,
  ADD COLUMN review_archived_by BIGINT UNSIGNED DEFAULT NULL;
