-- AChBot post-V9 R4: controlled ADDITIVE MariaDB schema change.
-- One optional field; all 23 original tables and pre-existing values remain.
-- Requires separately operator-authorized backup and recovery plan before
-- running on any installed system. No automatic production migration.
-- MariaDB ADD COLUMN IF NOT EXISTS is safe to rerun (no data loss).
ALTER TABLE server_info
    ADD COLUMN IF NOT EXISTS panel_group_id int(11) DEFAULT NULL AFTER flag;
