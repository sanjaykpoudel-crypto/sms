<?php
/**
 * Cash Book / Bank Book Report — Delegates directly to General Ledger report
 * with bank accounts pre-selected in the multi-select filter bar.
 */
if (empty($_GET['account_id']) && empty($_GET['account_type'])) {
    $_GET['account_type'] = 'bank';
}
require __DIR__ . '/general_ledger_list.php';
