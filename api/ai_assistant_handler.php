<?php
/**
 * api/ai_assistant_handler.php
 * AI-Assisted ERP Transaction Parser & Search Assistant for MNS Liquor ERP.
 * Strictly enforces Human-in-the-Loop confirmation rules:
 * - Parses natural language prompts (e.g. "Bought 20 cases of Gorkha beer from ABC supplier for 45,000")
 * - Extracts structured transaction fields (Party, Items, Quantities, Rates, Account)
 * - Returns candidate draft payload for UI user review & manual confirmation
 * - NEVER bypasses accounting rules, permissions, or direct GL posting without user confirmation.
 */

if (session_status() === PHP_SESSION_NONE) { session_start(); }
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access. Please log in.']);
    exit;
}

require_once __DIR__ . '/../database/DBConnection.php';

$rawInput = trim($_POST['prompt'] ?? $_GET['prompt'] ?? '');
if (empty($rawInput)) {
    echo json_encode(['status' => 'error', 'message' => 'Prompt text is required.']);
    exit;
}

$db = db();

try {
    $lower = strtolower($rawInput);

    // AI Intent Classification
    if (str_contains($lower, 'bought') || str_contains($lower, 'purchase') || str_contains($lower, 'supplier')) {
        $intent = 'vendor_bill';
    } elseif (str_contains($lower, 'sold') || str_contains($lower, 'invoice') || str_contains($lower, 'customer')) {
        $intent = 'customer_invoice';
    } elseif (str_contains($lower, 'paid') || str_contains($lower, 'expense') || str_contains($lower, 'bill')) {
        $intent = 'expense';
    } elseif (str_contains($lower, 'received') || str_contains($lower, 'payment')) {
        $intent = 'customer_payment';
    } else {
        $intent = 'search_query';
    }

    // Extraction Logic
    $extracted = [
        'intent' => $intent,
        'raw_prompt' => $rawInput,
        'parsed_data' => [],
        'human_action_required' => true,
        'suggested_route' => ''
    ];

    // Extract numbers (amounts/quantities)
    preg_match_all('/\d+([\.,]\d+)?/', $rawInput, $numMatches);
    $numbers = $numMatches[0] ?? [];

    if ($intent === 'vendor_bill' || $intent === 'customer_invoice') {
        // Try matching item from DB
        $items = $db->fetchAll("SELECT id, item_name, selling_price, cost_price FROM items WHERE is_deleted = 0");
        $matchedItem = null;
        foreach ($items as $item) {
            if (str_contains($lower, strtolower($item['item_name']))) {
                $matchedItem = $item;
                break;
            }
        }

        // Try matching vendor/customer from DB
        $matchedParty = null;
        if ($intent === 'vendor_bill') {
            $vendors = $db->fetchAll("SELECT id, company_name FROM vendors WHERE is_deleted = 0");
            foreach ($vendors as $v) {
                if (str_contains($lower, strtolower($v['company_name']))) {
                    $matchedParty = $v;
                    break;
                }
            }
            $extracted['suggested_route'] = 'index.php?page=transactions/bill/manage';
        } else {
            $customers = $db->fetchAll("SELECT id, full_name FROM customers WHERE is_deleted = 0");
            foreach ($customers as $c) {
                if (str_contains($lower, strtolower($c['full_name']))) {
                    $matchedParty = $c;
                    break;
                }
            }
            $extracted['suggested_route'] = 'index.php?page=transactions/invoice/manage';
        }

        $qty = count($numbers) > 0 ? (float)$numbers[0] : 1;
        $totalAmt = count($numbers) > 1 ? (float)$numbers[1] : 0;
        $rate = ($matchedItem && $matchedItem['cost_price'] > 0) ? (float)$matchedItem['cost_price'] : ($qty > 0 ? $totalAmt / $qty : 0);

        $extracted['parsed_data'] = [
            'party_id' => $matchedParty['id'] ?? null,
            'party_name' => $matchedParty['company_name'] ?? $matchedParty['full_name'] ?? 'Unassigned',
            'item_id' => $matchedItem['id'] ?? null,
            'item_name' => $matchedItem['item_name'] ?? 'Unassigned Item',
            'qty' => $qty,
            'rate' => round($rate, 2),
            'amount' => round($totalAmt ?: ($qty * $rate), 2),
        ];

    } elseif ($intent === 'expense') {
        $amount = count($numbers) > 0 ? (float)$numbers[0] : 0;
        $extracted['suggested_route'] = 'index.php?page=transactions/expense/manage';
        $extracted['parsed_data'] = [
            'expense_account' => 'Operating Expense',
            'amount' => $amount,
            'memo' => $rawInput
        ];
    } else {
        $extracted['suggested_route'] = 'index.php?page=global_search&q=' . urlencode($rawInput);
        $extracted['parsed_data'] = ['query' => $rawInput];
    }

    // Log AI Audit Trail
    try {
        $db->execute("
            INSERT INTO audit_logs (user_id, action, module, record_id, details) 
            VALUES (?, 'AI_PARSED_PROMPT', 'AI_ASSISTANT', 0, ?)
        ", [$_SESSION['user_id'], json_encode($extracted)]);
    } catch (Exception $e) {}

    echo json_encode([
        'status' => 'success',
        'message' => 'AI interpretation generated. Please review and confirm data before saving.',
        'ai_result' => $extracted
    ]);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'AI processing failed: ' . $e->getMessage()]);
}
