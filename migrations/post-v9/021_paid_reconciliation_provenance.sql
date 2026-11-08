-- Issue #117: auditable, one-time paid account replacement provenance.
-- SOURCE ONLY. An installed tenant needs explicit Owner migration authority,
-- audited exact 007/012/018/019/020 state and a restore-tested backup.
-- Do not rerun this ALTER on existing/partial columns. No historical backfill.
-- The original 007 CREATE row is NEVER rewritten or deleted to permit a new
-- native POST; a new effect_kind='replacement' row is separately unique by
-- (pay_row_id,effect_kind,ordinal). The original may still exist remotely.
ALTER TABLE service_effects
  ADD replaces_effect_id BIGINT UNSIGNED DEFAULT NULL,
  ADD reconciliation_reason VARCHAR(40) COLLATE utf8mb4_bin DEFAULT NULL,
  ADD reconciliation_fingerprint CHAR(64) COLLATE utf8mb4_bin DEFAULT NULL,
  ADD reconciliation_evidence_status VARCHAR(40) COLLATE utf8mb4_bin DEFAULT NULL,
  ADD reconciliation_observed_at DATETIME DEFAULT NULL,
  ADD UNIQUE KEY uq_effect_one_replacement(replaces_effect_id),
  ADD CONSTRAINT fk_effect_original_replacement FOREIGN KEY (replaces_effect_id)
    REFERENCES service_effects(id) ON DELETE RESTRICT ON UPDATE RESTRICT;

-- New invoice issuance records the actual standard-plan entitlement before
-- a payment link can be offered. NULL distinguishes legacy invoices whose
-- historical standard terms were NEVER independently pinned. The original
-- day/volume=0 standard-vs-custom routing contract remains unchanged.
ALTER TABLE pays
  ADD issued_entitlement_days INT UNSIGNED DEFAULT NULL,
  ADD issued_entitlement_gb BIGINT UNSIGNED DEFAULT NULL;
