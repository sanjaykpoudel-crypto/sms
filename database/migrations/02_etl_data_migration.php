<?php
/**
 * 02_etl_data_migration.php
 * Automated ETL Data Migration Script for MNS Liquor ERP.
 * Safely extracts all legacy master data, settings, transactions, payments, GL entries, and stock movements,
 * transforms them according to the new normalized schema, and populates mapping tables (`map_*`) and `migration_audit_log`.
 */

$host = 'localhost';
$user = 'root';
$pass = '';
$db   = 'sms_db';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    echo "====================================================\n";
    echo "  STARTING COMPLETE ERP DATA MIGRATION (ETL)\n";
    echo "====================================================\n\n";

    // Disable foreign key checks during ETL import
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");

    // Clear previous migration data if re-running
    $cleanTables = [
        'map_accounts', 'map_customers', 'map_vendors', 'map_items', 'map_locations', 'map_transactions', 'migration_audit_log',
        'erp_account_types', 'erp_accounts', 'erp_customers', 'erp_vendors', 'erp_categories', 'erp_items', 'erp_locations',
        'erp_item_locations', 'erp_tax_codes', 'erp_currencies', 'erp_transaction_types', 'erp_payment_methods', 'erp_fiscal_periods',
        'erp_accounting_preferences', 'erp_item_accounting', 'erp_category_accounting', 'erp_location_accounting', 'erp_transactions',
        'erp_transaction_lines', 'erp_gl_transactions', 'erp_gl_lines', 'erp_payments', 'erp_payment_lines', 'erp_payment_applications',
        'erp_credit_applications', 'erp_customer_transactions', 'erp_customer_balances', 'erp_vendor_transactions', 'erp_vendor_balances',
        'erp_inventory_transactions', 'erp_inventory_balances', 'erp_account_balances', 'erp_daily_sales_summary'
    ];
    foreach ($cleanTables as $tbl) {
        $pdo->exec("TRUNCATE TABLE `$tbl`;");
    }

    // Helper logging function
    function logMigration($pdo, $step, $srcCount, $migratedCount, $status = 'SUCCESS', $msg = '') {
        $stmt = $pdo->prepare("INSERT INTO migration_audit_log (step_name, source_count, migrated_count, status, log_message) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$step, $srcCount, $migratedCount, $status, $msg]);
        echo sprintf("[MIGRATION %s] %-30s | Source: %-6d | Migrated: %-6d | %s\n", $status, $step, $srcCount, $migratedCount, $msg);
    }

    // ----------------------------------------------------
    // STEP 1: ACCOUNT TYPES
    // ----------------------------------------------------
    $legacyAccTypes = $pdo->query("SELECT * FROM accounttypemaster")->fetchAll();
    $accTypeMap = [];
    $stmtAccType = $pdo->prepare("INSERT INTO erp_account_types (name, category, normal_balance, description, is_system, is_active, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)");
    foreach ($legacyAccTypes as $at) {
        $stmtAccType->execute([
            $at['AccountTypeName'],
            $at['Category'],
            $at['NormalBalance'],
            $at['Description'] ?? '',
            $at['IsSystem'] ?? 1,
            $at['IsActive'] ?? 1,
            $at['SortOrder'] ?? 0
        ]);
        $newId = $pdo->lastInsertId();
        $accTypeMap[$at['AccountTypeId']] = $newId;
    }
    logMigration($pdo, 'Account Types', count($legacyAccTypes), count($accTypeMap));

    // ----------------------------------------------------
    // STEP 2: CHART OF ACCOUNTS (COA)
    // ----------------------------------------------------
    $legacyAccounts = $pdo->query("SELECT * FROM accounts")->fetchAll();
    $stmtAcc = $pdo->prepare("INSERT INTO erp_accounts (account_code, account_name, account_type_id, parent_account_id, normal_balance, currency, opening_balance, current_balance, is_active, is_deleted) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmtMapAcc = $pdo->prepare("INSERT INTO map_accounts (old_id, new_id) VALUES (?, ?)");

    $accMigrated = 0;
    foreach ($legacyAccounts as $acc) {
        $oldId = $acc['id'];
        $code = 'ACC-' . str_pad($oldId, 4, '0', STR_PAD_LEFT);
        $typeId = $accTypeMap[$acc['account_type_id']] ?? 1;

        $stmtAcc->execute([
            $code,
            $acc['account_name'],
            $typeId,
            $acc['parent_account_id'] ?: null,
            strcasecmp($acc['normal_balance'], 'debit') === 0 ? 'Debit' : 'Credit',
            $acc['currency'] ?? 'NPR',
            $acc['opening_balance'] ?? 0.0000,
            $acc['opening_balance'] ?? 0.0000,
            $acc['is_active'] ?? 1,
            $acc['is_deleted'] ?? 0
        ]);
        $newAccId = $pdo->lastInsertId();
        $accMigrated++;

        // Map numeric old ID
        $stmtMapAcc->execute([$oldId, $newAccId]);
        // Map string old ID format (e.g. 'acc-1100', 'acc-1010') if applicable
        $stmtMapAcc->execute(['acc-' . $oldId, $newAccId]);
    }
    logMigration($pdo, 'Accounts (COA)', count($legacyAccounts), $accMigrated);

    // ----------------------------------------------------
    // STEP 3: LOCATIONS
    // ----------------------------------------------------
    $legacyLocations = $pdo->query("SELECT * FROM locations")->fetchAll();
    $stmtLoc = $pdo->prepare("INSERT INTO erp_locations (id, location_code, name, address, phone, is_main, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmtMapLoc = $pdo->prepare("INSERT INTO map_locations (old_id, new_id) VALUES (?, ?)");
    $locCount = 0;
    foreach ($legacyLocations as $loc) {
        $code = 'LOC-' . str_pad($loc['id'], 3, '0', STR_PAD_LEFT);
        $stmtLoc->execute([
            $loc['id'],
            $code,
            $loc['name'],
            $loc['address'] ?? '',
            $loc['phone'] ?? '',
            $loc['is_main'] ?? ($loc['id'] == 1 ? 1 : 0),
            $loc['is_active'] ?? 1
        ]);
        $stmtMapLoc->execute([$loc['id'], $loc['id']]);
        $locCount++;
    }
    logMigration($pdo, 'Locations', count($legacyLocations), $locCount);

    // ----------------------------------------------------
    // STEP 4: CUSTOMERS
    // ----------------------------------------------------
    $legacyCustomers = $pdo->query("SELECT * FROM customers")->fetchAll();
    $stmtCust = $pdo->prepare("INSERT INTO erp_customers (customer_code, name, company_name, pan_vat_no, phone, email, address, receivable_account_id, credit_limit, opening_balance, current_balance, is_active, is_deleted) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmtMapCust = $pdo->prepare("INSERT INTO map_customers (old_id, new_id) VALUES (?, ?)");
    $custCount = 0;

    // Fetch default AR account id from mapping (Account ID 6 = Accounts Receivable)
    $defaultArAcc = $pdo->query("SELECT new_id FROM map_accounts WHERE old_id = '6' LIMIT 1")->fetchColumn() ?: 1;

    foreach ($legacyCustomers as $c) {
        $code = !empty($c['customer_code']) ? $c['customer_code'] : ('CUST-' . str_pad($c['id'], 4, '0', STR_PAD_LEFT));
        $name = !empty($c['full_name']) ? $c['full_name'] : ($c['company_name'] ?? 'Valued Customer');
        
        $stmtCust->execute([
            $code,
            $name,
            $c['company_name'] ?? '',
            $c['pan_number'] ?? '',
            $c['phone'] ?? '',
            $c['email'] ?? '',
            $c['address'] ?? '',
            $defaultArAcc,
            $c['credit_limit'] ?? 0.0000,
            0.0000,
            0.0000,
            $c['is_active'] ?? 1,
            $c['is_deleted'] ?? 0
        ]);
        $newCustId = $pdo->lastInsertId();
        $stmtMapCust->execute([$c['id'], $newCustId]);
        $custCount++;
    }
    logMigration($pdo, 'Customers', count($legacyCustomers), $custCount);

    // ----------------------------------------------------
    // STEP 5: VENDORS
    // ----------------------------------------------------
    $legacyVendors = $pdo->query("SELECT * FROM vendors")->fetchAll();
    $stmtVend = $pdo->prepare("INSERT INTO erp_vendors (vendor_code, name, company_name, pan_vat_no, phone, email, address, payable_account_id, opening_balance, current_balance, is_active, is_deleted) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmtMapVend = $pdo->prepare("INSERT INTO map_vendors (old_id, new_id) VALUES (?, ?)");
    $vendCount = 0;

    // Fetch default AP account id from mapping (Account ID 12 = Accounts Payable)
    $defaultApAcc = $pdo->query("SELECT new_id FROM map_accounts WHERE old_id = '12' LIMIT 1")->fetchColumn() ?: 1;

    foreach ($legacyVendors as $v) {
        $code = !empty($v['vendor_code']) ? $v['vendor_code'] : ('VEND-' . str_pad($v['id'], 4, '0', STR_PAD_LEFT));
        $name = !empty($v['company_name']) ? $v['company_name'] : ($v['contact_name'] ?? 'Supplier Vendor');

        $stmtVend->execute([
            $code,
            $name,
            $v['company_name'] ?? '',
            $v['pan_number'] ?? ($v['vat_number'] ?? ''),
            $v['phone'] ?? '',
            $v['email'] ?? '',
            $v['address'] ?? '',
            $defaultApAcc,
            0.0000,
            0.0000,
            $v['is_active'] ?? 1,
            $v['is_deleted'] ?? 0
        ]);
        $newVendId = $pdo->lastInsertId();
        $stmtMapVend->execute([$v['id'], $newVendId]);
        $vendCount++;
    }
    logMigration($pdo, 'Vendors', count($legacyVendors), $vendCount);

    // ----------------------------------------------------
    // STEP 6: CATEGORIES & ITEMS
    // ----------------------------------------------------
    $rawCategories = $pdo->query("SELECT DISTINCT item_category FROM items WHERE item_category IS NOT NULL AND item_category != ''")->fetchAll(PDO::FETCH_COLUMN);
    $catMap = [];
    $stmtCat = $pdo->prepare("INSERT INTO erp_categories (name) VALUES (?)");
    foreach ($rawCategories as $catName) {
        $stmtCat->execute([$catName]);
        $catMap[$catName] = $pdo->lastInsertId();
    }

    $legacyItems = $pdo->query("SELECT * FROM items")->fetchAll();
    $stmtItem = $pdo->prepare("INSERT INTO erp_items (item_code, barcode, name, category_id, unit, cost_price, selling_price, mrp, min_stock, max_stock, reorder_level, is_active, is_deleted) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmtMapItem = $pdo->prepare("INSERT INTO map_items (old_id, new_id) VALUES (?, ?)");
    $stmtItemLoc = $pdo->prepare("INSERT INTO erp_item_locations (item_id, location_id, cost_price, selling_price, mrp, stock_quantity, reorder_level) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmtItemAcc = $pdo->prepare("INSERT INTO erp_item_accounting (item_id, income_account_id, cogs_account_id, inventory_asset_account_id) VALUES (?, ?, ?, ?)");

    $itemCount = 0;
    foreach ($legacyItems as $itm) {
        $code = !empty($itm['sku']) ? $itm['sku'] : ('ITEM-' . str_pad($itm['id'], 5, '0', STR_PAD_LEFT));
        $catId = isset($itm['item_category']) && isset($catMap[$itm['item_category']]) ? $catMap[$itm['item_category']] : null;
        $name = !empty($itm['item_name']) ? $itm['item_name'] : 'Unnamed Item';
        $barcode = !empty($itm['barcode']) ? trim($itm['barcode']) : null;

        $stmtItem->execute([
            $code,
            $barcode,
            $name,
            $catId,
            $itm['unit_type'] ?? 'Pcs',
            $itm['cost_price'] ?? 0.0000,
            $itm['selling_price'] ?? 0.0000,
            $itm['mrp'] ?? 0.0000,
            0.0000,
            0.0000,
            $itm['reorder_level'] ?? 0.0000,
            $itm['is_active'] ?? 1,
            $itm['is_deleted'] ?? 0
        ]);
        $newItemId = $pdo->lastInsertId();
        $stmtMapItem->execute([$itm['id'], $newItemId]);

        // Migrate Item Accounting overrides if present
        $incAcc = !empty($itm['income_account_id']) ? ($pdo->query("SELECT new_id FROM map_accounts WHERE old_id = '{$itm['income_account_id']}' LIMIT 1")->fetchColumn() ?: null) : null;
        $cogsAcc = !empty($itm['cogs_account_id']) ? ($pdo->query("SELECT new_id FROM map_accounts WHERE old_id = '{$itm['cogs_account_id']}' LIMIT 1")->fetchColumn() ?: null) : null;
        $invAcc = !empty($itm['inventory_account_id']) ? ($pdo->query("SELECT new_id FROM map_accounts WHERE old_id = '{$itm['inventory_account_id']}' LIMIT 1")->fetchColumn() ?: null) : null;

        if ($incAcc || $cogsAcc || $invAcc) {
            $stmtItemAcc->execute([$newItemId, $incAcc, $cogsAcc, $invAcc]);
        }

        // Setup Item Stock for Location 1 & Location 2
        $stmtItemLoc->execute([$newItemId, 1, $itm['cost_price'] ?? 0.0000, $itm['selling_price'] ?? 0.0000, $itm['mrp'] ?? 0.0000, 0.0000, $itm['reorder_level'] ?? 0.0000]);
        $stmtItemLoc->execute([$newItemId, 2, $itm['cost_price'] ?? 0.0000, $itm['selling_price'] ?? 0.0000, $itm['mrp'] ?? 0.0000, 0.0000, $itm['reorder_level'] ?? 0.0000]);

        $itemCount++;
    }
    logMigration($pdo, 'Items & Locations Stock', count($legacyItems), $itemCount);

    // ----------------------------------------------------
    // STEP 7: TAX CODES & PAYMENT METHODS & TRANSACTION TYPES
    // ----------------------------------------------------
    // Tax Code VAT 13%
    $vatAcc = $pdo->query("SELECT new_id FROM map_accounts WHERE old_id = '13' LIMIT 1")->fetchColumn() ?: 1;
    $pdo->exec("INSERT INTO erp_tax_codes (code, name, rate, input_tax_account_id, output_tax_account_id) VALUES ('VAT13', 'VAT 13%', 13.0000, $vatAcc, $vatAcc);");

    // Payment Methods
    $cashAcc = $pdo->query("SELECT new_id FROM map_accounts WHERE old_id = '2' LIMIT 1")->fetchColumn() ?: 1;
    $bankAcc = $pdo->query("SELECT new_id FROM map_accounts WHERE old_id = '3' LIMIT 1")->fetchColumn() ?: 1;
    $esewaAcc = $pdo->query("SELECT new_id FROM map_accounts WHERE old_id = '4' LIMIT 1")->fetchColumn() ?: 1;

    $payMethods = [
        ['code' => 'cash', 'name' => 'Cash Payment', 'account_id' => $cashAcc],
        ['code' => 'bank', 'name' => 'Bank / Cheque', 'account_id' => $bankAcc],
        ['code' => 'esewa', 'name' => 'eSewa Mobile Wallet', 'account_id' => $esewaAcc],
        ['code' => 'khalti', 'name' => 'Khalti Digital Wallet', 'account_id' => $esewaAcc],
        ['code' => 'credit', 'name' => 'Credit Account', 'account_id' => $defaultArAcc],
    ];
    $stmtPm = $pdo->prepare("INSERT INTO erp_payment_methods (code, name, account_id) VALUES (?, ?, ?)");
    foreach ($payMethods as $pm) {
        $stmtPm->execute([$pm['code'], $pm['name'], $pm['account_id']]);
    }

    // Transaction Types
    $txTypes = [
        ['code' => 'POS_SALE', 'name' => 'POS Retail Sale Invoice', 'prefix' => 'POS', 'module' => 'pos'],
        ['code' => 'SALES_INVOICE', 'name' => 'Sales Invoice', 'prefix' => 'INV', 'module' => 'sales'],
        ['code' => 'VENDOR_BILL', 'name' => 'Purchase Vendor Bill', 'prefix' => 'BILL', 'module' => 'purchase'],
        ['code' => 'EXPENSE', 'name' => 'Operating Expense', 'prefix' => 'EXP', 'module' => 'expense'],
        ['code' => 'JOURNAL', 'name' => 'General Journal Entry', 'prefix' => 'JV', 'module' => 'journal'],
        ['code' => 'CUST_PAYMENT', 'name' => 'Customer Payment Received', 'prefix' => 'CP', 'module' => 'payment'],
        ['code' => 'VEND_PAYMENT', 'name' => 'Vendor Payment Made', 'prefix' => 'VP', 'module' => 'payment'],
        ['code' => 'CREDIT_MEMO', 'name' => 'Customer Credit Memo', 'prefix' => 'CM', 'module' => 'credit'],
        ['code' => 'VENDOR_CREDIT', 'name' => 'Vendor Credit Note', 'prefix' => 'VC', 'module' => 'credit'],
        ['code' => 'STOCK_TRANSFER', 'name' => 'Stock Location Transfer', 'prefix' => 'ST', 'module' => 'inventory'],
        ['code' => 'STOCK_ADJUSTMENT', 'name' => 'Inventory Adjustment', 'prefix' => 'ADJ', 'module' => 'inventory'],
        ['code' => 'ACCOUNT_TRANSFER', 'name' => 'Fund Account Transfer', 'prefix' => 'TRF', 'module' => 'banking'],
    ];
    $stmtTt = $pdo->prepare("INSERT INTO erp_transaction_types (code, name, prefix, module) VALUES (?, ?, ?, ?)");
    $txTypeMap = [];
    foreach ($txTypes as $tt) {
        $stmtTt->execute([$tt['code'], $tt['name'], $tt['prefix'], $tt['module']]);
        $txTypeMap[$tt['code']] = $pdo->lastInsertId();
    }
    logMigration($pdo, 'Tax Codes & Payment Methods & Tx Types', 5, count($txTypes));

    // ----------------------------------------------------
    // STEP 8: ACCOUNTING PREFERENCES
    // ----------------------------------------------------
    $prefMap = [
        'default_ar_account'              => 6,
        'default_ap_account'              => 12,
        'default_sales_account'           => 25,
        'default_cogs_account'            => 26,
        'default_inventory_asset_account' => 7,
        'default_tax_account'             => 13,
        'default_discount_account'        => 36,
        'default_cash_account'            => 2,
        'default_bank_account'            => 3,
        'default_esewa_account'           => 4,
        'default_khalti_account'          => 4,
        'default_equity_account'          => 20,
        'default_retained_earnings_account' => 22,
        'default_expense_account'         => 37,
    ];

    $stmtPref = $pdo->prepare("INSERT INTO erp_accounting_preferences (preference_key, account_id, effective_from, is_active) VALUES (?, ?, '2000-01-01', 1)");
    $prefCount = 0;
    foreach ($prefMap as $key => $oldAccId) {
        $newAccId = $pdo->query("SELECT new_id FROM map_accounts WHERE old_id = '$oldAccId' LIMIT 1")->fetchColumn();
        if ($newAccId) {
            $stmtPref->execute([$key, $newAccId]);
            $prefCount++;
        }
    }
    logMigration($pdo, 'Accounting Preferences', count($prefMap), $prefCount);

    // ----------------------------------------------------
    // STEP 9: FISCAL PERIODS
    // ----------------------------------------------------
    $legacyFiscal = $pdo->query("SELECT * FROM fiscal_years")->fetchAll();
    $stmtFp = $pdo->prepare("INSERT INTO erp_fiscal_periods (fiscal_year, period_name, start_date, end_date, is_closed) VALUES (?, ?, ?, ?, ?)");
    foreach ($legacyFiscal as $fy) {
        $stmtFp->execute([
            $fy['fiscal_year_code'] ?? '2081/82',
            $fy['year_label'] ?? 'Full Year',
            $fy['start_date'] ?? '2024-07-16',
            $fy['end_date'] ?? '2025-07-15',
            $fy['is_closed'] ?? 0
        ]);
    }
    logMigration($pdo, 'Fiscal Periods', count($legacyFiscal), count($legacyFiscal));

    // ----------------------------------------------------
    // STEP 10: HISTORICAL TRANSACTIONS & POS SALES MIGRATION
    // ----------------------------------------------------
    $stmtTxHeader = $pdo->prepare("INSERT INTO erp_transactions 
        (transaction_no, transaction_type_id, transaction_date, posting_date, location_id, customer_id, vendor_id, subtotal, discount_amount, tax_amount, grand_total, paid_amount, due_amount, payment_status, status, memo, external_reference, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmtTxMap = $pdo->prepare("INSERT INTO map_transactions (old_table, old_id, new_transaction_id) VALUES (?, ?, ?)");

    // 10A. POS Entry Migration (315 sales)
    $legacyPos = $pdo->query("SELECT * FROM pos_entry")->fetchAll();
    $posTxTypeId = $txTypeMap['POS_SALE'];
    $posMigrated = 0;

    foreach ($legacyPos as $pos) {
        $custId = !empty($pos['customer_id']) ? ($pdo->query("SELECT new_id FROM map_customers WHERE old_id = '{$pos['customer_id']}' LIMIT 1")->fetchColumn() ?: null) : null;
        $locId = !empty($pos['location_id']) ? $pos['location_id'] : 1;
        $txNo = !empty($pos['invoice_no']) ? $pos['invoice_no'] : ('POS-' . $pos['id']);
        $txDate = !empty($pos['date_time']) ? date('Y-m-d', strtotime($pos['date_time'])) : date('Y-m-d');
        $grandTotal = $pos['net_amount'] ?? ($pos['gross_amount'] ?? 0.0000);
        $paidAmt = $grandTotal;
        $dueAmt = 0.0000;
        $payStatus = 'paid';

        $stmtTxHeader->execute([
            $txNo,
            $posTxTypeId,
            $txDate,
            $txDate,
            $locId,
            $custId,
            null,
            $pos['gross_amount'] ?? 0.0000,
            $pos['discount_amount'] ?? 0.0000,
            $pos['tax_amount'] ?? 0.0000,
            $grandTotal,
            $paidAmt,
            $dueAmt,
            $payStatus,
            'posted',
            'Migrated POS Retail Sale Invoice #' . $txNo,
            $txNo,
            $pos['created_at'] ?? date('Y-m-d H:i:s')
        ]);
        $newTxId = $pdo->lastInsertId();
        $stmtTxMap->execute(['pos_entry', $pos['id'], $newTxId]);
        $posMigrated++;

        // Migrate POS Line Items
        $posItems = $pdo->query("SELECT * FROM pos_items WHERE pos_id = '{$pos['id']}'")->fetchAll();
        $lineNo = 1;
        $stmtTxLine = $pdo->prepare("INSERT INTO erp_transaction_lines (transaction_id, line_no, item_id, account_id, customer_id, location_id, description, quantity, unit_price, gross_amount, discount_amount, tax_amount, net_amount) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        // Income account ID 25 = Sales
        $salesAccId = $pdo->query("SELECT new_id FROM map_accounts WHERE old_id = '25' LIMIT 1")->fetchColumn() ?: 1;

        foreach ($posItems as $pi) {
            $itemId = !empty($pi['item_id']) ? ($pdo->query("SELECT new_id FROM map_items WHERE old_id = '{$pi['item_id']}' LIMIT 1")->fetchColumn() ?: null) : null;
            $qty = $pi['quantity'] ?? 1;
            $rate = $pi['rate'] ?? 0.0000;
            $net = $pi['net_amount'] ?? ($qty * $rate);

            $stmtTxLine->execute([
                $newTxId, $lineNo++, $itemId, $salesAccId, $custId, $locId,
                'POS Line Item', $qty, $rate, $pi['amount'] ?? ($qty * $rate),
                $pi['discount'] ?? 0.0000, $pi['tax'] ?? 0.0000, $net
            ]);

            // Track Inventory Stock Out
            if ($itemId) {
                $costPrice = $pdo->query("SELECT cost_price FROM erp_items WHERE id = '$itemId'")->fetchColumn() ?: 0.0000;
                $stmtInv = $pdo->prepare("INSERT INTO erp_inventory_transactions (transaction_id, item_id, location_id, posting_date, movement_type, quantity, unit_cost, total_cost) VALUES (?, ?, ?, ?, 'sale', ?, ?, ?)");
                $stmtInv->execute([$newTxId, $itemId, $locId, $txDate, -$qty, $costPrice, -$qty * $costPrice]);
            }
        }
    }
    // Prepare GL Statements
    $stmtGlTx = $pdo->prepare("INSERT INTO erp_gl_transactions (gl_no, transaction_id, posting_date, memo, total_debit, total_credit, is_balanced) VALUES (?, ?, ?, ?, ?, ?, 1)");
    $stmtGlLine = $pdo->prepare("INSERT INTO erp_gl_lines (gl_transaction_id, transaction_id, line_no, account_id, debit, credit, customer_id, vendor_id, posting_date, memo) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    // 10B. Customer Invoices Migration (65 sales invoices)
    $legacyInvoices = $pdo->query("SELECT * FROM customer_invoices")->fetchAll();
    $invTxTypeId = $txTypeMap['SALES_INVOICE'];
    $invMigrated = 0;
    foreach ($legacyInvoices as $inv) {
        $custId = !empty($inv['customer_id']) ? ($pdo->query("SELECT new_id FROM map_customers WHERE old_id = '{$inv['customer_id']}' LIMIT 1")->fetchColumn() ?: null) : null;
        $locId = 1;
        $rawNo = !empty($inv['invoice_number']) ? $inv['invoice_number'] : ('INV-' . $inv['id']);
        $txNo = 'INV-' . $inv['id'] . '-' . $rawNo;
        $txDate = !empty($inv['invoice_date']) ? $inv['invoice_date'] : date('Y-m-d');
        $grandTotal = $inv['total_amount'] ?? 0.0000;
        $paidAmt = $inv['amount_paid'] ?? 0.0000;
        $dueAmt = $inv['balance_due'] ?? max(0, $grandTotal - $paidAmt);
        $payStatus = $inv['payment_status'] ?? (($dueAmt <= 0) ? 'paid' : (($paidAmt > 0) ? 'partial' : 'unpaid'));

        $stmtTxHeader->execute([
            $txNo,
            $invTxTypeId,
            $txDate,
            $txDate,
            $locId,
            $custId,
            null,
            $inv['subtotal'] ?? 0.0000,
            $inv['discount_amount'] ?? 0.0000,
            $inv['tax_amount'] ?? 0.0000,
            $grandTotal,
            $paidAmt,
            $dueAmt,
            $payStatus,
            'posted',
            'Migrated Sales Invoice #' . $rawNo,
            $rawNo,
            date('Y-m-d H:i:s')
        ]);
        $newTxId = $pdo->lastInsertId();
        $stmtTxMap->execute(['customer_invoices', $inv['id'], $newTxId]);
        if (!empty($inv['header_id'])) {
            $stmtTxMap->execute(['transaction_headers', $inv['header_id'], $newTxId]);
        }
        $invMigrated++;
    }
    logMigration($pdo, 'Customer Invoices', count($legacyInvoices), $invMigrated);

    // 10C. Vendor Bills Migration (19 purchase bills)
    $legacyBills = $pdo->query("SELECT * FROM vendor_bills")->fetchAll();
    $billTxTypeId = $txTypeMap['VENDOR_BILL'];
    $billMigrated = 0;
    foreach ($legacyBills as $bill) {
        $vendId = !empty($bill['vendor_id']) ? ($pdo->query("SELECT new_id FROM map_vendors WHERE old_id = '{$bill['vendor_id']}' LIMIT 1")->fetchColumn() ?: null) : null;
        $locId = 1;
        $rawNo = !empty($bill['vendor_invoice_number']) ? $bill['vendor_invoice_number'] : ('BILL-' . $bill['id']);
        $txNo = 'BILL-' . $bill['id'] . '-' . $rawNo;
        $txDate = !empty($bill['bill_date']) ? $bill['bill_date'] : date('Y-m-d');
        $grandTotal = $bill['total_amount'] ?? 0.0000;
        $paidAmt = $bill['amount_paid'] ?? 0.0000;
        $dueAmt = $bill['balance_due'] ?? max(0, $grandTotal - $paidAmt);
        $payStatus = $bill['payment_status'] ?? (($dueAmt <= 0) ? 'paid' : (($paidAmt > 0) ? 'partial' : 'unpaid'));

        $stmtTxHeader->execute([
            $txNo,
            $billTxTypeId,
            $txDate,
            $txDate,
            $locId,
            null,
            $vendId,
            $bill['subtotal'] ?? 0.0000,
            $bill['discount_amount'] ?? 0.0000,
            $bill['tax_amount'] ?? 0.0000,
            $grandTotal,
            $paidAmt,
            $dueAmt,
            $payStatus,
            'posted',
            'Migrated Vendor Purchase Bill #' . $rawNo,
            $rawNo,
            date('Y-m-d H:i:s')
        ]);
        $newTxId = $pdo->lastInsertId();
        $stmtTxMap->execute(['vendor_bills', $bill['id'], $newTxId]);
        if (!empty($bill['header_id'])) {
            $stmtTxMap->execute(['transaction_headers', $bill['header_id'], $newTxId]);
        }
        $billMigrated++;
    }
    logMigration($pdo, 'Vendor Bills', count($legacyBills), $billMigrated);

    // 10D. Expenses Migration (19 expenses)
    $legacyExpenses = $pdo->query("SELECT * FROM expenses")->fetchAll();
    $expTxTypeId = $txTypeMap['EXPENSE'];
    $expMigrated = 0;
    foreach ($legacyExpenses as $exp) {
        $locId = 1;
        $txNo = 'EXP-' . $exp['id'];
        $txDate = !empty($exp['expense_date']) ? $exp['expense_date'] : date('Y-m-d');
        $amt = $exp['amount'] ?? 0.0000;
        $vendId = !empty($exp['vendor_id']) ? ($pdo->query("SELECT new_id FROM map_vendors WHERE old_id = '{$exp['vendor_id']}' LIMIT 1")->fetchColumn() ?: null) : null;

        $stmtTxHeader->execute([
            $txNo,
            $expTxTypeId,
            $txDate,
            $txDate,
            $locId,
            null,
            $vendId,
            $amt,
            0.0000,
            $exp['tax_amount'] ?? 0.0000,
            $amt,
            $amt,
            0.0000,
            'paid',
            'posted',
            'Migrated Expense #' . $exp['id'],
            $txNo,
            date('Y-m-d H:i:s')
        ]);
        $newTxId = $pdo->lastInsertId();
        $stmtTxMap->execute(['expenses', $exp['id'], $newTxId]);
        if (!empty($exp['header_id'])) {
            $stmtTxMap->execute(['transaction_headers', $exp['header_id'], $newTxId]);
        }
        $expMigrated++;
    }
    logMigration($pdo, 'Operating Expenses', count($legacyExpenses), $expMigrated);

    // ----------------------------------------------------
    // STEP 11: HISTORICAL GENERAL LEDGER (journal_entries - 961 lines)
    // ----------------------------------------------------
    $legacyJournals = $pdo->query("SELECT * FROM journal_entries")->fetchAll();
    $stmtGlTx = $pdo->prepare("INSERT INTO erp_gl_transactions (gl_no, transaction_id, posting_date, memo, total_debit, total_credit, is_balanced) VALUES (?, ?, ?, ?, ?, ?, 1)");
    $stmtGlLine = $pdo->prepare("INSERT INTO erp_gl_lines (gl_transaction_id, transaction_id, line_no, account_id, debit, credit, customer_id, vendor_id, posting_date, memo) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    // Group journal entries by header_id
    $groupedJournals = [];
    foreach ($legacyJournals as $je) {
        $txHeaderId = $je['header_id'] ?? ($je['id'] ?? 0);
        $groupedJournals[$txHeaderId][] = $je;
    }

    $glMigrated = 0;
    foreach ($groupedJournals as $txHeaderId => $lines) {
        // Skip journal entries belonging to payments table (posted directly in Step 12)
        $isPaymentJournal = $pdo->query("SELECT id FROM payments WHERE header_id = '$txHeaderId' LIMIT 1")->fetchColumn();
        if ($isPaymentJournal) {
            continue;
        }

        // Map to corresponding new transaction from transaction_headers or pos_entry / customer_invoices / vendor_bills / expenses
        $newTxId = $pdo->query("SELECT new_transaction_id FROM map_transactions WHERE old_id = '$txHeaderId' LIMIT 1")->fetchColumn();
        if (!$newTxId) {
            // Create dummy transaction container for journal entries if any
            $txNo = 'JV-HIST-' . $txHeaderId;
            $txDate = $lines[0]['entry_date'] ?? date('Y-m-d');
            $stmtTxHeader->execute([
                $txNo, $txTypeMap['JOURNAL'], $txDate, $txDate, 1, null, null,
                0, 0, 0, 0, 0, 0, 'paid', 'posted', 'Migrated Journal Entry Container #' . $txHeaderId, $txNo, date('Y-m-d H:i:s')
            ]);
            $newTxId = $pdo->lastInsertId();
            $stmtTxMap->execute(['journal_entries_group', $txHeaderId, $newTxId]);
        }

        $totalDeb = 0.0;
        $totalCred = 0.0;
        foreach ($lines as $l) {
            if ($l['entry_type'] === 'debit') $totalDeb += (float)$l['amount'];
            else $totalCred += (float)$l['amount'];
        }

        $postDate = $lines[0]['entry_date'] ?? date('Y-m-d');
        $glNo = 'GL-MIG-' . $txHeaderId;

        $stmtGlTx->execute([$glNo, $newTxId, $postDate, $lines[0]['memo'] ?? 'Migrated Journal Entry', $totalDeb, $totalCred]);
        $glTxId = $pdo->lastInsertId();

        $lineNo = 1;
        foreach ($lines as $l) {
            $oldAccId = $l['account_id'];
            $newAccId = null;
            if ($oldAccId) {
                $newAccId = $pdo->query("SELECT new_id FROM map_accounts WHERE old_id = '$oldAccId' LIMIT 1")->fetchColumn();
            }

            // Check if journal entry header matches a customer invoice or vendor bill or payment
            $invParent = $pdo->query("SELECT customer_id FROM customer_invoices WHERE header_id = '$txHeaderId' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            $billParent = $pdo->query("SELECT vendor_id FROM vendor_bills WHERE header_id = '$txHeaderId' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            $payParent = $pdo->query("SELECT customer_id, vendor_id FROM payments WHERE header_id = '$txHeaderId' LIMIT 1")->fetch(PDO::FETCH_ASSOC);

            // Fallback account resolution for legacy entries with NULL account_id
            if (!$newAccId) {
                $memo = $l['memo'] ?? '';
                if ($invParent) {
                    $newAccId = ($l['entry_type'] === 'debit') ? 6 : 25; // AR for debit, Sales for credit
                } elseif ($billParent) {
                    $newAccId = ($l['entry_type'] === 'credit') ? 12 : 7; // AP for credit, Inventory for debit
                } elseif (stripos($memo, 'Invoice') !== false && $l['entry_type'] === 'debit') {
                    $newAccId = 6; // AR
                } elseif (stripos($memo, 'Sales') !== false && $l['entry_type'] === 'credit') {
                    $newAccId = 25; // Sales
                } elseif (stripos($memo, 'COGS') !== false) {
                    $newAccId = 26; // COGS
                } elseif (stripos($memo, 'Inventory') !== false) {
                    $newAccId = 7; // Inventory
                } elseif (stripos($memo, 'Payment') !== false && $l['entry_type'] === 'debit') {
                    $newAccId = 2; // Cash
                } elseif (stripos($memo, 'Payment') !== false && $l['entry_type'] === 'credit') {
                    $newAccId = 6; // AR
                } else {
                    $newAccId = 37; // Misc Expense
                }
            }

            if ($newAccId) {
                $deb = ($l['entry_type'] === 'debit') ? $l['amount'] : 0.0000;
                $cred = ($l['entry_type'] === 'credit') ? $l['amount'] : 0.0000;

                $custId = (!empty($l['party_type']) && $l['party_type'] === 'customer' && !empty($l['party_id'])) 
                    ? ($pdo->query("SELECT new_id FROM map_customers WHERE old_id = '{$l['party_id']}' LIMIT 1")->fetchColumn() ?: null) 
                    : null;

                $vendId = (!empty($l['party_type']) && $l['party_type'] === 'vendor' && !empty($l['party_id'])) 
                    ? ($pdo->query("SELECT new_id FROM map_vendors WHERE old_id = '{$l['party_id']}' LIMIT 1")->fetchColumn() ?: null) 
                    : null;

                // Fallback customer_id / vendor_id resolution from parent transaction, invoice, bill, or payment
                if (!$custId && $invParent && !empty($invParent['customer_id'])) {
                    $custId = $pdo->query("SELECT new_id FROM map_customers WHERE old_id = '{$invParent['customer_id']}' LIMIT 1")->fetchColumn() ?: null;
                }
                if (!$vendId && $billParent && !empty($billParent['vendor_id'])) {
                    $vendId = $pdo->query("SELECT new_id FROM map_vendors WHERE old_id = '{$billParent['vendor_id']}' LIMIT 1")->fetchColumn() ?: null;
                }
                if (!$custId && $payParent && !empty($payParent['customer_id'])) {
                    $custId = $pdo->query("SELECT new_id FROM map_customers WHERE old_id = '{$payParent['customer_id']}' LIMIT 1")->fetchColumn() ?: null;
                }
                if (!$vendId && $payParent && !empty($payParent['vendor_id'])) {
                    $vendId = $pdo->query("SELECT new_id FROM map_vendors WHERE old_id = '{$payParent['vendor_id']}' LIMIT 1")->fetchColumn() ?: null;
                }
                if (!$custId && !$vendId && $newTxId) {
                    $txHeaderInfo = $pdo->query("SELECT customer_id, vendor_id FROM erp_transactions WHERE id = '$newTxId'")->fetch(PDO::FETCH_ASSOC);
                    if ($txHeaderInfo) {
                        if (!empty($txHeaderInfo['customer_id'])) {
                            $custId = $txHeaderInfo['customer_id'];
                        }
                        if (!empty($txHeaderInfo['vendor_id'])) {
                            $vendId = $txHeaderInfo['vendor_id'];
                        }
                    }
                }

                $stmtGlLine->execute([
                    $glTxId,
                    $newTxId,
                    $lineNo++,
                    $newAccId,
                    $deb,
                    $cred,
                    $custId,
                    $vendId,
                    $postDate,
                    $l['memo'] ?? ''
                ]);
                $glMigrated++;
            }
        }
    }
    logMigration($pdo, 'General Ledger Lines (GL)', count($legacyJournals), $glMigrated);

    // ----------------------------------------------------
    // STEP 12: PAYMENTS MIGRATION (108 payments)
    // ----------------------------------------------------
    $legacyPayments = $pdo->query("SELECT * FROM payments")->fetchAll();
    $stmtPayHeader = $pdo->prepare("INSERT INTO erp_payments (payment_no, transaction_id, payment_type, party_type, party_id, payment_date, posting_date, amount, unapplied_amount, reference_no, memo) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmtPayLine = $pdo->prepare("INSERT INTO erp_payment_lines (payment_id, payment_method_id, account_id, amount, reference_no) VALUES (?, ?, ?, ?, ?)");

    $payMigrated = 0;
    foreach ($legacyPayments as $p) {
        $pNo = !empty($p['transaction_reference']) ? $p['transaction_reference'] : ('PAY-' . $p['id']);
        $pDate = !empty($p['payment_date']) ? $p['payment_date'] : date('Y-m-d');
        $amt = $p['amount'] ?? 0.0000;
        $partyType = !empty($p['customer_id']) ? 'customer' : (!empty($p['vendor_id']) ? 'vendor' : null);
        $partyId = !empty($p['customer_id']) ? ($pdo->query("SELECT new_id FROM map_customers WHERE old_id = '{$p['customer_id']}' LIMIT 1")->fetchColumn() ?: null) : (!empty($p['vendor_id']) ? ($pdo->query("SELECT new_id FROM map_vendors WHERE old_id = '{$p['vendor_id']}' LIMIT 1")->fetchColumn() ?: null) : null);
        $pType = ($partyType === 'customer') ? 'customer_payment' : 'vendor_payment';

        $stmtPayHeader->execute([
            $pNo, null, $pType, $partyType, $partyId, $pDate, $pDate, $amt, 0.0000, $p['transaction_reference'] ?? ($p['cheque_number'] ?? ''), 'Migrated Payment #' . $pNo
        ]);
        $newPayId = $pdo->lastInsertId();

        // Payment Method Account
        $pmCode = strtolower($p['payment_method'] ?? 'cash');
        if (strpos($pmCode, 'bank') !== false || strpos($pmCode, 'cheque') !== false) $pmCode = 'bank';
        elseif (strpos($pmCode, 'esewa') !== false) $pmCode = 'esewa';
        elseif (strpos($pmCode, 'khalti') !== false) $pmCode = 'khalti';
        else $pmCode = 'cash';

        $pmRow = $pdo->query("SELECT id, account_id FROM erp_payment_methods WHERE code = '$pmCode' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $pmId = $pmRow['id'] ?? 1;
        $pmAccId = $pmRow['account_id'] ?? $cashAcc;

        $stmtPayLine->execute([$newPayId, $pmId, $pmAccId, $amt, $p['transaction_reference'] ?? '']);
        $payMigrated++;

        // Post double-entry GL lines for payment
        $glTxNo = 'GL-' . $pNo;
        $glPostDate = $pDate;
        
        if ($pType === 'customer_payment') {
            $arAcc = $pdo->query("SELECT new_id FROM map_accounts WHERE old_id = '6' LIMIT 1")->fetchColumn() ?: 1;
            
            // Create container transaction header if needed
            $stmtTxHeader->execute([
                $pNo, $txTypeMap['CUST_PAYMENT'], $glPostDate, $glPostDate, 1, $partyId, null,
                $amt, 0, 0, $amt, $amt, 0, 'paid', 'posted', 'Customer Payment Received #' . $pNo, $pNo, date('Y-m-d H:i:s')
            ]);
            $payTxId = $pdo->lastInsertId();

            $stmtGlTx->execute([$glTxNo, $payTxId, $glPostDate, 'Customer Payment Received #' . $pNo, $amt, $amt]);
            $glTxId = $pdo->lastInsertId();

            // Dr Cash/Bank
            $stmtGlLine->execute([$glTxId, $payTxId, 1, $pmAccId, $amt, 0.0000, $partyId, null, $glPostDate, 'Customer Payment #' . $pNo]);
            // Cr Accounts Receivable
            $stmtGlLine->execute([$glTxId, $payTxId, 2, $arAcc, 0.0000, $amt, $partyId, null, $glPostDate, 'Customer Payment #' . $pNo]);

            // Update Customer Balance
            if ($partyId) {
                $pdo->exec("UPDATE erp_customers SET current_balance = current_balance - $amt WHERE id = $partyId;");
            }
        } elseif ($pType === 'vendor_payment') {
            $apAcc = $pdo->query("SELECT new_id FROM map_accounts WHERE old_id = '12' LIMIT 1")->fetchColumn() ?: 1;

            // Create container transaction header if needed
            $stmtTxHeader->execute([
                $pNo, $txTypeMap['VEND_PAYMENT'], $glPostDate, $glPostDate, 1, null, $partyId,
                $amt, 0, 0, $amt, $amt, 0, 'paid', 'posted', 'Vendor Payment Made #' . $pNo, $pNo, date('Y-m-d H:i:s')
            ]);
            $payTxId = $pdo->lastInsertId();

            $stmtGlTx->execute([$glTxNo, $payTxId, $glPostDate, 'Vendor Payment Made #' . $pNo, $amt, $amt]);
            $glTxId = $pdo->lastInsertId();

            // Dr Accounts Payable
            $stmtGlLine->execute([$glTxId, $payTxId, 1, $apAcc, $amt, 0.0000, null, $partyId, $glPostDate, 'Vendor Payment #' . $pNo]);
            // Cr Cash/Bank
            $stmtGlLine->execute([$glTxId, $payTxId, 2, $pmAccId, 0.0000, $amt, null, $partyId, $glPostDate, 'Vendor Payment #' . $pNo]);

            // Update Vendor Balance
            if ($partyId) {
                $pdo->exec("UPDATE erp_vendors SET current_balance = current_balance - $amt WHERE id = $partyId;");
            }
        }
    }
    logMigration($pdo, 'Payments & Payment Allocations', count($legacyPayments), $payMigrated);

    // ----------------------------------------------------
    // STEP 13: REBUILD INVENTORY BALANCES CACHE
    // ----------------------------------------------------
    $pdo->exec("INSERT INTO erp_inventory_balances (item_id, location_id, quantity, avg_unit_cost, total_value)
        SELECT 
            item_id,
            location_id,
            SUM(quantity) as quantity,
            IF(SUM(quantity) != 0, ABS(SUM(total_cost) / SUM(quantity)), 0.0000) as avg_unit_cost,
            SUM(total_cost) as total_value
        FROM erp_inventory_transactions
        GROUP BY item_id, location_id
        ON DUPLICATE KEY UPDATE 
            quantity = VALUES(quantity),
            avg_unit_cost = VALUES(avg_unit_cost),
            total_value = VALUES(total_value);");

    // Enable Foreign Key checks
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");

    echo "\n====================================================\n";
    echo "  ETL DATA MIGRATION COMPLETED SUCCESSFULLY!\n";
    echo "====================================================\n";

} catch (Exception $e) {
    echo "\nETL_MIGRATION_ERROR: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
    exit(1);
}
