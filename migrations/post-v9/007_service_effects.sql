-- Issue #26: durable, per-order, per-account service-effect identity.
-- PROPOSED ADDITIVE CREATE, never automatically installed. Target MUST
-- have no existing service_effects object; this revised 007 is NOT an ALTER
-- of an earlier installed 007. STOP on existing object and design reviewed
-- data-preserving forward migration after exact schema/row audit and restore.
-- Requires 001 and the relevant payment migrations, with Owner approval.
-- Unpaid free-trial/admin-bulk operations share THIS physical CREATE ledger;
-- their stable source identity uses (source_kind, source_ref, ordinal), not
-- fake payment records. NULL pay_row_id is valid only for authenticated
-- non-payment source entries; payment entries require pay_row_id and NULL
-- source_kind/ref. No backfill is inferred from counts or remote accounts.
CREATE TABLE service_effects (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  pay_row_id INT DEFAULT NULL,
  source_kind VARCHAR(20) COLLATE utf8mb4_bin DEFAULT NULL,
  source_ref VARCHAR(100) COLLATE utf8mb4_bin DEFAULT NULL,
  actor_userid BIGINT DEFAULT NULL,
  ordinal INT UNSIGNED NOT NULL,
  reserved_count INT UNSIGNED NOT NULL DEFAULT 0,
  operation_id CHAR(32) COLLATE utf8mb4_bin NOT NULL,
  effect_kind VARCHAR(16) COLLATE utf8mb4_bin NOT NULL DEFAULT 'create',
  target_server_id INT NOT NULL,
  target_group_id INT DEFAULT NULL,
  target_key VARCHAR(48) COLLATE utf8mb4_bin NOT NULL,
  endpoint_sha256 CHAR(64) COLLATE utf8mb4_bin NOT NULL,
  plan_id INT NOT NULL,
  remote_username VARCHAR(100) COLLATE utf8mb4_bin NOT NULL,
  requested_expire BIGINT NOT NULL,
  requested_bytes BIGINT UNSIGNED NOT NULL,
  request_sha256 CHAR(64) COLLATE utf8mb4_bin NOT NULL,
  state VARCHAR(24) COLLATE utf8mb4_bin NOT NULL DEFAULT 'dispatching',
  order_id INT DEFAULT NULL,
  verified_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_effect_source_line (source_kind,source_ref,ordinal),
  UNIQUE KEY uq_effect_pay_line (pay_row_id,effect_kind,ordinal),
  UNIQUE KEY uq_effect_operation (operation_id),
  UNIQUE KEY uq_effect_target_username (target_key,remote_username),
  UNIQUE KEY uq_effect_order (order_id),
  KEY idx_effect_panel_username (target_server_id,remote_username),
  KEY idx_effect_review (state,updated_at),
  -- The paid-order ID remains an immutable historical audit reference.
  -- Do NOT FK this to deletable subscription rows: user/admin/expiry cleanup
  -- must be free to delete orders_list without destroying this paid effect.
  CONSTRAINT fk_effect_pay FOREIGN KEY (pay_row_id)
    REFERENCES pays(id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
