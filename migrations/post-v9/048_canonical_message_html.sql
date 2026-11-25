-- Empty construction chain only. Historical message rows are preserved by the
-- separate approved data transfer, not replayed as pending operations.
ALTER TABLE bc_jobs DROP COLUMN parse_mode, ADD COLUMN new_html MEDIUMTEXT DEFAULT NULL AFTER new_text;
ALTER TABLE send_list ADD COLUMN text_html MEDIUMTEXT DEFAULT NULL AFTER text;
ALTER TABLE chats_info MODIFY COLUMN text TEXT NOT NULL;
