<?php
require_once __DIR__ . '/InventoryEngine.php';

/**
 * api/ReportEngine.php
 * Declarative Reporting Engine for MNS Liquor ERP (PHP + MySQL).
 *
 * Implements ReportSpec class, generic runner (ReportEngine::run),
 * and the 12 core Financial, Inventory, and Sales/Purchasing Report Specs.
 */

if (!class_exists('ReportException')) {
    class ReportException extends Exception {}
}

class ReportSpec
{
    public string $key;
    public string $title;
    public string $source;
    public array $filters = [];
    public array $groupBy = [];
    public array $columns = [];
    public $calculate = null;

    public function __construct(string $key, string $title, string $source, array $columns = [], array $filters = [], array $groupBy = [], $calculate = null)
    {
        $this->key       = $key;
        $this->title     = $title;
        $this->source    = $source;
        $this->columns   = $columns;
        $this->filters   = $filters;
        $this->groupBy   = $groupBy;
        $this->calculate = $calculate;
    }
}

class ReportResult
{
    public string $specKey;
    public string $title;
    public array $columns = [];
    public array $rows = [];
    public array $totals = [];
    public array $summary = [];
    public string $printedAt;

    public function __construct(string $specKey, string $title, array $columns, array $rows, array $totals = [], array $summary = [])
    {
        $this->specKey   = $specKey;
        $this->title     = $title;
        $this->columns   = $columns;
        $this->rows      = $rows;
        $this->totals    = $totals;
        $this->summary   = $summary;
        $this->printedAt = date('Y-m-d H:i:s');
    }
}

class ReportEngine
{
    private static $instance = null;
    private $pdo;
    private array $specs = [];

    private function __construct()
    {
        $db = DBConnection::getInstance();
        $this->pdo = $db->getConnection();
        $this->registerDefaultSpecs();
    }

    public static function getInstance(): ReportEngine
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function registerSpec(ReportSpec $spec): void
    {
        $this->specs[$spec->key] = $spec;
    }

    public function getSpec(string $key): ReportSpec
    {
        if (!isset($this->specs[$key])) {
            throw new ReportException("Report specification for key '{$key}' is not registered.");
        }
        return $this->specs[$key];
    }

    /**
     * Generic Spec Runner: Filter -> Group -> Aggregate -> Format
     */
    public function run(ReportSpec $spec, array $params = []): ReportResult
    {
        if (is_callable($spec->calculate)) {
            return call_user_func($spec->calculate, $this->pdo, $params, $spec);
        }

        // Generic query executor for declarative specs
        return $this->runGenericSpec($spec, $params);
    }

