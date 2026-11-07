-- Issue #117: source-only, additive crash-safe per-ordinal delivery ledger.
-- 018 is NOT applied to Chili by source integration or release. Before any live
-- migration: explicit Owner approval, exact schema/row/engine audit, verified
-- backup + restore, source/runtime version alignment and staged rollback plan.
-- NO backfill: historical terminal pays without a manifest are uncertain;
-- never fabricate their original QR/caption or infer Telegram delivery.
-- Must be InnoDB and present BEFORE new bank/API provisioning or wallet credit.
-- Pay/effect/order references are immutable ledger identity, not delete cascades.
CREATE TABLE payment_delivery_intents (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  pay_row_id INT NOT NULL,
  ordinal INT UNSIGNED NOT NULL,
  attempt_id BIGINT UNSIGNED NOT NULL,
  effect_id BIGINT UNSIGNED DEFAULT NULL,
  order_id INT DEFAULT NULL,
  user_id BIGINT NOT NULL,
  server_id INT NOT NULL DEFAULT 0,
  pay_type VARCHAR(120) COLLATE utf8mb4_bin NOT NULL,
  pay_fingerprint CHAR(64) COLLATE utf8mb4_bin NOT NULL,
  source_digest CHAR(64) COLLATE utf8mb4_bin NOT NULL,
  native_operation CHAR(32) COLLATE utf8mb4_bin DEFAULT NULL,
  native_target_key VARCHAR(48) COLLATE utf8mb4_bin DEFAULT NULL,
  sealed_payload LONGTEXT COLLATE utf8mb4_bin NOT NULL,
  payload_sha256 CHAR(64) COLLATE utf8mb4_bin NOT NULL,
  state VARCHAR(24) COLLATE utf8mb4_bin NOT NULL DEFAULT 'pending',
  attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
  dispatch_token CHAR(32) COLLATE utf8mb4_bin DEFAULT NULL,
  dispatched_at DATETIME DEFAULT NULL,
  acknowledged_at DATETIME DEFAULT NULL,
  last_actor BIGINT DEFAULT NULL,
  last_result VARCHAR(36) COLLATE utf8mb4_bin DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_delivery_pay_ordinal(pay_row_id,ordinal),
  UNIQUE KEY uq_delivery_effect(effect_id),
  KEY idx_delivery_work(state,updated_at),
  CONSTRAINT fk_delivery_payment FOREIGN KEY (pay_row_id)
    REFERENCES pays(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_delivery_attempt FOREIGN KEY (attempt_id)
    REFERENCES payment_attempts(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_delivery_effect FOREIGN KEY (effect_id)
    REFERENCES service_effects(id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Append-only history of each intentional Telegram dispatch. Completion can
-- update one row but never deletes prior attempts or forges old ACKs. A new
-- operator-directed attempt preserves the old unknown-send provenance.
CREATE TABLE payment_delivery_attempts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  intent_id BIGINT UNSIGNED NOT NULL,
  attempt_no INT UNSIGNED NOT NULL,
  dispatch_token CHAR(32) COLLATE utf8mb4_bin NOT NULL,
  actor_userid BIGINT NOT NULL DEFAULT 0,
  result VARCHAR(24) COLLATE utf8mb4_bin NOT NULL DEFAULT 'dispatching',
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at DATETIME DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_delivery_attempt_no(intent_id,attempt_no),
  UNIQUE KEY uq_delivery_dispatch_token(dispatch_token),
  CONSTRAINT fk_delivery_attempt_intent FOREIGN KEY (intent_id)
    REFERENCES payment_delivery_intents(id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
