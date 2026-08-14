<?php
/**
 * database/migrations/05_promotions_module.php
 * Migration script to create tables and alter existing sales tables for the Promotional Discount module.
 */

$host = 'localhost';
$user = 'root';
$pass = '';
$db   = 'sms_db';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    echo "Running 05_promotions_module migration...\n";

    $queries = [
        // 1. Promotions Master Table
        "CREATE TABLE IF NOT EXISTS `promotions` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `promo_code` VARCHAR(50) NOT NULL UNIQUE,
            `name` VARCHAR(150) NOT NULL,
            `description` TEXT NULL,
            `status` ENUM('draft', 'active', 'inactive') NOT NULL DEFAULT 'active',
            `start_datetime` DATETIME NOT NULL,
            `end_datetime` DATETIME NOT NULL,
            `discount_basis` ENUM('mrp', 'selling_price') NOT NULL DEFAULT 'mrp',
            `discount_type` ENUM('percentage', 'fixed') NOT NULL DEFAULT 'percentage',
            `discount_value` DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
            `applies_to_locations` ENUM('all', 'selected') NOT NULL DEFAULT 'all',
            `min_qty` DECIMAL(12,2) NOT NULL DEFAULT 1.00,
            `max_qty` DECIMAL(12,2) NULL,
            `priority` INT NOT NULL DEFAULT 1,
            `is_stackable` TINYINT(1) NOT NULL DEFAULT 0,
            `created_by` INT NULL,
            `updated_by` INT NULL,
            `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_promo_dates` (`start_datetime`, `end_datetime`, `status`),
            INDEX `idx_promo_status` (`status`, `is_deleted`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        // 2. Promotion Covered Items Mapping Table
        "CREATE TABLE IF NOT EXISTS `promotion_items` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `promotion_id` INT NOT NULL,
            `item_id` INT NOT NULL,
            `override_discount_type` ENUM('percentage', 'fixed') NULL,
            `override_discount_value` DECIMAL(12,4) NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`promotion_id`) REFERENCES `promotions`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`item_id`) REFERENCES `items`(`id`) ON DELETE CASCADE,
            UNIQUE KEY `uk_promo_item` (`promotion_id`, `item_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        // 3. Promotion Applicable Locations Mapping Table
        "CREATE TABLE IF NOT EXISTS `promotion_locations` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `promotion_id` INT NOT NULL,
            `location_id` INT NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`promotion_id`) REFERENCES `promotions`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`location_id`) REFERENCES `locations`(`id`) ON DELETE CASCADE,
            UNIQUE KEY `uk_promo_location` (`promotion_id`, `location_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
    ];

    foreach ($queries as $sql) {
        $pdo->exec($sql);
    }
    echo "Promotions tables created successfully.\n";

    // Helper function to safely add columns if missing
    $add_column_if_not_exists = function($table, $column, $definition) use ($pdo) {
        $check = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $check->execute([$column]);
        if ($check->rowCount() == 0) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
            echo "Added `$column` to `$table`.\n";
        }
    };

    // Alter pos_items for line-level promo snapshot fields
    $add_column_if_not_exists('pos_items', 'promotion_id', 'INT NULL AFTER `item_id`');
    $add_column_if_not_exists('pos_items', 'promo_code', 'VARCHAR(50) NULL AFTER `promotion_id`');
    $add_column_if_not_exists('pos_items', 'mrp_at_sale', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `rate`');
    $add_column_if_not_exists('pos_items', 'normal_selling_price_at_sale', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `mrp_at_sale`');
    $add_column_if_not_exists('pos_items', 'promo_discount_amount', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `normal_selling_price_at_sale`');

    // Alter transaction_lines for line-level promo snapshot fields
    $add_column_if_not_exists('transaction_lines', 'promotion_id', 'INT NULL AFTER `item_id`');
    $add_column_if_not_exists('transaction_lines', 'promo_code', 'VARCHAR(50) NULL AFTER `promotion_id`');
    $add_column_if_not_exists('transaction_lines', 'mrp_at_sale', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `unit_price`');
    $add_column_if_not_exists('transaction_lines', 'normal_selling_price_at_sale', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `mrp_at_sale`');
    $add_column_if_not_exists('transaction_lines', 'promo_discount_amount', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `normal_selling_price_at_sale`');

    echo "Migration 05_promotions_module completed successfully!\n";

} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
