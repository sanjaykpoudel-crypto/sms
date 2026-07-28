<?php
/**
 * Vendor / Supplier Balance Confirmation Report Forwarder
 * Delegates to the unified Balance Confirmation Report engine.
 */
if (!isset($_GET['confirmation_type']) && !isset($_GET['type'])) {
    $_GET['confirmation_type'] = 'supplier';
}
if (isset($_GET['vendor_id']) && !isset($_GET['party_id'])) {
    $_GET['party_id'] = $_GET['vendor_id'];
}

require_once __DIR__ . '/../customers/balance_confirmation_list.php';
