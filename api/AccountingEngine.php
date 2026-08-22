<?php
/**
 * api/AccountingEngine.php
 * Double-Entry General Ledger & Accounting Engine for MNS Liquor ERP (PHP + MySQL).
 *
 * Implements strict double-entry GL validation (SUM(debit) = SUM(credit)),
 * period locking enforcement, materialized account_balances maintenance with row-locking,
 * and automated mapping functions for Purchase Bills, Sales Invoices, Payments, Adjustments & Transfers.
 */

if (!class_exists('AccountingException')) {
    class AccountingException extends Exception {}
}

class AccountingEngine
{
    private static $instance = null;
    private $pdo;

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

    public function getPDO(): PDO
    {
        return $this->pdo;
    }

    /**
     * Resolve account_id by account_code or fallback
     */
    public function getAccountIdByCode(string $code, string $defaultType = 'ASSET', string $defaultName = 'Default Account'): int
    {
        $stmt = $this->pdo->prepare("SELECT account_id FROM chart_of_accounts WHERE account_code = ? LIMIT 1");
        $stmt->execute([$code]);
        $id = $stmt->fetchColumn();
        if ($id) return (int)$id;

        // Create if missing
        $normalBal = in_array($defaultType, ['ASSET', 'EXPENSE', 'COGS']) ? 'DEBIT' : 'CREDIT';
        $stmtIns = $this->pdo->prepare("INSERT INTO chart_of_accounts (account_code, account_name, account_type, normal_balance) VALUES (?, ?, ?, ?)");
        $stmtIns->execute([$code, $defaultName, $defaultType, $normalBal]);
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Resolve accounting period ID for a given date & enforce period locks
     */
    public function resolvePeriodForDate($dateStr): array
    {
        $d = date('Y-m-d', strtotime($dateStr));
        $stmt = $this->pdo->prepare("SELECT * FROM accounting_periods WHERE ? BETWEEN start_date AND end_date LIMIT 1");
        $stmt->execute([$d]);
        $period = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$period) {
            // Auto-create month period if not found
            $pName = date('M Y', strtotime($d));
            $sDate = date('Y-m-01', strtotime($d));
            $eDate = date('Y-m-t', strtotime($d));
            $stmtIns = $this->pdo->prepare("INSERT INTO accounting_periods (period_name, start_date, end_date, status) VALUES (?, ?, ?, 'OPEN')");
            $stmtIns->execute([$pName, $sDate, $eDate]);
            $pId = (int)$this->pdo->lastInsertId();
            return ['period_id' => $pId, 'period_name' => $pName, 'status' => 'OPEN', 'start_date' => $sDate, 'end_date' => $eDate];
        }

        if (in_array(strtoupper($period['status']), ['CLOSED', 'LOCKED'])) {
            throw new AccountingException("Accounting Period '{$period['period_name']}' is " . strtoupper($period['status']) . ". Transactions are locked.");
        }

        return $period;
    }

