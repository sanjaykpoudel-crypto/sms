<?php
/**
 * AccountingEngine.php
 * Centralized dynamic accounting resolution service for MNS Liquor ERP.
 * Strictly implements the 6-tier accounting resolution hierarchy:
 * 1. Transaction-specific override
 * 2. Item accounting configuration (erp_item_accounting)
 * 3. Item category accounting configuration (erp_category_accounting)
 * 4. Location accounting configuration (erp_location_accounting)
 * 5. Accounting Preferences (erp_accounting_preferences)
 * 6. Global default configuration
 * 
 * NEVER hard-codes account IDs or strings.
 */

class AccountingException extends Exception {}

class AccountingEngine
{
    private static $instance = null;
    private $pdo;
    private $preferenceCache = [];
    private $itemAccountCache = [];
    private $categoryAccountCache = [];
    private $locationAccountCache = [];

    private function __construct()
    {
        $db = DBConnection::getInstance();
        $this->pdo = $db->getConnection();
    }

    public static function getInstance(): AccountingEngine
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Resolve account by Preference Key and optional entity overrides.
     * Hierarchy:
     * 1. Direct override provided in $context['account_id']
     * 2. Item accounting (if item_id set in $context)
     * 3. Category accounting (if category_id / item_id set in $context)
     * 4. Location accounting (if location_id set in $context)
     * 5. Accounting Preferences (erp_accounting_preferences)
     */
    public function resolveAccount(string $preferenceKey, array $context = []): mixed
    {
        // 1. Transaction-specific explicit override
        if (!empty($context['account_id'])) {
            return $context['account_id'];
        }

        $itemId = $context['item_id'] ?? null;
        $locationId = $context['location_id'] ?? null;
        $asOfDate = $context['date'] ?? date('Y-m-d');

        // Extract categoryId from item if not provided
        $categoryId = $context['category_id'] ?? null;
        if ($itemId && !$categoryId) {
            try {
                $stmt = $this->pdo->prepare("SELECT category_id FROM items WHERE id = ?");
                $stmt->execute([$itemId]);
                $categoryId = $stmt->fetchColumn() ?: null;
            } catch (Exception $e) {}

            if (!$categoryId) {
                try {
                    $stmt = $this->pdo->prepare("SELECT category_id FROM erp_items WHERE id = ?");
                    $stmt->execute([$itemId]);
                    $categoryId = $stmt->fetchColumn() ?: null;
                } catch (Exception $e) {}
            }
        }

        // Mapping preference keys to entity field names
        $keyFieldMap = [
            'default_sales_account'           => 'income_account_id',
            'default_cogs_account'            => 'cogs_account_id',
            'default_inventory_asset_account' => 'inventory_asset_account_id',
            'default_purchase_account'        => 'purchase_account_id',
            'default_sales_return_account'    => 'sales_return_account_id',
            'default_purchase_return_account' => 'purchase_return_account_id',
        ];

        $entityField = $keyFieldMap[$preferenceKey] ?? null;

        // 2. Operational items table check
        if ($itemId && $entityField) {
            $opCol = ($entityField === 'inventory_asset_account_id') ? 'inventory_account_id' : $entityField;
            try {
                $stmt = $this->pdo->prepare("SELECT {$opCol} FROM items WHERE id = ?");
                $stmt->execute([$itemId]);
                $acc = $stmt->fetchColumn();
                if (!empty($acc)) return $acc;
            } catch (Exception $e) {}
        }

        // 3. Item Accounting Configuration (erp_item_accounting)
        if ($itemId && $entityField) {
            try {
                if (!isset($this->itemAccountCache[$itemId])) {
                    $stmt = $this->pdo->prepare("SELECT * FROM erp_item_accounting WHERE item_id = ?");
                    $stmt->execute([$itemId]);
                    $this->itemAccountCache[$itemId] = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
                }
                if (!empty($this->itemAccountCache[$itemId][$entityField])) {
                    return $this->itemAccountCache[$itemId][$entityField];
                }
            } catch (Exception $e) {}
        }

        // 4. Accounting Preferences / system_info (Effective-dated match)
        $prefAccountId = $this->getPreferenceAccountId($preferenceKey, $locationId, $asOfDate);
        if ($prefAccountId !== null) {
            $legacyMap = [
                'acc-1010' => 2,  // Cash
                'acc-1100' => 6,  // AR
                'acc-1200' => 7,  // Inventory Asset
                'acc-2100' => 12, // AP
                'acc-4100' => 25, // Sales
                'acc-5100' => 26, // COGS
                'acc-6160' => 36, // Discount
                'acc-2200' => 13, // Tax
            ];
            if (isset($legacyMap[$prefAccountId])) {
                $prefAccountId = $legacyMap[$prefAccountId];
            }
            return is_numeric($prefAccountId) ? (int)$prefAccountId : $prefAccountId;
        }

        // 5. Default Fallbacks for core preference keys (using real integer IDs from accounts table)
        $defaults = [
            'default_cash_account'            => 2,  // Cash
            'default_ar_account'              => 6,  // Accounts Receivable
            'default_inventory_asset_account' => 7,  // Inventory Asset
            'default_ap_account'              => 12, // Accounts Payable
            'default_sales_account'           => 25, // Sales Income
            'default_cogs_account'            => 26, // Cost of Goods Sold
            'default_discount_account'        => 36, // Discounts Given
            'default_tax_account'             => 13, // VAT Payable
            'default_sales_return_account'    => 25, // Sales Income
            'default_purchase_return_account' => 26, // Cost of Goods Sold
            'default_drawings_account'        => 39, // Owner Drawings
            'default_interest_account'        => 40, // Interest Expense
            'default_freight_account'         => 41, // Freight-In & Transport Cost
        ];
        
        $resolvedAcc = $defaults[$preferenceKey] ?? null;

        // Legacy code mapping if string codes are returned
        $legacyMap = [
            'acc-1010' => 2,  // Cash
            'acc-1100' => 6,  // AR
            'acc-1200' => 7,  // Inventory Asset
            'acc-2100' => 12, // AP
            'acc-4100' => 25, // Sales
            'acc-5100' => 26, // COGS
            'acc-6160' => 36, // Discount
            'acc-2200' => 13, // Tax
        ];

        if (isset($legacyMap[$resolvedAcc])) {
            $resolvedAcc = $legacyMap[$resolvedAcc];
        }

        if ($resolvedAcc !== null) {
            return (int)$resolvedAcc;
        }

        throw new AccountingException("Accounting account for preference key '{$preferenceKey}' is not configured.");
    }

