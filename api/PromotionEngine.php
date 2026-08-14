<?php
/**
 * api/PromotionEngine.php
 * Centralized Promotion Engine for MNS Liquor ERP.
 * Handles promotion evaluation, MRP vs Selling Price discounts, location scoping,
 * date/time authoritative server validation, priority rules, and price calculation.
 */

if (!class_exists('PromotionException')) {
    class PromotionException extends Exception {}
}

class PromotionEngine
{
    private static $instance = null;
    private $pdo;

    private function __construct()
    {
        $db = DBConnection::getInstance();
        $this->pdo = $db->getConnection();
    }

    public static function getInstance(): PromotionEngine
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Derive actual dynamic status based on datetime window
     */
    public function derivePromotionStatus(array $promo, string $currentDatetime = null): string
    {
        if (!empty($promo['is_deleted']) || ($promo['status'] ?? '') === 'inactive') {
            return 'inactive';
        }
        if (($promo['status'] ?? '') === 'draft') {
            return 'draft';
        }

        $now = $currentDatetime ? strtotime($currentDatetime) : time();
        $start = strtotime($promo['start_datetime']);
        $end = strtotime($promo['end_datetime']);

        if ($now < $start) {
            return 'scheduled';
        }
        if ($now > $end) {
            return 'expired';
        }
        return 'active';
    }

    /**
     * Get all active promotions for a specific location at a specific datetime
     */
    public function getActivePromotionsForLocation($locationId, string $datetime = null): array
    {
        $rawLoc = $locationId ?: (function_exists('get_user_default_location_id') ? get_user_default_location_id() : 1);
        $locationId = function_exists('resolve_location_id') ? resolve_location_id($rawLoc) : (is_numeric($rawLoc) ? (int)$rawLoc : 1);
        $targetDt = $datetime ?: date('Y-m-d H:i:s');

        $stmt = $this->pdo->prepare("
            SELECT DISTINCT p.* 
            FROM promotions p
            LEFT JOIN promotion_locations pl ON pl.promotion_id = p.id
            WHERE p.is_deleted = 0 
              AND p.status = 'active'
              AND ? BETWEEN p.start_datetime AND p.end_datetime
              AND (p.applies_to_locations = 'all' OR pl.location_id = ?)
            ORDER BY p.priority DESC, p.created_at DESC
        ");
        $stmt->execute([$targetDt, $locationId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Evaluate the single best applicable promotion for an item at a location
     */
    public function evaluateItemPromotion($itemId, $locationId, float $quantity = 1.0, float $mrp = null, float $sellingPrice = null, string $datetime = null): array
    {
        $rawLoc = $locationId ?: (function_exists('get_user_default_location_id') ? get_user_default_location_id() : 1);
        $locationId = function_exists('resolve_location_id') ? resolve_location_id($rawLoc) : (is_numeric($rawLoc) ? (int)$rawLoc : 1);
        $targetDt = $datetime ?: date('Y-m-d H:i:s');

        // Fetch master item details if prices not supplied
        if ($mrp === null || $sellingPrice === null) {
            $stmtItem = $this->pdo->prepare("
                SELECT 
                    mrp, 
                    COALESCE(ib.selling_price, i.selling_price) as selling_price
                FROM items i
                LEFT JOIN inventory_balances ib ON ib.item_id = i.id AND ib.location_id = ?
                WHERE i.id = ?
            ");
            $stmtItem->execute([$locationId, $itemId]);
            $itemRow = $stmtItem->fetch(PDO::FETCH_ASSOC);

            if ($itemRow) {
                if ($mrp === null) $mrp = (float)($itemRow['mrp'] ?? 0.0);
                if ($sellingPrice === null) $sellingPrice = (float)($itemRow['selling_price'] ?? 0.0);
            } else {
                $mrp = $mrp ?: 0.0;
                $sellingPrice = $sellingPrice ?: 0.0;
            }
        }

        // Query active promotions covering this item and location
        $stmt = $this->pdo->prepare("
            SELECT 
                p.*,
                pi.override_discount_type,
                pi.override_discount_value
            FROM promotions p
            JOIN promotion_items pi ON pi.promotion_id = p.id
            LEFT JOIN promotion_locations pl ON pl.promotion_id = p.id
            WHERE pi.item_id = ?
              AND p.is_deleted = 0
              AND p.status = 'active'
              AND ? BETWEEN p.start_datetime AND p.end_datetime
              AND (p.applies_to_locations = 'all' OR pl.location_id = ?)
              AND p.min_qty <= ?
              AND (p.max_qty IS NULL OR p.max_qty = 0 OR p.max_qty >= ?)
            ORDER BY p.priority DESC, p.created_at DESC
        ");
        $stmt->execute([$itemId, $targetDt, $locationId, $quantity, $quantity]);
        $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($candidates)) {
            return [
                'has_promotion' => false,
                'promotion' => null,
                'mrp' => $mrp,
                'normal_selling_price' => $sellingPrice,
                'promotional_selling_price' => $sellingPrice,
                'discount_amount_per_unit' => 0.0,
                'total_discount_amount' => 0.0
            ];
        }

        // Pick the highest priority promotion
        $best = $candidates[0];

        $discBasis = $best['discount_basis'] ?? 'mrp';
        $discType = !empty($best['override_discount_type']) ? $best['override_discount_type'] : ($best['discount_type'] ?? 'percentage');
        $discVal = !empty($best['override_discount_value']) ? (float)$best['override_discount_value'] : (float)($best['discount_value'] ?? 0.0);

        $basePrice = ($discBasis === 'mrp' && $mrp > 0) ? $mrp : $sellingPrice;

        $discountAmt = 0.0;
        if ($discType === 'percentage') {
            $discountAmt = round($basePrice * ($discVal / 100.0), 2);
        } else {
            $discountAmt = round($discVal, 2);
        }

        // Cap discount so price does not go below zero
        if ($discountAmt > $basePrice) {
            $discountAmt = $basePrice;
        }

        $promoSellingPrice = max(0.0, round($basePrice - $discountAmt, 2));
        $actualDiscountUnit = max(0.0, round($sellingPrice - $promoSellingPrice, 2));

        return [
            'has_promotion' => true,
            'promotion' => [
                'id' => (int)$best['id'],
                'promo_code' => $best['promo_code'],
                'name' => $best['name'],
                'discount_basis' => $discBasis,
                'discount_type' => $discType,
                'discount_value' => $discVal,
                'priority' => (int)$best['priority']
            ],
            'mrp' => $mrp,
            'normal_selling_price' => $sellingPrice,
            'promotional_selling_price' => $promoSellingPrice,
            'discount_amount_per_unit' => $actualDiscountUnit,
            'total_discount_amount' => round($actualDiscountUnit * $quantity, 2)
        ];
    }
}
