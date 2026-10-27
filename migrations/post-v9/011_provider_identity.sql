-- AChBot #81: additive provider identity/configuration and paid entitlement snapshots.
-- SOURCE ONLY. Never run automatically. Requires current 007 and reviewed backups.
-- STOP if any target object/column already exists; audit before a forward migration.
CREATE TABLE provider_panels (
 id INT NOT NULL AUTO_INCREMENT,
 provider VARCHAR(24) COLLATE utf8mb4_bin NOT NULL,
 instance_key CHAR(32) COLLATE utf8mb4_bin NOT NULL,
 name VARCHAR(100) NOT NULL,
 api_url VARCHAR(1000) COLLATE utf8mb4_bin NOT NULL,
 api_key TEXT COLLATE utf8mb4_bin NOT NULL,
 version VARCHAR(32) COLLATE utf8mb4_bin NOT NULL,
 enabled TINYINT UNSIGNED NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(id), UNIQUE KEY uq_provider_instance(instance_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE server_info
 ADD COLUMN provider_panel_id INT DEFAULT NULL,
 ADD COLUMN provider_group_ids TEXT COLLATE utf8mb4_bin DEFAULT NULL,
 ADD KEY idx_server_provider_panel(provider_panel_id);
ALTER TABLE server_plans ADD COLUMN provider_group_ids TEXT COLLATE utf8mb4_bin DEFAULT NULL;
ALTER TABLE pays ADD COLUMN provider_snapshot LONGTEXT COLLATE utf8mb4_bin DEFAULT NULL;
ALTER TABLE service_effects ADD COLUMN provider_snapshot LONGTEXT COLLATE utf8mb4_bin DEFAULT NULL;
CREATE TABLE provider_accounts (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 effect_id BIGINT UNSIGNED NOT NULL,
 order_id INT DEFAULT NULL,
 provider VARCHAR(24) COLLATE utf8mb4_bin NOT NULL,
 panel_id INT NOT NULL,
 instance_key CHAR(32) COLLATE utf8mb4_bin NOT NULL,
 endpoint_sha256 CHAR(64) COLLATE utf8mb4_bin NOT NULL,
 remote_user_id BIGINT UNSIGNED DEFAULT NULL,
 remote_created_at VARCHAR(40) COLLATE utf8mb4_bin DEFAULT NULL,
 remote_username VARCHAR(100) COLLATE utf8mb4_bin NOT NULL,
 operation_marker VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
 entitlement_snapshot LONGTEXT COLLATE utf8mb4_bin NOT NULL,
 state VARCHAR(24) COLLATE utf8mb4_bin NOT NULL DEFAULT 'reserved',
 verified_at DATETIME DEFAULT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY(id),
 UNIQUE KEY uq_provider_effect(effect_id),
 UNIQUE KEY uq_provider_order(order_id),
 UNIQUE KEY uq_provider_remote(panel_id,remote_user_id),
 UNIQUE KEY uq_provider_marker(instance_key,operation_marker),
 KEY idx_provider_review(state,updated_at)
 -- No cascade to deletable orders: retain paid/unknown evidence.
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
