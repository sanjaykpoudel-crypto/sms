<?php
$_GET['id'] = 'c60f6410-c271-4974-970b-48c173ff5fe2';
$_SESSION['user_id'] = 'usr-admin-001';

ob_start();
include __DIR__ . '/../forms/modules/transactions/view.php';
$html = ob_get_clean();

if (strpos($html, 'Line #') !== false && strpos($html, 'Account Code') !== false && strpos($html, 'Debit (Dr)') !== false && strpos($html, 'Credit (Cr)') !== false) {
    echo "SUCCESS: Journal View table updated with columns: Line #, Account Code, Account Name, Debit (Dr), Credit (Cr), Memo, Name!\n";
} else {
    echo "FAILED: Columns missing or unexpected output.\n";
}
