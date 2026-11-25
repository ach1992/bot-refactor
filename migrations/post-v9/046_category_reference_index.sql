-- Empty schema construction only. Never apply to a deployed database.
ALTER TABLE server_plans ADD INDEX idx_server_plans_category (catid);
