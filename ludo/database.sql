-- ======================================================
-- DATABASE SCHEMA - COMPLETE FIXED + financial_metrics
-- Ludo Tournament Platform - Production Ready
-- Version: 5.0.0 - ALL FIXES + NEW TABLE
-- ======================================================

-- ==============================================
-- 1. USERS TABLE
-- ==============================================
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `username` VARCHAR(50) NOT NULL,
    `mobile` VARCHAR(10) NOT NULL,
    `email` VARCHAR(100) DEFAULT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `wallet_balance` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `total_matches_played` INT(11) NOT NULL DEFAULT 0,
    `total_matches_won` INT(11) NOT NULL DEFAULT 0,
    `total_earnings` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `total_withdrawn` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `elo_rating` INT(11) NOT NULL DEFAULT 1200,
    `is_verified` TINYINT(1) NOT NULL DEFAULT 0,
    `is_admin` TINYINT(1) NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `kyc_status` ENUM('not_submitted', 'pending', 'verified', 'rejected') NOT NULL DEFAULT 'not_submitted',
    `pan_number` VARCHAR(20) DEFAULT NULL,
    `aadhaar_number` VARCHAR(20) DEFAULT NULL,
    `refer_code` VARCHAR(20) DEFAULT NULL,
    `referred_by` INT(11) DEFAULT NULL,
    `referral_earnings` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `failed_login_attempts` INT(11) NOT NULL DEFAULT 0,
    `last_login` TIMESTAMP NULL DEFAULT NULL,
    `last_withdrawal_date` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_mobile` (`mobile`),
    UNIQUE KEY `uk_username` (`username`),
    UNIQUE KEY `uk_refer_code` (`refer_code`),
    KEY `idx_mobile` (`mobile`),
    KEY `idx_is_active` (`is_active`),
    KEY `idx_kyc_status` (`kyc_status`),
    KEY `idx_elo_rating` (`elo_rating`),
    CONSTRAINT `fk_referred_by` FOREIGN KEY (`referred_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==============================================
