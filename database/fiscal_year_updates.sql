-- database/fiscal_year_updates.sql
-- Schema updates for Fiscal Year Closing Module

-- 1. Create fiscal_years master table
CREATE TABLE IF NOT EXISTS `fiscal_years` (
    `id` VARCHAR(36) PRIMARY KEY,
    `name` VARCHAR(50) NOT NULL UNIQUE,
    `start_date` DATE NOT NULL,
    `end_date` DATE NOT NULL,
    `status` ENUM('open', 'closed', 'reopened') NOT NULL DEFAULT 'open',
    `closing_date` DATE DEFAULT NULL,
    `closed_by` VARCHAR(36) DEFAULT NULL,
    `closed_timestamp` TIMESTAMP NULL DEFAULT NULL,
    `reopened_by` VARCHAR(36) DEFAULT NULL,
    `reopened_timestamp` TIMESTAMP NULL DEFAULT NULL,
    `closing_journal_id` VARCHAR(36) DEFAULT NULL,
    `opening_journal_id` VARCHAR(36) DEFAULT NULL,
    `notes` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`closed_by`) REFERENCES `users`(`id`),
    FOREIGN KEY (`reopened_by`) REFERENCES `users`(`id`),
    FOREIGN KEY (`closing_journal_id`) REFERENCES `transaction_headers`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`opening_journal_id`) REFERENCES `transaction_headers`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 2. Create fiscal_year_audit_logs table for audit trail
CREATE TABLE IF NOT EXISTS `fiscal_year_audit_logs` (
    `id` VARCHAR(36) PRIMARY KEY,
    `fiscal_year_id` VARCHAR(36) NOT NULL,
    `action_type` ENUM('close', 'reopen') NOT NULL,
    `previous_status` ENUM('open', 'closed', 'reopened') NOT NULL,
    `new_status` ENUM('open', 'closed', 'reopened') NOT NULL,
    `user_id` VARCHAR(36) NOT NULL,
    `reason` TEXT DEFAULT NULL,
    `ip_address` VARCHAR(50) DEFAULT NULL,
    `machine_name` VARCHAR(100) DEFAULT NULL,
    `closing_journal_id` VARCHAR(36) DEFAULT NULL,
    `deleted_reversed_journal_id` VARCHAR(36) DEFAULT NULL,
    `version_number` INT NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`fiscal_year_id`) REFERENCES `fiscal_years`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 3. Add source, is_readonly, and is_locked columns to transaction_headers
ALTER TABLE `transaction_headers`
    ADD COLUMN IF NOT EXISTS `source` VARCHAR(50) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `is_readonly` TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `is_locked` TINYINT(1) NOT NULL DEFAULT 0;

-- 4. Set indexes on these fields for faster queries
ALTER TABLE `transaction_headers` ADD INDEX IF NOT EXISTS `idx_th_source` (`source`);
ALTER TABLE `transaction_headers` ADD INDEX IF NOT EXISTS `idx_th_is_locked` (`is_locked`);
