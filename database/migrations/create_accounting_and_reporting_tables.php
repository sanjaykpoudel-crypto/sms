<?php
require_once __DIR__ . '/../../database/DBConnection.php';

function run_accounting_reporting_migrations() {
    $db = db();
    $pdo = $db->getConnection();

    echo "--- CREATING ACCOUNTING & REPORTING ENGINE TABLES ---\n";

    // 1. chart_of_accounts
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS chart_of_accounts (
            account_id INT PRIMARY KEY AUTO_INCREMENT,
            account_code VARCHAR(20) UNIQUE,
            account_name VARCHAR(255) NOT NULL,
            account_type ENUM('ASSET','LIABILITY','EQUITY','INCOME','COGS','EXPENSE') NOT NULL,
            normal_balance ENUM('DEBIT','CREDIT') NOT NULL,
            parent_account_id INT NULL,
            is_active TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    echo "Table 'chart_of_accounts' created/verified.\n";

    // Drop legacy journal tables if incompatible
    try { $pdo->exec("DROP TABLE IF EXISTS journal_lines"); } catch (Exception $e) {}
    try { $pdo->exec("DROP TABLE IF EXISTS journal_entries"); } catch (Exception $e) {}

    // 2. journal_entries
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS journal_entries (
            je_id BIGINT PRIMARY KEY AUTO_INCREMENT,
            transaction_id INT NULL,
            je_date DATETIME NOT NULL,
            je_type VARCHAR(50) NOT NULL,
            memo VARCHAR(255) NULL,
            status ENUM('POSTED','VOIDED') DEFAULT 'POSTED',
            period_id INT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_txn (transaction_id),
            INDEX idx_date (je_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    echo "Table 'journal_entries' created/verified.\n";

    // 3. journal_lines
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS journal_lines (
            jl_id BIGINT PRIMARY KEY AUTO_INCREMENT,
            je_id BIGINT NOT NULL,
            account_id INT NOT NULL,
            debit DECIMAL(18,2) DEFAULT 0.00,
            credit DECIMAL(18,2) DEFAULT 0.00,
            entity_type ENUM('CUSTOMER','VENDOR','ITEM','NONE') DEFAULT 'NONE',
            entity_id INT NULL,
            location_id INT NULL,
            class_id INT NULL,
            INDEX idx_je (je_id),
            INDEX idx_account_date (account_id),
            INDEX idx_entity (entity_type, entity_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    echo "Table 'journal_lines' created/verified.\n";

    // 4. accounting_periods
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS accounting_periods (
            period_id INT PRIMARY KEY AUTO_INCREMENT,
            period_name VARCHAR(50) NOT NULL,
            start_date DATE NOT NULL,
            end_date DATE NOT NULL,
            status ENUM('OPEN','CLOSED','LOCKED') DEFAULT 'OPEN',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    echo "Table 'accounting_periods' created/verified.\n";

    // 5. account_balances
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS account_balances (
            account_id INT NOT NULL,
            period_id INT NOT NULL,
            opening_balance DECIMAL(18,2) DEFAULT 0.00,
            period_debits DECIMAL(18,2) DEFAULT 0.00,
            period_credits DECIMAL(18,2) DEFAULT 0.00,
            closing_balance DECIMAL(18,2) DEFAULT 0.00,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (account_id, period_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    echo "Table 'account_balances' created/verified.\n";

    // 6. Seed Default Chart of Accounts if empty
    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM chart_of_accounts")->fetchColumn();
    if ($cnt === 0) {
        $coaSeed = [
            ['1010', 'Cash and Bank Balances', 'ASSET', 'DEBIT', null],
            ['1020', 'Accounts Receivable (AR)', 'ASSET', 'DEBIT', null],
            ['1030', 'Inventory Asset', 'ASSET', 'DEBIT', null],
            ['2010', 'Accounts Payable (AP)', 'LIABILITY', 'CREDIT', null],
            ['2020', 'VAT / Sales Tax Payable', 'LIABILITY', 'CREDIT', null],
            ['3010', "Owner's Capital / Equity", 'EQUITY', 'CREDIT', null],
            ['3020', 'Retained Earnings', 'EQUITY', 'CREDIT', null],
            ['4010', 'Sales Revenue', 'INCOME', 'CREDIT', null],
            ['4020', 'Other Income / Adjustment Gain', 'INCOME', 'CREDIT', null],
            ['5010', 'Cost of Goods Sold (COGS)', 'COGS', 'DEBIT', null],
            ['6010', 'Operating Expenses', 'EXPENSE', 'DEBIT', null],
            ['6020', 'Inventory Adjustment / Shrinkage Expense', 'EXPENSE', 'DEBIT', null],
        ];
        $stmtIns = $pdo->prepare("INSERT INTO chart_of_accounts (account_code, account_name, account_type, normal_balance, parent_account_id) VALUES (?, ?, ?, ?, ?)");
        foreach ($coaSeed as $acc) {
            $stmtIns->execute($acc);
        }
        echo "Seeded 12 core Chart of Accounts master records.\n";
    }

    // 7. Seed Default Accounting Periods for 2026 if empty
    $cntP = (int)$pdo->query("SELECT COUNT(*) FROM accounting_periods")->fetchColumn();
    if ($cntP === 0) {
        $months = [
            ['Jan 2026', '2026-01-01', '2026-01-31'],
            ['Feb 2026', '2026-02-01', '2026-02-28'],
            ['Mar 2026', '2026-03-01', '2026-03-31'],
            ['Apr 2026', '2026-04-01', '2026-04-30'],
            ['May 2026', '2026-05-01', '2026-05-31'],
            ['Jun 2026', '2026-06-01', '2026-06-30'],
            ['Jul 2026', '2026-07-01', '2026-07-31'],
            ['Aug 2026', '2026-08-01', '2026-08-31'],
            ['Sep 2026', '2026-09-01', '2026-09-30'],
            ['Oct 2026', '2026-10-01', '2026-10-31'],
            ['Nov 2026', '2026-11-01', '2026-11-30'],
            ['Dec 2026', '2026-12-01', '2026-12-31'],
        ];
        $stmtP = $pdo->prepare("INSERT INTO accounting_periods (period_name, start_date, end_date, status) VALUES (?, ?, ?, 'OPEN')");
        foreach ($months as $m) {
            $stmtP->execute($m);
        }
        echo "Seeded 12 monthly accounting periods for 2026.\n";
    }

    echo "--- ACCOUNTING MIGRATIONS COMPLETED SUCCESSFULLY ---\n";
}

if (basename(__FILE__) === basename($_SERVER['PHP_SELF'] ?? '')) {
    run_accounting_reporting_migrations();
}
