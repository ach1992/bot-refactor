-- AChBot #107 source-only optional extension-scopes migration (015).
-- Application requires compatible runtime; NO tenant DDL authorization is given.
-- Stop webhook/Cron, take and RESTORE-test a backup before separate approval.
-- This preflight must run on an unmigrated installation after provider 012.
-- Existing IDs, prices, units, pays rows and historical schema remain untouched.
-- Duplicate legacy quantities block migration, requiring an audited business
-- decision; do not silently choose a different existing price.
DELIMITER //
CREATE PROCEDURE achbot_scoped_addons_preflight_015()
BEGIN
 DECLARE failures BIGINT DEFAULT 0;
 SELECT COUNT(*) INTO failures FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND (
  (TABLE_NAME='increase_day' AND COLUMN_NAME IN ('server_id','group_ids','scope_hash')) OR
  (TABLE_NAME='increase_plan' AND COLUMN_NAME IN ('server_id','group_ids','scope_hash')) OR
  (TABLE_NAME='pays' AND COLUMN_NAME='addon_scope_snapshot'));
 IF failures<>0 THEN SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT='015 partial or already applied: audit before retry'; END IF;
 SELECT COUNT(*) INTO failures FROM (
  SELECT volume FROM increase_day GROUP BY volume HAVING COUNT(*)>1
  UNION ALL
  SELECT volume FROM increase_plan GROUP BY volume HAVING COUNT(*)>1
 ) conflicting;
 IF failures<>0 THEN SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT='015 conflicting existing extension units: audit pricing before migration'; END IF;
 SELECT COUNT(*) INTO failures FROM (
  SELECT id FROM increase_day WHERE id<=0 OR volume<=0 OR volume<>FLOOR(volume) OR
    volume>36500 OR price<0
  UNION ALL
  SELECT id FROM increase_plan WHERE id<=0 OR volume<=0 OR volume<>FLOOR(volume) OR
    volume>2147483647 OR price<0
 ) invalid_rows;
 IF failures<>0 THEN SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT='015 invalid legacy extension units or prices: audit before migration'; END IF;
END//
DELIMITER ;
CALL achbot_scoped_addons_preflight_015();
DROP PROCEDURE achbot_scoped_addons_preflight_015;

-- The scope_hash is a strict application-verified scope key. Unique
-- (volume,scope_hash) prevents two administrators from racing on the same
-- kind/quantity/scope, while preserving global legacy ID and prices.
ALTER TABLE increase_day
 ADD COLUMN server_id INT DEFAULT NULL,
 ADD COLUMN group_ids TEXT COLLATE utf8mb4_bin DEFAULT NULL,
 ADD COLUMN scope_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '333e0a1e27815d0ceee55c473fe3dc93d56c63e3bee2b3b4aee8eed6d70191a3',
 ADD UNIQUE KEY uq_addon_day_units_scope (volume,scope_hash);
ALTER TABLE increase_plan
 ADD COLUMN server_id INT DEFAULT NULL,
 ADD COLUMN group_ids TEXT COLLATE utf8mb4_bin DEFAULT NULL,
 ADD COLUMN scope_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '333e0a1e27815d0ceee55c473fe3dc93d56c63e3bee2b3b4aee8eed6d70191a3',
 ADD UNIQUE KEY uq_addon_volume_units_scope (volume,scope_hash);
-- New issued quotes pin original price, units, order, provider, native identity
-- and Groups. Old pending pays rows stay NULL, retain pinned original amounts
-- and use the legacy-global compatibility path with fail-closed reconciliation.
ALTER TABLE pays ADD COLUMN addon_scope_snapshot LONGTEXT COLLATE utf8mb4_bin DEFAULT NULL;
