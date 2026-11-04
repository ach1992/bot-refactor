-- AChBot #112: durable intent journal for a physical subscription's plan
-- and native-access changes across multiple customer order references.
-- SOURCE ONLY. Do not run automatically; requires approved tenant migration,
-- backup and recoverable operator rollout AFTER migration 016.
--
-- Keep an unambiguous, immutable history for manual post-crash reconciliation.
-- The native mutation fence is authoritative for PasarGuard and native GET
-- readback for Marzban; this journal does not settle financial operations.
DELIMITER //
CREATE PROCEDURE achbot_shared_plan_preflight_017()
BEGIN
 DECLARE found INT DEFAULT 0;
 SELECT COUNT(*) INTO found FROM information_schema.TABLES WHERE
    TABLE_SCHEMA=DATABASE() AND TABLE_NAME='service_plan_changes';
 IF found<>0 THEN SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT='017 already applied or partial; inspect before retry'; END IF;
END//
DELIMITER ;
CALL achbot_shared_plan_preflight_017();
DROP PROCEDURE achbot_shared_plan_preflight_017;
CREATE TABLE service_plan_changes (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 lock_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 source_order_id INT NOT NULL,
 plan_id INT NOT NULL,
 server_id INT NOT NULL,
 provider VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'prepared',
 intent_json LONGTEXT COLLATE utf8mb4_bin NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY(id),
 KEY idx_shared_plan_lock_state(lock_key,state,id),
 KEY idx_shared_plan_order(source_order_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
