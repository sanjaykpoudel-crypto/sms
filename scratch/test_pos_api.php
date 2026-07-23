<?php
session_start();
$_SESSION['user_id'] = 'usr-admin-001';

ob_start();
include __DIR__ . '/../api/get_pos_items.php';
$output = ob_get_clean();

$data = json_decode($output, true);
echo "Status: " . ($data['status'] ?? 'failed') . "\n";
echo "Total items returned: " . (isset($data['items']) ? count($data['items']) : 0) . "\n";

if (!empty($data['items'])) {
    echo "Sample item (first 3):\n";
    for ($i = 0; $i < min(3, count($data['items'])); $i++) {
        $item = $data['items'][$i];
        echo " - {$item['sku']} ({$item['item_name']}): Stock={$item['current_stock']}, Cost=Rs {$item['cost_price']}, Sell=Rs {$item['selling_price']}\n";
    }
}
