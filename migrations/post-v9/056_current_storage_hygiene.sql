-- Empty-schema construction only. Existing data requires controlled transfer
-- with a verified source archive; never drop populated historical evidence.
DELIMITER //
BEGIN NOT ATOMIC
    IF EXISTS (SELECT 1 FROM orders_list)
       OR EXISTS (SELECT 1 FROM server_plans)
       OR EXISTS (SELECT 1 FROM increase_order)
       OR EXISTS (SELECT 1 FROM payment_attempts)
       OR EXISTS (SELECT 1 FROM setting WHERE type IN ('ACHBOT_MARZBAN_PANEL_GROUP','ACHBOT_PANEL_GROUP'))
    THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Current storage hygiene requires a controlled data transfer';
    END IF;
END//
DELIMITER ;
ALTER TABLE orders_list DROP INDEX idx_orders_uuid, DROP COLUMN inbound_id;
ALTER TABLE server_plans DROP COLUMN inbound_id;
ALTER TABLE increase_order DROP COLUMN inbound_id;
ALTER TABLE payment_attempts DROP COLUMN review_notice_claimed_at, DROP COLUMN evidence_staff_digest;
ALTER TABLE server_config DROP INDEX idx_server_id;
