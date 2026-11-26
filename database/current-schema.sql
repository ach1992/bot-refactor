-- AChBot Wallet-first: complete EMPTY schema for first installation.
-- Use once in a dedicated empty database. Contains no tenant data, automatic upgrade or cleanup.
-- Based on the reviewed schema sequence through 034; five unused historical tables omitted.
/*M!999999\- enable the sandbox mode */
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*M!100616 SET @OLD_NOTE_VERBOSITY=@@NOTE_VERBOSITY, NOTE_VERBOSITY=0 */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `add_link` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `remark` varchar(100) NOT NULL,
  `token` varchar(255) NOT NULL,
  `added_by` bigint(20) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `added_by_idx` (`added_by`),
  KEY `remark_idx` (`remark`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `bc_jobs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `broadcast_id` int(11) NOT NULL,
  `action` enum('pin','unpin','delete','edit') NOT NULL,
  `new_text` text DEFAULT NULL,
  `new_html` mediumtext DEFAULT NULL,
  `ok_cnt` int(11) NOT NULL DEFAULT 0,
  `fail_cnt` int(11) NOT NULL DEFAULT 0,
  `offset` bigint(20) unsigned NOT NULL DEFAULT 0,
  `state` tinyint(4) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_state` (`state`),
  KEY `idx_broadcast_action` (`broadcast_id`,`action`),
  KEY `idx_bc_state_updated_id` (`state`,`updated_at`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `bc_messages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `broadcast_id` int(11) NOT NULL,
  `userid` bigint(20) NOT NULL,
  `message_id` int(11) NOT NULL,
  `pinned` tinyint(1) NOT NULL DEFAULT 0,
  `deleted` tinyint(1) NOT NULL DEFAULT 0,
  `sent_at` datetime NOT NULL DEFAULT current_timestamp(),
  `pinned_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_broadcast_user` (`broadcast_id`,`userid`),
  KEY `idx_broadcast` (`broadcast_id`),
  KEY `idx_userid` (`userid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `chats` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) NOT NULL,
  `create_date` int(11) NOT NULL,
  `title` varchar(1000) NOT NULL,
  `category` varchar(1000) NOT NULL,
  `state` int(11) NOT NULL,
  `rate` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `chats_info` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `chat_id` int(11) NOT NULL,
  `sent_date` int(11) NOT NULL,
  `msg_type` varchar(50) DEFAULT NULL,
  `text` text NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `discounts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `hash_id` varchar(100) NOT NULL,
  `type` varchar(10) NOT NULL,
  `amount` int(11) NOT NULL,
  `expire_date` int(11) NOT NULL,
  `expire_count` int(11) NOT NULL,
  `used_by` text DEFAULT NULL,
  `can_use` int(11) NOT NULL DEFAULT 1,
  `audience_type` varchar(32) NOT NULL DEFAULT 'all',
  `audience_user_id` bigint(20) DEFAULT NULL,
  `target_type` varchar(32) NOT NULL DEFAULT 'all',
  `target_id` int(11) DEFAULT NULL,
  `min_price` int(11) NOT NULL DEFAULT 0,
  `max_discount` int(11) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `gift_list` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `server_id` int(11) NOT NULL,
  `volume` int(11) NOT NULL,
  `day` int(11) NOT NULL,
  `offset` int(11) DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `increase_day` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `volume` float NOT NULL,
  `price` int(11) NOT NULL,
  `server_id` int(11) DEFAULT NULL,
  `group_ids` text CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `scope_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '333e0a1e27815d0ceee55c473fe3dc93d56c63e3bee2b3b4aee8eed6d70191a3',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_addon_day_units_scope` (`volume`,`scope_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `increase_order` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `userid` varchar(30) NOT NULL,
  `server_id` int(11) NOT NULL,
  `inbound_id` int(11) NOT NULL,
  `remark` varchar(100) NOT NULL,
  `amount` int(11) NOT NULL,
  `date` varchar(30) NOT NULL,
  `volume` int(11) DEFAULT NULL,
  `day` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `increase_plan` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `volume` float NOT NULL,
  `price` int(11) NOT NULL,
  `server_id` int(11) DEFAULT NULL,
  `group_ids` text CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `scope_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '333e0a1e27815d0ceee55c473fe3dc93d56c63e3bee2b3b4aee8eed6d70191a3',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_addon_volume_units_scope` (`volume`,`scope_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `needed_sofwares` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(100) NOT NULL,
  `link` varchar(250) NOT NULL,
  `status` int(11) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `orders_list` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `userid` bigint(20) NOT NULL,
  `token` varchar(1000) NOT NULL,
  `transid` varchar(150) NOT NULL,
  `fileid` int(11) NOT NULL,
  `server_id` int(11) NOT NULL,
  `inbound_id` int(11) NOT NULL DEFAULT 0,
  `remark` varchar(100) NOT NULL,
  `uuid` varchar(200) DEFAULT NULL,
  `expire_date` int(11) NOT NULL,
  `link` text NOT NULL,
  `amount` int(11) NOT NULL,
  `status` int(11) NOT NULL,
  `date` varchar(50) NOT NULL,
  `notif` int(11) NOT NULL DEFAULT 0,
  `agent_bought` int(11) NOT NULL DEFAULT 0,
  `autoRenew` varchar(10) DEFAULT 'off',
  `provider_effect_id` bigint(20) unsigned DEFAULT NULL,
  `provider_remote_user_id` bigint(20) unsigned DEFAULT NULL,
  `provider_remote_created_at` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_remark_userid` (`remark`,`userid`),
  KEY `idx_orders_status_notif` (`status`,`notif`),
  KEY `idx_orders_uuid` (`uuid`),
  KEY `idx_orders_userid_v1` (`userid`),
  KEY `idx_order_provider_effect` (`provider_effect_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment_attempts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `pay_row_id` int(11) NOT NULL,
  `provider` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `order_hash` varchar(50) NOT NULL,
  `provider_reference` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `payment_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `checkout_url` varchar(2048) DEFAULT NULL,
  `quote_amount` decimal(24,8) NOT NULL,
  `quote_currency` varchar(16) NOT NULL,
  `local_amount_toman` int(11) NOT NULL DEFAULT 0,
  `settlement_currency` varchar(32) DEFAULT NULL,
  `minimum_settlement_amount` decimal(24,8) NOT NULL DEFAULT 0.00000000,
  `stage` varchar(32) NOT NULL DEFAULT 'pending',
  `provider_status` varchar(32) DEFAULT NULL,
  `last_checked_at` datetime DEFAULT NULL,
  `review_notice_claim_token` char(32) DEFAULT NULL,
  `review_notice_claimed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `evidence_profile` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `evidence_state` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `evidence_cursor_id` bigint(20) unsigned DEFAULT NULL,
  `evidence_claim_token` char(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `evidence_claimed_at` datetime DEFAULT NULL,
  `evidence_next_check_at` datetime DEFAULT NULL,
  `evidence_code` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `evidence_code_sha256` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `evidence_review_sent_at` datetime DEFAULT NULL,
  `evidence_projection_checked_at` datetime DEFAULT NULL,
  `evidence_staff_digest` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `wallet_invoice_sha256` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `wallet_merchant_profile` text CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `wallet_credit_entry_id` bigint(20) unsigned DEFAULT NULL,
  `wallet_credit_basis` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `wallet_credit_transaction_id` bigint(20) unsigned DEFAULT NULL,
  `service_operation_id` bigint(20) unsigned DEFAULT NULL,
  `wallet_funding_result` varchar(48) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `invoice_expires_at` bigint(20) unsigned DEFAULT NULL,
  `receipt_grace_seconds` bigint(20) unsigned DEFAULT NULL,
  `receipt_started_at` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payment_attempt_pay_row` (`pay_row_id`),
  UNIQUE KEY `uq_payment_attempt_order_hash` (`order_hash`),
  UNIQUE KEY `uq_payment_attempt_provider` (`id`,`provider`),
  UNIQUE KEY `uq_payment_attempt_ref` (`provider`,`provider_reference`),
  UNIQUE KEY `uq_payment_attempt_payment` (`provider`,`payment_id`),
  UNIQUE KEY `uq_wallet_credit_entry` (`wallet_credit_entry_id`),
  UNIQUE KEY `uq_wallet_credit_transaction` (`wallet_credit_transaction_id`),
  KEY `idx_payment_attempt_reconcile` (`provider`,`stage`,`last_checked_at`),
  KEY `idx_payment_evidence_due` (`provider`,`evidence_state`,`evidence_next_check_at`),
  KEY `idx_payment_service_operation` (`service_operation_id`),
  KEY `idx_attempt_deadline` (`stage`,`invoice_expires_at`,`pay_row_id`),
  CONSTRAINT `fk_payment_attempt_order` FOREIGN KEY (`pay_row_id`) REFERENCES `pays` (`id`),
  CONSTRAINT `fk_payment_service_operation` FOREIGN KEY (`service_operation_id`) REFERENCES `service_operations` (`id`),
  CONSTRAINT `fk_wallet_credit_entry` FOREIGN KEY (`wallet_credit_entry_id`) REFERENCES `wallet_entries` (`id`),
  CONSTRAINT `fk_wallet_credit_transaction` FOREIGN KEY (`wallet_credit_transaction_id`) REFERENCES `payment_transactions` (`id`),
  CONSTRAINT `ck_wallet_invoice_admission` CHECK (`wallet_invoice_sha256` is null or `wallet_invoice_sha256` = '' or `wallet_invoice_sha256` regexp '^[0-9a-f]{64}$'),
  CONSTRAINT `ck_wallet_credit_link` CHECK (`wallet_credit_entry_id` is null and `wallet_credit_basis` is null and `wallet_credit_transaction_id` is null or `wallet_invoice_sha256` is not null and `wallet_invoice_sha256` <> '' and `wallet_credit_entry_id` is not null and `wallet_credit_basis` is not null),
  CONSTRAINT `ck_payment_funding_result` CHECK (`wallet_funding_result` is null or `wallet_credit_entry_id` is not null)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment_delivery_attempts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `intent_id` bigint(20) unsigned NOT NULL,
  `attempt_no` int(10) unsigned NOT NULL,
  `dispatch_token` char(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `actor_userid` bigint(20) NOT NULL DEFAULT 0,
  `result` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT 'dispatching',
  `started_at` datetime NOT NULL DEFAULT current_timestamp(),
  `finished_at` datetime DEFAULT NULL,
  `part_no` int(10) unsigned NOT NULL,
  `claim_state` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `telegram_error_code` smallint(5) unsigned DEFAULT NULL,
  `retry_after_seconds` int(10) unsigned DEFAULT NULL,
  `ack_chat_id` bigint(20) DEFAULT NULL,
  `ack_message_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_delivery_attempt_no` (`intent_id`,`attempt_no`),
  UNIQUE KEY `uq_delivery_dispatch_token` (`dispatch_token`),
  KEY `idx_delivery_part_attempt` (`intent_id`,`part_no`,`attempt_no`),
  CONSTRAINT `fk_delivery_attempt_intent` FOREIGN KEY (`intent_id`) REFERENCES `payment_delivery_intents` (`id`),
  CONSTRAINT `fk_delivery_attempt_part` FOREIGN KEY (`intent_id`, `part_no`) REFERENCES `payment_delivery_parts` (`intent_id`, `part_no`),
  CONSTRAINT `ck_delivery_attempt_ack_identity` CHECK ((`ack_chat_id` is null and `ack_message_id` is null or `result` = 'acknowledged' and `ack_chat_id` is not null and `ack_chat_id` <> 0 and `ack_message_id` is not null and `ack_message_id` > 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment_delivery_intents` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ordinal` int(10) unsigned NOT NULL,
  `effect_id` bigint(20) unsigned DEFAULT NULL,
  `order_id` int(11) NOT NULL,
  `user_id` bigint(20) NOT NULL,
  `server_id` int(11) NOT NULL DEFAULT 0,
  `source_digest` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `native_operation` char(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `native_target_key` varchar(48) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `sealed_payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `payload_sha256` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `state` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT 'pending',
  `attempt_count` int(10) unsigned NOT NULL DEFAULT 0,
  `dispatch_token` char(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `dispatched_at` datetime DEFAULT NULL,
  `acknowledged_at` datetime DEFAULT NULL,
  `last_actor` bigint(20) DEFAULT NULL,
  `last_result` varchar(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `service_operation_id` bigint(20) unsigned DEFAULT NULL,
  `part_count` int(10) unsigned NOT NULL,
  `next_attempt_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_delivery_effect` (`effect_id`),
  UNIQUE KEY `uq_delivery_service_ordinal` (`service_operation_id`,`ordinal`),
  KEY `idx_delivery_work` (`state`,`updated_at`),
  KEY `idx_delivery_due` (`state`,`next_attempt_at`,`id`),
  CONSTRAINT `fk_delivery_effect` FOREIGN KEY (`effect_id`) REFERENCES `service_effects` (`id`),
  CONSTRAINT `fk_delivery_service_operation` FOREIGN KEY (`service_operation_id`) REFERENCES `service_operations` (`id`),
  CONSTRAINT `ck_delivery_source_v2` CHECK ((`service_operation_id` is not null or `effect_id` is not null) and `part_count` > 0 and `order_id` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment_delivery_parts` (
  `intent_id` bigint(20) unsigned NOT NULL,
  `part_no` int(10) unsigned NOT NULL,
  `payload_sha256` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `state` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT 'pending',
  `last_attempt_no` int(10) unsigned DEFAULT NULL,
  `next_attempt_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`intent_id`,`part_no`),
  CONSTRAINT `fk_delivery_part_intent` FOREIGN KEY (`intent_id`) REFERENCES `payment_delivery_intents` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment_followup_attempts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `intent_id` bigint(20) unsigned NOT NULL,
  `attempt_no` int(10) unsigned NOT NULL,
  `dispatch_token` char(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `actor_userid` bigint(20) NOT NULL DEFAULT 0,
  `claim_state` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `wire_action` varchar(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `wire_recipient` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `wire_message_id` bigint(20) unsigned DEFAULT NULL,
  `result` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT 'dispatching',
  `telegram_error_code` smallint(5) unsigned DEFAULT NULL,
  `retry_after_seconds` int(10) unsigned DEFAULT NULL,
  `failure_reason` varchar(48) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `ack_chat_id` bigint(20) DEFAULT NULL,
  `ack_message_id` bigint(20) unsigned DEFAULT NULL,
  `started_at` datetime(6) NOT NULL DEFAULT current_timestamp(6),
  `finished_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_followup_attempt_no` (`intent_id`,`attempt_no`),
  UNIQUE KEY `uq_followup_attempt_token` (`dispatch_token`),
  CONSTRAINT `fk_followup_attempt_intent` FOREIGN KEY (`intent_id`) REFERENCES `payment_followup_intents` (`id`),
  CONSTRAINT `ck_followup_attempt_ack_identity` CHECK ((`ack_chat_id` is null and `ack_message_id` is null or `result` = 'acknowledged' and `ack_chat_id` is not null and `ack_chat_id` <> 0 and `ack_message_id` is not null and `ack_message_id` > 0)),
  CONSTRAINT `ck_followup_attempt_wire` CHECK ((`wire_action` = 'send' and `wire_message_id` is null or `wire_action` in ('edit_text','edit_keys') and `wire_message_id` is not null and `wire_message_id` > 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment_followup_intents` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `pay_row_id` int(11) DEFAULT NULL,
  `intent_key` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `stage` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `kind` varchar(8) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `role` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `recipient` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `message_id` int(11) DEFAULT NULL,
  `pay_fingerprint` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `sealed_payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `payload_sha256` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `state` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT 'pending',
  `attempt_count` int(10) unsigned NOT NULL DEFAULT 0,
  `dispatch_token` char(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `dispatched_at` datetime DEFAULT NULL,
  `acknowledged_at` datetime DEFAULT NULL,
  `last_result` varchar(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `service_operation_id` bigint(20) unsigned DEFAULT NULL,
  `next_attempt_at` datetime(6) DEFAULT NULL,
  `wallet_entry_id` bigint(20) unsigned DEFAULT NULL,
  `operational_ref` varchar(128) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_followup_pay_key` (`pay_row_id`,`intent_key`),
  UNIQUE KEY `uq_followup_service_key` (`service_operation_id`,`intent_key`),
  UNIQUE KEY `uq_followup_wallet_key` (`wallet_entry_id`,`intent_key`),
  UNIQUE KEY `uq_followup_operational_key` (`operational_ref`,`intent_key`),
  KEY `idx_followup_pending` (`state`,`updated_at`),
  KEY `idx_followup_due` (`state`,`next_attempt_at`,`id`),
  CONSTRAINT `fk_followup_payment` FOREIGN KEY (`pay_row_id`) REFERENCES `pays` (`id`),
  CONSTRAINT `fk_followup_service` FOREIGN KEY (`service_operation_id`) REFERENCES `service_operations` (`id`),
  CONSTRAINT `fk_followup_wallet` FOREIGN KEY (`wallet_entry_id`) REFERENCES `wallet_entries` (`id`),
  CONSTRAINT `ck_followup_original_source` CHECK (`operational_ref` is null and (`wallet_entry_id` is null and (`pay_row_id` is not null or `service_operation_id` is not null) or `wallet_entry_id` is not null and `pay_row_id` is null and `service_operation_id` is null) or `operational_ref` is not null and `wallet_entry_id` is null and `pay_row_id` is null and `service_operation_id` is null)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment_message_refs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `pay_row_id` int(11) NOT NULL,
  `chat_id` bigint(20) NOT NULL,
  `message_id` int(11) NOT NULL,
  `kind` varchar(8) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT 'text',
  `purpose` varchar(12) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT 'status',
  `last_status_update_id` bigint(20) unsigned DEFAULT NULL,
  `last_status_requested_at` datetime(6) DEFAULT NULL,
  `retired_reason` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `synced_digest` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime(6) NOT NULL DEFAULT current_timestamp(6) ON UPDATE current_timestamp(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payment_message_chat` (`chat_id`,`message_id`),
  KEY `idx_payment_message_order` (`pay_row_id`,`retired_reason`,`updated_at`),
  CONSTRAINT `fk_payment_message_pay` FOREIGN KEY (`pay_row_id`) REFERENCES `pays` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment_transactions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `attempt_id` bigint(20) unsigned NOT NULL,
  `provider` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `payment_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `observed_status` varchar(32) DEFAULT NULL,
  `received_at` datetime NOT NULL DEFAULT current_timestamp(),
  `evidence_state` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `evidence_operation` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `evidence_payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `evidence_dispatched_at` datetime DEFAULT NULL,
  `evidence_proven_at` datetime DEFAULT NULL,
  `merchant_checked_at` datetime(6) DEFAULT NULL,
  `merchant_verified_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payment_transaction_reference` (`provider`,`payment_id`),
  UNIQUE KEY `uq_payment_evidence_operation` (`evidence_operation`),
  KEY `idx_payment_transaction_attempt` (`attempt_id`,`provider`),
  KEY `idx_merchant_due` (`provider`,`merchant_verified_at`,`merchant_checked_at`,`id`),
  CONSTRAINT `fk_payment_transaction_attempt` FOREIGN KEY (`attempt_id`, `provider`) REFERENCES `payment_attempts` (`id`, `provider`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `pays` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `hash_id` varchar(50) NOT NULL,
  `description` varchar(5000) DEFAULT NULL,
  `payid` varchar(500) DEFAULT NULL,
  `user_id` bigint(20) NOT NULL,
  `type` varchar(100) DEFAULT NULL,
  `plan_id` int(11) DEFAULT NULL,
  `server_id` int(11) DEFAULT NULL,
  `volume` int(11) DEFAULT NULL,
  `day` int(11) DEFAULT NULL,
  `price` int(11) NOT NULL,
  `request_date` int(11) NOT NULL,
  `state` varchar(255) NOT NULL,
  `agent_bought` int(11) NOT NULL DEFAULT 0,
  `agent_count` int(11) NOT NULL DEFAULT 0,
  `message_id` int(11) DEFAULT NULL,
  `chat_id` varchar(500) DEFAULT NULL,
  `order_id` int(11) DEFAULT NULL,
  `admins_messages` text DEFAULT NULL,
  `approved_by` bigint(20) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `provider_snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `addon_scope_snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `checkout_draft_context` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`checkout_draft_context`)),
  `checkout_operation_id` bigint(20) unsigned DEFAULT NULL,
  `draft_expires_at` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `hash_id` (`hash_id`),
  UNIQUE KEY `uq_checkout_operation` (`checkout_operation_id`),
  KEY `idx_pays_state_type_v1` (`state`,`type`),
  KEY `idx_pays_user_v1` (`user_id`),
  KEY `idx_pays_price_v1` (`price`),
  KEY `idx_pays_state_updated_at` (`state`,`updated_at`),
  KEY `idx_pays_order_ref_v1` (`order_id`,`id`),
  KEY `idx_pays_type_ref_v1` (`type`,`id`),
  KEY `idx_pays_draft_deadline` (`state`,`draft_expires_at`,`id`),
  CONSTRAINT `fk_checkout_operation` FOREIGN KEY (`checkout_operation_id`) REFERENCES `service_operations` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `referral_awards` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `pay_row_id` int(11) NOT NULL,
  `buyer_userid` bigint(20) NOT NULL,
  `inviter_userid` bigint(20) NOT NULL,
  `amount_toman` int(10) unsigned NOT NULL,
  `state` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `before_balance` int(11) DEFAULT NULL,
  `after_balance` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `confirmed_at` datetime DEFAULT NULL,
  `review_at` datetime DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `service_operation_id` bigint(20) unsigned DEFAULT NULL,
  `inviter_user_row_id` int(11) DEFAULT NULL,
  `wallet_entry_id` bigint(20) unsigned DEFAULT NULL,
  `next_attempt_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_referral_award_payment` (`pay_row_id`),
  UNIQUE KEY `uq_referral_service` (`service_operation_id`),
  UNIQUE KEY `uq_referral_wallet_entry` (`wallet_entry_id`),
  KEY `idx_referral_award_review` (`state`,`updated_at`),
  KEY `idx_referral_award_inviter` (`inviter_userid`,`created_at`),
  KEY `idx_referral_service_due` (`state`,`next_attempt_at`,`id`),
  CONSTRAINT `fk_referral_award_payment` FOREIGN KEY (`pay_row_id`) REFERENCES `pays` (`id`),
  CONSTRAINT `fk_referral_service` FOREIGN KEY (`service_operation_id`) REFERENCES `service_operations` (`id`),
  CONSTRAINT `fk_referral_wallet_entry` FOREIGN KEY (`wallet_entry_id`) REFERENCES `wallet_entries` (`id`),
  CONSTRAINT `ck_referral_service_claim` CHECK (`service_operation_id` is null or `state` = 'NOT_ATTEMPTED' and `inviter_user_row_id` is not null and `amount_toman` > 0 and `wallet_entry_id` is null and `next_attempt_at` is not null or `state` = 'CONFIRMED' and `inviter_user_row_id` is not null and `amount_toman` > 0 and `wallet_entry_id` is not null and `next_attempt_at` is null or `state` in ('DISABLED','NEEDS_REVIEW') and `wallet_entry_id` is null and `next_attempt_at` is null)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `send_list` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `offset` int(11) NOT NULL DEFAULT 0,
  `type` varchar(20) NOT NULL,
  `title` varchar(255) DEFAULT NULL,
  `text` text DEFAULT NULL,
  `text_html` mediumtext DEFAULT NULL,
  `chat_id` bigint(20) DEFAULT NULL,
  `message_id` int(11) DEFAULT NULL,
  `file_id` varchar(500) DEFAULT NULL,
  `state` int(11) NOT NULL DEFAULT 0,
  `target_group` varchar(10) DEFAULT 'all',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  `sample_userid` bigint(20) DEFAULT NULL,
  `sample_message_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_state_id` (`state`,`id`),
  KEY `idx_state_created` (`state`,`created_at`),
  KEY `idx_state_target` (`state`,`target_group`),
  KEY `idx_state` (`state`),
  KEY `idx_updated_at` (`updated_at`),
  KEY `idx_state_updated_id` (`state`,`updated_at`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `server_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(50) NOT NULL,
  `parent` int(11) NOT NULL DEFAULT 0,
  `active` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `server_config` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `panel_url` varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `type` varchar(100) DEFAULT NULL,
  `username` varchar(100) NOT NULL,
  `password` text CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `sub_links` mediumtext DEFAULT NULL,
  `provider_connection_id` int(11) DEFAULT NULL,
  `provider_instance_key` char(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `provider_version` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `provider_enabled` tinyint(3) unsigned NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_server_id` (`id`),
  KEY `idx_server_provider_connection` (`provider_connection_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `server_info` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(200) NOT NULL,
  `ucount` varchar(20) NOT NULL,
  `remark` varchar(100) NOT NULL,
  `flag` varchar(100) NOT NULL,
  `panel_group_id` int(11) DEFAULT NULL,
  `active` int(11) NOT NULL DEFAULT 0,
  `state` int(11) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `server_plans` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `catid` int(11) NOT NULL,
  `server_id` int(11) NOT NULL,
  `inbound_id` int(11) NOT NULL DEFAULT 0,
  `title` varchar(150) NOT NULL,
  `days` float NOT NULL,
  `volume` float NOT NULL,
  `price` int(11) NOT NULL,
  `descr` text NOT NULL,
  `active` int(11) NOT NULL DEFAULT 0,
  `date` varchar(50) NOT NULL,
  `provider_group_ids` text CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_server_plans_category` (`catid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_effects` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `pay_row_id` int(11) DEFAULT NULL,
  `source_kind` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `source_ref` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `actor_userid` bigint(20) DEFAULT NULL,
  `ordinal` int(10) unsigned NOT NULL,
  `reserved_count` int(10) unsigned NOT NULL DEFAULT 0,
  `operation_id` char(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `effect_kind` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT 'create',
  `target_server_id` int(11) NOT NULL,
  `target_group_id` int(11) DEFAULT NULL,
  `target_key` varchar(48) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `endpoint_sha256` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `plan_id` int(11) NOT NULL,
  `remote_username` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `requested_expire` bigint(20) NOT NULL,
  `requested_bytes` bigint(20) unsigned NOT NULL,
  `request_sha256` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `state` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT 'dispatching',
  `order_id` int(11) DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `provider_snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `provider_reference_id` bigint(20) unsigned DEFAULT NULL,
  `provider_connection_id` int(11) DEFAULT NULL,
  `provider_remote_user_id` bigint(20) unsigned DEFAULT NULL,
  `provider_remote_created_at` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `provider_operation_marker` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `provider_entitlement_snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `provider_mutation_key` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `provider_mutation_snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `provider_state` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `provider_verified_at` datetime DEFAULT NULL,
  `provider_evidence_created_at` datetime DEFAULT NULL,
  `provider_evidence_updated_at` datetime DEFAULT NULL,
  `active_username` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin GENERATED ALWAYS AS (if(`state` = 'cancelled',NULL,`remote_username`)) VIRTUAL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_effect_operation` (`operation_id`),
  UNIQUE KEY `uq_effect_source_line` (`source_kind`,`source_ref`,`ordinal`),
  UNIQUE KEY `uq_effect_pay_line` (`pay_row_id`,`effect_kind`,`ordinal`),
  UNIQUE KEY `uq_effect_order` (`order_id`),
  UNIQUE KEY `uq_effect_provider_reference` (`provider_reference_id`),
  UNIQUE KEY `uq_effect_provider_remote` (`provider_connection_id`,`provider_remote_user_id`),
  UNIQUE KEY `uq_effect_target_username` (`target_key`,`active_username`),
  KEY `idx_effect_panel_username` (`target_server_id`,`remote_username`),
  KEY `idx_effect_review` (`state`,`updated_at`),
  KEY `idx_effect_physical_username` (`provider_connection_id`,`remote_username`),
  CONSTRAINT `fk_effect_pay` FOREIGN KEY (`pay_row_id`) REFERENCES `pays` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_mutations` (
  `service_operation_id` bigint(20) unsigned NOT NULL,
  `account_effect_id` bigint(20) unsigned NOT NULL,
  `previous_mutation_key` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `order_expire_before` int(11) NOT NULL,
  `phase` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `intent_payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `intent_sha256` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `dispatch_started_at` datetime(6) DEFAULT NULL,
  `reset_ack_at` datetime(6) DEFAULT NULL,
  `result_payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `result_sha256` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `audit_id` int(11) DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT current_timestamp(6),
  `updated_at` datetime(6) NOT NULL DEFAULT current_timestamp(6) ON UPDATE current_timestamp(6),
  PRIMARY KEY (`service_operation_id`),
  UNIQUE KEY `uq_service_mutation_audit` (`audit_id`),
  KEY `idx_service_mutation_account` (`account_effect_id`,`service_operation_id`),
  CONSTRAINT `fk_service_mutation_account` FOREIGN KEY (`account_effect_id`) REFERENCES `service_effects` (`id`),
  CONSTRAINT `fk_service_mutation_operation` FOREIGN KEY (`service_operation_id`) REFERENCES `service_operations` (`id`),
  CONSTRAINT `ck_service_mutation_phase` CHECK (`phase` in ('observe','reset','modify','verified','completed')),
  CONSTRAINT `ck_service_mutation_intent` CHECK (`phase` = 'observe' and `intent_payload` is null and `intent_sha256` is null or `phase` <> 'observe' and `intent_payload` is not null and `intent_sha256` is not null and json_valid(`intent_payload`)),
  CONSTRAINT `ck_service_mutation_result` CHECK (`phase` in ('verified','completed') and `result_payload` is not null and `result_sha256` is not null and json_valid(`result_payload`) or `phase` not in ('verified','completed') and `result_payload` is null and `result_sha256` is null),
  CONSTRAINT `ck_service_mutation_audit` CHECK (`phase` = 'completed' = (`audit_id` is not null)),
  CONSTRAINT `ck_service_mutation_dispatch` CHECK ((`phase` <> 'observe' or `dispatch_started_at` is null) and (`reset_ack_at` is null and `phase` not in ('verified','completed') or `dispatch_started_at` is not null))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_operations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `command_kind` varbinary(32) NOT NULL,
  `command_ref` varbinary(128) NOT NULL,
  `user_row_id` int(11) NOT NULL,
  `userid` bigint(20) NOT NULL,
  `kind` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `quote_payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `quote_sha256` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `subject_order_id` int(11) GENERATED ALWAYS AS (cast(json_value(`quote_payload`,'$.request.order_id') as unsigned)) STORED,
  `target_server_id` int(11) GENERATED ALWAYS AS (cast(json_value(`quote_payload`,'$.request.server_id') as unsigned)) STORED,
  `price_toman` int(10) unsigned NOT NULL,
  `stage` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT 'awaiting_funds',
  `debit_entry_id` bigint(20) unsigned DEFAULT NULL,
  `reserved_count` int(10) unsigned NOT NULL DEFAULT 0,
  `next_attempt_at` datetime(6) DEFAULT NULL,
  `attempt_count` bigint(20) unsigned NOT NULL DEFAULT 0,
  `claim_token` char(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `claim_until` datetime(6) DEFAULT NULL,
  `failure_code` varchar(96) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `created_at` datetime(6) NOT NULL,
  `updated_at` datetime(6) NOT NULL,
  `resolution_kind` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `resolved_by` bigint(20) DEFAULT NULL,
  `resolved_at` datetime(6) DEFAULT NULL,
  `refund_entry_id` bigint(20) unsigned DEFAULT NULL,
  `cancelled_count` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_service_command` (`command_kind`,`command_ref`),
  UNIQUE KEY `uq_service_debit` (`debit_entry_id`),
  UNIQUE KEY `uq_service_refund` (`refund_entry_id`),
  KEY `idx_service_due` (`stage`,`next_attempt_at`,`id`),
  KEY `idx_service_owner` (`userid`,`id`),
  KEY `idx_service_subject_stage` (`subject_order_id`,`stage`,`id`),
  KEY `idx_service_target_stage` (`target_server_id`,`stage`,`id`),
  CONSTRAINT `fk_service_debit` FOREIGN KEY (`debit_entry_id`) REFERENCES `wallet_entries` (`id`),
  CONSTRAINT `fk_service_refund` FOREIGN KEY (`refund_entry_id`) REFERENCES `wallet_entries` (`id`),
  CONSTRAINT `ck_service_kind` CHECK (`kind` in ('BUY','RENEW','ADD_VOLUME','ADD_TIME')),
  CONSTRAINT `ck_service_price` CHECK (`price_toman` <= 2147483647),
  CONSTRAINT `ck_service_quote` CHECK (json_valid(`quote_payload`) and `subject_order_id` is not null and `subject_order_id` >= 0 and `target_server_id` is not null and `target_server_id` > 0 and `kind` = 'BUY' = (`subject_order_id` = 0)),
  CONSTRAINT `ck_service_stage` CHECK (`stage` in ('awaiting_funds','funded','prepared','dispatching','applied','completed','cancelled')),
  CONSTRAINT `ck_service_unfunded` CHECK (`stage` <> 'awaiting_funds' or `debit_entry_id` is null and `reserved_count` = 0),
  CONSTRAINT `ck_service_funded` CHECK (`stage` not in ('funded','prepared','dispatching','applied','completed') or `price_toman` = 0 and `debit_entry_id` is null or `price_toman` > 0 and `debit_entry_id` is not null),
  CONSTRAINT `ck_service_resolution` CHECK (`resolution_kind` is null and `resolved_by` is null and `resolved_at` is null and `refund_entry_id` is null and `cancelled_count` = 0 or `resolution_kind` in ('closed','refunded') and `resolved_by` > 0 and `resolved_at` is not null and `stage` in ('completed','cancelled') and `next_attempt_at` is null and (`resolution_kind` = 'refunded' or `refund_entry_id` is null and `cancelled_count` = 0) and (`kind` <> 'BUY' or `resolution_kind` = 'refunded'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_plan_changes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `lock_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `source_order_id` int(11) NOT NULL,
  `plan_id` int(11) NOT NULL,
  `server_id` int(11) NOT NULL,
  `provider` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `state` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'prepared',
  `intent_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_shared_plan_lock_state` (`lock_key`,`state`,`id`),
  KEY `idx_shared_plan_order` (`source_order_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `setting` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `type` varchar(500) NOT NULL,
  `value` text NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `userid` bigint(20) NOT NULL,
  `name` varchar(100) NOT NULL,
  `username` varchar(100) NOT NULL,
  `refcode` varchar(50) NOT NULL,
  `wallet` int(11) NOT NULL DEFAULT 0,
  `date` varchar(50) NOT NULL,
  `phone` varchar(15) DEFAULT NULL,
  `refered_by` bigint(20) DEFAULT NULL,
  `step` varchar(1000) NOT NULL DEFAULT 'none',
  `freetrial` varchar(10) DEFAULT NULL,
  `isAdmin` tinyint(1) NOT NULL DEFAULT 0,
  `first_start` varchar(10) DEFAULT NULL,
  `temp` text DEFAULT NULL,
  `is_agent` int(11) NOT NULL DEFAULT 0,
  `discount_percent` varchar(1000) DEFAULT NULL,
  `agent_date` int(11) NOT NULL DEFAULT 0,
  `spam_info` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_users_userid_v1` (`userid`),
  KEY `idx_users_is_agent_v1` (`is_agent`),
  KEY `idx_users_date_v1` (`date`),
  KEY `idx_users_refered_by_v1` (`refered_by`),
  KEY `idx_users_wallet_v1` (`wallet`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `wallet_adjustments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `source_type` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `source_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `entry_no` tinyint(3) unsigned NOT NULL,
  `actor_userid` bigint(20) NOT NULL,
  `target_userid` bigint(20) NOT NULL,
  `telegram_update_id` bigint(20) NOT NULL,
  `source_chat_id` bigint(20) NOT NULL,
  `source_message_id` bigint(20) NOT NULL,
  `adjustment_type` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `amount_toman` int(10) unsigned NOT NULL,
  `before_balance` int(11) NOT NULL,
  `after_balance` int(11) NOT NULL,
  `reason` varchar(500) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wallet_source_entry` (`source_type`,`source_id`,`entry_no`),
  UNIQUE KEY `uq_wallet_telegram_entry` (`telegram_update_id`,`entry_no`),
  KEY `idx_wallet_target` (`target_userid`,`created_at`),
  KEY `idx_wallet_actor` (`actor_userid`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `wallet_entries` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `source_kind` varbinary(32) NOT NULL,
  `source_ref` varbinary(128) NOT NULL,
  `leg` int(10) unsigned NOT NULL,
  `user_row_id` int(11) NOT NULL,
  `userid` bigint(20) NOT NULL,
  `delta_toman` bigint(20) NOT NULL,
  `balance_before` bigint(20) NOT NULL,
  `balance_after` bigint(20) NOT NULL,
  `actor_userid` bigint(20) NOT NULL,
  `created_at` datetime(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wallet_entry_source` (`source_kind`,`source_ref`,`leg`),
  KEY `idx_wallet_entry_user` (`userid`,`id`),
  CONSTRAINT `ck_wallet_entry_arithmetic` CHECK (`balance_after` = `balance_before` + `delta_toman`),
  CONSTRAINT `ck_wallet_entry_nonzero` CHECK (`delta_toman` <> 0 or `source_kind` = 'opening')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*M!100616 SET NOTE_VERBOSITY=@OLD_NOTE_VERBOSITY */;