-- 2. SESSIONS TABLE
-- ==============================================
CREATE TABLE IF NOT EXISTS `sessions` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `session_token` VARCHAR(255) NOT NULL,
    `ip_address` VARCHAR(45) NOT NULL,
    `user_agent` VARCHAR(255) DEFAULT NULL,
    `device_type` VARCHAR(50) DEFAULT NULL,
    `last_activity` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `expires_at` TIMESTAMP NOT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_session_token` (`session_token`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_expires_at` (`expires_at`),
    KEY `idx_is_active` (`is_active`),
    CONSTRAINT `fk_sessions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==============================================
-- 3. TOURNAMENTS TABLE
-- ==============================================
CREATE TABLE IF NOT EXISTS `tournaments` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `tournament_code` VARCHAR(20) NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `entry_fee` DECIMAL(10,2) NOT NULL,
    `prize_pool` DECIMAL(10,2) NOT NULL,
    `platform_fee` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `max_players` INT(11) NOT NULL DEFAULT 4,
    `min_players` INT(11) NOT NULL DEFAULT 2,
    `current_players` INT(11) NOT NULL DEFAULT 0,
    `status` ENUM('scheduled', 'active', 'in_progress', 'completed', 'cancelled') NOT NULL DEFAULT 'scheduled',
    `winner_id` INT(11) DEFAULT NULL,
    `winner_amount` DECIMAL(10,2) DEFAULT NULL,
    `start_time` TIMESTAMP NULL DEFAULT NULL,
    `end_time` TIMESTAMP NULL DEFAULT NULL,
    `created_by` INT(11) NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_tournament_code` (`tournament_code`),
    KEY `idx_status` (`status`),
    KEY `idx_created_by` (`created_by`),
    KEY `idx_current_players` (`current_players`),
    CONSTRAINT `fk_tournaments_winner` FOREIGN KEY (`winner_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_tournaments_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==============================================
-- 4. MATCHES TABLE (Fixed - token columns)
-- ==============================================
CREATE TABLE IF NOT EXISTS `matches` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `tournament_id` INT(11) DEFAULT NULL,
    `room_code` VARCHAR(10) NOT NULL,
    `entry_fee` DECIMAL(10,2) NOT NULL,
    `prize_pool` DECIMAL(10,2) NOT NULL,
    `platform_fee` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `player1_id` INT(11) NOT NULL,
    `player2_id` INT(11) DEFAULT NULL,
    `player3_id` INT(11) DEFAULT NULL,
    `player4_id` INT(11) DEFAULT NULL,
    `player1_name` VARCHAR(50) NOT NULL,
    `player2_name` VARCHAR(50) DEFAULT NULL,
    `player3_name` VARCHAR(50) DEFAULT NULL,
    `player4_name` VARCHAR(50) DEFAULT NULL,
    `status` ENUM('waiting', 'ready', 'playing', 'paused', 'completed', 'cancelled') NOT NULL DEFAULT 'waiting',
    `current_turn_id` INT(11) DEFAULT NULL,
    `dice_value` TINYINT(1) DEFAULT NULL,
    `dice_rolled_by` INT(11) DEFAULT NULL,
    `last_dice_roll_time` TIMESTAMP NULL DEFAULT NULL,
    `winner_id` INT(11) DEFAULT NULL,
    `winner_name` VARCHAR(50) DEFAULT NULL,
    `winning_amount` DECIMAL(10,2) DEFAULT NULL,
    `tds_deducted` DECIMAL(10,2) DEFAULT NULL,
    `turn_number` INT(11) NOT NULL DEFAULT 0,
    `max_turns` INT(11) NOT NULL DEFAULT 50,
    `board_state` JSON DEFAULT NULL,
    `p1_token1` INT(11) NOT NULL DEFAULT -1,
    `p1_token2` INT(11) NOT NULL DEFAULT -1,
    `p1_token3` INT(11) NOT NULL DEFAULT -1,
    `p1_token4` INT(11) NOT NULL DEFAULT -1,
    `p1_home_count` TINYINT(1) NOT NULL DEFAULT 0,
    `p2_token1` INT(11) NOT NULL DEFAULT -1,
    `p2_token2` INT(11) NOT NULL DEFAULT -1,
    `p2_token3` INT(11) NOT NULL DEFAULT -1,
    `p2_token4` INT(11) NOT NULL DEFAULT -1,
    `p2_home_count` TINYINT(1) NOT NULL DEFAULT 0,
    `p3_token1` INT(11) DEFAULT NULL,
    `p3_token2` INT(11) DEFAULT NULL,
    `p3_token3` INT(11) DEFAULT NULL,
    `p3_token4` INT(11) DEFAULT NULL,
    `p3_home_count` TINYINT(1) DEFAULT NULL,
    `p4_token1` INT(11) DEFAULT NULL,
    `p4_token2` INT(11) DEFAULT NULL,
    `p4_token3` INT(11) DEFAULT NULL,
    `p4_token4` INT(11) DEFAULT NULL,
    `p4_home_count` TINYINT(1) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `started_at` TIMESTAMP NULL DEFAULT NULL,
    `completed_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_room_code` (`room_code`),
    KEY `idx_tournament_id` (`tournament_id`),
    KEY `idx_player1_id_status` (`player1_id`, `status`),
    KEY `idx_player2_id_status` (`player2_id`, `status`),
    KEY `idx_status_created` (`status`, `created_at`),
    KEY `idx_current_turn` (`current_turn_id`),
    KEY `idx_created_at` (`created_at`),
    CONSTRAINT `fk_matches_tournament` FOREIGN KEY (`tournament_id`) REFERENCES `tournaments` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_matches_player1` FOREIGN KEY (`player1_id`) REFERENCES `users` (`id`),
    CONSTRAINT `fk_matches_player2` FOREIGN KEY (`player2_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_matches_winner` FOREIGN KEY (`winner_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==============================================
-- 5. TRANSACTIONS TABLE
-- ==============================================
CREATE TABLE IF NOT EXISTS `transactions` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `tournament_id` INT(11) DEFAULT NULL,
    `match_id` INT(11) DEFAULT NULL,
    `amount` DECIMAL(12,2) NOT NULL,
    `type` ENUM('credit', 'debit') NOT NULL,
    `source` ENUM('deposit', 'withdrawal', 'match_fee', 'match_win', 'bonus', 'refund', 'commission') NOT NULL,
    `description` VARCHAR(255) NOT NULL,
    `order_id` VARCHAR(50) DEFAULT NULL,
    `status` ENUM('pending', 'processing', 'success', 'failed') NOT NULL DEFAULT 'pending',
    `balance_before` DECIMAL(12,2) NOT NULL,
    `balance_after` DECIMAL(12,2) NOT NULL,
    `payment_gateway` VARCHAR(50) DEFAULT NULL,
    `gateway_transaction_id` VARCHAR(100) DEFAULT NULL,
    `tds_deducted` DECIMAL(10,2) DEFAULT NULL,
    `metadata` JSON DEFAULT NULL,
    `processed_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user_id_status` (`user_id`, `status`),
    KEY `idx_order_id` (`order_id`),
    KEY `idx_created_at` (`created_at`),
    KEY `idx_source` (`source`),
    KEY `idx_match_id` (`match_id`),
    CONSTRAINT `fk_transactions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
    CONSTRAINT `fk_transactions_match` FOREIGN KEY (`match_id`) REFERENCES `matches` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==============================================
-- 6. GAME ACTIONS TABLE
-- ==============================================
CREATE TABLE IF NOT EXISTS `game_actions` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `match_id` INT(11) NOT NULL,
    `user_id` INT(11) NOT NULL,
    `action_type` VARCHAR(50) NOT NULL COMMENT 'dice_roll, token_move, game_start, game_end',
    `dice_value` TINYINT(1) DEFAULT NULL,
    `token_number` TINYINT(1) DEFAULT NULL,
    `from_position` INT(11) DEFAULT NULL,
    `to_position` INT(11) DEFAULT NULL,
    `opponent_captured` TINYINT(1) DEFAULT 0,
    `metadata` JSON DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_match_id_created` (`match_id`, `created_at`),
    KEY `idx_user_id` (`user_id`),
    CONSTRAINT `fk_game_actions_match` FOREIGN KEY (`match_id`) REFERENCES `matches` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_game_actions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==============================================
-- 7. KYC DOCUMENTS TABLE
-- ==============================================
CREATE TABLE IF NOT EXISTS `kyc_documents` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `document_type` ENUM('pan', 'aadhaar', 'bank', 'selfie') NOT NULL,
    `document_number` VARCHAR(50) NOT NULL,
    `document_image_front` VARCHAR(255) NOT NULL,
    `document_image_back` VARCHAR(255) DEFAULT NULL,
    `selfie_image` VARCHAR(255) DEFAULT NULL,
    `bank_account_number` VARCHAR(50) DEFAULT NULL,
    `bank_ifsc` VARCHAR(20) DEFAULT NULL,
    `bank_account_name` VARCHAR(100) DEFAULT NULL,
    `upi_id` VARCHAR(50) DEFAULT NULL,
    `status` ENUM('pending', 'verified', 'rejected') NOT NULL DEFAULT 'pending',
    `rejection_reason` TEXT DEFAULT NULL,
    `verified_by` INT(11) DEFAULT NULL,
    `verified_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_status` (`status`),
    CONSTRAINT `fk_kyc_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_kyc_verified_by` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==============================================
-- 8. WITHDRAWALS TABLE
-- ==============================================
CREATE TABLE IF NOT EXISTS `withdrawals` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `amount` DECIMAL(12,2) NOT NULL,
    `bank_account_number` VARCHAR(50) NOT NULL,
    `bank_ifsc` VARCHAR(20) NOT NULL,
    `bank_account_name` VARCHAR(100) NOT NULL,
    `upi_id` VARCHAR(50) DEFAULT NULL,
    `transaction_id` VARCHAR(100) DEFAULT NULL,
    `status` ENUM('pending', 'approved', 'processing', 'completed', 'rejected') NOT NULL DEFAULT 'pending',
    `rejection_reason` TEXT DEFAULT NULL,
    `admin_notes` TEXT DEFAULT NULL,
    `processed_by` INT(11) DEFAULT NULL,
    `processed_at` TIMESTAMP NULL DEFAULT NULL,
    `completed_at` TIMESTAMP NULL DEFAULT NULL,
    `metadata` JSON DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user_id_status` (`user_id`, `status`),
    KEY `idx_status_created` (`status`, `created_at`),
    CONSTRAINT `fk_withdrawals_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_withdrawals_processed_by` FOREIGN KEY (`processed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==============================================
-- 9. DISPUTE TICKETS TABLE
-- ==============================================
CREATE TABLE IF NOT EXISTS `dispute_tickets` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `match_id` INT(11) NOT NULL,
    `user_id` INT(11) NOT NULL,
    `opponent_id` INT(11) DEFAULT NULL,
    `ticket_number` VARCHAR(20) NOT NULL,
    `subject` VARCHAR(255) NOT NULL,
    `description` TEXT NOT NULL,
    `priority` ENUM('low', 'medium', 'high', 'urgent') NOT NULL DEFAULT 'medium',
    `status` ENUM('open', 'investigating', 'resolved', 'closed') NOT NULL DEFAULT 'open',
    `resolution_type` ENUM('winner_declared', 'refund', 'cancelled', 'replay', 'no_action') DEFAULT NULL,
    `resolution_notes` TEXT DEFAULT NULL,
    `refund_amount` DECIMAL(10,2) DEFAULT NULL,
    `admin_notes` TEXT DEFAULT NULL,
    `resolved_by` INT(11) DEFAULT NULL,
    `resolved_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_ticket_number` (`ticket_number`),
    KEY `idx_match_id` (`match_id`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_status_priority` (`status`, `priority`),
    CONSTRAINT `fk_dispute_match` FOREIGN KEY (`match_id`) REFERENCES `matches` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_dispute_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_dispute_opponent` FOREIGN KEY (`opponent_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_dispute_resolved_by` FOREIGN KEY (`resolved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==============================================
-- 10. TICKET MESSAGES TABLE
-- ==============================================
CREATE TABLE IF NOT EXISTS `ticket_messages` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `ticket_id` INT(11) NOT NULL,
    `user_id` INT(11) DEFAULT NULL,
    `message` TEXT NOT NULL,
    `screenshot_url` VARCHAR(255) DEFAULT NULL,
    `is_admin` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ticket_id` (`ticket_id`),
    CONSTRAINT `fk_ticket_message_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `dispute_tickets` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ticket_message_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==============================================
-- 11. SYSTEM SETTINGS TABLE
-- ==============================================
CREATE TABLE IF NOT EXISTS `system_settings` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `setting_key` VARCHAR(100) NOT NULL,
    `setting_value` TEXT NOT NULL,
    `setting_group` VARCHAR(50) NOT NULL DEFAULT 'general',
    `setting_type` ENUM('string', 'integer', 'decimal', 'boolean', 'json', 'text') NOT NULL DEFAULT 'string',
    `description` VARCHAR(255) DEFAULT NULL,
    `is_editable` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==============================================
-- 12. REFERRAL BONUSES TABLE
-- ==============================================
CREATE TABLE IF NOT EXISTS `referral_bonuses` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `referrer_id` INT(11) NOT NULL,
    `referred_id` INT(11) NOT NULL,
    `bonus_amount` DECIMAL(10,2) NOT NULL DEFAULT 50.00,
    `status` ENUM('pending', 'credited', 'failed') NOT NULL DEFAULT 'pending',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `credited_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_referrer_id` (`referrer_id`),
    KEY `idx_referred_id` (`referred_id`),
    CONSTRAINT `fk_referral_referrer` FOREIGN KEY (`referrer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_referral_referred` FOREIGN KEY (`referred_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==============================================
-- 13. TDS TRANSACTIONS TABLE
-- ==============================================
CREATE TABLE IF NOT EXISTS `tds_transactions` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `match_id` INT(11) NOT NULL,
    `amount` DECIMAL(12,2) NOT NULL,
    `tds_rate` DECIMAL(5,2) NOT NULL DEFAULT 30.00,
    `tds_amount` DECIMAL(12,2) NOT NULL,
    `financial_year` VARCHAR(10) NOT NULL,
    `status` ENUM('pending', 'deducted', 'deposited') NOT NULL DEFAULT 'pending',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_financial_year` (`financial_year`),
    CONSTRAINT `fk_tds_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_tds_match` FOREIGN KEY (`match_id`) REFERENCES `matches` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==============================================
-- 14. LEADERBOARD TABLE
-- ==============================================
CREATE TABLE IF NOT EXISTS `leaderboard` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `username` VARCHAR(50) NOT NULL,
    `elo_rating` INT(11) NOT NULL DEFAULT 1200,
    `matches_played` INT(11) NOT NULL DEFAULT 0,
    `matches_won` INT(11) NOT NULL DEFAULT 0,
    `total_earnings` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `last_updated` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_user_id` (`user_id`),
    KEY `idx_elo_rating` (`elo_rating` DESC),
    KEY `idx_total_earnings` (`total_earnings` DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==============================================
-- 15. MAINTENANCE LOGS TABLE
-- ==============================================
CREATE TABLE IF NOT EXISTS `maintenance_logs` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `action` VARCHAR(100) NOT NULL,
    `details` JSON DEFAULT NULL,
    `admin_id` INT(11) DEFAULT NULL,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_action` (`action`),
    KEY `idx_admin_id` (`admin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==============================================
-- 16. ADMIN AUDIT LOG TABLE
-- ==============================================
CREATE TABLE IF NOT EXISTS `admin_audit_log` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `admin_id` INT(11) NOT NULL,
    `admin_username` VARCHAR(50) NOT NULL,
    `action` VARCHAR(100) NOT NULL,
    `target_id` INT(11) DEFAULT NULL,
    `target_type` VARCHAR(50) DEFAULT NULL,
    `details` JSON DEFAULT NULL,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `user_agent` VARCHAR(255) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_admin_id` (`admin_id`),
    KEY `idx_action` (`action`),
    KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==============================================
-- 17. FINANCIAL METRICS TABLE (NEW - FIXED)
-- ==============================================
CREATE TABLE IF NOT EXISTS `financial_metrics` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `metric_date` DATE NOT NULL,
    `daily_deposits` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `daily_withdrawals` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `daily_platform_revenue` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `daily_matches_played` INT(11) NOT NULL DEFAULT 0,
    `daily_new_users` INT(11) NOT NULL DEFAULT 0,
    `total_platform_liability` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `total_user_balance` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `total_tds_deducted` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_metric_date` (`metric_date`),
    KEY `idx_metric_date` (`metric_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==============================================
-- 18. WEBSOCKET SESSIONS TABLE
-- ==============================================
CREATE TABLE IF NOT EXISTS `websocket_sessions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `socket_id` VARCHAR(255) NOT NULL,
    `user_id` INT NOT NULL,
    `match_id` INT,
    `room_code` VARCHAR(10),
    `is_active` BOOLEAN DEFAULT TRUE,
    `connected_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `last_activity` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_socket_id` (`socket_id`),
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_room_code` (`room_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==============================================
-- 19. DEFAULT SYSTEM SETTINGS
-- ==============================================
INSERT INTO `system_settings` (`setting_key`, `setting_value`, `setting_group`, `setting_type`, `description`, `is_editable`) VALUES
('site_name', 'Ludo Tournament Pro', 'general', 'string', 'Website name', 1),
('site_url', 'https://yourdomain.com', 'general', 'string', 'Website URL', 1),
('platform_fee', '15', 'financial', 'decimal', 'Platform commission percentage', 1),
('min_entry_fee', '1', 'financial', 'integer', 'Minimum entry fee for matches', 1),
('max_entry_fee', '10000', 'financial', 'integer', 'Maximum entry fee for matches', 1),
('session_timeout', '1800', 'system', 'integer', 'Session timeout in seconds', 1),
('max_login_attempts', '5', 'system', 'integer', 'Maximum failed login attempts', 1),
('maintenance_mode', '0', 'system', 'boolean', 'Enable maintenance mode', 1),
('maintenance_message', 'We are currently performing scheduled maintenance. Please check back later.', 'system', 'text', 'Maintenance mode message', 1),
('referral_bonus', '50', 'financial', 'integer', 'Referral bonus amount in INR', 1),
('min_withdrawal', '10', 'financial', 'integer', 'Minimum withdrawal amount', 1),
('max_withdrawal', '50000', 'financial', 'integer', 'Maximum withdrawal amount', 1),
('tds_rate', '30', 'financial', 'decimal', 'TDS rate percentage', 1),
('tds_threshold', '10000', 'financial', 'integer', 'TDS threshold amount per financial year', 1),
('game_timeout', '15', 'gameplay', 'integer', 'Turn timeout in seconds', 1),
('max_turns', '50', 'gameplay', 'integer', 'Maximum turns per match', 1),
('elo_k_factor', '32', 'gameplay', 'integer', 'ELO rating K-factor', 1);

-- ==============================================
-- 20. ADMIN USER (Default password: password)
-- ==============================================
INSERT INTO `users` (
    `username`, `mobile`, `email`, `password_hash`, `is_admin`, `is_verified`, `is_active`, `refer_code`, `created_at`
) VALUES (
    'admin',
    '9999999999',
    'admin@yourdomain.com',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    1, 1, 1,
    'ADMIN001',
    CURRENT_TIMESTAMP
) ON DUPLICATE KEY UPDATE is_admin = 1;

-- ==============================================
-- 21. INDEXES FOR PERFORMANCE
-- ==============================================
CREATE INDEX IF NOT EXISTS idx_matches_status_created ON matches(status, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_transactions_user_status ON transactions(user_id, status);
CREATE INDEX IF NOT EXISTS idx_withdrawals_status_created ON withdrawals(status, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_kyc_status_created ON kyc_documents(status, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_disputes_status_created ON dispute_tickets(status, created_at DESC);

-- ==============================================
-- 22. CLEANUP PROCEDURES
-- ==============================================
DELIMITER $$

CREATE PROCEDURE clean_expired_sessions()
BEGIN
    DELETE FROM sessions WHERE expires_at < NOW() OR is_active = 0;
END$$

CREATE PROCEDURE archive_old_game_actions()
BEGIN
    CREATE TABLE IF NOT EXISTS game_actions_archive LIKE game_actions;
    INSERT INTO game_actions_archive
        SELECT * FROM game_actions
        WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY);
    DELETE FROM game_actions WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY);
END$$

DELIMITER ;

-- ==============================================
-- END OF DATABASE SCHEMA
-- ==============================================
