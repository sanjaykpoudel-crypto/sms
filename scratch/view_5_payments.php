<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();

$ids = [220, 69, 85, 171, 193];
foreach ($ids as $id) {
    print_r($db->fetchOne("SELECT * FROM transaction_headers WHERE id = ?", [$id]));
}
