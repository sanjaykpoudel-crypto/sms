<?php
require_once __DIR__ . '/../database/DBConnection.php';
require_once __DIR__ . '/../api/AccountingEngine.php';
require_once __DIR__ . '/../api/reference_helper.php';

$db = DBConnection::getInstance();
$pdo = $db->getConnection();
$engine = AccountingEngine::getInstance();

function balance_gl_lines($lines) {
    if (empty($lines)) return $lines;
    $totDebit = 0.0;
    $totCredit = 0.0;
    foreach ($lines as $l) {
        $totDebit += round((float)$l['debit'], 2);
        $totCredit += round((float)$l['credit'], 2);
    }
    $diff = round($totDebit - $totCredit, 2);
    if (abs($diff) >= 0.01 && abs($diff) < 500) {
        if ($diff > 0) {
            // Debits > Credits -> add $diff to credit of first credit line
            $fixed = false;
            foreach ($lines as &$l) {
                if ($l['credit'] > 0) {
                    $l['credit'] = round($l['credit'] + $diff, 2);
                    $fixed = true;
                    break;
                }
            }
            if (!$fixed && !empty($lines)) {
                $lines[0]['credit'] = round(($lines[0]['credit'] ?? 0) + $diff, 2);
            }
        } else {
            // Credits > Debits -> add abs($diff) to debit of first debit line
            $fixed = false;
            foreach ($lines as &$l) {
                if ($l['debit'] > 0) {
                    $l['debit'] = round($l['debit'] + abs($diff), 2);
                    $fixed = true;
                    break;
                }
            }
            if (!$fixed && !empty($lines)) {
                $lines[0]['debit'] = round(($lines[0]['debit'] ?? 0) + abs($diff), 2);
            }
        }
    }
    return $lines;
}

echo "====================================================================\n";
echo " FULL GL REBUILD & RECONCILIATION ENGINE\n";
echo "====================================================================\n\n";

// 1. Clear all old journal entries and journal lines
$pdo->exec("SET FOREIGN_KEY_CHECKS=0");
$pdo->exec("TRUNCATE TABLE journal_lines");
$pdo->exec("TRUNCATE TABLE journal_entries");
$pdo->exec("SET FOREIGN_KEY_CHECKS=1");

echo "Cleared journal_entries and journal_lines tables.\n\n";