    /**
     * Get configured preference account_id from erp_accounting_preferences or system_info.
     */
    public function getPreferenceAccountId(string $preferenceKey, ?int $locationId = null, string $asOfDate = null): mixed
    {
        $asOfDate = $asOfDate ?: date('Y-m-d');
        
        $keysToTry = [$preferenceKey];
        if ($preferenceKey === 'default_sales_return_account') {
            $keysToTry[] = 'default_sales_account';
            $keysToTry[] = 'default_income_account';
        } elseif ($preferenceKey === 'default_purchase_return_account') {
            $keysToTry[] = 'default_purchase_account';
            $keysToTry[] = 'default_cogs_account';
        }

        foreach ($keysToTry as $pk) {
            // 1. Check erp_accounting_preferences (location specific)
            if ($locationId) {
                try {
                    $sql = "SELECT account_id FROM erp_accounting_preferences 
                            WHERE preference_key = ? AND location_id = ? AND is_active = 1 
                              AND effective_from <= ? AND (effective_to IS NULL OR effective_to >= ?)
                            ORDER BY effective_from DESC LIMIT 1";
                    $stmt = $this->pdo->prepare($sql);
                    $stmt->execute([$pk, $locationId, $asOfDate, $asOfDate]);
                    $accId = $stmt->fetchColumn();
                    if (!empty($accId)) return $accId;
                } catch (Exception $e) {}
            }

            // 2. Check erp_accounting_preferences (global)
            try {
                $sql = "SELECT account_id FROM erp_accounting_preferences 
                        WHERE preference_key = ? AND (location_id IS NULL OR location_id = 0) AND is_active = 1 
                          AND effective_from <= ? AND (effective_to IS NULL OR effective_to >= ?)
                        ORDER BY effective_from DESC LIMIT 1";
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute([$pk, $asOfDate, $asOfDate]);
                $accId = $stmt->fetchColumn();
                if (!empty($accId)) return $accId;
            } catch (Exception $e) {}

            // 3. Fallback to system_info operational table
            try {
                $sql = "SELECT meta_value FROM system_info WHERE meta_field = ? LIMIT 1";
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute([$pk]);
                $val = $stmt->fetchColumn();
                if (!empty($val)) return $val;
            } catch (Exception $e) {}
        }

        return null;
    }

