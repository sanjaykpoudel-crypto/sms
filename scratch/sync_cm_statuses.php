<?php
require_once 'database/DBConnection.php';
require_once 'api/reference_helper.php';

$db = db();

echo "=== RECALCULATING PAYMENT STATUS FOR ALL CREDIT MEMOS & VENDOR CREDITS ===\n\n";

$cms = $db->fetchAll("SELECT header_id, memo_number FROM credit_memos");
foreach ($cms as $cm) {
    recalculate_document_payment_status($cm['header_id']);
}

$vcs = $db->fetchAll("SELECT header_id, credit_number FROM vendor_credits");
foreach ($vcs as $vc) {
    recalculate_document_payment_status($vc['header_id']);
}

echo "RECALCULATION COMPLETE.\n\n";

echo "--- CHECKING CM-0002 NOW ---\n";
$th = $db->fetchOne("SELECT id, txn_number, status FROM transaction_headers WHERE txn_number = 'CM-0002'");
print_r($th);

$cm_rec = $db->fetchOne("SELECT memo_number, total_amount, remaining_credit, status FROM credit_memos WHERE memo_number = 'CM-0002'");
print_r($cm_rec);