    private function runGenericSpec(ReportSpec $spec, array $params): ReportResult
    {
        $fromDate = $params['date_from'] ?? '2026-01-01';
        $toDate   = $params['date_to'] ?? date('Y-m-d');
        $locationId = $params['location_id'] ?? null;

        $rows = [];
        $totals = [];

        // Build generic queries based on source
        if ($spec->source === 'account_balances') {
            $stmt = $this->pdo->prepare("
                SELECT c.account_code, c.account_name, c.account_type, c.normal_balance,
                       SUM(ab.opening_balance) as opening_balance,
                       SUM(ab.period_debits) as period_debits,
                       SUM(ab.period_credits) as period_credits,
                       SUM(ab.closing_balance) as closing_balance
                FROM chart_of_accounts c
                LEFT JOIN account_balances ab ON ab.account_id = c.account_id
                WHERE c.is_active = 1
                GROUP BY c.account_id
                ORDER BY c.account_code ASC
            ");
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        return new ReportResult($spec->key, $spec->title, $spec->columns, $rows, $totals);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // 12 CORE REPORT SPECIFICATIONS REGISTRY
    // ─────────────────────────────────────────────────────────────────────────────

    private function registerDefaultSpecs(): void
    {
        // 1. Trial Balance Report Spec
        $this->registerSpec(new ReportSpec(
            'trial_balance',
            'Trial Balance',
            'journal_lines',
            ['code' => 'Account Code', 'name' => 'Account Name', 'debit' => 'Debit', 'credit' => 'Credit'],
            ['date_from', 'date_to'],
            ['account_id'],
            function($pdo, $params, $spec) {
                $toDate = $params['date_to'] ?? date('Y-m-d');
                $toEnd  = date('Y-m-d 23:59:59', strtotime($toDate));

                $sql = "
                    SELECT c.account_code, c.account_name, c.account_type, c.normal_balance,
                           COALESCE(SUM(jl.debit), 0) as total_debit,
                           COALESCE(SUM(jl.credit), 0) as total_credit
                    FROM chart_of_accounts c
                    LEFT JOIN journal_lines jl ON jl.account_id = c.account_id
                    LEFT JOIN journal_entries je ON jl.je_id = je.je_id AND je.status = 'POSTED' AND je.je_date <= ?
                    WHERE c.is_active = 1
                    GROUP BY c.account_id, c.account_code, c.account_name, c.account_type, c.normal_balance
                    HAVING (total_debit != 0 OR total_credit != 0)
                    ORDER BY c.account_code ASC
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$toEnd]);
                $raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $rows = [];
                $totDebit = 0.0;
                $totCredit = 0.0;

                foreach ($raw as $r) {
                    $d = (float)$r['total_debit'];
                    $c = (float)$r['total_credit'];
                    $norm = strtoupper($r['normal_balance']);

                    $net = $norm === 'DEBIT' ? ($d - $c) : ($c - $d);
                    $debVal  = ($net > 0 && $norm === 'DEBIT') ? $net : 0.0;
                    $credVal = ($net > 0 && $norm === 'CREDIT') ? $net : 0.0;

                    $rows[] = [
                        'code'   => $r['account_code'],
                        'name'   => $r['account_name'],
                        'debit'  => $debVal,
                        'credit' => $credVal,
                    ];
                    $totDebit += $debVal;
                    $totCredit += $credVal;
                }

                $isBalanced = abs($totDebit - $totCredit) < 0.05;
                $totals = ['debit' => $totDebit, 'credit' => $totCredit, 'is_balanced' => $isBalanced];

                return new ReportResult($spec->key, $spec->title, $spec->columns, $rows, $totals, ['is_balanced' => $isBalanced]);
            }
        ));

        // 2. Income Statement (P&L) Spec
        $this->registerSpec(new ReportSpec(
            'income_statement',
            'Income Statement (P&L)',
            'journal_lines',
            ['section' => 'Section', 'account' => 'Account Name', 'amount' => 'Amount (NPR)'],
            ['date_from', 'date_to'],
            ['account_type'],
            function($pdo, $params, $spec) {
                $fromDate = $params['date_from'] ?? '2026-01-01';
                $toDate   = $params['date_to'] ?? date('Y-m-d');
                $fromStart = date('Y-m-01 00:00:00', strtotime($fromDate));
                $toEnd     = date('Y-m-d 23:59:59', strtotime($toDate));

                $sql = "
                    SELECT c.account_name, c.account_type,
                           COALESCE(SUM(jl.debit), 0) as total_debit,
                           COALESCE(SUM(jl.credit), 0) as total_credit
                    FROM chart_of_accounts c
                    LEFT JOIN journal_lines jl ON jl.account_id = c.account_id
                    LEFT JOIN journal_entries je ON jl.je_id = je.je_id AND je.status = 'POSTED' AND je.je_date BETWEEN ? AND ?
                    WHERE c.account_type IN ('INCOME', 'COGS', 'EXPENSE') AND c.is_active = 1
                    GROUP BY c.account_id, c.account_name, c.account_type
                    ORDER BY c.account_type ASC, c.account_code ASC
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$fromStart, $toEnd]);
                $raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $revenue = 0.0;
                $cogs    = 0.0;
                $opex    = 0.0;
                $rows    = [];

                foreach ($raw as $r) {
                    $d = (float)$r['total_debit'];
                    $c = (float)$r['total_credit'];
                    $type = strtoupper($r['account_type']);

                    if ($type === 'INCOME') {
                        $amt = $c - $d;
                        $revenue += $amt;
                        $rows[] = ['section' => 'REVENUE', 'account' => $r['account_name'], 'amount' => $amt];
                    } elseif ($type === 'COGS') {
                        $amt = $d - $c;
                        $cogs += $amt;
                        $rows[] = ['section' => 'COST OF GOODS SOLD', 'account' => $r['account_name'], 'amount' => $amt];
                    } else {
                        $amt = $d - $c;
                        $opex += $amt;
                        $rows[] = ['section' => 'OPERATING EXPENSES', 'account' => $r['account_name'], 'amount' => $amt];
                    }
                }

                $grossProfit = $revenue - $cogs;
                $netIncome   = $grossProfit - $opex;

                $summary = [
                    'revenue'      => $revenue,
                    'cogs'         => $cogs,
                    'gross_profit' => $grossProfit,
                    'opex'         => $opex,
                    'net_income'   => $netIncome,
                ];

                return new ReportResult($spec->key, $spec->title, $spec->columns, $rows, $summary, $summary);
            }
        ));