// 2. Fetch all active transaction headers
$headers = $pdo->query("
    SELECT * FROM transaction_headers
    WHERE is_deleted = 0 AND status NOT IN ('void', 'voided', 'draft')
    ORDER BY txn_date ASC, id ASC
")->fetchAll(PDO::FETCH_ASSOC);

echo "Total Active Headers to Process: " . count($headers) . "\n\n";

$posted_count = 0;
$error_count = 0;

foreach ($headers as $h) {
    $id = $h['id'];
    $txn_number = $h['txn_number'];
    $txn_type = $h['txn_type'];
    $txn_date = $h['txn_date'];
    $location_id = $h['location_id'];
    $party_id = $h['party_id'];

    $gl_lines = [];

    try {
        if ($txn_type === 'customer_invoice') {
            $inv = $db->fetchOne("SELECT * FROM customer_invoices WHERE header_id = ?", [$id]);
            if (!$inv) continue;

            $ar_account = get_effective_account($inv['customer_id'], 'receivable');
            $tax_account = get_accounting_preference('default_tax_account') ?: 13;
            $discount_account = get_accounting_preference('default_discount_account') ?: 36;

            $grand_total = (float)$inv['total_amount'];
            $discount_amount = (float)$inv['discount_amount'];
            $tax_total = (float)$inv['tax_amount'];

            if ($grand_total > 0) {
                $gl_lines[] = ['account_id' => $ar_account, 'debit' => $grand_total, 'credit' => 0, 'entity_type' => 'CUSTOMER', 'entity_id' => $inv['customer_id'], 'location_id' => $location_id];
            }
            if ($discount_amount > 0) {
                $gl_lines[] = ['account_id' => $discount_account, 'debit' => $discount_amount, 'credit' => 0, 'entity_type' => 'NONE', 'location_id' => $location_id];
            }
            if ($tax_total > 0) {
                $gl_lines[] = ['account_id' => $tax_account, 'debit' => 0, 'credit' => $tax_total, 'entity_type' => 'NONE', 'location_id' => $location_id];
            }

            $lines = $db->fetchAll("SELECT * FROM transaction_lines WHERE header_id = ?", [$id]);
            foreach ($lines as $l) {
                $item_id = $l['item_id'];
                $line_total = (float)$l['line_total'];
                $cogs = (float)$l['cost_price'] * (float)$l['base_qty'];
                if ($cogs == 0) {
                    $cogs = (float)$l['cost_price'] * (float)$l['quantity'];
                }
                $sales_acc = get_effective_account($item_id, 'income');
                $cogs_acc  = get_effective_account($item_id, 'cogs');
                $inv_acc   = get_effective_account($item_id, 'inventory');

                if ($line_total > 0) {
                    $gl_lines[] = ['account_id' => $sales_acc, 'debit' => 0, 'credit' => $line_total, 'entity_type' => 'ITEM', 'entity_id' => $item_id, 'location_id' => $location_id];
                }
                if ($cogs > 0) {
                    $gl_lines[] = ['account_id' => $cogs_acc, 'debit' => $cogs, 'credit' => 0, 'entity_type' => 'ITEM', 'entity_id' => $item_id, 'location_id' => $location_id];
                    $gl_lines[] = ['account_id' => $inv_acc, 'debit' => 0, 'credit' => $cogs, 'entity_type' => 'ITEM', 'entity_id' => $item_id, 'location_id' => $location_id];
                }
            }

            if (!empty($gl_lines)) {
                $gl_lines = balance_gl_lines($gl_lines);
                $engine->postJournalEntry($id, 'SALE', $gl_lines, $txn_date, 'Invoice ' . $txn_number);
                $posted_count++;
            }

        } elseif ($txn_type === 'vendor_bill') {
            $bill = $db->fetchOne("SELECT * FROM vendor_bills WHERE header_id = ?", [$id]);
            if (!$bill) continue;

            $ap_account  = get_effective_account($bill['vendor_id'], 'payable');
            $tax_account = get_accounting_preference('default_tax_account') ?: 13;
            $disc_account= get_accounting_preference('default_discount_account') ?: 36;

            $grand_total = (float)$bill['total_amount'];
            $discount_amount = (float)$bill['discount_amount'];
            $tax_total = (float)$bill['tax_amount'];

            $lines = $db->fetchAll("SELECT * FROM transaction_lines WHERE header_id = ?", [$id]);
            foreach ($lines as $l) {
                $item_id = $l['item_id'];
                $item_cost = (float)$l['quantity'] * (float)$l['unit_price'];
                $inv_acc = get_effective_account($item_id, 'inventory');
                if ($item_cost > 0) {
                    $gl_lines[] = ['account_id' => $inv_acc, 'debit' => $item_cost, 'credit' => 0, 'entity_type' => 'ITEM', 'entity_id' => $item_id, 'location_id' => $location_id];
                }
            }

            if ($tax_total > 0) {
                $gl_lines[] = ['account_id' => $tax_account, 'debit' => $tax_total, 'credit' => 0, 'entity_type' => 'NONE', 'location_id' => $location_id];
            }
            if ($discount_amount > 0) {
                $gl_lines[] = ['account_id' => $disc_account, 'debit' => 0, 'credit' => $discount_amount, 'entity_type' => 'NONE', 'location_id' => $location_id];
            }
            if ($grand_total > 0) {
                $gl_lines[] = ['account_id' => $ap_account, 'debit' => 0, 'credit' => $grand_total, 'entity_type' => 'VENDOR', 'entity_id' => $bill['vendor_id'], 'location_id' => $location_id];
            }

            if (!empty($gl_lines)) {
                $gl_lines = balance_gl_lines($gl_lines);
                $engine->postJournalEntry($id, 'PURCHASE', $gl_lines, $txn_date, 'Bill ' . $txn_number);
                $posted_count++;
            }

        } elseif ($txn_type === 'customer_payment' || $txn_type === 'vendor_payment') {
            $payments = $db->fetchAll("SELECT * FROM payments WHERE header_id = ?", [$id]);
            if (empty($payments)) continue;

            $is_cust = ($txn_type === 'customer_payment');
            $party_acc_type = $is_cust ? 'receivable' : 'payable';
            $party_account  = get_effective_account($party_id, $party_acc_type);
            $total_tendered = array_sum(array_column($payments, 'amount'));

            foreach ($payments as $p) {
                $bank_acc = $p['bank_account_id'];
                $amt = (float)$p['amount'];
                if ($amt <= 0 || empty($bank_acc)) continue;

                $gl_lines[] = [
                    'account_id'  => $bank_acc,
                    'debit'       => $is_cust ? $amt : 0.00,
                    'credit'      => $is_cust ? 0.00 : $amt,
                    'entity_type' => 'NONE',
                    'location_id' => $location_id,
                ];
            }

            if ($total_tendered > 0) {
                $gl_lines[] = [
                    'account_id'  => $party_account,
                    'debit'       => $is_cust ? 0.00 : $total_tendered,
                    'credit'      => $is_cust ? $total_tendered : 0.00,
                    'entity_type' => $is_cust ? 'CUSTOMER' : 'VENDOR',
                    'entity_id'   => $party_id,
                    'location_id' => $location_id,
                ];
            }

            if (!empty($gl_lines)) {
                $gl_lines = balance_gl_lines($gl_lines);
                $engine->postJournalEntry($id, $is_cust ? 'CUSTOMER_PAYMENT' : 'VENDOR_PAYMENT', $gl_lines, $txn_date, 'Payment ' . $txn_number);
                $posted_count++;
            }

        } elseif ($txn_type === 'expense' || $txn_type === 'Expense') {
            $exp = $db->fetchOne("SELECT * FROM expenses WHERE header_id = ?", [$id]);
            if (!$exp) continue;

            $net_amount = (float)$exp['amount'];
            if ($net_amount > 0) {
                $gl_lines = [
                    ['account_id' => $exp['expense_account_id'], 'debit' => $net_amount, 'credit' => 0, 'entity_type' => 'NONE', 'location_id' => $location_id],
                    ['account_id' => $exp['paid_from_account_id'], 'debit' => 0, 'credit' => $net_amount, 'entity_type' => 'NONE', 'location_id' => $location_id]
                ];
                $gl_lines = balance_gl_lines($gl_lines);
                $engine->postJournalEntry($id, 'EXPENSE', $gl_lines, $txn_date, 'Expense ' . $txn_number);
                $posted_count++;
            }
        } elseif ($txn_type === 'account_transfer') {
            $at = $db->fetchOne("SELECT * FROM account_transfers WHERE header_id = ?", [$id]);
            if (!$at) continue;
            $amt = (float)$at['amount'];
            if ($amt > 0) {
                $gl_lines = [
                    ['account_id' => $at['to_account_id'], 'debit' => $amt, 'credit' => 0, 'entity_type' => 'NONE', 'location_id' => $location_id],
                    ['account_id' => $at['from_account_id'], 'debit' => 0, 'credit' => $amt, 'entity_type' => 'NONE', 'location_id' => $location_id]
                ];
                $gl_lines = balance_gl_lines($gl_lines);
                $engine->postJournalEntry($id, 'ACCOUNT_TRANSFER', $gl_lines, $txn_date, 'Fund Transfer ' . $txn_number);
                $posted_count++;
            }
        }
    } catch (Exception $e) {
        $error_count++;
        echo "Error on Txn #{$txn_number} (ID {$id}): " . $e->getMessage() . "\n";
    }
}

echo "GL Rebuild Complete:\n";
echo "  - Posted: {$posted_count}\n";
echo "  - Errors: {$error_count}\n";
