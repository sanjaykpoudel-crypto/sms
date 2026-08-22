<?php
require_once __DIR__ . '/../../database/DBConnection.php';

function run_inventory_costing_migrations() {
    $db = db();
    $pdo = $db->getConnection();

    echo "--- CREATING PERPETUAL INVENTORY COSTING TABLES ---\n";

    // 1. inventory_ledger
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS inventory_ledger (
            ledger_id BIGINT PRIMARY KEY AUTO_INCREMENT,
            item_id INT NOT NULL,
            location_id INT NOT NULL,
            transaction_id INT NULL,
            transaction_line_id INT NULL,
            transaction_type VARCHAR(50) NOT NULL,
            movement_type VARCHAR(50) NOT NULL,
            transaction_date DATETIME NOT NULL,
            sequence_number BIGINT NOT NULL,
            quantity_in DECIMAL(18,6) DEFAULT 0.000000,
            quantity_out DECIMAL(18,6) DEFAULT 0.000000,
            net_quantity DECIMAL(18,6) DEFAULT 0.000000,
            unit_cost DECIMAL(18,6) DEFAULT 0.000000,
            total_value_in DECIMAL(18,6) DEFAULT 0.000000,
            total_value_out DECIMAL(18,6) DEFAULT 0.000000,
            running_qty_balance DECIMAL(18,6) DEFAULT 0.000000,
            running_value_balance DECIMAL(18,6) DEFAULT 0.000000,
            costing_method_used ENUM('AVERAGE','FIFO') DEFAULT 'AVERAGE',
            is_backdated TINYINT(1) DEFAULT 0,
            reversal_of_id BIGINT NULL,
            reason VARCHAR(255) NULL,
            created_by INT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            recosted_at DATETIME NULL,
            UNIQUE KEY uq_item_loc_seq (item_id, location_id, sequence_number),
            INDEX idx_item_loc_date (item_id, location_id, transaction_date),
            INDEX idx_txn_line (transaction_id, transaction_line_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    echo "Table 'inventory_ledger' created/verified.\n";

    // 2. fifo_cost_layers
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS fifo_cost_layers (
            layer_id BIGINT PRIMARY KEY AUTO_INCREMENT,
            item_id INT NOT NULL,
            location_id INT NOT NULL,
            ledger_id BIGINT NOT NULL,
            receipt_date DATETIME NOT NULL,
            original_qty DECIMAL(18,6) NOT NULL,
            remaining_qty DECIMAL(18,6) NOT NULL,
            unit_cost DECIMAL(18,6) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_fifo_open (item_id, location_id, remaining_qty, receipt_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    echo "Table 'fifo_cost_layers' created/verified.\n";

    // 3. inventory_balances (fast O(1) snapshot)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS inventory_balances (
            item_id INT NOT NULL,
            location_id INT NOT NULL,
            quantity_on_hand DECIMAL(18,6) DEFAULT 0.000000,
            average_cost DECIMAL(18,6) DEFAULT 0.000000,
            total_value DECIMAL(18,6) DEFAULT 0.000000,
            last_ledger_id BIGINT NULL,
            is_dirty TINYINT(1) DEFAULT 0,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (item_id, location_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    try { $pdo->exec("ALTER TABLE inventory_balances ADD COLUMN total_value DECIMAL(18,6) DEFAULT 0.000000"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE inventory_balances ADD COLUMN last_ledger_id BIGINT NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE inventory_balances ADD COLUMN is_dirty TINYINT(1) DEFAULT 0"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE inventory_balances ADD COLUMN updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE items ADD COLUMN costing_method ENUM('AVERAGE','FIFO') DEFAULT 'AVERAGE'"); } catch (Exception $e) {}
    echo "Table 'inventory_balances' created/verified.\n";

    // 4. inventory_ledger_history (audit log for recosted/deleted ledger rows)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS inventory_ledger_history (
            history_id BIGINT PRIMARY KEY AUTO_INCREMENT,
            ledger_id BIGINT NOT NULL,
            item_id INT NOT NULL,
            location_id INT NOT NULL,
            transaction_id INT NULL,
            transaction_line_id INT NULL,
            transaction_date DATETIME NOT NULL,
            sequence_number BIGINT NOT NULL,
            quantity_in DECIMAL(18,6),
            quantity_out DECIMAL(18,6),
            unit_cost DECIMAL(18,6),
            running_qty_balance DECIMAL(18,6),
            running_value_balance DECIMAL(18,6),
            archived_reason VARCHAR(255),
            archived_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    echo "Table 'inventory_ledger_history' created/verified.\n";

    // 5. recost_queue
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS recost_queue (
            queue_id BIGINT PRIMARY KEY AUTO_INCREMENT,
            item_id INT NOT NULL,
            location_id INT NOT NULL,
            from_sequence_number BIGINT DEFAULT 0,
            from_date DATETIME NULL,
            status ENUM('PENDING','PROCESSING','COMPLETED','FAILED') DEFAULT 'PENDING',
            error_message TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_queue_status (status, item_id, location_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    echo "Table 'recost_queue' created/verified.\n";

    echo "--- MIGRATIONS COMPLETED SUCCESSFULLY ---\n";
}

if (basename(__FILE__) === basename($_SERVER['PHP_SELF'] ?? '')) {
    run_inventory_costing_migrations();
}
