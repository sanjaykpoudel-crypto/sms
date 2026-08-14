<?php
/**
 * Central Unit Conversion Engine
 * Manages conversions between Packaging Units (CASE / BOX) and Base Units (PCS)
 */
require_once __DIR__ . '/../database/DBConnection.php';

if (!function_exists('uce_get_item_packaging')) {
    /**
     * Fetch packaging & unit metadata for a given item
     */
    function uce_get_item_packaging($item_id_or_row) {
        if (is_array($item_id_or_row)) {
            $item = $item_id_or_row;
        } else {
            $db = db();
            $item = $db->fetchOne("SELECT id, unit_type, units_per_case, case_unit_name, cost_price, selling_price, case_purchase_price, case_selling_price, case_barcode FROM items WHERE id = ?", [(int)$item_id_or_row]);
        }

        if (!$item) {
            return [
                'base_unit'           => 'PCS',
                'case_unit'           => 'CASE',
                'box_unit'            => 'CASE',
                'conversion_factor'   => 1.0,
                'pcs_cost_price'      => 0.0,
                'pcs_selling_price'   => 0.0,
                'case_purchase_price' => 0.0,
                'case_selling_price'  => 0.0,
                'case_barcode'        => '',
            ];
        }

        $conv = !empty($item['units_per_case']) && (int)$item['units_per_case'] > 0 ? (int)$item['units_per_case'] : 1;
        $pcs_cost = (float)($item['cost_price'] ?? 0);
        $pcs_sell = (float)($item['selling_price'] ?? 0);

        $case_cost = !empty($item['case_purchase_price']) && (float)$item['case_purchase_price'] > 0 
                     ? (float)$item['case_purchase_price'] 
                     : round($pcs_cost * $conv, 2);

        $case_sell = !empty($item['case_selling_price']) && (float)$item['case_selling_price'] > 0 
                     ? (float)$item['case_selling_price'] 
                     : round($pcs_sell * $conv, 2);

        $unit_name = !empty($item['case_unit_name']) ? $item['case_unit_name'] : 'CASE';

        $base_unit = 'PCS';
        if (!empty($item['unit_type'])) {
            if (is_numeric($item['unit_type'])) {
                $db = db();
                $ref = $db->fetchOne("SELECT name FROM reference_codes WHERE id = ? AND type = 'units'", [(int)$item['unit_type']]);
                if ($ref && !empty($ref['name'])) {
                    $base_unit = $ref['name'];
                }
            } else {
                $base_unit = $item['unit_type'];
            }
        }

        return [
            'base_unit'           => $base_unit,
            'case_unit'           => $unit_name,
            'box_unit'            => $unit_name,
            'conversion_factor'   => $conv,
            'pcs_cost_price'      => $pcs_cost,
            'pcs_selling_price'   => $pcs_sell,
            'case_purchase_price' => $case_cost,
            'case_selling_price'  => $case_sell,
            'box_purchase_price'  => $case_cost,
            'box_selling_price'   => $case_sell,
            'case_barcode'        => $item['case_barcode'] ?? '',
            'box_barcode'         => $item['case_barcode'] ?? '',
        ];
    }
}

if (!function_exists('uce_resolve_unit')) {
    /**
     * Resolve unit info for a specific item and requested unit string (e.g. 'CASE' vs 'PCS')
     */
    function uce_resolve_unit($item_id_or_row, string $requested_unit = 'PCS'): array {
        $meta = uce_get_item_packaging($item_id_or_row);
        $req = strtoupper(trim($requested_unit));
        $case_name = strtoupper(trim($meta['case_unit']));

        if ($req === $case_name || $req === 'CASE' || $req === 'BOX') {
            return [
                'unit_name'          => $meta['case_unit'],
                'conversion_factor'  => $meta['conversion_factor'],
                'is_case'            => true,
                'is_box'             => true,
                'default_unit_cost'  => $meta['case_purchase_price'],
                'default_unit_price' => $meta['case_selling_price'],
            ];
        }

        return [
            'unit_name'          => $meta['base_unit'],
            'conversion_factor'  => 1.0,
            'is_case'            => false,
            'is_box'             => false,
            'default_unit_cost'  => $meta['pcs_cost_price'],
            'default_unit_price' => $meta['pcs_selling_price'],
        ];
    }
}

if (!function_exists('uce_calculate_base_qty')) {
    /**
     * Calculate base unit quantity (PCS) from transaction quantity and conversion factor
     */
    function uce_calculate_base_qty(float $txn_qty, float $conversion_factor = 1.0): float {
        $factor = $conversion_factor > 0 ? $conversion_factor : 1.0;
        return round($txn_qty * $factor, 4);
    }
}

if (!function_exists('uce_calculate_base_unit_cost')) {
    /**
     * Calculate per-base-unit cost from total line amount and base quantity
     */
    function uce_calculate_base_unit_cost(float $line_total, float $base_qty): float {
        if ($base_qty <= 0) return 0.0;
        return round($line_total / $base_qty, 4);
    }
}
