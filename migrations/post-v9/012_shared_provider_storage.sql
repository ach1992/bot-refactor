-- AChBot: consolidate native connection and operation evidence into existing tables.
-- SOURCE PREPARATION ONLY. Stop webhook and all leased Cron workers; take and
-- verify a restorable backup before a separately approved tenant execution.
-- Requires the exact 011 schema. DDL commits implicitly: do not rerun after a
-- partial failure. Keep the backup until runtime and financial reconciliation.
-- No payment/effect snapshot, hash, origin document or native account is changed.
DELIMITER //
CREATE PROCEDURE achbot_consolidate_provider_012()
BEGIN
 DECLARE failures BIGINT DEFAULT 0;
 DECLARE current_server_next BIGINT UNSIGNED DEFAULT 1;
 DECLARE current_effect_next BIGINT UNSIGNED DEFAULT 1;
 DECLARE legacy_panel_next BIGINT UNSIGNED DEFAULT 1;
 DECLARE legacy_account_next BIGINT UNSIGNED DEFAULT 1;
 -- Preserve allocated history, including deleted rows and orphan legacy configs.
 -- Capture counters before any ALTER can rebuild an InnoDB table.
 SELECT COALESCE(AUTO_INCREMENT,1) INTO current_server_next FROM information_schema.TABLES
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='server_info';
 SELECT COALESCE(AUTO_INCREMENT,1) INTO current_effect_next FROM information_schema.TABLES
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='service_effects';
 SELECT COALESCE(AUTO_INCREMENT,1) INTO legacy_panel_next FROM information_schema.TABLES
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='provider_panels';
 SELECT COALESCE(AUTO_INCREMENT,1) INTO legacy_account_next FROM information_schema.TABLES
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='provider_accounts';
 SELECT COUNT(*) INTO failures FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND ((TABLE_NAME='server_config' AND COLUMN_NAME='provider_connection_id')
   OR (TABLE_NAME='service_effects' AND COLUMN_NAME='provider_reference_id')
   OR (TABLE_NAME='orders_list' AND COLUMN_NAME='provider_effect_id'));
 IF failures<>0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='012 partial/already applied: audit before retry'; END IF;
 SELECT COUNT(*) INTO failures FROM provider_panels p LEFT JOIN server_info i ON i.provider_panel_id=p.id
  LEFT JOIN server_config c ON c.id=i.id AND c.type='pasarguard'
  WHERE c.id IS NULL;
 IF failures<>0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='012 connection has no complete server mapping'; END IF;
 SELECT COUNT(*) INTO failures FROM server_config c LEFT JOIN server_info i ON i.id=c.id
  LEFT JOIN provider_panels p ON p.id=i.provider_panel_id
  WHERE c.type='pasarguard' AND (i.id IS NULL OR p.id IS NULL OR p.provider<>'pasarguard'
   OR BINARY TRIM(TRAILING '/' FROM c.panel_url)<>BINARY TRIM(TRAILING '/' FROM p.api_url));
 IF failures<>0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='012 server connection mapping invalid'; END IF;
 SELECT COUNT(*) INTO failures FROM provider_accounts a LEFT JOIN service_effects e ON e.id=a.effect_id
  LEFT JOIN provider_panels p ON p.id=a.panel_id
  WHERE e.id IS NULL OR p.id IS NULL OR a.provider<>'pasarguard'
   OR a.instance_key<>p.instance_key OR a.endpoint_sha256<>SHA2(TRIM(TRAILING '/' FROM p.api_url),256)
   OR a.remote_username<>e.remote_username OR a.endpoint_sha256<>e.endpoint_sha256
   OR NOT (a.order_id<=>e.order_id) OR e.effect_kind NOT IN ('create','adopt')
   OR COALESCE(JSON_VALID(e.provider_snapshot),0)<>1 OR JSON_VALID(a.entitlement_snapshot)<>1
   OR NOT(JSON_UNQUOTE(JSON_EXTRACT(e.provider_snapshot,'$.provider'))<=>'pasarguard')
   OR NOT(JSON_TYPE(JSON_EXTRACT(e.provider_snapshot,'$.panel_id'))<=>'INTEGER')
   OR NOT(JSON_TYPE(JSON_EXTRACT(e.provider_snapshot,'$.instance_key'))<=>'STRING')
   OR NOT(JSON_TYPE(JSON_EXTRACT(e.provider_snapshot,'$.endpoint_sha256'))<=>'STRING')
   OR NOT(CAST(JSON_UNQUOTE(JSON_EXTRACT(e.provider_snapshot,'$.panel_id')) AS UNSIGNED)<=>a.panel_id)
   OR NOT(BINARY JSON_UNQUOTE(JSON_EXTRACT(e.provider_snapshot,'$.instance_key'))<=>BINARY a.instance_key)
   OR NOT(BINARY JSON_UNQUOTE(JSON_EXTRACT(e.provider_snapshot,'$.endpoint_sha256'))<=>BINARY a.endpoint_sha256)
   OR NOT(JSON_TYPE(JSON_EXTRACT(e.provider_snapshot,'$.group_ids'))<=>'ARRAY')
   OR (e.effect_kind='create' AND COALESCE(JSON_LENGTH(JSON_EXTRACT(e.provider_snapshot,'$.group_ids')),0)=0)
   OR NOT(JSON_TYPE(JSON_EXTRACT(a.entitlement_snapshot,'$.group_ids'))<=>'ARRAY')
   OR (e.effect_kind='create' AND COALESCE(JSON_LENGTH(JSON_EXTRACT(a.entitlement_snapshot,'$.group_ids')),0)=0)
   OR NOT(a.operation_marker<=>CONCAT(IF(e.effect_kind='adopt','achbot:adopt:','achbot:v1:'),e.operation_id))
   OR (a.remote_user_id IS NULL)<>(a.remote_created_at IS NULL)
   OR (a.mutation_snapshot IS NOT NULL AND JSON_VALID(a.mutation_snapshot)<>1);
 IF failures<>0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='012 native effect identity mapping invalid'; END IF;
 SELECT COUNT(*) INTO failures FROM (
  SELECT CONCAT('effect:',effect_id) AS ambiguous FROM provider_accounts GROUP BY effect_id HAVING COUNT(*)<>1
  UNION ALL SELECT CONCAT('order:',order_id) FROM provider_accounts WHERE order_id IS NOT NULL GROUP BY order_id HAVING COUNT(*)<>1
  UNION ALL SELECT CONCAT('native:',panel_id,':',remote_user_id) FROM provider_accounts WHERE remote_user_id IS NOT NULL GROUP BY panel_id,remote_user_id HAVING COUNT(*)<>1
  UNION ALL SELECT CONCAT('marker:',instance_key,':',operation_marker) FROM provider_accounts GROUP BY instance_key,operation_marker HAVING COUNT(*)<>1
 ) mappings;
 IF failures<>0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='012 ambiguous native evidence mapping'; END IF;
 SELECT COUNT(*) INTO failures FROM setting WHERE type LIKE 'ACHBOT_SERVICE_ORIGIN:%' AND JSON_VALID(value)<>1;
 IF failures<>0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='012 malformed subscription origin requires audit'; END IF;
 SELECT COUNT(*) INTO failures FROM setting s WHERE s.type LIKE 'ACHBOT_SERVICE_ORIGIN:%' AND
  (NOT(JSON_EXTRACT(IF(JSON_VALID(s.value),s.value,'{}'),'$.version')<=>1)
   OR NOT(JSON_TYPE(JSON_EXTRACT(IF(JSON_VALID(s.value),s.value,'{}'),'$.version'))<=>'INTEGER')
   OR NOT(JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(s.value),s.value,'{}'),'$.kind'))<=>'external_import')
   OR NOT(JSON_TYPE(JSON_EXTRACT(IF(JSON_VALID(s.value),s.value,'{}'),'$.order_id'))<=>'INTEGER')
   OR NOT(JSON_TYPE(JSON_EXTRACT(IF(JSON_VALID(s.value),s.value,'{}'),'$.owner_userid'))<=>'INTEGER')
   OR NOT(JSON_TYPE(JSON_EXTRACT(IF(JSON_VALID(s.value),s.value,'{}'),'$.actor_userid'))<=>'INTEGER')
   OR NOT(JSON_TYPE(JSON_EXTRACT(IF(JSON_VALID(s.value),s.value,'{}'),'$.server_id'))<=>'INTEGER')
   OR NOT(JSON_TYPE(JSON_EXTRACT(IF(JSON_VALID(s.value),s.value,'{}'),'$.native_identity'))<=>'OBJECT')
   OR NOT(JSON_TYPE(JSON_EXTRACT(IF(JSON_VALID(s.value),s.value,'{}'),'$.remark'))<=>'STRING')
   OR NOT(JSON_TYPE(JSON_EXTRACT(IF(JSON_VALID(s.value),s.value,'{}'),'$.proof_sha256'))<=>'STRING')
   OR NOT(JSON_TYPE(JSON_EXTRACT(IF(JSON_VALID(s.value),s.value,'{}'),'$.provider'))<=>'STRING')
   OR NOT(JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(s.value),s.value,'{}'),'$.provider')) IN ('marzban','pasarguard'))
   OR NOT(JSON_TYPE(JSON_EXTRACT(IF(JSON_VALID(s.value),s.value,'{}'),'$.capacity_reserved'))<=>'BOOLEAN')
   OR NOT(JSON_EXTRACT(IF(JSON_VALID(s.value),s.value,'{}'),'$.capacity_reserved')<=>FALSE));
 IF failures<>0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='012 incomplete subscription origin requires audit'; END IF;
 SELECT COUNT(*) INTO failures FROM orders_list o JOIN server_config c ON c.id=o.server_id
  LEFT JOIN provider_accounts a ON a.order_id=o.id
  LEFT JOIN setting s ON s.type=CONCAT('ACHBOT_SERVICE_ORIGIN:',o.id)
  LEFT JOIN provider_accounts linked ON linked.id=CAST(JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(s.value),s.value,'{}'),'$.provider_account_id')) AS UNSIGNED)
  WHERE c.type='pasarguard' AND (COALESCE(a.id,linked.id) IS NULL
   OR (a.id IS NOT NULL AND linked.id IS NOT NULL AND a.id<>linked.id)
   OR COALESCE(a.remote_user_id,linked.remote_user_id) IS NULL
   OR BINARY COALESCE(a.remote_username,linked.remote_username)<>BINARY o.remark
   OR (s.id IS NOT NULL AND NOT(JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(s.value),s.value,'{}'),'$.provider'))<=>'pasarguard')));
 IF failures<>0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='012 local subscription has no unique native evidence'; END IF;
 SELECT COUNT(*) INTO failures FROM (SELECT s.type,COUNT(*) n FROM setting s
  WHERE s.type LIKE 'ACHBOT_SERVICE_ORIGIN:%' GROUP BY s.type HAVING n<>1) duplicates;
 IF failures<>0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='012 duplicate subscription origin requires audit'; END IF;
 SELECT COUNT(*) INTO failures FROM setting s JOIN orders_list o ON s.type=CONCAT('ACHBOT_SERVICE_ORIGIN:',o.id)
  LEFT JOIN provider_accounts a ON a.id=CAST(JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(s.value),s.value,'{}'),'$.provider_account_id')) AS UNSIGNED)
  WHERE JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(s.value),s.value,'{}'),'$.provider'))='pasarguard' AND
   (a.id IS NULL
    OR NOT(JSON_TYPE(JSON_EXTRACT(IF(JSON_VALID(s.value),s.value,'{}'),'$.provider_account_id'))<=>'INTEGER')
    OR NOT(JSON_TYPE(JSON_EXTRACT(IF(JSON_VALID(s.value),s.value,'{}'),'$.native_identity.remote_user_id'))<=>'INTEGER')
    OR NOT(JSON_EXTRACT(IF(JSON_VALID(s.value),s.value,'{}'),'$.version')<=>1) OR NOT(JSON_EXTRACT(IF(JSON_VALID(s.value),s.value,'{}'),'$.capacity_reserved')<=>FALSE)
    OR NOT(CAST(JSON_EXTRACT(IF(JSON_VALID(s.value),s.value,'{}'),'$.owner_userid') AS UNSIGNED)<=>o.userid)
    OR NOT(CAST(JSON_EXTRACT(IF(JSON_VALID(s.value),s.value,'{}'),'$.order_id') AS UNSIGNED)<=>o.id)
    OR NOT(BINARY JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(s.value),s.value,'{}'),'$.remark'))<=>BINARY o.remark)
    OR NOT(CAST(JSON_EXTRACT(IF(JSON_VALID(s.value),s.value,'{}'),'$.native_identity.remote_user_id') AS UNSIGNED)<=>a.remote_user_id)
    OR NOT(BINARY JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(s.value),s.value,'{}'),'$.native_identity.remote_created_at'))<=>BINARY a.remote_created_at)
    OR NOT(BINARY JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(s.value),s.value,'{}'),'$.native_identity.remote_username'))<=>BINARY a.remote_username));
 IF failures<>0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='012 imported subscription identity mapping invalid'; END IF;
 ALTER TABLE server_config
  MODIFY panel_url VARCHAR(1000) COLLATE utf8mb4_bin NOT NULL,
  MODIFY password TEXT COLLATE utf8mb4_bin NOT NULL,
  ADD provider_connection_id INT DEFAULT NULL,
  ADD provider_instance_key CHAR(32) COLLATE utf8mb4_bin DEFAULT NULL,
  ADD provider_version VARCHAR(32) COLLATE utf8mb4_bin DEFAULT NULL,
  ADD provider_enabled TINYINT UNSIGNED NOT NULL DEFAULT 1,
  ADD KEY idx_server_provider_connection(provider_connection_id);
 ALTER TABLE service_effects
  ADD provider_reference_id BIGINT UNSIGNED DEFAULT NULL,
  ADD provider_connection_id INT DEFAULT NULL,
  ADD provider_remote_user_id BIGINT UNSIGNED DEFAULT NULL,
  ADD provider_remote_created_at VARCHAR(40) COLLATE utf8mb4_bin DEFAULT NULL,
  ADD provider_operation_marker VARCHAR(64) COLLATE utf8mb4_bin DEFAULT NULL,
  ADD provider_entitlement_snapshot LONGTEXT COLLATE utf8mb4_bin DEFAULT NULL,
  ADD provider_mutation_key VARCHAR(100) COLLATE utf8mb4_bin DEFAULT NULL,
  ADD provider_mutation_snapshot LONGTEXT COLLATE utf8mb4_bin DEFAULT NULL,
  ADD provider_state VARCHAR(24) COLLATE utf8mb4_bin DEFAULT NULL,
  ADD provider_verified_at DATETIME DEFAULT NULL,
  ADD provider_evidence_created_at DATETIME DEFAULT NULL,
  ADD provider_evidence_updated_at DATETIME DEFAULT NULL,
  ADD UNIQUE KEY uq_effect_provider_reference(provider_reference_id),
  ADD UNIQUE KEY uq_effect_provider_remote(provider_connection_id,provider_remote_user_id);
 ALTER TABLE orders_list
  ADD provider_effect_id BIGINT UNSIGNED DEFAULT NULL,
  ADD provider_remote_user_id BIGINT UNSIGNED DEFAULT NULL,
  ADD provider_remote_created_at VARCHAR(40) COLLATE utf8mb4_bin DEFAULT NULL,
  ADD KEY idx_order_provider_effect(provider_effect_id);
 UPDATE server_config c JOIN server_info i ON i.id=c.id JOIN provider_panels p ON p.id=i.provider_panel_id
  SET c.panel_url=p.api_url,c.password=p.api_key,c.provider_connection_id=p.id,
   c.provider_instance_key=p.instance_key,c.provider_version=p.version,c.provider_enabled=p.enabled
  WHERE c.type='pasarguard';
 UPDATE service_effects e JOIN provider_accounts a ON a.effect_id=e.id
  SET e.provider_reference_id=a.id,e.provider_connection_id=a.panel_id,e.provider_remote_user_id=a.remote_user_id,
   e.provider_remote_created_at=a.remote_created_at,e.provider_operation_marker=a.operation_marker,
   e.provider_entitlement_snapshot=a.entitlement_snapshot,e.provider_mutation_key=a.mutation_key,
   e.provider_mutation_snapshot=a.mutation_snapshot,e.provider_state=a.state,e.provider_verified_at=a.verified_at,
   e.provider_evidence_created_at=a.created_at,e.provider_evidence_updated_at=a.updated_at,e.updated_at=e.updated_at;
 UPDATE orders_list o JOIN provider_accounts a ON a.order_id=o.id
  SET o.provider_effect_id=a.effect_id,o.provider_remote_user_id=a.remote_user_id,o.provider_remote_created_at=a.remote_created_at;
 UPDATE orders_list o JOIN setting s ON s.type=CONCAT('ACHBOT_SERVICE_ORIGIN:',o.id)
  JOIN provider_accounts a ON a.id=CAST(JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(s.value),s.value,'{}'),'$.provider_account_id')) AS UNSIGNED)
  SET o.provider_effect_id=a.effect_id,o.provider_remote_user_id=a.remote_user_id,o.provider_remote_created_at=a.remote_created_at
  WHERE JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(s.value),s.value,'{}'),'$.provider'))='pasarguard';
 -- Verify every transferred field before either source table can be dropped.
 SELECT COUNT(*) INTO failures FROM provider_panels p JOIN server_info i ON i.provider_panel_id=p.id
  JOIN server_config c ON c.id=i.id WHERE NOT(c.provider_connection_id<=>p.id)
   OR NOT(BINARY c.panel_url<=>BINARY p.api_url) OR NOT(BINARY c.password<=>BINARY p.api_key)
   OR NOT(BINARY c.provider_instance_key<=>BINARY p.instance_key) OR NOT(BINARY c.provider_version<=>BINARY p.version)
   OR NOT(c.provider_enabled<=>p.enabled);
 IF failures<>0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='012 connection transfer unconfirmed: sources retained'; END IF;
 SELECT COUNT(*) INTO failures FROM provider_accounts a JOIN service_effects e ON e.id=a.effect_id
  WHERE NOT(e.provider_reference_id<=>a.id) OR NOT(e.provider_connection_id<=>a.panel_id)
   OR NOT(e.provider_remote_user_id<=>a.remote_user_id) OR NOT(BINARY e.provider_remote_created_at<=>BINARY a.remote_created_at)
   OR NOT(BINARY e.provider_operation_marker<=>BINARY a.operation_marker)
   OR NOT(BINARY e.provider_entitlement_snapshot<=>BINARY a.entitlement_snapshot)
   OR NOT(BINARY e.provider_mutation_key<=>BINARY a.mutation_key) OR NOT(BINARY e.provider_mutation_snapshot<=>BINARY a.mutation_snapshot)
   OR NOT(BINARY e.provider_state<=>BINARY a.state) OR NOT(e.provider_verified_at<=>a.verified_at)
   OR NOT(e.provider_evidence_created_at<=>a.created_at) OR NOT(e.provider_evidence_updated_at<=>a.updated_at);
 IF failures<>0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='012 native evidence transfer unconfirmed: sources retained'; END IF;
 SELECT COUNT(*) INTO failures FROM orders_list o JOIN server_config c ON c.id=o.server_id
  LEFT JOIN service_effects e ON e.id=o.provider_effect_id WHERE c.type='pasarguard'
   AND (e.id IS NULL OR NOT(o.provider_remote_user_id<=>e.provider_remote_user_id)
    OR NOT(BINARY o.provider_remote_created_at<=>BINARY e.provider_remote_created_at));
 IF failures<>0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='012 order transfer unconfirmed: sources retained'; END IF;
 -- Reserve numeric space above immutable legacy snapshot IDs for new server/effect IDs.
 SELECT GREATEST(current_server_next,legacy_panel_next,COALESCE(MAX(id),0)+1,
  (SELECT COALESCE(MAX(id),0)+1 FROM provider_panels),
  (SELECT COALESCE(MAX(id),0)+1 FROM server_config)) INTO @achbot012_server_next FROM server_info;
 SET @achbot012_sql=CONCAT('ALTER TABLE server_info AUTO_INCREMENT=',@achbot012_server_next);
 PREPARE achbot012_stmt FROM @achbot012_sql;EXECUTE achbot012_stmt;DEALLOCATE PREPARE achbot012_stmt;
 SELECT GREATEST(current_effect_next,legacy_account_next,COALESCE(MAX(id),0)+1,
  (SELECT COALESCE(MAX(id),0)+1 FROM provider_accounts)) INTO @achbot012_effect_next FROM service_effects;
 SET @achbot012_sql=CONCAT('ALTER TABLE service_effects AUTO_INCREMENT=',@achbot012_effect_next);
 PREPARE achbot012_stmt FROM @achbot012_sql;EXECUTE achbot012_stmt;DEALLOCATE PREPARE achbot012_stmt;
 ALTER TABLE server_info DROP KEY idx_server_provider_panel,DROP COLUMN provider_panel_id;
 DROP TABLE provider_accounts;
 DROP TABLE provider_panels;
END//
DELIMITER ;
CALL achbot_consolidate_provider_012();
DROP PROCEDURE achbot_consolidate_provider_012;
