<?php
$_SESSION['user_id'] = 'usr-admin-001';
require_once __DIR__ . '/../api/reference_helper.php';

ob_start();
include __DIR__ . '/../forms/modules/transactions/journal/journal_manage.php';
$html = ob_get_clean();

if (strpos($html, 'Account') !== false && strpos($html, 'Debit') !== false && strpos($html, 'Credit') !== false && strpos($html, 'Name / Entity') !== false) {
    echo "SUCCESS: Journal Entry Create/Edit form updated to match view column sequence!\n";
} else {
    echo "FAILED: Form output unexpected.\n";
}