        // 3. Balance Sheet Spec (with live YTD Net Income)
        $this->registerSpec(new ReportSpec(
            'balance_sheet',
            'Balance Sheet',
            'journal_lines',
            ['type' => 'Category', 'name' => 'Account Name', 'balance' => 'Balance (NPR)'],
            ['date_to'],
            ['account_type'],
            function($pdo, $params, $spec) {
                $toDate = $params['date_to'] ?? date('Y-m-d');
                $toEnd  = date('Y-m-d 23:59:59', strtotime($toDate));

                $sql = "
                    SELECT c.account_name, c.account_type, c.normal_balance,
                           COALESCE(SUM(jl.debit), 0) as total_debit,
                           COALESCE(SUM(jl.credit), 0) as total_credit
                    FROM chart_of_accounts c
                    LEFT JOIN journal_lines jl ON jl.account_id = c.account_id
                    LEFT JOIN journal_entries je ON jl.je_id = je.je_id AND je.status = 'POSTED' AND je.je_date <= ?
                    WHERE c.account_type IN ('ASSET', 'LIABILITY', 'EQUITY') AND c.is_active = 1
                    GROUP BY c.account_id, c.account_name, c.account_type, c.normal_balance
                    ORDER BY c.account_type ASC, c.account_code ASC
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$toEnd]);
                $raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Compute YTD Net Income live
                $pnlSpec = ReportEngine::getInstance()->getSpec('income_statement');
                $pnlRes  = ReportEngine::getInstance()->run($pnlSpec, ['date_from' => date('Y-01-01', strtotime($toDate)), 'date_to' => $toDate]);
                $ytdNetIncome = (float)($pnlRes->summary['net_income'] ?? 0.0);

                $assets      = 0.0;
                $liabilities = 0.0;
                $equity      = 0.0;
                $rows        = [];

                foreach ($raw as $r) {
                    $d = (float)$r['total_debit'];
                    $c = (float)$r['total_credit'];
                    $type = strtoupper($r['account_type']);
                    $norm = strtoupper($r['normal_balance']);

                    $bal = $norm === 'DEBIT' ? ($d - $c) : ($c - $d);

                    if ($type === 'ASSET') $assets += $bal;
                    elseif ($type === 'LIABILITY') $liabilities += $bal;
                    else $equity += $bal;

                    $rows[] = ['type' => $type, 'name' => $r['account_name'], 'balance' => $bal];
                }

                // Add YTD Net Income equity line
                $equity += $ytdNetIncome;
                $rows[] = ['type' => 'EQUITY', 'name' => 'Current Year Net Income (YTD)', 'balance' => $ytdNetIncome];

                $totLiabEquity = $liabilities + $equity;
                $isBalanced    = abs($assets - $totLiabEquity) < 0.05;

                $summary = [
                    'total_assets'      => $assets,
                    'total_liabilities' => $liabilities,
                    'total_equity'      => $equity,
                    'total_liab_equity' => $totLiabEquity,
                    'is_balanced'       => $isBalanced,
                ];

                return new ReportResult($spec->key, $spec->title, $spec->columns, $rows, $summary, $summary);
            }
        ));

        // 4. General Ledger Detail Spec
        $this->registerSpec(new ReportSpec(
            'gl_detail',
            'General Ledger Detail',
            'journal_lines',
            ['date' => 'Date', 'je_type' => 'Type', 'memo' => 'Memo', 'debit' => 'Debit', 'credit' => 'Credit', 'balance' => 'Running Balance'],
            ['account_id', 'date_from', 'date_to']
        ));

        // 5. Cash Flow Statement Spec (Indirect Method)
        $this->registerSpec(new ReportSpec(
            'cash_flow',
            'Statement of Cash Flows (Indirect Method)',
            'account_balances',
            ['category' => 'Category', 'line' => 'Description', 'amount' => 'Amount (NPR)'],
            ['date_from', 'date_to']
        ));

        // 6. AR Aging / AP Aging Spec
        $this->registerSpec(new ReportSpec(
            'ar_ap_aging',
            'Accounts Receivable / Payable Aging',
            'journal_lines',
            ['party_name' => 'Customer / Vendor', 'current' => 'Current (0-30)', 'days_30' => '31-60 Days', 'days_60' => '61-90 Days', 'days_90' => '90+ Days', 'total' => 'Total Due'],
            ['type', 'as_of_date']
        ));

        // 7. Stock Status / Quantity on Hand Spec
        $this->registerSpec(new ReportSpec(
            'stock_status',
            'Stock Status & Quantity on Hand',
            'inventory_balances',
            ['sku' => 'SKU', 'item_name' => 'Item Name', 'category' => 'Category', 'on_hand' => 'Qty on Hand', 'avg_cost' => 'Avg Cost', 'total_val' => 'Total Value'],
            ['location_id', 'category_id'],
            ['item_id'],
            function($pdo, $params, $spec) {
                $locId = $params['location_id'] ?? null;
                $catId = $params['category_id'] ?? null;

                $raw = InventoryEngine::getInstance()->getRealtimeStockValuation(date('Y-m-d'), $locId, $catId);
                $rows = [];
                $totVal = 0.0;

                foreach ($raw as $r) {
                    $val = (float)$r['stock_value'];
                    $rows[] = [
                        'sku'       => $r['sku'],
                        'item_name' => $r['item_name'],
                        'category'  => $r['item_category'] ?? 'Uncategorized',
                        'on_hand'   => (float)$r['stock_qty'],
                        'avg_cost'  => (float)$r['cost_price'],
                        'total_val' => $val,
                    ];
                    $totVal += $val;
                }

                return new ReportResult($spec->key, $spec->title, $spec->columns, $rows, ['total_value' => $totVal]);
            }
        ));

        // 8. Inventory Valuation Summary Spec (with GL Reconciliation)
        $this->registerSpec(new ReportSpec(
            'inventory_valuation_summary',
            'Inventory Valuation Summary',
            'inventory_balances',
            ['sku' => 'SKU', 'item_name' => 'Item Name', 'category' => 'Category', 'qty' => 'Qty on Hand', 'cost' => 'Unit Cost', 'val' => 'Total Value (NPR)'],
            ['location_id', 'category_id'],
            [],
            function($pdo, $params, $spec) {
                $locId = $params['location_id'] ?? null;
                $catId = $params['category_id'] ?? null;

                $raw = InventoryEngine::getInstance()->getRealtimeStockValuation(date('Y-m-d'), $locId, $catId);
                $rows = [];
                $totVal = 0.0;

                foreach ($raw as $r) {
                    $val = (float)$r['stock_value'];
                    $rows[] = [
                        'sku'       => $r['sku'],
                        'item_name' => $r['item_name'],
                        'category'  => $r['item_category'] ?? 'Uncategorized',
                        'qty'       => (float)$r['stock_qty'],
                        'cost'      => (float)$r['cost_price'],
                        'val'       => $val,
                    ];
                    $totVal += $val;
                }

                // GL Inventory Asset Reconciliation Check
                $stmtGl = $pdo->query("
                    SELECT COALESCE(SUM(jl.debit - jl.credit), 0) as gl_val 
                    FROM journal_lines jl 
                    JOIN chart_of_accounts c ON jl.account_id = c.account_id
                    JOIN journal_entries je ON jl.je_id = je.je_id AND je.status = 'POSTED'
                    WHERE c.account_code = '1030'
                ");
                $glVal = (float)($stmtGl->fetchColumn() ?: 0.0);
                $diff  = abs($totVal - $glVal);
                $isReconciled = ($diff < 0.05);

                $summary = [
                    'subledger_value' => $totVal,
                    'gl_value'        => $glVal,
                    'difference'      => $diff,
                    'is_reconciled'   => $isReconciled
                ];

                return new ReportResult($spec->key, $spec->title, $spec->columns, $rows, $summary, $summary);
            }
        ));

        // 9. Inventory Valuation Detail Spec
        $this->registerSpec(new ReportSpec(
            'inventory_valuation_detail',
            'Inventory Valuation Detail',
            'inventory_ledger',
            ['date' => 'Date', 'txn' => 'Transaction #', 'qty_in' => 'Qty In', 'qty_out' => 'Qty Out', 'cost' => 'Unit Cost', 'bal_qty' => 'Balance Qty', 'bal_val' => 'Balance Value'],
            ['item_id', 'location_id', 'date_from', 'date_to']
        ));

        // 10. Inventory Turnover & DIO Spec
        $this->registerSpec(new ReportSpec(
            'inventory_turnover',
            'Inventory Turnover & Days Inventory Outstanding (DIO)',
            'inventory_ledger',
            ['sku' => 'SKU', 'name' => 'Item Name', 'cogs' => 'Period COGS', 'avg_val' => 'Avg Inventory Val', 'turnover' => 'Turnover Ratio', 'dio' => 'DIO (Days)'],
            ['date_from', 'date_to']
        ));

        // 11. Sales by Item / Sales by Customer Spec
        $this->registerSpec(new ReportSpec(
            'sales_by_item',
            'Sales by Item & Profitability',
            'journal_lines',
            ['name' => 'Item Name', 'qty' => 'Qty Sold', 'revenue' => 'Revenue', 'cogs' => 'COGS', 'margin' => 'Gross Margin', 'pct' => 'Margin %'],
            ['date_from', 'date_to']
        ));

        // 12. Purchases by Vendor Spec
        $this->registerSpec(new ReportSpec(
            'purchases_by_vendor',
            'Purchases by Vendor Report',
            'journal_lines',
            ['vendor' => 'Vendor Name', 'bills' => 'Bill Count', 'amount' => 'Total Purchased (NPR)'],
            ['date_from', 'date_to']
        ));
    }

    /**
     * Presentation Output Formatters (JSON, CSV, HTML Table)
     */
    public function export(ReportResult $result, string $format = 'html'): string
    {
        if ($format === 'json') {
            return json_encode([
                'title'      => $result->title,
                'columns'    => $result->columns,
                'rows'       => $result->rows,
                'totals'     => $result->totals,
                'summary'    => $result->summary,
                'printed_at' => $result->printedAt
            ], JSON_PRETTY_PRINT);
        }

        if ($format === 'csv') {
            $output = fopen('php://temp', 'r+');
            fputcsv($output, array_values($result->columns));
            foreach ($result->rows as $r) {
                fputcsv($output, array_intersect_key($r, $result->columns));
            }
            rewind($output);
            $csv = stream_get_contents($output);
            fclose($output);
            return $csv;
        }

        // Default HTML Table output
        $html = '<div class="ns-portlet"><div class="ns-portlet-content">';
        $html .= '<h3>' . htmlspecialchars($result->title) . '</h3>';
        $html .= '<table class="ns-table"><thead><tr>';
        foreach ($result->columns as $colLabel) {
            $html .= '<th>' . htmlspecialchars($colLabel) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($result->rows as $r) {
            $html .= '<tr>';
            foreach (array_keys($result->columns) as $colKey) {
                $val = $r[$colKey] ?? '';
                $valStr = is_numeric($val) ? number_format((float)$val, 2) : htmlspecialchars((string)$val);
                $html .= '<td style="' . (is_numeric($val) ? 'text-align:right' : '') . '">' . $valStr . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table></div></div>';
        return $html;
    }
}
