-- Empty schema construction only. Existing installations require a separately
-- authorized backup, scope audit, duplicate check, and controlled schema transition.
ALTER TABLE increase_day
 DROP INDEX uq_addon_day_units_scope, DROP COLUMN scope_hash,
 ADD COLUMN `scope_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin GENERATED ALWAYS AS (sha2(case when `server_id` is null and `group_ids` is null then 'G' when `server_id` is null or `server_id` <= 0 then NULL when `group_ids` is null then concat('S:',`server_id`) when json_valid(`group_ids`) then concat('P:',`server_id`,':',trim(both ']' from trim(both '[' from json_compact(`group_ids`)))) else NULL end,256)) STORED AFTER group_ids,
 ADD UNIQUE KEY uq_addon_day_units_scope (volume,scope_hash);
ALTER TABLE increase_plan
 DROP INDEX uq_addon_volume_units_scope, DROP COLUMN scope_hash,
 ADD COLUMN `scope_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin GENERATED ALWAYS AS (sha2(case when `server_id` is null and `group_ids` is null then 'G' when `server_id` is null or `server_id` <= 0 then NULL when `group_ids` is null then concat('S:',`server_id`) when json_valid(`group_ids`) then concat('P:',`server_id`,':',trim(both ']' from trim(both '[' from json_compact(`group_ids`)))) else NULL end,256)) STORED AFTER group_ids,
 ADD UNIQUE KEY uq_addon_volume_units_scope (volume,scope_hash);