    /**
     * Core Low-Level Journal Entry Posting Function
     * Enforces SUM(debit) = SUM(credit) and updates materialized account_balances.
     */
    public function postJournalEntry($transactionId, string $jeType, array $lines, $date = null, string $memo = ''): int
    {
        if (empty($lines)) {
            throw new AccountingException("Journal Entry must contain at least one debit and credit line.");
        }

        $dateStr = $date ? (is_a($date, 'DateTime') ? $date->format('Y-m-d H:i:s') : date('Y-m-d H:i:s', strtotime($date))) : date('Y-m-d H:i:s');
        
        // 1. Enforce Period Lock Check
        $period = $this->resolvePeriodForDate($dateStr);
        $periodId = (int)($period['period_id'] ?? $period['id']);

        // 2. Validate Double-Entry Balance Rule: SUM(debit) = SUM(credit)
        $totDebit = 0.0;
        $totCredit = 0.0;
        foreach ($lines as $l) {
            $totDebit  += round((float)($l['debit'] ?? 0.0), 2);
            $totCredit += round((float)($l['credit'] ?? 0.0), 2);
        }

        if (abs($totDebit - $totCredit) >= 0.01) {
            throw new AccountingException(sprintf(
                "Unbalanced Journal Entry! Total Debits ($%.2f) must equal Total Credits ($%.2f). Type: %s, TxnID: %s",
                $totDebit, $totCredit, $jeType, (string)$transactionId
            ));
        }

        $inTxn = $this->pdo->inTransaction();
        if (!$inTxn) {
            $this->pdo->beginTransaction();
        }

        try {
            // Check if JE already exists for transactionId & jeType, replace/update if posting again
            if (!empty($transactionId)) {
                $stmtPrev = $this->pdo->prepare("SELECT je_id FROM journal_entries WHERE transaction_id = ? AND je_type = ? AND status = 'POSTED'");
                $stmtPrev->execute([$transactionId, $jeType]);
                $prevJeIds = $stmtPrev->fetchAll(PDO::FETCH_COLUMN);

                foreach ($prevJeIds as $oldJeId) {
                    $this->reverseJournalEntry((int)$oldJeId, 'Replaced by updated posting');
                }
            }

            // Insert Journal Entry Header
            $stmtHeader = $this->pdo->prepare("
                INSERT INTO journal_entries (transaction_id, je_date, je_type, memo, status, period_id)
                VALUES (?, ?, ?, ?, 'POSTED', ?)
            ");
            $stmtHeader->execute([$transactionId, $dateStr, $jeType, $memo, $periodId]);
            $jeId = (int)$this->pdo->lastInsertId();

            // Insert Lines & Update Account Balances
            $stmtLine = $this->pdo->prepare("
                INSERT INTO journal_lines 
                (je_id, account_id, debit, credit, entity_type, entity_id, location_id, class_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");

            foreach ($lines as $l) {
                $accId  = (int)$l['account_id'];
                $deb    = round((float)($l['debit'] ?? 0.0), 2);
                $cred   = round((float)($l['credit'] ?? 0.0), 2);
                $eType  = $l['entity_type'] ?? 'NONE';
                $eId    = !empty($l['entity_id']) ? (int)$l['entity_id'] : null;
                $locId  = !empty($l['location_id']) ? (int)$l['location_id'] : null;
                $classId = !empty($l['class_id']) ? (int)$l['class_id'] : null;

                if ($deb == 0 && $cred == 0) continue;

                $stmtLine->execute([$jeId, $accId, $deb, $cred, $eType, $eId, $locId, $classId]);

                // Update Materialized Account Balances with Row-Locking
                $this->updateAccountBalance($accId, $periodId, $deb, $cred);
            }

            if (!$inTxn) {
                $this->pdo->commit();
            }

            return $jeId;
        } catch (Exception $e) {
            if (!$inTxn && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Materialized Account Balance Maintenance with Row Locking (SELECT ... FOR UPDATE)
     */
    private function updateAccountBalance(int $accountId, int $periodId, float $debit, float $credit): void
    {
        $stmtLock = $this->pdo->prepare("SELECT * FROM account_balances WHERE account_id = ? AND period_id = ? FOR UPDATE");
        $stmtLock->execute([$accountId, $periodId]);
        $balRow = $stmtLock->fetch(PDO::FETCH_ASSOC);

        if (!$balRow) {
            // Fetch normal balance of account
            $stmtAcc = $this->pdo->prepare("SELECT normal_balance FROM chart_of_accounts WHERE account_id = ?");
            $stmtAcc->execute([$accountId]);
            $normalBal = strtoupper($stmtAcc->fetchColumn() ?: 'DEBIT');

            // Fetch prior period closing balance if available
            $stmtPrior = $this->pdo->prepare("
                SELECT closing_balance 
                FROM account_balances 
                WHERE account_id = ? AND period_id < ? 
                ORDER BY period_id DESC LIMIT 1
            ");
            $stmtPrior->execute([$accountId, $periodId]);
            $openBal = (float)($stmtPrior->fetchColumn() ?: 0.0);

            $newDebs  = $debit;
            $newCreds = $credit;
            $newClose = $normalBal === 'DEBIT' ? ($openBal + $newDebs - $newCreds) : ($openBal + $newCreds - $newDebs);

            $stmtIns = $this->pdo->prepare("
                INSERT INTO account_balances (account_id, period_id, opening_balance, period_debits, period_credits, closing_balance)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmtIns->execute([$accountId, $periodId, $openBal, $newDebs, $newCreds, $newClose]);
        } else {
            $stmtAcc = $this->pdo->prepare("SELECT normal_balance FROM chart_of_accounts WHERE account_id = ?");
            $stmtAcc->execute([$accountId]);
            $normalBal = strtoupper($stmtAcc->fetchColumn() ?: 'DEBIT');

            $openBal  = (float)$balRow['opening_balance'];
            $newDebs  = (float)$balRow['period_debits'] + $debit;
            $newCreds = (float)$balRow['period_credits'] + $credit;
            $newClose = $normalBal === 'DEBIT' ? ($openBal + $newDebs - $newCreds) : ($openBal + $newCreds - $newDebs);

            $stmtUp = $this->pdo->prepare("
                UPDATE account_balances 
                SET period_debits = ?, period_credits = ?, closing_balance = ?, updated_at = NOW()
                WHERE account_id = ? AND period_id = ?
            ");
            $stmtUp->execute([$newDebs, $newCreds, $newClose, $accountId, $periodId]);
        }
    }

    /**
     * Reverse a Journal Entry
     */
    public function reverseJournalEntry(int $jeId, string $reason = 'Reversal'): int
    {
        $stmtJE = $this->pdo->prepare("SELECT * FROM journal_entries WHERE je_id = ?");
        $stmtJE->execute([$jeId]);
        $je = $stmtJE->fetch(PDO::FETCH_ASSOC);

        if (!$je || $je['status'] === 'VOIDED') return 0;

        $stmtLines = $this->pdo->prepare("SELECT * FROM journal_lines WHERE je_id = ?");
        $stmtLines->execute([$jeId]);
        $lines = $stmtLines->fetchAll(PDO::FETCH_ASSOC);

        $revLines = [];
        foreach ($lines as $l) {
            $revLines[] = [
                'account_id'  => $l['account_id'],
                'debit'       => $l['credit'],  // Swap debit & credit
                'credit'      => $l['debit'],
                'entity_type' => $l['entity_type'],
                'entity_id'   => $l['entity_id'],
                'location_id' => $l['location_id'],
                'class_id'    => $l['class_id'],
            ];
        }

        // Mark original JE as VOIDED
        $stmtMark = $this->pdo->prepare("UPDATE journal_entries SET status = 'VOIDED' WHERE je_id = ?");
        $stmtMark->execute([$jeId]);

        return $this->postJournalEntry($je['transaction_id'], $je['je_type'] . '_VOID', $revLines, date('Y-m-d H:i:s'), "Reversal of JE #{$jeId}: {$reason}");
    }

    /**
     * Delete all journal entries and lines associated with a transaction
     */
    public function deleteJournalForTransaction($transactionId): void
    {
        if (empty($transactionId)) return;
        $stmtJe = $this->pdo->prepare("SELECT je_id FROM journal_entries WHERE transaction_id = ?");
        $stmtJe->execute([$transactionId]);
        $jeIds = $stmtJe->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($jeIds)) {
            $inClause = implode(',', array_fill(0, count($jeIds), '?'));
            $stmtDelLines = $this->pdo->prepare("DELETE FROM journal_lines WHERE je_id IN ({$inClause})");
            $stmtDelLines->execute($jeIds);

            $stmtDelJe = $this->pdo->prepare("DELETE FROM journal_entries WHERE je_id IN ({$inClause})");
            $stmtDelJe->execute($jeIds);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // AUTO-POSTING MAPPING FUNCTIONS (Transaction -> GL Double Entry)
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * Purchase Bill: Debit Inventory Asset (1030), Credit Accounts Payable (2010)
     */
    public function postPurchaseBill($transactionId, $vendorId, $locationId, float $totalAmount, $date = null, string $memo = ''): int
    {
        $invAcc = $this->getAccountIdByCode('1030', 'ASSET', 'Inventory Asset');
        $apAcc  = $this->getAccountIdByCode('2010', 'LIABILITY', 'Accounts Payable (AP)');

        $lines = [
            ['account_id' => $invAcc, 'debit' => $totalAmount, 'credit' => 0, 'entity_type' => 'VENDOR', 'entity_id' => $vendorId, 'location_id' => $locationId],
            ['account_id' => $apAcc,  'debit' => 0, 'credit' => $totalAmount, 'entity_type' => 'VENDOR', 'entity_id' => $vendorId, 'location_id' => $locationId],
        ];

        return $this->postJournalEntry($transactionId, 'PURCHASE', $lines, $date, $memo ?: "Purchase Bill #{$transactionId}");
    }

    /**
     * Vendor Payment: Debit Accounts Payable (2010), Credit Cash/Bank (1010)
     */
    public function postVendorPayment($transactionId, $vendorId, $locationId, float $amount, $date = null, string $memo = ''): int
    {
        $apAcc   = $this->getAccountIdByCode('2010', 'LIABILITY', 'Accounts Payable (AP)');
        $cashAcc = $this->getAccountIdByCode('1010', 'ASSET', 'Cash and Bank Balances');

        $lines = [
            ['account_id' => $apAcc,   'debit' => $amount, 'credit' => 0, 'entity_type' => 'VENDOR', 'entity_id' => $vendorId, 'location_id' => $locationId],
            ['account_id' => $cashAcc, 'debit' => 0, 'credit' => $amount, 'entity_type' => 'VENDOR', 'entity_id' => $vendorId, 'location_id' => $locationId],
        ];

        return $this->postJournalEntry($transactionId, 'PAYMENT', $lines, $date, $memo ?: "Vendor Payment #{$transactionId}");
    }

    /**
     * Sale Invoice / POS: 
     * Revenue Leg: Debit Accounts Receivable (1020), Credit Sales Revenue (4010)
     * COGS Leg: Debit COGS (5010), Credit Inventory Asset (1030) using exact unit_cost from InventoryEngine
     */
    public function postSaleInvoice($transactionId, $customerId, $locationId, float $revenueAmount, float $cogsAmount, $date = null, string $memo = ''): int
    {
        $arAcc   = $this->getAccountIdByCode('1020', 'ASSET', 'Accounts Receivable (AR)');
        $revAcc  = $this->getAccountIdByCode('4010', 'INCOME', 'Sales Revenue');
        $cogsAcc = $this->getAccountIdByCode('5010', 'COGS', 'Cost of Goods Sold (COGS)');
        $invAcc  = $this->getAccountIdByCode('1030', 'ASSET', 'Inventory Asset');

        $lines = [
            // Revenue Leg
            ['account_id' => $arAcc,   'debit' => $revenueAmount, 'credit' => 0, 'entity_type' => 'CUSTOMER', 'entity_id' => $customerId, 'location_id' => $locationId],
            ['account_id' => $revAcc,  'debit' => 0, 'credit' => $revenueAmount, 'entity_type' => 'CUSTOMER', 'entity_id' => $customerId, 'location_id' => $locationId],
        ];

        if ($cogsAmount > 0) {
            // COGS Leg
            $lines[] = ['account_id' => $cogsAcc, 'debit' => $cogsAmount, 'credit' => 0, 'entity_type' => 'ITEM', 'location_id' => $locationId];
            $lines[] = ['account_id' => $invAcc,  'debit' => 0, 'credit' => $cogsAmount, 'entity_type' => 'ITEM', 'location_id' => $locationId];
        }

        return $this->postJournalEntry($transactionId, 'SALE', $lines, $date, $memo ?: "Sales Invoice #{$transactionId}");
    }

    /**
     * Customer Payment: Debit Cash/Bank (1010), Credit Accounts Receivable (1020)
     */
    public function postCustomerPayment($transactionId, $customerId, $locationId, float $amount, $date = null, string $memo = ''): int
    {
        $cashAcc = $this->getAccountIdByCode('1010', 'ASSET', 'Cash and Bank Balances');
        $arAcc   = $this->getAccountIdByCode('1020', 'ASSET', 'Accounts Receivable (AR)');

        $lines = [
            ['account_id' => $cashAcc, 'debit' => $amount, 'credit' => 0, 'entity_type' => 'CUSTOMER', 'entity_id' => $customerId, 'location_id' => $locationId],
            ['account_id' => $arAcc,   'debit' => 0, 'credit' => $amount, 'entity_type' => 'CUSTOMER', 'entity_id' => $customerId, 'location_id' => $locationId],
        ];

        return $this->postJournalEntry($transactionId, 'PAYMENT', $lines, $date, $memo ?: "Customer Payment #{$transactionId}");
    }

    /**
     * Inventory Adjustment: 
     * Increase: Debit Inventory Asset (1030), Credit Other Income / Adjustment Gain (4020)
     * Decrease: Debit Inventory Adjustment Expense (6020), Credit Inventory Asset (1030)
     */
    public function postInventoryAdjustment($transactionId, $locationId, float $totalVal, bool $isIncrease, $date = null, string $memo = ''): int
    {
        $invAcc  = $this->getAccountIdByCode('1030', 'ASSET', 'Inventory Asset');
        $gainAcc = $this->getAccountIdByCode('4020', 'INCOME', 'Other Income / Adjustment Gain');
        $expAcc  = $this->getAccountIdByCode('6020', 'EXPENSE', 'Inventory Adjustment / Shrinkage Expense');

        $val = abs($totalVal);
        if ($isIncrease) {
            $lines = [
                ['account_id' => $invAcc,  'debit' => $val, 'credit' => 0, 'location_id' => $locationId],
                ['account_id' => $gainAcc, 'debit' => 0, 'credit' => $val, 'location_id' => $locationId],
            ];
        } else {
            $lines = [
                ['account_id' => $expAcc, 'debit' => $val, 'credit' => 0, 'location_id' => $locationId],
                ['account_id' => $invAcc, 'debit' => 0, 'credit' => $val, 'location_id' => $locationId],
            ];
        }

        return $this->postJournalEntry($transactionId, 'INVENTORY_ADJ', $lines, $date, $memo ?: "Inventory Adjustment #{$transactionId}");
    }

    /**
     * Inventory Transfer between Locations:
     * Debit Destination Location Inventory Asset, Credit Source Location Inventory Asset
     */
    public function postTransfer($transactionId, $fromLocId, $toLocId, float $totalVal, $date = null, string $memo = ''): int
    {
        $invAcc = $this->getAccountIdByCode('1030', 'ASSET', 'Inventory Asset');

        $lines = [
            ['account_id' => $invAcc, 'debit' => $totalVal, 'credit' => 0, 'location_id' => $toLocId],
            ['account_id' => $invAcc, 'debit' => 0, 'credit' => $totalVal, 'location_id' => $fromLocId],
        ];

        return $this->postJournalEntry($transactionId, 'TRANSFER', $lines, $date, $memo ?: "Inventory Transfer #{$transactionId}");
    }

    /**
     * Linkage with InventoryEngine recostForward():
     * Re-posts COGS Journal Entry lines when historical unit costs change during recosting.
     */
    public function updateCogsJournalLines($transactionId, float $newCogsAmount): void
    {
        $stmt = $this->pdo->prepare("SELECT je_id, je_date, period_id FROM journal_entries WHERE transaction_id = ? AND je_type = 'SALE' AND status = 'POSTED'");
        $stmt->execute([$transactionId]);
        $je = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$je) return;

        $period = $this->resolvePeriodForDate($je['je_date']);
        if (in_array(strtoupper($period['status']), ['CLOSED', 'LOCKED'])) {
            // Post correcting entry in current open period if past period is closed
            $this->postInventoryAdjustment($transactionId, 1, $newCogsAmount, false, date('Y-m-d H:i:s'), "COGS Correction for closed period JE #{$je['je_id']}");
            return;
        }

        // Fetch transaction details and re-post Sale GL Entry with updated COGS amount
        $stmtLines = $this->pdo->prepare("
            SELECT jl.* 
            FROM journal_lines jl 
            JOIN chart_of_accounts c ON jl.account_id = c.account_id
            WHERE jl.je_id = ? AND c.account_code = '1020' AND jl.debit > 0
            LIMIT 1
        ");
        $stmtLines->execute([$je['je_id']]);
        $arLine = $stmtLines->fetch(PDO::FETCH_ASSOC);

        $revAmount = (float)($arLine['debit'] ?? 0.0);
        $custId    = (int)($arLine['entity_id'] ?? 0);
        $locId     = (int)($arLine['location_id'] ?? 1);

        $this->postSaleInvoice($transactionId, $custId, $locId, $revAmount, $newCogsAmount, $je['je_date'], "COGS Updated via RecostForward");
    }

    /**
     * Close an Accounting Period and Roll Forward Balance Sheet Balances
     */
    public function closePeriod(int $periodId): void
    {
        $inTxn = $this->pdo->inTransaction();
        if (!$inTxn) $this->pdo->beginTransaction();

        try {
            $stmtP = $this->pdo->prepare("SELECT * FROM accounting_periods WHERE period_id = ?");
            $stmtP->execute([$periodId]);
            $period = $stmtP->fetch(PDO::FETCH_ASSOC);

            if (!$period) throw new AccountingException("Period ID {$periodId} not found.");

            // 1. Lock period
            $stmtLock = $this->pdo->prepare("UPDATE accounting_periods SET status = 'CLOSED' WHERE period_id = ?");
            $stmtLock->execute([$periodId]);

            // 2. Fetch next period
            $stmtNext = $this->pdo->prepare("SELECT period_id FROM accounting_periods WHERE start_date > ? ORDER BY start_date ASC LIMIT 1");
            $stmtNext->execute([$period['end_date']]);
            $nextPeriodId = $stmtNext->fetchColumn();

            if ($nextPeriodId) {
                // Roll forward Balance Sheet closing balances to next period's opening balances
                $stmtBals = $this->pdo->prepare("
                    SELECT ab.*, c.account_type 
                    FROM account_balances ab
                    JOIN chart_of_accounts c ON ab.account_id = c.account_id
                    WHERE ab.period_id = ?
                ");
                $stmtBals->execute([$periodId]);
                $bals = $stmtBals->fetchAll(PDO::FETCH_ASSOC);

                foreach ($bals as $b) {
                    // Income / COGS / Expense accounts reset opening balance to 0 in new period
                    $openBal = in_array(strtoupper($b['account_type']), ['INCOME', 'COGS', 'EXPENSE']) ? 0.0 : (float)$b['closing_balance'];
                    $accId   = (int)$b['account_id'];

                    $stmtNextBal = $this->pdo->prepare("
                        INSERT INTO account_balances (account_id, period_id, opening_balance, period_debits, period_credits, closing_balance)
                        VALUES (?, ?, ?, 0, 0, ?)
                        ON DUPLICATE KEY UPDATE opening_balance = VALUES(opening_balance), closing_balance = VALUES(opening_balance) + period_debits - period_credits
                    ");
                    $stmtNextBal->execute([$accId, $nextPeriodId, $openBal, $openBal]);
                }
            }

            if (!$inTxn) $this->pdo->commit();
        } catch (Exception $e) {
            if (!$inTxn && $this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }
}
