-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: localhost
-- Generation Time: Oct 11, 2026 at 10:25 PM
-- Server version: 10.11.10-MariaDB-log
-- PHP Version: 8.4.25

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `chilibot`
--

-- --------------------------------------------------------

--
-- Table structure for table `add_link`
--

CREATE TABLE `add_link` (
  `id` int(11) NOT NULL,
  `remark` varchar(100) NOT NULL,
  `token` varchar(255) NOT NULL,
  `added_by` bigint(20) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `admins`
--

CREATE TABLE `admins` (
  `id` int(11) NOT NULL,
  `username` varchar(200) NOT NULL,
  `password` varchar(200) NOT NULL,
  `backupchannel` varchar(200) NOT NULL,
  `lang` varchar(10) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `bc_jobs`
--

CREATE TABLE `bc_jobs` (
  `id` int(11) NOT NULL,
  `broadcast_id` int(11) NOT NULL,
  `action` enum('pin','unpin','delete','edit') NOT NULL,
  `new_text` text DEFAULT NULL,
  `parse_mode` varchar(20) DEFAULT NULL,
  `ok_cnt` int(11) NOT NULL DEFAULT 0,
  `fail_cnt` int(11) NOT NULL DEFAULT 0,
  `offset` int(11) NOT NULL DEFAULT 0,
  `state` tinyint(4) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `bc_messages`
--

CREATE TABLE `bc_messages` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `broadcast_id` int(11) NOT NULL,
  `userid` bigint(20) NOT NULL,
  `message_id` int(11) NOT NULL,
  `pinned` tinyint(1) NOT NULL DEFAULT 0,
  `deleted` tinyint(1) NOT NULL DEFAULT 0,
  `sent_at` datetime NOT NULL DEFAULT current_timestamp(),
  `pinned_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `black_list`
--

CREATE TABLE `black_list` (
  `id` int(11) NOT NULL,
  `info` varchar(20) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `chats`
--

CREATE TABLE `chats` (
  `id` int(11) NOT NULL,
  `user_id` bigint(20) NOT NULL,
  `create_date` int(11) NOT NULL,
  `title` varchar(1000) NOT NULL,
  `category` varchar(1000) NOT NULL,
  `state` int(11) NOT NULL,
  `rate` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `chats_info`
--

CREATE TABLE `chats_info` (
  `id` int(11) NOT NULL,
  `chat_id` int(11) NOT NULL,
  `sent_date` int(11) NOT NULL,
  `msg_type` varchar(50) DEFAULT NULL,
  `text` varchar(2000) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `discounts`
--

CREATE TABLE `discounts` (
  `id` int(11) NOT NULL,
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
  `is_active` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `gift_list`
--

CREATE TABLE `gift_list` (
  `id` int(11) NOT NULL,
  `server_id` int(11) NOT NULL,
  `volume` int(11) NOT NULL,
  `day` int(11) NOT NULL,
  `offset` int(11) DEFAULT 0,
  `server_offset` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `increase_day`
--

CREATE TABLE `increase_day` (
  `id` int(11) NOT NULL,
  `volume` float NOT NULL,
  `price` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `increase_order`
--

CREATE TABLE `increase_order` (
  `id` int(11) NOT NULL,
  `userid` varchar(30) NOT NULL,
  `server_id` int(11) NOT NULL,
  `inbound_id` int(11) NOT NULL,
  `remark` varchar(100) NOT NULL,
  `amount` int(11) NOT NULL,
  `date` varchar(30) NOT NULL,
  `volume` int(11) DEFAULT NULL,
  `day` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `increase_plan`
--

CREATE TABLE `increase_plan` (
  `id` int(11) NOT NULL,
  `volume` float NOT NULL,
  `price` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `needed_sofwares`
--

CREATE TABLE `needed_sofwares` (
  `id` int(11) NOT NULL,
  `title` varchar(100) NOT NULL,
  `link` varchar(250) NOT NULL,
  `status` int(11) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `orders_list`
--

CREATE TABLE `orders_list` (
  `id` int(11) NOT NULL,
  `userid` bigint(20) NOT NULL,
  `token` varchar(1000) NOT NULL,
  `transid` varchar(150) NOT NULL,
  `fileid` int(11) NOT NULL,
  `server_id` int(11) NOT NULL,
  `inbound_id` int(11) NOT NULL DEFAULT 0,
  `remark` varchar(100) NOT NULL,
  `uuid` varchar(200) DEFAULT NULL,
  `protocol` varchar(50) DEFAULT '',
  `expire_date` int(11) NOT NULL,
  `link` text NOT NULL,
  `amount` int(11) NOT NULL,
  `status` int(11) NOT NULL,
  `date` varchar(50) NOT NULL,
  `notif` int(11) NOT NULL DEFAULT 0,
  `rahgozar` int(11) DEFAULT 0,
  `agent_bought` int(11) NOT NULL DEFAULT 0,
  `autoRenew` varchar(10) DEFAULT 'off'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pays`
--

CREATE TABLE `pays` (
  `id` int(11) NOT NULL,
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
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `send_list`
--

CREATE TABLE `send_list` (
  `id` int(11) NOT NULL,
  `offset` int(11) NOT NULL DEFAULT 0,
  `type` varchar(20) NOT NULL,
  `title` varchar(255) DEFAULT NULL,
  `text` text DEFAULT NULL,
  `chat_id` bigint(20) DEFAULT NULL,
  `message_id` int(11) DEFAULT NULL,
  `file_id` varchar(500) DEFAULT NULL,
  `state` int(11) NOT NULL DEFAULT 0,
  `target_group` varchar(10) DEFAULT 'all',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  `sample_userid` bigint(20) DEFAULT NULL,
  `sample_message_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `servers`
--

CREATE TABLE `servers` (
  `id` int(11) NOT NULL,
  `ip` varchar(200) NOT NULL,
  `port` int(11) NOT NULL,
  `username` varchar(200) NOT NULL,
  `password` varchar(200) NOT NULL,
  `name` varchar(200) NOT NULL,
  `panel` varchar(100) NOT NULL,
  `status` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `server_categories`
--

CREATE TABLE `server_categories` (
  `id` int(11) NOT NULL,
  `server_id` varchar(20) NOT NULL,
  `title` varchar(50) NOT NULL,
  `parent` int(11) NOT NULL DEFAULT 0,
  `step` int(11) NOT NULL,
  `active` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `server_config`
--

CREATE TABLE `server_config` (
  `id` int(11) NOT NULL,
  `panel_url` varchar(254) NOT NULL,
  `ip` mediumtext NOT NULL,
  `sni` varchar(254) NOT NULL,
  `header_type` enum('none','http') NOT NULL,
  `request_header` mediumtext NOT NULL,
  `response_header` mediumtext NOT NULL,
  `security` enum('xtls','tls','none') NOT NULL,
  `tlsSettings` mediumtext NOT NULL,
  `type` varchar(100) DEFAULT NULL,
  `username` varchar(100) NOT NULL,
  `password` varchar(100) NOT NULL,
  `port_type` varchar(10) DEFAULT 'auto',
  `reality` varchar(10) DEFAULT 'false',
  `sub_links` mediumtext DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `server_info`
--

CREATE TABLE `server_info` (
  `id` int(11) NOT NULL,
  `title` varchar(200) NOT NULL,
  `ucount` varchar(20) NOT NULL,
  `remark` varchar(100) NOT NULL,
  `flag` varchar(100) NOT NULL,
  `active` int(11) NOT NULL DEFAULT 0,
  `state` int(11) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `server_plans`
--

CREATE TABLE `server_plans` (
  `id` int(11) NOT NULL,
  `fileid` varchar(250) NOT NULL,
  `catid` int(11) NOT NULL,
  `server_id` int(11) NOT NULL,
  `inbound_id` int(11) NOT NULL DEFAULT 0,
  `acount` bigint(20) NOT NULL,
  `limitip` int(11) NOT NULL DEFAULT 1,
  `title` varchar(150) NOT NULL,
  `protocol` varchar(100) NOT NULL,
  `days` float NOT NULL,
  `volume` float NOT NULL,
  `type` varchar(50) NOT NULL,
  `price` int(11) NOT NULL,
  `descr` text NOT NULL,
  `pic` varchar(100) NOT NULL,
  `active` int(11) NOT NULL DEFAULT 0,
  `step` int(11) NOT NULL,
  `date` varchar(50) NOT NULL,
  `rahgozar` int(11) DEFAULT 0,
  `dest` varchar(1000) DEFAULT NULL,
  `serverNames` text DEFAULT NULL,
  `spiderX` varchar(500) DEFAULT NULL,
  `flow` varchar(50) DEFAULT 'None',
  `custom_path` int(11) DEFAULT 1,
  `custom_port` int(11) NOT NULL DEFAULT 0,
  `custom_sni` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `setting`
--

CREATE TABLE `setting` (
  `id` int(11) NOT NULL,
  `type` varchar(500) NOT NULL,
  `value` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
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
  `spam_info` varchar(500) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


--
-- Indexes for dumped tables
--

--
-- Indexes for table `add_link`
--
ALTER TABLE `add_link`
  ADD PRIMARY KEY (`id`),
  ADD KEY `added_by_idx` (`added_by`),
  ADD KEY `remark_idx` (`remark`);

--
-- Indexes for table `admins`
--
ALTER TABLE `admins`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `bc_jobs`
--
ALTER TABLE `bc_jobs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_state` (`state`),
  ADD KEY `idx_broadcast_action` (`broadcast_id`,`action`),
  ADD KEY `idx_bc_state_updated_id` (`state`,`updated_at`,`id`);

--
-- Indexes for table `bc_messages`
--
ALTER TABLE `bc_messages`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_broadcast_user` (`broadcast_id`,`userid`),
  ADD KEY `idx_broadcast` (`broadcast_id`),
  ADD KEY `idx_userid` (`userid`);

--
-- Indexes for table `black_list`
--
ALTER TABLE `black_list`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `chats`
--
ALTER TABLE `chats`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `chats_info`
--
ALTER TABLE `chats_info`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `discounts`
--
ALTER TABLE `discounts`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `gift_list`
--
ALTER TABLE `gift_list`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `increase_day`
--
ALTER TABLE `increase_day`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `increase_order`
--
ALTER TABLE `increase_order`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `increase_plan`
--
ALTER TABLE `increase_plan`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `needed_sofwares`
--
ALTER TABLE `needed_sofwares`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `orders_list`
--
ALTER TABLE `orders_list`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_remark_userid` (`remark`,`userid`),
  ADD KEY `idx_orders_status_notif` (`status`,`notif`),
  ADD KEY `idx_orders_uuid` (`uuid`),
  ADD KEY `idx_orders_userid_v1` (`userid`);

--
-- Indexes for table `pays`
--
ALTER TABLE `pays`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `hash_id` (`hash_id`),
  ADD KEY `idx_pays_state_type_v1` (`state`,`type`),
  ADD KEY `idx_pays_user_v1` (`user_id`),
  ADD KEY `idx_pays_price_v1` (`price`),
  ADD KEY `idx_pays_state_updated_at` (`state`,`updated_at`);

--
-- Indexes for table `send_list`
--
ALTER TABLE `send_list`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_state_id` (`state`,`id`),
  ADD KEY `idx_state_created` (`state`,`created_at`),
  ADD KEY `idx_state_target` (`state`,`target_group`),
  ADD KEY `idx_state` (`state`),
  ADD KEY `idx_updated_at` (`updated_at`),
  ADD KEY `idx_state_updated_id` (`state`,`updated_at`,`id`);

--
-- Indexes for table `servers`
--
ALTER TABLE `servers`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `server_categories`
--
ALTER TABLE `server_categories`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `server_config`
--
ALTER TABLE `server_config`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_server_id` (`id`);

--
-- Indexes for table `server_info`
--
ALTER TABLE `server_info`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `server_plans`
--
ALTER TABLE `server_plans`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `setting`
--
ALTER TABLE `setting`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_users_is_agent_v1` (`is_agent`),
  ADD KEY `idx_users_date_v1` (`date`),
  ADD KEY `idx_users_refered_by_v1` (`refered_by`),
  ADD KEY `idx_users_wallet_v1` (`wallet`),
  ADD KEY `idx_users_userid_v1` (`userid`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `add_link`
--
ALTER TABLE `add_link`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `admins`
--
ALTER TABLE `admins`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `bc_jobs`
--
ALTER TABLE `bc_jobs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `bc_messages`
--
ALTER TABLE `bc_messages`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `black_list`
--
ALTER TABLE `black_list`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `chats`
--
ALTER TABLE `chats`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `chats_info`
--
ALTER TABLE `chats_info`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `discounts`
--
ALTER TABLE `discounts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `gift_list`
--
ALTER TABLE `gift_list`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `increase_day`
--
ALTER TABLE `increase_day`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `increase_order`
--
ALTER TABLE `increase_order`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `increase_plan`
--
ALTER TABLE `increase_plan`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `needed_sofwares`
--
ALTER TABLE `needed_sofwares`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `orders_list`
--
ALTER TABLE `orders_list`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pays`
--
ALTER TABLE `pays`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `send_list`
--
ALTER TABLE `send_list`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `servers`
--
ALTER TABLE `servers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `server_categories`
--
ALTER TABLE `server_categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `server_config`
--
ALTER TABLE `server_config`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `server_info`
--
ALTER TABLE `server_info`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `server_plans`
--
ALTER TABLE `server_plans`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=85;

--
-- AUTO_INCREMENT for table `setting`
--
ALTER TABLE `setting`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=54;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
