-- Issue #227: bounded current locking reads for service payment guards.
-- Run once after inspecting original schema/backup/target, separately approved.
-- MariaDB DDL commits automatically. Require matching reviewed runtime.
-- Existing payment data, identities, provider journals and balances unchanged.
-- Source requires BOTH exact indexes before using the service payment guard.
ALTER TABLE pays
  ADD INDEX idx_pays_order_ref_v1 (order_id,id),
  ADD INDEX idx_pays_type_ref_v1 (type,id);
