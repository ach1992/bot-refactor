-- Fresh construction. Retain historical content and leave unknown source IDs NULL.
ALTER TABLE chats
    ADD KEY idx_ticket_owner_queue (user_id,state,create_date,id),
    ADD KEY idx_ticket_staff_queue (state,create_date,id);
ALTER TABLE chats_info
    ADD COLUMN source_chat_id bigint(20) DEFAULT NULL,
    ADD COLUMN source_message_id bigint(20) unsigned DEFAULT NULL,
    ADD COLUMN content_kind varchar(8) DEFAULT NULL,
    ADD UNIQUE KEY uq_ticket_message_source (source_chat_id,source_message_id),
    ADD KEY idx_ticket_history (chat_id,sent_date,id),
    ADD CONSTRAINT ck_ticket_message_source CHECK (
        (source_chat_id IS NULL AND source_message_id IS NULL AND content_kind IS NULL)
        OR (source_chat_id IS NOT NULL AND source_chat_id > 0 AND source_message_id IS NOT NULL
            AND source_message_id > 0 AND content_kind IS NOT NULL AND content_kind IN ('text','photo')));
