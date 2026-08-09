<?php
/**
 * 01_create_schema.php
 * Creates all normalized ERP database tables with explicit foreign keys, indexes, and constraints.
 */

$host = 'localhost';
$user = 'root';
$pass = '';
$db   = 'sms_db';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    echo "Creating normalized ERP tables in `$db`...\n";

    $queries = [
        // 1. Account Types Master
        "CREATE TABLE IF NOT EXISTS `erp_account_types` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(100) NOT NULL UNIQUE,
            `category` ENUM('Asset', 'Liability', 'Equity', 'Income', 'Expense') NOT NULL,
            `normal_balance` ENUM('Debit', 'Credit') NOT NULL,
            `description` VARCHAR(255) NULL,
            `is_system` TINYINT(1) NOT NULL DEFAULT 1,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `sort_order` INT NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        // 2. Chart of Accounts (COA)
        "CREATE TABLE IF NOT EXISTS `erp_accounts` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `account_code` VARCHAR(50) NOT NULL UNIQUE,
            `account_name` VARCHAR(150) NOT NULL,
            `account_type_id` INT NOT NULL,
            `parent_account_id` INT NULL,
            `normal_balance` ENUM('Debit', 'Credit') NOT NULL,
            `currency` CHAR(3) NOT NULL DEFAULT 'NPR',
            `opening_balance` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
            `current_balance` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (`account_type_id`) REFERENCES `erp_account_types`(`id`),
            FOREIGN KEY (`parent_account_id`) REFERENCES `erp_accounts`(`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        // 3. Customers Master
        "CREATE TABLE IF NOT EXISTS `erp_customers` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `customer_code` VARCHAR(50) NOT NULL UNIQUE,
            `name` VARCHAR(150) NOT NULL,
            `company_name` VARCHAR(150) NULL,
            `pan_vat_no` VARCHAR(50) NULL,
            `phone` VARCHAR(50) NULL,
            `email` VARCHAR(100) NULL,
            `address` TEXT NULL,
            `receivable_account_id` INT NULL,
            `credit_limit` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
            `opening_balance` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
            `current_balance` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (`receivable_account_id`) REFERENCES `erp_accounts`(`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        // 4. Vendors Master
        "CREATE TABLE IF NOT EXISTS `erp_vendors` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `vendor_code` VARCHAR(50) NOT NULL UNIQUE,
            `name` VARCHAR(150) NOT NULL,
            `company_name` VARCHAR(150) NULL,
            `pan_vat_no` VARCHAR(50) NULL,
            `phone` VARCHAR(50) NULL,
            `email` VARCHAR(100) NULL,
            `address` TEXT NULL,
            `payable_account_id` INT NULL,
            `opening_balance` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
            `current_balance` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (`payable_account_id`) REFERENCES `erp_accounts`(`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        // 5. Item Categories
        "CREATE TABLE IF NOT EXISTS `erp_categories` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(100) NOT NULL UNIQUE,
            `parent_id` INT NULL,
            `description` TEXT NULL,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (`parent_id`) REFERENCES `erp_categories`(`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        // 6. Tax Codes Master
        "CREATE TABLE IF NOT EXISTS `erp_tax_codes` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `code` VARCHAR(20) NOT NULL UNIQUE,
            `name` VARCHAR(100) NOT NULL,
            `rate` DECIMAL(8,4) NOT NULL DEFAULT 0.0000,
            `input_tax_account_id` INT NULL,
            `output_tax_account_id` INT NULL,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (`input_tax_account_id`) REFERENCES `erp_accounts`(`id`) ON DELETE SET NULL,
            FOREIGN KEY (`output_tax_account_id`) REFERENCES `erp_accounts`(`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        // 7. Items Master
        "CREATE TABLE IF NOT EXISTS `erp_items` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `item_code` VARCHAR(50) NOT NULL UNIQUE,
            `barcode` VARCHAR(100) NULL UNIQUE,
            `name` VARCHAR(200) NOT NULL,
            `category_id` INT NULL,
            `unit` VARCHAR(20) NOT NULL DEFAULT 'Pcs',
            `cost_price` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
            `selling_price` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
            `mrp` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
            `min_stock` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
            `max_stock` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
            `reorder_level` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
            `tax_code_id` INT NULL,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (`category_id`) REFERENCES `erp_categories`(`id`) ON DELETE SET NULL,
            FOREIGN KEY (`tax_code_id`) REFERENCES `erp_tax_codes`(`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        // 8. Locations Master
        "CREATE TABLE IF NOT EXISTS `erp_locations` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `location_code` VARCHAR(50) NOT NULL UNIQUE,
            `name` VARCHAR(100) NOT NULL,
            `address` TEXT NULL,
            `phone` VARCHAR(50) NULL,
            `is_main` TINYINT(1) NOT NULL DEFAULT 0,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        // 9. Item Locations Multi-warehouse Stock & Pricing
        "CREATE TABLE IF NOT EXISTS `erp_item_locations` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `item_id` INT NOT NULL,
            `location_id` INT NOT NULL,
            `cost_price` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
            `selling_price` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
            `mrp` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
            `stock_quantity` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
            `reorder_level` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `item_location_unique` (`item_id`, `location_id`),
            FOREIGN KEY (`item_id`) REFERENCES `erp_items`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`location_id`) REFERENCES `erp_locations`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        // 10. Currencies Master
        "CREATE TABLE IF NOT EXISTS `erp_currencies` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `code` CHAR(3) NOT NULL UNIQUE,
            `name` VARCHAR(50) NOT NULL,
            `symbol` VARCHAR(10) NOT NULL,
            `exchange_rate` DECIMAL(14,6) NOT NULL DEFAULT 1.000000,
            `is_base` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        // 11. Transaction Types Lookup
        "CREATE TABLE IF NOT EXISTS `erp_transaction_types` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `code` VARCHAR(50) NOT NULL UNIQUE,
            `name` VARCHAR(100) NOT NULL,
            `prefix` VARCHAR(10) NOT NULL,
            `module` VARCHAR(50) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        // 12. Payment Methods Lookup linked to COA Account
        "CREATE TABLE IF NOT EXISTS `erp_payment_methods` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `code` VARCHAR(50) NOT NULL UNIQUE,
            `name` VARCHAR(100) NOT NULL,
            `account_id` INT NOT NULL,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            FOREIGN KEY (`account_id`) REFERENCES `erp_accounts`(`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        // 13. Fiscal Periods
        "CREATE TABLE IF NOT EXISTS `erp_fiscal_periods` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `fiscal_year` VARCHAR(20) NOT NULL,
            `period_name` VARCHAR(50) NOT NULL,
            `start_date` DATE NOT NULL,
            `end_date` DATE NOT NULL,
            `is_closed` TINYINT(1) NOT NULL DEFAULT 0,
            `closed_at` DATETIME NULL,
            `closed_by` INT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        // 14. Accounting Preferences Table
        "CREATE TABLE IF NOT EXISTS `erp_accounting_preferences` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `preference_key` VARCHAR(100) NOT NULL,
            `account_id` INT NOT NULL,
            `location_id` INT NULL,
            `effective_from` DATE NOT NULL DEFAULT '2000-01-01',
            `effective_to` DATE NULL,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_pref_key` (`preference_key`, `is_active`, `effective_from`, `effective_to`),
            FOREIGN KEY (`account_id`) REFERENCES `erp_accounts`(`id`),
            FOREIGN KEY (`location_id`) REFERENCES `erp_locations`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        // 15. Item Level Accounting Configuration
        "CREATE TABLE IF NOT EXISTS `erp_item_accounting` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `item_id` INT NOT NULL UNIQUE,
            `income_account_id` INT NULL,
            `cogs_account_id` INT NULL,
            `inventory_asset_account_id` INT NULL,
            `purchase_account_id` INT NULL,
            `sales_return_account_id` INT NULL,
            `purchase_return_account_id` INT NULL,
            FOREIGN KEY (`item_id`) REFERENCES `erp_items`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`income_account_id`) REFERENCES `erp_accounts`(`id`),
            FOREIGN KEY (`cogs_account_id`) REFERENCES `erp_accounts`(`id`),
            FOREIGN KEY (`inventory_asset_account_id`) REFERENCES `erp_accounts`(`id`),
            FOREIGN KEY (`purchase_account_id`) REFERENCES `erp_accounts`(`id`),
            FOREIGN KEY (`sales_return_account_id`) REFERENCES `erp_accounts`(`id`),
            FOREIGN KEY (`purchase_return_account_id`) REFERENCES `erp_accounts`(`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        // 16. Category Level Accounting Configuration
        "CREATE TABLE IF NOT EXISTS `erp_category_accounting` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `category_id` INT NOT NULL UNIQUE,
            `income_account_id` INT NULL,
            `cogs_account_id` INT NULL,
            `inventory_account_id` INT NULL,
            `purchase_account_id` INT NULL,
            FOREIGN KEY (`category_id`) REFERENCES `erp_categories`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`income_account_id`) REFERENCES `erp_accounts`(`id`),
            FOREIGN KEY (`cogs_account_id`) REFERENCES `erp_accounts`(`id`),
            FOREIGN KEY (`inventory_account_id`) REFERENCES `erp_accounts`(`id`),
            FOREIGN KEY (`purchase_account_id`) REFERENCES `erp_accounts`(`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        // 17. Location Level Accounting Overrides
        "CREATE TABLE IF NOT EXISTS `erp_location_accounting` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `location_id` INT NOT NULL UNIQUE,
            `sales_account_id` INT NULL,
            `cogs_account_id` INT NULL,
            `inventory_account_id` INT NULL,
            FOREIGN KEY (`location_id`) REFERENCES `erp_locations`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`sales_account_id`) REFERENCES `erp_accounts`(`id`),
            FOREIGN KEY (`cogs_account_id`) REFERENCES `erp_accounts`(`id`),
            FOREIGN KEY (`inventory_account_id`) REFERENCES `erp_accounts`(`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        // 18. Core Transactions Header
        "CREATE TABLE IF NOT EXISTS `erp_transactions` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `transaction_no` VARCHAR(50) NOT NULL UNIQUE,
            `transaction_type_id` INT NOT NULL,
            `transaction_date` DATE NOT NULL,
            `posting_date` DATE NOT NULL,
            `fiscal_period_id` INT NULL,
            `location_id` INT NOT NULL,
            `customer_id` INT NULL,
            `vendor_id` INT NULL,
            `currency_id` INT NOT NULL DEFAULT 1,
            `exchange_rate` DECIMAL(14,6) NOT NULL DEFAULT 1.000000,
            `subtotal` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
            `discount_amount` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
            `tax_amount` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
            `grand_total` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
            `paid_amount` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
            `due_amount` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
            `payment_status` ENUM('unpaid', 'partial', 'paid') NOT NULL DEFAULT 'unpaid',
            `status` ENUM('draft', 'posted', 'void', 'cancelled') NOT NULL DEFAULT 'posted',
            `memo` TEXT NULL,
            `external_reference` VARCHAR(100) NULL,
            `source_type` VARCHAR(50) NULL,
            `source_id` INT NULL,
            `created_by` INT NULL,
            `updated_by` INT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `version_no` INT NOT NULL DEFAULT 1,
            `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
            INDEX `idx_tx_date` (`transaction_date`),
            INDEX `idx_tx_post_date` (`posting_date`),
            INDEX `idx_tx_type` (`transaction_type_id`),
            INDEX `idx_tx_customer` (`customer_id`),
            INDEX `idx_tx_vendor` (`vendor_id`),
            INDEX `idx_tx_location` (`location_id`),
            FOREIGN KEY (`transaction_type_id`) REFERENCES `erp_transaction_types`(`id`),
            FOREIGN KEY (`location_id`) REFERENCES `erp_locations`(`id`),
            FOREIGN KEY (`customer_id`) REFERENCES `erp_customers`(`id`) ON DELETE SET NULL,
            FOREIGN KEY (`vendor_id`) REFERENCES `erp_vendors`(`id`) ON DELETE SET NULL,
            FOREIGN KEY (`fiscal_period_id`) REFERENCES `erp_fiscal_periods`(`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        // 19. Core Transaction Lines
        "CREATE TABLE IF NOT EXISTS `erp_transaction_lines` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `transaction_id` INT NOT NULL,
            `line_no` INT NOT NULL,
            `item_id` INT NULL,
            `account_id` INT NULL,
            `customer_id` INT NULL,
            `vendor_id` INT NULL,
            `location_id` INT NULL,
            `description` TEXT NULL,
            `quantity` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
            `unit_price` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
            `gross_amount` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
            `discount_amount` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
            `tax_amount` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
            `net_amount` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
            `tax_code_id` INT NULL,
            INDEX `idx_tx_lines_tx_id` (`transaction_id`),
            INDEX `idx_tx_lines_item_id` (`item_id`),
            INDEX `idx_tx_lines_account_id` (`account_id`),
            FOREIGN KEY (`transaction_id`) REFERENCES `erp_transactions`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`item_id`) REFERENCES `erp_items`(`id`) ON DELETE SET NULL,
            FOREIGN KEY (`account_id`) REFERENCES `erp_accounts`(`id`) ON DELETE SET NULL,
            FOREIGN KEY (`tax_code_id`) REFERENCES `erp_tax_codes`(`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        // 20. Authoritative GL Header
        "CREATE TABLE IF NOT EXISTS `erp_gl_transactions` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `gl_no` VARCHAR(50) NOT NULL UNIQUE,
            `transaction_id` INT NOT NULL,
            `posting_date` DATE NOT NULL,
            `fiscal_period_id` INT NULL,
            `memo` VARCHAR(255) NULL,
            `total_debit` DECIMAL(18,4) NOT NULL,
            `total_credit` DECIMAL(18,4) NOT NULL,
            `is_balanced` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`transaction_id`) REFERENCES `erp_transactions`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        // 21. Authoritative GL Lines
        "CREATE TABLE IF NOT EXISTS `erp_gl_lines` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `gl_transaction_id` INT NOT NULL,
            `transaction_id` INT NOT NULL,
            `line_no` INT NOT NULL,
            `account_id` INT NOT NULL,
            `debit` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
            `credit` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
            `customer_id` INT NULL,
            `vendor_id` INT NULL,
            `item_id` INT NULL,
            `location_id` INT NULL,
            `posting_date` DATE NOT NULL,
            `memo` VARCHAR(255) NULL,
            INDEX `idx_gl_tx_id` (`transaction_id`),
            INDEX `idx_gl_account_date` (`account_id`, `posting_date`),
            INDEX `idx_gl_cust_date` (`customer_id`, `posting_date`),
            INDEX `idx_gl_vend_date` (`vendor_id`, `posting_date`),
            INDEX `idx_gl_item_date` (`item_id`, `posting_date`),
            FOREIGN KEY (`gl_transaction_id`) REFERENCES `erp_gl_transactions`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`transaction_id`) REFERENCES `erp_transactions`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`account_id`) REFERENCES `erp_accounts`(`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        // 22. Master Payments Header
        "CREATE TABLE IF NOT EXISTS `erp_payments` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `payment_no` VARCHAR(50) NOT NULL UNIQUE,
            `transaction_id` INT NULL,
            `payment_type` ENUM('customer_payment', 'vendor_payment', 'pos_payment') NOT NULL,
            `party_type` ENUM('customer', 'vendor') NULL,
            `party_id` INT NULL,
            `payment_date` DATE NOT NULL,
            `posting_date` DATE NOT NULL,
            `amount` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
            `unapplied_amount` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
            `reference_no` VARCHAR(100) NULL,
            `memo` TEXT NULL,
            `created_by` INT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (`transaction_id`) REFERENCES `erp_transactions`(`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        // 23. Payment Method Details Lines
        "CREATE TABLE IF NOT EXISTS `erp_payment_lines` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `payment_id` INT NOT NULL,
            `payment_method_id` INT NOT NULL,
            `account_id` INT NOT NULL,
            `amount` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
            `reference_no` VARCHAR(100) NULL,
            FOREIGN KEY (`payment_id`) REFERENCES `erp_payments`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`payment_method_id`) REFERENCES `erp_payment_methods`(`id`),
            FOREIGN KEY (`account_id`) REFERENCES `erp_accounts`(`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        // 24. Payment Applications Matrix
        "CREATE TABLE IF NOT EXISTS `erp_payment_applications` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `payment_id` INT NOT NULL,
            `invoice_transaction_id` INT NOT NULL,
            `applied_amount` DECIMAL(18,4) NOT NULL,
            `applied_date` DATE NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`payment_id`) REFERENCES `erp_payments`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`invoice_transaction_id`) REFERENCES `erp_transactions`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        // 25. Credit Applications Matrix
        "CREATE TABLE IF NOT EXISTS `erp_credit_applications` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `credit_transaction_id` INT NOT NULL,
            `target_transaction_id` INT NOT NULL,
            `applied_amount` DECIMAL(18,4) NOT NULL,
            `applied_date` DATE NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`credit_transaction_id`) REFERENCES `erp_transactions`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`target_transaction_id`) REFERENCES `erp_transactions`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        // 26. AR Customer Transactions Subledger
        "CREATE TABLE IF NOT EXISTS `erp_customer_transactions` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `customer_id` INT NOT NULL,
            `transaction_id` INT NOT NULL,
            `transaction_date` DATE NOT NULL,
            `amount` DECIMAL(18,4) NOT NULL,
            `balance` DECIMAL(18,4) NOT NULL,
            INDEX `idx_cust_tx_cust_date` (`customer_id`, `transaction_date`),
            FOREIGN KEY (`customer_id`) REFERENCES `erp_customers`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`transaction_id`) REFERENCES `erp_transactions`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        // 27. AR Customer Balances Cache Table
        "CREATE TABLE IF NOT EXISTS `erp_customer_balances` (
            `customer_id` INT PRIMARY KEY,
            `current_balance` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (`customer_id`) REFERENCES `erp_customers`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        // 28. AP Vendor Transactions Subledger
        "CREATE TABLE IF NOT EXISTS `erp_vendor_transactions` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `vendor_id` INT NOT NULL,
            `transaction_id` INT NOT NULL,
            `transaction_date` DATE NOT NULL,
            `amount` DECIMAL(18,4) NOT NULL,
            `balance` DECIMAL(18,4) NOT NULL,
            INDEX `idx_vend_tx_vend_date` (`vendor_id`, `transaction_date`),
            FOREIGN KEY (`vendor_id`) REFERENCES `erp_vendors`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`transaction_id`) REFERENCES `erp_transactions`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        // 29. AP Vendor Balances Cache Table
        "CREATE TABLE IF NOT EXISTS `erp_vendor_balances` (
            `vendor_id` INT PRIMARY KEY,
            `current_balance` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (`vendor_id`) REFERENCES `erp_vendors`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        // 30. Inventory Stock Transactions Movements Ledger
        "CREATE TABLE IF NOT EXISTS `erp_inventory_transactions` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `transaction_id` INT NOT NULL,
            `item_id` INT NOT NULL,
            `location_id` INT NOT NULL,
            `posting_date` DATE NOT NULL,
            `movement_type` ENUM('purchase', 'sale', 'sale_return', 'purchase_return', 'adjustment', 'transfer_in', 'transfer_out', 'opening') NOT NULL,
            `quantity` DECIMAL(18,4) NOT NULL,
            `unit_cost` DECIMAL(18,4) NOT NULL,
            `total_cost` DECIMAL(18,4) NOT NULL,
            INDEX `idx_inv_tx_item_loc_date` (`item_id`, `location_id`, `posting_date`),
            FOREIGN KEY (`transaction_id`) REFERENCES `erp_transactions`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`item_id`) REFERENCES `erp_items`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`location_id`) REFERENCES `erp_locations`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        // 31. Inventory Stock Balances Per Item + Location
        "CREATE TABLE IF NOT EXISTS `erp_inventory_balances` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `item_id` INT NOT NULL,
            `location_id` INT NOT NULL,
            `quantity` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
            `avg_unit_cost` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
            `total_value` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `item_loc_bal_unique` (`item_id`, `location_id`),
            FOREIGN KEY (`item_id`) REFERENCES `erp_items`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`location_id`) REFERENCES `erp_locations`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        // 32. Account Balances Performance Summary
        "CREATE TABLE IF NOT EXISTS `erp_account_balances` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `account_id` INT NOT NULL,
            `fiscal_period_id` INT NULL,
            `debit_balance` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
            `credit_balance` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
            `net_balance` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `acc_period_unique` (`account_id`, `fiscal_period_id`),
            FOREIGN KEY (`account_id`) REFERENCES `erp_accounts`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`fiscal_period_id`) REFERENCES `erp_fiscal_periods`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        // 33. High Performance Daily Sales Summary
        "CREATE TABLE IF NOT EXISTS `erp_daily_sales_summary` (
            `date` DATE NOT NULL,
            `location_id` INT NOT NULL,
            `total_sales` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
            `total_discount` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
            `total_tax` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
            `total_net` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
            PRIMARY KEY (`date`, `location_id`),
            FOREIGN KEY (`location_id`) REFERENCES `erp_locations`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        // 34. Migration Audit & Record Mapping Tables
        "CREATE TABLE IF NOT EXISTS `map_accounts` (
            `old_id` VARCHAR(50) NOT NULL PRIMARY KEY,
            `new_id` INT NOT NULL,
            FOREIGN KEY (`new_id`) REFERENCES `erp_accounts`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        "CREATE TABLE IF NOT EXISTS `map_customers` (
            `old_id` VARCHAR(50) NOT NULL PRIMARY KEY,
            `new_id` INT NOT NULL,
            FOREIGN KEY (`new_id`) REFERENCES `erp_customers`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        "CREATE TABLE IF NOT EXISTS `map_vendors` (
            `old_id` VARCHAR(50) NOT NULL PRIMARY KEY,
            `new_id` INT NOT NULL,
            FOREIGN KEY (`new_id`) REFERENCES `erp_vendors`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        "CREATE TABLE IF NOT EXISTS `map_locations` (
            `old_id` VARCHAR(50) NOT NULL PRIMARY KEY,
            `new_id` INT NOT NULL,
            FOREIGN KEY (`new_id`) REFERENCES `erp_locations`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        "CREATE TABLE IF NOT EXISTS `map_items` (
            `old_id` VARCHAR(50) NOT NULL PRIMARY KEY,
            `new_id` INT NOT NULL,
            FOREIGN KEY (`new_id`) REFERENCES `erp_items`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        "CREATE TABLE IF NOT EXISTS `map_transactions` (
            `old_table` VARCHAR(50) NOT NULL,
            `old_id` VARCHAR(50) NOT NULL,
            `new_transaction_id` INT NOT NULL,
            PRIMARY KEY (`old_table`, `old_id`),
            FOREIGN KEY (`new_transaction_id`) REFERENCES `erp_transactions`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        "CREATE TABLE IF NOT EXISTS `migration_audit_log` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `step_name` VARCHAR(100) NOT NULL,
            `source_count` INT NOT NULL DEFAULT 0,
            `migrated_count` INT NOT NULL DEFAULT 0,
            `status` VARCHAR(20) NOT NULL DEFAULT 'SUCCESS',
            `log_message` TEXT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
    ];

    foreach ($queries as $idx => $sql) {
        $pdo->exec($sql);
    }

    echo "SCHEMA_CREATION_SUCCESS: All normalized ERP tables created successfully.\n";
} catch (Exception $e) {
    echo "SCHEMA_CREATION_ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
