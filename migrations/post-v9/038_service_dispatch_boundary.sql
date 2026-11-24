-- Offline empty-schema construction evidence only. Never apply to a live database.
ALTER TABLE service_mutations
  ADD COLUMN dispatch_started_at DATETIME(6) DEFAULT NULL AFTER intent_sha256,
  ADD CONSTRAINT ck_service_mutation_dispatch CHECK (
    (phase <> 'observe' OR dispatch_started_at IS NULL)
    AND (reset_ack_at IS NULL AND phase NOT IN ('verified','completed') OR dispatch_started_at IS NOT NULL));
