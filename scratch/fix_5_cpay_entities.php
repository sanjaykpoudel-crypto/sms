<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();
$pdo = $db->getConnection();

$pdo->beginTransaction();

$map = [
    220 => 11,
    69  => 11,
    85  => 19,
    171 => 18,
    193 => 19
];

foreach ($map as $id => $cust_id) {
    $pdo->prepare("UPDATE transaction_headers SET party_id = ?, party_type = 'customer' WHERE id = ?")->execute([$cust_id, $id]);
    $pdo->prepare("
        UPDATE journal_lines jl 
        JOIN journal_entries je ON jl.je_id = je.je_id 
        SET jl.entity_type = 'CUSTOMER', jl.entity_id = ? 
        WHERE je.transaction_id = ? AND jl.account_id = 6
    ")->execute([$cust_id, $id]);
}

$pdo->commit();
echo "Successfully updated 5 customer payment entities!\n";
