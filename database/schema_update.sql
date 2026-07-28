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

-- ============================================================
-- 9. AccountTypeMaster Table Creation and Default Seeding
-- ============================================================

CREATE TABLE IF NOT EXISTS `AccountTypeMaster` (
  `AccountTypeId` INT AUTO_INCREMENT PRIMARY KEY,
  `AccountTypeName` VARCHAR(100) NOT NULL UNIQUE,
  `Category` ENUM('Asset', 'Liability', 'Equity', 'Income', 'Expense') NOT NULL,
  `NormalBalance` ENUM('Debit', 'Credit') NOT NULL,
  `Description` VARCHAR(255) NULL,
  `IsSystem` TINYINT(1) NOT NULL DEFAULT 1,
  `IsActive` TINYINT(1) NOT NULL DEFAULT 1,
  `SortOrder` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `AccountTypeMaster` (`AccountTypeName`, `Category`, `NormalBalance`, `Description`, `IsSystem`, `IsActive`, `SortOrder`) VALUES
('Bank', 'Asset', 'Debit', 'Bank and cash accounts', 1, 1, 10),
('Accounts Receivable', 'Asset', 'Debit', 'Customer receivables', 1, 1, 30),
('Inventory Asset', 'Asset', 'Debit', 'Liquor inventory value', 1, 1, 40),
('Other Current Asset', 'Asset', 'Debit', 'Deposits, advances, prepaid expenses', 1, 1, 50),
('Fixed Asset', 'Asset', 'Debit', 'Furniture, vehicles, equipment', 1, 1, 60),
('Other Asset', 'Asset', 'Debit', 'Long-term assets', 1, 1, 70),
('Accounts Payable', 'Liability', 'Credit', 'Vendor balances', 1, 1, 80),
('Credit Card', 'Liability', 'Credit', 'Credit card liabilities', 1, 1, 90),
('Other Current Liability', 'Liability', 'Credit', 'VAT, excise duty, payroll, accruals', 1, 1, 100),
('Long Term Liability', 'Liability', 'Credit', 'Loans and financing', 1, 1, 110),
('Owner\'s Equity', 'Equity', 'Credit', 'Capital account', 1, 1, 120),
('Retained Earnings', 'Equity', 'Credit', 'Prior year profits', 1, 1, 130),
('Current Year Earnings', 'Equity', 'Credit', 'Current fiscal year profit/loss', 1, 1, 140),
('Sales Income', 'Income', 'Credit', 'Liquor sales', 1, 1, 150),
('Other Income', 'Income', 'Credit', 'Interest, discounts received, miscellaneous income', 1, 1, 160),
('Cost of Goods Sold', 'Expense', 'Debit', 'Cost of inventory sold', 1, 1, 170),
('Operating Expense', 'Expense', 'Debit', 'Rent, salary, utilities, fuel, repairs', 1, 1, 180),
('Other Expense', 'Expense', 'Debit', 'Bank charges, exchange loss, miscellaneous expenses', 1, 1, 190);

ALTER TABLE `accounts` ADD COLUMN IF NOT EXISTS `account_type_id` INT NULL AFTER `account_name`;
ALTER TABLE `accounts` ADD COLUMN IF NOT EXISTS `description` VARCHAR(255) NULL AFTER `normal_balance`;

-- ============================================================
-- 10. Locations Table Creation and Initial Seeding
-- ============================================================

CREATE TABLE IF NOT EXISTS `locations` (
  `id` VARCHAR(36) PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL UNIQUE,
  `type` VARCHAR(50) NOT NULL DEFAULT 'Warehouse',
  `description` VARCHAR(255) NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `locations` (`id`, `name`, `type`, `description`, `is_active`) VALUES
('loc-main-wh', 'Main Warehouse', 'Warehouse', 'Central inventory storage and warehouse', 1),
('loc-main-retail', 'Main Retail Outlet', 'Retail Store', 'Primary retail store and POS location', 1);


