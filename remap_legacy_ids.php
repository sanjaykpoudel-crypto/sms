<?php
require_once 'database/DBConnection.php';
$db = db();

echo "=== MAPPING LEGACY UUIDs TO NEW IDs VIA AUDIT LOGS & MATCHING ===\n\n";

// Get all unmatched UUID party_ids in journal_entries
$unmatched_jes = $db->fetchAll("
    SELECT DISTINCT party_id, party_type 
    FROM journal_entries 
    WHERE party_id IS NOT NULL AND party_id != '' 
      AND party_id NOT IN (SELECT CAST(id AS CHAR) FROM customers) 
      AND party_id NOT IN (SELECT CAST(id AS CHAR) FROM vendors)
");

$customer_map = [];
$vendor_map = [];

foreach ($unmatched_jes as $row) {
    $old_uuid = $row['party_id'];
    $ptype = $row['party_type'];

    if ($ptype === 'customer' || empty($ptype)) {
        // Look up audit logs for this UUID
        $audit = $db->fetchOne("
            SELECT * FROM audit_logs 
            WHERE record_id = ? AND table_name = 'customers'
            ORDER BY created_at DESC LIMIT 1
        ", [$old_uuid]);

        if ($audit) {
            $old_data = json_decode($audit['old_values'], true) ?: json_decode($audit['new_values'], true);
            $full_name = $old_data['full_name'] ?? '';
            $code = $old_data['customer_code'] ?? '';
            $phone = $old_data['phone'] ?? '';

            // Match current customer
            $cur = null;
            if ($code) {
                $cur = $db->fetchOne("SELECT id, full_name, customer_code FROM customers WHERE customer_code = ? AND is_deleted = 0", [$code]);
            }
            if (!$cur && $full_name) {
                $cur = $db->fetchOne("SELECT id, full_name, customer_code FROM customers WHERE full_name = ? AND is_deleted = 0", [$full_name]);
            }
            if (!$cur && $phone) {
                $cur = $db->fetchOne("SELECT id, full_name, customer_code FROM customers WHERE phone = ? AND is_deleted = 0", [$phone]);
            }

            if ($cur) {
                $customer_map[$old_uuid] = $cur;
                echo sprintf("CUSTOMER MATCH: UUID '%s' -> New ID '%s' (%s - %s)\n", $old_uuid, $cur['id'], $cur['full_name'], $cur['customer_code']);
            } else {
                echo sprintf("CUSTOMER UNMATCHED: UUID '%s' (Audit Name: '%s', Code: '%s')\n", $old_uuid, $full_name, $code);
            }
        } else {
            echo sprintf("NO AUDIT LOG FOR CUSTOMER UUID '%s'\n", $old_uuid);
        }
    }

    if ($ptype === 'vendor' || empty($ptype)) {
        // Look up audit logs for this vendor UUID
        $audit = $db->fetchOne("
            SELECT * FROM audit_logs 
            WHERE record_id = ? AND table_name = 'vendors'
            ORDER BY created_at DESC LIMIT 1
        ", [$old_uuid]);

        if ($audit) {
            $old_data = json_decode($audit['old_values'], true) ?: json_decode($audit['new_values'], true);
            $comp_name = $old_data['company_name'] ?? $old_data['full_name'] ?? '';
            $code = $old_data['vendor_code'] ?? '';
            $phone = $old_data['phone'] ?? '';

            $cur = null;
            if ($code) {
                $cur = $db->fetchOne("SELECT id, company_name, vendor_code FROM vendors WHERE vendor_code = ? AND is_deleted = 0", [$code]);
            }
            if (!$cur && $comp_name) {
                $cur = $db->fetchOne("SELECT id, company_name, vendor_code FROM vendors WHERE company_name = ? AND is_deleted = 0", [$comp_name]);
            }
            if (!$cur && $phone) {
                $cur = $db->fetchOne("SELECT id, company_name, vendor_code FROM vendors WHERE phone = ? AND is_deleted = 0", [$phone]);
            }

            if ($cur) {
                $vendor_map[$old_uuid] = $cur;
                echo sprintf("VENDOR MATCH: UUID '%s' -> New ID '%s' (%s - %s)\n", $old_uuid, $cur['id'], $cur['company_name'], $cur['vendor_code']);
            } else {
                echo sprintf("VENDOR UNMATCHED: UUID '%s' (Audit Name: '%s', Code: '%s')\n", $old_uuid, $comp_name, $code);
            }
        } else {
            echo sprintf("NO AUDIT LOG FOR VENDOR UUID '%s'\n", $old_uuid);
        }
    }
}
