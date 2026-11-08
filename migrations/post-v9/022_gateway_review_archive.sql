-- Issue #117: operator triage closure on ORIGINAL hosted-gateway attempts.
-- Run once only after original 003; not on the installed RC26 bot.
-- Review the exact schema and target-specific recovery plan before DDL.
-- The Owner waived backup only for the named chilibot TEST database; this
-- does NOT waive separate host approval or imply that DDL can be rolled back.
-- Deploy only with the matching reviewed runtime. DDL autocommits in MariaDB.
-- This does not alter provider stage/status or pays, child or native evidence.
ALTER TABLE payment_attempts
  ADD COLUMN review_archived_at DATETIME DEFAULT NULL,
  ADD COLUMN review_archived_by BIGINT UNSIGNED DEFAULT NULL;
