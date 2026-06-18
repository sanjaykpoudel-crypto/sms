-- schema_update.sql
-- Upgrades for Modern Liquor Shop Dashboard

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
