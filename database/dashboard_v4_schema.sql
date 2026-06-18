-- ============================================================
-- DASHBOARD V4 — Schema Migrations
-- ============================================================

-- 1. Dashboard widget preferences table
CREATE TABLE IF NOT EXISTS `user_dashboard_preferences` (
    `id` VARCHAR(36) PRIMARY KEY,
    `user_id` VARCHAR(36) NOT NULL,
    `layout_data` JSON DEFAULT NULL,
    `filters_data` JSON DEFAULT NULL,
    `widget_visibility` JSON DEFAULT NULL,
    `widget_order` JSON DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 2. Dashboard KPI cache table for performance
CREATE TABLE IF NOT EXISTS `dashboard_kpi_cache` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `cache_key` VARCHAR(100) NOT NULL UNIQUE,
    `cache_value` JSON NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_cache_key` (`cache_key`),
    INDEX `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 3. Dashboard audit tracking
CREATE TABLE IF NOT EXISTS `dashboard_audit` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` VARCHAR(36) NOT NULL,
    `widget_id` VARCHAR(100) DEFAULT NULL,
    `action` ENUM('view','click','refresh','customize','export') NOT NULL,
    `metadata` JSON DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_audit_user` (`user_id`),
    INDEX `idx_audit_widget` (`widget_id`),
    INDEX `idx_audit_date` (`created_at`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 4. Add critical missing indexes for dashboard performance
ALTER TABLE `journal_entries` 
  ADD INDEX IF NOT EXISTS `idx_je_acc_date` (`account_id`, `entry_date`),
  ADD INDEX IF NOT EXISTS `idx_je_header_entry` (`header_id`, `entry_type`);

ALTER TABLE `transaction_headers` 
  ADD INDEX IF NOT EXISTS `idx_th_date_type` (`txn_date`, `txn_type`),
  ADD INDEX IF NOT EXISTS `idx_th_status` (`status`, `is_deleted`);

ALTER TABLE `items` 
  ADD INDEX IF NOT EXISTS `idx_items_del_act` (`is_deleted`, `is_active`),
  ADD INDEX IF NOT EXISTS `idx_items_stock` (`current_stock`, `reorder_level`);

ALTER TABLE `pos_entry` 
  ADD INDEX IF NOT EXISTS `idx_pos_date_status` (`date_time`, `is_deleted`),
  ADD INDEX IF NOT EXISTS `idx_pos_created` (`created_by`);

ALTER TABLE `pos_items` 
  ADD INDEX IF NOT EXISTS `idx_pos_items_item` (`item_id`, `pos_id`);

ALTER TABLE `pos_payments` 
  ADD INDEX IF NOT EXISTS `idx_pos_pay_mode` (`payment_mode`, `pos_id`);

ALTER TABLE `customer_invoices` 
  ADD INDEX IF NOT EXISTS `idx_ci_payment_status` (`payment_status`, `invoice_date`);

ALTER TABLE `vendor_bills` 
  ADD INDEX IF NOT EXISTS `idx_vb_payment_status` (`payment_status`, `bill_date`);

-- 5. Dashboard saved filters table
CREATE TABLE IF NOT EXISTS `dashboard_saved_filters` (
    `id` VARCHAR(36) PRIMARY KEY,
    `user_id` VARCHAR(36) NOT NULL,
    `filter_name` VARCHAR(100) NOT NULL,
    `filter_config` JSON NOT NULL,
    `is_default` BOOLEAN DEFAULT FALSE,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 6. Dashboard date range presets
CREATE TABLE IF NOT EXISTS `dashboard_date_presets` (
    `id` VARCHAR(36) PRIMARY KEY,
    `user_id` VARCHAR(36) NOT NULL,
    `preset_name` VARCHAR(100) NOT NULL,
    `date_from` DATE NOT NULL,
    `date_to` DATE NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;