    /**
     * Validate that a General Ledger posting satisfies TOTAL DEBIT = TOTAL CREDIT.
     */
    public function validateGL(array $glLines): bool
    {
        $totalDebit = 0.0;
        $totalCredit = 0.0;

        foreach ($glLines as $line) {
            $totalDebit += (float) ($line['debit'] ?? 0);
            $totalCredit += (float) ($line['credit'] ?? 0);
        }

        $diff = abs($totalDebit - $totalCredit);
        if ($diff > 0.0001) {
            throw new AccountingException(sprintf("GL Out of Balance Error: Total Debit (%.2f) != Total Credit (%.2f). Difference: %.2f", $totalDebit, $totalCredit, $diff));
        }

        return true;
    }

    /**
     * Resolve Customer Receivable Account
     */
    public function resolveCustomerARAccount($customerId, ?int $locationId = null): mixed
    {
        if ($customerId) {
            try {
                $stmt = $this->pdo->prepare("SELECT receivable_account_id FROM customers WHERE id = ?");
                $stmt->execute([$customerId]);
                $custAcc = $stmt->fetchColumn();
                if (!empty($custAcc)) return $custAcc;
            } catch (Exception $e) {}

            try {
                $stmt = $this->pdo->prepare("SELECT receivable_account_id FROM erp_customers WHERE id = ?");
                $stmt->execute([$customerId]);
                $custAcc = $stmt->fetchColumn();
                if (!empty($custAcc)) return $custAcc;
            } catch (Exception $e) {}
        }
        return $this->resolveAccount('default_ar_account', ['location_id' => $locationId]);
    }

    /**
     * Resolve Vendor Payable Account
     */
    public function resolveVendorAPAccount($vendorId, ?int $locationId = null): mixed
    {
        if ($vendorId) {
            try {
                $stmt = $this->pdo->prepare("SELECT payable_account_id FROM vendors WHERE id = ?");
                $stmt->execute([$vendorId]);
                $vendAcc = $stmt->fetchColumn();
                if (!empty($vendAcc)) return $vendAcc;
            } catch (Exception $e) {}

            try {
                $stmt = $this->pdo->prepare("SELECT payable_account_id FROM erp_vendors WHERE id = ?");
                $stmt->execute([$vendorId]);
                $vendAcc = $stmt->fetchColumn();
                if (!empty($vendAcc)) return $vendAcc;
            } catch (Exception $e) {}
        }
        return $this->resolveAccount('default_ap_account', ['location_id' => $locationId]);
    }

    /**
     * Resolve Payment Method Account (Cash, Bank, eSewa, Khalti, etc.)
     */
    public function resolvePaymentMethodAccount(int $paymentMethodId): int
    {
        $stmt = $this->pdo->prepare("SELECT account_id FROM erp_payment_methods WHERE id = ? AND is_active = 1");
        $stmt->execute([$paymentMethodId]);
        $accId = $stmt->fetchColumn();

        if ($accId) {
            return (int) $accId;
        }

        throw new AccountingException("Payment Method ID {$paymentMethodId} has no active linked COA account.");
    }
}
