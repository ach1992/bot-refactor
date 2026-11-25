-- Empty-install construction proof only; never apply to a live bot.
ALTER TABLE payment_attempts DROP COLUMN review_archived_at, DROP COLUMN review_archived_by;
