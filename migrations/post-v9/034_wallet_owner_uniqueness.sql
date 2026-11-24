-- Wallet-first cutover candidate only. Quiesce all writers, audit actual
-- duplicate identities, and prove backup/restore BEFORE this DDL.
-- Never merge/delete duplicate owners or change any existing balance.
-- The existing index name remains the forced-locking identity contract.
ALTER TABLE users DROP INDEX idx_users_userid_v1,
    ADD UNIQUE INDEX idx_users_userid_v1 (userid);
