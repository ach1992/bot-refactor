-- Empty schema construction only. Not a live database migration.
ALTER TABLE gift_list DROP COLUMN `server_offset`;
ALTER TABLE server_config DROP COLUMN `port_type`, DROP COLUMN `reality`;
ALTER TABLE server_plans DROP COLUMN `dest`, DROP COLUMN `serverNames`, DROP COLUMN `spiderX`;
ALTER TABLE server_config DROP COLUMN `ip`, DROP COLUMN `sni`, DROP COLUMN `header_type`, DROP COLUMN `request_header`, DROP COLUMN `response_header`, DROP COLUMN `security`, DROP COLUMN `tlsSettings`;
ALTER TABLE server_plans DROP COLUMN `limitip`, DROP COLUMN `rahgozar`, DROP COLUMN `flow`, DROP COLUMN `custom_path`, DROP COLUMN `custom_port`, DROP COLUMN `custom_sni`;
