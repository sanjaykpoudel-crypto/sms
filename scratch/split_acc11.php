<?php
require_once 'database/DBConnection.php';
$db = db();
$pdo = $db->getConnection();

try {
    $pdo->beginTransaction();

    // 1. Create 'Loan - Prabhu Bank' account
    $stmt1 = $pdo->prepare("
        INSERT INTO accounts (account_name, account_type_id, account_type, account_subtype, normal_balance, currency, is_active, created_at, updated_at)
        VALUES ('Loan - Prabhu Bank', 10, 'liability', 'Other Current Liability', 'credit', 'NPR', 1, NOW(), NOW())
    ");
    $stmt1->execute();
    $prabhu_acc_id = $pdo->lastInsertId();

    // 2. Create 'Credit Card - Nabil Bank' account
    $stmt2 = $pdo->prepare("
        INSERT INTO accounts (account_name, account_type_id, account_type, account_subtype, normal_balance, currency, is_active, created_at, updated_at)
        VALUES ('Credit Card - Nabil Bank', 10, 'liability', 'Other Current Liability', 'credit', 'NPR', 1, NOW(), NOW())
    ");
    $stmt2->execute();
    $nabil_acc_id = $pdo->lastInsertId();

    echo "Created account 'Loan - Prabhu Bank' ID: {$prabhu_acc_id}\n";
    echo "Created account 'Credit Card - Nabil Bank' ID: {$nabil_acc_id}\n";

    // 3. Reassign Txn #231 (Prabhu Bank Loan: 26,792.00)
    $stmt3 = $pdo->prepare("
        UPDATE journal_entries
        SET account_id = ?
        WHERE account_id = '11' AND amount = 26792.00
    ");
    $stmt3->execute([$prabhu_acc_id]);
    $c3 = $stmt3->rowCount();
    echo "Reassigned Prabhu Bank Loan journal entries: {$c3}\n";

    // 4. Reassign Txn #30 (Nabil Credit Card: 33,448.00)
    $stmt4 = $pdo->prepare("
        UPDATE journal_entries
        SET account_id = ?
        WHERE account_id = '11' AND amount = 33448.00
    ");
    $stmt4->execute([$nabil_acc_id]);
    $c4 = $stmt4->rowCount();
    echo "Reassigned Nabil Credit Card journal entries: {$c4}\n";

    $pdo->commit();
    echo "\nSUCCESSFULLY SPLIT ACCOUNT ID 11 INTO DEDICATED LOAN & CREDIT CARD ACCOUNTS!\n";

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "ERROR: " . $e->getMessage() . "\n";
}
