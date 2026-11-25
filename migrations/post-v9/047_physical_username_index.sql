-- Empty schema construction only. Never apply to a deployed database.
ALTER TABLE service_effects ADD INDEX idx_effect_physical_username (provider_connection_id,remote_username);
