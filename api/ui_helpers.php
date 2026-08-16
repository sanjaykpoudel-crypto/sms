<?php
/**
 * Centralized UI Component & Dropdown Engine with Microsecond Performance Calculation
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../database/DBConnection.php';
require_once __DIR__ . '/reference_helper.php';

if (!function_exists('render_select_dropdown')) {

    /**
     * Renders a complete <select> dropdown with uniform disabled hidden placeholder & load time metric.
     * 
     * @param string $name Name and ID attribute
     * @param string $type Data type ('location', 'from_location', 'to_location', 'bank_account', 'from_account', 'to_account', 'account', 'customer', 'vendor', 'item')
     * @param string|null $selected_id Currently selected option value
     * @param string|null $placeholder Custom placeholder label (defaults to '-- Select ... --')
     * @param string $extra_attrs Extra HTML attributes (class, required, style, onchange, etc.)
     * @return string Complete HTML <select> element string
     */
    function render_select_dropdown($name, $type, $selected_id = '', $placeholder = null, $extra_attrs = 'class="ns-select"') {
        $t_start = microtime(true);
        $db = db();

        // Standardized Placeholder Labels
        if (empty($placeholder)) {
            $labels = [
                'location'         => '-- Select Location --',
                'from_location'    => '-- Select Source Location --',
                'to_location'      => '-- Select Destination Location --',
                'bank_account'     => '-- Select Bank / Cash Account --',
                'from_account'     => '-- Select Source Account --',
                'to_account'       => '-- Select Destination Account --',
                'account'          => '-- Select Account --',
                'customer'         => '-- Select Customer --',
                'vendor'           => '-- Select Vendor --',
                'item'             => '-- Select Item --',
                'party'            => '-- Select Party --'
            ];
            $placeholder = $labels[$type] ?? '-- Select --';
        }

        // Query Items
        $items = [];
        switch ($type) {
            case 'location':
            case 'from_location':
            case 'to_location':
                foreach (get_active_locations() as $loc) {
                    $items[] = [
                        'id' => $loc['id'],
                        'label' => $loc['name'] . (!empty($loc['is_default']) ? ' (Default)' : '')
                    ];
                }
                break;

            case 'bank_account':
            case 'from_account':
            case 'to_account':
                $raw = $db->fetchAll("
                    SELECT id, account_name, account_subtype 
                    FROM accounts 
                    WHERE (account_subtype IN ('Bank', 'Cash', 'Liquid Assets') OR (account_type = 'asset' AND (account_name LIKE '%Cash%' OR account_name LIKE '%Bank%' OR account_name LIKE '%Hand%'))) 
                      AND is_active = 1 AND is_deleted = 0 
                    ORDER BY CASE WHEN account_subtype = 'Cash' OR account_name LIKE '%Cash%' THEN 0 ELSE 1 END, account_name ASC
                ");
                foreach ($raw as $r) {
                    $items[] = ['id' => $r['id'], 'label' => $r['account_name']];
                }
                break;

            case 'account':
                $raw = $db->fetchAll("SELECT id, account_name FROM accounts WHERE is_active = 1 AND is_deleted = 0 ORDER BY account_name ASC");
                foreach ($raw as $r) {
                    $items[] = ['id' => $r['id'], 'label' => $r['account_name']];
                }
                break;

            case 'customer':
                $raw = $db->fetchAll("SELECT id, full_name FROM customers WHERE is_active = 1 AND is_deleted = 0 ORDER BY full_name ASC");
                foreach ($raw as $r) {
                    $items[] = ['id' => $r['id'], 'label' => $r['full_name']];
                }
                break;

            case 'vendor':
                $raw = $db->fetchAll("SELECT id, company_name FROM vendors WHERE is_active = 1 AND is_deleted = 0 ORDER BY company_name ASC");
                foreach ($raw as $r) {
                    $items[] = ['id' => $r['id'], 'label' => $r['company_name']];
                }
                break;

            case 'item':
                $raw = $db->fetchAll("SELECT id, item_name, sku FROM items WHERE is_active = 1 AND is_deleted = 0 ORDER BY item_name ASC");
                foreach ($raw as $r) {
                    $items[] = ['id' => $r['id'], 'label' => $r['item_name'] . ($r['sku'] ? ' (' . $r['sku'] . ')' : '')];
                }
                break;
        }

        // Calculate Loading Time (Milliseconds)
        $load_time_ms = round((microtime(true) - $t_start) * 1000, 3);

        // Render HTML
        $is_selected_empty = empty($selected_id);
        $html = '<select name="' . htmlspecialchars($name) . '" id="' . htmlspecialchars($name) . '" data-load-time-ms="' . $load_time_ms . '" ' . $extra_attrs . '>';
        $html .= '<option value="" disabled ' . ($is_selected_empty ? 'selected' : '') . ' hidden>' . htmlspecialchars($placeholder) . '</option>';

        foreach ($items as $item) {
            $sel = ($selected_id == $item['id']) ? 'selected' : '';
            $html .= '<option value="' . htmlspecialchars($item['id']) . '" ' . $sel . '>' . htmlspecialchars($item['label']) . '</option>';
        }

        $html .= '</select>';
        return $html;
    }
}
