<?php
/**
 * Database Connection and Helper Functions
 */

class DBConnection
{
    private $host = 'localhost';
    private $username = 'root';
    private $password = '';
    private $database = 'sms_db';
    private $conn;
    private static $instance       = null;
    private static $initialized    = false;  // AccountTypeMaster seed guard
    private static $schema_boot_done = false; // Process-level boot guard

    private function __construct()
    {
        try {
            $this->conn = new PDO(
                "mysql:host={$this->host};dbname={$this->database};charset=utf8mb4",
                $this->username,
                $this->password,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,   // use native prepares
                    PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
                    // Keep connection alive for the request lifetime
                    PDO::ATTR_PERSISTENT         => false,
                ]
            );
            // Set session-level optimizations
            $this->conn->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO';");
        } catch (PDOException $e) {
            die("Connection failed: " . $e->getMessage());
        }
    }

    /**
     * Run expensive schema boot tasks ONCE per PHP process.
     * Skipped on every subsequent request within the same process (e.g. FPM worker).
     * Also skipped if the session flag tells us we already ran this session.
     */
    private function bootSchema(): void
    {
        // Process-level guard (zero cost after first run in FPM worker)
        if (self::$schema_boot_done) {
            return;
        }
        self::$schema_boot_done = true;

        // Session-level guard: skip entirely if already verified this session
        if (isset($_SESSION['_erp_schema_ok'])) {
            return;
        }

        $this->initAccountTypeMaster();
        $this->initLocationMaster();
        $this->initPaymentStatusMaster();

        // Mark session so subsequent requests skip the boot entirely
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['_erp_schema_ok'] = true;
        }
    }

    public function initAccountTypeMaster()
    {
        if (self::$initialized)
            return;
        self::$initialized = true;

        try {
            // 1. Create AccountTypeMaster table if not exists
            $sqlCreateTable = "CREATE TABLE IF NOT EXISTS `AccountTypeMaster` (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";

            $this->conn->exec($sqlCreateTable);

            // 2. Default Seed Records
            $seedRecords = [
                ['Bank', 'Asset', 'Debit', 'Bank and cash accounts', 1, 1, 10],
                ['Accounts Receivable', 'Asset', 'Debit', 'Customer receivables', 1, 1, 30],
                ['Inventory Asset', 'Asset', 'Debit', 'Liquor inventory value', 1, 1, 40],
                ['Other Current Asset', 'Asset', 'Debit', 'Deposits, advances, prepaid expenses', 1, 1, 50],
                ['Fixed Asset', 'Asset', 'Debit', 'Furniture, vehicles, equipment', 1, 1, 60],
                ['Other Asset', 'Asset', 'Debit', 'Long-term assets', 1, 1, 70],

                ['Accounts Payable', 'Liability', 'Credit', 'Vendor balances', 1, 1, 80],
                ['Credit Card', 'Liability', 'Credit', 'Credit card liabilities', 1, 1, 90],
                ['Other Current Liability', 'Liability', 'Credit', 'VAT, excise duty, payroll, accruals', 1, 1, 100],
                ['Long Term Liability', 'Liability', 'Credit', 'Loans and financing', 1, 1, 110],

                ["Owner's Equity", 'Equity', 'Credit', 'Capital account', 1, 1, 120],
                ['Retained Earnings', 'Equity', 'Credit', 'Prior year profits', 1, 1, 130],
                ['Current Year Earnings', 'Equity', 'Credit', 'Current fiscal year profit/loss', 1, 1, 140],

                ['Sales Income', 'Income', 'Credit', 'Liquor sales', 1, 1, 150],
                ['Other Income', 'Income', 'Credit', 'Interest, discounts received, miscellaneous income', 1, 1, 160],

                ['Cost of Goods Sold', 'Expense', 'Debit', 'Cost of inventory sold', 1, 1, 170],
                ['Operating Expense', 'Expense', 'Debit', 'Rent, salary, utilities, fuel, repairs', 1, 1, 180],
                ['Other Expense', 'Expense', 'Debit', 'Bank charges, exchange loss, miscellaneous expenses', 1, 1, 190],
            ];

            $stmtSelect = $this->conn->prepare("SELECT AccountTypeId, Category, NormalBalance, Description, IsSystem, SortOrder FROM `AccountTypeMaster` WHERE AccountTypeName = ?");
            $stmtInsert = $this->conn->prepare("INSERT INTO `AccountTypeMaster` (AccountTypeName, Category, NormalBalance, Description, IsSystem, IsActive, SortOrder) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmtUpdate = $this->conn->prepare("UPDATE `AccountTypeMaster` SET Category = ?, NormalBalance = ?, Description = ?, IsSystem = ?, SortOrder = ? WHERE AccountTypeId = ?");

            foreach ($seedRecords as $rec) {
                list($name, $cat, $bal, $desc, $isSys, $isActive, $sort) = $rec;
                $stmtSelect->execute([$name]);
                $existing = $stmtSelect->fetch(PDO::FETCH_ASSOC);
                if (!$existing) {
                    $stmtInsert->execute([$name, $cat, $bal, $desc, $isSys, $isActive, $sort]);
                } else {
                    if ($existing['Category'] !== $cat || $existing['NormalBalance'] !== $bal || $existing['Description'] !== $desc || (int) $existing['IsSystem'] !== $isSys || (int) $existing['SortOrder'] !== $sort) {
                        $stmtUpdate->execute([$cat, $bal, $desc, $isSys, $sort, $existing['AccountTypeId']]);
                    }
                }
            }

            // If Cash account type exists, reassign accounts to Bank and remove Cash
            $bankRow = $this->conn->query("SELECT AccountTypeId FROM `AccountTypeMaster` WHERE AccountTypeName = 'Bank'")->fetch();
            $cashRow = $this->conn->query("SELECT AccountTypeId FROM `AccountTypeMaster` WHERE AccountTypeName = 'Cash'")->fetch();
            if ($bankRow && $cashRow) {
                $this->conn->exec("UPDATE accounts SET account_type_id = {$bankRow['AccountTypeId']} WHERE account_type_id = {$cashRow['AccountTypeId']}");
                $this->conn->exec("DELETE FROM AccountTypeMaster WHERE AccountTypeName = 'Cash'");
            }

            // Drop account_code column if present
            try {
                $codeCheck = $this->conn->query("SHOW COLUMNS FROM `accounts` LIKE 'account_code'")->fetch();
                if ($codeCheck) {
                    $this->conn->exec("ALTER TABLE `accounts` DROP COLUMN `account_code`");
                }
            } catch (Exception $e) {
            }

            // 3. Ensure accounts table has account_type_id and description columns
            $colCheck = $this->conn->query("SHOW COLUMNS FROM `accounts` LIKE 'account_type_id'")->fetch();
            if (!$colCheck) {
                $this->conn->exec("ALTER TABLE `accounts` ADD COLUMN `account_type_id` INT NULL AFTER `account_name`");
            }

            $descCheck = $this->conn->query("SHOW COLUMNS FROM `accounts` LIKE 'description'")->fetch();
            if (!$descCheck) {
                $this->conn->exec("ALTER TABLE `accounts` ADD COLUMN `description` VARCHAR(255) NULL AFTER `normal_balance`");
            }

            // 4. Populate missing account_type_id in accounts table based on existing data
            $this->conn->exec("
                UPDATE accounts a
                JOIN AccountTypeMaster atm ON (
                    (a.account_subtype IN ('bank', 'cash') AND atm.AccountTypeName = 'Bank') OR
                    (a.account_subtype = 'receivable' AND atm.AccountTypeName = 'Accounts Receivable') OR
                    (a.account_subtype = 'inventory' AND atm.AccountTypeName = 'Inventory Asset') OR
                    (a.account_subtype = 'payable' AND atm.AccountTypeName = 'Accounts Payable') OR
                    (a.account_subtype = 'tax' AND atm.AccountTypeName = 'Other Current Liability') OR
                    (a.account_subtype = 'cogs' AND atm.AccountTypeName = 'Cost of Goods Sold') OR
                    (a.account_subtype = 'sales' AND atm.AccountTypeName = 'Sales Income') OR
                    (a.account_name LIKE '%Fixed Asset%' AND atm.AccountTypeName = 'Fixed Asset') OR
                    (a.account_name LIKE '%Capital%' AND atm.AccountTypeName = 'Owner\'s Equity') OR
                    (a.account_name LIKE '%Retained Earnings%' AND atm.AccountTypeName = 'Retained Earnings') OR
                    (a.account_name LIKE '%Income Summary%' AND atm.AccountTypeName = 'Current Year Earnings') OR
                    (a.account_name LIKE '%Operating%' AND atm.AccountTypeName = 'Operating Expense') OR
                    (a.account_type = 'expense' AND a.account_subtype = 'other' AND atm.AccountTypeName = 'Operating Expense') OR
                    (a.account_type = 'asset' AND a.account_subtype = 'other' AND atm.AccountTypeName = 'Other Current Asset') OR
                    (a.account_type = 'liability' AND a.account_subtype = 'other' AND atm.AccountTypeName = 'Other Current Liability') OR
                    (a.account_type = 'equity' AND a.account_subtype = 'other' AND atm.AccountTypeName = 'Owner\'s Equity') OR
                    (a.account_type = 'income' AND a.account_subtype = 'other' AND atm.AccountTypeName = 'Other Income')
                )
                SET a.account_type_id = atm.AccountTypeId
                WHERE a.account_type_id IS NULL;
            ");

            // Set fallback for any remaining accounts without account_type_id based on category
            $this->conn->exec("
                UPDATE accounts a
                JOIN AccountTypeMaster atm ON (
                    (a.account_type = 'asset' AND atm.AccountTypeName = 'Other Current Asset') OR
                    (a.account_type = 'liability' AND atm.AccountTypeName = 'Other Current Liability') OR
                    (a.account_type = 'equity' AND atm.AccountTypeName = 'Owner\'s Equity') OR
                    (a.account_type = 'income' AND atm.AccountTypeName = 'Other Income') OR
                    (a.account_type = 'expense' AND atm.AccountTypeName = 'Operating Expense')
                )
                SET a.account_type_id = atm.AccountTypeId
                WHERE a.account_type_id IS NULL;
            ");

            // 5. Sync account_subtype, account_type (category) and normal_balance on accounts table with AccountTypeMaster
            $this->conn->exec("
                UPDATE accounts a
                JOIN AccountTypeMaster atm ON a.account_type_id = atm.AccountTypeId
                SET a.account_subtype = atm.AccountTypeName,
                    a.account_type = LOWER(atm.Category),
                    a.normal_balance = LOWER(atm.NormalBalance);
            ");

        } catch (Exception $e) {
            error_log("initAccountTypeMaster error: " . $e->getMessage());
        }
    }

    public function initLocationMaster()
    {
        try {
            $sqlCreateTable = "CREATE TABLE IF NOT EXISTS `locations` (
              `id` VARCHAR(36) PRIMARY KEY,
              `name` VARCHAR(100) NOT NULL UNIQUE,
              `type` VARCHAR(50) NOT NULL DEFAULT 'Warehouse',
              `description` VARCHAR(255) NULL,
              `is_active` TINYINT(1) NOT NULL DEFAULT 1,
              `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              `deleted_at` DATETIME NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";

            $this->conn->exec($sqlCreateTable);

            // Ensure is_default column exists in locations table
            $defCheck = $this->conn->query("SHOW COLUMNS FROM `locations` LIKE 'is_default'")->fetch();
            if (!$defCheck) {
                $this->conn->exec("ALTER TABLE `locations` ADD COLUMN `is_default` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_active`");
                $this->conn->exec("UPDATE `locations` SET `is_default` = 1 WHERE `id` = 'loc-main-retail'");
            }

            // Ensure location_id column exists in users table
            $userLocCheck = $this->conn->query("SHOW COLUMNS FROM `users` LIKE 'location_id'")->fetch();
            if (!$userLocCheck) {
                $this->conn->exec("ALTER TABLE `users` ADD COLUMN `location_id` VARCHAR(36) NULL AFTER `role`");
            }

            // Ensure location_id column exists in transaction_headers table
            $hdrLocCheck = $this->conn->query("SHOW COLUMNS FROM `transaction_headers` LIKE 'location_id'")->fetch();
            if (!$hdrLocCheck) {
                $this->conn->exec("ALTER TABLE `transaction_headers` ADD COLUMN `location_id` VARCHAR(36) NULL AFTER `approved_by`");
            }

            // Auto-assign default location to unassigned legacy transactions
            $defLocRow = $this->conn->query("SELECT id FROM `locations` WHERE `is_default` = 1 AND `is_deleted` = 0 LIMIT 1")->fetch();
            $defaultLocId = $defLocRow['id'] ?? 'loc-main-retail';
            $this->conn->exec("UPDATE `transaction_headers` SET `location_id` = '{$defaultLocId}' WHERE `location_id` IS NULL OR `location_id` = ''");
        } catch (Exception $e) {
            error_log("initLocationMaster error: " . $e->getMessage());
        }
    }

    public function initPaymentStatusMaster()
    {
        try {
            // Seed default payment_status entries if none exist
            $existing = $this->conn->query("SELECT COUNT(*) as cnt FROM reference_codes WHERE type = 'payment_status'")->fetch();
            if ((int)($existing['cnt'] ?? 0) === 0) {
                $defaults = [
                    ['Unpaid',   'unpaid',   0, '#e74c3c'],
                    ['Partial',  'partial',  0, '#f39c12'],
                    ['Paid',     'paid',     0, '#27ae60'],
                    ['Overdue',  'overdue',  0, '#c0392b'],
                    ['Void',     'void',     0, '#7f8c8d'],
                ];
                $stmt = $this->conn->prepare(
                    "INSERT IGNORE INTO reference_codes (id, type, name, code, value, symbol, description, is_active)
                     VALUES (UUID(), 'payment_status', ?, ?, ?, ?, '', 1)"
                );
                foreach ($defaults as $d) {
                    $stmt->execute([$d[0], $d[1], $d[2], $d[3]]);
                }
            }
        } catch (Exception $e) {
            error_log("initPaymentStatusMaster error: " . $e->getMessage());
        }
    }

    public static function getInstance()
    {
        if (!self::$instance) {
            self::$instance = new DBConnection();
            self::$instance->bootSchema();
        }
        return self::$instance;
    }

    public function getConnection()
    {
        return $this->conn;
    }

    public function fetchOne($sql, $params = [])
    {
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch();
    }

    public function fetchAll($sql, $params = [])
    {
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function execute($sql, $params = [])
    {
        $stmt = $this->conn->prepare($sql);
        return $stmt->execute($params);
    }

    public function lastInsertId()
    {
        return $this->conn->lastInsertId();
    }

    public function insert($table, $data)
    {
        $keys = array_keys($data);
        $fields = implode(", ", $keys);
        $placeholders = implode(", ", array_fill(0, count($keys), "?"));
        $sql = "INSERT INTO {$table} ({$fields}) VALUES ({$placeholders})";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute(array_values($data));
        return $this->conn->lastInsertId();
    }

    public function update($table, $data, $where, $whereParams = [])
    {
        $set = "";
        foreach ($data as $key => $value) {
            $set .= "{$key} = :{$key}, ";
        }
        $set = rtrim($set, ", ");
        $sql = "UPDATE {$table} SET {$set} WHERE {$where}";
        $params = array_merge($data, $whereParams);
        return $this->execute($sql, $params);
    }
}

function db()
{
    return DBConnection::getInstance();
}

function get_accounting_status_list(): array
{
    return [];
}

function get_accounting_tax_list(): array
{
    static $tax_list = null;
    if ($tax_list === null) {
        try {
            $db = db();
            $tax_list = $db->fetchAll("SELECT id, name, code, value, is_active FROM reference_codes WHERE type IN ('tax', 'tax_code') AND is_active = 1 ORDER BY value ASC");
            if (empty($tax_list)) {
                $tax_list = [
                    ['id' => '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 'name' => 'Non-Taxable', 'code' => 'Zero', 'value' => 0.00, 'is_active' => 1],
                    ['id' => '9b1656e9-ec64-40ab-b7a8-da784752d6a3', 'name' => 'VAT 13%', 'code' => '13', 'value' => 13.00, 'is_active' => 1]
                ];
            }
        } catch (Exception $e) {
            $tax_list = [
                ['id' => '0ef7af3b-0c9d-41d3-b6f8-f7f3dfb2b9aa', 'name' => 'Non-Taxable', 'code' => 'Zero', 'value' => 0.00, 'is_active' => 1],
                ['id' => '9b1656e9-ec64-40ab-b7a8-da784752d6a3', 'name' => 'VAT 13%', 'code' => '13', 'value' => 13.00, 'is_active' => 1]
            ];
        }
    }
    return $tax_list;
}

function get_active_locations(): array
{
    static $loc_list = null;
    if ($loc_list === null) {
        try {
            $db = db();
            $loc_list = $db->fetchAll("SELECT id, name, type, is_default FROM locations WHERE is_active = 1 AND is_deleted = 0 ORDER BY is_default DESC, name ASC");
        } catch (Exception $e) {
            $loc_list = [];
        }
    }
    return $loc_list;
}

function get_payment_status_list(): array
{
    static $ps_list = null;
    if ($ps_list === null) {
        try {
            $db = db();
            $ps_list = $db->fetchAll("SELECT id, name, code, symbol as color, is_active FROM reference_codes WHERE type = 'payment_status' AND is_active = 1 ORDER BY name ASC");
            if (empty($ps_list)) {
                $ps_list = [
                    ['id' => 'ps-1', 'name' => 'Unpaid',  'code' => 'unpaid',  'color' => '#e74c3c'],
                    ['id' => 'ps-2', 'name' => 'Partial', 'code' => 'partial', 'color' => '#f39c12'],
                    ['id' => 'ps-3', 'name' => 'Paid',    'code' => 'paid',    'color' => '#27ae60'],
                    ['id' => 'ps-4', 'name' => 'Overdue', 'code' => 'overdue', 'color' => '#c0392b'],
                    ['id' => 'ps-5', 'name' => 'Void',    'code' => 'void',    'color' => '#7f8c8d'],
                ];
            }
        } catch (Exception $e) {
            $ps_list = [
                ['id' => 'ps-1', 'name' => 'Unpaid',  'code' => 'unpaid',  'color' => '#e74c3c'],
                ['id' => 'ps-2', 'name' => 'Partial', 'code' => 'partial', 'color' => '#f39c12'],
                ['id' => 'ps-3', 'name' => 'Paid',    'code' => 'paid',    'color' => '#27ae60'],
            ];
        }
    }
    return $ps_list;
}
?>