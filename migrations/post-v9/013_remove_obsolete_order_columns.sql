-- AChBot: apply only after installing the reviewed current runtime.
-- Restore requires the operator's verified pre-migration backup; legacy column
-- contents are intentionally removed. Financial/native identity fields remain.
-- Frozen database/legacy-schema.sql is historical and is never edited.
ALTER TABLE orders_list
 DROP COLUMN IF EXISTS protocol,
 DROP COLUMN IF EXISTS rahgozar;
