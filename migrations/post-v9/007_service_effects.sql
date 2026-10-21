-- Issue #26: durable, per-order, per-account service-effect identity.
-- ADDITIVE ONLY; NOT automatically installed. Apply only after 001 and the
-- relevant payment migrations, on an approved, backed-up test installation.
-- No backfill is inferred from counts, orders or absent remote accounts.
CREATE TABLE service_effects (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  pay_row_id INT NOT NULL,
  ordinal INT UNSIGNED NOT NULL,
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
