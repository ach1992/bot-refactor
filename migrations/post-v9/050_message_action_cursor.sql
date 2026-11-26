-- Empty schema construction only; match the acknowledged message identity.
ALTER TABLE bc_jobs MODIFY COLUMN `offset` bigint unsigned NOT NULL DEFAULT 0;
