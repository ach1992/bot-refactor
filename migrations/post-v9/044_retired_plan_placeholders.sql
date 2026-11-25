-- Empty schema construction only. Never apply to a deployed database.
ALTER TABLE server_plans
    DROP COLUMN `acount`,
    DROP COLUMN `fileid`,
    DROP COLUMN `pic`,
    DROP COLUMN `protocol`,
    DROP COLUMN `step`,
    DROP COLUMN `type`;
