-- schema_update.sql
-- Upgrades for Modern Liquor Shop Dashboard
-- Run this against your existing sms_db database to bring it up to date.

-- 1. Create Dashboard Preferences table
CREATE TABLE IF NOT EXISTS `user_dashboard_preferences` (
    `id` VARCHAR(36) PRIMARY KEY,
    `user_id` VARCHAR(36) NOT NULL,
    `layout_data` JSON DEFAULT NULL,
    `filters_data` JSON DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 2. Add indexes to optimize dashboard query speeds
ALTER TABLE `journal_entries` ADD INDEX IF NOT EXISTS `idx_je_acc_date` (`account_id`, `entry_date`);
ALTER TABLE `transaction_headers` ADD INDEX IF NOT EXISTS `idx_th_date_type` (`txn_date`, `txn_type`);
ALTER TABLE `items` ADD INDEX IF NOT EXISTS `idx_items_del_act` (`is_deleted`, `is_active`);

-- ============================================================
-- 3. Bank Opening Balances Feature (run once on existing DBs)
-- ============================================================

-- Add opening_balance column to accounts (if it doesn't exist)
ALTER TABLE `accounts` 
    ADD COLUMN IF NOT EXISTS `opening_balance` DECIMAL(14,2) NOT NULL DEFAULT 0.00;

-- Add updated_at column to accounts (if it doesn't exist)
ALTER TABLE `accounts`
    ADD COLUMN IF NOT EXISTS `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- Add deleted_at column to accounts (if it doesn't exist)
ALTER TABLE `accounts`
    ADD COLUMN IF NOT EXISTS `deleted_at` DATETIME NULL;

-- ============================================================
-- 4. Transaction Headers: Add missing columns (if not present)
-- ============================================================

ALTER TABLE `transaction_headers`
    ADD COLUMN IF NOT EXISTS `net_amount` DECIMAL(14,2) DEFAULT 0.00,
    ADD COLUMN IF NOT EXISTS `party_id` VARCHAR(36) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `party_type` ENUM('customer', 'vendor', 'user') DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- ============================================================
-- 5. Journal Entries: Add missing columns (if not present)
-- ============================================================

ALTER TABLE `journal_entries`
    ADD COLUMN IF NOT EXISTS `created_by` VARCHAR(36) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `party_id` VARCHAR(36) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `party_type` ENUM('customer', 'vendor', 'user') DEFAULT NULL;

-- ============================================================
-- 6. Users: Add updated_at column (if not present)
-- ============================================================

ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- ============================================================
-- 7. Create transaction_links table (if not exists)
-- ============================================================

CREATE TABLE IF NOT EXISTS `transaction_links` (
    `id` VARCHAR(36) PRIMARY KEY,
    `parent_id` VARCHAR(36) NOT NULL,
    `child_id` VARCHAR(36) NOT NULL,
    `link_type` VARCHAR(50) NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- 8. Create system_logs table (if not exists)
-- ============================================================

CREATE TABLE IF NOT EXISTS `system_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` VARCHAR(36) DEFAULT NULL,
    `action` TEXT NOT NULL,
    `action_type` VARCHAR(50) DEFAULT NULL,
    `table_name` VARCHAR(50) DEFAULT NULL,
    `module` VARCHAR(255) NOT NULL,
    `ref_id` VARCHAR(100) NOT NULL,
    `field_name` VARCHAR(100) DEFAULT NULL,
    `old_data` TEXT DEFAULT NULL,
    `new_data` TEXT DEFAULT NULL,
    `ip_address` VARCHAR(50) DEFAULT NULL,
    `device_info` TEXT DEFAULT NULL,
    `date_created` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
