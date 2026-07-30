<?php
require_once 'database/DBConnection.php';
$pdo = db()->getConnection();

$gokarna_id = 'loc-main-retail';

echo "--- UPDATING TRANSACTION HEADERS ---\n";
$stmt = $pdo->prepare("UPDATE transaction_headers SET location_id = ? WHERE location_id IS NULL OR location_id != ?");
$stmt->execute([$gokarna_id, $gokarna_id]);
$cnt = $stmt->rowCount();
echo "Updated $cnt transaction headers to Gokarna ('$gokarna_id').\n";

echo "--- UPDATING USERS DEFAULT LOCATION ---\n";
$stmt = $pdo->prepare("UPDATE users SET location_id = ? WHERE location_id IS NULL OR location_id != ?");
$stmt->execute([$gokarna_id, $gokarna_id]);
echo "Updated " . $stmt->rowCount() . " users.\n";

echo "--- UPDATING DEFAULT LOCATION IN SYSTEM INFO ---\n";
$pdo->exec("INSERT INTO system_info (meta_field, meta_value) VALUES ('default_location_id', '$gokarna_id') ON DUPLICATE KEY UPDATE meta_value = '$gokarna_id'");
echo "System info default location set to Gokarna ('$gokarna_id').\n";

// Consolidate inventory balances to Gokarna if any non-gokarna entries exist
$non_gokarna_inv = $pdo->query("SELECT * FROM inventory_balances WHERE location_id != '$gokarna_id'")->fetchAll(PDO::FETCH_ASSOC);
if (!empty($non_gokarna_inv)) {
    foreach ($non_gokarna_inv as $row) {
        $item_id = $row['item_id'];
        $qty = (float)$row['quantity_on_hand'];
        $avail = (float)$row['available_qty'];
        // Check if gokarna row exists
        $g_row = $pdo->query("SELECT * FROM inventory_balances WHERE item_id = '$item_id' AND location_id = '$gokarna_id'")->fetch();
        if ($g_row) {
            $pdo->exec("UPDATE inventory_balances SET quantity_on_hand = quantity_on_hand + $qty, available_qty = available_qty + $avail WHERE item_id = '$item_id' AND location_id = '$gokarna_id'");
            $pdo->exec("DELETE FROM inventory_balances WHERE id = '{$row['id']}'");
        } else {
            $pdo->exec("UPDATE inventory_balances SET location_id = '$gokarna_id' WHERE id = '{$row['id']}'");
        }
    }
    echo "Merged " . count($non_gokarna_inv) . " inventory balance records into Gokarna.\n";
} else {
    echo "All inventory balances are already assigned to Gokarna.\n";
}

echo "\n--- FINAL VERIFICATION: TRANSACTION HEADERS BY LOCATION ---\n";
$stmt = $pdo->query("SELECT h.location_id, l.name, COUNT(*) as count FROM transaction_headers h LEFT JOIN locations l ON h.location_id = l.id GROUP BY h.location_id");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
