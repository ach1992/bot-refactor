-- Empty-install schema equivalence only; not a live migration.
-- Evidence redemption and service/notification execution now have separate owners.
ALTER TABLE payment_attempts
    DROP INDEX idx_payment_attempt_alerts,
    DROP COLUMN review_notified_at,
    DROP COLUMN evidence_review_claim_token,
    DROP COLUMN evidence_review_claimed_at,
    DROP COLUMN evidence_report_digest,
    DROP COLUMN evidence_fulfillment_state,
    DROP COLUMN evidence_fulfillment_payload;
