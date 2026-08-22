<?php
require_once __DIR__ . '/../database/DBConnection.php';
require_once __DIR__ . '/../api/AccountingEngine.php';
$db = db();
$engine = AccountingEngine::getInstance();

// Check if customer 21 statement works with a sample journal line
$loc_sql = " AND th.location_id = 1 ";
$customer_id = 21;
$from_date = '2026-06-01';
$to_date = '2026-08-22';

$journals = $db->fetchAll("
    SELECT th.txn_date as date, 
           th.txn_number as number, 
           CASE 
               WHEN UPPER(COALESCE(je.memo, th.memo)) LIKE '%DEBIT NOTE%' OR LOWER(th.txn_type) = 'debit_note' THEN 'Debit Note'
               WHEN UPPER(COALESCE(je.memo, th.memo)) LIKE '%CREDIT NOTE%' OR LOWER(th.txn_type) = 'credit_note' THEN 'Credit Note'
               WHEN UPPER(COALESCE(je.memo, th.memo)) LIKE '%OPENING BALANCE%' OR LOWER(th.txn_type) LIKE '%opening%' THEN 'Opening Balance'
               ELSE 'Journal'
           END as type,
           jl.debit as debit,
           jl.credit as credit,
           COALESCE(NULLIF(je.memo, ''), NULLIF(th.memo, ''), 'Journal Entry') as memo,
           '' as applied_to_ref
    FROM journal_lines jl
    JOIN journal_entries je ON jl.je_id = je.je_id
    JOIN transaction_headers th ON je.transaction_id = th.id
    WHERE (jl.entity_id = ? OR th.party_id = ?) 
      AND (UPPER(jl.entity_type) = 'CUSTOMER' OR jl.entity_type IS NULL OR LOWER(th.party_type) = 'customer')
      AND (jl.debit > 0 OR jl.credit > 0)
      AND th.txn_date BETWEEN ? AND ? 
      AND th.status NOT IN ('void', 'voided', 'draft') 
      AND th.is_deleted = 0 
      AND LOWER(th.txn_type) IN ('journal', 'journal_entry', 'opening balance', 'opening_balance', 'debit_note', 'credit_note') {$loc_sql}
    ORDER BY th.txn_date ASC, jl.jl_id ASC
", [$customer_id, $customer_id, $from_date, $to_date]);

echo "Currently found journals for customer $customer_id: " . count($journals) . "\n";
print_r($journals);
