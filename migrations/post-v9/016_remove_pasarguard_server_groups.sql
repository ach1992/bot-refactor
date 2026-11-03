-- #112: retire obsolete test-era PasarGuard server-default Native Groups.
-- SOURCE ONLY. Never applied automatically by runtime or webhooks.
-- Requires migrations 011/012 and separate approved tenant backup/restore.
-- Historic migrations 001..015 and the original legacy schema remain immutable.
--
-- The owner confirms that old server-level PasarGuard Group assignments are
-- test data; Native Groups now belong ONLY to server_plans.provider_group_ids.
-- This drops ONLY server_info.provider_group_ids. It does not delete orders,
-- products, wallet/payment records, Native identities, effects or receipts.
--
-- Stop if an active PasarGuard plan lacks an explicit Group set. The operator
-- may retire obsolete *test* plans or set proper plan groups separately; do
-- not silently infer from the obsolete column. This file is not idempotent.
DELIMITER //
CREATE PROCEDURE achbot_drop_pg_server_defaults_016()
BEGIN
 DECLARE old_columns INT DEFAULT 0;
 DECLARE plan_columns INT DEFAULT 0;
 DECLARE invalid_plans BIGINT DEFAULT 0;
 SELECT COUNT(*) INTO old_columns FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='server_info'
    AND COLUMN_NAME='provider_group_ids';
 SELECT COUNT(*) INTO plan_columns FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='server_plans'
    AND COLUMN_NAME='provider_group_ids';
 IF old_columns<>1 OR plan_columns<>1 THEN SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT='016 schema mismatch or already applied; audit before retry'; END IF;
 SELECT COUNT(*) INTO invalid_plans FROM server_plans p
  JOIN server_config c ON c.id=p.server_id AND c.type='pasarguard'
  WHERE p.active=1 AND (p.provider_group_ids IS NULL OR
    p.provider_group_ids='' OR p.provider_group_ids='[]');
 IF invalid_plans<>0 THEN SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT='016 active PasarGuard plans require explicit Group IDs'; END IF;
END//
DELIMITER ;
CALL achbot_drop_pg_server_defaults_016();
DROP PROCEDURE achbot_drop_pg_server_defaults_016;
ALTER TABLE server_info DROP COLUMN provider_group_ids;
